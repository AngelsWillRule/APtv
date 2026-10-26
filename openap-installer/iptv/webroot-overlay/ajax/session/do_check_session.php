<?php
require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

$lastActivity = $_SESSION['lastActivity'] ?? time();
$sessionLifetime = time() - $lastActivity;
$status = $sessionLifetime >= OPENAP_SESSION_TIMEOUT ? 'session_expired' : 'active';

// Watching an APtv channel is active use even when there are no clicks or
// page navigations. Refresh the authenticated session only while the player
// is open; closing it restores the normal inactivity timeout.
if ($status === 'active' && ($_POST['keepalive'] ?? '') === '1') {
    $_SESSION['lastActivity'] = time();
    $lastActivity = $_SESSION['lastActivity'];
    $sessionLifetime = 0;
}

if ($status === 'session_expired') {
    session_unset(); // unset all session variables
    session_destroy(); // destroy the session
}

// send response
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Pragma: no-cache');

$response = [
    'status' => $status,
    'last_activity' => $lastActivity,
    'session_lifetime' => $sessionLifetime
];

echo json_encode($response);
exit();
