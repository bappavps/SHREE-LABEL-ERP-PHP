<?php

function packing_completed_statuses(): array {
    return [
        'closed',
        'finalized',
        'completed',
        'complete',
        'qc passed',
        'qc_passed',
        'packed',
        'finished production',
        'finished_production',
        'dispatched',
        'packing done',
        'packing_done',
    ];
}

function packing_is_completed_status(string $status): bool {
    $norm = strtolower(trim($status));
    $norm = preg_replace('/\s+/', ' ', str_replace(['-', '_'], ' ', $norm));
    if ($norm === null) {
        $norm = '';
    }

    if (in_array($norm, packing_completed_statuses(), true)) {
        return true;
    }

    return str_starts_with($norm, 'packing done')
        || str_starts_with($norm, 'completed')
        || str_starts_with($norm, 'qc passed')
        || str_starts_with($norm, 'finalized')
        || str_starts_with($norm, 'closed');
}

function packing_effective_status_from_row(array $row): string {
    $rawStatus = trim((string)($row['status'] ?? ''));
    $normRaw = strtolower(trim(str_replace(['-', '_'], ' ', $rawStatus)));
    $extra = packing_decode_json($row['extra_data'] ?? null);

    $hasFinishedFlag = (int)($extra['finished_production_flag'] ?? 0) === 1
        || trim((string)($extra['finished_production_at'] ?? '')) !== '';
    if ($hasFinishedFlag) {
        return 'Finished Production';
    }

    $hasPackedFlag = (int)($extra['packing_done_flag'] ?? 0) === 1
        || (int)($extra['packing_packed_flag'] ?? 0) === 1
        || trim((string)($extra['packing_done_at'] ?? '')) !== '';
    if ($hasPackedFlag && !in_array($normRaw, ['dispatched', 'finished production'], true)) {
        return 'Packed';
    }

    return $rawStatus;
}

function packing_department_type_map(): array {
    return [
        'printing_label' => [
            'label' => 'Printing Label',
            'departments' => ['label-printing', 'label printing', 'label_printing', 'printing_label', 'printing label'],
            'planning_types' => ['label-printing', 'label_printing', 'printing_label'],
            'department_label' => 'Label Slitting',
        ],
        'pos_roll' => [
            'label' => 'POS Roll',
            'departments' => ['pos', 'pos roll', 'pos_roll'],
            'planning_types' => ['pos_roll', 'pos'],
            'job_prefixes' => ['POS-PRL/', 'POS/'],
            'department_label' => 'POS Roll',
        ],
        'one_ply' => [
            'label' => 'One Ply',
            'departments' => ['oneply', 'one ply', 'one_ply', 'paper_roll_1ply', 'paper roll 1ply'],
            'planning_types' => ['one_ply', 'oneply', 'paper_roll_1ply', '1ply'],
            'department_label' => 'One Ply',
        ],
        'two_ply' => [
            'label' => 'Two Ply',
            'departments' => ['twoply', 'two ply', 'two_ply', 'paper_roll_2ply', 'paper roll 2ply'],
            'planning_types' => ['two_ply', 'twoply', 'paper_roll_2ply', '2ply'],
            'department_label' => 'Two Ply',
        ],
        'barcode' => [
            'label' => 'Barcode',
            'departments' => ['barcode', 'rotery', 'rotary'],
            'planning_types' => ['barcode', 'rotary', 'rotery'],
            'department_label' => 'Barcode',
        ],
    ];
}

function packing_normalize_match_key($value): string {
    $value = strtolower(trim((string)$value));
    if ($value === '') {
        return '';
    }

    $value = str_replace(['-', ' '], '_', $value);
    $value = preg_replace('/_+/', '_', $value);
    return $value ?? '';
}

function packing_value_matches_aliases($value, array $aliases): bool {
    $needle = packing_normalize_match_key($value);
    if ($needle === '') {
        return false;
    }

    foreach ($aliases as $alias) {
        if ($needle === packing_normalize_match_key($alias)) {
            return true;
        }
    }

    return false;
}

function packing_planning_board_key($value): string {
    $norm = packing_normalize_match_key($value);
    if ($norm === '') {
        return '';
    }
    if (in_array($norm, ['label_printing', 'printing_label', 'label'], true)) {
        return 'label';
    }
    if (in_array($norm, ['barcode', 'rotery', 'rotary'], true)) {
        return 'barcode';
    }
    if ($norm === 'paperroll' || $norm === 'paper_roll') {
        return 'paperroll';
    }

    return '';
}

function packing_row_origin_board(array $row): string {
    $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
    $jobExtra = packing_decode_json($row['extra_data'] ?? null);
    $sources = [
        (string)($row['plan_department'] ?? ''),
        (string)($planExtra['department'] ?? ''),
        (string)($planExtra['module_source'] ?? ''),
        (string)($planExtra['source_module'] ?? ''),
        (string)($jobExtra['planning_department'] ?? ''),
        (string)($jobExtra['planning_board'] ?? ''),
        (string)($jobExtra['module_source'] ?? ''),
        (string)($jobExtra['source_module'] ?? ''),
    ];

    foreach ($sources as $source) {
        $board = packing_planning_board_key($source);
        if ($board !== '') {
            return $board;
        }
    }

    return '';
}

