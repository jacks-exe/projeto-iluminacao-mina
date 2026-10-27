<?php
/**
 * GEONEX SCADA - Passo 4: Endpoint Unificado de Telemetria, Histórico e Analytics
 * Estilo: PHP Puro Procedural com mysqli (Sem PDO)
 */

if (isset($_GET['analytics']) || isset($_GET['periodo']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/historico_telemetria.php';
} else {
    $_GET['historico'] = $_GET['historico'] ?? '1';
    require_once __DIR__ . '/postes.php';
}
