<?php

function openapAptvStreamUrl(string $path): string
{
    return '/' . ltrim($path, '/');
}

function openapAptvProviderProxy(): ?string
{
    $proxy = trim((string) @file_get_contents('/etc/aptv-iptv/provider-proxy'));
    return preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{1,5}$~', $proxy) ? $proxy : null;
}

function openapAptvCurl(string $url = '')
{
    $handle = curl_init($url);
    if ($handle !== false) {
        curl_setopt($handle, CURLOPT_USERAGENT, 'APtv-VOD/0.1');
        $proxy = openapAptvProviderProxy();
        if ($proxy !== null) curl_setopt($handle, CURLOPT_PROXY, $proxy);
    }
    return $handle;
}

function openapAptvProviderProfile(): ?array
{
    $directory = '/var/lib/openap-iptv';
    $key = @file_get_contents($directory . '/provider.key');
    $sealed = @json_decode((string) @file_get_contents($directory . '/provider.enc'), true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !is_array($sealed)) return null;
    $nonce = base64_decode((string) ($sealed['nonce'] ?? ''), true);
    $ciphertext = base64_decode((string) ($sealed['ciphertext'] ?? ''), true);
    if (!is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !is_string($ciphertext)) return null;
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    if (!is_string($plain)) return null;
    $profile = json_decode($plain, true);
    sodium_memzero($plain);
    return is_array($profile) ? $profile : null;
}

function openapAptvProviderProfileById(string $providerId): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) return null;
    foreach ((openapAptvProviderCollection()['profiles'] ?? []) as $profile) {
        if (is_array($profile) && hash_equals($providerId, (string) ($profile['id'] ?? ''))) return $profile;
    }
    return null;
}

function openapAptvProviderCatalogPath(?string $providerId = null): string
{
    if ($providerId !== null && preg_match('/^[a-f0-9]{32}$/', $providerId)) {
        return '/var/lib/openap-iptv/providers/' . $providerId . '/catalog.db';
    }
    return '/var/lib/openap-iptv/catalog.db';
}

function openapAptvProviderContext(?string $requestedProviderId = null): ?array
{
    $providerId = trim((string) $requestedProviderId);
    if ($providerId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) return null;
        $profile = openapAptvProviderProfileById($providerId);
    } else {
        $collection = openapAptvProviderCollection();
        $providerId = (string) ($collection['active_id'] ?? '');
        $profile = openapAptvProviderProfile();
        if (!$profile && preg_match('/^[a-f0-9]{32}$/', $providerId)) {
            $profile = openapAptvProviderProfileById($providerId);
        }
    }
    if (!is_array($profile) || !preg_match('/^[a-f0-9]{32}$/', $providerId)) return null;
    return [
        'id' => $providerId,
        'profile' => $profile,
        'catalog' => openapAptvProviderCatalogPath($providerId),
    ];
}

function openapAptvRequestedProviderId(): ?string
{
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    return $providerId !== '' ? $providerId : null;
}

function openapAptvProviderContextForRequest(?string $providerId = null): ?array
{
    return openapAptvProviderContext($providerId ?? openapAptvRequestedProviderId());
}

function openapAptvPosterCachePath(string $directory, string $providerId, string $itemId, string $sourceUrl): string
{
    return $directory . '/' . hash('sha256', $providerId . "\0" . $itemId . "\0" . $sourceUrl) . '.img';
}

function openapAptvRemoveProviderCacheFile(string $path, array &$stats): void
{
    if (!is_file($path)) return;
    $size = max(0, (int) @filesize($path));
    if (@unlink($path)) {
        $stats['files']++;
        $stats['bytes'] += $size;
    } else {
        $stats['errors']++;
    }
}

function openapAptvClearProviderCaches(string $providerId, array $profile, bool $planOnly = false, array &$plannedPaths = []): array
{
    $stats = ['files' => 0, 'bytes' => 0, 'errors' => 0, 'skipped' => false];
    $catalog = openapAptvProviderCatalogPath($providerId);

    $providerKey = (string) ($profile['url'] ?? '') . "\0" . (string) ($profile['username'] ?? '');
    $removePath = static function (string $path) use (&$stats, $planOnly, &$plannedPaths): void {
        if ($planOnly) {
            if (is_file($path)) $plannedPaths[$path] = true;
            return;
        }
        openapAptvRemoveProviderCacheFile($path, $stats);
    };
    $remove = static function (string $directory, string $key, string $suffix) use ($removePath): void {
        $removePath($directory . '/' . hash('sha256', $key) . $suffix);
    };

    // These files carry the provider id in their name and can therefore be
    // removed even when the provider catalog is already unavailable.
    foreach (glob('/var/cache/openap-iptv/epg/*-' . $providerId . '.json') ?: [] as $path) {
        $removePath($path);
    }
    foreach (glob('/var/cache/openap-iptv/epg/*-' . $providerId . '.xml.new.*') ?: [] as $path) {
        $removePath($path);
    }

    $removeProbe = static function (string $kind, string $itemId) use ($providerKey, $remove): void {
        $remove('/var/cache/openap-iptv/vod-probes', $providerKey . "\0" . $kind . "\0" . $itemId, '.json');
    };
    $removeCue = static function (string $itemId, string $extension, string $sourceKind, string $seriesId) use ($profile, $remove): void {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'mkv';
        $key = implode("\0", [
            (string) ($profile['id'] ?? ''), (string) ($profile['url'] ?? ''),
            (string) ($profile['username'] ?? ''), $itemId, $extension, $sourceKind, $seriesId,
        ]);
        $remove('/var/cache/openap-iptv/vod-cues', $key, '.json');
        $remove('/var/cache/openap-iptv/vod-cues', $key, '.bin');
    };

    if (is_readable($catalog)) try {
        $db = new SQLite3($catalog, SQLITE3_OPEN_READONLY);
        $query = $db->query("SELECT kind,item_id,icon_url,container_extension FROM items WHERE kind IN ('live','vod','series')");
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $kind = (string) ($row['kind'] ?? '');
            $itemId = (string) ($row['item_id'] ?? '');
            if (!preg_match('/^[0-9]{1,20}$/', $itemId)) continue;

            if ($kind === 'live') {
                $marker = '/var/lib/openap-recording-state/iptv-profile-' . hash('sha256', $providerId . "\0" . $itemId) . '.normalized';
                $removePath($marker);
                continue;
            }

            $iconUrl = (string) ($row['icon_url'] ?? '');
            if ($kind === 'series') {
                $remove('/var/cache/openap-iptv/series-posters', $providerId . "\0" . $itemId . "\0" . $iconUrl, '.img');
            } else {
                $remove('/var/cache/openap-iptv/posters', $providerId . "\0" . $itemId . "\0" . $iconUrl, '.img');
            }

            $extension = (string) ($row['container_extension'] ?? 'mkv');
            $removeProbe($kind, $itemId);
            if ($kind === 'vod') {
                $removeCue($itemId, $extension, 'movie', '');
            }

            $details = $kind === 'series' ? '/var/cache/openap-iptv/series-details' : '/var/cache/openap-iptv/vod-details';
            $detailsPath = $details . '/' . hash('sha256', $providerKey . "\0" . $itemId) . '.json';
            $detailsPayload = is_file($detailsPath) ? json_decode((string) @file_get_contents($detailsPath), true) : null;
            if ($kind === 'series' && is_array($detailsPayload)) {
                foreach (($detailsPayload['episodes'] ?? []) as $seasonEpisodes) {
                    if (!is_array($seasonEpisodes)) continue;
                    foreach ($seasonEpisodes as $episode) {
                        if (!is_array($episode)) continue;
                        $episodeId = (string) ($episode['item_id'] ?? '');
                        if (!preg_match('/^[0-9]{1,20}$/', $episodeId)) continue;
                        $registryPath = '/var/cache/openap-iptv/series-episodes/' . hash('sha256', $providerKey . "\0" . $episodeId) . '.json';
                        $registry = is_file($registryPath) ? json_decode((string) @file_get_contents($registryPath), true) : null;
                        $previewSource = is_array($registry) ? (string) ($registry['preview_source'] ?? '') : '';
                        if ($previewSource !== '') {
                            $remove('/var/cache/openap-iptv/episode-images', $providerId . "\0" . $episodeId . "\0" . $previewSource, '.img');
                        }
                        $removeProbe('series', $episodeId);
                        $removeCue($episodeId, (string) ($episode['container_extension'] ?? 'mkv'), 'series', $itemId);
                        $removePath($registryPath);
                    }
                }
            }
            $removePath($detailsPath);
        }
        $db->close();
    } catch (Throwable $error) {
        $stats['errors']++;
    }
    return $stats;
}


function openapAptvProviderCollection(): array
{
    $directory = '/var/lib/openap-iptv';
    $key = @file_get_contents($directory . '/provider.key');
    $sealed = @json_decode((string) @file_get_contents($directory . '/providers.enc'), true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !is_array($sealed)) return ['active_id' => '', 'profiles' => []];
    $nonce = base64_decode((string) ($sealed['nonce'] ?? ''), true);
    $ciphertext = base64_decode((string) ($sealed['ciphertext'] ?? ''), true);
    if (!is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !is_string($ciphertext)) return ['active_id' => '', 'profiles' => []];
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    if (!is_string($plain)) return ['active_id' => '', 'profiles' => []];
    $collection = json_decode($plain, true); sodium_memzero($plain);
    return is_array($collection) ? $collection : ['active_id' => '', 'profiles' => []];
}

function openapAptvListProviders(): void
{
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    $collection = openapAptvProviderCollection(); $items = [];
    foreach (($collection['profiles'] ?? []) as $profile) {
        if (!is_array($profile)) continue;
        $items[] = ['id'=>(string)($profile['id']??''),'name'=>(string)($profile['name']??''),'url'=>(string)($profile['url']??''),'username'=>(string)($profile['username']??''),'api_version'=>(string)($profile['api_version']??'v2'),'max_connections'=>max(1,(int)($profile['max_connections']??$profile['max_conn']??1)),'active'=>hash_equals((string)($collection['active_id']??''),(string)($profile['id']??''))];
    }
    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_SLASHES);
}

function openapAptvSwitchProvider(): void
{
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !\OpenAP\Tokens\CSRF::verify()) {
        http_response_code(403); echo json_encode(['ok'=>false,'message'=>_('Invalid security token. Reload the page.')]); return;
    }
    $providerId = trim((string) ($_POST['provider_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]); return; }
    openap_release_session();
    $process = proc_open(['sudo','-n','/usr/local/sbin/openap-iptv-index','--stdin'], [['pipe','r'],['pipe','w'],['file','/dev/null','a']], $pipes);
    if (!is_resource($process)) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>_('Unable to switch the provider.')]); return; }
    fwrite($pipes[0], json_encode(['operation'=>'switch_provider','provider_id'=>$providerId], JSON_UNESCAPED_SLASHES)); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $exitCode = proc_close($process);
    $payload = json_decode((string) $output, true);
    if ($exitCode !== 0 || !is_array($payload) || !($payload['ok'] ?? false)) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>_('Unable to switch the provider.')]); return; }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function openapAptvDeleteProvider(): void
{
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !\OpenAP\Tokens\CSRF::verify()) {
        http_response_code(403); echo json_encode(['ok'=>false,'message'=>_('Invalid security token. Reload the page.')]); return;
    }
    $providerId = trim((string) ($_POST['provider_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]); return; }
    openap_release_session();
    $profile = openapAptvProviderProfileById($providerId);
    $plannedCachePaths = [];
    $cacheCleanup = is_array($profile)
        ? openapAptvClearProviderCaches($providerId, $profile, true, $plannedCachePaths)
        : ['files' => 0, 'bytes' => 0, 'errors' => 0, 'skipped' => true];
    $process = proc_open(['sudo','-n','/usr/local/sbin/openap-iptv-index','--stdin'], [['pipe','r'],['pipe','w'],['file','/dev/null','a']], $pipes);
    if (!is_resource($process)) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>_('Unable to remove the provider profile.')]); return; }
    fwrite($pipes[0], json_encode(['operation'=>'delete_provider','provider_id'=>$providerId], JSON_UNESCAPED_SLASHES)); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $exitCode = proc_close($process);
    $payload = json_decode((string)$output, true);
    if ($exitCode !== 0 || !is_array($payload) || !($payload['ok'] ?? false)) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>_('Unable to remove the provider profile.')]); return; }
    if (!$cacheCleanup['skipped']) {
        $cacheCleanup = ['files' => 0, 'bytes' => 0, 'errors' => 0, 'skipped' => false];
        foreach (array_keys($plannedCachePaths) as $cachePath) {
            openapAptvRemoveProviderCacheFile($cachePath, $cacheCleanup);
        }
    }
    $payload['cache_cleanup'] = $cacheCleanup;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function openapAptvLiveChannel(string $itemId, ?string $providerId = null): bool
{
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) return false;
    try {
        $db = new SQLite3(openapAptvProviderCatalogPath($providerId), SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT 1 FROM items WHERE kind='live' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $valid = (bool) $statement->execute()->fetchArray(SQLITE3_NUM);
        $db->close();
        return $valid;
    } catch (Throwable $error) { return false; }
}

function openapAptvLiveProfileMarker(string $itemId, ?string $providerId = null): string
{
    $provider = $providerId;
    if ($provider === null) {
        $collection = openapAptvProviderCollection();
        $provider = (string) ($collection['active_id'] ?? '');
    }
    $key = $provider !== '' ? $provider . "\0" . $itemId : $itemId;
    return '/var/lib/openap-recording-state/iptv-profile-' . hash('sha256', $key) . '.normalized';
}

function openapAptvMarkLiveNormalized(string $itemId, ?string $providerId = null): void
{
    $marker = openapAptvLiveProfileMarker($itemId, $providerId);
    $temporary = $marker . '.new.' . getmypid() . '.' . bin2hex(random_bytes(6));
    if (@file_put_contents($temporary, "normalized\n", LOCK_EX) !== false) {
        @chmod($temporary, 0660);
        if (!@rename($temporary, $marker)) @unlink($temporary);
    }
}

function openapAptvLiveCompatibilityProfile(string $itemId, ?string $providerId = null): string
{
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) return 'copy';
    try {
        $db = new SQLite3(openapAptvProviderCatalogPath($providerId), SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT c.name FROM items i LEFT JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id WHERE i.kind='live' AND i.item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        $marker = openapAptvLiveProfileMarker($itemId, $providerId);
        if (is_file($marker)) return 'normalized';
        return 'copy';
    } catch (Throwable $error) { return 'copy'; }
}

function openapAptvPruneStaleHls(): void
{
    foreach (glob('/var/lib/openap-recording-state/live-*-hls', GLOB_ONLYDIR) ?: [] as $directory) {
        $heartbeat = $directory . '/heartbeat';
        if (is_file($heartbeat) && time() - (int) @filemtime($heartbeat) <= 600) continue;
        if (!is_file($heartbeat) && time() - (int) @filemtime($directory) <= 600) continue;
        $lock = @fopen($directory . '/lock', 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if (is_resource($lock)) fclose($lock); continue; }
        foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file) || is_link($file)) @unlink($file);
        @flock($lock, LOCK_UN); fclose($lock); @rmdir($directory);
    }
}

function openapAptvDecodeEpgText(mixed $value): string
{
    $text=trim((string)$value);if($text==='')return '';
    $decoded=base64_decode($text,true);
    return is_string($decoded)&&$decoded!==''&&mb_check_encoding($decoded,'UTF-8')&&base64_encode($decoded)===$text?trim($decoded):(mb_check_encoding($text,'UTF-8')?$text:'');
}

function openapAptvNormalizeEpgPrograms(array $programs): array
{
    $unique=[];foreach($programs as$program){$key=$program['start']."\0".$program['end']."\0".mb_strtolower(trim((string)$program['title']));$unique[$key]=$program;}
    $candidates=array_values($unique);usort($candidates,static fn(array $a,array $b):int=>($a['end']<=>$b['end'])?:($a['start']<=>$b['start']));
    $selected=[];$lastEnd=PHP_INT_MIN;foreach($candidates as$program){if($program['start']<$lastEnd)continue;$selected[]=$program;$lastEnd=$program['end'];}usort($selected,static fn(array $a,array $b):int=>$a['start']<=>$b['start']);
    $normalized=[];foreach($selected as$program){$last=count($normalized)-1;$same=$last>=0&&mb_strtolower(trim((string)$normalized[$last]['title']))===mb_strtolower(trim((string)$program['title']));if($same&&$program['start']<=$normalized[$last]['end']+300){$normalized[$last]['end']=max($normalized[$last]['end'],$program['end']);if($normalized[$last]['description']===''&&$program['description']!=='')$normalized[$last]['description']=$program['description'];continue;}$normalized[]=$program;}return $normalized;
}

function openapAptvEpgCountryMap(): array
{
    return [
        'AL'=>['feed'=>'al','name'=>'Albania'],'AM'=>['feed'=>'am','name'=>'Armenia'],'AT'=>['feed'=>'at','name'=>'Austria'],'AU'=>['feed'=>'au','name'=>'Australia'],
        'BE'=>['feed'=>'be','name'=>'Belgium'],'BG'=>['feed'=>'bg','name'=>'Bulgaria'],'BH'=>['feed'=>'ba','name'=>'Bosnia & Herzegovina'],'BR'=>['feed'=>'br','name'=>'Brazil'],
        'CA'=>['feed'=>'ca','name'=>'Canada'],'CH'=>['feed'=>'ch','name'=>'Switzerland'],'CZ'=>['feed'=>'cz','name'=>'Czech Republic'],'DE'=>['feed'=>'de','name'=>'Germany'],
        'DK'=>['feed'=>'dk','name'=>'Denmark'],'ES'=>['feed'=>'es','name'=>'Spain'],'FI'=>['feed'=>'fi','name'=>'Finland'],'FR'=>['feed'=>'fr','name'=>'France'],
        'GR'=>['feed'=>'gr','name'=>'Greece'],'HR'=>['feed'=>'hr','name'=>'Croatia'],'HU'=>['feed'=>'hu','name'=>'Hungary'],'IL'=>['feed'=>'il','name'=>'Israel'],
        'IT'=>['feed'=>'it','name'=>'Italy'],'MA'=>['feed'=>'my','name'=>'Malaysia'],'MK'=>['feed'=>'mk','name'=>'North Macedonia'],'NL'=>['feed'=>'nl','name'=>'Netherlands'],
        'NO'=>['feed'=>'no','name'=>'Norway'],'NZ'=>['feed'=>'nz','name'=>'New Zealand'],'PL'=>['feed'=>'pl','name'=>'Poland'],'PT'=>['feed'=>'pt','name'=>'Portugal'],
        'RO'=>['feed'=>'ro','name'=>'Romania'],'RU'=>['feed'=>'ru','name'=>'Russia'],'SE'=>['feed'=>'se','name'=>'Sweden'],'SI'=>['feed'=>'si','name'=>'Slovenia'],
        'SR'=>['feed'=>'rs','name'=>'Serbia'],'TN'=>['feed'=>'tw','name'=>'Taiwan'],'UK'=>['feed'=>'gb','name'=>'United Kingdom'],'US'=>['feed'=>'us','name'=>'United States'],
    ];
}

function openapAptvItalianEpgAlias(string $channelName): string
{
    $name = mb_strtoupper(trim($channelName), 'UTF-8');
    $name = preg_replace('/^IT\s*:\s*/u', '', $name) ?? $name;
    $name = preg_replace('/\[(?:NOT\s*)?24\/7\]/u', ' ', $name) ?? $name;
    $name = preg_replace('/\b(?:UHD|4K|HD|HEVC|SD|RAW|FHD|1080P|720P|576P|380P)\b/u', ' ', $name) ?? $name;
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

    static $aliases = [
        'CANALE 20' => 'Mediaset20.it',
        'CANALE 5' => 'Canale5.it',
        'CLASS HORSE TV' => 'HorseTV.it',
        'CINE 34' => 'Cine34.it',
        'CINE34' => 'Cine34.it',
        'DEEJAY TV' => 'DeejayTV.it',
        'FASHION TV' => 'FashionTV.it',
        'FOCUS' => 'Focus.it',
        'FRISBEE' => '-frisbee-.it',
        'GAMBERO ROSSO' => 'GamberoRosso.it',
        'ITALIA 1' => 'Italia1.it',
        'ITALIA 2 TV' => 'Italia2.it',
        'ITALIAN FISHING TV' => 'ItalianFishingTV.it',
        'MEDIA EXTRA' => 'MediasetExtra.it',
        'MEDIASET TWENTYSEVEN' => 'Mediaset27Twentyseven.it',
        'NICK JR' => 'NickJr.it',
        'QVC ITALIA' => 'QVC.it',
        'RADIO ITALIA TREND' => 'RadioItaliaTrendTv.it',
        'RAI 1' => 'Rai1.it',
        'RAI 2' => 'Rai2.it',
        'RAI 3' => 'Rai3.it',
        'REALTIME' => 'RealTime.it',
        'RETE 4' => 'Rete4.it',
        'RSI LA 1' => 'RSILA1.it',
        'RSI LA 2' => 'RSILA2.it',
        'RTL 102 5' => 'RTL1025.it',
        'SAN MARINO RTV' => 'SanMarinoRTV.it',
        'SKY ACI SPORT TV' => 'ACISportTv.it',
        'SKY CINEMA COLLECTION' => 'SkyCinemaCollection.it',
        'SKY CINEMA DUE 24' => 'SkyCinemaDue+24.it',
        'SKY CINEMA FAMILY' => 'SkyCinemaFamily.it',
        'SKY CINEMA ROMANCE' => 'SkyCinemaRomance.it',
        'SKY CINEMA UNO 24' => 'SkyCinemaUno+24.it',
        'SKY COMEDY CENTRAL' => 'ComedyCentral.it',
        'SKY CRIME INVESTIGATION' => 'SkyInvestigation.it',
        'SKY DAZN 1' => 'Dazn1Linear.it',
        'SKY DISCOVERY' => 'Discovery.it',
        'SKY DOCUMENTARIES' => 'SkyDocumentaries.it',
        'SKY HISTORY' => 'History.it',
        'SKY EXPLORER TV' => 'ExplorerChannel.it',
        'SKY INTER TV' => 'InterTV.it',
        'SKY INTER' => 'InterTV.it',
        'SKY INVESTIGATION' => 'SkyInvestigation.it',
        'SKY MILAN TV' => 'MilanTV.it',
        'SKY LAZIO' => 'LazioStyleCh.it',
        'SKY NATURE' => 'SkyNature.it',
        'SKY PRIMAFILA 1' => 'Primafila1.it',
        'SKY PRIMAFILA 2' => 'Primafila2.it',
        'SKY PRIMAFILA 3' => 'Primafila3.it',
        'SKY PRIMAFILA 4' => 'Primafila4.it',
        'SKY PRIMAFILA 5' => 'Primafila5.it',
        'SKY SERIE' => 'SkySerie.it',
        'SKY SPORT' => 'SkySport.it',
        'SKY SPORT 24' => 'SkySport24.it',
        'SKY SPORT CALCIO' => 'SkySportCalcio.it',
        'SKY SPORT F1' => 'SkySportF1.it',
        'SKY SPORT LEGEND' => 'SkySportLegend.it',
        'SKY SPORT MAX' => 'SkySportMax.it',
        'SKY SPORT TENNIS' => 'SkySportTennis.it',
        'SKY SPORT UNO' => 'SkySportUno.it',
        'SPORT ITALIA' => 'Sportitalia.it',
        'SUPER' => 'Super!.it',
        'SUPER TENNIS' => 'SuperTennis.it',
        'TV 2000' => 'TV2000.it',
    ];
    return $aliases[$name] ?? '';
}

function openapAptvEpgMatchKey(string $country, string $value): string
{
    $country = mb_strtoupper(trim($country), 'UTF-8');
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $value = preg_replace('/^[\p{L}]{2,3}\s*[:|\-]\s*/u', '', $value) ?? $value;
    $value = preg_replace('/\.[a-z]{2}$/iu', '', $value) ?? $value;
    $value = preg_replace('/\[(?:NOT\s*)?24\/7\]/u', ' ', $value) ?? $value;
    $value = preg_replace('/\b(?:UHD|4K|8K|HD|HEVC|SD|RAW|FHD|FULL\s*HD|1080[IP]?|720P?|576[IP]?|480[IP]?|380P)\b/u', ' ', $value) ?? $value;
    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '', 'UTF-8');
}

function openapAptvEffectiveEpgChannelId(string $country, string $channelName, string $providerId): string
{
    $providerId = trim($providerId);
    $alias = strtoupper(trim($country)) === 'IT' ? openapAptvItalianEpgAlias($channelName) : '';
    if ($alias !== '') return mb_strtolower($alias, 'UTF-8');
    if ($providerId !== '') return mb_strtolower($providerId, 'UTF-8');
    $matchKey = openapAptvEpgMatchKey($country, $channelName);
    return $matchKey !== '' ? '@name:' . $matchKey : '';
}

function openapAptvRegisterEpgSqlFunctions(SQLite3 $db): void
{
    $db->createFunction(
        'openap_aptv_epg_id',
        static fn(string $country, string $name, string $providerId): string => openapAptvEffectiveEpgChannelId($country, $name, $providerId),
        3,
        SQLITE3_DETERMINISTIC
    );
}

function openapAptvXmltvPrograms(string $feedCode, bool $refresh = false, array $wantedChannelIds = [], array $channelNames = [], string $providerId = ''): array
{
    if (!preg_match('/^[a-z]{2}$/', $feedCode)) return [];
    $cacheDirectory = '/var/cache/openap-iptv/epg';
    if (!is_dir($cacheDirectory)) @mkdir($cacheDirectory, 02770, true);
    @chgrp($cacheDirectory, 'www-data');
    @chmod($cacheDirectory, 02770);
    $cacheSuffix = preg_match('/^[a-f0-9]{32}$/', $providerId) ? '-' . $providerId : '';
    $cache = $cacheDirectory . '/xmltv-' . $feedCode . $cacheSuffix . '.json';
    $now = time();
    if (!$refresh && is_file($cache) && filemtime($cache) > $now - 21600) {
        $decoded = json_decode((string) file_get_contents($cache), true);
        $covered = is_array($decoded['covered_ids'] ?? null) ? array_fill_keys(array_map('strtolower', $decoded['covered_ids']), true) : [];
        $complete = (!$channelNames || (int)($decoded['matcher_version'] ?? 0) >= 2) && (!$wantedChannelIds || ($covered && !array_diff_key(array_fill_keys(array_map('strtolower', $wantedChannelIds), true), $covered)));
        if ($complete && is_array($decoded) && is_array($decoded['programs'] ?? null)) return array_change_key_case($decoded['programs'], CASE_LOWER);
    }

    $temporaryXml = $cacheDirectory . '/xmltv-' . $feedCode . $cacheSuffix . '.xml.new.' . getmypid();
    $output = @fopen($temporaryXml, 'wb');
    if ($output === false) return [];
    $sourceUrl = 'https://iptv-epg.org/files/epg-' . $feedCode . '.xml';
    $curl = openapAptvCurl($sourceUrl);
    curl_setopt_array($curl, [
        CURLOPT_FILE => $output,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE_LARGE => 536870912,
        CURLOPT_USERAGENT => 'APtv/1.0 XMLTV importer',
    ]);
    $downloaded = curl_exec($curl) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
    curl_close($curl);
    fclose($output);
    if (!$downloaded || !is_file($temporaryXml) || filesize($temporaryXml) < 256) {
        @unlink($temporaryXml);
        $decoded = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        return is_array($decoded) && is_array($decoded['programs'] ?? null) ? array_change_key_case($decoded['programs'], CASE_LOWER) : [];
    }

    $programs = []; $wanted = array_fill_keys(array_map('strtolower', $wantedChannelIds), true);$matchKeys=[];$matchedIds=[];$xmltvMatchCounts=[];
    foreach($channelNames as $requestedId=>$channelName){$key=openapAptvEpgMatchKey($feedCode,(string)$channelName);if($key!=='')$matchKeys[$key][strtolower((string)$requestedId)]=true;}
    try {
        $reader = new XMLReader();
        if (!$reader->open($temporaryXml, null, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('Unable to open XMLTV data');
        while ($reader->read()) {
            if($reader->nodeType===XMLReader::ELEMENT&&$reader->name==='channel'){$listedId=trim((string)$reader->getAttribute('id'));$listedKey=openapAptvEpgMatchKey($feedCode,$listedId);if($listedKey!=='')$xmltvMatchCounts[$listedKey]=($xmltvMatchCounts[$listedKey]??0)+1;continue;}
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'programme') continue;
            $channelId = trim((string) $reader->getAttribute('channel')); $channelKey = strtolower($channelId);$storeKeys=isset($wanted[$channelKey])?[$channelKey]:[];
            if(!$storeKeys&&$matchKeys){$matchKey=openapAptvEpgMatchKey($feedCode,$channelId);$candidates=$matchKeys[$matchKey]??[];if(count($candidates)===1&&($xmltvMatchCounts[$matchKey]??0)===1){$requestedId=(string)array_key_first($candidates);$storeKeys=[$requestedId];$matchedIds[$requestedId]=$channelKey;}}
            if ($wanted && !$storeKeys) continue;
            $start = strtotime((string) $reader->getAttribute('start'));
            $end = strtotime((string) $reader->getAttribute('stop'));
            if ($channelId === '' || $start === false || $end === false || $end <= $start) continue;
            $node = $reader->expand();
            $title = '';
            $description = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->nodeName === 'title' && $title === '') $title = trim($child->textContent);
                elseif ($child->nodeName === 'desc' && $description === '') $description = trim($child->textContent);
            }
            if ($title === '' || strcasecmp($title, 'No Data') === 0) continue;
            foreach($storeKeys?:[$channelKey] as $storeKey)$programs[$storeKey][] = ['id'=>'xmltv-'.$channelId.'-'.$start,'title'=>$title,'description'=>$description,'start'=>$start,'end'=>$end];
        }
        $reader->close();
    } catch (Throwable $error) {
        $programs = [];
    }
    @unlink($temporaryXml);
    if (!$programs) {
        $decoded = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        return is_array($decoded) && is_array($decoded['programs'] ?? null) ? array_change_key_case($decoded['programs'], CASE_LOWER) : [];
    }
    foreach ($programs as $channelId => $entries) $programs[$channelId] = openapAptvNormalizeEpgPrograms($entries);
    $temporaryCache = $cache . '.new.' . getmypid();
    $encoded = json_encode(['fetched_at'=>$now,'source'=>$sourceUrl,'matcher_version'=>2,'covered_ids'=>array_keys($wanted),'matched_ids'=>$matchedIds,'programs'=>$programs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($encoded) && file_put_contents($temporaryCache, $encoded, LOCK_EX) !== false) {
        chmod($temporaryCache, 0640);
        @chgrp($temporaryCache, 'www-data');
        rename($temporaryCache, $cache);
    }
    @unlink($temporaryCache);
    return $programs;
}

