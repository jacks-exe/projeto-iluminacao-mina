<?php
/**
 * GEONEX SCADA - Endpoint REST de Ordens de Serviço (O.S.) e Máquina de Estados de Manutenção
 * Estilo: PHP Puro Procedural com mysqli + Prepared Statements + RBAC
 *
 * Métodos suportados:
 *  - GET  /ordens_servico.php[?status=ABERTA] -> Lista O.S. (ADMIN, OPERADOR, TECNICO)
 *  - POST /ordens_servico.php                 -> Cria nova O.S., salva último PWM e bloqueia torre (em_manutencao = true)
 *  - PUT  /ordens_servico.php                 -> Atualiza status/laudo; ao concluir, restaura em_manutencao = false e envia PWM anterior via Serial
 */

require_once __DIR__ . '/conexao.php';

/**
 * Determina o modo de iluminação correspondente ao valor PWM (0..255)
 */
function modo_iluminacao_por_pwm_os(int $pwm): string {
    if ($pwm <= 0) return 'DESLIGADA';
    if ($pwm <= 120) return 'ECONOMIA';
    if ($pwm <= 212) return 'NORMAL';
    return 'CERRACAO';
}

/**
 * Dispara o comando serial para a Ponte IoT Python quando uma O.S. bloqueia
 * ou libera uma torre específica (restaurando o último PWM salvo antes da manutenção).
 */
function notificar_ponte_os_evento(int $torre_id, int $em_manutencao, ?int $pwm_restaurado = null): bool {
    if ($torre_id < 1 || $torre_id > 5) return false;
    $flag = $em_manutencao ? 1 : 0;

    if ($flag === 1) {
        $comando_serial = "MANUT:{$torre_id},1\nPOSTE:{$torre_id},0\n";
    } else {
        $pwm_limpo = max(0, min(255, $pwm_restaurado ?? 170));
        $comando_serial = "MANUT:{$torre_id},0,{$pwm_limpo}\nPOSTE:{$torre_id},{$pwm_limpo}\n";
    }

    $payload = json_encode([
        'comando'       => $comando_serial,
        'acao'          => 'MANUTENCAO',
        'id'            => $torre_id,
        'em_manutencao' => (bool)$flag,
        'pwm'           => $pwm_restaurado,
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 0.45,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents('http://127.0.0.1:5005/evento', false, $ctx);
    if ($resp !== false) {
        $json = json_decode($resp, true);
        return !empty($json['sucesso']);
    }
    return false;
}

$metodo = $_SERVER['REQUEST_METHOD'];

// Lê o perfil e ID do usuário logado (via Header HTTP ou fallback para sessão/payload)
$header_role = $_SERVER['HTTP_X_USER_ROLE'] ?? '';
$header_uid  = isset($_SERVER['HTTP_X_USER_ID']) ? (int)$_SERVER['HTTP_X_USER_ID'] : 0;

// ============================================================================
// 1. LISTAR ORDENS DE SERVIÇO (GET)
//    Permissão: ADMIN, OPERADOR, TECNICO
// ============================================================================
if ($metodo === 'GET') {
    $filtro_status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : '';

    $sql = "
        SELECT 
            os.id,
            os.torre_id,
            t.codigo AS torre_codigo,
            t.setor AS torre_setor,
            t.status_operacional AS torre_status,
            t.em_manutencao AS torre_em_manutencao,
            t.pwm_antes_manutencao,
            os.usuario_criador_id,
            uc.nome AS criador_nome,
            uc.role AS criador_role,
            os.tecnico_responsavel_id,
            ut.nome AS tecnico_nome,
            os.prioridade,
            os.status,
            os.descricao_problema,
            os.laudo_solucao,
            os.data_abertura,
            os.data_inicio_atendimento,
            os.data_conclusao
        FROM ordens_servico os
        INNER JOIN torres t    ON t.id = os.torre_id
        INNER JOIN usuarios uc ON uc.id = os.usuario_criador_id
        LEFT JOIN  usuarios ut ON ut.id = os.tecnico_responsavel_id
    ";

    $status_validos = ['ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA'];

    if ($filtro_status !== '' && in_array($filtro_status, $status_validos, true)) {
        $sql .= " WHERE os.status = ? ";
        $sql .= " ORDER BY FIELD(os.prioridade, 'CRITICA', 'ALTA', 'MEDIA', 'BAIXA'), os.data_abertura DESC";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $filtro_status);
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);
    } else {
        $sql .= " ORDER BY FIELD(os.status, 'ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA'),
                           FIELD(os.prioridade, 'CRITICA', 'ALTA', 'MEDIA', 'BAIXA'),
                           os.data_abertura DESC";
        $resultado = mysqli_query($conn, $sql);
    }

    if (!$resultado) {
        responder_json(500, [
            'sucesso' => false,
            'erro'    => 'Erro ao consultar Ordens de Serviço: ' . mysqli_error($conn)
        ], $conn);
    }

    $ordens = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $linha['id']                     = (int)$linha['id'];
        $linha['torre_id']               = (int)$linha['torre_id'];
        $linha['torre_em_manutencao']    = (int)($linha['torre_em_manutencao'] ?? 0) === 1;
        $linha['pwm_antes_manutencao']   = (int)($linha['pwm_antes_manutencao'] ?? 170);
        $linha['usuario_criador_id']     = (int)$linha['usuario_criador_id'];
        $linha['tecnico_responsavel_id'] = $linha['tecnico_responsavel_id'] !== null
            ? (int)$linha['tecnico_responsavel_id']
            : null;
        $ordens[] = $linha;
    }

    if (isset($stmt)) {
        mysqli_stmt_close($stmt);
    }

    responder_json(200, [
        'sucesso' => true,
        'total'   => count($ordens),
        'dados'   => $ordens
    ], $conn);
}

