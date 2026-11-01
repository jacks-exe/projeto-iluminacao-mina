<?php
/**
 * GEONEX SCADA - Endpoint REST de Gestão de Usuários (CRUD Completo + Bcrypt + Primeiro Acesso)
 * Estilo: PHP Puro Procedural com mysqli + Prepared Statements
 *
 * Métodos suportados:
 *  - GET    /usuarios.php -> Lista todos os usuários ativos do banco MySQL
 *  - POST   /usuarios.php -> Cria novo usuário (senha criptografada com Bcrypt e primeiro_acesso = 1)
 *  - PUT    /usuarios.php -> Atualiza dados cadastrais, cargo (role) e/ou senha (Bcrypt) de qualquer usuário
 *  - DELETE /usuarios.php -> Remove (ou desativa caso possua O.S. vinculadas) um usuário
 */

require_once __DIR__ . '/conexao.php';

function cargo_por_role(string $role): string {
    switch (strtoupper(trim($role))) {
        case 'ADMIN':    return 'Administrador Geral';
        case 'TECNICO':  return 'Técnico de Manutenção';
        case 'OPERADOR': return 'Operador de Mina';
        default:         return 'Operador de Mina';
    }
}

function formatar_usuario_db(array $row): array {
    $role = strtoupper(trim((string)($row['role'] ?? 'OPERADOR')));
    $username = trim((string)($row['username'] ?? ''));
    if ($username === '') {
        $username = strtolower(trim((string)($row['matricula'] ?? 'user' . $row['id'])));
    }
    $primeiro_acesso = (int)($row['primeiro_acesso'] ?? 0) === 1;

    return [
        'id'              => (int)$row['id'],
        'username'        => strtolower($username),
        'nome'            => (string)$row['nome'],
        'email'           => (string)($row['email'] ?? ''),
        'matricula'       => (string)($row['matricula'] ?? ''),
        'role'            => $role,
        'cargo'           => cargo_por_role($role),
        'primeiro_acesso' => $primeiro_acesso,
        'primeiroAcesso'  => $primeiro_acesso,
        'ativo'           => (int)($row['ativo'] ?? 1) === 1,
        'criado_em'       => $row['criado_em'] ?? null,
    ];
}