function openapAptvUnifiedEpg(): void
{
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    $map = openapAptvEpgCountryMap();
    $requestedItemId=trim((string)($_GET['item_id']??''));
    $requestedProviderId=trim((string)($_GET['provider_id']??''));
    $providerContext=openapAptvProviderContext($requestedProviderId);
    if(!is_array($providerContext)){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]);return;}
    $providerId=(string)$providerContext['id'];
    if($requestedItemId!==''&&!preg_match('/^[0-9]{1,20}$/',$requestedItemId)){http_response_code(400);echo json_encode(['ok'=>false,'message'=>_('Invalid channel.')]);return;}
    $country = strtoupper(trim((string) ($_GET['country'] ?? 'IT')));
    if (!isset($map[$country])) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>_('Unsupported EPG country.')]); return; }
    $offset=max(0,min(10000,(int)($_GET['offset']??0))); $limit=max(1,min(96,(int)($_GET['limit']??48))); $now=time(); $windowStart=$now-1800; $windowEnd=$now+19800;
    openap_release_session();
    try {
        $db=new SQLite3((string)$providerContext['catalog'],SQLITE3_OPEN_READONLY); openapAptvRegisterEpgSqlFunctions($db);
        $regionExpression = 'ir.region_code';
        $available=[];
        if($requestedItemId===''){
            $regions=$db->query("SELECT DISTINCT ($regionExpression) AS code FROM categories c JOIN items i ON i.kind=c.kind AND i.category_id=c.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE c.kind='live' ORDER BY code");
            while($row=$regions->fetchArray(SQLITE3_ASSOC)){ $code=(string)$row['code']; if(isset($map[$code]))$available[]=['code'=>$code,'name'=>$map[$code]['name']]; }
        }
        if($requestedItemId!==''){
            $selected=$db->prepare("SELECT i.item_id,i.name,i.icon_url,i.epg_channel_id,($regionExpression) AS country FROM items i JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND i.item_id=:item_id LIMIT 1");
            $selected->bindValue(':item_id',$requestedItemId,SQLITE3_TEXT);$channel=$selected->execute()->fetchArray(SQLITE3_ASSOC);
            if(!is_array($channel)){ $db->close();http_response_code(404);echo json_encode(['ok'=>false,'message'=>_('Channel not found.')]);return; }
            $country=strtoupper((string)$channel['country']);if(!isset($map[$country])){$db->close();echo json_encode(['ok'=>true,'country'=>$country,'items'=>[['item_id'=>(string)$channel['item_id'],'name'=>(string)$channel['name'],'icon_url'=>(string)$channel['icon_url'],'epg_channel_id'=>(string)$channel['epg_channel_id'],'programs'=>[]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;}
            $channel['epg_channel_id']=openapAptvEffectiveEpgChannelId($country,(string)$channel['name'],(string)$channel['epg_channel_id']);
            $selectedEpgId=strtolower((string)$channel['epg_channel_id']);$channels=[$channel];
            $wanted=[$selectedEpgId];$channelNames=[$selectedEpgId=>(string)$channel['name']];
            $count=1;$offset=0;$limit=1;$db->close();
            $windowStart=$now-1800;$windowEnd=$now+86400;
            $xmltvPrograms=$selectedEpgId!==''?openapAptvXmltvPrograms((string)$map[$country]['feed'],!empty($_GET['refresh']),$wanted,$channelNames,$providerId):[];$programs=$selectedEpgId!==''?openapAptvNormalizeEpgPrograms($xmltvPrograms[$selectedEpgId]??[]):[];$programs=array_values(array_filter($programs,static fn(array $program):bool=>$program['end']>$windowStart&&$program['start']<$windowEnd));
            $channels[0]['programs']=$programs;$channels[0]['epg_source']=$programs?'xmltv':'';
            echo json_encode(['ok'=>true,'country'=>$country,'country_name'=>$map[$country]['name'],'offset'=>0,'next_offset'=>1,'limit'=>1,'total'=>1,'has_more'=>false,'window_start'=>$windowStart,'window_end'=>$windowEnd,'now'=>$now,'items'=>$channels],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;
        }
        $statement=$db->prepare("WITH ranked AS (SELECT i.item_id,i.name,i.icon_url,openap_aptv_epg_id(:country,i.name,i.epg_channel_id) AS epg_channel_id,ROW_NUMBER() OVER(PARTITION BY openap_aptv_epg_id(:country,i.name,i.epg_channel_id) ORDER BY CASE WHEN UPPER(i.name) LIKE '% HD%' THEN 0 WHEN UPPER(i.name) LIKE '% SD%' THEN 2 WHEN UPPER(i.name) LIKE '%HEVC%' OR UPPER(i.name) LIKE '%4K%' OR UPPER(i.name) LIKE '%UHD%' THEN 3 ELSE 1 END,i.name COLLATE NOCASE,i.item_id) AS epg_rank FROM items i JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND ($regionExpression)=:country AND openap_aptv_epg_id(:country,i.name,i.epg_channel_id)<>'') SELECT item_id,name,icon_url,epg_channel_id FROM ranked WHERE epg_rank=1 ORDER BY name COLLATE NOCASE");
        $statement->bindValue(':country',$country,SQLITE3_TEXT);
        $query=$statement->execute(); $channels=[]; while($row=$query->fetchArray(SQLITE3_ASSOC))$channels[]=$row;
        $wanted=[];$channelNames=[];$allIds=$db->prepare("SELECT openap_aptv_epg_id(:country,i.name,i.epg_channel_id) AS resolved_id,i.name FROM items i JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND ($regionExpression)=:country AND openap_aptv_epg_id(:country,i.name,i.epg_channel_id)<>''");$allIds->bindValue(':country',$country,SQLITE3_TEXT);$allQuery=$allIds->execute();while($row=$allQuery->fetchArray(SQLITE3_ASSOC)){$id=strtolower((string)$row['resolved_id']);$wanted[$id]=$id;$channelNames[$id]=(string)$row['name'];}$wanted=array_values($wanted);$db->close();
    } catch(Throwable $error){ http_response_code(503); echo json_encode(['ok'=>false,'message'=>_('Unable to read EPG channels.')]); return; }
    $wanted=array_map('strtolower',$wanted); $xmltvPrograms=openapAptvXmltvPrograms((string)$map[$country]['feed'],!empty($_GET['refresh']),$wanted,$channelNames,$providerId);
    $matchedItems=[];
    foreach($channels as $channel){
        $programs=openapAptvNormalizeEpgPrograms($xmltvPrograms[strtolower((string)$channel['epg_channel_id'])]??[]);
        $programs=array_values(array_filter($programs,static fn(array $program):bool=>$program['end']>$windowStart&&$program['start']<$windowEnd));
        if($programs)$matchedItems[]=['item_id'=>(string)$channel['item_id'],'name'=>(string)$channel['name'],'icon_url'=>(string)$channel['icon_url'],'epg_channel_id'=>(string)$channel['epg_channel_id'],'epg_source'=>'xmltv','programs'=>$programs];
    }
    $count=count($matchedItems);$items=array_slice($matchedItems,$offset,$limit);$nextOffset=$offset+count($items);
    echo json_encode(['ok'=>true,'country'=>$country,'country_name'=>$map[$country]['name'],'countries'=>$available,'offset'=>$offset,'next_offset'=>$nextOffset,'limit'=>$limit,'total'=>$count,'has_more'=>$nextOffset<$count,'window_start'=>$windowStart,'window_end'=>$windowEnd,'now'=>$now,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function openapAptvItalianEpg(): void
{
    openapAptvUnifiedEpg();
}

function openapAptvItalianVod(string $itemId): ?array
{
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) return null;
    try{$db=new SQLite3('/var/lib/openap-iptv/catalog.db',SQLITE3_OPEN_READONLY);$s=$db->prepare("SELECT i.name,i.container_extension FROM items i JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id WHERE i.kind='vod' AND i.item_id=:id AND c.name LIKE 'IT - %' LIMIT 1");$s->bindValue(':id',$itemId,SQLITE3_TEXT);$row=$s->execute()->fetchArray(SQLITE3_ASSOC);$db->close();return is_array($row)?$row:null;}catch(Throwable $error){return null;}
}

function openapAptvVodKind(string $kind): string
{
    $kind = strtolower(trim($kind));
    if (in_array($kind, ['series', 'episode', 'tv', 'tv_series'], true)) return 'series';
    if (in_array($kind, ['vod', 'movie', 'movies'], true)) return 'vod';
    return '';
}

function openapAptvVod(string $itemId, string $requestedKind = '', ?string $requestedProviderId = null): ?array
{
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) return null;
    $requestedKind = openapAptvVodKind($requestedKind !== '' ? $requestedKind : (string) ($_GET['kind'] ?? ''));
    $context = openapAptvProviderContextForRequest($requestedProviderId);
    $profile = is_array($context) ? (array) ($context['profile'] ?? []) : null;
    $catalog = is_array($context) ? (string) ($context['catalog'] ?? '') : '';
    if (!$profile || $catalog === '') return null;
    if ($requestedKind === 'series' || $requestedKind === '') {
        $cache = '/var/cache/openap-iptv/series-episodes/' . hash('sha256', (string) $profile['url'] . "\0" . (string) $profile['username'] . "\0" . $itemId) . '.json';
        $episode = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        if (is_array($episode) && ($episode['source_kind'] ?? '') === 'series') return $episode;
        // An episode ID is not a series catalog ID. Without its episode
        // metadata, never guess a /movie/ URL for a colliding numeric ID.
        if ($requestedKind === 'series') return null;
    }
    try {
        $db = new SQLite3($catalog, SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT name,container_extension FROM items WHERE kind='vod' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        if (is_array($row)) { $row['source_kind'] = 'movie'; return $row; }
    } catch (Throwable $error) { return null; }
    return null;
}

function openapAptvVodSourcePath(array $item): string
{
    return ($item['source_kind'] ?? 'movie') === 'series' ? 'series' : 'movie';
}

function openapAptvSealUrl(string $url, int $ttl = 90): string
{
    $key = file_get_contents('/var/lib/openap-iptv/provider.key');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $payload = json_encode(['url' => $url, 'expires' => time() + max(30, min(28800, $ttl))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $binary = $nonce . sodium_crypto_secretbox($payload, $nonce, $key);
    sodium_memzero($payload);
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function openapAptvTitleCacheKey(string $id): string
{
    return md5('openap-title-cache:' . $id);
}

function openapAptvTitleCacheDirectory(string $id): string
{
    return '/var/cache/openap-iptv/vod-cache/' . openapAptvTitleCacheKey($id);
}

function openapAptvTitleCacheStop(string $id): void
{
    $directory = openapAptvTitleCacheDirectory($id);
    if (!is_dir($directory)) return;
    @touch($directory . '/stop');
    $key = openapAptvTitleCacheKey($id);
    $runtime = '/run/openap-iptv-vod-remux/' . $key;
    if (is_dir($runtime)) @touch($runtime . '/stop');
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $status = is_file($directory . '/status.json') ? json_decode((string) @file_get_contents($directory . '/status.json'), true) : null;
        if (!is_array($status) || empty($status['active'])) break;
        usleep(50000);
    }
    $status = is_file($directory . '/status.json') ? json_decode((string) @file_get_contents($directory . '/status.json'), true) : null;
    if (is_array($status) && !empty($status['active'])) {
        $pid = is_file($directory . '/producer.pid') ? (int) trim((string) @file_get_contents($directory . '/producer.pid')) : 0;
        if ($pid > 1 && function_exists('posix_kill')) @posix_kill($pid, SIGTERM);
        $killDeadline = microtime(true) + 2;
        while (microtime(true) < $killDeadline) {
            $current = is_file($directory . '/status.json') ? json_decode((string) @file_get_contents($directory . '/status.json'), true) : null;
            if (!is_array($current) || empty($current['active'])) break;
            usleep(50000);
        }
    }
    @unlink($directory . '/stop');
    @unlink($runtime . '/stop');
}

function openapAptvTitleCacheEnsureWorker(string $id, int $start, string $audioMode, string $videoCodec, bool $replace = false): string
{
    $directory = openapAptvTitleCacheDirectory($id);
    $segments = $directory . '/segments';
    if ((!is_dir($segments) && !@mkdir($segments, 0700, true)) || !is_writable($segments)) return 'cache_directory_unavailable';
    $statusFile = $directory . '/status.json';
    $indexFile = $directory . '/index.json';
    $status = is_file($statusFile) ? json_decode((string) @file_get_contents($statusFile), true) : null;
    $index = is_file($indexFile) ? json_decode((string) @file_get_contents($indexFile), true) : null;
    $cacheStart = is_array($index) ? (float) ($index['cache_start'] ?? 0) : 0;
    $cacheEnd = is_array($index) ? (float) ($index['cache_end'] ?? 0) : 0;
    $inside = is_array($index) && $cacheEnd > $cacheStart && $start >= $cacheStart - .5 && $start <= $cacheEnd + .5;
    $sameProducer = is_array($status) && !empty($status['active']) && isset($status['producer_start']) && abs((float) $status['producer_start'] - $start) <= .5;
    // The direct-start response and the media request can arrive together.
    // The worker lock closes that small race before status.json is published.
    $workerLock = @fopen($directory . '/worker.lock', 'c');
    if (is_resource($workerLock)) {
        if (!@flock($workerLock, LOCK_EX | LOCK_NB)) {
            fclose($workerLock);
            // A worker that is already publishing its target is reusable. If
            // its published target differs, let the normal stop/restart path
            // handle a legitimate seek outside the current cache window.
            if (!is_array($status) || empty($status['active']) || !isset($status['producer_start'])) return 'reuse_title_producer';
        }
        else {
            @flock($workerLock, LOCK_UN);
            fclose($workerLock);
        }
    }
    if ($replace || (is_array($status) && !empty($status['active']) && !$inside && !$sameProducer)) {
        openapAptvTitleCacheStop($id);
        $status = null;
    }
    if (is_array($status) && !empty($status['active'])) return 'reuse_title_producer';
    if ($inside && is_array($status) && !empty($status['complete'])) return 'reuse_complete_title_cache';
    // A cache hit is served immediately, while a stopped producer resumes
    // from the cache tail so playback can continue without re-downloading the
    // already indexed region.
    $workerStart = $inside ? max($start, (int) floor($cacheEnd)) : $start;
    @mkdir('/run/openap-iptv-vod-remux/' . openapAptvTitleCacheKey($id), 0700, true);
    @unlink($directory . '/stop');
    $log = $directory . '/worker.log';
    $pipes = [];
    $worker = @proc_open(['/usr/bin/setsid', '-f', '/usr/bin/php', '/usr/local/lib/openap/iptv_vod_cache_worker.php', $id, openapAptvTitleCacheKey($id), (string) $workerStart, $audioMode, $videoCodec, '0'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', $log, 'a']], $pipes);
    if (is_resource($worker)) {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($worker);
        return 'title_producer_started';
    }
    return 'title_producer_start_failed';
}

function openapAptvStartTitleCachePilot(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $id = trim((string) ($_GET['id'] ?? ''));
    // Phase 4: the pilot title no longer has a persistent cache.  Reject the
    // legacy cache-start endpoint before it can recreate client.json or spawn
    // the removed disk-backed worker.
    if ($id === '1556812') {
        http_response_code(410);
        echo json_encode(['ok' => false, 'code' => 'PERSISTENT_VOD_CACHE_REMOVED'], JSON_UNESCAPED_SLASHES);
        return;
    }
    $start = max(0, min(86400, (int) ($_GET['start'] ?? 0)));
    $replace = (string) ($_GET['replace_cache'] ?? '') === '1';
    $vod = openapAptvVod($id) ?: openapAptvItalianVod($id);
    $profile = openapAptvProviderProfile();
    $probe = is_string($id) ? openapAptvVodCachedProbe($id) : null;
    if (is_array($probe)) {
        $probe['source_kind'] = (string) ($vod['source_kind'] ?? '');
        $probe['series_id'] = (string) ($vod['series_id'] ?? '');
        $probe['direct_compatible'] = openapAptvVodProgressiveCompatible($probe);
    }
    if (!$vod || !$profile || !is_array($probe) || empty($probe['direct_compatible'])) {
        http_response_code(415);
        echo json_encode(['ok' => false, 'message' => _('This title requires a prepared stream.')]);
        return;
    }
    $key = openapAptvTitleCacheKey($id);
    $directory = openapAptvTitleCacheDirectory($id);
    @mkdir($directory, 0700, true);
    $clientFile = $directory . '/client.json';
    $clientTemporary = $clientFile . '.new.' . getmypid();
    if (@file_put_contents($clientTemporary, json_encode(['position' => $start, 'updated' => microtime(true)], JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($clientTemporary, 0600);
        @rename($clientTemporary, $clientFile);
    }
    $indexFile = $directory . '/index.json';
    $index = is_file($indexFile) ? json_decode((string) @file_get_contents($indexFile), true) : null;
    $selected = is_array($index) ? openapAptvCacheFragmentAt($index, (float) $start) : null;
    $cacheStart = is_array($index) ? (float) ($index['cache_start'] ?? 0) : 0;
    $cacheEnd = is_array($index) ? (float) ($index['cache_end'] ?? 0) : 0;
    $cacheHit = is_array($selected) && $cacheEnd > $cacheStart && $start >= $cacheStart - .5 && $start <= $cacheEnd + .5;
    $audio = strtolower((string) ($probe['audio'][0]['codec'] ?? ''));
    $audioChannels = (int) ($probe['audio'][0]['channels'] ?? 0);
    $audioMode = ($audio !== 'aac' || $audioChannels > 2) ? 'aac' : 'copy';
    $videoCodec = strtolower((string) ($probe['video']['codec'] ?? ''));
    @mkdir('/run/openap-iptv-vod-remux/progressive-stats/' . $key, 0700, true);
    $cacheDecision = openapAptvTitleCacheEnsureWorker($id, $start, $audioMode, $videoCodec, $replace && !$cacheHit);
    $password = (string) ($profile['password'] ?? '');
    $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($vod['container_extension'] ?? 'mkv')));
    $source = rtrim((string) $profile['url'], '/') . '/' . openapAptvVodSourcePath($vod) . '/' . rawurlencode((string) $profile['username']) . '/' . rawurlencode($password) . '/' . $id . '.' . $extension;
    $sealed = openapAptvSealUrl($source, 28800);
    sodium_memzero($source); sodium_memzero($password);
    $query = ['token' => $sealed, 'session' => $key, 'format' => 'fmp4', 'id' => $id, 'start' => $start, 'cache' => '1', 'audio_mode' => $audioMode, 'video_codec' => $videoCodec];
    $cacheOrigin = $cacheHit ? (float) ($selected['start'] ?? $start) : max(0, $start - 10);
    $status = is_file($directory . '/status.json') ? json_decode((string) @file_get_contents($directory . '/status.json'), true) : null;
    echo json_encode(['ok' => true, 'url' => openapAptvStreamUrl('/aptv/api/vod/source?' . http_build_query($query)), 'session' => $key,
        'start' => $start, 'timeline_origin' => $cacheOrigin, 'duration' => (float) ($probe['duration'] ?? 0),
        'source_bitrate' => (int) ($probe['source_bitrate'] ?? 0), 'fmp4' => true, 'cache' => true, 'cache_only' => true,
        'cache_seek' => $cacheHit, 'cache_seek_origin' => $cacheOrigin, 'cache_fragment_file' => (string) ($selected['file'] ?? ''),
        'cache_fragment_duration' => (float) ($selected['duration'] ?? 0), 'cache_fragment_keyframe' => $selected['keyframe'] ?? null,
        'cache_start' => $cacheStart, 'cache_end' => $cacheEnd, 'cache_session_reused' => $cacheHit,
        'cache_decision' => $cacheDecision, 'cache_status' => is_array($status) ? $status : null,
        'hybrid' => false, 'progressive_audio_codec' => $audioMode === 'aac' ? 'aac' : $audio,
        'progressive_audio_transcoded' => $audioMode === 'aac', 'progressive_start_target' => 2], JSON_UNESCAPED_SLASHES);
}

function openapAptvVodSourceDaemon(): void
{
    @set_time_limit(0); ignore_user_abort(false); openap_release_session();
    while (ob_get_level() > 0) @ob_end_flush();
    header('X-Accel-Buffering: no');
    header('Content-Type: video/mp4');
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: none');
    $sid = strtolower((string) ($_GET['session'] ?? ''));
    $epoch = max(1, (int) ($_GET['epoch'] ?? 1));
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $sid)) { http_response_code(422); return; }
    if (!openapAptvEphemeralVodEligible($itemId)) { http_response_code(404); return; }
    $socket = @stream_socket_client('unix:///run/openap-iptv-vod-ephemeral/daemon.sock', $errno, $error, 5);
    if (!is_resource($socket)) { http_response_code(503); return; }
    stream_set_timeout($socket, 15);
    $request = [
        'sid'=>$sid, 'epoch'=>$epoch, 'id'=>$itemId,
        'start'=>max(0, min(86400, (int) ($_GET['start'] ?? 0))),
        'token'=>(string) ($_GET['token'] ?? ''),
        'audio_mode'=>(string) ($_GET['audio_mode'] ?? ''),
        'video_codec'=>(string) ($_GET['video_codec'] ?? ''),
    ];
    if (@fwrite($socket, json_encode($request, JSON_UNESCAPED_SLASHES) . "\n") === false) { fclose($socket); return; }
    $line = fgets($socket, 1048576);
    $meta = is_string($line) ? json_decode(trim($line), true) : null;
    if (!is_array($meta) || empty($meta['ok'])) { if (!headers_sent()) http_response_code(503); fclose($socket); return; }
    header('X-OpenAP-VOD-Daemon: phase3');
    header('X-OpenAP-VOD-Epoch: ' . $epoch);
    header('X-OpenAP-VOD-Keyframe: ' . number_format((float) ($meta['origin_keyframe'] ?? 0), 6, '.', ''));
    while (!feof($socket)) {
        $chunk = @fread($socket, 262144);
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        if (ob_get_level() > 0) @ob_flush();
        flush();
    }
    fclose($socket);
}

function openapAptvVodSource(): void
{
    // Phase 4: the pilot title is permanently ephemeral.  Reject the old
    // disk-cache source explicitly so stale callers cannot respawn the worker.
    if ((string) ($_GET['cache'] ?? '') === '1' && (string) ($_GET['id'] ?? '') === '1556812' && (string) ($_GET['ephemeral'] ?? '') !== '1') {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'code'=>'PERSISTENT_VOD_CACHE_REMOVED'], JSON_UNESCAPED_SLASHES);
        return;
    }
    @set_time_limit(0);ignore_user_abort(false);openap_release_session();while(ob_get_level()>0)@ob_end_flush();header('X-Accel-Buffering: no');
    $url=openapAptvOpenUrl((string)($_GET['token']??''));if(!$url){http_response_code(403);return;}
    if((string)($_GET['format']??'')==='fmp4'){
        if ((string) ($_GET['daemon'] ?? '') === '1' && openapAptvEphemeralVodEligible((string) ($_GET['id'] ?? ''))) {
            openapAptvVodSourceDaemon();
            return;
        }
        $statsDirectory=null;$statsFile=null;$transferStarted=microtime(true);$transferUpdated=$transferStarted;$lastOutputAt=0;$transferBytes=0;$transferSamples=[];$session=(string)($_GET['session']??'');$providerStatus=0;$providerError='';$streamError='';$ffmpegExit=null;$ffmpegStderr='';$proxyProcess=null;$proxyRuntime=null;$proxyPort=0;$tfdtAbsolute=false;$tfdtLast=null;$tfdtLabel=null;$tfdtDelta=null;$tfdtFragments=0;$tfdtError='';$fragmentObservationCount=0;
        $start=max(0,min(86400,(int)($_GET['start']??0)));$progressiveAudioMode=(string)($_GET['audio_mode']??'')==='aac';$progressiveVideoMode=strtolower((string)($_GET['video_codec']??''));$progressiveVideoTranscode=(string)($_GET['video_transcode']??'')==='1';
        if(preg_match('/^[a-f0-9]{32}$/',$session)){$candidate='/run/openap-iptv-vod-remux/progressive-stats/'.$session;if(is_dir($candidate)&&is_writable($candidate)){$statsDirectory=$candidate;$statsFile=$candidate.'/transfer-'.bin2hex(random_bytes(8)).'.json';}}
        $writeStats=static function(bool $active)use(&$transferBytes,$transferStarted,&$transferUpdated,&$lastOutputAt,&$transferSamples,$statsDirectory,$statsFile,&$providerStatus,&$providerError,&$streamError,&$ffmpegExit,&$ffmpegStderr,&$tfdtAbsolute,&$tfdtLast,&$tfdtLabel,&$tfdtDelta,&$tfdtFragments,&$tfdtError):void{
            if(!$statsDirectory||!$statsFile)return;$now=microtime(true);$transferSamples[]=['time'=>$now,'bytes'=>$transferBytes];$transferSamples=array_values(array_filter($transferSamples,static fn(array $sample):bool=>$sample['time']>=$now-65));
            $windowBytes=0;$windowSeconds=0;$minimumRate=0;$minimumAvailable=false;
            foreach($transferSamples as $sample){if($sample['time']<=$now-10){$windowBytes=$transferBytes-(int)$sample['bytes'];$windowSeconds=max(.001,$now-(float)$sample['time']);break;}}
            $historySeconds=$transferSamples?max(0,$now-(float)$transferSamples[0]['time']):0;
            if($historySeconds>=60){$rates=[];foreach($transferSamples as $sample){if($sample['time']<$now-60)continue;$older=null;foreach($transferSamples as $candidate){if($candidate['time']<=(float)$sample['time']-10)$older=$candidate;}if($older!==null)$rates[]=(int)round(((int)$sample['bytes']-(int)$older['bytes'])*8/max(.001,(float)$sample['time']-(float)$older['time']));}if($rates){$minimumRate=min($rates);$minimumAvailable=true;}}
            $elapsed=max(.001,$now-$transferStarted);$payload=['started'=>$transferStarted,'updated'=>$now,'elapsed'=>$elapsed,'bytes'=>$transferBytes,'transfer_bps'=>(int)round($transferBytes*8/$elapsed),'transfer_10s_bps'=>$windowSeconds>0?(int)round($windowBytes*8/$windowSeconds):0,'transfer_min_60s_bps'=>$minimumRate,'transfer_window_seconds'=>$windowSeconds,'minimum_available'=>$minimumAvailable,'provider_status'=>$providerStatus,'provider_error'=>$providerError,'stream_error'=>$streamError,'ffmpeg_exit'=>$ffmpegExit,'last_output_at'=>$lastOutputAt,'output_gap_seconds'=>$lastOutputAt>0?max(0,$now-$lastOutputAt):$elapsed,'ffmpeg_stderr'=>substr(trim($ffmpegStderr),-1000),'active'=>$active,'tfdt_absolute'=>$tfdtAbsolute,'tfdt_last'=>$tfdtLast,'tfdt_label'=>$tfdtLabel,'tfdt_delta'=>$tfdtDelta,'tfdt_fragments'=>$tfdtFragments,'tfdt_error'=>$tfdtError];$temporary=$statsFile.'.new';if(@file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){@chmod($temporary,0600);@rename($temporary,$statsFile);@touch($statsDirectory);}$transferUpdated=$now;
        };
        $statusProbe=openapAptvCurl($url);
        if($statusProbe!==false){$probeStatus=0;$probeError='';curl_setopt_array($statusProbe,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_RANGE=>'0-0',CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$probeStatus):int{if(preg_match('~^HTTP/\S+\s+(\d{3})~i',trim($line),$match))$probeStatus=(int)$match[1];return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk):int{return strlen($chunk);}]);$probeOk=curl_exec($statusProbe);$probeError=curl_error($statusProbe);$providerStatus=(int)curl_getinfo($statusProbe,CURLINFO_RESPONSE_CODE);curl_close($statusProbe);if(!$probeOk&&$providerStatus===0)$providerError='Provider connection failed';elseif($probeError!=='')$providerError='Provider probe error';}
        $stopProxy=static function()use(&$proxyProcess,&$proxyRuntime):void{if($proxyRuntime)@touch($proxyRuntime.'/stop');if(is_resource($proxyProcess)){@proc_terminate($proxyProcess);@proc_close($proxyProcess);$proxyProcess=null;}if($proxyRuntime){foreach(glob($proxyRuntime.'/*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($proxyRuntime);$proxyRuntime=null;}};
        $itemId=trim((string)($_GET['id']??''));
        // VPS-only read-ahead experiment.  The worker keeps a disk-backed
        // fMP4 copy alive while the browser is paused; all other VOD remains
        // on the existing direct relay path.
        $cuesStrictPilot=(string)($_GET['cues_strict']??'')==='1'&&(string)($_GET['ephemeral']??'')==='1'&&is_numeric((string)($_GET['cues_origin']??''));
        $cuesStrictOrigin=$cuesStrictPilot?(float)$_GET['cues_origin']:0.0;
        $ephemeralPilot=(openapAptvEphemeralVodEligible($itemId)||$cuesStrictPilot)&&(string)($_GET['ephemeral']??'')==='1';
        $cacheExperiment=$itemId==='1556812'&&(string)($_GET['cache']??'')==='1'&&!$ephemeralPilot&&preg_match('/^[a-f0-9]{32}$/',$session);
        if($cacheExperiment){
            $cacheRoot='/var/cache/openap-iptv/vod-cache';$cacheDirectory=$cacheRoot.'/'.$session;
            if((!is_dir($cacheRoot)&&!@mkdir($cacheRoot,0770,true))||(!is_dir($cacheDirectory)&&!@mkdir($cacheDirectory,0700,true))){http_response_code(503);return;}
            // Retain abandoned experiments briefly, then reclaim their disk.
            foreach(glob($cacheRoot.'/*')?:[]as$old){if(is_dir($old)&&basename($old)!==$session&&filemtime($old)<time()-21600){foreach(glob($old.'/*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($old);}}
            $runtime='/run/openap-iptv-vod-remux/'.$session;if(!is_dir($runtime)&&!@mkdir($runtime,0700,true)){$runtime='';}
            if($runtime!==''){
                $statusFile=$cacheDirectory.'/status.json';
                if(!is_file($statusFile)||!is_file($cacheDirectory.'/index.json')){
                    $workerAudioMode=(string)($_GET['audio_mode']??'')==='aac'?'aac':'copy';$workerVideoMode=strtolower((string)($_GET['video_codec']??''));$workerBurst=(string)($_GET['burst']??'')==='1';
                    $worker=@proc_open(['/usr/bin/setsid','-f','/usr/bin/php','/usr/local/lib/openap/iptv_vod_cache_worker.php',$itemId,$session,(string)$start,$workerAudioMode,$workerVideoMode,$workerBurst?'1':'0'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',$cacheDirectory.'/worker.log','a']],$workerPipes);
                    if(is_resource($worker)){foreach($workerPipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker);}
                }
                // Do not keep the player attached to the previous session while
                // an arbitrary provider seek is being indexed.  A new cache may
                // need to scan the provider container before its first keyframe.
                $deadline=microtime(true)+8;$manifestFile=$cacheDirectory.'/index.json';$initFile=$cacheDirectory.'/init.mp4';
                while(microtime(true)<$deadline&&(!is_file($manifestFile)||!is_file($initFile))){usleep(100000);$status=is_file($statusFile)?json_decode((string)@file_get_contents($statusFile),true):null;if(is_array($status)&&(!empty($status['error'])||!empty($status['cache_limit'])))break;}
                $index=is_file($manifestFile)?json_decode((string)@file_get_contents($manifestFile),true):null;$fragments=is_array($index)&&is_array($index['fragments']??null)?$index['fragments']:[];
                if(is_file($initFile)&&is_array($index)){$cacheStart=(float)($index['cache_start']??$index['source_start']??0);$selectedFragment=openapAptvCacheFragmentAt($index,(float)$start);header('Content-Type: video/mp4');header('Cache-Control: private, no-store');header('Accept-Ranges: none');header('X-Accel-Buffering: no');header('X-OpenAP-VOD-Cache: vps-segmented-seek');
                    $sendFile=static function(string $file):bool{if(!is_file($file))return false;$handle=@fopen($file,'rb');if(!is_resource($handle))return false;while(!feof($handle)){$chunk=fread($handle,262144);if($chunk===false||$chunk==='')break;echo $chunk;if(ob_get_level()>0)@ob_flush();flush();}fclose($handle);return true;};$sendFile($initFile);
$nextStart=(float)($selectedFragment['start']??$start);
// Finestra di lettura in avanti.  Il browser non riceve tutta la cache su
// disco: riceve solo ~30 s davanti alla posizione corrente e il relay resta in
// attesa finche' il playhead non avanza.  I quattro livelli restano distinti e
// separatamente misurabili: cache persistente su disco, byte trasferiti al
// browser, buffer MSE, posizione effettivamente riproducibile.
// For a non-zero cached seek, send the selected keyframe and only a small
// successor window before playback starts.  The relay resumes after the
// client heartbeat advances, so this limits startup transfer without changing
// the cache timeline or the normal start-at-zero path.
$relayWindowAheadSeconds=($start>0&&is_array($selectedFragment))?10.0:30.0;
$clientFile=$cacheDirectory.'/client.json';
$relayFile=$cacheDirectory.'/relay.json';
$initBytes=is_file($initFile)?(int)filesize($initFile):0;
$relaySourceStart=(float)$start;
$clientPosition=(float)$start;
$clientPositionAt=0.0;
$servedFragments=[];
$servedBytes=0;
$windowWaiting=false;
$relayPublish=static function()use(&$servedFragments,&$servedBytes,&$relayFile,&$relaySourceStart,&$clientPosition,&$clientPositionAt,&$windowWaiting,$relayWindowAheadSeconds,&$nextStart,$initBytes):void{
    $count=count($servedFragments);
    $payload=['updated'=>microtime(true),'source_start'=>$relaySourceStart,'init_bytes'=>$initBytes,
        'window_ahead_seconds'=>$relayWindowAheadSeconds,'window_waiting'=>$windowWaiting,
        'client_position'=>$clientPosition,'client_position_age'=>$clientPositionAt>0?max(0,microtime(true)-$clientPositionAt):null,
        'next_start'=>$nextStart,'served_bytes'=>$servedBytes,'served_count'=>$count,
        'last_served'=>$count>0?$servedFragments[$count-1]['file']:null,
        'served'=>$servedFragments];
    $temporary=$relayFile.'.new.'.getmypid();
    if(@file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){@chmod($temporary,0600);@rename($temporary,$relayFile);}
};
ignore_user_abort(true);
while(true){
    $current=is_file($manifestFile)?json_decode((string)@file_get_contents($manifestFile),true):null;
    $available=is_array($current)&&is_array($current['fragments']??null)?array_values($current['fragments']):[];
    usort($available,static fn(array $left,array $right):int=>((float)($left['start']??0)<=> (float)($right['start']??0)));
    $availableEnd=0.0;
    foreach($available as $candidate)$availableEnd=max($availableEnd,(float)($candidate['start']??0)+(float)($candidate['duration']??0));
    $firstAvailable=$available[0]??null;
    if(is_array($firstAvailable)&&$nextStart<(float)($firstAvailable['start']??$nextStart))$nextStart=(float)($firstAvailable['start']??$nextStart);
    // Posizione corrente del browser: l'ultima riportata dal player se non piu'
    // vecchia di 15 s.  Non scende mai sotto il punto da cui il relay e'
    // partito, altrimenti una posizione stantia di una sessione precedente
    // bloccherebbe subito una nuova riproduzione.
    $clientSnapshot=is_file($clientFile)?json_decode((string)@file_get_contents($clientFile),true):null;
    if(is_array($clientSnapshot)&&isset($clientSnapshot['position'])&&(float)($clientSnapshot['updated']??0)>=microtime(true)-15.0){
        $clientPosition=max($relaySourceStart,(float)$clientSnapshot['position']);
        $clientPositionAt=(float)$clientSnapshot['updated'];
    }else{
        $clientPosition=max($relaySourceStart,$clientPosition);
    }
    $windowWaiting=false;
    foreach($available as $fragment){
        $fragmentStart=(float)($fragment['start']??0);
        $fragmentDuration=(float)($fragment['duration']??2);
        if($fragmentStart<$nextStart-0.001)continue;
        if($fragmentStart>$nextStart+0.5){header('X-OpenAP-VOD-Cache-Gap: '.number_format($nextStart,3,'.','').'-'.number_format($fragmentStart,3,'.',''));return;}
        if($fragmentStart-$clientPosition>$relayWindowAheadSeconds){$windowWaiting=true;break;}
        $file=$cacheDirectory.'/segments/'.basename((string)($fragment['file']??''));
        if(!is_file($file))break;
        $fragmentBytes=(int)filesize($file);
        if(!$sendFile($file))break;
        $servedFragments[]=['file'=>basename((string)($fragment['file']??'')),'start'=>$fragmentStart,'duration'=>$fragmentDuration,'bytes'=>$fragmentBytes,'offset'=>$servedBytes];
        if(count($servedFragments)>64)array_shift($servedFragments);
        $servedBytes+=$fragmentBytes;
        $nextStart=$fragmentStart+$fragmentDuration;
    }
    $relayPublish();
    $status=is_file($statusFile)?json_decode((string)@file_get_contents($statusFile),true):null;
    // La risposta termina solo quando il produttore ha finito E non resta nulla
    // da servire: una attesa sulla finestra non e' una fine dello stream.
    if(is_array($status)&&(!empty($status['complete'])||!empty($status['cache_limit'])||!empty($status['error']))&&$nextStart>=$availableEnd-0.001)break;
    if(connection_aborted())break;
    usleep(100000);
}
return;
                }
            }
            // The cache remains alive in the background, but playback must not
            // remain blocked waiting for its first fragment.  Fall through to
            // the normal direct relay from the requested timestamp.
            $cacheExperiment=false;
        }
        if(preg_match('/^[a-f0-9]{32}$/',$session)&&preg_match('/^[0-9]{1,20}$/',$itemId)){$proxyRuntime='/run/openap-iptv-vod-remux/'.$session;if(@mkdir($proxyRuntime,0700,true)){ $proxyArgs=['/usr/bin/php','/usr/local/lib/openap/iptv_vod_proxy.php',$itemId,$session,openapAptvVodKind((string)($_GET['kind']??''))];$requestedProviderId=trim((string)($_GET['provider_id']??''));if($requestedProviderId!=='')$proxyArgs[]=$requestedProviderId;$proxyProcess=@proc_open($proxyArgs,[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',$proxyRuntime.'/proxy.log','a']],$proxyPipes);if(is_resource($proxyProcess)){if(isset($proxyPipes[0]))fclose($proxyPipes[0]);if(isset($proxyPipes[1]))fclose($proxyPipes[1]);if(isset($proxyPipes[2]))fclose($proxyPipes[2]);$deadline=microtime(true)+5;while(microtime(true)<$deadline){$proxyPort=(int)@file_get_contents($proxyRuntime.'/source.port');if($proxyPort>0)break;usleep(50000);}if($proxyPort<1)$stopProxy();}else{$proxyRuntime=null;}}else{$proxyRuntime=null;}}
        $inputUrl=$proxyPort>0?'http://127.0.0.1:'.$proxyPort.'/movie':$url;$command=['/usr/bin/ffmpeg','-nostdin','-hide_banner','-loglevel','error'];$titleKeyframes=$ephemeralPilot?openapAptvTitleKeyframeIndex($itemId):null;$seekOrigin=$ephemeralPilot?($cuesStrictPilot?$cuesStrictOrigin:(is_array($titleKeyframes)?openapAptvTitleKeyframeOrigin($titleKeyframes,(float)$start):max(0,$start-2))):max(0,$start-2);$seekOptions=$start>0?['-ss',(string)$seekOrigin,'-noaccurate_seek']:[];if($proxyPort<1){$proxy=openapAptvProviderProxy();if($proxy!==null)$command=array_merge($command,['-http_proxy',$proxy]);}$codecOptions=$progressiveVideoTranscode?['-c:v','libx264','-preset','veryfast','-crf','23','-pix_fmt','yuv420p','-profile:v','main','-level','3.1','-g','48','-keyint_min','48','-sc_threshold','0']:['-c:v','copy'];if(!$progressiveVideoTranscode&&$progressiveVideoMode==='hevc')$codecOptions=array_merge($codecOptions,['-tag:v','hvc1']);if($progressiveAudioMode)$codecOptions=array_merge($codecOptions,['-c:a','aac','-b:a:0','192k','-ac','2','-ar','48000','-af','aresample=async=1']);else $codecOptions=array_merge($codecOptions,['-c:a','copy']);$readRate=$ephemeralPilot?[]:((string)($_GET['burst']??'')==='1'?[]:['-readrate','1','-readrate_initial_burst','8']);$command=array_merge($command,['-reconnect','1','-reconnect_streamed','1','-reconnect_at_eof','1','-reconnect_on_network_error','1','-reconnect_on_http_error','4xx,5xx','-reconnect_delay_max','5','-rw_timeout','20000000',...$seekOptions,...$readRate,'-i',$inputUrl,'-map','0:v:0','-map','0:a:0?'],$codecOptions,['-movflags',$ephemeralPilot?'+frag_keyframe+empty_moov+default_base_moof+omit_tfhd_offset':'frag_keyframe+empty_moov+default_base_moof']);if(!$ephemeralPilot)$command=array_merge($command,['-frag_duration','2000000']);$command=array_merge($command,['-flush_packets','1','-f','mp4','pipe:1']);
        if($cuesStrictPilot){$flushIndex=array_search('-flush_packets',$command,true);if($flushIndex!==false)array_splice($command,$flushIndex,0,['-frag_duration','2000000']);}
        register_shutdown_function($stopProxy);$writeStats(true);$process=@proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)||!isset($pipes[1])){if(is_resource($process))proc_close($process);$stopProxy();sodium_memzero($url);http_response_code(503);return;}
        if(isset($pipes[1]))stream_set_blocking($pipes[1],false);if(isset($pipes[2]))stream_set_blocking($pipes[2],false);$drainStderr=static function()use(&$ffmpegStderr,&$pipes):void{if(!isset($pipes[2])||!is_resource($pipes[2]))return;$part=stream_get_contents($pipes[2]);if(is_string($part)&&$part!=='')$ffmpegStderr=substr($ffmpegStderr.$part,-4096);};
        header('Content-Type: video/mp4');header('Cache-Control: private, no-store');header('Accept-Ranges: none');
        if($ephemeralPilot){
            $streamOrigin=$seekOrigin;$streamKeyframes=$titleKeyframes;$pending='';$initialization=[];$fragment=[];$trackInfo=null;
            $emitEphemeral=static function(string $bytes)use(&$transferBytes,&$lastOutputAt,&$transferUpdated,$writeStats):void{if($bytes==='')return;echo $bytes;$transferBytes+=strlen($bytes);$lastOutputAt=microtime(true);if($lastOutputAt-$transferUpdated>=1)$writeStats(true);if(ob_get_level()>0)@ob_flush();flush();};
            $consumeEphemeral=static function(string $chunk)use(&$pending,&$initialization,&$fragment,&$trackInfo,$streamOrigin,$streamKeyframes,$emitEphemeral,&$tfdtAbsolute,&$tfdtLast,&$tfdtLabel,&$tfdtDelta,&$tfdtFragments,&$tfdtError,$statsDirectory,$transferStarted,&$transferBytes,&$fragmentObservationCount):void{
                $pending.=$chunk;
                while(strlen($pending)>=8){
                    $size=(int)(unpack('N',substr($pending,0,4))[1]??0);$header=8;
                    if($size===1){if(strlen($pending)<16)return;$parts=unpack('N2',substr($pending,8,8));$size=(int)(($parts[1]??0)*4294967296+($parts[2]??0));$header=16;}
                    if($size<$header||strlen($pending)<$size)return;
                    $type=substr($pending,4,4);$box=substr($pending,0,$size);$pending=substr($pending,$size);
                    if($type==='moof'){
                        if($initialization){$trackInfo=openapAptvEphemeralTrackInfo(implode('',$initialization));$emitEphemeral(implode('',$initialization));$initialization=[];}
                        $fragment=[$box];continue;
                    }
                    if($fragment){$fragment[]=$box;if($type!=='mdat')continue;$complete=implode('',$fragment);$fragment=[];$info=openapAptvEphemeralFragmentInfo($complete,$trackInfo);$output=$complete;
                        if(is_array($info)){$label=$streamOrigin+(float)$info['seconds'];$pinned=openapAptvEphemeralPinTfdt($complete,$trackInfo,$label);$tfdtLabel=$label;$tfdtDelta=(float)($pinned['delta']??0);$tfdtLast=$label;$tfdtFragments++;if(($pinned['applied']??false)||($pinned['reason']??'')==='already_absolute'){$output=(string)$pinned['bytes'];$tfdtAbsolute=true;}else{$tfdtError=(string)($pinned['reason']??'pin_failed');}}
                        else{$tfdtError='fragment_parse_failed';$label=null;$pinned=['delta'=>0,'reason'=>'fragment_parse_failed'];}
                        if($statsDirectory!==null&&$fragmentObservationCount<12){$observation=openapAptvEphemeralFragmentStats($complete,$trackInfo);$observation['ready_at']=microtime(true);$observation['producer_elapsed']=$observation['ready_at']-$transferStarted;$observation['output_offset']=$transferBytes;$observation['output_bytes']=strlen($output);$observation['origin']=$streamOrigin;$observation['label']=$label;$observation['tfdt_relative']=is_array($info)?($info['seconds']??null):null;$observation['pin_delta']=(float)($pinned['delta']??0);$observation['pin_reason']=(string)($pinned['reason']??'');@file_put_contents($statsDirectory.'/fragments.ndjson',json_encode($observation,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);$fragmentObservationCount++;}
                        $emitEphemeral($output);continue;
                    }
                    $initialization[]=$box;
                }
            };
            while(true){$readSet=[$pipes[1]];$writeSet=[];$exceptSet=[];$ready=@stream_select($readSet,$writeSet,$exceptSet,1);$drainStderr();if($ready===false)break;if($ready>0){$chunk=@fread($pipes[1],262144);if($chunk!==false&&$chunk!=='')$consumeEphemeral($chunk);}if(is_resource($process)){$status=@proc_get_status($process);if(is_array($status)&&empty($status['running'])&&feof($pipes[1]))break;}$now=microtime(true);$reference=$lastOutputAt>0?$lastOutputAt:$transferStarted;if($now-$reference>=20.0){$streamError=$lastOutputAt>0?'Provider stream stalled':'Provider stream startup timeout';@proc_terminate($process);break;}if($now-$transferUpdated>=1)$writeStats(true);}
        }else{
            while(true){$readSet=[$pipes[1]];$writeSet=[];$exceptSet=[];$ready=@stream_select($readSet,$writeSet,$exceptSet,1);$drainStderr();if($ready===false)break;if($ready>0){$chunk=@fread($pipes[1],262144);if($chunk!==false&&$chunk!==''){echo $chunk;$transferBytes+=strlen($chunk);$lastOutputAt=microtime(true);if($lastOutputAt-$transferUpdated>=1)$writeStats(true);if(ob_get_level()>0)@ob_flush();flush();}}if(is_resource($process)){$status=@proc_get_status($process);if(is_array($status)&&empty($status['running'])&&feof($pipes[1]))break;}$now=microtime(true);$reference=$lastOutputAt>0?$lastOutputAt:$transferStarted;if($now-$reference>=20.0){$streamError=$lastOutputAt>0?'Provider stream stalled':'Provider stream startup timeout';@proc_terminate($process);break;}if($now-$transferUpdated>=1)$writeStats(true);}
        }
        $drainStderr();fclose($pipes[1]);if(isset($pipes[2]))fclose($pipes[2]);$ffmpegExit=proc_close($process);if(stripos($ffmpegStderr,'premature')!==false||stripos($ffmpegStderr,'read error')!==false)$streamError='Provider stream ended prematurely';elseif($ffmpegExit!==0)$streamError='FFmpeg exited with error';$writeStats(false);$stopProxy();sodium_memzero($url);return;
    }
    $range=trim((string)($_SERVER['HTTP_RANGE']??''));if($range!==''&&!preg_match('/^bytes=\d*-\d*$/',$range)){http_response_code(416);return;}
    $effectiveRange=$range!==''?substr($range,6):'0-';
    $headersSent=false;$curl=openapAptvCurl($url);curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>0,CURLOPT_BUFFERSIZE=>262144,CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headersSent):int{$length=strlen($line);$trim=trim($line);if(preg_match('~^HTTP/\S+\s+(\d{3})~i',$trim,$match)){http_response_code((int)$match[1]);}elseif(preg_match('/^(Content-Type|Content-Length|Content-Range):\s*(.+)$/i',$trim,$match)){header($match[1].': '.$match[2]);}elseif($trim===''&&!$headersSent){header('Accept-Ranges: bytes');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');$headersSent=true;}return $length;},CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk):int{echo $chunk;if(ob_get_level()>0)@ob_flush();flush();return strlen($chunk);}]);curl_setopt($curl,CURLOPT_RANGE,$effectiveRange);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);sodium_memzero($url);if(!$ok&&$status===0&&!headers_sent())http_response_code(502);
}

function openapAptvOpenUrl(string $token): ?string
{
    $binary = base64_decode(strtr($token, '-_', '+/'), true);
    $key = @file_get_contents('/var/lib/openap-iptv/provider.key');
    if (!is_string($binary) || strlen($binary) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !is_string($key)) return null;
    $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if (!is_string($plain)) return null;
    $payload = json_decode($plain, true); sodium_memzero($plain);
    $url = (string) ($payload['url'] ?? '');
    $parts = parse_url($url);
    return (int) ($payload['expires'] ?? 0) >= time() && is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http','https'], true) ? $url : null;
}

function openapAptvResolveUrl(string $base, string $relative): string
{
    if (preg_match('~^https?://~i', $relative)) return $relative;
    $parts = parse_url($base);
    $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($relative, '/')) return $origin . $relative;
    $directory = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/'));
    return $origin . $directory . $relative;
}

function openapAptvHlsPlaylist(): void
{
    $itemId = trim((string) ($_GET['id'] ?? ''));
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) {
        $providerId = (string) (openapAptvProviderCollection()['active_id'] ?? '');
    }
    if (!openapAptvProviderProfileById($providerId) || !openapAptvLiveChannel($itemId, $providerId)) { http_response_code(403); echo 'Channel unavailable'; return; }
    $forceNormalized=(string)($_GET['profile']??'')==='normalized';
    // A normalized retry follows a browser compatibility failure.  It does
    // not need the conservative six-segment warm-up used for a clean start:
    // the player has already discarded the copy session and is waiting for a
    // fresh timeline.  Keep this hint private to the retry path.
    $fastNormalized=$forceNormalized&&(string)($_GET['fast']??'')==='1';
    $profileBeforeRequest=openapAptvLiveCompatibilityProfile($itemId, $providerId);
    openapAptvPruneStaleHls();
    require_once __DIR__ . '/live_relay.php';
    $dir=openapLiveRelayKey($providerId,$itemId).'-hls';
    if(!is_dir($dir)&&!@mkdir($dir,0770)&&!is_dir($dir)){http_response_code(503);echo '[LIVE_HLS_STORAGE]';return;}
    touch($dir.'/heartbeat');
    openap_release_session();
    if(isset($_GET['segment'])){
        $segment=(string)$_GET['segment'];
        // FFmpeg uses the configured %09d pattern, but the first segments
        // can legitimately contain fewer than nine significant digits.
        if(!preg_match('/^(?:init\.mp4|seg-[0-9]{1,}\.(?:m4s|ts))$/D',$segment)||!is_file($dir.'/'.$segment)){http_response_code(404);return;}
        header('Content-Type: '.($segment==='init.mp4'||str_ends_with($segment,'.m4s')?'video/mp4':'video/mp2t'));header('Cache-Control: no-store');readfile($dir.'/'.$segment);return;
    }
    // A retry may explicitly request normalized while an older copy worker is
    // still holding the relay lock.  Compare the worker's actual profile as
    // well as the marker: the marker can be updated before the old worker has
    // exited, which would otherwise leave copy segments under a normalized
    // response header.
    $activeProfile=trim((string)@file_get_contents($dir.'/profile'));
    $normalizedRestartNeeded=($forceNormalized||$profileBeforeRequest==='normalized')&&$activeProfile!=='normalized';
    if($normalizedRestartNeeded&&is_file($dir.'/pid')){
        $pid=(int)trim((string)@file_get_contents($dir.'/pid'));
        if($pid>1&&function_exists('posix_kill')){
            @posix_kill($pid,15);
            $deadline=microtime(true)+2.5;
            do{if(!@posix_kill($pid,0))break;usleep(50000);}while(microtime(true)<$deadline);
            if(@posix_kill($pid,0)){@posix_kill($pid,9);usleep(100000);}
        }
        @unlink($dir.'/pid');
    }
    $lock=fopen($dir.'/lock','c');
    $lockReady=false;
    if($lock){
        $lockDeadline=microtime(true)+($normalizedRestartNeeded?($fastNormalized?3:8):0);
        do{
            if(flock($lock,LOCK_EX|LOCK_NB)){$lockReady=true;break;}
            if(!$normalizedRestartNeeded)break;
            usleep(50000);
        }while(microtime(true)<$lockDeadline);
    }
    if($lockReady){
        @unlink($dir.'/index.m3u8');
        if($normalizedRestartNeeded)@unlink($dir.'/profile');
        flock($lock,LOCK_UN);
        $workerCommand='nohup /usr/bin/php /usr/local/lib/openap-recording/iptv_live_hls.php '.escapeshellarg($providerId).' '.escapeshellarg($itemId);
        if($forceNormalized||$profileBeforeRequest==='normalized')$workerCommand.=' '.escapeshellarg('normalized');
        exec($workerCommand.' </dev/null >/dev/null 2>&1 &');
    }
    if($lock)fclose($lock);
    $requiresNormalized=$forceNormalized||$profileBeforeRequest==='normalized';
    if($requiresNormalized){
        $profileDeadline=microtime(true)+5;
        do{$activeProfile=trim((string)@file_get_contents($dir.'/profile'));if($activeProfile==='normalized')break;usleep(50000);}while(microtime(true)<$profileDeadline);
        if($activeProfile!=='normalized'){http_response_code(502);echo '[LIVE_HLS_PROFILE] Il worker normalized non è diventato attivo.';return;}
    }
    // Let copy-profile streams start quickly, but give normalized streams more
    // time to stabilize timestamps, GOP boundaries and initial jitter.
    $profile=trim((string)@file_get_contents($dir.'/profile'));
    if($profile!=='normalized'&&$profile!=='copy')$profile=openapAptvLiveCompatibilityProfile($itemId, $providerId);
    $minimumSegments=$profile==='normalized'?($fastNormalized?3:6):3;
    $until=microtime(true)+15;
    do{$body=@file_get_contents($dir.'/index.m3u8');if(is_string($body)&&substr_count($body,'#EXTINF:')>=$minimumSegments)break;usleep(100000);}while(microtime(true)<$until);
    if(!is_string($body)||substr_count($body,'#EXTINF:')<$minimumSegments){http_response_code(502);echo '[LIVE_HLS_START] Flusso HLS condiviso non disponibile.';return;}
    if($profile==='normalized')openapAptvMarkLiveNormalized($itemId, $providerId);
    $body=preg_replace_callback('/URI="(init\.mp4|seg-[0-9]+\.(?:m4s|ts))"/',static function($match)use($itemId,$providerId){return 'URI="/aptv/api/hls?id='.rawurlencode($itemId).'&provider_id='.rawurlencode($providerId).'&segment='.rawurlencode($match[1]).'"';},$body);
    $body=preg_replace_callback('/^(?:seg-[0-9]+\.(?:m4s|ts))$/m',static function($match)use($itemId,$providerId){return '/aptv/api/hls?id='.rawurlencode($itemId).'&provider_id='.rawurlencode($providerId).'&segment='.rawurlencode($match[0]);},$body);
    header('X-OpenAP-Live-Profile: '.$profile);header('Access-Control-Expose-Headers: X-OpenAP-Live-Profile');header('Content-Type: application/vnd.apple.mpegurl');header('Cache-Control: no-store');echo $body;
}

function openapAptvLiveFmp4(): void
{
    @set_time_limit(0);
    ignore_user_abort(false);
    $itemId=trim((string)($_GET['id']??''));
    if(!openapAptvLiveChannel($itemId)){http_response_code(403);echo 'Channel unavailable';return;}
    $collection=openapAptvProviderCollection();$provider=(string)($collection['active_id']??'');
    if($provider===''){http_response_code(409);echo 'Save the provider again to enable playback';return;}
    require_once __DIR__.'/live_relay.php';
    openapLiveFmp4RelayReset($provider,$itemId);
    openap_release_session();
    $headersSent=false;
    try{
        openapLiveFmp4RelayConsume($provider,$itemId,static function(string $chunk)use(&$headersSent):int{if(!$headersSent){header('Content-Type: video/mp4');header('Cache-Control: private, no-store');header('Accept-Ranges: none');header('X-Accel-Buffering: no');header('X-OpenAP-Live-Profile: live-fmp4');header('Access-Control-Expose-Headers: X-OpenAP-Live-Profile');$headersSent=true;}echo $chunk;if(ob_get_level()>0)@ob_flush();flush();return strlen($chunk);},static function():bool{return connection_aborted()!==0;});
    }catch(Throwable $error){
        if(!$headersSent){http_response_code(502);header('Content-Type: text/plain; charset=utf-8');}
        if(!connection_aborted())echo '[LIVE_FMP4] '.preg_replace('/\s+/',' ',trim($error->getMessage()));
    }
}

function openapAptvLiveFmp4Status(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $itemId=trim((string)($_GET['id']??''));
    if(!openapAptvLiveChannel($itemId)){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Channel unavailable']);return;}
    $collection=openapAptvProviderCollection();$provider=(string)($collection['active_id']??'');
    if($provider===''){http_response_code(409);echo json_encode(['ok'=>false,'message'=>'Provider unavailable']);return;}
    require_once __DIR__.'/live_relay.php';
    $base=openapLiveFmp4RelayKey($provider,$itemId);$state=json_decode((string)@file_get_contents($base.'.json'),true);
    if(!is_array($state))$state=[];
    echo json_encode(['ok'=>true,'state'=>(string)($state['state']??'idle'),'output_bytes'=>(int)($state['output_bytes']??0),'last_output_at'=>(string)($state['last_output_at']??''),'output_gap'=>(float)($state['output_gap']??0),'slow_clients'=>(int)($state['slow_clients']??0),'peak_queue_bytes'=>(int)($state['peak_queue_bytes']??0),'error'=>substr((string)($state['error']??''),0,500)],JSON_UNESCAPED_SLASHES);
}

function openapAptvHlsSegment(): void
{
    $url = openapAptvOpenUrl((string) ($_GET['token'] ?? ''));
    if (!$url) { http_response_code(403); echo 'Invalid segment'; return; }
    openap_release_session(); header('Content-Type: video/mp2t'); header('Cache-Control: no-store'); header('X-Accel-Buffering: no');
    $curl = openapAptvCurl($url);
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'APtv-HLS/0.1',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk):int{echo $chunk;if(ob_get_level()>0)@ob_flush();flush();return strlen($chunk);}]);
    curl_exec($curl); curl_close($curl);
}

function openapAptvProxyLiveStream(): void
{
    @set_time_limit(0);
    ignore_user_abort(false);
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) {
        http_response_code(422); echo 'Invalid channel'; return;
    }
    $requestedProviderId = trim((string) ($_GET['provider_id'] ?? ''));
    $context = openapAptvProviderContext($requestedProviderId);
    if (!is_array($context)) {
        http_response_code(403); echo 'Provider unavailable'; return;
    }
    $providerId = (string) ($context['id'] ?? '');
    try {
        $db = new SQLite3((string) ($context['catalog'] ?? ''), SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT name FROM items WHERE kind='live' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $channel = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
    } catch (Throwable $error) {
        http_response_code(503); echo 'Catalog unavailable'; return;
    }
    if (!is_array($channel)) {
        http_response_code(403); echo 'Channel unavailable'; return;
    }
    $profile = (array) ($context['profile'] ?? []);
    if (!$profile) {
        http_response_code(409); echo 'Save the provider again to enable playback'; return;
    }
    $baseUrl = rtrim((string) ($profile['url'] ?? ''), '/');
    $username = (string) ($profile['username'] ?? '');
    $password = (string) ($profile['password'] ?? '');
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http','https'], true) || $username === '' || $password === '') {
        http_response_code(500); echo 'Invalid provider profile'; return;
    }
    $upstream = $baseUrl . '/live/' . rawurlencode($username) . '/' . rawurlencode($password) . '/' . $itemId . '.ts';
    openap_release_session();
    header('Content-Type: video/mp2t');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    require_once __DIR__ . '/live_relay.php';
    try {
        openapLiveRelayConsume($providerId,$itemId,
            static function(string $chunk):int {echo $chunk;if(ob_get_level()>0)@ob_flush();flush();return strlen($chunk);},
            static function():bool {return connection_aborted()!==0;});
    } catch(Throwable $error) {if(!headers_sent())http_response_code(502);}
    sodium_memzero($password);
}

function openapAptvTranscodeHevcStream(): void
{
    openap_release_session();
    @set_time_limit(0);
    ignore_user_abort(false);
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $itemId)) {
        http_response_code(422); echo 'Invalid channel'; return;
    }
    try {
        $db = new SQLite3('/var/lib/openap-iptv/catalog.db', SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT name FROM items WHERE kind='live' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $channel = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
    } catch (Throwable $error) {
        http_response_code(503); echo 'Catalog unavailable'; return;
    }
    $channelName = is_array($channel) ? (string) ($channel['name'] ?? '') : '';
    if ($channelName === '' || !preg_match('/(?:HEVC|H[. ]?265)/i', $channelName)) {
        http_response_code(403); echo 'This channel is unavailable or does not require HEVC conversion'; return;
    }
    $profile = openapAptvProviderProfile();
    if (!$profile) {
        http_response_code(409); echo 'Provider unavailable'; return;
    }
    $baseUrl = rtrim((string) ($profile['url'] ?? ''), '/');
    $username = (string) ($profile['username'] ?? '');
    $password = (string) ($profile['password'] ?? '');
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || $username === '' || $password === '') {
        sodium_memzero($password);
        http_response_code(500); echo 'Invalid provider profile'; return;
    }
    $upstream = $baseUrl . '/live/' . rawurlencode($username) . '/' . rawurlencode($password) . '/' . $itemId . '.ts';
    openap_release_session();
    header('Content-Type: video/x-flv');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');

    $command = [
        '/usr/bin/ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
        '-fflags', 'nobuffer', '-flags', 'low_delay', '-probesize', '2000000',
        '-analyzeduration', '2000000', '-i', 'pipe:0', '-map', '0:v:0', '-map', '0:a:0?',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-tune', 'zerolatency',
        '-pix_fmt', 'yuv420p', '-profile:v', 'main', '-g', '50', '-keyint_min', '50',
        '-sc_threshold', '0', '-c:a', 'copy', '-f', 'flv', '-flvflags', 'no_duration_filesize', 'pipe:1',
    ];
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
    if (!is_resource($process) || !isset($pipes[0], $pipes[1])) {
        sodium_memzero($password);
        http_response_code(503); echo 'Transcoder unavailable'; return;
    }
    $curlPipes = [];
    $curlCommand = [
        '/usr/bin/curl', '--silent', '--show-error', '--fail', '--location',
        '--max-redirs', '5', '--connect-timeout', '12', '--speed-limit', '512',
        '--speed-time', '20', '--user-agent', 'APtv-transcoder/0.2', '--config', '-',
    ];
    $curlProcess = @proc_open($curlCommand, [0 => ['pipe', 'r'], 1 => $pipes[0], 2 => ['file', '/dev/null', 'a']], $curlPipes);
    if (!is_resource($curlProcess) || !isset($curlPipes[0])) {
        sodium_memzero($password);
        fclose($pipes[0]); fclose($pipes[1]); proc_terminate($process); proc_close($process);
        http_response_code(503); echo 'Provider transport unavailable'; return;
    }
    $escapedUpstream = str_replace(['\\', '"'], ['\\\\', '\\"'], $upstream);
    $curlConfig = 'url = "' . $escapedUpstream . '"' . "\n";
    fwrite($curlPipes[0], $curlConfig);
    fclose($curlPipes[0]);
    sodium_memzero($curlConfig);
    sodium_memzero($escapedUpstream);
    sodium_memzero($upstream);
    sodium_memzero($password);
    if (isset($profile['password']) && is_string($profile['password'])) sodium_memzero($profile['password']);
    fclose($pipes[0]);
    while (!feof($pipes[1]) && !connection_aborted()) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) break;
        if ($chunk === '') continue;
        echo $chunk;
        if (ob_get_level() > 0) @ob_flush();
        flush();
    }
    fclose($pipes[1]);
    proc_terminate($curlProcess);
    proc_close($curlProcess);
    proc_terminate($process);
    proc_close($process);
}

function openapAptvHevcHlsJobDirectory(string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    return '/run/openap-iptv-hls/' . $token;
}

function openapAptvStartHevcHls(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $itemId) || !openapAptvLiveChannel($itemId)) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => _('Channel unavailable.')]); return;
    }
    try {
        $db = new SQLite3('/var/lib/openap-iptv/catalog.db', SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT name FROM items WHERE kind='live' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $channel = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
    } catch (Throwable $error) { $channel = false; }
    if (!is_array($channel) || !preg_match('/(?:HEVC|H[. ]?265)/i', (string) ($channel['name'] ?? ''))) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('This channel does not require HEVC conversion.')]); return;
    }
    $base = '/run/openap-iptv-hls';
    if ((!is_dir($base) && !@mkdir($base, 0700, true)) || !is_writable($base)) {
        http_response_code(503); echo json_encode(['ok' => false, 'message' => _('Unable to prepare the HLS transcoder.')]); return;
    }
    $token = bin2hex(random_bytes(16));
    $directory = $base . '/' . $token;
    if (!@mkdir($directory, 0700)) {
        http_response_code(503); echo json_encode(['ok' => false, 'message' => _('Unable to create the HLS session.')]); return;
    }
    touch($directory . '/heartbeat');
    $process = @proc_open(
        ['/usr/bin/setsid', '-f', '/usr/bin/php', '/usr/local/lib/openap/iptv_hls_worker.php', $itemId, $token],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($directory . '/heartbeat'); @rmdir($directory);
        http_response_code(503); echo json_encode(['ok' => false, 'message' => _('Unable to start the HLS transcoder.')]); return;
    }
    proc_close($process);
    $manifest = $directory . '/index.m3u8';
    $deadline = microtime(true) + 12.0;
    while (microtime(true) < $deadline && (!is_file($manifest) || filesize($manifest) < 40)) usleep(100000);
    clearstatcache(true, $manifest);
    if (!is_file($manifest) || filesize($manifest) < 40) {
        touch($directory . '/stop');
        http_response_code(504); echo json_encode(['ok' => false, 'message' => _('The converted channel did not start in time.')]); return;
    }
    echo json_encode(['ok' => true, 'token' => $token, 'playlist' => '/aptv/api/transcode/playlist?token=' . $token]);
}

function openapAptvServeHevcHlsPlaylist(): void
{
    $token = (string) ($_GET['token'] ?? '');
    $directory = openapAptvHevcHlsJobDirectory($token);
    $manifest = $directory !== null ? $directory . '/index.m3u8' : '';
    if ($directory === null || !is_readable($manifest)) { http_response_code(404); return; }
    touch($directory . '/heartbeat');
    $body = (string) file_get_contents($manifest);
    $lines = preg_split('/\R/', $body) ?: [];
    foreach ($lines as &$line) {
        $name = trim($line);
        if ($name !== '' && $name[0] !== '#' && preg_match('/^segment_[0-9]+\.ts$/', $name)) {
            $line = '/aptv/api/transcode/segment?token=' . $token . '&file=' . rawurlencode($name);
        }
    }
    unset($line);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store');
    echo implode("\n", $lines);
}

function openapAptvServeHevcHlsSegment(): void
{
    $token = (string) ($_GET['token'] ?? '');
    $file = (string) ($_GET['file'] ?? '');
    $directory = openapAptvHevcHlsJobDirectory($token);
    if ($directory === null || !preg_match('/^segment_[0-9]+\.ts$/', $file)) { http_response_code(404); return; }
    $path = $directory . '/' . $file;
    if (!is_readable($path)) { http_response_code(404); return; }
    touch($directory . '/heartbeat');
    header('Content-Type: video/mp2t');
    header('Cache-Control: private, max-age=10');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function openapAptvStopHevcHls(): void
{
    $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
    $directory = openapAptvHevcHlsJobDirectory($token);
    if ($directory !== null && is_dir($directory)) touch($directory . '/stop');
    http_response_code(204);
}

function openapAptvWebrtcDirectory(string $token): ?string
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? '/run/openap-iptv-webrtc/' . $token : null;
}

function openapAptvStartWebrtc(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $id=trim((string)($_GET['id']??''));
    $requestedProviderId=trim((string)($_GET['provider_id']??''));
    $context=openapAptvProviderContext($requestedProviderId);
    $providerId=is_array($context)?(string)($context['id']??''):'';
    if(!preg_match('/^[0-9]{1,20}$/',$id)||!is_array($context)||!openapAptvLiveChannel($id,$providerId)){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Channel unavailable.')]);return;}
    $base='/run/openap-iptv-webrtc';
    if((!is_dir($base)&&!@mkdir($base,0700,true))||!is_writable($base)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('WebRTC runtime unavailable.')]);return;}
    $token=bin2hex(random_bytes(16));$dir=$base.'/'.$token;
    if(!@mkdir($dir,0700)){http_response_code(503);echo json_encode(['ok'=>false]);return;}
    touch($dir.'/heartbeat');
    $p=@proc_open(['/usr/bin/setsid','-f','/usr/bin/php','/usr/local/lib/openap/iptv_webrtc_worker.php',$id,$token,'live',$providerId],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
    if(!is_resource($p)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('Unable to start WebRTC.')]);return;}proc_close($p);
    $deadline=microtime(true)+8;while(microtime(true)<$deadline&&!is_file($dir.'/ready'))usleep(100000);
    if(!is_file($dir.'/ready')){touch($dir.'/stop');http_response_code(504);echo json_encode(['ok'=>false,'message'=>_('WebRTC stream did not start in time.')]);return;}
    echo json_encode(['ok'=>true,'token'=>$token,'path'=>$token]);
}
function openapAptvStartVodWebrtc(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');$id=trim((string)($_GET['id']??''));$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$context=openapAptvProviderContextForRequest($providerId!==''?$providerId:null);$start=max(0,min(86400,(int)($_GET['start']??0)));$audio=max(0,min(31,(int)($_GET['audio']??0)));$vod=openapAptvVod($id,$kind,$providerId!==''?$providerId:null);if(!$vod){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Movie unavailable.')]);return;}$profile=is_array($context)?(array)($context['profile']??[]):null;if(!$profile){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}$password=(string)($profile['password']??'');$extension=preg_replace('/[^a-z0-9]/','',strtolower((string)($vod['container_extension']??'mkv')));$sourceUrl=rtrim((string)$profile['url'],'/').'/'.openapAptvVodSourcePath($vod).'/'.rawurlencode((string)$profile['username']).'/'.rawurlencode($password).'/'.$id.'.'.$extension;$sourceToken=openapAptvSealUrl($sourceUrl,28800);sodium_memzero($sourceUrl);sodium_memzero($password);
    $base='/run/openap-iptv-webrtc';if((!is_dir($base)&&!@mkdir($base,0700,true))||!is_writable($base)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('WebRTC runtime unavailable.')]);return;}$token=bin2hex(random_bytes(16));$dir=$base.'/'.$token;if(!@mkdir($dir,0700)){http_response_code(503);echo json_encode(['ok'=>false]);return;}touch($dir.'/heartbeat');
    $processArgs=['/usr/bin/setsid','-f','/usr/bin/php','/usr/local/lib/openap/iptv_webrtc_worker.php',$id,$token,'vod',(string)$start,(string)$audio,$sourceToken,$kind];if($providerId!=='')$processArgs[]=$providerId;$process=@proc_open($processArgs,[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);if(!is_resource($process)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('Unable to start movie playback.')]);return;}proc_close($process);$deadline=microtime(true)+20;while(microtime(true)<$deadline&&!is_file($dir.'/ready'))usleep(100000);if(!is_file($dir.'/ready')){if(is_dir($dir))touch($dir.'/stop');http_response_code(504);echo json_encode(['ok'=>false,'message'=>_('The movie stream did not start in time.')]);return;}echo json_encode(['ok'=>true,'token'=>$token,'path'=>$token]);
}
function openapAptvVodHlsDirectory(string $token): ?string
{
    return preg_match('/^[a-f0-9]{32}$/',$token)?'/run/openap-iptv-vod-hls/'.$token:null;
}
function openapAptvVodAudioHlsDirectory(string $token): ?string
{
    return preg_match('/^[a-f0-9]{32}$/',$token)?'/run/openap-iptv-vod-hls/'.$token:null;
}
function openapAptvStartVodAudioHls(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');
    $id=trim((string)($_GET['id']??''));$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$start=max(0,min(86400,(int)($_GET['start']??0)));$audio=max(0,min(31,(int)($_GET['audio']??0)));
    $vod=openapAptvVod($id,$kind,$providerId!==''?$providerId:null);if(!$vod&&$providerId==='')$vod=openapAptvItalianVod($id);if(!$vod){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Hybrid audio is unavailable for this movie.')]);return;}
    $base='/run/openap-iptv-vod-hls';if((!is_dir($base)&&!@mkdir($base,0700,true))||!is_writable($base)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('VOD audio runtime unavailable.')]);return;}
    $token=bin2hex(random_bytes(16));$directory=$base.'/'.$token;if(!@mkdir($directory,0700)){http_response_code(503);echo json_encode(['ok'=>false]);return;}touch($directory.'/heartbeat');
    $processArgs=['/usr/bin/setsid','-f','/usr/bin/php','/usr/local/lib/openap/iptv_vod_audio_hls_worker.php',$id,$token,(string)$start,(string)$audio,'hybrid',$kind];if($providerId!=='')$processArgs[]=$providerId;$process=@proc_open($processArgs,[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
    if(!is_resource($process)){@rmdir($directory);http_response_code(503);echo json_encode(['ok'=>false]);return;}proc_close($process);
    $manifest=$directory.'/index.m3u8';$deadline=microtime(true)+35;while(microtime(true)<$deadline&&(!is_file($manifest)||filesize($manifest)<40)){if(is_file($directory.'/failed'))break;usleep(100000);}clearstatcache(true,$manifest);
    if(!is_file($manifest)||filesize($manifest)<40){touch($directory.'/stop');echo json_encode(['ok'=>false,'message'=>_('The selected audio track did not start in time.')]);return;}
    echo json_encode(['ok'=>true,'token'=>$token,'playlist'=>openapAptvStreamUrl('/aptv/api/vod/audio-hls/playlist?'.http_build_query(['token'=>$token]))],JSON_UNESCAPED_SLASHES);
}
function openapAptvServeVodAudioHlsPlaylist(): void
{
    $token=(string)($_GET['token']??'');$directory=openapAptvVodAudioHlsDirectory($token);$manifest=$directory?$directory.'/index.m3u8':'';
    if(!$directory||!is_readable($manifest)){http_response_code(404);return;}touch($directory.'/heartbeat');$lines=preg_split('/\R/',trim((string)file_get_contents($manifest)))?:[];
    foreach($lines as &$line){$name=trim($line);if($name!==''&&$name[0]!=='#'&&preg_match('/^segment_[0-9]+\.ts$/',$name))$line=openapAptvStreamUrl('/aptv/api/vod/audio-hls/segment?'.http_build_query(['token'=>$token,'file'=>$name]));}unset($line);
    header('Content-Type: application/vnd.apple.mpegurl');header('Cache-Control:no-store');echo implode("\n",$lines)."\n";
}
function openapAptvServeVodAudioHlsSegment(): void
{
    $token=(string)($_GET['token']??'');$file=(string)($_GET['file']??'');$directory=openapAptvVodAudioHlsDirectory($token);
    if(!$directory||!preg_match('/^segment_[0-9]+\.ts$/',$file)||!is_readable($directory.'/'.$file)){http_response_code(404);return;}touch($directory.'/heartbeat');$path=$directory.'/'.$file;header('Content-Type:video/mp2t');header('Cache-Control:private,max-age=30');header('Content-Length:'.filesize($path));readfile($path);
}
function openapAptvVodRemuxDirectory(string $token): ?string
{
    return preg_match('/^[a-f0-9]{32}$/',$token)?'/run/openap-iptv-vod-remux/'.$token:null;
}
function openapAptvServeVodAudioRemux(): void
{
    openap_release_session();
    header('Content-Type:application/json;charset=utf-8');header('Cache-Control:no-store');$id=trim((string)($_GET['id']??''));$start=max(0,min(86400,(int)($_GET['start']??0)));$audio=max(0,min(31,(int)($_GET['audio']??0)));$vod=openapAptvItalianVod($id);$profile=openapAptvProviderProfile();if($id!=='160289'||!$vod||!$profile){http_response_code(403);echo json_encode(['ok'=>false]);return;}$password=(string)($profile['password']??'');$extension=preg_replace('/[^a-z0-9]/','',strtolower((string)($vod['container_extension']??'mkv')));$source=rtrim((string)$profile['url'],'/').'/movie/'.rawurlencode((string)$profile['username']).'/'.rawurlencode($password).'/'.$id.'.'.$extension;$sealed=openapAptvSealUrl($source,7200);sodium_memzero($source);sodium_memzero($password);echo json_encode(['ok'=>true,'url'=>openapAptvStreamUrl('/aptv/vod-audio?'.http_build_query(['token'=>$sealed,'start'=>$start,'audio'=>$audio]))],JSON_UNESCAPED_SLASHES);
}
function openapAptvVodProbeCacheFile(string $id, string $requestedKind = '', ?string $requestedProviderId = null): ?string
{
    $context = openapAptvProviderContextForRequest($requestedProviderId);
    $profile = is_array($context) ? (array) ($context['profile'] ?? []) : null;
    $providerId = is_array($context) ? (string) ($context['id'] ?? '') : '';
    if (!$profile || $providerId === '') return null;
    $kind=openapAptvVodKind($requestedKind !== '' ? $requestedKind : (string)($_GET['kind']??''));
    if ($kind === '') $kind = 'vod';
    return '/var/cache/openap-iptv/vod-probes/'.hash('sha256',$providerId."\0".(string)$profile['url']."\0".(string)$profile['username']."\0".$kind."\0".$id).'.json';
}
function openapAptvVodCachedProbe(string $id, string $requestedKind = '', ?string $requestedProviderId = null): ?array
{
    $cache=openapAptvVodProbeCacheFile($id,$requestedKind,$requestedProviderId);if(!$cache)return null;
    $probe=is_file($cache)?json_decode((string)file_get_contents($cache),true):null;
    return is_array($probe)?$probe:null;
}
function openapAptvVodProgressiveCompatible(array $probe): bool
{
    $container=strtolower((string)($probe['container']??''));
    $video=is_array($probe['video']??null)?$probe['video']:[];
    $videoCodec=strtolower((string)($video['codec']??''));
    $audio=is_array($probe['audio']??null)?$probe['audio']:[];
    $firstAudio=is_array($audio[0]??null)?$audio[0]:[];
    $audioCodec=strtolower((string)($firstAudio['codec']??''));
    $sourceKind=(string)($probe['source_kind']??'');
    $width=(int)($video['width']??0);$height=(int)($video['height']??0);
    // MPEG-4 ASP video is transcoded to browser-safe H.264/AAC by the
    // progressive relay. This covers the provider's AVI/MPEG-4 movie class
    // without widening any other unsupported codec combination.
    if($sourceKind==='movie'&&$videoCodec==='mpeg4'&&$audioCodec==='aac'&&$width>0&&$height>0)return true;
    if(!in_array($videoCodec,['h264','hevc'],true))return false;
    if(!in_array($audioCodec,['aac','mp3','ac3','eac3','dts','flac','opus','vorbis','truehd'],true))return false;
    if(!in_array($sourceKind,['movie','series'],true)||!in_array($container,['mkv','mp4','m4v'],true))return false;
    $pixelFormat=strtolower((string)($video['pixel_format']??''));
    if($videoCodec==='hevc')return $width>0&&$height>0&&in_array($pixelFormat,['yuv420p','yuv420p10le','yuvj420p','yuvj420p10le'],true);
    if($sourceKind==='movie'&&in_array($container,['mp4','m4v'],true))return true;
    $channels=(int)($firstAudio['channels']??0);
    if($videoCodec==='h264'&&$sourceKind==='series')return $width>0&&$height>0&&$channels>=1&&$channels<=8&&in_array($pixelFormat,['yuv420p','yuvj420p'],true);
    // MKV movies with H.264/AAC (including ordinary HD/SD titles) can use
    // the same progressive fMP4 path; resolution is not a transport limit.
    return $videoCodec==='h264'&&$sourceKind==='movie'&&$width>0&&$height>0&&$channels>=1&&$channels<=8&&in_array($pixelFormat,['yuv420p','yuvj420p'],true);
}
function openapAptvVodPlaybackClass(array $probe): string
{
    if(openapAptvVodProgressiveCompatible($probe))return 'direct';
    $video=strtolower((string)($probe['video']['codec']??''));$audio=is_array($probe['audio']??null)?$probe['audio']:[];
    foreach($audio as $track){$trackCodec=strtolower((string)($track['codec']??''));if(in_array($trackCodec,['aac','mp3'],true)&&in_array($video,['h264','hevc'],true))return 'hls_remux';if(in_array($trackCodec,['aac','mp3'],true)&&$video==='mpeg4')return 'hls_video_transcode';}
    foreach($audio as $track){$codec=strtolower((string)($track['codec']??''));if(($video==='h264'&&in_array($codec,['dts','ac3','eac3'],true))||($video==='hevc'&&in_array($codec,['ac3','eac3'],true)))return 'hls_audio_transcode';}
    return 'unsupported';
}
function openapAptvStartVodHls(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control:no-store');
    $id=trim((string)($_GET['id']??''));$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$start=max(0,min(86400,(int)($_GET['start']??0)));$audio=max(0,min(31,(int)($_GET['audio']??0)));
    if(!openapAptvVod($id,$kind,$providerId!==''?$providerId:null)){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Movie unavailable.')]);return;}
    $probe=openapAptvVodCachedProbe($id,$kind,$providerId!==''?$providerId:null);$tracks=is_array($probe['audio']??null)?$probe['audio']:[];$audioCodec=strtolower((string)($tracks[$audio]['codec']??''));$videoCodec=strtolower((string)($probe['video']['codec']??''));$audioTranscodeProfile=($videoCodec==='h264'&&in_array($audioCodec,['dts','ac3','eac3'],true))||($videoCodec==='hevc'&&in_array($audioCodec,['ac3','eac3'],true));$videoTranscodeProfile=$videoCodec==='mpeg4'&&in_array($audioCodec,['aac','mp3'],true);
    if(!$probe){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Analyze this movie before playback.')]);return;}
    if(!$audioTranscodeProfile&&!$videoTranscodeProfile&&(!in_array($videoCodec,['h264','hevc'],true)||!in_array($audioCodec,['aac','mp3'],true))){http_response_code(415);echo json_encode(['ok'=>false,'message'=>_('This movie format is not supported without transcoding.')]);return;}
    $base='/run/openap-iptv-vod-hls';if((!is_dir($base)&&!@mkdir($base,0700,true))||!is_writable($base)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('VOD streaming runtime unavailable.')]);return;}
    $previous=[];foreach(glob($base.'/*/item.id')?:[]as$marker){if(trim((string)@file_get_contents($marker))===$id){$old=dirname($marker);$pid=(int)@file_get_contents($old.'/worker.pid');if($pid<2||!@posix_kill($pid,0)){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as$entry)$entry->isDir()?@rmdir($entry->getPathname()):@unlink($entry->getPathname());@rmdir($old);continue;}@touch($old.'/stop');$previous[]=$old;}}$stopDeadline=microtime(true)+3;while($previous&&microtime(true)<$stopDeadline){$previous=array_values(array_filter($previous,'is_dir'));if($previous)usleep(50000);}
    $token='';$directory='';$manifest='';for($attempt=0;$attempt<2;$attempt++){$token=bin2hex(random_bytes(16));$directory=$base.'/'.$token;if(!@mkdir($directory,0700)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('Unable to create the VOD session.')]);return;}file_put_contents($directory.'/item.id',$id,LOCK_EX);chmod($directory.'/item.id',0600);touch($directory.'/heartbeat');
    $processArgs=['/usr/bin/setsid','-f','/usr/bin/php','/usr/local/lib/openap/iptv_vod_hls_worker.php',$id,$token,(string)$start,(string)$audio,$kind];if($providerId!=='')$processArgs[]=$providerId;$process=@proc_open($processArgs,[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
    if(!is_resource($process)){@rmdir($directory);http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('Unable to prepare the VOD stream.')]);return;}proc_close($process);
    $manifest=$directory.'/master.m3u8';$deadline=microtime(true)+45;while(microtime(true)<$deadline&&(!is_file($manifest)||filesize($manifest)<40)){if(is_file($directory.'/failed'))break;usleep(100000);}clearstatcache(true,$manifest);if(is_file($manifest)&&filesize($manifest)>=40)break;if($attempt===0&&is_file($directory.'/failed')){@touch($directory.'/stop');usleep(750000);continue;}break;}
    if(!is_file($manifest)||filesize($manifest)<40){touch($directory.'/stop');http_response_code(504);echo json_encode(['ok'=>false,'message'=>_('The movie stream did not start in time.')]);return;}
    echo json_encode(['ok'=>true,'token'=>$token,'playlist'=>openapAptvStreamUrl('/aptv/api/vod/hls/playlist?'.http_build_query(['token'=>$token,'file'=>'master.m3u8']))],JSON_UNESCAPED_SLASHES);
}
function openapAptvServeVodHlsPlaylist(): void
{
    $token=(string)($_GET['token']??'');$file=(string)($_GET['file']??'master.m3u8');$directory=openapAptvVodHlsDirectory($token);$manifest=$directory&&preg_match('~^(?:master\.m3u8|(?:video|audio[0-9]+)/index\.m3u8)$~',$file)?$directory.'/'.$file:'';
    if(!$directory||!is_readable($manifest)){http_response_code(404);return;}touch($directory.'/heartbeat');$lines=preg_split('/\R/',trim((string)file_get_contents($manifest)))?:[];
    foreach($lines as &$line){if($file==='master.m3u8'){$line=preg_replace_callback('/URI="([^"]+\.m3u8)"/',static fn($m)=>'URI="'.openapAptvStreamUrl('/aptv/api/vod/hls/playlist?'.http_build_query(['token'=>$token,'file'=>$m[1]])).'"',$line);$name=trim($line);if($name!==''&&$name[0]!=='#'&&str_ends_with($name,'.m3u8'))$line=openapAptvStreamUrl('/aptv/api/vod/hls/playlist?'.http_build_query(['token'=>$token,'file'=>$name]));}else{$name=trim($line);if($name!==''&&$name[0]!=='#'&&preg_match('/^segment_[0-9]+\.ts$/',$name))$line=openapAptvStreamUrl('/aptv/api/vod/hls/segment?'.http_build_query(['token'=>$token,'file'=>dirname($file).'/'.$name]));}}unset($line);
    header('Content-Type: application/vnd.apple.mpegurl');header('Cache-Control:no-store');echo implode("\n",$lines)."\n";
}
function openapAptvServeVodHlsSegment(): void
{
    $token=(string)($_GET['token']??'');$file=(string)($_GET['file']??'');$directory=openapAptvVodHlsDirectory($token);
    if(!$directory||!preg_match('~^(?:video|audio[0-9]+)/segment_[0-9]+\.ts$~',$file)||!is_readable($directory.'/'.$file)){http_response_code(404);return;}touch($directory.'/heartbeat');$path=$directory.'/'.$file;header('Content-Type:video/mp2t');header('Cache-Control:private,max-age=30');header('Content-Length:'.filesize($path));readfile($path);
}
function openapAptvStopVodHls(): void
{
    $directory=openapAptvVodHlsDirectory((string)($_POST['token']??''));if($directory&&is_dir($directory))touch($directory.'/stop');http_response_code(204);
}
function openapAptvVodProbe(): void
{
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    $id=trim((string)($_GET['id']??''));$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$context=openapAptvProviderContextForRequest($providerId!==''?$providerId:null);$vod=openapAptvVod($id,$kind,$providerId!==''?$providerId:null);if(!$vod){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Movie unavailable.')]);return;}
    $profile=is_array($context)?(array)($context['profile']??[]):null;if(!$profile){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $directory='/var/cache/openap-iptv/vod-probes';if(!is_dir($directory))@mkdir($directory,0750,true);$cache=openapAptvVodProbeCacheFile($id,$kind,$providerId!==''?$providerId:null);if(!$cache){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $payload=is_file($cache)&&filemtime($cache)>time()-604800?json_decode((string)file_get_contents($cache),true):null;if(is_array($payload)&&!array_key_exists('video',$payload))$payload=null;
    if(!is_array($payload)){
        $password=(string)($profile['password']??'');$extension=preg_replace('/[^a-z0-9]/','',strtolower((string)($vod['container_extension']??'mkv')));$url=rtrim((string)$profile['url'],'/').'/'.openapAptvVodSourcePath($vod).'/'.rawurlencode((string)$profile['username']).'/'.rawurlencode($password).'/'.$id.'.'.$extension;
        $ff=@proc_open(['/usr/bin/ffprobe','-v','error','-read_intervals','0%+3','-show_entries','format=duration:stream=index,codec_type,codec_name:stream_tags=language,title','-of','json','pipe:0'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$fp);
        if(is_resource($ff)&&isset($fp[0],$fp[1])){$cu=@proc_open(['/usr/bin/curl','--ipv4','--silent','--fail','--location','--max-redirs','5','--connect-timeout','12','--speed-limit','512','--speed-time','20','--config','-'],[0=>['pipe','r'],1=>$fp[0],2=>['file','/dev/null','a']],$cp);fclose($fp[0]);if(is_resource($cu)&&isset($cp[0])){$escaped=str_replace(['\\','"'],['\\\\','\\"'],$url);$config='url = "'.$escaped.'"'."\n";fwrite($cp[0],$config);fclose($cp[0]);$raw=stream_get_contents($fp[1]);fclose($fp[1]);proc_terminate($cu);proc_close($cu);proc_close($ff);$decoded=json_decode((string)$raw,true);if(is_array($decoded)){$audio=[];$subtitles=[];$video=null;foreach(($decoded['streams']??[])as$stream){$type=(string)($stream['codec_type']??'');$tags=is_array($stream['tags']??null)?$stream['tags']:[];$entry=['index'=>(int)($stream['index']??0),'codec'=>(string)($stream['codec_name']??''),'language'=>(string)($tags['language']??'und'),'title'=>(string)($tags['title']??'')];if($type==='video'&&$video===null)$video=$entry;elseif($type==='audio')$audio[]=$entry;elseif($type==='subtitle')$subtitles[]=$entry;}$container=strtolower((string)($vod['container_extension']??''));$videoCodec=strtolower((string)($video['codec']??''));$audioCodec=strtolower((string)($audio[0]['codec']??''));$direct=in_array($container,['mp4','m4v'],true)&&$videoCodec==='h264'&&in_array($audioCodec,['aac','mp3'],true);$payload=['duration'=>(float)($decoded['format']['duration']??0),'video'=>$video,'audio'=>$audio,'subtitles'=>$subtitles,'container'=>$container,'direct_compatible'=>$direct];$temporary=$cache.'.new.'.getmypid();file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$cache);}}else{fclose($fp[1]);proc_terminate($ff);proc_close($ff);}}
        sodium_memzero($url);sodium_memzero($password);
    }
    if(!is_array($payload)){$payload=['duration'=>0,'video'=>null,'audio'=>[],'subtitles'=>[],'container'=>(string)($vod['container_extension']??''),'direct_compatible'=>false];}$payload['source_kind']=(string)($vod['source_kind']??'movie');$payload['direct_compatible']=openapAptvVodProgressiveCompatible($payload);$payload['progressive_series']=$payload['source_kind']==='series'&&$payload['direct_compatible'];$payload['playback_class']=openapAptvVodPlaybackClass($payload);$payload['hls_compatible']=in_array($payload['playback_class'],['hls_remux','hls_audio_transcode','hls_video_transcode'],true);echo json_encode(['ok'=>true]+$payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function openapAptvEbmlVint(string $data, int $offset, bool $identifier = false): ?array
{
    $length = strlen($data);
    if ($offset < 0 || $offset >= $length) return null;
    $first = ord($data[$offset]);
    $mask = 0x80; $width = 1; $maximum = $identifier ? 4 : 8;
    while ($width <= $maximum && ($first & $mask) === 0) { $mask >>= 1; $width++; }
    if ($width > $maximum || $offset + $width > $length) return null;
    $value = $identifier ? $first : ($first & ($mask - 1));
    for ($i = 1; $i < $width; $i++) $value = ($value << 8) | ord($data[$offset + $i]);
    $unknown = !$identifier && $value === ((1 << (7 * $width)) - 1);
    return ['length'=>$width, 'value'=>$value, 'id'=>bin2hex(substr($data, $offset, $width)), 'unknown'=>$unknown];
}
function openapAptvEbmlElement(string $data, int $offset, int $end): ?array
{
    $id = openapAptvEbmlVint($data, $offset, true); if (!is_array($id)) return null;
    $size = openapAptvEbmlVint($data, $offset + $id['length'], false); if (!is_array($size)) return null;
    $payload = $offset + $id['length'] + $size['length'];
    if ($payload > $end) return null;
    $elementEnd = $size['unknown'] ? $end : min($end, $payload + (int)$size['value']);
    return ['id'=>$id['id'], 'header'=>$payload - $offset, 'payload'=>$payload, 'size'=>$elementEnd - $payload, 'end'=>$elementEnd];
}
function openapAptvEbmlUInt(string $data, int $offset, int $length): ?int
{
    if ($length < 1 || $length > 8 || $offset < 0 || $offset + $length > strlen($data)) return null;
    $value = 0;
    for ($i = 0; $i < $length; $i++) $value = ($value * 256) + ord($data[$offset + $i]);
    return $value;
}
function openapAptvEbmlHeadInfo(string $data, int $segmentStart, int $end): array
{
    $timestampScale = 1000000; $cuesPosition = null;
    for ($offset = $segmentStart; $offset < $end;) {
        $element = openapAptvEbmlElement($data, $offset, $end); if (!is_array($element)) break;
        if ($element['id'] === '1549a966') {
            for ($inner = $element['payload']; $inner < $element['end'];) {
                $child = openapAptvEbmlElement($data, $inner, $element['end']); if (!is_array($child)) break;
                if ($child['id'] === '2ad7b1') { $value = openapAptvEbmlUInt($data, $child['payload'], $child['size']); if ($value !== null && $value > 0) $timestampScale = $value; }
                $inner = $child['end'];
            }
        } elseif ($element['id'] === '114d9b74') {
            for ($inner = $element['payload']; $inner < $element['end'];) {
                $seek = openapAptvEbmlElement($data, $inner, $element['end']); if (!is_array($seek)) break;
                if ($seek['id'] === '4dbb') {
                    $seekId = ''; $seekPosition = null;
                    for ($part = $seek['payload']; $part < $seek['end'];) {
                        $child = openapAptvEbmlElement($data, $part, $seek['end']); if (!is_array($child)) break;
                        if ($child['id'] === '53ab') $seekId = bin2hex(substr($data, $child['payload'], $child['size']));
                        elseif ($child['id'] === '53ac') $seekPosition = openapAptvEbmlUInt($data, $child['payload'], $child['size']);
                        $part = $child['end'];
                    }
                    if ($seekId === '1c53bb6b' && $seekPosition !== null) $cuesPosition = $seekPosition;
                }
                $inner = $seek['end'];
            }
        }
        $offset = $element['end'];
    }
    return ['timestamp_scale'=>(int)$timestampScale, 'cues_position'=>$cuesPosition];
}
function openapAptvEbmlCueTimes(string $data, int $offset = 0): array
{
    $end = strlen($data); $root = openapAptvEbmlElement($data, $offset, $end); if (!is_array($root) || $root['id'] !== '1c53bb6b') return [];
    $times = [];
    for ($point = $root['payload']; $point < $root['end'];) {
        $cuePoint = openapAptvEbmlElement($data, $point, $root['end']); if (!is_array($cuePoint)) break;
        if ($cuePoint['id'] === 'bb') {
            for ($childOffset = $cuePoint['payload']; $childOffset < $cuePoint['end'];) {
                $child = openapAptvEbmlElement($data, $childOffset, $cuePoint['end']); if (!is_array($child)) break;
                if ($child['id'] === 'b3') { $time = openapAptvEbmlUInt($data, $child['payload'], $child['size']); if ($time !== null) $times[] = $time; }
                $childOffset = $child['end'];
            }
        }
        $point = $cuePoint['end'];
    }
    sort($times, SORT_NUMERIC); return array_values(array_unique($times, SORT_NUMERIC));
}
function openapAptvFetchRange(string $url, int $start, int $end, float $timeout = 1.5): array
{
    $curl = openapAptvCurl($url); $status = 0; $headers = '';
    curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>3, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT=>1, CURLOPT_TIMEOUT=>max(1, (int)ceil($timeout)), CURLOPT_RANGE=>$start.'-'.$end, CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADERFUNCTION=>static function($handle, string $line)use(&$status,&$headers):int{ $headers .= $line; if (preg_match('~^HTTP/\S+\s+(\d{3})~i', trim($line), $m)) $status=(int)$m[1]; return strlen($line); }]);
    $body = curl_exec($curl); $error = curl_error($curl); $status = (int)(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) ?: $status); curl_close($curl);
    return ['ok'=>is_string($body) && $body !== '' && $status === 206, 'body'=>is_string($body)?$body:'', 'status'=>$status, 'error'=>$error, 'headers'=>$headers];
}
function openapAptvCuesHeaderValues(array $response): array
{
    $headers=(string)($response['headers']??'');$values=['etag'=>'','last_modified'=>'','content_length'=>0];
    foreach(preg_split('/\r?\n/',$headers)?:[] as$line){if(preg_match('/^ETag:\s*(.*)$/i',$line,$m))$values['etag']=trim($m[1]);elseif(preg_match('/^Last-Modified:\s*(.*)$/i',$line,$m))$values['last_modified']=trim($m[1]);elseif(preg_match('/^Content-Length:\s*(\d+)/i',$line,$m))$values['content_length']=(int)$m[1];elseif(preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i',$line,$m))$values['content_length']=(int)$m[1];}
    return $values;
}
function openapAptvCuesSidecar(string $id, array $profile, array $vod): ?array
{
    $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($vod['container_extension'] ?? 'mkv')));
    $providerKey = hash('sha256', implode("\0", [
        (string) ($profile['id'] ?? ''), (string) ($profile['url'] ?? ''),
        (string) ($profile['username'] ?? ''), $id, $extension,
        (string) ($vod['source_kind'] ?? ''), (string) ($vod['series_id'] ?? ''),
    ]));
    $directory = '/var/cache/openap-iptv/vod-cues';
    $metadataPath = $directory . '/' . $providerKey . '.json';
    $sidecarPath = $directory . '/' . $providerKey . '.bin';
    $metadata = is_file($metadataPath) ? json_decode((string) @file_get_contents($metadataPath), true) : null;
    if (!is_array($metadata) || ($metadata['kind'] ?? '') !== 'cues' || (int) ($metadata['fresh_until'] ?? 0) <= time() || !is_file($sidecarPath)) return null;
    $offset = (int) ($metadata['cues_offset'] ?? -1);
    $length = (int) ($metadata['cues_bytes'] ?? 0);
    $total = (int) ($metadata['validator']['content_length'] ?? $metadata['total_size'] ?? 0);
    if ($offset < 0 || $length <= 0 || $total <= $offset || (int) @filesize($sidecarPath) < $length) return null;
    return ['path'=>$sidecarPath, 'offset'=>$offset, 'length'=>$length, 'total'=>$total];
}
function openapAptvCuesSelectOrigin(array $times, int $scale, float $target): ?float
{
    $targetTicks=$target*1000000000/max(1,$scale);$originTicks=null;
    foreach($times as$time){if($time<=$targetTicks+1e-6)$originTicks=$time;else break;}
    if($originTicks===null&&$target<=0.001&&!empty($times)&&$times[0]<=1)$originTicks=$times[0];
    return $originTicks===null?null:(float)$originTicks*max(1,$scale)/1000000000;
}
function openapAptvCuesOrigin(string $url, float $target): array
{
    $started = microtime(true); $head = openapAptvFetchRange($url, 0, 65535, 1.5);
    if (!$head['ok']) return ['ok'=>false,'code'=>'CUES_HEAD_RANGE_FAILED','status'=>$head['status'],'probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    $data = $head['body']; $segment = null;
    for ($offset = 0; $offset < strlen($data);) { $element = openapAptvEbmlElement($data, $offset, strlen($data)); if (!is_array($element)) break; if ($element['id'] === '18538067') { $segment = $element; break; } $offset = $element['end']; }
    if (!is_array($segment)) return ['ok'=>false,'code'=>'CUES_SEGMENT_NOT_FOUND','probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    $info = openapAptvEbmlHeadInfo($data, $segment['payload'], min($segment['end'], strlen($data))); $position = $info['cues_position'] ?? null;
    if ($position === null) return ['ok'=>false,'code'=>'CUES_SEEKHEAD_NOT_FOUND','probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    $cuesOffset = $segment['payload'] + (int)$position; $cues = openapAptvFetchRange($url, $cuesOffset, $cuesOffset + 524287, 1.5);
    if (!$cues['ok']) return ['ok'=>false,'code'=>'CUES_BODY_RANGE_FAILED','status'=>$cues['status'],'probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    $times = openapAptvEbmlCueTimes($cues['body']); if (!$times) return ['ok'=>false,'code'=>'CUES_EMPTY','probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    $scale = max(1, (int)$info['timestamp_scale']); $targetTicks = $target * 1000000000 / $scale; $originTicks = null;
    foreach ($times as $time) { if ($time <= $targetTicks + 1e-6) $originTicks = $time; else break; }
    if ($originTicks === null && $target <= 0.001 && $times[0] <= 1) $originTicks = $times[0];
    if ($originTicks === null) return ['ok'=>false,'code'=>'CUES_NO_ORIGIN','probe_ms'=>(int)round((microtime(true)-$started)*1000)];
    return ['ok'=>true,'origin'=>(float)$originTicks * $scale / 1000000000, 'cue_count'=>count($times), 'cue_times'=>$times, 'timestamp_scale'=>$scale, 'cues_offset'=>$cuesOffset, 'head_bytes'=>strlen($data), 'cues_bytes'=>strlen($cues['body']), 'cues_body'=>$cues['body'], 'validator'=>openapAptvCuesHeaderValues($head), 'probe_ms'=>(int)round((microtime(true)-$started)*1000)];
}
function openapAptvCuesOriginCached(string $url, float $target, string $id, array $profile, string $extension, string $sourceKind, string $seriesId): array
{
    $providerKey=hash('sha256',implode("\0",[(string)($profile['id']??''),(string)($profile['url']??''),(string)($profile['username']??''),$id,$extension,$sourceKind,$seriesId]));
    $directory='/var/cache/openap-iptv/vod-cues';$path=$directory.'/'.$providerKey.'.json';$sidecarPath=$directory.'/'.$providerKey.'.bin';$now=time();$cached=is_file($path)?json_decode((string)@file_get_contents($path),true):null;
    if(is_array($cached)&&($cached['kind']??'')==='cues'&&(!is_file($sidecarPath)||!isset($cached['cues_offset'])))$cached=null;
    if(is_array($cached)&&($cached['schema_version']??0)===1&&hash_equals($providerKey,(string)($cached['provider_key']??''))&&(int)($cached['fresh_until']??0)>$now){
        if(($cached['kind']??'')==='negative'){return ['ok'=>false,'code'=>(string)($cached['code']??'CUES_CACHED_NEGATIVE'),'status'=>(int)($cached['status']??0),'probe_ms'=>0,'cache_hit'=>true,'cache_state'=>'negative','cache_age_seconds'=>max(0,$now-(int)($cached['created_at']??$now))];}
        $times=is_array($cached['cue_times']??null)?$cached['cue_times']:[];$scale=max(1,(int)($cached['timestamp_scale']??1000000));$origin=openapAptvCuesSelectOrigin($times,$scale,$target);
        if($origin!==null){return ['ok'=>true,'origin'=>$origin,'cue_count'=>count($times),'timestamp_scale'=>$scale,'head_bytes'=>(int)($cached['head_bytes']??0),'cues_bytes'=>(int)($cached['cues_bytes']??0),'probe_ms'=>0,'cache_hit'=>true,'cache_state'=>'hit','cache_age_seconds'=>max(0,$now-(int)($cached['created_at']??$now))];}
        return ['ok'=>false,'code'=>'CUES_NO_ORIGIN','probe_ms'=>0,'cache_hit'=>true,'cache_state'=>'hit','cache_age_seconds'=>max(0,$now-(int)($cached['created_at']??$now))];
    }
    $result=openapAptvCuesOrigin($url,$target);$result['cache_hit']=false;$result['cache_state']='miss';$result['cache_age_seconds']=0;
    $record=null;
    if(!empty($result['ok'])&&is_array($result['cue_times']??null)){$validator=is_array($result['validator']??null)?$result['validator']:[];$record=['schema_version'=>1,'kind'=>'cues','created_at'=>$now,'fresh_until'=>$now+2592000,'provider_key'=>$providerKey,'timestamp_scale'=>(int)$result['timestamp_scale'],'cue_times'=>array_values($result['cue_times']),'cues_offset'=>(int)($result['cues_offset']??-1),'head_bytes'=>(int)$result['head_bytes'],'cues_bytes'=>(int)$result['cues_bytes'],'total_size'=>(int)($validator['content_length']??0),'validator'=>$validator];if(is_string($result['cues_body']??null)&&$result['cues_body']!==''){if(!is_dir($directory)){@mkdir($directory,0770,true);@chmod($directory,0770);@chgrp($directory,'www-data');}$temporary=$sidecarPath.'.new.'.getmypid().'.'.bin2hex(random_bytes(4));if(@file_put_contents($temporary,$result['cues_body'],LOCK_EX)!==false){@chmod($temporary,0640);@chgrp($temporary,'www-data');@rename($temporary,$sidecarPath);}else{@unlink($temporary);}}unset($result['cues_body']);}
    elseif(!empty($result['code'])&&$result['code']!=='CUES_NO_ORIGIN')$record=['schema_version'=>1,'kind'=>'negative','created_at'=>$now,'fresh_until'=>$now+3600,'provider_key'=>$providerKey,'code'=>(string)$result['code'],'status'=>(int)($result['status']??0)];
    if(is_array($record)){if(!is_dir($directory)){@mkdir($directory,0770,true);@chmod($directory,0770);@chgrp($directory,'www-data');}$temporary=$path.'.new.'.getmypid().'.'.bin2hex(random_bytes(4));if(@file_put_contents($temporary,json_encode($record,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){@chmod($temporary,0640);@chgrp($temporary,'www-data');@rename($temporary,$path);}else{@unlink($temporary);}}
    return $result;
}
function openapAptvTitleKeyframeIndex(string $id): ?array
{
    if (!preg_match('/^[0-9]{1,20}$/', $id)) return null;
    $file = '/var/cache/openap-iptv/vod-cache/' . md5('openap-title-cache:' . $id) . '/keyframes.json';
    if (!is_file($file)) return null;
    $index = json_decode((string) @file_get_contents($file), true);
    return is_array($index) && is_array($index['keyframes'] ?? null) ? $index : null;
}
function openapAptvEphemeralVodEligible(string $id): bool
{
    // The ephemeral architecture is retained but deliberately disabled while
    // the default remains the classic progressive-fMP4 path.  Re-enable it
    // later with the feature flag after automatic indexing is production-ready.
    if (!is_file('/etc/openap/iptv-vod-ephemeral.enabled')) return false;
    if (!preg_match('/^[0-9]{1,20}$/', $id)) return false;
    $index = openapAptvTitleKeyframeIndex($id);
    return is_array($index) && count($index['keyframes'] ?? []) > 0;
}
/**
 * Absolute movie time of the keyframe ffmpeg starts from for a given -ss
 * target.  With stream copy ffmpeg lands on the keyframe at or before the
 * target, and the mov muxer then rewrites the emitted tfdt to zero, so this
 * origin is the only thing that maps fragment timestamps back onto the movie.
 */
function openapAptvTitleKeyframeOrigin(array $index, float $seekTarget): float
{
    $origin = 0.0;
    foreach (($index['keyframes'] ?? []) as $keyframe) {
        $pts = (float) (is_array($keyframe) ? ($keyframe['source_pts'] ?? $keyframe['pts'] ?? -1) : $keyframe);
        if ($pts > $seekTarget + 1e-6) break;
        $origin = $pts;
    }
    return $origin;
}
function openapAptvTitleKeyframeIndexAt(array $index, float $seekTarget): int
{
    $at = 0;
    foreach (($index['keyframes'] ?? []) as $keyframe) {
        $pts = (float) (is_array($keyframe) ? ($keyframe['source_pts'] ?? $keyframe['pts'] ?? -1) : $keyframe);
        if ($pts > $seekTarget + 1e-6) break;
        $at = (int) (is_array($keyframe) ? ($keyframe['keyframe_index'] ?? $keyframe['index'] ?? $at) : $at);
    }
    return $at;
}
/**
 * Pin a computed fragment position onto the measured keyframe it must be.  The
 * producer's stream starts at the first packet's DTS, which under B-frames sits
 * a few milliseconds before the keyframe's PTS, so tfdt arithmetic drifts by
 * ~13 ms after the first fragment.  Fragments begin at keyframes, so the index
 * is the authority and the computed value is only a hint.
 */
function openapAptvTitleKeyframeSnap(?array $index, float $position, float $tolerance = 0.5): float
{
    $best = null;
    $bestDistance = $tolerance;
    foreach (($index['keyframes'] ?? []) as $keyframe) {
        $pts = (float) (is_array($keyframe) ? ($keyframe['source_pts'] ?? $keyframe['pts'] ?? -1) : $keyframe);
        $distance = abs($pts - $position);
        if ($distance <= $bestDistance) {
            $bestDistance = $distance;
            $best = $pts;
        }
    }
    return $best ?? $position;
}
function openapAptvCacheFragmentAt(array $index, float $position): ?array
{
    $position = max(0, $position);
    // Never answer with a fragment from a different part of the movie: callers
    // use this as the player's timeline origin, so a fallback outside the
    // cached window would silently play the wrong region.
    $cacheStart = (float) ($index['cache_start'] ?? $index['source_start'] ?? 0);
    $cacheEnd = (float) ($index['cache_end'] ?? 0);
    if ($cacheEnd > 0 && ($position < $cacheStart - .5 || $position > $cacheEnd + .5)) return null;
    $timeline = is_array($index['timeline_index'] ?? null) ? array_values($index['timeline_index']) : [];
    usort($timeline, static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0) <=> (float) ($right['start'] ?? 0)));
    $candidate = null;
    $keyframeCandidate = null;
    foreach ($timeline as $fragment) {
        if (!is_array($fragment)) continue;
        $start = (float) ($fragment['start'] ?? 0);
        $duration = max(.001, (float) ($fragment['duration'] ?? 2));
        $isKeyframe = !array_key_exists('keyframe', $fragment) || $fragment['keyframe'] !== false;
        if ($isKeyframe && $start <= $position && ($keyframeCandidate === null || $start > (float) ($keyframeCandidate['start'] ?? 0))) $keyframeCandidate = $fragment;
        if ($isKeyframe && $position + .001 >= $start && $position < $start + $duration) return $fragment;
        if ($start <= $position && ($candidate === null || $start > (float) ($candidate['start'] ?? 0))) $candidate = $fragment;
    }
    $fragments = is_array($index['fragments'] ?? null) ? $index['fragments'] : [];
    if ($keyframeCandidate === null && $candidate === null) {
        usort($fragments, static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0) <=> (float) ($right['start'] ?? 0)));
        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) continue;
            $duration = max(.001, (float) ($fragment['duration'] ?? 2));
            $isKeyframe = !array_key_exists('keyframe', $fragment) || $fragment['keyframe'] !== false;
            if ($isKeyframe && $position < (float) ($fragment['start'] ?? 0) + $duration) { $keyframeCandidate = $fragment; break; }
        }
    }
    // A title cache is a window, not a continuous timeline: the region before
    // the requested position may simply not be cached.  Answer only with a
    // fragment that reaches the position; otherwise let the caller start a
    // producer, rather than hand the player a fragment hundreds of seconds away.
    $selected = $keyframeCandidate !== null ? $keyframeCandidate : $candidate;
    if (!is_array($selected)) return null;
    $selectedEnd = (float) ($selected['start'] ?? 0) + max(.001, (float) ($selected['duration'] ?? 2));
    if ($position > $selectedEnd + .5) return null;
    return $selected;
}
function openapAptvCacheTimelineGap(array $index, float $tolerance = .5): ?array
{
    $fragments = is_array($index['fragments'] ?? null) ? array_values($index['fragments']) : [];
    usort($fragments, static fn (array $left, array $right): int => ((float) ($left['start'] ?? 0) <=> (float) ($right['start'] ?? 0)));
    $expected = null;
    foreach ($fragments as $fragment) {
        if (!is_array($fragment)) continue;
        $start = (float) ($fragment['start'] ?? 0);
        $duration = max(.001, (float) ($fragment['duration'] ?? 2));
        if ($expected !== null && $start > $expected + $tolerance) {
            return ['start' => $expected, 'end' => $start, 'seconds' => $start - $expected];
        }
        $expected = max($expected ?? $start, $start + $duration);
    }
    return null;
}

/*
 * The ephemeral pilot cannot rely on the mov muxer's relative tfdt.  Keep a
 * small BMFF reader here so the HTTP relay can pin each completed moof+mdat
 * in memory before it reaches the browser.  The cache worker has the same
 * operation, but the ephemeral path deliberately never includes that worker.
 */
function openapAptvEphemeralBoxes(string $data, int $start = 0, ?int $end = null): array
{
    $length = strlen($data); $end = $end === null ? $length : min($length, $end); $boxes = [];
    for ($offset = max(0, $start); $offset + 8 <= $end;) {
        $size = (int) (unpack('N', substr($data, $offset, 4))[1] ?? 0);
        $type = substr($data, $offset + 4, 4); $header = 8;
        if ($size === 1) {
            if ($offset + 16 > $end) break;
            $parts = unpack('N2', substr($data, $offset + 8, 8));
            $size = (int) (($parts[1] ?? 0) * 4294967296 + ($parts[2] ?? 0)); $header = 16;
        } elseif ($size === 0) $size = $end - $offset;
        if ($size < $header || $offset + $size > $end || $type === '') break;
        $boxes[] = ['type' => $type, 'offset' => $offset, 'size' => $size, 'header' => $header];
        $offset += $size;
    }
    return $boxes;
}
function openapAptvEphemeralFind(string $data, string $wanted, int $start = 0, ?int $end = null): array
{
    $containers = ['moov'=>true,'trak'=>true,'mdia'=>true,'minf'=>true,'stbl'=>true,'mvex'=>true,'moof'=>true,'traf'=>true,'meta'=>true]; $matches = [];
    foreach (openapAptvEphemeralBoxes($data, $start, $end) as $box) {
        if ($box['type'] === $wanted) $matches[] = $box;
        if (isset($containers[$box['type']])) {
            $inner = $box['offset'] + $box['header'] + ($box['type'] === 'meta' ? 4 : 0);
            $matches = array_merge($matches, openapAptvEphemeralFind($data, $wanted, $inner, $box['offset'] + $box['size']));
        }
    }
    return $matches;
}
function openapAptvEphemeralU32(string $data, int $offset): ?int
{
    return $offset >= 0 && $offset + 4 <= strlen($data) ? (int) (unpack('N', substr($data, $offset, 4))[1] ?? 0) : null;
}
function openapAptvEphemeralU64(string $data, int $offset): ?int
{
    if ($offset < 0 || $offset + 8 > strlen($data)) return null;
    $parts = unpack('N2', substr($data, $offset, 8));
    return (int) (($parts[1] ?? 0) * 4294967296 + ($parts[2] ?? 0));
}
function openapAptvEphemeralTrackInfo(string $init): ?array
{
    $moov = openapAptvEphemeralFind($init, 'moov')[0] ?? null; if (!is_array($moov)) return null;
    $tracks = [];
    foreach (openapAptvEphemeralFind($init, 'trak', $moov['offset'] + $moov['header'], $moov['offset'] + $moov['size']) as $trak) {
        $tkhd = openapAptvEphemeralFind($init, 'tkhd', $trak['offset'] + $trak['header'], $trak['offset'] + $trak['size'])[0] ?? null;
        $hdlr = openapAptvEphemeralFind($init, 'hdlr', $trak['offset'] + $trak['header'], $trak['offset'] + $trak['size'])[0] ?? null;
        $mdhd = openapAptvEphemeralFind($init, 'mdhd', $trak['offset'] + $trak['header'], $trak['offset'] + $trak['size'])[0] ?? null;
        if (!is_array($tkhd) || !is_array($hdlr) || !is_array($mdhd)) continue;
        $tkhdVersion = ord($init[$tkhd['offset'] + 8] ?? "\0"); $mdhdVersion = ord($init[$mdhd['offset'] + 8] ?? "\0");
        $trackId = openapAptvEphemeralU32($init, $tkhd['offset'] + ($tkhdVersion === 1 ? 28 : 20));
        $timescale = openapAptvEphemeralU32($init, $mdhd['offset'] + ($mdhdVersion === 1 ? 28 : 20));
        if ($trackId !== null && $timescale !== null && $timescale > 0) $tracks[(string) $trackId] = ['handler'=>substr($init, $hdlr['offset'] + 16, 4), 'timescale'=>$timescale];
    }
    $timescales = []; $videoId = null; $videoScale = 0;
    foreach ($tracks as $trackId => $track) { $timescales[(int) $trackId] = (int) $track['timescale']; if (($track['handler'] ?? '') === 'vide' && $videoId === null) { $videoId = (int) $trackId; $videoScale = (int) $track['timescale']; } }
    return $videoId === null ? null : ['track_id'=>$videoId, 'timescale'=>$videoScale, 'timescales'=>$timescales];
}
function openapAptvEphemeralFragmentInfo(string $fragment, ?array $trackInfo): ?array
{
    if (!is_array($trackInfo)) return null; $moof = openapAptvEphemeralFind($fragment, 'moof')[0] ?? null; if (!is_array($moof)) return null;
    $wanted = (int) ($trackInfo['track_id'] ?? 0); $scale = max(1, (int) ($trackInfo['timescale'] ?? 0));
    foreach (openapAptvEphemeralFind($fragment, 'traf', $moof['offset'] + $moof['header'], $moof['offset'] + $moof['size']) as $traf) {
        $tfhd = openapAptvEphemeralFind($fragment, 'tfhd', $traf['offset'] + $traf['header'], $traf['offset'] + $traf['size'])[0] ?? null;
        $tfdt = openapAptvEphemeralFind($fragment, 'tfdt', $traf['offset'] + $traf['header'], $traf['offset'] + $traf['size'])[0] ?? null;
        if (!is_array($tfhd) || !is_array($tfdt) || openapAptvEphemeralU32($fragment, $tfhd['offset'] + 12) !== $wanted) continue;
        $offset = $tfdt['offset']; $version = ord($fragment[$offset + 8] ?? "\0"); $value = $version === 1 ? openapAptvEphemeralU64($fragment, $offset + 12) : openapAptvEphemeralU32($fragment, $offset + 12);
        if ($value !== null) return ['tfdt'=>$value, 'seconds'=>$value / $scale, 'offset'=>$offset, 'version'=>$version];
    }
    return null;
}
function openapAptvEphemeralFragmentStats(string $fragment, ?array $trackInfo): array
{
    $result=['bytes'=>strlen($fragment),'sequence'=>null,'video'=>null,'tracks'=>[]];$moof=openapAptvEphemeralFind($fragment,'moof')[0]??null;if(!is_array($moof))return $result;
    $mfhd=openapAptvEphemeralFind($fragment,'mfhd',$moof['offset']+$moof['header'],$moof['offset']+$moof['size'])[0]??null;if(is_array($mfhd))$result['sequence']=openapAptvEphemeralU32($fragment,$mfhd['offset']+12);
    foreach(openapAptvEphemeralFind($fragment,'traf',$moof['offset']+$moof['header'],$moof['offset']+$moof['size'])as$traf){
        $tfhd=openapAptvEphemeralFind($fragment,'tfhd',$traf['offset']+$traf['header'],$traf['offset']+$traf['size'])[0]??null;$trun=openapAptvEphemeralFind($fragment,'trun',$traf['offset']+$traf['header'],$traf['offset']+$traf['size'])[0]??null;if(!is_array($tfhd)||!is_array($trun))continue;
        $trackId=openapAptvEphemeralU32($fragment,$tfhd['offset']+12);if($trackId===null)continue;$tfhdFlags=(int)(openapAptvEphemeralU32($fragment,$tfhd['offset']+8)??0)&0xFFFFFF;$cursor=$tfhd['offset']+16;$defaultDuration=null;
        if($tfhdFlags&0x000001)$cursor+=8;if($tfhdFlags&0x000002)$cursor+=4;if($tfhdFlags&0x000008){$defaultDuration=openapAptvEphemeralU32($fragment,$cursor);$cursor+=4;}if($tfhdFlags&0x000010)$cursor+=4;if($tfhdFlags&0x000020){$defaultFlags=openapAptvEphemeralU32($fragment,$cursor);$cursor+=4;}else$defaultFlags=null;
        $trunFlags=(int)(openapAptvEphemeralU32($fragment,$trun['offset']+8)??0)&0xFFFFFF;$sampleCount=(int)(openapAptvEphemeralU32($fragment,$trun['offset']+12)??0);$cursor=$trun['offset']+16;if($trunFlags&0x000001)$cursor+=4;$firstFlags=($trunFlags&0x000004)?openapAptvEphemeralU32($fragment,$cursor):null;if($trunFlags&0x000004)$cursor+=4;$durationTicks=0;$sampleFlags=$firstFlags??$defaultFlags;
        for($index=0;$index<$sampleCount;$index++){if($trunFlags&0x000100){$durationTicks+=(int)(openapAptvEphemeralU32($fragment,$cursor)??0);$cursor+=4;}elseif($defaultDuration!==null)$durationTicks+=(int)$defaultDuration;if($trunFlags&0x000200)$cursor+=4;if($trunFlags&0x000400){if($index>0||$firstFlags===null)$sampleFlags=openapAptvEphemeralU32($fragment,$cursor);$cursor+=4;}if($trunFlags&0x000800)$cursor+=4;}
        $scale=(int)($trackInfo['timescales'][$trackId]??0);$entry=['track_id'=>$trackId,'samples'=>$sampleCount,'trun_flags'=>sprintf('0x%06x',$trunFlags),'tfhd_default_sample_duration'=>$defaultDuration,'duration_ticks'=>$durationTicks,'duration_seconds'=>$scale>0?$durationTicks/$scale:null,'first_sample_flags'=>$sampleFlags,'first_sample_keyframe'=>$sampleFlags===null?null:(($sampleFlags&0x00010000)===0)];$result['tracks'][]=$entry;if(is_array($trackInfo)&&$trackId===(int)($trackInfo['track_id']??0))$result['video']=$entry;
    }
    return $result;
}
function openapAptvEphemeralPinTfdt(string $fragment, ?array $trackInfo, float $targetStart): array
{
    $result = ['bytes'=>$fragment, 'applied'=>false, 'delta'=>0.0, 'reason'=>''];
    if (!is_array($trackInfo) || !is_array($trackInfo['timescales'] ?? null)) { $result['reason'] = 'no_timescales'; return $result; }
    $moof = openapAptvEphemeralFind($fragment, 'moof')[0] ?? null; if (!is_array($moof)) { $result['reason'] = 'no_moof'; return $result; }
    $videoId = (int) ($trackInfo['track_id'] ?? 0); $videoScale = max(1, (int) ($trackInfo['timescale'] ?? 0)); $trafs = []; $video = null;
    foreach (openapAptvEphemeralFind($fragment, 'traf', $moof['offset'] + $moof['header'], $moof['offset'] + $moof['size']) as $traf) {
        $tfhd = openapAptvEphemeralFind($fragment, 'tfhd', $traf['offset'] + $traf['header'], $traf['offset'] + $traf['size'])[0] ?? null;
        $tfdt = openapAptvEphemeralFind($fragment, 'tfdt', $traf['offset'] + $traf['header'], $traf['offset'] + $traf['size'])[0] ?? null;
        if (!is_array($tfhd) || !is_array($tfdt)) continue;
        $trackId = openapAptvEphemeralU32($fragment, $tfhd['offset'] + 12); if ($trackId === null) continue;
        $offset = $tfdt['offset']; $version = ord($fragment[$offset + 8] ?? "\0"); $value = $version === 1 ? openapAptvEphemeralU64($fragment, $offset + 12) : openapAptvEphemeralU32($fragment, $offset + 12); if ($value === null) continue;
        $entry = ['track_id'=>$trackId, 'offset'=>$offset + 12, 'version'=>$version, 'value'=>$value]; $trafs[] = $entry; if ($trackId === $videoId) $video = $entry;
    }
    if ($video === null) { $result['reason'] = 'no_video_traf'; return $result; }
    $delta = $targetStart - ($video['value'] / $videoScale); $result['delta'] = $delta;
    if (abs($delta) < 1e-9) { $result['reason'] = 'already_absolute'; return $result; }
    $patched = $fragment;
    foreach ($trafs as $traf) {
        $scale = (int) ($trackInfo['timescales'][$traf['track_id']] ?? 0); if ($scale < 1) { $result['reason'] = 'unknown_timescale_'.$traf['track_id']; return $result; }
        $new = (int) round($traf['value'] + $delta * $scale); if ($new < 0) { $result['reason'] = 'negative_tfdt'; return $result; }
        $encoded = $traf['version'] === 1 ? pack('N2', intdiv($new, 4294967296), $new % 4294967296) : pack('N', $new); $patched = substr_replace($patched, $encoded, $traf['offset'], strlen($encoded));
    }
    $result['bytes'] = $patched; $result['applied'] = true; return $result;
}
function openapAptvStartDirectVod(): void
{
    $requestedId = trim((string) ($_GET['id'] ?? ''));
    $ephemeralPilot = openapAptvEphemeralVodEligible($requestedId);
    $cuesStrictPilot = (string) ($_GET['cues_strict'] ?? '') === '1' && (string) ($_GET['ephemeral'] ?? '') === '1';
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    $id=$requestedId;$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$context=openapAptvProviderContextForRequest($providerId!==''?$providerId:null);$start=max(0,min(86400,(int)($_GET['start']??0)));$disableCache=(string)($_GET['disable_cache']??'')==='1'||$requestedId==='1556812';$replaceCache=(string)($_GET['replace_cache']??'')==='1';$vod=openapAptvVod($id,$kind,$providerId!==''?$providerId:null);$profile=is_array($context)?(array)($context['profile']??[]):null;
    if(!$vod||!$profile){http_response_code(404);echo json_encode(['ok'=>false,'message'=>_('Movie unavailable.')]);return;}
    $cache=openapAptvVodProbeCacheFile($id,$kind,$providerId!==''?$providerId:null);if(!$cache){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $probe=is_file($cache)?json_decode((string)file_get_contents($cache),true):null;
    if(is_array($probe)){$probe['source_kind']=(string)($vod['source_kind']??'');$probe['series_id']=(string)($vod['series_id']??'');$probe['direct_compatible']=openapAptvVodProgressiveCompatible($probe);}
    $experimentalProgressive=is_array($probe)&&($probe['source_kind']??'')==='movie'&&strtolower((string)($probe['video']['codec']??''))==='mpeg4'&&strtolower((string)($probe['audio'][0]['codec']??''))==='aac'&&(int)($probe['video']['width']??0)>0&&(int)($probe['video']['height']??0)>0;
    if(!is_array($probe)||(empty($probe['direct_compatible'])&&!$experimentalProgressive)){http_response_code(415);echo json_encode(['ok'=>false,'message'=>_('This title requires a prepared stream.')]);return;}
    $password=(string)($profile['password']??'');$extension=preg_replace('/[^a-z0-9]/','',strtolower((string)($vod['container_extension']??'mkv')));
    $url=rtrim((string)$profile['url'],'/').'/'.openapAptvVodSourcePath($vod).'/'.rawurlencode((string)$profile['username']).'/'.rawurlencode($password).'/'.$id.'.'.$extension;
    $cuesProbe=null;
    if($cuesStrictPilot){
        $cuesProbe=openapAptvCuesOriginCached($url,(float)$start,$id,$profile,$extension,(string)($vod['source_kind']??''),(string)($vod['series_id']??''));
        if(!is_array($cuesProbe)||empty($cuesProbe['ok'])){http_response_code(424);echo json_encode(['ok'=>false,'code'=>(string)($cuesProbe['code']??'CUES_STRICT_FAILED'),'message'=>_('Cues strict test failed; classic fallback disabled.'),'cues_probe_ms'=>(int)($cuesProbe['probe_ms']??0),'cues_status'=>(int)($cuesProbe['status']??0)],JSON_UNESCAPED_SLASHES);sodium_memzero($url);sodium_memzero($password);return;}
        $ephemeralPilot=true;
    }
    $sealed=openapAptvSealUrl($url,28800);sodium_memzero($url);sodium_memzero($password);
    $requestedDaemonSid=strtolower((string)($_GET['sid']??''));$daemonPilot=$ephemeralPilot&&!$cuesStrictPilot&&(string)($_GET['daemon']??'')==='1'&&preg_match('/^[a-f0-9]{32}$/',$requestedDaemonSid);$daemonEpoch=max(1,(int)($_GET['epoch']??1));
    $session=$daemonPilot?$requestedDaemonSid:'';$reuseCacheSession=false;$requestedCacheSession=(string)($_GET['cache_session']??'');$cacheDecision=$daemonPilot?'daemon_session':'new_session';$cacheBusy=false;
    if(!$ephemeralPilot&&$id==='1556812'&&preg_match('/^[a-f0-9]{32}$/',$requestedCacheSession)&&is_file('/var/cache/openap-iptv/vod-cache/'.$requestedCacheSession.'/init.mp4')&&is_file('/var/cache/openap-iptv/vod-cache/'.$requestedCacheSession.'/index.json')){$session=$requestedCacheSession;$reuseCacheSession=true;$cacheDecision='reuse_requested_session';}
    elseif($requestedCacheSession!=='')$cacheDecision='requested_session_missing_or_invalid';
    $statsBase='/run/openap-iptv-vod-remux/progressive-stats';
    if(!$reuseCacheSession&&!$daemonPilot&&(is_dir($statsBase)||@mkdir($statsBase,0700,true))&&is_writable($statsBase)){
        foreach(glob($statsBase.'/*')?:[]as$old){if(is_dir($old)&&filemtime($old)<time()-86400&&preg_match('/^[a-f0-9]{32}$/',basename($old))){foreach(glob($old.'/*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($old);}}
        $session=bin2hex(random_bytes(16));if(!@mkdir($statsBase.'/'.$session,0700))$session='';
    }
    if ($daemonPilot && $session !== '' && (!is_dir($statsBase . '/' . $session) || !is_writable($statsBase . '/' . $session))) {
        if (!@mkdir($statsBase . '/' . $session, 0700, true) && !is_dir($statsBase . '/' . $session)) $daemonPilot = false;
    }
    $audio=$probe['audio'][0]['codec']??'';$audioChannels=(int)($probe['audio'][0]['channels']??0);$hybrid=!in_array(strtolower((string)$audio),['aac','mp3'],true);
    $sourceKind=(string)($vod['source_kind']??'');$container=strtolower((string)($probe['container']??''));$videoCodec=strtolower((string)($probe['video']['codec']??''));$videoWidth=(int)($probe['video']['width']??0);$movieNeedsFmp4=$sourceKind==='movie'&&$container==='mkv'&&in_array($videoCodec,['h264','hevc','h265'],true);$movieBurst=$movieNeedsFmp4&&$videoWidth>=3000;
    // Universal VOD policy: every direct-compatible movie or series uses the
    // fMP4/MSE relay first. Native progressive remains only the explicit
    // browser fallback when the fMP4 path cannot start.
    $fmp4=openapAptvVodProgressiveCompatible($probe)||$experimentalProgressive;$progressiveAudioMode=$fmp4&&($experimentalProgressive||$hybrid||$audioChannels>2||$sourceKind==='series');$progressiveVideoMode=$experimentalProgressive?'h264':$videoCodec;
    $cacheEnabled=$fmp4&&$id==='1556812'&&!$disableCache&&!$ephemeralPilot;
    $removeCacheDirectory=static function(string $directory)use(&$removeCacheDirectory):void{if(!is_dir($directory))return;foreach(scandir($directory)?:[]as$entry){if($entry==='.'||$entry==='..')continue;$path=$directory.'/'.$entry;if(is_dir($path))$removeCacheDirectory($path);else@unlink($path);}@rmdir($directory);};
    $stopCacheDirectory=static function(string $directory)use($removeCacheDirectory):void{if(!is_dir($directory))return;@touch($directory.'/stop');$runtime='/run/openap-iptv-vod-remux/'.basename($directory);if(is_dir($runtime))@touch($runtime.'/stop');$deadline=microtime(true)+2;while(microtime(true)<$deadline){$statusFile=$directory.'/status.json';$status=is_file($statusFile)?json_decode((string)@file_get_contents($statusFile),true):null;if(!is_array($status)||empty($status['active']))break;usleep(50000);}$removeCacheDirectory($directory);if(is_dir($runtime))$removeCacheDirectory($runtime);};
    if($cacheEnabled&&!$reuseCacheSession){$cacheRoot='/var/cache/openap-iptv/vod-cache';if(is_dir($cacheRoot)){foreach(glob($cacheRoot.'/*')?:[]as$old){if(!is_dir($old))continue;$statusFile=$old.'/status.json';$status=is_file($statusFile)?json_decode((string)@file_get_contents($statusFile),true):null;$clientFile=$old.'/client.json';$clientExists=is_file($clientFile);$client=$clientExists?json_decode((string)@file_get_contents($clientFile),true):null;$lastHeartbeat=$clientExists?(float)($client['updated']??0):(float)($status['updated']??0);if(is_array($status)&&!empty($status['active'])){$cacheStart=(float)($status['cache_start']??0);$cacheEnd=(float)($status['cache_end']??0);$heartbeatFresh=$lastHeartbeat>0&&$lastHeartbeat>=microtime(true)-30;if(!$replaceCache&&$id==='1556812'&&$heartbeatFresh&&$cacheEnd>$cacheStart&&$start>=$cacheStart-.5&&$start<=$cacheEnd+.5){$session=basename($old);$reuseCacheSession=true;$cacheDecision='reuse_active_session_inside_cache';$cacheBusy=false;break;}$stopCacheDirectory($old);$cacheDecision=$replaceCache?'replace_cache_always':'replace_cache_outside_window';continue;}$removeCacheDirectory($old);}}if($cacheBusy&&!$reuseCacheSession){$cacheEnabled=false;$cacheDecision='cache_busy_direct_fmp4';}elseif($cacheDecision==='new_session')$cacheDecision='new_cache_session';}
    $sourceQuery=['token'=>$sealed,'kind'=>$kind];if (preg_match('/^[a-f0-9]{32}$/', $providerId)) $sourceQuery['provider_id'] = $providerId;if($session!=='')$sourceQuery['session']=$session;if($fmp4){$sourceQuery['format']='fmp4';$sourceQuery['id']=$id;$sourceQuery['start']=$start;if($movieBurst)$sourceQuery['burst']='1';if($progressiveAudioMode)$sourceQuery['audio_mode']='aac';if($progressiveVideoMode!=='')$sourceQuery['video_codec']=$progressiveVideoMode;if($experimentalProgressive)$sourceQuery['video_transcode']='1';if($cacheEnabled)$sourceQuery['cache']='1';if($ephemeralPilot)$sourceQuery['ephemeral']='1';if($cuesStrictPilot){$sourceQuery['cues_strict']='1';$sourceQuery['cues_origin']=number_format((float)$cuesProbe['origin'],6,'.','');}if($daemonPilot){$sourceQuery['daemon']='1';$sourceQuery['epoch']=$daemonEpoch;}}
    $directUrl=openapAptvStreamUrl('/aptv/api/vod/source?'.http_build_query($sourceQuery));
    $progressiveStartupTarget=$cacheEnabled?2:4;
    if($progressiveVideoMode==='h264'&&(string)($vod['source_kind']??'')==='series'){
        $progressiveHeight=(int)($probe['video']['height']??0);
        if($progressiveHeight>0&&$progressiveHeight<=576)$progressiveStartupTarget=3;
        elseif($progressiveHeight>0&&$progressiveHeight<=1080)$progressiveStartupTarget=4;
    }
    $titleKeyframes=openapAptvTitleKeyframeIndex($id);$timelineOrigin=$cuesStrictPilot?(float)$cuesProbe['origin']:($ephemeralPilot&&is_array($titleKeyframes)?openapAptvTitleKeyframeOrigin($titleKeyframes,(float)$start):($fmp4?max(0,$start-10):$start));$cacheKeyframeOrigin=is_array($titleKeyframes)?openapAptvTitleKeyframeOrigin($titleKeyframes,(float)$timelineOrigin):0.0;$cacheKeyframeAt=is_array($titleKeyframes)?openapAptvTitleKeyframeIndexAt($titleKeyframes,(float)$timelineOrigin):-1;$cacheSeekOrigin=$cacheEnabled||$reuseCacheSession?$cacheKeyframeOrigin:0.0;$cacheFragmentFile='';$cacheFragmentDuration=0.0;$cacheFragmentKeyframe=null;
    if($reuseCacheSession){$cacheRoot='/var/cache/openap-iptv/vod-cache/'.$session;$indexFile=$cacheRoot.'/index.json';$index=is_file($indexFile)?json_decode((string)file_get_contents($indexFile),true):null;$fragments=is_array($index)&&is_array($index['fragments']??null)?$index['fragments']:[];$selectedFragment=openapAptvCacheFragmentAt($index,(float)$start);if(is_array($selectedFragment)){$cacheSeekOrigin=(float)($selectedFragment['start']??$cacheSeekOrigin);$cacheFragmentFile=(string)($selectedFragment['file']??'');$cacheFragmentDuration=(float)($selectedFragment['duration']??0);$cacheFragmentKeyframe=array_key_exists('keyframe',$selectedFragment)?$selectedFragment['keyframe']:null;}}
    $cacheStatusFile=$session!==''?'/var/cache/openap-iptv/vod-cache/'.$session.'/status.json':'';$cacheStatus=$cacheStatusFile!==''&&is_file($cacheStatusFile)?json_decode((string)@file_get_contents($cacheStatusFile),true):null;
    echo json_encode(['ok'=>true,'url'=>$directUrl,'session'=>$session,'epoch'=>$daemonEpoch,'daemon'=>$daemonPilot,'start'=>$start,'timeline_origin'=>$timelineOrigin,'tfdt_absolute'=>$ephemeralPilot,'duration'=>(float)($probe['duration']??0),'source_bitrate'=>(int)($probe['source_bitrate']??0),'fmp4'=>$fmp4,'cache'=>$cacheEnabled,'cache_only'=>$cacheEnabled&&$id==='1556812','cache_seek'=>$reuseCacheSession,'cache_seek_origin'=>$cacheSeekOrigin,'cache_fragment_file'=>$cacheFragmentFile,'cache_fragment_duration'=>$cacheFragmentDuration,'cache_fragment_keyframe'=>$cacheFragmentKeyframe,'cache_start'=>$cacheEnabled?($reuseCacheSession?$cacheSeekOrigin:$timelineOrigin):0,'cache_session_requested'=>$requestedCacheSession,'cache_session_reused'=>$reuseCacheSession,'cache_decision'=>$cacheDecision,'cache_busy'=>$cacheBusy,'cache_status'=>is_array($cacheStatus)?$cacheStatus:null,'hybrid'=>$hybrid&&!$progressiveAudioMode,'progressive_audio_codec'=>$progressiveAudioMode?'aac':(string)$audio,'progressive_audio_transcoded'=>$progressiveAudioMode,'progressive_video_codec'=>$progressiveVideoMode,'progressive_start_target'=>$ephemeralPilot?6.0:$progressiveStartupTarget,'ephemeral'=>$ephemeralPilot,'cues_strict'=>$cuesStrictPilot,'cues_origin'=>$cuesStrictPilot?(float)$cuesProbe['origin']:null,'cues_probe_ms'=>$cuesStrictPilot?(int)$cuesProbe['probe_ms']:0,'cues_count'=>$cuesStrictPilot?(int)$cuesProbe['cue_count']:0,'cues_range_bytes'=>$cuesStrictPilot?(int)$cuesProbe['head_bytes']+(int)$cuesProbe['cues_bytes']:0,'cues_cache_hit'=>$cuesStrictPilot&&!empty($cuesProbe['cache_hit']),'cues_cache_state'=>$cuesStrictPilot?(string)($cuesProbe['cache_state']??''):'','cues_cache_age_seconds'=>$cuesStrictPilot?(int)($cuesProbe['cache_age_seconds']??0):0],JSON_UNESCAPED_SLASHES);
}
function openapAptvVodDirectStats(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $requestedId = trim((string) ($_GET['id'] ?? ''));
    $session=trim((string)($_GET['session']??''));
    if(!preg_match('/^[a-f0-9]{32}$/',$session)){
        http_response_code(422);echo json_encode(['ok'=>false]);return;
    }
    if ($requestedId === '1556812' && !is_dir('/run/openap-iptv-vod-remux/progressive-stats/' . $session)) $session = openapAptvTitleCacheKey($requestedId);
    $clientPosition=(float)($_GET['position']??-1);$cacheDirectory='/var/cache/openap-iptv/vod-cache/'.$session;
    if($clientPosition>=0&&is_dir($cacheDirectory)&&$session!==openapAptvTitleCacheKey('1556812')){$temporary=$cacheDirectory.'/client.json.new.'.getmypid();if(@file_put_contents($temporary,json_encode(['position'=>$clientPosition,'updated'=>microtime(true)],JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){@chmod($temporary,0600);@rename($temporary,$cacheDirectory.'/client.json');}}
    $directory='/run/openap-iptv-vod-remux/progressive-stats/'.$session;
    $latest=null;$active=null;$now=microtime(true);$recentMinimums=[];
    foreach(is_dir($directory)?(glob($directory.'/transfer-*.json')?:[]):[] as $file){
        $entry=json_decode((string)@file_get_contents($file),true);
        if(!is_array($entry))continue;
        $started=(float)($entry['started']??0);$updated=(float)($entry['updated']??0);
        $latestStarted=(float)($latest['started']??0);$latestUpdated=(float)($latest['updated']??0);
        if($latest===null||$started>$latestStarted||($started==$latestStarted&&$updated>$latestUpdated))$latest=$entry;
        if(!empty($entry['active'])&&$now-$updated<=5){
            if($active===null||$updated>(float)($active['updated']??0))$active=$entry;
        }
        if($now-$updated<=60&&!empty($entry['minimum_available']))$recentMinimums[]=(int)($entry['transfer_min_60s_bps']??0);
    }
    $selected=$active??$latest??['transfer_bps'=>0,'bytes'=>0,'elapsed'=>0,'last_output_at'=>0,'output_gap_seconds'=>0,'ffmpeg_stderr'=>'','active'=>false,'updated'=>0,'provider_status'=>0,'provider_error'=>'','stream_error'=>'','ffmpeg_exit'=>null];
    $age=max(0,$now-(float)($selected['updated']??0));$recentBps=(int)($selected['transfer_10s_bps']??0);$minimumBps=$recentMinimums?min($recentMinimums):0;
    $cacheStatusFile='/var/cache/openap-iptv/vod-cache/'.$session.'/status.json';$cacheStatus=is_file($cacheStatusFile)?json_decode((string)@file_get_contents($cacheStatusFile),true):null;
    $cacheIndexFile='/var/cache/openap-iptv/vod-cache/'.$session.'/index.json';$cacheIndex=is_file($cacheIndexFile)?json_decode((string)@file_get_contents($cacheIndexFile),true):null;
    $cacheGap=is_array($cacheIndex)?openapAptvCacheTimelineGap($cacheIndex):null;
    // Stato del relay: quanto della cache su disco e' stato davvero trasferito
    // al browser e con quale finestra in avanti.  E' un livello distinto dalla
    // cache persistente, dal buffer MSE e dalla posizione riproducibile.
    $relayFile='/var/cache/openap-iptv/vod-cache/'.$session.'/relay.json';
    $relayState=is_file($relayFile)?json_decode((string)@file_get_contents($relayFile),true):null;
    $relayServed=is_array($relayState)&&is_array($relayState['served']??null)?$relayState['served']:[];
    // The progressive fMP4 proxy keeps a provider-side heartbeat separate
    // from FFmpeg output.  This lets the diagnostic distinguish an upstream
    // range/HTTP stall from a relay or browser/MSE stall without exposing the
    // sealed provider URL.
    $providerHeartbeatFile='/run/openap-iptv-vod-remux/'.$session.'/provider-status.json';
    $providerHeartbeat=is_file($providerHeartbeatFile)?json_decode((string)@file_get_contents($providerHeartbeatFile),true):null;
    if(is_array($providerHeartbeat)){
        $heartbeatUpdated=(float)($providerHeartbeat['updated_at']??0);
        $heartbeatLastByte=(float)($providerHeartbeat['last_byte_at']??0);
        $providerHeartbeat=[
            'state'=>(string)($providerHeartbeat['state']??''),
            'age_seconds'=>$heartbeatUpdated>0?max(0,$now-$heartbeatUpdated):null,
            'request_elapsed_seconds'=>(float)($providerHeartbeat['request_elapsed_seconds']??0),
            'range_requested'=>(string)($providerHeartbeat['range_requested']??''),
            'range'=>(string)($providerHeartbeat['range']??''),
            'range_attempt'=>(int)($providerHeartbeat['range_attempt']??0),
            'next_offset'=>(int)($providerHeartbeat['next_offset']??0),
            'total_size_reported'=>array_key_exists('total_size_reported',$providerHeartbeat)?$providerHeartbeat['total_size_reported']:null,
            'http_status'=>(int)($providerHeartbeat['http_status']??0),
            'content_type'=>(string)($providerHeartbeat['content_type']??''),
            'bytes_received'=>(int)($providerHeartbeat['bytes_received']??$providerHeartbeat['bytes']??0),
            'bytes_per_second'=>(float)($providerHeartbeat['bytes_per_second']??0),
            'last_byte_at'=>$heartbeatLastByte>0?$heartbeatLastByte:null,
            'current_gap_seconds'=>$heartbeatLastByte>0?max(0,$now-$heartbeatLastByte):(float)($providerHeartbeat['gap_seconds']??0),
            'gap_seconds'=>(float)($providerHeartbeat['gap_seconds']??0),
            'curl_errno'=>(int)($providerHeartbeat['curl_errno']??0),
            'curl_error'=>(string)($providerHeartbeat['curl_error']??''),
            'error_class'=>(string)($providerHeartbeat['error_class']??''),
        ];
    }
    $recentAvailable=(float)($selected['transfer_window_seconds']??0)>=3;$minimumAvailable=(bool)$recentMinimums;
    if(!empty($selected['active'])&&$age>3){$recentBps=0;$recentAvailable=false;}
    $lastOutputAt=(float)($selected['last_output_at']??0);$outputGap=$lastOutputAt>0?max(0,$now-$lastOutputAt):(float)($selected['output_gap_seconds']??0);
    echo json_encode([
        'ok'=>true,
        'transfer_bps'=>(int)($selected['transfer_bps']??0),
        'transfer_10s_bps'=>$recentBps,
        'transfer_min_60s_bps'=>$minimumBps,
        'recent_available'=>$recentAvailable,
        'minimum_available'=>$minimumAvailable,
        'provider_status'=>(int)($selected['provider_status']??0),
        'provider_error'=>(string)($selected['provider_error']??''),
        'stream_error'=>(string)($selected['stream_error']??''),
        'last_output_at'=>$lastOutputAt,
        'output_gap_seconds'=>$outputGap,
        'ffmpeg_stderr'=>(string)($selected['ffmpeg_stderr']??''),
        'tfdt_absolute'=>!empty($selected['tfdt_absolute']),
        'tfdt_last'=>$selected['tfdt_last']??null,
        'tfdt_label'=>$selected['tfdt_label']??null,
        'tfdt_delta'=>$selected['tfdt_delta']??null,
        'tfdt_fragments'=>(int)($selected['tfdt_fragments']??0),
        'tfdt_error'=>(string)($selected['tfdt_error']??''),
        'bytes'=>(int)($selected['bytes']??0),
        'elapsed'=>(float)($selected['elapsed']??0),
        'active'=>!empty($selected['active'])&&$age<=3,
        'age'=>$age,
        'cache_active'=>is_array($cacheStatus)&&!empty($cacheStatus['active']),
        'cache_complete'=>is_array($cacheStatus)&&!empty($cacheStatus['complete']),
        'cache_limit'=>is_array($cacheStatus)&&!empty($cacheStatus['cache_limit']),
        'cache_start'=>is_array($cacheStatus)?(float)($cacheStatus['cache_start']??0):0,
        'cache_end'=>is_array($cacheStatus)?(float)($cacheStatus['cache_end']??0):0,
        'cache_bytes'=>is_array($cacheStatus)?(int)($cacheStatus['bytes']??0):0,
        'cache_seconds'=>is_array($cacheStatus)?(float)($cacheStatus['cached_seconds']??0):0,
        'cache_error'=>is_array($cacheStatus)?(string)($cacheStatus['error']??''):'',
        'cache_client_position'=>is_array($cacheStatus)?($cacheStatus['client_position']??null):null,
        'cache_client_age_seconds'=>is_array($cacheStatus)?(float)($cacheStatus['client_age_seconds']??0):0,
        'cache_behind_bytes'=>is_array($cacheStatus)?(int)($cacheStatus['behind_bytes']??0):0,
        'cache_ahead_bytes'=>is_array($cacheStatus)?(int)($cacheStatus['ahead_bytes']??0):0,
        'cache_behind_seconds'=>is_array($cacheStatus)?(float)($cacheStatus['behind_seconds']??0):0,
        'cache_ahead_seconds'=>is_array($cacheStatus)?(float)($cacheStatus['ahead_seconds']??0):0,
        'cache_keep_behind_bytes'=>is_array($cacheStatus)?(int)($cacheStatus['keep_behind_bytes']??0):0,
        'cache_max_ahead_bytes'=>is_array($cacheStatus)?(int)($cacheStatus['max_ahead_bytes']??0):0,
        'cache_last_action'=>is_array($cacheStatus)?(string)($cacheStatus['last_action']??''):'',
        'cache_contiguous'=>$cacheGap===null,
        'cache_gap'=>$cacheGap,
        'cache_ahead_throttled'=>is_array($cacheStatus)&&!empty($cacheStatus['ahead_throttled']),
        'cache_ahead_wait_seconds'=>is_array($cacheStatus)?(float)($cacheStatus['ahead_wait_seconds']??0):0,
        'cache_last_prune'=>is_array($cacheStatus)?($cacheStatus['last_prune']??null):null,
        'cache_last_tfdt_pin'=>is_array($cacheStatus)?($cacheStatus['last_tfdt_pin']??null):null,
        'cache_origin_pts'=>is_array($cacheStatus)?(float)($cacheStatus['origin_pts']??0):0,
        // Livello relay: byte realmente usciti verso il browser e finestra.
        'relay'=>is_array($relayState)?[
            'age'=>max(0,$now-(float)($relayState['updated']??0)),
            'source_start'=>(float)($relayState['source_start']??0),
            'init_bytes'=>(int)($relayState['init_bytes']??0),
            'window_ahead_seconds'=>(float)($relayState['window_ahead_seconds']??0),
            'window_waiting'=>!empty($relayState['window_waiting']),
            'client_position'=>$relayState['client_position']??null,
            'client_position_age'=>$relayState['client_position_age']??null,
            'next_start'=>(float)($relayState['next_start']??0),
            'served_bytes'=>(int)($relayState['served_bytes']??0),
            'served_count'=>(int)($relayState['served_count']??0),
            'last_served'=>$relayState['last_served']??null,
            'served'=>array_slice($relayServed,-16),
        ]:null,
        'provider_heartbeat'=>$providerHeartbeat,
    ],JSON_UNESCAPED_SLASHES);
}

function openapAptvVodProbeSeekable(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    $id=trim((string)($_GET['id']??''));$kind=openapAptvVodKind((string)($_GET['kind']??''));$providerId=trim((string)($_GET['provider_id']??''));$context=openapAptvProviderContextForRequest($providerId!==''?$providerId:null);$vod=openapAptvVod($id,$kind,$providerId!==''?$providerId:null);
    if(!$vod){http_response_code(403);echo json_encode(['ok'=>false,'message'=>_('Movie unavailable.')]);return;}
    $profile=is_array($context)?(array)($context['profile']??[]):null;if(!$profile){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $cacheDirectory='/var/cache/openap-iptv/vod-probes';if(!is_dir($cacheDirectory))@mkdir($cacheDirectory,02770,true);@chgrp($cacheDirectory,'www-data');@chmod($cacheDirectory,02770);
    $cache=openapAptvVodProbeCacheFile($id,$kind,$providerId!==''?$providerId:null);if(!$cache){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $payload=is_file($cache)&&filemtime($cache)>time()-604800?json_decode((string)file_get_contents($cache),true):null;if(is_array($payload)){$candidate=$payload;$candidate['source_kind']=(string)($vod['source_kind']??'movie');$candidate['series_id']=(string)($vod['series_id']??'');if(openapAptvVodProgressiveCompatible($candidate)&&empty($payload['source_bitrate']))$payload=null;}
    $probeFailure=null;
    if(!is_array($payload)||!array_key_exists('video',$payload)){
        $base='/run/openap-iptv-vod-remux';if(!is_dir($base))@mkdir($base,0700,true);$token=bin2hex(random_bytes(16));$runtime=$base.'/'.$token;
        if(!@mkdir($runtime,0700)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('Unable to create the probe session.')]);return;}
        $proxyArgs=['/usr/bin/php','/usr/local/lib/openap/iptv_vod_proxy.php',$id,$token,$kind];if($providerId!=='')$proxyArgs[]=$providerId;$proxy=@proc_open($proxyArgs,[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',$runtime.'/proxy.log','a']],$proxyPipes);
        $port=0;$deadline=microtime(true)+5;while(microtime(true)<$deadline&&$port<1){$port=(int)@file_get_contents($runtime.'/source.port');if($port<1)usleep(50000);}
        $decoded=null;
        if(!is_resource($proxy)||$port<1)$probeFailure=['code'=>'RELAY_START_FAILED','stage'=>'relay','message'=>_('The local provider relay did not start.')];
        if(is_resource($proxy)&&$port>0){
            $ff=@proc_open(['/usr/bin/ffprobe','-v','error','-rw_timeout','15000000','-read_intervals','0%+3','-show_entries','format=duration,size,bit_rate:stream=index,codec_type,codec_name,profile,pix_fmt,width,height,channels:stream_tags=language,title','-of','json','http://127.0.0.1:'.$port.'/movie'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$runtime.'/ffprobe.log','a']],$pipes);
            if(is_resource($ff)&&isset($pipes[1])){$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$ffprobeStatus=proc_close($ff);if($ffprobeStatus===0)$decoded=json_decode((string)$raw,true);}
        }
        $providerTrace=is_file($runtime.'/provider-status.json')?json_decode((string)@file_get_contents($runtime.'/provider-status.json'),true):null;
        touch($runtime.'/stop');if(is_resource($proxy)){proc_terminate($proxy);proc_close($proxy);}foreach(glob($runtime.'/*')?:[]as$file)@unlink($file);@rmdir($runtime);
        if(is_array($decoded)){
            $audio=[];$subtitles=[];$video=null;
            foreach(($decoded['streams']??[])as$stream){$type=(string)($stream['codec_type']??'');$tags=is_array($stream['tags']??null)?$stream['tags']:[];$entry=['index'=>(int)($stream['index']??0),'codec'=>(string)($stream['codec_name']??''),'language'=>(string)($tags['language']??'und'),'title'=>(string)($tags['title']??'')];if($type==='video'&&$video===null){$entry+=['profile'=>(string)($stream['profile']??''),'pixel_format'=>(string)($stream['pix_fmt']??''),'width'=>(int)($stream['width']??0),'height'=>(int)($stream['height']??0)];$video=$entry;}elseif($type==='audio'){$entry['channels']=(int)($stream['channels']??0);$audio[]=$entry;}elseif($type==='subtitle')$subtitles[]=$entry;}
            $container=strtolower((string)($vod['container_extension']??''));$videoCodec=strtolower((string)($video['codec']??''));$audioCodec=strtolower((string)($audio[0]['codec']??''));$direct=in_array($container,['mp4','m4v'],true)&&$videoCodec==='h264'&&in_array($audioCodec,['aac','mp3'],true);
            $duration=(float)($decoded['format']['duration']??0);$sourceSize=(int)($decoded['format']['size']??0);$sourceBitrate=(int)($decoded['format']['bit_rate']??0);if($sourceBitrate<=0&&$sourceSize>0&&$duration>0)$sourceBitrate=(int)round($sourceSize*8/$duration);$payload=['duration'=>$duration,'source_size'=>$sourceSize,'source_bitrate'=>$sourceBitrate,'video'=>$video,'audio'=>$audio,'subtitles'=>$subtitles,'container'=>$container,'direct_compatible'=>$direct];$temporary=$cache.'.new.'.getmypid();$stored=file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);if($stored===false){$payload=null;}else{chmod($temporary,0640);@chgrp($temporary,'www-data');if(!rename($temporary,$cache)){$payload=null;@unlink($temporary);}}
        }
        if(!is_array($decoded)&&$probeFailure===null){
            $providerStatus=(int)($providerTrace['http_status']??0);$providerType=strtolower(trim((string)($providerTrace['content_type']??'')));$providerBytes=(int)($providerTrace['bytes']??0);$providerClass=(string)($providerTrace['error_class']??'');
            if($providerStatus>=400){$probeFailure=['code'=>'PROVIDER_HTTP_ERROR','stage'=>'provider','message'=>sprintf(_('The provider returned HTTP %d.'),$providerStatus),'http_status'=>502];}
            elseif($providerClass==='timeout'){$probeFailure=['code'=>'PROVIDER_TIMEOUT','stage'=>'provider','message'=>_('The provider did not answer within the allowed time.'),'http_status'=>504];}
            elseif($providerStatus>=200&&$providerStatus<300&&$providerBytes===0&&str_starts_with($providerType,'text/html')){$probeFailure=['code'=>'PROVIDER_NON_MEDIA_RESPONSE','stage'=>'provider','message'=>sprintf(_('The provider returned HTTP %d (%s) instead of media data.'),$providerStatus,$providerType),'http_status'=>502];}
            elseif($providerStatus>=200&&$providerStatus<300&&$providerBytes===0){$probeFailure=['code'=>'PROVIDER_EMPTY_RESPONSE','stage'=>'provider','message'=>_('The provider returned an empty media response.'),'http_status'=>502];}
            else{$probeFailure=['code'=>'PROBE_NO_MEDIA','stage'=>'probe','message'=>_('The provider response could not be analyzed as media.'),'http_status'=>502];}
            $probeFailure['provider_status']=$providerStatus;$probeFailure['provider_content_type']=$providerType;$probeFailure['provider_bytes']=$providerBytes;
        }
    }
    if(!is_array($payload)){$probeFailure=$probeFailure?:['code'=>'PROBE_NO_MEDIA','stage'=>'probe','message'=>_('The provider response could not be analyzed as media.'),'http_status'=>502];unset($probeFailure['http_status']);http_response_code(200);echo json_encode(['ok'=>false]+$probeFailure,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return;}
    $payload['source_kind']=(string)($vod['source_kind']??'movie');$payload['series_id']=(string)($vod['series_id']??'');$payload['direct_compatible']=openapAptvVodProgressiveCompatible($payload);$payload['progressive_series']=$payload['source_kind']==='series'&&$payload['direct_compatible'];$payload['playback_class']=openapAptvVodPlaybackClass($payload);$payload['hls_compatible']=in_array($payload['playback_class'],['hls_remux','hls_audio_transcode','hls_video_transcode'],true);echo json_encode(['ok'=>true]+$payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function openapAptvWebrtcHeartbeat(): void
{
    $dir=openapAptvWebrtcDirectory((string)($_POST['token']??''));if($dir&&is_dir($dir))touch($dir.'/heartbeat');http_response_code(204);
}
function openapAptvStopWebrtc(): void
{
    $dir=openapAptvWebrtcDirectory((string)($_POST['token']??''));if($dir&&is_dir($dir))touch($dir.'/stop');http_response_code(204);
}

function openapIptvCatalogBrowse(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $collection = openapAptvProviderCollection();
    $activeProviderId = (string) ($collection['active_id'] ?? '');
    $requestedProviderId = trim((string) ($_GET['provider_id'] ?? ''));
    if ($requestedProviderId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/', $requestedProviderId) || !openapAptvProviderProfileById($requestedProviderId)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => _('Invalid provider profile.')]);
            return;
        }
        $activeProviderId = $requestedProviderId;
    }
    $posterSuffix = $activeProviderId !== '' ? '&provider_id=' . rawurlencode($activeProviderId) : '';
    $path = openapAptvProviderCatalogPath($requestedProviderId !== '' ? $requestedProviderId : null);
    if (!is_readable($path)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'message' => _('The APtv catalog is not available.')]);
        return;
    }

    $view = (string) ($_GET['view'] ?? 'regions');
    $prefix = trim((string) ($_GET['region'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $offset = max(0, min(1000000, (int) ($_GET['offset'] ?? 0)));
    $limit = max(1, min(2000, (int) ($_GET['limit'] ?? 40)));
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1500);
        if ($view === 'regions') {
            $sql = "SELECT ir.region_code AS code, COUNT(DISTINCT i.category_id) AS categories, COUNT(i.item_id) AS channels FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' GROUP BY ir.region_code ORDER BY ir.region_code";
            $query = $db->query($sql);
            $items = [];
            while ($row = $query->fetchArray(SQLITE3_ASSOC)) $items[] = $row;
            openapAptvApplySavedOrder($items, 'region', 'global', 'code', $requestedProviderId !== '' ? $requestedProviderId : null);
            echo json_encode(['ok' => true, 'view' => 'regions', 'items' => $items]);
        } elseif ($view === 'categories' && preg_match('/^[A-Za-z0-9]{1,8}$/', $prefix)) {
            $statement = $db->prepare("SELECT c.category_id, trim(substr(c.name,instr(c.name,'|')+1)) AS name, COUNT(i.item_id) AS channels FROM categories c JOIN items i ON i.kind=c.kind AND i.category_id=c.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE c.kind='live' AND ir.region_code=:prefix GROUP BY c.category_id,c.name ORDER BY name COLLATE NOCASE");
            $statement->bindValue(':prefix', $prefix, SQLITE3_TEXT);
            $query = $statement->execute();
            $items = [];
            while ($row = $query->fetchArray(SQLITE3_ASSOC)) $items[] = $row;
            openapAptvApplySavedOrder($items, 'category', $prefix, 'category_id', $requestedProviderId !== '' ? $requestedProviderId : null);
            echo json_encode(['ok' => true, 'view' => 'categories', 'region' => $prefix, 'items' => $items]);
        } elseif ($view === 'channels' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $category)) {
            $channelRegion = strtoupper(trim((string)($_GET['region'] ?? '')));
            $channelRegionWhere = ($channelRegion !== '' && $channelRegion !== '__FAVORITES__') ? ' AND ir.region_code=:region' : '';
            $countStatement = $db->prepare("SELECT COUNT(*) FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND i.category_id=:category$channelRegionWhere");
            $countStatement->bindValue(':category', $category, SQLITE3_TEXT);
            if ($channelRegionWhere !== '') $countStatement->bindValue(':region', $channelRegion, SQLITE3_TEXT);
            $total = (int) $countStatement->execute()->fetchArray(SQLITE3_NUM)[0];
            $statement = $db->prepare("SELECT i.item_id,i.name,i.icon_url,i.epg_channel_id,ir.region_code AS country_code FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND i.category_id=:category$channelRegionWhere ORDER BY i.name COLLATE NOCASE");
            $statement->bindValue(':category', $category, SQLITE3_TEXT);
            if ($channelRegionWhere !== '') $statement->bindValue(':region', $channelRegion, SQLITE3_TEXT);
            $query = $statement->execute();
            $items = [];
            while ($row = $query->fetchArray(SQLITE3_ASSOC)) { $row['provider_id'] = $activeProviderId; $items[] = $row; }
            openapAptvApplySavedOrder($items, 'channel', $category, 'item_id', $requestedProviderId !== '' ? $requestedProviderId : null);
            $page = array_slice($items, $offset, $limit);
            echo json_encode(['ok' => true, 'view' => 'channels', 'items' => $page, 'offset' => $offset, 'limit' => $limit, 'total' => $total, 'has_more' => $offset + count($page) < $total]);
        } else {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => _('Invalid catalog request.')]);
        }
        $db->close();
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => _('Unable to read the APtv catalog.')]);
    }
}

function openapAptvCategoryRegionTokens(): array
{
    return [
        'IT' => ['IT', 'ITALY', 'ITALIAN', 'ITALIA'],
        'AL' => ['AL', 'ALB', 'ALBANIA', 'ALBANIAN'],
        'AT' => ['AT', 'AUSTRIA', 'AUSTRIAN'],
        'AU' => ['AU', 'AUSTRALIA', 'AUSTRALIAN'],
        'BE' => ['BE', 'BELGIUM', 'BELGIAN'],
        'BG' => ['BG', 'BULGARIA', 'BULGARIAN'],
        'BR' => ['BR', 'BRAZIL', 'BRAZILIAN'],
        'CA' => ['CA', 'CANADA', 'CANADIAN'],
        'CH' => ['CH', 'SWITZERLAND', 'SWISS'],
        'CN' => ['CN', 'CHINA', 'CHINESE'],
        'CZ' => ['CZ', 'CZECH'],
        'CY' => ['CY', 'CYPRUS', 'CYPRIOT'],
        'DK' => ['DK', 'DENMARK', 'DANISH'],
        'DE' => ['DE', 'GERMANY', 'GERMAN', 'DEUTSCH'],
        'EE' => ['EE', 'ESTONIA', 'ESTONIAN'],
        'ES' => ['ES', 'ESPAÑA', 'SPAIN', 'SPANISH'],
        'FI' => ['FI', 'FINLAND', 'FINNISH'],
        'FR' => ['FR', 'FRANCE', 'FRENCH'],
        'GE' => ['GE', 'GEORGIA', 'GEORGIAN'],
        'GR' => ['GR', 'GREECE', 'GREEK'],
        'HR' => ['HR', 'CROATIA', 'CROATIAN'],
        'HU' => ['HU', 'HUNGARY', 'HUNGARIA', 'HUNGARIAN'],
        'ID' => ['ID', 'INDONESIA', 'INDONESIAN'],
        'IE' => ['IE', 'IRELAND', 'IRISH'],
        'IL' => ['IL', 'ISRAEL', 'ISRAELI'],
        'IR' => ['IR', 'IRAN', 'IRANIAN', 'PERSIAN'],
        'IS' => ['IS', 'ICELAND', 'ICELANDIC'],
        'JP' => ['JP', 'JAPAN', 'JAPANESE'],
        'KR' => ['KR', 'KOREA', 'KOREAN'],
        'KZ' => ['KZ', 'KAZAKHSTAN', 'KAZAKH'],
        'MA' => ['MA', 'MY', 'MALAYSIA', 'MALAYSIAN'],
        'MX' => ['MX', 'MEXICO', 'MEXICAN'],
        'NL' => ['NL', 'NETHERLAND', 'NETHERLANDS', 'DUTCH'],
        'NO' => ['NO', 'NORWAY', 'NORWEGIAN'],
        'PH' => ['PH', 'PHILIPPINES', 'FILIPINO'],
        'PL' => ['PL', 'POLAND', 'POLISH'],
        'PT' => ['PT', 'PORTUGAL', 'PORTUGUESE'],
        'RO' => ['RO', 'ROMANIA', 'ROMANIAN'],
        'RU' => ['RU', 'RUSSIA', 'RUSSIAN'],
        'SE' => ['SE', 'SWEDEN', 'SWEDISH'],
        'SI' => ['SI', 'SLOVENIA', 'SLOVENIJA', 'SLOVENIAN'],
        'SK' => ['SK', 'SLOVAKIA', 'SLOVAK'],
        'TH' => ['TH', 'THAILAND', 'THAI'],
        'TR' => ['TR', 'TURKEY', 'TURKISH'],
        'UA' => ['UA', 'UKRAINE', 'UKRAIN', 'UKRAINIAN'],
        'UK' => ['UK', 'EN', 'ENGLISH', 'UNITED KINGDOM', 'BRITISH'],
        'US' => ['US', 'USA', 'AMERICA', 'AMERICAN', 'UNITED STATES'],
        'VE' => ['VE', 'VENEZUELA', 'VENEZUELAN'],
        'VI' => ['VI', 'VN', 'VIETNAM', 'VIETNAMESE'],
        'ZA' => ['ZA', 'SOUTH AFRICA', 'SOUTH AFRICAN'],
        'CR' => ['CR', 'CARIBBEAN', 'CARIBBIEAN'],
        'EXYU' => ['EXYU', 'FORMER YUGOSLAVIA'],
        'LA' => ['LA', 'LATINO', 'LATIN AMERICA'],
    ];
}

function openapAptvCategoryRegionFromName(string $kind, string $categoryName): string
{
    $value = trim(mb_strtoupper($categoryName, 'UTF-8'));
    if ($value === '') return '';
    if ($kind === 'live' && preg_match('/^([A-Z]{2,3})\s*\|/u', $value, $match)) {
        $liveCode = strtoupper($match[1]);
        return $liveCode === 'EN' ? 'UK' : ($liveCode === 'ALB' ? 'AL' : $liveCode);
    }
    foreach (openapAptvCategoryRegionTokens() as $code => $tokens) {
        foreach ($tokens as $token) {
            if (strlen($token) <= 3) {
                if (preg_match('/(?:^|[\/|])\s*' . preg_quote($token, '/') . '(?:\s*[-|\/]|\s*$)/u', $value)) return $code;
            } elseif (preg_match('/(?:^|[\s\/|_-])' . preg_quote($token, '/') . '(?:$|[\s\/|_-])/u', $value)) {
                return $code;
            }
        }
    }
    return 'OTHER';
}

function openapAptvCategoryRegionSql(string $column = 'name'): string
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_.]*$/', $column)) throw new InvalidArgumentException('Invalid category column');
    $matchColumn = "REPLACE(" . $column . ", char(160), ' ')";
    $branches = [];
    foreach (openapAptvCategoryRegionTokens() as $code => $tokens) {
        $tests = [];
        foreach ($tokens as $token) {
            if (strlen($token) <= 3) {
                $tests[] = $matchColumn . " GLOB '" . $token . "'";
                $tests[] = $matchColumn . " GLOB '" . $token . " [-|/]*'";
                $tests[] = $matchColumn . " GLOB '* / " . $token . "'";
                $tests[] = $matchColumn . " GLOB '* / " . $token . " [-|/]*'";
                $tests[] = $matchColumn . " GLOB '* | " . $token . "'";
                $tests[] = $matchColumn . " GLOB '* | " . $token . " [-|/]*'";
                // Some providers omit the space before the delimiter, e.g. "IT| GENERAL".
                $tests[] = $matchColumn . " GLOB '" . $token . "|*'";
                $tests[] = $matchColumn . " GLOB '" . $token . "/*'";
                $tests[] = $matchColumn . " GLOB '" . $token . "-*'";
            } else {
                $tests[] = 'UPPER(' . $matchColumn . ") LIKE '%" . str_replace("'", "''", $token) . "%'";
            }
        }
        $branches[] = 'WHEN (' . implode(' OR ', $tests) . ') THEN ' . "'" . $code . "'";
    }
    return 'CASE ' . implode(' ', $branches) . " ELSE 'OTHER' END";
}

function openapAptvGlobalSearch(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $search = trim((string) ($_GET['q'] ?? ''));
    $kind = (string) ($_GET['kind'] ?? 'all');
    $sort = (string) ($_GET['sort'] ?? 'relevance');
    $region = strtoupper(trim((string) ($_GET['region'] ?? '')));
    if (mb_strlen($search) < 2 || mb_strlen($search) > 100) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Enter at least two characters.')]); return;
    }
    if (!in_array($kind, ['all', 'live', 'vod', 'series'], true) || !in_array($sort, ['relevance', 'title', 'recent'], true)) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid search filter.')]); return;
    }
    if ($region !== '' && ($kind !== 'live' || !preg_match('/^[A-Z0-9]{1,8}$/', $region))) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid channel region.')]); return;
    }
    $resultLimit = max(1, min($kind === 'live' ? 5000 : 80, (int) ($_GET['limit'] ?? ($kind === 'live' ? 5000 : 80))));
    preg_match_all('/[\p{L}\p{N}]+/u', $search, $terms);
    $terms = array_slice(array_filter($terms[0] ?? [], static fn(string $term): bool => mb_strlen($term) > 0), 0, 10);
    if (!$terms) { http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Enter letters or numbers to search.')]); return; }
    $match = implode(' AND ', array_map(static fn(string $term): string => '"' . str_replace('"', '""', $term) . '"*', $terms));
    $order = match ($sort) {
        'title' => 'i.name COLLATE NOCASE, rank',
        'recent' => "CASE WHEN i.added GLOB '[0-9]*' THEN CAST(i.added AS INTEGER) ELSE 0 END DESC, rank, i.name COLLATE NOCASE",
        default => 'rank, i.name COLLATE NOCASE',
    };
    $collection = openapAptvProviderCollection();
    $activeProviderId = (string) ($collection['active_id'] ?? '');
    $requestedProviderId = trim((string) ($_GET['provider_id'] ?? ''));
    if ($requestedProviderId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/', $requestedProviderId) || !openapAptvProviderProfileById($requestedProviderId)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => _('Invalid provider profile.')]);
            return;
        }
        $activeProviderId = $requestedProviderId;
    }
    try {
        $db = new SQLite3(openapAptvProviderCatalogPath($requestedProviderId !== '' ? $requestedProviderId : null), SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1500);
        $countryCode = static fn(string $itemKind, string $categoryName): string => openapAptvCategoryRegionFromName($itemKind, $categoryName);
        $regionWhere = $region !== '' ? " AND ir.region_code=:region" : '';
        $statement = $db->prepare("SELECT f.kind,f.item_id,f.name,f.category_name AS group_name,i.category_id,i.icon_url,i.rating,i.added,ir.region_code AS indexed_region,CASE WHEN i.icon_url IS NULL OR i.icon_url='' THEN 0 ELSE 1 END AS has_icon,bm25(catalog_fts,1.0,0.35) AS rank FROM catalog_fts f JOIN items i ON i.kind=f.kind AND i.item_id=f.item_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE catalog_fts MATCH :match AND (:kind='all' OR f.kind=:kind)$regionWhere ORDER BY $order LIMIT :result_limit");
        $statement->bindValue(':match', $match, SQLITE3_TEXT); $statement->bindValue(':kind', $kind, SQLITE3_TEXT); $statement->bindValue(':result_limit', $resultLimit, SQLITE3_INTEGER); if ($region !== '') $statement->bindValue(':region', $region, SQLITE3_TEXT);
        $query = $statement->execute(); $items = [];
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $itemKind = (string) $row['kind'];
            $categoryName = (string) ($row['group_name'] ?? '');
            $country = (string) ($row['indexed_region'] ?? '') ?: $countryCode($itemKind, $categoryName);
            $displayName = trim((string) $row['name']);
            if ($itemKind === 'live' && preg_match('/^([A-Za-z]{2,3})\s*[-|:]\s*/u', $displayName, $prefix)) {
                $country = strtoupper($prefix[1]);
                $displayName = trim(substr($displayName, strlen($prefix[0])));
            }
            if ($itemKind === 'live') $displayName = trim(preg_replace('/#+/u', ' ', $displayName) ?? $displayName);
            $row['country_code'] = $country;
            $row['name'] = $displayName;
            if ($itemKind === 'live') $row['provider_id'] = $activeProviderId;
            $row['poster_url'] = $row['has_icon'] && $itemKind !== 'live'
                ? openapAptvPosterStaticUrl($itemKind, $activeProviderId, (string) $row['item_id'], (string) ($row['icon_url'] ?? ''))
                : '';
            $row['result_icon_url'] = $itemKind === 'live' ? (string) ($row['icon_url'] ?? '') : (string) $row['poster_url'];
            unset($row['icon_url'], $row['has_icon'], $row['rank']); $items[] = $row;
        }
        $db->close(); echo json_encode(['ok' => true, 'items' => $items, 'truncated' => count($items) === $resultLimit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        error_log('APtv global search failed: ' . $error->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'message' => _('Unable to search the APtv catalog.')]);
    }
}

