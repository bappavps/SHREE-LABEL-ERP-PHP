<?php
// ============================================================
// ERP System — Estimates: Add
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$errors  = [];
$success = null;

// Material types
$materialTypes = ['BOPP','PET','PVC','Paper','Thermal','Polyester','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Read & sanitise inputs
        $clientName      = trim($_POST['client_name']   ?? '');
        $labelLength     = trim($_POST['label_length_mm'] ?? '');
        $labelWidth      = trim($_POST['label_width_mm']  ?? '');
        $quantity        = trim($_POST['quantity']        ?? '');
        $materialType    = trim($_POST['material_type']   ?? '');
        $printingColors  = (int)($_POST['printing_colors'] ?? 0);
        $materialRate    = trim($_POST['material_rate']   ?? '');
        $printingRate    = trim($_POST['printing_rate']   ?? '');
        $wasteFactor     = trim($_POST['waste_factor']    ?? '1.15');
        $marginPct       = trim($_POST['margin_pct']      ?? '20');
        $sqmRequired     = trim($_POST['sqm_required']    ?? '0');
        $materialCost    = trim($_POST['material_cost']   ?? '0');
        $printingCost    = trim($_POST['printing_cost']   ?? '0');
        $totalCost       = trim($_POST['total_cost']      ?? '0');
        $sellingPrice    = trim($_POST['selling_price']   ?? '0');
        $notes           = trim($_POST['notes']           ?? '');
        $status          = 'Draft';

        // Validate
        if ($clientName === '')             $errors[] = 'Client name is required.';
        if (!is_numeric($labelLength) || (float)$labelLength <= 0) $errors[] = 'Label length must be a positive number.';
        if (!is_numeric($labelWidth)  || (float)$labelWidth  <= 0) $errors[] = 'Label width must be a positive number.';
        if (!is_numeric($quantity)    || (int)$quantity       <= 0) $errors[] = 'Quantity must be a positive integer.';
        if ($materialType === '')           $errors[] = 'Material type is required.';
        if (!is_numeric($materialRate)|| (float)$materialRate <= 0) $errors[] = 'Material rate must be a positive number.';
        if (!is_numeric($printingRate))     $errors[] = 'Printing rate is required.';
        if (!is_numeric($wasteFactor) || (float)$wasteFactor < 1)   $errors[] = 'Waste factor must be >= 1.';
        if (!is_numeric($marginPct)   || (float)$marginPct   <= 0)  $errors[] = 'Margin % must be a positive number.';

        if (empty($errors)) {
            // Server-side cost calculation (source of truth)
            $ll    = (float)$labelLength / 1000;
            $lw    = (float)$labelWidth  / 1000;
            $qty   = (int)$quantity;
            $sqmR  = round($ll * $lw * $qty * (float)$wasteFactor, 4);
            $mCost = round($sqmR * (float)$materialRate, 2);
            $pCost = round($sqmR * (float)$printingRate, 2);
            $tCost = round($mCost + $pCost, 2);
            $sPrice= round($tCost / (1 - (float)$marginPct/100), 2);

            $estimateNo = generateDocNo('EST', 'estimates', 'estimate_no');

            $stmt = $db->prepare(
                "INSERT INTO estimates
                 (estimate_no, client_name, label_length_mm, label_width_mm, quantity,
                  material_type, printing_colors, material_rate, printing_rate,
                  waste_factor, margin_pct, sqm_required, material_cost, printing_cost,
                  total_cost, selling_price, notes, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'ssddisidddddddddssi',
                $estimateNo, $clientName,
                $labelLength, $labelWidth, $qty,
                $materialType, $printingColors,
                $materialRate, $printingRate,
                $wasteFactor, $marginPct,
                $sqmR, $mCost, $pCost, $tCost, $sPrice,
                $notes, $status,
                $_SESSION['user_id']
            );

            if ($stmt->execute()) {
                setFlash('success', "Estimate {$estimateNo} created successfully.");
                redirect(BASE_URL . '/modules/estimate/index.php');
            } else {
                $errors[] = 'Database error: ' . $db->error;
            }
        }
    }
}

$pageTitle = 'New Estimate';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/estimate/index.php">Estimates</a><span class="breadcrumb-sep">›</span>
  <span>New Estimate</span>
