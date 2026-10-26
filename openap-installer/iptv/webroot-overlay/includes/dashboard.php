<?php

require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/user_preferences.php';

use OpenAP\System\Sysinfo;
use OpenAP\UI\Dashboard;
use OpenAP\Messages\StatusMessage;
use OpenAP\Plugins\PluginManager;
use OpenAP\Networking\Hotspot\WiFiManager;

/**
 * Returns the kernel byte counters for a network interface.
 *
 * @return array{rx_bytes:int,tx_bytes:int}
 */
function openapGetInterfaceTraffic(string $interface): array
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return [];
    }

    $statisticsPath = '/sys/class/net/' . $interface . '/statistics';
    $rxPath = $statisticsPath . '/rx_bytes';
    $txPath = $statisticsPath . '/tx_bytes';
    if (!is_readable($rxPath) || !is_readable($txPath)) {
        return [];
    }

    $rxBytes = trim((string) file_get_contents($rxPath));
    $txBytes = trim((string) file_get_contents($txPath));
    if (!ctype_digit($rxBytes) || !ctype_digit($txBytes)) {
        return [];
    }

    return [
        'rx_bytes' => (int) $rxBytes,
        'tx_bytes' => (int) $txBytes,
    ];
}

function openapGetInterfacesTraffic(array $interfaces): array
{
    $total = ['rx_bytes' => 0, 'tx_bytes' => 0];
    foreach (array_unique($interfaces) as $interface) {
        if (!is_string($interface)) {
            continue;
        }
        $traffic = openapGetInterfaceTraffic($interface);
        $total['rx_bytes'] += (int) ($traffic['rx_bytes'] ?? 0);
        $total['tx_bytes'] += (int) ($traffic['tx_bytes'] ?? 0);
    }
    return $total;
}

/** @return array{interface:string,band:string,channel:string,width:int,txpower:string,ssid:string,active:bool} */
function openapGetApRadioState(string $interface, string $band): array
{
    $state = [
        'interface' => $interface,
        'band' => $band,
        'channel' => '-',
        'width' => 0,
        'txpower' => '-',
        'ssid' => '-',
        'active' => false,
    ];
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return $state;
    }
    exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' info 2>/dev/null', $output, $returnCode);
    if ($returnCode !== 0) {
        return $state;
    }
    $info = implode("\n", $output);
    $state['active'] = preg_match('/^[[:space:]]*type[[:space:]]+AP$/m', $info) === 1;
    if (preg_match('/^[[:space:]]*ssid[[:space:]]+(.+)$/m', $info, $match)) {
        $state['ssid'] = trim($match[1]);
    }
    if (preg_match('/^[[:space:]]*channel[[:space:]]+([0-9]+).*width:[[:space:]]+([0-9]+)[[:space:]]+MHz/m', $info, $match)) {
        $state['channel'] = $match[1];
        $state['width'] = (int) $match[2];
    }
    if (preg_match('/^[[:space:]]*txpower[[:space:]]+([0-9.]+)[[:space:]]+dBm/m', $info, $match)) {
        $state['txpower'] = rtrim(rtrim($match[1], '0'), '.') . ' dBm';
    }
    return $state;
}

function openapFormatBytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    $precision = $unit === 0 ? 0 : ($value >= 100 ? 0 : 1);
    return number_format($value, $precision, '.', '') . ' ' . $units[$unit];
}

function openapGetInterfaceGateway(string $interface): string
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return '';
    }

    $output = [];
    $returnCode = 1;
    exec(
        '/usr/sbin/ip -4 route show default dev ' . escapeshellarg($interface) . ' 2>/dev/null',
        $output,
        $returnCode
    );
    if ($returnCode !== 0) {
        return '';
    }

    foreach ($output as $route) {
        if (preg_match('/(?:^|\s)via\s+([0-9.]+)(?:\s|$)/', $route, $matches)
            && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $matches[1];
        }
    }

    return '';
}

/**
 * Displays the dashboard
 */
