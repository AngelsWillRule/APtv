# OpenAP Universal Installer

The OpenAP universal installer provides one validated initial configuration:
an Ethernet uplink with a Wi-Fi hotspot. It supports Debian, Ubuntu and
Raspberry Pi OS through platform detection inside the same entrypoint.

The proposed transactional hotspot subnet editor is documented in
[`docs/DHCP_SUBNET_DESIGN.md`](docs/DHCP_SUBNET_DESIGN.md).

Future reliability, multimedia and consumer-facing product ideas are tracked
in [`../docs/OPENAP_FEATURE_ROADMAP.md`](../docs/OPENAP_FEATURE_ROADMAP.md).
Items in that roadmap are proposals, not implemented installer capabilities.
Public platform claims remain provisional until the exact release candidate
passes the clean validation matrix described in the main documentation.

The detector is intentionally read-only:

```bash
openap-installer/bin/openap-detect
openap-installer/bin/openap-detect --json
```

> **Container safety:** never use `--apply` in a container that shares its
> host's network namespace. Network configuration performed inside such a
> container can alter the host's routes and interfaces. Detection and dry runs
> are read-only; installation requires an isolated test target or the intended
> physical system.

The universal installer is dry-run by default:

```bash
openap-installer/bin/openap-install
sudo openap-installer/bin/openap-install --apply --yes
```

Interactive runs stop after the welcome screen and wait for `Enter` to proceed
or `Esc` to exit. `--yes` skips this pause for unattended operation.

## Component profiles

The installer can deploy the OpenAP network workspace, the APtv IPTV
workspace, or both:

```bash
sudo openap-installer/bin/openap-install --apply --components openap
sudo openap-installer/bin/openap-install --apply --components iptv
sudo openap-installer/bin/openap-install --apply --components both
```

When no component is specified, an interactive run offers the three choices.
An unattended run keeps the backward-compatible `openap` default. The
selected profile is recorded in `/etc/openap/components/profile` and the
workspace switcher only exposes workspaces that are actually installed.

The `iptv` profile installs the shared PHP web shell, APtv frontend, FFmpeg
workers, catalog indexer, persistent state directories and IPTV services. It
does not configure hostapd, dnsmasq, firewall rules or the OpenAP wireless
network. The `both` profile runs the validated OpenAP installation first and
adds APtv only after the OpenAP phase completes.

The standalone APtv component entrypoint is also available directly:

```bash
openap-installer/bin/openap-iptv-install
sudo OPENAP_ADMIN_PASSWORD='use-a-private-value' \
  openap-installer/bin/openap-iptv-install --apply --yes --profile iptv
```

The OpenAP profile continues to expose one validated network flow: an Ethernet
uplink with a Wi-Fi hotspot. The APtv profile does not select or modify a
network backend.

`repeater-wifi` is likewise not an installer profile because a clean
installation directly into that mode has not been developed or validated.
Wi-Fi repeater remains a post-install runtime option in the OpenAP UI when a
second suitable radio is present.

DNS conflict handling defaults to automatic mode:

```bash
sudo openap-installer/bin/openap-install --apply --dns-mode auto
```

After assigning the AP address, `auto` tests both TCP and UDP port 53 on
`10.88.77.1`. It enables local dnsmasq DNS when available. On conflict it
prints a warning, leaves the existing resolver untouched, runs dnsmasq as
DHCP-only and advertises `1.1.1.1,1.0.0.1` to hotspot clients.

Advanced overrides:

```bash
--dns-mode local      # require local DNS and abort if port 53 is unavailable
--dns-mode external   # always use DHCP-only with external DNS
```

## Initial access and credentials

An interactive standard install asks for:

- the Wi-Fi interface used by the hotspot;
- the two-letter wireless regulatory country, with the detected country offered as the default;
- the initial hotspot SSID and password;
- the OpenAP web administrator username and password.

