<?php
// ============================================================
// ERP System — Users: Delete (Admin only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error','Access denied.'); redirect(BASE_URL.'/modules/dashboard/index.php');
}

$db   = getDB();
$id   = (int)($_GET['id']   ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error','Invalid or expired request.');
    redirect(BASE_URL.'/modules/users/index.php');
}

// Cannot delete yourself
if ($id === (int)$_SESSION['user_id']) {
    setFlash('error','You cannot delete your own account.');
    redirect(BASE_URL.'/modules/users/index.php');
}

$stmt = $db->prepare("SELECT name, role FROM users WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$row  = $stmt->get_result()->fetch_assoc();
if (!$row) { setFlash('error','User not found.'); redirect(BASE_URL.'/modules/users/index.php'); }

// Prevent deleting last admin
if ($row['role'] === 'admin') {
    $admQ = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1");
    $admQ->execute();
    if ((int)$admQ->get_result()->fetch_assoc()['c'] <= 1) {
        setFlash('error','Cannot delete the last active admin account.');
        redirect(BASE_URL.'/modules/users/index.php');
    }
}

$del = $db->prepare("DELETE FROM users WHERE id = ?");
$del->bind_param('i',$id);
if ($del->execute()) {
    setFlash('success','User '.$row['name'].' deleted.');
} else {
    setFlash('error','Delete failed: '.$db->error);
}
redirect(BASE_URL.'/modules/users/index.php');
