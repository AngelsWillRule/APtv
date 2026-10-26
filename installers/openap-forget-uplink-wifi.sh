#!/bin/sh
set -eu
iface="${1:-}"
requested_ssid="${2:-}"
profile=/etc/openap/repeater.ini
fail() { echo "$1" >&2; exit 1; }
echo "$iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid uplink interface"
[ -n "$requested_ssid" ] || fail "Saved uplink SSID is required"
[ "$(printf %s "$requested_ssid" | wc -c)" -le 32 ] || fail "Saved uplink SSID is too long"
[ -r "$profile" ] || fail "OpenAP profile not found"
configured="$(awk -F ' *= *' '$1 == "uplink" {print $2}' "$profile" | head -1)"
[ "$iface" = "$configured" ] || fail "Interface is not the configured OpenAP uplink"
ethernet="$(awk -F ' *= *' '$1 == "ethernet" {print $2}' "$profile" | head -1)"
gateway="$(awk -F ' *= *' '$1 == "ethernet_gateway" {print $2}' "$profile" | head -1)"
ap_mac="$(awk -F ' *= *' '$1 == "ap_mac" {print $2}' "$profile" | head -1)"
[ -n "$gateway" ] || gateway="$(ip route show default dev "$ethernet" | awk '/default via/ {print $3; exit}')"
conf="/etc/wpa_supplicant/wpa_supplicant-${iface}.conf"
store_dir="/etc/openap/uplinks"
key="$(printf %s "$requested_ssid" | sha256sum | awk '{print $1}')"
saved_conf="$store_dir/$key.conf"
profile_ssid() {
  sed -n 's/^[[:space:]]*ssid="\(.*\)"[[:space:]]*$/\1/p' "$1" | head -1
}
saved_conf_present=0
if [ -f "$saved_conf" ]; then
  [ "$(profile_ssid "$saved_conf")" = "$requested_ssid" ] || fail "Saved uplink profile mismatch"
  saved_conf_present=1
elif [ ! -f "$conf" ] || [ "$(profile_ssid "$conf")" != "$requested_ssid" ]; then
  fail "Saved uplink profile not found"
fi
backup="/etc/openap/backups/forgotten-uplink-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup"
/usr/local/sbin/openap-apply-ap-ethernet --apply "$ethernet" "$gateway" "$ap_mac"
if [ -f "$conf" ] && [ "$(profile_ssid "$conf")" = "$requested_ssid" ]; then
  mv "$conf" "$backup/removed-wpa_supplicant-${iface}.conf"
fi
if [ "$saved_conf_present" -eq 1 ]; then
  mv "$saved_conf" "$backup/removed-saved-$key.conf"
else
  printf '%s\n' "Recovered orphaned active uplink profile; persistent saved copy was already missing." > "$backup/orphaned-profile-recovery.txt"
fi
echo "Saved uplink '$requested_ssid' removed. Backup: $backup"
