<?php
/**
 * GEONEX SCADA - Passo 3: Endpoint REST de Controle dos 5 Postes (Pinos PWM 2 a 6),
 * Status de Conectividade (Heartbeat Serial), Máquina de Estados de Manutenção e Telemetria.
 *
 * Estilo: PHP Puro Procedural estritamente com funções mysqli_* (Sem PDO).
 */

require_once __DIR__ . '/conexao.php';

define('POTENCIA_NOMINAL_POSTE_W', 400.0);
define('PONTE_IOT_STATUS_URL', 'http://127.0.0.1:5005/evento');

/**
 * Garante que a estrutura do Passo 3 exista no banco MySQL ativo.
 */
function garantir_schema_passo3($conn): void {
    $colunas_necessarias = [
        'pwm_antes_manutencao' => "ALTER TABLE torres ADD COLUMN pwm_antes_manutencao TINYINT UNSIGNED NOT NULL DEFAULT 170 COMMENT 'Último PWM antes da manutenção' AFTER nivel_brilho_atual",
        'em_manutencao'        => "ALTER TABLE torres ADD COLUMN em_manutencao TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Em Manutenção, 0 = Operando' AFTER status_operacional",
        'conectado'            => "ALTER TABLE torres ADD COLUMN conectado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Online, 0 = Offline' AFTER em_manutencao",
        'consumo_watts'        => "ALTER TABLE torres ADD COLUMN consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'Consumo instantâneo (W)' AFTER conectado",
        'bateria'              => "ALTER TABLE torres ADD COLUMN bateria DECIMAL(5,2) NOT NULL DEFAULT 92.00 COMMENT 'Nobreak UPS (%)' AFTER consumo_watts",
        'ldr_atual'            => "ALTER TABLE torres ADD COLUMN ldr_atual INT NOT NULL DEFAULT 320 COMMENT 'Sensor LDR (0..1023)' AFTER bateria"
    ];

    foreach ($colunas_necessarias as $coluna => $sql_alter) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM torres LIKE '{$coluna}'");
        if ($check && mysqli_num_rows($check) === 0) {
            mysqli_query($conn, $sql_alter);
        }
        if ($check) {
            mysqli_free_result($check);
        }
    }

    $sql_tabela_telemetria = "
        CREATE TABLE IF NOT EXISTS telemetria_postes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            torre_id INT NOT NULL,
            pino_digital INT NOT NULL,
            nivel_pwm TINYINT UNSIGNED NOT NULL,
            modo_iluminacao ENUM('DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO') NOT NULL,
            status_operacional ENUM('OPERANDO', 'EM_MANUTENCAO') NOT NULL DEFAULT 'OPERANDO',
            conectado TINYINT(1) NOT NULL DEFAULT 1,
            consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            bateria_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            ldr_valor INT NULL DEFAULT NULL,
            origem_evento VARCHAR(40) NOT NULL DEFAULT 'DASHBOARD_WEB',
            registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telemetria_torre_data (torre_id, registrado_em),
            INDEX idx_telemetria_pino (pino_digital)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    mysqli_query($conn, $sql_tabela_telemetria);

    $pinos_padrao = [
        1 => ['codigo' => 'TR-01', 'setor' => 'Setor Norte - Lavra A',  'pino' => 2],
        2 => ['codigo' => 'TR-02', 'setor' => 'Setor Norte - Lavra A',  'pino' => 3],
        3 => ['codigo' => 'TR-03', 'setor' => 'Setor Leste - Rampa B',  'pino' => 4],
        4 => ['codigo' => 'TR-04', 'setor' => 'Setor Oeste - Britagem', 'pino' => 5],
        5 => ['codigo' => 'TR-05', 'setor' => 'Setor Leste - Rampa B',  'pino' => 6],
    ];

    foreach ($pinos_padrao as $id => $cfg) {
        $pino = $cfg['pino'];
        $codigo = $cfg['codigo'];
        $setor = $cfg['setor'];

        $stmt_up = mysqli_prepare($conn, "
            INSERT INTO torres (id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, pwm_antes_manutencao, status_operacional, em_manutencao, conectado, consumo_watts, bateria)
            VALUES (?, ?, ?, ?, 'NORMAL', 170, 170, 'OPERANDO', 0, 1, 266.67, 92.00)
            ON DUPLICATE KEY UPDATE pino_pwm = VALUES(pino_pwm)
        ");
        if ($stmt_up) {
            mysqli_stmt_bind_param($stmt_up, 'issi', $id, $codigo, $setor, $pino);
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);
        }
    }
}

