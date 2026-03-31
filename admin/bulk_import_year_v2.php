<?php
// admin/bulk_import_year_v2.php - Robust bulk import by year (adds up to LIMIT new items)
session_start();

// Keep JSON stable
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) { ob_clean(); }
        http_response_code(500);
        echo json_encode([
            "ok" => false,
            "error" => "Fatal PHP error",
            "details" => $err
        ], JSON_PRETTY_PRINT);
        exit;
    }
});

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok"=>false,"error"=>"Not authorized"], JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

@set_time_limit(0);
@ini_set('max_execution_time','0');
@ignore_user_abort(true);

require_once __DIR__ . '/../lib/imdb_platform.php';

// ===== CONFIG =====
$TMDB_API_KEY = getenv('TMDB_API_KEY') ?: 'd4163a3eb4ae288e22f27d6175a8266a';
$OMDB_API_KEY = getenv('OMDB_API_KEY') ?: '';

$year   = isset($_POST['year']) ? trim((string)$_POST['year']) : '';
$mode   = isset($_POST['mode']) ? trim((string)$_POST['mode']) : 'all'; // all|movie|tv
$limit  = isset($_POST['limit']) ? max(1,(int)$_POST['limit']) : 60;
$cursor = isset($_POST['cursor']) ? max(0,(int)$_POST['cursor']) : 0;
$batch  = isset($_POST['batch']) ? max(5,(int)$_POST['batch']) : 20;
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'batch'; // reset|prepare|batch

$batch = min($batch, 20);

if (!preg_match('/^\d{4}$/', $year)) {
    echo json_encode(["ok"=>false,"error"=>"Invalid year"], JSON_PRETTY_PRINT);
    exit;
}
if (!in_array($mode, ['all','movie','tv'], true)) $mode = 'all';

// ===== Helpers =====
function httpGetJson($url, $timeout=12) {
    $ctx = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => $timeout,
            "header" => "Accept: application/json\r\nUser-Agent: Flixmo\r\n"
        ],
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false,
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function tmdbRequest($apiKey, $path, $params = []) {
    $base = "https://api.themoviedb.org/3" . $path;
    $params['api_key'] = $apiKey;
    $url = $base . "?" . http_build_query($params);
    return httpGetJson($url, 12);
}

function tmdbDiscoverIds($apiKey, $year, $type, $page) {
    if ($type === 'movie') {
        return tmdbRequest($apiKey, "/discover/movie", [
            "primary_release_year" => $year,
            "sort_by" => "popularity.desc",
            "page" => $page
        ]);
    }
    return tmdbRequest($apiKey, "/discover/tv", [
        "first_air_date_year" => $year,
        "sort_by" => "popularity.desc",
        "page" => $page
    ]);
}

function tmdbDetails($apiKey, $type, $tmdbId) {
    $path = $type === 'movie' ? "/movie/$tmdbId" : "/tv/$tmdbId";
    return tmdbRequest($apiKey, $path, []);
}

function tmdbExternalIds($apiKey, $type, $tmdbId) {
    $path = $type === 'movie' ? "/movie/$tmdbId/external_ids" : "/tv/$tmdbId/external_ids";
    return tmdbRequest($apiKey, $path, []);
}

function tmdbImg($path) {
    if (!$path) return '';
    return "https://image.tmdb.org/t/p/w500" . $path;
}

