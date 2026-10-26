<?php

require_once 'includes/config.php';

function DisplayHotspotClients()
{
    $clients = getHotspotClients(OPENAP_WIFI_AP_INTERFACE);
    echo renderTemplate("clients", compact("clients"));
}

function getHotspotClients(string $interface): array
{
    $leases = getDnsmasqLeases();
    $neighbors = getInterfaceNeighbors($interface);
    $stations = getHostapdStations($interface);
    $clients = [];

    foreach ($stations as $mac => $station) {
        $macLower = strtolower($mac);
        $lease = $leases[$macLower] ?? [];
        $clients[$macLower] = [
            'mac' => $macLower,
            'ip' => $lease['ip'] ?? ($neighbors[$macLower]['ip'] ?? '-'),
            'hostname' => $lease['hostname'] ?? '-',
            'signal' => $station['signal'] ?? '-',
            'rx_rate' => $station['rx_rate_info'] ?? '-',
            'tx_rate' => $station['tx_rate_info'] ?? '-',
            'connected' => formatClientUptime((int)($station['connected_time'] ?? 0)),
            'state' => $neighbors[$macLower]['state'] ?? '-',
        ];
    }

    foreach ($neighbors as $mac => $neighbor) {
        if (isset($clients[$mac])) {
            continue;
        }
        $lease = $leases[$mac] ?? [];
        $clients[$mac] = [
            'mac' => $mac,
            'ip' => $lease['ip'] ?? $neighbor['ip'],
            'hostname' => $lease['hostname'] ?? '-',
            'signal' => '-',
            'rx_rate' => '-',
            'tx_rate' => '-',
            'connected' => '-',
            'state' => $neighbor['state'],
        ];
    }

    ksort($clients);
    return array_values($clients);
}

/**
 * Dashboard-facing client list. The detailed clients page historically uses
 * the formatted uptime string in `connected`; expose an explicit boolean to
 * the dashboard without changing that existing data contract.
 */
function openapGetClientList(string $interface): array
{
    static $cache = [];
    if (isset($cache[$interface])) {
        return $cache[$interface];
    }

    $leases = getDnsmasqLeases();
    $neighbors = getInterfaceNeighbors($interface);
    $clients = [];
    foreach (getIwStations($interface) as $mac => $station) {
        $lease = $leases[$mac] ?? [];
        $clients[] = [
            'mac' => $mac,
            'ip' => $lease['ip'] ?? ($neighbors[$mac]['ip'] ?? '-'),
            'hostname' => $lease['hostname'] ?? '-',
            'signal' => $station['signal'] ?? '-',
            'rx_rate' => $station['rx_rate_info'] ?? '-',
            'tx_rate' => $station['tx_rate_info'] ?? '-',
            'connected' => true,
            'connected_for' => formatClientUptime((int) ($station['connected_time'] ?? 0)),
            'state' => $neighbors[$mac]['state'] ?? '-',
            'upload_bytes' => (int) ($station['rx_bytes'] ?? 0),            'download_bytes' => (int) ($station['tx_bytes'] ?? 0),            'traffic_bytes' => (int) ($station['rx_bytes'] ?? 0) + (int) ($station['tx_bytes'] ?? 0),
        ];
    }
    $cache[$interface] = $clients;
    return $clients;
}

/**
 * Return the stations associated with every active AP radio.  In the
 * multi-radio layout DHCP and neighbour information belongs to the hotspot
 * bridge, while station counters continue to belong to the individual radios.
 */
function openapGetClientListForInterfaces(array $interfaces, string $neighborInterface = ''): array
{
    $interfaces = array_values(array_unique(array_filter($interfaces, static function ($interface): bool {
        return is_string($interface) && preg_match('/^[A-Za-z0-9_.:-]+$/', $interface) === 1;
    })));
    if ($interfaces === []) {
        return [];
    }

    $leases = getDnsmasqLeases();
    $neighbors = getInterfaceNeighbors($neighborInterface !== '' ? $neighborInterface : $interfaces[0]);
    $clients = [];
    foreach ($interfaces as $interface) {
        $band = openapGetInterfaceBand($interface);
        foreach (getIwStations($interface) as $mac => $station) {
            $lease = $leases[$mac] ?? [];
            $candidate = [
                'mac' => $mac,
                'ip' => $lease['ip'] ?? ($neighbors[$mac]['ip'] ?? '-'),
                'hostname' => $lease['hostname'] ?? '-',
                'signal' => $station['signal'] ?? '-',
                'rx_rate' => $station['rx_rate_info'] ?? '-',
                'tx_rate' => $station['tx_rate_info'] ?? '-',
                'connected' => true,
                'connected_seconds' => (int) ($station['connected_time'] ?? 0),
                'connected_for' => formatClientUptime((int) ($station['connected_time'] ?? 0)),
                'state' => $neighbors[$mac]['state'] ?? '-',
                'upload_bytes' => (int) ($station['rx_bytes'] ?? 0),
                'download_bytes' => (int) ($station['tx_bytes'] ?? 0),
                'traffic_bytes' => (int) ($station['rx_bytes'] ?? 0) + (int) ($station['tx_bytes'] ?? 0),
                'interface' => $interface,
                'band' => $band,
            ];
            if (!isset($clients[$mac]) || $candidate['connected_seconds'] >= $clients[$mac]['connected_seconds']) {
                $clients[$mac] = $candidate;
            }
        }
    }

    ksort($clients);
    return array_values($clients);
}

