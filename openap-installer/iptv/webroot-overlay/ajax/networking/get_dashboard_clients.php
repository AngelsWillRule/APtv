<?php

chdir(dirname(__DIR__, 2));
require_once 'includes/autoload.php';
require_once 'includes/session.php';
require_once 'includes/config.php';
require_once 'includes/authenticate.php';
require_once 'includes/repeater.php';
require_once 'includes/clients.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$profile = openapReadRepeaterProfile();
$profileApInterfaces = [
    '2.4' => (string) ($profile['interfaces']['ap_24ghz'] ?? ''),
    '5' => (string) ($profile['interfaces']['ap_5ghz'] ?? ''),
];
foreach ([
    '2.4' => '/etc/hostapd/openap-ap-24ghz.conf',
    '5' => '/etc/hostapd/openap-ap-5ghz.conf',
] as $band => $radioConfig) {
    if ($profileApInterfaces[$band] !== '' || !is_readable($radioConfig)) {
        continue;
    }
    $contents = (string) file_get_contents($radioConfig);
    if (preg_match('/^interface=([A-Za-z0-9_.:-]+)$/m', $contents, $match)) {
        $profileApInterfaces[$band] = $match[1];
    }
}
$legacyApInterface = (string) ($profile['interfaces']['ap'] ?? ($_SESSION['ap_interface'] ?? ''));
$interfaces = array_values(array_unique(array_filter([
    $profileApInterfaces['2.4'],
    $profileApInterfaces['5'],
    $legacyApInterface,
], static fn($value): bool => is_string($value) && $value !== '')));
$bridge = (string) ($profile['interfaces']['bridge'] ?? $profile['network']['bridge'] ?? ($interfaces[0] ?? ''));
$clients = openapGetClientListForInterfaces($interfaces, $bridge);
$breakdown = openapGetClientBreakdownForList($clients);
$readApState = static function (string $interface, string $band): array {
    $state = ['active' => false, 'band' => $band, 'ssid' => '-'];
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return $state;
    }
    exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' info 2>/dev/null', $output, $status);
    if ($status !== 0) {
        return $state;
    }
    $info = implode("\n", $output);
    $state['active'] = preg_match('/^[[:space:]]*type[[:space:]]+AP$/m', $info) === 1;
    if (preg_match('/^[[:space:]]*ssid[[:space:]]+(.+)$/m', $info, $match)) {
        $state['ssid'] = trim($match[1]);
    }
    return $state;
};
$activeBands = [];
$apSsid = '-';
foreach ($profileApInterfaces as $band => $apInterface) {
    if ($apInterface === '') {
        continue;
    }
    $radioState = $readApState($apInterface, $band);
    if (!empty($radioState['active'])) {
        $activeBands[] = $band;
        if (($radioState['ssid'] ?? '-') !== '-') {
            $apSsid = (string) $radioState['ssid'];
        }
    }
}
if ($activeBands === [] && $legacyApInterface !== '') {
    $legacyBand = openapGetInterfaceBand($legacyApInterface);
    $radioState = $readApState($legacyApInterface, $legacyBand);
    if (!empty($radioState['active']) && in_array($legacyBand, ['2.4', '5'], true)) {
        $activeBands[] = $legacyBand;
        $apSsid = (string) ($radioState['ssid'] ?? '-');
    }
}
$activeBands = array_values(array_unique($activeBands));
if ($apSsid === '-') {
    $configuredSsid = trim((string) openapHostapdConfigValue('ssid'));
    if ($configuredSsid !== '' && $configuredSsid !== '-') {
        $apSsid = $configuredSsid;
    }
}

echo json_encode([
    'total' => $breakdown['total'],
    'bands' => [
        '2.4' => count(array_filter($clients, static fn(array $client): bool => ($client['band'] ?? '') === '2.4')),
        '5' => count(array_filter($clients, static fn(array $client): bool => ($client['band'] ?? '') === '5')),
    ],
    'active_bands' => $activeBands,
    'ssid' => $apSsid,
    'signal' => $breakdown,
    'timestamp_ms' => (int) round(microtime(true) * 1000),
]);
