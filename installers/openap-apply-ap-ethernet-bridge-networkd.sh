#!/bin/sh
set -eu

action="${1:---apply}"
eth_iface="${2:-}"
ap_mac="$(printf '%s' "${3:-}" | tr 'A-F' 'a-f')"
gateway_arg="${4:-}"
bridge_iface=br0
profile=/etc/openap/repeater.ini
hostapd_conf=/etc/hostapd/hostapd.conf
if [ -f /etc/openap/hostapd/hostapd.conf ]; then
  hostapd_conf=/etc/openap/hostapd/hostapd.conf
fi
nft_conf=/etc/openap/networking/openap.nft
dnsmasq_conf=/etc/dnsmasq.d/openap-repeater.conf
bridge_netdev=/etc/systemd/network/05-openap-bridge.netdev
bridge_network=/etc/systemd/network/05-openap-bridge.network
bridge_port=/etc/systemd/network/05-openap-bridge-port.network
bridge_ap=/etc/systemd/network/06-openap-bridge-ap.network
bridge_hotspot=/etc/systemd/network/06-openap-bridge-hotspot.network
dual_hostapd_configs="/etc/hostapd/openap-ap-24ghz.conf /etc/hostapd/openap-ap-5ghz.conf"
network_dir=/etc/systemd/network
mode_state_dir=/etc/openap/networkd-mode
disabled_routed_dir="$mode_state_dir/routed-profiles"
mode_state_iface="$mode_state_dir/ethernet-interface"
ifupdown_state_dir="$mode_state_dir/ifupdown"
ifupdown_mask_marker="$ifupdown_state_dir/mask-created"
ifupdown_unit="ifup@${eth_iface}.service"
ifupdown_mask_path="/etc/systemd/system/$ifupdown_unit"
ifupdown_files_dir="$ifupdown_state_dir/files"
ifupdown_files_list="$ifupdown_state_dir/files.list"

