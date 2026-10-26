#!/usr/bin/env bash
# Unified APtv installer and component manager entrypoint.
set -euo pipefail
script_path="$(readlink -f "${BASH_SOURCE[0]}")"
repo_dir="$(cd "$(dirname "$script_path")" && pwd)"
exec "$repo_dir/openap-installer/bin/openap-manager" "$@"
