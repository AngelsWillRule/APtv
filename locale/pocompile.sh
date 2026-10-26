#!/usr/bin/env bash

set -euo pipefail

locale_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

command -v msgfmt >/dev/null 2>&1 || {
  echo "GNU gettext is required (missing msgfmt)." >&2
  exit 1
}

for po in "$locale_dir"/*/LC_MESSAGES/messages.po; do
  mo="${po%.po}.mo"
  msgfmt --check --check-format -o "$mo" "$po"
  echo "Compiled $po"
done
