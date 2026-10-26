<?php

if (!defined('OPENAP_CONFIG')) {
    define('OPENAP_CONFIG', '/etc/openap');
}

$defaults = [
    'OPENAP_BRAND_TEXT' => 'OpenAP',
    'OPENAP_BRAND_TITLE' => OPENAP_BRAND_TEXT.' Admin Panel',
    'OPENAP_VERSION' => '0.2.5.1',
    'APTV_VERSION' => '1.0',
    'OPENAP_UPDATE_ENABLED' => false,
    'OPENAP_CONFIG_NETWORK' => OPENAP_CONFIG.'/networking/defaults.json',
    'OPENAP_CONFIG_PROVIDERS' => 'config/vpn-providers.json',
    'OPENAP_CONFIG_API' => OPENAP_CONFIG.'/api',
    'OPENAP_ADMIN_DETAILS' => OPENAP_CONFIG.'/openap.auth',
    'OPENAP_WIFI_AP_INTERFACE' => 'wlan0',
    'OPENAP_CACHE_PATH' => sys_get_temp_dir() . '/raspap',
    'OPENAP_ERROR_LOG' => sys_get_temp_dir() . '/raspap_error.log',
    'OPENAP_DEBUG_LOG' => 'raspap_debug.log',
    'OPENAP_LOG_SIZE_LIMIT' =>  64,
    'OPENAP_SESSION_TIMEOUT' => 1440,
    'OPENAP_THEMES' => [
        "default" => [
            "name" => "OpenAP (default)",
            "url" => "app/css/themes/default.php",
            "modes" => ["light", "dark"]
        ],
        "hackernews" => [
            "name" => "HackerNews",
            "url" => "app/css/themes/hackernews.css",
            "modes" => ["light"]
        ]   
    ],

    // Constants for configuration file paths.
    // These are typical for default RPi installs. Modify if needed.
    'OPENAP_DNSMASQ_LEASES' => '/var/lib/misc/dnsmasq.leases',
    'OPENAP_DNSMASQ_PREFIX' => '/etc/dnsmasq.d/090_',
    'OPENAP_ADBLOCK_LISTPATH' => '/etc/openap/adblock/',
    'OPENAP_ADBLOCK_CONFIG' =>  OPENAP_DNSMASQ_PREFIX.'adblock.conf',
    'OPENAP_HOSTAPD_CONFIG' => '/etc/hostapd/hostapd.conf',
    'OPENAP_DHCPCD_CONFIG' => '/etc/dhcpcd.conf',
    'OPENAP_DHCPCD_LOG' => '/var/log/dnsmasq.log',
    'OPENAP_WPA_SUPPLICANT_CONFIG' => '/etc/wpa_supplicant/wpa_supplicant.conf',
    'OPENAP_HOSTAPD_CTRL_INTERFACE' => '/var/run/hostapd',
    'OPENAP_WPA_CTRL_INTERFACE' => '/var/run/wpa_supplicant',
    'OPENAP_OPENVPN_CLIENT_PATH' => '/etc/openvpn/client/',
    'OPENAP_OPENVPN_CLIENT_CONFIG' => '/etc/openvpn/client/client.conf',
    'OPENAP_OPENVPN_CLIENT_LOGIN' => '/etc/openvpn/client/login.conf',
    'OPENAP_WIREGUARD_PATH' => '/etc/wireguard/',
    'OPENAP_WIREGUARD_CONFIG' => OPENAP_WIREGUARD_PATH.'wg0.conf',
    'OPENAP_IPTABLES_CONF' => OPENAP_CONFIG.'/networking/iptables_rules.json',
    'OPENAP_TORPROXY_ENABLED' => false,
    'OPENAP_TORPROXY_CONFIG' => '/etc/tor/torrc',
    'OPENAP_LIGHTTPD_CONFIG' => '/etc/lighttpd/lighttpd.conf',
    'OPENAP_ACCESS_CHECK_IP' => '1.1.1.1',
    'OPENAP_ACCESS_CHECK_DNS' => 'one.one.one.one',

    // Captive portal detection - returns 204 or 200 is successful
    'OPENAP_ACCESS_CHECK_URL' => 'http://detectportal.firefox.com',
    'OPENAP_ACCESS_CHECK_URL_CODE' => 200,

    // Constants for the 5GHz wireless regulatory domain
    'OPENAP_5GHZ_CHANNEL_MIN' => 100,
    'OPENAP_5GHZ_CHANNEL_MAX' => 192,

    // Enable basic authentication for the web admin.
    'OPENAP_AUTH_ENABLED' => true,

    // Optional services, set to true to enable.
    'OPENAP_WIFICLIENT_ENABLED' => true,
    'OPENAP_HOTSPOT_ENABLED' => true,
    'OPENAP_NETWORK_ENABLED' => true,
    'OPENAP_DHCP_ENABLED' => true,
    'OPENAP_ADBLOCK_ENABLED' => false,
    'OPENAP_OPENVPN_ENABLED' => false,
    'OPENAP_VPN_PROVIDER_ENABLED' => false,
    'OPENAP_WIREGUARD_ENABLED' => false,
    'OPENAP_CONFAUTH_ENABLED' => true,
    'OPENAP_CHANGETHEME_ENABLED' => true,
    'OPENAP_VNSTAT_ENABLED' => true,
    'OPENAP_SYSTEM_ENABLED' => true,
    'OPENAP_MONITOR_ENABLED' => false,
    'OPENAP_RESTAPI_ENABLED' => false,
    'OPENAP_PLUGINS_ENABLED' => false,

    // Locale settings
    'LOCALE_ROOT' => 'locale',
    'LOCALE_DOMAIN' => 'messages'
];

foreach ($defaults as $setting => $value) {
    if (!defined($setting)) {
        define($setting, $value);
    }
}

unset($defaults, $setting, $value);
