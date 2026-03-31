<?php
// Auto cache-busting (filemtime)
$asset_v_style = @filemtime(__DIR__.'/css/style.css') ?: time();
$asset_v_fade  = @filemtime(__DIR__.'/assets/fade.css') ?: $asset_v_style;
$asset_v_script = @filemtime(__DIR__.'/js/script.js') ?: $asset_v_style;

// index.php - Main homepage with separate sections
session_start();

// OMDb API Key (auto-synced from admin)
$OMDB_API_KEY = 'a689013';

// TMDb API Key (set this in admin panel)
$tmdbConfig = @include __DIR__ . '/config/tmdb.php';
$TMDB_API_KEY = is_array($tmdbConfig) ? ($tmdbConfig['api_key'] ?? '') : '';




$playerCfg = @include __DIR__ . '/config/player_servers.php';
$PLAYER_SERVER1_BASE = is_array($playerCfg) ? rtrim(($playerCfg['server1_base'] ?? 'https://vidsrc.me'), '/') : 'https://vidsrc.me';
$PLAYER_SERVER2_BASE = is_array($playerCfg) ? rtrim(($playerCfg['server2_base'] ?? 'https://www.vidking.net'), '/') : 'https://www.vidking.net';
$PLAYER_SERVER3_BASE = is_array($playerCfg) ? rtrim(($playerCfg['server3_base'] ?? 'https://player.videasy.net'), '/') : 'https://player.videasy.net';
$PLAYER_SERVER4_BASE = is_array($playerCfg) ? rtrim(($playerCfg['server4_base'] ?? ''), '/') : '';
$PLAYER_CUSTOM_SERVERS = (is_array($playerCfg) && isset($playerCfg['custom_servers']) && is_array($playerCfg['custom_servers'])) ? $playerCfg['custom_servers'] : [];

// Ads (managed from admin/ads_settings.php)
function zp_load_ads_settings() {
    $path = __DIR__ . '/config/ads.json';
    $defaults = [
        'home_after_viewall_movies' => '',
        'home_after_viewall_tv'     => '',
        'watch_after_related'       => '',
        // Random ad(s) inserted inside the Movies/TV listing grids (movie-card)
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

$ZP_ADS = zp_load_ads_settings();

// Pick a random non-empty movie-card ad code
function zp_pick_movie_card_ad($ads) {
    $pool = [];
    foreach (['movie_card_ad_1','movie_card_ad_2'] as $k) {
        $v = trim((string)($ads[$k] ?? ''));
        if ($v !== '') $pool[] = $v;
    }
    if (empty($pool)) return '';
    return $pool[array_rand($pool)];
}

function zp_render_movie_card_ad($ads) {
    $code = zp_pick_movie_card_ad($ads);
    if ($code === '') return;
    echo '<div class="movie-card ad-card" role="group" aria-label="Advertisement">';
    echo '  <div class="ad-slot ad-movie-card">' . $code . '</div>';
    echo '</div>';
}

// Poster fallback (prevents broken-image icons / "undefined")
function zp_poster_or_placeholder($poster) {
    $p = trim((string)$poster);
    if ($p === '' || strtoupper($p) === 'N/A') {
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

// Load movies from JSON file
function loadMovies() {
    if (file_exists('movies.json')) {
        $json = file_get_contents('movies.json');
        return json_decode($json, true) ?: [];
    }
    return [];
}


// Build genre list quickly (prefers stored 'genre' field; falls back to OMDb cache if available)
function buildGenresFromList($list) {
    $set = [];
    foreach ($list as $m) {
        $gStr = $m['genre'] ?? '';
        if (!$gStr && !empty($m['imdb_id'])) {
            // Use cached OMDb file if already cached (fast). Avoid network lag.
            $cacheFile = __DIR__ . '/cache/omdb_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $m['imdb_id']) . '.json';
            if (file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                $d = $raw ? json_decode($raw, true) : null;
                $gStr = is_array($d) ? ($d['Genre'] ?? '') : '';
            }
        }
        if (!$gStr || strtoupper($gStr) === 'N/A') continue;
        foreach (explode(',', $gStr) as $p) {
            $g = trim($p);
            if ($g !== '' && strtoupper($g) !== 'N/A') $set[$g] = true;
        }
    }
    $genres = array_keys($set);
    natcasesort($genres);
    return array_values($genres);
}

// Cached genres (rebuilds only if movies.json changed)
function getCachedGenres($cacheName, $list) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $moviesFile = __DIR__ . '/movies.json';
    $mtime = file_exists($moviesFile) ? filemtime($moviesFile) : 0;

    $file = $cacheDir . '/genres_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $cacheName) . '.json';
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j) && (int)($j['mtime'] ?? -1) === (int)$mtime && !empty($j['genres']) && is_array($j['genres'])) {
            return $j['genres'];
        }
    }

    $genres = buildGenresFromList($list);
    @file_put_contents($file, json_encode(["mtime"=>$mtime,"genres"=>$genres], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $genres;
}

// Build year list quickly from list (uses stored 'year' field)
function buildYearsFromList($list) {
    $set = [];
    foreach ($list as $m) {
        $y = trim((string)($m['year'] ?? ''));
        if ($y === '') continue;
        // keep only 4-digit year if present
        if (preg_match('/(19\d{2}|20\d{2}|2100)/', $y, $mm)) {
            $y = $mm[1];
        }
        if (!preg_match('/^\d{4}$/', $y)) continue;
        $set[$y] = true;
    }
    $years = array_keys($set);
    rsort($years, SORT_NUMERIC); // newest first
    return $years;
}

// Cached years (rebuilds only if movies.json changed)
function getCachedYears($cacheName, $list) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $moviesFile = __DIR__ . '/movies.json';
    $mtime = file_exists($moviesFile) ? filemtime($moviesFile) : 0;

    $file = $cacheDir . '/years_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $cacheName) . '.json';
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j) && (int)($j['mtime'] ?? -1) === (int)$mtime && !empty($j['years']) && is_array($j['years'])) {
            return $j['years'];
        }
    }

    $years = buildYearsFromList($list);
    @file_put_contents($file, json_encode(['mtime' => $mtime, 'years' => $years], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $years;
}




// Build trending chips (top genres by frequency) - uses stored 'genre' and cached OMDb if present
function normalizeGenreToken($g) {
    $g = trim($g);
    if ($g === '' || strtoupper($g) === 'N/A') return '';
    // Normalize common variants to a consistent chip label
    $low = strtolower($g);
    // Remove 'Soap' genre from chips/filters (requested)
    if ($low === 'soap') return '';
    // Keep "Sci-fi" and "Science Fiction" as SEPARATE chips.
    // Normalize only minor variants for each.
    if ($low === 'sci fi' || $low === 'scifi' || $low === 'sci-fi' || $low === 'sf') return 'Sci-fi';
    if ($low === 'science fiction') return 'Science Fiction';
    return $g;
}

function parseGenreTokens($gStr) {
    if (!$gStr || strtoupper($gStr) === 'N/A') return [];
    $out = [];
    foreach (explode(',', $gStr) as $p) {
        $p = trim($p);
        if ($p === '' || strtoupper($p) === 'N/A') continue;

        // Split "Action & Adventure" / "Sci-Fi & Fantasy" into separate tokens
        // (also handles 'Action&Adventure' without spaces)
        $subParts = preg_split('/\s*&\s*/', $p);
        foreach ($subParts as $sp) {
            $sp = normalizeGenreToken($sp);
            if ($sp !== '') $out[] = $sp;
        }
    }
    // de-dup while preserving order
    $seen = [];
    $uniq = [];
    foreach ($out as $t) {
        $k = strtolower($t);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $uniq[] = $t;
    }
    return $uniq;
}

function buildTrendingChips($list, $max = 10) {
    $counts = [];
    foreach ($list as $m) {
        $gStr = $m['genre'] ?? '';
        if (!$gStr && !empty($m['imdb_id'])) {
            $cacheFile = __DIR__ . '/cache/omdb_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $m['imdb_id']) . '.json';
            if (file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                $d = $raw ? json_decode($raw, true) : null;
                $gStr = is_array($d) ? ($d['Genre'] ?? '') : '';
            }
        }
        if (!$gStr || strtoupper($gStr) === 'N/A') continue;

        foreach (parseGenreTokens($gStr) as $g) {
            $counts[$g] = ($counts[$g] ?? 0) + 1;
        }
    }

    arsort($counts);
    $chips = array_slice(array_keys($counts), 0, $max);
    return $chips;
}

function getCachedTrendingChips($cacheName, $list, $max = 10) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $moviesFile = __DIR__ . '/movies.json';
    $mtime = file_exists($moviesFile) ? filemtime($moviesFile) : 0;

    $file = $cacheDir . '/trending_chips_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $cacheName) . '.json';
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j) && (int)($j['mtime'] ?? -1) === (int)$mtime && isset($j['max']) && (int)$j['max'] === (int)$max && !empty($j['chips']) && is_array($j['chips'])) {
            return $j['chips'];
        }
    }

    $chips = buildTrendingChips($list, $max);
    @file_put_contents($file, json_encode(["mtime"=>$mtime,"max"=>$max,"chips"=>$chips], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $chips;
}





// Fetch OMDb details by IMDb ID (uses OMDB_API_KEY from environment)
function fetchOmdbDetails($imdbId) {
    $apiKey = $GLOBALS['OMDB_API_KEY'] ?? '';
    if (!$apiKey) return null;

    // Simple file cache (reduces lag a LOT)
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/omdb_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $imdbId) . '.json';
    $cacheTTL = 60 * 60 * 24 * 7; // 7 days

    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < $cacheTTL) {
            $cached = @file_get_contents($cacheFile);
            if ($cached) {
                $data = json_decode($cached, true);
                if ($data && isset($data['Response']) && $data['Response'] === 'True') {
                    return $data;
                }
            }
        }
    }

    // Use full plot to reduce chances of getting empty/N/A descriptions on the watch page
    $url = "https://www.omdbapi.com/?i=" . urlencode($imdbId) . "&plot=full&apikey=" . urlencode($apiKey);

    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 3
        ]
    ]);

    $json = @file_get_contents($url, false, $context);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!$data || !isset($data['Response']) || $data['Response'] !== 'True') return null;

    // Save cache
    @file_put_contents($cacheFile, $json);

    return $data;
}


// === TMDb helpers (cast photos + characters) ===
function tmdbRequest($path, $params = []) {
    $apiKey = $GLOBALS['TMDB_API_KEY'] ?? '';
    if (!$apiKey) return null;

    $base = 'https://api.themoviedb.org/3';
    $params['api_key'] = $apiKey;
    $params['language'] = $params['language'] ?? 'en-US';

    $url = $base . $path . '?' . http_build_query($params);

    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 4
        ]
    ]);

    $json = @file_get_contents($url, false, $context);
    if (!$json) return null;

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function tmdbFindByImdbCached($imdbId) {
    $apiKey = $GLOBALS['TMDB_API_KEY'] ?? '';
    if (!$apiKey) return null;

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $imdbId);
    $cacheFile = $cacheDir . '/tmdb_find_' . $safe . '.json';
    $cacheTTL = 60 * 60 * 24 * 7;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data)) return $data;
        }
    }

    $data = tmdbRequest('/find/' . urlencode($imdbId), ['external_source' => 'imdb_id']);
    if (!$data) return null;

    @file_put_contents($cacheFile, json_encode($data));
    return $data;
}

function tmdbCreditsByImdb($imdbId, $type = 'movie') {
    $apiKey = $GLOBALS['TMDB_API_KEY'] ?? '';
    if (!$apiKey) return null;

    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $imdbId);
    $cacheFile = $cacheDir . '/tmdb_credits_' . $safe . '.json';
    $cacheTTL = 60 * 60 * 24 * 7;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data)) return $data;
        }
    }

    $found = tmdbFindByImdbCached($imdbId);
    if (!$found) return null;

    $result = null;
    if ($type === 'tv' && !empty($found['tv_results'][0]['id'])) {
        $tmdbId = $found['tv_results'][0]['id'];
        $result = tmdbRequest('/tv/' . $tmdbId . '/credits');
    } elseif (!empty($found['movie_results'][0]['id'])) {
        $tmdbId = $found['movie_results'][0]['id'];
        $result = tmdbRequest('/movie/' . $tmdbId . '/credits');
    } else {
        // fallback: try whichever exists
        if (!empty($found['movie_results'][0]['id'])) {
            $tmdbId = $found['movie_results'][0]['id'];
            $result = tmdbRequest('/movie/' . $tmdbId . '/credits');
        } elseif (!empty($found['tv_results'][0]['id'])) {
            $tmdbId = $found['tv_results'][0]['id'];
            $result = tmdbRequest('/tv/' . $tmdbId . '/credits');
        }
    }

    if (!$result) return null;

    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

// === TV helpers for episode selection ===
function tmdbTvIdByImdb($imdbId) {
    $found = tmdbFindByImdbCached($imdbId);
    if (!$found) return null;
    if (!empty($found['tv_results'][0]['id'])) return $found['tv_results'][0]['id'];
    // Sometimes a series is categorized differently; fallback to movie_results not appropriate for episodes.
    return null;
}

function tmdbTvDetailsByImdb($imdbId) {
    $tvId = tmdbTvIdByImdb($imdbId);
    if (!$tvId) return null;
    return tmdbRequest('/tv/' . $tvId, ['append_to_response' => 'external_ids']);
}

function tmdbSeasonDetailsByImdb($imdbId, $season) {
    $tvId = tmdbTvIdByImdb($imdbId);
    if (!$tvId) return null;
    $season = max(1, (int)$season);
    return tmdbRequest('/tv/' . $tvId . '/season/' . $season);
}

