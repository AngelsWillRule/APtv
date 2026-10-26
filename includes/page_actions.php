<?php

$pluginManager = \OpenAP\Plugins\PluginManager::getInstance();

// Get the requested page
$extraFooterScripts = array();
$page = $_SERVER['PATH_INFO'] ?? '';

// Check if any plugin wants to handle the request
if (!$pluginManager->handlePageAction($page)) {
    // If no plugin is available fall back to core page action handlers
    handleCorePageAction($page, $extraFooterScripts);
}

/**
 * Core application page handling
 *
 * @param string $page
 * @param array $extraFooterScripts
 * @return void
 */
function handleCorePageAction(string $page, array &$extraFooterScripts): void
{
    switch ($page) {
        case "/api/health":
            require_once "includes/api_health.php";
            openapServeHealthJson();
            break;

        case "/api/scan":
            require_once "includes/api_scan.php";
            break;

        case "/" ?? "/dashboard":
            DisplayDashboard();
            break;
        case "/clients":
            DisplayHotspotClients();
            break;
        case "/ap_configuration":
            DisplayApConfiguration();
            break;
        case "/dhcp_setting":
            DisplayDhcpSetting();
            break;
        case "/logging":
            DisplayLogging();
            break;
        case "/aptv":
        case "/iptv":
            DisplayIptv();
            break;
        case "/aptv/api/provider/index":
        case "/iptv/api/provider/index":
            openapIptvIndexProvider();
            break;
        case "/repeater":
            DisplayDashboard();
            break;
        case "/ap_wizard":
            defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER ? DisplayApWizard() : DisplayDashboard();
            break;
        case "/repeater_wizard":
            defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER ? DisplayRepeaterWizard() : DisplayDashboard();
            break;
        case "/uplink_wizard":
            defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER ? DisplayUplinkWizard() : DisplayDashboard();
            break;
        case "/dhcpd_conf":
            featureEnabled('OPENAP_DHCP_ENABLED') ? DisplayDHCPConfig() : DisplayDashboard();
            break;
        case "/wpa_conf":
            featureEnabled('OPENAP_WIFICLIENT_ENABLED') ? DisplayWPAConfig() : DisplayDashboard();
            break;
        case "/network_conf":
            featureEnabled('OPENAP_NETWORK_ENABLED') ? DisplayNetworkingConfig($extraFooterScripts) : DisplayDashboard();
            break;
        case "/hostapd_conf":
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                header('Location: /ap_configuration', true, 303);
                exit;
            }
            DisplayHostAPDConfig();
            break;
        case "/adblock_conf":
            featureEnabled('OPENAP_ADBLOCK_ENABLED') ? DisplayAdBlockConfig() : DisplayDashboard();
            break;
        case "/openvpn_conf":
            featureEnabled('OPENAP_OPENVPN_ENABLED') ? DisplayOpenVPNConfig() : DisplayDashboard();
            break;
        case "/wg_conf":
            featureEnabled('OPENAP_WIREGUARD_ENABLED') ? DisplayWireGuardConfig() : DisplayDashboard();
            break;
        case "/provider_conf":
            featureEnabled('OPENAP_VPN_PROVIDER_ENABLED') ? DisplayProviderConfig() : DisplayDashboard();
            break;
        case "/torproxy_conf":
            featureEnabled('OPENAP_TORPROXY_ENABLED') ? DisplayTorProxyConfig() : DisplayDashboard();
            break;
        case "/auth_conf":
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                DisplayAuthConfig($_SESSION['user_id']);
            } else {
                header('Location: /', true, 303);
                exit;
            }
            break;
        case "/save_hostapd_conf":
            SaveTORAndVPNConfig();
            break;
        case "/data_use":
            featureEnabled('OPENAP_VNSTAT_ENABLED') ? DisplayDataUsage($extraFooterScripts) : DisplayDashboard();
            break;
        case "/system_info":
            DisplaySystem($extraFooterScripts);
            break;
        case "/rollback":
            header('Location: /system_info?rollback=unavailable', true, 303);
            exit;

        case "/restapi_conf":
            featureEnabled('OPENAP_RESTAPI_ENABLED') ? DisplayRestAPI() : DisplayDashboard();
            break;
        case "/force_password":
            require_once "includes/force_password.php";
            DisplayForcePassword();
            break;

        case "/login":
            DisplayLogin();
            break;
        default:
            DisplayDashboard();
    }
}

function DisplayApConfiguration(): void
{
    DisplayDashboard('ap_configuration');
}

function DisplayDhcpSetting(): void
{
    DisplayDashboard('dhcp_setting');
}

function DisplayLogging(): void
{
    DisplayDashboard('logging');
}

function featureEnabled(string $constant): bool
{
    return defined($constant) && constant($constant);
}
