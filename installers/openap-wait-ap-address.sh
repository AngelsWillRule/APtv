#!/bin/sh
set -eu

profile="${OPENAP_PROFILE:-/etc/openap/repeater.ini}"
timeout="${OPENAP_AP_ADDRESS_TIMEOUT:-30}"

case "$timeout" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP AP-address timeout: $timeout" >&2
    exit 1
    ;;
esac

ap_iface="$(awk -F ' *= *' '$1 == "ap" {print $2; exit}' "$profile" 2>/dev/null || true)"
gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2; exit}' "$profile" 2>/dev/null || true)"
mode="$(awk -F ' *= *' '$1 == "current" {print $2; exit}' "$profile" 2>/dev/null || true)"
bridge_iface="$(awk -F ' *= *' '$1 == "bridge" {print $2; exit}' "$profile" 2>/dev/null || true)"
ap_24ghz="$(awk -F ' *= *' '$1 == "ap_24ghz" {print $2; exit}' "$profile" 2>/dev/null || true)"
ap_5ghz="$(awk -F ' *= *' '$1 == "ap_5ghz" {print $2; exit}' "$profile" 2>/dev/null || true)"
ap_mac="$(awk -F ' *= *' '$1 == "ap_mac" {print tolower($2); exit}' "$profile" 2>/dev/null || true)"

valid_iface() {
  case "$1" in ''|*[!A-Za-z0-9_.:-]*) return 1 ;; esac
}

# Cold-plugged PCI/USB radios can be registered as wlanN before their OpenAP
# .link names are applied.  Wait for every configured AP interface instead of
# assuming that network.target implies that udev has finished naming radios.
configured_ifaces=""
for configured_iface in "$ap_iface" "$ap_24ghz" "$ap_5ghz"; do
  [ -n "$configured_iface" ] || continue
  valid_iface "$configured_iface" || {
    echo "Invalid configured OpenAP AP interface: $configured_iface" >&2
    exit 1
  }
  case " $configured_ifaces " in *" $configured_iface "*) continue ;; esac
  configured_ifaces="$configured_ifaces $configured_iface"
done

[ -n "$configured_ifaces" ] || {
  echo "OpenAP AP interface is not configured in $profile" >&2
  exit 1
}

remaining="$timeout"
while [ "$remaining" -gt 0 ]; do
  ready=1
  for configured_iface in $configured_ifaces; do
    [ -d "/sys/class/net/$configured_iface/wireless" ] || ready=0
  done
  [ "$ready" -eq 1 ] && break
  remaining=$((remaining - 1))
  sleep 1
done
[ "$remaining" -gt 0 ] || {
  echo "Timed out waiting for configured OpenAP AP interfaces:$configured_ifaces" >&2
  exit 1
}

if [ -n "$ap_mac" ] && [ -r "/sys/class/net/$ap_iface/address" ]; then
  actual_ap_mac="$(tr '[:upper:]' '[:lower:]' < "/sys/class/net/$ap_iface/address")"
  [ "$actual_ap_mac" = "$ap_mac" ] || {
    echo "OpenAP AP interface $ap_iface has unexpected MAC $actual_ap_mac" >&2
    exit 1
  }
fi

# Ethernet Bridge deliberately has no private hotspot gateway and dnsmasq is
# skipped by its mode guard.  hostapd only needs the configured radios to have
# reached their final persistent names.
if [ "$mode" = "ap_ethernet_bridge" ]; then
  exit 0
fi

if [ -n "$bridge_iface" ]; then
  [ -n "$bridge_iface" ] || bridge_iface=br0
  remaining="$timeout"
  while [ "$remaining" -gt 0 ]; do
    if ip -4 -o addr show dev "$bridge_iface" 2>/dev/null \
      | awk -v gateway="$gateway" '{ split($4, address, "/"); if (address[1] == gateway) found=1 } END { exit !found }'; then
      exit 0
    fi
    remaining=$((remaining - 1))
    sleep 1
  done
  echo "Timed out waiting for OpenAP gateway $gateway on bridge $bridge_iface" >&2
  exit 1
fi

[ -n "$ap_iface" ] || {
  echo "OpenAP AP interface is not configured in $profile" >&2
  exit 1
}
[ -n "$gateway" ] || {
  echo "OpenAP hotspot gateway is not configured in $profile" >&2
  exit 1
}

remaining="$timeout"
while [ "$remaining" -gt 0 ]; do
  if [ -e "/sys/class/net/$ap_iface" ] \
    && ip -4 -o addr show dev "$ap_iface" 2>/dev/null \
      | awk -v gateway="$gateway" '{ split($4, address, "/"); if (address[1] == gateway) found=1 } END { exit !found }'; then
    exit 0
  fi
  remaining=$((remaining - 1))
  sleep 1
done

echo "Timed out waiting for OpenAP gateway $gateway on $ap_iface" >&2
exit 1
