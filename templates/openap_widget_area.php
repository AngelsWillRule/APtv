<?php
/**
 * Shared widget area, ported from the dashboard sidebar so it can be embedded
 * on any page routed through DisplayDashboard() (currently 'dashboard' and
 * 'ap_configuration').
 *
 * Expects in scope (set by the including template):
 *   - $openapWidgetPage : string page key ('dashboard' | 'ap_configuration')
 *   - all widget data vars already passed to the template via compact()
 *     (totalClients, clientBreakdown, trafficApTx, dhcpPool, currentMode,
 *      serviceList, interface, apIface, ...).
 *
 * Self-contained: derives its DHCP/widget state from the data in scope and
 * defines its own renderer closures, so no page-specific helper is required.
 */

if (empty($openapWidgetPage) || !is_string($openapWidgetPage)) {
    $openapWidgetPage = 'dashboard';
}

// Dashboard and AP Configuration share this partial, but their page scopes
// are not identical. Keep the renderer safe when an older or custom caller
// omits one of the display-only values.
$apBand = (string) ($apBand ?? 'Auto');
$uplinkSsid = (string) ($uplinkSsid ?? 'Ethernet');
$trafficApTx = (string) ($trafficApTx ?? '0 B');
$trafficApRx = (string) ($trafficApRx ?? '0 B');
$trafficUplinkTx = (string) ($trafficUplinkTx ?? '0 B');
$trafficUplinkRx = (string) ($trafficUplinkRx ?? '0 B');

$openapWidgetUser = (string) ($_SESSION['user_id'] ?? '');
$openapWidgetLayout = openapWidgetLayoutRead($openapWidgetUser, $openapWidgetPage);
$openapWidgetOrder = $openapWidgetLayout['order'];
$openapWidgetHidden = $openapWidgetLayout['hidden'];
$openapWidgetWidths = $openapWidgetLayout['widths'];
$openapWidgetPositions = array_flip($openapWidgetOrder);

// Widget data derived from the shared page scope (all present via compact()).
$openapWidgetDhcpActive = (int) ($dhcpPool['active'] ?? 0);
$openapWidgetDhcpTotal = (int) ($dhcpPool['total'] ?? 150);
$openapWidgetDhcpRange = ($dhcpPool['range_start'] ?? '10.88.77.50') . ' - ' . ($dhcpPool['range_end'] ?? '10.88.77.200');
$openapWidgetDhcpDns = $dhcpPool['dns'] ?? '10.88.77.1';
$openapWidgetDhcpDnsAddresses = array_values(array_filter(array_map('trim', explode(',', (string) $openapWidgetDhcpDns))));
$openapWidgetDhcpDnsDisplay = count($openapWidgetDhcpDnsAddresses) > 1
    ? $openapWidgetDhcpDnsAddresses[0] . ' +' . (count($openapWidgetDhcpDnsAddresses) - 1)
    : (string) $openapWidgetDhcpDns;
$openapWidgetDhcpProvider = $dhcpPool['dns_provider'] ?? 'Custom';
$openapWidgetDhcpTransport = $dhcpPool['dns_transport'] ?? 'Standard';
$openapWidgetDhcpTransportDisplay = preg_match('/\bDoH\b/i', (string) $openapWidgetDhcpTransport)
    ? 'DoH'
    : (string) $openapWidgetDhcpTransport;
$openapWidgetCurrentMode = $currentMode ?? 'ap_ethernet_routed';
$openapWidgetApSsid = trim((string) ($apSsid ?? $ssid ?? '-')) ?: '-';
$openapUptimeSeconds = 0;
if (is_readable('/proc/uptime')) {
    $openapUptimeParts = preg_split('/\s+/', trim((string) file_get_contents('/proc/uptime')));
    $openapUptimeSeconds = max(0, (int) floor((float) ($openapUptimeParts[0] ?? 0)));
}
$openapUptimeDays = intdiv($openapUptimeSeconds, 86400);
$openapUptimeHours = intdiv($openapUptimeSeconds % 86400, 3600);
$openapUptimeMinutes = intdiv($openapUptimeSeconds % 3600, 60);
$sysUptimeStr = sprintf('%dd %dh %dm', $openapUptimeDays, $openapUptimeHours, $openapUptimeMinutes);

$openapRenderServiceStatus = function () use ($serviceList, $interface, $apIface) {
    ob_start();
    require __DIR__ . '/openap_service_status.php';
    return ob_get_clean();
};

