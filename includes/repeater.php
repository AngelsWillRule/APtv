<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wifi_roles.php';

use OpenAP\Messages\StatusMessage;

function DisplayRepeater()
{
    if (!openapRepeaterActive()) {
        DisplayDashboard();
        return;
    }

    $status = new StatusMessage();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repeater_action'])) {
        openapHandleRepeaterAction((string) $_POST['repeater_action'], $status);
    }

    $roles = openapCurrentRepeaterInterfaces();
    $apInterface = $roles['ap'];
    $uplinkInterface = $roles['uplink'];
    $summary = openapGetRepeaterSummary($apInterface, $uplinkInterface);

    echo renderTemplate("repeater", compact("status", "summary"));
}

function DisplayApWizard(array $options = [])
{
    $isEmbedded = !empty($options['embedded']);
    $status = new StatusMessage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ap_action'] ?? '') === 'configure_ap_ethernet') {
        openapHandleApEthernetAction($status);
    }

    $ethernet = openapDetectEthernetInterfaces();
    $wireless = openapDetectWirelessInterfaces();
    $activeProfile = openapReadRepeaterProfile();
    $selectedEthernet = openapEffectiveEthernet($ethernet);
    if (($activeProfile['mode']['current'] ?? '') === 'ap_ethernet_bridge') {
        $physicalEthernet = (string) ($activeProfile['interfaces']['ethernet_physical'] ?? '');
        foreach ($ethernet as $candidate) {
            if ($candidate['name'] === $physicalEthernet) {
                $selectedEthernet = $candidate;
                break;
            }
        }
    }
    $summary = [
        'ready' => openapCanConfigureApEthernet(),
        'ethernet' => $ethernet,
        'wireless' => $wireless,
        'selected_ethernet' => $selectedEthernet,
        'configured_ethernet' => openapConfiguredEthernetName(),
        'selected_ap' => openapSelectedApInterface($wireless),
        'gateway' => openapEthernetGatewaySuggestion($ethernet),
        'profile' => $activeProfile,
    ];

    echo renderTemplate("wizard_ap_ethernet", compact("status", "summary", "isEmbedded"));
}

function DisplayRepeaterWizard()
{
    $status = new StatusMessage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['repeater_action'] ?? '') === 'configure_repeater_wifi') {
        openapHandleRepeaterWifiAction($status);
    }

    $wireless = openapDetectWirelessInterfaces();
    $roles = openapSelectedRepeaterRoles($wireless);
    $summary = [
        'ready' => openapCanConfigureRepeater(),
        'wireless' => $wireless,
        'roles' => $roles,
    ];

    echo renderTemplate("wizard_repeater", compact("status", "summary"));
}

function DisplayUplinkWizard(array $options = [])
{
    $isEmbedded = !empty($options['embedded']);
    $status = new StatusMessage();
    $connectionCompleted = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['uplink_action'] ?? '') === 'configure_uplink_wifi') {
        $connectionCompleted = openapHandleUplinkWifiAction($status);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['uplink_action'] ?? '') === 'connect_saved_wifi') {
        $connectionCompleted = openapHandleSavedUplinkWifiAction($status);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['uplink_action'] ?? '') === 'forget_uplink_wifi') {
        openapHandleForgetUplinkWifiAction($status);
    }

    $forceScan = ($_GET['scan'] ?? '') === '1';
    $statusOnly = ($_GET['status'] ?? '') === '1';
    $repeaterActive = (openapReadRepeaterProfile()['mode']['current'] ?? '') === 'repeater_wifi';
    $repeaterApplyState = trim((string) @file_get_contents('/run/openap/repeater-apply.state'));
    if ($statusOnly && $repeaterApplyState === 'failed') {
        $applyError = trim((string) @file_get_contents('/run/openap/repeater-apply.error'));
        $status->addMessage($applyError !== '' ? $applyError : 'Repeater mode activation failed.', 'danger');
    }
    if (!$connectionCompleted && $repeaterActive && openapUplinkReady()) {
        $connectionCompleted = true;
    }

    $wireless = openapDetectWirelessInterfaces();
    $roles = openapSelectedRepeaterRoles($wireless);
    $uplink = $roles['uplink'];
    // An explicit scan while Repeater Mode is already active is a request to
    // choose another uplink. Keep the current link running, but suppress the
    // success summary so the network picker is rendered.
    $showConnectionSummary = $connectionCompleted && !$forceScan;
    $networks = !$statusOnly && !$showConnectionSummary && !empty($uplink['name'])
        ? openapCachedWifiNetworks($uplink['name'], $forceScan)
        : [];
    $currentSsid = openapCurrentUplinkSsid();
    $currentSecurity = 'wpa';
    foreach ($networks as $network) {
        if ($currentSsid !== ''
            && hash_equals((string) ($network['ssid'] ?? ''), $currentSsid)
            && strtolower((string) ($network['security'] ?? '')) === 'open') {
            $currentSecurity = 'open';
            break;
        }
    }
    $uplinkConfig = !empty($uplink['name'])
        ? '/etc/wpa_supplicant/wpa_supplicant-' . $uplink['name'] . '.conf'
        : '';
    if ($currentSsid !== '' && $uplinkConfig !== '' && is_readable($uplinkConfig)) {
        $configContents = (string) file_get_contents($uplinkConfig);
        if (preg_match('/^[[:space:]]*key_mgmt[[:space:]]*=[[:space:]]*NONE[[:space:]]*$/mi', $configContents)) {
            $currentSecurity = 'open';
        }
    }
    $summary = [
        'ready' => !empty($uplink['name']) && !empty($uplink['supports_managed']),
        'uplink' => $uplink,
        'networks' => $networks,
        'current_ssid' => $currentSsid,
        'current_security' => $currentSecurity,
        'repeater_active' => $repeaterActive,
        'connection_pending' => !$forceScan && !$connectionCompleted
            && ($repeaterActive || in_array($repeaterApplyState, ['scheduled', 'applying'], true)),
        'connection_summary' => $showConnectionSummary ? openapBuildConnectionSuccessSummary() : [],
    ];

    echo renderTemplate("wizard_uplink", compact("status", "summary", "isEmbedded"));
}

function openapHandleSavedUplinkWifiAction(StatusMessage $status): bool
{
    $iface = (string) ($_POST['uplink_interface'] ?? '');
    $ssid = trim((string) ($_POST['ssid'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $iface) || $ssid === '' || !in_array($ssid, openapKnownWifiSsids(), true)) {
        $status->addMessage('Saved WiFi network is not valid.', 'danger');
        return false;
    }
    if (openapScannedNetworkUsesUnsupportedWpa3($iface, $ssid)) {
        $status->addMessage('WPA3-only uplinks are not currently supported.', 'danger');
        return false;
    }

    $wireless = openapDetectWirelessInterfaces();
    $roles = openapSelectedRepeaterRoles($wireless);
    $selected = $roles['uplink'] ?? null;
    if (empty($roles['valid']) || !is_array($selected) || ($selected['name'] ?? '') !== $iface) {
        $status->addMessage('Saved WiFi network does not use the assigned UPLINK interface.', 'danger');
        return false;
    }

    if (hash_equals($ssid, openapCurrentUplinkSsid()) && openapUplinkReady()) {
        $status->addMessage('The selected saved WiFi uplink is already active.', 'success');
        $status->addMessage('No connection or service restart was required.', 'info');
        return (openapReadRepeaterProfile()['mode']['current'] ?? '') === 'repeater_wifi';
    }

    exec(
        'sudo /usr/local/sbin/openap-apply-uplink-wifi --saved '
        . escapeshellarg($iface) . ' ' . escapeshellarg($ssid) . ' 2>&1',
        $output,
        $return
    );
    if ($return !== 0) {
        $status->addMessage('Unable to start the saved WiFi uplink: ' . implode(' ', $output), 'danger');
        return false;
    }

    // Re-establish the repeater profile immediately. Association on some
    // Debian 13 USB radios can take several retry/backoff cycles; forwarding
    // configuration must not remain incorrectly labelled as AP Ethernet while
    // wpa_supplicant settles in the background.
    openapCompleteRepeaterAfterUplink($status, $wireless, $selected);

    $deadline = microtime(true) + 60;
    do {
        $linkOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($iface) . ' link 2>/dev/null', $linkOutput, $linkReturn);
        $link = implode("\n", $linkOutput);
        $linkedSsid = preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $link, $matches) ? trim($matches[1]) : '';
        if ($linkReturn === 0 && hash_equals($ssid, $linkedSsid) && openapInterfaceIpv4($iface) !== '-') {
            $status->addMessage('Connected using saved WiFi credentials.', 'success');
            return (openapReadRepeaterProfile()['mode']['current'] ?? '') === 'repeater_wifi';
        }
        usleep(500000);
    } while (microtime(true) < $deadline);

    $status->addMessage('Saved WiFi uplink started, but the connection is still settling.', 'warning');
    return false;
}

