# Uninstallation

## Remove one component

```bash
sudo ./aptv-install.sh remove ap
sudo ./aptv-install.sh remove iptv
```

The manager preserves shared files when the remaining component needs them.
For example, removing IPTV while keeping OpenAP restores the OpenAP runtime
without deleting the active AP configuration.

## Remove the complete installation

```bash
sudo ./aptv-install.sh remove all
```

Review the confirmation carefully. The uninstaller removes APtv/OpenAP-owned
services, helpers, configuration and runtime state, while distribution packages
that may have existed before installation are preserved according to the
release policy.

Before a destructive removal, save any provider configuration or recordings you
intend to keep. Never delete snapshots or backups without first checking their
contents and available recovery options.

## After removal

Verify the host network manager and uplink configuration:

```bash
ip -brief link
ip address
ip route
systemctl --failed
```

If the host had a pre-existing NetworkManager, `systemd-networkd` or `ifupdown`
configuration, confirm that the original backend is active before rebooting.
