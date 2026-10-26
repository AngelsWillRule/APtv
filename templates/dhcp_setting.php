<?php
$dhcpManagedUpstream = ($currentMode ?? '') === 'ap_ethernet_bridge';
$dhcpProfile = [];
if (defined('OPENAP_REPEATER_PROFILE') && is_readable(OPENAP_REPEATER_PROFILE)) {
    $parsedProfile = parse_ini_file(OPENAP_REPEATER_PROFILE, true, INI_SCANNER_RAW);
    $dhcpProfile = is_array($parsedProfile) ? $parsedProfile : [];
}
$network = $dhcpProfile['network'] ?? [];
$interfaces = $dhcpProfile['interfaces'] ?? [];
$dhcpSubnet = (string) ($network['subnet'] ?? '10.88.77.0/24');
$dhcpNetworkAddress = preg_replace('/\/\d+$/', '', $dhcpSubnet);
$dhcpGateway = (string) ($network['gateway'] ?? '10.88.77.1');
$dhcpStart = (string) ($network['dhcp_start'] ?? '10.88.77.50');
$dhcpEnd = (string) ($network['dhcp_end'] ?? '10.88.77.200');
$dhcpInterface = (string) ($interfaces['bridge'] ?? $interfaces['ap'] ?? (!empty($apIface) ? $apIface : $interface));
$dhcpLeaseTime = '12h';
$dhcpAdvertisedDns = $dhcpGateway;
$dhcpUpstreamDns = [];

foreach (glob('/etc/dnsmasq.d/*.conf') ?: [] as $dnsmasqFile) {
    $dnsmasqLines = @file($dnsmasqFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($dnsmasqLines) || !in_array('interface='.$dhcpInterface, $dnsmasqLines, true)) {
        continue;
    }
    foreach ($dnsmasqLines as $line) {
        if (str_starts_with($line, 'dhcp-range=')) {
            $parts = array_map('trim', explode(',', substr($line, 11)));
            $dhcpStart = $parts[0] ?? $dhcpStart;
            $dhcpEnd = $parts[1] ?? $dhcpEnd;
            $rangeTail = end($parts);
            if (is_string($rangeTail) && preg_match('/^\d+[smhdw]$/i', $rangeTail)) {
                $dhcpLeaseTime = $rangeTail;
            }
        } elseif (str_starts_with($line, 'dhcp-option=6,')) {
            $dhcpAdvertisedDns = trim(substr($line, 14));
        } elseif (str_starts_with($line, 'server=')) {
            $dhcpUpstreamDns[] = trim(substr($line, 7));
        }
    }
    break;
}
$dhcpDnsPolicy = $dhcpAdvertisedDns === $dhcpGateway ? _('Local DNS') : _('External DNS');
$dhcpUpstreamLabel = $dhcpUpstreamDns !== [] ? implode(', ', $dhcpUpstreamDns) : $dhcpAdvertisedDns;
$dhcpDnsPresets = [
    'cloudflare' => ['label' => 'Cloudflare', 'addresses' => '1.1.1.1, 1.0.0.1'],
    'quad9' => ['label' => 'Quad9', 'addresses' => '9.9.9.9, 149.112.112.112'],
    'google' => ['label' => 'Google Public DNS', 'addresses' => '8.8.8.8, 8.8.4.4'],
];
$normalizeDnsList = static fn(string $value): string => implode(',', array_filter(array_map('trim', explode(',', $value))));
$dhcpDnsPreset = 'custom';
foreach ($dhcpDnsPresets as $presetKey => $preset) {
    $configuredDns = $dhcpDnsPolicy === _('Local DNS') ? $dhcpUpstreamLabel : $dhcpAdvertisedDns;
    if ($normalizeDnsList($configuredDns) === $normalizeDnsList($preset['addresses'])) {
        $dhcpDnsPreset = $presetKey;
        break;
    }
}
$encryptedDnsState = [];
if (is_readable('/etc/openap/encrypted-dns.ini')) {
    $parsedEncryptedDnsState = parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_TYPED);
    $encryptedDnsState = is_array($parsedEncryptedDnsState) ? ($parsedEncryptedDnsState['encrypted_dns'] ?? []) : [];
}
$encryptedDnsEnabled = !empty($encryptedDnsState['enabled']);
$encryptedDnsProvider = in_array(($encryptedDnsState['provider'] ?? ''), ['cloudflare', 'quad9'], true)
    ? (string) $encryptedDnsState['provider']
    : 'cloudflare';
