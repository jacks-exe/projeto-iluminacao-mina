<?php
/**
 * GEONEX SCADA - Endpoint Dedicado de Envio de Comandos Seriais por Evento (POST)
 *
 * Responsabilidade única:
 *   - Receber via POST o comando disparado APENAS no momento do clique na interface.
 *   - Formatar o pacote isolado (ex: "POSTE:2,0\n") com quebra de linha (\n) obrigatória.
 *   - Transmitir em 115200 bps para a Ponte Serial / Arduino sem polling e sem bloquear.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Role, X-User-Id');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

define('SERIAL_PORTA_PADRAO', 'COM17');
define('SERIAL_BAUD_RATE', 115200);
define('PONTE_IOT_EVENTO_URL', 'http://127.0.0.1:5005/evento');

function responder_serial_json(int $status, array $dados): void {
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo !== 'POST') {
    responder_serial_json(405, [
        'sucesso' => false,
        'erro'    => 'Este endpoint aceita apenas requisições POST disparadas por evento de clique.'
    ]);
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    responder_serial_json(400, [
        'sucesso' => false,
        'erro'    => 'Payload JSON inválido.'
    ]);
}

$acao            = strtoupper(trim((string)($corpo['acao'] ?? 'POSTE')));
$comando_direto  = trim((string)($corpo['comando'] ?? ''));
$torre_id        = isset($corpo['id']) ? (int)$corpo['id'] : (isset($corpo['torre_id']) ? (int)$corpo['torre_id'] : 0);
$pwm             = isset($corpo['pwm']) ? (int)$corpo['pwm'] : (isset($corpo['nivelBrilhoAtual']) ? (int)$corpo['nivelBrilhoAtual'] : -1);

// Monta o comando exato isolando o poste clicado para não afetar os demais pinos
if ($comando_direto !== '') {
    $comando_limpo = $comando_direto;
} elseif ($acao === 'POSTE' && $torre_id >= 1 && $torre_id <= 5 && $pwm >= 0) {
    $pwm_clamped = max(0, min(255, $pwm));
    $comando_limpo = "POSTE:{$torre_id},{$pwm_clamped}";
} elseif ($acao === 'CONECTIVIDADE' && $torre_id >= 1 && $torre_id <= 5) {
    $conectado_flag = !empty($corpo['conectado']) ? 1 : 0;
    $comando_limpo = "CONN:{$torre_id},{$conectado_flag}";
} elseif ($acao === 'MANUTENCAO' && $torre_id >= 1 && $torre_id <= 5) {
    $manut_flag = !empty($corpo['em_manutencao']) ? 1 : 0;
    $comando_limpo = "MANUT:{$torre_id},{$manut_flag}";
} elseif ($acao === 'CASCATA') {
    $pwm_cascata = max(0, min(255, $pwm >= 0 ? $pwm : 170));
    $comando_limpo = "CASCATA:{$pwm_cascata}";
} elseif ($acao === 'CERRACAO') {
    $comando_limpo = "CERRACAO";
} elseif ($acao === 'SENSORES_VIRTUAIS') {
    $bat = max(0, min(100, (int)($corpo['bateria'] ?? 92)));
    $ldr = max(0, min(1023, (int)($corpo['ldr'] ?? 310)));
    $comando_limpo = "TELEM:{$bat},{$ldr}";
} else {
    responder_serial_json(422, [
        'sucesso' => false,
        'erro'    => 'Parâmetros insuficientes para montar o comando serial.'
    ]);
}

// Garante quebra de linha (\n) no final do pacote para o Serial.readStringUntil('\n') do Arduino
$pacote_serial = $comando_limpo . "\n";

// Envia via HTTP para a Ponte IoT Python (que mantém a porta COM aberta em 115200 bps sem resetar o DTR)
$payload_ponte = json_encode([
    'comando'   => $pacote_serial,
    'baud_rate' => SERIAL_BAUD_RATE,
], JSON_UNESCAPED_UNICODE);

$ctx = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload_ponte) . "\r\n",
        'content'       => $payload_ponte,
        'timeout'       => 0.15,
        'ignore_errors' => true,
    ],
]);

$resposta_ponte = @file_get_contents(PONTE_IOT_EVENTO_URL, false, $ctx);
$enviado_ponte  = false;

if ($resposta_ponte !== false) {
    $json_ponte = json_decode($resposta_ponte, true);
    $enviado_ponte = !empty($json_ponte['sucesso']);
}

responder_serial_json(200, [
    'sucesso'        => true,
    'enviado_serial' => $enviado_ponte,
    'baud_rate'      => SERIAL_BAUD_RATE,
    'pacote'         => $pacote_serial,
    'comando'        => $comando_limpo,
]);