function garantir_usuarios_iniciais($conn): void {
    $res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM usuarios");
    $total = 0;
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $total = (int)($row['total'] ?? 0);
        mysqli_free_result($res);
    }

    if ($total === 0) {
        $hash_padrao = password_hash('geonex123', PASSWORD_BCRYPT);
        $seed = [
            [1, 'adm',          'Eng. Carlos Mendes', 'carlos.admin@geonex.com.br', 'ADM-001', $hash_padrao, 'ADMIN',    0],
            [2, 'operador',     'Marcos Souza (CCO)', 'marcos.cco@geonex.com.br',   'OPR-104', $hash_padrao, 'OPERADOR', 0],
            [3, 'tecnico',      'Roberto Lima',       'roberto.tec@geonex.com.br',  'TEC-208', $hash_padrao, 'TECNICO',  0],
            [4, 'fernanda.tec', 'Fernanda Alves',     'fernanda.tec@geonex.com.br', 'TEC-215', $hash_padrao, 'TECNICO',  0],
        ];
        $stmt = mysqli_prepare($conn, "
            INSERT INTO usuarios (id, username, nome, email, matricula, senha_hash, role, primeiro_acesso, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        if ($stmt) {
            foreach ($seed as $s) {
                mysqli_stmt_bind_param($stmt, 'issssssi', $s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7]);
                @mysqli_stmt_execute($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

garantir_usuarios_iniciais($conn);

$metodo = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// 1. LISTAR USUÁRIOS (GET)
// ============================================================================
if ($metodo === 'GET') {
    $sql = "
        SELECT id, username, nome, email, matricula, role, primeiro_acesso, ativo, criado_em
          FROM usuarios
         WHERE ativo = 1
         ORDER BY id ASC
    ";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        responder_json(500, [
            'sucesso' => false,
            'erro'    => 'Erro ao listar usuários: ' . mysqli_error($conn)
        ], $conn);
    }

    $lista = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $lista[] = formatar_usuario_db($row);
    }
    mysqli_free_result($res);

    responder_json(200, [
        'sucesso' => true,
        'total'   => count($lista),
        'dados'   => $lista
    ], $conn);
}

// ============================================================================
// 2. CRIAR NOVO USUÁRIO (POST)
// ============================================================================
if ($metodo === 'POST') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'Payload JSON inválido.'], $conn);
    }

    $nome       = trim((string)($corpo['nome'] ?? ''));
    $username   = strtolower(trim((string)($corpo['username'] ?? $corpo['login'] ?? '')));
    $role       = strtoupper(trim((string)($corpo['role'] ?? $corpo['cargo'] ?? 'OPERADOR')));
    $senha_raw  = trim((string)($corpo['password'] ?? $corpo['senha'] ?? 'geonex123'));
    $email      = strtolower(trim((string)($corpo['email'] ?? '')));
    $matricula  = strtoupper(trim((string)($corpo['matricula'] ?? '')));

    if (!in_array($role, ['ADMIN', 'OPERADOR', 'TECNICO'], true)) {
        $role = 'OPERADOR';
    }

    if ($nome === '' || $username === '') {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe o nome completo e o login (username) do usuário.'
        ], $conn);
    }

    if ($senha_raw === '') {
        $senha_raw = 'geonex123';
    }

    // Gera email e matrícula únicos caso não tenham sido enviados pelo formulário
    if ($email === '') {
        $email = $username . '@geonex.com.br';
    }
    if ($matricula === '') {
        $prefixo = $role === 'ADMIN' ? 'ADM' : ($role === 'TECNICO' ? 'TEC' : 'OPR');
        $matricula = $prefixo . '-' . strtoupper(substr(md5($username . uniqid('', true)), 0, 5));
    }

    // Verifica duplicidade de login, matrícula ou e-mail (incluindo usuários inativos)
    $stmt_dup = mysqli_prepare($conn, "
        SELECT id, ativo FROM usuarios
         WHERE LOWER(username) = ? OR LOWER(matricula) = ? OR LOWER(email) = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_dup, 'sss', $username, $username, $email);
    mysqli_stmt_execute($stmt_dup);
    $res_dup = mysqli_stmt_get_result($stmt_dup);
    $existente = mysqli_fetch_assoc($res_dup);
    mysqli_stmt_close($stmt_dup);

    $senha_hash      = password_hash($senha_raw, PASSWORD_BCRYPT);
    $primeiro_acesso = isset($corpo['primeiro_acesso']) ? ($corpo['primeiro_acesso'] ? 1 : 0) : 1;

    if ($existente) {
        if ((int)$existente['ativo'] === 1) {
            responder_json(409, [
                'sucesso' => false,
                'erro'    => "O login '{$username}' ou e-mail já está em uso por outro usuário ativo."
            ], $conn);
        }
        // Reativa usuário previamente desativado atualizando seus dados e marcando primeiro_acesso = 1
        $id_reativado = (int)$existente['id'];
        $stmt_reat = mysqli_prepare($conn, "
            UPDATE usuarios
               SET username = ?, nome = ?, email = ?, matricula = ?, senha_hash = ?,
                   role = ?, primeiro_acesso = ?, ativo = 1
             WHERE id = ?
        ");
        mysqli_stmt_bind_param(
            $stmt_reat,
            'ssssssii',
            $username,
            $nome,
            $email,
            $matricula,
            $senha_hash,
            $role,
            $primeiro_acesso,
            $id_reativado
        );
        if (!mysqli_stmt_execute($stmt_reat)) {
            $err = mysqli_stmt_error($stmt_reat);
            mysqli_stmt_close($stmt_reat);
            responder_json(500, ['sucesso' => false, 'erro' => 'Erro ao reativar usuário: ' . $err], $conn);
        }
        mysqli_stmt_close($stmt_reat);
        $novo_id = $id_reativado;
    } else {
        $stmt_ins = mysqli_prepare($conn, "
            INSERT INTO usuarios (username, nome, email, matricula, senha_hash, role, primeiro_acesso, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        if (!$stmt_ins) {
            responder_json(500, ['sucesso' => false, 'erro' => 'Erro no prepare INSERT: ' . mysqli_error($conn)], $conn);
        }

        mysqli_stmt_bind_param(
            $stmt_ins,
            'ssssssi',
            $username,
            $nome,
            $email,
            $matricula,
            $senha_hash,
            $role,
            $primeiro_acesso
        );

        if (!mysqli_stmt_execute($stmt_ins)) {
            $err = mysqli_stmt_error($stmt_ins);
            mysqli_stmt_close($stmt_ins);
            responder_json(500, ['sucesso' => false, 'erro' => 'Erro ao salvar novo usuário no banco: ' . $err], $conn);
        }

        $novo_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt_ins);
    }

    $stmt_sel = mysqli_prepare($conn, "
        SELECT id, username, nome, email, matricula, role, primeiro_acesso, ativo, criado_em
          FROM usuarios
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_sel, 'i', $novo_id);
    mysqli_stmt_execute($stmt_sel);
    $res_sel = mysqli_stmt_get_result($stmt_sel);
    $novo_usr = mysqli_fetch_assoc($res_sel);
    mysqli_stmt_close($stmt_sel);

    responder_json(201, [
        'sucesso'  => true,
        'mensagem' => "Usuário '{$nome}' criado com sucesso (troca de senha obrigatória no 1º acesso).",
        'dados'    => formatar_usuario_db($novo_usr),
    ], $conn);
}

// ============================================================================
// 3. ATUALIZAR USUÁRIO EXISTENTE E/OU ALTERAR SENHA COM BCRYPT (PUT)
// ============================================================================
if ($metodo === 'PUT') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) {
        responder_json(400, ['sucesso' => false, 'erro' => 'Payload JSON inválido.'], $conn);
    }

    $id = isset($corpo['id']) ? (int)$corpo['id'] : 0;
    if ($id <= 0) {
        responder_json(422, ['sucesso' => false, 'erro' => 'ID do usuário é obrigatório para atualização.'], $conn);
    }

    $stmt_atual = mysqli_prepare($conn, "
        SELECT id, username, nome, email, matricula, role, primeiro_acesso, ativo
          FROM usuarios
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_atual, 'i', $id);
    mysqli_stmt_execute($stmt_atual);
    $res_atual = mysqli_stmt_get_result($stmt_atual);
    $atual = mysqli_fetch_assoc($res_atual);
    mysqli_stmt_close($stmt_atual);

    if (!$atual) {
        responder_json(404, ['sucesso' => false, 'erro' => "Usuário #{$id} não encontrado."], $conn);
    }

    $novo_nome     = isset($corpo['nome']) && trim((string)$corpo['nome']) !== ''
        ? trim((string)$corpo['nome'])
        : (string)$atual['nome'];
    $novo_username = isset($corpo['username']) && trim((string)$corpo['username']) !== ''
        ? strtolower(trim((string)$corpo['username']))
        : strtolower(trim((string)($atual['username'] ?: $atual['matricula'])));
    $novo_email    = isset($corpo['email']) && trim((string)$corpo['email']) !== ''
        ? strtolower(trim((string)$corpo['email']))
        : (string)$atual['email'];
    $novo_role     = isset($corpo['role']) && trim((string)$corpo['role']) !== ''
        ? strtoupper(trim((string)$corpo['role']))
        : (string)$atual['role'];

    if (!in_array($novo_role, ['ADMIN', 'OPERADOR', 'TECNICO'], true)) {
        $novo_role = (string)$atual['role'];
    }

    // Proteção: impede remover o último ADMIN do sistema
    if ($atual['role'] === 'ADMIN' && $novo_role !== 'ADMIN') {
        $res_adm = mysqli_query($conn, "SELECT COUNT(*) AS total FROM usuarios WHERE role = 'ADMIN' AND ativo = 1");
        $row_adm = $res_adm ? mysqli_fetch_assoc($res_adm) : ['total' => 1];
        if ($res_adm) mysqli_free_result($res_adm);
        if ((int)($row_adm['total'] ?? 1) <= 1) {
            responder_json(422, [
                'sucesso' => false,
                'erro'    => 'Operação bloqueada: é obrigatório manter ao menos um Administrador ativo.'
            ], $conn);
        }
    }

    // Verifica se o novo username já pertence a outro usuário diferente
    $stmt_chk = mysqli_prepare($conn, "
        SELECT id FROM usuarios
         WHERE LOWER(username) = ? AND id <> ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_chk, 'si', $novo_username, $id);
    mysqli_stmt_execute($stmt_chk);
    $res_chk = mysqli_stmt_get_result($stmt_chk);
    if (mysqli_fetch_assoc($res_chk)) {
        mysqli_stmt_close($stmt_chk);
        responder_json(409, [
            'sucesso' => false,
            'erro'    => "O login '{$novo_username}' já está sendo utilizado por outro usuário."
        ], $conn);
    }
    mysqli_stmt_close($stmt_chk);

    // Verifica se foi enviada uma nova senha para criptografar com Bcrypt
    $nova_senha = trim((string)($corpo['password'] ?? $corpo['nova_senha'] ?? $corpo['senha'] ?? ''));
    $primeiro_acesso = isset($corpo['primeiro_acesso'])
        ? ($corpo['primeiro_acesso'] ? 1 : 0)
        : (int)$atual['primeiro_acesso'];

    if ($nova_senha !== '') {
        if (strlen($nova_senha) < 4) {
            responder_json(422, [
                'sucesso' => false,
                'erro'    => 'A senha deve possuir pelo menos 4 caracteres.'
            ], $conn);
        }
        $novo_hash = password_hash($nova_senha, PASSWORD_BCRYPT);
        $stmt_up = mysqli_prepare($conn, "
            UPDATE usuarios
               SET username = ?,
                   nome = ?,
                   email = ?,
                   role = ?,
                   senha_hash = ?,
                   primeiro_acesso = ?,
                   reset_token = NULL,
                   reset_expira_em = NULL
             WHERE id = ?
        ");
        mysqli_stmt_bind_param(
            $stmt_up,
            'sssssii',
            $novo_username,
            $novo_nome,
            $novo_email,
            $novo_role,
            $novo_hash,
            $primeiro_acesso,
            $id
        );
    } else {
        $stmt_up = mysqli_prepare($conn, "
            UPDATE usuarios
               SET username = ?,
                   nome = ?,
                   email = ?,
                   role = ?,
                   primeiro_acesso = ?
             WHERE id = ?
        ");
        mysqli_stmt_bind_param(
            $stmt_up,
            'ssssii',
            $novo_username,
            $novo_nome,
            $novo_email,
            $novo_role,
            $primeiro_acesso,
            $id
        );
    }

    if (!mysqli_stmt_execute($stmt_up)) {
        $err = mysqli_stmt_error($stmt_up);
        mysqli_stmt_close($stmt_up);
        responder_json(500, ['sucesso' => false, 'erro' => 'Erro ao atualizar usuário no banco: ' . $err], $conn);
    }
    mysqli_stmt_close($stmt_up);

    $stmt_sel = mysqli_prepare($conn, "
        SELECT id, username, nome, email, matricula, role, primeiro_acesso, ativo, criado_em
          FROM usuarios
         WHERE id = ?
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt_sel, 'i', $id);
    mysqli_stmt_execute($stmt_sel);
    $res_sel = mysqli_stmt_get_result($stmt_sel);
    $usr_atualizado = mysqli_fetch_assoc($res_sel);
    mysqli_stmt_close($stmt_sel);

    responder_json(200, [
        'sucesso'       => true,
        'senha_alterada'=> $nova_senha !== '',
        'mensagem'      => $nova_senha !== ''
            ? "Usuário '{$novo_nome}' e nova senha (Bcrypt) salvos com sucesso."
            : "Dados do usuário '{$novo_nome}' atualizados com sucesso.",
        'dados'         => formatar_usuario_db($usr_atualizado),
    ], $conn);
}

// ============================================================================
// 4. REMOVER USUÁRIO (DELETE)
// ============================================================================
if ($metodo === 'DELETE') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : (is_array($corpo) && isset($corpo['id']) ? (int)$corpo['id'] : 0);

    if ($id <= 0) {
        responder_json(422, ['sucesso' => false, 'erro' => 'Informe o ID do usuário a ser removido.'], $conn);
    }

    $stmt_sel = mysqli_prepare($conn, "SELECT id, nome, role FROM usuarios WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt_sel, 'i', $id);
    mysqli_stmt_execute($stmt_sel);
    $res_sel = mysqli_stmt_get_result($stmt_sel);
    $alvo = mysqli_fetch_assoc($res_sel);
    mysqli_stmt_close($stmt_sel);

    if (!$alvo) {
        responder_json(404, ['sucesso' => false, 'erro' => "Usuário #{$id} não encontrado."], $conn);
    }

    if ($alvo['role'] === 'ADMIN') {
        $res_adm = mysqli_query($conn, "SELECT COUNT(*) AS total FROM usuarios WHERE role = 'ADMIN' AND ativo = 1");
        $row_adm = $res_adm ? mysqli_fetch_assoc($res_adm) : ['total' => 1];
        if ($res_adm) mysqli_free_result($res_adm);
        if ((int)($row_adm['total'] ?? 1) <= 1) {
            responder_json(422, [
                'sucesso' => false,
                'erro'    => 'Não é permitido remover o único Administrador ativo.'
            ], $conn);
        }
    }

    // Tenta excluir fisicamente; se houver vínculo histórico em ordens_servico, desativa (ativo = 0)
    $stmt_del = mysqli_prepare($conn, "DELETE FROM usuarios WHERE id = ?");
    mysqli_stmt_bind_param($stmt_del, 'i', $id);
    $deletou = @mysqli_stmt_execute($stmt_del);
    mysqli_stmt_close($stmt_del);

    if (!$deletou) {
        $stmt_soft = mysqli_prepare($conn, "UPDATE usuarios SET ativo = 0 WHERE id = ?");
        mysqli_stmt_bind_param($stmt_soft, 'i', $id);
        mysqli_stmt_execute($stmt_soft);
        mysqli_stmt_close($stmt_soft);
    }

    responder_json(200, [
        'sucesso'  => true,
        'id'       => $id,
        'mensagem' => "Usuário '{$alvo['nome']}' removido com sucesso."
    ], $conn);
}

responder_json(405, ['sucesso' => false, 'erro' => 'Método não suportado.'], $conn);
