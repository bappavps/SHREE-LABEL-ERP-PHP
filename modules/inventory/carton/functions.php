<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';

function carton_db(): mysqli {
    return getDB();
}

function carton_ensure_tables(mysqli $db): void {
    $sqlItems = "CREATE TABLE IF NOT EXISTS carton_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        item_name VARCHAR(255) NOT NULL,
        status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_carton_item_name (item_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sqlStock = "CREATE TABLE IF NOT EXISTS carton_stock (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        item_id INT UNSIGNED NOT NULL,
        qty INT NOT NULL,
        type ENUM('ADD','CONSUME') NOT NULL,
        ref_type ENUM('MANUAL','JOB') NOT NULL DEFAULT 'MANUAL',
        ref_id INT NULL,
        remarks TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_carton_stock_item (item_id),
        KEY idx_carton_stock_type (type),
        KEY idx_carton_stock_created (created_at),
        CONSTRAINT fk_carton_stock_item FOREIGN KEY (item_id) REFERENCES carton_items(id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$db->query($sqlItems)) {
        throw new RuntimeException('Unable to initialize carton_items table: ' . $db->error);
    }
    if (!$db->query($sqlStock)) {
        throw new RuntimeException('Unable to initialize carton_stock table: ' . $db->error);
    }
}

function carton_clean_text($value, int $max = 255): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max);
    }
    return substr($text, 0, $max);
}

function carton_int($value): int {
    return (int)filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
}

function carton_item_exists(mysqli $db, int $itemId): bool {
    if ($itemId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM carton_items WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return (bool)$row;
}

function carton_add_stock_entry(mysqli $db, int $itemId, int $qty, string $type, string $refType = 'MANUAL', ?int $refId = null, string $remarks = ''): bool {
    if ($itemId <= 0 || $qty <= 0) {
        return false;
    }

    $type = strtoupper(trim($type));
    $refType = strtoupper(trim($refType));
    if ($type !== 'ADD' && $type !== 'CONSUME') {
        return false;
    }
    if ($refType !== 'MANUAL' && $refType !== 'JOB') {
        return false;
    }
    if (!carton_item_exists($db, $itemId)) {
        return false;
    }

    $remarks = carton_clean_text($remarks, 2000);

    $stmt = $db->prepare('INSERT INTO carton_stock (item_id, qty, type, ref_type, ref_id, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    if (!$stmt) {
        return false;
    }

    if ($refId === null || $refId <= 0) {
        $refId = null;
    }

    $stmt->bind_param('iissis', $itemId, $qty, $type, $refType, $refId, $remarks);
    return $stmt->execute();
}

function carton_stock_balance(mysqli $db, int $itemId): int {
    if ($itemId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("SELECT
        COALESCE(SUM(CASE WHEN type = 'ADD' THEN qty ELSE 0 END), 0) AS added,
        COALESCE(SUM(CASE WHEN type = 'CONSUME' THEN qty ELSE 0 END), 0) AS consumed
        FROM carton_stock
        WHERE item_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['added' => 0, 'consumed' => 0];

    return max(0, ((int)$row['added']) - ((int)$row['consumed']));
}

function carton_period_bounds(string $period): array {
    $today = new DateTime('today');
    $start = clone $today;
    $end = clone $today;

    switch ($period) {
        case 'weekly':
            $start->modify('monday this week');
            $end->modify('sunday this week');
            break;
        case 'monthly':
            $start->modify('first day of this month');
            $end->modify('last day of this month');
            break;
        case 'yearly':
            $start->setDate((int)$today->format('Y'), 1, 1);
            $end->setDate((int)$today->format('Y'), 12, 31);
            break;
        default:
            break;
    }

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function deduct_carton($item_id, $qty, $job_id) {
    $db = carton_db();
    carton_ensure_tables($db);

    $itemId = carton_int($item_id);
    $qtyInt = carton_int($qty);
    $jobId = carton_int($job_id);

    if ($itemId <= 0 || $qtyInt <= 0 || $jobId <= 0) {
        return ['ok' => false, 'error' => 'Invalid carton deduction payload.'];
    }

    $available = carton_stock_balance($db, $itemId);
    if ($qtyInt > $available) {
        return ['ok' => false, 'error' => 'Insufficient carton stock.', 'available' => $available];
    }

    $ok = carton_add_stock_entry($db, $itemId, $qtyInt, 'CONSUME', 'JOB', $jobId, 'Auto consume from packing/job');
    if (!$ok) {
        return ['ok' => false, 'error' => 'Unable to save carton deduction.'];
    }

    return ['ok' => true, 'remaining' => ($available - $qtyInt)];
}
