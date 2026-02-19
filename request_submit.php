<?php
// request_submit.php - Handle request submissions (AJAX)
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib/requests.php';

function respond($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

$title = trim($_POST['title'] ?? '');
$year  = trim($_POST['year'] ?? '');

if ($title === '' || $year === '') {
    respond(['ok' => false, 'message' => 'Title and Year are required.'], 400);
}

if (!preg_match('/^\d{4}$/', $year)) {
    respond(['ok' => false, 'message' => 'Year must be a 4-digit number.'], 400);
}

// 1) Quick check: if already exists in local library (movies.json)
if (file_exists(__DIR__ . '/movies.json')) {
    $movies = json_decode(file_get_contents(__DIR__ . '/movies.json'), true) ?: [];
    foreach ($movies as $m) {
        $mt = isset($m['title']) ? mb_strtolower(trim($m['title'])) : '';
        $my = isset($m['year']) ? (string)$m['year'] : '';
        if ($mt === mb_strtolower($title) && $my === (string)$year) {
            respond([
                'ok' => true,
                'status' => 'exists_local',
                'message' => 'Meron na sa library (already added).'
            ]);
        }
    }
}

// 2) Validate against IMDb/OMDb first
$check = imdbExists($title, $year);

if (($check['source'] ?? '') === 'imdb_error') {
    respond([
        'ok' => false,
        'status' => 'imdb_error',
        'message' => 'Hindi ma-check ang IMDb ngayon (network blocked). Please try again later or configure OMDB_API_KEY.'
    ], 503);
}

// If NOT found => do NOT submit request
if (!$check['found']) {
    respond([
        'ok' => false,
        'status' => 'not_found',
        'message' => 'Walang match sa IMDb. Please check Title at Year.'
    ], 404);
}

// 3) Found on IMDb => allow request to be queued
$requests = loadRequests();
cleanupRequests($requests);

$key = normalize_request_key($title, $year);

// Prevent duplicates
foreach ($requests as $r) {
    if (($r['key'] ?? '') === $key) {
        $rid = $r['id'] ?? null;
        $token = $r['token'] ?? null;
        if ($rid && $token) {
            if (!isset($_SESSION['request_tokens'])) $_SESSION['request_tokens'] = [];
            $_SESSION['request_tokens'][$rid] = $token;
        }
        respond([
            'ok' => true,
            'status' => 'queued',
            'message' => 'Request already submitted and queued for review.',
            'request_id' => $rid,
            'imdb' => $check['item'] ?? null,
            'source' => $check['source']
        ]);
    }
}

$id = bin2hex(random_bytes(8));
$token = bin2hex(random_bytes(16));

$req = [
    'id' => $id,
    'token' => $token,
    'key' => $key,
    'title' => $check['item']['title'] ?? $title,
    'year' => $check['item']['year'] ?? $year,
    'imdb_id' => $check['item']['imdb_id'] ?? null,
    'type' => $check['item']['type'] ?? null,
    'poster' => $check['item']['poster'] ?? null,
    'created_at' => time(),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null
];

$requests[] = $req;
saveRequests($requests);

// Store token in session so user can delete their own request later
if (!isset($_SESSION['request_tokens'])) $_SESSION['request_tokens'] = [];
$_SESSION['request_tokens'][$id] = $token;

respond([
    'ok' => true,
    'status' => 'queued',
    'message' => 'Request submitted! It will appear in admin review (auto-delete after 24 hours).',
    'request_id' => $id,
    'imdb' => $check['item'] ?? null,
    'source' => $check['source']
]);
