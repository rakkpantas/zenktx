<?php
// admin/content_api.php - returns paginated admin content HTML for dashboard (movies/tv)
session_start();
header('Content-Type: application/json; charset=utf-8');

// Auth
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/content_fragment.php';

// Load movies from JSON
function loadMoviesApi() {
    $path = __DIR__ . '/../movies.json';
    if (file_exists($path)) {
        $json = file_get_contents($path);
        return json_decode($json, true) ?: [];
    }
    return [];
}

$section = strtolower(trim($_GET['section'] ?? 'movie')); // movie | tv
$q       = strtolower(trim($_GET['q'] ?? ''));
$offset  = max(0, (int)($_GET['offset'] ?? 0));
$limit   = max(1, min(50, (int)($_GET['limit'] ?? 10)));

$movies = loadMoviesApi();

// Normalize and filter by type
$items = [];
foreach ($movies as $m) {
    $t = strtolower($m['type'] ?? 'movie');
    $norm = (in_array($t, ['tv','tv series','series'])) ? 'tv' : 'movie';
    if ($section !== $norm) continue;

    if ($q !== '') {
        $hay = strtolower(($m['title'] ?? '').' '.($m['year'] ?? '').' '.($m['imdb_id'] ?? ''));
        if (strpos($hay, $q) === false) continue;
    }
    $items[] = $m;
}


// Make newest items appear first in admin list (last item in JSON becomes first)
// This applies to both Movies and TV Series.
$items = array_reverse($items);

$totalMatched = count($items);

// Slice for paging
$slice = array_slice($items, $offset, $limit);

// Render HTML
ob_start();
foreach ($slice as $m) {
    zp_admin_render_movie_item($m);
}
$html = ob_get_clean();

$nextOffset = $offset + count($slice);
$hasMore = $nextOffset < $totalMatched;

echo json_encode([
    'ok' => true,
    'section' => $section,
    'q' => $q,
    'offset' => $offset,
    'limit' => $limit,
    'returned' => count($slice),
    'totalMatched' => $totalMatched,
    'nextOffset' => $nextOffset,
    'hasMore' => $hasMore,
    'html' => $html
]);