</div>
<div class="page-header">
  <div><h1>New Estimate</h1><p>Create a job costing estimate / quotation.</p></div>
  <a href="index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Please fix the following errors:</strong>
  <ul style="margin:6px 0 0 18px">
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" id="estimateForm">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
  <!-- Hidden computed fields (populated by JS) -->
  <input type="hidden" name="sqm_required"  id="sqm_required"  value="<?= e($_POST['sqm_required']  ?? '0') ?>">
  <input type="hidden" name="material_cost" id="material_cost" value="<?= e($_POST['material_cost'] ?? '0') ?>">
  <input type="hidden" name="printing_cost" id="printing_cost" value="<?= e($_POST['printing_cost'] ?? '0') ?>">
  <input type="hidden" name="total_cost"    id="total_cost"    value="<?= e($_POST['total_cost']    ?? '0') ?>">
  <input type="hidden" name="selling_price" id="selling_price" value="<?= e($_POST['selling_price'] ?? '0') ?>">

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="bi bi-person"></i> Client &amp; Label</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Client Name <span class="req">*</span></label>
          <input type="text" name="client_name" class="form-control" maxlength="120" required value="<?= e($_POST['client_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Material Type <span class="req">*</span></label>
          <select name="material_type" class="form-control" required>
            <option value="">— select —</option>
            <?php foreach ($materialTypes as $mt): ?>
            <option value="<?= $mt ?>" <?= ($_POST['material_type'] ?? '')===$mt?'selected':'' ?>><?= $mt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Label Length (mm) <span class="req">*</span></label>
          <input type="number" name="label_length_mm" id="label_length_mm" class="form-control" min="1" step="0.01" required value="<?= e($_POST['label_length_mm'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Label Width (mm) <span class="req">*</span></label>
          <input type="number" name="label_width_mm" id="label_width_mm" class="form-control" min="1" step="0.01" required value="<?= e($_POST['label_width_mm'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Quantity (pcs) <span class="req">*</span></label>
          <input type="number" name="quantity" id="quantity" class="form-control" min="1" required value="<?= e($_POST['quantity'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Printing Colors</label>
          <input type="number" name="printing_colors" class="form-control" min="0" max="8" value="<?= e($_POST['printing_colors'] ?? '4') ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-16">
    <div class="card-header"><span class="card-title"><i class="bi bi-calculator"></i> Costing Parameters</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Material Rate (₹/m²) <span class="req">*</span></label>
          <input type="number" name="material_rate" id="material_rate" class="form-control" min="0.01" step="0.01" required value="<?= e($_POST['material_rate'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Printing Rate (₹/m²)</label>
          <input type="number" name="printing_rate" id="printing_rate" class="form-control" min="0" step="0.01" value="<?= e($_POST['printing_rate'] ?? '0') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Waste Factor</label>
          <input type="number" name="waste_factor" id="waste_factor" class="form-control" min="1" step="0.01" value="<?= e($_POST['waste_factor'] ?? '1.15') ?>">
          <small class="form-hint">1.00 = no waste · 1.15 = 15% waste allowance</small>
        </div>
        <div class="form-group">
          <label class="form-label">Profit Margin (%) <span class="req">*</span></label>
          <input type="number" name="margin_pct" id="margin_pct" class="form-control" min="1" max="99" step="0.1" required value="<?= e($_POST['margin_pct'] ?? '20') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Live Cost Preview -->
  <div class="card mt-16" id="costPreviewCard">
    <div class="card-header"><span class="card-title"><i class="bi bi-bar-chart-line"></i> Cost Preview <span class="text-muted text-sm fw-400">(updates automatically)</span></span></div>
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
    <div class="card-header"><span class="card-title"><i class="bi bi-chat-left-text"></i> Notes</span></div>
    <div class="card-body">
      <textarea name="notes" class="form-control" rows="3" placeholder="Optional remarks…"><?= e($_POST['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Estimate</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<style>
.stat-mini{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px 16px}
.stat-mini label{display:block;font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.stat-mini span{font-size:1.15rem;font-weight:700;color:var(--text)}
.stat-mini.accent{background:linear-gradient(135deg,#ecfdf5,#d1fae5)}
.stat-mini.accent span{color:#059669}
.form-hint{font-size:0.75rem;color:var(--text-muted);margin-top:3px;display:block}
.req{color:var(--danger)}
</style>
<script>
(function(){
  const ids = ['label_length_mm','label_width_mm','quantity','material_rate','printing_rate','waste_factor','margin_pct'];
  function calc(){
    const ll   = parseFloat(document.getElementById('label_length_mm').value)||0;
    const lw   = parseFloat(document.getElementById('label_width_mm').value)||0;
    const qty  = parseInt(document.getElementById('quantity').value)||0;
    const mr   = parseFloat(document.getElementById('material_rate').value)||0;
    const pr   = parseFloat(document.getElementById('printing_rate').value)||0;
    const wf   = parseFloat(document.getElementById('waste_factor').value)||1.15;
    const mp   = parseFloat(document.getElementById('margin_pct').value)||20;

    const sqm   = (ll/1000)*(lw/1000)*qty*wf;
    const mCost = sqm*mr;
    const pCost = sqm*pr;
    const tCost = mCost+pCost;
    const sell  = mp<100 ? tCost/(1-mp/100) : 0;
    const perLbl= qty>0 ? (sell/qty) : 0;

    const fmt = n => '₹'+n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');

    document.getElementById('disp_sqm').textContent  = sqm.toFixed(4)+' m²';
    document.getElementById('disp_mat').textContent  = fmt(mCost);
    document.getElementById('disp_prt').textContent  = fmt(pCost);
    document.getElementById('disp_tot').textContent  = fmt(tCost);
    document.getElementById('disp_sell').textContent = fmt(sell);
    document.getElementById('disp_per').textContent  = '₹'+perLbl.toFixed(4);

    document.getElementById('sqm_required').value  = sqm.toFixed(4);
    document.getElementById('material_cost').value = mCost.toFixed(2);
    document.getElementById('printing_cost').value = pCost.toFixed(2);
    document.getElementById('total_cost').value    = tCost.toFixed(2);
    document.getElementById('selling_price').value = sell.toFixed(2);
  }
  ids.forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('input',calc); });
  calc();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
