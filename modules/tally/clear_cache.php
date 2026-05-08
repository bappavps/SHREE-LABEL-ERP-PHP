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

$db  = getDB();
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$type = trim((string)($body['type'] ?? 'all'));

if ($type === 'all') {
    $db->query("TRUNCATE TABLE tally_cache");
    $db->query("DELETE FROM tally_sync_log");
    echo json_encode(['ok' => true, 'message' => 'All Tally cache cleared.']);
    exit;
}

if (in_array($type, ['po', 'client', 'invoice'], true)) {
    // Check if specific row keys were requested for removal (PO by po_number)
    $removeKeys = array_filter((array)($body['keys'] ?? []));
    if (!empty($removeKeys)) {
        // Load current cached JSON and remove matching rows
        $stmt = $db->prepare('SELECT json_data FROM tally_cache WHERE cache_type = ? LIMIT 1');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $rows = json_decode((string)$row['json_data'], true);
            if (is_array($rows)) {
                $removeSet = array_flip(array_map('strval', $removeKeys));
                $rows = array_values(array_filter($rows, function($r) use ($removeSet) {
                    $key = (string)($r['po_number'] ?? $r['name'] ?? '');
                    return !isset($removeSet[$key]);
                }));
                $newJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
                $upd = $db->prepare('UPDATE tally_cache SET json_data = ?, last_updated = NOW() WHERE cache_type = ?');
                $upd->bind_param('ss', $newJson, $type);
                $upd->execute();
                $upd->close();
                echo json_encode(['ok' => true, 'remaining' => count($rows)]);
                exit;
            }
        }
    }
    // Full clear for this type
    $stmt = $db->prepare('DELETE FROM tally_cache WHERE cache_type = ?');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $stmt->close();
    if ($type === 'client') {
        $db->query("DELETE FROM tally_sync_log WHERE type = 'clients'");
    }
    echo json_encode(['ok' => true, 'message' => "Cache cleared for type: $type"]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid type.']);
