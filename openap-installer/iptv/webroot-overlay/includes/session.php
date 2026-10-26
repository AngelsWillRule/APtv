<?php

$openapSessionConfig = __DIR__ . '/config.php';
if (!is_file($openapSessionConfig)) {
    $openapSessionConfig = __DIR__ . '/../config/config.repeater.php';
}
require_once $openapSessionConfig;
require_once __DIR__ . '/defaults.php';
unset($openapSessionConfig);

if (session_status() == PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!isset($_SESSION['lastActivity'])) {
    $_SESSION['lastActivity'] = time();
}

if (defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER) {
    $_SESSION['ap_interface'] = $_SESSION['ap_interface'] ?? OPENAP_WIFI_AP_INTERFACE;
    $_SESSION['wifi_client_interface'] = $_SESSION['wifi_client_interface'] ?? OPENAP_WIFI_CLIENT_INTERFACE;
}

/**
 * Release the interactive session lock before an endpoint performs slow work.
 *
 * Callers must read and validate every session value they need first.  The
 * helper is deliberately idempotent because media and API handlers can share
 * code paths and may release the session more than once.
 */
function openap_release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}
