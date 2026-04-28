<?php
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../includes/functions.php';

// Set JSON header before auth_check so any redirect still returns JSON to JS callers.
header('Content-Type: application/json; charset=utf-8');

// Override auth_check redirect behaviour for this API endpoint:
// if session is missing or access is denied, return JSON 401/403 instead of HTML redirect.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated. Please log in.']);
    exit;
}

require_once __DIR__ . '/../../../../includes/auth_check.php';

$db = getDB();

function fg_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fg_is_admin(): bool {
    return isAdmin();
}

function fg_require_admin(): void {
    if (!fg_is_admin()) {
        fg_json(403, ['ok' => false, 'error' => 'Admin permission required.']);
    }
}

function fg_can_operate_finished_stock(): bool {
    if (fg_is_admin()) {
        return true;
    }
    if (function_exists('canAccessPath')) {
        if (canAccessPath('/modules/inventory/finished/index.php')) {
            return true;
        }
        if (canAccessPath('/modules/packing/index.php')) {
            return true;
        }
    }
    return false;
}

function fg_require_stock_operator(): void {
    if (!fg_can_operate_finished_stock()) {
        fg_json(403, ['ok' => false, 'error' => 'Permission denied for finished stock operation.']);
    }
}

function fg_clean_text($value, int $max = 255): string {
    $text = trim((string)$value);
    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function fg_decimal($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float)$value, 3);
}

