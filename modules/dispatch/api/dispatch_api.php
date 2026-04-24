<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated. Please log in.']);
    exit;
}

require_once __DIR__ . '/../../../includes/auth_check.php';

$db = getDB();

function ds_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ds_clean($value, int $max = 255): string {
    $text = trim(strip_tags((string)$value));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max);
    }
    return $text;
}

function ds_has_column(mysqli $db, string $table, string $column): bool {
    $tableSafe = $db->real_escape_string($table);
    $columnSafe = $db->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'";
    $res = $db->query($sql);
    if (!$res) {
        return false;
    }
    return (bool)$res->fetch_assoc();
}

function ds_require_dispatch_access(): void {
    if (isAdmin()) {
        return;
    }
    if (function_exists('canAccessPath') && canAccessPath('/modules/dispatch/index.php')) {
        return;
    }
    ds_json(403, ['ok' => false, 'error' => 'Permission denied for dispatch module.']);
}

function ds_decimal($value, int $precision = 3): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float)$value, $precision);
}

function ds_truthy($value): bool {
    $raw = strtolower(trim((string)$value));
    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function ds_dispatch_qty_mismatches(mysqli $db, array $entryIds = [], string $whereSql = ''): array {
    $filters = [];
    if (!empty($entryIds)) {
        $safeIds = array_values(array_filter(array_map(static function ($id) {
            return (int)$id;
        }, $entryIds), static function ($id) {
            return $id > 0;
        }));
        if (!empty($safeIds)) {
            $filters[] = 'de.id IN (' . implode(',', $safeIds) . ')';
        }
    }
    if (trim($whereSql) !== '') {
        $trimmed = preg_replace('/^\s*where\s+/i', '', trim($whereSql)) ?? trim($whereSql);
        if ($trimmed !== '') {
            $filters[] = '(' . $trimmed . ')';
        }
    }

    $sql = "SELECT de.id, de.dispatch_id, de.dispatch_qty, COALESCE(SUM(di.dispatch_qty),0) AS item_qty
        FROM dispatch_entries de
        LEFT JOIN dispatch_items di ON di.dispatch_id = de.id
        LEFT JOIN finished_goods_stock fs ON fs.id = di.item_id";
    if (!empty($filters)) {
        $sql .= ' WHERE ' . implode(' AND ', $filters);
    }
    $sql .= " GROUP BY de.id, de.dispatch_id, de.dispatch_qty
        HAVING ROUND(COALESCE(de.dispatch_qty,0),3) <> ROUND(COALESCE(item_qty,0),3)";

    $rows = [];
    $res = $db->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
            error_log('Dispatch qty mismatch: dispatch_id=' . (string)($row['dispatch_id'] ?? '')
                . ' entry_qty=' . (string)($row['dispatch_qty'] ?? '0')
                . ' items_qty=' . (string)($row['item_qty'] ?? '0'));
        }
    }

    return $rows;
}

function ds_assert_dispatch_qty_match(mysqli $db, array $entryIds = []): void {
    $mismatches = ds_dispatch_qty_mismatches($db, $entryIds);
    if (!empty($mismatches)) {
        $first = $mismatches[0];
        throw new RuntimeException('Dispatch quantity integrity check failed for ' . (string)($first['dispatch_id'] ?? 'dispatch entry') . '.');
    }
}

function ds_finished_goods_category_source_expr(mysqli $db, string $stockAlias, string $entryAlias = 'de'): string {
    $parts = [];
    foreach (['item_category', 'item_type', 'tab_name', 'category', 'sub_type', 'item_name'] as $column) {
        if (ds_has_column($db, 'finished_goods_stock', $column)) {
            $parts[] = "NULLIF({$stockAlias}.{$column}, '')";
        }
    }
    $parts[] = "NULLIF({$entryAlias}.item_name, '')";

    return "LOWER(REPLACE(REPLACE(REPLACE(CONCAT_WS('', " . implode(', ', $parts) . "), ' ', ''), '_', ''), '-', ''))";
}

function ds_dispatch_category_bucket_case(string $sourceExpr): string {
    return "CASE
        WHEN {$sourceExpr} LIKE '%pospaperroll%' OR {$sourceExpr} LIKE '%paperroll%' OR {$sourceExpr} LIKE '%posroll%' OR {$sourceExpr} LIKE 'pos%' THEN 'pos_paper_roll'
        WHEN {$sourceExpr} LIKE '%1ply%' OR {$sourceExpr} LIKE '%oneply%' THEN 'one_ply'
        WHEN {$sourceExpr} LIKE '%2ply%' OR {$sourceExpr} LIKE '%twoply%' THEN 'two_ply'
        WHEN {$sourceExpr} LIKE '%barcode%' OR {$sourceExpr} LIKE '%rotery%' OR {$sourceExpr} LIKE '%rotary%' THEN 'barcode'
        WHEN {$sourceExpr} LIKE '%printingroll%' OR {$sourceExpr} LIKE '%printroll%' THEN 'printing_roll'
        WHEN {$sourceExpr} LIKE '%ribbon%' THEN 'ribbon'
        WHEN {$sourceExpr} LIKE '%core%' THEN 'core'
        WHEN {$sourceExpr} LIKE '%carton%' THEN 'carton'
        ELSE 'other'
    END";
}

function ds_date($value): ?string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $patterns = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd.m.Y'];
    foreach ($patterns as $pattern) {
        $dt = DateTime::createFromFormat($pattern, $raw);
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

function ds_get_payload(): array {
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

function ds_ensure_tables(mysqli $db): void {
    $finishedStockSql = "CREATE TABLE IF NOT EXISTS finished_goods_stock (
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
    if (!$db->query($finishedStockSql)) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to initialize finished goods stock table: ' . $db->error]);
    }

    if (!ds_has_column($db, 'finished_goods_stock', 'dispatch_qty_total')) {
        $db->query("ALTER TABLE finished_goods_stock ADD COLUMN dispatch_qty_total DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER quantity");
    }
    if (!ds_has_column($db, 'finished_goods_stock', 'closing_stock')) {
        $db->query("ALTER TABLE finished_goods_stock ADD COLUMN closing_stock DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER dispatch_qty_total");
    }
    $db->query("UPDATE finished_goods_stock SET closing_stock = quantity WHERE closing_stock = 0 AND quantity <> 0");

    $dispatchLogSql = "CREATE TABLE IF NOT EXISTS finished_goods_dispatch_log (
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
    if (!$db->query($dispatchLogSql)) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to initialize dispatch log table: ' . $db->error]);
    }

    if (!ds_has_column($db, 'finished_goods_dispatch_log', 'previous_stock')) {
        $db->query("ALTER TABLE finished_goods_dispatch_log ADD COLUMN previous_stock DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER before_qty");
    }
    if (!ds_has_column($db, 'finished_goods_dispatch_log', 'new_stock')) {
        $db->query("ALTER TABLE finished_goods_dispatch_log ADD COLUMN new_stock DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER after_qty");
    }
    if (!ds_has_column($db, 'finished_goods_dispatch_log', 'user_id')) {
        $db->query("ALTER TABLE finished_goods_dispatch_log ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER created_by");
    }
    if (!ds_has_column($db, 'finished_goods_dispatch_log', 'event_time')) {
        $db->query("ALTER TABLE finished_goods_dispatch_log ADD COLUMN event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at");
    }

    $dispatchEntriesSql = "CREATE TABLE IF NOT EXISTS dispatch_entries (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        dispatch_id VARCHAR(50) NOT NULL,
        entry_date DATE DEFAULT NULL,
        client_name VARCHAR(255) NOT NULL DEFAULT '',
        finished_stock_id INT UNSIGNED DEFAULT NULL,
        item_name VARCHAR(255) NOT NULL DEFAULT '',
        packing_id VARCHAR(120) NOT NULL DEFAULT '',
        batch_no VARCHAR(120) NOT NULL DEFAULT '',
        size VARCHAR(120) NOT NULL DEFAULT '',
        available_qty_snapshot DECIMAL(14,3) NOT NULL DEFAULT 0,
        dispatch_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        unit VARCHAR(30) NOT NULL DEFAULT 'PCS',
        invoice_no VARCHAR(120) NOT NULL DEFAULT '',
        invoice_date DATE DEFAULT NULL,
        transport_type VARCHAR(60) NOT NULL DEFAULT 'Transport',
        vehicle_no VARCHAR(120) NOT NULL DEFAULT '',
        transport_name VARCHAR(180) NOT NULL DEFAULT '',
        driver_name VARCHAR(120) NOT NULL DEFAULT '',
        driver_phone VARCHAR(30) NOT NULL DEFAULT '',
        transport_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
        paid_by VARCHAR(20) NOT NULL DEFAULT 'Company',
        dispatch_date DATE DEFAULT NULL,
        expected_delivery_date DATE DEFAULT NULL,
        delivery_status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        remarks TEXT,
        created_by INT UNSIGNED NOT NULL DEFAULT 0,
        updated_by INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_dispatch_id (dispatch_id),
        KEY idx_dispatch_date (dispatch_date),
        KEY idx_delivery_status (delivery_status),
        KEY idx_client_name (client_name),
        KEY idx_item_name (item_name),
        KEY idx_finished_stock_id (finished_stock_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($dispatchEntriesSql)) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to initialize dispatch table: ' . $db->error]);
    }

    if (!ds_has_column($db, 'dispatch_entries', 'invoice_id')) {
        $db->query("ALTER TABLE dispatch_entries ADD COLUMN invoice_id INT UNSIGNED NULL DEFAULT NULL AFTER invoice_no");
    }
    if (!ds_has_column($db, 'dispatch_entries', 'lr_number')) {
        $db->query("ALTER TABLE dispatch_entries ADD COLUMN lr_number VARCHAR(120) NOT NULL DEFAULT '' AFTER driver_phone");
    }
    if (!ds_has_column($db, 'dispatch_entries', 'eway_bill_number')) {
        $db->query("ALTER TABLE dispatch_entries ADD COLUMN eway_bill_number VARCHAR(120) NOT NULL DEFAULT '' AFTER lr_number");
    }
}

