<?php

function packing_completed_statuses(): array {
    return ['closed', 'finalized', 'completed', 'qc passed'];
}

function packing_is_completed_status(string $status): bool {
    return in_array(strtolower(trim($status)), packing_completed_statuses(), true);
}

function packing_department_type_map(): array {
    return [
        'printing_label' => [
            'label' => 'Printing Label',
            'departments' => ['label_slitting', 'label slitting', 'label-slitting'],
            'department_label' => 'Label Slitting',
        ],
        'pos_roll' => [
            'label' => 'POS Roll',
            'departments' => ['pos', 'pos roll', 'pos_roll'],
            'department_label' => 'POS Roll',
        ],
        'one_ply' => [
            'label' => 'One Ply',
            'departments' => ['oneply', 'one ply', 'one_ply'],
            'department_label' => 'One Ply',
        ],
        'two_ply' => [
            'label' => 'Two Ply',
            'departments' => ['twoply', 'two ply', 'two_ply'],
            'department_label' => 'Two Ply',
        ],
        'barcode' => [
            'label' => 'Barcode',
            'departments' => ['barcode', 'rotery', 'rotary'],
            'department_label' => 'Barcode',
        ],
    ];
}

function packing_tab_keys(): array {
    return array_keys(packing_department_type_map());
}

function packing_tab_label(string $key): string {
    $map = packing_department_type_map();
    return $map[$key]['label'] ?? $key;
}

function packing_department_to_tab(string $department): ?string {
    $needle = strtolower(trim($department));
    if ($needle === '') {
        return null;
    }

    foreach (packing_department_type_map() as $key => $cfg) {
        foreach ($cfg['departments'] as $deptAlias) {
            if ($needle === strtolower(trim($deptAlias))) {
                return $key;
            }
        }
    }

    return null;
}

function packing_department_display_for_tab(string $tabKey): string {
    $map = packing_department_type_map();
    return $map[$tabKey]['department_label'] ?? '-';
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
        'status' => (string)($row['status'] ?? ''),
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
    ];
}

function packing_fetch_ready_rows(mysqli $db, array $filters = []): array {
    $search = trim((string)($filters['search'] ?? ''));
    $from = trim((string)($filters['from'] ?? ''));
    $to = trim((string)($filters['to'] ?? ''));

    $allDepartments = [];
    foreach (packing_department_type_map() as $cfg) {
        foreach ($cfg['departments'] as $deptAlias) {
            $allDepartments[] = strtolower(trim($deptAlias));
        }
    }
    $allDepartments = array_values(array_unique(array_filter($allDepartments)));

    $quotedDepartments = [];
    foreach ($allDepartments as $dept) {
        $quotedDepartments[] = "'" . $db->real_escape_string($dept) . "'";
    }

    $where = "(j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')";
    $where .= " AND LOWER(COALESCE(j.department, '')) IN (" . implode(',', $quotedDepartments) . ")";

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

    $latestByPlanAndType = [];
    foreach ($rows as $row) {
        $tabKey = packing_department_to_tab((string)($row['department'] ?? ''));
        if ($tabKey === null) {
            continue;
        }

        $planningId = (int)($row['planning_id'] ?? 0);
        $planGroup = $planningId > 0
            ? ('plan:' . $planningId)
            : ('job:' . strtolower(trim((string)($row['job_no'] ?? ''))));

        $groupKey = $planGroup . '|' . $tabKey;
        if (!isset($latestByPlanAndType[$groupKey])) {
            $latestByPlanAndType[$groupKey] = $row;
            $latestByPlanAndType[$groupKey]['packing_tab'] = $tabKey;
        }
    }

    $readyRows = [];
    foreach ($latestByPlanAndType as $row) {
        if (!packing_is_completed_status((string)($row['status'] ?? ''))) {
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
            'status' => (string)($row['status'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'image_url' => packing_extract_image_url($jobExtra, $planExtra),
            'event_time' => $eventTime,
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
