<div class="container-fluid p-0 openap-about-page">
  <div class="card shadow openap-about-shell openap-about-app">
    <div class="openap-about-hero">
      <div class="openap-about-logo-wrap">
        <img class="openap-about-logo" src="/app/img/aptv-header-logo-stylized.svg" alt="<?php echo _("APtv logo"); ?>">
      </div>
      <div class="openap-about-intro">
        <div class="openap-about-kicker"><?php echo _("Unified platform"); ?></div>
        <h1>APtv</h1>
        <div class="openap-about-version"><?php echo _("Version"); ?> <?php echo htmlspecialchars(APTV_VERSION, ENT_QUOTES); ?></div>
        <p><?php echo _("APtv brings the network access point and IPTV experience together in one coherent application, with a single interface for configuration, content and playback."); ?></p>
      </div>
    </div>

    <div class="openap-about-feature-grid">
      <section class="openap-about-section">
        <div class="openap-about-section-heading">
          <span><i class="fas fa-broadcast-tower"></i></span>
          <div>
            <strong><?php echo _("OpenAP network services"); ?></strong>
            <small><?php echo _("Access point and network control"); ?></small>
          </div>
        </div>
        <div class="openap-about-copy">
          <p><?php echo _("OpenAP provides the network foundation of APtv: WiFi access point management, Ethernet uplinks, bridge and repeater workflows, DHCP, DNS and firewall coordination."); ?></p>
          <p><?php echo _("Hardware capabilities and active network backends are detected at runtime, while privileged helpers keep system changes controlled and recoverable."); ?></p>
        </div>
      </section>

      <section class="openap-about-section">
        <div class="openap-about-section-heading">
          <span><i class="fas fa-tv"></i></span>
          <div>
            <strong><?php echo _("IPTV entertainment"); ?></strong>
            <small><?php echo _("Your catalogue and playback experience"); ?></small>
          </div>
        </div>
        <div class="openap-about-copy">
          <p><?php echo _("The IPTV component manages providers and catalogues for live channels, movies and TV series, with recordings, search, provider profiles and responsive navigation."); ?></p>
          <p><?php echo _("APtv includes adaptive live and VOD playback, programme information and diagnostics designed to make streaming behaviour clear and reliable."); ?></p>
        </div>
      </section>
    </div>

    <section class="openap-about-section openap-about-unified-section">
      <div class="openap-about-section-heading">
        <span><i class="fas fa-layer-group"></i></span>
        <div>
          <strong><?php echo _("One APtv application"); ?></strong>
          <small><?php echo _("Two integrated components, one consistent experience"); ?></small>
        </div>
      </div>
      <div class="openap-about-copy">
        <p><?php echo _("APtv is the unified application name. OpenAP and IPTV are integrated components of the same installation, sharing the same account, visual language and administration workflow."); ?></p>
        <div class="openap-about-attribution-note openap-info-badge">
          <i class="fas fa-shield-alt"></i>
          <span><?php echo _("APtv version 1.0 combines network control and IPTV playback in a single appliance-oriented experience."); ?></span>
        </div>
        <div class="openap-about-attribution-note openap-info-badge">
          <i class="fas fa-beer"></i>
          <span><?php echo _("Enjoying APtv? Support its development with a beer:"); ?> <a href="https://paypal.me/APtvApp" target="_blank" rel="noopener"><?php echo _("Offer me a beer"); ?></a></span>
        </div>
      </div>
    </section>
  </div>
</div>