function openapBuildConnectionSuccessSummary(): array
{
    $profile = openapReadRepeaterProfile();
    $apInterface = (string) ($profile['interfaces']['ap'] ?? '');
    $uplinkInterface = (string) ($profile['interfaces']['uplink'] ?? '');
    $linkOutput = [];
    if (preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkInterface)) {
        exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkInterface) . ' link 2>/dev/null', $linkOutput);
    }
    $link = implode("\n", $linkOutput);
    preg_match('/^Connected to[[:space:]]+([0-9a-f:]+)/mi', $link, $bssidMatch);
    preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $link, $ssidMatch);
    preg_match('/^[[:space:]]*freq:[[:space:]]*([0-9.]+)/m', $link, $freqMatch);
    preg_match('/^[[:space:]]*signal:[[:space:]]*(-?[0-9.]+)[[:space:]]+dBm/m', $link, $signalMatch);
    preg_match('/^[[:space:]]*tx bitrate:[[:space:]]*(.+)$/m', $link, $bitrateMatch);
    $frequency = isset($freqMatch[1]) ? (int) round((float) $freqMatch[1]) : 0;
    $channel = $frequency > 0 ? openapFrequencyChannel($frequency) : '-';
    $gateway = '-';
    if ($uplinkInterface !== '') {
        $routeOutput = [];
        exec('/usr/sbin/ip -4 route show default dev ' . escapeshellarg($uplinkInterface) . ' 2>/dev/null', $routeOutput);
        if (preg_match('/\bvia[[:space:]]+([0-9.]+)/', implode("\n", $routeOutput), $gatewayMatch)) {
            $gateway = $gatewayMatch[1];
        }
    }

    return [
        'mode' => 'WiFi repeater',
        'uplink_ssid' => trim($ssidMatch[1] ?? ''),
        'uplink_interface' => $uplinkInterface ?: '-',
        'uplink_ip' => $uplinkInterface !== '' ? openapInterfaceIpv4($uplinkInterface) : '-',
        'uplink_bssid' => strtolower($bssidMatch[1] ?? '-'),
        'uplink_band' => $frequency >= 5000 ? '5 GHz' : ($frequency > 0 ? '2.4 GHz' : '-'),
        'uplink_channel' => $channel,
        'uplink_frequency' => $frequency > 0 ? $frequency . ' MHz' : '-',
        'uplink_signal' => isset($signalMatch[1]) ? round((float) $signalMatch[1]) . ' dBm' : '-',
        'uplink_bitrate' => trim($bitrateMatch[1] ?? '-'),
        'uplink_gateway' => $gateway,
        'ap_ssid' => openapHostapdConfigValue('ssid') ?: '-',
        'ap_interface' => $apInterface ?: '-',
        'ap_ip' => $apInterface !== '' ? openapInterfaceIpv4($apInterface) : '-',
        'ap_channel' => openapHostapdConfigValue('channel') ?: '-',
        'country' => openapHostapdConfigValue('country_code') ?: '-',
    ];
}

function openapHandleRepeaterAction(string $action, StatusMessage $status): void
{
    $commands = [
        'restart_ap' => [
            'label' => 'AP service',
            'cmd' => 'sudo /bin/systemctl restart hostapd.service',
        ],
        'restart_uplink' => [
            'label' => 'uplink service',
            'cmd' => 'sudo /bin/systemctl restart openap-uplink.service',
        ],
        'restart_dhcp' => [
            'label' => 'DHCP/DNS service',
            'cmd' => 'sudo /bin/systemctl restart dnsmasq.service',
        ],
    ];

    if (!isset($commands[$action])) {
        $status->addMessage('Unknown repeater action.', 'danger');
        return;
    }

    exec($commands[$action]['cmd'] . ' 2>&1', $output, $return);
    if ($return === 0) {
        $ready = openapWaitAfterRepeaterAction($action);
        $status->addMessage('Restarted ' . $commands[$action]['label'] . '.', 'success');
        if (!$ready) {
            $status->addMessage('Service restarted, but status is still settling. Refresh the page in a few seconds.', 'warning');
        }
    } else {
        $status->addMessage('Failed to restart ' . $commands[$action]['label'] . ': ' . implode(' ', $output), 'danger');
    }
}

function openapHandleApEthernetAction(StatusMessage $status): void
{
    $iface = (string) ($_POST['ethernet_interface'] ?? '');
    $gateway = (string) ($_POST['ethernet_gateway'] ?? '');
    $apMac = strtolower((string) ($_POST['ap_mac'] ?? ''));
    $networkMode = (string) ($_POST['network_mode'] ?? 'routed');
    if (!in_array($networkMode, ['routed', 'bridge'], true)) {
        $status->addMessage('Invalid Ethernet Mode network setting.', 'danger');
        return;
    }

    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $iface) || $iface === 'lo' || str_starts_with($iface, 'wl') || str_starts_with($iface, 'wlan')) {
        $status->addMessage('Invalid ethernet interface.', 'danger');
        return;
    }
    if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        $status->addMessage('Invalid ethernet gateway.', 'danger');
        return;
    }
    if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $apMac)) {
        $status->addMessage('Invalid AP WiFi interface.', 'danger');
        return;
    }

    $ethernet = openapDetectEthernetInterfaces();
    $selected = null;
    foreach ($ethernet as $candidate) {
        if ($candidate['name'] === $iface) {
            $selected = $candidate;
            break;
        }
    }
    if ($selected === null || ($selected['carrier'] ?? '') !== 'up') {
        $status->addMessage('Ethernet interface is not ready.', 'danger');
        return;
    }
    $wireless = openapDetectWirelessInterfaces();
    $selectedAp = null;
    foreach ($wireless as $candidate) {
        if (strtolower($candidate['mac']) === $apMac && !empty($candidate['supports_ap'])) {
            $selectedAp = $candidate;
            break;
        }
    }
    if ($selectedAp === null) {
        $status->addMessage('Selected WiFi interface is not AP-capable.', 'danger');
        return;
    }

    // Applying the already active AP Ethernet configuration must be a no-op.
    // Re-running the helper would unnecessarily restart networkd, dnsmasq,
    // firewall and hostapd, and may interrupt the HTTP request that triggered it.
    $profile = openapReadRepeaterProfile();
    $currentMode = str_replace('-', '_', (string) ($profile['mode']['current'] ?? ''));
    $configuredIface = (string) ($profile['interfaces']['ethernet'] ?? '');
    $configuredGateway = (string) ($profile['network']['ethernet_gateway'] ?? '');
    $configuredApMac = strtolower((string) ($profile['interfaces']['ap_mac'] ?? ''));
    $requestedMode = $networkMode === 'bridge' ? 'ap_ethernet_bridge' : 'ap_ethernet';
    $runtimeGateway = '';
    $runtimeRouteInterface = $requestedMode === 'ap_ethernet_bridge' ? 'br0' : $iface;
    $routeOutput = [];
    $routeReturn = 1;
    exec(
        '/usr/sbin/ip -4 route show default dev ' . escapeshellarg($runtimeRouteInterface) . ' 2>/dev/null',
        $routeOutput,
        $routeReturn
    );
    if ($routeReturn === 0
        && preg_match('/\bvia[[:space:]]+([0-9.]+)/', implode("\n", $routeOutput), $routeMatch)
    ) {
        $runtimeGateway = $routeMatch[1];
    }
    if ($currentMode === $requestedMode
        && hash_equals($configuredIface, $iface)
        && hash_equals($configuredGateway, $gateway)
        && hash_equals($configuredApMac, $apMac)
        && hash_equals($runtimeGateway, $gateway)
    ) {
        $status->addMessage('Ethernet Mode is already active with this configuration.', 'success');
        $status->addMessage('No network changes or service restarts were required.', 'info');
        return;
    }

    if (!openapCanConfigureApEthernet()) {
        $status->addMessage('Ethernet Mode requirements are not satisfied.', 'danger');
        return;
    }

    if ($networkMode === 'bridge') {
        $bridgeAction = $currentMode === 'ap_ethernet_bridge' ? '--gateway-delayed' : '--apply-delayed';
        $command = sprintf(
            'sudo /usr/local/sbin/openap-apply-ap-ethernet-bridge %s %s %s %s 2>&1',
            $bridgeAction,
            escapeshellarg($iface),
            escapeshellarg($apMac),
            escapeshellarg($gateway)
        );
    } else {
        if ($currentMode === 'ap_ethernet_bridge') {
            $command = sprintf(
                'sudo /usr/local/sbin/openap-apply-ap-ethernet-bridge --routed-delayed %s %s %s 2>&1',
                escapeshellarg($iface),
                escapeshellarg($apMac),
                escapeshellarg($gateway)
            );
        } else {
            $command = sprintf(
                'sudo /usr/local/sbin/openap-apply-ap-ethernet --apply-delayed %s %s %s 2>&1',
                escapeshellarg($iface),
                escapeshellarg($gateway),
                escapeshellarg($apMac)
            );
        }
    }
    exec($command, $output, $return);
    if ($return === 0) {
        $status->addMessage('Ethernet Mode configuration applied.', 'success');
        $status->addMessage(implode(' ', $output), 'info');
    } else {
        $status->addMessage('Failed to configure Ethernet Mode: ' . implode(' ', $output), 'danger');
    }
}