function openapAptvApplySavedOrder(array &$items, string $scope, string $parent, string $idKey, ?string $providerId = null): void
{
    $path = $providerId !== null && preg_match('/^[a-f0-9]{32}$/', $providerId)
        ? '/var/lib/openap-iptv/providers/' . $providerId . '/preferences/catalog-order.db'
        : '/var/lib/openap-iptv/preferences/catalog-order.db';
    if (!is_readable($path) || !$items) return;
    try {
        $preferences = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $statement = $preferences->prepare('SELECT item_id, position FROM catalog_order WHERE scope=:scope AND parent_id=:parent ORDER BY position');
        $statement->bindValue(':scope', $scope, SQLITE3_TEXT);
        $statement->bindValue(':parent', $parent, SQLITE3_TEXT);
        $result = $statement->execute();
        $positions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) $positions[(string) $row['item_id']] = (int) $row['position'];
        $preferences->close();
        if (!$positions) return;
        $fallback = count($positions) + count($items);
        usort($items, static function (array $a, array $b) use ($positions, $idKey, $fallback): int {
            $aPosition = $positions[(string) ($a[$idKey] ?? '')] ?? $fallback;
            $bPosition = $positions[(string) ($b[$idKey] ?? '')] ?? $fallback;
            if ($aPosition !== $bPosition) return $aPosition <=> $bPosition;
            return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
    } catch (Throwable $error) {
        error_log('Unable to apply APtv catalog order: ' . $error->getMessage());
    }
}

function openapAptvCatalogAllowedItems(SQLite3 $catalog, string $scope, string $parent): array
{
    if ($scope === 'region') {
        if ($parent !== 'global') throw new InvalidArgumentException('Invalid region parent');
        $statement = $catalog->prepare("SELECT DISTINCT region_code AS item_id FROM item_regions WHERE kind='live'");
    } elseif ($scope === 'category') {
        $statement = $catalog->prepare("SELECT DISTINCT i.category_id AS item_id FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='live' AND ir.region_code=:parent");
    } elseif ($scope === 'channel') {
        $statement = $catalog->prepare("SELECT item_id FROM items WHERE kind='live' AND category_id=:parent");
    } elseif ($scope === 'vod_region') {
        if ($parent !== 'global') throw new InvalidArgumentException('Invalid movie region parent');
        $statement = $catalog->prepare("SELECT DISTINCT region_code AS item_id FROM item_regions WHERE kind='vod'");
    } elseif ($scope === 'vod_category') {
        $statement = $catalog->prepare("SELECT DISTINCT i.category_id AS item_id FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='vod' AND ir.region_code=:parent");
    } elseif ($scope === 'series_region') {
        if ($parent !== 'global') throw new InvalidArgumentException('Invalid series region parent');
        $statement = $catalog->prepare("SELECT DISTINCT region_code AS item_id FROM item_regions WHERE kind='series'");
    } elseif ($scope === 'series_category') {
        $statement = $catalog->prepare("SELECT DISTINCT i.category_id AS item_id FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='series' AND ir.region_code=:parent");
    } else {
        throw new InvalidArgumentException('Invalid catalog scope');
    }
    if (!in_array($scope, ['region','vod_region','series_region'], true)) $statement->bindValue(':parent', $parent, SQLITE3_TEXT);
    $result = $statement->execute(); $allowed = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $allowed[(string) $row['item_id']] = true;
    return $allowed;
}

function openapAptvSaveCatalogOrder(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $scope = (string) ($_GET['scope'] ?? '');
        $parent = trim((string) ($_GET['parent'] ?? ''));
        $providerId = trim((string) ($_GET['provider_id'] ?? ''));
        if ($providerId !== '' && (!preg_match('/^[a-f0-9]{32}$/', $providerId) || !openapAptvProviderProfileById($providerId))) {
            http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid provider profile.')]); return;
        }
        if (!in_array($scope, ['region', 'category', 'channel', 'vod_region', 'vod_category', 'series_region', 'series_category'], true)
            || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $parent)) {
            http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid order request.')]); return;
        }
        $preferencesPath = $providerId !== ''
            ? '/var/lib/openap-iptv/providers/' . $providerId . '/preferences/catalog-order.db'
            : '/var/lib/openap-iptv/preferences/catalog-order.db';
        $order = [];
        try {
            if (is_readable($preferencesPath)) {
                $preferences = new SQLite3($preferencesPath, SQLITE3_OPEN_READONLY);
                $statement = $preferences->prepare('SELECT item_id FROM catalog_order WHERE scope=:scope AND parent_id=:parent ORDER BY position');
                $statement->bindValue(':scope', $scope, SQLITE3_TEXT);
                $statement->bindValue(':parent', $parent, SQLITE3_TEXT);
                $result = $statement->execute();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $order[] = (string) $row['item_id'];
                $preferences->close();
            }
            echo json_encode(['ok' => true, 'scope' => $scope, 'parent' => $parent, 'order' => $order]);
        } catch (Throwable $error) {
            error_log('Unable to read APtv catalog order: ' . $error->getMessage());
            http_response_code(500); echo json_encode(['ok' => false, 'message' => _('Unable to read the channel order.')]);
        }
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['ok' => false, 'message' => _('Method not allowed.')]); return;
    }
    $scope = (string) ($_POST['scope'] ?? '');
    $parent = trim((string) ($_POST['parent'] ?? ''));
    $providerId = trim((string) ($_POST['provider_id'] ?? ''));
    if ($providerId !== '' && (!preg_match('/^[a-f0-9]{32}$/', $providerId) || !openapAptvProviderProfileById($providerId))) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid provider profile.')]); return;
    }
    $order = json_decode((string) ($_POST['order'] ?? ''), true);
    if (!in_array($scope, ['region', 'category', 'channel', 'vod_region', 'vod_category', 'series_region', 'series_category'], true) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $parent) || !is_array($order) || count($order) > 5000) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('Invalid order request.')]); return;
    }
    $order = array_values(array_unique(array_map('strval', $order)));
    if (count($order) === 0 || count($order) > 5000) {
        http_response_code(422); echo json_encode(['ok' => false, 'message' => _('The order cannot be empty.')]); return;
    }
    try {
        $catalog = new SQLite3(openapAptvProviderCatalogPath($providerId !== '' ? $providerId : null), SQLITE3_OPEN_READONLY);
        $allowed = openapAptvCatalogAllowedItems($catalog, $scope, $parent);
        $catalog->close();
        foreach ($order as $id) if (!isset($allowed[$id])) throw new InvalidArgumentException('Unknown catalog item');
        $preferencesPath = $providerId !== ''
            ? '/var/lib/openap-iptv/providers/' . $providerId . '/preferences/catalog-order.db'
            : '/var/lib/openap-iptv/preferences/catalog-order.db';
        $preferences = new SQLite3($preferencesPath, SQLITE3_OPEN_READWRITE);
        $preferences->busyTimeout(1500); $preferences->exec('BEGIN IMMEDIATE');
        $delete = $preferences->prepare('DELETE FROM catalog_order WHERE scope=:scope AND parent_id=:parent');
        $delete->bindValue(':scope', $scope, SQLITE3_TEXT); $delete->bindValue(':parent', $parent, SQLITE3_TEXT); $delete->execute();
        $insert = $preferences->prepare('INSERT INTO catalog_order(scope,parent_id,item_id,position,updated_at) VALUES(:scope,:parent,:item,:position,:updated)');
        foreach ($order as $position => $id) {
            $insert->reset(); $insert->bindValue(':scope', $scope, SQLITE3_TEXT); $insert->bindValue(':parent', $parent, SQLITE3_TEXT);
            $insert->bindValue(':item', $id, SQLITE3_TEXT); $insert->bindValue(':position', $position, SQLITE3_INTEGER); $insert->bindValue(':updated', time(), SQLITE3_INTEGER); $insert->execute();
        }
        $preferences->exec('COMMIT'); $preferences->close();
        echo json_encode(['ok' => true, 'saved' => count($order)]);
    } catch (Throwable $error) {
        if (isset($preferences)) @$preferences->exec('ROLLBACK');
        error_log('Unable to save APtv catalog order: ' . $error->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'message' => _('Unable to save the channel order.')]);
    }
}

