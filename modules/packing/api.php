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

function packing_api_can_finalize(): bool {
    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }
    if (function_exists('hasRole') && hasRole('manager', 'system_admin', 'super_admin')) {
        return true;
    }
    return false;
}

function packing_api_ensure_finished_goods_table(mysqli $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS finished_goods_stock (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        category VARCHAR(60) NOT NULL,
        sub_type VARCHAR(120) NOT NULL DEFAULT '',
        item_name VARCHAR(255) NOT NULL DEFAULT '',
        item_code VARCHAR(120) NOT NULL DEFAULT '',
        size VARCHAR(120) NOT NULL DEFAULT '',
        gsm VARCHAR(60) NOT NULL DEFAULT '',
        quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit VARCHAR(30) NOT NULL DEFAULT '',
        location VARCHAR(120) NOT NULL DEFAULT '',
        batch_no VARCHAR(120) NOT NULL DEFAULT '',
        date DATE DEFAULT NULL,
        remarks TEXT,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_fg_category (category),
        KEY idx_fg_item_code (item_code),
        KEY idx_fg_batch (batch_no),
        KEY idx_fg_date (date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($sql)) {
        packing_api_respond(['ok' => false, 'message' => 'Unable to initialize finished goods table: ' . $db->error], 500);
    }
}

function packing_api_assigned_child_rolls(array $jobDetails): array {
    $jobExtra = is_array($jobDetails['job_extra_data'] ?? null) ? $jobDetails['job_extra_data'] : [];
    foreach (['assigned_child_rolls', 'child_rolls', 'selected_rolls', 'rolls'] as $key) {
        $rows = $jobExtra[$key] ?? null;
        if (!is_array($rows) || !$rows) {
            continue;
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rollNo = trim((string)($row['roll_no'] ?? ''));
            if ($rollNo === '') {
                continue;
            }
            $out[] = $row;
        }
        if ($out) {
            return $out;
        }
    }
    return [];
}

function packing_api_company_short_code(string $company): string {
    $company = strtoupper(trim($company));
    if ($company === '') {
        return '';
    }
    $words = preg_split('/[^A-Z0-9]+/', $company, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '';
    }
    if (count($words) >= 2) {
        $code = '';
        foreach ($words as $word) {
            $code .= substr($word, 0, 1);
        }
        return substr($code, 0, 3);
    }
    return substr($words[0], 0, 2);
}

function packing_api_build_pos_barcode(array $childRolls): string {
    if (!$childRolls) {
        return '';
    }

    $rollNos = [];
    $companyCodes = [];
    foreach ($childRolls as $row) {
        $rollNo = trim((string)($row['roll_no'] ?? ''));
        if ($rollNo !== '') {
            $rollNos[] = $rollNo;
        }
        $companyCode = packing_api_company_short_code((string)($row['company'] ?? ''));
        if ($companyCode !== '') {
            $companyCodes[] = $companyCode;
        }
    }
    $rollNos = array_values(array_unique($rollNos));
    $companyCodes = array_values(array_unique($companyCodes));

    $barcode = implode(',', $rollNos);
    if ($barcode === '') {
        return '';
    }

    if (count($companyCodes) > 1) {
        $barcode .= ' / ' . end($companyCodes);
    } elseif (count($companyCodes) === 1) {
        $barcode .= ' / ' . $companyCodes[0];
    }

    return $barcode;
}

function packing_api_build_job_barcode(array $jobDetails): string {
    $jobId = (int)($jobDetails['id'] ?? 0);
    if ($jobId > 0) {
        return 'J' . strtoupper(base_convert((string)$jobId, 10, 36));
    }
    $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
    if ($jobNo !== '') {
        return 'JOB:' . $jobNo;
    }
    return '';
}

function packing_api_upsert_finished_goods(mysqli $db, array $jobDetails, array $operatorEntry, int $userId): void {
    packing_api_ensure_finished_goods_table($db);

    $packingId = trim((string)($jobDetails['packing_display_id'] ?? ''));
    $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
    $itemName = trim((string)($jobDetails['plan_name'] ?? ''));
    $clientName = trim((string)($jobDetails['client_name'] ?? ''));
    $planExtra = is_array($jobDetails['plan_extra_data'] ?? null) ? $jobDetails['plan_extra_data'] : [];
    $jobExtra = is_array($jobDetails['job_extra_data'] ?? null) ? $jobDetails['job_extra_data'] : [];
    $operatorPayload = packing_decode_json($operatorEntry['roll_payload_json'] ?? null);
    $rollOverrides = is_array($operatorPayload['roll_overrides'] ?? null) ? $operatorPayload['roll_overrides'] : [];
    $childRolls = packing_api_assigned_child_rolls($jobDetails);
    $firstOverride = [];
    if (!empty($rollOverrides)) {
        $firstOverride = reset($rollOverrides);
        if (!is_array($firstOverride)) {
            $firstOverride = [];
        }
    }

    $width = trim((string)($planExtra['item_width'] ?? ($jobDetails['paper_width_mm'] ?? ($planExtra['width_mm'] ?? ''))));
    $length = trim((string)($planExtra['item_length'] ?? ($jobDetails['paper_length_mtr'] ?? ($planExtra['length_mtr'] ?? ''))));
    $size = trim((string)($planExtra['paper_size'] ?? ''));
    if ($size === '' && ($width !== '' || $length !== '')) {
        $size = trim($width . (($width !== '' && $length !== '') ? ' x ' : '') . $length);
    }
    if ($size === '') {
        $size = trim((string)($jobDetails['paper_width_mm'] ?? ''));
    }
    $gsm = trim((string)($planExtra['gsm'] ?? ($jobDetails['paper_gsm'] ?? ($jobExtra['gsm'] ?? ''))));
    $quantity = round((float)($operatorEntry['packed_qty'] ?? 0), 3);
    $dateValue = date('Y-m-d');
    $coreSize = trim((string)($planExtra['core_size'] ?? ($planExtra['core'] ?? '')));
    $coreType = trim((string)($planExtra['core_type'] ?? ''));
    $paperCompanies = [];
    foreach ($childRolls as $row) {
        $company = trim((string)($row['company'] ?? ''));
        if ($company !== '' && !in_array($company, $paperCompanies, true)) {
            $paperCompanies[] = $company;
        }
    }
    $paperCompany = $paperCompanies ? implode(', ', $paperCompanies) : trim((string)($jobDetails['paper_company'] ?? ($planExtra['paper_company_name'] ?? ($planExtra['paper_company'] ?? ''))));
    $materialType = trim((string)($planExtra['material_type'] ?? ($jobDetails['paper_type'] ?? ($jobExtra['material_type'] ?? ''))));
    $barcode = packing_api_build_job_barcode($jobDetails);
    if ($barcode === '') {
        $barcode = packing_api_build_pos_barcode($childRolls);
    }
    $perCarton = (int)($firstOverride['rpc'] ?? 0);
    $cartonCount = (int)round((float)($operatorEntry['cartons_count'] ?? 0));
    $totalValue = $quantity;

    if ($packingId === '' || $jobNo === '' || $quantity <= 0) {
        packing_api_respond(['ok' => false, 'message' => 'Finished goods requires packing id, job no, and submitted physical quantity'], 409);
    }

    $remarksPayload = [
        'note' => 'Auto Generated',
        'extra' => [
            'packing_id' => $packingId,
            'width' => $width,
            'length' => $length,
            'core_size' => $coreSize,
            'core_type' => $coreType,
            'paper_company' => $paperCompany,
            'material_type' => $materialType,
            'barcode' => $barcode,
            'per_carton' => $perCarton > 0 ? $perCarton : '',
            'carton' => $cartonCount > 0 ? $cartonCount : '',
            'total' => $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '',
        ],
    ];
    $remarks = json_encode($remarksPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($remarks === false) {
        $remarks = '';
    }

    $existingStmt = $db->prepare("SELECT id FROM finished_goods_stock WHERE category = 'pos_paper_roll' AND item_code = ? AND batch_no = ? ORDER BY id DESC LIMIT 1");
    if (!$existingStmt) {
        packing_api_respond(['ok' => false, 'message' => 'Finished goods lookup prepare failed'], 500);
    }
    $existingStmt->bind_param('ss', $packingId, $jobNo);
    $existingStmt->execute();
    $existingRow = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existingRow) {
        $stockId = (int)($existingRow['id'] ?? 0);
        $updateStmt = $db->prepare("UPDATE finished_goods_stock SET sub_type = ?, item_name = ?, size = ?, gsm = ?, quantity = ?, unit = 'PCS', location = 'Packing', date = ?, remarks = ? WHERE id = ? LIMIT 1");
        if (!$updateStmt) {
            packing_api_respond(['ok' => false, 'message' => 'Finished goods update prepare failed'], 500);
        }
        $subType = 'POS Roll';
        $updateStmt->bind_param('ssssdssi', $subType, $itemName, $size, $gsm, $quantity, $dateValue, $remarks, $stockId);
        if (!$updateStmt->execute()) {
            $err = (string)$updateStmt->error;
            $updateStmt->close();
            packing_api_respond(['ok' => false, 'message' => 'Finished goods update failed: ' . $err], 500);
        }
        $updateStmt->close();
        return;
    }

    $insertStmt = $db->prepare("INSERT INTO finished_goods_stock (category, sub_type, item_name, item_code, size, gsm, quantity, unit, location, batch_no, date, remarks, created_by) VALUES ('pos_paper_roll', ?, ?, ?, ?, ?, ?, 'PCS', 'Packing', ?, ?, ?, ?)");
    if (!$insertStmt) {
        packing_api_respond(['ok' => false, 'message' => 'Finished goods insert prepare failed'], 500);
    }
    $subType = 'POS Roll';
    $insertStmt->bind_param('sssssdsssi', $subType, $itemName, $packingId, $size, $gsm, $quantity, $jobNo, $dateValue, $remarks, $userId);
    if (!$insertStmt->execute()) {
        $err = (string)$insertStmt->error;
        $insertStmt->close();
        packing_api_respond(['ok' => false, 'message' => 'Finished goods insert failed: ' . $err], 500);
    }
    $insertStmt->close();
}

$db = getDB();
packing_ensure_operator_entries_table($db);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_REQUEST['action'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

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

        $stmt = $db->prepare("\n            UPDATE jobs\n            SET deleted_at = NOW(), updated_at = NOW()\n            WHERE id = ?\n              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n            LIMIT 1\n        ");
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

    if ($action === 'mark_packed' || $action === 'mark_packing_done') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can finalize packing status'], 403);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $statusCheck = $db->prepare("SELECT status, extra_data, job_no FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
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

        $currentStatus = strtolower(trim(str_replace(['-', '_'], ' ', packing_effective_status_from_row($statusRow))));
        if ($currentStatus === 'dispatched') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Dispatched']);
        }
        if (in_array($currentStatus, ['packed', 'packing done'], true)) {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Packed']);
        }

        $jobNo = trim((string)($statusRow['job_no'] ?? ''));
        if ($jobNo === '') {
            packing_api_respond(['ok' => false, 'message' => 'Job no is required for operator submission check'], 409);
        }

        $opEntry = packing_fetch_operator_entry($db, $jobNo);
        if (!$opEntry) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submission is required before marking Packed'], 409);
        }
        $isSubmitted = (int)($opEntry['submitted_lock'] ?? 0) === 1 || trim((string)($opEntry['submitted_at'] ?? '')) !== '';
        if (!$isSubmitted) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submission is required before marking Packed'], 409);
        }

        $stmt = $db->prepare("\n            UPDATE jobs\n            SET status = 'Packed', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()\n            WHERE id = ?\n              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n            LIMIT 1\n        ");
        if (!$stmt) {
            packing_api_respond(['ok' => false, 'message' => 'Update prepare failed'], 500);
        }
        $stmt->bind_param('i', $jobId);
        $okExec = $stmt->execute();
        if (!$okExec) {
            $err = (string)$stmt->error;
            $stmt->close();
            packing_api_respond(['ok' => false, 'message' => 'Update failed: ' . $err], 500);
        }
        $affected = (int)$stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            $recheck = $db->prepare("SELECT status FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            if ($recheck) {
                $recheck->bind_param('i', $jobId);
                $recheck->execute();
                $reRes = $recheck->get_result();
                $reRow = $reRes ? $reRes->fetch_assoc() : null;
                $recheck->close();
                $reStatus = strtolower(trim(str_replace(['-', '_'], ' ', (string)($reRow['status'] ?? ''))));
                if (in_array($reStatus, ['packed', 'packing done'], true)) {
                    packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Packed']);
                }
            }
            packing_api_respond(['ok' => false, 'message' => 'Status update did not apply.'], 409);
        }

        $extraSel = $db->prepare("SELECT extra_data FROM jobs WHERE id = ? LIMIT 1");
        if ($extraSel) {
            $extraSel->bind_param('i', $jobId);
            $extraSel->execute();
            $extraRow = $extraSel->get_result()->fetch_assoc();
            $extraSel->close();

            $extra = packing_decode_json($extraRow['extra_data'] ?? null);
            $extra['packing_done_flag'] = 1;
            $extra['packing_packed_flag'] = 1;
            $extra['packing_done_at'] = date('Y-m-d H:i:s');
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
            if ($extraJson !== false) {
                $extraUpd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? LIMIT 1");
                if ($extraUpd) {
                    $extraUpd->bind_param('si', $extraJson, $jobId);
                    $extraUpd->execute();
                    $extraUpd->close();
                }
            }
        }

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Packed']);
    }

    if ($action === 'mark_dispatched') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can dispatch packed jobs'], 403);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $statusCheck = $db->prepare("SELECT status, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
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

        $currentStatus = strtolower(trim(str_replace(['-', '_'], ' ', packing_effective_status_from_row($statusRow))));
        if ($currentStatus === 'dispatched') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Dispatched']);
        }
        if (!in_array($currentStatus, ['packed', 'packing done'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Job must be Packed before dispatch'], 409);
        }

        $stmt = $db->prepare("\n            UPDATE jobs\n            SET status = 'Dispatched', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()\n            WHERE id = ?\n              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n            LIMIT 1\n        ");
        if (!$stmt) {
            packing_api_respond(['ok' => false, 'message' => 'Update prepare failed'], 500);
        }
        $stmt->bind_param('i', $jobId);
        $okExec = $stmt->execute();
        if (!$okExec) {
            $err = (string)$stmt->error;
            $stmt->close();
            packing_api_respond(['ok' => false, 'message' => 'Update failed: ' . $err], 500);
        }
        $stmt->close();

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Dispatched']);
    }

    if ($action === 'mark_finished_production') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can finish production'], 403);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            packing_api_respond(['ok' => false, 'message' => 'Invalid job id'], 400);
        }

        $jobDetails = packing_fetch_job_details($db, $jobId);
        if (!$jobDetails) {
            packing_api_respond(['ok' => false, 'message' => 'Job not found'], 404);
        }

        $tabKey = packing_row_to_tab([
            'job_no' => (string)($jobDetails['job_no'] ?? ''),
            'department' => (string)($jobDetails['department'] ?? ''),
            'plan_department' => (string)($jobDetails['plan_extra_data']['department'] ?? ''),
            'job_type' => (string)($jobDetails['job_type'] ?? ''),
            'extra_data' => $jobDetails['job_extra_data'] ?? [],
            'plan_extra_data' => $jobDetails['plan_extra_data'] ?? [],
        ]);
        if ($tabKey !== 'pos_roll') {
            packing_api_respond(['ok' => false, 'message' => 'Finished Production is available only for POS Roll packing'], 409);
        }

        $currentStatus = strtolower(trim(str_replace(['-', '_'], ' ', packing_effective_status_from_row([
            'status' => (string)($jobDetails['status'] ?? ''),
            'extra_data' => $jobDetails['job_extra_data'] ?? [],
        ]))));
        if ($currentStatus === 'finished production') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Finished Production']);
        }
        if (!in_array($currentStatus, ['packed', 'packing done'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Job must be Packed before Finished Production'], 409);
        }

        $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
        $opEntry = $jobNo !== '' ? packing_fetch_operator_entry($db, $jobNo) : null;
        if (!$opEntry) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }
        $isSubmitted = (int)($opEntry['submitted_lock'] ?? 0) === 1 || trim((string)($opEntry['submitted_at'] ?? '')) !== '';
        if (!$isSubmitted) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }

        $db->begin_transaction();
        try {
            packing_api_upsert_finished_goods($db, $jobDetails, $opEntry, $userId);

            $stmt = $db->prepare("UPDATE jobs SET status = 'Finished Production', completed_at = COALESCE(completed_at, NOW()), updated_at = NOW() WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            if (!$stmt) {
                throw new RuntimeException('Finished Production update prepare failed');
            }
            $stmt->bind_param('i', $jobId);
            if (!$stmt->execute()) {
                $err = (string)$stmt->error;
                $stmt->close();
                throw new RuntimeException('Finished Production update failed: ' . $err);
            }
            $stmt->close();

            $extraSel = $db->prepare("SELECT extra_data FROM jobs WHERE id = ? LIMIT 1");
            if ($extraSel) {
                $extraSel->bind_param('i', $jobId);
                $extraSel->execute();
                $extraRow = $extraSel->get_result()->fetch_assoc();
                $extraSel->close();

                $extra = packing_decode_json($extraRow['extra_data'] ?? null);
                $extra['finished_production_flag'] = 1;
                $extra['finished_production_at'] = date('Y-m-d H:i:s');
                $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE);
                if ($extraJson !== false) {
                    $extraUpd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? LIMIT 1");
                    if ($extraUpd) {
                        $extraUpd->bind_param('si', $extraJson, $jobId);
                        $extraUpd->execute();
                        $extraUpd->close();
                    }
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Finished Production']);
    }

    if ($action === 'operator_submit') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobNo = trim((string)($_POST['job_no'] ?? ''));
        $planId = (int)($_POST['planning_id'] ?? 0);

        if ($jobNo === '') {
            packing_api_respond(['ok' => false, 'message' => 'job_no is required'], 400);
        }

        $packedQty = trim((string)($_POST['packed_qty'] ?? ''));
        $bundlesCount = trim((string)($_POST['bundles_count'] ?? ''));
        $cartonsCount = trim((string)($_POST['cartons_count'] ?? ''));
        $wastageQty = trim((string)($_POST['wastage_qty'] ?? ''));
        $looseQty = trim((string)($_POST['loose_qty'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $rollPayloadJsonRaw = trim((string)($_POST['roll_payload_json'] ?? ''));

        $packedQtyVal = is_numeric($packedQty) ? (float)$packedQty : null;
        $bundlesCountVal = is_numeric($bundlesCount) ? (int)$bundlesCount : null;
        $cartonsCountVal = is_numeric($cartonsCount) ? (int)$cartonsCount : null;
        $wastageQtyVal = is_numeric($wastageQty) ? (float)$wastageQty : null;
        $looseQtyVal = is_numeric($looseQty) ? (float)$looseQty : null;

        $rollPayloadJsonVal = null;
        if ($rollPayloadJsonRaw !== '') {
            $decodedRollPayload = json_decode($rollPayloadJsonRaw, true);
            if (!is_array($decodedRollPayload)) {
                packing_api_respond(['ok' => false, 'message' => 'Invalid roll payload JSON'], 400);
            }
            $rollPayloadJsonVal = json_encode($decodedRollPayload, JSON_UNESCAPED_UNICODE);
        }

        $operatorId = (int)($_SESSION['user_id'] ?? 0);
        $operatorName = trim((string)($_SESSION['user_name'] ?? ''));
        if ($operatorName === '') {
            $operatorName = 'Operator';
        }

        $canAdminOverrideEditLock = false;
        if (function_exists('isAdmin') && isAdmin()) {
            $canAdminOverrideEditLock = true;
        } elseif (function_exists('hasRole') && hasRole('system_admin', 'super_admin')) {
            $canAdminOverrideEditLock = true;
        }

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
            $maxSize = 5 * 1024 * 1024;
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
        $existing = packing_fetch_operator_entry($db, $jobNo);

        if ($existing) {
            $isLocked = (int)($existing['submitted_lock'] ?? 0) === 1 || trim((string)($existing['submitted_at'] ?? '')) !== '';
            if ($isLocked && !$canAdminOverrideEditLock) {
                packing_api_respond([
                    'ok' => false,
                    'message' => 'Entry already submitted. Only admin can edit or delete it.'
                ], 403);
            }

            $stmt = $db->prepare("\n                UPDATE packing_operator_entries\n                SET job_id=?, planning_id=?, operator_id=?, operator_name=?,\n                    packed_qty=?, bundles_count=?, cartons_count=?, wastage_qty=?, loose_qty=?,\n                    notes=?, roll_payload_json=COALESCE(?,roll_payload_json), photo_path=COALESCE(?,photo_path), submitted_at=?, submitted_lock=1, updated_at=NOW()\n                WHERE job_no=?\n            ");
            if (!$stmt) {
                packing_api_respond(['ok' => false, 'message' => 'Prepare failed'], 500);
            }
            $stmt->bind_param(
                'iiisdiiddsssss',
                $jobId,
                $planId,
                $operatorId,
                $operatorName,
                $packedQtyVal,
                $bundlesCountVal,
                $cartonsCountVal,
                $wastageQtyVal,
                $looseQtyVal,
                $notes,
                $rollPayloadJsonVal,
                $photoPath,
                $submittedAt,
                $jobNo
            );
        } else {
            $stmt = $db->prepare("\n                INSERT INTO packing_operator_entries\n                    (job_no, job_id, planning_id, operator_id, operator_name,\n                     packed_qty, bundles_count, cartons_count, wastage_qty, loose_qty,\n                     notes, roll_payload_json, photo_path, submitted_at, submitted_lock)\n                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)\n            ");
            if (!$stmt) {
                packing_api_respond(['ok' => false, 'message' => 'Prepare failed'], 500);
            }
            $submittedLock = 1;
            $stmt->bind_param(
                'siiisdiiddssssi',
                $jobNo,
                $jobId,
                $planId,
                $operatorId,
                $operatorName,
                $packedQtyVal,
                $bundlesCountVal,
                $cartonsCountVal,
                $wastageQtyVal,
                $looseQtyVal,
                $notes,
                $rollPayloadJsonVal,
                $photoPath,
                $submittedAt,
                $submittedLock
            );
        }

        $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if ($err !== '') {
            packing_api_respond(['ok' => false, 'message' => 'DB error: ' . $err], 500);
        }

        if ($jobId > 0) {
            $touchStmt = $db->prepare("\n                UPDATE jobs\n                SET updated_at = NOW()\n                WHERE id = ?\n                  AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n                LIMIT 1\n            ");
            if ($touchStmt) {
                $touchStmt->bind_param('i', $jobId);
                $touchStmt->execute();
                $touchStmt->close();
            }
        }

        $entry = packing_fetch_operator_entry($db, $jobNo);
        packing_api_respond(['ok' => true, 'message' => 'Packing entry submitted successfully', 'entry' => $entry]);
    }

    packing_api_respond(['ok' => false, 'message' => 'Invalid action'], 400);
} catch (Throwable $e) {
    packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
}