function openapHandleRepeaterWifiAction(StatusMessage $status): void
{
    $apMac = (string) ($_POST['ap_mac'] ?? '');
    $uplinkMac = (string) ($_POST['uplink_mac'] ?? '');

    if (!preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $apMac)
        || !preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $uplinkMac)
        || strtolower($apMac) === strtolower($uplinkMac)) {
        $status->addMessage('Invalid repeater role selection.', 'danger');
        return;
    }

    $wireless = openapDetectWirelessInterfaces();
    $byMac = [];
    foreach ($wireless as $iface) {
        $byMac[strtolower($iface['mac'])] = $iface;
    }

    $ap = $byMac[strtolower($apMac)] ?? null;
    $uplink = $byMac[strtolower($uplinkMac)] ?? null;
    if ($ap === null || $uplink === null) {
        $status->addMessage('Selected WiFi interfaces are not present.', 'danger');
        return;
    }
    if (empty($ap['supports_ap']) || empty($uplink['supports_managed'])) {
        $status->addMessage('Selected WiFi interfaces do not satisfy repeater requirements.', 'danger');
        return;
    }

    $command = sprintf(
        'sudo /usr/local/sbin/openap-apply-repeater-wifi --apply-delayed %s %s 2>&1',
        escapeshellarg($apMac),
        escapeshellarg($uplinkMac)
    );
    exec($command, $output, $return);
    if ($return === 0) {
        $status->addMessage('WiFi repeater configuration applied.', 'success');
        $status->addMessage(implode(' ', $output), 'info');
    } else {
        $status->addMessage('Failed to configure WiFi repeater: ' . implode(' ', $output), 'danger');
    }
}

function openapHandleUplinkWifiAction(StatusMessage $status): bool
{
    $iface = (string) ($_POST['uplink_interface'] ?? '');
    $ssid = trim((string) ($_POST['ssid'] ?? ''));
    $passphrase = (string) ($_POST['passphrase'] ?? '');
    $security = (string) ($_POST['security'] ?? 'wpa');

    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $iface)) {
        $status->addMessage('Invalid uplink interface.', 'danger');
        return false;
    }
    if ($ssid === '' || strlen($ssid) > 32) {
        $status->addMessage('Invalid uplink SSID.', 'danger');
        return false;
    }
    if (!in_array($security, ['open', 'wpa'], true)) {
        $status->addMessage('Invalid uplink security type.', 'danger');
        return false;
    }
    if (openapScannedNetworkUsesUnsupportedWpa3($iface, $ssid)) {
        $status->addMessage('WPA3-only uplinks are not currently supported.', 'danger');
        return false;
    }
    if ($security === 'wpa' && (strlen($passphrase) < 8 || strlen($passphrase) > 63)) {
        $status->addMessage('WPA passphrase must be between 8 and 63 characters.', 'danger');
        return false;
    }

    $wireless = openapDetectWirelessInterfaces();
    $roles = openapSelectedRepeaterRoles($wireless);
    $selected = $roles['uplink'] ?? null;
    if (empty($roles['valid']) || !is_array($selected) || ($selected['name'] ?? '') !== $iface) {
        $status->addMessage('Selected interface is not the assigned UPLINK WiFi.', 'danger');
        return false;
    }
    if (openapWirelessInterfaceInUseOutsideOpenap($selected)) {
        $status->addMessage('Selected uplink interface is already connected and managed outside OpenAP. Choose a free WiFi adapter.', 'danger');
        return false;
    }

    if ($security === 'open') {
        $scannedNetworks = openapCachedWifiNetworks($iface);
        $openNetworkConfirmed = false;
        foreach ($scannedNetworks as $network) {
            if (hash_equals((string) ($network['ssid'] ?? ''), $ssid)
                && strtolower((string) ($network['security'] ?? '')) === 'open') {
                $openNetworkConfirmed = true;
                break;
            }
        }
        if (!$openNetworkConfirmed) {
            $status->addMessage('The selected network could not be confirmed as an open WiFi network. Scan again before connecting.', 'danger');
            return false;
        }
    }

    $tmp = tempnam('/tmp', 'openap-uplink-');
    if ($tmp === false) {
        $status->addMessage('Unable to create temporary uplink configuration.', 'danger');
        return false;
    }
    $tmpConf = $tmp . '.conf';
    rename($tmp, $tmpConf);
    chmod($tmpConf, 0600);
    $config = openapBuildWpaSupplicantConfig($ssid, $passphrase, $security);
    file_put_contents($tmpConf, $config);

    $command = sprintf(
        'sudo /usr/local/sbin/openap-apply-uplink-wifi %s %s 2>&1',
        escapeshellarg($iface),
        escapeshellarg($tmpConf)
    );
    exec($command, $output, $return);
    if (file_exists($tmpConf)) {
        unlink($tmpConf);
    }

    if ($return === 0) {
        $status->addMessage('WiFi uplink configured and connected.', 'success');
        $status->addMessage(implode(' ', $output), 'info');
        openapCompleteRepeaterAfterUplink($status, $wireless, $selected);
        return (openapReadRepeaterProfile()['mode']['current'] ?? '') === 'repeater_wifi';
    } elseif ($return === 2) {
        $status->addMessage('WiFi uplink saved, but connection is still settling.', 'warning');
        $status->addMessage(implode(' ', $output), 'info');
    } else {
        $status->addMessage('Failed to configure WiFi uplink: ' . implode(' ', $output), 'danger');
    }
    return false;
}

function openapHandleForgetUplinkWifiAction(StatusMessage $status): void
{
    $iface = (string) ($_POST['uplink_interface'] ?? '');
    $ssid = (string) ($_POST['ssid'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $iface)) {
        $status->addMessage('Invalid uplink interface.', 'danger');
        return;
    }
    if ($ssid === '' || strlen($ssid) > 32 || strpbrk($ssid, "\0\r\n") !== false) {
        $status->addMessage('Invalid saved uplink SSID.', 'danger');
        return;
    }
    exec(
        'sudo /usr/local/sbin/openap-forget-uplink-wifi '
        . escapeshellarg($iface) . ' ' . escapeshellarg($ssid) . ' 2>&1',
        $output,
        $return
    );
    if ($return === 0) {
        $status->addMessage('Saved network removed.', 'success');
    } else {
        $status->addMessage(implode(' ', $output), 'danger');
    }
}

function openapCompleteRepeaterAfterUplink(StatusMessage $status, array $wireless, array $connectedUplink): void
{
    $profile = openapReadRepeaterProfile();
    if (($profile['mode']['current'] ?? '') === 'repeater_wifi') {
        return;
    }

    $roles = openapSelectedRepeaterRoles($wireless);
    $roles['uplink'] = $connectedUplink;
    $roles['valid'] = !empty($roles['ap']['supports_ap'])
        && !empty($connectedUplink['supports_managed'])
        && strtolower((string) $roles['ap']['mac']) !== strtolower((string) $connectedUplink['mac']);
    if (empty($roles['valid'])) {
        $status->addMessage('Uplink connected. Repeater role selection is not valid yet.', 'warning');
        return;
    }

    $command = sprintf(
        'sudo /usr/local/sbin/openap-apply-repeater-wifi --apply-delayed %s %s 2>&1',
        escapeshellarg($roles['ap']['mac']),
        escapeshellarg($roles['uplink']['mac'])
    );
    exec($command, $output, $return);
    if ($return === 0) {
        $status->addMessage('Repeater mode activated after uplink setup.', 'success');
        $status->addMessage(implode(' ', $output), 'info');
    } else {
        $status->addMessage('Uplink connected, but repeater mode activation failed: ' . implode(' ', $output), 'danger');
    }
}

function openapWaitAfterRepeaterAction(string $action): bool
{
    $deadline = time() + 15;
    do {
        if ($action === 'restart_uplink' && openapUplinkReady()) {
            return true;
        }
        if ($action === 'restart_ap' && openapApReady()) {
            return true;
        }
        if ($action === 'restart_dhcp' && openapServiceActive('dnsmasq.service') === 'active') {
            return true;
        }
        usleep(500000);
    } while (time() < $deadline);

    return false;
}

function openapUplinkReady(): bool
{
    return openapUplinkHealth()['ready'];
}

/**
 * Fast repeater-uplink dataplane check for shared UI and API health reporting.
 *
 * systemd can keep openap-uplink.service active after a passed-through USB
 * radio disappears. Treat service state as only one signal and also require
 * the configured interface/MAC, association, IPv4 address and default route.
 */
