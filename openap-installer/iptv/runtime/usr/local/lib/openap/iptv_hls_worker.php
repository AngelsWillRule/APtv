<?php

if (PHP_SAPI !== 'cli' || count($argv) !== 3) exit(64);
require_once '/var/www/html/includes/iptv_api.php';

$itemId = (string) $argv[1];
$token = (string) $argv[2];
$directory = openapAptvHevcHlsJobDirectory($token);
if ($directory === null || !is_dir($directory) || !preg_match('/^[0-9]{1,20}$/', $itemId) || !openapAptvLiveChannel($itemId)) exit(65);
$profile = openapAptvProviderProfile();
if (!$profile) exit(66);
$baseUrl = rtrim((string) ($profile['url'] ?? ''), '/');
$username = (string) ($profile['username'] ?? '');
$password = (string) ($profile['password'] ?? '');
$parts = parse_url($baseUrl);
if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $username === '' || $password === '') exit(67);
$upstream = $baseUrl . '/live/' . rawurlencode($username) . '/' . rawurlencode($password) . '/' . $itemId . '.ts';
file_put_contents($directory . '/worker.pid', (string) getmypid(), LOCK_EX);

$segmentPattern = $directory . '/segment_%06d.ts';
$manifest = $directory . '/index.m3u8';
$ffmpeg = @proc_open([
    '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
    '-fflags', 'nobuffer', '-flags', 'low_delay', '-probesize', '2000000', '-analyzeduration', '2000000',
    '-re', '-i', 'pipe:0', '-map', '0:v:0', '-map', '0:a:0?', '-c:v', 'libx264', '-preset', 'ultrafast',
    '-tune', 'zerolatency', '-pix_fmt', 'yuv420p', '-profile:v', 'main', '-g', '50', '-keyint_min', '50',
    '-sc_threshold', '0', '-c:a', 'copy', '-f', 'hls', '-hls_time', '2', '-hls_list_size', '6',
    '-hls_delete_threshold', '2', '-hls_flags', 'delete_segments+independent_segments+temp_file',
    '-hls_segment_filename', $segmentPattern, $manifest,
], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $directory . '/ffmpeg.log', 'a']], $ffmpegPipes);
if (!is_resource($ffmpeg) || !isset($ffmpegPipes[0])) exit(68);

$curl = @proc_open([
    '/usr/bin/curl', '--silent', '--show-error', '--fail', '--location', '--max-redirs', '5',
    '--connect-timeout', '12', '--speed-limit', '512', '--speed-time', '20',
    '--user-agent', 'APtv-HLS-transcoder/0.1', '--config', '-',
], [0 => ['pipe', 'r'], 1 => $ffmpegPipes[0], 2 => ['file', '/dev/null', 'a']], $curlPipes);
if (!is_resource($curl) || !isset($curlPipes[0])) { fclose($ffmpegPipes[0]); proc_terminate($ffmpeg); proc_close($ffmpeg); exit(69); }
$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $upstream);
$config = 'url = "' . $escaped . '"' . "\n";
fwrite($curlPipes[0], $config); fclose($curlPipes[0]); fclose($ffmpegPipes[0]);
sodium_memzero($config); sodium_memzero($escaped); sodium_memzero($upstream); sodium_memzero($password);
if (isset($profile['password']) && is_string($profile['password'])) sodium_memzero($profile['password']);

while (true) {
    clearstatcache();
    $curlStatus = proc_get_status($curl);
    $ffmpegStatus = proc_get_status($ffmpeg);
    $heartbeat = @filemtime($directory . '/heartbeat') ?: 0;
    if (is_file($directory . '/stop') || time() - $heartbeat > 20 || !$curlStatus['running'] || !$ffmpegStatus['running']) break;
    usleep(500000);
}
proc_terminate($curl); proc_terminate($ffmpeg); usleep(250000);
proc_close($curl); proc_close($ffmpeg);
foreach (glob($directory . '/*') ?: [] as $path) @unlink($path);
@rmdir($directory);
