#!/bin/sh
set -eu

action="${1:---apply}"
apply_status=/run/openap/ethernet-mode-apply-status

write_apply_status() {
  state="$1"
  target="$2"
  message="${3:-}"
  mkdir -p /run/openap
  status_tmp="/run/openap/.ethernet-mode-apply-status.$$"
  {
    printf 'state = %s\n' "$state"
    printf 'target = %s\n' "$target"
    printf 'updated = %s\n' "$(date +%s)"
    printf 'message = %s\n' "$(printf '%s' "$message" | tr '\n\r=' '   ')"
  } > "$status_tmp"
  chmod 0644 "$status_tmp"
  mv "$status_tmp" "$apply_status"
}

if [ "$action" = --tracked ]; then
  target="${2:-}"
  tracked_action="${3:-}"
  shift 3
  write_apply_status applying "$target"
  tracked_output="$(mktemp /tmp/openap-ethernet-mode.XXXXXX)"
  if "$0" "$tracked_action" "$@" >"$tracked_output" 2>&1; then
    cat "$tracked_output"
    rm -f "$tracked_output"
    write_apply_status success "$target"
    exit 0
  else
    rc=$?
  fi
  message="$(tail -1 "$tracked_output" 2>/dev/null || true)"
  cat "$tracked_output" >&2
  rm -f "$tracked_output"
  write_apply_status failed "$target" "$message"
  exit "$rc"
fi

eth_iface="${2:-}"
gateway_arg="${4:-}"
ap_mac="$(printf '%s' "${3:-}" | tr 'A-F' 'a-f')"
bridge_iface="br0"
bridge_con="openap-bridge"
bridge_port_con="openap-bridge-${eth_iface}"
profile=/etc/openap/repeater.ini
hostapd_conf=/etc/hostapd/hostapd.conf
if [ -f /etc/openap/hostapd/hostapd.conf ]; then
  hostapd_conf=/etc/openap/hostapd/hostapd.conf
fi
nft_conf=/etc/openap/networking/openap.nft
dnsmasq_conf=/etc/dnsmasq.d/openap-repeater.conf
dual_hostapd_configs="/etc/hostapd/openap-ap-24ghz.conf /etc/hostapd/openap-ap-5ghz.conf"

