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
    echo json_encode(['status' => 'disconnected', 'error' => 'Not authenticated.']);
    exit;
}

$ts = tally_settings();

if ($ts['ip'] === '') {
    echo json_encode(['status' => 'disconnected', 'reason' => 'No Tally IP configured.']);
    exit;
}

$connected = tally_ping($ts['ip'], $ts['port'], 2);

echo json_encode([
    'status'   => $connected ? 'connected' : 'disconnected',
    'endpoint' => 'http://' . $ts['ip'] . ':' . $ts['port'],
]);
