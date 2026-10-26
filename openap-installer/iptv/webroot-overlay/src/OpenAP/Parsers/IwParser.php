<?php

/**
 * Linux iw parser class
 *
 * @description Parses output of iw to determine properties of a given physical device (phy)
 * @author      Bill Zimmerman <billzimmerman@gmail.com>
 * @license     https://github.com/raspap/raspap-webgui/blob/master/LICENSE
 * @see         https://wireless.wiki.kernel.org/en/users/Documentation/iw
 */

declare(strict_types=1);

namespace OpenAP\Parsers;

class IwParser
{
    private string $iw_output = '';

    public function __construct(string $interface = 'wlan0')
    {

        // Resolve physical device for selected interface
        $iface = escapeshellarg($interface);
        $pattern = "iw dev | awk -v iface=".$iface." '/^phy#/ { phy = $0 } $1 == \"Interface\" { interface = $2 } interface == iface { print phy }'";
        $return = [];
        exec($pattern, $return, $status);
        $phy = $return[0] ?? '';

        if ($status !== 0 || !preg_match('/^phy#[0-9]+$/', $phy)) {
            return;
        }

        // Fetch 'iw info' output for phy
        $output = shell_exec('iw '.escapeshellarg($phy).' info 2>/dev/null');
        $this->iw_output = is_string($output) ? $output : '';
    }

    /**
     * Parses raw output of 'iw info' command, filtering supported frequencies.
     *
     * Frequencies with the following regulatory restrictions are excluded:
     * (no IR): the AP won't Initiate Radiation until a DFS scan (or similar) is complete on these bands.
     * (radar detection): the specified channels are shared with radar equipment.
     * (disabled): self-explanatory.
     */
    public function parseIwInfo()
    {
        $excluded = [
            "(no IR, radar detection)",
            "(radar detection)",
            "(disabled)",
            "(no IR)"
        ];
        $excluded_pattern = implode('|', array_map('preg_quote', $excluded));
        $pattern = '/\*\s+([\d.]+)\s+MHz \[(\d+)\] \(([\d.]+) dBm\)\s(?!' .$excluded_pattern. ')/';
        $supportedFrequencies = [];

        // Match iw_output containing supported frequencies
        if ($this->iw_output === '') {
            return [];
        }
        preg_match_all($pattern, $this->iw_output, $matches, PREG_SET_ORDER, 0);

        /* For frequencies > 5500 MHz only the following "channels" are allowed:
         * 100 108 116 124 132 140 149 157 184 192
         * @see https://w1.fi/cgit/hostap/tree/src/common/hw_features_common.c
         */
        $allowed = [100, 108, 116, 124, 132, 140, 149, 157, 184, 192];

        foreach ($matches as $match) {
            $frequency = [
                'MHz' => (int)$match[1],
                'Channel' => (int)$match[2],
                'dBm' => (float)$match[3],
            ];
            // Drivers may expose 10 MHz centre-frequency entries such as
            // channels 34, 38, 42 and 46. These are not valid 20 MHz primary
            // channels for hostapd and must not be offered by the AP UI.
            if ($frequency['MHz'] >= 5000 && !$this->isValid5GHzPrimaryChannel($frequency['Channel'])) {
                continue;
            }
            if ( ($frequency['MHz'] >= 5500 && in_array($frequency['Channel'], $allowed))
                || $frequency['MHz'] < 5500 ) {
                $supportedFrequencies[] = $frequency;
            }
        }
        return $supportedFrequencies;
    }

    private function isValid5GHzPrimaryChannel(int $channel): bool
    {
        if ($channel >= 36 && $channel <= 64) {
            return $channel % 4 === 0;
        }
        if ($channel >= 100 && $channel <= 144) {
            return $channel % 4 === 0;
        }
        return $channel >= 149 && $channel <= 177 && ($channel - 149) % 4 === 0;
    }

    /**
     * Converts an ieee80211 frequency to a channel value
     * Adapted from iw source
     * @param int $freq
     * @see https://git.kernel.org/pub/scm/linux/kernel/git/jberg/iw.git/tree/util.c 
     */
    public function ieee80211_frequency_to_channel(int $freq)
    {
        /* see 802.11-2007 17.3.8.3.2 and Annex J */
        if ($freq == 2484) {
            return 14;
        } else if ($freq < 2484) {
            return ($freq - 2407) / 5;
        } else if ($freq >= 4910 && $freq <= 4980) {
            return ($freq - 4000) / 5;
        } else if ($freq <= 45000) { /* DMG band lower limit */
            return ($freq - 5000) / 5;
        } else if ($freq >= 58320 && $freq <= 64800) {
            return ($freq - 56160) / 2160;
        } else {
            return 0;
        }
    }
}
