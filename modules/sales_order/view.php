<?php
// ============================================================
// Shree Label ERP — Sales Orders: View
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid order.'); redirect(BASE_URL.'/modules/sales_order/index.php'); }

$stmt = $db->prepare(
    "SELECT so.*, u.name AS created_by_name, e.estimate_no
     FROM sales_orders so
     LEFT JOIN users u ON u.id = so.created_by
     LEFT JOIN estimates e ON e.id = so.estimate_id
     WHERE so.id = ?"
);
$stmt->bind_param('i',$id); $stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { setFlash('error','Order not found.'); redirect(BASE_URL.'/modules/sales_order/index.php'); }

$pageTitle = 'Order — '.$order['order_no'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Sales Orders</a><span class="breadcrumb-sep">›</span>
  <span><?= e($order['order_no']) ?></span>
</div>

<div class="page-header">
  <div>
    <h1><?= e($order['order_no']) ?> <?= statusBadge($order['status']) ?></h1>
    <p class="text-muted"><?= e($order['client_name']) ?> · <?= formatDate($order['created_at']) ?></p>
  </div>
  <div class="page-header-actions">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-ghost"><i class="bi bi-pencil"></i> Update Status</a>
    <?php if ($order['estimate_no']): ?>
    <a href="<?= BASE_URL ?>/modules/estimate/view.php?id=<?= $order['estimate_id'] ?>" class="btn btn-ghost">
      <i class="bi bi-calculator"></i> View Estimate
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="bi bi-person"></i> Order Details</span></div>
    <div class="card-body detail-body">
      <div class="detail-row"><span class="detail-label">Order No</span><span class="fw-600"><?= e($order['order_no']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Client</span><span><?= e($order['client_name']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Material</span><span><?= e($order['material_type'] ?? '—') ?></span></div>
      <div class="detail-row"><span class="detail-label">Label Size</span><span><?= e($order['label_length_mm']) ?> × <?= e($order['label_width_mm']) ?> mm</span></div>
      <div class="detail-row"><span class="detail-label">Quantity</span><span><?= number_format((int)$order['quantity']) ?> pcs</span></div>
      <div class="detail-row highlight"><span class="detail-label">Selling Price</span>
        <span class="price-big">₹<?= number_format((float)$order['selling_price'],2) ?></span></div>
      <div class="detail-row"><span class="detail-label">Status</span><span><?= statusBadge($order['status']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Estimate Ref</span>
        <span><?= $order['estimate_no'] ? '<a href="'.BASE_URL.'/modules/estimate/view.php?id='.e($order['estimate_id']).'" class="text-blue">'.e($order['estimate_no']).'</a>' : '—' ?></span></div>
      <div class="detail-row"><span class="detail-label">Created By</span><span><?= e($order['created_by_name'] ?? '—') ?></span></div>
      <div class="detail-row"><span class="detail-label">Created</span><span><?= formatDate($order['created_at']) ?></span></div>
    </div>
  </div>
  <?php if (!empty($order['notes'])): ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="bi bi-chat-left-text"></i> Notes</span></div>
    <div class="card-body"><p style="margin:0;white-space:pre-wrap"><?= e($order['notes']) ?></p></div>
  </div>
  <?php endif; ?>
</div>

<div class="form-actions mt-16">
  <a href="index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to List</a>
  <a href="delete.php?id=<?= $id ?>&csrf=<?= generateCSRF() ?>"
     class="btn btn-danger"
     data-confirm="Permanently delete order <?= e($order['order_no']) ?>?">
    <i class="bi bi-trash"></i> Delete
  </a>
</div>

<style>
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:700px){.detail-grid{grid-template-columns:1fr}}
.detail-body{padding:0}
.detail-row{display:flex;justify-content:space-between;align-items:center;padding:9px 16px;border-bottom:1px solid var(--border)}
.detail-row:last-child{border-bottom:none}
.detail-row.highlight{background:#f0fdf4}
.detail-label{font-size:0.82rem;color:var(--text-muted);min-width:130px}
.fw-600{font-weight:600}
.price-big{font-size:1.25rem;font-weight:700;color:#059669}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
