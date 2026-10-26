<?php

require_once '../../includes/autoload.php';
require_once '../../includes/CSRF.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/components.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


function openapApWizardReport(): array
{
    $detector = '/usr/local/lib/openap-installer/source/openap-installer/bin/openap-detect';
    if (!is_executable($detector)) {
        return ['error' => _('The AP hardware detector is not available.')];
    }

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    // The AP installation wizard must use a fresh inventory. A stale shared
    // cache can hide an adapter that is already available to the web process.
    $process = proc_open([$detector, '--json'], $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['error' => _('Unable to inspect the AP hardware.')];
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $report = json_decode((string) $output, true);
    if ($exitCode !== 0 || !is_array($report)) {
        return ['error' => _('Unable to inspect the AP hardware.')];
    }

    $interfaces = [];
    foreach ((array) ($report['interfaces'] ?? []) as $interface) {
        if (!is_array($interface) || empty($interface['is_wireless']) || empty($interface['supports_ap'])) {
            continue;
        }
        $name = trim((string) ($interface['name'] ?? ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            continue;
        }
        $interfaces[] = [
            'name' => $name,
            'driver' => (string) ($interface['driver'] ?? ''),
            'state' => (string) ($interface['state'] ?? ''),
            'operstate' => (string) ($interface['operstate'] ?? ''),
            'ssid' => (string) ($interface['ssid'] ?? ''),
            'supports_24ghz' => !empty($interface['supports_24ghz']),
            'supports_5ghz' => !empty($interface['supports_5ghz']),
        ];
    }

    $recommended = (string) (($report['proposed_roles']['ap'] ?? ''));
    $names = array_column($interfaces, 'name');
    if (!in_array($recommended, $names, true)) {
        $recommended = $names[0] ?? '';
    }

    $country = 'IT';
    foreach (['/etc/hostapd/hostapd.conf', '/etc/openap/hostapd/hostapd.conf'] as $hostapdConfig) {
        if (!is_readable($hostapdConfig)) {
            continue;
        }
        $contents = (string) file_get_contents($hostapdConfig);
        if (preg_match('/^country_code=([A-Za-z]{2})$/m', $contents, $match)) {
            $country = strtoupper($match[1]);
            break;
        }
    }

    $authPath = '/etc/openap/openap.auth';
    $adminUser = '';
    if (is_readable($authPath)) {
        $authLines = file($authPath, FILE_IGNORE_NEW_LINES);
        $adminUser = trim((string) ($authLines[0] ?? ''));
    }

    return [
        'interfaces' => $interfaces,
        'recommended_interface' => $recommended,
        'ethernet' => (string) (($report['proposed_roles']['ethernet'] ?? '')),
        'country' => $country,
        'admin_credentials_present' => is_file($authPath),
        'admin_user' => $adminUser,
    ];
}

function openapComponentJobLog(string $jobId): array
{
    $path = '/run/openap-component-manager/jobs/' . $jobId . '.log';
    if (!is_readable($path)) {
        return [];
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        return [];
    }
    $contents = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $contents) ?? $contents;
    $contents = str_replace("\r", "\n", $contents);
    $rawLines = preg_split('/\n+/', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lines = [];
    foreach (array_slice($rawLines, -320) as $rawLine) {
        $line = trim((string) $rawLine);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\b(hotspot_password|admin_password|password|passwd)\s*[:=]\s*\S+/i', '$1=[redacted]', $line) ?? $line;
        $lines[] = function_exists('mb_substr') ? mb_substr($line, 0, 2000) : substr($line, 0, 2000);
    }
    return $lines;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (($_GET['wizard'] ?? '') === 'ap') {
        $wizard = openapApWizardReport();
        if (isset($wizard['error'])) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => $wizard['error']]);
        } else {
            echo json_encode(['success' => true, 'wizard' => 'ap'] + $wizard);
        }
        exit;
    }

    $jobId = strtolower(trim((string) ($_GET['job'] ?? '')));
    if ($jobId === '' || !preg_match('/^[a-f0-9]+$/', $jobId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => _('Invalid component job.')]);
        exit;
    }
    $statusPath = '/run/openap-component-manager/status.json';
    $status = is_readable($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    if (!is_array($status) || ($status['job_id'] ?? '') !== $jobId) {
        echo json_encode(['success' => true, 'state' => 'pending', 'job_id' => $jobId, 'log' => openapComponentJobLog($jobId)]);
        exit;
    }
    echo json_encode(['success' => true] + $status + ['log' => openapComponentJobLog($jobId)]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => _('POST is required.')]);
    exit;
}