// ============================================================================
// 2. CRIAR NOVA ORDEM DE SERVIÇO (POST)
//    Permissão RBAC: Apenas ADMIN e OPERADOR podem abrir O.S.
// ============================================================================
if ($metodo === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'JSON inválido no corpo da requisição.'], $conn);
    }

    $role_usuario       = $header_role ?: ($corpo['usuario_role'] ?? 'OPERADOR');
    $usuario_criador_id = $header_uid  ?: (int)($corpo['usuario_criador_id'] ?? 1);

    // RBAC: Verifica se é ADMIN ou OPERADOR
    validar_rbac($role_usuario, ['ADMIN', 'OPERADOR'], $conn);

    $torre_id           = isset($corpo['torre_id']) ? (int)$corpo['torre_id'] : 0;
    $prioridade         = strtoupper(trim($corpo['prioridade'] ?? 'MEDIA'));
    $descricao_problema = trim($corpo['descricao_problema'] ?? '');
    $bloquear_torre     = isset($corpo['bloquear_torre']) ? (bool)$corpo['bloquear_torre'] : true;

    $prioridades_validas = ['BAIXA', 'MEDIA', 'ALTA', 'CRITICA'];

    if ($torre_id <= 0 || $usuario_criador_id <= 0 || $descricao_problema === '') {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Campos obrigatórios: torre_id, usuario_criador_id e descricao_problema.'
        ], $conn);
    }

    if (!in_array($prioridade, $prioridades_validas, true)) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Prioridade inválida. Use: BAIXA, MEDIA, ALTA ou CRITICA.'
        ], $conn);
    }

    // Garante que o usuario_criador_id exista no banco (fallback para ID 1 se vier de sessão mock)
    $stmt_usr = mysqli_prepare($conn, "SELECT id FROM usuarios WHERE id = ? LIMIT 1");
    if ($stmt_usr) {
        mysqli_stmt_bind_param($stmt_usr, 'i', $usuario_criador_id);
        mysqli_stmt_execute($stmt_usr);
        $res_usr = mysqli_stmt_get_result($stmt_usr);
        if (!mysqli_fetch_assoc($res_usr)) {
            $usuario_criador_id = 1;
        }
        mysqli_stmt_close($stmt_usr);
    }

    mysqli_begin_transaction($conn);

    // 1. Consulta estado atual da torre para preservar o último PWM antes da manutenção
    $stmt_t = mysqli_prepare($conn, "
        SELECT pino_pwm, nivel_brilho_atual, pwm_antes_manutencao, status_operacional, em_manutencao, conectado, bateria, ldr_atual
          FROM torres
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_t, 'i', $torre_id);
    mysqli_stmt_execute($stmt_t);
    $res_t = mysqli_stmt_get_result($stmt_t);
    $torre_atual = mysqli_fetch_assoc($res_t);
    mysqli_stmt_close($stmt_t);

    if (!$torre_atual) {
        mysqli_rollback($conn);
        responder_json(404, ['sucesso' => false, 'erro' => "Torre #{$torre_id} não encontrada."], $conn);
    }

    // 2. Insere a Ordem de Serviço
    $sql_insert = "
        INSERT INTO ordens_servico (torre_id, usuario_criador_id, prioridade, status, descricao_problema)
        VALUES (?, ?, ?, 'ABERTA', ?)
    ";
    $stmt = mysqli_prepare($conn, $sql_insert);
    if (!$stmt) {
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => mysqli_error($conn)], $conn);
    }

    mysqli_stmt_bind_param($stmt, 'iiss', $torre_id, $usuario_criador_id, $prioridade, $descricao_problema);
    $executou = mysqli_stmt_execute($stmt);

    if (!$executou) {
        $erro_msg = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => 'Erro ao registrar O.S.: ' . $erro_msg], $conn);
    }

    $nova_os_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // 3. Máquina de Estados de Manutenção: coloca a torre em manutenção (em_manutencao = true),
    //    salva o último PWM antes da manutenção e bloqueia acionamentos
    $colocar_em_manut = $bloquear_torre || in_array($prioridade, ['ALTA', 'CRITICA'], true);
    $ja_estava_manut  = ((int)$torre_atual['em_manutencao'] === 1) || ($torre_atual['status_operacional'] === 'EM_MANUTENCAO');
    $pwm_memorizado   = $ja_estava_manut
        ? (int)$torre_atual['pwm_antes_manutencao']
        : (int)$torre_atual['nivel_brilho_atual'];

    if ($colocar_em_manut) {
        $sql_torre = "
            UPDATE torres
               SET status_operacional = 'EM_MANUTENCAO',
                   em_manutencao = 1,
                   pwm_antes_manutencao = ?,
                   consumo_watts = 0.00
             WHERE id = ?
        ";
        $stmt_torre = mysqli_prepare($conn, $sql_torre);
        mysqli_stmt_bind_param($stmt_torre, 'ii', $pwm_memorizado, $torre_id);
        mysqli_stmt_execute($stmt_torre);
        mysqli_stmt_close($stmt_torre);

        // Registra evento de entrada em manutenção no histórico de auditoria
        $stmt_log = mysqli_prepare($conn, "
            INSERT INTO telemetria_postes (
                torre_id, pino_digital, nivel_pwm, modo_iluminacao,
                status_operacional, conectado, consumo_watts, bateria_pct,
                ldr_valor, origem_evento
            ) VALUES (?, ?, 0, 'DESLIGADA', 'EM_MANUTENCAO', ?, 0.00, ?, ?, 'BLOQUEIO_OS')
        ");
        if ($stmt_log) {
            $pino_d = (int)$torre_atual['pino_pwm'];
            $conn_d = (int)$torre_atual['conectado'];
            $bat_d  = (float)$torre_atual['bateria'];
            $ldr_d  = (int)$torre_atual['ldr_atual'];
            mysqli_stmt_bind_param($stmt_log, 'iiidi', $torre_id, $pino_d, $conn_d, $bat_d, $ldr_d);
            mysqli_stmt_execute($stmt_log);
            mysqli_stmt_close($stmt_log);
        }
    }

    mysqli_commit($conn);

    if ($colocar_em_manut) {
        notificar_ponte_os_evento($torre_id, 1, 0);
    }

    responder_json(201, [
        'sucesso'              => true,
        'mensagem'             => "Ordem de Serviço #{$nova_os_id} aberta com sucesso.",
        'id'                   => $nova_os_id,
        'em_manutencao'        => $colocar_em_manut,
        'pwm_antes_manutencao' => $pwm_memorizado,
    ], $conn);
}

