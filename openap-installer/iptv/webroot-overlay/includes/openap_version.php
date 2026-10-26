<?php

function openapInstalledVersion(string $path = '/etc/openap/release'): string
{
    if (!is_readable($path)) {
        return 'Not available';
    }

    $metadata = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($metadata)) {
        return 'Not available';
    }

    $version = trim((string) ($metadata['version'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $version)) {
        return 'Not available';
    }

    $revision = trim((string) ($metadata['revision'] ?? ''));
    if ($revision === '') {
        return $version;
    }
    if (!preg_match('/^[0-9a-f]{7,64}$/i', $revision)) {
        return 'Not available';
    }

    return sprintf('%s (%s)', $version, $revision);
}