$openapRenderDhcpPool = function () use (
    $openapWidgetCurrentMode,
    $openapWidgetDhcpActive,
    $openapWidgetDhcpTotal,
    $openapWidgetDhcpRange,
    $openapWidgetDhcpDns,
    $openapWidgetDhcpDnsDisplay,
    $openapWidgetDhcpProvider,
    $openapWidgetDhcpTransport,
    $openapWidgetDhcpTransportDisplay
) {
    ob_start();
    ?>
    <div class="stat-card openap-side-dhcp openap-dhcp-setting-widget" data-openap-dhcp-mode="<?php echo $openapWidgetCurrentMode === 'ap_ethernet_bridge' ? 'upstream' : 'local'; ?>">
      <div class="stat-top openap-widget-body">
        <div class="openap-widget-heading">
          <div>
            <div class="openap-widget-title"><?php echo _("DHCP setting"); ?></div>
            <div class="openap-widget-caption"><?php echo $openapWidgetCurrentMode === 'ap_ethernet_bridge' ? _("Upstream managed") : _("Hotspot address pool"); ?></div>
          </div>
          <div class="openap-widget-icon openap-widget-icon-blue"><i class="fas <?php echo $openapWidgetCurrentMode === 'ap_ethernet_bridge' ? 'fa-ban' : 'fa-arrow-right-arrow-left'; ?>"></i></div>
        </div>
        <?php if ($openapWidgetCurrentMode === 'ap_ethernet_bridge'): ?>
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
            <strong data-openap-dhcp-leases><?php echo $openapWidgetDhcpActive; ?> <span>/ <?php echo $openapWidgetDhcpTotal; ?></span></strong>
            <small><?php echo _("Leases active"); ?></small>
          </div>
          <div class="openap-dhcp-meta">
            <span><?php echo _("Client DNS"); ?> <strong class="text-nowrap" data-openap-dhcp-dns title="<?php echo htmlspecialchars($openapWidgetDhcpDns, ENT_QUOTES); ?>"><?php echo htmlspecialchars($openapWidgetDhcpDnsDisplay); ?></strong></span>
            <span><?php echo _("Upstream"); ?> <strong data-openap-dhcp-provider><?php echo htmlspecialchars($openapWidgetDhcpProvider); ?></strong></span>
            <span><?php echo _("Transport"); ?> <strong data-openap-dhcp-transport title="<?php echo htmlspecialchars($openapWidgetDhcpTransport, ENT_QUOTES); ?>"><?php echo htmlspecialchars($openapWidgetDhcpTransportDisplay); ?></strong></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="stat-bottom">
        <?php if ($openapWidgetCurrentMode === 'ap_ethernet_bridge'): ?>
        <span><i class="fas fa-ban"></i> <?php echo _("Managed upstream"); ?></span>
        <strong><?php echo _("Local DHCP disabled"); ?></strong>
        <?php else: ?>
        <span><i class="fas fa-network-wired"></i> <?php echo _("Pool"); ?></span>
        <strong data-openap-dhcp-range><?php echo htmlspecialchars($openapWidgetDhcpRange); ?></strong>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
};