function clampInt($v, $min, $max) {
    $v = (int)$v;
    if ($v < $min) return $min;
    if ($v > $max) return $max;
    return $v;
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



$movies = array_reverse(array_values(loadMovies()));

// -----------------------------
// Simple router for "pretty" URLs
// Supports:
//   /            -> home
//   /movies      -> movies
//   /tv          -> tv-series
//   /request     -> request
//   /watchlist   -> watchlist
//   /watch/{id}  -> watch (id)
// Also still supports legacy:
//   index.php?page=...&id=...
// -----------------------------
$page = $_GET['page'] ?? null;
if (!$page) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $path = trim($path, '/');

    if ($path === '') {
        $page = 'home';
    } else {
        $parts = explode('/', $path);
        $first = strtolower($parts[0] ?? '');
        $second = $parts[1] ?? '';

        switch ($first) {
            case 'movies':
                $page = 'movies';
                break;
            case 'tv':
            case 'tv-series':
                $page = 'tv-series';
                break;
            case 'request':
                $page = 'request';
                break;
            case 'watchlist':
                $page = 'watchlist';
                break;
            case 'watch':
                $page = 'watch';
                if (!isset($_GET['id']) && $second !== '') {
                    $_GET['id'] = $second;
                }
                break;
            case 'search':
                $page = 'search';
                break;
            default:
                // Unknown paths fall back to home
                $page = 'home';
                break;
        }
    }
}

// Final fallback
if (!$page) $page = 'home';

// Separate movies and TV shows
$moviesList = array_filter($movies, function($movie) {
    $platform = strtolower($movie['platform'] ?? 'all');
    return $movie['type'] === 'movie' && $platform !== 'vivamax';
});

// prevent duplicate cards (same imdb_id appearing multiple times)
$moviesList = dedupeMediaList(array_values($moviesList));

$tvShowsList = array_filter($movies, function($movie) {
    $platform = strtolower($movie['platform'] ?? 'all');
    return $movie['type'] === 'tv' && $platform !== 'vivamax';
});

$tvShowsList = dedupeMediaList(array_values($tvShowsList));


// Genre + Year filters
$selectedGenre = $_GET['genre'] ?? '';
$selectedYear  = $_GET['year'] ?? '';

function flixmo_filter_by_genre($list, $selectedGenreLower) {
    $filtered = [];
    foreach ($list as $m) {
        $gStr = $m['genre'] ?? '';
        if (!$gStr && !empty($m['imdb_id'])) {
            // only use cached OMDb file to avoid network lag
            $cacheFile = __DIR__ . '/cache/omdb_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $m['imdb_id']) . '.json';
            if (file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                $d = $raw ? json_decode($raw, true) : null;
                $gStr = is_array($d) ? ($d['Genre'] ?? '') : '';
            }
        }
        if (!$gStr) continue;

        $parts = array_map('trim', explode(',', strtolower($gStr)));
        if (in_array($selectedGenreLower, $parts, true)) {
            $m['genre'] = $gStr;
            $filtered[] = $m;
        }
    }
    return dedupeMediaList(array_values($filtered));
}

function flixmo_filter_by_year($list, $selectedYear) {
    $selectedYear = trim((string)$selectedYear);
    if ($selectedYear === '') return $list;
    // keep 4-digit year only
    if (!preg_match('/^\d{4}$/', $selectedYear)) {
        if (preg_match('/(19\d{2}|20\d{2}|2100)/', $selectedYear, $mm)) {
            $selectedYear = $mm[1];
        }
    }
    if (!preg_match('/^\d{4}$/', $selectedYear)) return $list;

    $filtered = [];
    foreach ($list as $m) {
        $y = trim((string)($m['year'] ?? ''));
        if ($y === '') continue;
        if (preg_match('/(19\d{2}|20\d{2}|2100)/', $y, $mm)) $y = $mm[1];
        if ($y === $selectedYear) $filtered[] = $m;
    }
    return dedupeMediaList(array_values($filtered));
}

if ($selectedGenre) {
    $selectedGenreLower = strtolower($selectedGenre);
    if ($page === 'tv-series') {
        $tvShowsList = flixmo_filter_by_genre($tvShowsList, $selectedGenreLower);
    } else {
        $moviesList = flixmo_filter_by_genre($moviesList, $selectedGenreLower);
    }
}

if ($selectedYear) {
    if ($page === 'tv-series') {
        $tvShowsList = flixmo_filter_by_year($tvShowsList, $selectedYear);
    } else {
        $moviesList = flixmo_filter_by_year($moviesList, $selectedYear);
    }
}


// Actor filter (from cast section)
// NOTE: Do NOT pre-filter here (it requires many OMDb calls and can hang the page).
// Infinite scroll endpoint (load_more.php) handles the actor filter efficiently per page.
$selectedActor = trim($_GET['actor'] ?? '');

// Render lists (can be deferred when actor filter is active so the first paint is fast)
$moviesRenderList = $moviesList;
$tvRenderList = $tvShowsList;
if ($selectedActor !== '' && ($page === 'movies' || $page === 'tv-series')) {
    $moviesRenderList = [];
    $tvRenderList = [];
}


// Helper: get latest items by type (movie/tv)
function getLatestByType($items, $type) {
    $filtered = array_filter($items, function($item) use ($type) {
        $show = isset($item['show_in_latest']) ? (bool)$item['show_in_latest'] : false;
        $order = isset($item['latest_order']) ? (int)$item['latest_order'] : 0;
        return ($item['type'] === $type) && $show === true && $order >= 1 && $order <= 10;
    });

    usort($filtered, function($a, $b) {
        $oa = isset($a['latest_order']) ? (int)$a['latest_order'] : 999;
        $ob = isset($b['latest_order']) ? (int)$b['latest_order'] : 999;
        return $oa <=> $ob;
    });

    return array_slice($filtered, 0, 10);
}


// Get latest 10 movies and 10 TV shows (separate ordering)
$latestMovies = array_filter(getLatestByType($movies, 'movie'), function($m){
    return strtolower($m['platform'] ?? 'all') !== 'vivamax';
});
$latestMovies = array_values($latestMovies);

$latestTVShows = array_filter(getLatestByType($movies, 'tv'), function($m){
    return strtolower($m['platform'] ?? 'all') !== 'vivamax';
});
$latestTVShows = array_values($latestTVShows);

$trendingNow = array_filter($movies, function($m){
    return strtolower($m['platform'] ?? 'all') !== 'vivamax';
});
$trendingNow = array_values($trendingNow);
shuffle($trendingNow);
$trendingNow = array_slice($trendingNow, 0, 12);


// Site logo loader (admin-uploaded)
$logoFile = 'logo.png';
$metaFile = __DIR__ . '/assets/img/logo_meta.json';
if (file_exists($metaFile)) {
    $meta = json_decode(file_get_contents($metaFile), true);
    if (is_array($meta) && !empty($meta['file'])) {
        $logoFile = basename($meta['file']);
    }
}
$siteLogoPath = 'assets/img/' . $logoFile;
$hasSiteLogo = file_exists(__DIR__ . '/' . $siteLogoPath);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.png" type="image/png">

    <base href="/">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZENKTX - Find Your Favorite Movies</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo $asset_v_style; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/fade.css?v=<?php echo $asset_v_fade; ?>">
</head>
<body data-type="<?php echo ($page==='tv-series')?'tv':(($page==='movies')?'movie':'home'); ?>">
    <header class="header">
        <div class="container">
            <div class="nav-container">
<a href="/" class="logo">
                    <?php if (!empty($hasSiteLogo)): ?>
                    <img src="<?php echo htmlspecialchars($siteLogoPath); ?>" alt="FlixMo" class="logo-img">
                    <?php else: ?>
                    <span class="logo-text">🎬 FlixMo</span>
                    <?php endif; ?>
                </a>
<div class="nav-search" role="search" aria-label="Site search">
                    <form method="GET" action="/search">
                        
                        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Search movies or TV..." aria-label="Search" />
                        <button type="submit" aria-label="Search button"><i class="fas fa-search"></i></button>
                    </form>
                </div>

                
                <!-- Mobile menu toggle -->
                <button class="mobile-menu-toggle" id="mobileMenuToggle">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>

                <!-- Navigation menu -->
                <nav class="nav-menu" id="navMenu">
                    <div class="nav-overlay" id="navOverlay"></div>
                    <ul class="nav-list">
                        <li class="nav-item">
                            <a href="/" class="nav-link <?= $page == 'home' ? 'active' : '' ?>">
                                <i class="fas fa-home"></i>
                                <span>Home</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/movies" class="nav-link <?= $page == 'movies' ? 'active' : '' ?>">
                                <i class="fas fa-film"></i>
                                <span>Movies</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/tv" class="nav-link <?= $page == 'tv-series' ? 'active' : '' ?>">
                                <i class="fas fa-tv"></i>
                                <span>TV-Series</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/request" class="nav-link <?= $page == 'request' ? 'active' : '' ?>">
                                <i class="fas fa-plus-circle"></i>
                                <span>Request</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/watchlist" class="nav-link <?= $page == 'watchlist' ? 'active' : '' ?>">
                                <i class="fas fa-bookmark"></i>
                                <span>Watchlist</span>
                            </a>
                        </li>
                    </ul>
                </nav>

                

            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <?php if ($page == 'home'): ?>
                