function openapAptvRestoreProviderCollection(array $provider, string $mode): array
{
    $descriptor = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $process = proc_open(['sudo','-n','/usr/local/sbin/openap-iptv-index','--stdin'], $descriptor, $pipes);
    if (!is_resource($process)) throw new RuntimeException(_('Unable to restore provider credentials.'));
    $payload = json_encode(['operation'=>'restore_collection','mode'=>$mode,'provider'=>$provider], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    fwrite($pipes[0], $payload); fclose($pipes[0]); sodium_memzero($payload);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $output, true);
    if ($exitCode !== 0 || !is_array($result) || empty($result['ok'])) {
        throw new RuntimeException(_('Unable to restore provider credentials.'));
    }
    return $result;
}

function openapAptvCatalogProfile(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $exportRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export';
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $exportRequest) {
        if ($exportRequest && !\OpenAP\Tokens\CSRF::verify()) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'message'=>_('Invalid security token. Reload the page.')]);
            return;
        }
        openap_release_session();
        $requestedProviderId = trim((string) ($_POST['provider_id'] ?? $_GET['provider_id'] ?? ''));
        if ($requestedProviderId !== '' && (!preg_match('/^[a-f0-9]{32}$/', $requestedProviderId) || !openapAptvProviderProfileById($requestedProviderId))) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]);
            return;
        }
        $collection = openapAptvProviderCollection();
        $sourceProfiles = array_values(array_filter(
            $collection['profiles'] ?? [],
            static fn(mixed $profile): bool => is_array($profile) && ($requestedProviderId === '' || hash_equals($requestedProviderId, (string) ($profile['id'] ?? '')))
        ));
        $providers = [];
        foreach ($sourceProfiles as $profile) {
            $providers[] = [
                'id'=>(string)($profile['id']??''),
                'name'=>(string)($profile['name']??''),
                'password'=>(string)($profile['password']??''),
                'api_version'=>(string)($profile['api_version']??'v2'),
            ];
        }
        if (!$providers) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'message'=>_('No provider profile is available for backup.')]);
            return;
        }
        if (!$exportRequest) {
            foreach ($providers as &$provider) unset($provider['password']);
            unset($provider);
        } else {
            foreach ($sourceProfiles as $index => $profile) {
                $providers[$index]['url']=(string)($profile['url']??'');
                $providers[$index]['username']=(string)($profile['username']??'');
            }
        }
        $readOrders = static function (string $path): array {
            if (!is_readable($path)) return [];
            try {
                $preferences = new SQLite3($path, SQLITE3_OPEN_READONLY);
                $result = $preferences->query('SELECT scope,parent_id,item_id FROM catalog_order ORDER BY scope,parent_id,position');
                $groups = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $key = (string) $row['scope'] . "\0" . (string) $row['parent_id'];
                    if (!isset($groups[$key])) $groups[$key] = ['scope'=>(string)$row['scope'],'parent'=>(string)$row['parent_id'],'order'=>[]];
                    $groups[$key]['order'][] = (string) $row['item_id'];
                }
                $preferences->close();
                return array_values($groups);
            } catch (Throwable $error) {
                error_log('Unable to export APtv catalog order: ' . $error->getMessage());
                return [];
            }
        };
        $providerOrders = [];
        foreach ($providers as $provider) {
            $providerId = (string) ($provider['id'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) continue;
            $providerOrders[] = [
                'provider_id' => $providerId,
                'orders' => $readOrders('/var/lib/openap-iptv/providers/' . $providerId . '/preferences/catalog-order.db'),
            ];
        }
        $activeId = $requestedProviderId !== '' ? $requestedProviderId : (string)($collection['active_id']??'');
        $activeOrders = [];
        foreach ($providerOrders as $providerOrder) {
            if (hash_equals($activeId, (string) ($providerOrder['provider_id'] ?? ''))) {
                $activeOrders = $providerOrder['orders'];
                break;
            }
        }
        echo json_encode(['ok'=>true,'profile'=>[
            'format'=>'openap-aptv-menu-profile','version'=>2,'exported_at'=>gmdate('c'),
            'provider_scope'=>$requestedProviderId !== '' ? 'single' : 'all',
            'selected_provider_id'=>$requestedProviderId,
            'provider'=>['active_id'=>$activeId,'profiles'=>$providers],
            'provider_orders'=>$providerOrders,
            'orders'=>$activeOrders,
            'favorites'=>[]
        ]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405); echo json_encode(['ok'=>false,'message'=>_('Method not allowed.')]); return;
    }
    $mode=(string)($_POST['mode']??'replace');
    if (!in_array($mode,['replace','merge','reset'],true)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>_('Invalid profile import mode.')]); return; }
    openap_release_session();
    try {
        if ($mode==='reset') {
            $collection = openapAptvProviderCollection();
            $activeId = (string) ($collection['active_id'] ?? '');
            $preferencesPath = preg_match('/^[a-f0-9]{32}$/', $activeId)
                ? '/var/lib/openap-iptv/providers/' . $activeId . '/preferences/catalog-order.db'
                : '/var/lib/openap-iptv/preferences/catalog-order.db';
            $preferences = new SQLite3($preferencesPath, SQLITE3_OPEN_READWRITE);
            $preferences->exec('BEGIN IMMEDIATE'); $preferences->exec('DELETE FROM catalog_order');
            $preferences->exec('COMMIT'); $preferences->close(); echo json_encode(['ok'=>true,'mode'=>'reset']); return;
        }
        $profile=json_decode((string)($_POST['profile']??''),true);
        $profileVersion=(int)($profile['version']??0);
        if (!is_array($profile)||($profile['format']??'')!=='openap-aptv-menu-profile'||!in_array($profileVersion,[1,2],true)||!is_array($profile['orders']??null)) throw new InvalidArgumentException('Invalid APtv menu profile');
        $providerScope=(string)($profile['provider_scope']??'all');
        $singleProviderBackup=$profileVersion>=2&&$providerScope==='single';
        if (array_key_exists('provider', $profile) && is_array($profile['provider']) && is_array($profile['provider']['profiles'] ?? null)) {
            $hasCredentials = false;
            foreach ($profile['provider']['profiles'] as $providerProfile) if (is_array($providerProfile) && (string)($providerProfile['password'] ?? '') !== '') { $hasCredentials = true; break; }
            if ($hasCredentials) openapAptvRestoreProviderCollection($profile['provider'], $singleProviderBackup ? 'merge' : $mode);
        }
        $providerOrders = [];
        if ($profileVersion >= 2 && is_array($profile['provider_orders'] ?? null)) {
            $providerOrders = $profile['provider_orders'];
        } else {
            $legacyProviderId = (string)($profile['provider']['active_id']??'');
            $providerOrders[] = ['provider_id'=>$legacyProviderId,'orders'=>$profile['orders']];
        }
        $saved=0; $skipped=0;
        foreach ($providerOrders as $providerOrder) {
            if (!is_array($providerOrder)) {$skipped++;continue;}
            $providerId=trim((string)($providerOrder['provider_id']??''));$orders=$providerOrder['orders']??[];
            if (!preg_match('/^[a-f0-9]{32}$/',$providerId)||!openapAptvProviderProfileById($providerId)||!is_array($orders)){ $skipped++;continue; }
            $catalogPath=openapAptvProviderCatalogPath($providerId);$preferencesPath='/var/lib/openap-iptv/providers/'.$providerId.'/preferences/catalog-order.db';
            // Version 1 backups were written against the active global
            // catalog/preferences symlinks. Keep those backups importable;
            // version 2 remains strictly provider-scoped.
            if ($profileVersion === 1 && (!is_readable($catalogPath) || !is_readable($preferencesPath))) {
                $catalogPath='/var/lib/openap-iptv/catalog.db';
                $preferencesPath='/var/lib/openap-iptv/preferences/catalog-order.db';
            }
            if (!is_readable($catalogPath)||!is_readable($preferencesPath)){ $skipped++;continue; }
            $catalog=new SQLite3($catalogPath,SQLITE3_OPEN_READONLY);$preferences=new SQLite3($preferencesPath,SQLITE3_OPEN_READWRITE);$preferences->busyTimeout(1500);$preferences->exec('BEGIN IMMEDIATE');
            if ($mode==='replace') $preferences->exec('DELETE FROM catalog_order');
            $delete=$preferences->prepare('DELETE FROM catalog_order WHERE scope=:scope AND parent_id=:parent');$insert=$preferences->prepare('INSERT INTO catalog_order(scope,parent_id,item_id,position,updated_at) VALUES(:scope,:parent,:item,:position,:updated)');
            foreach ($orders as $entry) {
                if (!is_array($entry)) {$skipped++;continue;}
                $scope=(string)($entry['scope']??'');$parent=trim((string)($entry['parent']??''));$order=$entry['order']??[];
                if (!in_array($scope,['region','category','channel','vod_region','vod_category','series_region','series_category'],true)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/',$parent)||!is_array($order)||count($order)>5000){$skipped++;continue;}
                $order=array_values(array_unique(array_map('strval',$order)));$allowed=openapAptvCatalogAllowedItems($catalog,$scope,$parent);$order=array_values(array_filter($order,static fn(string $id):bool=>isset($allowed[$id])));
                if (!$order){$skipped++;continue;}
                $delete->reset();$delete->bindValue(':scope',$scope,SQLITE3_TEXT);$delete->bindValue(':parent',$parent,SQLITE3_TEXT);$delete->execute();
                foreach($order as $position=>$id){$insert->reset();$insert->bindValue(':scope',$scope,SQLITE3_TEXT);$insert->bindValue(':parent',$parent,SQLITE3_TEXT);$insert->bindValue(':item',$id,SQLITE3_TEXT);$insert->bindValue(':position',$position,SQLITE3_INTEGER);$insert->bindValue(':updated',time(),SQLITE3_INTEGER);$insert->execute();$saved++;}
            }
            $catalog->close();$preferences->exec('COMMIT');$preferences->close();
        }
        echo json_encode(['ok'=>true,'mode'=>$mode,'saved'=>$saved,'skipped'=>$skipped]);
    } catch (Throwable $error) {
        if (isset($preferences)) @$preferences->exec('ROLLBACK');
        error_log('Unable to import APtv catalog profile: ' . $error->getMessage());
        http_response_code(422); echo json_encode(['ok'=>false,'message'=>_('Unable to import the APtv menu profile.')]);
    }
}

