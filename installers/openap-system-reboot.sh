#!/bin/sh
set -eu

if [ "$#" -ne 0 ]; then
  echo "openap-system-reboot does not accept arguments" >&2
  exit 2
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "openap-system-reboot must run as root" >&2
  exit 1
fi

# Leave enough time for the POST redirect to load the dashboard and show the
# reboot progress modal before the web server goes offline.
exec /usr/bin/systemd-run \
  --quiet \
  --collect \
  --unit=openap-system-reboot \
  --on-active=8s \
  /bin/systemctl reboot