// Compute the card body for a single widget id.
$openapRenderWidgetBody = function (string $id) use (
    $isRepeaterWifi,
    $apBand,
    $openapWidgetApSsid,
    $uplinkIface,
    $uplinkSsid,
    $uplinkGateway,
    $publicIpv4Address,
    $uplinkConnected,
    $totalClients,
    $clientBreakdown,
    $band5Count,
    $band24Count,
    $activeApBands,
    $trafficApTx,
    $trafficApRx,
    $trafficUplinkTx,
    $trafficUplinkRx,
    $cpuPercent,
    $memUsedPct,
    $sysTemp,
    $sysUptimeStr,
    $diskUsedPct,
    $loadAvg,
    $openapRenderServiceStatus,
    $openapRenderDhcpPool
) {
    switch ($id) {
        case 'clients':
            ?>
            <div class="stat-card border-top-blue openap-connected-clients-widget">
              <div class="stat-top openap-widget-body">
                <div class="openap-widget-heading">
                  <div>
                    <div class="openap-widget-title"><?php echo _("Connected clients"); ?></div>
                    <div class="openap-widget-caption"><?php echo _("Stations on the hotspot"); ?></div>
                  </div>
                  <div class="openap-widget-icon openap-widget-icon-blue"><i class="fas fa-wifi"></i></div>
                </div>
                <div class="openap-widget-metric">
                  <div class="openap-widget-direction openap-widget-direction-blue"><i class="fas fa-users"></i></div>
                  <div class="openap-widget-copy">
                    <span><?php echo _("AP network"); ?></span>
                    <small data-openap-ap-ssid title="<?php echo htmlspecialchars($openapWidgetApSsid, ENT_QUOTES); ?>"><?php echo htmlspecialchars($openapWidgetApSsid); ?></small>
                  </div>
                  <div class="openap-widget-value" data-openap-client-count><?php echo (int)$totalClients; ?></div>
                </div>
                <div class="openap-client-band-summary<?php echo empty($activeApBands) ? ' is-hotspot-stopped' : ''; ?>" data-openap-client-band-summary aria-label="<?php echo _("Connected clients by WiFi band"); ?>">
                  <?php if (empty($activeApBands)): ?>
                  <div class="openap-client-hotspot-stopped" role="status"><i class="fas fa-info-circle" aria-hidden="true"></i><div><strong><?php echo _("Hotspot stopped"); ?></strong><span><?php echo _("Client bands are unavailable"); ?></span></div></div>
                  <?php else: ?>
                  <?php if (in_array('5', $activeApBands, true)): ?>
                  <span class="openap-client-band-count"><span class="openap-hotspot-band-badge">5G</span><strong data-openap-client-band="5"><?php echo (int)$band5Count; ?></strong></span>
                  <?php endif; ?>
                  <?php if (in_array('2.4', $activeApBands, true)): ?>
                  <span class="openap-client-band-count"><span class="openap-hotspot-band-badge">2.4G</span><strong data-openap-client-band="2.4"><?php echo (int)$band24Count; ?></strong></span>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="stat-bottom">
                <span><i class="fas fa-tower-broadcast"></i> <?php echo _("Clients by AP band"); ?></span>
                <strong><?php echo _("Total"); ?>: <span data-openap-client-count><?php echo (int)$totalClients; ?></span></strong>
              </div>
            </div>
            <?php
            return '';
        case 'traffic':
            ?>
            <div class="stat-card border-top-green openap-hotspot-traffic-widget">
              <div class="stat-top openap-traffic-widget">
                <div class="openap-traffic-heading openap-widget-heading">
                  <div>
                    <div class="openap-traffic-title openap-widget-title"><?php echo _("Hotspot traffic"); ?></div>
                    <div class="openap-traffic-caption openap-widget-caption"><?php echo _("Totals since interface start"); ?></div>
                  </div>
                  <div class="openap-traffic-main-icon openap-widget-icon openap-widget-icon-green"><i class="fas fa-arrow-right-arrow-left"></i></div>
                </div>
                <div class="openap-traffic-metric openap-traffic-tx openap-widget-metric">
                  <div class="openap-traffic-direction openap-widget-direction"><i class="fas fa-arrow-up"></i></div>
                  <div class="openap-traffic-copy openap-widget-copy">
                    <span><?php echo _("Sent"); ?> <strong>TX</strong></span>
                    <small><?php echo _("AP → clients"); ?></small>
                  </div>
                  <div class="openap-traffic-value openap-widget-value" data-openap-traffic-total="ap-tx"><?php echo $trafficApTx; ?></div>
                </div>
                <div class="openap-traffic-metric openap-traffic-rx openap-widget-metric">
                  <div class="openap-traffic-direction openap-widget-direction"><i class="fas fa-arrow-down"></i></div>
                  <div class="openap-traffic-copy openap-widget-copy">
                    <span><?php echo _("Received"); ?> <strong>RX</strong></span>
                    <small><?php echo _("Clients → AP"); ?></small>
                  </div>
                  <div class="openap-traffic-value openap-widget-value" data-openap-traffic-total="ap-rx"><?php echo $trafficApRx; ?></div>
                </div>
              </div>
              <div class="stat-bottom openap-traffic-uplink">
                <span title="<?php echo _("Sent through the uplink"); ?>"><i class="fas fa-arrow-up"></i> <?php echo _("Uplink sent"); ?> <strong data-openap-traffic-total="uplink-tx"><?php echo $trafficUplinkTx; ?></strong></span>
                <span title="<?php echo _("Received through the uplink"); ?>"><i class="fas fa-arrow-down"></i> <?php echo _("Uplink received"); ?> <strong data-openap-traffic-total="uplink-rx"><?php echo $trafficUplinkRx; ?></strong></span>
              </div>
            </div>
            <?php
            return '';
        case 'uplink':
            ?>
            <div class="stat-card border-top-gold openap-network-uplink-widget">
              <div class="stat-top openap-widget-body">
                <div class="openap-widget-heading">
                  <div>
                    <div class="openap-widget-title"><?php echo $isRepeaterWifi ? _("WiFi uplink") : _("Ethernet uplink"); ?></div>
                    <div class="openap-widget-caption"><?php echo _("Active network path"); ?></div>
                  </div>
                  <div class="openap-widget-icon openap-widget-icon-gold"><i class="fas <?php echo $isRepeaterWifi ? 'fa-signal' : 'fa-network-wired'; ?>"></i></div>
                </div>
                <?php if ($isRepeaterWifi): ?>
                <div class="openap-widget-metric openap-widget-metric-uplink">
                  <div class="openap-widget-direction openap-widget-direction-gold"><i class="fas fa-wifi"></i></div>
                  <div class="openap-widget-copy">
                    <span><?php echo _("Network"); ?></span>
                    <small title="<?php echo htmlspecialchars($uplinkIface, ENT_QUOTES); ?>"><?php echo htmlspecialchars($uplinkIface); ?></small>
                  </div>
                  <div class="openap-widget-value openap-widget-value-compact" title="<?php echo htmlspecialchars($uplinkSsid, ENT_QUOTES); ?>"><?php echo htmlspecialchars($uplinkSsid); ?></div>
                </div>
                <div class="openap-widget-metric">
                  <div class="openap-widget-direction openap-widget-direction-gold"><i class="fas fa-route"></i></div>
                  <div class="openap-widget-copy">
                    <span><?php echo _("Gateway"); ?></span>
                    <small><?php echo _("Default route"); ?></small>
                  </div>
                  <div class="openap-widget-value openap-widget-value-compact" data-openap-uplink-gateway><?php echo htmlspecialchars($uplinkGateway ?: '-'); ?></div>
                </div>
                <?php else: ?>
                <div class="openap-widget-metric">
                  <div class="openap-widget-direction openap-widget-direction-gold"><i class="fas fa-network-wired"></i></div>
                  <div class="openap-widget-copy">
                    <span><?php echo _("Interface"); ?></span>
                    <small><?php echo htmlspecialchars($uplinkIface); ?></small>
                  </div>
                  <div class="openap-widget-value openap-widget-value-compact" data-openap-uplink-address><?php echo htmlspecialchars($publicIpv4Address ?: '-'); ?></div>
                </div>
                <div class="openap-widget-metric">
                  <div class="openap-widget-direction openap-widget-direction-gold"><i class="fas fa-route"></i></div>
                  <div class="openap-widget-copy">
                    <span><?php echo _("Gateway"); ?></span>
                    <small><?php echo _("Default route"); ?></small>
                  </div>
                  <div class="openap-widget-value openap-widget-value-compact" data-openap-uplink-gateway><?php echo htmlspecialchars($uplinkGateway ?: '-'); ?></div>
                </div>
                <?php endif; ?>
              </div>
              <div class="stat-bottom">
                <span>Uplink: <?php echo $uplinkConnected ? 'Connected' : 'Disconnected'; ?></span>
                <span>AP band: <?php echo $apBand; ?></span>
              </div>
            </div>
            <?php
            return '';
        case 'system-health':
            ?>
            <div class="stat-card border-top-purple openap-system-health-widget">
              <div class="stat-top openap-widget-body">
                <div class="openap-widget-heading">
                  <div>
                    <div class="openap-widget-title"><?php echo _("System health"); ?></div>
                    <div class="openap-widget-caption"><?php echo _("Live resource usage"); ?></div>
                  </div>
                  <div class="openap-widget-icon openap-widget-icon-purple"><i class="fas fa-microchip"></i></div>
                </div>
                <div class="openap-widget-resource-grid">
                  <div><span class="openap-system-health-metric-icon"><i class="fas fa-microchip" aria-hidden="true"></i></span><span class="openap-system-health-metric-label"><?php echo _("CPU used"); ?></span><strong><?php echo (int)$cpuPercent; ?>%</strong></div>
                  <div><span class="openap-system-health-metric-icon"><i class="fas fa-memory" aria-hidden="true"></i></span><span class="openap-system-health-metric-label"><?php echo _("RAM used"); ?></span><strong><?php echo (int)$memUsedPct; ?>%</strong></div>
                  <div><span class="openap-system-health-metric-icon"><i class="fas fa-temperature-half" aria-hidden="true"></i></span><span class="openap-system-health-metric-label"><?php echo _("Temperature"); ?></span><strong><?php echo htmlspecialchars($sysTemp); ?>&deg;C</strong></div>
                  <div><span class="openap-system-health-metric-icon"><i class="fas fa-clock" aria-hidden="true"></i></span><span class="openap-system-health-metric-label"><?php echo _("Uptime"); ?></span><strong><?php echo htmlspecialchars($sysUptimeStr); ?></strong></div>
                </div>
              </div>
              <div class="stat-bottom">
                <span><i class="fas fa-hdd"></i> <?php echo _("Disk"); ?>: <?php echo (int)$diskUsedPct; ?>%</span>
                <span><?php echo _("Load"); ?>: <?php echo htmlspecialchars($loadAvg); ?></span>
              </div>
            </div>
            <?php
            return '';
        case 'services':
            echo $openapRenderServiceStatus();
            return '';
        case 'dhcp':
            echo $openapRenderDhcpPool();
            return '';
        default:
            return '';
    }
};
?>
<section class="openap-widget-area" id="openapWidgetArea" aria-label="<?php echo _("OpenAP widgets"); ?>">
  <div class="openap-widget-area-toolbar">
    <div><strong><?php echo _("OpenAP widgets"); ?></strong></div>
    <div class="openap-widget-area-actions">
      <button type="button" class="openap-widget-save" data-openap-widget-save hidden><i class="fas fa-floppy-disk" aria-hidden="true"></i><span><?php echo _("Save"); ?></span></button>
      <button type="button" class="openap-widget-reset" data-openap-widget-reset hidden><i class="fas fa-rotate-left" aria-hidden="true"></i><span><?php echo _("Reset"); ?></span></button>
      <button type="button" class="openap-widget-edit-toggle" data-openap-widget-edit aria-pressed="false"><i class="fas fa-sliders" aria-hidden="true"></i><span><?php echo _("Customize"); ?></span></button>
    </div>
  </div>
  <div class="row g-3 openap-widget-grid" data-openap-widget-grid>
