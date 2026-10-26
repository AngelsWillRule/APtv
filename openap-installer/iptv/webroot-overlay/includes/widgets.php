<?php

/**
 * Self-contained OpenAP widget data collector + renderer.
 *
 * The dashboard widget area normally reads its data from page-scope variables
 * computed by DisplayDashboard(). For pages that do NOT route through
 * DisplayDashboard (clients, system, hostapd, about, ...), openapWidgetData()
 * computes the same data from the globally-available OpenAP helpers so the
 * widget area can be embedded on any page.
 *
 * The helpers below are loaded on every request (index.php requires
 * dashboard.php, clients.php, repeater.php globally), so this is safe to call
 * from any template. Every value is computed defensively with a fallback so a
 * failure here can never break a configuration page.
 */

function openapWidgetData(): array
{
    // --- Operating profile / mode ---
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $currentMode = str_replace('-', '_', $profile['mode']['current'] ?? 'ap_ethernet');
    $isRepeaterWifi = ($currentMode === 'repeater_wifi');

    // --- Interfaces ---
    $interface = (string) ($_SESSION['ap_interface'] ?? 'wlan0');
    $apIface = (string) ($profile['interfaces']['ap'] ?? $interface);
    $ap24 = (string) ($profile['interfaces']['ap_24ghz'] ?? '');
    $ap5 = (string) ($profile['interfaces']['ap_5ghz'] ?? '');
    $apInterfaces = array_values(array_unique(array_filter([$ap24, $ap5, $apIface], static fn($v): bool => is_string($v) && $v !== '')));
    $hotspotInterface = (string) ($profile['interfaces']['bridge'] ?? $profile['network']['bridge'] ?? $apIface);

    $uplinkIface = $isRepeaterWifi
        ? (string) ($profile['interfaces']['uplink'] ?? ($_SESSION['wifi_client_interface'] ?? 'wlan1'))
        : (string) ($profile['interfaces']['ethernet'] ?? 'eth0');
    $uplinkGateway = function_exists('openapGetInterfaceGateway') ? openapGetInterfaceGateway($uplinkIface) : '-';

    // --- System health ---
    $cpuPercent = 0;
    $memUsedPct = 0;
    $diskUsedPct = 0;
    $sysTemp = '-';
    $sysUptimeStr = '-';
    $loadAvg = '-';
    try {
        $system = new \OpenAP\System\Sysinfo();
        $cpuPercent = (float) $system->systemLoadPercentage();
        $memUsedPct = (float) $system->usedMemory();
        $diskUsedPct = (float) $system->usedDisk();
        $sysTemp = (string) $system->systemTemperature();
        $sysUptimeStr = (string) $system->uptime();
        $loadAvg = (string) $system->loadAvg1Min();
    } catch (Throwable $e) {
        error_log('openapWidgetData: Sysinfo failed: ' . $e->getMessage());
    }

    // --- AP band ---
    $apBand = 'Auto';
    $apSsid = '-';
    $activeApBands = [];
    $dashboard = null;
    try {
        $dashboard = new \OpenAP\UI\Dashboard();
        $frequency = (string) $dashboard->getFrequencyBand($apIface);
        $apBand = $frequency === '5' ? '5 GHz' : ($frequency === '2.4' ? '2.4 GHz' : 'Auto');
        foreach ([['interface' => $ap5, 'band' => '5'], ['interface' => $ap24, 'band' => '2.4'], ['interface' => $apIface, 'band' => $frequency]] as $apRadio) {
            if ($apRadio['interface'] === '' || !function_exists('openapGetApRadioState')) {
                continue;
            }
            $apRadioState = openapGetApRadioState($apRadio['interface'], $apRadio['band']);
            if (!empty($apRadioState['active']) && in_array($apRadio['band'], ['5', '2.4'], true)) {
                $activeApBands[] = $apRadio['band'];
            }
            if (!empty($apRadioState['active']) && ($apRadioState['ssid'] ?? '-') !== '-') {
                $apSsid = (string) $apRadioState['ssid'];
            }
        }
        $activeApBands = array_values(array_unique($activeApBands));
        if ($apSsid === '-' && function_exists('openapHostapdConfigValue')) {
            $configuredSsid = trim((string) openapHostapdConfigValue('ssid'));
            if ($configuredSsid !== '') {
                $apSsid = $configuredSsid;
            }
        }
    } catch (Throwable $e) {
        error_log('openapWidgetData: AP band failed: ' . $e->getMessage());
    }

    // --- Public uplink IP ---
    $publicIpv4Address = '-';
    try {
        $dashboard = $dashboard ?? new \OpenAP\UI\Dashboard();
        $connectionInterface = $dashboard->getConnectionInterface();
        $publicDetails = $dashboard->getInterfaceDetails($connectionInterface);
        $publicIpv4Address = (string) ($publicDetails['ipv4'] ?? '-');
    } catch (Throwable $e) {
        error_log('openapWidgetData: public IP failed: ' . $e->getMessage());
    }

    // --- DHCP pool ---
    $dhcpPool = function_exists('openapDhcpPoolInfo') ? openapDhcpPoolInfo() : ['active' => 0, 'total' => 150];

    // --- Clients ---
    $clientList = [];
    try {
        $candidate = function_exists('openapGetClientListForInterfaces')
            ? openapGetClientListForInterfaces($apInterfaces, $hotspotInterface)
            : (function_exists('openapGetClientList') ? openapGetClientList($apIface) : []);
        if (is_array($candidate)) {
            $clientList = $candidate;
        }
    } catch (Throwable $e) {
        error_log('openapWidgetData: client list failed: ' . $e->getMessage());
    }
    $totalClients = 0;
    foreach ($clientList as $client) {
        if (!empty($client['connected'])) {
            $totalClients++;
        }
    }
    $clientBreakdown = function_exists('openapGetClientBreakdownForList')
        ? openapGetClientBreakdownForList($clientList)
        : ['total' => 0, 'avg_signal' => 0, 'strong' => 0, 'medium' => 0, 'weak' => 0];
    if (!is_array($clientBreakdown)) {
        $clientBreakdown = ['total' => 0, 'avg_signal' => 0, 'strong' => 0, 'medium' => 0, 'weak' => 0];
    }
    $band5Count = count(array_filter($clientList, static fn(array $client): bool => !empty($client['connected']) && ($client['band'] ?? '') === '5'));
    $band24Count = count(array_filter($clientList, static fn(array $client): bool => !empty($client['connected']) && ($client['band'] ?? '') === '2.4'));

    // --- Traffic ---
    $trafficAp = [];
    $trafficUplink = [];
    try {
        $apTraffic = function_exists('openapGetInterfacesTraffic') ? openapGetInterfacesTraffic($apInterfaces) : [];
        if (is_array($apTraffic)) {
            $trafficAp = $apTraffic;
        }
        $uplinkTraffic = function_exists('openapGetInterfaceTraffic') ? openapGetInterfaceTraffic($uplinkIface) : [];
        if (is_array($uplinkTraffic)) {
            $trafficUplink = $uplinkTraffic;
        }
    } catch (Throwable $e) {
        error_log('openapWidgetData: traffic failed: ' . $e->getMessage());
    }
    $fmt = static function (array $stats, string $key): string {
        if (!isset($stats[$key])) {
            return '0 B';
        }
        return function_exists('openapFormatBytes')
            ? openapFormatBytes((int) $stats[$key])
            : ((string) $stats[$key]);
    };
    $trafficApRx = $fmt($trafficAp, 'rx_bytes');
    $trafficApTx = $fmt($trafficAp, 'tx_bytes');
    $trafficUplinkRx = $fmt($trafficUplink, 'rx_bytes');
    $trafficUplinkTx = $fmt($trafficUplink, 'tx_bytes');

    // --- Uplink (WiFi) details ---
    $uplinkSsid = $isRepeaterWifi ? (string) ($profile['interfaces']['uplink'] ?? '') : 'Ethernet';
    $uplinkConnected = true;
    if ($isRepeaterWifi) {
        $uplinkHealth = function_exists('openapUplinkHealth') ? openapUplinkHealth() : [];
        $uplinkConnected = !empty($uplinkHealth['ready']);
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkIface)) {
            $link = [];
            exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkIface) . ' link 2>/dev/null', $link, $rc);
            $linkOut = implode("\n", $link);
            if (preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $linkOut, $m)) {
                $uplinkSsid = trim($m[1]);
            }
        }
    }

    // --- Services (the service-status partial is self-contained, but provide a base) ---
    $serviceList = [
        'hostapd'  => function_exists('openapServiceActive') ? openapServiceActive('hostapd.service') === 'active' : false,
        'dnsmasq'  => function_exists('openapServiceActive') ? openapServiceActive('dnsmasq.service') === 'active' : false,
        'nftables' => function_exists('openapNatActive') ? openapNatActive() : (function_exists('openapServiceActive') ? openapServiceActive('nftables.service') === 'active' : false),
        'lighttpd' => function_exists('openapServiceActive') ? openapServiceActive('lighttpd.service') === 'active' : false,
        'dnscrypt' => function_exists('openapServiceActive') ? openapServiceActive('dnscrypt-proxy.service') === 'active' : false,
    ];

    return compact(
        'interface',
        'apIface',
        'apInterfaces',
        'currentMode',
        'isRepeaterWifi',
        'apBand',
        'apSsid',
        'activeApBands',
        'uplinkIface',
        'uplinkSsid',
        'uplinkGateway',
        'publicIpv4Address',
        'uplinkConnected',
        'totalClients',
        'clientBreakdown',
        'band5Count',
        'band24Count',
        'trafficApTx',
        'trafficApRx',
        'trafficUplinkTx',
        'trafficUplinkRx',
        'cpuPercent',
        'memUsedPct',
        'sysTemp',
        'sysUptimeStr',
        'diskUsedPct',
        'loadAvg',
        'dhcpPool',
        'serviceList'
    );
}

