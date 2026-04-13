<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json');
$db = getDB();

function rmJson($ok, $payload = []) {
    echo json_encode(array_merge(['ok' => (bool)$ok], $payload));
    exit;
}

function rmEnsureSchema(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS requisitions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        department VARCHAR(120) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        category ENUM('Paper','Ink','Plate','Consumable','Stationary','Others') NOT NULL,
        qty DECIMAL(12,2) NOT NULL,
        unit ENUM('Kg','Nos','Meter') NOT NULL,
        required_date DATE NOT NULL,
        priority ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal',
        remarks TEXT NULL,
        attachment VARCHAR(255) NULL,
        status ENUM('pending','approved','rejected','po_created') NOT NULL DEFAULT 'pending',
        approved_by INT NULL,
        approved_date DATETIME NULL,
        admin_comment TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_req_user (user_id),
        INDEX idx_req_status (status),
        INDEX idx_req_required_date (required_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requisition_id INT NOT NULL,
        vendor_name VARCHAR(255) NOT NULL,
        rate DECIMAL(12,2) NOT NULL,
        gst DECIMAL(8,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL,
        delivery_date DATE NULL,
        payment_terms VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_po_requisition (requisition_id),
        CONSTRAINT fk_po_requisition FOREIGN KEY (requisition_id)
            REFERENCES requisitions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS requisition_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requisition_id INT NOT NULL,
        sl_no INT NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        category ENUM('Paper','Ink','Plate','Consumable','Stationary','Others') NOT NULL,
        qty DECIMAL(12,2) NOT NULL,
        unit ENUM('Kg','Nos','Meter') NOT NULL,
        item_remarks VARCHAR(255) NULL,
        item_image VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_req_item_req (requisition_id),
        CONSTRAINT fk_req_items_requisition FOREIGN KEY (requisition_id)
            REFERENCES requisitions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Keep enum values in sync for existing installations.
    $db->query("ALTER TABLE requisitions MODIFY category ENUM('Paper','Ink','Plate','Consumable','Stationary','Others') NOT NULL");
}

function rmCanAdmin(): bool {
    $byRole = hasRole('admin', 'manager', 'system_admin', 'super_admin') || isAdmin();
    $byPolicy = function_exists('canAccessPath') ? canAccessPath('/modules/requisition-management/admin.php') : true;
    return $byRole && $byPolicy;
}

function rmCanAccounts(): bool {
    return hasRole('accounts', 'account', 'purchase', 'admin', 'system_admin', 'super_admin') || isAdmin();
}

function rmCurrentStockHint(mysqli $db, string $itemName, string $category): string {
    if (strcasecmp($category, 'Paper') !== 0) return 'N/A';

    $stmt = $db->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(length_mtr),0) AS total_len FROM paper_stock WHERE paper_type = ? AND status IN ('Main','Stock','Job Assign')");
    if (!$stmt) return 'N/A';
    $stmt->bind_param('s', $itemName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $count = (int)($row['c'] ?? 0);
    $len = (float)($row['total_len'] ?? 0);
    return $count > 0 ? ($count . ' rolls / ' . round($len, 2) . ' m') : '0';
}

function rmLoadItemsMap(mysqli $db, array $requisitionIds): array {
    if (!$requisitionIds) return [];
    $ids = array_values(array_unique(array_map('intval', $requisitionIds)));
    $ids = array_filter($ids, static fn($id) => $id > 0);
    if (!$ids) return [];

    $in = implode(',', $ids);
    $rows = $db->query("SELECT * FROM requisition_items WHERE requisition_id IN ($in) ORDER BY requisition_id ASC, sl_no ASC");
    if (!$rows) return [];

    $map = [];
    while ($r = $rows->fetch_assoc()) {
        $rid = (int)($r['requisition_id'] ?? 0);
        if ($rid <= 0) continue;
        $r['item_image_url'] = !empty($r['item_image']) ? (BASE_URL . '/' . ltrim((string)$r['item_image'], '/')) : null;
        if (!isset($map[$rid])) $map[$rid] = [];
        $map[$rid][] = $r;
    }
    return $map;
}

rmEnsureSchema($db);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($action === 'create_requisition') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) rmJson(false, ['error' => 'Invalid CSRF token']);

    $department = trim((string)($_POST['department'] ?? ''));
    $requiredDate = trim((string)($_POST['required_date'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'Normal'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $itemNames = $_POST['item_name'] ?? [];
    $categories = $_POST['category'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $units = $_POST['unit'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];

    if (!is_array($itemNames)) $itemNames = [];
    if (!is_array($categories)) $categories = [];
    if (!is_array($qtys)) $qtys = [];
    if (!is_array($units)) $units = [];
    if (!is_array($itemRemarks)) $itemRemarks = [];

    if ($department === '' || $requiredDate === '') {
        rmJson(false, ['error' => 'Please fill all required fields']);
    }

    if (!in_array($priority, ['Normal', 'Urgent'], true)) {
        $priority = 'Normal';
    }

    $allowedCategories = ['Paper', 'Ink', 'Plate', 'Consumable', 'Stationary', 'Others'];
    $allowedUnits = ['Kg', 'Nos', 'Meter'];

    $items = [];
    $totalQty = 0.0;
    $firstItemName = '';
    $firstCategory = 'Others';
    $firstUnit = 'Nos';
    $rawImageNames = $_FILES['item_image']['name'] ?? [];
    $rawImageTmp = $_FILES['item_image']['tmp_name'] ?? [];

    foreach ($itemNames as $i => $nameRaw) {
        $itemName = trim((string)$nameRaw);
        $category = trim((string)($categories[$i] ?? ''));
        $qty = (float)($qtys[$i] ?? 0);
        $unit = trim((string)($units[$i] ?? ''));
        $itemRemark = trim((string)($itemRemarks[$i] ?? ''));

        if ($itemName === '' && $qty <= 0 && $category === '' && $unit === '') {
            continue;
        }

        if ($itemName === '' || $qty <= 0 || !in_array($category, $allowedCategories, true) || !in_array($unit, $allowedUnits, true)) {
            rmJson(false, ['error' => 'Each item must have valid name, category, qty and unit']);
        }

        $itemImagePath = null;
        $hasImage = isset($rawImageNames[$i]) && (string)$rawImageNames[$i] !== '' && isset($rawImageTmp[$i]) && is_uploaded_file((string)$rawImageTmp[$i]);
        if ($hasImage) {
            $ext = strtolower(pathinfo((string)$rawImageNames[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                rmJson(false, ['error' => 'Only image files are allowed for item image']);
            }
            $uploadDir = __DIR__ . '/../../uploads/requisitions/items';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                rmJson(false, ['error' => 'Failed to create item image directory']);
            }
            $imgName = 'item_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $uploadDir . '/' . $imgName;
            if (!move_uploaded_file((string)$rawImageTmp[$i], $target)) {
                rmJson(false, ['error' => 'Failed to upload item image']);
            }
            $itemImagePath = 'uploads/requisitions/items/' . $imgName;
        }

        if ($firstItemName === '') {
            $firstItemName = $itemName;
            $firstCategory = $category;
            $firstUnit = $unit;
        }
        $totalQty += $qty;

        $items[] = [
            'item_name' => $itemName,
            'category' => $category,
            'qty' => $qty,
            'unit' => $unit,
            'item_remarks' => $itemRemark,
            'item_image' => $itemImagePath
        ];
    }

    if (!$items) {
        rmJson(false, ['error' => 'At least one item is required']);
    }

    $itemSummary = $firstItemName;
    if (count($items) > 1) {
        $itemSummary .= ' (+' . (count($items) - 1) . ' items)';
    }

    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
        $allowed = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
        $ext = strtolower(pathinfo((string)$_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            rmJson(false, ['error' => 'Attachment type is not allowed']);
        }
        $uploadDir = __DIR__ . '/../../uploads/requisitions';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            rmJson(false, ['error' => 'Failed to create upload directory']);
        }
        $name = 'req_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $uploadDir . '/' . $name;
        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $target)) {
            rmJson(false, ['error' => 'Failed to upload file']);
        }
        $attachmentPath = 'uploads/requisitions/' . $name;
    }

    $stmt = $db->prepare("INSERT INTO requisitions
        (user_id, department, item_name, category, qty, unit, required_date, priority, remarks, attachment, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('isssdsssss', $userId, $department, $itemSummary, $firstCategory, $totalQty, $firstUnit, $requiredDate, $priority, $remarks, $attachmentPath);
    $ok = $stmt->execute();
    $reqId = (int)$stmt->insert_id;
    $stmt->close();
    if (!$ok) rmJson(false, ['error' => 'Failed to save requisition']);

    $stmtItem = $db->prepare("INSERT INTO requisition_items
        (requisition_id, sl_no, item_name, category, qty, unit, item_remarks, item_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmtItem) rmJson(false, ['error' => 'Failed to prepare requisition items']);

    foreach ($items as $idx => $item) {
        $sl = $idx + 1;
        $name = (string)$item['item_name'];
        $cat = (string)$item['category'];
        $qty = (float)$item['qty'];
        $unit = (string)$item['unit'];
        $itemRemark = (string)$item['item_remarks'];
        $itemImage = $item['item_image'] !== null ? (string)$item['item_image'] : null;
        $stmtItem->bind_param('iissdsss', $reqId, $sl, $name, $cat, $qty, $unit, $itemRemark, $itemImage);
        if (!$stmtItem->execute()) {
            $stmtItem->close();
            rmJson(false, ['error' => 'Failed to save requisition items']);
        }
    }
    $stmtItem->close();

    rmJson(true, ['message' => 'Requisition submitted']);
}

if ($action === 'list_my') {
    $stmt = $db->prepare("SELECT r.*,
            u.name AS approved_by_name
        FROM requisitions r
        LEFT JOIN users u ON u.id = r.approved_by
        WHERE r.user_id = ?
        ORDER BY r.id DESC");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['created_at'] = !empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-';
        $r['current_stock_hint'] = rmCurrentStockHint($db, (string)$r['item_name'], (string)$r['category']);
        $r['attachment_url'] = !empty($r['attachment']) ? (BASE_URL . '/' . ltrim((string)$r['attachment'], '/')) : null;
    }
    unset($r);

    $itemMap = rmLoadItemsMap($db, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $rid = (int)($r['id'] ?? 0);
        $r['items'] = $itemMap[$rid] ?? [];
    }
    unset($r);

    rmJson(true, ['rows' => $rows]);
}

if ($action === 'dashboard_summary') {
    $canSeeAll = rmCanAdmin() || rmCanAccounts();

    $summary = [
        'total_requisitions' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'po_created' => 0,
        'pending_admin_queue' => 0,
        'approved_accounts_queue' => 0,
        'total_purchase_orders' => 0
    ];

    if ($canSeeAll) {
        $rows = $db->query("SELECT status, COUNT(*) AS c FROM requisitions GROUP BY status")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $count = (int)($r['c'] ?? 0);
            if (isset($summary[$status])) {
                $summary[$status] = $count;
            }
            $summary['total_requisitions'] += $count;
        }
    } else {
        $stmt = $db->prepare("SELECT status, COUNT(*) AS c FROM requisitions WHERE user_id = ? GROUP BY status");
        if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $count = (int)($r['c'] ?? 0);
            if (isset($summary[$status])) {
                $summary[$status] = $count;
            }
            $summary['total_requisitions'] += $count;
        }
    }

    if (rmCanAdmin()) {
        $row = $db->query("SELECT COUNT(*) AS c FROM requisitions WHERE status = 'pending'")->fetch_assoc();
        $summary['pending_admin_queue'] = (int)($row['c'] ?? 0);
    }

    if (rmCanAccounts()) {
        $rowApproved = $db->query("SELECT COUNT(*) AS c FROM requisitions WHERE status = 'approved'")->fetch_assoc();
        $summary['approved_accounts_queue'] = (int)($rowApproved['c'] ?? 0);

        $rowPo = $db->query("SELECT COUNT(*) AS c FROM purchase_orders")->fetch_assoc();
        $summary['total_purchase_orders'] = (int)($rowPo['c'] ?? 0);
    }

    rmJson(true, ['summary' => $summary]);
}

if ($action === 'list_pending_admin' || $action === 'list_admin') {
    if (!rmCanAdmin()) rmJson(false, ['error' => 'Access denied']);

    $rows = $db->query("SELECT r.*, u.name AS user_name
        FROM requisitions r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE r.status IN ('pending','approved','rejected')
        ORDER BY FIELD(r.status,'pending','approved','rejected'), r.priority='Urgent' DESC, r.id DESC")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as &$r) {
        $r['attachment_url'] = !empty($r['attachment']) ? (BASE_URL . '/' . ltrim((string)$r['attachment'], '/')) : null;
    }
    unset($r);

    $itemMap = rmLoadItemsMap($db, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $rid = (int)($r['id'] ?? 0);
        $r['items'] = $itemMap[$rid] ?? [];
    }
    unset($r);

    rmJson(true, ['rows' => $rows]);
}

if ($action === 'get_requisition_detail') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) rmJson(false, ['error' => 'Invalid requisition ID']);

    $stmt = $db->prepare("SELECT r.*, u.name AS user_name, au.name AS approved_by_name
        FROM requisitions r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN users au ON au.id = r.approved_by
        WHERE r.id = ? LIMIT 1");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) rmJson(false, ['error' => 'Requisition not found']);

    $isOwner = (int)($row['user_id'] ?? 0) === $userId;
    if (!$isOwner && !rmCanAdmin() && !rmCanAccounts()) {
        rmJson(false, ['error' => 'Access denied']);
    }

    $itemMap = rmLoadItemsMap($db, [$id]);
    $row['items'] = $itemMap[$id] ?? [];
    $row['attachment_url'] = !empty($row['attachment']) ? (BASE_URL . '/' . ltrim((string)$row['attachment'], '/')) : null;
    $row['created_at_fmt'] = !empty($row['created_at']) ? date('d M Y H:i', strtotime((string)$row['created_at'])) : '-';
    $row['can_edit'] = ($isOwner || rmCanAdmin()) && (string)($row['status'] ?? '') === 'pending';
    $row['can_delete'] = ($isOwner || rmCanAdmin()) && in_array((string)($row['status'] ?? ''), ['pending', 'rejected'], true);

    rmJson(true, ['row' => $row]);
}

if ($action === 'update_requisition') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) rmJson(false, ['error' => 'Invalid CSRF token']);
    $id = (int)($_POST['id'] ?? 0);
    $requiredDate = trim((string)($_POST['required_date'] ?? ''));
    $priority = trim((string)($_POST['priority'] ?? 'Normal'));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    if ($id <= 0 || $requiredDate === '') rmJson(false, ['error' => 'Invalid edit request']);
    if (!in_array($priority, ['Normal', 'Urgent'], true)) $priority = 'Normal';

    $stmt = $db->prepare("SELECT user_id, status FROM requisitions WHERE id = ? LIMIT 1");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) rmJson(false, ['error' => 'Requisition not found']);

    $isOwner = (int)($row['user_id'] ?? 0) === $userId;
    if (!$isOwner && !rmCanAdmin()) rmJson(false, ['error' => 'Access denied']);
    if ((string)($row['status'] ?? '') !== 'pending') rmJson(false, ['error' => 'Only pending requisitions can be edited']);

    $itemIds = $_POST['item_id'] ?? [];
    $itemNames = $_POST['item_name'] ?? [];
    $categories = $_POST['category'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $units = $_POST['unit'] ?? [];
    $itemRemarks = $_POST['item_remarks'] ?? [];

    $hasItemPayload = is_array($itemNames) && count($itemNames) > 0;
    $firstItemName = null;
    $firstCategory = null;
    $firstUnit = null;
    $totalQty = 0.0;

    if ($hasItemPayload) {
        if (!is_array($itemIds)) $itemIds = [];
        if (!is_array($categories) || !is_array($qtys) || !is_array($units)) {
            rmJson(false, ['error' => 'Invalid item edit payload']);
        }
        if (!is_array($itemRemarks)) $itemRemarks = [];

        $allowedCategories = ['Paper', 'Ink', 'Plate', 'Consumable', 'Stationary', 'Others'];
        $allowedUnits = ['Kg', 'Nos', 'Meter'];

        $existingRows = $db->query("SELECT id, item_image FROM requisition_items WHERE requisition_id = " . (int)$id);
        $existingMap = [];
        if ($existingRows) {
            while ($er = $existingRows->fetch_assoc()) {
                $existingMap[(int)$er['id']] = $er;
            }
        }
        $keptIds = [];

        $stmtUpdate = $db->prepare("UPDATE requisition_items
            SET sl_no = ?, item_name = ?, category = ?, qty = ?, unit = ?, item_remarks = ?, item_image = ?
            WHERE id = ? AND requisition_id = ?");
        if (!$stmtUpdate) rmJson(false, ['error' => 'Failed to prepare item update']);

        $stmtInsert = $db->prepare("INSERT INTO requisition_items
            (requisition_id, sl_no, item_name, category, qty, unit, item_remarks, item_image)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmtInsert) {
            $stmtUpdate->close();
            rmJson(false, ['error' => 'Failed to prepare item insert']);
        }

        $sl = 1;
        foreach ($itemNames as $i => $nameRaw) {
            $itemId = (int)($itemIds[$i] ?? 0);
            $name = trim((string)$nameRaw);
            $cat = trim((string)($categories[$i] ?? ''));
            $qty = (float)($qtys[$i] ?? 0);
            $unit = trim((string)($units[$i] ?? ''));
            $itemRemark = trim((string)($itemRemarks[$i] ?? ''));

            if ($name === '' || $qty <= 0 || !in_array($cat, $allowedCategories, true) || !in_array($unit, $allowedUnits, true)) {
                $stmtUpdate->close();
                $stmtInsert->close();
                rmJson(false, ['error' => 'Invalid item values for update']);
            }

            if ($firstItemName === null) {
                $firstItemName = $name;
                $firstCategory = $cat;
                $firstUnit = $unit;
            }
            $totalQty += $qty;

            $imagePath = null;
            if ($itemId > 0 && isset($existingMap[$itemId])) {
                $imagePath = (string)($existingMap[$itemId]['item_image'] ?? '');
                if ($imagePath === '') $imagePath = null;
            }

            $fileKey = 'item_image_new_' . $i;
            if (isset($_FILES[$fileKey]) && is_uploaded_file((string)($_FILES[$fileKey]['tmp_name'] ?? ''))) {
                $ext = strtolower(pathinfo((string)($_FILES[$fileKey]['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $stmtUpdate->close();
                    $stmtInsert->close();
                    rmJson(false, ['error' => 'Only image files are allowed for item image']);
                }
                $uploadDir = __DIR__ . '/../../uploads/requisitions/items';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $stmtUpdate->close();
                    $stmtInsert->close();
                    rmJson(false, ['error' => 'Failed to create item image directory']);
                }
                $imgName = 'item_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = $uploadDir . '/' . $imgName;
                if (!move_uploaded_file((string)$_FILES[$fileKey]['tmp_name'], $target)) {
                    $stmtUpdate->close();
                    $stmtInsert->close();
                    rmJson(false, ['error' => 'Failed to upload item image']);
                }
                $imagePath = 'uploads/requisitions/items/' . $imgName;
            }

            if ($itemId > 0 && isset($existingMap[$itemId])) {
                $stmtUpdate->bind_param('issdsssii', $sl, $name, $cat, $qty, $unit, $itemRemark, $imagePath, $itemId, $id);
                if (!$stmtUpdate->execute()) {
                    $stmtUpdate->close();
                    $stmtInsert->close();
                    rmJson(false, ['error' => 'Failed to update requisition items']);
                }
                $keptIds[] = $itemId;
            } else {
                $stmtInsert->bind_param('iissdsss', $id, $sl, $name, $cat, $qty, $unit, $itemRemark, $imagePath);
                if (!$stmtInsert->execute()) {
                    $stmtUpdate->close();
                    $stmtInsert->close();
                    rmJson(false, ['error' => 'Failed to add requisition item']);
                }
                $keptIds[] = (int)$stmtInsert->insert_id;
            }
            $sl++;
        }

        $stmtUpdate->close();
        $stmtInsert->close();

        if ($firstItemName === null) {
            rmJson(false, ['error' => 'At least one item is required']);
        }

        foreach ($existingMap as $existingId => $existingRow) {
            if (!in_array((int)$existingId, $keptIds, true)) {
                $stmtDelItem = $db->prepare("DELETE FROM requisition_items WHERE id = ? AND requisition_id = ?");
                if ($stmtDelItem) {
                    $stmtDelItem->bind_param('ii', $existingId, $id);
                    $stmtDelItem->execute();
                    $stmtDelItem->close();
                }
            }
        }
    }

    if ($hasItemPayload) {
        $itemSummary = $firstItemName;
        if (count($itemNames) > 1) {
            $itemSummary .= ' (+' . (count($itemNames) - 1) . ' items)';
        }

        $stmt = $db->prepare("UPDATE requisitions
            SET required_date = ?, priority = ?, remarks = ?, item_name = ?, category = ?, qty = ?, unit = ?, updated_at = NOW()
            WHERE id = ? LIMIT 1");
        if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
        $stmt->bind_param('sssssdsi', $requiredDate, $priority, $remarks, $itemSummary, $firstCategory, $totalQty, $firstUnit, $id);
    } else {
        $stmt = $db->prepare("UPDATE requisitions
            SET required_date = ?, priority = ?, remarks = ?, updated_at = NOW()
            WHERE id = ? LIMIT 1");
        if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
        $stmt->bind_param('sssi', $requiredDate, $priority, $remarks, $id);
    }

    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) rmJson(false, ['error' => 'Failed to update requisition']);

    rmJson(true, ['message' => 'Requisition updated']);
}

if ($action === 'delete_requisition') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) rmJson(false, ['error' => 'Invalid CSRF token']);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) rmJson(false, ['error' => 'Invalid requisition ID']);

    $stmt = $db->prepare("SELECT user_id, status FROM requisitions WHERE id = ? LIMIT 1");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) rmJson(false, ['error' => 'Requisition not found']);

    $isOwner = (int)($row['user_id'] ?? 0) === $userId;
    $isAdminUser = rmCanAdmin();
    if (!$isOwner && !$isAdminUser) rmJson(false, ['error' => 'Access denied']);

    $status = (string)($row['status'] ?? '');
    if ($isAdminUser) {
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            rmJson(false, ['error' => 'This requisition cannot be deleted from approval panel']);
        }
    } else {
        if (!in_array($status, ['pending', 'rejected'], true)) {
            rmJson(false, ['error' => 'Only pending/rejected requisition can be deleted']);
        }
    }

    $stmt = $db->prepare("DELETE FROM requisitions WHERE id = ? LIMIT 1");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) rmJson(false, ['error' => 'Failed to delete requisition']);

    rmJson(true, ['message' => 'Requisition deleted']);
}

