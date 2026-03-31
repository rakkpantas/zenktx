<?php
// admin/bulk_import_year.php - Bulk import movies/series by year using TMDb (list) + OMDb (details)
session_start();

// Prevent PHP warnings/notices from breaking JSON
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// Catch fatal errors and return JSON instead of HTML
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
    header('Location: login.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ===== CONFIG =====
// TMDb API key (required) - get one free at https://www.themoviedb.org/settings/api
$TMDB_API_KEY = getenv('TMDB_API_KEY') ?: 'd4163a3eb4ae288e22f27d6175a8266a';
// OMDb API key (required) - get one free at http://www.omdbapi.com/apikey.aspx
$OMDB_API_KEY = getenv('OMDB_API_KEY') ?: 'a689013'; // fallback to the existing key in dashboard.js

// ===== Helpers =====
function jsonResponse($data, $status = 200) {
    // Clean any accidental output (warnings, BOM, etc.)
    if (ob_get_length()) { ob_clean(); }
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function loadMovies() {
    $path = __DIR__ . '/../movies.json';
    if (file_exists($path)) {
        $json = file_get_contents($path);
        return json_decode($json, true) ?: [];
    }
    return [];
}

function saveMovies($movies) {
    $path = __DIR__ . '/../movies.json';
    $json = json_encode($movies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents($path, $json);
    if ($ok === false) {
        $err = error_get_last();
        return ["ok"=>false, "path"=>$path, "error"=>($err["message"] ?? "write_failed")];
    }
    return ["ok"=>true, "path"=>$path, "bytes"=>$ok];
}

function httpGetJson($url) {
    // 1) Try file_get_contents (fast)
    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: Flixmo/1.0
"
        ]
    ];
    $context = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $context);

    // 2) Fallback to cURL (many shared hostings require this)
    if ($raw === false || $raw === null) {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERAGENT, "Flixmo/1.0");
            $raw = curl_exec($ch);
            curl_close($ch);
        }
    }

    if (!$raw) return null;
    $data = json_decode($raw, true);
    return $data ?: null;
}

function normalizeType($type) {
    $t = strtolower(trim($type));
    if ($t === 'tv' || $t === 'series' || $t === 'tv series') return 'tv';
    return 'movie';
}

function movieExists($movies, $imdbId) {
    foreach ($movies as $m) {
        if (isset($m['imdb_id']) && strtolower($m['imdb_id']) === strtolower($imdbId)) return true;
    }
    return false;
}

function tmdbDiscover($apiKey, $year, $type, $page) {
    // type: movie | tv
    $base = "https://api.themoviedb.org/3/discover/" . $type;
    $params = [
        "api_key" => $apiKey,
        "language" => "en-US",
        "sort_by" => "popularity.desc",
        "include_adult" => "false",
        "include_video" => "false",
        "page" => $page
    ];

    if ($type === 'movie') {
        $params["primary_release_year"] = $year;
    } else {
        $params["first_air_date_year"] = $year;
    }

    $url = $base . "?" . http_build_query($params);
    return httpGetJson($url);
}


function tmdbDetails($apiKey, $type, $tmdbId) {
    $type = ($type === 'tv') ? 'tv' : 'movie';
    $url = "https://api.themoviedb.org/3/{$type}/{$tmdbId}?api_key=" . urlencode($apiKey) . "&language=en-US";
    $data = httpGetJson($url);
    return is_array($data) ? $data : null;
}

function tmdbExternalIds($apiKey, $type, $tmdbId) {
    $url = "https://api.themoviedb.org/3/" . $type . "/" . urlencode($tmdbId) . "/external_ids?api_key=" . urlencode($apiKey);
    return httpGetJson($url);
}

function omdbByImdb($apiKey, $imdbId) {
    $url = "https://www.omdbapi.com/?i=" . urlencode($imdbId) . "&apikey=" . urlencode($apiKey);
    return httpGetJson($url);
}

// ===== Validate input =====
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$mode = isset($_POST['mode']) ? strtolower(trim($_POST['mode'])) : 'all'; // all | movie | tv
$limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 60;

// Candidate cache (prevents repeated TMDb discover calls per batch)
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheKey = 'candidates_' . preg_replace('/[^0-9]/','', (string)$year) . '_' . preg_replace('/[^a-z]/','', (string)$mode) . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;

// Helper to load cached candidates
function loadCandidateCache($file) {
    if (!file_exists($file)) return null;
    $raw = @file_get_contents($file);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['candidates']) || !is_array($j['candidates'])) return null;
    // expire after 12 hours
    $ts = (int)($j['ts'] ?? 0);
    if ($ts && (time() - $ts) > 12 * 3600) return null;
    return $j;
}
function saveCandidateCache($file, $year, $mode, $limit, $candidates) {
    $payload = [
        'ts' => time(),
        'year' => $year,
        'mode' => $mode,
        'limit' => $limit,
        'candidates' => $candidates
    ];
    @file_put_contents($file, json_encode($payload));
}