/**
 * Render the widget area (right sidebar column) for a given page, computing
 * its own data. Safe to echo from any template.
 *
 * The returned HTML is the `<div class="col-xl-3 col-lg-4">…</div>` sidebar
 * column produced by the shared partial (templates/openap_widget_area.php).
 */
function openapWidgetArea(string $page): string
{
    $username = (string) ($_SESSION['user_id'] ?? '');
    if ($username === '') {
        return '';
    }

    // Register the shared JS once. Runs before loadFooterScripts() in
    // index.php (page render -> line 138, footer scripts -> line 163), and
    // $extraFooterScripts is a single global populated by page_actions.php.
    global $extraFooterScripts;
    if (is_array($extraFooterScripts)) {
        $registered = false;
        foreach ($extraFooterScripts as $existing) {
            if (isset($existing['src']) && strpos((string) $existing['src'], 'widget_layout.js') !== false) {
                $registered = true;
                break;
            }
        }
        if (!$registered) {
            $extraFooterScripts[] = [
                'src' => 'app/js/ui/widget_layout.js?v=' . filemtime('app/js/ui/widget_layout.js'),
                'defer' => false,
            ];
        }
    }

    $openapWidgetLayout = openapWidgetLayoutRead($username, $page);
    $openapWidgetOrder = $openapWidgetLayout['order'];
    $openapWidgetHidden = $openapWidgetLayout['hidden'];
    $openapWidgetWidths = $openapWidgetLayout['widths'];
    $openapWidgetPositions = array_flip($openapWidgetOrder);
    $openapWidgetPage = $page;

    extract(openapWidgetData(), EXTR_SKIP);

    ob_start();
    include __DIR__ . '/../templates/openap_widget_area.php';
    return ob_get_clean();
}