function openapUplinkHealth(): array
{
    $profile = openapReadRepeaterProfile();
    $interface = (string) ($profile['interfaces']['uplink'] ?? '');
    $expectedMac = strtolower(trim((string) ($profile['interfaces']['uplink_mac'] ?? '')));
    $health = [
        'ready' => false,
        'interface' => $interface,
        'expected_mac' => $expectedMac,
        'actual_mac' => '-',
        'interface_present' => false,
        'mac_matches' => false,
        'service_active' => openapServiceActive('openap-uplink.service') === 'active',
        'associated' => false,
        'ipv4' => '-',
        'default_route' => false,
        'watchdog_status' => 'unknown',
        'watchdog_reason' => '',
        'internet_reachable' => null,
        'watchdog_checked_at' => null,
        'reason' => 'Uplink interface is not configured',
    ];

    $watchdogFile = '/run/openap/uplink-watchdog.env';
    if (is_readable($watchdogFile)) {
        $watchdog = parse_ini_file($watchdogFile, false, INI_SCANNER_RAW);
        if (is_array($watchdog)) {
            $checkedAt = filter_var($watchdog['checked_at'] ?? null, FILTER_VALIDATE_INT);
            if ($checkedAt !== false && time() - (int) $checkedAt <= 60) {
                $health['watchdog_status'] = (string) ($watchdog['status'] ?? 'unknown');
                $health['watchdog_reason'] = (string) ($watchdog['reason'] ?? '');
                $health['watchdog_checked_at'] = (int) $checkedAt;
                $internet = (string) ($watchdog['internet'] ?? 'unknown');
                $health['internet_reachable'] = $internet === 'ready'
                    ? true
                    : ($internet === 'unavailable' ? false : null);
            }
        }
    }

    if ($interface === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return $health;
    }

    $addressPath = '/sys/class/net/' . $interface . '/address';
    if (!is_readable($addressPath)) {
        $health['reason'] = 'Uplink interface is missing';
        return $health;
    }

    $health['interface_present'] = true;
    $health['actual_mac'] = strtolower(trim((string) file_get_contents($addressPath)));
    $health['mac_matches'] = $expectedMac === '' || $expectedMac === '-'
        || hash_equals($expectedMac, $health['actual_mac']);
    if (!$health['mac_matches']) {
        $health['reason'] = 'Uplink MAC does not match the configured radio';
        return $health;
    }

    if (!$health['service_active']) {
        $health['reason'] = 'Uplink service is inactive';
        return $health;
    }

    $linkOutput = [];
    exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' link 2>/dev/null', $linkOutput, $linkReturn);
    $health['associated'] = $linkReturn === 0
        && str_starts_with(implode("\n", $linkOutput), 'Connected to ');
    if (!$health['associated']) {
        $health['reason'] = 'Uplink WiFi is not associated';
        return $health;
    }

    $health['ipv4'] = openapInterfaceIpv4($interface);
    if ($health['ipv4'] === '-') {
        $health['reason'] = 'Uplink has no IPv4 address';
        return $health;
    }

    $routeOutput = [];
    exec('/sbin/ip -4 route show default dev ' . escapeshellarg($interface) . ' 2>/dev/null', $routeOutput, $routeReturn);
    $health['default_route'] = $routeReturn === 0 && !empty($routeOutput);
    if (!$health['default_route']) {
        $health['reason'] = 'Uplink has no default route';
        return $health;
    }

    $health['ready'] = true;
    $health['reason'] = 'Uplink ready';
    return $health;
}

function openapApReady(): bool
{
    if (!openapApInterfaceReady()) {
        return false;
    }

    $apInterface = openapCurrentRepeaterInterfaces()['ap'];
    $ap = openapCommandKeyValues(
        sprintf('sudo /usr/sbin/hostapd_cli -p /run/hostapd -i %s status 2>/dev/null', escapeshellarg($apInterface))
    );
    return ($ap['state'] ?? '') === 'ENABLED';
}

/**
 * Fast AP dataplane check for common web request paths.
 *
 * A stale hostapd process can remain active after USB passthrough loss. Avoid
 * hostapd_cli here because it can block PHP-CGI for several seconds on the
 * supported VM distributions. The configured interface must exist, retain its
 * recorded MAC address and own the configured hotspot gateway.
 */
function openapApInterfaceReady(): bool
{
    $profile = openapReadRepeaterProfile();
    $isSkynetExistingAp = defined('OPENAP_SKYNET_EXISTING_AP') && OPENAP_SKYNET_EXISTING_AP;
    $apInterface = (string) ($profile['interfaces']['ap'] ?? '');
    if ($apInterface === '' && $isSkynetExistingAp && defined('OPENAP_WIFI_AP_INTERFACE')) {
        $apInterface = (string) OPENAP_WIFI_AP_INTERFACE;
    }
    if ($apInterface === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $apInterface)) {
        return false;
    }

    $apInterfaces = array_values(array_unique(array_filter([
        $profile['interfaces']['ap_24ghz'] ?? '',
        $profile['interfaces']['ap_5ghz'] ?? '',
        $apInterface,
    ], static fn($value): bool => is_string($value) && $value !== '')));
    foreach ($apInterfaces as $candidate) {
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $candidate) || !is_dir('/sys/class/net/' . $candidate)) {
            return false;
        }
    }

    $addressPath = '/sys/class/net/' . $apInterface . '/address';
    $configuredMac = strtolower(trim((string) ($profile['interfaces']['ap_mac'] ?? '')));
    $actualMac = is_readable($addressPath) ? strtolower(trim((string) file_get_contents($addressPath))) : '';
    if ($configuredMac !== '' && $configuredMac !== '-' && $actualMac !== $configuredMac) {
        return false;
    }

    $gateway = (string) ($profile['network']['gateway'] ?? '');
    if (($profile['mode']['current'] ?? '') === 'ap_ethernet_bridge') {
        $interfacePath = '/sys/class/net/' . $apInterface;
        $masterPath = $interfacePath . '/master';
        $bridge = (string) ($profile['network']['bridge'] ?? 'br0');
        return is_link($masterPath)
            && basename((string) realpath($masterPath)) === $bridge
            && openapInterfaceIpv4($bridge) !== '-';
    }
    if ($gateway === '' && $isSkynetExistingAp) {
        return openapInterfaceIpv4($apInterface) !== '-';
    }
    if ($gateway === '' && defined('OPENAP_REPEATER_GATEWAY')) {
        $gateway = (string) OPENAP_REPEATER_GATEWAY;
    }
    if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $hotspotInterface = trim((string) ($profile['interfaces']['bridge'] ?? $profile['network']['bridge'] ?? ''));
    // Raspberry native AP mode assigns the hotspot gateway directly to the
    // selected AP interface and intentionally has no bridge name. Keep the
    // bridge requirement for bridge-backed profiles, but validate the actual
    // selected AP interface for the native address backend.
    $addressBackend = (string) ($profile['runtime']['address_backend'] ?? '');
    if ($hotspotInterface === '' && $addressBackend === 'openap-ap-address') {
        $hotspotInterface = $apInterface;
    }
    return openapInterfaceIpv4($hotspotInterface) === $gateway;
}

function openapGetRepeaterSummary(string $apInterface, string $uplinkInterface): array
{
    $profile = openapReadRepeaterProfile();
    $detected = openapDetectWirelessInterfaces();
    $ethernet = openapDetectEthernetInterfaces();
    $hostapd = openapCommandKeyValues(
        sprintf('sudo /usr/sbin/hostapd_cli -p /run/hostapd -i %s status 2>/dev/null', escapeshellarg($apInterface))
    );
    $uplink = openapCommandKeyValues(
        sprintf('sudo /usr/sbin/wpa_cli -i %s status 2>/dev/null', escapeshellarg($uplinkInterface))
    );
    $signal = openapCommandKeyValues(
        sprintf('sudo /usr/sbin/wpa_cli -i %s signal_poll 2>/dev/null', escapeshellarg($uplinkInterface))
    );

    return [
        'profile' => [
            'path' => defined('OPENAP_REPEATER_PROFILE') ? OPENAP_REPEATER_PROFILE : '-',
            'ap' => $profile['interfaces']['ap'] ?? $apInterface,
            'uplink' => $profile['interfaces']['uplink'] ?? $uplinkInterface,
            'ap_mac' => $profile['interfaces']['ap_mac'] ?? '-',
            'uplink_mac' => $profile['interfaces']['uplink_mac'] ?? '-',
            'roles_valid' => openapRepeaterRolesValid($profile, $detected) ? 'valid' : 'invalid',
        ],
        'detected' => $detected,
        'ethernet' => $ethernet,
        'ap' => [
            'interface' => $apInterface,
            'service' => openapServiceActive('hostapd.service'),
            'ssid' => $hostapd['ssid[0]'] ?? openapHostapdConfigValue('ssid'),
            'state' => $hostapd['state'] ?? '-',
            'frequency' => openapFormatFrequency($hostapd['freq'] ?? ''),
            'channel' => $hostapd['channel'] ?? openapHostapdConfigValue('channel'),
            'mode' => !empty($hostapd['ieee80211ac']) ? '802.11ac' : (!empty($hostapd['ieee80211n']) ? '802.11n' : '-'),
            'clients' => $hostapd['num_sta[0]'] ?? '0',
        ],
        'uplink' => [
            'interface' => $uplinkInterface,
            'service' => openapServiceActive('openap-uplink.service'),
            'ssid' => $uplink['ssid'] ?? '-',
            'state' => $uplink['wpa_state'] ?? '-',
            'ip' => $uplink['ip_address'] ?? openapInterfaceIpv4($uplinkInterface),
            'frequency' => openapFormatFrequency($uplink['freq'] ?? ($signal['FREQUENCY'] ?? '')),
            'rssi' => isset($signal['RSSI']) ? $signal['RSSI'] . ' dBm' : '-',
            'link_speed' => isset($signal['LINKSPEED']) ? $signal['LINKSPEED'] . ' Mbit/s' : '-',
        ],
        'network' => [
            'gateway' => defined('OPENAP_REPEATER_GATEWAY') ? OPENAP_REPEATER_GATEWAY : openapInterfaceIpv4($apInterface),
            'subnet' => defined('OPENAP_REPEATER_SUBNET') ? OPENAP_REPEATER_SUBNET : '-',
            'dhcp_range' => openapDhcpRange(),
            'dnsmasq' => openapServiceActive('dnsmasq.service'),
            'nftables' => openapServiceActive('openap-firewall.service'),
            'ip_forward' => trim((string) shell_exec('/usr/sbin/sysctl -n net.ipv4.ip_forward 2>/dev/null')) === '1' ? 'active' : 'inactive',
            'nat' => openapNatActive() ? 'active' : 'inactive',
        ],
    ];
}

