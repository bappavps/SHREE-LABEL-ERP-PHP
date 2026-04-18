<?php
// ============================================================
// ERP System — Jobs Module: AJAX API
// Shared endpoint for job card operations (Jumbo, Printing, etc.)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

// JSON API safety: never emit HTML warnings/notices into response body.
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);
set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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

function jobs_collect_verified_rolls(): array {
    $verified = [];

    $verifiedRaw = trim((string)($_POST['verified_rolls_json'] ?? ''));
    if ($verifiedRaw !== '') {
        $decoded = json_decode($verifiedRaw, true);
        if (is_array($decoded)) {
            $verified = $decoded;
        } elseif ($verifiedRaw !== '[]') {
            $verified = [];
        }
    }

    if (empty($verified)) {
        $posted = $_POST['verified_rolls'] ?? $_POST['verified_rolls[]'] ?? [];
        if (is_array($posted)) {
            $verified = $posted;
        } elseif (is_string($posted) && trim($posted) !== '') {
            $verified = preg_split('/\s*,\s*/', trim($posted), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    if (empty($verified)) {
        $csv = trim((string)($_POST['verified_rolls_csv'] ?? ''));
        if ($csv !== '') {
            $verified = preg_split('/\s*,\s*/', $csv, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }

    $clean = [];
    foreach ($verified as $value) {
        $rollNo = strtoupper(trim((string)$value));
        if ($rollNo !== '') {
            $clean[$rollNo] = true;
        }
    }

    return array_keys($clean);
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

function jobs_float2($value): float {
    $n = (float)$value;
    return round($n, 2);
}

function jobs_calc_sqm(float $widthMm, float $lengthMtr): float {
    if ($widthMm <= 0 || $lengthMtr <= 0) return 0.0;
    return round(($widthMm / 1000) * $lengthMtr, 2);
}

function jobs_next_numeric_roll_no(mysqli $db, string $baseRollNo): string {
    $baseRollNo = trim($baseRollNo);
    if ($baseRollNo === '') {
        throw new Exception('Base roll number is required for numeric suffix generation');
    }
    for ($i = 1; $i <= 5000; $i++) {
        $candidate = $baseRollNo . '-' . $i;
        $chk = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ? LIMIT 1");
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        if (!$exists) return $candidate;
    }
    throw new Exception('Could not generate numeric suffix roll for base: ' . $baseRollNo);
}

function jobs_merge_roll_item(array $rows, array $item): array {
    $rollNo = trim((string)($item['roll_no'] ?? ''));
    if ($rollNo === '') return $rows;
    $out = [];
    $updated = false;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rn = trim((string)($row['roll_no'] ?? ''));
        if ($rn !== '' && strcasecmp($rn, $rollNo) === 0) {
            $out[] = array_merge($row, $item);
            $updated = true;
            continue;
        }
        $out[] = $row;
    }
    if (!$updated) {
        $out[] = $item;
    }
    return array_values($out);
}

function jobs_insert_inventory_log(mysqli $db, string $rollNo, int $paperStockId, string $description, int $performedBy): void {
    $stmt = $db->prepare("INSERT INTO inventory_logs (action_type, roll_no, paper_stock_id, description, performed_by) VALUES ('FLEXO_REQUEST', ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param('sisi', $rollNo, $paperStockId, $description, $performedBy);
    $stmt->execute();
}

function jobs_merge_child_rows_by_roll(array $baseRows, array $incomingRows): array {
    $map = [];
    foreach ($baseRows as $row) {
        if (!is_array($row)) continue;
        $rollNo = trim((string)($row['roll_no'] ?? ''));
        if ($rollNo === '') continue;
        $map[$rollNo] = $row;
    }
    foreach ($incomingRows as $row) {
        if (!is_array($row)) continue;
        $rollNo = trim((string)($row['roll_no'] ?? ''));
        if ($rollNo === '') continue;
        $map[$rollNo] = $row;
    }
    return array_values($map);
}

function jobs_create_or_get_flexo_extra_jumbo_card(mysqli $db, array $oldJumboJob, int $requestId, int $flexoJobId, string $actorName): array {
    $oldJumboId = (int)($oldJumboJob['id'] ?? 0);
    if ($oldJumboId <= 0) {
        throw new Exception('Old jumbo job not found for EXTRA flow');
    }

    $oldJumboNo = trim((string)($oldJumboJob['job_no'] ?? ''));
    if ($oldJumboNo === '') {
        throw new Exception('Old jumbo job number missing');
    }

    $find = $db->prepare("SELECT id, job_no, extra_data FROM jobs WHERE department = 'jumbo_slitting' AND previous_job_id = ? AND status IN ('Pending','Running') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
    $find->bind_param('i', $oldJumboId);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    if ($existing) {
        return [
            'id' => (int)$existing['id'],
            'job_no' => (string)($existing['job_no'] ?? ''),
            'extra_data' => json_decode((string)($existing['extra_data'] ?? '{}'), true) ?: [],
            'created' => false,
        ];
    }

    $baseNo = $oldJumboNo . '-EXTRA';
    $tryNo = $baseNo;
    $extraPayload = [
        'is_flexo_extra_temp_card' => true,
        'merge_target_job_id' => $oldJumboId,
        'merge_target_job_no' => $oldJumboNo,
        'flexo_job_id' => $flexoJobId,
        'flexo_request_id' => $requestId,
        'created_for' => 'flexo_additional_slitting',
        'child_rolls' => [],
        'stock_rolls' => [],
        'total_roll_count' => 0,
        'created_by_name' => $actorName,
        'created_at' => date('c'),
    ];
    $extraJson = json_encode($extraPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    for ($i = 0; $i < 30; $i++) {
        if ($i > 0) {
            $tryNo = $baseNo . '-' . ($i + 1);
        }
        $ins = $db->prepare("INSERT INTO jobs (job_no, planning_id, sales_order_id, roll_no, job_type, department, status, sequence_order, previous_job_id, notes, extra_data) VALUES (?, ?, NULL, ?, 'Slitting', 'jumbo_slitting', 'Pending', 1, ?, ?, ?)");
        $planningId = (int)($oldJumboJob['planning_id'] ?? 0);
        // Keep temporary EXTRA card isolated from old parent roll context.
        $rollNo = '';
        $notes = 'Temporary EXTRA jumbo card for flexo request #' . $requestId . ' | Merge target: ' . $oldJumboNo;
        $ins->bind_param('sisiss', $tryNo, $planningId, $rollNo, $oldJumboId, $notes, $extraJson);
        try {
            if ($ins->execute()) {
                return [
                    'id' => (int)$db->insert_id,
                    'job_no' => $tryNo,
                    'extra_data' => $extraPayload,
                    'created' => true,
                ];
            }
        } catch (Throwable $th) {
            if ((int)($ins->errno ?? 0) === 1062 || stripos((string)$th->getMessage(), 'Duplicate entry') !== false) {
                continue;
            }
            throw $th;
        }
    }

    throw new Exception('Unable to create temporary EXTRA jumbo card');
}

function jobs_apply_flexo_operator_request(mysqli $db, array $job, array $payload, int $actorId, string $actorName, array $options = []): array {
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId <= 0) return ['ok' => false, 'error' => 'Invalid flexo job'];

    $selectedRollNo = trim((string)($options['selected_roll_no'] ?? ''));
    $forceSlitting = !empty($options['force_slitting']);
    $requestId = (int)($options['request_id'] ?? 0);

    $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
    if (!is_array($extra)) $extra = [];
    $assignedRolls = is_array($extra['assigned_child_rolls'] ?? null) ? $extra['assigned_child_rolls'] : [];
    $jobName = jobs_display_job_name($job);

    $summary = [
        'issued_roll_no' => '',
        'remaining_stock_roll_no' => '',
        'remaining_stock_roll_id' => 0,
        'additional_slitting_task_created' => false,
        'slitting_task_index' => -1,
        'selected_roll_no' => $selectedRollNo,
        'apply_mode' => $forceSlitting ? 'slitting' : ($selectedRollNo !== '' ? 'selected_roll' : 'auto_stock'),
    ];

    $beforeState = [
        'assigned_child_rolls' => $assignedRolls,
        'flexo_extension_tasks' => is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [],
    ];

    $db->begin_transaction();
    try {
        $excess = is_array($payload['excess_roll_adjustment'] ?? null) ? $payload['excess_roll_adjustment'] : [];
        $excessEnabled = !empty($excess['enabled']);
        if ($excessEnabled) {
            $sourceRollNo = trim((string)($excess['source_roll_no'] ?? ''));
            $usedLength = jobs_float2($excess['used_length_mtr'] ?? 0);
            $remainingLength = jobs_float2($excess['remaining_length_mtr'] ?? 0);
            if ($sourceRollNo === '') throw new Exception('Source roll is required for excess adjustment');

            $srcStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1 FOR UPDATE");
            $srcStmt->bind_param('s', $sourceRollNo);
            $srcStmt->execute();
            $src = $srcStmt->get_result()->fetch_assoc();
            if (!$src) throw new Exception('Source roll not found in stock: ' . $sourceRollNo);

            $originalLength = jobs_float2($src['length_mtr'] ?? 0);
            if ($originalLength <= 0) {
                $originalLength = jobs_float2($usedLength + $remainingLength);
            }
            if ($usedLength <= 0 && $remainingLength > 0) {
                $usedLength = jobs_float2(max(0, $originalLength - $remainingLength));
            }
            if ($remainingLength <= 0 && $usedLength > 0 && $originalLength > ($usedLength + 0.01)) {
                // If operator provided only used length, infer remaining from source roll.
                $remainingLength = jobs_float2(max(0, $originalLength - $usedLength));
            }
            if ($usedLength <= 0) throw new Exception('Used length must be greater than 0');
            if ($remainingLength < 0) throw new Exception('Remaining length cannot be negative');
            if (($usedLength + $remainingLength) > ($originalLength + 0.01)) {
                throw new Exception('Used + remaining exceeds original roll length');
            }

            $srcWidth = jobs_float2($src['width_mm'] ?? 0);
            $srcSqmUsed = jobs_calc_sqm($srcWidth, $usedLength);
            $srcRemarks = trim((string)($src['remarks'] ?? ''));
            $adjNote = 'Flexo request approved by ' . $actorName . ' on ' . date('Y-m-d H:i');
            $srcRemarks = $srcRemarks === '' ? $adjNote : ($srcRemarks . ' | ' . $adjNote);
            $srcStatus = 'Job Assign';
            $srcJobNo = (string)($job['job_no'] ?? '');
            $srcJobName = $jobName;
            $updSrc = $db->prepare("UPDATE paper_stock SET length_mtr = ?, sqm = ?, remarks = ?, status = ?, job_no = ?, job_name = ?, date_used = CURDATE() WHERE roll_no = ?");
            $updSrc->bind_param('ddsssss', $usedLength, $srcSqmUsed, $srcRemarks, $srcStatus, $srcJobNo, $srcJobName, $sourceRollNo);
            $updSrc->execute();

            $assignedRolls = jobs_merge_roll_item($assignedRolls, [
                'roll_no' => $sourceRollNo,
                'parent_roll_no' => (string)($src['parent_roll_no'] ?? ''),
                'width_mm' => $srcWidth,
                'length_mtr' => $usedLength,
                'paper_type' => (string)($src['paper_type'] ?? ''),
                'company' => (string)($src['company'] ?? ''),
                'gsm' => jobs_float2($src['gsm'] ?? 0),
                'status' => 'Job Assign',
                'job_no' => (string)($job['job_no'] ?? ''),
                'job_name' => $jobName,
            ]);

            jobs_insert_inventory_log($db, $sourceRollNo, (int)($src['id'] ?? 0), 'Flexo request apply: adjusted used length to ' . $usedLength . ' mtr', $actorId);

            if ($remainingLength > 0.01) {
                $newStockRollNo = jobs_next_numeric_roll_no($db, $sourceRollNo);
                $summary['remaining_stock_roll_no'] = $newStockRollNo;
                $newSqm = jobs_calc_sqm($srcWidth, $remainingLength);
                $stockStatus = 'Stock';
                $empty = '';
                $insNew = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate, sqm, lot_batch_no, parent_roll_no, source_batch_id, source_allocation_id, status, job_no, job_name, job_size, date_received, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, CURDATE(), ?)");
                $insNew->bind_param(
                    'sssddddddssssssi',
                    $newStockRollNo,
                    $src['paper_type'],
                    $src['company'],
                    $srcWidth,
                    $remainingLength,
                    $src['gsm'],
                    $src['weight_kg'],
                    $src['purchase_rate'],
                    $newSqm,
                    $src['lot_batch_no'],
                    $sourceRollNo,
                    $stockStatus,
                    $empty,
                    $empty,
                    $empty,
                    $actorId
                );
                $insNew->execute();
                $newStockId = (int)$db->insert_id;
                $summary['remaining_stock_roll_id'] = $newStockId;
                jobs_insert_inventory_log($db, $newStockRollNo, $newStockId, 'Flexo request apply: created remaining stock roll ' . $remainingLength . ' mtr from ' . $sourceRollNo, $actorId);
            }
        }

        $additional = is_array($payload['additional_roll_request'] ?? null) ? $payload['additional_roll_request'] : [];
        $addEnabled = !empty($additional['enabled']);
        if ($addEnabled) {
            $reqWidth = jobs_float2($additional['width_mm'] ?? 0);
            $reqLength = jobs_float2($additional['length_mtr'] ?? 0);
            $reqReason = trim((string)($additional['reason'] ?? ''));
            if ($reqWidth <= 0 || $reqLength <= 0) {
                throw new Exception('Additional roll width and length must be greater than 0');
            }

            $candidate = null;
            if ($selectedRollNo !== '') {
                $pick = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1 FOR UPDATE");
                $pick->bind_param('s', $selectedRollNo);
                $pick->execute();
                $candidate = $pick->get_result()->fetch_assoc();
                if (!$candidate) {
                    throw new Exception('Selected roll not found in stock: ' . $selectedRollNo);
                }
                $candStatus = strtolower(trim((string)($candidate['status'] ?? '')));
                if (!in_array($candStatus, ['stock', 'main', 'available'], true)) {
                    throw new Exception('Selected roll is not available in stock: ' . $selectedRollNo);
                }
                $candWidth = jobs_float2($candidate['width_mm'] ?? 0);
                if (abs($candWidth - $reqWidth) > 0.01) {
                    throw new Exception('Selected roll width mismatch with request requirement');
                }
                $candLenChk = jobs_float2($candidate['length_mtr'] ?? 0);
                if ($candLenChk + 0.01 < $reqLength) {
                    throw new Exception('Selected roll length is less than required length');
                }
            } elseif (!$forceSlitting) {
                $find = $db->prepare("SELECT * FROM paper_stock WHERE ABS(width_mm - ?) <= 0.01 AND length_mtr >= ? AND LOWER(COALESCE(status,'')) IN ('stock','main','available') ORDER BY length_mtr ASC, id ASC LIMIT 1 FOR UPDATE");
                $find->bind_param('dd', $reqWidth, $reqLength);
                $find->execute();
                $candidate = $find->get_result()->fetch_assoc();
            }

            if ($candidate) {
                $candRollNo = (string)($candidate['roll_no'] ?? '');
                $candLen = jobs_float2($candidate['length_mtr'] ?? 0);
                $issuedRollNo = $candRollNo;
                $issuedLength = $reqLength;
                $issuedSqm = jobs_calc_sqm($reqWidth, $issuedLength);

                if ($candLen > ($reqLength + 0.01)) {
                    $issuedRollNo = jobs_next_numeric_roll_no($db, $candRollNo);
                    $remain = jobs_float2($candLen - $reqLength);
                    $remainSqm = jobs_calc_sqm($reqWidth, $remain);
                    $stockStatus = 'Stock';
                    $updCand = $db->prepare("UPDATE paper_stock SET length_mtr = ?, sqm = ?, status = ? WHERE id = ?");
                    $candId = (int)($candidate['id'] ?? 0);
                    $updCand->bind_param('ddsi', $remain, $remainSqm, $stockStatus, $candId);
                    $updCand->execute();

                    $jobStatus = 'Job Assign';
                    $jobNo = (string)($job['job_no'] ?? '');
                    $emptyText = '';
                    $insIssue = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate, sqm, lot_batch_no, parent_roll_no, source_batch_id, source_allocation_id, status, job_no, job_name, job_size, date_received, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, CURDATE(), ?)");
                    $insIssue->bind_param(
                        'sssddddddssssssi',
                        $issuedRollNo,
                        $candidate['paper_type'],
                        $candidate['company'],
                        $reqWidth,
                        $issuedLength,
                        $candidate['gsm'],
                        $candidate['weight_kg'],
                        $candidate['purchase_rate'],
                        $issuedSqm,
                        $candidate['lot_batch_no'],
                        $candRollNo,
                        $jobStatus,
                        $jobNo,
                        $jobName,
                        $emptyText,
                        $actorId
                    );
                    $insIssue->execute();
                    $issuedId = (int)$db->insert_id;
                    jobs_insert_inventory_log($db, $issuedRollNo, $issuedId, 'Flexo request apply: additional roll issued by splitting stock roll ' . $candRollNo, $actorId);
                } else {
                    $jobStatus = 'Job Assign';
                    $jobNo = (string)($job['job_no'] ?? '');
                    $updWhole = $db->prepare("UPDATE paper_stock SET status = ?, job_no = ?, job_name = ?, date_used = CURDATE() WHERE id = ?");
                    $candId = (int)($candidate['id'] ?? 0);
                    $updWhole->bind_param('sssi', $jobStatus, $jobNo, $jobName, $candId);
                    $updWhole->execute();
                    jobs_insert_inventory_log($db, $issuedRollNo, (int)($candidate['id'] ?? 0), 'Flexo request apply: additional roll issued directly from stock', $actorId);
                }

                $summary['issued_roll_no'] = $issuedRollNo;
                $assignedRolls = jobs_merge_roll_item($assignedRolls, [
                    'roll_no' => $issuedRollNo,
                    'parent_roll_no' => (string)($candidate['parent_roll_no'] ?? ''),
                    'width_mm' => $reqWidth,
                    'length_mtr' => $issuedLength,
                    'paper_type' => (string)($candidate['paper_type'] ?? ''),
                    'company' => (string)($candidate['company'] ?? ''),
                    'gsm' => jobs_float2($candidate['gsm'] ?? 0),
                    'status' => 'Job Assign',
                    'job_no' => (string)($job['job_no'] ?? ''),
                    'job_name' => $jobName,
                    'request_reason' => $reqReason,
                ]);
            } else {
                $tasks = is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [];
                $alreadyPending = false;
                $existingTaskIndex = -1;
                foreach ($tasks as $idx => $taskRow) {
                    if (!is_array($taskRow)) continue;
                    $tType = strtolower(trim((string)($taskRow['type'] ?? '')));
                    $tStatus = strtolower(trim((string)($taskRow['status'] ?? '')));
                    $tReqId = (int)($taskRow['request_id'] ?? 0);
                    $tWidth = jobs_float2($taskRow['width_mm'] ?? 0);
                    $tLength = jobs_float2($taskRow['length_mtr'] ?? 0);
                    if ($tType === 'additional_slitting' && $tStatus === 'pending' && ($requestId <= 0 || $tReqId === $requestId)
                        && abs($tWidth - $reqWidth) <= 0.01 && abs($tLength - $reqLength) <= 0.01) {
                        $alreadyPending = true;
                        $existingTaskIndex = (int)$idx;
                        break;
                    }
                }
                if (!$alreadyPending) {
                    $tasks[] = [
                        'type' => 'additional_slitting',
                        'tag' => 'Additional Slitting',
                        'status' => 'Pending',
                        'job_id' => $jobId,
                        'job_no' => (string)($job['job_no'] ?? ''),
                        'request_id' => $requestId,
                        'width_mm' => $reqWidth,
                        'length_mtr' => $reqLength,
                        'reason' => $reqReason,
                        'created_by' => $actorName,
                        'created_by_id' => $actorId,
                        'created_at' => date('c'),
                    ];
                    $summary['slitting_task_index'] = count($tasks) - 1;
                } else {
                    $summary['slitting_task_index'] = $existingTaskIndex;
                }
                $extra['flexo_extension_tasks'] = $tasks;
                $summary['additional_slitting_task_created'] = true;
            }
        }

        $extra['assigned_child_rolls'] = array_values($assignedRolls);
        $extra['flexo_request_last_applied_at'] = date('c');
        $extra['flexo_request_last_applied_by'] = $actorName;
        $extra['flexo_request_last_applied_by_id'] = $actorId;

        $afterState = [
            'assigned_child_rolls' => $extra['assigned_child_rolls'],
            'flexo_extension_tasks' => is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [],
        ];
        $extra['flexo_request_last_apply_summary'] = $summary;

        $jobStatusToSet = trim((string)($job['status'] ?? ''));
        if (!empty($summary['additional_slitting_task_created'])) {
            if ($jobStatusToSet === '' || strcasecmp($jobStatusToSet, 'Pending') !== 0) {
                $extra['flexo_status_before_waiting_slitting'] = $jobStatusToSet === '' ? 'Running' : $jobStatusToSet;
            }
            $extra['flexo_waiting_additional_slitting'] = true;
            $extra['flexo_waiting_additional_slitting_since'] = date('c');
            $extra['flexo_waiting_additional_slitting_reason'] = 'Additional roll not found in stock';
            $jobStatusToSet = 'Pending';
        }

        $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updJob = $db->prepare("UPDATE jobs SET status = ?, extra_data = ? WHERE id = ?");
        $updJob->bind_param('ssi', $jobStatusToSet, $extraJson, $jobId);
        $updJob->execute();

        $db->commit();
        return [
            'ok' => true,
            'summary' => $summary,
            'before_state' => $beforeState,
            'after_state' => $afterState,
        ];
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

function jobs_can_manual_roll_entry_for_job(array $job): bool {
    if (isAdmin() || hasRole('manager', 'system_admin', 'super_admin')) {
        return true;
    }

    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    $dept = strtolower(trim((string)($job['department'] ?? '')));

    $isJumbo = ($jobType === 'slitting' && $dept === 'jumbo_slitting')
        || in_array($jobType, ['jumbo', 'jumbo_slitting'], true)
        || $dept === 'jumbo_slitting';

    if ($isJumbo) {
        return hasPageAction('/modules/jobs/jumbo/index.php', 'edit')
            || hasPageAction('/modules/operators/jumbo/index.php', 'edit');
    }

    $isPrinting = in_array($jobType, ['printing', 'flexo'], true) || $dept === 'flexo_printing';
    if ($isPrinting) {
        return hasPageAction('/modules/jobs/printing/index.php', 'edit')
            || hasPageAction('/modules/operators/printing/index.php', 'edit');
    }

    if (jobs_is_die_cutting_job($job)) {
        return hasPageAction('/modules/jobs/flatbed/index.php', 'edit')
            || hasPageAction('/modules/operators/flatbed/index.php', 'edit');
    }

    if (jobs_is_barcode_job($job)) {
        return hasPageAction('/modules/jobs/barcode/index.php', 'edit')
            || hasPageAction('/modules/operators/barcode/index.php', 'edit');
    }

    if (jobs_is_label_slitting_job($job)) {
        return hasPageAction('/modules/jobs/label-slitting/index.php', 'edit')
            || hasPageAction('/modules/operators/label-slitting/index.php', 'edit');
    }

    return false;
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

function jobs_array_merge_deep(array $base, array $patch): array {
    foreach ($patch as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
            $base[$k] = jobs_array_merge_deep($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function jobs_decode_extra_data($raw): array {
    if (is_array($raw)) return $raw;
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jobs_parse_stock_qty_value($raw): int {
    $txt = trim((string)$raw);
    if ($txt === '') return 0;
    if (preg_match('/-?\d+(?:\.\d+)?/', $txt, $m)) {
        return max(0, (int)floor((float)$m[0]));
    }
    return 0;
}

function jobs_build_flexo_slitting_redirect_url(array $job, int $requestId, int $taskIndex = -1, float $targetWidth = 0.0, float $targetLength = 0.0): string {
    $params = [
        'from' => 'flexo_request_accept',
        'job_id' => (int)($job['id'] ?? 0),
        'request_id' => $requestId,
    ];
    $planningId = (int)($job['planning_id'] ?? 0);
    if ($planningId > 0) {
        $params['planning_id'] = $planningId;
    }
    $planNo = trim((string)($job['plan_no'] ?? ''));
    if ($planNo !== '') {
        $params['plan_no'] = $planNo;
    }
    if ($taskIndex >= 0) {
        $params['task_index'] = $taskIndex;
    }
    if ($targetWidth > 0.01) {
        $params['target_width'] = jobs_float2($targetWidth);
    }
    if ($targetLength > 0.01) {
        $params['target_length'] = jobs_float2($targetLength);
    }
    $query = http_build_query($params);
    return BASE_URL . '/modules/inventory/slitting/index.php' . ($query !== '' ? ('?' . $query) : '');
}

function jobs_table_exists(mysqli $db, string $table): bool {
    $table = trim($table);
    if ($table === '') return false;
    $safe = $db->real_escape_string($table);
    $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
    if (!($res instanceof mysqli_result)) return false;
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
}

function jobs_validate_printing_anilox_usage(mysqli $db, array $extra): array {
    $selected = [];
    if (isset($extra['color_anilox_rows']) && is_array($extra['color_anilox_rows'])) {
        foreach ($extra['color_anilox_rows'] as $row) {
            if (!is_array($row)) continue;
            $value = trim((string)($row['anilox_value'] ?? ''));
            if ($value === '' || strcasecmp($value, 'none') === 0) continue;
            $selected[] = $value;
        }
    } elseif (isset($extra['anilox_lanes']) && is_array($extra['anilox_lanes'])) {
        foreach ($extra['anilox_lanes'] as $value) {
            $v = trim((string)$value);
            if ($v === '' || strcasecmp($v, 'none') === 0) continue;
            $selected[] = $v;
        }
    }

    if (empty($selected)) return ['ok' => true];

    $stockMap = [];
    foreach (['master_anilox_data', 'anilox_data'] as $table) {
        if (!jobs_table_exists($db, $table)) continue;
        $res = @$db->query("SELECT anilox_lpi, stock_qty FROM {$table}");
        if (!($res instanceof mysqli_result)) continue;
        while ($row = $res->fetch_assoc()) {
            $lpi = trim((string)($row['anilox_lpi'] ?? ''));
            if ($lpi === '') continue;
            if (!isset($stockMap[$lpi])) $stockMap[$lpi] = 0;
            $stockMap[$lpi] += jobs_parse_stock_qty_value($row['stock_qty'] ?? 0);
        }
        $res->close();
    }

    if (empty($stockMap)) {
        return ['ok' => false, 'error' => 'Anilox stock is not configured in Anilox Management.'];
    }

    $usage = [];
    foreach ($selected as $val) {
        $usage[$val] = ($usage[$val] ?? 0) + 1;
    }

    $missing = [];
    $exceeded = [];
    foreach ($usage as $lpi => $count) {
        $available = (int)($stockMap[$lpi] ?? 0);
        if ($available <= 0) {
            $missing[] = $lpi;
            continue;
        }
        if ($count > $available) {
            $exceeded[] = $lpi . ': selected ' . $count . ', available ' . $available;
        }
    }

    if (!empty($missing) || !empty($exceeded)) {
        $parts = [];
        if (!empty($missing)) $parts[] = 'Not available: ' . implode(', ', $missing);
        if (!empty($exceeded)) $parts[] = 'Quantity mismatch: ' . implode(' | ', $exceeded);
        return ['ok' => false, 'error' => 'Anilox stock validation failed. ' . implode('. ', $parts) . '.'];
    }

    return ['ok' => true];
}

function jobs_timer_now(): string {
    return date('Y-m-d H:i:s');
}

function jobs_timer_seconds_between(string $from, string $to): int {
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if (!$fromTs || !$toTs || $toTs <= $fromTs) return 0;
    return (int)($toTs - $fromTs);
}

function jobs_timer_accumulated_seconds(array $extra): int {
    return max(0, (int)round((float)($extra['timer_accumulated_seconds'] ?? 0)));
}

function jobs_timer_array(array $extra, string $key): array {
    $rows = $extra[$key] ?? [];
    if (!is_array($rows)) return [];
    return array_values(array_filter($rows, 'is_array'));
}

function jobs_timer_push_event(array $extra, string $type, string $at): array {
    if ($type === '' || $at === '') return $extra;
    $events = jobs_timer_array($extra, 'timer_events');
    $last = !empty($events) ? end($events) : null;
    if (!is_array($last) || (string)($last['type'] ?? '') !== $type || (string)($last['at'] ?? '') !== $at) {
        $events[] = ['type' => $type, 'at' => $at];
    }
    $extra['timer_events'] = array_values($events);
    return $extra;
}

function jobs_timer_push_segment(array $extra, string $key, string $from, string $to): array {
    $seconds = jobs_timer_seconds_between($from, $to);
    if ($seconds <= 0) return $extra;
    $segments = jobs_timer_array($extra, $key);
    $segments[] = ['from' => $from, 'to' => $to, 'seconds' => $seconds];
    $extra[$key] = array_values($segments);
    return $extra;
}

function jobs_timer_close_work_segment(array $extra, string $to): array {
    $from = trim((string)($extra['timer_last_resumed_at'] ?? ''));
    if ($from === '') return $extra;
    return jobs_timer_push_segment($extra, 'timer_work_segments', $from, $to);
}

function jobs_timer_close_pause_segment(array $extra, string $to): array {
    $from = trim((string)($extra['timer_pause_started_at'] ?? ($extra['timer_paused_at'] ?? '')));
    if ($from === '') return $extra;
    return jobs_timer_push_segment($extra, 'timer_pause_segments', $from, $to);
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

function jobs_normalize_stock_status(string $statusRaw, string $bucket): string {
    $s = strtolower(trim($statusRaw));
    if (strpos($s, 'stock') !== false) return 'Stock';
    if (strpos($s, 'prod') !== false) return 'In Production';
    if (strpos($s, 'slit') !== false) return 'Slitting';
    if (strpos($s, 'consum') !== false) return 'Consumed';
    if (strpos($s, 'job') !== false) return 'Job Assign';
    return $bucket === 'stock' ? 'Stock' : 'Job Assign';
}

function jobs_safe_parent_restore_status(string $statusRaw): string {
    $s = trim($statusRaw);
    if ($s === '') return 'Main';
    $allowed = ['Main', 'Stock', 'Job Assign', 'In Production', 'Consumed', 'Slitting', 'Slitted'];
    foreach ($allowed as $ok) {
        if (strcasecmp($ok, $s) === 0) return $ok;
    }
    return 'Main';
}

function jobs_join_base_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;
    $base = rtrim((string)BASE_URL, '/');
    $rel = ltrim(str_replace('\\', '/', $path), '/');
    return $base . '/' . $rel;
}

function jobs_notification_department_rank(string $department): int {
    $d = strtolower(trim($department));
    if ($d === 'jumbo_slitting') return 1;
    if ($d === 'flexo_printing') return 2;
    if ($d === 'flatbed') return 3;
    if ($d === 'barcode') return 4;
    if ($d === 'label_slitting') return 5;
    if ($d === 'packing') return 6;
    if ($d === 'paperroll') return 7;
    if ($d === 'pos') return 8;
    if ($d === 'oneply') return 9;
    if ($d === 'twoply') return 10;
    return 99;
}

function jobs_notification_job_page_by_department(string $department): string {
    $d = strtolower(trim($department));
    if ($d === 'label-slitting' || $d === 'label slitting') $d = 'label_slitting';
    if ($d === 'rotary') $d = 'rotery';
    if ($d === 'one ply' || $d === 'one_ply') $d = 'oneply';
    if ($d === 'two ply' || $d === 'two_ply') $d = 'twoply';
    if ($d === 'jumbo_slitting') return '/modules/jobs/jumbo/index.php';
    if ($d === 'flexo_printing') return '/modules/jobs/printing/index.php';
    if ($d === 'flatbed') return '/modules/jobs/flatbed/index.php';
    if ($d === 'rotery') return '/modules/jobs/rotery/index.php';
    if ($d === 'barcode') return '/modules/jobs/barcode/index.php';
    if ($d === 'label_slitting') return '/modules/jobs/label-slitting/index.php';
    if ($d === 'pos') return '/modules/jobs/pos/index.php';
    if ($d === 'oneply') return '/modules/jobs/oneply/index.php';
    if ($d === 'twoply') return '/modules/jobs/twoply/index.php';
    if ($d === 'paperroll') return '/modules/planning/paperroll/index.php';
    if ($d === 'packing') return '/modules/jobs/packing/index.php';
    return '';
}

function jobs_notification_primary_chain_job_id(mysqli $db, int $jobId): int {
    if ($jobId <= 0) return 0;

    $currentId = $jobId;
    $bestId = $jobId;
    $bestRank = 99;
    $visited = [];

    for ($depth = 0; $depth < 16 && $currentId > 0; $depth++) {
        if (isset($visited[$currentId])) break;
        $visited[$currentId] = true;

        $stmt = $db->prepare("SELECT id, previous_job_id, department FROM jobs WHERE id = ? LIMIT 1");
        if (!$stmt) break;
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) break;

        $rank = jobs_notification_department_rank((string)($row['department'] ?? ''));
        if ($rank < $bestRank) {
            $bestRank = $rank;
            $bestId = (int)($row['id'] ?? $currentId);
        }

        $nextId = (int)($row['previous_job_id'] ?? 0);
        if ($nextId <= 0) break;
        $currentId = $nextId;
    }

    return $bestId > 0 ? $bestId : $jobId;
}

function jobs_notification_target_url(mysqli $db, array $notification): string {
    $department = strtolower(trim((string)($notification['department'] ?? '')));
    $message = strtolower(trim((string)($notification['message'] ?? '')));
    $notificationJobId = (int)($notification['job_id'] ?? 0);
    $routePath = trim((string)($notification['route_path'] ?? ''));

    if ($routePath !== '') {
        return jobs_join_base_url($routePath);
    }

    // PaperRoll pipeline notifications should stay on their own stage page.
    if ($notificationJobId > 0 && in_array($department, ['paperroll', 'pos', 'oneply', 'twoply'], true)) {
        $directPage = jobs_notification_job_page_by_department($department);
        if ($directPage !== '') {
            return jobs_join_base_url($directPage) . '?auto_job=' . rawurlencode((string)$notificationJobId);
        }
    }

    if ($department === 'requisition_admin' || preg_match('/^requisition_user_\d+$/', $department)) {
        return jobs_join_base_url('/modules/requisition-management/index.php');
    }

    if ($department === 'leave_admin' || preg_match('/^leave_user_\d+$/', $department)) {
        return jobs_join_base_url('/modules/leave-management/index.php');
    }

    if ($department === 'planning') {
        if (strpos($message, 'paperroll planning') !== false || strpos($message, 'paper roll planning') !== false || strpos($message, 'paper-roll planning') !== false) {
            return jobs_join_base_url('/modules/planning/paperroll/index.php');
        }
        if (strpos($message, 'barcode planning') !== false || strpos($message, 'rotery planning') !== false || strpos($message, 'rotary planning') !== false) {
            return jobs_join_base_url('/modules/planning/barcode/index.php');
        }
        if (strpos($message, 'label slitting planning') !== false) {
            return jobs_join_base_url('/modules/planning/label-slitting/index.php');
        }
        if (strpos($message, 'label printing planning') !== false) {
            return jobs_join_base_url('/modules/planning/label/index.php');
        }
        if (strpos($message, 'jumbo slitting planning') !== false || strpos($message, 'slitting planning') !== false) {
            return jobs_join_base_url('/modules/planning/slitting/index.php');
        }
        if (strpos($message, 'die-cutting planning') !== false || strpos($message, 'flatbed planning') !== false) {
            return jobs_join_base_url('/modules/planning/flatbed/index.php');
        }
        if (strpos($message, 'batch printing planning') !== false || strpos($message, 'batch planning') !== false) {
            return jobs_join_base_url('/modules/planning/batch/index.php');
        }
        if (strpos($message, 'packaging planning') !== false || strpos($message, 'packing planning') !== false) {
            return jobs_join_base_url('/modules/planning/packing/index.php');
        }
        if (strpos($message, 'dispatch planning') !== false) {
            return jobs_join_base_url('/modules/planning/dispatch/index.php');
        }
        if (strpos($message, 'printing planning') !== false) {
            return jobs_join_base_url('/modules/planning/printing/index.php');
        }
        return jobs_join_base_url('/modules/planning/index.php');
    }

    if ($notificationJobId > 0) {
        $targetJobId = jobs_notification_primary_chain_job_id($db, $notificationJobId);
        $jobStmt = $db->prepare("SELECT department FROM jobs WHERE id = ? LIMIT 1");
        if ($jobStmt) {
            $jobStmt->bind_param('i', $targetJobId);
            $jobStmt->execute();
            $jobRow = $jobStmt->get_result()->fetch_assoc() ?: [];
            $jobStmt->close();

            $jobDepartment = strtolower(trim((string)($jobRow['department'] ?? $department)));
            $jobPage = jobs_notification_job_page_by_department($jobDepartment);
            if ($jobPage !== '') {
                return jobs_join_base_url($jobPage) . '?auto_job=' . rawurlencode((string)$targetJobId);
            }
        }
    }

    $fallbackPage = jobs_notification_job_page_by_department($department);
    if ($fallbackPage !== '') {
        return jobs_join_base_url($fallbackPage);
    }

    if ($department === 'dispatch') return jobs_join_base_url('/modules/dispatch/index.php');

    return jobs_join_base_url('/modules/approval/index.php');
}

function jobs_is_die_cutting_job(array $job): bool {
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    if (in_array($department, ['flatbed', 'die-cutting', 'die_cutting'], true)) {
        return true;
    }
    if (in_array($jobType, ['die-cutting', 'diecutting'], true)) {
        return true;
    }
    return $jobType === 'finishing' && in_array($department, ['flatbed', 'die-cutting', 'die_cutting'], true);
}

function jobs_is_operator_media_upload_job(array $job): bool {
    if (jobs_is_die_cutting_job($job)) {
        return true;
    }

    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));

    if (in_array($department, ['barcode'], true)) {
        return true;
    }
    if (in_array($jobType, ['barcode'], true)) {
        return true;
    }

    if (in_array($department, ['label-slitting', 'label_slitting', 'label slitting', 'slitting'], true)) {
        return true;
    }
    if (in_array($jobType, ['label-slitting', 'label_slitting', 'label slitting', 'slitting'], true)) {
        return true;
    }

    if (in_array($department, ['pos'], true)) {
        return true;
    }
    if (in_array($jobType, ['pos'], true)) {
        return true;
    }

    return $jobType === 'finishing' && in_array($department, ['barcode', 'label-slitting', 'label_slitting', 'label slitting', 'slitting', 'pos'], true);
}

function jobs_is_barcode_job(array $job): bool {
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    if ($department === 'barcode') return true;
    if ($jobType === 'barcode') return true;
    return $jobType === 'finishing' && $department === 'barcode';
}

function jobs_is_label_slitting_job(array $job): bool {
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    if (in_array($department, ['label-slitting', 'label_slitting', 'label slitting'], true)) return true;
    if (in_array($jobType, ['label-slitting', 'label_slitting', 'label slitting'], true)) return true;
    return $jobType === 'finishing' && in_array($department, ['label-slitting', 'label_slitting', 'label slitting'], true);
}

function jobs_is_packing_job(array $job): bool {
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    if ($department === 'packing') return true;
    if ($jobType === 'packing') return true;
    return $jobType === 'finishing' && $department === 'packing';
}

function jobs_is_pos_job(array $job): bool {
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    if ($department === 'pos') return true;
    if ($jobType === 'pos') return true;
    return $jobType === 'finishing' && $department === 'pos';
}

function jobs_stage_status_for_event(array $job, string $event): string {
    $evt = strtolower(trim($event));

    if (($job['job_type'] ?? '') === 'Slitting') {
        if ($evt === 'running') return 'Slitting';
        if ($evt === 'pause') return 'Slitting Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Slitted';
        if ($evt === 'reset') return 'Preparing Slitting';
        return '';
    }

    $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
    $department = strtolower(trim((string)($job['department'] ?? '')));
    $isPrinting = in_array($jobType, ['printing', 'flexo'], true) || $department === 'flexo_printing';

    if ($isPrinting) {
        if ($evt === 'running') return 'Printing';
        if ($evt === 'pause') return 'Printing Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Printed';
        return '';
    }

    if (jobs_is_die_cutting_job($job)) {
        if ($evt === 'running') return 'Die Cutting';
        if ($evt === 'pause') return 'Die Cutting Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Die Cut';
        return '';
    }

    if (jobs_is_barcode_job($job)) {
        if ($evt === 'running') return 'Barcode';
        if ($evt === 'pause') return 'Barcode Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Barcoded';
        return '';
    }

    if (jobs_is_label_slitting_job($job)) {
        if ($evt === 'running') return 'Label Slitting';
        if ($evt === 'pause') return 'Label Slitting Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Label Slitted';
        return '';
    }

    if (jobs_is_packing_job($job)) {
        if ($evt === 'running') return 'Packing';
        if ($evt === 'pause') return 'Packing Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Packed';
        return '';
    }

    if (jobs_is_pos_job($job)) {
        if ($evt === 'running') return 'Barcode';
        if ($evt === 'pause') return 'Barcode Pause';
        if ($evt === 'end' || $evt === 'complete') return 'Barcoded';
        return '';
    }

    return '';
}

function jobs_is_flatbed_like_die(string $dieValue): bool {
    $die = strtolower(trim($dieValue));
    if ($die === '') return false;
    return strpos($die, 'flatbed') !== false && strpos($die, 'rotary') === false;
}

function jobs_derive_stage_job_no(string $planNo, string $targetPrefix): string {
    $planNo = trim($planNo);
    $targetPrefix = strtoupper(trim($targetPrefix));
    if ($planNo === '' || $targetPrefix === '') return '';

    if (preg_match('/^[A-Za-z]+([\/-].+)$/', $planNo, $m)) {
        return $targetPrefix . $m[1];
    }

    return $targetPrefix . '/' . $planNo;
}

function jobs_generate_unique_stage_job_no(mysqli $db, string $moduleType, string $planNo, string $targetPrefix, int $maxAttempts = 30): string {
    $derived = jobs_derive_stage_job_no($planNo, $targetPrefix);

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $candidate = '';
        if (function_exists('getNextId')) {
            $candidate = trim((string)(getNextId($moduleType) ?? ''));
        }
        if ($candidate === '') {
            if ($derived !== '') {
                $candidate = $attempt === 0 ? $derived : ($derived . '-' . ($attempt + 1));
            } else {
                $candidate = strtoupper($targetPrefix) . '/' . date('Y') . '/' . str_pad((string)((int)date('His') + $attempt), 6, '0', STR_PAD_LEFT);
            }
        }

        $chk = $db->prepare("SELECT COUNT(*) AS c FROM jobs WHERE job_no = ?");
        if (!$chk) continue;
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $exists = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        if (!$exists) {
            return $candidate;
        }
    }

    return '';
}

function jobs_ensure_label_slitting_job(
    mysqli $db,
    int $planningId,
    string $planNo,
    string $rollNo,
    int $previousJobId,
    string $previousJobNo,
    string $sourceLabel,
    string $displayJobName = '',
    int $sequenceOrder = 4
): int {
    if ($planningId <= 0 || $previousJobId <= 0) return 0;

    $jumboRef = 'N/A';
    $flexoRef = 'N/A';
    $dieCutRef = 'N/A';
    $labelRef = 'N/A';

    $chainStmt = $db->prepare("SELECT job_no, department FROM jobs WHERE planning_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
    if ($chainStmt) {
        $chainStmt->bind_param('i', $planningId);
        $chainStmt->execute();
        $chainRows = $chainStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($chainRows as $chainRow) {
            $chainDept = strtolower(trim((string)($chainRow['department'] ?? '')));
            $chainJobNo = trim((string)($chainRow['job_no'] ?? ''));
            if ($chainJobNo === '') continue;
            if ($chainDept === 'jumbo_slitting' && $jumboRef === 'N/A') $jumboRef = $chainJobNo;
            if ($chainDept === 'flexo_printing' && $flexoRef === 'N/A') $flexoRef = $chainJobNo;
            if ($chainDept === 'flatbed' && $dieCutRef === 'N/A') $dieCutRef = $chainJobNo;
            if (in_array($chainDept, ['label-slitting', 'label_slitting', 'label slitting'], true) && $labelRef === 'N/A') $labelRef = $chainJobNo;
        }
    }

    if ($previousJobNo !== '') {
        $prevUpper = strtoupper($previousJobNo);
        if (strpos($prevUpper, 'DCT/') === 0) $dieCutRef = $previousJobNo;
        if (strpos($prevUpper, 'FLX/') === 0) $flexoRef = $previousJobNo;
        if (strpos($prevUpper, 'JMB/') === 0) $jumboRef = $previousJobNo;
    }

    $buildLabelNotes = static function(string $labelJobNo) use ($planNo, $jumboRef, $flexoRef, $dieCutRef, $displayJobName): string {
        $notesText = 'Label slitting released from upstream'
            . ' | Plan: ' . ($planNo !== '' ? $planNo : 'N/A')
            . ' | Jumbo: ' . $jumboRef
            . ' | Flexo: ' . $flexoRef
            . ' | Die-Cut: ' . $dieCutRef
            . ' | Label: ' . ($labelJobNo !== '' ? $labelJobNo : 'N/A');
        if ($displayJobName !== '') {
            $notesText .= ' | Job name: ' . $displayJobName;
        }
        return $notesText;
    };

    $existingStmt = $db->prepare("SELECT id, job_no, status FROM jobs WHERE planning_id = ? AND LOWER(COALESCE(department, '')) IN ('label-slitting', 'label_slitting', 'label slitting') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
    if ($existingStmt) {
        $existingStmt->bind_param('i', $planningId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        if ($existing) {
            $existingId = (int)($existing['id'] ?? 0);
            $labelRef = trim((string)($existing['job_no'] ?? '')) ?: $labelRef;
            $notes = $buildLabelNotes($labelRef !== 'N/A' ? $labelRef : '');
            $currentStatus = trim((string)($existing['status'] ?? ''));
            $nextStatus = in_array($currentStatus, ['Queued', 'Pending'], true) ? 'Pending' : $currentStatus;
            $updExisting = $db->prepare("UPDATE jobs SET previous_job_id = ?, sequence_order = ?, notes = ?, status = ? WHERE id = ?");
            if ($updExisting) {
                $updExisting->bind_param('iissi', $previousJobId, $sequenceOrder, $notes, $nextStatus, $existingId);
                $updExisting->execute();
            }

            if ($existingId > 0 && $currentStatus === 'Queued' && $nextStatus === 'Pending') {
                $nMsg = ((string)($existing['job_no'] ?? 'Label Slitting')) . ' is now ready | From: ' . ($previousJobNo !== '' ? $previousJobNo : 'N/A');
                $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, 'label-slitting', ?, 'success')");
                if ($nIns) {
                    $nIns->bind_param('is', $existingId, $nMsg);
                    $nIns->execute();
                }
            }
            return $existingId;
        }
    }

    $jobNo = jobs_generate_unique_stage_job_no($db, 'label_slitting_job', $planNo, 'LST');
    if ($jobNo === '') {
        throw new RuntimeException('Unable to generate unique Label Slitting job number.');
    }

    $notes = $buildLabelNotes($jobNo);

    $insert = $db->prepare("INSERT INTO jobs (job_no, planning_id, sales_order_id, roll_no, job_type, department, status, sequence_order, previous_job_id, notes) VALUES (?, ?, NULL, ?, 'Finishing', 'label-slitting', 'Pending', ?, ?, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to prepare Label Slitting job creation.');
    }
    $insert->bind_param('sisiis', $jobNo, $planningId, $rollNo, $sequenceOrder, $previousJobId, $notes);
    $insert->execute();
    $labelJobId = (int)$db->insert_id;

    if ($labelJobId > 0) {
        $nMsg = 'New Label Slitting job card ready: ' . $jobNo . ' | From: ' . ($previousJobNo !== '' ? $previousJobNo : 'N/A');
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, 'label-slitting', ?, 'success')");
        if ($nIns) {
            $nIns->bind_param('is', $labelJobId, $nMsg);
            $nIns->execute();
        }
    }

    return $labelJobId;
}

function jobs_first_non_empty(array $arr, array $keys, string $fallback = ''): string {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $arr)) continue;
        $v = trim((string)$arr[$k]);
        if ($v !== '') return $v;
    }
    return $fallback;
}

function jobs_pick_planning_image(array $planningExtra): string {
    $candidates = [
        'print_image_url',
        'planning_image_url',
        'physical_image_url',
        'upload_image_url',
        'image_url',
        'print_image_path',
        'planning_image_path',
        'physical_image_path',
        'upload_image_path',
        'image_path',
    ];
    return jobs_first_non_empty($planningExtra, $candidates, '');
}

function jobs_set_planning_stage_status(mysqli $db, int $planId, string $stageStatus): void {
    if ($planId <= 0) {
        return;
    }

    $sel = $db->prepare("SELECT extra_data FROM planning WHERE id = ? LIMIT 1");
    if (!$sel) {
        return;
    }
    $sel->bind_param('i', $planId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
    if (!is_array($extra)) {
        $extra = [];
    }
    $extra['printing_planning'] = $stageStatus;
    $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $upd = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
    if ($upd) {
        $upd->bind_param('si', $extraJson, $planId);
        $upd->execute();
    }
}

function jobs_set_planning_status_and_stage(mysqli $db, int $planId, string $stageStatus): void {
    if ($planId <= 0 || trim($stageStatus) === '') return;

    $updStatus = $db->prepare("UPDATE planning SET status = ? WHERE id = ?");
    if ($updStatus) {
        $updStatus->bind_param('si', $stageStatus, $planId);
        $updStatus->execute();
    }

    jobs_set_planning_stage_status($db, $planId, $stageStatus);
}

function jobs_set_planning_status_and_stage_for_job(mysqli $db, array $job, string $stageStatus): void {
    $stageStatus = trim($stageStatus);
    if ($stageStatus === '') return;

    $planId = (int)($job['planning_id'] ?? 0);
    if ($planId > 0) {
        jobs_set_planning_status_and_stage($db, $planId, $stageStatus);
        return;
    }

    $extra = jobs_decode_extra_data((string)($job['extra_data'] ?? '{}'));
    $planNo = trim((string)($extra['plan_no'] ?? ''));
    if ($planNo === '') {
        $notes = trim((string)($job['notes'] ?? ''));
        if ($notes !== '' && preg_match('/\|\s*Plan:\s*([^|\n]+)/i', $notes, $m)) {
            $planNo = trim((string)($m[1] ?? ''));
        }
    }

    if ($planId <= 0 && $planNo === '') {
        $prevId = (int)($job['previous_job_id'] ?? 0);
        $guard = 0;
        while ($prevId > 0 && $guard < 8) {
            $guard++;
            $prevStmt = $db->prepare("SELECT id, planning_id, previous_job_id, extra_data, notes FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            if (!$prevStmt) {
                break;
            }
            $prevStmt->bind_param('i', $prevId);
            $prevStmt->execute();
            $prev = $prevStmt->get_result()->fetch_assoc();
            if (!$prev) {
                break;
            }

            $prevPlanId = (int)($prev['planning_id'] ?? 0);
            if ($prevPlanId > 0) {
                $planId = $prevPlanId;
                break;
            }

            $prevExtra = jobs_decode_extra_data((string)($prev['extra_data'] ?? '{}'));
            $prevPlanNo = trim((string)($prevExtra['plan_no'] ?? ''));
            if ($prevPlanNo === '') {
                $prevNotes = trim((string)($prev['notes'] ?? ''));
                if ($prevNotes !== '' && preg_match('/\|\s*Plan:\s*([^|\n]+)/i', $prevNotes, $mPrev)) {
                    $prevPlanNo = trim((string)($mPrev[1] ?? ''));
                }
            }
            if ($prevPlanNo !== '') {
                $planNo = $prevPlanNo;
                break;
            }

            $prevId = (int)($prev['previous_job_id'] ?? 0);
        }
    }

    if ($planId > 0) {
        jobs_set_planning_status_and_stage($db, $planId, $stageStatus);
        return;
    }

    if ($planNo === '') return;

    $find = $db->prepare("SELECT id FROM planning WHERE job_no = ? LIMIT 1");
    if (!$find) return;
    $find->bind_param('s', $planNo);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $resolvedPlanId = (int)($row['id'] ?? 0);
    if ($resolvedPlanId > 0) {
        jobs_set_planning_status_and_stage($db, $resolvedPlanId, $stageStatus);
    }
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

        $currentStatusNorm = strtolower(trim(str_replace(['-', '_'], ' ', (string)($job['status'] ?? ''))));
        // Global safeguard: once packing marks a job done, do not let generic jobs API downgrade it.
        if ($currentStatusNorm === 'packing done') {
            echo json_encode(['ok' => false, 'error' => 'This job is already Packing Done and is locked for status changes from this module.']);
            break;
        }

        $jobExtra = jobs_decode_extra_data($job['extra_data'] ?? '{}');
        $isFinishStatus = in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true);
        $timerIsActive = !empty($jobExtra['timer_active']);

        if ($isFinishStatus && $timerIsActive) {
            echo json_encode(['ok' => false, 'error' => 'Timer is still running. Please End the timer before finishing this job.']);
            break;
        }

        $db->begin_transaction();

        // Update job status + timestamps
        if ($newStatus === 'Running') {
            $verifiedMode = strtolower(trim((string)($_POST['verified_rolls_mode'] ?? '')));
            $manualModeRequested = strpos($verifiedMode, 'manual') !== false;
            if ($manualModeRequested && !jobs_can_manual_roll_entry_for_job($job)) {
                try { $db->rollback(); } catch (Throwable $e) {}
                echo json_encode(['ok' => false, 'error' => 'Manual roll verification is not allowed for this account. Please use QR scan only.']);
                break;
            }

            $isJumboSlitting = (strtolower(trim((string)($job['job_type'] ?? ''))) === 'slitting')
                && (strtolower(trim((string)($job['department'] ?? ''))) === 'jumbo_slitting');
            if ($isJumboSlitting) {
                $validationError = '';
                $requiredMap = [];

                // For EXTRA jobs, parent_roll and parent_details may be absent; derive from child_rolls.
                // Do NOT fall back to $job['roll_no'] — that holds the first OUTPUT roll, not the input parent.
                $extraChildParentNo = '';
                if (!empty($jobExtra['child_rolls']) && is_array($jobExtra['child_rolls'])) {
                    $extraChildParentNo = strtoupper(trim((string)($jobExtra['child_rolls'][0]['parent_roll_no'] ?? '')));
                }
                $parentPrimary = strtoupper(trim((string)($jobExtra['parent_details']['roll_no'] ?? ($jobExtra['parent_roll'] ?? $extraChildParentNo))));
                if ($parentPrimary !== '') {
                    $requiredMap[$parentPrimary] = true;
                }

                $parentRolls = $jobExtra['parent_rolls'] ?? [];
                if (is_string($parentRolls)) {
                    $parentRolls = preg_split('/\s*,\s*/', trim($parentRolls), -1, PREG_SPLIT_NO_EMPTY);
                }
                if (is_array($parentRolls)) {
                    foreach ($parentRolls as $pr) {
                        $rollNo = strtoupper(trim(is_array($pr) ? (string)($pr['roll_no'] ?? '') : (string)$pr));
                        if ($rollNo !== '') {
                            $requiredMap[$rollNo] = true;
                        }
                    }
                }

                foreach (['child_rolls', 'stock_rolls'] as $bucket) {
                    $rows = is_array($jobExtra[$bucket] ?? null) ? $jobExtra[$bucket] : [];
                    foreach ($rows as $rr) {
                        $parentNo = strtoupper(trim((string)($rr['parent_roll_no'] ?? '')));
                        if ($parentNo !== '') {
                            $requiredMap[$parentNo] = true;
                        }
                    }
                }

                if (empty($requiredMap)) {
                    $validationError = 'No parent rolls found for this Jumbo job. Please set parent roll before starting.';
                }

                $verifiedArr = jobs_collect_verified_rolls();

                $verifiedMap = [];
                foreach ($verifiedArr as $v) {
                    $rollNo = strtoupper(trim((string)$v));
                    if ($rollNo !== '') {
                        $verifiedMap[$rollNo] = true;
                    }
                }

                if ($validationError === '' && empty($verifiedMap)) {
                    $validationError = 'Parent roll verification incomplete. Please scan at least one parent roll.';
                }

                $missing = [];
                foreach (array_keys($requiredMap) as $req) {
                    if (!isset($verifiedMap[$req])) {
                        $missing[] = $req;
                    }
                }
                if ($validationError === '' && !empty($missing)) {
                    $validationError = 'Parent roll verification incomplete. Missing: ' . implode(', ', $missing);
                }

                if ($validationError !== '') {
                    try { $db->rollback(); } catch (Throwable $e) {}
                    echo json_encode(['ok' => false, 'error' => $validationError]);
                    break;
                }
            }

            $now = jobs_timer_now();
            $wasPaused = strtolower(trim((string)($jobExtra['timer_state'] ?? ''))) === 'paused';
            $jobExtra['timer_accumulated_seconds'] = jobs_timer_accumulated_seconds($jobExtra);
            $jobExtra['timer_active'] = true;
            $jobExtra['timer_state'] = 'running';
            if (empty($jobExtra['timer_started_at'])) {
                $jobExtra['timer_started_at'] = $now;
                $jobExtra = jobs_timer_push_event($jobExtra, 'start', $now);
            } elseif ($wasPaused) {
                $jobExtra = jobs_timer_close_pause_segment($jobExtra, $now);
                $jobExtra = jobs_timer_push_event($jobExtra, 'resume', $now);
            }
            $jobExtra['timer_last_resumed_at'] = $now;
            $jobExtra['timer_paused_at'] = '';
            $jobExtra['timer_pause_started_at'] = '';
            $jobExtra['timer_ended_at'] = '';
            $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);
            $upd = $db->prepare("UPDATE jobs SET status = ?, started_at = COALESCE(started_at, NOW()), extra_data = ? WHERE id = ?");
            $upd->bind_param('ssi', $newStatus, $safeExtraJson, $jobId);
        } elseif ($isFinishStatus) {
            $now = jobs_timer_now();
            $acc = jobs_timer_accumulated_seconds($jobExtra);
            if (!empty($jobExtra['timer_active']) && !empty($jobExtra['timer_last_resumed_at'])) {
                $acc += jobs_timer_seconds_between((string)$jobExtra['timer_last_resumed_at'], $now);
            }
            $jobExtra['timer_accumulated_seconds'] = max(0, $acc);
            $jobExtra['timer_active'] = false;
            $jobExtra['timer_state'] = 'completed';
            $jobExtra['timer_last_resumed_at'] = '';
            $jobExtra['timer_paused_at'] = '';
            $jobExtra['timer_pause_started_at'] = '';
            $jobExtra['timer_ended_at'] = $now;
            $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);
            $durationMinutes = (int)floor($acc / 60);
            $durSql = "UPDATE jobs SET status = ?, completed_at = NOW(), duration_minutes = ?, extra_data = ? WHERE id = ?";
            $upd = $db->prepare($durSql);
            $upd->bind_param('sisi', $newStatus, $durationMinutes, $safeExtraJson, $jobId);
        } elseif ($newStatus === 'Pending') {
            $jobExtra['timer_active'] = false;
            $jobExtra['timer_state'] = 'pending';
            $jobExtra['timer_last_resumed_at'] = '';
            $jobExtra['timer_paused_at'] = '';
            $jobExtra['timer_pause_started_at'] = '';
            $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);
            $upd = $db->prepare("UPDATE jobs SET status = ?, extra_data = ? WHERE id = ?");
            $upd->bind_param('ssi', $newStatus, $safeExtraJson, $jobId);
        } else {
            $upd = $db->prepare("UPDATE jobs SET status = ? WHERE id = ?");
            $upd->bind_param('si', $newStatus, $jobId);
        }
        $upd->execute();

        // Jumbo start should immediately move planning into active slitting stage.
        if ($newStatus === 'Running' && ($job['job_type'] ?? '') === 'Slitting') {
            $planId = (int)($job['planning_id'] ?? 0);
            if ($planId > 0) {
                $updPlan = $db->prepare("UPDATE planning SET status = 'Preparing Slitting' WHERE id = ?");
                $updPlan->bind_param('i', $planId);
                $updPlan->execute();
                jobs_set_planning_stage_status($db, $planId, 'Slitting');
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

        // Start event should immediately reflect active stage in planning for all departments.
        if ($newStatus === 'Running') {
            $runningStage = jobs_stage_status_for_event($job, 'running');
            $planId = (int)($job['planning_id'] ?? 0);
            if ($runningStage !== '') {
                jobs_set_planning_status_and_stage_for_job($db, $job, $runningStage);
            }
        }

        // If completing a job, mark parent rolls as Slitted + child rolls as Job Assign
        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true) && ($job['job_type'] ?? '') === 'Slitting') {
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
                    $updCr = $db->prepare("UPDATE paper_stock SET status = 'Job Assign', date_used = NOW() WHERE roll_no = ? AND status = 'Slitting'");
                    $updCr->bind_param('s', $crn);
                    $updCr->execute();
                }
            }

            $jobNoUpper = strtoupper(trim((string)($job['job_no'] ?? '')));
            $deptNow = strtolower(trim((string)($job['department'] ?? '')));
            $isLegacyExtraShape = ($deptNow === 'jumbo_slitting')
                && ((int)($job['previous_job_id'] ?? 0) > 0)
                && (strpos($jobNoUpper, '-EXTRA') !== false);
            $isTempExtraCard = !empty($extraClose['is_flexo_extra_temp_card']) || $isLegacyExtraShape;
            if ($isTempExtraCard) {
                $mergeTargetId = (int)($extraClose['merge_target_job_id'] ?? 0);
                if ($mergeTargetId <= 0) {
                    $mergeTargetId = (int)($job['previous_job_id'] ?? 0);
                }
                $flexoJobIdForUnlock = (int)($extraClose['flexo_job_id'] ?? 0);
                $requestIdForUnlock = (int)($extraClose['flexo_request_id'] ?? 0);
                if ($mergeTargetId > 0) {
                    $targetStmt = $db->prepare("SELECT id, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
                    $targetStmt->bind_param('i', $mergeTargetId);
                    $targetStmt->execute();
                    $target = $targetStmt->get_result()->fetch_assoc();
                    if ($target) {
                        $targetExtra = json_decode((string)($target['extra_data'] ?? '{}'), true);
                        if (!is_array($targetExtra)) $targetExtra = [];
                        $targetChild = is_array($targetExtra['child_rolls'] ?? null) ? $targetExtra['child_rolls'] : [];
                        $incoming = [];
                        foreach ($childRollsClose as $row) {
                            if (!is_array($row)) continue;
                            $rollNoIncoming = trim((string)($row['roll_no'] ?? ''));
                            if ($rollNoIncoming === '') continue;
                            $incoming[] = [
                                'roll_no' => $rollNoIncoming,
                                'parent_roll_no' => (string)($row['parent_roll_no'] ?? ''),
                                'width' => jobs_float2($row['width'] ?? ($row['width_mm'] ?? 0)),
                                'length' => jobs_float2($row['length'] ?? ($row['length_mtr'] ?? 0)),
                                'status' => 'Extra',
                                'remarks' => 'Merged from temporary EXTRA card ' . (string)($job['job_no'] ?? ''),
                                'from_flexo_request' => true,
                                'flexo_request_id' => $requestIdForUnlock,
                                'extra_route' => 'jumbo',
                            ];
                        }
                        $targetExtra['child_rolls'] = jobs_merge_child_rows_by_roll($targetChild, $incoming);
                        // Also add EXTRA job's referenced parent roll(s) to the main job's parent_rolls list.
                        // Without this, display omits e.g. SLC/2026/0016-C from the parent table after merge.
                        $extraMergedParents = [];
                        foreach ($incoming as $inc) {
                            $incParent = strtoupper(trim((string)($inc['parent_roll_no'] ?? '')));
                            if ($incParent !== '' && !in_array($incParent, $extraMergedParents, true)) {
                                $extraMergedParents[] = $incParent;
                            }
                        }
                        if (!empty($extraMergedParents)) {
                            $existingPRolls = is_array($targetExtra['parent_rolls'] ?? null) ? $targetExtra['parent_rolls'] : [];
                            foreach ($extraMergedParents as $ep) {
                                if (!in_array($ep, $existingPRolls, true)) {
                                    $existingPRolls[] = $ep;
                                }
                            }
                            $targetExtra['parent_rolls'] = $existingPRolls;
                        }
                        $targetExtra['total_roll_count'] = count($targetExtra['child_rolls']) + count(is_array($targetExtra['stock_rolls'] ?? null) ? $targetExtra['stock_rolls'] : []) + 1;
                        $targetExtra['has_extra_roll_update'] = true;
                        $targetExtra['last_extra_roll_update_at'] = date('c');
                        $targetExtra['last_extra_roll_update_from_temp_card'] = (string)($job['job_no'] ?? '');
                        $targetExtraJson = json_encode($targetExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $updTarget = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ?");
                        $updTarget->bind_param('si', $targetExtraJson, $mergeTargetId);
                        $updTarget->execute();
                    }
                }

                $extraClose['merge_completed_at'] = date('c');
                $extraClose['merge_completed_by_job_status'] = $newStatus;
                $extraClose['merge_completed'] = true;
                $extraCloseJson = json_encode($extraClose, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $updSelf = $db->prepare("UPDATE jobs SET extra_data = ?, deleted_at = NOW() WHERE id = ?");
                $updSelf->bind_param('si', $extraCloseJson, $jobId);
                $updSelf->execute();

                if ($flexoJobIdForUnlock > 0) {
                    $flexStmt = $db->prepare("SELECT id, status, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1 FOR UPDATE");
                    $flexStmt->bind_param('i', $flexoJobIdForUnlock);
                    $flexStmt->execute();
                    $flexRow = $flexStmt->get_result()->fetch_assoc();
                    if ($flexRow) {
                        $flexExtra = json_decode((string)($flexRow['extra_data'] ?? '{}'), true);
                        if (!is_array($flexExtra)) $flexExtra = [];
                        unset($flexExtra['flexo_waiting_additional_slitting']);
                        unset($flexExtra['flexo_waiting_additional_slitting_since']);
                        unset($flexExtra['flexo_waiting_additional_slitting_reason']);
                        unset($flexExtra['flexo_waiting_requires_jumbo_end']);
                        unset($flexExtra['flexo_status_before_waiting_slitting']);
                        $flexExtra['flexo_last_extra_merge_done_at'] = date('c');
                        $flexExtra['flexo_last_extra_merge_source_job_no'] = (string)($job['job_no'] ?? '');
                        $flexExtraJson = json_encode($flexExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $targetFlexStatus = 'Pending';
                        $updFlex = $db->prepare("UPDATE jobs SET status = ?, extra_data = ? WHERE id = ?");
                        $updFlex->bind_param('ssi', $targetFlexStatus, $flexExtraJson, $flexoJobIdForUnlock);
                        $updFlex->execute();

                        $msg = 'Temporary jumbo EXTRA merged. Flexo job is ready to start again.';
                        $dep = 'flexo_printing';
                        $typ = 'success';
                        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
                        $nIns->bind_param('isss', $flexoJobIdForUnlock, $dep, $msg, $typ);
                        $nIns->execute();
                    }
                }
            }
        } elseif (in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true) && $job['roll_no']) {
            $extraClose = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
            $isDirectPrintingBypass = !empty($extraClose['direct_flexo_bypass'])
                && (in_array(strtolower(trim((string)($job['job_type'] ?? ''))), ['printing', 'flexo'], true)
                    || strtolower(trim((string)($job['department'] ?? ''))) === 'flexo_printing');
            if ($isDirectPrintingBypass) {
                $assignedChildRolls = is_array($extraClose['assigned_child_rolls'] ?? null) ? $extraClose['assigned_child_rolls'] : [];
                foreach ($assignedChildRolls as $assignedRoll) {
                    $assignedRollNo = trim((string)($assignedRoll['roll_no'] ?? ''));
                    if ($assignedRollNo === '') continue;
                    $updAssigned = $db->prepare("UPDATE paper_stock SET status = 'Job Assign', date_used = COALESCE(date_used, NOW()) WHERE roll_no = ? AND status IN ('Slitting','Main','Stock','Available')");
                    $updAssigned->bind_param('s', $assignedRollNo);
                    $updAssigned->execute();
                }
            }

            $isDirectBarcodeBypass = !empty($extraClose['direct_barcode_bypass'])
                && (in_array(strtolower(trim((string)($job['job_type'] ?? ''))), ['finishing'], true)
                    && strtolower(trim((string)($job['department'] ?? ''))) === 'barcode');
            if ($isDirectBarcodeBypass) {
                $assignedChildRolls = is_array($extraClose['assigned_child_rolls'] ?? null) ? $extraClose['assigned_child_rolls'] : [];
                foreach ($assignedChildRolls as $assignedRoll) {
                    $assignedRollNo = trim((string)($assignedRoll['roll_no'] ?? ''));
                    if ($assignedRollNo === '') continue;
                    $updAssigned = $db->prepare("UPDATE paper_stock SET status = 'Job Assign', date_used = COALESCE(date_used, NOW()) WHERE roll_no = ? AND status IN ('Slitting','Main','Stock','Available')");
                    $updAssigned->bind_param('s', $assignedRollNo);
                    $updAssigned->execute();
                }
            }

            // Non-slitting jobs: simple parent roll update
            $updRoll = $db->prepare("UPDATE paper_stock SET status = 'Slitted' WHERE roll_no = ? AND status = 'Slitting'");
            $updRoll->bind_param('s', $job['roll_no']);
            $updRoll->execute();
        }

        // Sequential gating: when a job completes, move next queued jobs to Pending
        $planningExtraForNotifications = [];

        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true)) {
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

            // Completion should reflect in planning board for all departments.
            $completeStage = jobs_stage_status_for_event($job, 'complete');
            if ($completeStage !== '') {
                jobs_set_planning_status_and_stage_for_job($db, $job, $completeStage);
            }

            // Printing completion should reflect in planning board as Printed.
            $jobTypeNow = strtolower(trim((string)($job['job_type'] ?? '')));
            if (in_array($jobTypeNow, ['printing', 'flexo'], true)) {
                $planId = (int)($job['planning_id'] ?? 0);
                if ($planId > 0) {
                    $planExtraStmt = $db->prepare("SELECT job_no, extra_data FROM planning WHERE id = ? LIMIT 1");
                    if ($planExtraStmt) {
                        $planExtraStmt->bind_param('i', $planId);
                        $planExtraStmt->execute();
                        $planRow = $planExtraStmt->get_result()->fetch_assoc();
                        if ($planRow) {
                            $planNoForStage = trim((string)($planRow['job_no'] ?? ''));
                            $pExtra = json_decode((string)($planRow['extra_data'] ?? '{}'), true) ?: [];
                            $planningExtraForNotifications = $pExtra;
                            $pExtra['printing_planning'] = 'Printed';
                            $pExtraJson = json_encode($pExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $upPlanExtra = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
                            if ($upPlanExtra) {
                                $upPlanExtra->bind_param('si', $pExtraJson, $planId);
                                $upPlanExtra->execute();
                            }

                            $departmentChoices = erp_get_machine_departments($db);
                            $selectedDepartments = erp_department_selection_list((string)($pExtra['department_route'] ?? ''), $departmentChoices, []);
                            $allowLabelSlitting = erp_department_selection_contains($selectedDepartments, 'Label Slitting', $departmentChoices, []);
                            $allowDieCutting = erp_department_selection_contains($selectedDepartments, 'Die-Cutting', $departmentChoices, []);
                            $dieIsFlatbedLike = jobs_is_flatbed_like_die((string)($pExtra['die'] ?? ''));

                            if ($allowLabelSlitting && !($allowDieCutting && $dieIsFlatbedLike)) {
                                jobs_ensure_label_slitting_job(
                                    $db,
                                    $planId,
                                    $planNoForStage,
                                    trim((string)($job['roll_no'] ?? '')),
                                    $jobId,
                                    trim((string)($job['job_no'] ?? '')),
                                    'Printing',
                                    jobs_display_job_name($job),
                                    3
                                );
                            }
                        }
                    }
                }
            }

            // Die-Cutting completion should reflect in planning board as Die Cut.
            if (jobs_is_die_cutting_job($job)) {
                $planId = (int)($job['planning_id'] ?? 0);
                if ($planId > 0) {
                    $planExtraStmtD = $db->prepare("SELECT job_no, extra_data FROM planning WHERE id = ? LIMIT 1");
                    if ($planExtraStmtD) {
                        $planExtraStmtD->bind_param('i', $planId);
                        $planExtraStmtD->execute();
                        $planRowD = $planExtraStmtD->get_result()->fetch_assoc();
                        if ($planRowD) {
                            $planNoForStageD = trim((string)($planRowD['job_no'] ?? ''));
                            $pExtraD = json_decode((string)($planRowD['extra_data'] ?? '{}'), true) ?: [];
                            $planningExtraForNotifications = $pExtraD;
                            $pExtraD['printing_planning'] = 'Die Cut';
                            $pExtraDJson = json_encode($pExtraD, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $upPlanExtraD = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
                            if ($upPlanExtraD) {
                                $upPlanExtraD->bind_param('si', $pExtraDJson, $planId);
                                $upPlanExtraD->execute();
                            }

                            $departmentChoicesD = erp_get_machine_departments($db);
                            $selectedDepartmentsD = erp_department_selection_list((string)($pExtraD['department_route'] ?? ''), $departmentChoicesD, []);
                            $allowLabelSlittingD = erp_department_selection_contains($selectedDepartmentsD, 'Label Slitting', $departmentChoicesD, []);
                            if ($allowLabelSlittingD) {
                                jobs_ensure_label_slitting_job(
                                    $db,
                                    $planId,
                                    $planNoForStageD,
                                    trim((string)($job['roll_no'] ?? '')),
                                    $jobId,
                                    trim((string)($job['job_no'] ?? '')),
                                    'Die-Cutting',
                                    jobs_display_job_name($job),
                                    4
                                );
                            }
                        }
                    }
                }
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
                jobs_set_planning_stage_status($db, $planId, 'Slitted');
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

        if (in_array($newStatus, ['Closed', 'Finalized', 'Completed', 'QC Passed', 'QC Failed'], true)) {
            $advanceTargets = jobsAdvanceNotificationTargets((string)($job['department'] ?? ''), $planningExtraForNotifications);
            if (!empty($advanceTargets)) {
                $advanceMsg = $job['job_no'] . ' completed in ' . jobs_department_label((string)($job['department'] ?? '')) . ' — next stage updated';
                $routePath = jobs_notification_job_page_by_department((string)($job['department'] ?? ''));
                createDepartmentNotifications($db, $advanceTargets, $jobId, $advanceMsg, 'success', $routePath);
            }
        }

        $db->commit();

        echo json_encode(['ok' => true, 'job_id' => $jobId, 'status' => $newStatus]);
        break;

    // ─── End timer before completion ───────────────────────
    case 'end_timer_session':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }

        $stmt = $db->prepare("SELECT id, status, planning_id, department, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $currentStatusNorm = strtolower(trim(str_replace(['-', '_'], ' ', (string)($job['status'] ?? ''))));
        if ($currentStatusNorm === 'packing done') {
            echo json_encode(['ok' => false, 'error' => 'Packing Done job cannot be reset to Pending.']);
            break;
        }
        if (strcasecmp((string)($job['status'] ?? ''), 'Running') !== 0) {
            echo json_encode(['ok' => false, 'error' => 'Only running jobs can end the timer.']);
            break;
        }

        $jobExtra = jobs_decode_extra_data($job['extra_data'] ?? '{}');
        $now = jobs_timer_now();
        $acc = jobs_timer_accumulated_seconds($jobExtra);
        if (!empty($jobExtra['timer_active']) && !empty($jobExtra['timer_last_resumed_at'])) {
            $acc += jobs_timer_seconds_between((string)$jobExtra['timer_last_resumed_at'], $now);
            $jobExtra = jobs_timer_close_work_segment($jobExtra, $now);
        }
        $jobExtra['timer_accumulated_seconds'] = max(0, $acc);
        $jobExtra['timer_active'] = false;
        $jobExtra['timer_state'] = 'ended';
        $jobExtra['timer_last_resumed_at'] = '';
        $jobExtra['timer_paused_at'] = '';
        $jobExtra['timer_pause_started_at'] = '';
        $jobExtra['timer_ended_at'] = $now;
        $jobExtra = jobs_timer_push_event($jobExtra, 'end', $now);
        $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ?");
        $upd->bind_param('si', $safeExtraJson, $jobId);
        $upd->execute();

        $jobTypeEnd = strtolower(trim((string)($job['job_type'] ?? '')));
        if (($job['job_type'] ?? '') === 'Slitting') {
            jobs_set_planning_status_and_stage_for_job($db, $job, 'Slitting Pause');
        } elseif (in_array($jobTypeEnd, ['printing', 'flexo'], true)) {
            jobs_set_planning_status_and_stage_for_job($db, $job, 'Printed');
        } else {
            $endStage = jobs_stage_status_for_event($job, 'end');
            if ($endStage !== '') {
                jobs_set_planning_status_and_stage_for_job($db, $job, $endStage);
            }
        }

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'timer_active' => false,
            'timer_state' => 'ended',
            'timer_ended_at' => $jobExtra['timer_ended_at'],
            'timer_accumulated_seconds' => (int)$jobExtra['timer_accumulated_seconds'],
        ]);
        break;

    // ─── Pause timer (keeps running status) ────────────────
    case 'pause_timer_session':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }

        $stmt = $db->prepare("SELECT id, status, planning_id, department, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();

        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }
        if (strcasecmp((string)($job['status'] ?? ''), 'Running') !== 0) {
            echo json_encode(['ok' => false, 'error' => 'Only running jobs can pause timer.']);
            break;
        }

        $jobExtra = jobs_decode_extra_data($job['extra_data'] ?? '{}');
        if (empty($jobExtra['timer_active'])) {
            echo json_encode(['ok' => false, 'error' => 'Timer is not active.']);
            break;
        }

        $now = jobs_timer_now();
        $acc = jobs_timer_accumulated_seconds($jobExtra);
        if (!empty($jobExtra['timer_last_resumed_at'])) {
            $acc += jobs_timer_seconds_between((string)$jobExtra['timer_last_resumed_at'], $now);
            $jobExtra = jobs_timer_close_work_segment($jobExtra, $now);
        }
        $jobExtra['timer_accumulated_seconds'] = max(0, $acc);
        $jobExtra['timer_active'] = false;
        $jobExtra['timer_state'] = 'paused';
        $jobExtra['timer_last_resumed_at'] = '';
        $jobExtra['timer_paused_at'] = $now;
        $jobExtra['timer_pause_started_at'] = $now;
        $jobExtra = jobs_timer_push_event($jobExtra, 'pause', $now);
        $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ?");
        $upd->bind_param('si', $safeExtraJson, $jobId);
        $upd->execute();

        $jobTypePause = strtolower(trim((string)($job['job_type'] ?? '')));
        if (($job['job_type'] ?? '') === 'Slitting') {
            jobs_set_planning_status_and_stage_for_job($db, $job, 'Slitting Pause');
        } elseif (in_array($jobTypePause, ['printing', 'flexo'], true)) {
            jobs_set_planning_status_and_stage_for_job($db, $job, 'Printing Pause');
        } else {
            $pauseStage = jobs_stage_status_for_event($job, 'pause');
            if ($pauseStage !== '') {
                jobs_set_planning_status_and_stage_for_job($db, $job, $pauseStage);
            }
        }

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'timer_active' => false,
            'timer_state' => 'paused',
            'timer_paused_at' => $jobExtra['timer_paused_at'],
            'timer_accumulated_seconds' => (int)$jobExtra['timer_accumulated_seconds'],
        ]);
        break;

    // ─── Cancel timer and reset job to fresh pending ───────
    case 'reset_timer_session':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }

        $stmt = $db->prepare("SELECT id, planning_id, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        $jobExtra = jobs_decode_extra_data($job['extra_data'] ?? '{}');
        $jobExtra['timer_active'] = false;
        $jobExtra['timer_state'] = 'pending';
        $jobExtra['timer_started_at'] = '';
        $jobExtra['timer_last_resumed_at'] = '';
        $jobExtra['timer_paused_at'] = '';
        $jobExtra['timer_pause_started_at'] = '';
        $jobExtra['timer_ended_at'] = '';
        $jobExtra['timer_accumulated_seconds'] = 0;
        $jobExtra['timer_events'] = [];
        $jobExtra['timer_work_segments'] = [];
        $jobExtra['timer_pause_segments'] = [];
        $safeExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE);

        $upd = $db->prepare("UPDATE jobs SET status = 'Pending', started_at = NULL, completed_at = NULL, duration_minutes = NULL, extra_data = ? WHERE id = ?");
        $upd->bind_param('si', $safeExtraJson, $jobId);
        $upd->execute();

        if (($job['job_type'] ?? '') === 'Slitting') {
            $planId = (int)($job['planning_id'] ?? 0);
            if ($planId > 0) {
                jobs_set_planning_stage_status($db, $planId, 'Preparing Slitting');
            }
        }

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'Pending',
            'timer_state' => 'pending',
            'timer_active' => false,
            'timer_accumulated_seconds' => 0,
        ]);
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

        $curStmt = $db->prepare("SELECT job_type, department, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $curStmt->bind_param('i', $jobId);
        $curStmt->execute();
        $curJob = $curStmt->get_result()->fetch_assoc();
        $currentExtra = jobs_decode_extra_data($curJob['extra_data'] ?? '{}');
        $extraArr = jobs_array_merge_deep($currentExtra, $extraArr);

        $jobType = trim((string)($curJob['job_type'] ?? ''));
        $department = trim((string)($curJob['department'] ?? ''));
        if ($jobType === 'Printing' || strtolower($department) === 'flexo_printing') {
            $aniloxValidation = jobs_validate_printing_anilox_usage($db, $extraArr);
            if (empty($aniloxValidation['ok'])) {
                echo json_encode(['ok' => false, 'error' => (string)($aniloxValidation['error'] ?? 'Anilox validation failed')]);
                break;
            }
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

    // ─── Upload physical image for printing job ────────────
    case 'upload_printing_photo':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }
        if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
            echo json_encode(['ok' => false, 'error' => 'Missing photo file']);
            break;
        }

        $jobStmt = $db->prepare("SELECT id, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }
        if ((string)($job['job_type'] ?? '') !== 'Printing') {
            echo json_encode(['ok' => false, 'error' => 'This upload is only allowed for printing jobs']);
            break;
        }

        $file = $_FILES['photo'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            break;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid upload source']);
            break;
        }

        $maxBytes = 8 * 1024 * 1024;
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            echo json_encode(['ok' => false, 'error' => 'Image must be up to 8MB']);
            break;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extMap[$mime])) {
            echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed']);
            break;
        }

        $uploadDir = __DIR__ . '/../../uploads/jobs/printing';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            echo json_encode(['ok' => false, 'error' => 'Upload folder is not writable']);
            break;
        }

        $fname = 'print_' . $jobId . '_' . date('Ymd_His') . '_' . substr(sha1((string)mt_rand()), 0, 8) . '.' . $extMap[$mime];
        $absPath = $uploadDir . '/' . $fname;
        $relPath = 'uploads/jobs/printing/' . $fname;

        if (!move_uploaded_file($tmp, $absPath)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to move uploaded file']);
            break;
        }

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        $extra['physical_print_photo_path'] = $relPath;
        $extra['physical_print_photo_url'] = jobs_join_base_url($relPath);
        $extra['physical_print_photo_uploaded_at'] = date('c');
        $extra['physical_print_photo_uploaded_by'] = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
        $safeJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $safeJson, $jobId);
        $upd->execute();

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'photo_path' => $relPath,
            'photo_url' => jobs_join_base_url($relPath),
        ]);
        break;

    // ─── Upload Jumbo Slitting Photo ────────────────────────
    case 'upload_jumbo_photo':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }

        $jobStmt = $db->prepare("SELECT id, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }

        if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
            echo json_encode(['ok' => false, 'error' => 'Missing photo file']);
            break;
        }

        $file = $_FILES['photo'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            break;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid upload source']);
            break;
        }

        $maxBytes = 8 * 1024 * 1024;
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            echo json_encode(['ok' => false, 'error' => 'Image must be up to 8MB']);
            break;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
        }

        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extMap[$mime])) {
            echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed']);
            break;
        }

        $uploadDir = __DIR__ . '/../../uploads/jobs/jumbo';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            echo json_encode(['ok' => false, 'error' => 'Upload folder is not writable']);
            break;
        }

        $fname = 'jumbo_' . $jobId . '_' . date('Ymd_His') . '_' . substr(sha1((string)mt_rand()), 0, 8) . '.' . $extMap[$mime];
        $absPath = $uploadDir . '/' . $fname;
        $relPath = 'uploads/jobs/jumbo/' . $fname;

        if (!move_uploaded_file($tmp, $absPath)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to move uploaded file']);
            break;
        }

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        $extra['jumbo_photo_path'] = $relPath;
        $extra['jumbo_photo_url'] = jobs_join_base_url($relPath);
        $extra['jumbo_photo_uploaded_at'] = date('c');
        $extra['jumbo_photo_uploaded_by'] = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
        $safeJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $safeJson, $jobId);
        $upd->execute();

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'photo_path' => $relPath,
            'photo_url' => jobs_join_base_url($relPath),
        ]);
        break;

    // ─── Upload Die-Cutting completion photo ───────────────
    case 'upload_die_cutting_photo':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }
        if (empty($_FILES['photo']) || !is_array($_FILES['photo'])) {
            echo json_encode(['ok' => false, 'error' => 'Missing photo file']);
            break;
        }

        $jobStmt = $db->prepare("SELECT id, planning_id, job_type, department, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }
        if (!jobs_is_operator_media_upload_job($job)) {
            echo json_encode(['ok' => false, 'error' => 'This upload is only allowed for Barcode, Die-Cutting, Label Slitting, or POS jobs']);
            break;
        }

        $file = $_FILES['photo'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            break;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid upload source']);
            break;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > (8 * 1024 * 1024)) {
            echo json_encode(['ok' => false, 'error' => 'Image must be up to 8MB']);
            break;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
        }
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extMap[$mime])) {
            echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed']);
            break;
        }

        $uploadDir = __DIR__ . '/../../uploads/jobs/die-cutting';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            echo json_encode(['ok' => false, 'error' => 'Upload folder is not writable']);
            break;
        }

        $fname = 'die_cutting_' . $jobId . '_' . date('Ymd_His') . '_' . substr(sha1((string)mt_rand()), 0, 8) . '.' . $extMap[$mime];
        $absPath = $uploadDir . '/' . $fname;
        $relPath = 'uploads/jobs/die-cutting/' . $fname;
        if (!move_uploaded_file($tmp, $absPath)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to move uploaded file']);
            break;
        }

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        $extra['die_cutting_photo_path'] = $relPath;
        $extra['die_cutting_photo_url'] = jobs_join_base_url($relPath);
        $extra['die_cutting_photo_uploaded_at'] = date('c');
        $extra['die_cutting_photo_uploaded_by'] = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
        $safeJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $safeJson, $jobId);
        $upd->execute();

        $planId = (int)($job['planning_id'] ?? 0);
        if ($planId > 0) {
            $planStmt = $db->prepare("SELECT extra_data FROM planning WHERE id = ? LIMIT 1");
            if ($planStmt) {
                $planStmt->bind_param('i', $planId);
                $planStmt->execute();
                $planRow = $planStmt->get_result()->fetch_assoc();
                if ($planRow) {
                    $planExtra = json_decode((string)($planRow['extra_data'] ?? '{}'), true);
                    if (!is_array($planExtra)) $planExtra = [];
                    $planExtra['image_path'] = $relPath;
                    $planExtra['image_url'] = jobs_join_base_url($relPath);
                    $planExtra['planning_image_path'] = $relPath;
                    $planExtra['planning_image_url'] = jobs_join_base_url($relPath);
                    $planExtra['operator_photo_uploaded_at'] = date('c');
                    $planExtra['operator_photo_uploaded_by'] = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
                    $planExtraJson = json_encode($planExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $upPlan = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
                    if ($upPlan) {
                        $upPlan->bind_param('si', $planExtraJson, $planId);
                        $upPlan->execute();
                    }
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'photo_path' => $relPath,
            'photo_url' => jobs_join_base_url($relPath),
        ]);
        break;

    // ─── Upload Die-Cutting voice note ─────────────────────
    case 'upload_die_cutting_voice':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
            break;
        }
        if (empty($_FILES['voice']) || !is_array($_FILES['voice'])) {
            echo json_encode(['ok' => false, 'error' => 'Missing voice file']);
            break;
        }

        $jobStmt = $db->prepare("SELECT id, job_type, department, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            break;
        }
        if (!jobs_is_operator_media_upload_job($job)) {
            echo json_encode(['ok' => false, 'error' => 'This upload is only allowed for Barcode, Die-Cutting, Label Slitting, or POS jobs']);
            break;
        }

        $file = $_FILES['voice'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Upload failed']);
            break;
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid upload source']);
            break;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > (10 * 1024 * 1024)) {
            echo json_encode(['ok' => false, 'error' => 'Voice note must be up to 10MB']);
            break;
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)finfo_file($fi, $tmp);
                finfo_close($fi);
            }
        }

        $extMap = [
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
        ];
        if (!isset($extMap[$mime])) {
            echo json_encode(['ok' => false, 'error' => 'Only WEBM/OGG/MP3/WAV/M4A audio is allowed']);
            break;
        }

        $uploadDir = __DIR__ . '/../../uploads/jobs/die-cutting';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            echo json_encode(['ok' => false, 'error' => 'Upload folder is not writable']);
            break;
        }

        $fname = 'die_cutting_voice_' . $jobId . '_' . date('Ymd_His') . '_' . substr(sha1((string)mt_rand()), 0, 8) . '.' . $extMap[$mime];
        $absPath = $uploadDir . '/' . $fname;
        $relPath = 'uploads/jobs/die-cutting/' . $fname;
        if (!move_uploaded_file($tmp, $absPath)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to move uploaded file']);
            break;
        }

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        $extra['die_cutting_voice_note_path'] = $relPath;
        $extra['die_cutting_voice_note_url'] = jobs_join_base_url($relPath);
        $extra['die_cutting_voice_uploaded_at'] = date('c');
        $extra['die_cutting_voice_uploaded_by'] = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
        $safeJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $upd->bind_param('si', $safeJson, $jobId);
        $upd->execute();

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'voice_path' => $relPath,
            'voice_url' => jobs_join_base_url($relPath),
        ]);
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

    case 'get_roll_by_id':
        $stockId = (int)($_GET['id'] ?? 0);
        if ($stockId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            break;
        }
        $stmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, sqm, status FROM paper_stock WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $stockId);
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
        $changeReason = trim((string)($_POST['change_reason'] ?? ''));
        $rollChangesJson = (string)($_POST['roll_changes_json'] ?? '[]');
        $rollChanges = json_decode($rollChangesJson, true);
        if (!is_array($rollChanges)) $rollChanges = [];
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
            'roll_changes' => $rollChanges,
            'rows' => $rows,
            'generated_child_roll_nos' => array_values(array_map(function($row) {
                return (string)($row['roll_no'] ?? '');
            }, array_filter($rows, function($row) {
                return is_array($row) && strtolower(trim((string)($row['bucket'] ?? 'child'))) === 'child';
            }))),
            'wastage_kg' => $operatorWastageKg,
            'operator_remarks' => $operatorRemarks,
            'change_reason' => $changeReason,
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
        $reviewRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $canReviewRequest = in_array($reviewRole, ['admin', 'manager', 'system_admin', 'super_admin', 'systemadmin', 'superadmin'], true)
            || hasRole('admin', 'manager', 'system_admin', 'super_admin');
        if (!$canReviewRequest) {
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

    // ─── Submit flexo operator request ─────────────────────
    case 'submit_flexo_operator_request':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        jobs_ensure_change_request_table($db);

        $jobId = (int)($_POST['job_id'] ?? 0);
        $payloadRaw = (string)($_POST['request_payload'] ?? '{}');
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) $payload = [];

        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid job_id']);
            break;
        }

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Flexo job not found']);
            break;
        }

        $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
        $dept = strtolower(trim((string)($job['department'] ?? '')));
        $isFlexo = $jobType === 'printing' || $dept === 'flexo_printing';
        if (!$isFlexo) {
            echo json_encode(['ok' => false, 'error' => 'Only flexo printing jobs are supported']);
            break;
        }

        $pendingChk = $db->prepare("SELECT id FROM job_change_requests WHERE job_id = ? AND request_type = 'flexo_operator_request' AND status = 'Pending' ORDER BY id DESC LIMIT 1");
        $pendingChk->bind_param('i', $jobId);
        $pendingChk->execute();
        $existing = $pendingChk->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['ok' => false, 'error' => 'A pending request already exists for this job', 'request_id' => (int)$existing['id']]);
            break;
        }

        $additional = is_array($payload['additional_roll_request'] ?? null) ? $payload['additional_roll_request'] : [];
        $excess = is_array($payload['excess_roll_adjustment'] ?? null) ? $payload['excess_roll_adjustment'] : [];
        $addEnabled = !empty($additional['enabled']);
        $exEnabled = !empty($excess['enabled']);
        if (!$addEnabled && !$exEnabled) {
            echo json_encode(['ok' => false, 'error' => 'No flexo request data provided']);
            break;
        }

        if ($addEnabled) {
            $w = jobs_float2($additional['width_mm'] ?? 0);
            $l = jobs_float2($additional['length_mtr'] ?? 0);
            if ($w <= 0 || $l <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Additional roll width and length must be greater than 0']);
                break;
            }
        }
        if ($exEnabled) {
            $srcRoll = trim((string)($excess['source_roll_no'] ?? ''));
            $used = jobs_float2($excess['used_length_mtr'] ?? 0);
            $rem = jobs_float2($excess['remaining_length_mtr'] ?? 0);
            if ($srcRoll === '') {
                echo json_encode(['ok' => false, 'error' => 'Source roll is required for excess adjustment']);
                break;
            }
            if ($used <= 0 && $rem <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Used or remaining length must be greater than 0']);
                break;
            }
            if ($rem < 0) {
                echo json_encode(['ok' => false, 'error' => 'Remaining length cannot be negative']);
                break;
            }
        }

        $requestedBy = (int)($_SESSION['user_id'] ?? 0);
        $requestedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Operator')));
        if ($requestedByName === '') $requestedByName = 'Operator';

        $jobExtra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($jobExtra)) $jobExtra = [];
        $payload['requested_context'] = [
            'job_no' => (string)($job['job_no'] ?? ''),
            'job_name' => jobs_display_job_name($job),
            'department' => (string)($job['department'] ?? ''),
            'requested_at_iso' => date('c'),
            'requested_by' => $requestedByName,
            'before_state' => [
                'assigned_child_rolls' => is_array($jobExtra['assigned_child_rolls'] ?? null) ? $jobExtra['assigned_child_rolls'] : [],
                'flexo_extension_tasks' => is_array($jobExtra['flexo_extension_tasks'] ?? null) ? $jobExtra['flexo_extension_tasks'] : [],
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ins = $db->prepare("INSERT INTO job_change_requests (job_id, request_type, payload_json, status, requested_by, requested_by_name) VALUES (?, 'flexo_operator_request', ?, 'Pending', ?, ?)");
        $ins->bind_param('isis', $jobId, $payloadJson, $requestedBy, $requestedByName);
        $ins->execute();
        $rid = (int)$db->insert_id;

        $notifDept = 'flexo_printing';
        $notifMsg = 'Flexo operator request submitted for ' . ((string)($job['job_no'] ?? ('JOB-' . $jobId)));
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'warning')");
        $nIns->bind_param('iss', $jobId, $notifDept, $notifMsg);
        $nIns->execute();

            // ── For ADD ROLL requests: pre-create the pending slitting task in job extra_data ──
            // This ensures complete_flexo_slitting_from_batch can always find the task.
            if ($addEnabled && $rid > 0) {
                $reqWidth  = jobs_float2($additional['width_mm'] ?? 0);
                $reqLength = jobs_float2($additional['length_mtr'] ?? 0);
                $reqReason = trim((string)($additional['reason'] ?? ''));

                $jLock = $db->prepare("SELECT extra_data, status FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
                $jLock->bind_param('i', $jobId);
                $jLock->execute();
                $jRow = $jLock->get_result()->fetch_assoc();
                if ($jRow) {
                    $jExt = json_decode((string)($jRow['extra_data'] ?? '{}'), true);
                    if (!is_array($jExt)) $jExt = [];
                    $jTasks = is_array($jExt['flexo_extension_tasks'] ?? null) ? $jExt['flexo_extension_tasks'] : [];

                    // Duplicate guard: only add if no pending task for this exact request_id
                    $taskAlreadyQueued = false;
                    foreach ($jTasks as $tRow) {
                        if (!is_array($tRow)) continue;
                        if (strtolower(trim((string)($tRow['type'] ?? ''))) === 'additional_slitting'
                            && strtolower(trim((string)($tRow['status'] ?? ''))) === 'pending'
                            && (int)($tRow['request_id'] ?? 0) === $rid) {
                            $taskAlreadyQueued = true;
                            break;
                        }
                    }

                    if (!$taskAlreadyQueued && $reqWidth > 0 && $reqLength > 0) {
                        $jTasks[] = [
                            'type'          => 'additional_slitting',
                            'tag'           => 'Additional Slitting',
                            'status'        => 'Pending',
                            'job_id'        => $jobId,
                            'job_no'        => (string)($job['job_no'] ?? ''),
                            'request_id'    => $rid,
                            'width_mm'      => $reqWidth,
                            'length_mtr'    => $reqLength,
                            'reason'        => $reqReason,
                            'created_by'    => $requestedByName,
                            'created_by_id' => $requestedBy,
                            'created_at'    => date('c'),
                            'source'        => 'operator_submit',
                        ];
                        $jExt['flexo_extension_tasks'] = $jTasks;
                        $jExt['flexo_waiting_additional_slitting'] = true;
                        $jExt['flexo_waiting_additional_slitting_since'] = date('c');
                        $jExt['flexo_waiting_additional_slitting_reason'] = 'ADD ROLL pending request #' . $rid;
                        if (!isset($jExt['flexo_status_before_waiting_slitting'])) {
                            $curStatus = trim((string)($jRow['status'] ?? 'Running'));
                            $jExt['flexo_status_before_waiting_slitting'] = ($curStatus !== '') ? $curStatus : 'Running';
                        }
                        $jExtJson = json_encode($jExt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $updExt = $db->prepare("UPDATE jobs SET extra_data = ? WHERE id = ?");
                        $updExt->bind_param('si', $jExtJson, $jobId);
                        $updExt->execute();
                    }
                }
            }

            echo json_encode(['ok' => true, 'request_id' => $rid]);
            break;

        // ─── List flexo operator requests ──────────────────────
        case 'list_flexo_operator_requests':
        jobs_ensure_change_request_table($db);

        $status = trim((string)($_GET['status'] ?? 'all'));
        $jobId = (int)($_GET['job_id'] ?? 0);
        $limit = min(300, max(1, (int)($_GET['limit'] ?? 50)));

        $where = ["r.request_type = 'flexo_operator_request'"];
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

    // ─── List compatible stock rolls for a flexo request ───────────
    case 'get_flexo_request_stock_candidates':
        if (!hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only manager/admin can access stock candidates']);
            break;
        }
        jobs_ensure_change_request_table($db);

        $requestId = (int)($_GET['request_id'] ?? 0);
        if ($requestId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request_id']);
            break;
        }

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND request_type = 'flexo_operator_request' LIMIT 1");
        $reqStmt->bind_param('i', $requestId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        if (!$req) {
            echo json_encode(['ok' => false, 'error' => 'Flexo request not found']);
            break;
        }

        $payload = json_decode((string)($req['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];
        $additional = is_array($payload['additional_roll_request'] ?? null) ? $payload['additional_roll_request'] : [];
        $enabled = !empty($additional['enabled']);
        $reqWidth = jobs_float2($additional['width_mm'] ?? 0);
        $reqLength = jobs_float2($additional['length_mtr'] ?? 0);

        if (!$enabled || $reqWidth <= 0 || $reqLength <= 0) {
            echo json_encode([
                'ok' => true,
                'request_id' => $requestId,
                'request_spec' => ['width_mm' => $reqWidth, 'length_mtr' => $reqLength, 'enabled' => false],
                'candidates' => [],
                'message' => 'No additional roll requirement found in this request'
            ]);
            break;
        }

        $candStmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, gsm, status, lot_batch_no FROM paper_stock WHERE ABS(width_mm - ?) <= 0.01 AND length_mtr >= ? AND LOWER(COALESCE(status,'')) IN ('stock','main','available') ORDER BY length_mtr ASC, id ASC LIMIT 200");
        $candStmt->bind_param('dd', $reqWidth, $reqLength);
        $candStmt->execute();
        $candRows = $candStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $out = [];
        foreach ($candRows as $row) {
            $len = jobs_float2($row['length_mtr'] ?? 0);
            $remain = jobs_float2(max(0, $len - $reqLength));
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'roll_no' => (string)($row['roll_no'] ?? ''),
                'paper_type' => (string)($row['paper_type'] ?? ''),
                'company' => (string)($row['company'] ?? ''),
                'width_mm' => jobs_float2($row['width_mm'] ?? 0),
                'length_mtr' => $len,
                'gsm' => jobs_float2($row['gsm'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'lot_batch_no' => (string)($row['lot_batch_no'] ?? ''),
                'will_split' => $len > ($reqLength + 0.01),
                'remaining_after_issue' => $remain,
            ];
        }

        echo json_encode([
            'ok' => true,
            'request_id' => $requestId,
            'request_status' => (string)($req['status'] ?? ''),
            'request_spec' => ['width_mm' => $reqWidth, 'length_mtr' => $reqLength, 'enabled' => true],
            'candidates' => $out,
            'slitting_redirect_url' => jobs_build_flexo_slitting_redirect_url(['id' => (int)($req['job_id'] ?? 0)], $requestId, -1, $reqWidth, $reqLength),
        ]);
        break;

    // ─── Manager production update for flexo request ────────────────
    case 'approve_flexo_request_production_update':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        if (!hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only manager/admin can run production update']);
            break;
        }

        jobs_ensure_change_request_table($db);
        $requestId = (int)($_POST['request_id'] ?? 0);
        $mode = strtolower(trim((string)($_POST['mode'] ?? 'stock')));
        $selectedRollNo = trim((string)($_POST['selected_roll_no'] ?? ''));
        $reviewNote = trim((string)($_POST['review_note'] ?? ''));

        if ($requestId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid request_id']);
            break;
        }
        if (!in_array($mode, ['stock', 'slitting'], true)) {
            $mode = 'stock';
        }

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND request_type = 'flexo_operator_request' LIMIT 1");
        $reqStmt->bind_param('i', $requestId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        if (!$req) {
            echo json_encode(['ok' => false, 'error' => 'Flexo request not found']);
            break;
        }
        if ((string)($req['status'] ?? '') !== 'Pending') {
            echo json_encode(['ok' => false, 'error' => 'Request already reviewed']);
            break;
        }

        $jobId = (int)($req['job_id'] ?? 0);
        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Flexo job not found']);
            break;
        }

        $payload = json_decode((string)($req['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];

        $additional = is_array($payload['additional_roll_request'] ?? null) ? $payload['additional_roll_request'] : [];
        $excess = is_array($payload['excess_roll_adjustment'] ?? null) ? $payload['excess_roll_adjustment'] : [];
        $hasAdd = !empty($additional['enabled']);
        $hasRemaining = !empty($excess['enabled']);
        if ($hasAdd && !$hasRemaining && $mode !== 'slitting') {
            echo json_encode(['ok' => false, 'error' => 'ADD ROLL request must be processed via slitting only']);
            break;
        }
        if ($hasRemaining && !$hasAdd && $mode !== 'stock') {
            echo json_encode(['ok' => false, 'error' => 'REMAINING ROLL request must be processed via direct update only']);
            break;
        }

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Manager')));
        if ($reviewedByName === '') $reviewedByName = 'Manager';

        $applyRes = jobs_apply_flexo_operator_request($db, $job, $payload, $reviewedBy, $reviewedByName, [
            'request_id' => $requestId,
            'selected_roll_no' => $selectedRollNo,
            'force_slitting' => ($mode === 'slitting'),
        ]);
        if (!($applyRes['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => 'Production update failed: ' . ($applyRes['error'] ?? 'Unknown')]);
            break;
        }

        $applySummary = is_array($applyRes['summary'] ?? null) ? $applyRes['summary'] : [];
        $additionalReq = is_array($payload['additional_roll_request'] ?? null) ? $payload['additional_roll_request'] : [];
        $reqWidth = jobs_float2($additionalReq['width_mm'] ?? 0);
        $reqLength = jobs_float2($additionalReq['length_mtr'] ?? 0);
        $remainingAutoCompleted = false;

        // Remaining-roll production update: auto-complete the flexo job immediately.
        if ($hasRemaining && !$hasAdd && $mode === 'stock') {
            $jobRefreshStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            $jobRefreshStmt->bind_param('i', $jobId);
            $jobRefreshStmt->execute();
            $jobFresh = $jobRefreshStmt->get_result()->fetch_assoc();

            if ($jobFresh) {
                $currStatus = trim((string)($jobFresh['status'] ?? ''));
                $isAlreadyComplete = in_array($currStatus, ['Completed', 'QC Passed', 'Closed', 'Finalized'], true);
                if (!$isAlreadyComplete) {
                    $jobExtraComplete = jobs_decode_extra_data($jobFresh['extra_data'] ?? '{}');
                    $nowComplete = jobs_timer_now();
                    $accComplete = jobs_timer_accumulated_seconds($jobExtraComplete);

                    if (!empty($jobExtraComplete['timer_active']) && !empty($jobExtraComplete['timer_last_resumed_at'])) {
                        $accComplete += jobs_timer_seconds_between((string)$jobExtraComplete['timer_last_resumed_at'], $nowComplete);
                        $jobExtraComplete = jobs_timer_close_work_segment($jobExtraComplete, $nowComplete);
                    }
                    if (strtolower(trim((string)($jobExtraComplete['timer_state'] ?? ''))) === 'paused') {
                        $jobExtraComplete = jobs_timer_close_pause_segment($jobExtraComplete, $nowComplete);
                    }

                    $jobExtraComplete['timer_accumulated_seconds'] = max(0, $accComplete);
                    $jobExtraComplete['timer_active'] = false;
                    $jobExtraComplete['timer_state'] = 'completed';
                    $jobExtraComplete['timer_last_resumed_at'] = '';
                    $jobExtraComplete['timer_paused_at'] = '';
                    $jobExtraComplete['timer_pause_started_at'] = '';
                    $jobExtraComplete['timer_ended_at'] = $nowComplete;
                    $jobExtraComplete = jobs_timer_push_event($jobExtraComplete, 'complete', $nowComplete);

                    $durationMinutesComplete = (int)floor($accComplete / 60);
                    $safeExtraCompleteJson = json_encode($jobExtraComplete, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $statusCompleted = 'Completed';
                    $updComplete = $db->prepare("UPDATE jobs SET status = ?, completed_at = NOW(), duration_minutes = ?, extra_data = ? WHERE id = ?");
                    $updComplete->bind_param('sisi', $statusCompleted, $durationMinutesComplete, $safeExtraCompleteJson, $jobId);
                    $updComplete->execute();

                    $completeStage = jobs_stage_status_for_event($jobFresh, 'complete');
                    if ($completeStage !== '') {
                        jobs_set_planning_status_and_stage_for_job($db, $jobFresh, $completeStage);
                    }

                    $remainingAutoCompleted = true;
                }
            }
        }

        $payload['applied_context'] = [
            'applied_by' => $reviewedByName,
            'applied_by_id' => $reviewedBy,
            'applied_at_iso' => date('c'),
            'review_note' => $reviewNote,
            'summary' => $applySummary,
            'before_state' => $applyRes['before_state'] ?? [],
            'after_state' => $applyRes['after_state'] ?? [],
            'production_update_mode' => $mode,
            'selected_roll_no' => $selectedRollNo,
        ];

        $decision = 'Approved';
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updReq = $db->prepare("UPDATE job_change_requests SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ?, payload_json = ? WHERE id = ?");
        $updReq->bind_param('sisssi', $decision, $reviewedBy, $reviewedByName, $reviewNote, $payloadJson, $requestId);
        $updReq->execute();

        $notifDept = 'flexo_printing';
        $notifMsg = 'Flexo request #' . $requestId . ' for ' . ((string)($job['job_no'] ?? ('JOB-' . $jobId))) . ' approved via production update';
        $ntype = 'success';
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
        $nIns->bind_param('isss', $jobId, $notifDept, $notifMsg, $ntype);
        $nIns->execute();

        $redirectUrl = '';
        $labelPrintUrl = '';
        if (!empty($applySummary['additional_slitting_task_created'])) {
            $msg2 = 'Additional slitting task tagged under same job card: ' . ((string)($job['job_no'] ?? ('JOB-' . $jobId)));
            $dept2 = 'jumbo_slitting';
            $type2 = 'warning';
            $nIns2 = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
            $nIns2->bind_param('isss', $jobId, $dept2, $msg2, $type2);
            $nIns2->execute();
            $redirectUrl = jobs_build_flexo_slitting_redirect_url(
                $job,
                $requestId,
                (int)($applySummary['slitting_task_index'] ?? -1),
                $reqWidth,
                $reqLength
            );
        } elseif ($hasRemaining && !$hasAdd && (int)($applySummary['remaining_stock_roll_id'] ?? 0) > 0) {
            $labelPrintUrl = BASE_URL . '/modules/paper_stock/label.php?ids=' . (int)$applySummary['remaining_stock_roll_id'];
        }

        echo json_encode([
            'ok' => true,
            'request_id' => $requestId,
            'decision' => $decision,
            'apply_summary' => $applySummary,
            'auto_completed' => $remainingAutoCompleted,
            'label_print_url' => $labelPrintUrl,
            'slitting_redirect_url' => $redirectUrl,
        ]);
        break;

    // ─── Review flexo operator request ─────────────────────
    case 'review_flexo_operator_request':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        if (!hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only manager/admin can review flexo requests']);
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

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND request_type = 'flexo_operator_request' LIMIT 1");
        $reqStmt->bind_param('i', $requestId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        if (!$req) {
            echo json_encode(['ok' => false, 'error' => 'Flexo request not found']);
            break;
        }
        if ((string)($req['status'] ?? '') !== 'Pending') {
            echo json_encode(['ok' => false, 'error' => 'Request already reviewed']);
            break;
        }

        $jobId = (int)($req['job_id'] ?? 0);
        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Flexo job not found']);
            break;
        }

        $payload = json_decode((string)($req['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Manager')));
        if ($reviewedByName === '') $reviewedByName = 'Manager';

        $applySummary = [];
        if ($decision === 'Approved') {
            $applyRes = jobs_apply_flexo_operator_request($db, $job, $payload, $reviewedBy, $reviewedByName);
            if (!($applyRes['ok'] ?? false)) {
                echo json_encode(['ok' => false, 'error' => 'Approval apply failed: ' . ($applyRes['error'] ?? 'Unknown')]);
                break;
            }
            $applySummary = is_array($applyRes['summary'] ?? null) ? $applyRes['summary'] : [];
            $payload['applied_context'] = [
                'applied_by' => $reviewedByName,
                'applied_by_id' => $reviewedBy,
                'applied_at_iso' => date('c'),
                'review_note' => $reviewNote,
                'summary' => $applySummary,
                'before_state' => $applyRes['before_state'] ?? [],
                'after_state' => $applyRes['after_state'] ?? [],
            ];
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updReq = $db->prepare("UPDATE job_change_requests SET status = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ?, payload_json = ? WHERE id = ?");
        $updReq->bind_param('sisssi', $decision, $reviewedBy, $reviewedByName, $reviewNote, $payloadJson, $requestId);
        $updReq->execute();

        $notifDept = 'flexo_printing';
        $notifMsg = 'Flexo request #' . $requestId . ' for ' . ((string)($job['job_no'] ?? ('JOB-' . $jobId))) . ' ' . strtolower($decision);
        $ntype = $decision === 'Approved' ? 'success' : 'error';
        $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
        $nIns->bind_param('isss', $jobId, $notifDept, $notifMsg, $ntype);
        $nIns->execute();

        if ($decision === 'Approved' && !empty($applySummary['additional_slitting_task_created'])) {
            $msg2 = 'Additional slitting task tagged under same job card: ' . ((string)($job['job_no'] ?? ('JOB-' . $jobId)));
            $dept2 = 'jumbo_slitting';
            $type2 = 'warning';
            $nIns2 = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
            $nIns2->bind_param('isss', $jobId, $dept2, $msg2, $type2);
            $nIns2->execute();
        }

        echo json_encode(['ok' => true, 'request_id' => $requestId, 'decision' => $decision, 'apply_summary' => $applySummary]);
        break;

    // ─── Complete flexo slitting task from slitting batch ───────────
    case 'complete_flexo_slitting_from_batch':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        if (!hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only manager/admin can complete flexo slitting from batch']);
            break;
        }

        jobs_ensure_change_request_table($db);
        $jobId = (int)($_POST['job_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        $taskIndex = (int)($_POST['task_index'] ?? -1);
        $issuedRollNo = trim((string)($_POST['issued_roll_no'] ?? ''));
        $completionNote = trim((string)($_POST['completion_note'] ?? ''));
        $targetDepartment = strtolower(trim((string)($_POST['target_department'] ?? '')));
        $routeViaJumbo = strpos($targetDepartment, 'jumbo') !== false;
        $routeViaPrinting = !$routeViaJumbo;

        if ($jobId <= 0 || $requestId <= 0 || $issuedRollNo === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing job/request/task/issued roll information']);
            break;
        }

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Flexo job not found']);
            break;
        }

        $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
        $dept = strtolower(trim((string)($job['department'] ?? '')));
        if (!($jobType === 'printing' || $dept === 'flexo_printing')) {
            echo json_encode(['ok' => false, 'error' => 'Only flexo printing jobs are supported']);
            break;
        }

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND request_type = 'flexo_operator_request' AND job_id = ? LIMIT 1");
        $reqStmt->bind_param('ii', $requestId, $jobId);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();
        if (!$req) {
            echo json_encode(['ok' => false, 'error' => 'Related flexo request not found']);
            break;
        }

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Manager')));
        if ($reviewedByName === '') $reviewedByName = 'Manager';

        $db->begin_transaction();
        try {
            $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
            if (!is_array($extra)) $extra = [];
            $tasks = is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [];
            if ($taskIndex < 0) {
                foreach ($tasks as $idx => $taskRow) {
                    if (!is_array($taskRow)) continue;
                    $tType = strtolower(trim((string)($taskRow['type'] ?? '')));
                    $tStatus = strtolower(trim((string)($taskRow['status'] ?? 'pending')));
                    $tReqId = (int)($taskRow['request_id'] ?? 0);
                    if ($tType === 'additional_slitting' && $tStatus === 'pending' && ($tReqId <= 0 || $tReqId === $requestId)) {
                        $taskIndex = (int)$idx;
                        break;
                    }
                }
            }
                if ($taskIndex < 0 || !isset($tasks[$taskIndex]) || !is_array($tasks[$taskIndex])) {
                    // Safety net: create task JIT from request payload if not pre-created at submit time
                    $reqPayloadJit = json_decode((string)($req['payload_json'] ?? '{}'), true);
                    $addRollJit = is_array($reqPayloadJit) && is_array($reqPayloadJit['additional_roll_request'] ?? null)
                        ? $reqPayloadJit['additional_roll_request'] : [];
                    if (!empty($addRollJit['enabled'])) {
                        $jitWidth  = jobs_float2($addRollJit['width_mm'] ?? 0);
                        $jitLength = jobs_float2($addRollJit['length_mtr'] ?? 0);
                        $jitReason = trim((string)($addRollJit['reason'] ?? ''));
                        if ($jitWidth > 0 && $jitLength > 0) {
                            $tasks[] = [
                                'type'          => 'additional_slitting',
                                'tag'           => 'Additional Slitting',
                                'status'        => 'Pending',
                                'job_id'        => $jobId,
                                'job_no'        => (string)($job['job_no'] ?? ''),
                                'request_id'    => $requestId,
                                'width_mm'      => $jitWidth,
                                'length_mtr'    => $jitLength,
                                'reason'        => $jitReason,
                                'created_by'    => $reviewedByName,
                                'created_by_id' => $reviewedBy,
                                'created_at'    => date('c'),
                                'source'        => 'jit_from_batch',
                            ];
                            $taskIndex = count($tasks) - 1;
                            $extra['flexo_extension_tasks'] = $tasks;
                        }
                    }
                    // Re-check after JIT attempt — only throw if still not found
                    if ($taskIndex < 0 || !isset($tasks[$taskIndex]) || !is_array($tasks[$taskIndex])) {
                        throw new Exception('Pending additional slitting task not found for request');
                    }
                }
            $task = $tasks[$taskIndex];
            $taskType = strtolower(trim((string)($task['type'] ?? '')));
            $taskStatus = strtolower(trim((string)($task['status'] ?? 'pending')));
            if ($taskType !== 'additional_slitting') {
                throw new Exception('Task is not additional slitting');
            }
            if ($taskStatus === 'completed') {
                $db->commit();
                echo json_encode(['ok' => true, 'issued_roll_no' => (string)($task['issued_roll_no'] ?? $issuedRollNo), 'task_index' => $taskIndex, 'idempotent' => true]);
                break;
            }
            if ($taskStatus !== 'pending') {
                throw new Exception('Task is not pending additional slitting');
            }

            $rollStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1 FOR UPDATE");
            $rollStmt->bind_param('s', $issuedRollNo);
            $rollStmt->execute();
            $issuedRow = $rollStmt->get_result()->fetch_assoc();
            if (!$issuedRow) {
                throw new Exception('Issued roll not found in stock: ' . $issuedRollNo);
            }

            $jobNo = (string)($job['job_no'] ?? '');
            $jobName = jobs_display_job_name($job);

            $oldJumbo = null;
            $prevJumboId = (int)($job['previous_job_id'] ?? 0);
            if ($prevJumboId > 0) {
                $jumboStmt = $db->prepare("SELECT id, job_no, roll_no, planning_id, department, job_type, extra_data FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
                $jumboStmt->bind_param('i', $prevJumboId);
                $jumboStmt->execute();
                $oldJumbo = $jumboStmt->get_result()->fetch_assoc();
            }

            $tempExtraJumboCard = null;
            if ($routeViaJumbo && $oldJumbo) {
                $tempExtraJumboCard = jobs_create_or_get_flexo_extra_jumbo_card($db, $oldJumbo, $requestId, $jobId, $reviewedByName);
            }

            if ($routeViaJumbo && $tempExtraJumboCard) {
                $tempJobNo = (string)($tempExtraJumboCard['job_no'] ?? '');
                $tempJobName = $tempJobNo !== '' ? ($tempJobNo . ' (Jumbo Extra)') : 'Jumbo Extra';
                $updIssued = $db->prepare("UPDATE paper_stock SET status = 'Slitting', job_no = ?, job_name = ?, date_used = CURDATE() WHERE roll_no = ?");
                $updIssued->bind_param('sss', $tempJobNo, $tempJobName, $issuedRollNo);
                $updIssued->execute();
            } else {
                $updIssued = $db->prepare("UPDATE paper_stock SET status = 'Job Assign', job_no = ?, job_name = ?, date_used = CURDATE() WHERE roll_no = ?");
                $updIssued->bind_param('sss', $jobNo, $jobName, $issuedRollNo);
                $updIssued->execute();
            }

            $assigned = is_array($extra['assigned_child_rolls'] ?? null) ? $extra['assigned_child_rolls'] : [];
            $assigned = jobs_merge_roll_item($assigned, [
                'roll_no' => $issuedRollNo,
                'parent_roll_no' => (string)($issuedRow['parent_roll_no'] ?? ''),
                'width_mm' => jobs_float2($issuedRow['width_mm'] ?? 0),
                'length_mtr' => jobs_float2($issuedRow['length_mtr'] ?? 0),
                'paper_type' => (string)($issuedRow['paper_type'] ?? ''),
                'company' => (string)($issuedRow['company'] ?? ''),
                'gsm' => jobs_float2($issuedRow['gsm'] ?? 0),
                'status' => 'Job Assign',
                'job_no' => $jobNo,
                'job_name' => $jobName,
                'task_ref' => 'additional_slitting:' . $taskIndex,
            ]);
            $extra['assigned_child_rolls'] = array_values($assigned);

            if ($routeViaJumbo && $tempExtraJumboCard) {
                $tempExtra = is_array($tempExtraJumboCard['extra_data'] ?? null) ? $tempExtraJumboCard['extra_data'] : [];
                $tempChild = is_array($tempExtra['child_rolls'] ?? null) ? $tempExtra['child_rolls'] : [];
                $tempChild = array_values(array_filter($tempChild, function($row) use ($requestId) {
                    if (!is_array($row)) return false;
                    if (empty($row['from_flexo_request'])) return false;
                    $route = strtolower(trim((string)($row['extra_route'] ?? '')));
                    if ($route !== '' && $route !== 'jumbo') return false;
                    $rId = (int)($row['flexo_request_id'] ?? 0);
                    if ($rId > 0 && $requestId > 0 && $rId !== $requestId) return false;
                    return true;
                }));
                $tempChild = jobs_merge_roll_item($tempChild, [
                    'roll_no' => $issuedRollNo,
                    'parent_roll_no' => (string)($issuedRow['parent_roll_no'] ?? ''),
                    'width' => jobs_float2($issuedRow['width_mm'] ?? 0),
                    'length' => jobs_float2($issuedRow['length_mtr'] ?? 0),
                    'status' => 'Extra',
                    'remarks' => 'Extra from flexo request #' . $requestId,
                    'from_flexo_request' => true,
                    'flexo_request_id' => $requestId,
                    'extra_route' => 'jumbo',
                ]);
                $tempExtra['child_rolls'] = array_values($tempChild);
                $tempExtra['total_roll_count'] = count($tempExtra['child_rolls']) + count(is_array($tempExtra['stock_rolls'] ?? null) ? $tempExtra['stock_rolls'] : []) + 1;
                $tempExtra['last_extra_roll_update_at'] = date('c');
                $tempExtra['last_extra_roll_update_roll_no'] = $issuedRollNo;
                $tempExtra['flexo_request_id'] = $requestId;
                $tempExtra['flexo_job_id'] = $jobId;
                $tempExtra['merge_required'] = true;
                $tempExtraJson = json_encode($tempExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $tempId = (int)($tempExtraJumboCard['id'] ?? 0);
                $updTemp = $db->prepare("UPDATE jobs SET status = 'Pending', roll_no = ?, extra_data = ? WHERE id = ?");
                $updTemp->bind_param('ssi', $issuedRollNo, $tempExtraJson, $tempId);
                $updTemp->execute();
            }

            $tasks[$taskIndex]['status'] = 'Completed';
            $tasks[$taskIndex]['completed_at'] = date('c');
            $tasks[$taskIndex]['completed_by'] = $reviewedByName;
            $tasks[$taskIndex]['completed_by_id'] = $reviewedBy;
            $tasks[$taskIndex]['issued_roll_no'] = $issuedRollNo;
            $tasks[$taskIndex]['completion_note'] = $completionNote;
            $tasks[$taskIndex]['completed_via'] = 'slitting_batch';
            $tasks[$taskIndex]['target_department'] = $targetDepartment;
            $tasks[$taskIndex]['requires_jumbo_end'] = $routeViaJumbo;
            $extra['flexo_extension_tasks'] = array_values($tasks);

            $pendingAdditionalTasks = 0;
            foreach ($extra['flexo_extension_tasks'] as $taskRow) {
                if (!is_array($taskRow)) continue;
                $tType = strtolower(trim((string)($taskRow['type'] ?? '')));
                $tStatus = strtolower(trim((string)($taskRow['status'] ?? '')));
                if ($tType === 'additional_slitting' && $tStatus === 'pending') {
                    $pendingAdditionalTasks++;
                }
            }

            $pendingTasksForRequest = 0;
            foreach ($extra['flexo_extension_tasks'] as $taskRow) {
                if (!is_array($taskRow)) continue;
                $tType = strtolower(trim((string)($taskRow['type'] ?? '')));
                $tStatus = strtolower(trim((string)($taskRow['status'] ?? '')));
                $tReqId = (int)($taskRow['request_id'] ?? 0);
                if ($tType === 'additional_slitting' && $tStatus === 'pending' && $tReqId === $requestId) {
                    $pendingTasksForRequest++;
                }
            }

            $jobStatusToSet = 'Pending';
            if ($pendingAdditionalTasks <= 0 && !$routeViaJumbo) {
                $restoreStatus = trim((string)($extra['flexo_status_before_waiting_slitting'] ?? 'Running'));
                if ($routeViaPrinting) {
                    $jobStatusToSet = 'Running';
                } else {
                    $jobStatusToSet = $restoreStatus === '' ? 'Running' : $restoreStatus;
                }
                unset($extra['flexo_status_before_waiting_slitting']);
                unset($extra['flexo_waiting_additional_slitting']);
                unset($extra['flexo_waiting_additional_slitting_since']);
                unset($extra['flexo_waiting_additional_slitting_reason']);
                unset($extra['flexo_waiting_requires_jumbo_end']);
            } else {
                $extra['flexo_waiting_additional_slitting'] = true;
                if ($routeViaJumbo) {
                    $extra['flexo_waiting_additional_slitting_reason'] = 'Waiting for jumbo EXTRA card completion';
                    $extra['flexo_waiting_requires_jumbo_end'] = true;
                } else {
                    unset($extra['flexo_waiting_requires_jumbo_end']);
                }
            }

            $requestStatus = (string)($req['status'] ?? 'Pending');
            if ($routeViaPrinting && $pendingTasksForRequest <= 0) {
                $requestStatus = 'Approved';
                $reqPayload = json_decode((string)($req['payload_json'] ?? '{}'), true);
                if (!is_array($reqPayload)) $reqPayload = [];
                $reqPayload['applied_context'] = [
                    'applied_by' => $reviewedByName,
                    'applied_by_id' => $reviewedBy,
                    'applied_at_iso' => date('c'),
                    'review_note' => $completionNote,
                    'production_update_mode' => 'slitting',
                    'issued_roll_no' => $issuedRollNo,
                    'target_department' => $targetDepartment,
                    'completed_via' => 'slitting_batch',
                ];
                $reqPayloadJson = json_encode($reqPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $updReq = $db->prepare("UPDATE job_change_requests SET status = 'Approved', reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ?, payload_json = ? WHERE id = ?");
                $updReq->bind_param('isssi', $reviewedBy, $reviewedByName, $completionNote, $reqPayloadJson, $requestId);
                $updReq->execute();
            }

            $extra['flexo_request_last_applied_at'] = date('c');
            $extra['flexo_request_last_applied_by'] = $reviewedByName;
            $extra['flexo_request_last_applied_by_id'] = $reviewedBy;

            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $updJob = $db->prepare("UPDATE jobs SET status = ?, extra_data = ? WHERE id = ?");
            $updJob->bind_param('ssi', $jobStatusToSet, $extraJson, $jobId);
            $updJob->execute();

            $notifMsg = 'Flexo additional roll synced to printing for ' . ($jobNo !== '' ? $jobNo : ('JOB-' . $jobId)) . ' | Roll: ' . $issuedRollNo;
            $notifDept = 'flexo_printing';
            $notifType = 'success';
            $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
            $nIns->bind_param('isss', $jobId, $notifDept, $notifMsg, $notifType);
            $nIns->execute();

            $db->commit();
            $redirectUrl = '';
            if ($routeViaPrinting) {
                $redirectUrl = jobs_join_base_url('/modules/jobs/printing/index.php')
                    . '?auto_job=' . rawurlencode((string)$jobId)
                    . '&flexo_slitting_done=1&issued_roll_no=' . rawurlencode($issuedRollNo);
            }
            echo json_encode([
                'ok' => true,
                'issued_roll_no' => $issuedRollNo,
                'task_index' => $taskIndex,
                'request_status' => $requestStatus,
                'redirect_url' => $redirectUrl,
                'message' => $routeViaPrinting
                    ? 'Additional roll added to the same Flexo job and ready for printing.'
                    : 'Additional slitting synced from batch.',
            ]);
        } catch (Throwable $th) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => $th->getMessage()]);
        }
        break;

    // ─── Complete flexo additional slitting task ───────────
    case 'complete_flexo_additional_slitting_task':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        if (!hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only manager/admin can complete additional slitting task']);
            break;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $taskIndex = (int)($_POST['task_index'] ?? -1);
        $sourceRollNo = trim((string)($_POST['source_roll_no'] ?? ''));
        $completionNote = trim((string)($_POST['completion_note'] ?? ''));

        if ($jobId <= 0 || $taskIndex < 0 || $sourceRollNo === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing job/task/roll information']);
            break;
        }

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Flexo job not found']);
            break;
        }

        $jobType = strtolower(trim((string)($job['job_type'] ?? '')));
        $dept = strtolower(trim((string)($job['department'] ?? '')));
        $isFlexo = $jobType === 'printing' || $dept === 'flexo_printing';
        if (!$isFlexo) {
            echo json_encode(['ok' => false, 'error' => 'Only flexo printing jobs are supported']);
            break;
        }

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Manager')));
        if ($reviewedByName === '') $reviewedByName = 'Manager';

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        $tasks = is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [];
        if (!isset($tasks[$taskIndex]) || !is_array($tasks[$taskIndex])) {
            echo json_encode(['ok' => false, 'error' => 'Task not found']);
            break;
        }

        $task = $tasks[$taskIndex];
        $taskType = strtolower(trim((string)($task['type'] ?? '')));
        $taskStatus = strtolower(trim((string)($task['status'] ?? 'pending')));
        if ($taskType !== 'additional_slitting' || $taskStatus !== 'pending') {
            echo json_encode(['ok' => false, 'error' => 'Task is not pending additional slitting']);
            break;
        }

        $reqWidth = jobs_float2($task['width_mm'] ?? 0);
        $reqLength = jobs_float2($task['length_mtr'] ?? 0);
        if ($reqWidth <= 0 || $reqLength <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Task dimensions are invalid']);
            break;
        }

        $db->begin_transaction();
        try {
            $srcStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1 FOR UPDATE");
            $srcStmt->bind_param('s', $sourceRollNo);
            $srcStmt->execute();
            $src = $srcStmt->get_result()->fetch_assoc();
            if (!$src) {
                throw new Exception('Source roll not found: ' . $sourceRollNo);
            }

            $srcStatusRaw = strtolower(trim((string)($src['status'] ?? '')));
            if (!in_array($srcStatusRaw, ['stock', 'main', 'available'], true)) {
                throw new Exception('Selected roll is not available in stock');
            }

            $srcWidth = jobs_float2($src['width_mm'] ?? 0);
            $srcLength = jobs_float2($src['length_mtr'] ?? 0);
            if (abs($srcWidth - $reqWidth) > 0.01) {
                throw new Exception('Selected roll width does not match task width');
            }
            if ($srcLength + 0.01 < $reqLength) {
                throw new Exception('Selected roll length is less than required length');
            }

            $issuedRollNo = $sourceRollNo;
            $issuedLength = $reqLength;
            $issuedSqm = jobs_calc_sqm($reqWidth, $issuedLength);
            $jobNo = (string)($job['job_no'] ?? '');
            $jobName = jobs_display_job_name($job);
            $emptyText = '';

            if ($srcLength > ($reqLength + 0.01)) {
                $issuedRollNo = jobs_next_numeric_roll_no($db, $sourceRollNo);
                $remainLen = jobs_float2($srcLength - $reqLength);
                $remainSqm = jobs_calc_sqm($reqWidth, $remainLen);
                $remainStatus = 'Stock';
                $updSrc = $db->prepare("UPDATE paper_stock SET length_mtr = ?, sqm = ?, status = ? WHERE id = ?");
                $srcId = (int)($src['id'] ?? 0);
                $updSrc->bind_param('ddsi', $remainLen, $remainSqm, $remainStatus, $srcId);
                $updSrc->execute();

                $jobStatus = 'Job Assign';
                $insIssue = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate, sqm, lot_batch_no, parent_roll_no, source_batch_id, source_allocation_id, status, job_no, job_name, job_size, date_received, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, CURDATE(), ?)");
                $insIssue->bind_param(
                    'sssddddddssssssi',
                    $issuedRollNo,
                    $src['paper_type'],
                    $src['company'],
                    $reqWidth,
                    $issuedLength,
                    $src['gsm'],
                    $src['weight_kg'],
                    $src['purchase_rate'],
                    $issuedSqm,
                    $src['lot_batch_no'],
                    $sourceRollNo,
                    $jobStatus,
                    $jobNo,
                    $jobName,
                    $emptyText,
                    $reviewedBy
                );
                $insIssue->execute();
                $issuedStockId = (int)$db->insert_id;
                jobs_insert_inventory_log($db, $issuedRollNo, $issuedStockId, 'Flexo additional slitting task completed from source ' . $sourceRollNo, $reviewedBy);
            } else {
                $jobStatus = 'Job Assign';
                $updWhole = $db->prepare("UPDATE paper_stock SET status = ?, job_no = ?, job_name = ?, date_used = CURDATE() WHERE id = ?");
                $srcId = (int)($src['id'] ?? 0);
                $updWhole->bind_param('sssi', $jobStatus, $jobNo, $jobName, $srcId);
                $updWhole->execute();
                jobs_insert_inventory_log($db, $issuedRollNo, (int)($src['id'] ?? 0), 'Flexo additional slitting task completed by direct issue', $reviewedBy);
            }

            $assigned = is_array($extra['assigned_child_rolls'] ?? null) ? $extra['assigned_child_rolls'] : [];
            $assigned = jobs_merge_roll_item($assigned, [
                'roll_no' => $issuedRollNo,
                'parent_roll_no' => (string)($src['parent_roll_no'] ?? ''),
                'width_mm' => $reqWidth,
                'length_mtr' => $issuedLength,
                'paper_type' => (string)($src['paper_type'] ?? ''),
                'company' => (string)($src['company'] ?? ''),
                'gsm' => jobs_float2($src['gsm'] ?? 0),
                'status' => 'Job Assign',
                'job_no' => $jobNo,
                'job_name' => $jobName,
                'task_ref' => 'additional_slitting:' . $taskIndex,
            ]);
            $extra['assigned_child_rolls'] = array_values($assigned);

            $tasks[$taskIndex]['status'] = 'Completed';
            $tasks[$taskIndex]['completed_at'] = date('c');
            $tasks[$taskIndex]['completed_by'] = $reviewedByName;
            $tasks[$taskIndex]['completed_by_id'] = $reviewedBy;
            $tasks[$taskIndex]['issued_roll_no'] = $issuedRollNo;
            $tasks[$taskIndex]['source_roll_no'] = $sourceRollNo;
            $tasks[$taskIndex]['completion_note'] = $completionNote;
            $extra['flexo_extension_tasks'] = array_values($tasks);
            $extra['flexo_request_last_applied_at'] = date('c');
            $extra['flexo_request_last_applied_by'] = $reviewedByName;
            $extra['flexo_request_last_applied_by_id'] = $reviewedBy;

            $pendingAdditionalTasks = 0;
            foreach ($extra['flexo_extension_tasks'] as $taskRow) {
                if (!is_array($taskRow)) continue;
                $tType = strtolower(trim((string)($taskRow['type'] ?? '')));
                $tStatus = strtolower(trim((string)($taskRow['status'] ?? '')));
                if ($tType === 'additional_slitting' && $tStatus === 'pending') {
                    $pendingAdditionalTasks++;
                }
            }

            $jobStatusToSet = 'Pending';
            if ($pendingAdditionalTasks <= 0) {
                $restoreStatus = trim((string)($extra['flexo_status_before_waiting_slitting'] ?? 'Running'));
                $jobStatusToSet = $restoreStatus === '' ? 'Running' : $restoreStatus;
                unset($extra['flexo_status_before_waiting_slitting']);
                unset($extra['flexo_waiting_additional_slitting']);
                unset($extra['flexo_waiting_additional_slitting_since']);
                unset($extra['flexo_waiting_additional_slitting_reason']);
            } else {
                $extra['flexo_waiting_additional_slitting'] = true;
            }

            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $updJob = $db->prepare("UPDATE jobs SET status = ?, extra_data = ? WHERE id = ?");
            $updJob->bind_param('ssi', $jobStatusToSet, $extraJson, $jobId);
            $updJob->execute();

            $notifMsg = 'Additional slitting task completed for ' . ($jobNo !== '' ? $jobNo : ('JOB-' . $jobId)) . ' | Roll: ' . $issuedRollNo;
            $notifDept = 'flexo_printing';
            $notifType = 'success';
            $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
            $nIns->bind_param('isss', $jobId, $notifDept, $notifMsg, $notifType);
            $nIns->execute();

            $db->commit();
            echo json_encode(['ok' => true, 'issued_roll_no' => $issuedRollNo, 'task_index' => $taskIndex]);
        } catch (Throwable $th) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => $th->getMessage()]);
        }
        break;

    // ─── Manager update: apply suggested roll without delete/recreate ───
    case 'apply_jumbo_manager_roll_update':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }
        $sessionRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $isManagerish = in_array($sessionRole, ['admin', 'manager', 'system_admin', 'super_admin', 'systemadmin', 'superadmin'], true);
        if (!$isManagerish && !hasRole('admin', 'manager', 'system_admin', 'super_admin')) {
            echo json_encode(['ok' => false, 'error' => 'Only admin/manager can apply manager update']);
            break;
        }

        jobs_ensure_change_request_table($db);
        jobs_ensure_delete_audit_table($db);

        $jobId = (int)($_POST['job_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        $oldParentRoll = trim((string)($_POST['old_parent_roll_no'] ?? ''));
        $postedNewParentRoll = trim((string)($_POST['new_parent_roll_no'] ?? ''));
        $oldParentPrevStatus = jobs_safe_parent_restore_status((string)($_POST['old_parent_prev_status'] ?? 'Main'));
        $rowsJson = (string)($_POST['rows_json'] ?? '[]');
        $reviewNote = trim((string)($_POST['review_note'] ?? ''));
        $rows = json_decode($rowsJson, true);
        if (!is_array($rows)) $rows = [];

        if ($jobId <= 0 || $requestId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id or request_id']);
            break;
        }

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (department = 'jumbo_slitting' OR job_type IN ('Slitting','Jumbo')) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Jumbo slitting job not found']);
            break;
        }

        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND job_id = ? AND request_type = 'jumbo_roll_update' AND status IN ('Pending','Approved') LIMIT 1");
        $reqStmt->bind_param('ii', $requestId, $jobId);
        $reqStmt->execute();
        $changeReq = $reqStmt->get_result()->fetch_assoc();
        if (!$changeReq) {
            echo json_encode(['ok' => false, 'error' => 'Change request not found for this job']);
            break;
        }

        $payload = json_decode((string)($changeReq['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) $payload = [];
        $newParentRoll = $postedNewParentRoll;
        if ($newParentRoll === '') {
            $newParentRoll = trim((string)($payload['parent_roll_no'] ?? ''));
        }
        if ($newParentRoll === '') {
            $rollChanges = is_array($payload['roll_changes'] ?? null) ? $payload['roll_changes'] : [];
            if (!empty($rollChanges) && is_array($rollChanges[0] ?? null)) {
                $newParentRoll = trim((string)($rollChanges[0]['substitute_roll'] ?? ''));
            }
        }
        if ($newParentRoll === '') {
            echo json_encode(['ok' => false, 'error' => 'Suggested parent roll missing in request']);
            break;
        }

        $extra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) $extra = [];
        if ($oldParentRoll === '') {
            $oldParentRoll = trim((string)($extra['parent_roll'] ?? ($extra['parent_details']['roll_no'] ?? $job['roll_no'] ?? '')));
        }
        if ($oldParentRoll === '') {
            echo json_encode(['ok' => false, 'error' => 'Old parent roll is required']);
            break;
        }

        // Safety: manager apply mode can update only one selected parent group.
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $rowParent = trim((string)($r['parent_roll_no'] ?? ''));
            if ($rowParent !== '' && strcasecmp($rowParent, $oldParentRoll) !== 0) {
                echo json_encode(['ok' => false, 'error' => 'Selected-parent-only rule violated. Please edit only one parent roll group.']);
                break 2;
            }
        }

        // Keep same guard rails as accept flow for downstream progression.
        $chain = jobs_get_chain_jobs($db, $jobId);
        $blocked = [];
        foreach ($chain as $cj) {
            if ((int)($cj['id'] ?? 0) === $jobId) continue;
            $st = trim((string)($cj['status'] ?? ''));
            if (!in_array($st, ['Queued', 'Pending'], true)) {
                $blocked[] = ['id' => (int)$cj['id'], 'job_no' => (string)($cj['job_no'] ?? ''), 'status' => $st];
            }
        }
        if (!empty($blocked)) {
            echo json_encode(['ok' => false, 'error' => 'Update blocked: downstream jobs already progressed.', 'blocked_jobs' => $blocked]);
            break;
        }

        $newRollStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1");
        $newRollStmt->bind_param('s', $newParentRoll);
        $newRollStmt->execute();
        $newParentData = $newRollStmt->get_result()->fetch_assoc();
        if (!$newParentData) {
            echo json_encode(['ok' => false, 'error' => 'Suggested parent roll not found in paper stock']);
            break;
        }

        $reviewedBy = (int)($_SESSION['user_id'] ?? 0);
        $reviewedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Manager')));
        if ($reviewNote === '') $reviewNote = 'Manager updated roll/slits in embedded update mode';

        $db->begin_transaction();
        try {
            $existingChild = is_array($extra['child_rolls'] ?? null) ? $extra['child_rolls'] : [];
            $existingStock = is_array($extra['stock_rolls'] ?? null) ? $extra['stock_rolls'] : [];
            $baseParent = trim((string)($extra['parent_roll'] ?? ($extra['parent_details']['roll_no'] ?? '')));

            $isOldParentRow = function(array $row) use ($oldParentRoll, $baseParent): bool {
                $pr = trim((string)($row['parent_roll_no'] ?? ''));
                if ($pr !== '') return strcasecmp($pr, $oldParentRoll) === 0;
                return $baseParent !== '' && strcasecmp($baseParent, $oldParentRoll) === 0;
            };

            $keepChild = [];
            $keepStock = [];
            $removedChildRollNos = [];

            foreach ($existingChild as $row) {
                if (is_array($row) && $isOldParentRow($row)) {
                    $rn = trim((string)($row['roll_no'] ?? ''));
                    if ($rn !== '') $removedChildRollNos[$rn] = true;
                    continue;
                }
                $keepChild[] = $row;
            }
            foreach ($existingStock as $row) {
                if (is_array($row) && $isOldParentRow($row)) {
                    $rn = trim((string)($row['roll_no'] ?? ''));
                    if ($rn !== '') $removedChildRollNos[$rn] = true;
                    continue;
                }
                $keepStock[] = $row;
            }

            $removedRollCount = 0;
            if (!empty($removedChildRollNos)) {
                $list = array_values(array_keys($removedChildRollNos));
                $ph = implode(',', array_fill(0, count($list), '?'));
                $types = str_repeat('s', count($list));
                $delStmt = $db->prepare("DELETE FROM paper_stock WHERE roll_no IN ($ph)");
                $delStmt->bind_param($types, ...$list);
                $delStmt->execute();
                $removedRollCount = (int)$delStmt->affected_rows;
            }

            // Restore the replaced parent roll to previous pre-slitting status.
            $upOldParent = $db->prepare("UPDATE paper_stock SET status = ?, date_used = NULL WHERE roll_no = ?");
            $upOldParent->bind_param('ss', $oldParentPrevStatus, $oldParentRoll);
            $upOldParent->execute();

            $newRowsChild = [];
            $newRowsStock = [];
            $updExistingRoll = $db->prepare("UPDATE paper_stock SET paper_type = ?, company = ?, width_mm = ?, length_mtr = ?, gsm = ?, weight_kg = ?, sqm = ?, remarks = ?, status = ?, job_no = ?, job_name = ?, date_used = NOW() WHERE roll_no = ?");
            $insNewRoll = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, sqm, status, job_no, job_name, date_used, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)");
            $selRoll = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ? LIMIT 1");

            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $rollNo = trim((string)($r['roll_no'] ?? ''));
                if ($rollNo === '') continue;

                $bucket = strtolower(trim((string)($r['bucket'] ?? 'child'))) === 'stock' ? 'stock' : 'child';
                $width = (float)($r['width'] ?? 0);
                $length = (float)($r['length'] ?? 0);
                $wastage = (float)($r['wastage'] ?? 0);
                $remarks = trim((string)($r['remarks'] ?? ''));
                $rowStatus = jobs_normalize_stock_status((string)($r['status'] ?? ''), $bucket);
                $sqm = ($width > 0 && $length > 0) ? round(($width / 1000) * $length, 2) : 0;

                $entry = [
                    'roll_no' => $rollNo,
                    'parent_roll_no' => $newParentRoll,
                    'width' => $width,
                    'length' => $length,
                    'wastage' => $wastage,
                    'remarks' => $remarks,
                    'status' => $rowStatus,
                    'sqm' => $sqm,
                ];
                if ($bucket === 'stock') $newRowsStock[] = $entry;
                else $newRowsChild[] = $entry;

                $paperType = (string)($newParentData['paper_type'] ?? '');
                $company = (string)($newParentData['company'] ?? '');
                $gsm = (float)($newParentData['gsm'] ?? 0);
                $weight = null;
                if ($width > 0 && $length > 0 && $gsm > 0) {
                    $weight = round((($width / 1000) * $length * $gsm) / 1000, 2);
                }
                $jobNo = (string)($job['job_no'] ?? '');
                $jobName = jobs_display_job_name($job);

                $selRoll->bind_param('s', $rollNo);
                $selRoll->execute();
                $exists = $selRoll->get_result()->fetch_assoc();
                if ($exists) {
                    $updExistingRoll->bind_param('ssdddddsssss', $paperType, $company, $width, $length, $gsm, $weight, $sqm, $remarks, $rowStatus, $jobNo, $jobName, $rollNo);
                    $updExistingRoll->execute();
                } else {
                    $insNewRoll->bind_param('sssdddddssssi', $rollNo, $paperType, $company, $width, $length, $gsm, $weight, $sqm, $rowStatus, $jobNo, $jobName, $remarks, $reviewedBy);
                    $insNewRoll->execute();
                }
            }

            $parentRolls = $extra['parent_rolls'] ?? [];
            if (is_string($parentRolls)) {
                $parentRolls = preg_split('/\s*,\s*/', trim($parentRolls), -1, PREG_SPLIT_NO_EMPTY);
            }
            if (!is_array($parentRolls)) $parentRolls = [];
            if (empty($parentRolls)) $parentRolls = [$oldParentRoll];

            $normalizedParents = [];
            $replaced = false;
            foreach ($parentRolls as $pr) {
                $prn = trim((string)$pr);
                if ($prn === '') continue;
                if (strcasecmp($prn, $oldParentRoll) === 0) {
                    $normalizedParents[$newParentRoll] = true;
                    $replaced = true;
                } else {
                    $normalizedParents[$prn] = true;
                }
            }
            if (!$replaced) $normalizedParents[$newParentRoll] = true;

            $newParentOriginalStatus = trim((string)($newParentData['status'] ?? 'Main'));
            if ($newParentOriginalStatus === '') $newParentOriginalStatus = 'Main';

            $extraParentRoll = trim((string)($extra['parent_roll'] ?? ''));
            $extra['parent_roll'] = (strcasecmp($extraParentRoll, $oldParentRoll) === 0) ? $newParentRoll : ($extraParentRoll !== '' ? $extraParentRoll : $newParentRoll);
            $extra['parent_rolls'] = array_values(array_keys($normalizedParents));
            $extra['parent_details'] = [
                'roll_no' => $newParentRoll,
                'original_status' => $newParentOriginalStatus,
                'remarks' => (string)($newParentData['remarks'] ?? ''),
                'width_mm' => (float)($newParentData['width_mm'] ?? 0),
                'length_mtr' => (float)($newParentData['length_mtr'] ?? 0),
                'gsm' => (float)($newParentData['gsm'] ?? 0),
                'weight_kg' => (float)($newParentData['weight_kg'] ?? 0),
                'paper_type' => (string)($newParentData['paper_type'] ?? ''),
                'company' => (string)($newParentData['company'] ?? ''),
            ];
            $extra['child_rolls'] = array_values(array_merge($keepChild, $newRowsChild));
            $extra['stock_rolls'] = array_values(array_merge($keepStock, $newRowsStock));
            $extra['roll_change_from'] = $oldParentRoll;
            $extra['roll_change_to'] = $newParentRoll;
            $extra['roll_change_request_id'] = $requestId;
            $extra['manager_update_mode'] = true;
            $extra['manager_updated_at'] = date('c');

            $jobRollNo = trim((string)($job['roll_no'] ?? ''));
            $nextRollNo = (strcasecmp($jobRollNo, $oldParentRoll) === 0) ? $newParentRoll : ($jobRollNo !== '' ? $jobRollNo : $newParentRoll);

            $notes = trim((string)($job['notes'] ?? ''));
            if ($reviewNote !== '') $notes = trim($notes . "\nManager update: " . $reviewNote);
            $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $updJob = $db->prepare("UPDATE jobs SET roll_no = ?, extra_data = ?, notes = ?, status = 'Pending', started_at = NULL, completed_at = NULL, updated_at = NOW() WHERE id = ?");
            $updJob->bind_param('sssi', $nextRollNo, $extraJson, $notes, $jobId);
            $updJob->execute();

            $markNewParent = $db->prepare("UPDATE paper_stock SET status = 'Slitting', date_used = NOW() WHERE roll_no = ?");
            $markNewParent->bind_param('s', $newParentRoll);
            $markNewParent->execute();

            $updReq = $db->prepare("UPDATE job_change_requests SET status = 'Approved', reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?");
            $updReq->bind_param('issi', $reviewedBy, $reviewedByName, $reviewNote, $requestId);
            $updReq->execute();

            $planId = (int)($job['planning_id'] ?? 0);
            $rootJobNo = (string)($job['job_no'] ?? '');
            $rootType = (string)($job['job_type'] ?? '');
            $snapshot = json_encode([
                'action' => 'roll_change_manager_update',
                'old_parent_roll' => $oldParentRoll,
                'new_parent_roll' => $newParentRoll,
                'request_id' => $requestId,
                'removed_child_rolls' => $removedRollCount,
                'new_child_rows' => count($newRowsChild),
                'new_stock_rows' => count($newRowsStock),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $auditIns = $db->prepare("INSERT INTO job_delete_audit (root_job_id, root_job_no, root_job_type, planning_id, parent_roll_no, action_status, deleted_root, deleted_child_jobs, removed_child_rolls, parent_restored, planning_restored, reset_snapshot_json, requested_by, requested_by_name) VALUES (?, ?, ?, ?, ?, 'completed', 0, 0, ?, 1, 1, ?, ?, ?)");
            if ($auditIns) {
                $auditIns->bind_param('issisisis', $jobId, $rootJobNo, $rootType, $planId, $oldParentRoll, $removedRollCount, $snapshot, $reviewedBy, $reviewedByName);
                $auditIns->execute();
            }

            $notifMsg = ($rootJobNo !== '' ? $rootJobNo : ('JOB-' . $jobId)) . ' manager update applied: ' . $oldParentRoll . ' -> ' . $newParentRoll;
            $notifDept = (string)($job['department'] ?? 'jumbo_slitting');
            $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'success')");
            $nIns->bind_param('iss', $jobId, $notifDept, $notifMsg);
            $nIns->execute();

            $db->commit();
            echo json_encode([
                'ok' => true,
                'job_id' => $jobId,
                'job_no' => $rootJobNo,
                'old_parent_roll' => $oldParentRoll,
                'new_parent_roll' => $newParentRoll,
                'removed_child_rolls' => $removedRollCount,
            ]);
        } catch (Throwable $th) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => 'Manager update failed: ' . $th->getMessage()]);
        }
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
                   p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority, p.created_at AS planning_created_at, p.scheduled_date AS planning_scheduled_date, p.extra_data AS planning_extra_data,
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
            $planningExtra = json_decode($j['planning_extra_data'] ?? '{}', true) ?: [];
            $j['planning_dispatch_date'] = (string)($planningExtra['dispatch_date'] ?? ($j['planning_scheduled_date'] ?? ''));
            $j['planning_die'] = trim((string)($planningExtra['die'] ?? ''));
            $j['planning_image_path'] = jobs_pick_planning_image($planningExtra);
            $j['planning_image_url'] = jobs_join_base_url($j['planning_image_path']);
            $j['planning_job_date'] = jobs_first_non_empty($planningExtra, ['job_date', 'date'], (string)($j['planning_scheduled_date'] ?? ''));
            $j['planning_mkd_job_sl_no'] = jobs_first_non_empty($planningExtra, ['mkd_job_sl_no', 'mkd_sl_no', 'mkd_no']);
            $j['planning_plate_no'] = jobs_first_non_empty($planningExtra, ['plate_no', 'plate', 'plate_number']);
            $j['planning_label_size'] = jobs_first_non_empty($planningExtra, ['label_size', 'size']);
            $j['planning_repeat_mm'] = jobs_first_non_empty($planningExtra, ['repeat_mm', 'repeat']);
            $j['planning_direction'] = jobs_first_non_empty($planningExtra, ['direction']);
            $j['planning_order_mtr'] = jobs_first_non_empty($planningExtra, ['order_mtr', 'order_meter', 'order_meters']);
            $j['planning_order_qty'] = jobs_first_non_empty($planningExtra, ['order_qty', 'quantity', 'order_quantity']);
            $j['planning_reel_no_c1'] = jobs_first_non_empty($planningExtra, ['reel_no_c1', 'reel_c1']);
            $j['planning_reel_no_c2'] = jobs_first_non_empty($planningExtra, ['reel_no_c2', 'reel_c2']);
            $j['planning_width_c1'] = jobs_first_non_empty($planningExtra, ['width_c1']);
            $j['planning_width_c2'] = jobs_first_non_empty($planningExtra, ['width_c2']);
            $j['planning_length_c1'] = jobs_first_non_empty($planningExtra, ['length_c1']);
            $j['planning_length_c2'] = jobs_first_non_empty($planningExtra, ['length_c2']);
            $j['printing_planning'] = trim((string)($planningExtra['printing_planning'] ?? ''));
            $rollNos = jobs_collect_roll_nos($j['extra_data_parsed'], $j);
            $rollMap = jobs_fetch_roll_map($db, $rollNos);
            jobs_attach_live_roll_data($j, $rollMap);
        }
        unset($j);

        echo json_encode(['ok' => true, 'jobs' => $jobs]);
        break;

    // ─── Live Floor unified feed (jobs + pending planning rows) ───────
    case 'list_live_floor':
        $limit = min(800, max(1, (int)($_GET['limit'] ?? 400)));

        jobs_ensure_change_request_table($db);

        $jobsSql = "SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
                   ps.status AS roll_status,
                   p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority, p.created_at AS planning_created_at, p.scheduled_date AS planning_scheduled_date, p.extra_data AS planning_extra_data,
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
                WHERE (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
                  AND j.job_type IN ('Slitting','Printing','Finishing')
                ORDER BY j.created_at DESC
                LIMIT ?";

        $jobsStmt = $db->prepare($jobsSql);
        $jobsStmt->bind_param('i', $limit);
        $jobsStmt->execute();
        $jobs = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($jobs as &$j) {
            $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
            $planningExtra = json_decode($j['planning_extra_data'] ?? '{}', true) ?: [];
            $j['planning_dispatch_date'] = (string)($planningExtra['dispatch_date'] ?? ($j['planning_scheduled_date'] ?? ''));
            $j['planning_die'] = trim((string)($planningExtra['die'] ?? ''));
            $j['planning_image_path'] = jobs_pick_planning_image($planningExtra);
            $j['planning_image_url'] = jobs_join_base_url($j['planning_image_path']);
            $j['planning_job_date'] = jobs_first_non_empty($planningExtra, ['job_date', 'date'], (string)($j['planning_scheduled_date'] ?? ''));
            $j['planning_mkd_job_sl_no'] = jobs_first_non_empty($planningExtra, ['mkd_job_sl_no', 'mkd_sl_no', 'mkd_no']);
            $j['planning_plate_no'] = jobs_first_non_empty($planningExtra, ['plate_no', 'plate', 'plate_number']);
            $j['planning_label_size'] = jobs_first_non_empty($planningExtra, ['label_size', 'size']);
            $j['planning_repeat_mm'] = jobs_first_non_empty($planningExtra, ['repeat_mm', 'repeat']);
            $j['planning_direction'] = jobs_first_non_empty($planningExtra, ['direction']);
            $j['planning_order_mtr'] = jobs_first_non_empty($planningExtra, ['order_mtr', 'order_meter', 'order_meters']);
            $j['planning_order_qty'] = jobs_first_non_empty($planningExtra, ['order_qty', 'quantity', 'order_quantity']);
            $j['planning_reel_no_c1'] = jobs_first_non_empty($planningExtra, ['reel_no_c1', 'reel_c1']);
            $j['planning_reel_no_c2'] = jobs_first_non_empty($planningExtra, ['reel_no_c2', 'reel_c2']);
            $j['planning_width_c1'] = jobs_first_non_empty($planningExtra, ['width_c1']);
            $j['planning_width_c2'] = jobs_first_non_empty($planningExtra, ['width_c2']);
            $j['planning_length_c1'] = jobs_first_non_empty($planningExtra, ['length_c1']);
            $j['planning_length_c2'] = jobs_first_non_empty($planningExtra, ['length_c2']);
            $j['printing_planning'] = trim((string)($planningExtra['printing_planning'] ?? ''));
            $rollNos = jobs_collect_roll_nos($j['extra_data_parsed'], $j);
            $rollMap = jobs_fetch_roll_map($db, $rollNos);
            jobs_attach_live_roll_data($j, $rollMap);
        }
        unset($j);

        // Include planning rows that are not yet converted to any active job card.
        $planSql = "SELECT p.id, p.job_no, p.job_name, p.status, p.priority, p.created_at, p.scheduled_date, p.extra_data
                    FROM planning p
                    LEFT JOIN jobs j ON j.planning_id = p.id
                        AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
                    WHERE j.id IS NULL
                    ORDER BY p.created_at DESC
                    LIMIT ?";
        $planStmt = $db->prepare($planSql);
        $planStmt->bind_param('i', $limit);
        $planStmt->execute();
        $planRows = $planStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($planRows as $p) {
            $planningExtra = json_decode((string)($p['extra_data'] ?? '{}'), true) ?: [];
            $jobs[] = [
                'id' => 'plan-' . (int)$p['id'],
                'job_no' => (string)($p['job_no'] ?? ''),
                'status' => 'Pending',
                'job_type' => 'Planning',
                'department' => 'planning',
                'roll_no' => '',
                'paper_type' => '',
                'company' => '',
                'width_mm' => null,
                'length_mtr' => null,
                'gsm' => null,
                'weight_kg' => null,
                'planning_job_name' => (string)($p['job_name'] ?? ''),
                'planning_status' => trim((string)($p['status'] ?? '')) !== '' ? (string)$p['status'] : 'Pending',
                'planning_priority' => trim((string)($p['priority'] ?? '')) !== '' ? (string)$p['priority'] : 'Normal',
                'planning_created_at' => (string)($p['created_at'] ?? ''),
                'planning_scheduled_date' => (string)($p['scheduled_date'] ?? ''),
                'planning_extra_data' => (string)($p['extra_data'] ?? '{}'),
                'planning_dispatch_date' => (string)($planningExtra['dispatch_date'] ?? ($p['scheduled_date'] ?? '')),
                'planning_die' => trim((string)($planningExtra['die'] ?? '')),
                'planning_image_path' => jobs_pick_planning_image($planningExtra),
                'planning_image_url' => jobs_join_base_url(jobs_pick_planning_image($planningExtra)),
                'planning_job_date' => jobs_first_non_empty($planningExtra, ['job_date', 'date'], (string)($p['scheduled_date'] ?? '')),
                'printing_planning' => trim((string)($planningExtra['printing_planning'] ?? '')),
                'prev_job_no' => '',
                'prev_job_status' => '',
                'pending_change_requests' => 0,
                'extra_data' => '{}',
                'extra_data_parsed' => [],
                'created_at' => (string)($p['created_at'] ?? ''),
                'started_at' => null,
                'completed_at' => null,
                'deleted_at' => null,
                'previous_job_id' => 0,
            ];
        }

        echo json_encode(['ok' => true, 'jobs' => $jobs]);
        break;

    // ─── Get notifications ──────────────────────────────────
    case 'get_notifications':
        $dept = trim($_GET['department'] ?? '');
        $departmentsRaw = trim((string)($_GET['departments'] ?? ''));
        $unreadOnly = !empty($_GET['unread']);
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

        $where = [];
        $params = [];
        $types = '';

        $departments = [];
        if ($departmentsRaw !== '') {
            foreach (explode(',', $departmentsRaw) as $d) {
                $d = trim((string)$d);
                if ($d !== '') $departments[] = $d;
            }
            $departments = array_values(array_unique($departments));
        }

        if (empty($departments) && $dept !== '') {
            $departments = [$dept];
        }

        if (!empty($departments)) {
            $inTokens = implode(',', array_fill(0, count($departments), '?'));
            $where[] = "(n.department IN ({$inTokens}) OR n.department IS NULL)";
            foreach ($departments as $d) {
                $params[] = $d;
                $types .= 's';
            }
        }
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
        foreach ($rows as &$row) {
            $row['target_url'] = jobs_notification_target_url($db, $row);
        }
        unset($row);

        $countSql = "SELECT COUNT(*) AS c FROM job_notifications n";
        $countWhere = [];
        $countParams = [];
        $countTypes = '';
        if (!empty($departments)) {
            $inTokens = implode(',', array_fill(0, count($departments), '?'));
            $countWhere[] = "(n.department IN ({$inTokens}) OR n.department IS NULL)";
            foreach ($departments as $d) {
                $countParams[] = $d;
                $countTypes .= 's';
            }
        }
        $countWhere[] = "n.is_read = 0";
        if ($countWhere) $countSql .= " WHERE " . implode(' AND ', $countWhere);
        $countStmt = $db->prepare($countSql);
        if ($countTypes) $countStmt->bind_param($countTypes, ...$countParams);
        $countStmt->execute();
        $unreadCount = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);

        echo json_encode(['ok' => true, 'notifications' => $rows, 'unread_count' => $unreadCount]);
        break;

    // ─── Mark notification read ─────────────────────────────
    case 'mark_notification_read':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        $nid = (int)($_POST['notification_id'] ?? 0);
        $departmentsRaw = trim((string)($_POST['departments'] ?? ''));
        $departments = [];
        if ($departmentsRaw !== '') {
            foreach (explode(',', $departmentsRaw) as $d) {
                $d = trim((string)$d);
                if ($d !== '') $departments[] = $d;
            }
            $departments = array_values(array_unique($departments));
        }
        if ($nid) {
            $s = $db->prepare("UPDATE job_notifications SET is_read = 1 WHERE id = ?");
            $s->bind_param('i', $nid);
            $s->execute();
        } elseif (!empty($departments)) {
            $inTokens = implode(',', array_fill(0, count($departments), '?'));
            $sql = "UPDATE job_notifications SET is_read = 1 WHERE is_read = 0 AND (department IN ({$inTokens}) OR department IS NULL)";
            $s = $db->prepare($sql);
            $types = str_repeat('s', count($departments));
            $s->bind_param($types, ...$departments);
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

    // ─── Regenerate same job card with reason (admin) ──────
    case 'regenerate_job_card':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Access denied. Only system admin can regenerate job cards.']); break; }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $notesAppend = trim((string)($_POST['notes_append'] ?? ''));
        $newRollNo = strtoupper(trim((string)($_POST['roll_no'] ?? '')));
        $changesJson = trim((string)($_POST['changes_json'] ?? '{}'));

        if ($jobId <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing job_id']); break; }
        if ($reason === '') { echo json_encode(['ok' => false, 'error' => 'Reason is required']); break; }

        $jobStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $jobStmt->bind_param('i', $jobId);
        $jobStmt->execute();
        $job = $jobStmt->get_result()->fetch_assoc();
        if (!$job) { echo json_encode(['ok' => false, 'error' => 'Job not found']); break; }

        $patch = json_decode($changesJson, true);
        if (!is_array($patch)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid changes_json']);
            break;
        }

        $baseExtra = json_decode((string)($job['extra_data'] ?? '{}'), true);
        if (!is_array($baseExtra)) $baseExtra = [];
        $nextExtra = jobs_array_merge_deep($baseExtra, $patch);
        $nextExtraJson = json_encode($nextExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($newRollNo !== '') {
            $chkRoll = $db->prepare("SELECT id FROM paper_stock WHERE UPPER(TRIM(roll_no)) = ? LIMIT 1");
            $chkRoll->bind_param('s', $newRollNo);
            $chkRoll->execute();
            if (!$chkRoll->get_result()->fetch_assoc()) {
                echo json_encode(['ok' => false, 'error' => 'Roll not found: ' . $newRollNo]);
                break;
            }
        }

        $existingNotes = trim((string)($job['notes'] ?? ''));
        $regenNote = '[REGENERATED ' . date('Y-m-d H:i') . '] Reason: ' . $reason;
        if ($notesAppend !== '') {
            $regenNote .= ' | Changes: ' . $notesAppend;
        }
        $nextNotes = trim($existingNotes . "\n" . $regenNote);
        $finalRollNo = $newRollNo !== '' ? $newRollNo : (string)($job['roll_no'] ?? '');

        jobs_ensure_change_request_table($db);
        $db->begin_transaction();
        try {
            $upd = $db->prepare("UPDATE jobs SET roll_no = ?, extra_data = ?, notes = ?, status = 'Pending', started_at = NULL, completed_at = NULL, updated_at = NOW() WHERE id = ?");
            $upd->bind_param('sssi', $finalRollNo, $nextExtraJson, $nextNotes, $jobId);
            $upd->execute();

            $rb = (int)($_SESSION['user_id'] ?? 0);
            $rbName = trim((string)($_SESSION['user_name'] ?? 'Admin'));
            $payloadJson = json_encode([
                'reason' => $reason,
                'notes_append' => $notesAppend,
                'changed_roll_no' => $newRollNo !== '' ? $newRollNo : null,
                'changes_patch' => $patch,
                'regenerated_by' => $rb,
                'regenerated_by_name' => $rbName,
                'regenerated_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $insReq = $db->prepare("INSERT INTO job_change_requests (job_id, request_type, payload_json, status, requested_by, requested_by_name, reviewed_by, reviewed_by_name, reviewed_at, review_note) VALUES (?, 'production_regenerate', ?, 'Approved', ?, ?, ?, ?, NOW(), ?)");
            $insReq->bind_param('isissss', $jobId, $payloadJson, $rb, $rbName, $rb, $rbName, $reason);
            $insReq->execute();

            $dept = trim((string)($job['department'] ?? ''));
            $msg = (string)($job['job_no'] ?? ('JOB-' . $jobId)) . ' regenerated by ' . $rbName . ': ' . $reason;
            $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'warning')");
            $nIns->bind_param('iss', $jobId, $dept, $msg);
            $nIns->execute();

            $db->commit();
            echo json_encode(['ok' => true, 'job_id' => $jobId, 'job_no' => (string)($job['job_no'] ?? ''), 'status' => 'Pending']);
        } catch (Throwable $e) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => 'Regeneration failed: ' . $e->getMessage()]);
        }
        break;

    // ─── Delete job with reset (admin only) ─────────────────
    case 'delete_job':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }
        if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Access denied. Only system admin can delete job cards.']); break; }
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

    // ─── Accept roll change: delete & recreate with same IDs ─────
    case 'accept_roll_change':
        if ($method !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); break; }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $requestId = (int)($_POST['request_id'] ?? 0);
        if (!$jobId || !$requestId) {
            echo json_encode(['ok' => false, 'error' => 'Missing job_id or request_id']);
            break;
        }

        // Fetch job
        $rootStmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $rootStmt->bind_param('i', $jobId);
        $rootStmt->execute();
        $rootJob = $rootStmt->get_result()->fetch_assoc();
        if (!$rootJob) { echo json_encode(['ok' => false, 'error' => 'Job not found']); break; }

        // Fetch change request
        $reqStmt = $db->prepare("SELECT * FROM job_change_requests WHERE id = ? AND job_id = ? AND status = 'Pending' LIMIT 1");
        $reqStmt->bind_param('ii', $requestId, $jobId);
        $reqStmt->execute();
        $changeReq = $reqStmt->get_result()->fetch_assoc();
        if (!$changeReq) { echo json_encode(['ok' => false, 'error' => 'Change request not found or already reviewed']); break; }

        $payload = json_decode((string)($changeReq['payload_json'] ?? '{}'), true) ?: [];
        $newParentRoll = trim((string)($payload['parent_roll_no'] ?? ''));
        if ($newParentRoll === '') { echo json_encode(['ok' => false, 'error' => 'No parent roll in change request']); break; }

        // Fetch new parent roll live data
        $newRollStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1");
        $newRollStmt->bind_param('s', $newParentRoll);
        $newRollStmt->execute();
        $newRollData = $newRollStmt->get_result()->fetch_assoc();
        if (!$newRollData) { echo json_encode(['ok' => false, 'error' => 'New parent roll not found in paper stock']); break; }

        // Check chain blocking
        $chain = jobs_get_chain_jobs($db, $jobId);
        $blocked = [];
        foreach ($chain as $cj) {
            if ((int)($cj['id'] ?? 0) === $jobId) continue;
            $st = trim((string)($cj['status'] ?? ''));
            if (!in_array($st, ['Queued', 'Pending'], true)) {
                $blocked[] = ['id' => (int)$cj['id'], 'job_no' => (string)($cj['job_no'] ?? ''), 'status' => $st];
            }
        }
        if (!empty($blocked)) {
            echo json_encode(['ok' => false, 'error' => 'Delete blocked: downstream jobs already progressed.', 'blocked_jobs' => $blocked]);
            break;
        }

        jobs_ensure_delete_audit_table($db);
        $requestedBy = (int)($_SESSION['user_id'] ?? 0);
        $requestedByName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Unknown')));

        $db->begin_transaction();
        try {
            // ─── PHASE 1: Full delete/restore (same logic as delete_job) ───
            $extra = json_decode((string)($rootJob['extra_data'] ?? '{}'), true) ?: [];
            $parentRoll = trim((string)($extra['parent_roll'] ?? ($extra['parent_details']['roll_no'] ?? '')));
            $parentRemarks = trim((string)($extra['parent_details']['remarks'] ?? ''));
            $parentRollForAudit = trim((string)($rootJob['roll_no'] ?? ''));
            if ($parentRoll !== '') $parentRollForAudit = $parentRoll;

            // Delete child rolls from paper_stock
            $childRolls = [];
            foreach (['child_rolls', 'stock_rolls'] as $bucket) {
                $bRows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
                foreach ($bRows as $r) {
                    $rn = trim((string)($r['roll_no'] ?? ''));
                    if ($rn !== '') $childRolls[$rn] = true;
                }
            }
            $removedRolls = 0;
            if (!empty($childRolls)) {
                $list = array_values(array_keys($childRolls));
                $ph = implode(',', array_fill(0, count($list), '?'));
                $types = str_repeat('s', count($list));
                $delStmt = $db->prepare("DELETE FROM paper_stock WHERE roll_no IN ($ph)");
                $delStmt->bind_param($types, ...$list);
                $delStmt->execute();
                $removedRolls = (int)$delStmt->affected_rows;
            }

            // Restore ALL parent rolls
            $parentRollsToRestore = [];
            if ($parentRoll !== '') $parentRollsToRestore[$parentRoll] = true;
            $rootRollNo = trim((string)($rootJob['roll_no'] ?? ''));
            if ($rootRollNo !== '') $parentRollsToRestore[$rootRollNo] = true;
            foreach (['child_rolls', 'stock_rolls'] as $bk) {
                foreach (is_array($extra[$bk] ?? null) ? $extra[$bk] : [] as $bkRow) {
                    $bkPrn = trim((string)($bkRow['parent_roll_no'] ?? ''));
                    if ($bkPrn !== '') $parentRollsToRestore[$bkPrn] = true;
                }
            }
            $primaryOriginalStatus = trim((string)($extra['parent_details']['original_status'] ?? ''));
            if ($primaryOriginalStatus === '' || in_array($primaryOriginalStatus, ['Consumed', 'Slitting'], true)) {
                $primaryOriginalStatus = 'Main';
            }
            foreach (array_keys($parentRollsToRestore) as $pRollToRestore) {
                $restoreStatus = ($pRollToRestore === $parentRoll) ? $primaryOriginalStatus : 'Main';
                $restoreRemarks = ($pRollToRestore === $parentRoll) ? $parentRemarks : '';
                $upParent = $db->prepare("UPDATE paper_stock SET status = ?, date_used = NULL, remarks = ? WHERE roll_no = ?");
                $upParent->bind_param('sss', $restoreStatus, $restoreRemarks, $pRollToRestore);
                $upParent->execute();
            }

            // Soft-delete chain jobs (downstream only)
            $deletedChildJobs = 0;
            foreach ($chain as $cj) {
                $cid = (int)($cj['id'] ?? 0);
                if ($cid <= 0 || $cid === $jobId) continue;
                $delChild = $db->prepare("UPDATE jobs SET deleted_at = NOW() WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
                $delChild->bind_param('i', $cid);
                $delChild->execute();
                $deletedChildJobs += max(0, (int)$delChild->affected_rows);
            }

            // ─── PHASE 2: Recreate with new roll (same IDs) ───
            $newOriginalStatus = trim((string)($newRollData['status'] ?? 'Main'));
            $newParentDetails = [
                'roll_no'         => $newParentRoll,
                'original_status' => $newOriginalStatus,
                'remarks'         => (string)($newRollData['remarks'] ?? ''),
                'width_mm'        => (float)($newRollData['width_mm'] ?? 0),
                'length_mtr'      => (float)($newRollData['length_mtr'] ?? 0),
                'gsm'             => (float)($newRollData['gsm'] ?? 0),
                'weight_kg'       => (float)($newRollData['weight_kg'] ?? 0),
                'paper_type'      => (string)($newRollData['paper_type'] ?? ''),
                'company'         => (string)($newRollData['company'] ?? ''),
            ];

            // Build new extra_data: keep metadata, update parent, clear child/stock rolls
            $newExtra = [
                'parent_roll'           => $newParentRoll,
                'parent_details'        => $newParentDetails,
                'parent_rolls'          => [$newParentRoll],
                'child_rolls'           => [],
                'stock_rolls'           => [],
                'plan_no'               => $extra['plan_no'] ?? '',
                'batch_no'              => $extra['batch_no'] ?? '',
                'material'              => (string)($newRollData['paper_type'] ?? ($extra['material'] ?? '')),
                'machine'               => $extra['machine'] ?? '',
                'roll_change_from'      => $parentRollForAudit,
                'roll_change_request_id' => $requestId,
                'roll_change_remarks'   => trim((string)($payload['operator_remarks'] ?? '')),
                'roll_change_by'        => trim((string)($changeReq['requested_by_name'] ?? '')),
            ];
            $newExtraJson = json_encode($newExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Revive the same job row with new parent roll, reset status
            $updJob = $db->prepare("UPDATE jobs SET deleted_at = NULL, roll_no = ?, extra_data = ?, status = 'Pending', started_at = NULL, completed_at = NULL, updated_at = NOW() WHERE id = ?");
            $updJob->bind_param('ssi', $newParentRoll, $newExtraJson, $jobId);
            $updJob->execute();

            // Mark new parent roll as 'Slitting'
            $markSlitting = $db->prepare("UPDATE paper_stock SET status = 'Slitting', date_used = NOW() WHERE roll_no = ?");
            $markSlitting->bind_param('s', $newParentRoll);
            $markSlitting->execute();

            // Update planning to Preparing Slitting
            $planId = (int)($rootJob['planning_id'] ?? 0);
            if ($planId > 0) {
                $upPlan = $db->prepare("UPDATE planning SET status = 'Preparing Slitting' WHERE id = ?");
                $upPlan->bind_param('i', $planId);
                $upPlan->execute();
            }

            // Approve the change request
            $updReq = $db->prepare("UPDATE job_change_requests SET status = 'Approved', reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW(), review_note = 'Accepted: Job deleted and recreated with new roll' WHERE id = ?");
            $updReq->bind_param('isi', $requestedBy, $requestedByName, $requestId);
            $updReq->execute();

            // Audit log
            $rootJobNo = (string)($rootJob['job_no'] ?? '');
            $rootType = (string)($rootJob['job_type'] ?? '');
            $deletedRoot = 0;
            $restoredParent = 1;
            $restoredPlanning = 1;
            $snapshot = json_encode([
                'action'             => 'roll_change_recreate',
                'old_parent_roll'    => $parentRollForAudit,
                'new_parent_roll'    => $newParentRoll,
                'request_id'         => $requestId,
                'removed_child_rolls' => $removedRolls,
                'deleted_child_jobs' => $deletedChildJobs,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $auditIns = $db->prepare("INSERT INTO job_delete_audit (root_job_id, root_job_no, root_job_type, planning_id, parent_roll_no, action_status, deleted_root, deleted_child_jobs, removed_child_rolls, parent_restored, planning_restored, reset_snapshot_json, requested_by, requested_by_name) VALUES (?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($auditIns) {
                $auditIns->bind_param('issisiiiiisis', $jobId, $rootJobNo, $rootType, $planId, $parentRollForAudit, $deletedRoot, $deletedChildJobs, $removedRolls, $restoredParent, $restoredPlanning, $snapshot, $requestedBy, $requestedByName);
                $auditIns->execute();
            }

            // Notification
            $notifMsg = ($rootJob['job_no'] ?? 'JMB') . ' roll changed: ' . $parentRollForAudit . ' → ' . $newParentRoll;
            $notifDept = $rootJob['department'] ?? 'jumbo_slitting';
            $nIns = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, 'success')");
            $nIns->bind_param('iss', $jobId, $notifDept, $notifMsg);
            $nIns->execute();

            $db->commit();
            echo json_encode([
                'ok' => true,
                'job_id' => $jobId,
                'job_no' => $rootJobNo,
                'new_parent_roll' => $newParentRoll,
                'old_parent_roll' => $parentRollForAudit,
                'removed_child_rolls' => $removedRolls,
                'deleted_child_jobs' => $deletedChildJobs,
            ]);
        } catch (Throwable $th) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => 'Roll change failed: ' . $th->getMessage()]);
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
    try { $db->rollback(); } catch (Throwable $e) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $th->getMessage()]);
}
