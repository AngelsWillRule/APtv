<?php
/**
 * OpenAP V3 Dashboard Template
 * Enhanced AP/Repeater dashboard with real-time data
 */
$vpn = $vpn ?? null;
// Helper: signal dots from dBm
function sigDots($dBm) {
    if ($dBm === null || $dBm === '-') return '<div class="signal-dots">'.str_repeat('<div class="dot bg-gray"></div>',5).'</div>';
    $v = (int)$dBm;
    $n = 0;
    if ($v > -50) { $n = 5; }
    elseif ($v > -60) { $n = 4; }
    elseif ($v > -70) { $n = 3; }
    elseif ($v > -80) { $n = 2; }
    else { $n = 1; }
    $dotColor = $n >= 4 ? '#059669' : ($n >= 2 ? '#d97706' : '#dc2626');
    $bars = '';
    for ($i=0; $i<5; $i++) {
        $c = $i < $n ? $dotColor : '#e2e8f0';
        $bars .= '<div class="dot" style="background:'.$c.'"></div>';
    }
    return '<div class="signal-dots">'.$bars.'</div>';
}

// Format traffic total
$trafficApRx = isset($trafficAp['rx_bytes']) ? openapFormatBytes((int)$trafficAp['rx_bytes']) : '0 B';
$trafficApTx = isset($trafficAp['tx_bytes']) ? openapFormatBytes((int)$trafficAp['tx_bytes']) : '0 B';
$trafficUplinkRx = isset($trafficUplink['rx_bytes']) ? openapFormatBytes((int)$trafficUplink['rx_bytes']) : '0 B';
$trafficUplinkTx = isset($trafficUplink['tx_bytes']) ? openapFormatBytes((int)$trafficUplink['tx_bytes']) : '0 B';

// Uplink status
$isRepeaterWifi = $isRepeaterWifi ?? false;
$uplinkSsid = $isRepeaterWifi ? ($uplinkDetails['ssid'] ?? '-') : 'Ethernet';
$uplinkState = $uplinkDetails['wpa_state'] ?? ($isRepeaterWifi ? 'DISCONNECTED' : 'COMPLETED');
$uplinkConnected = $isRepeaterWifi ? ($uplinkState === 'COMPLETED') : true;
$uplinkRssi = $isRepeaterWifi ? ($uplinkSignal['RSSI'] ?? '-') : '-';
$uplinkFreq = $isRepeaterWifi ? ($uplinkSignal['FREQUENCY'] ?? '-') : '-';
$uplinkChannel = $isRepeaterWifi ? ($uplinkSignal['CHANNEL'] ?? '-') : '-';
$uplinkLabel = $uplinkLabel ?? ($isRepeaterWifi ? 'WiFi uplink' : 'Ethernet uplink');
$uplinkKind = $uplinkKind ?? ($isRepeaterWifi ? 'wireless' : 'ethernet');
$uplinkNodeLabel = $isRepeaterWifi ? _('Uplink AP') : _('Ethernet uplink');
$uplinkNodeIcon = $isRepeaterWifi ? 'fas fa-wifi' : 'fas fa-network-wired';
$uplinkNodeSub = $isRepeaterWifi
    ? ($uplinkConnected ? ($uplinkSsid ?: '-') : ($uplinkHealth['reason'] ?? _('Uplink unavailable')))
    : ($uplinkIface ?: 'eth0');
$topologyHealthy = isset($healthy)
    ? (bool) $healthy
    : ($hostapdEnabled
        && $uplinkConnected
        && (bool) ($serviceList['dnsmasq'] ?? false)
        && (bool) ($serviceList['nftables'] ?? false)
        && (bool) ($serviceList['lighttpd'] ?? false));
if ($currentMode === 'ap_ethernet_bridge') {
    $topologyHealthy = $hostapdEnabled && $uplinkConnected && (bool) ($serviceList['lighttpd'] ?? false);
}
$topologyModeIcon = in_array($currentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true)
    ? 'fa-network-wired'
    : ($currentMode === 'repeater_wifi' ? 'fa-wifi' : 'fa-question-circle');
$topologyModeLabel = $currentMode === 'ap_ethernet_bridge'
    ? _('Ethernet Bridge')
    : ($currentMode === 'ap_ethernet' ? _('Ethernet Mode') : ($currentMode === 'repeater_wifi' ? _('Repeater') : ucfirst((string) $currentMode)));
$topologyLiveState = !$hostapdEnabled ? 'offline' : ($topologyHealthy ? 'live' : 'degraded');
$topologyLiveLabel = _('Live');

// Signal quality percentage (for display bar)
$sigPct = 0;
$sigDisplay = ($clientBreakdown['avg_signal'] ?? 0) ?: '-';
if (is_numeric($sigDisplay)) {
    $sigPct = max(0, min(100, round((($sigDisplay + 100) / 90) * 100)));
}
// Uplink signal quality
$ulSigPct = 0;
if (is_numeric($uplinkRssi)) {
    $ulSigPct = max(0, min(100, round((((int)$uplinkRssi + 100) / 90) * 100)));
}

// System uptime string
$sysUptimeStr = $sysUptime ?? '-';

// DHCP pool
$dhcpActive = $dhcpPool['active'] ?? 0;
$dhcpTotal = $dhcpPool['total'] ?? 150;
$dhcpRange = ($dhcpPool['range_start'] ?? '10.88.77.50') . ' - ' . ($dhcpPool['range_end'] ?? '10.88.77.200');
$topologyHotspotIpv4 = function_exists('openapInterfaceIpv4')
    ? openapInterfaceIpv4($currentMode === 'ap_ethernet_bridge' ? $uplinkIface : $hotspotInterface)
    : ($currentMode === 'ap_ethernet_bridge' ? $publicIpv4Address : $ipv4Address);
if (in_array($topologyHotspotIpv4, ['', '-', 'None'], true) && $currentMode !== 'ap_ethernet_bridge') {
    $topologyHotspotIpv4 = (string) ($profile['network']['gateway'] ?? $ipv4Address);
}
$topologyHotspotIpv4Label = $currentMode === 'ap_ethernet_bridge' ? _('Management IP') : _('Gateway');
$topologyClientNetwork = $currentMode === 'ap_ethernet_bridge' ? _('Upstream DHCP') : $dhcpRange;
$dhcpLeaseTime = $dhcpPool['lease_time'] ?? '12h';
$dhcpDns = $dhcpPool['dns'] ?? '10.88.77.1';
$dhcpPct = $dhcpTotal > 0 ? round(($dhcpActive / $dhcpTotal) * 100) : 0;
$dhcpFill = $dhcpActive > $dhcpTotal ? $dhcpTotal : $dhcpActive;
$dhcpRemain = $dhcpTotal - $dhcpFill;

// Band info
$apBand = $frequency === '5' ? '5 GHz' : ($frequency === '2.4' ? '2.4 GHz' : 'Auto');

// Service icons
function svcLed($ok) {
    return $ok ? '#059669' : '#dc2626';
}
function svcLabel($ok) {
    return $ok ? 'Active' : 'Inactive';
}

$dashboardServices = [
  'hostapd' => ['hostapd', htmlspecialchars($interface).' AP', 'fas fa-bullseye'],
  'dnsmasq' => ['dnsmasq', 'DHCP + DNS', 'fas fa-exchange-alt'],
  'nftables' => ['nftables', 'NAT / Firewall', 'fas fa-fire-flame-curved'],
];
if ($isRepeaterWifi) {
  $dashboardServices['wpa_supplicant'] = ['wpa_supplicant', htmlspecialchars($uplinkIface).' uplink', 'fas fa-wifi'];
}
$dashboardServices['lighttpd'] = ['lighttpd', 'Web server', 'fas fa-server'];

$renderServiceStatus = function () use ($serviceList, $interface, $apIface) {
    ob_start();
    require __DIR__ . '/openap_service_status.php';
    return ob_get_clean();
};

