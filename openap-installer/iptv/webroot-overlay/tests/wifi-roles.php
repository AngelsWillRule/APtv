<?php

require __DIR__ . '/../includes/wifi_roles.php';

function testRadio(
    string $name,
    string $identity,
    bool $ap = true,
    bool $managed = true,
    bool $band24 = true,
    bool $band5 = true,
    string $frequency = ''
): array {
    return [
        'name' => $name,
        'mac' => $identity,
        'permanent_mac' => $identity,
        'identity_mac' => $identity,
        'supports_ap' => $ap,
        'supports_managed' => $managed,
        'supports_24ghz' => $band24,
        'supports_5ghz' => $band5,
        'frequency_mhz' => $frequency,
    ];
}

function assertError(array $validation, string $code): void
{
    foreach ($validation['errors'] as $error) {
        if (($error['code'] ?? '') === $code) return;
    }
    exit(1);
}

$ap24 = 'aa:bb:cc:dd:ee:01';
$ap5 = 'aa:bb:cc:dd:ee:02';
$uplink = 'aa:bb:cc:dd:ee:03';
$radios = [
    'radio24' => testRadio('radio24', $ap24, true, true, true, false, '2437'),
    'radio5' => testRadio('radio5', $ap5, true, true, false, true, '5180'),
    'radio-uplink' => testRadio('radio-uplink', $uplink),
];

$oneRadio = openapValidateWifiRoles(['ap_24ghz' => $ap24], $radios);
if (!$oneRadio['valid']) exit(1);

$twoAp = openapValidateWifiRoles(['ap_24ghz' => $ap24, 'ap_5ghz' => $ap5], $radios);
if (!$twoAp['valid']) exit(1);

$threeRoles = ['ap_24ghz' => $ap24, 'ap_5ghz' => $ap5, 'uplink' => $uplink];
if (!openapValidateWifiRoles($threeRoles, $radios)['valid']) exit(1);

assertError(openapValidateWifiRoles([
    'ap_24ghz' => $ap24,
    'uplink' => $ap24,
], $radios), 'duplicate_radio');
assertError(openapValidateWifiRoles(['ap_5ghz' => $ap24], $radios), 'band_not_supported');
assertError(openapValidateWifiRoles(['uplink' => $uplink], $radios), 'ap_required');
assertError(openapValidateWifiRoles(['ap_24ghz' => 'aa:bb:cc:dd:ee:99'], $radios), 'radio_not_present');
assertError(openapValidateWifiRoles(['ap_24ghz' => 'invalid'], $radios), 'invalid_identity');

$legacy = [
    'interfaces' => [
        'ap_mac' => $ap24,
        'uplink_mac' => $uplink,
    ],
];
$migrated = openapBuildWifiRoleModel($legacy, [], $radios);
if ($migrated['source'] !== 'legacy') exit(1);
if ($migrated['active']['ap_24ghz'] !== $ap24) exit(1);
if ($migrated['active']['uplink'] !== $uplink) exit(1);
if ($migrated['legacy_ap'] !== null) exit(1);

$ambiguousIdentity = 'aa:bb:cc:dd:ee:04';
$ambiguous = ['dual' => testRadio('dual', $ambiguousIdentity)];
$unresolved = openapBuildWifiRoleModel([
    'interfaces' => ['ap_mac' => $ambiguousIdentity],
], [], $ambiguous);
if ($unresolved['legacy_ap'] !== $ambiguousIdentity) exit(1);
if (($unresolved['warnings'][0]['code'] ?? '') !== 'legacy_ap_band_unresolved') exit(1);

$rendered = openapRenderWifiRoleModel($threeRoles, [
    'ap_24ghz' => $ap24,
    'ap_5ghz' => null,
    'uplink' => $uplink,
]);
$parsed = parse_ini_string($rendered, true, INI_SCANNER_RAW);
if (!is_array($parsed) || ($parsed['meta']['version'] ?? '') !== '2') exit(1);
$stored = openapBuildWifiRoleModel([], $parsed, $radios);
if ($stored['source'] !== 'wifi-roles') exit(1);
if ($stored['active'] !== openapNormalizeWifiRoles($threeRoles)) exit(1);
if ($stored['draft']['ap_5ghz'] !== null) exit(1);

$temporaryDirectory = sys_get_temp_dir() . '/openap-wifi-roles-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700)) exit(1);
$temporaryProfile = $temporaryDirectory . '/wifi-roles.ini';
$hotspotValidation = openapValidateHotspotDraft([
    'ssid' => 'OpenAP dual band',
    'ap_24ghz_channel' => '6',
    'ap_24ghz_width' => '20',
    'ap_24ghz_txpower' => '20',
    'ap_5ghz_channel' => '36',
    'ap_5ghz_width' => '80',
    'ap_5ghz_txpower' => '23',
    'ap_isolate' => '0',
    'ignore_broadcast_ssid' => '0',
]);
if (!$hotspotValidation['valid']) exit(1);
openapWriteWifiRoleModel(
    $temporaryProfile,
    $threeRoles,
    $threeRoles,
    $ambiguousIdentity,
    $hotspotValidation['hotspot_draft']
);
$written = parse_ini_file($temporaryProfile, true, INI_SCANNER_RAW);
if (!is_array($written) || ($written['legacy']['ap'] ?? '') !== $ambiguousIdentity) exit(1);
if ((fileperms($temporaryProfile) & 0777) !== 0640) exit(1);
$writtenModel = openapBuildWifiRoleModel([], $written, $radios);
if ($writtenModel['legacy_ap'] !== $ambiguousIdentity) exit(1);
if (!$writtenModel['hotspot_draft']['configured']) exit(1);
if ($writtenModel['hotspot_draft']['ssid'] !== 'OpenAP dual band') exit(1);
if ($writtenModel['hotspot_draft']['ap_5ghz_width'] !== 80) exit(1);
if (openapValidateHotspotDraft(array_merge(
    $hotspotValidation['hotspot_draft'],
    ['ap_24ghz_channel' => '36']
))['valid']) exit(1);

$strictAvailable = openapEvaluateRepeaterAvailability($threeRoles, $radios);
if (!$strictAvailable['valid'] || ($strictAvailable['uplink']['identity_mac'] ?? '') !== $uplink) exit(1);
$notAssigned = openapEvaluateRepeaterAvailability(array_merge($threeRoles, ['uplink' => null]), $radios);
if ($notAssigned['valid'] || $notAssigned['reason_code'] !== 'uplink_not_assigned') exit(1);
$noFreeUplink = openapEvaluateRepeaterAvailability([
    'ap_24ghz' => $ap24,
    'ap_5ghz' => $ap5,
    'uplink' => null,
], array_intersect_key($radios, ['radio24' => true, 'radio5' => true]));
if ($noFreeUplink['valid'] || $noFreeUplink['reason_code'] !== 'uplink_not_available') exit(1);
$disconnected = openapEvaluateRepeaterAvailability(array_merge($threeRoles, [
    'uplink' => 'aa:bb:cc:dd:ee:99',
]), $radios);
if ($disconnected['valid'] || $disconnected['reason_code'] !== 'assigned_uplink_disconnected') exit(1);
unlink($temporaryProfile);
rmdir($temporaryDirectory);
