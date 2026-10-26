<?php

declare(strict_types=1);

$stdinConfig = in_array('--stdin', $argv ?? [], true)
    ? json_decode((string) stream_get_contents(STDIN), true)
    : null;
$baseUrl = rtrim((string) (is_array($stdinConfig) ? ($stdinConfig['url'] ?? '') : getenv('IPTV_BASE_URL')), '/');
$username = (string) (is_array($stdinConfig) ? ($stdinConfig['username'] ?? '') : getenv('IPTV_USERNAME'));
$password = (string) (is_array($stdinConfig) ? ($stdinConfig['password'] ?? '') : getenv('IPTV_PASSWORD'));
$providerName = trim((string) (is_array($stdinConfig) ? ($stdinConfig['provider_name'] ?? '') : getenv('IPTV_PROVIDER_NAME')));
$providerId = trim((string) (is_array($stdinConfig) ? ($stdinConfig['provider_id'] ?? '') : getenv('IPTV_PROVIDER_ID')));
$apiVersion = (string) (is_array($stdinConfig) ? ($stdinConfig['api_version'] ?? 'v2') : (getenv('IPTV_API_VERSION') ?: 'v2'));
$regionClassifierVerify = getenv('APTV_REGION_CLASSIFIER_VERIFY') === '1';
$storageDirectory = '/var/lib/openap-iptv';
$providerDirectory = preg_match('/^[a-f0-9]{32}$/', $providerId) ? $storageDirectory . '/providers/' . $providerId : $storageDirectory;
$databasePath = (string) (getenv('IPTV_DATABASE') ?: $providerDirectory . '/catalog.db');
$payloadCacheDirectory = preg_match('/^[a-f0-9]{32}$/', $providerId) ? $providerDirectory . '/payload-cache' : '';
$payloadCacheManifestPath = $payloadCacheDirectory !== '' ? $payloadCacheDirectory . '/manifest.json' : '';
$payloadCacheManifest = [];
if ($payloadCacheManifestPath !== '' && is_file($payloadCacheManifestPath)) {
    $cachedManifest = json_decode((string) @file_get_contents($payloadCacheManifestPath), true);
    if (is_array($cachedManifest)) {
        $payloadCacheManifest = is_array($cachedManifest['entries'] ?? null) ? $cachedManifest['entries'] : $cachedManifest;
    }
}

// Provider catalogs are built and promoted atomically, but the active-provider
// pointer and provider deletion must not interleave with an indexing operation.
// Keep one lock for the complete indexer process so a switch can only observe
// a fully committed provider catalog.
$providerOperationLock = @fopen($storageDirectory . '/provider-operation.lock', 'c');
if (!is_resource($providerOperationLock) || !flock($providerOperationLock, LOCK_EX)) {
    if (is_resource($providerOperationLock)) @fclose($providerOperationLock);
    fwrite(STDERR, "Unable to acquire the provider operation lock.\n");
    exit(8);
}

function openapIptvRegionTokens(): array
{
    static $tokens;
    if (is_array($tokens)) return $tokens;
    return $tokens = [
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
        'DE' => ['DE', 'GERMANY', 'GERMAN', 'DEUTSCH'],
        'DK' => ['DK', 'DENMARK', 'DANISH'],
        'ES' => ['ES', 'SP', 'SPAIN', 'SPANISH'],
        'FI' => ['FI', 'FINLAND', 'FINNISH'],
        'FR' => ['FR', 'FRANCE', 'FRENCH'],
        'GE' => ['GE', 'GEORGIA', 'GEORGIAN'],
        'GR' => ['GR', 'GREECE', 'GREEK'],
        'HR' => ['HR', 'CROATIA', 'CROATIAN'],
        'HU' => ['HU', 'HUNGARY', 'HUNGARIAN'],
        'ID' => ['ID', 'INDONESIA', 'INDONESIAN'],
        'IE' => ['IE', 'IRELAND', 'IRISH'],
        'IL' => ['IL', 'ISRAEL', 'ISRAËL', 'ISRAELI'],
        'IR' => ['IR', 'IRAN', 'IRANIAN', 'PERSIAN'],
        'JP' => ['JP', 'JAPAN', 'JAPANESE'],
        'KR' => ['KR', 'KOREA', 'KOREAN'],
        'MA' => ['MA', 'MOROCCO', 'MOROCCAN'],
        'MY' => ['MY', 'MALAYSIA', 'MALASIA', 'MALAYSIAN'],
        'MX' => ['MX', 'MEXICO', 'MEXICAN'],
        'NL' => ['NL', 'NETHERLAND', 'NETHERLANDS', 'DUTCH'],
        'NO' => ['NO', 'NORWAY', 'NORWEGIAN'],
        'PH' => ['PH', 'PHILIPPINES', 'PHILIPPINE', 'FILIPINO'],
        'PL' => ['PL', 'POLAND', 'POLISH'],
        'PT' => ['PT', 'PORTUGAL', 'PORTUGUESE'],
        'RO' => ['RO', 'ROMANIA', 'ROMANIAN'],
        'RU' => ['RU', 'RUSSIA', 'RUSSIAN'],
        'SE' => ['SE', 'SWEDEN', 'SWEDISH'],
        'SI' => ['SI', 'SLOVENIA', 'SLOVENIAN'],
        'SK' => ['SK', 'SLOVAKIA', 'SLOVAK'],
        'TH' => ['TH', 'THAILAND', 'THAILLAND', 'THAI'],
        'TR' => ['TR', 'TURKEY', 'TURK', 'TURKISH'],
        'UA' => ['UA', 'UKRAINE', 'UKRAINIAN'],
        'UK' => ['UK', 'GB', 'EN', 'ENGLISH', 'UNITED KINGDOM', 'BRITISH'],
        'US' => ['US', 'USA', 'AMERICA', 'AMERICAN', 'UNITED STATES'],
        'VE' => ['VE', 'VENEZUELA', 'VENEZUELAN'],
        'VN' => ['VN', 'VIETNAM', 'VIETNAMESE'],
        'ZA' => ['ZA', 'SOUTH AFRICA', 'SOUTH AFRICAN'],
        'AFR' => ['AF', 'AFR', 'AFRICA', 'AFRICAN'],
        'CR' => ['CR', 'COSTA RICA'],
        'EXYU' => ['EXYU', 'FORMER YUGOSLAVIA'],
        'LA' => ['LA', 'LATINO', 'LATIN AMERICA'],
        // Country names used in otherwise unclassified provider categories.
        // Ambiguous family prefixes such as AR, AM, AS and EU are omitted.
        'AR' => ['ARGENTINA', 'ARGENTINE'],
        'BO' => ['BOLIVIA', 'BOLIVIAN'],
        'CL' => ['CHILE', 'CHILEAN'],
        'CO' => ['COLOMBIA', 'COLOMBIAN'],
        'DO' => ['DOMINICAN REP', 'DOMINICAN REPUBLIC'],
        'EC' => ['ECUADOR', 'ECUADORIAN'],
        'SV' => ['EL SALVADOR'],
        'HN' => ['HONDURAS', 'HONDURAN'],
        'PA' => ['PANAMA', 'PANAMANIAN'],
        'PY' => ['PARAGUAY', 'PARAGUAYAN'],
        'PE' => ['PERU', 'PERUVIAN'],
        'SR' => ['SURINAME'],
        'UY' => ['URUGUAY', 'URUGUAYAN'],
        'DZ' => ['ALGERIA', 'ALGERIAN'],
        'BH' => ['BAHRAIN', 'BAHRAINI'],
        'EG' => ['EGYPT', 'EGYPTIAN'],
        'AE' => ['EMIRATES', 'UNITED ARAB EMIRATES'],
        'IQ' => ['IRAQ', 'IRAQI'],
        'JO' => ['JORDAN', 'JORDANIAN'],
        'KW' => ['KUWAIT', 'KUWAITI'],
        'LB' => ['LEBANON', 'LEBANESE'],
        'LY' => ['LIBYA', 'LIBYAN'],
        'MR' => ['MAURITANIA', 'MAURITANIAN'],
        'OM' => ['OMAN', 'OMANI'],
        'PS' => ['PALESTINE', 'PALESTINIAN'],
        'QA' => ['QATAR', 'QATARI'],
        'SA' => ['SAUDI ARABIA', 'SAUDI'],
        'SD' => ['SUDAN', 'SUDANESE'],
        'SY' => ['SYRIA', 'SYRIAN'],
        'TN' => ['TUNISIA', 'TUNISIAN'],
        'YE' => ['YEMEN', 'YEMENI'],
        'AF' => ['AFGHANISTAN', 'AFGHAN'],
        'BD' => ['BANGLADESH', 'BANGLADESHI'],
        'KH' => ['CAMBODIA', 'CAMBODIAN'],
        'IN' => ['INDIA', 'INDIAN'],
        'KZ' => ['KAZAKHSTAN', 'KAZAKH'],
        'NZ' => ['NEW ZEALAND', 'NEW ZEALANDER'],
        'PK' => ['PAKISTAN', 'PAKISTANI'],
        'SG' => ['SINGAPORE', 'SINGAPOREAN'],
        'LK' => ['SRI LANKA', 'SRI LANKAN'],
        'TW' => ['TAIWAN', 'TAIWANESE'],
        'AM' => ['ARMENIA', 'ARMENIAN'],
        'AZ' => ['AZERBAIJAN', 'AZERBAIJANI'],
        'CY' => ['CYPRUS', 'CYPRIOT'],
        'EE' => ['ESTONIA', 'ESTONIAN'],
        'IS' => ['ICELAND', 'ICELANDIC'],
        'LV' => ['LATVIA', 'LATVIAN'],
        'LT' => ['LITHUANIA', 'LITHUANIAN'],
        'MT' => ['MALTA', 'MALTESE'],
    ];
}

