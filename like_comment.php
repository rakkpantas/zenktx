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
  respond(false, 'You must be logged in to like.');
}

$commentId = trim($_POST['comment_id'] ?? '');
if ($commentId === '') {
  respond(false, 'Missing comment id');
}

$userEmail = $_SESSION['user']['email'];

$commentsFile = __DIR__ . '/comments.json';
if (!file_exists($commentsFile)) {
  file_put_contents($commentsFile, "[]");
}

$raw = file_get_contents($commentsFile);
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$found = false;
$likeCount = 0;
$already = false;

for ($i = 0; $i < count($data); $i++) {
  if (($data[$i]['id'] ?? '') === $commentId) {
    $found = true;
    if (!isset($data[$i]['liked_by']) || !is_array($data[$i]['liked_by'])) {
      $data[$i]['liked_by'] = [];
    }
    if (in_array($userEmail, $data[$i]['liked_by'], true)) {
      $already = true;
    } else {
      $data[$i]['liked_by'][] = $userEmail;
    }
    $likeCount = count($data[$i]['liked_by']);
    break;
  }
}

if (!$found) {
  respond(false, 'Comment not found');
}

file_put_contents($commentsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

respond(true, $already ? 'Already liked' : 'Liked', ['count' => $likeCount, 'already' => $already]);