function openapRepeaterActive(): bool
{
    if (!defined('OPENAP_REPEATER_CONTAINER') || !OPENAP_REPEATER_CONTAINER) {
        return false;
    }

    $profile = openapReadRepeaterProfile();
    if (($profile['mode']['current'] ?? '') !== 'repeater_wifi') {
        return false;
    }

    $detected = openapDetectWirelessInterfaces();
    $uplink = $profile['interfaces']['uplink'] ?? (defined('OPENAP_WIFI_CLIENT_INTERFACE') ? OPENAP_WIFI_CLIENT_INTERFACE : 'wlan1');

    return openapRepeaterRolesValid($profile, $detected)
        && openapApReady()
        && openapUplinkReady()
        && openapServiceActive('dnsmasq.service') === 'active'
        && openapServiceActive('openap-firewall.service') === 'active'
        && openapNatActive($uplink);
}

function openapCanConfigureApEthernet(): bool
{
    $ethernet = openapDetectEthernetInterfaces();
    $apCapable = array_values(array_filter(
        openapDetectWirelessInterfaces(),
        fn($iface) => !empty($iface['supports_ap'])
    ));

    if (count($apCapable) < 1) {
        return false;
    }

    // Prefer the physical Ethernet interface selected by OpenAP. Checking
    // any carrier-positive Ethernet port can enable Ethernet Mode when a
    // different, unused port is connected.
    $profile = openapReadRepeaterProfile();
    $configured = trim((string) ($profile['interfaces']['ethernet_physical'] ?? ''));
    if ($configured === '') {
        $configured = trim((string) ($profile['interfaces']['ethernet'] ?? ''));
    }
    if ($configured !== '') {
        foreach ($ethernet as $iface) {
            if (($iface['name'] ?? '') === $configured) {
                return ($iface['carrier'] ?? '') === 'up';
            }
        }
        return false;
    }

    // During first installation there may be no profile yet.
    return (bool) array_filter(
        $ethernet,
        fn($iface) => ($iface['carrier'] ?? '') === 'up'
    );
}

function openapCanConfigureRepeater(): bool
{
    $wifi = openapDetectWirelessInterfaces();
    $apCapable = array_values(array_filter($wifi, fn($iface) => !empty($iface['supports_ap'])));
    $managedCapable = array_values(array_filter($wifi, fn($iface) => !empty($iface['supports_managed'])));

    return count($wifi) >= 2 && count($apCapable) >= 1 && count($managedCapable) >= 2;
}

function openapSelectedRepeaterRoles(array $wireless): array
{
    $profile = openapReadRepeaterProfile();
    $stored = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW);
    $model = openapBuildWifiRoleModel($profile, is_array($stored) ? $stored : [], $wireless);
    return openapEvaluateRepeaterAvailability($model['active'], $wireless);
}

function openapWirelessInterfaceInUseOutsideOpenap(array $interface): bool
{
    $profile = openapReadRepeaterProfile();
    $configuredName = (string) ($profile['interfaces']['uplink'] ?? '');
    $configuredMac = strtolower((string) ($profile['interfaces']['uplink_mac'] ?? ''));
    $name = (string) ($interface['name'] ?? '');
    $mac = strtolower((string) ($interface['mac'] ?? ''));
    $ownedByOpenap = ($configuredName !== '' && $configuredName === $name)
        || ($configuredMac !== '' && $configuredMac !== '-' && $configuredMac === $mac);
    if ($ownedByOpenap) {
        return false;
    }
    return ($interface['ssid'] ?? '-') !== '-' || openapInterfaceIpv4($name) !== '-';
}

function openapCurrentRepeaterInterfaces(): array
{
    $profile = openapReadRepeaterProfile();
    return [
        'ap' => $profile['interfaces']['ap'] ?? OPENAP_WIFI_AP_INTERFACE,
        'uplink' => $profile['interfaces']['uplink'] ?? (defined('OPENAP_WIFI_CLIENT_INTERFACE') ? OPENAP_WIFI_CLIENT_INTERFACE : 'wlan1'),
    ];
}

function openapFirstUpEthernet(array $ethernet): array
{
    $profile = openapReadRepeaterProfile();
    $configured = $profile['interfaces']['ethernet'] ?? '';
    foreach ($ethernet as $iface) {
        if ($iface['name'] === $configured && ($iface['carrier'] ?? '') === 'up'
            && filter_var($iface['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $iface;
        }
    }
    foreach ($ethernet as $iface) {
        if (($iface['carrier'] ?? '') === 'up'
            && filter_var($iface['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $iface;
        }
    }
    return $ethernet[0] ?? ['name' => '', 'ip' => '', 'mac' => '', 'carrier' => 'down'];
}

function openapConfiguredEthernetName(): string
{
    $profile = openapReadRepeaterProfile();
    return (string) ($profile['interfaces']['ethernet'] ?? '');
}

function openapEffectiveEthernet(array $ethernet): array
{
    $configured = openapConfiguredEthernetName();
    if ($configured !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $configured)) {
        $masterPath = '/sys/class/net/' . $configured . '/master';
        $effective = is_link($masterPath) ? basename((string) realpath($masterPath)) : $configured;
        foreach ($ethernet as $iface) {
            if ($iface['name'] === $effective && ($iface['carrier'] ?? '') === 'up'
                && filter_var($iface['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $iface;
            }
        }
    }
    return openapFirstUpEthernet($ethernet);
}

function openapSelectedApInterface(array $wireless): array
{
    $profile = openapReadRepeaterProfile();
    $configuredMac = strtolower($profile['interfaces']['ap_mac'] ?? '');
    foreach ($wireless as $iface) {
        if (!empty($iface['supports_ap']) && strtolower($iface['mac']) === $configuredMac) {
            return $iface;
        }
    }
    foreach ($wireless as $iface) {
        if (!empty($iface['supports_ap'])) {
            return $iface;
        }
    }
    return ['name' => '', 'mac' => '', 'supports_ap' => false];
}

function openapEthernetGatewaySuggestion(array $ethernet): string
{
    $selected = openapEffectiveEthernet($ethernet);
    $interface = (string) ($selected['name'] ?? '');
    $routes = [];
    exec('ip -4 route show default 2>/dev/null', $routes);
    foreach ($routes as $route) {
        if (!preg_match('/\bvia\s+((?:\d{1,3}\.){3}\d{1,3})\b/', $route, $gatewayMatch)) {
            continue;
        }
        $candidate = $gatewayMatch[1];
        if ($interface !== '' && preg_match('/\bdev\s+([^\s]+)/', $route, $deviceMatch)
            && $deviceMatch[1] === $interface) {
            return $candidate;
        }
    }

    $profile = openapReadRepeaterProfile();
    if (!empty($profile['network']['ethernet_gateway'])
        && filter_var($profile['network']['ethernet_gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return $profile['network']['ethernet_gateway'];
    }

    $ip = $selected['ip'] ?? '';
    if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ip, $matches)) {
        return $matches[1] . '.1';
    }
    return '';
}

function openapDashboardEthernetTarget(): string
{
    if (defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER && openapCanConfigureApEthernet()) {
        return '/ap_wizard';
    }
    return '/network_conf';
}

function openapDashboardRepeaterTarget(): string
{
    if (!defined('OPENAP_REPEATER_CONTAINER') || !OPENAP_REPEATER_CONTAINER) {
        return '/wpa_conf';
    }
    if (openapRepeaterActive()) {
        return '/repeater';
    }
    if (openapCanConfigureRepeater()) {
        return '/uplink_wizard';
    }
    return '#';
}

function openapCurrentUplinkSsid(): string
{
    $uplinkInterface = openapCurrentRepeaterInterfaces()['uplink'];
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkInterface)) {
        return '';
    }
    $output = [];
    exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkInterface) . ' link 2>/dev/null', $output, $return);
    if ($return === 0 && preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', implode("\n", $output), $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function openapCurrentApInfo(): array
{
    $apInterface = openapCurrentRepeaterInterfaces()['ap'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $apInterface)) {
        return ['ssid' => '', 'mac' => ''];
    }
    $output = [];
    exec('/usr/sbin/iw dev ' . escapeshellarg($apInterface) . ' info 2>/dev/null', $output, $return);
    if ($return !== 0) {
        return ['ssid' => '', 'mac' => ''];
    }
    $info = ['ssid' => '', 'mac' => ''];
    $text = implode("\n", $output);
    if (preg_match('/^[[:space:]]*addr[[:space:]]+([0-9a-f:]{17})$/im', $text, $matches)) {
        $info['mac'] = strtolower($matches[1]);
    }
    if (preg_match('/^[[:space:]]*ssid[[:space:]:]+(.+)$/im', $text, $matches)) {
        $info['ssid'] = trim($matches[1]);
    }
    return $info;
}

function openapScanWifiNetworks(string $interface): array
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return [];
    }
    // Use one scan path before and after the uplink is connected. wpa_cli
    // calls from PHP-CGI stall for 10 seconds each on Ubuntu 26.04 sudo-rs.
    $networks = openapScanWifiNetworksWithIw($interface);

    $networks = openapDecorateWifiNetworks($networks);
    uasort($networks, fn($a, $b) => (int) $b['signal'] <=> (int) $a['signal']);
    return array_values($networks);
}

