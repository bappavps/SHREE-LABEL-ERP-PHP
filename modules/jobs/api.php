<?php
// ============================================================
// ERP System — Jobs Module: AJAX API
// Shared endpoint for job card operations (Jumbo, Printing, etc.)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = trim($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// CSRF check for POST
if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

function jobs_ensure_change_request_table(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS job_change_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        request_type VARCHAR(50) NOT NULL DEFAULT 'jumbo_roll_update',
        payload_json LONGTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        requested_by INT NULL,
        requested_by_name VARCHAR(120) NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_by_name VARCHAR(120) NULL,
        reviewed_at DATETIME NULL,
        review_note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_jcr_job_id (job_id),
        INDEX idx_jcr_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function jobs_apply_jumbo_roll_changes(mysqli $db, array $job, array $rows, float $operatorWastageKg, string $operatorRemarks, string $notePrefix = 'Operator remarks'): array {
    $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
    if (!is_array($extra)) $extra = [];

    $childMap = [];
    $stockMap = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $rollNo = trim((string)($r['roll_no'] ?? ''));
        if ($rollNo === '') continue;
        $bucket = strtolower(trim((string)($r['bucket'] ?? 'child')));
        $entry = [
            'roll_no' => $rollNo,
            'width' => (float)($r['width'] ?? 0),
            'length' => (float)($r['length'] ?? 0),
            'wastage' => (float)($r['wastage'] ?? 0),
            'remarks' => trim((string)($r['remarks'] ?? '')),
            'status' => trim((string)($r['status'] ?? '')),
        ];
        if ($bucket === 'stock') $stockMap[$rollNo] = $entry;
        else $childMap[$rollNo] = $entry;
    }

    $childRows = is_array($extra['child_rolls'] ?? null) ? $extra['child_rolls'] : [];
    $stockRows = is_array($extra['stock_rolls'] ?? null) ? $extra['stock_rolls'] : [];

    $db->begin_transaction();
    try {
        $updRollStmt = $db->prepare("UPDATE paper_stock SET width_mm = ?, length_mtr = ?, sqm = ?, remarks = ?, status = ? WHERE roll_no = ?");

        $applyUpdates = function(array $sourceRows, array $incomingMap, string $defaultStatus) use ($updRollStmt) {
            foreach ($sourceRows as &$row) {
                $rollNo = trim((string)($row['roll_no'] ?? ''));
                if ($rollNo === '' || !isset($incomingMap[$rollNo])) continue;

                $in = $incomingMap[$rollNo];
                $w = (float)($in['width'] ?? ($row['width'] ?? 0));
                $l = (float)($in['length'] ?? ($row['length'] ?? 0));
                if ($w > 0) $row['width'] = $w;
                if ($l > 0) $row['length'] = $l;
                $row['wastage'] = (float)($in['wastage'] ?? ($row['wastage'] ?? 0));
                $row['remarks'] = (string)($in['remarks'] ?? ($row['remarks'] ?? ''));
                if (!empty($in['status'])) $row['status'] = (string)$in['status'];

                $wSafe = (float)($row['width'] ?? 0);
                $lSafe = (float)($row['length'] ?? 0);
                $sqm = ($wSafe > 0 && $lSafe > 0) ? round(($wSafe / 1000) * $lSafe, 2) : 0;
                $row['sqm'] = $sqm;

                $statusRaw = strtolower(trim((string)($row['status'] ?? '')));
                $status = $defaultStatus;
                if (strpos($statusRaw, 'stock') !== false) $status = 'Stock';
                elseif (strpos($statusRaw, 'job') !== false) $status = 'Job Assign';
                elseif (strpos($statusRaw, 'slit') !== false) $status = 'Slitting';

                $remarks = (string)($row['remarks'] ?? '');
                $rollNoParam = $rollNo;
                $updRollStmt->bind_param('dddsss', $wSafe, $lSafe, $sqm, $remarks, $status, $rollNoParam);
                $updRollStmt->execute();
            }
            unset($row);
            return $sourceRows;
        };

        $childRows = $applyUpdates($childRows, $childMap, 'Slitting');
        $stockRows = $applyUpdates($stockRows, $stockMap, 'Stock');

        $extra['child_rolls'] = array_values($childRows);
        $extra['stock_rolls'] = array_values($stockRows);
        $extra['operator_wastage_kg'] = $operatorWastageKg;
        $extra['operator_remarks'] = $operatorRemarks;
        $extra['operator_last_updated_at'] = date('c');

        $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $notes = trim((string)($job['notes'] ?? ''));
        if ($operatorRemarks !== '') {
            $notes = trim($notes . "\n" . $notePrefix . ": " . $operatorRemarks);
        }
        $jobId = (int)$job['id'];
        $updJob = $db->prepare("UPDATE jobs SET extra_data = ?, notes = ? WHERE id = ?");
        $updJob->bind_param('ssi', $extraJson, $notes, $jobId);
        $updJob->execute();

        $db->commit();
        return ['ok' => true];
    } catch (Throwable $th) {
        $db->rollback();
        return ['ok' => false, 'error' => $th->getMessage()];
    }
}