<div class="hero-section hero-spotlight" id="heroSpotlight">
    <?php
        // Build 15 random hero items (movies + tv-series)
        $heroItems = $movies;
        shuffle($heroItems);
        $heroItems = array_slice($heroItems, 0, 15);

        // NOTE: Keep the hero *fast*. Avoid reading lots of cache files here.
        // Use movies.json fields only (overview + genre). This improves TTFB and makes the hero appear instantly.
        $heroPayload = [];
        foreach ($heroItems as $m) {
            $imdb = $m['imdb_id'] ?? '';
            // Some catalog entries may be missing imdb_id; skip them so the Play button always works.
            if (!$imdb) { continue; }
            $plot = $m['overview'] ?? '';
            $genres = [];

            $gStr = $m['genre'] ?? '';
            if ($gStr && strtoupper($gStr) !== 'N/A') {
                // Use existing helpers to split tokens (also handles "Action & Adventure")
                $genres = array_slice(parseGenreTokens($gStr), 0, 3);
            }

            if (!$plot || strtoupper(trim($plot)) === 'N/A') $plot = 'Tap Play to start watching now.';

            $heroPayload[] = [
                'imdb_id' => $imdb,
                'title'   => $m['title'] ?? '',
                'year'    => $m['year'] ?? '',
                'type'    => $m['type'] ?? '',
                'poster'  => $m['poster'] ?? '',
                'rating'  => $m['rating'] ?? '',
                'genres'  => $genres,
                'plot'    => $plot,
            ];
        }

        // Fallback: if everything was skipped, keep at least one valid item (best effort)
        if (empty($heroPayload) && !empty($movies)) {
            foreach ($movies as $m) {
                $imdb = $m['imdb_id'] ?? '';
                if (!$imdb) continue;
                $heroPayload[] = [
                    'imdb_id' => $imdb,
                    'title'   => $m['title'] ?? '',
                    'year'    => $m['year'] ?? '',
                    'type'    => $m['type'] ?? '',
                    'poster'  => $m['poster'] ?? '',
                    'rating'  => $m['rating'] ?? '',
                    'genres'  => array_slice(parseGenreTokens($m['genre'] ?? ''), 0, 3),
                    'plot'    => (($m['overview'] ?? '') && strtoupper(trim($m['overview'] ?? '')) !== 'N/A') ? ($m['overview'] ?? '') : 'Tap Play to start watching now.',
                ];
                break;
            }
        }
    ?>
    <?php $heroFirst = $heroPayload[0] ?? null; ?>
    <div class="hero-bg" aria-hidden="true" style="<?php if(!empty($heroFirst['poster'])){ echo 'background-image:url(\'' . htmlspecialchars($heroFirst['poster'], ENT_QUOTES) . '\');'; } ?>"></div>

    <div class="hero-inner">
        <div class="hero-left">
            <div class="hero-title" id="heroTitle"><?php echo htmlspecialchars($heroFirst['title'] ?? 'Featured'); ?></div>

            <div class="hero-meta" id="heroMeta">
                <?php if (!empty($heroFirst)): ?>
                    <?php if (!empty($heroFirst['rating'])): ?><span class="hero-pill"><?php echo '⭐ ' . htmlspecialchars($heroFirst['rating']) . '/10'; ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['year'])): ?><span class="hero-pill"><?php echo htmlspecialchars($heroFirst['year']); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['type'])): ?><span class="hero-pill"><?php echo htmlspecialchars(strtoupper($heroFirst['type'])); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['genres']) && is_array($heroFirst['genres'])): ?>
                        <?php foreach (array_slice($heroFirst['genres'], 0, 2) as $g): ?><span class="hero-pill"><?php echo htmlspecialchars($g); ?></span><?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="hero-desc" id="heroDesc"><?php echo htmlspecialchars($heroFirst['plot'] ?? ''); ?></div>

            <div class="hero-ctas">
                <a href="#" class="btn btn-play hero-btn-play" id="heroPlayBtn"><i class="fas fa-play"></i> Play</a>
                <a href="#" class="btn btn-more" id="heroMoreBtn"><i class="fas fa-info-circle"></i> See More</a>
            </div>

            <div class="hero-thumbs-wrap" aria-label="Hero picks">
                <button class="hero-thumbs-arrow hero-thumbs-prev" id="heroThumbsLeft" type="button" aria-label="Scroll hero picks left">❮</button>
                <div class="hero-thumbs" id="heroThumbs" role="list">
                    <?php if (!empty($heroPayload) && is_array($heroPayload)): ?>
                        <?php foreach ($heroPayload as $hi => $hv): ?>
                            <button type="button" class="hero-thumb is-loading<?php echo ($hi === 0) ? ' active' : ''; ?>" data-hero-index="<?php echo (int)$hi; ?>" title="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>" aria-label="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>">
                                <img
                                    src="<?php echo htmlspecialchars($hv['poster'] ?? ''); ?>"
                                    alt="<?php echo htmlspecialchars($hv['title'] ?? 'Poster'); ?>"
                                    decoding="async"
                                    <?php if ($hi < 8): ?>loading="eager" fetchpriority="high"<?php else: ?>loading="lazy"<?php endif; ?>
                                />
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="hero-thumbs-arrow hero-thumbs-next" id="heroThumbsRight" type="button" aria-label="Scroll hero picks right">❯</button>
            </div>
        </div>

        <div class="hero-right" aria-hidden="true">
            <div class="hero-art">
                <img id="heroArtImg" src="<?php echo htmlspecialchars($heroFirst['poster'] ?? ''); ?>" alt="<?php echo htmlspecialchars(($heroFirst['title'] ?? '') . ' poster'); ?>" />
            </div>
        </div>
    </div>

    <script>
        window.__heroItems = <?= json_encode($heroPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
</div>



                <!-- Latest Movies Section -->

                <!-- Platform Logo Buttons (premium style) -->
                <div class="platform-logos" aria-label="Streaming platforms">
                    <button class="platform-card" data-platform="vivamax" title="VivaMax" aria-label="VivaMax">
                        <img class="platform-logo" src="assets/logos/vivamax.png" alt="VivaMax">
                        <span class="platform-label">VIVAMAX</span>
                    </button>

                    <button class="platform-card" data-platform="netflix" title="Netflix" aria-label="Netflix">
                        <img class="platform-logo" src="assets/logos/netflix.png" alt="Netflix">
                        <span class="platform-label">NETFLIX</span>
                    </button>

                    <button class="platform-card" data-platform="warnerbros" title="Warner Bros." aria-label="Warner Bros.">
                        <img class="platform-logo" src="assets/logos/warnerbros.png" alt="Warner Bros.">
                        <span class="platform-label">WARNER BROS</span>
                    </button>

                    <button class="platform-card" data-platform="hulu" title="Hulu" aria-label="Hulu">
                        <img class="platform-logo" src="assets/logos/hulu.png" alt="Hulu">
                        <span class="platform-label">HULU</span>
                    </button>

                    <button class="platform-card" data-platform="primevideo" title="Prime Video" aria-label="Prime Video">
                        <img class="platform-logo" src="assets/logos/primevideo.png" alt="Prime Video">
                        <span class="platform-label">PRIME VIDEO</span>
                    </button>

                    <button class="platform-card" data-platform="disneyplus" title="Disney+" aria-label="Disney+">
                        <img class="platform-logo" src="assets/logos/disneyplus.png" alt="Disney+">
                        <span class="platform-label">DISNEY+</span>
                    </button>

                    <button class="platform-card" data-platform="hbomax" title="HBO Max" aria-label="HBO Max">
                        <img class="platform-logo" src="assets/logos/hbomax.png" alt="HBO Max">
                        <span class="platform-label">HBOMAX</span>
                    </button>

                    <button class="platform-card" data-platform="appletvplus" title="Apple TV+" aria-label="Apple TV+">
                        <img class="platform-logo" src="assets/logos/appletvplus.png" alt="Apple TV+">
                        <span class="platform-label">APPLE+</span>
                    </button>
                </div>

<h2 class="section-title"> Latest Post Movies 🔥</h2>
                <div class="content-grid" id="moviesGrid">
                    <?php foreach ($latestMovies as $movie): ?>
                        <div class="movie-card" data-platform="<?= htmlspecialchars($movie['platform'] ?? 'all') ?>" onclick="watchMovie('<?= htmlspecialchars($movie['imdb_id']) ?>')">
                            <div style="position: relative;">
                                <img src="<?= htmlspecialchars(zp_poster_or_placeholder($movie['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($movie['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                <span class="movie-label">MOVIE</span>
                                <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($movie['rating']) ?></span>
                            </div>
                            <div class="movie-info">
                                <h3 class="movie-title"><?= htmlspecialchars($movie['title']) ?></h3>
                                <p class="movie-year"><?= htmlspecialchars($movie['year']) ?></p>
                                
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="/movies" class="view-all-btn">View All Movies</a>
		<?php if (!empty($ZP_ADS['home_after_viewall_movies'])): ?>
		    <div class="ad-slot ad-home-after-viewall-movies">
		        <?= $ZP_ADS['home_after_viewall_movies'] ?>
		    </div>
		<?php endif; ?>

                <!-- Latest TV Shows Section -->
                <h2 class="section-title"> Latest Post TV-Shows 🔥</h2>
                <div class="content-grid" id="tvShowsGrid">
                    <?php foreach ($latestTVShows as $tvShow): ?>
                        <div class="movie-card" data-platform="<?= htmlspecialchars($movie['platform'] ?? 'all') ?>" onclick="watchMovie('<?= htmlspecialchars($tvShow['imdb_id']) ?>')">
                            <div style="position: relative;">
                                <img src="<?= htmlspecialchars(zp_poster_or_placeholder($tvShow['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($tvShow['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                <span class="movie-label">TV SHOW</span>
                                <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($tvShow['rating']) ?></span>
                            </div>
                            <div class="movie-info">
                                <h3 class="movie-title"><?= htmlspecialchars($tvShow['title']) ?></h3>
                                <p class="movie-year"><?= htmlspecialchars($tvShow['year']) ?></p>
                                
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="/tv" class="view-all-btn">View All TV Shows</a>
		<?php if (!empty($ZP_ADS['home_after_viewall_tv'])): ?>
		    <div class="ad-slot ad-home-after-viewall-tv">
		        <?= $ZP_ADS['home_after_viewall_tv'] ?>
		    </div>
		<?php endif; ?>


                <!-- Most Watched Section -->
                <h2 class="section-title" id="most-watched">Most Watched 🔥</h2>

                
                <div class="trending-chips">
                    <?php
                        $chipGenre = $_GET['tgenre'] ?? '';
                        echo '<button class="chip ' . ($chipGenre==='' ? 'active' : '') . '" onclick="filterTrendingGenre(\'\')">All</button>';
                        // Fixed chip list (requested) + add any extra genres found in the current list.
                        $fixedChips = [
                            'Action','Adventure','Animation','Comedy','Crime','Drama','Family','Fantasy','History',
                            'Horror','Mystery','Romance','Sci-fi','Science Fiction','Sport','Thriller','War'
                        ];

                        $dynamicChips = getCachedTrendingChips('most_watched', $trendingNow, 50);
                        $chipSet = [];
                        foreach ($fixedChips as $g) $chipSet[normalizeGenreToken($g)] = true;
                        foreach ($dynamicChips as $g) $chipSet[normalizeGenreToken($g)] = true;

                        // Keep order: fixed first, then extras alphabetically.
                        $chips = [];
                        foreach ($fixedChips as $g) {
                            $n = normalizeGenreToken($g);
                            if ($n !== '' && isset($chipSet[$n])) {
                                $chips[] = $n;
                                unset($chipSet[$n]);
                            }
                        }
                        $extras = array_keys($chipSet);
                        natcasesort($extras);
                        foreach ($extras as $g) { if ($g !== '') $chips[] = $g; }

                        foreach ($chips as $c) {
                            $active = ($chipGenre && strcasecmp($chipGenre, $c)===0) ? 'active' : '';
                            echo '<button class="chip ' . $active . '" onclick="filterTrendingGenre(\'' . htmlspecialchars(addslashes($c)) . '\')">' . htmlspecialchars($c) . '</button>';
                        }
                    ?>
                </div>


                <?php
                    // Apply trending chip filter (Most Watched section)
                    $tgenre = $_GET['tgenre'] ?? '';
                    if ($tgenre) {
                        $tgenreLower = strtolower($tgenre);
                        $filteredTrending = [];
                        foreach ($trendingNow as $it) {
                            $gStr = $it['genre'] ?? '';
                            if (!$gStr && !empty($it['imdb_id'])) {
                                $cacheFile = __DIR__ . '/cache/omdb_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $it['imdb_id']) . '.json';
                                if (file_exists($cacheFile)) {
                                    $raw = @file_get_contents($cacheFile);
                                    $d = $raw ? json_decode($raw, true) : null;
                                    $gStr = is_array($d) ? ($d['Genre'] ?? '') : '';
                                }
                            }
                            if (!$gStr) continue;
                            $parts = array_map('strtolower', parseGenreTokens($gStr));
                            // normalize desired token
                            $want = normalizeGenreToken($tgenre);
                            $wantLower = strtolower($want);
                            if (in_array($wantLower, $parts, true)) {
                                $it['genre'] = $gStr;
                                $filteredTrending[] = $it;
                            }
                        }
                        $trendingNow = $filteredTrending;
                    }
                ?>

                <div class="trending-grid">
                    <?php if (empty($trendingNow)): ?>
                        <div class="no-results" style="grid-column: 1 / -1; padding: 18px; border-radius: 14px; background: rgba(255,255,255,0.06);">
                            No results for <strong><?= htmlspecialchars($tgenre ?: 'All') ?></strong>.
                        </div>
                    <?php endif; ?>
                    <?php foreach ($trendingNow as $i => $item): ?>
                        <?php $d = fetchOmdbDetails($item['imdb_id']); ?>
                        <div class="trending-card" data-genre="<?= htmlspecialchars($d['Genre'] ?? '') ?>" onclick="watchMovie('<?= htmlspecialchars($item['imdb_id']) ?>')">
                            <div class="trending-rank"><?= $i + 1 ?></div>
                            <div class="trending-poster">
                                <img loading="lazy" src="<?= htmlspecialchars($item['poster']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                            </div>
                            <div class="trending-info">
                                <div class="trending-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="trending-meta">
                                    <span class="t-type"><?= strtoupper(htmlspecialchars($item['type'])) ?></span>
                                    <span class="t-rating">⭐ <?= htmlspecialchars($item['rating']) ?></span>
                                    <span class="t-year"><?= htmlspecialchars($item['year']) ?></span>
                                </div>
                                <div class="trending-plot"><?= htmlspecialchars(($d['Plot'] ?? '') ?: ($item['plot'] ?? '')) ?></div>
                                <button class="trending-play" onclick="event.stopPropagation(); watchMovie('<?= htmlspecialchars($item['imdb_id']) ?>')">
                                    ▶ Play
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>


            <?php elseif ($page == 'movies'): ?>
                




<div class="hero-section hero-spotlight" id="heroSpotlight">
    <?php
        // Build 15 random hero items (movies only)
        $heroItems = $moviesList;
        shuffle($heroItems);
        $heroItems = array_slice($heroItems, 0, 15);

        // NOTE: Keep the hero *fast*. Avoid reading lots of cache files here.
        // Use movies.json fields only (overview + genre). This improves TTFB and makes the hero appear instantly.
        $heroPayload = [];
        foreach ($heroItems as $m) {
            $imdb = $m['imdb_id'] ?? '';
            if (!$imdb) { continue; }
            $plot = $m['overview'] ?? '';
            $genres = [];

            $gStr = $m['genre'] ?? '';
            if ($gStr && strtoupper($gStr) !== 'N/A') {
                $genres = array_slice(parseGenreTokens($gStr), 0, 3);
            }

            if (!$plot || strtoupper(trim($plot)) === 'N/A') $plot = 'Tap Play to start watching now.';

            $heroPayload[] = [
                'imdb_id' => $imdb,
                'title'   => $m['title'] ?? '',
                'year'    => $m['year'] ?? '',
                'type'    => $m['type'] ?? '',
                'poster'  => $m['poster'] ?? '',
                'rating'  => $m['rating'] ?? '',
                'genres'  => $genres,
                'plot'    => $plot,
            ];
        }

        if (empty($heroPayload) && !empty($moviesList)) {
            foreach ($moviesList as $m) {
                $imdb = $m['imdb_id'] ?? '';
                if (!$imdb) continue;
                $heroPayload[] = [
                    'imdb_id' => $imdb,
                    'title'   => $m['title'] ?? '',
                    'year'    => $m['year'] ?? '',
                    'type'    => $m['type'] ?? '',
                    'poster'  => $m['poster'] ?? '',
                    'rating'  => $m['rating'] ?? '',
                    'genres'  => array_slice(parseGenreTokens($m['genre'] ?? ''), 0, 3),
                    'plot'    => (($m['overview'] ?? '') && strtoupper(trim($m['overview'] ?? '')) !== 'N/A') ? ($m['overview'] ?? '') : 'Tap Play to start watching now.',
                ];
                break;
            }
        }
    ?>
    <?php $heroFirst = $heroPayload[0] ?? null; ?>
    <div class="hero-bg" aria-hidden="true" style="<?php if(!empty($heroFirst['poster'])){ echo 'background-image:url(\'' . htmlspecialchars($heroFirst['poster'], ENT_QUOTES) . '\');'; } ?>"></div>

    <div class="hero-inner">
        <div class="hero-left">
            <div class="hero-title" id="heroTitle"><?php echo htmlspecialchars($heroFirst['title'] ?? 'Featured'); ?></div>

            <div class="hero-meta" id="heroMeta">
                <?php if (!empty($heroFirst)): ?>
                    <?php if (!empty($heroFirst['rating'])): ?><span class="hero-pill"><?php echo '⭐ ' . htmlspecialchars($heroFirst['rating']) . '/10'; ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['year'])): ?><span class="hero-pill"><?php echo htmlspecialchars($heroFirst['year']); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['type'])): ?><span class="hero-pill"><?php echo htmlspecialchars(strtoupper($heroFirst['type'])); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['genres']) && is_array($heroFirst['genres'])): ?>
                        <?php foreach (array_slice($heroFirst['genres'], 0, 2) as $g): ?><span class="hero-pill"><?php echo htmlspecialchars($g); ?></span><?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="hero-desc" id="heroDesc"><?php echo htmlspecialchars($heroFirst['plot'] ?? ''); ?></div>

            <div class="hero-ctas">
                <a href="#" class="btn btn-play hero-btn-play" id="heroPlayBtn"><i class="fas fa-play"></i> Play</a>
                <a href="#" class="btn btn-more" id="heroMoreBtn"><i class="fas fa-info-circle"></i> See More</a>
            </div>

            <div class="hero-thumbs-wrap" aria-label="Hero picks">
                <button class="hero-thumbs-arrow hero-thumbs-prev" id="heroThumbsLeft" type="button" aria-label="Scroll hero picks left">❮</button>
                <div class="hero-thumbs" id="heroThumbs" role="list">
                    <?php if (!empty($heroPayload) && is_array($heroPayload)): ?>
                        <?php foreach ($heroPayload as $hi => $hv): ?>
                            <button type="button" class="hero-thumb is-loading<?php echo ($hi === 0) ? ' active' : ''; ?>" data-hero-index="<?php echo (int)$hi; ?>" title="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>" aria-label="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>">
                                <img
                                    src="<?php echo htmlspecialchars($hv['poster'] ?? ''); ?>"
                                    alt="<?php echo htmlspecialchars($hv['title'] ?? 'Poster'); ?>"
                                    decoding="async"
                                    <?php if ($hi < 8): ?>loading="eager" fetchpriority="high"<?php else: ?>loading="lazy"<?php endif; ?>
                                />
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="hero-thumbs-arrow hero-thumbs-next" id="heroThumbsRight" type="button" aria-label="Scroll hero picks right">❯</button>
            </div>
        </div>

        <div class="hero-right" aria-hidden="true">
            <div class="hero-art">
                <img id="heroArtImg" src="<?php echo htmlspecialchars($heroFirst['poster'] ?? ''); ?>" alt="<?php echo htmlspecialchars(($heroFirst['title'] ?? '') . ' poster'); ?>" />
            </div>
        </div>
    </div>

    <script>
        window.__heroItems = <?= json_encode($heroPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
</div>

<div class="grid-top-filters">
    <div class="genre-filter">
        <select id="genreSelect" class="filter-select genre-select" onchange="applyGenreFilter()">
            <option value="">🎭 All Genres</option>
            <?php
                $genreOptions = getCachedGenres('home_movies', $moviesList);
                foreach ($genreOptions as $g) {
                    $sel = ($selectedGenre && strcasecmp($selectedGenre, $g) === 0) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($g) . '" ' . $sel . '>' . htmlspecialchars($g) . '</option>';
                }
            ?>
        </select>
    </div>

    <div class="year-filter">
        <select id="yearSelect" class="filter-select year-select" onchange="applyYearFilter()">
            <option value="">📅 All Years</option>
            <?php
                $yearOptions = getCachedYears('movies', $moviesList);
                foreach ($yearOptions as $y) {
                    $sel = ($selectedYear && (string)$selectedYear === (string)$y) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($y) . '" ' . $sel . '>' . htmlspecialchars($y) . '</option>';
                }
            ?>
        </select>
    </div>
</div>

	                <?php if (!empty($selectedActor)): ?>
	                    <div class="search-subtitle" style="margin:10px 2px 14px;opacity:.95;">
	                        Filtering by actor: <strong><?= htmlspecialchars($selectedActor) ?></strong>
	                        <a href="index.php?page=<?= htmlspecialchars($page) ?><?= !empty($selectedGenre) ? '&genre=' . urlencode($selectedGenre) : '' ?>" style="margin-left:10px;">(clear)</a>
	                    </div>
	                <?php endif; ?>

                <div class="content-grid" id="contentGrid">
                    <?php $zp_i = 0; $zp_ads_shown = 0; foreach (array_slice($moviesRenderList, 0, 10) as $movie): $zp_i++; ?>
                        <div class="movie-card" data-platform="<?= htmlspecialchars($movie['platform'] ?? 'all') ?>" onclick="watchMovie('<?= htmlspecialchars($movie['imdb_id']) ?>')">
                            <div style="position: relative;">
                                <img src="<?= htmlspecialchars(zp_poster_or_placeholder($movie['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($movie['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                <span class="movie-label">MOVIE</span>
                                <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($movie['rating']) ?></span>
                            </div>
<div class="movie-info">
                                <h3 class="movie-title"><?= htmlspecialchars($movie['title']) ?></h3>
                                <p class="movie-year"><?= htmlspecialchars($movie['year']) ?></p>
                                
                            </div>
                        </div>
                        <?php if (isset($zp_ads_shown) && $zp_ads_shown < 2 && in_array($zp_i, [6,18], true)) { zp_render_movie_card_ad($ZP_ADS); $zp_ads_shown++; } ?>
                    <?php endforeach; ?>
                </div>

                <!-- Infinite scroll sentinel + bottom indicator (MUST be outside the grid) -->
                <div id="loadMoreTrigger" aria-hidden="true" style="height:1px;"></div>
                <div id="loading" style="display:block;text-align:center;padding:20px;opacity:.9;">⬇️ Load more</div>

            <?php elseif ($page == 'tv-series'): ?>
                




<div class="hero-section hero-spotlight" id="heroSpotlight">
    <?php
        // Build 15 random hero items (tv-series only)
        $heroItems = $tvShowsList;
        shuffle($heroItems);
        $heroItems = array_slice($heroItems, 0, 15);

        // NOTE: Keep the hero *fast*. Avoid reading lots of cache files here.
        // Use movies.json fields only (overview + genre). This improves TTFB and makes the hero appear instantly.
        $heroPayload = [];
        foreach ($heroItems as $m) {
            $imdb = $m['imdb_id'] ?? '';
            if (!$imdb) { continue; }
            $plot = $m['overview'] ?? '';
            $genres = [];

            $gStr = $m['genre'] ?? '';
            if ($gStr && strtoupper($gStr) !== 'N/A') {
                $genres = array_slice(parseGenreTokens($gStr), 0, 3);
            }

            if (!$plot || strtoupper(trim($plot)) === 'N/A') $plot = 'Tap Play to start watching now.';

            $heroPayload[] = [
                'imdb_id' => $imdb,
                'title'   => $m['title'] ?? '',
                'year'    => $m['year'] ?? '',
                'type'    => $m['type'] ?? '',
                'poster'  => $m['poster'] ?? '',
                'rating'  => $m['rating'] ?? '',
                'genres'  => $genres,
                'plot'    => $plot,
            ];
        }

        if (empty($heroPayload) && !empty($tvSeriesList)) {
            foreach ($tvSeriesList as $m) {
                $imdb = $m['imdb_id'] ?? '';
                if (!$imdb) continue;
                $heroPayload[] = [
                    'imdb_id' => $imdb,
                    'title'   => $m['title'] ?? '',
                    'year'    => $m['year'] ?? '',
                    'type'    => $m['type'] ?? '',
                    'poster'  => $m['poster'] ?? '',
                    'rating'  => $m['rating'] ?? '',
                    'genres'  => array_slice(parseGenreTokens($m['genre'] ?? ''), 0, 3),
                    'plot'    => (($m['overview'] ?? '') && strtoupper(trim($m['overview'] ?? '')) !== 'N/A') ? ($m['overview'] ?? '') : 'Tap Play to start watching now.',
                ];
                break;
            }
        }
    ?>
    <?php $heroFirst = $heroPayload[0] ?? null; ?>
    <div class="hero-bg" aria-hidden="true" style="<?php if(!empty($heroFirst['poster'])){ echo 'background-image:url(\'' . htmlspecialchars($heroFirst['poster'], ENT_QUOTES) . '\');'; } ?>"></div>

    <div class="hero-inner">
        <div class="hero-left">
            <div class="hero-title" id="heroTitle"><?php echo htmlspecialchars($heroFirst['title'] ?? 'Featured'); ?></div>

            <div class="hero-meta" id="heroMeta">
                <?php if (!empty($heroFirst)): ?>
                    <?php if (!empty($heroFirst['rating'])): ?><span class="hero-pill"><?php echo '⭐ ' . htmlspecialchars($heroFirst['rating']) . '/10'; ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['year'])): ?><span class="hero-pill"><?php echo htmlspecialchars($heroFirst['year']); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['type'])): ?><span class="hero-pill"><?php echo htmlspecialchars(strtoupper($heroFirst['type'])); ?></span><?php endif; ?>
                    <?php if (!empty($heroFirst['genres']) && is_array($heroFirst['genres'])): ?>
                        <?php foreach (array_slice($heroFirst['genres'], 0, 2) as $g): ?><span class="hero-pill"><?php echo htmlspecialchars($g); ?></span><?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="hero-desc" id="heroDesc"><?php echo htmlspecialchars($heroFirst['plot'] ?? ''); ?></div>

            <div class="hero-ctas">
                <a href="#" class="btn btn-play hero-btn-play" id="heroPlayBtn"><i class="fas fa-play"></i> Play</a>
                <a href="#" class="btn btn-more" id="heroMoreBtn"><i class="fas fa-info-circle"></i> See More</a>
            </div>

            <div class="hero-thumbs-wrap" aria-label="Hero picks">
                <button class="hero-thumbs-arrow hero-thumbs-prev" id="heroThumbsLeft" type="button" aria-label="Scroll hero picks left">❮</button>
                <div class="hero-thumbs" id="heroThumbs" role="list">
                    <?php if (!empty($heroPayload) && is_array($heroPayload)): ?>
                        <?php foreach ($heroPayload as $hi => $hv): ?>
                            <button type="button" class="hero-thumb is-loading<?php echo ($hi === 0) ? ' active' : ''; ?>" data-hero-index="<?php echo (int)$hi; ?>" title="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>" aria-label="<?php echo htmlspecialchars($hv['title'] ?? 'Pick'); ?>">
                                <img
                                    src="<?php echo htmlspecialchars($hv['poster'] ?? ''); ?>"
                                    alt="<?php echo htmlspecialchars($hv['title'] ?? 'Poster'); ?>"
                                    decoding="async"
                                    <?php if ($hi < 8): ?>loading="eager" fetchpriority="high"<?php else: ?>loading="lazy"<?php endif; ?>
                                />
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="hero-thumbs-arrow hero-thumbs-next" id="heroThumbsRight" type="button" aria-label="Scroll hero picks right">❯</button>
            </div>
        </div>

        <div class="hero-right" aria-hidden="true">
            <div class="hero-art">
                <img id="heroArtImg" src="<?php echo htmlspecialchars($heroFirst['poster'] ?? ''); ?>" alt="<?php echo htmlspecialchars(($heroFirst['title'] ?? '') . ' poster'); ?>" />
            </div>
        </div>
    </div>

    <script>
        window.__heroItems = <?= json_encode($heroPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
</div>

<div class="grid-top-filters">
    <div class="genre-filter">
        <select id="genreSelect" class="filter-select genre-select" onchange="applyGenreFilter()">
            <option value="">🎭 All Genres</option>
            <?php
                $genreOptions = getCachedGenres('tv_series', $tvShowsList);
                foreach ($genreOptions as $g) {
                    $sel = ($selectedGenre && strcasecmp($selectedGenre, $g) === 0) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($g) . '" ' . $sel . '>' . htmlspecialchars($g) . '</option>';
                }
            ?>
        </select>
    </div>

    <div class="year-filter">
        <select id="yearSelect" class="filter-select year-select" onchange="applyYearFilter()">
            <option value="">📅 All Years</option>
            <?php
                $yearOptions = getCachedYears('tv', $tvShowsList);
                foreach ($yearOptions as $y) {
                    $sel = ($selectedYear && (string)$selectedYear === (string)$y) ? 'selected' : '';
                    echo '<option value="' . htmlspecialchars($y) . '" ' . $sel . '>' . htmlspecialchars($y) . '</option>';
                }
            ?>
        </select>
    </div>
</div>

	                <?php if (!empty($selectedActor)): ?>
	                    <div class="search-subtitle" style="margin:10px 2px 14px;opacity:.95;">
	                        Filtering by actor: <strong><?= htmlspecialchars($selectedActor) ?></strong>
	                        <a href="index.php?page=<?= htmlspecialchars($page) ?><?= !empty($selectedGenre) ? '&genre=' . urlencode($selectedGenre) : '' ?>" style="margin-left:10px;">(clear)</a>
	                    </div>
	                <?php endif; ?>

                <div class="content-grid" id="contentGrid">
                    <?php $zp_i = 0; $zp_ads_shown = 0; foreach (array_slice($tvRenderList, 0, 10) as $tvShow): $zp_i++; ?>
                        <div class="movie-card" data-platform="<?= htmlspecialchars($tvShow['platform'] ?? 'all') ?>" onclick="watchMovie('<?= htmlspecialchars($tvShow['imdb_id']) ?>')">
                            <div style="position: relative;">
                                <img src="<?= htmlspecialchars(zp_poster_or_placeholder($tvShow['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($tvShow['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                <span class="movie-label">TV SHOW</span>
                                <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($tvShow['rating']) ?></span>
                            </div>
<div class="movie-info">
                                <h3 class="movie-title"><?= htmlspecialchars($tvShow['title']) ?></h3>
                                <p class="movie-year"><?= htmlspecialchars($tvShow['year']) ?></p>
                                
                            </div>
                        </div>
                        <?php if (isset($zp_ads_shown) && $zp_ads_shown < 2 && in_array($zp_i, [6,18], true)) { zp_render_movie_card_ad($ZP_ADS); $zp_ads_shown++; } ?>
                    <?php endforeach; ?>
                </div>

                <!-- Infinite scroll sentinel + bottom indicator (MUST be outside the grid) -->
                <div id="loadMoreTrigger" aria-hidden="true" style="height:1px;"></div>
                <div id="loading" style="display:block;text-align:center;padding:20px;opacity:.9;">⬇️ Load more</div>

            
            <?php elseif ($page == 'search'): ?>
                <?php
                    $q = trim($_GET['q'] ?? '');
                    $qLower = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);

                    // search across all items (movies + tv)
                    $results = [];
                    if ($q !== '') {
                        foreach ($movies as $m) {
                            $title = $m['title'] ?? '';
                            $imdb  = $m['imdb_id'] ?? '';
                            $hay = function_exists('mb_strtolower') ? mb_strtolower($title . ' ' . $imdb) : strtolower($title . ' ' . $imdb);
                            if (strpos($hay, $qLower) !== false) {
                                $results[] = $m;
                            }
                        }
                    }
                ?>

                <div class="search-page">
                    <div class="search-header">
                        <h2 class="section-title">Search</h2>
                        <?php if ($q !== ''): ?>
                            <p class="search-subtitle">
                                Results for <span class="search-query">"<?= htmlspecialchars($q) ?>"</span>
                                <span class="search-count">(<?= count($results) ?>)</span>
                            </p>
                        <?php else: ?>
                            <p class="search-subtitle">Type something in the search box above.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($q !== '' && count($results) === 0): ?>
                        <div class="search-empty">
                            <div class="search-empty-title">No results found</div>
                            <div class="search-empty-hint">Try a different keyword or check spelling.</div>
                        </div>
                    <?php elseif ($q !== ''): ?>
                        <div class="content-grid search-grid">
                            <?php foreach ($results as $movie): ?>
                                <div class="movie-card" onclick="watchMovie('<?= htmlspecialchars($movie['imdb_id'] ?? '') ?>')">
                                    <div style="position: relative;">
                                        <img src="<?= htmlspecialchars(zp_poster_or_placeholder($movie['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($movie['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                        <span class="movie-label"><?= strtoupper(htmlspecialchars($movie['type'] ?? 'MOVIE')) ?></span>
                                        <span class="movie-rating movie-rating-overlay">⭐ <?= htmlspecialchars($movie['rating'] ?? '') ?></span>
                                    </div>
                                    <div class="movie-info">
                                        <h3><?= htmlspecialchars($movie['title'] ?? '') ?></h3>
                                        <p><?= htmlspecialchars($movie['year'] ?? '') ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