When exactly two usable Wi-Fi interfaces are detected and both can operate as
an access point, the interactive installer always asks which adapter should be
dedicated to the OpenAP hotspot. It then identifies the other adapter and
records it as the standby Wi-Fi uplink for a later switch to Repeater Mode.
The standard installation remains AP-over-Ethernet and does not activate the
repeater automatically. On an interactive reinstall, the existing AP remains
the default choice but is shown for confirmation instead of being preserved
silently. Unattended `--yes` runs continue accepting the detector's recommended
roles; `--ap-iface IFACE` remains the explicit non-interactive override.

OpenAP does not install Wi-Fi firmware, enable firmware repositories, reload
hardware drivers or attempt to recover radios that failed probing. Before
running the installer, the administrator must install the firmware and driver
required by the hardware and verify that every intended AP/uplink Wi-Fi
interface is visible and functional. If no Wi-Fi interface exists, the
installer stops before installing OpenAP packages.

### Realtek adapters used for OpenAP testing

The Debian 13 and Ubuntu 26.04 OpenAP test matrix includes Realtek RTL8821CU
USB (`0bda:c820`) and RTL8822CE PCIe adapters using the in-kernel `rtw88`
drivers. This records the tested configuration; it does not make firmware or
driver installation part of OpenAP.

On a minimal Debian 13 installation, enable Debian's `non-free-firmware`
component and install the Realtek firmware before starting the OpenAP
installer. For the traditional `/etc/apt/sources.list` format used by the test
VM, run as root:

```bash
sed -i '/^deb / s/ main contrib$/ main contrib non-free-firmware/' /etc/apt/sources.list
apt update
apt install -y firmware-realtek wireless-regdb
reboot
```

Administrators using deb822 `.sources` files should add
`non-free-firmware` to each applicable `Components:` line instead of using the
`sed` command above. Ubuntu provides the corresponding firmware through its
`linux-firmware` package.

After rebooting, confirm that every intended radio exists before running
OpenAP:

```bash
iw dev
ip -brief link
```

Hardware revisions can require different firmware or out-of-tree drivers.
OpenAP deliberately leaves that hardware-specific setup to the administrator.

The country is stored in the OpenAP role profile, written to hostapd as
`country_code` with `ieee80211d=1`, and applied at boot before hostapd and the
Wi-Fi uplink start. Automated installs can specify it explicitly, for example:

```bash
sudo openap-installer/bin/openap-install --apply --yes --country IT
```

With `--yes`, the installer accepts a valid country already present in hostapd,
the current kernel regulatory domain, or the system locale. If none can be
detected, `--country` is required.

On Debian, if the installer adds `wireless-regdb`, it offers an optional reboot
at completion because cfg80211 must reload the regulatory database before the
selected country and full 5 GHz channel list are reliable. Unattended `--yes`
runs report the required reboot but never initiate it automatically.

When several hotspot-capable Wi-Fi adapters are present, the installer explains
that exactly one adapter will be dedicated to broadcasting OpenAP. Each choice
shows whether it is USB/internal, supported bands, driver, MAC address, whether
it is already connected as a Wi-Fi client, and which adapter is recommended.
It warns that choosing a connected adapter will disconnect that client link and
confirms that the Ethernet uplink and other Wi-Fi adapters remain untouched.

The initial hotspot uses WPA2-Personal (`WPA2-PSK`) with AES/CCMP. WPA2 keys
must contain 8 to 63 bytes. A new installation never uses a shared default
password. Interactive installs generate a unique password when the password
prompt is left empty. With `--yes` and an attached terminal, unique hotspot
and administrator passwords are generated and displayed once at completion.
Headless unattended installations must provide `OPENAP_HOTSPOT_PASSWORD` and
`OPENAP_ADMIN_PASSWORD` in the installer environment; these values are not
written to the installer log. Administrator passwords must contain 12 to 128
bytes. The default SSID and administrator username remain `OpenAP` and
`admin`.

