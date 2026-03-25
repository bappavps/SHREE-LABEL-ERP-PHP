<?php
// ============================================================
// ERP System — Estimates: Delete
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id   = (int)($_GET['id']   ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error', 'Invalid or expired request.');
    redirect(BASE_URL . '/modules/estimate/index.php');
}

$stmt = $db->prepare("SELECT estimate_no, status FROM estimates WHERE id = ?");
$stmt->bind_param('i', $id); $stmt->execute();
$est = $stmt->get_result()->fetch_assoc();

if (!$est) { setFlash('error','Estimate not found.'); redirect(BASE_URL.'/modules/estimate/index.php'); }
if ($est['status'] === 'Converted') { setFlash('error','Cannot delete a converted estimate.'); redirect(BASE_URL.'/modules/estimate/index.php'); }

$del = $db->prepare("DELETE FROM estimates WHERE id = ?");
$del->bind_param('i', $id);
if ($del->execute()) {
    setFlash('success', 'Estimate '.$est['estimate_no'].' deleted.');
} else {
    setFlash('error', 'Delete failed: '.$db->error);
}
redirect(BASE_URL . '/modules/estimate/index.php');
