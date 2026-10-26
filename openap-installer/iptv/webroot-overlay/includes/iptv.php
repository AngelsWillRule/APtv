<?php

function DisplayIptv(): void
{
    $service = openapIptvCatalogServiceStatus();
    $providerCollection = openapAptvProviderCollection();
    $providerProfiles = array_values(array_filter(
        is_array($providerCollection['profiles'] ?? null) ? $providerCollection['profiles'] : [],
        static fn (mixed $profile): bool => is_array($profile)
    ));
    $activeProviderId = (string) ($providerCollection['active_id'] ?? '');
    $activeProviderProfile = null;
    foreach ($providerProfiles as $profile) {
        if (hash_equals($activeProviderId, (string) ($profile['id'] ?? ''))) {
            $activeProviderProfile = $profile;
            break;
        }
    }
    $hasActiveProvider = $activeProviderId !== '' && is_array($providerCollection['profiles'] ?? null)
        && array_reduce($providerCollection['profiles'], static function (bool $found, mixed $profile) use ($activeProviderId): bool {
            return $found || (is_array($profile) && hash_equals($activeProviderId, (string) ($profile['id'] ?? '')));
        }, false);
    $activeMaxConnections = max(1, (int) ($activeProviderProfile['max_connections'] ?? $activeProviderProfile['max_conn'] ?? 1));
    $wallAvailable = $hasActiveProvider && (count($providerProfiles) > 1 || $activeMaxConnections > 1);
    ?>
    <div class="openap-iptv-page<?php echo $hasActiveProvider ? '' : ' is-no-provider'; ?>">
      <section class="openap-iptv-no-provider" data-openap-no-provider<?php echo $hasActiveProvider ? ' hidden' : ''; ?> aria-live="polite">
        <div class="openap-iptv-no-provider-shell">
          <div class="openap-iptv-no-provider-prompt"><?php echo _("Add an IPTV provider"); ?></div>
          <button type="button" class="openap-iptv-no-provider-action" data-openap-no-provider-toggle aria-expanded="false" aria-controls="openapIptvNoProviderOptions">
            <span class="openap-iptv-no-provider-plus" aria-hidden="true">+</span>
            <span class="visually-hidden"><?php echo _("Add provider"); ?></span>
          </button>
          <div class="openap-iptv-no-provider-options" id="openapIptvNoProviderOptions" data-openap-no-provider-options hidden>
            <button type="button" class="openap-iptv-no-provider-option" data-bs-toggle="modal" data-bs-target="#openapIptvAddProviderModal">
              <i class="fas fa-plus-circle" aria-hidden="true"></i><span><?php echo _("Add Provider"); ?></span>
            </button>
            <button type="button" class="openap-iptv-no-provider-option" data-openap-no-provider-backup>
              <i class="fas fa-file-import" aria-hidden="true"></i><span><?php echo _("Add from backup file"); ?></span>
            </button>
          </div>
        </div>
      </section>
      <button class="openap-iptv-mobile-search-trigger" type="button" title="<?php echo _("Global search"); ?>" aria-label="<?php echo _("Global search"); ?>" aria-controls="openapIptvGlobalSearch" aria-expanded="false" data-openap-global-search-toggle><i class="fas fa-search" aria-hidden="true"></i></button>
      <div class="openap-iptv-date" aria-live="off">
        <time class="openap-iptv-time" id="openapIptvTime">--:--</time>
        <time class="openap-iptv-day" id="openapIptvDay">--</time>
        <time class="openap-iptv-full-date" id="openapIptvDate">--</time>
      </div>

      <nav class="openap-iptv-primary-actions<?php echo $wallAvailable ? '' : ' is-wall-unavailable'; ?>" aria-label="<?php echo _("APtv main actions"); ?>">
        <button class="openap-iptv-primary-action is-active" type="button" aria-controls="openapIptvLiveDrawer" aria-expanded="false" data-openap-iptv-live-toggle>
          <span class="openap-iptv-primary-icon"><i class="fas fa-play" aria-hidden="true"></i></span>
          <span><?php echo _("Live Stream"); ?></span>
        </button>
        <div class="openap-wall-launcher-wrap"<?php echo $wallAvailable ? '' : ' hidden data-openap-wall-deferred'; ?>>
          <button class="openap-iptv-primary-action" type="button" data-openap-wall-toggle aria-controls="openapAptvWall" aria-expanded="false">
            <span class="openap-iptv-primary-icon openap-wall-launcher-icon"><span data-openap-wall-launcher-cell></span><span data-openap-wall-launcher-cell></span><span data-openap-wall-launcher-cell></span><span data-openap-wall-launcher-cell></span></span>
            <span><?php echo _("Wall"); ?></span>
          </button>
          <button class="openap-wall-launcher-clean" data-openap-wall-launcher-clean type="button" title="<?php echo htmlspecialchars(_('Clean wall'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars(_('Clean wall'), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-broom" aria-hidden="true"></i></button>
        </div>
      </nav>
      <?php if ($hasActiveProvider): ?>
      <div class="openap-provider-switch<?php echo count($providerProfiles) > 1 ? ' is-provider-switch-visible' : ''; ?>" data-openap-provider-switch<?php echo $wallAvailable ? '' : ' hidden'; ?> aria-label="<?php echo htmlspecialchars(_('Provider switcher'), ENT_QUOTES, 'UTF-8'); ?>">
        <span class="openap-provider-switch-label"><i class="fas fa-exchange-alt" aria-hidden="true"></i> <?php echo _('Provider'); ?></span>
        <select data-openap-provider-switch-select aria-label="<?php echo htmlspecialchars(_('Select provider'), ENT_QUOTES, 'UTF-8'); ?>">
          <?php foreach ($providerProfiles as $profile): $profileId=(string)($profile['id']??''); if($profileId==='')continue; ?><option value="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>"<?php echo hash_equals($activeProviderId,$profileId)?' selected':''; ?>><?php echo htmlspecialchars((string)($profile['name']??$profileId), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <nav class="openap-iptv-bottom-actions" aria-label="<?php echo _("APtv library actions"); ?>">
        <button class="openap-iptv-bottom-action" type="button" data-openap-global-search-toggle aria-controls="openapIptvGlobalSearch" aria-expanded="false">
          <i class="fas fa-search" aria-hidden="true"></i>
          <span><?php echo _("Search"); ?></span>
        </button>
        <button class="openap-iptv-bottom-action" type="button" data-openap-iptv-movies-toggle aria-controls="openapIptvMoviesDrawer" aria-expanded="false">
          <i class="fas fa-film" aria-hidden="true"></i>
          <span><?php echo _("Movies"); ?></span>
        </button>
        <button class="openap-iptv-bottom-action" type="button" data-openap-iptv-series-toggle aria-expanded="false">
          <i class="fas fa-photo-video" aria-hidden="true"></i>
          <span><?php echo _("TV Series"); ?></span>
        </button>
        <button class="openap-iptv-bottom-action" id="settings" type="button" aria-controls="openapIptvSettingsDrawer" aria-expanded="false" data-openap-iptv-settings-toggle>
          <i class="fas fa-cog" aria-hidden="true"></i>
          <span><?php echo _("Settings"); ?></span>
        </button>
      </nav>

      <div class="openap-iptv-expiration">
        <div><span><?php echo _("Expiration"); ?>:</span> <strong data-openap-service-expiration data-expiration-timestamp="<?php echo (int) $service['expiration']; ?>"><?php echo htmlspecialchars($service['expiration_label']); ?></strong></div>
        <div class="openap-iptv-network-meta">
          <span data-openap-service-ip><?php echo htmlspecialchars($service['public_ip'] ?: '--'); ?></span>
        </div>
      </div>

      <section class="openap-aptv-player" data-openap-aptv-player hidden aria-label="<?php echo _("APtv player"); ?>">
        <video data-openap-aptv-video playsinline></video>
        <audio data-openap-aptv-hybrid-audio hidden></audio>
        <iframe class="openap-aptv-webrtc" data-openap-aptv-webrtc title="<?php echo _("APtv WebRTC player"); ?>" allow="autoplay; fullscreen" hidden></iframe>
        <div class="openap-aptv-player-shade"></div>
        <div class="openap-aptv-player-feedback" data-openap-aptv-player-feedback hidden aria-live="polite">
          <div class="openap-aptv-player-feedback-card">
            <button type="button" class="openap-aptv-player-feedback-play" data-openap-aptv-feedback-play hidden aria-label="<?php echo _("Resume playback"); ?>"><i class="fas fa-play" aria-hidden="true"></i></button>
            <span data-openap-aptv-feedback-label><?php echo _("Seeking…"); ?></span>
          </div>
        </div>
        <header class="openap-aptv-player-header">
          <div class="openap-aptv-player-channel">
            <img data-openap-aptv-channel-logo alt="" hidden>
            <div><small><?php echo _("Now playing"); ?></small><strong data-openap-aptv-channel-name>--</strong></div>
          </div>
          <button type="button" data-openap-aptv-player-close aria-label="<?php echo _("Close player"); ?>"><i class="fas fa-times"></i></button>
        </header>
        <div class="openap-aptv-player-state" data-openap-aptv-player-state><i class="fas fa-circle-notch fa-spin"></i><span><?php echo _("Connecting to channel…"); ?></span></div>
        <aside class="openap-aptv-player-epg" data-openap-player-epg hidden aria-label="<?php echo _("Channel guide"); ?>">
          <header><div><small><?php echo _("Channel guide"); ?></small><strong data-openap-player-epg-channel>--</strong></div></header>
          <div class="openap-aptv-player-epg-list" data-openap-player-epg-list></div>
        </aside>
        <div class="openap-aptv-player-hud" data-openap-player-hud hidden aria-live="polite">
          <span data-openap-player-quality hidden></span>
          <span data-openap-player-resolution hidden></span>
          <span data-openap-player-video-codec hidden></span>
          <span data-openap-player-audio-codec hidden></span>
          <span data-openap-player-framerate hidden></span>
        </div>
        <aside class="openap-aptv-player-epg openap-aptv-player-diagnostics" data-openap-player-diagnostics hidden aria-label="<?php echo _("Video diagnostics"); ?>" style="width:min(560px,78%);">
          <header><div><small><?php echo _("Video diagnostics"); ?></small><strong style="font-size:16px;" data-openap-player-diagnostics-channel>--</strong></div><span class="openap-aptv-diagnostics-header-actions"><button type="button" class="btn btn-sm btn-outline-light" data-openap-player-diagnostics-copy><i class="fas fa-copy" aria-hidden="true"></i> <?php echo _("Copy"); ?></button><small data-openap-player-diagnostics-copy-status></small><button type="button" data-openap-player-diagnostics-close aria-label="<?php echo _("Close diagnostics"); ?>"><i class="fas fa-times"></i></button></span></header>
          <div class="openap-aptv-player-epg-list" data-openap-player-diagnostics-list style="font-size:10px;line-height:1.25;padding-top:4px;">
            <div class="openap-aptv-diagnostic-section"><span>Playback</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>State</time><strong data-openap-diagnostic-state>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Profile</time><strong data-openap-diagnostic-profile>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Last event</time><strong class="text-break" data-openap-diagnostic-event>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Content / position</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Content</time><strong data-openap-diagnostic-content>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-vod hidden><div><time>Season / episode</time><strong data-openap-diagnostic-season-episode>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-vod hidden><div><time>Position / duration</time><strong data-openap-diagnostic-position>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-vod hidden><div><time>Progress</time><strong data-openap-diagnostic-progress>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-vod hidden><div><time>Seek</time><strong data-openap-diagnostic-seek>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Playback method</time><strong data-openap-diagnostic-method>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Audio track</time><strong data-openap-diagnostic-audio-track>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Subtitles</time><strong data-openap-diagnostic-subtitles>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Video / audio</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Video</time><strong data-openap-diagnostic-video>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Audio</time><strong data-openap-diagnostic-audio>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Resolution / FPS</time><strong><span data-openap-diagnostic-resolution>--</span> · <span data-openap-diagnostic-framerate>--</span></strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Decoded / dropped frames</time><strong data-openap-diagnostic-frames>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Presentation stalls</time><strong data-openap-diagnostic-presentation-stalls>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Buffer &amp; live</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Immediate buffer</time><strong data-openap-diagnostic-buffer>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Buffer range</time><strong data-openap-diagnostic-buffer-range>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Buffer trend (30 s)</time><strong data-openap-diagnostic-buffer-trend>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Live latency</time><strong data-openap-diagnostic-latency>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Rebuffers</time><strong data-openap-diagnostic-rebuffers>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Streaming / network</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>HLS playlist</time><strong data-openap-diagnostic-playlist>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Target duration</time><strong data-openap-diagnostic-target-duration>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Last segment</time><strong data-openap-diagnostic-segment>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Estimated bandwidth</time><strong data-openap-diagnostic-bandwidth>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS state</time><strong class="text-break" data-openap-diagnostic-mpegts-state>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS transfer</time><strong class="text-break" data-openap-diagnostic-mpegts-transfer>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS last data</time><strong class="text-break" data-openap-diagnostic-mpegts-last-data>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS demux / decoder</time><strong class="text-break" data-openap-diagnostic-mpegts-demux>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS error</time><strong class="text-break" data-openap-diagnostic-mpegts-error>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-mpegts hidden><div><time>MPEG-TS buffer</time><strong class="text-break" data-openap-diagnostic-mpegts-buffer>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Source bitrate</time><strong data-openap-diagnostic-source-bitrate>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Progressive transfer</time><strong data-openap-diagnostic-progressive-transfer>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Transfer (10 s)</time><strong data-openap-diagnostic-progressive-recent>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Minimum transfer (60 s)</time><strong data-openap-diagnostic-progressive-minimum>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Transfer margin (10 s)</time><strong data-openap-diagnostic-transfer-margin>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Minimum margin (60 s)</time><strong data-openap-diagnostic-minimum-margin>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Relay last output</time><strong data-openap-diagnostic-relay-last-output>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>Relay output gap</time><strong data-openap-diagnostic-relay-gap>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>FFmpeg state</time><strong class="text-break" data-openap-diagnostic-ffmpeg-state>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-progressive hidden><div><time>MSE append</time><strong class="text-break" data-openap-diagnostic-mse-append>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>OTT test</time><strong class="text-break" data-openap-diagnostic-ott-profile>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>Append mode</time><strong class="text-break" data-openap-diagnostic-ott-mode>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>Fragments appended</time><strong class="text-break" data-openap-diagnostic-ott-fragments>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>OTT buffer cap</time><strong data-openap-diagnostic-ott-buffer>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>Pause restart</time><strong class="text-break" data-openap-diagnostic-ott-pause>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>VPS cache</time><strong class="text-break" data-openap-diagnostic-ott-cache>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>Cache timeline</time><strong class="text-break" data-openap-diagnostic-ott-timeline>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>Relay served</time><strong class="text-break" data-openap-diagnostic-ott-served>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>MSE fragment</time><strong class="text-break" data-openap-diagnostic-ott-appended>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>MSE rejected</time><strong class="text-break" data-openap-diagnostic-ott-rejected>--</strong></div></div>
            <div class="openap-aptv-player-epg-item" data-openap-diagnostic-ott hidden><div><time>MSE intervals</time><strong class="text-break" data-openap-diagnostic-ott-intervals>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Provider / upstream</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider status</time><strong data-openap-diagnostic-provider-status>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider I/O</time><strong class="text-break" data-openap-diagnostic-provider-io>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider range</time><strong class="text-break" data-openap-diagnostic-provider-range>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider bytes</time><strong class="text-break" data-openap-diagnostic-provider-bytes>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider last byte</time><strong class="text-break" data-openap-diagnostic-provider-last-byte>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider heartbeat</time><strong class="text-break" data-openap-diagnostic-provider-heartbeat>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Provider error</time><strong class="text-break" data-openap-diagnostic-provider-upstream-error>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Player / HLS message</time><strong class="text-break" data-openap-diagnostic-provider-error>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Retries</time><strong data-openap-diagnostic-retries>0</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>MSE / recovery</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>MSE error</time><strong class="text-break" data-openap-diagnostic-mse-error>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>MediaSource state</time><strong data-openap-diagnostic-mse-state>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>SourceBuffers</time><strong data-openap-diagnostic-mse-source-buffers>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Playback watchdog</time><strong class="text-break" data-openap-diagnostic-watchdog>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Recovery action</time><strong class="text-break" data-openap-diagnostic-recovery>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Channel identity</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Country</time><strong data-openap-diagnostic-country>--</strong></div></div>
            <div class="openap-aptv-player-epg-item"><div><time>Group</time><strong data-openap-diagnostic-group>--</strong></div></div>
            <div class="openap-aptv-diagnostic-section"><span>Recent events</span></div>
            <div class="openap-aptv-player-epg-item"><div><time>Timeline</time><strong class="text-break" data-openap-diagnostic-events>--</strong></div></div>
          </div>
        </aside>
        <div class="openap-aptv-player-controls" data-openap-aptv-controls hidden>
          <div class="openap-aptv-player-progress" data-openap-vod-only>
            <span data-openap-vod-current>00:00</span>
            <div class="openap-aptv-vod-seek-wrap" style="position:relative;flex:1 1 auto;min-width:0;">
              <span class="openap-aptv-vod-cache-track" data-openap-vod-cache-track aria-hidden="true"><span data-openap-vod-cache></span><span data-openap-vod-buffered></span></span>
              <output data-openap-vod-seek-preview hidden aria-hidden="true"></output>
              <input type="range" min="0" max="100" value="0" step="1" data-openap-vod-seek aria-label="<?php echo _("Movie position"); ?>" style="width:100%;">
            </div>
            <span data-openap-vod-duration>--:--</span>
          </div>
          <div class="openap-aptv-live-buffer" data-openap-live-only aria-label="<?php echo _("Live stream buffer"); ?>">
            <div><span data-openap-live-buffer-fill></span></div>
            <small><span data-openap-live-buffer-label><?php echo _("Buffer"); ?></span> <b data-openap-live-buffer-value>0.0 s</b></small>
          </div>
          <div class="openap-aptv-player-primary">
            <button type="button" data-openap-player-play aria-label="<?php echo _("Play or pause"); ?>"><i class="fas fa-pause"></i></button>
            <button type="button" data-openap-vod-only data-openap-vod-skip="-10" aria-label="<?php echo _("Back 10 seconds"); ?>"><i class="fas fa-undo-alt"></i><span>10</span></button>
            <button type="button" data-openap-vod-only data-openap-vod-skip="10" aria-label="<?php echo _("Forward 10 seconds"); ?>"><i class="fas fa-redo-alt"></i><span>10</span></button>
            <button type="button" data-openap-player-mute aria-label="<?php echo _("Mute"); ?>"><i class="fas fa-volume-up"></i></button>
            <input class="openap-aptv-volume" type="range" min="0" max="1" value="1" step="0.05" data-openap-player-volume aria-label="<?php echo _("Volume"); ?>">
            <button type="button" class="openap-aptv-player-more" data-openap-player-more aria-expanded="false" aria-label="<?php echo _("More"); ?>" title="<?php echo _("More"); ?>"><i class="fas fa-ellipsis-h"></i></button>
            <button type="button" data-openap-player-fullscreen aria-label="<?php echo _("Full screen"); ?>"><i class="fas fa-expand"></i></button>
          </div>
          <div class="openap-aptv-player-secondary" data-openap-player-secondary aria-label="<?php echo _("More player controls"); ?>">
            <button type="button" data-openap-vod-only data-openap-subtitles disabled title="<?php echo _("Subtitles are ready for a future source"); ?>"><i class="fas fa-closed-captioning"></i><span><?php echo _("Subtitles"); ?></span></button>
            <button type="button" data-openap-live-only data-openap-player-epg-toggle aria-label="<?php echo _("Channel guide"); ?>" title="<?php echo _("Channel guide"); ?>"><i class="fas fa-list-alt"></i><span><?php echo _("EPG"); ?></span></button>
            <button type="button" data-openap-player-diagnostics-toggle aria-label="<?php echo _("Video diagnostics"); ?>" title="<?php echo _("Video diagnostics"); ?>" hidden><i class="fas fa-stethoscope"></i><span><?php echo _("Diagnostics"); ?></span></button>
          </div>
        </div>
      </section>


      <section class="openap-aptv-recordings-view" id="openapIptvRecordingsView" data-openap-recordings-view hidden aria-label="<?php echo _("Recordings"); ?>">
        <header class="openap-aptv-recordings-header">
          <div><small><?php echo _("APtv"); ?></small><h2><?php echo _("Recordings"); ?></h2><p><?php echo _("Browse recorded videos by storage destination."); ?></p></div>
        </header>
        <div class="openap-aptv-recordings-folders">
          <button type="button" class="openap-aptv-recordings-folder" data-openap-recording-folder="local"><i class="fas fa-folder-open" aria-hidden="true"></i><strong><?php echo _("Local"); ?></strong><small data-openap-recording-folder-path="local"><?php echo _("Loading destination…"); ?></small></button>
          <button type="button" class="openap-aptv-recordings-folder" data-openap-recording-folder="nfs"><i class="fas fa-folder-open" aria-hidden="true"></i><strong><?php echo _("NFS partition"); ?></strong><small data-openap-recording-folder-path="nfs"><?php echo _("Loading destination…"); ?></small></button>
        </div>
        <section class="openap-aptv-recording-schedule" data-openap-recording-schedule aria-labelledby="openapRecordingScheduleTitle">
          <header class="openap-aptv-recording-schedule-header">
            <div><small><?php echo _("APtv"); ?></small><h3 id="openapRecordingScheduleTitle"><?php echo _("Scheduled recordings"); ?></h3><p><?php echo _("Scheduled and active recordings."); ?></p></div>
            <span class="openap-aptv-recording-schedule-icon" aria-hidden="true"><i class="fas fa-calendar-check"></i></span>
          </header>
          <div class="openap-aptv-recording-schedule-list" data-openap-recording-schedule-list role="list" aria-live="polite"></div>
        </section>
        <aside class="openap-aptv-recordings-browser" data-openap-recordings-browser hidden aria-label="<?php echo _("Recording files"); ?>" aria-busy="false">
          <header>
            <div><small data-openap-recordings-browser-kicker><?php echo _("Recordings"); ?></small><h3 data-openap-recordings-browser-title></h3><p data-openap-recordings-browser-path></p></div>
            <div class="openap-aptv-recordings-browser-actions"><button type="button" data-openap-recordings-browser-back aria-label="<?php echo _("Go to parent folder"); ?>" hidden><i class="fas fa-arrow-left" aria-hidden="true"></i></button><button type="button" data-openap-recordings-watch hidden><?php echo _("Watch"); ?></button><button type="button" data-openap-recordings-delete hidden><?php echo _("Delete"); ?></button></div><button type="button" data-openap-recordings-browser-close aria-label="<?php echo _("Close file list"); ?>"><i class="fas fa-times" aria-hidden="true"></i></button>
          </header>
          <p class="openap-aptv-recordings-browser-status" data-openap-recordings-browser-status role="status" aria-live="polite"></p>
          <div class="openap-aptv-recordings-file-list" data-openap-recordings-file-list></div>
        </aside>
      </section>

      <section class="openap-aptv-global-search-view" id="openapIptvGlobalSearch" data-openap-global-search-view hidden aria-label="<?php echo _("Global search"); ?>">
        <aside class="openap-aptv-global-search-browser" data-openap-global-search-browser aria-busy="false">
          <header>
            <div><small><?php echo _("APtv library"); ?></small><h2><?php echo _("Search everything"); ?></h2><p><?php echo _("Channels, movies and TV series in one catalog."); ?></p></div>
            <button type="button" data-openap-global-search-close aria-label="<?php echo _("Close search"); ?>"><i class="fas fa-times" aria-hidden="true"></i></button>
          </header>
          <form class="openap-aptv-global-search-form" data-openap-global-search-form>
            <label><i class="fas fa-search" aria-hidden="true"></i><input type="search" data-openap-global-search-input placeholder="<?php echo _("Type at least two characters"); ?>" autocomplete="off" maxlength="100"></label>
            <div class="openap-aptv-global-search-filters" aria-label="<?php echo _("Search filters"); ?>">
              <fieldset><legend><?php echo _("Content"); ?></legend><label><input type="radio" name="aptvGlobalSearchKind" value="all" checked> <?php echo _("All"); ?></label><label><input type="radio" name="aptvGlobalSearchKind" value="live"> <?php echo _("Channels"); ?></label><label><input type="radio" name="aptvGlobalSearchKind" value="vod"> <?php echo _("Movies"); ?></label><label><input type="radio" name="aptvGlobalSearchKind" value="series"> <?php echo _("TV Series"); ?></label></fieldset>
              <label class="openap-aptv-global-search-sort"><span><?php echo _("Order"); ?></span><select data-openap-global-search-sort><option value="relevance"><?php echo _("Best match"); ?></option><option value="title"><?php echo _("Title"); ?></option><option value="recent"><?php echo _("Recently added"); ?></option></select></label>
            </div>
          </form>
          <p class="openap-aptv-global-search-status" data-openap-global-search-status role="status" aria-live="polite"><?php echo _("Start typing to search the whole catalog."); ?></p>
          <div class="openap-aptv-global-search-results" data-openap-global-search-results></div>
        </aside>
      </section>

      <section class="openap-aptv-epg" data-openap-epg hidden aria-label="<?php echo _("TV Guide"); ?>">
        <header class="openap-aptv-epg-header">
          <div><small><?php echo _("APtv"); ?></small><h2><?php echo _("TV Guide"); ?></h2></div>
          <label><span><?php echo _("Country"); ?></span><select data-openap-epg-country><option value="IT">🇮🇹 <?php echo _("Italy"); ?></option></select></label>
          <div class="openap-aptv-epg-actions"><button type="button" data-openap-epg-now><?php echo _("Now"); ?></button><button type="button" data-openap-epg-refresh aria-label="<?php echo _("Refresh guide"); ?>"><i class="fas fa-sync-alt"></i></button><button type="button" data-openap-epg-close aria-label="<?php echo _("Close TV Guide"); ?>"><i class="fas fa-times"></i></button></div>
        </header>
        <div class="openap-aptv-epg-timeline"><div><?php echo _("Channels"); ?></div><div data-openap-epg-hours></div></div>
        <div class="openap-aptv-epg-grid" data-openap-epg-grid><div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div></div>
        <aside class="openap-aptv-epg-detail" data-openap-epg-detail hidden><button type="button" data-openap-epg-detail-close aria-label="<?php echo _("Close details"); ?>"><i class="fas fa-times"></i></button><small data-openap-epg-detail-time></small><h3 data-openap-epg-detail-title></h3><p data-openap-epg-detail-description></p><button type="button" data-openap-epg-watch><i class="fas fa-play"></i> <?php echo _("Watch now"); ?></button></aside>
      </section>

      <section class="openap-aptv-wall" id="openapAptvWall" data-openap-wall hidden aria-label="<?php echo _("Live Wall"); ?>">
        <header class="openap-aptv-wall-header"><div><small><?php echo _("APtv"); ?></small><h2><?php echo _("Live Wall"); ?></h2><p><?php echo _("Watch up to four live streams at once."); ?></p><p class="openap-aptv-wall-status" data-openap-wall-status><?php echo _("No live streams selected. Search for a channel in a panel to start playback."); ?></p></div><button type="button" data-openap-wall-close aria-label="<?php echo _("Close Wall"); ?>"><i class="fas fa-times" aria-hidden="true"></i></button></header>
        <div class="openap-iptv-wall-play-controls" data-openap-wall-play-controls>
          <button type="button" class="openap-aptv-wall-add" data-openap-wall-add aria-label="<?php echo _("Add a stream"); ?>" title="<?php echo _("Open live channels"); ?>"><i class="fas fa-plus" aria-hidden="true"></i></button>
          <button type="button" class="openap-aptv-wall-play-stream" data-openap-wall-play-stream aria-label="<?php echo _("Play stream"); ?>" title="<?php echo _("Play stream"); ?>" hidden><i class="fas fa-play" aria-hidden="true"></i></button>
          <button type="button" class="openap-iptv-wall-clean" data-openap-wall-clean aria-label="<?php echo _("Clean wall"); ?>" title="<?php echo _("Clean wall"); ?>" hidden><i class="fas fa-eraser" aria-hidden="true"></i></button>
        </div>
        <div class="openap-aptv-wall-grid">
          <?php for($wallSlot=0;$wallSlot<4;$wallSlot++): ?><article class="openap-aptv-wall-tile" data-openap-wall-tile><div class="openap-aptv-wall-toolbar"><strong data-openap-wall-title></strong><button type="button" data-openap-wall-mute aria-label="<?php echo _("Mute or unmute"); ?>"><i class="fas fa-volume-mute" aria-hidden="true"></i></button><button type="button" data-openap-wall-fullscreen aria-label="<?php echo _("Full screen"); ?>" title="<?php echo _("Full screen"); ?>"><i class="fas fa-expand" aria-hidden="true"></i></button><button type="button" data-openap-wall-close-tile aria-label="<?php echo _("Close stream"); ?>"><i class="fas fa-times"></i></button></div><video playsinline muted data-openap-wall-video></video><div class="openap-aptv-wall-empty" data-openap-wall-empty><span class="openap-aptv-wall-empty-message"><?php echo _("Select a channel to start this panel."); ?></span><span class="openap-aptv-wall-channel-info" data-openap-wall-channel-info hidden><span class="openap-aptv-wall-channel-icon" data-openap-wall-channel-icon></span><strong data-openap-wall-channel-name></strong></span></div></article><?php endfor; ?>
        </div>
      </section>

      <section class="openap-iptv-movies-view" data-openap-iptv-movies-view hidden aria-label="<?php echo _("Movies"); ?>">
        <section class="openap-iptv-movie-detail" data-openap-movie-detail>
          <div class="openap-iptv-movie-detail-backdrop" data-openap-movie-backdrop></div>
          <div class="openap-iptv-movie-detail-copy">
            <small data-openap-library-kicker><?php echo _("Movies"); ?></small>
            <label class="openap-iptv-hero-search">
              <button type="button" class="openap-iptv-hero-search-toggle" data-openap-movie-search-toggle aria-label="<?php echo _("Open or close search"); ?>" aria-expanded="false"><i class="fas fa-search" aria-hidden="true"></i></button>
              <input type="search" data-openap-movie-search placeholder="<?php echo _("Search the entire movie catalog"); ?>" autocomplete="off">
            </label>
            <div class="openap-iptv-movie-narrative">
              <h2 data-openap-movie-title><?php echo _("Choose a movie"); ?></h2>
              <div class="openap-iptv-movie-meta" data-openap-movie-meta></div>
              <p data-openap-movie-description><?php echo _("Select a poster below to view the movie details."); ?></p>
            </div>
            <div class="openap-iptv-movie-play-options" data-openap-movie-play-options hidden>
              <button type="button" class="openap-iptv-movie-play" data-openap-movie-play><i class="fas fa-play"></i><span><?php echo _("Play"); ?></span></button>
              <button type="button" class="openap-iptv-movie-play is-secondary" data-openap-movie-play-danish hidden><i class="fas fa-play"></i><span><?php echo _("Play in Danish"); ?></span></button>
              <button type="button" class="openap-iptv-movie-play" data-openap-series-episodes hidden><i class="fas fa-list-ol"></i><span class="openap-iptv-series-episodes-desktop-label"><?php echo _("Seasons and episodes"); ?></span><span class="openap-iptv-series-episodes-mobile-label"><?php echo _("Episodes"); ?></span></button>
            </div>
          </div>
        </section>
        <button type="button" class="openap-iptv-catalog-expand" data-openap-movie-catalog-expand aria-expanded="false" aria-label="<?php echo htmlspecialchars(_("Expand catalog"), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(_("Expand catalog"), ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
        <section class="openap-iptv-movie-catalog">
          <header><button type="button" class="openap-iptv-series-catalog-back" data-openap-series-catalog-back aria-label="<?php echo _("Back to TV series posters"); ?>" title="<?php echo _("Back to TV series posters"); ?>" hidden><i class="fas fa-arrow-left" aria-hidden="true"></i></button><h3 data-openap-movies-heading><?php echo _("Choose a movie group"); ?></h3><select class="openap-iptv-season-select" data-openap-season-select aria-label="<?php echo _("Season"); ?>" hidden></select><span data-openap-movies-count></span></header>
          <div class="openap-iptv-movie-grid" data-openap-movie-grid></div>
        </section>
        <div class="openap-iptv-movie-loading" data-openap-movie-loading hidden><i class="fas fa-circle-notch fa-spin"></i></div>
      </section>

      <div class="openap-iptv-settings-backdrop" data-openap-iptv-settings-dismiss hidden></div>
      <aside class="openap-iptv-live-drawer" id="openapIptvLiveDrawer" aria-labelledby="openapIptvLiveTitle" aria-hidden="true">
        <button class="openap-iptv-mobile-collapse" type="button" aria-label="<?php echo _("Close Live Stream"); ?>" title="<?php echo _("Close Live Stream"); ?>" data-openap-iptv-live-collapse><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
        <header class="openap-iptv-settings-header">
          <button class="openap-iptv-settings-back" type="button" aria-label="<?php echo _("Close Live Stream"); ?>" data-openap-iptv-live-close><i class="fas fa-arrow-left" aria-hidden="true"></i></button>
          <strong class="openap-iptv-uppercase-title" id="openapIptvLiveTitle"><?php echo _("Live Stream"); ?></strong>
          <div class="openap-wall-picker-meta">
            <div class="openap-wall-picker-provider-switch" data-openap-wall-provider-switch<?php echo count($providerProfiles) > 1 ? '' : ' hidden'; ?> aria-label="<?php echo htmlspecialchars(_('Wall provider selector'), ENT_QUOTES, 'UTF-8'); ?>">
              <span class="openap-provider-switch-label"><i class="fas fa-exchange-alt" aria-hidden="true"></i> <?php echo _('Provider'); ?></span>
              <select id="openapWallProviderSwitchSelect" data-openap-wall-provider-switch-select aria-label="<?php echo htmlspecialchars(_('Select provider for Wall'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php foreach ($providerProfiles as $profile): $profileId=(string)($profile['id']??''); if($profileId==='')continue; ?><option value="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>"<?php echo hash_equals($activeProviderId,$profileId)?' selected':''; ?>><?php echo htmlspecialchars((string)($profile['name']??$profileId), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
              </select>
            </div>
            <span class="openap-wall-picker-capacity" data-openap-wall-capacity aria-live="polite">Connections: —</span>
          </div>
          <button type="button" class="openap-iptv-live-dismiss" data-openap-iptv-live-dismiss aria-label="<?php echo _("Close Live Stream"); ?>" title="<?php echo _("Close Live Stream"); ?>"><i class="fas fa-times" aria-hidden="true"></i></button>
          <div class="openap-iptv-live-edit-actions">
            <button type="button" class="openap-iptv-live-edit" data-openap-iptv-edit-order aria-label="<?php echo _("Edit order"); ?>" hidden><i class="fas fa-pen"></i></button>
            <button type="button" class="openap-iptv-live-selection-move" data-openap-iptv-move-top aria-label="<?php echo _("Move selected to top"); ?>" title="<?php echo _("Move selected to top"); ?>" hidden disabled><i class="fas fa-angle-double-up"></i></button>
            <button type="button" class="openap-iptv-live-selection-move" data-openap-iptv-move-bottom aria-label="<?php echo _("Move selected to bottom"); ?>" title="<?php echo _("Move selected to bottom"); ?>" hidden disabled><i class="fas fa-angle-double-down"></i></button>
            <button type="button" class="openap-iptv-live-edit-cancel" data-openap-iptv-edit-cancel hidden><?php echo _("Cancel"); ?></button>
            <button type="button" class="openap-iptv-live-edit-save" data-openap-iptv-edit-save hidden><?php echo _("Save"); ?></button>
          </div>
        </header>
        <div class="openap-iptv-live-toolbar">
          <i class="fas fa-search" aria-hidden="true"></i>
          <input type="search" placeholder="<?php echo _("Filter this list"); ?>" aria-label="<?php echo _("Filter this list"); ?>" data-openap-iptv-live-filter>
        </div>
        <div class="openap-iptv-live-list" data-openap-iptv-live-list aria-live="polite"></div>
      </aside>
      <aside class="openap-iptv-live-drawer openap-iptv-movies-drawer" id="openapIptvMoviesDrawer" aria-labelledby="openapIptvMoviesTitle" aria-hidden="true">
        <button class="openap-iptv-mobile-collapse" type="button" aria-label="<?php echo _("Close Movies or TV Series"); ?>" title="<?php echo _("Close Movies or TV Series"); ?>" data-openap-movies-collapse><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
        <header class="openap-iptv-settings-header">
          <button class="openap-iptv-settings-back" type="button" aria-label="<?php echo _("Back"); ?>" data-openap-movies-back><i class="fas fa-arrow-left"></i></button>
          <strong class="openap-iptv-uppercase-title" id="openapIptvMoviesTitle"><?php echo _("Movies"); ?></strong>
          <div class="openap-iptv-live-edit-actions">
            <button type="button" class="openap-iptv-live-edit" data-openap-library-edit-order aria-label="<?php echo _("Edit order"); ?>"><i class="fas fa-pen"></i></button>
            <button type="button" class="openap-iptv-live-selection-move" data-openap-library-move-top aria-label="<?php echo _("Move selected to top"); ?>" title="<?php echo _("Move selected to top"); ?>" hidden disabled><i class="fas fa-angle-double-up"></i></button>
            <button type="button" class="openap-iptv-live-selection-move" data-openap-library-move-bottom aria-label="<?php echo _("Move selected to bottom"); ?>" title="<?php echo _("Move selected to bottom"); ?>" hidden disabled><i class="fas fa-angle-double-down"></i></button>
            <button type="button" class="openap-iptv-live-edit-cancel" data-openap-library-edit-cancel hidden><?php echo _("Cancel"); ?></button>
            <button type="button" class="openap-iptv-live-edit-save" data-openap-library-edit-save hidden><?php echo _("Save"); ?></button>
          </div>
        </header>
        <div class="openap-iptv-live-toolbar"><i class="fas fa-search"></i><input type="search" placeholder="<?php echo _("Filter this list"); ?>" data-openap-movies-filter></div>
        <div class="openap-iptv-live-list" data-openap-movies-list></div>
      </aside>
      <aside class="openap-iptv-settings-drawer" id="openapIptvSettingsDrawer" aria-labelledby="openapIptvSettingsTitle" aria-hidden="true">
        <header class="openap-iptv-settings-header">
          <button class="openap-iptv-settings-back" type="button" aria-label="<?php echo _("Close settings"); ?>" data-openap-iptv-settings-dismiss>
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
          </button>
          <strong id="openapIptvSettingsTitle"><?php echo _("Settings"); ?></strong>
        </header>
        <div class="openap-iptv-settings-panel is-active" data-openap-iptv-settings-panel="root">
        <nav class="openap-iptv-settings-menu" aria-label="<?php echo _("APtv settings"); ?>">
          <button class="openap-iptv-settings-item" type="button" data-openap-iptv-provider-menu>
            <i class="fas fa-satellite-dish" aria-hidden="true"></i>
            <span><?php echo _("Provider"); ?></span>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
          </button>
          <button class="openap-iptv-settings-item" type="button" data-openap-recording-menu>
            <i class="fas fa-record-vinyl" aria-hidden="true"></i><span><?php echo _("Recording"); ?></span><i class="fas fa-chevron-right" aria-hidden="true"></i>
          </button>
          <button class="openap-iptv-settings-item" type="button" data-openap-iptv-profile-menu>
            <i class="fas fa-file-export" aria-hidden="true"></i>
            <span><?php echo _("Backup / Restore"); ?></span>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
          </button>
        </nav>
        </div>
        <div class="openap-iptv-settings-panel" data-openap-iptv-settings-panel="provider" aria-hidden="true">
          <header class="openap-iptv-settings-header">
            <button class="openap-iptv-settings-back" type="button" aria-label="<?php echo _("Back to settings"); ?>" data-openap-iptv-provider-back>
              <i class="fas fa-arrow-left" aria-hidden="true"></i>
            </button>
            <strong><?php echo _("Provider"); ?></strong>
          </header>
          <nav class="openap-iptv-settings-menu" aria-label="<?php echo _("Provider actions"); ?>">
            <button class="openap-iptv-settings-item" type="button" data-bs-toggle="modal" data-bs-target="#openapIptvAddProviderModal">
              <i class="fas fa-plus-circle" aria-hidden="true"></i>
              <span><?php echo _("Add Provider"); ?></span>
              <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
            <button class="openap-iptv-settings-item" type="button" data-bs-toggle="modal" data-bs-target="#openapIptvEditProviderModal">
              <i class="fas fa-edit" aria-hidden="true"></i>
              <span><?php echo _("Edit Provider"); ?></span>
              <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
          </nav>
        </div>
      </aside>
    </div>

    <div class="modal fade openap-iptv-provider-modal" id="openapRecordingModal" tabindex="-1" aria-labelledby="openapRecordingTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content openap-ap-ethernet-modal">
          <div class="modal-header openap-ap-ethernet-header">
            <div>
              <div class="openap-ap-ethernet-title" id="openapRecordingTitle"><?php echo _("Recording"); ?></div>
              <div class="openap-ap-ethernet-subtitle"><?php echo _("Configure recording storage"); ?></div>
            </div>
            <div class="openap-ap-ethernet-header-actions">
              <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-record-vinyl"></i></span>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
            </div>
          </div>
          <div class="modal-body openap-ap-ethernet-body">
          <div class="openap-recording-options">
            <label for="aptvRecordingLocalPath"><?php echo _("Local recording folder"); ?></label>
            <input id="aptvRecordingLocalPath" type="text" value="/var/lib/openap-recordings" readonly aria-readonly="true">
            <p><?php echo _("The local recording folder is fixed on the APtv device."); ?></p>
            <div data-openap-recording-nfs>
              <h3 class="h6"><?php echo _("NFS storage"); ?></h3>
              <label for="aptvRecordingNfsServer"><?php echo _("NFS server"); ?></label>
              <input id="aptvRecordingNfsServer" type="text" placeholder="nas.local" autocomplete="off" spellcheck="false">
              <label for="aptvRecordingNfsPath"><?php echo _("Exported folder"); ?></label>
              <input id="aptvRecordingNfsPath" type="text" placeholder="/exports/recordings" autocomplete="off" spellcheck="false">
              <p data-recording-nfs-status role="status" aria-live="polite"></p>
              <div class="openap-recording-path-row"><button type="button" class="btn openap-recording-nfs-primary" data-recording-nfs-mount><span><?php echo _("Mount NFS storage"); ?></span><i class="fas fa-circle-notch fa-spin" data-recording-action-spinner hidden aria-hidden="true"></i></button></div>
            </div>
            <p data-recording-settings-status role="status" aria-live="polite"></p>
          </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times" aria-hidden="true"></i> <?php echo _("Cancel"); ?></button>
            <button type="button" class="btn btn-primary openap-recording-save" data-recording-save><i class="fas fa-save me-1" aria-hidden="true"></i><span><?php echo _("Save"); ?></span><i class="fas fa-circle-notch fa-spin" data-recording-action-spinner hidden aria-hidden="true"></i></button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade openap-iptv-profile-modal" id="openapIptvProfileModal" tabindex="-1" aria-labelledby="openapIptvProfileTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content openap-ap-ethernet-modal">
          <div class="modal-header openap-ap-ethernet-header">
            <div>
              <div class="openap-ap-ethernet-title" id="openapIptvProfileTitle"><?php echo _("Backup / Restore"); ?></div>
              <div class="openap-ap-ethernet-subtitle"><?php echo _("Manage APtv customization"); ?></div>
            </div>
            <div class="openap-ap-ethernet-header-actions">
              <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-file-export"></i></span>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
            </div>
          </div>
          <div class="modal-body openap-ap-ethernet-body">
            <div class="openap-iptv-profile-panel">
              <p><?php echo _("Export or import the provider, group, channel order and Favorites customization."); ?></p>
              <p class="openap-iptv-profile-warning"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <?php echo _("The exported file contains provider usernames and passwords. Store it securely and delete it when it is no longer needed."); ?></p>
              <div class="openap-iptv-profile-import-options">
                <label for="openapIptvProfileExportScope"><?php echo _("Backup scope"); ?></label>
                <select id="openapIptvProfileExportScope" data-openap-profile-export-scope>
                  <option value=""><?php echo _("All providers"); ?></option>
                  <?php foreach ($providerProfiles as $profile): $profileId=(string)($profile['id']??''); if($profileId==='')continue; ?><option value="<?php echo htmlspecialchars($profileId, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($profile['name']??$profileId), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                </select>
                <small><?php echo _("Choose whether the backup contains all providers or only one provider profile and its catalog customization."); ?></small>
              </div>
              <button class="openap-iptv-settings-item" type="button" data-openap-profile-export>
                <i class="fas fa-download" aria-hidden="true"></i><span><?php echo _("Create backup"); ?></span><i class="fas fa-chevron-right" aria-hidden="true"></i>
              </button>
              <div class="openap-iptv-profile-import-options">
                <label for="openapIptvProfileImportMode"><?php echo _("Import mode"); ?></label>
                <select id="openapIptvProfileImportMode" data-openap-profile-import-mode>
                  <option value="replace"><?php echo _("Replace current customization"); ?></option>
                  <option value="merge"><?php echo _("Merge imported customization"); ?></option>
                </select>
              </div>
              <input type="file" accept="application/json,.json" data-openap-profile-import-input hidden>
              <button class="openap-iptv-settings-item" type="button" data-openap-profile-choose>
                <i class="fas fa-upload" aria-hidden="true"></i><span data-openap-profile-file-name><?php echo _("Choose backup to restore"); ?></span><i class="fas fa-chevron-right" aria-hidden="true"></i>
              </button>
              <button class="openap-iptv-settings-item" type="button" data-openap-profile-import disabled>
                <i class="fas fa-file-import" aria-hidden="true"></i><span><?php echo _("Restore selected backup"); ?></span><i class="fas fa-chevron-right" aria-hidden="true"></i>
              </button>
              <button class="openap-iptv-settings-item openap-iptv-profile-reset" type="button" data-openap-profile-reset>
                <i class="fas fa-undo" aria-hidden="true"></i><span><?php echo _("Reset customization"); ?></span><i class="fas fa-chevron-right" aria-hidden="true"></i>
              </button>
              <p class="openap-iptv-profile-status" data-openap-profile-status role="status" aria-live="polite"></p>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times" aria-hidden="true"></i> <?php echo _("Cancel"); ?></button>
          </div>
        </div>
      </div>
    </div>

    <?php openapRenderIptvProviderModal('add'); ?>
    <?php openapRenderIptvProviderModal('edit'); ?>
    <div class="modal openap-iptv-fetch-modal" id="openapIptvFetchModal" tabindex="-1" aria-labelledby="openapIptvFetchHeading" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
              <div class="openap-iptv-provider-progress" data-openap-fetch-progress>
                <div class="openap-iptv-fetch-header">
                  <div class="openap-iptv-fetch-heading"><strong id="openapIptvFetchHeading"><?php echo _("FETCH PROVIDER"); ?></strong><small><?php echo _("Update your APtv catalog"); ?></small></div>
                  <div class="openap-iptv-fetch-icon"><i class="fas fa-download" aria-hidden="true"></i></div>
                  <button type="button" data-bs-dismiss="modal" aria-label="<?php echo _("Close; fetching continues in the background"); ?>" title="<?php echo _("Close; fetching continues in the background"); ?>">&times;</button>
                </div>
                <div class="openap-iptv-fetch-content">
                  <span id="openapIptvFetchTitle" role="status"><?php echo _("Fetching playlist…"); ?></span>
                  <progress max="11" value="0" aria-label="<?php echo _("Completed fetch phases"); ?>"></progress>
                  <div class="openap-iptv-fetch-terminal" data-fetch-terminal role="log" aria-label="<?php echo _("Provider fetch details"); ?>" aria-live="polite" tabindex="0"></div>
                </div>
                <div class="openap-iptv-fetch-footer"><strong>APtv <span> / <?php echo _("PROVIDER"); ?></span></strong><div><?php echo _("ELAPSED"); ?> <strong data-fetch-elapsed>0 s</strong></div></div>
              </div>
        </div>
      </div>
    </div>
    <script src="/dist/aptv/hls.min.js?v=1.6.16"></script>
    <script src="/dist/aptv/mpegts.min.js?v=1.8.0"></script>
    <script>
      (function () {
        const timeNode = document.getElementById('openapIptvTime');
        const dayNode = document.getElementById('openapIptvDay');
        const dateNode = document.getElementById('openapIptvDate');
        if (!timeNode || !dayNode || !dateNode) return;

        const updateClock = function () {
          const now = new Date();
          timeNode.textContent = new Intl.DateTimeFormat(undefined, {
            hour: '2-digit', minute: '2-digit'
          }).format(now);
          dayNode.textContent = new Intl.DateTimeFormat(undefined, {
            weekday: 'long'
          }).format(now);
          dateNode.textContent = new Intl.DateTimeFormat(undefined, {
            day: 'numeric', month: 'long', year: 'numeric'
          }).format(now);
          timeNode.dateTime = now.toISOString();
          dayNode.dateTime = now.toISOString();
          dateNode.dateTime = now.toISOString();
        };

        updateClock();
        window.setInterval(updateClock, 30000);
        const initialExpiration = document.querySelector('[data-openap-service-expiration]');
        const formatIptvExpiration=function(timestamp){const value=Number(timestamp)||0;return value>0?new Intl.DateTimeFormat(undefined,{day:'2-digit',month:'short',year:'numeric'}).format(new Date(value*1000)):'Not configured';};
        const updateIptvServiceExpiration=function(timestamp){if(!initialExpiration)return;const value=Number(timestamp)||0;initialExpiration.dataset.expirationTimestamp=String(value);initialExpiration.textContent=formatIptvExpiration(value);};
        const initialExpirationTimestamp = Number(initialExpiration?.dataset.expirationTimestamp || 0);
        if (initialExpiration) updateIptvServiceExpiration(initialExpirationTimestamp);
        let ipRefreshAnimation=null;
        let ipServiceStatusReady=false;
        const animateIptvServiceIp=function(ipNode,value){
          if(!ipNode||ipNode.textContent===value)return;
          if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){ipNode.textContent=value;return;}
          if(ipRefreshAnimation)ipRefreshAnimation.cancel();
          const fadeOut=ipNode.animate([{opacity:1},{opacity:0}],{duration:150,easing:'ease-in',fill:'forwards'});
          ipRefreshAnimation=fadeOut;
          fadeOut.finished.then(function(){
            if(ipRefreshAnimation!==fadeOut)return;
            ipNode.textContent=value;
            const fadeIn=ipNode.animate([{opacity:0},{opacity:1}],{duration:180,easing:'ease-out',fill:'forwards'});
            ipRefreshAnimation=fadeIn;
            fadeIn.finished.then(function(){if(ipRefreshAnimation===fadeIn)ipRefreshAnimation=null;}).catch(function(){});
          }).catch(function(){});
        };
        const refreshIptvServiceStatus=function(providerId){
          const ipNode=document.querySelector('[data-openap-service-ip]');
          if(!ipNode)return;
          const query=new URLSearchParams();if(/^[a-f0-9]{32}$/.test(String(providerId||'')))query.set('provider_id',String(providerId));
          fetch('/aptv/api/service'+(query.toString()?'?'+query.toString():''),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(data){
            if(data?.ok&&Object.prototype.hasOwnProperty.call(data,'expiration'))updateIptvServiceExpiration(data.expiration);
            if(data?.ok&&data.public_ip){
              if(!ipServiceStatusReady){ipNode.textContent=data.public_ip;ipServiceStatusReady=true;}
              else animateIptvServiceIp(ipNode,data.public_ip);
            }
          }).catch(function(){});
        };
        refreshIptvServiceStatus();
        window.openapSession?.setInterval(refreshIptvServiceStatus,30000);

        const iptvPage = document.querySelector('.openap-iptv-page');
        const noProviderState = document.querySelector('[data-openap-no-provider]');
        const noProviderShell = noProviderState?.querySelector('.openap-iptv-no-provider-shell');
        const noProviderToggle = document.querySelector('[data-openap-no-provider-toggle]');
        const noProviderOptions = document.querySelector('[data-openap-no-provider-options]');
        let noProviderOptionsTimer = null;
        let noProviderReturnTimer = null;
        const noProviderMotionDuration = 420;
        noProviderToggle?.addEventListener('click',function(){
          if (!noProviderOptions || !noProviderShell) return;
          const expanded = noProviderShell.classList.contains('is-expanded');
          window.clearTimeout(noProviderOptionsTimer);
          if (expanded) {
            noProviderShell.classList.remove('is-expanded');
            noProviderShell.classList.add('is-collapsing');
            noProviderToggle.setAttribute('aria-expanded','false');
            noProviderOptionsTimer = window.setTimeout(function(){
              noProviderOptions.hidden = true;
              noProviderShell.classList.remove('is-collapsing');
            }, noProviderMotionDuration);
          } else {
            noProviderOptions.hidden = false;
            noProviderShell.classList.remove('is-expanded', 'is-collapsing');
            /* Ensure the collapsed style is painted before the expanded class
             * is added; otherwise display:none -> expanded happens in one
             * style update and the opening transition is skipped. */
            void noProviderOptions.offsetHeight;
            noProviderShell.classList.add('is-expanded');
            noProviderToggle.setAttribute('aria-expanded','true');
          }
        });
        let providerOnboardingRevealPending = false;
        let providerDashboardRevealTimer = null;
        let rebuildProviderNavigation = null;
        let rebuildBottomNavigation = null;
        const setProviderOnboardingStage = function (stage) {
          if (!iptvPage) return;
          ['live', 'movies', 'series', 'ready'].forEach(function (name) {
            iptvPage.classList.toggle('is-onboarding-' + name, stage === name);
          });
          iptvPage.dataset.providerOnboarding = stage || '';
        };
        const setProviderCatalogAvailability = function (kind, count) {
          if (!iptvPage || !['live', 'movies', 'series'].includes(kind)) return;
          iptvPage.classList.toggle('is-onboarding-has-' + kind, Number(count) > 0);
        };
        const setNoProviderState = function (empty) {
          const enteringNoProvider = Boolean(empty) && !iptvPage?.classList.contains('is-no-provider');
          window.clearTimeout(noProviderReturnTimer);
          noProviderReturnTimer = null;
          if (!empty) iptvPage?.classList.remove('is-returning-no-provider');
          if (enteringNoProvider) iptvPage?.classList.add('is-returning-no-provider');
          iptvPage?.classList.toggle('is-no-provider', Boolean(empty));
          if (noProviderState) noProviderState.hidden = !empty;
          if (!empty || enteringNoProvider) {
            window.clearTimeout(noProviderOptionsTimer);
            noProviderShell?.classList.remove('is-expanded', 'is-collapsing');
            if (noProviderOptions) noProviderOptions.hidden = true;
            noProviderToggle?.setAttribute('aria-expanded', 'false');
          }
          if (empty) {
            iptvPage?.classList.remove('is-provider-catalog-filtered');
            iptvPage?.classList.remove('is-provider-onboarding-reveal', 'is-provider-dashboard-reveal', 'is-onboarding-revealed-live', 'is-onboarding-revealed-movies', 'is-onboarding-revealed-series', 'is-onboarding-revealed-recordings', 'is-onboarding-revealed-settings');
            providerOnboardingRevealPending = false;
            setProviderOnboardingStage('');
            ['live', 'movies', 'series'].forEach(function (kind) { setProviderCatalogAvailability(kind, 0); });
            const clearProviderNavigation = function () {
              if (!iptvPage?.classList.contains('is-no-provider')) return;
              iptvPage.classList.remove('is-returning-no-provider');
              rebuildProviderNavigation?.({live:false, recordings:false, movies:false, series:false, settings:false});
              rebuildBottomNavigation?.({search:false, movies:false, series:false, settings:false});
              noProviderReturnTimer = null;
            };
            if (enteringNoProvider) noProviderReturnTimer = window.setTimeout(clearProviderNavigation, 520);
            else clearProviderNavigation();
            try { window.localStorage.removeItem('openap-aptv-live-favorites-v1'); } catch (error) {}
          } else setProviderOnboardingStage('ready');
        };
        const refreshNoProviderState = async function () {
          try {
            const response = await fetch('/aptv/api/providers', {credentials:'same-origin', cache:'no-store'});
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to load providers.');
            const items = Array.isArray(payload.items) ? payload.items : [];
            setNoProviderState(!items.some(function (item) { return item && item.active; }));
          } catch (error) {
            setNoProviderState(false);
          }
        };
        refreshNoProviderState();

        const drawer = document.getElementById('openapIptvSettingsDrawer');
        const liveDrawer = document.getElementById('openapIptvLiveDrawer');
        const liveToggles = Array.from(document.querySelectorAll('[data-openap-iptv-live-toggle]'));
        const mediaSectionNav = document.querySelector('.openap-iptv-side-actions');
        const liveClose = document.querySelector('[data-openap-iptv-live-close]');
        const liveDismiss = document.querySelector('[data-openap-iptv-live-dismiss]');
        const liveMobileCollapse = document.querySelector('[data-openap-iptv-live-collapse]');
        const liveList = document.querySelector('[data-openap-iptv-live-list]');
        const liveFilter = document.querySelector('[data-openap-iptv-live-filter]');
        const moviesDrawer = document.getElementById('openapIptvMoviesDrawer');
        const moviesMobileCollapse = document.querySelector('[data-openap-movies-collapse]');
        const moviesToggles = Array.from(document.querySelectorAll('[data-openap-iptv-movies-toggle]'));
        const seriesToggles = Array.from(document.querySelectorAll('[data-openap-iptv-series-toggle]'));
        const recordingsToggles = Array.from(document.querySelectorAll('[data-openap-recordings-toggle]'));
        const sideNavigation = document.querySelector('.openap-iptv-side-actions');
        const footerNavigation = document.querySelector('.openap-footer-iptv-nav');
        const sideNavigationButtons = {
          live: sideNavigation?.querySelector('[data-openap-iptv-live-toggle]'),
          recordings: sideNavigation?.querySelector('[data-openap-recordings-toggle]'),
          movies: sideNavigation?.querySelector('[data-openap-iptv-movies-toggle]'),
          series: sideNavigation?.querySelector('[data-openap-iptv-series-toggle]'),
          settings: sideNavigation?.querySelector('[data-openap-iptv-settings-toggle]')
        };
        const footerNavigationButtons = {
          live: footerNavigation?.querySelector('[data-openap-iptv-live-toggle]'),
          recordings: footerNavigation?.querySelector('[data-openap-recordings-toggle]'),
          movies: footerNavigation?.querySelector('[data-openap-iptv-movies-toggle]'),
          series: footerNavigation?.querySelector('[data-openap-iptv-series-toggle]'),
          settings: footerNavigation?.querySelector('[data-openap-iptv-settings-toggle]')
        };
        const bottomNavigation = document.querySelector('.openap-iptv-bottom-actions');
        const bottomNavigationButtons = {
          search: bottomNavigation?.querySelector('[data-openap-global-search-toggle]'),
          movies: bottomNavigation?.querySelector('[data-openap-iptv-movies-toggle]'),
          series: bottomNavigation?.querySelector('[data-openap-iptv-series-toggle]'),
          settings: bottomNavigation?.querySelector('[data-openap-iptv-settings-toggle]')
        };
        rebuildBottomNavigation = function (available) {
          if (!bottomNavigation) return Promise.resolve();
          ['search', 'movies', 'series', 'settings'].forEach(function (kind) {
            const button = bottomNavigationButtons[kind];
            if (button) button.remove();
          });
          ['search', 'movies', 'series', 'settings'].forEach(function (kind) {
            if (available[kind] && bottomNavigationButtons[kind]) bottomNavigation.append(bottomNavigationButtons[kind]);
          });
          bottomNavigation.classList.remove('is-onboarding-bottom-fade-in');
          requestAnimationFrame(function () { bottomNavigation.classList.add('is-onboarding-bottom-fade-in'); });
          return new Promise(function (resolve) {
            window.setTimeout(function () {
              bottomNavigation.classList.remove('is-onboarding-bottom-fade-in');
              resolve();
            }, 520);
          });
        };
        if (iptvPage?.classList.contains('is-no-provider')) rebuildBottomNavigation({search:false, movies:false, series:false, settings:false});
        const providerNavigationButtonSelector = 'button.openap-iptv-side-link, button[data-openap-iptv-live-toggle], button[data-openap-recordings-toggle], button[data-openap-iptv-movies-toggle], button[data-openap-iptv-series-toggle], button[data-openap-iptv-settings-toggle]';
        const playProviderDashboardReveal = function () {
          if (!iptvPage) return;
          window.clearTimeout(providerDashboardRevealTimer);
          iptvPage.classList.remove('is-provider-dashboard-reveal');
          void iptvPage.offsetWidth;
          iptvPage.classList.add('is-provider-dashboard-reveal');
          providerDashboardRevealTimer = window.setTimeout(function () {
            iptvPage.classList.remove('is-provider-dashboard-reveal');
            providerDashboardRevealTimer = null;
          }, 1500);
        };
        const playProviderOnboardingReveal = function (available) {
          if (!iptvPage || !providerOnboardingRevealPending) return;
          providerOnboardingRevealPending = false;
          const stages = [
            {kind:'live', className:'is-onboarding-revealed-live'},
            {kind:'movies', className:'is-onboarding-revealed-movies'},
            {kind:'series', className:'is-onboarding-revealed-series'},
            {kind:'recordings', className:'is-onboarding-revealed-recordings'},
            {kind:'settings', className:'is-onboarding-revealed-settings'}
          ].filter(function (stage) { return available[stage.kind]; });
          iptvPage.classList.add('is-provider-onboarding-reveal');
          stages.forEach(function (stage, index) {
            window.setTimeout(function () {
              if (!iptvPage.classList.contains('is-provider-onboarding-reveal')) return;
              iptvPage.classList.add(stage.className);
              const buttons = [sideNavigationButtons[stage.kind], footerNavigationButtons[stage.kind]];
              buttons.filter(Boolean).forEach(function (button, buttonIndex) {
                button.classList.remove('is-onboarding-menu-entering');
                button.style.setProperty('--openap-onboarding-delay', (buttonIndex * 55) + 'ms');
                requestAnimationFrame(function () {
                  button.classList.add('is-onboarding-menu-entering');
                  window.setTimeout(function () {
                    button.classList.remove('is-onboarding-menu-entering');
                    button.style.removeProperty('--openap-onboarding-delay');
                  }, 460);
                });
              });
            }, 140 + (index * 220));
          });
          window.setTimeout(function () {
            iptvPage.classList.remove('is-provider-onboarding-reveal', 'is-onboarding-revealed-live', 'is-onboarding-revealed-movies', 'is-onboarding-revealed-series', 'is-onboarding-revealed-recordings', 'is-onboarding-revealed-settings');
          }, 140 + (stages.length * 220) + 420);
        };
        rebuildProviderNavigation = function (available) {
          const order = ['live', 'movies', 'series', 'recordings', 'settings'];
          [
            [sideNavigation, sideNavigationButtons],
            [footerNavigation, footerNavigationButtons]
          ].forEach(function (entry) {
            const container = entry[0], buttons = entry[1];
            if (!container) return;
            const presentButtons = new Set(Array.from(container.querySelectorAll(providerNavigationButtonSelector)));
            container.querySelectorAll(providerNavigationButtonSelector).forEach(function (button) { button.remove(); });
            let animationIndex = 0;
            order.forEach(function (kind) {
              const button = buttons[kind];
              if (!available[kind] || !button) return;
              if (!presentButtons.has(button)) {
                button.dataset.openapOnboardingPending = '1';
                button.style.setProperty('--openap-onboarding-delay', (animationIndex * 70) + 'ms');
              }
              container.append(button);
              animationIndex += 1;
            });
          });
        };
        // Settings is available even before the first provider is configured.
        // Keep its actual footer/sidebar buttons in the DOM: the no-provider
        // CSS hides only catalog sections, while the mobile footer must still
        // be able to open the Settings drawer.
        if (iptvPage?.classList.contains('is-no-provider')) rebuildProviderNavigation({live:false, recordings:false, movies:false, series:false, settings:true});
        const recordingsView = document.querySelector('[data-openap-recordings-view]');
        const globalSearchToggles = Array.from(document.querySelectorAll('[data-openap-global-search-toggle]'));
        const globalSearchView = document.querySelector('[data-openap-global-search-view]');
        const globalSearchBrowser = document.querySelector('[data-openap-global-search-browser]');
        const globalSearchForm = document.querySelector('[data-openap-global-search-form]');
        const globalSearchInput = document.querySelector('[data-openap-global-search-input]');
        const globalSearchSort = document.querySelector('[data-openap-global-search-sort]');
        const globalSearchStatus = document.querySelector('[data-openap-global-search-status]');
        const globalSearchResults = document.querySelector('[data-openap-global-search-results]');
        const moviesDrawerTitle = document.getElementById('openapIptvMoviesTitle');
        const moviesBack = document.querySelector('[data-openap-movies-back]');
        const moviesList = document.querySelector('[data-openap-movies-list]');
        const moviesFilter = document.querySelector('[data-openap-movies-filter]');
        const libraryEditButton = document.querySelector('[data-openap-library-edit-order]');
        const libraryEditCancel = document.querySelector('[data-openap-library-edit-cancel]');
        const libraryEditSave = document.querySelector('[data-openap-library-edit-save]');
        const libraryMoveTop = document.querySelector('[data-openap-library-move-top]');
        const libraryMoveBottom = document.querySelector('[data-openap-library-move-bottom]');
        const moviesView = document.querySelector('[data-openap-iptv-movies-view]');
        const movieCatalog = moviesView?.querySelector('.openap-iptv-movie-catalog');
        const movieCatalogExpand = moviesView?.querySelector('[data-openap-movie-catalog-expand]');
        const movieCatalogExpandMedia = window.matchMedia('(max-width:767.98px) and (orientation:portrait) and (max-height:999px)');
        const syncMovieCatalogExpansion=function(expanded){
          if(!moviesView||!movieCatalogExpand)return;
          const active=movieCatalogExpandMedia.matches&&Boolean(expanded);
          if(active){
            const viewRect=moviesView.getBoundingClientRect();
            const targetTop=Math.min(window.innerHeight*.32,270);
            const playOptions=moviesView.querySelector('[data-openap-movie-play-options]');
            const playRect=playOptions&&!playOptions.hidden?playOptions.getBoundingClientRect():null;
            const safeTop=playRect&&playRect.height?Math.max(targetTop,playRect.bottom-viewRect.top+12):targetTop;
            moviesView.style.setProperty('--openap-catalog-expanded-top',Math.round(safeTop)+'px');
          }else moviesView.style.removeProperty('--openap-catalog-expanded-top');
          moviesView.classList.toggle('is-catalog-expanded',active);
          movieCatalogExpand.setAttribute('aria-expanded',active?'true':'false');
          const label=active?<?php echo json_encode(_('Collapse catalog')); ?>:<?php echo json_encode(_('Expand catalog')); ?>;
          movieCatalogExpand.setAttribute('aria-label',label);
          movieCatalogExpand.title=label;
          movieCatalogExpand.classList.toggle('is-expanded',active);
          const icon=movieCatalogExpand.querySelector('i');
          if(icon)icon.className='fas fa-chevron-up';
        };
        movieCatalogExpand?.addEventListener('click',function(){syncMovieCatalogExpansion(!moviesView.classList.contains('is-catalog-expanded'));});
        movieCatalogExpandMedia.addEventListener?.('change',function(){syncMovieCatalogExpansion(false);});
        let movieCatalogExpansionResizeFrame=0;
        window.addEventListener('resize',function(){
          if(!moviesView?.classList.contains('is-catalog-expanded'))return;
          window.cancelAnimationFrame(movieCatalogExpansionResizeFrame);
          movieCatalogExpansionResizeFrame=window.requestAnimationFrame(function(){syncMovieCatalogExpansion(true);});
        },{passive:true});
        const movieGrid = document.querySelector('[data-openap-movie-grid]');
        const movieLoading = document.querySelector('[data-openap-movie-loading]');
        const revealMovieCard=function(card){if(!card||card.classList.contains('is-poster-ready'))return;const siblings=card.parentElement?Array.from(card.parentElement.children):[],index=siblings.indexOf(card);card.style.setProperty('--openap-poster-index',String(Math.min(Math.max(index,0),12)));card.classList.remove('is-poster-pending');card.classList.add('is-poster-ready');};
        const posterLoadLimit=4;
        let activePosterLoads=0;
        const posterQueue=[];
        const posterJobs=new WeakMap();
        const posterJobSet=new Set();
        const pumpPosterQueue=function(){while(activePosterLoads<posterLoadLimit&&posterQueue.length){const job=posterQueue.shift();if(!job||job.started||job.cancelled||!job.image.isConnected){if(job)posterJobSet.delete(job);continue;}job.started=true;activePosterLoads+=1;let finished=false;const finish=function(ok,cancelled){if(finished)return;finished=true;activePosterLoads=Math.max(0,activePosterLoads-1);posterJobSet.delete(job);if(cancelled){job.image.removeAttribute('src');}else if(ok){job.poster.classList.remove('is-placeholder');revealMovieCard(job.card);}else{job.image.remove();job.poster.classList.add('is-placeholder');revealMovieCard(job.card);}pumpPosterQueue();};job.finish=finish;job.image.decoding='async';job.image.addEventListener('load',function(){finish(true,false);},{once:true});job.image.addEventListener('error',function(){finish(false,false);},{once:true});job.image.src=job.url;}};
        const posterObserver=movieGrid&&'IntersectionObserver' in window?new IntersectionObserver(function(entries){entries.forEach(function(entry){if(!entry.isIntersecting&&entry.intersectionRatio===0)return;const job=posterJobs.get(entry.target);if(!job||job.queued)return;job.queued=true;posterObserver.unobserve(entry.target);posterQueue.push(job);pumpPosterQueue();});},{root:movieGrid,rootMargin:'160px 0px',threshold:0.01}):null;
        const resetPosterQueue=function(){if(posterObserver)posterObserver.disconnect();posterQueue.splice(0);Array.from(posterJobSet).forEach(function(job){job.cancelled=true;if(job.finish)job.finish(false,true);else posterJobSet.delete(job);});};
        const cancelDisconnectedPosterJobs=function(){Array.from(posterJobSet).forEach(function(job){if(job.image.isConnected)return;job.cancelled=true;if(job.finish)job.finish(false,true);else posterJobSet.delete(job);});};
        const queuePosterImage=function(image,url,poster,card){const job={image:image,url:String(url),poster:poster,card:card,queued:false,started:false,cancelled:false};posterJobs.set(image,job);posterJobSet.add(job);if(posterObserver)posterObserver.observe(image);else{job.queued=true;posterQueue.push(job);pumpPosterQueue();}};
        if(movieGrid)new MutationObserver(function(records){records.forEach(function(record){if(record.removedNodes.length)cancelDisconnectedPosterJobs();record.addedNodes.forEach(function(node){if(!(node instanceof HTMLElement)||!node.classList.contains('openap-iptv-movie-card'))return;node.classList.add('is-poster-pending');const image=node.querySelector('.openap-iptv-movie-poster img');if(!image||image.complete)requestAnimationFrame(function(){revealMovieCard(node);});else{image.addEventListener('load',function(){revealMovieCard(node);},{once:true});image.addEventListener('error',function(){revealMovieCard(node);},{once:true});}});});}).observe(movieGrid,{childList:true});
        const moviesHeading = document.querySelector('[data-openap-movies-heading]');
        const moviesCount = document.querySelector('[data-openap-movies-count]');
        const seasonSelect = document.querySelector('[data-openap-season-select]');
        const movieSearch = document.querySelector('[data-openap-movie-search]');
        const movieSearchToggle = document.querySelector('[data-openap-movie-search-toggle]');
        const movieSearchControl = movieSearch?.closest('.openap-iptv-hero-search');
        const libraryKicker = document.querySelector('[data-openap-library-kicker]');
        const movieBackdrop = document.querySelector('[data-openap-movie-backdrop]');
        const primeMobileHeroEntrance=function(){if(window.matchMedia('(max-width:767.98px)').matches&&movieBackdrop)movieBackdrop.style.transform='translateY(calc(-100% - 100vh))';};
        movieGrid?.addEventListener('click',function(event){if(!(event.target instanceof Element)||!event.target.closest('.openap-iptv-movie-card'))return;primeMobileHeroEntrance();},true);
        const movieDetailCopy = document.querySelector('.openap-iptv-movie-detail-copy');
        const movieTitle = document.querySelector('[data-openap-movie-title]');
        const movieMeta = document.querySelector('[data-openap-movie-meta]');
        let movieMetaRendering=false;
        const renderMovieMetaChips=function(){if(!movieMeta||movieMetaRendering||!movieMeta.childNodes.length)return;const raw=Array.from(movieMeta.childNodes).filter(function(node){return node.nodeType===Node.TEXT_NODE;}).map(function(node){return node.textContent;}).join(' ').trim();if(!raw)return;const values=raw.split(/\s+·\s+/).map(function(value){return value.trim();}).filter(Boolean);if(!values.length)return;movieMetaRendering=true;movieMeta.replaceChildren(...values.map(function(value,index){const chip=document.createElement('span');let kind='neutral';if(/^★\s*/.test(value))kind='rating';else if(/^\d{4}(?:-\d{2}-\d{2})?$/.test(value))kind='date';else if(/^\d{1,3}:\d{2}(?::\d{2})?$/.test(value)||(index===2&&/^\d+(?:\.\d+)?(?:\s*(?:min|mins|minutes|m))?$/i.test(value)))kind='duration';else if(/^(?:\d+\s+)?(?:Season|Seasons|Stagione|Stagioni)\b/i.test(value))kind='seasons';else if(index===1&&!/^(?:Season|Episode|Stagione|Episodio)\b/i.test(value))kind='genre';chip.className='openap-iptv-metadata-chip openap-iptv-metadata-chip-'+kind;chip.textContent=value;return chip;}));movieMetaRendering=false;};
        if(movieMeta)new MutationObserver(renderMovieMetaChips).observe(movieMeta,{childList:true,characterData:true,subtree:true});
        const movieDescription = document.querySelector('[data-openap-movie-description]');
        const moviePlayOptions = document.querySelector('[data-openap-movie-play-options]');
        const moviePlay = document.querySelector('[data-openap-movie-play]');
        const moviePlayDanish = document.querySelector('[data-openap-movie-play-danish]');
        const seriesEpisodes = document.querySelector('[data-openap-series-episodes]');
        const seriesCatalogBack = document.querySelector('[data-openap-series-catalog-back]');
        const epgView=document.querySelector('[data-openap-epg]'),epgToggles=Array.from(document.querySelectorAll('[data-openap-epg-toggle]')),epgClose=document.querySelector('[data-openap-epg-close]'),epgNow=document.querySelector('[data-openap-epg-now]'),epgRefresh=document.querySelector('[data-openap-epg-refresh]'),epgCountry=document.querySelector('[data-openap-epg-country]'),epgHours=document.querySelector('[data-openap-epg-hours]'),epgGrid=document.querySelector('[data-openap-epg-grid]'),epgDetail=document.querySelector('[data-openap-epg-detail]'),epgDetailTime=document.querySelector('[data-openap-epg-detail-time]'),epgDetailTitle=document.querySelector('[data-openap-epg-detail-title]'),epgDetailDescription=document.querySelector('[data-openap-epg-detail-description]'),epgWatch=document.querySelector('[data-openap-epg-watch]');
        const wallView=document.querySelector('[data-openap-wall]'),wallToggles=Array.from(document.querySelectorAll('[data-openap-wall-toggle]')),wallLauncherCells=Array.from(document.querySelectorAll('[data-openap-wall-launcher-cell]')),wallLauncherIcon=wallLauncherCells[0]?.parentElement,wallLauncherClean=document.querySelector('[data-openap-wall-launcher-clean]'),wallClose=document.querySelector('[data-openap-wall-close]'),wallAdd=document.querySelector('[data-openap-wall-add]'),wallPlayStream=document.querySelector('[data-openap-wall-play-stream]'),wallEmptyState=document.querySelector('[data-openap-wall-empty-state]'),wallStatus=document.querySelector('[data-openap-wall-status]'),wallTiles=Array.from(document.querySelectorAll('[data-openap-wall-tile]')),wallPlayers=wallTiles.map(function(tile){return {tile:tile,video:tile.querySelector('[data-openap-wall-video]'),search:tile.querySelector('[data-openap-wall-search]'),options:tile.querySelector('[data-openap-wall-options]'),empty:tile.querySelector('[data-openap-wall-empty]'),emptyMessage:tile.querySelector('.openap-aptv-wall-empty-message'),channelInfo:tile.querySelector('[data-openap-wall-channel-info]'),channelIcon:tile.querySelector('[data-openap-wall-channel-icon]'),channelName:tile.querySelector('[data-openap-wall-channel-name]'),title:tile.querySelector('[data-openap-wall-title]'),mute:tile.querySelector('[data-openap-wall-mute]'),fullscreen:tile.querySelector('[data-openap-wall-fullscreen]'),close:tile.querySelector('[data-openap-wall-close-tile]'),hls:null,items:[],searchTimer:0,searchController:null,closeTimer:0,enterFrame:0,enterTimer:0,wallToken:0};});
        const providerSwitch=document.querySelector('[data-openap-provider-switch]');
        const providerSwitchSelect=providerSwitch?.querySelector('[data-openap-provider-switch-select]');
        const wallProviderSwitch=document.querySelector('[data-openap-wall-provider-switch]');
        const wallProviderSwitchSelect=wallProviderSwitch?.querySelector('[data-openap-wall-provider-switch-select]');
        const wallPickerCapacity=document.querySelector('[data-openap-wall-capacity]');
        let providerSwitchItems=[];
        let providerSwitchBusy=false;
        const fillProviderSelect=function(select,items,activeId){
          if(!select)return;
          select.replaceChildren();
          items.forEach(function(item){const option=new Option(String(item.name||item.id),String(item.id));select.append(option);});
          if(activeId&&items.some(function(item){return String(item.id)===String(activeId);}))select.value=String(activeId);
          select.dataset.previous=String(select.value||'');
        };
        const renderProviderSwitch=function(items,activeId){
          providerSwitchItems=Array.isArray(items)?items.filter(function(item){return item&&String(item.id||'')!=='';}):[];
          fillProviderSelect(providerSwitchSelect,providerSwitchItems,activeId);
          fillProviderSelect(wallProviderSwitchSelect,providerSwitchItems,activeId);
          if(wallProviderSwitch)wallProviderSwitch.hidden=providerSwitchItems.length<2;
        };
        const requestProviderSwitch=async function(providerId){
          const response=await fetch('/aptv/api/providers/switch',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:document.querySelector('meta[name="csrf_token"]')?.content||'',provider_id:providerId})});
          const payload=await response.json();
          if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_('Unable to switch the provider.')); ?>);
          if(String(payload.active_id||'')!==providerId)throw new Error(<?php echo json_encode(_('The provider switch was not committed.')); ?>);
          return payload;
        };
        const showProviderSwitchError=function(select,previous,error,title){
          if(previous)select.value=previous;
          if(typeof window.openapNotify==='function')window.openapNotify({level:'error',title:title,message:error.message,duration:5000});
        };
        providerSwitchSelect?.addEventListener('change',async function(){
          const providerId=String(providerSwitchSelect.value||''),previous=providerSwitchSelect.dataset.previous||'';
          if(!providerId||providerSwitchSelect.disabled)return;
          providerSwitchSelect.disabled=true;
          providerSwitchBusy=true;
          invalidateProviderRequests();
          try{
            await requestProviderSwitch(providerId);
            providerSwitchBusy=false;
            providerSwitchSelect.dataset.previous=providerId;
            if(wallProviderSwitchSelect){wallProviderSwitchSelect.value=providerId;wallProviderSwitchSelect.dataset.previous=providerId;updateWallPickerCapacity?.();}
            refreshIptvServiceStatus(providerId);
            if(typeof stopChannel==='function')stopChannel();
            if(typeof setLiveOpen==='function')setLiveOpen(false);
            if(typeof resetLibraryCatalogForProvider==='function')await resetLibraryCatalogForProvider();
            if(typeof loadRegions==='function')await loadRegions('back');
          }catch(error){showProviderSwitchError(providerSwitchSelect,previous,error,<?php echo json_encode(_('Provider switch failed')); ?>);}
          finally{providerSwitchBusy=false;providerSwitchSelect.disabled=false;}
        });
        wallProviderSwitchSelect?.addEventListener('change',async function(){
          const providerId=String(wallProviderSwitchSelect.value||''),previous=wallProviderSwitchSelect.dataset.previous||'';
          if(!providerId||wallProviderSwitchSelect.disabled)return;
          wallProviderSwitchSelect.disabled=true;
          providerSwitchBusy=true;
          invalidateProviderRequests();
          try{
            await requestProviderSwitch(providerId);
            providerSwitchBusy=false;
            wallProviderSwitchSelect.dataset.previous=providerId;
            if(providerSwitchSelect){providerSwitchSelect.value=providerId;providerSwitchSelect.dataset.previous=providerId;}
            updateWallPickerCapacity?.();
            refreshIptvServiceStatus(providerId);
            if(typeof resetLibraryCatalogForProvider==='function')await resetLibraryCatalogForProvider();
            if(typeof loadRegions==='function')await loadRegions('back');
          }catch(error){showProviderSwitchError(wallProviderSwitchSelect,previous,error,<?php echo json_encode(_('Wall provider switch failed')); ?>);}
          finally{providerSwitchBusy=false;wallProviderSwitchSelect.disabled=false;}
        });
        const animateWallAvailability=function(){
          const actions=document.querySelector('.openap-iptv-primary-actions');
          const liveButton=actions?.querySelector('[data-openap-iptv-live-toggle]');
          const wallWrap=actions?.querySelector('.openap-wall-launcher-wrap');
          const wallButton=wallWrap?.querySelector('[data-openap-wall-toggle]');
          if(!actions||!liveButton||!wallWrap||!wallButton||!actions.classList.contains('is-wall-unavailable'))return false;
          const originRect=liveButton.getBoundingClientRect();
          const originX=originRect.left+(originRect.width/2),originY=originRect.top+(originRect.height/2);
          const setStart=function(node,rect){
            node.style.setProperty('--wall-start-x',(originX-(rect.left+(rect.width/2)))+'px');
            node.style.setProperty('--wall-start-y',(originY-(rect.top+(rect.height/2)))+'px');
          };
          actions.classList.add('is-wall-transitioning');
          providerSwitch?.classList.remove('is-provider-switch-visible','is-provider-switch-entering','is-provider-switch-primed','is-provider-switch-run','is-provider-switch-collapsing','is-provider-switch-collapse-run');
          requestAnimationFrame(function(){
            actions.classList.add('is-wall-transition-fade-out');
            window.setTimeout(function(){
              actions.classList.add('is-wall-transition-entering','is-wall-transition-primed');
              if(providerSwitch&&providerSwitchItems.length){providerSwitch.hidden=false;providerSwitch.classList.add('is-provider-switch-entering','is-provider-switch-primed');}
              wallWrap.hidden=false;
              wallWrap.removeAttribute('data-openap-wall-deferred');
              actions.classList.remove('is-wall-unavailable');
              void actions.offsetWidth;
              const enteredLiveRect=liveButton.getBoundingClientRect(),enteredWallRect=wallButton.getBoundingClientRect();
              setStart(liveButton,enteredLiveRect);
              setStart(wallButton,enteredWallRect);
              actions.classList.remove('is-wall-transition-fade-out');
              void actions.offsetWidth;
              void window.getComputedStyle(liveButton).transform;
              void window.getComputedStyle(wallButton).transform;
              actions.classList.remove('is-wall-transition-primed');
              void actions.offsetWidth;
              providerSwitch?.classList.remove('is-provider-switch-primed');
              if(providerSwitch&&!providerSwitch.hidden)void providerSwitch.offsetWidth;
              requestAnimationFrame(function(){
                requestAnimationFrame(function(){
                  actions.classList.add('is-wall-transition-run');
                  providerSwitch?.classList.add('is-provider-switch-run');
                  window.setTimeout(function(){
                    actions.classList.remove('is-wall-transition-run','is-wall-transition-entering','is-wall-transition-primed','is-wall-transitioning');
                    providerSwitch?.classList.remove('is-provider-switch-run','is-provider-switch-entering','is-provider-switch-primed');
                    providerSwitch?.classList.add('is-provider-switch-visible');
                    [liveButton,wallButton].forEach(function(node){node.style.removeProperty('--wall-start-x');node.style.removeProperty('--wall-start-y');});
                  },1200);
                });
              });
            },260);
          });
          return true;
        };
        const collapseWallAvailability=function(){
          const actions=document.querySelector('.openap-iptv-primary-actions');
          const liveButton=actions?.querySelector('[data-openap-iptv-live-toggle]');
          const wallWrap=actions?.querySelector('.openap-wall-launcher-wrap');
          const wallButton=wallWrap?.querySelector('[data-openap-wall-toggle]');
          if(!actions||!liveButton||!wallWrap||!wallButton||actions.classList.contains('is-wall-unavailable')||wallWrap.hidden)return false;
          providerSwitch?.classList.add('is-provider-switch-collapsing');
          const liveRect=liveButton.getBoundingClientRect(),wallRect=wallButton.getBoundingClientRect();
          actions.classList.add('is-wall-unavailable');
          wallWrap.hidden=true;
          void actions.offsetWidth;
          const targetRect=liveButton.getBoundingClientRect();
          const targetX=targetRect.left+(targetRect.width/2),targetY=targetRect.top+(targetRect.height/2);
          wallWrap.hidden=false;
          actions.classList.remove('is-wall-unavailable');
          void actions.offsetWidth;
          const setDestination=function(node,rect){
            node.style.setProperty('--wall-collapse-x',(targetX-(rect.left+(rect.width/2)))+'px');
            node.style.setProperty('--wall-collapse-y',(targetY-(rect.top+(rect.height/2)))+'px');
          };
          setDestination(liveButton,liveRect);
          setDestination(wallButton,wallRect);
          actions.classList.add('is-wall-collapsing');
          requestAnimationFrame(function(){
            actions.classList.add('is-wall-collapse-run');
            providerSwitch?.classList.add('is-provider-switch-collapse-run');
            window.setTimeout(function(){
              [liveButton,wallButton].forEach(function(node){
                node.style.setProperty('transition','none','important');
                node.style.setProperty('animation','none','important');
                node.style.setProperty('transform','none','important');
              });
              wallWrap.hidden=true;
              actions.classList.add('is-wall-unavailable','is-wall-collapse-finalizing');
              actions.classList.remove('is-wall-collapse-run','is-wall-collapsing');
              if(providerSwitch){providerSwitch.hidden=true;providerSwitch.classList.remove('is-provider-switch-visible','is-provider-switch-collapsing','is-provider-switch-collapse-run');}
              [liveButton,wallButton].forEach(function(node){node.style.removeProperty('--wall-collapse-x');node.style.removeProperty('--wall-collapse-y');});
              requestAnimationFrame(function(){requestAnimationFrame(function(){
                actions.classList.remove('is-wall-collapse-finalizing');
                [liveButton,wallButton].forEach(function(node){node.style.removeProperty('transition');node.style.removeProperty('animation');node.style.removeProperty('transform');});
              });});
            },1200);
          });
          return true;
        };
        const syncWallAvailability=async function(){
          try{
            const response=await fetch('/aptv/api/providers',{credentials:'same-origin',cache:'no-store'});
            const payload=await response.json();
            if(!response.ok||!payload.ok)throw new Error('Unable to load providers.');
            const items=Array.isArray(payload.items)?payload.items:[],active=items.find(function(item){return item&&item.active;})||null;
            renderProviderSwitch(items,String(active?.id||''));
            const available=items.length>1||Number(active?.max_connections||active?.max_conn||1)>1;
            const actions=document.querySelector('.openap-iptv-primary-actions');
            if(available){if(actions?.classList.contains('is-wall-unavailable'))animateWallAvailability();else if(providerSwitch&&providerSwitchItems.length){providerSwitch.hidden=false;providerSwitch.classList.add('is-provider-switch-visible');}}
            else if(actions&&!actions.classList.contains('is-wall-unavailable'))collapseWallAvailability();
            else if(providerSwitch){providerSwitch.hidden=true;providerSwitch.classList.remove('is-provider-switch-visible','is-provider-switch-entering','is-provider-switch-primed','is-provider-switch-run','is-provider-switch-collapsing','is-provider-switch-collapse-run');}
            return available;
          }catch(error){return null;}
        };
        // Populate both dashboard and Wall selectors on every page load. The
        // Wall picker must use the same provider list and active profile even
        // when the provider modal has not been opened in this session.
        syncWallAvailability();
        const wallPlayControls=document.querySelector('[data-openap-wall-play-controls]'),wallClean=document.querySelector('[data-openap-wall-clean]');
        const isWallTouchViewport=function(){return window.matchMedia('(pointer: coarse) and (max-width:1100px)').matches;};
        let wallActiveCount=0;
        let wallChannelPickerOpen=false;
        let wallPickerCloseTimer=0;
        const wallSelectedProviderId=function(){const value=String(wallProviderSwitchSelect?.value||providerSwitchSelect?.value||'');return /^[a-f0-9]{32}$/.test(value)?value:'';};
        const wallItemProviderId=function(item){const value=String(item?.provider_id||'');return /^[a-f0-9]{32}$/.test(value)?value:wallSelectedProviderId();};
        const wallProviderMaxConnections=function(providerId){const profile=providerSwitchItems.find(function(item){return String(item?.id||'')===String(providerId||'');});return Math.max(1,Number(profile?.max_connections||profile?.max_conn||1));};
        const wallProviderActivePlayers=function(providerId){return wallPlayers.filter(function(player){return player.tile?.classList.contains('is-active')&&wallItemProviderId(player.item)===providerId;});};
        const wallSelectedPlayerForItem=function(item){const providerId=wallItemProviderId(item),itemId=String(item?.item_id||'');return wallPlayers.find(function(player){return player.tile?.classList.contains('is-active')&&String(player.item?.item_id||'')===itemId&&wallItemProviderId(player.item)===providerId;})||null;};
        const updateWallPickerCapacity=function(){if(!wallPickerCapacity)return;const providerId=wallSelectedProviderId(),used=providerId?wallProviderActivePlayers(providerId).length:0,max=wallProviderMaxConnections(providerId);wallPickerCapacity.textContent='Connections: '+used+'/'+max;wallPickerCapacity.classList.toggle('is-at-capacity',used>=max);};
        const syncWallPickerActions=function(){document.querySelectorAll('.openap-iptv-live-wall-action').forEach(function(action){const providerId=String(action.dataset.wallProviderId||''),itemId=String(action.dataset.wallItemId||''),selected=wallPlayers.some(function(player){return player.tile?.classList.contains('is-active')&&String(player.item?.item_id||'')===itemId&&wallItemProviderId(player.item)===providerId;}),used=providerId?wallProviderActivePlayers(providerId).length:0,max=wallProviderMaxConnections(providerId),disabled=!selected&&(used>=max||wallPlayers.every(function(player){return player.tile?.classList.contains('is-active');}));action.classList.toggle('is-selected',selected);action.classList.toggle('is-disabled',disabled);action.setAttribute('aria-disabled',disabled?'true':'false');action.setAttribute('title',selected?<?php echo json_encode(_('Remove from Wall')); ?>:<?php echo json_encode(_('Add to Wall')); ?>);action.setAttribute('aria-label',action.title);action.innerHTML='<i class="fas '+(selected?'fa-minus':'fa-plus')+'" aria-hidden="true"></i>';});updateWallPickerCapacity();};
        const setWallPickerInlineStyle=function(open){
          if(!liveDrawer)return;
          if(open){
            liveDrawer.style.setProperty('position','absolute','important');
            liveDrawer.style.setProperty('top','0px','important');
            liveDrawer.style.setProperty('right','auto','important');
            liveDrawer.style.setProperty('bottom','0px','important');
            liveDrawer.style.setProperty('left','50%','important');
            liveDrawer.style.setProperty('width','400px','important');
            liveDrawer.style.setProperty('max-width','400px','important');
            liveDrawer.style.setProperty('height','auto','important');
            liveDrawer.style.setProperty('max-height','none','important');
            liveDrawer.style.setProperty('transform','translate(-50%,0)','important');
            liveDrawer.style.setProperty('opacity','1','important');
            liveDrawer.style.setProperty('visibility','visible','important');
            liveDrawer.style.setProperty('animation','none','important');
            liveDrawer.style.setProperty('transition','transform .28s cubic-bezier(.22,1,.36,1),opacity .2s ease','important');
            liveDrawer.style.setProperty('z-index','20','important');
          }else{
            liveDrawer.style.setProperty('transform','translate(-50%,-120px)','important');
            liveDrawer.style.setProperty('opacity','0','important');
          }
        };
        let wallFullscreenSession=null;
        let wallTileFullscreenSession=null;
        let wallGeneration=0;
        let wallCleanupTimer=0;
        const wallNavigationSelector='.openap-iptv-primary-actions,.openap-iptv-bottom-actions,.openap-iptv-mobile-search-trigger,.openap-iptv-side-actions,.openap-footer-iptv-nav';
        const blockWallNavigationEvent=function(event){if(!wallView||wallView.hidden||event.target.closest('[data-openap-iptv-settings-toggle]')||!event.target.closest(wallNavigationSelector))return;event.preventDefault();event.stopImmediatePropagation();};
        document.addEventListener('click',blockWallNavigationEvent,true);
        document.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '||event.key==='ArrowDown'||event.key==='ArrowUp')blockWallNavigationEvent(event);},true);
        const editOrderButton = document.querySelector('[data-openap-iptv-edit-order]');
        const editCancelButton = document.querySelector('[data-openap-iptv-edit-cancel]');
        const editSaveButton = document.querySelector('[data-openap-iptv-edit-save]');
        const moveTopButton = document.querySelector('[data-openap-iptv-move-top]');
        const moveBottomButton = document.querySelector('[data-openap-iptv-move-bottom]');
        const toggles = Array.from(document.querySelectorAll('[data-openap-iptv-settings-toggle]'));
        const dismissers = Array.from(document.querySelectorAll('[data-openap-iptv-settings-dismiss]'));
        const backdrop = document.querySelector('.openap-iptv-settings-backdrop');
        const rootPanel = document.querySelector('[data-openap-iptv-settings-panel="root"]');
        const providerPanel = document.querySelector('[data-openap-iptv-settings-panel="provider"]');
        const providerMenu = document.querySelector('[data-openap-iptv-provider-menu]');
        const providerBack = document.querySelector('[data-openap-iptv-provider-back]');
        const profilePanel = document.querySelector('[data-openap-iptv-settings-panel="profile"]');
        const profileMenu = document.querySelector('[data-openap-iptv-profile-menu]');
        const profileBack = document.querySelector('[data-openap-iptv-profile-back]');
        const profileExportButton = document.querySelector('[data-openap-profile-export]');
        const profileExportScope = document.querySelector('[data-openap-profile-export-scope]');
        const profileChooseButton = document.querySelector('[data-openap-profile-choose]');
        const profileImportButton = document.querySelector('[data-openap-profile-import]');
        const profileImportInput = document.querySelector('[data-openap-profile-import-input]');
        const profileImportMode = document.querySelector('[data-openap-profile-import-mode]');
        const profileFileName = document.querySelector('[data-openap-profile-file-name]');
        const profileResetButton = document.querySelector('[data-openap-profile-reset]');
        const profileStatus = document.querySelector('[data-openap-profile-status]');
        const profileModalNode = document.getElementById('openapIptvProfileModal');
        const noProviderBackupButton = document.querySelector('[data-openap-no-provider-backup]');
        noProviderBackupButton?.addEventListener('click',function(){profileChooseButton?.click();});
        let selectedMenuProfile = null;
        let profilePanelFrame = 0;
        let providerPanelFrame = 0;
        let providerPanelAnimationTimer = 0;
        let returnFocus = null;
        let liveReturnFocus = null;
        if(moviePlay){const updatePlayLabel=function(){const label=moviePlay.querySelector('span');if(label)label.textContent=String(moviePlay.dataset.movieId||'')==='160289'?<?php echo json_encode(_("Play in Italian")); ?>:<?php echo json_encode(_("Play")); ?>;};new MutationObserver(updatePlayLabel).observe(moviePlay,{attributes:true,attributeFilter:['data-movie-id']});updatePlayLabel();}

        const regionNames = {AF:'Afghanistan',AFR:'Africa',AE:'United Arab Emirates',AG:'Antigua and Barbuda',AL:'Albania',AM:'Armenia',AR:'Argentina',ASIA:'Asia',AT:'Austria',AU:'Australia',AZ:'Azerbaijan',BD:'Bangladesh',BE:'Belgium',BF:'Burkina Faso',BG:'Bulgaria',BH:'Bahrain',BJ:'Benin',BO:'Bolivia',BR:'Brazil',CA:'Canada',CD:'Democratic Republic of the Congo',CG:'Republic of the Congo',CH:'Switzerland',CL:'Chile',CN:'China',CO:'Colombia',CR:'Costa Rica',CV:'Cape Verde',CY:'Cyprus',CZ:'Czech Republic',DE:'Germany',DJ:'Djibouti',DK:'Denmark',DO:'Dominican Republic',DZ:'Algeria',EC:'Ecuador',EE:'Estonia',EG:'Egypt',ER:'Eritrea',ES:'Spain',EXYU:'Ex-Yugoslavia',FI:'Finland',FR:'France',GE:'Georgia',GH:'Ghana',GM:'Gambia',GN:'Guinea',GR:'Greece',HK:'Hong Kong',HN:'Honduras',HR:'Croatia',HT:'Haiti',HU:'Hungary',ID:'Indonesia',IE:'Ireland',IL:'Israel',IN:'India',IQ:'Iraq',IR:'Iran',IS:'Iceland',IT:'Italy',JO:'Jordan',JP:'Japan',KE:'Kenya',KH:'Cambodia',KR:'South Korea',KU:'Kurdish',KW:'Kuwait',KZ:'Kazakhstan',LA:'Latin America',LB:'Lebanon',LK:'Sri Lanka',LT:'Lithuania',LV:'Latvia',LY:'Libya',MA:'Morocco',MC:'Radio',MK:'Macedonia',MR:'Mauritania',MT:'Malta',MX:'Mexico',MY:'Malaysia',MZ:'Mozambique',NA:'Namibia',MK:'Macedonia',MR:'Mauritania',MT:'Malta',MX:'Mexico',MY:'Malaysia',MZ:'Mozambique',NA:'Namibia',NG:'Nigeria',NL:'Netherlands',NO:'Norway',NP:'Nepal',NZ:'New Zealand',OM:'Oman',PA:'Panama',PE:'Peru',PH:'Philippines',PK:'Pakistan',PL:'Poland',PS:'Palestine',PT:'Portugal',PY:'Paraguay',QA:'Qatar',RO:'Romania',RU:'Russia',RX:'Relax',SA:'Saudi Arabia',SD:'Sudan',SE:'Sweden',SG:'Singapore',SI:'Slovenia',SK:'Slovakia',SN:'Senegal',SO:'Somalia',SR:'Suriname',SY:'Syria',TD:'Chad',TG:'Togo',TH:'Thailand',TN:'Tunisia',TR:'Turkey',TS:'Tennis',TW:'Taiwan',TZ:'Tanzania',UA:'Ukraine',UK:'United Kingdom',US:'United States',UY:'Uruguay',UZ:'Uzbekistan',VE:'Venezuela',VN:'Vietnam',WT:'World Cricket',YE:'Yemen',ZA:'South Africa','4K':'4K','8K':'8K'};
        const regionFlags={AF:'🇦🇫',AE:'🇦🇪',AG:'🇦🇬',AL:'🇦🇱',AM:'🇦🇲',AR:'🇦🇷',AT:'🇦🇹',AU:'🇦🇺',AZ:'🇦🇿',BD:'🇧🇩',BE:'🇧🇪',BF:'🇧🇫',BG:'🇧🇬',BH:'🇧🇭',BJ:'🇧🇯',BO:'🇧🇴',BR:'🇧🇷',CA:'🇨🇦',CD:'🇨🇩',CG:'🇨🇬',CH:'🇨🇭',CL:'🇨🇱',CN:'🇨🇳',CO:'🇨🇴',CR:'🇨🇷',CV:'🇨🇻',CY:'🇨🇾',CZ:'🇨🇿',DE:'🇩🇪',DJ:'🇩🇯',DK:'🇩🇰',DO:'🇩🇴',DZ:'🇩🇿',EC:'🇪🇨',EE:'🇪🇪',EG:'🇪🇬',ER:'🇪🇷',ES:'🇪🇸',FI:'🇫🇮',FR:'🇫🇷',GE:'🇬🇪',GH:'🇬🇭',GM:'🇬🇲',GN:'🇬🇳',GR:'🇬🇷',HK:'🇭🇰',HN:'🇭🇳',HR:'🇭🇷',HT:'🇭🇹',HU:'🇭🇺',ID:'🇮🇩',IE:'🇮🇪',IL:'🇮🇱',IN:'🇮🇳',IQ:'🇮🇶',IR:'🇮🇷',IS:'🇮🇸',IT:'🇮🇹',JO:'🇯🇴',JP:'🇯🇵',KE:'🇰🇪',KH:'🇰🇭',KR:'🇰🇷',KW:'🇰🇼',KZ:'🇰🇿',LB:'🇱🇧',LK:'🇱🇰',LT:'🇱🇹',LV:'🇱🇻',LY:'🇱🇾',MA:'🇲🇦',MK:'🇲🇰',MR:'🇲🇷',MT:'🇲🇹',MX:'🇲🇽',MY:'🇲🇾',MZ:'🇲🇿',NA:'🇳🇦',NG:'🇳🇬',NL:'🇳🇱',NO:'🇳🇴',NP:'🇳🇵',NZ:'🇳🇿',OM:'🇴🇲',PA:'🇵🇦',PE:'🇵🇪',PH:'🇵🇭',PK:'🇵🇰',PL:'🇵🇱',PS:'🇵🇸',PT:'🇵🇹',PY:'🇵🇾',QA:'🇶🇦',RO:'🇷🇴',RU:'🇷🇺',SA:'🇸🇦',SD:'🇸🇩',SE:'🇸🇪',SG:'🇸🇬',SI:'🇸🇮',SK:'🇸🇰',SN:'🇸🇳',SO:'🇸🇴',SR:'🇸🇷',SY:'🇸🇾',TD:'🇹🇩',TG:'🇹🇬',TH:'🇹🇭',TN:'🇹🇳',TR:'🇹🇷',TW:'🇹🇼',TZ:'🇹🇿',UA:'🇺🇦',UK:'🇬🇧',US:'🇺🇸',UY:'🇺🇾',UZ:'🇺🇿',VE:'🇻🇪',VN:'🇻🇳',YE:'🇾🇪',ZA:'🇿🇦',AFR:'🌍',ASIA:'🌏',EXYU:'🌐',KU:'🌐',LA:'🌎',MC:'📻',RX:'✨',TS:'🎾',WT:'🏏','4K':'📺','8K':'📺'};
        const customRegionFlags={AG:'AG',AL:'AL',AM:'AM',AR:'AR',AT:'AT',AU:'AU',AZ:'AZ',BE:'BE',BG:'BG',BH:'BH',BO:'BO',BR:'BR',CA:'CA',CG:'CG',CH:'CH',CY:'CY',CZ:'CZ',DE:'DE',DK:'DK',ES:'ES',FI:'FI',FR:'FR',GE:'GE',GR:'GR',HK:'HK',HR:'HR',HU:'HU',ID:'ID',IE:'IE',IL:'IL',IR:'IR',IS:'IS',IT:'IT',JP:'JP',KR:'KR',KZ:'KZ',LT:'LT',MA:'MA',MK:'MK',MX:'MX',NL:'NL',NO:'NO',NZ:'NZ',PH:'PH',PL:'PL',PT:'PT',RO:'RO',RU:'RU',SE:'SE',SI:'SI',SR:'SR',SU:'SU',TH:'TH',TN:'TN',TR:'TR',UK:'UK',US:'US',UZ:'UZ',VE:'VE'};
        const forceCrispVerticalRegionFlag=function(icon,code){const normalized=String(code||'').toUpperCase();if(!icon||!['IT','FR','PT'].includes(normalized))return icon;icon.replaceChildren();icon.classList.add('is-custom','flag-'+normalized.toLowerCase());icon.style.setProperty('width','24px','important');icon.style.setProperty('min-width','24px','important');icon.style.setProperty('height','15px','important');icon.style.setProperty('border-radius','0','important');icon.style.setProperty('overflow','hidden','important');if(normalized==='IT'){icon.style.setProperty('background-color','#cd2e3f','important');icon.style.setProperty('background-image','linear-gradient(90deg,#168b55 0 8px,#fff 8px 16px,transparent 16px 100%)','important');}else if(normalized==='FR'){icon.style.setProperty('background-color','#ed2939','important');icon.style.setProperty('background-image','linear-gradient(90deg,#1e4aa5 0 8px,#fff 8px 16px,transparent 16px 100%)','important');}else{icon.style.setProperty('background-color','#d52b3b','important');icon.style.setProperty('background-image','linear-gradient(90deg,#168b55 0 10px,transparent 10px 100%)','important');}return icon;};
        const createRegionFlag=function(code){const icon=document.createElement('span');icon.className='openap-iptv-country-flag';icon.setAttribute('aria-hidden','true');const symbol=customRegionFlags[String(code||'').toUpperCase()];if(symbol){icon.classList.add('is-custom','flag-'+symbol.toLowerCase());icon.style.cssText='display:inline-flex;width:1.333em;min-width:1.333em;height:1em;align-items:center;justify-content:center;overflow:hidden;border-radius:3px;font-size:19px;line-height:1;';const image=document.createElement('img');image.src='/app/img/aptv-country-flags/'+symbol+'.svg?v=20261005-firefox';image.alt='';image.width=640;image.height=480;image.draggable=false;image.setAttribute('aria-hidden','true');image.style.cssText='display:block;width:100%;height:100%;object-fit:fill;';icon.append(image);}else icon.textContent=regionFlags[code]||'🌐';return icon;};
        const createRasterVerticalRegionFlag=function(icon,code){const normalized=String(code||'').toUpperCase();if(!icon||!['IT','FR','PT'].includes(normalized))return icon;icon.replaceChildren();const raster=document.createElement('img');raster.src='/app/img/aptv-live-flags-raster/'+normalized.toLowerCase()+'.png?v=20260922-0912';raster.alt='';raster.width=24;raster.height=15;raster.setAttribute('aria-hidden','true');raster.style.cssText='display:block;width:24px;height:15px;min-width:24px;object-fit:fill;image-rendering:auto;';icon.append(raster);icon.classList.add('is-custom','flag-'+normalized.toLowerCase());icon.style.cssText='display:inline-flex;width:24px;min-width:24px;height:15px;overflow:hidden;border:0;border-radius:3px;font-size:0;line-height:0;box-shadow:none;justify-self:center;align-items:center;justify-content:center;';return icon;};
        const upgradeRenderedRegionFlags=function(root){root?.querySelectorAll('.openap-iptv-country-flag:not(.is-custom)').forEach(function(icon){const code=icon.closest('[data-order-id]')?.dataset.orderId;const replacement=createRegionFlag(code);if(replacement.classList.contains('is-custom'))icon.replaceWith(replacement);});};
        if(liveList)new MutationObserver(function(){upgradeRenderedRegionFlags(liveList);}).observe(liveList,{childList:true,subtree:true});
        if(moviesList)new MutationObserver(function(){upgradeRenderedRegionFlags(moviesList);}).observe(moviesList,{childList:true,subtree:true});
        const updateLibrarySearchContext=function(){if(libraryKicker)libraryKicker.textContent=libraryKind==='series'?<?php echo json_encode(_("TV Series")); ?>:<?php echo json_encode(_("Movies")); ?>;if(!movieSearch)return;if(libraryKind!=='series'){movieSearch.placeholder=<?php echo json_encode(_("Search the entire movie catalog")); ?>;return;}if(moviesRegion&&moviesRegion!=='OTHER'){const country=regionNames[moviesRegion]||moviesRegion;movieSearch.placeholder=<?php echo json_encode(_("Search TV Series in")); ?>+' '+country;}else movieSearch.placeholder=<?php echo json_encode(_("Search the entire TV Series catalog")); ?>;};
        let liveItems = [];
        let liveLevel = 'regions';
        let liveRegion = '';
        let liveCategory = '';
        let liveCategoryCollapsed = false;
        let liveOffset = 0;
        let liveHasMore = false;
        let liveLoading = false;
        let liveRequestId = 0;
        let liveChannelTitle = '';
        let liveInitialized = false;
        let liveListScrollTop = 0;
        let liveGlobalSearchMode = false;
        let liveGlobalSearchTimer = 0;
        let liveGlobalSearchRequest = 0;
        let liveGlobalSearchReturn = null;
        let orderEditing = false;
        let orderSnapshot = [];
        let draggedOrderItem = null;
        const selectedOrderItems = new Set();
        const setOrderDragState=function(list,item,active){if(active)list?.classList.add('is-reordering');item?.classList.toggle('is-dragging',active);};
        const reorderListItemAtPoint=function(list,item,event){if(!list||!item||!event)return;const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('.openap-iptv-live-item');if(!target||target===item||target.parentNode!==list)return;const box=target.getBoundingClientRect(),before=event.clientY<box.top+box.height/2,reference=before?target:target.nextElementSibling;if(reference===item||(!reference&&item===list.lastElementChild)||(before&&item.nextElementSibling===target)||(!before&&item.previousElementSibling===target))return;if(reference)list.insertBefore(item,reference);else list.append(item);};
        const liveFavoritesKey='openap-aptv-live-favorites-v1',liveFavoritesRegion='__favorites__',liveFavoritesLabel=<?php echo json_encode(_('Favorites')); ?>;
        const hasInitialProvider=!document.querySelector('.openap-iptv-page.is-no-provider');
        if(!hasInitialProvider){try{window.localStorage.removeItem(liveFavoritesKey);}catch(error){}}
        let liveFavorites=[];
        const readLiveFavorites=function(){try{const saved=JSON.parse(window.localStorage.getItem(liveFavoritesKey)||'[]');liveFavorites=Array.isArray(saved)?saved.filter(function(item){return item&&/^\d{1,20}$/.test(String(item.item_id||''));}).map(function(item){return {item_id:String(item.item_id),name:String(item.name||item.label||''),label:String(item.label||item.name||''),country_code:String(item.country_code||''),icon_url:String(item.icon_url||'')};}):[];}catch(error){liveFavorites=[];}return liveFavorites;};
        const isLiveFavorite=function(item){return liveFavorites.some(function(favorite){return String(favorite.item_id)===String(item?.item_id);});};
        const toggleLiveFavorite=function(item){if(!item?.item_id)return;const id=String(item.item_id),index=liveFavorites.findIndex(function(favorite){return String(favorite.item_id)===id;});if(index>=0)liveFavorites.splice(index,1);else liveFavorites.push({item_id:id,name:String(item.name||item.label||''),label:String(item.label||item.name||''),country_code:String(item.country_code||''),icon_url:String(item.icon_url||'')});try{window.localStorage.setItem(liveFavoritesKey,JSON.stringify(liveFavorites));}catch(error){}if(liveRegion===liveFavoritesRegion){liveItems=liveFavorites.slice();liveOffset=liveItems.length;renderLiveItems();}else renderLiveItems();};
        readLiveFavorites();
        const playerSurface = document.querySelector('[data-openap-aptv-player]');
        const video = document.querySelector('[data-openap-aptv-video]');
        const hybridAudio = document.querySelector('[data-openap-aptv-hybrid-audio]');
        const webrtcFrame = document.querySelector('[data-openap-aptv-webrtc]');
        const playerState = document.querySelector('[data-openap-aptv-player-state]');
        const playerFeedback=document.querySelector('[data-openap-aptv-player-feedback]'),playerFeedbackPlay=playerFeedback?.querySelector('[data-openap-aptv-feedback-play]'),playerFeedbackLabel=playerFeedback?.querySelector('[data-openap-aptv-feedback-label]');
        const playerDiagnosticsToggle=document.querySelector('[data-openap-player-diagnostics-toggle]');
        const playerDiagnosticsModal=document.querySelector('[data-openap-player-diagnostics]');
        const playerDiagnosticsClose=playerDiagnosticsModal?.querySelector('[data-openap-player-diagnostics-close]'),playerDiagnosticsCopy=playerDiagnosticsModal?.querySelector('[data-openap-player-diagnostics-copy]'),playerDiagnosticsCopyStatus=playerDiagnosticsModal?.querySelector('[data-openap-player-diagnostics-copy-status]');
        const diagnosticChannel=playerDiagnosticsModal?.querySelector('[data-openap-player-diagnostics-channel]'),diagnosticState=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-state]'),diagnosticVideo=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-video]'),diagnosticAudio=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-audio]'),diagnosticCountry=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-country]'),diagnosticGroup=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-group]'),diagnosticResolution=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-resolution]'),diagnosticFramerate=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-framerate]'),diagnosticFrames=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-frames]'),diagnosticPresentationStalls=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-presentation-stalls]'),diagnosticBuffer=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-buffer]'),diagnosticBufferRange=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-buffer-range]'),diagnosticPlaylist=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-playlist]'),diagnosticLatency=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-latency]'),diagnosticRebuffers=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-rebuffers]'),diagnosticTargetDuration=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-target-duration]'),diagnosticSegment=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-segment]'),diagnosticBandwidth=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-bandwidth]'),diagnosticProviderStatus=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-status]'),diagnosticProviderIo=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-io]'),diagnosticProviderRange=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-range]'),diagnosticProviderBytes=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-bytes]'),diagnosticProviderLastByte=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-last-byte]'),diagnosticProviderHeartbeat=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-heartbeat]'),diagnosticProviderUpstreamError=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-upstream-error]'),diagnosticProviderError=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-provider-error]'),diagnosticRetries=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-retries]'),diagnosticMseError=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mse-error]'),diagnosticMseState=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mse-state]'),diagnosticMseSourceBuffers=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mse-source-buffers]'),diagnosticWatchdog=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-watchdog]'),diagnosticRecovery=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-recovery]'),diagnosticProfile=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-profile]'),diagnosticEvent=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-event]'),diagnosticEvents=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-events]'),diagnosticVodNodes=playerDiagnosticsModal?.querySelectorAll('[data-openap-diagnostic-vod]'),diagnosticContent=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-content]'),diagnosticSeasonEpisode=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-season-episode]'),diagnosticPosition=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-position]'),diagnosticProgress=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-progress]'),diagnosticSeek=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-seek]'),diagnosticMethod=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-method]'),diagnosticAudioTrack=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-audio-track]'),diagnosticSubtitles=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-subtitles]');
        const diagnosticProgressiveNodes=playerDiagnosticsModal?.querySelectorAll('[data-openap-diagnostic-progressive]'),diagnosticMpegtsNodes=playerDiagnosticsModal?.querySelectorAll('[data-openap-diagnostic-mpegts]'),diagnosticMpegtsState=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-state]'),diagnosticMpegtsTransfer=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-transfer]'),diagnosticMpegtsLastData=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-last-data]'),diagnosticMpegtsDemux=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-demux]'),diagnosticMpegtsError=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-error]'),diagnosticMpegtsBuffer=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mpegts-buffer]'),diagnosticSourceBitrate=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-source-bitrate]'),diagnosticProgressiveTransfer=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-progressive-transfer]'),diagnosticProgressiveRecent=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-progressive-recent]'),diagnosticProgressiveMinimum=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-progressive-minimum]'),diagnosticTransferMargin=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-transfer-margin]'),diagnosticMinimumMargin=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-minimum-margin]'),diagnosticBufferTrend=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-buffer-trend]'),diagnosticRelayLastOutput=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-relay-last-output]'),diagnosticRelayGap=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-relay-gap]'),diagnosticFfmpegState=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ffmpeg-state]'),diagnosticMseAppend=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-mse-append]'),diagnosticOttNodes=playerDiagnosticsModal?.querySelectorAll('[data-openap-diagnostic-ott]'),diagnosticOttProfile=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-profile]'),diagnosticOttMode=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-mode]'),diagnosticOttFragments=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-fragments]'),diagnosticOttBuffer=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-buffer]'),diagnosticOttPause=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-pause]'),diagnosticOttCache=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-cache]'),diagnosticOttTimeline=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-timeline]'),diagnosticOttServed=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-served]'),diagnosticOttAppended=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-appended]'),diagnosticOttRejected=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-rejected]'),diagnosticOttIntervals=playerDiagnosticsModal?.querySelector('[data-openap-diagnostic-ott-intervals]');
        const playerName = document.querySelector('[data-openap-aptv-channel-name]');
        const playerLogo = document.querySelector('[data-openap-aptv-channel-logo]');
        playerLogo?.addEventListener('load',function(){playerLogo.hidden=false;});
        playerLogo?.addEventListener('error',function(){
          const fallback=libraryKind==='series'?String(selectedSeries?.detail?.poster_url||selectedSeries?.item?.poster_url||''):'';
          const fallbackUrl=fallback?new URL(fallback,window.location.href).href:'';
          if(fallbackUrl&&playerLogo.src!==fallbackUrl){playerLogo.src=fallbackUrl;return;}
          playerLogo.hidden=true;
          playerLogo.removeAttribute('src');
        });
        const vodControls=document.querySelector('[data-openap-aptv-controls]'),vodSeek=document.querySelector('[data-openap-vod-seek]'),vodCurrent=document.querySelector('[data-openap-vod-current]'),vodDuration=document.querySelector('[data-openap-vod-duration]'),vodCacheTrack=document.querySelector('[data-openap-vod-cache-track]'),vodCacheFill=document.querySelector('[data-openap-vod-cache]'),vodBufferedFill=document.querySelector('[data-openap-vod-buffered]');
        const playerPlay=document.querySelector('[data-openap-player-play]'),playerMute=document.querySelector('[data-openap-player-mute]'),playerVolume=document.querySelector('[data-openap-player-volume]'),playerFullscreen=document.querySelector('[data-openap-player-fullscreen]'),playerMore=document.querySelector('[data-openap-player-more]'),playerSecondary=document.querySelector('[data-openap-player-secondary]'),audioToggle=document.querySelector('[data-openap-audio-toggle]'),audioMenu=document.querySelector('[data-openap-audio-menu]');
        const setPlayerMore=function(open){const active=Boolean(open);vodControls?.classList.toggle('is-more-open',active);playerMore?.setAttribute('aria-expanded',active?'true':'false');playerSecondary?.setAttribute('aria-hidden',active?'false':'true');};
        playerMore?.addEventListener('click',function(){setPlayerMore(!vodControls?.classList.contains('is-more-open'));});
        document.addEventListener('pointerdown',function(event){if(!vodControls?.classList.contains('is-more-open')||event.target?.closest?.('[data-openap-player-more],[data-openap-player-secondary]'))return;setPlayerMore(false);},true);
        const playerEpg=document.querySelector('[data-openap-player-epg]'),playerEpgToggle=document.querySelector('[data-openap-player-epg-toggle]'),playerEpgClose=document.querySelector('[data-openap-player-epg-close]'),playerEpgChannel=document.querySelector('[data-openap-player-epg-channel]'),playerEpgList=document.querySelector('[data-openap-player-epg-list]');
        const liveBufferFill=document.querySelector('[data-openap-live-buffer-fill]'),liveBufferValue=document.querySelector('[data-openap-live-buffer-value]');
        const liveBufferLabel=document.querySelector('[data-openap-live-buffer-label]');
        const liveBufferDefaultLabel=liveBufferLabel?.textContent||'Buffer';
        let liveBufferReceiver=null,liveBufferSample=null,liveBufferPending=null;
        const playerHud=document.querySelector('[data-openap-player-hud]'),playerQuality=document.querySelector('[data-openap-player-quality]'),playerResolution=document.querySelector('[data-openap-player-resolution]'),playerVideoCodec=document.querySelector('[data-openap-player-video-codec]'),playerAudioCodec=document.querySelector('[data-openap-player-audio-codec]'),playerFramerate=document.querySelector('[data-openap-player-framerate]');
        let aptvPlayer = null;
        // The public hostname adds an Internet/Cloudflare/VPS hop for every HLS
        // segment.  Keep LAN startup latency as-is, but retain a larger live
        // reserve when the same application is reached from the Internet.
        const aptvInternetPlayback = window.location.hostname.toLowerCase() === 'aptv.aptivi.online';
        const aptvLiveHlsOptions = aptvInternetPlayback
          ? {enableWorker:true,lowLatencyMode:false,backBufferLength:30,liveSyncDurationCount:16,liveMaxLatencyDurationCount:32,maxBufferLength:90,maxMaxBufferLength:180}
          : {enableWorker:true,lowLatencyMode:false,backBufferLength:20,liveSyncDurationCount:12,liveMaxLatencyDurationCount:24,maxBufferLength:60,maxMaxBufferLength:120};
        const aptvWallHlsOptions = aptvInternetPlayback
          ? {enableWorker:true,lowLatencyMode:false,backBufferLength:16,liveSyncDurationCount:6,liveMaxLatencyDurationCount:12,maxBufferLength:36,maxMaxBufferLength:72}
          : {enableWorker:true,lowLatencyMode:false,backBufferLength:10,liveSyncDurationCount:4};
        let aptvTranscodeToken = '';
        let playbackAttemptId = 0;
        let webrtcToken = '';
        let webrtcHeartbeat = 0;
        let vodPlayback=null,vodClock=0,vodHlsToken='',vodAudioPlayer=null,hybridSyncClock=0,liveBufferClock=0,vodProgressiveStatsClock=0,progressiveRecoveryClock=0,progressiveRecoveryActive=false,progressiveRecoveryTarget=0,progressiveRecoveryDeadline=0,progressiveRecoveryCooldownUntil=0,progressiveRelayRestartCount=0,progressiveRelayRestartActive=false,controlsFadeClock=0,vodSeeking=false,pendingVodSeek=null,vodRemuxAbort=null,vodRemuxObjectUrl='',vodPauseRestartClock=0,suppressVodPauseRestart=false,playerFeedbackPending='';
        let whepReader=null,whepScriptPromise=null,playerMode='live',playerLibraryKind='vod',currentLiveChannel=null,playerEpgRequest=0,playerEpgCloseClock=0,playerEpgOpening=false,playerTechnical={},vodTechnical={},frameRateCallback=0,frameRateSample=null,aptvMediaSessionMetadataKey='';
        const displayCodec=function(value){const codec=String(value||'').split(',')[0].trim().toLowerCase();if(!codec)return '';if(codec.includes('hvc1')||codec.includes('hev1')||codec.includes('hevc'))return 'HEVC';if(codec.includes('avc1')||codec.includes('avc3')||codec.includes('h264'))return 'H.264';if(codec.includes('av01'))return 'AV1';if(codec.includes('vp09')||codec.includes('vp9'))return 'VP9';if(codec.includes('mp4a')||codec.includes('aac'))return 'AAC';if(codec.includes('opus'))return 'Opus';if(codec.includes('ac-3')||codec==='ac3')return 'AC-3';if(codec.includes('ec-3')||codec.includes('eac3'))return 'E-AC-3';if(codec.includes('mp3'))return 'MP3';return '';};
        const qualityLabel=function(width,height){const edge=Math.max(Number(width)||0,Number(height)||0),short=Math.min(Number(width)||0,Number(height)||0);if(edge>=3840||short>=2160)return 'UHD';if(edge>=1920||short>=1080)return 'FULL HD';if(edge>=1280||short>=720)return 'HD';if(width&&height)return 'SD';return '';};
        const setHudValue=function(node,value){if(!node)return;node.textContent=value||'';node.hidden=!value;};
        const formatNetworkRate=function(value){const bps=Number(value)||0;if(bps<=0)return '--';return bps>=1000000?(bps/1000000).toFixed(2)+' Mbps':Math.round(bps/1000)+' kbps';};
        const playerDiagnostic=document.querySelector('[data-openap-player-diagnostic]');let playerDiagnosticsOpen=false,playerLiveProfile='copy',playerHlsPlaylistDuration=0,playerHlsSegmentCount=0,playerHlsTargetDuration=0,playerLiveLatency=0,playerPlaybackState='--',playerDecodedFrames=0,playerDroppedFrames=0,playerRebufferCount=0,playerRebufferDuration=0,playerRebufferStarted=0,playerPresentationStallCount=0,playerPresentationStallDuration=0,playerPresentationStallMax=0,playerPresentationStallStarted=0,playerLastPresentedAt=0,playerT0Pending=null,playerLastSegmentMs=0,playerLastSegmentStatus=0,playerEstimatedBandwidth=0,playerProgressiveTransferBps=0,playerProgressiveRecentBps=0,playerProgressiveMinimumBps=0,playerProgressiveRecentAvailable=false,playerProgressiveMinimumAvailable=false,playerProgressiveBufferSamples=[],playerProviderStatus=0,playerProviderHeartbeat=null,playerProviderError='',playerRetryCount=0,playerLastEvent='--',playerEventLog=[],playerProgressiveLastOutputAt=0,playerProgressiveOutputGapSeconds=0,playerProgressiveFfmpegState='',playerProgressiveMseAppend='--',playerMpegtsState='--',playerMpegtsBytes=0,playerMpegtsLastDataAt=0,playerMpegtsLastStatsAt=0,playerMpegtsStats='--',playerMpegtsMediaInfo='--',playerMpegtsLastError='--',playerMpegtsLoading='--',playerVodCacheActive=false,playerVodCacheComplete=false,playerVodCacheLimit=false,playerVodCacheStart=0,playerVodCacheEnd=0,playerVodCacheSeconds=0,playerVodCacheBytes=0,playerVodCacheError='',playerVodCacheClientPosition=null,playerVodCacheClientAge=0,playerVodCacheBehindBytes=0,playerVodCacheAheadBytes=0,playerVodCacheBehindSeconds=0,playerVodCacheLastAction='',playerVodCacheContiguous=true,playerVodCacheGap=null,playerVodCacheAheadThrottled=false,playerVodCacheAheadWaitSeconds=0,playerVodCacheLastPrune=null,playerOttTestActive=false,playerOttFragmentCount=0,playerOttFragmentBytes=0,playerOttBufferCap=0,playerOttPauseState='--',playerOttRelayRestartCount=0,playerMseFragments=[],playerMseLastAppended=null,playerMseLastRejected=null,playerMseError='',playerMseErrorName='--',playerMseFatal='--',playerMseReadyState='--',playerMseSourceBufferCount=null,playerWatchdogState='--',playerWatchdogLastProgressAt=0,playerWatchdogLastTime=0,playerWatchdogLastFrames=0,playerRecoveryAction='--',playerLiveIntent='unknown',playerLiveRecoveryActive=false,playerLiveRecoveryOnPlaying=null,playerMseTimescales=null,playerMseSourceBuffer=null,playerMseAppendedBytes=0,playerRelayState=null,playerVodCacheTfdtPin='';
        const recordPlayerEvent=function(message){const text=String(message||'').trim();if(!text)return;playerEventLog.push(new Date().toLocaleTimeString()+' '+text);if(playerEventLog.length>12){const fallbackIndex=playerEventLog.findIndex(function(item){return item.includes('Direct playback failed → HLS fallback');});if(fallbackIndex===0)playerEventLog.splice(1,1);else playerEventLog.shift();}};
        const syncAptvMediaSession=function(){const mediaSession=navigator.mediaSession;if(!mediaSession||!video)return;try{mediaSession.playbackState=video.paused?'paused':'playing';}catch(error){}if(playerMode!=='vod'||!vodPlayback){if(aptvMediaSessionMetadataKey){try{mediaSession.metadata=null;}catch(error){}aptvMediaSessionMetadataKey='';}return;}if(typeof MediaMetadata==='function'){const title=String(vodPlayback.title||playerName?.textContent||'APtv').trim()||'APtv',album=playerLibraryKind==='series'?'TV Series':'Movies',artwork=String(playerLogo?.currentSrc||playerLogo?.src||'').trim(),metadataKey=title+'|'+album+'|'+artwork;if(metadataKey!==aptvMediaSessionMetadataKey){try{const metadata={title:title,artist:'APtv',album:album};if(/^(?:https?:|blob:|data:)/i.test(artwork))metadata.artwork=[{src:artwork}];mediaSession.metadata=new MediaMetadata(metadata);aptvMediaSessionMetadataKey=metadataKey;}catch(error){}}}if(typeof mediaSession.setPositionState!=='function')return;const duration=Number(vodPlayback.duration)||Number(video.duration),position=Number(vodPlayback.position??((Number(vodPlayback.offset)||0)+(Number(video.currentTime)||0)));if(!Number.isFinite(duration)||duration<=0||!Number.isFinite(position))return;try{mediaSession.setPositionState({duration:duration,playbackRate:Number(video.playbackRate)||1,position:Math.max(0,Math.min(duration,position))});}catch(error){}};
        const setPlayerFeedback=function(mode,label){if(mode==='seeking'){playerStateSeekActive=true;if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=label||<?php echo json_encode(_('Seeking…')); ?>;}if(playerFeedback)playerFeedback.hidden=true;return;}if(playerStateSeekActive){playerStateSeekActive=false;if(playerState)playerState.hidden=true;}if(!playerFeedback)return;const active=mode==='paused';playerFeedback.hidden=!active;playerFeedback.dataset.state=active?'paused':'';if(playerFeedbackPlay)playerFeedbackPlay.hidden=!active;if(playerFeedbackLabel)playerFeedbackLabel.textContent=label||<?php echo json_encode(_('Video paused')); ?>;};
        playerFeedbackPlay?.addEventListener('click',function(){if(video?.paused)video.play().catch(function(){});});
        let playerFeedbackSeeking=false,playerStateSeekActive=false,suppressNextVodSeeking=false;
        const beginPlayerFeedbackSeek=function(){if(playerMode!=='vod')return;playerFeedbackSeeking=true;setPlayerFeedback('seeking');};
        const finishPlayerFeedbackPlayback=function(){playerFeedbackSeeking=false;setPlayerFeedback('');};
        vodSeek?.addEventListener('pointerdown',beginPlayerFeedbackSeek,true);
        vodSeek?.addEventListener('input',beginPlayerFeedbackSeek);
        document.addEventListener('click',function(event){if(event.target?.closest?.('[data-openap-vod-skip]'))beginPlayerFeedbackSeek();},true);
        video?.addEventListener('seeking',function(){if(playerMode==='vod'){if(suppressNextVodSeeking){suppressNextVodSeeking=false;return;}beginPlayerFeedbackSeek();}});
        video?.addEventListener('playing',finishPlayerFeedbackPlayback);
        video?.addEventListener('pause',function(){if(playerMode==='vod'&&!suppressVodPauseRestart&&!playerFeedbackSeeking&&!progressiveRecoveryActive)setPlayerFeedback('paused');});
        video?.addEventListener('play',function(){if(playerMode==='live'&&!playerLiveRecoveryActive)playerLiveIntent='want-play';});
        const setPlayerDiagnostic=function(message){playerLastEvent=String(message||'--');if(playerDiagnostic){playerDiagnostic.textContent=String(message||'');playerDiagnostic.hidden=!message;}if(message)recordPlayerEvent(message);if(playerDiagnosticsOpen&&playerHud)playerHud.hidden=false;};
        const captureHlsMseState=function(player){const mediaSource=player?.mediaSource||player?.coreComponents?.mediaSource||player?.coreComponents?.bufferController?.mediaSource||null;playerMseReadyState=String(mediaSource?.readyState||'unavailable');playerMseSourceBufferCount=mediaSource&&mediaSource.sourceBuffers&&typeof mediaSource.sourceBuffers.length==='number'?Number(mediaSource.sourceBuffers.length):null;};
        const samplePlaybackWatchdog=function(){if(!video||playerMode!=='live'){playerWatchdogState='--';return;}const now=performance.now(),current=Number(video.currentTime)||0,quality=video.getVideoPlaybackQuality?.(),frames=Number(quality?.totalVideoFrames)||Number(playerDecodedFrames)||0;if(!playerWatchdogLastProgressAt){playerWatchdogLastTime=current;playerWatchdogLastFrames=frames;playerWatchdogLastProgressAt=now;playerWatchdogState=video.paused?'paused':'waiting';playerWatchdogTimeDelta=0;playerWatchdogFrameDelta=0;return;}playerWatchdogTimeDelta=current-playerWatchdogLastTime;playerWatchdogFrameDelta=Math.max(0,frames-playerWatchdogLastFrames);const advanced=playerWatchdogTimeDelta>=.05||playerWatchdogFrameDelta>0;playerWatchdogLastTime=current;playerWatchdogLastFrames=frames;if(advanced){playerWatchdogLastProgressAt=now;playerWatchdogState='progressing';}else if(video.paused)playerWatchdogState='paused';else if(video.readyState<3)playerWatchdogState='waiting';else playerWatchdogState=now-playerWatchdogLastProgressAt>=2500?'frozen':'waiting';};
        const renderMseDiagnostics=function(){if(!diagnosticMseError)return;diagnosticMseError.textContent=playerMseError&&playerMseError!=='--'?[playerMseError,playerMseErrorName,playerMseFatal].filter(function(value){return value&&value!=='--';}).join(' · '):'--';diagnosticMseState.textContent=playerMseReadyState||'--';diagnosticMseSourceBuffers.textContent=playerMseSourceBufferCount===null?'--':String(playerMseSourceBufferCount);const age=playerWatchdogLastProgressAt?Math.max(0,(performance.now()-playerWatchdogLastProgressAt)/1000):0;diagnosticWatchdog.textContent=playerMode==='live'?(('t '+(Number(video?.currentTime)||0).toFixed(1)+' s · Δt '+(playerWatchdogTimeDelta>=0?'+':'')+playerWatchdogTimeDelta.toFixed(2)+' s · Δframes '+playerWatchdogFrameDelta+' · '+playerWatchdogState)+(playerWatchdogLastProgressAt?' · '+age.toFixed(1)+' s since progress':'')):'--';diagnosticRecovery.textContent=playerRecoveryAction||'--';};
        const captureHlsErrorDiagnostics=function(player,data){const detail=String(data?.details||data?.type||'HLS error'),errorName=String(data?.error?.name||data?.error?.constructor?.name||'--'),fatal=Boolean(data?.fatal);captureHlsMseState(player);playerMseError=detail;playerMseErrorName=errorName;playerMseFatal=fatal?'fatal':'recoverable';playerRecoveryAction=fatal?'fatal '+detail+' · recovery evaluation':'none · '+detail+' recoverable';recordPlayerEvent('HLS '+detail+' · '+playerMseFatal+' · '+errorName+' · MS '+playerMseReadyState+' · SB '+(playerMseSourceBufferCount===null?'--':playerMseSourceBufferCount));renderMseDiagnostics();return {detail:detail,errorName:errorName,fatal:fatal};};
        const resetMseDiagnostics=function(){playerMseError='';playerMseErrorName='--';playerMseFatal='--';playerMseReadyState='--';playerMseSourceBufferCount=null;playerWatchdogState='--';playerWatchdogLastProgressAt=0;playerWatchdogLastTime=0;playerWatchdogLastFrames=0;playerWatchdogTimeDelta=0;playerWatchdogFrameDelta=0;playerRecoveryAction='--';playerLiveIntent='unknown';playerLiveRecoveryActive=false;playerLiveRecoveryOnPlaying=null;playerMpegtsState='--';playerMpegtsBytes=0;playerMpegtsLastDataAt=0;playerMpegtsLastStatsAt=0;playerMpegtsStats='--';playerMpegtsMediaInfo='--';playerMpegtsLastError='--';playerMpegtsLoading='--';};
        const renderPresentationDiagnostics=function(){if(!diagnosticPresentationStalls)return;const active=playerPresentationStallStarted?Math.max(0,performance.now()-playerPresentationStallStarted):0,total=playerPresentationStallDuration+active,maximum=Math.max(playerPresentationStallMax,active);diagnosticPresentationStalls.textContent=video?.requestVideoFrameCallback?(playerPresentationStallCount+' · '+(total/1000).toFixed(1)+' s · max '+(maximum/1000).toFixed(1)+' s'):'--';};
        const resetPresentationDiagnostics=function(){playerPresentationStallCount=0;playerPresentationStallDuration=0;playerPresentationStallMax=0;playerPresentationStallStarted=0;playerLastPresentedAt=0;renderPresentationDiagnostics();};
        const progressiveBufferAhead=function(){if(!video?.buffered)return 0;for(let index=0;index<video.buffered.length;index++){const start=video.buffered.start(index),end=video.buffered.end(index);if(video.currentTime>=start&&video.currentTime<=end+.25)return Math.max(0,end-video.currentTime);}return 0;};
        const recordPauseResumeDecision=function(bufferAhead,relayState,relayStopped,playback){const reason=playback.forceRelayRestart?'forceRelayRestart':bufferAhead<4?'bufferAhead':relayStopped?'relayStopped':'buffered';recordPlayerEvent('Pause resume decision · buffer '+Number(bufferAhead||0).toFixed(1)+' s · relay '+(relayState||'--')+' · gap '+Number(playerProgressiveOutputGapSeconds||0).toFixed(1)+' s · force '+(playback.forceRelayRestart?'yes':'no')+' · restart '+(playback.restartOnResume?'yes':'no')+' · branch '+reason);};
        let playerPauseResumeSnapshot=null,playerPauseResumePending=null;
        const capturePauseResumeState=function(phase,playback,bufferAhead,relayState,relayStopped,target){const snapshot={phase:phase,position:Number(video?.currentTime)||0,savedPosition:Number(playback?.pausePosition??playback?.position??0),bufferAhead:Number(bufferAhead||0),relayState:String(relayState||'--'),relayStopped:Boolean(relayStopped),gap:Number(playerProgressiveOutputGapSeconds||0),force:Boolean(playback?.forceRelayRestart),restart:Boolean(playback?.restartOnResume),target:target===undefined?null:Number(target)};playerPauseResumeSnapshot=snapshot;recordPlayerEvent('Pause '+phase+' · pos '+snapshot.position.toFixed(3)+' s · saved '+snapshot.savedPosition.toFixed(3)+' s · buffer '+snapshot.bufferAhead.toFixed(1)+' s · relay '+snapshot.relayState+' · gap '+snapshot.gap.toFixed(1)+' s · force '+(snapshot.force?'yes':'no')+' · restart '+(snapshot.restart?'yes':'no')+(snapshot.target===null?'':' · target '+snapshot.target.toFixed(3)+' s'));return snapshot;};
        const renderVodCacheBar=function(){if(!vodCacheTrack||!vodCacheFill||!vodBufferedFill){return;}const playback=vodPlayback,duration=Number(playback?.duration)||0,cacheEnabled=Boolean(playback?.cache),progressiveEnabled=Boolean(playback?.direct&&playback?.fmp4),hlsEnabled=Boolean(playback?.hls),recordingEnabled=Boolean(playback?.recording),isProgressive=progressiveEnabled&&!cacheEnabled,isHls=hlsEnabled&&!cacheEnabled&&!progressiveEnabled;if(playerMode!=="vod"||(!cacheEnabled&&!progressiveEnabled&&!hlsEnabled&&!recordingEnabled)||duration<=0){vodCacheTrack.hidden=true;vodCacheTrack.classList.remove("is-recording");vodSeek?.classList.remove("is-recording-seek");vodSeek?.style.removeProperty("--openap-vod-seek-progress");return;}const offset=Math.max(0,Number(playback.offset)||0),current=Math.max(0,Math.min(duration,Number(playback.position)||offset+(Number(video?.currentTime)||0))),fallbackStart=Math.max(0,Number(playback.cacheStart)||0),cacheStart=Math.min(duration,playerVodCacheStart>0?playerVodCacheStart:fallbackStart),fallbackEnd=fallbackStart+Math.max(0,Number(playerVodCacheSeconds)||0),cacheEnd=Math.min(duration,playerVodCacheEnd>cacheStart?playerVodCacheEnd:fallbackEnd),cacheLeft=(isProgressive||isHls||recordingEnabled)?0:cacheStart/duration*100,cacheWidth=(isProgressive||isHls||recordingEnabled)?current/duration*100:Math.max(0,(cacheEnd-cacheStart)/duration*100);vodCacheTrack.hidden=false;vodCacheTrack.classList.toggle("is-progressive",isProgressive);vodCacheTrack.classList.toggle("is-hls",isHls);vodCacheTrack.classList.toggle("is-recording",recordingEnabled);vodSeek?.classList.toggle("is-recording-seek",recordingEnabled);if(vodSeek){if(recordingEnabled)vodSeek.style.setProperty("--openap-vod-seek-progress",Math.max(0,Math.min(100,current/duration*100)).toFixed(3)+"%");else vodSeek.style.removeProperty("--openap-vod-seek-progress");}vodCacheFill.style.left=cacheLeft.toFixed(3)+"%";vodCacheFill.style.width=cacheWidth.toFixed(3)+"%";let bufferedStart=0,bufferedEnd=0;if(video?.buffered){for(let index=0;index<video.buffered.length;index++){const start=offset+video.buffered.start(index),end=offset+video.buffered.end(index);if(!bufferedEnd||((video.currentTime>=video.buffered.start(index)-.25)&&(video.currentTime<=video.buffered.end(index)+.25))){bufferedStart=start;bufferedEnd=end;if(video.currentTime>=video.buffered.start(index)-.25&&video.currentTime<=video.buffered.end(index)+.25)break;}}}const bufferedLeft=Math.max(isProgressive||recordingEnabled?current:cacheStart,Math.min(duration,bufferedStart)),bufferedRight=Math.max(bufferedLeft,Math.min(duration,bufferedEnd));vodBufferedFill.style.left=(bufferedLeft/duration*100).toFixed(3)+"%";vodBufferedFill.style.width=(Math.max(0,(bufferedRight-bufferedLeft)/duration*100)).toFixed(3)+"%";vodCacheTrack.title=recordingEnabled?("Playback: "+formatVodTime(current)+" · browser buffer: "+Math.max(0,bufferedRight-current).toFixed(1)+" s"):isProgressive?("Playback: "+formatVodTime(current)+" · browser buffer: "+Math.max(0,bufferedRight-current).toFixed(1)+" s"):isHls?("Playback: "+formatVodTime(current)+" · browser buffer: "+Math.max(0,bufferedRight-current).toFixed(1)+" s"):("VPS cache: "+Math.max(0,cacheEnd-cacheStart).toFixed(1)+" s · browser buffer: "+Math.max(0,bufferedRight-(offset+(Number(video?.currentTime)||0))).toFixed(1)+" s");};
        const sampleProgressiveBuffer=function(forceEmpty){if(playerMode!=='vod'||!vodPlayback?.direct)return;const now=Date.now(),ahead=forceEmpty?0:progressiveBufferAhead();playerProgressiveBufferSamples.push({time:now,ahead:ahead});playerProgressiveBufferSamples=playerProgressiveBufferSamples.filter(function(sample){return sample.time>=now-30000;});};
        const formatMeasuredRate=function(value,available){return !available?'--':(Number(value)>0?formatNetworkRate(value):'0 kbps');};
        const formatTransferMargin=function(transfer,source,available){if(!available||source<=0)return '--';const margin=(Number(transfer)/source-1)*100;return(margin>=0?'+':'')+margin.toFixed(1)+'%';};
        const setLiveDiagnosticValue=function(node,value){if(node&&node.textContent!==value)node.textContent=value;};
        const renderCommonLiveDiagnostics=function(){
          if(playerMode!=='live')return;
          setLiveDiagnosticValue(diagnosticContent,'Live stream');
          setLiveDiagnosticValue(diagnosticMethod,playerLiveProfile==='live-fmp4'?'Live fMP4/MSE':((video?.srcObject||webrtcToken)?'WebRTC':'HLS'));
          setLiveDiagnosticValue(diagnosticVideo,displayCodec(playerTechnical.videoCodec)||(playerLiveProfile==='live-fmp4'?'H.264':'--'));
          setLiveDiagnosticValue(diagnosticAudio,displayCodec(playerTechnical.audioCodec)||(playerLiveProfile==='live-fmp4'?'AAC':'--'));
          setLiveDiagnosticValue(diagnosticAudioTrack,playerTechnical.audioCodec?'Default live track · '+displayCodec(playerTechnical.audioCodec):'Default live track');
          setLiveDiagnosticValue(diagnosticSubtitles,'Not available');
        };
        const liveDiagnosticObserver=window.MutationObserver?new MutationObserver(function(){renderCommonLiveDiagnostics();}):null;
        [diagnosticContent,diagnosticMethod,diagnosticAudioTrack,diagnosticSubtitles].forEach(function(node){if(node)liveDiagnosticObserver?.observe(node,{characterData:true,childList:true,subtree:true});});
        playerDiagnosticsCopy?.addEventListener('click',renderCommonLiveDiagnostics,true);
        playerDiagnosticsCopy?.addEventListener('click',function(){if(playerMode==='live'&&playerLiveProfile==='live-fmp4'){setLiveDiagnosticValue(diagnosticMethod,'Live fMP4/MSE');setLiveDiagnosticValue(diagnosticVideo,displayCodec(playerTechnical.videoCodec)||'H.264');setLiveDiagnosticValue(diagnosticAudio,displayCodec(playerTechnical.audioCodec)||'AAC');}},true);
        playerDiagnosticsToggle?.addEventListener('click',function(){window.setTimeout(renderCommonLiveDiagnostics,0);});
        window.setInterval(function(){if(playerDiagnosticsModal&&!playerDiagnosticsModal.hidden)renderCommonLiveDiagnostics();},520);
        const renderLiveFmp4Diagnostics=function(){
          if(playerMode!=='live'||playerLiveProfile!=='live-fmp4')return;
          if(playerProgressiveFfmpegState==='running'&&/^\[(?:h264|aac|mp4)\b/i.test(String(playerProviderError||'')))playerProviderError='';
          diagnosticProgressiveNodes?.forEach(function(node){node.hidden=false;});
          if(diagnosticSourceBitrate)diagnosticSourceBitrate.textContent='--';
          if(diagnosticProgressiveTransfer)diagnosticProgressiveTransfer.textContent='--';
          if(diagnosticProgressiveRecent)diagnosticProgressiveRecent.textContent='--';
          if(diagnosticProgressiveMinimum)diagnosticProgressiveMinimum.textContent='--';
          if(diagnosticTransferMargin)diagnosticTransferMargin.textContent='--';
          if(diagnosticMinimumMargin)diagnosticMinimumMargin.textContent='--';
          if(diagnosticRelayLastOutput)diagnosticRelayLastOutput.textContent=playerProgressiveLastOutputAt?new Date(playerProgressiveLastOutputAt*1000).toLocaleTimeString():'--';
          if(diagnosticRelayGap)diagnosticRelayGap.textContent=Number.isFinite(playerProgressiveOutputGapSeconds)?playerProgressiveOutputGapSeconds.toFixed(1)+' s':'--';
          if(diagnosticFfmpegState)diagnosticFfmpegState.textContent=playerProgressiveFfmpegState||'--';
          if(diagnosticMseAppend)diagnosticMseAppend.textContent=playerProgressiveMseAppend||'--';
        };
        const renderMpegtsDiagnostics=function(){
          const active=playerMode==='live'&&playerLiveProfile==='mpegts';
          diagnosticMpegtsNodes?.forEach(function(node){node.hidden=!active;});
          if(!active)return;
          diagnosticPlaylist.textContent='--';diagnosticLatency.textContent='--';diagnosticTargetDuration.textContent='--';diagnosticSegment.textContent='--';diagnosticBandwidth.textContent='--';diagnosticProviderStatus.textContent='--';diagnosticProviderIo.textContent='--';diagnosticProviderRange.textContent='--';diagnosticProviderBytes.textContent='--';diagnosticProviderLastByte.textContent='--';diagnosticProviderHeartbeat.textContent='--';diagnosticProviderUpstreamError.textContent='--';diagnosticProviderError.textContent='--';
          const age=playerMpegtsLastStatsAt?Math.max(0,(performance.now()-playerMpegtsLastStatsAt)/1000):null;
          diagnosticMpegtsState.textContent=playerMpegtsState||'--';
          diagnosticMpegtsTransfer.textContent=playerMpegtsBytes>0?Math.round(playerMpegtsBytes/1048576)+' MiB'+(playerMpegtsStats!=='--'?' · '+playerMpegtsStats:''):'--';
          diagnosticMpegtsLastData.textContent=playerMpegtsLastDataAt?(age===null?'--':age.toFixed(1)+' s ago'):'--';
          diagnosticMpegtsDemux.textContent=playerMpegtsMediaInfo||'--';
          diagnosticMpegtsError.textContent=playerMpegtsLastError||'none';
          const ranges=[];if(video?.buffered){for(let index=0;index<video.buffered.length;index++)ranges.push(video.buffered.start(index).toFixed(1)+'–'+video.buffered.end(index).toFixed(1));}
          diagnosticMpegtsBuffer.textContent=(video?.readyState!==undefined?'readyState '+video.readyState:'readyState --')+' · '+(ranges.length?ranges.join(' · ')+' · ahead '+Math.max(0,progressiveBufferAhead()).toFixed(1)+' s':'empty');
        };
        const renderProgressiveDiagnostics=function(){
          renderVodCacheBar();
          const ottActive=playerMode==='vod'&&Boolean(vodPlayback?.direct)&&Boolean(vodPlayback?.fmp4)&&playerOttTestActive;
          diagnosticOttNodes?.forEach(function(node){node.hidden=!ottActive;});
          if(diagnosticOttProfile)diagnosticOttProfile.textContent=ottActive?'ACTIVE · VOD fMP4':'--';
          if(diagnosticOttMode)diagnosticOttMode.textContent=ottActive?'moof+mdat per fragment':'--';
          if(diagnosticOttFragments)diagnosticOttFragments.textContent=ottActive?String(playerOttFragmentCount)+' · '+Math.round(playerOttFragmentBytes/1048576)+' MiB':'--';
          if(diagnosticOttBuffer)diagnosticOttBuffer.textContent=ottActive?playerOttBufferCap.toFixed(0)+' s':'--';
          if(diagnosticOttPause)diagnosticOttPause.textContent=ottActive?playerOttPauseState:'--';
          if(diagnosticOttCache)diagnosticOttCache.textContent=ottActive&&vodPlayback?.cache?((playerVodCacheActive?'ACTIVE':'READY')+' · '+formatVodTime(playerVodCacheSeconds)+' · '+Math.round(playerVodCacheBytes/1048576)+' MiB · behind '+formatVodTime(playerVodCacheBehindSeconds)+' · ahead '+formatVodTime(playerVodCacheAheadSeconds)+(playerVodCacheAheadThrottled?' · throttle':'')+(playerVodCacheLastAction?' · '+playerVodCacheLastAction:'')+(vodPlayback.progressiveSession?' · '+String(vodPlayback.progressiveSession).slice(0,8):'')):'--';
          if(diagnosticOttTimeline)diagnosticOttTimeline.textContent=ottActive&&vodPlayback?.cache?((playerVodCacheContiguous?formatVodTime(playerVodCacheStart)+'–'+formatVodTime(playerVodCacheEnd)+' · continuous':('GAP '+formatVodTime(playerVodCacheGap?.start)+'–'+formatVodTime(playerVodCacheGap?.end)))+(playerVodCacheTfdtPin?' · tfdt pin '+playerVodCacheTfdtPin:'')):'--';
          if(diagnosticOttServed)diagnosticOttServed.textContent=ottActive?mp4RelaySummary(playerRelayState):'--';
          if(diagnosticOttAppended)diagnosticOttAppended.textContent=ottActive?mp4FormatFragment(playerMseLastAppended,mp4RelayLabel(playerMseAppendedBytes-(playerMseLastAppended?.bytes||0))):'--';
          if(diagnosticOttRejected)diagnosticOttRejected.textContent=ottActive?(playerMseLastRejected?mp4FormatFragment(playerMseLastRejected,mp4RelayLabel(playerMseAppendedBytes))+' · '+String(playerMseLastRejected.error||playerMseError||''):'none'):'--';
          if(diagnosticOttIntervals)diagnosticOttIntervals.textContent=ottActive?mp4Buffered(playerMseSourceBuffer):'--';
          const progressive=(playerMode==='vod'&&Boolean(vodPlayback?.direct))||(playerMode==='live'&&playerLiveProfile==='live-fmp4'),sourceBitrate=Number(vodTechnical?.source_bitrate)||0,transfer=Number(playerProgressiveTransferBps)||0,recent=Number(playerProgressiveRecentBps)||0,minimum=Number(playerProgressiveMinimumBps)||0;
          diagnosticProgressiveNodes?.forEach(function(node){node.hidden=!progressive;});
          if(progressive&&playerMode==='live')diagnosticMethod.textContent='Live fMP4/MSE';
          if(progressive&&playerMode==='live'&&/^\[(?:h264|aac|mp4)\b/i.test(String(playerProviderError||'')))playerProviderError='';
          if(diagnosticSourceBitrate)diagnosticSourceBitrate.textContent=progressive?formatNetworkRate(sourceBitrate):'--';
          if(diagnosticProgressiveTransfer)diagnosticProgressiveTransfer.textContent=progressive?formatNetworkRate(transfer):'--';
          if(diagnosticProgressiveRecent)diagnosticProgressiveRecent.textContent=progressive?formatMeasuredRate(recent,playerProgressiveRecentAvailable):'--';
          if(diagnosticProgressiveMinimum)diagnosticProgressiveMinimum.textContent=progressive?formatMeasuredRate(minimum,playerProgressiveMinimumAvailable):'--';
          if(diagnosticTransferMargin)diagnosticTransferMargin.textContent=progressive?formatTransferMargin(recent,sourceBitrate,playerProgressiveRecentAvailable):'--';
          if(diagnosticMinimumMargin)diagnosticMinimumMargin.textContent=progressive?formatTransferMargin(minimum,sourceBitrate,playerProgressiveMinimumAvailable):'--';
          if(diagnosticRelayLastOutput)diagnosticRelayLastOutput.textContent=progressive&&playerProgressiveLastOutputAt?new Date(playerProgressiveLastOutputAt*1000).toLocaleTimeString():'--';if(diagnosticRelayGap)diagnosticRelayGap.textContent=progressive&&Number.isFinite(playerProgressiveOutputGapSeconds)?playerProgressiveOutputGapSeconds.toFixed(1)+' s':'--';if(diagnosticFfmpegState)diagnosticFfmpegState.textContent=progressive?(playerProgressiveFfmpegState||'--'):'--';if(diagnosticMseAppend)diagnosticMseAppend.textContent=progressive?(playerProgressiveMseAppend||'--'):'--';
          const providerHeartbeat=progressive&&playerProviderHeartbeat&&typeof playerProviderHeartbeat==='object'?playerProviderHeartbeat:null;
          if(diagnosticProviderIo)diagnosticProviderIo.textContent=providerHeartbeat?String(providerHeartbeat.state||'--')+(Number(providerHeartbeat.range_attempt)>0?' · attempt '+String(providerHeartbeat.range_attempt):''):'--';
          if(diagnosticProviderRange)diagnosticProviderRange.textContent=providerHeartbeat?((providerHeartbeat.range_requested||providerHeartbeat.range||'--')+(providerHeartbeat.range&&providerHeartbeat.range!==providerHeartbeat.range_requested?' → '+providerHeartbeat.range:'')+(Number(providerHeartbeat.next_offset)>0?' · next '+String(providerHeartbeat.next_offset):'')+(providerHeartbeat.total_size_reported!==null&&providerHeartbeat.total_size_reported!==undefined?' · total '+String(providerHeartbeat.total_size_reported):'')):'--';
          if(diagnosticProviderBytes)diagnosticProviderBytes.textContent=providerHeartbeat?(Math.round((Number(providerHeartbeat.bytes_received)||0)/1048576)+' MiB'+(Number(providerHeartbeat.bytes_per_second)>0?' · '+Math.round(Number(providerHeartbeat.bytes_per_second)*8/1000)+' kbps':'')+(Number(providerHeartbeat.http_status)>0?' · HTTP '+String(providerHeartbeat.http_status):'')):'--';
          if(diagnosticProviderLastByte)diagnosticProviderLastByte.textContent=providerHeartbeat&&Number(providerHeartbeat.last_byte_at)>0?new Date(Number(providerHeartbeat.last_byte_at)*1000).toLocaleTimeString():'--';
          if(diagnosticProviderHeartbeat)diagnosticProviderHeartbeat.textContent=providerHeartbeat?('age '+Number(providerHeartbeat.age_seconds||0).toFixed(1)+' s · gap '+Number(providerHeartbeat.current_gap_seconds||0).toFixed(1)+' s'):'--';
          if(diagnosticProviderUpstreamError)diagnosticProviderUpstreamError.textContent=providerHeartbeat?((providerHeartbeat.error_class||'none')+(Number(providerHeartbeat.curl_errno)>0?' · cURL '+String(providerHeartbeat.curl_errno):'')+(providerHeartbeat.curl_error?' · '+String(providerHeartbeat.curl_error):'')):'--';
          if(diagnosticBufferTrend){const samples=playerProgressiveBufferSamples;if(!progressive||!samples.length)diagnosticBufferTrend.textContent='--';else{const values=samples.map(function(sample){return sample.ahead;}),minimumBuffer=Math.min(...values),maximumBuffer=Math.max(...values),delta=values[values.length-1]-values[0];diagnosticBufferTrend.textContent='min '+minimumBuffer.toFixed(1)+' s · max '+maximumBuffer.toFixed(1)+' s · Δ '+(delta>=0?'+':'')+delta.toFixed(1)+' s';}}
        };
        const renderPlayerDiagnostics=function(){if(!playerDiagnosticsModal)return;const width=Number(video?.videoWidth)||Number(playerTechnical.width)||0,height=Number(video?.videoHeight)||Number(playerTechnical.height)||0,fps=Number(playerTechnical.fps)||0,isVod=playerMode==='vod',playback=vodPlayback||{},position=Math.max(0,Number(playback.position)||Number(video?.currentTime)||0),duration=Math.max(0,Number(playback.duration)||Number(video?.duration)||0),progress=duration?Math.min(100,position/duration*100):0,track=Array.isArray(playback.tracks)?playback.tracks[Number(playback.audio)||0]:null;let ahead=0,behind=0,ranges=[];if(video?.buffered){for(let index=0;index<video.buffered.length;index++){const start=video.buffered.start(index),end=video.buffered.end(index);ranges.push(start.toFixed(1)+'–'+end.toFixed(1));if(video.currentTime>=start&&video.currentTime<=end+.25){ahead=Math.max(0,end-video.currentTime);behind=Math.max(0,video.currentTime-start);}}}const quality=video?.getVideoPlaybackQuality?.();if(quality){playerDecodedFrames=Number(quality.totalVideoFrames)||playerDecodedFrames;playerDroppedFrames=Number(quality.droppedVideoFrames)||playerDroppedFrames;}diagnosticVodNodes?.forEach(function(node){node.hidden=!isVod;});diagnosticChannel.textContent=currentLiveChannel?.name||playerName?.textContent||'--';diagnosticState.textContent=playerPlaybackState;diagnosticVideo.textContent=displayCodec(playerTechnical.videoCodec)||'--';diagnosticAudio.textContent=displayCodec(playerTechnical.audioCodec)||'--';diagnosticCountry.textContent=isVod?(selectedSeries?.item?.country_code||vodTechnical?.country_code||'--'):(liveRegion||'--');diagnosticGroup.textContent=isVod?(selectedSeries?.item?.group_name||moviesCategoryTitle||'--'):(liveChannelTitle||'--');diagnosticResolution.textContent=width&&height?width+' × '+height:'--';diagnosticFramerate.textContent=fps?(Math.round(fps*100)/100)+' FPS':'--';diagnosticFrames.textContent=(playerDecodedFrames||playerDroppedFrames)?playerDecodedFrames+' / '+playerDroppedFrames:'--';diagnosticBuffer.textContent=isVod?ahead.toFixed(1)+' s':(liveBufferValue?.textContent||'--');diagnosticBufferRange.textContent=ranges.length?ranges.join(' · ')+' · ahead '+ahead.toFixed(1)+' s':'--';diagnosticPlaylist.textContent=playerHlsSegmentCount?playerHlsSegmentCount+' segments · '+playerHlsPlaylistDuration.toFixed(1)+' s':'--';diagnosticLatency.textContent=playerLiveLatency?playerLiveLatency.toFixed(1)+' s':'--';diagnosticRebuffers.textContent=playerRebufferCount+' · '+(playerRebufferDuration/1000).toFixed(1)+' s';diagnosticTargetDuration.textContent=playerHlsTargetDuration?playerHlsTargetDuration.toFixed(1)+' s':'--';diagnosticSegment.textContent=playerLastSegmentStatus?(playerLastSegmentStatus+' · '+(playerLastSegmentMs>0?playerLastSegmentMs+' ms':'n/a')):'--';diagnosticBandwidth.textContent=playerEstimatedBandwidth?Math.round(playerEstimatedBandwidth/1000)+' kbps':'--';diagnosticProviderStatus.textContent=playerProviderStatus?String(playerProviderStatus):'--';diagnosticProviderError.textContent=playerProviderError||'--';diagnosticRetries.textContent=String(playerRetryCount);diagnosticProfile.textContent=isVod?(playback.hls?'vod-hls':playback.direct?(playback.fmp4?'progressive-fmp4':'progressive'):playback.remux?'audio-remux':playback.started?'webrtc':'vod') : playerLiveProfile;diagnosticEvent.textContent=playerLastEvent;diagnosticEvents.textContent=playerEventLog.length?playerEventLog.join(' | '):'--';diagnosticContent.textContent=isVod?(playerLibraryKind==='series'?'Series':'Movie'):'--';diagnosticSeasonEpisode.textContent=isVod&&playerLibraryKind==='series'?((typeof seriesActiveSeason!=='undefined'&&seriesActiveSeason)?'Season '+seriesActiveSeason+' · ':'')+(playback.title||'Episode'):'--';diagnosticPosition.textContent=isVod?formatVodTime(position)+' / '+formatVodTime(duration):'--';diagnosticProgress.textContent=isVod?progress.toFixed(1)+'%':'--';diagnosticSeek.textContent=isVod?(vodSeek&&!vodSeek.disabled?'Available':'Unavailable'):'--';diagnosticMethod.textContent=isVod?(playback.hls?'HLS':playback.direct?(playback.fmp4?'Progressive fMP4':'Progressive'):playback.remux?'Remux':playback.started?'WebRTC':'Preparing'):'--';diagnosticAudioTrack.textContent=isVod?(track?[(track.title||track.language||'und'),(track.codec||'audio')].join(' · '):'Default audio'):'--';diagnosticSubtitles.textContent=isVod?'Not available':'--';};
        const renderPlayerTechnical=function(){if(!playerHud)return;const width=Number(video?.videoWidth)||Number(playerTechnical.width)||0,height=Number(video?.videoHeight)||Number(playerTechnical.height)||0,fps=Number(playerTechnical.fps)||0;setHudValue(playerQuality,qualityLabel(width,height));setHudValue(playerResolution,width&&height?width+' × '+height:'');setHudValue(playerVideoCodec,displayCodec(playerTechnical.videoCodec));setHudValue(playerAudioCodec,displayCodec(playerTechnical.audioCodec));setHudValue(playerFramerate,fps>0?(Math.round(fps*100)/100)+' FPS':'');playerHud.hidden=false;};
        const stopFrameRateMeasurement=function(){if(frameRateCallback&&video?.cancelVideoFrameCallback)video.cancelVideoFrameCallback(frameRateCallback);frameRateCallback=0;frameRateSample=null;playerLastPresentedAt=0;if(playerPresentationStallStarted)playerPresentationStallCount=Math.max(0,playerPresentationStallCount-1);playerPresentationStallStarted=0;};
        const measurePlayerFrameRate=function(){stopFrameRateMeasurement();if(!video?.requestVideoFrameCallback)return;const sample=function(now,metadata){if(!['live','vod'].includes(playerMode)||video.paused||video.hidden){frameRateCallback=0;playerLastPresentedAt=0;return;}if(playerPresentationStallStarted){const stalled=Math.max(0,now-playerPresentationStallStarted);playerPresentationStallDuration+=stalled;playerPresentationStallMax=Math.max(playerPresentationStallMax,stalled);playerPresentationStallStarted=0;if(!playerRebufferStarted)playerPlaybackState='Playing';recordPlayerEvent('Video frames resumed · '+(stalled/1000).toFixed(1)+' s');renderPlayerDiagnostics();}playerLastPresentedAt=now;if(playerT0Pending&&playerT0Pending.attemptId===playbackAttemptId&&vodPlayback?.direct){const presented=(Number(vodPlayback.offset)||0)+(Number.isFinite(Number(metadata.mediaTime))?Number(metadata.mediaTime):Number(video.currentTime)||0),error=presented-playerT0Pending.target,wait=Math.max(0,now-playerT0Pending.issuedAt);recordPlayerEvent('T0 first frame · target '+formatVodTime(playerT0Pending.target)+' · presented '+formatVodTime(presented)+' · error '+(error>=0?'+':'')+error.toFixed(3)+' s · wait '+Math.round(wait)+' ms');playerT0Pending=null;renderPlayerDiagnostics();}const frames=Number(metadata.presentedFrames)||0,mediaTime=Number(metadata.mediaTime)||0;if(!frameRateSample)frameRateSample={frames:frames,mediaTime:mediaTime};else{const elapsed=mediaTime-frameRateSample.mediaTime,advanced=frames-frameRateSample.frames;if(elapsed>=1&&advanced>0){const fps=advanced/elapsed;if(fps>=1&&fps<=240){playerTechnical.fps=fps;renderPlayerTechnical();}frameRateSample={frames:frames,mediaTime:mediaTime};}}frameRateCallback=video.requestVideoFrameCallback(sample);};frameRateCallback=video.requestVideoFrameCallback(sample);};
        window.setInterval(function(){const supported=Boolean(video?.requestVideoFrameCallback),eligible=supported&&['live','vod'].includes(playerMode)&&!video.paused&&!video.hidden&&!vodSeeking&&document.visibilityState==='visible'&&video.readyState>=3;if(!eligible){if(playerPresentationStallStarted){playerPresentationStallCount=Math.max(0,playerPresentationStallCount-1);playerPresentationStallStarted=0;}playerLastPresentedAt=0;return;}const now=performance.now();if(playerLastPresentedAt&&now-playerLastPresentedAt>=800&&!playerPresentationStallStarted){playerPresentationStallStarted=playerLastPresentedAt;playerPresentationStallCount++;playerPlaybackState='Frame stalled';recordPlayerEvent('Video presentation stalled');renderPlayerDiagnostics();}},250);
        const resetPlayerTechnical=function(){stopFrameRateMeasurement();playerTechnical={};playerT0Pending=null;playerLiveProfile='copy';playerHlsPlaylistDuration=0;playerHlsSegmentCount=0;playerHlsTargetDuration=0;playerLiveLatency=0;playerPlaybackState='Connecting';playerDecodedFrames=0;playerDroppedFrames=0;playerRebufferCount=0;playerRebufferDuration=0;playerRebufferStarted=0;playerLastSegmentMs=0;playerLastSegmentStatus=0;playerEstimatedBandwidth=0;playerProviderStatus=0;playerProviderHeartbeat=null;playerProviderError='';playerRetryCount=0;playerLastEvent='--';playerEventLog=[];playerDiagnosticsOpen=false;if(playerDiagnosticsToggle)playerDiagnosticsToggle.hidden=true;if(playerDiagnosticsModal)playerDiagnosticsModal.hidden=true;if(playerHud){playerHud.hidden=true;playerHud.classList.remove('is-idle');Array.from(playerHud.children).forEach(function(node){node.hidden=true;node.textContent='';});}};
        const closePlayerDiagnostics=function(){if(!playerDiagnosticsModal)return;playerDiagnosticsOpen=false;playerDiagnosticsModal.hidden=true;playerDiagnosticsModal.classList.remove('is-closing');playerDiagnosticsToggle?.setAttribute('aria-pressed','false');};
        const openPlayerDiagnostics=function(){if(!playerDiagnosticsModal)return;if(!playerDiagnosticsModal.hidden){closePlayerDiagnostics();return;}playerDiagnosticsOpen=true;samplePlaybackWatchdog();renderMseDiagnostics();renderPlayerDiagnostics();renderPresentationDiagnostics();renderProgressiveDiagnostics();renderMpegtsDiagnostics();playerDiagnosticsModal.hidden=false;playerDiagnosticsModal.classList.remove('is-closing');playerDiagnosticsToggle?.setAttribute('aria-pressed','true');};
        playerDiagnosticsToggle?.addEventListener('click',openPlayerDiagnostics);
        playerDiagnosticsClose?.addEventListener('click',closePlayerDiagnostics);
        playerDiagnosticsCopy?.addEventListener('click',function(){if(playerMode==='live'&&playerLiveProfile==='mpegts')recordPlayerEvent('MPEG-TS snapshot · state '+playerMpegtsState+' · bytes '+Math.round(playerMpegtsBytes/1048576)+' MiB · last data '+(playerMpegtsLastDataAt?((performance.now()-playerMpegtsLastDataAt)/1000).toFixed(1)+' s ago':'--')+' · stats '+playerMpegtsStats+' · error '+playerMpegtsLastError);},true);
        const copyPlayerDiagnosticsFallback=function(text){
          const heartbeat=playerProviderHeartbeat&&typeof playerProviderHeartbeat==='object'?playerProviderHeartbeat:null;
          if(heartbeat)text+="\nProvider I/O: "+String(heartbeat.state||'--')+"\nProvider range: "+String(heartbeat.range_requested||heartbeat.range||'--')+(heartbeat.range&&heartbeat.range!==heartbeat.range_requested?' → '+String(heartbeat.range):'')+"\nProvider bytes: "+String(heartbeat.bytes_received||0)+"\nProvider heartbeat: age "+Number(heartbeat.age_seconds||0).toFixed(1)+" s · gap "+Number(heartbeat.current_gap_seconds||0).toFixed(1)+" s\nProvider error: "+String(heartbeat.error_class||'none')+(heartbeat.curl_errno?' · cURL '+String(heartbeat.curl_errno):'')+(heartbeat.curl_error?' · '+String(heartbeat.curl_error):'');
          const fallback=document.createElement('textarea');fallback.value=text;fallback.setAttribute('readonly','');fallback.style.position='fixed';fallback.style.left='-9999px';fallback.style.top='0';fallback.style.opacity='0';document.body.append(fallback);fallback.focus();fallback.select();fallback.setSelectionRange(0,fallback.value.length);let copied=false;try{copied=document.execCommand('copy');}catch(error){}fallback.remove();return copied;
        };
        playerDiagnosticsCopy?.addEventListener('click',async function(){
          samplePlaybackWatchdog();renderMseDiagnostics();renderPlayerDiagnostics();renderPresentationDiagnostics();renderProgressiveDiagnostics();renderMpegtsDiagnostics();
          const lines=['APtv video diagnostics','Channel: '+(diagnosticChannel?.textContent||'--'),'Country: '+(diagnosticCountry?.textContent||'--'),'Group: '+(diagnosticGroup?.textContent||'--'),'State: '+(diagnosticState?.textContent||'--'),'Content: '+(diagnosticContent?.textContent||'--'),'Season / episode: '+(diagnosticSeasonEpisode?.textContent||'--'),'Position / duration: '+(diagnosticPosition?.textContent||'--'),'Progress: '+(diagnosticProgress?.textContent||'--'),'Seek: '+(diagnosticSeek?.textContent||'--'),'Playback method: '+(diagnosticMethod?.textContent||'--'),'Audio track: '+(diagnosticAudioTrack?.textContent||'--'),'Subtitles: '+(diagnosticSubtitles?.textContent||'--'),'Video: '+(diagnosticVideo?.textContent||'--'),'Audio: '+(diagnosticAudio?.textContent||'--'),'Resolution: '+(diagnosticResolution?.textContent||'--'),'Frame rate: '+(diagnosticFramerate?.textContent||'--'),'Decoded / dropped frames: '+(diagnosticFrames?.textContent||'--'),'Presentation stalls: '+(diagnosticPresentationStalls?.textContent||'--'),'Immediate buffer: '+(diagnosticBuffer?.textContent||'--'),'Buffer range: '+(diagnosticBufferRange?.textContent||'--'),'Buffer trend (30 s): '+(diagnosticBufferTrend?.textContent||'--'),'HLS playlist: '+(diagnosticPlaylist?.textContent||'--'),'Live latency: '+(diagnosticLatency?.textContent||'--'),'Rebuffers: '+(diagnosticRebuffers?.textContent||'--'),'Target duration: '+(diagnosticTargetDuration?.textContent||'--'),'Last segment: '+(diagnosticSegment?.textContent||'--'),'Estimated bandwidth: '+(diagnosticBandwidth?.textContent||'--'),'Source bitrate: '+(diagnosticSourceBitrate?.textContent||'--'),'Progressive transfer: '+(diagnosticProgressiveTransfer?.textContent||'--'),'Transfer (10 s): '+(diagnosticProgressiveRecent?.textContent||'--'),'Minimum transfer (60 s): '+(diagnosticProgressiveMinimum?.textContent||'--'),'Transfer margin (10 s): '+(diagnosticTransferMargin?.textContent||'--'),'Minimum margin (60 s): '+(diagnosticMinimumMargin?.textContent||'--'),'Relay last output: '+(diagnosticRelayLastOutput?.textContent||'--'),'Relay output gap: '+(diagnosticRelayGap?.textContent||'--'),'FFmpeg state: '+(diagnosticFfmpegState?.textContent||'--'),'MSE append: '+(diagnosticMseAppend?.textContent||'--'),'OTT test: '+(diagnosticOttProfile?.textContent||'--'),'Append mode: '+(diagnosticOttMode?.textContent||'--'),'Fragments appended: '+(diagnosticOttFragments?.textContent||'--'),'OTT buffer cap: '+(diagnosticOttBuffer?.textContent||'--'),'Pause restart: '+(diagnosticOttPause?.textContent||'--'),'VPS cache: '+(diagnosticOttCache?.textContent||'--'),'Cache timeline: '+(diagnosticOttTimeline?.textContent||'--'),'Relay served: '+(diagnosticOttServed?.textContent||'--'),'MSE fragment: '+(diagnosticOttAppended?.textContent||'--'),'MSE rejected: '+(diagnosticOttRejected?.textContent||'--'),'MSE intervals: '+(diagnosticOttIntervals?.textContent||'--'),'Provider status: '+(diagnosticProviderStatus?.textContent||'--'),'Player / HLS message: '+(diagnosticProviderError?.textContent||'--'),'Retries: '+(diagnosticRetries?.textContent||'0'),'Profile: '+(diagnosticProfile?.textContent||'--'),'Last event: '+(diagnosticEvent?.textContent||'--'),'Recent events: '+(diagnosticEvents?.textContent||'--')],text=lines.join('\n');
          let copied=copyPlayerDiagnosticsFallback(text);
          if(!copied&&navigator.clipboard?.writeText){try{await navigator.clipboard.writeText(text);copied=true;}catch(error){}}
          if(playerDiagnosticsCopyStatus)playerDiagnosticsCopyStatus.textContent=copied?<?php echo json_encode(_("Copied")); ?>:<?php echo json_encode(_("Copy unavailable")); ?>;window.setTimeout(function(){if(playerDiagnosticsCopyStatus)playerDiagnosticsCopyStatus.textContent='';},2200);
        });
        playerDiagnosticsCopy?.addEventListener('click',async function(event){event.preventDefault();event.stopImmediatePropagation();samplePlaybackWatchdog();renderMseDiagnostics();renderPlayerDiagnostics();renderPresentationDiagnostics();renderProgressiveDiagnostics();const lines=['APtv video diagnostics','Channel: '+(diagnosticChannel?.textContent||'--'),'Country: '+(diagnosticCountry?.textContent||'--'),'Group: '+(diagnosticGroup?.textContent||'--'),'State: '+(diagnosticState?.textContent||'--'),'Content: '+(diagnosticContent?.textContent||'--'),'Season / episode: '+(diagnosticSeasonEpisode?.textContent||'--'),'Position / duration: '+(diagnosticPosition?.textContent||'--'),'Progress: '+(diagnosticProgress?.textContent||'--'),'Seek: '+(diagnosticSeek?.textContent||'--'),'Playback method: '+(diagnosticMethod?.textContent||'--'),'Audio track: '+(diagnosticAudioTrack?.textContent||'--'),'Subtitles: '+(diagnosticSubtitles?.textContent||'--'),'Video: '+(diagnosticVideo?.textContent||'--'),'Audio: '+(diagnosticAudio?.textContent||'--'),'Resolution: '+(diagnosticResolution?.textContent||'--'),'Frame rate: '+(diagnosticFramerate?.textContent||'--'),'Decoded / dropped frames: '+(diagnosticFrames?.textContent||'--'),'Presentation stalls: '+(diagnosticPresentationStalls?.textContent||'--'),'Immediate buffer: '+(diagnosticBuffer?.textContent||'--'),'Buffer range: '+(diagnosticBufferRange?.textContent||'--'),'Buffer trend (30 s): '+(diagnosticBufferTrend?.textContent||'--'),'HLS playlist: '+(diagnosticPlaylist?.textContent||'--'),'Live latency: '+(diagnosticLatency?.textContent||'--'),'Rebuffers: '+(diagnosticRebuffers?.textContent||'--'),'Target duration: '+(diagnosticTargetDuration?.textContent||'--'),'Last segment: '+(diagnosticSegment?.textContent||'--'),'Estimated bandwidth: '+(diagnosticBandwidth?.textContent||'--'),'Source bitrate: '+(diagnosticSourceBitrate?.textContent||'--'),'Progressive transfer: '+(diagnosticProgressiveTransfer?.textContent||'--'),'Transfer (10 s): '+(diagnosticProgressiveRecent?.textContent||'--'),'Minimum transfer (60 s): '+(diagnosticProgressiveMinimum?.textContent||'--'),'Transfer margin (10 s): '+(diagnosticTransferMargin?.textContent||'--'),'Minimum margin (60 s): '+(diagnosticMinimumMargin?.textContent||'--'),'Relay last output: '+(diagnosticRelayLastOutput?.textContent||'--'),'Relay output gap: '+(diagnosticRelayGap?.textContent||'--'),'FFmpeg state: '+(diagnosticFfmpegState?.textContent||'--'),'MSE append: '+(diagnosticMseAppend?.textContent||'--'),'OTT test: '+(diagnosticOttProfile?.textContent||'--'),'Append mode: '+(diagnosticOttMode?.textContent||'--'),'Fragments appended: '+(diagnosticOttFragments?.textContent||'--'),'OTT buffer cap: '+(diagnosticOttBuffer?.textContent||'--'),'Pause restart: '+(diagnosticOttPause?.textContent||'--'),'VPS cache: '+(diagnosticOttCache?.textContent||'--'),'Cache timeline: '+(diagnosticOttTimeline?.textContent||'--'),'Relay served: '+(diagnosticOttServed?.textContent||'--'),'MSE fragment: '+(diagnosticOttAppended?.textContent||'--'),'MSE rejected: '+(diagnosticOttRejected?.textContent||'--'),'MSE intervals: '+(diagnosticOttIntervals?.textContent||'--'),'Provider status: '+(diagnosticProviderStatus?.textContent||'--'),'Player / HLS message: '+(diagnosticProviderError?.textContent||'--'),'Retries: '+(diagnosticRetries?.textContent||'0'),'MSE error: '+(diagnosticMseError?.textContent||'--'),'MediaSource state: '+(diagnosticMseState?.textContent||'--'),'SourceBuffers: '+(diagnosticMseSourceBuffers?.textContent||'--'),'Playback watchdog: '+(diagnosticWatchdog?.textContent||'--'),'Recovery action: '+(diagnosticRecovery?.textContent||'--'),'Profile: '+(diagnosticProfile?.textContent||'--'),'Last event: '+(diagnosticEvent?.textContent||'--'),'Recent events: '+(diagnosticEvents?.textContent||'--')],text=lines.join('\n');let copied=copyPlayerDiagnosticsFallback(text);if(!copied&&navigator.clipboard?.writeText){try{await navigator.clipboard.writeText(text);copied=true;}catch(error){}}if(playerDiagnosticsCopyStatus)playerDiagnosticsCopyStatus.textContent=copied?<?php echo json_encode(_("Copied")); ?>:<?php echo json_encode(_("Copy unavailable")); ?>;window.setTimeout(function(){if(playerDiagnosticsCopyStatus)playerDiagnosticsCopyStatus.textContent='';},2200);},true);
        window.setInterval(function(){if(playerDiagnosticsModal&&!playerDiagnosticsModal.hidden){samplePlaybackWatchdog();renderMseDiagnostics();renderPlayerDiagnostics();renderPresentationDiagnostics();renderProgressiveDiagnostics();renderMpegtsDiagnostics();renderLiveFmp4Diagnostics();}},500);
        const updateTrackTechnical=function(track){if(!track?.getSettings)return;const settings=track.getSettings();if(track.kind==='video'){if(Number(settings.width)>0)playerTechnical.width=Number(settings.width);if(Number(settings.height)>0)playerTechnical.height=Number(settings.height);if(Number(settings.frameRate)>0)playerTechnical.fps=Number(settings.frameRate);}renderPlayerTechnical();};
        const updateHlsTechnical=function(data){const tracks=data?.tracks||data||{},videoTrack=tracks.video||tracks.videoTrack,audioTrack=tracks.audio||tracks.audioTrack;if(videoTrack){playerTechnical.videoCodec=videoTrack.codec||videoTrack.levelCodec||playerTechnical.videoCodec;if(Number(videoTrack.metadata?.width||videoTrack.width)>0)playerTechnical.width=Number(videoTrack.metadata?.width||videoTrack.width);if(Number(videoTrack.metadata?.height||videoTrack.height)>0)playerTechnical.height=Number(videoTrack.metadata?.height||videoTrack.height);}if(audioTrack)playerTechnical.audioCodec=audioTrack.codec||audioTrack.levelCodec||playerTechnical.audioCodec;renderPlayerTechnical();};
        const attachVodHlsDiagnostics=function(hls){if(!hls||!window.Hls)return;hls.on(window.Hls.Events.MANIFEST_LOADED,function(event,data){const details=data?.networkDetails;playerProviderStatus=Number(details?.status)||playerProviderStatus;recordPlayerEvent('VOD manifest loaded'+(playerProviderStatus?' · HTTP '+playerProviderStatus:''));renderPlayerDiagnostics();});hls.on(window.Hls.Events.LEVEL_LOADED,function(event,data){const details=data?.details||{},fragments=Array.isArray(details.fragments)?details.fragments:[];playerHlsSegmentCount=fragments.length;playerHlsPlaylistDuration=Number(details.totalduration)||fragments.reduce(function(total,fragment){return total+(Number(fragment.duration)||0);},0);playerHlsTargetDuration=Number(details.targetduration)||Number(details.targetDuration)||playerHlsTargetDuration;renderPlayerDiagnostics();});hls.on(window.Hls.Events.FRAG_LOADED,function(event,data){const details=data?.networkDetails,stats=data?.stats||{},start=Number(stats.loading?.start),end=Number(stats.loading?.end);playerLastSegmentStatus=Number(details?.status)||200;playerLastSegmentMs=Number.isFinite(start)&&Number.isFinite(end)?Math.max(0,Math.round(end-start)):0;playerEstimatedBandwidth=Number(stats.bwEstimate)||Number(hls.bandwidthEstimate)||playerEstimatedBandwidth;recordPlayerEvent('VOD segment loaded · HTTP '+playerLastSegmentStatus+(playerLastSegmentMs?' · '+playerLastSegmentMs+' ms':''));renderPlayerDiagnostics();});hls.on(window.Hls.Events.ERROR,function(event,data){if(!data)return;const detail=String(data.details||data.type||'HLS error'),status=Number(data.networkDetails?.status)||0;if(status)playerProviderStatus=status;playerProviderError=detail;setPlayerDiagnostic(detail);renderPlayerDiagnostics();});};
        const stopProgressiveRecovery=function(){if(progressiveRecoveryClock){window.clearInterval(progressiveRecoveryClock);progressiveRecoveryClock=0;}progressiveRecoveryActive=false;progressiveRecoveryTarget=0;progressiveRecoveryDeadline=0;};
        const startProgressiveRecovery=function(){
          if(playerMode!=='vod'||!vodPlayback?.direct||vodSeeking||video.ended||progressiveRecoveryActive||performance.now()<progressiveRecoveryCooldownUntil)return;
          const attemptId=playbackAttemptId,firstRecovery=playerRebufferCount<=1,moviePlayback=playerLibraryKind==='vod',maxWait=moviePlayback?7000:(firstRecovery?12000:20000);
          progressiveRecoveryTarget=moviePlayback?4:(firstRecovery?8:10);progressiveRecoveryDeadline=performance.now()+maxWait;progressiveRecoveryActive=true;
          recordPlayerEvent('Adaptive buffer recovery · target '+progressiveRecoveryTarget+' s');playerPlaybackState='Recovering buffer';renderPlayerDiagnostics();
          const check=function(){
            if(attemptId!==playbackAttemptId||playerMode!=='vod'||!vodPlayback?.direct){stopProgressiveRecovery();return;}
            sampleProgressiveBuffer(false);const ahead=progressiveBufferAhead(),expired=performance.now()>=progressiveRecoveryDeadline;
            if(ahead>=progressiveRecoveryTarget||expired){const target=progressiveRecoveryTarget;stopProgressiveRecovery();progressiveRecoveryCooldownUntil=performance.now()+4000;recordPlayerEvent((expired?'Buffer recovery timeout':'Buffer recovered')+' · '+ahead.toFixed(1)+' / '+target+' s');video.play().catch(function(error){if(error?.name==='NotAllowedError'&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Press play to resume the movie.")); ?>;}});return;}
            if(!video.paused&&video.readyState>=3)video.pause();playerPlaybackState='Recovering buffer';renderPlayerDiagnostics();renderProgressiveDiagnostics();
          };
          progressiveRecoveryClock=window.setInterval(check,250);check();
        };
        const restartProgressiveRelay=function(reason){if(playerMode!=='vod'||!vodPlayback?.direct||!vodPlayback.fmp4||progressiveRelayRestartActive||progressiveRelayRestartCount>=1)return false;const playback=vodPlayback,bufferAhead=progressiveBufferAhead();if(video?.paused||bufferAhead>4){playback.relayRestartDeferred=true;if(!playback.relayRestartDeferredLogged){playback.relayRestartDeferredLogged=true;playerOttPauseState=video?.paused?'relay restart deferred until resume':'relay restart deferred · buffered data';recordPlayerEvent('Progressive fMP4 relay restart deferred · '+(video?.paused?'paused':'buffer '+bufferAhead.toFixed(1)+' s'));renderProgressiveDiagnostics();}return true;}const target=Math.max(0,Math.max(Number(playback.position)||0,(Number(playback.offset)||0)+(Number(video.currentTime)||0)));progressiveRelayRestartActive=true;progressiveRelayRestartCount++;stopProgressiveRecovery();playerRecoveryAction='progressive fMP4 relay restart from '+formatVodTime(target);recordPlayerEvent('Progressive fMP4 relay stalled · restarting from '+formatVodTime(target)+' · attempt '+String(progressiveRelayRestartCount));renderPlayerDiagnostics();startDirectVod(playback.id,playback.title,playback.duration,playback.tracks,target,null,false,false,false);return true;};
          const stopProgressiveStats=function(){stopProgressiveRecovery();progressiveRecoveryCooldownUntil=0;if(vodProgressiveStatsClock){window.clearInterval(vodProgressiveStatsClock);vodProgressiveStatsClock=0;}playerProgressiveTransferBps=0;playerProgressiveRecentBps=0;playerProgressiveMinimumBps=0;playerProgressiveRecentAvailable=false;playerProgressiveMinimumAvailable=false;playerProgressiveBufferSamples=[];playerProgressiveLastOutputAt=0;playerProgressiveOutputGapSeconds=0;playerProgressiveFfmpegState='';playerProgressiveMseAppend='--';playerMseFragments=[];playerMseLastAppended=null;playerMseLastRejected=null;playerMseError='';playerMseTimescales=null;playerMseSourceBuffer=null;playerMseAppendedBytes=0;playerRelayState=null;playerProviderHeartbeat=null;playerVodCacheTfdtPin='';playerVodCacheActive=false;playerVodCacheComplete=false;playerVodCacheLimit=false;playerVodCacheStart=0;playerVodCacheEnd=0;playerVodCacheSeconds=0;playerVodCacheBytes=0;playerVodCacheError='';playerVodCacheClientPosition=null;playerVodCacheClientAge=0;playerVodCacheBehindBytes=0;playerVodCacheAheadBytes=0;playerVodCacheBehindSeconds=0;playerVodCacheAheadSeconds=0;playerVodCacheLastAction='';playerVodCacheContiguous=true;playerVodCacheGap=null;playerVodCacheAheadThrottled=false;playerVodCacheAheadWaitSeconds=0;playerVodCacheLastPrune=null;renderVodCacheBar();renderProgressiveDiagnostics();};
          const startProgressiveStats=function(session,attemptId){stopProgressiveStats();if(!/^[a-f0-9]{32}$/.test(String(session||'')))return;const poll=function(){if(attemptId!==playbackAttemptId||!vodPlayback?.direct||vodPlayback.progressiveSession!==session)return;sampleProgressiveBuffer(false);const statsQuery=new URLSearchParams({session:session,position:String(Math.max(0,Number(vodPlayback?.position)||0))});fetch('/aptv/api/vod/direct-stats?'+statsQuery.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(payload){if(attemptId!==playbackAttemptId||!payload?.ok||vodPlayback?.progressiveSession!==session)return;playerProgressiveTransferBps=Number(payload.transfer_bps)||0;playerProgressiveRecentBps=Number(payload.transfer_10s_bps)||0;playerProgressiveMinimumBps=Number(payload.transfer_min_60s_bps)||0;playerProgressiveRecentAvailable=Boolean(payload.recent_available);playerProgressiveMinimumAvailable=Boolean(payload.minimum_available);playerProgressiveLastOutputAt=Number(payload.last_output_at)||0;playerProgressiveOutputGapSeconds=Number(payload.output_gap_seconds)||0;playerProgressiveFfmpegState=payload.active?'running'+(payload.ffmpeg_stderr?' · '+String(payload.ffmpeg_stderr):''):((payload.ffmpeg_exit!==null&&payload.ffmpeg_exit!==undefined)?'exit '+String(payload.ffmpeg_exit):((Number(payload.output_gap_seconds)>5)?'no output'+(payload.ffmpeg_stderr?' · '+String(payload.ffmpeg_stderr):''):(payload.ffmpeg_stderr||'stopped')));playerVodCacheActive=Boolean(payload.cache_active);playerVodCacheComplete=Boolean(payload.cache_complete);playerVodCacheLimit=Boolean(payload.cache_limit);playerVodCacheStart=Number(payload.cache_start)||0;playerVodCacheEnd=Number(payload.cache_end)||0;playerVodCacheSeconds=Number(payload.cache_seconds)||Math.max(0,playerVodCacheEnd-playerVodCacheStart);playerVodCacheBytes=Number(payload.cache_bytes)||0;playerVodCacheError=String(payload.cache_error||'');playerVodCacheClientPosition=payload.cache_client_position===null||payload.cache_client_position===undefined?null:Number(payload.cache_client_position);playerVodCacheClientAge=Number(payload.cache_client_age_seconds)||0;playerVodCacheBehindBytes=Number(payload.cache_behind_bytes)||0;playerVodCacheAheadBytes=Number(payload.cache_ahead_bytes)||0;playerVodCacheBehindSeconds=Number(payload.cache_behind_seconds)||0;playerVodCacheAheadSeconds=Number(payload.cache_ahead_seconds)||0;playerVodCacheLastAction=String(payload.cache_last_action||'');playerVodCacheContiguous=payload.cache_contiguous!==false;playerVodCacheGap=payload.cache_gap&&typeof payload.cache_gap==='object'?payload.cache_gap:null;playerVodCacheAheadThrottled=Boolean(payload.cache_ahead_throttled);playerVodCacheAheadWaitSeconds=Number(payload.cache_ahead_wait_seconds)||0;playerVodCacheLastPrune=payload.cache_last_prune||null;playerVodCacheTfdtPin=String(payload.cache_last_tfdt_pin||'');playerRelayState=payload.relay&&typeof payload.relay==='object'?payload.relay:null;if(playerVodCacheLimit&&vodPlayback.cache&&!vodPlayback.cacheLimitFallback){vodPlayback.cacheLimitFallback=true;if(vodPlayback.cacheOnly){recordPlayerEvent('VPS cache limit reached · cache-only test stopped');playerProviderError='VPS_CACHE_LIMIT · cache-only playback stopped';renderProgressiveDiagnostics();renderPlayerDiagnostics();return;}recordPlayerEvent('VPS cache limit reached · switching to direct stream');startDirectVod(vodPlayback.id,vodPlayback.title,vodPlayback.duration,vodPlayback.tracks,Math.max(0,Number(vodPlayback.position)||0),null,true);return;}if(Number(payload.provider_status)>0)playerProviderStatus=Number(payload.provider_status);const streamError=String(payload.stream_error||'');const providerError=String(payload.provider_error||'');const relayStalled=Boolean(vodPlayback?.fmp4)&&((streamError&&/stalled|startup timeout|premature|ffmpeg/i.test(streamError))||(!payload.active&&Number(payload.output_gap_seconds)>10));if(relayStalled){if(restartProgressiveRelay(streamError||'relay stopped'))return;if(!video.paused&&progressiveBufferAhead()>4){if(/^PROGRESSIVE_FMP4_RELAY_FAILED/.test(playerProviderError)){playerProviderError='';playerPlaybackState='Playing';playerRecoveryAction='progressive fMP4 playing from buffered data';recordPlayerEvent('Progressive fMP4 resumed from buffered data · relay error cleared');if(playerState)playerState.hidden=true;}renderVodCacheBar();renderProgressiveDiagnostics();renderPlayerDiagnostics();return;}playerProviderError='PROGRESSIVE_FMP4_RELAY_FAILED · '+(streamError||'The progressive fMP4 relay stopped.');playerPlaybackState='Provider error';if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=playerProviderError;}renderPlayerDiagnostics();return;}if(payload.active&&Number(payload.output_gap_seconds)<=5&&/^PROGRESSIVE_FMP4_RELAY_FAILED/.test(playerProviderError)){playerProviderError='';progressiveRelayRestartActive=false;if(!video.paused){playerPlaybackState='Playing';if(playerState)playerState.hidden=true;}recordPlayerEvent('Progressive fMP4 relay output resumed · error cleared');}if(streamError)playerProviderError=streamError;else if(providerError)playerProviderError=providerError;renderVodCacheBar();renderProgressiveDiagnostics();renderPlayerDiagnostics();}).catch(function(){});};poll();vodProgressiveStatsClock=window.setInterval(poll,2000);};
        const applyVodTechnical=function(probe,audio){const videoInfo=probe?.video||{},audioTracks=Array.isArray(probe?.audio)?probe.audio:[],audioInfo=audioTracks[Number(audio)||0]||audioTracks[0]||{};playerTechnical.width=Number(videoInfo.width)||playerTechnical.width||0;playerTechnical.height=Number(videoInfo.height)||playerTechnical.height||0;playerTechnical.videoCodec=videoInfo.codec||playerTechnical.videoCodec||'';playerTechnical.audioCodec=probe?.playback_class==='hls_audio_transcode'?'aac':(audioInfo.codec||playerTechnical.audioCodec||'');renderPlayerTechnical();};
        const updateWebRtcCodec=async function(receiver,kind){if(!receiver?.getStats)return;try{const stats=await receiver.getStats(),codecs=new Map();stats.forEach(function(report){if(report.type==='codec')codecs.set(report.id,report);});stats.forEach(function(report){if(report.type!=='inbound-rtp'||report.kind&&report.kind!==kind||!report.codecId)return;const codec=codecs.get(report.codecId)?.mimeType;if(codec)playerTechnical[kind==='audio'?'audioCodec':'videoCodec']=codec;});renderPlayerTechnical();}catch(error){}};
        const closePlayerEpg=function(){playerEpgRequest++;playerEpgOpening=false;playerEpgList?.classList.remove('is-recording-state-pending');playerEpgToggle?.classList.remove('is-active');window.clearTimeout(playerEpgCloseClock);if(!playerEpg||playerEpg.hidden)return;playerEpg.classList.add('is-closing');playerEpgCloseClock=window.setTimeout(function(){playerEpg.hidden=true;playerEpg.classList.remove('is-closing');playerEpgCloseClock=0;},300);};
        const animatePlayerEpgContent=function(){if(!playerEpgList||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;playerEpgList.classList.remove('is-content-entering');void playerEpgList.offsetWidth;playerEpgList.classList.add('is-content-entering');window.setTimeout(function(){playerEpgList?.classList.remove('is-content-entering');},460);};
        const renderPlayerEpgMessage=function(message,loading){if(!playerEpgList)return;playerEpgList.classList.remove('is-content-entering');playerEpgList.replaceChildren();const state=document.createElement('div');state.className='openap-aptv-player-epg-empty';state.classList.toggle('is-loading',loading);if(loading){const icon=document.createElement('i');icon.className='fas fa-circle-notch fa-spin';icon.setAttribute('aria-hidden','true');state.append(icon);}const text=document.createElement('span');text.textContent=message;state.append(text);playerEpgList.append(state);};
        const renderPlayerEpgPrograms=function(programs){
          if(!playerEpgList)return;playerEpgList.replaceChildren();
          if(!programs.length){renderPlayerEpgMessage(<?php echo json_encode(_("EPG not available")); ?>,false);return;}
          const now=Math.floor(Date.now()/1000);
          programs.forEach(function(program){const item=document.createElement('article');item.className='openap-aptv-player-epg-item';item.classList.toggle('is-now',Number(program.start)<=now&&Number(program.end)>now);const copy=document.createElement('div');const time=document.createElement('time');time.textContent=epgTime(Number(program.start))+' – '+epgTime(Number(program.end));const title=document.createElement('strong');title.textContent=program.title||<?php echo json_encode(_("Untitled programme")); ?>;copy.append(time,title);const picker=createRecordingDestinationPicker();const record=document.createElement('button');record.type='button';record.innerHTML='<i class="fas fa-circle"></i><span>'+<?php echo json_encode(_("Record")); ?>+'</span>';bindRecordingButton(record,currentLiveChannel,program,picker);const actions=document.createElement('span');actions.className='openap-recording-actions';actions.append(record,picker);item.append(copy,actions);playerEpgList.append(item);});updateRecordingDestinationPickers();playerEpgList.querySelectorAll('.openap-aptv-player-epg-item>button,.openap-recording-actions>button').forEach(updateRecordingButton);animatePlayerEpgContent();
        };
        const openPlayerEpg=async function(){
          if(!playerEpg||playerEpgOpening)return;window.clearTimeout(playerEpgCloseClock);playerEpgCloseClock=0;playerEpg.classList.remove('is-closing');playerEpg.hidden=false;playerEpgOpening=true;playerEpgList?.classList.add('is-recording-state-pending');playerEpgToggle?.classList.add('is-active');if(playerEpgChannel)playerEpgChannel.textContent=currentLiveChannel?.name||<?php echo json_encode(_("Live channel")); ?>;renderPlayerEpgMessage(<?php echo json_encode(_("Loading channel guide…")); ?>,true);
          // Do not block the guide on the recording service.  The recording
          // poll updates the buttons independently when its response arrives.
          refreshRecordings();
          const request=++playerEpgRequest;
          if(!currentLiveChannel?.item_id){renderPlayerEpgMessage(<?php echo json_encode(_("EPG not available")); ?>,false);}else{
            try{const epgParams={item_id:String(currentLiveChannel.item_id),provider_id:String(currentLiveChannel.provider_id||providerSwitchSelect?.value||'')};const response=await fetch('/aptv/api/epg?'+new URLSearchParams(epgParams),{credentials:'same-origin',cache:'no-store'}),data=await response.json();if(request!==playerEpgRequest)return;if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("EPG not available")); ?>);const channel=Array.isArray(data.items)?data.items[0]:null;renderPlayerEpgPrograms(Array.isArray(channel?.programs)?channel.programs:[]);}catch(error){if(request!==playerEpgRequest)return;renderPlayerEpgMessage(<?php echo json_encode(_("EPG not available")); ?>,false);}
          }
          await new Promise(function(resolve){requestAnimationFrame(function(){requestAnimationFrame(resolve);});});
          if(request!==playerEpgRequest)return;playerEpgOpening=false;playerEpg.hidden=false;window.requestAnimationFrame(function(){playerEpgList?.classList.remove('is-recording-state-pending');});
        };
        playerEpgToggle?.addEventListener('click',function(){if(playerEpg?.hidden)openPlayerEpg();else closePlayerEpg();});playerEpgClose?.addEventListener('click',closePlayerEpg);
        document.addEventListener('pointerdown',function(event){
          if(!playerEpg||playerEpg.hidden||!playerSurface||playerSurface.hidden||!(event.target instanceof Node)||!playerSurface.contains(event.target)||playerEpg.contains(event.target)||playerEpgToggle?.contains(event.target))return;
          closePlayerEpg();
        },true);
        const updateLiveBuffer=function(){
          if(playerMode!=='live'||!video||!liveBufferFill||!liveBufferValue)return;
          const bar=liveBufferFill.parentElement;
          if(video.srcObject||webrtcToken){
            if(liveBufferLabel)liveBufferLabel.textContent='Jitter buffer';
            const unavailable=function(){liveBufferValue.textContent='—';liveBufferFill.style.width='0%';bar.style.visibility='hidden';liveBufferValue.title='Misura WebRTC non disponibile in questo momento';};
            const receiver=liveBufferReceiver,attempt=playbackAttemptId;
            if(!receiver?.getStats){unavailable();return;}
            if(liveBufferPending===receiver)return;
            liveBufferPending=receiver;
            receiver.getStats().then(function(stats){
              if(attempt!==playbackAttemptId||receiver!==liveBufferReceiver||playerMode!=='live')return;
              let report=null;
              stats.forEach(function(item){if(item.type==='inbound-rtp'&&(item.kind==='video'||item.mediaType==='video')&&Number.isFinite(item.jitterBufferDelay)&&Number.isFinite(item.jitterBufferEmittedCount))report=item;});
              if(!report){liveBufferSample=null;unavailable();return;}
              const previous=liveBufferSample;
              liveBufferSample={id:report.id,delay:report.jitterBufferDelay,count:report.jitterBufferEmittedCount};
              if(!previous||previous.id!==report.id||report.jitterBufferEmittedCount<=previous.count||report.jitterBufferDelay<previous.delay){unavailable();return;}
              // W3C: interval-average residence time, not seconds of playable media ahead.
              const milliseconds=1000*(report.jitterBufferDelay-previous.delay)/(report.jitterBufferEmittedCount-previous.count);
              if(!Number.isFinite(milliseconds)||milliseconds<0){unavailable();return;}
              const detail='Tempo medio nel jitter buffer video WebRTC; scala barra 0–200 ms. Non indica la riserva video HLS.';
              bar.style.visibility='visible';bar.title=detail;liveBufferValue.title=detail;
              liveBufferFill.style.width=Math.min(100,milliseconds/200*100).toFixed(1)+'%';
              liveBufferValue.textContent=Math.round(milliseconds)+' ms';
            }).catch(function(){if(attempt===playbackAttemptId&&receiver===liveBufferReceiver)unavailable();})
              .finally(function(){if(liveBufferPending===receiver)liveBufferPending=null;});
            return;
          }
          if(liveBufferLabel)liveBufferLabel.textContent=liveBufferDefaultLabel;
          bar.style.visibility='visible';bar.title='Video disponibile davanti alla posizione corrente; scala 0–20 secondi';liveBufferValue.title=bar.title;
          let ahead=0;
          for(let index=0;index<video.buffered.length;index++){
            const start=video.buffered.start(index),end=video.buffered.end(index);
            if(video.currentTime>=start&&video.currentTime<=end+.25){ahead=Math.max(0,end-video.currentTime);break;}
          }
          liveBufferFill.style.width=Math.min(100,ahead/20*100).toFixed(1)+'%';
          liveBufferValue.textContent=ahead.toFixed(1)+' s';
        };
        const revealPlayerControls=function(){
          if(!vodControls||vodControls.hidden)return;
          vodControls.classList.remove('is-idle');
          playerHud?.classList.remove('is-idle');
          playerSurface?.classList.remove('is-cursor-idle');
          window.clearTimeout(controlsFadeClock);
          controlsFadeClock=window.setTimeout(function(){if(vodControls&&!vodControls.hidden&&!video?.paused){vodControls.classList.add('is-idle');playerHud?.classList.add('is-idle');playerSurface?.classList.add('is-cursor-idle');}},3000);
        };
        ['pointermove','pointerdown','touchstart','keydown'].forEach(function(type){playerSurface?.addEventListener(type,revealPlayerControls,{passive:type!=='keydown'});});
        playerSurface?.addEventListener('focusin',revealPlayerControls);
        video?.addEventListener('playing',revealPlayerControls);
        video?.addEventListener('pause',revealPlayerControls);
        const aptvCsrf = document.querySelector('meta[name="csrf_token"]')?.content || '';

        const stopHybridAudio=function(restoreVideoAudio){
          if(hybridSyncClock){clearInterval(hybridSyncClock);hybridSyncClock=0;}
          if(vodAudioPlayer){try{vodAudioPlayer.destroy();}catch(error){}vodAudioPlayer=null;}
          if(hybridAudio){hybridAudio.pause();hybridAudio.removeAttribute('src');hybridAudio.load();}
          if(vodHlsToken){fetch('/aptv/api/vod/hls/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:vodHlsToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});vodHlsToken='';}
          if(restoreVideoAudio&&video)video.muted=false;
        };

        const loadWhepClient=function(){
          if(window.MediaMTXWebRTCReader)return Promise.resolve();
          if(whepScriptPromise)return whepScriptPromise;
          whepScriptPromise=new Promise(function(resolve,reject){const script=document.createElement('script');script.src=window.location.protocol+'//'+window.location.host+'/webrtc/reader.js';script.onload=resolve;script.onerror=function(){reject(new Error(<?php echo json_encode(_("Unable to load the WebRTC player.")); ?>));};document.head.append(script);});return whepScriptPromise;
        };
        const connectWhep=function(path,attemptId){
          return loadWhepClient().then(function(){if(attemptId!==playbackAttemptId)return;if(whepReader)whepReader.close();whepReader=new window.MediaMTXWebRTCReader({url:window.location.protocol+'//'+window.location.host+'/webrtc/'+encodeURIComponent(path)+'/whep',onTrack:function(event){if(attemptId!==playbackAttemptId)return;video.srcObject=event.streams[0];if(event.track?.kind==='video'){liveBufferReceiver=event.receiver;liveBufferSample=null;updateLiveBuffer();}video.hidden=false;updateTrackTechnical(event.track);window.setTimeout(function(){updateWebRtcCodec(event.receiver,event.track?.kind||'video');},500);const playWebRtc=function(){const promise=video.play();if(promise&&typeof promise.catch==='function')promise.catch(function(error){if(error?.name!=='NotAllowedError'){if(playerState)playerState.hidden=false;return;}video.muted=true;syncPlayerMuteControl(video);recordPlayerEvent('WebRTC autoplay retry · muted');video.play().catch(function(){});});};playWebRtc();if(playerState)playerState.hidden=true;},onError:function(message){if(attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=String(message);}}});});
        };
        let playerMiniAnimation=null;
        const cancelPlayerMiniAnimation=function(){
          if(playerMiniAnimation){playerMiniAnimation.cancel();playerMiniAnimation=null;}
        };
        window.addEventListener('resize',cancelPlayerMiniAnimation);
        document.addEventListener('fullscreenchange',cancelPlayerMiniAnimation);
        document.addEventListener('webkitfullscreenchange',cancelPlayerMiniAnimation);
        let playerFadeClock=0;
        const showPlayerSurface=function(){
          if(!playerSurface)return;
          window.clearTimeout(playerFadeClock);playerFadeClock=0;
          playerSurface.inert=false;
          playerSurface.style.pointerEvents='auto';
          playerSurface.hidden=false;
          if(playerDiagnosticsToggle)playerDiagnosticsToggle.hidden=false;
          revealPlayerControls();
          window.requestAnimationFrame(function(){window.requestAnimationFrame(function(){if(!playerSurface.hidden){playerSurface.classList.add('is-visible');revealPlayerControls();}});});
        };
        const hidePlayerSurface=function(){
          if(!playerSurface)return;
          cancelPlayerMiniAnimation();
          playerSurface.inert=true;
          playerSurface.style.pointerEvents='none';
          playerSurface.classList.remove('is-visible','is-mini','is-cursor-idle');
          window.clearTimeout(playerFadeClock);
          playerFadeClock=window.setTimeout(function(){if(!playerSurface.classList.contains('is-visible'))playerSurface.hidden=true;playerFadeClock=0;},330);
        };

        const vodHlsPauseEligible=function(){return !suppressVodPauseRestart&&playerMode==='vod'&&vodPlayback?.hls&&!vodPlayback.hlsPaused&&['vod','series'].includes(playerLibraryKind);};
        const updateVodPausePosition=function(playback){if(!playback||!video)return;playback.position=Math.max(0,Math.min(playback.duration||Infinity,(playback.offset||0)+(video.currentTime||0)));if(vodSeek)vodSeek.value=String(Math.round(playback.position));if(vodCurrent)vodCurrent.textContent=formatVodTime(playback.position);};
        const armVodHlsPauseRestart=function(){
          window.clearTimeout(vodPauseRestartClock);vodPauseRestartClock=0;
          if(!vodHlsPauseEligible())return;
          const playback=vodPlayback;
          vodPauseRestartClock=window.setTimeout(function(){
            vodPauseRestartClock=0;
            if(vodPlayback!==playback||!vodHlsPauseEligible()||!video.paused)return;
            updateVodPausePosition(playback);playback.paused=true;playback.hlsPaused=true;
            // Stop fetching and release the provider session, but keep the
            // media element attached so the last decoded frame remains visible.
            if(aptvPlayer){try{if(aptvPlayer.stopLoad)aptvPlayer.stopLoad();if(aptvPlayer.pause)aptvPlayer.pause();}catch(error){}}
            if(vodHlsToken){fetch('/aptv/api/vod/hls/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:vodHlsToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});vodHlsToken='';}
            if(playerState)playerState.hidden=true;
          },120000);
        };
        const stopChannel = function () {
          suppressVodPauseRestart=true;window.setTimeout(function(){suppressVodPauseRestart=false;},0);
          window.clearTimeout(vodPauseRestartClock);vodPauseRestartClock=0;
          liveBufferReceiver=null;liveBufferSample=null;liveBufferPending=null;
          playbackAttemptId++;
          stopProgressiveStats();
          closePlayerEpg();currentLiveChannel=null;
          resetPlayerTechnical();resetMseDiagnostics();
          if(webrtcHeartbeat){window.clearInterval(webrtcHeartbeat);webrtcHeartbeat=0;}
          if(whepReader){whepReader.close();whepReader=null;}
          if(webrtcToken){fetch('/aptv/api/webrtc/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:webrtcToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});webrtcToken='';}
          stopHybridAudio(false);
          if(webrtcFrame){webrtcFrame.src='about:blank';webrtcFrame.hidden=true;}
          if(video)video.hidden=false;
          if (aptvPlayer) { try { if(aptvPlayer.stopLoad)aptvPlayer.stopLoad();if(aptvPlayer.pause)aptvPlayer.pause();if(aptvPlayer.unload)aptvPlayer.unload();if(aptvPlayer.detachMedia)aptvPlayer.detachMedia();if(aptvPlayer.detachMediaElement)aptvPlayer.detachMediaElement();aptvPlayer.destroy(); } catch(e) {} aptvPlayer=null; }
          if (aptvTranscodeToken) {
            const body=new URLSearchParams({token:aptvTranscodeToken,csrf_token:aptvCsrf});
            fetch('/aptv/api/transcode/stop',{method:'POST',credentials:'same-origin',body:body,keepalive:true}).catch(function(){});
            aptvTranscodeToken='';
          }
          if (video) { video.pause();video.srcObject=null;video.removeAttribute('src'); video.load(); }
          if(!playerFeedbackSeeking)setPlayerFeedback('');
          if(playerFeedbackSeeking){playerSurface.inert=false;playerSurface.style.pointerEvents='auto';playerSurface.hidden=false;playerSurface.classList.add('is-visible');}else hidePlayerSurface();
          if(vodClock){clearInterval(vodClock);vodClock=0;}if(liveBufferClock){clearInterval(liveBufferClock);liveBufferClock=0;}window.clearInterval(liveFmp4StatusClock);liveFmp4StatusClock=0;window.clearInterval(liveFmp4RecoveryClock);liveFmp4RecoveryClock=0;if(vodRemuxAbort){vodRemuxAbort.abort();vodRemuxAbort=null;}if(vodRemuxObjectUrl){URL.revokeObjectURL(vodRemuxObjectUrl);vodRemuxObjectUrl='';}window.clearTimeout(controlsFadeClock);vodSeeking=false;pendingVodSeek=null;vodPlayback=null;if(vodControls){vodControls.hidden=true;vodControls.classList.remove('is-idle');}
        };
        window.addEventListener('openap:session-expired', function () {
          webrtcToken='';vodHlsToken='';aptvTranscodeToken='';
          stopChannel();
        }, {once:true});
        const setMiniPlayer=function(mini){
          if(!playerSurface||playerSurface.hidden||!['live','vod'].includes(playerMode))return;
          mini=Boolean(mini);
          if(playerSurface.classList.contains('is-mini')===mini)return;
          const animate=((playerMode==='live'&&!mini)||(playerMode==='vod'&&['vod','series'].includes(playerLibraryKind)))
            &&playerSurface.classList.contains('is-visible')&&typeof playerSurface.animate==='function'
            &&!window.matchMedia('(prefers-reduced-motion: reduce)').matches
            &&!document.fullscreenElement&&!document.webkitFullscreenElement;
          // Read the displayed geometry before cancelling, so rapid reversals stay continuous.
          const before=animate?playerSurface.getBoundingClientRect():null;
          const radius=animate?getComputedStyle(playerSurface).borderRadius:'';
          cancelPlayerMiniAnimation();
          playerSurface.classList.toggle('is-mini',mini);
          if(!animate)return;
          const after=playerSurface.getBoundingClientRect();
          if(!before.width||!before.height||!after.width||!after.height)return;
          const targetRadius=getComputedStyle(playerSurface).borderRadius;
          const animation=playerSurface.animate([
            {transform:'translate('+ (before.left-after.left)+'px,'+(before.top-after.top)+'px) scale('+(before.width/after.width)+','+(before.height/after.height)+')',borderRadius:radius},
            {transform:'translate(0,0) scale(1,1)',borderRadius:targetRadius}
          ],{duration:420,easing:'cubic-bezier(.22,1,.36,1)'});
          // Keep the expanding player above the drawer until it reaches its normal layer.
          animation.effect.setKeyframes(animation.effect.getKeyframes().map(function(frame){
            return Object.assign(frame,{transformOrigin:'0 0',zIndex:1040,overflow:'hidden'});
          }));
          playerMiniAnimation=animation;
          animation.onfinish=function(){if(playerMiniAnimation===animation)playerMiniAnimation=null;};
        };
        // Live fMP4 is retained for controlled testing only; normal live playback stays on HLS.
        const liveFmp4PilotIds=new Set();
        let liveFmp4StatusClock=0,liveFmp4RecoveryClock=0;
        const startLiveFmp4=function(item,attemptId){
          if(!window.MediaSource||typeof MediaSource.isTypeSupported!=='function')return false;
          const liveProviderId=/^[a-f0-9]{32}$/.test(String(item.provider_id||''))?String(item.provider_id):String(providerSwitchSelect?.value||''),liveProviderSuffix=/^[a-f0-9]{32}$/.test(liveProviderId)?'&provider_id='+encodeURIComponent(liveProviderId):'';
          const mimeCandidates=['video/mp4; codecs="avc1,mp4a.40.2"','video/mp4; codecs="avc1.4d401f,mp4a.40.2"','video/mp4; codecs="avc1.42e01e,mp4a.40.2"','video/mp4; codecs="avc1.640028,mp4a.40.2"','video/mp4'];
          const mime=mimeCandidates.find(function(candidate){return MediaSource.isTypeSupported(candidate);});
          if(!mime)return false;
          playerLiveProfile='live-fmp4';playerTechnical.videoCodec='avc1';playerTechnical.audioCodec='mp4a.40.2';playerProgressiveMseAppend='opening';renderCommonLiveDiagnostics();recordPlayerEvent('Live fMP4/MSE opening');
          const liveAbortController=new AbortController();const mediaSource=new MediaSource();vodRemuxAbort=liveAbortController;vodRemuxObjectUrl=URL.createObjectURL(mediaSource);video.src=vodRemuxObjectUrl;video.hidden=false;video.load();
          window.clearInterval(liveFmp4StatusClock);window.clearInterval(liveFmp4RecoveryClock);const pollLiveFmp4Status=function(){if(attemptId!==playbackAttemptId||playerMode!=='live'||playerLiveProfile!=='live-fmp4'){window.clearInterval(liveFmp4StatusClock);liveFmp4StatusClock=0;return;}fetch('/aptv/api/live-fmp4-status?id='+encodeURIComponent(item.item_id)+liveProviderSuffix,{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(payload){if(!payload?.ok||attemptId!==playbackAttemptId)return;playerProgressiveFfmpegState=String(payload.state||'--');playerProgressiveLastOutputAt=payload.last_output_at?Math.floor(Date.parse(payload.last_output_at)/1000):0;playerProgressiveOutputGapSeconds=Number(payload.output_gap)||0;if(String(payload.error||''))playerProviderError=String(payload.error);if(Number(payload.output_bytes)>0)recordPlayerEvent('Relay output · '+String(payload.output_bytes)+' B · gap '+Number(payload.output_gap||0).toFixed(1)+' s');renderPlayerDiagnostics();}).catch(function(){});};pollLiveFmp4Status();liveFmp4StatusClock=window.setInterval(pollLiveFmp4Status,2000);
          mediaSource.addEventListener('sourceopen',async function(){
            if(attemptId!==playbackAttemptId||liveAbortController.signal.aborted)return;
            try{
              const sourceBuffer=mediaSource.addSourceBuffer(mime),appendMp4=createMp4Appender(sourceBuffer,'Unable to buffer the live fMP4 stream; MIME '+mime+'.');
              const response=await fetch('/aptv/api/live-fmp4?id='+encodeURIComponent(item.item_id)+liveProviderSuffix,{credentials:'same-origin',cache:'no-store',signal:liveAbortController.signal});
              playerProviderStatus=Number(response.status)||0;
              const responseProfile=response.headers.get('X-OpenAP-Live-Profile')||'';if(responseProfile)playerLiveProfile=responseProfile;
              recordPlayerEvent('Live fMP4 relay · HTTP '+String(playerProviderStatus||0));renderPlayerDiagnostics();
              if(!response.ok||!response.body)throw new Error('HTTP '+String(response.status||0));
              playerProgressiveMseAppend='fetching';
              const reader=response.body.getReader();let started=false,trimPending=false;const liveStartupTarget=8;
              const liveBufferAhead=function(){for(let index=0;index<sourceBuffer.buffered.length;index++){const start=sourceBuffer.buffered.start(index),end=sourceBuffer.buffered.end(index);if(video.currentTime>=start&&video.currentTime<=end+.25)return Math.max(0,end-video.currentTime);}return 0;};
              const maybeStart=function(){if(started||!sourceBuffer.buffered.length||video.readyState<3||!video.videoWidth)return;const ahead=liveBufferAhead();if(ahead<liveStartupTarget)return;started=true;playerPlaybackState='Playing';recordPlayerEvent('Live fMP4 buffer ready · '+ahead.toFixed(1)+' s');renderPlayerDiagnostics();video.play().catch(function(error){if(error?.name==='NotAllowedError'&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent='Press play to start the channel.';}});};
              const recoverLiveBuffer=function(){if(attemptId!==playbackAttemptId||playerMode!=='live'||!started){window.clearInterval(liveFmp4RecoveryClock);liveFmp4RecoveryClock=0;return;}const ahead=liveBufferAhead();if(ahead>=2||video.paused)return;let end=0,start=0;try{if(!sourceBuffer.buffered.length)return;start=sourceBuffer.buffered.start(0);end=sourceBuffer.buffered.end(sourceBuffer.buffered.length-1);}catch(error){return;}const target=Math.max(start,end-4);if(target<=video.currentTime+.5)return;playerPlaybackState='Recovering buffer';recordPlayerEvent('Live buffer recovery · '+ahead.toFixed(1)+' s → '+Math.max(0,end-target).toFixed(1)+' s');video.currentTime=target;renderPlayerDiagnostics();video.play().catch(function(){});};
              liveFmp4RecoveryClock=window.setInterval(recoverLiveBuffer,250);
              const trim=function(){if(trimPending||sourceBuffer.updating||!sourceBuffer.buffered.length||video.currentTime<25)return;const end=sourceBuffer.buffered.end(sourceBuffer.buffered.length-1);if(end-video.currentTime<30)return;trimPending=true;try{sourceBuffer.remove(0,Math.max(0,video.currentTime-20));sourceBuffer.addEventListener('updateend',function(){trimPending=false;},{once:true});}catch(error){trimPending=false;}};
              while(attemptId===playbackAttemptId){
                const result=await reader.read();if(result.done)break;if(!result.value?.byteLength)continue;
                await appendMp4(result.value);trim();maybeStart();
              }
              if(attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent='Live fMP4 relay ended.';}
              window.clearInterval(liveFmp4RecoveryClock);liveFmp4RecoveryClock=0;
              if(mediaSource.readyState==='open'&&!sourceBuffer.updating)mediaSource.endOfStream();
            }catch(error){
              if(error?.name==='AbortError'||attemptId!==playbackAttemptId)return;
              if(playerRetryCount<1){
                playerRetryCount++;recordPlayerEvent('Live fMP4 retry · '+String(error?.message||'MSE startup'));if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent='Retrying live fMP4…';}
                window.clearInterval(liveFmp4RecoveryClock);liveFmp4RecoveryClock=0;if(vodRemuxAbort===liveAbortController){liveAbortController.abort();vodRemuxAbort=null;}if(vodRemuxObjectUrl){URL.revokeObjectURL(vodRemuxObjectUrl);vodRemuxObjectUrl='';}video.pause();video.removeAttribute('src');video.load();window.setTimeout(function(){if(attemptId===playbackAttemptId)startLiveFmp4(item,attemptId);},400);return;
              }
              playerProviderError=String(error?.message||error);playerPlaybackState='Stalled';setPlayerDiagnostic(playerProviderError);renderPlayerDiagnostics();if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent='Unable to play the live fMP4 stream.';}
            }
          },{once:true});
          return true;
        };
        const playChannel = function (item) {
          if (window.openapSession.expired || !video || !playerSurface) return;
          const itemProviderId=/^[a-f0-9]{32}$/.test(String(item.provider_id||''))?String(item.provider_id):selectedProviderId();
          const recordingConflict=recordingState.items.find(function(job){return String(job.provider_id)===String(itemProviderId)&&String(job.channel_id)!==String(item.item_id)&&['starting','recording','reconnecting','stopping'].includes(job.status);});
          if(recordingConflict&&!window.confirm('Registrazione in corso su '+recordingConflict.channel_name+'. Guardare un altro canale richiede una seconda connessione al provider. Se il tuo abbonamento consente una sola connessione, video e registrazione possono bloccarsi o ripetersi. La registrazione resterà attiva. Vuoi continuare comunque?'))return;
          stopChannel();
          let liveFallbackStarted=false;
          let liveWebrtcStarted=false;
          let liveFallbackClock=0;
          let mpegTsPlaying=false;
          let normalizedRetryStarted=false;
          let liveManifestReady=false;
          let liveStartupRetryCount=0;
          let liveManifestRetryClock=0;
          let hlsMediaRecoveryAttempted=false;
          let normalizedBufferFallbackStarted=false;
          const liveAttemptId=playbackAttemptId;
          const liveProviderId=/^[a-f0-9]{32}$/.test(String(item.provider_id||''))?String(item.provider_id):String(providerSwitchSelect?.value||'');
          const liveManifestUrl='/aptv/api/hls?id='+encodeURIComponent(item.item_id)+(liveProviderId?'&provider_id='+encodeURIComponent(liveProviderId):'');
          let liveHlsEpoch=0;
          const liveRecovery={phase:'idle',rung:'copy',epoch:0,timer:0,startedAt:0,startTime:0,startFrames:0,ledger:[],discarded:0,stageMarks:{}};
          const clearLiveRecoveryTimer=function(){if(liveRecovery.timer){window.clearTimeout(liveRecovery.timer);liveRecovery.timer=0;}};
          const liveFrameCount=function(){const quality=video?.getVideoPlaybackQuality?.();return Number(quality?.totalVideoFrames)||Number(playerDecodedFrames)||0;};
          const liveHasBufferedData=function(){if(!video?.buffered)return false;const current=Number(video.currentTime)||0;for(let index=0;index<video.buffered.length;index++){if(current>=video.buffered.start(index)-.25&&current<=video.buffered.end(index)+.25)return video.buffered.end(index)-current>.25;}return false;};
          const liveBufferedSummary=function(){if(!video?.buffered||!video.buffered.length)return 'none';const ranges=[];for(let index=0;index<video.buffered.length&&index<3;index++)ranges.push(Number(video.buffered.start(index)).toFixed(1)+'–'+Number(video.buffered.end(index)).toFixed(1));return ranges.join(',');};
          const recordLiveStage=function(stage,detail){const key=String(liveHlsEpoch)+'|'+String(stage);if(liveRecovery.stageMarks[key])return;liveRecovery.stageMarks[key]=true;recordPlayerEvent('Recovery stage '+String(stage)+' · '+String(detail||'')+' · epoch '+liveHlsEpoch+' · paused '+(video?.paused?'yes':'no')+' · readyState '+(video?.readyState??'--')+' · buffer '+liveBufferedSummary());renderMseDiagnostics();};
          const recordLiveTimeout=function(reason){recordPlayerEvent('Recovery timeout snapshot · '+liveRecovery.rung+' · '+String(reason||'unspecified')+' · phase '+liveRecovery.phase+' · paused '+(video?.paused?'yes':'no')+' · readyState '+(video?.readyState??'--')+' · t '+(Number(video?.currentTime)||0).toFixed(1)+' s · frames '+liveFrameCount()+' · buffer '+liveBufferedSummary()+' · since '+(liveRecovery.startedAt?((performance.now()-liveRecovery.startedAt)/1000).toFixed(1):'--')+' s');renderMseDiagnostics();};
          const recordLiveRecovery=function(rung,reason){const entry={rung:rung,reason:String(reason||'unspecified'),epoch:liveHlsEpoch,time:performance.now(),position:Number(video?.currentTime)||0,frames:liveFrameCount(),paused:Boolean(video?.paused),buffered:liveHasBufferedData()};liveRecovery.ledger.push(entry);if(liveRecovery.ledger.length>16)liveRecovery.ledger.shift();recordPlayerEvent('Recovery '+rung+' · '+entry.reason+' · epoch '+entry.epoch+' · t '+entry.position.toFixed(1)+' s · frames '+entry.frames+' · '+(entry.paused?'paused':'playing')+' · '+(entry.buffered?'buffered':'not buffered'));return entry;};
          const finishLiveRecovery=function(reason){clearLiveRecoveryTimer();playerLiveRecoveryActive=false;playerLiveRecoveryOnPlaying=null;liveRecovery.phase='succeeded';playerRecoveryAction='recovery succeeded · '+String(reason||'playing');recordPlayerEvent('Recovery succeeded · '+String(reason||'playing')+' · epoch '+liveRecovery.epoch);renderMseDiagnostics();};
          const failLiveRecovery=function(reason){clearLiveRecoveryTimer();recordLiveTimeout(reason);playerLiveRecoveryActive=false;playerLiveRecoveryOnPlaying=null;liveRecovery.phase='failed';recordPlayerEvent('Recovery failed · '+String(reason||'no progress')+' · epoch '+liveRecovery.epoch);if(liveRecovery.rung==='copy-play')return restartLiveWithCopy(String(reason||'copy resume timeout'));if(liveRecovery.rung==='rebuild-copy'){if(!normalizedBufferFallbackStarted&&restartLiveWithNormalized(String(reason||'copy recovery timeout')))return true;}if(startWebRtcFallback())return true;playerRecoveryAction='terminal · '+String(reason||'recovery failed');renderMseDiagnostics();return false;};
          const attemptLivePlayback=function(epoch,reason){if(epoch!==liveHlsEpoch||liveAttemptId!==playbackAttemptId||playerLiveIntent==='want-pause')return false;recordPlayerEvent('play() called · '+String(reason||'recovery')+' · epoch '+epoch+' · paused '+(video?.paused?'yes':'no')+' · readyState '+(video?.readyState??'--')+' · buffer '+liveBufferedSummary());let promise;try{promise=video.play();}catch(error){playerRecoveryAction='play() threw · '+String(error?.name||'unknown');recordPlayerEvent('play() threw · '+String(error?.name||'unknown')+' · '+String(reason||'recovery'));renderMseDiagnostics();return false;}if(promise&&typeof promise.catch==='function')promise.then(function(){if(epoch===liveHlsEpoch){recordPlayerEvent('play() accepted · '+String(reason||'recovery'));renderMseDiagnostics();}}).catch(function(error){if(epoch!==liveHlsEpoch)return;playerRecoveryAction='play() rejected · '+String(error?.name||'unknown');recordPlayerEvent('play() rejected · '+String(error?.name||'unknown')+' · '+String(reason||'recovery'));renderMseDiagnostics();if(error?.name==='NotAllowedError'){playerLiveRecoveryActive=false;playerLiveRecoveryOnPlaying=null;liveRecovery.phase='blocked';}});return true;};
          const armLiveResumeWatchdog=function(epoch,reason){clearLiveRecoveryTimer();const deadline=performance.now()+3000;liveRecovery.timer=window.setTimeout(function(){if(epoch!==liveHlsEpoch||liveAttemptId!==playbackAttemptId||!playerLiveRecoveryActive)return;if(playerLiveIntent==='want-pause'){finishLiveRecovery('user paused during recovery');return;}const current=Number(video.currentTime)||0,frames=liveFrameCount(),advanced=current>liveRecovery.startTime+.05||frames>liveRecovery.startFrames;if(advanced){finishLiveRecovery('currentTime/frame progress');return;}failLiveRecovery(String(reason||'no currentTime/frame progress after play()'));},Math.max(250,deadline-performance.now()));};
          const startLiveResume=function(reason){if(liveAttemptId!==playbackAttemptId||playerLiveIntent==='want-pause')return false;clearLiveRecoveryTimer();playerLiveRecoveryActive=true;liveRecovery.phase='resuming';liveRecovery.rung='copy-play';liveRecovery.epoch=liveHlsEpoch;liveRecovery.startedAt=performance.now();liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();recordLiveRecovery('copy-play',reason);playerRecoveryAction='play() after MediaSource recovery';playerLiveRecoveryOnPlaying=function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;clearLiveRecoveryTimer();liveRecovery.phase='sustaining';liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();liveRecovery.timer=window.setTimeout(function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;const current=Number(video.currentTime)||0,frames=liveFrameCount();if(current>liveRecovery.startTime+.05||frames>liveRecovery.startFrames)finishLiveRecovery('sustained frame progress');else failLiveRecovery('first frame without sustained progress');},2500);};window.setTimeout(function(){if(liveRecovery.epoch===liveHlsEpoch&&playerLiveRecoveryActive)attemptLivePlayback(liveHlsEpoch,reason);},120);armLiveResumeWatchdog(liveHlsEpoch,reason);renderMseDiagnostics();return true;};
          const scheduleLiveManifestRetry=function(forceNormalized){
            if(liveManifestReady||liveAttemptId!==playbackAttemptId||liveStartupRetryCount>=3)return false;
            liveStartupRetryCount++;
            const retryNumber=liveStartupRetryCount;
            const delay=400+(retryNumber*700);
            const source=forceNormalized?liveManifestUrl+'&profile=normalized&fast=1':liveManifestUrl;
            window.clearTimeout(liveManifestRetryClock);
            liveManifestRetryClock=window.setTimeout(function(){
              if(liveAttemptId!==playbackAttemptId||liveManifestReady||!aptvPlayer)return;
              playerRetryCount++;
              playerRecoveryAction='HLS startup retry '+retryNumber+'/3'+(forceNormalized?' · normalized':'');
              recordPlayerEvent('HLS startup retry '+retryNumber+'/3'+(forceNormalized?' · normalized':''));
              if(playerState)playerState.querySelector('span').textContent='Retrying channel startup…';
              aptvPlayer.loadSource(source);
            },delay);
            return true;
          };
          const restartLiveWithCopy=function(reason){
            if(liveRecovery.rung==='rebuild-copy'||liveAttemptId!==playbackAttemptId||!aptvPlayer)return false;
            clearLiveRecoveryTimer();liveRecovery.rung='rebuild-copy';liveRecovery.phase='recovering';liveRecovery.epoch=liveHlsEpoch;recordLiveRecovery('rebuild-copy',reason||'MSE state unavailable');playerRecoveryAction='rebuild copy after '+String(reason||'MSE recovery');playerLiveRecoveryActive=true;playerLiveRecoveryOnPlaying=null;
            try{
              const oldPlayer=aptvPlayer;aptvPlayer=null;liveHlsEpoch++;oldPlayer.stopLoad?.();oldPlayer.detachMedia?.();oldPlayer.destroy?.();video.pause();video.removeAttribute('src');video.load();startFreshLiveHls(liveManifestUrl);liveRecovery.epoch=liveHlsEpoch;liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();playerLiveRecoveryOnPlaying=function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;clearLiveRecoveryTimer();liveRecovery.phase='sustaining';liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();liveRecovery.timer=window.setTimeout(function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;const current=Number(video.currentTime)||0,frames=liveFrameCount();if(current>liveRecovery.startTime+.05||frames>liveRecovery.startFrames)finishLiveRecovery('copy rebuild sustained frame progress');else failLiveRecovery('copy rebuild first frame without sustained progress');},2500);};liveRecovery.timer=window.setTimeout(function(){if(liveRecovery.epoch===liveHlsEpoch&&playerLiveRecoveryActive)failLiveRecovery('copy rebuild startup timeout');},10000);return true;
            }catch(error){playerLiveRecoveryActive=false;recordPlayerEvent('Copy rebuild failed · '+String(error?.message||error));return false;}
          };
          const restartLiveWithNormalized=function(reason){
            if(normalizedBufferFallbackStarted||liveAttemptId!==playbackAttemptId||!aptvPlayer)return false;
            const resumePlayback=playerLiveIntent!=='want-pause';
            clearLiveRecoveryTimer();window.clearTimeout(liveManifestRetryClock);
            normalizedBufferFallbackStarted=true;
            liveManifestReady=false;
            hlsMediaRecoveryAttempted=false;
            playerRetryCount++;
              liveRecovery.rung='normalized';liveRecovery.phase='recovering';liveRecovery.epoch=liveHlsEpoch;liveRecovery.startedAt=performance.now();recordLiveRecovery('normalized',reason||'bufferAppendError');
            playerRecoveryAction='retry normalized after '+String(reason||'bufferAppendError');
            setPlayerDiagnostic('Retry normalized after bufferAppendError');
            if(playerState)playerState.querySelector('span').textContent='Preparing compatible channel…';
            try{
              const oldPlayer=aptvPlayer;
              playerLiveRecoveryActive=true;playerLiveRecoveryOnPlaying=null;if(resumePlayback)playerLiveIntent='want-play';
              aptvPlayer=null;
              oldPlayer.stopLoad?.();
              oldPlayer.detachMedia?.();
              oldPlayer.destroy?.();
              video.pause();
              video.removeAttribute('src');
              video.load();
              startFreshLiveHls(liveManifestUrl+'&profile=normalized&fast=1');
              liveRecovery.epoch=liveHlsEpoch;liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();playerLiveRecoveryOnPlaying=function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;clearLiveRecoveryTimer();liveRecovery.phase='sustaining';liveRecovery.startTime=Number(video.currentTime)||0;liveRecovery.startFrames=liveFrameCount();liveRecovery.timer=window.setTimeout(function(){if(liveRecovery.epoch!==liveHlsEpoch||!playerLiveRecoveryActive)return;const current=Number(video.currentTime)||0,frames=liveFrameCount();if(current>liveRecovery.startTime+.05||frames>liveRecovery.startFrames)finishLiveRecovery('normalized sustained frame progress');else failLiveRecovery('normalized first frame without sustained progress');},2500);};liveRecovery.timer=window.setTimeout(function(){if(liveRecovery.epoch===liveHlsEpoch&&playerLiveRecoveryActive)failLiveRecovery('normalized startup timeout');},15000);
              return true;
            }catch(error){
              recordPlayerEvent('Normalized retry failed · '+String(error?.message||error||'HLS restart error'));
              return false;
            }
          };
          const startFreshLiveHls=function(source){
            const epoch=++liveHlsEpoch,hls=new window.Hls(aptvLiveHlsOptions);aptvPlayer=hls;liveRecovery.stageMarks={};liveRecovery.startedAt=performance.now();recordLiveStage('hls-requested',String(source||'').includes('profile=normalized')?'normalized':'copy');
            aptvPlayer.on(window.Hls.Events.MANIFEST_LOADED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;const details=data?.networkDetails,profile=details?.getResponseHeader?.('X-OpenAP-Live-Profile')||'';playerLiveProfile=profile||'copy';playerProviderStatus=Number(details?.status)||0;recordPlayerEvent('Manifest loaded'+(playerProviderStatus?' · HTTP '+playerProviderStatus:''));recordLiveStage('manifest-loaded',(profile||'copy')+(playerProviderStatus?' · HTTP '+playerProviderStatus:''));if(profile==='normalized')setPlayerDiagnostic('Profile: normalized');});
            aptvPlayer.on(window.Hls.Events.MANIFEST_PARSED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;liveManifestReady=true;window.clearTimeout(liveManifestRetryClock);recordLiveStage('manifest-parsed',String(aptvPlayer.levels?.length||data?.levels?.length||0)+' levels');const level=data?.levels?.[0]||aptvPlayer.levels?.[0];if(level){playerTechnical.width=Number(level.width)||0;playerTechnical.height=Number(level.height)||0;playerTechnical.fps=Number(level.frameRate)||0;const codecs=String(level.attrs?.CODECS||level.codecSet||'').split(',');playerTechnical.videoCodec=level.videoCodec||codecs.find(function(codec){return /avc|hvc|hev|vp9|vp0|av01/i.test(codec);})||'';playerTechnical.audioCodec=level.audioCodec||codecs.find(function(codec){return /mp4a|aac|opus|ac-3|ec-3|mp3/i.test(codec);})||'';renderPlayerTechnical();}attemptLivePlayback(epoch,'manifest parsed');});
            if(window.Hls.Events.BUFFER_CODECS)aptvPlayer.on(window.Hls.Events.BUFFER_CODECS,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;updateHlsTechnical(data);});
            aptvPlayer.on(window.Hls.Events.LEVEL_SWITCHED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;const level=aptvPlayer.levels?.[data.level];if(level){playerTechnical.width=Number(level.width)||playerTechnical.width;playerTechnical.height=Number(level.height)||playerTechnical.height;playerTechnical.fps=Number(level.frameRate)||playerTechnical.fps;renderPlayerTechnical();}});
            aptvPlayer.on(window.Hls.Events.LEVEL_LOADED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;const details=data?.details||{},fragments=Array.isArray(details.fragments)?details.fragments:[],duration=Number(details.totalduration)||fragments.reduce(function(total,fragment){return total+(Number(fragment.duration)||0);},0),sync=Number(aptvPlayer.liveSyncPosition),current=Number(video.currentTime);playerHlsSegmentCount=fragments.length;playerHlsPlaylistDuration=duration;playerHlsTargetDuration=Number(details.targetduration)||Number(details.targetDuration)||playerHlsTargetDuration;playerLiveLatency=Number.isFinite(sync)&&Number.isFinite(current)&&sync>current?sync-current:0;recordLiveStage('level-loaded',fragments.length+' fragments · '+duration.toFixed(1)+' s');renderPlayerDiagnostics();});
            aptvPlayer.on(window.Hls.Events.FRAG_LOADED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;const details=data?.networkDetails,stats=data?.stats||{},start=Number(stats.loading?.start),end=Number(stats.loading?.end);playerLastSegmentStatus=Number(details?.status)||200;playerLastSegmentMs=Number.isFinite(start)&&Number.isFinite(end)?Math.max(0,Math.round(end-start)):0;playerEstimatedBandwidth=Number(stats.bwEstimate)||Number(aptvPlayer.bandwidthEstimate)||playerEstimatedBandwidth;recordPlayerEvent('Segment loaded · HTTP '+playerLastSegmentStatus+(playerLastSegmentMs?' · '+playerLastSegmentMs+' ms':''));recordLiveStage('frag-loaded','HTTP '+playerLastSegmentStatus+(playerLastSegmentMs?' · '+playerLastSegmentMs+' ms':''));renderPlayerDiagnostics();});
            if(window.Hls.Events.BUFFER_APPENDED)aptvPlayer.on(window.Hls.Events.BUFFER_APPENDED,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls)return;recordLiveStage('buffer-appended',String(data?.type||'media'));});
            aptvPlayer.on(window.Hls.Events.ERROR,function(event,data){if(epoch!==liveHlsEpoch||aptvPlayer!==hls){liveRecovery.discarded++;if(liveRecovery.discarded<=3)recordPlayerEvent('Discarded stale HLS error · epoch '+epoch);return;}handleLiveHlsError(aptvPlayer,data,epoch);});
            aptvPlayer.loadSource(source);
            aptvPlayer.attachMedia(video);
          };
          const startWebRtcFallback=function(){
            if(liveWebrtcStarted)return false;
            liveWebrtcStarted=true;window.clearTimeout(liveFallbackClock);
            clearLiveRecoveryTimer();liveHlsEpoch++;liveRecovery.rung='webrtc';liveRecovery.phase='terminal';liveRecovery.epoch=liveHlsEpoch;playerLiveRecoveryActive=false;playerLiveRecoveryOnPlaying=null;recordLiveRecovery('webrtc','HLS recovery ladder exhausted');
            playerLiveProfile='webrtc';
            recordPlayerEvent('Fallback WebRTC after HLS ladder failure');
            setPlayerDiagnostic('Fallback WebRTC');
            if(aptvPlayer){try{if(aptvPlayer.pause)aptvPlayer.pause();if(aptvPlayer.unload)aptvPlayer.unload();if(aptvPlayer.detachMediaElement)aptvPlayer.detachMediaElement();aptvPlayer.destroy();}catch(error){}aptvPlayer=null;}
            video.pause();video.removeAttribute('src');video.load();
            if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Preparing compatible video…")); ?>;}
            recordPlayerEvent('WebRTC runtime requested');
            fetch('/aptv/api/webrtc/start?id='+encodeURIComponent(item.item_id)+(liveProviderId?'&provider_id='+encodeURIComponent(liveProviderId):''),{credentials:'same-origin',cache:'no-store'}).then(async function(response){const body=await response.text();recordPlayerEvent('WebRTC runtime response · HTTP '+response.status+(response.redirected?' · redirected':''));let payload=null;try{payload=JSON.parse(body);}catch(error){throw new Error(response.status===401||response.redirected?<?php echo json_encode(_("The APtv session has expired. Reload the page and try again.")); ?>:<?php echo json_encode(_("The compatible playback service returned an invalid response.")); ?>);}if(!response.ok||!payload?.ok||!payload?.token||!payload?.path)throw new Error(payload?.message||<?php echo json_encode(_("Unable to prepare this channel.")); ?>);recordPlayerEvent('WebRTC runtime ready');return payload;}).then(function(payload){
              if(liveAttemptId!==playbackAttemptId){fetch('/aptv/api/webrtc/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:payload.token,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});return;}
              webrtcToken=payload.token;connectWhep(payload.path,liveAttemptId);if(webrtcHeartbeat)window.clearInterval(webrtcHeartbeat);webrtcHeartbeat=window.setInterval(function(){fetch('/aptv/api/webrtc/heartbeat',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:webrtcToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});},5000);
            }).catch(function(error){recordPlayerEvent('WebRTC runtime failed · '+String(error?.message||error||'unknown'));if(liveAttemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}renderMseDiagnostics();});return true;
          };
          const startMpegTsFallback=function(){
            if(liveFallbackStarted||!window.mpegts||!window.mpegts.isSupported())return false;
            liveFallbackStarted=true;
            clearLiveRecoveryTimer();liveHlsEpoch++;liveRecovery.rung='mpegts';liveRecovery.phase='recovering';liveRecovery.epoch=liveHlsEpoch;playerLiveRecoveryActive=true;playerLiveRecoveryOnPlaying=null;recordLiveRecovery('mpegts','normalized recovery failed');
            playerLiveProfile='mpegts';
            playerRecoveryAction='fallback MPEG-TS after normalized HLS failure';
            recordPlayerEvent('Fallback MPEG-TS after normalized HLS failure');
            setPlayerDiagnostic('Fallback MPEG-TS');
            if(aptvPlayer){try{if(aptvPlayer.stopLoad)aptvPlayer.stopLoad();if(aptvPlayer.detachMedia)aptvPlayer.detachMedia();aptvPlayer.destroy();}catch(error){}aptvPlayer=null;}
            video.pause();video.removeAttribute('src');video.load();
            if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Retrying with compatible transport…")); ?>;}
            playerMpegtsState='loading';playerMpegtsBytes=0;playerMpegtsLastDataAt=0;playerMpegtsLastStatsAt=0;playerMpegtsStats='--';playerMpegtsMediaInfo='--';playerMpegtsLastError='--';playerMpegtsLoading='active';renderMpegtsDiagnostics();
            aptvPlayer=window.mpegts.createPlayer({type:'mpegts',isLive:true,withCredentials:true,cors:false,url:'/aptv/api/stream?id='+encodeURIComponent(item.item_id)+(liveProviderId?'&provider_id='+encodeURIComponent(liveProviderId):'')},{enableWorker:false,enableStashBuffer:false,fixAudioTimestampGap:true});
            aptvPlayer.on(window.mpegts.Events.MEDIA_INFO,function(info){playerMpegtsState='media-info';playerMpegtsMediaInfo=[info.videoCodec||'video --',info.audioCodec||'audio --',(Number(info.width)||0)+'×'+(Number(info.height)||0),Number(info.fps)?Number(info.fps).toFixed(2)+' fps':'fps --'].join(' · ');playerTechnical.width=Number(info.width)||0;playerTechnical.height=Number(info.height)||0;playerTechnical.fps=Number(info.fps)||0;playerTechnical.videoCodec=info.videoCodec||'';playerTechnical.audioCodec=info.audioCodec||'';recordPlayerEvent('MPEG-TS MEDIA_INFO · '+playerMpegtsMediaInfo);renderPlayerTechnical();renderMpegtsDiagnostics();});
            if(window.mpegts.Events.STATISTICS_INFO)aptvPlayer.on(window.mpegts.Events.STATISTICS_INFO,function(stats){const bytes=Number(stats?.totalBytes||stats?.totalBytesLoaded||stats?.loadedBytes||0);if(bytes>playerMpegtsBytes){playerMpegtsBytes=bytes;playerMpegtsLastDataAt=performance.now();}playerMpegtsLastStatsAt=performance.now();const parts=[];if(Number(stats?.speed)>0)parts.push('speed '+Number(stats.speed).toFixed(2)+'×');if(Number(stats?.decodedFrames)>=0)parts.push('decoded '+Number(stats.decodedFrames));if(Number(stats?.droppedFrames)>=0)parts.push('dropped '+Number(stats.droppedFrames));if(Number(stats?.currentTime)>0)parts.push('media '+Number(stats.currentTime).toFixed(1)+' s');playerMpegtsStats=parts.length?parts.join(' · '):'updated';if(playerMpegtsState!=='error')playerMpegtsState='streaming';renderMpegtsDiagnostics();});
            if(window.mpegts.Events.LOADING_COMPLETE)aptvPlayer.on(window.mpegts.Events.LOADING_COMPLETE,function(){playerMpegtsLoading='complete';playerMpegtsState='loading-complete';recordPlayerEvent('MPEG-TS LOADING_COMPLETE');renderMpegtsDiagnostics();});
            if(window.mpegts.Events.RECOVERED_EARLY_EOF)aptvPlayer.on(window.mpegts.Events.RECOVERED_EARLY_EOF,function(){playerMpegtsLoading='recovered-early-eof';recordPlayerEvent('MPEG-TS RECOVERED_EARLY_EOF');renderMpegtsDiagnostics();});
            aptvPlayer.on(window.mpegts.Events.ERROR,function(errorType,errorDetail,errorInfo){playerMpegtsState='error';playerMpegtsLastError=[String(errorType||'unknown'),String(errorDetail||'unknown'),errorInfo?String(errorInfo):''].filter(function(value){return value&&value!=='unknown';}).join(' · ');recordPlayerEvent('MPEG-TS error · '+playerMpegtsLastError);renderMpegtsDiagnostics();startWebRtcFallback();});
            video.addEventListener('playing',function(){mpegTsPlaying=true;playerMpegtsState='playing';window.clearTimeout(liveFallbackClock);recordLiveStage('mpegts-playing','frame pipeline active');finishLiveRecovery('MPEG-TS playing');renderMpegtsDiagnostics();},{once:true});aptvPlayer.attachMediaElement(video);aptvPlayer.load();let mpegPlay=null;try{recordPlayerEvent('MPEG-TS play() called');mpegPlay=aptvPlayer.play();}catch(error){recordPlayerEvent('MPEG-TS play() threw · '+String(error?.name||error||'unknown'));}if(mpegPlay&&typeof mpegPlay.then==='function')mpegPlay.then(function(){recordPlayerEvent('MPEG-TS play() accepted');}).catch(function(error){recordPlayerEvent('MPEG-TS play() rejected · '+String(error?.name||error||'unknown'));});attemptLivePlayback(liveHlsEpoch,'MPEG-TS fallback');liveFallbackClock=window.setTimeout(function(){if(liveAttemptId===playbackAttemptId&&!mpegTsPlaying){playerMpegtsState='startup-timeout';recordPlayerEvent('MPEG-TS startup timeout · 5.0 s');renderMpegtsDiagnostics();startWebRtcFallback();}},5000);return true;
          };
          const handleLiveHlsError=function(player,data,epoch){if(!data)return;if(epoch!==liveHlsEpoch){liveRecovery.discarded++;if(liveRecovery.discarded<=3)recordPlayerEvent('Discarded stale HLS error · epoch '+String(epoch));return;}const mse=captureHlsErrorDiagnostics(player,data),detail=mse.detail,status=Number(data.networkDetails?.status)||0;if(status)playerProviderStatus=status;playerProviderError=detail;setPlayerDiagnostic(detail);if(data.fatal&&playerLiveIntent==='want-play'){playerLiveRecoveryActive=true;recordPlayerEvent('Recovery intent protected · want-play');}if(detail==='bufferAddCodecError'&&mse.errorName==='NotSupportedError'&&!normalizedBufferFallbackStarted&&restartLiveWithNormalized())return;if(data.fatal){if(!liveManifestReady){if(!normalizedRetryStarted){normalizedRetryStarted=true;if(scheduleLiveManifestRetry(true))return;}if(scheduleLiveManifestRetry(false))return;}if(detail==='bufferAppendError'){if(liveRecovery.phase==='resuming'||liveRecovery.phase==='sustaining'){recordPlayerEvent('Fatal bufferAppendError during recovery · ignored for current epoch');return;}recordLiveRecovery('fatal-buffer',detail+' · '+mse.errorName);const stateAllowsRecover=playerMseReadyState==='open'&&Number(playerMseSourceBufferCount)>0;if(!hlsMediaRecoveryAttempted&&stateAllowsRecover&&typeof player.recoverMediaError==='function'){hlsMediaRecoveryAttempted=true;playerRetryCount++;playerRecoveryAction='recoverMediaError() after fatal bufferAppendError';playerLiveRecoveryActive=true;recordPlayerEvent('Media recovery');renderMseDiagnostics();player.recoverMediaError();window.setTimeout(function(){if(epoch===liveHlsEpoch)startLiveResume('after recoverMediaError');},120);return;}if(restartLiveWithCopy('fatal '+detail+' · '+(stateAllowsRecover?'recovery exhausted':'MSE state unavailable')))return;if(!normalizedBufferFallbackStarted&&restartLiveWithNormalized('fatal '+detail+' · '+(stateAllowsRecover?'recovery exhausted':'MSE state unavailable')))return;if(startWebRtcFallback())return;}}};
          currentLiveChannel=item;
          resetPlayerTechnical();resetMseDiagnostics();playerLiveIntent='want-play';
          showPlayerSurface();
          if(playerDiagnosticsToggle)playerDiagnosticsToggle.hidden=false;
          if(playerName) playerName.textContent=item.name || <?php echo json_encode(_("Live channel")); ?>;
          if(playerLogo){const logo=String(item.icon_url||'');playerLogo.hidden=!logo;if(logo)playerLogo.src=logo;}
          playerMode='live';playerLibraryKind='live';if(vodControls){vodControls.hidden=false;vodControls.classList.remove('is-vod');}updateLiveBuffer();liveBufferClock=window.setInterval(updateLiveBuffer,500);revealPlayerControls();
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Connecting to channel…")); ?>;}
          setLiveOpen(false);
          if ((!liveFmp4PilotIds||liveFmp4PilotIds.has(String(item.item_id)))&&window.MediaSource&&typeof MediaSource.isTypeSupported==='function'&&(['video/mp4','video/mp4; codecs="avc1,mp4a.40.2"','video/mp4; codecs="avc1.4d401f,mp4a.40.2"'].some(function(type){return MediaSource.isTypeSupported(type);}))) {
            if(!startLiveFmp4(item,liveAttemptId)&&playerState){playerState.querySelector('span').textContent='This browser cannot play live fMP4.';playerProviderError='MediaSource unsupported';setPlayerDiagnostic(playerProviderError);}
          } else if (window.Hls && window.Hls.isSupported()) {
            startFreshLiveHls(liveManifestUrl);
          } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src=liveManifestUrl;video.play().catch(function(){});
          } else { if(playerState)playerState.querySelector('span').textContent=<?php echo json_encode(_("This browser cannot play the channel format.")); ?>; }
          video.addEventListener('playing',function(){if(playerState)playerState.hidden=true;},{once:true});
        };
        video?.addEventListener('loadedmetadata',renderPlayerTechnical);
        video?.addEventListener('resize',renderPlayerTechnical);
        video?.addEventListener('loadstart',resetPresentationDiagnostics);
        video?.addEventListener('loadstart',function(){if(playerFeedbackSeeking&&playerMode==='vod')setPlayerFeedback('seeking');});
        video?.addEventListener('waiting',function(){playerPlaybackState='Buffering';playerRebufferCount++;playerRebufferStarted=performance.now();sampleProgressiveBuffer(true);recordPlayerEvent('Playback waiting');renderPlayerDiagnostics();renderProgressiveDiagnostics();if(vodPlayback?.recording){recordPlayerEvent('Recording buffer wait · adaptive recovery bypassed');return;}if(vodPlayback?.cacheSeek){recordPlayerEvent('Cache seek decoder warm-up · adaptive recovery bypassed');return;}startProgressiveRecovery();});
        video?.addEventListener('stalled',function(){playerPlaybackState='Stalled';recordPlayerEvent('Playback stalled');renderPlayerDiagnostics();});
        video?.addEventListener('playing',function(){if(playerMode==='live'){playerLiveIntent='want-play';if(playerLiveRecoveryOnPlaying)playerLiveRecoveryOnPlaying();}if(progressiveRecoveryActive){const ahead=progressiveBufferAhead(),expired=performance.now()>=progressiveRecoveryDeadline;if(ahead<progressiveRecoveryTarget&&!expired){video.pause();playerPlaybackState='Recovering buffer';renderPlayerDiagnostics();return;}const target=progressiveRecoveryTarget;stopProgressiveRecovery();progressiveRecoveryCooldownUntil=performance.now()+4000;recordPlayerEvent((expired?'Buffer recovery timeout':'Buffer recovered')+' · '+ahead.toFixed(1)+' / '+target+' s');}if(playerRebufferStarted){playerRebufferDuration+=Math.max(0,performance.now()-playerRebufferStarted);playerRebufferStarted=0;}playerPlaybackState='Playing';renderPlayerDiagnostics();});
        video?.addEventListener('pause',function(){if(playerMode==='live'){playerPlaybackState='Paused';renderPlayerDiagnostics();}});
        video?.addEventListener('playing',measurePlayerFrameRate);
        video?.addEventListener('pause',stopFrameRateMeasurement);
        const restoreMobileHeroAfterPlayback=function(){
          if(!movieBackdrop||!window.matchMedia('(max-width:767.98px)').matches||!['vod','series'].includes(activeMediaSection))return;
          if(moviesView)moviesView.hidden=false;
          movieBackdrop.getAnimations?.().forEach(function(animation){animation.cancel();});
          movieBackdrop.style.removeProperty('opacity');
          movieBackdrop.style.transform='translateY(calc(-100% - 100vh))';
          if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){movieBackdrop.style.removeProperty('transform');return;}
          const animation=movieBackdrop.animate([{opacity:1,transform:'translateY(calc(-100% - 100vh))'},{opacity:1,transform:'translateY(0)'}],{duration:420,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});
          animation.finished.catch(function(){}).finally(function(){animation.cancel();movieBackdrop.style.removeProperty('transform');});
        };
        const closePlayer=function(){
          if(wallTraditionalPlayerSession){restoreWallTraditionalPlayer();return;}
          const returnToRecordings=playerMode==='vod'&&playerLibraryKind==='recording';
          const fullscreenElement=document.fullscreenElement||document.webkitFullscreenElement;
          if(fullscreenElement&&(fullscreenElement===playerSurface||playerSurface?.contains(fullscreenElement))){
            const exitFullscreen=document.exitFullscreen?.bind(document)||document.webkitExitFullscreen?.bind(document);
            if(exitFullscreen)Promise.resolve(exitFullscreen()).catch(function(){});
          }
          const preserveSeriesEpisode=playerMode==='vod'&&playerLibraryKind==='series'&&Boolean(playingSeriesView?.episodeId);
          stopChannel();
          restoreMobileHeroAfterPlayback();
          playingEpisodeId='';
          if(!preserveSeriesEpisode)playingSeriesView=null;
          playingMovieId='';
          movieGrid?.querySelectorAll('.openap-iptv-now-playing').forEach(function(label){label.remove();});
          if(returnToRecordings)setRecordingsOpen(true);
        };
        video?.addEventListener('ended',function(){
          const playback=vodPlayback,attempt=playbackAttemptId;
          if(playerMode!=='vod'||!playback||playback.recording)return;
          playerProviderError='';
          playerPlaybackState='Ended';
          playerRecoveryAction='VOD ended · returning to library';
          stopProgressiveStats();
          recordPlayerEvent('VOD ended · closing player');
          renderPlayerDiagnostics();
          window.setTimeout(function(){
            if(attempt===playbackAttemptId&&vodPlayback===playback&&playerMode==='vod')closePlayer();
          },180);
        });
        document.querySelector('[data-openap-aptv-player-close]')?.addEventListener('click',closePlayer);
        let epgItems=[],epgOffset=0,epgHasMore=false,epgLoading=false,epgWindowStart=0,epgWindowEnd=0,epgSelectedChannel=null,epgCountriesLoaded=false;
        const renderEpgLoading=function(count){
          if(!epgGrid)return;epgGrid.replaceChildren();
          for(let index=0;index<count;index++){
            const row=document.createElement('div');row.className='openap-aptv-epg-row openap-aptv-epg-row-loading';row.setAttribute('aria-busy','true');
            const channel=document.createElement('div');channel.className='openap-aptv-epg-channel';
            const icon=document.createElement('span');icon.className='openap-aptv-epg-loading-icon';
            const name=document.createElement('strong');name.textContent=<?php echo json_encode(_('Loading EPG')); ?>;channel.append(icon,name);
            const programs=document.createElement('div');programs.className='openap-aptv-epg-programs';
            const status=document.createElement('div');status.className='openap-aptv-epg-row-loading-status';status.innerHTML='<i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><span>'+<?php echo json_encode(_('Loading EPG')); ?>+'</span>';
            programs.append(status);row.append(channel,programs);epgGrid.append(row);
          }
        };
        const epgTime=function(timestamp){return new Intl.DateTimeFormat(undefined,{hour:'2-digit',minute:'2-digit'}).format(new Date(timestamp*1000));};
        const closeEpg=function(){if(epgView)epgView.hidden=true;document.querySelector('.openap-iptv-page')?.classList.remove('is-guide');if(epgDetail)epgDetail.hidden=true;};
        const renderEpg=function(){
          if(!epgGrid||!epgHours)return;epgHours.replaceChildren();for(let index=0;index<12;index++){const time=document.createElement('time');time.textContent=epgTime(epgWindowStart+index*1800);epgHours.append(time);}
          epgGrid.replaceChildren();const span=Math.max(1,epgWindowEnd-epgWindowStart),now=Math.floor(Date.now()/1000);
          epgItems.forEach(function(channel){const row=document.createElement('div');row.className='openap-aptv-epg-row';const channelButton=document.createElement('button');channelButton.type='button';channelButton.className='openap-aptv-epg-channel';if(channel.icon_url){const logo=document.createElement('img');logo.src=channel.icon_url;logo.alt='';logo.loading='lazy';channelButton.append(logo);}else{const icon=document.createElement('i');icon.className='fas fa-tv';channelButton.append(icon);}const name=document.createElement('strong');name.textContent=channel.name;channelButton.append(name);channelButton.addEventListener('click',function(){closeEpg();playChannel(channel);});const programs=document.createElement('div');programs.className='openap-aptv-epg-programs';if(!channel.programs.length){const empty=document.createElement('span');empty.className='openap-aptv-epg-empty';empty.textContent=<?php echo json_encode(_("No guide data")); ?>;programs.append(empty);}channel.programs.forEach(function(program){const start=Math.max(epgWindowStart,Number(program.start)),end=Math.min(epgWindowEnd,Number(program.end));if(end<=start)return;const button=document.createElement('button');button.type='button';button.className='openap-aptv-epg-program';button.style.left=((start-epgWindowStart)/span*100)+'%';button.style.width=Math.max(1.5,(end-start)/span*100)+'%';button.classList.toggle('is-now',program.start<=now&&program.end>now);const title=document.createElement('strong');title.textContent=program.title||<?php echo json_encode(_("Untitled programme")); ?>;const time=document.createElement('small');time.textContent=epgTime(program.start)+' – '+epgTime(program.end);button.append(title,time);button.addEventListener('click',function(){epgSelectedChannel=channel;if(epgDetailTime)epgDetailTime.textContent=time.textContent;if(epgDetailTitle)epgDetailTitle.textContent=title.textContent;if(epgDetailDescription)epgDetailDescription.textContent=program.description||<?php echo json_encode(_("No description is available.")); ?>;if(epgWatch)epgWatch.hidden=!(program.start<=now&&program.end>now);if(epgDetail)epgDetail.hidden=false;});const tile=document.createElement('div');tile.className=button.className;tile.style.cssText=button.style.cssText;button.className='openap-aptv-epg-program-detail';button.removeAttribute('style');const record=document.createElement('button');record.type='button';record.className='openap-aptv-epg-record';record.disabled=true;record.title=<?php echo json_encode(_("Recording will be available in a future version.")); ?>;record.setAttribute('aria-label',<?php echo json_encode(_("Record")); ?>+': '+title.textContent);record.innerHTML='<i class="fas fa-circle" aria-hidden="true"></i><span>'+<?php echo json_encode(_("Record")); ?>+'</span>';bindRecordingButton(record,channel,program);tile.append(button,record);programs.append(tile);});row.append(channelButton,programs);epgGrid.append(row);});
        };
        const positionEpgNow=function(){if(!epgGrid||epgWindowEnd<=epgWindowStart)return;epgGrid.scrollLeft=Math.max(0,(Date.now()/1000-epgWindowStart)/(epgWindowEnd-epgWindowStart)*1800);epgGrid.scrollTop=0;};
        const loadEpg=async function(reset,refresh){if(window.openapSession.expired||epgLoading||(!reset&&!epgHasMore))return;const loadingStarted=performance.now();let loaded=false;epgLoading=true;if(reset){epgOffset=0;epgItems=[];epgHasMore=true;renderEpgLoading(8);}try{const epgParams={country:epgCountry?.value||'IT',offset:epgOffset,limit:48,refresh:refresh?'1':'0'},providerId=String(providerSwitchSelect?.value||'');if(/^[a-f0-9]{32}$/.test(providerId))epgParams.provider_id=providerId;const response=await fetch('/aptv/api/epg?'+new URLSearchParams(epgParams),{credentials:'same-origin',cache:'no-store'}),data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to load TV Guide.")); ?>);if(!epgCountriesLoaded&&epgCountry&&Array.isArray(data.countries)){const selected=data.country||epgCountry.value;epgCountry.replaceChildren();data.countries.forEach(function(item){const option=document.createElement('option');option.value=item.code;option.textContent=(regionFlags[item.code]||'🌐')+' '+item.name;epgCountry.append(option);});epgCountry.value=selected;epgCountriesLoaded=true;}epgWindowStart=Number(data.window_start);epgWindowEnd=Number(data.window_end);epgItems=epgItems.concat(data.items||[]);epgOffset=Number(data.next_offset??epgItems.length);epgHasMore=Boolean(data.has_more);if(reset){const remaining=Math.max(0,450-(performance.now()-loadingStarted));if(remaining)await new Promise(function(resolve){window.setTimeout(resolve,remaining);});}loaded=true;renderEpg();if(reset)positionEpgNow();}catch(error){epgHasMore=false;if(epgGrid)epgGrid.textContent=error.message;}finally{epgLoading=false;if(loaded&&!window.openapSession.expired&&epgHasMore&&epgGrid&&epgGrid.scrollHeight-epgGrid.scrollTop-epgGrid.clientHeight<240)window.setTimeout(function(){loadEpg(false,false);},0);}};
        const invalidateWallPlayer=function(player){if(!player)return 0;player.wallToken=(player.wallToken||0)+1;window.clearTimeout(player.searchTimer);player.searchTimer=0;window.clearTimeout(player.closeTimer);player.closeTimer=0;if(player.enterFrame)window.cancelAnimationFrame(player.enterFrame);player.enterFrame=0;window.clearTimeout(player.enterTimer);player.enterTimer=0;if(player.searchController){try{player.searchController.abort();}catch(error){}player.searchController=null;}if(player.hls){try{player.hls.destroy();}catch(error){}player.hls=null;}player.video?.pause();return player.wallToken;};
        const stopWallTile=function(player){if(!player)return;invalidateWallPlayer(player);if(player.video){player.video.hidden=false;player.video.removeAttribute('src');player.video.load();}player.tile?.classList.remove('is-active','is-opening','is-playing','is-closing','is-wall-mobile-player-fullscreen','is-wall-tile-fullscreen','is-wall-traditional-player-source');if(player.tile)delete player.tile.dataset.wallEntering;delete player.tile?.dataset.wallClosing;if(player.emptyMessage)player.emptyMessage.hidden=false;if(player.channelInfo)player.channelInfo.hidden=true;if(player.empty)player.empty.hidden=false;if(player.title)player.title.textContent='';};
        const resetWallTile=function(player){if(!player)return;stopWallTile(player);player.item=null;player.pending=null;if(player.search)player.search.value='';player.items=[];player.options?.replaceChildren();};
        const closeWallTile=function(player){if(!player)return;if(player.tile?.classList.contains('is-active')&&!isWallTouchViewport()){if(player.tile.dataset.wallClosing==='true')return;const token=invalidateWallPlayer(player);player.tile.dataset.wallClosing='true';player.tile.classList.remove('is-opening');void player.tile.offsetWidth;player.tile.classList.add('is-closing');player.closeTimer=window.setTimeout(function(){if(!player.tile||player.wallToken!==token)return;player.closeTimer=0;delete player.tile.dataset.wallClosing;player.tile.classList.remove('is-closing');stopWallTile(player);player.item=null;player.pending=null;if(player.search)player.search.value='';player.items=[];player.options?.replaceChildren();updateWallStatus();if(wallChannelPickerOpen&&liveLevel==='channels')renderLiveItems();},160);return;}resetWallTile(player);updateWallStatus();if(wallChannelPickerOpen&&liveLevel==='channels')renderLiveItems();};
        const selectedProviderId=function(){const value=String((wallChannelPickerOpen?wallProviderSwitchSelect?.value:providerSwitchSelect?.value)||'');if(/^[a-f0-9]{32}$/.test(value))return value;const singleProviderId=providerSwitchItems.length===1?String(providerSwitchItems[0]?.id||''):'';return /^[a-f0-9]{32}$/.test(singleProviderId)?singleProviderId:'';};
        const wallSearch=function(player){if(!player)return;const query=String(player.search?.value||'').trim();const token=player.wallToken||0;const generation=wallGeneration;window.clearTimeout(player.searchTimer);player.searchTimer=0;if(player.searchController){try{player.searchController.abort();}catch(error){}player.searchController=null;}if(query.length<2)return;const controller=new AbortController();player.searchController=controller;const params=new URLSearchParams({q:query,kind:'live',limit:'40'}),providerId=selectedProviderId();if(providerId)params.set('provider_id',providerId);fetch('/aptv/api/search?'+params.toString(),{credentials:'same-origin',cache:'no-store',signal:controller.signal}).then(function(response){return response.json();}).then(function(data){if(player.wallToken!==token||generation!==wallGeneration||player.searchController!==controller||wallView?.hidden||player.tile?.dataset.wallClosing==='true')return;if(!data.ok)return;player.items=Array.isArray(data.items)?data.items:[];player.options?.replaceChildren(...player.items.map(function(item){const option=document.createElement('option');option.value=(item.country_code?item.country_code+'| ':'')+item.name;return option;}));const selected=player.items.find(function(item){return ((item.country_code?item.country_code+'| ':'')+item.name)===query||item.name===query;});if(selected)startWallTile(player,selected);}).catch(function(error){if(error.name!=='AbortError'){} }).finally(function(){if(player.searchController===controller)player.searchController=null;});};
        const refreshWallStatus=function(){const activePlayers=wallPlayers.filter(function(player){return player.tile?.classList.contains('is-active');}),activeCount=activePlayers.length,wallAtCapacity=activeCount>=4;wallView?.classList.remove('wall-count-0','wall-count-1','wall-count-2','wall-count-3','wall-count-4');wallView?.classList.add('wall-count-'+activeCount);wallLauncherIcon?.classList.remove('wall-count-0','wall-count-1','wall-count-2','wall-count-3','wall-count-4');wallLauncherIcon?.classList.add('wall-count-'+activeCount);wallPlayers.forEach(function(player){player.tile?.classList.remove('wall-order-1','wall-order-2','wall-order-3','wall-order-4');});activePlayers.forEach(function(player,index){player.tile?.classList.add('wall-order-'+(index+1));});const enteringPlayers=activePlayers.filter(function(player){return player.tile?.dataset.wallEntering==='true';});enteringPlayers.forEach(function(player){delete player.tile.dataset.wallEntering;if(!window.matchMedia('(max-width:991.98px)').matches){const token=player.wallToken;player.enterFrame=window.requestAnimationFrame(function(){player.enterFrame=0;if(!player.tile||player.wallToken!==token||!player.tile.classList.contains('is-active'))return;player.tile.classList.remove('is-opening');void player.tile.offsetWidth;player.tile.classList.add('is-opening');player.enterTimer=window.setTimeout(function(){player.enterTimer=0;if(player.wallToken===token)player.tile?.classList.remove('is-opening');},460);});}});if(wallEmptyState)wallEmptyState.hidden=activeCount>0;if(wallStatus)wallStatus.hidden=activeCount>0;wallLauncherIcon?.classList.toggle('is-single',activeCount===1);wallLauncherIcon?.classList.toggle('has-streams',activeCount>0);if(wallLauncherClean){window.clearTimeout(wallLauncherClean._hideTimer);if(activeCount>0){wallLauncherClean.hidden=false;wallLauncherClean.style.setProperty('display','flex','important');wallLauncherClean.classList.remove('is-hiding');window.requestAnimationFrame(function(){wallLauncherClean.classList.add('is-visible');});}else{wallLauncherClean.classList.remove('is-visible');wallLauncherClean.classList.add('is-hiding');wallLauncherClean._hideTimer=window.setTimeout(function(){wallLauncherClean.hidden=true;wallLauncherClean.style.setProperty('display','none','important');wallLauncherClean.classList.remove('is-hiding');},220);}}if(wallLauncherIcon&&activeCount>wallActiveCount){wallLauncherIcon.classList.remove('is-splitting','is-adding-third','is-adding-fourth');void wallLauncherIcon.offsetWidth;if(wallActiveCount===1&&activeCount===2)wallLauncherIcon.classList.add('is-splitting');else if(wallActiveCount===2&&activeCount===3)wallLauncherIcon.classList.add('is-adding-third');else if(wallActiveCount===3&&activeCount===4)wallLauncherIcon.classList.add('is-adding-fourth');window.setTimeout(function(){wallLauncherIcon.classList.remove('is-splitting','is-adding-third','is-adding-fourth');},380);}wallLauncherCells.forEach(function(cell,index){cell.classList.toggle('is-active',index<activeCount);});syncWallPickerActions();wallActiveCount=activeCount;};
        const updateWallPlayButton=function(){if(!wallPlayStream)return;const playing=wallPlayers.some(function(player){return player.tile?.classList.contains('is-playing');}),label=playing?<?php echo json_encode(_('Stop stream')); ?>:<?php echo json_encode(_('Play stream')); ?>,icon=wallPlayStream.querySelector('i');if(icon)icon.className='fas '+(playing?'fa-stop':'fa-play');wallPlayStream.setAttribute('aria-label',label);wallPlayStream.title=label;};
        const syncWallMuteControl=function(player){if(!player?.mute||!player.video)return;const muted=Boolean(player.video.muted),icon=player.mute.querySelector('i'),label=muted?<?php echo json_encode(_('Unmute')); ?>:<?php echo json_encode(_('Mute')); ?>;if(icon)icon.className='fas '+(muted?'fa-volume-mute':'fa-volume-up');player.mute.setAttribute('aria-label',label);player.mute.title=label;};
        const updateWallStatus=function(){refreshWallStatus();updateWallPlayButton();const hasStreams=wallPlayers.some(function(player){return player.tile?.classList.contains('is-active');}),wallAtCapacity=wallPlayers.filter(function(player){return player.tile?.classList.contains('is-active');}).length>=4;if(wallAdd){wallAdd.disabled=wallAtCapacity;wallAdd.setAttribute('aria-disabled',wallAtCapacity?'true':'false');}if(wallClean){window.clearTimeout(wallClean._hideTimer);if(hasStreams){wallClean.style.removeProperty('display');wallClean.hidden=false;wallClean.classList.remove('is-hiding');window.requestAnimationFrame(function(){wallClean.classList.add('is-visible');});}else{wallClean.classList.remove('is-visible');wallClean.classList.add('is-hiding');wallClean._hideTimer=window.setTimeout(function(){wallClean.hidden=true;wallClean.style.setProperty('display','none','important');wallClean.classList.remove('is-hiding');},220);}}if(wallPlayStream){window.clearTimeout(wallPlayStream._hideTimer);if(hasStreams){wallPlayStream.style.removeProperty('display');wallPlayStream.hidden=false;wallPlayStream.classList.remove('is-hiding');window.requestAnimationFrame(function(){wallPlayStream.classList.add('is-visible');});}else{wallPlayStream.classList.remove('is-visible');wallPlayStream.classList.add('is-hiding');wallPlayStream._hideTimer=window.setTimeout(function(){wallPlayStream.hidden=true;wallPlayStream.style.setProperty('display','none','important');wallPlayStream.classList.remove('is-hiding');},220);}}};
        const wallHlsUrl=function(item){const query=new URLSearchParams({id:String(item?.item_id||'')});const providerId=String(item?.provider_id||wallProviderSwitchSelect?.value||providerSwitchSelect?.value||'');if(/^[a-f0-9]{32}$/.test(providerId))query.set('provider_id',providerId);return '/aptv/api/hls?'+query.toString();};
        const wallConnectionCapacity=function(){return providerSwitchItems.reduce(function(total,item){return total+Math.max(1,Number(item?.max_connections||item?.max_conn||1));},0);};
        const wallAutoplayAllowed=function(){return wallConnectionCapacity()>=2;};
        const startWallPlayback=function(player){if(!player?.item||!player.video||player.tile?.dataset.wallClosing==='true')return false;player.tile?.classList.add('is-playing');if(player.channelInfo)player.channelInfo.hidden=false;if(player.empty)player.empty.hidden=false;player.video.hidden=false;if(player.hls?.startLoad)player.hls.startLoad(-1);const promise=player.video.play();if(promise&&typeof promise.catch==='function')promise.catch(function(){player.tile?.classList.remove('is-playing');updateWallStatus();});updateWallStatus();return true;};
        const stopWallPlayback=function(player){if(!player?.item||!player.video)return;player.tile?.classList.remove('is-playing');if(player.hls?.stopLoad)player.hls.stopLoad();player.video.pause();player.video.hidden=true;if(player.channelInfo)player.channelInfo.hidden=false;};
        const startWallTile=function(player,item){stopWallTile(player);if(!item||!player.video)return;const token=player.wallToken;player.item=item;player.tile?.classList.add('is-active');const channelLabel=(item.country_code?item.country_code+'| ':'')+(item.name||'');player.title.textContent=channelLabel;if(player.channelInfo){player.emptyMessage.hidden=true;player.channelInfo.hidden=false;player.channelName.textContent=channelLabel;player.channelIcon.replaceChildren();if(item.icon_url){const logo=document.createElement('img');logo.src=item.icon_url;logo.alt='';logo.loading='lazy';player.channelIcon.append(logo);}else{const icon=document.createElement('i');icon.className='fas fa-tv';icon.setAttribute('aria-hidden','true');player.channelIcon.append(icon);}player.empty.hidden=false;player.video.hidden=true;}else player.empty.hidden=true;player.video.muted=true;const hlsUrl=wallHlsUrl(item);if(window.Hls&&window.Hls.isSupported()){const hls=new window.Hls(aptvWallHlsOptions);player.hls=hls;hls.loadSource(hlsUrl);hls.attachMedia(player.video);hls.on(window.Hls.Events.MANIFEST_PARSED,function(){if(player.wallToken!==token||player.hls!==hls||player.item!==item||player.tile?.dataset.wallClosing)return;updateWallStatus();if(player.tile?.classList.contains('is-playing'))startWallPlayback(player);});hls.on(window.Hls.Events.ERROR,function(event,data){if(data?.fatal&&player.wallToken===token&&player.hls===hls){player.tile?.classList.remove('is-playing');updateWallStatus();}});}else if(player.video.canPlayType('application/vnd.apple.mpegurl')){player.video.src=hlsUrl;if(player.tile?.classList.contains('is-playing'))player.video.play().catch(function(){});updateWallStatus();}if(wallAutoplayAllowed())startWallPlayback(player);};
        const addWallChannel=function(item){if(!item||!wallView)return;const selectedProvider=wallItemProviderId(item);if(!item.provider_id&&selectedProvider)item=Object.assign({},item,{provider_id:selectedProvider});const existing=wallSelectedPlayerForItem(item);if(existing){closeWallTile(existing);return;}const providerUsed=selectedProvider?wallProviderActivePlayers(selectedProvider).length:0,providerMax=wallProviderMaxConnections(selectedProvider);if(providerUsed>=providerMax){window.openapNotify?.({level:'error',icon:'fa-plug',title:<?php echo json_encode(_('Provider connection limit reached')); ?>,message:<?php echo json_encode(_('This provider has no free connections for another Wall channel.')); ?>,details:[<?php echo json_encode(_('Remove one of its selected channels before adding another.')); ?>]});syncWallPickerActions();return;}const player=wallPlayers.find(function(candidate){return !candidate.tile?.classList.contains('is-active');});if(!player){window.openapNotify?.({level:'error',icon:'fa-tv',title:<?php echo json_encode(_('Live Wall limit reached')); ?>,message:<?php echo json_encode(_('The Live Wall can display up to four channels at the same time.')); ?>,details:[<?php echo json_encode(_('Close a stream before adding another channel.')); ?>]});return;}const label=(item.country_code?item.country_code+'| ':'')+(item.name||'');if(player.search)player.search.value=label;startWallTile(player,item);if(player.tile)player.tile.dataset.wallEntering='true';updateWallStatus();if(wallChannelPickerOpen&&liveLevel==='channels')renderLiveItems();};
        wallPlayStream?.addEventListener('click',function(){const players=wallPlayers.filter(function(player){return player.tile?.classList.contains('is-active');});const stop=players.some(function(player){return player.tile?.classList.contains('is-playing');});players.forEach(function(player){if(stop)stopWallPlayback(player);else startWallPlayback(player);});updateWallStatus();});
        const cleanWall=function(){wallChannelPickerOpen=false;const generation=++wallGeneration;window.clearTimeout(wallCleanupTimer);wallCleanupTimer=0;const activePlayers=wallPlayers.filter(function(player){return player.tile?.classList.contains('is-active');});if(!activePlayers.length){wallPlayers.forEach(function(player){invalidateWallPlayer(player);});updateWallStatus();return;}if(isWallTouchViewport()){activePlayers.forEach(resetWallTile);updateWallStatus();return;}const tokens=new Map();activePlayers.forEach(function(player){tokens.set(player,invalidateWallPlayer(player));if(player.tile.dataset.wallClosing!=='true')player.tile.dataset.wallClosing='true';player.tile.classList.remove('is-opening');});void wallView?.offsetWidth;activePlayers.forEach(function(player){player.tile?.classList.add('is-closing');});wallCleanupTimer=window.setTimeout(function(){wallCleanupTimer=0;if(generation!==wallGeneration)return;activePlayers.forEach(function(player){if(!player.tile||player.wallToken!==tokens.get(player))return;delete player.tile.dataset.wallClosing;player.tile.classList.remove('is-closing');resetWallTile(player);});updateWallStatus();},160);};
        wallClean?.addEventListener('click',cleanWall);
        wallLauncherClean?.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();cleanWall();});
        wallLauncherClean?.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();event.stopPropagation();cleanWall();}});
        let wallAnimationTimer=0;
        let wallMobileFullscreen=false;
        let wallLiveDrawerParent=null;
        let wallLiveDrawerNext=null;
        let wallTraditionalPlayerSession=null;
        let wallTraditionalPlayerParent=null;
        let wallTraditionalPlayerNext=null;
        const wallFullscreenBackground='radial-gradient(circle at 52% 45%,rgba(41,142,151,.24),transparent 29%),radial-gradient(circle at 78% 18%,rgba(22,132,127,.15),transparent 25%),linear-gradient(135deg,#10282c 0%,#0c1d21 48%,#071317 100%)';
        const applyWallFullscreenBackground=function(){wallView?.style.setProperty('background',wallFullscreenBackground,'important');};
        const clearWallFullscreenBackground=function(){wallView?.style.removeProperty('background');}; const setWallFullscreenControls=function(compact){if(!wallPlayControls)return;const closeButton=wallClose;if(compact){wallPlayControls.style.setProperty('border-top','1px solid rgba(132,166,166,.62)','important');wallPlayControls.querySelectorAll('button').forEach(function(button){button.style.setProperty('align-items','center','important');button.style.setProperty('justify-content','center','important');button.style.setProperty('position','relative','important');button.style.setProperty('padding','0','important');button.style.setProperty('border-left','0','important');button.style.setProperty('border-top','1px solid rgba(132,166,166,.5)','important');button.style.setProperty('place-items','center','important');button.style.setProperty('line-height','1','important');button.style.setProperty('text-align','center','important');});const firstRailButton=wallPlayControls.querySelector('button:first-child');if(firstRailButton)firstRailButton.style.setProperty('border-top','0','important');wallPlayControls.querySelectorAll('button > i').forEach(function(icon){icon.style.setProperty('display','block','important');icon.style.setProperty('place-items','center','important');icon.style.setProperty('position','absolute','important');icon.style.setProperty('inset','0','important');icon.style.setProperty('align-items','center','important');icon.style.setProperty('justify-content','center','important');icon.style.setProperty('width','auto','important');icon.style.setProperty('height','auto','important');icon.style.setProperty('margin','0','important');icon.style.setProperty('transform','none','important');icon.style.setProperty('line-height','35px','important');icon.style.setProperty('text-align','center','important');});wallPlayControls.querySelectorAll('button > i').forEach(function(icon){icon.style.removeProperty('display');icon.style.removeProperty('place-items');icon.style.removeProperty('position');icon.style.removeProperty('inset');icon.style.removeProperty('align-items');icon.style.removeProperty('justify-content');icon.style.removeProperty('width');icon.style.removeProperty('height');icon.style.removeProperty('margin');icon.style.removeProperty('transform');icon.style.removeProperty('line-height');icon.style.removeProperty('text-align');});wallPlayControls.querySelectorAll('button > i').forEach(function(icon){icon.style.setProperty('position','relative','important');icon.style.setProperty('top','-4px','important');});wallPlayControls.style.setProperty('height','auto','important');wallPlayControls.style.setProperty('width','35px','important');wallPlayControls.style.setProperty('min-width','35px','important');wallPlayControls.style.setProperty('max-width','35px','important');wallPlayControls.style.setProperty('top','50%','important');wallPlayControls.style.setProperty('right','12px','important');wallPlayControls.style.setProperty('left','auto','important');wallPlayControls.style.setProperty('transform','translateY(-50%)','important');wallPlayControls.style.setProperty('flex-direction','column','important');wallPlayControls.querySelectorAll('button').forEach(function(button){button.style.setProperty('height','35px','important');button.style.setProperty('width','35px','important');button.style.setProperty('min-width','35px','important');button.style.setProperty('max-width','35px','important');button.style.setProperty('box-sizing','border-box','important');button.style.setProperty('min-height','35px','important');button.style.setProperty('max-height','35px','important');});if(closeButton){closeButton.style.setProperty('height','35px','important');closeButton.style.setProperty('width','35px','important');closeButton.style.setProperty('min-height','35px','important');closeButton.style.setProperty('max-height','35px','important');closeButton.style.setProperty('top','10px','important');}}else{wallPlayControls.style.removeProperty('border-top');wallPlayControls.querySelectorAll('button').forEach(function(button){button.style.removeProperty('display');button.style.removeProperty('align-items');button.style.removeProperty('justify-content');button.style.removeProperty('padding');button.style.removeProperty('place-items');button.style.removeProperty('line-height');button.style.removeProperty('text-align');button.style.removeProperty('position');button.style.removeProperty('border-left');button.style.removeProperty('border-top');});wallPlayControls.querySelectorAll('button > i').forEach(function(icon){icon.style.removeProperty('display');icon.style.removeProperty('align-items');icon.style.removeProperty('justify-content');icon.style.removeProperty('width');icon.style.removeProperty('height');icon.style.removeProperty('margin');icon.style.removeProperty('transform');icon.style.removeProperty('line-height');icon.style.removeProperty('text-align');icon.style.removeProperty('place-items');icon.style.removeProperty('position');icon.style.removeProperty('inset');});wallPlayControls.style.removeProperty('height');wallPlayControls.style.removeProperty('width');wallPlayControls.style.removeProperty('min-width');wallPlayControls.style.removeProperty('max-width');wallPlayControls.style.removeProperty('top');wallPlayControls.style.removeProperty('right');wallPlayControls.style.removeProperty('left');wallPlayControls.style.removeProperty('transform');wallPlayControls.style.removeProperty('flex-direction');wallPlayControls.querySelectorAll('button').forEach(function(button){button.style.removeProperty('height');button.style.removeProperty('width');button.style.removeProperty('min-width');button.style.removeProperty('max-width');button.style.removeProperty('box-sizing');button.style.removeProperty('min-height');button.style.removeProperty('max-height');});if(closeButton){closeButton.style.removeProperty('height');closeButton.style.removeProperty('width');closeButton.style.removeProperty('min-height');closeButton.style.removeProperty('max-height');closeButton.style.removeProperty('top');}}};
        const mountWallLiveDrawer=function(){if(!liveDrawer||!wallView||liveDrawer.parentElement===wallView)return;wallLiveDrawerParent=liveDrawer.parentElement;wallLiveDrawerNext=liveDrawer.nextSibling;wallView.appendChild(liveDrawer);};
        const restoreWallLiveDrawer=function(){if(!liveDrawer||!wallLiveDrawerParent||liveDrawer.parentElement!==wallView)return;if(wallLiveDrawerNext&&wallLiveDrawerNext.parentNode===wallLiveDrawerParent)wallLiveDrawerParent.insertBefore(liveDrawer,wallLiveDrawerNext);else wallLiveDrawerParent.appendChild(liveDrawer);wallLiveDrawerParent=null;wallLiveDrawerNext=null;clearWallFullscreenBackground();};
        const lockWallLandscape=async function(){
          if(!wallView||document.fullscreenElement!==wallView||!screen.orientation?.lock)return false;
          for(const orientation of ['landscape-primary','landscape']){
            try{await screen.orientation.lock(orientation);return true;}catch(error){}
          }
          return false;
        };
        const enterMobileWallFullscreen=async function(){if(!isWallTouchViewport()||!wallView?.requestFullscreen||document.fullscreenElement)return;mountWallLiveDrawer();applyWallFullscreenBackground();try{try{await wallView.requestFullscreen({navigationUI:'hide'});}catch(error){await wallView.requestFullscreen();}await waitForFullscreenState(true);wallMobileFullscreen=document.fullscreenElement===wallView;wallView.classList.toggle('is-wall-mobile-fullscreen-active',wallMobileFullscreen);setWallFullscreenControls(wallMobileFullscreen);if(wallMobileFullscreen){await lockWallLandscape();window.setTimeout(lockWallLandscape,120);window.setTimeout(lockWallLandscape,420);}}catch(error){wallMobileFullscreen=false;wallView.classList.remove('is-wall-mobile-fullscreen-active');restoreWallLiveDrawer();}};
        const leaveMobileWallFullscreen=function(){if(!wallMobileFullscreen&&document.fullscreenElement!==wallView)return;wallMobileFullscreen=false;wallView?.classList.remove('is-wall-mobile-fullscreen-active');setWallFullscreenControls(false);if(document.fullscreenElement===wallView){Promise.resolve(document.exitFullscreen?.()).catch(function(){}).finally(restoreWallLiveDrawer);}else restoreWallLiveDrawer();};
        document.addEventListener("fullscreenchange",function(){if(document.fullscreenElement!==wallView){const unexpectedWallExit=Boolean(wallMobileFullscreen);wallNeedsFullscreenResume=false;if(unexpectedWallExit){wallMobileFullscreen=false;wallView.hidden=true;wallView.classList.remove("is-open","is-closing","is-wall-mobile-fullscreen-active");document.querySelector(".openap-iptv-page")?.classList.remove("is-wall");setWallFullscreenControls(false);closeWall();}else wallView?.classList.remove("is-wall-mobile-fullscreen-active");restoreWallLiveDrawer();clearWallFullscreenBackground();}});
        const restoreMobileWallLandscape=async function(){if(!isWallTouchViewport()||(!wallMobileFullscreen&&!wallNeedsFullscreenResume)||document.visibilityState==='hidden'||!wallView)return;try{if(document.fullscreenElement!==wallView&&wallView.requestFullscreen){mountWallLiveDrawer();applyWallFullscreenBackground();try{await wallView.requestFullscreen({navigationUI:'hide'});}catch(error){await wallView.requestFullscreen();}await waitForFullscreenState(true);}if(document.fullscreenElement===wallView){wallMobileFullscreen=true;wallView.classList.add('is-wall-mobile-fullscreen-active');setWallFullscreenControls(true);await lockWallLandscape();window.setTimeout(lockWallLandscape,180);}}catch(error){}};
        let wallNeedsFullscreenResume=false;
        const tryResumeWallFullscreen=function(){if(!isWallTouchViewport()||!wallView||wallView.hidden||!document.querySelector('.openap-iptv-page')?.classList.contains('is-wall')||document.fullscreenElement===wallView)return;wallNeedsFullscreenResume=true;restoreMobileWallLandscape().then(function(){wallNeedsFullscreenResume=document.fullscreenElement!==wallView;});};
        document.addEventListener("visibilitychange",function(){const mobileWallOpen=Boolean(wallView&&!wallView.hidden&&document.querySelector(".openap-iptv-page")?.classList.contains("is-wall"));if(!mobileWallOpen)return;if(document.visibilityState==="hidden"){wallNeedsFullscreenResume=false;wallView.hidden=true;wallView.classList.remove("is-open","is-closing","is-wall-mobile-fullscreen-active");document.querySelector(".openap-iptv-page")?.classList.remove("is-wall");leaveMobileWallFullscreen();closeWall();return;}wallNeedsFullscreenResume=false;wallView.hidden=true;wallView.classList.remove("is-open","is-closing","is-wall-mobile-fullscreen-active");document.querySelector(".openap-iptv-page")?.classList.remove("is-wall");leaveMobileWallFullscreen();closeWall();});
        window.addEventListener("pageshow",function(){const mobileWallOpen=Boolean(wallView&&!wallView.hidden&&document.querySelector(".openap-iptv-page")?.classList.contains("is-wall"));if(mobileWallOpen){wallNeedsFullscreenResume=false;wallView.hidden=true;wallView.classList.remove("is-open","is-closing","is-wall-mobile-fullscreen-active");document.querySelector(".openap-iptv-page")?.classList.remove("is-wall");leaveMobileWallFullscreen();closeWall();}});
        window.addEventListener("pagehide",function(){const mobileWallOpen=Boolean(wallView&&!wallView.hidden&&document.querySelector(".openap-iptv-page")?.classList.contains("is-wall"));if(mobileWallOpen){wallNeedsFullscreenResume=false;wallView.hidden=true;wallView.classList.remove("is-open","is-closing","is-wall-mobile-fullscreen-active");document.querySelector(".openap-iptv-page")?.classList.remove("is-wall");leaveMobileWallFullscreen();closeWall();}});
        document.addEventListener('pointerdown',tryResumeWallFullscreen,true);
        screen.orientation?.addEventListener?.('change',function(){if(document.visibilityState==='visible'&&wallMobileFullscreen&&document.fullscreenElement===wallView&&screen.orientation.type.startsWith('portrait')){window.setTimeout(lockWallLandscape,80);window.setTimeout(restoreMobileWallLandscape,220);}});
        document.addEventListener('click',function(event){if(event.target.closest('[data-openap-wall-close]'))leaveMobileWallFullscreen();},true);
        const openWall=function(trigger){if(!wallView)return;const generation=++wallGeneration;window.clearTimeout(wallCleanupTimer);wallCleanupTimer=0;window.clearTimeout(wallAnimationTimer);stopChannel();setLiveOpen(false);setMoviesOpen(false);setSettingsOpen(false);closeEpg();wallView.hidden=false;wallView.classList.remove('is-closing');wallView.classList.remove('is-open');wallView.classList.remove('is-opening');wallView.style.setProperty('transform','none');const wallRect=wallView.getBoundingClientRect(),triggerRect=(trigger||wallToggles[0])?.getBoundingClientRect();if(triggerRect){wallView.style.setProperty('--openap-wall-origin-x',(triggerRect.left+triggerRect.width/2-wallRect.left)+'px');wallView.style.setProperty('--openap-wall-origin-y',(triggerRect.top+triggerRect.height/2-wallRect.top)+'px');}wallView.style.removeProperty('transform');void wallView.offsetWidth;wallPlayers.forEach(function(player){if(player.pending&&generation===wallGeneration){const item=player.pending;player.pending=null;startWallTile(player,item);}});window.requestAnimationFrame(function(){if(generation===wallGeneration)wallView.classList.add('is-open');});wallToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded','true');});document.querySelector('.openap-iptv-page')?.classList.add('is-wall');};
        const closeWall=function(){if(!wallView)return;if(wallTraditionalPlayerSession)restoreWallTraditionalPlayer();wallChannelPickerOpen=false;stopChannel();++wallGeneration;window.clearTimeout(wallCleanupTimer);wallCleanupTimer=0;window.clearTimeout(wallAnimationTimer);wallPlayers.forEach(function(player){const item=player.item;invalidateWallPlayer(player);player.tile?.classList.remove('is-playing');if(player.video){player.video.removeAttribute('src');player.video.load();}if(item)player.pending=item;});updateWallStatus();wallView.classList.remove('is-open');wallView.classList.add('is-closing');wallAnimationTimer=window.setTimeout(function(){wallView.hidden=true;wallView.classList.remove('is-closing');},480);wallToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded','false');});document.querySelector('.openap-iptv-page')?.classList.remove('is-wall');};
        let wallTraditionalPlayerAnimation=null;
        let wallTraditionalPlayerReturnTimer=0;
        const setWallTraditionalPlayerChrome=function(active){const closeButton=playerSurface?.querySelector('[data-openap-aptv-player-close]'),closeIcon=closeButton?.querySelector('i'),fullscreenIcon=playerFullscreen?.querySelector('i');if(closeButton){closeButton.setAttribute('aria-label',active?<?php echo json_encode(_('Return to Wall')); ?>:<?php echo json_encode(_('Close player')); ?>);closeButton.title=active?<?php echo json_encode(_('Return to Wall')); ?>:<?php echo json_encode(_('Close player')); ?>;}if(closeIcon)closeIcon.className='fas '+(active?'fa-window-restore':'fa-times');if(playerFullscreen){playerFullscreen.setAttribute('aria-label',active?<?php echo json_encode(_('Return to Wall')); ?>:<?php echo json_encode(_('Full screen')); ?>);playerFullscreen.title=active?<?php echo json_encode(_('Return to Wall')); ?>:<?php echo json_encode(_('Full screen')); ?>;}if(fullscreenIcon)fullscreenIcon.className='fas '+(active?'fa-compress':'fa-expand');};
        const animateWallTraditionalPlayer=function(player,entering){if(!playerSurface||!player?.tile||!playerSurface.animate||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;const surface=playerSurface.getBoundingClientRect(),tile=player.tile.getBoundingClientRect();if(!surface.width||!surface.height||!tile.width||!tile.height)return;const dx=tile.left-surface.left,dy=tile.top-surface.top,sx=tile.width/surface.width,sy=tile.height/surface.height;const frames=entering?[{transform:'translate('+dx+'px,'+dy+'px) scale('+sx+','+sy+')'},{transform:'translate(0,0) scale(1,1)'}]:[{transform:'translate(0,0) scale(1,1)'},{transform:'translate('+dx+'px,'+dy+'px) scale('+sx+','+sy+')'}];if(wallTraditionalPlayerAnimation)wallTraditionalPlayerAnimation.cancel();const animation=playerSurface.animate(frames,{duration:460,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.effect.setKeyframes(animation.effect.getKeyframes().map(function(frame){return Object.assign(frame,{transformOrigin:'0 0'});}));wallTraditionalPlayerAnimation=animation;animation.onfinish=function(){if(wallTraditionalPlayerAnimation===animation&&entering){wallTraditionalPlayerAnimation=null;animation.cancel();}};animation.oncancel=function(){if(wallTraditionalPlayerAnimation===animation)wallTraditionalPlayerAnimation=null;};};
        const syncPlayerMuteControl=function(media){
          if(!playerMute||!media)return;
          const muted=Boolean(media.muted),icon=playerMute.querySelector('i'),label=muted?<?php echo json_encode(_('Unmute')); ?>:<?php echo json_encode(_('Mute')); ?>;
          if(icon)icon.className='fas '+(muted?'fa-volume-mute':'fa-volume-up');
          playerMute.setAttribute('aria-label',label);
          playerMute.title=label;
        };
        const restoreWallTraditionalPlayer=function(){
          const session=wallTraditionalPlayerSession;
          if(!session)return false;
          const player=session.player;
          window.clearTimeout(wallTraditionalPlayerReturnTimer);
          wallTraditionalPlayerReturnTimer=0;
          const finish=function(){
            if(wallTraditionalPlayerAnimation){wallTraditionalPlayerAnimation.cancel();wallTraditionalPlayerAnimation=null;}
            // Hide the moving surface before changing its containing block.
            // Reparenting a still-visible player first lets the browser paint
            // one frame at the original full-size position, creating a ghost
            // flash at the end of the return animation.
            if(playerSurface){playerSurface.hidden=true;playerSurface.classList.remove('is-visible');playerSurface.style.pointerEvents='none';}
            if(video){video.pause();video.srcObject=null;video.removeAttribute('src');video.load();}
            wallView?.classList.remove('is-wall-traditional-player');
            document.body.classList.remove('openap-wall-traditional-player-returning');
            wallView?.classList.remove('is-wall-traditional-player-returning');
            if(playerSurface&&wallTraditionalPlayerParent){if(wallTraditionalPlayerNext&&wallTraditionalPlayerNext.parentNode===wallTraditionalPlayerParent)wallTraditionalPlayerParent.insertBefore(playerSurface,wallTraditionalPlayerNext);else wallTraditionalPlayerParent.appendChild(playerSurface);}
            wallTraditionalPlayerParent=null;wallTraditionalPlayerNext=null;playerSurface?.classList.remove('is-wall-traditional-player','is-mini','is-cursor-idle');document.body.classList.remove('openap-wall-traditional-player-active');player.tile?.classList.remove('is-wall-traditional-player-source');if(player.tile){player.tile.classList.remove('is-wall-traditional-player-source-reveal');window.requestAnimationFrame(function(){if(!player.tile)return;player.tile.classList.add('is-wall-traditional-player-source-reveal');window.setTimeout(function(){player.tile?.classList.remove('is-wall-traditional-player-source-reveal');},300);});}setWallTraditionalPlayerChrome(false);syncWallMuteControl(player);wallTraditionalPlayerSession=null;if(player.item===session.item)startWallPlayback(player);updateWallStatus();
          };
          const prepareReturn=function(){
            document.body.classList.remove('openap-wall-traditional-player-active');
            document.body.classList.add('openap-wall-traditional-player-returning');
            wallView?.classList.add('is-wall-traditional-player-returning');
            window.clearTimeout(wallTraditionalPlayerReturnTimer);
            wallTraditionalPlayerReturnTimer=window.setTimeout(function(){
              document.body.classList.remove('openap-wall-traditional-player-returning');
              wallView?.classList.remove('is-wall-traditional-player-returning');
              wallTraditionalPlayerReturnTimer=0;
            },470);
            window.requestAnimationFrame(function(){
              window.requestAnimationFrame(function(){
                if(playerSurface&&wallView?.contains(playerSurface)&&player.tile?.classList.contains('is-wall-traditional-player-source')){
                  animateWallTraditionalPlayer(player,false);
                  window.setTimeout(finish,470);
                }else finish();
              });
            });
          };
          if(playerSurface&&wallView?.contains(playerSurface)&&player.tile?.classList.contains('is-wall-traditional-player-source'))prepareReturn();else finish();
          return true;
        };
        const enterWallTraditionalPlayer=function(player){
          if(!player?.item||!wallView||!playerSurface)return false;
          if(wallTraditionalPlayerSession){restoreWallTraditionalPlayer();return true;}
          // The player surface can still carry the animation used when it was
          // moved to/from the mini-player (or into browser fullscreen). Cancel
          // those effects before taking its geometry; otherwise the Wall
          // promotion may be painted at its final size with no visible flight.
          if(typeof cancelPlayerMiniAnimation==='function')cancelPlayerMiniAnimation();
          if(typeof cancelPlayerFullscreenAnimation==='function')cancelPlayerFullscreenAnimation();
          window.clearTimeout(wallTraditionalPlayerReturnTimer);
          wallTraditionalPlayerReturnTimer=0;
          document.body.classList.remove('openap-wall-traditional-player-returning');
          wallView.classList.remove('is-wall-traditional-player-returning');
          if(wallTraditionalPlayerAnimation){wallTraditionalPlayerAnimation.cancel();wallTraditionalPlayerAnimation=null;}
          playerSurface.style.removeProperty('transform');
          playerSurface.style.removeProperty('transform-origin');
          const captureStreamMethod=player.video.captureStream||player.video.webkitCaptureStream;if(typeof captureStreamMethod!=='function')return false;
          if(!player.tile||!player.video)return false;
          if(!player.tile.classList.contains('is-playing'))startWallPlayback(player);
          const captureStream=captureStreamMethod.call(player.video);if(!captureStream)return false;
          wallTraditionalPlayerSession={player:player,item:player.item,captureStream:captureStream};wallTraditionalPlayerParent=playerSurface.parentElement;wallTraditionalPlayerNext=playerSurface.nextSibling;
          const tileRect=player.tile.getBoundingClientRect();
          player.tile.classList.add('is-wall-traditional-player-source');
          document.body.classList.add('openap-wall-traditional-player-active');
          video.pause();video.srcObject=null;video.removeAttribute('src');video.load();video.srcObject=captureStream;video.muted=true;video.volume=player.video.volume;
          currentLiveChannel=player.item;playerMode='live';if(playerName)playerName.textContent=player.item.name||<?php echo json_encode(_('Live channel')); ?>;if(playerLogo){const logo=String(player.item.icon_url||'');playerLogo.hidden=!logo;if(logo)playerLogo.src=logo;}
          wallView.appendChild(playerSurface);wallView.classList.add('is-wall-traditional-player');playerSurface.classList.remove('is-mini','is-cursor-idle');playerSurface.classList.add('is-wall-traditional-player');playerSurface.hidden=false;playerSurface.inert=false;playerSurface.style.pointerEvents='auto';playerSurface.classList.add('is-visible');if(playerState)playerState.hidden=true;if(vodControls){vodControls.hidden=false;vodControls.classList.remove('is-vod');}setWallTraditionalPlayerChrome(true);syncPlayerMuteControl(player.video);syncWallMuteControl(player);if(player.video.paused)startWallPlayback(player);video.play().catch(function(){});window.requestAnimationFrame(function(){if(!wallTraditionalPlayerSession)return;const surface=playerSurface.getBoundingClientRect();if(tileRect.width&&tileRect.height&&surface.width&&surface.height){const dx=tileRect.left-surface.left,dy=tileRect.top-surface.top,sx=tileRect.width/surface.width,sy=tileRect.height/surface.height;const animation=playerSurface.animate([{transform:'translate('+dx+'px,'+dy+'px) scale('+sx+','+sy+')'},{transform:'translate(0,0) scale(1,1)'}],{duration:460,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.effect.setKeyframes(animation.effect.getKeyframes().map(function(frame){return Object.assign(frame,{transformOrigin:'0 0'});}));wallTraditionalPlayerAnimation=animation;animation.onfinish=function(){if(wallTraditionalPlayerAnimation===animation){wallTraditionalPlayerAnimation=null;animation.cancel();}};animation.oncancel=function(){if(wallTraditionalPlayerAnimation===animation)wallTraditionalPlayerAnimation=null;};}});return true;
        };
        const leaveMobileWallPlayerFullscreen=function(){if(wallTraditionalPlayerSession){restoreWallTraditionalPlayer();return;}if(!wallView?.classList.contains('is-wall-mobile-player-fullscreen'))return;const player=wallPlayers.find(function(candidate){return candidate.tile?.classList.contains('is-wall-mobile-player-fullscreen');});wallView.classList.remove('is-wall-mobile-player-fullscreen');player?.tile?.classList.remove('is-wall-mobile-player-fullscreen');if(player?.fullscreen)player.fullscreen.querySelector('i').className='fas fa-expand';};
        const enterWallFullscreen=async function(player){if(!player?.item||wallFullscreenSession)return;if(wallTraditionalPlayerSession){await togglePlayerFullscreen();return;}if(enterWallTraditionalPlayer(player))return;if(!player.tile?.requestFullscreen)return;wallTileFullscreenSession=player;player.tile.classList.add('is-wall-tile-fullscreen');if(player.fullscreen)player.fullscreen.querySelector('i').className='fas fa-compress';try{try{await player.tile.requestFullscreen({navigationUI:'hide'});}catch(error){await player.tile.requestFullscreen();}await waitForFullscreenState(true);}catch(error){player.tile.classList.remove('is-wall-tile-fullscreen');wallTileFullscreenSession=null;if(player.fullscreen)player.fullscreen.querySelector('i').className='fas fa-expand';}};
        document.addEventListener('fullscreenchange',function(){if(wallTileFullscreenSession&&document.fullscreenElement!==wallTileFullscreenSession.tile){const session=wallTileFullscreenSession;wallTileFullscreenSession=null;session.tile?.classList.remove('is-wall-tile-fullscreen');if(session.fullscreen)session.fullscreen.querySelector('i').className='fas fa-expand';}if(!wallFullscreenSession||document.fullscreenElement)return;const session=wallFullscreenSession;wallFullscreenSession=null;stopChannel();wallPlayers.forEach(function(player){player.item=null;player.pending=null;});session.items.slice(0,4).forEach(function(item,index){wallPlayers[index].pending=item;});openWall(wallToggles[0]);enterMobileWallFullscreen();window.requestAnimationFrame(function(){wallPlayers.forEach(function(player){if(!player.item||!player.tile?.classList.contains('is-active'))return;player.tile.classList.add('is-playing');if(player.channelInfo)player.channelInfo.hidden=false;if(player.empty)player.empty.hidden=false;if(player.video)player.video.hidden=false;if(player.hls?.startLoad)player.hls.startLoad(-1);player.video?.play().catch(function(){});});updateWallStatus();});});
        wallToggles.forEach(function(toggle){toggle.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();openWall(toggle);toggle.setAttribute('aria-expanded','true');enterMobileWallFullscreen();});});document.addEventListener('click',function(event){const toggle=event.target.closest('[data-openap-wall-toggle]');if(toggle){event.preventDefault();openWall(toggle);toggle.setAttribute('aria-expanded','true');enterMobileWallFullscreen();}});wallClose?.addEventListener('click',closeWall);wallAdd?.addEventListener('click',function(){if(wallAdd.disabled)return;wallChannelPickerOpen=true;if(liveFilter)liveFilter.value='';setLiveOpen(true,wallAdd);if(liveInitialized)loadRegions();});wallPlayers.forEach(function(player){player.search?.addEventListener('input',function(){window.clearTimeout(player.searchTimer);player.searchTimer=window.setTimeout(function(){player.searchTimer=0;wallSearch(player);},260);});player.mute?.addEventListener('click',function(){player.video.muted=!player.video.muted;syncWallMuteControl(player);});player.fullscreen?.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();if(wallTraditionalPlayerSession===null)enterWallFullscreen(player);else restoreWallTraditionalPlayer();});player.tile?.addEventListener('dblclick',function(event){if(event.target.closest('button'))return;enterWallFullscreen(player);});player.close?.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();if(wallTraditionalPlayerSession?.player===player){restoreWallTraditionalPlayer();return;}if(player.tile?.classList.contains('is-wall-mobile-player-fullscreen'))leaveMobileWallPlayerFullscreen();closeWallTile(player);});});
        updateWallStatus();
        const openEpg=function(){if(!epgView)return;stopChannel();closeWall();setLiveOpen(false);setMoviesOpen(false);setSettingsOpen(false);epgView.hidden=false;document.querySelector('.openap-iptv-page')?.classList.add('is-guide');loadEpg(true,false);};
        epgToggles.forEach(function(toggle){toggle.addEventListener('click',openEpg);});epgClose?.addEventListener('click',closeEpg);epgNow?.addEventListener('click',positionEpgNow);epgRefresh?.addEventListener('click',function(){loadEpg(true,true);});epgCountry?.addEventListener('change',function(){if(epgDetail)epgDetail.hidden=true;loadEpg(true,false);});epgGrid?.addEventListener('scroll',function(){if(epgHours)epgHours.style.transform='translateX(-'+epgGrid.scrollLeft+'px)';if(epgGrid.scrollHeight-epgGrid.scrollTop-epgGrid.clientHeight<240)loadEpg(false,false);},{passive:true});document.querySelector('[data-openap-epg-detail-close]')?.addEventListener('click',function(){if(epgDetail)epgDetail.hidden=true;});epgWatch?.addEventListener('click',function(){if(epgSelectedChannel){closeEpg();playChannel(epgSelectedChannel);}});

        let activeMediaSection='live',libraryKind='vod',libraryProviderGeneration=0,movieDetailGeneration=0,moviesLevel='regions',moviesRegion='',moviesCategory='',moviesCategoryTitle='',moviesItems=[],movieOffset=0,movieHasMore=false,movieLoadingNow=false,movieLoadFailed=false,moviesInitialized=false,movieSearchTimer=0,movieSearchMode=false,selectedSeries=null,seriesEpisodesMode=false,seriesSeasons=null,seriesActiveSeason='',playingSeriesView=null,playingEpisodeId='',playingMovieId='',preserveSearchCatalogSelection=false;
        const providerRequestControllers=new Set();
        const staleProviderRequest=function(){const error=new Error('Stale provider request');error.name='AbortError';return error;};
        const invalidateProviderRequests=function(){
          providerRequestControllers.forEach(function(controller){try{controller.abort();}catch(error){}});
          providerRequestControllers.clear();
          libraryProviderGeneration++;
          if(typeof searchGeneration!=='undefined')searchGeneration++;
          if(typeof globalSearchRequest!=='undefined')globalSearchRequest++;
          if(typeof liveGlobalSearchRequest!=='undefined')liveGlobalSearchRequest++;
          if(typeof liveRequestId!=='undefined')liveRequestId++;
          if(typeof wallGeneration!=='undefined')wallGeneration++;
        };
        const providerRequest=async function(endpoint,params,message,generation){
          const requestGeneration=Number.isInteger(generation)?generation:libraryProviderGeneration;
          const requestParams=Object.assign({},params||{}),providerId=selectedProviderId();
          if(providerId)requestParams.provider_id=providerId;
          if(requestGeneration!==libraryProviderGeneration||providerId!==selectedProviderId())throw staleProviderRequest();
          const controller=new AbortController();providerRequestControllers.add(controller);
          try{
            const response=await fetch(endpoint+'?'+new URLSearchParams(requestParams),{credentials:'same-origin',cache:requestParams.view==='nav'?'no-cache':'no-store',signal:controller.signal});
            const payload=await response.json();
            if(requestGeneration!==libraryProviderGeneration||providerId!==selectedProviderId())throw staleProviderRequest();
            if(!response.ok||!payload.ok)throw new Error(payload.message||message);
            return payload;
          }finally{providerRequestControllers.delete(controller);}
        };
        const catalogRequest=async function(params){return providerRequest('/aptv/api/catalog',params,<?php echo json_encode(_("Unable to load channels.")); ?>,libraryProviderGeneration);};
        const openapOriginalFetch=window.fetch.bind(window);
        window.fetch=function(input,init){
          if(typeof input==='string'&&input.startsWith('/aptv/api/vod/')){
            const url=new URL(input,window.location.href),needsKind=/^\/aptv\/api\/vod\/(?:probe|direct|play|hls\/start|audio-start|audio-hls\/start)$/.test(url.pathname);
            if(needsKind&&url.searchParams.has('id')&&!url.searchParams.has('kind'))url.searchParams.set('kind',playerLibraryKind==='series'||libraryKind==='series'?'series':'vod');
            input=url.pathname+url.search+(url.hash||'');
          }
          if(typeof input==='string'&&input.startsWith('/aptv/api/search')){
            const url=new URL(input,window.location.href),providerId=selectedProviderId();
            if(providerId&&!url.searchParams.has('provider_id')){url.searchParams.set('provider_id',providerId);input=url.pathname+url.search+(url.hash||'');}
          }
          return openapOriginalFetch(input,init);
        };
        const expandableMediaSections=new Set(['live','vod','series']);
        let previousMediaSectionForSelector=activeMediaSection,mediaSelectorCaretTimer=0;
        const syncMediaSectionSelector=function(){
          const nextSection=activeMediaSection;
          const sectionChanged=previousMediaSectionForSelector!==nextSection;
          const animateCaret=sectionChanged&&expandableMediaSections.has(previousMediaSectionForSelector)&&expandableMediaSections.has(nextSection);
          const suppressCaret=sectionChanged&&!animateCaret&&(previousMediaSectionForSelector==='recordings'||nextSection==='recordings');
          if(sectionChanged){
            window.clearTimeout(mediaSelectorCaretTimer);
            if(footerNavigation){
              footerNavigation.classList.toggle('is-caret-sliding',animateCaret);
              footerNavigation.classList.toggle('is-caret-suppressed',suppressCaret);
            }
            if(mediaSectionNav)mediaSectionNav.classList.toggle('is-caret-suppressed',suppressCaret);
            if(animateCaret||suppressCaret)mediaSelectorCaretTimer=window.setTimeout(function(){
              if(footerNavigation)footerNavigation.classList.remove('is-caret-sliding','is-caret-suppressed');
              if(mediaSectionNav)mediaSectionNav.classList.remove('is-caret-suppressed');
              mediaSelectorCaretTimer=0;
            },suppressCaret&&window.matchMedia('(min-width:992px)').matches?220:380);
          }
          if(mediaSectionNav){
            mediaSectionNav.classList.toggle('is-section-live',nextSection==='live');
            mediaSectionNav.classList.toggle('is-section-recordings',nextSection==='recordings');
            mediaSectionNav.classList.toggle('is-section-movies',nextSection==='vod');
            mediaSectionNav.classList.toggle('is-section-series',nextSection==='series');
          }
          previousMediaSectionForSelector=nextSection;
        };
        syncMediaSectionSelector();
        let libraryOrderEditing=false,libraryOrderSnapshot=[],libraryDraggedItem=null,libraryDragGrip=null;const selectedLibraryOrderItems=new Set();
        const vodRequest=async function(params){params=Object.assign({},params);if(params.view!=='regions'&&params.view!=='nav'&&moviesRegion)params.region=moviesRegion;const endpoint=libraryKind==='series'?'/aptv/api/series':'/aptv/api/vod';return providerRequest(endpoint,params,<?php echo json_encode(_("Unable to load the catalog.")); ?>,libraryProviderGeneration);};
        const navRequest=async function(){const endpoint=libraryKind==='series'?'/aptv/api/series':'/aptv/api/vod';try{return await providerRequest(endpoint,{view:'nav'},<?php echo json_encode(_("Unable to load the navigation catalog.")); ?>,libraryProviderGeneration);}catch(error){if(error?.name==='AbortError')throw error;return null;}};
        const catalogOrderRequest=async function(scope,parent){try{const data=await providerRequest('/aptv/api/catalog/order',{scope:scope,parent:parent},<?php echo json_encode(_("Unable to load the catalog order.")); ?>,libraryProviderGeneration);return Array.isArray(data.order)?data.order:[];}catch(error){if(error?.name==='AbortError')throw error;return []}};
        const finishLibraryPointerDrag=function(event){if(libraryDragGrip&&libraryDragGrip.hasPointerCapture?.(event.pointerId))libraryDragGrip.releasePointerCapture(event.pointerId);if(libraryDraggedItem)setOrderDragState(moviesList,libraryDraggedItem,false);libraryDraggedItem=null;libraryDragGrip=null;};
        moviesList?.addEventListener('pointerdown',function(event){if(!libraryOrderEditing||!(event.target instanceof Element))return;const grip=event.target.closest('.openap-iptv-order-grip'),item=grip?.closest('.openap-iptv-live-item');if(!grip||!item||item.parentNode!==moviesList)return;event.preventDefault();libraryDraggedItem=item;libraryDragGrip=grip;setOrderDragState(moviesList,item,true);grip.setPointerCapture?.(event.pointerId);});
        moviesList?.addEventListener('pointermove',function(event){if(!libraryDraggedItem)return;event.preventDefault();reorderListItemAtPoint(moviesList,libraryDraggedItem,event);});
        moviesList?.addEventListener('pointerup',finishLibraryPointerDrag);
        moviesList?.addEventListener('pointercancel',finishLibraryPointerDrag);
        moviesList?.addEventListener('dragstart',function(event){if(!libraryOrderEditing||!(event.target instanceof Element))return;const item=event.target.closest('.openap-iptv-live-item');if(!item||item.parentNode!==moviesList)return;libraryDraggedItem=item;setOrderDragState(moviesList,item,true);});
        moviesList?.addEventListener('dragover',function(event){if(!libraryDraggedItem)return;event.preventDefault();event.stopPropagation();reorderListItemAtPoint(moviesList,libraryDraggedItem,event);},true);
        moviesList?.addEventListener('dragend',function(){if(libraryDraggedItem)setOrderDragState(moviesList,libraryDraggedItem,false);libraryDraggedItem=null;libraryDragGrip=null;},true);
        const applySavedClientOrder=function(items,order,idKey){if(!Array.isArray(order)||!order.length)return items;const positions=new Map(order.map(function(id,index){return [String(id),index];})),fallback=order.length+items.length;return items.slice().sort(function(left,right){const leftPosition=positions.has(String(left[idKey]))?positions.get(String(left[idKey])):fallback;const rightPosition=positions.has(String(right[idKey]))?positions.get(String(right[idKey])):fallback;if(leftPosition!==rightPosition)return leftPosition-rightPosition;return String(left.name||left.label||'').localeCompare(String(right.name||right.label||''),undefined,{numeric:true,sensitivity:'base'});});};
        const renderMovieMenu=function(){if(!moviesList)return;const needle=String(moviesFilter?.value||'').trim().toLocaleLowerCase();moviesList.replaceChildren();moviesItems.filter(function(item){return String(item.label||item.name||'').toLocaleLowerCase().includes(needle);}).forEach(function(item){const button=document.createElement('button');button.type='button';button.className='openap-iptv-live-item';button.dataset.orderId=String(moviesLevel==='regions'?item.code:item.category_id);if(libraryOrderEditing){button.classList.add('is-ordering');button.draggable=true;const selector=document.createElement('span');selector.className='openap-iptv-order-selector';selector.setAttribute('role','checkbox');selector.setAttribute('aria-checked','false');selector.tabIndex=0;const toggleSelection=function(){const id=button.dataset.orderId,selected=!selectedLibraryOrderItems.has(id);if(selected)selectedLibraryOrderItems.add(id);else selectedLibraryOrderItems.delete(id);selector.setAttribute('aria-checked',selected?'true':'false');button.classList.toggle('is-selected',selected);updateLibrarySelectionActions();};selector.addEventListener('click',function(event){event.stopPropagation();toggleSelection();});selector.addEventListener('keydown',function(event){if(event.key===' '||event.key==='Enter'){event.preventDefault();toggleSelection();}});button.append(selector);const grip=document.createElement('i');grip.className='fas fa-grip-vertical openap-iptv-order-grip';button.append(grip);button.addEventListener('dragstart',function(event){libraryDraggedItem=button;button.classList.add('is-dragging');event.dataTransfer.effectAllowed='move';});button.addEventListener('dragend',function(){button.classList.remove('is-dragging');libraryDraggedItem=null;});button.addEventListener('dragover',function(event){event.preventDefault();if(!libraryDraggedItem||libraryDraggedItem===button)return;const box=button.getBoundingClientRect();moviesList.insertBefore(libraryDraggedItem,event.clientY<box.top+box.height/2?button:button.nextSibling);});}let icon;if(moviesLevel==='regions'){icon=createRegionFlag(item.code);}else{icon=document.createElement('i');icon.className='fas fa-folder';icon.setAttribute('aria-hidden','true');}const text=document.createElement('span');text.textContent=item.label||item.name;const count=document.createElement('small');count.textContent=new Intl.NumberFormat().format(Number(moviesLevel==='regions'?item.categories:item.movies));button.append(icon,text,count);button.addEventListener('click',function(){if(libraryOrderEditing)return;if(moviesLevel==='regions')loadMovieCategories(item.code,'forward');else{loadMovies(item.category_id,item.name,false);if(window.matchMedia('(max-width:991.98px)').matches)setMoviesOpen(false,true);}});moviesList.append(button);});};
        const animateMovieNavigation=function(direction){if(!moviesList||!direction||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;const className=direction==='back'?'openap-live-nav-enter-back':'openap-live-nav-enter-forward';const entries=Array.from(moviesList.children);entries.forEach(function(entry,index){entry.style.setProperty('--openap-live-nav-index',String(Math.min(index,10)));entry.classList.add(className);});window.setTimeout(function(){entries.forEach(function(entry){entry.classList.remove(className);entry.style.removeProperty('--openap-live-nav-index');});},480);};
        const updateMovieGroupIndicator=function(){if(!moviesList)return;let indicator=moviesList.querySelector('.openap-library-group-indicator');if(moviesLevel!=='categories'||!moviesCategory){if(indicator)indicator.hidden=true;moviesList.querySelectorAll('.is-current-group').forEach(function(button){button.classList.remove('is-current-group');button.removeAttribute('aria-current');});return;}const button=Array.from(moviesList.querySelectorAll('.openap-iptv-live-item[data-order-id]')).find(function(item){return item.dataset.orderId===String(moviesCategory);});if(!button){if(indicator)indicator.hidden=true;return;}if(!indicator){indicator=document.createElement('span');indicator.className='openap-library-group-indicator';indicator.setAttribute('aria-hidden','true');moviesList.append(indicator);}moviesList.querySelectorAll('.is-current-group').forEach(function(item){const active=item===button;item.classList.toggle('is-current-group',active);if(active)item.setAttribute('aria-current','true');else item.removeAttribute('aria-current');});button.classList.add('is-current-group');button.setAttribute('aria-current','true');indicator.hidden=false;indicator.style.height=button.offsetHeight+'px';indicator.style.transform='translateY('+button.offsetTop+'px)';indicator.classList.add('is-ready');};
        if(moviesList)new MutationObserver(function(){requestAnimationFrame(updateMovieGroupIndicator);}).observe(moviesList,{childList:true});
        if(moviesHeading)new MutationObserver(function(){requestAnimationFrame(updateMovieGroupIndicator);}).observe(moviesHeading,{childList:true,characterData:true,subtree:true});
        const loadInitialMovieSelection=async function(regions,navData=null){
          if(!['vod','series'].includes(libraryKind)||!Array.isArray(regions)||!regions.length)return;
          const initialKind=libraryKind,catalogGeneration=libraryProviderGeneration,region=regions.find(function(item){return Number(item.categories)>0;})||regions[0];
          try{
            moviesRegion=region.code;
            const scope=libraryKind==='series'?'series_category':'vod_category';
            const [nav,order]=await Promise.all([navData?Promise.resolve(navData):navRequest(),catalogOrderRequest(scope,region.code)]);
            const categories=nav?{items:applySavedClientOrder((nav.categories||[]).filter(function(item){return String(item.region||'')===String(region.code);}),order,'category_id')}:await vodRequest({view:'categories',region:region.code});
            if(catalogGeneration!==libraryProviderGeneration||libraryKind!==initialKind||moviesLevel!=='regions'||!Array.isArray(categories.items)||!categories.items.length)return;
            const category=categories.items.find(function(item){return Number(item.movies)>0;})||categories.items[0];
            await loadMovies(category.category_id,category.name,false);
          }catch(error){
            if(movieDescription)movieDescription.textContent=error.message;
          }
        };
        const loadMovieRegions=async function(direction){const catalogGeneration=libraryProviderGeneration;if(libraryOrderEditing)setLibraryOrderEditing(false);const skipInitialSelection=preserveSearchCatalogSelection;preserveSearchCatalogSelection=false;moviesLevel='regions';moviesRegion='';moviesList?.classList.add('is-country-list');if(moviesFilter)moviesFilter.value='';if(moviesBack){moviesBack.disabled=true;moviesBack.setAttribute('aria-disabled','true');}moviesList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';try{const scope=libraryKind==='series'?'series_region':'vod_region',[nav,order]=await Promise.all([navRequest(),catalogOrderRequest(scope,'global')]);const data=nav?{items:applySavedClientOrder(nav.regions,order,'code')}:await vodRequest({view:'regions'});if(catalogGeneration!==libraryProviderGeneration)return;moviesItems=data.items.map(function(item){item.label=item.code==='OTHER'?<?php echo json_encode(_("Other")); ?>:(regionNames[item.code]||item.code);return item;});renderMovieMenu();animateMovieNavigation(direction);moviesInitialized=true;if(!skipInitialSelection)loadInitialMovieSelection(moviesItems,nav);}catch(error){if(catalogGeneration===libraryProviderGeneration)moviesList.textContent=error.message;}};
        const resetLibraryCatalogForProvider=async function(){searchGeneration++;libraryProviderGeneration++;searchReturnState=null;episodeSearchReturn=null;pendingSeriesPostersBackAnimation=false;preserveMovieDetailDuringCatalogReturn=false;clearTimeout(movieSearchTimer);moviesInitialized=false;moviesLevel='regions';moviesRegion='';moviesCategory='';moviesCategoryTitle='';moviesItems=[];movieOffset=0;movieHasMore=false;movieLoadingNow=false;movieLoadFailed=false;movieSearchMode=false;selectedSeries=null;seriesEpisodesMode=false;seriesSeasons=null;seriesActiveSeason='';if(movieSearch)movieSearch.value='';if(moviesFilter)moviesFilter.value='';if(searchBack)searchBack.hidden=true;if(seasonSelect)seasonSelect.hidden=true;if(seriesCatalogBack)seriesCatalogBack.hidden=true;if(moviePlay)delete moviePlay.dataset.mediaKind;if(movieGrid)movieGrid.replaceChildren();if(movieTitle)movieTitle.textContent=libraryKind==='series'?<?php echo json_encode(_("Choose a TV series")); ?>:<?php echo json_encode(_("Choose a movie")); ?>;if(movieDescription)movieDescription.textContent=<?php echo json_encode(_("Select a poster below to view the details.")); ?>;if(movieMeta)movieMeta.textContent='';if(movieBackdrop)movieBackdrop.style.backgroundImage='';if(moviePlayOptions)moviePlayOptions.hidden=true;if(moviePlay)moviePlay.hidden=true;if(moviePlayDanish)moviePlayDanish.hidden=true;if(seriesEpisodes)seriesEpisodes.hidden=true;if((activeMediaSection==='vod'||activeMediaSection==='series')&&moviesDrawer?.classList.contains('is-open'))await loadMovieRegions('back');};
        const loadMovieCategories=async function(region,direction){if(providerSwitchBusy)return;const catalogGeneration=libraryProviderGeneration;if(libraryOrderEditing)setLibraryOrderEditing(false);moviesLevel='categories';moviesRegion=region;moviesList?.classList.remove('is-country-list');movieSearchMode=false;if(movieSearch)movieSearch.value='';updateLibrarySearchContext();if(moviesFilter)moviesFilter.value='';if(moviesBack){moviesBack.disabled=false;moviesBack.removeAttribute('aria-disabled');}moviesList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';try{const scope=libraryKind==='series'?'series_category':'vod_category',[nav,order]=await Promise.all([navRequest(),catalogOrderRequest(scope,region)]);const data=nav?{items:applySavedClientOrder((nav.categories||[]).filter(function(item){return String(item.region||'')===String(region);}),order,'category_id')}:await vodRequest({view:'categories',region:region});if(catalogGeneration!==libraryProviderGeneration)return;moviesItems=data.items;renderMovieMenu();animateMovieNavigation(direction);}catch(error){if(catalogGeneration===libraryProviderGeneration)moviesList.textContent=error.message;}};
        const loadMovieDetails=async function(item,card,preserveExistingDetail=false){if(providerSwitchBusy)return;const detailGeneration=searchGeneration,catalogGeneration=libraryProviderGeneration,detailRequestGeneration=++movieDetailGeneration,detailKind=libraryKind,providerId=selectedProviderId();movieGrid?.querySelectorAll('.is-selected').forEach(function(node){node.classList.remove('is-selected');});card?.classList.add('is-selected');if(!preserveExistingDetail){[movieBackdrop,movieTitle,movieMeta,movieDescription].filter(Boolean).forEach(function(node){node.getAnimations?.().forEach(function(animation){animation.cancel();});});if(movieTitle)movieTitle.textContent='';if(movieMeta)movieMeta.textContent='';if(movieDescription)movieDescription.textContent='';if(movieBackdrop)movieBackdrop.style.backgroundImage=item.poster_url?'url("'+item.poster_url.replace(/["\\]/g,'')+'")':'';}try{const params=new URLSearchParams({id:String(item.item_id)});if(providerId)params.set('provider_id',providerId);const response=await fetch('/aptv/api/'+(libraryKind==='series'?'series':'vod')+'/details?'+params.toString(),{credentials:'same-origin',cache:'no-store'});const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to load details.")); ?>);if(detailRequestGeneration!==movieDetailGeneration||detailKind!==libraryKind||activeMediaSection!==detailKind||detailGeneration!==searchGeneration||catalogGeneration!==libraryProviderGeneration)return;const detail=payload.item||{};if(movieTitle)movieTitle.textContent=detail.title||item.name;if(movieDescription)movieDescription.textContent=detail.description||<?php echo json_encode(_("No description is available.")); ?>;if(movieBackdrop&&detail.poster_url)movieBackdrop.style.backgroundImage='url("'+String(detail.poster_url).replace(/["\\]/g,'')+'")';const seasons=libraryKind==='series'&&Number(detail.seasons)>0?Number(detail.seasons)+' '+<?php echo json_encode(_("seasons")); ?>:'';const meta=[detail.year,detail.genre,detail.duration,seasons,Number(detail.rating)>0?'★ '+Number(detail.rating).toFixed(1):''].filter(Boolean);if(movieMeta)movieMeta.textContent=meta.join(' · ');if(libraryKind==='vod'&&moviePlay){moviePlay.dataset.movieId=item.item_id;moviePlay.hidden=false;if(moviePlayOptions)moviePlayOptions.hidden=false;if(seriesEpisodes)seriesEpisodes.hidden=true;if(moviePlayDanish){moviePlayDanish.dataset.movieId=item.item_id;moviePlayDanish.hidden=String(item.item_id)!=='160289';}}else if(libraryKind==='series'&&seriesEpisodes){selectedSeries={item:item,detail:detail};seriesEpisodes.dataset.seriesId=item.item_id;if(preserveExistingDetail)morphPlayToSeriesEpisodes();else{seriesEpisodes.hidden=false;if(moviePlay)moviePlay.hidden=true;}if(moviePlayDanish)moviePlayDanish.hidden=true;if(moviePlayOptions)moviePlayOptions.hidden=false;}if(!preserveExistingDetail)window.requestAnimationFrame(function(){animateMovieDetailEntrance();});}catch(error){if(detailRequestGeneration!==movieDetailGeneration||detailKind!==libraryKind||activeMediaSection!==detailKind||detailGeneration!==searchGeneration||catalogGeneration!==libraryProviderGeneration)return;if(movieDescription)movieDescription.textContent=error.message;}};
        const refreshMovieNowPlaying=function(){if(!movieGrid)return;movieGrid.querySelectorAll('.openap-iptv-now-playing').forEach(function(label){label.remove();});if(libraryKind!=='vod'||!playingMovieId)return;const card=movieGrid.querySelector('[data-movie-id="'+CSS.escape(playingMovieId)+'"]'),poster=card?.querySelector('.openap-iptv-movie-poster');if(poster){const now=document.createElement('em');now.className='openap-iptv-now-playing';now.textContent='NOW PLAYING';poster.append(now);}};
        const renderMovies=function(items,append){if(!movieGrid)return;if(seasonSelect)seasonSelect.hidden=true;if(!append){resetPosterQueue();movieGrid.replaceChildren();}items.forEach(function(item){const card=document.createElement('button');card.type='button';card.className='openap-iptv-movie-card';const poster=document.createElement('div');poster.className='openap-iptv-movie-poster';if(item.poster_url){const image=document.createElement('img');image.alt='';poster.classList.add('is-placeholder');poster.append(image);queuePosterImage(image,item.poster_url,poster,card);}else poster.classList.add('is-placeholder');const rating=document.createElement('span');rating.textContent=Number(item.rating)>0?Number(item.rating).toFixed(1):'--';const title=document.createElement('strong');title.textContent=item.name;poster.append(rating);card.append(poster,title);card.dataset.movieId=item.item_id;card.addEventListener('click',function(){if(card.classList.contains('is-selected'))return;loadMovieDetails(item,card);});movieGrid.append(card);});refreshMovieNowPlaying();};
        const morphSeriesEpisodesToPlay=function(){if(!moviePlay){if(seriesEpisodes)seriesEpisodes.hidden=true;return;}const source=seriesEpisodes,sourceRect=source&&!source.hidden?source.getBoundingClientRect():null,reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;if(source)source.hidden=true;moviePlay.hidden=false;if(!sourceRect||reduced)return;const targetRect=moviePlay.getBoundingClientRect(),fromWidth=Math.round(sourceRect.width)+'px',fromHeight=Math.round(sourceRect.height)+'px',toWidth=Math.round(targetRect.width)+'px',toHeight=Math.round(targetRect.height)+'px';moviePlay.style.width=fromWidth;moviePlay.style.height=fromHeight;moviePlay.style.whiteSpace='nowrap';moviePlay.style.overflow='hidden';const animation=moviePlay.animate([{width:fromWidth,height:fromHeight,opacity:.86},{width:toWidth,height:toHeight,opacity:1}],{duration:360,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.finished.catch(function(){}).finally(function(){moviePlay.style.removeProperty('width');moviePlay.style.removeProperty('height');moviePlay.style.removeProperty('white-space');moviePlay.style.removeProperty('overflow');});};
        const morphPlayToSeriesEpisodes=function(){if(!seriesEpisodes){if(moviePlay)moviePlay.hidden=true;return;}const source=moviePlay,sourceRect=source&&!source.hidden?source.getBoundingClientRect():null,reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;if(source)source.hidden=true;seriesEpisodes.hidden=false;if(!sourceRect||reduced)return;const targetRect=seriesEpisodes.getBoundingClientRect(),fromWidth=Math.round(sourceRect.width)+'px',fromHeight=Math.round(sourceRect.height)+'px',toWidth=Math.round(targetRect.width)+'px',toHeight=Math.round(targetRect.height)+'px';seriesEpisodes.style.width=fromWidth;seriesEpisodes.style.height=fromHeight;seriesEpisodes.style.whiteSpace='nowrap';seriesEpisodes.style.overflow='hidden';const animation=seriesEpisodes.animate([{width:fromWidth,height:fromHeight,opacity:.86},{width:toWidth,height:toHeight,opacity:1}],{duration:360,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.finished.catch(function(){}).finally(function(){seriesEpisodes.style.removeProperty('width');seriesEpisodes.style.removeProperty('height');seriesEpisodes.style.removeProperty('white-space');seriesEpisodes.style.removeProperty('overflow');});};
        const selectSeriesEpisode=function(episode,card){movieGrid?.querySelectorAll('.is-selected').forEach(function(node){node.classList.remove('is-selected');});card?.classList.add('is-selected');if(movieTitle)movieTitle.textContent=episode.title||<?php echo json_encode(_("Episode")); ?>;if(movieDescription)movieDescription.textContent=episode.description||<?php echo json_encode(_("No description is available.")); ?>;if(movieMeta)movieMeta.textContent=[<?php echo json_encode(_("Season")); ?>+' '+episode.season,<?php echo json_encode(_("Episode")); ?>+' '+episode.episode,episode.duration,episode.air_date,Number(episode.rating)>0?'★ '+Number(episode.rating).toFixed(1):''].filter(Boolean).join(' · ');if(moviePlay){moviePlay.dataset.movieId=episode.item_id;moviePlay.dataset.mediaKind='episode';}morphSeriesEpisodesToPlay();if(moviePlayDanish)moviePlayDanish.hidden=true;if(moviePlayOptions)moviePlayOptions.hidden=false;if(window.matchMedia('(max-width:767.98px)').matches)window.requestAnimationFrame(function(){if(movieBackdrop?.style.transform)animateMovieDetailEntrance();});};
        let seriesSeasonTransitioning=false,pendingSeriesPostersBackAnimation=false;
        const renderSeriesSeason=function(seasons,season){if(!movieGrid)return;seriesActiveSeason=String(season);const numbers=Object.keys(seasons).sort(function(a,b){return Number(a)-Number(b);});if(seasonSelect){seasonSelect.replaceChildren(...numbers.map(function(number){const option=document.createElement('option');option.value=String(number);option.textContent=<?php echo json_encode(_("Season")); ?>+' '+number;return option;}));seasonSelect.value=String(season);seasonSelect.hidden=false;}movieGrid.replaceChildren();movieGrid.scrollTop=0;const fallbackPoster=String(selectedSeries?.detail?.poster_url||selectedSeries?.item?.poster_url||'');(seasons[String(season)]||[]).forEach(function(episode){const card=document.createElement('button');card.type='button';card.className='openap-iptv-movie-card openap-iptv-episode-card';card.dataset.episodeId=String(episode.item_id);const selectedEpisodeId=String(playingSeriesView?.episodeId||playingEpisodeId||'');if(String(episode.item_id)===selectedEpisodeId)card.classList.add('is-selected');const tile=document.createElement('div');tile.className='openap-iptv-movie-poster';const previewUrl=String(episode.preview_url||'');if(previewUrl||fallbackPoster){const image=document.createElement('img');const useFallback=Boolean(episode.preview_fallback||fallbackPoster);image.src=useFallback?fallbackPoster:previewUrl;image.alt='';image.loading='lazy';image.addEventListener('error',function(){if(useFallback&&previewUrl&&!episode.preview_fallback){image.src=previewUrl;return;}if(!useFallback&&fallbackPoster){image.src=fallbackPoster;return;}image.remove();tile.classList.add('is-placeholder');},{once:false});tile.append(image);if(useFallback&&previewUrl&&previewUrl!==fallbackPoster&&!episode.preview_fallback){const previewImage=new Image();previewImage.decoding='async';previewImage.onload=function(){if(image.isConnected)image.src=previewUrl;};previewImage.src=previewUrl;}}else tile.classList.add('is-placeholder');if(String(episode.item_id)===String(playingEpisodeId)){const now=document.createElement('em');now.className='openap-iptv-now-playing';now.textContent='NOW PLAYING';tile.append(now);}const badge=document.createElement('span');badge.textContent='S'+String(episode.season).padStart(2,'0')+' E'+String(episode.episode).padStart(2,'0');const title=document.createElement('strong');title.textContent=episode.title;tile.append(badge);card.append(tile,title);card.addEventListener('click',function(){if(card.classList.contains('is-selected'))return;selectSeriesEpisode(episode,card);});movieGrid.append(card);});if(moviesHeading)moviesHeading.textContent=selectedSeries?.detail?.title||<?php echo json_encode(_("TV Series")); ?>;if(moviesCount)moviesCount.textContent=new Intl.NumberFormat().format((seasons[String(season)]||[]).length)+' '+<?php echo json_encode(_("episodes")); ?>;};
        const selectFirstSeriesEpisode=function(seasons,season){const firstEpisode=(seasons[String(season)]||[])[0],firstCard=firstEpisode?movieGrid?.querySelector('[data-episode-id="'+CSS.escape(String(firstEpisode.item_id))+'"]'):null;if(firstEpisode&&firstCard)selectSeriesEpisode(firstEpisode,firstCard);};
        const transitionSeriesSeason=async function(seasons,season){if(seriesSeasonTransitioning||!movieGrid||seriesActiveSeason===String(season))return;if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){renderSeriesSeason(seasons,season);selectFirstSeriesEpisode(seasons,season);return;}seriesSeasonTransitioning=true;if(seasonSelect)seasonSelect.disabled=true;const oldEpisodes=Array.from(movieGrid.querySelectorAll('.openap-iptv-episode-card')),animations=oldEpisodes.map(function(card){return card.animate([{opacity:1},{opacity:0}],{duration:150,easing:'ease-in',fill:'forwards'});});try{await Promise.all(animations.map(function(animation){return animation.finished.catch(function(){});}));renderSeriesSeason(seasons,season);selectFirstSeriesEpisode(seasons,season);}finally{animations.forEach(function(animation){animation.cancel();});seriesSeasonTransitioning=false;if(seasonSelect)seasonSelect.disabled=false;}};
        seasonSelect?.addEventListener('change',function(){if(seriesSeasons)transitionSeriesSeason(seriesSeasons,seasonSelect.value);});
        const isDesktopSeriesMotion=function(){return window.matchMedia('(min-width: 992px)').matches&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches;};
        const cancelSeriesMotion=function(node){node?.getAnimations?.().forEach(function(animation){animation.cancel();});};
        const animateSeriesCatalogEntrance=function(){if(!movieCatalog||!isDesktopSeriesMotion())return;cancelSeriesMotion(movieCatalog);movieCatalog.animate([{opacity:0,translate:'0 100%'},{opacity:1,translate:'0 0'}],{duration:460,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});};
        const animateSeriesPostersEntrance=function(){if(!movieGrid||!isDesktopSeriesMotion())return;cancelSeriesMotion(movieGrid);movieGrid.animate([{opacity:0,transform:'translateX(72px)'},{opacity:1,transform:'translateX(0)'}],{duration:420,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});};
        const animateSeriesEpisodesEntrance=function(){if(!movieGrid||window.matchMedia('(prefers-reduced-motion: reduce)').matches||window.matchMedia('(min-width: 992px)').matches)return;movieGrid.querySelectorAll('.openap-iptv-episode-card').forEach(function(card,index){card.animate([{opacity:0,transform:'translateY(24px)'},{opacity:1,transform:'translateY(0)'}],{duration:360,delay:Math.min(index,8)*24,easing:'cubic-bezier(.22,1,.36,1)',fill:'backwards'});});};
        const openSeriesEpisodes=async function(){if(providerSwitchBusy||!selectedSeries||!seriesEpisodes?.dataset.seriesId)return;const catalogGeneration=libraryProviderGeneration,providerId=selectedProviderId();if(movieLoading)movieLoading.hidden=false;try{const params=new URLSearchParams({id:String(seriesEpisodes.dataset.seriesId)});if(providerId)params.set('provider_id',providerId);const response=await fetch('/aptv/api/series/episodes?'+params.toString(),{credentials:'same-origin',cache:'no-store'});const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to load episodes.")); ?>);if(catalogGeneration!==libraryProviderGeneration)return;const seasons=payload.seasons||{},first=Object.keys(seasons).sort(function(a,b){return Number(a)-Number(b);})[0];if(!first)throw new Error(<?php echo json_encode(_("No episodes are available.")); ?>);if(movieSearchMode){episodeSearchReturn={nodes:Array.from(movieGrid.childNodes),scroll:movieGrid.scrollTop,heading:moviesHeading.textContent,count:moviesCount.textContent,selected:selectedSeries};searchBack.hidden=true;}seriesSeasons=seasons;seriesEpisodesMode=true;if(seriesCatalogBack)seriesCatalogBack.hidden=false;renderSeriesSeason(seasons,first);if(movieGrid)movieGrid.scrollTop=0;const firstEpisode=(seasons[String(first)]||[])[0],firstCard=firstEpisode?movieGrid?.querySelector('[data-episode-id="'+CSS.escape(String(firstEpisode.item_id))+'"]'):null;if(firstEpisode&&firstCard)selectSeriesEpisode(firstEpisode,firstCard);if(isDesktopSeriesMotion())animateSeriesCatalogEntrance();else animateSeriesEpisodesEntrance();}catch(error){if(catalogGeneration===libraryProviderGeneration&&movieDescription)movieDescription.textContent=error.message;}finally{if(movieLoading)movieLoading.hidden=true;}};
        seriesEpisodes?.addEventListener('click',openSeriesEpisodes);
        seriesCatalogBack?.addEventListener('click',function(){if(libraryKind!=='series'||!seriesEpisodesMode)return;if(episodeSearchReturn){const saved=episodeSearchReturn;episodeSearchReturn=null;seriesEpisodesMode=false;seriesSeasons=null;seriesActiveSeason='';seriesCatalogBack.hidden=true;seasonSelect.hidden=true;searchBack.hidden=false;movieGrid.replaceChildren(...saved.nodes);movieGrid.scrollTop=saved.scroll;moviesHeading.textContent=saved.heading;moviesCount.textContent=saved.count;if(moviePlay)delete moviePlay.dataset.mediaKind;if(saved.selected)loadMovieDetails(saved.selected.item,movieGrid.querySelector('.is-selected'));return;}if(!moviesCategory)return;seriesCatalogBack.hidden=true;seriesEpisodesMode=false;seriesSeasons=null;seriesActiveSeason='';if(moviePlay)delete moviePlay.dataset.mediaKind;pendingSeriesPostersBackAnimation=true;preserveMovieDetailDuringCatalogReturn=true;loadMovies(moviesCategory,moviesCategoryTitle||<?php echo json_encode(_("TV Series")); ?>,false);});
        const maybeLoadMoreMovies=function(){if(!movieGrid||seriesEpisodesMode||movieSearchMode||movieLoadingNow||movieLoadFailed||!movieHasMore)return;const remaining=movieGrid.scrollHeight-movieGrid.scrollTop-movieGrid.clientHeight;if(remaining<300)loadMovies(moviesCategory,moviesHeading?.textContent||'',true);};
        const showMovieLoadRetry=function(message){if(!movieGrid)return;movieGrid.querySelector('[data-openap-movie-load-retry]')?.remove();const status=document.createElement('div');status.dataset.openapMovieLoadRetry='';status.className='openap-iptv-live-loading';const retry=document.createElement('button');retry.type='button';retry.className='btn btn-sm btn-outline-light';retry.textContent=(message?message+' · ':'')+<?php echo json_encode(_("Retry")); ?>;retry.addEventListener('click',function(){status.remove();movieLoadFailed=false;loadMovies(moviesCategory,moviesHeading?.textContent||'',true);});status.append(retry);movieGrid.append(status);};
        const loadMovies=async function(category,title,append,selectFirst=true){if(providerSwitchBusy)return;const catalogGeneration=libraryProviderGeneration;if(movieLoadingNow||append&&!movieHasMore)return;movieLoadingNow=true;movieLoadFailed=false;movieGrid?.querySelector('[data-openap-movie-load-retry]')?.remove();if(movieLoading)movieLoading.hidden=false;if(!append){searchGeneration++;searchReturnState=null;searchBack.hidden=true;movieSearchMode=false;seriesEpisodesMode=false;if(seasonSelect)seasonSelect.hidden=true;if(seriesCatalogBack)seriesCatalogBack.hidden=true;moviesCategory=category;moviesCategoryTitle=title;movieOffset=0;movieHasMore=true;if(moviesHeading)moviesHeading.textContent=title;if(moviesView)moviesView.hidden=false;}try{const data=await vodRequest({view:'movies',category:category,offset:movieOffset,limit:30});if(catalogGeneration!==libraryProviderGeneration)return;renderMovies(data.items,append);movieOffset+=data.items.length;movieHasMore=Boolean(data.has_more);if(!append&&pendingSeriesPostersBackAnimation){pendingSeriesPostersBackAnimation=false;window.requestAnimationFrame(function(){animateSeriesPostersEntrance();});}if(moviesCount)moviesCount.textContent=new Intl.NumberFormat().format(data.total)+' '+(libraryKind==='series'?<?php echo json_encode(_("series")); ?>:<?php echo json_encode(_("movies")); ?>);if(!append&&selectFirst&&data.items.length){const firstCard=movieGrid?.querySelector('.openap-iptv-movie-card');const preserveDetail=preserveMovieDetailDuringCatalogReturn;preserveMovieDetailDuringCatalogReturn=false;await loadMovieDetails(data.items[0],firstCard,preserveDetail);}}catch(error){if(catalogGeneration!==libraryProviderGeneration)return;pendingSeriesPostersBackAnimation=false;preserveMovieDetailDuringCatalogReturn=false;movieLoadFailed=true;if(append)showMovieLoadRetry(error.message);else if(movieGrid)movieGrid.textContent=error.message;}finally{movieLoadingNow=false;if(catalogGeneration===libraryProviderGeneration){if(movieLoading)movieLoading.hidden=true;window.requestAnimationFrame(function(){window.requestAnimationFrame(maybeLoadMoreMovies);});}}};
        let searchReturnState=null,searchGeneration=0,episodeSearchReturn=null,preserveMovieDetailDuringCatalogReturn=false;
        const searchBack=document.createElement('button');
        searchBack.type='button';searchBack.className='openap-iptv-series-catalog-back';searchBack.hidden=true;
        searchBack.setAttribute('aria-label',<?php echo json_encode(_("Back to catalog")); ?>);
        searchBack.innerHTML='<i class="fas fa-arrow-left" aria-hidden="true"></i>';
        moviesHeading?.before(searchBack);
        const saveSearchPosition=function(){
          if(searchReturnState)return;
          const elements=[movieTitle,movieMeta,movieDescription,movieBackdrop,moviePlayOptions,moviePlay,moviePlayDanish,seriesEpisodes,seasonSelect,seriesCatalogBack];
          searchReturnState={nodes:Array.from(movieGrid.childNodes),scroll:movieGrid.scrollTop,heading:moviesHeading.textContent,count:moviesCount.textContent,episodes:seriesEpisodesMode,selected:selectedSeries,seasons:seriesSeasons,season:seriesActiveSeason,
            elements:elements.filter(Boolean).map(function(node){return {node:node,html:node.innerHTML,attributes:Array.from(node.attributes).map(function(a){return [a.name,a.value];})};})};
        };
        searchBack.addEventListener('click',function(){
          const saved=searchReturnState;if(!saved)return;
          searchGeneration++;clearTimeout(movieSearchTimer);movieSearch.value='';movieSearchMode=false;
          movieGrid.replaceChildren(...saved.nodes);moviesHeading.textContent=saved.heading;moviesCount.textContent=saved.count;
          seriesEpisodesMode=saved.episodes;selectedSeries=saved.selected;seriesSeasons=saved.seasons;seriesActiveSeason=saved.season;
          saved.elements.forEach(function(entry){if([movieTitle,movieMeta,movieDescription,seasonSelect].includes(entry.node))entry.node.innerHTML=entry.html;Array.from(entry.node.attributes).forEach(function(a){entry.node.removeAttribute(a.name);});entry.attributes.forEach(function(a){entry.node.setAttribute(a[0],a[1]);});});
          if(seasonSelect)seasonSelect.value=seriesActiveSeason;
          movieGrid.scrollTop=saved.scroll;searchReturnState=null;searchBack.hidden=true;if(movieLoading)movieLoading.hidden=true;
        });
        const searchMovies=async function(query){if(providerSwitchBusy)return;query=String(query||'').trim();if(query.length<2)return;saveSearchPosition();const generation=++searchGeneration,catalogGeneration=libraryProviderGeneration;movieSearchMode=true;searchBack.hidden=false;if(movieLoading)movieLoading.hidden=false;try{const params={view:'search',q:query,limit:60};if(libraryKind==='series'&&moviesRegion&&moviesRegion!=='OTHER')params.region=moviesRegion;const data=await vodRequest(params);if(generation!==searchGeneration||catalogGeneration!==libraryProviderGeneration)return;if(String(movieSearch?.value||'').trim()!==query)return;seriesEpisodesMode=false;if(seriesCatalogBack)seriesCatalogBack.hidden=true;renderMovies(data.items,false);movieGrid.scrollTop=0;if(moviesHeading)moviesHeading.textContent=<?php echo json_encode(_("Search results")); ?>;if(moviesCount)moviesCount.textContent=new Intl.NumberFormat().format(data.total)+' '+<?php echo json_encode(_("results")); ?>;if(data.items.length){loadMovieDetails(data.items[0],movieGrid?.querySelector('.openap-iptv-movie-card'));}else{if(movieTitle)movieTitle.textContent=libraryKind==='series'?<?php echo json_encode(_("No TV series found")); ?>:<?php echo json_encode(_("No movie found")); ?>;if(movieDescription)movieDescription.textContent=<?php echo json_encode(_("Try a different title.")); ?>;if(moviePlayOptions)moviePlayOptions.hidden=true;}}catch(error){if(generation!==searchGeneration||catalogGeneration!==libraryProviderGeneration)return;if(movieGrid)movieGrid.textContent=error.message;}finally{if(generation===searchGeneration&&catalogGeneration===libraryProviderGeneration&&movieLoading)movieLoading.hidden=true;}};
        const updateLibrarySelectionActions=function(){const disabled=selectedLibraryOrderItems.size===0;if(libraryMoveTop)libraryMoveTop.disabled=disabled;if(libraryMoveBottom)libraryMoveBottom.disabled=disabled;};
        const setLibraryOrderEditing=function(enabled){
          if(!enabled&&libraryOrderEditing&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches){
            moviesList?.classList.remove('is-reordering');
            const header=libraryEditButton?.closest('.openap-iptv-settings-header');
            header?.classList.add('is-ordering-closing');
            moviesList?.querySelectorAll('.openap-iptv-live-item.is-ordering').forEach(function(row){row.classList.add('is-ordering-closing');});
            window.setTimeout(function(){header?.classList.remove('is-ordering-closing');libraryOrderEditing=false;setLibraryOrderEditing(false);},280);
            return;
          }
          libraryOrderEditing=enabled;selectedLibraryOrderItems.clear();
          if(enabled)moviesList?.classList.remove('is-reordering');
          if(enabled){libraryOrderSnapshot=moviesItems.slice();if(moviesFilter)moviesFilter.value='';}
          if(libraryEditButton)libraryEditButton.hidden=enabled;
          if(libraryEditCancel)libraryEditCancel.hidden=!enabled;
          if(libraryEditSave)libraryEditSave.hidden=!enabled;
          if(libraryMoveTop)libraryMoveTop.hidden=!enabled;
          if(libraryMoveBottom)libraryMoveBottom.hidden=!enabled;
          libraryEditButton?.closest('.openap-iptv-settings-header')?.classList.toggle('is-ordering',enabled);
          moviesFilter?.toggleAttribute('disabled',enabled);renderMovieMenu();updateLibrarySelectionActions();
        };
        const moveSelectedLibraryItems=function(toTop){if(!moviesList||selectedLibraryOrderItems.size===0)return;const selected=Array.from(moviesList.querySelectorAll('[data-order-id]')).filter(function(node){return selectedLibraryOrderItems.has(node.dataset.orderId);});if(toTop){for(let index=selected.length-1;index>=0;index--)moviesList.prepend(selected[index]);}else selected.forEach(function(node){moviesList.append(node);});};
        libraryEditButton?.addEventListener('click',function(){setLibraryOrderEditing(true);});
        libraryEditCancel?.addEventListener('click',function(){moviesItems=libraryOrderSnapshot.slice();setLibraryOrderEditing(false);});
        libraryMoveTop?.addEventListener('click',function(){moveSelectedLibraryItems(true);});
        libraryMoveBottom?.addEventListener('click',function(){moveSelectedLibraryItems(false);});
        libraryEditSave?.addEventListener('click',async function(){if(!moviesList)return;const ids=Array.from(moviesList.querySelectorAll('[data-order-id]')).map(function(node){return node.dataset.orderId;});const scope=(libraryKind==='series'?'series_':'vod_')+(moviesLevel==='regions'?'region':'category'),parent=moviesLevel==='regions'?'global':moviesRegion,providerId=selectedProviderId();libraryEditSave.disabled=true;try{const csrf=document.querySelector('meta[name="csrf_token"]')?.content||'',params=new URLSearchParams({csrf_token:csrf,scope:scope,parent:parent,order:JSON.stringify(ids)});if(providerId)params.set('provider_id',providerId);const response=await fetch('/aptv/api/catalog/order',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params}),payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to save the catalog order.")); ?>);const byId=new Map(moviesItems.map(function(item){return [String(moviesLevel==='regions'?item.code:item.category_id),item];}));moviesItems=ids.map(function(id){return byId.get(id);}).filter(Boolean);libraryOrderSnapshot=moviesItems.slice();setLibraryOrderEditing(false);}catch(error){window.alert(error.message);}finally{libraryEditSave.disabled=false;}});
        const formatVodTime=function(value){const numeric=Number(value);if(!Number.isFinite(numeric))return '--:--';const seconds=Math.max(0,Math.round(numeric)),h=Math.floor(seconds/3600),m=Math.floor(seconds%3600/60),s=seconds%60;return(h?h+':':'')+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');};
        const renderAudioTracks=function(playback){if(!audioMenu)return;audioMenu.replaceChildren();const nativeTracks=playback.direct&&!playback.hybrid&&video&&video.audioTracks&&video.audioTracks.length?video.audioTracks:null;(playback.tracks||[]).forEach(function(track,position){const button=document.createElement('button');button.type='button';button.classList.toggle('is-active',position===playback.audio);const language=String(track.language||'und').toUpperCase();button.textContent=(track.title?track.title+' · ':'')+language+' · '+String(track.codec||'audio').toUpperCase();if(playback.hls&&aptvPlayer)button.addEventListener('click',function(){aptvPlayer.audioTrack=position;playback.audio=position;renderAudioTracks(playback);audioMenu.hidden=true;audioToggle?.setAttribute('aria-expanded','false');});else if(playback.direct&&nativeTracks&&position<nativeTracks.length)button.addEventListener('click',function(){for(let index=0;index<nativeTracks.length;index++)nativeTracks[index].enabled=index===position;playback.audio=position;renderAudioTracks(playback);audioMenu.hidden=true;audioToggle?.setAttribute('aria-expanded','false');});else if(playback.direct)button.addEventListener('click',function(){audioMenu.hidden=true;audioToggle?.setAttribute('aria-expanded','false');startHybridVodAudio(playback,position,video.currentTime||playback.position||0);});else button.addEventListener('click',function(){audioMenu.hidden=true;audioToggle?.setAttribute('aria-expanded','false');if(position!==playback.audio||playback.direct)startAudioRemuxVod(playback.id,playback.title,playback.position,playback.duration,playback.tracks,position);});audioMenu.append(button);});if(!(playback.tracks||[]).length){const empty=document.createElement('button');empty.type='button';empty.disabled=true;empty.textContent=<?php echo json_encode(_("Default audio track")); ?>;audioMenu.append(empty);}};
        // Diagnostica dei frammenti lato browser.  L'appender gia' scompone il
        // flusso in box: qui se ne approfitta per leggere il tfdt reale dentro
        // il moof e i timescale delle tracce nell'init, cosi' la posizione
        // interna di ogni frammento e' confrontabile con l'etichetta che il
        // relay ha servito.
        const mp4Boxes=function(bytes,start,end){const list=[];let offset=Math.max(0,start);const limit=Math.min(end,bytes.byteLength);while(offset+8<=limit){const view=new DataView(bytes.buffer,bytes.byteOffset+offset,limit-offset);let size=view.getUint32(0),header=8;const type=String.fromCharCode(bytes[offset+4],bytes[offset+5],bytes[offset+6],bytes[offset+7]);if(size===1){if(offset+16>limit)break;size=view.getUint32(8)*4294967296+view.getUint32(12);header=16;}else if(size===0){size=limit-offset;}if(size<header||offset+size>limit)break;list.push({type:type,offset:offset,size:size,header:header});offset+=size;}return list;};
        const mp4U32=function(bytes,offset){if(offset<0||offset+4>bytes.byteLength)return null;return new DataView(bytes.buffer,bytes.byteOffset).getUint32(offset);};
        const mp4U64=function(bytes,offset){if(offset<0||offset+8>bytes.byteLength)return null;const view=new DataView(bytes.buffer,bytes.byteOffset);return view.getUint32(offset)*4294967296+view.getUint32(offset+4);};
        const mp4Timescales=function(boxes){const out={};boxes.forEach(function(raw){const moov=mp4Boxes(raw,0,raw.byteLength).find(function(item){return item.type==='moov';});if(!moov)return;mp4Boxes(raw,moov.offset+moov.header,moov.offset+moov.size).forEach(function(trak){if(trak.type!=='trak')return;let trackId=null,timescale=null;mp4Boxes(raw,trak.offset+trak.header,trak.offset+trak.size).forEach(function(child){if(child.type==='tkhd'){const version=raw[child.offset+8];trackId=mp4U32(raw,child.offset+(version===1?28:20));}else if(child.type==='mdia'){mp4Boxes(raw,child.offset+child.header,child.offset+child.size).forEach(function(md){if(md.type==='mdhd'){const version=raw[md.offset+8];timescale=mp4U32(raw,md.offset+(version===1?28:20));}});}});if(trackId!==null&&timescale)out[trackId]=timescale;});});return out;};
        const mp4FragmentInfo=function(bytes,timescales){const moof=mp4Boxes(bytes,0,bytes.byteLength).find(function(box){return box.type==='moof';});if(!moof)return null;const end=moof.offset+moof.size,info={bytes:bytes.byteLength,tracks:[]};mp4Boxes(bytes,moof.offset+moof.header,end).forEach(function(child){if(child.type==='mfhd')info.sequence=mp4U32(bytes,child.offset+12);});mp4Boxes(bytes,moof.offset+moof.header,end).forEach(function(traf){if(traf.type!=='traf')return;let trackId=null,tfdt=null,samples=0;mp4Boxes(bytes,traf.offset+traf.header,traf.offset+traf.size).forEach(function(child){if(child.type==='tfhd')trackId=mp4U32(bytes,child.offset+12);else if(child.type==='tfdt'){const version=bytes[child.offset+8];tfdt=version===1?mp4U64(bytes,child.offset+12):mp4U32(bytes,child.offset+12);}else if(child.type==='trun')samples=mp4U32(bytes,child.offset+12);});const timescale=timescales&&trackId!==null&&timescales[trackId]?timescales[trackId]:0;info.tracks.push({track_id:trackId,tfdt:tfdt,timescale:timescale||null,seconds:timescale&&tfdt!==null?tfdt/timescale:null,samples:samples});});return info;};
        // Etichetta del frammento che il relay ha servito a un dato offset
        // cumulativo di byte: collega i byte davvero accodati in MSE al
        // frammento della cache da cui provengono.
        const mp4RelayLabel=function(offset){const served=playerRelayState&&Array.isArray(playerRelayState.served)?playerRelayState.served:[];let label=null;served.forEach(function(entry){const start=Number(entry.offset)||0,size=Number(entry.bytes)||0;if(offset>=start&&offset<start+size)label=Number(entry.start);});return label;};
        const mp4FormatFragment=function(info,label){if(!info)return '--';const track=info.tracks.filter(function(item){return item.seconds!==null;})[0]||info.tracks[0]||null,parts=[];if(info.sequence!==undefined)parts.push('seq '+String(info.sequence));parts.push(Math.round(info.bytes/1024)+' KiB');if(track&&track.seconds!==null){parts.push('tfdt '+track.seconds.toFixed(3)+' s @'+String(track.timescale||'?'));if(label!==null&&label!==undefined){const delta=Number(label)-track.seconds;parts.push('label '+Number(label).toFixed(3)+' s');parts.push('delta '+(delta>=0?'+':'')+delta.toFixed(3)+' s');}}return parts.join(' · ');};
        const mp4Buffered=function(buffer){if(!buffer)return '--';const parts=[];try{for(let index=0;index<buffer.buffered.length;index++)parts.push(buffer.buffered.start(index).toFixed(1)+'–'+buffer.buffered.end(index).toFixed(1));}catch(error){return '--';}return parts.length?parts.join(' · '):'empty';};
        const mp4RelaySummary=function(relay){if(!relay)return '--';const served=Array.isArray(relay.served)&&relay.served.length?relay.served[relay.served.length-1]:null,parts=[];parts.push(served?Number(served.start).toFixed(3)+' s ('+String(served.file||'').replace(/\.m4s$/,'')+')':'--');parts.push(Math.round((Number(relay.served_bytes)||0)/1048576)+' MiB');parts.push('window '+Number(relay.window_ahead_seconds||0).toFixed(0)+' s');if(relay.window_waiting)parts.push('waiting');parts.push('next '+Number(relay.next_start||0).toFixed(3)+' s');return parts.join(' · ');};
        const createMp4Appender=function(sourceBuffer,errorMessage,onFragment,onBeforeFragment){
          playerMseSourceBuffer=sourceBuffer;playerMseTimescales=null;playerMseAppendedBytes=0;
          playerMseFragments=[];playerMseLastAppended=null;playerMseLastRejected=null;playerMseError='';
          let pending=new Uint8Array(0),initialization=[],fragment=[];
          const join=function(parts){const size=parts.reduce(function(total,part){return total+part.byteLength;},0),joined=new Uint8Array(size);let offset=0;parts.forEach(function(part){joined.set(part,offset);offset+=part.byteLength;});return joined;};
          // La causa reale dell'errore MSE non va sovrascritta dal messaggio
          // generico: viene letta da sourceBuffer.error o dall'eccezione e
          // accodata al messaggio utente, che resta il testo principale.
          const appendFailure=function(cause){const media=sourceBuffer.error,detail=[cause,media&&media.name?String(media.name):'',media&&media.message?String(media.message):''].filter(Boolean).join(' · ')||'unknown buffer error';playerMseError=detail;return errorMessage+' · '+detail;};
          const append=function(bytes){return new Promise(function(resolve,reject){const complete=function(){sourceBuffer.removeEventListener('updateend',complete);sourceBuffer.removeEventListener('error',failed);playerProgressiveMseAppend='updateend';resolve();},failed=function(cause){sourceBuffer.removeEventListener('updateend',complete);sourceBuffer.removeEventListener('error',failed);const message=appendFailure(cause);playerProgressiveMseAppend='error: '+message;reject(new Error(message));};sourceBuffer.addEventListener('updateend',complete,{once:true});sourceBuffer.addEventListener('error',failed,{once:true});playerProgressiveMseAppend='appending '+bytes.byteLength+' B';try{sourceBuffer.appendBuffer(bytes);}catch(error){failed('appendBuffer threw '+(error?.name?String(error.name):'')+(error?.message?' · '+String(error.message):''));}});};
          const emitFragment=async function(bytes){const info=mp4FragmentInfo(bytes,playerMseTimescales);if(onBeforeFragment)await onBeforeFragment(bytes);try{await append(bytes);}catch(error){if(info){info.error=playerMseError||String(error?.message||error);playerMseLastRejected=info;}throw error;}if(info){playerMseLastAppended=info;playerMseAppendedBytes+=bytes.byteLength;playerMseFragments.push(info);if(playerMseFragments.length>12)playerMseFragments.shift();}if(onFragment)await onFragment(bytes.byteLength);};
          return async function(chunk){
            const merged=new Uint8Array(pending.byteLength+chunk.byteLength);merged.set(pending);merged.set(chunk,pending.byteLength);pending=merged;
            while(pending.byteLength>=8){
              const view=new DataView(pending.buffer,pending.byteOffset,pending.byteLength);let size=view.getUint32(0),header=8,type=String.fromCharCode(pending[4],pending[5],pending[6],pending[7]);
              if(size===1){if(pending.byteLength<16)return;size=view.getUint32(8)*4294967296+view.getUint32(12);header=16;}
              if(size<header||pending.byteLength<size)return;
              const box=pending.slice(0,size);pending=pending.slice(size);
              if(type==='moof'){
                if(initialization.length){playerMseTimescales=mp4Timescales(initialization);await append(join(initialization));initialization=[];}
                fragment=[box];
              }else if(fragment.length){
                fragment.push(box);
                if(type==='mdat'){const complete=join(fragment);fragment=[];await emitFragment(complete);}
              }else initialization.push(box);
            }
          };
        };
        const startDirectFmp4=function(payload,attemptId,onStarted,onError){if(!window.MediaSource||typeof MediaSource.isTypeSupported!=='function')return false;const sourceVideoCodec=String(vodTechnical?.video?.codec||vodTechnical?.videoCodec||'').toLowerCase(),outputAudioCodec=String(payload?.progressive_audio_codec||'aac').toLowerCase(),audioMime=outputAudioCodec==='mp3'?'mp4a.40.34':'mp4a.40.2',cacheSeekPlayback=Boolean(payload?.cache_seek),ephemeralPlayback=Boolean(payload?.ephemeral),progressiveStartupTarget=cacheSeekPlayback?0:(ephemeralPlayback?Math.max(.25,Number(payload?.progressive_start_target)||.25):Math.max(1,Number(payload?.progressive_start_target)||8)),experimentalFragmentProfile=Boolean(vodPlayback?.fmp4),progressiveBufferLimit=20,mimeCandidates=/(hevc|h265)/.test(sourceVideoCodec)?['video/mp4; codecs="hvc1.2.4.L120.B0,'+audioMime+'"','video/mp4; codecs="hvc1.1.6.L120.B0,'+audioMime+'"','video/mp4; codecs="hvc1,'+audioMime+'"','video/mp4']:['video/mp4; codecs="avc1.640028,'+audioMime+'"','video/mp4; codecs="avc1.4d401f,'+audioMime+'"','video/mp4; codecs="avc1,'+audioMime+'"','video/mp4'],mime=mimeCandidates.find(function(candidate){return MediaSource.isTypeSupported(candidate);});if(!mime)return false;const ottRestartResume=experimentalFragmentProfile&&playerOttPauseState==='relay restart requested';if(experimentalFragmentProfile&&!playerOttTestActive)playerOttRelayRestartCount=0;playerOttTestActive=experimentalFragmentProfile;playerOttFragmentCount=0;playerOttFragmentBytes=0;playerOttBufferCap=progressiveBufferLimit;if(ottRestartResume){playerOttRelayRestartCount++;playerOttPauseState='relay restarted · '+String(playerOttRelayRestartCount);recordPlayerEvent('OTT test: relay restarted after long pause');}else playerOttPauseState=experimentalFragmentProfile?'not paused':'--';playerProgressiveMseAppend='opening';const mediaSource=new MediaSource();vodRemuxAbort=new AbortController();vodRemuxObjectUrl=URL.createObjectURL(mediaSource);video.src=vodRemuxObjectUrl;video.hidden=false;video.load();mediaSource.addEventListener('sourceopen',async function(){let started=false;const begin=function(){if(started||attemptId!==playbackAttemptId)return;started=true;if(cacheSeekPlayback)recordPlayerEvent('Cache seek playback started after target fragment');onStarted();video.play().catch(function(error){if(error?.name==='NotAllowedError'&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Press play to start the episode.")); ?>;}});};const cacheSeekReady=function(){return cacheSeekPlayback&&progressiveBufferAhead()>=.25;};const waitForProgressiveRoom=async function(){while(attemptId===playbackAttemptId&&vodPlayback?.direct&&progressiveBufferAhead()>progressiveBufferLimit)await new Promise(function(resolve){window.setTimeout(resolve,250);});};try{const cacheSeekOrigin=payload.cache_seek?Math.max(0,Number(payload.cache_seek_origin)||0):Math.max(0,Number(payload.timeline_origin)||0);
if((payload.cache||payload.ephemeral)&&vodPlayback)vodPlayback.offset=0;
const startOffset=Math.max(0,Number(payload.start)||0);
if(payload.cache_seek)recordPlayerEvent('Cache fragment '+String(payload.cache_fragment_file||'--')+' · origin '+formatVodTime(cacheSeekOrigin)+' · MSE timestampOffset 0 s · target '+startOffset+' s');
const sourceBuffer=mediaSource.addSourceBuffer(mime),appendMp4=createMp4Appender(sourceBuffer,<?php echo json_encode(_("Unable to buffer the selected episode.")); ?>,async function(fragmentBytes){if(experimentalFragmentProfile){playerOttFragmentCount++;playerOttFragmentBytes+=Number(fragmentBytes)||0;renderProgressiveDiagnostics();}},async function(){
if(started)await waitForProgressiveRoom();
if(payload.cache||payload.ephemeral)sourceBuffer.timestampOffset=payload.ephemeral&&!payload.tfdt_absolute?Math.max(0,Number(payload.timeline_origin)||0):0;
});playerProgressiveMseAppend='fetching';const response=await fetch(payload.url,{credentials:'same-origin',cache:'no-store',signal:vodRemuxAbort.signal});if(!response.ok||!response.body)throw new Error('HTTP '+String(response.status||0));let positioned=false;const reader=response.body.getReader();while(true){const result=await reader.read();if(result.done)break;if(!result.value||!result.value.byteLength)continue;await appendMp4(result.value);if(!positioned&&sourceBuffer.buffered.length){const bufferedStart=sourceBuffer.buffered.start(0);suppressNextVodSeeking=true;video.currentTime=startOffset<bufferedStart?bufferedStart:startOffset;positioned=true;}if(!started&&((cacheSeekPlayback&&cacheSeekReady())||(!cacheSeekPlayback&&progressiveBufferAhead()>=progressiveStartupTarget)))begin();}if(!started&&(cacheSeekPlayback?cacheSeekReady():video.buffered.length))begin();playerProgressiveMseAppend='stream ended';if(mediaSource.readyState==='open'&&!sourceBuffer.updating)mediaSource.endOfStream();}catch(error){if(error?.name!=='AbortError'&&attemptId===playbackAttemptId)onError(error?.message||'fMP4 MSE error');}},{once:true});return true;};
        const startAudioRemuxVod=function(id,title,offset,duration,tracks,audio){
          stopChannel();setMoviesOpen(false);showPlayerSurface();playerMode='vod';duration=Number(duration)||0;offset=Number(offset)||0;audio=Number(audio)||0;
          applyVodTechnical(vodTechnical,audio);
          if(playerName)playerName.textContent=title;if(playerLogo){playerLogo.src='/aptv/api/vod/poster?id='+encodeURIComponent(id);playerLogo.hidden=false;}if(vodControls){vodControls.hidden=false;vodControls.classList.add('is-vod');}if(vodSeek){vodSeek.max=String(Math.max(1,duration));vodSeek.value=String(offset);}if(vodCurrent)vodCurrent.textContent=formatVodTime(offset);if(vodDuration)vodDuration.textContent=duration?formatVodTime(duration):'--:--';if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Switching audio track…")); ?>;}
          vodPlayback={id:id,title:title,offset:offset,position:offset,duration:duration,tracks:tracks,audio:audio,paused:false,remux:true};renderAudioTracks(vodPlayback);
          vodRemuxAbort=new AbortController();fetch('/aptv/api/vod/audio-start?'+new URLSearchParams({id:id,start:String(Math.floor(offset)),audio:String(audio)}),{credentials:'same-origin',cache:'no-store',signal:vodRemuxAbort.signal}).then(function(response){return response.json().then(function(payload){if(!response.ok||!payload.ok||!payload.url)throw new Error(<?php echo json_encode(_("Unable to authorize the selected audio stream.")); ?>);return payload;});}).then(function(payload){video.src=payload.url;video.hidden=false;video.load();return video.play();}).catch(function(error){if(error.name!=='AbortError'&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}});return;
          const mediaSource=new MediaSource(),mimeCandidates=['video/mp4; codecs="hvc1.2.4.L120.B0,mp4a.40.2"','video/mp4; codecs="hvc1.1.6.L120.B0,mp4a.40.2"','video/mp4; codecs="hvc1,mp4a.40.2"'],mime=mimeCandidates.find(function(candidate){return MediaSource.isTypeSupported(candidate);});if(!mime){if(playerState)playerState.querySelector('span').textContent=<?php echo json_encode(_("This browser cannot buffer the selected HEVC audio stream.")); ?>;return;}vodRemuxAbort=new AbortController();vodRemuxObjectUrl=URL.createObjectURL(mediaSource);video.src=vodRemuxObjectUrl;video.hidden=false;video.load();mediaSource.addEventListener('sourceopen',async function(){try{if(playerState)playerState.querySelector('span').textContent=<?php echo json_encode(_("Opening selected audio stream…")); ?>;
const sourceBuffer=mediaSource.addSourceBuffer(mime),appendMp4=createMp4Appender(sourceBuffer),ticketResponse=await fetch('/aptv/api/vod/audio-start?'+new URLSearchParams({id:id,start:String(Math.floor(offset)),audio:String(audio)}),{credentials:'same-origin',cache:'no-store',signal:vodRemuxAbort.signal}),ticket=await ticketResponse.json();if(!ticketResponse.ok||!ticket.ok||!ticket.url)throw new Error(<?php echo json_encode(_("Unable to authorize the selected audio stream.")); ?>);const response=await fetch(ticket.url,{credentials:'same-origin',cache:'no-store',signal:vodRemuxAbort.signal});if(!response.ok||!response.body)throw new Error(<?php echo json_encode(_("Unable to open the selected audio stream.")); ?>);if(playerState)playerState.querySelector('span').textContent=<?php echo json_encode(_("Receiving selected audio stream…")); ?>;const reader=response.body.getReader();let started=false;while(true){const result=await reader.read();if(result.done)break;if(!result.value||!result.value.byteLength)continue;await appendMp4(result.value);if(!started&&!sourceBuffer.buffered.length&&playerState)playerState.querySelector('span').textContent=<?php echo json_encode(_("Parsing selected audio stream…")); ?>;if(!started&&sourceBuffer.buffered.length){started=true;video.play().catch(function(error){if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.name==='NotAllowedError'?<?php echo json_encode(_("Press play to resume with the selected audio.")); ?>:error.message;}});}}if(mediaSource.readyState==='open'&&!sourceBuffer.updating)mediaSource.endOfStream();}catch(error){if(error.name!=='AbortError'&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}}},{once:true});
        };
        const startDirectVod=function(id,title,duration,tracks,start,cacheSession,disableCache,replaceCache,forceClassic){const pendingFeedback=playerFeedbackPending;playerFeedbackPending='';const progressiveFmp4Candidate=String(vodTechnical?.container||'').toLowerCase()==='mkv'&&(String(vodTechnical?.source_kind||'')==='series'||(String(vodTechnical?.source_kind||'')==='movie'&&['h264','hevc','h265'].includes(String(vodTechnical?.video?.codec||vodTechnical?.videoCodec||'').toLowerCase()))),strictCandidate=!forceClassic&&progressiveFmp4Candidate,cuesStrictPilot=strictCandidate||(!forceClassic&&String(id)==='2028954'),ephemeralPilot=forceClassic?false:(progressiveFmp4Candidate||String(id)==='2140638'||String(id)==='1556812'||cuesStrictPilot),daemonPilot=ephemeralPilot&&!cuesStrictPilot&&!progressiveFmp4Candidate,cacheSeekRequest=false,openingPlayback=!vodPlayback||String(vodPlayback.id)!==String(id);start=Math.max(0,Math.floor(Number(start)||0));
          if(daemonPilot&&(!window.__openapVodDaemonSid||window.__openapVodDaemonTitle!==String(id))){window.__openapVodDaemonSid=(window.crypto&&typeof window.crypto.randomUUID==='function'?window.crypto.randomUUID().replace(/-/g,''):Array.from(crypto.getRandomValues(new Uint8Array(16))).map(function(value){return value.toString(16).padStart(2,'0');}).join(''));window.__openapVodDaemonTitle=String(id);window.__openapVodDaemonEpoch=0;}const daemonSid=daemonPilot?String(window.__openapVodDaemonSid||''):'';const daemonEpoch=daemonPilot?Number(window.__openapVodDaemonEpoch||0)+1:0;if(daemonPilot)window.__openapVodDaemonEpoch=daemonEpoch;
          if(openingPlayback){playerFeedbackSeeking=false;setPlayerFeedback('');}stopChannel();setMoviesOpen(false);showPlayerSurface();if(pendingFeedback)setPlayerFeedback(pendingFeedback);playerMode='vod';if(playerName)playerName.textContent=title;if(playerLogo){const poster=playerLibraryKind==='series'?String(selectedSeries?.detail?.poster_url||selectedSeries?.item?.poster_url||''):'/aptv/api/vod/poster?id='+encodeURIComponent(id);playerLogo.hidden=!poster;if(poster)playerLogo.src=poster;}if(vodControls){vodControls.hidden=false;vodControls.classList.add('is-vod');}if(vodSeek){vodSeek.max=String(Math.max(1,duration));vodSeek.value=String(start);}if(vodCurrent)vodCurrent.textContent=formatVodTime(start);if(vodDuration)vodDuration.textContent=duration?formatVodTime(duration):'--:--';if(playerState){playerState.hidden=cacheSeekRequest||!openingPlayback;playerState.querySelector('span').textContent=playerLibraryKind==='series'?<?php echo json_encode(_("Opening episode…")); ?>:<?php echo json_encode(_("Opening movie…")); ?>;}
          applyVodTechnical(vodTechnical,0);const vodSourceBitrate=Number(vodTechnical?.source_bitrate)||0;if(vodSourceBitrate>0)recordPlayerEvent('VOD source bitrate · '+formatNetworkRate(vodSourceBitrate));
          const attemptId=playbackAttemptId;playerT0Pending={target:start,issuedAt:performance.now(),attemptId:attemptId};const cacheOnlyPilot=String(id)==='1556812'&&!disableCache&&!ephemeralPilot;const noFallbackPilot=ephemeralPilot;let directStarted=false,fallbackStarted=false,startupTimer=0;if(openingPlayback){progressiveRelayRestartCount=0;progressiveRelayRestartActive=false;}
          const clearStartupTimer=function(){if(startupTimer){clearTimeout(startupTimer);startupTimer=0;}};
          const fallbackToHls=function(reason){if(fallbackStarted||directStarted||attemptId!==playbackAttemptId)return;fallbackStarted=true;clearStartupTimer();const detail=String(reason||'unknown');if(progressiveRelayRestartCount>0&&vodPlayback?.fmp4){playerProviderError='PROGRESSIVE_FMP4_RELAY_FAILED · '+detail;playerPlaybackState='Provider error';recordPlayerEvent('Progressive fMP4 restart failed · HLS fallback disabled');renderPlayerDiagnostics();if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Progressive fMP4 playback failed.")); ?>;}return;}if(directStarted&&vodPlayback?.fmp4&&restartProgressiveRelay(detail))return;if(noFallbackPilot&&!forceClassic){recordPlayerEvent('Cues strict failed · classic progressive-fMP4 fallback · '+detail);startDirectVod(id,title,duration,tracks,start,null,disableCache,replaceCache,true);return;}if(cacheOnlyPilot||noFallbackPilot){recordPlayerEvent('VPS cache-only playback failed · '+detail);playerProviderError='VPS_CACHE_ONLY · '+detail;renderPlayerDiagnostics();if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=noFallbackPilot?'Progressive fMP4 playback failed':'VPS cache-only playback failed';}return;}const message='Direct playback failed → HLS fallback · '+detail;startVod(id,title,start,duration,tracks,0);window.setTimeout(function(){recordPlayerEvent(message);playerProviderError='directPlaybackFallback: '+detail;renderPlayerDiagnostics();},0);};
          const providerId=selectedProviderId(),directQuery=new URLSearchParams({id:String(id),start:String(start)});if(providerId)directQuery.set("provider_id",providerId);if(disableCache)directQuery.set('disable_cache','1');if(ephemeralPilot)directQuery.set('ephemeral','1');if(cuesStrictPilot)directQuery.set('cues_strict','1');if(daemonPilot){directQuery.set('daemon','1');directQuery.set('sid',daemonSid);directQuery.set('epoch',String(daemonEpoch));}if(replaceCache)directQuery.set('replace_cache','1');if(!ephemeralPilot&&String(id)==='1556812'&&/^[a-f0-9]{32}$/.test(String(cacheSession||'')))directQuery.set('cache_session',String(cacheSession));fetch('/aptv/api/vod/direct?'+directQuery.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.text().then(function(text){let payload;try{payload=JSON.parse(text);}catch(error){throw new Error('SERVER_NON_JSON_RESPONSE · HTTP '+String(response.status||0)+' · '+String(response.headers.get('content-type')||'unknown'));}if(!response.ok||!payload.ok)throw new Error((payload.code?String(payload.code)+' · ':'')+(payload.message||<?php echo json_encode(_("Direct playback unavailable.")); ?>));return payload;});}).then(function(payload){if(attemptId!==playbackAttemptId)return;if(Number(payload.source_bitrate)>0)vodTechnical.source_bitrate=Number(payload.source_bitrate);if(payload.hybrid){vodTechnical.playback_class='hls_audio_transcode';applyVodTechnical(vodTechnical,0);}const progressiveSession=String(payload.session||'');if(payload.cues_strict)recordPlayerEvent('Cues strict · origin '+formatVodTime(Number(payload.cues_origin)||0)+' · probe '+Number(payload.cues_probe_ms||0)+' ms · cues '+Number(payload.cues_count||0)+' · range '+Number(payload.cues_range_bytes||0)+' B · '+(payload.cues_cache_hit?'cache hit':'cache '+String(payload.cues_cache_state||'miss')));if(cacheSeekRequest)recordPlayerEvent('Cache API target '+formatVodTime(start)+' · fragment '+String(payload.cache_fragment_file||'--')+' · origin '+formatVodTime(Number(payload.cache_seek_origin)||0)+' · keyframe '+(payload.cache_fragment_keyframe===false?'no':payload.cache_fragment_keyframe===true?'yes':'unknown'));
          if(!payload.ephemeral&&playerState)playerState.hidden=false;vodPlayback={id:id,title:title,offset:payload.fmp4?((payload.cache||payload.ephemeral)?0:Math.max(0,Number(payload.cache_seek?payload.cache_seek_origin:payload.timeline_origin)||0)):start,
cacheStart:payload.fmp4?Math.max(0,Number(payload.cache_start)||0):0,position:start,duration:Number(payload.duration)||duration,tracks:tracks,audio:0,providerId:providerId,paused:false,direct:true,fmp4:Boolean(payload.fmp4),cache:Boolean(payload.cache),cacheOnly:Boolean(payload.cache_only),cacheSeek:Boolean(payload.cache_seek),cacheLimitFallback:Boolean(disableCache),hybrid:Boolean(payload.hybrid),audioTranscoded:Boolean(payload.progressive_audio_transcoded),progressiveSession:progressiveSession,daemon:Boolean(payload.daemon),daemonSid:daemonSid,daemonEpoch:Number(payload.epoch||daemonEpoch)};if(playerPauseResumePending){const pending=playerPauseResumePending;playerPauseResumePending=null;recordPlayerEvent("Pause resume persisted · branch "+pending.reason+" · saved "+Number(pending.snapshot?.savedPosition||0).toFixed(3)+" s · target "+Number(pending.target||0).toFixed(3)+" s");}startProgressiveStats(progressiveSession,attemptId);renderVodCacheBar();renderProgressiveDiagnostics();video.muted=Boolean(payload.hybrid);video.preload='auto';renderAudioTracks(vodPlayback);video.addEventListener('loadedmetadata',function(){if(attemptId===playbackAttemptId&&vodPlayback&&vodPlayback.direct)renderAudioTracks(vodPlayback);},{once:true});video.addEventListener('playing',function(){if(attemptId!==playbackAttemptId)return;directStarted=true;clearStartupTimer();if(vodPlayback?.hybrid)startHybridVodAudio(vodPlayback,0,video.currentTime||0);if(playerState)playerState.hidden=true;},{once:true});video.addEventListener('error',function(){fallbackToHls(video.error?('media error '+String(video.error.code||'')):'media error');},{once:true});const fmp4Started=Boolean(payload.fmp4)&&startDirectFmp4(payload,attemptId,function(){directStarted=true;clearStartupTimer();if(playerState)playerState.hidden=true;},function(message){fallbackToHls('fMP4 MSE: '+String(message||'error'));});if(fmp4Started){startupTimer=window.setTimeout(function(){fallbackToHls('startup timeout');},30000);return;}video.src=payload.url;video.hidden=false;startupTimer=window.setTimeout(function(){fallbackToHls('startup timeout');},30000);video.load();return video.play().catch(function(error){if(error.name==='NotAllowedError'){clearStartupTimer();if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Press play to start the movie.")); ?>;}return;}fallbackToHls('play error '+String(error.name||'unknown'));});}).catch(function(error){fallbackToHls('request error '+String(error.message||error));});
        };
        const startHybridVodAudio=function(playback,audio,start){
          if(!playback||!playback.direct||!hybridAudio)return;const attemptId=playbackAttemptId;stopHybridAudio(true);start=Math.max(0,Number(start)||0);audio=Number(audio)||0;playback.hybrid=true;playback.audio=audio;playback.hybridOffset=start;hybridAudio.volume=Number(playerVolume?.value||1);hybridAudio.muted=true;renderAudioTracks(playback);
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Preparing selected audio…")); ?>;}
          const audioProviderId=String(playback.providerId||selectedProviderId()||""),audioQuery=new URLSearchParams({id:playback.id,start:String(Math.floor(start)),audio:String(audio)});if(/^[a-f0-9]{32}$/.test(audioProviderId))audioQuery.set("provider_id",audioProviderId);fetch('/aptv/api/vod/audio-hls/start?'+audioQuery,{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json().then(function(payload){if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to prepare the selected audio.")); ?>);return payload;});}).then(function(payload){if(attemptId!==playbackAttemptId||vodPlayback!==playback){fetch('/aptv/api/vod/hls/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:payload.token,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});return;}vodHlsToken=payload.token;const begin=function(){const playAudio=function(){if(video.paused)return;hybridAudio.play().then(function(){if(attemptId===playbackAttemptId&&vodPlayback===playback)hybridAudio.muted=false;}).catch(function(error){if(attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.name==='NotAllowedError'?'Press play to enable audio.':error.message;}});};hybridAudio.addEventListener('loadedmetadata',playAudio,{once:true});playAudio();hybridSyncClock=setInterval(function(){if(!vodPlayback||vodPlayback!==playback||!playback.hybrid)return;const expected=Math.max(0,(video.currentTime||0)-playback.hybridOffset),drift=expected-(hybridAudio.currentTime||0);if(!video.paused&&hybridAudio.paused)hybridAudio.play().catch(function(){});if(Math.abs(drift)>.35&&hybridAudio.readyState>0)hybridAudio.currentTime=Math.max(0,expected);},250);};if(window.Hls&&window.Hls.isSupported()){vodAudioPlayer=new window.Hls({enableWorker:true,lowLatencyMode:false,backBufferLength:20});vodAudioPlayer.loadSource(payload.playlist);vodAudioPlayer.attachMedia(hybridAudio);vodAudioPlayer.on(window.Hls.Events.MANIFEST_PARSED,begin);vodAudioPlayer.on(window.Hls.Events.ERROR,function(event,data){if(data&&data.fatal&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Unable to play the selected audio")); ?>+' ('+String(data.details||data.type||'HLS error')+')';}});}else if(hybridAudio.canPlayType('application/vnd.apple.mpegurl')){hybridAudio.src=payload.playlist;hybridAudio.load();begin();}else throw new Error(<?php echo json_encode(_("This browser cannot play the selected audio stream.")); ?>);hybridAudio.addEventListener('playing',function(){video.muted=true;hybridAudio.muted=false;if(playerState)playerState.hidden=true;},{once:true});}).catch(function(error){playback.hybrid=false;renderAudioTracks(playback);if(attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}});
        };
        const startVodWebrtc=function(id,title,offset,duration,tracks,audio){
          stopChannel();setMoviesOpen(false);showPlayerSurface();if(playerName)playerName.textContent=title;
          if(playerLogo){playerLogo.src='/aptv/api/vod/poster?id='+encodeURIComponent(id);playerLogo.hidden=false;}
          playerMode='vod';duration=Number(duration)||0;tracks=Array.isArray(tracks)?tracks:[];audio=Number(audio)||0;if(vodControls){vodControls.hidden=false;vodControls.classList.add('is-vod');}if(vodSeek){vodSeek.max=String(Math.max(1,duration));vodSeek.value=String(offset);}if(vodCurrent)vodCurrent.textContent=formatVodTime(offset);if(vodDuration)vodDuration.textContent=duration?formatVodTime(duration):'--:--';
          applyVodTechnical(vodTechnical,audio);
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=offset>0?<?php echo json_encode(_("Seeking in movie…")); ?>:<?php echo json_encode(_("Connecting to movie…")); ?>;}
          const attemptId=playbackAttemptId;
          fetch('/aptv/api/vod/play?'+new URLSearchParams({id:id,start:String(Math.floor(offset)),audio:String(audio)}),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json().then(function(payload){if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to start the movie.")); ?>);return payload;});}).then(function(payload){
            if(attemptId!==playbackAttemptId){fetch('/aptv/api/webrtc/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:payload.token,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});return;}
            webrtcToken=payload.token;vodPlayback={id:id,title:title,offset:offset,position:offset,duration:duration,tracks:tracks,audio:audio,started:Date.now(),paused:false};renderAudioTracks(vodPlayback);connectWhep(payload.path,attemptId);
            vodClock=setInterval(function(){if(!vodPlayback||vodPlayback.paused)return;vodPlayback.position=Math.min(vodPlayback.duration||Infinity,vodPlayback.offset+(Date.now()-vodPlayback.started)/1000);if(vodSeek)vodSeek.value=String(Math.round(vodPlayback.position));if(vodCurrent)vodCurrent.textContent=formatVodTime(vodPlayback.position);},1000);
            webrtcHeartbeat=window.setInterval(function(){fetch('/aptv/api/webrtc/heartbeat',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:webrtcToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});},5000);
          }).catch(function(error){if(attemptId===playbackAttemptId&&playerState)playerState.querySelector('span').textContent=error.message;});
        };
        const startVod=function(id,title,offset,duration,tracks,audio){
          stopChannel();setMoviesOpen(false);showPlayerSurface();playerMode='vod';duration=Number(duration)||0;tracks=Array.isArray(tracks)?tracks:[];audio=Number(audio)||0;
          // A failed direct HEVC/E-AC3 attempt mutes the video to satisfy
          // autoplay. The muxed HLS fallback already carries AAC audio and
          // must not inherit that stale muted state.
          video.muted=false;
          applyVodTechnical(vodTechnical,audio);
          if(playerName)playerName.textContent=title;if(playerLogo){playerLogo.src='/aptv/api/vod/poster?id='+encodeURIComponent(id);playerLogo.hidden=false;}if(vodControls){vodControls.hidden=false;vodControls.classList.add('is-vod');}if(vodSeek){vodSeek.max=String(Math.max(1,duration));vodSeek.value=String(offset);}if(vodCurrent)vodCurrent.textContent=formatVodTime(offset);if(vodDuration)vodDuration.textContent=duration?formatVodTime(duration):'--:--';if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=offset>0?<?php echo json_encode(_("Seeking in movie…")); ?>:<?php echo json_encode(_("Preparing movie stream…")); ?>;}
          const attemptId=playbackAttemptId,providerId=selectedProviderId(),hlsQuery=new URLSearchParams({id:id,start:String(Math.floor(offset)),audio:String(audio)});if(providerId)hlsQuery.set("provider_id",providerId);fetch('/aptv/api/vod/hls/start?'+hlsQuery,{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.text().then(function(text){let payload;try{payload=JSON.parse(text);}catch(error){throw new Error('SERVER_NON_JSON_RESPONSE · HTTP '+String(response.status||0)+' · '+String(response.headers.get('content-type')||'unknown'));}if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to prepare this movie.")); ?>);return payload;});}).then(function(payload){if(attemptId!==playbackAttemptId){fetch('/aptv/api/vod/hls/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:payload.token,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});return;}vodHlsToken=payload.token;vodPlayback={id:id,title:title,offset:offset,position:offset,duration:duration,tracks:tracks,audio:audio,started:Date.now(),paused:false,hls:true};if(window.Hls&&window.Hls.isSupported()){aptvPlayer=new window.Hls({enableWorker:true,lowLatencyMode:false,backBufferLength:30,startPosition:0});attachVodHlsDiagnostics(aptvPlayer);aptvPlayer.loadSource(payload.playlist);aptvPlayer.attachMedia(video);aptvPlayer.on(window.Hls.Events.AUDIO_TRACKS_UPDATED,function(){if(aptvPlayer.audioTracks.length>audio)aptvPlayer.audioTrack=audio;renderAudioTracks(vodPlayback);});aptvPlayer.on(window.Hls.Events.AUDIO_TRACK_SWITCHED,function(event,data){if(vodPlayback&&vodPlayback.hls){vodPlayback.audio=Number(data.id)||0;renderAudioTracks(vodPlayback);}});aptvPlayer.on(window.Hls.Events.MANIFEST_PARSED,function(){video.play().catch(function(){});});aptvPlayer.on(window.Hls.Events.ERROR,function(event,data){if(data&&data.fatal&&attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Unable to play the movie stream")); ?>+' ('+String(data.details||data.type||'HLS error')+')';}});}else if(video.canPlayType('application/vnd.apple.mpegurl')){video.src=payload.playlist;video.play().catch(function(){});}else throw new Error(<?php echo json_encode(_("This browser cannot play HLS video.")); ?>);renderAudioTracks(vodPlayback);vodClock=setInterval(function(){if(!vodPlayback||vodPlayback.paused)return;vodPlayback.position=Math.min(vodPlayback.duration||Infinity,vodPlayback.offset+(video.currentTime||0));if(vodSeek)vodSeek.value=String(Math.round(vodPlayback.position));if(vodCurrent)vodCurrent.textContent=formatVodTime(vodPlayback.position);},500);}).catch(function(error){if(attemptId===playbackAttemptId&&playerState){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}});
        };
        const startRecordingPlayback=function(file,startOffset=0,autoPlay=true){
          if(!file?.path)return;
          const start=Math.max(0,Number(startOffset)||0),extension=String(file.name||file.path||'').split('.').pop().toLowerCase();
          stopChannel();setMoviesOpen(false,true);showPlayerSurface();playerMode='vod';playerLibraryKind='recording';
          if(playerName)playerName.textContent=file.name||<?php echo json_encode(_("Recording")); ?>;
          if(playerLogo){playerLogo.hidden=true;playerLogo.removeAttribute('src');}
          if(vodControls){vodControls.hidden=false;vodControls.classList.add('is-vod');}
          if(vodSeek){vodSeek.value=String(Math.floor(start));vodSeek.max='100';}
          if(vodCurrent)vodCurrent.textContent=formatVodTime(start);if(vodDuration)vodDuration.textContent='--:--';
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=start>0?<?php echo json_encode(_("Seeking in recording…")); ?>:<?php echo json_encode(_("Opening recording…")); ?>;}
          vodPlayback={id:file.path,title:file.name||<?php echo json_encode(_("Recording")); ?>,offset:start,position:start,duration:Number(file.duration)||0,tracks:[],audio:0,paused:false,direct:true,recording:true,recordingExtension:extension};
          if(vodPlayback.duration){if(vodSeek)vodSeek.max=String(Math.max(1,Math.ceil(vodPlayback.duration)));if(vodDuration)vodDuration.textContent=formatVodTime(vodPlayback.duration);}
          renderVodCacheBar();
          const streamParams=new URLSearchParams({action:'stream',repair:'1',path:file.path});if(extension==='ts'&&start>0)streamParams.set('start',String(start));
          const url='/aptv/api/recording/storage?'+streamParams.toString(),attempt=playbackAttemptId;
          const started=function(){if(attempt!==playbackAttemptId)return;if(playerState)playerState.hidden=true;};
          video.addEventListener('loadedmetadata',function(){if(attempt!==playbackAttemptId||!vodPlayback)return;const loadedDuration=Number(video.duration)+start;if((!Number.isFinite(vodPlayback.duration)||vodPlayback.duration<=0)&&Number.isFinite(loadedDuration)&&loadedDuration>0){vodPlayback.duration=loadedDuration;if(vodSeek)vodSeek.max=String(Math.max(1,Math.ceil(loadedDuration)));if(vodDuration)vodDuration.textContent=formatVodTime(loadedDuration);}},{once:true});
          video.addEventListener('playing',started,{once:true});
          if(extension==='ts'&&window.mpegts&&window.mpegts.isSupported()){
            aptvPlayer=window.mpegts.createPlayer({type:'mpegts',isLive:false,withCredentials:true,cors:false,url:url},{enableWorker:false,enableStashBuffer:true,fixAudioTimestampGap:true});
            aptvPlayer.on(window.mpegts.Events.MEDIA_INFO,function(info){playerTechnical.width=Number(info.width)||0;playerTechnical.height=Number(info.height)||0;playerTechnical.fps=Number(info.fps)||0;playerTechnical.videoCodec=info.videoCodec||'';playerTechnical.audioCodec=info.audioCodec||'';let mediaDuration=Number(info.duration);if(!Number.isFinite(mediaDuration)||mediaDuration<=0)mediaDuration=Number(info.duration_ms)/1000;if(mediaDuration>100000)mediaDuration/=1000;const totalDuration=mediaDuration+start;if(Number.isFinite(totalDuration)&&totalDuration>0&&vodPlayback){vodPlayback.duration=Math.max(Number(vodPlayback.duration)||0,totalDuration);if(vodSeek)vodSeek.max=String(Math.max(1,Math.ceil(vodPlayback.duration)));if(vodDuration)vodDuration.textContent=formatVodTime(vodPlayback.duration);}renderPlayerTechnical();});
            aptvPlayer.on(window.mpegts.Events.ERROR,function(errorType,errorDetail,errorInfo){if(attempt===playbackAttemptId&&playerState){playerState.hidden=false;const detail=String(errorDetail||errorType||'demux error');playerState.querySelector('span').textContent='[REC_PLAY_MPEGTS] '+<?php echo json_encode(_("Unable to play this recording.")); ?>+' ('+detail+')';}});
            aptvPlayer.attachMediaElement(video);aptvPlayer.load();if(autoPlay)aptvPlayer.play().catch(function(){});
          }else{video.src=url;video.load();if(autoPlay)video.play().catch(function(error){if(attempt===playbackAttemptId&&playerState&&error.name!=='NotAllowedError'){playerState.hidden=false;playerState.querySelector('span').textContent=error.message;}});}
        };
        const showVodProbeFailure=function(probe){
          probe=probe&&typeof probe==='object'?probe:{};vodTechnical=probe;playerProviderStatus=Number(probe.provider_status||0);const code=String(probe.code||'PROBE_FAILED'),message=String(probe.message||'Unable to analyze the provider response.'),status=playerProviderStatus?' · HTTP '+playerProviderStatus:'';const detail=code+status+' · '+message;playerPlaybackState='Provider error';playerProviderError=detail;setPlayerDiagnostic(detail);if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=detail;}renderPlayerDiagnostics();
        };
        const prepareVodSelection=function(){
          const title=movieTitle?.textContent||<?php echo json_encode(_("Movie")); ?>;
          stopChannel();
          currentLiveChannel=null;
          playerMode='vod';
          if(playerName)playerName.textContent=title;
        };
        moviePlay?.addEventListener('click',prepareVodSelection,true);
        moviePlayDanish?.addEventListener('click',prepareVodSelection,true);
        moviePlay?.addEventListener('click',function(){
          if(!moviePlay.dataset.movieId||(libraryKind!=='vod'&&moviePlay.dataset.mediaKind!=='episode'))return;
          playerLibraryKind=libraryKind;
          const id=moviePlay.dataset.movieId;const title=movieTitle?.textContent||<?php echo json_encode(_("Movie")); ?>;
          if(libraryKind==='vod'){playingMovieId=String(id);refreshMovieNowPlaying();}
          if(libraryKind==='series'&&selectedSeries&&seriesSeasons){playingEpisodeId=String(id);playingSeriesView={selectedSeries:selectedSeries,seasons:seriesSeasons,season:seriesActiveSeason,episodeId:playingEpisodeId};renderSeriesSeason(seriesSeasons,seriesActiveSeason,true);}
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Analyzing movie tracks…")); ?>;}showPlayerSurface();setMoviesOpen(false);
          const playbackCatalogGeneration=libraryProviderGeneration,providerId=selectedProviderId(),probeParams=new URLSearchParams({id:String(id)});if(providerId)probeParams.set('provider_id',providerId);fetch('/aptv/api/vod/probe?'+probeParams.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.text().then(function(text){let probe=null;try{probe=JSON.parse(text);}catch(error){probe={ok:false,code:response.redirected?'AUTHENTICATION_REQUIRED':'PROBE_INVALID_RESPONSE',stage:response.redirected?'authentication':'probe',message:response.redirected?'The APtv session requires authentication again.':'The probe returned an invalid response.'};}if(playbackCatalogGeneration!==libraryProviderGeneration)return null;if(!response.ok||!probe?.ok){showVodProbeFailure(probe);return null;}return probe;});}).then(function(probe){if(!probe||playbackCatalogGeneration!==libraryProviderGeneration)return;vodTechnical=probe;if((libraryKind==='vod'&&(String(id)==='160289'||probe.direct_compatible))||probe.progressive_series||String(id)==='2141355')startDirectVod(id,title,Number(probe.duration)||0,probe.audio||[]);else startVod(id,title,0,Number(probe.duration)||0,probe.audio||[],0);}).catch(function(){if(playbackCatalogGeneration===libraryProviderGeneration)showVodProbeFailure({ok:false,code:'PROBE_REQUEST_FAILED',stage:'probe',message:'The technical probe could not be completed.'});});
        });
        moviePlayDanish?.addEventListener('click',function(){
          if(libraryKind!=='vod'||!moviePlayDanish.dataset.movieId)return;
          playerLibraryKind='vod';
          const id=moviePlayDanish.dataset.movieId;const title=movieTitle?.textContent||<?php echo json_encode(_("Movie")); ?>;
          if(playerState){playerState.hidden=false;playerState.querySelector('span').textContent=<?php echo json_encode(_("Preparing Danish playback…")); ?>;}showPlayerSurface();setMoviesOpen(false);
          const playbackCatalogGeneration=libraryProviderGeneration,providerId=selectedProviderId(),probeParams=new URLSearchParams({id:String(id)});if(providerId)probeParams.set('provider_id',providerId);fetch('/aptv/api/vod/probe?'+probeParams.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.text().then(function(text){let probe=null;try{probe=JSON.parse(text);}catch(error){probe={ok:false,code:response.redirected?'AUTHENTICATION_REQUIRED':'PROBE_INVALID_RESPONSE',stage:response.redirected?'authentication':'probe',message:response.redirected?'The APtv session requires authentication again.':'The probe returned an invalid response.'};}if(playbackCatalogGeneration!==libraryProviderGeneration)return null;if(!response.ok||!probe?.ok){showVodProbeFailure(probe);return null;}return probe;});}).then(function(probe){if(!probe||playbackCatalogGeneration!==libraryProviderGeneration)return;vodTechnical=probe;startVod(id,title,0,Number(probe.duration)||0,probe.audio||[],1);}).catch(function(){if(playbackCatalogGeneration===libraryProviderGeneration)showVodProbeFailure({ok:false,code:'PROBE_REQUEST_FAILED',stage:'probe',message:'The technical probe could not be completed.'});});
        });
        const beginVodSeek=function(){if(!vodPlayback)return;if(vodPlayback.recording&&!vodSeeking)vodPlayback.resumeAfterSeek=!video.paused;vodSeeking=true;};
        vodSeek?.addEventListener('pointerdown',beginVodSeek);
        vodSeek?.addEventListener('input',function(event){if(!vodPlayback)return;beginVodSeek();pendingVodSeek=Number(vodSeek.value)||0;if(vodCurrent)vodCurrent.textContent=formatVodTime(pendingVodSeek);updateVodSeekPreview(event.clientX);});
        const vodSeekPreview=document.querySelector('[data-openap-vod-seek-preview]'),vodSeekWrap=vodSeek?.closest('[data-openap-vod-seek-wrap]');
        const calculateVodSeekTarget=function(clientX){if(!vodSeek||!vodPlayback||!Number.isFinite(clientX))return null;const duration=Number(vodPlayback.duration)||Number(video?.duration)||0,rect=vodSeek.getBoundingClientRect();if(duration<=0||rect.width<=0)return null;const ratio=Math.max(0,Math.min(1,(clientX-rect.left)/rect.width)),target=Math.max(0,Math.min(duration,Math.round(duration*ratio)));return {duration:duration,rect:rect,ratio:ratio,target:target};};const updateVodSeekPreview=function(clientX){if(!vodSeekPreview||!vodSeek||!vodPlayback)return;const measurement=calculateVodSeekTarget(clientX);if(!measurement)return;const ratio=measurement.ratio,target=measurement.target;vodSeekPreview.textContent=formatVodTime(target);vodSeekPreview.style.position='absolute';vodSeekPreview.style.left=(ratio*100)+'%';vodSeekPreview.style.top='auto';vodSeekPreview.style.bottom='calc(100% + 8px)';vodSeekPreview.style.transform='translateX(-50%)';vodSeekPreview.style.zIndex='2147483647';vodSeekPreview.style.pointerEvents='none';vodSeekPreview.style.display='block';vodSeekPreview.style.visibility='visible';vodSeekPreview.style.padding='3px 7px';vodSeekPreview.style.borderRadius='4px';vodSeekPreview.style.background='rgba(0,0,0,.88)';vodSeekPreview.style.color='#fff';vodSeekPreview.style.fontSize='12px';vodSeekPreview.style.lineHeight='1.2';vodSeekPreview.style.whiteSpace='nowrap';vodSeekPreview.hidden=false;};
        const vodSeekTargetFromPointer=function(event){if(!vodSeek||!vodPlayback||!event||!Number.isFinite(event.clientX))return null;const measurement=calculateVodSeekTarget(event.clientX);if(!measurement)return null;const target=measurement.target;vodSeek.value=String(target);pendingVodSeek=target;if(vodCurrent)vodCurrent.textContent=formatVodTime(target);return target;};
        const hideVodSeekPreview=function(){if(vodSeekPreview){vodSeekPreview.hidden=true;vodSeekPreview.style.display='none';}};
        [vodSeek,vodSeekWrap].filter(Boolean).forEach(function(node){node.addEventListener('pointerenter',function(event){updateVodSeekPreview(event.clientX);});node.addEventListener('pointermove',function(event){updateVodSeekPreview(event.clientX);});node.addEventListener('mousemove',function(event){updateVodSeekPreview(event.clientX);});node.addEventListener('pointerleave',hideVodSeekPreview);});
        vodSeek?.addEventListener('pointercancel',hideVodSeekPreview);
        const vodTimerPosition=function(){if(!vodPlayback)return 0;const requested=Math.max(0,Number(vodPlayback.position)||0);if(!vodPlayback.fmp4)return requested;const media=Math.max(0,(Number(vodPlayback.offset)||0)+(Number(video.currentTime)||0));return Math.max(requested,media);};
        
const recordVodSeekDecision=function(source,target){if(!vodPlayback||!Number.isFinite(Number(target)))return;const playback=vodPlayback,current=vodTimerPosition(),cacheStart=playerVodCacheStart>0?playerVodCacheStart:(Number(playback.cacheStart)||0),cacheEnd=playerVodCacheEnd>cacheStart?playerVodCacheEnd:cacheStart+(Number(playerVodCacheSeconds)||0),session=/^[a-f0-9]{32}$/.test(String(playback.progressiveSession||''))?String(playback.progressiveSession).slice(0,8):'--',cacheHit=Boolean(playback.cache&&playback.fmp4&&Number(target)>=cacheStart&&Number(target)<Math.max(cacheStart,cacheEnd-.25)&&session!=='--'),action=cacheHit?'reuse-cache':(playback.fmp4&&progressiveSeekRequiresRestart(Number(target))?'replace-cache':'native-buffer');recordPlayerEvent('Seek decision · '+source+' · current '+formatVodTime(current)+' · target '+formatVodTime(Number(target))+' · cache '+formatVodTime(cacheStart)+'–'+formatVodTime(cacheEnd)+' · '+action+' · session '+session);renderPlayerDiagnostics();};
        document.addEventListener('pointerdown',function(event){if(!vodSeek||!vodPlayback)return;const measurement=event.target===vodSeek||event.target?.closest?.('[data-openap-vod-seek-wrap]')?calculateVodSeekTarget(event.clientX):null;if(measurement)recordVodSeekDecision('pointer',measurement.target);},true);
        document.addEventListener('click',function(event){const button=event.target?.closest?.('[data-openap-vod-skip]');if(!button||!vodPlayback)return;const timerPosition=vodTimerPosition(),target=Math.max(0,Math.min(vodPlayback.duration||Infinity,timerPosition+Number(button.dataset.openapVodSkip)));recordVodSeekDecision('skip '+String(button.dataset.openapVodSkip)+'s',target);},true);
        document.addEventListener('mousemove',function(event){if(!vodSeek||!vodPlayback||!vodControls||vodControls.hidden||!vodControls.classList.contains('is-vod'))return;const rect=vodSeek.getBoundingClientRect();if(event.clientX>=rect.left&&event.clientX<=rect.right&&event.clientY>=rect.top&&event.clientY<=rect.bottom)updateVodSeekPreview(event.clientX);else hideVodSeekPreview();},{passive:true});
        const progressiveSeekRequiresRestart=function(target){if(!vodPlayback?.fmp4||!video?.buffered)return false;const relativeTarget=target-(Number(vodPlayback.offset)||0);for(let index=0;index<video.buffered.length;index++){if(relativeTarget>=video.buffered.start(index)-.25&&relativeTarget<=video.buffered.end(index)+.25)return false;}return true;};
        
const vodCacheHitForTarget=function(playback,target){if(!playback?.cache||!playback?.fmp4||!/^[a-f0-9]{32}$/.test(String(playback.progressiveSession||'')))return false;const cacheStart=playerVodCacheStart>0?playerVodCacheStart:(Number(playback.cacheStart)||0),cacheEnd=playerVodCacheEnd>cacheStart?playerVodCacheEnd:cacheStart+(Number(playerVodCacheSeconds)||0);return Number(target)>=cacheStart&&Number(target)<Math.max(cacheStart,cacheEnd-.25);};
const restartDirectVodForSeek=function(playback,target){const cacheHit=vodCacheHitForTarget(playback,target),cacheSession=cacheHit?String(playback.progressiveSession):null;if(cacheHit)recordPlayerEvent('Cache seek reuse · target '+formatVodTime(target));startDirectVod(playback.id,playback.title,playback.duration,playback.tracks,target,cacheSession,false,!cacheHit);};
const resumeRecordingPlaybackAfterSeek=function(playback,resumeRecording){if(!playback?.recording||!resumeRecording)return;const attemptId=playbackAttemptId;let resumed=false;const resume=function(){if(resumed||attemptId!==playbackAttemptId||vodPlayback!==playback||!video.paused)return;resumed=true;const request=video.play();if(request?.catch)request.catch(function(error){recordPlayerEvent('Recording seek resume failed · '+String(error.name||error));});};video.addEventListener('seeked',resume,{once:true});window.setTimeout(resume,180);};
const restartRecordingPlaybackAt=function(playback,target,resumeRecording){if(!playback?.recording||String(playback.recordingExtension||'').toLowerCase()!=='ts')return false;recordPlayerEvent('Recording server seek · target '+formatVodTime(target));startRecordingPlayback({path:playback.id,name:playback.title,duration:playback.duration},target,resumeRecording);return true;};
const commitVodSeek=function(){if(!vodPlayback||!vodSeeking)return;const playback=vodPlayback,target=pendingVodSeek===null?(Number(vodSeek.value)||0):pendingVodSeek,resumeRecording=Boolean(playback.recording&&(playback.resumeAfterSeek||(playback.recordingEnded&&target<Number(playback.duration)-.08)));if(playback.recording)delete playback.resumeAfterSeek;vodSeeking=false;pendingVodSeek=null;const seekDiagnostic='Tooltip target '+formatVodTime(target)+' = '+target+' s · sent target '+target+' s';window.setTimeout(function(){recordPlayerEvent(seekDiagnostic);renderPlayerDiagnostics();},0);if(playback.direct){if(playback.fmp4){
restartDirectVodForSeek(playback,target);
return;
}if(playback.recording&&restartRecordingPlaybackAt(playback,target,resumeRecording))return;playback.position=target;if(playback.recording){resumeRecordingPlaybackAfterSeek(playback,resumeRecording);if(video.readyState===0)video.addEventListener('loadedmetadata',function(){video.currentTime=target;},{once:true});else video.currentTime=target;}else if(video.readyState===0)video.addEventListener('loadedmetadata',function(){video.currentTime=target;},{once:true});else video.currentTime=target;}else if(playback.remux)startAudioRemuxVod(playback.id,playback.title,target,playback.duration,playback.tracks,playback.audio);else startVod(playback.id,playback.title,target,playback.duration,playback.tracks,playback.audio);};
        vodSeek?.addEventListener('pointerup',function(event){if(!vodPlayback)return;vodSeekTargetFromPointer(event);commitVodSeek();},true);
        vodSeek?.addEventListener('change',commitVodSeek);
document.querySelectorAll('[data-openap-vod-skip]').forEach(function(button){button.addEventListener('click',function(){if(!vodPlayback)return;const timerPosition=vodTimerPosition(),target=Math.max(0,Math.min(vodPlayback.duration||Infinity,timerPosition+Number(button.dataset.openapVodSkip)));if(vodPlayback.direct){if(vodPlayback.fmp4){
restartDirectVodForSeek(vodPlayback,target);
return;
}if(vodPlayback.recording&&restartRecordingPlaybackAt(vodPlayback,target,Boolean(!video.paused||(vodPlayback.recordingEnded&&target<Number(vodPlayback.duration)-.08))))return;const resumeRecording=Boolean(vodPlayback.recording&&!video.paused);if(vodPlayback.recording)resumeRecordingPlaybackAfterSeek(vodPlayback,resumeRecording);video.currentTime=target-(Number(vodPlayback.offset)||0);vodPlayback.position=target;}else startVod(vodPlayback.id,vodPlayback.title,target,vodPlayback.duration,vodPlayback.tracks,vodPlayback.audio);});});
        playerPlay?.addEventListener('click',function(){
          if(playerMode==='live')playerLiveIntent=video.paused?'want-play':'want-pause';
          if(wallTraditionalPlayerSession){const wallPlayer=wallTraditionalPlayerSession.player;if(wallPlayer.video?.paused)startWallPlayback(wallPlayer);else stopWallPlayback(wallPlayer);return;}
          if(progressiveRecoveryActive){stopProgressiveRecovery();progressiveRecoveryCooldownUntil=performance.now()+4000;recordPlayerEvent('Adaptive buffer recovery overridden');video.play().catch(function(){});return;}
          if(playerMode==='vod'&&vodPlayback?.hls&&vodPlayback.hlsPaused&&['vod','series'].includes(playerLibraryKind)){const playback=vodPlayback;startVod(playback.id,playback.title,playback.position,playback.duration,playback.tracks,playback.audio);return;}
          if(playerMode==='vod'&&vodPlayback&&!vodPlayback.direct&&!vodPlayback.hls){if(!vodPlayback.paused){const saved=vodPlayback;if(vodClock){clearInterval(vodClock);vodClock=0;}if(webrtcHeartbeat){clearInterval(webrtcHeartbeat);webrtcHeartbeat=0;}if(whepReader){whepReader.close();whepReader=null;}if(webrtcToken){fetch('/aptv/api/webrtc/stop',{method:'POST',credentials:'same-origin',body:new URLSearchParams({token:webrtcToken,csrf_token:aptvCsrf}),keepalive:true}).catch(function(){});webrtcToken='';}video.pause();video.srcObject=null;saved.paused=true;vodPlayback=saved;playerPlay.querySelector('i').className='fas fa-play';}else{startVod(vodPlayback.id,vodPlayback.title,vodPlayback.position,vodPlayback.duration,vodPlayback.tracks,vodPlayback.audio);}}else if(video.paused){video.play().catch(function(){});}else video.pause();
        });
        video.addEventListener('timeupdate',function(){if(!vodPlayback||(!vodPlayback.direct&&!vodPlayback.remux)||vodSeeking)return;vodPlayback.position=(vodPlayback.offset||0)+(video.currentTime||0);if(vodSeek)vodSeek.value=String(Math.round(vodPlayback.position));if(vodCurrent)vodCurrent.textContent=formatVodTime(vodPlayback.position);renderVodCacheBar();const absoluteTime=(Number(vodPlayback.offset)||0)+(Number(video.currentTime)||0);if(vodPlayback.recording&&vodPlayback.duration>0&&absoluteTime>=vodPlayback.duration-.08&&!vodPlayback.recordingEnded){vodPlayback.recordingEnded=true;vodPlayback.position=vodPlayback.duration;video.pause();video.currentTime=Math.max(0,vodPlayback.duration-(Number(vodPlayback.offset)||0));if(vodSeek)vodSeek.value=String(Math.round(vodPlayback.duration));if(vodCurrent)vodCurrent.textContent=formatVodTime(vodPlayback.duration);if(aptvPlayer){try{aptvPlayer.pause();aptvPlayer.unload();aptvPlayer.detachMediaElement();aptvPlayer.destroy();}catch(error){}aptvPlayer=null;}}});
        video.addEventListener('timeupdate',function(){if(vodPlayback?.hls&&!vodSeeking)renderVodCacheBar();});
        video.addEventListener('timeupdate',syncAptvMediaSession);
        video.addEventListener('durationchange',syncAptvMediaSession);
        video.addEventListener('play',syncAptvMediaSession);
        video.addEventListener('pause',syncAptvMediaSession);
        video.addEventListener('ended',syncAptvMediaSession);
        video.addEventListener('seeked',function(){if(!vodPlayback||!vodPlayback.direct)return;vodSeeking=false;pendingVodSeek=null;const seekOffset=(vodPlayback.fmp4||vodPlayback.recording)?(vodPlayback.offset||0):0;vodPlayback.position=seekOffset+(video.currentTime||0);if(vodSeek)vodSeek.value=String(Math.round(vodPlayback.position));if(vodCurrent)vodCurrent.textContent=formatVodTime(vodPlayback.position);if(vodPlayback.hybrid)startHybridVodAudio(vodPlayback,vodPlayback.audio,vodPlayback.position);});
        video.addEventListener('playing',function(){if(playerState)playerState.hidden=true;});
        video.addEventListener('play',function(){window.clearTimeout(vodPauseRestartClock);vodPauseRestartClock=0;if(vodPlayback?.hls&&!vodPlayback.hlsPaused)vodPlayback.paused=false;if(vodPlayback?.cache&&vodPlayback.restartOnResume&&!progressiveRecoveryActive){vodPlayback.restartOnResume=false;vodPlayback.forceRelayRestart=false;playerOttPauseState='resumed from VPS cache';renderProgressiveDiagnostics();recordPlayerEvent('fMP4 resumed from VPS cache');}if(vodPlayback?.restartOnResume&&vodPlayback.fmp4&&!vodPlayback.cache&&!progressiveRecoveryActive){const playback=vodPlayback,bufferAhead=progressiveBufferAhead(),relayState=String(playerProgressiveFfmpegState||''),relayStopped=/^(?:exit|no output|stopped)/i.test(relayState)||Number(playerProgressiveOutputGapSeconds)>8;
recordPauseResumeDecision(bufferAhead,relayState,relayStopped,playback);if(bufferAhead<4||playback.forceRelayRestart){const target=Math.max(0,Number(playback.pausePosition??playback.position??0));capturePauseResumeState("resume",playback,bufferAhead,relayState,relayStopped,target);playerPauseResumePending={reason:playback.forceRelayRestart?"forceRelayRestart":bufferAhead<4?"bufferAhead":relayStopped?"relayStopped":"buffered",target:target,snapshot:playerPauseResumeSnapshot};if(playback.forceRelayRestart){playerOttPauseState='relay restart requested';renderProgressiveDiagnostics();}playback.restartOnResume=false;playback.forceRelayRestart=false;recordPlayerEvent(playback.audioTranscoded?'fMP4 relay restarted after pause · audio timestamp reset':'fMP4 relay restarted after pause');startDirectVod(playback.id,playback.title,playback.duration,playback.tracks,target);return;}playback.restartOnResume=false;if(playerOttTestActive){playerOttPauseState='resumed from buffered data';renderProgressiveDiagnostics();}recordPlayerEvent('fMP4 resumed from buffered data');}if(vodPlayback?.hybrid)hybridAudio?.play().catch(function(){});if(playerPlay)playerPlay.querySelector('i').className='fas fa-pause';});video.addEventListener('pause',function(){armVodHlsPauseRestart();if(vodPlayback?.direct&&vodPlayback.fmp4&&!progressiveRecoveryActive&&!suppressVodPauseRestart){vodPlayback.restartOnResume=true;vodPlayback.pausePosition=Math.max(0,(vodPlayback.offset||0)+(video.currentTime||0));const pauseBufferAhead=progressiveBufferAhead(),pauseRelayState=String(playerProgressiveFfmpegState||""),pauseRelayStopped=/(?:exit|no output|stopped)/i.test(pauseRelayState)||Number(playerProgressiveOutputGapSeconds)>8;capturePauseResumeState("pause",vodPlayback,pauseBufferAhead,pauseRelayState,pauseRelayStopped);if(playerOttTestActive){playerOttPauseState='waiting 15 s';renderProgressiveDiagnostics();}if(vodPlayback?.fmp4){const pausedPlayback=vodPlayback;vodPauseRestartClock=window.setTimeout(function(){if(vodPlayback===pausedPlayback&&video.paused&&pausedPlayback.fmp4){pausedPlayback.forceRelayRestart=true;playerOttPauseState='armed after 15 s';renderProgressiveDiagnostics();recordPlayerEvent('OTT test: relay restart armed after long pause');}},15000);}}if(vodPlayback?.hybrid)hybridAudio?.pause();if(playerPlay)playerPlay.querySelector('i').className=progressiveRecoveryActive?'fas fa-pause':'fas fa-play';});
        // After a long pause, keep the current MSE buffer on screen and let it
        // resume before replacing the MediaSource.  The relay restart is
        // deferred until the buffer is low; replacing the source directly from
        // the play event caused an avoidable black interval.
        video.addEventListener('play',function(){const playback=vodPlayback;if(playback?.direct&&playback.fmp4&&!playback.cache&&!progressiveRecoveryActive&&playback.forceRelayRestart&&progressiveBufferAhead()>4){playback.forceRelayRestart=false;playback.relayRestartDeferred=true;playback.relayRestartDeferredLogged=false;playerOttPauseState='resuming from buffered data';recordPlayerEvent('OTT test: resume from buffered data · relay restart deferred');renderProgressiveDiagnostics();}},true);
        // A natural end also raises pause. Suppress the manual-pause path for
        // that event so it cannot arm a relay restart or show "Video paused".
        video.addEventListener('pause',function(){if(video?.ended){suppressVodPauseRestart=true;window.setTimeout(function(){suppressVodPauseRestart=false;},0);}},true);
        playerMute?.addEventListener('click',function(){const wallPlayer=wallTraditionalPlayerSession?.player,media=wallPlayer?.video||(vodPlayback?.hybrid?hybridAudio:video);media.muted=!media.muted;if(wallPlayer){video.muted=true;syncWallMuteControl(wallPlayer);}syncPlayerMuteControl(media);});
        playerVolume?.addEventListener('input',function(){const media=wallTraditionalPlayerSession?.player?.video||(vodPlayback?.hybrid?hybridAudio:video);media.volume=Number(playerVolume.value);media.muted=media.volume===0;if(wallTraditionalPlayerSession)video.muted=true;syncPlayerMuteControl(media);});
        let playerFullscreenAnimation=null;
        const cancelPlayerFullscreenAnimation=function(){
          if(playerFullscreenAnimation){playerFullscreenAnimation.cancel();playerFullscreenAnimation=null;}
        };
        const waitForFullscreenState=function(entering){
          if(Boolean(document.fullscreenElement)===entering)return Promise.resolve();
          return new Promise(function(resolve){
            let settled=false;
            const finish=function(){if(settled)return;settled=true;document.removeEventListener('fullscreenchange',finish);document.removeEventListener('webkitfullscreenchange',finish);resolve();};
            document.addEventListener('fullscreenchange',finish,{once:true});
            document.addEventListener('webkitfullscreenchange',finish,{once:true});
            window.setTimeout(finish,700);
          });
        };
        const animatePlayerFullscreen=function(before){
          if(!playerSurface||!before||window.matchMedia('(prefers-reduced-motion: reduce)').matches||typeof playerSurface.animate!=='function')return;
          const after=playerSurface.getBoundingClientRect();
          if(!before.width||!before.height||!after.width||!after.height)return;
          cancelPlayerFullscreenAnimation();
          const animation=playerSurface.animate([
            {transform:'translate('+(before.left-after.left)+'px,'+(before.top-after.top)+'px) scale('+(before.width/after.width)+','+(before.height/after.height)+')'},
            {transform:'translate(0,0) scale(1,1)'}
          ],{duration:420,easing:'cubic-bezier(.22,1,.36,1)',fill:'none'});
          animation.effect.setKeyframes(animation.effect.getKeyframes().map(function(frame){return Object.assign(frame,{transformOrigin:'0 0'});}));
          playerFullscreenAnimation=animation;
          animation.onfinish=function(){if(playerFullscreenAnimation===animation)playerFullscreenAnimation=null;};
          animation.oncancel=function(){if(playerFullscreenAnimation===animation)playerFullscreenAnimation=null;};
        };
        const desktopPlayerChromeDuration=420;
        let desktopPlayerChromeTimer=0;
        let pendingPlayerFullscreenExitBefore=null;
        let playerFullscreenExitAnimationScheduled=false;
        const clearDesktopPlayerChromeTimer=function(){if(desktopPlayerChromeTimer){window.clearTimeout(desktopPlayerChromeTimer);desktopPlayerChromeTimer=0;}};
        const desktopPlayerChromeMotionEnabled=function(){return window.matchMedia('(min-width:992px) and (prefers-reduced-motion: no-preference)').matches;};
        const setDesktopPlayerChromeState=function(state){const root=document.documentElement;root.classList.remove('openap-player-chrome-exit','openap-player-chrome-enter');if(state)root.classList.add('openap-player-chrome-'+state);};
        const prepareDesktopPlayerChromeExit=function(before){if(!desktopPlayerChromeMotionEnabled())return Promise.resolve(false);clearDesktopPlayerChromeTimer();setDesktopPlayerChromeState('exit');playerSurface?.classList.add('is-desktop-fullscreen-transition');const playerAnimation=animatePlayerFullscreen(before),chromeAnimation=new Promise(function(resolve){desktopPlayerChromeTimer=window.setTimeout(function(){desktopPlayerChromeTimer=0;resolve();},desktopPlayerChromeDuration);}),playerAnimationDone=playerAnimation?.finished?.catch(function(){})||Promise.resolve();return Promise.all([chromeAnimation,playerAnimationDone]).then(function(){return Boolean(playerAnimation);});};
        const restoreDesktopPlayerChrome=function(){clearDesktopPlayerChromeTimer();if(!desktopPlayerChromeMotionEnabled()){setDesktopPlayerChromeState('');return;}setDesktopPlayerChromeState('enter');desktopPlayerChromeTimer=window.setTimeout(function(){desktopPlayerChromeTimer=0;setDesktopPlayerChromeState('');},desktopPlayerChromeDuration);};
        const schedulePlayerFullscreenExitAnimation=function(before){if(!before||playerFullscreenExitAnimationScheduled)return;playerFullscreenExitAnimationScheduled=true;playerSurface?.classList.remove('is-desktop-fullscreen-transition');window.requestAnimationFrame(function(){if(pendingPlayerFullscreenExitBefore===before){animatePlayerFullscreen(before);pendingPlayerFullscreenExitBefore=null;}playerFullscreenExitAnimationScheduled=false;});};
        const togglePlayerFullscreen=async function(){
          try{
            const entering=!document.fullscreenElement;
            const before=playerSurface?.getBoundingClientRect();
            cancelPlayerFullscreenAnimation();
            let preFullscreenAnimated=false;
            if(entering){
              pendingPlayerFullscreenExitBefore=null;
              playerFullscreenExitAnimationScheduled=false;
              preFullscreenAnimated=await prepareDesktopPlayerChromeExit(before);
              await playerSurface?.requestFullscreen?.();
            }else{
              pendingPlayerFullscreenExitBefore=before;
              clearDesktopPlayerChromeTimer();
              setDesktopPlayerChromeState('exit');
              await document.exitFullscreen?.();
            }
            await waitForFullscreenState(entering);
            if(entering&&!preFullscreenAnimated)animatePlayerFullscreen(before);
            if(!entering&&pendingPlayerFullscreenExitBefore===before)schedulePlayerFullscreenExitAnimation(before);
            if(entering&&window.matchMedia('(max-width:991.98px)').matches&&screen.orientation?.lock){
              try{await screen.orientation.lock('landscape');}catch(error){}
            }
          }catch(error){cancelPlayerFullscreenAnimation();pendingPlayerFullscreenExitBefore=null;playerFullscreenExitAnimationScheduled=false;playerSurface?.classList.remove('is-desktop-fullscreen-transition');if(!document.fullscreenElement)restoreDesktopPlayerChrome();}
        };
        playerFullscreen?.addEventListener('click',togglePlayerFullscreen);
        let orientationUnlockTimer=0;
        const syncPlayerFullscreenState=function(){
          const fullscreenElement=document.fullscreenElement||document.webkitFullscreenElement;
          const active=fullscreenElement===playerSurface;
          playerSurface?.classList.toggle('is-fullscreen',active);
          if(playerFullscreen)playerFullscreen.querySelector('i').className=active?'fas fa-compress':'fas fa-expand';
          if(active){clearDesktopPlayerChromeTimer();if(desktopPlayerChromeMotionEnabled())setDesktopPlayerChromeState('exit');}
          else if(!fullscreenElement){const exitBefore=pendingPlayerFullscreenExitBefore;if(exitBefore)schedulePlayerFullscreenExitAnimation(exitBefore);else playerSurface?.classList.remove('is-desktop-fullscreen-transition');restoreDesktopPlayerChrome();}
        };
        document.addEventListener('fullscreenchange',function(){
          syncPlayerFullscreenState();
          if(!document.fullscreenElement)cancelPlayerFullscreenAnimation();
          window.clearTimeout(orientationUnlockTimer);
          orientationUnlockTimer=window.setTimeout(function(){
            if(document.fullscreenElement||wallMobileFullscreen||!screen.orientation?.unlock)return;
            try{screen.orientation.unlock();}catch(error){}
          },400);
        });
        document.addEventListener('webkitfullscreenchange',syncPlayerFullscreenState);
        audioToggle?.addEventListener('click',function(){if(!audioMenu)return;audioMenu.hidden=!audioMenu.hidden;audioToggle.setAttribute('aria-expanded',audioMenu.hidden?'false':'true');});
        const restorePlayingSeriesView=function(){if(!playingSeriesView||playerMode!=='vod'||playerLibraryKind!=='series'||!movieGrid)return false;selectedSeries=playingSeriesView.selectedSeries;seriesSeasons=playingSeriesView.seasons;seriesEpisodesMode=true;const season=String(playingSeriesView.season||Object.keys(seriesSeasons||{})[0]||'');if(!season)return false;if(movieBackdrop){const poster=String(selectedSeries?.detail?.poster_url||selectedSeries?.item?.poster_url||'');movieBackdrop.style.backgroundImage=poster?'url("'+poster.replace(/["\\]/g,'')+'")':'';}renderSeriesSeason(seriesSeasons,season);const episode=(seriesSeasons[season]||[]).find(function(item){return String(item.item_id)===String(playingSeriesView.episodeId);});const card=movieGrid?.querySelector('[data-episode-id="'+CSS.escape(String(playingSeriesView.episodeId))+'"]');if(episode)selectSeriesEpisode(episode,card);return true;};
        const setMoviesOpen=function(open,preserveMini){if(!moviesDrawer)return;const active=activeMediaSection==='vod'||activeMediaSection==='series';syncMediaSectionSelector();if(recordingsView)recordingsView.hidden=true;recordingsToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded','false');toggle.classList.remove('is-active');});document.querySelector('.openap-iptv-page')?.classList.remove('is-recordings');if(open){closeEpg();setLiveOpen(false);setSettingsOpen(false);}else if(!preserveMini)setMiniPlayer(false);moviesDrawer.classList.toggle('is-open',open);moviesDrawer.setAttribute('aria-hidden',open?'false':'true');liveToggles.forEach(function(toggle){toggle.classList.toggle('is-active',activeMediaSection==='live');});moviesToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded',open&&activeMediaSection==='vod'?'true':'false');toggle.classList.toggle('is-active',activeMediaSection==='vod');});seriesToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded',open&&activeMediaSection==='series'?'true':'false');toggle.classList.toggle('is-active',activeMediaSection==='series');});document.querySelector('.openap-iptv-page')?.classList.toggle('is-movies',active);if(moviesView){moviesView.hidden=!active;moviesView.classList.toggle('is-menu-open',open);}if(active&&!moviesInitialized)loadMovieRegions();};
        const closeMoviesMenu=function(){setMoviesOpen(false,true);if(backdrop){backdrop.classList.remove('is-open');backdrop.hidden=true;}};
        const dismissMediaMenuOnDesktopClick=function(event){
          if(window.matchMedia('(max-width:991.98px)').matches)return;
          const target=event.target;
          const eventPath=event.composedPath();
          if(!(target instanceof Element))return;
          if(target.closest('[data-openap-iptv-live-toggle],[data-openap-iptv-movies-toggle],[data-openap-iptv-series-toggle],[data-openap-recordings-toggle],[data-openap-iptv-settings-toggle],[data-openap-wall-toggle],[data-openap-global-search-toggle]'))return;
          if(liveDrawer?.classList.contains('is-open')&&!liveDrawer.contains(target)&&!eventPath.includes(liveDrawer))setLiveOpen(false);
          if(moviesDrawer?.classList.contains('is-open')&&!moviesDrawer.contains(target)&&!eventPath.includes(moviesDrawer))closeMoviesMenu();
        };
        document.addEventListener('click',dismissMediaMenuOnDesktopClick);
        moviesMobileCollapse?.addEventListener('click',closeMoviesMenu);
        const openLibrary=function(kind,openMenu,skipInitialCatalog){if(playerSurface&&!playerSurface.hidden){if(playerMode==='live')setMiniPlayer(true);else if(playerMode==='vod')setMiniPlayer(kind!==playerLibraryKind||Boolean(openMenu));}else stopChannel();const changed=libraryKind!==kind;activeMediaSection=kind;if(changed){searchGeneration++;searchReturnState=null;searchBack.hidden=true;if(seasonSelect)seasonSelect.hidden=true;libraryKind=kind;moviesInitialized=false;moviesLevel='regions';moviesRegion='';moviesItems=[];movieSearchMode=false;selectedSeries=null;seriesEpisodesMode=false;syncMovieCatalogExpansion(false);if(movieSearch)movieSearch.value='';if(moviePlay)delete moviePlay.dataset.mediaKind;if(movieGrid)movieGrid.replaceChildren();if(movieBackdrop)movieBackdrop.style.backgroundImage='';if(movieTitle)movieTitle.textContent=kind==='series'?<?php echo json_encode(_("Choose a TV series")); ?>:<?php echo json_encode(_("Choose a movie")); ?>;if(movieDescription)movieDescription.textContent=<?php echo json_encode(_("Select a poster below to view the details.")); ?>;}preserveSearchCatalogSelection=Boolean(skipInitialCatalog);const restored=kind==='series'&&restorePlayingSeriesView();if(moviesView){moviesView.classList.toggle('is-library-vod',kind==='vod');moviesView.classList.toggle('is-library-series',kind==='series');}if(moviesDrawer){moviesDrawer.classList.toggle('is-library-vod',kind==='vod');moviesDrawer.classList.toggle('is-library-series',kind==='series');}updateLibrarySearchContext();if(moviesDrawerTitle)moviesDrawerTitle.textContent=kind==='series'?<?php echo json_encode(_("TV Series")); ?>:<?php echo json_encode(_("Movies")); ?>;if(restored)moviesInitialized=true;setMoviesOpen(Boolean(openMenu),true);};
        const switchExpandedMediaDrawer=function(callback){const page=document.querySelector('.openap-iptv-page');if(!page){callback();return;}page.classList.add('is-media-drawer-switching');callback();void page.offsetWidth;page.classList.remove('is-media-drawer-switching');};
        const keepMediaMenuOnSectionChange=function(){return !window.matchMedia('(max-width:991.98px)').matches&&Boolean(liveDrawer?.classList.contains('is-open')||moviesDrawer?.classList.contains('is-open'));};
        let mediaSectionTransitioning=false;
        const mediaSectionLayer=function(section){if(section==='recordings')return recordingsView&&!recordingsView.hidden?[recordingsView]:[];if(section==='vod'||section==='series')return moviesView&&!moviesView.hidden?[moviesView]:[];return ['.openap-iptv-date','.openap-iptv-primary-actions','.openap-iptv-bottom-actions','.openap-iptv-expiration','.openap-aptv-player'].map(function(selector){return document.querySelector(selector);}).filter(function(node){return node&&!node.hidden&&(node!==playerSurface||(!playerSurface.classList.contains('is-mini')&&!playerMiniAnimation));});};
        let pendingHeroSectionEntrance=false,suppressMovieDetailEntrance=false,suppressMovieDetailEntranceTimer=0,heroPosterReadyUrl='',heroPosterLoadingUrl='';
        const currentHeroPosterUrl=function(){const background=String(movieBackdrop?.style.backgroundImage||'');return background.replace(/^url\(["']?/,'').replace(/["']?\)$/,'');};
        const animateHeroSectionEntrance=function(){if(!movieBackdrop||window.matchMedia('(prefers-reduced-motion: reduce)').matches){pendingHeroSectionEntrance=false;return;}const heroUrl=currentHeroPosterUrl();if(heroUrl&&heroPosterReadyUrl!==heroUrl){if(heroPosterLoadingUrl===heroUrl)return;heroPosterLoadingUrl=heroUrl;const poster=new Image();const posterReady=function(){if(heroPosterLoadingUrl!==heroUrl)return;heroPosterLoadingUrl='';heroPosterReadyUrl=heroUrl;if(currentHeroPosterUrl()!==heroUrl||!pendingHeroSectionEntrance)return;animateHeroSectionEntrance();};poster.onload=posterReady;poster.onerror=posterReady;poster.src=heroUrl;if(poster.complete&&poster.naturalWidth>0)posterReady();return;}pendingHeroSectionEntrance=false;movieBackdrop.style.opacity='0';movieBackdrop.style.transform='translateX(-90px)';const animation=movieBackdrop.animate([{opacity:0,transform:'translateX(-90px)'},{opacity:1,transform:'translateX(0)'}],{duration:360,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.finished.catch(function(){}).finally(function(){movieBackdrop.style.removeProperty('opacity');movieBackdrop.style.removeProperty('transform');});};
        const animateMovieDetailEntrance=function(){if(pendingHeroSectionEntrance&&movieBackdrop?.style.backgroundImage){suppressMovieDetailEntrance=false;window.clearTimeout(suppressMovieDetailEntranceTimer);suppressMovieDetailEntranceTimer=0;animateHeroSectionEntrance();return;}if(suppressMovieDetailEntrance){suppressMovieDetailEntrance=false;window.clearTimeout(suppressMovieDetailEntranceTimer);suppressMovieDetailEntranceTimer=0;return;}if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){pendingHeroSectionEntrance=false;return;}pendingHeroSectionEntrance=false;const compactMobile=window.matchMedia('(max-width:767.98px)').matches,keyframes=compactMobile?[{opacity:1,transform:'translateX(-14px)'},{opacity:1,transform:'translateX(0)'}]:[{opacity:0},{opacity:1}],options={duration:360,easing:'ease-out',fill:'backwards'},nodes=compactMobile?[movieTitle,movieMeta,movieDescription]:[movieBackdrop,movieTitle,movieMeta,movieDescription];if(compactMobile&&movieBackdrop?.style.backgroundImage){movieBackdrop.style.transform='translateY(calc(-100% - 100vh))';movieBackdrop.getAnimations?.().forEach(function(animation){animation.cancel();});const heroAnimation=movieBackdrop.animate([{opacity:1,transform:'translateY(calc(-100% - 100vh))'},{opacity:1,transform:'translateY(0)'}],{duration:420,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});heroAnimation.finished.catch(function(){}).finally(function(){heroAnimation.cancel();movieBackdrop.style.removeProperty('transform');});}nodes.filter(Boolean).forEach(function(node){node.getAnimations?.().forEach(function(animation){animation.cancel();});node.animate(keyframes,options);});};
        const animateMobileLibraryEntrance=function(){pendingHeroSectionEntrance=false;if(!movieCatalog||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;movieCatalog.getAnimations?.().forEach(function(animation){animation.cancel();});const animation=movieCatalog.animate([{opacity:0,transform:'translateY(16px)'},{opacity:1,transform:'translateY(0)'}],{duration:340,delay:160,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});animation.finished.catch(function(){}).finally(function(){animation.cancel();});};
        const animatePendingHeroEntrance=function(useCurrentPoster){if(window.matchMedia('(max-width:991.98px)').matches){pendingHeroSectionEntrance=false;return;}if(!useCurrentPoster||!moviesInitialized||!movieBackdrop?.style.backgroundImage)return;animateHeroSectionEntrance();};
        const transitionMediaSection=async function(nextSection,changeSection){if(mediaSectionTransitioning)return;const animateFooterCaret=expandableMediaSections.has(activeMediaSection)&&expandableMediaSections.has(nextSection);if(animateFooterCaret&&footerNavigation){window.clearTimeout(mediaSelectorCaretTimer);footerNavigation.classList.add('is-caret-sliding');}if(nextSection!==activeMediaSection&&window.matchMedia('(max-width:991.98px)').matches){setLiveOpen(false);closeMoviesMenu();setSettingsOpen(false);}if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){changeSection();return;}mediaSectionTransitioning=true;const order={live:0,vod:1,series:2,recordings:3,settings:4},forward=(order[nextSection]??0)>(order[activeMediaSection]??0),distance=forward?24:-24,enteringLibrary=nextSection==='vod'||nextSection==='series',librarySwap=(activeMediaSection==='vod'||activeMediaSection==='series')&&enteringLibrary,enteringLibraryFromOtherSection=enteringLibrary&&!librarySwap,mobileLibraryTransition=enteringLibrary&&window.matchMedia('(max-width:991.98px)').matches;const outgoing=mediaSectionLayer(activeMediaSection),outAnimations=outgoing.map(function(node){return node.animate([{opacity:1,transform:'translateY(0)'},{opacity:0,transform:'translateY('+(-distance)+'px)'}],{duration:150,easing:'ease-in',fill:'forwards'});});if(enteringLibrary){pendingHeroSectionEntrance=true;if(window.matchMedia('(min-width:992px)').matches){suppressMovieDetailEntrance=true;window.clearTimeout(suppressMovieDetailEntranceTimer);suppressMovieDetailEntranceTimer=window.setTimeout(function(){suppressMovieDetailEntrance=false;suppressMovieDetailEntranceTimer=0;},3500);}}if(librarySwap&&movieBackdrop&&!mobileLibraryTransition)outAnimations.push(movieBackdrop.animate([{opacity:1,transform:'translateX(0)'},{opacity:0,transform:'translateX(110%)'}],{duration:220,easing:'cubic-bezier(.4,0,1,1)',fill:'forwards'}));try{await Promise.all(outAnimations.map(function(animation){return animation.finished.catch(function(){});}));outAnimations.forEach(function(animation){animation.cancel();});if(mobileLibraryTransition&&movieBackdrop)movieBackdrop.style.transform='translateY(calc(-100% - 100vh))';changeSection();if(mobileLibraryTransition){animateMobileLibraryEntrance();if(moviesInitialized&&movieBackdrop?.style.backgroundImage)animateMovieDetailEntrance();}else animatePendingHeroEntrance(enteringLibraryFromOtherSection);const incoming=mobileLibraryTransition?[]:mediaSectionLayer(nextSection),inAnimations=incoming.map(function(node){return node.animate([{opacity:0,transform:'translateY('+distance+'px)'},{opacity:1,transform:'translateY(0)'}],{duration:230,easing:'cubic-bezier(.22,1,.36,1)',fill:'backwards'});});await Promise.all(inAnimations.map(function(animation){return animation.finished.catch(function(){});}));}finally{outAnimations.forEach(function(animation){animation.cancel();});mediaSectionTransitioning=false;}};
        moviesToggles.forEach(function(toggle){toggle.addEventListener('click',function(){if(activeMediaSection!=='vod'){const keepMenuOpen=keepMediaMenuOnSectionChange();transitionMediaSection('vod',function(){if(keepMenuOpen)switchExpandedMediaDrawer(function(){openLibrary('vod',true);});else openLibrary('vod',false);});}else if(playerMode==='vod'&&playerLibraryKind==='vod'&&playerSurface&&!playerSurface.hidden){const mini=playerSurface.classList.contains('is-mini');if(mini){setMoviesOpen(false,true);setMiniPlayer(false);}else{setMiniPlayer(true);setMoviesOpen(true,true);}}else if(moviesDrawer?.classList.contains('is-open'))closeMoviesMenu();else setMoviesOpen(true);});});seriesToggles.forEach(function(toggle){toggle.addEventListener('click',function(){if(activeMediaSection!=='series'){const keepMenuOpen=keepMediaMenuOnSectionChange();transitionMediaSection('series',function(){if(keepMenuOpen)switchExpandedMediaDrawer(function(){openLibrary('series',true);});else openLibrary('series',false);});}else if(playerMode==='vod'&&playerLibraryKind==='series'&&playerSurface&&!playerSurface.hidden){const mini=playerSurface.classList.contains('is-mini');if(mini){setMoviesOpen(false,true);setMiniPlayer(false);}else{setMiniPlayer(true);setMoviesOpen(true,true);}}else if(moviesDrawer?.classList.contains('is-open'))closeMoviesMenu();else setMoviesOpen(true);});});moviesBack?.addEventListener('click',function(){if(moviesLevel==='categories')loadMovieRegions('back');});moviesFilter?.addEventListener('input',renderMovieMenu);movieSearchToggle?.addEventListener('click',function(){const expanded=!movieSearchControl?.classList.contains('is-expanded');movieSearchControl?.classList.toggle('is-expanded',expanded);movieSearchToggle.setAttribute('aria-expanded',expanded?'true':'false');if(expanded)window.setTimeout(function(){movieSearch?.focus();},220);else movieSearch?.blur();});movieSearch?.addEventListener('input',function(){clearTimeout(movieSearchTimer);const query=movieSearch.value;movieSearchTimer=setTimeout(function(){searchMovies(query);},250);});movieGrid?.addEventListener('scroll',maybeLoadMoreMovies,{passive:true});
        // The shared Movies/TV Series drawer uses the same left arrow as Live:
        // at the country root it closes the expanded menu.
        moviesBack?.addEventListener('click',function(){if(moviesLevel==='regions')closeMoviesMenu();},true);
        if(moviesBack){new MutationObserver(function(){if(moviesLevel==='regions'&&moviesBack.disabled){moviesBack.disabled=false;moviesBack.removeAttribute('aria-disabled');}}).observe(moviesBack,{attributes:true,attributeFilter:['disabled','aria-disabled']});}
        const renderLiveItems = function () {
          if (!liveList) return;
          const restoreScrollTop=liveListScrollTop;
          const needle = String(liveFilter?.value || '').trim().toLocaleLowerCase();
          liveList.replaceChildren();
          liveItems.filter(function (item) { return String(item.label || item.name || '').toLocaleLowerCase().includes(needle); }).forEach(function (item, itemIndex) {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'openap-iptv-live-item';
            const isFavoritesRegion=liveLevel==='regions'&&item.code===liveFavoritesRegion;
            const orderId=String(liveLevel === 'categories' ? item.category_id : (liveLevel === 'channels' ? item.item_id : item.code));
            if(orderId!==liveFavoritesRegion)button.dataset.orderId=orderId;
            if (orderEditing&&!isFavoritesRegion) {
              button.classList.add('is-ordering'); button.draggable = true;
              button.style.setProperty('--openap-order-row-index', String(Math.min(itemIndex, 12)));
              const selector = document.createElement('span'); selector.className = 'openap-iptv-order-selector'; selector.setAttribute('role','checkbox'); selector.setAttribute('aria-checked','false'); selector.setAttribute('aria-label',<?php echo json_encode(_("Select item")); ?>);
              const toggleSelection=function(){const id=button.dataset.orderId;const selected=!selectedOrderItems.has(id);if(selected)selectedOrderItems.add(id);else selectedOrderItems.delete(id);selector.setAttribute('aria-checked',selected?'true':'false');button.classList.toggle('is-selected',selected);updateSelectionActions();};
              selector.addEventListener('click',function(event){event.stopPropagation();toggleSelection();});
              selector.addEventListener('keydown',function(event){if(event.key===' '||event.key==='Enter'){event.preventDefault();event.stopPropagation();toggleSelection();}}); selector.tabIndex=0;
              button.append(selector);
              const grip = document.createElement('i'); grip.className = 'fas fa-grip-vertical openap-iptv-order-grip'; grip.setAttribute('aria-hidden','true');
              button.append(grip);
              const finishPointerDrag=function(event){if(grip.hasPointerCapture(event.pointerId))grip.releasePointerCapture(event.pointerId);if(draggedOrderItem===button)draggedOrderItem=null;setOrderDragState(liveList,button,false);};
              grip.addEventListener('pointerdown',function(event){event.preventDefault();draggedOrderItem=button;setOrderDragState(liveList,button,true);grip.setPointerCapture(event.pointerId);});
              grip.addEventListener('pointermove',function(event){if(draggedOrderItem!==button)return;event.preventDefault();reorderListItemAtPoint(liveList,button,event);});
              grip.addEventListener('pointerup',finishPointerDrag);
              grip.addEventListener('pointercancel',finishPointerDrag);
              button.addEventListener('dragstart',function(event){draggedOrderItem=button;setOrderDragState(liveList,button,true);event.dataTransfer.effectAllowed='move';});
              button.addEventListener('dragend',function(){if(draggedOrderItem===button)draggedOrderItem=null;setOrderDragState(liveList,button,false);});
              button.addEventListener('dragover',function(event){event.preventDefault();if(!draggedOrderItem||draggedOrderItem===button)return;reorderListItemAtPoint(liveList,draggedOrderItem,event);});
              button.addEventListener('keydown',function(event){if(!event.altKey||!['ArrowUp','ArrowDown','Home','End'].includes(event.key))return;event.preventDefault();if(event.key==='ArrowUp'&&button.previousElementSibling)liveList.insertBefore(button,button.previousElementSibling);else if(event.key==='ArrowDown'&&button.nextElementSibling)liveList.insertBefore(button.nextElementSibling,button);else if(event.key==='Home')liveList.prepend(button);else if(event.key==='End')liveList.append(button);button.focus();});
            }
            let icon;
            if (liveLevel === 'regions') {
              icon = isFavoritesRegion ? document.createElement('i') : createRegionFlag(item.code);
              if(isFavoritesRegion)icon.className='fas fa-star';
              icon.setAttribute('aria-hidden', 'true');
            } else if (liveLevel === 'channels' && item.icon_url) {
              icon = document.createElement('img');
              icon.className = 'openap-iptv-channel-list-logo'; icon.src = item.icon_url;
              icon.alt = ''; icon.loading = 'lazy'; icon.referrerPolicy = 'no-referrer';
              icon.addEventListener('error', function () {
                const fallback = document.createElement('i'); fallback.className = 'fas fa-play-circle';
                fallback.setAttribute('aria-hidden', 'true'); icon.replaceWith(fallback);
              }, {once:true});
            } else {
              icon = document.createElement('i');
              icon.className = liveLevel === 'channels' ? 'fas fa-play-circle' : (liveLevel === 'regions' ? (isFavoritesRegion?'fas fa-star':'fas fa-globe-europe') : 'fas fa-list');
              icon.setAttribute('aria-hidden', 'true');
            }
            const text = document.createElement('span'); text.textContent = liveLevel === 'regions' ? String(item.label || item.name || '').toLocaleUpperCase() : (item.label || item.name);
            const count = document.createElement('small');
            count.textContent = liveLevel === 'regions' ? new Intl.NumberFormat().format(item.channels) : (liveLevel === 'categories' ? new Intl.NumberFormat().format(item.channels) : '');
            button.append(icon, text, count);
            if(liveLevel==='channels'){
              if(wallChannelPickerOpen){
                const wallAction=document.createElement('span'),wallProviderId=wallItemProviderId(item),selectedWallPlayer=wallSelectedPlayerForItem(item);wallAction.className='openap-iptv-live-wall-action';wallAction.dataset.wallItemId=String(item.item_id||'');wallAction.dataset.wallProviderId=wallProviderId;wallAction.setAttribute('role','button');wallAction.setAttribute('tabindex','0');wallAction.classList.toggle('is-selected',Boolean(selectedWallPlayer));wallAction.innerHTML='<i class="fas '+(selectedWallPlayer?'fa-minus':'fa-plus')+'" aria-hidden="true"></i>';
                const addToWall=function(event){event.preventDefault();event.stopPropagation();if(wallAction.classList.contains('is-disabled'))return;if(wallSelectedPlayerForItem(item)){closeWallTile(wallSelectedPlayerForItem(item));return;}addWallChannel(item);};wallAction.addEventListener('click',addToWall);wallAction.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){addToWall(event);}});button.append(wallAction);
              }else{
                const favoriteToggle=document.createElement('span');favoriteToggle.className='openap-iptv-live-favorite-toggle';favoriteToggle.setAttribute('role','button');favoriteToggle.setAttribute('tabindex','0');favoriteToggle.dataset.favoriteChannel=String(item.item_id||'');
                if(orderEditing){favoriteToggle.classList.add('is-ordering-hidden');favoriteToggle.setAttribute('aria-hidden','true');favoriteToggle.removeAttribute('role');favoriteToggle.setAttribute('tabindex','-1');}
                const updateFavoriteToggle=function(){const selected=isLiveFavorite(item);favoriteToggle.classList.toggle('is-selected',selected);favoriteToggle.setAttribute('aria-pressed',selected?'true':'false');favoriteToggle.setAttribute('title',selected?<?php echo json_encode(_("Remove from Favorites")); ?>:<?php echo json_encode(_("Add to Favorites")); ?>);favoriteToggle.setAttribute('aria-label',favoriteToggle.title);favoriteToggle.innerHTML='<i class="'+(selected?'fas':'far')+' fa-star" aria-hidden="true"></i>';};
                const toggleFavorite=function(event){event.preventDefault();event.stopPropagation();toggleLiveFavorite(item);};favoriteToggle.addEventListener('click',toggleFavorite);favoriteToggle.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){toggleFavorite(event);}});updateFavoriteToggle();button.append(favoriteToggle);
              }
            }
            button.addEventListener('click', function () {
              if (orderEditing) return;
              if (liveLevel === 'regions') { if(item.code===liveFavoritesRegion)loadFavoriteChannels('forward');else loadCategories(item.code, 'forward'); }
              else if (liveLevel === 'categories') loadChannels(item.category_id, item.name, false, 'forward');
              else if (liveLevel === 'channels') { if(wallChannelPickerOpen)return;liveListScrollTop=liveList.scrollTop;playChannel(item); }
            });
            liveList.append(button);
          });
          if (!liveList.children.length) { const empty=document.createElement('p'); empty.className='openap-iptv-live-empty'; empty.textContent=<?php echo json_encode(_("No results")); ?>; liveList.append(empty); }
          syncWallPickerActions();
          window.requestAnimationFrame(function(){if(liveList)liveList.scrollTop=restoreScrollTop;});
        };
        const restoreLiveGlobalSearch=function(){const previous=liveGlobalSearchReturn;liveGlobalSearchReturn=null;liveGlobalSearchMode=false;if(!previous){loadRegions('back');return;}if(previous.level==='categories'&&previous.region)loadCategories(previous.region,'back');else if(previous.level==='channels'&&previous.region===liveFavoritesRegion)loadFavoriteChannels('back');else if(previous.level==='channels'&&previous.category)loadChannels(previous.category,previous.title||<?php echo json_encode(_("Channels")); ?>,false,'back');else loadRegions('back');};
        const runLiveGlobalSearch=async function(){
          const query=String(liveFilter?.value||'').trim();
          if((liveLevel!=='regions'&&liveLevel!=='categories'&&!liveGlobalSearchMode)||query.length<2){if(liveGlobalSearchMode)restoreLiveGlobalSearch();return;}
          if(!liveGlobalSearchMode)liveGlobalSearchReturn={level:liveLevel,region:liveRegion,category:liveCategory,title:liveChannelTitle};
          const request=++liveGlobalSearchRequest;const region=liveGlobalSearchReturn?.level==='categories'?liveGlobalSearchReturn.region:'';liveGlobalSearchMode=true;liveLoading=true;liveHasMore=false;liveLevel='channels';liveRegion='';liveCategory='';setLiveHeader(<?php echo json_encode(_("Channel search")); ?>,true,false);if(liveList)liveList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';
          try{const params={q:query,kind:'live',sort:'relevance',limit:'5000'},providerId=selectedProviderId();if(region)params.region=region;if(providerId)params.provider_id=providerId;const response=await fetch('/aptv/api/search?'+new URLSearchParams(params),{credentials:'same-origin',cache:'no-store'}),data=await response.json();if(request!==liveGlobalSearchRequest)return;if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to search live channels.")); ?>);liveItems=(Array.isArray(data.items)?data.items:[]).map(function(item){item.icon_url=item.result_icon_url||'';item.label=(item.country_code?item.country_code+'| ':'')+item.name;return item;});liveLoading=false;renderLiveItems();if(!liveItems.length&&liveList)liveList.textContent=<?php echo json_encode(_("No matching channels.")); ?>;}catch(error){if(request===liveGlobalSearchRequest&&liveList){liveLoading=false;liveList.textContent='[LIVE_SEARCH] '+error.message;}}finally{if(request===liveGlobalSearchRequest)liveLoading=false;}
        };
        const queueLiveGlobalSearch=function(){window.clearTimeout(liveGlobalSearchTimer);liveGlobalSearchTimer=window.setTimeout(runLiveGlobalSearch,180);};
        const animateLiveNavigation = function (direction) {
          if (!liveList || !direction || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
          const className = direction === 'back' ? 'openap-live-nav-enter-back' : 'openap-live-nav-enter-forward';
          const entries = Array.from(liveList.children);
          entries.forEach(function (entry, index) {
            entry.style.setProperty('--openap-live-nav-index', String(Math.min(index, 10)));
            entry.classList.add(className);
          });
          window.setTimeout(function () {
            entries.forEach(function (entry) {
              entry.classList.remove(className);
              entry.style.removeProperty('--openap-live-nav-index');
            });
          }, 480);
        };
        const setLiveHeader = function (title, canGoBack, uppercase) {
          const titleNode = document.getElementById('openapIptvLiveTitle'); if (titleNode) titleNode.textContent = title;
          titleNode?.classList.toggle('openap-iptv-uppercase-title',Boolean(uppercase));
          liveClose?.querySelector('i')?.classList.toggle('fa-arrow-left', !canGoBack);
          liveClose?.querySelector('i')?.classList.toggle('fa-chevron-left', canGoBack);
          if(editOrderButton) editOrderButton.hidden=orderEditing;
        };
        const maybeLoadMoreChannels = function () {
          if (!liveList || liveLevel !== 'channels' || !liveHasMore || liveLoading) return;
          const remaining = liveList.scrollHeight - liveList.scrollTop - liveList.clientHeight;
          if (remaining <= 160) loadChannels(liveCategory, liveChannelTitle, true);
        };
        const loadRegions = async function (direction) {
          liveGlobalSearchMode=false;liveGlobalSearchRequest++;
          orderEditing=false;
          liveListScrollTop=0;
          liveRequestId++; const requestId=liveRequestId; liveLoading=false; liveHasMore=false; liveLevel='regions'; liveRegion=''; liveCategory=''; liveCategoryCollapsed=false; if(liveFilter) liveFilter.value=''; setLiveHeader(<?php echo json_encode(_("Live Stream")); ?>, false, true);
          if(liveList) liveList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';
          try { const data=await catalogRequest({view:'regions'}); if(requestId!==liveRequestId)return; readLiveFavorites(); const regions=data.items.map(function(x){x.label=regionNames[x.code] || x.code; return x;}); liveItems=[{code:liveFavoritesRegion,label:liveFavoritesLabel,name:liveFavoritesLabel,channels:liveFavorites.length,isFavorites:true}].concat(regions); renderLiveItems(); animateLiveNavigation(direction); liveInitialized=true; } catch(e) { if(requestId===liveRequestId&&liveList) liveList.textContent=e.message; }
        };
        const loadFavoriteChannels=function(direction){
          liveGlobalSearchMode=false;liveGlobalSearchRequest++;orderEditing=false;liveListScrollTop=0;liveRequestId++;liveLoading=false;liveHasMore=false;liveLevel='channels';liveRegion=liveFavoritesRegion;liveCategory='';liveCategoryCollapsed=false;liveChannelTitle=liveFavoritesLabel;readLiveFavorites();if(liveFilter)liveFilter.value='';setLiveHeader(liveFavoritesLabel,true,false);const providerId=String(wallProviderSwitchSelect?.value||providerSwitchSelect?.value||'');liveItems=liveFavorites.map(function(item){return /^[a-f0-9]{32}$/.test(providerId)?Object.assign({},item,{provider_id:providerId}):item;});liveOffset=liveItems.length;renderLiveItems();animateLiveNavigation(direction);
        };
        const loadCategories = async function (region, direction) {
          liveGlobalSearchMode=false;liveGlobalSearchRequest++;
          orderEditing=false;
          liveListScrollTop=0;
          liveRequestId++; const requestId=liveRequestId; liveLoading=false; liveHasMore=false; liveLevel='categories'; liveRegion=region; liveCategoryCollapsed=false; if(liveFilter) liveFilter.value=''; setLiveHeader(regionNames[region] || region, true, true);
          if(liveList) liveList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';
          try { const data=await catalogRequest({view:'categories',region:region}); if(requestId!==liveRequestId)return; if(Array.isArray(data.items)&&data.items.length===1&&data.items[0]?.category_id){liveCategoryCollapsed=true;await loadChannels(data.items[0].category_id,regionNames[region]||region,false,direction);return;} liveItems=data.items; renderLiveItems(); animateLiveNavigation(direction); } catch(e) { if(requestId===liveRequestId&&liveList) liveList.textContent=e.message; }
        };
        const loadChannels = async function (category, title, append, direction) {
          liveGlobalSearchMode=false;liveGlobalSearchRequest++;
          if (liveLoading || (append && (!liveHasMore || category !== liveCategory))) return;
          if (!append) {
            liveListScrollTop=0;
            liveRequestId++; liveOffset=0; liveHasMore=true; liveChannelTitle=title;
            if(liveFilter)liveFilter.value=''; setLiveHeader(title,true,false);
            if(liveList)liveList.innerHTML='<div class="openap-iptv-live-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';
          }
          liveLevel='channels'; liveCategory=category; liveLoading=true;
          const requestId=liveRequestId;
          try {
            const data=await catalogRequest({view:'channels',category:category,region:liveRegion,offset:liveOffset,limit:40});
            if(requestId!==liveRequestId)return;
            liveItems=append?liveItems.concat(data.items):data.items;
            liveOffset=liveItems.length; liveHasMore=Boolean(data.has_more); renderLiveItems(); if(!append)animateLiveNavigation(direction);
          } catch(e) {
            if(requestId===liveRequestId&&liveList)liveList.textContent=e.message;
          } finally {
            if(requestId===liveRequestId){liveLoading=false;window.requestAnimationFrame(maybeLoadMoreChannels);}
          }
        };
        const setOrderEditing = async function (enabled) {
          if (enabled && !['regions','categories','channels'].includes(liveLevel)) return;
          if (!enabled && orderEditing && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            liveList?.classList.remove('is-reordering');
            const header=editOrderButton?.closest('.openap-iptv-settings-header');
            header?.classList.add('is-ordering-closing');
            liveList?.querySelectorAll('.openap-iptv-live-item.is-ordering').forEach(function(row){row.classList.add('is-ordering-closing');});
            window.setTimeout(function(){
              header?.classList.remove('is-ordering-closing');
              orderEditing=false;
              setOrderEditing(false);
            },280);
            return;
          }
          if (enabled && liveLevel === 'channels' && liveHasMore) {
            liveLoading=true;
            try {
              const data=await catalogRequest({view:'channels',category:liveCategory,region:liveRegion,offset:0,limit:2000});
              liveItems=data.items; liveOffset=liveItems.length; liveHasMore=false;
            } catch(error) { liveList.textContent=error.message; liveLoading=false; return; }
            liveLoading=false;
          }
          orderEditing=enabled;
          if(enabled)liveList?.classList.remove('is-reordering');
          selectedOrderItems.clear();
          if(enabled){orderSnapshot=liveItems.slice();if(liveFilter)liveFilter.value='';}
          if(editOrderButton)editOrderButton.hidden=enabled;
          if(editCancelButton)editCancelButton.hidden=!enabled;
          if(editSaveButton)editSaveButton.hidden=!enabled;
          if(moveTopButton)moveTopButton.hidden=!enabled;
          if(moveBottomButton)moveBottomButton.hidden=!enabled;
          editOrderButton?.closest('.openap-iptv-settings-header')?.classList.toggle('is-ordering',enabled);
          liveFilter?.toggleAttribute('disabled',enabled);
          renderLiveItems();
          updateSelectionActions();
        };
        const updateSelectionActions = function () {
          const disabled=selectedOrderItems.size===0;
          if(moveTopButton)moveTopButton.disabled=disabled;
          if(moveBottomButton)moveBottomButton.disabled=disabled;
        };
        const moveSelected = function (toTop) {
          if(!liveList||selectedOrderItems.size===0)return;
          const selected=Array.from(liveList.querySelectorAll('[data-order-id]')).filter(function(node){return selectedOrderItems.has(node.dataset.orderId);});
          if(toTop){for(let index=selected.length-1;index>=0;index--)liveList.prepend(selected[index]);}
          else selected.forEach(function(node){liveList.append(node);});
        };
        moveTopButton?.addEventListener('click',function(){moveSelected(true);});
        moveBottomButton?.addEventListener('click',function(){moveSelected(false);});
        editOrderButton?.addEventListener('click',function(){setOrderEditing(true);});
        editCancelButton?.addEventListener('click',function(){liveItems=orderSnapshot.slice();setOrderEditing(false);});
        editSaveButton?.addEventListener('click',async function(){
          const ids=Array.from(liveList.querySelectorAll('[data-order-id]')).map(function(node){return node.dataset.orderId;});
          const scope=liveLevel==='regions'?'region':(liveLevel==='categories'?'category':'channel');
          const parent=liveLevel==='regions'?'global':(liveLevel==='categories'?liveRegion:liveCategory);
          editSaveButton.disabled=true;
          try {
            const csrf=document.querySelector('meta[name="csrf_token"]')?.content || '';
            const params=new URLSearchParams({csrf_token:csrf,scope:scope,parent:parent,order:JSON.stringify(ids)}),providerId=selectedProviderId();
            if(providerId)params.set('provider_id',providerId);
            const response=await fetch('/aptv/api/catalog/order',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
            const payload=await response.json(); if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to save the channel order.")); ?>);
            const byId=new Map(liveItems.map(function(item){return [String(scope==='region'?item.code:(scope==='category'?item.category_id:item.item_id)),item];}));
            const orderedItems=ids.map(function(id){return byId.get(id);}).filter(Boolean);liveItems=liveLevel==='regions'?[{code:liveFavoritesRegion,label:liveFavoritesLabel,name:liveFavoritesLabel,channels:liveFavorites.length,isFavorites:true}].concat(orderedItems):orderedItems;orderSnapshot=liveItems.slice();setOrderEditing(false);
          } catch(error) { window.alert(error.message); }
          finally { editSaveButton.disabled=false; }
        });
        const setLiveOpen = function(open, source) {
          if(!liveDrawer||!backdrop)return;
          syncMediaSectionSelector();
          if(open)closeEpg();
          if(recordingsView){recordingsView.hidden=true;recordingsToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded','false');toggle.classList.remove('is-active');});document.querySelector('.openap-iptv-page')?.classList.remove('is-recordings');}
          
          if(open && moviesDrawer?.classList.contains('is-open')) setMoviesOpen(false);
          if(open && drawer?.classList.contains('is-open')) setSettingsOpen(false);
          if(open) liveReturnFocus=source || document.activeElement;
          const wallPickerRequested=open&&wallChannelPickerOpen&&wallView&&!wallView.hidden&&document.querySelector('.openap-iptv-page')?.classList.contains('is-wall');if(!wallPickerRequested)wallChannelPickerOpen=false;const openingWallPicker=open&&wallChannelPickerOpen; const mobileWallPicker=openingWallPicker&&(wallMobileFullscreen||document.fullscreenElement===wallView||document.webkitFullscreenElement===wallView);
          if(!open) wallChannelPickerOpen=false;
          if(mobileWallPicker){window.clearTimeout(wallPickerCloseTimer);wallPickerCloseTimer=0;liveDrawer.hidden=false;liveDrawer.classList.add('is-wall-picker','is-wall-picker-landscape');liveDrawer.classList.remove('is-open','is-wall-picker-open');setWallPickerInlineStyle(true);liveDrawer.style.setProperty('transform','translate(-50%,-120px)','important');liveDrawer.style.setProperty('opacity','0','important');void liveDrawer.offsetWidth;window.requestAnimationFrame(function(){if(wallChannelPickerOpen){liveDrawer.classList.add('is-wall-picker-open');liveDrawer.style.setProperty('transform','translate(-50%,0)','important');liveDrawer.style.setProperty('opacity','1','important');}});}else if(openingWallPicker){window.clearTimeout(wallPickerCloseTimer);wallPickerCloseTimer=0;liveDrawer.hidden=false;liveDrawer.removeAttribute('style');liveDrawer.classList.remove('is-open','is-wall-picker-landscape');liveDrawer.classList.add('is-wall-picker','is-wall-picker-open');}else if(liveDrawer.classList.contains('is-wall-picker')){window.clearTimeout(wallPickerCloseTimer);wallPickerCloseTimer=0;liveDrawer.classList.remove('is-wall-picker-open');setWallPickerInlineStyle(false);wallPickerCloseTimer=window.setTimeout(function(){liveDrawer.hidden=true;liveDrawer.classList.remove('is-wall-picker','is-wall-picker-landscape','is-open');liveDrawer.removeAttribute('style');wallPickerCloseTimer=0;},300);}else{window.clearTimeout(liveDrawer._closeTimer);liveDrawer.classList.remove('is-wall-picker-open','is-wall-picker-landscape');liveDrawer.removeAttribute('style');if(open){liveDrawer.hidden=false;liveDrawer.classList.remove('is-open');void liveDrawer.offsetWidth;window.requestAnimationFrame(function(){liveDrawer.classList.add('is-open');});}else{liveDrawer.classList.remove('is-open');liveDrawer._closeTimer=window.setTimeout(function(){if(!liveDrawer.classList.contains('is-open')&&!liveDrawer.classList.contains('is-wall-picker'))liveDrawer.hidden=true;},380);}}
          // Never leave focus inside a drawer immediately before hiding it;
          // otherwise Chromium reports an aria-hidden/focused-descendant
          // warning and can retain the stale mobile wall-picker focus.
          if(!open && liveDrawer.contains(document.activeElement)) document.activeElement.blur();
          liveDrawer.setAttribute('aria-hidden',open?'false':'true');
          liveToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded',open?'true':'false');toggle.classList.toggle('is-active',open&&activeMediaSection==='live');});
          backdrop.hidden=!open; backdrop.classList.toggle('is-open',open);
          if(open){if(!liveInitialized)loadRegions();else{if(!openingWallPicker&&liveLevel==='channels')renderLiveItems();window.requestAnimationFrame(function(){if(liveList)liveList.scrollTop=liveListScrollTop;});}(openingWallPicker?liveClose:(window.matchMedia('(max-width:991.98px)').matches?liveMobileCollapse:liveClose))?.focus();}else if(liveReturnFocus instanceof HTMLElement){liveReturnFocus.focus();liveReturnFocus=null;}
        };
        const setRecordingsOpen=function(open){
          if(!recordingsView)return;
          if(open)activeMediaSection='recordings';
          if(open){
            closeEpg();
            if(playerSurface&&!playerSurface.hidden)setMiniPlayer(true);else stopChannel();
            setLiveOpen(false);setMoviesOpen(false,true);setSettingsOpen(false);
          }else if(!playerSurface||playerSurface.hidden){
            stopChannel();
          }
          recordingsView.hidden=!open;
          syncMediaSectionSelector();
          recordingsToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded',open?'true':'false');toggle.classList.toggle('is-active',open);});
          document.querySelector('.openap-iptv-page')?.classList.toggle('is-recordings',open);
          if(open){if(window.matchMedia('(min-width:992px)').matches)openRecordingsBrowser('local');refreshRecordings();}
        };
        recordingsToggles.forEach(function(toggle){
          toggle.addEventListener('click',function(){
            if(activeMediaSection==='recordings')return;
            transitionMediaSection('recordings',function(){setRecordingsOpen(true);});
          });
        });
        document.addEventListener('click',function(event){
          const toggle=event.target.closest('.openap-footer-iptv-nav [data-openap-recordings-toggle]');
          if(!toggle)return;
          if(activeMediaSection==='recordings')return;
          transitionMediaSection('recordings',function(){setRecordingsOpen(true);});
        });
        let globalSearchTimer=0,globalSearchRequest=0;
        const globalSearchText={start:<?php echo json_encode(_("Start typing to search the whole catalog.")); ?>,loading:<?php echo json_encode(_("Searching the catalog…")); ?>,empty:<?php echo json_encode(_("No matching channels, movies or TV series.")); ?>,results:<?php echo json_encode(_("results")); ?>,more:<?php echo json_encode(_("Showing the first 80 results. Refine the search to narrow it down.")); ?>};
        let globalSearchCloseTimer=0;
        const setGlobalSearchOpen=function(open){if(!globalSearchView)return;window.clearTimeout(globalSearchCloseTimer);if(open){closeEpg();setLiveOpen(false);setMoviesOpen(false,true);setSettingsOpen(false);globalSearchView.hidden=false;window.requestAnimationFrame(function(){globalSearchView.classList.add('is-open');});window.setTimeout(function(){globalSearchInput?.focus();},0);}else{globalSearchView.classList.remove('is-open');globalSearchView.hidden=true;}globalSearchToggles.forEach(function(toggle){toggle.setAttribute('aria-expanded',open?'true':'false');toggle.classList.toggle('is-active',open);});document.querySelector('.openap-iptv-page')?.classList.toggle('is-global-search',open);};
        const renderGlobalSearchResults=function(items){if(!globalSearchResults)return;globalSearchResults.replaceChildren();const labels={live:<?php echo json_encode(_("Channel")); ?>,vod:<?php echo json_encode(_("Movie")); ?>,series:<?php echo json_encode(_("TV Series")); ?>};items.forEach(function(item){const row=document.createElement('button');row.type='button';row.className='openap-aptv-global-search-result';const iconBox=document.createElement('span');iconBox.className='openap-aptv-global-search-provider-icon';if(item.result_icon_url){const image=document.createElement('img');image.src=item.result_icon_url;image.alt='';image.loading='lazy';image.addEventListener('error',function(){image.remove();const fallback=document.createElement('i');fallback.className=item.kind==='live'?'fas fa-tv':item.kind==='vod'?'fas fa-film':'fas fa-photo-video';fallback.setAttribute('aria-hidden','true');iconBox.append(fallback);},{once:true});iconBox.append(image);}else{const fallback=document.createElement('i');fallback.className=item.kind==='live'?'fas fa-tv':item.kind==='vod'?'fas fa-film':'fas fa-photo-video';fallback.setAttribute('aria-hidden','true');iconBox.append(fallback);}const copy=document.createElement('span');copy.className='openap-aptv-global-search-result-copy';const title=document.createElement('strong');title.textContent=(item.country_code?item.country_code+'| ':'')+item.name;const meta=document.createElement('small');meta.textContent=[labels[item.kind],Number(item.rating)>0?'★ '+Number(item.rating).toFixed(1):''].filter(Boolean).join(' · ');copy.append(title,meta);const arrow=document.createElement('i');arrow.className='fas fa-chevron-right';arrow.setAttribute('aria-hidden','true');row.append(iconBox,copy,arrow);row.addEventListener('click',async function(){setGlobalSearchOpen(false);if(item.kind==='live'){activeMediaSection='live';syncMediaSectionSelector();playChannel(item);return;}openLibrary(item.kind,false,true);if(item.category_id){await loadMovies(item.category_id,item.group_name||<?php echo json_encode(_("Selected group")); ?>,false,false);const card=movieGrid?.querySelector('[data-movie-id="'+CSS.escape(String(item.item_id))+'"]');const selected=card?item:item;loadMovieDetails(selected,card); }else loadMovieDetails(item,null);});globalSearchResults.append(row);});};
        const runGlobalSearch=async function(){const query=String(globalSearchInput?.value||'').trim();const request=++globalSearchRequest;if(query.length<2){if(globalSearchResults)globalSearchResults.replaceChildren();if(globalSearchStatus)globalSearchStatus.textContent=globalSearchText.start;return;}const kind=document.querySelector('input[name="aptvGlobalSearchKind"]:checked')?.value||'all';if(globalSearchBrowser)globalSearchBrowser.setAttribute('aria-busy','true');if(globalSearchStatus)globalSearchStatus.textContent=globalSearchText.loading;try{const response=await fetch('/aptv/api/search?'+new URLSearchParams({q:query,kind:kind,sort:globalSearchSort?.value||'relevance'}),{credentials:'same-origin',cache:'no-store'}),data=await response.json();if(request!==globalSearchRequest)return;if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to search the APtv catalog.")); ?>);const items=Array.isArray(data.items)?data.items:[];renderGlobalSearchResults(items);if(globalSearchStatus)globalSearchStatus.textContent=items.length?(items.length+' '+globalSearchText.results+(data.truncated?' · '+globalSearchText.more:'')):globalSearchText.empty;}catch(error){if(request===globalSearchRequest){if(globalSearchResults)globalSearchResults.replaceChildren();if(globalSearchStatus)globalSearchStatus.textContent='[SEARCH_CATALOG] '+error.message;}}finally{if(request===globalSearchRequest)globalSearchBrowser?.setAttribute('aria-busy','false');}};
        const queueGlobalSearch=function(){window.clearTimeout(globalSearchTimer);globalSearchTimer=window.setTimeout(runGlobalSearch,220);};
        globalSearchToggles.forEach(function(toggle){toggle.addEventListener('click',function(){setGlobalSearchOpen(globalSearchView?.hidden);});});
        document.querySelector('[data-openap-global-search-close]')?.addEventListener('click',function(){setGlobalSearchOpen(false);globalSearchToggles[0]?.focus();});
        document.addEventListener('click',function(event){if(!globalSearchView||globalSearchView.hidden)return;if(event.target.closest('[data-openap-global-search-browser]')||event.target.closest('[data-openap-global-search-toggle]'))return;setGlobalSearchOpen(false);});
        globalSearchForm?.addEventListener('submit',function(event){event.preventDefault();window.clearTimeout(globalSearchTimer);runGlobalSearch();});
        globalSearchInput?.addEventListener('input',queueGlobalSearch);globalSearchSort?.addEventListener('change',queueGlobalSearch);document.querySelectorAll('input[name="aptvGlobalSearchKind"]').forEach(function(input){input.addEventListener('change',queueGlobalSearch);});
        liveToggles.forEach(function(toggle){toggle.addEventListener('click',function(){if(activeMediaSection!=='live'){const keepMenuOpen=keepMediaMenuOnSectionChange();transitionMediaSection('live',function(){const activateLive=function(){activeMediaSection='live';setMoviesOpen(false,true);setMiniPlayer(playerMode==='vod'&&playerSurface&&!playerSurface.hidden);setLiveOpen(keepMenuOpen,toggle);};if(keepMenuOpen)switchExpandedMediaDrawer(activateLive);else activateLive();});}else setLiveOpen(!liveDrawer?.classList.contains('is-open'),toggle);});});
        const navigateLiveBack=function(event){
          event.preventDefault();
          event.stopImmediatePropagation();
          if(liveGlobalSearchMode){restoreLiveGlobalSearch();return;}
          if(liveLevel==='channels'&&liveRegion===liveFavoritesRegion){loadRegions('back');return;}
          if(liveLevel==='channels'){if(liveCategoryCollapsed){liveCategoryCollapsed=false;loadRegions('back');return;}loadCategories(liveRegion,'back');return;}
          if(liveLevel==='categories'){loadRegions('back');return;}
          setLiveOpen(false);
        };
        liveClose?.addEventListener('click',navigateLiveBack);
        liveDismiss?.addEventListener('click',function(){setLiveOpen(false);});
        liveMobileCollapse?.addEventListener('click',function(){setLiveOpen(false);});
        liveFilter?.addEventListener('input',function(){const query=String(liveFilter.value||'').trim();if((liveLevel==='regions'||liveLevel==='categories'||liveGlobalSearchMode)&&query.length>=2){queueLiveGlobalSearch();return;}if(liveGlobalSearchMode){window.clearTimeout(liveGlobalSearchTimer);restoreLiveGlobalSearch();return;}renderLiveItems();window.requestAnimationFrame(maybeLoadMoreChannels);});
        liveList?.addEventListener('scroll',function(){liveListScrollTop=liveList.scrollTop;maybeLoadMoreChannels();},{passive:true});

        const resetSettingsPanels = function () {
          window.cancelAnimationFrame(providerPanelFrame);
          providerPanelFrame = 0;
          window.clearTimeout(providerPanelAnimationTimer);
          providerPanelAnimationTimer = 0;
          rootPanel?.classList.remove('openap-live-nav-enter-forward', 'openap-live-nav-enter-back');
          providerPanel?.classList.remove('openap-live-nav-enter-forward', 'openap-live-nav-enter-back');
          drawer?.classList.remove('is-provider');
          rootPanel?.classList.add('is-active');
          providerPanel?.classList.remove('is-active');
          providerPanel?.style.removeProperty('visibility');
          profilePanel?.classList.remove('is-active');
          rootPanel?.setAttribute('aria-hidden', 'false');
          providerPanel?.setAttribute('aria-hidden', 'true');
          profilePanel?.setAttribute('aria-hidden', 'true');
          drawer?.querySelectorAll('[data-openap-iptv-settings-panel], .openap-iptv-settings-menu, .openap-iptv-profile-panel').forEach(function (node) {
            if (node instanceof HTMLElement) node.scrollTop = 0;
          });
        };
        const setSettingsOpen = function (open, source) {
          if (!drawer || !backdrop) return;
          if(open){resetSettingsPanels();closeEpg();}
          if (open && liveDrawer?.classList.contains('is-open')) setLiveOpen(false);
          if (open) returnFocus = source || document.activeElement;
          drawer.classList.toggle('is-open', open);
          drawer.style.setProperty('visibility', open ? 'visible' : 'hidden', 'important');
          drawer.style.setProperty('transform', open ? 'translateX(0)' : 'translateX(100%)', 'important');
          drawer.style.setProperty('z-index', open ? '2050' : '1050', 'important');
          drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
          toggles.forEach(function (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.classList.toggle('is-active', open);
          });
          if (open) {
            backdrop.hidden = false;
            backdrop.style.setProperty('visibility', 'visible', 'important');
            backdrop.style.setProperty('z-index', '2045', 'important');
            window.requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
          } else {
            backdrop.classList.remove('is-open');
            backdrop.style.setProperty('visibility', 'hidden', 'important');
            backdrop.style.removeProperty('z-index');
            window.setTimeout(function () {
              if (!drawer.classList.contains('is-open') && !liveDrawer?.classList.contains('is-open')) backdrop.hidden = true;
            }, 240);
          }
          if (open) {
            drawer.querySelector('.openap-iptv-settings-back')?.focus();
          } else if (returnFocus instanceof HTMLElement) {
            returnFocus.focus();
          }
        };

        // The global mobile footer is rendered after this APtv partial. Use
        // one capture-phase delegated handler so the footer Settings button is
        // handled even when it did not exist during the initial query. Capture
        // also prevents a second delegated handler from immediately toggling
        // the drawer closed again.
        document.addEventListener('click', function (event) {
          const toggle = event.target?.closest?.('[data-openap-iptv-settings-toggle]');
          if (!toggle || wallView?.classList.contains('is-open')) return;
          event.preventDefault();
          event.stopImmediatePropagation();
          setSettingsOpen(!drawer?.classList.contains('is-open'), toggle);
        }, true);

        // Leaving Settings for another APtv section must close the Settings
        // drawer first, including navigation controls rendered outside this
        // partial (for example the global mobile footer).
        document.addEventListener('click', function (event) {
          const sectionToggle = event.target?.closest?.('[data-openap-iptv-live-toggle],[data-openap-recordings-toggle],[data-openap-iptv-movies-toggle],[data-openap-iptv-series-toggle],[data-openap-global-search-toggle]');
          if (!sectionToggle || wallView?.classList.contains('is-open')) return;
          if (drawer?.classList.contains('is-open')) setSettingsOpen(false);
        }, true);
        dismissers.forEach(function (dismiss) {
          dismiss.addEventListener('click', function () { setSettingsOpen(false); setLiveOpen(false); });
        });
        playerSurface?.addEventListener('dblclick',function(event){
          if(event.target instanceof Element&&event.target.closest('button,input,select,textarea,a,[role="button"]'))return;
          event.preventDefault();
          if(!playerSurface.classList.contains('is-mini')){
            togglePlayerFullscreen();
            return;
          }
          const target=playerMode==='live'?'live':playerLibraryKind;
          const restore=function(){
            setSettingsOpen(false);
            setLiveOpen(false);
            if(target==='live'){
              activeMediaSection='live';
              setMoviesOpen(false,true);
              syncMediaSectionSelector();
              setMiniPlayer(false);
            }else{
              openLibrary(target,false);
            }
          };
          if(activeMediaSection===target)restore();else transitionMediaSection(target,restore);
        });
        const showProviderPanel = function (providerVisible) {
          window.cancelAnimationFrame(providerPanelFrame);
          providerPanelFrame = 0;
          window.clearTimeout(providerPanelAnimationTimer);
          providerPanelAnimationTimer = 0;
          rootPanel?.classList.remove('openap-live-nav-enter-forward', 'openap-live-nav-enter-back');
          providerPanel?.classList.remove('openap-live-nav-enter-forward', 'openap-live-nav-enter-back');
          if (!providerVisible) {
            drawer?.classList.remove('is-provider');
            providerPanel?.classList.remove('is-active');
            providerPanel?.style.removeProperty('visibility');
            providerPanel?.setAttribute('aria-hidden', 'true');
            profilePanel?.classList.remove('is-active');
            profilePanel?.setAttribute('aria-hidden', 'true');
            rootPanel?.classList.add('is-active');
            rootPanel?.setAttribute('aria-hidden', 'false');
            void rootPanel?.offsetWidth;
            rootPanel?.classList.add('openap-live-nav-enter-back');
            providerPanelAnimationTimer=window.setTimeout(function(){
              rootPanel?.classList.remove('openap-live-nav-enter-back');
            },480);
            providerMenu?.focus();
            return;
          }
          drawer?.classList.toggle('is-provider', providerVisible);
          rootPanel?.classList.remove('is-active');
          providerPanel?.classList.remove('is-active');
          providerPanel?.style.setProperty('visibility', 'visible', 'important');
          profilePanel?.classList.remove('is-active');
          rootPanel?.setAttribute('aria-hidden', 'true');
          providerPanel?.setAttribute('aria-hidden', 'true');
          profilePanel?.setAttribute('aria-hidden', 'true');
          void providerPanel?.offsetWidth;
          providerPanelFrame=window.requestAnimationFrame(function(){
            providerPanel?.classList.add('is-active');
            providerPanel?.classList.add('openap-live-nav-enter-forward');
            providerPanel?.style.removeProperty('visibility');
            providerPanel?.setAttribute('aria-hidden', 'false');
            providerPanelAnimationTimer=window.setTimeout(function(){
              providerPanel?.classList.remove('openap-live-nav-enter-forward');
            },480);
            providerBack?.focus();
          });
        };
        const showProfilePanel = function (profileVisible) {
          window.cancelAnimationFrame(profilePanelFrame);
          if (!profileVisible) {
            drawer?.classList.remove('is-provider');
            profilePanel?.classList.remove('is-active');
            rootPanel?.classList.add('is-active');
            rootPanel?.setAttribute('aria-hidden', 'false');
            providerPanel?.classList.remove('is-active');
            providerPanel?.setAttribute('aria-hidden', 'true');
            profilePanel?.setAttribute('aria-hidden', 'true');
            profileMenu?.focus();
            return;
          }
          drawer?.classList.add('is-provider');
          rootPanel?.classList.remove('is-active');
          providerPanel?.classList.remove('is-active');
          profilePanel?.classList.remove('is-active');
          rootPanel?.setAttribute('aria-hidden', 'true');
          providerPanel?.setAttribute('aria-hidden', 'true');
          profilePanel?.setAttribute('aria-hidden', 'true');
          void profilePanel?.offsetWidth;
          profilePanelFrame=window.requestAnimationFrame(function(){
            profilePanel?.classList.add('is-active');
            profilePanel?.setAttribute('aria-hidden', 'false');
            profileBack?.focus();
          });
        };
        let recordingState={items:[],destination:'',nfs_server:'',nfs_export:'',provider_id:'',service_online:false,nfs:{mounted:false,mountpoint:'/mnt/aptv-recordings'}},recordingBusy=false,recordingPollBusy=false,recordingRevision=0;
        const recordingScheduleStoppingIds=new Set();
        const recordingLabels={record:<?php echo json_encode(_("Record")); ?>,scheduled:<?php echo json_encode(_("Scheduled")); ?>,starting:<?php echo json_encode(_("Starting…")); ?>,recording:<?php echo json_encode(_("Recording")); ?>,reconnecting:<?php echo json_encode(_("Reconnecting…")); ?>,stopping:<?php echo json_encode(_("Stopping…")); ?>,completed:<?php echo json_encode(_("Recorded")); ?>,stopped:<?php echo json_encode(_("Stopped")); ?>,failed:<?php echo json_encode(_("Failed")); ?>,missed:<?php echo json_encode(_("Missed")); ?>,cancelled:<?php echo json_encode(_("Cancelled")); ?>,interrupted:<?php echo json_encode(_("Interrupted")); ?>};
        const recordingSettingsStatus=document.querySelector('[data-recording-settings-status]');
        const recordingNfsStatus=document.querySelector('[data-recording-nfs-status]');
        const recordingJobsList=document.querySelector('[data-recording-jobs]');
        const recordingScheduleList=document.querySelector('[data-openap-recording-schedule-list]');
        const recordingFolderLocal=document.querySelector('[data-openap-recording-folder-path="local"]');
        const recordingFolderNfs=document.querySelector('[data-openap-recording-folder-path="nfs"]');
        const recordingsBrowser=document.querySelector('[data-openap-recordings-browser]');
        const recordingsBrowserTitle=document.querySelector('[data-openap-recordings-browser-title]');
        const recordingsBrowserPath=document.querySelector('[data-openap-recordings-browser-path]');
        const recordingsBrowserStatus=document.querySelector('[data-openap-recordings-browser-status]');
        const recordingsFileList=document.querySelector('[data-openap-recordings-file-list]');
        const recordingsBrowserBack=document.querySelector('[data-openap-recordings-browser-back]');
        const recordingsWatch=document.querySelector('[data-openap-recordings-watch]');
        const recordingsDelete=document.querySelector('[data-openap-recordings-delete]');
        const recordingsActionBar=document.querySelector('.openap-aptv-recordings-browser-actions');
        let recordingsSelectedFile=null;
        let recordingsBrowserGeneration=0;
        const recordingsBrowserText={
          loading:<?php echo json_encode(_("Loading recorded files…")); ?>,
          empty:<?php echo json_encode(_("No recorded files in this destination.")); ?>,
          unavailable:<?php echo json_encode(_("This storage destination is not available.")); ?>,
          notMounted:<?php echo json_encode(_("NFS storage is not mounted. Mount it from Recording settings before browsing its files.")); ?>,
          truncated:<?php echo json_encode(_("Only the first 500 files are shown.")); ?>,
          fileCount:<?php echo json_encode(_("file")); ?>,
          filesCount:<?php echo json_encode(_("files")); ?>
        };
        const formatRecordingBytes=function(bytes){const value=Number(bytes)||0;if(value<1024)return value+' B';if(value<1048576)return (value/1024).toFixed(1)+' KiB';if(value<1073741824)return (value/1048576).toFixed(1)+' MiB';return (value/1073741824).toFixed(2)+' GiB';};
        const confirmRecordingDeletion=function(message,onConfirm){
          if(typeof window.openapNotify!=='function'){onConfirm();return;}
          window.openapNotify({level:'success',duration:0,icon:'fa-trash',title:<?php echo json_encode(_('Confirm removal')); ?>,message:message,actions:[{label:<?php echo json_encode(_('Remove')); ?>,icon:'fa-check',onClick:onConfirm},{label:<?php echo json_encode(_('Cancel')); ?>,icon:'fa-times',onClick:function(){}}]});
        };
        const closeRecordingsBrowser=function(){if(recordingsBrowser)recordingsBrowser.hidden=true;document.querySelector('[data-openap-recording-folder="local"]')?.focus();};
        const clearRecordingSelection=function(hideIndicator=true){recordingsSelectedFile=null;if(recordingsWatch)recordingsWatch.hidden=true;if(recordingsDelete)recordingsDelete.hidden=true;recordingsActionBar?.classList.remove('is-recording-actions-entering');recordingsWatch?.classList.remove('is-recording-action-entering');recordingsDelete?.classList.remove('is-recording-action-entering');recordingsFileList?.querySelectorAll('.is-selected').forEach(function(row){row.classList.remove('is-selected');});const indicator=recordingsFileList?.querySelector('.openap-recording-selection-indicator');if(indicator&&hideIndicator)indicator.hidden=true;};
        const moveRecordingSelectionIndicator=function(row){if(!recordingsFileList||!row)return;let indicator=recordingsFileList.querySelector('.openap-recording-selection-indicator');if(!indicator){indicator=document.createElement('span');indicator.className='openap-recording-selection-indicator';indicator.setAttribute('aria-hidden','true');recordingsFileList.append(indicator);}indicator.hidden=false;indicator.style.height=row.offsetHeight+'px';indicator.style.transform='translateY('+row.offsetTop+'px)';};
        const selectRecordingFile=function(file,row){const animateActions=!recordingsSelectedFile;clearRecordingSelection(false);recordingsSelectedFile=file;row.classList.add('is-selected');moveRecordingSelectionIndicator(row);recordingsWatch.hidden=false;recordingsDelete.hidden=false;if(animateActions){recordingsWatch.classList.add('is-recording-action-entering');recordingsDelete.classList.add('is-recording-action-entering');window.setTimeout(function(){recordingsWatch?.classList.remove('is-recording-action-entering');recordingsDelete?.classList.remove('is-recording-action-entering');},280);}};
        const openRecordingsBrowser=async function(type,path,direction){
          if(!recordingsBrowser)return;
          const generation=++recordingsBrowserGeneration;
          const isNfs=type==='nfs';
          const previousStorage=recordingsBrowser.dataset.recordingStorage||'';
          const storageSwitch=previousStorage&&previousStorage!==type&&!window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          if(storageSwitch){const switchClass=previousStorage==='local'&&type==='nfs'?'is-storage-switch-forward':'is-storage-switch-back';recordingsBrowser.classList.remove('is-storage-switch-forward','is-storage-switch-back');void recordingsBrowser.offsetWidth;recordingsBrowser.classList.add(switchClass);window.setTimeout(function(){recordingsBrowser?.classList.remove(switchClass);},420);}
          recordingsBrowser.dataset.recordingStorage=type;
          document.querySelectorAll('[data-openap-recording-folder]').forEach(function(folder){folder.classList.toggle('is-selected',folder.dataset.openapRecordingFolder===type);});
          const root=isNfs?(recordingState.nfs?.mountpoint||'/mnt/aptv-recordings')+'/APtv Recordings':'/var/lib/openap-recordings';
          const destination=path||root;
          recordingsBrowser.hidden=false;recordingsBrowser.setAttribute('aria-busy','true');
          clearRecordingSelection();
          recordingsBrowserTitle.textContent=path?destination.split('/').pop():(isNfs?<?php echo json_encode(_("NFS partition")); ?>:<?php echo json_encode(_("Local")); ?>);
          recordingsBrowserPath.textContent=destination;recordingsBrowserStatus.textContent=recordingsBrowserText.loading;recordingsFileList.replaceChildren();
          recordingsBrowserBack.hidden=!path;
          const navigationClass=direction==='back'?'openap-recording-nav-enter-back':'openap-recording-nav-enter-forward';
          let navigationIndex=0;
          const animateRecordingRow=function(row){if(!direction||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;row.style.setProperty('--openap-recording-nav-index',String(Math.min(navigationIndex++,10)));row.classList.add(navigationClass);window.setTimeout(function(){row.classList.remove(navigationClass);row.style.removeProperty('--openap-recording-nav-index');},480);};
          if(isNfs&&!recordingState.nfs?.mounted){recordingsBrowserStatus.textContent='[REC_NFS_NOT_MOUNTED] '+recordingsBrowserText.notMounted;recordingsBrowser.setAttribute('aria-busy','false');return;}
          try{
            const response=await fetch('/aptv/api/recording/storage?'+new URLSearchParams({path:destination,action:'files'}).toString(),{credentials:'same-origin',cache:'no-store'});
            if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(recordingsBrowserText.unavailable);
            const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||recordingsBrowserText.unavailable);
            if(generation!==recordingsBrowserGeneration)return;
            recordingsBrowserPath.textContent=data.path||destination;
            recordingsBrowserStatus.textContent=(data.folders.length+data.files.length)+' '+(data.folders.length+data.files.length===1?recordingsBrowserText.fileCount:recordingsBrowserText.filesCount)+(data.truncated?' · '+recordingsBrowserText.truncated:'');
            data.folders.forEach(function(folder){
              const row=document.createElement('div');row.className='openap-aptv-recording-file openap-aptv-recording-folder-row';row.tabIndex=0;
              const icon=document.createElement('i');icon.className='fas fa-folder';icon.setAttribute('aria-hidden','true');
              const copy=document.createElement('div');copy.className='openap-aptv-recording-file-copy';
              const name=document.createElement('strong');name.textContent=folder.name;
              const meta=document.createElement('small');meta.textContent=<?php echo json_encode(_("Open folder")); ?>;
              const deleteFolder=document.createElement('button');deleteFolder.type='button';deleteFolder.className='openap-aptv-recording-folder-delete';deleteFolder.title=<?php echo json_encode(_("Delete folder")); ?>;deleteFolder.setAttribute('aria-label',deleteFolder.title);deleteFolder.innerHTML='<i class="fas fa-trash" aria-hidden="true"></i>';
              copy.append(name,meta);row.append(icon,copy,deleteFolder);animateRecordingRow(row);row.addEventListener('click',function(){openRecordingsBrowser(type,folder.path,'forward');});row.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();openRecordingsBrowser(type,folder.path,'forward');}});deleteFolder.addEventListener('click',function(event){event.stopPropagation();confirmRecordingDeletion(<?php echo json_encode(_("Delete this folder and all recordings inside it?")); ?>,async function(){deleteFolder.disabled=true;try{const response=await fetch('/aptv/api/recording/storage',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'delete_folder',path:folder.path,csrf_token:aptvCsrf})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to delete this folder.")); ?>);openRecordingsBrowser(type,destination,'back');}catch(error){recordingsBrowserStatus.textContent='[REC_DELETE_FOLDER] '+error.message;deleteFolder.disabled=false;}});});recordingsFileList.append(row);
            });
            if(!data.folders.length&&!data.files.length){recordingsFileList.textContent=recordingsBrowserText.empty;return;}
            data.files.forEach(function(file){
              const row=document.createElement('div');row.className='openap-aptv-recording-file';
              if(file.preview){const thumbnail=document.createElement('img');thumbnail.className='openap-aptv-recording-thumbnail';thumbnail.loading='lazy';thumbnail.alt='';thumbnail.src='/aptv/api/recording/storage?'+new URLSearchParams({action:'thumbnail',path:file.path}).toString();row.append(thumbnail);}
              const icon=document.createElement('i');icon.className='fas fa-file-video';icon.setAttribute('aria-hidden','true');
              const copy=document.createElement('div');copy.className='openap-aptv-recording-file-copy';
              const name=document.createElement('strong');name.textContent=file.name;
              const meta=document.createElement('small');meta.textContent=formatRecordingBytes(file.bytes)+' · '+new Date(Number(file.modified)*1000).toLocaleString();
              copy.append(name,meta);row.append(icon,copy);row.tabIndex=0;animateRecordingRow(row);row.addEventListener('click',function(){selectRecordingFile(file,row);});row.addEventListener('keydown',function(event){if(event.key==='Enter'||event.key===' '){event.preventDefault();selectRecordingFile(file,row);}});recordingsFileList.append(row);
            });
          }catch(error){if(generation===recordingsBrowserGeneration){recordingsBrowserStatus.textContent='[REC_FILES] '+error.message;}}
          finally{if(generation===recordingsBrowserGeneration)recordingsBrowser.setAttribute('aria-busy','false');}
        };
        document.querySelectorAll('[data-openap-recording-folder]').forEach(function(folder){folder.addEventListener('click',function(){openRecordingsBrowser(folder.dataset.openapRecordingFolder);});});
        recordingsBrowserBack?.addEventListener('click',function(){const path=recordingsBrowserPath.textContent;const parent=path.split('/').slice(0,-1).join('/')||'/';const type=path.includes('/mnt/aptv-recordings/')?'nfs':'local';const root=type==='nfs'?(recordingState.nfs?.mountpoint||'/mnt/aptv-recordings')+'/APtv Recordings':'/var/lib/openap-recordings';openRecordingsBrowser(type,parent===root?'':parent,'back');});
        recordingsWatch?.addEventListener('click',async function(){
          if(!recordingsSelectedFile)return;recordingsWatch.disabled=true;
          try{
            const response=await fetch('/aptv/api/recording/storage?'+new URLSearchParams({action:'probe',path:recordingsSelectedFile.path}).toString(),{credentials:'same-origin',cache:'no-store'}),data=await response.json();
            if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Recording duration unavailable.")); ?>);
            startRecordingPlayback(Object.assign({},recordingsSelectedFile,{duration:Number(data.duration)||0}));
          }catch(error){recordingsBrowserStatus.textContent=error.message;}
          finally{recordingsWatch.disabled=false;}
        });
        recordingsDelete?.addEventListener('click',function(){if(!recordingsSelectedFile)return;const selectedPath=recordingsSelectedFile.path;confirmRecordingDeletion(<?php echo json_encode(_("Delete this recording and its metadata?")); ?>,async function(){recordingsDelete.disabled=true;try{const response=await fetch('/aptv/api/recording/storage',{method:'POST',credentials:'same-origin',body:new URLSearchParams({action:'delete',path:selectedPath,csrf_token:aptvCsrf})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to delete this recording.")); ?>);const current=recordingsBrowserPath.textContent;const type=current.includes('/mnt/aptv-recordings/')?'nfs':'local';await openRecordingsBrowser(type,current);}catch(error){recordingsBrowserStatus.textContent='[REC_DELETE] '+error.message;}finally{recordingsDelete.disabled=false;}});});
        document.querySelector('[data-openap-recordings-browser-close]')?.addEventListener('click',closeRecordingsBrowser);
        const recordingSeenErrors=new Map();
        const recordingProviderNameFor=function(job){
          const providerId=String(job?.provider_id||'');
          const profile=providerSwitchItems.find(function(item){return String(item?.id||'')===providerId;});
          return String(profile?.name||job?.provider_name||providerId||<?php echo json_encode(_('Unknown provider')); ?>);
        };
        const updateRecordingFolderPaths=function(){
          const destination=String(recordingState.destination||'');
          const nfs=recordingState.nfs||{};
          if(recordingFolderLocal)recordingFolderLocal.textContent=destination&&destination!=='/mnt/aptv-recordings'?destination:'/var/lib/openap-recordings';
          if(recordingFolderNfs)recordingFolderNfs.textContent=nfs.mounted?(nfs.mountpoint||'/mnt/aptv-recordings')+'/APtv Recordings':<?php echo json_encode(_("Not mounted")); ?>;
        };
        const showRecordingError=function(message){recordingSettingsStatus.textContent=message;};
        const recordingCall=async function(values){
          const options={credentials:'same-origin',cache:'no-store'};
          if(values){options.method='POST';options.body=new URLSearchParams(Object.assign({csrf_token:aptvCsrf},values));}
          const response=await fetch('/aptv/api/recording',options);
          if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(<?php echo json_encode(_("Recording service unavailable. Reload the page.")); ?>);
          const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||<?php echo json_encode(_("Unable to update recording.")); ?>);return data;
        };
        const recordingDestinationFor=function(button){const picker=button.nextElementSibling?.classList.contains('openap-recording-destination-picker')?button.nextElementSibling:null;return (picker?.dataset.recordingDestination||'local')==='nfs'?'/mnt/aptv-recordings':'/var/lib/openap-recordings';};
        const activeRecordingStatuses=['scheduled','starting','recording','reconnecting','stopping'];
        const recordingProgrammeJobsFor=function(button){return recordingState.items.filter(function(job){return String(job.provider_id)===String(button.dataset.recordProvider||'')&&String(job.channel_id)===button.dataset.recordChannel&&String(job.start)===button.dataset.recordStart;});};
        const recordingJobFor=function(button){const destination=recordingDestinationFor(button);return recordingProgrammeJobsFor(button).find(function(job){return String(job.destination||'')===destination&&!['stopped','cancelled','completed'].includes(job.status);});};
        const updateRecordingButton=function(button){
          const job=recordingJobFor(button),state=job?.status;
          const previousState=button.dataset.recordingVisualState||'';
          const scheduleTransition=state==='scheduled'&&button.dataset.recordingSchedulePending==='true';
          const liveTransition=['starting','recording'].includes(state)&&previousState&&previousState!==state;
          const recordReturnTransition=previousState==='stopping'&&!['scheduled','starting','recording','reconnecting','stopping'].includes(state);
          if(scheduleTransition)delete button.dataset.recordingSchedulePending;
          const active=['scheduled','starting','recording','reconnecting','stopping'].includes(state);
          const past=Number(button.dataset.recordEnd)<=Date.now()/1000;
          const buttonState=['stopped','completed','cancelled'].includes(state)?null:state;
          const label=buttonState?recordingLabels[buttonState]:recordingLabels.record;
          const icon=button.querySelector('i');
          if(icon){
            icon.className=['starting','stopping'].includes(state)?'fas fa-circle-notch fa-spin':state==='scheduled'?'fas fa-pen-to-square':'fas fa-circle';
            icon.setAttribute('aria-hidden','true');
          }
          button.querySelector('span').textContent=label;
          button.classList.toggle('is-recording',state==='recording');button.classList.toggle('is-starting',state==='starting');button.classList.toggle('is-recording-failed',['failed','missed','interrupted'].includes(state));button.classList.toggle('is-scheduled',state==='scheduled');button.classList.toggle('is-stopping',state==='stopping');button.dataset.recordingVisualState=state||'';
          const otherActive=recordingProgrammeJobsFor(button).some(function(item){return activeRecordingStatuses.includes(item.status)&&String(item.destination||'')!==recordingDestinationFor(button);});
          button.disabled=recordingBusy||state==='stopping'||state==='completed'||(!active&&past)||otherActive;
          button.title=otherActive?<?php echo json_encode(_("This programme is already being recorded to another destination.")); ?>:state==='recording'||state==='starting'||state==='reconnecting'?<?php echo json_encode(_("Stop recording")); ?>:state==='scheduled'?<?php echo json_encode(_("Cancel scheduled recording")); ?>:job?.error?job.error:past?<?php echo json_encode(_("This programme has ended.")); ?>:label;
          button.setAttribute('aria-label',label+': '+button.dataset.recordTitle);
          if(scheduleTransition){button.classList.remove('is-scheduled-enter');void button.offsetWidth;button.classList.add('is-scheduled-enter');window.setTimeout(function(){button.classList.remove('is-scheduled-enter');},700);}
          if(liveTransition){const transitionClass=state==='starting'?'is-starting-enter':'is-recording-enter';button.classList.remove('is-starting-enter','is-recording-enter');void button.offsetWidth;button.classList.add(transitionClass);window.setTimeout(function(){button.classList.remove(transitionClass);},700);}
          if(recordReturnTransition){button.classList.remove('is-record-return-enter');void button.offsetWidth;button.classList.add('is-record-return-enter');window.setTimeout(function(){button.classList.remove('is-record-return-enter');},760);}
        };
        const renderRecordingSchedule=function(){
          if(!recordingScheduleList)return;
          const visibleStatuses=['scheduled','starting','recording','reconnecting','stopping'];
          recordingScheduleStoppingIds.forEach(function(id){const current=recordingState.items.find(function(job){return String(job.id)===String(id);});if(!current||!visibleStatuses.includes(current.status))recordingScheduleStoppingIds.delete(id);});
          const scheduledJobs=recordingState.items.filter(function(job){return visibleStatuses.includes(job.status);}).sort(function(a,b){return (Number(a.start)||0)-(Number(b.start)||0);});
          const rows=new Map();
          recordingScheduleList.querySelectorAll('[data-recording-schedule-id]').forEach(function(row){rows.set(String(row.dataset.recordingScheduleId),row);});
          const empty=recordingScheduleList.querySelector('.openap-aptv-recording-schedule-empty');
          if(!scheduledJobs.length){
            if(!empty){const message=document.createElement('p');message.className='openap-aptv-recording-schedule-empty';message.textContent=<?php echo json_encode(_("No scheduled or active recordings.")); ?>;recordingScheduleList.append(message);}else empty.hidden=false;
            rows.forEach(function(row){row.remove();});
            return;
          }
          if(empty)empty.remove();
          const used=new Set();
          scheduledJobs.forEach(function(job){
            const key=String(job.id),previous=rows.get(key),previousStatus=previous?.dataset.recordingScheduleStatus||'';
            const row=previous||document.createElement('article');
            if(!previous){
              row.className='openap-aptv-recording-schedule-item';row.dataset.recordingScheduleId=key;row.setAttribute('role','listitem');
              const icon=document.createElement('i');icon.setAttribute('aria-hidden','true');
              const copy=document.createElement('div');copy.className='openap-aptv-recording-schedule-copy';
              const title=document.createElement('strong');const detail=document.createElement('small');const error=document.createElement('small');error.className='openap-aptv-recording-schedule-error';
              copy.append(title,detail,error);
              const provider=document.createElement('span');provider.className='openap-aptv-recording-schedule-provider';
              const action=document.createElement('button');action.type='button';action.className='openap-aptv-epg-record';
              const actionIcon=document.createElement('i');actionIcon.setAttribute('aria-hidden','true');const actionLabel=document.createElement('span');action.append(actionIcon,actionLabel);
              action.addEventListener('click',function(){if(row._recordingJob)stopRecording(row._recordingJob);});
              const destination=document.createElement('span');destination.className='openap-aptv-recording-schedule-destination';destination.setAttribute('aria-hidden','true');const destinationIcon=document.createElement('i');destination.append(destinationIcon);
              row.append(icon,copy,provider,destination,action);row._recordingIcon=icon;row._recordingTitle=title;row._recordingDetail=detail;row._recordingError=error;row._recordingProvider=provider;row._recordingDestination=destination;row._recordingDestinationIcon=destinationIcon;row._recordingAction=action;row._recordingActionIcon=actionIcon;row._recordingActionLabel=actionLabel;
            }
            const actualState=job.status,optimisticStopping=recordingScheduleStoppingIds.has(String(job.id))&&['starting','recording','reconnecting','stopping'].includes(actualState),state=optimisticStopping?'stopping':actualState,iconClass=state==='scheduled'?'fas fa-pen-to-square':['starting','stopping'].includes(state)?'fas fa-circle-notch fa-spin':state==='recording'?'fas fa-circle':'fas fa-arrows-rotate fa-spin';
            const isNfs=String(job.destination||'')==='/mnt/aptv-recordings',destinationLabel=isNfs?<?php echo json_encode(_("NFS partition")); ?>:<?php echo json_encode(_("Local")); ?>,providerName=recordingProviderNameFor(job);row._recordingJob=job;row.dataset.recordingScheduleStatus=state;row.classList.remove('is-scheduled','is-starting','is-recording','is-stopping');row.classList.add('is-'+state);if(row._recordingIcon.className!==iconClass)row._recordingIcon.className=iconClass;row._recordingTitle.textContent=(job.channel_name||'')+' — '+(job.title||'');row._recordingDetail.textContent=new Date(Number(job.start)*1000).toLocaleString();row._recordingError.textContent=job.error||'';row._recordingError.hidden=!job.error;row._recordingProvider.textContent=providerName;row._recordingProvider.title=providerName;row._recordingProvider.setAttribute('aria-label',<?php echo json_encode(_("Provider")); ?>+': '+providerName);if(row._recordingDestinationIcon.className!==(isNfs?'fas fa-network-wired':'fas fa-hdd'))row._recordingDestinationIcon.className=isNfs?'fas fa-network-wired':'fas fa-hdd';row._recordingDestination.title=destinationLabel;row._recordingDestination.setAttribute('aria-label',destinationLabel);
            const action=row._recordingAction,actionLabel=recordingLabels[state]||state,previousActionState=action.dataset.recordingScheduleState||'';if(previousActionState!==state){action.className='openap-aptv-epg-record is-'+state;row._recordingActionIcon.className=iconClass;}action.dataset.recordingScheduleState=state;action.disabled=recordingBusy||state==='stopping';action.title=state==='scheduled'?<?php echo json_encode(_("Cancel scheduled recording")); ?>:state==='stopping'?recordingLabels.stopping:<?php echo json_encode(_("Stop recording")); ?>;action.setAttribute('aria-label',actionLabel+': '+(job.title||''));row._recordingActionLabel.textContent=actionLabel;
            if(!previous||previousStatus!==state){action.classList.remove('is-scheduled-enter','is-starting-enter','is-recording-enter');void action.offsetWidth;if(state==='scheduled')action.classList.add('is-scheduled-enter');else if(state==='starting')action.classList.add('is-starting-enter');else if(state==='recording')action.classList.add('is-recording-enter');window.setTimeout(function(){action.classList.remove('is-scheduled-enter','is-starting-enter','is-recording-enter');},760);}
            recordingScheduleList.append(row);used.add(key);
          });
          rows.forEach(function(row,key){if(!used.has(key))row.remove();});
        };
        const setRecordingScheduleStopping=function(job,stopping){
          if(!recordingScheduleList||!job)return;
          if(stopping)recordingScheduleStoppingIds.add(String(job.id));else recordingScheduleStoppingIds.delete(String(job.id));
          renderRecordingSchedule();
        };
        const updateRecordingUi=function(){
          const newErrors=[];
          recordingState.items.forEach(function(job){
            if(job.error&&recordingSeenErrors.get(job.id)!==job.error){
              recordingSeenErrors.set(job.id,job.error);
              newErrors.push('APtv · Registrazione #'+job.id+' · '+job.channel_name+' · '+job.title+'\n'+job.error);
            }
          });
          if(newErrors.length)showRecordingError(newErrors.join('\n\n'));
          if(!recordingState.service_online&&recordingState.items.some(function(job){return ['scheduled','starting','recording','reconnecting'].includes(job.status);})){
            if(!recordingSeenErrors.has('offline')){recordingSeenErrors.set('offline',true);showRecordingError('[REC_SERVICE_OFFLINE] Il servizio di registrazione non risponde.');}
          }else recordingSeenErrors.delete('offline');
          document.querySelectorAll('[data-record-channel]').forEach(updateRecordingButton);
          updateRecordingNfsUi();
          updateRecordingFolderPaths();
          updateRecordingDestinationPickers();
          renderRecordingSchedule();
          if(recordingJobsList&&!recordingJobsList.contains(document.activeElement)){
            recordingJobsList.replaceChildren();
            recordingState.items.slice(0,50).forEach(function(job){
              const row=document.createElement('div');row.className='openap-recording-job';
              const title=document.createElement('strong');title.textContent=job.channel_name+' — '+job.title;
              const detail=document.createElement('small');detail.textContent=new Date(job.start*1000).toLocaleString()+' · '+(recordingLabels[job.status]||job.status);
              row.append(title,detail);
              if(job.file&&job.bytes>0){const file=document.createElement('small');file.textContent=job.file+' · '+(job.bytes/1048576).toFixed(1)+' MiB';row.append(file);}
              if(job.error){const error=document.createElement('small');error.textContent=job.error;row.append(error);}
              if(['scheduled','starting','recording','reconnecting'].includes(job.status)){const stop=document.createElement('button');stop.type='button';stop.className='btn btn-sm btn-outline-info';stop.textContent=job.status==='scheduled'?<?php echo json_encode(_("Cancel recording")); ?>:<?php echo json_encode(_("Stop recording")); ?>;stop.disabled=recordingBusy;stop.addEventListener('click',function(){stopRecording(job);});row.append(stop);}
              recordingJobsList.append(row);
            });
            if(!recordingState.items.length)recordingJobsList.textContent=<?php echo json_encode(_("No recordings yet.")); ?>;
          }
        };
        let recordingRefreshPromise=null;
        const refreshRecordings=async function(){
          if(recordingRefreshPromise)return recordingRefreshPromise;
          if(recordingPollBusy||recordingBusy||window.openapSession.expired)return;
          recordingRefreshPromise=(async function(){
            recordingPollBusy=true;const revision=recordingRevision;
            try{const data=await recordingCall();if(!recordingBusy&&revision===recordingRevision){recordingState=data;recordingSeenErrors.delete('api');updateRecordingUi();}}
            catch(error){recordingSettingsStatus.textContent=error.message;if(!recordingSeenErrors.has('api')){recordingSeenErrors.set('api',true);showRecordingError('[REC_API] '+error.message);}}
            finally{recordingPollBusy=false;}
          })();
          try{return await recordingRefreshPromise;}finally{recordingRefreshPromise=null;}
        };
        const mutateRecording=async function(values){
          if(recordingBusy)return;recordingBusy=true;recordingRevision++;updateRecordingUi();
          try{recordingState=await recordingCall(values);}
          finally{recordingBusy=false;updateRecordingUi();}
        };
        const waitForRecordingStop=async function(id){
          const deadline=Date.now()+15000;
          recordingBusy=true;updateRecordingUi();
          try{
            while(Date.now()<deadline){
              const data=await recordingCall();recordingState=data;updateRecordingUi();
              const job=recordingState.items.find(function(item){return Number(item.id)===Number(id);});
              if(!job||!['scheduled','starting','recording','reconnecting','stopping'].includes(job.status))return job?.status||'stopped';
              await new Promise(function(resolve){window.setTimeout(resolve,500);});
            }
            showRecordingError('[REC_STOP_WAIT] Lo stop è ancora in chiusura; attendere prima di cambiare destinazione.');
            return 'stopping';
          }finally{recordingBusy=false;updateRecordingUi();}
        };
        const stopRecording=async function(job,confirmed=false){
          if(!confirmed&&typeof window.openapNotify==='function'){
            const scheduled=job.status==='scheduled';
            window.openapNotify({level:'success',duration:0,icon:scheduled?'fa-calendar-xmark':'fa-stop-circle',title:scheduled?<?php echo json_encode(_('Cancel scheduled recording?')); ?>:<?php echo json_encode(_('Stop recording now?')); ?>,message:scheduled?<?php echo json_encode(_('Do you want to cancel this scheduled recording?')); ?>:<?php echo json_encode(_('The active recording will be stopped immediately.')); ?>,actions:[{label:scheduled?<?php echo json_encode(_('Cancel scheduled')); ?>:<?php echo json_encode(_('Stop now')); ?>,icon:'fa-check',onClick:function(){stopRecording(job,true);}},{label:scheduled?<?php echo json_encode(_('Keep scheduled')); ?>:<?php echo json_encode(_('Keep recording')); ?>,icon:'fa-times',onClick:function(){}}]});
            return;
          }
          if(!confirmed&&!window.confirm(job.status==='scheduled'?<?php echo json_encode(_("Cancel this scheduled recording?")); ?>:<?php echo json_encode(_("Stop this recording now?")); ?>))return;
          const activeStop=job.status!=='scheduled';
          if(activeStop)setRecordingScheduleStopping(job,true);
          try{await mutateRecording({action:'stop',id:job.id});if(activeStop)await waitForRecordingStop(job.id);}catch(error){if(activeStop)setRecordingScheduleStopping(job,false);showRecordingError('[REC_STOP] '+error.message);window.alert(error.message);}
        };
        const createRecordingDestinationPicker=function(){const picker=document.createElement('span');picker.className='openap-recording-destination-picker';picker.dataset.recordingDestination='local';picker.setAttribute('role','group');picker.setAttribute('aria-label',<?php echo json_encode(_("Recording destination")); ?>);const destinationGroup=document.createElement('span');destinationGroup.className='openap-recording-destination-group';const destinationLabel=document.createElement('span');destinationLabel.className='openap-recording-destination-label';destinationLabel.textContent=<?php echo json_encode(_('Destination')); ?>;const destinationButtons=document.createElement('span');destinationButtons.className='openap-recording-destination-buttons';destinationGroup.append(destinationLabel,destinationButtons);['local','nfs'].forEach(function(type){const option=document.createElement('button');option.type='button';option.className='openap-recording-destination';option.dataset.recordingDestinationOption=type;option.title=type==='local'?<?php echo json_encode(_("Save locally")); ?>:<?php echo json_encode(_("Save to NFS")); ?>;option.setAttribute('aria-label',option.title);option.innerHTML='<i class="fas '+(type==='local'?'fa-hdd':'fa-network-wired')+'" aria-hidden="true"></i>';option.addEventListener('click',function(event){event.stopPropagation();picker.dataset.recordingDestination=type;updateRecordingDestinationPickers();const record=picker.previousElementSibling;if(record?.matches('.openap-aptv-epg-record,.openap-aptv-player-epg-item>button,.openap-recording-actions>button'))updateRecordingButton(record);});destinationButtons.append(option);});const closeGroup=document.createElement('span');closeGroup.className='openap-recording-close-group';const closeLabel=document.createElement('span');closeLabel.className='openap-recording-close-label';closeLabel.textContent=<?php echo json_encode(_('Close')); ?>;const close=document.createElement('button');close.type='button';close.className='openap-recording-destination-close';close.setAttribute('aria-label',<?php echo json_encode(_('Close recording destinations')); ?>);close.title=<?php echo json_encode(_('Close')); ?>;close.innerHTML='<i class="fas fa-times" aria-hidden="true"></i>';close.addEventListener('click',function(event){event.stopPropagation();picker.classList.remove('is-open');picker.setAttribute('aria-hidden','true');const record=picker.previousElementSibling;if(record)record.setAttribute('aria-expanded','false');});closeGroup.append(closeLabel,close);picker.append(destinationGroup,closeGroup);return picker;};
        const updateRecordingDestinationPickers=function(){const configured=Boolean(recordingState.nfs_server&&recordingState.nfs_export);document.querySelectorAll('.openap-recording-destination-picker').forEach(function(picker){const button=picker.previousElementSibling?.matches('.openap-aptv-epg-record,.openap-aptv-player-epg-item>button,.openap-recording-actions>button')?picker.previousElementSibling:null;const activeJob=button?recordingProgrammeJobsFor(button).find(function(job){return activeRecordingStatuses.includes(job.status);}):null;const lockedType=activeJob?(String(activeJob.destination||'')==='/mnt/aptv-recordings'?'nfs':'local'):'';const selected=lockedType||(picker.dataset.recordingDestination==='nfs'&&configured?'nfs':'local');picker.dataset.recordingDestination=selected;const nfs=picker.querySelector('[data-recording-destination-option="nfs"]');if(nfs){nfs.hidden=!configured;nfs.disabled=!recordingState.nfs?.mounted||lockedType==='local';nfs.title=lockedType==='local'?<?php echo json_encode(_("A local recording is already active for this programme.")); ?>:recordingState.nfs?.mounted?<?php echo json_encode(_("Save to NFS")); ?>:<?php echo json_encode(_("Mount NFS storage first")); ?>;}const local=picker.querySelector('[data-recording-destination-option="local"]');if(local){local.disabled=lockedType==='nfs';local.title=lockedType==='nfs'?<?php echo json_encode(_("A remote recording is already active for this programme.")); ?>:<?php echo json_encode(_("Save locally")); ?>;}picker.querySelectorAll('[data-recording-destination-option]').forEach(function(option){option.classList.toggle('is-selected',option.dataset.recordingDestinationOption===selected);option.setAttribute('aria-pressed',option.dataset.recordingDestinationOption===selected?'true':'false');});});};
        const bindRecordingButton=function(button,channel,program,picker){
          const channelProviderId=/^[a-f0-9]{32}$/.test(String(channel?.provider_id||''))?String(channel.provider_id):selectedProviderId();
          button.dataset.recordProvider=channelProviderId;button.dataset.recordChannel=String(channel?.item_id||'');button.dataset.recordStart=String(program.start);button.dataset.recordEnd=String(program.end);button.dataset.recordTitle=program.title||'';
          button.addEventListener('click',async function(event){
            event.stopPropagation();const destinationPicker=picker||button.nextElementSibling?.classList.contains('openap-recording-destination-picker')&&button.nextElementSibling;const job=recordingJobFor(button);
            if(destinationPicker&&!destinationPicker.classList.contains('is-open')&&!job){destinationPicker.classList.add('is-open');destinationPicker.setAttribute('aria-hidden','false');button.setAttribute('aria-expanded','true');return;}
            if(job?.status==='scheduled'){await stopRecording(job);return;}
            if(job&&['starting','recording','reconnecting'].includes(job.status)){await stopRecording(job);return;}
            if(job?.error&&['failed','interrupted','missed'].includes(job.status)&&!window.confirm(job.error+'\n\n'+<?php echo json_encode(_("Retry recording this programme?")); ?>))return;
            try{const destination=destinationPicker?.dataset.recordingDestination||'local';button.dataset.recordingSchedulePending='true';await mutateRecording({action:'record',destination:destination,provider_id:button.dataset.recordProvider,channel_id:button.dataset.recordChannel,start:button.dataset.recordStart,end:button.dataset.recordEnd});if(destinationPicker){destinationPicker.classList.remove('is-open');destinationPicker.setAttribute('aria-hidden','true');button.setAttribute('aria-expanded','false');}}
            catch(error){delete button.dataset.recordingSchedulePending;showRecordingError('[REC_ACTION] '+error.message);window.alert(error.message);}
          });updateRecordingButton(button);updateRecordingDestinationPickers();
        };
        const enhanceRecordingPickers=function(root){if(!root)return;root.querySelectorAll('.openap-aptv-epg-record,.openap-aptv-player-epg-item>button,.openap-recording-actions>button').forEach(function(button){if(button.nextElementSibling?.classList.contains('openap-recording-destination-picker'))return;const picker=createRecordingDestinationPicker();button.parentNode.insertBefore(picker,button.nextSibling);});updateRecordingDestinationPickers();};
        if(playerEpgList)new MutationObserver(function(){enhanceRecordingPickers(playerEpgList);}).observe(playerEpgList,{childList:true,subtree:true});
        if(epgGrid)new MutationObserver(function(){enhanceRecordingPickers(epgGrid);}).observe(epgGrid,{childList:true,subtree:true});
        const recordingModal=document.getElementById('openapRecordingModal');
        const recordingNfsMountButton=document.querySelector('[data-recording-nfs-mount]');
        const recordingSaveButton=document.querySelector('[data-recording-save]');
        const setRecordingActionBusy=function(button,busy){if(!button)return;button.classList.toggle('is-busy',busy);button.setAttribute('aria-busy',busy?'true':'false');const spinner=button.querySelector('[data-recording-action-spinner]');if(spinner)spinner.hidden=!busy;};
        const recordingNfsDetails=function(operation,error,fields){const details=['Operation: '+operation];if(fields?.server)details.push('NFS server: '+fields.server);if(fields?.exportPath)details.push('Exported folder: '+fields.exportPath);details.push('Mount point: /mnt/aptv-recordings');if(error)details.push('Cause reported by OpenAP: '+String(error?.message||error||'Unknown NFS error'));details.push('Diagnostic source: NFS helper and system mount logs');return details;};
        const recordingNfsFields=function(){return {server:document.getElementById('aptvRecordingNfsServer')?.value.trim()||'',exportPath:document.getElementById('aptvRecordingNfsPath')?.value.trim()||''};};
        const recordingNfsSettingsSaved=function(){const fields=recordingNfsFields();return Boolean(fields.server&&fields.exportPath&&fields.server===String(recordingState.nfs_server||'')&&fields.exportPath===String(recordingState.nfs_export||''));};
        const updateRecordingNfsUi=function(){
          const nfs=recordingState.nfs||{};
          if(recordingNfsStatus)recordingNfsStatus.textContent=nfs.mounted?<?php echo json_encode(_("NFS mounted at /mnt/aptv-recordings.")); ?>:<?php echo json_encode(_("NFS is not mounted.")); ?>;
          const saved=recordingNfsSettingsSaved();
          const mount=document.querySelector('[data-recording-nfs-mount]');
          if(mount){mount.disabled=recordingBusy||(!nfs.mounted&&!saved);mount.querySelector('span').textContent=nfs.mounted?<?php echo json_encode(_("Unmount NFS storage")); ?>:<?php echo json_encode(_("Mount NFS storage")); ?>;}
          if(recordingSaveButton)recordingSaveButton.disabled=recordingBusy;
        };
        recordingNfsMountButton?.addEventListener('click',async function(){
          const button=this,nfs=recordingState.nfs||{};
          if(nfs.mounted){
            const confirmMessage=<?php echo json_encode(_("Unmount the NFS recording storage? Scheduled recordings must be cancelled first.")); ?>;
            const unmount=async function(){setRecordingActionBusy(button,true);try{await mutateRecording({action:'nfs_unmount'});const message=<?php echo json_encode(_("NFS storage unmounted.")); ?>;recordingSettingsStatus.textContent=message;window.openapNotify?.({level:'success',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS unmounted')); ?>,message:message,details:recordingNfsDetails('NFS unmount')});}catch(error){recordingSettingsStatus.textContent=error.message;window.openapNotify?.({level:'error',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS unmount failed')); ?>,message:error.message,duration:0,details:recordingNfsDetails('NFS unmount',error)});}finally{setRecordingActionBusy(button,false);}};
            if(typeof window.openapNotify==='function'){window.openapNotify({level:'warning',duration:0,icon:'fa-network-wired',title:<?php echo json_encode(_('Confirm NFS unmount')); ?>,message:confirmMessage,actions:[{label:<?php echo json_encode(_('Unmount')); ?>,icon:'fa-check',onClick:unmount},{label:<?php echo json_encode(_('Cancel')); ?>,icon:'fa-times',onClick:function(){}}]});return;}
            if(window.confirm(confirmMessage))await unmount();
            return;
          }
          const fields=recordingNfsFields();
          if(!recordingNfsSettingsSaved()){const message=<?php echo json_encode(_("Save the NFS settings before mounting.")); ?>;recordingSettingsStatus.textContent=message;window.openapNotify?.({level:'error',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS mount unavailable')); ?>,message:message,duration:0,details:recordingNfsDetails('NFS mount',new Error(message),fields)});return;}
          setRecordingActionBusy(button,true);try{await mutateRecording({action:'nfs_mount',server:fields.server,export:fields.exportPath});const message=<?php echo json_encode(_("NFS storage mounted and selected for recordings.")); ?>;recordingSettingsStatus.textContent=message;window.openapNotify?.({level:'success',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS mounted')); ?>,message:message,details:recordingNfsDetails('NFS mount',null,fields)});}catch(error){recordingSettingsStatus.textContent=error.message;window.openapNotify?.({level:'error',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS mount failed')); ?>,message:error.message,duration:0,details:recordingNfsDetails('NFS mount',error,fields)});}finally{setRecordingActionBusy(button,false);}
        });
        document.querySelector('[data-openap-recording-menu]')?.addEventListener('click',function(){
          setSettingsOpen(false);
          bootstrap.Modal.getOrCreateInstance(recordingModal).show();
        });
        recordingModal?.addEventListener('show.bs.modal',async function(){
          await refreshRecordings();
          updateRecordingNfsUi();
          if(recordingState.nfs_server)document.getElementById('aptvRecordingNfsServer').value=recordingState.nfs_server;
          if(recordingState.nfs_export)document.getElementById('aptvRecordingNfsPath').value=recordingState.nfs_export;
          updateRecordingNfsUi();
          recordingSettingsStatus.textContent=recordingState.service_online?'':<?php echo json_encode(_("Recording service is offline. Scheduled programmes will start when it returns.")); ?>;
        });
        document.querySelector('[data-recording-save]')?.addEventListener('click',async function(event){
          const button=event.currentTarget;button.disabled=true;
          const fields=recordingNfsFields();
          try{await mutateRecording({action:'settings',type:'nfs',server:fields.server,export:fields.exportPath});const message=<?php echo json_encode(_("NFS settings saved.")); ?>;recordingSettingsStatus.textContent=message;window.openapNotify?.({level:'success',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS settings saved')); ?>,message:message,details:recordingNfsDetails('NFS settings save',null,fields)});}
          catch(error){recordingSettingsStatus.textContent=error.message;window.openapNotify?.({level:'error',icon:'fa-network-wired',title:<?php echo json_encode(_('NFS settings save failed')); ?>,message:error.message,duration:0,details:recordingNfsDetails('NFS settings save',error,fields)});}
          finally{button.disabled=false;}
        });
        window.setInterval(function(){if(!document.hidden)refreshRecordings();},4000);
        document.addEventListener('visibilitychange',function(){if(!document.hidden)refreshRecordings();});
        refreshRecordings();
        recordingModal?.addEventListener('hidden.bs.modal',function(){
          document.querySelector('[data-openap-iptv-settings-toggle]')?.focus();
        });
        const storageBrowser=document.querySelector('[data-recording-browser]');
        const storageFolders=document.querySelector('[data-recording-folders]');
        const storageStatus=document.querySelector('[data-recording-browser-status]');
        const storageSelectionStatus=document.querySelector('[data-recording-selection-status]');
        const storagePath=document.getElementById('aptvRecordingLocalPath');
        const storageSelect=document.querySelector('[data-recording-select]');
        const storageUp=document.querySelector('[data-recording-up]');
        const storageNew=document.querySelector('[data-recording-new-folder]');
        let storageLocation=null,storageBusy=false,storageGeneration=0;
        const storageText={loading:<?php echo json_encode(_("Loading folders…")); ?>,empty:<?php echo json_encode(_("No subfolders.")); ?>,locations:<?php echo json_encode(_("Storage locations")); ?>,readonly:<?php echo json_encode(_("Read-only for APtv")); ?>,writable:<?php echo json_encode(_("Writable by APtv")); ?>,free:<?php echo json_encode(_("free")); ?>,verified:<?php echo json_encode(_("Folder selected and write check passed. Destination is not saved yet.")); ?>,error:<?php echo json_encode(_("Unable to access storage. Please try again.")); ?>,limit:<?php echo json_encode(_("Showing the first 500 folders. Enter a full path to browse another folder.")); ?>};
        const storageSpace=function(bytes){return typeof bytes==='number'?new Intl.NumberFormat(undefined,{maximumFractionDigits:1}).format(bytes/1073741824)+' GiB '+storageText.free:'';};
        const storageRequest=async function(path,action,name){
          const params=new URLSearchParams({path:path});
          const options={credentials:'same-origin',cache:'no-store'};
          let url='/aptv/api/recording/storage';
          if(action){params.set('action',action);params.set('csrf_token',aptvCsrf);if(name!==undefined)params.set('name',name);options.method='POST';options.body=params;}
          else url+='?'+params.toString();
          const response=await fetch(url,options);
          if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(storageText.error);
          const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||storageText.error);return data;
        };
        const storageSetBusy=function(busy){storageBusy=busy;storageBrowser.setAttribute('aria-busy',String(busy));storageBrowser.querySelectorAll('button,input').forEach(function(node){node.disabled=busy;});storageSelect.disabled=busy||!storageLocation?.path||!storageLocation?.writable;storageUp.disabled=busy||!storageLocation?.path;storageNew.querySelectorAll('input,button').forEach(function(node){node.disabled=busy||!storageLocation?.path||!storageLocation?.writable;});};
        const browseStorage=async function(path,action,name){
          if(storageBusy)return;
          const generation=++storageGeneration;storageSetBusy(true);storageStatus.textContent=storageText.loading;
          try{
            const data=await storageRequest(path,action,name);if(generation!==storageGeneration)return;
            storageLocation=data;document.querySelector('[data-recording-current]').textContent=data.path||storageText.locations;storageFolders.replaceChildren();
            data.items.forEach(function(item){const button=document.createElement('button');button.type='button';button.className='openap-recording-folder';const icon=document.createElement('i');icon.className='fas fa-folder';icon.setAttribute('aria-hidden','true');const label=document.createElement('span');label.textContent=item.name;button.append(icon,label);button.addEventListener('click',function(){browseStorage(item.path);});storageFolders.append(button);});
            storageStatus.textContent=[data.path?(data.writable?storageText.writable:storageText.readonly):'',storageSpace(data.free_bytes),data.items.length?'':storageText.empty,data.truncated?storageText.limit:''].filter(Boolean).join(' · ');
            if(action==='mkdir')storageNew.reset();
          }catch(error){if(generation===storageGeneration){storageLocation=null;storageFolders.replaceChildren();document.querySelector('[data-recording-current]').textContent=path||storageText.locations;storageStatus.textContent=error.message;}}
          finally{if(generation===storageGeneration)storageSetBusy(false);}
        };
        document.querySelector('[data-recording-browse]')?.addEventListener('click',function(){storageBrowser.hidden=false;browseStorage(storagePath.value.trim());});
        document.querySelector('[data-recording-roots]')?.addEventListener('click',function(){browseStorage('');});
        storageUp?.addEventListener('click',function(){browseStorage(storageLocation?.parent||'');});
        document.querySelector('[data-recording-cancel]')?.addEventListener('click',function(){storageBrowser.hidden=true;document.querySelector('[data-recording-browse]').focus();});
        storageNew?.addEventListener('submit',function(event){event.preventDefault();if(storageLocation?.path)browseStorage(storageLocation.path,'mkdir',document.querySelector('[data-recording-folder-name]').value);});
        storageSelect?.addEventListener('click',async function(){
          if(storageBusy||!storageLocation?.path)return;storageSetBusy(true);
          try{const data=await storageRequest(storageLocation.path,'check');storagePath.value=data.path;storageSelectionStatus.textContent=storageText.verified+' '+storageSpace(data.free_bytes);storageBrowser.hidden=true;document.querySelector('[data-recording-browse]').focus();}
          catch(error){storageStatus.textContent=error.message;}
          finally{storageSetBusy(false);}
        });
        storagePath?.addEventListener('input',function(){storageSelectionStatus.textContent='';});
        providerMenu?.addEventListener('click', function () { showProviderPanel(true); });
        providerBack?.addEventListener('click', function () { showProviderPanel(false); });
        profileMenu?.addEventListener('click', function () { setSettingsOpen(false); bootstrap.Modal.getOrCreateInstance(document.getElementById('openapIptvProfileModal')).show(); });
        profileBack?.addEventListener('click', function () { showProfilePanel(false); });
        const normalizeMenuProfileFavorites=function(items){const seen=new Set();return (Array.isArray(items)?items:[]).filter(function(item){const id=String(item?.item_id||'');if(!/^\d{1,20}$/.test(id)||seen.has(id))return false;seen.add(id);return true;}).map(function(item){return {item_id:String(item.item_id),name:String(item.name||item.label||''),label:String(item.label||item.name||''),country_code:String(item.country_code||''),icon_url:String(item.icon_url||'')};});};
        const menuProfileRequest=async function(options){const response=await fetch('/aptv/api/catalog/profile',Object.assign({credentials:'same-origin',cache:'no-store'},options||{}));const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_('Unable to process the APtv backup.')); ?>);return payload;};
        const indexImportedProvider=async function(profile){const provider=profile?.provider||{},profiles=Array.isArray(provider.profiles)?provider.profiles:[],activeId=String(provider.active_id||''),selected=profiles.find(function(item){return item&&String(item.id||'')===activeId;})||profiles[0];if(!selected||!selected.url||!selected.username||!selected.password)throw new Error(<?php echo json_encode(_('The backup does not contain a complete provider profile.')); ?>);const body=new URLSearchParams({csrf_token:aptvCsrf,provider_name:String(selected.name||'APtv provider'),provider_id:String(selected.id||''),url:String(selected.url||''),username:String(selected.username||''),password:String(selected.password||''),api_version:String(selected.api_version||'v2')});const response=await fetch('/aptv/api/provider/index',{method:'POST',body:body,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_('Unable to load the provider playlist from the backup.')); ?>);return payload;};
        const notifyProfile=function(level,title,message,details){window.openapNotify?.({level:level,icon:level==='error'?'fa-exclamation-triangle':'fa-file-import',title:title,message:message,duration:level==='error'?0:5000,details:details});};
        profileExportButton?.addEventListener('click',async function(){
          profileExportButton.disabled=true;if(profileStatus)profileStatus.textContent=<?php echo json_encode(_('Preparing backup…')); ?>;
          try{const providerId=String(profileExportScope?.value||''),providerName=providerId?String(profileExportScope?.selectedOptions?.[0]?.textContent||'provider').trim():'all-providers',payload=await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'export',csrf_token:aptvCsrf,provider_id:providerId})});const profile=payload.profile||{};profile.favorites=normalizeMenuProfileFavorites(readLiveFavorites());const safeName=providerName.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'')||'provider',blob=new Blob([JSON.stringify(profile,null,2)+'\n'],{type:'application/json'}),suggestedName='aptv-backup-'+safeName+'-'+new Date().toISOString().slice(0,10)+'.json';if(typeof window.showSaveFilePicker==='function'){const handle=await window.showSaveFilePicker({suggestedName:suggestedName,types:[{description:'APtv backup',accept:{'application/json':['.json']}}]});const writable=await handle.createWritable();await writable.write(blob);await writable.close();}else{const url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=suggestedName;document.body.append(link);link.click();link.remove();window.setTimeout(function(){URL.revokeObjectURL(url);},1000);}if(profileStatus)profileStatus.textContent=<?php echo json_encode(_('Backup created successfully.')); ?>;}catch(error){if(error?.name!=='AbortError'&&profileStatus)profileStatus.textContent=error.message;}finally{profileExportButton.disabled=false;}
        });
        profileChooseButton?.addEventListener('click',function(){profileImportInput?.click();});
        profileImportInput?.addEventListener('change',async function(){
          const file=profileImportInput.files?.[0];selectedMenuProfile=null;if(profileImportButton)profileImportButton.disabled=true;if(!file){if(profileFileName)profileFileName.textContent=<?php echo json_encode(_('Choose backup to restore')); ?>;return;}
          if(file.size>5242880){const message=<?php echo json_encode(_('The backup file is too large.')); ?>;if(profileStatus)profileStatus.textContent=message;if(profileFileName)profileFileName.textContent=file.name;notifyProfile('error',<?php echo json_encode(_('Backup import failed')); ?>,message,['Operation: backup import','File: '+file.name,'File size: '+file.size+' bytes','Maximum allowed: 5242880 bytes']);return;}
          if(profileFileName)profileFileName.textContent=file.name;
          try{const parsed=JSON.parse(await file.text()),profile=parsed?.profile||parsed,version=Number(profile?.version||0);if(profile?.format!=='openap-aptv-menu-profile'||![1,2].includes(version)||!Array.isArray(profile?.orders)||!Array.isArray(profile?.favorites)|| (version>=2&&!Array.isArray(profile?.provider_orders)))throw new Error(<?php echo json_encode(_('This is not a valid APtv backup.')); ?>);selectedMenuProfile=profile;if(profileImportButton)profileImportButton.disabled=false;if(profileStatus)profileStatus.textContent=<?php echo json_encode(_('Backup ready to restore.')); ?>;if(iptvPage?.classList.contains('is-no-provider'))importSelectedProfile(true);}catch(error){if(profileStatus)profileStatus.textContent=error.message;notifyProfile('error',<?php echo json_encode(_('Backup import failed')); ?>,error.message,['Operation: backup validation','File: '+file.name,'Cause: '+error.message]);}
        });
        const importSelectedProfile=async function(automaticNoProviderImport){
          if(!selectedMenuProfile)return;const mode=profileImportMode?.value==='merge'?'merge':'replace';if(mode==='replace'&&!automaticNoProviderImport&&!window.confirm(<?php echo json_encode(_('Replace the current menu order and Favorites with the selected profile?')); ?>))return;
          profileImportButton.disabled=true;if(profileStatus)profileStatus.textContent=<?php echo json_encode(_('Restoring backup…')); ?>;
          try{const fileName=profileFileName?.textContent||<?php echo json_encode(_('Selected backup')); ?>,wasNoProvider=iptvPage?.classList.contains('is-no-provider');let payload;if(wasNoProvider&&automaticNoProviderImport){const provider=selectedMenuProfile?.provider||{},profiles=Array.isArray(provider.profiles)?provider.profiles:[],activeId=String(provider.active_id||''),selected=profiles.find(function(item){return item&&String(item.id||'')===activeId;})||profiles[0];if(!selected||!selected.url||!selected.username||!selected.password)throw new Error(<?php echo json_encode(_('The backup does not contain a complete provider profile.')); ?>);if(!addProviderForm)throw new Error(<?php echo json_encode(_('The Add Provider form is unavailable.')); ?>);addProviderForm.elements.provider_id.value='';addProviderForm.elements.provider_name.value=String(selected.name||'APtv provider');addProviderForm.elements.url.value=String(selected.url||'');addProviderForm.elements.username.value=String(selected.username||'');addProviderForm.elements.password.value=String(selected.password||'');addProviderForm.elements.api_version.value=String(selected.api_version||'v2');await new Promise(function(resolve,reject){pendingNoProviderBackup={resolve:resolve,reject:reject};addProviderForm.requestSubmit();});payload=await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:aptvCsrf,mode:'replace',profile:JSON.stringify(selectedMenuProfile)})});}else if(wasNoProvider){const providerOnly=Object.assign({},selectedMenuProfile,{orders:[],favorites:[]});await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:aptvCsrf,mode:'replace',profile:JSON.stringify(providerOnly)})});await indexImportedProvider(selectedMenuProfile);payload=await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:aptvCsrf,mode:'replace',profile:JSON.stringify(selectedMenuProfile)})});}else payload=await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:aptvCsrf,mode:mode,profile:JSON.stringify(selectedMenuProfile)})});const imported=normalizeMenuProfileFavorites(selectedMenuProfile.favorites),favorites=mode==='merge'&&!wasNoProvider?normalizeMenuProfileFavorites(readLiveFavorites().concat(imported)):imported;window.localStorage.setItem(liveFavoritesKey,JSON.stringify(favorites));readLiveFavorites();selectedMenuProfile=null;if(profileImportInput)profileImportInput.value='';if(profileFileName)profileFileName.textContent=<?php echo json_encode(_('Choose backup to restore')); ?>;if(liveInitialized)await loadRegions('back');const message=wasNoProvider?<?php echo json_encode(_('Provider playlist and backup customization restored successfully.')); ?>:<?php echo json_encode(_('Backup restored successfully.')); ?>;if(profileStatus)profileStatus.textContent=message+' '+(payload.skipped?('('+payload.skipped+' '+<?php echo json_encode(_('catalog sections skipped')); ?>+')'):'');notifyProfile('success',wasNoProvider?<?php echo json_encode(_('Provider added from backup')); ?>:<?php echo json_encode(_('Backup imported')); ?>,message,['Operation: backup import','File: '+fileName,'Import mode: '+(wasNoProvider?'replace':mode),'Playlist: '+(wasNoProvider?'indexed':'unchanged'),'Skipped catalog sections: '+String(payload.skipped||0)]);if(wasNoProvider)window.setTimeout(function(){window.location.reload();},900);}catch(error){if(pendingNoProviderBackup?.reject){pendingNoProviderBackup.reject(error);pendingNoProviderBackup=null;}if(profileStatus)profileStatus.textContent=error.message;notifyProfile('error',<?php echo json_encode(_('Backup import failed')); ?>,error.message,['Operation: backup import','Cause: '+error.message]);}finally{profileImportButton.disabled=!selectedMenuProfile;}
        };
        profileImportButton?.addEventListener('click',function(){importSelectedProfile(false);});
        const resetMenuProfile=async function(){
          profileResetButton.disabled=true;if(profileStatus)profileStatus.textContent=<?php echo json_encode(_('Resetting customization…')); ?>;
          try{await menuProfileRequest({method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:aptvCsrf,mode:'reset',profile:'{}'})});window.localStorage.removeItem(liveFavoritesKey);readLiveFavorites();if(liveInitialized)await loadRegions('back');const message=<?php echo json_encode(_('Customization reset successfully.')); ?>;if(profileStatus)profileStatus.textContent=message;notifyProfile('success',<?php echo json_encode(_('Customization reset')); ?>,message,['Operation: reset customization','Menu order: reset','Favorites: cleared']);}catch(error){if(profileStatus)profileStatus.textContent=error.message;notifyProfile('error',<?php echo json_encode(_('Customization reset failed')); ?>,error.message,['Operation: reset customization','Cause: '+error.message]);}finally{profileResetButton.disabled=false;}
        };
        profileResetButton?.addEventListener('click',function(){
          const message=<?php echo json_encode(_('Reset all custom menu order and Favorites?')); ?>;
          if(typeof window.openapNotify==='function'){window.openapNotify({level:'success',icon:'fa-undo',title:<?php echo json_encode(_('Confirm customization reset')); ?>,message:message,duration:0,actions:[{label:<?php echo json_encode(_('Reset')); ?>,icon:'fa-check',onClick:resetMenuProfile},{label:<?php echo json_encode(_('Cancel')); ?>,icon:'fa-times',onClick:function(){}}]});return;}
          if(window.confirm(message))resetMenuProfile();
        });
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && drawer?.classList.contains('is-open')) setSettingsOpen(false);
          else if (event.key === 'Escape' && liveDrawer?.classList.contains('is-open')) setLiveOpen(false);
        });
        const providerForms = Array.from(document.querySelectorAll('.openap-iptv-provider-form'));
        const addProviderModal = document.getElementById('openapIptvAddProviderModal');
        const addProviderForm = document.querySelector('#openapIptvAddProviderModal form');
        const resetAddProviderForm = function () {
          if (!addProviderForm) return;
          addProviderForm.reset();
          addProviderForm.elements.provider_id.value = '';
          addProviderForm.querySelector('[data-openap-provider-progress]')?.setAttribute('hidden', '');
          addProviderForm.querySelectorAll('input[type="password"]').forEach(function (input) {
            input.type = 'password';
            const toggle = addProviderForm.querySelector('[data-openap-provider-password-toggle][aria-controls="' + input.id + '"]');
            toggle?.setAttribute('aria-pressed', 'false');
            toggle?.setAttribute('aria-label', <?php echo json_encode(_('Show password')); ?>);
            toggle?.querySelector('i')?.classList.remove('fa-eye-slash');
            toggle?.querySelector('i')?.classList.add('fa-eye');
          });
        };
        addProviderModal?.addEventListener('show.bs.modal', function () {
          setSettingsOpen(false);
          resetAddProviderForm();
        });
        const providerDetailsPanel = document.querySelector('[data-openap-provider-details-panel]');
        const providerEditFields = document.querySelector('[data-openap-provider-edit-fields]');
        const providerDetailsClose = document.querySelector('[data-openap-provider-details-close]');
        const editProviderModal = document.getElementById('openapIptvEditProviderModal');
        const savedProviderSelect = editProviderModal?.querySelector('[data-openap-saved-provider]');
        const providerDetailsButton = editProviderModal?.querySelector('[data-openap-provider-details]');
        const removeProviderButton = editProviderModal?.querySelector('[data-openap-provider-remove]');
        const providerDetailsStorageKey = 'openap-iptv-provider-details-v1';
        const resetProviderDetailsView = function () {
          providerDetailsPanel?.classList.remove('is-open');
          providerDetailsPanel?.setAttribute('aria-hidden','true');
          providerEditFields?.classList.remove('is-hidden');
          providerEditFields?.setAttribute('aria-hidden','false');
          if (providerDetailsButton) providerDetailsButton.disabled = false;
        };
        const renderProviderDetails = function (providerName, stats) {
          const values = {
            download_bytes: formatBytes(stats?.download_bytes),
            live_categories: formatCount(stats?.categories?.live),
            vod_categories: formatCount(stats?.categories?.vod),
            series_categories: formatCount(stats?.categories?.series),
            live_count: formatCount(stats?.counts?.live),
            vod_count: formatCount(stats?.counts?.vod),
            series_count: formatCount(stats?.counts?.series),
            database_bytes: formatBytes(stats?.database_bytes),
            integrity: stats?.integrity === 'ok' ? 'OK' : String(stats?.integrity || '--')
          };
          Object.entries(values).forEach(function (entry) {
            const node = providerDetailsPanel?.querySelector('[data-openap-index-stat="' + entry[0] + '"]');
            if (node) node.textContent = entry[1];
          });
        };
        const restoreStoredProviderDetails = function () {
          try {
            const stored = JSON.parse(window.localStorage.getItem(providerDetailsStorageKey) || 'null');
            if (stored?.stats) renderProviderDetails(stored.provider_name, stored.stats);
          } catch (error) {}
        };
        let savedProviders = [];
        const notifyProvider = function (level, title, message, options) {
          if (typeof window.openapNotify === 'function') {
            window.openapNotify(Object.assign({level:level, title:title, message:message, duration:level === 'error' ? 0 : 5000}, options || {}));
          }
        };
        const removeProvider = async function (form, providerId, providerName) {
          const isOnlyProvider = savedProviders.length === 1;
          setSettingsOpen(false);
          removeProviderButton.disabled=true;
          if (isOnlyProvider) bootstrap.Modal.getOrCreateInstance(editProviderModal).hide();
          notifyProvider('success', <?php echo json_encode(_('Removing provider')); ?>, providerName);
          try{const response=await fetch('/aptv/api/providers/delete',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf_token:String(form?.elements.csrf_token?.value||''),provider_id:providerId})}),payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_('Unable to remove the provider.')); ?>);bootstrap.Modal.getOrCreateInstance(editProviderModal).hide();await Promise.all([refreshNoProviderState(),syncWallAvailability()]);notifyProvider('success', <?php echo json_encode(_('Provider removed successfully')); ?>, providerName);}catch(error){if(isOnlyProvider)bootstrap.Modal.getOrCreateInstance(editProviderModal).show();notifyProvider('error', <?php echo json_encode(_('Unable to remove the provider')); ?>, error.message);}finally{removeProviderButton.disabled=false;}
        };
        const populateEditProvider = function () {
          const profile=savedProviders.find(function(item){return item.id===savedProviderSelect?.value;});
          const form=editProviderModal?.querySelector('form'); if(!form)return;
          form.elements.provider_id.value=profile?.id||'';
          form.elements.provider_name.value=profile?.name||'';
          form.elements.url.value=profile?.url||'';
          form.elements.username.value=profile?.username||'';
          form.elements.api_version.value=profile?.api_version||'v2';
          form.elements.password.value='';
        };
        savedProviderSelect?.addEventListener('change',populateEditProvider);
        providerDetailsButton?.addEventListener('click',function(){
          restoreStoredProviderDetails();
          if (providerDetailsPanel) {
            providerDetailsPanel.setAttribute('aria-hidden','false');
            providerDetailsPanel.classList.add('is-open');
          }
          providerEditFields?.setAttribute('aria-hidden','true');
          providerEditFields?.classList.add('is-hidden');
          providerDetailsButton.disabled = true;
        });
        providerDetailsClose?.addEventListener('click',function(){
          resetProviderDetailsView();
        });
        editProviderModal?.addEventListener('hidden.bs.modal', resetProviderDetailsView);
        removeProviderButton?.addEventListener('click',function(){
          const form=editProviderModal?.querySelector('form'),providerId=String(form?.elements.provider_id?.value||'');
          if(!providerId)return;
          const providerName=String(form?.elements.provider_name?.value||savedProviders.find(function(item){return String(item.id)===providerId;})?.name||providerId);
          notifyProvider('success', <?php echo json_encode(_('Confirm provider removal')); ?>, providerName, {duration:0, actions:[
            {label:<?php echo json_encode(_('Remove')); ?>, icon:'fa-trash', onClick:function(){removeProvider(form, providerId, providerName);}},
            {label:<?php echo json_encode(_('Cancel')); ?>, icon:'fa-times', onClick:function(){}}
          ]});
        });
        editProviderModal?.addEventListener('show.bs.modal',async function(){
          try {
            const response=await fetch('/aptv/api/providers',{credentials:'same-origin',cache:'no-store'});const payload=await response.json();
            if(!response.ok||!payload.ok)throw new Error(payload.message||<?php echo json_encode(_("Unable to load providers.")); ?>);
            savedProviders=Array.isArray(payload.items)?payload.items:[]; savedProviderSelect.replaceChildren();
            if(!savedProviders.length){const option=new Option(<?php echo json_encode(_("No saved providers")); ?>,'');savedProviderSelect.append(option);savedProviderSelect.disabled=true;populateEditProvider();return;}
            savedProviderSelect.disabled=false;savedProviders.forEach(function(profile){const option=new Option(profile.name+(profile.active?' · '+<?php echo json_encode(_("Active")); ?>:''),profile.id);savedProviderSelect.append(option);});
            const active=savedProviders.find(function(profile){return profile.active;});savedProviderSelect.value=(active||savedProviders[0]).id;populateEditProvider();
          } catch(error) { savedProviderSelect.replaceChildren(new Option(error.message,''));savedProviderSelect.disabled=true; }
        });
        const formatBytes = function (bytes) {
          if (!Number.isFinite(Number(bytes))) return '--';
          return new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(Number(bytes) / 1048576) + ' MB';
        };
        const formatCount = function (value) {
          return new Intl.NumberFormat().format(Number(value || 0));
        };
        const fetchModalNode = document.getElementById('openapIptvFetchModal');
        let pendingNoProviderBackup = null;
          providerForms.forEach(function (form) {
          form.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = form.querySelector('[data-openap-provider-save]');
            if (providerForms.some(function(item){return item.dataset.fetchBusy === 'true';})) return;
            const modal = form.closest('.modal');
            const status = fetchModalNode.querySelector('[data-openap-fetch-progress]');
            const statusText = status?.querySelector('[role="status"]');
            const terminal = status?.querySelector('[data-fetch-terminal]');
            const progress = status?.querySelector('progress');
            const fetchLog = [];
            let fetchNotification = null;
            const firstProviderOnboarding = Boolean(iptvPage?.classList.contains('is-no-provider'));
            const requestBody = new FormData(form);
            const elapsed = status.querySelector('[data-fetch-elapsed]');
            const startedAt = performance.now();
            const updateElapsed = function() {
              if (elapsed) elapsed.textContent = Math.floor((performance.now() - startedAt) / 1000) + ' s';
            };
            updateElapsed();
            const activityTimer = window.setInterval(updateElapsed, 1000);
            status.classList.add('is-running');
            if (iptvPage?.classList.contains('is-no-provider')) {
            iptvPage.classList.add('is-provider-indexing');
            iptvPage.classList.remove('is-provider-onboarding-reveal');
            providerOnboardingRevealPending = true;
            }
            progress?.setAttribute('aria-busy', 'true');
            const logProgress = function(message, step) {
              fetchLog.push(String(message || ''));
              while (fetchLog.length > 120) fetchLog.shift();
              if (terminal) {
                const line = document.createElement('div');
                line.textContent = message;
                terminal.append(line);
                while (terminal.children.length > 120) terminal.firstChild.remove();
                terminal.scrollTop = terminal.scrollHeight;
              }
              if (progress && Number.isFinite(step)) progress.value = Math.max(progress.value, Math.min(11, step));
              if (fetchNotification) fetchNotification.update({message:String(message || ''), progress:Number.isFinite(step) ? (Math.max(0, Math.min(11, step)) / 11) * 100 : undefined, details:fetchLog});
            };
            form.dataset.fetchBusy = 'true';
            if (button) button.disabled = true;
            if (terminal) terminal.replaceChildren();
            if (progress) progress.value = 0;
            status?.classList.remove('is-error');
            if (status) status.hidden = false;
            if (statusText) statusText.textContent = <?php echo json_encode(_("Fetching playlist…")); ?>;
            logProgress(<?php echo json_encode(_("Connecting to provider…")); ?>, 0);
            try {
              {
                form.querySelector('[data-openap-provider-progress]').hidden = true;
                if (modal.classList.contains('show')) {
                  await new Promise(function(resolve) {
                    modal.addEventListener('hidden.bs.modal', resolve, {once:true});
                    bootstrap.Modal.getOrCreateInstance(modal).hide();
                  });
                }
                if (typeof window.openapNotify === 'function') {
                  fetchNotification = window.openapNotify({
                    level:'success',
                    icon:'fa-download',
                    title:<?php echo json_encode(_('FETCH PROVIDER')); ?>,
                    message:<?php echo json_encode(_('Fetching playlist…')); ?>,
                    duration:0,
                    taskProgress:true,
                    progress:0,
                    details:fetchLog
                  });
                  fetchNotification.update({details:fetchLog});
                } else bootstrap.Modal.getOrCreateInstance(fetchModalNode).show();
              }
              const response = await fetch('/aptv/api/provider/index', {
                method: 'POST', body: requestBody, credentials: 'same-origin', cache: 'no-store', headers: {'Accept':'application/x-ndjson'}
              });
              let payload;
              if (response.headers.get('content-type')?.includes('application/x-ndjson') && response.body) {
                const reader = response.body.getReader(), decoder = new TextDecoder();
                let pending = '';
                const consume = function(line) {
                  if (!line.trim()) return;
                  const record = JSON.parse(line);
                  if (record.event === 'progress') {
                    logProgress(record.message, Number(record.step));
                    if (record.catalog_kind && Number(record.catalog_count) > 0) {
                      if (record.catalog_kind === 'live') {
                        setProviderCatalogAvailability('live', record.catalog_count); setProviderOnboardingStage('live');
                      } else if (record.catalog_kind === 'vod') {
                        setProviderCatalogAvailability('movies', record.catalog_count); setProviderOnboardingStage('movies');
                      } else if (record.catalog_kind === 'series') {
                        setProviderCatalogAvailability('series', record.catalog_count); setProviderOnboardingStage('series');
                      }
                    }
                  }
                  else payload = record;
                };
                try {
                  while (true) {
                    const chunk = await reader.read();
                    pending += decoder.decode(chunk.value, {stream:!chunk.done});
                    let newline;
                    while ((newline = pending.indexOf('\n')) >= 0) { consume(pending.slice(0,newline)); pending = pending.slice(newline+1); }
                    if (chunk.done) break;
                  }
                  consume(pending);
                } finally { reader.releaseLock(); }
              } else payload = await response.json();
              if (!payload) throw new Error(<?php echo json_encode(_("The connection was interrupted. Indexing may still be running.")); ?>);

              if (!response.ok || !payload.ok) throw new Error(payload.message || <?php echo json_encode(_("Indexing failed.")); ?>);
              const indexedCounts = payload.stats?.counts || {};
              setProviderCatalogAvailability('live', indexedCounts.live);
              setProviderCatalogAvailability('movies', indexedCounts.vod);
              setProviderCatalogAvailability('series', indexedCounts.series);
              iptvPage?.classList.add('is-provider-catalog-filtered');
              if (iptvPage?.classList.contains('is-provider-indexing')) {
                const live = Number(indexedCounts.live) > 0, movies = Number(indexedCounts.vod) > 0, series = Number(indexedCounts.series) > 0;
                iptvPage.classList.remove('is-provider-indexing');
                rebuildProviderNavigation?.({live:live, recordings:live, movies:movies, series:series, settings:true});
              }
              setProviderOnboardingStage('ready');
              setNoProviderState(false);
              if (firstProviderOnboarding) playProviderDashboardReveal();
              if (form.dataset.providerMode === 'add' || form.dataset.providerMode === 'edit') {
                /* Refresh the provider switcher as soon as the indexer has
                 * committed the profile; waiting for the bottom navigation
                 * animation can leave the new provider visible only after a
                 * full page reload. */
                syncWallAvailability();
              }
              if (pendingNoProviderBackup?.resolve) { pendingNoProviderBackup.resolve(payload); pendingNoProviderBackup = null; }
              if (firstProviderOnboarding) playProviderOnboardingReveal({
                live: Number(indexedCounts.live) > 0,
                recordings: Number(indexedCounts.live) > 0,
                movies: Number(indexedCounts.vod) > 0,
                series: Number(indexedCounts.series) > 0,
                settings: true
              });
              rebuildBottomNavigation?.({search:true, movies:Number(indexedCounts.vod) > 0, series:Number(indexedCounts.series) > 0, settings:true});
              const providerSectionIndicator = sideNavigation?.querySelector('.openap-iptv-section-indicator');
              if (providerSectionIndicator) {
                requestAnimationFrame(function () {
                  providerSectionIndicator.classList.add('is-onboarding-menu-entering');
                  window.setTimeout(function () { providerSectionIndicator.classList.remove('is-onboarding-menu-entering'); }, 520);
                });
              }
              status.classList.remove('is-running');
              logProgress(<?php echo json_encode(_("Catalog ready. Integrity check: OK.")); ?>, 11);
              if (statusText) statusText.textContent = <?php echo json_encode(_("Fetch complete")); ?>;
              fetchNotification?.update({level:'success',icon:'fa-check',title:<?php echo json_encode(_('Provider ready')); ?>,message:<?php echo json_encode(_('Catalog ready. Integrity check: OK.')); ?>,progress:100,details:fetchLog});
              await new Promise(function(resolve){window.setTimeout(resolve, 600);});
              const stats = payload.stats || {};
              renderProviderDetails(form.elements.provider_name?.value || '', stats);
              try { window.localStorage.setItem(providerDetailsStorageKey, JSON.stringify({provider_name:String(form.elements.provider_name?.value || ''),stats:stats})); } catch (error) {}
              const expirationNode = document.querySelector('[data-openap-service-expiration]');
              const ipNode = document.querySelector('[data-openap-service-ip]');
              const expirationTimestamp = Number(stats.service?.expiration || 0);
              if (expirationNode && expirationTimestamp > 0) {
                expirationNode.textContent = new Intl.DateTimeFormat(undefined, {
                  day: '2-digit', month: 'short', year: 'numeric'
                }).format(new Date(expirationTimestamp * 1000));
              }
              if (ipNode && stats.service?.public_ip) ipNode.textContent = stats.service.public_ip;
              form.querySelectorAll('input[type="password"]').forEach(function (input) { input.value = ''; });
              if (fetchNotification) window.setTimeout(function(){fetchNotification?.close();}, 5000);
              else bootstrap.Modal.getOrCreateInstance(fetchModalNode).hide();
            } catch (error) {
              if (iptvPage?.classList.contains('is-provider-indexing')) {
                iptvPage.classList.remove('is-provider-indexing');
                rebuildProviderNavigation?.({live:false, recordings:false, movies:false, series:false, settings:false});
              }
              if (pendingNoProviderBackup?.reject) { pendingNoProviderBackup.reject(error); pendingNoProviderBackup = null; }
              if (status) status.classList.add('is-error');
              if (statusText) statusText.textContent = <?php echo json_encode(_("Fetch failed")); ?>;
              const fetchError = error instanceof Error ? error.message : <?php echo json_encode(_("Indexing failed.")); ?>;
              logProgress(fetchError);
              fetchNotification?.update({level:'error',icon:'fa-exclamation-triangle',title:<?php echo json_encode(_('Fetch failed')); ?>,message:fetchError,details:fetchLog});
            } finally {
              window.clearInterval(activityTimer);
              updateElapsed();
              status.classList.remove('is-running');
              progress?.setAttribute('aria-busy', 'false');
              form.dataset.fetchBusy = 'false';
              if (button) button.disabled = false;
            }
          });
        });
        document.querySelectorAll('[data-openap-provider-password-toggle]').forEach(function (toggle) {
          toggle.addEventListener('click', function () {
            const input = document.getElementById(toggle.getAttribute('aria-controls') || '');
            if (!(input instanceof HTMLInputElement)) return;
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            toggle.setAttribute('aria-label', reveal ? <?php echo json_encode(_("Hide password")); ?> : <?php echo json_encode(_("Show password")); ?>);
            toggle.querySelector('i')?.classList.toggle('fa-eye', !reveal);
            toggle.querySelector('i')?.classList.toggle('fa-eye-slash', reveal);
            input.focus({ preventScroll: true });
          });
        });
        window.setInterval(function(){
          if(!playerDiagnosticsOpen||!vodPlayback?.direct||!vodPlayback.progressiveSession)return;
          const heartbeatQuery=new URLSearchParams({session:String(vodPlayback.progressiveSession),position:String(Math.max(0,Number(vodPlayback.position)||0))});
          fetch('/aptv/api/vod/direct-stats?'+heartbeatQuery.toString(),{credentials:'same-origin',cache:'no-store'}).then(function(response){return response.json();}).then(function(payload){
            if(!payload?.ok||!vodPlayback?.direct)return;
            playerProviderHeartbeat=payload.provider_heartbeat&&typeof payload.provider_heartbeat==='object'?payload.provider_heartbeat:null;
            if(Number(payload.provider_status)>0)playerProviderStatus=Number(payload.provider_status);
            renderProgressiveDiagnostics();renderPlayerDiagnostics();
          }).catch(function(){});
        },2000);
      })();
    </script>
    <?php
}

function openapIptvCatalogServiceStatus(?string $providerId = null): array
{
    $result = ['expiration' => 0, 'expiration_label' => _("Not configured"), 'public_ip' => '', 'vpn' => ''];
    $path = openapAptvProviderCatalogPath($providerId);
    if (!is_readable($path)) {
        return $result;
    }
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $query = $db->query("SELECT key,value FROM index_metadata WHERE key IN ('service_expiration','public_ip','vpn_type')");
        $metadata = [];
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $metadata[(string) $row['key']] = (string) $row['value'];
        }
        $db->close();
        $expiration = (int) ($metadata['service_expiration'] ?? 0);
        if ($expiration > 0) {
            $result['expiration'] = $expiration;
            $result['expiration_label'] = gmdate('d M Y', $expiration);
        }
        $ip = (string) ($metadata['public_ip'] ?? '');
        $result['public_ip'] = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        $result['vpn'] = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($metadata['vpn_type'] ?? ''));
    } catch (Throwable $error) {
        return $result;
    }
    return $result;
}

function openapIptvServiceStatus(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    if ($providerId !== '' && (!preg_match('/^[a-f0-9]{32}$/', $providerId) || !openapAptvProviderProfileById($providerId))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => _('Invalid provider profile.')]);
        return;
    }
    $service = openapIptvCatalogServiceStatus($providerId !== '' ? $providerId : null);
    $ip = '';
    $traceHandle = function_exists('openapAptvCurl')
        ? openapAptvCurl('https://www.cloudflare.com/cdn-cgi/trace')
        : curl_init('https://www.cloudflare.com/cdn-cgi/trace');
    if ($traceHandle !== false) {
        curl_setopt_array($traceHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $trace = curl_exec($traceHandle);
        curl_close($traceHandle);
        if (is_string($trace)) {
            foreach (preg_split('/\R/', $trace) ?: [] as $line) {
                if (str_starts_with($line, 'ip=')) {
                    $candidate = trim(substr($line, 3));
                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        $ip = $candidate;
                    }
                    break;
                }
            }
        }
    }
    echo json_encode([
        'ok' => true,
        'expiration' => (int) ($service['expiration'] ?? 0),
        'expiration_label' => (string) ($service['expiration_label'] ?? _('Not configured')),
        'public_ip' => filter_var(trim($ip), FILTER_VALIDATE_IP) ? trim($ip) : '',
    ], JSON_UNESCAPED_SLASHES);
}

function openapRenderIptvProviderModal(string $mode): void
{
    $isEdit = $mode === 'edit';
    $modalId = $isEdit ? 'openapIptvEditProviderModal' : 'openapIptvAddProviderModal';
    $title = $isEdit ? _("Edit Provider") : _("Add Provider");
    $detailRows = [
        'download_bytes' => _("Downloaded data"),
        'live_categories' => _("Live categories"), 'vod_categories' => _("VOD categories"),
        'series_categories' => _("Series categories"), 'live_count' => _("Live channels"),
        'vod_count' => _("Movies / VOD"), 'series_count' => _("Series"),
        'database_bytes' => _("Database size"), 'integrity' => _("Integrity"),
    ];
    ?>
    <div class="modal fade openap-iptv-provider-modal" id="<?php echo $modalId; ?>" tabindex="-1" aria-labelledby="<?php echo $modalId; ?>Label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content openap-admin-modal-content openap-ap-ethernet-modal">
          <div class="modal-header openap-ap-ethernet-header">
            <div>
              <div class="openap-ap-ethernet-title" id="<?php echo $modalId; ?>Label"><?php echo $title; ?></div>
              <div class="openap-ap-ethernet-subtitle"><?php echo _("APtv provider connection"); ?></div>
            </div>
            <div class="openap-ap-ethernet-header-actions">
              <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas <?php echo $isEdit ? 'fa-edit' : 'fa-plus-circle'; ?>"></i></span>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
            </div>
          </div>
          <form class="openap-iptv-provider-form" autocomplete="off" data-provider-mode="<?php echo $isEdit ? 'edit' : 'add'; ?>">
            <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
            <input type="hidden" name="provider_id" value="">
            <div class="modal-body openap-ap-ethernet-body">
              <?php if ($isEdit): ?><div class="openap-provider-edit-fields" data-openap-provider-edit-fields><?php endif; ?>
              <?php if ($isEdit): ?>
                <div class="openap-iptv-provider-field">
                  <label for="openapIptvSavedProvider"><?php echo _("Saved provider"); ?></label>
                  <div class="openap-provider-select-row">
                    <select id="openapIptvSavedProvider" data-openap-saved-provider required>
                      <option><?php echo _("No saved providers"); ?></option>
                    </select>
                    <button type="button" class="openap-provider-details-button" data-openap-provider-details aria-label="<?php echo htmlspecialchars(_("Provider details"), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(_("Provider details"), ENT_QUOTES, 'UTF-8'); ?>">
                      <i class="fas fa-list-alt" aria-hidden="true"></i><span><?php echo _("Details"); ?></span>
                    </button>
                  </div>
                </div>
              <?php endif; ?>
              <div class="openap-iptv-provider-field">
                <label for="<?php echo $modalId; ?>Name"><?php echo _("Provider name"); ?></label>
                <input id="<?php echo $modalId; ?>Name" name="provider_name" type="text" maxlength="80" autocomplete="off" placeholder="<?php echo _("My provider"); ?>" required>
              </div>
              <div class="openap-iptv-provider-field">
                <label for="<?php echo $modalId; ?>Url"><?php echo _("URL address"); ?></label>
                <input id="<?php echo $modalId; ?>Url" name="url" type="url" inputmode="url" placeholder="https://provider.example:443" required>
              </div>
              <div class="openap-iptv-provider-field">
                <label for="<?php echo $modalId; ?>Login"><?php echo _("Login"); ?></label>
                <input id="<?php echo $modalId; ?>Login" name="username" type="text" autocomplete="off" required>
              </div>
              <div class="openap-iptv-provider-field">
                <label for="<?php echo $modalId; ?>Password"><?php echo _("Password"); ?></label>
                <div class="openap-iptv-password-control">
                  <input id="<?php echo $modalId; ?>Password" name="password" type="password" autocomplete="new-password" <?php echo $isEdit ? 'placeholder="' . htmlspecialchars(_("Leave blank to keep the current password")) . '"' : 'required'; ?>>
                  <button type="button" class="openap-iptv-password-toggle" aria-label="<?php echo _("Show password"); ?>" aria-controls="<?php echo $modalId; ?>Password" aria-pressed="false" data-openap-provider-password-toggle>
                    <i class="fas fa-eye" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
              <div class="openap-iptv-provider-field">
                <label for="<?php echo $modalId; ?>Api"><?php echo _("API version"); ?></label>
                <select id="<?php echo $modalId; ?>Api" name="api_version">
                  <option value="v2">v2</option>
                  <option value="v1">v1</option>
                </select>
              </div>
              <div class="openap-iptv-provider-progress" data-openap-provider-progress hidden><span role="status"></span></div>
              <?php if ($isEdit): ?></div>
                <div class="openap-provider-details-panel" data-openap-provider-details-panel aria-hidden="true">
                  <div class="openap-provider-details-list">
                    <?php foreach ($detailRows as $key => $label): ?>
                      <div><span><?php echo $label; ?></span><strong data-openap-index-stat="<?php echo $key; ?>">--</strong></div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="openap-provider-details-close" data-openap-provider-details-close><i class="fas fa-times" aria-hidden="true"></i> <?php echo _("Close details"); ?></button>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times" aria-hidden="true"></i> <?php echo _("Cancel"); ?></button>
              <?php if ($isEdit): ?><button type="button" class="btn btn-outline-danger me-auto" data-openap-provider-remove><i class="fas fa-trash" aria-hidden="true"></i> <?php echo _("Remove provider"); ?></button><?php endif; ?>
              <button type="submit" class="btn openap-universal-apply" data-openap-provider-save><i class="fas fa-save" aria-hidden="true"></i> <?php echo _("Save Provider"); ?></button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
}
