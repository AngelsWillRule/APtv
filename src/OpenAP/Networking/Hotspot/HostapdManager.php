<?php

/**
 * A hostapd manager class for RaspAP
 *
 * @description Manages hostapd configurations and runtime settings
 * @author      Bill Zimmerman <billzimmerman@gmail.com>
 * @license     https://github.com/raspap/raspap-webgui/blob/master/LICENSE
 */

declare(strict_types=1);

namespace OpenAP\Networking\Hotspot;

use OpenAP\Networking\Hotspot\Validators\HostapdValidator;
use OpenAP\Messages\StatusMessage;

class HostapdManager
{
    private const CONF_DEFAULT = OPENAP_HOSTAPD_CONFIG;
    private const CONF_PATH_PREFIX = '/etc/hostapd/hostapd-';
    private const CONF_TMP = '/tmp/hostapddata';

    /** @var HostapdValidator */
    private $validator;

    public function __construct(?HostapdValidator $validator = null)
    {
        $this->validator = $validator ?: new HostapdValidator();
    }

    /**
     * Retrieves current hostapd config
     *
     * @return array
     * @throws \RuntimeException
     */
    public function getConfig(): array
    {
        $configFile = SELF::CONF_DEFAULT;

        if (!file_exists($configFile)) {
            throw new \RuntimeException("hostapd config not found:  $configFile");
        }
        if (!is_readable($configFile)) {
            throw new \RuntimeException("Unable to read hostapd config: $configFile");
        }
        exec('cat ' . escapeshellarg($configFile), $hostapdconfig, $status);
        if ($status !== 0 || empty($hostapdconfig)) {
            throw new \RuntimeException("Failed to read hostapd config: $configFile");
        }

        foreach ($hostapdconfig as $hostapdconfigline) {
            if (strlen($hostapdconfigline) === 0) {
                continue;
            }
            if ($hostapdconfigline[0] != "#") {
                $line = explode("=", $hostapdconfigline);
                $config[$line[0]]=$line[1];
            }
        };

        // assign beacon_int boolean if value is set
        if (isset($config['beacon_int'])) {
            $config['beacon_interval_bool'] = 1;
        }
        // assign disassoc_low_ack boolean if value is set
        if (isset($config['disassoc_low_ack'])) {
            $config['disassoc_low_ack_bool'] = 1;
        }
        // assign country_code from iw reg if not set in config
        if (empty($config['country_code']) && isset($country_code[0])) {
            $config['country_code'] = $country_code[0];
        }
        // map wpa_key_mgmt to security types
        if (($config['wpa_key_mgmt'] ?? '') == 'WPA-PSK WPA-PSK-SHA256 SAE') {
            $config['wpa'] = 4;
        } elseif (($config['wpa_key_mgmt'] ?? '') == 'SAE') {
            $config['wpa'] = 5;
        } elseif (!isset($config['wpa'])) {
            $config['wpa'] = 'none';
        }
        $config['wpa_pairwise'] ??= $config['rsn_pairwise'] ?? 'CCMP';
        $config['wpa_passphrase'] ??= '';
        $config['beacon_interval_bool'] ??= 0;
        $config['beacon_int'] ??= '';
        $config['disassoc_low_ack_bool'] ??= 0;
        $config['selected_hw_mode'] = $this->resolveHwMode($config);
        $config['openap_channel_width'] = $this->resolveOpenApChannelWidth($config);
        $config['ignore_broadcast_ssid'] ??= 0;
        $config['max_num_sta'] ??= 0;
        $config['wep_default_key'] ??= 0;

        return $config;
    }

