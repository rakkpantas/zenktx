<?php
// load_more.php - infinite scroll endpoint (movies + tv)
// Supports: genre, search query, actor
// Optimized: streaming filter so actor/genre don't scan the full catalog per request.
session_start();

$OMDB_API_KEY = 'a689013';

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

$wantType = ($type === 'tv') ? 'tv' : 'movie';
$qLower   = ($q !== '') ? strtolower($q) : '';
$gLower   = ($genre !== '') ? strtolower($genre) : '';
$aLower   = ($actor !== '') ? strtolower($actor) : '';

$all = array_reverse(array_values(loadMovies()));

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
    $payload = array_map(function($item) use ($type) {
        return [
            'imdb_id' => (string)($item['imdb_id'] ?? ''),
            'title'   => (string)($item['title'] ?? ''),
            'year'    => (string)($item['year'] ?? ''),
            'poster'  => (string)($item['poster'] ?? ''),
            'rating'  => (string)($item['rating'] ?? ''),
            'label'   => ($type === 'tv') ? 'TV SHOW' : 'MOVIE',
        ];
    }, $chunk);

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
        <img loading="lazy" src="<?= htmlspecialchars($item['poster']) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="movie-poster">
        <span class="movie-label"><?= ($type === 'tv') ? 'TV SHOW' : 'MOVIE' ?></span>
        <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($item['rating']) ?></span>
    </div>
    <div class="movie-info">
        <h3 class="movie-title"><?= htmlspecialchars($item['title']) ?></h3>
        <p class="movie-year"><?= htmlspecialchars($item['year']) ?></p>
    </div>
</div>
<?php endforeach; ?>