fail() { echo "$1" >&2; exit 1; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
is_wireless() { [ -d "/sys/class/net/$1/wireless" ] || iw dev 2>/dev/null | awk '$1=="Interface"{print $2}' | grep -Fxq "$1"; }

network_profile_matches_iface() {
  awk -v iface="$eth_iface" '$0 == "Name=" iface { found=1 } END { exit(found ? 0 : 1) }' "$1"
}

quarantine_routed_profiles() {
  [ ! -e "$mode_state_iface" ] || fail "Ethernet Bridge mode already owns routed network profiles"
  mkdir -p "$disabled_routed_dir"
  for path in "$network_dir"/*.network; do
    [ -f "$path" ] || continue
    case "$(basename "$path")" in
      05-openap-bridge.network|05-openap-bridge-port.network|06-openap-bridge-ap.network|06-openap-bridge-hotspot.network)
        continue
        ;;
    esac
    network_profile_matches_iface "$path" || continue
    mv -- "$path" "$disabled_routed_dir/$(basename "$path")"
  done
  printf '%s\n' "$eth_iface" > "$mode_state_iface"
}

restore_routed_profiles() {
  [ ! -e "$mode_state_iface" ] || [ "$(cat "$mode_state_iface")" = "$eth_iface" ] \
    || fail "Stored Ethernet profile belongs to a different interface"
  [ -d "$disabled_routed_dir" ] || return 0
  for path in "$disabled_routed_dir"/*.network; do
    [ -f "$path" ] || continue
    mv -- "$path" "$network_dir/$(basename "$path")"
  done
}

ifupdown_configured_for_iface() {
  [ -r /etc/network/interfaces ] || return 1
  for path in /etc/network/interfaces /etc/network/interfaces.d/*; do
    [ -f "$path" ] || continue
    awk -v iface="$eth_iface" '$1 == "auto" || $1 == "allow-hotplug" || $1 == "iface" { for (i=2; i<=NF; i++) if ($i == iface) found=1 } END { exit(found ? 0 : 1) }' "$path" && return 0
  done
  return 1
}

quarantine_ifupdown() {
  ifupdown_configured_for_iface || return 0
  mkdir -p "$ifupdown_state_dir"
  mask_was_preexisting=false
  if [ -L "$ifupdown_mask_path" ] && [ "$(readlink "$ifupdown_mask_path")" = /dev/null ]; then
    printf '%s\n' preexisting > "$ifupdown_state_dir/mask-state"
    mask_was_preexisting=true
  fi
  : > "$ifupdown_files_list"
  for path in /etc/network/interfaces /etc/network/interfaces.d/*; do
    [ -f "$path" ] || continue
    awk -v iface="$eth_iface" '$1 == "auto" || $1 == "allow-hotplug" { for (i=2; i<=NF; i++) if ($i == iface) found=1 } END { exit(found ? 0 : 1) }' "$path" || continue
    relative_path="${path#/etc/network/}"
    mkdir -p "$ifupdown_files_dir/$(dirname "$relative_path")"
    cp -a "$path" "$ifupdown_files_dir/$relative_path"
    printf '%s\n' "$relative_path" >> "$ifupdown_files_list"
    awk -v iface="$eth_iface" '
      ($1 == "auto" || $1 == "allow-hotplug") {
        output=$1; removed=0
        for (i=2; i<=NF; i++) {
          if ($i == iface) removed=1
          else output=output " " $i
        }
        if (removed && output == $1) next
        if (removed) { print output; next }
      }
      { print }
    ' "$path" > "$path.openap-tmp"
    cat "$path.openap-tmp" > "$path"
    rm -f "$path.openap-tmp"
  done
  if [ "$mask_was_preexisting" = false ]; then
    systemctl stop "$ifupdown_unit" >/dev/null 2>&1 || true
    systemctl mask "$ifupdown_unit" >/dev/null
    printf '%s\n' created > "$ifupdown_mask_marker"
  fi
}

restore_ifupdown() {
  for path in "$network_dir"/*.network; do
    [ -f "$path" ] || continue
    if network_profile_matches_iface "$path"; then
      rm -rf "$ifupdown_state_dir"
      return 0
    fi
  done
  if [ -f "$ifupdown_files_list" ]; then
    while IFS= read -r relative_path; do
      [ -n "$relative_path" ] || continue
      cp -a "$ifupdown_files_dir/$relative_path" "/etc/network/$relative_path"
    done < "$ifupdown_files_list"
  fi
  if [ -f "$ifupdown_mask_marker" ]; then
    systemctl unmask "$ifupdown_unit" >/dev/null 2>&1 || true
  fi
  rm -rf "$ifupdown_state_dir"
}

case "$action" in --apply|--gateway|--validate-only|--disable) ;; *) fail "Invalid networkd bridge action";; esac
systemctl is-active --quiet systemd-networkd.service || fail "systemd-networkd is not active"
printf '%s' "$eth_iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid Ethernet interface"
[ -e "/sys/class/net/$eth_iface" ] || fail "Ethernet interface not found"

if [ "$action" = --disable ]; then
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  rm -f "$bridge_ap" "$bridge_hotspot" "$bridge_port" "$bridge_network" "$bridge_netdev"
  if [ -f "$hostapd_conf" ]; then
    sed -i '/^bridge=br0$/d' "$hostapd_conf"
  fi
  for conf in $dual_hostapd_configs; do
    [ -f "$conf" ] || continue
    sed -i 's/^bridge=br0$/bridge=openap0/' "$conf"
  done
  restore_routed_profiles
  restore_ifupdown
  rm -rf -- "$mode_state_dir"
  networkctl reload >/dev/null 2>&1 || true
  ip link set dev "$eth_iface" nomaster >/dev/null 2>&1 || true
  ip link del "$bridge_iface" type bridge >/dev/null 2>&1 || true
  systemctl restart systemd-networkd.service
  echo "Ethernet bridge disabled; systemd-networkd routed profile restored"
  exit 0
fi

printf '%s' "$ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC address"
ap_iface=""
for net_path in /sys/class/net/*; do
  [ -r "$net_path/address" ] || continue
  [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$ap_mac" ] || continue
  candidate_iface="$(basename "$net_path")"
  # The hotspot bridge can inherit the AP radio MAC.  Ignore non-wireless
  # duplicates and retain the physical radio associated with the role.
  is_wireless "$candidate_iface" || continue
  ap_iface="$candidate_iface"
  break
done
[ -n "$ap_iface" ] || fail "Selected AP interface not found"
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

addresses="$(ip -4 -o address show dev "$eth_iface" scope global | awk '{print $4}' | paste -sd, -)"
gateway="$gateway_arg"
dns="$(resolvectl dns "$eth_iface" 2>/dev/null | sed 's/^[^:]*:[[:space:]]*//' | tr ' ' '\n' | grep -E '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | paste -sd' ' - || true)"
if [ -z "$dns" ] && [ -r "$dnsmasq_conf" ]; then
  dns="$(sed -n 's/^server=//p' "$dnsmasq_conf" | sed '/^[[:space:]]*$/d' | paste -sd' ' -)"
fi
if [ -z "$dns" ] && [ -r /etc/resolv.conf ]; then
  dns="$(awk '$1 == "nameserver" && $2 != "127.0.0.53" {print $2}' /etc/resolv.conf | paste -sd' ' -)"
fi
[ -n "$dns" ] || dns="1.1.1.1 9.9.9.9"
eth_mac="$(tr 'A-F' 'a-f' < "/sys/class/net/$eth_iface/address")"

if [ "$action" = --gateway ]; then
  [ "$(ini_value current)" = ap_ethernet_bridge ] || fail "Ethernet Bridge is not active"
  [ -f "$bridge_network" ] || fail "OpenAP bridge network configuration not found"
  backup_dir="/etc/openap/backups/bridge-gateway-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$backup_dir"
  cp -a "$bridge_network" "$profile" "$backup_dir/"
  bridge_tmp="$(mktemp)"
  awk -v gateway="$gateway" '
    BEGIN { section=""; network_seen=0; configure_without_carrier=0; onlink=0; route_seen=0 }
    /^\[/ {
      if (section == "Network" && !configure_without_carrier) print "ConfigureWithoutCarrier=yes"
      if (section == "Route" && !onlink) print "GatewayOnLink=yes"
      if ($0 == "[Link]") { section="Link" }
      else if ($0 == "[Network]") { section="Network"; network_seen=1 }
      else if ($0 == "[Route]") { section="Route"; route_seen=1 }
      else { section="" }
      print
      next
    }
    section == "Link" && /^ConfigureWithoutCarrier=/ { next }
    section == "Network" && /^ConfigureWithoutCarrier=/ { print "ConfigureWithoutCarrier=yes"; configure_without_carrier=1; next }
    section == "Route" && /^Gateway=/ { print "Gateway=" gateway; next }
    section == "Route" && /^GatewayOnLink=/ { print "GatewayOnLink=yes"; onlink=1; next }
    { print }
    END {
      if (section == "Network" && !configure_without_carrier) print "ConfigureWithoutCarrier=yes"
      if (section == "Route" && !onlink) print "GatewayOnLink=yes"
      if (!network_seen) {
        print ""
        print "[Network]"
        print "ConfigureWithoutCarrier=yes"
      }
      if (!route_seen) {
        print ""
        print "[Route]"
        print "Destination=0.0.0.0/0"
        print "Gateway=" gateway
        print "GatewayOnLink=yes"
        print "Metric=50"
      }
    }
  ' "$bridge_network" > "$bridge_tmp"
  cat "$bridge_tmp" > "$bridge_network"
  rm -f "$bridge_tmp"
  networkctl reload
  networkctl reconfigure "$bridge_iface"
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" || fail "Bridge management gateway did not converge"
  profile_tmp="$(mktemp)"
  awk -v gateway="$gateway" 'BEGIN{done=0} /^ethernet_gateway[[:space:]]*=/{print "ethernet_gateway = " gateway;done=1;next} {print} END{if(!done)print "ethernet_gateway = " gateway}' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
  echo "Ethernet Bridge management gateway updated: $gateway"
  echo "Backup: $backup_dir"
  exit 0
fi

if [ "$action" = --validate-only ]; then
  [ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
  echo "Ethernet Bridge validation passed"
  echo "Backend: systemd-networkd"
  echo "Bridge: $bridge_iface; Ethernet: $eth_iface; AP: $ap_iface"
  exit 0
fi

[ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
profile_uplink="$(ini_value uplink)"
profile_uplink_mac="$(ini_value uplink_mac)"
profile_subnet="$(ini_value subnet)"
profile_gateway="$(ini_value gateway)"
profile_dhcp_start="$(ini_value dhcp_start)"
profile_dhcp_end="$(ini_value dhcp_end)"
profile_country="$(ini_value country)"
backup_dir="/etc/openap/backups/ap-ethernet-bridge-networkd-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
for path in "$profile" "$hostapd_conf" "$nft_conf" "$dnsmasq_conf"; do
  [ ! -e "$path" ] || cp -a "$path" "$backup_dir/"
done
for path in $dual_hostapd_configs; do
  [ ! -e "$path" ] || cp -a "$path" "$backup_dir/"
done
cp -a /etc/systemd/network "$backup_dir/systemd-network"
if [ -e "$mode_state_dir" ]; then
  cp -a "$mode_state_dir" "$backup_dir/networkd-mode-state"
fi

rollback() {
  rc=$?
  trap - EXIT INT TERM
  [ "$rc" -eq 0 ] && return 0
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  rm -f "$bridge_ap" "$bridge_hotspot" "$bridge_port" "$bridge_network" "$bridge_netdev"
  rm -rf /etc/systemd/network
  cp -a "$backup_dir/systemd-network" /etc/systemd/network
  restore_ifupdown
  rm -rf -- "$mode_state_dir"
  if [ -e "$backup_dir/networkd-mode-state" ]; then
    cp -a "$backup_dir/networkd-mode-state" "$mode_state_dir"
  fi
  [ ! -f "$backup_dir/hostapd.conf" ] || cp -a "$backup_dir/hostapd.conf" "$hostapd_conf"
  [ ! -f "$backup_dir/openap.nft" ] || cp -a "$backup_dir/openap.nft" "$nft_conf"
  [ ! -f "$backup_dir/openap-repeater.conf" ] || cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_conf"
  [ ! -f "$backup_dir/repeater.ini" ] || cp -a "$backup_dir/repeater.ini" "$profile"
  for path in $dual_hostapd_configs; do
    [ ! -f "$backup_dir/$(basename "$path")" ] || cp -a "$backup_dir/$(basename "$path")" "$path"
  done
  ip link set dev "$eth_iface" nomaster >/dev/null 2>&1 || true
  ip link del "$bridge_iface" type bridge >/dev/null 2>&1 || true
  systemctl restart systemd-networkd.service >/dev/null 2>&1 || true
  systemctl restart openap-firewall.service >/dev/null 2>&1 || true
  systemctl restart dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart openap-ap-address.service >/dev/null 2>&1 || true
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  echo "Bridge activation failed; original systemd-networkd configuration restored" >&2
  exit "$rc"
}
trap rollback EXIT INT TERM

quarantine_routed_profiles
quarantine_ifupdown

cat > "$bridge_netdev" <<EOF
[NetDev]
Name=$bridge_iface
Kind=bridge
MACAddress=$eth_mac

[Bridge]
STP=false
EOF

cat > "$bridge_network" <<EOF
[Match]
Name=$bridge_iface

[Link]
RequiredForOnline=yes

[Network]
ConfigureWithoutCarrier=yes
Address=$addresses
DNS=$dns
LinkLocalAddressing=ipv6
IPv6AcceptRA=no
EOF
if [ -n "$gateway" ]; then
  cat >> "$bridge_network" <<EOF

[Route]
Destination=0.0.0.0/0
Gateway=$gateway
GatewayOnLink=yes
Metric=50
EOF
fi

cat > "$bridge_port" <<EOF
[Match]
Name=$eth_iface

[Link]
RequiredForOnline=no

[Network]
Bridge=$bridge_iface
LinkLocalAddressing=no
EOF

cat > "$bridge_ap" <<EOF
[Match]
Name=$ap_iface

[Link]
RequiredForOnline=no

[Network]
LinkLocalAddressing=no
IPv6AcceptRA=no
EOF

# Keep the persistent routed-hotspot definition installed, but override its
# address while Ethernet Bridge mode is active.  Removing this lower-numbered
# match in --disable lets networkd restore openap0 automatically.
cat > "$bridge_hotspot" <<EOF
[Match]
Name=openap0

[Link]
RequiredForOnline=no

[Network]
DHCP=no
LinkLocalAddressing=no
ConfigureWithoutCarrier=yes
EOF

hostapd_tmp="$(mktemp)"
awk -v iface="$ap_iface" 'BEGIN{i=0;b=0} /^interface=/{if(!i)print "interface="iface;i=1;next} /^bridge=/{if(!b)print "bridge=br0";b=1;next} {print} END{if(!i)print "interface="iface;if(!b)print "bridge=br0"}' "$hostapd_conf" > "$hostapd_tmp"
chown root:www-data "$hostapd_tmp"
chmod 0640 "$hostapd_tmp"
mv "$hostapd_tmp" "$hostapd_conf"
for conf in $dual_hostapd_configs; do
  [ -f "$conf" ] || continue
  if grep -q '^bridge=' "$conf"; then
    sed -i 's/^bridge=.*/bridge=br0/' "$conf"
  else
    printf '\nbridge=br0\n' >> "$conf"
  fi
done

systemctl stop hostapd.service
systemctl stop dnsmasq.service
systemctl stop openap-firewall.service
systemctl stop openap-ap-address.service >/dev/null 2>&1 || true
ip link set dev "$ap_iface" nomaster >/dev/null 2>&1 || true
ip -4 address flush dev "$ap_iface" scope global >/dev/null 2>&1 || true
ip -4 address flush dev openap0 scope global >/dev/null 2>&1 || true
systemctl restart systemd-networkd.service

tries=20
while [ "$tries" -gt 0 ]; do
  ip -4 -o address show dev "$bridge_iface" | grep -q ' inet ' && break
  tries=$((tries-1))
  sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge did not receive the Ethernet IPv4 address"
tries=10
while [ "$tries" -gt 0 ]; do
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" && break
  tries=$((tries-1))
  sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
ip link set dev "$ap_iface" up
profile_tmp="$(mktemp)"
cat > "$profile_tmp" <<EOF
[mode]
current = ap_ethernet_bridge

[runtime]
address_backend = systemd-networkd
firewall_backend = none

[interfaces]
ap = $ap_iface
uplink = $profile_uplink
ethernet = $bridge_iface
ethernet_physical = $eth_iface
bridge = $bridge_iface
ap_mac = $ap_mac
uplink_mac = $profile_uplink_mac
ethernet_mac = $eth_mac

[network]
bridge = $bridge_iface
subnet = $profile_subnet
gateway = $profile_gateway
dhcp_start = $profile_dhcp_start
dhcp_end = $profile_dhcp_end
ethernet_gateway = $gateway

[wireless]
country = $profile_country
EOF
chown www-data:www-data "$profile_tmp"
chmod 0640 "$profile_tmp"
mv "$profile_tmp" "$profile"
systemctl restart hostapd.service
dns_ready=false
for _attempt in 1 2 3 4 5 6 7 8 9 10; do
  if getent ahostsv4 example.com >/dev/null 2>&1; then
    dns_ready=true
    break
  fi
  sleep 1
done
[ "$dns_ready" = true ] || fail "Ethernet Bridge DNS did not become ready on the host"
mkdir -p /run/openap
date +%s > /run/openap/mode-switch
trap - EXIT INT TERM
echo "Ethernet Bridge active on $bridge_iface ($eth_iface + $ap_iface)"
echo "Backend: systemd-networkd"
echo "DHCP is provided by the upstream Ethernet network"
echo "Backup: $backup_dir"
