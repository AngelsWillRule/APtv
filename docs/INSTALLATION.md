# Installation

## 1. Prepare the operating system

Use a supported Debian-based system with Internet access and root privileges.
The installer tolerates ordinary pending package updates, but the package state
must not be broken.

Check the state before starting:

```bash
cat /etc/os-release
uname -a
dpkg --audit
ip -brief link
iw dev
```

If `dpkg --audit` reports incomplete packages, repair them before installing.
The installer will not silently run a full distribution upgrade.

## 2. Download the project

```bash
sudo apt update
sudo apt install -y git
git clone https://github.com/AngelsWillRule/APtv.git aptv-install
cd aptv-install
```

For a reproducible installation, download a tagged release archive and verify
its published SHA-256 checksum instead of using the moving default branch.

## 3. Run the installer

The public menu is the recommended entrypoint:

```bash
sudo ./aptv-install.sh
```

Complete installation:

```bash
sudo ./aptv-install.sh install --profile both
```

OpenAP only:

```bash
sudo ./aptv-install.sh install --profile ap
```

IPTV only:

```bash
sudo ./aptv-install.sh install --profile iptv
```

For scripted use, add `--yes`. If ordinary package updates are pending, add
`--allow-pending-updates`:

```bash
sudo ./aptv-install.sh install --profile both --yes --allow-pending-updates
```

This option does not bypass broken `dpkg` state or unresolved dependencies.

## 4. Configure the hotspot

The installer asks for:

- AP-capable Wi-Fi interface;
- country code, for example `IT`;
- hotspot SSID;
- hotspot password;
- APtv administrator username and password.

Use a unique, strong password. Do not reuse an IPTV provider password.

The initial validated flow is AP over Ethernet. After installation, the
dashboard can expose other supported modes according to the detected hardware.

## 5. Verify the installation

```bash
sudo ./aptv-install.sh status
```

Then verify, from a client:

1. the hotspot is visible;
2. DHCP assigns an address;
3. the configured DNS mode resolves names;
4. the dashboard opens on the hotspot address;
5. Internet forwarding works when the selected uplink is available;
6. IPTV login, catalog loading and playback work.

Do not change operating mode until the baseline installation is healthy.

## 6. Repair or add components

```bash
sudo ./aptv-install.sh repair
sudo ./aptv-install.sh add ap
sudo ./aptv-install.sh add iptv
```

Component operations are serialized by the installed component manager. Wait
for an operation to finish before starting another one.
