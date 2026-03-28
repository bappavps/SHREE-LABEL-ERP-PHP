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

function jobs_ensure_delete_audit_table(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS job_delete_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        root_job_id INT NOT NULL,
        root_job_no VARCHAR(60) NULL,
        root_job_type VARCHAR(50) NULL,
        planning_id INT NULL,
        parent_roll_no VARCHAR(80) NULL,
        action_status VARCHAR(20) NOT NULL DEFAULT 'completed',
        deleted_root TINYINT(1) NOT NULL DEFAULT 0,
        deleted_child_jobs INT NOT NULL DEFAULT 0,
        removed_child_rolls INT NOT NULL DEFAULT 0,
        parent_restored TINYINT(1) NOT NULL DEFAULT 0,
        planning_restored TINYINT(1) NOT NULL DEFAULT 0,
        blocked_jobs_json LONGTEXT NULL,
        reset_snapshot_json LONGTEXT NULL,
        requested_by INT NULL,
        requested_by_name VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_jda_root_job_id (root_job_id),
        INDEX idx_jda_status (action_status),
        INDEX idx_jda_created_at (created_at)
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

function jobs_department_label(string $department): string {
    $department = strtolower(trim($department));
    $map = [
        'jumbo_slitting' => 'Jumbo Slitting',
        'flexo_printing' => 'Flexo Printing',
        'qc' => 'QC',
        'packing' => 'Packing',
    ];
    if (isset($map[$department])) return $map[$department];
    $department = str_replace('_', ' ', $department);
    return trim((string)preg_replace('/\s+/', ' ', ucwords($department)));
}

function jobs_display_job_name(array $job): string {
    $planningName = trim((string)($job['planning_job_name'] ?? ''));
    if ($planningName !== '') return $planningName;

    $jobNo = trim((string)($job['job_no'] ?? ''));
    $dept = jobs_department_label((string)($job['department'] ?? ''));
    if ($jobNo !== '') return $dept !== '' ? ($jobNo . ' (' . $dept . ')') : $jobNo;

    if ($dept !== '') {
        $seq = (int)($job['sequence_order'] ?? 0);
        return $seq > 0 ? ($dept . ' #' . $seq) : $dept;
    }

    return '—';
}

function jobs_collect_roll_nos(array $extra, array $job): array {
    $rollNos = [];
    $jobRoll = trim((string)($job['roll_no'] ?? ''));
    if ($jobRoll !== '') $rollNos[$jobRoll] = true;

    $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
    if ($parentRoll !== '') $rollNos[$parentRoll] = true;

    foreach (['child_rolls', 'stock_rolls'] as $bucket) {
        $rows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
        foreach ($rows as $r) {
            $rn = trim((string)($r['roll_no'] ?? ''));
            if ($rn !== '') $rollNos[$rn] = true;
        }
    }
    return array_values(array_keys($rollNos));
}

function jobs_fetch_roll_map(mysqli $db, array $rollNos): array {
    if (empty($rollNos)) return [];
    $ph = implode(',', array_fill(0, count($rollNos), '?'));
    $types = str_repeat('s', count($rollNos));
    $sql = "SELECT roll_no, remarks, status, width_mm, length_mtr, gsm, weight_kg, paper_type, company FROM paper_stock WHERE roll_no IN ($ph)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$rollNos);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $k = (string)($r['roll_no'] ?? '');
        if ($k === '') continue;
        $map[$k] = [
            'remarks'    => (string)($r['remarks'] ?? ''),
            'status'     => (string)($r['status'] ?? ''),
            'width_mm'   => (float)($r['width_mm'] ?? 0),
            'length_mtr' => (float)($r['length_mtr'] ?? 0),
            'gsm'        => (float)($r['gsm'] ?? 0),
            'weight_kg'  => (float)($r['weight_kg'] ?? 0),
            'paper_type' => (string)($r['paper_type'] ?? ''),
            'company'    => (string)($r['company'] ?? ''),
        ];
    }
    return $map;
}

