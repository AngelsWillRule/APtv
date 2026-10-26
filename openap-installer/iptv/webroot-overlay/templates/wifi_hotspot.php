<?php
$isRepeaterWifi = $isRepeaterWifi ?? false;
$uplinkState = $uplinkDetails['wpa_state'] ?? ($isRepeaterWifi ? 'DISCONNECTED' : 'COMPLETED');
$uplinkConnected = $isRepeaterWifi ? ($uplinkState === 'COMPLETED') : true;
$openapWifiHotspotCardHeader = $openapWifiHotspotCardHeader ?? false;
$openapWifiHotspotHeaderTitle = $openapWifiHotspotHeaderTitle ?? _("WiFi Hotspot");
$openapWifiHotspotHeaderIcon = $openapWifiHotspotHeaderIcon ?? 'fa-wifi';
$apRadios = array_values(array_filter($apRadios ?? [], static fn(array $radio): bool => !empty($radio['active'])));
$apRadiosByBand = [];
foreach ($apRadios as $apRadio) {
    $apRadiosByBand[(string) ($apRadio['band'] ?? '')] = $apRadio;
}
$orderedApRadios = [];
foreach (['5', '2.4'] as $orderedBand) {
    if (isset($apRadiosByBand[$orderedBand])) {
        $orderedApRadios[] = $apRadiosByBand[$orderedBand];
        unset($apRadiosByBand[$orderedBand]);
    }
}
$orderedApRadios = array_merge($orderedApRadios, array_values($apRadiosByBand));
$hotspotBandBadge = '';
if (count($orderedApRadios) > 1) {
    $hotspotBandBadge = '2.4G/5G';
} elseif ($orderedApRadios !== []) {
    $singleBand = (string) ($orderedApRadios[0]['band'] ?? '');
    $hotspotBandBadge = $singleBand === '5' ? '5G' : ($singleBand === '2.4' ? '2.4G' : $singleBand . 'G');
}
if ($openapWifiHotspotCardHeader) {
    $hotspotHeaderHealthy = $hostapdEnabled
        && $uplinkConnected
        && (bool) ($serviceList['dnsmasq'] ?? false)
        && (bool) ($serviceList['nftables'] ?? false)
        && (bool) ($serviceList['lighttpd'] ?? false);
    if ($currentMode === 'ap_ethernet_bridge') {
        $hotspotHeaderHealthy = $hostapdEnabled && $uplinkConnected && (bool) ($serviceList['lighttpd'] ?? false);
    }
    $hotspotHeaderModeIcon = in_array($currentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true) ? 'fa-network-wired' : 'fa-wifi';
    $hotspotHeaderModeLabel = $currentMode === 'ap_ethernet_bridge' ? _('Ethernet Bridge') : (in_array($currentMode, ['ap_ethernet'], true) ? _('Ethernet Mode') : _('Repeater'));
    $hotspotHeaderLiveState = !$hostapdEnabled ? 'offline' : ($hotspotHeaderHealthy ? 'live' : 'degraded');
}
$hotspotReturnPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (!in_array($hotspotReturnPath, ['/', '/ap_configuration', '/dhcp_setting'], true)) {
    $hotspotReturnPath = '/';
}
?>
<?php if ($openapWifiHotspotCardHeader): ?>
<div class="card shadow openap-wifi-hotspot-card">
  <div class="card-header openap-topology-header openap-page-main-header openap-dashboard-main-header">
    <div class="openap-topology-header-title">
      <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas <?php echo htmlspecialchars($openapWifiHotspotHeaderIcon, ENT_QUOTES); ?>"></i></span>
      <div><strong><?php echo htmlspecialchars($openapWifiHotspotHeaderTitle, ENT_QUOTES); ?></strong></div>
    </div>
  </div>
<?php else: ?>
<div class="openap-section-heading openap-hotspot-section-heading">
  <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
  <div><strong><?php echo _("WiFi Hotspot"); ?></strong></div>