function openapDecorateWifiNetworks(array $networks): array
{
    $profile = openapReadRepeaterProfile();
    $apInfo = openapCurrentApInfo();
    $apMac = strtolower((string) ($apInfo['mac'] ?? ''));
    if ($apMac === '') {
        $apMac = strtolower((string) ($profile['interfaces']['ap_mac'] ?? ''));
    }
    $savedSsids = openapKnownWifiSsids();
    $connectedSsid = openapCurrentUplinkSsid();

    foreach ($networks as $key => &$network) {
        $ssid = (string) ($network['ssid'] ?? '');
        $bssid = strtolower((string) ($network['bssid'] ?? ''));
        // Hide only the local AP; other APs may reuse the same SSID.
        if ($apMac !== '' && $bssid === $apMac) {
            unset($networks[$key]);
            continue;
        }
        $network['saved'] = in_array($ssid, $savedSsids, true);
        $network['connected'] = $connectedSsid !== '' && hash_equals($connectedSsid, $ssid);
    }
    unset($network);
    return $networks;
}

function openapScannedNetworkUsesUnsupportedWpa3(string $interface, string $ssid): bool
{
    foreach (openapCachedWifiNetworks($interface) as $network) {
        if (hash_equals((string) ($network['ssid'] ?? ''), $ssid)) {
            return strtolower((string) ($network['security'] ?? '')) === 'wpa3';
        }
    }
    return false;
}

function openapKnownWifiSsids(): array
{
    $ssids = [];
    $profileFiles = array_merge(
        glob('/etc/wpa_supplicant/*.conf') ?: [],
        glob('/etc/openap/uplinks/*.conf') ?: []
    );
    foreach ($profileFiles as $file) {
        $contents = @file_get_contents($file);
        if (!is_string($contents)) continue;
        if (preg_match_all('/^\s*ssid\s*=\s*"([^"]+)"/m', $contents, $matches)) {
            $ssids = array_merge($ssids, $matches[1]);
        }
    }
    return array_values(array_unique(array_filter($ssids, fn($ssid) => $ssid !== '')));
}

function openapSavedWifiPassphrase(string $ssid): string
{
    if ($ssid === '' || strlen($ssid) > 32 || strpbrk($ssid, "\0\r\n") !== false) {
        return '';
    }
    $profile = '/etc/openap/uplinks/' . hash('sha256', $ssid) . '.conf';
    $contents = @file_get_contents($profile);
    if (!is_string($contents)) {
        return '';
    }
    foreach (['/^\s*#psk="((?:\\\\.|[^"])*)"\s*$/m', '/^\s*psk="((?:\\\\.|[^"])*)"\s*$/m'] as $pattern) {
        if (preg_match($pattern, $contents, $matches)) {
            return stripcslashes($matches[1]);
        }
    }
    return '';
}

function openapScanWifiNetworksWithIw(string $interface): array
{
    exec(sprintf('sudo /usr/local/sbin/openap-scan-wifi %s 2>/dev/null', escapeshellarg($interface)), $output, $return);
    if ($return !== 0 || count($output) === 0) {
        return [];
    }

    $networks = [];
    $current = null;
    foreach ($output as $line) {
        $trimmed = trim($line);
        if (preg_match('/^BSS\s+([0-9a-fA-F:]+)/', $trimmed, $matches)) {
            if ($current !== null && ($current['ssid'] ?? '') !== '') {
                $networks = openapMergeScanNetwork($networks, $current);
            }
            $current = [
                'bssid' => strtolower($matches[1]),
                'frequency' => '-',
                'channel' => '-',
                'band' => '-',
                'signal' => '-100',
                'security' => 'open',
                'ssid' => '',
            ];
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^freq:\s+(\d+)/', $trimmed, $matches)) {
            $frequency = (int) $matches[1];
            $current['frequency'] = (string) $frequency;
            $current['channel'] = openapFrequencyChannel($frequency);
            $current['band'] = $frequency >= 5000 ? '5 GHz' : '2.4 GHz';
        } elseif (preg_match('/^signal:\s+(-?\d+(?:\.\d+)?)\s+dBm/', $trimmed, $matches)) {
            $current['signal'] = (string) round((float) $matches[1]);
        } elseif (preg_match('/^SSID:\s*(.*)$/', $trimmed, $matches)) {
            $current['ssid'] = trim($matches[1]);
        } elseif ($trimmed === 'RSN:' || str_starts_with($trimmed, 'RSN:')) {
            $current['security'] = 'WPA2';
        } elseif ($trimmed === 'WPA:' || str_starts_with($trimmed, 'WPA:')) {
            $current['security'] = 'WPA';
        } elseif (preg_match('/^(?:\*\s*)?Authentication suites:\s*(.+)$/i', $trimmed, $matches)) {
            $suites = strtoupper($matches[1]);
            if (preg_match('/\bSAE\b/', $suites)) {
                $current['security'] = preg_match('/\bPSK\b/', $suites) ? 'WPA2/WPA3' : 'WPA3';
            }
        }
    }

    if ($current !== null && ($current['ssid'] ?? '') !== '') {
        $networks = openapMergeScanNetwork($networks, $current);
    }
    return $networks;
}

function openapMergeWifiSecurity(string $first, string $second): string
{
    $values = array_map('strtoupper', [$first, $second]);
    $hasWpa2 = in_array('WPA2', $values, true) || in_array('WPA2/WPA3', $values, true);
    $hasWpa3 = in_array('WPA3', $values, true) || in_array('WPA2/WPA3', $values, true);
    if ($hasWpa2 && $hasWpa3) {
        return 'WPA2/WPA3';
    }
    if ($hasWpa3) {
        return 'WPA3';
    }
    if ($hasWpa2) {
        return 'WPA2';
    }
    if (in_array('WPA', $values, true)) {
        return 'WPA';
    }
    return 'open';
}

function openapMergeScanNetwork(array $networks, array $entry): array
{
    $key = $entry['ssid'];
    if (!isset($networks[$key])) {
        $networks[$key] = $entry;
        return $networks;
    }

    $security = openapMergeWifiSecurity(
        (string) ($networks[$key]['security'] ?? 'open'),
        (string) ($entry['security'] ?? 'open')
    );
    if ((int) $entry['signal'] > (int) $networks[$key]['signal']) {
        $networks[$key] = $entry;
    }
    $networks[$key]['security'] = $security;
    return $networks;
}

function openapCachedWifiNetworks(string $interface, bool $force = false): array
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return [];
    }

    $cacheFile = sys_get_temp_dir() . '/openap-scan-' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', $interface) . '.json';
    $cached = [];
    if (is_readable($cacheFile)) {
        $parsedCache = json_decode((string) file_get_contents($cacheFile), true);
        $cached = is_array($parsedCache) ? $parsedCache : [];
    }
    if (!$force && $cached !== [] && filemtime($cacheFile) !== false && time() - filemtime($cacheFile) < 30) {
        return openapDecorateWifiNetworks($cached);
    }

    $networks = openapScanWifiNetworks($interface);
    if ($networks === [] && $cached !== []) {
        // Some USB drivers intermittently return an empty scan while the
        // managed interface remains associated. Preserve and re-decorate the
        // last complete result instead of replacing it with a one-row fallback.
        $networks = openapDecorateWifiNetworks($cached);
        file_put_contents($cacheFile, json_encode($networks));
        return $networks;
    }
    if (count($networks) === 0) {
        $linkOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' link 2>/dev/null', $linkOutput, $linkReturn);
        $link = implode("\n", $linkOutput);
        if ($linkReturn === 0 && preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $link, $ssidMatch)) {
            preg_match('/^Connected to[[:space:]]+([0-9a-f:]+)/mi', $link, $bssidMatch);
            preg_match('/^[[:space:]]*freq:[[:space:]]*([0-9.]+)/m', $link, $freqMatch);
            preg_match('/^[[:space:]]*signal:[[:space:]]*(-?[0-9.]+)[[:space:]]+dBm/m', $link, $signalMatch);
            $frequency = isset($freqMatch[1]) ? (int) round((float) $freqMatch[1]) : 0;
            $activeConfig = '/etc/wpa_supplicant/wpa_supplicant-' . $interface . '.conf';
            $activeConfigContents = is_readable($activeConfig) ? (string) file_get_contents($activeConfig) : '';
            $activeSecurity = 'WPA2';
            if (preg_match('/^[[:space:]]*key_mgmt[[:space:]]*=[[:space:]]*NONE[[:space:]]*$/mi', $activeConfigContents)) {
                $activeSecurity = 'open';
            } elseif (preg_match('/^[[:space:]]*key_mgmt[[:space:]]*=[[:space:]]*(.+)$/mi', $activeConfigContents, $keyMgmtMatch)) {
                $keyMgmt = strtoupper($keyMgmtMatch[1]);
                $hasSae = preg_match('/\bSAE\b/', $keyMgmt) === 1;
                $hasPsk = preg_match('/\bWPA-PSK\b/', $keyMgmt) === 1;
                if ($hasSae) {
                    $activeSecurity = $hasPsk ? 'WPA2/WPA3' : 'WPA3';
                }
            }
            $networks[] = [
                'bssid' => $bssidMatch[1] ?? '-',
                'frequency' => $frequency > 0 ? (string) $frequency : '-',
                'channel' => $frequency > 0 ? openapFrequencyChannel($frequency) : '-',
                'band' => $frequency >= 5000 ? '5 GHz' : '2.4 GHz',
                'signal' => isset($signalMatch[1]) ? (string) round((float) $signalMatch[1]) : '-100',
                'security' => $activeSecurity,
                'ssid' => trim($ssidMatch[1]),
            ];
        }
    }
    $networks = openapDecorateWifiNetworks($networks);
    file_put_contents($cacheFile, json_encode($networks));
    return $networks;
}

