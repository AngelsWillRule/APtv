#!/usr/bin/env bash

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

section() {
    printf '\n==> %s\n' "$1"
}

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Required command not found: %s\n' "$1" >&2
        exit 1
    fi
}

for command_name in bash git msgfmt node php python3; do
    require_command "$command_name"
done

section "Repository hygiene"
git diff --check

if git ls-files | grep -Eq '(^|/)(node_modules|__pycache__)(/|$)|\.pyc$'; then
    printf 'Generated dependency or cache files are tracked.\n' >&2
    exit 1
fi

secret_pattern='gh[pousr]_[A-Za-z0-9_]{20,}|BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY'
if git grep -I -n -E "$secret_pattern" -- . ':(exclude)tests/ci-checks.sh'; then
    printf 'Possible private infrastructure data or credential found.\n' >&2
    exit 1
fi

section "PHP syntax"
while IFS= read -r -d '' source_file; do
    php -l "$source_file" >/dev/null
done < <(find . -path ./.git -prune -o -type f -name '*.php' -print0)

section "Installed version metadata"
version_value="$(tr -d '\r\n' < VERSION)"
[[ "$version_value" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$ ]]
php -r '
require "includes/openap_version.php";
$path = tempnam(sys_get_temp_dir(), "openap-version-");
file_put_contents($path, "version=0.2.0\nrevision=9b264faf\n");
if (openapInstalledVersion($path) !== "0.2.0 (9b264faf)") exit(1);
file_put_contents($path, "version=0.2.0\n");
if (openapInstalledVersion($path) !== "0.2.0") exit(1);
file_put_contents($path, "version=<invalid>\n");
if (openapInstalledVersion($path) !== "Not available") exit(1);
unlink($path);
if (openapInstalledVersion($path) !== "Not available") exit(1);
'

section "Shell syntax"
while IFS= read -r -d '' source_file; do
    bash -n "$source_file"
done < <(find . -path ./.git -prune -o -type f -name '*.sh' -print0)
bash -n openap-installer/bin/openap-install
sh -n openap-installer/bin/openap-apply-dual-hostapd
sh -n installers/openap-prepare-ap-interface.sh
grep -Fq 'baseline_requires_bridge=0' openap-installer/bin/openap-apply-dual-hostapd
grep -Fq '[ "$baseline_requires_bridge" -eq 1 ]' openap-installer/bin/openap-apply-dual-hostapd
grep -Fq 'Address=$gateway/$hotspot_prefix' openap-installer/bin/openap-apply-dual-hostapd
grep -Fq 'ip link add name "$bridge_iface" type bridge' installers/openap-prepare-ap-interface.sh
grep -Fq '/usr/bin/nmcli radio wifi on' installers/openap-prepare-ap-interface.sh
grep -Fq 'After=network-pre.target NetworkManager.service systemd-rfkill.service' installers/openap-ap-address.service
grep -Fq 'Requires=openap-ap-address.service hostapd.service' installers/20-openap-ap-address.conf
grep -Fq 'ExecCondition=/usr/local/sbin/openap-routed-service-required' openap-installer/config/25-openap-dnsmasq-mode.conf
grep -Fq 'Requires=hostapd.service' openap-installer/config/25-openap-dnsmasq-mode.conf
grep -Fq 'After=hostapd.service' openap-installer/config/25-openap-dnsmasq-mode.conf
grep -Fq 'ExecCondition=/usr/local/sbin/openap-routed-service-required' openap-installer/config/25-openap-firewall-mode.conf
grep -Fq '[ "$mode" != ap_ethernet_bridge ]' installers/openap-routed-service-required.sh
grep -Fq 'Timed out waiting for configured OpenAP AP interfaces:' installers/openap-wait-ap-address.sh
grep -Fq 'systemctl reset-failed hostapd.service dnsmasq.service' installers/openap-apply-ap-ethernet.sh
grep -Fq 'systemctl reset-failed dnsmasq.service' installers/openap-apply-repeater-wifi.sh
grep -Fq 'release_repeater_uplink 1' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'is_raspberry_pi' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'release_raspberry_networkd_for_nm' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'systemctl stop systemd-networkd.service' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'nmcli connection down "$original_con"' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'ip -4 route get "$gateway_arg" oif "$eth_iface"' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'openap-uplink-watchdog.timer' installers/openap-apply-ap-ethernet.sh
grep -Fq 'systemctl reset-failed openap-uplink-watchdog.service' installers/openap-apply-ap-ethernet.sh
grep -Fq 'ip -4 route get "$ethernet_gateway" oif "$ethernet_iface"' installers/openap-apply-ap-ethernet.sh
grep -Fq 'systemctl reset-failed openap-uplink-watchdog.service' installers/openap-apply-ap-ethernet-bridge.sh
grep -Fq 'systemctl enable --now openap-uplink-watchdog.timer' installers/openap-apply-repeater-wifi.sh
grep -Fq "var transactionSucceeded = applyState.state === 'success'" app/js/ui/dashboard.js
grep -Fq 'if (transactionSucceeded) notifyTrackedSuccess();' app/js/ui/dashboard.js
grep -Fq 'notificationState.successShown = true;' app/js/ui/dashboard.js
grep -Fq 'if (transactionSucceeded) {' app/js/ui/dashboard.js
grep -Fq 'modeAnimation.complete(reconcileWidgets, verifiedDocument ? html' app/js/ui/dashboard.js
grep -Fq 'delete window.openapLocalModeSwitchGuard;' app/js/ui/dashboard.js
grep -Fq 'systemctl reset-failed dnsmasq.service' installers/openap-apply-dhcp-settings.sh
grep -Fq 'systemctl reset-failed dnsmasq.service' installers/openap-apply-encrypted-dns.sh
grep -Fq 'systemctl reset-failed hostapd.service dnsmasq.service' openap-installer/bin/openap-apply-dual-hostapd
grep -Fq 'inhibit_package_service_starts' openap-installer/bin/openap-install
grep -Fq 'exit 101' openap-installer/bin/openap-install
grep -Fq 'release_package_start_policy' openap-installer/bin/openap-install
grep -Fq 'lighttpd-maint.timer' openap-installer/bin/openap-install
grep -Fq 'phpsessionclean.timer' openap-installer/bin/openap-install
if grep -Fq 'systemctl mask --runtime' openap-installer/bin/openap-install; then
    printf 'Installer must not runtime-mask package units before apt configures them.\n' >&2
    exit 1
