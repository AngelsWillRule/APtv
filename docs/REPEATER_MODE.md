# Wi-Fi Repeater Mode

Repeater Mode is configured after the initial installation. The recommended
baseline is AP over Ethernet; only test repeater behavior after hotspot, DHCP,
DNS and dashboard access are healthy.

## Required roles

1. an AP-capable interface continues serving the APtv hotspot;
2. a separate managed-mode interface associates with the upstream Wi-Fi.

The dashboard scans for upstream networks, stores approved uplink profiles and
keeps the hotspot addressing stable while the uplink changes. Saved credentials
must not be returned to the browser.

## Limitations

- two independent radios are normally required;
- WPA3-only uplinks are not claimed as supported by the first public release;
- WPA2/WPA3 transition mode may work through its WPA2 path;
- bridge behavior is driver-dependent;
- interface names can change after reboot or USB reordering.

## Validation

After enabling Repeater Mode, verify in this order:

1. the AP beacon remains visible;
2. a client receives a DHCP address;
3. the dashboard remains reachable;
4. the uplink associates and receives an address;
5. DNS resolves through the selected mode;
6. Internet forwarding works.

If the uplink fails, return to the last known-good mode before changing another
setting. Capture sanitized service and route status for troubleshooting.
