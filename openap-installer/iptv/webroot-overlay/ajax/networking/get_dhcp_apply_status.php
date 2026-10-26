<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

$state = is_readable('/run/openap/dhcp-apply-status')
    ? trim((string) file_get_contents('/run/openap/dhcp-apply-status'))
    : 'unknown';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(['status' => $state]);
exit;
