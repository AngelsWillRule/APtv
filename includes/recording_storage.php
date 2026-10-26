<?php

function openapRecordingRoots(): array
{
    return ['/var/lib/openap-recordings', '/mnt', '/media'];
}

function openapRecordingPath(string $path): string
{
    if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")) {
        throw new InvalidArgumentException(_('Invalid folder path.'));
    }
    $real = realpath($path);
    foreach (openapRecordingRoots() as $root) {
        // Do not follow a replacement of a trusted root with a symlink.
        if ($real !== false && realpath($root) === $root && ($real === $root || str_starts_with($real, $root . '/')) && is_dir($real)) return $real;
    }
    throw new InvalidArgumentException(_('Choose a folder inside the available storage locations.'));
}

function openapRecordingFilePath(string $path): string
{
    $real = realpath($path);
    if ($real === false || !is_file($real) || str_ends_with(strtolower($real), '.json')) {
        throw new InvalidArgumentException(_('Invalid recording file.'));
    }
    foreach (openapRecordingRoots() as $root) {
        if (realpath($root) === $root && str_starts_with($real, $root . '/')) return $real;
    }
    throw new InvalidArgumentException(_('Invalid recording file.'));
}

function openapRecordingFileMime(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        default => 'video/mp2t',
    };
}

function openapRecordingDeleteDirectory(string $path): void
{
    $directory = openapRecordingPath($path);
    if (in_array($directory, openapRecordingRoots(), true)) throw new InvalidArgumentException(_('The storage root cannot be deleted.'));
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            if (!@unlink($entryPath)) throw new RuntimeException(_('Unable to delete a file inside this folder.'));
        } elseif ($entry->isDir() && !@rmdir($entryPath)) {
            throw new RuntimeException(_('Unable to delete a subfolder inside this folder.'));
        }
    }
    if (!@rmdir($directory)) throw new RuntimeException(_('Unable to delete this folder.'));
}

