<?php
// request_delete.php - Allow user to delete their own request (AJAX)
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/lib/requests.php';

function respond($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr);
    exit;
}

$id = trim($_POST['request_id'] ?? '');
if ($id === '') {
    respond(['ok' => false, 'message' => 'Missing request_id'], 400);
}

$tokens = $_SESSION['request_tokens'] ?? [];
$token = $tokens[$id] ?? null;

if (!$token) {
    respond(['ok' => false, 'message' => 'Not allowed to delete this request (session token missing).'], 403);
}

$requests = loadRequests();
cleanupRequests($requests);

$before = count($requests);
$requests = array_values(array_filter($requests, function($r) use ($id, $token) {
    return !((($r['id'] ?? '') === $id) && (($r['token'] ?? '') === $token));
}));

saveRequests($requests);

unset($_SESSION['request_tokens'][$id]);

$after = count($requests);
if ($after < $before) {
    respond(['ok' => true, 'message' => 'Request deleted.']);
}
respond(['ok' => false, 'message' => 'Request not found (maybe already auto-deleted).'], 404);
