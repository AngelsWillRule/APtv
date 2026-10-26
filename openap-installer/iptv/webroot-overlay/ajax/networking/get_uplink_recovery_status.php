<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$profile = is_readable(OPENAP_REPEATER_PROFILE)
    ? parse_ini_file(OPENAP_REPEATER_PROFILE, true, INI_SCANNER_RAW)
    : [];
$mode = is_array($profile) ? (string) ($profile['mode']['current'] ?? '') : '';
$state = [];
$statePath = '/run/openap/uplink-watchdog.env';
if (is_readable($statePath)) {
    $parsed = parse_ini_file($statePath, false, INI_SCANNER_RAW);
    $state = is_array($parsed) ? $parsed : [];
}

$checkedAt = filter_var($state['checked_at'] ?? null, FILTER_VALIDATE_INT);
$fresh = $checkedAt !== false && time() - (int) $checkedAt <= 60;
$status = $fresh ? (string) ($state['status'] ?? 'unknown') : 'unknown';
$failures = filter_var($state['failures'] ?? 0, FILTER_VALIDATE_INT);
$ethernetReady = $fresh && ($state['ethernet_status'] ?? '') === 'ready';
$uplinkInterface = is_array($profile) ? (string) ($profile['interfaces']['uplink'] ?? '') : '';
$uplinkConfig = preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkInterface)
    ? '/etc/wpa_supplicant/wpa_supplicant-' . $uplinkInterface . '.conf'
    : '';
$configChangedAt = $uplinkConfig !== '' && is_file($uplinkConfig) ? filemtime($uplinkConfig) : false;
$transitioning = $mode === 'repeater_wifi'
    && $configChangedAt !== false
    && time() - (int) $configChangedAt < 35;

echo json_encode([
    'mode' => $mode,
    'active' => $mode === 'repeater_wifi',
    'status' => $status,
    'reason' => $fresh ? (string) ($state['reason'] ?? 'Uplink status unavailable') : 'Waiting for a fresh uplink check',
    'interface' => $fresh ? (string) ($state['interface'] ?? '') : '',
    'failures' => $failures === false ? 0 : (int) $failures,
    'internet_ready' => $fresh && ($state['internet'] ?? '') === 'ready',
    'checked_at' => $fresh ? (int) $checkedAt : null,
    'transitioning' => $transitioning,
    'ethernet' => [
        'interface' => $fresh ? (string) ($state['ethernet_interface'] ?? '') : '',
        'ready' => $ethernetReady,
        'reason' => $fresh
            ? (string) ($state['ethernet_reason'] ?? 'Ethernet fallback has not been checked')
            : 'Waiting for a fresh Ethernet check',
    ],
], JSON_UNESCAPED_SLASHES);
