<?php $isEmbedded = !empty($isEmbedded); ?>
<?php if (!$isEmbedded): ?>
<div class="row">
  <div class="col-lg-12">
    <div class="card shadow">
      <div class="card-header page-card-header">
        <div class="d-flex justify-content-between align-items-center">
          <div><i class="fas fa-wifi me-2"></i><?php echo _("Uplink setup"); ?></div>
          <a href="<?php echo $isEmbedded ? '/uplink_embed.php?scan=1' : '/uplink_wizard?scan=1'; ?>" class="btn btn-primary btn-sm js-uplink-rescan">
            <i class="fas fa-sync-alt"></i> <?php echo _("Scan"); ?>
          </a>
        </div>
      </div>
<?php endif; ?>
      <div class="card-body<?php echo $isEmbedded ? ' p-0' : ''; ?>">
        <?php if (empty($summary['connection_summary'])) $status->showMessages(); ?>
        <?php if (!empty($summary['connection_pending'])): ?>
          <div class="js-uplink-settling d-none" aria-hidden="true"></div>
        <?php endif; ?>

        <?php if (!empty($summary['connection_summary'])): ?>
        <?php $connected = $summary['connection_summary']; ?>
        <div class="js-repeater-success openap-repeater-success text-center" role="status">
          <div style="width:58px;height:58px;border-radius:50%;background:rgba(5,150,105,.1);display:flex;align-items:center;justify-content:center;margin:4px auto 10px">
            <i class="fas fa-check-circle" style="font-size:30px;color:#059669"></i>
          </div>
          <h4 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:4px"><?php echo _("Repeater connected successfully"); ?></h4>
          <p class="openap-repeater-success-copy" style="font-size:12px;color:#64748b;margin-bottom:14px"><?php echo _("Hotspot traffic now uses the selected WiFi uplink."); ?></p>

          <div class="openap-repeater-success-details border rounded text-start mx-auto mb-3" style="max-width:420px;overflow:hidden">
            <?php
            $connectionRows = [
              [_('Uplink network'), $connected['uplink_ssid']],
              [_('Uplink address'), $connected['uplink_ip']],
              [_('Signal'), $connected['uplink_signal']],
              [_('Hotspot'), $connected['ap_ssid']],
            ];
            foreach ($connectionRows as $index => [$label, $value]):
            ?>
            <div class="d-flex justify-content-between align-items-center gap-3 px-3 py-2<?php echo $index < count($connectionRows) - 1 ? ' border-bottom' : ''; ?>">
              <span class="openap-repeater-success-label text-muted" style="font-size:11px"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></span>
              <strong class="openap-repeater-success-value text-end text-break" style="font-size:12px"><?php echo htmlspecialchars((string) $value, ENT_QUOTES); ?></strong>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="d-flex justify-content-center" style="border-top:1px solid #e2e8f0;padding-top:12px">
            <button type="button" class="btn btn-primary btn-sm px-4" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i><?php echo _("Done"); ?></button>
          </div>
        </div>
        <?php else: ?>
        <div class="alert openap-persistent-alert openap-repeater-notice d-flex align-items-center gap-2 <?php echo $summary['ready'] ? 'alert-info' : 'alert-warning'; ?>" role="alert">
          <i class="fas <?php echo $summary['ready'] ? 'fa-info-circle' : 'fa-exclamation-triangle'; ?>"></i>
          <?php if ($summary['ready']) : ?>
            <?php echo _("Select a WiFi network below or click Scan to search."); ?>
          <?php else : ?>
            <?php echo _("No managed-capable WiFi uplink interface is ready."); ?>
          <?php endif; ?>
        </div>

        <div class="row g-2">
          <div class="col-12">
            <div class="openap-repeater-section-heading">
              <div><strong><?php echo _("Available networks"); ?></strong><small><?php echo _("Choose an uplink or scan again"); ?></small></div>
              <?php if ($isEmbedded): ?>
              <a href="/uplink_embed.php?scan=1" class="btn-ss js-uplink-rescan"><i class="fas fa-sync-alt"></i> <?php echo _("Scan"); ?></a>
              <?php endif; ?>
            </div>
            <div class="openap-repeater-networks js-uplink-networks-body">
                  <?php foreach ($summary['networks'] as $network) : ?>
                    <?php $networkActive = !empty($network['connected']) && !empty($summary['repeater_active']); ?>
                    <article class="openap-uplink-card<?php echo $networkActive ? ' is-active' : ''; ?>">
                      <div class="openap-uplink-card-main">
                        <div class="openap-repeater-network-name openap-uplink-card-name">
                          <span class="openap-repeater-network-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
                          <div>
                            <strong><?php echo htmlspecialchars($network['ssid'], ENT_QUOTES); ?></strong>
                            <?php if (!empty($network['saved'])) : ?>
                              <span class="badge text-bg-success ms-1"><?php echo _("Saved"); ?></span>
                            <?php endif; ?>
                            <?php if ($networkActive) : ?>
                              <span class="badge rounded-pill text-bg-success ms-1"><i class="fas fa-check-circle me-1"></i><?php echo _("Current uplink"); ?></span>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="openap-uplink-card-meta">
                          <span><?php echo htmlspecialchars($network['band'], ENT_QUOTES); ?></span>
                          <span><?php echo _("Channel"); ?> <?php echo htmlspecialchars($network['channel'], ENT_QUOTES); ?></span>
                          <span class="openap-uplink-signal" style="--signal-color:<?php echo (int)$network['signal'] > -60 ? '#059669' : ((int)$network['signal'] > -75 ? '#d97706' : '#dc2626'); ?>"><?php echo htmlspecialchars($network['signal'], ENT_QUOTES); ?> dBm</span>
                          <span><?php echo htmlspecialchars($network['security'], ENT_QUOTES); ?></span>
                        </div>
                      </div>
                      <div class="openap-uplink-card-actions">
                        <?php $unsupportedWpa3 = strtolower((string) ($network['security'] ?? '')) === 'wpa3'; ?>
                        <?php if ($unsupportedWpa3) : ?>
                          <button type="button" class="btn btn-outline-secondary btn-sm" disabled title="<?php echo _("WPA3-only uplinks are not currently supported"); ?>">
                            <i class="fas fa-ban me-1"></i><?php echo _("WPA3 unsupported"); ?>
                          </button>
                        <?php elseif (!empty($network['saved'])) : ?>
                            <form method="POST" action="<?php echo $isEmbedded ? '/uplink_embed.php' : '/uplink_wizard'; ?>" class="d-inline js-openap-switch-form js-uplink-form">
                              <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
                              <input type="hidden" name="uplink_action" value="connect_saved_wifi">
                              <input type="hidden" name="uplink_interface" value="<?php echo htmlspecialchars($summary['uplink']['name'], ENT_QUOTES); ?>">
                              <input type="hidden" name="ssid" value="<?php echo htmlspecialchars($network['ssid'], ENT_QUOTES); ?>">
                              <button type="submit" class="btn-ss <?php echo $networkActive ? '' : 'primary'; ?> js-openap-switch-submit" data-loading-text="<?php echo _("Connecting"); ?>">
                                <span class="spinner-border spinner-border-sm me-1 d-none js-openap-switch-spinner" role="status" aria-hidden="true"></span>
                                <i class="fas fa-play js-openap-switch-icon"></i>
                                <span class="js-openap-switch-label"><?php echo !empty($network['connected']) ? _("Reconnect saved") : _("Connect saved"); ?></span>
                              </button>
                            </form>
                            <?php if (strtolower((string) $network['security']) !== 'open') : ?>
                              <?php $savedPassphrase = openapSavedWifiPassphrase((string) $network['ssid']); ?>
                              <button type="button" class="btn-ss js-uplink-select" data-ssid="<?php echo htmlspecialchars($network['ssid'], ENT_QUOTES); ?>" data-security="wpa" data-saved-password="<?php echo htmlspecialchars($savedPassphrase, ENT_QUOTES); ?>" title="<?php echo _("Edit saved password"); ?>">
                                <i class="fas fa-key me-1"></i><?php echo _("Edit password"); ?>
                              </button>
                            <?php endif; ?>
                        <?php else : ?>
                          <button type="button" class="btn btn-outline-primary btn-sm js-uplink-select openap-repeater-select" data-ssid="<?php echo htmlspecialchars($network['ssid'], ENT_QUOTES); ?>" data-security="<?php echo strtolower((string) $network['security']) === 'open' ? 'open' : 'wpa'; ?>"><i class="fas fa-check me-1"></i><?php echo _("Select"); ?></button>
                        <?php endif; ?>
                        <?php if (!$unsupportedWpa3 && !empty($network['saved'])) : ?>
                          <form method="POST" action="/uplink_embed.php" class="d-inline js-uplink-forget-form">
                            <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
                            <input type="hidden" name="uplink_action" value="forget_uplink_wifi">
                            <input type="hidden" name="uplink_interface" value="<?php echo htmlspecialchars($summary['uplink']['name'], ENT_QUOTES); ?>">
                            <input type="hidden" name="ssid" value="<?php echo htmlspecialchars($network['ssid'], ENT_QUOTES); ?>">
                            <button type="submit" class="btn-ss openap-uplink-forget" title="<?php echo _("Forget saved network"); ?>"><i class="fas fa-trash me-1"></i><?php echo _("Forget"); ?></button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                  <?php if (count($summary['networks']) === 0) : ?>
                    <div class="text-muted text-center py-3"><?php echo _("No networks found. Click Scan to search."); ?></div>
                  <?php endif; ?>
            </div>
            <button type="button" class="btn-ss mt-2 js-uplink-manual"><i class="fas fa-keyboard me-1"></i><?php echo _("Enter network manually"); ?></button>
          </div>

          <div class="col-12 openap-repeater-credentials" hidden>
            <div class="openap-repeater-section-heading">
              <div><strong><?php echo _("Uplink credentials"); ?></strong><small><?php echo _("Connect the selected network"); ?></small></div>
            </div>
            <form method="POST" action="<?php echo $isEmbedded ? '/uplink_embed.php' : '/uplink_wizard'; ?>" class="js-openap-switch-form js-uplink-form">
              <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
              <input type="hidden" name="uplink_action" value="configure_uplink_wifi">
              <input type="hidden" name="uplink_interface" value="<?php echo htmlspecialchars($summary['uplink']['name'], ENT_QUOTES); ?>">
              <input type="hidden" name="security" class="js-uplink-security" value="wpa">
              <div class="openap-repeater-form-grid">
                <div class="openap-repeater-field openap-repeater-interface-field">
                  <label class="form-label"><?php echo _("Interface"); ?></label>
                  <input type="text" class="form-control" value="<?php echo htmlspecialchars($summary['uplink']['name'], ENT_QUOTES); ?>" readonly>
                </div>
                <div class="openap-repeater-field">
                  <label class="form-label"><?php echo _("SSID"); ?></label>
                  <input type="text" class="form-control js-uplink-ssid" name="ssid" value="" maxlength="32" required placeholder="<?php echo _("Select a network or type SSID"); ?>">
                </div>
                <div class="openap-repeater-field js-uplink-password-field">
                  <label class="form-label"><?php echo _("Password"); ?></label>
                  <div class="input-group">
                    <input type="password" class="form-control" name="passphrase" minlength="8" maxlength="63" autocomplete="new-password" required>
                    <button class="btn btn-outline-secondary js-toggle-password" type="button" data-bs-target="[name=passphrase]" data-toggle-with="fas fa-eye-slash">
                      <i class="fas fa-eye"></i>
                    </button>
                  </div>
                </div>
                <button type="submit" class="btn-ss primary js-openap-switch-submit openap-repeater-connect" data-loading-text="<?php echo _("Connecting"); ?>" <?php echo $summary['ready'] ? '' : 'disabled'; ?>>
                  <span class="spinner-border spinner-border-sm me-1 d-none js-openap-switch-spinner" role="status" aria-hidden="true"></span>
                  <i class="fas fa-play js-openap-switch-icon"></i> <span class="js-openap-switch-label"><?php echo _("Connect"); ?></span>
                </button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
      </div>