// ============================================================================
// 3. ATUALIZAR STATUS E LAUDO TÉCNICO (PUT)
//    Permissão RBAC: Apenas ADMIN e TECNICO podem alterar status e laudo
// ============================================================================
if ($metodo === 'PUT') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'JSON inválido no corpo da requisição.'], $conn);
    }

    $role_usuario           = $header_role ?: ($corpo['usuario_role'] ?? 'TECNICO');
    $tecnico_responsavel_id = $header_uid  ?: (int)($corpo['tecnico_responsavel_id'] ?? 0);

    // RBAC: Verifica se é ADMIN ou TECNICO
    validar_rbac($role_usuario, ['ADMIN', 'TECNICO'], $conn);

    $os_id       = isset($corpo['id']) ? (int)$corpo['id'] : 0;
    $novo_status = strtoupper(trim($corpo['status'] ?? ''));
    $laudo       = isset($corpo['laudo_solucao']) ? trim($corpo['laudo_solucao']) : null;

    $status_permitidos = ['ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA', 'CANCELADA'];

    if ($os_id <= 0 || !in_array($novo_status, $status_permitidos, true)) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe um ID de O.S. válido e um status permitido (EM_ANDAMENTO, CONCLUIDA, CANCELADA).'
        ], $conn);
    }

    // Regra de negócio industrial: para concluir uma O.S., o laudo técnico é obrigatório
    if ($novo_status === 'CONCLUIDA' && ($laudo === null || $laudo === '')) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'O preenchimento do Laudo Técnico de Solução é obrigatório para concluir a O.S.'
        ], $conn);
    }

    // Busca qual é a torre associada a esta O.S.
    $stmt_os = mysqli_prepare($conn, "SELECT torre_id FROM ordens_servico WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt_os, 'i', $os_id);
    mysqli_stmt_execute($stmt_os);
    $res_os = mysqli_stmt_get_result($stmt_os);
    $os_row = mysqli_fetch_assoc($res_os);
    mysqli_stmt_close($stmt_os);

    if (!$os_row) {
        responder_json(404, ['sucesso' => false, 'erro' => "Ordem de Serviço #{$os_id} não encontrada."], $conn);
    }

    $torre_id = (int)$os_row['torre_id'];

    mysqli_begin_transaction($conn);

    // Atualiza datas de controle conforme transição de status
    if ($novo_status === 'EM_ANDAMENTO') {
        $sql_update = "
            UPDATE ordens_servico
               SET status = ?,
                   laudo_solucao = COALESCE(?, laudo_solucao),
                   tecnico_responsavel_id = IF(? > 0, ?, tecnico_responsavel_id),
                   data_inicio_atendimento = COALESCE(data_inicio_atendimento, NOW())
             WHERE id = ?
        ";
    } elseif ($novo_status === 'CONCLUIDA') {
        $sql_update = "
            UPDATE ordens_servico
               SET status = ?,
                   laudo_solucao = ?,
                   tecnico_responsavel_id = IF(? > 0, ?, tecnico_responsavel_id),
                   data_inicio_atendimento = COALESCE(data_inicio_atendimento, NOW()),
                   data_conclusao = NOW()
             WHERE id = ?
        ";
    } else {
        $sql_update = "
            UPDATE ordens_servico
               SET status = ?,
                   laudo_solucao = COALESCE(?, laudo_solucao),
                   tecnico_responsavel_id = IF(? > 0, ?, tecnico_responsavel_id)
             WHERE id = ?
        ";
    }

    $stmt = mysqli_prepare($conn, $sql_update);
    if (!$stmt) {
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => mysqli_error($conn)], $conn);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssiii',
        $novo_status,
        $laudo,
        $tecnico_responsavel_id,
        $tecnico_responsavel_id,
        $os_id
    );

    $executou = mysqli_stmt_execute($stmt);
    if (!$executou) {
        $erro_msg = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        mysqli_rollback($conn);
        responder_json(500, ['sucesso' => false, 'erro' => 'Falha ao atualizar O.S.: ' . $erro_msg], $conn);
    }
    mysqli_stmt_close($stmt);

    $torre_liberada     = false;
    $pwm_restaurado     = null;
    $modo_restaurado    = null;
    $serial_notificado  = false;

    // Se a O.S. foi CONCLUIDA ou CANCELADA, verifica se não há outras O.S. ativas para a mesma torre
    if (in_array($novo_status, ['CONCLUIDA', 'CANCELADA'], true)) {
        $stmt_pend = mysqli_prepare($conn, "
            SELECT COUNT(*) AS pendentes
              FROM ordens_servico
             WHERE torre_id = ?
               AND id <> ?
               AND status IN ('ABERTA', 'EM_ANDAMENTO')
        ");
        mysqli_stmt_bind_param($stmt_pend, 'ii', $torre_id, $os_id);
        mysqli_stmt_execute($stmt_pend);
        $res_pend = mysqli_stmt_get_result($stmt_pend);
        $row_pend = mysqli_fetch_assoc($res_pend);
        $outras_pendentes = (int)($row_pend['pendentes'] ?? 0);
        mysqli_stmt_close($stmt_pend);

        if ($outras_pendentes === 0) {
            // Recupera do banco o último valor de intensidade luminosa/PWM que a torre possuía antes da manutenção
            $stmt_torre = mysqli_prepare($conn, "
                SELECT pino_pwm, nivel_brilho_atual, pwm_antes_manutencao, conectado, bateria, ldr_atual
                  FROM torres
                 WHERE id = ?
                 LIMIT 1
            ");
            mysqli_stmt_bind_param($stmt_torre, 'i', $torre_id);
            mysqli_stmt_execute($stmt_torre);
            $res_torre = mysqli_stmt_get_result($stmt_torre);
            $dados_torre = mysqli_fetch_assoc($res_torre);
            mysqli_stmt_close($stmt_torre);

            if ($dados_torre) {
                $pwm_salvo = isset($dados_torre['pwm_antes_manutencao'])
                    ? (int)$dados_torre['pwm_antes_manutencao']
                    : (int)$dados_torre['nivel_brilho_atual'];

                // Caso pwm_antes_manutencao não tenha sido preenchido, consulta também o último log OPERANDO em telemetria_postes
                if ($pwm_salvo <= 0 && (int)$dados_torre['nivel_brilho_atual'] > 0) {
                    $pwm_salvo = (int)$dados_torre['nivel_brilho_atual'];
                } elseif ($pwm_salvo <= 0) {
                    $stmt_ult_log = mysqli_prepare($conn, "
                        SELECT nivel_pwm
                          FROM telemetria_postes
                         WHERE torre_id = ?
                           AND status_operacional = 'OPERANDO'
                           AND origem_evento <> 'BLOQUEIO_OS'
                         ORDER BY id DESC
                         LIMIT 1
                    ");
                    if ($stmt_ult_log) {
                        mysqli_stmt_bind_param($stmt_ult_log, 'i', $torre_id);
                        mysqli_stmt_execute($stmt_ult_log);
                        $res_ult = mysqli_stmt_get_result($stmt_ult_log);
                        if ($row_ult = mysqli_fetch_assoc($res_ult)) {
                            $pwm_salvo = (int)$row_ult['nivel_pwm'];
                        }
                        mysqli_stmt_close($stmt_ult_log);
                    }
                }

                $pwm_restaurado     = max(0, min(255, $pwm_salvo));
                $modo_restaurado    = modo_iluminacao_por_pwm_os($pwm_restaurado);
                $conectado_atual    = (int)$dados_torre['conectado'];
                $consumo_restaurado = ($conectado_atual === 0 || $pwm_restaurado <= 0)
                    ? 0.00
                    : round(($pwm_restaurado / 255.0) * 400.0, 2);

                // Altera em_manutencao para false (0) e status_operacional para 'OPERANDO' com o PWM recuperado
                $stmt_lib = mysqli_prepare($conn, "
                    UPDATE torres
                       SET status_operacional = 'OPERANDO',
                           em_manutencao = 0,
                           nivel_brilho_atual = ?,
                           modo_iluminacao = ?,
                           consumo_watts = ?
                     WHERE id = ?
                ");
                mysqli_stmt_bind_param(
                    $stmt_lib,
                    'isdi',
                    $pwm_restaurado,
                    $modo_restaurado,
                    $consumo_restaurado,
                    $torre_id
                );
                mysqli_stmt_execute($stmt_lib);
                mysqli_stmt_close($stmt_lib);

                // Registra log de auditoria da liberação e religamento automático
                $stmt_log_lib = mysqli_prepare($conn, "
                    INSERT INTO telemetria_postes (
                        torre_id, pino_digital, nivel_pwm, modo_iluminacao,
                        status_operacional, conectado, consumo_watts, bateria_pct,
                        ldr_valor, origem_evento
                    ) VALUES (?, ?, ?, ?, 'OPERANDO', ?, ?, ?, ?, 'RETORNO_MANUTENCAO')
                ");
                if ($stmt_log_lib) {
                    $pino_d = (int)$dados_torre['pino_pwm'];
                    $bat_d  = (float)$dados_torre['bateria'];
                    $ldr_d  = (int)$dados_torre['ldr_atual'];
                    mysqli_stmt_bind_param(
                        $stmt_log_lib,
                        'iiisiddi',
                        $torre_id,
                        $pino_d,
                        $pwm_restaurado,
                        $modo_restaurado,
                        $conectado_atual,
                        $consumo_restaurado,
                        $bat_d,
                        $ldr_d
                    );
                    mysqli_stmt_execute($stmt_log_lib);
                    mysqli_stmt_close($stmt_log_lib);
                }

                $torre_liberada = true;
            }
        }
    }

    mysqli_commit($conn);

    // Envia automaticamente o comando serial para o Arduino religar a torre naquele exato estado de PWM
    if ($torre_liberada && $pwm_restaurado !== null) {
        $serial_notificado = notificar_ponte_os_evento($torre_id, 0, $pwm_restaurado);
    }

    responder_json(200, [
        'sucesso'           => true,
        'mensagem'          => $torre_liberada
            ? "O.S. #{$os_id} concluída! Torre liberada e religada automaticamente em PWM {$pwm_restaurado} ({$modo_restaurado})."
            : "Ordem de Serviço #{$os_id} atualizada para '{$novo_status}'.",
        'torre_liberada'    => $torre_liberada,
        'torre_id'          => $torre_id,
        'pwm_restaurado'    => $pwm_restaurado,
        'modo_restaurado'   => $modo_restaurado,
        'enviado_serial'    => $serial_notificado,
    ], $conn);
}

// Método HTTP não permitido
responder_json(405, [
    'sucesso' => false,
    'erro'    => "Método HTTP '{$metodo}' não suportado neste endpoint."
], $conn);
