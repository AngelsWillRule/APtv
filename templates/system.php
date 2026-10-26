<?php
function openapSystemEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function openapSystemBadge(string $state, string $class): string
{
    return '<span class="openap-system-badge openap-system-badge-' . openapSystemEscape($class) . '">' . openapSystemEscape($state) . '</span>';
}

$openapActiveServices = count(array_filter($services, static function (array $service): bool {
    return ($service['statusClass'] ?? '') === 'up';
}));
$openapServiceDescriptions = [
    'hostapd.service' => _('WiFi access point'),
    'dnsmasq.service' => _('DHCP + DNS'),
    'openap-uplink.service' => _('WiFi uplink'),
    'openap-firewall.service' => _('NAT / Firewall'),
    'lighttpd.service' => _('Web server'),
    'systemd-networkd.service' => _('Network backend'),
];
$systemProfile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
$systemCurrentMode = str_replace('-', '_', (string) ($systemProfile['mode']['current'] ?? 'ap_ethernet'));
$systemIsRepeaterWifi = $systemCurrentMode === 'repeater_wifi';
$systemUplinkHealth = $systemIsRepeaterWifi && function_exists('openapUplinkHealth') ? openapUplinkHealth() : ['ready' => true];
$systemUplinkConnected = !$systemIsRepeaterWifi || !empty($systemUplinkHealth['ready']);
$systemHeaderServices = [
    'hostapd' => function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active',
    'dnsmasq' => function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active',
    'nftables' => function_exists('openapNatActive') ? openapNatActive() : (function_exists('openapServiceActive') && openapServiceActive('nftables.service') === 'active'),
    'lighttpd' => function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active',
];
$systemHostapdEnabled = $systemHeaderServices['hostapd'];
$systemTopologyHealthy = $systemHostapdEnabled
    && $systemUplinkConnected
    && $systemHeaderServices['dnsmasq']
    && $systemHeaderServices['nftables']
    && $systemHeaderServices['lighttpd'];
