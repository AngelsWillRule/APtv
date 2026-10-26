#!/bin/sh
set -eu

action="--apply"
if [ "${1:-}" = --apply-delayed ]; then
  action="$1"
  shift
fi
ap_mac="${1:-}"
uplink_mac="${2:-}"
profile="/etc/openap/repeater.ini"
roles="/etc/openap/wifi-roles.ini"
dnsmasq_conf="/etc/dnsmasq.d/openap-repeater.conf"
nft_conf="/etc/openap/networking/openap.nft"
radio_reset_helper="/usr/local/sbin/openap-reset-ap-radio"

fail() { echo "openap-apply-repeater-wifi: $1" >&2; exit 1; }
valid_mac() { printf '%s' "$1" | grep -Eiq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$'; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$2" 2>/dev/null || true; }
role_value() {
  awk -F ' *= *' -v key="$1" '
    /^\[active\]$/ { active=1; next }
    /^\[/ { active=0 }
    active && $1 == key { print $2; exit }
  ' "$roles" 2>/dev/null || true
}
iface_for_mac() {
  target="$(printf '%s' "$1" | tr A-F a-f)"
  for path in /sys/class/net/*; do
    [ -e "$path/address" ] || continue
    [ "$(tr A-F a-f < "$path/address")" = "$target" ] || continue
    iface="$(basename "$path")"
    # The hotspot bridge may inherit a radio MAC after boot.  Wi-Fi role MACs
    # must resolve to physical wireless interfaces, never to openap0 or any
    # other non-wireless device sharing that address.
    is_wireless_iface "$iface" || continue
    printf '%s\n' "$iface"
    return 0
  done
  return 1
}
is_wireless_iface() {
  [ -d "/sys/class/net/$1/wireless" ] && return 0
  iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$1"
}
ap_radio_needs_reset() {
  iface="$1"
  driver_path="$(readlink -f "/sys/class/net/$iface/device/driver" 2>/dev/null || true)"
  device_path="$(readlink -f "/sys/class/net/$iface/device" 2>/dev/null || true)"
  [ "$(basename "$driver_path")" = rtw88_8821cu ] || return 1
  case "$device_path" in /sys/devices/*/usb*/*:*) return 0 ;; esac
  return 1
}
wait_hostapd_ready() {
  interfaces="$*"
  attempts=10
  while [ "$attempts" -gt 0 ]; do
    ready=1
    for iface in $interfaces; do
      timeout 1 hostapd_cli -p /run/hostapd -i "$iface" ping 2>/dev/null | grep -Fxq PONG \
        || ready=0
    done
    [ "$ready" -eq 1 ] && return 0
    attempts=$((attempts - 1))
    sleep 1
  done
  return 1
}

[ "$(id -u)" -eq 0 ] || fail "This command must run as root"
valid_mac "$ap_mac" || fail "Invalid AP MAC"
valid_mac "$uplink_mac" || fail "Invalid uplink MAC"
ap_mac="$(printf '%s' "$ap_mac" | tr A-F a-f)"
uplink_mac="$(printf '%s' "$uplink_mac" | tr A-F a-f)"
[ "$ap_mac" != "$uplink_mac" ] || fail "AP and uplink MAC must differ"
[ -r "$profile" ] || fail "OpenAP profile not found"
[ -r "$roles" ] || fail "Wi-Fi role model not found"
command -v python3 >/dev/null 2>&1 || fail "python3 is required"
command -v dnsmasq >/dev/null 2>&1 || fail "dnsmasq is required"
command -v nft >/dev/null 2>&1 || fail "nft is required"

active_ap24_mac="$(role_value ap_24ghz | tr A-F a-f)"
active_ap5_mac="$(role_value ap_5ghz | tr A-F a-f)"
active_uplink_mac="$(role_value uplink | tr A-F a-f)"
[ -n "$active_uplink_mac" ] && [ "$active_uplink_mac" = "$uplink_mac" ] \
  || fail "Requested uplink is not the active UPLINK role"
[ "$ap_mac" = "$active_ap24_mac" ] || [ "$ap_mac" = "$active_ap5_mac" ] \
  || fail "Requested AP is not an active AP role"

ap24_iface=""
ap5_iface=""
[ -z "$active_ap24_mac" ] || ap24_iface="$(iface_for_mac "$active_ap24_mac" || true)"
[ -z "$active_ap5_mac" ] || ap5_iface="$(iface_for_mac "$active_ap5_mac" || true)"
uplink_iface="$(iface_for_mac "$uplink_mac" || true)"
[ -n "$ap24_iface$ap5_iface" ] || fail "No active AP interface is present"
[ -n "$uplink_iface" ] || fail "UPLINK interface is not present"
is_wireless_iface "$uplink_iface" || fail "UPLINK interface is not wireless"
for iface in $ap24_iface $ap5_iface; do
  is_wireless_iface "$iface" || fail "AP interface is not wireless: $iface"
  [ "$iface" != "$uplink_iface" ] || fail "UPLINK collides with an AP interface"
done

primary_ap="$ap24_iface"
[ -n "$primary_ap" ] || primary_ap="$ap5_iface"
profile_bridge="$(ini_value bridge "$profile")"
hotspot_iface="$profile_bridge"
if [ -n "$hotspot_iface" ]; then
  case "$hotspot_iface" in lo|*[!A-Za-z0-9_.:-]*) fail "Invalid hotspot bridge" ;; esac
  [ -d "/sys/class/net/$hotspot_iface/bridge" ] || fail "Configured hotspot bridge is not present"
else
  if [ -n "$ap24_iface" ] && [ -z "$ap5_iface" ]; then :
  elif [ -z "$ap24_iface" ] && [ -n "$ap5_iface" ]; then :
  else fail "A dual-band hotspot requires a configured bridge"
  fi
  hotspot_iface="$primary_ap"
fi

subnet="$(ini_value subnet "$profile")"
gateway="$(ini_value gateway "$profile")"
dhcp_start="$(ini_value dhcp_start "$profile")"
dhcp_end="$(ini_value dhcp_end "$profile")"
ethernet_iface="$(ini_value ethernet "$profile")"
ethernet_physical="$(ini_value ethernet_physical "$profile")"
ethernet_gateway="$(ini_value ethernet_gateway "$profile")"
country="$(ini_value country "$profile")"
subnet="${subnet:-10.88.77.0/24}"
gateway="${gateway:-10.88.77.1}"
dhcp_start="${dhcp_start:-10.88.77.50}"
dhcp_end="${dhcp_end:-10.88.77.200}"
ethernet_iface="${ethernet_iface:-eth0}"
ethernet_physical="${ethernet_physical:-$ethernet_iface}"
country="${country:-00}"
case "$subnet:$gateway:$dhcp_start:$dhcp_end" in *[!0-9./:]*|*::* ) fail "Invalid hotspot network settings" ;; esac

uplink_wpa="/etc/wpa_supplicant/wpa_supplicant-${uplink_iface}.conf"
[ -r "$uplink_wpa" ] || fail "UPLINK wpa_supplicant configuration not found"

if [ "$action" = --apply-delayed ]; then
  mkdir -p /run/openap
  printf '%s\n' scheduled > /run/openap/repeater-apply.state
  rm -f /run/openap/repeater-apply.error
  unit="openap-repeater-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-repeater-wifi "$ap_mac" "$uplink_mac"
  echo "WiFi repeater activation scheduled"
  exit 0
fi

mkdir -p /run/openap
printf '%s\n' applying > /run/openap/repeater-apply.state
rm -f /run/openap/repeater-apply.error

work_dir="$(mktemp -d /run/openap-repeater.XXXXXX)"
case "$work_dir" in /run/openap-repeater.*) ;; *) fail "Unexpected temporary directory" ;; esac
backup_dir="/etc/openap/backups/repeater-wifi-$(date +%Y%m%d-%H%M%S)"
[ ! -e "$backup_dir" ] || fail "Backup destination already exists"
mkdir -p "$backup_dir"

nm_ethernet_connection=""
nm_old_ethernet_route_metric=""
nm_old_ethernet_never_default=""
nm_ethernet_route_changed=0
ap_radio_reset_active=0
if command -v nmcli >/dev/null 2>&1 \
  && systemctl is-active --quiet NetworkManager.service; then
  nm_ethernet_connection="$(ini_value ethernet_connection "$profile")"
  [ "$nm_ethernet_connection" = "--" ] && nm_ethernet_connection=""
  if [ -z "$nm_ethernet_connection" ]; then
    nm_ethernet_connection="$(nmcli -g GENERAL.CONNECTION device show "$ethernet_iface" 2>/dev/null | head -n 1 || true)"
    [ "$nm_ethernet_connection" = "--" ] && nm_ethernet_connection=""
  fi
  if [ -n "$nm_ethernet_connection" ] \
    && nmcli -t -f NAME connection show | grep -Fxq "$nm_ethernet_connection"; then
    nm_old_ethernet_route_metric="$(nmcli -g ipv4.route-metric connection show "$nm_ethernet_connection" 2>/dev/null || true)"
    nm_old_ethernet_never_default="$(nmcli -g ipv4.never-default connection show "$nm_ethernet_connection" 2>/dev/null || true)"
    [ "$nm_old_ethernet_route_metric" = "--" ] && nm_old_ethernet_route_metric=""
    [ "$nm_old_ethernet_never_default" = "--" ] && nm_old_ethernet_never_default=""
    nm_ethernet_route_changed=1
  fi
fi

cleanup() { rm -rf -- "$work_dir"; }
trap cleanup EXIT HUP INT TERM

restore_ethernet_route() {
  [ "$nm_ethernet_route_changed" -eq 1 ] || return 0
  nmcli connection modify "$nm_ethernet_connection" \
    ipv4.never-default "${nm_old_ethernet_never_default:-no}" \
    ipv4.route-metric "${nm_old_ethernet_route_metric:-0}" \
    >/dev/null 2>&1 || true
  nmcli connection up "$nm_ethernet_connection" ifname "$ethernet_iface" \
    >/dev/null 2>&1 || true
  nm_ethernet_route_changed=0
}

encrypted_dns_enabled="$(ini_value enabled /etc/openap/encrypted-dns.ini)"
if [ "$encrypted_dns_enabled" = true ]; then
  dnsmasq_servers='server=127.0.2.1'
else
  dnsmasq_servers="$(sed -n '/^server=/p' "$dnsmasq_conf" 2>/dev/null | grep -v '^server=127\.0\.2\.1$' || true)"
  [ -n "$dnsmasq_servers" ] || dnsmasq_servers='server=1.1.1.1
server=1.0.0.1'
fi

cat > "$work_dir/dnsmasq.conf" <<EOF
interface=$hotspot_iface
bind-interfaces
listen-address=$gateway
dhcp-authoritative
dhcp-range=$dhcp_start,$dhcp_end,255.255.255.0,12h
dhcp-option=3,$gateway
dhcp-option=6,$gateway
$dnsmasq_servers
no-resolv
domain-needed
bogus-priv
EOF
dnsmasq --test --conf-file="$work_dir/dnsmasq.conf" >/dev/null

cat > "$work_dir/openap.nft" <<EOF
table ip openap_nat {
  chain postrouting {
    type nat hook postrouting priority srcnat; policy accept;
    oifname "$uplink_iface" ip saddr $subnet masquerade
  }
}
EOF
nft -c -f "$work_dir/openap.nft"

python3 - "$profile" "$work_dir/repeater.ini" "$primary_ap" "$uplink_iface" \
  "$ethernet_iface" "$ethernet_physical" "$ap_mac" "$uplink_mac" \
  "$ap24_iface" "$ap5_iface" "$profile_bridge" "$subnet" "$gateway" \
  "$dhcp_start" "$dhcp_end" "$ethernet_gateway" "$country" <<'PY'
import configparser, sys

(source, target, primary_ap, uplink, ethernet, ethernet_physical, ap_mac,
 uplink_mac, ap24, ap5, bridge, subnet, gateway, dhcp_start, dhcp_end,
 ethernet_gateway, country) = sys.argv[1:]
config = configparser.ConfigParser(interpolation=None, strict=False)
config.optionxform = str
config.read(source, encoding="utf-8")
for section in ("mode", "interfaces", "network", "wireless"):
    if not config.has_section(section):
        config.add_section(section)
config["mode"]["current"] = "repeater_wifi"
interfaces = config["interfaces"]
interfaces["ap"] = primary_ap
interfaces["uplink"] = uplink
interfaces["ethernet"] = ethernet
interfaces["ethernet_physical"] = ethernet_physical
interfaces["ap_mac"] = ap_mac
interfaces["uplink_mac"] = uplink_mac
interfaces["ap_24ghz"] = ap24
interfaces["ap_5ghz"] = ap5
if bridge:
    interfaces["bridge"] = bridge
else:
    interfaces.pop("bridge", None)
network = config["network"]
network["subnet"] = subnet
network["gateway"] = gateway
network["dhcp_start"] = dhcp_start
network["dhcp_end"] = dhcp_end
network["ethernet_gateway"] = ethernet_gateway
config["wireless"]["country"] = country
with open(target, "w", encoding="utf-8") as stream:
    config.write(stream, space_around_delimiters=True)
PY

for path in "$profile" "$dnsmasq_conf" "$nft_conf"; do
  [ -e "$path" ] || continue
  cp -a --parents "$path" "$backup_dir"
  printf '%s\n' "$path" >> "$backup_dir/existing-paths"
done
for iface in $ap24_iface $ap5_iface; do
  for path in \
    "/etc/systemd/network/20-${iface}-ap.network" \
    "/etc/systemd/network/30-${iface}-sta.network"; do
    [ -e "$path" ] || continue
    cp -a --parents "$path" "$backup_dir"
    printf '%s\n' "$path" >> "$backup_dir/existing-paths"
  done
done

rollback() {
  reason="${1:-Final repeater verification failed}"
  trap - EXIT HUP INT TERM
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    [ -e "$backup_dir$path" ] && cp -a "$backup_dir$path" "$path"
  done < "$backup_dir/existing-paths"
  restore_ethernet_route
  if [ "$ap_radio_reset_active" -eq 1 ]; then
    systemctl reset-failed hostapd.service dnsmasq.service >/dev/null 2>&1 || true
    systemctl restart hostapd.service dnsmasq.service >/dev/null 2>&1 || true
  fi
  systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart openap-firewall.service dnsmasq.service >/dev/null 2>&1 || true
  printf '%s\n' failed > /run/openap/repeater-apply.state
  printf '%s\n' "$reason" > /run/openap/repeater-apply.error
  echo "openap-apply-repeater-wifi: $reason; previous configuration restored from $backup_dir" >&2
  rm -rf -- "$work_dir"
  exit 1
}
trap rollback HUP INT TERM

install -o www-data -g www-data -m 0640 "$work_dir/repeater.ini" "$profile"
install -o root -g root -m 0644 "$work_dir/dnsmasq.conf" "$dnsmasq_conf"
install -o root -g root -m 0644 "$work_dir/openap.nft" "$nft_conf"
for iface in $ap24_iface $ap5_iface; do
  rm -f -- \
    "/etc/systemd/network/20-${iface}-ap.network" \
    "/etc/systemd/network/30-${iface}-sta.network"
done

# rtw88_8821cu USB radios can remain in a broken beacon/firmware state after
# hostapd has changed the AP mode during a network transition.  Reset only
# affected AP radios, never the Wi-Fi uplink or non-USB/non-rtw88 adapters.
ap_radio_reset_required=0
for iface in $ap24_iface $ap5_iface; do
  if [ -x "$radio_reset_helper" ] && ap_radio_needs_reset "$iface"; then
    ap_radio_reset_required=1
  fi
done
if [ "$ap_radio_reset_required" -eq 1 ]; then
  ap_radio_reset_active=1
  systemctl stop hostapd.service dnsmasq.service || rollback "Unable to stop hotspot services before AP radio reset"
  for iface in $ap24_iface $ap5_iface; do
    [ -x "$radio_reset_helper" ] || continue
    ap_radio_needs_reset "$iface" || continue
    "$radio_reset_helper" "$iface" || rollback "Unable to reset AP radio $iface"
  done
  [ -z "$active_ap24_mac" ] || ap24_iface="$(iface_for_mac "$active_ap24_mac" || true)"
  [ -z "$active_ap5_mac" ] || ap5_iface="$(iface_for_mac "$active_ap5_mac" || true)"
  [ -n "$ap24_iface$ap5_iface" ] || rollback "No AP interface returned after radio reset"
fi

networkctl reload
if [ "$ap_radio_reset_required" -eq 1 ]; then
  # The freshly rebound radio is managed until hostapd changes it back to AP;
  # a managed Wi-Fi interface cannot be enslaved to a Linux bridge.
  systemctl start hostapd.service || rollback "hostapd did not recover after AP radio reset"
fi
if [ -n "$profile_bridge" ]; then
  for iface in $ap24_iface $ap5_iface; do
    ip link set dev "$iface" master "$profile_bridge" || rollback "Unable to attach AP interface $iface to $profile_bridge"
    ip link set dev "$iface" up || rollback "Unable to bring AP interface $iface up"
  done
fi
if [ "$ap_radio_reset_required" -eq 1 ]; then
  systemctl start dnsmasq.service || rollback "dnsmasq did not recover after AP radio reset"
fi

# The credential step normally leaves this service connected already. Start it
# only when necessary; never restart a healthy UPLINK during the mode switch.
systemctl enable openap-uplink.service >/dev/null
systemctl is-active --quiet openap-uplink.service || systemctl start openap-uplink.service

tries=30
while [ "$tries" -gt 0 ]; do
  if iw dev "$uplink_iface" link 2>/dev/null | grep -q '^Connected to ' \
    && ip -4 -o address show dev "$uplink_iface" | grep -q ' inet '; then
    break
  fi
  tries=$((tries - 1))
  sleep 1
done
[ "$tries" -gt 0 ] || rollback "UPLINK did not become connected with IPv4"

systemctl restart openap-firewall.service || rollback "Firewall activation failed"
systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
systemctl restart dnsmasq.service || rollback "DHCP/DNS activation failed"

# Remove obsolete addresses inherited from the legacy single-AP profile. The
# hotspot gateway belongs exclusively to the common bridge.
if [ -n "$profile_bridge" ]; then
  for iface in $ap24_iface $ap5_iface; do ip -4 address del "$gateway/24" dev "$iface" >/dev/null 2>&1 || true; done
fi
ip -4 -o address show dev "$hotspot_iface" | grep -Fq " $gateway/" || rollback "Hotspot interface has no gateway address"
wait_hostapd_ready $ap24_iface $ap5_iface \
  || rollback "hostapd is not ready on all hotspot radios"
if [ -n "$profile_bridge" ]; then
  for iface in $ap24_iface $ap5_iface; do
    [ "$(basename "$(readlink -f "/sys/class/net/$iface/master" 2>/dev/null)")" = "$profile_bridge" ] || rollback "AP interface $iface is not attached to $profile_bridge"
  done
fi
systemctl is-active --quiet openap-uplink.service openap-firewall.service dnsmasq.service hostapd.service \
  || rollback "A required OpenAP service is inactive"

if [ "$nm_ethernet_route_changed" -eq 1 ]; then
  nmcli connection modify "$nm_ethernet_connection" \
    ipv4.never-default "${nm_old_ethernet_never_default:-no}" \
    ipv4.route-metric 600 \
    || rollback "Unable to lower NetworkManager Ethernet route priority"
  if ! nmcli device reapply "$ethernet_iface" >/dev/null 2>&1; then
    nmcli connection up "$nm_ethernet_connection" ifname "$ethernet_iface" >/dev/null 2>&1 \
      || rollback "Unable to reapply NetworkManager Ethernet route priority"
  fi
fi

ip -4 route show default | grep -Eq "dev $uplink_iface( |$)" \
  || rollback "Default route does not use the assigned UPLINK"
nft list table ip openap_nat 2>/dev/null | grep -Fq "oifname \"$uplink_iface\"" \
  || rollback "NAT does not use the assigned UPLINK"
grep -Fxq "interface=$hotspot_iface" "$dnsmasq_conf" || rollback "dnsmasq is not bound to the hotspot interface"

systemctl enable --now openap-uplink-watchdog.timer >/dev/null 2>&1 \
  || rollback "UPLINK watchdog activation failed"

ip route del default dev "$ethernet_iface" >/dev/null 2>&1 || true
mkdir -p /run/openap
date +%s > /run/openap/mode-switch
printf '%s\n' success > /run/openap/repeater-apply.state
rm -f /run/openap/repeater-apply.error
trap cleanup EXIT HUP INT TERM
echo "WiFi repeater active with AP interfaces:$([ -n "$ap24_iface" ] && printf ' %s' "$ap24_iface")$([ -n "$ap5_iface" ] && printf ' %s' "$ap5_iface") and uplink $uplink_iface"
echo "Hotspot interface: $hotspot_iface ($gateway)"
echo "Backup: $backup_dir"