$renderDhcpPool = function () use ($currentMode, $dhcpActive, $dhcpTotal, $dhcpFill, $dhcpRemain, $dhcpRange, $dhcpLeaseTime, $dhcpDns) {
    ob_start();
    ?>
    <div class="stat-card border-top-blue openap-side-dhcp">
      <div class="stat-top openap-widget-body">
        <div class="openap-widget-heading">
          <div>
            <div class="openap-widget-title"><?php echo _("DHCP setting"); ?></div>
            <div class="openap-widget-caption"><?php echo $currentMode === 'ap_ethernet_bridge' ? _("Upstream managed") : _("Hotspot address pool"); ?></div>
          </div>
          <div class="openap-widget-icon openap-widget-icon-blue"><i class="fas <?php echo $currentMode === 'ap_ethernet_bridge' ? 'fa-ban' : 'fa-arrow-right-arrow-left'; ?>"></i></div>
        </div>
        <?php if ($currentMode === 'ap_ethernet_bridge'): ?>
        <div class="openap-dhcp-summary">
          <div class="openap-dhcp-lease-count">
            <strong><i class="fas fa-cloud"></i></strong>
            <small><?php echo _("Upstream DHCP"); ?></small>
          </div>
          <div class="openap-dhcp-meta">
            <span><?php echo _("Addresses and leases are managed by the upstream router."); ?></span>
          </div>
        </div>
        <?php else: ?>
        <div class="openap-dhcp-summary">
          <div class="openap-dhcp-lease-count">
            <strong><?php echo $dhcpActive; ?> <span>/ <?php echo $dhcpTotal; ?></span></strong>
            <small><?php echo _("Leases active"); ?></small>
          </div>
          <div class="openap-dhcp-meta">
            <span><?php echo _("Lease"); ?> <strong><?php echo htmlspecialchars($dhcpLeaseTime); ?></strong></span>
            <span>DNS <strong><?php echo htmlspecialchars($dhcpDns); ?></strong></span>
          </div>
        </div>
        <div class="openap-dhcp-track" role="progressbar" aria-label="<?php echo _("DHCP leases in use"); ?>" aria-valuenow="<?php echo (int)$dhcpFill; ?>" aria-valuemin="0" aria-valuemax="<?php echo (int)$dhcpTotal; ?>">
          <span style="width:<?php echo $dhcpTotal > 0 ? min(100, round($dhcpFill / $dhcpTotal * 100)) : 0; ?>%"></span>
        </div>
        <?php endif; ?>
      </div>
      <div class="stat-bottom">
        <?php if ($currentMode === 'ap_ethernet_bridge'): ?>
        <span><i class="fas fa-ban"></i> <?php echo _("Managed upstream"); ?></span>
        <strong><?php echo _("Local DHCP disabled"); ?></strong>
        <?php else: ?>
        <span><i class="fas fa-network-wired"></i> <?php echo _("Pool"); ?></span>
        <strong><?php echo htmlspecialchars($dhcpRange); ?></strong>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
};
$dashboardWidgetOrder = is_array($dashboardWidgetOrder ?? null)
    ? openapNormalizeDashboardWidgetOrder($dashboardWidgetOrder)
    : openapDashboardWidgetIds();
$dashboardWidgetPositions = array_flip($dashboardWidgetOrder);
?>


