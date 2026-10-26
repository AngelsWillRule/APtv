#!/bin/sh
set -eu

profile="/etc/openap/repeater.ini"

fail() {
  echo "$1" >&2
  exit 1
}

ini_get() {
  key="$1"
  awk -F ' *= *' -v key="$key" '$1 == key {print $2; exit}' "$profile" 2>/dev/null
}

if_by_mac() {
  wanted="$(printf '%s' "$1" | tr 'A-F' 'a-f')"
  [ -n "$wanted" ] || return 1

  for path in /sys/class/net/*; do
    [ -e "$path/address" ] || continue
    name="${path##*/}"
    mac="$(tr 'A-F' 'a-f' < "$path/address")"
    [ "$mac" = "$wanted" ] || continue
    printf '%s\n' "$name"
    return 0
  done

  return 1
}

set_ini_key() {
  key="$1"
  value="$2"
  tmp="$(mktemp)"
  awk -F ' *= *' -v key="$key" -v value="$value" '
    $1 == key {
      print key " = " value
      done = 1
      next
    }
    { print }
    END {
      if (!done) {
        print key " = " value
      }
    }
  ' "$profile" > "$tmp"
  cat "$tmp" > "$profile"
  rm -f "$tmp"
}

ap_mac="$(ini_get ap_mac)"
uplink_mac="$(ini_get uplink_mac)"
ap_iface="$(if_by_mac "$ap_mac" || true)"
uplink_iface="$(if_by_mac "$uplink_mac" || true)"

[ -n "$ap_iface" ] || fail "AP adapter with MAC $ap_mac not found"
[ -n "$uplink_iface" ] || fail "Uplink adapter with MAC $uplink_mac not found"
[ "$ap_iface" != "$uplink_iface" ] || fail "AP and uplink resolved to the same interface"

backup_dir="/etc/openap/backups/interface-resolve-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
for path in \
  "$profile" \
  /etc/hostapd/hostapd.conf \
  /etc/dnsmasq.d/openap-repeater.conf \
  /etc/systemd/network/20-wlan0-ap.network
do
  [ -e "$path" ] && cp -a "$path" "$backup_dir/"
done

set_ini_key ap "$ap_iface"
set_ini_key uplink "$uplink_iface"
chown www-data:www-data "$profile" 2>/dev/null || true
chmod 0640 "$profile" 2>/dev/null || true

if [ -f /etc/hostapd/hostapd.conf ]; then
  sed -i "s/^interface=.*/interface=$ap_iface/" /etc/hostapd/hostapd.conf
fi

if [ -f /etc/dnsmasq.d/openap-repeater.conf ]; then
  sed -i "s/^interface=.*/interface=$ap_iface/" /etc/dnsmasq.d/openap-repeater.conf
fi

if [ -f /etc/systemd/network/20-wlan0-ap.network ]; then
  sed -i "s/^Name=.*/Name=$ap_iface/" /etc/systemd/network/20-wlan0-ap.network
fi

systemctl restart systemd-networkd.service
sleep 2
systemctl restart dnsmasq.service
systemctl restart hostapd.service

echo "Resolved AP role: $ap_mac -> $ap_iface"
echo "Resolved uplink role: $uplink_mac -> $uplink_iface"
echo "Backup: $backup_dir"
