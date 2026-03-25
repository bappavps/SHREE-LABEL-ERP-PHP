<?php
// ============================================================
// ERP System — Estimates: View
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid estimate.'); redirect(BASE_URL.'/modules/estimate/index.php'); }

$stmt = $db->prepare(
    "SELECT e.*, u.name AS created_by_name FROM estimates e
     LEFT JOIN users u ON u.id = e.created_by
     WHERE e.id = ?"
);
$stmt->bind_param('i', $id); $stmt->execute();
$est = $stmt->get_result()->fetch_assoc();
if (!$est) { setFlash('error','Estimate not found.'); redirect(BASE_URL.'/modules/estimate/index.php'); }

// Linked sales order (if converted)
$soRow = null;
if ($est['status'] === 'Converted') {
    $soQ = $db->prepare("SELECT id, order_no FROM sales_orders WHERE estimate_id = ? LIMIT 1");
    $soQ->bind_param('i',$id); $soQ->execute();
    $soRow = $soQ->get_result()->fetch_assoc();
}

$pageTitle = 'Estimate — '.$est['estimate_no'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Estimates</a><span class="breadcrumb-sep">›</span>
  <span><?= e($est['estimate_no']) ?></span>
</div>

<div class="page-header">
  <div>
    <h1><?= e($est['estimate_no']) ?> <?= statusBadge($est['status']) ?></h1>
    <p class="text-muted"><?= e($est['client_name']) ?> · <?= formatDate($est['created_at']) ?></p>
  </div>
  <div class="page-header-actions">
    <?php if ($est['status'] !== 'Converted'): ?>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-ghost"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
    <?php if ($est['status'] === 'Approved'): ?>
    <a href="convert.php?id=<?= $id ?>&csrf=<?= generateCSRF() ?>"
       class="btn btn-primary"
       data-confirm="Convert this estimate to a Sales Order?">
      <i class="bi bi-arrow-right-circle"></i> Convert to Order
    </a>
    <?php endif; ?>
    <?php if ($soRow): ?>
    <a href="<?= BASE_URL ?>/modules/sales_order/view.php?id=<?= $soRow['id'] ?>" class="btn btn-blue">
      <i class="bi bi-receipt"></i> View Order <?= e($soRow['order_no']) ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid">

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="bi bi-person"></i> Client &amp; Label</span></div>
    <div class="card-body detail-body">
      <div class="detail-row"><span class="detail-label">Client Name</span><span><?= e($est['client_name']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Material Type</span><span><?= e($est['material_type']) ?></span></div>
      <div class="detail-row"><span class="detail-label">Label Size</span>
        <span><?= e($est['label_length_mm']) ?> mm × <?= e($est['label_width_mm']) ?> mm</span></div>
      <div class="detail-row"><span class="detail-label">Quantity</span><span><?= number_format((int)$est['quantity']) ?> pcs</span></div>
      <div class="detail-row"><span class="detail-label">Printing Colors</span><span><?= (int)$est['printing_colors'] ?></span></div>
      <div class="detail-row"><span class="detail-label">Created By</span><span><?= e($est['created_by_name'] ?? '—') ?></span></div>
      <div class="detail-row"><span class="detail-label">Status</span><span><?= statusBadge($est['status']) ?></span></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="bi bi-calculator"></i> Cost Breakdown</span></div>
    <div class="card-body detail-body">
      <div class="detail-row"><span class="detail-label">Material Rate</span><span>₹<?= number_format((float)$est['material_rate'],2) ?>/m²</span></div>
      <div class="detail-row"><span class="detail-label">Printing Rate</span><span>₹<?= number_format((float)$est['printing_rate'],2) ?>/m²</span></div>
      <div class="detail-row"><span class="detail-label">Waste Factor</span><span><?= number_format((float)$est['waste_factor'],2) ?>×</span></div>
      <div class="detail-row"><span class="detail-label">SQM Required</span><span class="fw-600"><?= number_format((float)$est['sqm_required'],4) ?> m²</span></div>
      <div class="detail-row"><span class="detail-label">Material Cost</span><span>₹<?= number_format((float)$est['material_cost'],2) ?></span></div>
      <div class="detail-row"><span class="detail-label">Printing Cost</span><span>₹<?= number_format((float)$est['printing_cost'],2) ?></span></div>
      <div class="detail-row"><span class="detail-label">Total Cost</span><span class="fw-600">₹<?= number_format((float)$est['total_cost'],2) ?></span></div>
      <div class="detail-row"><span class="detail-label">Profit Margin</span><span><?= number_format((float)$est['margin_pct'],1) ?>%</span></div>
      <div class="detail-row highlight"><span class="detail-label">Selling Price</span>
        <span class="price-big">₹<?= number_format((float)$est['selling_price'],2) ?></span></div>
      <div class="detail-row"><span class="detail-label">Per Label</span>
        <span>₹<?= $est['quantity'] > 0 ? number_format((float)$est['selling_price']/(int)$est['quantity'],4) : '—' ?></span></div>
    </div>
  </div>

</div>

<?php if (!empty($est['notes'])): ?>
<div class="card mt-16">
  <div class="card-header"><span class="card-title"><i class="bi bi-chat-left-text"></i> Notes</span></div>
  <div class="card-body"><p style="margin:0;white-space:pre-wrap"><?= e($est['notes']) ?></p></div>
</div>
<?php endif; ?>

<div class="form-actions mt-16">
  <a href="index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to List</a>
  <?php if ($est['status'] !== 'Converted'): ?>
  <a href="delete.php?id=<?= $id ?>&csrf=<?= generateCSRF() ?>"
     class="btn btn-danger"
     data-confirm="Permanently delete estimate <?= e($est['estimate_no']) ?>?">
    <i class="bi bi-trash"></i> Delete
  </a>
  <?php endif; ?>
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
