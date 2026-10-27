<?php
/**
 * GEONEX SCADA - Passo 4 (Etapa Final):
 * API de Histórico de Telemetria, Auditoria de Acionamentos PWM e Analytics de Consumo
 *
 * Estilo: PHP Puro Procedural estritamente com funções mysqli_* (Sem PDO).
 *
 * Métodos e Parâmetros suportados:
 *  - GET  /historico_telemetria.php
 *      ?periodo=1H|6H|24H|7D|30D|TODOS  (padrão: 24H)
 *      &torre_id=1..5                   (opcional: filtra por poste TR-01 a TR-05 / Pinos 2 a 6)
 *      &origem=DASHBOARD_WEB|CASCATA|ARDUINO_IOT|SENSORES_SOFTWARE (opcional)
 *      &data_inicio=YYYY-MM-DD HH:MM:SS (opcional)
 *      &data_fim=YYYY-MM-DD HH:MM:SS    (opcional)
 *      &limite=80                       (opcional: limite de registros na tabela de auditoria)
 *
 *  - POST /historico_telemetria.php
 *      Registra novo evento de telemetria ou snapshot vindo da Ponte Python / Arduino Mega
 *      com deduplicação inteligente para alta performance.
 */

require_once __DIR__ . '/conexao.php';

define('POTENCIA_MAX_POSTE_W', 400.0);
define('CAPACIDADE_TOTAL_MINA_W', 2000.0); // 5 postes x 400W

/**
 * Garante a existência das tabelas, índices de performance e seed inicial do Passo 4
 * usando estritamente funções procedurais mysqli_*.
 */