<?php foreach ($openapWidgetOrder as $openapWidgetId): ?>
    <div class="<?php echo $openapWidgetId === 'system-health' ? 'col-12' : ($openapWidgetId === 'dhcp' ? 'col-6' : ($openapWidgetWidths[$openapWidgetId] ?? 'col-12')); ?> openap-widget-item<?php echo $openapWidgetId === 'system-health' ? ' openap-widget-size-2x1-horizontal' : ($openapWidgetId === 'dhcp' ? ' openap-widget-size-1x1' : ''); ?><?php echo in_array($openapWidgetId, $openapWidgetHidden, true) ? ' is-hidden' : ''; ?>" data-openap-widget-id="<?php echo $openapWidgetId; ?>" style="order:<?php echo (int) ($openapWidgetPositions[$openapWidgetId] ?? 0); ?>">
      <?php echo $openapRenderWidgetBody($openapWidgetId); ?>
    </div>
<?php endforeach; ?>
  </div>
  <div class="openap-widget-hidden-tray" data-openap-widget-hidden-tray hidden></div>
  <div class="openap-widget-edit-status" data-openap-widget-status role="status" aria-live="polite"></div>
</section>
<script>
window.openapWidgets = window.openapWidgets || {};
window.openapWidgets.page = <?php echo json_encode($openapWidgetPage, JSON_UNESCAPED_SLASHES); ?>;
window.openapWidgets.order = <?php echo json_encode($openapWidgetOrder, JSON_UNESCAPED_SLASHES); ?>;
window.openapWidgets.hidden = <?php echo json_encode($openapWidgetHidden, JSON_UNESCAPED_SLASHES); ?>;
window.openapWidgets.widths = <?php echo json_encode($openapWidgetWidths, JSON_UNESCAPED_SLASHES); ?>;
window.openapWidgets.moduleNames = {
  'clients': <?php echo json_encode(_("Connected clients"), JSON_UNESCAPED_SLASHES); ?>,
  'traffic': <?php echo json_encode(_("Hotspot traffic"), JSON_UNESCAPED_SLASHES); ?>,
  'uplink': <?php echo json_encode(_("Network uplink"), JSON_UNESCAPED_SLASHES); ?>,
  'system-health': <?php echo json_encode(_("System health"), JSON_UNESCAPED_SLASHES); ?>,
  'services': <?php echo json_encode(_("Service status"), JSON_UNESCAPED_SLASHES); ?>,
  'dhcp': <?php echo json_encode(_("DHCP pool"), JSON_UNESCAPED_SLASHES); ?>
};
</script>
