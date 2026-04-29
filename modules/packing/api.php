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

function packing_api_create_notifications(mysqli $db, int $jobId, string $jobNo, string $eventLabel, string $type = 'info', array $extraDepartments = []): void {
    if (!function_exists('createDepartmentNotifications')) {
        return;
    }

    $baseDepartments = ['packing'];
    $departments = array_merge($baseDepartments, $extraDepartments);
    $departments = array_values(array_unique(array_filter(array_map(static function ($dept) {
        return trim((string)$dept);
    }, $departments))));
    if (!$departments) {
        return;
    }

    $jobId = (int)$jobId;
    $jobNo = trim($jobNo);
    $eventLabel = trim($eventLabel);
    if ($eventLabel === '') {
        return;
    }
    $routePath = '/modules/packing/index.php';

    $jobRef = $jobNo !== '' ? $jobNo : ('Job #' . ($jobId > 0 ? $jobId : 'N/A'));
    $message = $jobRef . ' ' . $eventLabel;
    createDepartmentNotifications($db, $departments, $jobId, $message, $type, $routePath);
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

function packing_api_resolve_barcode_metrics(array $operatorPayload, array $planExtra): array {
    $rollOverrides = is_array($operatorPayload['roll_overrides'] ?? null) ? $operatorPayload['roll_overrides'] : [];
    $selected = is_array($operatorPayload['selected_roll_keys'] ?? null) ? $operatorPayload['selected_roll_keys'] : [];
    $mixed = is_array($operatorPayload['mixed'] ?? null) ? $operatorPayload['mixed'] : [];

    $keys = [];
    foreach ($selected as $k) {
        $key = trim((string)$k);
        if ($key !== '') $keys[] = $key;
    }
    if (!$keys) {
        foreach (array_keys($rollOverrides) as $k) {
            $key = trim((string)$k);
            if ($key !== '') $keys[] = $key;
        }
    }

    $sumTotalRolls = 0;
    $bpr = 0;
    $rpc = 0;
    foreach ($keys as $k) {
        $st = $rollOverrides[$k] ?? null;
        if (!is_array($st)) continue;
        $tr = (int)floor((float)($st['total_rolls'] ?? 0));
        if ($tr > 0) $sumTotalRolls += $tr;
        if ($bpr <= 0) {
            $bv = (int)floor((float)($st['bpr'] ?? 0));
            if ($bv > 0) $bpr = $bv;
        }
        if ($rpc <= 0) {
            $rv = (int)floor((float)($st['rolls_per_carton'] ?? 0));
            if ($rv > 0) $rpc = $rv;
        }
    }

    if ($bpr <= 0) {
        $bpr = (int)round((float)($planExtra['barcode_in_1_roll'] ?? 0));
    }

    $mixedEnabled = (!empty($mixed['enabled']) && ((int)$mixed['enabled'] === 1 || $mixed['enabled'] === true));
    if ($mixedEnabled) {
        $mrpc = (int)floor((float)($mixed['rolls_per_carton'] ?? 0));
        if ($mrpc > 0) $rpc = $mrpc;
    }

    return [
        'bpr' => max(0, $bpr),
        'total_rolls' => max(0, $sumTotalRolls),
        'rolls_per_carton' => max(0, $rpc),
        'mixed' => $mixed,
        'mixed_enabled' => $mixedEnabled,
    ];
}

function packing_api_upsert_finished_goods(mysqli $db, array $jobDetails, array $operatorEntry, int $userId, string $packingTabKey = 'pos_roll', bool $throwOnError = false): void {
    packing_api_ensure_finished_goods_table($db);

    $category = 'pos_paper_roll';
    $subTypeDerived = 'POS Roll';
    if ($packingTabKey === 'one_ply') {
        $category = 'one_ply';
        $subTypeDerived = '1 Ply';
    } elseif ($packingTabKey === 'two_ply') {
        $category = 'two_ply';
        $subTypeDerived = '2 Ply';
    } elseif ($packingTabKey === 'barcode') {
        $category = 'barcode';
        $subTypeDerived = 'Barcode';
    } elseif ($packingTabKey === 'printing_label') {
        $category = 'printing_label';
        $subTypeDerived = 'Printing Label';
    }

    $packingId = trim((string)($jobDetails['packing_display_id'] ?? ''));
    $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
    $itemName = trim((string)($jobDetails['plan_name'] ?? ''));
    $clientName = trim((string)($jobDetails['client_name'] ?? ''));
    $planExtra = is_array($jobDetails['plan_extra_data'] ?? null) ? $jobDetails['plan_extra_data'] : [];
    $jobExtra = is_array($jobDetails['job_extra_data'] ?? null) ? $jobDetails['job_extra_data'] : [];
    $operatorPayload = packing_decode_json($operatorEntry['roll_payload_json'] ?? null);
    $rollOverrides = is_array($operatorPayload['roll_overrides'] ?? null) ? $operatorPayload['roll_overrides'] : [];
    $mixedPayload = is_array($operatorPayload['mixed'] ?? null) ? $operatorPayload['mixed'] : [];
    $mixedEnabled = (!empty($mixedPayload['enabled']) && ((int)($mixedPayload['enabled'] ?? 0) === 1 || ($mixedPayload['enabled'] ?? false) === true));
    $mixedPerCarton = (int)floor((float)($mixedPayload['rolls_per_carton'] ?? 0));
    $mixedCartons = (int)floor((float)($mixedPayload['mixed_cartons'] ?? 0));
    $mixedExtraRolls = (int)floor((float)($mixedPayload['mixed_extra_rolls'] ?? 0));
    $mixedBatchLabels = trim((string)($mixedPayload['batch_labels'] ?? ''));
    $childRolls = packing_api_assigned_child_rolls($jobDetails);
    $barcodeMetricsResolved = packing_api_resolve_barcode_metrics($operatorPayload, $planExtra);

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
    $perCarton = 0;
    $displayPerCarton = 0;
    if (!empty($rollOverrides)) {
        foreach ($rollOverrides as $ov) {
            if (!is_array($ov)) {
                continue;
            }
            $candidate = (int)round((float)($ov['rpc'] ?? ($ov['rolls_per_carton'] ?? 0)));
            if ($candidate > 0) {
                $perCarton = $candidate;
                $displayPerCarton = $candidate;
                break;
            }
        }
    }
    if ($mixedEnabled && $mixedPerCarton > 0) {
        $perCarton = $mixedPerCarton;
    }
    $cartonCount = (int)round((float)($operatorEntry['cartons_count'] ?? 0));
    $looseQtyCount = (int)round((float)($operatorEntry['loose_qty'] ?? 0));
    $totalValue = $quantity;

    if ($packingTabKey === 'barcode') {
        $barcodeSizeRaw = trim((string)($planExtra['barcode_size'] ?? ($planExtra['planning_die_size'] ?? ($planExtra['die_size'] ?? ($planExtra['size'] ?? '')))));
        if ($barcodeSizeRaw !== '') {
            $size = $barcodeSizeRaw;
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i', $barcodeSizeRaw, $m)) {
                $a = (float)$m[1];
                $b = (float)$m[2];
                if ($a > 0 && $b > 0) {
                    $maxDim = max($a, $b);
                    $minDim = min($a, $b);
                    $width = rtrim(rtrim(number_format($maxDim, 3, '.', ''), '0'), '.') . ' mm';
                    $length = rtrim(rtrim(number_format($minDim, 3, '.', ''), '0'), '.') . ' mm';
                }
            }
        }

        $barcodePerRoll = (int)($barcodeMetricsResolved['bpr'] ?? 0);
        $barcodeTotalRolls = (int)($barcodeMetricsResolved['total_rolls'] ?? 0);
        $rollsPerCartoon = (int)($barcodeMetricsResolved['rolls_per_carton'] ?? 0);

        // Keep operator submitted physical quantity as finished stock quantity.
        $totalValue = $quantity;

        $perCarton = $rollsPerCartoon > 0 ? $rollsPerCartoon : $perCarton;
    }

    if ($packingTabKey === 'printing_label') {
        $labelPerRoll = (int)($barcodeMetricsResolved['bpr'] ?? 0);
        $labelTotalRolls = (int)($barcodeMetricsResolved['total_rolls'] ?? 0);
        $rollsPerCartoon = (int)($barcodeMetricsResolved['rolls_per_carton'] ?? 0);

        // For printing label, keep operator submitted physical quantity as finished stock quantity.
        $totalValue = $quantity;
        $perCarton = $rollsPerCartoon > 0 ? $rollsPerCartoon : $perCarton;
    }

    if ($packingId === '' || $jobNo === '' || $quantity <= 0) {
        $msg = 'Finished goods requires packing id, job no, and submitted physical quantity';
        if ($throwOnError) {
            throw new RuntimeException($msg);
        }
        packing_api_respond(['ok' => false, 'message' => $msg], 409);
    }

    $remarksPayload = [
        'note' => 'Auto Generated',
        'extra' => [
            'status' => 'Ready to Dispatch',
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
            'loose_qty' => $looseQtyCount > 0 ? $looseQtyCount : 0,
            'total' => $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '',
            'mixed_enabled' => $mixedEnabled ? 1 : 0,
            'mixed_cartons' => $mixedCartons,
            'mixed_extra_rolls' => $mixedExtraRolls,
            'mixed_batch_labels' => $mixedBatchLabels,
        ],
    ];

    if ($packingTabKey === 'barcode') {
        $barcodePerRoll = (int)($barcodeMetricsResolved['bpr'] ?? 0);
        $barcodeTotalRolls = (int)($barcodeMetricsResolved['total_rolls'] ?? 0);
        $rollsPerCartoon = (int)($barcodeMetricsResolved['rolls_per_carton'] ?? 0);
        $mixedPayload = is_array($barcodeMetricsResolved['mixed'] ?? null) ? $barcodeMetricsResolved['mixed'] : [];
        $mixedEnabled = !empty($barcodeMetricsResolved['mixed_enabled']);
        $labelGap = trim((string)($planExtra['label_gap'] ?? ''));
        $dieType = trim((string)($planExtra['die_type'] ?? ''));
        $upInRoll = trim((string)($planExtra['up_in_roll'] ?? ($planExtra['ups_in_roll'] ?? '')));
        $upInProduction = trim((string)($planExtra['up_in_production'] ?? ($planExtra['up_in_die'] ?? ($planExtra['ups'] ?? ''))));
        $ups = $upInRoll !== '' ? $upInRoll : ($upInProduction !== '' ? $upInProduction : '');

        $remarksPayload['extra']['planning_id'] = '';
        $remarksPayload['extra']['pcs_per_roll'] = $barcodePerRoll > 0 ? $barcodePerRoll : '';
        $remarksPayload['extra']['total_roll'] = $barcodeTotalRolls > 0 ? $barcodeTotalRolls : '';
        $remarksPayload['extra']['roll_per_cartoon'] = $rollsPerCartoon > 0 ? $rollsPerCartoon : '';
        $remarksPayload['extra']['label_gap'] = $labelGap;
        $remarksPayload['extra']['die_type'] = $dieType;
        $remarksPayload['extra']['up_in_roll'] = $upInRoll;
        $remarksPayload['extra']['up_in_production'] = $upInProduction;
        $remarksPayload['extra']['ups'] = $ups;
        $remarksPayload['extra']['total_quantity'] = $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '';
        $remarksPayload['extra']['available_for_dispatch'] = $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '';
        $remarksPayload['extra']['mixed_enabled'] = $mixedEnabled ? 1 : 0;
        $remarksPayload['extra']['mixed_cartons'] = (int)($mixedPayload['mixed_cartons'] ?? 0);
        $remarksPayload['extra']['mixed_extra_rolls'] = (int)($mixedPayload['mixed_extra_rolls'] ?? 0);
        $remarksPayload['extra']['mixed_batch_labels'] = trim((string)($mixedPayload['batch_labels'] ?? ''));
    }
    if ($packingTabKey === 'printing_label') {
        $labelPerRoll = (int)($barcodeMetricsResolved['bpr'] ?? 0);
        $labelTotalRolls = (int)($barcodeMetricsResolved['total_rolls'] ?? 0);
        $rollsPerCartoon = (int)($barcodeMetricsResolved['rolls_per_carton'] ?? 0);
        $mixedPayload = is_array($barcodeMetricsResolved['mixed'] ?? null) ? $barcodeMetricsResolved['mixed'] : [];
        $mixedEnabled = !empty($barcodeMetricsResolved['mixed_enabled']);

        $labelSizeRaw = trim((string)($planExtra['size'] ?? ($planExtra['label_size'] ?? '')));
        if ($labelSizeRaw !== '' && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i', $labelSizeRaw, $m)) {
            $width = rtrim(rtrim(number_format((float)$m[1], 3, '.', ''), '0'), '.') . ' mm';
            $length = rtrim(rtrim(number_format((float)$m[2], 3, '.', ''), '0'), '.') . ' mm';
        }

        $displayRollCount = $labelTotalRolls > 0 ? $labelTotalRolls : (int)round((float)($operatorEntry['bundles_count'] ?? 0));
        $netPackedQty = max(0, $totalValue - $looseQtyCount);
        $mixedExtraRollsQty = (int)($mixedPayload['mixed_extra_rolls'] ?? 0);
        if ($mixedExtraRollsQty > 0 && $labelPerRoll > 0) {
            $netPackedQty = max(0, $netPackedQty - ($mixedExtraRollsQty * $labelPerRoll));
        }

        $remarksPayload['extra']['job_name'] = trim((string)($jobDetails['plan_name'] ?? $itemName));
        $remarksPayload['extra']['order_date'] = trim((string)($jobDetails['order_date'] ?? ($planExtra['order_date'] ?? '')));
        $remarksPayload['extra']['dispatch_date'] = trim((string)($jobDetails['dispatch_date'] ?? ($planExtra['dispatch_date'] ?? '')));
        $remarksPayload['extra']['mtrs'] = trim((string)($planExtra['allocate_mtrs'] ?? ($planExtra['mtrs'] ?? ($planExtra['meter'] ?? ($jobDetails['paper_length_mtr'] ?? '')))));
        $remarksPayload['extra']['qty'] = $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '';
        $remarksPayload['extra']['qty_per_roll'] = $labelPerRoll > 0 ? $labelPerRoll : '';
        $remarksPayload['extra']['direction'] = trim((string)($planExtra['roll_direction'] ?? ($planExtra['direction'] ?? ($jobExtra['direction'] ?? ''))));
        $remarksPayload['extra']['pcs_per_roll'] = $labelPerRoll > 0 ? $labelPerRoll : '';
        $remarksPayload['extra']['total_roll'] = $labelTotalRolls > 0 ? $labelTotalRolls : '';
        $remarksPayload['extra']['roll_per_cartoon'] = $rollsPerCartoon > 0 ? $rollsPerCartoon : '';
        $remarksPayload['extra']['total_quantity'] = $totalValue > 0 ? rtrim(rtrim(number_format($totalValue, 3, '.', ''), '0'), '.') : '';
        $remarksPayload['extra']['available_for_dispatch'] = rtrim(rtrim(number_format($netPackedQty, 3, '.', ''), '0'), '.');
        $remarksPayload['extra']['after_packing_qty'] = rtrim(rtrim(number_format($netPackedQty, 3, '.', ''), '0'), '.');
        $remarksPayload['extra']['current_total'] = rtrim(rtrim(number_format($netPackedQty, 3, '.', ''), '0'), '.');
        $remarksPayload['extra']['display_roll_count'] = $displayRollCount > 0 ? $displayRollCount : '';
        $remarksPayload['extra']['display_per_carton'] = $displayPerCarton > 0 ? $displayPerCarton : '';
        $remarksPayload['extra']['paper_size'] = trim((string)($planExtra['paper_size'] ?? $size));
        $remarksPayload['extra']['mixed_enabled'] = $mixedEnabled ? 1 : 0;
        $remarksPayload['extra']['mixed_cartons'] = (int)($mixedPayload['mixed_cartons'] ?? 0);
        $remarksPayload['extra']['mixed_extra_rolls'] = (int)($mixedPayload['mixed_extra_rolls'] ?? 0);
        $remarksPayload['extra']['mixed_batch_labels'] = trim((string)($mixedPayload['batch_labels'] ?? ''));
    }
    $remarks = json_encode($remarksPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($remarks === false) {
        $remarks = '';
    }

    $existingStmt = $db->prepare("SELECT id FROM finished_goods_stock WHERE category = '{$category}' AND item_code = ? AND batch_no = ? ORDER BY id DESC LIMIT 1");
    if (!$existingStmt) {
        $msg = 'Finished goods lookup prepare failed';
        if ($throwOnError) {
            throw new RuntimeException($msg);
        }
        packing_api_respond(['ok' => false, 'message' => $msg], 500);
    }
    $existingStmt->bind_param('ss', $packingId, $jobNo);
    $existingStmt->execute();
    $existingRow = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existingRow) {
        $stockId = (int)($existingRow['id'] ?? 0);
        $updateStmt = $db->prepare("UPDATE finished_goods_stock SET sub_type = ?, item_name = ?, size = ?, gsm = ?, quantity = ?, unit = 'PCS', location = 'Packing', date = ?, remarks = ? WHERE id = ? LIMIT 1");
        if (!$updateStmt) {
            $msg = 'Finished goods update prepare failed';
            if ($throwOnError) {
                throw new RuntimeException($msg);
            }
            packing_api_respond(['ok' => false, 'message' => $msg], 500);
        }
        $subType = $subTypeDerived;
        $updateStmt->bind_param('ssssdssi', $subType, $itemName, $size, $gsm, $quantity, $dateValue, $remarks, $stockId);
        if (!$updateStmt->execute()) {
            $err = (string)$updateStmt->error;
            $updateStmt->close();
            $msg = 'Finished goods update failed: ' . $err;
            if ($throwOnError) {
                throw new RuntimeException($msg);
            }
            packing_api_respond(['ok' => false, 'message' => $msg], 500);
        }
        $updateStmt->close();
        return;
    }

    $insertStmt = $db->prepare("INSERT INTO finished_goods_stock (category, sub_type, item_name, item_code, size, gsm, quantity, unit, location, batch_no, date, remarks, created_by) VALUES ('{$category}', ?, ?, ?, ?, ?, ?, 'PCS', 'Packing', ?, ?, ?, ?)");
    if (!$insertStmt) {
        $msg = 'Finished goods insert prepare failed';
        if ($throwOnError) {
            throw new RuntimeException($msg);
        }
        packing_api_respond(['ok' => false, 'message' => $msg], 500);
    }
    $subType = $subTypeDerived;
    $insertStmt->bind_param('sssssdsssi', $subType, $itemName, $packingId, $size, $gsm, $quantity, $jobNo, $dateValue, $remarks, $userId);
    if (!$insertStmt->execute()) {
        $err = (string)$insertStmt->error;
        $insertStmt->close();
        $msg = 'Finished goods insert failed: ' . $err;
        if ($throwOnError) {
            throw new RuntimeException($msg);
        }
        packing_api_respond(['ok' => false, 'message' => $msg], 500);
    }
    $insertStmt->close();
}

