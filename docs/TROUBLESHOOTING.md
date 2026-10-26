# Troubleshooting

Start with read-only checks:

```bash
sudo ./aptv-install.sh status
ip -brief link
ip -brief address
ip route
iw dev
systemctl --failed
systemctl status lighttpd hostapd dnsmasq openap-firewall --no-pager
```

## Installer stops before making changes

Check package state:

```bash
dpkg --audit
apt-cache policy
```

Ordinary pending updates can be tolerated with `--allow-pending-updates`.
Incomplete package configuration, broken dependencies, unavailable repositories
or insufficient disk space remain blocking conditions.

## Wi-Fi interface is not offered

Verify:

```bash
iw dev
iw list
rfkill list
```

The adapter must expose AP mode. OpenAP does not install firmware or drivers.
Check that the interface is not blocked and that the correct USB/PCI adapter is
present.

## Hotspot is visible but clients receive no address

Check the AP, bridge, DHCP and address state:

```bash
ip -brief link
ip -brief address
systemctl status hostapd dnsmasq --no-pager
journalctl -u hostapd -u dnsmasq -b --no-pager -n 100
```

Do not change DNS settings until DHCP and the hotspot address are working.

## Hotspot has no Internet access

Check the selected uplink and forwarding path:

```bash
ip route
sysctl net.ipv4.ip_forward
systemctl status openap-firewall --no-pager
```

In Repeater Mode also verify that the uplink interface has carrier, an address,
a default route and an associated access point.

## Bridge mode fails

Bridge mode depends on driver behavior and upstream network support. Confirm
that the AP radio and Ethernet backend are the intended ones, then inspect:

```bash
networkctl status
nmcli device status 2>/dev/null || true
ip link show master br0 2>/dev/null || true
journalctl -b --no-pager -n 150
```

If the driver cannot bridge station traffic, use routed NAT or AP over Ethernet.

## Dashboard is unreachable after a mode change

Reconnect through the currently advertised hotspot address and collect only
sanitized status output. Do not repeatedly switch modes while the previous
operation is still running.

## Safe diagnostic reporting

Remove before sharing:

- hotspot and uplink SSIDs;
- passwords, PSKs, IPTV tokens, cookies and private keys;
- MAC addresses, public addresses and hostnames;
- usernames and home-directory paths;
- provider URLs and account identifiers.

Security issues must be reported privately through `SECURITY.md`, not through a
public issue.
