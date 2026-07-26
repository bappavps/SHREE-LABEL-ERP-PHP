<?php
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

require_once __DIR__ . '/../../../../includes/auth_check.php';

$db = getDB();

function mi_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mi_clean($value, int $max = 255): string
{
    $text = trim((string) $value);
    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function mi_num($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float) $value, 3);
}

function mi_can_operate(): bool
{
    if (isAdmin()) {
        return true;
    }
    if (function_exists('canAccessPath')) {
        if (canAccessPath('/modules/inventory/mixed-item/index.php')) {
            return true;
        }
        if (canAccessPath('/modules/inventory/finished/index.php')) {
            return true;
        }
        if (canAccessPath('/modules/packing/index.php')) {
            return true;
        }
    }
    return false;
}

function mi_require_access(): void
{
    if (!mi_can_operate()) {
        mi_json(403, ['ok' => false, 'error' => 'Permission denied.']);
    }
}

function mi_parse_extra($remarks): array
{
    $raw = trim((string) $remarks);
    if ($raw === '') {
        return [];
    }
    if ($raw[0] !== '{') {
        return [];
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return [];
    }
    $extra = $parsed['extra'] ?? [];
    return is_array($extra) ? $extra : [];
}

function mi_pick(array $extra, array $keys): string
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $extra)) {
            continue;
        }
        $v = trim((string) $extra[$k]);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function mi_categories(): array
{
    return [
        'pos_paper_roll' => 'POS & Paper Roll Extra',
        'one_ply' => '1 Ply Extra',
        'two_ply' => '2 Ply Extra',
        'barcode' => 'Barcode Extra',
        'printing_roll' => 'Printing Extra',
    ];
}

function mi_decode_assoc($raw): array
{
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mi_format_measure($value, string $suffix = ''): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/-?[0-9]+(?:\.[0-9]+)?/', $raw, $m)) {
        $num = rtrim(rtrim(number_format((float) $m[0], 3, '.', ''), '0'), '.');
        return $suffix !== '' ? ($num . ' ' . $suffix) : $num;
    }
    return $raw;
}

function mi_parse_label_dimensions(string $text): array
{
    $raw = trim($text);
    if ($raw === '') {
        return ['width' => '', 'length' => ''];
    }
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i', $raw, $m)) {
        return [
            'width' => mi_format_measure($m[1], 'mm'),
            'length' => mi_format_measure($m[2], 'mm'),
        ];
    }
    return ['width' => '', 'length' => ''];
}

function mi_fetch_printing_label_context(mysqli $db, array $jobNos): array
{
    $clean = [];
    foreach ($jobNos as $jobNo) {
        $value = trim((string) $jobNo);
        if ($value !== '') {
            $clean[$value] = true;
        }
    }
    $jobNos = array_keys($clean);
    if (empty($jobNos)) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($jobNos), '?'));
    $types = str_repeat('s', count($jobNos));
    $sql = "SELECT pe.job_no, p.extra_data AS plan_extra_data
            FROM packing_operator_entries pe
            LEFT JOIN planning p ON p.id = pe.planning_id
            WHERE pe.job_no IN ($ph)
            ORDER BY pe.id DESC";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$jobNos);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $map = [];
    foreach ($rows as $row) {
        $jobNo = trim((string) ($row['job_no'] ?? ''));
        if ($jobNo === '' || isset($map[$jobNo])) {
            continue;
        }
        $planExtra = mi_decode_assoc($row['plan_extra_data'] ?? '');
        $dims = mi_parse_label_dimensions((string) ($planExtra['size'] ?? ($planExtra['label_size'] ?? '')));
        $map[$jobNo] = [
            'width' => $dims['width'],
            'length' => $dims['length'],
        ];
    }

    return $map;
}

