<?php
ob_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function lmRespond($ok, $message, $data = null, $status = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode([
        'ok' => (bool)$ok,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

function lmFail($message, $status = 400) {
    lmRespond(false, $message, null, $status);
}

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($exception) {
    $message = 'Leave API error: ' . $exception->getMessage();
    lmRespond(false, $message, null, 500);
});

function lmEnsureSchema() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();
    $schema = DB_NAME;

    $tableExists = function($table) use ($db, $schema) {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
        $stmt->bind_param('ss', $schema, $table);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $columnExists = function($table, $column) use ($db, $schema) {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
        $stmt->bind_param('sss', $schema, $table, $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    if (!$tableExists('leaves')) {
        $sql = "CREATE TABLE leaves (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            department VARCHAR(120) NOT NULL,
            leave_type ENUM('Sick','Casual','Emergency','Other') NOT NULL DEFAULT 'Casual',
            from_date DATE NOT NULL,
            to_date DATE NOT NULL,
            total_days INT NOT NULL DEFAULT 1,
            reason_text TEXT NULL,
            voice_file VARCHAR(255) NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_remark TEXT NULL,
            approved_by INT NULL,
            approved_date DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leaves_user (user_id),
            INDEX idx_leaves_status (status),
            INDEX idx_leaves_date (from_date, to_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$db->query($sql)) {
            lmFail('Unable to create leave table. ' . $db->error, 500);
        }
    }

    $columns = [
        'user_id' => 'ALTER TABLE leaves ADD COLUMN user_id INT NOT NULL AFTER id',
        'department' => 'ALTER TABLE leaves ADD COLUMN department VARCHAR(120) NOT NULL AFTER user_id',
        'leave_type' => "ALTER TABLE leaves ADD COLUMN leave_type ENUM('Sick','Casual','Emergency','Other') NOT NULL DEFAULT 'Casual' AFTER department",
        'from_date' => 'ALTER TABLE leaves ADD COLUMN from_date DATE NOT NULL AFTER leave_type',
        'to_date' => 'ALTER TABLE leaves ADD COLUMN to_date DATE NOT NULL AFTER from_date',
        'total_days' => 'ALTER TABLE leaves ADD COLUMN total_days INT NOT NULL DEFAULT 1 AFTER to_date',
        'reason_text' => 'ALTER TABLE leaves ADD COLUMN reason_text TEXT NULL AFTER total_days',
        'voice_file' => 'ALTER TABLE leaves ADD COLUMN voice_file VARCHAR(255) NULL AFTER reason_text',
        'status' => "ALTER TABLE leaves ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER voice_file",
        'admin_remark' => 'ALTER TABLE leaves ADD COLUMN admin_remark TEXT NULL AFTER status',
        'approved_by' => 'ALTER TABLE leaves ADD COLUMN approved_by INT NULL AFTER admin_remark',
        'approved_date' => 'ALTER TABLE leaves ADD COLUMN approved_date DATETIME NULL AFTER approved_by',
        'created_at' => 'ALTER TABLE leaves ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER approved_date',
        'updated_at' => 'ALTER TABLE leaves ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    ];

    foreach ($columns as $column => $sql) {
        if (!$columnExists('leaves', $column)) {
            $db->query($sql);
        }
    }
}

function lmCanAdmin() {
    return hasRole('admin', 'manager', 'system_admin', 'super_admin') || isAdmin();
}

function lmAllowedDepartments() {
    $departments = erp_get_job_card_departments();
    if (!in_array('Others', $departments, true)) {
        $departments[] = 'Others';
    }
    return $departments;
}

function lmNormalizedDepartment($value) {
    return erp_canonical_department_label((string)$value, lmAllowedDepartments());
}

function lmEmployeeRecord(mysqli $db, $userId) {
    $stmt = $db->prepare('SELECT id, name, email, role, group_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function lmLeaveCode($id, $createdAt) {
    $year = '0000';
    if ($createdAt) {
        $timestamp = strtotime((string)$createdAt);
        if ($timestamp !== false) {
            $year = date('Y', $timestamp);
        }
    }
    return 'LV-' . $year . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function lmDaysBetween($fromDate, $toDate) {
    $start = strtotime((string)$fromDate);
    $end = strtotime((string)$toDate);
    if ($start === false || $end === false || $end < $start) {
        return 0;
    }
    return (int)floor(($end - $start) / 86400) + 1;
}

function lmStoreVoiceFile(array $file) {
    if (empty($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        lmFail('Voice file upload failed.');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        lmFail('Invalid voice file upload.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        lmFail('Voice file is empty.');
    }
    if ($size > 12 * 1024 * 1024) {
        lmFail('Voice file is too large. Maximum 12 MB allowed.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmpName);
    $extMap = [
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'application/octet-stream' => 'webm',
    ];
    $extension = $extMap[$mime] ?? '';
    if ($extension === '') {
        $original = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extension = in_array($original, ['webm', 'ogg', 'm4a', 'mp3', 'wav'], true) ? $original : 'webm';
    }

    $relativeDir = 'uploads/leaves/voice/' . date('Y') . '/' . date('m');
    $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        lmFail('Unable to create leave voice upload directory.', 500);
    }

    $filename = 'leave_voice_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $absolutePath = $absoluteDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $absolutePath)) {
        lmFail('Unable to save voice file.', 500);
    }

    return $relativeDir . '/' . $filename;
}

function lmFetchLeave(mysqli $db, $leaveId, $viewerId, $adminView = false) {
    $sql = 'SELECT l.*, u.name AS employee_name, u.email AS employee_email, a.name AS approved_by_name
        FROM leaves l
        INNER JOIN users u ON u.id = l.user_id
        LEFT JOIN users a ON a.id = l.approved_by
        WHERE l.id = ?';
    if (!$adminView) {
        $sql .= ' AND l.user_id = ?';
    }

    $stmt = $db->prepare($sql);
    if ($adminView) {
        $stmt->bind_param('i', $leaveId);
    } else {
        $stmt->bind_param('ii', $leaveId, $viewerId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['user_id'] = (int)$row['user_id'];
    $row['total_days'] = (int)$row['total_days'];
    $row['leave_code'] = lmLeaveCode($row['id'], $row['created_at'] ?? '');
    return $row;
}

lmEnsureSchema();

$db = getDB();
$currentPath = '/modules/leave-management/index.php';
if (empty($_SESSION['user_id'])) {
    lmFail('Session expired. Please log in again.', 401);
}
if (function_exists('ensureRbacSchema')) {
    ensureRbacSchema();
}
if (function_exists('canAccessPath') && !canAccessPath($currentPath)) {
    lmFail('Access denied for Leave Management.', 403);
}

$action = trim((string)($_REQUEST['action'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    lmFail('User session not found.', 401);
}

if ($action === 'create_leave') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lmFail('Invalid request method.', 405);
    }
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        lmFail('Security token mismatch.', 403);
    }

    $department = lmNormalizedDepartment($_POST['department'] ?? '');
    if ($department === '') {
        lmFail('Department is required.');
    }
    $leaveType = trim((string)($_POST['leave_type'] ?? ''));
    if (!in_array($leaveType, ['Sick', 'Casual', 'Emergency', 'Other'], true)) {
        lmFail('Invalid leave type.');
    }

    $fromDate = trim((string)($_POST['from_date'] ?? ''));
    $toDate = trim((string)($_POST['to_date'] ?? ''));
    if ($fromDate === '' || $toDate === '') {
        lmFail('Leave dates are required.');
    }

    $days = lmDaysBetween($fromDate, $toDate);
    if ($days <= 0) {
        lmFail('Invalid leave date range.');
    }

    $reasonText = trim((string)($_POST['reason_text'] ?? ''));
    $voiceFile = isset($_FILES['voice_file']) ? lmStoreVoiceFile($_FILES['voice_file']) : '';

    $stmt = $db->prepare('INSERT INTO leaves (user_id, department, leave_type, from_date, to_date, total_days, reason_text, voice_file, status) VALUES (?,?,?,?,?,?,?,?,\'pending\')');
    $stmt->bind_param('issssiss', $userId, $department, $leaveType, $fromDate, $toDate, $days, $reasonText, $voiceFile);
    if (!$stmt->execute()) {
        $stmt->close();
        lmFail('Unable to save leave request. ' . $db->error, 500);
    }
    $leaveId = (int)$stmt->insert_id;
    $stmt->close();

    $detail = lmFetchLeave($db, $leaveId, $userId, true);
    lmRespond(true, 'Leave request submitted successfully.', $detail);
}

if ($action === 'list_my') {
    $stmt = $db->prepare('SELECT l.* FROM leaves l WHERE l.user_id = ? ORDER BY l.created_at DESC, l.id DESC');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $data = [];
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['total_days'] = (int)$row['total_days'];
        $row['leave_code'] = lmLeaveCode($row['id'], $row['created_at'] ?? '');
        $data[] = $row;
    }
    lmRespond(true, 'Leave requests loaded.', $data);
}

if ($action === 'list_admin') {
    if (!lmCanAdmin()) {
        lmFail('Admin access required.', 403);
    }
    $sql = "SELECT l.*, u.name AS employee_name, u.email AS employee_email
        FROM leaves l
        INNER JOIN users u ON u.id = l.user_id
        ORDER BY FIELD(l.status, 'pending', 'approved', 'rejected'), l.created_at DESC, l.id DESC";
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    $data = [];
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['total_days'] = (int)$row['total_days'];
        $row['leave_code'] = lmLeaveCode($row['id'], $row['created_at'] ?? '');
        $data[] = $row;
    }
    lmRespond(true, 'Approval queue loaded.', $data);
}

if ($action === 'get_detail') {
    $leaveId = (int)($_GET['id'] ?? 0);
    if ($leaveId <= 0) {
        lmFail('Invalid leave ID.');
    }
    $adminView = lmCanAdmin();
    $detail = lmFetchLeave($db, $leaveId, $userId, $adminView);
    if (!$detail) {
        lmFail('Leave request not found.', 404);
    }
    lmRespond(true, 'Leave detail loaded.', $detail);
}

if ($action === 'admin_update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lmFail('Invalid request method.', 405);
    }
    if (!lmCanAdmin()) {
        lmFail('Admin access required.', 403);
    }
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        lmFail('Security token mismatch.', 403);
    }

    $leaveId = (int)($_POST['id'] ?? 0);
    if ($leaveId <= 0) {
        lmFail('Invalid leave ID.');
    }
    $detail = lmFetchLeave($db, $leaveId, $userId, true);
    if (!$detail) {
        lmFail('Leave request not found.', 404);
    }

    $decision = trim((string)($_POST['decision'] ?? 'save'));
    if (!in_array($decision, ['save', 'approved', 'rejected'], true)) {
        lmFail('Invalid admin action.');
    }

    $reasonText = trim((string)($_POST['reason_text'] ?? ''));
    $adminRemark = trim((string)($_POST['admin_remark'] ?? ''));
    $status = $detail['status'];
    $approvedBy = $detail['approved_by'] !== null ? (int)$detail['approved_by'] : null;
    $approvedDate = $detail['approved_date'] ?? null;

    if ($decision === 'approved' || $decision === 'rejected') {
        $status = $decision;
        $approvedBy = $userId;
        $approvedDate = date('Y-m-d H:i:s');
    }

    if ($approvedBy === null || $approvedDate === null) {
        $stmt = $db->prepare('UPDATE leaves SET reason_text = ?, admin_remark = ?, status = ?, approved_by = NULL, approved_date = NULL WHERE id = ? LIMIT 1');
        $stmt->bind_param('sssi', $reasonText, $adminRemark, $status, $leaveId);
    } else {
        $stmt = $db->prepare('UPDATE leaves SET reason_text = ?, admin_remark = ?, status = ?, approved_by = ?, approved_date = ? WHERE id = ? LIMIT 1');
        $stmt->bind_param('sssisi', $reasonText, $adminRemark, $status, $approvedBy, $approvedDate, $leaveId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        lmFail('Unable to update leave request. ' . $db->error, 500);
    }
    $stmt->close();

    $message = $decision === 'save'
        ? 'Leave request updated.'
        : ('Leave request ' . ($decision === 'approved' ? 'approved.' : 'rejected.'));
    lmRespond(true, $message, lmFetchLeave($db, $leaveId, $userId, true));
}

if ($action === 'delete_leave') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lmFail('Invalid request method.', 405);
    }
    if (!lmCanAdmin()) {
        lmFail('Admin access required.', 403);
    }
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        lmFail('Security token mismatch.', 403);
    }

    $leaveId = (int)($_POST['id'] ?? 0);
    if ($leaveId <= 0) {
        lmFail('Invalid leave ID.');
    }

    $detail = lmFetchLeave($db, $leaveId, $userId, true);
    if (!$detail) {
        lmFail('Leave request not found.', 404);
    }

    $voiceFile = (string)($detail['voice_file'] ?? '');
    if ($voiceFile !== '') {
        $absolutePath = dirname(__DIR__, 2) . '/' . ltrim($voiceFile, '/');
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    $stmt = $db->prepare('DELETE FROM leaves WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $leaveId);
    if (!$stmt->execute()) {
        $stmt->close();
        lmFail('Unable to delete leave request. ' . $db->error, 500);
    }
    $stmt->close();

    lmRespond(true, 'Leave request deleted.');
}

lmFail('Unknown action.', 404);