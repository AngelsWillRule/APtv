#!/bin/sh
set -eu

status_file=/run/openap/interface-role-apply-status
result_file=/run/openap/interface-role-apply-result
profile=/etc/openap/repeater.ini

if [ "${1:-}" = "--delayed" ]; then
  shift
  ap_mac="${1:-}"
  uplink_mac="${2:-}"
  mkdir -p /run/openap
  printf '%s\n' applying > "$status_file"
  : > "$result_file"
  chmod 0644 "$status_file" "$result_file"
  (sleep 2; if "$0" "$ap_mac" "$uplink_mac"; then
      printf '%s\n' success > "$status_file"
    else
      printf '%s\n' failed > "$status_file"
    fi
    chmod 0644 "$status_file" "$result_file"
  ) </dev/null >>/var/log/openap-interface-role-apply.log 2>&1 &
  echo "Interface role change scheduled"
  exit 0
fi

ap_mac="${1:-}"
uplink_mac="${2:-}"

fail() { echo "$1" >&2; exit 1; }
valid_mac() { printf '%s' "$1" | grep -Eiq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$'; }
valid_mac "$ap_mac" || fail "Invalid AP MAC"
valid_mac "$uplink_mac" || fail "Invalid uplink MAC"
[ "$(printf '%s' "$ap_mac" | tr A-F a-f)" != "$(printf '%s' "$uplink_mac" | tr A-F a-f)" ] || fail "AP and uplink must differ"

ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
mode="$(ini_value current)"
old_ap_mac="$(ini_value ap_mac)"
old_uplink_mac="$(ini_value uplink_mac)"

write_result() {
  current_ap="$(ini_value ap)"
  current_uplink="$(ini_value uplink)"
  {
    printf 'ap=%s\n' "$current_ap"
    printf 'uplink=%s\n' "$current_uplink"
    printf 'mode=%s\n' "$(ini_value current)"
  } > "$result_file"
}

apply_roles() {
  requested_ap="$1"
  requested_uplink="$2"
  case "$mode" in
    repeater_wifi)
      /usr/local/sbin/openap-apply-repeater-wifi "$requested_ap" "$requested_uplink"
      ;;
    ap_ethernet)
      ethernet="$(ini_value ethernet)"
      gateway="$(ip -4 route show default dev "$ethernet" | awk '$1 == "default" && $2 == "via" {print $3; exit}')"
      gateway="${gateway:-$(ini_value ethernet_gateway)}"
      [ -n "$gateway" ] || fail "Ethernet gateway is unavailable"
      /usr/local/sbin/openap-apply-ap-ethernet --apply "$ethernet" "$gateway" "$requested_ap" "$requested_uplink"
      ;;
    *) fail "Unsupported OpenAP mode: $mode" ;;
  esac
}

if [ "$(printf '%s' "$ap_mac" | tr A-F a-f)" = "$(printf '%s' "$old_ap_mac" | tr A-F a-f)" ] \
  && [ "$(printf '%s' "$uplink_mac" | tr A-F a-f)" = "$(printf '%s' "$old_uplink_mac" | tr A-F a-f)" ]; then
  write_result
  exit 0
fi

if apply_roles "$ap_mac" "$uplink_mac"; then
  write_result
  exit 0
fi

echo "Role change failed; restoring previous roles" >&2
apply_roles "$old_ap_mac" "$old_uplink_mac" || echo "Automatic role restore failed" >&2
write_result
exit 1