    /**
     * Determines the selected hardware mode based on config
     *
     * @param array $config
     * @return string
     */
    private function resolveHwMode(array $config): string
    {
        $selected = $config['hw_mode'] ?? 'g'; // default fallback

        if (!empty($config['ieee80211n']) && strval($config['ieee80211n']) === '1') {
            $selected = 'n';
        }
        if (!empty($config['ieee80211ac']) && strval($config['ieee80211ac']) === '1') {
            $selected = 'ac';
        }
        if (!empty($config['ieee80211ax']) && strval($config['ieee80211ax']) === '1') {
            $selected = 'ax';
        }
        if (!empty($config['ieee80211be']) && strval($config['ieee80211be']) === '1') {
            $selected = 'be';
        }

        return $selected;
    }

    private function resolveOpenApChannelWidth(array $config): int
    {
        if (($config['vht_oper_chwidth'] ?? '') === '1') {
            return 80;
        }
        if (preg_match('/\[HT40[+-]\]/', $config['ht_capab'] ?? '')) {
            return 40;
        }
        return 20;
    }

    /**
     * Validates a hostapd configuration
     *
     * @param array $post            raw $_POST object
     * @param array $wpaArray        allowed WPA values
     * @param array $encTypes        allowed encryption types
     * @param array $modes           allowed hardware modes
     * @param array $interfaces      valid interface list
     * @param string $regDomain      regulatory domain
     * @param StatusMessage $status  Status message collector
     * @return array|false           validated configuration array or false on failure
     */
    public function validate(
        array $post,
        array $wpaArray,
        array $encTypes,
        array $modes,
        array $interfaces,
        string $regDomain,
        StatusMessage $status
    ) {
        return $this->validator->validate($post, $wpaArray, $encTypes, $modes, $interfaces, $regDomain, $status);
    }