$component = strtolower(trim((string) ($_POST['component'] ?? '')));
$action = strtolower(trim((string) ($_POST['action'] ?? '')));

if (!in_array($component, ['ap', 'iptv'], true) || !in_array($action, ['install', 'remove'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => _('Invalid component operation.')]);
    exit;
}

$installed = openapComponentEnabled($component === 'ap' ? 'openap' : 'iptv');
if ($action === 'install' && $installed) {
    echo json_encode(['success' => true, 'message' => _('The component is already installed.')]);
    exit;
}
if ($action === 'remove' && !$installed) {
    echo json_encode(['success' => true, 'message' => _('The component is not installed.')]);
    exit;
}


$wizardRequest = null;
if ($component === 'ap' && $action === 'install') {
    $apIface = trim((string) ($_POST['ap_iface'] ?? ''));
    $country = strtoupper(trim((string) ($_POST['country'] ?? '')));
    $ssid = (string) ($_POST['ssid'] ?? '');
    $hotspotPassword = (string) ($_POST['hotspot_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $apIface)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => _('Choose a valid AP interface.')]);
        exit;
    }
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => _('Enter a valid two-letter wireless country code.')]);
        exit;
    }
    $ssidLength = strlen($ssid);
    if ($ssidLength < 1 || $ssidLength > 32) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => _('The hotspot SSID must contain between 1 and 32 bytes.')]);
        exit;
    }
    $passwordLength = strlen($hotspotPassword);
    if ($passwordLength < 8 || $passwordLength > 63) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => _('The hotspot password must contain between 8 and 63 bytes.')]);
        exit;
    }

    $wizard = openapApWizardReport();
    if (isset($wizard['error'])) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => $wizard['error']]);
        exit;
    }
    $allowedInterfaces = array_column((array) ($wizard['interfaces'] ?? []), 'name');
    if (!in_array($apIface, $allowedInterfaces, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => _('The selected AP interface is no longer available.')]);
        exit;
    }

    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');
    $adminCredentialsPresent = !empty($wizard['admin_credentials_present']);
    if (!$adminCredentialsPresent) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $adminUser)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => _('Enter a valid administrator username.')]);
            exit;
        }
        if (strlen($adminPassword) < 12 || strlen($adminPassword) > 128 || $adminPassword !== $adminPasswordConfirm) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => _('The administrator passwords must match and contain between 12 and 128 bytes.')]);
            exit;
        }
    } else {
        $adminUser = '';
        $adminPassword = '';
    }

    $wizardRequest = [
        'ap_iface' => $apIface,
        'country' => $country,
        'ssid' => $ssid,
        'hotspot_password' => $hotspotPassword,
        'admin_user' => $adminUser,
        'admin_password' => $adminPassword,
    ];
}

$manager = '/usr/local/sbin/openap-component-manager';
if (!is_executable($manager)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => _('The component manager is not available. Run aptv-install.sh once to provision it.')]);
    exit;
}

$outputPath = tempnam(sys_get_temp_dir(), 'openap-component-');
$jobId = bin2hex(random_bytes(8));
$requestPath = '/var/lib/openap-component-manager/requests/' . $jobId . '.json';
if ($wizardRequest !== null) {
    $requestJson = json_encode($wizardRequest, JSON_UNESCAPED_SLASHES);
    if (!is_string($requestJson) || @file_put_contents($requestPath, $requestJson, LOCK_EX) === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => _('Unable to save the AP setup request.')]);
        exit;
    }
    @chmod($requestPath, 0600);
}
$command = ['/usr/bin/sudo', '-n', $manager, $action === 'install' ? 'add' : 'remove', $component, '--yes', 'background', $jobId];
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $outputPath, 'a'],
    2 => ['file', $outputPath, 'a'],
];
$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    if ($wizardRequest !== null) {
        @unlink($requestPath);
    }
    @unlink($outputPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => _('Unable to start the component manager.')]);
    exit;
}

$exitCode = proc_close($process);
$output = $outputPath && is_file($outputPath) ? (string) file_get_contents($outputPath) : '';
if ($outputPath) {
    @unlink($outputPath);
}

if ($exitCode !== 0) {
    if ($wizardRequest !== null) {
        @unlink($requestPath);
    }
    error_log('OpenAP component operation failed: ' . trim(substr($output, -2000)));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => _('The component operation failed. Check the installer log.')]);
    exit;
}

echo json_encode([
    'success' => true,
    'accepted' => true,
    'job_id' => $jobId,
    'message' => _('Component operation started.'),
]);