<?php elseif ($page == 'watch' && isset($_GET['id'])): ?>
                <div class="page-content">
                    <?php
                    $watchId = $_GET['id'];
                    $currentMovie = null;
                    $omdbDetails = null;
                    foreach ($movies as $movie) {
                        if ($movie['imdb_id'] === $watchId) {
                            $currentMovie = $movie;
                            break;
                        }
                    }
                    ?>
                    
                    <?php
                    if ($currentMovie) {
                        $omdbDetails = fetchOmdbDetails($currentMovie['imdb_id']);
                    // Ensure genre/runtime variables always defined
                    // already prepared above
                    $runtimeStr = $omdbDetails['Runtime'] ?? '';
                    $genrePage = ((($currentMovie['type'] ?? '') === 'tv') ? 'tv-series' : 'movies');
    
                    }
                    ?>

                    <?php if ($currentMovie): ?>
                        <div class="watch-layout">
                            <div class="watch-player">
                                <h1 class="watch-title"><?= htmlspecialchars($currentMovie['title']) ?> (<?= htmlspecialchars($currentMovie['year']) ?>)</h1>
                                <?php
                                    $isTV = (($currentMovie['type'] ?? '') === 'tv');
                                    $season = isset($_GET['s']) ? (int)$_GET['s'] : 1;
                                    $episode = isset($_GET['e']) ? (int)$_GET['e'] : 1;

                                    $season = max(1, $season);
                                    $episode = max(1, $episode);

                                    $embedSrc = $PLAYER_SERVER1_BASE . "/embed/" . htmlspecialchars($currentMovie['imdb_id']);

                                    // TV embed uses TMDb + season/episode when available
                                    $tvTmdbId = null;
                                    $tmdbIdGeneral = null;
                                    $tvDetails = null;
                                    $foundGeneral = tmdbFindByImdbCached($currentMovie['imdb_id']);
                                    if (is_array($foundGeneral)) {
                                        if (!empty($foundGeneral['movie_results'][0]['id'])) $tmdbIdGeneral = (int)$foundGeneral['movie_results'][0]['id'];
                                        if (!empty($foundGeneral['tv_results'][0]['id'])) $tvTmdbId = (int)$foundGeneral['tv_results'][0]['id'];
                                    }
                                    $seasonDetails = null;
                                    $maxSeasons = 1;
                                    $maxEpisodes = 1;

                                    if ($isTV) {
                                        $tvTmdbId = tmdbTvIdByImdb($currentMovie['imdb_id']);
                                        if ($tvTmdbId) {
                                            // Vidsrc TV template (tmdb + season + episode)
                                            $embedSrc = $PLAYER_SERVER1_BASE . "/embed/tv?tmdb=" . urlencode($tvTmdbId) . "&season=" . urlencode($season) . "&episode=" . urlencode($episode);

                                            $tvDetails = tmdbTvDetailsByImdb($currentMovie['imdb_id']);
                                            if (is_array($tvDetails) && !empty($tvDetails['number_of_seasons'])) {
                                                $maxSeasons = max(1, (int)$tvDetails['number_of_seasons']);
                                            }

                                            $season = clampInt($season, 1, $maxSeasons);
                                            $seasonDetails = tmdbSeasonDetailsByImdb($currentMovie['imdb_id'], $season);
                                            if (is_array($seasonDetails) && !empty($seasonDetails['episodes']) && is_array($seasonDetails['episodes'])) {
                                                $maxEpisodes = max(1, count($seasonDetails['episodes']));
                                            } elseif (is_array($seasonDetails) && !empty($seasonDetails['episodes']) && is_numeric($seasonDetails['episodes'])) {
                                                // just in case
                                                $maxEpisodes = max(1, (int)$seasonDetails['episodes']);
                                            } else {
                                                // fallback if TMDb key missing or request fails
                                                $maxEpisodes = 50;
                                            }

                                            $episode = clampInt($episode, 1, $maxEpisodes);
                                            $embedSrc = $PLAYER_SERVER1_BASE . "/embed/tv?tmdb=" . urlencode($tvTmdbId) . "&season=" . urlencode($season) . "&episode=" . urlencode($episode);
                                        } else {
                                            // fallback if tmdb not available
                                            $maxSeasons = 10;
                                            $maxEpisodes = 50;
                                        }
                                    }
                                ?>
                                <?php
    // Server sources
    $isTV = (($currentMovie['type'] ?? '') === 'tv');

    // Build server buttons with one button per server, but each can have multiple URL patterns (auto-fallback)
    $serverButtons = [];

    // Server 1: VidSrc (IMDb)
    $serverButtons[] = [
        'id'    => 'server1Btn',
        'label' => 'Server 1',
        'server'=> 1,
        'srcs'  => [$embedSrc],
    ];

    // Server 2: VidKing (TMDb)
    $serverButtons[] = [
        'id'    => 'server2Btn',
        'label' => 'Server 2',
        'server'=> 2,
        'srcs'  => [
            $isTV
                ? ($PLAYER_SERVER2_BASE . "/embed/tv/" . urlencode((string)$tvTmdbId) . "/" . urlencode((string)$season) . "/" . urlencode((string)$episode) . "?episodeSelector=true&nextEpisode=true")
                : ($PLAYER_SERVER2_BASE . "/embed/movie/" . urlencode((string)$tmdbIdGeneral))
        ],
    ];

    // Server 3: Videasy (TMDb)
    $serverButtons[] = [
        'id'    => 'server3Btn',
        'label' => 'Server 3',
        'server'=> 3,
        'srcs'  => [
            $isTV
                ? ($PLAYER_SERVER3_BASE . "/tv/" . urlencode((string)$tvTmdbId) . "/" . urlencode((string)$season) . "/" . urlencode((string)$episode))
                : ($PLAYER_SERVER3_BASE . "/movie/" . urlencode((string)$tmdbIdGeneral))
        ],
    ];

    // Server 4: optional base — supports multiple patterns
    if (!empty($PLAYER_SERVER4_BASE)) {
        $base = rtrim((string)$PLAYER_SERVER4_BASE, '/');
        $srcs = [];
        if ($isTV) {
            $srcs[] = $base . "/embed/tv/" . urlencode((string)$tvTmdbId) . "/" . urlencode((string)$season) . "/" . urlencode((string)$episode);
            $srcs[] = $base . "/embed/tv?tmdb=" . urlencode((string)$tvTmdbId) . "&season=" . urlencode((string)$season) . "&episode=" . urlencode((string)$episode);
            // legacy fallback (some sites use /embed/{imdb})
            $srcs[] = $base . "/embed/" . htmlspecialchars($currentMovie['imdb_id']);
        } else {
            $srcs[] = $base . "/embed/movie/" . urlencode((string)$tmdbIdGeneral);
            $srcs[] = $base . "/embed/movie?imdb=" . urlencode((string)$currentMovie['imdb_id']);
            // legacy fallback
            $srcs[] = $base . "/embed/" . htmlspecialchars($currentMovie['imdb_id']);
        }
        $srcs = array_values(array_unique(array_filter($srcs)));
        if (!empty($srcs)) {
            $serverButtons[] = [
                'id'    => 'server4Btn',
                'label' => 'Server 4',
                'server'=> 4,
                'srcs'  => $srcs,
            ];
        }
    }

    // Custom servers (unlimited): one button per site address, auto-fallback using multiple patterns
    if (!empty($PLAYER_CUSTOM_SERVERS) && is_array($PLAYER_CUSTOM_SERVERS)) {
        $n = 5;
        foreach ($PLAYER_CUSTOM_SERVERS as $baseRaw) {
            $base = rtrim((string)$baseRaw, '/');
            if ($base === '') continue;

            $srcs = [];
            if ($isTV) {
                $srcs[] = $base . "/embed/tv/" . urlencode((string)$tvTmdbId) . "/" . urlencode((string)$season) . "/" . urlencode((string)$episode);
                $srcs[] = $base . "/embed/tv?tmdb=" . urlencode((string)$tvTmdbId) . "&season=" . urlencode((string)$season) . "&episode=" . urlencode((string)$episode);
                $srcs[] = $base . "/embed/" . htmlspecialchars($currentMovie['imdb_id']); // legacy fallback
            } else {
                $srcs[] = $base . "/embed/movie/" . urlencode((string)$tmdbIdGeneral);
                $srcs[] = $base . "/embed/movie?imdb=" . urlencode((string)$currentMovie['imdb_id']);
                $srcs[] = $base . "/embed/" . htmlspecialchars($currentMovie['imdb_id']); // legacy fallback
            }

            $srcs = array_values(array_unique(array_filter($srcs)));
            if (empty($srcs)) continue;

            $serverButtons[] = [
                'id'    => 'server' . $n . 'Btn',
                'label' => 'Server ' . $n,
                'server'=> $n,
                'srcs'  => $srcs,
            ];
            $n++;
        }
    }