/**
 * Serve the small, rebuild-time materialized navigation payload.  The ETag
 * is derived from nav_fingerprint, not the provider payload fingerprint:
 * metadata-only catalog changes must not invalidate navigation clients.
 */
function openapAptvTryServeCatalogNav(SQLite3 $db, string $kind, string $providerId): bool
{
    $navFingerprint = $db->querySingle("SELECT value FROM index_metadata WHERE key='nav_fingerprint'");
    if (!is_string($navFingerprint) || !preg_match('/^[a-f0-9]{64}$/', $navFingerprint)) return false;

    $etag = '"' . ($providerId !== '' ? $providerId . '-' : '') . $navFingerprint . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, no-cache');
    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '') {
        $matches = array_map('trim', explode(',', $ifNoneMatch));
        if (in_array('*', $matches, true) || in_array($etag, $matches, true)) {
            header_remove('Content-Length');
            http_response_code(304);
            return true;
        }
    }

    $regions = [];
    $regionQuery = $db->prepare('SELECT region AS code, category_count AS categories FROM nav_regions WHERE kind=:kind ORDER BY region');
    $regionQuery->bindValue(':kind', $kind, SQLITE3_TEXT);
    $result = $regionQuery->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $regions[] = $row;

    $categories = [];
    $categoryQuery = $db->prepare('SELECT region, category_id, name, item_count AS movies FROM nav_categories WHERE kind=:kind ORDER BY region, name COLLATE NOCASE, category_id');
    $categoryQuery->bindValue(':kind', $kind, SQLITE3_TEXT);
    $result = $categoryQuery->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $categories[] = $row;

    echo json_encode([
        'ok' => true,
        'view' => 'nav',
        'nav_fingerprint' => $navFingerprint,
        'regions' => $regions,
        'categories' => $categories,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

function openapAptvVodBrowse(): void
{
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    $requestedProviderId=trim((string)($_GET['provider_id']??''));
    if($requestedProviderId!==''&&(!preg_match('/^[a-f0-9]{32}$/',$requestedProviderId)||!openapAptvProviderProfileById($requestedProviderId))){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]);return;}
    $path=openapAptvProviderCatalogPath($requestedProviderId!==''?$requestedProviderId:null); if(!is_readable($path)){http_response_code(503);echo json_encode(['ok'=>false,'message'=>_('The APtv catalog is not available.')]);return;}
    $posterProviderId = $requestedProviderId !== '' ? $requestedProviderId : (string) (openapAptvProviderCollection()['active_id'] ?? '');
    $posterSuffix = preg_match('/^[a-f0-9]{32}$/', $posterProviderId) ? '&provider_id=' . rawurlencode($posterProviderId) : '';
    $view=(string)($_GET['view']??'regions');$region=strtoupper(trim((string)($_GET['region']??'')));$category=trim((string)($_GET['category']??''));
    $offset=max(0,min(1000000,(int)($_GET['offset']??0)));$limit=max(1,min(60,(int)($_GET['limit']??30)));
    try {
        $db=new SQLite3($path,SQLITE3_OPEN_READONLY);$db->busyTimeout(1500);
        if ($view === 'nav') {
            if (openapAptvTryServeCatalogNav($db, 'vod', $posterProviderId)) { $db->close(); return; }
            http_response_code(503); echo json_encode(['ok'=>false,'message'=>_('The navigation index is not ready.')]); $db->close(); return;
        } elseif($view==='regions'){
            $hasNav = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nav_regions'") === 1;
            if ($hasNav) {
                $query=$db->query("SELECT region AS code, category_count AS categories FROM nav_regions WHERE kind='vod' ORDER BY region");
            } else {
                $query=$db->query("SELECT ir.region_code code,COUNT(DISTINCT i.category_id) categories FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='vod' GROUP BY ir.region_code ORDER BY ir.region_code");
            }
            $items=[];while($row=$query->fetchArray(SQLITE3_ASSOC))$items[]=$row;
            openapAptvApplySavedOrder($items,'vod_region','global','code',$requestedProviderId!==''?$requestedProviderId:null);echo json_encode(['ok'=>true,'view'=>'regions','items'=>$items]);
        }elseif($view==='categories'&&preg_match('/^(?:[A-Z0-9]{1,8}|OTHER)$/',$region)){
            $hasNav = (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nav_categories'") === 1;
            $statement=$db->prepare($hasNav
                ? "SELECT category_id,name,item_count AS movies FROM nav_categories WHERE kind='vod' AND region=:region ORDER BY name COLLATE NOCASE, category_id"
                : "SELECT c.category_id,c.name,COUNT(i.item_id) movies FROM categories c JOIN items i ON i.kind=c.kind AND i.category_id=c.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE c.kind='vod' AND ir.region_code=:region GROUP BY c.category_id,c.name ORDER BY c.name COLLATE NOCASE");$statement->bindValue(':region',$region,SQLITE3_TEXT);
            $query=$statement->execute();$items=[];while($row=$query->fetchArray(SQLITE3_ASSOC))$items[]=$row;openapAptvApplySavedOrder($items,'vod_category',$region,'category_id',$requestedProviderId!==''?$requestedProviderId:null);echo json_encode(['ok'=>true,'view'=>'categories','region'=>$region,'items'=>$items]);
        }elseif($view==='movies'&&preg_match('/^[A-Za-z0-9_-]{1,64}$/',$category)){
            $movieRegion=strtoupper(trim((string)($_GET['region']??'')));$movieRegionWhere=$movieRegion!==''?' AND ir.region_code=:region':'';
            $count=$db->prepare("SELECT COUNT(*) FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='vod' AND i.category_id=:category$movieRegionWhere");$count->bindValue(':category',$category,SQLITE3_TEXT);if($movieRegionWhere!=='')$count->bindValue(':region',$movieRegion,SQLITE3_TEXT);$total=(int)$count->execute()->fetchArray(SQLITE3_NUM)[0];
            $statement=$db->prepare("SELECT i.item_id,i.name,i.rating,i.added,i.container_extension,i.icon_url FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='vod' AND i.category_id=:category$movieRegionWhere ORDER BY i.name COLLATE NOCASE LIMIT :limit OFFSET :offset");$statement->bindValue(':category',$category,SQLITE3_TEXT);if($movieRegionWhere!=='')$statement->bindValue(':region',$movieRegion,SQLITE3_TEXT);$statement->bindValue(':limit',$limit,SQLITE3_INTEGER);$statement->bindValue(':offset',$offset,SQLITE3_INTEGER);
            $query=$statement->execute();$items=[];while($row=$query->fetchArray(SQLITE3_ASSOC)){$row['poster_url']=openapAptvPosterStaticUrl('vod',$posterProviderId,(string)$row['item_id'],(string)($row['icon_url']??''));unset($row['icon_url']);$items[]=$row;}
            echo json_encode(['ok'=>true,'view'=>'movies','items'=>$items,'total'=>$total,'offset'=>$offset,'has_more'=>$offset+count($items)<$total]);
        }elseif($view==='search' && $region !== ''){
            if(!preg_match('/^(?:[A-Z0-9]{1,8}|OTHER)$/',$region)){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid movie region.')]);$db->close();return;}
            $search=trim((string)($_GET['q']??''));
            if(mb_strlen($search)<2||mb_strlen($search)>100){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Enter at least two characters.')]);$db->close();return;}
            $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';
            $where="i.kind='vod' AND i.name LIKE :search ESCAPE '\\' AND ir.region_code=:region";
            $count=$db->prepare("SELECT COUNT(*) FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE $where");$count->bindValue(':search',$pattern,SQLITE3_TEXT);$count->bindValue(':region',$region,SQLITE3_TEXT);$total=(int)$count->execute()->fetchArray(SQLITE3_NUM)[0];
            $statement=$db->prepare("SELECT i.item_id,i.name,i.rating,i.added,i.container_extension,i.icon_url FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE $where ORDER BY i.name COLLATE NOCASE LIMIT :limit");$statement->bindValue(':search',$pattern,SQLITE3_TEXT);$statement->bindValue(':region',$region,SQLITE3_TEXT);$statement->bindValue(':limit',$limit,SQLITE3_INTEGER);$query=$statement->execute();$items=[];
            while($row=$query->fetchArray(SQLITE3_ASSOC)){$row['poster_url']=openapAptvPosterStaticUrl('vod',$posterProviderId,(string)$row['item_id'],(string)($row['icon_url']??''));unset($row['icon_url']);$items[]=$row;}
            echo json_encode(['ok'=>true,'view'=>'search','region'=>$region,'items'=>$items,'total'=>$total]);
        }elseif($view==='search'){
            $search=trim((string)($_GET['q']??''));
            if(mb_strlen($search)<2||mb_strlen($search)>100){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Enter at least two characters.')]);$db->close();return;}
            $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';
            $count=$db->prepare("SELECT COUNT(*) FROM items WHERE kind='vod' AND name LIKE :search ESCAPE '\\'");$count->bindValue(':search',$pattern,SQLITE3_TEXT);$total=(int)$count->execute()->fetchArray(SQLITE3_NUM)[0];
            $statement=$db->prepare("SELECT item_id,name,rating,added,container_extension,icon_url FROM items WHERE kind='vod' AND name LIKE :search ESCAPE '\\' ORDER BY name COLLATE NOCASE LIMIT :limit");$statement->bindValue(':search',$pattern,SQLITE3_TEXT);$statement->bindValue(':limit',$limit,SQLITE3_INTEGER);$query=$statement->execute();$items=[];
            while($row=$query->fetchArray(SQLITE3_ASSOC)){$row['poster_url']=openapAptvPosterStaticUrl('vod',$posterProviderId,(string)$row['item_id'],(string)($row['icon_url']??''));unset($row['icon_url']);$items[]=$row;}
            echo json_encode(['ok'=>true,'view'=>'search','items'=>$items,'total'=>$total]);
        }else{http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid movie catalog request.')]);}
        $db->close();
    }catch(Throwable $error){error_log('APtv VOD browse failed: '.$error->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>_('Unable to read the movie catalog.')]);}
}

function openapAptvSeriesBrowse(): void
{
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control:private,no-store');$view=(string)($_GET['view']??'regions');$region=strtoupper(trim((string)($_GET['region']??'')));$category=trim((string)($_GET['category']??''));$offset=max(0,(int)($_GET['offset']??0));$limit=max(1,min(60,(int)($_GET['limit']??30)));$requestedProviderId=trim((string)($_GET['provider_id']??''));if($requestedProviderId!==''&&(!preg_match('/^[a-f0-9]{32}$/',$requestedProviderId)||!openapAptvProviderProfileById($requestedProviderId))){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]);return;}$posterProviderId=$requestedProviderId!==''?$requestedProviderId:(string)(openapAptvProviderCollection()['active_id']??'');$posterSuffix=preg_match('/^[a-f0-9]{32}$/',$posterProviderId)?'&provider_id='.rawurlencode($posterProviderId):'';
    try{$db=new SQLite3(openapAptvProviderCatalogPath($requestedProviderId!==''?$requestedProviderId:null),SQLITE3_OPEN_READONLY);if($view==='nav'){
            if (openapAptvTryServeCatalogNav($db, 'series', $posterProviderId)) { $db->close(); return; }
            http_response_code(503); echo json_encode(['ok'=>false,'message'=>_('The navigation index is not ready.')]); $db->close(); return;
        }elseif($view==='regions'){$hasNav=(int)$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nav_regions'")===1;$q=$hasNav?$db->query("SELECT region AS code,category_count AS categories FROM nav_regions WHERE kind='series' ORDER BY region"):$db->query("SELECT ir.region_code code,COUNT(DISTINCT i.category_id) categories FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='series' GROUP BY ir.region_code ORDER BY ir.region_code");$items=[];while($r=$q->fetchArray(SQLITE3_ASSOC))$items[]=$r;openapAptvApplySavedOrder($items,'series_region','global','code',$requestedProviderId!==''?$requestedProviderId:null);echo json_encode(['ok'=>true,'view'=>'regions','items'=>$items]);}
        elseif($view==='categories'&&preg_match('/^(?:[A-Z0-9]{1,8}|OTHER)$/',$region)){$hasNav=(int)$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nav_categories'")===1;$s=$db->prepare($hasNav?"SELECT category_id,name,item_count AS movies FROM nav_categories WHERE kind='series' AND region=:region ORDER BY name COLLATE NOCASE, category_id":"SELECT c.category_id,c.name,COUNT(i.item_id) movies FROM categories c JOIN items i ON i.kind=c.kind AND i.category_id=c.category_id JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE c.kind='series' AND ir.region_code=:region GROUP BY c.category_id,c.name ORDER BY c.name COLLATE NOCASE");$s->bindValue(':region',$region,SQLITE3_TEXT);$q=$s->execute();$items=[];while($r=$q->fetchArray(SQLITE3_ASSOC))$items[]=$r;openapAptvApplySavedOrder($items,'series_category',$region,'category_id',$requestedProviderId!==''?$requestedProviderId:null);echo json_encode(['ok'=>true,'view'=>'categories','region'=>$region,'items'=>$items]);}
        elseif($view==='movies'&&preg_match('/^[A-Za-z0-9_-]{1,64}$/',$category)){
            $seriesRegion=strtoupper(trim((string)($_GET['region']??'')));
            $seriesRegionWhere=$seriesRegion!==''?' AND ir.region_code=:region':'';
            $c=$db->prepare("SELECT COUNT(*) FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='series' AND i.category_id=:category$seriesRegionWhere");
            $c->bindValue(':category',$category,SQLITE3_TEXT);
            if($seriesRegionWhere!=='')$c->bindValue(':region',$seriesRegion,SQLITE3_TEXT);
            $total=(int)$c->execute()->fetchArray(SQLITE3_NUM)[0];
            $s=$db->prepare("SELECT i.item_id,i.name,i.rating,i.added,i.icon_url FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE i.kind='series' AND i.category_id=:category$seriesRegionWhere ORDER BY i.name COLLATE NOCASE LIMIT :limit OFFSET :offset");
            $s->bindValue(':category',$category,SQLITE3_TEXT);
            if($seriesRegionWhere!=='')$s->bindValue(':region',$seriesRegion,SQLITE3_TEXT);
            $s->bindValue(':limit',$limit,SQLITE3_INTEGER);$s->bindValue(':offset',$offset,SQLITE3_INTEGER);
            $q=$s->execute();$items=[];
            while($r=$q->fetchArray(SQLITE3_ASSOC)){$r['poster_url']=openapAptvPosterStaticUrl('series',$posterProviderId,(string)$r['item_id'],(string)($r['icon_url']??''));unset($r['icon_url']);$items[]=$r;}
            echo json_encode(['ok'=>true,'view'=>'movies','items'=>$items,'total'=>$total,'offset'=>$offset,'has_more'=>$offset+count($items)<$total]);
        }
        elseif($view==='search'){$search=trim((string)($_GET['q']??''));if(mb_strlen($search)<2||mb_strlen($search)>100){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Enter at least two characters.')]);$db->close();return;}if($region!==''&&!preg_match('/^(?:[A-Z0-9]{1,8}|OTHER)$/',$region)){http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid TV series region.')]);$db->close();return;}$pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';$where="i.kind='series' AND i.name LIKE :search ESCAPE '\\'".($region!==''?" AND ir.region_code=:region":'');$c=$db->prepare("SELECT COUNT(*) FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE $where");$c->bindValue(':search',$pattern,SQLITE3_TEXT);if($region!=='')$c->bindValue(':region',$region,SQLITE3_TEXT);$total=(int)$c->execute()->fetchArray(SQLITE3_NUM)[0];$s=$db->prepare("SELECT i.item_id,i.name,i.rating,i.added,i.icon_url FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id WHERE $where ORDER BY i.name COLLATE NOCASE LIMIT :limit");$s->bindValue(':search',$pattern,SQLITE3_TEXT);if($region!=='')$s->bindValue(':region',$region,SQLITE3_TEXT);$s->bindValue(':limit',$limit,SQLITE3_INTEGER);$q=$s->execute();$items=[];while($r=$q->fetchArray(SQLITE3_ASSOC)){$r['poster_url']=openapAptvPosterStaticUrl('series',$posterProviderId,(string)$r['item_id'],(string)($r['icon_url']??''));unset($r['icon_url']);$items[]=$r;}echo json_encode(['ok'=>true,'view'=>'search','region'=>$region,'items'=>$items,'total'=>$total]);}
        else{http_response_code(422);echo json_encode(['ok'=>false,'message'=>_('Invalid TV series catalog request.')]);}$db->close();
    }catch(Throwable $error){error_log('APtv series browse failed: '.$error->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>_('Unable to read the TV series catalog.')]);}
}

function openapAptvServeCachedImage(string $cache, string $prefix, string $mime, bool $emitCacheControl = true): void
{
    header('Content-Type: '.$mime);
    if ($emitCacheControl) header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400, immutable');
    header('X-Content-Type-Options: nosniff');
    $size=@filesize($cache);
    if($size===false){http_response_code(404);return;}
    header('Content-Length: '.(string)$size);
    readfile($cache);
}

function openapAptvServePosterCacheIfReady(string $cache, string $prefix): bool
{
    if (!is_file($cache) || (int) @filesize($cache) < 32) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($cache);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return false;
    openapAptvServeCachedImage($cache, $prefix, $mime);
    return true;
}

function openapAptvClosePosterSession(): void
{
    openap_release_session();
}

function openapAptvServeProviderPoster(string $kind): void
{
    openapAptvClosePosterSession();
    $id = trim((string) ($_GET['id'] ?? ''));
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $id)) { http_response_code(422); return; }
    $context = openapAptvProviderContext($providerId);
    if (!is_array($context)) { http_response_code(409); return; }
    $directory = $kind === 'series' ? '/var/cache/openap-iptv/series-posters' : '/var/cache/openap-iptv/posters';
    try {
        $db = new SQLite3((string) $context['catalog'], SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT icon_url FROM items WHERE kind=:kind AND item_id=:id LIMIT 1");
        $statement->bindValue(':kind', $kind, SQLITE3_TEXT);
        $statement->bindValue(':id', $id, SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_NUM);
        $db->close();
        $url = is_array($row) ? (string) ($row[0] ?? '') : '';
    } catch (Throwable $error) {
        http_response_code(503); return;
    }
    $cache = openapAptvPosterCachePath($directory, (string) $context['id'], $id, $url);
    $legacyCache = $directory . '/' . hash('sha256', $id . "\0" . $url) . '.img';
    if (!is_file($cache) && is_file($legacyCache) && (int) @filesize($legacyCache) >= 32) {
        $legacyMime = (new finfo(FILEINFO_MIME_TYPE))->file($legacyCache);
        if (in_array($legacyMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) @link($legacyCache, $cache);
    }
    if (openapAptvServePosterCacheIfReady($cache, $kind === 'series' ? '/__openap_aptv_cache/series-posters/' : '/__openap_aptv_cache/posters/')) return;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) { http_response_code(404); return; }
    $records = @dns_get_record((string) $parts['host'], DNS_A | DNS_AAAA);
    if (!is_array($records) || !$records) { http_response_code(403); return; }
    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { http_response_code(403); return; }
    }
    $body = '';
    $curl = openapAptvCurl($url);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'APtv-Poster/0.1',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 6291456) return 0;
            $body .= $chunk; return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $mime = $body !== '' ? (new finfo(FILEINFO_MIME_TYPE))->buffer($body) : '';
    if (!$ok || $status !== 200 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) { http_response_code(502); return; }
    $temporary = $cache . '.new.' . getmypid();
    if (file_put_contents($temporary, $body, LOCK_EX) === false) { http_response_code(503); return; }
    chmod($temporary, 0640); @chgrp($temporary, 'www-data'); rename($temporary, $cache);
    openapAptvServeCachedImage($cache, $kind === 'series' ? '/__openap_aptv_cache/series-posters/' : '/__openap_aptv_cache/posters/', $mime);
}