function DisplayDashboard(string $template = 'dashboard'): void
{
    global $extraFooterScripts;
    $extraFooterScripts[] = [
        'src' => 'app/js/ui/dashboard.js?v=' . filemtime('app/js/ui/dashboard.js'),
        'defer' => false,
    ];
    $extraFooterScripts[] = [
        'src' => 'app/js/ui/hotspot_controls.js?v=' . filemtime('app/js/ui/hotspot_controls.js'),
        'defer' => false,
    ];
    if (in_array($template, ['dashboard', 'ap_configuration', 'dhcp_setting'], true)) {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/widget_layout.js?v=' . filemtime('app/js/ui/widget_layout.js'),
            'defer' => false,
        ];
    }
    if ($template === 'ap_configuration') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/ap_configuration.js?v=' . filemtime('app/js/ui/ap_configuration.js'),
            'defer' => false,
        ];
    } elseif ($template === 'dhcp_setting') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/dhcp_setting.js?v=' . filemtime('app/js/ui/dhcp_setting.js'),
            'defer' => false,
        ];
    } elseif ($template === 'logging') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/logging.js?v=' . filemtime('app/js/ui/logging.js'),
            'defer' => false,
        ];
    }
    // instantiate RaspAP objects
    $system = new Sysinfo();
    $dashboard = new Dashboard();
    $status = new StatusMessage();
    openapLoadDashboardFlashMessages($status);
    if ($template === 'dhcp_setting' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SaveDhcpSettings'])) {
        openapHandleDhcpSettings($status);
    }
    if ($template === 'dhcp_setting' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SaveDnsSettings'])) {
        $_SESSION['openap_encrypted_dns_result'] = openapHandleDnsSettings($status);
        header('Location: /dhcp_setting', true, 303);
        exit;
    }
    if (isset($_GET['dhcp_upstream'])) {
        $status->addMessage(_('DHCP settings are managed by the upstream router while Ethernet Bridge mode is active.'), 'info');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_action'])) {
        openapHandleDashboardAction((string) $_POST['dashboard_action'], $status);
        $returnTo = (string) ($_POST['return_to'] ?? '/');
        if (!in_array($returnTo, ['/', '/ap_configuration', '/dhcp_setting'], true)) {
            $returnTo = '/';
        }
        if ($returnTo === '/') {
            openapStoreDashboardFlashMessages($status);
        }
        header('Location: ' . $returnTo, true, 303);
        exit;
    }
    $pluginManager = PluginManager::getInstance();
    $wifi = new WiFiManager();

    $modeSwitchSettling = false;
    $modeSwitchMarker = '/run/openap/mode-switch';
    if (is_readable($modeSwitchMarker)) {
        $modeSwitchAt = (int) trim((string) @file_get_contents($modeSwitchMarker));
        $modeSwitchSettling = $modeSwitchAt > 0 && (time() - $modeSwitchAt) < 25;
    }
    // set AP and client interface session vars
    $wifi->getWifiInterface();

    $interface = $_SESSION['ap_interface'] ?? 'wlan0';
    $clientInterface = $_SESSION['wifi_client_interface'];
    $hostname = $system->hostname();
    $revision = $system->rpiRevision();
    $deviceImage = $dashboard->getDeviceImage($revision);
    $hostapd = $system->hostapdStatus();
    $adblock = $system->adBlockStatus();
    $vpn = $system->getActiveVpnInterface();
    $frequency = $dashboard->getFrequencyBand($interface);
    $apBand = $frequency === '5' ? '5 GHz' : ($frequency === '2.4' ? '2.4 GHz' : 'Auto');
    $details = $dashboard->getInterfaceDetails($interface);
    $wireless = $dashboard->getWirelessDetails($interface);
    $connectionInterface = $dashboard->getConnectionInterface();
    $connectionType = $dashboard->getConnectionType($connectionInterface);
    $connectionIcon = $dashboard->getConnectionIcon($connectionType);
    $publicDetails = $dashboard->getInterfaceDetails($connectionInterface);
    $publicWireless = $dashboard->getWirelessDetails($connectionInterface);
    $wirelessClients = $dashboard->getWirelessClients($interface);
    $ethernetClients = $dashboard->getEthernetClients();
    $totalClients = $wirelessClients + $ethernetClients;
    $plugins = $pluginManager->getInstalledPlugins();
    $bridgedEnable = getBridgedState();

    if ($bridgedEnable) {
        $interface = 'br0';
        $details = $dashboard->getInterfaceDetails($interface);
        $connectionType = 'ethernet';
    }
    
    $ipv4Address = $details['ipv4'];
    $ipv4Netmask = $details['ipv4_netmask'];
    $macAddress = $details['mac'];
    $ssid = $wireless['ssid'];
    $publicIpv4Address = $publicDetails['ipv4'];
    $publicIpv4Netmask = $publicDetails['ipv4_netmask'];
    $publicMacAddress = $publicDetails['mac'];
    $publicSsid = $publicWireless['ssid'];
    $ethernetActive = ($connectionType === 'ethernet') ? "active" : "inactive";
    $wirelessActive = ($connectionType === 'wireless') ? "active" : "inactive";
    $tetheringActive = ($connectionType === 'tethering') ? "active" : "inactive";
    $cellularActive = ($connectionType === 'cellular') ? "active" : "inactive";
    $bridgedStatus = ($bridgedEnable == 1) ? "active" : "";
    $hostapdStatus = ($hostapd[0] == 1) ?  "active" : "";
    $adblockStatus = ($adblock == true) ?  "active" : "";
    $wirelessClientActive = ($wirelessClients > 0) ? "active" : "inactive";
    $wirelessClientLabel = sprintf(
        _('%d WLAN %s'),
        $wirelessClients,
        $dashboard->formatClientLabel($wirelessClients)
    );
    $ethernetClientActive = ($ethernetClients > 0) ? "active" : "inactive";
    $ethernetClientLabel = sprintf(
        _('%d LAN %s'),
        $ethernetClients,
        $dashboard->formatClientLabel($ethernetClients)
    );
    $totalClientsActive = ($totalClients > 0) ? "active": "inactive";
    $freq5active = $freq24active = "";
    $varName = "freq" . str_replace('.', '', $frequency) . "active";
    $$varName = "active";
    $vpnStatus = $vpn ? "active" : "inactive";
    $vpnManaged = $vpn ? $dashboard->getVpnManaged($vpn) : null;
    $firewallManaged = $firewallStatus = "";
    $firewallInstalled = (bool) array_filter($plugins, function($p) {
        return substr($p, -strlen('Firewall')) === 'Firewall';
    });
    if (!$firewallInstalled) {
        $firewallUnavailable = '<i class="fas fa-slash fa-stack-2x"></i>';
    } else {
        $firewallManaged = '<a href="/plugin__Firewall">';
        $firewallStatus = ($dashboard->firewallEnabled() == true) ? "active" : "";
        $firewallUnavailable = null;
    }

    // ============================================================
    // New: additional data for enhanced dashboard
    // ============================================================

    // System metrics
    $cpuPercent   = $system->systemLoadPercentage();
    $memUsedPct   = $system->usedMemory();
    $diskUsedPct  = $system->usedDisk();
    $sysTemp      = $system->systemTemperature();
    $sysUptime    = $system->uptime();
    $sysUptimeStr = (string) $sysUptime;
    $loadAvg      = $system->loadAvg1Min();
    $memInfo      = @file_get_contents('/proc/meminfo');
    $memTotal     = 0;
    if ($memInfo && preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) {
        $memTotal = round((int)$m[1] / 1024);
    }

    $dashboardServiceLogs = openapDashboardServiceLogs();
    $serviceLogs = [];
    if ($template === 'logging') {
        $loggingServiceNames = ['hostapd', 'dnsmasq', 'lighttpd'];
        if (is_file('/etc/dnscrypt-proxy/dnscrypt-proxy.toml')) {
            $loggingServiceNames[] = 'dnscrypt-proxy';
        }
        foreach ($loggingServiceNames as $logService) {
            $serviceLogs[$logService] = openapReadServiceLog($logService);
        }
    }

    // Operating profile
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $currentMode = str_replace('-', '_', $profile['mode']['current'] ?? 'ap_ethernet');
    $isRepeaterWifi = ($currentMode === 'repeater_wifi');
    $wifiRoleUplinkIface = $profile['interfaces']['uplink'] ?? ($_SESSION['wifi_client_interface'] ?? 'wlan1');
    $configuredApMac = strtolower((string) ($profile['interfaces']['ap_mac'] ?? ''));
    $configuredUplinkMac = strtolower((string) ($profile['interfaces']['uplink_mac'] ?? ''));
    $uplinkIface = $isRepeaterWifi
        ? $wifiRoleUplinkIface
        : ($profile['interfaces']['ethernet'] ?? 'eth0');
    $uplinkGateway = openapGetInterfaceGateway($uplinkIface);
    $uplinkLabel = $isRepeaterWifi ? _('WiFi uplink') : _('Ethernet uplink');
    $uplinkKind = $isRepeaterWifi ? 'wireless' : 'ethernet';
    $repeaterWifiInterfaces = function_exists('openapDetectWirelessInterfaces') ? openapDetectWirelessInterfaces() : [];
    $repeaterRoles = function_exists('openapSelectedRepeaterRoles') ? openapSelectedRepeaterRoles($repeaterWifiInterfaces) : ['valid' => false];
    $repeaterModeAvailable = !empty($repeaterRoles['valid']);
    $repeaterModeUnavailableReason = _((string) ($repeaterRoles['reason'] ?? 'Repeater mode is unavailable.'));
    $ethernetModeAvailable = function_exists('openapCanConfigureApEthernet')
        ? openapCanConfigureApEthernet()
        : true;

    // DHCP pool info
    $dhcpPool = function_exists('openapDhcpPoolInfo') ? openapDhcpPoolInfo() : ['active' => 0, 'total' => 150];

    // Client list (AP side). Keep counters tied to OpenAP's real AP interface
    // so mode switches do not leave stale generic RaspAP client counts behind.
    $apIface = $profile['interfaces']['ap'] ?? $interface;
    $profileApInterfaces = [
        'ap_24ghz' => (string) ($profile['interfaces']['ap_24ghz'] ?? ''),
        'ap_5ghz' => (string) ($profile['interfaces']['ap_5ghz'] ?? ''),
    ];
    foreach ([
        'ap_24ghz' => '/etc/hostapd/openap-ap-24ghz.conf',
        'ap_5ghz' => '/etc/hostapd/openap-ap-5ghz.conf',
    ] as $profileKey => $radioConfig) {
        if ($profileApInterfaces[$profileKey] !== '' || !is_readable($radioConfig)) {
            continue;
        }
        $radioConfigContent = (string) file_get_contents($radioConfig);
        if (preg_match('/^interface=([A-Za-z0-9_.:-]+)$/m', $radioConfigContent, $radioInterfaceMatch)) {
            $profileApInterfaces[$profileKey] = $radioInterfaceMatch[1];
        }
    }
    $apInterfaces = array_values(array_unique(array_filter([
        $profileApInterfaces['ap_24ghz'],
        $profileApInterfaces['ap_5ghz'],
        $apIface,
    ], static fn($value): bool => is_string($value) && $value !== '')));
    $hotspotInterface = (string) ($profile['interfaces']['bridge'] ?? $profile['network']['bridge'] ?? $apIface);
    $apRadios = [];
    $roleSettings = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW) ?: [];
    foreach (['5' => 'ap_5ghz', '2.4' => 'ap_24ghz'] as $band => $profileKey) {
        $radioInterface = $profileApInterfaces[$profileKey];
        if ($radioInterface !== '') {
            $radioState = openapGetApRadioState($radioInterface, $band);
            $activeSection = $profileKey . '_active';
            $configuredWidth = (int) ($roleSettings[$activeSection]['width'] ?? 0);
            $radioState['configured_width'] = $configuredWidth > 0 ? $configuredWidth : $radioState['width'];
            $apRadios[] = $radioState;
        }
    }
    // Fresh single-band installations keep the AP in the legacy `interfaces.ap`
    // profile key.  Use it only when no band-specific interface was resolved, so
    // dual-band profiles remain authoritative and the same radio is not listed
    // twice.  Prefer the live channel when determining the legacy radio's band.
    if ($apRadios === [] && $apIface !== '') {
        $legacyBand = in_array((string) $frequency, ['5', '2.4'], true)
            ? (string) $frequency
            : '';
        $legacyRadioState = openapGetApRadioState($apIface, $legacyBand);
        $legacyChannel = (string) ($legacyRadioState['channel'] ?? '');
        if (ctype_digit($legacyChannel)) {
            $legacyBand = (int) $legacyChannel <= 14 ? '2.4' : '5';
        }
        if ($legacyBand !== '') {
            $legacyRadioState['band'] = $legacyBand;
            $legacyProfileKey = $legacyBand === '5' ? 'ap_5ghz' : 'ap_24ghz';
            $legacyActiveSection = $legacyProfileKey . '_active';
            $legacyConfiguredWidth = (int) ($roleSettings[$legacyActiveSection]['width'] ?? 0);
            $legacyRadioState['configured_width'] = $legacyConfiguredWidth > 0
                ? $legacyConfiguredWidth
                : $legacyRadioState['width'];
            $apRadios[] = $legacyRadioState;
        }
    }
    foreach ($apRadios as $radioState) {
        if ($radioState['active'] && $radioState['ssid'] !== '-') {
            $ssid = $radioState['ssid'];
            break;
        }
    }
    $activeApBands = array_values(array_unique(array_map(
        static fn(array $radioState): string => (string) $radioState['band'],
        array_filter($apRadios, static fn(array $radioState): bool => !empty($radioState['active']))
    )));
    if ($activeApBands === [] && $apIface !== '') {
        $fallbackApState = openapGetApRadioState($apIface, (string) $frequency);
        if (!empty($fallbackApState['active']) && in_array((string) $frequency, ['5', '2.4'], true)) {
            $activeApBands[] = (string) $frequency;
        }
    }
    $clientList = function_exists('openapGetClientListForInterfaces')
        ? openapGetClientListForInterfaces($apInterfaces, $hotspotInterface)
        : (function_exists('openapGetClientList') ? openapGetClientList($apIface) : []);
    if ($currentMode === 'ap_ethernet_bridge' && function_exists('getInterfaceNeighbors')) {
        $bridgeNeighbors = getInterfaceNeighbors($uplinkIface);
        foreach ($clientList as &$bridgeClient) {
            $bridgeMac = strtolower((string) ($bridgeClient['mac'] ?? ''));
            $bridgeClient['ip'] = $bridgeNeighbors[$bridgeMac]['ip'] ?? '-';
            $bridgeClient['state'] = $bridgeNeighbors[$bridgeMac]['state'] ?? '-';
        }
        unset($bridgeClient);
    }
    usort($clientList, static function (array $left, array $right): int { return ((int) ($right['traffic_bytes'] ?? 0)) <=> ((int) ($left['traffic_bytes'] ?? 0)); });    $topClientTraffic = 0;    foreach ($clientList as $trafficClient) { if (!empty($trafficClient['connected'])) { $topClientTraffic = max($topClientTraffic, (int) ($trafficClient['traffic_bytes'] ?? 0)); } }    foreach ($clientList as &$trafficClient) { $trafficClient['top_usage'] = $topClientTraffic > 0 && (int) ($trafficClient['traffic_bytes'] ?? 0) === $topClientTraffic; }    unset($trafficClient);
    $clientBreakdown = function_exists('openapGetClientBreakdownForList') ? openapGetClientBreakdownForList($clientList) : ['total' => 0, 'avg_signal' => 0, 'strong' => 0, 'medium' => 0, 'weak' => 0];
    $wirelessClients = 0;
    foreach ($clientList as $client) {
        if (!empty($client['connected'])) {
            $wirelessClients++;
        }
    }
    $ethernetClients = 0;
    $totalClients = $wirelessClients;
    $wirelessClientActive = ($wirelessClients > 0) ? "active" : "inactive";
    $wirelessClientLabel = sprintf(
        _('%d WLAN %s'),
        $wirelessClients,
        $dashboard->formatClientLabel($wirelessClients)
    );
    $ethernetClientActive = "inactive";
    $ethernetClientLabel = sprintf(
        _('%d LAN %s'),
        0,
        $dashboard->formatClientLabel(0)
    );
    $totalClientsActive = ($totalClients > 0) ? "active" : "inactive";

    // Interface traffic stats
    $trafficAp  = function_exists('openapGetInterfacesTraffic') ? openapGetInterfacesTraffic($apInterfaces) : [];
    $trafficUplink = function_exists('openapGetInterfaceTraffic') ? openapGetInterfaceTraffic($uplinkIface) : [];
    $trafficApRx = openapFormatBytes((int) ($trafficAp['rx_bytes'] ?? 0));
    $trafficApTx = openapFormatBytes((int) ($trafficAp['tx_bytes'] ?? 0));
    $trafficUplinkRx = openapFormatBytes((int) ($trafficUplink['rx_bytes'] ?? 0));
    $trafficUplinkTx = openapFormatBytes((int) ($trafficUplink['tx_bytes'] ?? 0));

    // Read repeater uplink details directly from nl80211. Avoid wpa_cli here:
    // with Ubuntu 26.04 sudo-rs under PHP-CGI it can hold requests for 10s.
    $uplinkDetails = [];
    $uplinkSignal = [];
    $uplinkHealth = $isRepeaterWifi && function_exists('openapUplinkHealth')
        ? openapUplinkHealth()
        : ['ready' => true, 'reason' => 'Ethernet uplink'];
    if ($isRepeaterWifi && preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkIface)) {
        $uplinkLinkOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkIface) . ' link 2>/dev/null', $uplinkLinkOutput, $uplinkLinkReturn);
        $uplinkLink = implode("\n", $uplinkLinkOutput);
        $uplinkIsConnected = !empty($uplinkHealth['ready']);
        if (preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $uplinkLink, $matches)) {
            $uplinkDetails['ssid'] = trim($matches[1]);
        }
        $uplinkDetails['wpa_state'] = $uplinkIsConnected ? 'COMPLETED' : 'DISCONNECTED';
        $uplinkDetails['ip_address'] = function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($uplinkIface) : '-';
        if (preg_match('/^[[:space:]]*freq:[[:space:]]*([0-9.]+)/m', $uplinkLink, $matches)) {
            $uplinkFrequency = (int) round((float) $matches[1]);
            $uplinkSignal['FREQUENCY'] = (string) $uplinkFrequency;
            $uplinkSignal['CHANNEL'] = function_exists('openapFrequencyChannel') ? openapFrequencyChannel($uplinkFrequency) : '-';
        }
        if (preg_match('/^[[:space:]]*signal:[[:space:]]*(-?[0-9.]+)[[:space:]]+dBm/m', $uplinkLink, $matches)) {
            $uplinkSignal['RSSI'] = (string) round((float) $matches[1]);
        }
    } elseif (!$isRepeaterWifi) {
        $uplinkDetails = [
            'ssid' => 'Ethernet',
            'wpa_state' => 'COMPLETED',
            'ip_address' => function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($uplinkIface) : ($publicIpv4Address ?? '-'),
        ];
    }
    $uplinkSsid = $isRepeaterWifi ? (string) ($uplinkDetails['ssid'] ?? '-') : 'Ethernet';

    // Service status array
    $serviceList = [
        'hostapd'  => function_exists('openapServiceActive') ? openapServiceActive('hostapd.service') === 'active' : false,
        'dnsmasq'  => function_exists('openapServiceActive') ? openapServiceActive('dnsmasq.service') === 'active' : false,
        'nftables' => function_exists('openapNatActive') ? openapNatActive() : (function_exists('openapServiceActive') ? openapServiceActive('nftables.service') === 'active' : false),
        'lighttpd' => function_exists('openapServiceActive') ? openapServiceActive('lighttpd.service') === 'active' : false,
        'dnscrypt' => function_exists('openapServiceActive') ? openapServiceActive('dnscrypt-proxy.service') === 'active' : false,
        'openvpn'  => $vpn !== null,
    ];
    if ($isRepeaterWifi) {
        $serviceList['wpa_supplicant'] = !empty($uplinkHealth['ready']);
    }

    // Band distribution (if we know total clients, estimate from frequency)
    $band5Count = count(array_filter($clientList, static fn(array $client): bool => !empty($client['connected']) && ($client['band'] ?? '') === '5'));
    $band24Count = count(array_filter($clientList, static fn(array $client): bool => !empty($client['connected']) && ($client['band'] ?? '') === '2.4'));

    // Determine available 5GHz channels from iw (filters out NO-IR channels)
    $available5ghzChannels = [];
    $channelTxPowerLimits = [];
    try {
        $parser = new \OpenAP\Parsers\IwParser($interface);
        $freqs = $parser->parseIwInfo();
        foreach ($freqs as $f) {
            $channelTxPowerLimits[(int) $f['Channel']] = max(1, min(30, (int) floor($f['dBm'])));
            if ($f['MHz'] >= 5000) {
                $available5ghzChannels[] = $f['Channel'];
            }
        }
    } catch (\Exception $e) {
        // fallback: only channels confirmed working with world reg domain (00)
        $available5ghzChannels = [36, 40, 44];
    }

    // Hostapd client details
    // Use the same systemd-backed status shown in the services panel. The
    // legacy pidof check can report a stale/false state in VM and sudo-rs
    // environments even while hostapd is serving the configured AP.
    // A hostapd process may survive USB passthrough loss. Treat the AP as
    // enabled only when its configured interface, MAC and gateway are live.
    $hostapdEnabled = (bool) ($serviceList['hostapd'] ?? false)
        && function_exists('openapApInterfaceReady')
        && openapApInterfaceReady();
    $apChannel = '';
    $apHwMode = 'n';
    $apSecurityType = 'WPA2-PSK';
    $apSecurityMode = '2';
    $apEncryption = 'CCMP';
    $apPsk = '';
    $apTxPower = '20 dBm';
    $apTxPowerMax = 30;
    $apCountry = 'IT';
    $apWmm = true;
    $apIsolation = false;
    $apIgnoreBroadcast = false;
    $apFreq = '';
    $apWidth = 20;
    $ap80211n = false;
    $ap80211ac = false;
    $apHtCapab = '';
    $apVhtOperChwidth = '';

    // Read hostapd config file for PSK, security, hw_mode
    $hostapdConfFile = defined('OPENAP_HOSTAPD_CONFIG')
        ? OPENAP_HOSTAPD_CONFIG
        : '/etc/hostapd/hostapd.conf';
    foreach (['/etc/hostapd/openap-ap-24ghz.conf', '/etc/hostapd/openap-ap-5ghz.conf'] as $dualHostapdConf) {
        if (is_readable($dualHostapdConf)) {
            $hostapdConfFile = $dualHostapdConf;
            break;
        }
    }
    if (is_readable($hostapdConfFile)) {
        $confContent = file_get_contents($hostapdConfFile);
        if (!empty($confContent)) {
            // Parse key hostapd settings
            $patterns = [
                'hw_mode' => '/^hw_mode=(.+)$/m',
                'wpa_passphrase' => '/^wpa_passphrase=(.+)$/m',
                'country_code' => '/^country_code=(.+)$/m',
                'wpa' => '/^wpa=(.+)$/m',
                'wpa_pairwise' => '/^wpa_pairwise=(.+)$/m',
                'rsn_pairwise' => '/^rsn_pairwise=(.+)$/m',
                'wmm_enabled' => '/^wmm_enabled=(.+)$/m',
                'ap_isolate' => '/^ap_isolate=(.+)$/m',
                'ignore_broadcast_ssid' => '/^ignore_broadcast_ssid=(.+)$/m',
                'ieee80211n' => '/^ieee80211n=(.+)$/m',
                'ieee80211ac' => '/^ieee80211ac=(.+)$/m',
                'ht_capab' => '/^ht_capab=(.+)$/m',
                'vht_oper_chwidth' => '/^vht_oper_chwidth=(.+)$/m',
            ];
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $confContent, $m)) {
                    $val = trim($m[1]);
                    switch ($key) {
                        case 'hw_mode': $apHwMode = $val; break;
                        case 'wpa_passphrase': $apPsk = $val; break;
                        case 'country_code': $apCountry = $val; break;
                        case 'wpa':
                            $apSecurityMode = $val;
                            if ($val == '1') $apSecurityType = 'WPA-PSK';
                            elseif ($val == '2') $apSecurityType = 'WPA2-PSK';
                            elseif ($val == '3') $apSecurityType = 'WPA3-SAE';
                            break;
                        case 'wpa_pairwise':
                        case 'rsn_pairwise':
                            if (!empty($val)) $apEncryption = $val;
                            break;
                        case 'wmm_enabled': $apWmm = ($val == '1'); break;
                        case 'ap_isolate': $apIsolation = ($val == '1'); break;
                        case 'ignore_broadcast_ssid': $apIgnoreBroadcast = ($val == '1'); break;
                        case 'ieee80211n': $ap80211n = ($val == '1'); break;
                        case 'ieee80211ac': $ap80211ac = ($val == '1'); break;
                        case 'ht_capab': $apHtCapab = $val; break;
                        case 'vht_oper_chwidth': $apVhtOperChwidth = $val; break;
                    }
                }
            }
            if (!preg_match('/^wpa=.+$/m', $confContent)) {
                $apSecurityMode = 'none';
                $apSecurityType = 'None (open network)';
                $apEncryption = 'None';
                $apPsk = '';
            }

            if ($ap80211ac) {
                $apHwMode = 'ac';
            } elseif ($ap80211n) {
                $apHwMode = 'n';
            }
            if ($apVhtOperChwidth === '1') {
                $apWidth = 80;
            } elseif (preg_match('/\[HT40[+-]\]/', $apHtCapab)) {
                $apWidth = 40;
            }
        }
    }

    // Prefer live radio values from nl80211. This remains available to the web
    // process without sudo and avoids losing channel details when hostapd_cli
    // cannot be executed from a particular FastCGI/sudo implementation.
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $apIface)) {
        $liveRadioOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($apIface) . ' info 2>/dev/null', $liveRadioOutput, $liveRadioReturn);
        if ($liveRadioReturn === 0) {
            $liveRadio = implode("\n", $liveRadioOutput);
            if (preg_match('/^[[:space:]]*channel[[:space:]]+([0-9]+)[[:space:]]+\(([0-9]+)[[:space:]]+MHz\),[[:space:]]+width:[[:space:]]+([0-9]+)[[:space:]]+MHz/m', $liveRadio, $matches)) {
                $apChannel = $matches[1];
                $apFreq = $matches[2];
                $apWidth = (int) $matches[3];
            }
            if (preg_match('/^[[:space:]]*txpower[[:space:]]+([0-9.]+)[[:space:]]+dBm/m', $liveRadio, $matches)) {
                $reportedTxPower = (float) $matches[1];
                $apTxPowerMax = $channelTxPowerLimits[(int) $apChannel] ?? 30;
                $apTxPower = min($reportedTxPower, (float) $apTxPowerMax) . ' dBm';
            }
        }
    }

    $interfaceRoleRadios = [];
    if (is_executable('/usr/local/sbin/openap-detect')) {
        $detected = function_exists('openapReadCachedDetectorReport')
            ? openapReadCachedDetectorReport()
            : null;
        if (is_array($detected)) {
            foreach (($detected['interfaces'] ?? []) as $radio) {
                $name = (string) ($radio['name'] ?? '');
                $mac = strtolower((string) ($radio['mac'] ?? ''));
                if (empty($radio['is_wireless'])
                    || !preg_match('/^[A-Za-z0-9_.:-]+$/', $name)
                    || !preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
                    continue;
                }
                $interfaceRoleRadios[] = [
                    'name' => $name,
                    'mac' => $mac,
                    'driver' => (string) ($radio['driver'] ?? 'unknown'),
                    'bus' => strtoupper((string) ($radio['bus'] ?? 'unknown')),
                    'bands' => implode(' / ', array_filter([
                        !empty($radio['supports_24ghz']) ? '2.4 GHz' : '',
                        !empty($radio['supports_5ghz']) ? '5 GHz' : '',
                    ])),
                    'supports_ap' => !empty($radio['supports_ap']),
                    'supports_managed' => !empty($radio['supports_managed']),
                ];
            }
        }
    }

    $dashboardWidgetOrder = openapReadDashboardWidgetOrder((string) ($_SESSION['user_id'] ?? ''));

    echo renderTemplate(
        $template, compact(
            // Original vars
            "revision",
            "deviceImage",
            "interface",
            "clientInterface",
            "bridgedStatus",
            "hostapdStatus",
            "adblockStatus",
            "vpnStatus",
            "vpnManaged",
            "firewallUnavailable",
            "firewallStatus",
            "firewallManaged",
            "ipv4Address",
            "ipv4Netmask",
            "macAddress",
            "ssid",
            "publicIpv4Address",
            "publicIpv4Netmask",
            "publicMacAddress",
            "publicSsid",
            "frequency",
            "freq5active",
            "freq24active",
            "wirelessClients",
            "wirelessClientLabel",
            "wirelessClientActive",
            "ethernetClients",
            "ethernetClientLabel",
            "ethernetClientActive",
            "totalClients",
            "totalClientsActive",
            "connectionType",
            "connectionIcon",
            "ethernetActive",
            "wirelessActive",
            "tetheringActive",
            "cellularActive",
            "status",
            // New vars
            "currentMode",
            "modeSwitchSettling",
            "isRepeaterWifi",
            "apBand",
            "uplinkSsid",
            "trafficApTx",
            "trafficApRx",
            "trafficUplinkTx",
            "trafficUplinkRx",
            "sysUptimeStr",
            "uplinkLabel",
            "uplinkKind",
            "repeaterModeAvailable",
            "repeaterModeUnavailableReason",
            "ethernetModeAvailable",
            "cpuPercent",
            "memUsedPct",
            "diskUsedPct",
            "sysTemp",
            "sysUptime",
            "loadAvg",
            "memTotal",
            "dhcpPool",
            "clientList",
            "clientBreakdown",
            "trafficAp",
            "trafficUplink",
            "uplinkDetails",
            "uplinkSignal",
            "uplinkHealth",
            "serviceList",
            "band5Count",
            "band24Count",
            "activeApBands",
            "hostapdEnabled",
            "apChannel",
            "apHwMode",
            "apSecurityType",
            "apSecurityMode",
            "apEncryption",
            "apPsk",
            "apTxPower",
            "apTxPowerMax",
            "channelTxPowerLimits",
            "apCountry",
            "apWmm",
            "apIsolation",
            "apIgnoreBroadcast",
            "apFreq",
            "apWidth",
            "available5ghzChannels",
            "apIface",
            "apInterfaces",
            "apRadios",
            "hotspotInterface",
            "uplinkIface",
            "wifiRoleUplinkIface",
            "configuredApMac",
            "configuredUplinkMac",
            "interfaceRoleRadios",
            "uplinkGateway",
            "dashboardServiceLogs",
            "serviceLogs",
            "dashboardWidgetOrder"
        )
    );
}