try {
    switch ($action) {

    // ─── Update job status ──────────────────────────────────
    case 'update_status':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId     = (int)($_POST['job_id'] ?? ($_POST['id'] ?? 0));
        $newStatus = trim($_POST['status'] ?? '');

        $validStatuses = ['Queued', 'Pending', 'Running', 'Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'];
        if (!$jobId || !in_array($newStatus, $validStatuses, true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid job_id or status']);
            break;
        }

        // Get current job
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $db->begin_transaction();

        // Update job status + timestamps
        if ($newStatus === 'Running') {
            $upd = $db->prepare("UPDATE jobs SET status = ?, started_at = NOW() WHERE id = ?");
            $upd->bind_param('si', $newStatus, $jobId);
        } elseif (in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true)) {
            $durSql = "UPDATE jobs SET status = ?, completed_at = NOW(), duration_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW()) WHERE id = ?";
            $upd = $db->prepare($durSql);
            $upd->bind_param('si', $newStatus, $jobId);
        } else {
            $upd = $db->prepare("UPDATE jobs SET status = ? WHERE id = ?");
            $upd->bind_param('si', $newStatus, $jobId);
        }
        $upd->execute();

        // Jumbo start should immediately move planning into Slitting stage color flow.
        if ($newStatus === 'Running' && ($job['job_type'] ?? '') === 'Slitting') {
            $planId = (int)($job['planning_id'] ?? 0);
            if ($planId > 0) {
                $updPlan = $db->prepare("UPDATE planning SET status = 'Preparing Slitting' WHERE id = ?");
                $updPlan->bind_param('i', $planId);
                $updPlan->execute();
            }
            if (!empty($job['roll_no'])) {
                $updParentRoll = $db->prepare("UPDATE paper_stock SET status = 'Slitting' WHERE roll_no = ? AND status IN ('Job Assign','Stock','Main')");
                $updParentRoll->bind_param('s', $job['roll_no']);
                $updParentRoll->execute();
            }
        }

        // If completing a job, mark parent roll as Slitted in paper_stock
        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed'], true) && $job['roll_no']) {
            $updRoll = $db->prepare("UPDATE paper_stock SET status = 'Slitted' WHERE roll_no = ? AND status = 'Slitting'");
            $updRoll->bind_param('s', $job['roll_no']);
            $updRoll->execute();
        }

        // Sequential gating: when a job completes, move next queued jobs to Pending
        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed'], true)) {
            $nextStmt = $db->prepare("UPDATE jobs SET status = 'Pending' WHERE previous_job_id = ? AND status = 'Queued' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $nextStmt->bind_param('i', $jobId);
            $nextStmt->execute();

            // Insert notification for next department
            $nxtQ = $db->prepare("SELECT id, job_no, department FROM jobs WHERE previous_job_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $nxtQ->bind_param('i', $jobId);
            $nxtQ->execute();
            $nxtRes = $nxtQ->get_result();
            while ($nxtJob = $nxtRes->fetch_assoc()) {
                $nMsg = $job['job_no'] . ' closed — ' . $nxtJob['job_no'] . ' is now ready';
                $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'success')");
                $nIns->bind_param('iss', $nxtJob['id'], $nxtJob['department'], $nMsg);
                $nIns->execute();
            }
        }

        // Jumbo close/finalize moves planning into Sliting Done stage.
        if (in_array($newStatus, ['Closed', 'Finalized'], true) && ($job['job_type'] ?? '') === 'Slitting') {
            $planId = (int)($job['planning_id'] ?? 0);
            if ($planId > 0) {
                $planNoStmt = $db->prepare("SELECT job_no FROM planning WHERE id = ? LIMIT 1");
                $planNoStmt->bind_param('i', $planId);
                $planNoStmt->execute();
                $planNoRow = $planNoStmt->get_result()->fetch_assoc();
                $planNo = trim((string)($planNoRow['job_no'] ?? ''));
                if ($planNo !== '') {
                    $updPlanNo = $db->prepare("UPDATE planning SET status = 'Sliting Done' WHERE job_no = ?");
                    $updPlanNo->bind_param('s', $planNo);
                    $updPlanNo->execute();
                } else {
                    $updPlan = $db->prepare("UPDATE planning SET status = 'Sliting Done' WHERE id = ?");
                    $updPlan->bind_param('i', $planId);
                    $updPlan->execute();
                }
            } else {
                $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
                $planNo = trim((string)($extra['plan_no'] ?? ''));
                if ($planNo !== '') {
                    $updPlanNo = $db->prepare("UPDATE planning SET status = 'Sliting Done' WHERE job_no = ?");
                    $updPlanNo->bind_param('s', $planNo);
                    $updPlanNo->execute();
                }
            }
        }

        // Insert notification for status change
        $notifMsg = $job['job_no'] . ' status → ' . $newStatus;
        $notifDept = $job['department'] ?? null;
        $nIns2 = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'info')");
        $nIns2->bind_param('iss', $jobId, $notifDept, $notifMsg);
        $nIns2->execute();

        $db->commit();

        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => $newStatus]);
        break;

    // ─── Submit operator extra data ─────────────────────────
    case 'submit_extra_data':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId   = (int)($_POST['job_id'] ?? 0);
        $rawData = trim($_POST['extra_data'] ?? '{}');

        if (!$jobId) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }

        $extraArr = json_decode($rawData, true);
        if (!is_array($extraArr)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid extra_data JSON']);
            break;
        }

        // Sanitize values
        array_walk_recursive($extraArr, function(&$val) {
            if (is_string($val)) $val = htmlspecialchars(strip_tags($val), ENT_QUOTES, 'UTF-8');
        });

        $safeJson = json_encode($extraArr, JSON_UNESCAPED_UNICODE);
        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $safeJson, $jobId);
        $upd->execute();

        echo json_encode(['ok' => true, 'job_id' => $jobId]);
        break;

    // ─── Get job details ────────────────────────────────────
    case 'get_job':
        $jobId = (int)($_GET['id'] ?? 0);
        if (!$jobId) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            break;
        }

        $stmt = $db->prepare("
            SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
                   ps.status AS roll_status, ps.lot_batch_no,
                   p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority
            FROM jobs j
            LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
            LEFT JOIN planning p ON j.planning_id = p.id
            WHERE j.id = ? AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
        ");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $job['extra_data_parsed'] = json_decode($job['extra_data'] ?? '{}', true) ?: [];
        echo json_encode(['ok' => true, 'job' => $job]);
        break;

    // ─── Resolve roll numbers to paper_stock IDs ───────────
    case 'get_roll_ids':
        $rollNosRaw = trim((string)($_GET['roll_nos'] ?? ''));
        if ($rollNosRaw === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing roll_nos']);
            break;
        }

        $rollNos = array_values(array_unique(array_filter(array_map('trim', explode(',', $rollNosRaw)), function($v) {
            return $v !== '';
        })));

        if (empty($rollNos)) {
            echo json_encode(['ok' => false, 'error' => 'No valid roll numbers']);
            break;
        }

        $ph = implode(',', array_fill(0, count($rollNos), '?'));
        $types = str_repeat('s', count($rollNos));
        $sql = "SELECT id, roll_no FROM paper_stock WHERE roll_no IN ($ph) ORDER BY id ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$rollNos);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $ids = [];
        $foundRollNos = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['id'];
            $foundRollNos[] = (string)$r['roll_no'];
        }

        echo json_encode([
            'ok' => true,
            'ids' => $ids,
            'found_roll_nos' => $foundRollNos,
            'missing_roll_nos' => array_values(array_diff($rollNos, $foundRollNos)),
        ]);
        break;

    // ─── Lookup single roll details for operator replacement check ───
    case 'get_roll_lookup':
        $rollNo = trim((string)($_GET['roll_no'] ?? ''));
        if ($rollNo === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing roll_no']);
            break;
        }

        $stmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, sqm, status, lot_batch_no, remarks FROM paper_stock WHERE roll_no = ? LIMIT 1");
        $stmt->bind_param('s', $rollNo);
        $stmt->execute();
        $roll = $stmt->get_result()->fetch_assoc();

        if (!$roll) {
            echo json_encode(['ok' => false, 'error' => 'Roll not found']);
            break;
        }

        echo json_encode(['ok' => true, 'roll' => $roll]);
        break;

    // ─── Suggest rolls for operator picker ────────────────
    case 'get_roll_suggestions':
        $q = trim((string)($_GET['q'] ?? ''));
        $paperType = trim((string)($_GET['paper_type'] ?? ''));
        $company = trim((string)($_GET['company'] ?? ''));
        $limit = min(300, max(20, (int)($_GET['limit'] ?? 120)));

        $allowedStatuses = ['main', 'stock', 'job assign', 'available'];
        $where = ["roll_no IS NOT NULL", "roll_no <> ''", "LOWER(COALESCE(status,'')) IN ('main','stock','job assign','available')"];
        $params = [];
        $types = '';

        if ($q !== '') {
            $where[] = "(roll_no LIKE ? OR paper_type LIKE ? OR company LIKE ?)";
            $likeQ = '%' . $q . '%';
            $params[] = $likeQ;
            $params[] = $likeQ;
            $params[] = $likeQ;
            $types .= 'sss';
        }
        if ($paperType !== '') {
            $where[] = "paper_type = ?";
            $params[] = $paperType;
            $types .= 's';
        }
        if ($company !== '') {
            $where[] = "company = ?";
            $params[] = $company;
            $types .= 's';
        }

        $sql = "SELECT id, roll_no, paper_type, company, status, width_mm, length_mtr, gsm
                FROM paper_stock
                WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC
                LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $fStmt = $db->prepare("SELECT DISTINCT paper_type, company
                               FROM paper_stock
                               WHERE roll_no IS NOT NULL
                                 AND roll_no <> ''
                                 AND LOWER(COALESCE(status,'')) IN ('main','stock','job assign','available')");
        $fStmt->execute();
        $fRows = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $paperTypes = [];
        $companies = [];
        foreach ($fRows as $fr) {
            $pt = trim((string)($fr['paper_type'] ?? ''));
            $co = trim((string)($fr['company'] ?? ''));
            if ($pt !== '') $paperTypes[$pt] = true;
            if ($co !== '') $companies[$co] = true;
        }

        echo json_encode([
            'ok' => true,
            'rolls' => $rows,
            'paper_types' => array_values(array_keys($paperTypes)),
            'companies' => array_values(array_keys($companies)),
        ]);
        break;

    // ─── Operator update: jumbo roll edits + wastage ───────
    case 'update_jumbo_rolls':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $rowsJson = (string)($_POST['rows_json'] ?? '[]');
        $operatorWastageKg = (float)($_POST['wastage_kg'] ?? 0);
        $operatorRemarks = trim((string)($_POST['operator_remarks'] ?? ''));

        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid job_id']);
            break;
        }

        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) $rows = [];

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND job_type = 'Slitting' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Jumbo job not found']);
            break;
        }

        $res = jobs_apply_jumbo_roll_changes($db, $job, $rows, $operatorWastageKg, $operatorRemarks, 'Operator remarks');
        if (!($res['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => 'Save failed: ' . ($res['error'] ?? 'Unknown')]);
            break;
        }
        echo json_encode(['ok' => true, 'job_id' => $jobId]);
        break;

    // ─── Submit jumbo edit as approval request ─────────────
    case 'submit_jumbo_change_request':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        jobs_ensure_change_request_table($db);

        $jobId = (int)($_POST['job_id'] ?? 0);
        $rowsJson = (string)($_POST['rows_json'] ?? '[]');
        $parentRollNo = trim((string)($_POST['parent_roll_no'] ?? ''));
        $operatorWastageKg = (float)($_POST['wastage_kg'] ?? 0);
        $operatorRemarks = trim((string)($_POST['operator_remarks'] ?? ''));
        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) $rows = [];

        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid job_id']);
            break;
        }

        $jobStmt = $db->prepare("SELECT id, job_no, job_type FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job || ($job['job_type'] ?? '') !== 'Slitting') {
            echo json_encode(['ok' => false, 'error' => 'Jumbo job not found']);
            break;
        }

        $pendingChk = $db->prepare("SELECT id FROM job_change_requests WHERE job_id = ? AND request_type = 'jumbo_roll_update' AND status = 'Pending' ORDER BY id DESC LIMIT 1");
        $pendingChk->bind_param('i', $jobId);
        $pendingChk->execute();
        $existing = $pendingChk->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['ok' => true, 'already_pending' => true, 'request_id' => (int)$existing['id']]);
            break;
        }

        $requestedBy = (int)($_SESSION['user_id'] ?? 0);
        $requestedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Operator')));
        $payload = [
            'job_id' => $jobId,
            'parent_roll_no' => $parentRollNo,
            'rows' => $rows,
            'generated_child_roll_nos' => array_values(array_map(function($row) {
                return (string)($row['roll_no'] ?? '');
            }, array_filter($rows, function($row) {
                return is_array($row) && strtolower(trim((string)($row['bucket'] ?? 'child'))) === 'child';
            }))),
            'wastage_kg' => $operatorWastageKg,
            'operator_remarks' => $operatorRemarks,
            'requested_at_iso' => date('c'),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ins = $db->prepare("INSERT INTO job_change_requests (job_id, request_type, payload_json, status, requested_by, requested_by_name) VALUES (?, 'jumbo_roll_update', ?, 'Pending', ?, ?)");
        $ins->bind_param('isis', $jobId, $payloadJson, $requestedBy, $requestedByName);
        $ins->execute();
        $rid = (int)$db->insert_id;

        $notifDept = 'jumbo_slitting';
        $notifMsg = 'Change request for ' . ($job['job_no'] ?? ('JOB-' . $jobId)) . ' submitted by ' . $requestedByName;
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'warning')");
        $nIns->bind_param('iss', $jobId, $notifDept, $notifMsg);
        $nIns->execute();

        echo json_encode(['ok' => true, 'request_id' => $rid]);
        break;

    // ─── List jumbo change requests ────────────────────────
    case 'list_jumbo_change_requests':
        jobs_ensure_change_request_table($db);

        $status = trim((string)($_GET['status'] ?? 'Pending'));
        $jobId = (int)($_GET['job_id'] ?? 0);
        $limit = min(300, max(1, (int)($_GET['limit'] ?? 100)));

        $where = ["request_type = 'jumbo_roll_update'"];
        $params = [];
        $types = '';

        if ($status !== '' && strtolower($status) !== 'all') {
            $where[] = 'r.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($jobId > 0) {
            $where[] = 'r.job_id = ?';
            $params[] = $jobId;
            $types .= 'i';
        }

        $sql = "SELECT r.*, j.job_no, j.status AS job_status
                FROM job_change_requests r
                LEFT JOIN jobs j ON r.job_id = j.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.id DESC
                LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as &$r) {
            $r['payload'] = json_decode((string)($r['payload_json'] ?? '{}'), true) ?: [];
        }
        unset($r);

        echo json_encode(['ok' => true, 'requests' => $rows]);
        break;

    // ─── Review jumbo change request ───────────────────────
    case 'review_jumbo_change_request':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        if (!hasRole('admin', 'manager')) {
            echo json_encode(['ok' => false, 'error' => 'Only admin/manager can review requests']);
            break;
        }

        jobs_ensure_change_request_table($db);

        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = trim((string)($_POST['decision'] ?? ''));
        $reviewNote = trim((string)($_POST['review_note'] ?? ''));
        if ($requestId <= 0 || !in_array($decision, ['Approved', 'Rejected'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request or decision']);
            break;
        }

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND request_type = 'jumbo_roll_update' LIMIT 1");
        $reqStmt->bind_param('i', $requestId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        if (!$req) {
            echo json_encode(['ok' => false, 'error' => 'Request not found']);
            break;
        }
        if (($req['status'] ?? '') !== 'Pending') {
            echo json_encode(['ok' => false, 'error' => 'Request already reviewed']);
            break;
        }

        $jobId = (int)($req['job_id'] ?? 0);
        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $payload = json_decode((string)($req['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];

        if ($decision === 'Approved') {
            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
            $wkg = (float)($payload['wastage_kg'] ?? 0);
            $remarks = trim((string)($payload['operator_remarks'] ?? ''));
            $res = jobs_apply_jumbo_roll_changes($db, $job, $rows, $wkg, $remarks, 'Approved operator remarks');
            if (!($res['ok'] ?? false)) {
                echo json_encode(['ok' => false, 'error' => 'Approval apply failed: ' . ($res['error'] ?? 'Unknown')]);
                break;
            }
        }

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Admin')));
        $updReq = $db->prepare("UPDATE job_change_requests SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
        $updReq->bind_param('sissi', $decision, $reviewedBy, $reviewedByName, $reviewNote, $requestId);
        $updReq->execute();

        $notifMsg = 'Change request #' . $requestId . ' for ' . ($job['job_no'] ?? ('JOB-' . $jobId)) . ' ' . strtolower($decision);
        $notifDept = $job['department'] ?? 'jumbo_slitting';
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
        $ntype = ($decision === 'Approved') ? 'success' : 'error';
        $nIns->bind_param('isss', $jobId, $notifDept, $notifMsg, $ntype);
        $nIns->execute();

        echo json_encode(['ok' => true, 'request_id' => $requestId, 'decision' => $decision]);
        break;

    // ─── List jobs by department ─────────────────────────────
    case 'list_jobs':
        $dept = trim($_GET['department'] ?? '');
        $jobType = trim($_GET['job_type'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));

        $where = ["(j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')"];
        $params = [];
        $types = '';

        if ($dept) { $where[] = "j.department = ?"; $params[] = $dept; $types .= 's'; }
        if ($jobType) { $where[] = "j.job_type = ?"; $params[] = $jobType; $types .= 's'; }
        if ($status) { $where[] = "j.status = ?"; $params[] = $status; $types .= 's'; }

        jobs_ensure_change_request_table($db);

        $sql = "SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
                       ps.status AS roll_status,
                       p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
                       prev.job_no AS prev_job_no, prev.status AS prev_job_status,
                       COALESCE(req.pending_count, 0) AS pending_change_requests
                FROM jobs j
                LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
                LEFT JOIN planning p ON j.planning_id = p.id
                LEFT JOIN jobs prev ON j.previous_job_id = prev.id
                LEFT JOIN (
                    SELECT job_id, COUNT(*) AS pending_count
                    FROM job_change_requests
                    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'
                    GROUP BY job_id
                ) req ON req.job_id = j.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY j.created_at DESC
                LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $db->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($jobs as &$j) {
            $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
        }
        unset($j);

        echo json_encode(['ok' => true, 'jobs' => $jobs]);
        break;

    // ─── Get notifications ──────────────────────────────────
    case 'get_notifications':
        $dept = trim($_GET['department'] ?? '');
        $unreadOnly = !empty($_GET['unread']);
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $where = [];
        $params = [];
        $types = '';

        if ($dept) { $where[] = "(n.department = ? OR n.department IS NULL)"; $params[] = $dept; $types .= 's'; }
        if ($unreadOnly) { $where[] = "n.is_read = 0"; }

        $sql = "SELECT n.*, j.job_no FROM job_notifications n LEFT JOIN jobs j ON n.job_id = j.id";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY n.created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $db->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['ok' => true, 'notifications' => $rows]);
        break;

    // ─── Mark notification read ─────────────────────────────
    case 'mark_notification_read':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        $nid = (int)($_POST['notification_id'] ?? 0);
        if ($nid) {
            $s = $db->prepare("UPDATE job_notifications SET is_read = 1 WHERE id = ?");
            $s->bind_param('i', $nid);
            $s->execute();
        } else {
            $db->query("UPDATE job_notifications SET is_read = 1");
        }
        echo json_encode(['ok' => true]);
        break;

    // ─── Edit job (admin only) ──────────────────────────────
    case 'edit_job':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        $jobId = (int)($_POST['job_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if (!$jobId) { echo json_encode(['ok' => false, 'error' => 'Missing job_id']); break; }
        $upd = $db->prepare("UPDATE jobs SET notes = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $notes, $jobId);
        $upd->execute();
        echo json_encode(['ok' => true, 'job_id' => $jobId]);
        break;

    // ─── Delete job (admin only, soft delete) ───────────────
    case 'delete_job':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        $jobId = (int)($_POST['job_id'] ?? 0);
        if (!$jobId) { echo json_encode(['ok' => false, 'error' => 'Missing job_id']); break; }
        $upd = $db->prepare("UPDATE jobs SET deleted_at = NOW() WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('i', $jobId);
        $upd->execute();
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'deleted' => $upd->affected_rows > 0]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Throwable $th) {
    if ($db->in_transaction ?? false) $db->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $th->getMessage()]);
}