fail() { echo "$1" >&2; exit 1; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
is_wireless() { [ -d "/sys/class/net/$1/wireless" ] || iw dev 2>/dev/null | awk '$1=="Interface"{print $2}' | grep -Fxq "$1"; }

write_bridge_profile() {
  desired_bridge="$1"
  [ -d "/sys/class/net/$desired_bridge/bridge" ] || fail "Target bridge is not available: $desired_bridge"
  profile_tmp="$(mktemp "$(dirname "$profile")/.repeater.ini.XXXXXX")"
  cat > "$profile_tmp" <<EOF
[mode]
current = ap_ethernet_bridge

[runtime]
address_backend = NetworkManager
firewall_backend = none

[interfaces]
ap = $ap_iface
ap_24ghz = $profile_ap24
ap_5ghz = $profile_ap5
bridge = $desired_bridge
uplink = $profile_uplink
ethernet = $desired_bridge
ethernet_physical = $eth_iface
ethernet_connection = $original_con
ap_mac = $ap_mac
uplink_mac = $profile_uplink_mac
ethernet_mac = $eth_mac

[network]
bridge = $desired_bridge
subnet = $profile_subnet
gateway = $profile_gateway
dhcp_start = $profile_dhcp_start
dhcp_end = $profile_dhcp_end
ethernet_gateway = $gateway

[wireless]
country = $profile_country
EOF
  if ! awk -F ' *= *' -v desired="$desired_bridge" '
    /^\[/ { section = $0; next }
    section == "[interfaces]" && $1 == "bridge" { interfaces_bridge = $2 }
    section == "[network]" && $1 == "bridge" { network_bridge = $2 }
    END { exit !(interfaces_bridge == desired && network_bridge == desired) }
  ' "$profile_tmp"; then
    rm -f "$profile_tmp"
    fail "Generated bridge profile is inconsistent"
  fi
  chown www-data:www-data "$profile_tmp"
  chmod 0640 "$profile_tmp"
  mv "$profile_tmp" "$profile"
}

nm_cleanup_bridge_profiles() {
  [ -x "$(command -v nmcli 2>/dev/null || true)" ] || return 0
  nmcli -t -f NAME connection show 2>/dev/null | while IFS= read -r connection_name; do
    case "$connection_name" in
      "$bridge_con"|"$bridge_port_con"|openap-bridge|openap-bridge-*)
        nmcli connection down "$connection_name" >/dev/null 2>&1 || true
        nmcli connection delete "$connection_name" >/dev/null 2>&1 || true
        ;;
    esac
  done
}
is_raspberry_pi() {
  [ -r /proc/device-tree/model ] || return 1
  tr -d '\000' < /proc/device-tree/model | grep -qi 'Raspberry Pi'
}

uplink_iface=""
uplink_was_active=0
uplink_was_enabled=0
watchdog_was_enabled=0
watchdog_was_active=0
restore_uplink_on_failure=0
raspberry_networkd_released=0
raspberry_networkd_was_active=0
raspberry_networkd_socket_was_active=0

release_repeater_uplink() {
  restore_uplink_on_failure="${1:-0}"
  uplink_iface="$(ini_value uplink)"
  case "$uplink_iface" in ''|*[!A-Za-z0-9_.:-]*) uplink_iface="" ;; esac
  systemctl is-active --quiet openap-uplink.service && uplink_was_active=1 || true
  systemctl is-enabled --quiet openap-uplink.service && uplink_was_enabled=1 || true
  systemctl is-active --quiet openap-uplink-watchdog.timer && watchdog_was_active=1 || true
  systemctl is-enabled --quiet openap-uplink-watchdog.timer && watchdog_was_enabled=1 || true
  # Stop the scheduler before its oneshot worker.  A timer may fire between
  # the state checks above and this point; terminating that worker is expected
  # during a mode handoff and must not leave a false failed unit behind.
  systemctl disable --now openap-uplink-watchdog.timer >/dev/null 2>&1 || true
  systemctl stop openap-uplink-watchdog.service >/dev/null 2>&1 || true
  systemctl reset-failed openap-uplink-watchdog.service >/dev/null 2>&1 || true
  systemctl disable --now openap-uplink.service >/dev/null 2>&1 || true
  if [ -n "$uplink_iface" ] && [ -e "/sys/class/net/$uplink_iface" ]; then
    ip -4 address flush dev "$uplink_iface" scope global >/dev/null 2>&1 || true
  fi
}

restore_repeater_uplink() {
  [ "$restore_uplink_on_failure" -eq 1 ] || return 0
  systemctl reset-failed openap-uplink-watchdog.service >/dev/null 2>&1 || true
  if [ "$uplink_was_enabled" -eq 1 ]; then
    systemctl enable openap-uplink.service >/dev/null 2>&1 || true
  fi
  if [ "$uplink_was_active" -eq 1 ]; then
    systemctl start openap-uplink.service >/dev/null 2>&1 || true
  fi
  if [ "$watchdog_was_enabled" -eq 1 ]; then
    systemctl enable openap-uplink-watchdog.timer >/dev/null 2>&1 || true
  fi
  if [ "$watchdog_was_active" -eq 1 ]; then
    systemctl start openap-uplink-watchdog.timer >/dev/null 2>&1 || true
  fi
}

release_raspberry_networkd_for_nm() {
  is_raspberry_pi || return 0
  [ "$network_backend" = NetworkManager ] || return 0
  systemctl is-active --quiet systemd-networkd.service && raspberry_networkd_was_active=1 || true
  systemctl is-active --quiet systemd-networkd.socket && raspberry_networkd_socket_was_active=1 || true
  if [ "$raspberry_networkd_was_active" -eq 1 ] || [ "$raspberry_networkd_socket_was_active" -eq 1 ]; then
    # Routed Raspberry Pi mode may leave networkd managing eth0 while
    # NetworkManager is selected for the bridge.  Release both units before
    # moving the Ethernet profile into br0.  Non-Pi paths and the dedicated
    # networkd bridge helper are deliberately untouched.
    systemctl stop systemd-networkd.service >/dev/null 2>&1 || true
    systemctl stop systemd-networkd.socket >/dev/null 2>&1 || true
    raspberry_networkd_released=1
  fi
}