function fg_parse_date($value): ?string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $patterns = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y'];
    foreach ($patterns as $p) {
        $dt = DateTime::createFromFormat($p, $raw);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

function fg_mixed_parse_extra($remarks): array {
    $raw = trim((string)$remarks);
    if ($raw === '' || $raw[0] !== '{') {
        return [];
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return [];
    }
    $extra = $parsed['extra'] ?? [];
    return is_array($extra) ? $extra : [];
}

function fg_mixed_pick(array $extra, array $keys): string {
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

function fg_mixed_extra_qty(string $category, float $quantity, array $extra): float {
    if (!in_array($category, ['pos_paper_roll', 'one_ply', 'two_ply', 'barcode', 'printing_roll'], true)) {
        return 0.0;
    }

    if ($category === 'barcode') {
        $mixedEnabled = (int)($extra['mixed_enabled'] ?? 0) === 1;
        $rpc = (int)floor(fg_decimal(fg_mixed_pick($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton'])));

        if ($mixedEnabled) {
            return max(0.0, fg_decimal($extra['mixed_extra_rolls'] ?? 0));
        }

        $totalRoll = fg_decimal(fg_mixed_pick($extra, ['total_roll', 'total_rolls']));
        if ($totalRoll <= 0) {
            $pcsPerRoll = fg_decimal(fg_mixed_pick($extra, ['pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll']));
            if ($pcsPerRoll > 0 && $quantity > 0) {
                $totalRoll = ceil($quantity / $pcsPerRoll);
            }
        }

        if ($rpc > 0 && $totalRoll > 0) {
            return max(0.0, (float)fmod($totalRoll, $rpc));
        }
        return max(0.0, $totalRoll);
    }

    $mixedEnabled = (int)($extra['mixed_enabled'] ?? 0) === 1;
    if ($mixedEnabled) {
        return max(0.0, fg_decimal($extra['mixed_extra_rolls'] ?? 0));
    }

    $perCarton = fg_decimal(fg_mixed_pick($extra, ['per_carton']));
    if ($perCarton > 0 && $quantity > 0) {
        return max(0.0, (float)fmod($quantity, $perCarton));
    }
    return max(0.0, $quantity);
}

function fg_period_bounds(string $period): array {
    $today = new DateTime('today');
    $start = clone $today;
    $end = clone $today;

    if ($period === 'day') {
        // today to today
    } elseif ($period === 'week') {
        $start = new DateTime('monday this week');
        $end = new DateTime('sunday this week');
    } elseif ($period === 'month') {
        $start = new DateTime('first day of this month');
        $end = new DateTime('last day of this month');
    } elseif ($period === 'year') {
        $start = new DateTime(date('Y-01-01'));
        $end = new DateTime(date('Y-12-31'));
    } elseif ($period === 'last_3_months') {
        $start = new DateTime('first day of -2 month');
        $end = clone $today;
    } else {
        $start = new DateTime('first day of this month');
        $end = new DateTime('last day of this month');
    }

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function fg_get_payload(): array {
    $payload = $_POST;
    if (!empty($payload)) {
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fg_ensure_table(mysqli $db): void {
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
        fg_json(500, ['ok' => false, 'error' => 'Unable to initialize finished goods table: ' . $db->error]);
    }

    $dispatchSql = "CREATE TABLE IF NOT EXISTS finished_goods_dispatch_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id INT UNSIGNED NOT NULL,
        category VARCHAR(60) NOT NULL,
        deducted_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        before_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        after_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        reference_no VARCHAR(120) NOT NULL DEFAULT '',
        reason VARCHAR(255) NOT NULL DEFAULT '',
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_fgdl_stock (stock_id),
        KEY idx_fgdl_category (category),
        KEY idx_fgdl_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($dispatchSql)) {
        fg_json(500, ['ok' => false, 'error' => 'Unable to initialize dispatch log table: ' . $db->error]);
    }

    // Ensure carton minimum threshold column exists.
    $check = $db->query("SHOW COLUMNS FROM carton_items LIKE 'min_qty'");
    $exists = $check && $check->num_rows > 0;
    if ($check) {
        $check->close();
    }
    if (!$exists) {
        $db->query("ALTER TABLE carton_items ADD COLUMN min_qty INT UNSIGNED NOT NULL DEFAULT 0 AFTER item_name");
    }
}

function fg_fetch_carton_min_rows(mysqli $db): array {
    $sql = "SELECT id, item_name, COALESCE(min_qty, 0) AS min_qty
            FROM carton_items
            ORDER BY item_name ASC";
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $size = trim((string)($row['item_name'] ?? ''));
        if ($size === '') {
            continue;
        }
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'size' => $size,
            'min_qty' => max(0, (int)floor((float)($row['min_qty'] ?? 0))),
        ];
    }
    return $rows;
}

function fg_insert_row(mysqli $db, array $row, int $userId): bool {
    $category = fg_clean_text($row['category'] ?? '', 60);
    if ($category === '') {
        return false;
    }

    $subType = fg_clean_text($row['sub_type'] ?? '', 120);
    $itemName = fg_clean_text($row['item_name'] ?? '', 255);
    $itemCode = fg_clean_text($row['item_code'] ?? '', 120);
    $size = fg_clean_text($row['size'] ?? '', 120);
    $gsm = fg_clean_text($row['gsm'] ?? '', 60);
    $quantity = fg_decimal($row['quantity'] ?? 0);
    $unit = fg_clean_text($row['unit'] ?? '', 30);
    $location = fg_clean_text($row['location'] ?? '', 120);
    $batchNo = fg_clean_text($row['batch_no'] ?? '', 120);
    $dateValue = fg_parse_date($row['date'] ?? '');
    $remarks = trim((string)($row['remarks'] ?? ''));

    $stmt = $db->prepare("INSERT INTO finished_goods_stock
        (category, sub_type, item_name, item_code, size, gsm, quantity, unit, location, batch_no, date, remarks, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ssssssdsssssi',
        $category,
        $subType,
        $itemName,
        $itemCode,
        $size,
        $gsm,
        $quantity,
        $unit,
        $location,
        $batchNo,
        $dateValue,
        $remarks,
        $userId
    );

    return $stmt->execute();
}

function fg_resolve_row_id(mysqli $db, array $payload): int {
    $id = (int)($payload['id'] ?? 0);
    if ($id > 0) {
        return $id;
    }

    $category = fg_clean_text($payload['category'] ?? '', 60);
    if ($category === '') {
        return 0;
    }

    $itemCode = fg_clean_text($payload['item_code'] ?? '', 120);
    $subType = fg_clean_text($payload['sub_type'] ?? '', 120);
    $itemName = fg_clean_text($payload['item_name'] ?? '', 255);
    $size = fg_clean_text($payload['size'] ?? '', 120);
    $gsm = fg_clean_text($payload['gsm'] ?? '', 60);

    // Match on stable identity fields only.
    // Do not match using volatile fields like batch/location/date to keep add behavior consistent.
    $where = [];
    $types = '';
    $params = [];

    $where[] = "LOWER(TRIM(COALESCE(category, ''))) = ?";
    $types .= 's';
    $params[] = strtolower($category);

    if ($itemCode !== '') {
        $where[] = "LOWER(TRIM(COALESCE(item_code, ''))) = ?";
        $types .= 's';
        $params[] = strtolower($itemCode);
    }
    if ($subType !== '') {
        $where[] = "LOWER(TRIM(COALESCE(sub_type, ''))) = ?";
        $types .= 's';
        $params[] = strtolower($subType);
    }
    if ($itemName !== '') {
        $where[] = "LOWER(TRIM(COALESCE(item_name, ''))) = ?";
        $types .= 's';
        $params[] = strtolower($itemName);
    }
    if ($size !== '') {
        $where[] = "LOWER(TRIM(COALESCE(size, ''))) = ?";
        $types .= 's';
        $params[] = strtolower($size);
    }
    if ($gsm !== '') {
        $where[] = "LOWER(TRIM(COALESCE(gsm, ''))) = ?";
        $types .= 's';
        $params[] = strtolower($gsm);
    }

    $sql = 'SELECT id FROM finished_goods_stock WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['id'] ?? 0);
}

function fg_apply_deduction(mysqli $db, int $id, float $deductQty, bool $strictMode, int $userId, string $referenceNo = '', string $reason = ''): array {
    $stmt = $db->prepare('SELECT id, category, quantity FROM finished_goods_stock WHERE id = ? FOR UPDATE');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to prepare quantity lock query.'];
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['ok' => false, 'error' => 'Stock row not found.'];
    }

    $currentQty = (float)($row['quantity'] ?? 0);
    $category = (string)($row['category'] ?? '');
    if ($deductQty <= 0) {
        return ['ok' => false, 'error' => 'Deduct quantity must be greater than zero.'];
    }
    if ($strictMode && $currentQty < $deductQty) {
        return ['ok' => false, 'error' => 'Insufficient stock for strict deduction.', 'current_qty' => $currentQty];
    }

    $actualDeducted = $strictMode ? $deductQty : min($currentQty, $deductQty);
    $newQty = max(0, $currentQty - $actualDeducted);

    $update = $db->prepare('UPDATE finished_goods_stock SET quantity = ? WHERE id = ?');
    if (!$update) {
        return ['ok' => false, 'error' => 'Unable to prepare stock update query.'];
    }
    $update->bind_param('di', $newQty, $id);
    if (!$update->execute()) {
        return ['ok' => false, 'error' => 'Unable to deduct stock quantity.'];
    }

    $log = $db->prepare('INSERT INTO finished_goods_dispatch_log (stock_id, category, deducted_qty, before_qty, after_qty, reference_no, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$log) {
        return ['ok' => false, 'error' => 'Unable to prepare dispatch log insert.'];
    }
    $log->bind_param('isdddssi', $id, $category, $actualDeducted, $currentQty, $newQty, $referenceNo, $reason, $userId);
    if (!$log->execute()) {
        return ['ok' => false, 'error' => 'Unable to write dispatch log.'];
    }

    return [
        'ok' => true,
        'id' => $id,
        'before_qty' => $currentQty,
        'deducted_qty' => $actualDeducted,
        'after_qty' => $newQty,
        'reference_no' => $referenceNo,
        'reason' => $reason,
    ];
}

fg_ensure_table($db);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_REQUEST['action'] ?? ''));
if ($action === '') {
    fg_json(400, ['ok' => false, 'error' => 'Missing action.']);
}

if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        fg_json(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($action === 'get_prc_suggestions') {
    $res = $db->query("SELECT id, item_name, item, width_mm, length_mtr, paper_type, gsm, dia, core, size, core_type FROM paper_roll_concept ORDER BY sl_no ASC, id ASC");
    $prcRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    fg_json(200, ['ok' => true, 'rows' => $prcRows]);
}

if ($action === 'get_tab_counts') {
    $categories = ['pos_paper_roll', 'one_ply', 'two_ply', 'barcode', 'printing_roll', 'ribbon', 'core', 'carton'];
    $counts = [];
    foreach ($categories as $cat) {
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM finished_goods_stock WHERE category = ?");
        $stmt->bind_param('s', $cat);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $counts[$cat] = (int)($row['cnt'] ?? 0);
    }
    fg_json(200, ['ok' => true, 'counts' => $counts]);
}

if ($action === 'get_period_report') {
    $category = fg_clean_text($_GET['category'] ?? '', 60);
    if ($category === '') {
        fg_json(400, ['ok' => false, 'error' => 'Category is required.']);
    }

    $period = fg_clean_text($_GET['period'] ?? 'month', 40);
    $allowed = ['day', 'week', 'month', 'year', 'last_3_months'];
    if (!in_array($period, $allowed, true)) {
        $period = 'month';
    }

    [$fromDate, $toDate] = fg_period_bounds($period);

    // Inward during period (using stock row date, fallback to created_at date)
    $inwardStmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) AS inward_qty
        FROM finished_goods_stock
        WHERE category = ?
          AND DATE(COALESCE(date, created_at)) BETWEEN ? AND ?");
    $inwardStmt->bind_param('sss', $category, $fromDate, $toDate);
    $inwardStmt->execute();
    $inward = (float)(($inwardStmt->get_result()->fetch_assoc() ?: ['inward_qty' => 0])['inward_qty'] ?? 0);

    // Dispatch during period from deduction logs
    $dispatchStmt = $db->prepare("SELECT COALESCE(SUM(deducted_qty),0) AS dispatch_qty
        FROM finished_goods_dispatch_log
        WHERE category = ?
          AND DATE(created_at) BETWEEN ? AND ?");
    $dispatchStmt->bind_param('sss', $category, $fromDate, $toDate);
    $dispatchStmt->execute();
    $dispatch = (float)(($dispatchStmt->get_result()->fetch_assoc() ?: ['dispatch_qty' => 0])['dispatch_qty'] ?? 0);

    // Closing stock (current)
    $closingStmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) AS closing_qty
        FROM finished_goods_stock
        WHERE category = ?");
    $closingStmt->bind_param('s', $category);
    $closingStmt->execute();
    $closing = (float)(($closingStmt->get_result()->fetch_assoc() ?: ['closing_qty' => 0])['closing_qty'] ?? 0);

    // Estimated opening stock for period
    $opening = $closing + $dispatch - $inward;

    $monthRows = [];
    if ($period === 'last_3_months') {
        $monthlyInward = [];
        $monthlyDispatch = [];

        $mi = $db->prepare("SELECT DATE_FORMAT(DATE(COALESCE(date, created_at)),'%Y-%m') AS ym, COALESCE(SUM(quantity),0) AS qty
            FROM finished_goods_stock
            WHERE category = ?
              AND DATE(COALESCE(date, created_at)) BETWEEN ? AND ?
            GROUP BY ym
            ORDER BY ym ASC");
        $mi->bind_param('sss', $category, $fromDate, $toDate);
        $mi->execute();
        $miRes = $mi->get_result();
        while ($r = $miRes->fetch_assoc()) {
            $monthlyInward[(string)$r['ym']] = (float)($r['qty'] ?? 0);
        }

        $md = $db->prepare("SELECT DATE_FORMAT(DATE(created_at),'%Y-%m') AS ym, COALESCE(SUM(deducted_qty),0) AS qty
            FROM finished_goods_dispatch_log
            WHERE category = ?
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY ym
            ORDER BY ym ASC");
        $md->bind_param('sss', $category, $fromDate, $toDate);
        $md->execute();
        $mdRes = $md->get_result();
        while ($r2 = $mdRes->fetch_assoc()) {
            $monthlyDispatch[(string)$r2['ym']] = (float)($r2['qty'] ?? 0);
        }

        $cursor = new DateTime($fromDate);
        $cursor->modify('first day of this month');
        $endCursor = new DateTime($toDate);
        $endCursor->modify('first day of this month');

        while ($cursor <= $endCursor) {
            $ym = $cursor->format('Y-m');
            $monthRows[] = [
                'month' => $ym,
                'inward' => (float)($monthlyInward[$ym] ?? 0),
                'dispatch' => (float)($monthlyDispatch[$ym] ?? 0),
            ];
            $cursor->modify('+1 month');
        }
    }

    fg_json(200, [
        'ok' => true,
        'report' => [
            'period' => $period,
            'from' => $fromDate,
            'to' => $toDate,
            'opening_stock' => round($opening, 3),
            'inward_stock' => round($inward, 3),
            'dispatch_qty' => round($dispatch, 3),
            'closing_stock' => round($closing, 3),
            'months' => $monthRows,
        ],
    ]);
}

if ($action === 'get_stock') {
    $category = fg_clean_text($_GET['category'] ?? '', 60);
    if ($category === '') {
        fg_json(400, ['ok' => false, 'error' => 'Category is required.']);
    }

    $stmt = $db->prepare("SELECT id, category, sub_type, item_name, item_code, size, gsm, quantity, dispatch_qty_total, closing_stock, unit, location, batch_no, date, remarks, created_by, created_at
        FROM finished_goods_stock
        WHERE category = ?
        ORDER BY id DESC");
    $stmt->bind_param('s', $category);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if ($category === 'carton') {
        $minRows = fg_fetch_carton_min_rows($db);
        $minByKey = [];
        $idByKey = [];
        $labelByKey = [];
        foreach ($minRows as $mrow) {
            $mSize = trim((string)($mrow['size'] ?? ''));
            if ($mSize === '') {
                continue;
            }
            $mKey = strtolower($mSize);
            $minByKey[$mKey] = (int)($mrow['min_qty'] ?? 0);
            $idByKey[$mKey] = (int)($mrow['id'] ?? 0);
            $labelByKey[$mKey] = $mSize;
        }

        $qtyByKey = [];
        $presentKeys = [];
        foreach ($rows as $row) {
            $sizeText = trim((string)($row['size'] ?? $row['item_name'] ?? ''));
            if ($sizeText === '') {
                continue;
            }
            $key = strtolower($sizeText);
            $presentKeys[$key] = true;
            if (!isset($labelByKey[$key])) {
                $labelByKey[$key] = $sizeText;
            }
            $qtyByKey[$key] = ($qtyByKey[$key] ?? 0.0) + fg_decimal($row['quantity'] ?? 0);
        }

        foreach ($rows as $idx => $row) {
            $sizeText = trim((string)($row['size'] ?? $row['item_name'] ?? ''));
            $key = strtolower($sizeText);
            $minQty = (int)($minByKey[$key] ?? 0);
            $aggQty = (float)($qtyByKey[$key] ?? 0.0);
            $rows[$idx]['carton_item_id'] = (int)($idByKey[$key] ?? 0);
            $rows[$idx]['min_qty'] = $minQty;
            $rows[$idx]['stock_status'] = ($minQty > 0 && $aggQty < $minQty) ? 'Low Quantity' : 'In Stock';
        }

        foreach ($minByKey as $key => $minQty) {
            if (isset($presentKeys[$key])) {
                continue;
            }
            $sizeLabel = (string)($labelByKey[$key] ?? $key);
            $rows[] = [
                'id' => (int)($idByKey[$key] ?? 0),
                'carton_item_id' => (int)($idByKey[$key] ?? 0),
                'category' => 'carton',
                'sub_type' => '',
                'item_name' => $sizeLabel,
                'item_code' => '',
                'size' => $sizeLabel,
                'gsm' => '',
                'quantity' => 0,
                'dispatch_qty_total' => 0,
                'closing_stock' => 0,
                'unit' => 'PCS',
                'location' => '',
                'batch_no' => '',
                'date' => '',
                'remarks' => '',
                'created_by' => 0,
                'created_at' => '',
                'min_qty' => (int)$minQty,
                'stock_status' => ((int)$minQty > 0) ? 'Low Quantity' : 'In Stock',
            ];
        }
    }

    fg_json(200, ['ok' => true, 'rows' => $rows]);
}

if ($action === 'set_carton_min_qty') {
    fg_require_admin();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $id = (int)($payload['id'] ?? 0);
    $size = fg_clean_text($payload['size'] ?? '', 120);
    $minQty = max(0, (int)($payload['min_qty'] ?? 0));

    if ($id <= 0 && $size === '') {
        fg_json(400, ['ok' => false, 'error' => 'Carton item id or size is required.']);
    }

    $sizeKey = strtolower(trim($size));
    $isSaved = false;

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE carton_items SET min_qty = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to prepare carton minimum update.']);
        }
        $stmt->bind_param('ii', $minQty, $id);
        if (!$stmt->execute()) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to save carton minimum.']);
        }

        if ($stmt->affected_rows > 0) {
            $isSaved = true;
        } else {
            // If value remained unchanged, row still exists and save is valid.
            $existsStmt = $db->prepare("SELECT id FROM carton_items WHERE id = ? LIMIT 1");
            if ($existsStmt) {
                $existsStmt->bind_param('i', $id);
                $existsStmt->execute();
                $existsRow = $existsStmt->get_result()->fetch_assoc();
                $isSaved = (bool)$existsRow;
                $existsStmt->close();
            }
        }
        $stmt->close();

        // If id did not map to carton_items (e.g., stock id was sent), fallback by size.
        if (!$isSaved && $sizeKey === '') {
            fg_json(400, ['ok' => false, 'error' => 'Invalid carton item id for MOQ update.']);
        }
    }

    if (!$isSaved) {
        $sel = $db->prepare("SELECT id FROM carton_items WHERE LOWER(TRIM(item_name)) = ? LIMIT 1");
        if (!$sel) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to prepare carton lookup.']);
        }
        $sel->bind_param('s', $sizeKey);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        if ($row) {
            $foundId = (int)$row['id'];
            $up = $db->prepare("UPDATE carton_items SET min_qty = ? WHERE id = ? LIMIT 1");
            if (!$up) {
                fg_json(500, ['ok' => false, 'error' => 'Unable to prepare carton minimum update.']);
            }
            $up->bind_param('ii', $minQty, $foundId);
            if (!$up->execute()) {
                fg_json(500, ['ok' => false, 'error' => 'Unable to save carton minimum.']);
            }
            $up->close();
            $isSaved = true;
        } else {
            $ins = $db->prepare("INSERT INTO carton_items (item_name, min_qty, status) VALUES (?, ?, 'ACTIVE')");
            if (!$ins) {
                fg_json(500, ['ok' => false, 'error' => 'Unable to prepare carton item insert.']);
            }
            $ins->bind_param('si', $size, $minQty);
            if (!$ins->execute()) {
                fg_json(500, ['ok' => false, 'error' => 'Unable to create carton item.']);
            }
            $ins->close();
            $isSaved = true;
        }
    }

    fg_json(200, ['ok' => true, 'message' => 'Carton minimum saved successfully.']);
}

if ($action === 'get_summary' || $action === 'summary') {
    $category = fg_clean_text($_GET['category'] ?? '', 60);
    if ($category === '') {
        fg_json(400, ['ok' => false, 'error' => 'Category is required.']);
    }

    $stmt = $db->prepare("SELECT id, quantity, remarks
        FROM finished_goods_stock
        WHERE category = ?");
    $stmt->bind_param('s', $category);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $totalItems = count($rows);
    $totalQuantity = 0.0;
    $mixedExtra = 0.0;

    foreach ($rows as $row) {
        $qty = fg_decimal($row['quantity'] ?? 0);
        $totalQuantity += $qty;
        $extra = fg_mixed_parse_extra($row['remarks'] ?? '');
        $mixedExtra += fg_mixed_extra_qty($category, $qty, $extra);
    }

    $effectiveQuantity = max(0.0, $totalQuantity - $mixedExtra);

    fg_json(200, [
        'ok' => true,
        'summary' => [
            'total_items' => (int)$totalItems,
            'total_quantity' => round($effectiveQuantity, 3),
            'raw_total_quantity' => round($totalQuantity, 3),
            'mixed_extra_quantity' => round($mixedExtra, 3),
        ],
    ]);
}

if ($action === 'add_stock' || $action === 'add') {
    fg_require_stock_operator();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $addQty = fg_decimal($payload['quantity'] ?? 0);

    // Check if a matching row already exists; if so, accumulate quantity.
    $existingId = fg_resolve_row_id($db, $payload);
    if ($existingId > 0 && $addQty > 0) {
        $upd = $db->prepare("UPDATE finished_goods_stock SET quantity = quantity + ? WHERE id = ?");
        if (!$upd) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to prepare stock accumulate query.']);
        }
        $upd->bind_param('di', $addQty, $existingId);
        if (!$upd->execute()) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to accumulate stock quantity.']);
        }
        fg_json(200, ['ok' => true, 'message' => 'Stock quantity added successfully.']);
    }

    if (!fg_insert_row($db, $payload, $userId)) {
        fg_json(500, ['ok' => false, 'error' => 'Unable to add stock entry.']);
    }

    fg_json(200, ['ok' => true, 'message' => 'Stock entry added successfully.']);
}

