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
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || empty($body['clients']) || !is_array($body['clients'])) {
    echo json_encode(['success' => false, 'error' => 'No client data received.']);
    exit;
}

$db = getDB();

// Ensure tally_sync_log table exists
@$db->query("CREATE TABLE IF NOT EXISTS tally_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    fetched_count INT DEFAULT 0,
    imported_count INT DEFAULT 0,
    skipped_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure gst_number column exists on master_clients (safe, idempotent)
$chkGst = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'master_clients' AND column_name = 'gst_number' LIMIT 1");
if ($chkGst) {
    $chkGst->execute();
    if (!(bool)$chkGst->get_result()->fetch_row()) {
        @$db->query("ALTER TABLE master_clients ADD COLUMN gst_number VARCHAR(30) DEFAULT NULL AFTER state");
    }
    $chkGst->close();
}

// Helper: normalize name for fallback comparison
function tcNormalizeNamePHP(string $name): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($name));
}

// Load existing clients for duplicate detection
$existing = [];
$res = $db->query("SELECT name, COALESCE(gst_number,'') AS gst_number FROM master_clients");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $existing[] = $row;
    }
}

$inserted = 0;
$skipped  = 0;

foreach ($body['clients'] as $item) {
    if (!is_array($item)) { $skipped++; continue; }

    $name    = trim((string)($item['name']       ?? ''));
    $address = trim((string)($item['address']    ?? ''));
    $gst     = strtoupper(trim((string)($item['gst_number'] ?? '')));

    if ($name === '') { $skipped++; continue; }

    // Duplicate check: prefer GST matching when both sides have GST,
    // else fall back to normalized name comparison.
    $isDup = false;
    foreach ($existing as $ex) {
        $exGst = strtoupper(trim((string)($ex['gst_number'] ?? '')));
        if ($gst !== '' && $exGst !== '') {
            if ($gst === $exGst) { $isDup = true; break; }
        } else {
            if (tcNormalizeNamePHP($name) === tcNormalizeNamePHP((string)($ex['name'] ?? ''))) {
                $isDup = true; break;
            }
        }
    }

    if ($isDup) { $skipped++; continue; }

    // Insert new client
    $stmt = $db->prepare(
        "INSERT INTO master_clients (name, address, gst_number, credit_period_days, credit_limit) VALUES (?, ?, ?, 0, 0)"
    );
    if (!$stmt) { $skipped++; continue; }
    $stmt->bind_param('sss', $name, $address, $gst);
    if ($stmt->execute()) {
        $inserted++;
        // Track in-memory to prevent intra-batch duplicates
        $existing[] = ['name' => $name, 'gst_number' => $gst];
    } else {
        $skipped++;
    }
    $stmt->close();
}

// Write sync log entry
$type    = 'clients';
$fetched = $inserted + $skipped;
$logStmt = $db->prepare(
    "INSERT INTO tally_sync_log (type, fetched_count, imported_count, skipped_count) VALUES (?, ?, ?, ?)"
);
if ($logStmt) {
    $logStmt->bind_param('siii', $type, $fetched, $inserted, $skipped);
    $logStmt->execute();
    $logStmt->close();
}

// Return latest sync time for frontend display
$lastSyncStr = null;
$lsRes = $db->query("SELECT created_at FROM tally_sync_log WHERE type='clients' ORDER BY created_at DESC LIMIT 1");
if ($lsRes) {
    $lsRow = $lsRes->fetch_assoc();
    if ($lsRow) {
        $lastSyncStr = date('d M Y, g:i A', strtotime($lsRow['created_at']));
    }
}

echo json_encode([
    'success'   => true,
    'inserted'  => $inserted,
    'skipped'   => $skipped,
    'last_sync' => $lastSyncStr,
], JSON_UNESCAPED_UNICODE);
