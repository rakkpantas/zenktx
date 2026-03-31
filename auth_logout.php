<?php
session_start();
header('Content-Type: application/json');
unset($_SESSION['user']);
echo json_encode(['ok' => true, 'message' => 'Logged out']);