restore_raspberry_networkd() {
  [ "$raspberry_networkd_released" -eq 1 ] || return 0
  if [ "$raspberry_networkd_socket_was_active" -eq 1 ]; then
    systemctl start systemd-networkd.socket >/dev/null 2>&1 || true
  fi
  if [ "$raspberry_networkd_was_active" -eq 1 ]; then
    systemctl start systemd-networkd.service >/dev/null 2>&1 || true
  fi
}

case "$action" in --apply|--apply-delayed|--gateway|--gateway-delayed|--routed-delayed|--restore-routed|--validate-only|--disable) ;; *) fail "Invalid bridge action";; esac
printf '%s' "$eth_iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid Ethernet interface"
[ "$eth_iface" != lo ] || fail "Invalid Ethernet interface"
[ -e "/sys/class/net/$eth_iface" ] || fail "Ethernet interface not found"
! is_wireless "$eth_iface" || fail "Bridge uplink must be Ethernet"
[ "$(cat "/sys/class/net/$eth_iface/type" 2>/dev/null)" = 1 ] || fail "Interface is not Ethernet"
networkmanager_active=0
networkd_active=0
ifupdown_active=0
if command -v nmcli >/dev/null 2>&1 && systemctl is-active --quiet NetworkManager.service; then
  nm_managed="$(nmcli -g GENERAL.NM-MANAGED device show "$eth_iface" 2>/dev/null | head -n 1 || true)"
  nm_connection="$(nmcli -g GENERAL.CONNECTION device show "$eth_iface" 2>/dev/null | head -n 1 || true)"
  if [ "$nm_managed" = yes ] && [ -n "$nm_connection" ] && [ "$nm_connection" != -- ]; then
    networkmanager_active=1
  fi
fi
if systemctl is-active --quiet systemd-networkd.service; then
  # systemd-networkd may be running for unrelated interfaces while Network-
  # Manager owns the Ethernet uplink.  Only count it when networkctl reports
  # that it actually manages the target interface; `(unmanaged)` is explicit.
  if ! networkctl status "$eth_iface" --no-pager 2>/dev/null | grep -Eq 'State:.*\(unmanaged\)'; then
    networkd_active=1
  fi