function jobs_attach_live_roll_data(array &$job, array $rollMap): void {
    $extra = $job['extra_data_parsed'] ?? json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
    if (!is_array($extra)) $extra = [];

    $job['display_job_name'] = jobs_display_job_name($job);
    $job['live_roll_map'] = $rollMap;

    $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
    $job['live_parent_remarks'] = '';
    if ($parentRoll !== '' && isset($rollMap[$parentRoll])) {
        $job['live_parent_remarks'] = (string)($rollMap[$parentRoll]['remarks'] ?? '');
    }

    foreach (['child_rolls', 'stock_rolls'] as $bucket) {
        $rows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
        foreach ($rows as &$r) {
            $rn = trim((string)($r['roll_no'] ?? ''));
            if ($rn !== '' && isset($rollMap[$rn])) {
                $r['remarks_live'] = (string)($rollMap[$rn]['remarks'] ?? '');
                $r['status_live'] = (string)($rollMap[$rn]['status'] ?? '');
            }
        }
        unset($r);
        $extra[$bucket] = $rows;
    }
    $job['extra_data_parsed'] = $extra;
}

function jobs_get_chain_jobs(mysqli $db, int $rootJobId): array {
    $chain = [];
    $seen = [];
    $queue = [$rootJobId];

    while (!empty($queue)) {
        $currentId = (int)array_shift($queue);
        if ($currentId <= 0 || isset($seen[$currentId])) continue;
        $seen[$currentId] = true;

        $selfStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $selfStmt->bind_param('i', $currentId);
        $selfStmt->execute();
        $self = $selfStmt->get_result()->fetch_assoc();
        if (!$self) continue;
        $chain[$currentId] = $self;

        $childStmt = $db->prepare("SELECT id FROM jobs WHERE previous_job_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $childStmt->bind_param('i', $currentId);
        $childStmt->execute();
        $childRows = $childStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($childRows as $cr) {
            $cid = (int)($cr['id'] ?? 0);
            if ($cid > 0 && !isset($seen[$cid])) $queue[] = $cid;
        }
    }

    return array_values($chain);
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
            // Update primary roll
            if (!empty($job['roll_no'])) {
                $updParentRoll = $db->prepare("UPDATE paper_stock SET status = 'Slitting' WHERE roll_no = ? AND status IN ('Job Assign','Stock','Main')");
                $updParentRoll->bind_param('s', $job['roll_no']);
                $updParentRoll->execute();
            }
            // Update ALL parent rolls from extra_data.parent_rolls
            $extraStart = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
            $parentRollsStart = $extraStart['parent_rolls'] ?? [];
            if (is_string($parentRollsStart)) {
                $parentRollsStart = preg_split('/\s*,\s*/', trim($parentRollsStart), -1, PREG_SPLIT_NO_EMPTY);
            }
            if (is_array($parentRollsStart)) {
                foreach ($parentRollsStart as $pr) {
                    $prn = trim((string)$pr);
                    if ($prn !== '' && $prn !== ($job['roll_no'] ?? '')) {
                        $updPr = $db->prepare("UPDATE paper_stock SET status = 'Slitting' WHERE roll_no = ? AND status IN ('Job Assign','Stock','Main')");
                        $updPr->bind_param('s', $prn);
                        $updPr->execute();
                    }
                }
            }
        }

        // If completing a job, mark parent rolls as Slitted + child rolls as Job Assign
        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed'], true) && ($job['job_type'] ?? '') === 'Slitting') {
            // Update primary parent roll
            if ($job['roll_no']) {
                $updRoll = $db->prepare("UPDATE paper_stock SET status = 'Slitted' WHERE roll_no = ? AND status IN ('Slitting','Consumed')");
                $updRoll->bind_param('s', $job['roll_no']);
                $updRoll->execute();
            }
            // Update ALL parent rolls from extra_data
            $extraClose = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
            $parentRollsClose = $extraClose['parent_rolls'] ?? [];
            if (is_string($parentRollsClose)) {
                $parentRollsClose = preg_split('/\s*,\s*/', trim($parentRollsClose), -1, PREG_SPLIT_NO_EMPTY);
            }
            if (is_array($parentRollsClose)) {
                foreach ($parentRollsClose as $pr) {
                    $prn = trim((string)$pr);
                    if ($prn !== '' && $prn !== ($job['roll_no'] ?? '')) {
                        $updPr = $db->prepare("UPDATE paper_stock SET status = 'Slitted' WHERE roll_no = ? AND status IN ('Slitting','Consumed')");
                        $updPr->bind_param('s', $prn);
                        $updPr->execute();
                    }
                }
            }
            // Update child rolls: Slitting → Job Assign
            $childRollsClose = is_array($extraClose['child_rolls'] ?? null) ? $extraClose['child_rolls'] : [];
            foreach ($childRollsClose as $cr) {
                $crn = trim((string)($cr['roll_no'] ?? ''));
                if ($crn !== '') {
                    $updCr = $db->prepare("UPDATE paper_stock SET status = 'Job Assign' WHERE roll_no = ? AND status = 'Slitting'");
                    $updCr->bind_param('s', $crn);
                    $updCr->execute();
                }
            }
        } elseif (in_array($newStatus, ['Closed', 'Finalized', 'Completed'], true) && $job['roll_no']) {
            // Non-slitting jobs: simple parent roll update
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

        // Jumbo close/finalize moves planning into Slitting Completed stage.
        if (in_array($newStatus, ['Closed', 'Finalized'], true) && ($job['job_type'] ?? '') === 'Slitting') {
            $planId = (int)($job['planning_id'] ?? 0);
            if ($planId > 0) {
                $planNoStmt = $db->prepare("SELECT job_no FROM planning WHERE id = ? LIMIT 1");
                $planNoStmt->bind_param('i', $planId);
                $planNoStmt->execute();
                $planNoRow = $planNoStmt->get_result()->fetch_assoc();
                $planNo = trim((string)($planNoRow['job_no'] ?? ''));
                if ($planNo !== '') {
                    $updPlanNo = $db->prepare("UPDATE planning SET status = 'Slitting Completed' WHERE job_no = ?");
                    $updPlanNo->bind_param('s', $planNo);
                    $updPlanNo->execute();
                } else {
                    $updPlan = $db->prepare("UPDATE planning SET status = 'Slitting Completed' WHERE id = ?");
                    $updPlan->bind_param('i', $planId);
                    $updPlan->execute();
                }
            } else {
                $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
                $planNo = trim((string)($extra['plan_no'] ?? ''));
                if ($planNo !== '') {
                    $updPlanNo = $db->prepare("UPDATE planning SET status = 'Slitting Completed' WHERE job_no = ?");
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
        $rollNos = jobs_collect_roll_nos($job['extra_data_parsed'], $job);
        $rollMap = jobs_fetch_roll_map($db, $rollNos);
        jobs_attach_live_roll_data($job, $rollMap);
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
            $rollNos = jobs_collect_roll_nos($j['extra_data_parsed'], $j);
            $rollMap = jobs_fetch_roll_map($db, $rollNos);
            jobs_attach_live_roll_data($j, $rollMap);
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

    // ─── Delete job with reset (admin only) ─────────────────
    case 'delete_job':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        $jobId = (int)($_POST['job_id'] ?? 0);
        if (!$jobId) { echo json_encode(['ok' => false, 'error' => 'Missing job_id']); break; }

        jobs_ensure_delete_audit_table($db);
        $requestedBy = (int)($_SESSION['user_id'] ?? 0);
        $requestedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Unknown')));

        $rootStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $rootStmt->bind_param('i', $jobId);
        $rootStmt->execute();
        $rootJob = $rootStmt->get_result()->fetch_assoc();
        if (!$rootJob) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $chain = jobs_get_chain_jobs($db, $jobId);
        $blocked = [];
        foreach ($chain as $cj) {
            $cid = (int)($cj['id'] ?? 0);
            if ($cid === $jobId) continue;
            $st = trim((string)($cj['status'] ?? ''));
            if (!in_array($st, ['Queued', 'Pending'], true)) {
                $blocked[] = ['id' => $cid, 'job_no' => (string)($cj['job_no'] ?? ''), 'status' => $st];
            }
        }
        if (!empty($blocked)) {
            $blockedJson = json_encode($blocked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rootJobNo = (string)($rootJob['job_no'] ?? '');
            $rootType = (string)($rootJob['job_type'] ?? '');
            $planId = (int)($rootJob['planning_id'] ?? 0);
            $blockedParentRoll = trim((string)($rootJob['roll_no'] ?? ''));
            $auditBlocked = $db->prepare("INSERT INTO job_delete_audit (root_job_id, root_job_no, root_job_type, planning_id, parent_roll_no, action_status, blocked_jobs_json, requested_by, requested_by_name) VALUES (?, ?, ?, ?, ?, 'blocked', ?, ?, ?)");
            if ($auditBlocked) {
                $auditBlocked->bind_param('ississis', $jobId, $rootJobNo, $rootType, $planId, $blockedParentRoll, $blockedJson, $requestedBy, $requestedByName);
                $auditBlocked->execute();
            }
            echo json_encode([
                'ok' => false,
                'error' => 'Delete blocked: downstream jobs already progressed.',
                'blocked_jobs' => $blocked,
            ]);
            break;
        }

        $db->begin_transaction();
        try {
            $deletedChildJobs = 0;
            $deletedRoot = 0;
            $removedRolls = 0;
            $restoredParent = false;
            $restoredPlanning = false;
            $parentRollForAudit = trim((string)($rootJob['roll_no'] ?? ''));

            if (($rootJob['job_type'] ?? '') === 'Slitting') {
                $extra = json_decode((string)($rootJob['extra_data'] ?? '{}'), true) ?: [];
                $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
                $parentRemarks = trim((string)($extra['parent_details']['remarks'] ?? ''));
                if ($parentRoll !== '') $parentRollForAudit = $parentRoll;

                $childRolls = [];
                foreach (['child_rolls', 'stock_rolls'] as $bucket) {
                    $rows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
                    foreach ($rows as $r) {
                        $rn = trim((string)($r['roll_no'] ?? ''));
                        if ($rn !== '') $childRolls[$rn] = true;
                    }
                }

                if (!empty($childRolls)) {
                    $list = array_values(array_keys($childRolls));
                    $ph = implode(',', array_fill(0, count($list), '?'));
                    $types = str_repeat('s', count($list));
                    $delSql = "DELETE FROM paper_stock WHERE roll_no IN ($ph)";
                    $delStmt = $db->prepare($delSql);
                    $delStmt->bind_param($types, ...$list);
                    $delStmt->execute();
                    $removedRolls = (int)$delStmt->affected_rows;
                }

                // Collect all unique parent rolls (primary + any additional from multi-roll slitting)
                $parentRollsToRestore = [];
                if ($parentRoll !== '') $parentRollsToRestore[$parentRoll] = true;
                $rootRollNo = trim((string)($rootJob['roll_no'] ?? ''));
                if ($rootRollNo !== '' && !isset($parentRollsToRestore[$rootRollNo])) {
                    $parentRollsToRestore[$rootRollNo] = true;
                }
                foreach (['child_rolls', 'stock_rolls'] as $bk) {
                    foreach (is_array($extra[$bk] ?? null) ? $extra[$bk] : [] as $bkRow) {
                        $bkPrn = trim((string)($bkRow['parent_roll_no'] ?? ''));
                        if ($bkPrn !== '') $parentRollsToRestore[$bkPrn] = true;
                    }
                }
                // Use original_status from parent_details (saved at slitting time); map invalid states to 'Main'
                $primaryOriginalStatus = trim((string)($extra['parent_details']['original_status'] ?? ''));
                if ($primaryOriginalStatus === '' || in_array($primaryOriginalStatus, ['Consumed', 'Slitting'], true)) {
                    $primaryOriginalStatus = 'Main';
                }
                foreach (array_keys($parentRollsToRestore) as $pRollToRestore) {
                    $restoreStatus  = ($pRollToRestore === $parentRoll) ? $primaryOriginalStatus : 'Main';
                    $restoreRemarks = ($pRollToRestore === $parentRoll) ? $parentRemarks : '';
                    $upParent = $db->prepare("UPDATE paper_stock SET status = ?, date_used = NULL, remarks = ? WHERE roll_no = ?");
                    $upParent->bind_param('sss', $restoreStatus, $restoreRemarks, $pRollToRestore);
                    $upParent->execute();
                    if ($upParent->affected_rows > 0) $restoredParent = true;
                }

                $planId = (int)($rootJob['planning_id'] ?? 0);
                if ($planId > 0) {
                    $queued = 'Queued';
                    $upPlan = $db->prepare("UPDATE planning SET status = ? WHERE id = ?");
                    $upPlan->bind_param('si', $queued, $planId);
                    $upPlan->execute();
                    $restoredPlanning = $upPlan->affected_rows > 0;

                    $planExtraStmt = $db->prepare("SELECT extra_data FROM planning WHERE id = ? LIMIT 1");
                    if ($planExtraStmt) {
                        $planExtraStmt->bind_param('i', $planId);
                        $planExtraStmt->execute();
                        $planRow = $planExtraStmt->get_result()->fetch_assoc();
                        if ($planRow) {
                            $pExtra = json_decode((string)($planRow['extra_data'] ?? '{}'), true) ?: [];
                            $pExtra['printing_planning'] = 'Queued';
                            $pExtraJson = json_encode($pExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $upPlanExtra = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
                            if ($upPlanExtra) {
                                $upPlanExtra->bind_param('si', $pExtraJson, $planId);
                                $upPlanExtra->execute();
                            }
                        }
                    }
                }
            }

            foreach ($chain as $cj) {
                $cid = (int)($cj['id'] ?? 0);
                if ($cid <= 0 || $cid === $jobId) continue;
                $delChild = $db->prepare("UPDATE jobs SET deleted_at = NOW() WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
                $delChild->bind_param('i', $cid);
                $delChild->execute();
                $deletedChildJobs += max(0, (int)$delChild->affected_rows);
            }

            $delRoot = $db->prepare("UPDATE jobs SET deleted_at = NOW() WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $delRoot->bind_param('i', $jobId);
            $delRoot->execute();
            $deletedRoot = (int)$delRoot->affected_rows;

            $chainSummary = array_map(function($row) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'job_no' => (string)($row['job_no'] ?? ''),
                    'status' => (string)($row['status'] ?? ''),
                    'job_type' => (string)($row['job_type'] ?? ''),
                ];
            }, $chain);
            $snapshot = [
                'root_job_id' => $jobId,
                'root_job_no' => (string)($rootJob['job_no'] ?? ''),
                'root_job_type' => (string)($rootJob['job_type'] ?? ''),
                'planning_id' => (int)($rootJob['planning_id'] ?? 0),
                'chain_jobs' => $chainSummary,
                'result' => [
                    'deleted_root' => $deletedRoot,
                    'deleted_child_jobs' => $deletedChildJobs,
                    'removed_child_rolls' => $removedRolls,
                    'parent_restored' => $restoredParent,
                    'planning_restored' => $restoredPlanning,
                ],
            ];
            $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $rootJobNo = (string)($rootJob['job_no'] ?? '');
            $rootType = (string)($rootJob['job_type'] ?? '');
            $planId = (int)($rootJob['planning_id'] ?? 0);
            $auditCompleted = $db->prepare("INSERT INTO job_delete_audit (root_job_id, root_job_no, root_job_type, planning_id, parent_roll_no, action_status, deleted_root, deleted_child_jobs, removed_child_rolls, parent_restored, planning_restored, reset_snapshot_json, requested_by, requested_by_name) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($auditCompleted) {
                $auditCompleted->bind_param('issisiiiiisis', $jobId, $rootJobNo, $rootType, $planId, $parentRollForAudit, $deletedRoot, $deletedChildJobs, $removedRolls, $restoredParent, $restoredPlanning, $snapshotJson, $requestedBy, $requestedByName);
                $auditCompleted->execute();
            }

            $db->commit();
            echo json_encode([
                'ok' => true,
                'job_id' => $jobId,
                'deleted' => $deletedRoot > 0,
                'reset' => [
                    'deleted_root' => $deletedRoot,
                    'deleted_child_jobs' => $deletedChildJobs,
                    'removed_child_rolls' => $removedRolls,
                    'parent_restored' => $restoredParent,
                    'planning_restored' => $restoredPlanning,
                ],
            ]);
        } catch (Throwable $th) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => 'Delete reset failed: ' . $th->getMessage()]);
        }
        break;

    // ─── Fetch delete-reset audit log ──────────────────────
    case 'get_delete_audit':
        jobs_ensure_delete_audit_table($db);
        $dalLimit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $dalStatus = trim($_GET['status'] ?? '');
        $dalWhere  = [];
        $dalParams = [];
        $dalTypes  = '';
        if (in_array($dalStatus, ['completed', 'blocked'], true)) {
            $dalWhere[]  = 'action_status = ?';
            $dalParams[] = $dalStatus;
            $dalTypes   .= 's';
        }
        $dalSql = "SELECT id, root_job_no, root_job_type, parent_roll_no, action_status,
                          deleted_root, deleted_child_jobs, removed_child_rolls,
                          parent_restored, planning_restored,
                          requested_by_name, created_at
                   FROM job_delete_audit"
                . ($dalWhere ? ' WHERE ' . implode(' AND ', $dalWhere) : '')
                . ' ORDER BY id DESC LIMIT ?';
        $dalParams[] = $dalLimit;
        $dalTypes   .= 'i';
        $dalStmt = $db->prepare($dalSql);
        $dalStmt->bind_param($dalTypes, ...$dalParams);
        $dalStmt->execute();
        echo json_encode(['ok' => true, 'records' => $dalStmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Throwable $th) {
    if ($db->in_transaction ?? false) $db->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $th->getMessage()]);
}
