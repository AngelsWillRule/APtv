#!/usr/bin/env bash

set -euo pipefail

locale_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_dir="$(cd "$locale_dir/.." && pwd)"
pot="$locale_dir/messages.pot"
work_dir="$(mktemp -d)"
cleanup() {
  rm -f -- \
    "$work_dir/php-files.txt" \
    "$work_dir/php-relative.txt" \
    "$work_dir/messages.po"
  rmdir -- "$work_dir"
}
trap cleanup EXIT

for tool in xgettext msgmerge msgattrib msgfmt; do
  command -v "$tool" >/dev/null 2>&1 || {
    echo "GNU gettext is required (missing $tool)." >&2
    exit 1
  }
done

find "$repo_dir" -type f -name '*.php' \
  -not -path "$locale_dir/*" \
  -print | LC_ALL=C sort > "$work_dir/php-files.txt"

(
  cd "$repo_dir"
  sed "s|^$repo_dir/|./|" "$work_dir/php-files.txt" > "$work_dir/php-relative.txt"
  xgettext \
    --language=PHP \
    --from-code=UTF-8 \
    --keyword=_ \
    --package-name=OpenAP \
    --package-version=0.2.5.1 \
    --msgid-bugs-address=https://github.com/AngelsWillRule/OpenAP/issues \
    --files-from="$work_dir/php-relative.txt" \
    --output="$pot"
)

for po in "$locale_dir"/*/LC_MESSAGES/messages.po; do
  msgmerge --quiet --update --backup=none "$po" "$pot"
  msgattrib --no-obsolete --output-file="$work_dir/messages.po" "$po"
  mv "$work_dir/messages.po" "$po"
  msgfmt --check --check-format -o "${po%.po}.mo" "$po"
done

echo "Updated $(find "$locale_dir" -type f -name '*.po' | wc -l) OpenAP catalogs."
