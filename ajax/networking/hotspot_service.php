<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$readState = static function (): string {
    exec('/bin/systemctl is-active hostapd.service 2>/dev/null', $output, $return);
    $state = trim((string) ($output[0] ?? 'unknown'));
    return in_array($state, ['active', 'activating', 'deactivating', 'inactive', 'failed'], true)
        ? $state
        : ($return === 0 ? 'active' : 'unknown');
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'state' => $readState()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
$mode = is_array($profile) ? (string) ($profile['mode']['current'] ?? 'ap_ethernet') : 'ap_ethernet';
$hotspotUnits = $mode === 'ap_ethernet_bridge'
    ? 'hostapd.service'
    : 'hostapd.service dnsmasq.service';
$commands = [
    'start' => 'sudo /bin/systemctl start ' . $hotspotUnits,
    'stop' => 'sudo /bin/systemctl stop hostapd.service',
    'restart' => 'sudo /bin/systemctl restart ' . $hotspotUnits,
];
if (!isset($commands[$action])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unknown hotspot action.']);
    exit;
}

exec($commands[$action] . ' >/dev/null 2>&1 &');
echo json_encode([
    'success' => true,
    'action' => $action,
    'state' => $readState(),
]);
exit;
