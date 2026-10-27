<?php
/**
 * GEONEX SCADA - Passo 3: Endpoint REST de Controle dos 5 Postes (Portas 8 a 12),
 * Status de Conectividade, Consumo Energético e Telemetria.
 *
 * Estilo: PHP Puro Procedural estritamente com funções mysqli_* (Sem PDO).
 *
 * Métodos suportados:
 *  - GET  /postes.php[?historico=1] -> Lista o estado em tempo real dos 5 postes (portas 8 a 12) e resumo de telemetria
 *  - PUT  /postes.php               -> Atualiza intensidade PWM (0..255), modo, conectividade ou aciona efeito cascata
 *  - POST /postes.php               -> Registra telemetria vinda do Arduino Uno / Ponte IoT ou executa comandos em lote
 */

require_once __DIR__ . '/conexao.php';

// Potência nominal de cada refletor LED industrial de mina a 100% (PWM 255)
define('POTENCIA_NOMINAL_POSTE_W', 400.0);

/**
 * Garante que a estrutura do Passo 3 (colunas de telemetria, consumo, conectividade
 * e portas digitais sequenciais 8, 9, 10, 11 e 12) exista no banco MySQL ativo.
 */
function garantir_schema_passo3($conn): void {
    // 1. Verifica colunas extras na tabela `torres`
    $colunas_necessarias = [
        'conectado'     => "ALTER TABLE torres ADD COLUMN conectado TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Online, 0 = Offline' AFTER status_operacional",
        'consumo_watts' => "ALTER TABLE torres ADD COLUMN consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 0.00 COMMENT 'Consumo instantâneo (W)' AFTER conectado",
        'bateria'       => "ALTER TABLE torres ADD COLUMN bateria DECIMAL(5,2) NOT NULL DEFAULT 92.00 COMMENT 'Nobreak UPS (%)' AFTER consumo_watts",
        'ldr_atual'     => "ALTER TABLE torres ADD COLUMN ldr_atual INT NOT NULL DEFAULT 320 COMMENT 'Sensor LDR (0..1023)' AFTER bateria"
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

    // 2. Garante a existência da tabela de histórico de telemetria e consumo (`telemetria_postes`)
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

    // 3. Sincroniza as 5 portas PWM sequenciais do Arduino Mega (2, 3, 4, 5 e 6) para os 5 postes (id 1 a 5)
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
            INSERT INTO torres (id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual, status_operacional, conectado, consumo_watts, bateria)
            VALUES (?, ?, ?, ?, 'NORMAL', 170, 'OPERANDO', 1, 266.67, 92.00)
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
    $status_op   = (string)$row['status_operacional'];
    $conectado   = isset($row['conectado']) ? ((int)$row['conectado'] === 1) : true;
    $pwm_bruto   = (int)$row['nivel_brilho_atual'];
    $pwm_efetivo = ($status_op === 'EM_MANUTENCAO' || !$conectado) ? 0 : max(0, min(255, $pwm_bruto));
    $modo        = (string)$row['modo_iluminacao'];
    $consumo_w   = calcular_consumo_watts($pwm_efetivo, $status_op, $conectado ? 1 : 0);
    $bateria     = isset($row['bateria']) ? round((float)$row['bateria'], 1) : 92.0;
    $ldr         = isset($row['ldr_atual']) ? (int)$row['ldr_atual'] : 320;
    $nome_setor  = (string)($row['setor'] ?? 'Setor Principal');

    return [
        // Campos camelCase (compatíveis com TypeScript `Torre` no Vue.js 3)
        'id'                 => $id,
        'codigo'             => (string)$row['codigo'],
        'pinoPwm'            => $pino,
        'modoIluminacao'     => $modo,
        'nivelBrilhoAtual'   => $pwm_efetivo,
        'statusOperacional'  => $status_op,
        'conectado'          => $conectado,
        'consumoWatts'       => $consumo_w,
        'bateria'            => $bateria,
        'ldrAtual'           => $ldr,
        'atualizadoEm'       => $row['atualizado_em'] ?? null,
        'setor'              => [
            'id'                => $id,
            'nome'              => $nome_setor,
            'condicaoClimatica' => ($modo === 'CERRACAO') ? 'CERRACAO' : 'NORMAL'
        ],
        // Campos snake_case (compatíveis com scripts PHP / Ponte Python IoT)
        'pino_pwm'           => $pino,
        'modo_iluminacao'    => $modo,
        'nivel_brilho_atual' => $pwm_efetivo,
        'status_operacional' => $status_op,
        'consumo_watts'      => $consumo_w,
        'ldr_atual'          => $ldr,
        'atualizado_em'      => $row['atualizado_em'] ?? null,
    ];
}

/**
 * Consulta todos os 5 postes ordenados pelas portas digitais sequenciais (8 a 12)
 */
function buscar_todos_postes($conn): array {
    $sql = "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual,
               status_operacional, conectado, consumo_watts, bateria, ldr_atual, atualizado_em
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

// Inicializa e valida o schema do Passo 3
garantir_schema_passo3($conn);

$metodo      = $_SERVER['REQUEST_METHOD'];
$header_role = $_SERVER['HTTP_X_USER_ROLE'] ?? '';
$header_uid  = isset($_SERVER['HTTP_X_USER_ID']) ? (int)$_SERVER['HTTP_X_USER_ID'] : 0;

// ============================================================================
// 1. LISTAR POSTES, CONECTIVIDADE, CONSUMO E TELEMETRIA (GET)
// ============================================================================
if ($metodo === 'GET') {
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
        if ($p['statusOperacional'] === 'EM_MANUTENCAO') {
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

    // Se solicitado histórico recente de telemetria (?historico=1)
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

    // Se ?formato=array (para clientes que esperam o array direto de Torre[])
    if (isset($_GET['formato']) && $_GET['formato'] === 'array') {
        responder_json(200, $postes, $conn);
    }

    responder_json(200, [
        'sucesso'           => true,
        'dispositivo'       => 'ARDUINO_MEGA_UNIFICADO',
        'portas_digitais'   => [2, 3, 4, 5, 6],
        'pwm_torres'        => implode(',', $pwm_valores),
        'resumo_telemetria' => [
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
            'comunicacao'       => 'USB_SERIAL_DIRETA_MEGA',
            'timestamp_servidor'=> date('Y-m-d H:i:s'),
        ],
        'dados'             => $postes,
        'historico'         => $historico,
    ], $conn);
}

// ============================================================================
// 2. ATUALIZAR INTENSIDADE PWM, ACIONAMENTO, CASCATA OU TELEMETRIA (PUT / POST)
// ============================================================================
if ($metodo === 'PUT' || $metodo === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'Payload JSON inválido.'], $conn);
    }

    $acao = strtoupper(trim($corpo['acao'] ?? ''));

    // ------------------------------------------------------------------------
    // CASO A0: Controle / Simulação de Sensores via Software (Bateria e LDR)
    //          (Substitui o LDR e Potenciômetro físicos pelo Painel Vue.js / MySQL)
    // ------------------------------------------------------------------------
    if ($acao === 'SENSORES_VIRTUAIS') {
        $bateria_virtual  = isset($corpo['bateria']) ? max(0.0, min(100.0, (float)$corpo['bateria'])) : 92.0;
        $ldr_virtual      = isset($corpo['ldr']) ? max(0, min(1023, (int)$corpo['ldr'])) : 310;
        $aplicar_auto     = isset($corpo['aplicar_regra_auto']) ? (bool)$corpo['aplicar_regra_auto'] : true;

        mysqli_begin_transaction($conn);

        $res_torres = mysqli_query($conn, "SELECT id, pino_pwm, modo_iluminacao, nivel_brilho_atual, status_operacional, conectado FROM torres ORDER BY pino_pwm ASC");
        while ($t = mysqli_fetch_assoc($res_torres)) {
            $tid       = (int)$t['id'];
            $pino      = (int)$t['pino_pwm'];
            $status_op = (string)$t['status_operacional'];
            $conectado = (int)$t['conectado'];
            $pwm_novo  = (int)$t['nivel_brilho_atual'];
            $modo_novo = (string)$t['modo_iluminacao'];

            if ($aplicar_auto && $status_op !== 'EM_MANUTENCAO' && $conectado === 1) {
                if ($bateria_virtual < 5.0) {
                    $pwm_novo  = 0;
                    $modo_novo = 'DESLIGADA';
                } elseif ($ldr_virtual >= 450) {
                    // Luz natural alta (Dia) -> Desliga postes
                    $pwm_novo  = 0;
                    $modo_novo = 'DESLIGADA';
                } elseif ($bateria_virtual < 25.0) {
                    // Noite + Bateria baixa -> Modo Economia (PWM 70)
                    $pwm_novo  = 70;
                    $modo_novo = 'ECONOMIA';
                } else {
                    // Noite + Bateria normal -> Operação Normal (PWM 170)
                    $pwm_novo  = 170;
                    $modo_novo = 'NORMAL';
                }
            } elseif ($status_op === 'EM_MANUTENCAO' || $conectado === 0) {
                $pwm_novo = 0;
            }

            $consumo_w = calcular_consumo_watts($pwm_novo, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       consumo_watts = ?,
                       bateria = ?,
                       ldr_atual = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmt_up,
                'siddii',
                $modo_novo,
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
    // CASO A: Recebimento de Pacote de Telemetria da Ponte IoT / Arduino Uno
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

            // Verifica status operacional atual antes de atualizar
            $stmt_sel = mysqli_prepare($conn, "SELECT status_operacional, pino_pwm FROM torres WHERE id = ?");
            mysqli_stmt_bind_param($stmt_sel, 'i', $torre_id);
            mysqli_stmt_execute($stmt_sel);
            $res_sel = mysqli_stmt_get_result($stmt_sel);
            $atual   = mysqli_fetch_assoc($res_sel);
            mysqli_stmt_close($stmt_sel);

            if (!$atual) continue;

            $status_op = $atual['status_operacional'];
            $pino_db   = (int)$atual['pino_pwm'];
            if ($status_op === 'EM_MANUTENCAO') {
                $pwm_val = 0;
                $modo    = 'DESLIGADA';
            }

            $consumo_w = calcular_consumo_watts($pwm_val, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       conectado = ?,
                       consumo_watts = ?,
                       bateria = ?,
                       ldr_atual = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmt_up,
                'siiddii',
                $modo,
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
            'mensagem' => 'Telemetria dos 5 postes (portas 8 a 12) sincronizada com sucesso.',
            'dados'    => $postes_atualizados
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO B: Acionamento Sequencial em Cascata (Portas 8 -> 9 -> 10 -> 11 -> 12)
    //         ou Contingência Geral (Cerração / Desligamento Geral)
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

        $res_torres = mysqli_query($conn, "SELECT id, pino_pwm, status_operacional, conectado, bateria, ldr_atual FROM torres ORDER BY pino_pwm ASC");
        while ($t = mysqli_fetch_assoc($res_torres)) {
            $tid       = (int)$t['id'];
            $pino      = (int)$t['pino_pwm'];
            $status_op = (string)$t['status_operacional'];
            $conectado = (int)$t['conectado'];
            $bateria   = (float)$t['bateria'];
            $ldr       = (int)$t['ldr_atual'];

            // Postes em manutenção ou desconectados permanecem bloqueados em PWM 0
            if ($status_op === 'EM_MANUTENCAO' || $conectado === 0) {
                continue;
            }

            $consumo_w = calcular_consumo_watts($pwm_alvo, $status_op, $conectado);

            $stmt_up = mysqli_prepare($conn, "
                UPDATE torres
                   SET modo_iluminacao = ?,
                       nivel_brilho_atual = ?,
                       consumo_watts = ?
                 WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmt_up, 'sidi', $modo_req, $pwm_alvo, $consumo_w, $tid);
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
            'mensagem' => "Acionamento {$acao} aplicado nas portas digitais 8 a 12 (PWM {$pwm_alvo}).",
            'dados'    => $postes
        ], $conn);
    }

    // ------------------------------------------------------------------------
    // CASO C: Atualização Individual de um Poste (Portas 8 a 12)
    // ------------------------------------------------------------------------
    $torre_id = isset($corpo['id']) ? (int)$corpo['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
    $pino_pwm = isset($corpo['pino_pwm']) ? (int)$corpo['pino_pwm'] : (isset($corpo['pinoPwm']) ? (int)$corpo['pinoPwm'] : 0);

    if ($torre_id <= 0 && $pino_pwm >= 2 && $pino_pwm <= 6) {
        $torre_id = $pino_pwm - 1; // Pino PWM 2 -> ID 1 ... Pino PWM 6 -> ID 5
    }

    if ($torre_id <= 0) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe o ID do poste (1 a 5) ou o pino PWM do Arduino Mega (2 a 6).'
        ], $conn);
    }

    // Busca estado atual do poste no MySQL
    $stmt_busca = mysqli_prepare($conn, "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual,
               status_operacional, conectado, consumo_watts, bateria, ldr_atual
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

    // Status operacional e conectividade
    $novo_status_op = isset($corpo['statusOperacional'])
        ? strtoupper(trim($corpo['statusOperacional']))
        : (isset($corpo['status_operacional']) ? strtoupper(trim($corpo['status_operacional'])) : $poste_atual['status_operacional']);

    if (!in_array($novo_status_op, ['OPERANDO', 'EM_MANUTENCAO'], true)) {
        $novo_status_op = $poste_atual['status_operacional'];
    }

    $novo_conectado = isset($corpo['conectado'])
        ? ($corpo['conectado'] ? 1 : 0)
        : (int)$poste_atual['conectado'];

    // Se a torre está EM_MANUTENCAO e o comando tenta apenas alterar brilho/modo, bloqueia com HTTP 409
    $tentando_mudar_brilho = isset($corpo['nivelBrilhoAtual']) || isset($corpo['nivel_brilho_atual']) || isset($corpo['modoIluminacao']) || isset($corpo['modo_iluminacao']);
    $mudando_status_op     = isset($corpo['statusOperacional']) || isset($corpo['status_operacional']);

    if ($novo_status_op === 'EM_MANUTENCAO' && $tentando_mudar_brilho && !$mudando_status_op) {
        responder_json(409, [
            'sucesso' => false,
            'erro'    => "O poste {$poste_atual['codigo']} (Porta D{$poste_atual['pino_pwm']}) está EM MANUTENÇÃO — acionamento remoto bloqueado."
        ], $conn);
    }

    // Determina novo PWM (0..255) e Modo de Iluminação
    $tem_pwm_explicito = isset($corpo['nivelBrilhoAtual']) || isset($corpo['nivel_brilho_atual']);
    $tem_modo_explicito = isset($corpo['modoIluminacao']) || isset($corpo['modo_iluminacao']);

    if ($tem_pwm_explicito) {
        $novo_pwm = (int)($corpo['nivelBrilhoAtual'] ?? $corpo['nivel_brilho_atual']);
        $novo_pwm = max(0, min(255, $novo_pwm));
        $novo_modo = $tem_modo_explicito
            ? strtoupper(trim($corpo['modoIluminacao'] ?? $corpo['modo_iluminacao']))
            : modo_por_pwm($novo_pwm);
        // Se o modo enviado for incompatível com PWM > 0 (ex: mandou DESLIGADA mas PWM 180 via slider), ajusta pelo PWM
        if (($novo_pwm > 0 && $novo_modo === 'DESLIGADA') || ($novo_pwm === 0 && $novo_modo !== 'DESLIGADA')) {
            $novo_modo = modo_por_pwm($novo_pwm);
        }
    } elseif ($tem_modo_explicito) {
        $novo_modo = strtoupper(trim($corpo['modoIluminacao'] ?? $corpo['modo_iluminacao']));
        $novo_pwm  = pwm_por_modo($novo_modo);
    } else {
        $novo_pwm  = (int)$poste_atual['nivel_brilho_atual'];
        $novo_modo = (string)$poste_atual['modo_iluminacao'];
    }

    if (!in_array($novo_modo, ['DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO'], true)) {
        $novo_modo = modo_por_pwm($novo_pwm);
    }

    if ($novo_status_op === 'EM_MANUTENCAO') {
        $novo_pwm = 0;
    }

    $novo_consumo_w = calcular_consumo_watts($novo_pwm, $novo_status_op, $novo_conectado);
    $nova_bateria   = isset($corpo['bateria']) ? (float)$corpo['bateria'] : (float)$poste_atual['bateria'];
    $novo_ldr       = isset($corpo['ldr_atual']) ? (int)$corpo['ldr_atual'] : (int)$poste_atual['ldr_atual'];

    mysqli_begin_transaction($conn);

    $stmt_update = mysqli_prepare($conn, "
        UPDATE torres
           SET modo_iluminacao = ?,
               nivel_brilho_atual = ?,
               status_operacional = ?,
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
        'sisiddii',
        $novo_modo,
        $novo_pwm,
        $novo_status_op,
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
        $novo_pwm,
        $novo_modo,
        $novo_status_op,
        $novo_conectado,
        $novo_consumo_w,
        $nova_bateria,
        $novo_ldr,
        'DASHBOARD_WEB'
    );

    mysqli_commit($conn);

    // Retorna o registro atualizado
    $stmt_final = mysqli_prepare($conn, "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual,
               status_operacional, conectado, consumo_watts, bateria, ldr_atual, atualizado_em
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
