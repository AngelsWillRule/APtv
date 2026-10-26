#!/bin/sh
set -eu

iface="${1:-}"
[ -n "$iface" ] || {
  echo "Usage: openap-reset-ap-radio INTERFACE" >&2
  exit 2
}

case "$iface" in
  ''|lo|*[!A-Za-z0-9_.:-]*)
    echo "Invalid AP interface: $iface" >&2
    exit 2
    ;;
esac

net_dir="/sys/class/net/$iface"
[ -e "$net_dir" ] || {
  echo "AP interface is absent: $iface" >&2
  exit 1
}

driver_path="$(readlink -f "$net_dir/device/driver" 2>/dev/null || true)"
driver_name="$(basename "$driver_path")"
device_path="$(readlink -f "$net_dir/device" 2>/dev/null || true)"

# The failure is specific to the USB Realtek rtw88 AP implementation.  Other
# drivers and non-USB radios must remain untouched on all supported distros.
[ "$driver_name" = rtw88_8821cu ] || {
  echo "AP radio reset skipped for $iface (driver=${driver_name:-unknown})"
  exit 0
}
case "$device_path" in
  /sys/devices/*/usb*/*:*) ;;
  *)
    echo "AP radio reset skipped for $iface (non-USB device)"
    exit 0
    ;;
esac

driver_dir="/sys/bus/usb/drivers/$driver_name"
unbind="$driver_dir/unbind"
bind="$driver_dir/bind"
usb_function="$(basename "$device_path")"
[ -w "$unbind" ] && [ -w "$bind" ] || {
  echo "USB driver reset is unavailable for $iface ($usb_function)" >&2
  exit 1
}

echo "Resetting AP radio $iface through $driver_name ($usb_function)"
ip link set dev "$iface" nomaster 2>/dev/null || true
ip link set dev "$iface" down 2>/dev/null || true
printf '%s\n' "$usb_function" > "$unbind"

for attempt in $(seq 1 20); do
  [ ! -e "/sys/class/net/$iface" ] && break
  [ "$attempt" -eq 20 ] || sleep 0.1
done
[ ! -e "/sys/class/net/$iface" ] || {
  echo "AP interface did not detach from $driver_name: $iface" >&2
  exit 1
}

printf '%s\n' "$usb_function" > "$bind"
for attempt in $(seq 1 30); do
  [ -e "/sys/class/net/$iface" ] && break
  [ "$attempt" -eq 30 ] || sleep 0.2
done
[ -e "/sys/class/net/$iface" ] || {
  echo "AP interface did not return after $driver_name reset: $iface" >&2
  exit 1
}

iw dev "$iface" set type managed 2>/dev/null || true
iw dev "$iface" set power_save off 2>/dev/null || true
echo "AP radio reset complete: $iface"