function openapHandleDhcpSettings(StatusMessage $status): void
{
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    if (($profile['mode']['current'] ?? '') === 'ap_ethernet_bridge') {
        $status->addMessage('DHCP settings are read-only in Ethernet Bridge mode.', 'danger');
        return;
    }
    $values = [
        'subnet' => trim((string) ($_POST['dhcp_subnet'] ?? '')),
        'gateway' => trim((string) ($_POST['dhcp_gateway'] ?? '')),
        'start' => trim((string) ($_POST['dhcp_start'] ?? '')),
        'end' => trim((string) ($_POST['dhcp_end'] ?? '')),
        'lease' => trim((string) ($_POST['dhcp_lease_time'] ?? '')),
        // DNS is preserved by the privileged helper from the active dnsmasq
        // configuration. These valid placeholders are never authoritative.
        'policy' => 'local',
        'advertised' => trim((string) ($_POST['dhcp_gateway'] ?? '')),
        'upstream' => trim((string) ($_POST['dhcp_gateway'] ?? '')),
    ];
    if (!preg_match('/^([0-9.]+)\/(\d{1,2})$/', $values['subnet'], $subnetMatch)
        || filter_var($subnetMatch[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || (int) $subnetMatch[2] !== 24) {
        $status->addMessage('Invalid hotspot subnet. OpenAP DHCP uses a fixed /24 network.', 'danger');
        return;
    }
    $prefix = (int) $subnetMatch[2];
    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    $network = ip2long($subnetMatch[1]);
    if (($network & $mask) !== $network) {
        $status->addMessage('The subnet must use its network address.', 'danger');
        return;
    }
    $apInterface = (string) ($profile['interfaces']['bridge'] ?? $profile['interfaces']['ap'] ?? '');
    $uplinkInterface = (string) ($profile['interfaces']['uplink'] ?? '');
    $addressOutput = [];
    exec('/usr/sbin/ip -4 -o address show scope global 2>/dev/null', $addressOutput);
    foreach ($addressOutput as $addressLine) {
        if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+([0-9.]+)\/(\d{1,2})\b/', $addressLine, $addressMatch)) {
            continue;
        }
        $interface = rtrim($addressMatch[1], ':');
        if ($interface === $apInterface) {
            continue;
        }
        $address = ip2long($addressMatch[2]);
        $interfacePrefix = (int) $addressMatch[3];
        if ($address === false || $interfacePrefix < 0 || $interfacePrefix > 32) {
            continue;
        }
        $interfaceMask = $interfacePrefix === 0
            ? 0
            : ((0xffffffff << (32 - $interfacePrefix)) & 0xffffffff);
        $interfaceNetwork = $address & $interfaceMask;
        $interfaceBroadcast = $interfaceNetwork | (~$interfaceMask & 0xffffffff);
        $requestedBroadcast = $network | (~$mask & 0xffffffff);
        if ($network <= $interfaceBroadcast && $interfaceNetwork <= $requestedBroadcast) {
            $role = $interface === $uplinkInterface ? 'WiFi uplink' : 'interface';
            $status->addMessage(
                sprintf(
                    'Hotspot subnet %s overlaps the %s %s (%s/%d). Choose a different /24 network so client and uplink routes remain unambiguous.',
                    $values['subnet'],
                    $role,
                    $interface,
                    $addressMatch[2],
                    $interfacePrefix
                ),
                'danger'
            );
            return;
        }
    }
    if (ip2long($values['gateway']) !== $network + 1) {
        $status->addMessage('The gateway must be the first usable address in the /24 subnet.', 'danger');
        return;
    }
    $ips = [];
    foreach (['gateway', 'start', 'end'] as $key) {
        if (filter_var($values[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $status->addMessage('Gateway and DHCP range must contain valid IPv4 addresses.', 'danger');
            return;
        }
        $ips[$key] = ip2long($values[$key]);
        if (($ips[$key] & $mask) !== $network || $ips[$key] === $network || $ips[$key] === ($network | (~$mask & 0xffffffff))) {
            $status->addMessage('Gateway and DHCP range must be usable addresses in the selected subnet.', 'danger');
            return;
        }
    }
    if ($ips['start'] > $ips['end'] || ($ips['gateway'] >= $ips['start'] && $ips['gateway'] <= $ips['end'])) {
        $status->addMessage('The DHCP range is invalid or contains the gateway address.', 'danger');
        return;
    }
    if (!preg_match('/^[1-9][0-9]*[mhdw]$/i', $values['lease'])) {
        $status->addMessage('Invalid lease time. Examples: 30m, 12h, 7d.', 'danger');
        return;
    }
    $encryptedDnsState = is_readable('/etc/openap/encrypted-dns.ini')
        ? parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_TYPED)
        : [];
    if (!empty($encryptedDnsState['encrypted_dns']['enabled'])) {
        $values['policy'] = 'local';
        $values['advertised'] = $values['gateway'];
        $values['upstream'] = '127.0.2.1';
    }
    if (!in_array($values['policy'], ['local', 'external'], true)) {
        $status->addMessage('Invalid DNS policy.', 'danger');
        return;
    }
    foreach (['advertised', 'upstream'] as $key) {
        $dnsAddresses = array_filter(array_map('trim', explode(',', $values[$key])));
        if ($dnsAddresses === [] || array_filter($dnsAddresses, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            $status->addMessage('DNS fields must contain comma-separated IPv4 addresses.', 'danger');
            return;
        }
        $values[$key] = implode(',', $dnsAddresses);
    }
    $command = 'sudo /usr/local/sbin/openap-apply-dhcp-settings --delayed --preserve-dns';
    foreach ($values as $value) {
        $command .= ' ' . escapeshellarg($value);
    }
    exec($command . ' 2>&1', $output, $return);
    if ($return !== 0) {
        $status->addMessage('Unable to apply DHCP settings: ' . implode(' ', $output), 'danger');
        return;
    }
    $status->addMessage('DHCP settings saved successfully.', 'success');
}

function openapHandleEncryptedDns(StatusMessage $status): array
{
    $enabled = (string) ($_POST['encrypted_dns_enabled'] ?? '0');
    $provider = trim((string) ($_POST['encrypted_dns_provider'] ?? ''));
    if (!in_array($enabled, ['0', '1'], true)) {
        return ['success' => false, 'message' => _('Invalid Encrypted DNS state.')];
    }
    if (!in_array($provider, ['cloudflare', 'quad9'], true)) {
        return ['success' => false, 'message' => _('Unsupported Encrypted DNS provider.')];
    }

    $mode = $enabled === '1' ? 'enable' : 'disable';
    $command = 'sudo /usr/local/sbin/openap-apply-encrypted-dns '
        . escapeshellarg($mode) . ' ' . escapeshellarg($provider);
    exec($command . ' 2>&1', $output, $return);
    if ($return !== 0) {
        return [
            'success' => false,
            'message' => _('Unable to apply Encrypted DNS settings: ') . implode(' ', $output),
        ];
    }

    return [
        'success' => true,
        'message' => $enabled === '1'
            ? _('Encrypted DNS enabled successfully.')
            : _('Encrypted DNS disabled; direct public DNS restored.'),
    ];
}

function openapHandleDnsSettings(StatusMessage $status): array
{
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    if (($profile['mode']['current'] ?? '') === 'ap_ethernet_bridge') {
        return ['success' => false, 'message' => _('DNS settings are read-only in Ethernet Bridge mode.')];
    }

    $enabled = (string) ($_POST['encrypted_dns_enabled'] ?? '0');
    $provider = trim((string) ($_POST['dns_provider'] ?? ''));
    $policy = trim((string) ($_POST['dhcp_dns_policy'] ?? 'local'));
    if (!in_array($enabled, ['0', '1'], true)
        || !in_array($provider, ['cloudflare', 'quad9', 'google', 'custom'], true)
        || !in_array($policy, ['local', 'external'], true)) {
        return ['success' => false, 'message' => _('Invalid DNS settings.')];
    }
    if ($enabled === '1' && !in_array($provider, ['cloudflare', 'quad9'], true)) {
        return ['success' => false, 'message' => _('Encrypted DNS supports Cloudflare or Quad9. Choose one of these providers.')];
    }

    $providerAddresses = [
        'cloudflare' => '1.1.1.1,1.0.0.1',
        'quad9' => '9.9.9.9,149.112.112.112',
        'google' => '8.8.8.8,8.8.4.4',
    ];
    if ($enabled === '1') {
        $command = 'sudo /usr/local/sbin/openap-apply-encrypted-dns enable ' . escapeshellarg($provider);
        exec($command . ' 2>&1', $output, $return);
        if ($return !== 0) {
            return ['success' => false, 'message' => _('Unable to apply Encrypted DNS settings: ') . implode(' ', $output)];
        }
        return ['success' => true, 'message' => sprintf(_('Encrypted DNS enabled with %s.'), $provider === 'quad9' ? 'Quad9 Security' : 'Cloudflare')];
    }

    $upstream = $providerAddresses[$provider] ?? trim((string) ($_POST['dhcp_upstream_dns'] ?? ''));
    $advertised = trim((string) ($_POST['dhcp_advertised_dns'] ?? ''));
    $dnsLists = [];
    foreach (['upstream' => $upstream, 'advertised' => $advertised] as $key => $value) {
        $addresses = array_values(array_filter(array_map('trim', explode(',', $value))));
        if ($addresses === [] || array_filter($addresses, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            return ['success' => false, 'message' => _('DNS fields must contain comma-separated IPv4 addresses.')];
        }
        $dnsLists[$key] = implode(',', $addresses);
    }

    $gateway = (string) ($profile['network']['gateway'] ?? '');
    if ($policy === 'local') {
        $dnsLists['advertised'] = $gateway;
    } elseif ($provider !== 'custom') {
        $dnsLists['advertised'] = $upstream;
    }

    $storedDns = is_readable('/etc/openap/encrypted-dns.ini')
        ? parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_RAW)
        : [];
    $disableProvider = in_array($provider, ['cloudflare', 'quad9'], true)
        ? $provider
        : (in_array(($storedDns['encrypted_dns']['provider'] ?? ''), ['cloudflare', 'quad9'], true)
            ? (string) $storedDns['encrypted_dns']['provider']
            : 'cloudflare');
    exec(
        'sudo /usr/local/sbin/openap-apply-encrypted-dns disable ' . escapeshellarg($disableProvider) . ' 2>&1',
        $disableOutput,
        $disableReturn
    );
    if ($disableReturn !== 0) {
        return ['success' => false, 'message' => _('Unable to disable Encrypted DNS: ') . implode(' ', $disableOutput)];
    }

    $lease = '12h';
    foreach (@file('/etc/dnsmasq.d/openap-repeater.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'dhcp-range=')) {
            $parts = array_map('trim', explode(',', substr($line, 11)));
            $candidate = end($parts);
            if (is_string($candidate) && preg_match('/^[1-9][0-9]*[mhdw]$/i', $candidate)) {
                $lease = $candidate;
            }
            break;
        }
    }
    $network = $profile['network'] ?? [];
    $values = [
        (string) ($network['subnet'] ?? ''),
        $gateway,
        (string) ($network['dhcp_start'] ?? ''),
        (string) ($network['dhcp_end'] ?? ''),
        $lease,
        $policy,
        $dnsLists['advertised'],
        $dnsLists['upstream'],
    ];
    $command = 'sudo /usr/local/sbin/openap-apply-dhcp-settings';
    foreach ($values as $value) {
        $command .= ' ' . escapeshellarg($value);
    }
    exec($command . ' 2>&1', $output, $return);
    if ($return !== 0) {
        return ['success' => false, 'message' => _('Unable to apply standard DNS settings: ') . implode(' ', $output)];
    }

    $label = $provider === 'quad9' ? 'Quad9 Security' : ($provider === 'cloudflare' ? 'Cloudflare' : ($provider === 'google' ? 'Google Public DNS' : _('Custom')));
    return ['success' => true, 'message' => sprintf(_('Standard DNS enabled with %s.'), $label)];
}



function openapLoadDashboardFlashMessages(StatusMessage $status): void
{
    if (empty($_SESSION['openap_dashboard_flash']) || !is_array($_SESSION['openap_dashboard_flash'])) {
        return;
    }
    foreach ($_SESSION['openap_dashboard_flash'] as $message) {
        if (is_string($message) && $message !== '') {
            $status->messages[] = $message;
        }
    }
    unset($_SESSION['openap_dashboard_flash']);
}

function openapStoreDashboardFlashMessages(StatusMessage $status): void
{
    $_SESSION['openap_dashboard_flash'] = $status->messages;
}

function openapHandleDashboardAction(string $action, StatusMessage $status): void
{
    $profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
    $mode = is_array($profile) ? (string) ($profile['mode']['current'] ?? 'ap_ethernet') : 'ap_ethernet';
    $hotspotUnits = $mode === 'ap_ethernet_bridge'
        ? 'hostapd.service'
        : 'hostapd.service dnsmasq.service';
    $commands = [
        'start_ap' => [
            'label' => 'AP service',
            'verb' => 'Started',
            'cmd' => 'sudo /bin/systemctl start ' . $hotspotUnits,
            'wait' => 'ap',
        ],
        'stop_ap' => [
            'label' => 'AP service',
            'verb' => 'Stopped',
            'cmd' => 'sudo /bin/systemctl stop hostapd.service',
            'wait' => '',
        ],
        'restart_ap' => [
            'label' => 'AP service',
            'verb' => 'Restarted',
            'cmd' => 'sudo /bin/systemctl restart ' . $hotspotUnits,
            'wait' => 'ap',
        ],
        'restart_dhcp' => [
            'label' => 'DHCP/DNS service',
            'verb' => 'Restarted',
            'cmd' => 'sudo /bin/systemctl restart dnsmasq.service',
            'wait' => 'dhcp',
        ],
    ];

    if (!isset($commands[$action])) {
        $status->addMessage('Unknown dashboard action.', 'danger');
        return;
    }

    exec($commands[$action]['cmd'] . ' 2>&1', $output, $return);
    if ($return !== 0) {
        $status->addMessage('Failed to update ' . $commands[$action]['label'] . ': ' . implode(' ', $output), 'danger');
        return;
    }

    $ready = true;
    if ($commands[$action]['wait'] === 'ap' && function_exists('openapWaitAfterRepeaterAction')) {
        $ready = openapWaitAfterRepeaterAction('restart_ap');
    } elseif ($commands[$action]['wait'] === 'dhcp' && function_exists('openapWaitAfterRepeaterAction')) {
        $ready = openapWaitAfterRepeaterAction('restart_dhcp');
    }

    $status->addMessage($commands[$action]['verb'] . ' ' . $commands[$action]['label'] . '.', 'success');
    if (!$ready) {
        $status->addMessage('Service restarted, but status is still settling. Refresh the page in a few seconds.', 'warning');
    }
}

function openapDashboardServiceLogs(): string
{
    $command = 'sudo /bin/journalctl -u hostapd.service -u dnsmasq.service -n 120 --no-pager --output=short-iso 2>&1';
    exec($command, $output, $return);
    if ($return !== 0) {
        return 'Unable to read service logs: ' . implode("\n", $output);
    }
    return trim(implode("\n", $output));
}

function openapReadServiceLog(string $service): string
{
    $allowed = ['hostapd', 'dnsmasq', 'lighttpd', 'dnscrypt-proxy'];
    if (!in_array($service, $allowed, true)) {
        return _('Invalid service.');
    }
    $command = 'sudo /usr/bin/journalctl -u ' . escapeshellarg($service . '.service')
        . ' -n 240 --no-pager --output=short-iso 2>&1';
    exec($command, $output, $return);
    $log = trim(implode("\n", $output));
    if ($return !== 0) {
        return _('Unable to read this service log.') . ($log !== '' ? "\n" . $log : '');
    }
    return $log !== '' ? $log : _('No recent log entries.');
}

/**
 * Renders a URL for an svg solid line representing the associated
 * connection type
 *
 * @param string $connectionType
 * @return string
 */
function renderConnection(string $connectionType): string
{
    $deviceMap = [
        'ethernet'  => 'device-1',
        'wireless'  => 'device-2',
        'tethering' => 'device-3',
        'cellular'  => 'device-4'
    ];
    if (!isset($deviceMap[$connectionType])) {
        return 'app/img/solid.php';
    }

    return sprintf('app/img/solid.php?joint&%s&out', $deviceMap[$connectionType]);
}

/**
 * Renders a URL for an svg solid line representing associated
 * client connection(s)
 *
 * @param int $wirelessClients
 * @param int $ethernetClients
 * @return string
 */
function renderClientConnections(int $wirelessClients, int $ethernetClients): string
{
    $devices = [];

    if ($wirelessClients > 0) {
        $devices[] = 'device-1&out';
    }
    if ($ethernetClients > 0) {
        $devices[] = 'device-2&out';
    }
    return empty($devices) ? '' : sprintf(
        '<img src="app/img/right-solid.php?%s" class="solid-lines solid-lines-right" alt="Client connections">',
        implode('&', $devices)
    );
}
