<?php
/**
 * GEONEX SCADA - Conexão MySQL Procedural (mysqli) e Helpers HTTP/RBAC
 */

// Configurações de CORS para comunicação com o Frontend Vue.js 3 (Vite)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Role');
header('Content-Type: application/json; charset=utf-8');

// Responde imediatamente ao preflight request do navegador
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Credenciais do Banco (compatível com o docker-compose.yml do projeto)
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = (int)(getenv('DB_PORT') ?: 3306);
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: 'root';
$db_name = getenv('DB_NAME') ?: 'mina_iot_db';

// Conexão estritamente procedural com mysqli
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);

if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro'    => 'Falha crítica de conexão com o banco SCADA: ' . mysqli_connect_error()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

/**
 * Garante migração automática das colunas de manutenção e autenticação no MySQL ativo
 */
function garantir_colunas_base_geonex($conn): void {
    $alteracoes = [
        'usuarios' => [
            'username'        => "ALTER TABLE usuarios ADD COLUMN username VARCHAR(60) NULL UNIQUE AFTER id",
            'primeiro_acesso' => "ALTER TABLE usuarios ADD COLUMN primeiro_acesso TINYINT(1) NOT NULL DEFAULT 0 AFTER role",
            'reset_token'     => "ALTER TABLE usuarios ADD COLUMN reset_token VARCHAR(128) NULL DEFAULT NULL AFTER primeiro_acesso",
            'reset_expira_em' => "ALTER TABLE usuarios ADD COLUMN reset_expira_em DATETIME NULL DEFAULT NULL AFTER reset_token",
        ],
        'torres' => [
            'pwm_antes_manutencao' => "ALTER TABLE torres ADD COLUMN pwm_antes_manutencao TINYINT UNSIGNED NOT NULL DEFAULT 170 AFTER nivel_brilho_atual",
            'em_manutencao'        => "ALTER TABLE torres ADD COLUMN em_manutencao TINYINT(1) NOT NULL DEFAULT 0 AFTER status_operacional",
        ],
    ];

    foreach ($alteracoes as $tabela => $colunas) {
        $res_tab = mysqli_query($conn, "SHOW TABLES LIKE '{$tabela}'");
        $existe_tab = $res_tab && mysqli_num_rows($res_tab) > 0;
        if ($res_tab) mysqli_free_result($res_tab);
        if (!$existe_tab) continue;

        foreach ($colunas as $col => $sql_alter) {
            $check = mysqli_query($conn, "SHOW COLUMNS FROM {$tabela} LIKE '{$col}'");
            if ($check && mysqli_num_rows($check) === 0) {
                @mysqli_query($conn, $sql_alter);
            }
            if ($check) mysqli_free_result($check);
        }
    }

    // Sincroniza flag booleana em_manutencao com status_operacional preexistente
    @mysqli_query($conn, "UPDATE torres SET em_manutencao = IF(status_operacional = 'EM_MANUTENCAO', 1, 0)");

    // Preenche username padrão dos usuários seed caso esteja nulo
    @mysqli_query($conn, "UPDATE usuarios SET username = 'adm' WHERE id = 1 AND (username IS NULL OR username = '')");
    @mysqli_query($conn, "UPDATE usuarios SET username = 'operador' WHERE id = 2 AND (username IS NULL OR username = '')");
    @mysqli_query($conn, "UPDATE usuarios SET username = 'tecnico' WHERE id = 3 AND (username IS NULL OR username = '')");
    @mysqli_query($conn, "UPDATE usuarios SET username = 'fernanda.tec' WHERE id = 4 AND (username IS NULL OR username = '')");
}

garantir_colunas_base_geonex($conn);

/**
 * Envia resposta JSON padronizada e encerra o script
 */
function responder_json(int $status_code, array $payload, $conn = null): void {
    if ($conn) {
        mysqli_close($conn);
    }
    http_response_code($status_code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Valida o perfil de acesso (RBAC) enviado via Header ou Payload
 */
function validar_rbac(string $role_atual, array $roles_permitidas, $conn): void {
    $role_formatada = strtoupper(trim($role_atual));
    if (!in_array($role_formatada, $roles_permitidas, true)) {
        responder_json(403, [
            'sucesso' => false,
            'erro'    => "Acesso negado (RBAC). Perfil '{$role_formatada}' não possui permissão para esta operação."
        ], $conn);
    }
}
