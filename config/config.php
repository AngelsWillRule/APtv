<?php

define('OPENAP_BRAND_TEXT', 'OpenAP');
define('OPENAP_BRAND_TITLE', OPENAP_BRAND_TEXT.' Admin Panel');
define('OPENAP_CONFIG', '/etc/openap');
define('OPENAP_CONFIG_NETWORK', OPENAP_CONFIG.'/networking/defaults.json');
define('OPENAP_CONFIG_PROVIDERS', 'config/vpn-providers.json');
define('OPENAP_CONFIG_API', OPENAP_CONFIG.'/api');
define('OPENAP_ADMIN_DETAILS', OPENAP_CONFIG.'/openap.auth');
define('OPENAP_WIFI_AP_INTERFACE', 'wlan0');
define('OPENAP_CACHE_PATH', sys_get_temp_dir() . '/raspap');
define('OPENAP_ERROR_LOG', sys_get_temp_dir() . '/raspap_error.log');
define('OPENAP_DEBUG_LOG', 'raspap_debug.log');
define('OPENAP_LOG_SIZE_LIMIT', 64);
define('OPENAP_SESSION_TIMEOUT', 1440);

// Constants for configuration file paths.
// These are typical for default RPi installs. Modify if needed.
define('OPENAP_DNSMASQ_LEASES', '/var/lib/misc/dnsmasq.leases');
define('OPENAP_DNSMASQ_PREFIX', '/etc/dnsmasq.d/090_');
define('OPENAP_ADBLOCK_LISTPATH', '/etc/openap/adblock/');
define('OPENAP_ADBLOCK_CONFIG', OPENAP_DNSMASQ_PREFIX.'adblock.conf');
define('OPENAP_HOSTAPD_CONFIG', '/etc/hostapd/hostapd.conf');
define('OPENAP_DHCPCD_CONFIG', '/etc/dhcpcd.conf');
define('OPENAP_DHCPCD_LOG', '/var/log/dnsmasq.log');
define('OPENAP_WPA_SUPPLICANT_CONFIG', '/etc/wpa_supplicant/wpa_supplicant.conf');
define('OPENAP_HOSTAPD_CTRL_INTERFACE', '/var/run/hostapd');
define('OPENAP_WPA_CTRL_INTERFACE', '/var/run/wpa_supplicant');
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

// Captive portal detection - returns 204 or 200 is successful
define('OPENAP_ACCESS_CHECK_URL', 'http://detectportal.firefox.com');
define('OPENAP_ACCESS_CHECK_URL_CODE', 200);

// Constant for the 5GHz wireless regulatory domain
define("OPENAP_5GHZ_CHANNEL_MIN", 100);
define("OPENAP_5GHZ_CHANNEL_MAX", 192);

// Enable basic authentication for the web admin.
define('OPENAP_AUTH_ENABLED', true);

// Optional services, set to true to enable.
define('OPENAP_WIFICLIENT_ENABLED', true);
define('OPENAP_HOTSPOT_ENABLED', true);
define('OPENAP_NETWORK_ENABLED', true);
define('OPENAP_DHCP_ENABLED', true);
define('OPENAP_ADBLOCK_ENABLED', false);
define('OPENAP_OPENVPN_ENABLED', false);
define('OPENAP_VPN_PROVIDER_ENABLED', false);
define('OPENAP_WIREGUARD_ENABLED', false);
define('OPENAP_TORPROXY_ENABLED', false);
define('OPENAP_CONFAUTH_ENABLED', true);
define('OPENAP_CHANGETHEME_ENABLED', true);
define('OPENAP_VNSTAT_ENABLED', true);
define('OPENAP_SYSTEM_ENABLED', true);
define('OPENAP_MONITOR_ENABLED', false);
define('OPENAP_RESTAPI_ENABLED', false);
define('OPENAP_PLUGINS_ENABLED', false);
define('OPENAP_UI_STATIC_LOGO', false);

// Locale settings
define('LOCALE_ROOT', 'locale');
define('LOCALE_DOMAIN', 'messages');
