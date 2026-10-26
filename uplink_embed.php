<?php
/**
 * Lightweight WiFi uplink wizard endpoint for the dashboard modal.
 */

require_once 'includes/bootstrap.php';
require_once 'includes/config.php';
require_once 'includes/defaults.php';
require_once 'includes/autoload.php';
require_once 'includes/session.php';
require_once 'includes/CSRF.php';
require_once 'includes/locale.php';
require_once 'includes/functions.php';
require_once 'includes/authenticate.php';
require_once 'includes/repeater.php';

if (($_GET['status'] ?? '') === '1' && ($_GET['format'] ?? '') === 'json') {
    $profile = openapReadRepeaterProfile();
    $health = openapUplinkHealth();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode([
        'mode' => (string) ($profile['mode']['current'] ?? ''),
        'ready' => !empty($health['ready']),
        'ssid' => openapCurrentUplinkSsid(),
        'apply_state' => trim((string) @file_get_contents('/run/openap/repeater-apply.state')),
        'reason' => (string) ($health['reason'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

DisplayUplinkWizard(['embedded' => true]);
