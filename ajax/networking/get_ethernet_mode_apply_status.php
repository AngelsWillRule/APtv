<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

$status = is_readable('/run/openap/ethernet-mode-apply-status')
    ? parse_ini_file('/run/openap/ethernet-mode-apply-status', false, INI_SCANNER_RAW)
    : [];
$state = (string) ($status['state'] ?? 'unknown');
if (!in_array($state, ['scheduled', 'applying', 'success', 'failed'], true)) {
    $state = 'unknown';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'state' => $state,
    'target' => (string) ($status['target'] ?? ''),
    'updated' => (int) ($status['updated'] ?? 0),
    'message' => (string) ($status['message'] ?? ''),
]);
