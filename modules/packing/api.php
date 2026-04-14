<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_data.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_REQUEST['action'] ?? ''));

if ($action === 'job_details') {
    $jobId = (int)($_GET['job_id'] ?? 0);
    $details = packing_fetch_job_details($db, $jobId);
    if (!$details) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Job not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'job' => $details]);
    exit;
}

if ($action === 'resolve_paper_stock_id') {
    $jobId = (int)($_GET['job_id'] ?? 0);
    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid job id']);
        exit;
    }

    $stmtJob = $db->prepare("SELECT roll_no FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
    if (!$stmtJob) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Job lookup prepare failed']);
        exit;
    }
    $stmtJob->bind_param('i', $jobId);
    $stmtJob->execute();
    $jobRes = $stmtJob->get_result();
    $jobRow = $jobRes ? $jobRes->fetch_assoc() : null;
    $stmtJob->close();

    if (!$jobRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Job not found']);
        exit;
    }

    $rollNo = trim((string)($jobRow['roll_no'] ?? ''));
    if ($rollNo === '') {
        echo json_encode(['ok' => true, 'paper_stock_id' => 0]);
        exit;
    }

    $stmtPs = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ? OR company_roll_no = ? ORDER BY id DESC LIMIT 1");
    if (!$stmtPs) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Paper stock lookup prepare failed']);
        exit;
    }
    $stmtPs->bind_param('ss', $rollNo, $rollNo);
    $stmtPs->execute();
    $psRes = $stmtPs->get_result();
    $psRow = $psRes ? $psRes->fetch_assoc() : null;
    $stmtPs->close();

    echo json_encode(['ok' => true, 'paper_stock_id' => (int)($psRow['id'] ?? 0)]);
    exit;
}

if ($action === 'delete_job') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only admin can delete job']);
        exit;
    }

    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid job id']);
        exit;
    }

    $stmt = $db->prepare("\n        UPDATE jobs\n        SET deleted_at = NOW(), updated_at = NOW()\n        WHERE id = ?\n          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n        LIMIT 1\n    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Delete prepare failed']);
        exit;
    }

    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Job not found or already deleted']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Job deleted successfully']);
    exit;
}

if ($action === 'mark_packing_done') {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid job id']);
        exit;
    }

    $statusCheck = $db->prepare("SELECT status FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
    if (!$statusCheck) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Status check prepare failed']);
        exit;
    }
    $statusCheck->bind_param('i', $jobId);
    $statusCheck->execute();
    $statusRes = $statusCheck->get_result();
    $statusRow = $statusRes ? $statusRes->fetch_assoc() : null;
    $statusCheck->close();

    if (!$statusRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Job not found']);
        exit;
    }

    $currentStatus = strtolower(trim((string)($statusRow['status'] ?? '')));
    if ($currentStatus === 'packing done') {
        echo json_encode(['ok' => true, 'already_done' => true, 'status' => 'Packing Done']);
        exit;
    }

    if (!in_array($currentStatus, ['complete', 'completed'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Only Complete jobs can be marked as Packing Done']);
        exit;
    }

    $stmt = $db->prepare("\n        UPDATE jobs\n        SET status = 'Packing Done', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()\n        WHERE id = ?\n          AND LOWER(TRIM(status)) IN ('complete', 'completed')\n          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n        LIMIT 1\n    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Update prepare failed']);
        exit;
    }
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
        echo json_encode(['ok' => true, 'already_done' => true, 'status' => 'Packing Done']);
        exit;
    }

    echo json_encode(['ok' => true, 'already_done' => false, 'status' => 'Packing Done']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Invalid action']);
