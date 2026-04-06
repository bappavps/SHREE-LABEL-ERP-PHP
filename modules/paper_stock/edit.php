<?php
// ============================================================
// ERP System — Paper Stock: Edit Roll
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Invalid roll ID.'); redirect(BASE_URL . '/modules/paper_stock/index.php'); }

$stmt = $db->prepare("SELECT * FROM paper_stock WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$roll = $stmt->get_result()->fetch_assoc();
if (!$roll) { setFlash('error', 'Roll not found.'); redirect(BASE_URL . '/modules/paper_stock/index.php'); }

$errors = [];
$old    = $roll; // pre-fill with existing data

// Fetch admin-managed paper companies and paper types.
// Combined list: active master entries + all distinct values in paper_stock + this roll's current value.
$companyOptions = getMasterPaperCompanies(true);
$typeOptions    = getMasterPaperTypes(true);
$legacyCompany  = trim((string)($roll['company'] ?? ''));
$legacyType     = trim((string)($roll['paper_type'] ?? ''));

// Merge existing paper_stock values
$res = $db->query("SELECT DISTINCT company FROM paper_stock WHERE company IS NOT NULL AND TRIM(company)<>'' ORDER BY company");
if ($res) { while ($r = $res->fetch_assoc()) { if (!in_array($r['company'], $companyOptions, true)) $companyOptions[] = $r['company']; } sort($companyOptions); }
$res = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE paper_type IS NOT NULL AND TRIM(paper_type)<>'' ORDER BY paper_type");
if ($res) { while ($r = $res->fetch_assoc()) { if (!in_array($r['paper_type'], $typeOptions, true)) $typeOptions[] = $r['paper_type']; } sort($typeOptions); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $old = $_POST;

        $paper_type = trim($old['paper_type']  ?? '');
        $company    = trim($old['company']     ?? '');
        $width_mm   = trim($old['width_mm']    ?? '');
        $length_mtr = trim($old['length_mtr']  ?? '');

        if ($paper_type === '') $errors[] = 'Paper Type is required.';
        if ($company === '')    $errors[] = 'Company is required.';
        if ($company !== '' && !in_array($company, $companyOptions, true)) $errors[] = 'Select a valid Paper Company from the suggestions list.';
        if ($paper_type !== '' && !in_array($paper_type, $typeOptions, true)) $errors[] = 'Select a valid Paper Type from the suggestions list.';
        if (!is_numeric($width_mm)   || $width_mm   <= 0) $errors[] = 'Width must be a positive number.';
        if (!is_numeric($length_mtr) || $length_mtr <= 0) $errors[] = 'Length must be a positive number.';

        if (empty($errors)) {
          $validStatuses = erp_paper_stock_status_options();
          $status = in_array($old['status'] ?? '', $validStatuses, true) ? (string)$old['status'] : 'Main';
            $gsm           = ($old['gsm']            ?? '') !== '' ? (float)$old['gsm']           : null;
            $weight_kg     = ($old['weight_kg']      ?? '') !== '' ? (float)$old['weight_kg']     : null;
            $purchase_rate = ($old['purchase_rate']  ?? '') !== '' ? (float)$old['purchase_rate'] : null;
            $lot_batch     = ($old['lot_batch_no']   ?? '') !== '' ? $old['lot_batch_no']         : null;
            $co_roll       = ($old['company_roll_no']?? '') !== '' ? $old['company_roll_no']      : null;
            $date_recv     = ($old['date_received']  ?? '') !== '' ? $old['date_received']        : null;
            $date_used     = ($old['date_used']      ?? '') !== '' ? $old['date_used']            : null;
            $job_no        = ($old['job_no']         ?? '') !== '' ? $old['job_no']               : null;
            $job_size      = ($old['job_size']       ?? '') !== '' ? $old['job_size']             : null;
            $job_name      = ($old['job_name']       ?? '') !== '' ? $old['job_name']             : null;
            $remarks       = ($old['remarks']        ?? '') !== '' ? $old['remarks']              : null;

            $upd = $db->prepare("UPDATE paper_stock SET
                paper_type=?, company=?, width_mm=?, length_mtr=?, gsm=?, weight_kg=?,
                purchase_rate=?, lot_batch_no=?, company_roll_no=?, status=?,
                job_no=?, job_size=?, job_name=?,
                date_received=?, date_used=?, remarks=?
                WHERE id=?");
            $upd->bind_param(
                'ssddddssssssssssi',
                $paper_type, $company, $width_mm, $length_mtr, $gsm, $weight_kg,
                $purchase_rate, $lot_batch, $co_roll, $status,
                $job_no, $job_size, $job_name,
                $date_recv, $date_used, $remarks, $id
            );

            if ($upd->execute()) {
                setFlash('success', 'Roll updated successfully.');
                redirect(BASE_URL . '/modules/paper_stock/index.php');
            } else {
                $errors[] = 'Database error: ' . $db->error;
            }
        }
    }
}

$csrf = generateCSRF();
$pageTitle = 'Edit Roll — ' . e($roll['roll_no']);

// Build product-type image map for JS
$appSettings = getAppSettings();
$productTypeImageMap = [];
foreach (($appSettings['image_library'] ?? []) as $img) {
    if (($img['category'] ?? '') === 'product-type' && !empty($img['paper_type']) && !empty($img['path'])) {
        $key = strtolower(trim($img['paper_type']));
        $productTypeImageMap[$key] = BASE_URL . '/' . ltrim($img['path'], '/');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/paper_stock/index.php">Paper Stock</a>
  <span class="breadcrumb-sep">›</span>
  <span>Edit Roll</span>
</div>

<div class="page-header">
  <div><h1>Edit Roll: <?= e($roll['roll_no']) ?></h1><p>Update the details for this roll.</p></div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <span><ul style="margin:0;padding-left:16px">
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></span>
</div>
<?php endif; ?>

<form method="POST" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

  <div class="card mb-16">
    <div class="card-header"><span class="card-title">Roll Details</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Roll No</label>
          <input type="text" class="form-control" value="<?= e($roll['roll_no']) ?>" readonly>
          <span class="form-hint">Roll No cannot be changed after creation.</span>
        </div>
        <div class="form-group">
          <label>Status</label>
          <?php $presetStatuses = erp_paper_stock_status_options(); $curStatus = erp_paper_stock_normalize_status((string)($old['status'] ?? 'Main')); ?>
          <input type="hidden" name="status" id="status-hidden" value="<?= e($curStatus) ?>">
          <input type="hidden" name="custom_status" id="custom-status-hidden" value="">
          <div id="status-dd-container"></div>
        </div>
        <div class="form-group">
          <label>Paper Company <span style="color:red">*</span></label>
          <input type="hidden" name="company" id="company-hidden" value="<?= e($old['company'] ?? '') ?>">
          <div id="company-dd-container"></div>
          <span class="form-hint">
            Type to search — suggestions include master list and existing stock values.
            <?php if ($legacyCompany !== '' && !in_array($legacyCompany, getMasterPaperCompanies(true), true)): ?>
              This roll's current value is a legacy entry — still available in the dropdown.
            <?php endif; ?>
          </span>
        </div>
        <div class="form-group">
          <label>Paper Type <span style="color:red">*</span></label>
          <input type="hidden" name="paper_type" id="type-dd-hidden" value="<?= e($old['paper_type'] ?? '') ?>">
          <div id="type-dd-container"></div>
          <span class="form-hint">
            Type to search — suggestions include master list and existing stock values.
            <?php if ($legacyType !== '' && !in_array($legacyType, getMasterPaperTypes(true), true)): ?>
              This roll's current type is a legacy entry — still available in the dropdown.
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-header"><span class="card-title">Technical Specifications</span></div>
    <div class="card-body">
      <div class="form-grid-4">
        <div class="form-group">
          <label>Width (MM) <span style="color:red">*</span></label>
          <input type="number" id="width_mm" name="width_mm" class="form-control"
                 value="<?= e($old['width_mm'] ?? '') ?>" step="0.01" required>
        </div>
        <div class="form-group">
          <label>Length (MTR) <span style="color:red">*</span></label>
          <input type="number" id="length_mtr" name="length_mtr" class="form-control"
                 value="<?= e($old['length_mtr'] ?? '') ?>" step="0.01" required>
        </div>
        <div class="form-group">
          <label>GSM</label>
          <input type="number" name="gsm" class="form-control" value="<?= e($old['gsm'] ?? '') ?>" step="0.01">
        </div>
        <div class="form-group">
          <label>Weight (KG)</label>
          <input type="number" name="weight_kg" class="form-control" value="<?= e($old['weight_kg'] ?? '') ?>" step="0.01">
        </div>
      </div>
      <div class="calc-grid mt-12">
        <div class="calc-item">
          <small>Calculated SQM</small>
          <strong class="green" id="sqm_display"><?= number_format(calcSQM((float)($old['width_mm']??0),(float)($old['length_mtr']??0)),2) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-header"><span class="card-title">Purchase & Usage</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Lot / Batch No</label>
          <input type="text" name="lot_batch_no" class="form-control" value="<?= e($old['lot_batch_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Company Roll No</label>
          <input type="text" name="company_roll_no" class="form-control" value="<?= e($old['company_roll_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Purchase Rate</label>
          <input type="number" name="purchase_rate" class="form-control" value="<?= e($old['purchase_rate'] ?? '') ?>" step="0.01">
        </div>
        <div class="form-group">
          <label>Date Received</label>
          <input type="date" name="date_received" class="form-control" value="<?= e($old['date_received'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Date Used</label>
          <input type="date" name="date_used" class="form-control" value="<?= e($old['date_used'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job No</label>
          <input type="text" name="job_no" class="form-control" value="<?= e($old['job_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job Name</label>
          <input type="text" name="job_name" class="form-control" value="<?= e($old['job_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job Size</label>
          <input type="text" name="job_size" class="form-control" value="<?= e($old['job_size'] ?? '') ?>">
        </div>
        <div class="form-group col-span-2">
          <label>Remarks</label>
          <textarea name="remarks" class="form-control" rows="2"><?= e($old['remarks'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Product Type Image Preview -->
  <div class="card mb-16" id="product-image-card">
    <div class="card-header"><span class="card-title"><i class="bi bi-image" style="color:#f97316"></i> Product Type Image</span></div>
    <div class="card-body" id="product-image-body">
      <div id="product-img-preview" style="text-align:center">
        <div style="color:#94a3b8;font-size:.82rem;padding:20px 0">Select a paper type to see the product image</div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-8">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Update Roll</button>
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/_dropdown_component.php'; ?>
<script>
(function(){
  var statusVal = <?= json_encode($curStatus) ?>;
  initStatusDropdown(
    document.getElementById('status-dd-container'),
    document.getElementById('status-hidden'),
    document.getElementById('custom-status-hidden'),
    statusVal,
    <?= json_encode(array_values($presetStatuses), JSON_UNESCAPED_UNICODE) ?>,
    false
  );

  // Paper Company typeahead (strict — must select from list)
  var companyOptions = <?= json_encode(array_values($companyOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  initSearchDropdown(
    document.getElementById('company-dd-container'),
    document.getElementById('company-hidden'),
    companyOptions,
    'Search paper company…',
    true
  );

  // Paper Type typeahead (strict — must select from list)
  var typeOptionsArr = <?= json_encode(array_values($typeOptions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  initSearchDropdown(
    document.getElementById('type-dd-container'),
    document.getElementById('type-dd-hidden'),
    typeOptionsArr,
    'Search paper type…',
    true
  );

  // Product type image preview
  var ptImageMap = <?= json_encode($productTypeImageMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var typeHidden = document.getElementById('type-dd-hidden');
  var imgPreview = document.getElementById('product-img-preview');
  var settingsUrl = <?= json_encode(BASE_URL . '/modules/settings/index.php?tab=library') ?>;

  function updateProductImage() {
    var val = (typeHidden.value || '').trim().toLowerCase();
    if (val && ptImageMap[val]) {
      imgPreview.innerHTML = '<img src="' + ptImageMap[val] + '" alt="Product Image" style="max-width:100%;width:280px;aspect-ratio:16/10;object-fit:cover;border:1px solid #e2e8f0;border-radius:10px;background:#fff">' +
        '<div style="font-size:.72rem;color:#94a3b8;margin-top:6px;font-weight:600">' + typeHidden.value + '</div>';
    } else if (val) {
      imgPreview.innerHTML = '<div style="width:280px;max-width:100%;aspect-ratio:16/10;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#64748b;font-size:.82rem;font-weight:600;gap:6px;padding:12px;text-align:center;margin:0 auto">' +
        '<i class="bi bi-image" style="font-size:22px;color:#94a3b8"></i>' +
        'No image for "' + typeHidden.value + '"' +
        '<a href="' + settingsUrl + '" style="font-size:.72rem;color:#f97316;font-weight:700;text-decoration:none" target="_blank">Upload in Settings &rarr;</a></div>';
    } else {
      imgPreview.innerHTML = '<div style="color:#94a3b8;font-size:.82rem;padding:20px 0">Select a paper type to see the product image</div>';
    }
  }

  // Observe changes to the hidden input
  var ptObserver = new MutationObserver(function() { updateProductImage(); });
  ptObserver.observe(typeHidden, { attributes: true, attributeFilter: ['value'] });
  typeHidden.addEventListener('change', updateProductImage);
  // Poll as fallback (dropdown component may set value programmatically)
  var ptLastVal = typeHidden.value;
  setInterval(function() {
    if (typeHidden.value !== ptLastVal) { ptLastVal = typeHidden.value; updateProductImage(); }
  }, 300);
  updateProductImage();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