fi
if systemctl is-active --quiet networking.service && [ -r /etc/network/interfaces ]; then
  if awk -v iface="$eth_iface" '$1 == "iface" && $2 == iface {found=1} END {exit(found ? 0 : 1)}' \
      /etc/network/interfaces /etc/network/interfaces.d/* 2>/dev/null; then
    ifupdown_active=1
  fi
fi
active_network_backends=$((networkmanager_active + networkd_active + ifupdown_active))
[ "$active_network_backends" -gt 0 ] || fail "No active network backend manages $eth_iface"
[ "$active_network_backends" -eq 1 ] || fail "Conflicting network backends manage $eth_iface; refusing bridge activation"
if [ "$networkmanager_active" -eq 1 ]; then
  network_backend=NetworkManager
elif [ "$networkd_active" -eq 1 ]; then
  network_backend=systemd-networkd
else
  network_backend=ifupdown
fi

if [ "$network_backend" = ifupdown ]; then
  case "$action" in
    --apply|--gateway|--validate-only|--disable)
      exec /usr/local/sbin/openap-apply-ap-ethernet-bridge-ifupdown \
        "$action" "$eth_iface" "$ap_mac" "$gateway_arg"
      ;;
    *)
      fail "Action $action is not supported while ifupdown manages $eth_iface"
      ;;
  esac
fi

if [ "$action" != --disable ]; then
  printf '%s' "$ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC address"
  ap_iface=""
  for net_path in /sys/class/net/*; do
    [ -r "$net_path/address" ] || continue
    [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$ap_mac" ] || continue
    candidate_iface="$(basename "$net_path")"
    # A Linux bridge commonly inherits the AP radio MAC.  Resolve the
    # persistent identity only among wireless interfaces so openap0/br0
    # cannot shadow the actual radio after boot.
    is_wireless "$candidate_iface" || continue
    ap_iface="$candidate_iface"; break
  done
  [ -n "$ap_iface" ] || fail "Selected AP interface not found"
  iw phy "$(iw dev "$ap_iface" info 2>/dev/null | awk '$1=="wiphy"{print "phy"$2;exit}')" info 2>/dev/null | grep -Eq '^[[:space:]]+\* AP$' || fail "Selected WiFi interface is not AP-capable"
  [ "$(cat "/sys/class/net/$eth_iface/carrier" 2>/dev/null || echo 0)" = 1 ] || fail "Ethernet carrier is down"
  printf '%s' "$gateway_arg" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid management gateway"
  python3 - "$eth_iface" "$gateway_arg" <<'PY' || fail "Management gateway is not on the Ethernet subnet"
import ipaddress
import subprocess
import sys

iface, gateway = sys.argv[1:]
gateway_ip = ipaddress.IPv4Address(gateway)
output = subprocess.check_output(
    ["ip", "-4", "-o", "address", "show", "dev", iface, "scope", "global"],
    text=True,
)
if not output.strip() and iface != "br0":
    output = subprocess.check_output(
        ["ip", "-4", "-o", "address", "show", "dev", "br0", "scope", "global"],
        text=True,
    )
networks = [ipaddress.IPv4Interface(line.split()[3]).network for line in output.splitlines()]
raise SystemExit(0 if any(gateway_ip in network for network in networks) else 1)
PY
fi

if [ "$action" = --apply-delayed ]; then
  write_apply_status scheduled ap_ethernet_bridge
  unit="openap-ethernet-bridge-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --tracked ap_ethernet_bridge --apply "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Ethernet Bridge activation scheduled"
  exit 0
fi

if [ "$action" = --gateway-delayed ]; then
  write_apply_status scheduled ap_ethernet_bridge
  unit="openap-ethernet-bridge-gateway-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --tracked ap_ethernet_bridge --gateway "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Ethernet Bridge management gateway update scheduled"
  exit 0
fi

if [ "$action" = --routed-delayed ]; then
  printf '%s' "$gateway_arg" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid gateway"
  write_apply_status scheduled ap_ethernet
  unit="openap-ethernet-routed-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --tracked ap_ethernet --restore-routed "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Routed AP Ethernet activation scheduled"
  exit 0
fi

if [ "$action" = --restore-routed ]; then
  restore_snapshot="$(mktemp /tmp/openap-restore-routed.XXXXXX)"
  cp -a "$profile" "$restore_snapshot"
  rollback_restore_routed() {
    rc=$?
    trap - EXIT INT TERM
    [ "$rc" -ne 0 ] || { rm -f "$restore_snapshot"; return 0; }
    cp -a "$restore_snapshot" "$profile"
    if [ "$network_backend" = systemd-networkd ]; then
      /usr/local/sbin/openap-apply-ap-ethernet-bridge-networkd \
        --apply "$eth_iface" "$ap_mac" "$gateway_arg" >/dev/null 2>&1 || true
    else
      "$0" --apply "$eth_iface" "$ap_mac" "$gateway_arg" >/dev/null 2>&1 || true
    fi
    rm -f "$restore_snapshot"
    echo "Routed restoration failed; Ethernet Bridge recovery was attempted" >&2
    exit "$rc"
  }
  trap rollback_restore_routed EXIT INT TERM
  # A stale Repeater uplink creates a competing route to the management LAN
  # and can prevent the physical Ethernet address from converging.  Ethernet
  # modes never own the station uplink, on either backend.
  release_repeater_uplink 0
  /usr/local/sbin/openap-apply-ap-ethernet-bridge --disable "$eth_iface" "$ap_mac"
  # Disabling the bridge restarts systemd-networkd.  With a DHCP Ethernet
  # profile the physical interface can therefore be briefly addressless even
  # though the lease is restored normally a moment later.  Do not invoke the
  # routed helper until both its IPv4 preconditions have converged.
  routed_wait=30
  while [ "$routed_wait" -gt 0 ]; do
    routed_addr="$(ip -4 -o address show dev "$eth_iface" scope global | awk '{print $4; exit}')"
    if [ -n "$routed_addr" ] && \
       ip -4 route get "$gateway_arg" oif "$eth_iface" 2>/dev/null | grep -Eq "(^|[[:space:]])dev[[:space:]]+$eth_iface([[:space:]]|$)"; then
      break
    fi
    routed_wait=$((routed_wait - 1))
    sleep 1
  done
  [ "$routed_wait" -gt 0 ] || fail "Ethernet IPv4 address did not return after disabling bridge mode"
  routed_hotspot_bridge=""
  active_ap24="$(awk -F ' *= *' '/^\[active\]$/{s=1;next} /^\[/{s=0} s && $1=="ap_24ghz"{print $2;exit}' /etc/openap/wifi-roles.ini 2>/dev/null || true)"
  active_ap5="$(awk -F ' *= *' '/^\[active\]$/{s=1;next} /^\[/{s=0} s && $1=="ap_5ghz"{print $2;exit}' /etc/openap/wifi-roles.ini 2>/dev/null || true)"
  if [ -n "$active_ap24" ] && [ -n "$active_ap5" ]; then
    routed_hotspot_bridge=openap0
  fi
  # A single-band AP may also remain attached to the routed hotspot bridge.
  # Preserve the bridge recorded by the effective hostapd configuration;
  # otherwise the readiness helper looks for the gateway on the radio itself
  # while networkd correctly keeps it on openap0.
  for hostapd_candidate in "$hostapd_conf" $dual_hostapd_configs; do
    [ -r "$hostapd_candidate" ] || continue
    if grep -Fxq 'bridge=openap0' "$hostapd_candidate"; then
      routed_hotspot_bridge=openap0
      break
    fi
  done
  # The desktop systemd-networkd AP path owns a persistent openap0 bridge,
  # but hostapd does not declare it: the post-start attach helper enslaves the
  # radio after hostapd has opened it.  Preserve that bridge during
  # Bridge -> routed NAT even for a single-band AP; otherwise the generated
  # routed profile loses both bridge keys and readiness is checked on the
  # wireless radio instead of on 10.88.77.1/openap0.  Raspberry Pi and
  # ifupdown/NetworkManager paths keep their existing detection rules.
  if [ "$network_backend" = systemd-networkd ] && ! is_raspberry_pi \
      && [ -f /etc/systemd/network/15-openap-hotspot.netdev ] \
      && [ -f /etc/systemd/network/20-openap-hotspot.network ]; then
    if grep -Eq '^Name=openap0$' /etc/systemd/network/15-openap-hotspot.netdev \
        && grep -Eq '^Address=10\.88\.77\.1/24$' /etc/systemd/network/20-openap-hotspot.network; then
      routed_hotspot_bridge=openap0
    fi
  fi
  profile_tmp="$(mktemp)"
  awk -v hotspot_bridge="$routed_hotspot_bridge" '
    BEGIN { section="" }
    /^\[/ { section=$0 }
    section == "[mode]" && /^current[[:space:]]*=/ { print "current = ap_ethernet"; next }
    section == "[runtime]" && /^firewall_backend[[:space:]]*=/ { print "firewall_backend = openap-firewall"; next }
    section == "[interfaces]" && /^ethernet[[:space:]]*=/ { print "ethernet = '"$eth_iface"'"; next }
    section == "[interfaces]" && /^ethernet_physical[[:space:]]*=/ { print "ethernet_physical = '"$eth_iface"'"; next }
    section == "[interfaces]" && /^bridge[[:space:]]*=/ { if (hotspot_bridge != "") print "bridge = " hotspot_bridge; next }
    section == "[network]" && /^bridge[[:space:]]*=/ { if (hotspot_bridge != "") print "bridge = " hotspot_bridge; next }
    { print }
  ' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"
  chmod 0640 "$profile_tmp"
  mv "$profile_tmp" "$profile"
  if [ -n "$routed_hotspot_bridge" ]; then
    # A boot in Ethernet Bridge mode may leave the routed hotspot bridge
    # absent (or deliberately down).  The routed helper validates the bridge
    # before it writes or restarts anything, so converge the profile-owned
    # hotspot interface first instead of failing after br0 was removed.
    if systemctl cat openap-ap-address.service >/dev/null 2>&1; then
      systemctl restart openap-ap-address.service \
        || fail "Unable to restore routed hotspot bridge"
    elif [ -x /usr/local/sbin/openap-prepare-ap-interface ]; then
      /usr/local/sbin/openap-prepare-ap-interface --prepare \
        || fail "Unable to restore routed hotspot bridge"
    else
      fail "Routed hotspot bridge helper is not installed"
    fi
    [ -d "/sys/class/net/$routed_hotspot_bridge/bridge" ] \
      || fail "Routed hotspot bridge was not restored"
  fi
  if /usr/local/sbin/openap-apply-ap-ethernet --apply "$eth_iface" "$gateway_arg" "$ap_mac"; then
    trap - EXIT INT TERM
    rm -f "$restore_snapshot"
    exit 0
  fi
  fail "Routed AP Ethernet activation failed"
fi

if [ "$action" = --apply ] && [ "$(ini_value current)" = repeater_wifi ]; then
  release_repeater_uplink 1
fi

if [ "$network_backend" = systemd-networkd ]; then
  if /usr/local/sbin/openap-apply-ap-ethernet-bridge-networkd \
    "$action" "$eth_iface" "$ap_mac" "$gateway_arg"; then
    exit 0
  else
    rc=$?
  fi
  restore_repeater_uplink
  exit "$rc"
fi

if [ "$action" = --gateway ]; then
  [ "$(ini_value current)" = ap_ethernet_bridge ] || fail "Ethernet Bridge is not active"
  nmcli -t -f NAME connection show | grep -Fxq "$bridge_con" || fail "OpenAP bridge connection not found"
  method="$(nmcli -g ipv4.method connection show "$bridge_con")"
  if [ "$method" = manual ]; then
    nmcli connection modify "$bridge_con" ipv4.gateway "$gateway_arg"
  else
    nmcli connection modify "$bridge_con" ipv4.ignore-auto-routes yes ipv4.routes "0.0.0.0/0 $gateway_arg 50"
  fi
  nmcli connection up "$bridge_con" >/dev/null
  tries=10
  while [ "$tries" -gt 0 ]; do
    ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway_arg([[:space:]]|$)" && break
    tries=$((tries-1)); sleep 1
  done
  [ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
  profile_tmp="$(mktemp)"
  awk -v gateway="$gateway_arg" 'BEGIN{done=0} /^ethernet_gateway[[:space:]]*=/{print "ethernet_gateway = " gateway;done=1;next} {print} END{if(!done)print "ethernet_gateway = " gateway}' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
  echo "Ethernet Bridge management gateway updated: $gateway_arg"
  exit 0
fi

original_con="$(nmcli -g GENERAL.CONNECTION device show "$eth_iface" 2>/dev/null | head -1)"
[ "$original_con" != -- ] || original_con=""
if [ -z "$original_con" ]; then original_con="$(ini_value ethernet_connection)"; fi

if [ "$action" = --validate-only ]; then
  [ -n "$original_con" ] || fail "No active Ethernet connection profile"
  method="$(nmcli -g ipv4.method connection show "$original_con")"
  case "$method" in auto|manual) ;; *) fail "Unsupported Ethernet IPv4 method: $method";; esac
  echo "Ethernet Bridge validation passed"
  echo "Bridge: $bridge_iface; Ethernet: $eth_iface; AP: $ap_iface"
  echo "IPv4 configuration source: $original_con ($method)"
  echo "Upstream router will provide DHCP to WiFi clients"
  echo "No changes were applied"
  exit 0
fi

if [ "$action" = --disable ]; then
  saved_con="$(ini_value ethernet_connection)"
  [ -n "$saved_con" ] || saved_con="$original_con"
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  nm_cleanup_bridge_profiles
  if [ -n "$saved_con" ] && nmcli -t -f NAME connection show | grep -Fxq "$saved_con"; then
    nmcli connection modify "$saved_con" connection.autoconnect yes
    nmcli connection up "$saved_con" >/dev/null
  fi
  if [ -f "$hostapd_conf" ]; then
    sed -i '/^bridge=br0$/d' "$hostapd_conf"
  fi
  for conf in $dual_hostapd_configs; do
    [ -f "$conf" ] || continue
    sed -i 's/^bridge=br0$/bridge=openap0/' "$conf"
  done
  echo "Ethernet bridge disabled; restored connection: ${saved_con:-unknown}"
  exit 0
fi

[ -n "$original_con" ] || fail "No active Ethernet connection profile"
release_raspberry_networkd_for_nm
method="$(nmcli -g ipv4.method connection show "$original_con")"
case "$method" in auto|manual) ;; *) fail "Unsupported Ethernet IPv4 method: $method";; esac
addresses="$(nmcli -g ipv4.addresses connection show "$original_con" | paste -sd, -)"
gateway="$gateway_arg"
dns_candidates="$(nmcli -g ipv4.dns connection show "$original_con" | paste -sd, -)"
# Static Ethernet profiles often leave ipv4.dns empty because routed OpenAP
# clients use dnsmasq.  In bridge mode dnsmasq is intentionally stopped, so
# the Raspberry itself needs the active OpenAP upstream resolver copied onto
# br0 or it retains a route but loses all name resolution.
dns_candidates="$dns_candidates,$(nmcli -g IP4.DNS device show "$eth_iface" 2>/dev/null | sed '/^[[:space:]]*$/d' | paste -sd, -)"
if [ -r "$dnsmasq_conf" ]; then
  dns_candidates="$dns_candidates,$(sed -n 's/^server=//p' "$dnsmasq_conf" | sed '/^[[:space:]]*$/d' | paste -sd, -)"
fi
if [ -r /etc/resolv.conf ]; then
  dns_candidates="$dns_candidates,$(awk '$1 == "nameserver" {print $2}' /etc/resolv.conf | paste -sd, -)"
fi
dns="$(printf '%s\n' "$dns_candidates" | tr ', ' '\n' | awk -v excluded="$gateway" '
  $0 ~ /^[0-9]+(\.[0-9]+){3}$/ && $0 != excluded && $0 !~ /^127\./ && !seen[$0]++ {
    printf "%s%s", sep, $0
    sep = ","
  }
')"
[ -n "$dns" ] || dns="1.1.1.1,1.0.0.1"
eth_mac="$(tr 'A-F' 'a-f' < "/sys/class/net/$eth_iface/address")"
profile_uplink="$(ini_value uplink)"
profile_uplink_mac="$(ini_value uplink_mac)"
profile_ap24="$(ini_value ap_24ghz)"
profile_ap5="$(ini_value ap_5ghz)"
profile_subnet="$(ini_value subnet)"
profile_gateway="$(ini_value gateway)"
profile_dhcp_start="$(ini_value dhcp_start)"
profile_dhcp_end="$(ini_value dhcp_end)"
profile_country="$(ini_value country)"
backup_dir="/etc/openap/backups/ap-ethernet-bridge-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
for path in "$profile" "$hostapd_conf" "$nft_conf" "$dnsmasq_conf" $dual_hostapd_configs; do [ ! -e "$path" ] || cp -a "$path" "$backup_dir/"; done
nmcli connection show "$original_con" > "$backup_dir/original-ethernet-connection.txt"

rollback() {
  rc=$?
  trap - EXIT INT TERM
  [ "$rc" -eq 0 ] && return 0
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  nm_cleanup_bridge_profiles
  nmcli connection modify "$original_con" connection.autoconnect yes >/dev/null 2>&1 || true
  nmcli connection up "$original_con" >/dev/null 2>&1 || true
  restore_raspberry_networkd
  [ ! -f "$backup_dir/hostapd.conf" ] || cp -a "$backup_dir/hostapd.conf" "$hostapd_conf"
  for conf in $dual_hostapd_configs; do
    [ ! -f "$backup_dir/$(basename "$conf")" ] || cp -a "$backup_dir/$(basename "$conf")" "$conf"
  done
  [ ! -f "$backup_dir/openap.nft" ] || cp -a "$backup_dir/openap.nft" "$nft_conf"
  [ ! -f "$backup_dir/openap-repeater.conf" ] || cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_conf"
  [ ! -f "$backup_dir/repeater.ini" ] || cp -a "$backup_dir/repeater.ini" "$profile"
  systemctl restart openap-firewall.service >/dev/null 2>&1 || true
  systemctl restart dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart openap-ap-address.service >/dev/null 2>&1 || true
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  restore_repeater_uplink
  echo "Bridge activation failed; original Ethernet connection restored" >&2
  exit "$rc"
}
trap rollback EXIT INT TERM

nm_cleanup_bridge_profiles
if [ "$method" = manual ]; then
  [ -n "$addresses" ] || fail "Ethernet profile has no static IPv4 address"
  nmcli connection add type bridge ifname "$bridge_iface" con-name "$bridge_con" connection.autoconnect no connection.autoconnect-priority 200 bridge.stp no bridge.mac-address "$eth_mac" ipv4.method manual ipv4.addresses "$addresses" ipv4.gateway "$gateway" ipv4.dns "$dns" ipv6.method disabled >/dev/null
else
  nmcli connection add type bridge ifname "$bridge_iface" con-name "$bridge_con" connection.autoconnect no connection.autoconnect-priority 200 bridge.stp no bridge.mac-address "$eth_mac" ipv4.method auto ipv4.ignore-auto-routes yes ipv4.routes "0.0.0.0/0 $gateway 50" ipv6.method disabled >/dev/null
fi
nmcli connection add type ethernet slave-type bridge ifname "$eth_iface" master "$bridge_iface" con-name "$bridge_port_con" connection.autoconnect no connection.autoconnect-priority 200 >/dev/null
nmcli connection modify "$original_con" connection.autoconnect no
# The original profile may still own eth0 after its autoconnect flag changes.
# Bring it down explicitly before activating the bridge port so NetworkManager
# does not keep an L3 profile attached beside br0 on Raspberry Pi.
nmcli connection down "$original_con" >/dev/null 2>&1 || true

hostapd_tmp="$(mktemp)"
awk -v iface="$ap_iface" 'BEGIN{i=0;b=0} /^interface=/{if(!i)print "interface="iface;i=1;next} /^bridge=/{if(!b)print "bridge=br0";b=1;next} {print} END{if(!i)print "interface="iface;if(!b)print "bridge=br0"}' "$hostapd_conf" > "$hostapd_tmp"
chown root:www-data "$hostapd_tmp"; chmod 0640 "$hostapd_tmp"; mv "$hostapd_tmp" "$hostapd_conf"
for conf in $dual_hostapd_configs; do
  [ -f "$conf" ] || continue
  sed -i -E 's/^bridge=.*/bridge=br0/' "$conf"
  grep -q '^bridge=br0$' "$conf" || printf '\nbridge=br0\n' >> "$conf"
