<?php
// load_more.php - infinite scroll endpoint (movies + tv)
// Supports: genre, search query, actor
// Optimized: streaming filter so actor/genre don't scan the full catalog per request.
session_start();

$OMDB_API_KEY = 'a689013';

// Ads (managed from admin/ads_settings.php)
function zp_load_ads_settings() {
    $path = __DIR__ . '/config/ads.json';
    $defaults = [
        'home_after_viewall_movies' => '',
        'home_after_viewall_tv'     => '',
        'watch_after_related'       => '',
        'movie_card_ad_1'           => '',
        'movie_card_ad_2'           => '',
        
    ];
    if (!file_exists($path)) {
        @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $defaults;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = [];
    return array_merge($defaults, $data);
}

function zp_pick_movie_card_ad($ads) {
    $pool = [];
    foreach (['movie_card_ad_1','movie_card_ad_2'] as $k) {
        $v = trim((string)($ads[$k] ?? ''));
        if ($v !== '') $pool[] = $v;
    }
    if (empty($pool)) return '';
    return $pool[array_rand($pool)];
}

$ZP_ADS = zp_load_ads_settings();

// Poster fallback (prevents broken-image icons / "undefined")
function zp_poster_or_placeholder($poster) {
    $p = trim((string)$poster);
    if ($p === '' || strtoupper($p) === 'N/A') {
        // Inline SVG placeholder so we don't depend on extra files
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="600" viewBox="0 0 400 600">'
             . '<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0" stop-color="#1b1b2b"/><stop offset="1" stop-color="#0d0d16"/>'
             . '</linearGradient></defs>'
             . '<rect width="400" height="600" fill="url(#g)"/>'
             . '<text x="200" y="310" text-anchor="middle" font-size="20" fill="#9aa0a6" font-family="Arial, sans-serif">No Poster</text>'
             . '</svg>';
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode($svg);
    }
    return $p;
}

// Fast-path cache for the common case: no filters.
// Builds a prepared, deduped list per type and slices by offset.
function zp_load_prepared_list($wantType) {
    $src = __DIR__ . '/movies.json';
    if (!file_exists($src)) return [];
    $srcMtime = filemtime($src) ?: 0;

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
    $cacheFile = $cacheDir . '/prepared_' . ($wantType === 'tv' ? 'tv' : 'movie') . '.json';

    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $data = $raw ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['mtime']) && (int)$data['mtime'] === (int)$srcMtime && isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }
    }

    // Build prepared list (dedupe + exclude vivamax)
    $json = @file_get_contents($src);
    $all = $json ? (json_decode($json, true) ?: []) : [];
    $all = array_reverse(array_values($all));
    $out = [];
    $seen = [];
    foreach ($all as $m) {
        if (($m['type'] ?? '') !== $wantType) continue;
        $platform = strtolower($m['platform'] ?? 'all');
        if ($platform === 'vivamax') continue;

        $id = trim((string)($m['imdb_id'] ?? ''));
        if ($id !== '') {
            $key = 'id:' . strtolower($id);
        } else {
            $t = strtolower(trim((string)($m['title'] ?? '')));
            $y = strtolower(trim((string)($m['year'] ?? '')));
            $ty = strtolower(trim((string)($m['type'] ?? '')));
            $key = 'k:' . $ty . '|' . $t . '|' . $y;
        }
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $out[] = $m;
    }

    @file_put_contents($cacheFile, json_encode(['mtime' => $srcMtime, 'items' => $out], JSON_UNESCAPED_SLASHES));
    return $out;
}

function loadMovies() {
    if (file_exists('movies.json')) {
        $json = file_get_contents('movies.json');
        return json_decode($json, true) ?: [];
    }
    return [];
}

function fetchOmdbDetails($imdbId) {
    $apiKey = $GLOBALS['OMDB_API_KEY'] ?? '';
    if (!$apiKey) return null;

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }

    $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $imdbId);
    $cacheFile = $cacheDir . '/omdb_' . $safeId . '.json';
    $cacheTTL = 60 * 60 * 24 * 7; // 7 days

    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < $cacheTTL) {
            $cached = @file_get_contents($cacheFile);
            if ($cached) {
                $data = json_decode($cached, true);
                if ($data && isset($data['Response']) && $data['Response'] === 'True') return $data;
            }
        }
    }

    $url = "https://www.omdbapi.com/?apikey={$apiKey}&i=" . urlencode($imdbId) . "&plot=short&r=json";
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: Flixmo\r\n"
        ]
    ]);

    $json = @file_get_contents($url, false, $context);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!$data || !isset($data['Response']) || $data['Response'] !== 'True') return null;

    @file_put_contents($cacheFile, $json);
    return $data;
}

// Deduplicate media items by imdb_id (preferred) or by (type|title|year)
function dedupeMediaList($list) {
    $seen = [];
    $out = [];
    foreach ($list as $m) {
        $id = trim((string)($m['imdb_id'] ?? ''));
        if ($id !== '') {
            $key = 'id:' . strtolower($id);
        } else {
            $t = strtolower(trim((string)($m['title'] ?? '')));
            $y = strtolower(trim((string)($m['year'] ?? '')));
            $ty = strtolower(trim((string)($m['type'] ?? '')));
            $key = 'k:' . $ty . '|' . $t . '|' . $y;
        }
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $m;
    }
    return $out;
}


$type   = $_GET['type'] ?? 'movie'; // 'movie' or 'tv'
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit  = 10;

$genre = trim($_GET['genre'] ?? '');
$q     = trim($_GET['q'] ?? '');
$actor = trim($_GET['actor'] ?? '');
$year  = trim($_GET['year'] ?? '');