function openapGetInterfaceBand(string $interface): string
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return '';
    }
    exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' info 2>/dev/null', $output, $status);
    if ($status !== 0) {
        return '';
    }
    if (preg_match('/\bchannel\s+\d+\s+\((\d+)\s+MHz\)/', implode("\n", $output), $match)) {
        return (int) $match[1] >= 5000 ? '5' : '2.4';
    }
    return '';
}

function openapGetClientBreakdownForList(array $clients): array
{
    $signals = [];
    $breakdown = ['total' => 0, 'avg_signal' => 0, 'strong' => 0, 'medium' => 0, 'weak' => 0];
    foreach ($clients as $client) {
        if (empty($client['connected'])) {
            continue;
        }
        $breakdown['total']++;
        if (!is_numeric($client['signal'] ?? null)) {
            continue;
        }
        $signal = (int) $client['signal'];
        $signals[] = $signal;
        $signal >= -60 ? $breakdown['strong']++ : ($signal >= -75 ? $breakdown['medium']++ : $breakdown['weak']++);
    }
    if ($signals !== []) {
        $breakdown['avg_signal'] = (int) round(array_sum($signals) / count($signals));
    }
    return $breakdown;
}

function openapGetClientDeviceBreakdown(string $interface): array
{
    return openapGetClientBreakdownForList(openapGetClientList($interface));
}

function getHostapdStations(string $interface): array
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $interface)) {
        return [];
    }

    $cmd = sprintf(
        'sudo /usr/sbin/hostapd_cli -p /run/hostapd -i %s all_sta',
        escapeshellarg($interface)
    );
    exec($cmd, $output, $status);
    if ($status !== 0) {
        return [];
    }

    $stations = [];
    $currentMac = null;
    foreach ($output as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([0-9a-f]{2}(?::[0-9a-f]{2}){5})$/i', $line, $matches)) {
            $currentMac = strtolower($matches[1]);
            $stations[$currentMac] = [];
            continue;
        }
        if ($currentMac !== null && strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $stations[$currentMac][$key] = $value;
        }
    }
    return $stations;
}

function getIwStations(string $interface): array
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $interface)) {
        return [];
    }

    exec('/usr/sbin/iw dev ' . escapeshellarg($interface) . ' station dump 2>/dev/null', $output, $status);
    if ($status !== 0) {
        return [];
    }

    $stations = [];
    $currentMac = null;
    foreach ($output as $line) {
        if (preg_match('/^Station\s+([0-9a-f:]{17})\s+/i', trim($line), $matches)) {
            $currentMac = strtolower($matches[1]);
            $stations[$currentMac] = [];
            continue;
        }
        if ($currentMac === null || !preg_match('/^\s*([^:]+):\s*(.*?)\s*$/', $line, $matches)) {
            continue;
        }
        $key = strtolower(trim($matches[1]));
        $value = trim($matches[2]);
        if ($key === 'signal' && preg_match('/-?\d+/', $value, $signal)) {
            $stations[$currentMac]['signal'] = $signal[0];
        } elseif ($key === 'rx bytes' && preg_match('/^[0-9]+$/', $value)) {
            $stations[$currentMac]['rx_bytes'] = (int) $value;
        } elseif ($key === 'tx bytes' && preg_match('/^[0-9]+$/', $value)) {
            $stations[$currentMac]['tx_bytes'] = (int) $value;
        } elseif ($key === 'rx bitrate') {
            $stations[$currentMac]['rx_rate_info'] = $value;
        } elseif ($key === 'tx bitrate') {
            $stations[$currentMac]['tx_rate_info'] = $value;
        } elseif ($key === 'connected time' && preg_match('/\d+/', $value, $seconds)) {
            $stations[$currentMac]['connected_time'] = $seconds[0];
        }
    }
    return $stations;
}

function getDnsmasqLeases(): array
{
    $leases = [];
    if (!is_readable(OPENAP_DNSMASQ_LEASES)) {
        return $leases;
    }

    foreach (file(OPENAP_DNSMASQ_LEASES, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) < 4) {
            continue;
        }
        $leases[strtolower($fields[1])] = [
            'ip' => $fields[2],
            'hostname' => $fields[3] === '*' ? '-' : $fields[3],
        ];
    }
    return $leases;
}

function getInterfaceNeighbors(string $interface): array
{
    $neighbors = [];
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $interface)) {
        return $neighbors;
    }

    exec('ip neigh show dev ' . escapeshellarg($interface), $output, $status);
    if ($status !== 0) {
        return $neighbors;
    }

    foreach ($output as $line) {
        if (preg_match('/^(\S+)(?:\s+dev\s+\S+)?\s+lladdr\s+([0-9a-f:]{17})\s+(\S+)/i', $line, $matches)) {
            $neighbors[strtolower($matches[2])] = [
                'ip' => $matches[1],
                'state' => $matches[3],
            ];
        }
    }
    return $neighbors;
}

function formatClientUptime(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return sprintf('%dh %02dm', $hours, $minutes);
    }
    return sprintf('%dm', $minutes);
}
