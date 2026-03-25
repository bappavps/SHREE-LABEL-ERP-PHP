<?php
// ============================================================
// ERP System — Sales Orders: Delete
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db   = getDB();
$id   = (int)($_GET['id']   ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error','Invalid or expired request.');
    redirect(BASE_URL.'/modules/sales_order/index.php');
}

$stmt = $db->prepare("SELECT order_no FROM sales_orders WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) { setFlash('error','Order not found.'); redirect(BASE_URL.'/modules/sales_order/index.php'); }

$del = $db->prepare("DELETE FROM sales_orders WHERE id = ?");
$del->bind_param('i',$id);
if ($del->execute()) {
    setFlash('success','Order '.$row['order_no'].' deleted.');
} else {
    setFlash('error','Delete failed: '.$db->error);
}
redirect(BASE_URL.'/modules/sales_order/index.php');
