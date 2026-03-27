<?php
// ============================================================
// ERP System — Audit Module: AJAX API
// Single endpoint for all audit operations.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/setup_tables.php';

ensureAuditTables();

header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = trim($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// CSRF check for all POST operations
if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

/**
 * Normalize a scanned roll number from various formats:
 *   - QR payload: "Roll: 3381 | Type: SILVER ... | Company: ..."  → "3381"
 *   - QR payload: "ROLL: 3381 | TYPE: ..."                         → "3381"
 *   - Plain with slashes: "ABC/DEF/3381"                           → "3381"
 *   - Plain text: "  3381  "                                        → "3381"
 */
function normalizeRollNo($raw) {
    $v = trim((string)$raw);

    // QR label format: extract roll number from "Roll: XXXX | Type: ..." pattern
    if (preg_match('/^ROLL\s*:\s*([^\|]+)/i', $v, $m)) {
        $v = trim($m[1]);
    }

    $v = strtoupper($v);

    // If still contains slashes, take last segment
    if (strpos($v, '/') !== false) {
        $parts = explode('/', $v);
        $last  = trim(end($parts));
        if ($last !== '') $v = $last;
    }

    return $v;
}

/**
 * Generate an audit ID like AUDIT-20260327-4821
 */
function generateAuditId() {
    return 'AUDIT-' . date('Ymd') . '-' . random_int(1000, 9999);
}

// ─── Route actions ──────────────────────────────────────────
try {
    switch ($action) {

    // ═══════════════════════════════════════════════════════
    // LIST SESSIONS
    // ═══════════════════════════════════════════════════════
    case 'list_sessions':
        $rows = $db->query("SELECT * FROM inventory_audits ORDER BY created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok' => true, 'sessions' => $rows]);
        break;

    // ═══════════════════════════════════════════════════════
    // GET SINGLE SESSION
    // ═══════════════════════════════════════════════════════
    case 'get_session':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); break; }
        $stmt = $db->prepare("SELECT * FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'Session not found']); break; }

        // Also fetch scanned rolls
        $stmt2 = $db->prepare("SELECT * FROM audit_scanned_rolls WHERE audit_id = ? ORDER BY scan_time DESC");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $scans = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $session['scanned_rolls'] = $scans;

        echo json_encode(['ok' => true, 'session' => $session]);
        break;

    // ═══════════════════════════════════════════════════════
    // CREATE SESSION
    // ═══════════════════════════════════════════════════════
    case 'create_session':
        $name = trim($_POST['session_name'] ?? '');
        if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Session name is required']); break; }

        $auditId     = generateAuditId();
        $createdBy   = (int)($_SESSION['user_id'] ?? 0);
        $createdName = $_SESSION['user_name'] ?? 'System';

        $stmt = $db->prepare("INSERT INTO inventory_audits (audit_id, session_name, created_by, created_by_name) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssis', $auditId, $name, $createdBy, $createdName);
        $stmt->execute();
        $newId = $db->insert_id;

        echo json_encode(['ok' => true, 'id' => $newId, 'audit_id' => $auditId]);
        break;

    // ═══════════════════════════════════════════════════════
    // SCAN ROLL — add a scanned roll to a session
    // ═══════════════════════════════════════════════════════
    case 'scan_roll':
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $rawRollNo = $_POST['roll_no'] ?? '';
        $rollNo    = normalizeRollNo($rawRollNo);

        if (!$sessionId || $rollNo === '') {
            echo json_encode(['ok' => false, 'error' => 'Session ID and Roll No are required']);
            break;
        }

        // Check session exists and is In Progress
        $stmt = $db->prepare("SELECT id, status FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session not found']); break; }
        if ($sess['status'] === 'Finalized') { echo json_encode(['ok' => false, 'error' => 'Session is finalized']); break; }

        // Check if already scanned in this session
        $stmt2 = $db->prepare("SELECT id FROM audit_scanned_rolls WHERE audit_id = ? AND roll_no = ?");
        $stmt2->bind_param('is', $sessionId, $rollNo);
        $stmt2->execute();
        if ($stmt2->get_result()->num_rows > 0) {
            echo json_encode(['ok' => false, 'error' => 'Already scanned', 'duplicate' => true, 'roll_no' => $rollNo]);
            break;
        }

        // Look up in paper_stock
        $stmt3 = $db->prepare("SELECT id, roll_no, paper_type, width_mm, length_mtr, status FROM paper_stock WHERE UPPER(TRIM(roll_no)) = ?");
        $stmt3->bind_param('s', $rollNo);
        $stmt3->execute();
        $erpRoll = $stmt3->get_result()->fetch_assoc();

        $scanStatus = 'Unknown';
        $paperType  = '';
        $dimension  = '';
        if ($erpRoll) {
            $scanStatus = 'Matched';
            $paperType  = $erpRoll['paper_type'] ?? '';
            $w = $erpRoll['width_mm'] ? (int)$erpRoll['width_mm'] . 'mm' : '';
            $l = $erpRoll['length_mtr'] ? number_format((float)$erpRoll['length_mtr'], 0) . 'm' : '';
            $dimension = trim($w . ' × ' . $l, ' ×');
        }

        $now = date('Y-m-d H:i:s');
        $stmt4 = $db->prepare("INSERT INTO audit_scanned_rolls (audit_id, roll_no, paper_type, dimension, scan_time, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt4->bind_param('isssss', $sessionId, $rollNo, $paperType, $dimension, $now, $scanStatus);
        $stmt4->execute();

        echo json_encode([
            'ok'        => true,
            'scan_id'   => $db->insert_id,
            'roll_no'   => $rollNo,
            'status'    => $scanStatus,
            'paper_type' => $paperType,
            'dimension' => $dimension,
            'scan_time' => $now,
            'erp_status' => $erpRoll['status'] ?? null,
        ]);
        break;

    // ═══════════════════════════════════════════════════════
    // DELETE SESSION (admin only)
    // ═══════════════════════════════════════════════════════
    case 'delete_session':
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Admin access required']); break; }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        if (!$sessionId) { echo json_encode(['ok' => false, 'error' => 'Missing session ID']); break; }

        // Delete scanned rolls first (child records)
        $stmt = $db->prepare("DELETE FROM audit_scanned_rolls WHERE audit_id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $deletedScans = $stmt->affected_rows;

        // Delete the session
        $stmt2 = $db->prepare("DELETE FROM inventory_audits WHERE id = ?");
        $stmt2->bind_param('i', $sessionId);
        $stmt2->execute();

        echo json_encode(['ok' => true, 'deleted_scans' => $deletedScans]);
        break;

    // ═══════════════════════════════════════════════════════
    // DELETE SCAN ENTRY (admin only)
    // ═══════════════════════════════════════════════════════
    case 'delete_scan':
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Admin access required']); break; }

        $scanId    = (int)($_POST['scan_id'] ?? 0);
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if (!$scanId || !$sessionId) { echo json_encode(['ok' => false, 'error' => 'Missing parameters']); break; }

        // Verify session is not finalized
        $stmt = $db->prepare("SELECT status FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if ($sess && $sess['status'] === 'Finalized') {
            echo json_encode(['ok' => false, 'error' => 'Cannot modify finalized session']);
            break;
        }

        $stmt2 = $db->prepare("DELETE FROM audit_scanned_rolls WHERE id = ? AND audit_id = ?");
        $stmt2->bind_param('ii', $scanId, $sessionId);
        $stmt2->execute();
        echo json_encode(['ok' => true, 'deleted' => $stmt2->affected_rows]);
        break;

    // ═══════════════════════════════════════════════════════
    // RECONCILE — compare scanned vs ERP inventory
    // ═══════════════════════════════════════════════════════
    case 'reconcile':
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if (!$sessionId) { echo json_encode(['ok' => false, 'error' => 'Missing session_id']); break; }

        // Active ERP rolls (exclude Consumed)
        $erpRows = $db->query("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, gsm, status FROM paper_stock WHERE status != 'Consumed'")->fetch_all(MYSQLI_ASSOC);
        $erpMap = [];
        foreach ($erpRows as $r) {
            $key = strtoupper(trim($r['roll_no']));
            $erpMap[$key] = $r;
        }

        // Scanned rolls for this session
        $stmt = $db->prepare("SELECT id, roll_no, paper_type, dimension, scan_time, status FROM audit_scanned_rolls WHERE audit_id = ? ORDER BY scan_time DESC");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $scans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $scannedSet = [];
        foreach ($scans as $s) {
            $scannedSet[strtoupper(trim($s['roll_no']))] = true;
        }

        $matched = [];
        $extra   = [];
        foreach ($scans as $s) {
            $key = strtoupper(trim($s['roll_no']));
            if (isset($erpMap[$key])) {
                $matched[] = array_merge($s, ['erp' => $erpMap[$key]]);
            } else {
                $extra[] = $s;
            }
        }

        $missing = [];
        foreach ($erpRows as $r) {
            $key = strtoupper(trim($r['roll_no']));
            if (!isset($scannedSet[$key])) {
                $missing[] = $r;
            }
        }

        $totalErp     = count($erpRows);
        $totalScanned = count($scans);
        $matchedCount = count($matched);
        $missingCount = count($missing);
        $extraCount   = count($extra);
        $matchPercent = $totalErp > 0 ? round(($matchedCount / $totalErp) * 100, 2) : 0;

        echo json_encode([
            'ok'            => true,
            'total_erp'     => $totalErp,
            'total_scanned' => $totalScanned,
            'matched_count' => $matchedCount,
            'missing_count' => $missingCount,
            'extra_count'   => $extraCount,
            'match_percent' => $matchPercent,
            'matched'       => $matched,
            'missing'       => $missing,
            'extra'         => $extra,
        ]);
        break;

    // ═══════════════════════════════════════════════════════
    // BULK REMOVE MISSING — mark as Consumed in paper_stock
    // ═══════════════════════════════════════════════════════
    case 'bulk_remove_missing':
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Admin access required']); break; }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $rollNos   = json_decode($_POST['roll_nos'] ?? '[]', true);
        if (!$sessionId || empty($rollNos) || !is_array($rollNos)) {
            echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
            break;
        }

        // Get session name for remarks
        $stmt = $db->prepare("SELECT session_name, status FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session not found']); break; }
        if ($sess['status'] === 'Finalized') { echo json_encode(['ok' => false, 'error' => 'Session is finalized']); break; }

        $today   = date('Y-m-d');
        $remark  = 'Deducted during audit: ' . $sess['session_name'];
        $updated = 0;

        $stmtUp = $db->prepare("UPDATE paper_stock SET status = 'Consumed', date_used = ?, remarks = CONCAT(IFNULL(remarks,''), '\n', ?) WHERE UPPER(TRIM(roll_no)) = ? AND status != 'Consumed'");
        foreach ($rollNos as $rn) {
            $rn = strtoupper(trim((string)$rn));
            if ($rn === '') continue;
            $stmtUp->bind_param('sss', $today, $remark, $rn);
            $stmtUp->execute();
            $updated += $stmtUp->affected_rows;
        }

        echo json_encode(['ok' => true, 'updated' => $updated]);
        break;

    // ═══════════════════════════════════════════════════════
    // BULK ADD EXTRA — register found items as new paper_stock
    // ═══════════════════════════════════════════════════════
    case 'bulk_add_extra':
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Admin access required']); break; }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        $rollNos   = json_decode($_POST['roll_nos'] ?? '[]', true);
        if (!$sessionId || empty($rollNos) || !is_array($rollNos)) {
            echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
            break;
        }

        $stmt = $db->prepare("SELECT session_name, status FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) { echo json_encode(['ok' => false, 'error' => 'Session not found']); break; }
        if ($sess['status'] === 'Finalized') { echo json_encode(['ok' => false, 'error' => 'Session is finalized']); break; }

        $today   = date('Y-m-d');
        $remark  = 'Registered from audit: ' . $sess['session_name'];
        $created = 0;

        $stmtIns = $db->prepare("INSERT IGNORE INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, status, date_received, remarks) VALUES (?, 'AUDIT_FOUND', 'AUDIT', 0, 0, 'Stock', ?, ?)");
        foreach ($rollNos as $rn) {
            $rn = strtoupper(trim((string)$rn));
            if ($rn === '') continue;
            $stmtIns->bind_param('sss', $rn, $today, $remark);
            $stmtIns->execute();
            $created += $stmtIns->affected_rows;
        }

        echo json_encode(['ok' => true, 'created' => $created]);
        break;

    // ═══════════════════════════════════════════════════════
    // FINALIZE SESSION (admin only)
    // ═══════════════════════════════════════════════════════
    case 'finalize':
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Admin access required']); break; }

        $sessionId = (int)($_POST['session_id'] ?? 0);
        if (!$sessionId) { echo json_encode(['ok' => false, 'error' => 'Missing session_id']); break; }

        // Compute final stats
        $erpCount = (int)$db->query("SELECT COUNT(*) AS c FROM paper_stock WHERE status != 'Consumed'")->fetch_assoc()['c'];

        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM audit_scanned_rolls WHERE audit_id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $scannedCount = (int)$stmt->get_result()->fetch_assoc()['c'];

        $stmt2 = $db->prepare("SELECT COUNT(*) AS c FROM audit_scanned_rolls WHERE audit_id = ? AND status = 'Matched'");
        $stmt2->bind_param('i', $sessionId);
        $stmt2->execute();
        $matchedCount = (int)$stmt2->get_result()->fetch_assoc()['c'];

        // Extra = scanned rolls that don't match ERP
        $missingCount = $erpCount - $matchedCount;
        if ($missingCount < 0) $missingCount = 0;
        $extraCount   = $scannedCount - $matchedCount;
        if ($extraCount < 0) $extraCount = 0;
        $matchPercent = $erpCount > 0 ? round(($matchedCount / $erpCount) * 100, 2) : 0;

        $now = date('Y-m-d H:i:s');
        $userId = (int)($_SESSION['user_id'] ?? 0);

        $stmt3 = $db->prepare("UPDATE inventory_audits SET status = 'Finalized', total_erp = ?, total_scanned = ?, matched_count = ?, missing_count = ?, extra_count = ?, match_percent = ?, finalized_at = ?, finalized_by = ? WHERE id = ? AND status = 'In Progress'");
        $stmt3->bind_param('iiiiidsii', $erpCount, $scannedCount, $matchedCount, $missingCount, $extraCount, $matchPercent, $now, $userId, $sessionId);
        $stmt3->execute();

        if ($stmt3->affected_rows > 0) {
            echo json_encode(['ok' => true, 'message' => 'Session finalized']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Session not found or already finalized']);
        }
        break;

    // ═══════════════════════════════════════════════════════
    // EXPORT (CSV / printable HTML report)
    // ═══════════════════════════════════════════════════════
    case 'export_csv':
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if (!$sessionId) { echo json_encode(['ok' => false, 'error' => 'Missing session_id']); break; }

        $stmt = $db->prepare("SELECT * FROM inventory_audits WHERE id = ?");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) { http_response_code(404); exit; }

        $stmt2 = $db->prepare("SELECT roll_no, paper_type, dimension, scan_time, status FROM audit_scanned_rolls WHERE audit_id = ? ORDER BY scan_time ASC");
        $stmt2->bind_param('i', $sessionId);
        $stmt2->execute();
        $scans = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-' . $sess['audit_id'] . '.csv"');
        $out = fopen('php://output', 'w');
        // Header info
        fputcsv($out, ['Audit Report: ' . $sess['session_name']]);
        fputcsv($out, ['Audit ID', $sess['audit_id']]);
        fputcsv($out, ['Status', $sess['status']]);
        fputcsv($out, ['Created', $sess['created_at']]);
        fputcsv($out, []);
        fputcsv($out, ['#', 'Roll No', 'Paper Type', 'Dimension', 'Scan Time', 'Status']);
        foreach ($scans as $i => $s) {
            fputcsv($out, [$i+1, $s['roll_no'], $s['paper_type'], $s['dimension'], $s['scan_time'], $s['status']]);
        }
        fclose($out);
        exit;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $ex) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $ex->getMessage()]);
}
