# APtv

APtv is a Debian-based home-network appliance that combines:

- **OpenAP**: hotspot, DHCP/DNS, routing, firewall and Wi-Fi configuration;
- **APtv IPTV**: web dashboard, provider catalog, live TV, movies, series and VOD playback.

The project is designed for Raspberry Pi, Debian and Ubuntu systems with supported
network hardware. The installer detects interfaces by capability and hardware
identity; it does not require names such as `wlan0` or `eth0`.

> The public installation entrypoint is `aptv-install.sh`. The scripts under
> `openap-installer/bin/` are internal implementation commands.

## Quick start

```bash
sudo apt update
sudo apt install -y git
git clone https://github.com/AngelsWillRule/APtv.git aptv-install
cd aptv-install
sudo ./aptv-install.sh
```

For an explicit profile:

```bash
sudo ./aptv-install.sh install --profile both
```

Available profiles are `ap` (OpenAP), `iptv` (IPTV) and `both` (complete APtv).
The installer accepts ordinary pending operating-system updates. Use
`--allow-pending-updates` in unattended workflows when required; incomplete
`dpkg` state and unresolved dependencies remain blocking errors.

## Compatibility

| Platform | IPTV | OpenAP | Status |
| --- | ---: | ---: | --- |
| Debian 13 x86-64 | Yes | Yes | Tested |
| Ubuntu 26.04 x86-64 | Yes | Yes | Tested |
| Raspberry Pi OS 64-bit on Raspberry Pi 3B+ | Yes | Yes | Tested |
| Raspberry Pi 4/5 | Yes | Yes | Expected; physical validation required |
| Other Debian-based systems | Usually | Hardware-dependent | Experimental |

The exact release archive and SHA-256 checksum define the supported build. See
[compatibility](docs/COMPATIBILITY.md) for the full matrix and limitations.

## Requirements

- 64-bit Debian-based operating system;
- root or `sudo` access;
- working Internet access during installation;
- at least 32 GB of available storage recommended;
- at least one Wi-Fi adapter advertising AP mode;
- a working Ethernet uplink for the initial validated AP flow;
- a valid two-letter wireless regulatory country code.

The installer verifies or installs the `sudo` and `iw` prerequisites when the
distribution package manager permits it. It does not install vendor firmware,
kernel drivers or unsupported third-party Wi-Fi drivers.

Repeater Mode additionally requires a second Wi-Fi adapter capable of managed
client mode. Bridge behavior depends on the driver and hardware support.

```bash
ip -brief link
iw dev
iw list
rfkill list
```

## Network modes

- **AP over Ethernet**: the initial and most widely compatible installation;
- **Routed NAT**: hotspot clients are routed and masqueraded through an uplink;
- **Ethernet bridge**: clients share the Ethernet layer-2 network when supported;
- **Wi-Fi Repeater**: one radio serves the hotspot while another connects upstream.

Mode changes are performed after installation from the dashboard. Repeater and
bridge support is hardware-dependent and must be validated on the target host.

## Component operations

```bash
sudo ./aptv-install.sh status
sudo ./aptv-install.sh repair
sudo ./aptv-install.sh add ap
sudo ./aptv-install.sh add iptv
sudo ./aptv-install.sh remove ap
sudo ./aptv-install.sh remove iptv
sudo ./aptv-install.sh remove all
```

## Documentation

- [Installation guide](docs/INSTALLATION.md)
- [Compatibility and hardware matrix](docs/COMPATIBILITY.md)
- [Network modes](docs/NETWORKING.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Uninstallation](docs/UNINSTALL.md)
- [Security policy](SECURITY.md)
- [Changelog](CHANGELOG.md)

## Support APtv

If APtv is useful to you, you can support its development with a beer:
[Offer me a beer via PayPal](https://paypal.me/APtvApp).

Follow the official [APtv Telegram channel](https://t.me/APtvTelegram) for
release announcements and project updates.

## Security and privacy

The dashboard does not enable WAN access by default. Network changes are
delegated to narrowly scoped root-owned helpers and sudoers rules. Administrators
remain responsible for host firewall policy, updates, physical security and
secure hotspot credentials.

Never publish passwords, provider URLs, IPTV tokens, cookies, private keys,
MAC addresses, public addresses or unsanitized diagnostic bundles. Report
security vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Project status

APtv is preparing its first public GitHub release. Hardware support claims are
limited to the systems listed above and the exact release archive that passed
the validation matrix. See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a
change or issue.

## License and provenance

APtv/OpenAP is distributed under the [GNU GPL version 3](LICENSE). The project
contains code and assets derived from RaspAP; see [NOTICE.md](NOTICE.md) and
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for provenance and licenses.
