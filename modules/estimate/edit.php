<?php
// ============================================================
// ERP System — Estimates: Edit
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid estimate.'); redirect(BASE_URL.'/modules/estimate/index.php'); }

$row = $db->prepare("SELECT * FROM estimates WHERE id = ?");
$row->bind_param('i', $id); $row->execute();
$est = $row->get_result()->fetch_assoc();
if (!$est) { setFlash('error','Estimate not found.'); redirect(BASE_URL.'/modules/estimate/index.php'); }

// Non-admin cannot edit Converted estimates
if ($est['status'] === 'Converted') {
    setFlash('error','Converted estimates cannot be edited.'); redirect(BASE_URL.'/modules/estimate/view.php?id='.$id);
}

$materialTypes = ['BOPP','PET','PVC','Paper','Thermal','Polyester','Other'];
$statuses      = ['Draft','Sent','Approved','Rejected'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $clientName     = trim($_POST['client_name']     ?? '');
        $labelLength    = trim($_POST['label_length_mm'] ?? '');
        $labelWidth     = trim($_POST['label_width_mm']  ?? '');
        $quantity       = trim($_POST['quantity']        ?? '');
        $materialType   = trim($_POST['material_type']   ?? '');
        $printingColors = (int)($_POST['printing_colors'] ?? 0);
        $materialRate   = trim($_POST['material_rate']   ?? '');
        $printingRate   = trim($_POST['printing_rate']   ?? '0');
        $wasteFactor    = trim($_POST['waste_factor']    ?? '1.15');
        $marginPct      = trim($_POST['margin_pct']      ?? '20');
        $notes          = trim($_POST['notes']           ?? '');
        $status         = $_POST['status'] ?? 'Draft';

        if ($clientName === '')             $errors[] = 'Client name is required.';
        if (!is_numeric($labelLength) || (float)$labelLength <= 0) $errors[] = 'Label length must be positive.';
        if (!is_numeric($labelWidth)  || (float)$labelWidth  <= 0) $errors[] = 'Label width must be positive.';
        if (!is_numeric($quantity)    || (int)$quantity       <= 0) $errors[] = 'Quantity must be a positive integer.';
        if ($materialType === '')           $errors[] = 'Material type is required.';
        if (!is_numeric($materialRate)|| (float)$materialRate <= 0) $errors[] = 'Material rate must be positive.';
        if (!in_array($status, $statuses))  $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $ll   = (float)$labelLength / 1000;
            $lw   = (float)$labelWidth  / 1000;
            $qty  = (int)$quantity;
            $sqmR = round($ll * $lw * $qty * (float)$wasteFactor, 4);
            $mCost= round($sqmR * (float)$materialRate, 2);
            $pCost= round($sqmR * (float)$printingRate, 2);
            $tCost= round($mCost + $pCost, 2);
            $sell = round($tCost / (1 - (float)$marginPct/100), 2);

            $upd = $db->prepare(
                "UPDATE estimates SET
                 client_name=?, label_length_mm=?, label_width_mm=?, quantity=?,
                 material_type=?, printing_colors=?, material_rate=?, printing_rate=?,
                 waste_factor=?, margin_pct=?, sqm_required=?, material_cost=?,
                 printing_cost=?, total_cost=?, selling_price=?, notes=?, status=?
                 WHERE id=?"
            );
            $upd->bind_param(
                'sddisidddddddddssi',
                $clientName, $labelLength, $labelWidth, $qty,
                $materialType, $printingColors,
                $materialRate, $printingRate,
                $wasteFactor, $marginPct,
                $sqmR, $mCost, $pCost, $tCost, $sell,
                $notes, $status, $id
            );
            if ($upd->execute()) {
                setFlash('success','Estimate updated.');
                redirect(BASE_URL.'/modules/estimate/view.php?id='.$id);
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
    // Re-populate from POST on validation failure
    $est = array_merge($est, $_POST);
}

$pageTitle = 'Edit Estimate — '.$est['estimate_no'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Estimates</a><span class="breadcrumb-sep">›</span>
  <a href="view.php?id=<?= $id ?>"><?= e($est['estimate_no']) ?></a><span class="breadcrumb-sep">›</span>
  <span>Edit</span>
</div>
<div class="page-header">
  <div><h1>Edit Estimate</h1><p><?= e($est['estimate_no']) ?></p></div>
  <a href="view.php?id=<?= $id ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Errors:</strong>
  <ul style="margin:6px 0 0 18px"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

  <div class="card">
    <div class="card-header"><span class="card-title">Client &amp; Label</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Client Name <span class="req">*</span></label>
          <input type="text" name="client_name" class="form-control" required value="<?= e($est['client_name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Material Type <span class="req">*</span></label>
          <select name="material_type" class="form-control" required>
            <option value="">— select —</option>
            <?php foreach ($materialTypes as $mt): ?>
            <option value="<?= $mt ?>" <?= $est['material_type']===$mt?'selected':'' ?>><?= $mt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Label Length (mm) <span class="req">*</span></label>
          <input type="number" name="label_length_mm" id="label_length_mm" class="form-control" min="1" step="0.01" required value="<?= e($est['label_length_mm']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Label Width (mm) <span class="req">*</span></label>
          <input type="number" name="label_width_mm" id="label_width_mm" class="form-control" min="1" step="0.01" required value="<?= e($est['label_width_mm']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Quantity (pcs) <span class="req">*</span></label>
          <input type="number" name="quantity" id="quantity" class="form-control" min="1" required value="<?= e($est['quantity']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Printing Colors</label>
          <input type="number" name="printing_colors" class="form-control" min="0" max="8" value="<?= e($est['printing_colors']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-16">
    <div class="card-header"><span class="card-title">Costing Parameters</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Material Rate (₹/m²) <span class="req">*</span></label>
          <input type="number" name="material_rate" id="material_rate" class="form-control" min="0.01" step="0.01" required value="<?= e($est['material_rate']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Printing Rate (₹/m²)</label>
          <input type="number" name="printing_rate" id="printing_rate" class="form-control" min="0" step="0.01" value="<?= e($est['printing_rate']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Waste Factor</label>
          <input type="number" name="waste_factor" id="waste_factor" class="form-control" min="1" step="0.01" value="<?= e($est['waste_factor']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Profit Margin (%) <span class="req">*</span></label>
          <input type="number" name="margin_pct" id="margin_pct" class="form-control" min="1" max="99" step="0.1" required value="<?= e($est['margin_pct']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $est['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-16">
    <div class="card-header"><span class="card-title"><i class="bi bi-bar-chart-line"></i> Cost Preview</span></div>
    <div class="card-body">
      <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
        <div class="stat-mini"><label>SQM Required</label><span id="disp_sqm">—</span></div>
        <div class="stat-mini"><label>Material Cost</label><span id="disp_mat">—</span></div>
        <div class="stat-mini"><label>Printing Cost</label><span id="disp_prt">—</span></div>
        <div class="stat-mini"><label>Total Cost</label><span id="disp_tot">—</span></div>
        <div class="stat-mini accent"><label>Selling Price</label><span id="disp_sell">—</span></div>
        <div class="stat-mini"><label>Cost / Label</label><span id="disp_per">—</span></div>
      </div>
    </div>
  </div>

  <div class="card mt-16">
    <div class="card-header"><span class="card-title">Notes</span></div>
    <div class="card-body">
      <textarea name="notes" class="form-control" rows="3"><?= e($est['notes']) ?></textarea>
    </div>
  </div>

  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Update Estimate</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<style>
.stat-mini{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px 16px}
.stat-mini label{display:block;font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.stat-mini span{font-size:1.15rem;font-weight:700;color:var(--text)}
.stat-mini.accent{background:linear-gradient(135deg,#ecfdf5,#d1fae5)}
.stat-mini.accent span{color:#059669}
.req{color:var(--danger)}
</style>
<script>
(function(){
  const ids = ['label_length_mm','label_width_mm','quantity','material_rate','printing_rate','waste_factor','margin_pct'];
  function calc(){
    const ll  = parseFloat(document.getElementById('label_length_mm').value)||0;
    const lw  = parseFloat(document.getElementById('label_width_mm').value)||0;
    const qty = parseInt(document.getElementById('quantity').value)||0;
    const mr  = parseFloat(document.getElementById('material_rate').value)||0;
    const pr  = parseFloat(document.getElementById('printing_rate').value)||0;
    const wf  = parseFloat(document.getElementById('waste_factor').value)||1.15;
    const mp  = parseFloat(document.getElementById('margin_pct').value)||20;
    const sqm  = (ll/1000)*(lw/1000)*qty*wf;
    const mCost= sqm*mr; const pCost=sqm*pr; const tCost=mCost+pCost;
    const sell = mp<100?tCost/(1-mp/100):0;
    const fmt  = n=>'₹'+n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('disp_sqm').textContent  = sqm.toFixed(4)+' m²';
    document.getElementById('disp_mat').textContent  = fmt(mCost);
    document.getElementById('disp_prt').textContent  = fmt(pCost);
    document.getElementById('disp_tot').textContent  = fmt(tCost);
    document.getElementById('disp_sell').textContent = fmt(sell);
    document.getElementById('disp_per').textContent  = '₹'+(qty>0?sell/qty:0).toFixed(4);
  }
  ids.forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('input',calc); });
  calc();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
