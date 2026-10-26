#!/bin/sh
set -eu

mode="${1:-}"
provider="${2:-}"

case "$mode" in
  enable|disable) ;;
  *) echo "Usage: $0 enable|disable cloudflare|quad9" >&2; exit 2 ;;
esac

case "$provider" in
  cloudflare)
    resolver_name=cloudflare
    plain_servers="1.1.1.1 1.0.0.1"
    ;;
  quad9)
    resolver_name=quad9-doh-ip4-port443-filter-pri
    plain_servers="9.9.9.9 149.112.112.112"
    ;;
  *)
    echo "Unsupported encrypted DNS provider: $provider" >&2
    exit 2
    ;;
esac

command -v dnscrypt-proxy >/dev/null 2>&1 || {
  echo "dnscrypt-proxy is not installed" >&2
  exit 2
}

dnscrypt_config=/etc/dnscrypt-proxy/dnscrypt-proxy.toml
dnsmasq_config=/etc/dnsmasq.d/openap-repeater.conf
state_file=/etc/openap/encrypted-dns.ini
profile=/etc/openap/repeater.ini

[ -f "$dnscrypt_config" ] || { echo "Missing $dnscrypt_config" >&2; exit 2; }
[ -f "$dnsmasq_config" ] || { echo "Missing $dnsmasq_config" >&2; exit 2; }
[ -f "$profile" ] || { echo "Missing $profile" >&2; exit 2; }

current_mode="$(awk -F ' *= *' '$1 == "current" {print $2; exit}' "$profile")"
bridge_mode=0
[ "$current_mode" != ap_ethernet_bridge ] || bridge_mode=1
gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2; exit}' "$profile")"
case "$gateway" in
  ""|*[!0-9.]*) echo "Invalid OpenAP gateway in $profile" >&2; exit 2 ;;
esac

backup_dir="/etc/openap/backups/encrypted-dns-$(date +%Y%m%d-%H%M%S)"
work_dir="$(mktemp -d /tmp/openap-encrypted-dns.XXXXXX)"
mkdir -p "$backup_dir"
cp -a "$dnscrypt_config" "$backup_dir/dnscrypt-proxy.toml"
cp -a "$dnsmasq_config" "$backup_dir/openap-repeater.conf"
[ ! -e "$state_file" ] || cp -a "$state_file" "$backup_dir/encrypted-dns.ini"

applied=0
cleanup() {
  rm -rf "$work_dir"
}
start_dnscrypt() {
  # Starting the socket and service in a single systemctl transaction can race:
  # dnscrypt-proxy may inherit descriptors from the socket that is then
  # restarted.  Keep the ordering explicit on Debian, Ubuntu and Raspberry Pi
  # OS so the readiness probe always targets the descriptors held by the
  # running service.
  systemctl enable dnscrypt-proxy.socket dnscrypt-proxy.service >/dev/null
  systemctl stop dnscrypt-proxy.service
  systemctl restart dnscrypt-proxy.socket
  systemctl start dnscrypt-proxy.service
}
rollback() {
  cp -a "$backup_dir/dnscrypt-proxy.toml" "$dnscrypt_config"
  cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_config"
  if [ -f "$backup_dir/encrypted-dns.ini" ]; then
    cp -a "$backup_dir/encrypted-dns.ini" "$state_file"
  else
    rm -f "$state_file"
  fi
  restored_encrypted=false
  if [ -f "$state_file" ]; then
    restored_encrypted="$(awk -F ' *= *' '$1 == "enabled" {print $2; exit}' "$state_file")"
  fi
  if [ "$restored_encrypted" = true ]; then
    start_dnscrypt >/dev/null 2>&1 || true
  else
    systemctl disable --now dnscrypt-proxy.service dnscrypt-proxy.socket >/dev/null 2>&1 || true
  fi
  if [ "$bridge_mode" -eq 0 ]; then
    systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
    systemctl restart dnsmasq.service >/dev/null 2>&1 || true
  fi
}
finish() {
  rc=$?
  trap - EXIT
  if [ "$rc" -ne 0 ] && [ "$applied" -eq 1 ]; then
    rollback
    echo "Encrypted DNS apply failed; previous configuration restored from $backup_dir" >&2
  fi
  cleanup
  exit "$rc"
}
trap finish EXIT