function ds_already_dispatched_qty(mysqli $db, int $stockId): float {
    $stmt = $db->prepare('SELECT COALESCE(SUM(deducted_qty),0) AS dispatched_qty FROM finished_goods_dispatch_log WHERE stock_id = ?');
    if (!$stmt) {
        return 0.0;
    }
    $stmt->bind_param('i', $stockId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['dispatched_qty'] ?? 0);
}

function ds_next_dispatch_id(mysqli $db): string {
    $prefix = 'DIS-' . date('Ym') . '-';
    $like = $prefix . '%';
    $stmt = $db->prepare('SELECT dispatch_id FROM dispatch_entries WHERE dispatch_id LIKE ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        return $prefix . '0001';
    }
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || empty($row['dispatch_id'])) {
        return $prefix . '0001';
    }

    $last = (string)$row['dispatch_id'];
    $parts = explode('-', $last);
    $seq = isset($parts[2]) ? (int)$parts[2] : 0;
    $seq++;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function ds_get_stock_row(mysqli $db, int $stockId): ?array {
    $stmt = $db->prepare('SELECT id, category, item_name, item_code, size, batch_no, unit, quantity, remarks FROM finished_goods_stock WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $stockId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

function ds_parse_remarks_extra($remarks): array {
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

function ds_pick_extra(array $extra, array $keys): string {
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

function ds_carton_ratio_for_stock(array $stockRow): float {
    $extra = ds_parse_remarks_extra($stockRow['remarks'] ?? '');
    $category = strtolower(trim((string)($stockRow['category'] ?? '')));
    if ($category === 'barcode') {
        return ds_decimal(ds_pick_extra($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton']));
    }
    return ds_decimal(ds_pick_extra($extra, ['per_carton', 'roll_per_cartoon', 'roll_per_carton']));
}

function ds_available_net_for_stock(array $row): float {
    $qty = (float)($row['quantity'] ?? 0);
    if ($qty <= 0) {
        return 0.0;
    }
    $category = strtolower(trim((string)($row['category'] ?? '')));
    $supportsMixed = in_array($category, ['pos_paper_roll', 'one_ply', 'two_ply', 'barcode', 'printing_roll'], true);
    if (!$supportsMixed) {
        return $qty;
    }
    $extra = ds_parse_remarks_extra($row['remarks'] ?? '');
    $mixedEnabled = (string)($extra['mixed_enabled'] ?? '0') === '1';
    $mixedExtra = 0.0;
    if ($category === 'barcode') {
        $rpc = (int)floor(ds_decimal(ds_pick_extra($extra, ['roll_per_cartoon', 'roll_per_carton', 'per_carton'])));
        if ($mixedEnabled) {
            $mixedExtra = max(0.0, ds_decimal($extra['mixed_extra_rolls'] ?? 0));
        } else {
            $totalRoll = ds_decimal(ds_pick_extra($extra, ['total_roll', 'total_rolls', 'total_roll_value']));
            if ($totalRoll <= 0) {
                $pcsPerRoll = ds_decimal(ds_pick_extra($extra, ['pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll', 'qty_per_roll']));
                if ($pcsPerRoll > 0) {
                    $totalRoll = (float)ceil($qty / $pcsPerRoll);
                }
            }
            if ($rpc > 0 && $totalRoll > 0) {
                $mixedExtra = fmod($totalRoll, (float)$rpc);
            } else {
                $mixedExtra = $totalRoll;
            }
        }
    } else {
        if ($mixedEnabled) {
            $mixedExtra = max(0.0, ds_decimal($extra['mixed_extra_rolls'] ?? 0));
        } else {
            $perCarton = ds_decimal(ds_pick_extra($extra, ['per_carton']));
            if ($perCarton > 0) {
                $mixedExtra = fmod($qty, $perCarton);
            }
        }
    }
    return max(0.0, round($qty - max(0.0, $mixedExtra), 3));
}

function ds_parse_batch_items($raw): array {
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        return [];
    }

    $items = [];
    foreach ($raw as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $itemId = (int)($entry['item_id'] ?? 0);
        $batchNo = ds_clean($entry['batch_no'] ?? '', 120);
        $packingId = ds_clean($entry['packing_id'] ?? '', 120);
        $dispatchQty = ds_decimal($entry['dispatch_qty'] ?? 0);
        $availableQty = ds_decimal($entry['available_qty'] ?? 0);

        if ($itemId <= 0 || $dispatchQty <= 0) {
            continue;
        }

        $items[] = [
            'item_id' => $itemId,
            'batch_no' => $batchNo,
            'packing_id' => $packingId,
            'dispatch_qty' => $dispatchQty,
            'available_qty' => $availableQty,
        ];
    }

    return $items;
}

function ds_get_item_batches(mysqli $db, string $itemName, string $packingId = ''): array {
    if ($itemName === '') {
        return [];
    }

    $normalizeRows = static function (array $rows): array {
        foreach ($rows as &$row) {
            $row['available_qty'] = ds_available_net_for_stock($row);
        }
        unset($row);
        return $rows;
    };

    if ($packingId !== '') {
        $stmt = $db->prepare('SELECT id, item_name, item_code, batch_no, size, unit, quantity, remarks, category FROM finished_goods_stock WHERE item_name = ? AND item_code = ? AND quantity > 0 ORDER BY batch_no ASC, id ASC');
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ss', $itemName, $packingId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return $normalizeRows($rows);
    }

    $stmt = $db->prepare('SELECT id, item_name, item_code, batch_no, size, unit, quantity, remarks, category FROM finished_goods_stock WHERE item_name = ? AND quantity > 0 ORDER BY batch_no ASC, id ASC');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $itemName);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $normalizeRows($rows);
}

function ds_adjust_stock(mysqli $db, int $stockId, float $deltaQty, string $referenceNo, string $reason, int $userId): array {
    if ($deltaQty == 0.0) {
        return ['ok' => true];
    }

    $stmt = $db->prepare('SELECT id, category, quantity FROM finished_goods_stock WHERE id = ? FOR UPDATE');
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to lock stock row.'];
    }

    $stmt->bind_param('i', $stockId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return ['ok' => false, 'error' => 'Selected stock item not found.'];
    }

    $current = (float)($row['quantity'] ?? 0);
    $category = (string)($row['category'] ?? '');
    $alreadyDispatched = ds_already_dispatched_qty($db, $stockId);
    $totalProduction = round($current + $alreadyDispatched, 3);
    $available = round($totalProduction - $alreadyDispatched, 3);

    if ($deltaQty > 0 && $deltaQty > $available) {
        return [
            'ok' => false,
            'error' => 'Cannot dispatch more than available quantity.',
            'available_qty' => $available,
            'already_dispatched' => $alreadyDispatched,
            'total_production' => $totalProduction,
        ];
    }

    $newDispatched = round($alreadyDispatched + $deltaQty, 3);
    if ($newDispatched < 0) {
        $newDispatched = 0;
    }

    // closing = opening + inward - dispatch, where opening+inward maps to total production snapshot.
    $newQty = round($totalProduction - $newDispatched, 3);
    if ($newQty < 0) {
        $newQty = 0;
    }

    $up = $db->prepare('UPDATE finished_goods_stock SET quantity = ?, dispatch_qty_total = ?, closing_stock = ? WHERE id = ?');
    if (!$up) {
        return ['ok' => false, 'error' => 'Unable to update stock quantity.'];
    }
    $up->bind_param('dddi', $newQty, $newDispatched, $newQty, $stockId);
    if (!$up->execute()) {
        return ['ok' => false, 'error' => 'Stock update failed.'];
    }

    $log = $db->prepare('INSERT INTO finished_goods_dispatch_log (stock_id, category, deducted_qty, before_qty, previous_stock, after_qty, new_stock, reference_no, reason, created_by, user_id, created_at, event_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    if (!$log) {
        return ['ok' => false, 'error' => 'Unable to write stock movement log.'];
    }
    $log->bind_param('isdddddssii', $stockId, $category, $deltaQty, $current, $current, $newQty, $newQty, $referenceNo, $reason, $userId, $userId);
    if (!$log->execute()) {
        return ['ok' => false, 'error' => 'Unable to log stock movement.'];
    }

    return [
        'ok' => true,
        'before_qty' => $current,
        'after_qty' => $newQty,
        'available_qty' => $available,
        'already_dispatched' => $alreadyDispatched,
        'total_production' => $totalProduction,
    ];
}

function ds_upsert_dispatch_items_table(mysqli $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS dispatch_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        dispatch_id INT UNSIGNED NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        batch_no VARCHAR(120) NOT NULL DEFAULT '',
        packing_id VARCHAR(120) NOT NULL DEFAULT '',
        dispatch_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_di_dispatch (dispatch_id),
        KEY idx_di_item (item_id),
        KEY idx_di_batch (batch_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($sql)) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to initialize dispatch items table: ' . $db->error]);
    }
}

ds_ensure_tables($db);
ds_upsert_dispatch_items_table($db);
ds_require_dispatch_access();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_REQUEST['action'] ?? ''));

