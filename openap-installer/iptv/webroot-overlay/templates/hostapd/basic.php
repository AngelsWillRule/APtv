<div class="tab-pane fade show active" id="basic">
  <h4 class="mt-3"><?php echo _("Basic settings") ;?></h4>
  <div class="row">
    <div class="mb-3 col-md-6">
      <label for="cbxinterface"><?php echo _("Interface") ;?></label>
      <?php if (defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER) : ?>
        <input type="hidden" name="interface" id="cbxinterface" value="<?php echo htmlspecialchars(OPENAP_WIFI_AP_INTERFACE, ENT_QUOTES); ?>">
        <input type="text" class="form-control" value="<?php echo htmlspecialchars(OPENAP_WIFI_AP_INTERFACE . ' (AP)', ENT_QUOTES); ?>" disabled>
      <?php else : ?>
        <?php SelectorOptions('interface', $interfaces, $arrConfig['interface'], 'cbxinterface', 'getChannel'); ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="row">
    <div class="mb-3 col-md-6" required>
      <label for="txtssid"><?php echo _("SSID"); ?></label>
      <input type="text" id="txtssid" class="form-control" name="ssid" value="<?php echo htmlspecialchars($arrConfig['ssid'], ENT_QUOTES); ?>" required />
      <div class="invalid-feedback">
        <?php echo _("Please provide a valid SSID."); ?>
      </div>
    </div>
  </div>
  <?php if (defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER) : ?>
    <?php
      $openapIs5GHz = $arrConfig['selected_hw_mode'] === 'ac';
      $openapWidth = (int) ($arrConfig['openap_channel_width'] ?? ($openapIs5GHz ? 80 : 20));
    ?>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="cbxopenapband"><?php echo _("Wi-Fi band"); ?></label>
        <select class="form-select" id="cbxopenapband">
          <option value="2.4"<?php echo !$openapIs5GHz ? ' selected' : ''; ?>><?php echo _("2.4 GHz — longer range and widest compatibility"); ?></option>
          <option value="5"<?php echo $openapIs5GHz ? ' selected' : ''; ?>><?php echo _("5 GHz — higher speed and less interference"); ?></option>
        </select>
        <input type="hidden" name="hw_mode" id="cbxhwmode" value="<?php echo $openapIs5GHz ? 'ac' : 'n'; ?>">
      </div>
    </div>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="txtopenapmode"><?php echo _("Wireless mode"); ?></label>
        <input type="text" class="form-control" id="txtopenapmode" value="<?php echo $openapIs5GHz ? '802.11ac' : '802.11n'; ?>" readonly>
        <span class="form-text text-muted"><?php echo _("The wireless mode follows the selected band automatically."); ?></span>
      </div>
    </div>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="cbxopenapwidth"><?php echo _("Channel width"); ?></label>
        <select class="form-select" name="openap_channel_width" id="cbxopenapwidth" data-current-width="<?php echo $openapWidth; ?>"></select>
        <span class="form-text text-muted"><?php echo _("OpenAP defaults to 20 MHz after a channel change. Wider compatible widths remain available."); ?></span>
      </div>
    </div>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="cbxchannel"><?php echo _("Channel"); ?></label>
        <select class="form-select" name="channel" id="cbxchannel" data-current-channel="<?php echo intval($arrConfig['channel']); ?>"></select>
        <span class="form-text text-muted" id="openap-channel-status">
          <?php echo _("Loading channels supported by this adapter..."); ?>
        </span>
      </div>
    </div>
    <div class="alert alert-warning py-2" role="alert">
      <?php echo _("Changing band, mode, width or channel restarts the hotspot. Connected Wi-Fi devices must reconnect afterward."); ?>
    </div>
  <?php else : ?>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="cbxhwmode"><?php echo _("Wireless Mode") ;?></label>
        <i class="fas fa-info-circle" data-bs-toggle="tooltip" data-bs-placement="right" title="<?php echo _('Wireless standards which are not compatible with your adapter may be disabled') ?>"></i>
        <?php SelectorOptions('hw_mode', $arr80211Standard, $arrConfig['selected_hw_mode'], 'cbxhwmode', 'getChannel'); ?>
        <span id="suggested-hw-mode-text" class="form-text text-muted" style="display: none;">
          <?php echo sprintf(_('Based on the selected interface, 802.11%s is suggested.'), '<span id="suggested-hw-mode"></span>') ?>
        </span>
      </div>
    </div>
    <div class="row">
      <div class="mb-3 col-md-6">
        <label for="cbxchannel"><?php echo _("Channel"); ?></label>
        <?php
        $selectablechannels = [];
        SelectorOptions('channel', $selectablechannels, intval($arrConfig['channel']), 'cbxchannel'); ?>
      </div>
    </div>
  <?php endif; ?>
</div><!-- /.tab-pane | basic tab -->