function openapBuildWpaSupplicantConfig(string $ssid, string $passphrase, string $security = 'wpa'): string
{
    if ($security === 'open') {
        return "ctrl_interface=/run/wpa_supplicant\n"
            . "update_config=1\n"
            . "country=IT\n\n"
            . "network={\n"
            . "    ssid=\"" . openapWpaQuote($ssid) . "\"\n"
            . "    key_mgmt=NONE\n"
            . "}\n";
    }

    $network = openapWpaPassphraseNetwork($ssid, $passphrase);
    if ($network === '') {
        $network = "network={\n"
            . "    ssid=\"" . openapWpaQuote($ssid) . "\"\n"
            . "    psk=\"" . openapWpaQuote($passphrase) . "\"\n"
            . "    key_mgmt=WPA-PSK\n"
            . "}\n";
    }

    return "ctrl_interface=/run/wpa_supplicant\n"
        . "update_config=1\n"
        . "country=IT\n\n"
        . $network;
}

function openapWpaQuote(string $value): string
{
    return str_replace(["\\", "\""], ["\\\\", "\\\""], $value);
}

function openapWpaPassphraseNetwork(string $ssid, string $passphrase): string
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open('wpa_passphrase ' . escapeshellarg($ssid), $descriptor, $pipes);
    if (!is_resource($process)) {
        return '';
    }

    fwrite($pipes[0], $passphrase . "\n");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $return = proc_close($process);
    if ($return !== 0 || !is_string($output)) {
        return '';
    }

    return rtrim($output) . "\n";
}

function openapFormatWifiSecurity(string $flags): string
{
    if (str_contains($flags, 'WPA3') || str_contains($flags, 'SAE')) {
        return 'WPA3';
    }
    if (str_contains($flags, 'WPA2') || str_contains($flags, 'RSN')) {
        return 'WPA2';
    }
    if (str_contains($flags, 'WPA')) {
        return 'WPA';
    }
    return 'open';
}

function openapFrequencyChannel(int $frequency): string
{
    if ($frequency >= 2412 && $frequency <= 2472) {
        return (string) (($frequency - 2407) / 5);
    }
    if ($frequency === 2484) {
        return '14';
    }
    if ($frequency >= 5000 && $frequency <= 5900) {
        return (string) (($frequency - 5000) / 5);
    }
    return '-';
}

function openapReadSysfsValue(string $path): ?string
{
    if (!is_readable($path)) {
        return null;
    }

    $value = @file_get_contents($path);
    return $value === false ? null : trim($value);
}

function openapDetectEthernetInterfaces(): array
{
    $interfaces = [];
    foreach (glob('/sys/class/net/*') ?: [] as $path) {
        $iface = basename($path);
        if ($iface === 'lo' || str_starts_with($iface, 'wl') || str_starts_with($iface, 'wlan')) {
            continue;
        }
        if (preg_match('/^(docker\d*|br-[0-9a-f]+|veth|tap|neko-)/', $iface)) {
            continue;
        }
        // A bridge can report carrier while its physical uplink is
        // disconnected. Ethernet Mode must reflect a real wired carrier,
        // not the state of an OpenAP-created virtual bridge.
        if (is_dir($path . '/bridge')) {
            continue;
        }
        $typePath = $path . '/type';
        if (openapReadSysfsValue($typePath) !== '1') {
            continue;
        }
        $carrierPath = $path . '/carrier';
        $carrier = openapReadSysfsValue($carrierPath);
        if ($carrier !== null) {
            $hasCarrier = $carrier === '1';
        } else {
            // Some drivers expose carrier but reject reads with EINVAL. In
            // that case operstate is the safe, warning-free fallback.
            $hasCarrier = openapReadSysfsValue($path . '/operstate') === 'up';
        }
        $interfaces[] = [
            'name' => $iface,
            'mac' => openapInterfaceMac($iface),
            'ip' => openapInterfaceIpv4($iface),
            'carrier' => $hasCarrier ? 'up' : 'down',
        ];
    }
    return $interfaces;
}

function openapDetermineRepeaterMode(array $profile, array $wifi, array $ethernet): array
{
    $current = $profile['mode']['current'] ?? 'unknown';
    $wifiCount = count($wifi);
    $usableAp = array_values(array_filter($wifi, fn($iface) => !empty($iface['supports_ap'])));
    $usableManaged = array_values(array_filter($wifi, fn($iface) => !empty($iface['supports_managed'])));
    $ethernetUp = array_values(array_filter($ethernet, fn($iface) => $iface['carrier'] === 'up'));
    $rolesValid = openapRepeaterRolesValid($profile, $wifi);

    $recommended = 'degraded';
    $notice = 'Repeater requirements are not fully satisfied.';
    $action = 'Check wireless and ethernet interfaces.';

    if (count($usableAp) >= 1 && count($ethernetUp) >= 1 && $wifiCount < 2) {
        $recommended = 'ap_ethernet';
        $notice = 'One WiFi interface and ethernet uplink detected.';
        $action = 'AP over ethernet is the recommended mode.';
    }

    if (count($usableAp) >= 1 && count($usableManaged) >= 2) {
        if ($current === 'ap_ethernet') {
            $recommended = 'ap_ethernet';
            $notice = 'A second WiFi interface was detected.';
            $action = 'Offer a prompt to configure WiFi repeater mode.';
        } elseif ($rolesValid) {
            $recommended = 'repeater_wifi';
            $notice = 'Two WiFi interfaces are available and configured roles are valid.';
            $action = 'WiFi repeater mode is available.';
        } else {
            $recommended = 'degraded';
            $notice = 'Two WiFi interfaces are available, but configured roles are invalid.';
            $action = 'Run role assignment before applying repeater mode.';
        }
    }

    if ($current === 'repeater_wifi' && !$rolesValid && count($ethernetUp) >= 1 && count($usableAp) >= 1) {
        $recommended = 'ap_ethernet';
        $notice = 'Repeater mode is degraded, but ethernet fallback is available.';
        $action = 'Offer fallback to AP over ethernet.';
    }

    return [
        'current' => $current,
        'recommended' => $recommended,
        'wifi_count' => (string) $wifiCount,
        'ethernet_count' => (string) count($ethernetUp),
        'notice' => $notice,
        'action' => $action,
    ];
}

function openapReadRepeaterProfile(): array
{
    $profilePath = defined('OPENAP_REPEATER_PROFILE') ? OPENAP_REPEATER_PROFILE : '';
    if ($profilePath === '' || !is_readable($profilePath)) {
        return [];
    }
    $profile = parse_ini_file($profilePath, true);
    return is_array($profile) ? $profile : [];
}

function openapDetectWirelessInterfaces(): array
{
    $interfaces = [];
    exec('iw dev 2>/dev/null', $output, $return);
    if ($return !== 0) {
        return $interfaces;
    }

    $current = null;
    $currentPhy = '-';
    foreach ($output as $line) {
        $trimmed = trim($line);
        if (preg_match('/^phy#(\d+)$/', $trimmed, $matches)) {
            $currentPhy = 'phy' . $matches[1];
            continue;
        }
        if (preg_match('/^Interface\s+(\S+)$/', $trimmed, $matches)) {
            $current = $matches[1];
            $interfaces[$current] = [
                'name' => $current,
                'mac' => openapInterfaceMac($current),
                'type' => '-',
                'phy' => $currentPhy,
                'ssid' => '-',
                'channel' => '-',
                'frequency' => '-',
                'supports_ap' => false,
                'supports_managed' => false,
            ];
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^type\s+(\S+)$/', $trimmed, $matches)) {
            $interfaces[$current]['type'] = $matches[1];
        } elseif (preg_match('/^wiphy\s+(\d+)$/', $trimmed, $matches)) {
            $interfaces[$current]['phy'] = 'phy' . $matches[1];
        } elseif (preg_match('/^ssid\s+(.+)$/', $trimmed, $matches)) {
            $interfaces[$current]['ssid'] = $matches[1];
        } elseif (preg_match('/^channel\s+(\d+)\s+\((\d+)\s+MHz\)/', $trimmed, $matches)) {
            $interfaces[$current]['channel'] = $matches[1];
            $interfaces[$current]['frequency'] = $matches[2] . ' MHz';
        }
    }

    foreach ($interfaces as $iface => $data) {
        $modes = openapPhyModes($data['phy']);
        $interfaces[$iface]['supports_ap'] = in_array('AP', $modes, true);
        $interfaces[$iface]['supports_managed'] = in_array('managed', $modes, true);
    }

    ksort($interfaces);
    return array_values($interfaces);
}

