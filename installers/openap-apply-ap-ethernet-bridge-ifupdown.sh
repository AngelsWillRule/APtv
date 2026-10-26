#!/bin/sh
set -eu

action="${1:---apply}"
eth_iface="${2:-}"
ap_mac="$(printf '%s' "${3:-}" | tr 'A-F' 'a-f')"
gateway_arg="${4:-}"
bridge_iface=br0
interfaces_file=/etc/network/interfaces
profile=/etc/openap/repeater.ini
hostapd_conf=/etc/hostapd/hostapd.conf
[ -f /etc/openap/hostapd/hostapd.conf ] && hostapd_conf=/etc/openap/hostapd/hostapd.conf
dnsmasq_conf=/etc/dnsmasq.d/openap-repeater.conf
dual_hostapd_configs="/etc/hostapd/openap-ap-24ghz.conf /etc/hostapd/openap-ap-5ghz.conf"
state_file=/etc/openap/ifupdown-ethernet-bridge.state

fail() { echo "openap-apply-ap-ethernet-bridge-ifupdown: $1" >&2; exit 1; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
is_wireless() { [ -d "/sys/class/net/$1/wireless" ] || iw dev 2>/dev/null | awk '$1=="Interface"{print $2}' | grep -Fxq "$1"; }

case "$action" in --apply|--gateway|--validate-only|--disable) ;; *) fail "invalid action" ;; esac
[ "$(id -u)" -eq 0 ] || fail "must run as root"
[ -r "$interfaces_file" ] || fail "ifupdown configuration not found"
systemctl is-active --quiet networking.service || fail "networking.service is not active"
command -v ifup >/dev/null 2>&1 || fail "ifup is not installed"
command -v ifdown >/dev/null 2>&1 || fail "ifdown is not installed"
command -v brctl >/dev/null 2>&1 || fail "bridge-utils is required for ifupdown bridge mode"

printf '%s' "$eth_iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "invalid Ethernet interface"
[ -e "/sys/class/net/$eth_iface" ] || fail "Ethernet interface not found"
[ ! -d "/sys/class/net/$eth_iface/wireless" ] || fail "bridge uplink must be Ethernet"

