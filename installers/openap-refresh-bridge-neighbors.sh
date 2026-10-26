#!/bin/sh
set -eu
profile=/etc/openap/repeater.ini
[ -r "$profile" ] || exit 0
mode="$(awk -F ' *= *' '$1=="current"{print $2;exit}' "$profile")"
[ "$mode" = ap_ethernet_bridge ] || exit 0
bridge="$(awk -F ' *= *' '$1=="bridge"{print $2;exit}' "$profile")"
case "$bridge" in ''|*[!A-Za-z0-9_.:-]*) exit 0;; esac
cidr="$(ip -4 -o address show dev "$bridge" scope global | awk 'NR==1{print $4}')"
[ -n "$cidr" ] || exit 0
prefix="${cidr#*/}"
[ "$prefix" = 24 ] || exit 0
address="${cidr%/*}"
network="${address%.*}"
seq 1 254 | xargs -P 32 -I{} sh -c 'ping -n -c 1 -W 1 "$1.$2" >/dev/null 2>&1 || true' _ "$network" {}