/**
 * Verifica em tempo real na Ponte IoT (heartbeat serial) se o cabo USB do Arduino está conectado.
 * Se a conexão serial caiu ou a ponte reportar OFFLINE, atualiza imediatamente o banco MySQL para Offline (conectado = 0).
 */
function sincronizar_heartbeat_arduino($conn, bool $modo_simulacao = false): bool {
    if ($modo_simulacao) {
        return true;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 0.18,
            'ignore_errors' => true,
        ],
    ]);

    $resp = @file_get_contents(PONTE_IOT_STATUS_URL, false, $ctx);
    $arduino_online = false;

    if ($resp !== false) {
        $json = json_decode($resp, true);
        if (is_array($json) && !empty($json['online']) && strtoupper((string)($json['status'] ?? '')) === 'ONLINE') {
            $arduino_online = true;
        }
    }

    if (!$arduino_online) {
        // Se o cabo do Arduino foi desconectado ou a Ponte Serial caiu, muda todas as torres no banco para Offline imediatamente
        mysqli_query($conn, "UPDATE torres SET conectado = 0, consumo_watts = 0.00 WHERE conectado = 1");
    }

    return $arduino_online;
}

/**
 * Determina o modo de iluminação correspondente ao valor PWM (0..255)
 */
function modo_por_pwm(int $pwm): string {
    if ($pwm <= 0) return 'DESLIGADA';
    if ($pwm <= 120) return 'ECONOMIA';
    if ($pwm <= 212) return 'NORMAL';
    return 'CERRACAO';
}

/**
 * Retorna o valor PWM padrão para um modo nomeado
 */
function pwm_por_modo(string $modo): int {
    switch (strtoupper(trim($modo))) {
        case 'DESLIGADA': return 0;
        case 'ECONOMIA':  return 70;
        case 'NORMAL':    return 170;
        case 'CERRACAO':  return 255;
        default:          return 170;
    }
}

/**
 * Calcula o consumo instantâneo em Watts com base no PWM (0..255), status e conectividade
 */
function calcular_consumo_watts(int $pwm, string $status_operacional, int $conectado): float {
    if ($status_operacional === 'EM_MANUTENCAO' || $conectado === 0 || $pwm <= 0) {
        return 0.00;
    }
    $pwm_clamped = max(0, min(255, $pwm));
    return round(($pwm_clamped / 255.0) * POTENCIA_NOMINAL_POSTE_W, 2);
}

/**
 * Registra uma amostra de telemetria na tabela `telemetria_postes` usando mysqli procedural
 */