dns_query() {
  python3 - "$1" "$2" <<'PY'
import os
import socket
import struct
import sys

host = sys.argv[1]
port = int(sys.argv[2])
query_id = int.from_bytes(os.urandom(2), "big")
name = b"".join(bytes([len(label)]) + label.encode("ascii") for label in "example.com".split(".")) + b"\0"
packet = struct.pack("!HHHHHH", query_id, 0x0100, 1, 0, 0, 0) + name + struct.pack("!HH", 1, 1)
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(2)
try:
    sock.sendto(packet, (host, port))
    response, _ = sock.recvfrom(4096)
except OSError as exc:
    print(f"DNS query to {host}:{port} failed: {exc}", file=sys.stderr)
    raise SystemExit(1)
if len(response) < 12:
    raise SystemExit("short DNS response")
response_id, flags, _, answers, _, _ = struct.unpack("!HHHHHH", response[:12])
if response_id != query_id or flags & 0x000f or answers < 1:
    raise SystemExit("invalid DNS response")
PY
}

awk -v resolver="$resolver_name" '
  /^server_names[[:space:]]*=/ {
    print "server_names = [\x27" resolver "\x27]"
    replaced = 1
    next
  }
  {print}
  END {
    if (!replaced) print "server_names = [\x27" resolver "\x27]"
  }
' "$dnscrypt_config" > "$work_dir/dnscrypt-proxy.toml"

# Local and encrypted DNS both require dnsmasq to listen on the gateway.  An
# earlier External DNS policy may have left port=0 in this file.
awk '!/^server=/ && $0 != "no-resolv" && $0 != "port=0" {print}' "$dnsmasq_config" > "$work_dir/dnsmasq.conf"
if [ "$mode" = enable ]; then
  printf '%s\n' "server=127.0.2.1" "no-resolv" >> "$work_dir/dnsmasq.conf"
else
  for server in $plain_servers; do
    printf 'server=%s\n' "$server" >> "$work_dir/dnsmasq.conf"
  done
  printf '%s\n' "no-resolv" >> "$work_dir/dnsmasq.conf"
fi

dnscrypt_group="$(id -gn _dnscrypt-proxy 2>/dev/null || printf '%s' _dnscrypt-proxy)"
install -d -o _dnscrypt-proxy -g "$dnscrypt_group" -m 0755 /var/cache/dnscrypt-proxy

dnscrypt-proxy -check -config "$work_dir/dnscrypt-proxy.toml" >/dev/null
dnsmasq --test --conf-file="$work_dir/dnsmasq.conf" >/dev/null

applied=1
install -o root -g root -m 0644 "$work_dir/dnscrypt-proxy.toml" "$dnscrypt_config"

if [ "$mode" = enable ]; then
  start_dnscrypt
  systemctl is-active --quiet dnscrypt-proxy.socket
  systemctl is-active --quiet dnscrypt-proxy.service
  dnscrypt_ready=0
  dnscrypt_attempt=1
  while [ "$dnscrypt_attempt" -le 15 ]; do
    if dns_query 127.0.2.1 53 2>/dev/null; then
      dnscrypt_ready=1
      break
    fi
    dnscrypt_attempt=$((dnscrypt_attempt + 1))
    sleep 1
  done
  if [ "$dnscrypt_ready" -ne 1 ]; then
    echo "dnscrypt-proxy did not become ready on 127.0.2.1:53" >&2
    exit 1
  fi
fi

if [ "$bridge_mode" -eq 0 ]; then
  install -o root -g root -m 0644 "$work_dir/dnsmasq.conf" "$dnsmasq_config"
  systemctl reset-failed dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart dnsmasq.service
  systemctl is-active --quiet dnsmasq.service
  dns_query "$gateway" 53
fi

if [ "$mode" = disable ]; then
  systemctl disable --now dnscrypt-proxy.service dnscrypt-proxy.socket >/dev/null 2>&1 || true
fi

{
  printf '[encrypted_dns]\n'
  printf 'enabled = %s\n' "$([ "$mode" = enable ] && printf true || printf false)"
  printf 'provider = %s\n' "$provider"
  printf 'resolver = %s\n' "$resolver_name"
  printf 'transport = %s\n' "$([ "$mode" = enable ] && printf doh || printf plain)"
  printf 'local_endpoint = %s\n' "$([ "$mode" = enable ] && printf 127.0.2.1:53 || printf disabled)"
  printf 'enforced = %s\n' "$([ "$bridge_mode" -eq 0 ] && printf true || printf false)"
  printf 'updated = %s\n' "$(date --iso-8601=seconds)"
} > "$work_dir/encrypted-dns.ini"
install -o root -g root -m 0644 "$work_dir/encrypted-dns.ini" "$state_file"

echo "Encrypted DNS $mode completed with provider $provider. Backup: $backup_dir"