if ($action === 'admin_decide') {
    if (!rmCanAdmin()) rmJson(false, ['error' => 'Access denied']);
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) rmJson(false, ['error' => 'Invalid CSRF token']);

    $id = (int)($_POST['id'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? ''));
    $approvedQty = (float)($_POST['approved_qty'] ?? 0);
    $comment = trim((string)($_POST['admin_comment'] ?? ''));

    if ($id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        rmJson(false, ['error' => 'Invalid request']);
    }

    $status = $decision;
    $approvedBy = (int)($_SESSION['user_id'] ?? 0);

    if ($decision === 'approved' && $approvedQty <= 0) {
        rmJson(false, ['error' => 'Approved quantity must be greater than zero']);
    }

    if ($decision === 'approved') {
        $stmt = $db->prepare("UPDATE requisitions
            SET qty = ?, status = ?, approved_by = ?, approved_date = NOW(), admin_comment = ?
            WHERE id = ? AND status = 'pending'");
        if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
        $stmt->bind_param('dsisi', $approvedQty, $status, $approvedBy, $comment, $id);
    } else {
        $stmt = $db->prepare("UPDATE requisitions
            SET status = ?, approved_by = ?, approved_date = NOW(), admin_comment = ?
            WHERE id = ? AND status = 'pending'");
        if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
        $stmt->bind_param('sisi', $status, $approvedBy, $comment, $id);
    }

    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) rmJson(false, ['error' => 'No pending requisition updated']);
    rmJson(true, ['message' => 'Requisition updated']);
}