done

systemctl stop hostapd.service
systemctl stop dnsmasq.service
systemctl stop openap-firewall.service
systemctl stop openap-ap-address.service >/dev/null 2>&1 || true
ip -4 addr flush dev "$ap_iface" scope global >/dev/null 2>&1 || true
nmcli connection up "$bridge_con" >/dev/null
nmcli connection up "$bridge_port_con" >/dev/null
tries=20
while [ "$tries" -gt 0 ]; do
  ip -4 -o addr show dev "$bridge_iface" | grep -q ' inet ' && break
  tries=$((tries-1)); sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge did not receive an IPv4 address"
tries=10
while [ "$tries" -gt 0 ]; do
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" && break
  tries=$((tries-1)); sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
nmcli connection modify "$bridge_con" connection.autoconnect yes
nmcli connection modify "$bridge_port_con" connection.autoconnect yes
ip link set dev "$ap_iface" up
write_bridge_profile "$bridge_iface"
systemctl restart hostapd.service

active_ap_interfaces=""
for conf in $dual_hostapd_configs; do
  [ -f "$conf" ] || continue
  configured_ap="$(awk -F= '$1 == "interface" {print $2; exit}' "$conf")"
  [ -n "$configured_ap" ] || continue
  active_ap_interfaces="$active_ap_interfaces $configured_ap"
