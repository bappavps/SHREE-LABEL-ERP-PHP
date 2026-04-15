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

    packing_api_respond(['ok' => false, 'message' => 'Invalid action'], 400);
} catch (Throwable $e) {
    packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
}
