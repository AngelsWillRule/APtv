<?php
if (PHP_SAPI !== 'cli' || !in_array(count($argv), [3, 4, 5], true)) exit(64);
require_once '/var/www/html/includes/iptv_api.php';
$id = (string) $argv[1];
$token = (string) $argv[2];
$kind = openapAptvVodKind((string) ($argv[3] ?? ''));
$providerId = trim((string) ($argv[4] ?? ''));
$directory = openapAptvWebrtcDirectory($token);
if (!$directory || !is_dir($directory)) $directory = openapAptvVodHlsDirectory($token);
if (!$directory || !is_dir($directory)) $directory = openapAptvVodRemuxDirectory($token);
$vod = openapAptvVod($id, $kind, $providerId !== '' ? $providerId : null);
if (!$vod && $providerId === '') $vod = openapAptvItalianVod($id);
$context = openapAptvProviderContextForRequest($providerId !== '' ? $providerId : null);
$profile = is_array($context) ? (array) ($context['profile'] ?? []) : null;
if (!$directory || !is_dir($directory) || !$vod || !$profile) exit(65);
$password = (string) ($profile['password'] ?? '');
$extension = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($vod['container_extension'] ?? 'mkv')));
$upstream = rtrim((string) ($profile['url'] ?? ''), '/') . '/' . openapAptvVodSourcePath($vod) . '/' . rawurlencode((string) ($profile['username'] ?? '')) . '/' . rawurlencode($password) . '/' . $id . '.' . $extension;
$cuesSidecar = openapAptvCuesSidecar($id, $profile, $vod);
$server = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
if (!is_resource($server)) exit(66);
$address = stream_socket_get_name($server, false);
$port = (int) substr(strrchr((string) $address, ':'), 1);
if ($port < 1 || file_put_contents($directory . '/source.port', (string) $port, LOCK_EX) === false) exit(67);
chmod($directory . '/source.port', 0600);
$children = [];
$stopping = false;
$reapChildren = false;
$maximumChildren = 8;
pcntl_async_signals(true);
pcntl_signal(SIGCHLD, static function () use (&$reapChildren): void { $reapChildren = true; });
pcntl_signal(SIGTERM, static function () use (&$stopping): void { $stopping = true; });
pcntl_signal(SIGINT, static function () use (&$stopping): void { $stopping = true; });

