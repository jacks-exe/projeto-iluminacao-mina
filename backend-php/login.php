<?php
/**
 * GEONEX SCADA - Endpoint REST de Autenticação, Primeiro Acesso e Recuperação de Senha (RBAC)
 *
 * Funcionalidades:
 *  1. Login com validação prioritária no MySQL (`usuarios`) via Bcrypt (`password_verify`).
 *  2. Retorno da flag `primeiro_acesso` para forçar redefinição de senha no 1º login.
 *  3. Ação `DEFINIR_NOVA_SENHA`: atualiza a senha no primeiro acesso e limpa `primeiro_acesso = 0`.
 *  4. Ações `SOLICITAR_RESET` e `CONFIRMAR_RESET`: fluxo completo de "Esqueci a Senha" com token temporário.
 */

require_once __DIR__ . '/conexao.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo !== 'POST') {
    responder_json(405, [
        'sucesso' => false,
        'erro'    => 'Use o método POST para autenticar ou recuperar senha.'
    ], $conn);
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    responder_json(400, [
        'sucesso' => false,
        'erro'    => 'Payload JSON inválido.'
    ], $conn);
}

$acao = strtoupper(trim((string)($corpo['acao'] ?? 'LOGIN')));

// Mapa de apelidos para localizar os perfis padrão no banco MySQL
$mapa_aliases = [
    'adm'           => 'adm',
    'admin'         => 'adm',
    'administrador' => 'adm',
    'adm-001'       => 'adm',
    'operador'      => 'operador',
    'opr'           => 'operador',
    'op'            => 'operador',
    'opr-104'       => 'operador',
    'tecnico'       => 'tecnico',
    'técnico'       => 'tecnico',
    'tec'           => 'tecnico',
    'tec-208'       => 'tecnico',
];

$cargo_map = [
    'ADMIN'    => 'Administrador Geral',
    'TECNICO'  => 'Técnico de Manutenção',
    'OPERADOR' => 'Operador de Mina',
];

// Hash placeholder original do seed SQL
$HASH_SEED_ORIGINAL = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

// ============================================================================
// AÇÃO 1: DEFINIR NOVA SENHA OBRIGATÓRIA NO PRIMEIRO ACESSO
// ============================================================================
if ($acao === 'DEFINIR_NOVA_SENHA') {
    $usuario_id = isset($corpo['usuario_id']) ? (int)$corpo['usuario_id'] : (isset($corpo['id']) ? (int)$corpo['id'] : 0);
    $username   = strtolower(trim((string)($corpo['username'] ?? '')));
    $nova_senha = trim((string)($corpo['nova_senha'] ?? $corpo['password'] ?? ''));

    if (($usuario_id <= 0 && $username === '') || strlen($nova_senha) < 4) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe uma nova senha válida (mínimo de 4 caracteres).'
        ], $conn);
    }

    $novo_hash = password_hash($nova_senha, PASSWORD_BCRYPT);

    if ($usuario_id > 0) {
        $stmt = mysqli_prepare($conn, "
            UPDATE usuarios
               SET senha_hash = ?,
                   primeiro_acesso = 0,
                   reset_token = NULL,
                   reset_expira_em = NULL
             WHERE id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'si', $novo_hash, $usuario_id);
    } else {
        $stmt = mysqli_prepare($conn, "
            UPDATE usuarios
               SET senha_hash = ?,
                   primeiro_acesso = 0,
                   reset_token = NULL,
                   reset_expira_em = NULL
             WHERE LOWER(username) = ? OR LOWER(matricula) = ? OR LOWER(email) = ?
        ");
        mysqli_stmt_bind_param($stmt, 'ssss', $novo_hash, $username, $username, $username);
    }

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        if ($stmt) mysqli_stmt_close($stmt);
        responder_json(500, [
            'sucesso' => false,
            'erro'    => 'Falha ao gravar a nova senha no banco de dados.'
        ], $conn);
    }
    mysqli_stmt_close($stmt);

    responder_json(200, [
        'sucesso'         => true,
        'primeiro_acesso' => false,
        'primeiroAcesso'  => false,
        'mensagem'        => 'Nova senha definida com sucesso! Acesso ao sistema liberado.'
    ], $conn);
}

