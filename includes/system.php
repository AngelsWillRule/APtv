<?php

require_once 'includes/functions.php';
require_once 'includes/openap_version.php';
require_once 'config.php';

/**
 *
 */
function DisplaySystem(&$extraFooterScripts)
{
    $status = new \OpenAP\Messages\StatusMessage;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['system_action'] ?? '') === 'reboot') {
        exec('sudo /usr/local/sbin/openap-system-reboot >/dev/null 2>&1 &');
        header('Location: /?rebooting=1', true, 303);
        exit;
    }

    // fetch system status variables
    $system = new \OpenAP\System\Sysinfo;

    $hostname = trim($system->hostname());
    $uptime   = trim($system->uptime());
    $cores    = $system->processorCount();
    $os       = trim($system->operatingSystem());
    $kernel   = trim($system->kernelVersion());
    $systime  = trim($system->systime());
    $machine  = openapCommandValue('uname -m');
    $container = openapCommandValue('systemd-detect-virt --container 2>/dev/null', 'none');
    $openapMode = openapReadIniValue('/etc/openap/repeater.ini', 'current', 'Unknown');
    $openapModeLabel = [
        'ap_ethernet' => 'AP over Ethernet',
        'ap_ethernet_bridge' => 'Ethernet Bridge',
        'repeater_wifi' => 'Repeater WiFi',
        'uplink_wifi' => 'Uplink WiFi'
    ][$openapMode] ?? $openapMode;
    $managementIp = openapCommandValue("hostname -I | awk '{print $1}'");
    $openapUiVersion = openapInstalledVersion();

    // memory use
    $memused  = $system->usedMemory();
    $memStatus = getResourceStatus($memused);
    $memused_status = $memStatus['status'];
    $memused_led = $memStatus['led'];

    // disk storage use
    $diskused  = $system->usedDisk();
    $diskStatus = getResourceStatus($diskused);
    $diskused_status = $diskStatus['status'];
    $diskused_led = $diskStatus['led'];

    // cpu load
    $cpuload = $system->systemLoadPercentage();
    $cpuload_status = getCPULoadStatus($cpuload);

    // cpu temp
    $cputemp = $system->systemTemperature();
    $cpuStatus = getCPUTempStatus($cputemp);
    $cputemp_status = $cpuStatus['status'];
    $cputemp_led =  $cpuStatus['led'];

    $serviceNames = [
        'hostapd.service',
        'dnsmasq.service',
        'openap-firewall.service',
        'lighttpd.service',
        'systemd-networkd.service'
    ];
    if ($openapMode === 'repeater_wifi' || file_exists('/etc/systemd/system/openap-uplink.service')) {
        array_splice($serviceNames, 2, 0, ['openap-uplink.service']);
    }
    $services = array_map('openapServiceInfo', $serviceNames);
    $uplinkHealth = null;
    if ($openapMode === 'repeater_wifi' && function_exists('openapUplinkHealth')) {
        $uplinkHealth = openapUplinkHealth();
        foreach ($services as &$service) {
            if ($service['name'] === 'openap-uplink.service' && empty($uplinkHealth['ready'])) {
                $service['active'] = 'degraded: ' . ($uplinkHealth['reason'] ?? 'runtime check failed');
                $service['statusClass'] = 'down';
            }
        }
        unset($service);
    }

    $software = [
        ['name' => 'OpenAP UI', 'version' => $openapUiVersion],
        ['name' => 'PHP', 'version' => PHP_VERSION],
        ['name' => 'lighttpd', 'version' => openapSoftwareVersion('lighttpd -v 2>&1', '/lighttpd\/([\w.\-]+)/')],
        ['name' => 'hostapd', 'version' => openapSoftwareVersion('hostapd -v 2>&1', '/hostapd v?([\w.\-]+)/i')],
        ['name' => 'dnsmasq', 'version' => openapSoftwareVersion('dnsmasq --version 2>&1', '/Dnsmasq version ([\w.\-]+)/i')],
        ['name' => 'iw', 'version' => openapSoftwareVersion('iw --version 2>&1', '/iw version ([\w.\-]+)/i')],
        ['name' => 'wpa_supplicant', 'version' => openapSoftwareVersion('wpa_supplicant -v 2>&1', '/wpa_supplicant v?([\w.\-]+)/i')],
        ['name' => 'nftables', 'version' => openapSoftwareVersion('nft --version 2>&1', '/nftables v?([\w.\-]+)/i')]
    ];

    $network = [
        ['label' => 'AP interface', 'value' => defined('OPENAP_WIFI_AP_INTERFACE') ? OPENAP_WIFI_AP_INTERFACE : 'wlan0'],
        ['label' => 'Uplink interface', 'value' => openapCommandValue("ip route show default 2>/dev/null | sed -n 's/.* dev \\([^ ]*\\).*/\\1/p' | head -1")],
        ['label' => 'Management IP', 'value' => $managementIp],
        ['label' => 'AP SSID', 'value' => openapReadHostapdValue(defined('OPENAP_HOSTAPD_CONFIG') ? OPENAP_HOSTAPD_CONFIG : '/etc/hostapd/hostapd.conf', 'ssid')],
        ['label' => 'AP channel', 'value' => openapReadHostapdValue(defined('OPENAP_HOSTAPD_CONFIG') ? OPENAP_HOSTAPD_CONFIG : '/etc/hostapd/hostapd.conf', 'channel')],
        ['label' => 'Mode profile', 'value' => $openapModeLabel]
    ];
    if (is_array($uplinkHealth)) {
        $network[] = ['label' => 'Uplink runtime', 'value' => $uplinkHealth['reason'] ?? 'Unknown'];
    }

    $configPaths = [
        defined('OPENAP_HOSTAPD_CONFIG') ? OPENAP_HOSTAPD_CONFIG : '/etc/hostapd/hostapd.conf',
        '/etc/dnsmasq.d/openap-repeater.conf',
        '/etc/openap/repeater.ini',
        '/etc/openap/networking/openap.nft',
        OPENAP_LIGHTTPD_CONFIG
    ];
    if ($openapMode === 'repeater_wifi' || file_exists('/etc/systemd/system/openap-uplink.service')) {
        array_splice($configPaths, 2, 0, ['/etc/systemd/system/openap-uplink.service']);
    }
    $configFiles = array_map('openapConfigFileInfo', $configPaths);

    echo renderTemplate("system", compact(
        "status",
        "hostname",
        "uptime",
        "systime",
        "machine",
        "container",
        "cores",
        "os",
        "kernel",
        "memused",
        "memused_status",
        "memused_led",
        "diskused",
        "diskused_status",
        "diskused_led",
        "cpuload",
        "cpuload_status",
        "cputemp",
        "cputemp_status",
        "cputemp_led",
        "services",
        "software",
        "network",
        "configFiles",
        "openapModeLabel"
    ));
}