done
[ -n "$active_ap_interfaces" ] || active_ap_interfaces=" $ap_iface"
for configured_ap in $active_ap_interfaces; do
  hostapd_cli -i "$configured_ap" ping 2>/dev/null | grep -Fxq PONG \
    || fail "hostapd is not ready on $configured_ap"
  [ "$(basename "$(readlink -f "/sys/class/net/$configured_ap/master" 2>/dev/null)")" = "$bridge_iface" ] \
    || fail "$configured_ap is not attached to $bridge_iface"
done

# hostapd's address dependency runs before the final profile switches the
# routed dual-band bridge name from openap0 to br0.  Remove the obsolete
# routed address after that commit so no link-down route survives the mode
# transition; subsequent boots are also covered by the mode-aware preparer.
if [ "$bridge_iface" != openap0 ] && [ -d /sys/class/net/openap0/bridge ]; then
  ip -4 address flush dev openap0 scope global
  ip link set dev openap0 down
fi
# The bridge can carry client traffic while the host itself has no usable
# resolver.  Do not report a successful mode switch until libc name
# resolution works through the DNS copied onto the bridge profile.
dns_ready=false
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if getent ahostsv4 example.com >/dev/null 2>&1; then
    dns_ready=true
    break
  fi
  sleep 1
done
[ "$dns_ready" = true ] || fail "Ethernet Bridge DNS did not become ready on the host"
mkdir -p /run/openap; date +%s > /run/openap/mode-switch
trap - EXIT INT TERM
echo "Ethernet Bridge active on $bridge_iface ($eth_iface + $ap_iface)"
echo "DHCP is provided by the upstream Ethernet network"
echo "Backup: $backup_dir"
