<?php

/**
 * Sidebar UI class
 *
 * @description A sidebar class for the RaspAP UI, extendable by user plugins
 * @author      Bill Zimmerman <billzimmerman@gmail.com>
 * @license     https://github.com/raspap/raspap-webgui/blob/master/LICENSE
 */

namespace OpenAP\UI;

class Sidebar {
    private $items = [];

    public function __construct() {
        // Load default sidebar items
        $this->addItem(_('Dashboard'), 'fa-solid fa-gauge-high', 'dashboard', 10);
        $this->addItem(_('AP Configuration'), 'fas fa-broadcast-tower', 'ap_configuration', 20,
            fn() => OPENAP_HOTSPOT_ENABLED
        );
        $this->addItem(_('DHCP and DNS Setting'), 'fas fa-exchange-alt', 'dhcp_setting', 25,
            fn() => OPENAP_HOTSPOT_ENABLED
        );
        $this->addItem(_('Logging'), 'fas fa-file-alt', 'logging', 27,
            fn() => OPENAP_HOTSPOT_ENABLED
        );
        $this->addItem(_('DHCP Server'), 'fas fa-exchange-alt', 'dhcpd_conf', 30,
            fn() => OPENAP_DHCP_ENABLED && !$_SESSION["bridgedEnabled"]
        );
        $this->addItem(_('Ad Blocking'), 'far fa-hand-paper', 'adblock_conf', 40,
            fn() => OPENAP_ADBLOCK_ENABLED && OPENAP_HOTSPOT_ENABLED && !$_SESSION["bridgedEnabled"]
        );
        $this->addItem(_('Networking'), 'fas fa-network-wired', 'network_conf', 50,
            fn() => OPENAP_NETWORK_ENABLED
        );
        $this->addItem(_('WiFi client'), 'fas fa-wifi', 'wpa_conf', 60,
            fn() => OPENAP_WIFICLIENT_ENABLED && !$_SESSION["bridgedEnabled"]
        );
        $this->addItem(_('OpenVPN'), 'fas fa-key', 'openvpn_conf', 70,
            fn() => OPENAP_OPENVPN_ENABLED
        );
        $this->addItem(_('WireGuard'), 'ra-wireguard', 'wg_conf', 80,
            fn() => OPENAP_WIREGUARD_ENABLED
        );
        $this->addItem(_(getProviderValue($_SESSION["providerID"], "name")), 'fas fa-shield-alt', 'provider_conf', 90,
            fn() => OPENAP_VPN_PROVIDER_ENABLED
        );
        $this->addItem(_('Data usage'), 'fas fa-chart-area', 'data_use', 110,
            fn() => OPENAP_VNSTAT_ENABLED
        );
        $this->addItem(_('RestAPI'), 'fas fa-puzzle-piece', 'restapi_conf', 120,
            fn() => OPENAP_RESTAPI_ENABLED
        );
        $this->addItem(_('System'), 'fas fa-cube', 'system_info', 130,
            fn() => OPENAP_SYSTEM_ENABLED
        );
    }

    /**
     * Adds an item to the sidebar
     * @param string $label
     * @param string $iconClass
     * @param string $link
     * @param int $priority
     * @param callable $condition
     */
    private static function ethernetBridgeActive(): bool {
        $profile = @parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
        return is_array($profile) && (($profile['mode']['current'] ?? '') === 'ap_ethernet_bridge');
    }

    public function addItem(string $label, string $iconClass, string $link, int $priority, callable $condition = null) {
        $this->items[] = [
            'label' => $label,
            'icon' => $iconClass,
            'link' => $link,
            'priority' => $priority,
            'condition' => $condition
        ];
    }

    public function getItems(): array {
        // Sort items by priority and filter by enabled condition
        $filteredItems = array_filter($this->items, function ($item) {
            return $item['condition'] === null || call_user_func($item['condition']);
        });
        usort($filteredItems, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        return $filteredItems;
    }

    public function render() {
        echo '<span class="openap-sidebar-indicator" aria-hidden="true"></span>';
        foreach ($this->getItems() as $index => $item) {
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            echo "<div class=\"sb-nav-link-icon px-2\" data-openap-sidebar-index=\"{$index}\">
                  <a class=\"nav-link\" href=\"{$item['link']}\" title=\"{$label}\" aria-label=\"{$label}\">
                    <i class=\"sb-nav-link-icon {$item['icon']} fa-fw mr-2\"></i>
                    <span class=\"nav-label\">{$label}</span>
                  </a>
                  </div>";
        }
    }
}
