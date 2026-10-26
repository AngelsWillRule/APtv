<?php

function openapClientWatchdog(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false]);
        return;
    }
    $payload = (string) ($_POST['events'] ?? '');
    if ($payload === '' || strlen($payload) > 524288) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        return;
    }
    $events = json_decode($payload, true);
    if (!is_array($events) || count($events) > 1500) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        return;
    }
    $clean = [];
    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $clean[] = [
            'view_id' => preg_replace('/[^a-zA-Z0-9-]/', '', substr((string) ($event['view_id'] ?? ''), 0, 64)),
            'seq' => max(0, (int) ($event['seq'] ?? 0)),
            'frame' => !empty($event['frame']),
            'width' => max(0, min(16384, (int) ($event['width'] ?? 0))),
            'height' => max(0, min(16384, (int) ($event['height'] ?? 0))),
            'time' => substr((string) ($event['time'] ?? ''), 0, 32),
            'type' => substr((string) ($event['type'] ?? ''), 0, 32),
            'path' => substr((string) ($event['path'] ?? ''), 0, 160),
            'visibility' => substr((string) ($event['visibility'] ?? ''), 0, 16),
            'lag_ms' => max(0, min(3600000, (int) ($event['lag_ms'] ?? 0))),
            'heap_mb' => max(0, min(16384, (int) ($event['heap_mb'] ?? 0))),
            'online' => !empty($event['online']),
            'detail' => substr((string) ($event['detail'] ?? ''), 0, 300),
        ];
    }
    error_log('OPENAP_CLIENT_WATCHDOG ' . json_encode([
        'remote' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'events' => $clean,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok' => true, 'accepted' => count($clean)]);
}