function packing_api_ensure_carton_min_column(mysqli $db): void {
    $check = $db->query("SHOW COLUMNS FROM carton_items LIKE 'min_qty'");
    $exists = $check && $check->num_rows > 0;
    if ($check) {
        $check->close();
    }
    if ($exists) {
        return;
    }
    $db->query("ALTER TABLE carton_items ADD COLUMN min_qty INT UNSIGNED NOT NULL DEFAULT 0 AFTER item_name");
}

function packing_api_fetch_carton_status_rows(mysqli $db): array {
    packing_api_ensure_carton_min_column($db);
    $sql = "SELECT ci.id,
                   ci.item_name,
                   COALESCE(ci.min_qty, 0) AS min_qty,
                   COALESCE(fg.qty, 0) AS qty
            FROM carton_items ci
            LEFT JOIN (
                SELECT LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))) AS size_key,
                       COALESCE(SUM(quantity), 0) AS qty
                FROM finished_goods_stock
                WHERE category = 'carton'
                GROUP BY LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, '')))
            ) fg ON fg.size_key = LOWER(TRIM(ci.item_name))
            ORDER BY ci.item_name ASC";
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }
    $rows = [];
    $seen = [];
    while ($row = $res->fetch_assoc()) {
        $size = trim((string)($row['item_name'] ?? ''));
        if ($size === '') {
            continue;
        }
        $sizeKey = strtolower($size);
        $seen[$sizeKey] = true;
        $qty = (int)max(0, floor((float)($row['qty'] ?? 0)));
        $minQty = max(0, (int)floor((float)($row['min_qty'] ?? 0)));
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'size' => $size,
            'qty' => $qty,
            'min_qty' => $minQty,
            'is_low' => ($minQty > 0 && $qty < $minQty),
        ];
    }

    // Include carton sizes created directly in Finished Goods > Carton even if not present in carton_items.
    $fgOnlySql = "SELECT LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))) AS size_key,
                         TRIM(COALESCE(NULLIF(size, ''), item_name, '')) AS size_label,
                         COALESCE(SUM(quantity), 0) AS qty
                  FROM finished_goods_stock
                  WHERE category = 'carton'
                    AND TRIM(COALESCE(NULLIF(size, ''), item_name, '')) <> ''
                  GROUP BY LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))),
                           TRIM(COALESCE(NULLIF(size, ''), item_name, ''))";
    $fgOnlyRes = $db->query($fgOnlySql);
    if ($fgOnlyRes) {
        while ($fgRow = $fgOnlyRes->fetch_assoc()) {
            $sizeKey = strtolower(trim((string)($fgRow['size_key'] ?? '')));
            if ($sizeKey === '' || isset($seen[$sizeKey])) {
                continue;
            }
            $sizeLabel = trim((string)($fgRow['size_label'] ?? ''));
            if ($sizeLabel === '') {
                continue;
            }
            $seen[$sizeKey] = true;
            $qty = (int)max(0, floor((float)($fgRow['qty'] ?? 0)));
            $rows[] = [
                'id' => 0,
                'size' => $sizeLabel,
                'qty' => $qty,
                'min_qty' => 0,
                'is_low' => false,
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string)($a['size'] ?? ''), (string)($b['size'] ?? ''));
    });

    return $rows;
}

