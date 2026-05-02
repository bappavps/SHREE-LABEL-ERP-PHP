<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../artwork-system/config.php';
require_once __DIR__ . '/../../artwork-system/includes/Db.php';
require_once __DIR__ . '/../../artwork-system/includes/functions.php';

// auth_check.php already validated current ERP session and RBAC page access.
$erpUserId = (int)($_SESSION['user_id'] ?? 0);
$erpEmail = trim((string)($_SESSION['user_email'] ?? ''));
$erpName = trim((string)($_SESSION['user_name'] ?? ''));
$erpRole = trim((string)($_SESSION['role'] ?? ''));

if ($erpUserId <= 0) {
    setFlash('error', 'Unable to start Artwork plugin session. Please sign in again.');
    redirect(BASE_URL . '/auth/login.php');
}

if ($erpName === '') {
    $erpName = 'ERP User ' . $erpUserId;
}
if ($erpEmail === '') {
    $erpEmail = 'erp-user-' . $erpUserId . '@local.invalid';
}

$pluginRole = ($erpRole === 'admin') ? 'admin' : 'designer';

try {
    $artDb = Db::getInstance();
    ensureDefaultAuthUsers($artDb);

    $find = $artDb->prepare('SELECT id FROM artwork_users WHERE email = ? LIMIT 1');
    $find->execute([$erpEmail]);
    $pluginUserId = (int)$find->fetchColumn();

    if ($pluginUserId > 0) {
        $upd = $artDb->prepare('UPDATE artwork_users SET name = ?, role = ? WHERE id = ?');
        $upd->execute([$erpName, $pluginRole, $pluginUserId]);
    } else {
        $randomPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $ins = $artDb->prepare('INSERT INTO artwork_users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $ins->execute([$erpName, $erpEmail, $randomPasswordHash, $pluginRole]);
        $pluginUserId = (int)$artDb->lastInsertId();
    }

    if ($pluginUserId <= 0) {
        throw new RuntimeException('Plugin account provisioning failed.');
    }

    setAuthSession([
        'id' => $pluginUserId,
        'name' => $erpName,
        'role' => $pluginRole,
    ]);

    redirect(BASE_URL . '/artwork-system/designer/index.php');
} catch (Throwable $e) {
    error_log('Artwork bridge error: ' . $e->getMessage());
    setFlash('error', 'Artwork plugin is temporarily unavailable. Please contact admin.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}
