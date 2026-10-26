<?php
$loggingServices = [
    'hostapd' => ['title' => 'hostapd', 'icon' => 'fas fa-broadcast-tower'],
    'dnsmasq' => ['title' => 'dnsmasq', 'icon' => 'fas fa-exchange-alt'],
    'lighttpd' => ['title' => 'lighttpd', 'icon' => 'fas fa-globe'],
];
if (is_file('/etc/dnscrypt-proxy/dnscrypt-proxy.toml')) {
    $loggingServices['dnscrypt-proxy'] = [
        'title' => 'dnscrypt-proxy',
        'icon' => 'fas fa-shield-alt',
    ];
}
$statusServices = [
    'hostapd' => ['hostapd', htmlspecialchars($interface).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
    'lighttpd' => ['lighttpd', 'Web server'],
];
$dhcpActive = (int) ($dhcpPool['active'] ?? 0);
$dhcpTotal = (int) ($dhcpPool['total'] ?? 150);
$dhcpRange = ($dhcpPool['range_start'] ?? '10.88.77.50').' - '.($dhcpPool['range_end'] ?? '10.88.77.200');
$dhcpLeaseTime = $dhcpPool['lease_time'] ?? '12h';
$dhcpDns = $dhcpPool['dns'] ?? '10.88.77.1';
$dhcpPercent = $dhcpTotal > 0 ? min(100, (int) round(($dhcpActive / $dhcpTotal) * 100)) : 0;
$loggingUplinkState = $uplinkDetails['wpa_state'] ?? ($isRepeaterWifi ? 'DISCONNECTED' : 'COMPLETED');
$loggingUplinkConnected = $isRepeaterWifi ? ($loggingUplinkState === 'COMPLETED') : true;
$loggingTopologyHealthy = $hostapdEnabled
    && $loggingUplinkConnected
    && (bool) ($serviceList['dnsmasq'] ?? false)
    && (bool) ($serviceList['nftables'] ?? false)
    && (bool) ($serviceList['lighttpd'] ?? false);
if ($currentMode === 'ap_ethernet_bridge') {
    $loggingTopologyHealthy = $hostapdEnabled && $loggingUplinkConnected && (bool) ($serviceList['lighttpd'] ?? false);
}
$loggingModeIcon = in_array($currentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true) ? 'fa-network-wired' : 'fa-wifi';
$loggingModeLabel = $currentMode === 'ap_ethernet_bridge' ? _('Ethernet Bridge') : ($currentMode === 'ap_ethernet' ? _('Ethernet Mode') : _('Repeater'));
$loggingLiveState = !$hostapdEnabled ? 'offline' : ($loggingTopologyHealthy ? 'live' : 'degraded');
?>
<div class="container-fluid p-0 openap-ap-configuration-page openap-logging-page">
  <?php $status->showMessages(); ?>
  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <div class="openap-topology-header openap-page-main-header openap-dashboard-main-header openap-logging-main-header">
        <div class="openap-topology-header-title"><span class="openap-section-heading-icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></span><div><strong><?php echo _('Logging'); ?></strong></div></div>
      </div>
      <div class="card shadow openap-logging-shell">
        <div class="openap-logging-body">
          <div class="openap-log-tabs" role="tablist" aria-label="<?php echo _('Service logs'); ?>">
            <?php $firstTab = true; foreach ($loggingServices as $serviceKey => $service): ?>
            <button type="button" class="openap-log-tab<?php echo $firstTab ? ' active' : ''; ?>" id="log-tab-<?php echo $serviceKey; ?>" data-log-tab="<?php echo $serviceKey; ?>" role="tab" aria-controls="log-panel-<?php echo $serviceKey; ?>" aria-selected="<?php echo $firstTab ? 'true' : 'false'; ?>" tabindex="<?php echo $firstTab ? '0' : '-1'; ?>"><i class="<?php echo $service['icon']; ?>"></i><span><?php echo htmlspecialchars($service['title'], ENT_QUOTES); ?></span></button>
            <?php $firstTab = false; endforeach; ?>
          </div>
          <div class="openap-log-panels">
            <?php $firstPanel = true; foreach ($loggingServices as $serviceKey => $service): ?>
            <section class="openap-log-panel<?php echo $firstPanel ? ' active' : ''; ?>" id="log-panel-<?php echo $serviceKey; ?>" data-log-panel="<?php echo $serviceKey; ?>" role="tabpanel" aria-labelledby="log-tab-<?php echo $serviceKey; ?>"<?php echo $firstPanel ? '' : ' hidden'; ?>>
              <div class="openap-log-toolbar"><div><strong><?php echo htmlspecialchars($service['title'], ENT_QUOTES); ?>.service</strong><small><?php echo _('Latest 240 journal entries'); ?></small></div><button type="button" class="openap-log-copy" data-copy-log="<?php echo $serviceKey; ?>"><i class="far fa-copy"></i><span><?php echo _('Copy log'); ?></span></button></div>
              <pre class="openap-log-output" id="log-<?php echo $serviceKey; ?>" tabindex="0"><?php echo htmlspecialchars($serviceLogs[$serviceKey] ?? _('No recent log entries.'), ENT_QUOTES); ?></pre>
            </section>
            <?php $firstPanel = false; endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4"><?php echo openapWidgetArea('logging'); ?></div>
  </div>
</div>