<div class="container-fluid p-0 openap-dashboard-page">

  <?php $status->showMessages(); ?>

  <!-- ===== RIGA 2: TOPOLOGY + QUICK ACTIONS ===== -->
  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <div class="card shadow openap-dashboard-main-card">
        <div class="card-header openap-topology-header openap-page-main-header openap-dashboard-main-header">
          <div class="openap-topology-header-title">
            <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
            <div><strong><?php echo _("Network Topology and Operating Mode"); ?></strong></div>
          </div>
        </div>
        <div class="card-body">
          <div class="openap-topology-mode-shell">
          <!-- Topology -->
          <div class="openap-topology openap-topology-led-panel d-flex align-items-center justify-content-center py-2" style="gap:0">
            <!-- Internet -->
            <div class="topo-node <?php echo $uplinkConnected ? 'active' : ''; ?>" data-openap-topology-node="internet">
              <div class="topo-icon openap-topology-recovery-icon">
                <span class="openap-topology-recovery-icon-viewport" aria-hidden="true">
                  <i class="fas fa-globe openap-topology-recovery-glyph"></i>
                </span>
                <span class="topo-status-indicator" aria-label="<?php echo $uplinkConnected ? _("Active") : _("Interrupted"); ?>"><i class="fas fa-times"></i></span>
              </div>
              <div class="topo-label" style="color:<?php echo $uplinkConnected ? '#059669' : '#94a3b8'; ?>"><?php echo _("Internet"); ?></div>
              <div class="topo-sub" data-openap-topology-sub data-ready-text="<?php echo htmlspecialchars(_("Upstream"), ENT_QUOTES); ?>"><?php echo $uplinkConnected ? _("Upstream") : _("Offline"); ?></div>
            </div>
            <!-- Line -->
            <div class="topo-line <?php echo $uplinkConnected ? 'active' : ''; ?>" data-openap-topology-line="internet-uplink"></div>

            <!-- Uplink AP -->
            <div class="topo-node <?php echo $uplinkConnected ? 'active' : ''; ?>" data-openap-topology-node="uplink">
              <div class="topo-icon openap-topology-mode-icon openap-topology-recovery-icon" data-openap-topology-mode-icon data-current-mode="<?php echo htmlspecialchars($currentMode, ENT_QUOTES); ?>">
                <span class="openap-topology-mode-icon-viewport openap-topology-recovery-icon-viewport" aria-hidden="true">
                  <i class="<?php echo $uplinkNodeIcon; ?> openap-topology-mode-glyph"></i>
                </span>
                <span class="topo-status-indicator" aria-label="<?php echo $uplinkConnected ? _("Active") : _("Interrupted"); ?>"><i class="fas fa-times"></i></span>
              </div>
              <div class="topo-label openap-topology-mode-label" data-openap-topology-mode-label style="color:<?php echo $uplinkConnected ? '#059669' : '#94a3b8'; ?>"><?php echo $uplinkNodeLabel; ?></div>
              <div class="topo-sub openap-topology-mode-sub" data-openap-topology-sub data-openap-topology-mode-sub data-ready-text="<?php echo htmlspecialchars($uplinkNodeSub ?: '-', ENT_QUOTES); ?>"><?php echo htmlspecialchars($uplinkNodeSub ?: '-'); ?></div>
            </div>
            <!-- Line -->
            <div class="topo-line <?php echo $uplinkConnected && $hostapdEnabled ? 'active' : ''; ?>" data-openap-topology-line="uplink-openap"></div>

            <!-- Hotspot node -->
            <div class="topo-node <?php echo $hostapdEnabled ? 'active' : ''; ?>" data-openap-topology-node="hotspot" style="flex-shrink:0">
              <div class="openap-topology-node-badges">
                <span class="badge rounded-pill openap-topology-node-badge openap-topology-node-badge-ap"><?php echo htmlspecialchars($interface); ?> AP</span>
                <?php if ($uplinkIface): ?>
                <span class="badge rounded-pill openap-topology-node-badge openap-topology-node-badge-up"><?php echo htmlspecialchars($uplinkIface); ?> UP</span>
                <?php endif; ?>
              </div>
              <div class="topo-icon openap-topology-hotspot-icon openap-topology-recovery-icon" aria-hidden="true">
                <span class="openap-topology-recovery-icon-viewport"><i class="fas fa-broadcast-tower openap-topology-recovery-glyph"></i></span>
                <span class="topo-status-indicator"><i class="fas fa-times"></i></span>
              </div>
              <div class="topo-label openap-topology-hotspot-name"><?php echo htmlspecialchars($ssid ?: OPENAP_BRAND_TEXT, ENT_QUOTES); ?></div>
              <div class="topo-sub" data-openap-topology-sub data-openap-topology-hotspot-address><?php echo htmlspecialchars($topologyHotspotIpv4 ?: '-', ENT_QUOTES); ?></div>
            </div>

            <!-- Line to clients -->
            <div class="topo-line <?php echo $hostapdEnabled ? 'active' : ''; ?>" data-openap-topology-line="openap-clients"></div>

            <!-- Clients -->
            <div class="topo-node <?php echo $hostapdEnabled ? 'active' : ''; ?>" data-openap-topology-node="clients">
              <div class="topo-icon openap-topology-recovery-icon">
                <span class="openap-topology-recovery-icon-viewport"><i class="fas fa-laptop openap-topology-recovery-glyph"></i></span>
                <span class="topo-status-indicator"><i class="fas fa-times"></i></span>
              </div>
              <div class="topo-label" style="color:<?php echo $totalClients > 0 ? '#059669' : '#94a3b8'; ?>" data-openap-topology-label><span data-openap-client-count><?php echo (int)$totalClients; ?></span> <?php echo _("Clients"); ?></div>
              <div class="topo-sub" data-openap-topology-sub><?php echo htmlspecialchars($topologyClientNetwork); ?></div>
            </div>
          </div>

          <!-- Operating Mode -->
          <div class="row g-2 openap-dashboard-section openap-operating-mode-section">
            <div class="col-12">
              <div class="openap-section-heading">
                <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-exchange-alt"></i></span>
                <div>
                  <strong><?php echo _("Operating Mode"); ?></strong>
                </div>
              </div>
              <?php $apEthernetActive = in_array($currentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true); ?>
              <?php $apEthernetState = $currentMode === 'ap_ethernet_bridge' ? _('● Bridge') : ($currentMode === 'ap_ethernet' ? _('● Routed / NAT') : 'Switch →'); ?>
              <?php $ethernetModeSelectable = $ethernetModeAvailable ?? true; ?>
              <?php $ethernetModeUnavailableReason = _('Ethernet cable disconnected. Connect the cable to use Ethernet Mode.'); ?>
              <div class="openap-mode-selector <?php echo $currentMode === 'repeater_wifi' ? 'is-repeater' : 'is-ap-ethernet'; ?>" data-current-mode="<?php echo htmlspecialchars($currentMode, ENT_QUOTES); ?>" data-openap-ap-channel="<?php echo htmlspecialchars((string) ($apChannel ?: '-'), ENT_QUOTES); ?>" role="group" aria-label="<?php echo _("Operating Mode"); ?>">
                <span class="openap-mode-selector-indicator" aria-hidden="true"></span>
                <a href="#" data-openap-ethernet-mode="1" class="openap-mode-option<?php echo $apEthernetActive ? ' active' : ''; ?><?php echo $ethernetModeSelectable ? '' : ' is-unavailable'; ?>"<?php echo $ethernetModeSelectable ? ' data-openap-modal="ap-ethernet"' : ' aria-disabled="true" tabindex="-1" title="' . htmlspecialchars($ethernetModeUnavailableReason, ENT_QUOTES) . '" onclick="return false;"'; ?><?php echo $apEthernetActive ? ' aria-current="true"' : ''; ?>>
                  <div class="openap-mode-option-icon">
                    <i class="fas <?php echo $ethernetModeSelectable ? 'fa-network-wired' : 'fa-ban'; ?>"></i>
                  </div>
                  <div class="openap-mode-option-title"><?php echo _("Ethernet Mode"); ?></div>
                  <div class="openap-mode-option-state"><?php echo htmlspecialchars($ethernetModeSelectable ? $apEthernetState : _('Cable disconnected'), ENT_QUOTES); ?></div>
                </a>
                <?php $repeaterModeAvailable = $repeaterModeAvailable ?? false; ?>
                <?php $repeaterBlockedByBridge = $currentMode === 'ap_ethernet_bridge'; ?>
                <?php $repeaterModeSelectable = $repeaterModeAvailable && !$repeaterBlockedByBridge; ?>
                <?php $repeaterModeActive = $repeaterModeAvailable && $currentMode === 'repeater_wifi'; ?>
                <a href="#" id="openapRepeaterModeCard" data-bridge-blocked="<?php echo $repeaterBlockedByBridge ? '1' : '0'; ?>" <?php echo $repeaterModeSelectable ? 'data-openap-modal="uplink"' : 'aria-disabled="true" tabindex="-1"'; ?> title="<?php echo htmlspecialchars($repeaterModeSelectable ? _("Configure repeater mode") : ($repeaterBlockedByBridge ? _("Switch Ethernet Mode to Routed / NAT first") : ($repeaterModeUnavailableReason ?? _("Repeater mode requires at least 2 WiFi interfaces."))), ENT_QUOTES); ?>" class="openap-mode-option openap-repeater-mode-card<?php echo $repeaterModeActive ? ' active' : ''; ?><?php echo $repeaterModeSelectable ? '' : ' is-unavailable'; ?>"<?php echo $repeaterModeActive ? ' aria-current="true"' : ''; ?>>
                  <div class="openap-mode-option-icon openap-repeater-mode-icon"><i class="fas <?php echo $repeaterModeSelectable ? 'fa-wifi' : ($repeaterBlockedByBridge ? 'fa-lock' : 'fa-ban'); ?>"></i></div>
                  <div class="openap-mode-option-title openap-repeater-mode-title"><?php echo _("Repeater Mode"); ?></div>
                  <div class="openap-mode-option-state openap-repeater-mode-state"><?php echo htmlspecialchars($repeaterModeActive ? '● Active' : ($repeaterBlockedByBridge ? _("Routed first") : ($repeaterModeSelectable ? 'Switch →' : ($repeaterModeUnavailableReason ?? _('Repeater unavailable')))), ENT_QUOTES); ?></div>
                </a>
              </div>
            </div>
          </div>
          </div>

          <!-- Hotspot Status -->
          <?php require __DIR__ . '/wifi_hotspot.php'; ?>
          <?php if (false): // Legacy inline copy retained temporarily; shared partial above is authoritative. ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fas fa-bullseye fa-fw" style="color:#1e3a8a;font-size:13px"></i>
            <span style="font-size:12px;font-weight:600;color:#0f172a"><?php echo _("WiFi Hotspot"); ?></span>
          </div>
          <div class="hs-section" style="margin-bottom:10px;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden;background:#fff">
            <div style="display:flex;align-items:center;padding:8px 12px;background:linear-gradient(135deg,#f8fafc,#fff);border-bottom:1px solid #e2e8f0">
              <div style="display:flex;align-items:center;gap:6px;flex:1">
                <i class="fas fa-wifi" style="color:<?php echo $hostapdEnabled ? '#059669' : '#94a3b8'; ?>;font-size:13px"></i>
                <span style="font-size:12px;font-weight:600;color:#0f172a"><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></span>
                <?php if ($hostapdEnabled): ?>
                  <span class="badge rounded-pill" style="background:rgba(5,150,105,0.08);color:#059669;font-weight:400;font-size:9px"><i class="fas fa-circle" style="font-size:5px"></i> <?php echo _("Running"); ?></span>
                <?php else: ?>
                  <span class="badge rounded-pill" style="background:#fee2e2;color:#dc2626;font-weight:400;font-size:9px"><i class="fas fa-circle" style="font-size:5px"></i> <?php echo _("Stopped"); ?></span>
                <?php endif; ?>
                <?php if ($apIgnoreBroadcast): ?>
                  <span class="badge rounded-pill" style="background:rgba(217,119,6,0.08);color:#d97706;font-weight:400;font-size:9px"><?php echo _("Hidden"); ?></span>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:4px;align-items:center">
                <form method="POST" action="/" style="display:inline;margin:0">
                  <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
                  <input type="hidden" name="dashboard_action" value="restart_ap">
                  <button type="submit" class="hs-btn" title="<?php echo _("Restart AP service"); ?>" style="padding:3px 7px;border-radius:5px;border:1px solid #e2e8f0;background:#fff;font-size:10px;color:#475569;display:inline-flex;align-items:center;gap:3px;transition:all .15s">
                    <i class="fas fa-broadcast-tower" style="font-size:9px"></i> <?php echo _("AP"); ?>
                  </button>
                </form>
                <form method="POST" action="/" style="display:inline;margin:0">
                  <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
                  <input type="hidden" name="dashboard_action" value="restart_dhcp">
                  <button type="submit" class="hs-btn" title="<?php echo _("Restart DHCP/DNS service"); ?>" style="padding:3px 7px;border-radius:5px;border:1px solid #e2e8f0;background:#fff;font-size:10px;color:#475569;display:inline-flex;align-items:center;gap:3px;transition:all .15s">
                    <i class="fas fa-retweet" style="font-size:9px"></i> <?php echo _("DHCP"); ?>
                  </button>
                </form>
                <button type="button" data-openap-modal="service-logs" class="hs-btn" title="<?php echo _("Show AP and DHCP logs"); ?>" style="padding:3px 7px;border-radius:5px;border:1px solid #e2e8f0;background:#fff;font-size:10px;color:#475569;display:inline-flex;align-items:center;gap:3px;transition:all .15s">
                  <i class="fas fa-file-alt" style="font-size:9px"></i> <?php echo _("Logs"); ?>
                </button>
                <a href="#" data-openap-modal="hotspot" class="hs-btn" style="padding:3px 8px;border-radius:5px;border:1px solid #e2e8f0;background:#fff;font-size:10px;color:#475569;text-decoration:none;display:inline-flex;align-items:center;gap:3px;transition:all .15s">
                  <i class="fas fa-sliders-h" style="font-size:9px"></i> <?php echo _("Configure"); ?>
                </a>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
              <!-- Col 1 -->
              <div>
                <div style="display:flex;align-items:center;padding:6px 12px;border-bottom:1px solid #f8fafc">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("Band"); ?></span>
                  <span style="font-size:12px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:5px;flex-wrap:wrap">
                    <?php foreach ($apRadios as $index => $radio): ?>
                      <?php if ($index): ?><span class="openap-dashboard-radio-separator" aria-hidden="true"></span><?php endif; ?>
                      <span class="badge rounded-pill" style="background:rgba(30,58,138,0.08);color:#1e3a8a;font-weight:400;font-size:9px"><?php echo htmlspecialchars($radio['band'], ENT_QUOTES); ?> GHz · <?php echo _("Ch"); ?> <?php echo htmlspecialchars($radio['channel'], ENT_QUOTES); ?></span>
                    <?php endforeach; ?>
                    <?php if ($apRadios === []): ?>-<?php endif; ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;padding:6px 12px;border-bottom:1px solid #f8fafc">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("Width"); ?></span>
                  <span style="font-size:12px;font-weight:600;color:#0f172a">
                    <?php foreach ($apRadios as $index => $radio): ?><?php if ($index): ?><span class="openap-dashboard-radio-separator" aria-hidden="true"></span><?php endif; ?><span class="badge rounded-pill" style="background:#e2e8f0;color:#475569;font-weight:400;font-size:9px"><?php echo htmlspecialchars($radio['band'], ENT_QUOTES); ?> GHz · <?php echo (int) $radio['width']; ?> MHz</span><?php endforeach; ?><?php if ($apRadios === []): ?>-<?php endif; ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;padding:6px 12px">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("Security"); ?></span>
                  <span style="font-size:12px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:4px">
                    <i class="fas fa-shield-alt" style="color:#059669;font-size:11px"></i>
                    <?php echo htmlspecialchars($apSecurityType, ENT_QUOTES); ?>
                    <?php if ($apEncryption && $apEncryption !== 'CCMP'): ?>
                      <span style="font-size:10px;color:#94a3b8;font-family:monospace"><?php echo htmlspecialchars($apEncryption, ENT_QUOTES); ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
              <!-- Col 2 -->
              <div style="border-left:1px solid #f1f5f9">
                <div style="display:flex;align-items:center;padding:6px 12px;border-bottom:1px solid #f8fafc">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("TX Power"); ?></span>
                  <span style="font-size:12px;font-weight:600;color:#0f172a">
                    <?php foreach ($apRadios as $index => $radio): ?><?php if ($index): ?><span class="openap-dashboard-radio-separator" aria-hidden="true"></span><?php endif; ?><span class="badge rounded-pill" style="background:rgba(217,119,6,0.08);color:#d97706;font-weight:400;font-size:9px;font-family:monospace"><?php echo htmlspecialchars($radio['band'], ENT_QUOTES); ?>G · <?php echo htmlspecialchars($radio['txpower'], ENT_QUOTES); ?></span><?php endforeach; ?><?php if ($apRadios === []): ?>-<?php endif; ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;padding:6px 12px;border-bottom:1px solid #f8fafc">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("Clients"); ?></span>
                  <span style="font-size:12px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:6px">
                    <span data-openap-client-count style="font-size:16px;font-weight:700;color:<?php echo $wirelessClients > 0 ? '#1e3a8a' : '#94a3b8'; ?>"><?php echo (int)$wirelessClients; ?></span>
                    <span style="font-size:10px;color:#64748b;font-weight:400"><?php echo _("connected"); ?></span>
                    <?php if (($clientBreakdown['avg_signal'] ?? 0) > 0): ?>
                      <span style="display:inline-flex;align-items:flex-end;gap:2px;height:12px;color:<?php echo $clientBreakdown['avg_signal'] >= -60 ? '#059669' : ($clientBreakdown['avg_signal'] >= -75 ? '#d97706' : '#dc2626'); ?>">
                        <span style="width:3px;height:3px;border-radius:1.5px;background:currentColor;opacity:0.3"></span>
                        <span style="width:3px;height:6px;border-radius:1.5px;background:currentColor;opacity:0.5"></span>
                        <span style="width:3px;height:9px;border-radius:1.5px;background:currentColor;opacity:0.7"></span>
                        <span style="width:3px;height:12px;border-radius:1.5px;background:currentColor"></span>
                      </span>
                    <?php endif; ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;padding:6px 12px">
                  <span style="flex:0 0 60px;font-size:10px;font-weight:500;color:#64748b;text-transform:uppercase;letter-spacing:0.3px"><?php echo _("Uplink"); ?></span>
                  <span style="font-size:11px;color:#0f172a;display:flex;align-items:center;gap:4px;font-weight:500">
                    <?php if ($uplinkConnected): ?>
                      <i class="fas <?php echo $isRepeaterWifi ? 'fa-arrow-up' : 'fa-network-wired'; ?>" style="color:#059669;font-size:9px"></i>
                      <?php echo $isRepeaterWifi ? htmlspecialchars($uplinkDetails['ssid'] ?? '-', ENT_QUOTES) : htmlspecialchars('Ethernet ('.$uplinkIface.')', ENT_QUOTES); ?>
                      <?php if ($isRepeaterWifi && !empty($uplinkDetails['freq'])): ?>
                        <span style="font-size:10px;color:#94a3b8;font-weight:400">· ch<?php echo $uplinkDetails['freq'] >= 5000 ? round(($uplinkDetails['freq'] - 5000) / 5) : round(($uplinkDetails['freq'] - 2407) / 5); ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span style="font-size:10px;color:#94a3b8;font-weight:400"><?php echo _("Not connected"); ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Interface details -->
          <section class="openap-network-traffic-section">
            <div class="openap-network-traffic-heading">
              <span class="openap-network-traffic-heading-icon" aria-hidden="true"><i class="fas fa-chart-line"></i></span>
              <div>
                <strong><?php echo _("Live Network Traffic"); ?></strong>
              </div>
            </div>
          <div class="row g-2 openap-interface-details">
            <div class="col-12 col-md-6">
              <div class="iface-card openap-live-traffic openap-traffic-card"
                   data-traffic-role="uplink"
                   data-interface="<?php echo htmlspecialchars($uplinkIface, ENT_QUOTES); ?>"
                   data-rx-bytes="<?php echo (int)($trafficUplink['rx_bytes'] ?? 0); ?>"
                   data-tx-bytes="<?php echo (int)($trafficUplink['tx_bytes'] ?? 0); ?>"
                   data-download-counter="rx">
                <div class="openap-hotspot-identity openap-traffic-identity has-corner-state">
                  <span class="openap-hotspot-identity-icon <?php echo $uplinkConnected ? 'is-running' : ''; ?>" aria-hidden="true"><i class="fas <?php echo $isRepeaterWifi ? 'fa-wifi' : 'fa-network-wired'; ?>"></i></span>
                  <div class="openap-hotspot-identity-copy">
                    <div class="openap-hotspot-name-row"><strong><?php echo _("Uplink"); ?>: <span data-openap-traffic-uplink-name><?php echo $isRepeaterWifi ? htmlspecialchars((string) ($uplinkDetails['ssid'] ?? '-'), ENT_QUOTES) : _("Ethernet"); ?></span></strong></div>
                    <div class="openap-traffic-uplink-details">
                      <div><small><?php echo _("Device"); ?>:</small><strong data-openap-traffic-uplink-device><?php echo htmlspecialchars($uplinkIface ?: '-', ENT_QUOTES); ?></strong></div>
                      <div><small><?php echo _("IP address"); ?>:</small><strong data-openap-traffic-uplink-address><?php echo htmlspecialchars((string) ($uplinkDetails['ip_address'] ?? '-'), ENT_QUOTES); ?></strong></div>
                    </div>
                  </div>
                  <div class="openap-hotspot-state openap-hotspot-state-corner">
                    <span class="openap-hotspot-state-slot openap-traffic-connection-slot"><span class="openap-hotspot-status <?php echo $uplinkConnected ? 'is-running' : 'is-stopped'; ?>"><i class="fas fa-circle" aria-hidden="true"></i><?php echo $uplinkConnected ? _("Connected") : _("Disconnected"); ?></span></span>
                  </div>
                </div>
                <div class="openap-traffic-live">
                  <div class="openap-traffic-direction-row download">
                    <div class="openap-hotspot-band-cell"><span class="openap-hotspot-band-badge openap-traffic-direction-badge" aria-label="<?php echo _("Download"); ?>"><i class="fas fa-arrow-down"></i></span></div>
                    <div class="openap-hotspot-band-metric openap-traffic-direction-metric">
                      <div class="openap-traffic-metric-heading"><small><?php echo _("Download"); ?></small><strong class="openap-rate-download">0 B/s</strong></div>
                      <div class="openap-traffic-share"><div class="openap-traffic-share-track"><span class="openap-share-download"></span></div><span class="openap-percent-download">0%</span></div>
                    </div>
                  </div>
                  <div class="openap-traffic-direction-row upload">
                    <div class="openap-hotspot-band-cell"><span class="openap-hotspot-band-badge openap-traffic-direction-badge" aria-label="<?php echo _("Upload"); ?>"><i class="fas fa-arrow-up"></i></span></div>
                    <div class="openap-hotspot-band-metric openap-traffic-direction-metric">
                      <div class="openap-traffic-metric-heading"><small><?php echo _("Upload"); ?></small><strong class="openap-rate-upload">0 B/s</strong></div>
                      <div class="openap-traffic-share"><div class="openap-traffic-share-track"><span class="openap-share-upload"></span></div><span class="openap-percent-upload">0%</span></div>
                    </div>
                  </div>
                  <div class="openap-traffic-total-row">
                    <span><?php echo _("Traffic total"); ?></span>
                    <strong class="openap-traffic-total"><?php echo openapFormatBytes((int)($trafficUplink['rx_bytes'] ?? 0) + (int)($trafficUplink['tx_bytes'] ?? 0)); ?></strong>
                    <span class="openap-traffic-since"><?php echo _("since interface start"); ?></span>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="iface-card openap-live-traffic openap-traffic-card"
                   data-traffic-role="ap"
                   data-interface="<?php echo htmlspecialchars($apIface, ENT_QUOTES); ?>"
                   data-interfaces="<?php echo htmlspecialchars(implode(',', $apInterfaces), ENT_QUOTES); ?>"
                   data-rx-bytes="<?php echo (int)($trafficAp['rx_bytes'] ?? 0); ?>"
                   data-tx-bytes="<?php echo (int)($trafficAp['tx_bytes'] ?? 0); ?>"
                   data-download-counter="tx">
                <div class="openap-hotspot-identity openap-traffic-identity has-corner-state">
                  <span class="openap-hotspot-identity-icon <?php echo $hostapdEnabled ? 'is-running' : ''; ?>" aria-hidden="true"><i class="fas fa-wifi"></i></span>
                  <div class="openap-hotspot-identity-copy">
                    <div class="openap-hotspot-name-row"><strong><?php echo _("AP"); ?>: <?php echo htmlspecialchars($ssid ?: OPENAP_BRAND_TEXT, ENT_QUOTES); ?></strong></div>
                    <div class="openap-traffic-uplink-details">
                      <div><small><?php echo _("Device"); ?>:</small><strong><?php echo htmlspecialchars(implode(' + ', $apInterfaces) ?: '-', ENT_QUOTES); ?></strong></div>
                      <div><small><?php echo _("IP address"); ?>:</small><strong data-openap-traffic-ap-address><?php echo htmlspecialchars(function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($hotspotInterface) : ($ipv4Address ?: '-'), ENT_QUOTES); ?></strong></div>
                    </div>
                  </div>
                  <div class="openap-hotspot-state openap-hotspot-state-corner">
                    <span class="openap-hotspot-state-slot openap-traffic-connection-slot"><span class="openap-hotspot-status <?php echo $hostapdEnabled ? 'is-running' : 'is-stopped'; ?>"><i class="fas fa-circle" aria-hidden="true"></i><?php echo svcLabel($hostapdEnabled); ?></span></span>
                  </div>
                </div>
                <div class="openap-traffic-live">
                  <div class="openap-traffic-direction-row download">
                    <div class="openap-hotspot-band-cell"><span class="openap-hotspot-band-badge openap-traffic-direction-badge" aria-label="<?php echo _("Download"); ?>"><i class="fas fa-arrow-down"></i></span></div>
                    <div class="openap-hotspot-band-metric openap-traffic-direction-metric">
                      <div class="openap-traffic-metric-heading"><small><?php echo _("Download"); ?></small><strong class="openap-rate-download">0 B/s</strong></div>
                      <div class="openap-traffic-share"><div class="openap-traffic-share-track"><span class="openap-share-download"></span></div><span class="openap-percent-download">0%</span></div>
                    </div>
                  </div>
                  <div class="openap-traffic-direction-row upload">
                    <div class="openap-hotspot-band-cell"><span class="openap-hotspot-band-badge openap-traffic-direction-badge" aria-label="<?php echo _("Upload"); ?>"><i class="fas fa-arrow-up"></i></span></div>
                    <div class="openap-hotspot-band-metric openap-traffic-direction-metric">
                      <div class="openap-traffic-metric-heading"><small><?php echo _("Upload"); ?></small><strong class="openap-rate-upload">0 B/s</strong></div>
                      <div class="openap-traffic-share"><div class="openap-traffic-share-track"><span class="openap-share-upload"></span></div><span class="openap-percent-upload">0%</span></div>
                    </div>
                  </div>
                  <div class="openap-traffic-total-row">
                    <span><?php echo _("Traffic total"); ?></span>
                    <strong class="openap-traffic-total"><?php echo openapFormatBytes((int)($trafficAp['rx_bytes'] ?? 0) + (int)($trafficAp['tx_bytes'] ?? 0)); ?></strong>
                    <span class="openap-traffic-since"><?php echo _("since interface start"); ?></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          </section>

          <!-- Connected Clients -->
          <div class="openap-topology-clients">
            <div class="openap-topology-clients-header">
              <div class="openap-connected-clients-heading">
                <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                <div><strong><?php echo _("Connected Clients"); ?></strong></div>
              </div>
              <div class="openap-connected-clients-meta">
                <span class="badge rounded-pill badge-openap me-1"><i class="fas fa-wifi"></i> <?php echo (int)$totalClients; ?> <?php echo _("total"); ?></span>
                <?php if ($currentMode === 'ap_ethernet_bridge'): ?>
                <span class="badge rounded-pill badge-openap"><i class="fas fa-network-wired"></i> <?php echo _("Upstream DHCP"); ?></span>
                <?php else: ?>
                <span class="badge rounded-pill badge-openap"><i class="fas fa-database"></i> <?php echo $dhcpActive; ?> <?php echo _("leased"); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="openap-topology-clients-body">
              <?php if (count($clientList) > 0): ?>
              <table class="client-table table mb-0">
                <thead>
                  <tr>
                    <th><?php echo _("Hostname"); ?></th>
                    <th><?php echo _("IP"); ?></th>
                    <th><?php echo _("Traffic"); ?></th>
                    <th><?php echo _("MAC"); ?></th>
                    <th><?php echo _("Signal"); ?></th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php $shown = 0; foreach ($clientList as $c): ?>
                  <?php if (!$c['connected']) continue; ?>
                  <?php if ($shown >= 2) break; $shown++; ?>
                  <tr>
                    <td>
                      <div class="openap-client-device">
                        <span class="openap-client-device-icon" aria-hidden="true"><i class="fas fa-laptop"></i></span>
                        <span style="font-size:11px;color:#0f172a;font-weight:500"><?php echo htmlspecialchars($c['hostname'] ?: '-'); ?></span>
                      </div>
                    </td>
                    <td style="font-size:12px"><?php echo htmlspecialchars($c['ip']); ?></td>
                    <td class="openap-client-traffic-cell">
                      <div class="openap-client-traffic-values">
                        <span class="download"><i class="fas fa-arrow-down"></i> <?php echo openapFormatBytes((int) ($c['download_bytes'] ?? 0)); ?></span>
                        <span class="upload"><i class="fas fa-arrow-up"></i> <?php echo openapFormatBytes((int) ($c['upload_bytes'] ?? 0)); ?></span>
                      </div>
                      <div class="openap-client-traffic-total"><?php echo _("Total"); ?> <?php echo openapFormatBytes((int) ($c['traffic_bytes'] ?? 0)); ?><?php if (!empty($c['top_usage'])): ?> <span class="openap-client-top-usage"><i class="fas fa-crown"></i> <?php echo _("Top usage"); ?></span><?php endif; ?></div>
                    </td>
                    <td style="font-size:10px;color:#64748b;font-family:monospace"><?php echo htmlspecialchars($c['mac']); ?></td>
                    <td>
                      <?php echo sigDots($c['signal'] ?? null); ?>
                      <span class="openap-client-signal-value" style="font-size:10px;color:#64748b;margin-left:4px"><?php echo $c['signal'] ? $c['signal'].' dBm' : '-'; ?></span>
                    </td>
                    <td style="text-align:right">
                      <span class="badge rounded-pill" style="background:rgba(5,150,105,0.08);color:#059669;font-weight:400;font-size:9px"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle"></i> <?php echo _("Online"); ?></span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php else: ?>
              <div class="text-center py-4" style="color:#94a3b8">
                <i class="fas fa-wifi" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4"></i>
                <span style="font-size:13px"><?php echo _("No clients connected"); ?></span>
              </div>
              <?php endif; ?>
              <div class="openap-clients-view">
                <button type="button" class="openap-clients-view-button"
                        data-bs-toggle="modal" data-bs-target="#openapConnectedClientsModal"
                        aria-controls="openapConnectedClientsModal">
                  <i class="fas fa-users" aria-hidden="true"></i>
                  <span><?php echo _("View all clients"); ?></span>
                </button>
              </div>
            </div>
          </div>

          <div class="modal fade" id="openapConnectedClientsModal" tabindex="-1"
               aria-labelledby="openapConnectedClientsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
              <div class="modal-content openap-clients-modal">
                <div class="modal-header openap-repeater-header">
                  <div>
                    <div class="openap-repeater-title" id="openapConnectedClientsModalLabel"><?php echo _("Connected Clients"); ?></div>
                    <div class="openap-repeater-subtitle"><?php echo sprintf(_("%d devices currently associated with the hotspot"), (int) $totalClients); ?></div>
                  </div>
                  <div class="openap-repeater-header-actions">
                    <span class="openap-repeater-header-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
                  </div>
                </div>
                <div class="modal-body p-0">
                  <?php if ($totalClients > 0): ?>
                  <div class="table-responsive openap-clients-modal-list">
                    <table class="table client-table align-middle mb-0 openap-clients-modal-table">
                      <tbody>
                        <?php foreach ($clientList as $c): ?>
                        <?php if (empty($c['connected'])) continue; ?>
                        <tr>
                          <td>
                            <div class="openap-client-device">
                              <span class="openap-client-device-icon" aria-hidden="true"><i class="fas fa-laptop"></i></span>
                              <strong><?php echo htmlspecialchars($c['hostname'] ?: _("Unknown device"), ENT_QUOTES); ?></strong>
                            </div>
                          </td>
                          <td><?php echo htmlspecialchars($c['ip'] ?: '-', ENT_QUOTES); ?></td>
                          <td class="openap-client-traffic-cell">
                            <div class="openap-client-traffic-values">
                              <span class="download"><i class="fas fa-arrow-down"></i> <?php echo openapFormatBytes((int) ($c['download_bytes'] ?? 0)); ?></span>
                              <span class="upload"><i class="fas fa-arrow-up"></i> <?php echo openapFormatBytes((int) ($c['upload_bytes'] ?? 0)); ?></span>
                            </div>
                            <div class="openap-client-traffic-total"><?php echo _("Total"); ?> <?php echo openapFormatBytes((int) ($c['traffic_bytes'] ?? 0)); ?><?php if (!empty($c['top_usage'])): ?> <span class="openap-client-top-usage"><i class="fas fa-crown"></i> <?php echo _("Top usage"); ?></span><?php endif; ?></div>
                          </td>
                          <td><code><?php echo htmlspecialchars($c['mac'] ?: '-', ENT_QUOTES); ?></code></td>
                          <td>
                            <div class="openap-client-modal-signal">
                              <?php echo sigDots($c['signal'] ?? null); ?>
                              <span><?php echo !empty($c['signal']) ? htmlspecialchars((string) $c['signal'], ENT_QUOTES) . ' dBm' : '-'; ?></span>
                            </div>
                          </td>
                          <td><span class="openap-client-online"><i class="fas fa-circle"></i> <?php echo _("Online"); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?php else: ?>
                  <div class="openap-clients-modal-empty">
                    <span aria-hidden="true"><i class="fas fa-wifi"></i></span>
                    <strong><?php echo _("No clients connected"); ?></strong>
                    <small><?php echo _("Associated hotspot devices will appear here."); ?></small>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="modal-footer">
                  <span class="openap-clients-modal-count"><i class="fas fa-wifi"></i> <?php echo (int) $totalClients; ?> <?php echo _("connected"); ?></span>
                  <button type="button" class="btn-ss primary" data-bs-dismiss="modal"><?php echo _("Close"); ?></button>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
    <div class="col-xl-3 col-lg-4">
      <?php $openapWidgetPage = 'dashboard'; require __DIR__ . '/openap_widget_area.php'; ?>
    </div>

  </div>



    <script>
