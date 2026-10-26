<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

$statusPath = '/run/openap/interface-role-apply-status';
$resultPath = '/run/openap/interface-role-apply-result';
$status = is_readable($statusPath) ? trim((string) file_get_contents($statusPath)) : 'unknown';
$result = is_readable($resultPath) ? parse_ini_file($resultPath, false, INI_SCANNER_RAW) : [];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => $status,
    'ap' => (string) ($result['ap'] ?? ''),
    'uplink' => (string) ($result['uplink'] ?? ''),
    'mode' => (string) ($result['mode'] ?? ''),
]);
