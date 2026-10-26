<?php
$isEmbedded = !empty($isEmbedded);
ob_start();
$status->showMessages();
$messages = ob_get_clean();
$hasSuccess = strpos($messages, 'alert-success') !== false && strpos($messages, 'alert-danger') === false;
$selected = $summary['selected_ethernet'] ?? ['name' => '', 'ip' => '', 'carrier' => 'down'];
$selectedAp = $summary['selected_ap'] ?? ['mac' => ''];
$gateway = $summary['gateway'] ?? '';
$requestedGateway = (string) ($_POST['ethernet_gateway'] ?? '');
if ($hasSuccess && filter_var($requestedGateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
    // Delayed network changes intentionally return before the profile is
    // updated. Report the accepted request, not the previous profile value.
    $gateway = $requestedGateway;
}
$profile = $summary['profile'] ?? [];
$currentProfileMode = str_replace('-', '_', (string) ($profile['mode']['current'] ?? 'ap_ethernet'));
$selectedNetworkMode = (string) ($_POST['network_mode'] ?? ($currentProfileMode === 'ap_ethernet_bridge' ? 'bridge' : 'routed'));
$formAction = $isEmbedded ? '/ap_ethernet_embed.php' : '/ap_wizard';
?>
<?php if (!$isEmbedded): ?>
<div class="row"><div class="col-lg-12"><div class="card shadow">
  <div class="card-header"><i class="fas fa-network-wired me-2"></i><?php echo _("AP via Ethernet"); ?></div>
  <div class="card-body">
<?php endif; ?>

<?php if ($messages && !$hasSuccess): ?>
  <div class="mb-3"><?php echo $messages; ?></div>
<?php endif; ?>

<?php if ($hasSuccess): ?>
  <div class="text-center py-4 px-3 js-ap-ethernet-success"
       data-network-mode="<?php echo htmlspecialchars($selectedNetworkMode, ENT_QUOTES); ?>"
       data-gateway="<?php echo htmlspecialchars($gateway, ENT_QUOTES); ?>">
    <div style="width:56px;height:56px;border-radius:50%;background:rgba(5,150,105,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
      <i class="fas fa-check-circle" style="font-size:28px;color:#059669"></i>
    </div>
    <h5 style="color:#0f172a;font-weight:700;margin-bottom:4px;font-size:16px"><?php echo _("Ethernet Mode active"); ?></h5>
    <p style="color:#334155;font-size:13px;margin:0 0 12px"><?php echo _("Ethernet uplink configured on"); ?> <strong><?php echo htmlspecialchars($selected['name'], ENT_QUOTES); ?></strong></p>
    <div class="openap-ap-ethernet-status mb-3">
      <span><?php echo _("Mode"); ?>: <strong><?php echo $selectedNetworkMode === 'bridge' ? _("Ethernet Bridge") : _("Routed / NAT"); ?></strong></span>
      <span><?php echo _("Management gateway"); ?>: <strong><?php echo htmlspecialchars($gateway, ENT_QUOTES); ?></strong></span>
    </div>
    <div class="alert alert-success py-2 px-3 mb-0" style="border-radius:10px;font-size:12px"><?php echo _("Waiting for hotspot state before refreshing dashboard..."); ?></div>
  </div>
<?php else: ?>
  <form method="POST" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES); ?>" class="js-ap-ethernet-form needs-validation" novalidate>
    <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
    <input type="hidden" name="ap_action" value="configure_ap_ethernet">

    <fieldset class="openap-ethernet-network-mode">
      <legend><?php echo _("Network mode"); ?></legend>
      <div class="openap-ethernet-mode-options">
        <label class="openap-ethernet-mode-choice">
          <input type="radio" name="network_mode" value="routed" <?php echo $selectedNetworkMode === 'routed' ? 'checked' : ''; ?>>
          <span class="openap-ethernet-mode-icon"><i class="fas fa-route"></i></span>
          <span><strong><?php echo _("Routed / NAT"); ?></strong><small><?php echo _("OpenAP DHCP and isolated subnet"); ?></small></span>
        </label>
        <label class="openap-ethernet-mode-choice">
          <input type="radio" name="network_mode" value="bridge" <?php echo $selectedNetworkMode === 'bridge' ? 'checked' : ''; ?>>
          <span class="openap-ethernet-mode-icon"><i class="fas fa-link"></i></span>
          <span><strong><?php echo _("Ethernet Bridge"); ?></strong><small><?php echo _("Same LAN and DHCP as Ethernet"); ?></small></span>
        </label>
      </div>
      <div class="openap-ethernet-bridge-note" data-bridge-note <?php echo $selectedNetworkMode === 'bridge' ? '' : 'hidden'; ?>><i class="fas fa-info-circle"></i> <?php echo _("WiFi clients will receive addresses, gateway and DNS directly from the upstream router. The management gateway below applies only to the OpenAP host."); ?></div>
    </fieldset>
    <input type="hidden" name="ethernet_interface" value="<?php echo htmlspecialchars($selected['name'], ENT_QUOTES); ?>">
    <input type="hidden" name="ap_mac" value="<?php echo htmlspecialchars($selectedAp['mac'], ENT_QUOTES); ?>">

    <div class="openap-ap-ethernet-settings">
      <div class="openap-ap-ethernet-setting">
        <span class="openap-ap-ethernet-setting-icon" aria-hidden="true"><i class="fas fa-network-wired"></i></span>
        <div class="openap-ap-ethernet-setting-copy">
          <label for="openapEthernetInterface"><?php echo _("Interface"); ?></label>
          <small><?php echo _("Active network path"); ?></small>
        </div>
        <input id="openapEthernetInterface" type="text" class="openap-ap-ethernet-value" value="<?php echo htmlspecialchars($selected['name'] . '  ' . $selected['ip'], ENT_QUOTES); ?>" readonly>
      </div>

      <div class="openap-ap-ethernet-setting">
        <span class="openap-ap-ethernet-setting-icon" aria-hidden="true"><i class="fas fa-route"></i></span>
        <div class="openap-ap-ethernet-setting-copy">
          <label for="openapEthernetGateway"><?php echo _("Management gateway"); ?></label>
          <small><?php echo _("OpenAP host default route"); ?></small>
        </div>
        <input id="openapEthernetGateway" type="text" name="ethernet_gateway" value="<?php echo htmlspecialchars($gateway, ENT_QUOTES); ?>" required class="openap-ap-ethernet-value is-editable" inputmode="decimal">
      </div>
    </div>

    <div class="openap-ap-ethernet-status">
      <span><?php echo _("Uplink"); ?>: <strong><?php echo $selected['carrier'] === 'up' ? _("Connected") : _("Detected"); ?></strong></span>
      <span><?php echo _("Mode"); ?>: <strong><?php echo _("Ethernet Mode"); ?></strong></span>
    </div>

    <div class="openap-ap-ethernet-actions">
      <button type="button" class="btn-ss" data-bs-dismiss="modal"><i class="fas fa-times"></i> <?php echo _("Cancel"); ?></button>
      <button type="submit" class="btn-ss primary js-ap-ethernet-submit" data-loading-text="<?php echo _("Saving..."); ?>">
          <span class="spinner-border spinner-border-sm me-1 d-none js-ap-ethernet-spinner" role="status" aria-hidden="true"></span>
          <i class="fas fa-floppy-disk js-ap-ethernet-icon" aria-hidden="true"></i>
          <span class="js-ap-ethernet-label"><?php echo _("Save"); ?></span>
        </button>
    </div>
  </form>
<?php endif; ?>

<?php if (!$isEmbedded): ?>
  </div>
  <div class="card-footer text-muted small"><i class="fas fa-info-circle me-1"></i> <?php echo _("AP traffic routed via ethernet uplink."); ?></div>
</div></div></div>
<?php endif; ?>