At completion, the installer reports the hotspot URL (`http://10.88.77.1/`),
the detected LAN URL when available, selected interfaces, DNS mode, SSID,
security and administrator username. Passwords are shown only on the attached
interactive terminal and are not copied into `/var/log/openap-installer.log`.

After installation, the Hotspot configuration page keeps the installer-selected
AP interface fixed and presents coordinated Band, Wireless mode, Channel width
and Channel controls. Selecting 2.4 GHz automatically selects 802.11n, offers
20/40 MHz and immediately lists only supported 2.4 GHz channels. Selecting
5 GHz chooses 802.11ac, offers 20/40/80 MHz and immediately lists only supported
5 GHz channels. The channel list comes from the adapter and regulatory domain.
When the channel changes, OpenAP selects the widest valid width whose complete
20 MHz channel block is available: up to 40 MHz on 2.4 GHz and up to 80 MHz on
5 GHz. Narrower compatible widths remain selectable manually.
Saving restarts only hostapd, so connected Wi-Fi clients disconnect briefly and
must reconnect; DHCP, addressing and routing are left unchanged.

## Current Scope

### Maintenance menu

Running `openap-install` interactively on a host where `/etc/openap/release`
already exists opens a deliberately small maintenance menu. The first version
offers only these operations:

1. switch the configured AP and Wi-Fi uplink adapters;
2. uninstall OpenAP;
3. exit without changes.

The command keeps its existing safety model: without `--apply`, adapter
switching and uninstall are dry runs. Uninstall removes only OpenAP-owned web
files, `/etc/openap`, OpenAP helpers, configuration and systemd units. It does
not create a backup and retains Lighttpd, dnsmasq, hostapd, installed operating-
system packages, firmware and drivers.

During an applied uninstall, a single progress bar is redrawn as its percentage
and current cleanup phase advance. A final summary reports the system, web and
configuration entries that were actually removed.

The adapter switch opens a dedicated role-selection screen listing every
detected Wi-Fi interface, its MAC address, driver, bus and bands. The
administrator selects the AP and uplink roles separately. OpenAP then applies
only the interface-related hostapd, dnsmasq, networkd, firewall and role-profile
changes; it does not reinstall packages or the web UI and does not ask again
for country or credentials.

The standard Debian install also includes optional encrypted hotspot DNS.
DHCP Setting can enable Cloudflare or Quad9 Security through dnscrypt-proxy.
OpenAP keeps dnsmasq as the client-facing resolver and switches only its
upstream to the local encrypted endpoint. Configuration validation, endpoint
health checks and automatic rollback are performed before the new state is
committed.

- Detect Wi-Fi and Ethernet interfaces without trusting interface names.
- Identify wireless devices through `iw dev` and sysfs, even if named `eth1`.
- Report MAC, driver, bus, USB IDs, phy, supported AP/managed modes, and 2.4/5 GHz capability.
- Detect local systemd `.link` rules that force interface names.
- Detect Realtek USB Wi-Fi devices in storage/install mode such as `0bda:1a2b`.
- Report relevant service state: NetworkManager, systemd-networkd, hostapd, dnsmasq, nftables, openap-uplink.
- Propose initial AP/ethernet roles for standard installation.
- Report repeater-capable Wi-Fi candidates for later UI/runtime switching.
- Install the package set needed by OpenAP on Debian/Ubuntu-like minimal systems.
- Require Wi-Fi firmware and drivers to be configured before OpenAP starts;
  block before the package install if no Wi-Fi interface is visible.