function openapAptvServeSeriesPoster(): void
{
    openapAptvClosePosterSession();
    if (trim((string) ($_GET['provider_id'] ?? '')) !== '') { openapAptvServeProviderPoster('series'); return; }
    $original=$_GET['id']??'';$id=trim((string)$original);if(!preg_match('/^[0-9]{1,20}$/',$id)){http_response_code(422);return;}try{$db=new SQLite3('/var/lib/openap-iptv/catalog.db',SQLITE3_OPEN_READONLY);$s=$db->prepare("SELECT icon_url FROM items WHERE kind='series' AND item_id=:id");$s->bindValue(':id',$id,SQLITE3_TEXT);$r=$s->execute()->fetchArray(SQLITE3_NUM);$url=is_array($r)?(string)($r[0]??''):'';$db->close();}catch(Throwable $e){http_response_code(503);return;}$directory='/var/cache/openap-iptv/series-posters';$cache=$directory.'/'.hash('sha256',$id."\0".$url).'.img';if(openapAptvServePosterCacheIfReady($cache,'/__openap_aptv_cache/series-posters/'))return;$parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)){http_response_code(404);return;}$host=(string)($parts['host']??'');$records=@dns_get_record($host,DNS_A|DNS_AAAA);if(!$records){http_response_code(403);return;}foreach($records as $record){$ip=(string)($record['ip']??$record['ipv6']??'');if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){http_response_code(403);return;}}if(!is_file($cache)){$body='';$curl=openapAptvCurl($url);curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>6291456)return 0;$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$mime=$body!==''?(new finfo(FILEINFO_MIME_TYPE))->buffer($body):'';if(!$ok||$status!==200||!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(502);return;}$tmp=$cache.'.new.'.getmypid();file_put_contents($tmp,$body,LOCK_EX);chmod($tmp,0640);@chgrp($tmp,'www-data');rename($tmp,$cache);}openapAptvServeCachedImage($cache,'/__openap_aptv_cache/series-posters/',(new finfo(FILEINFO_MIME_TYPE))->file($cache));
}

