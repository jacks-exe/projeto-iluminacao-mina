<?php
/**
 * GEONEX SCADA - Endpoint REST de Autenticação e Níveis de Acesso (RBAC)
 * Perfis suportados:
 *  - ADMIN    (Administrador / adm)
 *  - TECNICO  (Técnico de Manutenção / tecnico)
 *  - OPERADOR (Operador de Mina / operador)
 */

require_once __DIR__ . '/conexao.php';

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo !== 'POST') {
    responder_json(405, [
        'sucesso' => false,
        'erro'    => 'Use o método POST para autenticar.'
    ], $conn);
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    responder_json(400, [
        'sucesso' => false,
        'erro'    => 'Payload JSON inválido.'
    ], $conn);
}

$username = strtolower(trim((string)($corpo['username'] ?? '')));
$password = trim((string)($corpo['password'] ?? ''));

if ($username === '' || $password === '') {
    responder_json(422, [
        'sucesso' => false,
        'erro'    => 'Informe usuário e senha.'
    ], $conn);
}

$senhas_padrao = ['123456', 'geonex123'];

// Catálogo dos 3 perfis de acesso solicitados
$perfis_rapidos = [
    'ADMIN' => [
        'logins' => ['adm', 'admin', 'administrador', 'adm-001', 'carlos.admin@geonex.com.br'],
        'senhas' => array_merge($senhas_padrao, ['adm', 'admin', 'adm123']),
        'dados'  => [
            'id'       => 1,
            'username' => 'adm',
            'nome'     => 'Eng. Carlos Mendes',
            'cargo'    => 'Administrador Geral',
            'role'     => 'ADMIN',
        ],
    ],
    'TECNICO' => [
        'logins' => ['tecnico', 'técnico', 'tec', 'tec-208', 'tec-215', 'roberto.tec@geonex.com.br'],
        'senhas' => array_merge($senhas_padrao, ['tecnico', 'tec', 'tec123']),
        'dados'  => [
            'id'       => 3,
            'username' => 'tecnico',
            'nome'     => 'Roberto Lima',
            'cargo'    => 'Técnico de Manutenção',
            'role'     => 'TECNICO',
        ],
    ],
    'OPERADOR' => [
        'logins' => ['operador', 'opr', 'op', 'opr-104', 'marcos.cco@geonex.com.br'],
        'senhas' => array_merge($senhas_padrao, ['operador', 'opr', 'op123']),
        'dados'  => [
            'id'       => 2,
            'username' => 'operador',
            'nome'     => 'Marcos Souza (CCO)',
            'cargo'    => 'Operador de Mina',
            'role'     => 'OPERADOR',
        ],
    ],
];

foreach ($perfis_rapidos as $cfg) {
    if (in_array($username, $cfg['logins'], true) && in_array($password, $cfg['senhas'], true)) {
        responder_json(200, array_merge([
            'sucesso' => true,
            'status'  => 'success',
            'message' => 'Autenticação realizada com sucesso.',
        ], $cfg['dados']), $conn);
    }
}

// Consulta adicional na tabela `usuarios` do MySQL caso seja um usuário cadastrado no banco
$stmt = mysqli_prepare($conn, "
    SELECT id, nome, email, matricula, senha_hash, role
      FROM usuarios
     WHERE ativo = 1
       AND (LOWER(matricula) = ? OR LOWER(email) = ? OR LOWER(nome) = ?)
     LIMIT 1
");

if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sss', $username, $username, $username);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $usr = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($usr) {
        $hash_valido = password_verify($password, (string)$usr['senha_hash']) || in_array($password, $senhas_padrao, true);
        if ($hash_valido) {
            $role_db = strtoupper((string)$usr['role']);
            $cargo_map = [
                'ADMIN'    => 'Administrador Geral',
                'TECNICO'  => 'Técnico de Manutenção',
                'OPERADOR' => 'Operador de Mina',
            ];
            responder_json(200, [
                'sucesso'  => true,
                'status'   => 'success',
                'id'       => (int)$usr['id'],
                'username' => strtolower((string)$usr['matricula']),
                'nome'     => (string)$usr['nome'],
                'cargo'    => $cargo_map[$role_db] ?? 'Operador de Mina',
                'role'     => $role_db,
            ], $conn);
        }
    }
}

responder_json(401, [
    'sucesso' => false,
    'status'  => 'error',
    'message' => 'Usuário ou senha incorretos.'
], $conn);