$reap = static function () use (&$children, &$reapChildren): void {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) unset($children[$pid]);
    $reapChildren = false;
};
$serve = static function ($client) use ($upstream, $directory, $cuesSidecar): void {
    stream_set_timeout($client, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && strlen($request) < 16384 && !feof($client)) $request .= (string) fgets($client, 4096);
    $range = '0-';
    if (preg_match('/\r\nRange:\s*bytes=(\d*-\d*)\r\n/i', $request, $match)) $range = $match[1];
    $status = 502; $responseHeaders = []; $headersWritten = false;
    $requestedStart = 0; $requestedEnd = null;
    if (preg_match('/^(\d+)-(\d*)$/', $range, $rangeMatch)) {
        $requestedStart = (int) $rangeMatch[1];
        $requestedEnd = $rangeMatch[2] === '' ? null : (int) $rangeMatch[2];
    }
    $requestStartedAt = microtime(true);
    $attemptRange = '';
    $attemptStartedAt = $requestStartedAt;
    $firstByteAt = 0.0;
    $lastByteAt = 0.0;
    $lastHeartbeatAt = 0.0;
    $nextOffset = $requestedStart;
    $totalSize = null;
    $attemptNumber = 0;
    $received = 0;
    $status = 502;
    $providerContentType = '';
    $curlErrno = 0;
    $curlError = '';
    $errorClass = '';
    $heartbeat = static function (string $state, array $extra = []) use (&$attemptRange, &$attemptStartedAt, &$firstByteAt, &$lastByteAt, &$nextOffset, &$totalSize, &$attemptNumber, &$received, &$status, &$providerContentType, &$curlErrno, &$curlError, &$errorClass, $range, $requestedStart, $requestedEnd, $requestStartedAt, $directory): void {
        $now = microtime(true);
        $payload = [
            'state' => $state,
            'updated_at' => $now,
            'request_started_at' => $requestStartedAt,
            'request_elapsed_seconds' => max(0.0, $now - $requestStartedAt),
            'range_requested' => $range,
            'range_start' => $requestedStart,
            'range_end' => $requestedEnd,
            'range_attempt' => $attemptNumber,
            'range' => $attemptRange,
            'attempt_started_at' => $attemptStartedAt,
            'next_offset' => $nextOffset,
            'total_size_reported' => $totalSize,
            'http_status' => $status,
            'content_type' => $providerContentType,
            'bytes_received' => $received,
            'bytes' => $received,
            'bytes_per_second' => $received > 0 && $attemptStartedAt > 0 ? round($received / max(0.001, $now - $attemptStartedAt), 1) : 0,
            'first_byte_at' => $firstByteAt ?: null,
            'last_byte_at' => $lastByteAt ?: null,
            'gap_seconds' => $lastByteAt > 0 ? max(0.0, $now - $lastByteAt) : max(0.0, $now - $attemptStartedAt),
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'error_class' => $errorClass,
            'pid' => getmypid(),
        ];
        foreach ($extra as $key => $value) $payload[$key] = $value;
        $temporary = $directory . '/provider-status.json.new.' . getmypid();
        if (@file_put_contents($temporary, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $directory . '/provider-status.json');
        }
    };
    $heartbeat('requesting');
    if (is_array($cuesSidecar) && $requestedStart === (int) $cuesSidecar['offset']) {
        $sidecarLength = (int) $cuesSidecar['length'];
        $sidecarBytes = @file_get_contents((string) $cuesSidecar['path']);
        if (is_string($sidecarBytes) && strlen($sidecarBytes) >= $sidecarLength) {
            $sidecarBytes = substr($sidecarBytes, 0, $sidecarLength);
            $sidecarEnd = $requestedStart + $sidecarLength - 1;
            $headers = [
                'HTTP/1.1 206 Partial Content',
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $sidecarLength,
                'Content-Range: bytes ' . $requestedStart . '-' . $sidecarEnd . '/' . (int) $cuesSidecar['total'],
                'Accept-Ranges: bytes',
                'Cache-Control: no-store',
                'Connection: close',
            ];
            fwrite($client, implode("\r\n", $headers) . "\r\n\r\n");
            fwrite($client, $sidecarBytes);
            fclose($client);
            return;
        }
    }
    $attempts = 0; $emptyAttempts = 0; $headersSent = false;
    $rangeChunkBytes = 32 * 1024 * 1024;
    while ($attempts < 512) {
        $attemptEnd = $requestedEnd === null ? $nextOffset + $rangeChunkBytes - 1 : min($requestedEnd, $nextOffset + $rangeChunkBytes - 1);
        $attemptRange = (string) $nextOffset . '-' . (string) $attemptEnd;
        $attemptNumber = $attempts + 1; $attemptStartedAt = microtime(true); $firstByteAt = 0.0; $lastByteAt = 0.0; $received = 0; $curlErrno = 0; $curlError = ''; $errorClass = '';
        $status = 502; $responseHeaders = []; $headersWritten = false; $writeFailed = false; $providerContentType = '';
        $heartbeat('requesting');
        $curl = openapAptvCurl($upstream);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_TIMEOUT => 0,
            CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_BUFFERSIZE => 1048576, CURLOPT_TCP_NODELAY => true,
            CURLOPT_RANGE => $attemptRange,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$responseHeaders, &$headersWritten, &$headersSent, &$totalSize, &$providerContentType, $requestedStart, $requestedEnd, $client, $heartbeat): int {
                $trimmed = trim($line);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $match)) {
                    $status = (int) $match[1]; $responseHeaders = []; $headersWritten = false;
                } elseif (preg_match('/^(Content-Type|Content-Length|Content-Range|Last-Modified|ETag):\s*(.+)$/i', $trimmed, $match)) {
                    $name = strtolower($match[1]); $responseHeaders[$name] = $match[2];
                    if ($name === 'content-type') $providerContentType = trim($match[2]);
                    if ($name === 'content-range' && preg_match('/^bytes\s+\d+-\d+\/(\d+)$/i', trim($match[2]), $rangeInfo)) $totalSize = (int) $rangeInfo[1];
                } elseif ($trimmed === '' && !$headersWritten && $status >= 200 && $status < 300) {
                    if (!$headersSent) {
                        $clientStatus = $status;
                        if ($totalSize !== null) {
                            $responseEnd = $requestedEnd === null ? $totalSize - 1 : min($requestedEnd, $totalSize - 1);
                            $responseHeaders['content-length'] = (string) max(0, $responseEnd - $requestedStart + 1);
                            if ($requestedStart === 0 && $requestedEnd === null) {
                                $clientStatus = 200;
                                unset($responseHeaders['content-range']);
                            } else {
                                $clientStatus = 206;
                                $responseHeaders['content-range'] = 'bytes ' . $requestedStart . '-' . $responseEnd . '/' . $totalSize;
                            }
                        }
                        $lines = ['HTTP/1.1 ' . $clientStatus . ($clientStatus === 206 ? ' Partial Content' : ' OK'), 'Accept-Ranges: bytes', 'Cache-Control: no-store', 'Connection: close'];
                        foreach ($responseHeaders as $name => $value) $lines[] = ucwords($name, '-') . ': ' . $value;
                        fwrite($client, implode("\r\n", $lines) . "\r\n\r\n"); $headersSent = true;
                    }
                    $heartbeat('receiving');
                    $headersWritten = true;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($client, &$received, &$writeFailed, &$firstByteAt, &$lastByteAt, &$lastHeartbeatAt, $heartbeat): int {
                $total = 0; $length = strlen($chunk);
                while ($total < $length) {
                    $written = @fwrite($client, $total === 0 ? $chunk : substr($chunk, $total));
                    if ($written === false || $written === 0) { $writeFailed = true; return 0; }
                    $total += $written;
                }
                $received += $total;
                $now = microtime(true);
                if ($firstByteAt <= 0) $firstByteAt = $now;
                $lastByteAt = $now;
                if ($lastHeartbeatAt <= 0 || $now - $lastHeartbeatAt >= 1.0) { $heartbeat('receiving'); $lastHeartbeatAt = $now; }
                return $total;
            },
        ]);
        curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = (string) curl_error($curl);
        $curlInfo = curl_getinfo($curl);
        $providerStatus = (int) ($curlInfo['http_code'] ?? $status);
        $errorClass = $curlErrno === 28 ? 'timeout' : ($curlErrno === 6 ? 'dns' : ($curlErrno === 7 ? 'connection' : ($curlErrno === 18 ? 'partial' : ($curlErrno === 23 ? 'write' : ($curlErrno === 52 ? 'empty_reply' : ($curlErrno > 0 ? 'transport' : ''))))));
        $status = $providerStatus;
        $terminalState = $writeFailed ? 'failed' : ($curlErrno > 0 ? ($curlErrno === 28 ? 'stalled' : 'failed') : ($providerStatus === 416 ? 'eof' : ($received > 0 ? 'range_complete' : 'empty')));
        $heartbeat($terminalState, ['curl_info_total_time_seconds' => (float) ($curlInfo['total_time'] ?? 0), 'curl_error' => $curlError]);
        curl_close($curl);
        if ($writeFailed) break;
        if ($received <= 0) {
            if ($status !== 206 || $emptyAttempts >= 6) { $heartbeat($status === 416 ? 'eof' : 'failed'); break; }
            $emptyAttempts++; $attempts++; usleep(250000); continue;
        }
        $nextOffset += $received; $emptyAttempts = 0;
        if (($requestedEnd !== null && $nextOffset > $requestedEnd) || ($totalSize !== null && $nextOffset >= $totalSize)) { $heartbeat('completed'); break; }
        if ($status !== 206) { $heartbeat('completed'); break; }
        $attempts++;
        usleep(100000);
    }
    fclose($client);
};

while (!$stopping && !is_file($directory . '/stop')) {
    if ($reapChildren) $reap();
    if (count($children) >= $maximumChildren) { usleep(20000); continue; }
    $client = @stream_socket_accept($server, 1);
    if (!is_resource($client)) continue;
    $child = pcntl_fork();
    if ($child < 0) { fclose($client); continue; }
    if ($child > 0) { $children[$child] = true; fclose($client); continue; }
    fclose($server);
    pcntl_signal(SIGCHLD, SIG_DFL); pcntl_signal(SIGTERM, SIG_DFL); pcntl_signal(SIGINT, SIG_DFL);
    $serve($client);
    sodium_memzero($upstream); sodium_memzero($password);
    exit(0);
}
fclose($server);
foreach (array_keys($children) as $pid) @posix_kill($pid, SIGTERM);
$deadline = microtime(true) + 2;
while ($children && microtime(true) < $deadline) { $reap(); if ($children) usleep(20000); }
foreach (array_keys($children) as $pid) @posix_kill($pid, SIGKILL);
while ($children) { $pid = pcntl_waitpid(-1, $status); if ($pid > 0) unset($children[$pid]); else break; }
sodium_memzero($upstream); sodium_memzero($password);
@unlink($directory . '/source.port');