?>
<div class="player-wrap">
    <iframe class="video-player"
        id="mainVideoPlayer"
        src="<?= $srcServer1 ?>"
        allowfullscreen></iframe>

    <div class="server-switch" aria-label="Video servers">
        <?php if (!empty($serverButtons)): ?>
            <?php foreach ($serverButtons as $idx => $sb): ?>
                <button
                    type="button"
                    class="server-btn <?= $idx === 0 ? 'active' : '' ?>"
                    id="<?= htmlspecialchars($sb['id']) ?>"
                    data-server="<?= (int)$sb['server'] ?>"
                    data-srcs='<?= htmlspecialchars(json_encode($sb['srcs'])) ?>'>
                    <?= htmlspecialchars($sb['label']) ?>
                </button>
            <?php endforeach; ?>
        <?php endif; ?>

        <span class="server-hint">If a server fails, switch here.</span>
    </div>
</div>

<script>
(function(){
  const iframe = document.getElementById('mainVideoPlayer');
  const buttons = Array.from(document.querySelectorAll('.server-switch .server-btn'));

  if(!iframe || buttons.length === 0) return;

  function setActive(btn){
    buttons.forEach(b => b.classList.remove('active'));
    btn && btn.classList.add('active');
  }

  function serverId(btn){
    return String(btn?.getAttribute('data-server') || btn?.dataset?.server || '1');
  }

  function getSrcList(btn){
    try{
      const raw = btn.getAttribute('data-srcs');
      const arr = JSON.parse(raw || '[]');
      return Array.isArray(arr) ? arr.filter(Boolean) : [];
    }catch(e){
      return [];
    }
  }

  function loadWithFallback(btn){
    if(!btn || btn.disabled) return;
    const srcs = getSrcList(btn);
    if(srcs.length === 0) return;

    setActive(btn);

    // persist chosen server in URL so episode navigation keeps it
    try{
      const url = new URL(window.location.href);
      url.searchParams.set('server', serverId(btn));
      window.history.replaceState({}, '', url.toString());
    }catch(e){}

    let i = 0;
    const tryLoad = () => {
      if(i >= srcs.length) return;
      iframe.src = srcs[i];

      // If it doesn't load within 6s, try next pattern
      let done = false;
      const timer = setTimeout(() => {
        if(done) return;
        done = true;
        i++;
        tryLoad();
      }, 6000);

      const onLoad = () => {
        if(done) return;
        done = true;
        clearTimeout(timer);
        iframe.removeEventListener('load', onLoad);
      };

      iframe.addEventListener('load', onLoad);
    };

    tryLoad();
  }

  // Restore server choice from URL
  try{
    const url = new URL(window.location.href);
    const s = url.searchParams.get('server') || '1';
    const match = buttons.find(b => serverId(b) === String(s));
    loadWithFallback(match || buttons[0]);
  }catch(e){
    loadWithFallback(buttons[0]);
  }

  // Manual switching
  buttons.forEach(btn => btn.addEventListener('click', () => loadWithFallback(btn)));

  // Auto-fallback between servers (not patterns): if first server doesn't load within 6s, click next server
  if(buttons.length >= 2){
    let tried = false;
    const timer = setTimeout(() => {
      if(tried) return;
      tried = true;
      loadWithFallback(buttons[1]);
    }, 6000);

    iframe.addEventListener('load', () => clearTimeout(timer), { once: true });
  }
})();
</script>

                                <?php if (!empty($isTV) && $isTV): ?>
                                    <div class="episode-controls" style="margin-top: 12px;">
                                        <div class="episode-controls-row">
                                            <label class="episode-label">Season</label>
                                            <select id="seasonSelect" class="episode-select">
                                                <?php for ($s = 1; $s <= (int)$maxSeasons; $s++): ?>
                                                    <option value="<?= $s ?>" <?= ($s === (int)$season) ? 'selected' : '' ?>>S<?= $s ?></option>
                                                <?php endfor; ?>
                                            </select>

                                            <label class="episode-label">Episode</label>
                                            <select id="episodeSelect" class="episode-select">
                                                <?php for ($e = 1; $e <= (int)$maxEpisodes; $e++): ?>
                                                    <option value="<?= $e ?>" <?= ($e === (int)$episode) ? 'selected' : '' ?>>E<?= $e ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>

                                        <?php
                                            $nextS = (int)$season;
                                            $nextE = (int)$episode + 1;

                                            if ($nextE > (int)$maxEpisodes) {
                                                $nextS = (int)$season + 1;
                                                $nextE = 1;
                                            }

                                            $hasNext = ($nextS <= (int)$maxSeasons);
                                            $baseUrl = ($_SERVER['PHP_SELF'] ?? "index.php") . "/watch/" . urlencode($currentMovie['imdb_id']);
                                            $curServer = isset($_GET['server']) ? (int)$_GET['server'] : 1;
                                            $curServer = ($curServer === 2) ? 2 : 1;
                                            $nextUrl = $baseUrl . "&s=" . urlencode($nextS) . "&e=" . urlencode($nextE) . "&server=" . urlencode($curServer);
                                        ?>

                                        <button class="btn btn-primary" id="nextEpisodeBtn" <?= $hasNext ? '' : 'disabled' ?> data-base-url="<?= htmlspecialchars($baseUrl) ?>" data-next-s="<?= (int)$nextS ?>" data-next-e="<?= (int)$nextE ?>">
                                            ⏭ Next Episode
                                        </button>
                                    </div>
                                <?php endif; ?>
                        <div style="margin-top: 1rem;">
                            <button class="watchlist-btn" id="watchlistBtn"
                                    data-movie-b64='<?= base64_encode(json_encode($currentMovie)) ?>'>
                                ⭐ Add to Watchlist
                            </button>

                            <button class="btn btn-outline" onclick="openReportModal()" style="margin-top: 10px; width: 100%;">
                                🚨 Report Broken Stream
                            </button>
                        
                                        <div class="share-row" style="margin-top: 10px;">
                                            <button class="share-btn fb" id="shareFbBtn">📘 Share</button>
                                            <button class="share-btn x" id="shareXBtn">𝕏 Share</button>
                                        </div>
