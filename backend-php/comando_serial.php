<?php
/**
 * GEONEX SCADA - Endpoint Dedicado de Envio de Comandos Seriais por Evento (POST)
 *
 * Responsabilidade:
 *   - Validar no banco se a torre alvo está em manutenção (em_manutencao = 1) e bloquear acionamentos.
 *   - Formatar o pacote isolado (ex: "POSTE:2,0\n") com quebra de linha (\n) obrigatória.
 *   - Transmitir em 115200 bps para a Ponte Serial / Arduino.
 */

require_once __DIR__ . '/conexao.php';

define('SERIAL_PORTA_PADRAO', 'COM17');
define('SERIAL_BAUD_RATE', 115200);
define('PONTE_IOT_EVENTO_URL', 'http://127.0.0.1:5005/evento');

function responder_serial_json(int $status, array $dados, $conn = null): void {
    if ($conn) {
        mysqli_close($conn);
    }
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo !== 'POST') {
    responder_serial_json(405, [
        'sucesso' => false,
        'erro'    => 'Este endpoint aceita apenas requisições POST disparadas por evento de clique.'
    ], $conn);
}

$corpo = json_decode(file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    responder_serial_json(400, [
        'sucesso' => false,
        'erro'    => 'Payload JSON inválido.'
    ], $conn);
}

$acao           = strtoupper(trim((string)($corpo['acao'] ?? 'POSTE')));
$comando_direto = trim((string)($corpo['comando'] ?? ''));
$torre_id       = isset($corpo['id']) ? (int)$corpo['id'] : (isset($corpo['torre_id']) ? (int)$corpo['torre_id'] : 0);
$pwm            = isset($corpo['pwm']) ? (int)$corpo['pwm'] : (isset($corpo['nivelBrilhoAtual']) ? (int)$corpo['nivelBrilhoAtual'] : -1);

// Se o comando direto for POSTE:id,pwm, extrai o torre_id para validar bloqueio de manutenção
if ($torre_id <= 0 && preg_match('/^POSTE:(\d+),/i', $comando_direto, $matches)) {
    $torre_id = (int)$matches[1];
}

// Bloqueia qualquer tentativa de acionamento de PWM ou relé para torre que esteja em manutenção no banco
if ($torre_id >= 1 && $torre_id <= 5 && $acao !== 'MANUTENCAO') {
    $stmt_chk = mysqli_prepare($conn, "SELECT codigo, status_operacional, em_manutencao FROM torres WHERE id = ? LIMIT 1");
    if ($stmt_chk) {
        mysqli_stmt_bind_param($stmt_chk, 'i', $torre_id);
        mysqli_stmt_execute($stmt_chk);
        $res_chk = mysqli_stmt_get_result($stmt_chk);
        $row_chk = mysqli_fetch_assoc($res_chk);
        mysqli_stmt_close($stmt_chk);

        if ($row_chk && ((int)$row_chk['em_manutencao'] === 1 || $row_chk['status_operacional'] === 'EM_MANUTENCAO')) {
            responder_serial_json(409, [
                'sucesso'        => false,
                'enviado_serial' => false,
                'erro'           => "Torre {$row_chk['codigo']} está EM MANUTENÇÃO — acionamento de PWM/relé bloqueado."
            ], $conn);
        }
    }
}

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
    if ($manut_flag === 0 && $pwm >= 0) {
        $pwm_clamped = max(0, min(255, $pwm));
        $comando_limpo = "MANUT:{$torre_id},0,{$pwm_clamped}";
    } else {
        $comando_limpo = "MANUT:{$torre_id},{$manut_flag}";
    }
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
    ], $conn);
}

// Garante quebra de linha (\n) no final do pacote para o Serial do Arduino
$pacote_serial = $comando_limpo . "\n";

// Envia via HTTP para a Ponte IoT Python
$payload_ponte = json_encode([
    'comando'   => $pacote_serial,
    'baud_rate' => SERIAL_BAUD_RATE,
], JSON_UNESCAPED_UNICODE);

$ctx = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload_ponte) . "\r\n",
        'content'       => $payload_ponte,
        'timeout'       => 0.25,
        'ignore_errors' => true,
    ],
]);

$resposta_ponte = @file_get_contents(PONTE_IOT_EVENTO_URL, false, $ctx);
$enviado_ponte  = false;
$arduino_online = false;

if ($resposta_ponte !== false) {
    $json_ponte     = json_decode($resposta_ponte, true);
    $enviado_ponte  = !empty($json_ponte['sucesso']);
    $arduino_online = !empty($json_ponte['online']);
}

responder_serial_json(200, [
    'sucesso'        => true,
    'enviado_serial' => $enviado_ponte,
    'arduino_online' => $arduino_online,
    'baud_rate'      => SERIAL_BAUD_RATE,
    'pacote'         => $pacote_serial,
    'comando'        => $comando_limpo,
], $conn);
