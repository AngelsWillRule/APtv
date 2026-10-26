<?php
$openapServiceStatusInterface = $apIface ?? $interface ?? '';
$openapServiceStatusProfile = [];
if (is_readable('/etc/openap/repeater.ini')) {
    $openapServiceStatusProfile = parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
    if (!is_array($openapServiceStatusProfile)) {
        $openapServiceStatusProfile = [];
    }
    if ($openapServiceStatusInterface === '') {
        $openapServiceStatusInterface = (string) ($openapServiceStatusProfile['interfaces']['ap'] ?? '');
    }
}
$openapServiceStatusMode = str_replace('-', '_', (string) ($openapServiceStatusProfile['mode']['current'] ?? 'ap_ethernet'));
$openapServiceStatusList = $serviceList ?? [];
if ($openapServiceStatusList === []) {
    $openapServiceStatusList = [
        'hostapd' => function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active',
        'dnsmasq' => function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active',
        'nftables' => function_exists('openapNatActive')
            ? openapNatActive()
            : (function_exists('openapServiceActive') && openapServiceActive('nftables.service') === 'active'),
        'lighttpd' => function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active',
        'dnscrypt' => function_exists('openapServiceActive') && openapServiceActive('dnscrypt-proxy.service') === 'active',
    ];
}
$openapServiceStatusServices = [
    'hostapd' => ['hostapd', htmlspecialchars((string) $openapServiceStatusInterface, ENT_QUOTES).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
    'lighttpd' => ['lighttpd', 'Web server'],
];
if ($openapServiceStatusMode === 'ap_ethernet_bridge') {
    // Bridge clients use upstream DHCP and forwarding at layer 2. dnsmasq and
    // the OpenAP NAT firewall are intentionally inactive in this mode.
    unset($openapServiceStatusServices['dnsmasq'], $openapServiceStatusServices['nftables']);
}

$openapEncryptedDnsEnabled = false;
if (is_readable('/etc/openap/encrypted-dns.ini')) {
    $openapEncryptedDnsProfile = parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_TYPED);
    $openapEncryptedDnsEnabled = is_array($openapEncryptedDnsProfile)
        && !empty($openapEncryptedDnsProfile['encrypted_dns']['enabled']);
}
if ($openapEncryptedDnsEnabled) {
    $openapServiceStatusServices['dnscrypt'] = ['dnscrypt-proxy', 'Encrypted DNS'];
}
$openapServiceStatusHealthy = true;
foreach (array_keys($openapServiceStatusServices) as $openapServiceStatusKey) {
    if (empty($openapServiceStatusList[$openapServiceStatusKey])) {
        $openapServiceStatusHealthy = false;
        break;
    }
}
?>
<div class="stat-card openap-side-status openap-service-status-card <?php echo $openapServiceStatusHealthy ? 'is-healthy' : 'is-degraded'; ?>">
  <div class="stat-top openap-widget-body">
    <div class="openap-widget-heading">
      <div>
        <div class="openap-widget-title"><?php echo _("Service status"); ?></div>
        <div class="openap-widget-caption"><?php echo _("Core OpenAP services"); ?></div>
      </div>
      <div class="openap-service-heading-status">
        <span class="badge rounded-pill badge-openap openap-topology-live-badge <?php echo $openapServiceStatusHealthy ? 'live' : 'degraded'; ?>" data-openap-service-live>
          <i class="fas fa-circle" aria-hidden="true"></i>
          <span><?php echo $openapServiceStatusHealthy ? _("Live") : _("Attention"); ?></span>
        </span>
        <div class="openap-widget-icon openap-widget-icon-green"><i class="fas fa-heart-pulse"></i></div>
      </div>
    </div>
    <div class="openap-service-grid">
      <?php foreach ($openapServiceStatusServices as $serviceKey => $service): ?>
      <div class="openap-service-item" data-openap-service-key="<?php echo htmlspecialchars($serviceKey, ENT_QUOTES); ?>">
        <span class="openap-service-led <?php echo !empty($openapServiceStatusList[$serviceKey]) ? 'is-active' : 'is-inactive'; ?>"></span>
        <div><strong><?php echo $service[0]; ?></strong></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stat-bottom">
    <span><i class="fas <?php echo $openapServiceStatusHealthy ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i> <?php echo _("Status"); ?></span>
    <strong><?php echo $openapServiceStatusHealthy ? _("Healthy") : _("Degraded"); ?></strong>
  </div>
</div>
