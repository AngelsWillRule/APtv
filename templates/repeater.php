<div class="row">
  <div class="col-lg-12">
    <div class="card shadow">
      <div class="card-header page-card-header">
        <div class="d-flex justify-content-between align-items-center">
          <div><i class="fas fa-repeat me-2"></i><?php echo _("Repeater"); ?></div>
        </div>
      </div>
      <div class="card-body">
        <?php $status->showMessages(); ?>

        <form method="POST" action="repeater" class="mb-4">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <div class="d-flex flex-wrap gap-2">
            <a href="/repeater" class="btn btn-primary">
              <i class="fas fa-sync-alt"></i> <?php echo _("Refresh status"); ?>
            </a>
            <button type="submit" name="repeater_action" value="restart_uplink" class="btn btn-outline-primary">
              <i class="fas fa-sync-alt"></i> <?php echo _("Restart uplink"); ?>
            </button>
            <a href="/uplink_wizard" class="btn btn-outline-primary js-openap-nav-spinner" data-loading-text="<?php echo _("Opening"); ?>">
              <span class="spinner-border spinner-border-sm me-2 d-none js-openap-nav-spinner-icon" role="status" aria-hidden="true"></span>
              <i class="fas fa-wifi js-openap-nav-icon"></i> <span class="js-openap-nav-label"><?php echo _("Change uplink"); ?></span>
            </a>
            <button type="submit" name="repeater_action" value="restart_ap" class="btn btn-outline-primary">
              <i class="fas fa-sync-alt"></i> <?php echo _("Restart AP"); ?>
            </button>
            <button type="submit" name="repeater_action" value="restart_dhcp" class="btn btn-outline-primary">
              <i class="fas fa-sync-alt"></i> <?php echo _("Restart DHCP"); ?>
            </button>
          </div>
        </form>

        <div class="row g-4">
          <div class="col-lg-12">
            <h5><?php echo _("Role configuration"); ?></h5>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <tbody>
                  <tr><th><?php echo _("Profile"); ?></th><td><code><?php echo htmlspecialchars($summary['profile']['path'], ENT_QUOTES); ?></code></td></tr>
                  <tr><th><?php echo _("AP role"); ?></th><td><code><?php echo htmlspecialchars($summary['profile']['ap'], ENT_QUOTES); ?></code> <span class="text-muted"><?php echo htmlspecialchars($summary['profile']['ap_mac'], ENT_QUOTES); ?></span></td></tr>
                  <tr><th><?php echo _("Uplink role"); ?></th><td><code><?php echo htmlspecialchars($summary['profile']['uplink'], ENT_QUOTES); ?></code> <span class="text-muted"><?php echo htmlspecialchars($summary['profile']['uplink_mac'], ENT_QUOTES); ?></span></td></tr>
                  <tr><th><?php echo _("Role validation"); ?></th><td><?php echo htmlspecialchars($summary['profile']['roles_valid'], ENT_QUOTES); ?></td></tr>
                </tbody>
              </table>
            </div>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th><?php echo _("Ethernet"); ?></th>
                    <th><?php echo _("MAC"); ?></th>
                    <th><?php echo _("IP"); ?></th>
                    <th><?php echo _("Carrier"); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($summary['ethernet'] as $iface) : ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($iface['name'], ENT_QUOTES); ?></code></td>
                      <td><code><?php echo htmlspecialchars($iface['mac'], ENT_QUOTES); ?></code></td>
                      <td><?php echo htmlspecialchars($iface['ip'], ENT_QUOTES); ?></td>
                      <td><?php echo htmlspecialchars($iface['carrier'], ENT_QUOTES); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (count($summary['ethernet']) === 0) : ?>
                    <tr><td colspan="4" class="text-muted"><?php echo _("No ethernet interfaces found."); ?></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th><?php echo _("Interface"); ?></th>
                    <th><?php echo _("MAC"); ?></th>
                    <th><?php echo _("Type"); ?></th>
                    <th><?php echo _("Phy"); ?></th>
                    <th><?php echo _("SSID"); ?></th>
                    <th><?php echo _("Channel"); ?></th>
                    <th><?php echo _("AP"); ?></th>
                    <th><?php echo _("Managed"); ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($summary['detected'] as $iface) : ?>
                    <tr>
                      <td><code><?php echo htmlspecialchars($iface['name'], ENT_QUOTES); ?></code></td>
                      <td><code><?php echo htmlspecialchars($iface['mac'], ENT_QUOTES); ?></code></td>
                      <td><?php echo htmlspecialchars($iface['type'], ENT_QUOTES); ?></td>
                      <td><?php echo htmlspecialchars($iface['phy'], ENT_QUOTES); ?></td>
                      <td><?php echo htmlspecialchars($iface['ssid'], ENT_QUOTES); ?></td>
                      <td><?php echo htmlspecialchars($iface['channel'], ENT_QUOTES); ?> <?php echo htmlspecialchars($iface['frequency'], ENT_QUOTES); ?></td>
                      <td><?php echo $iface['supports_ap'] ? _("yes") : _("no"); ?></td>
                      <td><?php echo $iface['supports_managed'] ? _("yes") : _("no"); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="col-lg-4">
            <h5><?php echo _("Uplink"); ?></h5>
            <table class="table table-sm">
              <tbody>
                <tr><th><?php echo _("Interface"); ?></th><td><code><?php echo htmlspecialchars($summary['uplink']['interface'], ENT_QUOTES); ?></code></td></tr>
                <tr><th><?php echo _("Service"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['service'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("SSID"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['ssid'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("State"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['state'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("IP"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['ip'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Frequency"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['frequency'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Signal"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['rssi'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Link speed"); ?></th><td><?php echo htmlspecialchars($summary['uplink']['link_speed'], ENT_QUOTES); ?></td></tr>
              </tbody>
            </table>
          </div>

          <div class="col-lg-4">
            <h5><?php echo _("Hotspot"); ?></h5>
            <table class="table table-sm">
              <tbody>
                <tr><th><?php echo _("Interface"); ?></th><td><code><?php echo htmlspecialchars($summary['ap']['interface'], ENT_QUOTES); ?></code></td></tr>
                <tr><th><?php echo _("Service"); ?></th><td><?php echo htmlspecialchars($summary['ap']['service'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("SSID"); ?></th><td><?php echo htmlspecialchars($summary['ap']['ssid'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("State"); ?></th><td><?php echo htmlspecialchars($summary['ap']['state'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Mode"); ?></th><td><?php echo htmlspecialchars($summary['ap']['mode'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Frequency"); ?></th><td><?php echo htmlspecialchars($summary['ap']['frequency'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Channel"); ?></th><td><?php echo htmlspecialchars($summary['ap']['channel'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("Clients"); ?></th><td><?php echo htmlspecialchars($summary['ap']['clients'], ENT_QUOTES); ?></td></tr>
              </tbody>
            </table>
          </div>

          <div class="col-lg-4">
            <h5><?php echo _("Client network"); ?></h5>
            <table class="table table-sm">
              <tbody>
                <tr><th><?php echo _("Subnet"); ?></th><td><code><?php echo htmlspecialchars($summary['network']['subnet'], ENT_QUOTES); ?></code></td></tr>
                <tr><th><?php echo _("Gateway"); ?></th><td><?php echo htmlspecialchars($summary['network']['gateway'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("DHCP range"); ?></th><td><?php echo htmlspecialchars($summary['network']['dhcp_range'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("dnsmasq"); ?></th><td><?php echo htmlspecialchars($summary['network']['dnsmasq'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("nftables"); ?></th><td><?php echo htmlspecialchars($summary['network']['nftables'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("IPv4 forwarding"); ?></th><td><?php echo htmlspecialchars($summary['network']['ip_forward'], ENT_QUOTES); ?></td></tr>
                <tr><th><?php echo _("NAT"); ?></th><td><?php echo htmlspecialchars($summary['network']['nat'], ENT_QUOTES); ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div class="card-footer"><?php echo _("Information provided by hostapd, wpa_supplicant, dnsmasq, nftables and systemd."); ?></div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.js-openap-nav-spinner').forEach(function(link) {
  link.addEventListener('click', function() {
    var spinner = link.querySelector('.js-openap-nav-spinner-icon');
    var icon = link.querySelector('.js-openap-nav-icon');
    var label = link.querySelector('.js-openap-nav-label');
    if (spinner) {
      spinner.classList.remove('d-none');
    }
    if (icon) {
      icon.classList.add('d-none');
    }
    if (label) {
      label.textContent = link.dataset.loadingText || label.textContent;
    }
    link.classList.add('disabled');
  });
});
</script>