</div>
<?php endif; ?>
<div class="hs-section openap-hotspot-summary<?php echo $openapWifiHotspotCardHeader ? ' is-contained' : ''; ?>">
  <div class="openap-hotspot-identity has-corner-state">
    <span class="openap-hotspot-identity-icon <?php echo $hostapdEnabled ? 'is-running' : ''; ?>" data-openap-hotspot-field="identity-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
    <div class="openap-hotspot-identity-copy">
      <div class="openap-hotspot-name-row">
        <strong data-openap-hotspot-field="ssid"><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></strong>
      </div>
      <div class="openap-hotspot-network-badges">
        <?php if ($hotspotBandBadge !== ''): ?><span class="openap-hotspot-dual-band-badge" data-openap-hotspot-field="band-badge" aria-label="<?php echo htmlspecialchars($hotspotBandBadge . ' WiFi', ENT_QUOTES); ?>"><?php echo htmlspecialchars($hotspotBandBadge, ENT_QUOTES); ?></span><?php endif; ?>
        <span class="openap-hotspot-security" data-openap-hotspot-field="security" aria-label="<?php echo _("Security"); ?>: <?php echo htmlspecialchars($apSecurityType, ENT_QUOTES); ?>">
          <i class="fas fa-shield-alt" aria-hidden="true"></i>
          <span><?php echo htmlspecialchars($apSecurityType, ENT_QUOTES); ?></span>
        </span>
      </div>
      <?php if ($apIgnoreBroadcast): ?><span class="openap-hotspot-status is-hidden openap-hotspot-hidden-badge"><?php echo _("Hidden"); ?></span><?php endif; ?>
    </div>
    <div class="openap-hotspot-state openap-hotspot-state-corner" data-openap-hotspot-field="state" data-hotspot-hidden="<?php echo $apIgnoreBroadcast ? '1' : '0'; ?>">
      <span class="openap-hotspot-state-slot">
      <?php if ($hostapdEnabled): ?>
        <span class="openap-hotspot-status is-running"><i class="fas fa-circle"></i> <?php echo _("Running"); ?></span>
      <?php else: ?>
        <span class="openap-hotspot-status is-stopped"><i class="fas fa-circle"></i> <?php echo _("Stopped"); ?></span>
      <?php endif; ?>
      </span>
      <?php if (!OPENAP_MONITOR_ENABLED): ?>
      <div class="openap-hotspot-actions" aria-label="<?php echo _("Hotspot controls"); ?>">
        <form method="POST" action="/">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="dashboard_action" value="start_ap">
          <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($hotspotReturnPath, ENT_QUOTES); ?>">
          <button type="submit" class="openap-hotspot-action is-start" data-hotspot-action="start" title="<?php echo _("Start hotspot"); ?>" aria-label="<?php echo _("Start hotspot"); ?>"<?php echo $hostapdEnabled ? ' disabled' : ''; ?>><i class="fas fa-play" aria-hidden="true"></i></button>
        </form>
        <form method="POST" action="/">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="dashboard_action" value="stop_ap">
          <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($hotspotReturnPath, ENT_QUOTES); ?>">
          <button type="submit" class="openap-hotspot-action is-stop" data-hotspot-action="stop" title="<?php echo _("Stop hotspot"); ?>" aria-label="<?php echo _("Stop hotspot"); ?>"<?php echo !$hostapdEnabled ? ' disabled' : ''; ?>><i class="fas fa-stop" aria-hidden="true"></i></button>
        </form>
        <form method="POST" action="/">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="dashboard_action" value="restart_ap">
          <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($hotspotReturnPath, ENT_QUOTES); ?>">
          <button type="submit" class="openap-hotspot-action is-restart" data-hotspot-action="restart" title="<?php echo _("Restart hotspot"); ?>" aria-label="<?php echo _("Restart hotspot"); ?>"<?php echo !$hostapdEnabled ? ' disabled' : ''; ?>><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="openap-hotspot-band-grid<?php echo $hostapdEnabled ? '' : ' is-service-stopped'; ?>">
    <?php if ($hostapdEnabled): ?>
      <?php foreach ($orderedApRadios as $radio): ?>
        <?php $bandBadge = (string) ($radio['band'] ?? '') === '5' ? '5G' : ((string) ($radio['band'] ?? '') === '2.4' ? '2.4G' : (string) ($radio['band'] ?? '') . 'G'); ?>
        <div class="openap-hotspot-band-row" data-openap-hotspot-band="<?php echo htmlspecialchars((string) ($radio['band'] ?? ''), ENT_QUOTES); ?>">
          <div class="openap-hotspot-band-cell"><span class="openap-hotspot-band-badge"><?php echo htmlspecialchars($bandBadge, ENT_QUOTES); ?></span></div>
          <div class="openap-hotspot-band-metric"><span class="openap-hotspot-metric-icon" aria-hidden="true"><i class="fas fa-signal"></i></span><div><small><?php echo _("Channel"); ?></small><strong data-openap-hotspot-field="<?php echo htmlspecialchars((string) ($radio['band'] ?? ''), ENT_QUOTES); ?>-channel"><?php echo htmlspecialchars($radio['channel'], ENT_QUOTES); ?></strong></div></div>
          <div class="openap-hotspot-band-metric"><span class="openap-hotspot-metric-icon" aria-hidden="true"><i class="fas fa-arrows-alt-h"></i></span><div><small><?php echo _("Channel width"); ?></small><strong data-openap-hotspot-field="<?php echo htmlspecialchars((string) ($radio['band'] ?? ''), ENT_QUOTES); ?>-width"><?php echo (int) $radio['width']; ?> MHz<?php if ((int) ($radio['configured_width'] ?? $radio['width']) !== (int) $radio['width']): ?><span class="openap-hotspot-configured-value"><?php echo (int) $radio['configured_width']; ?> MHz <?php echo _("configured"); ?></span><?php endif; ?></strong></div></div>
          <div class="openap-hotspot-band-metric"><span class="openap-hotspot-metric-icon" aria-hidden="true"><i class="fas fa-broadcast-tower"></i></span><div><small><?php echo _("TX Power"); ?></small><strong data-openap-hotspot-field="<?php echo htmlspecialchars((string) ($radio['band'] ?? ''), ENT_QUOTES); ?>-txpower"><?php echo htmlspecialchars($radio['txpower'], ENT_QUOTES); ?></strong></div></div>
        </div>
      <?php endforeach; ?>
      <?php if ($orderedApRadios === []): ?><div class="openap-hotspot-band-empty"><?php echo _("No active WiFi bands"); ?></div><?php endif; ?>
    <?php else: ?>
      <div class="openap-hotspot-stopped-message" role="status">
        <span class="openap-hotspot-stopped-icon" aria-hidden="true"><i class="fas fa-ban"></i></span>
        <div><strong><?php echo _("Hotspot stopped"); ?></strong><span class="openap-hotspot-stopped-info"><i class="fas fa-info-circle" aria-hidden="true"></i><?php echo _("WiFi channels are unavailable while the hotspot service is stopped."); ?></span></div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($openapWifiHotspotCardHeader): ?>
</div>
<?php endif; ?>