function registrar_log_telemetria(
    $conn,
    int $torre_id,
    int $pino_digital,
    int $nivel_pwm,
    string $modo_iluminacao,
    string $status_operacional,
    int $conectado,
    float $consumo_watts,
    float $bateria_pct,
    ?int $ldr_valor,
    string $origem_evento
): void {
    $sql = "
        INSERT INTO telemetria_postes (
            torre_id, pino_digital, nivel_pwm, modo_iluminacao,
            status_operacional, conectado, consumo_watts, bateria_pct,
            ldr_valor, origem_evento
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param(
            $stmt,
            'iiissiddis',
            $torre_id,
            $pino_digital,
            $nivel_pwm,
            $modo_iluminacao,
            $status_operacional,
            $conectado,
            $consumo_watts,
            $bateria_pct,
            $ldr_valor,
            $origem_evento
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * Formata uma linha da tabela `torres` para o contrato híbrido (camelCase Vue.js + snake_case PHP/SQL)
 */
function formatar_poste_scada(array $row): array {
    $id          = (int)$row['id'];
    $pino        = (int)$row['pino_pwm'];
    $em_manut    = ((int)($row['em_manutencao'] ?? 0) === 1) || ((string)$row['status_operacional'] === 'EM_MANUTENCAO');
    $status_op   = $em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
    $conectado   = isset($row['conectado']) ? ((int)$row['conectado'] === 1) : true;
    $pwm_bruto   = (int)$row['nivel_brilho_atual'];
    $pwm_antes   = isset($row['pwm_antes_manutencao']) ? (int)$row['pwm_antes_manutencao'] : $pwm_bruto;
    $pwm_efetivo = ($em_manut || !$conectado) ? 0 : max(0, min(255, $pwm_bruto));
    $modo        = (string)$row['modo_iluminacao'];
    $consumo_w   = calcular_consumo_watts($pwm_efetivo, $status_op, $conectado ? 1 : 0);
    $bateria     = isset($row['bateria']) ? round((float)$row['bateria'], 1) : 92.0;
    $ldr         = isset($row['ldr_atual']) ? (int)$row['ldr_atual'] : 320;
    $nome_setor  = (string)($row['setor'] ?? 'Setor Principal');

    return [
        // Campos camelCase (compatíveis com TypeScript `Torre` no Vue.js 3)
        'id'                   => $id,
        'codigo'               => (string)$row['codigo'],
        'pinoPwm'              => $pino,
        'modoIluminacao'       => $modo,
        'nivelBrilhoAtual'     => $pwm_efetivo,
        'pwmAntesManutencao'   => $pwm_antes,
        'statusOperacional'    => $status_op,
        'emManutencao'         => $em_manut,
        'conectado'            => $conectado,
        'consumoWatts'         => $consumo_w,
        'bateria'              => $bateria,
        'ldrAtual'             => $ldr,
        'atualizadoEm'         => $row['atualizado_em'] ?? null,
        'setor'                => [
            'id'                => $id,
            'nome'              => $nome_setor,
            'condicaoClimatica' => ($modo === 'CERRACAO') ? 'CERRACAO' : 'NORMAL'
        ],
        // Campos snake_case (compatíveis com scripts PHP / Ponte Python IoT)
        'pino_pwm'             => $pino,
        'modo_iluminacao'      => $modo,
        'nivel_brilho_atual'   => $pwm_efetivo,
        'pwm_antes_manutencao' => $pwm_antes,
        'status_operacional'   => $status_op,
        'em_manutencao'        => $em_manut,
        'consumo_watts'        => $consumo_w,
        'ldr_atual'            => $ldr,
        'atualizado_em'        => $row['atualizado_em'] ?? null,
    ];
}

/**
 * Consulta todos os 5 postes ordenados pelas portas PWM sequenciais (2 a 6)
 */
function buscar_todos_postes($conn): array {
    $sql = "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, pwm_antes_manutencao,
               status_operacional, em_manutencao, conectado, consumo_watts, bateria, ldr_atual, atualizado_em
          FROM torres
         ORDER BY pino_pwm ASC, id ASC
         LIMIT 5
    ";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return [];
    }
    $lista = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $lista[] = formatar_poste_scada($row);
    }
    mysqli_free_result($res);
    return $lista;
}

garantir_schema_passo3($conn);

$metodo      = $_SERVER['REQUEST_METHOD'];
$header_role = $_SERVER['HTTP_X_USER_ROLE'] ?? '';
$header_uid  = isset($_SERVER['HTTP_X_USER_ID']) ? (int)$_SERVER['HTTP_X_USER_ID'] : 0;

// ============================================================================
// 1. LISTAR POSTES, CONECTIVIDADE REAL DO ARDUINO, CONSUMO E TELEMETRIA (GET)
// ============================================================================
if ($metodo === 'GET') {
    $modo_simulacao = isset($_GET['simulacao']) && $_GET['simulacao'] == '1';
    $arduino_online = sincronizar_heartbeat_arduino($conn, $modo_simulacao);

    $postes = buscar_todos_postes($conn);

    $consumo_total_w  = 0.0;
    $online_count     = 0;
    $operando_count   = 0;
    $manutencao_count = 0;
    $soma_bateria     = 0.0;
    $pwm_valores      = [];

    foreach ($postes as $p) {
        $consumo_total_w += $p['consumoWatts'];
        if ($p['conectado']) $online_count++;
        if ($p['statusOperacional'] === 'EM_MANUTENCAO' || !empty($p['emManutencao'])) {
            $manutencao_count++;
        } else {
            $operando_count++;
        }
        $soma_bateria += $p['bateria'];
        $pwm_valores[] = $p['nivelBrilhoAtual'];
    }

    $total_postes     = count($postes);
    $capacidade_max_w = $total_postes * POTENCIA_NOMINAL_POSTE_W;
    $carga_pct        = $capacidade_max_w > 0 ? round(($consumo_total_w / $capacidade_max_w) * 100, 1) : 0.0;
    $economia_pct     = round(100.0 - $carga_pct, 1);
    $media_bateria    = $total_postes > 0 ? round($soma_bateria / $total_postes, 1) : 0.0;
    $ldr_atual_ref    = $total_postes > 0 ? (int)($postes[0]['ldrAtual'] ?? 310) : 310;

    $historico = [];
    if (isset($_GET['historico']) && $_GET['historico'] == '1') {
        $res_hist = mysqli_query($conn, "
            SELECT tp.id, tp.torre_id, t.codigo AS torre_codigo, tp.pino_digital,
                   tp.nivel_pwm, tp.modo_iluminacao, tp.status_operacional,
                   tp.conectado, tp.consumo_watts, tp.bateria_pct, tp.ldr_valor,
                   tp.origem_evento, tp.registrado_em
              FROM telemetria_postes tp
              INNER JOIN torres t ON t.id = tp.torre_id
             ORDER BY tp.id DESC
             LIMIT 30
        ");
        if ($res_hist) {
            while ($h = mysqli_fetch_assoc($res_hist)) {
                $h['id']            = (int)$h['id'];
                $h['torre_id']      = (int)$h['torre_id'];
                $h['pino_digital']  = (int)$h['pino_digital'];
                $h['nivel_pwm']     = (int)$h['nivel_pwm'];
                $h['conectado']     = (int)$h['conectado'] === 1;
                $h['consumo_watts'] = (float)$h['consumo_watts'];
                $h['bateria_pct']   = (float)$h['bateria_pct'];
                $h['ldr_valor']     = $h['ldr_valor'] !== null ? (int)$h['ldr_valor'] : null;
                $historico[]        = $h;
            }
            mysqli_free_result($res_hist);
        }
    }

    if (isset($_GET['formato']) && $_GET['formato'] === 'array') {
        responder_json(200, $postes, $conn);
    }

    responder_json(200, [
        'sucesso'           => true,
        'arduino_online'    => $arduino_online,
        'status_conexao'    => $arduino_online ? 'ONLINE' : 'OFFLINE',
        'dispositivo'       => 'ARDUINO_MEGA_UNIFICADO',
        'portas_digitais'   => [2, 3, 4, 5, 6],
        'pwm_torres'        => implode(',', $pwm_valores),
        'resumo_telemetria' => [
            'arduino_online'    => $arduino_online,
            'total_postes'      => $total_postes,
            'postes_online'     => $online_count,
            'postes_operando'   => $operando_count,
            'postes_manutencao' => $manutencao_count,
            'consumo_total_w'   => round($consumo_total_w, 2),
            'consumo_total_kw'  => round($consumo_total_w / 1000.0, 3),
            'capacidade_max_w'  => $capacidade_max_w,
            'carga_percentual'  => $carga_pct,
            'economia_pct'      => $economia_pct,
            'bateria_media_pct' => $media_bateria,
            'ldr_atual'         => $ldr_atual_ref,
            'comunicacao'       => $arduino_online ? 'USB_SERIAL_DIRETA_MEGA' : 'DESCONECTADO',
            'timestamp_servidor'=> date('Y-m-d H:i:s'),
        ],
        'dados'             => $postes,
        'historico'         => $historico,
    ], $conn);
}

// ============================================================================
// 2. ATUALIZAR INTENSIDADE PWM, ACIONAMENTO, CASCATA, HEARTBEAT OU TELEMETRIA (PUT / POST)
// ============================================================================
if ($metodo === 'PUT' || $metodo === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'Payload JSON inválido.'], $conn);
    }

    $acao = strtoupper(trim($corpo['acao'] ?? ''));

    // ------------------------------------------------------------------------
    // CASO ESPECIAL: Evento de Conexão / Desconexão Física do Cabo do Arduino
    // ------------------------------------------------------------------------
    if ($acao === 'STATUS_ARDUINO') {
        $online_flag = !empty($corpo['online']) ? 1 : 0;
        mysqli_begin_transaction($conn);

        if ($online_flag === 0) {
            mysqli_query($conn, "UPDATE torres SET conectado = 0, consumo_watts = 0.00");
        } else {
            mysqli_query($conn, "
                UPDATE torres
                   SET conectado = 1,
                       consumo_watts = IF(em_manutencao = 1 OR status_operacional = 'EM_MANUTENCAO' OR nivel_brilho_atual <= 0, 0.00, ROUND((nivel_brilho_atual / 255.0) * 400.0, 2))
            ");
        }

        mysqli_commit($conn);
        $postes_atualizados = buscar_todos_postes($conn);

        responder_json(200, [
            'sucesso'        => true,
            'arduino_online' => (bool)$online_flag,
            'mensagem'       => $online_flag ? 'Arduino reconectado (Online).' : 'Arduino desconectado (Offline).',
            'dados'          => $postes_atualizados,
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO A0: Controle / Simulação de Sensores via Software (Bateria e LDR)
    // ------------------------------------------------------------------------
    if ($acao === 'SENSORES_VIRTUAIS') {
        $bateria_virtual  = isset($corpo['bateria']) ? max(0.0, min(100.0, (float)$corpo['bateria'])) : 92.0;
        $ldr_virtual      = isset($corpo['ldr']) ? max(0, min(1023, (int)$corpo['ldr'])) : 310;
        $aplicar_auto     = isset($corpo['aplicar_regra_auto']) ? (bool)$corpo['aplicar_regra_auto'] : true;

        mysqli_begin_transaction($conn);

        $res_torres = mysqli_query($conn, "SELECT id, pino_pwm, modo_iluminacao, nivel_brilho_atual, status_operacional, em_manutencao, conectado FROM torres ORDER BY pino_pwm ASC");
        while ($t = mysqli_fetch_assoc($res_torres)) {
            $tid       = (int)$t['id'];
            $pino      = (int)$t['pino_pwm'];
            $em_manut  = ((int)$t['em_manutencao'] === 1) || ($t['status_operacional'] === 'EM_MANUTENCAO');
            $status_op = $em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
            $conectado = (int)$t['conectado'];
            $pwm_novo  = (int)$t['nivel_brilho_atual'];
            $modo_novo = (string)$t['modo_iluminacao'];

            // Se a torre está em manutenção, bloqueia alteração do PWM/modo
            if ($em_manut) {
                continue;
            }

            if ($aplicar_auto && $conectado === 1) {
                if ($bateria_virtual < 5.0) {
                    $pwm_novo  = 0;
                    $modo_novo = 'DESLIGADA';
                } elseif ($ldr_virtual >= 450) {
                    $pwm_novo  = 0;
                    $modo_novo = 'DESLIGADA';
                } elseif ($bateria_virtual < 25.0) {
                    $pwm_novo  = 70;
                    $modo_novo = 'ECONOMIA';
                } else {
                    $pwm_novo  = 170;
                    $modo_novo = 'NORMAL';
                }
            } elseif ($conectado === 0) {
                $pwm_novo = 0;
            }

            $consumo_w = calcular_consumo_watts($pwm_novo, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       pwm_antes_manutencao = ?,
                       consumo_watts = ?,
                       bateria = ?,
                       ldr_atual = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmt_up,
                'siiddii',
                $modo_novo,
                $pwm_novo,
                $pwm_novo,
                $consumo_w,
                $bateria_virtual,
                $ldr_virtual,
                $tid
            );
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            registrar_log_telemetria(
                $conn,
                $tid,
                $pino,
                $pwm_novo,
                $modo_novo,
                $status_op,
                $conectado,
                $consumo_w,
                $bateria_virtual,
                $ldr_virtual,
                'SENSORES_SOFTWARE'
            );
        }
        if ($res_torres) {
            mysqli_free_result($res_torres);
        }

        mysqli_commit($conn);
        $postes_atualizados = buscar_todos_postes($conn);

        responder_json(200, [
            'sucesso'  => true,
            'mensagem' => "Sensores virtuais aplicados via software (Bateria: {$bateria_virtual}%, LDR: {$ldr_virtual}).",
            'dados'    => $postes_atualizados
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO A: Recebimento de Pacote de Telemetria da Ponte IoT / Arduino
    // ------------------------------------------------------------------------
    if ($acao === 'TELEMETRIA' || isset($corpo['torres']) || isset($corpo['postes'])) {
        $ldr_lido     = isset($corpo['ldr']) ? (int)$corpo['ldr'] : 320;
        $bateria_lida = isset($corpo['bateria']) ? (float)$corpo['bateria'] : 92.0;
        $lista_pwm    = $corpo['torres'] ?? $corpo['postes'] ?? [];

        mysqli_begin_transaction($conn);

        foreach ($lista_pwm as $idx => $item) {
            $torre_id  = $idx + 1;
            $pino_dig  = 2 + $idx;
            $conectado = 1;
            $pwm_val   = 0;

            if (is_array($item)) {
                $torre_id  = isset($item['id']) ? (int)$item['id'] : $torre_id;
                $pino_dig  = isset($item['pino']) ? (int)$item['pino'] : (1 + $torre_id);
                $pwm_val   = isset($item['pwm']) ? (int)$item['pwm'] : 0;
                $conectado = isset($item['conectado']) ? ($item['conectado'] ? 1 : 0) : 1;
            } else {
                $pwm_val = (int)$item;
            }

            $pwm_val = max(0, min(255, $pwm_val));
            $modo    = modo_por_pwm($pwm_val);

            $stmt_sel = mysqli_prepare($conn, "SELECT status_operacional, em_manutencao, pino_pwm, nivel_brilho_atual, modo_iluminacao FROM torres WHERE id = ?");
            mysqli_stmt_bind_param($stmt_sel, 'i', $torre_id);
            mysqli_stmt_execute($stmt_sel);
            $res_sel = mysqli_stmt_get_result($stmt_sel);
            $atual   = mysqli_fetch_assoc($res_sel);
            mysqli_stmt_close($stmt_sel);

            if (!$atual) continue;

            $em_manut  = ((int)$atual['em_manutencao'] === 1) || ($atual['status_operacional'] === 'EM_MANUTENCAO');
            $status_op = $em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
            $pino_db   = (int)$atual['pino_pwm'];

            if ($em_manut) {
                // Preserva o último brilho salvo no banco enquanto a torre está em manutenção
                $stmt_up = mysqli_prepare($conn, "
                    UPDATE torres
                       SET conectado = ?,
                           consumo_watts = 0.00,
                           bateria = ?,
                           ldr_atual = ?
                     WHERE id = ?
                ");
                mysqli_stmt_bind_param($stmt_up, 'idii', $conectado, $bateria_lida, $ldr_lido, $torre_id);
                mysqli_stmt_execute($stmt_up);
                mysqli_stmt_close($stmt_up);
                continue;
            }

            $consumo_w = calcular_consumo_watts($pwm_val, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       pwm_antes_manutencao = ?,
                       conectado = ?,
                       consumo_watts = ?,
                       bateria = ?,
                       ldr_atual = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmt_up,
                'siiiddii',
                $modo,
                $pwm_val,
                $pwm_val,
                $conectado,
                $consumo_w,
                $bateria_lida,
                $ldr_lido,
                $torre_id
            );
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            registrar_log_telemetria(
                $conn,
                $torre_id,
                $pino_db,
                $pwm_val,
                $modo,
                $status_op,
                $conectado,
                $consumo_w,
                $bateria_lida,
                $ldr_lido,
                'ARDUINO_IOT'
            );
        }

        mysqli_commit($conn);
        $postes_atualizados = buscar_todos_postes($conn);

        responder_json(200, [
            'sucesso'  => true,
            'mensagem' => 'Telemetria dos 5 postes sincronizada com sucesso.',
            'dados'    => $postes_atualizados
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO B: Acionamento Sequencial em Cascata ou Contingência Geral
    // ------------------------------------------------------------------------
    if (in_array($acao, ['CASCATA', 'CERRACAO', 'LOTE'], true)) {
        $role_usuario = $header_role ?: ($corpo['usuario_role'] ?? 'OPERADOR');
        validar_rbac($role_usuario, ['ADMIN', 'OPERADOR', 'TECNICO'], $conn);

        $pwm_alvo = isset($corpo['nivelBrilhoAtual'])
            ? (int)$corpo['nivelBrilhoAtual']
            : (isset($corpo['nivel_brilho_atual']) ? (int)$corpo['nivel_brilho_atual'] : 170);

        if (isset($corpo['modoIluminacao']) || isset($corpo['modo_iluminacao'])) {
            $modo_req = strtoupper(trim($corpo['modoIluminacao'] ?? $corpo['modo_iluminacao']));
            if (!isset($corpo['nivelBrilhoAtual']) && !isset($corpo['nivel_brilho_atual'])) {
                $pwm_alvo = pwm_por_modo($modo_req);
            }
        } else {
            $modo_req = modo_por_pwm($pwm_alvo);
        }

        $pwm_alvo = max(0, min(255, $pwm_alvo));
        $modo_req = modo_por_pwm($pwm_alvo);

        mysqli_begin_transaction($conn);

        $res_torres = mysqli_query($conn, "SELECT id, pino_pwm, status_operacional, em_manutencao, conectado, bateria, ldr_atual FROM torres ORDER BY pino_pwm ASC");
        while ($t = mysqli_fetch_assoc($res_torres)) {
            $tid       = (int)$t['id'];
            $pino      = (int)$t['pino_pwm'];
            $em_manut  = ((int)$t['em_manutencao'] === 1) || ($t['status_operacional'] === 'EM_MANUTENCAO');
            $status_op = $em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
            $conectado = (int)$t['conectado'];
            $bateria   = (float)$t['bateria'];
            $ldr       = (int)$t['ldr_atual'];

            // Postes em manutenção ou desconectados permanecem bloqueados
            if ($em_manut || $conectado === 0) {
                continue;
            }

            $consumo_w = calcular_consumo_watts($pwm_alvo, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       pwm_antes_manutencao = ?,
                       consumo_watts = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt_up, 'siidi', $modo_req, $pwm_alvo, $pwm_alvo, $consumo_w, $tid);
            mysqli_stmt_execute($stmt_up);
            mysqli_stmt_close($stmt_up);

            registrar_log_telemetria(
                $conn,
                $tid,
                $pino,
                $pwm_alvo,
                $modo_req,
                $status_op,
                $conectado,
                $consumo_w,
                $bateria,
                $ldr,
                $acao
            );
        }
        if ($res_torres) {
            mysqli_free_result($res_torres);
        }

        mysqli_commit($conn);
        $postes = buscar_todos_postes($conn);

        responder_json(200, [
            'sucesso'  => true,
            'mensagem' => "Acionamento {$acao} aplicado (PWM {$pwm_alvo}).",
            'dados'    => $postes
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO C: Atualização Individual de um Poste
    // ------------------------------------------------------------------------
    $torre_id = isset($corpo['id']) ? (int)$corpo['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $pino_pwm = isset($corpo['pino_pwm']) ? (int)$corpo['pino_pwm'] : (isset($corpo['pinoPwm']) ? (int)$corpo['pinoPwm'] : 0);

    if ($torre_id <= 0 && $pino_pwm >= 2 && $pino_pwm <= 6) {
        $torre_id = $pino_pwm - 1;
    }

    if ($torre_id <= 0) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe o ID do poste (1 a 5) ou o pino PWM do Arduino Mega (2 a 6).'
        ], $conn);
    }

    $stmt_busca = mysqli_prepare($conn, "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, pwm_antes_manutencao,
               status_operacional, em_manutencao, conectado, consumo_watts, bateria, ldr_atual
          FROM torres
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_busca, 'i', $torre_id);
    mysqli_stmt_execute($stmt_busca);
    $res_busca = mysqli_stmt_get_result($stmt_busca);
    $poste_atual = mysqli_fetch_assoc($res_busca);
    mysqli_stmt_close($stmt_busca);

    if (!$poste_atual) {
        responder_json(404, ['sucesso' => false, 'erro' => "Poste #{$torre_id} não encontrado."], $conn);
    }

    $estava_em_manut = ((int)$poste_atual['em_manutencao'] === 1) || ($poste_atual['status_operacional'] === 'EM_MANUTENCAO');

    // Verifica se o request está alterando explicitamente o estado de manutenção
    $mudando_status_op = isset($corpo['statusOperacional']) || isset($corpo['status_operacional']) || isset($corpo['em_manutencao']) || isset($corpo['emManutencao']);
    if (isset($corpo['em_manutencao']) || isset($corpo['emManutencao'])) {
        $flag_req = !empty($corpo['em_manutencao']) || !empty($corpo['emManutencao']);
        $novo_status_op = $flag_req ? 'EM_MANUTENCAO' : 'OPERANDO';
    } elseif (isset($corpo['statusOperacional']) || isset($corpo['status_operacional'])) {
        $novo_status_op = strtoupper(trim($corpo['statusOperacional'] ?? $corpo['status_operacional']));
        if (!in_array($novo_status_op, ['OPERANDO', 'EM_MANUTENCAO'], true)) {
            $novo_status_op = $estava_em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
        }
    } else {
        $novo_status_op = $estava_em_manut ? 'EM_MANUTENCAO' : 'OPERANDO';
    }

    $novo_em_manut = ($novo_status_op === 'EM_MANUTENCAO') ? 1 : 0;

    $novo_conectado = isset($corpo['conectado'])
        ? ($corpo['conectado'] ? 1 : 0)
        : (int)$poste_atual['conectado'];

    // Bloqueia qualquer tentativa de acionamento de PWM ou relé vinda do painel se a torre estiver em manutenção
    $tentando_mudar_brilho = isset($corpo['nivelBrilhoAtual']) || isset($corpo['nivel_brilho_atual']) || isset($corpo['modoIluminacao']) || isset($corpo['modo_iluminacao']);

    if ($novo_em_manut === 1 && $tentando_mudar_brilho && !$mudando_status_op) {
        responder_json(409, [
            'sucesso' => false,
            'erro'    => "O poste {$poste_atual['codigo']} (Porta D{$poste_atual['pino_pwm']}) está EM MANUTENÇÃO — acionamento de PWM/relé bloqueado."
        ], $conn);
    }

    $tem_pwm_explicito  = isset($corpo['nivelBrilhoAtual']) || isset($corpo['nivel_brilho_atual']);
    $tem_modo_explicito = isset($corpo['modoIluminacao']) || isset($corpo['modo_iluminacao']);

    if ($tem_pwm_explicito) {
        $novo_pwm = (int)($corpo['nivelBrilhoAtual'] ?? $corpo['nivel_brilho_atual']);
        $novo_pwm = max(0, min(255, $novo_pwm));
        $novo_modo = $tem_modo_explicito
            ? strtoupper(trim($corpo['modoIluminacao'] ?? $corpo['modo_iluminacao']))
            : modo_por_pwm($novo_pwm);
        if (($novo_pwm > 0 && $novo_modo === 'DESLIGADA') || ($novo_pwm === 0 && $novo_modo !== 'DESLIGADA')) {
            $novo_modo = modo_por_pwm($novo_pwm);
        }
    } elseif ($tem_modo_explicito) {
        $novo_modo = strtoupper(trim($corpo['modoIluminacao'] ?? $corpo['modo_iluminacao']));
        $novo_pwm  = pwm_por_modo($novo_modo);
    } elseif ($estava_em_manut && $novo_em_manut === 0) {
        // Saindo de manutenção: recupera o último PWM anterior à manutenção
        $novo_pwm  = (int)($poste_atual['pwm_antes_manutencao'] ?? $poste_atual['nivel_brilho_atual']);
        $novo_modo = modo_por_pwm($novo_pwm);
    } else {
        $novo_pwm  = (int)$poste_atual['nivel_brilho_atual'];
        $novo_modo = (string)$poste_atual['modo_iluminacao'];
    }

    if (!in_array($novo_modo, ['DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO'], true)) {
        $novo_modo = modo_por_pwm($novo_pwm);
    }

    // Preserva pwm_antes_manutencao quando entra ou opera normalmente
    $novo_pwm_antes_manut = (int)($poste_atual['pwm_antes_manutencao'] ?? 170);
    if (!$estava_em_manut && $novo_em_manut === 1) {
        $novo_pwm_antes_manut = (int)$poste_atual['nivel_brilho_atual'];
    } elseif ($novo_em_manut === 0) {
        $novo_pwm_antes_manut = $novo_pwm;
    }

    $pwm_para_consumo = ($novo_em_manut === 1) ? 0 : $novo_pwm;
    $novo_consumo_w   = calcular_consumo_watts($pwm_para_consumo, $novo_status_op, $novo_conectado);
    $nova_bateria     = isset($corpo['bateria']) ? (float)$corpo['bateria'] : (float)$poste_atual['bateria'];
    $novo_ldr         = isset($corpo['ldr_atual']) ? (int)$corpo['ldr_atual'] : (int)$poste_atual['ldr_atual'];

    mysqli_begin_transaction($conn);

    $stmt_update = mysqli_prepare($conn, "
        UPDATE torres
           SET modo_iluminacao = ?,
               nivel_brilho_atual = ?,
               pwm_antes_manutencao = ?,
               status_operacional = ?,
               em_manutencao = ?,
               conectado = ?,
               consumo_watts = ?,
               bateria = ?,
               ldr_atual = ?
         WHERE id = ?
    ");

    if (!$stmt_update) {
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => mysqli_error($conn)], $conn);
    }

    mysqli_stmt_bind_param(
        $stmt_update,
        'siisiiidii',
        $novo_modo,
        $novo_pwm,
        $novo_pwm_antes_manut,
        $novo_status_op,
        $novo_em_manut,
        $novo_conectado,
        $novo_consumo_w,
        $nova_bateria,
        $novo_ldr,
        $torre_id
    );

    if (!mysqli_stmt_execute($stmt_update)) {
        $err = mysqli_stmt_error($stmt_update);
        mysqli_stmt_close($stmt_update);
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => 'Erro ao atualizar poste: ' . $err], $conn);
    }
    mysqli_stmt_close($stmt_update);

    registrar_log_telemetria(
        $conn,
        $torre_id,
        (int)$poste_atual['pino_pwm'],
        $pwm_para_consumo,
        $novo_modo,
        $novo_status_op,
        $novo_conectado,
        $novo_consumo_w,
        $nova_bateria,
        $novo_ldr,
        'DASHBOARD_WEB'
    );

    mysqli_commit($conn);

    $stmt_final = mysqli_prepare($conn, "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, pwm_antes_manutencao,
               status_operacional, em_manutencao, conectado, consumo_watts, bateria, ldr_atual, atualizado_em
          FROM torres
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_final, 'i', $torre_id);
    mysqli_stmt_execute($stmt_final);
    $res_final = mysqli_stmt_get_result($stmt_final);
    $linha_final = mysqli_fetch_assoc($res_final);
    mysqli_stmt_close($stmt_final);

    $poste_formatado = formatar_poste_scada($linha_final);
    $poste_formatado['sucesso']  = true;
    $poste_formatado['mensagem'] = "Poste {$poste_formatado['codigo']} (Porta D{$poste_formatado['pinoPwm']}) atualizado para PWM {$poste_formatado['nivelBrilhoAtual']}.";

    responder_json(200, $poste_formatado, $conn);
}

responder_json(405, [
    'sucesso' => false,
    'erro'    => "Método HTTP '{$metodo}' não suportado neste endpoint."
], $conn);