$encryptedDnsServiceActive = !empty($serviceList['dnscrypt']);
$encryptedDnsHealthy = $encryptedDnsEnabled
    && $encryptedDnsServiceActive
    && in_array('127.0.2.1', $dhcpUpstreamDns, true)
    && !$dhcpManagedUpstream;
$encryptedDnsStatus = $dhcpManagedUpstream && $encryptedDnsEnabled
    ? _('Ready, not enforced')
    : ($encryptedDnsHealthy
    ? _('Protected')
    : ($encryptedDnsEnabled ? _('Degraded') : _('Disabled')));
$encryptedDnsResult = $_SESSION['openap_encrypted_dns_result'] ?? null;
unset($_SESSION['openap_encrypted_dns_result']);
$dhcpActive = (int) ($dhcpPool['active'] ?? 0);
$dhcpTotal = (int) ($dhcpPool['total'] ?? 150);
$dhcpRange = $dhcpStart.' - '.$dhcpEnd;
$dhcpPercent = $dhcpTotal > 0 ? min(100, (int) round(($dhcpActive / $dhcpTotal) * 100)) : 0;
$dhcpServices = [
    'hostapd' => ['hostapd', htmlspecialchars($interface).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
    'lighttpd' => ['lighttpd', 'Web server'],
];
if (is_file('/etc/dnscrypt-proxy/dnscrypt-proxy.toml')) {
    $dhcpServices['dnscrypt'] = ['dnscrypt-proxy', 'Encrypted DNS'];
}
?>

<div class="container-fluid p-0 openap-ap-configuration-page openap-dhcp-setting-page">
  <?php $status->showMessages(); ?>

  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <?php
      $openapWifiHotspotCardHeader = true;
      $openapWifiHotspotHeaderTitle = _("DHCP and DNS Settings");
      $openapWifiHotspotHeaderIcon = 'fa-network-wired';
      require __DIR__ . '/wifi_hotspot.php';
      ?>
      <?php if ($dhcpManagedUpstream): ?>
      <div class="openap-bridge-readonly-note">
        <i class="fas fa-link" aria-hidden="true"></i>
        <div><strong><?php echo _("Ethernet Bridge mode"); ?></strong><span><?php echo _("Subnet, gateway, DHCP pool and client DNS are assigned by the upstream router. The values below are shown for reference and cannot be edited."); ?></span></div>
      </div>
      <?php endif; ?>
      <div class="card shadow openap-ap-config-panel openap-dhcp-dns-panel" id="dhcpSettingPanel">
        <div class="openap-dhcp-dns-layout">
        <div class="openap-dhcp-dns-column openap-dhcp-column">
        <form method="POST" action="/dhcp_setting" id="dhcpSettingForm" class="needs-validation openap-dhcp-column-form" data-encrypted-dns="<?php echo $encryptedDnsEnabled ? '1' : '0'; ?>" novalidate>
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <fieldset class="openap-dhcp-managed-fields"<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>>
          <div class="openap-config-sections openap-dhcp-column-sections">
            <section class="openap-config-section">
              <div class="openap-config-section-heading"><i class="fas fa-network-wired"></i><span><?php echo _("Basic"); ?></span></div>
              <div class="hfield-row openap-dhcp-basic-fields">
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Hotspot subnet"); ?></div><div class="openap-input-with-suffix"><input class="hfield-input" id="dhcpNetworkAddress" type="text" name="dhcp_network" value="<?php echo htmlspecialchars($dhcpNetworkAddress, ENT_QUOTES); ?>" autocomplete="off" required><span class="openap-input-suffix" aria-label="<?php echo _("Network prefix"); ?>">/24</span></div><input type="hidden" name="dhcp_subnet" id="dhcpSubnet" value="<?php echo htmlspecialchars($dhcpSubnet, ENT_QUOTES); ?>"></div>
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Gateway"); ?></div><input class="hfield-input" id="dhcpGateway" type="text" name="dhcp_gateway" value="<?php echo htmlspecialchars($dhcpGateway, ENT_QUOTES); ?>" readonly required></div>
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("AP interface"); ?></div><input class="hfield-input" value="<?php echo htmlspecialchars($dhcpInterface, ENT_QUOTES); ?>" readonly></div>
              </div>
            </section>

            <section class="openap-config-section">
              <div class="openap-config-section-heading"><i class="fas fa-exchange-alt"></i><span><?php echo _("DHCP Pool"); ?></span></div>
              <div class="hfield-row">
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Range start"); ?></div><input class="hfield-input" id="dhcpRangeStart" type="text" name="dhcp_start" value="<?php echo htmlspecialchars($dhcpStart, ENT_QUOTES); ?>" autocomplete="off" required></div>
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Range end"); ?></div><input class="hfield-input" id="dhcpRangeEnd" type="text" name="dhcp_end" value="<?php echo htmlspecialchars($dhcpEnd, ENT_QUOTES); ?>" autocomplete="off" required></div>
                <div class="hfield-group"><div class="hfield-label"><?php echo _("Lease time"); ?></div><input class="hfield-input" type="text" name="dhcp_lease_time" value="<?php echo htmlspecialchars($dhcpLeaseTime, ENT_QUOTES); ?>" autocomplete="off" required pattern="[1-9][0-9]*[mhdwMHDW]"></div>
              </div>
            </section>

          </div>
          </fieldset>
          <div class="card-footer openap-column-footer d-flex justify-content-between align-items-center gap-2 px-3 py-2">
            <span class="small text-muted"><i class="fas <?php echo $dhcpManagedUpstream ? 'fa-lock' : 'fa-shield-alt'; ?> me-1"></i><?php echo $dhcpManagedUpstream ? _("Managed by upstream router") : _("Validated before applying"); ?></span>
            <button type="submit" name="SaveDhcpSettings" value="1" class="btn-ss primary openap-save-action"<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>><i class="fas fa-floppy-disk" aria-hidden="true"></i> <span><?php echo _("Save DHCP"); ?></span></button>
          </div>
        </form>
        </div>

        <div class="openap-dhcp-dns-column openap-dns-column">
          <div class="openap-config-sections openap-dns-column-sections">
            <section class="openap-config-section openap-standard-dns-section">
              <div class="openap-config-section-heading"><i class="fas fa-globe"></i><span><?php echo $encryptedDnsEnabled ? _("Protected DNS path") : _("Standard DNS"); ?></span></div>
              <?php if ($encryptedDnsEnabled): ?>
              <input type="hidden" form="dnsSettingsForm" name="dhcp_dns_policy" id="dhcpDnsPolicy" value="local">
              <input type="hidden" form="dnsSettingsForm" name="dhcp_advertised_dns" id="dhcpAdvertisedDns" value="<?php echo htmlspecialchars($dhcpGateway, ENT_QUOTES); ?>">
              <input type="hidden" form="dnsSettingsForm" name="dhcp_upstream_dns" id="dhcpUpstreamDns" value="127.0.2.1">
              <div class="openap-dns-path" aria-label="<?php echo _("Protected DNS path"); ?>">
                <div class="openap-dns-path-node">
                  <i class="fas fa-laptop" aria-hidden="true"></i>
                  <span><?php echo _("Client DNS"); ?></span>
                  <strong><?php echo htmlspecialchars($dhcpGateway, ENT_QUOTES); ?></strong>
                </div>
                <i class="fas fa-chevron-right openap-dns-path-arrow" aria-hidden="true"></i>
                <div class="openap-dns-path-node">
                  <i class="fas fa-shield-alt" aria-hidden="true"></i>
                  <span><?php echo _("Encrypted proxy"); ?></span>
                  <strong>127.0.2.1</strong>
                </div>
                <i class="fas fa-chevron-right openap-dns-path-arrow" aria-hidden="true"></i>
                <div class="openap-dns-path-node">
                  <i class="fas fa-cloud" aria-hidden="true"></i>
                  <span><?php echo _("Secure provider"); ?></span>
                  <strong><?php echo $encryptedDnsProvider === 'quad9' ? 'Quad9 Security' : 'Cloudflare'; ?></strong>
                </div>
                <i class="fas fa-chevron-right openap-dns-path-arrow" aria-hidden="true"></i>
                <div class="openap-dns-path-node">
                  <i class="fas fa-lock" aria-hidden="true"></i>
                  <span><?php echo _("Transport"); ?></span>
                  <strong>DNS over HTTPS</strong>
                </div>
              </div>
              <div class="openap-info-badge openap-dns-managed-info" role="note"><i class="fas fa-circle-info" aria-hidden="true"></i><span><?php echo _("These values are managed automatically while Encrypted DNS is active."); ?></span></div>
              <?php else: ?>
              <div class="hfield-row openap-standard-dns-fields">
                <div class="hfield-group"><div class="hfield-label"><?php echo _("DNS provider"); ?></div><select class="hfield-input" form="dnsSettingsForm" name="dns_provider" id="dhcpDnsPreset"<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>><option value="custom"<?php echo $dhcpDnsPreset === 'custom' ? ' selected' : ''; ?>><?php echo _("Custom"); ?></option><?php foreach ($dhcpDnsPresets as $presetKey => $preset): ?><option value="<?php echo htmlspecialchars($presetKey, ENT_QUOTES); ?>" data-addresses="<?php echo htmlspecialchars($preset['addresses'], ENT_QUOTES); ?>"<?php echo $dhcpDnsPreset === $presetKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($preset['label'], ENT_QUOTES); ?></option><?php endforeach; ?></select></div>
                <div class="hfield-group"><div class="hfield-label"><?php echo _("DNS policy"); ?></div><select class="hfield-input" form="dnsSettingsForm" name="dhcp_dns_policy" id="dhcpDnsPolicy"<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>><option value="local"<?php echo $dhcpDnsPolicy === _('Local DNS') ? ' selected' : ''; ?>><?php echo _("Local DNS"); ?></option><option value="external"<?php echo $dhcpDnsPolicy === _('External DNS') ? ' selected' : ''; ?>><?php echo _("External DNS"); ?></option></select></div>
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Advertised DNS"); ?></div><input class="hfield-input" form="dnsSettingsForm" type="text" id="dhcpAdvertisedDns" name="dhcp_advertised_dns" value="<?php echo htmlspecialchars($dhcpAdvertisedDns, ENT_QUOTES); ?>" autocomplete="off" required<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>></div>
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("Upstream DNS"); ?></div><input class="hfield-input" form="dnsSettingsForm" type="text" id="dhcpUpstreamDns" name="dhcp_upstream_dns" value="<?php echo htmlspecialchars($dhcpUpstreamLabel, ENT_QUOTES); ?>" autocomplete="off" required<?php echo $dhcpManagedUpstream ? ' disabled' : ''; ?>></div>
              </div>
              <div class="openap-info-badge"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?php echo _("Standard DNS uses unencrypted port 53 and may be intercepted or redirected by the network provider."); ?></span></div>
              <?php endif; ?>
            </section>
            <section class="openap-config-section openap-encrypted-dns-section">
              <div class="openap-config-section-heading"><i class="fas fa-shield-alt"></i><span><?php echo _("Encrypted DNS"); ?></span></div>
              <p class="openap-section-description"><?php echo _("Protect hotspot DNS requests by forwarding them to a trusted resolver over an encrypted connection."); ?></p>
        <form method="POST" action="/dhcp_setting" id="dnsSettingsForm" class="openap-encrypted-dns-form" data-saved-enabled="<?php echo $encryptedDnsEnabled ? '1' : '0'; ?>" data-saved-provider="<?php echo htmlspecialchars($dhcpDnsPreset, ENT_QUOTES); ?>">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
              <div class="hfield-row openap-encrypted-dns-fields">
                <?php if ($encryptedDnsEnabled): ?>
                <div class="hfield-group">
                  <div class="hfield-label"><?php echo _("DNS provider"); ?></div>
                  <select class="hfield-input" name="dns_provider" id="dhcpDnsPreset">
                    <option value="cloudflare" data-addresses="1.1.1.1, 1.0.0.1"<?php echo $encryptedDnsProvider === 'cloudflare' ? ' selected' : ''; ?>>Cloudflare</option>
                    <option value="quad9" data-addresses="9.9.9.9, 149.112.112.112"<?php echo $encryptedDnsProvider === 'quad9' ? ' selected' : ''; ?>>Quad9 Security</option>
                  </select>
                </div>
                <?php endif; ?>
                <div class="hfield-group openap-encrypted-dns-toggle">
                  <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                      <div class="hfield-label mb-1"><?php echo _("Use Encrypted DNS"); ?></div>
                      <div class="small text-muted"><?php echo _("OpenAP remains the DNS server for hotspot clients."); ?></div>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input type="hidden" name="encrypted_dns_enabled" value="0">
                      <input class="form-check-input" type="checkbox" role="switch" name="encrypted_dns_enabled" id="encryptedDnsEnabled" value="1"<?php echo $encryptedDnsEnabled ? ' checked' : ''; ?>>
                    </div>
                  </div>
                </div>
                <div class="hfield-group openap-encrypted-dns-runtime-value">
                  <div class="hfield-label"><?php echo _("Transport"); ?></div>
                  <input class="hfield-input" value="<?php echo $encryptedDnsEnabled ? 'DNS over HTTPS (DoH)' : _('Standard DNS'); ?>" readonly>
                </div>
                <div class="hfield-group openap-encrypted-dns-runtime-value">
                  <div class="hfield-label"><?php echo _("Local endpoint"); ?></div>
                  <input class="hfield-input" value="<?php echo $encryptedDnsEnabled ? '127.0.2.1:53' : _('Not active'); ?>" readonly>
                </div>
              </div>
              <div class="openap-encrypted-dns-note openap-info-badge">
                <i class="fas <?php echo $encryptedDnsHealthy ? 'fa-lock' : ($dhcpManagedUpstream ? 'fa-link' : 'fa-info-circle'); ?>"></i>
                <span><?php echo $dhcpManagedUpstream
                    ? _("Bridge clients use the DNS assigned by the upstream router. This resolver is configurable but is not enforced on bridged clients.")
                    : ($encryptedDnsEnabled
                    ? _("dnsmasq forwards only to the local encrypted proxy; system and ISP DNS are ignored.")
                    : _("When disabled, OpenAP uses the selected public resolver over standard DNS.")); ?></span>
                <strong class="<?php echo $encryptedDnsHealthy ? 'is-protected' : ($encryptedDnsEnabled ? 'is-degraded' : ''); ?>"><?php echo htmlspecialchars($encryptedDnsStatus, ENT_QUOTES); ?></strong>
              </div>
          <div class="card-footer openap-column-footer d-flex justify-content-between align-items-center gap-2 px-3 py-2">
            <span class="small text-muted"><i class="fas fa-rotate-left me-1"></i><?php echo _("Automatic rollback on validation failure"); ?></span>
            <button type="submit" name="SaveDnsSettings" value="1" class="btn-ss primary openap-save-action" id="applyDnsSettings"><i class="fas fa-floppy-disk" aria-hidden="true"></i> <span><?php echo _("Apply DNS settings"); ?></span></button>
          </div>
        </form>
            </section>
          </div>
        </div>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-lg-4"><?php echo openapWidgetArea('dhcp_setting'); ?></div>
  </div>
</div>

<div class="modal fade" id="apDualBandApplyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="dhcpApplyProgressTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
    <div class="modal-content openap-ap-ethernet-modal is-applying" id="dhcpApplyModalContent">
      <div class="modal-body openap-ap-ethernet-body">
        <div class="openap-interface-role-progress openap-universal-apply">
          <div class="openap-universal-apply-brand"><i class="fas fa-shuffle" aria-hidden="true"></i><span>OPENAP</span></div>
          <div class="openap-mode-switch-title" id="dhcpApplyProgressTitle"><?php echo _("Applying changes"); ?></div>
          <div class="openap-mode-switch-caption" id="dhcpApplyProgressCaption"><?php echo _("Network services may restart briefly."); ?></div>
          <div class="openap-universal-apply-visual" aria-hidden="true"><span><i class="fas fa-network-wired" id="dhcpApplyProgressIcon"></i></span></div>
          <div class="openap-mode-switch-steps" aria-label="<?php echo _("Application progress"); ?>">
            <div id="dhcpApplyStepPrepare" class="active"><i class="fas fa-circle-notch fa-spin"></i><span><?php echo _("Validating settings"); ?></span></div>
            <div id="dhcpApplyStepApply"><i class="far fa-circle"></i><span><?php echo _("Applying configuration"); ?></span></div>
            <div id="dhcpApplyStepVerify"><i class="far fa-circle"></i><span><?php echo _("Verifying services"); ?></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (is_array($encryptedDnsResult)): ?>
<div id="encryptedDnsOperationResult"
     class="d-none"
     data-level="<?php echo !empty($encryptedDnsResult['success']) ? 'success' : 'danger'; ?>"
     data-message="<?php echo htmlspecialchars((string) ($encryptedDnsResult['message'] ?? ''), ENT_QUOTES); ?>"></div>
<?php endif; ?>