if ($year < 1900 || $year > ((int)date('Y') + 1)) {
    jsonResponse(["ok" => false, "error" => "Invalid year."], 400);
}

if (!in_array($mode, ['all', 'movie', 'tv'])) {
    jsonResponse(["ok" => false, "error" => "Invalid mode."], 400);
}

if ($limit < 1) $limit = 1;
if ($limit > 300) $limit = 300;

if (!$TMDB_API_KEY) {
    jsonResponse([
        "ok" => false,
        "error" => "TMDB_API_KEY is missing. Add it in your hosting environment variables (recommended) or hardcode it inside admin/bulk_import_year.php."
    ], 400);
}

if (!$OMDB_API_KEY) {
    jsonResponse([
        "ok" => false,
        "error" => "OMDB_API_KEY is missing."
    ], 400);
}

// ===== Main =====
@set_time_limit(0);
@ini_set('max_execution_time', '0');

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'batch';
$cursor = isset($_POST['cursor']) ? (int)$_POST['cursor'] : 0; // global offset
$batchSize = isset($_POST['batch']) ? max(1,(int)$_POST['batch']) : 15;

// Hard caps per request (avoid XAMPP timeouts)
$timeStart = microtime(true);
$timeBudget = 15.0; // seconds
$maxItemsThisRequest = min($batchSize, 8);


if ($batchSize < 1) $batchSize = 1;
if ($batchSize > 50) $batchSize = 50;

// Load existing DB (json)
$movies = loadMovies();

// Build (or reuse) a deterministic candidate list (TMDb IDs)
$candidates = [];

// reset cache if requested
if ($action === 'reset') {
    if (file_exists($cacheFile)) @unlink($cacheFile);
    echo json_encode(["ok"=>true, "reset"=>true], JSON_PRETTY_PRINT);
    exit;
}

$cached = loadCandidateCache($cacheFile);
if ($cached && !empty($cached['candidates'])) {
    $candidates = $cached['candidates'];
    // If old cache schema (missing title), rebuild
    $first = $candidates[0] ?? null;
    if (!is_array($first) || !array_key_exists('title', $first)) {
        $candidates = [];
    }
}
if (empty($candidates)) {
    // If action is 'batch' and no cache yet, we will build minimal candidates once.
    // Keep it light to avoid timeouts: fewer pages, and only up to limit*2.
    $targets = [];
    if ($mode === 'all') $targets = ['movie', 'tv'];
    if ($mode === 'movie') $targets = ['movie'];
    if ($mode === 'tv') $targets = ['tv'];

    foreach ($targets as $t) {
        $page = 1;
        $maxPages = 25; // allow larger pool
        $buildCap = max(600, $limit * 20);
        while ($page <= $maxPages && count($candidates) < $buildCap) {
            $discover = tmdbDiscover($TMDB_API_KEY, $year, $t, $page);
            if (!$discover || !isset($discover['results'])) break;

            foreach ($discover['results'] as $item) {
                $tmdbId = $item['id'] ?? null;
                if (!$tmdbId) continue;
                $candidates[] = ["type"=>$t,"tmdb_id"=>$tmdbId,"title"=>($item["title"]??$item["name"]??""),"poster_path"=>($item["poster_path"]??""),"vote_average"=>($item["vote_average"]??""),"overview"=>($item["overview"]??""),"date"=>($item["release_date"]??$item["first_air_date"]??"")];
                if (count($candidates) >= $buildCap) break;
            }
            $page++;
        }
    }

    saveCandidateCache($cacheFile, $year, $mode, $limit, $candidates);
}

// If caller only wants to prepare cache, return totals and exit
if ($action === 'prepare') {
    echo json_encode([
        "ok" => true,
        "prepared" => true,
        "total_candidates" => count($candidates),
        "cache_file" => basename($cacheFile)
    ], JSON_PRETTY_PRINT);
    exit;
}
// Now process only the current batch
$added = [];
$skipped = [];
$errors = [];

$countAddedThisBatch = 0;
$start = $cursor;
$end = min($cursor + $batchSize, count($candidates));

