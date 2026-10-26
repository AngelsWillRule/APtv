<?php $_SESSION['lastActivity'] = time(); ?>

<div class="openap-footer-meta d-flex flex-column flex-sm-row align-items-center justify-content-between small">
  <div class="text-muted">
    <span class="pe-2"><button type="button" class="openap-about-footer-trigger" data-bs-toggle="modal" data-bs-target="#openapAboutModal">APtv v<?php echo htmlspecialchars(APTV_VERSION, ENT_QUOTES); ?></button></span>  |
    <span class="ps-2"><?php echo _("Unified OpenAP and IPTV platform"); ?></span>
  </div>
  <div class="text-muted">
    <i class="fas fa-book-reader"></i> <a href="https://github.com/AngelsWillRule/APtv/tree/main/docs" target="_blank" rel="noopener"><?php echo _("APtv documentation"); ?></a><span class="px-2">·</span><i class="fas fa-beer"></i> <a href="https://paypal.me/APtvApp" target="_blank" rel="noopener"><?php echo _("Offer me a beer"); ?></a>
  </div>
</div>
<?php if (($openapIsLoginPage ?? false) === false): ?>
  <?php if (($openapActiveArea ?? 'openap') === 'iptv'): ?>
    <nav class="openap-footer-mobile-nav openap-footer-iptv-nav" aria-label="<?php echo _("IPTV navigation"); ?>">
      <span class="openap-footer-iptv-track" aria-hidden="true"></span>
      <button type="button" data-openap-iptv-live-toggle><i class="fas fa-tv" aria-hidden="true"></i><span><?php echo _("Live"); ?></span></button>
      <button type="button" data-openap-iptv-movies-toggle><i class="fas fa-film" aria-hidden="true"></i><span><?php echo _("Movies"); ?></span></button>
      <button type="button" data-openap-iptv-series-toggle><i class="fas fa-photo-video" aria-hidden="true"></i><span><?php echo _("Series"); ?></span></button>
      <button type="button" data-openap-recordings-toggle><i class="fas fa-record-vinyl" aria-hidden="true"></i><span><?php echo _("Recordings"); ?></span></button>
      <button type="button" data-openap-iptv-settings-toggle><i class="fas fa-cog" aria-hidden="true"></i><span><?php echo _("Settings"); ?></span></button>
    </nav>
  <?php elseif (($openapActiveArea ?? 'openap') === 'openap'): ?>
    <?php $openapFooterPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; ?>
    <?php
      $openapMobileNavIndex = match ($openapFooterPath) {
        '/ap_configuration' => 1,
        '/dhcp_setting' => 2,
        '/logging' => 3,
        '/system_info' => 4,
        default => in_array($openapFooterPath, ['/', '/dashboard'], true) ? 0 : -1,
      };
    ?>
    <nav class="openap-mobile-bottom-nav openap-footer-mobile-nav" data-active-index="<?php echo $openapMobileNavIndex; ?>" style="--openap-mobile-active:<?php echo $openapMobileNavIndex; ?>" aria-label="<?php echo _("AP navigation"); ?>">
      <span class="openap-mobile-bottom-indicator" aria-hidden="true"></span>
      <a class="openap-mobile-bottom-link<?php echo in_array($openapFooterPath, ['/', '/dashboard'], true) ? ' active' : ''; ?>" data-mobile-index="0" href="/"><i class="fas fa-tachometer-alt" aria-hidden="true"></i><span><?php echo _("Dashboard"); ?></span></a>
      <a class="openap-mobile-bottom-link<?php echo $openapFooterPath === '/ap_configuration' ? ' active' : ''; ?>" data-mobile-index="1" href="/ap_configuration"><i class="fas fa-broadcast-tower" aria-hidden="true"></i><span><?php echo _("AP Config"); ?></span></a>
      <a class="openap-mobile-bottom-link<?php echo $openapFooterPath === '/dhcp_setting' ? ' active' : ''; ?>" data-mobile-index="2" href="/dhcp_setting"><i class="fas fa-exchange-alt" aria-hidden="true"></i><span><?php echo _("DHCP"); ?></span></a>
      <a class="openap-mobile-bottom-link<?php echo $openapFooterPath === '/logging' ? ' active' : ''; ?>" data-mobile-index="3" href="/logging"><i class="fas fa-file-alt" aria-hidden="true"></i><span><?php echo _("Logging"); ?></span></a>
      <a class="openap-mobile-bottom-link<?php echo $openapFooterPath === '/system_info' ? ' active' : ''; ?>" data-mobile-index="4" href="/system_info"><i class="fas fa-cube" aria-hidden="true"></i><span><?php echo _("System"); ?></span></a>
    </nav>
  <?php endif; ?>
<?php endif; ?>
