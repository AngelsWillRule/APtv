#!/bin/sh
set -eu

profile="${OPENAP_PROFILE:-/etc/openap/repeater.ini}"
[ -r "$profile" ] || exit 0

mode="$(awk -F ' *= *' '$1 == "current" {print $2; exit}' "$profile" 2>/dev/null || true)"

# Ethernet Bridge clients use the upstream LAN directly.  DHCP/DNS and the
# dedicated OpenAP NAT table are Routed-only services, so skip their enabled
# units cleanly at boot while Bridge mode is active.
[ "$mode" != ap_ethernet_bridge ]
