# OpenAP feature roadmap

Last updated: 2026-07-26

This document records product ideas for future OpenAP releases. None of the
features below should be considered implemented until it is moved into the
validated project change log.

The roadmap has two complementary goals:

1. make network changes recoverable and understandable;
2. give OpenAP useful, visible features beyond access-point administration.

Every feature that changes addressing, routes, DHCP, DNS, hostapd or firewall
state must preserve the current hardware-based interface detection and use a
transactional apply/verification/rollback flow.

## Reliability foundation

These items should precede advanced routing and consumer modules.

### Confirmed apply and automatic rollback

- Create a coordinated backup before AP, repeater, DHCP or hostapd changes.
- Apply the new configuration and verify AP address, DHCP, DNS, default route,
  forwarding, NAT, Internet access and dashboard reachability.
- Ask the administrator to confirm the new state within a bounded interval.
- Automatically restore the previous state when validation or confirmation
  fails.
- Keep rollback independent from the web session so it still runs if the
  dashboard becomes unreachable.

### Safe AP recovery mode

- Provide a minimal known-good recovery SSID and fixed management subnet.
- Run local DHCP and the recovery UI without requiring a working uplink.
- Allow inspection, backup restore and controlled service repair.
- Support explicit activation and optional activation after repeated failed
  boots.

### OpenAP watchdog

- Detect missing AP addresses, hostapd/dnsmasq failures, mismatched DHCP
  subnets, missing default routes, failed NAT and an unreachable local UI.
- Automatically perform only conservative, idempotent repairs.
- Require confirmation for changes that can disrupt connectivity.
- Record every detection and repair in the event history.

### Backup and restore UI

- Export `/etc/openap`, OpenAP hostapd/dnsmasq state, firewall rules, role
  profiles, OpenAP systemd units, helpers and sudoers rules.
- Record OpenAP version, operating system, hardware profile, creation date and
  checksum.
- Protect secrets and require explicit confirmation before restore.
- Validate restored files before activating services.

### Diagnostics and history

- Present the connectivity chain as
  `client -> AP -> DHCP -> DNS -> uplink gateway -> Internet`.
- Explain failures in plain language and show the mismatched active values.
- Provide a sanitized support bundle with secrets, PSKs, cookies and private
  keys removed.
- Maintain a timeline of mode changes, associations, DHCP events, uplink loss,
  service restarts, repairs and rollbacks.
- Produce a post-boot health report with readiness timings.

## Consumer and multimedia direction

The proposed product identity is an optional **OpenAP Lounge**: a portable,
local-first hub for connectivity, entertainment and sharing. It must continue
to work without Internet access.

Consumer modules should be optional and isolated from the core router runtime.
A broken media or sharing module must never prevent hostapd, DHCP, DNS,
firewall or the recovery UI from starting.

### Media Hub

- Detect explicitly approved USB disks and media directories.
- Index video, audio and photographs.
- Provide a responsive browser player, cover art, metadata, playlists and
  playback progress.
- Offer DLNA discovery and direct playback on compatible televisions and
  players.
- Integrate optionally with Jellyfin on capable hosts.
- Prefer direct streaming on Raspberry Pi 3B+; do not require real-time
  transcoding on low-power hardware.

### Offline Cinema

- Expose local media, documents, guides and web applications through the
  hotspot when no Internet connection is available.
- Provide a landing page suitable for campers, travel, schools, events and
  temporary installations.
- Keep all essential assets local and display the current online/offline
  state clearly.

### Photo Drop

- Let guests open an upload page from a QR code without installing an app.
- Organize uploads into event albums.
- Support moderation, quotas, expiry and an administrator-controlled ZIP
  export.
- Provide an optional live slideshow for a television or browser display.
- Never expose uploaded material outside the local network by default.

### Universal local file sharing

- Send and receive files between Android, iOS, Windows, macOS and Linux from a
  browser.
- Generate temporary QR codes and expiring download links.
- Support optional passwords, upload limits and a shared text/URL clipboard.
- Keep public-WAN exposure disabled by default.

### Party Mode

- Allow guests to propose and vote on tracks through a QR-accessible page.
- Provide an administrator-moderated playback queue and a TV-friendly
  now-playing screen.
- Support local music and external players through optional adapters.
- Treat commercial streaming integrations as separate modules subject to
  their service terms and APIs.

### Cast and device discovery

- Discover DLNA renderers and compatible local players.
- Offer a simple `Play on device` action from the Media Hub.
- Proxy mDNS or SSDP only across explicitly selected trusted zones.
- Do not weaken guest/client isolation merely to make discovery work.

### Local game lounge

- Host lightweight, legally distributable HTML5 games.
- Prioritize local multiplayer, quizzes and party games controlled by phones.
- Keep ROMs and other copyrighted content outside the shipped OpenAP package.