function openapAptvServeEpisodeImage(): void
{
    openapAptvClosePosterSession();
    if (trim((string) ($_GET['provider_id'] ?? '')) !== '') { openapAptvServeProviderEpisodeImage(); return; }
    $id=trim((string)($_GET['id']??''));if(!preg_match('/^[0-9]{1,20}$/',$id)){http_response_code(422);return;}
    $profile=openapAptvProviderProfile();if(!$profile){http_response_code(409);return;}
    $registry='/var/cache/openap-iptv/series-episodes/'.hash('sha256',(string)$profile['url']."\0".(string)$profile['username']."\0".$id).'.json';
    $record=is_file($registry)?json_decode((string)file_get_contents($registry),true):null;$url=is_array($record)?(string)($record['preview_source']??''):'';
    $parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host'])){http_response_code(404);return;}
    $records=@dns_get_record((string)$parts['host'],DNS_A|DNS_AAAA);if(!is_array($records)||!$records){http_response_code(403);return;}
    foreach($records as $dnsRecord){$ip=(string)($dnsRecord['ip']??$dnsRecord['ipv6']??'');if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){http_response_code(403);return;}}
    $directory='/var/cache/openap-iptv/episode-images';if(!is_dir($directory))@mkdir($directory,02770,true);@chgrp($directory,'www-data');@chmod($directory,02770);
    $cache=$directory.'/'.hash('sha256',$id."\0".$url).'.img';
    if(!is_file($cache)||filesize($cache)<32){$body='';$tooLarge=false;$curl=openapAptvCurl($url);curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'APtv-Episode/0.1',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>6291456){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$mime=is_string($body)&&$body!==''?(new finfo(FILEINFO_MIME_TYPE))->buffer($body):'';if(!$ok||$tooLarge||$status<200||$status>=300||!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(502);return;}$temporary=$cache.'.new.'.getmypid();if(file_put_contents($temporary,$body,LOCK_EX)===false){http_response_code(503);return;}chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$cache);}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($cache);if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(404);return;}openapAptvServeCachedImage($cache,'/__openap_aptv_cache/episode-images/',$mime);
}