<?php if (!$isEmbedded): ?>
      <div class="card-footer"><?php echo _("Uplink credentials are stored in the selected wpa_supplicant profile."); ?></div>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
function bindUplinkSelectButtons(root) {
  root.querySelectorAll('.js-uplink-select').forEach(function(button) {
    button.addEventListener('click', function() {
      var ssid = document.querySelector('.js-uplink-ssid');
      var form = ssid ? ssid.closest('.js-uplink-form') : null;
      var security = form ? form.querySelector('.js-uplink-security') : null;
      var password = form ? form.querySelector('[name="passphrase"]') : null;
      var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
      var credentials = document.querySelector('.openap-repeater-credentials');
      var isOpen = button.dataset.security === 'open';
      if (ssid) {
        ssid.value = button.dataset.ssid || '';
      }
      if (security) security.value = isOpen ? 'open' : 'wpa';
      if (password) {
        password.required = !isOpen;
        password.disabled = isOpen;
        password.value = isOpen ? '' : (button.dataset.savedPassword || '');
      }
      if (passwordField) passwordField.hidden = isOpen;
      if (credentials) credentials.hidden = false;
      if (isOpen && ssid) ssid.focus();
      if (!isOpen && password) password.focus();
    });
  });
}
bindUplinkSelectButtons(document);
document.querySelectorAll('.js-uplink-manual').forEach(function(button) {
  button.addEventListener('click', function() {
    var credentials = document.querySelector('.openap-repeater-credentials');
    var form = credentials ? credentials.querySelector('.js-uplink-form') : null;
    var ssid = form ? form.querySelector('.js-uplink-ssid') : null;
    var security = form ? form.querySelector('.js-uplink-security') : null;
    var password = form ? form.querySelector('[name="passphrase"]') : null;
    var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
    if (credentials) credentials.hidden = false;
    if (ssid) ssid.value = '';
    if (security) security.value = 'wpa';
    if (password) { password.disabled = false; password.required = true; password.value = ''; }
    if (passwordField) passwordField.hidden = false;
    if (ssid) ssid.focus();
  });
});
document.querySelectorAll('.js-uplink-ssid').forEach(function(ssid) {
  ssid.addEventListener('input', function(event) {
    if (!event.isTrusted) return;
    var form = ssid.closest('.js-uplink-form');
    var security = form ? form.querySelector('.js-uplink-security') : null;
    var password = form ? form.querySelector('[name="passphrase"]') : null;
    var passwordField = form ? form.querySelector('.js-uplink-password-field') : null;
    if (security) security.value = 'wpa';
    if (password) {
      password.disabled = false;
      password.required = true;
    }
    if (passwordField) passwordField.hidden = false;
  });
});
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
  });
});
</script>
