<?php

function openapDashboardWidgetIds(): array
{
    return ['clients', 'traffic', 'uplink', 'dhcp', 'system-health', 'services'];
}

function openapNormalizeDashboardWidgetOrder(array $order): array
{
    $allowed = openapDashboardWidgetIds();
    $normalized = [];
    foreach ($order as $id) {
        if (is_string($id) && in_array($id, $allowed, true) && !in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }
    foreach ($allowed as $id) {
        if (!in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }
    return $normalized;
}

function openapUserPreferencesDirectory(): string
{
    return defined('OPENAP_USER_PREFERENCES_DIR')
        ? (string) OPENAP_USER_PREFERENCES_DIR
        : '/etc/openap/user-preferences';
}

function openapUserPreferencesPath(string $username): string
{
    return openapUserPreferencesDirectory() . '/' . hash('sha256', $username) . '.json';
}

/**
 * Backward-compatible helper: the dashboard order is now stored as the
 * "dashboard" page layout. Kept so the existing dashboard read path keeps
 * working unchanged.
 */
function openapReadDashboardWidgetOrder(string $username): array
{
    return openapWidgetLayoutRead($username, 'dashboard')['order'];
}

/**
 * Backward-compatible helper: persists the dashboard order as the "dashboard"
 * page layout. Kept so the existing dashboard endpoint keeps working unchanged.
 */
function openapWriteDashboardWidgetOrder(string $username, array $order): array
{
    return openapWidgetLayoutWrite($username, 'dashboard', [
        'order' => $order,
        'hidden' => [],
        'widths' => [],
    ])['order'];
}

/**
 * Pages that may host the shared widget area.
 */
function openapWidgetPageIds(): array
{
    return ['dashboard', 'ap_configuration', 'dhcp_setting', 'logging', 'clients', 'system', 'hostapd'];
}

/**
 * Default widget width per id (the current dashboard arrangement).
 */
function openapWidgetDefaultWidths(): array
{
    return [
        'clients' => 'col-6',
        'traffic' => 'col-6',
        'uplink' => 'col-6',
        'system-health' => 'col-12',
        'services' => 'col-12',
        'dhcp' => 'col-6',
    ];
}

/**
 * Restrict a widths map to valid ids and valid column classes, filling any
 * missing id with its default.
 */
function openapNormalizeWidgetWidths(array $widths): array
{
    $defaults = openapWidgetDefaultWidths();
    $normalized = [];
    foreach ($defaults as $id => $default) {
        $value = is_array($widths) ? ($widths[$id] ?? $default) : $default;
        $normalized[$id] = in_array($value, ['col-6', 'col-12'], true) ? $value : $default;
    }
    return $normalized;
}

/**
 * Restrict a hidden list to valid widget ids (order within it is irrelevant).
 */
function openapNormalizeWidgetHidden(array $hidden): array
{
    $allowed = openapDashboardWidgetIds();
    $normalized = [];
    foreach ($hidden as $id) {
        if (is_string($id) && in_array($id, $allowed, true) && !in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }
    return $normalized;
}

/**
 * Read the widget layout for a user + page.
 *
 * Returns ['order' => [...], 'hidden' => [...], 'widths' => [...]]. Missing
 * files, malformed data and unknown usernames fall back to the current
 * dashboard defaults (order = full widget list, nothing hidden, default widths).
 */
function openapWidgetLayoutRead(string $username, string $page): array
{
    $defaults = [
        'order' => openapDashboardWidgetIds(),
        'hidden' => [],
        'widths' => openapWidgetDefaultWidths(),
    ];
    if ($username === '') {
        return $defaults;
    }
    $path = openapUserPreferencesPath($username);
    if (!is_readable($path)) {
        return $defaults;
    }
    $stored = json_decode((string) file_get_contents($path), true);
    if (!is_array($stored) || !hash_equals((string) ($stored['username'] ?? ''), $username)) {
        return $defaults;
    }
    $pageLayout = is_array($stored['layouts'][$page] ?? null) ? $stored['layouts'][$page] : null;
    // Backward compatibility: a legacy dashboard-only file has no "layouts".
    if ($pageLayout === null && $page === 'dashboard' && is_array($stored['dashboard_widgets'] ?? null)) {
        return [
            'order' => openapNormalizeDashboardWidgetOrder($stored['dashboard_widgets']),
            'hidden' => [],
            'widths' => openapWidgetDefaultWidths(),
        ];
    }
    if ($pageLayout === null) {
        return $defaults;
    }
    return [
        'order' => openapNormalizeDashboardWidgetOrder(is_array($pageLayout['order'] ?? null) ? $pageLayout['order'] : []),
        'hidden' => openapNormalizeWidgetHidden(is_array($pageLayout['hidden'] ?? null) ? $pageLayout['hidden'] : []),
        'widths' => openapNormalizeWidgetWidths(is_array($pageLayout['widths'] ?? null) ? $pageLayout['widths'] : []),
    ];
}

/**
 * Persist the widget layout for a user + page, preserving any other pages
 * already stored in the same file. Validates page and widget ids so a client
 * can never inject arbitrary identifiers.
 */
function openapWidgetLayoutWrite(string $username, string $page, array $layout): array
{
    if ($username === '') {
        throw new InvalidArgumentException('An authenticated username is required.');
    }
    if (!in_array($page, openapWidgetPageIds(), true)) {
        throw new InvalidArgumentException('Unknown widget page.');
    }
    $allowed = openapDashboardWidgetIds();

    // The order must be a full permutation of the widget ids.
    $submittedOrder = array_values(array_filter(is_array($layout['order'] ?? null) ? $layout['order'] : [], 'is_string'));
    if (count($submittedOrder) !== count($allowed)
        || array_diff($allowed, $submittedOrder) !== []
        || array_diff($submittedOrder, $allowed) !== []
        || count(array_unique($submittedOrder)) !== count($allowed)) {
        throw new InvalidArgumentException('The widget order is incomplete or invalid.');
    }
    $order = openapNormalizeDashboardWidgetOrder($layout['order']);
    $hidden = openapNormalizeWidgetHidden(is_array($layout['hidden'] ?? null) ? $layout['hidden'] : []);
    $widths = openapNormalizeWidgetWidths(is_array($layout['widths'] ?? null) ? $layout['widths'] : []);

    $directory = openapUserPreferencesDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the user preferences directory.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The user preferences directory is not writable.');
    }

    // Read the existing file so other pages' layouts are preserved.
    $stored = [];
    $path = openapUserPreferencesPath($username);
    if (is_readable($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    if (!is_array($stored['layouts'] ?? null)) {
        $stored['layouts'] = [];
    }
    $stored['version'] = 2;
    $stored['username'] = $username;
    $stored['layouts'][$page] = [
        'order' => $order,
        'hidden' => $hidden,
        'widths' => $widths,
    ];
    $stored['updated_at'] = gmdate('c');

    $temporary = tempnam($directory, '.widget-layouts.');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary preference file.');
    }
    $payload = json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    try {
        if (file_put_contents($temporary, $payload, LOCK_EX) === false || !chmod($temporary, 0640)) {
            throw new RuntimeException('Unable to write the user preference file.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Unable to publish the user preference file.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    return ['order' => $order, 'hidden' => $hidden, 'widths' => $widths];
}
