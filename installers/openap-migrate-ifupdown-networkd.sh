#!/bin/sh
set -eu

action="${1:---validate-only}"
backup_root=/var/lib/openap/network-migration
network_dir=/etc/systemd/network

fail() { echo "OpenAP network migration: $1" >&2; exit 1; }
configured_interfaces() {
  awk '$1 == "iface" && $3 == "inet" && $2 != "lo" {print $2}' \
    /etc/network/interfaces /etc/network/interfaces.d/* 2>/dev/null | sort -u
}
value_for() {
  iface="$1"; key="$2"
  awk -v iface="$iface" -v key="$key" '
    $1 == "iface" {
      active=($2 == iface)
      if (active && key == "method") { print $4; exit }
      next
    }
    active && $1 == key { print $2; exit }
  ' /etc/network/interfaces /etc/network/interfaces.d/* 2>/dev/null || true
}

case "$action" in --validate-only|--apply) ;; *) fail "invalid action" ;; esac
[ "$(id -u)" -eq 0 ] || fail "must run as root"
[ -r /etc/network/interfaces ] || {
  echo "No ifupdown configuration found; migration not required."
  exit 0
}

if [ "$action" = --validate-only ]; then
  if ! systemctl is-active --quiet networking.service; then
    echo "No active ifupdown networking.service; migration not required."
    exit 0
  fi
  found=0
  for iface in $(configured_interfaces); do
    [ -e "/sys/class/net/$iface" ] || continue
    [ ! -d "/sys/class/net/$iface/bridge" ] || fail "$iface is a bridge; use a dedicated bridge migration"
    [ ! -d "/sys/class/net/$iface/wireless" ] || continue
    method="$(value_for "$iface" method)"
    case "$method" in dhcp|static) ;; *) fail "$iface uses unsupported ifupdown method: ${method:-unknown}" ;; esac
    [ "$method" != static ] || {
      [ -n "$(value_for "$iface" address)" ] || fail "$iface static configuration has no address"
      [ -n "$(value_for "$iface" netmask)" ] || fail "$iface static configuration has no netmask"
    }
    found=1
    echo "Convertible ifupdown interface: $iface ($method)"
  done
  [ "$found" -eq 1 ] || echo "No convertible active Ethernet ifupdown interface found."
  exit 0
fi

systemctl is-active --quiet networking.service || {
  echo "ifupdown networking.service is not active; migration not required."
  exit 0
}

timestamp="$(date +%Y%m%d-%H%M%S)"
backup_dir="$backup_root/$timestamp"
mkdir -p "$backup_dir" "$network_dir"
cp -a /etc/network "$backup_dir/"
[ ! -e /etc/resolv.conf ] || cp -a /etc/resolv.conf "$backup_dir/"
mkdir -p "$backup_dir/systemd-network"
cp -a "$network_dir/." "$backup_dir/systemd-network/"
temp_dir="$(mktemp -d /run/openap-network-migration.XXXXXX)"
cleanup() { rm -rf -- "$temp_dir"; }
completed=0
networkd_was_active=0
systemctl is-active --quiet systemd-networkd.service && networkd_was_active=1 || true

rollback() {
  status=$?
  [ "$completed" -eq 1 ] && { cleanup; return "$status"; }
  echo "OpenAP network migration failed; restoring previous networkd state" >&2
  rm -rf -- "$network_dir"
  mkdir -p "$network_dir"
  cp -a "$backup_dir/systemd-network/." "$network_dir/"
  if [ "$networkd_was_active" -eq 1 ]; then
    systemctl restart systemd-networkd.service >/dev/null 2>&1 || true
  else
    systemctl disable --now systemd-networkd.service >/dev/null 2>&1 || true
  fi
  systemctl start networking.service >/dev/null 2>&1 || true
  cleanup
  return "$status"
}
trap rollback EXIT INT TERM

interfaces=""
for iface in $(configured_interfaces); do
  [ -e "/sys/class/net/$iface" ] || continue
  [ ! -d "/sys/class/net/$iface/bridge" ] || fail "$iface is a bridge; use a dedicated bridge migration"
  [ ! -d "/sys/class/net/$iface/wireless" ] || continue
  method="$(value_for "$iface" method)"
  case "$method" in dhcp|static) ;; *) fail "$iface uses unsupported ifupdown method: ${method:-unknown}" ;; esac
  file="$temp_dir/10-openap-migrated-$iface.network"
  {
    printf '[Match]\nName=%s\n\n[Network]\n' "$iface"
    if [ "$method" = static ]; then
      address="$(value_for "$iface" address)"
      netmask="$(value_for "$iface" netmask)"
      prefix="$(python3 - "$netmask" <<'PY'
import ipaddress, sys
print(ipaddress.IPv4Network('0.0.0.0/' + sys.argv[1]).prefixlen)
PY
)"
      printf 'Address=%s/%s\n' "$address" "$prefix"
      gateway="$(value_for "$iface" gateway)"
      [ -z "$gateway" ] || printf 'Gateway=%s\n' "$gateway"
    else
      printf 'DHCP=ipv4\n'
    fi
    printf 'IPv6AcceptRA=yes\nLinkLocalAddressing=ipv6\n'
  } > "$file"
  interfaces="$interfaces $iface"
done
[ -n "$interfaces" ] || fail "no supported ifupdown Ethernet interface found"

for iface in $interfaces; do
  install -o root -g root -m 0644 "$temp_dir/10-openap-migrated-$iface.network" \
    "$network_dir/10-openap-migrated-$iface.network"
done

systemctl enable --now systemd-networkd.service
systemctl stop networking.service
networkctl reload || fail "networkd reload failed; backup: $backup_dir"
for iface in $interfaces; do
  networkctl reconfigure "$iface" >/dev/null 2>&1 \
    || fail "networkd reconfigure failed for $iface; backup: $backup_dir"
done

for attempt in $(seq 1 15); do
  ready=1
  for iface in $interfaces; do
    ip -4 -o address show dev "$iface" scope global | grep -q ' inet ' || ready=0
    networkctl status "$iface" --no-pager 2>/dev/null | \
      grep -Eq '^[[:space:]]*State:.*(routable|degraded)' || ready=0
  done
  [ "$ready" -eq 1 ] && break
  sleep 1
done
[ "$ready" -eq 1 ] || fail "networkd did not configure all migrated interfaces; backup: $backup_dir"
systemctl disable networking.service >/dev/null 2>&1 || true
printf '%s\n' "$backup_dir" > /var/lib/openap/network-migration/last-success
completed=1
echo "ifupdown to systemd-networkd migration completed: $interfaces"
echo "Backup: $backup_dir"
