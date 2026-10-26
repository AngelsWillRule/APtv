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

$output = [];
$return = 1;
if (is_executable('/usr/local/sbin/openap-detect')) {
    exec('/usr/local/sbin/openap-detect --json 2>/dev/null', $output, $return);
}
$detected = $return === 0 ? json_decode(implode("\n", $output), true) : null;
if (!is_array($detected)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Wireless inventory is temporarily unavailable.']);
    exit;
}

$radios = openapNormalizeWifiInventory($detected);
$requested = [];
foreach (openapWifiRoleSlots() as $slot) {
    $requested[$slot] = (string) ($_POST[$slot] ?? '');
}
$validation = openapValidateWifiRoles($requested, $radios, true);
$hotspotRequested = array_key_exists('hotspot_ssid', $_POST);
$hotspotValidation = $hotspotRequested ? openapValidateHotspotDraft([
    'ssid' => $_POST['hotspot_ssid'] ?? '',
    'ap_24ghz_channel' => $_POST['ap_24ghz_channel'] ?? '',
    'ap_24ghz_width' => $_POST['ap_24ghz_width'] ?? '',
    'ap_24ghz_txpower' => $_POST['ap_24ghz_txpower'] ?? '',
    'ap_5ghz_channel' => $_POST['ap_5ghz_channel'] ?? '',
    'ap_5ghz_width' => $_POST['ap_5ghz_width'] ?? '',
    'ap_5ghz_txpower' => $_POST['ap_5ghz_txpower'] ?? '',
    'security' => $_POST['security'] ?? '',
    'wpa_passphrase' => $_POST['wpa_passphrase'] ?? '',
    'ap_isolate' => $_POST['ap_isolate'] ?? '',
    'ignore_broadcast_ssid' => $_POST['ignore_broadcast_ssid'] ?? '',
]) : null;
if (!$validation['valid'] || ($hotspotValidation !== null && !$hotspotValidation['valid'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'The Wi-Fi role draft is not valid.',
        'validation' => $validation,
        'hotspot_validation' => $hotspotValidation,
    ]);
    exit;
}

$profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
$stored = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW);
$model = openapBuildWifiRoleModel(
    is_array($profile) ? $profile : [],
    is_array($stored) ? $stored : [],
    $radios
);
$hotspotDraft = $hotspotValidation !== null
    ? $hotspotValidation['hotspot_draft']
    : $model['hotspot_draft'];
if ($hotspotValidation !== null) {
    foreach (['ap_24ghz', 'ap_5ghz'] as $slot) {
        $identity = $validation['roles'][$slot] ?? null;
        if ($identity === null) {
            continue;
        }
        $radio = openapFindWifiByIdentity($radios, $identity);
        $channel = (int) ($hotspotDraft[$slot . '_channel'] ?? 0);
        foreach (($radio['channels'] ?? []) as $availableChannel) {
            if ((int) ($availableChannel['channel'] ?? 0) === $channel
                && ($availableChannel['selectable'] ?? !($availableChannel['no_ir'] ?? false))) {
                $hotspotDraft[$slot . '_txpower'] = (int) ($availableChannel['max_dbm'] ?? 0);
                break;
            }
        }
    }
}

try {
    openapWriteWifiRoleModel(
        '/etc/openap/wifi-roles.ini',
        $model['active'],
        $validation['roles'],
        $model['legacy_ap'],
        $hotspotDraft
    );
} catch (Throwable $error) {
    error_log('Unable to save OpenAP Wi-Fi role draft: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the Wi-Fi role draft.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Wi-Fi role draft saved. No network changes were applied.',
    'draft_roles' => $validation['roles'],
    'validation' => $validation,
    'hotspot_draft' => $hotspotDraft,
    'hotspot_validation' => $hotspotValidation,
]);