function openapIptvRegionFromToken(string $token): ?string
{
    $token = strtoupper(trim($token));
    foreach (openapIptvRegionTokens() as $region => $tokens) {
        if (in_array($token, $tokens, true)) return $region;
    }
    return null;
}

function openapIptvExplicitRegionTokens(): array
{
    static $tokens;
    if (is_array($tokens)) return $tokens;
    $tokens = openapIptvRegionTokens();
    foreach ([
        'AE', 'BA', 'BH', 'BJ', 'BF', 'BO', 'BS', 'BW', 'BY', 'BZ', 'CD', 'CI', 'CL', 'CM', 'CO', 'CU',
        'CV', 'DJ', 'DO', 'DZ', 'EC', 'EG', 'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GT', 'GY', 'HN', 'HK',
        'HT', 'IQ', 'IS', 'JM', 'JO', 'KE', 'KW', 'KZ', 'LB', 'LC', 'LI', 'LK', 'LR', 'LT', 'LU', 'LY', 'MD',
        'ME', 'MG', 'MK', 'MM', 'MN', 'MR', 'MT', 'MU', 'MV', 'MW', 'MZ', 'NA', 'NE', 'NG', 'NI', 'NP',
        'OM', 'PA', 'PE', 'PK', 'PS', 'PY', 'QA', 'RS', 'SA', 'SD', 'SG', 'SN', 'SO', 'SR', 'SS', 'SV',
        'SY', 'TD', 'TG', 'TJ', 'TM', 'TN', 'TZ', 'UG', 'UY', 'UZ', 'YE', 'ZM', 'ZW',
    ] as $code) {
        if (!isset($tokens[$code])) $tokens[$code] = [$code];
        elseif (!in_array($code, $tokens[$code], true)) $tokens[$code][] = $code;
    }
    return $tokens;
}

function openapIptvRegionPatterns(bool $pipeDelimitedOnly = false): array
{
    static $patterns = ['text' => null, 'pipe' => null];
    $cacheKey = $pipeDelimitedOnly ? 'pipe' : 'text';
    if (is_array($patterns[$cacheKey])) return $patterns[$cacheKey];
    $compiled = [];
    $regionTokens = $pipeDelimitedOnly ? openapIptvExplicitRegionTokens() : openapIptvRegionTokens();
    foreach ($regionTokens as $region => $tokens) {
        foreach ($tokens as $token) {
            $escaped = preg_quote($token, '/');
            $pattern = $pipeDelimitedOnly
                ? '/(?:^|\|)' . $escaped . '\|/u'
                : (strlen($token) <= 3
                    ? '/(?:^|[\s|\/_-])' . $escaped . '(?=$|[\s|\/_-])/u'
                    : '/(?:^|[\s|\/_-])' . $escaped . '(?:$|[\s|\/_-])/u');
            $compiled[] = [$region, $pattern];
        }
    }
    return $patterns[$cacheKey] = $compiled;
}

function openapIptvRegionFromText(string $text, bool $pipeDelimitedOnly = false): ?string
{
    $value = trim(mb_strtoupper($text, 'UTF-8'));
    if ($value === '') return null;
    foreach (openapIptvRegionPatterns($pipeDelimitedOnly) as [$region, $pattern]) {
        if (preg_match($pattern, $value) === 1) return $region;
    }
    return null;
}

function openapIptvItemRegion(string $kind, string $itemName, string $categoryName): array
{
    $itemRegion = openapIptvRegionFromText($itemName, true);
    if ($itemRegion !== null) return [$itemRegion, 'item-name'];
    $categoryRegion = openapIptvRegionFromText($categoryName, false);
    if ($categoryRegion !== null) return [$categoryRegion, 'category-name'];
    return ['OTHER', 'fallback'];
}

function openapIptvFastItemRegion(string $itemName): ?string
{
    static $tokenPriority;
    if (!is_array($tokenPriority)) {
        $tokenPriority = [];
        $priority = 0;
        foreach (openapIptvExplicitRegionTokens() as $region => $tokens) {
            foreach ($tokens as $token) {
                $token = strtoupper(trim($token));
                if ($token !== '' && !isset($tokenPriority[$token])) {
                    $tokenPriority[$token] = [$region, $priority];
                }
                $priority++;
            }
        }
    }
    $value = trim(mb_strtoupper($itemName, 'UTF-8'));
    if ($value === '') return null;
    $parts = explode('|', $value);
    $bestRegion = null;
    $bestPriority = PHP_INT_MAX;
    // The legacy regex requires every matching token to be followed by '|'.
    // Iterating all segments except the final one preserves that boundary.
    for ($index = 0, $last = count($parts) - 1; $index < $last; $index++) {
        $token = $parts[$index];
        if (!isset($tokenPriority[$token])) continue;
        [$region, $priority] = $tokenPriority[$token];
        if ($priority < $bestPriority) {
            $bestPriority = $priority;
            $bestRegion = $region;
        }
    }
    return $bestRegion;
}

function openapIptvFastItemRegionResult(string $kind, string $itemName, string $categoryName): array
{
    $itemRegion = openapIptvFastItemRegion($itemName);
    if ($itemRegion !== null) return [$itemRegion, 'item-name'];
    static $categoryCache = [];
    if (!array_key_exists($categoryName, $categoryCache)) {
        $categoryRegion = openapIptvRegionFromText($categoryName, false);
        $categoryCache[$categoryName] = $categoryRegion === null
            ? ['OTHER', 'fallback']
            : [$categoryRegion, 'category-name'];
    }
    return $categoryCache[$categoryName];
}

function openapIptvFastCategoryRegionResult(string $categoryName): array
{
    static $categoryCache = [];
    if (!array_key_exists($categoryName, $categoryCache)) {
        $categoryRegion = openapIptvRegionFromText($categoryName, false);
        $categoryCache[$categoryName] = $categoryRegion === null
            ? ['OTHER', 'fallback']
            : [$categoryRegion, 'category-name'];
    }
    return $categoryCache[$categoryName];
}

function openapIptvVerifyRegionClassifier(array $payloads): void
{
    $categoryNames = ['live' => [], 'vod' => [], 'series' => []];
    foreach (['live', 'vod', 'series'] as $kind) {
        foreach (($payloads[$kind . '_categories'] ?? []) as $category) {
            $categoryNames[$kind][(string) ($category['category_id'] ?? '')] = trim((string) ($category['category_name'] ?? ''));
        }
    }
    foreach (['live', 'vod', 'series'] as $kind) {
        $idKey = $kind === 'series' ? 'series_id' : 'stream_id';
        foreach (($payloads[$kind] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') continue;
            $categoryId = (string) ($item['category_id'] ?? '');
            $categoryName = (string) ($categoryNames[$kind][$categoryId] ?? '');
            $legacy = openapIptvItemRegion($kind, $name, $categoryName);
            $optimized = openapIptvFastItemRegionResult($kind, $name, $categoryName);
            if ($legacy !== $optimized) {
                throw new RuntimeException('Region classifier zero-diff gate failed for ' . $kind . ':' . (string) ($item[$idKey] ?? ''));
            }
        }
    }
}

function openapIptvPointActiveProvider(string $storageDirectory, string $providerId): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) return;
    $providerDirectory = $storageDirectory . '/providers/' . $providerId;
    $catalog = $providerDirectory . '/catalog.db';
    $preferences = $providerDirectory . '/preferences/catalog-order.db';
    if (!is_file($catalog) || !is_file($preferences)) return;
    if (!is_dir($storageDirectory . '/preferences')) @mkdir($storageDirectory . '/preferences', 02770, true);
    $catalogTemporary = $storageDirectory . '/catalog.db.new.' . getmypid();
    $preferencesTemporary = $storageDirectory . '/preferences/catalog-order.db.new.' . getmypid();
    @unlink($catalogTemporary); @unlink($preferencesTemporary);
    if (!symlink('providers/' . $providerId . '/catalog.db', $catalogTemporary) || !rename($catalogTemporary, $storageDirectory . '/catalog.db')) {
        @unlink($catalogTemporary); throw new RuntimeException('Unable to point the active IPTV catalog.');
    }
    if (!symlink('../providers/' . $providerId . '/preferences/catalog-order.db', $preferencesTemporary) || !rename($preferencesTemporary, $storageDirectory . '/preferences/catalog-order.db')) {
        @unlink($preferencesTemporary); throw new RuntimeException('Unable to point the active IPTV preferences.');
    }
}

