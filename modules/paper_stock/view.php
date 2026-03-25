<?php
// ============================================================
// Shree Label ERP — Paper Stock: View Roll
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Invalid ID.'); redirect(BASE_URL . '/modules/paper_stock/index.php'); }

$stmt = $db->prepare("SELECT ps.*, u.name AS added_by_name FROM paper_stock ps LEFT JOIN users u ON u.id = ps.created_by WHERE ps.id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
if (!$r) { setFlash('error', 'Roll not found.'); redirect(BASE_URL . '/modules/paper_stock/index.php'); }

$sqm = calcSQM((float)$r['width_mm'], (float)$r['length_mtr']);

$pageTitle = 'Roll — ' . $r['roll_no'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/paper_stock/index.php">Paper Stock</a>
  <span class="breadcrumb-sep">›</span>
  <span><?= e($r['roll_no']) ?></span>
</div>

<div class="page-header">
  <div>
    <h1><?= e($r['roll_no']) ?></h1>
    <p><?= e($r['paper_type']) ?> &middot; <?= e($r['company']) ?> &middot; <?= statusBadge($r['status']) ?></p>
  </div>
  <div class="page-header-actions">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</a>
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <div class="card-header"><span class="card-title">Technical Details</span></div>
    <div class="card-body">
      <table style="width:100%">
        <tbody>
          <tr><td class="text-muted" style="width:45%;padding:7px 0">Roll No</td>     <td class="fw-700"><?= e($r['roll_no']) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Status</td>                <td><?= statusBadge($r['status']) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Paper Type</td>            <td><?= e($r['paper_type']) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Company</td>               <td><?= e($r['company']) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Width (MM)</td>            <td class="fw-600"><?= e($r['width_mm']) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Length (MTR)</td>          <td class="fw-600"><?= number_format((float)$r['length_mtr'],0) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">SQM (Calculated)</td>     <td class="fw-700 text-green"><?= number_format($sqm,2) ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">GSM</td>                  <td><?= e($r['gsm'] ?? '-') ?></td></tr>
          <tr><td class="text-muted" style="padding:7px 0">Weight (KG)</td>           <td><?= e($r['weight_kg'] ?? '-') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">Purchase Information</span></div>
      <div class="card-body">
        <table style="width:100%">
          <tbody>
            <tr><td class="text-muted" style="width:55%;padding:7px 0">Lot / Batch No</td> <td><?= e($r['lot_batch_no'] ?? '-') ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Company Roll No</td>           <td><?= e($r['company_roll_no'] ?? '-') ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Purchase Rate</td>             <td><?= $r['purchase_rate'] ? '₹' . number_format((float)$r['purchase_rate'],2) : '-' ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Date Received</td>             <td><?= formatDate($r['date_received']) ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Date Used</td>                 <td><?= formatDate($r['date_used']) ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Added By</td>                  <td><?= e($r['added_by_name'] ?? '-') ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Created At</td>                <td class="text-muted"><?= formatDate($r['created_at']) ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($r['job_no'] || $r['job_name'] || $r['job_size']): ?>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">Workflow / Job Info</span></div>
      <div class="card-body">
        <table style="width:100%">
          <tbody>
            <tr><td class="text-muted" style="width:55%;padding:7px 0">Job No</td>    <td class="fw-600"><?= e($r['job_no'] ?? '-') ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Job Name</td>             <td><?= e($r['job_name'] ?? '-') ?></td></tr>
            <tr><td class="text-muted" style="padding:7px 0">Job Size</td>             <td><?= e($r['job_size'] ?? '-') ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($r['remarks']): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">Remarks</span></div>
      <div class="card-body"><p class="text-sm"><?= nl2br(e($r['remarks'])) ?></p></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
