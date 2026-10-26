# Hotspot subnet configuration draft

Last reviewed: 2026-07-21

## Goal

Allow an OpenAP administrator to change the hotspot IPv4 subnet without
editing dnsmasq, systemd-networkd, nftables or the OpenAP role profile by hand.
The operation must remain consistent across AP-over-Ethernet and WiFi repeater
modes and must fail without leaving the hotspot partially configured.

The current default remains:

```text
Subnet:      10.88.77.0/24
AP gateway:  10.88.77.1
DHCP pool:   10.88.77.50 - 10.88.77.200
```

## Proposed first version

Add a **Hotspot network** section at the top of DHCP Server > Server settings.
Keep the existing generic RaspAP interface configuration separate: OpenAP's
hotspot network is a coordinated system setting, not merely a dnsmasq field.

Primary control:

```text
Hotspot subnet (CIDR)  [ 10.88.77.0/24 ]
```

For the first implementation accept private IPv4 `/24` networks only. This
covers the normal appliance use case while keeping gateway, broadcast, pool
size and collision checks predictable. Broader prefix support can be added
after the apply/rollback path is proven.

Derived controls are shown immediately and remain editable only in an
**Advanced pool settings** disclosure:

```text
Gateway       10.88.77.1       (default: first usable address)
DHCP start    10.88.77.50
DHCP end      10.88.77.200
Lease time    12h
DNS policy    current OpenAP auto/local/external policy (read-only here)
```

Changing the CIDR recalculates gateway and pool defaults. If the user has
manually changed a derived value, the UI asks before replacing it.

## Preview and confirmation

Before Apply, show a concise impact summary:

```text
Old hotspot network: 10.88.77.0/24
New hotspot network: 192.168.50.0/24
New gateway / local URL: 192.168.50.1 / http://192.168.50.1/
New DHCP pool: 192.168.50.50 - 192.168.50.200
Affected services: systemd-networkd, dnsmasq, nftables
Connected hotspot clients will disconnect and must obtain a new address.
```

Require an explicit confirmation. If the dashboard is being accessed through
the hotspot gateway rather than the Ethernet management address, warn that the
browser connection will be lost as soon as the address changes. Do not promise
an automatic redirect in that case.

On success, use the same final-state pattern as the Configure modal: show the
applied values, the new hotspot URL, a Copy details button and a Close button.
Close refreshes the dashboard when it is still reachable.

## Validation

Validation must run both in PHP and again in the privileged helper.

Reject a request unless all of the following are true:

- CIDR is canonical private IPv4 and exactly `/24` in the first version.
- Gateway belongs to the subnet and is neither network nor broadcast address.
- DHCP start/end belong to the subnet, are ordered and exclude the gateway.
- The pool leaves sufficient usable addresses and does not include reserved
  addresses.
- The new subnet does not overlap any non-AP IPv4 address or route currently
  present on the host.
- It does not overlap the Ethernet LAN, WiFi uplink, VPN, Docker/Incus bridge
  or another locally routed network.
- The selected AP interface still exists and is wireless.
- DNS mode remains valid for the new gateway address.

The current OpenAP subnet is ignored only when comparing the AP interface and
OpenAP's own NAT route; every other overlap remains an error. Error messages
must name the conflicting interface or route, for example:

```text
192.0.2.0/24 overlaps Ethernet interface enp5s0 (192.0.2.22/24).
```

## Canonical state

`/etc/raspap/repeater.ini` remains the authoritative OpenAP network profile:

```ini
[network]
subnet = 192.168.50.0/24
gateway = 192.168.50.1
dhcp_start = 192.168.50.50
dhcp_end = 192.168.50.200
lease_time = 12h
ethernet_gateway = 192.168.1.1
```

AP-over-Ethernet and repeater helpers must continue reading these values rather
than reintroducing fixed addresses.

## Privileged apply helper

Add a narrowly scoped helper:

```text
/usr/local/sbin/openap-apply-hotspot-network
```

The web process writes a root-untrusted request to a temporary file. The helper
accepts only that file path, parses a fixed key set and independently validates
all addresses with Python's standard `ipaddress` module. Its sudoers rule must
name the helper exactly; do not grant direct write access to network files.

Apply sequence:

