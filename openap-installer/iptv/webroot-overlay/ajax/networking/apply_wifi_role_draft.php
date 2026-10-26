<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';
require_once '../../includes/wifi_inventory.php';
require_once '../../includes/wifi_roles.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
$stored = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW);
$output = [];
$return = 1;
if (is_executable('/usr/local/sbin/openap-detect')) {
    exec('/usr/local/sbin/openap-detect --json 2>/dev/null', $output, $return);
}
$detected = $return === 0 ? json_decode(implode("\n", $output), true) : null;
if (!is_array($detected) || !is_array($stored)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The saved Wi-Fi draft cannot be validated right now.']);
    exit;
}

$radios = openapNormalizeWifiInventory($detected);
$model = openapBuildWifiRoleModel(is_array($profile) ? $profile : [], $stored, $radios);
$validation = openapValidateWifiRoles($model['draft'], $radios, true);
if (!$validation['valid']
    || (empty($model['draft']['ap_24ghz']) && empty($model['draft']['ap_5ghz']))
    || empty($model['hotspot_draft']['configured'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Save a valid hotspot draft before applying it.',
        'validation' => $validation,
    ]);
    exit;
}

$command = 'sudo /usr/local/sbin/openap-apply-dual-hostapd --apply-delayed';
exec($command . ' 2>&1', $output, $return);
if ($return !== 0) {
    error_log('Unable to schedule OpenAP dual-band apply: ' . implode(' ', $output));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to schedule the dual-band configuration.']);
    exit;
}

echo json_encode(['success' => true, 'status' => 'scheduled']);
