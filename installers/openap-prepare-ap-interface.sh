#!/bin/sh
set -eu

profile=/etc/openap/repeater.ini
timeout="${OPENAP_AP_INTERFACE_TIMEOUT:-30}"
action="${1:---prepare}"

case "$action" in
  --prepare|--ensure-address) ;;
  *)
    echo "Invalid OpenAP AP-interface action: $action" >&2
    exit 2
    ;;
esac

case "$timeout" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP AP-interface timeout: $timeout" >&2
    exit 1
    ;;
esac

profile_value() {
  awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile"
}

[ -r "$profile" ] || {
  echo "OpenAP profile is not readable: $profile" >&2
  exit 1
}

ap_iface="$(profile_value ap)"
ap_mac="$(profile_value ap_mac | tr '[:upper:]' '[:lower:]')"
ap_24ghz="$(profile_value ap_24ghz)"
ap_5ghz="$(profile_value ap_5ghz)"
current_mode="$(profile_value current)"
gateway="$(profile_value gateway)"
subnet="$(profile_value subnet)"
bridge_iface="$(profile_value bridge)"
prefix="${subnet#*/}"

case "$ap_iface" in
  ''|*[!A-Za-z0-9_.:-]*)
    echo "Invalid OpenAP AP interface: $ap_iface" >&2
    exit 1
    ;;
esac
case "$ap_mac" in
  [0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]) ;;
  *)
    echo "Invalid OpenAP AP MAC address: $ap_mac" >&2
    exit 1
    ;;
esac
case "$prefix" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP hotspot subnet: $subnet" >&2
    exit 1
    ;;
esac
[ "$prefix" -ge 1 ] && [ "$prefix" -le 32 ] || {
  echo "Invalid OpenAP hotspot prefix: $prefix" >&2
  exit 1
}
case "$bridge_iface" in
  ''|*[!A-Za-z0-9_.:-]*)
    [ -z "$bridge_iface" ] || {
      echo "Invalid OpenAP hotspot bridge: $bridge_iface" >&2
      exit 1
    }
    ;;
esac

remaining="$timeout"
while [ "$remaining" -gt 0 ]; do
  if [ -r "/sys/class/net/$ap_iface/address" ]; then
    actual_mac="$(tr '[:upper:]' '[:lower:]' < "/sys/class/net/$ap_iface/address")"
    if [ "$actual_mac" = "$ap_mac" ]; then
      break
    fi
    echo "OpenAP AP interface $ap_iface has unexpected MAC $actual_mac" >&2
    exit 1
  fi
  remaining=$((remaining - 1))
  sleep 1
done

[ -r "/sys/class/net/$ap_iface/address" ] || {
  echo "Timed out waiting for OpenAP AP interface $ap_iface" >&2
  exit 1
}

# NetworkManager persists its global Wi-Fi radio state separately from rfkill.
# The systemd unit orders this helper after NetworkManager so both states can
# be released deterministically during a cold boot.
if command -v /usr/bin/nmcli >/dev/null 2>&1 \
  && /usr/bin/systemctl is-active --quiet NetworkManager.service; then
  /usr/bin/nmcli radio wifi on
fi
/usr/sbin/rfkill unblock wifi

if [ "$current_mode" = ap_ethernet_bridge ]; then
  # The routed dual-band topology uses openap0.  It must not retain its old
  # hotspot address after hostapd moves the radios to the upstream bridge.
  if [ "$bridge_iface" != openap0 ] && [ -d /sys/class/net/openap0/bridge ]; then
    /usr/sbin/ip -4 address flush dev openap0 scope global
    /usr/sbin/ip link set dev openap0 down
  fi
  /usr/sbin/ip link set dev "$ap_iface" up
  exit 0
fi

if [ -n "$bridge_iface" ]; then
  if [ ! -e "/sys/class/net/$bridge_iface" ]; then
    /usr/sbin/ip link add name "$bridge_iface" type bridge
  fi
  [ -d "/sys/class/net/$bridge_iface/bridge" ] || {
    echo "OpenAP hotspot interface is not a bridge: $bridge_iface" >&2
    exit 1
  }
  /usr/sbin/ip link set dev "$bridge_iface" type bridge stp_state 0
  /usr/sbin/ip -4 -o address show dev "$bridge_iface" \
    | awk -v expected="$gateway/$prefix" '$4 != expected {print $4}' \
    | while IFS= read -r stale_address; do
        [ -n "$stale_address" ] || continue
        /usr/sbin/ip -4 address del "$stale_address" dev "$bridge_iface"
      done
  # networkd may assign the bridge address concurrently during boot.  A
  # check followed by "address add" races with that assignment and can fail
  # with EEXIST.  "address replace" is idempotent whether the address is
  # already present or appears while this command is running.
  /usr/sbin/ip address replace "$gateway/$prefix" dev "$bridge_iface"
  /usr/sbin/ip link set dev "$bridge_iface" up
  configured_ap_ifaces=""
  for configured_ap in "$ap_iface" "$ap_24ghz" "$ap_5ghz"; do
    [ -n "$configured_ap" ] || continue
    case " $configured_ap_ifaces " in *" $configured_ap "*) continue ;; esac
    [ -e "/sys/class/net/$configured_ap" ] || {
      echo "Configured OpenAP AP interface is absent: $configured_ap" >&2
      exit 1
    }
    configured_ap_ifaces="$configured_ap_ifaces $configured_ap"
    # A bridge member must not retain either the current or a stale hotspot
    # gateway after the subnet is changed.
    /usr/sbin/ip -4 address flush dev "$configured_ap" scope global
    /usr/sbin/ip link set dev "$configured_ap" up
  done
  /usr/sbin/ip -4 -o address show dev "$bridge_iface" \
    | awk -v gateway="$gateway" -v prefix="$prefix" '
        $4 == gateway "/" prefix { found=1 }
        END { exit found ? 0 : 1 }
      '
  exit $?
fi

if [ "$action" = "--ensure-address" ]; then
  # hostapd is already running. Never cycle the link here: doing so terminates
  # the daemon on Raspberry brcmfmac radios. If hostapd preserved the address,
  # the post-start check is intentionally a no-op.
  if /usr/sbin/ip -4 -o address show dev "$ap_iface" \
    | awk -v gateway="$gateway" -v prefix="$prefix" '
        $4 == gateway "/" prefix { found=1 }
        END { exit found ? 0 : 1 }
      '; then
    exit 0
  fi
  /usr/sbin/ip -4 address flush dev "$ap_iface"
  /usr/sbin/ip address add "$gateway/$prefix" dev "$ap_iface"
  /usr/sbin/ip link set dev "$ap_iface" up
else
  /usr/sbin/ip link set dev "$ap_iface" down
  /usr/sbin/ip address flush dev "$ap_iface"
  /usr/sbin/ip address add "$gateway/$prefix" dev "$ap_iface"
  /usr/sbin/ip link set dev "$ap_iface" up
fi

/usr/sbin/ip -4 -o address show dev "$ap_iface" \
  | awk -v gateway="$gateway" '
      { split($4, address, "/"); if (address[1] == gateway) found=1 }
      END { exit found ? 0 : 1 }
    '
