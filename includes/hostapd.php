<?php

use OpenAP\Networking\Hotspot\HostapdManager;
use OpenAP\Networking\Hotspot\HotspotService;
use OpenAP\Networking\Hotspot\WiFiManager;
use OpenAP\Networking\Hotspot\DhcpcdManager;
use OpenAP\Messages\StatusMessage;
use OpenAP\System\Sysinfo;

$wifi = new WiFiManager();
$wifi->getWifiInterface();

/**
 * Initialize hostapd values, display interface
 *
 */
function DisplayHostAPDConfig()
{
    $reg_domain = 'GB';
    $hostapd = new HostapdManager();
    $hotspot = new HotspotService();
    $status = new StatusMessage();
    $dhcpcd = new DhcpcdManager();
    $system = new Sysinfo();
    $operatingSystem = $system->operatingSystem();

    // set hostapd defaults
    $arr80211Standard = $hotspot->get80211Standards();
    $arrSecurity = $hotspot->getSecurityModes();
    $arrEncType = $hotspot->getEncTypes();
    $arr80211w = $hotspot->get80211wOptions();
    $languageCode = strtok($_SESSION['locale'], '_');
    $countryCodes = getCountryCodes($languageCode);
    $ifaces = $hotspot->getInterfaces();
    $interfaces = [];
    if (defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER) {
        $ifaces = [OPENAP_WIFI_AP_INTERFACE];
        $_SESSION['ap_interface'] = OPENAP_WIFI_AP_INTERFACE;
        $_SESSION['wifi_client_interface'] = OPENAP_WIFI_CLIENT_INTERFACE;
        $interfaces[OPENAP_WIFI_AP_INTERFACE] = OPENAP_WIFI_AP_INTERFACE . ' (AP)';
    } else {
        foreach ($ifaces as $iface) {
            $ifaceServices = [];
            if ($iface === $_SESSION['ap_interface']) {
                $ifaceServices[] = 'AP';
            }
            if ($iface === $_SESSION['wifi_client_interface']) {
                $ifaceServices[] = 'Client';
            }
            $label = !empty($ifaceServices) ? $iface . ' (' . implode(', ', $ifaceServices) . ')' : $iface;
            $interfaces[$iface] = $label;
        }
    }
    $arrTxPower = getDefaultNetOpts('txpower','dbm');
    $managedModeEnabled = false;
    try {
        $reg_domain = $hotspot->getRegDomain();
    } catch (RuntimeException $e) {
        error_log('Failed to get regulatory domain: ' . $e->getMessage());
    }

    if (isset($_POST['interface'])) {
        $interface = $_POST['interface'];
    } else {
        $interface = $_SESSION['ap_interface'];
    }

    $txpower = $hotspot->getTxPower($interface);
    $arrHostapdConf = $hotspot->getHostapdIni();
    $arrHostapdConf += [
        'BridgedEnable' => 0,
        'WifiAPEnable' => 0,
        'RepeaterEnable' => defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER ? 1 : 0,
        'LogEnable' => 0,
        'WifiInterface' => $interface,
    ];
    $logOutput = [];

    if (!OPENAP_MONITOR_ENABLED) {
        if (isset($_POST['StartHotspot']) || isset($_POST['RestartHotspot'])) {
            $status->addMessage('Attempting to start hotspot', 'info');
            exec('sudo /bin/systemctl restart hostapd.service', $return);
            foreach ($return as $line) {
                $status->addMessage($line, 'info');
            }
        } elseif (isset($_POST['SaveHostAPDSettings'])) {
            $result = $hotspot->saveSettings(
                $_POST,
                $arrSecurity,
                $arrEncType,
                $arr80211Standard,
                $ifaces,
                $reg_domain,
                $status
            );

            // reload hostapi.ini
            $arrHostapdConf = $hotspot->getHostapdIni();
            $arrHostapdConf += [
                'BridgedEnable' => 0,
                'WifiAPEnable' => 0,
                'RepeaterEnable' => defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER ? 1 : 0,
                'LogEnable' => 0,
                'WifiInterface' => $interface,
            ];

        } elseif (isset($_POST['StopHotspot'])) {
            $status->addMessage('Attempting to stop hotspot', 'info');
            exec('sudo /bin/systemctl stop hostapd.service', $return);
            foreach ($return as $line) {
                $status->addMessage($line, 'info');
            }
        }
    }
    if (isset($_SESSION['wifi_client_interface'])) {
        exec(
            'iw dev '.escapeshellarg($_SESSION['wifi_client_interface']).
            ' link 2>/dev/null | sed -n "s/^[[:space:]]*SSID: //p"',
            $wifiNetworkID
        );
        if (!empty($wifiNetworkID[0])) {
            $managedModeEnabled = true;
        }
    }

    // process txpower user input 
    if (isset($_POST['txpower']) && (!isset($_POST['SaveHostAPDSettings']) || ($result ?? false) === true)) {
        $requestedChannel = isset($_POST['channel']) && ctype_digit((string) $_POST['channel'])
            ? (int) $_POST['channel']
            : null;
        if ($_POST['txpower'] != 'auto') {
            $txpower = intval($_POST['txpower']);
            $hotspot->maybeSetTxPower($interface, $txpower, $status, $requestedChannel);
        } elseif ($_POST['txpower'] == 'auto') {
            $hotspot->maybeSetTxPower($interface, 'auto', $status, $requestedChannel);
        }
        $txpower = $_POST['txpower'];
    }

    // parse hostapd configuration
    try {
        $arrConfig = $hostapd->getConfig();
    } catch (\RuntimeException $e) {
        error_log('Error: ' . $e->getMessage());
    }
    $arrConfig['country_code'] ??= $reg_domain;

    // bridge configuration
    if (!empty($arrHostapdConf['BridgedEnable']) && (int)$arrHostapdConf['BridgedEnable'] === 1) {
        $iface = 'br0';
        $bridgeConfig = $dhcpcd->getInterfaceConfig($iface);

        if (is_array($bridgeConfig) && !empty($bridgeConfig)) {
            $arrConfig['bridgeStaticIP'] = !empty($bridgeConfig['StaticIP'])
                ? $bridgeConfig['StaticIP']
                : '192.168.1.10';

            $arrConfig['bridgeNetmask'] = !empty($bridgeConfig['SubnetMask'])
                ? mask2cidr($bridgeConfig['SubnetMask'])
                : '24';

            $arrConfig['bridgeGateway'] = !empty($bridgeConfig['StaticRouters'])
                ? $bridgeConfig['StaticRouters']
                : '192.168.1.1';

            $arrConfig['bridgeDNS'] = !empty($bridgeConfig['StaticDNS'])
                ? $bridgeConfig['StaticDNS']
                : '192.168.1.1';
        }
    }

    // fetch hostapd logs if enabled
    if ((string)$arrHostapdConf['LogEnable'] === "1") {
        $logResult = $hotspot->getHostapdLogs(5000);
        if ($logResult['success']) {
            $joined = implode("\n", $logResult['logs']);
            $limited = getLogLimited('', $joined);
            $logOutput = explode("\n", $limited);
        }
    }

    // assign disassoc_low_ack boolean if value is set
    $arrConfig['disassoc_low_ack_bool'] = isset($arrConfig['disassoc_low_ack']) ? 1 : 0;
    $hostapdstatus = $system->hostapdStatus();
    $serviceStatus = $hostapdstatus[0] == 0 ? "down" : "up";

    echo renderTemplate(
        "hostapd", compact(
            "status",
            "serviceStatus",
            "hostapdstatus",
            "managedModeEnabled",
            "interfaces",
            "arrConfig",
            "arr80211Standard",
            "arrSecurity",
            "arrEncType",
            "arr80211w",
            "arrTxPower",
            "txpower",
            "arrHostapdConf",
            "operatingSystem",
            "countryCodes",
            "logOutput"
        )
    );
}
