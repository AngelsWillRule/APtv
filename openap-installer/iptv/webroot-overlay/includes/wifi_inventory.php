<?php

/**
 * Return the shared read-only hardware inventory without blocking page loads.
 * A stale result remains useful for one polling interval while a single
 * background process refreshes it. Configuration-changing endpoints continue
 * to invoke openap-detect directly and therefore never rely on stale data.
 */
function openapReadCachedDetectorReport(int $maxAgeSeconds = 4): ?array
{
    $cacheFile = '/var/cache/openap/detect/report.json';
    $readCache = static function () use ($cacheFile): ?array {
        if (!is_readable($cacheFile)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($cacheFile), true);
        return is_array($decoded) && ($decoded['schema'] ?? '') === 'openap-detect/v1'
            ? $decoded
            : null;
    };

    $cached = $readCache();
    if ($cached !== null) {
        $mtime = @filemtime($cacheFile);
        if ($mtime === false || time() - $mtime > $maxAgeSeconds) {
            exec(
                '/usr/bin/flock -n /var/cache/openap/detect/refresh.lock '
                . '/usr/local/sbin/openap-detect --json --cached --cache-ttl '
                . escapeshellarg((string) $maxAgeSeconds)
                . ' >/dev/null 2>&1 &'
            );
        }
        return $cached;
    }

    $output = [];
    exec(
        '/usr/local/sbin/openap-detect --json --cached --cache-ttl '
        . escapeshellarg((string) $maxAgeSeconds) . ' 2>/dev/null',
        $output,
        $return
    );
    if ($return !== 0) {
        return null;
    }
    $detected = json_decode(implode("\n", $output), true);
    return is_array($detected) ? $detected : null;
}

/**
 * Normalize the wireless portion of an openap-detect report.
 *
 * The legacy `mac` field remains the current runtime address. New role
 * assignments should use `identity_mac`, which prefers the permanent hardware
 * address and survives Linux interface renames.
 */
function openapNormalizeWifiInventory(array $detected): array
{
    $radios = [];
    foreach (($detected['interfaces'] ?? []) as $radio) {
        $name = (string) ($radio['name'] ?? '');
        $currentMac = strtolower((string) ($radio['mac'] ?? ''));
        $permanentMac = strtolower((string) ($radio['permanent_mac'] ?? ''));
        $identityMac = strtolower((string) ($radio['identity_mac'] ?? ''));
        $validMac = static fn(string $mac): bool => (bool) preg_match(
            '/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/',
            $mac
        ) && $mac !== '00:00:00:00:00:00';

        if (empty($radio['is_wireless'])
            || !preg_match('/^[A-Za-z0-9_.:-]+$/', $name)
            || !$validMac($currentMac)) {
            continue;
        }
        if (!$validMac($permanentMac)) {
            $permanentMac = '';
        }
        if (!$validMac($identityMac)) {
            $identityMac = $permanentMac ?: $currentMac;
        }

        $bands = array_values(array_filter([
            !empty($radio['supports_24ghz']) ? '2.4 GHz' : '',
            !empty($radio['supports_5ghz']) ? '5 GHz' : '',
        ]));
        $radios[$name] = [
            'name' => $name,
            'mac' => $currentMac,
            'permanent_mac' => $permanentMac,
            'identity_mac' => $identityMac,
            'phy' => (string) ($radio['phy'] ?? ''),
            'bus' => (string) ($radio['bus'] ?? 'unknown'),
            'driver' => (string) ($radio['driver'] ?? 'unknown'),
            'wireless_type' => (string) ($radio['wireless_type'] ?? ''),
            'channel' => (string) ($radio['channel'] ?? ''),
            'frequency_mhz' => (string) ($radio['frequency_mhz'] ?? ''),
            'details' => strtoupper((string) ($radio['bus'] ?? 'unknown')) . ' · '
                . (string) ($radio['driver'] ?? 'unknown') . ' · '
                . implode(' / ', $bands),
            'supports_ap' => !empty($radio['supports_ap']),
            'supports_managed' => !empty($radio['supports_managed']),
            'supports_24ghz' => !empty($radio['supports_24ghz']),
            'supports_5ghz' => !empty($radio['supports_5ghz']),
            'channels' => is_array($radio['channels'] ?? null) ? array_values($radio['channels']) : [],
            'present' => true,
        ];
    }

    ksort($radios);
    return $radios;
}

function openapFindWifiByIdentity(array $radios, string $identity): ?array
{
    $identity = strtolower(trim($identity));
    if ($identity === '') {
        return null;
    }
    foreach ($radios as $radio) {
        foreach (['identity_mac', 'permanent_mac', 'mac'] as $field) {
            $candidate = (string) ($radio[$field] ?? '');
            if ($candidate !== '' && hash_equals($identity, $candidate)) {
                return $radio;
            }
        }
    }
    return null;
}
