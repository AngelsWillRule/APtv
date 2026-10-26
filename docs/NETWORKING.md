# Network modes

## AP over Ethernet

The initial installation path serves a Wi-Fi hotspot while the host uses
Ethernet as its uplink. This is the most broadly compatible mode and the
recommended first validation step.

## Routed NAT

Hotspot clients use a private subnet and are routed to the selected uplink.
Internet traffic is masqueraded on the uplink. This mode does not require the
upstream network to share the hotspot layer-2 segment.

## Ethernet bridge

Bridge mode connects the hotspot path to an Ethernet network at layer 2 when the
kernel, Ethernet adapter, Wi-Fi driver and upstream network support it. Some
Wi-Fi drivers cannot bridge station traffic correctly. A successful AP
installation does not guarantee bridge compatibility.

The installer detects whether the Ethernet interface is managed by
`systemd-networkd`, NetworkManager or `ifupdown`. It must not run two competing
network managers on the same interface.

## Wi-Fi Repeater

Repeater Mode requires two independent Wi-Fi roles:

1. an AP-capable radio serving the hotspot;
2. a managed/client radio connected to the upstream Wi-Fi network.

The dashboard hides unsupported role combinations. WPA3-only uplinks are not
claimed as supported by the first public release; WPA2/WPA3 transition mode may
work through its WPA2 path when the adapter permits it.

## Interface names

Do not write configuration assuming `wlan0`, `wlan1`, `eth0` or a fixed USB
name. OpenAP resolves interfaces using capability, MAC identity and the current
host configuration. Names can change after reboot or when USB adapters are
reordered.

## DNS modes

The dashboard can configure local or external DNS behavior. External DNS sends
queries to the selected resolver instead of relying on the upstream router.
DNS selection does not repair a missing DHCP lease, an absent uplink route or a
driver that cannot maintain the hotspot.

## Safe validation order

1. verify the AP radio and hotspot beacon;
2. verify DHCP;
3. verify the dashboard from a hotspot client;
4. verify local DNS and Internet reachability;
5. test the selected uplink;
6. only then test NAT, bridge or repeater transitions.
