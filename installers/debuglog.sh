#!/bin/bash
#
# OpenAP debug log generator, derived from the RaspAP debug utility
# Author: @billz <billzimmerman@gmail.com>
# Author URI: https://github.com/billz/
# License: GNU General Public License v3.0
# License URI: https://github.com/raspap/raspap-webgui/blob/master/LICENSE
#
# Typically used in an AJAX call from the OpenAP UI, this utility may also
# be invoked directly to generate a detailed system debug log.
#
# Usage: debuglog.sh [options]
#
# OPTIONS:
# -w, --write       Writes the debug log to /tmp (useful if sourced directly)
# -i, --install     Overrides the default OpenAP install location (/var/www/html)
#
# NOTE
# Detailed system information is gathered for debugging and troubleshooting.
# Known credential fields are redacted, but users must inspect the output before
# sharing it because network and system metadata can still be sensitive.
#
# You are not obligated to bundle the LICENSE file with your RaspAP projects as long
# as you leave these references intact in the header comments of your source files.

# Exit on error
set -o errexit
# Exit on error inside functions
set -o errtrace
# Turn on traces, disabled by default
# set -o xtrace

# Set defaults
readonly RASPAP_DIR="/etc/openap"
readonly DNSMASQ_D_DIR="/etc/dnsmasq.d"
readonly RASPAP_DHCDPCD="/etc/dhcpcd.conf"
readonly RASPAP_HOSTAPD="$RASPAP_DIR/hostapd.ini"
readonly RASPAP_PROVIDER="$RASPAP_DIR/provider.ini"
readonly RASPAP_LOGPATH="/tmp"
readonly RASPAP_LOGFILE="$RASPAP_LOGPATH/raspap_debug.log"
readonly RASPAP_DEBUG_VERSION="1.0"
readonly PREAMBLE="
OpenAP Debug Log Generator $RASPAP_DEBUG_VERSION

This process collects debug and troubleshooting information about your OpenAP installation.
It is intended to support self-diagnosis and provide a useful starting point for technical
troubleshooting. The log contains the OpenAP version, service state, relevant package and
kernel versions, and local networking configuration details.

Review the complete log before sharing it. Known credential fields are redacted, but the
output still contains potentially sensitive network and system metadata.
========================================================================================"

function _main() {
    _parse_params "$@"
    _initialize
    _output_preamble
    _generate_log
}

function _parse_params() {
    # default option values
    install_dir="/var/www/html"
    writelog=0

    while :; do
        case "${1-}" in
            -w|--write)
            writelog=1
            ;;
            -i|--install)
            install_dir="$2"
            shift
            ;;
            -*|--*)
            echo "Unknown option: $1"
            _usage
            exit 1
            ;;
            *)
            break
            ;;
        esac
        shift
    done
}

function _generate_log() {
    _log_write "Debug log generation started at $(date)"
    _system_info
    _packages_info
    _openap_info
    _usb_info
    _rfkill_info
    _wpa_info
    _dnsmasq_info
    _dhcpcd_info
    _interface_info
    _routing_info
    _iw_dev_info
    _iw_reg_info
    _systemd_info
    _log_write "OpenAP debug log generation complete."
    exit 0
}

# Fetches hardware, OS, uptime & used memory
function _system_info() {
    local model="Unknown"
    if [ -r /proc/device-tree/model ]; then
        model=$(tr -d '\0' < /proc/device-tree/model)
    elif [ -r /sys/class/dmi/id/product_name ]; then
        model=$(cat /sys/class/dmi/id/product_name)
    fi
    local system_uptime=$(uptime | awk -F'( |,|:)+' '{if ($7=="min") m=$6; else {if ($7~/^day/){if ($9=="min") {d=$6;m=$8} else {d=$6;h=$8;m=$9}} else {h=$6;m=$7}}} {print d+0,"days,",h+0,"hours,",m+0,"minutes"}')
    local free_mem=$(free -m | awk 'NR==2{ total=$2 ; used=$3 } END { print used/total*100}')
    _log_separator "System Info"
    _log_write "Hardware: ${model}"
    _log_write "Detected OS: ${DESC} ${LONG_BIT}-bit"
    _log_write "Kernel: ${KERNEL}"
    _log_write "System Uptime: ${system_uptime}"
    _log_write "Memory Usage: ${free_mem}%"
}

# Fetch installed package versions
function _packages_info() {
    local php_version="Not present"
    local dnsmasq_version="Not present"
    local dhcpcd_version="Not present"
    local lighttpd_version="Not present"
    local vnstat_version="Not present"

    if [ -x "$(command -v php)" ]; then
        php_version=$(php -v | grep -oP "PHP \K[0-9]+\.[0-9]+.*")
    fi
    if [ -x "$(command -v dnsmasq)" ]; then
        dnsmasq_version=$(dnsmasq -v | grep -oP "Dnsmasq version \K[0-9]+\.[0-9]+")
    fi
    if [ -x "$(command -v dhcpcd)" ]; then
        dhcpcd_version=$(dhcpcd --version | grep -oP '\d+\.\d+\.\d+')
    fi
    if [ -x "$(command -v lighttpd)" ]; then
        lighttpd_version=$(lighttpd -v | grep -oP '(\d+\.\d+\.\d+)')
    fi
    if [ -x "$(command -v vnstat)" ]; then
        vnstat_version=$(vnstat -v | grep -oP "vnStat \K[0-9]+\.[0-9]+")
    fi

    _log_separator "Installed Packages"
    _log_write "PHP Version: ${php_version}"
    _log_write "Dnsmasq Version: ${dnsmasq_version}"
    _log_write "dhcpcd Version: ${dhcpcd_version}"
    _log_write "lighttpd Version: ${lighttpd_version}"
    _log_write "vnStat Version: ${vnstat_version}"
}

