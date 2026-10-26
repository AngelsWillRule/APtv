<?php

require_once __DIR__ . '/wifi_inventory.php';

function openapWifiRoleSlots(): array
{
    return ['ap_24ghz', 'ap_5ghz', 'uplink'];
}

function openapEmptyWifiRoles(): array
{
    return array_fill_keys(openapWifiRoleSlots(), null);
}

function openapEmptyHotspotDraft(): array
{
    return [
        'configured' => false,
        'ssid' => '',
        'ap_24ghz_channel' => 6,
        'ap_24ghz_width' => 20,
        'ap_24ghz_txpower' => 20,
        'ap_5ghz_channel' => 36,
        'ap_5ghz_width' => 80,
        'ap_5ghz_txpower' => 20,
        'security' => 'legacy',
        'wpa_passphrase' => '',
        'ap_isolate' => 0,
        'ignore_broadcast_ssid' => 0,
    ];
}

function openapReadHotspotDraft(array $stored): array
{
    $draft = openapEmptyHotspotDraft();
    if (!isset($stored['hotspot_draft']) || !is_array($stored['hotspot_draft'])) {
        return $draft;
    }
    $ssid = base64_decode((string) ($stored['hotspot_draft']['ssid_base64'] ?? ''), true);
    $draft['configured'] = true;
    $draft['ssid'] = is_string($ssid) ? $ssid : '';
    $draft['security'] = (string) ($stored['hotspot_draft']['security'] ?? 'legacy');
    $psk = base64_decode((string) ($stored['hotspot_draft']['wpa_passphrase_base64'] ?? ''), true);
    $draft['wpa_passphrase'] = is_string($psk) ? $psk : '';
    foreach (['ap_isolate', 'ignore_broadcast_ssid'] as $field) {
        $raw = (string) ($stored['hotspot_draft'][$field] ?? '0');
        $draft[$field] = $raw === '1' ? 1 : 0;
    }
    foreach (['ap_24ghz', 'ap_5ghz'] as $band) {
        $section = $stored[$band . '_draft'] ?? [];
        foreach (['channel', 'width', 'txpower'] as $field) {
            if (isset($section[$field]) && ctype_digit((string) $section[$field])) {
                $draft[$band . '_' . $field] = (int) $section[$field];
            }
        }
    }
    return $draft;
}

function openapValidateHotspotDraft(array $requested): array
{
    $draft = openapEmptyHotspotDraft();
    $draft['configured'] = true;
    $draft['ssid'] = trim((string) ($requested['ssid'] ?? ''));
    $errors = [];
    $ssidLength = strlen($draft['ssid']);
    if ($ssidLength < 1 || $ssidLength > 32 || preg_match('/[\x00-\x1f\x7f]/', $draft['ssid'])) {
        $errors[] = ['field' => 'ssid', 'code' => 'invalid_ssid', 'message' => 'SSID must contain 1 to 32 bytes without control characters.'];
    }
    $securityRequested = array_key_exists('security', $requested);
    $security = (string) ($requested['security'] ?? '');
    $draft['security'] = !$securityRequested ? 'legacy' : ($security === 'none' ? 'none' : 'wpa2');
    $draft['wpa_passphrase'] = (string) ($requested['wpa_passphrase'] ?? '');
    if ($securityRequested && !in_array($security, ['2', 'wpa2', 'none'], true)) {
        $errors[] = ['field' => 'security', 'code' => 'invalid_security', 'message' => 'Security must be WPA2-PSK or an open network.'];
    }
    $pskLength = strlen($draft['wpa_passphrase']);
    if ($securityRequested && $draft['security'] === 'wpa2' && ($pskLength < 8 || $pskLength > 63)) {
        $errors[] = ['field' => 'wpa_passphrase', 'code' => 'invalid_passphrase', 'message' => 'WPA passphrase must contain 8 to 63 characters.'];
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $draft['wpa_passphrase'])) {
        $errors[] = ['field' => 'wpa_passphrase', 'code' => 'invalid_passphrase', 'message' => 'WPA passphrase must not contain control characters.'];
    }
    $rules = [
        'ap_24ghz_channel' => static fn(int $value): bool => $value >= 1 && $value <= 14,
        'ap_24ghz_width' => static fn(int $value): bool => in_array($value, [20, 40], true),
        'ap_24ghz_txpower' => static fn(int $value): bool => $value >= 1 && $value <= 40,
        'ap_5ghz_channel' => static fn(int $value): bool => $value >= 32 && $value <= 177,
        'ap_5ghz_width' => static fn(int $value): bool => in_array($value, [20, 40, 80], true),
        'ap_5ghz_txpower' => static fn(int $value): bool => $value >= 1 && $value <= 40,
    ];
    foreach ($rules as $field => $rule) {
        $raw = (string) ($requested[$field] ?? '');
        $value = ctype_digit($raw) ? (int) $raw : 0;
        $draft[$field] = $value;
        if (!$rule($value)) {
            $errors[] = ['field' => $field, 'code' => 'invalid_hotspot_value', 'message' => sprintf('Invalid value for %s.', $field)];
        }
    }
    foreach (['ap_isolate', 'ignore_broadcast_ssid'] as $field) {
        $raw = (string) ($requested[$field] ?? '');
        $draft[$field] = $raw === '1' ? 1 : 0;
        if (!in_array($raw, ['0', '1'], true)) {
            $errors[] = ['field' => $field, 'code' => 'invalid_hotspot_value', 'message' => sprintf('Invalid value for %s.', $field)];
        }
    }
    return ['valid' => $errors === [], 'hotspot_draft' => $draft, 'errors' => $errors];
}

