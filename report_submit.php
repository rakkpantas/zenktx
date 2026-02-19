<?php
session_start();
header('Content-Type: application/json');

function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge([
    'ok' => $ok,
    'message' => $msg
  ], $extra));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'Invalid request');
}

$imdb = trim($_POST['imdb_id'] ?? '');
$title = trim($_POST['title'] ?? '');
$type = trim($_POST['type'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($imdb === '' || $reason === '') {
  respond(false, 'Missing fields');
}

if (mb_strlen($reason) > 200) {
  respond(false, 'Reason too long (max 200 chars).');
}

$reportsFile = __DIR__ . '/reports.json';
if (!file_exists($reportsFile)) {
  file_put_contents($reportsFile, "[]");
}

$raw = file_get_contents($reportsFile);
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$reporter = 'Guest';
$email = '';
if (isset($_SESSION['user']) && !empty($_SESSION['user']['email'])) {
  $reporter = $_SESSION['user']['name'] ?? 'User';
  $email = $_SESSION['user']['email'] ?? '';
}

$entry = [
  'id' => bin2hex(random_bytes(8)),
  'imdb_id' => $imdb,
  'title' => $title,
  'type' => $type,
  'reason' => $reason,
  'reporter' => $reporter,
  'email' => $email,
  'status' => 'NEW',
  'created_at' => date('c')
];

array_unshift($data, $entry);

file_put_contents($reportsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

respond(true, 'Report submitted. Thank you!', ['report' => $entry]);
