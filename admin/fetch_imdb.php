<?php
// admin/fetch_imdb.php - AJAX endpoint to fetch movie/series data from OMDb via IMDb ID
header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/../config/omdb.php';
$apiKey = '';
if (file_exists($configFile)) {
    $cfg = require $configFile;
    if (is_array($cfg) && isset($cfg['api_key'])) $apiKey = trim($cfg['api_key']);
}

if (!$apiKey) {
    echo json_encode(['success' => false, 'message' => 'OMDb API key not configured.']);
    exit;
}

$imdbID = isset($_GET['imdb_id']) ? trim($_GET['imdb_id']) : '';
if (!$imdbID) {
    echo json_encode(['success' => false, 'message' => 'IMDb ID is required.']);
    exit;
}

// Basic validation
if (!preg_match('/^tt\d{5,10}$/', $imdbID)) {
    echo json_encode(['success' => false, 'message' => 'Invalid IMDb ID format. Use something like tt1375666.']);
    exit;
}

$url = 'https://www.omdbapi.com/?apikey=' . urlencode($apiKey) . '&i=' . urlencode($imdbID) . '&plot=short&r=json';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $code >= 400) {
    echo json_encode(['success' => false, 'message' => 'Failed to fetch from OMDb. ' . ($err ? $err : '')]);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid response from OMDb.']);
    exit;
}

if (($data['Response'] ?? 'False') === 'False') {
    echo json_encode(['success' => false, 'message' => $data['Error'] ?? 'OMDb returned an error.']);
    exit;
}

echo json_encode([
    'success' => true,
    'data' => [
        'title' => $data['Title'] ?? '',
        'year' => $data['Year'] ?? '',
        'poster' => $data['Poster'] ?? '',
        'rating' => $data['imdbRating'] ?? '',
        'type' => $data['Type'] ?? ''
    ]
]);