if ($systemCurrentMode === 'ap_ethernet_bridge') {
    $systemTopologyHealthy = $systemHostapdEnabled && $systemUplinkConnected && $systemHeaderServices['lighttpd'];
}
$systemModeIcon = in_array($systemCurrentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true) ? 'fa-network-wired' : 'fa-wifi';
$systemModeLabel = $systemCurrentMode === 'ap_ethernet_bridge' ? _('Ethernet Bridge') : ($systemCurrentMode === 'ap_ethernet' ? _('Ethernet Mode') : _('Repeater'));
$systemLiveState = !$systemHostapdEnabled ? 'offline' : ($systemTopologyHealthy ? 'live' : 'degraded');
?>
<div class="container-fluid p-0 openap-system-layout">
  <?php $status->showMessages(); ?>

  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <div class="openap-section-heading openap-topology-header openap-page-main-header openap-dashboard-main-header openap-system-heading">
        <div class="openap-topology-header-title">
          <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-server"></i></span>
          <div><strong><?php echo _("System"); ?></strong></div>
        </div>
      </div>

      <div class="openap-system-header-actions-row">
        <button type="button" class="openap-system-action danger" data-bs-toggle="modal" data-bs-target="#system-reboot-modal"><i class="fas fa-power-off"></i><span><?php echo _("Reboot"); ?></span></button>
        <button type="button" onClick="window.location.reload();" class="openap-system-action"><i class="fas fa-sync-alt"></i><span><?php echo _("Refresh"); ?></span></button>
      </div>

      <div class="card shadow openap-system-shell">
        <div class="card-body openap-system-page">
          <div class="openap-system-hero">
            <div class="openap-system-identity">
              <span class="openap-system-identity-icon"><i class="fas fa-microchip"></i></span>
              <div>
                <div class="openap-system-kicker"><?php echo _("OpenAP diagnostics"); ?></div>
                <h4><?php echo openapSystemEscape($hostname); ?></h4>
                <div class="text-muted"><?php echo openapSystemEscape($os); ?> &middot; <?php echo openapSystemEscape($kernel); ?></div>
              </div>
            </div>
          </div>

        <div class="openap-system-metrics">
          <div class="openap-system-metric">
            <i class="fas fa-microchip"></i>
            <span><?php echo _("CPU Load"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($cpuload_status); ?>"><?php echo openapSystemEscape($cpuload); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-memory"></i>
            <span><?php echo _("Memory"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($memused_status); ?>"><?php echo openapSystemEscape($memused); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-hdd"></i>
            <span><?php echo _("Disk"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($diskused_status); ?>"><?php echo openapSystemEscape($diskused); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-temperature-half"></i>
            <span><?php echo _("Temperature"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($cputemp_status); ?>"><?php echo openapSystemEscape($cputemp); ?>&deg;C</strong>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-xl-6">
            <section class="openap-system-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-server"></i>
                <span><?php echo _("Operating System"); ?></span>
              </div>
              <div class="openap-system-list">
                <div><span><?php echo _("Hostname"); ?></span><strong><?php echo openapSystemEscape($hostname); ?></strong></div>
                <div><span><?php echo _("OS"); ?></span><strong><?php echo openapSystemEscape($os); ?></strong></div>
                <div><span><?php echo _("Kernel"); ?></span><strong><?php echo openapSystemEscape($kernel); ?></strong></div>
                <div><span><?php echo _("Architecture"); ?></span><strong><?php echo openapSystemEscape($machine); ?></strong></div>
                <div><span><?php echo _("Container"); ?></span><strong><?php echo openapSystemEscape($container); ?></strong></div>
                <div><span><?php echo _("CPU cores"); ?></span><strong><?php echo openapSystemEscape($cores); ?></strong></div>
                <div><span><?php echo _("Uptime"); ?></span><strong><?php echo openapSystemEscape($uptime); ?></strong></div>
                <div><span><?php echo _("System time"); ?></span><strong><?php echo openapSystemEscape($systime); ?></strong></div>
              </div>
            </section>
          </div>

          <div class="col-xl-6">
            <section class="openap-system-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-code-branch"></i>
                <span><?php echo _("Project Software"); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table openap-system-table openap-system-detail-table openap-system-software-table">
                  <tbody>
                    <?php foreach ($software as $component) : ?>
                      <tr>
                        <td class="openap-system-property"><?php echo openapSystemEscape($component['name']); ?></td>
                        <td class="openap-system-value"><code><?php echo openapSystemEscape($component['version']); ?></code></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>

        <section class="openap-system-panel openap-system-services-panel mt-3">
          <div class="openap-system-panel-title">
            <i class="fas fa-heartbeat"></i>
            <span><?php echo _("Services"); ?></span>
          </div>
          <div class="table-responsive">
            <table class="table openap-system-table openap-system-detail-table openap-system-services-table">
              <thead>
                <tr>
                  <th><?php echo _("Service"); ?></th>
                  <th><?php echo _("State"); ?></th>
                  <th><?php echo _("Enabled"); ?></th>
                  <th><?php echo _("Active since"); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($services as $service) : ?>
                  <tr>
                    <td class="openap-system-property"><code><?php echo openapSystemEscape($service['name']); ?></code></td>
                    <td><?php echo openapSystemBadge($service['active'], $service['statusClass']); ?></td>
                    <td class="openap-system-value"><?php echo openapSystemEscape($service['enabled']); ?></td>
                    <td class="openap-system-value"><?php echo openapSystemEscape($service['since']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <div class="row g-3 mt-0">
          <div class="col-12">
            <section class="openap-system-panel openap-system-config-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-file-lines"></i>
                <span><?php echo _("Configuration Files"); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table openap-system-table openap-system-detail-table openap-system-files-table">
                  <tbody>
                    <?php foreach ($configFiles as $file) : ?>
                      <tr>
                        <td class="openap-system-property openap-system-file-path"><code><?php echo openapSystemEscape($file['path']); ?></code></td>
                        <td>
                          <div class="openap-system-file-meta">
                            <div>
                              <?php echo $file['exists'] ? openapSystemBadge(_("Readable"), 'up') : openapSystemBadge(_("Missing"), 'warn'); ?>
                              <span class="openap-system-file-time"><?php echo openapSystemEscape($file['modified']); ?></span>
                            </div>
                            <button type="button" class="openap-system-copy-path" data-openap-copy-path="<?php echo openapSystemEscape($file['path']); ?>" aria-label="<?php echo _("Copy path"); ?>">
                              <i class="far fa-copy" aria-hidden="true"></i><span><?php echo _("Copy path"); ?></span>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
        </div>
        <div class="card-footer openap-system-footer">
          <i class="fas fa-lock me-1"></i><?php echo _("Read-only information provided by OpenAP system diagnostics."); ?>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-lg-4"><?php echo openapWidgetArea('system'); ?></div>
  </div>
</div>

<div class="modal fade openap-system-modal" id="system-reboot-modal" tabindex="-1" aria-labelledby="system-reboot-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content openap-system-modal-content">
      <div class="modal-header">
        <div class="modal-title openap-system-modal-title" id="system-reboot-title">
          <span class="openap-system-modal-icon"><i class="fas fa-power-off"></i></span>
          <span><strong><?php echo _("Reboot system"); ?></strong><small><?php echo _("System operation"); ?></small></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
      </div>
      <div class="modal-body">
        <div class="openap-system-info"><i class="fas fa-circle-info" aria-hidden="true"></i><span><?php echo _("OpenAP and its network services will be temporarily unavailable while the system restarts."); ?></span></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ss openap-system-cancel" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
        <form method="POST" action="system_info" class="m-0">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="system_action" value="reboot">
          <button type="submit" class="btn-ss openap-system-reboot-confirm">
            <i class="fas fa-power-off me-1"></i><?php echo _("Reboot"); ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
