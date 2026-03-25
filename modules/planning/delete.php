<?php
// ============================================================
// ERP System — Planning: Delete
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error','Invalid or expired request.');
    redirect(BASE_URL.'/modules/planning/index.php');
}

$stmt = $db->prepare("SELECT job_name FROM planning WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    setFlash('error','Planning item not found.');
    redirect(BASE_URL.'/modules/planning/index.php');
}

$del = $db->prepare("DELETE FROM planning WHERE id = ?");
$del->bind_param('i',$id);
if ($del->execute()) {
    setFlash('success','Planning item '.$row['job_name'].' deleted.');
} else {
    setFlash('error','Delete failed: '.$db->error);
}
redirect(BASE_URL.'/modules/planning/index.php');