1. Take an exclusive OpenAP network lock.
2. Re-resolve the AP and current egress interfaces by stored MAC address.
3. Re-run collision and address validation against current host state.
4. Back up the role profile, AP `.network` file, dnsmasq config and nftables
   config to a single timestamped directory.
5. Generate every replacement into temporary files.
6. Validate dnsmasq with `dnsmasq --test` and nftables with `nft -c`.
7. Stop dnsmasq, apply the AP address, reload networkd and replace NAT rules.
8. Start dnsmasq and verify the gateway address, DHCP service, NAT table and
   hostapd state.
9. Commit the new `repeater.ini` last and prune leases outside the new subnet.
10. On any failure, restore the complete backup and restart the previous state.

Do not update the profile before the runtime health checks succeed. This avoids
the partial-state failure already seen when dnsmasq was restarted without the
AP gateway address.

## Files and code paths affected

The implementation must remove fixed `10.88.77.0/24` assumptions from:

- OpenAP installer baseline network and dnsmasq generation;
- `openap-apply-ap-ethernet.sh`;
- `openap-apply-repeater-wifi.sh`;
- OpenAP NAT health checks in `includes/repeater.php`;
- dashboard DHCP summary and hotspot URL reporting;
- systemd-networkd AP address generation;
- dnsmasq local/external DNS configuration;
- nftables source subnet matching;
- install summary and documentation.

The generic legacy DHCP editor currently writes separate RaspAP/dhcpcd files.
It must not be allowed to overwrite the OpenAP AP interface independently of
this transaction. For the OpenAP profile, either hide those conflicting fields
or render them read-only with a link to Hotspot network.

## Testing matrix

Minimum tests:

1. `10.88.77.0/24` to `192.168.50.0/24` in AP-over-Ethernet mode.
2. The same change in repeater mode with an active WiFi uplink.
3. Reject overlap with `192.168.1.0/24` Ethernet LAN.
4. Reject overlap with the active WiFi uplink subnet.
5. Reject malformed, public, loopback, link-local and non-`/24` CIDRs.
6. Reject invalid gateway and reversed/out-of-range DHCP pools.
7. Simulate dnsmasq validation failure and confirm complete rollback.
8. Simulate nftables validation failure and confirm complete rollback.
9. Reboot and verify that gateway, DHCP pool, NAT and UI summary persist.
10. Switch AP-Ethernet -> repeater -> AP-Ethernet and verify the custom subnet
    survives both transitions.

## Deliberately deferred

- IPv6 prefix and router-advertisement configuration.
- Multiple hotspot subnets or VLANs.
- Prefixes other than `/24`.
- Automatic browser migration when management occurs through the old hotspot
  gateway.
- Importing arbitrary legacy dnsmasq configurations.

## Implemented status on Ubuntu 26.04 (2026-07-21)

The first `/24` version is implemented as the dedicated `/dhcp_setting` page.
The approved UI differs from the early disclosure/confirmation sketch:

- the `/24` suffix and derived gateway are always read-only;
- changing the network address derives gateway `.1`, updates the DHCP pool
  prefix and preserves the pool host portions;
- AP interface remains read-only;
- DNS policy, advertised DNS, upstream DNS and lease time are editable;
- Save uses the same spinner and central notification as AP Configuration.

The active helper is named `openap-apply-dhcp-settings`. It performs duplicate
validation in Python, uses the networkd file reported active by `networkctl`,
validates generated dnsmasq configuration, creates timestamped backups and
rolls the file set back if application fails. Apply is scheduled after the web
response because changing the AP gateway can otherwise strand the HTTP request.
An authenticated JSON status endpoint prevents optimistic success messages.

Dashboard integration is complete: DHCP range/lease/DNS are parsed from the
active dnsmasq file and lease database; Network Topology reads the live AP
address; NAT health reads the configured subnet instead of a hard-coded
default.

Validated sequence:

1. Saved `10.99.130.0/24` with gateway `10.99.130.1` and pool `.50-.200`.
2. Confirmed networkd, dnsmasq and nftables use the new subnet.
3. Switched AP-Ethernet to WiFi repeater.
4. Confirmed the custom hotspot subnet persisted and the uplink received
   `10.99.100.123/24`.

Still recommended before treating this as release-complete: collision checks
against every local route/interface, explicit nftables syntax pre-validation,
lease pruning after subnet changes and a reboot persistence test with the
custom subnet.
