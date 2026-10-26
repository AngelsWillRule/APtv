<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$interface = isset($_GET['interface']) ? (string) $_GET['interface'] : '';
$interfaces = isset($_GET['interfaces']) ? explode(',', (string) $_GET['interfaces']) : [$interface];
$interfaces = array_values(array_unique(array_filter($interfaces, static fn(string $value): bool => $value !== '')));
if ($interfaces === [] || count($interfaces) > 4 || array_filter($interfaces, static fn(string $value): bool => !preg_match('/^[A-Za-z0-9_.:-]+$/', $value))) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid interface']);
    exit;
}

$rxBytes = 0;
$txBytes = 0;
foreach ($interfaces as $name) {
    $statisticsPath = '/sys/class/net/' . $name . '/statistics';
    $rx = is_readable($statisticsPath . '/rx_bytes') ? trim((string) file_get_contents($statisticsPath . '/rx_bytes')) : '';
    $tx = is_readable($statisticsPath . '/tx_bytes') ? trim((string) file_get_contents($statisticsPath . '/tx_bytes')) : '';
    if (!ctype_digit($rx) || !ctype_digit($tx)) {
        http_response_code(404);
        echo json_encode(['error' => 'Interface not available']);
        exit;
    }
    $rxBytes += (int) $rx;
    $txBytes += (int) $tx;
}

echo json_encode([
    'interface' => implode(',', $interfaces),
    'interfaces' => $interfaces,
    'rx_bytes' => $rxBytes,
    'tx_bytes' => $txBytes,
    'timestamp_ms' => (int) round(microtime(true) * 1000),
]);