function mi_compute_extra(array $row, array $extra): array
{
    $category = (string) ($row['category'] ?? '');
    $quantity = mi_num($row['quantity'] ?? 0);

    if ($category === 'barcode' || $category === 'printing_label') {
        $mixedEnabled = (int) ($extra['mixed_enabled'] ?? 0) === 1;
        $rpc = (int) floor(mi_num(mi_pick($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton'])));
        $looseQty = max(0, mi_num(mi_pick($extra, ['loose_qty'])));
        $mixedExtraRolls = max(0, mi_num($extra['mixed_extra_rolls'] ?? 0));

        if ($mixedEnabled) {
            $extraQty = $looseQty > 0 ? $looseQty : $mixedExtraRolls;
            return [
                'extra_qty' => $extraQty,
                'unit_type' => 'PCS',
                'per_carton' => '',
                'possible_cartons' => '',
            ];
        }

        // If mixed_enabled is explicitly 0 and no loose items remain (e.g. repacked items or new repacked full cartons)
        if (isset($extra['mixed_enabled']) && (int) $extra['mixed_enabled'] === 0 && $looseQty <= 0 && $mixedExtraRolls <= 0) {
            return [
                'extra_qty' => 0,
                'unit_type' => 'PCS',
                'per_carton' => '',
                'possible_cartons' => '',
            ];
        }

        $pcsPerRoll = mi_num(mi_pick($extra, ['pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll', 'qty_per_roll']));
        $explicitExtraRolls = mi_num(mi_pick($extra, ['extra_rolls', 'mixed_extra_rolls']));

        $extraQty = 0;
        $possible = 0;
        if ($explicitExtraRolls > 0) {
            // Trust the explicit extra rolls recorded at packing time. A dispatch only
            // removes full cartons and rewrites total_roll/carton/loose_qty from the
            // full-carton-only quantity, so the explicit extra pool must stay the source
            // of truth for the mixed item board (otherwise it shows blank after dispatch).
            $extraQty = $explicitExtraRolls * ($pcsPerRoll > 0 ? $pcsPerRoll : 1);
            if ($rpc > 0) {
                $possible = (int) floor($explicitExtraRolls / $rpc);
            }
        } else {
            $totalRoll = mi_num(mi_pick($extra, ['total_roll', 'total_rolls']));
            if ($totalRoll <= 0) {
                if ($pcsPerRoll > 0 && $quantity > 0) {
                    $totalRoll = ceil($quantity / $pcsPerRoll);
                }
            }
            if ($rpc > 0 && $totalRoll > 0) {
                $possible = (int) floor($totalRoll / $rpc);
                $rollRemainder = fmod($totalRoll, $rpc);
                if ($rollRemainder > 0 && $pcsPerRoll > 0) {
                    $extraQty += $rollRemainder * $pcsPerRoll;
                }
            } elseif ($totalRoll > 0 && $pcsPerRoll > 0) {
                $extraQty += $totalRoll * $pcsPerRoll;
            }
        }
        $extraQty += $looseQty;

        $extraRolls = 0;
        if ($rpc > 0) {
            // In the explicit-extra branch $totalRoll is intentionally undefined (we trust
            // extra_rolls directly), so fall back to the explicit extra roll count there.
            $extraRolls = (isset($totalRoll) && $totalRoll > 0)
                ? (int) fmod($totalRoll, $rpc)
                : (int) $explicitExtraRolls;
        }
        return [
            'extra_qty' => max(0, $extraQty),
            'unit_type' => 'PCS',
            'per_carton' => '',
            'possible_cartons' => '',
            'extra_rolls' => $extraRolls,
            'extra_pcs' => (int) $looseQty,
            'pcs_per_roll' => (int) $pcsPerRoll,
        ];
    }

    $mixedEnabled = (int) ($extra['mixed_enabled'] ?? 0) === 1;
    if ($mixedEnabled) {
        $rpc = mi_num(mi_pick($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton']));
        $looseQty = max(0, mi_num($extra['loose_qty'] ?? 0));
        $mixedExtraRolls = max(0, mi_num($extra['mixed_extra_rolls'] ?? 0));
        $extraQty = $looseQty > 0 ? $looseQty : $mixedExtraRolls;
        $possible = max(0, (int) floor(mi_num($extra['mixed_cartons'] ?? 0)));
        return [
            'extra_qty' => $extraQty,
            'unit_type' => 'PCS',
            'per_carton' => $rpc,
            'possible_cartons' => $possible,
        ];
    }

    // If mixed_enabled is explicitly 0 and no loose items remain across all categories (e.g. repacked items or new repacked full cartons)
    if (isset($extra['mixed_enabled']) && (int) $extra['mixed_enabled'] === 0 && $looseQty <= 0 && $mixedExtraRolls <= 0) {
        return [
            'extra_qty' => 0,
            'unit_type' => 'PCS',
            'per_carton' => $rpc,
            'possible_cartons' => 0,
        ];
    }
    $extraQty = $quantity;
    $possible = 0;
    if ($perCarton > 0 && $quantity > 0) {
        $possible = (int) floor($quantity / $perCarton);
        $extraQty = fmod($quantity, $perCarton);
    }

    return [
        'extra_qty' => max(0, $extraQty),
        'unit_type' => 'PCS',
        'per_carton' => $perCarton,
        'possible_cartons' => $possible,
    ];
}

function mi_fetch_rows(mysqli $db, string $category = ''): array
{
    $map = mi_categories();
    $labelContextMap = [];

    $sql = "SELECT id, category, sub_type, item_name, item_code, size, gsm, quantity, unit, location, batch_no, date, remarks, created_by, created_at
            FROM finished_goods_stock
            WHERE category IN ('pos_paper_roll','one_ply','two_ply','barcode','printing_roll','printing_label')
            ORDER BY id DESC";
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }

    $allRows = $res->fetch_all(MYSQLI_ASSOC);
    foreach ($allRows as $seedRow) {
        if ((string) ($seedRow['category'] ?? '') === 'printing_label') {
            $jobNo = trim((string) ($seedRow['batch_no'] ?? ''));
            if ($jobNo !== '') {
                $labelContextMap[$jobNo] = true;
            }
        }
    }
    $labelContextMap = mi_fetch_printing_label_context($db, array_keys($labelContextMap));

    // Fetch stock IDs that are currently pending assignment for repacking
    $assignedStockIds = [];
    $aRes = $db->query("SELECT items_json FROM mixed_item_assignments WHERE status = 'pending'");
    if ($aRes) {
        while ($aRow = $aRes->fetch_assoc()) {
            $items = json_decode($aRow['items_json'] ?? '[]', true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $sId = (int) ($item['source_id'] ?? ($item['id'] ?? 0));
                    if ($sId > 0) {
                        $assignedStockIds[$sId] = true;
                    }
                }
            }
        }
    }

    $rows = [];
    foreach ($allRows as $row) {
        $stockId = (int) $row['id'];
        if (!empty($assignedStockIds[$stockId])) {
            continue; // Skip items currently assigned to Packing for repacking
        }

        $cat = (string) ($row['category'] ?? '');
        if ($category !== '') {
            if ($category === 'printing_roll') {
                if ($cat !== 'printing_roll' && $cat !== 'printing_label') {
                    continue;
                }
            } elseif ($cat !== $category) {
                continue;
            }
        }

        $extra = mi_parse_extra($row['remarks'] ?? '');
        $calc = mi_compute_extra($row, $extra);
        $extraQty = mi_num($calc['extra_qty'] ?? 0);
        if ($extraQty <= 0) {
            continue;
        }

        $width = mi_pick($extra, ['width']);
        $length = mi_pick($extra, ['length']);
        if ($cat === 'printing_label') {
            $jobNo = trim((string) ($row['batch_no'] ?? ''));
            if ($jobNo !== '' && isset($labelContextMap[$jobNo])) {
                $ctx = $labelContextMap[$jobNo];
                if (($ctx['width'] ?? '') !== '') {
                    $width = (string) $ctx['width'];
                }
                if (($ctx['length'] ?? '') !== '') {
                    $length = (string) $ctx['length'];
                }
            }
        }
        $perCarton = $calc['per_carton'] ?? '';
        $possible = $calc['possible_cartons'] ?? '';

        $rawQty = mi_num($row['quantity'] ?? 0);
        $afterPackingQty = mi_num(mi_pick($extra, ['after_packing_qty', 'packed_qty', 'total_production']));
        $totalQty = $afterPackingQty > 0 ? $afterPackingQty : ($rawQty + $extraQty);

        if ($extraQty <= 0) {
            continue;
        }

        $rows[] = [
            'id' => $cat . '-' . (int) $row['id'],
            'source_id' => (int) $row['id'],
            'category' => $cat,
            'category_label' => ($cat === 'printing_label' ? ($map['printing_roll'] ?? 'Printing Extra') : ($map[$cat] ?? $cat)),
            'item_name' => (string) ($row['item_name'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'size' => (string) ($row['size'] ?? ''),
            'gsm' => (string) ($row['gsm'] ?? ''),
            'batch_no' => (string) ($row['batch_no'] ?? ''),
            'date' => (string) ($row['date'] ?? ''),
            'total_qty' => $totalQty,
            'extra_qty' => $extraQty,
            'unit_type' => (string) ($calc['unit_type'] ?? 'PCS'),
            'per_carton' => $perCarton,
            'possible_cartons' => $possible,
            'width' => $width,
            'length' => $length,
            'remarks' => (string) ($row['remarks'] ?? ''),
            'extra_rolls' => (int) ($calc['extra_rolls'] ?? 0),
            'extra_pcs' => (int) ($calc['extra_pcs'] ?? 0),
            'pcs_per_roll' => (int) ($calc['pcs_per_roll'] ?? 0),
        ];
    }

    return $rows;
}

function mi_ensure_assignment_table(mysqli $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS mixed_item_assignments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        assignment_code VARCHAR(60) NOT NULL DEFAULT '',
        target VARCHAR(30) NOT NULL DEFAULT 'packing',
        source_category VARCHAR(60) NOT NULL DEFAULT '',
        item_count INT UNSIGNED NOT NULL DEFAULT 0,
        items_json LONGTEXT,
        note VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_mia_target (target),
        KEY idx_mia_status (status),
        KEY idx_mia_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($sql)) {
        mi_json(500, ['ok' => false, 'error' => 'Unable to initialize mixed assignment table: ' . $db->error]);
    }
}

mi_require_access();
mi_ensure_assignment_table($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string) ($_REQUEST['action'] ?? ''));
if ($action === '') {
    mi_json(400, ['ok' => false, 'error' => 'Missing action.']);
}

if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        mi_json(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);
    }
}

if ($action === 'get_tabs') {
    $tabs = [];
    foreach (mi_categories() as $key => $label) {
        $tabs[] = ['key' => $key, 'label' => $label];
    }
    mi_json(200, ['ok' => true, 'tabs' => $tabs]);
}

if ($action === 'get_tab_counts') {
    $counts = [];
    foreach (mi_categories() as $key => $label) {
        $counts[$key] = 0;
    }

    $rows = mi_fetch_rows($db, '');
    foreach ($rows as $row) {
        $cat = (string) ($row['category'] ?? '');
        if ($cat === 'printing_label') {
            $cat = 'printing_roll';
        }
        if ($cat !== '' && array_key_exists($cat, $counts)) {
            $counts[$cat] += 1;
        }
    }

    mi_json(200, ['ok' => true, 'counts' => $counts]);
}

if ($action === 'get_extra_stock') {
    $category = mi_clean($_GET['category'] ?? '', 60);
    if ($category !== '' && !array_key_exists($category, mi_categories())) {
        mi_json(400, ['ok' => false, 'error' => 'Invalid category.']);
    }

    $rows = mi_fetch_rows($db, $category);
    $sum = 0.0;
    foreach ($rows as $r) {
        $sum += mi_num($r['extra_qty'] ?? 0);
    }

    mi_json(200, [
        'ok' => true,
        'rows' => $rows,
        'summary' => [
            'total_items' => count($rows),
            'total_extra' => round($sum, 3),
        ],
    ]);
}

if ($action === 'assign_mixed_items') {
    if ($method !== 'POST') {
        mi_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $target = mi_clean($_POST['target'] ?? 'packing', 30);
    if (!in_array($target, ['packing', 'planning'], true)) {
        $target = 'packing';
    }

    $sourceCategory = mi_clean($_POST['source_category'] ?? '', 60);
    $note = mi_clean($_POST['note'] ?? '', 255);
    $itemsRaw = $_POST['items'] ?? '[]';
    $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

    if (!is_array($items) || empty($items)) {
        mi_json(400, ['ok' => false, 'error' => 'No selected items found.']);
    }
    if (count($items) > 500) {
        mi_json(400, ['ok' => false, 'error' => 'Too many items selected. Maximum 500.']);
    }

    $compact = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $compact[] = [
            'id' => mi_clean($item['id'] ?? '', 80),
            'source_id' => (int) ($item['source_id'] ?? 0),
            'category' => mi_clean($item['category'] ?? '', 60),
            'item_name' => mi_clean($item['item_name'] ?? '', 255),
            'batch_no' => mi_clean($item['batch_no'] ?? '', 120),
            'extra_qty' => mi_num($item['extra_qty'] ?? 0),
            'unit_type' => mi_clean($item['unit_type'] ?? '', 20),
        ];
    }

    if (empty($compact)) {
        mi_json(400, ['ok' => false, 'error' => 'No valid selected rows found.']);
    }

    $itemsJson = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($itemsJson === false) {
        $itemsJson = '[]';
    }

    $assignmentCode = 'MIX-' . date('Ymd-His') . '-' . mt_rand(100, 999);
    $itemCount = count($compact);
    $createdBy = (int) ($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare("INSERT INTO mixed_item_assignments
        (assignment_code, target, source_category, item_count, items_json, note, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
    if (!$stmt) {
        mi_json(500, ['ok' => false, 'error' => 'Unable to prepare assignment insert.']);
    }

    $stmt->bind_param('sssissi', $assignmentCode, $target, $sourceCategory, $itemCount, $itemsJson, $note, $createdBy);
    if (!$stmt->execute()) {
        mi_json(500, ['ok' => false, 'error' => 'Unable to save assignment.']);
    }

    if ($target === 'packing') {
        $firstItem = $compact[0];
        $cat = strtolower(trim((string) ($firstItem['category'] ?? $sourceCategory)));
        $dept = 'POS Roll';
        if (strpos($cat, 'barcode') !== false) {
            $dept = 'Barcode';
        } elseif (strpos($cat, 'print') !== false || strpos($cat, 'label') !== false) {
            $dept = 'Label Slitting';
        } elseif (strpos($cat, 'one') !== false || strpos($cat, '1') !== false) {
            $dept = 'One Ply';
        } elseif (strpos($cat, 'two') !== false || strpos($cat, '2') !== false) {
            $dept = 'Two Ply';
        }

        $assignedChildRolls = [];
        $totalExtraQty = 0;
        $jobNames = [];
        $firstSize = '';
        $allSourceStockIds = [];

        foreach ($compact as $idx => $cItem) {
            $sourceStockId = (int) ($cItem['source_id'] ?? 0);
            if ($sourceStockId > 0 && !in_array($sourceStockId, $allSourceStockIds, true)) {
                $allSourceStockIds[] = $sourceStockId;
            }
            $width = '';
            $length = '';
            $gsm = '';
            $itemSize = '';
            $mixedBatchLabels = '';
            if ($sourceStockId > 0) {
                $sRes = $db->query("SELECT size, gsm, remarks FROM finished_goods_stock WHERE id = {$sourceStockId}");
                if ($sRes && $sRow = $sRes->fetch_assoc()) {
                    $itemSize = (string) ($sRow['size'] ?? '');
                    if ($firstSize === '')
                        $firstSize = $itemSize;
                    $gsm = (string) ($sRow['gsm'] ?? '');
                    $sParsed = json_decode($sRow['remarks'] ?? '{}', true) ?: [];
                    $sExtra = $sParsed['extra'] ?? [];
                    $width = (string) ($sExtra['width'] ?? '');
                    $length = (string) ($sExtra['length'] ?? '');
                    $mixedBatchLabels = trim((string) ($sExtra['mixed_batch_labels'] ?? ($sExtra['batch_no'] ?? '')));
                }
            }

            // Parse actual roll labels from mixed_batch_labels
            $rollLabels = [];
            if ($mixedBatchLabels !== '') {
                $parts = array_filter(array_map('trim', explode(',', $mixedBatchLabels)));
                if (!empty($parts)) {
                    $rollLabels = array_values($parts);
                }
            }
            if (empty($rollLabels)) {
                $fallbackRoll = (string) ($cItem['batch_no'] ?? '');
                $rollLabels = [$fallbackRoll !== '' ? $fallbackRoll : ($assignmentCode . '-' . ($idx + 1))];
            }

            $extraQty = (float) ($cItem['extra_qty'] ?? 0);
            $totalExtraQty += $extraQty;
            $name = (string) ($cItem['item_name'] ?? 'Repacking Job');
            if (!in_array($name, $jobNames, true)) {
                $jobNames[] = $name;
            }

            $eachRollQty = count($rollLabels) > 0 ? round($extraQty / count($rollLabels), 3) : $extraQty;

            foreach ($rollLabels as $rLabel) {
                $assignedChildRolls[] = [
                    'roll_no' => $rLabel,
                    'parent_roll_no' => $rLabel,
                    'width_mm' => $width,
                    'length_mtr' => $length,
                    'width' => $width,
                    'length' => $length,
                    'gsm' => $gsm,
                    'status' => 'Packing',
                    'production_qty' => $eachRollQty,
                    'available_qty' => $eachRollQty,
                    'job_no' => $assignmentCode,
                    'job_name' => $name,
                ];
            }
        }

        $consolidatedJobName = implode(', ', $jobNames);

        $jobExtra = [
            'client_name' => 'Mixed Item Pool',
            'job_name' => $consolidatedJobName,
            'plan_no' => $assignmentCode,
            'batch_no' => $assignmentCode,
            'loose_qty' => $totalExtraQty,
            'order_quantity' => $totalExtraQty,
            'production_quantity' => $totalExtraQty,
            'item_width' => $assignedChildRolls[0]['width'] ?? '',
            'item_length' => $assignedChildRolls[0]['length'] ?? '',
            'paper_size' => $firstSize,
            'gsm' => $assignedChildRolls[0]['gsm'] ?? '',
            'source_stock_id' => (int) ($compact[0]['source_id'] ?? 0),
            'source_stock_ids' => $allSourceStockIds,
            'repacking_assignment_code' => $assignmentCode,
            'is_repacking_job' => 1,
            'assigned_child_rolls' => $assignedChildRolls,
            'child_rolls' => $assignedChildRolls,
            'selected_rolls' => $assignedChildRolls,
        ];
        $jobExtraJson = json_encode($jobExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $jStmt = $db->prepare("INSERT INTO jobs (job_no, job_type, department, status, notes, extra_data, created_at, updated_at) VALUES (?, 'Repacking', ?, 'Packing', 'Loose item repacking request from Mixed Item Board', ?, NOW(), NOW())");
        if ($jStmt) {
            $jStmt->bind_param('sss', $assignmentCode, $dept, $jobExtraJson);
            $jStmt->execute();
        }
    }

    mi_json(200, [
        'ok' => true,
        'message' => 'Selected mixed items assigned successfully.',
        'assignment' => [
            'id' => (int) $stmt->insert_id,
            'code' => $assignmentCode,
            'target' => $target,
            'item_count' => $itemCount,
        ],
    ]);
}

if ($action === 'repack_mixed_items') {
    if ($method !== 'POST') {
        mi_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $itemsRaw = $_POST['items'] ?? '[]';
    $items = is_string($itemsRaw) ? json_decode($itemsRaw, true) : $itemsRaw;

    if (!is_array($items) || empty($items)) {
        mi_json(400, ['ok' => false, 'error' => 'No selected loose items found.']);
    }

    $db->begin_transaction();

    try {
        $repackedCount = 0;
        foreach ($items as $item) {
            $stockId = (int) ($item['source_id'] ?? ($item['id'] ?? 0));
            if ($stockId <= 0)
                continue;

            $stmt = $db->prepare("SELECT id, category, item_name, item_code, batch_no, size, unit, quantity, remarks FROM finished_goods_stock WHERE id = ? FOR UPDATE");
            if (!$stmt)
                continue;
            $stmt->bind_param('i', $stockId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row)
                continue;

            $parsed = json_decode($row['remarks'] ?? '{}', true) ?: [];
            $extra = $parsed['extra'] ?? [];
            $looseQty = (float) ($extra['loose_qty'] ?? 0);
            $perCarton = (float) ($extra['per_carton'] ?? 50);

            if ($looseQty <= 0)
                continue;

            // Compute full cartons formed from loose rolls
            $repackedCartons = $perCarton > 0 ? (int) floor($looseQty / $perCarton) : 0;
            $repackedQty = $perCarton > 0 ? ($repackedCartons * $perCarton) : $looseQty;

            if ($repackedQty > 0 || $looseQty > 0) {
                // If looseQty >= perCarton, convert to full carton, else clear loose qty into finished stock
                $transferQty = $repackedQty > 0 ? $repackedQty : $looseQty;
                $newQuantity = (float) ($row['quantity'] ?? 0) + $transferQty;
                if ($repackedCartons > 0) {
                    $extra['carton'] = (int) ($extra['carton'] ?? 0) + $repackedCartons;
                }
                $newLoose = max(0, $looseQty - $transferQty);
                $extra['loose_qty'] = $newLoose;
                $extra['mixed_extra_rolls'] = 0;
                $extra['extra_rolls'] = 0;
                $extra['extra_pcs'] = 0;
                if ($newLoose <= 0) {
                    $extra['mixed_enabled'] = 0;
                }
                $parsed['extra'] = $extra;

                $newRemarks = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $up = $db->prepare("UPDATE finished_goods_stock SET quantity = ?, closing_stock = ?, remarks = ? WHERE id = ?");
                if ($up) {
                    $up->bind_param('ddsi', $newQuantity, $newQuantity, $newRemarks, $stockId);
                    $up->execute();
                    $repackedCount++;
                }
            }
        }

        $db->commit();
        mi_json(200, ['ok' => true, 'message' => "Successfully repacked {$repackedCount} item(s) into full cartons and transferred to Finished Goods Inventory."]);
    } catch (Throwable $e) {
        $db->rollback();
        mi_json(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

mi_json(404, ['ok' => false, 'error' => 'Unknown action: ' . $action]);
