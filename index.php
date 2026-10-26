<?php

/**
 * OpenAP WiFi access point and repeater management
 *
 * Derived from the RaspAP web interface and adapted for OpenAP's validated
 * AP-over-Ethernet and two-radio repeater workflows.
 *
 * @author  Lawrence Yau <sirlagz@gmail.com>
 * @author  Bill Zimmerman <billzimmerman@gmail.com>
 * @author  OpenAP contributors
 * @license GNU General Public License, version 3 (GPL-3.0)
 * @version 0.2.5.1
 * @link    https://github.com/AngelsWillRule/OpenAP
 * @see     https://github.com/RaspAP/raspap-webgui
 */

require_once 'includes/bootstrap.php';
require_once 'includes/config.php';
require_once 'includes/defaults.php';
require_once 'includes/autoload.php';
$handler = new OpenAP\Exceptions\ExceptionHandler;

require_once 'includes/CSRF.php';
require_once 'includes/session.php';
require_once 'includes/locale.php';
require_once 'includes/functions.php';
require_once 'includes/components.php';

$openapIptvEnabled = openapComponentEnabled('iptv');
$openapApEnabled = openapComponentEnabled('openap');

$openapEarlyPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if ($openapEarlyPath === '/index.php') {
    $openapEarlyPath = $_SERVER['PATH_INFO'] ?? '';
} elseif (str_starts_with($openapEarlyPath, '/index.php/')) {
    $openapEarlyPath = substr($openapEarlyPath, strlen('/index.php')) ?: '/';
}
if ($openapEarlyPath === '/about') {
    http_response_code(404);
    exit;
}

// Default page actions
require_once 'includes/dashboard.php';
require_once 'includes/clients.php';
require_once 'includes/repeater.php';
require_once 'includes/login.php';

// The loopback-only VOD byte source is authenticated by an encrypted,
// short-lived token and must not depend on the interactive browser session.
$openapIptvPath = str_starts_with($openapEarlyPath, '/aptv') || str_starts_with($openapEarlyPath, '/iptv');
if (!$openapIptvEnabled && $openapIptvPath) {
    http_response_code(404);
    exit;
}
$openapPublicVodMediaRoutes = [
    '/aptv/api/vod/hls/playlist' => 'openapAptvServeVodHlsPlaylist',
    '/iptv/api/vod/hls/playlist' => 'openapAptvServeVodHlsPlaylist',
    '/aptv/api/vod/hls/segment' => 'openapAptvServeVodHlsSegment',
    '/iptv/api/vod/hls/segment' => 'openapAptvServeVodHlsSegment',
    '/aptv/api/vod/audio-hls/playlist' => 'openapAptvServeVodAudioHlsPlaylist',
    '/iptv/api/vod/audio-hls/playlist' => 'openapAptvServeVodAudioHlsPlaylist',
    '/aptv/api/vod/audio-hls/segment' => 'openapAptvServeVodAudioHlsSegment',
    '/iptv/api/vod/audio-hls/segment' => 'openapAptvServeVodAudioHlsSegment',
];
if (isset($openapPublicVodMediaRoutes[$openapEarlyPath])) {
    require_once 'includes/iptv_api.php';
    call_user_func($openapPublicVodMediaRoutes[$openapEarlyPath]);
    exit;
}
if (in_array($openapEarlyPath, ['/aptv/api/vod/source', '/iptv/api/vod/source'], true)) {
    require_once 'includes/iptv_api.php';
    openapAptvVodSource();
    exit;
}
if (str_starts_with($openapEarlyPath, '/posters/') || str_starts_with($openapEarlyPath, '/__openap_poster_fallback/')) {
    require_once 'includes/iptv_api.php';
    if (!$openapIptvEnabled) {
        http_response_code(404);
    } elseif (preg_match('~^/(?:posters|__openap_poster_fallback)/(v1/(?:vod|series)/[a-f0-9]{32}/[0-9]{1,20}-[a-f0-9]{64}\.jpg)$~', $openapEarlyPath, $openapPosterPath)) {
        openapAptvServeStaticPosterFallback($openapPosterPath[1]);
    } else {
        http_response_code(403);
    }
    exit;
}
require_once 'includes/authenticate.php';
require_once 'includes/admin.php';
require_once 'includes/hostapd.php';
require_once 'includes/system.php';
require_once 'includes/sysstats.php';
require_once 'includes/widgets.php';
require_once 'includes/client_watchdog.php';
if ($openapIptvEnabled) {
    require_once 'includes/iptv.php';
    require_once 'includes/iptv_api.php';
}

