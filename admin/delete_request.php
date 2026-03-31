<?php
// admin/delete_request.php - Admin delete request
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../lib/requests.php';

$id = trim($_POST['request_id'] ?? '');
if ($id === '') {
    header('Location: dashboard.php?tab=requests&err=missing_id');
    exit;
}

$requests = loadRequests();
cleanupRequests($requests);

$requests = array_values(array_filter($requests, function($r) use ($id) {
    return (($r['id'] ?? '') !== $id);
}));

saveRequests($requests);

header('Location: dashboard.php?tab=requests&deleted=1');
exit;
