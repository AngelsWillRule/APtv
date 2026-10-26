<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';
require_once '../../includes/user_preferences.php';

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
    echo json_encode([
        'success' => true,
        'order' => openapReadDashboardWidgetOrder($username),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$requested = json_decode((string) ($_POST['order'] ?? ''), true);
if (!is_array($requested)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid widget order.']);
    exit;
}

try {
    $saved = openapWriteDashboardWidgetOrder($username, $requested);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    exit;
} catch (Throwable $error) {
    error_log('Unable to save OpenAP dashboard widget order: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the widget order.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Widget order saved for this account.',
    'order' => $saved,
]);
