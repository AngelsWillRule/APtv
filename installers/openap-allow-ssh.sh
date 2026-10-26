#!/bin/sh
set -eu

allow_ufw() {
  command -v ufw >/dev/null 2>&1 || return 0

  status="$(ufw status 2>/dev/null || true)"
  printf '%s\n' "$status" | grep -Fq 'Status: active' || return 0

  # No source address or interface is supplied intentionally: SSH management
  # must remain reachable through every configured host address and gateway.
  ufw allow 22/tcp comment 'OpenAP SSH management' >/dev/null 2>&1 || true
}

allow_nft_chain() {
  family="$1"
  command -v nft >/dev/null 2>&1 || return 0
  nft list chain "$family" filter input >/dev/null 2>&1 || return 0

  if nft list chain "$family" filter input 2>/dev/null \
    | grep -Eq 'tcp dport (22|ssh).*accept'; then
    return 0
  fi

  # Do not restrict by source address, destination address or input device.
  nft insert rule "$family" filter input tcp dport 22 accept >/dev/null 2>&1 || true
}

allow_ufw
allow_nft_chain inet
allow_nft_chain ip
allow_nft_chain ip6
exit 0
