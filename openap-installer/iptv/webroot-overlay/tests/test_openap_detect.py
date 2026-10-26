#!/usr/bin/env python3

import importlib.util
import importlib.machinery
from pathlib import Path
import sys
from unittest import mock


MODULE_PATH = Path(__file__).parents[1] / "openap-installer/bin/openap-detect"
LOADER = importlib.machinery.SourceFileLoader("openap_detect", str(MODULE_PATH))
SPEC = importlib.util.spec_from_loader(LOADER.name, LOADER)
assert SPEC and SPEC.loader
openap_detect = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = openap_detect
SPEC.loader.exec_module(openap_detect)


def test_mac_normalization() -> None:
    assert openap_detect.normalize_mac("AA:BB:CC:DD:EE:FF\n") == "aa:bb:cc:dd:ee:ff"
    assert openap_detect.normalize_mac("00:00:00:00:00:00") == ""
    assert openap_detect.normalize_mac("not-a-mac") == ""


def test_permanent_mac() -> None:
    with mock.patch.object(openap_detect, "command_exists", return_value=True), mock.patch.object(
        openap_detect,
        "run",
        return_value=(0, "Permanent address: AA:BB:CC:DD:EE:FF\n", ""),
    ):
        assert openap_detect.permanent_mac("wlan0") == "aa:bb:cc:dd:ee:ff"


def test_phy_channels() -> None:
    output = """
            * 2437.0 MHz [6] (20.0 dBm)
            * 5180.0 MHz [36] (23.0 dBm)
            * 5260.0 MHz [52] (20.0 dBm) (radar detection)
            * 5745.0 MHz [149] (13.0 dBm) (no IR)
            * 5825.0 MHz [165] (disabled)
    """
    with mock.patch.object(openap_detect, "run", return_value=(0, output, "")):
        channels = openap_detect.phy_channels("phy0")
    assert [item["channel"] for item in channels] == [6, 36, 52, 149]
    assert channels[1]["max_dbm"] == 23 and channels[1]["band"] == "5"
    assert channels[2]["radar"] is True
    assert channels[2]["selectable"] is False
    assert channels[3]["no_ir"] is True
    assert channels[3]["selectable"] is False


if __name__ == "__main__":
    test_mac_normalization()
    test_permanent_mac()
    test_phy_channels()
