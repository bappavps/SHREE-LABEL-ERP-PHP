<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_data.php';

header('Content-Type: application/json; charset=utf-8');

function packing_api_respond(array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"message":"Failed to encode response."}';
    }
    echo $json;
    exit;
}

$db = getDB();
packing_ensure_operator_entries_table($db);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_REQUEST['action'] ?? ''));
try {
    if ($action === 'job_details') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $details = packing_fetch_job_details($db, $jobId);
        if (!$details) {
            packing_api_respond(['ok' => false, 'message' => 'Job not found'], 404);
        }

        packing_api_respond(['ok' => true, 'job' => $details]);
    }

    if ($action === 'resolve_paper_stock_id') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $stmtJob = $db->prepare("SELECT roll_no FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if (!$stmtJob) {
            packing_api_respond(['ok' => false, 'message' => 'Job lookup prepare failed'], 500);
        }
        $stmtJob->bind_param('i', $jobId);
        $stmtJob->execute();
        $jobRes = $stmtJob->get_result();
        $jobRow = $jobRes ? $jobRes->fetch_assoc() : null;
        $stmtJob->close();

        if (!$jobRow) {
            packing_api_respond(['ok' => false, 'message' => 'Job not found'], 404);
        }

        $rollNo = trim((string)($jobRow['roll_no'] ?? ''));
        if ($rollNo === '') {
            packing_api_respond(['ok' => true, 'paper_stock_id' => 0]);
        }

        $stmtPs = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ? OR company_roll_no = ? ORDER BY id DESC LIMIT 1");
        if (!$stmtPs) {
            packing_api_respond(['ok' => false, 'message' => 'Paper stock lookup prepare failed'], 500);
        }
        $stmtPs->bind_param('ss', $rollNo, $rollNo);
        $stmtPs->execute();
        $psRes = $stmtPs->get_result();
        $psRow = $psRes ? $psRes->fetch_assoc() : null;
        $stmtPs->close();

        packing_api_respond(['ok' => true, 'paper_stock_id' => (int)($psRow['id'] ?? 0)]);
    }

    if ($action === 'delete_job') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        if (!isAdmin()) {
            packing_api_respond(['ok' => false, 'message' => 'Only admin can delete job'], 403);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $stmt = $db->prepare("\n        UPDATE jobs\n        SET deleted_at = NOW(), updated_at = NOW()\n        WHERE id = ?\n          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n        LIMIT 1\n    ");
        if (!$stmt) {
            packing_api_respond(['ok' => false, 'message' => 'Delete prepare failed'], 500);
        }

        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $affected = (int)$stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            packing_api_respond(['ok' => false, 'message' => 'Job not found or already deleted'], 404);
        }

        packing_api_respond(['ok' => true, 'message' => 'Job deleted successfully']);
    }

    if ($action === 'mark_packing_done') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $statusCheck = $db->prepare("SELECT status FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if (!$statusCheck) {
            packing_api_respond(['ok' => false, 'message' => 'Status check prepare failed'], 500);
        }
        $statusCheck->bind_param('i', $jobId);
        $statusCheck->execute();
        $statusRes = $statusCheck->get_result();
        $statusRow = $statusRes ? $statusRes->fetch_assoc() : null;
        $statusCheck->close();

        if (!$statusRow) {
            packing_api_respond(['ok' => false, 'message' => 'Job not found'], 404);
        }

        $currentStatus = strtolower(trim((string)($statusRow['status'] ?? '')));
        if ($currentStatus === 'packing done') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Packing Done']);
        }

        if (!in_array($currentStatus, ['complete', 'completed'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Only Complete jobs can be marked as Packing Done'], 409);
        }

        $stmt = $db->prepare("\n        UPDATE jobs\n        SET status = 'Packing Done', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()\n        WHERE id = ?\n          AND LOWER(TRIM(status)) IN ('complete', 'completed')\n          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n        LIMIT 1\n    ");
        if (!$stmt) {
            packing_api_respond(['ok' => false, 'message' => 'Update prepare failed'], 500);
        }
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $affected = (int)$stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Packing Done']);
        }

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Packing Done']);
    }

    if ($action === 'operator_submit') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $jobId   = (int)($_POST['job_id'] ?? 0);
        $jobNo   = trim((string)($_POST['job_no'] ?? ''));
        $planId  = (int)($_POST['planning_id'] ?? 0);

        if ($jobNo === '') {
            packing_api_respond(['ok' => false, 'message' => 'job_no is required'], 400);
        }

        $packedQty    = trim((string)($_POST['packed_qty'] ?? ''));
        $bundlesCount = trim((string)($_POST['bundles_count'] ?? ''));
        $cartonsCount = trim((string)($_POST['cartons_count'] ?? ''));
        $wastageQty   = trim((string)($_POST['wastage_qty'] ?? ''));
        $looseQty     = trim((string)($_POST['loose_qty'] ?? ''));
        $notes        = trim((string)($_POST['notes'] ?? ''));

        $packedQtyVal    = is_numeric($packedQty)    ? (float)$packedQty    : null;
        $bundlesCountVal = is_numeric($bundlesCount) ? (int)$bundlesCount   : null;
        $cartonsCountVal = is_numeric($cartonsCount) ? (int)$cartonsCount   : null;
        $wastageQtyVal   = is_numeric($wastageQty)   ? (float)$wastageQty   : null;
        $looseQtyVal     = is_numeric($looseQty)     ? (float)$looseQty     : null;

        $operatorId   = (int)($_SESSION['user_id']   ?? 0);
        $operatorName = trim((string)($_SESSION['user_name'] ?? ''));
        if ($operatorName === '') $operatorName = 'Operator';

        $canAdminOverrideEditLock = false;
        if (function_exists('isAdmin') && isAdmin()) {
            $canAdminOverrideEditLock = true;
        } elseif (function_exists('hasRole') && hasRole('system_admin', 'super_admin')) {
            $canAdminOverrideEditLock = true;
        }

        // Handle optional photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $uploadDir = __DIR__ . '/../../uploads/packing/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo((string)($_FILES['photo']['name'] ?? ''), PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowedExts, true)) {
                packing_api_respond(['ok' => false, 'message' => 'Photo must be jpg/jpeg/png/webp'], 400);
            }
            $maxSize = 5 * 1024 * 1024; // 5 MB
            if ((int)($_FILES['photo']['size'] ?? 0) > $maxSize) {
                packing_api_respond(['ok' => false, 'message' => 'Photo must be under 5 MB'], 400);
            }
            $safeName = 'pkg_' . preg_replace('/[^a-z0-9]/', '', strtolower($jobNo)) . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $safeName;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                $photoPath = 'uploads/packing/' . $safeName;
            }
        }

        $submittedAt = date('Y-m-d H:i:s');

        // Upsert into packing_operator_entries
        $existing = packing_fetch_operator_entry($db, $jobNo);
        if ($existing) {
            if (!$canAdminOverrideEditLock) {
                packing_api_respond([
                    'ok' => false,
                    'message' => 'Entry already submitted. Only admin can edit or delete it.'
                ], 403);
            }

            $stmt = $db->prepare("
                UPDATE packing_operator_entries
                SET job_id=?, planning_id=?, operator_id=?, operator_name=?,
                    packed_qty=?, bundles_count=?, cartons_count=?, wastage_qty=?, loose_qty=?,
                    notes=?, photo_path=COALESCE(?,photo_path), submitted_at=?, updated_at=NOW()
                WHERE job_no=?
            ");
            if (!$stmt) packing_api_respond(['ok' => false, 'message' => 'Prepare failed'], 500);
            $stmt->bind_param('iiisdiiddssss',
                $jobId, $planId, $operatorId, $operatorName,
                $packedQtyVal, $bundlesCountVal, $cartonsCountVal, $wastageQtyVal, $looseQtyVal,
                $notes, $photoPath, $submittedAt, $jobNo
            );
        } else {
            $stmt = $db->prepare("
                INSERT INTO packing_operator_entries
                    (job_no, job_id, planning_id, operator_id, operator_name,
                     packed_qty, bundles_count, cartons_count, wastage_qty, loose_qty,
                     notes, photo_path, submitted_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            if (!$stmt) packing_api_respond(['ok' => false, 'message' => 'Prepare failed'], 500);
            $stmt->bind_param('siiisdiiddsss',
                $jobNo, $jobId, $planId, $operatorId, $operatorName,
                $packedQtyVal, $bundlesCountVal, $cartonsCountVal, $wastageQtyVal, $looseQtyVal,
                $notes, $photoPath, $submittedAt
            );
        }

        $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if ($err !== '') {
            packing_api_respond(['ok' => false, 'message' => 'DB error: ' . $err], 500);
        }

        $entry = packing_fetch_operator_entry($db, $jobNo);
        packing_api_respond(['ok' => true, 'message' => 'Packing entry submitted successfully', 'entry' => $entry]);
    }

    packing_api_respond(['ok' => false, 'message' => 'Invalid action'], 400);
} catch (Throwable $e) {
    packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
}