$wantType = ($type === 'tv') ? 'tv' : 'movie';
$qLower   = ($q !== '') ? strtolower($q) : '';
$gLower   = ($genre !== '') ? strtolower($genre) : '';
$aLower   = ($actor !== '') ? strtolower($actor) : '';

// Year filter (exact 4-digit)
$ySel = '';
if ($year !== '') {
    if (preg_match('/^(\d{4})$/', $year, $mm)) {
        $ySel = $mm[1];
    } elseif (preg_match('/(19\d{2}|20\d{2}|2100)/', $year, $mm)) {
        $ySel = $mm[1];
    }
}


// If no filters, use the prepared cache list (much faster on big catalogs)
$useFastPath = ($qLower === '' && $gLower === '' && $aLower === '' && $ySel === '');
$all = $useFastPath ? zp_load_prepared_list($wantType) : array_reverse(array_values(loadMovies()));

// Stream-filter and collect only what we need for this page.
$chunk = [];
$matchIndex = 0;
$hasMore = false;

// Dedupe while streaming
$seen = [];

foreach ($all as $m) {
    $platform = strtolower($m['platform'] ?? 'all');
    if (($m['type'] ?? '') !== $wantType) continue;
    if ($platform === 'vivamax') continue;

    // Dedupe: imdb_id preferred
    $id = trim((string)($m['imdb_id'] ?? ''));
    if ($id !== '') {
        $key = 'id:' . strtolower($id);
    } else {
        $t = strtolower(trim((string)($m['title'] ?? '')));
        $y = strtolower(trim((string)($m['year'] ?? '')));
        $ty = strtolower(trim((string)($m['type'] ?? '')));
        $key = 'k:' . $ty . '|' . $t . '|' . $y;
    }
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    // Search filter
    if ($qLower !== '' && strpos(strtolower($m['title'] ?? ''), $qLower) === false) continue;

    // Year filter
    if ($ySel !== '') {
        $my = trim((string)($m['year'] ?? ''));
        if (preg_match('/(19\d{2}|20\d{2}|2100)/', $my, $mm)) $my = $mm[1];
        if ($my !== $ySel) continue;
    }

    // Genre/Actor filters
    // NOTE: Prefer stored 'genre' field in movies.json to avoid slow OMDb calls on shared hosting.
    // Only use OMDb (cached/network) for ACTOR filtering, since actors aren't stored in movies.json by default.
    if ($gLower !== '') {
        $gStr = (string)($m['genre'] ?? '');
        if (!$gStr || strtoupper($gStr) === 'N/A') {
            // No local genre -> treat as no match (avoid network lag)
            continue;
        }
        $parts = array_map('trim', explode(',', strtolower($gStr)));
        if (!in_array($gLower, $parts, true)) continue;
    }

    if ($aLower !== '') {
        $d = fetchOmdbDetails($m['imdb_id'] ?? '');
        if (!is_array($d)) continue;

        $aStr = $d['Actors'] ?? '';
        if (!$aStr || strtoupper($aStr) === 'N/A') continue;
        if (strpos(strtolower($aStr), $aLower) === false) continue;
        $m['actors'] = $aStr;
    }
// This item matches all filters.
    if ($matchIndex >= $offset) {
        if (count($chunk) < $limit) {
            $chunk[] = $m;
        } else {
            $hasMore = true;
            break;
        }
    }
    $matchIndex++;
}

// Optional JSON response for faster infinite scroll rendering
// Usage: load_more.php?...&format=json
if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    // Build a mixed payload (movies + occasional ad cards). Ads do NOT count towards offset.
    $payload = [];
    $countMoviesInBatch = 0;
	    foreach ($chunk as $item) {
        $payload[] = [
            'kind'    => 'media',
            'imdb_id' => (string)($item['imdb_id'] ?? ''),
            'title'   => (string)($item['title'] ?? ''),
            'year'    => (string)($item['year'] ?? ''),
            'poster'  => zp_poster_or_placeholder($item['poster'] ?? ''),
            'rating'  => (string)($item['rating'] ?? ''),
            'label'   => ($type === 'tv') ? 'TV SHOW' : 'MOVIE',
        ];
        $countMoviesInBatch++;

	        // Insert ad cards ONLY 2 times in the grid (to avoid lag): after item #6 and #18.
	        // Use global position (offset + count within this batch) so it works across infinite scroll.
	        $globalPos = $offset + $countMoviesInBatch;
	        if (in_array($globalPos, [6, 18], true)) {
	            $adHtml = zp_pick_movie_card_ad($ZP_ADS);
	            if (trim((string)$adHtml) !== '') {
	                $payload[] = [
	                    'kind' => 'ad',
	                    'html' => (string)$adHtml
	                ];
	            }
	        }

	    }

    echo json_encode([
        'items'   => $payload,
        'offset'  => $offset,
        'limit'   => $limit,
        'hasMore' => $hasMore,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

foreach ($chunk as $item):
?>
<div class="movie-card" onclick="watchMovie('<?= htmlspecialchars($item['imdb_id']) ?>')">
    <div style="position: relative;">
        <img loading="lazy" src="<?= htmlspecialchars(zp_poster_or_placeholder($item['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($item['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
        <span class="movie-label"><?= ($type === 'tv') ? 'TV SHOW' : 'MOVIE' ?></span>
        <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($item['rating']) ?></span>
    </div>
    <div class="movie-info">
        <h3 class="movie-title"><?= htmlspecialchars($item['title']) ?></h3>
        <p class="movie-year"><?= htmlspecialchars($item['year']) ?></p>
    </div>
</div>
<?php endforeach; ?>