function openapNormalizeRoleIdentity($value): ?string
{
    $identity = strtolower(trim((string) $value));
    if ($identity === '' || $identity === '-') {
        return null;
    }
    return preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/', $identity)
        && $identity !== '00:00:00:00:00:00'
        ? $identity
        : null;
}

function openapNormalizeWifiRoles(array $section): array
{
    $roles = openapEmptyWifiRoles();
    foreach (openapWifiRoleSlots() as $slot) {
        $roles[$slot] = openapNormalizeRoleIdentity($section[$slot] ?? null);
    }
    return $roles;
}

function openapRadioBand(array $radio): ?string
{
    $frequency = (int) ($radio['frequency_mhz'] ?? 0);
    if ($frequency >= 2400 && $frequency < 2500) {
        return '2.4ghz';
    }
    if ($frequency >= 4900 && $frequency < 6000) {
        return '5ghz';
    }
    if (!empty($radio['supports_24ghz']) && empty($radio['supports_5ghz'])) {
        return '2.4ghz';
    }
    if (!empty($radio['supports_5ghz']) && empty($radio['supports_24ghz'])) {
        return '5ghz';
    }
    return null;
}

function openapMigrateLegacyWifiRoles(array $profile, array $radios): array
{
    $interfaces = $profile['interfaces'] ?? [];
    $roles = openapEmptyWifiRoles();
    $warnings = [];
    $legacyAp = openapNormalizeRoleIdentity($interfaces['ap_mac'] ?? null);
    $legacyUplink = openapNormalizeRoleIdentity($interfaces['uplink_mac'] ?? null);

    if ($legacyUplink !== null) {
        $roles['uplink'] = $legacyUplink;
    }
    if ($legacyAp !== null) {
        $radio = openapFindWifiByIdentity($radios, $legacyAp);
        $band = is_array($radio) ? openapRadioBand($radio) : null;
        if ($band === '2.4ghz') {
            $roles['ap_24ghz'] = $legacyAp;
            $legacyAp = null;
        } elseif ($band === '5ghz') {
            $roles['ap_5ghz'] = $legacyAp;
            $legacyAp = null;
        } else {
            $warnings[] = [
                'code' => 'legacy_ap_band_unresolved',
                'message' => 'The current AP identity is known, but its active band cannot be resolved safely.',
            ];
        }
    }

    return [
        'roles' => $roles,
        'legacy_ap' => $legacyAp,
        'warnings' => $warnings,
    ];
}

