<?php

function openapServeHealthJson(): void
{
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $mode = $profile['mode']['current'] ?? 'ap_ethernet';
    $apInterface = $profile['interfaces']['ap'] ?? '';
    $uplinkInterface = $profile['interfaces']['uplink'] ?? '';

    $services = [
        'hostapd' => openapServiceActive('hostapd.service') === 'active',
        'dnsmasq' => openapServiceActive('dnsmasq.service') === 'active',
        'firewall' => openapServiceActive('openap-firewall.service') === 'active',
        'lighttpd' => openapServiceActive('lighttpd.service') === 'active',
    ];

    $apReady = function_exists('openapApInterfaceReady') && openapApInterfaceReady();
    $natReady = function_exists('openapNatActive') && openapNatActive();
    $uplinkReady = true;
    $uplinkHealth = null;

    if ($mode === 'repeater_wifi') {
        $uplinkHealth = function_exists('openapUplinkHealth') ? openapUplinkHealth() : null;
        $uplinkReady = $uplinkInterface !== ''
            && is_array($uplinkHealth)
            && !empty($uplinkHealth['ready']);
        $services['openap_uplink'] = is_array($uplinkHealth) && !empty($uplinkHealth['service_active']);
    }

    if ($mode === 'ap_ethernet_bridge') {
        $services['dnsmasq'] = true;
        $services['firewall'] = true;
        $natReady = true;
    }
    $healthy = !in_array(false, $services, true) && $apReady && $natReady && $uplinkReady;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($healthy ? 200 : 503);
    echo json_encode([
        'status' => $healthy ? 'healthy' : 'degraded',
        'mode' => $mode,
        'services' => $services,
        'ap_ready' => $apReady,
        'nat_ready' => $natReady,
        'uplink_ready' => $uplinkReady,
        'uplink' => $uplinkHealth,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
