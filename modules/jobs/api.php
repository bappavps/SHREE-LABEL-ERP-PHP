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

try {
    switch ($action) {

    // ─── Update job status ──────────────────────────────────
    case 'update_status':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId     = (int)($_POST['job_id'] ?? 0);
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

        // If completing a job, update linked paper_stock roll status
        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed'], true) && $job['roll_no']) {
            $updRoll = $db->prepare("UPDATE paper_stock SET status = 'Job Assign' WHERE roll_no = ? AND status = 'Slitting'");
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

        $sql = "SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
                       ps.status AS roll_status,
                       p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
                       prev.job_no AS prev_job_no, prev.status AS prev_job_status
                FROM jobs j
                LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
                LEFT JOIN planning p ON j.planning_id = p.id
                LEFT JOIN jobs prev ON j.previous_job_id = prev.id
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
