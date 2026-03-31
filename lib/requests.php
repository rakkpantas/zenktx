<?php
// lib/requests.php - Request system helpers (JSON storage + IMDb check)

function requests_path() {
    return __DIR__ . '/../requests.json';
}

function normalize_request_key($title, $year) {
    $t = mb_strtolower(trim($title));
    $t = preg_replace('/\s+/', ' ', $t);
    $y = preg_replace('/[^0-9]/', '', (string)$year);
    return $t . '|' . $y;
}

function loadRequests() {
    $path = requests_path();
    if (!file_exists($path)) {
        return [];
    }
    $json = @file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) return [];
    return $data;
}

function saveRequests($requests) {
    $path = requests_path();
    @file_put_contents($path, json_encode(array_values($requests), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function cleanupRequests(&$requests) {
    $now = time();
    $requests = array_values(array_filter($requests, function($r) use ($now) {
        $created = isset($r['created_at']) ? (int)$r['created_at'] : 0;
        // Auto-delete after 24 hours
        return ($created > 0) && (($now - $created) <= 86400);
    }));
}

/**
 * Check if title+year exists on IMDb (best-effort).
 * Returns array: ['found' => bool, 'source' => 'omdb'|'imdb_suggest'|'none', 'item' => [...optional...]]
 */
function imdbExists($title, $year) {
    $title = trim((string)$title);
    $year = trim((string)$year);

    // 1) OMDb (recommended) if API key is configured
    $omdbKey = getenv('OMDB_API_KEY');
    if ($omdbKey) {
        $url = 'https://www.omdbapi.com/?apikey=' . urlencode($omdbKey) . '&t=' . urlencode($title) . '&y=' . urlencode($year);
        $resp = http_get_json($url);
        if (is_array($resp) && isset($resp['Response']) && $resp['Response'] === 'True') {
            return [
                'found' => true,
                'source' => 'omdb',
                'item' => [
                    'imdb_id' => $resp['imdbID'] ?? null,
                    'title' => $resp['Title'] ?? $title,
                    'year'  => $resp['Year'] ?? $year,
                    'type'  => $resp['Type'] ?? null,
                    'poster'=> $resp['Poster'] ?? null,
                    'rating'=> $resp['imdbRating'] ?? null,
                ]
            ];
        }
    }

    // 2) IMDb suggestion API (no key, best-effort)
    // Endpoint pattern: https://v2.sg.media-imdb.com/suggestion/<first-letter>/<query>.json
    $q = preg_replace('/[^a-z0-9]/i', '', mb_strtolower($title));
    $first = $q ? $q[0] : 'a';
    $url = 'https://v2.sg.media-imdb.com/suggestion/' . rawurlencode($first) . '/' . rawurlencode($q) . '.json';
    $resp = http_get_json($url);
    if ($resp === null) {
        return ['found' => false, 'source' => 'imdb_error'];
    }
    if (is_array($resp) && isset($resp['d']) && is_array($resp['d'])) {
        foreach ($resp['d'] as $item) {
            $iy = $item['y'] ?? null;
            if ($iy && (string)$iy === (string)$year) {
                return [
                    'found' => true,
                    'source' => 'imdb_suggest',
                    'item' => [
                        'imdb_id' => $item['id'] ?? null,
                        'title' => $item['l'] ?? $title,
                        'year'  => $iy,
                        'type'  => $item['q'] ?? null,
                        'poster'=> isset($item['i'][0]) ? $item['i'][0] : null,
                    ]
                ];
            }
        }
    }

    return ['found' => false, 'source' => 'none'];
}

function http_get_json($url) {
    // Prefer cURL
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FlixMo/1.0 (+request-check)');
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw !== false && $code >= 200 && $code < 300) {
            $data = json_decode($raw, true);
            return $data;
        }
        return null;
    }

    // Fallback: file_get_contents
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: FlixMo/1.0 (+request-check)\r\n"
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    return json_decode($raw, true);
}
?>