</div>

                            </div>

<?php
                                $downloadRows = $currentMovie['downloads'] ?? [];
                            ?>
                            <?php if (!empty($downloadRows) && is_array($downloadRows)): ?>
                                <div class="downloads-section">
                                    <div class="downloads-table">
                                        <div class="drow dhead">
                                            <div>Server</div>
                                            <div>Password</div>
                                            <div>Quality</div>
                                            <div>Link</div>
                                        </div>
                                        <?php foreach ($downloadRows as $ri => $row): ?>
                                            <div class="drow">
                                                <div class="dcell server">
                                                    #<?= $ri + 1 ?> <?= htmlspecialchars($row['server'] ?? 'Server') ?>
                                                </div>
                                                <div class="dcell"><?= htmlspecialchars($row['password'] ?? '') ?></div>
                                                <div class="dcell"><?= htmlspecialchars($row['quality'] ?? 'HD') ?></div>
                                                <div class="dcell">
                                                    <?php if (!empty($row['url'])): ?>
                                                        <a class="download-link" href="<?= htmlspecialchars($row['url']) ?>" target="_blank" rel="noopener">Download</a>
                                                    <?php else: ?>
                                                        <span class="download-disabled">N/A</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="watch-details">

                                <div class="watch-details-grid">
                                    <div class="watch-poster-wrap">
                                        <img src="<?= htmlspecialchars($currentMovie['poster']) ?>" alt="<?= htmlspecialchars($currentMovie['title']) ?>" class="watch-poster">
                                        
                                    </div>

                                    <div class="watch-meta">
                                        <p class="watch-desc">
                                            <?php
                                                // Fetch details once and reuse across the watch page
                                                $omdbDetails = fetchOmdbDetails($currentMovie['imdb_id'] ?? '') ?: [];

                                                // Plot / description (fallback order: OMDb -> movies.json -> TMDb)
                                                $watchPlot = ($omdbDetails['Plot'] ?? '');
                                                if (!$watchPlot || strtoupper($watchPlot) === 'N/A') {
                                                    $watchPlot = $currentMovie['plot'] ?? '';
                                                }

                                                // If still missing, try TMDb overview (fast + cached helpers)
                                                if (!$watchPlot || strtoupper($watchPlot) === 'N/A') {
                                                    $found = tmdbFindByImdbCached($currentMovie['imdb_id']);
                                                    $isTv = (($currentMovie['type'] ?? '') === 'tv');
                                                    $tmdbId = null;
                                                    if ($isTv && !empty($found['tv_results'][0]['id'])) {
                                                        $tmdbId = (int)$found['tv_results'][0]['id'];
                                                    } elseif (!$isTv && !empty($found['movie_results'][0]['id'])) {
                                                        $tmdbId = (int)$found['movie_results'][0]['id'];
                                                    } else {
                                                        if (!empty($found['movie_results'][0]['id'])) $tmdbId = (int)$found['movie_results'][0]['id'];
                                                        if (!$tmdbId && !empty($found['tv_results'][0]['id'])) $tmdbId = (int)$found['tv_results'][0]['id'];
                                                    }
                                                    if ($tmdbId) {
                                                        $details = $isTv ? tmdbRequest('/tv/' . $tmdbId) : tmdbRequest('/movie/' . $tmdbId);
                                                        if (is_array($details) && !empty($details['overview'])) {
                                                            $watchPlot = (string)$details['overview'];
                                                        }
                                                    }
                                                }
                                            ?>
                                            <strong><?= htmlspecialchars($currentMovie['title']) ?></strong><br>
                                            <?= htmlspecialchars($watchPlot ?: 'No description available yet.') ?>
                                        </p>

                                        <?php
                                            // Sync Genre + Runtime + Plot (fallback to TMDb if OMDb returns N/A)
                                            $genreStr = $omdbDetails['Genre'] ?? '';
                                            $runtimeStr = $omdbDetails['Runtime'] ?? '';

                                            $needGenre = (!$genreStr || strtoupper($genreStr) === 'N/A');
                                            $needRuntime = (!$runtimeStr || strtoupper($runtimeStr) === 'N/A');
                                            $needPlot = (!$watchPlot || strtoupper($watchPlot) === 'N/A');

                                            if ($needGenre || $needRuntime || $needPlot) {
                                                $found = tmdbFindByImdbCached($currentMovie['imdb_id']);
                                                $isTv = (($currentMovie['type'] ?? '') === 'tv');

                                                $tmdbId = null;
                                                if ($isTv && !empty($found['tv_results'][0]['id'])) {
                                                    $tmdbId = (int)$found['tv_results'][0]['id'];
                                                } elseif (!$isTv && !empty($found['movie_results'][0]['id'])) {
                                                    $tmdbId = (int)$found['movie_results'][0]['id'];
                                                } else {
                                                    // fallback: try whichever exists
                                                    if (!empty($found['movie_results'][0]['id'])) $tmdbId = (int)$found['movie_results'][0]['id'];
                                                    if (!$tmdbId && !empty($found['tv_results'][0]['id'])) $tmdbId = (int)$found['tv_results'][0]['id'];
                                                }

                                                if ($tmdbId) {
                                                    $details = $isTv ? tmdbRequest('/tv/' . $tmdbId) : tmdbRequest('/movie/' . $tmdbId);

                                                    if ($needPlot && is_array($details) && !empty($details['overview'])) {
                                                        $watchPlot = (string)$details['overview'];
                                                    }

                                                    if ($needGenre && is_array($details) && !empty($details['genres']) && is_array($details['genres'])) {
                                                        $names = [];
                                                        foreach ($details['genres'] as $g) {
                                                            if (!empty($g['name'])) $names[] = $g['name'];
                                                        }
                                                        if (!empty($names)) $genreStr = implode(', ', $names);
                                                    }

                                                    if ($needRuntime && is_array($details)) {
                                                        if (!$isTv && !empty($details['runtime']) && is_numeric($details['runtime'])) {
                                                            $runtimeStr = (int)$details['runtime'] . ' min';
                                                        } elseif ($isTv && !empty($details['episode_run_time']) && is_array($details['episode_run_time'])) {
                                                            $rt = (int)($details['episode_run_time'][0] ?? 0);
                                                            if ($rt > 0) $runtimeStr = $rt . ' min';
                                                        }
                                                    }
                                                }
                                            }

                                            if (!$runtimeStr || strtoupper($runtimeStr) === 'N/A') $runtimeStr = 'N/A';
                                            $genrePage = (($currentMovie['type'] ?? '') === 'tv') ? 'tv-series' : 'movies';
                                        ?>


                                        <?php
                                            // Bulletproof defaults (prevents Undefined variable warnings)
                                            if (!isset($genreStr)) $genreStr = ($omdbDetails['Genre'] ?? '');
                                            if (!isset($runtimeStr)) $runtimeStr = ($omdbDetails['Runtime'] ?? '');
                                            if (!isset($genrePage)) $genrePage = ((($currentMovie['type'] ?? '') === 'tv') ? 'tv-series' : 'movies');
                                            if (!$runtimeStr || strtoupper($runtimeStr) === 'N/A') $runtimeStr = 'N/A';
                                        ?>

                                        <div class="watch-meta-grid">
                                            <div><span>Type:</span> <?= strtoupper(htmlspecialchars($currentMovie['type'])) ?></div>
                                            <div><span>Year:</span> <?= htmlspecialchars($currentMovie['year']) ?></div>
                                            <div><span>IMDb ID:</span> <?= htmlspecialchars($currentMovie['imdb_id']) ?></div>
                                            <div><span>Rating:</span> ⭐ <?= htmlspecialchars($currentMovie['rating']) ?></div>
                                            <?php
                                                    // Guard defaults in case upstream sync block didn't run
                                                    if (!isset($genreStr)) $genreStr = ($omdbDetails['Genre'] ?? '');
                                                    if (!isset($runtimeStr)) $runtimeStr = ($omdbDetails['Runtime'] ?? '');
                                                    if (!isset($genrePage)) $genrePage = ((($currentMovie['type'] ?? '') === 'tv') ? 'tv-series' : 'movies');
                                                ?>
                                            <div><span>Genre:</span>
                                                <?php
                                                    // Use the synced/fallback genre string (OMDb -> TMDb)
                                                    if (!$genreStr || strtoupper($genreStr) === 'N/A') {
                                                        echo 'N/A';
                                                    } else {
                                                        $genres = array_values(array_filter(array_map('trim', explode(',', $genreStr))));
                                                        foreach ($genres as $i => $g) {
                                                            $url = 'index.php?page=' . $genrePage . '&genre=' . urlencode($g);
                                                            echo '<a class="genre-link" href="' . $url . '">' . htmlspecialchars($g) . '</a>';
                                                            if ($i < count($genres)-1) echo ', ';
                                                        }
                                                    }
                                                ?>
                                            </div>
                                            <div><span>Quality:</span> HD</div>
                                            <div><span>Runtime:</span> <?= htmlspecialchars($runtimeStr ?: "N/A") ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php
                                $tmdbCredits = tmdbCreditsByImdb($currentMovie['imdb_id'], $currentMovie['type'] === 'tv' ? 'tv' : 'movie');
                                $cast = [];
                                if ($tmdbCredits && !empty($tmdbCredits['cast'])) {
                                    $cast = array_slice($tmdbCredits['cast'], 0, 18); // top cast
                                }
                            ?>

                            <?php if (!empty($cast)): ?>
                                <div class="watch-actors-section">
                                    <div class="actors-head">
                                        <h2 class="actors-title">🎭 Cast</h2>
                                        <div class="actors-sub">Tap an actor to filter titles</div>
                                    </div>

                                    <div class="actors-row">
                                        <?php foreach ($cast as $p): ?>
                                            <?php
                                                $actorName = $p['name'] ?? '';
                                                $character = $p['character'] ?? '';
                                                $profile = $p['profile_path'] ?? '';
                                                $img = $profile ? ("https://image.tmdb.org/t/p/w185" . $profile) : "";
                                                $targetPage = ($currentMovie['type'] === 'tv') ? 'tv-series' : 'movies';
                                                $href = "index.php?page=" . $targetPage . "&actor=" . urlencode($actorName);
                                            ?>
                                            <a class="actor-card" href="<?= htmlspecialchars($href) ?>" title="Filter by <?= htmlspecialchars($actorName) ?>">
                                                <div class="actor-photo">
                                                    <?php if ($img): ?>
                                                        <img loading="lazy" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($actorName) ?>">
                                                    <?php else: ?>
                                                        <div class="actor-photo-fallback">👤</div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="actor-name"><?= htmlspecialchars($actorName) ?></div>
                                                <?php if ($character): ?>
                                                    <div class="actor-role">as <?= htmlspecialchars($character) ?></div>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php elseif (!empty($omdbDetails['Actors']) && strtoupper($omdbDetails['Actors']) !== 'N/A'): ?>
                                <div class="watch-actors-section">
                                    <div class="actors-head">
                                        <h2 class="actors-title">🎭 Actors</h2>
                                    </div>
                                    <div class="actors-row">
                                        <?php
                                            $actors = array_map('trim', explode(',', $omdbDetails['Actors']));
                                            foreach ($actors as $actor):
                                                $targetPage = ($currentMovie['type'] === 'tv') ? 'tv-series' : 'movies';
                                                $href = "index.php?page=" . $targetPage . "&actor=" . urlencode($actor);
                                        ?>
                                            <a class="actor-card" href="<?= htmlspecialchars($href) ?>">
                                                <div class="actor-photo"><div class="actor-photo-fallback">👤</div></div>
                                                <div class="actor-name"><?= htmlspecialchars($actor) ?></div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>


                            <?php
                            // Related: same type first, fallback to any
                            $related = [];
                            foreach ($movies as $m) {
                                if ($m['imdb_id'] === $currentMovie['imdb_id']) continue;
                                if ($m['type'] === $currentMovie['type']) $related[] = $m;
                            }
                            if (count($related) < 5) {
                                foreach ($movies as $m) {
                                    if ($m['imdb_id'] === $currentMovie['imdb_id']) continue;
                                    if ($m['type'] !== $currentMovie['type']) $related[] = $m;
                                }
                            }
                            shuffle($related);
                            $related = array_slice($related, 0, 5);
                            ?>
                            



                            
                            <?php
                            // Load comments for this imdb
                            $commentsFile = __DIR__ . '/comments.json';
                            if (!file_exists($commentsFile)) { file_put_contents($commentsFile, "[]"); }
                            $commentsRaw = file_get_contents($commentsFile);
                            $allComments = json_decode($commentsRaw, true);
                            if (!is_array($allComments)) $allComments = [];
                            $comments = array_values(array_filter($allComments, function($c) use ($currentMovie){
                                return isset($c['imdb_id']) && $c['imdb_id'] === $currentMovie['imdb_id'];
                            }));
                            $comments = array_slice($comments, 0, 30);
                            $isLogged = isset($_SESSION['user']) && !empty($_SESSION['user']['email']);
                            $userProvider = strtolower($_SESSION['user']['provider'] ?? 'local');
                            $profileEmoji = '👤';
                            if ($userProvider === 'facebook') $profileEmoji = '📘';
                            if ($userProvider === 'gmail') $profileEmoji = '📧';
                            ?>

                            <div class="watch-extras">
                                <div class="extras-topbar">
                                    <div class="extras-left">
                                        <div class="extras-title">💬 Comments</div>
                                        <div class="extras-subtitle">Join the discussion (login required to comment & like)</div>
                                    </div>

                                    <div class="extras-right">
                                        <?php if ($isLogged): ?>
                                            <div class="user-pill">
                                                <span class="emoji" aria-hidden="true"><?= $profileEmoji ?></span>
                                                <span class="name"><?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?></span>
                                                <span class="provider"><?= htmlspecialchars($_SESSION['user']['provider'] ?? 'local') ?></span>
                                                <button class="link-btn" onclick="logoutUser()">Logout</button>
                                            </div>
                                        <?php else: ?>
                                            <div class="login-actions">
                                                <button class="auth-pill auth-login" onclick="openAuthModal('login')">🔑 Login</button>
                                                <button class="auth-pill auth-signup" onclick="openAuthModal('signup')">✨ Sign up</button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="comments-section">
