#!/bin/sh
set -eu

profile=/etc/openap/repeater.ini
[ -r "$profile" ] || exit 0

ini_value() {
  awk -F ' *= *' -v section="$1" -v key="$2" '
    BEGIN { current = "" }
    /^\[/ { current = substr($0, 2, length($0) - 2); next }
    current == section && $1 == key { print $2; exit }
  ' "$profile"
}

mode="$(ini_value mode current)"
address_backend="$(ini_value runtime address_backend)"
# Routed NAT and Wi-Fi repeater mode both need the AP radio attached to the
# local bridge: the bridge carries the hotspot gateway and DHCP/DNS service.
# The ExecStartPost hook is also needed for repeater mode because hostapd can
# be restarted independently of the repeater apply transaction; without it,
# hostapd advertises the SSID while the radio is left outside openap0.
[ "$mode" = ap_ethernet ] || [ "$mode" = ap_ethernet_bridge ] || \
  [ "$mode" = repeater_wifi ] || exit 0
# Raspberry native AP-address mode normally assigns the gateway directly to
# one AP interface. When dual band is active, the profile explicitly names
# openap0 so both AP radios can share one gateway and DHCP domain.
if [ "$address_backend" = openap-ap-address ] \
  && [ -z "$(ini_value interfaces bridge)" ]; then
  exit 0
fi

network_bridge="$(ini_value network bridge)"
interfaces_bridge="$(ini_value interfaces bridge)"
[ -n "$network_bridge" ] || {
  echo "openap-attach-ap-bridge: missing [network] bridge in $profile" >&2
  exit 1
}
[ -n "$interfaces_bridge" ] || {
  echo "openap-attach-ap-bridge: missing [interfaces] bridge in $profile" >&2
  exit 1
}
[ "$network_bridge" = "$interfaces_bridge" ] || {
  echo "openap-attach-ap-bridge: inconsistent bridge values: network=$network_bridge interfaces=$interfaces_bridge" >&2
  exit 1
}
bridge="$network_bridge"
bridge_source="profile:[network]+[interfaces]"

case "$bridge" in
  ''|*[!A-Za-z0-9_.:-]*)
    echo "openap-attach-ap-bridge: invalid bridge interface: $bridge" >&2
    exit 1
    ;;
esac

[ -d "/sys/class/net/$bridge/bridge" ] || {
  echo "openap-attach-ap-bridge: target is not an existing Linux bridge: $bridge" >&2
  exit 1
}

ap_interfaces=""
for key in ap_24ghz ap_5ghz ap; do
  iface="$(ini_value interfaces "$key")"
  [ -n "$iface" ] || continue
  case " $ap_interfaces " in
    *" $iface "*) continue ;;
  esac
  case "$iface" in
    ''|lo|*[!A-Za-z0-9_.:-]*)
      echo "openap-attach-ap-bridge: invalid AP interface: $iface" >&2
      exit 1
      ;;
  esac
  ap_interfaces="$ap_interfaces $iface"
done
ap_interfaces="${ap_interfaces# }"
[ -n "$ap_interfaces" ] || exit 0

echo "openap-attach-ap-bridge: attaching $ap_interfaces to $bridge (source=$bridge_source)" >&2

tries=30
while [ "$tries" -gt 0 ]; do
  [ -d "/sys/class/net/$bridge" ] || {
    tries=$((tries - 1))
    sleep 1
    continue
  }
  ready=1
  for iface in $ap_interfaces; do
    [ -d "/sys/class/net/$iface" ] || ready=0
  done
  [ "$ready" -eq 1 ] && break
  tries=$((tries - 1))
  sleep 1
done

[ "$tries" -gt 0 ] || {
  echo "openap-attach-ap-bridge: timed out waiting for bridge/AP interfaces" >&2
  exit 1
}

for iface in $ap_interfaces; do
  ip link set dev "$iface" up
  current_master="$(basename "$(readlink -f "/sys/class/net/$iface/master" 2>/dev/null || true)")"
  if [ "$current_master" != "$bridge" ]; then
    ip link set dev "$iface" master "$bridge"
  fi
  actual_master="$(basename "$(readlink -f "/sys/class/net/$iface/master" 2>/dev/null || true)")"
  [ "$actual_master" = "$bridge" ] || {
    echo "openap-attach-ap-bridge: $iface is not attached to $bridge" >&2
    exit 1
  }
done

exit 0