# Outputs installed OpenAP version and selected settings.
function _openap_info() {
    local version="Not present"
    local hostapd_ini="Not present"
    local provider_ini="Not present"

    if [ -f ${install_dir}/includes/defaults.php ]; then
        version=$(grep "OPENAP_VERSION" $install_dir/includes/defaults.php | awk -F"'" '{print $4}')
    fi
    if [ -f ${RASPAP_HOSTAPD} ]; then
        hostapd_ini=$(_redact_config < ${RASPAP_HOSTAPD})
    fi
    if [ -f ${RASPAP_PROVIDER} ]; then
        provider_ini=$(_redact_config < ${RASPAP_PROVIDER})
    fi

    _log_separator "OpenAP Install"
    _log_write "OpenAP Version: ${version}"
    _log_write "OpenAP Installation Directory: ${install_dir}"
    _log_write "OpenAP hostapd.ini contents:\n${hostapd_ini}"
    _log_write "OpenAP provider.ini: ${provider_ini}"
}

function _redact_config() {
    sed -E '/^[[:space:]]*(wpa_passphrase|wpa_psk|password|passwd|token|secret|pin)[[:space:]]*=/I s/=.*/=[REDACTED]/'
}

function _usb_info() {
    local stdout=$(lsusb 2>&1 || true)
    _log_separator "USB Devices"
    _log_write "${stdout}"
}

function _rfkill_info() {
    local stdout=$(rfkill list 2>&1 || true)
     _log_separator "rfkill"
     _log_write "${stdout}"
}

function _wpa_info() {
    local stdout=$(wpa_cli status 2>&1 || true)
    _log_separator "WPA Supplicant"
    _log_write "${stdout}"
}

# Iterates legacy-compatible 090_*.conf files in dnsmasq.d.
function _dnsmasq_info() {
    local stdout
    stdout=$(find "${DNSMASQ_D_DIR}" -maxdepth 1 -type f -name '090_*.conf' -print 2>/dev/null || true)
    local contents
    _log_separator "Dnsmasq Contents"
    _log_write "${stdout}"
    IFS= # set IFS to empty
    if [ -d "${DNSMASQ_D_DIR}" ]; then
        for file in "${DNSMASQ_D_DIR}"/090_*.conf; do
            if [ -f "$file" ]; then
                contents+="\n$file contents:\n"
                contents+="$(cat $file)"
                contents+=$'\n'
            fi
        done
        _log_write "$contents"
    else
        _log_write "Not found: ${DNSMASQ_D_DIR}"
    fi
}

function _dhcpcd_info() {
    _log_separator "Dhcpcd Contents"
    if [ -f "${RASPAP_DHCDPCD}" ]; then
        local stdout=$(cat ${RASPAP_DHCDPCD});
        _log_write "${stdout}"

    else
        _log_write "${RASPAP_DHCDPCD} not present"
    fi
}

function _interface_info() {
    local stdout=$(ip a)
    _log_separator "Interfaces"
    _log_write "${stdout}"
}

function _iw_reg_info() {
     local stdout=$(iw reg get)
    _log_separator "IW Regulatory Info"
    _log_write "${stdout}"
}

function _iw_dev_info() {
     local stdout=$(iw dev)
    _log_separator "IW Device Info"
    _log_write "${stdout}"
}

function _routing_info() {
    local stdout=$(ip route)
    _log_separator "Routing Table"
    _log_write "${stdout}"
}

# Status of systemd services
function _systemd_info() {
    local SYSTEMD_SERVICES=(
        "hostapd"
        "dnsmasq"
        "dhcpcd"
        "systemd-networkd"
        "wg-quick@wg0"
        "openvpn-client@client"
        "lighttpd")
    _log_separator "Systemd Services"
    for i in "${!SYSTEMD_SERVICES[@]}"; do
        _log_write "${SYSTEMD_SERVICES[$i]} status:"
        stdout=$(systemctl status "${SYSTEMD_SERVICES[$i]}" || echo "")
        _log_write "${stdout}\n"
    done
}

function _output_preamble() {
    _log_write "${PREAMBLE}\n"
}

# Fetches host Linux distribution details
function _get_linux_distro() {
    if type lsb_release >/dev/null 2>&1; then # linuxbase.org
        OS=$(lsb_release -si)
        RELEASE=$(lsb_release -sr)
        CODENAME=$(lsb_release -sc)
        DESC=$(lsb_release -sd)
        LONG_BIT=$(getconf LONG_BIT)
    elif [ -f /etc/os-release ]; then # freedesktop.org
        . /etc/os-release
        OS=$ID
        RELEASE=$VERSION_ID
        CODENAME=$VERSION_CODENAME
        DESC=$PRETTY_NAME
    else
        OS="Unsupported Linux distribution"
    fi
    KERNEL=$(uname -a)
}

function _initialize() {
    if [ -e "${RASPAP_LOGFILE}" ] && [ "${writelog}" = 1 ]; then
        rm "${RASPAP_LOGFILE}"
    fi
    _get_linux_distro
}

function _log_separator(){
    local separator=""
    local msg="$1"
    local length=${#msg}
    _log_write "\n$1"
    for ((i=1; i<=length; i++)); do
         separator+="="
    done
    _log_write $separator
}

function _log_write() {
    if [ "${writelog}" = 1 ]; then
        echo -e "${@}" | tee -a $RASPAP_LOGFILE
    else
        echo -e "${@}"
    fi
}

_main "$@"