function openapRecordingStorage(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405); header('Allow: GET, POST'); echo json_encode(['ok'=>false,'message'=>_('Method not allowed.')]); return;
    }
    if ($method === 'POST' && !\OpenAP\Tokens\CSRF::verify()) {
        http_response_code(403); echo json_encode(['ok'=>false,'message'=>_('Invalid security token. Reload the page.')]); return;
    }
    try {
        $input = $method === 'POST' ? $_POST : $_GET;
        $path = $input['path'] ?? '';
        if (!is_string($path)) throw new InvalidArgumentException(_('Invalid folder path.'));
        if ($method === 'GET' && $path === '') {
            $items = [];
            foreach (openapRecordingRoots() as $root) {
                if (is_dir($root) && realpath($root) === $root) $items[] = ['name'=>$root,'path'=>$root];
            }
            echo json_encode(['ok'=>true,'path'=>'','parent'=>null,'items'=>$items,'writable'=>false,'free_bytes'=>null]); return;
        }
        if ($method === 'GET' && ($input['action'] ?? '') === 'thumbnail') {
            $filePath = openapRecordingFilePath($path);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['ts', 'mp4', 'mkv', 'webm', 'mov', 'm4v', 'avi'], true)) {
                http_response_code(404); return;
            }
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-ss', '0', '-i', $filePath, '-frames:v', '1', '-vf', 'scale=320:-2', '-f', 'mjpeg', 'pipe:1'], $descriptors, $pipes);
            if (!is_resource($process)) { http_response_code(404); return; }
            $thumbnail = stream_get_contents($pipes[1]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0 || $thumbnail === '') { http_response_code(404); return; }
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=3600');
            echo $thumbnail;
            return;
        }
        if ($method === 'GET' && ($input['action'] ?? '') === 'stream') {
            $filePath = openapRecordingFilePath($path);
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (($input['repair'] ?? '') === '1' && $extension === 'ts') {
                $start = max(0, (float)($input['start'] ?? 0));
                $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $command = ['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-fflags', '+genpts', '-err_detect', 'ignore_err'];
                if ($start > 0) {
                    $command[] = '-ss';
                    $command[] = number_format($start, 3, '.', '');
                }
                $command = array_merge($command, ['-i', $filePath, '-map', '0:v:0?', '-map', '0:a:0?', '-c', 'copy', '-avoid_negative_ts', 'make_zero', '-f', 'mpegts', 'pipe:1']);
                $process = @proc_open($command, $descriptors, $pipes);
                if (!is_resource($process)) { http_response_code(404); return; }
                header('Content-Type: video/mp2t');
                header('Cache-Control: private, no-store');
                while (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 1048576);
                    if ($chunk === false || $chunk === '') break;
                    echo $chunk;
                    if (function_exists('flush')) flush();
                }
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                return;
            }
            if (($input['repair'] ?? '') === 'mp4' && $extension === 'ts') {
                $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                $process = @proc_open(['/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin', '-fflags', '+genpts', '-err_detect', 'ignore_err', '-i', $filePath, '-map', '0:v:0?', '-map', '0:a:0?', '-c', 'copy', '-avoid_negative_ts', 'make_zero', '-movflags', 'frag_keyframe+empty_moov+default_base_moof', '-f', 'mp4', 'pipe:1'], $descriptors, $pipes);
                if (!is_resource($process)) { http_response_code(404); return; }
                header('Content-Type: video/mp4');
                header('Cache-Control: private, no-store');
                while (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 1048576);
                    if ($chunk === false || $chunk === '') break;
                    echo $chunk;
                    if (function_exists('flush')) flush();
                }
                fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
                return;
            }
            $size = filesize($filePath);
            $start = 0; $end = $size - 1; $status = 200;
            $range = $_SERVER['HTTP_RANGE'] ?? '';
            if (preg_match('/bytes=(\d*)-(\d*)/', $range, $match)) {
                if ($match[1] === '' && $match[2] === '') { http_response_code(416); return; }
                if ($match[1] === '') { $start = max(0, $size - (int)$match[2]); }
                else { $start = (int)$match[1]; }
                if ($match[2] !== '') { $end = min($size - 1, (int)$match[2]); }
                if ($start > $end || $start >= $size) { http_response_code(416); return; }
                $status = 206;
            }
            http_response_code($status);
            header('Content-Type: '.openapRecordingFileMime($filePath));
            header('Accept-Ranges: bytes');
            header('Content-Length: '.($end - $start + 1));
            if ($status === 206) header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
            $handle = fopen($filePath, 'rb');
            fseek($handle, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(1048576, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk; $remaining -= strlen($chunk);
                if (function_exists('flush')) flush();
            }
            fclose($handle);
            return;
        }
        if ($method === 'GET' && ($input['action'] ?? '') === 'probe') {
            $filePath = openapRecordingFilePath($path);
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open(['/usr/bin/ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $filePath], $descriptors, $pipes);
            if (!is_resource($process)) throw new RuntimeException('[REC_PROBE] FFprobe unavailable.');
            $output = trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]); fclose($pipes[2]);
            $exitCode = proc_close($process);
            $duration = (float)$output;
            if ($exitCode !== 0 || !is_finite($duration) || $duration <= 0) throw new RuntimeException('[REC_PROBE] Recording duration unavailable.');
            echo json_encode(['ok'=>true,'duration'=>$duration]);
            return;
        }
        if ($method === 'POST' && ($input['action'] ?? '') === 'delete') {
            $filePath = openapRecordingFilePath($path);
            if (!@unlink($filePath)) throw new RuntimeException(_('Unable to delete this recording file.'));
            $sidecar = $filePath.'.json';
            if (is_file($sidecar)) @unlink($sidecar);
            echo json_encode(['ok'=>true]);
            return;
        }
        if ($method === 'POST' && ($input['action'] ?? '') === 'delete_folder') {
            openapRecordingDeleteDirectory($path);
            echo json_encode(['ok'=>true]);
            return;
        }
        $path = openapRecordingPath($path);
        if ($method === 'GET' && ($input['action'] ?? '') === 'files') {
            if (!is_readable($path) || !is_executable($path)) throw new RuntimeException(_('APtv cannot browse this folder.'));
            $files = []; $folders = []; $truncated = false;
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                if ($entry->isLink() || str_starts_with($entry->getFilename(), '.')) continue;
                if (count($files) + count($folders) >= 500) { $truncated = true; break; }
                $entryPath = $entry->getPathname();
                if ($entry->isDir()) {
                    try { $entryPath = openapRecordingPath($entryPath); } catch (InvalidArgumentException $error) { continue; }
                    $folders[] = ['name' => $entry->getFilename(), 'path' => $entryPath];
                } elseif ($entry->isFile() && !str_ends_with(strtolower($entry->getFilename()), '.json')) {
                    if (str_ends_with(strtolower($entry->getFilename()), '.ts') && is_file(substr($entryPath, 0, -3).'mp4')) continue;
                    $files[] = [
                        'name' => $entry->getFilename(),
                        'path' => $entryPath,
                        'bytes' => $entry->getSize(),
                        'modified' => $entry->getMTime(),
                        'preview' => in_array(strtolower(pathinfo($entry->getFilename(), PATHINFO_EXTENSION)), ['ts', 'mp4', 'mkv', 'webm', 'mov', 'm4v', 'avi'], true),
                    ];
                }
            }
            usort($folders, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            usort($files, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            echo json_encode([
                'ok' => true,
                'path' => $path,
                'parent' => in_array($path, openapRecordingRoots(), true) ? '' : dirname($path),
                'folders' => $folders,
                'files' => $files,
                'truncated' => $truncated,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }
        if ($method === 'POST') {
            $action = $input['action'] ?? '';
            if ($action === 'mkdir') {
                $name = $input['name'] ?? '';
                if (!is_string($name) || $name === '' || $name === '.' || $name === '..' || strlen($name) > 255 || preg_match('~[/\\\\\x00-\x1f\x7f]~', $name) || trim($name) !== $name) throw new InvalidArgumentException(_('Enter a valid folder name without slashes.'));
                if (!@mkdir($path . '/' . $name, 0770)) throw new RuntimeException(_('Cannot create this folder. Check permissions or choose another name.'));
                $path = openapRecordingPath($path . '/' . $name);
            } elseif ($action === 'check') {
                $probe = $path . '/.aptv-write-check-' . bin2hex(random_bytes(12));
                $handle = @fopen($probe, 'x');
                if ($handle === false) throw new RuntimeException(_('APtv cannot write to this folder.'));
                try {
                    $written = fwrite($handle, 'APtv');
                } finally {
                    fclose($handle);
                    $removed = @unlink($probe);
                }
                if ($written !== 4 || !$removed) throw new RuntimeException(_('The folder did not pass the write check.'));
                echo json_encode(['ok'=>true,'path'=>$path,'writable'=>true,'free_bytes'=>@disk_free_space($path)]); return;
            } else throw new InvalidArgumentException(_('Invalid storage action.'));
        }
        if (!is_readable($path) || !is_executable($path)) throw new RuntimeException(_('APtv cannot browse this folder.'));
        $items = []; $truncated = false;
        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            if (str_starts_with($entry->getFilename(), '.') || !$entry->isDir()) continue;
            try { $child = openapRecordingPath($entry->getPathname()); } catch (InvalidArgumentException $error) { continue; }
            if (count($items) >= 500) { $truncated = true; break; }
            $items[] = ['name'=>$entry->getFilename(),'path'=>$child];
        }
        usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        echo json_encode(['ok'=>true,'path'=>$path,'parent'=>in_array($path,openapRecordingRoots(),true)?'':dirname($path),'items'=>$items,'truncated'=>$truncated,'writable'=>is_writable($path),'free_bytes'=>@disk_free_space($path)], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (InvalidArgumentException $error) {
        http_response_code(422); echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);
    } catch (Throwable $error) {
        http_response_code(403); echo json_encode(['ok'=>false,'message'=>$error instanceof RuntimeException && !$error instanceof UnexpectedValueException ? $error->getMessage() : _('Unable to access this folder.')]);
    }
}