if ($action === 'update_stock') {
    fg_require_stock_operator();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        fg_json(400, ['ok' => false, 'error' => 'Invalid row id.']);
    }

    $category = fg_clean_text($payload['category'] ?? '', 60);
    if ($category === '') {
        fg_json(400, ['ok' => false, 'error' => 'Category is required.']);
    }

    $subType = fg_clean_text($payload['sub_type'] ?? '', 120);
    $itemName = fg_clean_text($payload['item_name'] ?? '', 255);
    $itemCode = fg_clean_text($payload['item_code'] ?? '', 120);
    $size = fg_clean_text($payload['size'] ?? '', 120);
    $gsm = fg_clean_text($payload['gsm'] ?? '', 60);
    $quantity = fg_decimal($payload['quantity'] ?? 0);
    $unit = fg_clean_text($payload['unit'] ?? '', 30);
    $location = fg_clean_text($payload['location'] ?? '', 120);
    $batchNo = fg_clean_text($payload['batch_no'] ?? '', 120);
    $dateValue = fg_parse_date($payload['date'] ?? '');
    $remarks = trim((string)($payload['remarks'] ?? ''));

    $targetId = $id;

    // Carton matrix can send carton_items.id for synthetic rows.
    // Resolve to real finished_goods_stock row by carton size, or insert if missing.
    if ($category === 'carton') {
        $isRealCartonRow = false;
        $checkStmt = $db->prepare("SELECT id FROM finished_goods_stock WHERE id = ? AND category = 'carton' LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param('i', $targetId);
            $checkStmt->execute();
            $isRealCartonRow = (bool)$checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
        }

        if (!$isRealCartonRow) {
            $targetId = 0;
            $sizeKey = strtolower(trim($size !== '' ? $size : $itemName));
            if ($sizeKey !== '') {
                $resolveStmt = $db->prepare("SELECT id
                    FROM finished_goods_stock
                    WHERE category = 'carton'
                      AND LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))) = ?
                    ORDER BY id DESC
                    LIMIT 1");
                if ($resolveStmt) {
                    $resolveStmt->bind_param('s', $sizeKey);
                    $resolveStmt->execute();
                    $resolved = $resolveStmt->get_result()->fetch_assoc();
                    $resolveStmt->close();
                    if ($resolved) {
                        $targetId = (int)($resolved['id'] ?? 0);
                    }
                }
            }
        }

        if ($targetId <= 0) {
            $insertPayload = [
                'category' => $category,
                'sub_type' => $subType,
                'item_name' => ($itemName !== '' ? $itemName : $size),
                'item_code' => $itemCode,
                'size' => $size,
                'gsm' => $gsm,
                'quantity' => $quantity,
                'unit' => ($unit !== '' ? $unit : 'PCS'),
                'location' => $location,
                'batch_no' => $batchNo,
                'date' => $dateValue,
                'remarks' => $remarks,
            ];

            if (!fg_insert_row($db, $insertPayload, $userId)) {
                fg_json(500, ['ok' => false, 'error' => 'Unable to create carton stock entry.']);
            }

            fg_json(200, ['ok' => true, 'message' => 'Stock entry updated successfully.']);
        }
    }

    $stmt = $db->prepare("UPDATE finished_goods_stock
        SET category = ?, sub_type = ?, item_name = ?, item_code = ?, size = ?, gsm = ?, quantity = ?, unit = ?, location = ?, batch_no = ?, date = ?, remarks = ?,
            dispatch_qty_total = 0, closing_stock = 0
        WHERE id = ?");
    $stmt->bind_param(
        'ssssssdsssssi',
        $category,
        $subType,
        $itemName,
        $itemCode,
        $size,
        $gsm,
        $quantity,
        $unit,
        $location,
        $batchNo,
        $dateValue,
        $remarks,
        $targetId
    );

    if (!$stmt->execute()) {
        fg_json(500, ['ok' => false, 'error' => 'Unable to update stock entry.']);
    }

    // For carton: zero-out all other rows for the same size so aggregate stays correct.
    if ($category === 'carton' && $targetId > 0) {
        $sizeForMerge = strtolower(trim($size !== '' ? $size : $itemName));
        if ($sizeForMerge !== '') {
            $zeroStmt = $db->prepare("UPDATE finished_goods_stock
                SET quantity = 0
                WHERE id != ?
                  AND category = 'carton'
                  AND LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))) = ?");
            if ($zeroStmt) {
                $zeroStmt->bind_param('is', $targetId, $sizeForMerge);
                $zeroStmt->execute();
                $zeroStmt->close();
            }
        }
    }

    if ($stmt->affected_rows < 1) {
        $existsStmt = $db->prepare('SELECT id FROM finished_goods_stock WHERE id = ? LIMIT 1');
        if (!$existsStmt) {
            fg_json(500, ['ok' => false, 'error' => 'Unable to verify stock row.']);
        }
        $existsStmt->bind_param('i', $targetId);
        $existsStmt->execute();
        $exists = $existsStmt->get_result()->fetch_assoc();
        $existsStmt->close();
        if (!$exists) {
            fg_json(404, ['ok' => false, 'error' => 'Stock row not found.']);
        }
    }

    fg_json(200, ['ok' => true, 'message' => 'Stock entry updated successfully.']);
}