function loadMovies() {
    $path = __DIR__ . '/../movies.json';
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function saveMovies($movies) {
    $path = __DIR__ . '/../movies.json';
    $json = json_encode($movies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($path, $json);
    if ($ok === false) {
        $err = error_get_last();
        return ["ok"=>false,"path"=>$path,"error"=>($err["message"] ?? "write_failed")];
    }
    return ["ok"=>true,"bytes"=>$ok,"path"=>$path];
}

function buildExistingSets($movies) {
    $byImdb = [];
    $byTmdb = ["movie"=>[], "tv"=>[]];
    foreach ($movies as $k => $m) {
        if (!is_array($m)) continue;
        if (!empty($m['imdb_id'])) $byImdb[(string)$m['imdb_id']] = true;
        if (!empty($m['tmdb_id']) && !empty($m['type'])) {
            $t = ($m['type'] === 'tv') ? 'tv' : 'movie';
            $byTmdb[$t][(string)$m['tmdb_id']] = true;
        }
    }
    return [$byImdb, $byTmdb];
}

function nextNumericKey($movies) {
    if (!is_array($movies) || empty($movies)) return 1;
    $keys = array_keys($movies);
    $nums = array_filter($keys, 'is_numeric');
    if (empty($nums)) return count($movies) + 1;
    return (int)max($nums) + 1;
}

// ===== Candidate cache =====
$cacheDir = __DIR__ . '/cache_v2';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheFile = $cacheDir . "/candidates_{$year}_{$mode}.json";

if ($action === 'reset') {
    if (file_exists($cacheFile)) @unlink($cacheFile);
    echo json_encode(["ok"=>true,"reset"=>true], JSON_PRETTY_PRINT);
    exit;
}

$candidates = null;
if (file_exists($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $j = json_decode($raw, true);
    if (is_array($j) && !empty($j['candidates']) && is_array($j['candidates'])) {
        $candidates = $j['candidates'];
    }
}

if ($candidates === null) {
    // Build big pool once (up to 1500 combined)
    if (!$TMDB_API_KEY) {
        echo json_encode(["ok"=>false,"error"=>"TMDB_API_KEY not set"], JSON_PRETTY_PRINT);
        exit;
    }
    $targets = ($mode === 'all') ? ['movie','tv'] : [$mode];
    $out = [];
    foreach ($targets as $t) {
        for ($page=1; $page<=30; $page++) {
            $resp = tmdbDiscoverIds($TMDB_API_KEY, $year, $t, $page);
            if (!$resp || empty($resp['results'])) break;
            foreach ($resp['results'] as $item) {
                $id = $item['id'] ?? null;
                if (!$id) continue;
                $out[] = [
                    "type"=>$t,
                    "tmdb_id" => (int)$id
                ];
                if (count($out) >= 1500) break 2;
            }
        }
    }
    $candidates = $out;
    @file_put_contents($cacheFile, json_encode(["ts"=>time(),"year"=>$year,"mode"=>$mode,"candidates"=>$candidates]));
}

if ($action === 'prepare') {
    echo json_encode(["ok"=>true,"prepared"=>true,"total_candidates"=>count($candidates)], JSON_PRETTY_PRINT);
    exit;
}

// ===== Batch import =====
$movies = loadMovies();
list($existingImdb, $existingTmdb) = buildExistingSets($movies);

$added = [];
$skipped = [];
$errors = [];

$start = $cursor;
$end = min($cursor + $batch, count($candidates));

$timeStart = microtime(true);
$timeBudget = 15.0;

for ($i=$start; $i < $end; $i++) {
    if ((microtime(true) - $timeStart) > $timeBudget) break;

    $c = $candidates[$i] ?? null;
    if (!is_array($c)) { $skipped[]=["reason"=>"bad_candidate"]; continue; }

    $t = ($c['type'] === 'tv') ? 'tv' : 'movie';
    $tmdbId = (int)($c['tmdb_id'] ?? 0);
    if ($tmdbId <= 0) { $skipped[]=["reason"=>"bad_tmdb_id"]; continue; }

    if (!empty($existingTmdb[$t][(string)$tmdbId])) {
        $skipped[]=["reason"=>"duplicate_tmdb","type"=>$t,"tmdb_id"=>$tmdbId];
        continue;
    }

    $details = tmdbDetails($TMDB_API_KEY, $t, $tmdbId);
    if (!is_array($details)) {
        $skipped[]=["reason"=>"tmdb_details_failed","type"=>$t,"tmdb_id"=>$tmdbId];
        continue;
    }

    $ext = tmdbExternalIds($TMDB_API_KEY, $t, $tmdbId);
    $imdbId = is_array($ext) ? ($ext['imdb_id'] ?? '') : '';
    if (!$imdbId) {
        $skipped[]=["reason"=>"no_imdb_id","type"=>$t,"tmdb_id"=>$tmdbId];
        continue;
    }
    if (!empty($existingImdb[$imdbId])) {
        $skipped[]=["reason"=>"duplicate_imdb","type"=>$t,"imdb_id"=>$imdbId];
        continue;
    }

    // Build genres/runtime from TMDb (no OMDb needed)
    $genres = [];
    if (!empty($details['genres']) && is_array($details['genres'])) {
        foreach ($details['genres'] as $g) { if (!empty($g['name'])) $genres[] = $g['name']; }
    }
    $genreStr = !empty($genres) ? implode(', ', $genres) : 'N/A';

    $runtime = 'N/A';
    if ($t === 'movie' && !empty($details['runtime'])) $runtime = (int)$details['runtime'] . ' min';
    if ($t === 'tv' && !empty($details['episode_run_time'][0])) $runtime = (int)$details['episode_run_time'][0] . ' min';

    $title = ($t === 'movie') ? ($details['title'] ?? '') : ($details['name'] ?? '');
    if (!$title) { $skipped[]=["reason"=>"no_title","type"=>$t,"tmdb_id"=>$tmdbId]; continue; }

    $yearOut = '';
    if ($t === 'movie') $yearOut = substr((string)($details['release_date'] ?? ''), 0, 4);
    else $yearOut = substr((string)($details['first_air_date'] ?? ''), 0, 4);
    if (!$yearOut) $yearOut = $year;

    $poster = tmdbImg($details['poster_path'] ?? '');
    $rating = isset($details['vote_average']) ? round((float)$details['vote_average'], 1) : 0;

    $detPlat = zp_detect_platform_for_imdb_id($imdbId);
    if (empty($detPlat)) $detPlat = 'all';

    $newItem = [
        "title" => $title,
        "year" => (int)$yearOut,
        "type" => ($t === 'tv') ? 'tv' : 'movie',
        "imdb_id" => $imdbId,
        "tmdb_id" => $tmdbId,
        "poster" => $poster,
        "overview" => $details['overview'] ?? '',
        "genre" => $genreStr,
        "runtime" => $runtime,
        "rating" => $rating,
        "quality" => "HD"
    ];

    $newKey = nextNumericKey($movies);
    $movies[$newKey] = $newItem;

    $existingImdb[$imdbId] = true;
    $existingTmdb[$t][(string)$tmdbId] = true;

    $added[] = ["key"=>$newKey,"type"=>$t,"tmdb_id"=>$tmdbId,"imdb_id"=>$imdbId];

    // Stop early if we've added enough in THIS request (frontend stops when totalAdded>=limit)
    if (count($added) >= $limit) break;
}

$nextCursor = isset($i) ? $i : $end;
$done = ($nextCursor >= count($candidates));

$saveStatus = saveMovies($movies);
if (!($saveStatus['ok'] ?? false)) $errors[] = ["type"=>"save","details"=>$saveStatus];

echo json_encode([
    "ok" => true,
    "year" => $year,
    "mode" => $mode,
    "limit" => $limit,
    "cursor" => $cursor,
    "next_cursor" => $nextCursor,
    "total_candidates" => count($candidates),
    "added" => $added,
    "skipped" => array_slice($skipped, 0, 30),
    "added_count" => count($added),
    "skipped_count" => count($skipped),
    "errors" => $errors,
    "done" => $done
], JSON_PRETTY_PRINT);

?>