ap_iface=""
if [ "$action" != --disable ]; then
  printf '%s' "$ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "invalid AP MAC address"
  for net_path in /sys/class/net/*; do
    [ -r "$net_path/address" ] || continue
    [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$ap_mac" ] || continue
    candidate_iface="$(basename "$net_path")"
    is_wireless "$candidate_iface" || continue
    ap_iface="$candidate_iface"
    break
  done
  [ -n "$ap_iface" ] || fail "selected AP interface not found"
  [ "$(cat "/sys/class/net/$eth_iface/carrier" 2>/dev/null || echo 0)" = 1 ] || fail "Ethernet carrier is down"
  printf '%s' "$gateway_arg" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "invalid management gateway"
fi

if [ "$action" = --validate-only ]; then
  addresses="$(ip -4 -o address show dev "$eth_iface" scope global | awk '{print $4}' | paste -sd, -)"
  [ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
  method="$(awk -v iface="$eth_iface" '$1 == "iface" && $2 == iface && $3 == "inet" {print $4; exit}' "$interfaces_file")"
  case "$method" in dhcp|static) ;; *) fail "unsupported ifupdown IPv4 method: ${method:-unknown}" ;; esac
  echo "Ethernet Bridge validation passed"
  echo "Backend: ifupdown"
  echo "Bridge: $bridge_iface; Ethernet: $eth_iface; AP: $ap_iface"
  echo "IPv4 configuration source: $method"
  exit 0
fi

if [ "$action" = --disable ]; then
  [ -r "$state_file" ] || fail "no saved ifupdown bridge state"
  saved_backup="$(sed -n 's/^interfaces_backup=//p' "$state_file" | head -n 1)"
  [ -n "$saved_backup" ] && [ -f "$saved_backup" ] || fail "saved ifupdown configuration is missing"
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  ifdown --force "$bridge_iface" >/dev/null 2>&1 || true
  cp -a "$saved_backup" "$interfaces_file"
  ifup "$eth_iface" >/dev/null 2>&1 || true
  if [ -f "$hostapd_conf" ]; then sed -i '/^bridge=br0$/d' "$hostapd_conf"; fi
  for conf in $dual_hostapd_configs; do
    [ -f "$conf" ] || continue
    sed -i 's/^bridge=br0$/bridge=openap0/' "$conf"
  done
  rm -f "$state_file"
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  echo "Ethernet bridge disabled; restored ifupdown Ethernet configuration"
  exit 0
fi

addresses="$(ip -4 -o address show dev "$eth_iface" scope global | awk '{print $4}' | paste -sd, -)"
[ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
method="$(awk -v iface="$eth_iface" '$1 == "iface" && $2 == iface && $3 == "inet" {print $4; exit}' "$interfaces_file")"
case "$method" in dhcp|static) ;; *) fail "unsupported ifupdown IPv4 method: ${method:-unknown}" ;; esac

if [ "$action" = --gateway ]; then
  [ "$(ini_value current)" = ap_ethernet_bridge ] || fail "Ethernet Bridge is not active"
  backup_dir="/etc/openap/backups/bridge-gateway-ifupdown-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$backup_dir"
  cp -a "$interfaces_file" "$backup_dir/interfaces"
  python3 - "$interfaces_file" "$bridge_iface" "$gateway_arg" <<'PY'
import pathlib, re, sys
path, bridge, gateway = sys.argv[1:]
lines = pathlib.Path(path).read_text().splitlines(True)
out = []
active = False
done = False
for line in lines:
    if re.match(r'^\s*iface\s+' + re.escape(bridge) + r'\s+', line):
        active = True
    elif re.match(r'^\s*(auto|allow-|iface|mapping|source|source-directory)\b', line):
        active = False
    if active and re.match(r'^\s*gateway\s+', line):
        out.append('    gateway %s\n' % gateway)
        done = True
    else:
        out.append(line)
if not done:
    raise SystemExit('br0 stanza has no gateway line; refusing an ambiguous update')
pathlib.Path(path).write_text(''.join(out))
PY
  ifdown --force "$bridge_iface" >/dev/null 2>&1 || true
  ifup "$bridge_iface"
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway_arg([[:space:]]|$)" || fail "Bridge management gateway did not converge"
  profile_tmp="$(mktemp)"
  awk -v gateway="$gateway_arg" 'BEGIN{done=0} /^ethernet_gateway[[:space:]]*=/{print "ethernet_gateway = " gateway;done=1;next} {print} END{if(!done)print "ethernet_gateway = " gateway}' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
  echo "Ethernet Bridge management gateway updated: $gateway_arg"
  echo "Backup: $backup_dir"
  exit 0
fi

backup_dir="/etc/openap/backups/ap-ethernet-bridge-ifupdown-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
cp -a "$interfaces_file" "$backup_dir/interfaces"
[ ! -e "$hostapd_conf" ] || cp -a "$hostapd_conf" "$backup_dir/hostapd.conf"
for conf in $dual_hostapd_configs; do [ ! -e "$conf" ] || cp -a "$conf" "$backup_dir/$(basename "$conf")"; done

rollback() {
  rc=$?
  trap - EXIT INT TERM
  [ "$rc" -eq 0 ] && return 0
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  ifdown --force "$bridge_iface" >/dev/null 2>&1 || true
  cp -a "$backup_dir/interfaces" "$interfaces_file"
  ifup "$eth_iface" >/dev/null 2>&1 || true
  [ ! -f "$backup_dir/hostapd.conf" ] || cp -a "$backup_dir/hostapd.conf" "$hostapd_conf"
  for conf in $dual_hostapd_configs; do
    [ ! -f "$backup_dir/$(basename "$conf")" ] || cp -a "$backup_dir/$(basename "$conf")" "$conf"
  done
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  echo "Bridge activation failed; original ifupdown configuration restored" >&2
  exit "$rc"
}
trap rollback EXIT INT TERM

python3 - "$interfaces_file" "$eth_iface" "$bridge_iface" "$method" "$addresses" "$gateway_arg" <<'PY'
import ipaddress
import pathlib
import re
import sys

path, eth, bridge, method, addresses, gateway = sys.argv[1:]
text = pathlib.Path(path).read_text()
if re.search(r'^\s*iface\s+' + re.escape(bridge) + r'\s+', text, re.M):
    raise SystemExit('an existing br0 ifupdown stanza was found; refusing to overwrite it')
lines = text.splitlines(True)
out = []
i = 0
while i < len(lines):
    line = lines[i]
    if re.match(r'^\s*(auto|allow-hotplug)\s+' + re.escape(eth) + r'(\s|$)', line):
        i += 1
        continue
    if re.match(r'^\s*iface\s+' + re.escape(eth) + r'\s+', line):
        out.append('iface %s inet manual\n' % eth)
        i += 1
        while i < len(lines) and not re.match(r'^\s*(auto|allow-|iface|mapping|source|source-directory)\b', lines[i]):
            i += 1
        continue
    out.append(line)
    i += 1
if not any(re.match(r'^\s*iface\s+' + re.escape(eth) + r'\s+', line) for line in out):
    out.append('\niface %s inet manual\n' % eth)
out.append('\nauto %s\niface %s inet %s\n' % (bridge, bridge, method))
if method == 'static':
    iface = ipaddress.ip_interface(addresses.split(',')[0])
    out.append('    address %s\n    netmask %s\n' % (iface.ip, iface.network.netmask))
    if gateway:
        out.append('    gateway %s\n' % gateway)
out.extend(['    bridge_ports %s\n' % eth, '    bridge_stp off\n', '    bridge_fd 0\n', '    bridge_maxwait 0\n'])
pathlib.Path(path).write_text(''.join(out))
PY

systemctl stop hostapd.service >/dev/null 2>&1 || true
systemctl stop dnsmasq.service >/dev/null 2>&1 || true
systemctl stop openap-firewall.service >/dev/null 2>&1 || true
systemctl stop openap-ap-address.service >/dev/null 2>&1 || true
ip -4 address flush dev "$ap_iface" scope global >/dev/null 2>&1 || true

hostapd_tmp="$(mktemp)"
awk -v iface="$ap_iface" 'BEGIN{i=0;b=0} /^interface=/{if(!i)print "interface="iface;i=1;next} /^bridge=/{if(!b)print "bridge=br0";b=1;next} {print} END{if(!i)print "interface="iface;if(!b)print "bridge=br0"}' "$hostapd_conf" > "$hostapd_tmp"
chown root:www-data "$hostapd_tmp"; chmod 0640 "$hostapd_tmp"; mv "$hostapd_tmp" "$hostapd_conf"
for conf in $dual_hostapd_configs; do
  [ -f "$conf" ] || continue
  if grep -q '^bridge=' "$conf"; then sed -i 's/^bridge=.*/bridge=br0/' "$conf"; else printf '\nbridge=br0\n' >> "$conf"; fi
