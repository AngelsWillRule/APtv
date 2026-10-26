#!/bin/sh
set -eu

validate_only=0
preserve_dns=0
if [ "${1:-}" = "--scheduled" ]; then
  shift
  if "$0" "$@" >>/var/log/openap-dhcp-apply.log 2>&1; then
    state=success
    result=0
  else
    state=failed
    result=$?
  fi
  printf '%s\n' "$state" > /run/openap/dhcp-apply-status
  chmod 0644 /run/openap/dhcp-apply-status
  exit "$result"
fi

if [ "${1:-}" = "--validate-only" ]; then
  validate_only=1
  shift
fi

if [ "${1:-}" = "--preserve-dns" ]; then
  preserve_dns=1
  shift
fi

if [ "${1:-}" = "--delayed" ]; then
  shift
  mkdir -p /run/openap
  printf '%s\n' applying > /run/openap/dhcp-apply-status
  chmod 0644 /run/openap/dhcp-apply-status
  unit="openap-dhcp-apply-$(date +%s)-$$"
  /usr/bin/systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    "$0" --scheduled "$@"
  echo "DHCP settings scheduled"
  exit 0
fi

subnet="${1:-}"
gateway="${2:-}"
dhcp_start="${3:-}"
dhcp_end="${4:-}"
lease_time="${5:-}"
dns_policy="${6:-}"
advertised_dns="${7:-}"
upstream_dns="${8:-}"

if [ "$preserve_dns" -eq 1 ]; then
  active_dnsmasq=/etc/dnsmasq.d/openap-repeater.conf
  [ -r "$active_dnsmasq" ] || { echo 'Active OpenAP DNS configuration not found' >&2; exit 2; }
  profile=/etc/openap/repeater.ini
  previous_gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2; exit}' "$profile")"
  advertised_dns="$(sed -n 's/^dhcp-option=6,//p' "$active_dnsmasq" | head -1)"
  upstream_dns="$(sed -n 's/^server=//p' "$active_dnsmasq" | paste -sd, -)"
  [ -n "$advertised_dns" ] || advertised_dns="$previous_gateway"
  [ -n "$upstream_dns" ] || upstream_dns="$advertised_dns"
  if [ -n "$previous_gateway" ] && [ "$advertised_dns" = "$previous_gateway" ]; then
    dns_policy=local
    advertised_dns="$gateway"
  else
    dns_policy=external
  fi
fi

validation="$(python3 - "$subnet" "$gateway" "$dhcp_start" "$dhcp_end" "$lease_time" "$dns_policy" "$advertised_dns" "$upstream_dns" <<'PY'
import ipaddress, re, sys
try:
    net = ipaddress.IPv4Network(sys.argv[1], strict=True)
    gateway = ipaddress.IPv4Address(sys.argv[2])
    start = ipaddress.IPv4Address(sys.argv[3])
    end = ipaddress.IPv4Address(sys.argv[4])
    if net.prefixlen != 24:
        raise ValueError('OpenAP DHCP requires a /24 subnet')
    if any(ip not in net or ip in (net.network_address, net.broadcast_address) for ip in (gateway, start, end)):
        raise ValueError('Gateway and DHCP range must be usable addresses in the subnet')
    if gateway != net.network_address + 1:
        raise ValueError('Gateway must be the first usable address in the subnet')
    if int(start) > int(end) or int(start) <= int(gateway) <= int(end):
        raise ValueError('Invalid DHCP range or gateway inside the pool')
    if not re.fullmatch(r'[1-9][0-9]*[mhdw]', sys.argv[5], re.I):
        raise ValueError('Invalid lease time')
    if sys.argv[6] not in ('local', 'external'):
        raise ValueError('Invalid DNS policy')
    def addresses(value):
        result = [part.strip() for part in value.split(',') if part.strip()]
        if not result: raise ValueError('At least one DNS address is required')
        for item in result: ipaddress.IPv4Address(item)
        return result
    advertised = addresses(sys.argv[7])
    upstream = addresses(sys.argv[8])
    if sys.argv[6] == 'local': advertised = [str(gateway)]
    print(net.netmask, net.prefixlen, ','.join(advertised), ','.join(upstream), sep='|')
