<?php

require __DIR__ . '/../includes/wifi_inventory.php';

$detected = [
    'interfaces' => [
        [
            'name' => 'wlan-old-name',
            'mac' => '02:00:00:00:00:01',
            'permanent_mac' => 'aa:bb:cc:dd:ee:01',
            'identity_mac' => 'aa:bb:cc:dd:ee:01',
            'is_wireless' => true,
            'phy' => 'phy1',
            'bus' => 'usb',
            'driver' => 'example',
            'supports_ap' => true,
            'supports_managed' => true,
            'supports_24ghz' => true,
            'supports_5ghz' => false,
        ],
        [
            'name' => 'eth0',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'is_wireless' => false,
        ],
    ],
];

$radios = openapNormalizeWifiInventory($detected);
if (count($radios) !== 1) exit(1);
$radio = $radios['wlan-old-name'] ?? null;
if (!is_array($radio)) exit(1);
if ($radio['identity_mac'] !== 'aa:bb:cc:dd:ee:01') exit(1);
if (!$radio['supports_24ghz'] || $radio['supports_5ghz']) exit(1);
if (openapFindWifiByIdentity($radios, 'aa:bb:cc:dd:ee:01') !== $radio) exit(1);
if (openapFindWifiByIdentity($radios, '02:00:00:00:00:01') !== $radio) exit(1);

// A runtime rename must not change the identity used by a saved role.
$detected['interfaces'][0]['name'] = 'wlxaabbccddee01';
$renamed = openapNormalizeWifiInventory($detected);
$matched = openapFindWifiByIdentity($renamed, 'aa:bb:cc:dd:ee:01');
if (($matched['name'] ?? '') !== 'wlxaabbccddee01') exit(1);

// Older detector payloads fall back to the current MAC.
unset($detected['interfaces'][0]['permanent_mac'], $detected['interfaces'][0]['identity_mac']);
$legacy = openapNormalizeWifiInventory($detected);
if (($legacy['wlxaabbccddee01']['identity_mac'] ?? '') !== '02:00:00:00:00:01') exit(1);
