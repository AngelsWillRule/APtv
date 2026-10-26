#!/usr/bin/env bash
# Shared selective-backup policy for the OpenAP/APtv installer.
#
# Backups must contain recovery-critical configuration only.  Provider
# catalogs, posters, HLS/VOD segments, recordings and other regenerated data
# are deliberately excluded.

openap_backup_keep_count="${OPENAP_BACKUP_KEEP:-3}"

openap_backup_prune() {
  local root="$1" prefix="$2" keep="${3:-$openap_backup_keep_count}"
  local -a entries=()
  [ -d "$root" ] || return 0
  case "$keep" in ''|*[!0-9]*) keep=3 ;; esac
  mapfile -t entries < <(find "$root" -mindepth 1 -maxdepth 1 -type d -name "$prefix-*" -printf '%T@ %p\n' 2>/dev/null | sort -rn | cut -d' ' -f2-)
  if [ "${#entries[@]}" -gt "$keep" ]; then
    for entry in "${entries[@]:$keep}"; do
      rm -rf -- "$entry"
    done
  fi
}

openap_backup_prune_root_prefixes() {
  openap_backup_prune /var/backups/openap manager
  openap_backup_prune /var/backups/openap iptv-uninstall
  openap_backup_prune /root openap-universal-preinstall
  openap_backup_prune /root openap-iptv-preinstall
}

openap_backup_manifest() {
  local manifest="$1"
  shift
  : > "$manifest"
  local path
  for path in "$@"; do
    if [ -e "$path" ] || [ -L "$path" ]; then
      printf '%s\0' "$path" >> "$manifest"
    fi
  done
}

openap_backup_archive() {
  local archive="$1" manifest="$2"
  tar --ignore-failed-read --null --verbatim-files-from \
    --files-from="$manifest" -czf "$archive"
}
