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

if (!isset($_SESSION['user']) || empty($_SESSION['user']['email'])) {
  respond(false, 'You must be logged in to comment.');
}

$imdb = trim($_POST['imdb_id'] ?? '');
$text = trim($_POST['comment'] ?? '');

if ($imdb === '' || $text === '') {
  respond(false, 'Missing fields');
}

if (mb_strlen($text) > 500) {
  respond(false, 'Comment too long (max 500 chars).');
}

$commentsFile = __DIR__ . '/comments.json';
if (!file_exists($commentsFile)) {
  file_put_contents($commentsFile, "[]");
}

$raw = file_get_contents($commentsFile);
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$entry = [
  'id' => bin2hex(random_bytes(8)),
  'imdb_id' => $imdb,
  'name' => $_SESSION['user']['name'] ?? 'User',
  'email' => $_SESSION['user']['email'] ?? '',
  'provider' => $_SESSION['user']['provider'] ?? 'gmail',
  'comment' => $text,
  'created_at' => date('c')
];

array_unshift($data, $entry);

file_put_contents($commentsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

respond(true, 'Comment posted', ['comment' => $entry]);
