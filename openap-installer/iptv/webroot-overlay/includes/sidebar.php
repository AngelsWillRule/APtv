<?php

use OpenAP\Plugins\PluginManager;

$pluginManager = PluginManager::getInstance();

$hostapd_led = $hostapd_led ?? "service-status-warn";
$hostapd_status = $hostapd_status ?? "unknown";
$memused_led = $memused_led ?? "service-status-warn";
$memused = $memused ?? 0;
$cputemp_led = $cputemp_led ?? "service-status-warn";
$cputemp = $cputemp ?? "0.0";

// Render sidebar via the PluginManager
$sidebar = $pluginManager->getSidebar();
$sidebar->render();
?>