fi
grep -Fq 'persist_service_state' openap-installer/bin/openap-install
grep -Fq 'disable_new_dnscrypt_services' openap-installer/bin/openap-install
grep -Fq 'resume_preexisting_dnscrypt_services' openap-installer/bin/openap-install
grep -Fq 'platform_update_preflight' openap-installer/bin/openap-install
grep -Fq 'apt-get --simulate dist-upgrade' openap-installer/bin/openap-install
grep -Fq 'args+=(--allow-pending-updates)' openap-installer/bin/openap-manager
grep -Fq '[ "$allow_pending_updates" -eq 1 ] && args+=(--allow-pending-updates)' openap-installer/bin/openap-install
grep -Fq '[ "$allow_pending_updates" -eq 1 ] && iptv_args+=(--allow-pending-updates)' openap-installer/bin/openap-install
grep -Fq -- '--allow-pending-updates)' openap-installer/bin/openap-iptv-install
grep -Fq -- '--no-install-recommends --no-upgrade' openap-installer/bin/openap-install
if grep -Eq '^[[:space:]]*run apt-get (--fix-broken|upgrade|dist-upgrade|full-upgrade)' openap-installer/bin/openap-install; then
    printf 'Installer must not repair or upgrade the host operating system.\n' >&2
    exit 1
fi
if grep -Fq 'platform_core_packages=' openap-installer/bin/openap-install; then
    printf 'Installer must not carry a host core-package upgrade set.\n' >&2
    exit 1
fi
grep -Fq 'restore_managed_service_state' openap-installer/bin/openap-uninstall
grep -Fq 'dnscrypt-proxy.toml' openap-installer/bin/openap-uninstall
grep -Fq 'ip link delete dev openap0 type bridge' openap-installer/bin/openap-uninstall
python3 -c 'compile(open("openap-installer/bin/openap-detect", encoding="utf-8").read(), "openap-detect", "exec")'
python3 tests/test_openap_detect.py
python3 tests/test_generate_dual_hostapd.py
php tests/wifi-inventory.php
php tests/wifi-roles.php
php tests/user-preferences.php

section "JSON syntax"
while IFS= read -r -d '' source_file; do
    python3 -m json.tool "$source_file" >/dev/null
done < <(find . -path ./.git -prune -o -type f -name '*.json' -print0)

section "JavaScript module syntax"
while IFS= read -r -d '' source_file; do
    node --input-type=module --check <"$source_file"
done < <(find app/js -type f -name '*.js' ! -name '*.min.js' -print0)

section "Translations"
compiled_mo="$(mktemp)"
dry_run_log="$(mktemp)"
cleanup() {
    rm -f -- "$compiled_mo" "$dry_run_log"
}
trap cleanup EXIT

while IFS= read -r -d '' po_file; do
    mo_file="${po_file%.po}.mo"
    test -s "$mo_file"
    msgfmt --check --check-format -o "$compiled_mo" "$po_file"
    cmp --silent "$compiled_mo" "$mo_file"
done < <(find locale -type f -name '*.po' -print0)

section "Installer dry run"
openap-installer/bin/openap-install --yes >"$dry_run_log"
grep -Fq 'No changes were made.' "$dry_run_log"
grep -Fq 'DRY-RUN: write OpenAP version metadata to /etc/openap/release' "$dry_run_log"
grep -Fq 'DRY-RUN: do not update, repair or upgrade the operating system' "$dry_run_log"
grep -Fq -- '--no-install-recommends --no-upgrade' "$dry_run_log"

section "Installer Wi-Fi role model"
grep -Fq '/etc/openap/wifi-roles.ini' openap-installer/bin/openap-install
grep -Fq 'ap_24ghz = $ap_mac' openap-installer/bin/openap-install
grep -Fq 'uplink = $uplink_mac' openap-installer/bin/openap-install
grep -Fq 'if [ ! -e /etc/openap/wifi-roles.ini ]; then' openap-installer/bin/openap-install
grep -Fq 'mv "$roles_tmp" /etc/openap/wifi-roles.ini' openap-installer/bin/openap-install

printf '\nAll CI checks passed.\n'
