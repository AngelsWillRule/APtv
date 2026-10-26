<div class="row">
  <div class="col-xl-9 col-lg-8">
    <div class="card shadow">
      <div class="card-header openap-topology-header openap-page-main-header openap-dashboard-main-header">
        <div class="openap-topology-header-title">
          <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-laptop"></i></span>
          <div><strong><?php echo _("Hotspot clients"); ?></strong></div>
        </div>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th><?php echo _("Hostname"); ?></th>
                <th><?php echo _("IP"); ?></th>
                <th><?php echo _("MAC"); ?></th>
                <th><?php echo _("Signal"); ?></th>
                <th><?php echo _("RX rate"); ?></th>
                <th><?php echo _("TX rate"); ?></th>
                <th><?php echo _("Connected"); ?></th>
                <th><?php echo _("State"); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clients as $client) : ?>
                <tr>
                  <td><?php echo htmlspecialchars($client['hostname'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($client['ip'], ENT_QUOTES); ?></td>
                  <td><code><?php echo htmlspecialchars($client['mac'], ENT_QUOTES); ?></code></td>
                  <td><?php echo htmlspecialchars($client['signal'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($client['rx_rate'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($client['tx_rate'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($client['connected'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($client['state'], ENT_QUOTES); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (count($clients) === 0) : ?>
                <tr>
                  <td colspan="8" class="text-muted"><?php echo _("No hotspot clients found."); ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer"><?php echo _("Information provided by hostapd, dnsmasq and ARP."); ?></div>
    </div>
  </div>
  <div class="col-xl-3 col-lg-4"><?php echo openapWidgetArea('clients'); ?></div>
</div>