function packing_row_to_tab(array $row): ?string {
    $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
    $jobExtra = packing_decode_json($row['extra_data'] ?? null);
    $originBoard = packing_row_origin_board($row);

    $planningType = packing_pick_value_loose(
        [$planExtra, $jobExtra, $row],
        ['planning_type', 'paper_roll_type', 'category', 'job_category', 'type']
    );
    $jobNo = trim((string)($row['job_no'] ?? ''));

    if ($originBoard === 'label') {
        return 'printing_label';
    }

    if ($originBoard === 'barcode') {
        return 'barcode';
    }

    if ($originBoard === 'paperroll') {
        foreach (['pos_roll', 'one_ply', 'two_ply'] as $tabKey) {
            $cfg = packing_department_type_map()[$tabKey] ?? null;
            if (!is_array($cfg)) {
                continue;
            }

            if (!empty($cfg['planning_types']) && packing_value_matches_aliases($planningType, (array)$cfg['planning_types'])) {
                return $tabKey;
            }

            foreach ((array)($cfg['job_prefixes'] ?? []) as $prefix) {
                if ($jobNo !== '' && stripos($jobNo, (string)$prefix) === 0) {
                    return $tabKey;
                }
            }

            $candidates = [
                (string)($row['department'] ?? ''),
                (string)($row['job_type'] ?? ''),
                (string)($jobExtra['department'] ?? ''),
                (string)($planExtra['planning_type'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if (packing_value_matches_aliases($candidate, (array)$cfg['departments'])) {
                    return $tabKey;
                }
            }
        }

        return null;
    }

    foreach (packing_department_type_map() as $tabKey => $cfg) {
        if (!empty($cfg['planning_types']) && packing_value_matches_aliases($planningType, (array)$cfg['planning_types'])) {
            return $tabKey;
        }

        foreach ((array)($cfg['job_prefixes'] ?? []) as $prefix) {
            if ($jobNo !== '' && stripos($jobNo, (string)$prefix) === 0) {
                return $tabKey;
            }
        }

        $candidates = [
            (string)($row['department'] ?? ''),
            (string)($row['plan_department'] ?? ''),
            (string)($row['job_type'] ?? ''),
            (string)($planExtra['department'] ?? ''),
            (string)($jobExtra['department'] ?? ''),
            (string)($planExtra['module_source'] ?? ''),
            (string)($jobExtra['module_source'] ?? ''),
            (string)($planExtra['source_module'] ?? ''),
            (string)($jobExtra['source_module'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (packing_value_matches_aliases($candidate, (array)$cfg['departments'])) {
                return $tabKey;
            }
        }
    }

    return null;
}

function packing_tab_keys(): array {
    return array_keys(packing_department_type_map());
}

function packing_tab_label(string $key): string {
    $map = packing_department_type_map();
    return $map[$key]['label'] ?? $key;
}

function packing_department_to_tab(string $department): ?string {
    return packing_row_to_tab(['department' => $department]);
}

function packing_department_display_for_tab(string $tabKey): string {
    $map = packing_department_type_map();
    return $map[$tabKey]['department_label'] ?? '-';
}

function packing_active_row_priority(array $row): int {
    $status = strtolower(trim(str_replace(['-', '_'], ' ', (string)($row['status'] ?? ''))));
    $hasOperatorSubmission = !empty($row['operator_submitted']);

    if (in_array($status, ['packed', 'packing done'], true)) {
        return 300;
    }
    if ($hasOperatorSubmission) {
        return 200;
    }
    if (packing_is_completed_status((string)($row['status'] ?? ''))) {
        return 100;
    }

    return 0;
}

function packing_decode_json($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function packing_find_image_url_in_value($value): string {
    if (is_array($value)) {
        foreach ($value as $v) {
            $found = packing_find_image_url_in_value($v);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    if (!is_string($value)) {
        return '';
    }

    $candidate = trim($value);
    if ($candidate === '') {
        return '';
    }

    $looksLikeImage = (bool)preg_match('/\.(png|jpe?g|webp|gif|bmp|svg)(\?.*)?$/i', $candidate);
    $looksLikeUploadPath = stripos($candidate, '/uploads/') !== false || stripos($candidate, 'uploads/') === 0;
    if ($looksLikeImage || $looksLikeUploadPath) {
        return $candidate;
    }

    return '';
}

function packing_extract_image_url(array $jobExtra, array $planExtra): string {
    foreach (['jumbo_photo_url', 'physical_print_photo_url', 'photo_url', 'image_url', 'artwork_url'] as $key) {
        $candidate = trim((string)($jobExtra[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $fromJob = packing_find_image_url_in_value($jobExtra);
    if ($fromJob !== '') {
        return $fromJob;
    }

    foreach (['artwork_url', 'photo_url', 'image_url', 'product_image'] as $key) {
        $candidate = trim((string)($planExtra[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return packing_find_image_url_in_value($planExtra);
}

function packing_pick_value_loose(array $sources, array $keys): string {
    $wanted = [];
    foreach ($keys as $k) {
        $wanted[strtolower((string)$k)] = true;
    }

    foreach ($sources as $src) {
        if (!is_array($src) || !$src) {
            continue;
        }

        foreach ($keys as $k) {
            if (array_key_exists($k, $src)) {
                $v = $src[$k];
                if ($v !== null && trim((string)$v) !== '') {
                    return trim((string)$v);
                }
            }
        }

        foreach ($src as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (!isset($wanted[strtolower($k)])) {
                continue;
            }
            if ($v !== null && trim((string)$v) !== '') {
                return trim((string)$v);
            }
        }
    }

    return '';
}

function packing_to_float_or_null($value): ?float {
    if ($value === null) {
        return null;
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace([',', '%'], '', $raw);
    return is_numeric($raw) ? (float)$raw : null;
}

function packing_collect_metric_sources(mysqli $db, int $planningId, array $jobExtra, array $planExtra, array $prevExtra = [], array $grandPrevExtra = []): array {
    $sources = [$jobExtra, $planExtra, $prevExtra, $grandPrevExtra];
    if ($planningId <= 0) {
        return $sources;
    }

    $stmt = $db->prepare("\n        SELECT extra_data\n        FROM jobs\n        WHERE planning_id = ?\n          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')\n        ORDER BY id DESC\n        LIMIT 50\n    ");
    if (!$stmt) {
        return $sources;
    }

    $stmt->bind_param('i', $planningId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $extra = packing_decode_json($row['extra_data'] ?? null);
        if ($extra) {
            $sources[] = $extra;
        }
    }
    $stmt->close();

    return $sources;
}

function packing_extract_production_metrics(mysqli $db, int $planningId, array $jobExtra, array $planExtra, array $prevExtra = [], array $grandPrevExtra = []): array {
    $sources = packing_collect_metric_sources($db, $planningId, $jobExtra, $planExtra, $prevExtra, $grandPrevExtra);

    $orderRaw = packing_pick_value_loose($sources, [
        'order_quantity_user', 'order_qty_user', 'quantity_user',
        'order_quantity', 'order_qty', 'quantity', 'qty_pcs', 'order_pcs',
        'total_order_qty'
    ]);
    $prodRaw = packing_pick_value_loose($sources, [
        'production_quantity', 'production_qty', 'produced_quantity', 'produced_qty',
        'actual_qty', 'actual_quantity', 'output_qty', 'completed_qty',
        'total_qty_pcs', 'barcode_total_qty_pcs', 'die_cutting_total_qty_pcs',
        'production_total_qty', 'printed_qty', 'print_qty',
        'final_production_qty', 'up_in_production'
    ]);
    $wastageRaw = packing_pick_value_loose($sources, [
        'wastage', 'wastage_qty', 'waste_qty', 'total_wastage',
        'wastage_percent', 'wastage_percentage',
        'label_slitting_wastage_percentage', 'die_cutting_wastage_percentage',
        'die_cutting_wastage_pcs', 'die_cutting_wastage_mtr',
        'wastage_meters', 'total_wastage_meters'
    ]);
    $percentRaw = packing_pick_value_loose($sources, [
        'production_percent', 'production_percentage', 'prod_percent',
        'completion_percent', 'efficiency_percentage'
    ]);

    $orderNum = packing_to_float_or_null($orderRaw);
    $prodNum = packing_to_float_or_null($prodRaw);

    $percentOut = '';
    if ($orderNum !== null && $orderNum > 0 && $prodNum !== null) {
        // Show variance against plan (e.g. +16.7%), not production rate (e.g. 116.7%).
        $deltaPct = (($prodNum - $orderNum) / $orderNum) * 100;
        $percentOut = ($deltaPct > 0 ? '+' : '') . number_format($deltaPct, 1, '.', '') . '%';
    } elseif ($percentRaw !== '') {
        $percentOut = rtrim($percentRaw);
        if (substr($percentOut, -1) !== '%') {
            $percentOut .= '%';
        }
    }

    return [
        'order_quantity' => $orderRaw,
        'production_quantity' => $prodRaw,
        'wastage' => $wastageRaw,
        'production_percent' => $percentOut,
    ];
}

function packing_display_prefix(): string {
    $prefixSettings = getPrefixSettings();
    $prefix = strtoupper(trim((string)($prefixSettings['modules']['packing']['prefix'] ?? 'PKG')));
    if ($prefix === '') {
        $prefix = 'PKG';
    }
    return $prefix;
}

function packing_build_display_id(int $jobId, string $planNo = ''): string {
    $prefix = packing_display_prefix();
    $planNo = trim($planNo);

    if ($planNo !== '') {
        if (preg_match('/^(?:[^\/-]+)[\/-](\d{2,4})[\/-](\d+)$/', $planNo, $m)) {
            return $prefix . '/' . $m[1] . '/' . $m[2];
        }
        if (preg_match('/(\d{2,4})[\/-](\d+)$/', $planNo, $m)) {
            return $prefix . '/' . $m[1] . '/' . $m[2];
        }
    }

    return $prefix . '/' . date('Y') . '/' . str_pad((string)max(1, $jobId), 4, '0', STR_PAD_LEFT);
}

function packing_ensure_operator_entries_table(mysqli $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS `packing_operator_entries` (
            `id`            INT(11) NOT NULL AUTO_INCREMENT,
            `job_no`        VARCHAR(64) NOT NULL,
            `job_id`        INT(11) DEFAULT NULL,
            `planning_id`   INT(11) DEFAULT NULL,
            `operator_id`   INT(11) DEFAULT NULL,
            `operator_name` VARCHAR(128) DEFAULT NULL,
            `packed_qty`    DECIMAL(12,2) DEFAULT NULL,
            `bundles_count` INT(11) DEFAULT NULL,
            `cartons_count` INT(11) DEFAULT NULL,
            `wastage_qty`   DECIMAL(12,2) DEFAULT NULL,
            `loose_qty`     DECIMAL(12,2) DEFAULT NULL,
            `notes`         TEXT DEFAULT NULL,
            `roll_payload_json` LONGTEXT DEFAULT NULL,
            `photo_path`    VARCHAR(255) DEFAULT NULL,
            `submitted_at`  DATETIME DEFAULT NULL,
            `submitted_lock` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_packing_op_job_no` (`job_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Backward compatibility for existing installations created before loose_qty field.
    $hasLooseQty = false;
    $res = $db->query("SHOW COLUMNS FROM `packing_operator_entries` LIKE 'loose_qty'");
    if ($res instanceof mysqli_result) {
        $hasLooseQty = ($res->num_rows > 0);
        $res->close();
    }
    if (!$hasLooseQty) {
        $db->query("ALTER TABLE `packing_operator_entries` ADD COLUMN `loose_qty` DECIMAL(12,2) DEFAULT NULL AFTER `wastage_qty`");
    }

    // Backward compatibility for installations created before roll_payload_json field.
    $hasRollPayloadJson = false;
    $res = $db->query("SHOW COLUMNS FROM `packing_operator_entries` LIKE 'roll_payload_json'");
    if ($res instanceof mysqli_result) {
        $hasRollPayloadJson = ($res->num_rows > 0);
        $res->close();
    }
    if (!$hasRollPayloadJson) {
        $db->query("ALTER TABLE `packing_operator_entries` ADD COLUMN `roll_payload_json` LONGTEXT DEFAULT NULL AFTER `notes`");
    }

    // Backward compatibility for installations created before submitted_lock field.
    $hasSubmittedLock = false;
    $res = $db->query("SHOW COLUMNS FROM `packing_operator_entries` LIKE 'submitted_lock'");
    if ($res instanceof mysqli_result) {
        $hasSubmittedLock = ($res->num_rows > 0);
        $res->close();
    }
    if (!$hasSubmittedLock) {
        $db->query("ALTER TABLE `packing_operator_entries` ADD COLUMN `submitted_lock` TINYINT(1) NOT NULL DEFAULT 0 AFTER `submitted_at`");
    }
}

function packing_table_has_column(mysqli $db, string $table, string $column): bool {
    $table = trim($table);
    $column = trim($column);
    if ($table === '' || $column === '') {
        return false;
    }

    try {
        $sql = "SHOW COLUMNS FROM `" . $db->real_escape_string($table) . "` LIKE '" . $db->real_escape_string($column) . "'";
        $res = $db->query($sql);
        if ($res instanceof mysqli_result) {
            $exists = ($res->num_rows > 0);
            $res->close();
            return $exists;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function packing_fetch_operator_entry(mysqli $db, string $jobNo, int $jobId = 0, string $jobCreatedAt = ''): ?array {
    $jobNo = trim($jobNo);
    if ($jobNo === '') return null;
    $stmt = $db->prepare("SELECT * FROM packing_operator_entries WHERE job_no = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $jobNo);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    $currentJobId = max(0, (int)$jobId);
    $entryJobId = (int)($row['job_id'] ?? 0);
    if ($currentJobId > 0 && $entryJobId > 0 && $entryJobId !== $currentJobId) {
        return null;
    }

    $jobCreatedAt = trim($jobCreatedAt);
    if ($jobCreatedAt !== '') {
        $jobCreatedTs = strtotime($jobCreatedAt);
        $submittedAt = trim((string)($row['submitted_at'] ?? ''));
        $submittedTs = $submittedAt !== '' ? strtotime($submittedAt) : false;
        $isDifferentJob = $currentJobId > 0 && $entryJobId > 0 && $entryJobId !== $currentJobId;
        if ($isDifferentJob && $jobCreatedTs !== false && $submittedTs !== false && $submittedTs < $jobCreatedTs) {
            return null;
        }
    }

    $row['is_submitted'] = packing_operator_entry_is_submitted($row) ? 1 : 0;
    return $row;
}

function packing_operator_entry_is_submitted(array $entry): bool {
    $hasSubmittedAt = trim((string)($entry['submitted_at'] ?? '')) !== '';
    if (array_key_exists('submitted_lock', $entry)) {
        return (int)($entry['submitted_lock'] ?? 0) === 1 || $hasSubmittedAt;
    }
    return $hasSubmittedAt;
}

function packing_fetch_job_details(mysqli $db, int $jobId): ?array {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
        return null;
    }

    $sql = "
        SELECT
            j.id,
            j.job_no,
            j.roll_no,
            j.job_type,
            j.department,
            j.status,
            j.notes,
            j.started_at,
            j.completed_at,
            j.created_at,
            j.updated_at,
            j.extra_data,
            j.planning_id,
            j.previous_job_id,
            j.sales_order_id,
            p.job_no AS plan_no,
            p.job_name AS plan_name,
            p.priority AS plan_priority,
            p.department AS plan_department,
            p.scheduled_date,
            p.extra_data AS plan_extra_data,
            prev.extra_data AS prev_extra_data,
            grandprev.extra_data AS grandprev_extra_data,
            ps.id AS paper_stock_id,
            ps.company AS paper_company,
            ps.paper_type AS paper_type,
            ps.width_mm AS paper_width_mm,
            ps.length_mtr AS paper_length_mtr,
            ps.gsm AS paper_gsm,
            so.order_no,
            so.client_name,
            so.created_at AS so_created_at,
            so.due_date AS so_due_date
        FROM jobs j
        LEFT JOIN planning p ON p.id = j.planning_id
        LEFT JOIN jobs prev ON prev.id = j.previous_job_id
        LEFT JOIN jobs grandprev ON grandprev.id = prev.previous_job_id
        LEFT JOIN paper_stock ps ON ps.roll_no = j.roll_no
        LEFT JOIN sales_orders so ON so.id = COALESCE(j.sales_order_id, p.sales_order_id)
        WHERE j.id = ?
          AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $jobId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }

    $jobExtra = packing_decode_json($row['extra_data'] ?? null);
    $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
    $prevExtra = packing_decode_json($row['prev_extra_data'] ?? null);
    $grandPrevExtra = packing_decode_json($row['grandprev_extra_data'] ?? null);

    $orderDate = trim((string)(
        $planExtra['order_date']
        ?? $planExtra['job_date']
        ?? $planExtra['planning_date']
        ?? ($row['scheduled_date'] ?? '')
        ?? ($row['so_created_at'] ?? '')
    ));
    $dispatchDate = trim((string)($planExtra['dispatch_date'] ?? ($row['so_due_date'] ?? ($row['completed_at'] ?? ''))));
    $imageUrl = packing_extract_image_url($jobExtra, $planExtra);

    $planningId = (int)($row['planning_id'] ?? 0);
    $metrics = packing_extract_production_metrics($db, $planningId, $jobExtra, $planExtra, $prevExtra, $grandPrevExtra);

    return [
        'id' => (int)$row['id'],
        'packing_display_id' => packing_build_display_id((int)$row['id'], (string)($row['plan_no'] ?? '')),
        'job_no' => (string)($row['job_no'] ?? ''),
        'plan_no' => (string)($row['plan_no'] ?? ''),
        'plan_name' => (string)($row['plan_name'] ?? ''),
        'client_name' => (string)($row['client_name'] ?? ''),
        'order_date' => $orderDate,
        'dispatch_date' => $dispatchDate,
        'roll_no' => (string)($row['roll_no'] ?? ''),
        'job_type' => (string)($row['job_type'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
        'status' => packing_effective_status_from_row($row),
        'started_at' => (string)($row['started_at'] ?? ''),
        'completed_at' => (string)($row['completed_at'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'image_url' => $imageUrl,
        'paper_stock_id' => (int)($row['paper_stock_id'] ?? 0),
        'paper_company' => (string)($row['paper_company'] ?? ''),
        'paper_type' => (string)($row['paper_type'] ?? ''),
        'paper_width_mm' => (string)($row['paper_width_mm'] ?? ''),
        'paper_length_mtr' => (string)($row['paper_length_mtr'] ?? ''),
        'paper_gsm' => (string)($row['paper_gsm'] ?? ''),
        'order_quantity' => (string)($metrics['order_quantity'] ?? ''),
        'production_quantity' => (string)($metrics['production_quantity'] ?? ''),
        'wastage' => (string)($metrics['wastage'] ?? ''),
        'production_percent' => (string)($metrics['production_percent'] ?? ''),
        'job_extra_data' => $jobExtra,
        'plan_extra_data' => $planExtra,
        'prev_job_extra_data' => $prevExtra,
        'grandprev_job_extra_data' => $grandPrevExtra,
        'operator_entry' => packing_fetch_operator_entry(
            $db,
            (string)($row['job_no'] ?? ''),
            (int)($row['id'] ?? 0),
            (string)($row['created_at'] ?? '')
        ),
    ];
}

function packing_fetch_ready_rows(mysqli $db, array $filters = []): array {
    $search = trim((string)($filters['search'] ?? ''));
    $from = trim((string)($filters['from'] ?? ''));
    $to = trim((string)($filters['to'] ?? ''));
    $status = trim((string)($filters['status'] ?? ''));

    // When true, show ALL active jobs regardless of operator submission status.
    // Used by the operator packing page so operators can see fresh jobs to submit.
    $showAllActive = !empty($filters['show_all_active']);
    $hidePackedInActive = !empty($filters['hide_packed_in_active']);

    $allDepartments = [];
    foreach (packing_department_type_map() as $cfg) {
        foreach ($cfg['departments'] as $deptAlias) {
            $allDepartments[] = strtolower(trim($deptAlias));
        }
    }
    $allDepartments = array_values(array_unique(array_filter($allDepartments)));

    $trackedPlanDepartments = ['paperroll', 'barcode', 'rotery', 'rotary', 'label-printing'];

    $quotedDepartments = [];
    foreach ($allDepartments as $dept) {
        $quotedDepartments[] = "'" . $db->real_escape_string($dept) . "'";
    }

    $quotedPlanDepartments = [];
    foreach ($trackedPlanDepartments as $dept) {
        $quotedPlanDepartments[] = "'" . $db->real_escape_string($dept) . "'";
    }

    $where = "(j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')";
    $where .= " AND (LOWER(COALESCE(j.department, '')) IN (" . implode(',', $quotedDepartments) . ")";
    $where .= " OR LOWER(COALESCE(p.department, '')) IN (" . implode(',', $quotedPlanDepartments) . "))";

    // Keep Packed jobs visible in manager dashboard for final completion step.
    // Dispatched / Finished Production should leave active tabs.
    $where .= " AND LOWER(TRIM(REPLACE(REPLACE(COALESCE(j.status,''),'-',' '),'_',' '))) NOT IN ('dispatched','finished production')";

    // Status filter: if provided, filter by exact status match
    if ($status !== '') {
        $statusEsc = $db->real_escape_string($status);
        $where .= " AND j.status = '" . $statusEsc . "'";
    }

    $sql = "
        SELECT
            j.id,
            j.planning_id,
            j.sales_order_id,
            j.job_no,
            j.roll_no,
            j.job_type,
            j.department,
            j.status,
            j.notes,
            j.completed_at,
            j.updated_at,
            j.created_at,
            j.extra_data,
            p.job_no AS plan_no,
            p.job_name AS plan_name,
            p.priority AS plan_priority,
            p.department AS plan_department,
            p.scheduled_date,
            p.extra_data AS plan_extra_data,
            so.client_name,
            so.created_at AS so_created_at,
            so.due_date AS so_due_date
        FROM jobs j
        LEFT JOIN planning p ON p.id = j.planning_id
        LEFT JOIN sales_orders so ON so.id = COALESCE(j.sales_order_id, p.sales_order_id)
        WHERE {$where}
        ORDER BY j.id DESC
    ";

    $result = $db->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    // Keep a fast lookup of jobs that already have operator-submitted entries.
    // Such rows must remain visible to managers until explicitly marked finished.
    $operatorEntryByJobNo = [];
    $hasSubmittedLock = packing_table_has_column($db, 'packing_operator_entries', 'submitted_lock');
    $opSql = $hasSubmittedLock
        ? "SELECT job_no, job_id, submitted_lock, submitted_at FROM packing_operator_entries WHERE job_no IS NOT NULL AND TRIM(job_no) <> ''"
        : "SELECT job_no, job_id, submitted_at FROM packing_operator_entries WHERE job_no IS NOT NULL AND TRIM(job_no) <> ''";
    $opRes = $db->query($opSql);
    if ($opRes) {
        while ($opRow = $opRes->fetch_assoc()) {
            $jn = strtolower(trim((string)($opRow['job_no'] ?? '')));
            if ($jn !== '' && packing_operator_entry_is_submitted($opRow)) {
                $operatorEntryByJobNo[$jn][] = [
                    'job_id' => (int)($opRow['job_id'] ?? 0),
                    'submitted_at' => trim((string)($opRow['submitted_at'] ?? '')),
                ];
            }
        }
    }

    // Group by plan+tab, keeping the latest non-terminal job in active tabs.
    $activeByGroup  = []; // best active job per group
    $doneByGroup    = []; // best terminal job per group (for history fallback)
    $submittedByGroup = []; // any submitted operator entry exists in this group
    foreach ($rows as $row) {
        $tabKey = packing_row_to_tab($row);
        if ($tabKey === null) {
            continue;
        }

        $planningId = (int)($row['planning_id'] ?? 0);
        $planGroup = $planningId > 0
            ? ('plan:' . $planningId)
            : ('job:' . strtolower(trim((string)($row['job_no'] ?? ''))));
        $groupKey = $planGroup . '|' . $tabKey;

        $row['status'] = packing_effective_status_from_row($row);
        $rawStatus  = (string)($row['status'] ?? '');
        $normStatus = strtolower(trim(str_replace(['-', '_'], ' ', $rawStatus)));
        $jobNoKey = strtolower(trim((string)($row['job_no'] ?? '')));
        $jobId = (int)($row['id'] ?? 0);
        $hasSubmittedEntry = false;
        if ($jobNoKey !== '' && isset($operatorEntryByJobNo[$jobNoKey])) {
            foreach ((array)$operatorEntryByJobNo[$jobNoKey] as $entryMeta) {
                $entryJobId = (int)($entryMeta['job_id'] ?? 0);
                $entrySubmittedAt = trim((string)($entryMeta['submitted_at'] ?? ''));
                $entrySubmittedTs = $entrySubmittedAt !== '' ? strtotime($entrySubmittedAt) : false;
                $jobCreatedAt = trim((string)($row['created_at'] ?? ''));
                $jobCreatedTs = $jobCreatedAt !== '' ? strtotime($jobCreatedAt) : false;
                $isDifferentJob = ($entryJobId > 0 && $jobId > 0 && $entryJobId !== $jobId);
                $isStaleFromPastCycle = ($isDifferentJob && $entrySubmittedTs !== false && $jobCreatedTs !== false && $entrySubmittedTs < $jobCreatedTs);
                if ($isStaleFromPastCycle) {
                    continue;
                }

                // After stale-cycle filtering, same job_no should remain submitted on refresh
                // even when job_id references shift between active rows.
                $hasSubmittedEntry = true;
                break;
            }
        }
        $row['operator_submitted'] = $hasSubmittedEntry;
        if ($hasSubmittedEntry) {
            $submittedByGroup[$groupKey] = true;
        }

        $row['packing_tab'] = $tabKey;
        $row['group_key'] = $groupKey;

        $isTerminalForActive = in_array($normStatus, ['dispatched', 'finished production'], true)
            || ($hidePackedInActive && in_array($normStatus, ['packed', 'packing done'], true));

        if ($isTerminalForActive) {
            // Store terminal group (for reference); will NOT go to active tabs
            if (!isset($doneByGroup[$groupKey])) {
                $doneByGroup[$groupKey] = $row;
            }
        } else {
            // Prefer Packed rows first so refresh does not fall back to an older in-progress row.
            if (!isset($activeByGroup[$groupKey])) {
                $activeByGroup[$groupKey] = $row;
            } else {
                $existingPriority = packing_active_row_priority($activeByGroup[$groupKey]);
                $currentPriority = packing_active_row_priority($row);
                if ($currentPriority > $existingPriority) {
                    $activeByGroup[$groupKey] = $row;
                }
            }
        }
    }

    $readyRows = [];
    foreach ($activeByGroup as $row) {
        $groupKey = (string)($row['group_key'] ?? '');
        if ($groupKey !== '' && isset($doneByGroup[$groupKey])) {
            // A newer terminal row exists for this plan/tab group.
            // Do not fallback to an older active row (prevents false "Awaiting Submit").
            continue;
        }

        // Keep queue clean: manager view only shows submitted/completed jobs.
        // show_all_active mode (operator page) also includes fresh "Packing" jobs
        // so operators can see new work and submit their entries for the first time.
        $hasOperatorSubmission = !empty($row['operator_submitted']) || !empty($submittedByGroup[$groupKey]);
        if (!$showAllActive && !$hasOperatorSubmission && !packing_is_completed_status((string)($row['status'] ?? ''))) {
            continue;
        }

        $completedAt = (string)($row['completed_at'] ?? '');
        $fallbackAt = (string)($row['updated_at'] ?? ($row['created_at'] ?? ''));
        $eventTime = $completedAt !== '' ? $completedAt : $fallbackAt;

        if ($from !== '') {
            $eventDate = date('Y-m-d', strtotime($eventTime));
            if ($eventDate < $from) {
                continue;
            }
        }
        if ($to !== '') {
            $eventDate = date('Y-m-d', strtotime($eventTime));
            if ($eventDate > $to) {
                continue;
            }
        }

        if ($search !== '') {
            $jobExtra = packing_decode_json($row['extra_data'] ?? null);
            $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
            $hay = strtolower(
                (string)($row['plan_no'] ?? '') . ' ' .
                (string)($row['plan_name'] ?? '') . ' ' .
                (string)($row['job_no'] ?? '') . ' ' .
                (string)($row['roll_no'] ?? '') . ' ' .
                (string)($row['client_name'] ?? '') . ' ' .
                (string)($jobExtra['client_name'] ?? '') . ' ' .
                (string)($planExtra['client_name'] ?? '')
            );
            if (strpos($hay, strtolower($search)) === false) {
                continue;
            }
        }

        $tabKey = (string)($row['packing_tab'] ?? '');
        $jobExtra = packing_decode_json($row['extra_data'] ?? null);
        $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
        $orderDate = trim((string)(
            $planExtra['order_date']
            ?? $planExtra['job_date']
            ?? $planExtra['planning_date']
            ?? ($row['scheduled_date'] ?? '')
            ?? ($row['so_created_at'] ?? '')
        ));
        $dispatchDate = trim((string)($planExtra['dispatch_date'] ?? ($row['so_due_date'] ?? $eventTime)));
        $clientName = trim((string)($row['client_name'] ?? ($planExtra['client_name'] ?? ($jobExtra['client_name'] ?? ''))));
        $readyRows[] = [
            'id' => (int)$row['id'],
            'packing_display_id' => packing_build_display_id((int)$row['id'], (string)($row['plan_no'] ?? '')),
            'tab' => $tabKey,
            'tab_label' => packing_tab_label($tabKey),
            'plan_no' => (string)($row['plan_no'] ?? ''),
            'plan_name' => (string)($row['plan_name'] ?? ''),
            'plan_priority' => (string)($row['plan_priority'] ?? 'Normal'),
            'job_type' => (string)($row['job_type'] ?? ''),
            'job_no' => (string)($row['job_no'] ?? ''),
            'roll_no' => (string)($row['roll_no'] ?? ''),
            'client_name' => $clientName,
            'order_date' => $orderDate,
            'dispatch_date' => $dispatchDate,
            'last_department' => packing_department_display_for_tab($tabKey),
            'status' => packing_effective_status_from_row($row),
            'notes' => (string)($row['notes'] ?? ''),
            'image_url' => packing_extract_image_url($jobExtra, $planExtra),
            'event_time' => $eventTime,
            'operator_submitted' => $hasOperatorSubmission,
        ];
    }

    usort($readyRows, static function(array $a, array $b): int {
        return strtotime((string)$b['event_time']) <=> strtotime((string)$a['event_time']);
    });

    $byTab = [];
    $counts = [];
    foreach (packing_tab_keys() as $tabKey) {
        $byTab[$tabKey] = [];
        $counts[$tabKey] = 0;
    }

    foreach ($readyRows as $row) {
        $tab = $row['tab'];
        if (!isset($byTab[$tab])) {
            $byTab[$tab] = [];
            $counts[$tab] = 0;
        }
        $byTab[$tab][] = $row;
        $counts[$tab]++;
    }

    return [
        'rows' => $readyRows,
        'rows_by_tab' => $byTab,
        'counts' => $counts,
    ];
}

function packing_fetch_history_rows(mysqli $db, array $filters = []): array {
    $search = trim((string)($filters['search'] ?? ''));
    $from = trim((string)($filters['from'] ?? ''));
    $to = trim((string)($filters['to'] ?? ''));
    $deptFilter = trim((string)($filters['dept'] ?? '')); // Filter by department

    // No SQL status filter â€” filter in PHP for reliability.
    $where = "(j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')";
    
    // Department filter
    if ($deptFilter !== '') {
        $deptEsc = $db->real_escape_string($deptFilter);
        $where .= " AND (LOWER(COALESCE(j.department, '')) = '" . $deptEsc . "' OR LOWER(COALESCE(p.department, '')) = '" . $deptEsc . "')";
    }

    $sql = "
        SELECT
            j.id,
            j.planning_id,
            j.sales_order_id,
            j.job_no,
            j.roll_no,
            j.job_type,
            j.department,
            j.status,
            j.notes,
            j.completed_at,
            j.updated_at,
            j.created_at,
            j.extra_data,
            p.job_no AS plan_no,
            p.job_name AS plan_name,
            p.priority AS plan_priority,
            p.department AS plan_department,
            p.scheduled_date,
            p.extra_data AS plan_extra_data,
            so.client_name,
            so.created_at AS so_created_at,
            so.due_date AS so_due_date
        FROM jobs j
        LEFT JOIN planning p ON p.id = j.planning_id
        LEFT JOIN sales_orders so ON so.id = COALESCE(j.sales_order_id, p.sales_order_id)
        WHERE {$where}
        ORDER BY j.completed_at DESC, j.updated_at DESC, j.id DESC
    ";

    $result = $db->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $historyRows = [];
    foreach ($rows as $row) {
        // Operator packing history should only show packing outcomes,
        // not generic completed rows from upstream departments.
        $effectiveStatus = packing_effective_status_from_row($row);
        if (!packing_is_completed_status($effectiveStatus)) {
            continue;
        }

        $effectiveNorm = strtolower(trim(str_replace(['-', '_'], ' ', $effectiveStatus)));
        $isPackingOutcome = in_array($effectiveNorm, ['packed', 'packing done', 'finished production', 'dispatched'], true);
        if (!$isPackingOutcome) {
            continue;
        }

        $tabKey = packing_department_to_tab((string)($row['department'] ?? ''));
        if ($tabKey === null) {
            $tabKey = packing_department_to_tab((string)($row['plan_department'] ?? ''));
        }

        $jobExtra = packing_decode_json($row['extra_data'] ?? null);
        $planExtra = packing_decode_json($row['plan_extra_data'] ?? null);
        $completedAt = (string)($row['completed_at'] ?? '');
        $fallbackAt = (string)($row['updated_at'] ?? ($row['created_at'] ?? ''));
        $eventTime = $completedAt !== '' ? $completedAt : $fallbackAt;

        if ($from !== '') {
            $eventDate = date('Y-m-d', strtotime($eventTime));
            if ($eventDate < $from) {
                continue;
            }
        }
        if ($to !== '') {
            $eventDate = date('Y-m-d', strtotime($eventTime));
            if ($eventDate > $to) {
                continue;
            }
        }

        if ($search !== '') {
            $hay = strtolower(
                (string)($row['plan_no'] ?? '') . ' ' .
                (string)($row['plan_name'] ?? '') . ' ' .
                (string)($row['job_no'] ?? '') . ' ' .
                (string)($row['roll_no'] ?? '') . ' ' .
                (string)($row['client_name'] ?? '') . ' ' .
                (string)($jobExtra['client_name'] ?? '') . ' ' .
                (string)($planExtra['client_name'] ?? '')
            );
            if (strpos($hay, strtolower($search)) === false) {
                continue;
            }
        }

        $orderDate = trim((string)(
            $planExtra['order_date']
            ?? $planExtra['job_date']
            ?? $planExtra['planning_date']
            ?? ($row['scheduled_date'] ?? '')
            ?? ($row['so_created_at'] ?? '')
        ));
        $dispatchDate = trim((string)($planExtra['dispatch_date'] ?? ($row['so_due_date'] ?? $eventTime)));
        $clientName = trim((string)($row['client_name'] ?? ($planExtra['client_name'] ?? ($jobExtra['client_name'] ?? ''))));

        $historyRows[] = [
            'id' => (int)$row['id'],
            'packing_display_id' => packing_build_display_id((int)$row['id'], (string)($row['plan_no'] ?? '')),
            'tab' => $tabKey ?? 'history_unknown',
            'tab_label' => $tabKey ? packing_tab_label($tabKey) : (trim((string)($row['department'] ?? '')) !== '' ? (string)$row['department'] : 'Unknown'),
            'plan_no' => (string)($row['plan_no'] ?? ''),
            'plan_name' => (string)($row['plan_name'] ?? ''),
            'plan_priority' => (string)($row['plan_priority'] ?? 'Normal'),
            'job_type' => (string)($row['job_type'] ?? ''),
            'job_no' => (string)($row['job_no'] ?? ''),
            'roll_no' => (string)($row['roll_no'] ?? ''),
            'client_name' => $clientName,
            'order_date' => $orderDate,
            'dispatch_date' => $dispatchDate,
            'last_department' => $tabKey ? packing_department_display_for_tab($tabKey) : (trim((string)($row['department'] ?? '')) !== '' ? (string)$row['department'] : '-'),
            'status' => $effectiveStatus,
            'notes' => (string)($row['notes'] ?? ''),
            'image_url' => packing_extract_image_url($jobExtra, $planExtra),
            'event_time' => $eventTime,
        ];
    }

    return $historyRows;
}
