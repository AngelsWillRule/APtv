#!/bin/sh
set -eu

iface="${1:-}"
fail() {
  echo "$1" >&2
  exit 1
}

echo "$iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid WiFi interface"
[ -e "/sys/class/net/$iface" ] || fail "WiFi interface not found"
iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$iface" \
  || fail "Interface is not wireless"

profile=/etc/openap/repeater.ini
iface_mac="$(tr A-F a-f < "/sys/class/net/$iface/address")"
ap_mac="$(awk -F ' *= *' '$1 == "ap_mac" {print tolower($2)}' "$profile" 2>/dev/null | head -1)"
[ -z "$ap_mac" ] || [ "$iface_mac" != "$ap_mac" ] || fail "Refusing to scan with the OpenAP hotspot interface"

uplink="$(awk -F ' *= *' '$1 == "uplink" {print $2}' "$profile" 2>/dev/null | head -1)"
uplink_mac="$(awk -F ' *= *' '$1 == "uplink_mac" {print tolower($2)}' "$profile" 2>/dev/null | head -1)"
owned=0
[ -n "$uplink" ] && [ "$uplink" = "$iface" ] && owned=1
[ -n "$uplink_mac" ] && [ "$uplink_mac" != "-" ] && [ "$uplink_mac" = "$iface_mac" ] && owned=1
if [ "$owned" -eq 0 ]; then
  active_ssid="$(iw dev "$iface" link 2>/dev/null | awk -F 'SSID: ' '/SSID:/ {print $2; exit}')"
  active_ipv4="$(ip -4 -o addr show dev "$iface" 2>/dev/null | awk '{print $4; exit}')"
  [ -z "$active_ssid" ] && [ -z "$active_ipv4" ] \
    || fail "Refusing to scan with an interface already in use outside OpenAP"
fi

ip link set dev "$iface" up
if ! timeout 20 iw dev "$iface" scan; then
  fail "WiFi scan timed out or failed on $iface"
fi
