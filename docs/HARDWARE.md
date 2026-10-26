# Hardware and Wi-Fi prerequisites

OpenAP detects interfaces by capability and hardware identity rather than
assuming names such as `wlan0` or `eth0`.

The administrator is responsible for installing the correct operating-system
firmware and driver before using APtv. OpenAP does not install vendor firmware,
kernel modules or third-party driver packages.

## Required checks

```bash
ip -brief link
iw dev
iw list
rfkill list
```

The intended hotspot adapter must advertise AP mode through `iw list`. A visible
interface alone is not enough.

## Repeater requirements

Repeater Mode needs two usable Wi-Fi roles:

1. one AP-capable interface for the hotspot;
2. one managed/client interface for the upstream Wi-Fi connection.

The adapters must be able to operate at the same time. USB adapter names can
change when devices are reordered, so configuration should use the detected
hardware identity rather than a hard-coded `wlan0` or `wlan1`.

## Bridge requirements

Ethernet bridge mode also depends on the Wi-Fi driver, kernel bridge support and
the upstream network. AP support by itself does not guarantee that a station
interface can forward bridged traffic correctly.

## Reporting hardware support

Useful compatibility reports include board or computer model, Wi-Fi chipset,
driver, kernel, bands, requested mode and exact APtv release. Remove MAC
addresses, SSIDs and credentials before publishing a report.
