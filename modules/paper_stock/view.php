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

$rollQrPayload = implode(' | ', [
  'Roll: ' . ($r['roll_no'] ?? ''),
  'Type: ' . ($r['paper_type'] ?? ''),
  'Company: ' . ($r['company'] ?? ''),
  'Width: ' . ($r['width_mm'] ?? ''),
  'Length: ' . ($r['length_mtr'] ?? ''),
  'Status: ' . ($r['status'] ?? ''),
]);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . rawurlencode($rollQrPayload);

// Paper type thumbnail fallback search by slug under assets/images/paper-types/
$paperSlug = strtolower(trim((string)($r['paper_type'] ?? '')));
$paperSlug = preg_replace('/[^a-z0-9]+/', '-', $paperSlug);
$paperSlug = trim($paperSlug, '-');
$thumbPath = null;
if ($paperSlug !== '') {
  foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
    $candidateFs = __DIR__ . '/../../assets/images/paper-types/' . $paperSlug . '.' . $ext;
    if (file_exists($candidateFs)) {
      $thumbPath = BASE_URL . '/assets/images/paper-types/' . $paperSlug . '.' . $ext;
      break;
    }
  }
}

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
    <div class="card-header"><span class="card-title">QR Code & Preview</span></div>
    <div class="card-body" style="display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start">
      <div>
        <img src="<?= e($qrUrl) ?>" alt="QR Code <?= e($r['roll_no']) ?>" style="width:220px;height:220px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:8px">
        <div class="text-muted" style="font-size:.72rem;margin-top:8px">QR is generated automatically from roll details.</div>
      </div>
      <div>
        <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Paper Type Thumbnail</div>
        <?php if ($thumbPath): ?>
          <img src="<?= e($thumbPath) ?>" alt="<?= e($r['paper_type']) ?>" style="max-width:100%;width:320px;aspect-ratio:16/10;object-fit:cover;border:1px solid #e2e8f0;border-radius:10px;background:#fff">
        <?php else: ?>
          <div style="width:320px;max-width:100%;aspect-ratio:16/10;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:.82rem;font-weight:600">
            No thumbnail available for <?= e($r['paper_type'] ?: 'this paper type') ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

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
    <?php if (!empty($r['job_no'])): ?>
    <div class="card mb-16">
      <div class="card-header"><span class="card-title">Workflow / Job Info</span></div>
      <div class="card-body">
        <table style="width:100%">
          <tbody>
            <tr><td class="text-muted" style="width:55%;padding:7px 0">Job No</td>    <td class="fw-600"><?= e($r['job_no'] ?? '-') ?></td></tr>
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