<?php if ($isLogged): ?>
                                        <div class="comment-box">
                                            <textarea id="commentText" placeholder="Write a comment..."></textarea>
                                            <div class="comment-actions">
                                                <button class="btn" onclick="submitComment('<?= htmlspecialchars($currentMovie['imdb_id']) ?>')">Post Comment</button>
                                                <span id="commentMsg" class="muted"></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="comment-locked">
                                            🔒 Please <strong>login</strong> or <strong>sign up</strong> to comment.
                                        </div>
                                    <?php endif; ?>

                                    <div class="comment-list">
                                        <?php if (count($comments) === 0): ?>
                                            <div class="empty-note">No comments yet. Be the first!</div>
                                        <?php else: ?>
                                            <?php foreach ($comments as $c): ?>
                                                <div class="comment-item">
                                                    <div class="avatar"><?= strtoupper(substr(htmlspecialchars($c['name'] ?? 'U'), 0, 1)) ?></div>
                                                    <div class="comment-body">
                                                        <div class="comment-meta">
                                                            <strong><?= htmlspecialchars($c['name'] ?? 'User') ?></strong>
                                                            <span class="tag"><?= htmlspecialchars($c['provider'] ?? 'gmail') ?></span>
                                                            <span class="muted"><?= htmlspecialchars(date('M d, Y • h:i A', strtotime($c['created_at'] ?? 'now'))) ?></span>
                                                        </div>
                                                        <div class="comment-text"><?= nl2br(htmlspecialchars($c['comment'] ?? '')) ?></div>
                                                        <div class="comment-footer">
                                                            <?php
                                                                $likedBy = $c['liked_by'] ?? [];
                                                                if (!is_array($likedBy)) $likedBy = [];
                                                                $likeCount = count($likedBy);
                                                                $userEmail = $_SESSION['user']['email'] ?? '';
                                                                $hasLiked = ($isLogged && $userEmail && in_array($userEmail, $likedBy, true));
                                                            ?>
                                                            <?php if ($isLogged): ?>
                                                                <button class="cbtn <?= $hasLiked ? 'disabled' : '' ?>"
                                                                        data-comment-id="<?= htmlspecialchars($c['id'] ?? '') ?>"
                                                                        data-liked="<?= $hasLiked ? '1' : '0' ?>"
                                                                        onclick="toggleLike('<?= htmlspecialchars($c['id'] ?? '') ?>', this)"
                                                                        <?= $hasLiked ? 'disabled aria-disabled="true" title="You already liked this"' : 'title="Like this comment"' ?>>
                                                                    <?= $hasLiked ? '✅ Liked' : '👍 Like' ?>
                                                                </button>
                                                            <?php else: ?>
                                                                <button class="cbtn disabled" onclick="promptLogin()" title="Login required" aria-disabled="true">🔒 Like</button>
                                                            <?php endif; ?>
                                                            <span class="like-count" id="likeCount_<?= htmlspecialchars($c['id'] ?? '') ?>"><?= $likeCount ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <script>
                                window.FLIXMO_LOGGED_IN = <?= $isLogged ? 'true' : 'false' ?>;
                            </script>

                            <!-- Login Modal -->
                            <div id="loginModal" class="modal-overlay" style="display:none;">
                                <div class="modal-card auth-card">
                                    <div class="modal-head auth-head">
                                        <div class="auth-badge">🔒 Secure account</div>
                                        <button class="xbtn" onclick="closeLoginModal()" aria-label="Close">✕</button>
                                    </div>

                                    <div class="auth-top">
                                        <h3 id="authTitle">Welcome back</h3>
                                        <div class="auth-tabs" role="tablist" aria-label="Auth tabs">
                                            <button id="authTabLogin" class="auth-tab active" onclick="openAuthModal('login')" type="button">Login</button>
                                            <button id="authTabSignup" class="auth-tab" onclick="openAuthModal('signup')" type="button">Sign up</button>
                                        </div>
                                    </div>

                                    <div class="modal-body auth-body">
                                        <div id="authNameWrap" style="display:none;">
                                            <label class="auth-label">Name</label>
                                            <input id="authName" class="auth-input" type="text" placeholder="e.g., Juan Dela Cruz" autocomplete="name" />
                                        </div>

                                        <label class="auth-label">Email</label>
                                        <input id="authEmail" class="auth-input" type="email" placeholder="you@example.com" autocomplete="email" />

                                        <label class="auth-label">Password</label>
                                        <input id="authPassword" class="auth-input" type="password" placeholder="••••••••" autocomplete="current-password" />

                                        <div id="authConfirmWrap" style="display:none;">
                                            <label class="auth-label">Confirm password</label>
                                            <input id="authConfirm" class="auth-input" type="password" placeholder="••••••••" autocomplete="new-password" />
                                        </div>

                                        <div class="auth-actions">
                                            <button id="authCta" class="btn btn-auth" onclick="doAuth()">Login</button>
                                            <button class="btn btn-ghost" onclick="closeLoginModal()">Cancel</button>
                                        </div>

                                        <div class="auth-footnote">
                                            By continuing, you agree to keep your account details private.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Report Modal -->
                            <div id="reportModal" class="modal-overlay" style="display:none;">
                                <div class="modal-card">
                                    <div class="modal-head">
                                        <h3>🚨 Report Broken Stream</h3>
                                        <button class="xbtn" onclick="closeReportModal()">✕</button>
                                    </div>
                                    <div class="modal-body">
                                        <select id="reportReason">
                                            <option value="Not playing">Not playing</option>
                                            <option value="Broken link">Broken link</option>
                                            <option value="Wrong movie/episode">Wrong movie/episode</option>
                                            <option value="Loading forever">Loading forever</option>
                                            <option value="Audio issue">Audio issue</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <textarea id="reportOther" placeholder="Extra details (optional)"></textarea>
                                        <div class="modal-actions">
                                            <button class="btn danger" onclick="submitReport('<?= htmlspecialchars($currentMovie['imdb_id']) ?>', '<?= htmlspecialchars(addslashes($currentMovie['title'])) ?>', '<?= htmlspecialchars($currentMovie['type']) ?>')">Submit Report</button>
                                            <button class="btn btn-outline" onclick="closeReportModal()">Cancel</button>
                                        </div>
                                        <div id="reportMsg" class="muted" style="margin-top:10px;"></div>
                                    </div>
                                </div>
                            </div>