if ($action === 'list_approved_accounts') {
    if (!rmCanAccounts()) rmJson(false, ['error' => 'Access denied']);

    $rows = $db->query("SELECT r.*, u.name AS approved_by_name
        FROM requisitions r
        LEFT JOIN users u ON u.id = r.approved_by
        WHERE r.status = 'approved'
        ORDER BY r.approved_date DESC, r.id DESC")->fetch_all(MYSQLI_ASSOC);

    $itemMap = rmLoadItemsMap($db, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $rid = (int)($r['id'] ?? 0);
        $r['items'] = $itemMap[$rid] ?? [];
    }
    unset($r);

    rmJson(true, ['rows' => $rows]);
}

if ($action === 'create_po') {
    if (!rmCanAccounts()) rmJson(false, ['error' => 'Access denied']);
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) rmJson(false, ['error' => 'Invalid CSRF token']);

    $reqId = (int)($_POST['requisition_id'] ?? 0);
    $vendor = trim((string)($_POST['vendor_name'] ?? ''));
    $rate = (float)($_POST['rate'] ?? 0);
    $gst = (float)($_POST['gst'] ?? 0);
    $deliveryDate = trim((string)($_POST['delivery_date'] ?? ''));
    $paymentTerms = trim((string)($_POST['payment_terms'] ?? ''));

    if ($reqId <= 0 || $vendor === '' || $rate < 0 || $gst < 0 || $deliveryDate === '') {
        rmJson(false, ['error' => 'Please fill all required PO fields']);
    }

    $stmt = $db->prepare("SELECT id, qty, status FROM requisitions WHERE id = ? LIMIT 1");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('i', $reqId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req || $req['status'] !== 'approved') {
        rmJson(false, ['error' => 'Requisition is not approved for PO']);
    }

    $qty = (float)$req['qty'];
    $subtotal = $qty * $rate;
    $totalAmount = $subtotal + (($subtotal * $gst) / 100);

    $stmt = $db->prepare("INSERT INTO purchase_orders
        (requisition_id, vendor_name, rate, gst, total_amount, delivery_date, payment_terms)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) rmJson(false, ['error' => 'Prepare failed']);
    $stmt->bind_param('isdddss', $reqId, $vendor, $rate, $gst, $totalAmount, $deliveryDate, $paymentTerms);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) rmJson(false, ['error' => 'Failed to create PO']);

    $stmt = $db->prepare("UPDATE requisitions SET status = 'po_created' WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $stmt->close();
    }

    rmJson(true, ['message' => 'PO created', 'total_amount' => round($totalAmount, 2)]);
}

if ($action === 'list_po') {
    if (!rmCanAccounts()) rmJson(false, ['error' => 'Access denied']);

    $rows = $db->query("SELECT * FROM purchase_orders ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['created_at'] = !empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-';
    }
    unset($r);

    rmJson(true, ['rows' => $rows]);
}

rmJson(false, ['error' => 'Invalid action']);
