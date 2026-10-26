<?php

$temporaryDirectory = sys_get_temp_dir() . '/openap-user-preferences-' . bin2hex(random_bytes(6));
define('OPENAP_USER_PREFERENCES_DIR', $temporaryDirectory);
require __DIR__ . '/../includes/user_preferences.php';

$defaults = openapDashboardWidgetIds();
if (openapReadDashboardWidgetOrder('alice') !== $defaults) exit(1);

$custom = ['services', 'dhcp', 'clients', 'traffic', 'uplink', 'system-health'];
if (openapWriteDashboardWidgetOrder('alice', $custom) !== $custom) exit(1);
if (openapReadDashboardWidgetOrder('alice') !== $custom) exit(1);
if (openapReadDashboardWidgetOrder('bob') !== $defaults) exit(1);
if ((fileperms(openapUserPreferencesPath('alice')) & 0777) !== 0640) exit(1);

try {
    openapWriteDashboardWidgetOrder('alice', ['clients', 'clients']);
    exit(1);
} catch (InvalidArgumentException $error) {
    // Invalid and incomplete layouts must not replace the stored preference.
}
if (openapReadDashboardWidgetOrder('alice') !== $custom) exit(1);

unlink(openapUserPreferencesPath('alice'));
rmdir($temporaryDirectory);
