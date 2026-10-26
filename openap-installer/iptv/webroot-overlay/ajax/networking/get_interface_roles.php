<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';
require_once '../../includes/wifi_inventory.php';
require_once '../../includes/wifi_roles.php';
require_once '../../includes/repeater.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
$configured = is_array($profile) ? ($profile['interfaces'] ?? []) : [];
$apName = (string) ($configured['ap'] ?? '');
$uplinkName = (string) ($configured['uplink'] ?? '');
$apMac = strtolower((string) ($configured['ap_mac'] ?? ''));
$uplinkMac = strtolower((string) ($configured['uplink_mac'] ?? ''));
$radios = [];

if (is_executable('/usr/local/sbin/openap-detect')) {
    $detected = function_exists('openapReadCachedDetectorReport')
        ? openapReadCachedDetectorReport()
        : null;
    $radios = is_array($detected) ? openapNormalizeWifiInventory($detected) : [];
}

$findByMac = static function (array $items, string $mac): ?array {
    return openapFindWifiByIdentity($items, $mac);
};
$configuredAp = $findByMac($radios, $apMac) ?? ($radios[$apName] ?? null);
$configuredUplink = $findByMac($radios, $uplinkMac) ?? ($radios[$uplinkName] ?? null);
$ap = $configuredAp;
$uplink = $configuredUplink;
if ($ap && !$uplink && $ap['supports_managed']) {
    foreach ($radios as $radio) {
        if ($radio['name'] !== $apName && $radio['supports_ap']) {
            $uplink = $radio;
            break;
        }
    }
}
if (!$ap && $uplink && $uplink['supports_ap']) {
    foreach ($radios as $radio) {
        if ($radio['mac'] !== $uplink['mac'] && $radio['supports_managed']) {
            $ap = $radio;
            break;
        }
    }
}
if (!$ap && !$uplink) {
    foreach ($radios as $apCandidate) {
        if (!$apCandidate['supports_managed']) continue;
        foreach ($radios as $uplinkCandidate) {
            if ($uplinkCandidate['mac'] !== $apCandidate['mac'] && $uplinkCandidate['supports_ap']) {
                $ap = $apCandidate;
                $uplink = $uplinkCandidate;
                break 2;
            }
        }
    }
}
$storedRoles = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW);
$roleModel = openapBuildWifiRoleModel(
    is_array($profile) ? $profile : [],
    is_array($storedRoles) ? $storedRoles : [],
    $radios
);
$draftValidation = openapValidateWifiRoles(
    $roleModel['draft'],
    $radios,
    $roleModel['legacy_ap'] === null
);
$repeaterAvailability = openapEvaluateRepeaterAvailability($roleModel['active'], $radios);
$applyState = @file_get_contents('/run/openap/dual-hostapd-apply.state');
$applyState = in_array(trim((string) $applyState), ['scheduled', 'applying', 'success', 'failed', 'rollback_failed'], true)
    ? trim((string) $applyState)
    : 'idle';
$applyError = in_array($applyState, ['failed', 'rollback_failed'], true)
    ? trim((string) @file_get_contents('/run/openap/dual-hostapd-apply.error'))
    : '';
$hostapdOutput = [];
exec('/bin/systemctl is-active hostapd.service 2>/dev/null', $hostapdOutput, $hostapdReturn);
$hostapdActive = $hostapdReturn === 0 && trim((string) ($hostapdOutput[0] ?? '')) === 'active';
$ethernetModeAvailable = openapCanConfigureApEthernet();

$ethernetInterfaces = [];
$netRoot = '/sys/class/net';
$netEntries = @scandir($netRoot);
if (is_array($netEntries)) {
    foreach ($netEntries as $interfaceName) {
        if ($interfaceName === '.' || $interfaceName === '..') continue;
        $interfacePath = $netRoot . '/' . $interfaceName;
        // Only report physical, non-wireless Ethernet devices. This avoids
        // treating the AP bridge, loopback and virtual links as a cable.
        if (!is_link($interfacePath . '/device') || is_dir($interfacePath . '/wireless')) continue;
        if (trim((string) @file_get_contents($interfacePath . '/type')) !== '1') continue;
        $carrierValue = trim((string) @file_get_contents($interfacePath . '/carrier'));
        $carrier = $carrierValue === '1' ? true : ($carrierValue === '0' ? false : null);
        $operstate = trim((string) @file_get_contents($interfacePath . '/operstate'));
        $ethernetInterfaces[] = [
            'name' => $interfaceName,
            'carrier' => $carrier,
            'operstate' => $operstate !== '' ? $operstate : null,
        ];
    }
}
$ethernetConnected = null;
foreach ($ethernetInterfaces as $ethernetInterface) {
    if ($ethernetInterface['carrier'] === true) {
        $ethernetConnected = true;
        break;
    }
    if ($ethernetInterface['carrier'] === false && $ethernetConnected === null) {
        $ethernetConnected = false;
    }
}

echo json_encode([
    'schema' => 'openap-interface-roles/v2',
    'available' => (bool) $repeaterAvailability['valid'],
    'repeater_available' => (bool) $repeaterAvailability['valid'],
    'repeater_unavailable_reason' => $repeaterAvailability['reason'],
    'repeater_unavailable_code' => $repeaterAvailability['reason_code'],
    'count' => count($radios),
    'ethernet' => $ethernetInterfaces,
    'ethernet_connected' => $ethernetConnected,
    'radios' => array_values($radios),
    'ap' => $repeaterAvailability['ap'],
    'uplink' => $repeaterAvailability['uplink'],
    'ap_present' => (bool) $configuredAp,
    'ap_service_active' => $hostapdActive,
    'ethernet_mode_available' => $ethernetModeAvailable,
    'uplink_present' => (bool) $configuredUplink,
    'active_roles' => [
        'ap' => $configuredAp['identity_mac'] ?? ($apMac ?: null),
        'ap_24ghz' => $roleModel['active']['ap_24ghz'],
        'ap_5ghz' => $roleModel['active']['ap_5ghz'],
        'uplink' => $roleModel['active']['uplink'],
    ],
    'draft_roles' => $roleModel['draft'],
    'legacy_ap' => $roleModel['legacy_ap'],
    'role_model_source' => $roleModel['source'],
    'role_warnings' => $roleModel['warnings'],
    'draft_validation' => $draftValidation,
    'hotspot_draft' => $roleModel['hotspot_draft'],
    'apply_state' => $applyState,
    'apply_error' => $applyError,
]);
