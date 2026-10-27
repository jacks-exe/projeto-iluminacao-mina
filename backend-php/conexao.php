<?php
/**
 * GEONEX SCADA - Conexão MySQL Procedural (mysqli) e Helpers HTTP/RBAC
 */

// Configurações de CORS para comunicação com o Frontend Vue.js 3 (Vite)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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
