<?php
// ============================================================
// ERP System — Estimates: Convert to Sales Order
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db   = getDB();
$id   = (int)($_GET['id']   ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error','Invalid or expired request.');
    redirect(BASE_URL.'/modules/estimate/index.php');
}

$stmt = $db->prepare("SELECT * FROM estimates WHERE id = ? AND status = 'Approved'");
$stmt->bind_param('i',$id); $stmt->execute();
$est = $stmt->get_result()->fetch_assoc();

if (!$est) {
    setFlash('error','Only Approved estimates can be converted.');
    redirect(BASE_URL.'/modules/estimate/index.php');
}

// Check not already converted
$chk = $db->prepare("SELECT id FROM sales_orders WHERE estimate_id = ? LIMIT 1");
$chk->bind_param('i',$id); $chk->execute();
if ($chk->get_result()->fetch_assoc()) {
    setFlash('error','This estimate has already been converted.');
    redirect(BASE_URL.'/modules/estimate/view.php?id='.$id);
}

$orderNo = generateDocNo('SO','sales_orders','order_no');

$ins = $db->prepare(
    "INSERT INTO sales_orders
     (order_no, estimate_id, client_name, label_length_mm, label_width_mm, quantity,
      material_type, selling_price, status, created_by)
     VALUES (?,?,?,?,?,?,?,?,'Pending',?)"
);
$ins->bind_param(
    'sisddisdi',
    $orderNo, $id,
    $est['client_name'], $est['label_length_mm'], $est['label_width_mm'],
    $est['quantity'], $est['material_type'], $est['selling_price'],
    $_SESSION['user_id']
);

if ($ins->execute()) {
    $newOrderId = $db->insert_id;
    // Mark estimate as Converted
    $upd = $db->prepare("UPDATE estimates SET status='Converted' WHERE id=?");
    $upd->bind_param('i',$id); $upd->execute();

    setFlash('success',"Estimate converted to Sales Order {$orderNo}.");
    redirect(BASE_URL.'/modules/sales_order/view.php?id='.$newOrderId);
} else {
    setFlash('error','Conversion failed: '.$db->error);
    redirect(BASE_URL.'/modules/estimate/view.php?id='.$id);
}