function openapBuildWifiRoleModel(array $profile, array $stored, array $radios): array
{
    $hasStoredModel = isset($stored['active']) && is_array($stored['active']);
    if ($hasStoredModel) {
        $active = openapNormalizeWifiRoles($stored['active']);
        $legacyAp = openapNormalizeRoleIdentity($stored['legacy']['ap'] ?? null);
        $warnings = [];
        if ($legacyAp !== null) {
            $warnings[] = [
                'code' => 'legacy_ap_band_unresolved',
                'message' => 'The current AP identity is known, but its active band cannot be resolved safely.',
            ];
        }
    } else {
        $migration = openapMigrateLegacyWifiRoles($profile, $radios);
        $active = $migration['roles'];
        $legacyAp = $migration['legacy_ap'];
        $warnings = $migration['warnings'];
    }
    $draft = isset($stored['draft']) && is_array($stored['draft'])
        ? openapNormalizeWifiRoles($stored['draft'])
        : $active;

    return [
        'version' => 2,
        'source' => $hasStoredModel ? 'wifi-roles' : 'legacy',
        'active' => $active,
        'draft' => $draft,
        'legacy_ap' => $legacyAp,
        'warnings' => $warnings,
        'hotspot_draft' => openapReadHotspotDraft($stored),
    ];
}

function openapEvaluateRepeaterAvailability(array $roles, array $radios): array
{
    $roles = openapNormalizeWifiRoles($roles);
    $emptyRadio = ['name' => '', 'mac' => '', 'identity_mac' => '', 'supports_ap' => false, 'supports_managed' => false];
    $ap = null;
    $apIdentities = array_values(array_filter([
        $roles['ap_24ghz'],
        $roles['ap_5ghz'],
    ]));

    foreach ($apIdentities as $identity) {
        $candidate = openapFindWifiByIdentity($radios, $identity);
        if (is_array($candidate) && !empty($candidate['supports_ap'])) {
            $ap = $candidate;
            break;
        }
    }

    if ($ap === null) {
        return [
            'valid' => false,
            'reason_code' => 'ap_not_available',
            'reason' => 'Assign and apply at least one available Wi-Fi interface to an AP role.',
            'ap' => $emptyRadio,
            'uplink' => $emptyRadio,
        ];
    }

    $uplinkIdentity = $roles['uplink'];
    if ($uplinkIdentity === null) {
        $freeManaged = array_filter($radios, static function (array $radio) use ($apIdentities): bool {
            $identity = openapNormalizeRoleIdentity($radio['identity_mac'] ?? ($radio['permanent_mac'] ?? ($radio['mac'] ?? null)));
            return !empty($radio['supports_managed'])
                && $identity !== null
                && !in_array($identity, $apIdentities, true);
        });
        $hasCandidate = count($freeManaged) > 0;
        return [
            'valid' => false,
            'reason_code' => $hasCandidate ? 'uplink_not_assigned' : 'uplink_not_available',
            'reason' => $hasCandidate
                ? 'Wi-Fi available but not assigned to UPLINK.'
                : 'No compatible Wi-Fi available for UPLINK.',
            'ap' => $ap,
            'uplink' => $emptyRadio,
        ];
    }

    $uplink = openapFindWifiByIdentity($radios, $uplinkIdentity);
    if (!is_array($uplink)) {
        return [
            'valid' => false,
            'reason_code' => 'assigned_uplink_disconnected',
            'reason' => 'Assigned UPLINK Wi-Fi is disconnected.',
            'ap' => $ap,
            'uplink' => $emptyRadio,
        ];
    }
    if (empty($uplink['supports_managed']) || in_array($uplinkIdentity, $apIdentities, true)) {
        return [
            'valid' => false,
            'reason_code' => 'assigned_uplink_incompatible',
            'reason' => 'Assigned UPLINK Wi-Fi is not compatible with managed mode.',
            'ap' => $ap,
            'uplink' => $uplink,
        ];
    }

    return [
        'valid' => true,
        'reason_code' => null,
        'reason' => '',
        'ap' => $ap,
        'uplink' => $uplink,
    ];
}

