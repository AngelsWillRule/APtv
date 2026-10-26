<?php

require_once __DIR__ . '/user_preferences.php';

function openapWidgetPageKey(string $path): string
{
    $map = [
        '' => 'dashboard', '/' => 'dashboard', '/dashboard' => 'dashboard', '/repeater' => 'dashboard',
        '/clients' => 'clients', '/ap_configuration' => 'ap_configuration',
        '/dhcp_setting' => 'dhcp_setting', '/logging' => 'logging',
        '/system_info' => 'system',
    ];
    return $map[$path] ?? openapNormalizeWidgetPage(trim($path, '/') ?: 'dashboard');
}

function openapPageWidgetData(string $page): array
{
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $interfaces = $profile['interfaces'] ?? [];
    $mode = str_replace('-', '_', (string) ($profile['mode']['current'] ?? 'ap_ethernet'));
    $isRepeater = $mode === 'repeater_wifi';
    $apInterfaces = array_values(array_unique(array_filter([
        $interfaces['ap_24ghz'] ?? '', $interfaces['ap_5ghz'] ?? '', $interfaces['ap'] ?? '',
    ])));
    $hotspotInterface = (string) ($interfaces['bridge'] ?? ($interfaces['ap'] ?? 'wlan0'));
    $uplinkInterface = (string) ($isRepeater ? ($interfaces['uplink'] ?? 'wlan1') : ($interfaces['ethernet'] ?? 'eth0'));
    $clients = function_exists('openapGetClientListForInterfaces')
        ? openapGetClientListForInterfaces($apInterfaces, $hotspotInterface) : [];
    $connected = array_values(array_filter($clients, static fn(array $client): bool => !empty($client['connected'])));
    $breakdown = function_exists('openapGetClientBreakdownForList')
        ? openapGetClientBreakdownForList($clients) : ['avg_signal' => 0];
    $apTraffic = function_exists('openapGetInterfacesTraffic') ? openapGetInterfacesTraffic($apInterfaces) : [];
    $uplinkTraffic = function_exists('openapGetInterfaceTraffic') ? openapGetInterfaceTraffic($uplinkInterface) : [];
    $uplinkName = $isRepeater ? '-': 'Ethernet';
    $uplinkSignal = '-';
    if ($isRepeater && preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkInterface)) {
        $output = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkInterface) . ' link 2>/dev/null', $output);
        $link = implode("\n", $output);
        if (preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $link, $match)) $uplinkName = trim($match[1]);
        if (preg_match('/^[[:space:]]*signal:[[:space:]]*(-?[0-9.]+)/m', $link, $match)) $uplinkSignal = round((float) $match[1]) . ' dBm';
    }
    $system = new \OpenAP\System\Sysinfo();
    $dhcp = function_exists('openapDhcpPoolInfo') ? openapDhcpPoolInfo() : ['active' => 0, 'total' => 150];
    $services = [
        'hostapd' => function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active',
        'dnsmasq' => function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active',
        'firewall' => function_exists('openapServiceActive') && openapServiceActive('openap-firewall.service') === 'active',
        'lighttpd' => function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active',
    ];
    return [
        'page' => $page,
        'order' => openapReadWidgetOrder((string) ($_SESSION['user_id'] ?? ''), $page),
        'clients' => count($connected), 'avg_signal' => (int) ($breakdown['avg_signal'] ?? 0),
        'ap_tx' => openapFormatBytes((int) ($apTraffic['tx_bytes'] ?? 0)),
        'ap_rx' => openapFormatBytes((int) ($apTraffic['rx_bytes'] ?? 0)),
        'uplink_tx' => openapFormatBytes((int) ($uplinkTraffic['tx_bytes'] ?? 0)),
        'uplink_rx' => openapFormatBytes((int) ($uplinkTraffic['rx_bytes'] ?? 0)),
        'uplink_label' => $isRepeater ? 'WiFi uplink' : 'Ethernet uplink',
        'uplink_name' => $uplinkName, 'uplink_interface' => $uplinkInterface,
        'uplink_ip' => function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($uplinkInterface) : '-',
        'uplink_signal' => $uplinkSignal,
        'cpu' => (int) $system->systemLoadPercentage(), 'memory' => (int) $system->usedMemory(),
        'disk' => (int) $system->usedDisk(), 'uptime' => $system->uptime(),
        'services' => $services, 'services_up' => count(array_filter($services)),
        'dhcp_active' => (int) ($dhcp['active'] ?? 0), 'dhcp_total' => (int) ($dhcp['total'] ?? 150),
        'dhcp_start' => (string) ($dhcp['range_start'] ?? '10.88.77.50'),
        'dhcp_end' => (string) ($dhcp['range_end'] ?? '10.88.77.200'),
    ];
}
