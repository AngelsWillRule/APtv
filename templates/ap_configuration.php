<?php
$apDisplayInterface = $apIface ?: $interface;
$apDisplayChannel = $apChannel ?: '-';
$dhcpActive = (int) ($dhcpPool['active'] ?? 0);
$dhcpTotal = (int) ($dhcpPool['total'] ?? 150);
$dhcpRange = ($dhcpPool['range_start'] ?? '10.88.77.50').' - '.($dhcpPool['range_end'] ?? '10.88.77.200');
$dhcpLeaseTime = $dhcpPool['lease_time'] ?? '12h';
$dhcpDns = $dhcpPool['dns'] ?? '10.88.77.1';
$dhcpPercent = $dhcpTotal > 0 ? min(100, (int) round(($dhcpActive / $dhcpTotal) * 100)) : 0;
$apConfigurationServices = [
    'hostapd' => ['hostapd', htmlspecialchars($interface).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
];
if ($isRepeaterWifi) {
    $apConfigurationServices['wpa_supplicant'] = ['wpa_supplicant', htmlspecialchars($uplinkIface).' uplink'];
}
$apConfigurationServices['lighttpd'] = ['lighttpd', 'Web server'];
$apRoleRadio = null;
$uplinkRoleRadio = null;
$configuredApMac = strtolower((string) ($configuredApMac ?? ''));
$configuredUplinkMac = strtolower((string) ($configuredUplinkMac ?? ''));
foreach (($interfaceRoleRadios ?? []) as $roleRadio) {
    if (($configuredApMac !== '' && hash_equals($configuredApMac, $roleRadio['mac']))
        || ($configuredApMac === '' && $roleRadio['name'] === $apIface)) $apRoleRadio = $roleRadio;
    if (($configuredUplinkMac !== '' && hash_equals($configuredUplinkMac, $roleRadio['mac']))
        || ($configuredUplinkMac === '' && $roleRadio['name'] === $wifiRoleUplinkIface)) $uplinkRoleRadio = $roleRadio;
}
// The uplink stored during installation can be empty when OpenAP initially
// starts with a single radio. If another radio appears later, use the live
// detection result so the role switch becomes available without reinstalling.
if ($apRoleRadio && !$uplinkRoleRadio && !empty($apRoleRadio['supports_managed'])) {
    foreach (($interfaceRoleRadios ?? []) as $roleRadio) {
        if ($roleRadio['name'] !== $apIface && !empty($roleRadio['supports_ap'])) {
            $uplinkRoleRadio = $roleRadio;
            break;
        }
    }
}
if (!$apRoleRadio && $uplinkRoleRadio && !empty($uplinkRoleRadio['supports_ap'])) {
    foreach (($interfaceRoleRadios ?? []) as $roleRadio) {
        if ($roleRadio['mac'] !== $uplinkRoleRadio['mac'] && !empty($roleRadio['supports_managed'])) {
            $apRoleRadio = $roleRadio;
            break;
        }
    }
}
if (!$apRoleRadio && !$uplinkRoleRadio) {
    foreach (($interfaceRoleRadios ?? []) as $apCandidate) {
        if (empty($apCandidate['supports_managed'])) continue;
        foreach (($interfaceRoleRadios ?? []) as $uplinkCandidate) {
            if ($uplinkCandidate['mac'] !== $apCandidate['mac'] && !empty($uplinkCandidate['supports_ap'])) {
                $apRoleRadio = $apCandidate;
                $uplinkRoleRadio = $uplinkCandidate;
                break 2;
            }
        }
    }
}
$roleSwitchAvailable = $apRoleRadio && $uplinkRoleRadio;
$emptyRoleRadio = ['name' => '', 'mac' => '', 'driver' => '', 'bus' => '', 'bands' => ''];
$apRoleRadio = $apRoleRadio ?: $emptyRoleRadio;
$uplinkRoleRadio = $uplinkRoleRadio ?: $emptyRoleRadio;
?>

<div class="container-fluid p-0 openap-ap-configuration-page is-page-loading" id="apConfigurationPage" aria-busy="true" style="visibility:hidden;opacity:0">
  <?php $status->showMessages(); ?>

  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <?php
      $openapWifiHotspotCardHeader = true;
      $openapWifiHotspotHeaderTitle = _("AP Configuration");
      $openapWifiHotspotHeaderIcon = 'fa-broadcast-tower';
      require __DIR__ . '/wifi_hotspot.php';
      ?>
      <div class="card shadow openap-ap-config-panel" id="apConfigurationPanel">
        <form method="POST" action="hostapd_conf" id="apConfigurationForm" class="needs-validation" novalidate>
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="interface" value="<?php echo htmlspecialchars($apDisplayInterface, ENT_QUOTES); ?>">
          <input type="hidden" name="wpa_pairwise" value="CCMP">
          <input type="hidden" name="country_code" value="<?php echo htmlspecialchars($apCountry, ENT_QUOTES); ?>">
          <input type="hidden" name="repeaterEnable" value="1">
          <div class="hotspot-panes openap-config-sections openap-ap-configuration-layout" id="apConfigurationLayout">
            <div class="openap-multi-radio-roles" id="apMultiRadioRoles">
              <section class="openap-config-section openap-radio-layout-panel openap-available-radios-panel">
                <div class="openap-config-section-heading"><i class="fas fa-wifi"></i><span><?php echo _("Available WiFi"); ?></span><span class="openap-draft-badge" id="apRoleDraftBadge" hidden><?php echo _("Pending changes"); ?></span></div>
                <div class="openap-radio-unassigned" id="apUnassignedDropzone" tabindex="0" role="region" aria-label="<?php echo _("Available Wi-Fi interfaces not assigned to a role"); ?>">
                  <div class="openap-radio-drop-hint"><i class="fas fa-arrow-down"></i><span><?php echo _("Drop a radio here to remove its role"); ?></span></div>
                  <div class="openap-radio-card-list" id="apUnassignedRadios" aria-live="polite"></div>
                </div>
              </section>

              <section class="openap-config-section openap-radio-layout-panel openap-radio-roles-panel">
                <div class="openap-config-section-heading"><i class="fas fa-broadcast-tower"></i><span><?php echo _("Radio AP"); ?></span></div>
                <div class="openap-role-columns" id="apRadioRoleSlots">
                  <div class="openap-role-column openap-role-column-ap">
                    <div class="openap-role-ap-slots">
                      <div class="openap-radio-slot" data-role-slot="ap_5ghz" tabindex="0">
                        <div class="openap-radio-slot-title"><span class="openap-hotspot-band-badge">5G</span><span><?php echo _("Access point"); ?></span></div>
                        <div class="openap-radio-slot-content" data-role-content="ap_5ghz"></div>
                      </div>
                      <div class="openap-radio-slot" data-role-slot="ap_24ghz" tabindex="0">
                        <div class="openap-radio-slot-title"><span class="openap-hotspot-band-badge">2.4G</span><span><?php echo _("Access point"); ?></span></div>
                        <div class="openap-radio-slot-content" data-role-content="ap_24ghz"></div>
                      </div>
                    </div>
                  </div>
                  <div class="openap-role-column openap-role-column-uplink">
                    <div class="openap-role-column-heading"><i class="fas fa-cloud-upload-alt"></i><span><?php echo _("Wi-Fi uplink"); ?></span><small><?php echo _("Dedicated client radio"); ?></small></div>
                    <div class="openap-radio-slot" data-role-slot="uplink" tabindex="0">
                      <div class="openap-radio-slot-title"><i class="fas fa-wifi" aria-hidden="true"></i><span><?php echo _("Uplink"); ?></span></div>
                      <div class="openap-radio-slot-content" data-role-content="uplink"></div>
                    </div>
                  </div>
                </div>
                <div class="openap-role-draft-footer">
                  <div class="openap-role-draft-status" id="apRoleDraftStatus" role="status" aria-live="polite"></div>
                </div>
              </section>
            </div>

            <div class="openap-settings-layout-column">
            <section class="openap-config-section openap-config-basic" id="apc-basic" hidden aria-hidden="true">
              <div class="openap-config-section-heading"><i class="fas fa-sliders-h"></i><span><?php echo _("Basic (single band)"); ?></span></div>
              <div class="hfield-row">
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("SSID"); ?></div><input type="text" class="hfield-input" name="ssid" value="<?php echo htmlspecialchars(($ssid !== '-') ? $ssid : '', ENT_QUOTES); ?>" required minlength="1" maxlength="32"></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Band"); ?> <span class="chip-cap">auto</span></div><select class="hfield-select" id="apcBand"><option value="24" <?php echo in_array($apHwMode, ['b','g','n']) ? 'selected' : ''; ?>>2.4 GHz</option><option value="5" <?php echo in_array($apHwMode, ['a','ac']) ? 'selected' : ''; ?>>5 GHz</option></select></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Ch"); ?></div><select class="hfield-select" name="channel" id="apcChannel"><optgroup label="2.4 GHz"><?php for ($c=1;$c<=13;$c++): ?><option value="<?php echo $c; ?>" data-band="24" data-max-dbm="<?php echo (int) ($channelTxPowerLimits[$c] ?? 30); ?>" <?php echo (int)$apChannel === $c ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endfor; ?></optgroup><optgroup label="5 GHz"><?php foreach ($available5ghzChannels as $c): ?><option value="<?php echo $c; ?>" data-band="5" data-max-dbm="<?php echo (int) ($channelTxPowerLimits[(int) $c] ?? 30); ?>" <?php echo (int)$apChannel === (int)$c ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endforeach; ?></optgroup></select></div>
              </div>
              <div class="hfield-row">
                <div class="hfield-group"><div class="hfield-label"><?php echo _("Wireless Mode"); ?></div><input type="text" class="hfield-input" id="apcModeDisplay" value="<?php echo in_array($apHwMode, ['a','ac']) ? '802.11a / 802.11ac (5 GHz)' : '802.11n (2.4 GHz)'; ?>" readonly aria-readonly="true"><input type="hidden" name="hw_mode" id="apcMode" value="<?php echo in_array($apHwMode, ['a','ac']) ? 'ac' : 'n'; ?>"></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Width"); ?></div><select class="hfield-select" name="openap_channel_width" id="apcWidth" data-current-width="<?php echo (int)$apWidth; ?>"></select></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("TX dBm"); ?></div><input type="number" class="hfield-input" name="txpower" value="<?php echo (int) $apTxPower; ?>" min="1" max="<?php echo (int) $apTxPowerMax; ?>" required></div>
              </div>
              <div class="openap-config-info-badge"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?php echo htmlspecialchars($apDisplayInterface, ENT_QUOTES); ?> · <span id="apcChipInfo"><?php echo strtoupper($apHwMode).' · '.htmlspecialchars($apCountry, ENT_QUOTES); ?></span></span></div>
            </section>

            <section class="openap-config-section openap-config-dual-band" id="apc-dual-band" hidden>
              <div class="openap-config-section-heading"><i class="fas fa-sliders-h"></i><span><?php echo _("Basic settings"); ?></span></div>
              <div class="hfield-row"><div class="hfield-group wide"><div class="hfield-label"><?php echo _("SSID"); ?></div><input type="text" class="hfield-input" id="apcDualSsid" value="<?php echo htmlspecialchars(($ssid !== '-') ? $ssid : '', ENT_QUOTES); ?>" minlength="1" maxlength="32"></div></div>
              <div class="openap-dual-band-grid" id="apcBandSettingsGrid">
                <div class="openap-dual-band-card" id="apcBandSettings5" hidden>
                  <div class="openap-dual-band-card-heading"><span class="openap-hotspot-band-badge">5G</span><small id="apcDual5Radio">-</small></div>
                  <div class="hfield-row"><div class="hfield-group"><div class="hfield-label"><?php echo _("Channel"); ?></div><select class="hfield-select" id="apcDual5Channel"><?php foreach ($available5ghzChannels as $c): ?><option value="<?php echo (int) $c; ?>"><?php echo (int) $c; ?></option><?php endforeach; ?></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("Width"); ?></div><select class="hfield-select" id="apcDual5Width"><option value="20">20 MHz</option><option value="40">40 MHz</option><option value="80">80 MHz</option></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("TX dBm"); ?></div><input type="number" class="hfield-input" id="apcDual5TxPower" value="<?php echo (int) $apTxPower; ?>" min="1" max="<?php echo (int) $apTxPowerMax; ?>" readonly aria-readonly="true"></div></div>
                </div>
                <div class="openap-dual-band-card" id="apcBandSettings24" hidden>
                  <div class="openap-dual-band-card-heading"><span class="openap-hotspot-band-badge">2.4G</span><small id="apcDual24Radio">-</small></div>
                  <div class="hfield-row"><div class="hfield-group"><div class="hfield-label"><?php echo _("Channel"); ?></div><select class="hfield-select" id="apcDual24Channel"><?php for ($c=1;$c<=13;$c++): ?><option value="<?php echo $c; ?>" <?php echo (int)$apChannel === $c ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endfor; ?></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("Width"); ?></div><select class="hfield-select" id="apcDual24Width"><option value="20">20 MHz</option><option value="40">40 MHz</option></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("TX dBm"); ?></div><input type="number" class="hfield-input" id="apcDual24TxPower" value="<?php echo (int) $apTxPower; ?>" min="1" max="<?php echo (int) $apTxPowerMax; ?>" readonly aria-readonly="true"></div></div>
                </div>
              </div>
              <div class="openap-config-info-badge"><i class="fas fa-info-circle"></i><span><?php echo _("Apply configuration saves these settings and activates the selected bands. Wi-Fi clients will disconnect briefly."); ?></span></div>
            </section>

            <section class="openap-config-section openap-config-no-ap" id="apc-no-ap" hidden>
              <div class="openap-config-info-badge"><i class="fas fa-info-circle"></i><span><?php echo _("Assign at least one wireless interface to an AP slot to configure the hotspot."); ?></span></div>
            </section>

            <section class="openap-config-section openap-config-security" id="apc-security">
              <div class="openap-config-section-heading"><i class="fas fa-shield-alt"></i><span><?php echo _("Security"); ?></span></div>
              <div class="hfield-row"><div class="hfield-group"><div class="hfield-label"><?php echo _("Security Type"); ?></div><select class="hfield-select" id="apcSecurity" name="wpa"><option value="2" <?php echo $apSecurityMode !== 'none' ? 'selected' : ''; ?>>WPA2-PSK</option><option value="none" <?php echo $apSecurityMode === 'none' ? 'selected' : ''; ?>><?php echo _("None (open network)"); ?></option></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("Encryption"); ?></div><input class="hfield-input" id="apcEncryption" value="<?php echo htmlspecialchars($apEncryption, ENT_QUOTES); ?>" readonly></div></div>
              <div class="hfield-row" id="apcPskRow"><div class="hfield-group wide"><div class="hfield-label"><?php echo _("Pre-shared Key (PSK)"); ?></div><div class="d-flex gap-1"><input type="password" class="hfield-input" id="apcPsk" name="wpa_passphrase" value="<?php echo htmlspecialchars($apPsk ?: '', ENT_QUOTES); ?>" minlength="8" maxlength="63" <?php echo $apSecurityMode === 'none' ? 'disabled' : 'required'; ?>><button type="button" class="btn-icon-ss" id="apcTogglePsk" title="<?php echo _("Toggle visibility"); ?>" aria-label="<?php echo _("Toggle password visibility"); ?>"><i class="fas fa-eye"></i></button><button type="button" class="btn-icon-ss" data-bs-toggle="modal" data-bs-target="#apConfigurationWifiQrModal" title="<?php echo _("WiFi QR code"); ?>" aria-label="<?php echo _("Open WiFi QR code"); ?>"><i class="fas fa-qrcode"></i></button></div></div></div>
              <div class="openap-config-info-badge openap-security-info-badge" id="apcSecurityNote"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?php echo $apSecurityMode === 'none' ? _("Open networks have no password or traffic encryption. Anyone within range can connect.") : _("WPA2-PSK with AES/CCMP is used for client compatibility."); ?></span></div>
              <div class="hfield-row openap-advanced-toggles" style="margin-top:20px">
                <div class="hfield-group openap-advanced-toggle">
                  <div class="openap-advanced-toggle-header">
                    <div class="openap-advanced-toggle-copy">
                      <div class="openap-advanced-toggle-title"><i class="fas fa-user-shield" aria-hidden="true"></i><span><?php echo _("AP Isolation"); ?></span></div>
                      <div class="openap-advanced-toggle-description"><?php echo _("Prevents wireless clients from communicating directly with each other."); ?></div>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input type="hidden" name="apIsolation" value="0">
                      <input class="form-check-input" type="checkbox" role="switch" name="apIsolation" id="apcIsolation" value="1" <?php echo $apIsolation ? 'checked' : ''; ?> aria-label="<?php echo _("AP Isolation"); ?>">
                    </div>
                  </div>
                </div>
                <div class="hfield-group openap-advanced-toggle">
                  <div class="openap-advanced-toggle-header">
                    <div class="openap-advanced-toggle-copy">
                      <div class="openap-advanced-toggle-title"><i class="fas fa-eye-slash" aria-hidden="true"></i><span><?php echo _("Hidden SSID"); ?></span></div>
                      <div class="openap-advanced-toggle-description"><?php echo _("Stops the network name from appearing in normal WiFi scans."); ?></div>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input type="hidden" name="hiddenSSID" value="0">
                      <input class="form-check-input" type="checkbox" role="switch" name="hiddenSSID" id="apcHiddenSsid" value="1" <?php echo $apIgnoreBroadcast ? 'checked' : ''; ?> aria-label="<?php echo _("Hidden SSID"); ?>">
                    </div>
                  </div>
                </div>
              </div>
            </section>
            </div>
          </div>

          <div class="card-footer d-flex justify-content-end align-items-center gap-2 px-3 py-2">
            <button type="button" class="btn-ss primary openap-save-action" id="apApplyRoleDraft" data-loading-text="<?php echo _("Saving..."); ?>" disabled>
              <span class="spinner-border spinner-border-sm d-none" data-openap-save-spinner role="status" aria-hidden="true"></span>
              <i class="fas fa-floppy-disk" data-openap-save-icon aria-hidden="true"></i>
              <span data-openap-save-label><?php echo _("Apply configuration"); ?></span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="col-xl-3 col-lg-4">
      <?php $openapWidgetPage = 'ap_configuration'; require __DIR__ . '/openap_widget_area.php'; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="apDualBandApplyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="apDualBandApplyTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
    <div class="modal-content openap-ap-ethernet-modal" id="apDualBandApplyModalContent">
      <div class="modal-header openap-ap-ethernet-header">
        <div class="openap-dual-apply-heading"><span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span><div><div class="openap-ap-ethernet-title" id="apDualBandApplyTitle"><?php echo _("Apply hotspot configuration"); ?></div><div class="openap-ap-ethernet-subtitle"><?php echo _("Review changes before restarting Wi-Fi"); ?></div></div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
      </div>
      <div class="modal-body openap-ap-ethernet-body">
        <div id="apDualBandConfirmView">
          <section class="openap-dual-apply-group">
            <div class="openap-dual-apply-group-label"><?php echo _("Network"); ?></div>
            <div class="openap-dual-apply-grid">
              <div class="openap-dual-apply-cell"><span><?php echo _("SSID"); ?></span><strong id="apDualApplySsid">-</strong></div>
              <div class="openap-dual-apply-cell"><span><?php echo _("Wi-Fi uplink"); ?></span><strong id="apDualApplyUplink">-</strong></div>
            </div>
          </section>
          <section class="openap-dual-apply-group">
            <div class="openap-dual-apply-group-label"><?php echo _("Access points"); ?></div>
            <div class="openap-dual-apply-grid">
              <div class="openap-dual-apply-cell"><span>2.4 GHz</span><strong id="apDualApply24">-</strong></div>
              <div class="openap-dual-apply-cell"><span>5 GHz</span><strong id="apDualApply5">-</strong></div>
            </div>
          </section>
          <section class="openap-dual-apply-group">
            <div class="openap-dual-apply-group-label"><?php echo _("Privacy"); ?></div>
            <div class="openap-dual-apply-privacy">
              <div><span><?php echo _("AP Isolation"); ?></span><strong id="apDualApplyIsolation">-</strong></div>
              <div><span><?php echo _("Hidden SSID"); ?></span><strong id="apDualApplyHiddenSsid">-</strong></div>
            </div>
          </section>
          <div class="openap-dual-apply-warning openap-info-badge">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            <div><strong><?php echo _("Wi-Fi clients will disconnect briefly"); ?></strong><span><i class="fas fa-rotate-left" aria-hidden="true"></i><?php echo _("Automatic rollback remains enabled if either access point fails."); ?></span></div>
          </div>
          <div class="openap-ap-ethernet-actions">
            <button type="button" class="btn-ss" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
            <button type="button" class="btn-ss primary" id="apDualBandApplyConfirm"><i class="fas fa-rotate" aria-hidden="true"></i> <span><?php echo _("Apply and restart"); ?></span></button>
          </div>
        </div>
        <div id="apDualBandProgressView" class="openap-interface-role-progress openap-universal-apply" hidden>
          <div class="openap-universal-apply-brand"><i class="fas fa-shuffle" aria-hidden="true"></i><span>OPENAP</span></div>
          <div class="openap-mode-switch-title" id="apDualBandProgressTitle"><?php echo _("Applying changes"); ?></div>
          <div class="openap-mode-switch-caption" id="apDualBandProgressCaption"><?php echo _("Wi-Fi clients may disconnect briefly."); ?></div>
          <div class="openap-universal-apply-visual" aria-hidden="true"><span><i class="fas fa-wifi"></i></span></div>
          <div class="openap-mode-switch-steps" aria-label="<?php echo _("Application progress"); ?>">
            <div id="apDualStepPrepare" class="active"><i class="fas fa-circle-notch fa-spin"></i><span><?php echo _("Validating saved draft"); ?></span></div>
            <div id="apDualStepApply"><i class="far fa-circle"></i><span><?php echo _("Restarting access point radios"); ?></span></div>
            <div id="apDualStepVerify"><i class="far fa-circle"></i><span><?php echo _("Verifying services and rollback state"); ?></span></div>
          </div>
          <button type="button" class="btn-ss openap-universal-apply-dismiss" id="apDualBandApplyDismiss" data-bs-dismiss="modal" hidden><?php echo _("Close"); ?></button>
        </div>
        <div id="apDualBandSuccessView" class="openap-interface-role-success" hidden>
          <div class="openap-interface-role-success-icon"><i class="fas fa-check"></i></div>
          <div class="openap-interface-role-success-title"><?php echo _("Dual-band hotspot active"); ?></div>
          <div class="openap-interface-role-success-caption"><span id="apDualBandSuccessCaption"></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="apInterfaceRoleModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="apInterfaceRoleModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
    <div class="modal-content openap-ap-ethernet-modal openap-interface-role-modal" id="apInterfaceRoleModalContent">
      <div id="apInterfaceRoleConfirmView">
        <div class="modal-header openap-ap-ethernet-header openap-interface-role-modal-header">
          <div>
            <div class="openap-ap-ethernet-title" id="apInterfaceRoleModalTitle"><?php echo _("Switch wireless roles"); ?></div>
            <div class="openap-ap-ethernet-subtitle"><?php echo _("Exchange access point and Wi-Fi uplink"); ?></div>
          </div>
          <div class="openap-ap-ethernet-header-actions">
            <span class="openap-ap-ethernet-header-icon"><i class="fas fa-right-left"></i></span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
          </div>
        </div>
        <div class="modal-body openap-ap-ethernet-body">
          <div class="openap-interface-role-modal-row">
            <span class="openap-ap-ethernet-setting-icon"><i class="fas fa-broadcast-tower"></i></span>
            <div><strong><?php echo _("Access point"); ?></strong><small><?php echo _("New hotspot radio"); ?></small></div>
            <b id="apRoleModalNewAp">-</b>
          </div>
          <div class="openap-interface-role-modal-row">
            <span class="openap-ap-ethernet-setting-icon"><i class="fas fa-wifi"></i></span>
            <div><strong><?php echo _("Wi-Fi uplink"); ?></strong><small><?php echo _("New Internet uplink radio"); ?></small></div>
            <b id="apRoleModalNewUplink">-</b>
          </div>
          <div class="openap-ap-ethernet-status">
            <span><?php echo _("Action"); ?>: <strong><?php echo _("Exchange roles"); ?></strong></span>
            <span><?php echo _("Hotspot"); ?>: <strong><?php echo _("Briefly unavailable"); ?></strong></span>
          </div>
          <div class="openap-ap-ethernet-actions">
            <button type="button" class="btn-ss" data-bs-dismiss="modal"><i class="fas fa-times"></i> <?php echo _("Cancel"); ?></button>
            <button type="button" class="btn-ss primary" id="apRoleModalConfirm"><i class="fas fa-right-left"></i> <?php echo _("Switch roles"); ?></button>
          </div>
        </div>
      </div>
      <div id="apInterfaceRoleProgressView" class="openap-interface-role-progress" hidden>
        <div class="openap-mode-switch-eyebrow"><i class="fas fa-shuffle"></i> OpenAP</div>
        <div class="openap-mode-switch-title" id="apRoleProgressTitle"><?php echo _("Switching wireless roles"); ?></div>
        <div class="openap-mode-switch-caption" id="apRoleProgressCaption"><?php echo _("OpenAP is restarting both Wi-Fi interfaces."); ?></div>
        <div class="openap-role-transfer-visual" aria-hidden="true">
          <div class="openap-role-transfer-side is-left">
            <strong><?php echo _("Access point"); ?></strong>
            <small class="openap-role-transfer-interface" id="apRoleProgressOldAp">AP</small>
          </div>
          <div class="openap-role-transfer-animation">
            <span class="openap-role-transfer-arrow is-forward"><i class="fas fa-long-arrow-alt-right"></i></span>
            <div class="openap-uplink-scan-visual">
              <span class="openap-uplink-scan-ring"></span>
              <span class="openap-uplink-scan-icon"><i class="fas fa-wifi"></i></span>
            </div>
            <span class="openap-role-transfer-arrow is-backward"><i class="fas fa-long-arrow-alt-left"></i></span>
          </div>
          <div class="openap-role-transfer-side is-right">
            <strong><?php echo _("Wi-Fi uplink"); ?></strong>
            <small class="openap-role-transfer-interface" id="apRoleProgressNewAp">Uplink</small>
          </div>
        </div>
        <div class="openap-mode-switch-steps">
          <div id="apRoleStepPrepare" class="active"><i class="fas fa-circle-notch fa-spin"></i><span><?php echo _("Preparing interfaces"); ?></span></div>
          <div id="apRoleStepApply"><i class="far fa-circle"></i><span><?php echo _("Applying AP and uplink roles"); ?></span></div>
          <div id="apRoleStepVerify"><i class="far fa-circle"></i><span><?php echo _("Verifying services and connectivity"); ?></span></div>
        </div>
      </div>
      <div id="apInterfaceRoleSuccessView" class="openap-interface-role-success" hidden>
        <div class="openap-interface-role-success-icon"><i class="fas fa-check"></i></div>
        <div class="openap-interface-role-success-title"><?php echo _("Operation successful"); ?></div>
        <div class="openap-interface-role-success-caption">
          <span><?php echo _("Access point"); ?>: <strong id="apRoleSuccessAp">-</strong></span>
          <span><?php echo _("Wi-Fi uplink"); ?>: <strong id="apRoleSuccessUplink">-</strong></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="apOpenSecurityConfirmModal" tabindex="-1" aria-labelledby="apOpenSecurityConfirmModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
    <div class="modal-content openap-ap-ethernet-modal openap-open-security-modal">
      <div class="modal-header openap-ap-ethernet-header">
        <div>
          <div class="openap-ap-ethernet-title" id="apOpenSecurityConfirmModalTitle"><?php echo _("Open network"); ?></div>
          <div class="openap-ap-ethernet-subtitle"><?php echo _("Confirm hotspot security"); ?></div>
        </div>
        <div class="openap-ap-ethernet-header-actions">
          <span class="openap-ap-ethernet-header-icon"><i class="fas fa-lock-open"></i></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
        </div>
      </div>
      <div class="modal-body openap-ap-ethernet-body openap-open-security-body">
        <div class="openap-open-security-label"><?php echo _("Security mode"); ?></div>
        <div class="openap-open-security-warning">
          <span class="openap-open-security-warning-icon"><i class="fas fa-triangle-exclamation"></i></span>
          <div>
            <strong><?php echo _("No password or encryption"); ?></strong>
            <p><?php echo _("Anyone within range will be able to connect to this hotspot and inspect unencrypted wireless traffic."); ?></p>
          </div>
        </div>
        <div class="openap-open-security-note"><i class="fas fa-circle-info"></i><span><?php echo _("Only continue if an open network is intentional."); ?></span></div>
        <div class="openap-ap-ethernet-status">
          <span><?php echo _("Security"); ?>: <strong><?php echo _("None"); ?></strong></span>
          <span><?php echo _("Encryption"); ?>: <strong><?php echo _("None"); ?></strong></span>
        </div>
        <div class="openap-ap-ethernet-actions">
          <button type="button" class="btn-ss" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
          <button type="button" class="btn-ss primary" id="apOpenSecurityConfirm"><i class="fas fa-lock-open"></i> <?php echo _("Create open network"); ?></button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="apConfigurationWifiQrModal" tabindex="-1" aria-labelledby="apConfigurationWifiQrModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered wifi-qr-dialog">
    <div class="modal-content wifi-qr-modal">
      <div class="modal-header wifi-qr-header">
        <div class="wifi-qr-heading">
          <span class="wifi-qr-heading-icon" aria-hidden="true"><i class="fas fa-qrcode"></i></span>
          <div>
            <div class="wifi-qr-eyebrow"><?php echo _("WiFi access"); ?></div>
            <h2 id="apConfigurationWifiQrModalTitle" class="wifi-qr-title"><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></h2>
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
