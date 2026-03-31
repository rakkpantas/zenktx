<?php
// lib/imdb_platform.php
// Auto-detect a movie/TV platform by checking membership in configured IMDb lists.

function zp_root_path(): string {
    return dirname(__DIR__);
}

function zp_platform_lists_path(): string {
    return zp_root_path() . '/platform_lists.json';
}

function zp_cache_dir(): string {
    $dir = zp_root_path() . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function zp_load_platform_lists(): array {
    $path = zp_platform_lists_path();
    if (!file_exists($path)) return [];
    $data = json_decode(@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function zp_save_platform_lists(array $cfg): bool {
    $path = zp_platform_lists_path();
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return (bool)@file_put_contents($path, $json);
}

function zp_extract_imdb_list_id(string $urlOrId): string {
    $s = trim($urlOrId);
    if (preg_match('/\b(ls\d+)\b/i', $s, $m)) return strtolower($m[1]);
    return '';
}

function zp_set_query_param(string $url, string $key, string $value): string {
    $parts = parse_url($url);
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $query[$key] = $value;
    $newQuery = http_build_query($query);

    $scheme   = $parts['scheme'] ?? 'https';
    $host     = $parts['host'] ?? 'www.imdb.com';
    $path     = $parts['path'] ?? '/';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . '://' . $host . $path . ($newQuery ? '?' . $newQuery : '') . $fragment;
}

function zp_http_get(string $url, int $timeoutSeconds = 18): array {
    // Returns [ok=>bool, status=>int, body=>string]
    $ch = curl_init($url);
    if (!$ch) return ['ok' => false, 'status' => 0, 'body' => ''];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9'
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'ok' => is_string($body) && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($body) ? $body : ''
    ];
}

function zp_parse_imdb_list_title(string $html): string {
    if (preg_match('/<title>\s*(.*?)\s*<\/title>/is', $html, $m)) {
        $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $t = preg_replace('/\s*-\s*IMDb\s*$/i', '', $t);
        return trim($t);
    }
    return '';
}

function zp_parse_imdb_title_ids(string $html): array {
    $ids = [];
    if (preg_match_all('/data-tconst\s*=\s*"(tt\d{7,8})"/i', $html, $m)) {
        foreach ($m[1] as $id) $ids[strtolower($id)] = true;
    }
    if (preg_match_all('/\/title\/(tt\d{7,8})\//i', $html, $m2)) {
        foreach ($m2[1] as $id) $ids[strtolower($id)] = true;
    }
    return array_keys($ids);
}

function zp_fetch_imdb_list_ids(string $listUrlOrId, int $maxPages = 30, int $cacheTtlSeconds = 21600): array {
    // Returns [ok=>bool, list_id=>string, title=>string, ids=>array, platform_guess=>string, source_url=>string, error=>string]
    $listId = zp_extract_imdb_list_id($listUrlOrId);
    if (!$listId) {
        return ['ok'=>false,'list_id'=>'','title'=>'','ids'=>[],'platform_guess'=>'','source_url'=>'','error'=>'invalid_list_id'];
    }

    $cacheFile = zp_cache_dir() . '/imdb_list_' . $listId . '.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        $updated = (int)($cached['updated_at'] ?? 0);
        if (is_array($cached) && $updated > 0 && (time() - $updated) < $cacheTtlSeconds) {
            $cached['ok'] = true;
            return $cached;
        }
    }

    $baseUrl = trim($listUrlOrId);
    if (!preg_match('#^https?://#i', $baseUrl)) {
        $baseUrl = 'https://www.imdb.com/list/' . $listId . '/';
    }

    // Ensure detail view so IDs are present
    $baseUrl = zp_set_query_param($baseUrl, 'view', 'detail');

    $all = [];
    $title = '';
    $sourceUrl = '';
    $emptyStreak = 0;

    for ($page = 1; $page <= $maxPages; $page++) {
        $url = zp_set_query_param($baseUrl, 'page', (string)$page);
        $resp = zp_http_get($url);

        if (!$resp['ok']) {
            if (!empty($all)) break;
            return ['ok'=>false,'list_id'=>$listId,'title'=>'','ids'=>[],'platform_guess'=>'','source_url'=>$url,'error'=>'fetch_failed_' . ($resp['status'] ?? 0)];
        }

        $sourceUrl = $url;
        if (!$title) $title = zp_parse_imdb_list_title($resp['body']);

        $ids = zp_parse_imdb_title_ids($resp['body']);
        $before = count($all);
        foreach ($ids as $id) $all[$id] = true;
        $after = count($all);

        if ($after === $before) $emptyStreak++; else $emptyStreak = 0;
        if ($emptyStreak >= 2) break;
    }

    $idsOut = array_keys($all);
    sort($idsOut);

    $platformGuess = $title ? zp_guess_platform_from_title($title) : '';

    $payload = [
        'list_id' => $listId,
        'title' => $title,
        'platform_guess' => $platformGuess,
        'updated_at' => time(),
        'source_url' => $sourceUrl,
        'ids' => $idsOut
    ];
    @file_put_contents($cacheFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $payload['ok'] = true;
    return $payload;
}

function zp_guess_platform_from_title(string $title): string {
    $t = strtolower($title);
    $map = [
        'vivamax' => ['vivamax'],
        'netflix' => ['netflix'],
        'warnerbros' => ['warner bros', 'warnerbros', 'warner'],
        'hulu' => ['hulu'],
        'primevideo' => ['prime video', 'amazon prime', 'primevideo'],
        'disneyplus' => ['disney+', 'disney plus', 'disneyplus'],
        'hbomax' => ['hbo max', 'hbomax', 'hbo'],
        'appletvplus' => ['apple tv+', 'apple tv plus', 'appletvplus', 'apple tv'],
    ];
    foreach ($map as $plat => $needles) {
        foreach ($needles as $n) {
            if (str_contains($t, $n)) return $plat;
        }
    }
    return '';
}

function zp_detect_platform_for_imdb_id(string $imdbId, array $cfg = null): string {
    $imdbId = strtolower(trim($imdbId));
    if (!$imdbId) return '';
    if ($cfg === null) $cfg = zp_load_platform_lists();

    foreach ($cfg as $platform => $info) {
        $platform = strtolower((string)$platform);
        if (!is_array($info)) continue;

        $urls = [];
        if (!empty($info['list_url']) && is_string($info['list_url'])) $urls[] = $info['list_url'];
        if (!empty($info['list_urls']) && is_array($info['list_urls'])) {
            foreach ($info['list_urls'] as $u) if (is_string($u) && trim($u) !== '') $urls[] = $u;
        }
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        if (empty($urls)) continue;

        foreach ($urls as $u) {
            $list = zp_fetch_imdb_list_ids($u);
            if (!($list['ok'] ?? false)) continue;
            $set = array_flip($list['ids'] ?? []);
            if (isset($set[$imdbId])) return $platform;
        }
    }

    return '';
}
