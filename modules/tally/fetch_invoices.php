<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/tally_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$db = getDB();
$force = isset($_GET['refresh']) && (string)$_GET['refresh'] === '1';
$out = tally_fetch_type($db, 'invoice', $force);
http_response_code(!empty($out['ok']) ? 200 : 503);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