// ============================================================================
// AÇÃO 2: SOLICITAR RECUPERAÇÃO DE SENHA (ESQUECI A SENHA - GERAR TOKEN/LINK)
// ============================================================================
if ($acao === 'SOLICITAR_RESET') {
    $identificador = strtolower(trim((string)($corpo['identificador'] ?? $corpo['username'] ?? $corpo['email'] ?? '')));
    if ($identificador === '') {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe seu login, matrícula ou e-mail cadastrado.'
        ], $conn);
    }

    $busca = $mapa_aliases[$identificador] ?? $identificador;

    $stmt = mysqli_prepare($conn, "
        SELECT id, username, nome, email, matricula
          FROM usuarios
         WHERE ativo = 1
           AND (LOWER(username) = ? OR LOWER(matricula) = ? OR LOWER(email) = ? OR LOWER(nome) = ?)
         LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'ssss', $busca, $identificador, $identificador, $identificador);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $usr = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$usr) {
        responder_json(404, [
            'sucesso' => false,
            'erro'    => 'Nenhum usuário ativo encontrado com o identificador informado.'
        ], $conn);
    }

    $uid = (int)$usr['id'];
    $token_reset = strtoupper(bin2hex(random_bytes(3))); // Token de 6 caracteres hexadecimais

    $stmt_tok = mysqli_prepare($conn, "
        UPDATE usuarios
           SET reset_token = ?,
               reset_expira_em = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
         WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt_tok, 'si', $token_reset, $uid);
    mysqli_stmt_execute($stmt_tok);
    mysqli_stmt_close($stmt_tok);

    $login_ref = strtolower((string)($usr['username'] ?: $usr['matricula']));
    $link_temp = "/login?reset_token={$token_reset}&user=" . urlencode($login_ref);

    responder_json(200, [
        'sucesso'         => true,
        'usuario_id'      => $uid,
        'username'        => $login_ref,
        'nome'            => (string)$usr['nome'],
        'email'           => (string)$usr['email'],
        'token_reset'     => $token_reset,
        'link_temporario' => $link_temp,
        'expira_minutos'  => 30,
        'mensagem'        => "Token de recuperação gerado para {$usr['nome']} (válido por 30 min)."
    ], $conn);
}

// ============================================================================
// AÇÃO 3: CONFIRMAR REDEFINIÇÃO DE SENHA COM TOKEN DE RESET
// ============================================================================
if ($acao === 'CONFIRMAR_RESET') {
    $identificador = strtolower(trim((string)($corpo['identificador'] ?? $corpo['username'] ?? '')));
    $token         = strtoupper(trim((string)($corpo['token'] ?? $corpo['reset_token'] ?? '')));
    $nova_senha    = trim((string)($corpo['nova_senha'] ?? $corpo['password'] ?? ''));

    if ($token === '' || strlen($nova_senha) < 4) {
        responder_json(422, [
            'sucesso' => false,
            'erro'    => 'Informe o token de recuperação e uma nova senha (mínimo de 4 caracteres).'
        ], $conn);
    }

    $busca = $mapa_aliases[$identificador] ?? $identificador;

    if ($identificador !== '') {
        $stmt = mysqli_prepare($conn, "
            SELECT id, username, nome
              FROM usuarios
             WHERE ativo = 1
               AND reset_token = ?
               AND reset_expira_em >= NOW()
               AND (LOWER(username) = ? OR LOWER(matricula) = ? OR LOWER(email) = ?)
             LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 'ssss', $token, $busca, $identificador, $identificador);
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT id, username, nome
              FROM usuarios
             WHERE ativo = 1
               AND reset_token = ?
               AND reset_expira_em >= NOW()
             LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, 's', $token);
    }

    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $usr = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$usr) {
        responder_json(401, [
            'sucesso' => false,
            'erro'    => 'Token de recuperação inválido ou expirado.'
        ], $conn);
    }

    $uid = (int)$usr['id'];
    $novo_hash = password_hash($nova_senha, PASSWORD_BCRYPT);

    $stmt_up = mysqli_prepare($conn, "
        UPDATE usuarios
           SET senha_hash = ?,
               primeiro_acesso = 0,
               reset_token = NULL,
               reset_expira_em = NULL
         WHERE id = ?
    ");
    mysqli_stmt_bind_param($stmt_up, 'si', $novo_hash, $uid);
    mysqli_stmt_execute($stmt_up);
    mysqli_stmt_close($stmt_up);

    responder_json(200, [
        'sucesso'  => true,
        'username' => strtolower((string)$usr['username']),
        'mensagem' => "Senha de {$usr['nome']} atualizada com sucesso! Faça login com sua nova senha."
    ], $conn);
}

