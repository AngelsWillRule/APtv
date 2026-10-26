#!/usr/bin/env bash
# Shared validation for the OpenAP administrator credential file.
#
# The file contains a username and a password_hash() value. This helper never
# prints either value; it only validates the format before a lifecycle
# operation removes or reuses the file.

openap_auth_file_is_valid() {
  local path="${1:-}" user hash
  [ -f "$path" ] || return 1

  user="$(sed -n '1p' "$path")"
  hash="$(sed -n '2p' "$path")"

  [[ "$user" =~ ^[A-Za-z0-9._-]+$ ]] || return 1
  [[ "$hash" =~ ^\$2[ay]\$[0-9]{2}\$[./A-Za-z0-9]{53}$ ]] || return 1
  [ "$(wc -l < "$path")" -ge 2 ] || return 1
}
