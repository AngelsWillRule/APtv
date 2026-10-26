#!/bin/sh
set -eu

profile=/etc/openap/repeater.ini
state_dir=/run/openap
state_file="$state_dir/uplink-watchdog.env"
failure_file="$state_dir/uplink-watchdog.failures"
restart_file="$state_dir/uplink-watchdog.last-restart"
lock_file="$state_dir/uplink-watchdog.lock"

mkdir -p "$state_dir"
exec 9>"$lock_file"
flock -n 9 || exit 0

ini_value() {
  awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null
}

write_state() {
  status="$1"
  reason="$2"
  interface="${3:-}"
  failures="${4:-0}"
  internet="${5:-unknown}"
  tmp="$(mktemp "$state_dir/uplink-watchdog.XXXXXX")"
  {
    printf 'checked_at=%s\n' "$(date +%s)"
    printf 'status=%s\n' "$status"
    printf 'reason=%s\n' "$reason"
    printf 'interface=%s\n' "$interface"
    printf 'failures=%s\n' "$failures"
    printf 'internet=%s\n' "$internet"
    printf 'ethernet_interface=%s\n' "${ethernet_interface:-}"
    printf 'ethernet_status=%s\n' "${ethernet_status:-unknown}"
    printf 'ethernet_reason=%s\n' "${ethernet_reason:-Not checked}"
  } > "$tmp"
  chmod 0644 "$tmp"
  mv "$tmp" "$state_file"
}

mode="$(ini_value current || true)"
if [ "$mode" != repeater_wifi ]; then
  rm -f "$failure_file"
  write_state inactive "Repeater Mode is not active" "" 0 unknown
  exit 0
fi

configured_iface="$(ini_value uplink || true)"
expected_mac="$(ini_value uplink_mac | tr A-F a-f || true)"
ethernet_interface="$(ini_value ethernet || true)"
ethernet_gateway="$(ini_value ethernet_gateway || true)"
ethernet_status=unknown
ethernet_reason="Not checked"
ethernet_failure_file="$state_dir/ethernet-fallback.failures"