if ($action === 'delete_stock') {
    fg_require_stock_operator();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        fg_json(400, ['ok' => false, 'error' => 'Invalid row id.']);
    }

    $category = strtolower(fg_clean_text($payload['category'] ?? '', 60));
    $size = fg_clean_text($payload['size'] ?? '', 120);
    $sizeKey = strtolower(trim($size));

    try {
        $db->begin_transaction();

        if ($category === 'carton' && $sizeKey !== '') {
            $deletedStock = 0;
            $deletedCartonItem = 0;

            // Remove child dispatch logs first to satisfy FK constraints.
            $delLog = $db->prepare("DELETE l FROM finished_goods_dispatch_log l
                INNER JOIN finished_goods_stock s ON s.id = l.stock_id
                WHERE s.category = 'carton'
                  AND LOWER(TRIM(COALESCE(NULLIF(s.size, ''), s.item_name, ''))) = ?");
            if (!$delLog) {
                throw new Exception('Unable to prepare carton dispatch-log delete query.');
            }
            $delLog->bind_param('s', $sizeKey);
            $delLog->execute();
            $delLog->close();

            $delStock = $db->prepare("DELETE FROM finished_goods_stock
                WHERE category = 'carton'
                  AND LOWER(TRIM(COALESCE(NULLIF(size, ''), item_name, ''))) = ?");
            if (!$delStock) {
                throw new Exception('Unable to prepare carton stock delete query.');
            }
            $delStock->bind_param('s', $sizeKey);
            $delStock->execute();
            $deletedStock = (int)$delStock->affected_rows;
            $delStock->close();

            // carton_stock has FK to carton_items (ON DELETE RESTRICT), so remove child rows first.
            $delCartonStock = $db->prepare("DELETE cs FROM carton_stock cs
                INNER JOIN carton_items ci ON ci.id = cs.item_id
                WHERE LOWER(TRIM(ci.item_name)) = ?");
            if (!$delCartonStock) {
                throw new Exception('Unable to prepare carton stock-ledger delete query.');
            }
            $delCartonStock->bind_param('s', $sizeKey);
            $delCartonStock->execute();
            $delCartonStock->close();

            $delMin = $db->prepare("DELETE FROM carton_items WHERE LOWER(TRIM(item_name)) = ?");
            if (!$delMin) {
                throw new Exception('Unable to prepare carton item delete query.');
            }
            $delMin->bind_param('s', $sizeKey);
            $delMin->execute();
            $deletedCartonItem = (int)$delMin->affected_rows;
            $delMin->close();

            if ($deletedStock < 1 && $deletedCartonItem < 1) {
                $db->rollback();
                fg_json(404, ['ok' => false, 'error' => 'Stock row not found.']);
            }

            $db->commit();
            fg_json(200, ['ok' => true, 'message' => 'Stock entry deleted successfully.']);
        }

        // Remove child dispatch logs first to satisfy FK constraints.
        $delLogById = $db->prepare("DELETE FROM finished_goods_dispatch_log WHERE stock_id = ?");
        if (!$delLogById) {
            throw new Exception('Unable to prepare dispatch-log delete query.');
        }
        $delLogById->bind_param('i', $id);
        $delLogById->execute();
        $delLogById->close();

        $stmt = $db->prepare("DELETE FROM finished_goods_stock WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to prepare stock delete query.');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            $db->rollback();
            fg_json(404, ['ok' => false, 'error' => 'Stock row not found.']);
        }
        $stmt->close();

        $db->commit();
        fg_json(200, ['ok' => true, 'message' => 'Stock entry deleted successfully.']);
    } catch (Throwable $e) {
        try {
            $db->rollback();
        } catch (Throwable $ignore) {
            // no-op
        }
        fg_json(500, ['ok' => false, 'error' => 'Unable to delete stock entry: ' . $e->getMessage()]);
    }
}

if ($action === 'import_excel') {
    fg_require_admin();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $rowsRaw = $payload['rows'] ?? [];
    if (is_string($rowsRaw)) {
        $rowsRaw = json_decode($rowsRaw, true);
    }
    if (!is_array($rowsRaw) || empty($rowsRaw)) {
        fg_json(400, ['ok' => false, 'error' => 'Import rows are missing.']);
    }

    $inserted = 0;
    $failed = 0;
    $errors = [];

    $db->begin_transaction();
    foreach ($rowsRaw as $idx => $row) {
        if (!is_array($row)) {
            $failed++;
            $errors[] = 'Row ' . ($idx + 1) . ': invalid row format.';
            continue;
        }

        $ok = fg_insert_row($db, $row, $userId);
        if ($ok) {
            $inserted++;
        } else {
            $failed++;
            $errors[] = 'Row ' . ($idx + 1) . ': insert failed.';
        }

        if (($inserted + $failed) >= 5000) {
            break;
        }
    }

    if ($failed > 0 && $inserted === 0) {
        $db->rollback();
        fg_json(422, ['ok' => false, 'error' => 'No rows imported.', 'failed' => $failed, 'errors' => $errors]);
    }

    $db->commit();
    fg_json(200, ['ok' => true, 'inserted' => $inserted, 'failed' => $failed, 'errors' => $errors]);
}

if ($action === 'deduct_stock') {
    fg_require_stock_operator();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $id = fg_resolve_row_id($db, $payload);
    $deductQty = fg_decimal($payload['deduct_qty'] ?? 0);
    $strictMode = (int)($payload['strict_mode'] ?? 1) === 1;
    $referenceNo = fg_clean_text($payload['reference_no'] ?? '', 120);
    $reason = fg_clean_text($payload['reason'] ?? '', 255);

    if ($id <= 0) {
        fg_json(400, ['ok' => false, 'error' => 'Unable to resolve stock row. Provide id or match fields.']);
    }

    $db->begin_transaction();
    $result = fg_apply_deduction($db, $id, $deductQty, $strictMode, $userId, $referenceNo, $reason);
    if (empty($result['ok'])) {
        $db->rollback();
        fg_json(422, $result);
    }
    $db->commit();

    fg_json(200, [
        'ok' => true,
        'message' => 'Stock deducted successfully.',
        'result' => $result,
    ]);
}

if ($action === 'bulk_deduct_stock') {
    fg_require_stock_operator();
    if ($method !== 'POST') {
        fg_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = fg_get_payload();
    $rows = $payload['rows'] ?? [];
    if (is_string($rows)) {
        $rows = json_decode($rows, true);
    }
    if (!is_array($rows) || empty($rows)) {
        fg_json(400, ['ok' => false, 'error' => 'Bulk deduction rows are missing.']);
    }

    $strictMode = (int)($payload['strict_mode'] ?? 1) === 1;

    $results = [];
    $db->begin_transaction();
    foreach ($rows as $idx => $row) {
        if (!is_array($row)) {
            $results[] = ['ok' => false, 'row' => $idx + 1, 'error' => 'Invalid row payload.'];
            if ($strictMode) {
                $db->rollback();
                fg_json(422, ['ok' => false, 'error' => 'Bulk deduction failed on row ' . ($idx + 1), 'results' => $results]);
            }
            continue;
        }

        $id = fg_resolve_row_id($db, $row);
        $qty = fg_decimal($row['deduct_qty'] ?? 0);
        $rowStrict = array_key_exists('strict_mode', $row) ? ((int)$row['strict_mode'] === 1) : $strictMode;
        $referenceNo = fg_clean_text($row['reference_no'] ?? '', 120);
        $reason = fg_clean_text($row['reason'] ?? '', 255);

        if ($id <= 0 || $qty <= 0) {
            $results[] = ['ok' => false, 'row' => $idx + 1, 'error' => 'Invalid match/id or deduct quantity.'];
            if ($strictMode) {
                $db->rollback();
                fg_json(422, ['ok' => false, 'error' => 'Bulk deduction failed on row ' . ($idx + 1), 'results' => $results]);
            }
            continue;
        }

        $res = fg_apply_deduction($db, $id, $qty, $rowStrict, $userId, $referenceNo, $reason);
        $res['row'] = $idx + 1;
        $results[] = $res;

        if (empty($res['ok']) && $strictMode) {
            $db->rollback();
            fg_json(422, ['ok' => false, 'error' => 'Bulk deduction failed on row ' . ($idx + 1), 'results' => $results]);
        }
    }

    $db->commit();

    $okCount = 0;
    foreach ($results as $r) {
        if (!empty($r['ok'])) {
            $okCount++;
        }
    }
    $failCount = count($results) - $okCount;

    fg_json(200, [
        'ok' => true,
        'message' => 'Bulk deduction processed.',
        'processed' => count($results),
        'success' => $okCount,
        'failed' => $failCount,
        'results' => $results,
    ]);
}

fg_json(404, ['ok' => false, 'error' => 'Unknown action: ' . $action]);