done

ifdown --force "$eth_iface" >/dev/null 2>&1 || true
ifup "$bridge_iface"
tries=20
while [ "$tries" -gt 0 ]; do
  ip -4 -o address show dev "$bridge_iface" | grep -q ' inet ' && break
  tries=$((tries - 1)); sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge did not receive an IPv4 address"
ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway_arg([[:space:]]|$)" || fail "Bridge management gateway did not converge"

printf 'interfaces_backup=%s\n' "$backup_dir/interfaces" > "$state_file"
chmod 0600 "$state_file"
profile_tmp="$(mktemp)"
awk 'BEGIN{done=0} /^current[[:space:]]*=/{print "current = ap_ethernet_bridge";done=1;next} {print} END{if(!done)print "current = ap_ethernet_bridge"}' "$profile" > "$profile_tmp"
chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
systemctl restart hostapd.service
for configured_ap in $ap_iface; do
  hostapd_cli -i "$configured_ap" ping 2>/dev/null | grep -Fxq PONG || fail "hostapd is not ready on $configured_ap"
  [ "$(basename "$(readlink -f "/sys/class/net/$configured_ap/master" 2>/dev/null)")" = "$bridge_iface" ] || fail "$configured_ap is not attached to $bridge_iface"
done
mkdir -p /run/openap
date +%s > /run/openap/mode-switch
trap - EXIT INT TERM
echo "Ethernet Bridge active on $bridge_iface ($eth_iface + $ap_iface)"
echo "Backend: ifupdown"
echo "DHCP is provided by the upstream Ethernet network"
echo "Backup: $backup_dir"