if ($action === '') {
    ds_json(400, ['ok' => false, 'error' => 'Missing action.']);
}

if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        ds_json(403, ['ok' => false, 'error' => 'Invalid CSRF token.']);
    }
}

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($action === 'get_next_dispatch_id') {
    ds_json(200, ['ok' => true, 'dispatch_id' => ds_next_dispatch_id($db)]);
}

if ($action === 'prefill_stock') {
    $stockId = (int)($_GET['item_id'] ?? 0);
    if ($stockId <= 0) {
        ds_json(400, ['ok' => false, 'error' => 'Invalid stock item id.']);
    }

    $row = ds_get_stock_row($db, $stockId);
    if (!$row) {
        ds_json(404, ['ok' => false, 'error' => 'Stock item not found.']);
    }

    ds_json(200, ['ok' => true, 'row' => $row]);
}

if ($action === 'get_item_batches') {
    $stockId = (int)($_GET['stock_id'] ?? 0);
    $itemName = ds_clean($_GET['item_name'] ?? '', 255);
    $packingId = ds_clean($_GET['packing_id'] ?? '', 120);

    if ($stockId > 0) {
        $row = ds_get_stock_row($db, $stockId);
        if (!$row) {
            ds_json(404, ['ok' => false, 'error' => 'Stock item not found.']);
        }
        $itemName = ds_clean($row['item_name'] ?? '', 255);
        if ($packingId === '') {
            $packingId = ds_clean($row['item_code'] ?? '', 120);
        }
    }

    if ($itemName === '') {
        ds_json(422, ['ok' => false, 'error' => 'Item name is required for batch fetch.']);
    }

    $rows = ds_get_item_batches($db, $itemName, $packingId);
    ds_json(200, ['ok' => true, 'rows' => $rows]);
}