function openapCommandValue(string $command, string $fallback = 'Unknown'): string
{
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $value = trim(implode("\n", $output));
    return $value !== '' ? $value : $fallback;
}

function openapSoftwareVersion(string $command, string $pattern): string
{
    $output = openapCommandValue($command, '');
    if ($output !== '' && preg_match($pattern, $output, $matches)) {
        return $matches[1];
    }
    return $output !== '' ? strtok($output, "\n") : 'Not installed';
}

function openapServiceInfo(string $service): array
{
    $active = openapCommandValue('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null', 'unknown');
    $enabled = openapCommandValue('/bin/systemctl is-enabled ' . escapeshellarg($service) . ' 2>/dev/null', 'unknown');
    $since = openapCommandValue('/bin/systemctl show ' . escapeshellarg($service) . ' --property=ActiveEnterTimestamp --value 2>/dev/null', 'Unknown');

    return [
        'name' => $service,
        'active' => $active,
        'enabled' => $enabled,
        'since' => $since,
        'statusClass' => $active === 'active' ? 'up' : ($active === 'inactive' || $active === 'unknown' ? 'warn' : 'down')
    ];
}

function openapReadIniValue(string $path, string $key, string $fallback = 'Unknown'): string
{
    if (!is_readable($path)) {
        return $fallback;
    }
    $data = parse_ini_file($path);
    return isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : $fallback;
}

function openapReadHostapdValue(string $path, string $key): string
{
    if (!is_readable($path)) {
        return 'Unknown';
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, $key . '=') === 0) {
            return trim(substr($line, strlen($key) + 1));
        }
    }
    return 'Unknown';
}

function openapConfigFileInfo(string $path): array
{
    return [
        'path' => $path,
        'exists' => is_readable($path),
        'modified' => is_readable($path) ? date('Y-m-d H:i:s', filemtime($path)) : 'Missing'
    ];
}

function getResourceStatus($used): array
{
    $used_status = "primary";
    $used_led = "";

    if ($used > 90) {
        $used_status = "danger";
        $used_led = "service-status-down";
    } elseif ($used > 75) {
        $used_status = "warning";
        $used_led = "service-status-warn";
    } elseif ($used > 0) {
        $used_status = "success";
        $used_led = "service-status-up";
    }

    return [
        'status' => $used_status,
        'led' => $used_led
    ];
}

function getCPULoadStatus($cpuload): string
{
    if ($cpuload > 90) {
        $status = "danger";
    } elseif ($cpuload > 75) {
        $status = "warning";
    } elseif ($cpuload >=  0) {
        $status = "success";
    }
    return $status;
}

function getCPUTempStatus($cputemp): array
{
    if ($cputemp > 70) {
        $cputemp_status = "danger";
        $cputemp_led = "service-status-down";
    } elseif ($cputemp > 50) {
        $cputemp_status = "warning";
        $cputemp_led = "service-status-warn";
    } else {
        $cputemp_status = "success";
        $cputemp_led = "service-status-up";
    }
    return [
        'status' => $cputemp_status,
        'led' => $cputemp_led
    ];
}
