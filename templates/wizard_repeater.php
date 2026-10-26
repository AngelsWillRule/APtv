<div class="row">
  <div class="col-lg-12">
    <div class="card shadow">
      <div class="card-header page-card-header">
        <div class="d-flex justify-content-between align-items-center">
          <div><i class="fas fa-repeat me-2"></i><?php echo _("Repeater setup"); ?></div>
        </div>
      </div>
      <div class="card-body">
        <?php $status->showMessages(); ?>

        <div class="alert <?php echo $summary['ready'] ? 'alert-info' : 'alert-warning'; ?>" role="alert">
          <?php if ($summary['ready']) : ?>
            <?php echo _("Two WiFi interfaces detected. Repeater setup is available."); ?>
          <?php else : ?>
            <?php echo _("Repeater setup needs two WiFi interfaces with AP and managed support."); ?>
          <?php endif; ?>
        </div>

        <?php if ($summary['roles']['valid']) : ?>
          <div class="row g-4 mb-3">
            <div class="col-lg-6">
              <h5><?php echo _("Selected AP"); ?></h5>
              <table class="table table-sm">
                <tbody>
                  <tr><th><?php echo _("Interface"); ?></th><td><code><?php echo htmlspecialchars($summary['roles']['ap']['name'], ENT_QUOTES); ?></code></td></tr>
                  <tr><th><?php echo _("MAC"); ?></th><td><code><?php echo htmlspecialchars($summary['roles']['ap']['mac'], ENT_QUOTES); ?></code></td></tr>
                </tbody>
              </table>
            </div>
            <div class="col-lg-6">
              <h5><?php echo _("Selected uplink"); ?></h5>
              <table class="table table-sm">
                <tbody>
                  <tr><th><?php echo _("Interface"); ?></th><td><code><?php echo htmlspecialchars($summary['roles']['uplink']['name'], ENT_QUOTES); ?></code></td></tr>
                  <tr><th><?php echo _("MAC"); ?></th><td><code><?php echo htmlspecialchars($summary['roles']['uplink']['mac'], ENT_QUOTES); ?></code></td></tr>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <h5><?php echo _("WiFi interfaces"); ?></h5>
        <div class="table-responsive">
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
              <?php foreach ($summary['wireless'] as $iface) : ?>
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
              <?php if (count($summary['wireless']) === 0) : ?>
                <tr><td colspan="8" class="text-muted"><?php echo _("No WiFi interfaces found."); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <form method="POST" action="repeater_wizard" class="mt-3 js-openap-switch-form">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="repeater_action" value="configure_repeater_wifi">
          <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($summary['roles']['ap']['mac'], ENT_QUOTES); ?>">
          <input type="hidden" name="uplink_mac" value="<?php echo htmlspecialchars($summary['roles']['uplink']['mac'], ENT_QUOTES); ?>">
          <button type="submit" class="btn btn-primary js-openap-switch-submit" data-loading-text="<?php echo _("Switching"); ?>" <?php echo $summary['ready'] && $summary['roles']['valid'] ? '' : 'disabled'; ?>>
            <span class="spinner-border spinner-border-sm me-2 d-none js-openap-switch-spinner" role="status" aria-hidden="true"></span>
            <i class="fas fa-play js-openap-switch-icon"></i> <span class="js-openap-switch-label"><?php echo _("Configure repeater"); ?></span>
          </button>
          <a href="/" class="btn btn-outline-secondary ms-2"><?php echo _("Cancel"); ?></a>
        </form>
      </div>
      <div class="card-footer"><?php echo _("Repeater traffic will use the selected WiFi uplink."); ?></div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.js-openap-switch-form').forEach(function(form) {
  form.addEventListener('submit', function() {
    var submit = form.querySelector('.js-openap-switch-submit');
    if (!submit) {
      return;
    }
    var spinner = submit.querySelector('.js-openap-switch-spinner');
    var icon = submit.querySelector('.js-openap-switch-icon');
    var label = submit.querySelector('.js-openap-switch-label');
    if (spinner) {
      spinner.classList.remove('d-none');
    }
    if (icon) {
      icon.classList.add('d-none');
    }
    if (label) {
      label.textContent = submit.dataset.loadingText || label.textContent;
    }
    submit.disabled = true;
    form.querySelectorAll('button, input, select, textarea').forEach(function(control) {
      if (control !== submit) {
        control.readOnly = true;
      }
    });
  });
});
</script>