if ($action === 'search_packing_ids') {
    $query = ds_clean($_GET['q'] ?? '', 120);
    $limit = (int)($_GET['limit'] ?? 25);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 50) {
        $limit = 50;
    }

    if ($query === '') {
        $sql = "SELECT item_code AS packing_id, item_name, COALESCE(SUM(quantity),0) AS available_qty
            FROM finished_goods_stock
            WHERE item_code <> '' AND quantity > 0
            GROUP BY item_code, item_name
            ORDER BY MAX(id) DESC
            LIMIT {$limit}";
        $res = $db->query($sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        ds_json(200, ['ok' => true, 'rows' => $rows]);
    }

    $like = '%' . $query . '%';
    $starts = $query . '%';
    $stmt = $db->prepare("SELECT item_code AS packing_id, item_name, COALESCE(SUM(quantity),0) AS available_qty
        FROM finished_goods_stock
        WHERE item_code <> '' AND quantity > 0
          AND (item_code LIKE ? OR item_name LIKE ?)
        GROUP BY item_code, item_name
        ORDER BY (item_code LIKE ?) DESC, MAX(id) DESC
        LIMIT {$limit}");
    if (!$stmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to search packing IDs.']);
    }
    $stmt->bind_param('sss', $like, $like, $starts);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    ds_json(200, ['ok' => true, 'rows' => $rows]);
}

if ($action === 'prefill_by_packing_id') {
    $packingId = ds_clean($_GET['packing_id'] ?? '', 120);
    if ($packingId === '') {
        ds_json(422, ['ok' => false, 'error' => 'Packing ID is required.']);
    }

    $stmt = $db->prepare('SELECT id, item_name, item_code, batch_no, size, unit, quantity, remarks, category
        FROM finished_goods_stock
        WHERE item_code = ? AND quantity > 0
        ORDER BY id DESC
        LIMIT 1');
    if (!$stmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to fetch packing details.']);
    }
    $stmt->bind_param('s', $packingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        ds_json(404, ['ok' => false, 'error' => 'Packing ID not found in available finished goods.']);
    }

    $allStmt = $db->prepare('SELECT quantity, remarks, category FROM finished_goods_stock WHERE item_code = ? AND quantity > 0');
    if ($allStmt) {
        $allStmt->bind_param('s', $packingId);
        $allStmt->execute();
        $allRows = $allStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $totalNet = 0.0;
        $totalRaw = 0.0;
        foreach ($allRows as $sr) {
            $totalRaw += (float)($sr['quantity'] ?? 0);
            $totalNet += ds_available_net_for_stock($sr);
        }
        $row['available_qty'] = $totalNet > 0 ? $totalNet : $totalRaw;
    } else {
        $row['available_qty'] = (float)($row['quantity'] ?? 0);
    }

    $row['carton_ratio'] = ds_carton_ratio_for_stock($row);

    ds_json(200, ['ok' => true, 'row' => $row]);
}

if ($action === 'save_dispatch') {
    if ($method !== 'POST') {
        ds_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = ds_get_payload();
    $pk = (int)($payload['id'] ?? 0);

    $entryDate = ds_date($payload['entry_date'] ?? date('Y-m-d'));
    $clientName = ds_clean($payload['client_name'] ?? '', 255);
    $finishedStockId = (int)($payload['finished_stock_id'] ?? 0);
    $itemName = ds_clean($payload['item_name'] ?? '', 255);
    $packingId = ds_clean($payload['packing_id'] ?? '', 120);
    $batchNo = ds_clean($payload['batch_no'] ?? '', 120);
    $size = ds_clean($payload['size'] ?? '', 120);
    $availableSnapshot = ds_decimal($payload['available_qty_snapshot'] ?? 0);
    $dispatchQty = ds_decimal($payload['dispatch_qty'] ?? 0);
    $unit = ds_clean($payload['unit'] ?? 'PCS', 30);
    $unitNorm = strtoupper($unit);

    $invoiceNo = ds_clean($payload['invoice_no'] ?? '', 120);
    $invoiceIdRaw = trim((string)($payload['invoice_id'] ?? ''));
    $invoiceId = ($invoiceIdRaw === '' || !ctype_digit($invoiceIdRaw)) ? null : (int)$invoiceIdRaw;
    $invoiceDate = ds_date($payload['invoice_date'] ?? '');

    $transportType = ds_clean($payload['transport_type'] ?? 'Transport', 60);
    $vehicleNo = ds_clean($payload['vehicle_no'] ?? '', 120);
    $transportName = ds_clean($payload['transport_name'] ?? '', 180);
    $driverName = ds_clean($payload['driver_name'] ?? '', 120);
    $driverPhone = ds_clean($payload['driver_phone'] ?? '', 30);
    $lrNumber = ds_clean($payload['lr_number'] ?? '', 120);
    $ewayBillNumber = ds_clean($payload['eway_bill_number'] ?? '', 120);

    $transportCost = ds_decimal($payload['transport_cost'] ?? 0, 2);
    $paidBy = ds_clean($payload['paid_by'] ?? 'Company', 20);

    $dispatchDate = ds_date($payload['dispatch_date'] ?? '');
    $expectedDeliveryDate = ds_date($payload['expected_delivery_date'] ?? '');
    $deliveryStatus = ds_clean($payload['delivery_status'] ?? 'Pending', 30);
    $remarks = trim((string)($payload['remarks'] ?? ''));
    $batchItems = ds_parse_batch_items($payload['batch_items'] ?? []);

    if (empty($batchItems) && $finishedStockId > 0 && $dispatchQty > 0) {
        $batchItems[] = [
            'item_id' => $finishedStockId,
            'batch_no' => $batchNo,
            'packing_id' => $packingId,
            'dispatch_qty' => $dispatchQty,
            'available_qty' => $availableSnapshot,
        ];
    }

    if (empty($batchItems)) {
        ds_json(422, ['ok' => false, 'error' => 'At least one batch quantity is required.']);
    }

    if ($entryDate === null) {
        ds_json(422, ['ok' => false, 'error' => 'Entry date is required.']);
    }
    if ($clientName === '') {
        ds_json(422, ['ok' => false, 'error' => 'Client name is required.']);
    }
    if ($itemName === '') {
        ds_json(422, ['ok' => false, 'error' => 'Item name is required.']);
    }
    $dispatchQty = 0;
    $availableSnapshot = 0;
    $batchNos = [];
    $firstStockId = 0;
    $firstPackingId = '';
    $normalizedBatchItems = [];
    foreach ($batchItems as $bi) {
        $batchQtyInput = ds_decimal($bi['dispatch_qty'] ?? 0);
        $itemId = (int)($bi['item_id'] ?? 0);
        if ($itemId <= 0 || $batchQtyInput <= 0) {
            ds_json(422, ['ok' => false, 'error' => 'Invalid batch dispatch payload.']);
        }

        $stockRow = ds_get_stock_row($db, $itemId);
        if (!$stockRow) {
            ds_json(422, ['ok' => false, 'error' => 'Batch stock row not found.']);
        }

        $batchQty = $batchQtyInput;
        if ($unitNorm === 'CARTON') {
            $ratio = ds_carton_ratio_for_stock($stockRow);
            if ($ratio <= 0) {
                ds_json(422, ['ok' => false, 'error' => 'Per carton not set for selected batch. Cannot dispatch by carton.']);
            }
            $batchQty = ds_decimal($batchQtyInput * $ratio);
        }

        $currentAvail = (float)($stockRow['quantity'] ?? 0);
        if ($pk <= 0 && $batchQty > $currentAvail) {
            ds_json(422, ['ok' => false, 'error' => 'Batch dispatch quantity exceeds available stock.', 'batch_no' => (string)($bi['batch_no'] ?? '')]);
        }

        if ($firstStockId === 0) {
            $firstStockId = $itemId;
            $firstPackingId = ds_clean($bi['packing_id'] ?? ($stockRow['item_code'] ?? ''), 120);
        }

        $dispatchQty += $batchQty;
        $availableSnapshot += $currentAvail;
        $batchNos[] = ds_clean($bi['batch_no'] ?? ($stockRow['batch_no'] ?? ''), 120);

        $normalizedBatchItems[] = [
            'item_id' => $itemId,
            'batch_no' => ds_clean($bi['batch_no'] ?? ($stockRow['batch_no'] ?? ''), 120),
            'packing_id' => ds_clean($bi['packing_id'] ?? ($stockRow['item_code'] ?? ''), 120),
            'dispatch_qty' => $batchQty,
            'available_qty' => $currentAvail,
        ];
    }

    $batchItems = $normalizedBatchItems;

    if ($dispatchQty <= 0) {
        ds_json(422, ['ok' => false, 'error' => 'Dispatch quantity must be greater than zero.']);
    }

    if ($unitNorm === 'CARTON') {
        // Quantities are normalized to PCS for stock and logs.
        $unit = 'PCS';
    }

    $finishedStockId = $firstStockId;
    if ($firstPackingId !== '') {
        $packingId = $firstPackingId;
    }
    $batchNo = ds_clean(implode(', ', array_slice(array_values(array_unique(array_filter($batchNos))), 0, 8)), 120);

    if ($driverPhone !== '' && !preg_match('/^[0-9+\-()\s]{6,20}$/', $driverPhone)) {
        ds_json(422, ['ok' => false, 'error' => 'Driver phone format is invalid.']);
    }

    if ($dispatchDate === null) {
        $dispatchDate = $entryDate;
    }

    if ($finishedStockId > 0) {
        $stock = ds_get_stock_row($db, $finishedStockId);
        if (!$stock) {
            ds_json(422, ['ok' => false, 'error' => 'Selected stock item not found in finished goods.']);
        }
    }

    if ($paidBy !== 'Company' && $paidBy !== 'Client') {
        $paidBy = 'Company';
    }

    if ($deliveryStatus !== 'Pending' && $deliveryStatus !== 'In Transit' && $deliveryStatus !== 'Delivered') {
        $deliveryStatus = 'Pending';
    }

    $db->begin_transaction();

    try {
        if ($pk > 0) {
            $existingStmt = $db->prepare('SELECT * FROM dispatch_entries WHERE id = ? FOR UPDATE');
            if (!$existingStmt) {
                throw new RuntimeException('Unable to lock existing dispatch entry.');
            }
            $existingStmt->bind_param('i', $pk);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc();
            if (!$existing) {
                throw new RuntimeException('Dispatch entry not found.');
            }

            $oldStockId = (int)($existing['finished_stock_id'] ?? 0);
            $oldQty = (float)($existing['dispatch_qty'] ?? 0);
            $dispatchId = (string)($existing['dispatch_id'] ?? '');

            $oldBatchStmt = $db->prepare('SELECT item_id, dispatch_qty, batch_no FROM dispatch_items WHERE dispatch_id = ? ORDER BY id ASC');
            if (!$oldBatchStmt) {
                throw new RuntimeException('Unable to load existing dispatch batch rows.');
            }
            $oldBatchStmt->bind_param('i', $pk);
            $oldBatchStmt->execute();
            $oldBatchRows = $oldBatchStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!empty($oldBatchRows)) {
                foreach ($oldBatchRows as $oldBatch) {
                    $revertQty = ds_decimal($oldBatch['dispatch_qty'] ?? 0);
                    $revertItemId = (int)($oldBatch['item_id'] ?? 0);
                    if ($revertItemId <= 0 || $revertQty <= 0) {
                        continue;
                    }
                    $revert = ds_adjust_stock($db, $revertItemId, -$revertQty, $dispatchId, 'Dispatch update rollback (batch: ' . ds_clean($oldBatch['batch_no'] ?? '', 120) . ')', $userId);
                    if (empty($revert['ok'])) {
                        throw new RuntimeException((string)($revert['error'] ?? 'Unable to rollback previous stock deduction.'));
                    }
                }
            } elseif ($oldStockId > 0 && $oldQty > 0) {
                $revert = ds_adjust_stock($db, $oldStockId, -$oldQty, $dispatchId, 'Dispatch update rollback', $userId);
                if (empty($revert['ok'])) {
                    throw new RuntimeException((string)($revert['error'] ?? 'Unable to rollback previous stock deduction.'));
                }
            }

            $deleteOldBatch = $db->prepare('DELETE FROM dispatch_items WHERE dispatch_id = ?');
            if (!$deleteOldBatch) {
                throw new RuntimeException('Unable to clear existing dispatch batches.');
            }
            $deleteOldBatch->bind_param('i', $pk);
            if (!$deleteOldBatch->execute()) {
                throw new RuntimeException('Unable to clear existing dispatch batch allocations.');
            }

            $insertBatch = $db->prepare('INSERT INTO dispatch_items (dispatch_id, item_id, batch_no, packing_id, dispatch_qty) VALUES (?, ?, ?, ?, ?)');
            if (!$insertBatch) {
                throw new RuntimeException('Unable to prepare dispatch batch insert.');
            }

            foreach ($batchItems as $batchItem) {
                $biItemId = (int)$batchItem['item_id'];
                $biQty = ds_decimal($batchItem['dispatch_qty'] ?? 0);
                $biBatchNo = ds_clean($batchItem['batch_no'] ?? '', 120);
                $biPackingId = ds_clean($batchItem['packing_id'] ?? '', 120);

                $deduct = ds_adjust_stock($db, $biItemId, $biQty, $dispatchId, 'Dispatch quantity deducted (batch: ' . $biBatchNo . ')', $userId);
                if (empty($deduct['ok'])) {
                    throw new RuntimeException((string)($deduct['error'] ?? 'Unable to deduct stock for dispatch update.'));
                }

                $insertBatch->bind_param('iissd', $pk, $biItemId, $biBatchNo, $biPackingId, $biQty);
                if (!$insertBatch->execute()) {
                    throw new RuntimeException('Unable to save dispatch batch allocation.');
                }
            }

            $update = $db->prepare('UPDATE dispatch_entries SET entry_date = ?, client_name = ?, finished_stock_id = ?, item_name = ?, packing_id = ?, batch_no = ?, size = ?, available_qty_snapshot = ?, dispatch_qty = ?, unit = ?, invoice_no = ?, invoice_id = ?, invoice_date = ?, transport_type = ?, vehicle_no = ?, transport_name = ?, driver_name = ?, driver_phone = ?, lr_number = ?, eway_bill_number = ?, transport_cost = ?, paid_by = ?, dispatch_date = ?, expected_delivery_date = ?, delivery_status = ?, remarks = ?, updated_by = ? WHERE id = ?');
            if (!$update) {
                throw new RuntimeException('Unable to update dispatch entry.');
            }

            $invoiceIdSql = $invoiceId;
            $update->bind_param(
                'ssissssddssissssssssdsssssii',
                $entryDate,
                $clientName,
                $finishedStockId,
                $itemName,
                $packingId,
                $batchNo,
                $size,
                $availableSnapshot,
                $dispatchQty,
                $unit,
                $invoiceNo,
                $invoiceIdSql,
                $invoiceDate,
                $transportType,
                $vehicleNo,
                $transportName,
                $driverName,
                $driverPhone,
                $lrNumber,
                $ewayBillNumber,
                $transportCost,
                $paidBy,
                $dispatchDate,
                $expectedDeliveryDate,
                $deliveryStatus,
                $remarks,
                $userId,
                $pk
            );
            if (!$update->execute()) {
                throw new RuntimeException('Dispatch update failed.');
            }

            ds_assert_dispatch_qty_match($db, [$pk]);

            $db->commit();
            ds_json(200, ['ok' => true, 'message' => 'Dispatch updated successfully.', 'id' => $pk, 'dispatch_id' => $dispatchId]);
        }

        $dispatchId = ds_next_dispatch_id($db);

        foreach ($batchItems as $batchItem) {
            $biItemId = (int)$batchItem['item_id'];
            $biQty = ds_decimal($batchItem['dispatch_qty'] ?? 0);
            $biBatchNo = ds_clean($batchItem['batch_no'] ?? '', 120);
            $deduct = ds_adjust_stock($db, $biItemId, $biQty, $dispatchId, 'Dispatch quantity deducted (batch: ' . $biBatchNo . ')', $userId);
            if (empty($deduct['ok'])) {
                throw new RuntimeException((string)($deduct['error'] ?? 'Unable to deduct stock for dispatch.'));
            }
        }

        $insert = $db->prepare('INSERT INTO dispatch_entries (dispatch_id, entry_date, client_name, finished_stock_id, item_name, packing_id, batch_no, size, available_qty_snapshot, dispatch_qty, unit, invoice_no, invoice_id, invoice_date, transport_type, vehicle_no, transport_name, driver_name, driver_phone, lr_number, eway_bill_number, transport_cost, paid_by, dispatch_date, expected_delivery_date, delivery_status, remarks, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) {
            throw new RuntimeException('Unable to create dispatch entry.');
        }

        $invoiceIdSql = $invoiceId;

        $insert->bind_param(
            'sssissssddssissssssssdsssssii',
            $dispatchId,
            $entryDate,
            $clientName,
            $finishedStockId,
            $itemName,
            $packingId,
            $batchNo,
            $size,
            $availableSnapshot,
            $dispatchQty,
            $unit,
            $invoiceNo,
            $invoiceIdSql,
            $invoiceDate,
            $transportType,
            $vehicleNo,
            $transportName,
            $driverName,
            $driverPhone,
            $lrNumber,
            $ewayBillNumber,
            $transportCost,
            $paidBy,
            $dispatchDate,
            $expectedDeliveryDate,
            $deliveryStatus,
            $remarks,
            $userId,
            $userId
        );

        if (!$insert->execute()) {
            throw new RuntimeException('Dispatch insert failed.');
        }

        $id = (int)$insert->insert_id;

        $insertBatch = $db->prepare('INSERT INTO dispatch_items (dispatch_id, item_id, batch_no, packing_id, dispatch_qty) VALUES (?, ?, ?, ?, ?)');
        if (!$insertBatch) {
            throw new RuntimeException('Unable to prepare dispatch batch insert.');
        }
        foreach ($batchItems as $batchItem) {
            $biItemId = (int)$batchItem['item_id'];
            $biQty = ds_decimal($batchItem['dispatch_qty'] ?? 0);
            $biBatchNo = ds_clean($batchItem['batch_no'] ?? '', 120);
            $biPackingId = ds_clean($batchItem['packing_id'] ?? '', 120);
            $insertBatch->bind_param('iissd', $id, $biItemId, $biBatchNo, $biPackingId, $biQty);
            if (!$insertBatch->execute()) {
                throw new RuntimeException('Unable to save dispatch batch allocation.');
            }
        }

        ds_assert_dispatch_qty_match($db, [$id]);

        $db->commit();

        ds_json(200, ['ok' => true, 'message' => 'Dispatched Successfully', 'id' => $id, 'dispatch_id' => $dispatchId]);
    } catch (Throwable $e) {
        $db->rollback();
        ds_json(422, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'list_dispatches') {
    $from = ds_date($_GET['from'] ?? '');
    $to = ds_date($_GET['to'] ?? '');
    $client = ds_clean($_GET['client'] ?? '', 255);
    $item = ds_clean($_GET['item'] ?? '', 255);
    $status = ds_clean($_GET['status'] ?? '', 30);
    $search = ds_clean($_GET['search'] ?? '', 255);
    $strict = ds_truthy($_GET['strict'] ?? '');

    $where = [];
    $types = '';
    $params = [];

    if ($from) {
        $where[] = 'dispatch_date >= ?';
        $types .= 's';
        $params[] = $from;
    }
    if ($to) {
        $where[] = 'dispatch_date <= ?';
        $types .= 's';
        $params[] = $to;
    }
    if ($client !== '') {
        $where[] = 'client_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $client . '%';
    }
    if ($item !== '') {
        $where[] = 'item_name LIKE ?';
        $types .= 's';
        $params[] = '%' . $item . '%';
    }
    if ($status !== '') {
        $where[] = 'delivery_status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(dispatch_id LIKE ? OR client_name LIKE ? OR item_name LIKE ? OR invoice_no LIKE ? OR transport_name LIKE ? OR batch_no LIKE ?)';
        $types .= 'ssssss';
        $q = '%' . $search . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    $sql = 'SELECT * FROM dispatch_entries';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY dispatch_date DESC, id DESC';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to load dispatch entries.']);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $rowIds = array_values(array_filter(array_map(static function ($row) {
        return (int)($row['id'] ?? 0);
    }, $rows)));
    $mismatches = ds_dispatch_qty_mismatches($db, $rowIds);
    if ($strict && !empty($mismatches)) {
        ds_json(409, ['ok' => false, 'error' => 'Dispatch quantity mismatch detected.', 'mismatch_count' => count($mismatches)]);
    }

    ds_json(200, ['ok' => true, 'rows' => $rows, 'mismatch_count' => count($mismatches)]);
}

if ($action === 'get_dispatch') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        ds_json(400, ['ok' => false, 'error' => 'Invalid dispatch id.']);
    }

    $stmt = $db->prepare('SELECT * FROM dispatch_entries WHERE id = ? LIMIT 1');
    if (!$stmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to prepare dispatch fetch.']);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        ds_json(404, ['ok' => false, 'error' => 'Dispatch entry not found.']);
    }

    $batchStmt = $db->prepare("SELECT di.id, di.dispatch_id, di.item_id, di.batch_no, di.packing_id, di.dispatch_qty,
        COALESCE(NULLIF(fs.item_name, ''), ?, 'Unknown Item') AS item_name,
        COALESCE(fs.size, '') AS size,
        COALESCE(fs.unit, ?) AS unit
        FROM dispatch_items di
        LEFT JOIN finished_goods_stock fs ON fs.id = di.item_id
        WHERE di.dispatch_id = ?
        ORDER BY di.id ASC");
    if (!$batchStmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to load dispatch batch rows.']);
    }
    $fallbackItemName = (string)($row['item_name'] ?? '');
    $fallbackUnit = (string)($row['unit'] ?? 'PCS');
    $batchStmt->bind_param('ssi', $fallbackItemName, $fallbackUnit, $id);
    $batchStmt->execute();
    $batchRows = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $strict = ds_truthy($_GET['strict'] ?? '');
    $mismatches = ds_dispatch_qty_mismatches($db, [$id]);
    if ($strict && !empty($mismatches)) {
        ds_json(409, ['ok' => false, 'error' => 'Dispatch quantity mismatch detected for this entry.', 'mismatch_count' => count($mismatches)]);
    }

    ds_json(200, ['ok' => true, 'row' => $row, 'batch_items' => $batchRows, 'mismatch_count' => count($mismatches)]);
}

if ($action === 'delete_dispatch') {
    if ($method !== 'POST') {
        ds_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    $payload = ds_get_payload();
    $id = (int)($payload['id'] ?? 0);
    if ($id <= 0) {
        ds_json(400, ['ok' => false, 'error' => 'Invalid dispatch id.']);
    }

    $db->begin_transaction();
    try {
        $entryStmt = $db->prepare('SELECT id, dispatch_id, finished_stock_id, dispatch_qty FROM dispatch_entries WHERE id = ? FOR UPDATE');
        if (!$entryStmt) {
            throw new RuntimeException('Unable to lock dispatch entry.');
        }
        $entryStmt->bind_param('i', $id);
        $entryStmt->execute();
        $entry = $entryStmt->get_result()->fetch_assoc();
        if (!$entry) {
            throw new RuntimeException('Dispatch entry not found.');
        }

        $dispatchId = (string)($entry['dispatch_id'] ?? '');
        $oldStockId = (int)($entry['finished_stock_id'] ?? 0);
        $oldQty = ds_decimal($entry['dispatch_qty'] ?? 0);

        $batchStmt = $db->prepare('SELECT item_id, dispatch_qty, batch_no FROM dispatch_items WHERE dispatch_id = ? ORDER BY id ASC');
        if (!$batchStmt) {
            throw new RuntimeException('Unable to load dispatch batches for delete.');
        }
        $batchStmt->bind_param('i', $id);
        $batchStmt->execute();
        $batchRows = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (!empty($batchRows)) {
            foreach ($batchRows as $batchRow) {
                $revertItemId = (int)($batchRow['item_id'] ?? 0);
                $revertQty = ds_decimal($batchRow['dispatch_qty'] ?? 0);
                if ($revertItemId <= 0 || $revertQty <= 0) {
                    continue;
                }
                // Check if stock item exists before trying to adjust
                $checkStmt = $db->prepare('SELECT id FROM finished_goods_stock WHERE id = ? LIMIT 1');
                if ($checkStmt) {
                    $checkStmt->bind_param('i', $revertItemId);
                    $checkStmt->execute();
                    $stockExists = $checkStmt->get_result()->fetch_assoc();
                    if ($stockExists) {
                        $revert = ds_adjust_stock($db, $revertItemId, -$revertQty, $dispatchId, 'Dispatch delete rollback (batch: ' . ds_clean($batchRow['batch_no'] ?? '', 120) . ')', $userId);
                        if (empty($revert['ok'])) {
                            error_log('Stock adjustment warning during delete: ' . (string)($revert['error'] ?? 'Unknown error'));
                        }
                    } else {
                        error_log('Stock item ' . $revertItemId . ' not found during dispatch delete rollback - skipping stock adjustment');
                    }
                }
            }
        } elseif ($oldStockId > 0 && $oldQty > 0) {
            // Check if stock item exists before trying to adjust
            $checkStmt = $db->prepare('SELECT id FROM finished_goods_stock WHERE id = ? LIMIT 1');
            if ($checkStmt) {
                $checkStmt->bind_param('i', $oldStockId);
                $checkStmt->execute();
                $stockExists = $checkStmt->get_result()->fetch_assoc();
                if ($stockExists) {
                    $revert = ds_adjust_stock($db, $oldStockId, -$oldQty, $dispatchId, 'Dispatch delete rollback', $userId);
                    if (empty($revert['ok'])) {
                        error_log('Stock adjustment warning during delete: ' . (string)($revert['error'] ?? 'Unknown error'));
                    }
                } else {
                    error_log('Stock item ' . $oldStockId . ' not found during dispatch delete rollback - skipping stock adjustment');
                }
            }
        }

        $deleteItems = $db->prepare('DELETE FROM dispatch_items WHERE dispatch_id = ?');
        if (!$deleteItems) {
            throw new RuntimeException('Unable to prepare dispatch batch delete.');
        }
        $deleteItems->bind_param('i', $id);
        if (!$deleteItems->execute()) {
            throw new RuntimeException('Unable to delete dispatch batch rows.');
        }

        $deleteEntry = $db->prepare('DELETE FROM dispatch_entries WHERE id = ? LIMIT 1');
        if (!$deleteEntry) {
            throw new RuntimeException('Unable to prepare dispatch delete.');
        }
        $deleteEntry->bind_param('i', $id);
        if (!$deleteEntry->execute()) {
            throw new RuntimeException('Unable to delete dispatch entry.');
        }

        $db->commit();
        ds_json(200, ['ok' => true, 'message' => 'Dispatch entry deleted and stock rolled back successfully.']);
    } catch (Throwable $e) {
        $db->rollback();
        ds_json(422, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'update_dispatch_status') {
    $payload = ds_get_payload();
    $id = (int)($payload['id'] ?? 0);
    $status = ds_clean($payload['status'] ?? '', 50);
    
    if ($id <= 0) {
        ds_json(400, ['ok' => false, 'error' => 'Invalid dispatch id.']);
    }
    
    if (!in_array($status, ['Pending', 'In Transit', 'Delivered'], true)) {
        ds_json(400, ['ok' => false, 'error' => 'Invalid delivery status.']);
    }
    
    // Check if dispatch exists
    $stmt = $db->prepare('SELECT id, dispatch_id, delivery_status, item_name, batch_no FROM dispatch_entries WHERE id = ? LIMIT 1');
    if (!$stmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to prepare query.']);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if (!$existing) {
        ds_json(404, ['ok' => false, 'error' => 'Dispatch entry not found.']);
    }
    
    // Update status
    $updateStmt = $db->prepare('UPDATE dispatch_entries SET delivery_status = ? WHERE id = ?');
    if (!$updateStmt) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to prepare update.']);
    }
    $updateStmt->bind_param('si', $status, $id);
    if (!$updateStmt->execute()) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to update dispatch status.']);
    }

    $strict = ds_truthy($payload['strict'] ?? '');
    $mismatches = ds_dispatch_qty_mismatches($db, [$id]);
    if ($strict && !empty($mismatches)) {
        ds_json(409, ['ok' => false, 'error' => 'Dispatch quantity mismatch detected for this entry.', 'mismatch_count' => count($mismatches)]);
    }

    $oldStatus = ds_clean($existing['delivery_status'] ?? 'Pending', 30);
    $dispatchCode = ds_clean($existing['dispatch_id'] ?? '', 60);
    $itemName = ds_clean($existing['item_name'] ?? '', 120);
    $batchNo = ds_clean($existing['batch_no'] ?? '', 120);
    if (function_exists('createDepartmentNotifications') && $oldStatus !== $status) {
        $message = 'Dispatch status updated to ' . $status;
        if ($itemName !== '') {
            $message .= ' for Item ' . $itemName;
        }
        if ($batchNo !== '') {
            $message .= ' (Batch ' . $batchNo . ')';
        }
        if ($dispatchCode !== '') {
            $message .= ' [' . $dispatchCode . ']';
        }
        createDepartmentNotifications(
            $db,
            ['dispatch', 'planning', 'packing', 'paperroll'],
            $id,
            $message,
            'info',
            '/modules/dispatch/index.php?dispatch_entry=' . rawurlencode((string)$id) . '&open=view&highlight=1'
        );
    }
    
    ds_json(200, ['ok' => true, 'message' => 'Dispatch status updated successfully.']);
}

if ($action === 'dispatch_reports') {
    $from = ds_date($_GET['from'] ?? '');
    $to = ds_date($_GET['to'] ?? '');
    $client = ds_clean($_GET['client'] ?? '', 255);
    $transportType = ds_clean($_GET['transport_type'] ?? '', 60);
    $item = ds_clean($_GET['item'] ?? '', 255);
    $strict = ds_truthy($_GET['strict'] ?? '');

    $itemExpr = "COALESCE(NULLIF(fs.item_name, ''), NULLIF(de.item_name, ''), 'Unknown Item')";
    $whereParts = [];

    if ($from) {
        $whereParts[] = "COALESCE(de.dispatch_date, de.entry_date) >= '" . $db->real_escape_string($from) . "'";
    }
    if ($to) {
        $whereParts[] = "COALESCE(de.dispatch_date, de.entry_date) <= '" . $db->real_escape_string($to) . "'";
    }
    if ($client !== '') {
        $whereParts[] = "de.client_name = '" . $db->real_escape_string($client) . "'";
    }
    if ($transportType !== '') {
        $whereParts[] = "de.transport_type = '" . $db->real_escape_string($transportType) . "'";
    }
    if ($item !== '') {
        $whereParts[] = $itemExpr . " = '" . $db->real_escape_string($item) . "'";
    }

    $whereSql = !empty($whereParts) ? (' WHERE ' . implode(' AND ', $whereParts)) : '';

    $mismatches = ds_dispatch_qty_mismatches($db, [], $whereSql);
    $mismatchCount = count($mismatches);
    if ($strict && $mismatchCount > 0) {
        ds_json(409, ['ok' => false, 'error' => 'Dispatch quantity mismatch detected.', 'mismatch_count' => $mismatchCount]);
    }

    $rows = [];
    $clientOptions = [];
    $itemOptions = [];
    $transportOptions = [];

    $detailRes = $db->query(
        "SELECT
            de.id AS dispatch_entry_id,
            de.dispatch_id,
            COALESCE(de.dispatch_date, de.entry_date) AS dispatch_date,
            DATE_FORMAT(COALESCE(de.dispatch_date, de.entry_date), '%Y-%m') AS month_key,
            de.client_name,
            {$itemExpr} AS item_name,
            COALESCE(NULLIF(di.batch_no, ''), NULLIF(de.batch_no, ''), '-') AS batch_no,
            COALESCE(NULLIF(de.transport_type, ''), 'Transport') AS transport_type,
            COALESCE(di.dispatch_qty, de.dispatch_qty, 0) AS dispatch_qty,
            CASE
                WHEN di.id IS NOT NULL AND COALESCE(de.dispatch_qty, 0) > 0 THEN COALESCE(de.transport_cost, 0) * (COALESCE(di.dispatch_qty, 0) / de.dispatch_qty)
                ELSE COALESCE(de.transport_cost, 0)
            END AS transport_cost
        FROM dispatch_entries de
        LEFT JOIN dispatch_items di ON di.dispatch_id = de.id
        LEFT JOIN finished_goods_stock fs ON fs.id = di.item_id"
        . $whereSql .
        " ORDER BY COALESCE(de.dispatch_date, de.entry_date) DESC, de.id DESC, di.id ASC"
    );

    if (!$detailRes) {
        ds_json(500, ['ok' => false, 'error' => 'Unable to load dispatch report analytics.']);
    }

    while ($row = $detailRes->fetch_assoc()) {
        $row['dispatch_qty'] = (float)($row['dispatch_qty'] ?? 0);
        $row['transport_cost'] = round((float)($row['transport_cost'] ?? 0), 2);
        $rows[] = $row;

        $clientName = trim((string)($row['client_name'] ?? ''));
        $itemName = trim((string)($row['item_name'] ?? ''));
        $transportName = trim((string)($row['transport_type'] ?? ''));

        if ($clientName !== '') {
            $clientOptions[$clientName] = true;
        }
        if ($itemName !== '') {
            $itemOptions[$itemName] = true;
        }
        if ($transportName !== '') {
            $transportOptions[$transportName] = true;
        }
    }

    $clients = array_keys($clientOptions);
    $items = array_keys($itemOptions);
    $transportTypes = array_keys($transportOptions);

    natcasesort($clients);
    natcasesort($items);

    $transportOrder = ['Own Vehicle' => 1, 'Toto' => 2, 'Courier' => 3, 'Transport' => 4];
    usort($transportTypes, static function ($a, $b) use ($transportOrder) {
        $aRank = $transportOrder[$a] ?? 99;
        $bRank = $transportOrder[$b] ?? 99;
        if ($aRank === $bRank) {
            return strcasecmp((string)$a, (string)$b);
        }
        return $aRank <=> $bRank;
    });

    ds_json(200, [
        'ok' => true,
        'rows' => $rows,
        'filter_options' => [
            'clients' => array_values($clients),
            'items' => array_values($items),
            'transportTypes' => array_values($transportTypes),
        ],
        'mismatch_count' => $mismatchCount,
    ]);
}

if ($action === 'dashboard_stats') {
    $from = ds_date($_GET['from'] ?? '');
    $to = ds_date($_GET['to'] ?? '');
    $client = ds_clean($_GET['client'] ?? '', 255);
    $item = ds_clean($_GET['item'] ?? '', 255);
    $status = ds_clean($_GET['status'] ?? '', 30);
    $search = ds_clean($_GET['search'] ?? '', 255);
    $strict = ds_truthy($_GET['strict'] ?? '');

    $whereParts = [];
    if ($from) {
        $whereParts[] = "COALESCE(de.dispatch_date, de.entry_date) >= '" . $db->real_escape_string($from) . "'";
    }
    if ($to) {
        $whereParts[] = "COALESCE(de.dispatch_date, de.entry_date) <= '" . $db->real_escape_string($to) . "'";
    }
    if ($client !== '') {
        $whereParts[] = "de.client_name LIKE '%" . $db->real_escape_string($client) . "%'";
    }
    if ($item !== '') {
        $whereParts[] = "de.item_name LIKE '%" . $db->real_escape_string($item) . "%'";
    }
    if ($status !== '') {
        $whereParts[] = "de.delivery_status = '" . $db->real_escape_string($status) . "'";
    }
    if ($search !== '') {
        $q = $db->real_escape_string($search);
        $whereParts[] = "(de.dispatch_id LIKE '%{$q}%' OR de.client_name LIKE '%{$q}%' OR de.item_name LIKE '%{$q}%' OR de.invoice_no LIKE '%{$q}%' OR de.batch_no LIKE '%{$q}%')";
    }
    $whereSql = !empty($whereParts) ? (' WHERE ' . implode(' AND ', $whereParts)) : '';

    $monthlyRows = [];
    $clientRows = [];
    $costRows = [];
    $transportCostSummary = [];
    $itemWiseByCategory = [];
    $categorySummaryRows = [];
    $categoryTotals = [];
    $mismatchCount = 0;

    $kpiEntryRes = $db->query(
        "SELECT
            COUNT(*) AS total_dispatches,
            COALESCE(SUM(de.transport_cost),0) AS total_cost,
            COALESCE(SUM(CASE WHEN de.delivery_status IN ('Pending','In Transit') THEN 1 ELSE 0 END),0) AS pending_transit
         FROM dispatch_entries de"
         . $whereSql
    );
    $kpiEntry = $kpiEntryRes ? ($kpiEntryRes->fetch_assoc() ?: []) : [];

    $kpiQtyRes = $db->query(
        "SELECT COALESCE(SUM(di.dispatch_qty),0) AS total_qty
         FROM dispatch_items di
         INNER JOIN dispatch_entries de ON de.id = di.dispatch_id"
         . $whereSql
    );
    $kpiQty = $kpiQtyRes ? ($kpiQtyRes->fetch_assoc() ?: []) : [];

    $monthlyRes = $db->query(
        "SELECT DATE_FORMAT(COALESCE(de.dispatch_date, de.entry_date),'%Y-%m') AS ym, COALESCE(SUM(di.dispatch_qty),0) AS qty
         FROM dispatch_entries de
         LEFT JOIN dispatch_items di ON di.dispatch_id = de.id"
         . $whereSql .
        " GROUP BY ym ORDER BY ym ASC"
    );
    if ($monthlyRes) {
        while ($r = $monthlyRes->fetch_assoc()) {
            $monthlyRows[] = $r;
        }
    }

    $clientRes = $db->query(
        "SELECT de.client_name, COALESCE(SUM(di.dispatch_qty),0) AS qty
         FROM dispatch_entries de
         LEFT JOIN dispatch_items di ON di.dispatch_id = de.id"
         . $whereSql .
        " GROUP BY de.client_name ORDER BY qty DESC LIMIT 8"
    );
    if ($clientRes) {
        while ($r = $clientRes->fetch_assoc()) {
            $clientRows[] = $r;
        }
    }

    $costRes = $db->query(
        "SELECT DATE_FORMAT(COALESCE(de.dispatch_date, de.entry_date),'%Y-%m') AS ym, COALESCE(SUM(de.transport_cost),0) AS cost
         FROM dispatch_entries de"
         . $whereSql .
        " GROUP BY ym ORDER BY ym ASC"
    );
    if ($costRes) {
        while ($r = $costRes->fetch_assoc()) {
            $costRows[] = $r;
        }
    }

    $transportCostRes = $db->query(
        "SELECT de.transport_type, COALESCE(SUM(de.transport_cost),0) AS cost
         FROM dispatch_entries de"
         . $whereSql .
        " GROUP BY de.transport_type ORDER BY cost DESC"
    );
    if ($transportCostRes) {
        while ($r = $transportCostRes->fetch_assoc()) {
            $transportCostSummary[] = $r;
        }
    }

    $mismatches = ds_dispatch_qty_mismatches($db, [], $whereSql);
    $mismatchCount = count($mismatches);
    if ($strict && $mismatchCount > 0) {
        ds_json(409, ['ok' => false, 'error' => 'Dispatch quantity mismatch detected.', 'mismatch_count' => $mismatchCount]);
    }

    $categorySourceExpr = ds_finished_goods_category_source_expr($db, 'fs', 'de');
    $categoryBucketSql = ds_dispatch_category_bucket_case($categorySourceExpr);

    $categorySummaryRes = $db->query(
        "SELECT
            {$categoryBucketSql} AS category_key,
            COUNT(DISTINCT de.id) AS dispatch_count,
            COALESCE(SUM(di.dispatch_qty), 0) AS qty
        FROM dispatch_entries de
        LEFT JOIN dispatch_items di ON di.dispatch_id = de.id
        LEFT JOIN finished_goods_stock fs ON fs.id = di.item_id"
        . $whereSql .
        " GROUP BY category_key"
    );
    if ($categorySummaryRes) {
        while ($r = $categorySummaryRes->fetch_assoc()) {
            $categorySummaryRows[(string)($r['category_key'] ?? 'other')] = [
                'dispatch_count' => (int)($r['dispatch_count'] ?? 0),
                'qty' => (float)($r['qty'] ?? 0),
            ];
        }
    }

    $itemWiseRes = $db->query(
        "SELECT
            CASE
                WHEN {$categoryBucketSql} = 'pos_paper_roll' AND {$categorySourceExpr} LIKE '%paperroll%' THEN 'paperroll'
                WHEN {$categoryBucketSql} = 'pos_paper_roll' THEN 'pos'
                WHEN {$categoryBucketSql} = 'one_ply' THEN 'one_ply'
                WHEN {$categoryBucketSql} = 'two_ply' THEN 'two_ply'
                WHEN {$categoryBucketSql} = 'barcode' THEN 'barcode'
                ELSE 'unmapped'
            END AS category_key,
            CASE
                WHEN {$categoryBucketSql} = 'pos_paper_roll' AND {$categorySourceExpr} LIKE '%paperroll%' THEN 'PaperRoll'
                WHEN {$categoryBucketSql} = 'pos_paper_roll' THEN 'POS'
                WHEN {$categoryBucketSql} = 'one_ply' THEN '1 Ply'
                WHEN {$categoryBucketSql} = 'two_ply' THEN '2 Ply'
                WHEN {$categoryBucketSql} = 'barcode' THEN 'Barcode'
                ELSE 'Unmapped'
            END AS category_label,
            COALESCE(NULLIF(de.item_name, ''), 'Unknown Item') AS item_name,
            COALESCE(SUM(di.dispatch_qty), 0) AS qty
        FROM dispatch_entries de
        LEFT JOIN dispatch_items di ON di.dispatch_id = de.id
        LEFT JOIN finished_goods_stock fs ON fs.id = di.item_id"
        . $whereSql .
        " GROUP BY category_key, category_label, item_name
          ORDER BY category_label ASC, qty DESC"
    );
    if ($itemWiseRes) {
        while ($r = $itemWiseRes->fetch_assoc()) {
            $catKey = (string)($r['category_key'] ?? 'unmapped');
            if (!isset($itemWiseByCategory[$catKey])) {
                $itemWiseByCategory[$catKey] = [
                    'category_key' => $catKey,
                    'category_label' => (string)($r['category_label'] ?? 'Unmapped'),
                    'items' => [],
                ];
                $categoryTotals[$catKey] = 0.0;
            }
            $qty = (float)($r['qty'] ?? 0);
            $itemWiseByCategory[$catKey]['items'][] = [
                'item_name' => (string)($r['item_name'] ?? 'Unknown Item'),
                'qty' => $qty,
            ];
            $categoryTotals[$catKey] += $qty;
        }
    }

    foreach ($itemWiseByCategory as $catKey => &$catPayload) {
        $catPayload['total_qty'] = (float)($categoryTotals[$catKey] ?? 0);
    }
    unset($catPayload);

    ds_json(200, [
        'ok' => true,
        'kpi' => [
            'total_dispatches' => (int)($kpiEntry['total_dispatches'] ?? 0),
            'total_qty' => (float)($kpiQty['total_qty'] ?? 0),
            'total_cost' => (float)($kpiEntry['total_cost'] ?? 0),
            'pending_transit' => (int)($kpiEntry['pending_transit'] ?? 0),
        ],
        'monthly_qty' => $monthlyRows,
        'client_qty' => $clientRows,
        'monthly_cost' => $costRows,
        'transport_cost_summary' => $transportCostSummary,
        'category_summary' => $categorySummaryRows,
        'item_wise_by_category' => $itemWiseByCategory,
        'mismatch_count' => $mismatchCount,
    ]);
}

ds_json(404, ['ok' => false, 'error' => 'Unknown action: ' . $action]);
