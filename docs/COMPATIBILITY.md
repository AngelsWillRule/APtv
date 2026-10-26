# Compatibility

## Tested targets

| Operating system | Architecture | IPTV | OpenAP | Validation status |
| --- | --- | ---: | ---: | --- |
| Debian 13 | x86-64 | Yes | Yes | Tested |
| Ubuntu 26.04 | x86-64 | Yes | Yes | Tested |
| Raspberry Pi OS 64-bit | Raspberry Pi 3B+ | Yes | Yes | Tested |

## Expected or experimental targets

| Target | Status | Notes |
| --- | --- | --- |
| Raspberry Pi 4/5 | Expected | Validate the exact board, kernel and adapters |
| Other Debian-based distributions | Experimental | Package names, services and network backend may differ |
| Unsupported architectures | Not claimed | No release guarantee |

Compatibility means that the exact release archive completed installation and
runtime checks on that target. It does not mean that every Wi-Fi chipset or
driver supports every network mode.

## Hardware requirements

The host must provide:

- a supported Debian-based operating system;
- root or `sudo` access;
- a working package-manager connection during installation;
- at least 32 GB of storage recommended for OS, catalogs, caches, logs and snapshots;
- at least one Wi-Fi interface that advertises AP mode;
- a working uplink for the selected network mode.

The installer checks for `sudo` and `iw` and installs the distribution packages
when possible. It does not install firmware or vendor-specific drivers.

| Feature | Required capability |
| --- | --- |
| Hotspot/AP | `AP` mode |
| Wi-Fi uplink | managed/client mode |
| Repeater | one AP-capable radio plus one managed radio |
| Ethernet bridge | driver and kernel bridge support |

Inspect capabilities with:

```bash
ip -brief link
iw dev
iw list
rfkill list
```

## Platform-specific behavior

The installer uses `/etc/os-release` to identify supported Debian/Ubuntu-like
systems and detects native Raspberry Pi hardware from the device model. The
platform choice affects backend and AP-address handling; it does not change the
public installer names or commands.

Debian systems may use `systemd-networkd` or `ifupdown`. Ubuntu systems may use
NetworkManager or `systemd-networkd`. OpenAP detects the active backend and does
not choose one only because the host is physical or virtual.

## Storage policy

- 32 GB is the recommended minimum operational capacity;
- below 32 GB the installer warns the administrator;
- below 16 GB installation is refused unless an explicit override is used;
- obsolete IPTV cache data must be cleaned separately from recordings and snapshots.

## Support expectations

When reporting a compatibility problem, include the sanitized operating system,
kernel, board, Wi-Fi chipset, driver, OpenAP/APtv version and requested mode.
Do not include passwords, IPTV tokens, private addresses or complete wireless
configuration files.