    /**
     * Builds hostapd configuration text from array
     *
     * @param array         $params
     * @param StatusMessage $status
     * @return string
     */
    public function buildConfig(array $params, StatusMessage $status): string
    {
        if (defined('OPENAP_SKYNET_EXISTING_AP') && OPENAP_SKYNET_EXISTING_AP) {
            return $this->buildExistingApConfig($params);
        }

        $config = [];

        // core static values
        $config[] = 'driver=nl80211';
        $config[] = 'ctrl_interface=' . OPENAP_HOSTAPD_CTRL_INTERFACE;
        $config[] = 'ctrl_interface_group=0';
        $config[] = 'auth_algs=1';

        $wpa = $params['wpa'];
        $wpa_numeric = $wpa;
        $wpa_key_mgmt = 'WPA-PSK';

        if ($wpa == 4) {
            $config[] = 'ieee80211w=1';
            $wpa_key_mgmt = 'WPA-PSK WPA-PSK-SHA256 SAE';
            $wpa = 2;
        } elseif ($wpa == 5) {
            $config[] = 'ieee80211w=2';
            $wpa_key_mgmt = 'SAE';
            $wpa = 2;
        }

        if ($params['80211w'] == 1) {
            $config[] = 'ieee80211w=1';
            $wpa_key_mgmt = 'WPA-PSK';
        } elseif ($params['80211w'] == 2) {
            $config[] = 'ieee80211w=2';
            $wpa_key_mgmt = 'WPA-PSK-SHA256';
        }

        if ($wpa_numeric !== 'none') {
            $config[] = 'wpa_key_mgmt=' . $wpa_key_mgmt;
        }

        if (!empty($params['beacon_interval'])) {
            $config[] = 'beacon_int=' . intval($params['beacon_interval']);
        }

        if (!empty($params['disassoc_low_ack'])) {
            $config[] = 'disassoc_low_ack=0';
        }

        // SSID and channel (required)
        $config[] = 'ssid=' . $params['ssid'];
        $config[] = 'channel=' . $params['channel'];

        $hwMode = isset($params['hw_mode']) ? $params['hw_mode'] : '';

        // validate channel width for 802.11ax/be
        if (in_array($hwMode, ['ax', 'be'])) {
            // for 6GHz band (channels 1-233) wider bandwidths are available
            $is6GHz = ($params['channel'] >= 1 && $params['channel'] <= 233);

            // for 802.11be, 320 MHz only available on 6GHz
            if ($hwMode === 'be' && !$is6GHz && isset($params['eht_oper_chwidth']) && $params['eht_oper_chwidth'] == 4) {
                // reset to 160 MHz if 320 MHz requested on non-6GHz
                $params['eht_oper_chwidth'] = 2;
            }
        }

        $isOpenAp = defined('OPENAP_REPEATER_CONTAINER') && OPENAP_REPEATER_CONTAINER;
        if ($isOpenAp && in_array($hwMode, ['n', 'ac'], true)) {
            $chwidth = (int) ($params['openap_channel_width'] ?? ($hwMode === 'ac' ? 80 : 20));
            $settings = $this->openApRadioSettings($hwMode, $chwidth);
        } else {
            // fetch settings for selected mode
            $modeSettings = getDefaultNetOpts('hostapd', 'modes', $hwMode);
            $settings = $modeSettings[$hwMode]['settings'] ?? [];
            // extract channel width from settings to calculate center frequency
            $chwidth = $this->extractChannelWidth($settings, $hwMode);
        }

        // calculate center frequency indices based on channel + width
        $vht_freq_idx = $this->calculateCenterFreqIndex((int)$params['channel'], $chwidth);
        $he_freq_idx = $vht_freq_idx; // For most cases, HE and VHT use the same center frequency

        // calculate HT40 direction based on channel and width
        $ht40_dir = $this->calculateHT40Direction((int)$params['channel'], $chwidth);

        if (!empty($settings)) {
            foreach ($settings as $line) {
                if (!is_string($line)) {
                    continue;
                }
                $replaced = str_replace('{VHT_FREQ_IDX}', (string) $vht_freq_idx ?? '', $line);
                $replaced = str_replace('{HE_FREQ_IDX}', (string) $he_freq_idx ?? '', $replaced);
                $replaced = str_replace('{HT40_DIR}', (string) $ht40_dir ?? '', $replaced);
                $config[] = $replaced;
            }
        }

        // WPA passphrase
        if ($wpa_numeric !== 'none' && !empty($params['wpa_passphrase'])) {
            $config[] = 'wpa_passphrase=' . $params['wpa_passphrase'];
        }

        // bridge handling
        if (!empty($params['bridgeName'])) {
            $config[] = 'interface=' . $params['interface'];
            $config[] = 'bridge=' . $params['bridgeName'];
        } else {
            $config[] = 'interface=' . $params['interface'];
        }

        if ($wpa_numeric !== 'none') {
            $config[] = 'wpa=' . $wpa;
            $config[] = 'wpa_pairwise=' . ($params['wpa_pairwise'] ?? '');
        }
        $config[] = 'country_code=' . ($params['country_code'] ?? '');
        $config[] = 'ap_isolate=' . ($params['apIsolation'] ?? 0);
        $config[] = 'ignore_broadcast_ssid=' . ($params['hiddenSSID'] ?? 0);

        if (!empty($params['max_num_sta'])) {
            $config[] = 'max_num_sta=' . (int)$params['max_num_sta'];
        }

        // add logging configuration if enabled
        if (!empty($params['log_enable'])) {
            $config[] = 'logger_syslog=-1';
            $config[] = 'logger_syslog_level=0';
        }

        // optional additional user config
        $config[] = $this->parseUserHostapdCfg();

        return implode(PHP_EOL, array_filter($config, function ($v) { return $v !== null && $v !== ''; })) . PHP_EOL;
    }