### Guest portal

- Provide customizable welcome, event, house-information and acceptable-use
  pages.
- Link Media Hub, Photo Drop, file sharing and Party Mode from one landing
  page.
- Support temporary guest credentials and expiry.
- Keep captive-portal interception optional because it can interfere with
  HTTPS and operating-system connectivity checks.

### Home services dashboard

- Discover and link to services such as Home Assistant, Nextcloud, Jellyfin,
  NAS devices, printers and camera systems.
- Display service availability without storing third-party credentials unless
  a dedicated integration explicitly requires them.
- Use allowlisted discovery and avoid unrestricted scanning by default.

### Download station

- Support controlled HTTP/HTTPS downloads and lawful torrent/magnet use.
- Provide storage selection, schedules, bandwidth limits and completion
  notifications.
- Isolate the downloader, require authentication and bind its administration
  interface to trusted networks only.
- Never expose a downloader WebUI publicly by default.

## Travel and everyday networking

### Travel mode

- Preserve one familiar OpenAP SSID while connecting to hotel or temporary
  WiFi uplinks.
- Detect captive portals and guide the administrator through authentication.
- Combine local files and media with the current Internet connection.
- Show data usage and optionally route hotspot clients through a VPN.

### Uplink profiles and failover

- Store multiple WiFi uplinks with priority and metered-network flags.
- Support ordered failover such as
  `Ethernet -> preferred WiFi -> secondary WiFi`.
- Use settling intervals to avoid route oscillation.
- Keep the hotspot subnet stable while changing uplinks.
- Return to the preferred uplink only after it remains healthy.

### Guest and IoT networks

- Offer separate guest and IoT SSIDs when the radio supports multiple BSSIDs.
- Provide client isolation and dedicated DHCP/firewall policy.
- Allow Internet-only guests and explicitly allowlisted IoT destinations.
- Detect unsupported hardware before presenting the feature.

### Client management

- Assign friendly names and optional reserved addresses.
- Show vendor, lease, signal, traffic, first seen and last seen.
- Support blocking, schedules and bandwidth policy.
- Make policy changes reversible and identify clients by more than a mutable
  interface name.

### Radio quality assistant

- Report signal, noise/SNR where available, bitrate, retries, failures,
  channel width and channel occupancy.
- Recommend channels and widths using the regulatory domain and the real
  adapter capability.
- Apply a recommendation only through the normal transactional workflow.

### Dashboard-preserving QoS

- Protect management UI, DNS and essential control traffic during saturation.
- Offer fair sharing between clients and optional interactive-traffic
  priority.
- Measure latency and throughput before and after activation.
- Do not install a fixed bandwidth ceiling without measuring the target
  uplink and hardware.

## Maintenance and extensibility

### Update preflight

- Check disk space, OS/PHP/hostapd compatibility and local modifications.
- Create a verified backup and validate sudoers before upgrading.
- Show a dry-run report and prepare a rollback path.

### Hardware compatibility records

- Store observed USB ID, chipset, driver, bands, widths and AP/managed
  capabilities.
- Record resets, usb-modeswitch requirements and validated operating systems.
- Distinguish detected capability from a configuration actually tested by
  OpenAP.

### Optional module framework

- Install consumer services as explicit modules rather than core
  dependencies.
- Declare CPU, memory, storage, ports and operating-system requirements.
- Expose health, logs, upgrades and uninstall operations consistently.
- Keep module permissions narrow and prevent modules from silently altering
  the OpenAP network stack.

## Proposed delivery order

### Phase 1: safe platform

1. backup and restore UI;
2. confirmed apply with automatic rollback;
3. Safe AP recovery mode;
4. layered diagnostics and sanitized support bundle;
5. watchdog and event history;
6. dashboard-preserving QoS.

### Phase 2: OpenAP Lounge MVP

1. approved USB-storage management;
2. direct-stream Media Hub;
3. Photo Drop and slideshow;
4. browser-based local file sharing;
5. unified guest landing page;
6. optional DLNA output.

### Phase 3: broader consumer features

1. Party Mode;
2. travel mode and captive-portal assistance;
3. uplink profiles and failover;
4. home-services dashboard;
5. optional Jellyfin integration;
6. local game lounge and download station.

### Phase 4: advanced networking

1. guest and IoT SSIDs;
2. client policy and schedules;
3. channel-quality assistant;
4. per-client or per-service policy routing.

## First MVP constraints

The first multimedia release should run acceptably on a Raspberry Pi 3B+:

- direct playback rather than mandatory transcoding;
- bounded thumbnail generation;
- configurable library size and scan schedule;
- no automatic indexing of every mounted filesystem;
- no WAN exposure;
- no network-stack dependency on a multimedia service;
- explicit storage-removal handling;
- graceful operation when Internet metadata services are unavailable.