// ============================================================================
// AÇÃO 4: AUTENTICAÇÃO PADRÃO (LOGIN)
// ============================================================================
$username = strtolower(trim((string)($corpo['username'] ?? '')));
$password = trim((string)($corpo['password'] ?? ''));

if ($username === '' || $password === '') {
    responder_json(422, [
        'sucesso' => false,
        'erro'    => 'Informe usuário e senha.'
    ], $conn);
}

$busca_login = $mapa_aliases[$username] ?? $username;

// 1. Consulta prioritariamente a tabela `usuarios` do MySQL
$stmt = mysqli_prepare($conn, "
    SELECT id, username, nome, email, matricula, senha_hash, role, primeiro_acesso
      FROM usuarios
     WHERE ativo = 1
       AND (LOWER(username) = ? OR LOWER(username) = ? OR LOWER(matricula) = ? OR LOWER(email) = ? OR LOWER(nome) = ?)
     LIMIT 1
");

if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sssss', $busca_login, $username, $username, $username, $username);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $usr = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($usr) {
        $senha_hash_db = (string)$usr['senha_hash'];
        $hash_valido   = password_verify($password, $senha_hash_db);

        // Compatibilidade inicial apenas se o hash ainda for o seed original não alterado
        if (!$hash_valido && $senha_hash_db === $HASH_SEED_ORIGINAL) {
            $senhas_legadas = ['123456', 'geonex123', 'adm', 'admin', 'adm123', 'tecnico', 'tec', 'tec123', 'operador', 'opr', 'op123'];
            if (in_array($password, $senhas_legadas, true)) {
                $hash_valido = true;
            }
        }

        if ($hash_valido) {
            $role_db         = strtoupper((string)$usr['role']);
            $primeiro_acesso = (int)($usr['primeiro_acesso'] ?? 0) === 1;
            $login_norm      = strtolower((string)($usr['username'] ?: $usr['matricula']));

            responder_json(200, [
                'sucesso'         => true,
                'status'          => 'success',
                'message'         => 'Autenticação realizada com sucesso.',
                'id'              => (int)$usr['id'],
                'username'        => $login_norm,
                'nome'            => (string)$usr['nome'],
                'email'           => (string)$usr['email'],
                'matricula'       => (string)$usr['matricula'],
                'cargo'           => $cargo_map[$role_db] ?? 'Operador de Mina',
                'role'            => $role_db,
                'primeiro_acesso' => $primeiro_acesso,
                'primeiroAcesso'  => $primeiro_acesso,
            ], $conn);
        } else {
            responder_json(401, [
                'sucesso' => false,
                'status'  => 'error',
                'message' => 'Usuário ou senha incorretos.'
            ], $conn);
        }
    }
}

responder_json(401, [
    'sucesso' => false,
    'status'  => 'error',
    'message' => 'Usuário ou senha incorretos.'
], $conn);
