#!/bin/sh
set -eu

fail() {
  echo "$1" >&2
  exit 1
}

IFS= read -r iface || fail "Missing WiFi interface"
IFS= read -r txpower || fail "Missing transmit-power value"
IFS= read -r expected_channel || expected_channel=""

case "$iface" in
  ''|*[!A-Za-z0-9_.:-]*) fail "Invalid WiFi interface" ;;
esac

[ -e "/sys/class/net/$iface" ] || fail "WiFi interface not found"
/usr/sbin/iw dev 2>/dev/null \
  | awk '$1 == "Interface" {print $2}' \
  | grep -Fxq "$iface" || fail "Interface is not wireless"

if [ "$txpower" = "auto" ]; then
  exec /usr/sbin/iw dev "$iface" set txpower auto
fi

case "$txpower" in
  ''|*[!0-9]*) fail "Transmit power must be auto or an integer from 1 to 30 dBm" ;;
esac
[ "$txpower" -ge 1 ] && [ "$txpower" -le 30 ] \
  || fail "Transmit power must be between 1 and 30 dBm"

case "$expected_channel" in
  ''|*[!0-9]*) [ -z "$expected_channel" ] || fail "Invalid expected WiFi channel" ;;
esac

channel=""
attempt=0
while [ "$attempt" -lt 20 ]; do
  channel=$(/usr/sbin/iw dev "$iface" info 2>/dev/null \
    | awk '$1 == "channel" {print $2; exit}')
  if [ -z "$expected_channel" ] || [ "$channel" = "$expected_channel" ]; then
    break
  fi
  attempt=$((attempt + 1))
  sleep 0.5
done
[ -n "$channel" ] || fail "Unable to determine the active WiFi channel"
[ -z "$expected_channel" ] || [ "$channel" = "$expected_channel" ] \
  || fail "WiFi channel did not converge (requested $expected_channel, active $channel)"

phy=$(/usr/sbin/iw dev 2>/dev/null \
  | awk -v iface="$iface" '
      /^phy#/ { current = $1; sub(/^phy#/, "phy", current) }
      $1 == "Interface" && $2 == iface { print current; exit }
    ')
[ -n "$phy" ] || fail "Unable to determine the WiFi physical device"

max_dbm=$(/usr/sbin/iw phy "$phy" info 2>/dev/null \
  | awk -v channel="$channel" '
      $0 ~ "\\[" channel "\\]" {
        for (field = 1; field < NF; field++) {
          if ($field ~ /^\([0-9.]+$/ && $(field + 1) == "dBm)") {
            sub(/^\(/, "", $field)
            print int($field); exit
          }
        }
      }
    ')
[ -n "$max_dbm" ] || fail "Unable to determine the transmit-power limit for channel $channel"
[ "$txpower" -le "$max_dbm" ] \
  || fail "Transmit power exceeds the ${max_dbm} dBm limit for channel $channel"

# Some drivers report a nominal value above the channel's regulatory limit
# (for example brcmfmac reports 31 dBm on a 20 dBm channel). Normalize that
# value before deciding whether a fixed-power request is actually required.
current_dbm=$(/usr/sbin/iw dev "$iface" info 2>/dev/null \
  | awk '$1 == "txpower" {print int($2); exit}')
if [ -n "$current_dbm" ] && [ "$current_dbm" -gt "$max_dbm" ]; then
  current_dbm="$max_dbm"
fi
[ "$current_dbm" = "$txpower" ] && exit 0

exec /usr/sbin/iw dev "$iface" set txpower fixed "$((txpower * 100))"