for ($i = $start, $processed = 0; $i < $end; $i++) {
    $processed++;
    if ($processed > $maxItemsThisRequest) { break; }
    if ((microtime(true) - $timeStart) > $timeBudget) { break; }


    $t = $candidates[$i]["type"];
    $tmdbId = $candidates[$i]["tmdb_id"];

    $ext = tmdbExternalIds($TMDB_API_KEY, $t, $tmdbId);
    $imdbId = $ext['imdb_id'] ?? '';

    // Always fetch TMDb details (fast + reliable) for fallback fields
    $details = tmdbDetails($TMDB_API_KEY, $t, $tmdbId);

    
    // Fallback to cached candidate metadata if TMDb details call fails
    if (!is_array($details)) {
        $details = [
            'title' => ($candidates[$i]['title'] ?? ''),
            'name' => ($candidates[$i]['title'] ?? ''),
            'poster_path' => ($candidates[$i]['poster_path'] ?? ''),
            'vote_average' => ($candidates[$i]['vote_average'] ?? ''),
            'overview' => ($candidates[$i]['overview'] ?? ''),
            'release_date' => ($candidates[$i]['date'] ?? ''),
            'first_air_date' => ($candidates[$i]['date'] ?? ''),
        ];
    }
// Prefer OMDb if we have IMDb ID, but do NOT require it (TV often fails on OMDb)
    $omdb = null;
    if ($imdbId) {
        $omdb = omdbByImdb($OMDB_API_KEY, $imdbId);
        if (!$omdb || ($omdb['Response'] ?? 'False') === 'False') {
            $omdb = null;
        }
    }

    // Build fields
    $title = '';
    $yearStr = (string)$year;
    $poster = '';
    $rating = '';

    if ($omdb) {
        $title = $omdb['Title'] ?? '';
        $yearStr = $omdb['Year'] ?? (string)$year;
        $poster = $omdb['Poster'] ?? '';
        $rating = $omdb['imdbRating'] ?? '';
    }

    // Fallback to TMDb details
    if (!$title && $details) {
        $title = ($t === 'tv') ? ($details['name'] ?? '') : ($details['title'] ?? '');
    }
    if ($details) {
        if ($t === 'tv') {
            $yearStr = $yearStr ?: ($details['first_air_date'] ?? (string)$year);
        } else {
            $yearStr = $yearStr ?: ($details['release_date'] ?? (string)$year);
        }
    }
    if ((!$poster || strtoupper($poster) === 'N/A') && $details) {
        $path = $details['poster_path'] ?? '';
        if ($path) {
            $poster = "https://image.tmdb.org/t/p/w500" . $path;
        }
    }
    if (!$rating && $details) {
        $vote = $details['vote_average'] ?? '';
        if ($vote !== '') $rating = number_format((float)$vote, 1);
    }

    if (!$title) {
        $skipped[] = ["reason" => "Missing title", "tmdb_id" => $tmdbId];
        continue;
    }

    // If still no poster, allow placeholder
    if (!$poster || strtoupper($poster) === 'N/A') {
        $poster = '../assets/no-poster.png';
    }

    // Dedup: use IMDb ID if present; otherwise fallback to TMDb ID + type
    if ($imdbId && movieExists($movies, $imdbId)) {
        $skipped[] = ["reason" => "Duplicate", "imdb_id" => $imdbId];
        continue;
    }
    if (!$imdbId) {
        $dup = false;
        foreach ($movies as $mm) {
            if (($mm['tmdb_id'] ?? '') == $tmdbId && ($mm['type'] ?? '') == normalizeType($t)) {
                $dup = true; break;
            }
        }
        if ($dup) {
            $skipped[] = ["reason" => "Duplicate (TMDb)", "tmdb_id" => $tmdbId];
            continue;
        }
    }

    $typeNormalized = normalizeType($omdb['Type'] ?? ($t === 'tv' ? 'series' : 'movie'));

    $newItem = [
        "imdb_id" => $imdbId,
        "tmdb_id" => $tmdbId,
        "title" => $title,
        "year" => preg_replace('/[^0-9\-]/', '', (string)$yearStr),
        "type" => $typeNormalized,
        "poster" => $poster,
        "rating" => is_numeric($rating) ? (float)$rating : $rating,
        "plot" => ($omdb && !empty($omdb["Plot"]) && strtoupper($omdb["Plot"]) !== "N/A") ? $omdb["Plot"] : ($details["overview"] ?? ""),
        "added_at" => date('c')
    ];

    $movies[] = $newItem;
    $added[] = $newItem;
    $countAddedThisBatch++;
}

// Save after every batch so progress is not lost
if ($countAddedThisBatch > 0) {
    $saveStatus = saveMovies($movies);
    if (!($saveStatus['ok'] ?? false)) { $errors[] = ['type'=>'save', 'details'=>$saveStatus]; }
}

// Determine completion
$totalAddedSoFar = 0;
foreach ($movies as $m) {
    if (isset($m['added_at']) && substr($m['added_at'], 0, 4) == (string)$year) {
        // not reliable; skip counting by year
    }
}

$nextCursor = isset($i) ? $i : $end;
$done = false;

// stop if cursor reached end OR if we already added enough items in total
// (we don't have a clean counter persisted, so we stop when cursor ends)
if ($nextCursor >= count($candidates)) $done = true;

jsonResponse([
    "ok" => true,
    "year" => $year,
    "mode" => $mode,
    "limit" => $limit,
    "batch" => $batchSize,
    "cursor" => $cursor,
    "next_cursor" => $nextCursor,
    "total_candidates" => count($candidates),
    "done" => $done,
    "added_count" => count($added),
    "processed_count" => ($nextCursor - $cursor),
    "added" => $added,
    "added_count" => count($added),
    "skipped_count" => count($skipped),
    "errors" => $errors,
    "skipped" => $skipped,
    "errors" => $errors
]);