- Validate AP-capable Wi-Fi and Ethernet after the minimal detection tools are installed.
- Explain in plain language which Wi-Fi will become the dedicated hotspot adapter, marking connected and recommended choices before asking the user.
- Ask for the initial hotspot SSID and WPA2 password during an interactive install, preserving existing hotspot credentials on reinstall.
- Ask for and persist the wireless regulatory country, preserving an existing valid hostapd country and supporting `--country CC` for unattended installs.
- Resolve the effective Ethernet uplink automatically. If the physical Ethernet port is enslaved to a bridge, store the bridge as the runtime uplink and retain the physical interface and MAC as persistent identity.
- Ask for the OpenAP administrator username and password during an interactive install.
- Generate unique credentials with `--yes`, or require explicit password environment variables for a headless unattended installation.
- Preserve existing administrator credentials on reinstall and include `raspap.auth` in the preventive backup.
- Back up existing hostapd, dnsmasq, nftables, sudoers, wpa_supplicant and OpenAP config state before apply.
- Install the OpenAP UI, helper scripts, sudoers, runtime directories and baseline web server routing.
- Create an initial `/etc/raspap/repeater.ini` in standard `ap-ethernet` mode.
- Activate the selected hotspot immediately for the standard AP-over-Ethernet install, with adaptive dnsmasq DNS handling, dedicated OpenAP nftables NAT and persistent interface addressing.
- Detect DNS port conflicts on the AP address and select local DNS or a safe external-DNS fallback without stopping an existing resolver.
- Switch between AP-over-Ethernet and WiFi repeater mode from the OpenAP UI
  when two suitable radios are available.
- Configure the hotspot `/24` network, gateway, DHCP pool, lease and DNS policy
  transactionally, with delayed apply status and coordinated rollback on
  failure.
- Install the narrowly scoped system reboot helper used by the System page.

## Design Rule

OpenAP must assign roles to real interfaces. It should not depend on fixed Linux names such as `wlan0`, `wlan1`, or `eth0`.

Friendly labels may be shown in the UI, but generated configs must use the resolved current interface name at apply time.

## Installer Rule

The installer should assume the simplest useful starting point:

```text
Ethernet uplink -> default Wi-Fi hotspot
```

It should not ask the user to assign two Wi-Fi interfaces during the base install. If OpenAP later detects a second usable Wi-Fi interface, the UI/runtime should offer a switch to repeater mode and ask which interface should be AP and which should be uplink.

If more than one AP-capable Wi-Fi interface is available during the base install, the installer may ask only which single interface should be used for the initial hotspot. It must not switch the install flow to repeater mode.

The base installer must not ask the user to choose an Ethernet interface. It detects the physical Ethernet port and follows its master relationship when it belongs to a bridge. The resulting effective interface is stored as the Ethernet uplink.

For a generic two-adapter test host, example interface labels are:

```text
wlan-usb-a
wlan-usb-b
```

An internal adapter such as `wlan-internal` should remain outside the normal
repeater test path unless only one external Wi-Fi interface is visible.

## Not Yet Implemented

- Fedora/Arch/OpenWrt/non-Debian package handling.
- Full conflict adoption for pre-existing hostapd, dnsmasq, NetworkManager and custom firewall setups.
- General-purpose interactive AP/uplink role remapping beyond the validated
  two-radio OpenAP workflow.
- Additional service activation hardening and conflict adoption for non-clean hosts.
- Rollback command that restores the backup automatically.


## Interactive component manager

The only public entrypoint is `aptv-install.sh` at the repository root. It opens a
state-aware English menu and exposes only operations valid for the components
currently installed. The scripts under `openap-installer/bin` are internal
implementation commands.

```bash
sudo ./aptv-install.sh
sudo ./aptv-install.sh status
sudo ./aptv-install.sh install --profile ap
sudo ./aptv-install.sh install --profile iptv
sudo ./aptv-install.sh install --profile both
sudo ./aptv-install.sh add ap
sudo ./aptv-install.sh add iptv
sudo ./aptv-install.sh remove ap
sudo ./aptv-install.sh remove iptv
sudo ./aptv-install.sh remove all
sudo ./aptv-install.sh repair
```

The manager keeps component ownership separate. Removing APtv uses the
component uninstaller and restores the OpenAP UI when AP is retained. Removing
AP rebuilds the standalone APtv installation when IPTV is retained. Every
component removal creates a root-only backup under `/var/backups/openap/`.
Shared files are rebuilt from the selected profile, rather than being removed
blindly.