check_ethernet_fallback() {
  ethernet_status=unavailable
  ethernet_reason="Ethernet interface is not configured"
  [ -n "$ethernet_interface" ] || return 0
  [ -d "/sys/class/net/$ethernet_interface" ] || {
    ethernet_reason="Ethernet interface is missing"
    return 0
  }
  [ "$(cat "/sys/class/net/$ethernet_interface/carrier" 2>/dev/null || echo 0)" = 1 ] || {
    ethernet_reason="Ethernet cable is disconnected"
    return 0
  }
  ethernet_ip="$(ip -4 -o address show dev "$ethernet_interface" scope global | awk 'NR == 1 {split($4, address, "/"); print address[1]}')"
  [ -n "$ethernet_ip" ] || {
    ethernet_reason="Ethernet has no IPv4 address"
    return 0
  }
  [ -n "$ethernet_gateway" ] || {
    ethernet_reason="Ethernet gateway is not configured"
    return 0
  }
  ping -I "$ethernet_interface" -c 1 -W 2 "$ethernet_gateway" >/dev/null 2>&1 || {
    ethernet_reason="Ethernet gateway is not reachable"
    return 0
  }

  # Probe only these public IPs through Ethernet. Temporary /32 routes are
  # required because DHCP removes the WiFi default route during an outage;
  # they leave the dashboard and every other destination on existing routes.
  probe_routes=""
  for probe_target in 1.1.1.1 9.9.9.9 8.8.8.8; do
    if ip route add "$probe_target/32" via "$ethernet_gateway" dev "$ethernet_interface" src "$ethernet_ip" \
      >/dev/null 2>&1; then
      probe_routes="$probe_routes $probe_target"
    fi
  done
  [ -n "$probe_routes" ] || {
    ethernet_reason="Unable to prepare the isolated Ethernet probe"
    return 0
  }
  sleep 1
  ethernet_probe_ready=0
  for probe_round in 1 2; do
    for probe_target in $probe_routes; do
      if ping -I "$ethernet_interface" -c 1 -W 2 "$probe_target" >/dev/null 2>&1; then
        ethernet_probe_ready=1
        break 2
      fi
    done
  done
  if [ "$ethernet_probe_ready" -eq 1 ]; then
    ethernet_status=ready
    ethernet_reason="Ethernet Internet connection is available"
    rm -f "$ethernet_failure_file"
  else
    ethernet_failures="$(cat "$ethernet_failure_file" 2>/dev/null || echo 0)"
    case "$ethernet_failures" in ''|*[!0-9]*) ethernet_failures=0 ;; esac
    ethernet_failures=$((ethernet_failures + 1))
    printf '%s\n' "$ethernet_failures" > "$ethernet_failure_file"
    if [ "$ethernet_failures" -lt 2 ]; then
      ethernet_status=checking
      ethernet_reason="Verifying Ethernet Internet access"
    else
      ethernet_reason="Ethernet is connected, but Internet is not reachable"
    fi
  fi
  for probe_target in $probe_routes; do
    ip route del "$probe_target/32" via "$ethernet_gateway" dev "$ethernet_interface" src "$ethernet_ip" \
      2>/dev/null || true
  done
}
actual_iface=""
for path in /sys/class/net/*; do
  [ -r "$path/address" ] || continue
  [ "$(tr A-F a-f < "$path/address")" = "$expected_mac" ] || continue
  actual_iface="$(basename "$path")"
  break
done

failures="$(cat "$failure_file" 2>/dev/null || echo 0)"
case "$failures" in ''|*[!0-9]*) failures=0 ;; esac

record_failure() {
  reason="$1"
  iface="${2:-$configured_iface}"
  failures=$((failures + 1))
  printf '%s\n' "$failures" > "$failure_file"
  check_ethernet_fallback
  write_state degraded "$reason" "$iface" "$failures" unavailable

  [ "$failures" -ge 3 ] || exit 0
  now="$(date +%s)"
  last_restart="$(cat "$restart_file" 2>/dev/null || echo 0)"
  case "$last_restart" in ''|*[!0-9]*) last_restart=0 ;; esac
  [ $((now - last_restart)) -ge 60 ] || exit 0
  printf '%s\n' "$now" > "$restart_file"
  systemctl restart openap-uplink.service
  exit 0
}

[ -n "$actual_iface" ] || record_failure "Uplink interface is missing" "$configured_iface"
[ "$actual_iface" = "$configured_iface" ] || {
  check_ethernet_fallback
  write_state degraded "Uplink interface name changed; role regeneration is required" "$actual_iface" "$failures" unavailable
  exit 0
}

systemctl is-active --quiet openap-uplink.service \
  || record_failure "Uplink service is inactive" "$actual_iface"

iw dev "$actual_iface" link 2>/dev/null | grep -q '^Connected to ' \
  || record_failure "Uplink WiFi is not associated" "$actual_iface"

ip -4 -o address show dev "$actual_iface" scope global | grep -q ' inet ' \
  || record_failure "Uplink has no IPv4 address" "$actual_iface"

default_route="$(ip -4 route show default dev "$actual_iface" | head -1)"
[ -n "$default_route" ] || record_failure "Uplink has no default route" "$actual_iface"

# In repeater mode all hotspot traffic must follow the Wi-Fi UPLINK selected
# by the NAT rules. networkd may recreate the management Ethernet default
# route after a restart or rollback, so remove that competing route once the
# Wi-Fi route is known to be ready.
[ -z "$ethernet_interface" ] \
  || ip -4 route del default dev "$ethernet_interface" >/dev/null 2>&1 || true

rm -f "$failure_file"
gateway="$(printf '%s\n' "$default_route" | awk '/ via / {print $3; exit}')"
internet=unknown
reason="Uplink ready"
if [ -n "$gateway" ] && ping -I "$actual_iface" -c 1 -W 2 "$gateway" >/dev/null 2>&1; then
  if ping -I "$actual_iface" -c 1 -W 2 1.1.1.1 >/dev/null 2>&1 \
    || ping -I "$actual_iface" -c 1 -W 2 9.9.9.9 >/dev/null 2>&1; then
    internet=ready
  else
    internet=unavailable
    reason="Uplink associated, but Internet reachability failed"
  fi
else
  internet=unavailable
  reason="Uplink gateway is not reachable"
fi

if [ "$internet" = ready ]; then
  rm -f "$ethernet_failure_file"
  write_state ready "$reason" "$actual_iface" 0 "$internet"
else
  # Do not restart a healthy WiFi/DHCP dataplane merely because an upstream
  # network blocks ICMP or temporarily lacks Internet access.
  check_ethernet_fallback
  write_state impaired "$reason" "$actual_iface" 0 "$internet"
fi