function openapValidateWifiRoles(array $roles, array $radios, bool $requireAp = true): array
{
    $requested = $roles;
    $roles = openapNormalizeWifiRoles($roles);
    $errors = [];
    $used = [];

    foreach ($roles as $slot => $identity) {
        $rawIdentity = trim((string) ($requested[$slot] ?? ''));
        if ($identity === null && $rawIdentity !== '' && $rawIdentity !== '-') {
            $errors[] = [
                'slot' => $slot,
                'code' => 'invalid_identity',
                'message' => 'The assigned radio identity is invalid.',
            ];
            continue;
        }
        if ($identity === null) {
            continue;
        }
        if (isset($used[$identity])) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'duplicate_radio',
                'message' => sprintf('The same radio is already assigned to %s.', $used[$identity]),
            ];
            continue;
        }
        $used[$identity] = $slot;
        $radio = openapFindWifiByIdentity($radios, $identity);
        if ($radio === null) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'radio_not_present',
                'message' => 'The assigned radio is not currently present.',
            ];
            continue;
        }
        if ($slot === 'uplink' && empty($radio['supports_managed'])) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'managed_not_supported',
                'message' => 'The selected radio does not support managed mode.',
            ];
        }
        if ($slot !== 'uplink' && empty($radio['supports_ap'])) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'ap_not_supported',
                'message' => 'The selected radio does not support AP mode.',
            ];
        }
        if ($slot === 'ap_24ghz' && empty($radio['supports_24ghz'])) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'band_not_supported',
                'message' => 'The selected radio does not support 2.4 GHz.',
            ];
        }
        if ($slot === 'ap_5ghz' && empty($radio['supports_5ghz'])) {
            $errors[] = [
                'slot' => $slot,
                'code' => 'band_not_supported',
                'message' => 'The selected radio does not support 5 GHz.',
            ];
        }
    }

    if ($requireAp && $roles['ap_24ghz'] === null && $roles['ap_5ghz'] === null) {
        $errors[] = [
            'slot' => 'access_point',
            'code' => 'ap_required',
            'message' => 'At least one access point radio must be assigned.',
        ];
    }

    return [
        'valid' => $errors === [],
        'roles' => $roles,
        'errors' => $errors,
    ];
}

function openapRenderWifiRoleModel(array $active, array $draft, ?string $legacyAp = null, array $hotspotDraft = []): string
{
    $active = openapNormalizeWifiRoles($active);
    $draft = openapNormalizeWifiRoles($draft);
    $legacyAp = openapNormalizeRoleIdentity($legacyAp);
    $lines = ['[meta]', 'version = 2', '', '[active]'];
    foreach (openapWifiRoleSlots() as $slot) {
        $lines[] = $slot . ' = ' . ($active[$slot] ?? '');
    }
    $lines[] = '';
    $lines[] = '[draft]';
    foreach (openapWifiRoleSlots() as $slot) {
        $lines[] = $slot . ' = ' . ($draft[$slot] ?? '');
    }
    if ($legacyAp !== null) {
        $lines[] = '';
        $lines[] = '[legacy]';
        $lines[] = 'ap = ' . $legacyAp;
    }
    if (!empty($hotspotDraft['configured'])) {
        $lines[] = '';
        $lines[] = '[hotspot_draft]';
        $lines[] = 'ssid_base64 = ' . base64_encode((string) ($hotspotDraft['ssid'] ?? ''));
        if (in_array(($hotspotDraft['security'] ?? 'legacy'), ['none', 'wpa2'], true)) {
            $lines[] = 'security = ' . $hotspotDraft['security'];
            $lines[] = 'wpa_passphrase_base64 = ' . base64_encode((string) ($hotspotDraft['wpa_passphrase'] ?? ''));
        }
        $lines[] = 'ap_isolate = ' . (int) ($hotspotDraft['ap_isolate'] ?? 0);
        $lines[] = 'ignore_broadcast_ssid = ' . (int) ($hotspotDraft['ignore_broadcast_ssid'] ?? 0);
        foreach (['ap_24ghz', 'ap_5ghz'] as $band) {
            $lines[] = '';
            $lines[] = '[' . $band . '_draft]';
            foreach (['channel', 'width', 'txpower'] as $field) {
                $lines[] = $field . ' = ' . (int) ($hotspotDraft[$band . '_' . $field] ?? 0);
            }
        }
    }
    return implode("\n", $lines) . "\n";
}

function openapWriteWifiRoleModel(
    string $path,
    array $active,
    array $draft,
    ?string $legacyAp = null,
    array $hotspotDraft = []
): void {
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('The OpenAP configuration directory is not writable.');
    }
    $temporary = tempnam($directory, '.wifi-roles.');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create the temporary Wi-Fi role profile.');
    }
    try {
        $contents = openapRenderWifiRoleModel($active, $draft, $legacyAp, $hotspotDraft);
        if (file_put_contents($temporary, $contents, LOCK_EX) === false
            || !chmod($temporary, 0640)
            || !rename($temporary, $path)) {
            throw new RuntimeException('Unable to save the Wi-Fi role draft.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}