function packing_api_norm_carton_size_key(string $size): string {
    $key = strtolower(trim($size));
    if ($key === '') {
        return '75mm';
    }
    $key = preg_replace('/\s+/', '', $key);
    if ($key === '75' || $key === '75mm') {
        return '75mm';
    }
    return $key;
}

function packing_api_deduct_carton_from_finished_goods(mysqli $db, string $size, int $deductQty): float {
    if ($deductQty <= 0) {
        return 0.0;
    }

    $sizeKey = packing_api_norm_carton_size_key($size);
    $remaining = (float)$deductQty;
    $deducted = 0.0;

    $sel = $db->prepare("SELECT id, quantity
                        FROM finished_goods_stock
                        WHERE category = 'carton'
                          AND (
                            LOWER(REPLACE(TRIM(size), ' ', '')) = ?
                            OR LOWER(REPLACE(TRIM(item_name), ' ', '')) = ?
                          )
                        ORDER BY id DESC");
    if (!$sel) {
        return 0.0;
    }
    $sel->bind_param('ss', $sizeKey, $sizeKey);
    $sel->execute();
    $res = $sel->get_result();

    $upd = $db->prepare("UPDATE finished_goods_stock SET quantity = ? WHERE id = ? LIMIT 1");
    if (!$upd) {
        $sel->close();
        return 0.0;
    }

    while ($remaining > 0 && ($row = $res->fetch_assoc())) {
        $id = (int)($row['id'] ?? 0);
        $qty = (float)($row['quantity'] ?? 0);
        if ($id <= 0 || $qty <= 0) {
            continue;
        }

        $take = min($qty, $remaining);
        if ($take <= 0) {
            continue;
        }

        $newQty = max(0, $qty - $take);
        $upd->bind_param('di', $newQty, $id);
        if (!$upd->execute()) {
            continue;
        }

        $remaining -= $take;
        $deducted += $take;
    }

    $upd->close();
    $sel->close();
    return round($deducted, 3);
}

function packing_api_apply_carton_usage_deduction(mysqli $db, array $opEntry, string $jobNo, int $jobId, string $stageLabel): void {
    $opPayload = packing_decode_json($opEntry['roll_payload_json'] ?? null);
    $rollOverrides = is_array($opPayload['roll_overrides'] ?? null) ? $opPayload['roll_overrides'] : [];
    $mixedData = is_array($opPayload['mixed'] ?? null) ? $opPayload['mixed'] : [];
    $cartonUsage = []; // [ 'size' => qty ]

    foreach ($rollOverrides as $override) {
        if (!is_array($override)) {
            continue;
        }
        $size = trim((string)($override['csize_text'] ?? '75mm'));
        if ($size === '') {
            $size = '75mm';
        }
        $qty = max(0, (int)($override['cartons'] ?? 0));
        if ($qty > 0) {
            $cartonUsage[$size] = ($cartonUsage[$size] ?? 0) + $qty;
        }
    }

    // Mixed carton: use csize_text of first roll, or default.
    if (!empty($mixedData['enabled']) && (int)($mixedData['mixed_cartons'] ?? 0) > 0) {
        $mixedQty = (int)$mixedData['mixed_cartons'];
        $firstSize = '75mm';
        foreach ($rollOverrides as $override) {
            if (is_array($override) && !empty($override['csize_text'])) {
                $firstSize = trim((string)$override['csize_text']);
                break;
            }
        }
        $cartonUsage[$firstSize] = ($cartonUsage[$firstSize] ?? 0) + $mixedQty;
    }

    foreach ($cartonUsage as $size => $usedQty) {
        if ($usedQty <= 0) {
            continue;
        }

        // Find or create carton_items record.
        $itemId = 0;
        $itemSel = $db->prepare("SELECT id FROM carton_items WHERE item_name = ? LIMIT 1");
        if ($itemSel) {
            $itemSel->bind_param('s', $size);
            $itemSel->execute();
            $itemRow = $itemSel->get_result()->fetch_assoc();
            $itemSel->close();

            if ($itemRow) {
                $itemId = (int)$itemRow['id'];
            } else {
                $itemIns = $db->prepare("INSERT INTO carton_items (item_name, status) VALUES (?, 'ACTIVE')");
                if ($itemIns) {
                    $itemIns->bind_param('s', $size);
                    $itemIns->execute();
                    $itemId = (int)$db->insert_id;
                    $itemIns->close();
                }
            }
        }

        if ($itemId > 0) {
            $remarks = 'Auto-deducted: Job ' . $jobNo . ' ' . $stageLabel . ' [FG_SYNCED]';
            $stockIns = $db->prepare("INSERT INTO carton_stock (item_id, qty, type, ref_type, ref_id, remarks) VALUES (?, ?, 'CONSUME', 'JOB', ?, ?)");
            if ($stockIns) {
                $stockIns->bind_param('iiis', $itemId, $usedQty, $jobId, $remarks);
                $stockIns->execute();
                $stockIns->close();
            }
        }

        // Keep Finished > Carton inventory in sync immediately.
        packing_api_deduct_carton_from_finished_goods($db, (string)$size, (int)$usedQty);
    }
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

    if ($action === 'get_carton_stock_status') {
        $rows = packing_api_fetch_carton_status_rows($db);
        packing_api_respond(['ok' => true, 'rows' => $rows]);
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
        if ($currentStatus === 'finished production') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Finished Production']);
        }
        if ($currentStatus === 'finished barcode' || $currentStatus === 'finished production') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Finished Production']);
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
        $isSubmitted = packing_operator_entry_is_submitted($opEntry);
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

        $advanceTargets = function_exists('jobsAdvanceNotificationTargets')
            ? jobsAdvanceNotificationTargets('packing', [])
            : [];
        packing_api_create_notifications($db, $jobId, $jobNo, 'moved to Packed in packing.', 'success', $advanceTargets);

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
        if ($tabKey === 'barcode') {
            packing_api_respond(['ok' => false, 'message' => 'Barcode packing uses Finished Barcode instead of Dispatched'], 409);
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

        if ($tabKey === 'printing_label') {
            $dispatchJobNoForDeduct = trim((string)($statusRow['job_no'] ?? ''));
            $dispatchOpEntry = $dispatchJobNoForDeduct !== '' ? packing_fetch_operator_entry($db, $dispatchJobNoForDeduct) : null;
            if (is_array($dispatchOpEntry) && packing_operator_entry_is_submitted($dispatchOpEntry)) {
                packing_api_apply_carton_usage_deduction($db, $dispatchOpEntry, $dispatchJobNoForDeduct, $jobId, 'Dispatched');
            }
        }

        $dispatchJobNo = trim((string)($statusRow['job_no'] ?? ''));
        $advanceTargets = function_exists('jobsAdvanceNotificationTargets')
            ? jobsAdvanceNotificationTargets('packing', [])
            : [];
        packing_api_create_notifications($db, $jobId, $dispatchJobNo, 'marked as Dispatched.', 'success', $advanceTargets);

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Dispatched']);
    }

    if ($action === 'mark_finished_barcode') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can finish barcode packing'], 403);
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
        if ($tabKey !== 'barcode') {
            packing_api_respond(['ok' => false, 'message' => 'Finished Barcode is available only for Barcode packing'], 409);
        }

        $currentStatus = strtolower(trim(str_replace(['-', '_'], ' ', packing_effective_status_from_row([
            'status' => (string)($jobDetails['status'] ?? ''),
            'extra_data' => $jobDetails['job_extra_data'] ?? [],
        ]))));
        if ($currentStatus === 'finished barcode') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Finished Barcode']);
        }
        if (!in_array($currentStatus, ['packed', 'packing done'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Job must be Packed before Finished Barcode'], 409);
        }

        $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
        $opEntry = $jobNo !== '' ? packing_fetch_operator_entry($db, $jobNo) : null;
        if (!$opEntry) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }
        $isSubmitted = packing_operator_entry_is_submitted($opEntry);
        if (!$isSubmitted) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }

        $db->begin_transaction();
        try {
            packing_api_upsert_finished_goods($db, $jobDetails, $opEntry, $userId, $tabKey);

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

            $planningId = (int)($jobDetails['planning_id'] ?? 0);
            if ($planningId > 0) {
                $planUpd = $db->prepare("UPDATE planning SET status = 'Finished Production', updated_at = NOW() WHERE id = ? LIMIT 1");
                if ($planUpd) {
                    $planUpd->bind_param('i', $planningId);
                    $planUpd->execute();
                    $planUpd->close();
                }
            }

            $extraSel = $db->prepare("SELECT extra_data FROM jobs WHERE id = ? LIMIT 1");
            if ($extraSel) {
                $extraSel->bind_param('i', $jobId);
                $extraSel->execute();
                $extraRow = $extraSel->get_result()->fetch_assoc();
                $extraSel->close();

                $extra = packing_decode_json($extraRow['extra_data'] ?? null);
                $extra['finished_barcode_flag'] = 1;
                $extra['finished_barcode_at'] = date('Y-m-d H:i:s');
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

            packing_api_apply_carton_usage_deduction($db, $opEntry, $jobNo, $jobId, 'Finished Production');

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $advanceTargets = function_exists('jobsAdvanceNotificationTargets')
            ? jobsAdvanceNotificationTargets('packing', [])
            : [];
        $finishedBarcodeJobNo = trim((string)($jobDetails['job_no'] ?? ''));
        packing_api_create_notifications($db, $jobId, $finishedBarcodeJobNo, 'marked as Finished Production.', 'success', $advanceTargets);

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Finished Production']);
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
        if (!in_array($tabKey, ['pos_roll', 'one_ply', 'two_ply'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Finished Production is available only for POS Roll, 1 Ply and 2 Ply packing'], 409);
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
        $isSubmitted = packing_operator_entry_is_submitted($opEntry);
        if (!$isSubmitted) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }

        $db->begin_transaction();
        try {
            packing_api_upsert_finished_goods($db, $jobDetails, $opEntry, $userId, $tabKey);

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

            packing_api_apply_carton_usage_deduction($db, $opEntry, $jobNo, $jobId, 'Finished Production');

            $db->commit();

            $advanceTargets = function_exists('jobsAdvanceNotificationTargets')
                ? jobsAdvanceNotificationTargets('packing', [])
                : [];
            $finishedJobNo = trim((string)($jobDetails['job_no'] ?? ''));
            packing_api_create_notifications($db, $jobId, $finishedJobNo, 'marked as Finished Production.', 'success', $advanceTargets);
        } catch (Throwable $e) {
            $db->rollback();
            packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Finished Production']);
    }

    if ($action === 'mark_finished_label') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can finish label packing'], 403);
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
        if ($tabKey !== 'printing_label') {
            packing_api_respond(['ok' => false, 'message' => 'Finished Label is available only for Printing Label packing'], 409);
        }

        $currentStatus = strtolower(trim(str_replace(['-', '_'], ' ', packing_effective_status_from_row([
            'status' => (string)($jobDetails['status'] ?? ''),
            'extra_data' => $jobDetails['job_extra_data'] ?? [],
        ]))));
        if ($currentStatus === 'finished label' || $currentStatus === 'finished production') {
            packing_api_respond(['ok' => true, 'already_done' => true, 'status' => 'Finished Production']);
        }
        if (!in_array($currentStatus, ['packed', 'packing done'], true)) {
            packing_api_respond(['ok' => false, 'message' => 'Job must be Packed before Finished Production'], 409);
        }

        $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
        $opEntry = $jobNo !== '' ? packing_fetch_operator_entry($db, $jobNo) : null;
        if (!$opEntry || !packing_operator_entry_is_submitted($opEntry)) {
            packing_api_respond(['ok' => false, 'message' => 'Operator submitted physical production is required'], 409);
        }

        $db->begin_transaction();
        try {
            packing_api_upsert_finished_goods($db, $jobDetails, $opEntry, $userId, $tabKey);

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
                $extra['finished_label_flag'] = 1;
                $extra['finished_label_at'] = date('Y-m-d H:i:s');
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

            packing_api_apply_carton_usage_deduction($db, $opEntry, $jobNo, $jobId, 'Finished Production');

            $labelPlanningId = (int)($jobDetails['planning_id'] ?? 0);
            if ($labelPlanningId > 0) {
                $labelPlanUpd = $db->prepare("UPDATE planning SET status = 'Finished Production', updated_at = NOW() WHERE id = ? LIMIT 1");
                if ($labelPlanUpd) {
                    $labelPlanUpd->bind_param('i', $labelPlanningId);
                    $labelPlanUpd->execute();
                    $labelPlanUpd->close();
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $advanceTargets = function_exists('jobsAdvanceNotificationTargets')
            ? jobsAdvanceNotificationTargets('packing', [])
            : [];
        $finishedLabelJobNo = trim((string)($jobDetails['job_no'] ?? ''));
        packing_api_create_notifications($db, $jobId, $finishedLabelJobNo, 'marked as Finished Production.', 'success', $advanceTargets);

        packing_api_respond(['ok' => true, 'already_done' => false, 'status' => 'Finished Production']);
    }

    if ($action === 'backfill_finished_barcode_stock') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }
        if (!packing_api_can_finalize()) {
            packing_api_respond(['ok' => false, 'message' => 'Only manager/admin can run backfill'], 403);
        }

        $limit = (int)($_POST['limit'] ?? 500);
        if ($limit <= 0) {
            $limit = 500;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }

        $sql = "SELECT id, status, extra_data FROM jobs
                WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                ORDER BY id DESC
                LIMIT ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            packing_api_respond(['ok' => false, 'message' => 'Backfill query prepare failed'], 500);
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $scanned = 0;
        $matchedBarcode = 0;
        $upserted = 0;
        $skippedNoOperator = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            $scanned++;
            $jobId = (int)($row['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $rawStatus = strtolower(trim(str_replace(['-', '_'], ' ', (string)($row['status'] ?? ''))));
            $extra = packing_decode_json($row['extra_data'] ?? null);
            $hasFinishedBarcodeFlag = (int)($extra['finished_barcode_flag'] ?? 0) === 1
                || trim((string)($extra['finished_barcode_at'] ?? '')) !== '';
            $isFinishedBarcode = $rawStatus === 'finished barcode' || $hasFinishedBarcodeFlag;
            if (!$isFinishedBarcode) {
                continue;
            }

            $jobDetails = packing_fetch_job_details($db, $jobId);
            if (!$jobDetails) {
                continue;
            }

            $tabKey = packing_row_to_tab([
                'job_no' => (string)($jobDetails['job_no'] ?? ''),
                'department' => (string)($jobDetails['department'] ?? ''),
                'plan_department' => (string)($jobDetails['plan_extra_data']['department'] ?? ''),
                'job_type' => (string)($jobDetails['job_type'] ?? ''),
                'extra_data' => $jobDetails['job_extra_data'] ?? [],
                'plan_extra_data' => $jobDetails['plan_extra_data'] ?? [],
            ]);
            if ($tabKey !== 'barcode') {
                continue;
            }

            $matchedBarcode++;

            $jobNo = trim((string)($jobDetails['job_no'] ?? ''));
            $opEntry = $jobNo !== '' ? packing_fetch_operator_entry($db, $jobNo) : null;
            if (!$opEntry || !packing_operator_entry_is_submitted($opEntry)) {
                $skippedNoOperator++;
                continue;
            }

            try {
                packing_api_upsert_finished_goods($db, $jobDetails, $opEntry, $userId, 'barcode', true);
                $upserted++;
            } catch (Throwable $e) {
                $failed++;
                if (count($errors) < 25) {
                    $errors[] = [
                        'job_id' => $jobId,
                        'job_no' => (string)($jobDetails['job_no'] ?? ''),
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        packing_api_respond([
            'ok' => true,
            'message' => 'Finished Barcode backfill completed',
            'result' => [
                'scanned' => $scanned,
                'matched_barcode_finished' => $matchedBarcode,
                'upserted' => $upserted,
                'skipped_no_operator_submission' => $skippedNoOperator,
                'failed' => $failed,
                'sample_errors' => $errors,
            ],
        ]);
    }

    if ($action === 'operator_submit') {
        if ($method !== 'POST') {
            packing_api_respond(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobNo = trim((string)($_POST['job_no'] ?? ''));
        $planId = (int)($_POST['planning_id'] ?? 0);

        // Always resolve canonical identifiers from DB to avoid client-side stale job_no
        // causing submit persistence mismatch after refresh.
        if ($jobId > 0) {
            $idStmt = $db->prepare("SELECT job_no, planning_id FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
            if ($idStmt) {
                $idStmt->bind_param('i', $jobId);
                $idStmt->execute();
                $idRow = $idStmt->get_result()->fetch_assoc();
                $idStmt->close();
                if (is_array($idRow)) {
                    $resolvedJobNo = trim((string)($idRow['job_no'] ?? ''));
                    if ($resolvedJobNo !== '') {
                        $jobNo = $resolvedJobNo;
                    }
                    $resolvedPlanId = (int)($idRow['planning_id'] ?? 0);
                    if ($resolvedPlanId > 0) {
                        $planId = $resolvedPlanId;
                    }
                }
            }
        }

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
            $isLocked = packing_operator_entry_is_submitted($existing);
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

        packing_api_create_notifications($db, $jobId, $jobNo, 'operator submitted packing entry.', 'info');

        $entry = packing_fetch_operator_entry($db, $jobNo);
        packing_api_respond(['ok' => true, 'message' => 'Packing entry submitted successfully', 'entry' => $entry]);
    }

    packing_api_respond(['ok' => false, 'message' => 'Invalid action'], 400);
} catch (Throwable $e) {
    packing_api_respond(['ok' => false, 'message' => $e->getMessage()], 500);
}