function openapAptvServeProviderEpisodeImage(): void
{
    openapAptvClosePosterSession();
    $id = trim((string) ($_GET['id'] ?? ''));
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $id)) { http_response_code(422); return; }
    $context = openapAptvProviderContext($providerId);
    if (!is_array($context)) { http_response_code(409); return; }
    $profile = (array) $context['profile'];
    $registry = '/var/cache/openap-iptv/series-episodes/' . hash('sha256', (string) ($profile['url'] ?? '') . "\0" . (string) ($profile['username'] ?? '') . "\0" . $id) . '.json';
    $record = is_file($registry) ? json_decode((string) @file_get_contents($registry), true) : null;
    $url = is_array($record) ? (string) ($record['preview_source'] ?? '') : '';
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) { http_response_code(404); return; }
    $records = @dns_get_record((string) $parts['host'], DNS_A | DNS_AAAA);
    if (!is_array($records) || !$records) { http_response_code(403); return; }
    foreach ($records as $record) {
        $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { http_response_code(403); return; }
    }
    $directory = '/var/cache/openap-iptv/episode-images';
    if (!is_dir($directory)) @mkdir($directory, 02770, true);
    @chgrp($directory, 'www-data'); @chmod($directory, 02770);
    $cache = openapAptvPosterCachePath($directory, (string) $context['id'], $id, $url);
    $legacyCache = $directory . '/' . hash('sha256', $id . "\0" . $url) . '.img';
    if (!is_file($cache) && is_file($legacyCache) && (int) @filesize($legacyCache) >= 32) @link($legacyCache, $cache);
    if (is_file($cache) && (int) @filesize($cache) >= 32) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($cache);
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            openapAptvServeCachedImage($cache, '/__openap_aptv_cache/episode-images/', $mime); return;
        }
    }
    $body = ''; $tooLarge = false; $curl = openapAptvCurl($url);
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'APtv-Episode/0.1',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 6291456) { $tooLarge = true; return 0; }
            $body .= $chunk; return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $mime = $body !== '' ? (new finfo(FILEINFO_MIME_TYPE))->buffer($body) : '';
    if (!$ok || $tooLarge || $status < 200 || $status >= 300 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) { http_response_code(502); return; }
    $temporary = $cache . '.new.' . getmypid();
    if (file_put_contents($temporary, $body, LOCK_EX) === false) { http_response_code(503); return; }
    chmod($temporary, 0640); @chgrp($temporary, 'www-data'); rename($temporary, $cache);
    openapAptvServeCachedImage($cache, '/__openap_aptv_cache/episode-images/', $mime);
}

function openapAptvSeries(string $id, ?string $providerId = null): ?array
{
    if(!preg_match('/^[0-9]{1,20}$/',$id))return null;
    $catalog = $providerId !== null && $providerId !== '' ? openapAptvProviderCatalogPath($providerId) : '/var/lib/openap-iptv/catalog.db';
    try{$db=new SQLite3($catalog,SQLITE3_OPEN_READONLY);$s=$db->prepare("SELECT name,rating,icon_url FROM items WHERE kind='series' AND item_id=:id LIMIT 1");$s->bindValue(':id',$id,SQLITE3_TEXT);$item=$s->execute()->fetchArray(SQLITE3_ASSOC);$db->close();return is_array($item)?$item:null;}catch(Throwable $error){return null;}
}
function openapAptvTagSeriesEpisodeCache(array $profile,string $seriesId,array $episodes): void
{
    $registry='/var/cache/openap-iptv/series-episodes';
    foreach($episodes as $seasonEpisodes){
        if(!is_array($seasonEpisodes))continue;
        foreach($seasonEpisodes as $episode){
            $episodeId=(string)($episode['item_id']??'');if(!preg_match('/^[0-9]{1,20}$/',$episodeId))continue;
            $path=$registry.'/'.hash('sha256',(string)$profile['url']."\0".(string)$profile['username']."\0".$episodeId).'.json';
            $playable=is_file($path)?json_decode((string)file_get_contents($path),true):null;
            if(!is_array($playable)||($playable['source_kind']??'')!=='series'||(string)($playable['series_id']??'')===$seriesId)continue;
            $playable['series_id']=$seriesId;$temporary=$path.'.new.'.getmypid();
            if(file_put_contents($temporary,json_encode($playable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$path);}else @unlink($temporary);
        }
    }
}

function openapAptvSeriesPayloadUrls(array $payload, string $seriesId, string $posterSuffix): array
{
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    $sourceUrl = (string) ($payload['item']['icon_url'] ?? '');
    if ($sourceUrl === '' && $providerId !== '') {
        $catalogItem = openapAptvSeries($seriesId, $providerId);
        $sourceUrl = is_array($catalogItem) ? (string) ($catalogItem['icon_url'] ?? '') : '';
    }
    if (isset($payload['item']) && is_array($payload['item'])) {
        $payload['item']['poster_url'] = openapAptvPosterStaticUrl('series', $providerId, $seriesId, $sourceUrl);
        unset($payload['item']['icon_url']);
    }
    foreach (($payload['episodes'] ?? []) as $season => $episodes) {
        if (!is_array($episodes)) continue;
        foreach ($episodes as $index => $episode) {
            if (!is_array($episode)) continue;
            $episodeId = (string) ($episode['item_id'] ?? '');
            if (!preg_match('/^[0-9]{1,20}$/', $episodeId)) continue;
            if ((string) ($episode['preview_url'] ?? '') !== '') {
                $payload['episodes'][$season][$index]['preview_url'] = '/aptv/api/series/episode-image?id=' . rawurlencode($episodeId) . $posterSuffix;
            } else {
                // Some providers expose no usable episode artwork. Return the
                // series poster immediately so the episode grid never waits
                // for an image URL that does not exist.
                $payload['episodes'][$season][$index]['preview_url'] = (string) ($payload['item']['poster_url'] ?? '');
                $payload['episodes'][$season][$index]['preview_fallback'] = true;
            }
        }
    }
    return $payload;
}

function openapAptvSeriesPayload(string $id): ?array
{
    $requestedProviderId = trim((string) ($_GET['provider_id'] ?? ''));
    $context = openapAptvProviderContext($requestedProviderId);
    $item = openapAptvSeries($id, is_array($context) ? (string) $context['id'] : null);
    $profile = is_array($context) ? (array) $context['profile'] : null;
    if (!$item || !$profile) return null;
    $posterSuffix = '&provider_id=' . rawurlencode((string) $context['id']);
    $directory='/var/cache/openap-iptv/series-details';if(!is_dir($directory))@mkdir($directory,02770,true);@chgrp($directory,'www-data');@chmod($directory,02770);
    $cache=$directory.'/'.hash('sha256',(string)$profile['url']."\0".(string)$profile['username']."\0".$id).'.json';
    $payload=is_file($cache)&&filemtime($cache)>time()-604800?json_decode((string)file_get_contents($cache),true):null;
    if(is_array($payload)&&($payload['schema_version']??0)>=2&&is_array($payload['episodes']??null)){$payload=openapAptvSeriesPayloadUrls($payload,$id,$posterSuffix);openapAptvTagSeriesEpisodeCache($profile,$id,$payload['episodes']);return $payload;}
    $url=rtrim((string)$profile['url'],'/').'/player_api.php?'.http_build_query(['username'=>$profile['username'],'password'=>$profile['password'],'action'=>'get_series_info','series_id'=>$id]);$curl=openapAptvCurl($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);$body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);sodium_memzero($url);$decoded=$status===200&&is_string($body)?json_decode($body,true):null;if(!is_array($decoded))return null;
    $info=is_array($decoded['info']??null)?$decoded['info']:[];$episodes=[];$registry='/var/cache/openap-iptv/series-episodes';if(!is_dir($registry))@mkdir($registry,02770,true);@chgrp($registry,'www-data');@chmod($registry,02770);
    foreach(($decoded['episodes']??[])as$season=>$seasonEpisodes){if(!is_array($seasonEpisodes))continue;$clean=[];foreach($seasonEpisodes as$episode){if(!is_array($episode))continue;$episodeId=(string)($episode['id']??'');if(!preg_match('/^[0-9]{1,20}$/',$episodeId))continue;$episodeInfo=is_array($episode['info']??null)?$episode['info']:[];$previewSource=trim((string)($episodeInfo['movie_image']??''));$previewParts=$previewSource!==''?parse_url($previewSource):false;$hasPreview=is_array($previewParts)&&in_array(strtolower((string)($previewParts['scheme']??'')),['http','https'],true)&&!empty($previewParts['host']);$entry=['item_id'=>$episodeId,'series_id'=>$id,'season'=>(int)($episode['season']??$season),'episode'=>(int)($episode['episode_num']??0),'title'=>(string)($episode['title']??('Episode '.($episode['episode_num']??''))),'description'=>(string)($episodeInfo['plot']??''),'duration'=>(string)($episodeInfo['duration']??''),'rating'=>(string)($episodeInfo['rating']??''),'air_date'=>(string)($episodeInfo['air_date']??''),'container_extension'=>preg_replace('/[^a-z0-9]/','',strtolower((string)($episode['container_extension']??'mkv'))),'preview_url'=>$hasPreview?'/aptv/api/series/episode-image?id='.rawurlencode($episodeId):''];$clean[]=$entry;$playable=['name'=>$entry['title'],'container_extension'=>$entry['container_extension'],'source_kind'=>'series','series_id'=>$id,'preview_source'=>$hasPreview?$previewSource:''];$episodeCache=$registry.'/'.hash('sha256',(string)$profile['url']."\0".(string)$profile['username']."\0".$episodeId).'.json';$temporary=$episodeCache.'.new.'.getmypid();if(file_put_contents($temporary,json_encode($playable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$episodeCache);}}if($clean)$episodes[(string)(int)$season]=$clean;}
    uksort($episodes,static fn($a,$b)=>(int)$a<=>(int)$b);$payload=['schema_version'=>2,'item'=>['item_id'=>$id,'title'=>(string)($info['name']??$item['name']),'description'=>(string)($info['plot']??''),'rating'=>(string)($info['rating']??$item['rating']),'year'=>(string)($info['releaseDate']??$info['release_date']??''),'genre'=>(string)($info['genre']??''),'duration'=>(string)($info['episode_run_time']??''),'seasons'=>count($episodes),'icon_url'=>(string)($item['icon_url']??''),'poster_url'=>'/aptv/api/series/poster?id='.$id.$posterSuffix],'episodes'=>$episodes];$payload=openapAptvSeriesPayloadUrls($payload,$id,$posterSuffix);$temporary=$cache.'.new.'.getmypid();if(file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$cache);}return $payload;
}
function openapAptvSeriesDetails(): void
{
    openap_release_session();
    header('Content-Type:application/json;charset=utf-8');header('Cache-Control:private,no-store');$id=trim((string)($_GET['id']??''));$payload=openapAptvSeriesPayload($id);if(!$payload){http_response_code(404);echo json_encode(['ok'=>false,'message'=>_('Series unavailable.')]);return;}echo json_encode(['ok'=>true,'item'=>$payload['item']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function openapAptvSeriesEpisodes(): void
{
    openap_release_session();
    header('Content-Type:application/json;charset=utf-8');header('Cache-Control:private,no-store');$id=trim((string)($_GET['id']??''));$payload=openapAptvSeriesPayload($id);if(!$payload){http_response_code(404);echo json_encode(['ok'=>false,'message'=>_('Episodes unavailable.')]);return;}echo json_encode(['ok'=>true,'series'=>$payload['item'],'seasons'=>$payload['episodes']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function openapAptvPosterServedRoot(): string
{
    $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html');
    if ($documentRoot === '' || $documentRoot[0] !== '/') $documentRoot = '/var/www/html';
    return rtrim($documentRoot, '/') . '/posters';
}

function openapAptvPosterServedRelative(string $kind, string $providerId, string $itemId, string $sourceUrl): ?string
{
    if (!in_array($kind, ['vod', 'series'], true)
        || !preg_match('/^[a-f0-9]{32}$/', $providerId)
        || !preg_match('/^[0-9]{1,20}$/', $itemId)
        || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) return null;
    return 'v1/' . $kind . '/' . $providerId . '/' . $itemId . '-' . hash('sha256', $sourceUrl) . '.jpg';
}

function openapAptvPosterStaticUrl(string $kind, string $providerId, string $itemId, string $sourceUrl): string
{
    $relative = openapAptvPosterServedRelative($kind, $providerId, $itemId, $sourceUrl);
    return $relative === null ? '' : '/posters/' . $relative;
}

function openapAptvPosterFailurePath(string $kind, string $providerId, string $itemId, string $sourceUrl): string
{
    $directory = '/var/cache/openap-iptv/poster-failures';
    if (!is_dir($directory)) {
        @mkdir($directory, 02750, true);
        @chgrp($directory, 'www-data');
        @chmod($directory, 02750);
    }
    return $directory . '/' . hash('sha256', $kind . "\0" . $providerId . "\0" . $itemId . "\0" . $sourceUrl) . '.failed';
}

function openapAptvPosterFailureActive(string $kind, string $providerId, string $itemId, string $sourceUrl): bool
{
    $path = openapAptvPosterFailurePath($kind, $providerId, $itemId, $sourceUrl);
    $mtime = is_file($path) ? @filemtime($path) : false;
    if ($mtime === false) return false;
    if (time() - (int) $mtime >= 21600) {
        @unlink($path);
        return false;
    }
    return true;
}

function openapAptvPosterMarkFailure(string $kind, string $providerId, string $itemId, string $sourceUrl): void
{
    $path = openapAptvPosterFailurePath($kind, $providerId, $itemId, $sourceUrl);
    $temporary = $path . '.new.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($temporary, (string) time(), LOCK_EX) !== false) {
        @chmod($temporary, 0640);
        @chgrp($temporary, 'www-data');
        if (!@rename($temporary, $path)) @unlink($temporary);
    }
}

function openapAptvPosterNoCache(int $status): void
{
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    http_response_code($status);
}

function openapAptvPosterEnsureServed(string $original, string $served): bool
{
    if (is_file($served) && (int) @filesize($served) >= 32) return true;
    if (!is_file($original) || (int) @filesize($original) < 32) return false;
    $parent = dirname($served);
    if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) return false;
    @chgrp($parent, 'www-data'); @chmod($parent, 0750);
    $temporary = $served . '.new.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($original);
    if ($mime === 'image/jpeg') {
        if (!@link($original, $temporary)) return false;
    } else {
        $process = @proc_open([
            '/usr/bin/ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $original,
            '-frames:v', '1', '-c:v', 'mjpeg', '-q:v', '3', $temporary,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($process)) { @unlink($temporary); return false; }
        $exitCode = @proc_close($process);
        if ($exitCode !== 0 || !is_file($temporary)) { @unlink($temporary); return false; }
    }
    $temporaryMime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $temporaryImage = @getimagesize($temporary);
    if ($temporaryMime !== 'image/jpeg' || !is_array($temporaryImage)
        || (int) ($temporaryImage[0] ?? 0) < 1 || (int) ($temporaryImage[1] ?? 0) < 1
        || (int) @filesize($temporary) < 32) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, 0640); @chgrp($temporary, 'www-data');
    if (!@rename($temporary, $served)) { @unlink($temporary); return false; }
    return true;
}

function openapAptvServeStaticPosterFallback(string $relative): void
{
    if (!preg_match('~^v1/(vod|series)/([a-f0-9]{32})/([0-9]{1,20})-([a-f0-9]{64})\.jpg$~', $relative, $match)) { openapAptvPosterNoCache(403); return; }
    [, $kind, $providerId, $itemId, $sourceDigest] = $match;
    openap_release_session();
    $context = openapAptvProviderContext($providerId);
    if (!is_array($context)) { openapAptvPosterNoCache(409); return; }
    try {
        $db = new SQLite3((string) $context['catalog'], SQLITE3_OPEN_READONLY);
        $statement = $db->prepare('SELECT icon_url FROM items WHERE kind=:kind AND item_id=:id LIMIT 1');
        $statement->bindValue(':kind', $kind, SQLITE3_TEXT); $statement->bindValue(':id', $itemId, SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_NUM); $db->close();
    } catch (Throwable $error) { openapAptvPosterNoCache(503); return; }
    $sourceUrl = is_array($row) ? trim((string) ($row[0] ?? '')) : '';
    if ($sourceUrl === '' || !hash_equals($sourceDigest, hash('sha256', $sourceUrl))) { openapAptvPosterNoCache(404); return; }
    if (openapAptvPosterFailureActive($kind, $providerId, $itemId, $sourceUrl)) { openapAptvPosterNoCache(404); return; }
    $generationFailure = static function (int $status = 502) use ($kind, $providerId, $itemId, $sourceUrl): void {
        openapAptvPosterMarkFailure($kind, $providerId, $itemId, $sourceUrl);
        openapAptvPosterNoCache($status);
    };
    $served = openapAptvPosterServedRoot() . '/' . $relative;
    $original = openapAptvPosterCachePath('/var/cache/openap-iptv/' . ($kind === 'series' ? 'series-posters' : 'posters'), $providerId, $itemId, $sourceUrl);
    if (!is_file($original)) {
        $parts = parse_url($sourceUrl);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) { $generationFailure(404); return; }
        $records = @dns_get_record((string) $parts['host'], DNS_A | DNS_AAAA);
        if (!is_array($records) || !$records) { $generationFailure(403); return; }
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE)) { $generationFailure(403); return; }
        }
        $body = ''; $curl = openapAptvCurl($sourceUrl);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 6291456) return 0; $body .= $chunk; return strlen($chunk); }]);
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $mime = $body !== '' ? (new finfo(FILEINFO_MIME_TYPE))->buffer($body) : '';
        if (!$ok || $status !== 200 || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) { $generationFailure(502); return; }
        $originalDir = dirname($original); if (!is_dir($originalDir) && !@mkdir($originalDir, 02750, true) && !is_dir($originalDir)) { $generationFailure(503); return; }
        $temporary = $original . '.new.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $body, LOCK_EX) === false) { $generationFailure(503); return; }
        @chmod($temporary, 0640); @chgrp($temporary, 'www-data'); if (!@rename($temporary, $original)) { @unlink($temporary); $generationFailure(503); return; }
    }
    if (!openapAptvPosterEnsureServed($original, $served)) { $generationFailure(502); return; }
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
    header('X-Poster-Source: php');
    http_response_code(302);
    header('Location: /posters/' . $relative, true, 302);
}

function openapAptvServeVodPoster(): void
{
    openapAptvClosePosterSession();
    if (trim((string) ($_GET['provider_id'] ?? '')) !== '') { openapAptvServeProviderPoster('vod'); return; }
    $id=trim((string)($_GET['id']??''));if(!preg_match('/^[0-9]{1,20}$/',$id)){http_response_code(422);return;}
    try{$db=new SQLite3('/var/lib/openap-iptv/catalog.db',SQLITE3_OPEN_READONLY);$statement=$db->prepare("SELECT icon_url FROM items WHERE kind='vod' AND item_id=:id LIMIT 1");$statement->bindValue(':id',$id,SQLITE3_TEXT);$row=$statement->execute()->fetchArray(SQLITE3_NUM);$url=is_array($row)?(string)($row[0]??''):'';$db->close();}catch(Throwable $error){http_response_code(503);return;}
    $cacheDirectory='/var/cache/openap-iptv/posters';$cache=$cacheDirectory.'/'.hash('sha256',$id."\0".$url).'.img';if(openapAptvServePosterCacheIfReady($cache,'/__openap_aptv_cache/posters/'))return;
    $parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host'])){http_response_code(404);return;}
    $records=@dns_get_record((string)$parts['host'],DNS_A|DNS_AAAA);if(!is_array($records)||!$records){http_response_code(403);return;}
    foreach($records as $record){$ip=(string)($record['ip']??$record['ipv6']??'');if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){http_response_code(403);return;}}
    if(!is_file($cache)||filesize($cache)<32){$body='';$tooLarge=false;$curl=openapAptvCurl($url);curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'APtv-Poster/0.1',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>6291456){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        $mime=is_string($body)&&$body!==''?(new finfo(FILEINFO_MIME_TYPE))->buffer($body):'';if(!$ok||$tooLarge||$status<200||$status>=300||!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(502);return;}$temporary=$cache.'.new.'.getmypid();if(file_put_contents($temporary,$body,LOCK_EX)===false){http_response_code(503);return;}chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$cache);
        if(mt_rand(1,50)===1){$files=glob($cacheDirectory.'/*.img')?:[];$total=0;$dated=[];foreach($files as $file){$size=(int)@filesize($file);$total+=$size;$dated[]=[(int)@filemtime($file),$size,$file];}if($total>1073741824){usort($dated,static fn($a,$b)=>$a[0]<=>$b[0]);foreach($dated as $entry){if($total<=966367641)break;if(@unlink($entry[2]))$total-=$entry[1];}}}
    }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($cache);if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)){http_response_code(404);return;}openapAptvServeCachedImage($cache,'/__openap_aptv_cache/posters/',$mime);
}

function openapAptvVodDetails(): void
{
    openap_release_session();
    if (trim((string) ($_GET['provider_id'] ?? '')) !== '') { openapAptvVodDetailsForProvider(); return; }
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control:private,no-store');
    $id=trim((string)($_GET['id']??''));if(!preg_match('/^[0-9]{1,20}$/',$id)){http_response_code(422);echo json_encode(['ok'=>false]);return;}
    try{$db=new SQLite3('/var/lib/openap-iptv/catalog.db',SQLITE3_OPEN_READONLY);$statement=$db->prepare("SELECT name,rating,added,icon_url FROM items WHERE kind='vod' AND item_id=:id LIMIT 1");$statement->bindValue(':id',$id,SQLITE3_TEXT);$item=$statement->execute()->fetchArray(SQLITE3_ASSOC);$db->close();}catch(Throwable $error){$item=false;}
    if(!is_array($item)){http_response_code(404);echo json_encode(['ok'=>false,'message'=>_('Movie not found.')]);return;}
    $profile=openapAptvProviderProfile();if(!$profile){http_response_code(409);echo json_encode(['ok'=>false,'message'=>_('Provider unavailable.')]);return;}
    $cacheDirectory='/var/cache/openap-iptv/vod-details';$cache=$cacheDirectory.'/'.hash('sha256',(string)($profile['url']??'')."\0".(string)($profile['username']??'')."\0".$id).'.json';$payload=null;
    if(is_file($cache)&&filemtime($cache)>time()-604800)$payload=json_decode((string)file_get_contents($cache),true);
    if(!is_array($payload)){
        $query=http_build_query(['username'=>(string)$profile['username'],'password'=>(string)$profile['password'],'action'=>'get_vod_info','vod_id'=>$id]);$url=rtrim((string)$profile['url'],'/').'/player_api.php?'.$query;$body='';$tooLarge=false;$curl=openapAptvCurl($url);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'APtv-VOD/0.1',CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>2097152){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);sodium_memzero($query);sodium_memzero($url);
        $decoded=$ok&&!$tooLarge&&$status===200?json_decode($body,true):null;if(is_string($body))sodium_memzero($body);if(is_array($decoded)){$info=is_array($decoded['info']??null)?$decoded['info']:[];$movie=is_array($decoded['movie_data']??null)?$decoded['movie_data']:[];$payload=['title'=>(string)($info['name']??$movie['name']??$item['name']),'description'=>(string)($info['plot']??$info['description']??''),'rating'=>(string)($info['rating']??$item['rating']??''),'year'=>(string)($info['releasedate']??$info['release_date']??$info['year']??''),'genre'=>(string)($info['genre']??''),'duration'=>(string)($info['duration']??$info['duration_secs']??'')];$temporary=$cache.'.new.'.getmypid();if(file_put_contents($temporary,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false){chmod($temporary,0640);@chgrp($temporary,'www-data');rename($temporary,$cache);}}}
    if(!is_array($payload))$payload=['title'=>(string)$item['name'],'description'=>'','rating'=>(string)$item['rating'],'year'=>'','genre'=>'','duration'=>''];
    $payload['poster_url']=openapAptvPosterStaticUrl('vod',(string)($profile['id']??''),$id,(string)($item['icon_url']??''));$payload['item_id']=$id;echo json_encode(['ok'=>true,'item'=>$payload],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function openapAptvVodDetailsForProvider(): void
{
    openap_release_session();
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    $id = trim((string) ($_GET['id'] ?? ''));
    $providerId = trim((string) ($_GET['provider_id'] ?? ''));
    if (!preg_match('/^[0-9]{1,20}$/', $id)) { http_response_code(422); echo json_encode(['ok' => false]); return; }
    $context = openapAptvProviderContext($providerId);
    if (!is_array($context)) { http_response_code(409); echo json_encode(['ok' => false, 'message' => _('Provider unavailable.')]); return; }
    try {
        $db = new SQLite3((string) $context['catalog'], SQLITE3_OPEN_READONLY);
        $statement = $db->prepare("SELECT name,rating,added,icon_url FROM items WHERE kind='vod' AND item_id=:id LIMIT 1");
        $statement->bindValue(':id', $id, SQLITE3_TEXT);
        $item = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
    } catch (Throwable $error) { $item = false; }
    if (!is_array($item)) { http_response_code(404); echo json_encode(['ok' => false, 'message' => _('Movie not found.')]); return; }
    $profile = (array) $context['profile'];
    $cacheDirectory = '/var/cache/openap-iptv/vod-details';
    $cache = $cacheDirectory . '/' . hash('sha256', (string) ($profile['url'] ?? '') . "\0" . (string) ($profile['username'] ?? '') . "\0" . $id) . '.json';
    $payload = is_file($cache) && filemtime($cache) > time() - 604800 ? json_decode((string) @file_get_contents($cache), true) : null;
    if (!is_array($payload)) {
        $query = http_build_query(['username' => (string) ($profile['username'] ?? ''), 'password' => (string) ($profile['password'] ?? ''), 'action' => 'get_vod_info', 'vod_id' => $id]);
        $url = rtrim((string) ($profile['url'] ?? ''), '/') . '/player_api.php?' . $query;
        $body = ''; $tooLarge = false; $curl = openapAptvCurl($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'APtv-VOD/0.1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        $decoded = $ok && !$tooLarge && $status === 200 ? json_decode($body, true) : null;
        if (is_array($decoded)) {
            $info = is_array($decoded['info'] ?? null) ? $decoded['info'] : [];
            $movie = is_array($decoded['movie_data'] ?? null) ? $decoded['movie_data'] : [];
            $payload = ['title' => (string) ($info['name'] ?? $movie['name'] ?? $item['name']), 'description' => (string) ($info['plot'] ?? $info['description'] ?? ''), 'rating' => (string) ($info['rating'] ?? $item['rating'] ?? ''), 'year' => (string) ($info['releasedate'] ?? $info['release_date'] ?? $info['year'] ?? ''), 'genre' => (string) ($info['genre'] ?? ''), 'duration' => (string) ($info['duration'] ?? $info['duration_secs'] ?? '')];
            $temporary = $cache . '.new.' . getmypid();
            if (file_put_contents($temporary, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) { chmod($temporary, 0640); @chgrp($temporary, 'www-data'); rename($temporary, $cache); }
        }
    }
    if (!is_array($payload)) $payload = ['title' => (string) $item['name'], 'description' => '', 'rating' => (string) $item['rating'], 'year' => '', 'genre' => '', 'duration' => ''];
    $payload['poster_url'] = openapAptvPosterStaticUrl('vod', $providerId, $id, (string) ($item['icon_url'] ?? ''));
    $payload['item_id'] = $id;
    echo json_encode(['ok' => true, 'item' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function openapIptvIndexProvider(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => _('Method not allowed.')]);
        return;
    }

    $providerName = trim((string) ($_POST['provider_name'] ?? ''));
    $providerId = trim((string) ($_POST['provider_id'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $apiVersion = (string) ($_POST['api_version'] ?? 'v2');
    if ($providerId !== '') {
        if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) { http_response_code(422); echo json_encode(['ok'=>false,'message'=>_('Invalid provider profile.')]); return; }
        $matchedProfile = null;
        foreach ((openapAptvProviderCollection()['profiles'] ?? []) as $savedProfile) {
            if (is_array($savedProfile) && hash_equals((string)($savedProfile['id']??''), $providerId)) {
                $matchedProfile = $savedProfile;
                if ($password === '') $password = (string)($savedProfile['password']??'');
                break;
            }
        }
    } else {
        $providerId = bin2hex(random_bytes(16));
    }
    $parts = parse_url($url);

    if (!is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || $providerName === ''
        || mb_strlen($providerName) > 80
        || $username === ''
        || $password === ''
        || strlen($url) > 2048
        || strlen($username) > 256
        || strlen($password) > 512
        || !in_array($apiVersion, ['v1', 'v2'], true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => _('Complete all provider fields with valid values.')]);
        return;
    }

    // All session-dependent validation is complete.  The indexer may fetch
    // and rebuild the catalog for many seconds, so it must not hold the
    // browser session lock while its output is consumed below.
    openap_release_session();

    // Keep the indexer stderr long enough to diagnose failures that happen
    // after the playlist has already been fetched.  Do not stream it to the
    // browser: helper diagnostics can contain provider-specific URLs.
    $indexerErrorLog = tempnam('/tmp', 'aptv-indexer-');
    if ($indexerErrorLog === false) $indexerErrorLog = '/dev/null';
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', $indexerErrorLog, 'a'],
    ];
    $process = proc_open(['sudo', '-n', '/usr/local/sbin/openap-iptv-index', '--stdin'], $descriptor, $pipes);
    if (!is_resource($process)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => _('Unable to start the APtv indexer.')]);
        return;
    }

    $streamProgress = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/x-ndjson');
    if ($streamProgress) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('X-Accel-Buffering: no');
        @set_time_limit(0);
        ignore_user_abort(true);
        while (ob_get_level() > 0) ob_end_flush();
    }
    fwrite($pipes[0], json_encode([
        'progress' => $streamProgress,
        'url' => $url,
        'provider_name' => $providerName,
        'provider_id' => $providerId,
        'username' => $username,
        'password' => $password,
        'api_version' => $apiVersion,
    ], JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);
    $output = '';
    while (($line = fgets($pipes[1])) !== false) {
        $record = json_decode($line, true);
        if (is_array($record) && ($record['event'] ?? '') === 'progress') {
            if ($streamProgress) { echo json_encode($record), "\n"; flush(); }
        } else { $output .= $line; }
    }
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    $indexerError = $indexerErrorLog === '/dev/null' ? '' : (string) @file_get_contents($indexerErrorLog);
    if ($indexerErrorLog !== '/dev/null') @unlink($indexerErrorLog);

    $stats = is_string($output) ? json_decode(trim($output), true) : null;
    if ($exitCode !== 0 || !is_array($stats) || ($stats['integrity'] ?? '') !== 'ok') {
        $diagnostic = trim((string) preg_replace('/\s+/', ' ', $indexerError));
        if ($diagnostic !== '') {
            error_log('APtv provider indexer failed (provider ' . $providerId . ', exit ' . $exitCode . '): ' . substr($diagnostic, 0, 1200));
        } else {
            error_log('APtv provider indexer failed (provider ' . $providerId . ', exit ' . $exitCode . ', no stderr).');
        }
        if (!$streamProgress) http_response_code(502);
        echo json_encode(['ok' => false, 'message' => _('Provider connection or indexing failed. Verify the credentials and try again.')]), "\n";
        return;
    }

    echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_SLASHES), "\n";
}
