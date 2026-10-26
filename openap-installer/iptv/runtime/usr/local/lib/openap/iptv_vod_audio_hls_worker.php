<?php
if (PHP_SAPI !== 'cli' || !in_array(count($argv), [7, 8], true)) exit(64);
require_once '/var/www/html/includes/iptv_api.php';

$id = (string) $argv[1];
$token = (string) $argv[2];
$start = max(0, min(86400, (int) $argv[3]));
$audio = max(0, min(31, (int) $argv[4]));
$kind = openapAptvVodKind((string) $argv[6]);
$providerId = trim((string) ($argv[7] ?? ''));
$directory = openapAptvVodAudioHlsDirectory($token);
$vod = openapAptvVod($id, $kind, $providerId !== '' ? $providerId : null);
if (!$vod && $providerId === '') $vod = openapAptvItalianVod($id);
$context = openapAptvProviderContextForRequest($providerId !== '' ? $providerId : null);
$profile = is_array($context) ? (array) ($context['profile'] ?? []) : null;
if (!$directory || !is_dir($directory) || !$vod) exit(65);
if (!$profile) exit(66);

file_put_contents($directory . '/worker.pid', (string) getmypid(), LOCK_EX);
$proxy = @proc_open(
    array_values(array_filter(['/usr/bin/php', '/usr/local/lib/openap/iptv_vod_proxy.php', $id, $token, $kind, $providerId], static fn ($value) => $value !== '')),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $directory . '/proxy.log', 'a']],
    $proxyPipes
);
$port = 0; $deadline = microtime(true) + 5;
while (microtime(true) < $deadline && $port < 1) {
    $port = (int) @file_get_contents($directory . '/source.port');
    if ($port < 1) usleep(50000);
}
if (!is_resource($proxy) || $port < 1) { touch($directory . '/failed'); exit(68); }

$command = ['/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'warning'];
if ($start > 0) $command = array_merge($command, ['-ss', (string) $start]);
$command = array_merge($command, [
    '-readrate', '1', '-readrate_initial_burst', '8', '-i', 'http://127.0.0.1:' . $port . '/movie',
    '-map', '0:a:' . $audio, '-vn', '-c:a', 'aac', '-b:a', '192k', '-ac', '2', '-ar', '48000',
    '-f', 'hls', '-hls_time', '2', '-hls_list_size', '12', '-hls_delete_threshold', '3',
    '-hls_flags', 'delete_segments+temp_file', '-hls_segment_filename', $directory . '/segment_%06d.ts',
    $directory . '/index.m3u8',
]);
$ffmpeg = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $directory . '/ffmpeg.log', 'a']], $ffmpegPipes);
if (!is_resource($ffmpeg)) { touch($directory . '/failed'); proc_terminate($proxy); proc_close($proxy); exit(69); }
while (true) {
    clearstatcache(); $ff = proc_get_status($ffmpeg); $px = proc_get_status($proxy); $heartbeat = @filemtime($directory . '/heartbeat') ?: 0;
    if (is_file($directory . '/stop') || time() - $heartbeat > 45) break;
    if (!$ff['running'] || !$px['running']) { touch($directory . '/failed'); break; }
    usleep(500000);
}
proc_terminate($ffmpeg); proc_terminate($proxy); usleep(250000); proc_close($ffmpeg); proc_close($proxy);
$failed = is_file($directory . '/failed');
if ($failed) exit(70);
foreach (glob($directory . '/*') ?: [] as $entry) @unlink($entry);
@rmdir($directory);
