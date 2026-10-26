<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$apMac = strtolower(trim((string) ($_POST['ap_mac'] ?? '')));
$uplinkMac = strtolower(trim((string) ($_POST['uplink_mac'] ?? '')));
$validMac = static fn(string $value): bool => preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $value) === 1;

if (!$validMac($apMac) || !$validMac($uplinkMac) || hash_equals($apMac, $uplinkMac)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select two different valid Wi-Fi interfaces.']);
    exit;
}

$command = 'sudo /usr/local/sbin/openap-switch-interface-roles --delayed '
    . escapeshellarg($apMac) . ' ' . escapeshellarg($uplinkMac);
exec($command . ' 2>&1', $output, $return);
if ($return !== 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => implode(' ', $output)]);
    exit;
}

echo json_encode(['success' => true, 'status' => 'applying']);
