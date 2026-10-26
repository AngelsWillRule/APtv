<?php
if (PHP_SAPI !== 'cli' || !in_array(count($argv), [6, 7], true)) exit(64);
require_once '/var/www/html/includes/iptv_api.php';

$id = (string) $argv[1];
$token = (string) $argv[2];
$start = max(0, min(86400, (int) $argv[3]));
$audio = max(0, min(31, (int) $argv[4]));
$kind = openapAptvVodKind((string) $argv[5]);
$providerId = trim((string) ($argv[6] ?? ''));
$directory = openapAptvVodHlsDirectory($token);
$vod = openapAptvVod($id, $kind, $providerId !== '' ? $providerId : null);
$context = openapAptvProviderContextForRequest($providerId !== '' ? $providerId : null);
$profile = is_array($context) ? (array) ($context['profile'] ?? []) : null;
if (!$directory || !is_dir($directory) || !$vod || !$profile) exit(65);
$cache = openapAptvVodProbeCacheFile($id, $kind, $providerId !== '' ? $providerId : null);
$probe = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
$tracks = is_array($probe['audio'] ?? null) ? $probe['audio'] : [];
$videoCodec = strtolower((string) ($probe['video']['codec'] ?? ''));
$audioCodec = strtolower((string) ($tracks[$audio]['codec'] ?? ''));
$audioTranscodeProfile = ($videoCodec === 'h264' && in_array($audioCodec, ['dts', 'ac3', 'eac3'], true)) || ($videoCodec === 'hevc' && in_array($audioCodec, ['ac3', 'eac3'], true));
$videoTranscodeProfile = $videoCodec === 'mpeg4' && in_array($audioCodec, ['aac', 'mp3'], true);
$muxedAudioProfile = $audioTranscodeProfile;
if ((!$videoTranscodeProfile && !in_array($videoCodec, ['h264', 'hevc'], true)) || (!$audioTranscodeProfile && !$videoTranscodeProfile && !in_array($audioCodec, ['aac', 'mp3'], true))) exit(66);

file_put_contents($directory . '/worker.pid', (string) getmypid(), LOCK_EX);
foreach (['video', 'audio0'] as $variant) {
    if (!mkdir($directory . '/' . $variant, 0700) && !is_dir($directory . '/' . $variant)) {
        touch($directory . '/failed'); exit(67);
    }
}
$proxy = @proc_open(
    array_values(array_filter(['/usr/bin/php', '/usr/local/lib/openap/iptv_vod_proxy.php', $id, $token, $kind, $providerId], static fn ($value) => $value !== '')),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $directory . '/proxy.log', 'a']], $proxyPipes
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
    '-readrate', '1', '-i', 'http://127.0.0.1:' . $port . '/movie',
    '-map', '0:v:0', '-map', '0:a:' . $audio,
]);
if ($videoTranscodeProfile) {
    $command = array_merge($command, [
        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-pix_fmt', 'yuv420p',
        '-profile:v', 'main', '-g', '48', '-keyint_min', '48', '-sc_threshold', '0',
        '-force_key_frames', 'expr:gte(t,n_forced*2)',
    ]);
} else {
    $command = array_merge($command, ['-c:v', 'copy']);
}
if ($audioTranscodeProfile) {
    $command = array_merge($command, ['-c:a', 'aac', '-b:a:0', '192k', '-ac', '2']);
} else {
    $command = array_merge($command, ['-c:a', 'copy', '-b:a:0', '384k']);
}
if ($muxedAudioProfile) {
    // Keep the copied HEVC video and the AAC compatibility track in the same
    // MPEG-TS variant. Some browsers load the separate audio rendition but
    // never attach it to a HEVC video variant.
    $width = max(0, (int) ($probe['video']['width'] ?? 0));
    $height = max(0, (int) ($probe['video']['height'] ?? 0));
    $bandwidth = max(6000000, (int) ($probe['source_bitrate'] ?? 0));
    $masterContents = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-INDEPENDENT-SEGMENTS\n";
    $masterContents .= '#EXT-X-STREAM-INF:BANDWIDTH=' . $bandwidth;
    if ($width > 0 && $height > 0) $masterContents .= ',RESOLUTION=' . $width . 'x' . $height;
    $masterContents .= "\nvideo/index.m3u8\n";
    $command = array_merge($command, [
        '-b:v:0', '5000k',
        '-f', 'hls', '-hls_time', '2', '-hls_list_size', '12', '-hls_delete_threshold', '3',
        '-hls_flags', 'delete_segments+temp_file+independent_segments',
        '-hls_segment_filename', $directory . '/video/segment_%06d.ts', $directory . '/video/index.m3u8',
    ]);
} else {
    $command = array_merge($command, [
        ...($videoTranscodeProfile ? ['-crf', '22', '-maxrate', '1500k', '-bufsize', '3000k'] : ['-b:v:0', '5000k']),
        '-f', 'hls', '-hls_time', '2', '-hls_list_size', '12', '-hls_delete_threshold', '3',
        '-hls_flags', 'delete_segments+temp_file+independent_segments', '-master_pl_name', 'master.m3u8',
        '-var_stream_map', 'v:0,agroup:audio,name:video a:0,agroup:audio,name:audio0,language:' . preg_replace('/[^a-z0-9_-]/i', '', (string) ($tracks[$audio]['language'] ?? 'und')) . ',default:yes',
        '-hls_segment_filename', $directory . '/%v/segment_%06d.ts', $directory . '/%v/index.m3u8',
    ]);
}
$ffmpeg = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $directory . '/ffmpeg.log', 'a']], $ffmpegPipes);
if (!is_resource($ffmpeg)) { touch($directory . '/failed'); proc_terminate($proxy); proc_close($proxy); exit(69); }
if ($muxedAudioProfile) {
    $masterReady = false;
    $manifestDeadline = microtime(true) + 30;
    while (microtime(true) < $manifestDeadline) {
        clearstatcache(true, $directory . '/video/index.m3u8');
        if (is_file($directory . '/video/index.m3u8') && filesize($directory . '/video/index.m3u8') >= 40) {
            file_put_contents($directory . '/master.m3u8', $masterContents, LOCK_EX);
            $masterReady = true;
            break;
        }
        $status = proc_get_status($ffmpeg);
        if (!$status['running']) break;
        usleep(100000);
    }
    if (!$masterReady) touch($directory . '/failed');
}
while (true) {
    clearstatcache(); $ff = proc_get_status($ffmpeg); $px = proc_get_status($proxy); $heartbeat = @filemtime($directory . '/heartbeat') ?: 0;
    if (is_file($directory . '/stop') || time() - $heartbeat > 45) break;
    if (!$ff['running'] || !$px['running']) { touch($directory . '/failed'); break; }
    usleep(500000);
}
proc_terminate($ffmpeg); proc_terminate($proxy); usleep(250000); proc_close($ffmpeg); proc_close($proxy);
if (is_file($directory . '/failed')) exit(70);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
@rmdir($directory);
