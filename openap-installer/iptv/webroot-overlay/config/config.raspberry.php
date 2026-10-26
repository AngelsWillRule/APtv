<?php

$openapRoleProfile = [];
if (is_readable('/etc/openap/repeater.ini')) {
    $parsedOpenapRoleProfile = parse_ini_file('/etc/openap/repeater.ini', true);
    if (is_array($parsedOpenapRoleProfile)) {
        $openapRoleProfile = $parsedOpenapRoleProfile;
    }
}
$openapApInterface = $openapRoleProfile['interfaces']['ap'] ?? 'wlan0';
$openapClientInterface = $openapRoleProfile['interfaces']['uplink'] ?? 'wlan1';
$openapSubnet = $openapRoleProfile['network']['subnet'] ?? '10.99.100.0/24';
$openapGateway = $openapRoleProfile['network']['gateway'] ?? '10.99.100.1';
$openapDhcpStart = $openapRoleProfile['network']['dhcp_start'] ?? '10.99.100.2';
$openapDhcpEnd = $openapRoleProfile['network']['dhcp_end'] ?? '10.99.100.200';

define('OPENAP_BRAND_TEXT', 'OpenAP');
define('OPENAP_BRAND_TITLE', OPENAP_BRAND_TEXT.' Admin Panel');
define('OPENAP_VERSION', '0.2.5.1');
define('OPENAP_UPDATE_ENABLED', false);
define('OPENAP_CONFIG', '/etc/openap');
define('OPENAP_CONFIG_NETWORK', OPENAP_CONFIG.'/networking/defaults.json');
define('OPENAP_CONFIG_PROVIDERS', 'config/vpn-providers.json');
define('OPENAP_CONFIG_API', OPENAP_CONFIG.'/api');
define('OPENAP_ADMIN_DETAILS', OPENAP_CONFIG.'/openap.auth');
define('OPENAP_REPEATER_PROFILE', OPENAP_CONFIG.'/repeater.ini');
define('OPENAP_WIFI_AP_INTERFACE', $openapApInterface);
define('OPENAP_WIFI_CLIENT_INTERFACE', $openapClientInterface);
define('OPENAP_CACHE_PATH', sys_get_temp_dir().'/openap');
define('OPENAP_ERROR_LOG', sys_get_temp_dir().'/openap_error.log');
define('OPENAP_DEBUG_LOG', 'openap_debug.log');
define('OPENAP_LOG_SIZE_LIMIT', 64);
define('OPENAP_SESSION_TIMEOUT', 1440);

define('OPENAP_DNSMASQ_LEASES', '/var/lib/misc/dnsmasq.leases');
define('OPENAP_DNSMASQ_PREFIX', '/etc/dnsmasq.d/');
define('OPENAP_ADBLOCK_LISTPATH', OPENAP_CONFIG.'/adblock/');
define('OPENAP_ADBLOCK_CONFIG', OPENAP_DNSMASQ_PREFIX.'adblock.conf');
define('OPENAP_HOSTAPD_CONFIG', OPENAP_CONFIG.'/hostapd/hostapd.conf');
define('OPENAP_DHCPCD_CONFIG', '/etc/dhcpcd.conf');
define('OPENAP_DHCPCD_LOG', '/var/log/dnsmasq.log');
define('OPENAP_WPA_SUPPLICANT_CONFIG', '/etc/wpa_supplicant/wpa_supplicant-'.$openapClientInterface.'.conf');
define('OPENAP_HOSTAPD_CTRL_INTERFACE', '/run/hostapd');
define('OPENAP_WPA_CTRL_INTERFACE', '/run/wpa_supplicant');
define('OPENAP_OPENVPN_CLIENT_PATH', '/etc/openvpn/client/');
define('OPENAP_OPENVPN_CLIENT_CONFIG', '/etc/openvpn/client/client.conf');
define('OPENAP_OPENVPN_CLIENT_LOGIN', '/etc/openvpn/client/login.conf');
define('OPENAP_WIREGUARD_PATH', '/etc/wireguard/');
define('OPENAP_WIREGUARD_CONFIG', OPENAP_WIREGUARD_PATH.'wg0.conf');
define('OPENAP_IPTABLES_CONF', OPENAP_CONFIG.'/networking/iptables_rules.json');
define('OPENAP_TORPROXY_CONFIG', '/etc/tor/torrc');
define('OPENAP_LIGHTTPD_CONFIG', '/etc/lighttpd/lighttpd.conf');
define('OPENAP_ACCESS_CHECK_IP', '1.1.1.1');
define('OPENAP_ACCESS_CHECK_DNS', 'one.one.one.one');
define('OPENAP_ACCESS_CHECK_URL', 'http://detectportal.firefox.com');
define('OPENAP_ACCESS_CHECK_URL_CODE', 200);

define('OPENAP_5GHZ_CHANNEL_MIN', 36);
define('OPENAP_5GHZ_CHANNEL_MAX', 165);

define('OPENAP_AUTH_ENABLED', true);
define('OPENAP_WIFICLIENT_ENABLED', false);
define('OPENAP_HOTSPOT_ENABLED', true);
define('OPENAP_NETWORK_ENABLED', false);
define('OPENAP_DHCP_ENABLED', false);
define('OPENAP_ADBLOCK_ENABLED', false);
define('OPENAP_OPENVPN_ENABLED', false);
define('OPENAP_VPN_PROVIDER_ENABLED', false);
define('OPENAP_WIREGUARD_ENABLED', false);
define('OPENAP_TORPROXY_ENABLED', false);
define('OPENAP_CONFAUTH_ENABLED', true);
define('OPENAP_CHANGETHEME_ENABLED', true);
define('OPENAP_VNSTAT_ENABLED', false);
define('OPENAP_SYSTEM_ENABLED', true);
define('OPENAP_MONITOR_ENABLED', false);
define('OPENAP_RESTAPI_ENABLED', false);
define('OPENAP_PLUGINS_ENABLED', false);
define('OPENAP_UI_STATIC_LOGO', false);

define('OPENAP_RASPBERRY_NATIVE', true);
define('OPENAP_REPEATER_CONTAINER', true);
define('OPENAP_REPEATER_SUBNET', $openapSubnet);
define('OPENAP_REPEATER_GATEWAY', $openapGateway);
define('OPENAP_REPEATER_DHCP_START', $openapDhcpStart);
define('OPENAP_REPEATER_DHCP_END', $openapDhcpEnd);
define('OPENAP_UPLINK_SERVICE', 'openap-uplink.service');

define('LOCALE_ROOT', 'locale');
define('LOCALE_DOMAIN', 'messages');