function openapIptvRemoveProviderDirectory(string $storageDirectory, string $providerId): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $providerId)) {
        throw new RuntimeException('Invalid provider directory.');
    }
    $providerDirectory = $storageDirectory . '/providers/' . $providerId;
    if (!is_dir($providerDirectory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($providerDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || $entry->isFile()) {
            if (!@unlink($path) && file_exists($path)) throw new RuntimeException('Unable to remove provider data.');
        } elseif ($entry->isDir() && !@rmdir($path) && is_dir($path)) {
            throw new RuntimeException('Unable to remove provider data.');
        }
    }
    if (!@rmdir($providerDirectory) && is_dir($providerDirectory)) {
        throw new RuntimeException('Unable to remove provider directory.');
    }
}

if (is_array($stdinConfig) && ($stdinConfig['operation'] ?? '') === 'restore_collection') {
    $directory = $storageDirectory;
    $key = @file_get_contents($directory . '/provider.key');
    $providerPayload = is_array($stdinConfig['provider'] ?? null) ? $stdinConfig['provider'] : [];
    $requestedMode = (string) ($stdinConfig['mode'] ?? 'replace');
    if (!in_array($requestedMode, ['replace', 'merge'], true)
        || !is_string($key)
        || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        || !is_array($providerPayload)
        || !is_array($providerPayload['profiles'] ?? null)
        || count($providerPayload['profiles']) > 20) {
        fwrite(STDERR, "Invalid provider profile import.\n"); exit(2);
    }
    $cleanProfiles = [];
    foreach ($providerPayload['profiles'] as $profile) {
        if (!is_array($profile)) continue;
        $id = trim((string) ($profile['id'] ?? ''));
        $name = trim((string) ($profile['name'] ?? ''));
        $url = rtrim(trim((string) ($profile['url'] ?? '')), '/');
        $username = trim((string) ($profile['username'] ?? ''));
        $password = (string) ($profile['password'] ?? '');
        $version = (string) ($profile['api_version'] ?? 'v2');
        $parts = parse_url($url);
        if (!preg_match('/^[a-f0-9]{32}$/', $id)
            || $name === '' || mb_strlen($name) > 80
            || !is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])
            || $username === '' || strlen($username) > 256 || $password === '' || strlen($password) > 512
            || !in_array($version, ['v1', 'v2'], true) || strlen($url) > 2048) continue;
        $cleanProfiles[$id] = ['id'=>$id,'name'=>$name,'url'=>$url,'username'=>$username,'password'=>$password,'api_version'=>$version];
    }
    $decryptCollection = static function () use ($directory, $key): array {
        $sealed = @json_decode((string) @file_get_contents($directory . '/providers.enc'), true);
        $nonce = is_array($sealed) ? base64_decode((string) ($sealed['nonce'] ?? ''), true) : false;
        $ciphertext = is_array($sealed) ? base64_decode((string) ($sealed['ciphertext'] ?? ''), true) : false;
        if (!is_string($nonce) || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || !is_string($ciphertext)) return ['version'=>1,'active_id'=>'','profiles'=>[]];
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        $decoded = is_string($plain) ? json_decode($plain, true) : null;
        if (is_string($plain)) sodium_memzero($plain);
        return is_array($decoded) ? $decoded : ['version'=>1,'active_id'=>'','profiles'=>[]];
    };
    $current = $decryptCollection();
    if ($requestedMode === 'merge') {
        foreach (($current['profiles'] ?? []) as $profile) {
            if (is_array($profile) && preg_match('/^[a-f0-9]{32}$/', (string) ($profile['id'] ?? '')) && !isset($cleanProfiles[$profile['id']])) $cleanProfiles[$profile['id']] = $profile;
        }
    }
    if (!$cleanProfiles) { fwrite(STDERR, "No valid provider credentials found.\n"); exit(3); }
    $activeId = trim((string) ($providerPayload['active_id'] ?? ''));
    if (!isset($cleanProfiles[$activeId])) $activeId = $requestedMode === 'merge' && isset($current['active_id']) && isset($cleanProfiles[$current['active_id']]) ? (string) $current['active_id'] : (string) array_key_first($cleanProfiles);
    $collection = ['version'=>1,'active_id'=>$activeId,'profiles'=>array_values($cleanProfiles)];
    $seal = static function (array $payload) use ($key): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sealed = json_encode(['version'=>1,'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($plain, $nonce, $key))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        sodium_memzero($plain);
        return $sealed;
    };
    $collectionPath = $directory . '/providers.enc';
    $collectionTemporaryPath = $collectionPath . '.new.' . getmypid();
    $collectionSealed = $seal($collection);
    if (file_put_contents($collectionTemporaryPath, $collectionSealed, LOCK_EX) === false || !rename($collectionTemporaryPath, $collectionPath)) { @unlink($collectionTemporaryPath); fwrite(STDERR, "Unable to save provider collection.\n"); exit(4); }
    $activeProfile = $cleanProfiles[$activeId];
    $profilePath = $directory . '/provider.enc';
    $profileTemporaryPath = $profilePath . '.new.' . getmypid();
    $profileSealed = $seal($activeProfile);
    if (file_put_contents($profileTemporaryPath, $profileSealed, LOCK_EX) === false || !rename($profileTemporaryPath, $profilePath)) { @unlink($profileTemporaryPath); fwrite(STDERR, "Unable to save active provider.\n"); exit(5); }
    chmod($collectionPath, 0640); chmod($profilePath, 0640); @chgrp($collectionPath, 'www-data'); @chgrp($profilePath, 'www-data');
    openapIptvPointActiveProvider($storageDirectory, $activeId);
    echo json_encode(['ok'=>true,'active_id'=>$activeId,'profiles'=>count($cleanProfiles)], JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if (is_array($stdinConfig) && ($stdinConfig['operation'] ?? '') === 'switch_provider') {
    $directory = $storageDirectory;
    $key = @file_get_contents($directory . '/provider.key');
    $providerId = trim((string) ($stdinConfig['provider_id'] ?? ''));
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !preg_match('/^[a-f0-9]{32}$/', $providerId)) {
        fwrite(STDERR, "Invalid provider switch request.\n"); exit(2);
    }
    $sealed = @json_decode((string) @file_get_contents($directory . '/providers.enc'), true);
    $nonce = is_array($sealed) ? base64_decode((string) ($sealed['nonce'] ?? ''), true) : false;
    $ciphertext = is_array($sealed) ? base64_decode((string) ($sealed['ciphertext'] ?? ''), true) : false;
    $plain = is_string($nonce) && is_string($ciphertext) ? sodium_crypto_secretbox_open($ciphertext, $nonce, $key) : false;
    $collection = is_string($plain) ? json_decode($plain, true) : null;
    if (is_string($plain)) sodium_memzero($plain);
    if (!is_array($collection)) { fwrite(STDERR, "Invalid provider collection.\n"); exit(3); }
    $activeProfile = null;
    foreach (($collection['profiles'] ?? []) as $profile) {
        if (is_array($profile) && hash_equals((string) ($profile['id'] ?? ''), $providerId)) {
            $activeProfile = $profile;
            break;
        }
    }
    if (!is_array($activeProfile)) { fwrite(STDERR, "Provider not found.\n"); exit(4); }
    if (!is_file($storageDirectory . '/providers/' . $providerId . '/catalog.db') || !is_file($storageDirectory . '/providers/' . $providerId . '/preferences/catalog-order.db')) {
        fwrite(STDERR, "Provider catalog is not indexed.\n"); exit(7);
    }
    $collection['version'] = 1;
    $collection['active_id'] = $providerId;
    $collectionPath = $directory . '/providers.enc';
    $collectionPlain = json_encode($collection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $collectionNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $collectionSealed = json_encode(['version'=>1,'nonce'=>base64_encode($collectionNonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($collectionPlain, $collectionNonce, $key))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    sodium_memzero($collectionPlain);
    $collectionTemporary = $collectionPath . '.new.' . getmypid();
    if (file_put_contents($collectionTemporary, $collectionSealed, LOCK_EX) === false || !rename($collectionTemporary, $collectionPath)) {
        @unlink($collectionTemporary); fwrite(STDERR, "Unable to save provider collection.\n"); exit(5);
    }
    chmod($collectionPath, 0640); @chgrp($collectionPath, 'www-data');
    $profilePath = $directory . '/provider.enc';
    $profilePlain = json_encode($activeProfile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $profileNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $profileSealed = json_encode(['version'=>1,'nonce'=>base64_encode($profileNonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($profilePlain, $profileNonce, $key))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    sodium_memzero($profilePlain);
    $profileTemporary = $profilePath . '.new.' . getmypid();
    if (file_put_contents($profileTemporary, $profileSealed, LOCK_EX) === false || !rename($profileTemporary, $profilePath)) {
        @unlink($profileTemporary); fwrite(STDERR, "Unable to save active provider.\n"); exit(6);
    }
    chmod($profilePath, 0640); @chgrp($profilePath, 'www-data');
    openapIptvPointActiveProvider($storageDirectory, $providerId);
    echo json_encode(['ok'=>true,'active_id'=>$providerId], JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

if (is_array($stdinConfig) && ($stdinConfig['operation'] ?? '') === 'delete_provider') {
    $directory = $storageDirectory; $key = @file_get_contents($directory . '/provider.key');
    $providerId = trim((string) ($stdinConfig['provider_id'] ?? ''));
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !preg_match('/^[a-f0-9]{32}$/', $providerId)) { fwrite(STDERR, "Invalid provider deletion request.\n"); exit(2); }
    $sealed = @json_decode((string) @file_get_contents($directory . '/providers.enc'), true);
    $nonce = is_array($sealed) ? base64_decode((string)($sealed['nonce'] ?? ''), true) : false;
    $ciphertext = is_array($sealed) ? base64_decode((string)($sealed['ciphertext'] ?? ''), true) : false;
    $plain = is_string($nonce) && is_string($ciphertext) ? sodium_crypto_secretbox_open($ciphertext, $nonce, $key) : false;
    $collection = is_string($plain) ? json_decode($plain, true) : null; if (is_string($plain)) sodium_memzero($plain);
    if (!is_array($collection)) { fwrite(STDERR, "Invalid provider collection.\n"); exit(3); }
    $profiles=[]; $found=false; foreach (($collection['profiles'] ?? []) as $profile) { if (!is_array($profile)) continue; if (hash_equals((string)($profile['id'] ?? ''), $providerId)) { $found=true; continue; } $profiles[]=$profile; }
    if (!$found) { fwrite(STDERR, "Provider not found.\n"); exit(4); }
    $activeId=(string)($collection['active_id'] ?? ''); if ($activeId===$providerId || !in_array($activeId,array_map(static fn(array $profile): string=>(string)($profile['id']??''),$profiles),true)) $activeId=(string)($profiles[0]['id']??'');
    $collection=['version'=>1,'active_id'=>$activeId,'profiles'=>array_values($profiles)]; $newNonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $newPlain=json_encode($collection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); $newSealed=json_encode(['version'=>1,'nonce'=>base64_encode($newNonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($newPlain,$newNonce,$key))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); sodium_memzero($newPlain);
    $collectionPath=$directory.'/providers.enc'; $temporaryPath=$collectionPath.'.new.'.getmypid(); if(file_put_contents($temporaryPath,$newSealed,LOCK_EX)===false||!rename($temporaryPath,$collectionPath)){@unlink($temporaryPath);fwrite(STDERR,"Unable to save provider collection.\n");exit(5);} chmod($collectionPath,0640); @chgrp($collectionPath,'www-data');
    if (!$profiles) {
        try {
            $catalog = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE); $catalog->busyTimeout(3000); $catalog->exec('BEGIN IMMEDIATE');
            $preferences = new SQLite3($providerDirectory.'/preferences/catalog-order.db', SQLITE3_OPEN_READWRITE); $preferences->busyTimeout(3000); $preferences->exec('BEGIN IMMEDIATE');
            $catalog->exec('DELETE FROM catalog_fts'); $catalog->exec('DELETE FROM items'); $catalog->exec('DELETE FROM categories'); $catalog->exec('DELETE FROM index_metadata');
            $preferences->exec('DELETE FROM catalog_order');
            $catalog->exec('COMMIT'); $preferences->exec('COMMIT'); $catalog->close(); $preferences->close();
        } catch (Throwable $error) { if (isset($catalog) && $catalog instanceof SQLite3) { @$catalog->exec('ROLLBACK'); @$catalog->close(); } if (isset($preferences) && $preferences instanceof SQLite3) { @$preferences->exec('ROLLBACK'); @$preferences->close(); } fwrite(STDERR,"Unable to clear the APtv catalog.\n"); exit(6); }
        @unlink($directory.'/provider.enc');
    } elseif (preg_match('/^[a-f0-9]{32}$/', $activeId)) {
        openapIptvPointActiveProvider($storageDirectory, $activeId);
    }
    try {
        openapIptvRemoveProviderDirectory($storageDirectory, $providerId);
    } catch (Throwable $error) {
        fwrite(STDERR, "Unable to remove provider data.\n"); exit(7);
    }
    echo json_encode(['ok'=>true,'remaining'=>count($profiles),'active_id'=>$activeId],JSON_UNESCAPED_SLASHES),"\n"; exit(0);
}

if (is_array($stdinConfig) && ($stdinConfig['operation'] ?? '') === 'clear_catalog') {
    try {
        $catalog = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE); $catalog->busyTimeout(3000); $catalog->exec('BEGIN IMMEDIATE');
        $preferences = new SQLite3(dirname($databasePath).'/preferences/catalog-order.db', SQLITE3_OPEN_READWRITE); $preferences->busyTimeout(3000); $preferences->exec('BEGIN IMMEDIATE');
        $catalog->exec('DELETE FROM catalog_fts'); $catalog->exec('DELETE FROM items'); $catalog->exec('DELETE FROM categories'); $catalog->exec('DELETE FROM index_metadata'); $preferences->exec('DELETE FROM catalog_order');
        $catalog->exec('COMMIT'); $preferences->exec('COMMIT'); $catalog->close(); $preferences->close(); echo json_encode(['ok'=>true],JSON_UNESCAPED_SLASHES),"\n"; exit(0);
    } catch (Throwable $error) { if (isset($catalog) && $catalog instanceof SQLite3) { @$catalog->exec('ROLLBACK'); @$catalog->close(); } if (isset($preferences) && $preferences instanceof SQLite3) { @$preferences->exec('ROLLBACK'); @$preferences->close(); } fwrite(STDERR,"Unable to clear the APtv catalog.\n"); exit(6); }
}

if (is_array($stdinConfig) && ($stdinConfig['operation'] ?? '') === 'rename') {
    if ($providerName === '' || mb_strlen($providerName) > 80 || !preg_match('/^[a-f0-9]{32}$/', $providerId)) {
        fwrite(STDERR, "Invalid provider rename request.\n"); exit(2);
    }
    $directory = $storageDirectory; $key = @file_get_contents($directory . '/provider.key');
    $collectionPath = $directory . '/providers.enc'; $sealed = @json_decode((string)@file_get_contents($collectionPath), true);
    $nonce = is_array($sealed) ? base64_decode((string)($sealed['nonce']??''), true) : false;
    $ciphertext = is_array($sealed) ? base64_decode((string)($sealed['ciphertext']??''), true) : false;
    if (!is_string($key) || !is_string($nonce) || !is_string($ciphertext)) { fwrite(STDERR, "Provider collection unavailable.\n"); exit(3); }
    $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key); $collection = is_string($plain) ? json_decode($plain, true) : null;
    if (is_string($plain)) sodium_memzero($plain); if (!is_array($collection)) { fwrite(STDERR, "Invalid provider collection.\n"); exit(3); }
    $found = false;
    foreach ($collection['profiles'] as &$profile) if (is_array($profile) && hash_equals((string)($profile['id']??''), $providerId)) { $profile['name']=$providerName; $found=true; break; }
    unset($profile); if (!$found) { fwrite(STDERR, "Provider not found.\n"); exit(4); }
    $newNonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$newPlain=json_encode($collection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $newSealed=json_encode(['version'=>1,'nonce'=>base64_encode($newNonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($newPlain,$newNonce,$key))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $temporaryPath=$collectionPath.'.new.'.getmypid();if(file_put_contents($temporaryPath,$newSealed,LOCK_EX)===false){sodium_memzero($newPlain);exit(5);}chmod($temporaryPath,0640);@chgrp($temporaryPath,'www-data');
    if(!rename($temporaryPath,$collectionPath)){@unlink($temporaryPath);sodium_memzero($newPlain);exit(5);}sodium_memzero($newPlain);
    echo json_encode(['ok'=>true,'renamed_only'=>true],JSON_UNESCAPED_SLASHES),"\n";exit(0);
}

if ($baseUrl === '' || $username === '' || $password === '' || $providerName === '' || !preg_match('/^[a-f0-9]{32}$/', $providerId)) {
    fwrite(STDERR, "Missing IPTV connection environment.\n");
    exit(2);
}

// Progress records contain only local labels and numeric counters, never provider URLs.
$reportProgress = static function (string $message, int $step, ?string $kind = null, ?int $count = null) use ($stdinConfig): void {
    if (!empty($stdinConfig['progress'])) {
        $record = ['event'=>'progress', 'message'=>$message, 'step'=>$step];
        if ($kind !== null) {
            $record['catalog_kind'] = $kind;
            $record['catalog_count'] = max(0, (int) $count);
        }
        echo json_encode($record), "\n";
        flush();
    }
};
$reportProgress('Connecting to provider…', 0);
$started = hrtime(true);
$actions = [
    'account' => null,
    'live_categories' => 'get_live_categories',
    'live' => 'get_live_streams',
    'vod_categories' => 'get_vod_categories',
    'vod' => 'get_vod_streams',
    'series_categories' => 'get_series_categories',
    'series' => 'get_series',
];
if ($payloadCacheDirectory !== '' && !is_dir($payloadCacheDirectory)) {
    if (!mkdir($payloadCacheDirectory, 0750, true) && !is_dir($payloadCacheDirectory)) {
        throw new RuntimeException('Unable to create the IPTV payload cache directory.');
    }
    chmod($payloadCacheDirectory, 0750);
    @chgrp($payloadCacheDirectory, 'www-data');
}
$multi = curl_multi_init();
$handles = [];
$responseHeaders = [];

foreach ($actions as $key => $action) {
    $query = [
        'username' => $username,
        'password' => $password,
    ];
    if (is_string($action)) {
        $query['action'] = $action;
    }
    $url = $baseUrl . '/player_api.php?' . http_build_query($query);
    $handle = curl_init($url);
    $responseHeaders[$key] = [];
    $requestHeaders = [];
    if (is_array($payloadCacheManifest[$key] ?? null)) {
        $cachedEntry = $payloadCacheManifest[$key];
        if (($cachedEntry['etag'] ?? '') !== '') $requestHeaders[] = 'If-None-Match: ' . (string) $cachedEntry['etag'];
        if (($cachedEntry['last_modified'] ?? '') !== '') $requestHeaders[] = 'If-Modified-Since: ' . (string) $cachedEntry['last_modified'];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        // Large provider VOD catalogs can take several minutes even when the
        // server has already sent HTTP 200 and is still streaming JSON.
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'APtv-indexer/0.1',
        // Negotiate gzip/br and let libcurl transparently return the
        // decompressed JSON to the indexer. Payload hashing and the on-disk
        // cache therefore remain based on content, never wire encoding.
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders, $key): int {
            $length = strlen($header);
            $separator = strpos($header, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($header, 0, $separator)));
                $value = trim(substr($header, $separator + 1));
                if ($name !== '') $responseHeaders[$key][$name] = $value;
            }
            return $length;
        },
    ]);
    curl_multi_add_handle($multi, $handle);
    $handles[$key] = $handle;
}

$downloadStarted = hrtime(true);
$lastProgress = 0;
$completedDownloads = [];
$labels = ['account'=>'Account', 'live_categories'=>'Live categories', 'live'=>'Live channels', 'vod_categories'=>'Movie categories', 'vod'=>'Movies', 'series_categories'=>'Series categories', 'series'=>'TV series'];
do {
    $status = curl_multi_exec($multi, $running);
    while ($info = curl_multi_info_read($multi)) {
        $key = array_search($info['handle'], $handles, true);
        $responseCode = $key !== false ? curl_getinfo($info['handle'], CURLINFO_RESPONSE_CODE) : 0;
        if ($key !== false && $info['result'] === CURLE_OK && in_array($responseCode, [200, 304], true)) {
            $completedDownloads[$key] = true;
            $reportProgress($labels[$key] . ': response received', count($completedDownloads));
        }
    }
    if (microtime(true) - $lastProgress >= 1) {
        $bytes = 0;
        foreach ($handles as $handle) $bytes += (int) curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD);
        $reportProgress('Downloaded ' . number_format($bytes / 1048576, 2, '.', '') . ' MB', count($completedDownloads));
        $lastProgress = microtime(true);
    }
    if ($running) {
        curl_multi_select($multi, 1.0);
    }
} while ($running && $status === CURLM_OK);
$networkFinished = hrtime(true);

$payloads = [];
$payloadHashes = [];
$cacheEntries = [];
$payloadCacheTemporaryPaths = [];
$cacheResponseHits = 0;
$downloadBytes = 0;
$cacheWriteSeconds = 0.0;
$payloadProcessingStarted = hrtime(true);
foreach ($handles as $key => $handle) {
    $body = curl_multi_getcontent($handle);
    $http = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    curl_multi_remove_handle($multi, $handle);
    curl_close($handle);
    $cachedPath = $payloadCacheDirectory !== '' ? $payloadCacheDirectory . '/' . $key . '.json' : '';
    if ($error !== 0 || ($http !== 200 && $http !== 304) || !is_string($body)) {
        throw new RuntimeException("Metadata request failed for {$key} (HTTP {$http}, curl {$error}).");
    }
    if ($http === 304) {
        if ($cachedPath === '' || !is_file($cachedPath)) {
            throw new RuntimeException("Provider returned 304 for {$key}, but its local payload cache is missing.");
        }
        $body = (string) @file_get_contents($cachedPath);
        $cacheResponseHits++;
        $reportProgress($labels[$key] . ': reused cached response', 7);
    } else {
        $downloadBytes += strlen($body);
    }
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid metadata payload for {$key}.");
    }
    $payloads[$key] = $decoded;
    $payloadHashes[$key] = hash('sha256', $body);
    if ($cachedPath !== '') {
        $temporaryCachePath = $cachedPath . '.new.' . getmypid();
        $cacheWriteStarted = hrtime(true);
        if (file_put_contents($temporaryCachePath, $body, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write the payload cache for {$key}.");
        }
        $cacheWriteSeconds += (hrtime(true) - $cacheWriteStarted) / 1e9;
        chmod($temporaryCachePath, 0640);
        @chgrp($temporaryCachePath, 'www-data');
        $payloadCacheTemporaryPaths[$key] = $temporaryCachePath;
    }
    $cacheEntries[$key] = [
        'sha256' => $payloadHashes[$key],
        'bytes' => strlen($body),
        'etag' => (string) ($responseHeaders[$key]['etag'] ?? ($payloadCacheManifest[$key]['etag'] ?? '')),
        'last_modified' => (string) ($responseHeaders[$key]['last-modified'] ?? ($payloadCacheManifest[$key]['last_modified'] ?? '')),
        'updated_at' => gmdate('c'),
    ];
    if ($key !== 'account') $reportProgress($labels[$key] . ': ' . count($decoded) . ' entries', 7);
}
curl_multi_close($multi);
if ($regionClassifierVerify) {
    openapIptvVerifyRegionClassifier($payloads);
}
$downloadSeconds = (hrtime(true) - $downloadStarted) / 1e9;
$networkSeconds = ($networkFinished - $downloadStarted) / 1e9;
$payloadProcessingSeconds = (hrtime(true) - $payloadProcessingStarted) / 1e9;
$semanticStarted = hrtime(true);
$semanticDigest = hash_init('sha256');
hash_update($semanticDigest, 'ap-tv-semantic-catalog-v1' . "\0");
$semanticUpdate = static function (string $label, array $record) use (&$semanticDigest): void {
    hash_update($semanticDigest, $label . "\0" . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
};
$accountInfo = is_array($payloads['account']['user_info'] ?? null) ? $payloads['account']['user_info'] : [];
$semanticUpdate('account', [
    'status' => (string) ($accountInfo['status'] ?? ''),
    'expiration' => (string) ($accountInfo['exp_date'] ?? ''),
]);
foreach (['live', 'vod', 'series'] as $kind) {
    $categories = $payloads[$kind . '_categories'];
    usort($categories, static function (array $left, array $right): int {
        $leftId = (string) ($left['category_id'] ?? '');
        $rightId = (string) ($right['category_id'] ?? '');
        $comparison = strcmp($leftId, $rightId);
        return $comparison !== 0 ? $comparison : strcmp(json_encode($left), json_encode($right));
    });
    foreach ($categories as $category) {
        $semanticUpdate($kind . ':category', [
            'id' => (string) ($category['category_id'] ?? ''),
            'name' => trim((string) ($category['category_name'] ?? '')),
            'parent' => isset($category['parent_id']) ? (string) $category['parent_id'] : null,
        ]);
    }
    $items = $payloads[$kind];
    $idKey = $kind === 'series' ? 'series_id' : 'stream_id';
    usort($items, static function (array $left, array $right) use ($idKey): int {
        $leftId = (string) ($left[$idKey] ?? '');
        $rightId = (string) ($right[$idKey] ?? '');
        $comparison = strcmp($leftId, $rightId);
        return $comparison !== 0 ? $comparison : strcmp(json_encode($left), json_encode($right));
    });
    foreach ($items as $item) {
        $rating = $item['rating_5based'] ?? $item['rating'] ?? null;
        $semanticUpdate($kind . ':item', [
            'id' => (string) ($item[$idKey] ?? ''),
            'category' => isset($item['category_id']) ? (string) $item['category_id'] : null,
            'name' => trim((string) ($item['name'] ?? '')),
            'icon' => (string) ($kind === 'series' ? ($item['cover'] ?? '') : ($item['stream_icon'] ?? '')),
            'epg' => (string) ($item['epg_channel_id'] ?? ''),
            'extension' => (string) ($item['container_extension'] ?? ''),
            'added' => (string) ($item['added'] ?? ''),
            'rating' => is_numeric($rating) ? (float) $rating : null,
        ]);
    }
}
$payloadFingerprint = hash_final($semanticDigest);
$semanticSeconds = (hrtime(true) - $semanticStarted) / 1e9;
$catalogSchemaVersion = '5';

/**
 * Build the fingerprint for the materialized navigation payload only.
 * Keep this independent from payload_fingerprint, timestamps and metadata
 * so a metadata-only catalog rebuild does not invalidate nav clients.
 */
function openapIptvNavFingerprint(SQLite3 $db): string
{
    $digest = hash_init('sha256');
    $regionQuery = $db->query('SELECT kind, region, item_count, category_count FROM nav_regions ORDER BY kind, region');
    while ($row = $regionQuery->fetchArray(SQLITE3_NUM)) {
        hash_update($digest, implode("\x1f", [
            'region', (string) $row[0], (string) $row[1], (string) ((int) $row[2]), (string) ((int) $row[3]),
        ]) . "\x1e");
    }
    $categoryQuery = $db->query('SELECT kind, region, category_id, name, item_count FROM nav_categories ORDER BY kind, region, name, category_id');
    while ($row = $categoryQuery->fetchArray(SQLITE3_NUM)) {
        hash_update($digest, implode("\x1f", [
            'category', (string) $row[0], (string) $row[1], (string) $row[2], (string) $row[3], (string) ((int) $row[4]),
        ]) . "\x1e");
    }
    return hash_final($digest);
}
$cachePromotionSeconds = 0.0;
if ($payloadCacheDirectory !== '' && $payloadCacheTemporaryPaths) {
    $cachePromotionStarted = hrtime(true);
    foreach ($payloadCacheTemporaryPaths as $key => $temporaryCachePath) {
        $cachedPath = $payloadCacheDirectory . '/' . $key . '.json';
        if (!rename($temporaryCachePath, $cachedPath)) {
            @unlink($temporaryCachePath);
            throw new RuntimeException("Unable to promote the payload cache for {$key}.");
        }
    }
    $manifestTemporaryPath = $payloadCacheManifestPath . '.new.' . getmypid();
    $manifestPayload = json_encode([
        'version' => 1,
        'provider_id' => $providerId,
        'payload_fingerprint' => $payloadFingerprint,
        'entries' => $cacheEntries,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($manifestTemporaryPath, $manifestPayload, LOCK_EX) === false || !rename($manifestTemporaryPath, $payloadCacheManifestPath)) {
        @unlink($manifestTemporaryPath);
        throw new RuntimeException('Unable to promote the IPTV payload cache manifest.');
    }
    chmod($payloadCacheManifestPath, 0640);
    @chgrp($payloadCacheManifestPath, 'www-data');
    $cachePromotionSeconds = (hrtime(true) - $cachePromotionStarted) / 1e9;
}

$traceStarted = hrtime(true);
$trace = @file_get_contents('https://www.cloudflare.com/cdn-cgi/trace');
$networkStatus = [];
if (is_string($trace)) {
    foreach (preg_split('/\R/', $trace) ?: [] as $line) {
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $networkStatus[$key] = trim($value);
        }
    }
}
$traceSeconds = (hrtime(true) - $traceStarted) / 1e9;
$expiration = (string) ($payloads['account']['user_info']['exp_date'] ?? '');
$accountStatus = (string) ($payloads['account']['user_info']['status'] ?? '');
$counts = ['live' => 0, 'vod' => 0, 'series' => 0];
$categoryCounts = ['live' => 0, 'vod' => 0, 'series' => 0];
$integrity = 'ok';
$journalMode = 'delete';
$catalogCacheHit = false;
$catalogValidationStarted = hrtime(true);
if (is_file($databasePath)) {
    try {
        $existingCatalog = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
        $storedFingerprint = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='payload_fingerprint'");
        $storedSchemaVersion = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='schema_version'");
        $storedRegionClassifierVersion = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='region_classifier_version'");
        $storedIntegrity = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='integrity_check'");
        if (is_string($storedFingerprint) && hash_equals($payloadFingerprint, $storedFingerprint)
            && (string) $storedSchemaVersion === $catalogSchemaVersion
            && (string) $storedRegionClassifierVersion === '3'
            && (string) $storedIntegrity === 'ok') {
            foreach (array_keys($counts) as $kind) {
                $value = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='" . $kind . "_count'");
                $counts[$kind] = max(0, (int) $value);
                $categoryValue = $existingCatalog->querySingle("SELECT value FROM index_metadata WHERE key='" . $kind . "_category_count'");
                $categoryCounts[$kind] = max(0, (int) $categoryValue);
            }
            $integrity = 'ok';
            $journalMode = (string) $existingCatalog->querySingle('PRAGMA journal_mode');
            $catalogCacheHit = true;
        }
        $existingCatalog->close();
    } catch (Throwable $error) {
        $catalogCacheHit = false;
    }
}
$catalogValidationSeconds = (hrtime(true) - $catalogValidationStarted) / 1e9;
$reportProgress($catalogCacheHit ? 'Catalog unchanged; reusing local SQLite index…' : 'Preparing local catalog index…', 7);
$postValidationStarted = hrtime(true);

$directory = $providerDirectory;
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create the IPTV data directory.');
}
// The indexer is intentionally invoked through the narrowly scoped sudo
// rule, so newly created provider directories belong to root.  PHP/Lighttpd
// still needs to traverse and read the active catalog as www-data.
if (!chmod($directory, 0750) || !@chgrp($directory, 'www-data')) {
    throw new RuntimeException('Unable to grant the APtv web process access to the provider data directory.');
}
$posterCache = '/var/cache/openap-iptv/posters';
if (!is_dir($posterCache)) {
    mkdir($posterCache, 02770, true);
    chmod(dirname($posterCache), 02770); @chgrp(dirname($posterCache), 'www-data');
    chmod($posterCache, 02770); @chgrp($posterCache, 'www-data');
}
$vodDetailsCache = '/var/cache/openap-iptv/vod-details';
if (!is_dir($vodDetailsCache)) {
    mkdir($vodDetailsCache, 02770, true);
    chmod($vodDetailsCache, 02770); @chgrp($vodDetailsCache, 'www-data');
}
foreach (['/var/cache/openap-iptv/series-posters', '/var/cache/openap-iptv/series-details'] as $seriesCache) {
    if (!is_dir($seriesCache)) {
        mkdir($seriesCache, 02770, true);
        chmod($seriesCache, 02770); @chgrp($seriesCache, 'www-data');
    }
}
$preferencesDirectory = $directory . '/preferences';
if (!is_dir($preferencesDirectory)) {
    // Lighttpd runs with RestrictSUIDSGID on Debian 13.  Requesting the
    // setgid bit explicitly (02770) makes mkdir/chmod fail inside that
    // sandbox.  The parent provider directory already supplies the required
    // www-data group; a plain group-writable directory is sufficient here.
    mkdir($preferencesDirectory, 0770, true);
    chmod($preferencesDirectory, 0770);
    @chgrp($preferencesDirectory, 'www-data');
}
$preferencesPath = $preferencesDirectory . '/catalog-order.db';
if (!is_file($preferencesPath)) {
    $preferences = new SQLite3($preferencesPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $preferences->exec('PRAGMA journal_mode=DELETE');
    $preferences->exec('CREATE TABLE catalog_order (scope TEXT NOT NULL, parent_id TEXT NOT NULL, item_id TEXT NOT NULL, position INTEGER NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(scope,parent_id,item_id)) STRICT');
    $preferences->exec('CREATE INDEX catalog_order_position_idx ON catalog_order(scope,parent_id,position)');
    $preferences->close();
    chmod($preferencesPath, 0660);
    @chgrp($preferencesPath, 'www-data');
}
if (!$catalogCacheHit) {
$temporaryPath = $databasePath . '.new.' . getmypid();
$db = new SQLite3($temporaryPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=DELETE');
// This database is built in a temporary path and promoted only after the
// integrity check. NORMAL avoids an fsync for every journal boundary while
// retaining rollback protection during the single bulk transaction.
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE categories (kind TEXT NOT NULL, category_id TEXT NOT NULL, name TEXT NOT NULL, parent_id TEXT, region_code TEXT NOT NULL, region_source TEXT NOT NULL, PRIMARY KEY(kind, category_id)) STRICT');
$db->exec('CREATE TABLE items (kind TEXT NOT NULL, item_id TEXT NOT NULL, category_id TEXT, name TEXT NOT NULL, icon_url TEXT, epg_channel_id TEXT, container_extension TEXT, added TEXT, rating REAL, PRIMARY KEY(kind, item_id)) STRICT');
$db->exec('CREATE TABLE item_region_overrides (kind TEXT NOT NULL, item_id TEXT NOT NULL, region_code TEXT NOT NULL, source TEXT NOT NULL, PRIMARY KEY(kind, item_id)) STRICT');
$db->exec("CREATE VIEW item_regions AS SELECT i.kind, i.item_id, COALESCE(o.region_code, c.region_code, 'OTHER') AS region_code, COALESCE(o.source, c.region_source, 'fallback') AS source FROM items i LEFT JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id LEFT JOIN item_region_overrides o ON o.kind=i.kind AND o.item_id=i.item_id");
$db->exec('CREATE TABLE nav_regions (kind TEXT NOT NULL, region TEXT NOT NULL, item_count INTEGER NOT NULL, category_count INTEGER NOT NULL, PRIMARY KEY(kind, region)) STRICT');
$db->exec('CREATE TABLE nav_categories (kind TEXT NOT NULL, region TEXT NOT NULL, category_id TEXT NOT NULL, name TEXT NOT NULL, item_count INTEGER NOT NULL, PRIMARY KEY(kind, region, category_id)) STRICT');
$db->exec("CREATE VIRTUAL TABLE catalog_fts USING fts5(kind UNINDEXED, item_id UNINDEXED, name, category_name, tokenize='unicode61 remove_diacritics 2')");
$db->exec('CREATE TABLE index_metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL) STRICT');

$categoryNames = [];
$categoryRegions = [];
$categoryInsert = $db->prepare('INSERT OR REPLACE INTO categories(kind, category_id, name, parent_id, region_code, region_source) VALUES (:kind, :id, :name, :parent, :region, :source)');
$itemInsert = $db->prepare('INSERT OR REPLACE INTO items(kind, item_id, category_id, name, icon_url, epg_channel_id, container_extension, added, rating) VALUES (:kind, :id, :category, :name, :icon, :epg, :extension, :added, :rating)');
$regionOverrideInsert = $db->prepare('INSERT OR REPLACE INTO item_region_overrides(kind, item_id, region_code, source) VALUES (:kind, :id, :region, :source)');
$ftsInsert = $db->prepare('INSERT INTO catalog_fts(kind, item_id, name, category_name) VALUES (:kind, :id, :name, :category_name)');
$db->exec('BEGIN IMMEDIATE');

foreach (['live', 'vod', 'series'] as $kind) {
    foreach ($payloads[$kind . '_categories'] as $category) {
        $id = (string) ($category['category_id'] ?? '');
        $name = trim((string) ($category['category_name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        [$categoryRegion, $categorySource] = openapIptvFastCategoryRegionResult($name);
        $categoryInsert->bindValue(':kind', $kind, SQLITE3_TEXT);
        $categoryInsert->bindValue(':id', $id, SQLITE3_TEXT);
        $categoryInsert->bindValue(':name', $name, SQLITE3_TEXT);
        $categoryInsert->bindValue(':parent', isset($category['parent_id']) ? (string) $category['parent_id'] : null, SQLITE3_TEXT);
        $categoryInsert->bindValue(':region', $categoryRegion, SQLITE3_TEXT);
        $categoryInsert->bindValue(':source', $categorySource, SQLITE3_TEXT);
        $categoryInsert->execute();
        $categoryNames[$kind][$id] = $name;
        $categoryRegions[$kind][$id] = [$categoryRegion, $categorySource];
        $categoryCounts[$kind]++;
    }
}

$reportProgress('Building the local search index…', 7);
$indexStep = 7;
foreach (['live', 'vod', 'series'] as $kind) {
    foreach ($payloads[$kind] as $item) {
        $idKey = $kind === 'series' ? 'series_id' : 'stream_id';
        $id = (string) ($item[$idKey] ?? '');
        $name = trim((string) ($item['name'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $categoryId = isset($item['category_id']) ? (string) $item['category_id'] : null;
        $icon = $kind === 'series' ? ($item['cover'] ?? '') : ($item['stream_icon'] ?? '');
        $itemInsert->bindValue(':kind', $kind, SQLITE3_TEXT);
        $itemInsert->bindValue(':id', $id, SQLITE3_TEXT);
        $itemInsert->bindValue(':category', $categoryId, SQLITE3_TEXT);
        $itemInsert->bindValue(':name', $name, SQLITE3_TEXT);
        $itemInsert->bindValue(':icon', (string) $icon, SQLITE3_TEXT);
        $itemInsert->bindValue(':epg', (string) ($item['epg_channel_id'] ?? ''), SQLITE3_TEXT);
        $itemInsert->bindValue(':extension', (string) ($item['container_extension'] ?? ''), SQLITE3_TEXT);
        $itemInsert->bindValue(':added', (string) ($item['added'] ?? ''), SQLITE3_TEXT);
        $rating = $item['rating_5based'] ?? $item['rating'] ?? null;
        $itemInsert->bindValue(':rating', is_numeric($rating) ? (float) $rating : null, SQLITE3_FLOAT);
        $itemInsert->execute();
        $itemRegion = openapIptvFastItemRegion($name);
        if ($itemRegion !== null) {
            $regionOverrideInsert->bindValue(':kind', $kind, SQLITE3_TEXT);
            $regionOverrideInsert->bindValue(':id', $id, SQLITE3_TEXT);
            $regionOverrideInsert->bindValue(':region', $itemRegion, SQLITE3_TEXT);
            $regionOverrideInsert->bindValue(':source', 'item-name', SQLITE3_TEXT);
            $regionOverrideInsert->execute();
        }
        $ftsInsert->bindValue(':kind', $kind, SQLITE3_TEXT);
        $ftsInsert->bindValue(':id', $id, SQLITE3_TEXT);
        $ftsInsert->bindValue(':name', $name, SQLITE3_TEXT);
        $ftsInsert->bindValue(':category_name', (string) ($categoryNames[$kind][$categoryId] ?? ''), SQLITE3_TEXT);
        $ftsInsert->execute();
        $counts[$kind]++;
        if ($counts[$kind] % 5000 === 0) $reportProgress($labels[$kind] . ': ' . $counts[$kind] . ' indexed…', $indexStep);
    }
    $reportProgress($labels[$kind] . ': ' . $counts[$kind] . ' indexed', ++$indexStep, $kind, $counts[$kind]);
}

$reportProgress('Finalizing catalog indexes…', ++$indexStep);
$db->exec('CREATE INDEX items_category_idx ON items(kind, category_id, name)');
$db->exec('CREATE INDEX items_kind_name_idx ON items(kind, name)');
$db->exec('CREATE INDEX items_name_idx ON items(name)');
$db->exec('CREATE INDEX categories_region_idx ON categories(kind, region_code, category_id)');
$db->exec('CREATE INDEX item_region_overrides_region_idx ON item_region_overrides(kind, region_code, item_id)');

// Navigation is derived once from the same semantic region view used by the
// browse endpoints.  Keeping it in the temporary catalog makes the eventual
// rename swap the catalog and its navigation state atomically.
$db->exec('INSERT INTO nav_regions(kind, region, item_count, category_count)
    SELECT i.kind, ir.region_code, COUNT(*), COUNT(DISTINCT i.category_id)
    FROM items i JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id
    GROUP BY i.kind, ir.region_code');
$db->exec('INSERT INTO nav_categories(kind, region, category_id, name, item_count)
    SELECT i.kind, ir.region_code, i.category_id, COALESCE(c.name, \'\'), COUNT(*)
    FROM items i
    JOIN item_regions ir ON ir.kind=i.kind AND ir.item_id=i.item_id
    LEFT JOIN categories c ON c.kind=i.kind AND c.category_id=i.category_id
    WHERE i.category_id IS NOT NULL
    GROUP BY i.kind, ir.region_code, i.category_id, c.name');
$db->exec('CREATE INDEX nav_categories_region_idx ON nav_categories(kind, region, name, category_id)');
$navFingerprint = openapIptvNavFingerprint($db);

$metadataInsert = $db->prepare('INSERT INTO index_metadata(key, value) VALUES (:key, :value)');
foreach ([
    'schema_version' => $catalogSchemaVersion,
    'region_classifier_version' => '3',
    'integrity_check' => 'ok',
    'payload_fingerprint' => $payloadFingerprint,
    'nav_fingerprint' => $navFingerprint,
    'indexed_at_utc' => gmdate('c'),
    'live_count' => (string) $counts['live'],
    'vod_count' => (string) $counts['vod'],
    'series_count' => (string) $counts['series'],
    'live_category_count' => (string) $categoryCounts['live'],
    'vod_category_count' => (string) $categoryCounts['vod'],
    'series_category_count' => (string) $categoryCounts['series'],
    'account_status' => $accountStatus,
    'service_expiration' => $expiration,
    'public_ip' => (string) ($networkStatus['ip'] ?? ''),
    'vpn_type' => (($networkStatus['warp'] ?? 'off') === 'on' ? 'WARP' : ''),
    'network_location' => (string) ($networkStatus['loc'] ?? ''),
] as $key => $value) {
    $metadataInsert->bindValue(':key', $key, SQLITE3_TEXT);
    $metadataInsert->bindValue(':value', $value, SQLITE3_TEXT);
    $metadataInsert->execute();
}
$db->exec('COMMIT');
$db->exec('PRAGMA optimize');
$integrity = $db->querySingle('PRAGMA integrity_check');
$db->close();
if ($integrity !== 'ok') {
    @unlink($temporaryPath);
    throw new RuntimeException('SQLite integrity check failed.');
}
chmod($temporaryPath, 0640);
@chgrp($temporaryPath, 'www-data');
if (!rename($temporaryPath, $databasePath)) {
    @unlink($temporaryPath);
    throw new RuntimeException('Unable to promote the IPTV catalog.');
}
}

// Keep the provider profile outside the webroot and encrypted at rest. The
// web process may decrypt it only to proxy an authenticated APtv stream.
$keyPath = $storageDirectory . '/provider.key';
$profilePath = $storageDirectory . '/provider.enc';
if (!extension_loaded('sodium')) {
    throw new RuntimeException('Sodium is required to protect the APtv provider profile.');
}
if (!is_file($keyPath)) {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    if (file_put_contents($keyPath, $key, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create the APtv provider key.');
    }
    chmod($keyPath, 0640);
    @chgrp($keyPath, 'www-data');
} else {
    $key = file_get_contents($keyPath);
}
if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
    throw new RuntimeException('Invalid APtv provider key.');
}
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$profileData = ['id'=>$providerId,'name'=>$providerName,'url'=>$baseUrl,'username'=>$username,'password'=>$password,'api_version'=>$apiVersion];
$plainProfile = json_encode($profileData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$sealedProfile = json_encode(['version' => 1, 'nonce' => base64_encode($nonce), 'ciphertext' => base64_encode(sodium_crypto_secretbox($plainProfile, $nonce, $key))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$profileTemporaryPath = $profilePath . '.new.' . getmypid();
if (file_put_contents($profileTemporaryPath, $sealedProfile, LOCK_EX) === false) {
    throw new RuntimeException('Unable to save the encrypted APtv provider profile.');
}
chmod($profileTemporaryPath, 0640);
@chgrp($profileTemporaryPath, 'www-data');
if (!rename($profileTemporaryPath, $profilePath)) {
    @unlink($profileTemporaryPath);
    throw new RuntimeException('Unable to promote the encrypted APtv provider profile.');
}
$collectionPath = $storageDirectory . '/providers.enc';
$collection = ['version'=>1,'active_id'=>$providerId,'profiles'=>[]];
if (is_file($collectionPath)) {
    $existingSealed = json_decode((string)file_get_contents($collectionPath), true);
    $existingNonce = is_array($existingSealed) ? base64_decode((string)($existingSealed['nonce']??''), true) : false;
    $existingCiphertext = is_array($existingSealed) ? base64_decode((string)($existingSealed['ciphertext']??''), true) : false;
    if (is_string($existingNonce) && is_string($existingCiphertext)) {
        $existingPlain = sodium_crypto_secretbox_open($existingCiphertext, $existingNonce, $key);
        $decoded = is_string($existingPlain) ? json_decode($existingPlain, true) : null;
        if (is_string($existingPlain)) sodium_memzero($existingPlain);
        if (is_array($decoded)) $collection = $decoded;
    }
}
$profiles = []; $replaced = false;
foreach (($collection['profiles']??[]) as $savedProfile) {
    if (!is_array($savedProfile)) continue;
    if (hash_equals((string)($savedProfile['id']??''), $providerId)) { $profiles[]=$profileData; $replaced=true; }
    else $profiles[]=$savedProfile;
}
if (!$replaced) $profiles[]=$profileData;
$collection['version']=1; $collection['active_id']=$providerId; $collection['profiles']=$profiles;
$collectionNonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$collectionPlain=json_encode($collection,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$collectionSealed=json_encode(['version'=>1,'nonce'=>base64_encode($collectionNonce),'ciphertext'=>base64_encode(sodium_crypto_secretbox($collectionPlain,$collectionNonce,$key))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$collectionTemporaryPath=$collectionPath.'.new.'.getmypid();
if(file_put_contents($collectionTemporaryPath,$collectionSealed,LOCK_EX)===false){throw new RuntimeException('Unable to save provider collection.');}
chmod($collectionTemporaryPath,0640);@chgrp($collectionTemporaryPath,'www-data');
if(!rename($collectionTemporaryPath,$collectionPath)){@unlink($collectionTemporaryPath);throw new RuntimeException('Unable to promote provider collection.');}
sodium_memzero($collectionPlain);
sodium_memzero($plainProfile);
sodium_memzero($password);
openapIptvPointActiveProvider($storageDirectory, $providerId);
$final = new SQLite3($databasePath);
$journalMode = $final->querySingle('PRAGMA journal_mode=DELETE');
$final->close();
$postValidationSeconds = (hrtime(true) - $postValidationStarted) / 1e9;
$totalSeconds = (hrtime(true) - $started) / 1e9;
$indexSeconds = max(0, $totalSeconds - $downloadSeconds);

echo json_encode([
    'download_seconds' => round($downloadSeconds, 3),
    'network_seconds' => round($networkSeconds, 3),
    'payload_processing_seconds' => round($payloadProcessingSeconds, 3),
    'cache_write_seconds' => round($cacheWriteSeconds, 3),
    'semantic_seconds' => round($semanticSeconds, 3),
    'cache_promotion_seconds' => round($cachePromotionSeconds, 3),
    'trace_seconds' => round($traceSeconds, 3),
    'catalog_validation_seconds' => round($catalogValidationSeconds, 3),
    'post_validation_seconds' => round($postValidationSeconds, 3),
    'index_seconds' => round($indexSeconds, 3),
    'total_seconds' => round($totalSeconds, 3),
    'download_bytes' => $downloadBytes,
    'cache_hit' => $catalogCacheHit,
    'conditional_cache_hits' => $cacheResponseHits,
    'counts' => $counts,
    'categories' => [
        'live' => $categoryCounts['live'],
        'vod' => $categoryCounts['vod'],
        'series' => $categoryCounts['series'],
    ],
    'database_bytes' => filesize($databasePath),
    'journal_mode' => $journalMode,
    'integrity' => $integrity,
    'service' => [
        'expiration' => $expiration,
        'public_ip' => (string) ($networkStatus['ip'] ?? ''),
        'vpn' => (($networkStatus['warp'] ?? 'off') === 'on' ? 'WARP' : ''),
        'location' => (string) ($networkStatus['loc'] ?? ''),
    ],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
