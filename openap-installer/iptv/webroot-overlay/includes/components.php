<?php

/**
 * Resolve the components installed by the OpenAP installer.
 *
 * The profile file is deliberately outside the webroot so a partial web
 * deployment cannot advertise a component before its runtime is ready.
 */
function openapComponentProfile(): string
{
    static $profile;
    if ($profile !== null) {
        return $profile;
    }

    $profile = '';
    $profilePath = '/etc/openap/components/profile';
    if (is_file($profilePath)) {
        $candidate = strtolower(trim((string) @file_get_contents($profilePath)));
        if (in_array($candidate, ['openap', 'iptv', 'both'], true)) {
            $profile = $candidate;
            return $profile;
        }
    }

    // Backward compatibility for installations created before component
    // profiles existed. An installed release is OpenAP; complete APtv files
    // and runtime are enough to recognize the older combined deployment.
    if (is_file('/etc/openap/release')) {
        $profile = is_file(__DIR__ . '/iptv.php')
            && is_file(__DIR__ . '/iptv_api.php')
            && is_file('/usr/lib/openap-iptv/index.php')
            ? 'both'
            : 'openap';
    } elseif (is_file(__DIR__ . '/iptv.php')
        && is_file(__DIR__ . '/iptv_api.php')
        && is_file('/usr/lib/openap-iptv/index.php')) {
        $profile = 'iptv';
    }

    return $profile;
}

function openapComponentEnabled(string $component): bool
{
    $profile = openapComponentProfile();
    if ($component === 'openap') {
        return in_array($profile, ['openap', 'both'], true);
    }
    if ($component === 'iptv') {
        return in_array($profile, ['iptv', 'both'], true);
    }
    return false;
}
