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

function usersFilePath() {
  $dir = __DIR__ . '/config';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir . '/users.json';
}

function loadUsers() {
  $file = usersFilePath();
  if (!file_exists($file)) return [];
  $raw = @file_get_contents($file);
  $data = $raw ? json_decode($raw, true) : null;
  return is_array($data) ? $data : [];
}

function saveUsers($users) {
  $file = usersFilePath();
  $tmp = $file . '.tmp';
  @file_put_contents($tmp, json_encode($users, JSON_PRETTY_PRINT));
  @rename($tmp, $file);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'Invalid request');
}

$mode = trim($_POST['mode'] ?? 'login'); // login | signup
$email = trim($_POST['email'] ?? '');
$password = (string)($_POST['password'] ?? '');
$name = trim($_POST['name'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(false, 'Please enter a valid email.');
}
if (strlen($password) < 6) {
  respond(false, 'Password must be at least 6 characters.');
}

$users = loadUsers();
$key = strtolower($email);

if ($mode === 'signup') {
  if ($name === '') {
    // default: part before @
    $name = explode('@', $email)[0];
  }
  if (isset($users[$key])) {
    respond(false, 'Account already exists. Please login instead.');
  }
  $users[$key] = [
    'email' => $email,
    'name' => $name,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'created_at' => date('c')
  ];
  saveUsers($users);

  $_SESSION['user'] = [
    'provider' => 'local',
    'email' => $email,
    'name' => $name
  ];
  respond(true, 'Account created', ['user' => $_SESSION['user']]);
}

if (!isset($users[$key])) {
  respond(false, 'Account not found. Please sign up first.');
}

$u = $users[$key];
if (!isset($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
  respond(false, 'Incorrect password.');
}

$_SESSION['user'] = [
  'provider' => 'local',
  'email' => $u['email'],
  'name' => $u['name'] ?? explode('@', $email)[0]
];

respond(true, 'Logged in', ['user' => $_SESSION['user']]);
