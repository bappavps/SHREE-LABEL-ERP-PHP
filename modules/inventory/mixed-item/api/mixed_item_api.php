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

function mi_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mi_clean($value, int $max = 255): string {
    $text = trim((string)$value);
    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function mi_num($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float)$value, 3);
}

function mi_can_operate(): bool {
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

function mi_require_access(): void {
    if (!mi_can_operate()) {
        mi_json(403, ['ok' => false, 'error' => 'Permission denied.']);
    }
}

function mi_parse_extra($remarks): array {
    $raw = trim((string)$remarks);
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

function mi_pick(array $extra, array $keys): string {
    foreach ($keys as $k) {
        if (!array_key_exists($k, $extra)) {
            continue;
        }
        $v = trim((string)$extra[$k]);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function mi_categories(): array {
    return [
        'pos_paper_roll' => 'POS & Paper Roll Extra',
        'one_ply' => '1 Ply Extra',
        'two_ply' => '2 Ply Extra',
        'barcode' => 'Barcode Extra',
        'printing_roll' => 'Printing Extra',
    ];
}

function mi_compute_extra(array $row, array $extra): array {
    $category = (string)($row['category'] ?? '');
    $quantity = mi_num($row['quantity'] ?? 0);

    if ($category === 'barcode') {
        $mixedEnabled = (int)($extra['mixed_enabled'] ?? 0) === 1;
        $rpc = (int)floor(mi_num(mi_pick($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton'])));

        if ($mixedEnabled) {
            $extraQty = max(0, mi_num($extra['mixed_extra_rolls'] ?? 0));
            $possible = max(0, (int)floor(mi_num($extra['mixed_cartons'] ?? 0)));
            return [
                'extra_qty' => $extraQty,
                'unit_type' => 'ROLL',
                'per_carton' => $rpc,
                'possible_cartons' => $possible,
            ];
        }

        $totalRoll = mi_num(mi_pick($extra, ['total_roll', 'total_rolls']));
        if ($totalRoll <= 0) {
            $pcsPerRoll = mi_num(mi_pick($extra, ['pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll']));
            if ($pcsPerRoll > 0 && $quantity > 0) {
                $totalRoll = ceil($quantity / $pcsPerRoll);
            }
        }

        $extraQty = $totalRoll;
        $possible = 0;
        if ($rpc > 0 && $totalRoll > 0) {
            $possible = (int)floor($totalRoll / $rpc);
            $extraQty = fmod($totalRoll, $rpc);
        }

        return [
            'extra_qty' => max(0, $extraQty),
            'unit_type' => 'ROLL',
            'per_carton' => $rpc,
            'possible_cartons' => $possible,
        ];
    }

    $mixedEnabled = (int)($extra['mixed_enabled'] ?? 0) === 1;
    if ($mixedEnabled) {
        $rpc = mi_num(mi_pick($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton']));
        $extraQty = max(0, mi_num($extra['mixed_extra_rolls'] ?? 0));
        $possible = max(0, (int)floor(mi_num($extra['mixed_cartons'] ?? 0)));
        return [
            'extra_qty' => $extraQty,
            'unit_type' => 'PCS',
            'per_carton' => $rpc,
            'possible_cartons' => $possible,
        ];
    }

    $perCarton = mi_num(mi_pick($extra, ['per_carton']));
    $extraQty = $quantity;
    $possible = 0;
    if ($perCarton > 0 && $quantity > 0) {
        $possible = (int)floor($quantity / $perCarton);
        $extraQty = fmod($quantity, $perCarton);
    }

    return [
        'extra_qty' => max(0, $extraQty),
        'unit_type' => 'PCS',
        'per_carton' => $perCarton,
        'possible_cartons' => $possible,
    ];
}

function mi_fetch_rows(mysqli $db, string $category = ''): array {
    $map = mi_categories();
    $allCats = array_keys($map);

    $sql = "SELECT id, category, sub_type, item_name, item_code, size, gsm, quantity, unit, location, batch_no, date, remarks, created_by, created_at
            FROM finished_goods_stock
            WHERE category IN ('pos_paper_roll','one_ply','two_ply','barcode','printing_roll')
            ORDER BY id DESC";
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $cat = (string)($row['category'] ?? '');
        if ($category !== '' && $cat !== $category) {
            continue;
        }

        $extra = mi_parse_extra($row['remarks'] ?? '');
        $calc = mi_compute_extra($row, $extra);
        $extraQty = mi_num($calc['extra_qty'] ?? 0);
        if ($extraQty <= 0) {
            continue;
        }

        $width = mi_pick($extra, ['width']);
        $length = mi_pick($extra, ['length']);
        $perCarton = $calc['per_carton'] ?? 0;
        $possible = (int)($calc['possible_cartons'] ?? 0);

        $rows[] = [
            'id' => $cat . '-' . (int)$row['id'],
            'source_id' => (int)$row['id'],
            'category' => $cat,
            'category_label' => $map[$cat] ?? $cat,
            'item_name' => (string)($row['item_name'] ?? ''),
            'item_code' => (string)($row['item_code'] ?? ''),
            'size' => (string)($row['size'] ?? ''),
            'gsm' => (string)($row['gsm'] ?? ''),
            'batch_no' => (string)($row['batch_no'] ?? ''),
            'date' => (string)($row['date'] ?? ''),
            'total_qty' => mi_num($row['quantity'] ?? 0),
            'extra_qty' => $extraQty,
            'unit_type' => (string)($calc['unit_type'] ?? 'PCS'),
            'per_carton' => mi_num($perCarton),
            'possible_cartons' => $possible,
            'width' => $width,
            'length' => $length,
            'remarks' => (string)($row['remarks'] ?? ''),
        ];
    }

    return $rows;
}

function mi_ensure_assignment_table(mysqli $db): void {
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

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_REQUEST['action'] ?? ''));
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
        $cat = (string)($row['category'] ?? '');
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
            'source_id' => (int)($item['source_id'] ?? 0),
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
    $createdBy = (int)($_SESSION['user_id'] ?? 0);

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

    mi_json(200, [
        'ok' => true,
        'message' => 'Selected mixed items assigned successfully.',
        'assignment' => [
            'id' => (int)$stmt->insert_id,
            'code' => $assignmentCode,
            'target' => $target,
            'item_count' => $itemCount,
        ],
    ]);
}

mi_json(404, ['ok' => false, 'error' => 'Unknown action: ' . $action]);