function inicializar_schema_analytics_passo4($conn): void {
    // 1. Garante tabela `telemetria_postes` com índices otimizados por período e poste
    $sql_create = "
        CREATE TABLE IF NOT EXISTS telemetria_postes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            torre_id INT NOT NULL,
            pino_digital INT NOT NULL COMMENT 'Pinos PWM sequenciais Arduino Mega: 2, 3, 4, 5 e 6',
            nivel_pwm TINYINT UNSIGNED NOT NULL COMMENT 'Valor PWM 0..255',
            modo_iluminacao ENUM('DESLIGADA', 'ECONOMIA', 'NORMAL', 'CERRACAO') NOT NULL,
            status_operacional ENUM('OPERANDO', 'EM_MANUTENCAO') NOT NULL DEFAULT 'OPERANDO',
            conectado TINYINT(1) NOT NULL DEFAULT 1,
            consumo_watts DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            bateria_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            ldr_valor INT NULL DEFAULT NULL,
            origem_evento VARCHAR(40) NOT NULL DEFAULT 'DASHBOARD_WEB',
            registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telemetria_torre_data (torre_id, registrado_em),
            INDEX idx_telemetria_data (registrado_em),
            INDEX idx_telemetria_origem (origem_evento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    mysqli_query($conn, $sql_create);

    // 2. Verifica se já existem dados históricos para os gráficos de Analytics;
    //    caso esteja vazia, popula uma linha do tempo realista das últimas 24h.
    $res_count = mysqli_query($conn, "SELECT COUNT(*) AS total FROM telemetria_postes");
    $total_existente = 0;
    if ($res_count) {
        $row_c = mysqli_fetch_assoc($res_count);
        $total_existente = (int)($row_c['total'] ?? 0);
        mysqli_free_result($res_count);
    }

    if ($total_existente < 10) {
        $cenarios_seed = [
            // [minutos_atras, torre_id, pino, pwm, modo, status, conectado, consumo_w, bat, ldr, origem]
            [720, 1, 2,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 98.0, 680, 'SENSORES_SOFTWARE'],
            [720, 2, 3,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 98.0, 680, 'SENSORES_SOFTWARE'],
            [720, 3, 4,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 98.0, 680, 'SENSORES_SOFTWARE'],
            [720, 4, 5,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 98.0, 680, 'SENSORES_SOFTWARE'],
            [720, 5, 6,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 98.0, 680, 'SENSORES_SOFTWARE'],

            [360, 1, 2, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 95.0, 290, 'CASCATA'],
            [360, 2, 3, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 94.5, 290, 'CASCATA'],
            [360, 3, 4, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 94.0, 290, 'CASCATA'],
            [360, 4, 5, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 95.0, 290, 'CASCATA'],
            [360, 5, 6, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 94.0, 290, 'CASCATA'],

            [240, 1, 2, 255, 'CERRACAO',  'OPERANDO',      1, 400.00, 88.0, 180, 'CONTINGENCIA'],
            [240, 2, 3, 255, 'CERRACAO',  'OPERANDO',      1, 400.00, 87.5, 180, 'CONTINGENCIA'],
            [240, 3, 4, 255, 'CERRACAO',  'OPERANDO',      1, 400.00, 86.0, 180, 'CONTINGENCIA'],
            [240, 4, 5, 255, 'CERRACAO',  'OPERANDO',      1, 400.00, 88.0, 180, 'CONTINGENCIA'],
            [240, 5, 6, 255, 'CERRACAO',  'OPERANDO',      1, 400.00, 87.0, 180, 'CONTINGENCIA'],

            [180, 3, 4,   0, 'DESLIGADA', 'EM_MANUTENCAO', 1,   0.00, 82.0, 260, 'DASHBOARD_WEB'],
            [120, 1, 2,  70, 'ECONOMIA',  'OPERANDO',      1, 109.80, 79.0, 250, 'DASHBOARD_WEB'],
            [120, 2, 3,  70, 'ECONOMIA',  'OPERANDO',      1, 109.80, 78.5, 250, 'DASHBOARD_WEB'],
            [120, 4, 5,  70, 'ECONOMIA',  'OPERANDO',      1, 109.80, 80.0, 250, 'DASHBOARD_WEB'],
            [120, 5, 6,  70, 'ECONOMIA',  'OPERANDO',      1, 109.80, 79.5, 250, 'DASHBOARD_WEB'],

            [45,  1, 2, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 94.0, 310, 'ARDUINO_IOT'],
            [45,  2, 3, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 89.5, 310, 'ARDUINO_IOT'],
            [45,  4, 5,   0, 'DESLIGADA', 'OPERANDO',      1,   0.00, 97.0, 310, 'DASHBOARD_WEB'],
            [45,  5, 6, 170, 'NORMAL',    'OPERANDO',      1, 266.67, 91.0, 310, 'ARDUINO_IOT'],
        ];

        $stmt_seed = mysqli_prepare($conn, "
            INSERT INTO telemetria_postes (
                torre_id, pino_digital, nivel_pwm, modo_iluminacao,
                status_operacional, conectado, consumo_watts, bateria_pct,
                ldr_valor, origem_evento, registrado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))
        ");

        if ($stmt_seed) {
            foreach ($cenarios_seed as $c) {
                mysqli_stmt_bind_param(
                    $stmt_seed,
                    'iiissiddisi',
                    $c[1],
                    $c[2],
                    $c[3],
                    $c[4],
                    $c[5],
                    $c[6],
                    $c[7],
                    $c[8],
                    $c[9],
                    $c[10],
                    $c[0]
                );
                mysqli_stmt_execute($stmt_seed);
            }
            mysqli_stmt_close($stmt_seed);
        }
    }
}

/**
 * Converte o filtro de período ('1H', '6H', '24H', '7D', '30D', 'TODOS') em horas
 */
function horas_por_periodo(string $periodo): int {
    switch (strtoupper(trim($periodo))) {
        case '1H':    return 1;
        case '6H':    return 6;
        case '24H':   return 24;
        case '7D':    return 168;
        case '30D':   return 720;
        case 'TODOS': return 0;
        default:      return 24;
    }
}

inicializar_schema_analytics_passo4($conn);

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// 1. CONSULTA DE HISTÓRICO, SÉRIE TEMPORAL E KPIS DE ANALYTICS (GET)
// ============================================================================
if ($metodo === 'GET') {
    $periodo     = strtoupper(trim($_GET['periodo'] ?? '24H'));
    $torre_id    = isset($_GET['torre_id']) ? (int)$_GET['torre_id'] : 0;
    $origem      = strtoupper(trim($_GET['origem'] ?? 'TODOS'));
    $data_inicio = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
    $data_fim    = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
    $limite      = isset($_GET['limite']) ? max(10, min(250, (int)$_GET['limite'])) : 80;

    $horas_filtro = horas_por_periodo($periodo);

    // ------------------------------------------------------------------------
    // A. Estado Atual dos 5 Postes (Pinos PWM 2 a 6 no Arduino Mega)
    // ------------------------------------------------------------------------
    $res_atuais = mysqli_query($conn, "
        SELECT id, codigo, setor, pino_pwm, modo_iluminacao, nivel_brilho_atual,
               status_operacional, conectado, consumo_watts, bateria, ldr_atual, atualizado_em
          FROM torres
         ORDER BY pino_pwm ASC, id ASC
         LIMIT 5
    ");

    $postes_atuais     = [];
    $consumo_atual_w   = 0.0;
    $postes_online     = 0;
    $postes_operando   = 0;
    $postes_acesos     = 0;
    $soma_bateria_atual= 0.0;
    $ldr_atual         = 310;

    if ($res_atuais) {
        while ($t = mysqli_fetch_assoc($res_atuais)) {
            $id_t      = (int)$t['id'];
            $pino_t    = (int)$t['pino_pwm'];
            $status_op = (string)$t['status_operacional'];
            $conectado = (int)$t['conectado'] === 1;
            $pwm       = ($status_op === 'EM_MANUTENCAO' || !$conectado) ? 0 : (int)$t['nivel_brilho_atual'];
            $consumo_w = ($status_op === 'EM_MANUTENCAO' || !$conectado || $pwm <= 0)
                ? 0.0
                : round(($pwm / 255.0) * POTENCIA_MAX_POSTE_W, 2);
            $bat       = (float)$t['bateria'];
            $ldr_atual = (int)$t['ldr_atual'];

            $consumo_atual_w    += $consumo_w;
            $soma_bateria_atual += $bat;
            if ($conectado) $postes_online++;
            if ($status_op === 'OPERANDO' && $conectado) $postes_operando++;
            if ($pwm > 0) $postes_acesos++;

            $postes_atuais[$id_t] = [
                'torre_id'           => $id_t,
                'codigo'             => (string)$t['codigo'],
                'setor'              => (string)$t['setor'],
                'pino_pwm'           => $pino_t,
                'modo_atual'         => (string)$t['modo_iluminacao'],
                'pwm_atual'          => $pwm,
                'status_operacional' => $status_op,
                'conectado'          => $conectado,
                'consumo_atual_w'    => $consumo_w,
                'bateria_pct'        => round($bat, 1),
                'atualizado_em'      => $t['atualizado_em'],
                // Agregados históricos preenchidos a seguir
                'total_eventos'      => 0,
                'consumo_medio_w'    => $consumo_w,
                'pico_pwm'           => $pwm,
                'amostras_aceso_pct' => $pwm > 0 ? 100.0 : 0.0,
            ];
        }
        mysqli_free_result($res_atuais);
    }

    // ------------------------------------------------------------------------
    // B. Monta cláusula WHERE segura para o período e filtros
    // ------------------------------------------------------------------------
    $condicoes = ["1=1"];
    if ($data_inicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $data_inicio)) {
        $dt_ini_esc = mysqli_real_escape_string($conn, $data_inicio);
        $condicoes[] = "tp.registrado_em >= '{$dt_ini_esc}'";
    } elseif ($horas_filtro > 0) {
        $condicoes[] = "tp.registrado_em >= DATE_SUB(NOW(), INTERVAL {$horas_filtro} HOUR)";
    }

    if ($data_fim !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $data_fim)) {
        $dt_fim_esc = mysqli_real_escape_string($conn, $data_fim);
        $condicoes[] = "tp.registrado_em <= '{$dt_fim_esc}'";
    }

    if ($torre_id >= 1 && $torre_id <= 5) {
        $condicoes[] = "tp.torre_id = {$torre_id}";
    }

    if ($origem !== '' && $origem !== 'TODOS') {
        $origem_esc = mysqli_real_escape_string($conn, $origem);
        $condicoes[] = "tp.origem_evento = '{$origem_esc}'";
    }

    $where_sql = implode(' AND ', $condicoes);

    // ------------------------------------------------------------------------
    // C. Métricas Agregadas por Poste no Período Selecionado
    // ------------------------------------------------------------------------
    $sql_por_poste = "
        SELECT
            tp.torre_id,
            COUNT(*) AS total_eventos,
            ROUND(AVG(tp.consumo_watts), 2) AS consumo_medio_w,
            MAX(tp.nivel_pwm) AS pico_pwm,
            ROUND((SUM(CASE WHEN tp.nivel_pwm > 0 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) AS amostras_aceso_pct
        FROM telemetria_postes tp
        WHERE {$where_sql}
        GROUP BY tp.torre_id
    ";
    $res_pp = mysqli_query($conn, $sql_por_poste);
    $total_eventos_periodo = 0;

    if ($res_pp) {
        while ($r = mysqli_fetch_assoc($res_pp)) {
            $tid = (int)$r['torre_id'];
            $ev  = (int)$r['total_eventos'];
            $total_eventos_periodo += $ev;
            if (isset($postes_atuais[$tid])) {
                $postes_atuais[$tid]['total_eventos']      = $ev;
                $postes_atuais[$tid]['consumo_medio_w']    = (float)$r['consumo_medio_w'];
                $postes_atuais[$tid]['pico_pwm']           = (int)$r['pico_pwm'];
                $postes_atuais[$tid]['amostras_aceso_pct'] = (float)$r['amostras_aceso_pct'];
            }
        }
        mysqli_free_result($res_pp);
    }

    // ------------------------------------------------------------------------
    // D. Série Temporal para o Gráfico de Consumo e Eficiência
    // ------------------------------------------------------------------------
    $sql_serie = "
        SELECT
            DATE_FORMAT(tp.registrado_em, '%H:%i') AS horario_label,
            DATE_FORMAT(tp.registrado_em, '%Y-%m-%d %H:%i:00') AS bucket_minuto,
            ROUND(AVG(tp.consumo_watts) * 5, 2) AS consumo_estimado_planta_w,
            ROUND(AVG(tp.nivel_pwm), 0) AS pwm_medio,
            ROUND(AVG(tp.bateria_pct), 1) AS bateria_media,
            COUNT(*) AS eventos_bucket
        FROM telemetria_postes tp
        WHERE {$where_sql}
        GROUP BY bucket_minuto, horario_label
        ORDER BY bucket_minuto ASC
        LIMIT 36
    ";
    $res_serie = mysqli_query($conn, $sql_serie);
    $serie_temporal = [];
    $pico_consumo_w = $consumo_atual_w;
    $soma_consumo_serie = 0.0;

    if ($res_serie) {
        while ($s = mysqli_fetch_assoc($res_serie)) {
            $c_planta = min(CAPACIDADE_TOTAL_MINA_W, (float)$s['consumo_estimado_planta_w']);
            if ($c_planta > $pico_consumo_w) {
                $pico_consumo_w = $c_planta;
            }
            $soma_consumo_serie += $c_planta;
            $eficiencia_ponto = round(100.0 - (($c_planta / CAPACIDADE_TOTAL_MINA_W) * 100.0), 1);

            $serie_temporal[] = [
                'horario'         => $s['horario_label'],
                'timestamp'       => $s['bucket_minuto'],
                'consumo_total_w' => $c_planta,
                'pwm_medio'       => (int)$s['pwm_medio'],
                'bateria_media'   => (float)$s['bateria_media'],
                'economia_pct'    => max(0.0, $eficiencia_ponto),
                'eventos'         => (int)$s['eventos_bucket'],
            ];
        }
        mysqli_free_result($res_serie);
    }

    // Adiciona o ponto em tempo real ("Agora") no final da série para feedback instantâneo
    $serie_temporal[] = [
        'horario'         => 'Agora',
        'timestamp'       => date('Y-m-d H:i:s'),
        'consumo_total_w' => round($consumo_atual_w, 2),
        'pwm_medio'       => (int)round(($consumo_atual_w / CAPACIDADE_TOTAL_MINA_W) * 255),
        'bateria_media'   => count($postes_atuais) > 0 ? round($soma_bateria_atual / count($postes_atuais), 1) : 92.0,
        'economia_pct'    => round(100.0 - (($consumo_atual_w / CAPACIDADE_TOTAL_MINA_W) * 100.0), 1),
        'eventos'         => 1,
    ];

    // ------------------------------------------------------------------------
    // E. Lista Detalhada de Logs de Auditoria Operacional
    // ------------------------------------------------------------------------
    $sql_logs = "
        SELECT
            tp.id,
            tp.torre_id,
            t.codigo AS torre_codigo,
            t.setor AS torre_setor,
            tp.pino_digital,
            tp.nivel_pwm,
            tp.modo_iluminacao,
            tp.status_operacional,
            tp.conectado,
            tp.consumo_watts,
            tp.bateria_pct,
            tp.ldr_valor,
            tp.origem_evento,
            tp.registrado_em
        FROM telemetria_postes tp
        INNER JOIN torres t ON t.id = tp.torre_id
        WHERE {$where_sql}
        ORDER BY tp.id DESC
        LIMIT {$limite}
    ";
    $res_logs = mysqli_query($conn, $sql_logs);
    $logs_auditoria = [];
    $acionamentos_cascata = 0;

    if ($res_logs) {
        while ($l = mysqli_fetch_assoc($res_logs)) {
            if (in_array($l['origem_evento'], ['CASCATA', 'CONTINGENCIA'], true)) {
                $acionamentos_cascata++;
            }
            $logs_auditoria[] = [
                'id'                 => (int)$l['id'],
                'torre_id'           => (int)$l['torre_id'],
                'torre_codigo'       => (string)$l['torre_codigo'],
                'torre_setor'        => (string)$l['torre_setor'],
                'pino_digital'       => (int)$l['pino_digital'],
                'nivel_pwm'          => (int)$l['nivel_pwm'],
                'modo_iluminacao'    => (string)$l['modo_iluminacao'],
                'status_operacional' => (string)$l['status_operacional'],
                'conectado'          => (int)$l['conectado'] === 1,
                'consumo_watts'      => (float)$l['consumo_watts'],
                'bateria_pct'        => (float)$l['bateria_pct'],
                'ldr_valor'          => $l['ldr_valor'] !== null ? (int)$l['ldr_valor'] : 310,
                'origem_evento'      => (string)$l['origem_evento'],
                'registrado_em'      => (string)$l['registrado_em'],
            ];
        }
        mysqli_free_result($res_logs);
    }

    // ------------------------------------------------------------------------
    // F. Cálculo de KPIs Globais de Desempenho da Planta
    // ------------------------------------------------------------------------
    $qtd_pontos_serie     = max(1, count($serie_temporal));
    $consumo_medio_w      = round(($soma_consumo_serie + $consumo_atual_w) / $qtd_pontos_serie, 2);
    $horas_calculo_kwh    = $horas_filtro > 0 ? $horas_filtro : 24;
    $energia_estimada_kwh = round(($consumo_medio_w * $horas_calculo_kwh) / 1000.0, 2);
    $energia_maxima_kwh   = round((CAPACIDADE_TOTAL_MINA_W * $horas_calculo_kwh) / 1000.0, 2);
    $energia_poupada_kwh  = max(0.0, round($energia_maxima_kwh - $energia_estimada_kwh, 2));
    $eficiencia_pct       = round(100.0 - (($consumo_atual_w / CAPACIDADE_TOTAL_MINA_W) * 100.0), 1);
    $disponibilidade_sla  = count($postes_atuais) > 0
        ? round(($postes_operando / count($postes_atuais)) * 100.0, 1)
        : 100.0;

    responder_json(200, [
        'sucesso'        => true,
        'dispositivo'    => 'ARDUINO_MEGA_UNIFICADO',
        'pinos_pwm'      => [2, 3, 4, 5, 6],
        'filtro_aplicado'=> [
            'periodo'  => $periodo,
            'torre_id' => $torre_id,
            'origem'   => $origem,
            'limite'   => $limite,
        ],
        'kpis' => [
            'consumo_atual_w'          => round($consumo_atual_w, 2),
            'consumo_atual_kw'         => round($consumo_atual_w / 1000.0, 2),
            'consumo_medio_periodo_w'  => $consumo_medio_w,
            'pico_consumo_w'           => round($pico_consumo_w, 2),
            'capacidade_instalada_w'   => CAPACIDADE_TOTAL_MINA_W,
            'energia_estimada_kwh'     => $energia_estimada_kwh,
            'energia_poupada_kwh'      => $energia_poupada_kwh,
            'eficiencia_energetica_pct'=> max(0.0, $eficiencia_pct),
            'disponibilidade_sla_pct'  => $disponibilidade_sla,
            'postes_online'            => $postes_online,
            'postes_operando'          => $postes_operando,
            'postes_acesos'            => $postes_acesos,
            'total_postes'             => count($postes_atuais),
            'total_eventos_periodo'    => $total_eventos_periodo,
            'acionamentos_cascata'     => $acionamentos_cascata,
            'bateria_media_pct'        => count($postes_atuais) > 0 ? round($soma_bateria_atual / count($postes_atuais), 1) : 92.0,
            'ldr_atual'                => $ldr_atual,
        ],
        'por_poste'      => array_values($postes_atuais),
        'serie_temporal' => $serie_temporal,
        'logs_auditoria' => $logs_auditoria,
    ], $conn);
}

// ============================================================================
// 2. INGESTÃO DE TELEMETRIA DO ARDUINO MEGA / PONTE PYTHON (POST)
//    Com deduplicação inteligente para não sobrecarregar o MySQL
// ============================================================================
if ($metodo === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'JSON inválido.'], $conn);
    }

    $ldr_lido     = isset($corpo['ldr']) ? (int)$corpo['ldr'] : 310;
    $bateria_lida = isset($corpo['bateria']) ? (float)$corpo['bateria'] : 92.0;
    $origem       = strtoupper(trim($corpo['origem_evento'] ?? 'ARDUINO_IOT'));
    $lista_postes = $corpo['postes'] ?? $corpo['torres'] ?? [];

    $registros_gravados = 0;

    mysqli_begin_transaction($conn);

    foreach ($lista_postes as $idx => $item) {
        $torre_id  = is_array($item) && isset($item['id']) ? (int)$item['id'] : ($idx + 1);
        $pino_dig  = is_array($item) && isset($item['pino']) ? (int)$item['pino'] : (1 + $torre_id);
        $pwm_val   = is_array($item) && isset($item['pwm']) ? (int)$item['pwm'] : (int)$item;
        $conectado = is_array($item) && isset($item['conectado']) ? ($item['conectado'] ? 1 : 0) : 1;

        $pwm_val = max(0, min(255, $pwm_val));
        $modo    = ($pwm_val <= 0) ? 'DESLIGADA' : (($pwm_val <= 120) ? 'ECONOMIA' : (($pwm_val <= 212) ? 'NORMAL' : 'CERRACAO'));

        // Verifica o último registro gravado para este poste para deduplicar leituras idênticas
        $stmt_ult = mysqli_prepare($conn, "
            SELECT nivel_pwm, conectado, TIMESTAMPDIFF(SECOND, registrado_em, NOW()) AS segs
              FROM telemetria_postes
             WHERE torre_id = ?
             ORDER BY id DESC
             LIMIT 1
        ");
        $deve_gravar = true;
        if ($stmt_ult) {
            mysqli_stmt_bind_param($stmt_ult, 'i', $torre_id);
            mysqli_stmt_execute($stmt_ult);
            $res_u = mysqli_stmt_get_result($stmt_ult);
            if ($ult = mysqli_fetch_assoc($res_u)) {
                $mesmo_pwm  = ((int)$ult['nivel_pwm'] === $pwm_val);
                $mesma_conn = ((int)$ult['conectado'] === $conectado);
                $segs       = (int)$ult['segs'];
                // Só grava se mudou o PWM/conectividade ou a cada 60s como heartbeat histórico
                if ($mesmo_pwm && $mesma_conn && $segs < 60) {
                    $deve_gravar = false;
                }
            }
            mysqli_stmt_close($stmt_ult);
        }

        if ($deve_gravar) {
            $consumo_w = ($conectado === 0 || $pwm_val <= 0) ? 0.0 : round(($pwm_val / 255.0) * POTENCIA_MAX_POSTE_W, 2);
            $stmt_ins = mysqli_prepare($conn, "
                INSERT INTO telemetria_postes (
                    torre_id, pino_digital, nivel_pwm, modo_iluminacao,
                    status_operacional, conectado, consumo_watts, bateria_pct,
                    ldr_valor, origem_evento
                ) VALUES (?, ?, ?, ?, 'OPERANDO', ?, ?, ?, ?, ?)
            ");
            if ($stmt_ins) {
                mysqli_stmt_bind_param(
                    $stmt_ins,
                    'iiisiddis',
                    $torre_id,
                    $pino_dig,
                    $pwm_val,
                    $modo,
                    $conectado,
                    $consumo_w,
                    $bateria_lida,
                    $ldr_lido,
                    $origem
                );
                mysqli_stmt_execute($stmt_ins);
                mysqli_stmt_close($stmt_ins);
                $registros_gravados++;
            }
        }
    }

    mysqli_commit($conn);

    responder_json(200, [
        'sucesso'            => true,
        'registros_gravados' => $registros_gravados,
        'mensagem'           => 'Telemetria processada com sucesso.'
    ], $conn);
}

responder_json(405, ['sucesso' => false, 'erro' => 'Método não suportado.'], $conn);