    private function openApRadioSettings(string $hwMode, int $channelWidth): array
    {
        if ($hwMode === 'n') {
            $settings = [
                'hw_mode=g',
                'ieee80211n=1',
                'wmm_enabled=1',
            ];
            if ($channelWidth === 40) {
                $settings[] = 'ht_capab=[{HT40_DIR}][SHORT-GI-20][SHORT-GI-40]';
            }
            return $settings;
        }

        $settings = [
            'hw_mode=a',
            'ieee80211n=1',
            'ieee80211ac=1',
            'wmm_enabled=1',
        ];
        if ($channelWidth >= 40) {
            $settings[] = 'ht_capab=[{HT40_DIR}][SHORT-GI-20][SHORT-GI-40]';
        }
        if ($channelWidth === 80) {
            $settings[] = 'vht_capab=[SHORT-GI-80]';
            $settings[] = 'vht_oper_chwidth=1';
            $settings[] = 'vht_oper_centr_freq_seg0_idx={VHT_FREQ_IDX}';
        } else {
            $settings[] = 'vht_oper_chwidth=0';
        }
        return $settings;
    }

    /**
     * Updates only UI-managed hostapd keys, preserving Skynet radio tuning.
     *
     * @param array $params
     * @return string
     */
    private function buildExistingApConfig(array $params): string
    {
        $configFile = self::CONF_DEFAULT;
        $lines = [];
        if (is_readable($configFile)) {
            $lines = file($configFile, FILE_IGNORE_NEW_LINES);
        }
        $current = $this->parseHostapdKeyValues($lines);
        $channel = (int) $params['channel'];
        $channelWidth = $this->getExistingApChannelWidth($current);
        $ht40Direction = $this->calculateHT40Direction($channel, $channelWidth);

        $wpa = $params['wpa'];
        $wpaNumeric = $wpa;
        $wpaKeyMgmt = 'WPA-PSK';

        if ($wpa == 4) {
            $wpaKeyMgmt = 'WPA-PSK WPA-PSK-SHA256 SAE';
            $wpa = 2;
        } elseif ($wpa == 5) {
            $wpaKeyMgmt = 'SAE';
            $wpa = 2;
        }

        if (($params['80211w'] ?? 0) == 1) {
            $wpaKeyMgmt = 'WPA-PSK';
        } elseif (($params['80211w'] ?? 0) == 2) {
            $wpaKeyMgmt = 'WPA-PSK-SHA256';
        }

        $updates = [
            'interface' => $params['interface'],
            'driver' => 'nl80211',
            'ssid' => $params['ssid'],
            'channel' => (string) $params['channel'],
            'country_code' => $params['country_code'] ?? '',
            'ctrl_interface' => OPENAP_HOSTAPD_CTRL_INTERFACE,
            'ctrl_interface_group' => '0',
            'ap_isolate' => (string) ($params['apIsolation'] ?? 0),
            'ignore_broadcast_ssid' => (string) ($params['hiddenSSID'] ?? 0),
        ];

        $securityKeys = ['wpa', 'wpa_key_mgmt', 'wpa_pairwise', 'rsn_pairwise', 'wpa_passphrase', 'sae_password', 'ieee80211w'];
        if ($wpaNumeric !== 'none') {
            $updates['wpa'] = (string) $wpa;
            $updates['wpa_key_mgmt'] = $wpaKeyMgmt;
            $updates['wpa_pairwise'] = $params['wpa_pairwise'] ?? 'CCMP';
            $updates['rsn_pairwise'] = $params['wpa_pairwise'] ?? 'CCMP';
        }

        if (!empty($current['ht_capab']) && !empty($ht40Direction)) {
            $updates['ht_capab'] = $this->replaceHt40Direction($current['ht_capab'], $ht40Direction);
        }
        if (array_key_exists('vht_oper_centr_freq_seg0_idx', $current)) {
            $updates['vht_oper_centr_freq_seg0_idx'] = (string) $this->calculateCenterFreqIndex($channel, $channelWidth);
        }
        if ($wpaNumeric !== 'none' && !empty($params['wpa_passphrase'])) {
            $updates['wpa_passphrase'] = $params['wpa_passphrase'];
        }
        if (!empty($params['beacon_interval'])) {
            $updates['beacon_int'] = (string) intval($params['beacon_interval']);
        }
        if (!empty($params['max_num_sta'])) {
            $updates['max_num_sta'] = (string) (int) $params['max_num_sta'];
        }

        $seen = [];
        $result = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Za-z0-9_]+)=/', $line, $matches)) {
                $result[] = $line;
                continue;
            }

            $key = $matches[1];
            if ($wpaNumeric === 'none' && in_array($key, $securityKeys, true)) {
                continue;
            }
            if (array_key_exists($key, $updates)) {
                $result[] = $key . '=' . $updates[$key];
                $seen[$key] = true;
                continue;
            }
            $result[] = $line;
        }

        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                $result[] = $key . '=' . $value;
            }
        }

        return implode(PHP_EOL, $result) . PHP_EOL;
    }

    /**
     * Parses key=value hostapd lines while ignoring comments.
     *
     * @param array $lines
     * @return array
     */
    private function parseHostapdKeyValues(array $lines): array
    {
        $config = [];
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Za-z0-9_]+)=(.*)$/', $line, $matches)) {
                continue;
            }
            $config[$matches[1]] = $matches[2];
        }
        return $config;
    }

    /**
     * Derives the active channel width from the existing Skynet config.
     *
     * @param array $config
     * @return int
     */
    private function getExistingApChannelWidth(array $config): int
    {
        if (!isset($config['vht_oper_chwidth'])) {
            return 40;
        }

        switch ((int) $config['vht_oper_chwidth']) {
            case 0:
                return 40;
            case 1:
                return 80;
            case 2:
            case 3:
                return 160;
            default:
                return 20;
        }
    }

    /**
     * Replaces any HT40 direction in ht_capab, preserving other capabilities.
     *
     * @param string $htCapab
     * @param string $direction
     * @return string
     */
    private function replaceHt40Direction(string $htCapab, string $direction): string
    {
        if (preg_match('/\[HT40[+-]\]/', $htCapab)) {
            return preg_replace('/\[HT40[+-]\]/', '[' . $direction . ']', $htCapab, 1);
        }
        return '[' . $direction . ']' . $htCapab;
    }

    /**
     * Saves a hostapd configuration
     *
     * @param string $config, rendered hostapd.conf
     * @param string $interface, named interface
     * @param bool   $dualMode, dual-band AP mode enabled
     * @param bool   $restart, option to restart hostapd@<iface> after save
     * @return bool
     * @throws \RuntimeException
     */
    public function saveConfig(string $config, bool $dualMode, string $iface, bool $restart = false): bool
    {
        $configFile = $this->resolveConfigPath($iface, $dualMode); 
        $tempFile   = SELF::CONF_TMP;


        if (file_put_contents($tempFile, $config) === false) {
            throw new \RuntimeException("Failed to write temp hostapd config");
        }

        exec(sprintf('sudo cp %s %s', escapeshellarg($tempFile), escapeshellarg($configFile)), $o, $status);
        if ($status !== 0) {
            throw new \RuntimeException("Failed to apply new hostapd config");
        }

        if ($restart) {
            $this->restartService($iface);
        }

        return true; 
    }

    /**
     * Derives mode checkbox states from POST + existing ini
     *
     * @param array  $post raw $_POST
     * @param array  $currentIni parsed hostapd.ini
     * @return array normalized states
     */
    public function deriveModeStates(array $post, array $currentIni): array
    {
        $prevWifiAPEnable = (int)($currentIni['WifiAPEnable'] ?? 0);
        $bridgedEnable  = isset($post['bridgedEnable'])  ? 1 : 0;
        $repeaterEnable = 0;
        $wifiAPEnable   = 0;

        if ($bridgedEnable === 0) {
            // only meaningful when not bridged
            $repeaterEnable = isset($post['repeaterEnable']) ? 1 : 0;
            $wifiAPEnable   = isset($post['wifiAPEnable'])   ? 1 : 0;
        }

        $logEnable = isset($post['logEnable']) ? 1 : 0;
        $effectiveWifiAPEnable = $bridgedEnable === 1 ? $prevWifiAPEnable : $wifiAPEnable;

        return [
            'BridgedEnable'  => $bridgedEnable,
            'RepeaterEnable' => $repeaterEnable,
            'WifiAPEnable'   => $effectiveWifiAPEnable,
            'LogEnable'      => $logEnable
        ];
    }

    /**
     * Determine AP interface, client (managed) interface and session/monitor interface
     * Uses these semantics:
     *  - Base interface = user selection (validated) or OPENAP_WIFI_AP_INTERFACE
     *  - AP-STA mode (WifiAPEnable=1): AP is 'uap0', client is base iface
     *  - Bridged mode: client/session use 'br0', AP remains base iface
     *
     * @param string $baseIface Selected interface from form
     * @param array $states Output from deriveModeStates()
     * @return array [ap_iface, cli_iface, session_iface]
     */
    public function deriveInterfaces(string $baseIface, array $states): array
    {
        $apIface      = $baseIface;
        $cliIface     = $baseIface;
        $sessionIface = $baseIface;

        if ($states['WifiAPEnable'] === 1 && $states['BridgedEnable'] === 0) {
            // client AP (AP-STA) – uap0 is AP, base iface remains client
            $apIface      = 'uap0';
            $sessionIface = 'uap0';
            $cliIface     = $baseIface;
        } elseif ($states['BridgedEnable'] === 1) {
            // bridged mode – monitor br0, AP stays as base wireless iface
            $cliIface     = 'br0';
            $sessionIface = 'br0';
        }

        return [$apIface, $cliIface, $sessionIface];
    }

    /**
     * Parses optional /etc/hostapd/hostapd.conf.users file
     *
     * @return string $tmp
     */
    private function parseUserHostapdCfg()
    {
        if (file_exists(SELF::CONF_DEFAULT . '.users')) {
            exec('cat '. SELF::CONF_DEFAULT . '.users', $hostapdconfigusers);
            foreach ($hostapdconfigusers as $hostapdconfigusersline) {
                if (strlen($hostapdconfigusersline) === 0) {
                    continue;
                }
                if ($hostapdconfigusersline[0] != "#") {
                    $arrLine = explode("=", $hostapdconfigusersline);
                    $tmp.= $arrLine[0]."=".$arrLine[1].PHP_EOL;;
                }
            }
            return $tmp;
        }
    }

    /**
     * Determines the hostapd config file for a given interface
     *
     * @param string $iface
     * @param bool $dualMode
     * @return string
     */
    private function resolveConfigPath(string $iface, bool $dualMode): string
    {
        if ($dualMode) {
            return SELF::CONF_PATH_PREFIX . $iface . '.conf';
        }
        // primary interface uses the canonical config path
        return self::CONF_DEFAULT;
    }

    /**
     * Restarts hostapd systemd instance
     *
     * @param string $iface
     * @throws \RuntimeException
     */
    private function restartService(string $iface): void
    {
        // sanitize 
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $iface)) {
            throw new \RuntimeException("Invalid interface name: $iface");
        }

        exec('sudo systemctl restart hostapd.service', $out, $rc);
        if ($rc === 0) {
            return;
        }

        throw new \RuntimeException("Failed to restart hostapd.service.");
    }

    /**
     * Persist hostapd.ini with mode / interface user settings
     *
     * @param array $states states from deriveModeStates()
     * @param string $apIface the AP interface
     * @param string $cliIface the managed interface
     * @param array $previousIni existing ini
     * @return bool
     */
    public function persistHostapdIni(array $states, string $apIface, string $cliIface, array $previousIni = []): bool
    {
        // compose new ini payload
        $cfg = [
            'WifiInterface'  => $apIface,
            'LogEnable'      => $states['LogEnable'] ?? false,
            'WifiAPEnable'   => $states['WifiAPEnable'] ?? false,
            'BridgedEnable'  => $states['BridgedEnable'] ?? false,
            'RepeaterEnable' => $states['RepeaterEnable'] ?? false,
            'DualAPEnable'   => $states['DualAPEnable'] ?? false,
            'WifiManaged'    => $cliIface
        ];
        foreach ($previousIni as $k => $v) {
            if (!array_key_exists($k, $cfg)) {
                $cfg[$k] = $v;
            }
        }
        return write_php_ini($cfg, OPENAP_CONFIG . '/hostapd.ini');
    }

    /**
     * Returns a count of hostapd-<interface>.conf files
     *
     * @return int 
     */
    private function countHostapdConfigs(): int
    {
        $configs = glob('/etc/hostapd/hostapd-*.conf');
        return is_array($configs) ? count($configs) : 0;
    }

    /**
     * Extracts channel width from mode settings
     *
     * @param array $settings mode settings array
     * @param string $hwMode hardware mode (ac, ax, be)
     * @return int channel width in MHz (20, 40, 80, 160, 320)
     */
    private function extractChannelWidth(array $settings, string $hwMode): int
    {
        $chwidthParam = '';

        // determine parameter based on mode
        if ($hwMode === 'ac') {
            $chwidthParam = 'vht_oper_chwidth';
        } elseif ($hwMode === 'ax') {
            $chwidthParam = 'he_oper_chwidth';
        } elseif ($hwMode === 'be') {
            $chwidthParam = 'eht_oper_chwidth';
        } else {
            return 20; // 20 MHz default for other modes
        }

        // parse settings to find channel width
        foreach ($settings as $line) {
            if (!is_string($line)) {
                continue;
            }

            // skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            if (strpos($line, $chwidthParam . '=') !== false) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    // extract numeric value
                    $value = trim($parts[1]);
                    // remove any inline comments
                    $value = preg_replace('/\s*#.*$/', '', $value);
                    $chwidthCode = (int) $value;

                    // convert hostapd encoding to MHz based on mode
                    if ($hwMode === 'be') {
                        // EHT uses: 0=20, 1=40, 2=80, 3=160, 4=320
                        switch ($chwidthCode) {
                            case 0: return 20;
                            case 1: return 40;
                            case 2: return 80;
                            case 3: return 160;
                            case 4: return 320;
                            default: return 20;
                        }
                    } else {
                        // VHT/HE uses: 0=20/40, 1=80, 2=160, 3=80+80
                        switch ($chwidthCode) {
                            case 0: return 40;
                            case 1: return 80;
                            case 2: return 160;
                            case 3: return 160; // 80+80 treated as 160
                            default: return 20;
                        }
                    }
                }
            }
        }

        return 20; // default to 20 MHz channel if not found
    }

    /**
     * Calculates center frequency segment 0 index for given channel and width
     *
     * @param int $channel primary channel number
     * @param int $chwidthMHz channel width in MHz (20, 40, 80, 160, 320)
     * @return int center frequency segment 0 index
     */
    private function calculateCenterFreqIndex(int $channel, int $chwidthMHz): int
    {
        // determine band based on channel number
        $is24GHz = ($channel >= 1 && $channel <= 14);
        $is5GHz = ($channel >= 36 && $channel <= 177);
        $is6GHz = ($channel >= 1 && $channel <= 233 && !$is24GHz); // 6 GHz uses 1-233

        // 20 MHz - center is always primary channel
        if ($chwidthMHz <= 20) {
            return $channel;
        }

        // 2.4 GHz band
        if ($is24GHz) {
            if ($chwidthMHz == 40) {
                // for 2.4 GHz, typically use HT40+ (center is primary + 2)
                // channels 1-7 use HT40+, channels 8-13 use HT40-
                return ($channel <= 7) ? $channel + 2 : $channel - 2;
            }
            // wider bandwidths not supported on 2.4 GHz
            return $channel;
        }

        // 5 GHz band
        if ($is5GHz) {
            if ($chwidthMHz == 40) {
                // HT40+ configuration: center = primary + 2
                // adjust for upper/lower position in 40 MHz pair
                if (in_array($channel, [36, 44, 52, 60, 100, 108, 116, 124, 132, 140, 149, 157, 165, 173])) {
                    return $channel + 2;
                } else {
                    return $channel - 2;
                }
            }

            if ($chwidthMHz == 80) {
                // map channel to 80 MHz center frequency
                if ($channel >= 36 && $channel <= 48) return 42;
                if ($channel >= 52 && $channel <= 64) return 58;
                if ($channel >= 100 && $channel <= 112) return 106;
                if ($channel >= 116 && $channel <= 128) return 122;
                if ($channel >= 132 && $channel <= 144) return 138;
                if ($channel >= 149 && $channel <= 161) return 155;
                if ($channel >= 165 && $channel <= 177) return 171;
            }

            if ($chwidthMHz == 160) {
                // map channel to 160 MHz center frequency
                if ($channel >= 36 && $channel <= 64) return 50;
                if ($channel >= 100 && $channel <= 128) return 114;
                // channels 149-177 don't support 160 MHz in most regions
                if ($channel >= 149 && $channel <= 177) return 163;
            }
        }

        // 6 GHz band (UNII-5 through UNII-8)
        if ($is6GHz && !$is24GHz) {
            // 6 GHz uses different channel numbering: 1, 5, 9, 13, ... (every 4)
            if ($chwidthMHz == 40) {
                // center is at the midpoint between two 20 MHz channels
                return $channel + 2;
            }

            if ($chwidthMHz == 80) {
                // calculate 80 MHz center
                $blockStart = (int)(($channel - 1) / 16) * 16 + 1;
                return $blockStart + 6;
            }

            if ($chwidthMHz == 160) {
                // calculate 160 MHz center
                $blockStart = (int)(($channel - 1) / 32) * 32 + 1;
                return $blockStart + 14;
            }

            if ($chwidthMHz == 320) {
                // calculate 320 MHz center
                $blockStart = (int)(($channel - 1) / 64) * 64 + 1;
                return $blockStart + 30;
            }
        }

        // fallback: return primary channel
        return $channel;
    }

    /**
     * Calculates HT40 direction (+ or -) based on channel and bandwidth
     *
     * @param int $channel primary channel number
     * @param int $chwidthMHz channel width in MHz
     * @return string HT40 direction: "HT40+" or "HT40-" or "" for 20MHz
     */
    private function calculateHT40Direction(int $channel, int $chwidthMHz): string
    {
        // only applicable for 40 MHz and wider on 5 GHz
        if ($chwidthMHz < 40) {
            return '';
        }

        $is24GHz = ($channel >= 1 && $channel <= 14);
        $is5GHz = ($channel >= 36 && $channel <= 177);

        // 2.4 GHz band
        if ($is24GHz) {
            // channels 1-7 use HT40+, channels 8-13 use HT40-
            return ($channel <= 7) ? 'HT40+' : 'HT40-';
        }

        // 5 GHz band
        if ($is5GHz) {
            // HT40 direction always follows the adjacent 20 MHz pair, also
            // when that pair is part of an 80/160 MHz block. The primary
            // channels alternate + and - (36+, 40-, 44+, 48-, ...).
            // Treating the lower half of an 80 MHz block as uniformly HT40+
            // creates an inconsistent hostapd configuration and some drivers
            // silently fall back to a different primary channel.
            if (in_array($channel, [36, 44, 52, 60, 100, 108, 116, 124, 132, 140, 149, 157, 165, 173], true)) {
                return 'HT40+';
            }
            return 'HT40-';
        }

        // default fallback
        return 'HT40+';
    }

}
