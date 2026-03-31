<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
  header('Location: login.php');
  exit;
}

$reportsFile = __DIR__ . '/../reports.json';
if (!file_exists($reportsFile)) {
  file_put_contents($reportsFile, "[]");
}

$raw = file_get_contents($reportsFile);
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? '';

if ($id === '') {
  header('Location: dashboard.php');
  exit;
}

if ($action === 'resolve') {
  foreach ($data as &$r) {
    if (($r['id'] ?? '') === $id) {
      $r['status'] = 'RESOLVED';
      $r['resolved_at'] = date('c');
      break;
    }
  }
}

if ($action === 'delete') {
  $data = array_values(array_filter($data, function($r) use ($id) {
    return ($r['id'] ?? '') !== $id;
  }));
}

file_put_contents($reportsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
header('Location: dashboard.php');
exit;
