<?php

require_once '../includes/autoload.php';
require_once '../includes/CSRF.php';
require_once '../includes/session.php';
require_once '../includes/config.php';
require_once '../includes/authenticate.php';
require_once '../includes/user_preferences.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$username = (string) ($_SESSION['user_id'] ?? '');
if ($username === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $page = (string) ($_GET['page'] ?? 'dashboard');
    if (!in_array($page, openapWidgetPageIds(), true)) {
        $page = 'dashboard';
    }
    $layout = openapWidgetLayoutRead($username, $page);
    echo json_encode([
        'success' => true,
        'page' => $page,
        'order' => $layout['order'],
        'hidden' => $layout['hidden'],
        'widths' => $layout['widths'],
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!\OpenAP\Tokens\CSRF::verify()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$page = (string) ($_POST['page'] ?? 'dashboard');
if (!in_array($page, openapWidgetPageIds(), true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unknown widget page.']);
    exit;
}

$order = json_decode((string) ($_POST['order'] ?? '[]'), true);
$hidden = json_decode((string) ($_POST['hidden'] ?? '[]'), true);
$widths = json_decode((string) ($_POST['widths'] ?? '{}'), true);
if (!is_array($order) || !is_array($hidden) || !is_array($widths)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid widget layout.']);
    exit;
}

try {
    $saved = openapWidgetLayoutWrite($username, $page, [
        'order' => $order,
        'hidden' => $hidden,
        'widths' => $widths,
    ]);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    exit;
} catch (Throwable $error) {
    error_log('Unable to save OpenAP widget layout: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the widget layout.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Widget layout saved for this account.',
    'page' => $page,
    'order' => $saved['order'],
    'hidden' => $saved['hidden'],
    'widths' => $saved['widths'],
]);
