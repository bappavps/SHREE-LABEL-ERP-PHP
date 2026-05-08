<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

$type = trim((string)($body['type'] ?? ''));
$ids  = array_values(array_filter(array_map('intval', (array)($body['ids'] ?? []))));

if (!in_array($type, ['client', 'supplier'], true) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid type or empty ids.']);
    exit;
}

$db    = getDB();
$table = $type === 'client' ? 'master_clients' : 'master_suppliers';
$ph    = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$stmt = $db->prepare("DELETE FROM $table WHERE id IN ($ph)");
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();

echo json_encode(['ok' => true, 'deleted' => $deleted]);