function openapInterfaceMac(string $interface): string
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return '-';
    }
    $path = '/sys/class/net/' . $interface . '/address';
    if (!is_readable($path)) {
        return '-';
    }
    return trim((string) file_get_contents($path));
}

function openapPhyModes(string $phy): array
{
    if (!preg_match('/^phy\d+$/', $phy)) {
        return [];
    }
    exec('iw phy ' . escapeshellarg($phy) . ' info 2>/dev/null', $output, $return);
    if ($return !== 0) {
        return [];
    }

    $modes = [];
    $inModes = false;
    foreach ($output as $line) {
        if (strpos($line, 'Supported interface modes:') !== false) {
            $inModes = true;
            continue;
        }
        if ($inModes && preg_match('/^\s+\*\s+(.+)$/', $line, $matches)) {
            $modes[] = trim($matches[1]);
            continue;
        }
        if ($inModes && trim($line) !== '' && strpos(trim($line), '*') !== 0) {
            break;
        }
    }
    return $modes;
}

function openapRepeaterRolesValid(array $profile, array $detected): bool
{
    $stored = @parse_ini_file('/etc/openap/wifi-roles.ini', true, INI_SCANNER_RAW);
    $model = openapBuildWifiRoleModel($profile, is_array($stored) ? $stored : [], $detected);
    return !empty(openapEvaluateRepeaterAvailability($model['active'], $detected)['valid']);
}

function openapCommandKeyValues(string $command): array
{
    exec($command, $output, $return);
    if ($return !== 0) {
        return [];
    }
    $values = [];
    foreach ($output as $line) {
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }
    return $values;
}

function openapServiceActive(string $service): string
{
    if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $service)) {
        return 'unknown';
    }
    exec('/bin/systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null', $output, $return);
    return $return === 0 ? 'active' : 'inactive';
}

function openapHostapdConfigValue(string $key): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $key) || !is_readable(OPENAP_HOSTAPD_CONFIG)) {
        return '-';
    }
    foreach (file(OPENAP_HOSTAPD_CONFIG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, $key . '=')) {
            return substr($line, strlen($key) + 1);
        }
    }
    return '-';
}

function openapInterfaceIpv4(string $interface): string
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $interface)) {
        return '-';
    }
    exec('ip -4 -o addr show dev ' . escapeshellarg($interface), $output, $return);
    if ($return !== 0 || empty($output[0])) {
        return '-';
    }
    if (preg_match('/inet\s+([0-9.]+)/', $output[0], $matches)) {
        return $matches[1];
    }
    return '-';
}

function openapDhcpRange(): string
{
    $file = '/etc/dnsmasq.d/openap-repeater.conf';
    if (!is_readable($file) && defined('OPENAP_SKYNET_EXISTING_AP') && OPENAP_SKYNET_EXISTING_AP) {
        $file = '/etc/dnsmasq.d/wifi.conf';
    }
    if (!is_readable($file)) {
        return '-';
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, 'dhcp-range=')) {
            $parts = explode(',', substr($line, strlen('dhcp-range=')));
            if (count($parts) >= 2) {
                return $parts[0] . ' - ' . $parts[1];
            }
        }
    }
    return '-';
}

function openapDhcpPoolInfo(): array
{
    $result = ['active' => 0, 'total' => 0, 'range_start' => '-', 'range_end' => '-', 'lease_time' => '-', 'dns' => '-', 'upstream_dns' => '-', 'dns_provider' => 'Custom', 'dns_transport' => 'Standard'];
    $upstreamDns = [];
    $file = '/etc/dnsmasq.d/openap-repeater.conf';
    if (!is_readable($file) && defined('OPENAP_SKYNET_EXISTING_AP') && OPENAP_SKYNET_EXISTING_AP) {
        $file = '/etc/dnsmasq.d/wifi.conf';
    }
    if (is_readable($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with($line, 'dhcp-range=')) {
                $parts = array_map('trim', explode(',', substr($line, 11)));
                $result['range_start'] = $parts[0] ?? '-';
                $result['range_end'] = $parts[1] ?? '-';
                $result['lease_time'] = end($parts) ?: '-';
            } elseif (str_starts_with($line, 'dhcp-option=6,')) {
                $result['dns'] = trim(substr($line, 14));
            } elseif (str_starts_with($line, 'server=')) {
                $upstreamDns[] = trim(substr($line, 7));
            }
        }
    }
    $result['upstream_dns'] = $upstreamDns !== [] ? implode(', ', $upstreamDns) : '-';
    $normalizedUpstream = implode(',', $upstreamDns);
    if ($normalizedUpstream === '1.1.1.1,1.0.0.1') {
        $result['dns_provider'] = 'Cloudflare';
    } elseif ($normalizedUpstream === '9.9.9.9,149.112.112.112') {
        $result['dns_provider'] = 'Quad9';
    } elseif ($normalizedUpstream === '8.8.8.8,8.8.4.4') {
        $result['dns_provider'] = 'Google';
    }
    $encryptedState = is_readable('/etc/openap/encrypted-dns.ini')
        ? parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_TYPED)
        : [];
    if (!empty($encryptedState['encrypted_dns']['enabled'])) {
        $provider = (string) ($encryptedState['encrypted_dns']['provider'] ?? '');
        $result['dns_provider'] = $provider === 'quad9' ? 'Quad9' : ($provider === 'cloudflare' ? 'Cloudflare' : 'Custom');
        $result['dns_transport'] = 'Encrypted · DoH';
        $result['upstream_dns'] = $provider === 'quad9' ? 'Quad9 DoH' : ($provider === 'cloudflare' ? 'Cloudflare DoH' : '127.0.2.1');
    }
    $start = ip2long($result['range_start']);
    $end = ip2long($result['range_end']);
    if ($start !== false && $end !== false && $end >= $start) {
        $result['total'] = $end - $start + 1;
    }
    $leases = '/var/lib/misc/dnsmasq.leases';
    if (is_readable($leases)) {
        $now = time();
        foreach (file($leases, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $expires = (int) strtok($line, ' ');
            if ($expires === 0 || $expires > $now) $result['active']++;
        }
    }
    return $result;
}

function openapNatActive(string $egressInterface = ''): bool
{
    exec('sudo /usr/sbin/nft list ruleset 2>/dev/null', $output, $return);
    $profile = openapReadRepeaterProfile();
    $subnet = (string) ($profile['network']['subnet'] ?? '10.88.77.0/24');
    if ($return === 0) {
        $ruleset = implode("\n", $output);
        if ($egressInterface !== '') {
            if (strpos($ruleset, 'oifname "' . $egressInterface . '" ip saddr ' . $subnet . ' masquerade') !== false) {
                return true;
            }
        } elseif (strpos($ruleset, 'ip saddr ' . $subnet . ' masquerade') !== false) {
            return true;
        }
    }

    if (!(defined('OPENAP_SKYNET_EXISTING_AP') && OPENAP_SKYNET_EXISTING_AP)) {
        return false;
    }

    $apInterface = defined('OPENAP_WIFI_AP_INTERFACE') ? (string) OPENAP_WIFI_AP_INTERFACE : 'wlan0';
    $routeOutput = [];
    exec('/sbin/ip -4 route show dev ' . escapeshellarg($apInterface) . ' scope link 2>/dev/null', $routeOutput, $routeReturn);
    $legacySubnet = '';
    if ($routeReturn === 0 && preg_match('/^([0-9.]+\/[0-9]+)/', implode("\n", $routeOutput), $matches)) {
        $legacySubnet = $matches[1];
    }
    if ($legacySubnet === '') {
        return false;
    }

    $iptablesOutput = [];
    exec('sudo /usr/sbin/iptables -t nat -S 2>/dev/null', $iptablesOutput, $iptablesReturn);
    if ($iptablesReturn !== 0) {
        return false;
    }
    $iptablesRules = implode("\n", $iptablesOutput);
    $sourceRule = '-s ' . $legacySubnet;
    if ($egressInterface !== '') {
        return strpos($iptablesRules, $sourceRule) !== false
            && strpos($iptablesRules, '-o ' . $egressInterface) !== false
            && strpos($iptablesRules, '-j MASQUERADE') !== false;
    }
    return strpos($iptablesRules, $sourceRule) !== false
        && strpos($iptablesRules, '-j MASQUERADE') !== false;
}

function openapFormatFrequency(string $frequency): string
{
    if ($frequency === '') {
        return '-';
    }
    return $frequency . ' MHz';
}