except ValueError as exc:
    print(str(exc), file=sys.stderr)
    raise SystemExit(2)
PY
)"
IFS='|' read -r netmask prefix advertised_dns upstream_dns <<EOF
$validation
EOF

profile=/etc/openap/repeater.ini
ap_iface="$(awk -F ' *= *' '$1 == "bridge" {print $2; exit}' "$profile")"
[ -n "$ap_iface" ] || ap_iface="$(awk -F ' *= *' '$1 == "ap" {print $2; exit}' "$profile")"
address_backend="$(awk -F ' *= *' '$1 == "address_backend" {print $2; exit}' "$profile")"
[ -n "$address_backend" ] || address_backend=systemd-networkd
# NetworkManager deliberately leaves the hotspot interface unmanaged; OpenAP's
# dedicated address service owns its static AP address in this mode.
if [ "$address_backend" = NetworkManager ]; then
  address_backend=openap-ap-address
fi
[ -n "$ap_iface" ] && [ -e "/sys/class/net/$ap_iface" ] || { echo 'AP interface not found' >&2; exit 2; }

python3 - "$subnet" "$ap_iface" <<'PY'
import ipaddress
import json
import subprocess
import sys

requested = ipaddress.IPv4Network(sys.argv[1], strict=True)
ap_iface = sys.argv[2]

def run_json(*command):
    return json.loads(subprocess.check_output(command, text=True))

for interface in run_json("ip", "-j", "-4", "address", "show"):
    name = interface.get("ifname", "")
    if name == ap_iface:
        continue
    for info in interface.get("addr_info", []):
        if info.get("family") != "inet" or info.get("scope") != "global":
            continue
        active = ipaddress.IPv4Interface(
            f"{info['local']}/{info['prefixlen']}"
        ).network
        if requested.overlaps(active):
            print(
                f"Hotspot subnet {requested} overlaps interface {name} "
                f"({info['local']}/{info['prefixlen']}). Choose a different "
                "/24 network so client and uplink routes remain unambiguous.",
                file=sys.stderr,
            )
            raise SystemExit(2)

for route in run_json("ip", "-j", "-4", "route", "show", "table", "all"):
    destination = route.get("dst")
    device = route.get("dev", "")
    if not destination or destination == "default" or device == ap_iface:
        continue
    try:
        active = ipaddress.IPv4Network(destination, strict=False)
    except ValueError:
        continue
    if requested.overlaps(active):
        print(
            f"Hotspot subnet {requested} overlaps active route {active}"
            + (f" on {device}" if device else "")
            + ". Choose a different /24 network.",
            file=sys.stderr,
        )
        raise SystemExit(2)
PY