// Load optional feature modules only when the active profile enables them.
// This keeps disabled legacy modules outside the OpenAP request path while
// preserving the upstream feature switches for other profiles.
$optionalFeatureModules = [
    'OPENAP_DHCP_ENABLED' => 'includes/dhcp.php',
    'OPENAP_ADBLOCK_ENABLED' => 'includes/adblock.php',
    'OPENAP_WIFICLIENT_ENABLED' => 'includes/configure_client.php',
    'OPENAP_NETWORK_ENABLED' => 'includes/networking.php',
    'OPENAP_VNSTAT_ENABLED' => 'includes/data_usage.php',
    'OPENAP_OPENVPN_ENABLED' => 'includes/openvpn.php',
    'OPENAP_WIREGUARD_ENABLED' => 'includes/wireguard.php',
    'OPENAP_VPN_PROVIDER_ENABLED' => 'includes/provider.php',
    'OPENAP_RESTAPI_ENABLED' => 'includes/restapi.php',
];

foreach ($optionalFeatureModules as $featureConstant => $modulePath) {
    if (defined($featureConstant) && constant($featureConstant)) {
        require_once $modulePath;
    }
}
unset($optionalFeatureModules, $featureConstant, $modulePath);

initializeApp();

$openapAreaPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$openapActiveArea = (str_starts_with($openapAreaPath, '/aptv') || str_starts_with($openapAreaPath, '/iptv')) ? 'iptv' : 'openap';
$openapIsLoginPage = $openapAreaPath === '/login';
if (!$openapApEnabled && $openapIptvEnabled && !$openapIsLoginPage
    && !str_starts_with($openapAreaPath, '/aptv')
    && !str_starts_with($openapAreaPath, '/iptv')) {
    header('Location: /aptv', true, 302);
    exit;
}

