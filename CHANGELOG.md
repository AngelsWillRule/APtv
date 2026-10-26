# Changelog

All notable changes to OpenAP are documented in this file.

The project follows semantic versioning. Until a versioned GitHub release is
published, entries describe validated pre-release candidates from `main`.

## [0.2.5.1] - 2026-08-07

### Fixed

- Fixed uninstall detection to use the installed OpenAP marker and entrypoint
  instead of requiring the intentionally excluded webroot `VERSION` file.
- Limited uninstall cleanup to OpenAP-owned files and services, preserving
  unrelated webroot content, shared services, packages, firmware and drivers.
- Added a single updating progress bar and a final removed-items summary to
  applied uninstall operations.

## [0.2.5] - 2026-08-06

### Added

- Added an interactive maintenance menu for existing installations, with
  adapter role switching, uninstall and safe exit actions.
- Added a dry-run-first uninstaller that removes only OpenAP-owned web files,
  configuration, helpers and systemd units while retaining shared services,
  packages, firmware and drivers.
- Added CLI and web workflows for swapping the configured access-point and
  Wi-Fi uplink adapters without reinstalling OpenAP.
- Added progress, confirmation and result states for interface role changes in
  AP Configuration.

### Changed

- Preserved the selected AP/uplink roles consistently when switching between
  AP Ethernet and WiFi Repeater Mode.
- Improved service settling and interface cleanup during runtime mode changes.
- Extended the installer to deploy the interface-role helper and its narrowly
  scoped sudo authorization.

### Fixed

- Fixed the final Network Topology state after choosing AP Ethernet from the
  repeater uplink recovery flow. The UI now clears stale interrupted nodes,
  links and health badges before completing the Ethernet transition.
- Fixed stale interface state during wireless role swaps and subsequent mode
  restoration.

### Validation

- Validated maintenance-menu, adapter-role round trips and the web role-switch
  workflow on Ubuntu 26.04 with Realtek RTL8821CU USB and RTL8822CE PCIe radios.
- Validated clean Debian 13 startup with persistent Ethernet, Debian Realtek
  firmware and both Wi-Fi radios available.
- Validated the repeater failure, recovery modal and AP Ethernet fallback on a
  Debian 13 ARM64 Raspberry Pi test system.

### Known limitations

- Uninstall requires a full applied removal-and-reinstall validation before it
  is considered production-ready.
- OpenAP does not install Wi-Fi firmware or hardware-specific drivers.
- WPA3-only uplinks and automatic full configuration rollback are not yet
  supported.

## [0.2.0] - 2026-08-05

### Added

- Introduced the first public OpenAP pre-release candidate with AP-over-
  Ethernet, two-radio WiFi Repeater Mode, DHCP/DNS, nftables forwarding and a
  responsive administration interface.
- Added the universal Debian-family installer, hardware-capability detection,
  release metadata and read-only continuous-integration checks.
- Added public installation, hardware, security and contribution guidance.