<div class="related-section">
                                <div class="section-header">
                                    <h2 class="related-title">Related <?= (in_array(strtolower($currentMovie['type']), ['tv','series','tv-series']) || stripos(strtolower($currentMovie['type']), 'tv') !== false || stripos(strtolower($currentMovie['type']), 'series') !== false) ? 'TV' : 'Movies' ?> 🔥</h2>
	</div>
	                                <?php if (!empty($ZP_ADS['watch_after_related'])): ?>
	                                    <div class="ad-slot ad-watch-after-related">
	                                        <?= $ZP_ADS['watch_after_related'] ?>
	                                    </div>
	                                <?php endif; ?>

                                <div class="related-grid">
                                    <?php foreach ($related as $rel): ?>
                                        <div class="movie-card" data-platform="<?= htmlspecialchars($movie['platform'] ?? 'all') ?>" onclick="watchMovie('<?= htmlspecialchars($rel['imdb_id']) ?>')">
                                            <img loading="lazy" src="<?= htmlspecialchars(zp_poster_or_placeholder($rel['poster'] ?? '')) ?>" alt="<?= htmlspecialchars($rel['title'] ?? '') ?>" class="movie-poster" onerror="this.onerror=null;this.src='<?= htmlspecialchars(zp_poster_or_placeholder('')) ?>';">
                                            <div class="movie-info">
                                                <h3 class="movie-title"><?= htmlspecialchars($rel['title']) ?></h3>
                                                <p class="movie-year"><?= htmlspecialchars($rel['year']) ?> • ⭐ <?= htmlspecialchars($rel['rating']) ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <h1>Movie not found</h1>
                    <?php endif; ?>
                </div>

            <?php elseif ($page == 'request'): ?>
                <div class="page-content">
                    <h1>🎬 Request a Movie or TV Series</h1>
                    <p>Enter the <b>Title</b> and <b>Year</b>. We will auto-check IMDb; if it already exists, we'll tell you. If not, your request will be queued for admin review (auto-delete after 24 hours).</p>

                    <div id="requestStatus" style="margin-top: 1rem; display:none; padding: 12px 14px; border-radius: 12px; background: rgba(255,255,255,0.08);"></div>

                    <form id="requestForm" style="margin-top: 1.5rem;">
                        <input id="reqTitle" type="text" class="search-input" placeholder="Title (required)..." style="width: 100%; margin-bottom: 1rem;" required>
                        <input id="reqYear" type="number" class="search-input" placeholder="Year (required) e.g. 2023" style="width: 100%; margin-bottom: 1rem;" min="1900" max="2100" required>
                        <div class="request-actions">
                            <button type="submit" class="search-btn">📝 Submit Request</button>
                            <button type="button" id="cancelBtn" class="search-btn cancel-btn" style="display:none; opacity: 0.85;">🗑️ Delete Request</button>
                        </div>
                    </form>

                    <div id="imdbInfo" style="margin-top: 1rem;"></div>
                </div>

                <script>
                (function(){
                    const form = document.getElementById('requestForm');
                    const statusBox = document.getElementById('requestStatus');
                    const imdbInfo = document.getElementById('imdbInfo');
                    const cancelBtn = document.getElementById('cancelBtn');

                    function showStatus(msg){
                        statusBox.style.display = 'block';
                        statusBox.innerHTML = msg;
                    }

                    function setCancelVisible(visible){
                        cancelBtn.style.display = visible ? 'inline-block' : 'none';
                    }

                    // Restore last queued request id (per session)
                    let lastRequestId = sessionStorage.getItem('flixmo_last_request_id');
                    if (lastRequestId) setCancelVisible(true);

                    cancelBtn.addEventListener('click', async function(){
                        if (!lastRequestId) return;
                        try {
                            const fd = new FormData();
                            fd.append('request_id', lastRequestId);
                            const res = await fetch('request_delete.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            if (data.ok){
                                showStatus('✅ ' + data.message);
                                sessionStorage.removeItem('flixmo_last_request_id');
                                lastRequestId = null;
                                setCancelVisible(false);
                            } else {
                                showStatus('⚠️ ' + (data.message || 'Failed to delete request'));
                            }
                        } catch(e){
                            showStatus('⚠️ Network error while deleting request.');
                        }
                    });

                    form.addEventListener('submit', async function(e){
                        e.preventDefault();
                        imdbInfo.innerHTML = '';
                        setCancelVisible(false);

                        const title = document.getElementById('reqTitle').value.trim();
                        const year = document.getElementById('reqYear').value.trim();

                        if (!title || !year){
                            showStatus('⚠️ Title and Year are required.');
                            return;
                        }

                        try{
                            const fd = new FormData();
                            fd.append('title', title);
                            fd.append('year', year);

                            const res = await fetch('request_submit.php', { method: 'POST', body: fd });
                            const data = await res.json();

                            if (!data.ok){
                                showStatus('⚠️ ' + (data.message || 'Request failed.'));
                                return;
                            }

                            if (data.status === 'exists_local'){
                                showStatus('✅ ' + data.message);
                                return;
                            }

                            if (data.status === 'exists_imdb'){
                                showStatus('✅ ' + data.message);
                                if (data.imdb){
                                    const it = data.imdb;
                                    let html = '<div style="margin-top:10px; padding:12px 14px; border-radius:12px; background: rgba(255,255,255,0.06);">';
                                    html += '<b>Matched:</b> ' + (it.title || title) + ' (' + (it.year || year) + ')';
                                    if (it.imdb_id) html += '<br><b>IMDb ID:</b> ' + it.imdb_id;
                                    if (it.type) html += '<br><b>Type:</b> ' + it.type;
                                    if (it.poster) html += '<br><img src="' + it.poster + '" alt="Poster" style="max-width:160px; margin-top:10px; border-radius:10px;">';
                                    html += '</div>';
                                    imdbInfo.innerHTML = html;
                                }
                                return;
                            }

                            if (data.status === 'queued'){
                                showStatus('🕒 ' + data.message);
                                if (data.imdb){
                                    const it = data.imdb;
                                    let html = '<div style="margin-top:10px; padding:12px 14px; border-radius:12px; background: rgba(255,255,255,0.06);">';
                                    html += '<b>Matched:</b> ' + (it.title || title) + ' (' + (it.year || year) + ')';
                                    if (it.imdb_id) html += '<br><b>IMDb ID:</b> ' + it.imdb_id;
                                    if (it.type) html += '<br><b>Type:</b> ' + it.type;
                                    if (it.poster) html += '<br><img src="' + it.poster + '" alt="Poster" style="max-width:160px; margin-top:10px; border-radius:10px;">';
                                    html += '</div>';
                                    imdbInfo.innerHTML = html;
                                }
                                if (data.request_id){
                                    lastRequestId = data.request_id;
                                    sessionStorage.setItem('flixmo_last_request_id', lastRequestId);
                                    setCancelVisible(true);
                                }
                                return;
                            }

                            showStatus('✅ Done.');
                        } catch(err){
                            showStatus('⚠️ Network error. Please try again.');
                        }
                    });
                })();
                </script>

            <?php elseif ($page == 'watchlist'): ?>
                <div class="page-content">
                    <h1>⭐ Your Watchlist</h1>
                    <p>Your saved movies and TV series will appear here.</p>
                    <div id="watchlistContainer" class="content-grid" style="margin-top: 2rem;">
                        <!-- Watchlist items will be loaded here -->
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <!-- Statistics Section -->
            <div class="footer-stats">
                <div class="stat-item">
                    <span class="stat-number"><?= count($moviesList) ?></span>
                    <span class="stat-label">Movies</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= count($tvShowsList) ?></span>
                    <span class="stat-label">TV Series</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?= count($movies) ?></span>
                    <span class="stat-label">Total Content</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">24/7</span>
                    <span class="stat-label">Available</span>
                </div>
            </div>

            <!-- Footer Content -->
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <?php if (!empty($hasSiteLogo)): ?>
                            <img src="<?php echo htmlspecialchars($siteLogoPath); ?>" alt="FlixMo" class="footer-logo-img">
                        <?php else: ?>
                            🎬 FlixMo
                        <?php endif; ?>
                    </div>
                    <p>Your ultimate destination for movies and TV series. Stream your favorite content anytime, anywhere with high-quality viewing experience.</p>
                    <div class="social-links">
                        <a href="#" title="Facebook">📘</a>
                        <a href="#" title="Twitter">🐦</a>
                        <a href="#" title="Instagram">📷</a>
                        <a href="#" title="YouTube">📺</a>
                    </div>
                </div>

                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="/">🏠 Home</a></li>
                        <li><a href="/movies">🎬 Movies</a></li>
                        <li><a href="/tv">📺 TV Series</a></li>
                        <li><a href="/request">📝 Request Content</a></li>
                        <li><a href="/watchlist">⭐ My Watchlist</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3>Categories</h3>
                    <ul class="footer-links">
                        <li><a href="index.php?page=movies&genre=Action">🎭 Action</a></li>
                        <li><a href="index.php?page=movies&genre=Romance">💝 Romance</a></li>
                        <li><a href="index.php?page=movies&genre=Comedy">😂 Comedy</a></li>
                        <li><a href="index.php?page=movies&genre=Horror">👻 Horror</a></li>
                        <li><a href="index.php?page=movies&genre=Sci-Fi">🚀 Sci-Fi</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3>Support</h3>
                    <ul class="footer-links">
                        <li><a href="#">❓ Help Center</a></li>
                        <li><a href="#">📞 Contact Us</a></li>
                        <li><a href="#">🔒 Privacy Policy</a></li>
                        <li><a href="#">📋 Terms of Service</a></li>
                        <li><a href="#">🛡️ DMCA</a></li>
                    </ul>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="footer-bottom">
                <p>&copy; 2024 Zenktx. All rights reserved. | Made with ❤️ for movie lovers</p>
            </div>
        </div>
    </footer>

    <a href="admin/login.php" class="admin-link" title="Admin Panel">⚙️</a>

    <script>
        // Mobile menu toggle functionality
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const navMenu = document.getElementById('navMenu');
        const navOverlay = document.getElementById('navOverlay');
        const body = document.body;

        mobileMenuToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            mobileMenuToggle.classList.toggle('active');
            body.classList.toggle('menu-open');
        });

        // Close menu when clicking on overlay
        navOverlay.addEventListener('click', function() {
            navMenu.classList.remove('active');
            mobileMenuToggle.classList.remove('active');
            body.classList.remove('menu-open');
        });

        // Close menu when clicking on nav links (mobile/tablet slide menu)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 1024) {
                    navMenu.classList.remove('active');
                    mobileMenuToggle.classList.remove('active');
                    body.classList.remove('menu-open');
                }
            });
        });

        // Search functionality
        function searchContent() {
            const query = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.movie-card');
            
            cards.forEach(card => {
                const title = card.querySelector('.movie-title').textContent.toLowerCase();
                if (title.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function watchMovie(imdbId) {
            window.location.href = `/watch/${imdbId}`;
        }

        // Real-time search
        document.getElementById('searchInput')?.addEventListener('input', searchContent);

        // Close slide menu on window resize (back to desktop nav)
        window.addEventListener('resize', function() {
            if (window.innerWidth > 1024) {
                navMenu.classList.remove('active');
                mobileMenuToggle.classList.remove('active');
                body.classList.remove('menu-open');
            }
        });
    </script>

    <script src="js/script.js?v=<?php echo $asset_v_script; ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('watchlistBtn');
    const msg = document.getElementById('watchlistMsg');
    if(!btn) return;

    btn.addEventListener('click', function(){
        try{
            const b64 = btn.getAttribute('data-movie-b64');
            if(!b64) return;

            const movieData = JSON.parse(atob(b64));

            // load existing
            let list = [];
            try{
                list = JSON.parse(localStorage.getItem('flixmo_watchlist')) || [];
            }catch(e){
                list = [];
            }

            const exists = list.some(x => x.imdb_id === movieData.imdb_id);

            if(!exists){
                list.push(movieData);
                localStorage.setItem('flixmo_watchlist', JSON.stringify(list));
            }

            if(msg){
                msg.textContent = exists ? 'ℹ️ Already in Watchlist.' : '✅ Added to Watchlist!';
                msg.classList.add('show');
            }

            btn.textContent = exists ? '⭐ In Watchlist' : '✅ Added';
            btn.style.opacity = '0.95';

            setTimeout(() => {
                window.location.href = 'index.php?page=watchlist';
            }, 900);

        }catch(err){
            console.error(err);
            if(msg){
                msg.textContent = '⚠️ Could not add to Watchlist.';
                msg.classList.add('show');
            }
        }
    });
});
</script>


<script>
/* --- Mini lists (Most Watched / Top Rated) dynamic --- */
(function(){
  const allItems = <?php echo json_encode(array_values($movies)); ?>;

  // Track views when watching
  const origWatchMovie = window.watchMovie;
  window.watchMovie = function(imdbId){
    try{
      const key = "flixmo_views_" + imdbId;
      const cur = parseInt(localStorage.getItem(key) || "0", 10);
      localStorage.setItem(key, String(cur + 1));
    }catch(e){}
    origWatchMovie(imdbId);
  };

  function getViewCount(imdbId){
    try{
      return parseInt(localStorage.getItem("flixmo_views_" + imdbId) || "0", 10);
    }catch(e){ return 0; }
  }

  function topMostWatched(filterType){
    const items = allItems.filter(it => !filterType || it.type === filterType);
    items.sort((a,b) => getViewCount(b.imdb_id) - getViewCount(a.imdb_id));
    return items.slice(0,3);
  }

  function topRated(filterType){
    const items = allItems.filter(it => !filterType || it.type === filterType);
    items.sort((a,b) => parseFloat(b.rating || 0) - parseFloat(a.rating || 0));
    return items.slice(0,3);
  }

  function renderList(ulId, items){
    const ul = document.getElementById(ulId);
    if(!ul) return;

    const lis = ul.querySelectorAll("li");
    items.forEach((it, idx) => {
      const li = lis[idx];
      if(!li) return;

      const btn = li.querySelector(".mini-link");
      const name = li.querySelector(".mini-name");

      if(btn){
        btn.dataset.imdb = it.imdb_id;
        btn.onclick = function(e){
          e.preventDefault();
          watchMovie(it.imdb_id);
        };
      }
      if(name){
        name.textContent = it.title;
      }
    });
  }

  // HOME: mix movies + tv
  renderList("home-most-watched", topMostWatched(null));
  renderList("home-top-rated", topRated(null));
})();
</script>



<script>
/* --- Hero poster carousel (random) --- */
(function(){
  const allItems = <?php echo json_encode(array_values($movies)); ?>;

  function shuffle(arr){
    const a = arr.slice();
    for(let i=a.length-1;i>0;i--){
      const j = Math.floor(Math.random() * (i+1));
      [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
  }

  function pickPosters(type){
    const items = allItems.filter(it => it.poster && (!type || it.type === type));
    return shuffle(items).slice(0, 10);
  }

  function setPoster(el, item){
    if(!el || !item) return;
    el.innerHTML = `
      <img loading="lazy" src="${item.poster}" alt="${item.title}">
      <div class="poster-tag">${item.title}</div>
    `;
    el.onclick = function(e){
      e.preventDefault();
      watchMovie(item.imdb_id);
    };
    el.style.cursor = "pointer";
  }

  function startCarousel(leftId, rightId, posters){
    const left = document.getElementById(leftId);
    const right = document.getElementById(rightId);
    if(!left || !right) return;
    if(!posters || posters.length < 2) return;

    let i = 0;
    let j = 1;

    setPoster(left, posters[i]);
    setPoster(right, posters[j]);

    setInterval(() => {
      i = (i + 1) % posters.length;
      j = (j + 1) % posters.length;
      setPoster(left, posters[i]);
      setPoster(right, posters[j]);
    }, 3500);
  }

  function init(){
    // HOME: mix movies + tv
    startCarousel("heroPosterLeft", "heroPosterRight", pickPosters(null));

    // MOVIES: movies only
    startCarousel("moviesPosterLeft", "moviesPosterRight", pickPosters("movie"));

    // TV: tv only
    startCarousel("tvPosterLeft", "tvPosterRight", pickPosters("tv"));
  }

  if(document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", init);
  }else{
    init();
  }
})();
</script>

  <script src="assets/infinite.js"></script>

<script>
(function(){
  const seasonSel = document.getElementById('seasonSelect');
  const epSel = document.getElementById('episodeSelect');
  const nextBtn = document.getElementById('nextEpisodeBtn');

  function buildUrl(season, episode){
    const url = new URL(window.location.href);
    url.searchParams.set('s', String(season));
    url.searchParams.set('e', String(episode));
    // keep chosen server (read from active server button)
    const activeBtn = document.querySelector('.server-switch .server-btn.active');
    const sv = activeBtn ? String(activeBtn.getAttribute('data-server') || activeBtn.dataset.server || '1') : '1';
    url.searchParams.set('server', sv);
    return url.toString();
  }

  function go(){
    if(!seasonSel || !epSel) return;
    const s = seasonSel.value || '1';
    const e = epSel.value || '1';
    window.location.href = buildUrl(s, e);
  }

  if(seasonSel){
    seasonSel.addEventListener('change', () => {
      // reset episode to 1 when season changes
      if(epSel) epSel.value = '1';
      go();
    });
  }
  if(epSel){
    epSel.addEventListener('change', go);
  }
  if(nextBtn){
    nextBtn.addEventListener('click', () => {
      if(nextBtn.disabled) return;

      const ns = nextBtn.getAttribute('data-next-s') || '1';
      const ne = nextBtn.getAttribute('data-next-e') || '1';

      try{
        const url = new URL(window.location.href);
        url.searchParams.set('s', String(ns));
        url.searchParams.set('e', String(ne));

        const serverBtn2 = document.getElementById('server2Btn');
        const use2 = serverBtn2 && serverBtn2.classList.contains('active');
        url.searchParams.set('server', use2 ? '2' : '1');

        window.location.href = url.toString();
      }catch(e){
        window.location.href = window.location.pathname + '?s=' + ns + '&e=' + ne + '&server=1';
      }
    });
  }})();
</script>

</body>
</html>