// Machine-readable endpoints must run before any page markup is emitted.
// Keeping health checks outside the normal layout also makes reboot polling
// inexpensive and guarantees a valid JSON response body.
if (($_SERVER['PATH_INFO'] ?? '') === '/api/health') {
    require_once 'includes/api_health.php';
    openapServeHealthJson();
}
$openapRequestPath = $_SERVER['PATH_INFO'] ?? '';
if ($openapRequestPath === '/api/client-watchdog') { openapClientWatchdog(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/provider/index', '/iptv/api/provider/index'], true)) {
    openapIptvIndexProvider();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/providers', '/iptv/api/providers'], true)) {
    openapAptvListProviders();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/providers/switch', '/iptv/api/providers/switch'], true)) {
    openapAptvSwitchProvider();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/providers/delete', '/iptv/api/providers/delete'], true)) {
    openapAptvDeleteProvider();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/service', '/iptv/api/service'], true)) {
    openapIptvServiceStatus();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/catalog', '/iptv/api/catalog'], true)) {
    openapIptvCatalogBrowse();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/search', '/iptv/api/search'], true)) {
    openapAptvGlobalSearch();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/catalog/profile', '/iptv/api/catalog/profile'], true)) {
    openapAptvCatalogProfile();
    exit;
}
if ($openapRequestPath === '/aptv/api/recording') { require_once 'includes/recording.php'; openapRecordingApi(); exit; }
if ($openapRequestPath === '/aptv/api/recording/storage') { require_once 'includes/recording_storage.php'; openapRecordingStorage(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/epg', '/iptv/api/epg'], true)) { openapAptvUnifiedEpg(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/catalog/order', '/iptv/api/catalog/order'], true)) {
    openapAptvSaveCatalogOrder();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/vod', '/iptv/api/vod'], true)) { openapAptvVodBrowse(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/poster', '/iptv/api/vod/poster'], true)) { openapAptvServeVodPoster(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/details', '/iptv/api/vod/details'], true)) { openapAptvVodDetails(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/series', '/iptv/api/series'], true)) { openapAptvSeriesBrowse(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/series/poster', '/iptv/api/series/poster'], true)) { openapAptvServeSeriesPoster(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/series/episode-image', '/iptv/api/series/episode-image'], true)) { openapAptvServeEpisodeImage(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/series/details', '/iptv/api/series/details'], true)) { openapAptvSeriesDetails(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/series/episodes', '/iptv/api/series/episodes'], true)) { openapAptvSeriesEpisodes(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/stream', '/iptv/api/stream'], true)) {
    openapAptvProxyLiveStream();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/transcode', '/iptv/api/transcode'], true)) {
    openapAptvTranscodeHevcStream();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/transcode/start', '/iptv/api/transcode/start'], true)) {
    openapAptvStartHevcHls();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/transcode/playlist', '/iptv/api/transcode/playlist'], true)) {
    openapAptvServeHevcHlsPlaylist();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/transcode/segment', '/iptv/api/transcode/segment'], true)) {
    openapAptvServeHevcHlsSegment();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/transcode/stop', '/iptv/api/transcode/stop'], true)) {
    openapAptvStopHevcHls();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/webrtc/start', '/iptv/api/webrtc/start'], true)) { openapAptvStartWebrtc(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/play', '/iptv/api/vod/play'], true)) { openapAptvStartVodWebrtc(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/hls/start', '/iptv/api/vod/hls/start'], true)) { openapAptvStartVodHls(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/hls/playlist', '/iptv/api/vod/hls/playlist'], true)) { openapAptvServeVodHlsPlaylist(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/hls/segment', '/iptv/api/vod/hls/segment'], true)) { openapAptvServeVodHlsSegment(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/hls/stop', '/iptv/api/vod/hls/stop'], true)) { openapAptvStopVodHls(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/audio-start', '/iptv/api/vod/audio-start'], true)) { openapAptvServeVodAudioRemux(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/audio-hls/start', '/iptv/api/vod/audio-hls/start'], true)) { openapAptvStartVodAudioHls(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/audio-hls/playlist', '/iptv/api/vod/audio-hls/playlist'], true)) { openapAptvServeVodAudioHlsPlaylist(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/audio-hls/segment', '/iptv/api/vod/audio-hls/segment'], true)) { openapAptvServeVodAudioHlsSegment(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/direct', '/iptv/api/vod/direct'], true)) { openapAptvStartDirectVod(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/direct-stats', '/iptv/api/vod/direct-stats'], true)) { openapAptvVodDirectStats(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/vod/probe', '/iptv/api/vod/probe'], true)) { openapAptvVodProbeSeekable(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/webrtc/heartbeat', '/iptv/api/webrtc/heartbeat'], true)) { openapAptvWebrtcHeartbeat(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/webrtc/stop', '/iptv/api/webrtc/stop'], true)) { openapAptvStopWebrtc(); exit; }
if (in_array($openapRequestPath, ['/aptv/api/hls', '/iptv/api/hls'], true)) {
    openapAptvHlsPlaylist();
    exit;
}
if (in_array($openapRequestPath, ['/aptv/api/hls-segment', '/iptv/api/hls-segment'], true)) {
    openapAptvHlsSegment();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" <?php setTheme();?>>
  <head>
    <meta charset="utf-8">
    <?php echo \OpenAP\Tokens\CSRF::metaTag(); ?>
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="">
    <meta name="author" content="">

    <title><?php echo OPENAP_BRAND_TITLE; ?></title>

    <!-- Bootstrap Core CSS -->
    <link href="dist/bootstrap/css/bootstrap.min.css?v=<?= filemtime('dist/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">

    <!-- SB-Admin CSS -->
    <link href="dist/sb-admin/css/styles.css?v=<?= filemtime('dist/sb-admin/css/styles.css'); ?>" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="dist/font-awesome/css/all.min.css?v=<?= filemtime('dist/font-awesome/css/all.min.css'); ?>" rel="stylesheet" type="text/css">

    <!-- Custom CSS -->
    <link href="app/css/base.css?v=<?= filemtime('app/css/base.css'); ?>" rel="stylesheet" />
    <link href="<?php echo $_SESSION['theme']['url'] . "?v=" . filemtime($_SESSION['theme']['url']); ?>" title="main" rel="stylesheet">
    <link href="app/css/openap-recovered-responsive.css?v=<?= filemtime('app/css/openap-recovered-responsive.css'); ?>" rel="stylesheet" />
    
    <!-- Meta -->
    <link rel="icon" type="image/png" href="/app/icons/openap-favicon-96x96.png?v=<?= filemtime('app/icons/openap-favicon-96x96.png'); ?>" sizes="96x96" />
    <link rel="shortcut icon" href="/app/icons/openap-favicon.ico?v=<?= filemtime('app/icons/openap-favicon.ico'); ?>" />
    <link rel="apple-touch-icon" sizes="180x180" href="/app/icons/openap-apple-touch-icon.png?v=<?= filemtime('app/icons/openap-apple-touch-icon.png'); ?>" />
    <link rel="manifest" href="/app/icons/site.webmanifest?v=<?= filemtime('app/icons/site.webmanifest'); ?>" />
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(OPENAP_BRAND_TEXT); ?>" />
    <script>
      (function () {
        try {
          var areaDirection = window.sessionStorage.getItem('openap|area-page-transition');
          if (areaDirection === 'down' || areaDirection === 'up') {
            document.documentElement.classList.add('openap-area-enter-' + areaDirection);
          }
          var pageDirection = window.sessionStorage.getItem('openap|sidebar-page-transition');
          if (pageDirection === 'forward' || pageDirection === 'backward') {
            document.documentElement.classList.add('openap-page-preload-' + pageDirection);
          }
          var authDirection = window.sessionStorage.getItem('openap|auth-transition');
          if (authDirection === 'logout' || authDirection === 'login') {
            document.documentElement.classList.add('openap-auth-' + authDirection + '-preload');
          }
          if (window.matchMedia('(max-width: 991.98px)').matches &&
              window.localStorage.getItem('openap|mobile-sidebar-open') === 'true') {
            document.documentElement.classList.add('openap-mobile-sidebar-open');
          }
        } catch (e) {}
      })();
    </script>
    <meta name="theme-color" content="#ffffff">
    <script src="/app/js/session_lifecycle.js?v=<?= filemtime('app/js/session_lifecycle.js'); ?>"></script>
    <script defer src="/app/js/portrait_only.js?v=<?= filemtime('app/js/portrait_only.js'); ?>"></script>
  </head>

  <body class="sb-nav-fixed<?php echo $openapActiveArea === 'iptv' ? ' openap-area-iptv' : ''; ?><?php echo $openapIsLoginPage ? ' openap-login-page' : ''; ?><?php echo ($openapIsLoginPage && !empty($status)) ? ' openap-login-error' : ''; ?>">
    <dialog id="openapPortraitNotice" aria-labelledby="openapPortraitTitle" aria-describedby="openapPortraitDescription">
      <div class="openap-portrait-message">
        <i class="fas fa-mobile-alt" aria-hidden="true"></i>
        <h2 id="openapPortraitTitle"><?php echo _("Rotate your device"); ?></h2>
        <p id="openapPortraitDescription"><?php echo _("Use portrait orientation to continue."); ?></p>
      </div>
    </dialog>
    <!-- Navbar -->
    <?php ob_start(); ?>
    <?php require_once 'includes/navbar.php'; ?>
    <!-- End of Navbar -->
    <div id="layoutSidenav">
      <div id="layoutSidenav_nav">
        <nav class="sb-sidenav accordion sb-sidenav-light<?php echo $openapActiveArea === 'iptv' ? ' openap-iptv-sidenav' : ''; ?>" id="sidenavAccordion">
          <div class="sb-sidenav-menu">
            <div class="nav">
              <!-- Sidebar -->
              <?php if ($openapActiveArea === 'iptv'): ?>
                <div class="openap-iptv-side-actions" aria-label="<?php echo _("APtv navigation"); ?>">
                  <span class="openap-iptv-section-indicator" aria-hidden="true"></span>
                  <button class="openap-iptv-side-link" type="button" title="<?php echo _("Live Stream"); ?>" aria-label="<?php echo _("Live Stream"); ?>" aria-controls="openapIptvLiveDrawer" aria-expanded="false" data-openap-iptv-live-toggle>
                    <i class="fas fa-tv" aria-hidden="true"></i>
                  </button>
                  <button class="openap-iptv-side-link" type="button" title="<?php echo _("Movies"); ?>" aria-label="<?php echo _("Movies"); ?>" aria-controls="openapIptvMoviesDrawer" aria-expanded="false" data-openap-iptv-movies-toggle>
                    <i class="fas fa-film" aria-hidden="true"></i>
                  </button>
                  <button class="openap-iptv-side-link" type="button" title="<?php echo _("TV Series"); ?>" aria-label="<?php echo _("TV Series"); ?>" aria-controls="openapIptvMoviesDrawer" aria-expanded="false" data-openap-iptv-series-toggle>
                    <i class="fas fa-photo-video" aria-hidden="true"></i>
                  </button>
                  <button class="openap-iptv-side-link" type="button" title="<?php echo _("Recordings"); ?>" aria-label="<?php echo _("Recordings"); ?>" aria-controls="openapIptvRecordingsView" aria-expanded="false" data-openap-recordings-toggle>
                    <i class="fas fa-record-vinyl" aria-hidden="true"></i>
                  </button>
                  <button class="openap-iptv-side-link" type="button" title="<?php echo _("APtv settings"); ?>" aria-label="<?php echo _("APtv settings"); ?>" aria-controls="openapIptvSettingsDrawer" aria-expanded="false" data-openap-iptv-settings-toggle>
                    <i class="fas fa-cog" aria-hidden="true"></i>
                  </button>
                </div>
              <?php else: ?>
                <?php require_once 'includes/sidebar.php'; ?>
              <?php endif; ?>
              <!-- End of Sidebar -->
            </div>
          </div>
        </nav>
      </div>
      <div id="layoutSidenav_content">
        <main>
          <div class="container-fluid mt-2">
            <?php require_once 'includes/page_actions.php'; ?>
          </div>
        </main>
        <footer class="py-4 mt-auto openap-global-footer">
          <div class="container-fluid px-4">
            <?php require_once 'includes/footer.php'; ?>
          </div>
        </footer>
      </div>
    </div>
    <?php ob_end_flush(); ?>
    <!-- jQuery -->
    <script src="dist/jquery/jquery.min.js?v=<?= filemtime('dist/jquery/jquery.min.js'); ?>"></script>

    <!-- Bootstrap Core JavaScript -->
    <script src="dist/bootstrap/js/bootstrap.bundle.min.js?v=<?= filemtime('dist/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>

    <!-- SB-Admin JavaScript -->
    <script src="dist/sb-admin/js/scripts.js?v=<?= filemtime('dist/sb-admin/js/scripts.js'); ?>"></script>

    <!-- jQuery Mask plugin -->
    <script src="dist/jquery-mask/jquery.mask.min.js?v=<?= filemtime('dist/jquery-mask/jquery.mask.min.js'); ?>"></script>

    <script type="module" src="app/js/app.js?v=<?= filemtime('app/js/app.js'); ?>"></script>

    <?php loadFooterScripts($extraFooterScripts); ?>
  </body>
</html>
