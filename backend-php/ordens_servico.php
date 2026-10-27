<?php
/**
 * GEONEX SCADA - Endpoint REST de Ordens de Serviço (O.S.)
 * Estilo: PHP Puro Procedural com mysqli + Prepared Statements + RBAC
 *
 * Métodos suportados:
 *  - GET  /ordens_servico.php[?status=ABERTA] -> Lista O.S. (ADMIN, OPERADOR, TECNICO)
 *  - POST /ordens_servico.php                 -> Cria nova O.S. (ADMIN, OPERADOR)
 *  - PUT  /ordens_servico.php                 -> Atualiza status e laudo técnico (ADMIN, TECNICO)
 */

require_once __DIR__ . '/conexao.php';

/**
 * Dispara o comando isolado MANUT:<torre_id>,<0|1>\n para a Ponte IoT Python
 * quando uma O.S. bloqueia ou libera uma torre específica.
 */
function notificar_ponte_os_evento(int $torre_id, int $em_manutencao): void {
    if ($torre_id < 1 || $torre_id > 5) return;
    $flag = $em_manutencao ? 1 : 0;
    $payload = json_encode([
        'comando' => "MANUT:{$torre_id},{$flag}\n",
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 0.35,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents('http://127.0.0.1:5005/evento', false, $ctx);
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
    $usuario_criador_id = $header_uid  ?: (int)($corpo['usuario_criador_id'] ?? 0);

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

    // Inicia transação para inserir a O.S. e colocar a torre em manutenção com segurança
    mysqli_begin_transaction($conn);

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

    // Se solicitado (ou prioridade ALTA/CRITICA), marca a torre como EM_MANUTENCAO no SCADA e zera consumo
    if ($bloquear_torre || in_array($prioridade, ['ALTA', 'CRITICA'], true)) {
        $sql_torre = "UPDATE torres SET status_operacional = 'EM_MANUTENCAO', consumo_watts = 0.00 WHERE id = ?";
        $stmt_torre = mysqli_prepare($conn, $sql_torre);
        mysqli_stmt_bind_param($stmt_torre, 'i', $torre_id);
        mysqli_stmt_execute($stmt_torre);
        mysqli_stmt_close($stmt_torre);
    }

    mysqli_commit($conn);
    if ($bloquear_torre || in_array($prioridade, ['ALTA', 'CRITICA'], true)) {
        notificar_ponte_os_evento($torre_id, 1);
    }

    responder_json(201, [
        'sucesso'  => true,
        'mensagem' => "Ordem de Serviço #{$nova_os_id} aberta com sucesso.",
        'id'       => $nova_os_id
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

    // Se a O.S. foi CONCLUIDA ou CANCELADA, verifica se não há outras O.S. abertas para a mesma torre e libera a torre
    if (in_array($novo_status, ['CONCLUIDA', 'CANCELADA'], true)) {
        $sql_liberar = "
            UPDATE torres t
            INNER JOIN ordens_servico os ON os.torre_id = t.id
               SET t.status_operacional = 'OPERANDO',
                   t.consumo_watts = ROUND((t.nivel_brilho_atual / 255.0) * 400.0, 2)
             WHERE os.id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM ordens_servico os2
                    WHERE os2.torre_id = t.id
                      AND os2.id <> os.id
                      AND os2.status IN ('ABERTA', 'EM_ANDAMENTO')
               )
        ";
        $stmt_lib = mysqli_prepare($conn, $sql_liberar);
        mysqli_stmt_bind_param($stmt_lib, 'i', $os_id);
        mysqli_stmt_execute($stmt_lib);
        mysqli_stmt_close($stmt_lib);
    }

    mysqli_commit($conn);

    responder_json(200, [
        'sucesso'  => true,
        'mensagem' => "Ordem de Serviço #{$os_id} atualizada para '{$novo_status}'."
    ], $conn);
}

// Método HTTP não permitido
responder_json(405, [
    'sucesso' => false,
    'erro'    => "Método HTTP '{$metodo}' não suportado neste endpoint."
], $conn);
