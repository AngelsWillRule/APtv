#!/usr/bin/env python3

import importlib.machinery
import importlib.util
import configparser
import sys
from pathlib import Path


path = Path(__file__).parents[1] / "openap-installer/bin/openap-generate-dual-hostapd"
loader = importlib.machinery.SourceFileLoader("dual_hostapd", str(path))
spec = importlib.util.spec_from_loader(loader.name, loader)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

base = "interface=wlan0\nssid=Old\nhw_mode=g\nchannel=6\nieee80211n=1\nwpa=2\nwpa_passphrase=secret123\n"
settings = {
    "ssid": "OpenAP",
    "ap_isolate": 1,
    "ignore_broadcast_ssid": 1,
    "ap_24ghz": {"channel": 6, "width": 20, "txpower": 20},
    "ap_5ghz": {"channel": 36, "width": 80, "txpower": 20},
}
radio24 = {"name": "wifi24"}
radio5 = {"name": "wifi5"}
conf24 = module.build_config(base, "ap_24ghz", radio24, settings)
conf5 = module.build_config(base, "ap_5ghz", radio5, settings)
assert "interface=wifi24" in conf24 and "hw_mode=g" in conf24
assert "interface=wifi5" in conf5 and "hw_mode=a" in conf5
assert "vht_oper_chwidth=1" in conf5 and "vht_oper_centr_freq_seg0_idx=42" in conf5
assert "wpa_passphrase=secret123" in conf24 and "wpa_passphrase=secret123" in conf5
assert "ap_isolate=1" in conf24 and "ap_isolate=1" in conf5
assert "ignore_broadcast_ssid=1" in conf24 and "ignore_broadcast_ssid=1" in conf5
assert module.five_ghz_center(149, 80) == 155

resolved = {
    "ap_24ghz": {"channels": [{"channel": 6, "max_dbm": 20, "no_ir": False}]},
    "ap_5ghz": {"channels": [{"channel": 36, "max_dbm": 23, "no_ir": False}]},
}
module.validate_radio_settings(resolved, settings)
too_powerful = {**settings, "ap_5ghz": {"channel": 36, "width": 80, "txpower": 24}}
try:
    module.validate_radio_settings(resolved, too_powerful)
except module.DraftError:
    pass
else:
    raise AssertionError("channel transmit-power limit was not enforced")

single = configparser.ConfigParser(interpolation=None)
single.read_string("""
[draft]
ap_24ghz =
ap_5ghz = aa:bb:cc:dd:ee:55
uplink = aa:bb:cc:dd:ee:66
[hotspot_draft]
ssid_base64 = T3BlbkFQ
[ap_5ghz_draft]
channel = 40
width = 40
txpower = 23
""")
single_settings = module.validate_draft(single)
assert "ap_24ghz" not in single_settings
assert single_settings["ap_5ghz"]["width"] == 40
assert single_settings["identities"]["uplink"] == "aa:bb:cc:dd:ee:66"
assert single_settings["ap_isolate"] == 0
assert single_settings["ignore_broadcast_ssid"] == 0
