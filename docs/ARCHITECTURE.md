# Architecture

## Product layers

```text
APtv
├── OpenAP
│   ├── interface detection
│   ├── hostapd and hotspot configuration
│   ├── DHCP/DNS
│   ├── routing, NAT and firewall
│   └── network-mode helpers
└── IPTV
    ├── web dashboard
    ├── provider and catalog management
    ├── live, movie and series views
    ├── VOD relay/player services
    └── installer overlay and runtime
```

## Installer call graph

```text
aptv-install.sh
└── openap-installer/bin/openap-manager
    ├── openap-install
    ├── openap-iptv-install
    ├── openap-uninstall
    └── openap-iptv-uninstall
```

`installers/openap-component-manager` is installed as
`/usr/local/sbin/openap-component-manager` and serializes component operations
requested by the authenticated dashboard.

The names under `openap-installer/` are internal. The stable public interface
is the root command `aptv-install.sh` and its documented profiles.

## Privilege boundary

The web process is not an unrestricted root shell. Privileged operations are
delegated to specific root-owned helpers and narrow sudoers rules. Runtime
component operations use queued jobs and status files so that the dashboard can
report progress without returning credentials to the browser.

## Runtime and release layout

The source tree contains both the APtv webroot and the installer overlay. The
release archive has a single public root directory named `aptv-install/`.
The installer copies only the required runtime files and helpers to the host;
the source tree itself is not intended to become a public webroot.

## Platform selection

The installer detects the operating system through `/etc/os-release`, detects
native Raspberry Pi hardware from the device model, and detects the active
network backend from the running services and interface configuration. It does
not select a backend solely from virtualization status.