network_file=""
case "$address_backend" in
  openap-ap-address)
    systemctl cat openap-ap-address.service >/dev/null 2>&1 || {
      echo 'OpenAP AP-address service is not installed' >&2
      exit 2
    }
    bridge_network_file=/etc/systemd/network/20-openap-hotspot.network
    if [ -d "/sys/class/net/$ap_iface/bridge" ] \
      && [ -f "$bridge_network_file" ] \
      && awk -F ' *= *' -v iface="$ap_iface" '$1 == "Name" && $2 == iface {found=1} END {exit !found}' "$bridge_network_file"; then
      network_file="$bridge_network_file"
    fi
    ;;
  systemd-networkd)
    network_file="$(networkctl status "$ap_iface" --no-pager 2>/dev/null | sed -n 's/^[[:space:]]*Network File:[[:space:]]*//p' | head -1)"
    case "$network_file" in
      ""|"n/a") network_file="" ;;
    esac
    if [ -z "$network_file" ] || [ ! -f "$network_file" ]; then
      for candidate in /etc/systemd/network/*.network; do
        [ -f "$candidate" ] || continue
        if awk -v iface="$ap_iface" '
          /^\[/ {
            in_match = ($0 == "[Match]")
            next
          }
          in_match && /^[[:space:]]*Name[[:space:]]*=/ {
            value = $0
            sub(/^[^=]*=[[:space:]]*/, "", value)
            count = split(value, names, /[[:space:]]+/)
            for (i = 1; i <= count; i++) {
              if (names[i] == iface) {
                found = 1
                exit
              }
            }
          }
          END { exit found ? 0 : 1 }
        ' "$candidate"; then
          network_file="$candidate"
          break
        fi
      done
    fi
    if [ -z "$network_file" ] || [ ! -f "$network_file" ]; then
      # A single-band AP may intentionally be left unmanaged by networkd:
      # openap-ap-address owns the gateway directly on the radio instead.
      # Profiles created before that ownership was recorded can still say
      # systemd-networkd, so determine the effective backend from the live
      # interface rather than rejecting every later subnet change.
      if systemctl cat openap-ap-address.service >/dev/null 2>&1 \
        && LC_ALL=C networkctl status "$ap_iface" --no-pager 2>/dev/null \
          | grep -q 'Network File:[[:space:]]*n/a'; then
        address_backend=openap-ap-address
        network_file=""
      else
        echo "No systemd-networkd file matches AP interface $ap_iface" >&2
        exit 2
      fi
    fi
    ;;
  *)
    echo "Unsupported OpenAP address backend: $address_backend" >&2
    exit 2
    ;;
esac
dnsmasq_file=/etc/dnsmasq.d/openap-repeater.conf
nft_file=/etc/openap/networking/openap.nft
backup_dir="/etc/openap/backups/dhcp-$(date +%Y%m%d-%H%M%S)"
work_dir="$(mktemp -d /tmp/openap-dhcp.XXXXXX)"
mkdir -p "$backup_dir"
for file in "$profile" "$dnsmasq_file" "$nft_file"; do [ ! -e "$file" ] || cp -a "$file" "$backup_dir/"; done
[ -z "$network_file" ] || cp -a "$network_file" "$backup_dir/"
cleanup() { rm -rf "$work_dir"; }
applied=0
finish() {
  rc=$?
  trap - EXIT
  if [ "$rc" -ne 0 ] && [ "$applied" -eq 1 ]; then
    [ ! -f "$backup_dir/repeater.ini" ] || cp -a "$backup_dir/repeater.ini" "$profile"
    if [ -n "$network_file" ] && [ -f "$backup_dir/$(basename "$network_file")" ]; then
      cp -a "$backup_dir/$(basename "$network_file")" "$network_file"
    fi
    [ ! -f "$backup_dir/openap.nft" ] || cp -a "$backup_dir/openap.nft" "$nft_file"
    [ ! -f "$backup_dir/openap-repeater.conf" ] || cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_file"
    if [ "$address_backend" = openap-ap-address ]; then
      systemctl stop hostapd.service >/dev/null 2>&1 || true
      systemctl restart openap-ap-address.service >/dev/null 2>&1 || true
    else
      systemctl restart systemd-networkd.service >/dev/null 2>&1 || true
      networkctl reconfigure "$ap_iface" >/dev/null 2>&1 || true
    fi
    systemctl restart openap-firewall.service >/dev/null 2>&1 || true
    # dnsmasq Requires/After hostapd on OpenAP installations.  Starting it
    # before restarting hostapd makes systemd stop it again and can race the
    # rollback health check.
    [ "$address_backend" != openap-ap-address ] || systemctl restart hostapd.service >/dev/null 2>&1 || true
    systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
    systemctl restart dnsmasq.service >/dev/null 2>&1 || true
    echo "Apply failed; previous DHCP configuration restored from $backup_dir" >&2
  fi
  cleanup
  exit "$rc"
}
trap finish EXIT