window.openapDashboardConfig = window.openapDashboardConfig || {};
window.openapDashboardConfig.modeSwitchSettling = <?php echo !empty($modeSwitchSettling) ? 'true' : 'false'; ?>;
</script>

</div><!-- /container-fluid -->

            <!-- ════════════════════════════════════════════ -->
    <!-- Hotspot Config Modal -->
    <!-- ════════════════════════════════════════════ -->
    <div class="modal fade" id="hotspotModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-dialog-centered" style="max-width:640px">
        <div class="modal-content" style="border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.1);overflow:hidden">
          <div class="modal-header py-2 px-4" style="border-bottom:1px solid #e2e8f0;min-height:42px">
            <div style="font-size:13px;font-weight:600;color:#0f172a"><i class="fas fa-bullseye me-2" style="color:#1e3a8a"></i><?php echo _("WiFi Hotspot Settings"); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:12px"></button>
          </div>
          <div class="modal-body p-0">
<form method="POST" action="hostapd_conf" id="hotspotForm">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <!-- Hidden fields for required hostapd params -->
          <input type="hidden" name="interface" value="<?php echo htmlspecialchars($apIface ?: $interface, ENT_QUOTES); ?>">
          <input type="hidden" name="wpa" value="2">
          <input type="hidden" name="wpa_pairwise" value="CCMP">
          <input type="hidden" name="country_code" value="<?php echo htmlspecialchars($apCountry, ENT_QUOTES); ?>">
          <input type="hidden" name="repeaterEnable" value="1">
          <!-- Status messages container -->
          <div id="hostapdStatus" style="display:none"></div>
        <!-- Tab buttons -->
        <div class="hotspot-tabs" role="tablist" aria-label="<?php echo _("WiFi hotspot settings"); ?>">
          <button type="button" class="tab-btn active" role="tab" aria-selected="true" aria-controls="hsp-basic" data-hotspot-tab="basic"><i class="fas fa-sliders-h"></i> <?php echo _("Basic"); ?></button>
          <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="hsp-security" data-hotspot-tab="security"><i class="fas fa-shield-alt"></i> <?php echo _("Security"); ?></button>
          <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="hsp-advanced" data-hotspot-tab="advanced"><i class="fas fa-cogs"></i> <?php echo _("Advanced"); ?></button>
          <button type="button" class="tab-btn" role="tab" aria-selected="false" aria-controls="hsp-logging" data-hotspot-tab="logging"><i class="fas fa-list"></i> <?php echo _("Logging"); ?></button>
        </div>

        <div class="hotspot-panes" style="position:relative">

          <!-- ═══ BASIC ═══ -->
          <div class="hotspot-pane active" id="hsp-basic">
            <div class="hfield-row">
              <div class="hfield-group wide">
                <div class="hfield-label"><?php echo _("SSID"); ?></div>
                <input type="text" class="hfield-input" name="ssid" value="<?php echo htmlspecialchars(($ssid !== '-') ? $ssid : '', ENT_QUOTES); ?>" required minlength="1" maxlength="32">
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label"><?php echo _("Band"); ?> <span class="chip-cap">auto</span></div>
                <select class="hfield-select" id="hsBand">
                  <option value="24" <?php echo in_array($apHwMode, ['b','g','n']) ? 'selected' : ''; ?>>2.4 GHz</option>
                  <option value="5" <?php echo in_array($apHwMode, ['a','ac']) ? 'selected' : ''; ?>>5 GHz</option>
                  <?php if ($apHwMode === 'ax'): ?><option value="6" <?php echo $apHwMode === 'ax' ? 'selected' : ''; ?>>6 GHz</option><?php endif; ?>
                </select>
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label"><?php echo _("Ch"); ?></div>
                <select class="hfield-select" name="channel" id="hsChannel">
                  <!-- 2.4 GHz channels (1-13) -->
                  <optgroup label="2.4 GHz" id="ch24">
                    <?php for ($c = 1; $c <= 13; $c++): ?>
                    <option value="<?php echo $c; ?>" data-band="24" <?php echo (int)$apChannel === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endfor; ?>
                  </optgroup>
                  <!-- 5 GHz channels (filtered by iw — NO-IR channels excluded) -->
                  <optgroup label="5 GHz" id="ch5">
                    <?php if (!empty($available5ghzChannels)): ?>
                    <?php foreach ($available5ghzChannels as $c): ?>
                    <option value="<?php echo $c; ?>" data-band="5" <?php echo (int)$apChannel === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <option value="" disabled><?php echo _("No channels available"); ?></option>
                    <?php endif; ?>
                  </optgroup>
                </select>
              </div>
            </div>
            <div class="hfield-row">
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("Wireless Mode"); ?></div>
                <select class="hfield-select" name="hw_mode" id="hsMode">
                  <option value="n" data-band="24" <?php echo in_array($apHwMode, ['b','g','n']) ? 'selected' : ''; ?>>802.11n (2.4 GHz)</option>
                  <option value="ac" data-band="5" <?php echo in_array($apHwMode, ['a','ac']) ? 'selected' : ''; ?>>802.11a / 802.11ac (5 GHz)</option>
                </select>
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label"><?php echo _("Width"); ?> <span id="widthSuggestion" class="chip-cap"></span></div>
                <select class="hfield-select" name="openap_channel_width" id="hsWidth" data-current-width="<?php echo (int) $apWidth; ?>">
                  <!-- filled by JS based on band + channel -->
                </select>
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label"><?php echo _("TX dBm"); ?></div>
                <input type="number" class="hfield-input" name="txpower" value="20" min="1" max="30" style="background:#f8fafc">
              </div>
            </div>
            <div style="font-size:10px;color:#94a3b8;display:flex;gap:8px;margin-top:2px">
              <span><i class="fas fa-wifi" style="color:#1e3a8a"></i> <?php echo htmlspecialchars($interface); ?> · <span id="chipInfo"><?php echo strtoupper($apHwMode) . ' · ' . $apCountry; ?></span></span>
            </div>
          </div>

          <!-- ═══ SECURITY ═══ -->
          <div class="hotspot-pane" id="hsp-security">
            <div class="hfield-row">
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("Security Type"); ?></div>
                <input type="text" class="hfield-input" value="<?php echo htmlspecialchars($apSecurityType, ENT_QUOTES); ?>" readonly style="background:#f1f5f9;cursor:default">
              </div>
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("Encryption"); ?></div>
                <input type="text" class="hfield-input" value="<?php echo htmlspecialchars($apEncryption, ENT_QUOTES); ?>" readonly style="background:#f1f5f9;cursor:default">
              </div>
            </div>
            <div class="hfield-row">
              <div class="hfield-group wide">
                <div class="hfield-label"><?php echo _("Pre-shared Key (PSK)"); ?></div>
                <div class="d-flex gap-1">
                  <input type="password" class="hfield-input" id="pskDisplay" name="wpa_passphrase" value="<?php echo htmlspecialchars($apPsk ?: '', ENT_QUOTES); ?>" style="flex:1;font-family:monospace" minlength="8" maxlength="63">
                  <button type="button" class="btn-icon-ss" data-hotspot-toggle-psk title="<?php echo _("Toggle visibility"); ?>"><i class="fas fa-eye" style="font-size:11px"></i></button>
                </div>
              </div>
              <button type="button" class="qr-thumb" data-openap-modal="wifi-qr" title="<?php echo _("WiFi QR code"); ?>" aria-label="<?php echo _("Open WiFi QR code"); ?>">
                <svg viewBox="0 0 44 44" width="34" height="34" aria-hidden="true" focusable="false">
                  <rect width="44" height="44" fill="#fff"/>
                  <rect x="4" y="4" width="36" height="36" fill="none" stroke="#1e3a8a" stroke-width="2" rx="3"/>
                  <rect x="8" y="8" width="10" height="10" fill="#1e3a8a" rx="1"/>
                  <rect x="22" y="8" width="6" height="6" fill="#1e3a8a" rx="1"/>
                  <rect x="32" y="8" width="4" height="10" fill="#1e3a8a" rx="1"/>
                  <rect x="8" y="22" width="6" height="4" fill="#1e3a8a" rx="1"/>
                  <rect x="26" y="22" width="10" height="10" fill="#1e3a8a" rx="1"/>
                  <rect x="8" y="30" width="10" height="6" fill="#1e3a8a" rx="1"/>
                  <rect x="22" y="32" width="6" height="4" fill="#1e3a8a" rx="1"/>
                </svg>
              </button>
            </div>
            <div style="font-size:10px;color:#64748b">
              <span class="chip-cap"><i class="fas fa-shield-alt" style="color:#059669"></i> <?php echo htmlspecialchars($apSecurityType); ?> <?php echo _("recommended for compatibility"); ?></span>
            </div>
          </div>

          <!-- ═══ ADVANCED ═══ -->
          <div class="hotspot-pane" id="hsp-advanced">
            <div class="hfield-row">
              <div class="hfield-group">
                <div class="hfield-label"><i class="fas fa-globe" style="color:#1e3a8a;font-size:9px"></i> <?php echo _("Country"); ?></div>
                <input type="text" class="hfield-input" value="<?php echo htmlspecialchars($apCountry, ENT_QUOTES); ?>" readonly style="background:#f1f5f9;cursor:default">
              </div>
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("Beacon Interval"); ?></div>
                <input type="text" class="hfield-input" value="100" readonly style="background:#f1f5f9;cursor:default">
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label">DTIM</div>
                <input type="text" class="hfield-input" value="2" readonly style="background:#f1f5f9;cursor:default">
              </div>
            </div>
            <div class="hfield-row">
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("RTS Threshold"); ?></div>
                <input type="text" class="hfield-input" value="2347" readonly style="background:#f1f5f9;cursor:default">
              </div>
              <div class="hfield-group">
                <div class="hfield-label"><?php echo _("Frag. Threshold"); ?></div>
                <input type="text" class="hfield-input" value="2346" readonly style="background:#f1f5f9;cursor:default">
              </div>
              <div class="hfield-group narrow">
                <div class="hfield-label"><?php echo _("Max STA"); ?></div>
                <input type="text" class="hfield-input" value="128" readonly style="background:#f1f5f9;cursor:default">
              </div>
            </div>
            <hr style="border:none;border-top:1px solid #f1f5f9;margin:6px 0">
            <div style="font-size:10px;color:#64748b;margin-bottom:4px;display:flex;align-items:center;gap:4px">
              <i class="fas fa-check-circle" style="color:#059669;font-size:9px"></i> <?php echo _("Options"); ?>:
            </div>
            <div class="row gx-3 gy-1">
              <div class="col-6">
                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #f1f5f9">
                  <span style="font-size:11px;color:#0f172a">WMM <span class="chip-cap">802.11e</span></span>
                  <input type="checkbox" class="form-check-input" <?php echo $apWmm ? 'checked' : ''; ?> disabled style="width:28px;height:16px;opacity:.6">
                </div>
              </div>
              <div class="col-6">
                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #f1f5f9">
                  <span style="font-size:11px;color:#0f172a"><?php echo _("AP Isolation"); ?></span>
                  <input type="checkbox" class="form-check-input" disabled style="width:28px;height:16px;opacity:.6">
                </div>
              </div>
              <div class="col-6">
                <div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #f1f5f9">
                  <span style="font-size:11px;color:#0f172a"><?php echo _("Ign. Broadcast"); ?></span>
                  <input type="checkbox" class="form-check-input" <?php echo $apIgnoreBroadcast ? 'checked' : ''; ?> disabled style="width:28px;height:16px;opacity:.6">
                </div>
              </div>
              <div class="col-6">
                <div class="d-flex justify-content-between align-items-center py-1">
                  <span style="font-size:11px;color:#0f172a"><?php echo _("Probe Resp."); ?></span>
                  <input type="checkbox" class="form-check-input" checked disabled style="width:28px;height:16px;opacity:.6">
                </div>
              </div>
            </div>
            <div class="unsupported-note mt-2">
              <i class="fas fa-info-circle" style="color:#94a3b8"></i>
              <?php echo _("Edit full configuration in"); ?> <a href="/hostapd_conf" style="color:#1e3a8a">hostapd.conf</a>
            </div>
          </div>

          <!-- ═══ LOGGING ═══ -->
          <div class="hotspot-pane" id="hsp-logging">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0" style="min-height:initial">
                  <input type="checkbox" class="form-check-input" checked disabled style="opacity:.6">
                </div>
                <span style="font-size:12px;color:#0f172a"><?php echo _("Enable logging"); ?></span>
              </div>
              <span class="chip-cap"><?php echo _("live"); ?></span>
            </div>
            <div class="log-preview">
              <?php echo htmlspecialchars($interface); ?>: AP-STA-CONNECTED<br>
              <?php echo htmlspecialchars($interface); ?>: CTRL-EVENT-CHANNEL-SWITCH<br>
              hostapd: <?php echo _("Started"); ?>
            </div>
            <div style="margin-top:8px;font-size:10px;color:#64748b;text-align:center">
              <i class="fas fa-external-link-alt"></i> <a href="/hostapd_conf#logoutput" style="color:#1e3a8a"><?php echo _("Full log"); ?></a>
            </div>
          </div>

        </div><!-- /hotspot-panes -->

        <!-- Footer: service controls + save -->
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <div class="card-footer d-flex justify-content-between align-items-center gap-2 px-3 py-2" style="border-top:1px solid #e2e8f0;background:#fafbfc">
            <div class="btn-group-ss">
              <?php if ($hostapdEnabled): ?>
                <button type="submit" name="RestartHotspot" class="btn-ss" style="font-size:10px"><i class="fas fa-sync-alt"></i> <?php echo _("Restart"); ?></button>
                <button type="submit" name="StopHotspot" class="btn-ss danger" style="font-size:10px;padding:0 8px"><i class="fas fa-stop"></i></button>
              <?php else: ?>
                <button type="submit" name="StartHotspot" class="btn-ss success" style="font-size:10px;padding:0 8px"><i class="fas fa-play"></i></button>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span style="font-size:10px;color:#94a3b8"><?php echo (int)$totalClients; ?> clients</span>
              <button type="submit" name="SaveHostAPDSettings" class="btn-ss primary" style="font-size:10px"><i class="fas fa-save"></i> <?php echo _("Save"); ?></button>
            </div>
          </div>
        </form>
          </div>
        </div>
      </div>
    </div>

    <!-- WiFi QR Modal -->
    <div class="modal fade" id="wifiQrModal" tabindex="-1" aria-labelledby="wifiQrModalTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered wifi-qr-dialog">
        <div class="modal-content wifi-qr-modal">
          <div class="modal-header wifi-qr-header">
            <div class="wifi-qr-heading">
              <span class="wifi-qr-heading-icon" aria-hidden="true"><i class="fas fa-qrcode"></i></span>
              <div>
                <div class="wifi-qr-eyebrow"><?php echo _("WiFi access"); ?></div>
                <h2 id="wifiQrModalTitle" class="wifi-qr-title"><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></h2>
                <div class="wifi-qr-subtitle"><?php echo _("Scan to connect to the OpenAP hotspot"); ?></div>
              </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
          </div>
          <div class="modal-body wifi-qr-body">
            <div class="wifi-qr-frame">
              <img src="app/img/wifi-qr-code.php" alt="<?php echo _("OpenAP WiFi QR code"); ?>" class="wifi-qr-image">
            </div>
            <div class="wifi-qr-details">
              <div class="wifi-qr-detail"><span><i class="fas fa-wifi" aria-hidden="true"></i><?php echo _("Network"); ?></span><strong><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></strong></div>
              <div class="wifi-qr-detail"><span><i class="fas fa-shield-alt" aria-hidden="true"></i><?php echo _("Security"); ?></span><strong><?php echo htmlspecialchars($apSecurityType ?: 'WPA', ENT_QUOTES); ?></strong></div>
            </div>
            <div class="wifi-qr-hint"><i class="fas fa-mobile-alt"></i> <?php echo _("Scan with a phone camera to join the hotspot."); ?></div>
          </div>
          <div class="modal-footer wifi-qr-footer">
            <a class="btn-ss" href="/app/lib/signprint.php" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <?php echo _("Open full page"); ?></a>
            <a class="btn-ss primary" href="/app/img/wifi-qr-code.php?download=1"><i class="fas fa-download"></i> <?php echo _("Download SVG"); ?></a>
          </div>
        </div>
      </div>
    </div>




    <!-- AP/DHCP Logs Modal -->
    <div class="modal fade" id="serviceLogsModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden">
          <div class="modal-header py-2 px-3" style="border-bottom:1px solid #e2e8f0;min-height:42px">
            <div style="font-size:14px;font-weight:600;color:#0f172a"><i class="fas fa-file-alt me-2" style="color:#1e3a8a"></i><?php echo _("AP and DHCP logs"); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:12px"></button>
          </div>
          <div class="modal-body" style="padding:0">
            <pre style="margin:0;max-height:460px;overflow:auto;background:#0f172a;color:#dbeafe;font-size:11px;line-height:1.45;padding:14px;white-space:pre-wrap"><?php echo htmlspecialchars($dashboardServiceLogs ?: _('No recent AP/DHCP logs.'), ENT_QUOTES); ?></pre>
          </div>
        </div>
      </div>
    </div>

    <!-- AP Ethernet Modal -->
    <div class="modal fade" id="apEthernetModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
        <div class="modal-content openap-ap-ethernet-modal">
          <div class="modal-header openap-ap-ethernet-header">
            <div>
              <div class="openap-ap-ethernet-title"><?php echo _("Configure Ethernet Mode"); ?></div>
              <div class="openap-ap-ethernet-subtitle"><?php echo _("Configure wired uplink"); ?></div>
            </div>
            <div class="openap-ap-ethernet-header-actions">
              <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
            </div>
          </div>
          <div class="modal-body openap-ap-ethernet-body">
            <div id="apEthernetModalContent">
              <div class="text-center py-4">
                <div class="openap-ap-ethernet-title"><?php echo _("Ethernet Mode"); ?></div>
                <p style="font-size:13px;color:#64748b;margin-top:8px"><?php echo _("Loading configuration..."); ?></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Uplink Selection Modal -->
    <div class="modal fade" id="uplinkModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-dialog-centered openap-repeater-dialog">
        <div class="modal-content openap-repeater-modal">
          <div class="modal-header openap-repeater-header">
            <div>
              <div class="openap-repeater-title"><?php echo _("Repeater Mode"); ?></div>
              <div class="openap-repeater-subtitle"><?php echo _("Select the WiFi uplink network"); ?></div>
            </div>
            <div class="openap-repeater-header-actions">
              <button type="button" class="openap-repeater-change-uplink" id="openapChangeUplink" onclick="window.openapScanUplinkNetworks(this); return false;" hidden>
                <i class="fas fa-exchange-alt" aria-hidden="true"></i>
                <span><?php echo _("Change uplink"); ?></span>
              </button>
              <span class="openap-repeater-header-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
            </div>
          </div>
          <div class="modal-body openap-repeater-body">
            <div id="uplinkModalContent">
              <div class="openap-uplink-scan-state" role="status" aria-live="polite">
                <div class="openap-uplink-scan-visual" aria-hidden="true">
                  <span class="openap-uplink-scan-ring"></span>
                  <span class="openap-uplink-scan-icon"><i class="fas fa-wifi"></i></span>
                </div>
                <div class="openap-uplink-scan-label"><?php echo _("Scanning for available networks"); ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Repeater uplink recovery -->
    <div class="modal fade" id="uplinkRecoveryModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="uplinkRecoveryTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered openap-recovery-dialog">
        <div class="modal-content openap-mode-switch-card openap-recovery-modal">
          <button type="button" class="btn-close openap-recovery-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
          <div class="openap-mode-switch-eyebrow"><i class="fas fa-shuffle"></i> OpenAP</div>
          <div class="openap-mode-switch-title" id="uplinkRecoveryTitle"><?php echo _("Restoring Internet connection"); ?></div>
          <div class="openap-mode-switch-caption"><?php echo _("The OpenAP hotspot remains active while the uplink recovers."); ?></div>

          <div class="openap-mode-switch-path openap-recovery-path" aria-hidden="true">
              <div class="openap-mode-node source openap-recovery-node is-failed">
                <span><i class="fas fa-wifi"></i></span>
                <small><?php echo _("WiFi uplink"); ?></small>
              </div>
              <div class="openap-mode-link openap-recovery-link is-failed"><span></span><i class="fas fa-circle"></i></div>
              <div class="openap-mode-node target openap-recovery-node">
                <span><i class="fas fa-globe"></i></span>
                <small><?php echo _("Internet"); ?></small>
              </div>
          </div>

          <div class="openap-recovery-summary">
            <strong id="uplinkRecoveryReason"><?php echo _("WiFi uplink is unavailable"); ?></strong>
            <span id="uplinkRecoveryAttempt"><?php echo _("OpenAP is checking the saved network"); ?></span>
          </div>

          <div class="openap-mode-switch-steps openap-recovery-steps">
            <div id="uplinkRecoveryStepDetect" class="done"><i class="fas fa-check-circle"></i><span><?php echo _("Uplink loss detected"); ?></span></div>
            <div id="uplinkRecoveryStepRestart" class="active"><i class="fas fa-circle-notch fa-spin"></i><span><?php echo _("Trying the saved WiFi uplink"); ?></span></div>
            <div id="uplinkRecoveryStepVerify"><i class="far fa-circle"></i><span><?php echo _("Waiting for IP and Internet"); ?></span></div>
          </div>

          <div class="openap-recovery-fallback" id="uplinkRecoveryFallback">
            <div>
              <strong><i class="fas fa-network-wired"></i> <?php echo _("Ethernet Mode fallback"); ?></strong>
              <span id="uplinkRecoveryEthernetReason"><?php echo _("Checking the Ethernet connection…"); ?></span>
            </div>
            <button type="button" class="btn-ss primary" id="uplinkRecoveryEthernetButton" hidden>
              <i class="fas fa-arrow-right"></i> <?php echo _("Switch to Ethernet Mode"); ?>
            </button>
          </div>
        </div>
      </div>
    </div>

    <?php if (isset($_GET['rebooting'])): ?>
    <div class="modal fade" id="rebootProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="rebootProgressTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered openap-reboot-progress-dialog">
        <div class="modal-content openap-mode-switch-card openap-universal-mode-apply openap-reboot-progress-card">
          <div class="modal-body openap-reboot-progress-body">
            <div id="rebootProgressTitle" class="openap-mode-switch-title"><?php echo _("System is rebooting"); ?></div>
            <div class="openap-universal-mode-visual" aria-hidden="true"><span><i class="fas fa-power-off"></i></span></div>
            <div id="rebootProgressText" class="openap-mode-switch-caption">
              <?php echo _("Waiting for OpenAP to become reachable again."); ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('rebootProgressModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
          return;
        }

        var modal = new bootstrap.Modal(modalEl, {
          backdrop: 'static',
          keyboard: false
        });
        var textEl = document.getElementById('rebootProgressText');
        var startedAt = Date.now();
        var seenOffline = false;
        var attempts = 0;
        var probeTimer = null;
        var finishing = false;

        modal.show();

        function setMessage(message) {
          if (textEl) {
            textEl.textContent = message;
          }
        }

        function finish() {
          if (finishing) {
            return;
          }
          finishing = true;
          setMessage('<?php echo _("Connection restored. Reloading dashboard..."); ?>');
          setTimeout(function () {
            var cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('rebooting');
            window.location.replace(cleanUrl.toString());
          }, 900);
        }

        function scheduleProbe(delay) {
          if (probeTimer !== null) {
            window.clearTimeout(probeTimer);
          }
          probeTimer = window.setTimeout(probe, delay);
        }

        function probe() {
          probeTimer = null;
          attempts += 1;
          if (navigator.onLine === false) {
            seenOffline = true;
          }

          var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
          var timeout = controller ? window.setTimeout(function () {
            controller.abort();
          }, 3500) : null;
          var healthUrl = new URL('/api/health', window.location.origin);
          healthUrl.searchParams.set('reboot_probe', Date.now());

          fetch(healthUrl.toString(), {
            cache: 'no-store',
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
          }).then(function (response) {
            if (timeout !== null) {
              window.clearTimeout(timeout);
            }
            var waitedLongEnough = Date.now() - startedAt > 20000;
            if ((response.ok || response.status === 401 || response.status === 403) && (seenOffline || waitedLongEnough)) {
              finish();
              return;
            }
            setMessage('<?php echo _("Reboot command sent. Waiting for services to restart..."); ?>');
            scheduleProbe(2000);
          }).catch(function () {
            if (timeout !== null) {
              window.clearTimeout(timeout);
            }
            seenOffline = true;
            setMessage(attempts < 4
              ? '<?php echo _("OpenAP is going offline..."); ?>'
              : '<?php echo _("Reconnecting to OpenAP..."); ?>'
            );
            scheduleProbe(2000);
          });
        }

        window.addEventListener('offline', function () {
          seenOffline = true;
          setMessage('<?php echo _("OpenAP is going offline..."); ?>');
        });
        window.addEventListener('online', function () {
          seenOffline = true;
          setMessage('<?php echo _("Reconnecting to OpenAP..."); ?>');
          scheduleProbe(0);
        });

        scheduleProbe(6000);
      });
    </script>
    <?php endif; ?>