awk -v subnet="$subnet" -v gateway="$gateway" -v start="$dhcp_start" -v end="$dhcp_end" '
  $1 == "subnet" && $2 == "=" {$0="subnet = " subnet}
  $1 == "gateway" && $2 == "=" {$0="gateway = " gateway}
  $1 == "dhcp_start" && $2 == "=" {$0="dhcp_start = " start}
  $1 == "dhcp_end" && $2 == "=" {$0="dhcp_end = " end}
  {print}
' "$profile" > "$work_dir/repeater.ini"

[ -z "$network_file" ] || sed -E "s|^Address=.*$|Address=$gateway/$prefix|" "$network_file" > "$work_dir/ap.network"
sed -E "s|ip saddr [0-9./]+ masquerade|ip saddr $subnet masquerade|" "$nft_file" > "$work_dir/openap.nft"
{
  echo "interface=$ap_iface"
  echo bind-interfaces
  echo "listen-address=$gateway"
  [ "$dns_policy" != external ] || echo port=0
  echo dhcp-authoritative
  echo "dhcp-range=$dhcp_start,$dhcp_end,$netmask,$lease_time"
  echo "dhcp-option=3,$gateway"
  echo "dhcp-option=6,$advertised_dns"
  if [ "$dns_policy" = local ]; then
    old_ifs="$IFS"; IFS=','
    for dns in $upstream_dns; do echo "server=$dns"; done
    IFS="$old_ifs"
    echo no-resolv; echo domain-needed; echo bogus-priv
  fi
} > "$work_dir/dnsmasq.conf"

dnsmasq --test --conf-file="$work_dir/dnsmasq.conf" >/dev/null
nft -c -f "$work_dir/openap.nft"
if [ "$validate_only" -eq 1 ]; then
  echo "DHCP settings validated for backend: $address_backend"
  exit 0
fi
if cmp -s "$work_dir/repeater.ini" "$profile" \
  && cmp -s "$work_dir/openap.nft" "$nft_file" \
  && cmp -s "$work_dir/dnsmasq.conf" "$dnsmasq_file" \
  && { [ -z "$network_file" ] || cmp -s "$work_dir/ap.network" "$network_file"; }; then
  echo "DHCP settings already active; no restart required."
  exit 0
fi
applied=1
install -o www-data -g www-data -m 0640 "$work_dir/repeater.ini" "$profile"
[ -z "$network_file" ] || install -o root -g root -m 0644 "$work_dir/ap.network" "$network_file"
install -o root -g root -m 0644 "$work_dir/openap.nft" "$nft_file"
install -o root -g root -m 0644 "$work_dir/dnsmasq.conf" "$dnsmasq_file"

if [ "$address_backend" = openap-ap-address ]; then
  systemctl stop hostapd.service
  # A deleted AP .network file can remain cached by a running networkd. Drop
  # that ownership before the OpenAP service toggles the interface, or the old
  # gateway will be restored alongside the newly selected DHCP gateway.
  if systemctl is-active --quiet systemd-networkd.service; then
    networkctl reload
    networkctl reconfigure "$ap_iface" >/dev/null 2>&1 || true
  fi
  systemctl restart openap-ap-address.service
else
  systemctl restart systemd-networkd.service
  networkctl reconfigure "$ap_iface" >/dev/null 2>&1 || true
  # Restarting networkd can drop the AP radio from the local routed-NAT
  # bridge. Re-attach it after the network backend is ready; the helper is
  # mode-aware and leaves Ethernet bridge transitions unchanged.
  /usr/local/sbin/openap-attach-ap-bridge
fi
systemctl restart openap-firewall.service
if [ "$address_backend" = openap-ap-address ]; then
  systemctl restart hostapd.service
fi
systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
systemctl restart dnsmasq.service
systemctl is-active --quiet dnsmasq.service
echo "DHCP settings applied. Backup: $backup_dir"
