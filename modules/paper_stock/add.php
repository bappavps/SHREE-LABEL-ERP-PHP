<?php
// ============================================================
// ERP System — Paper Stock: Add Roll
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db     = getDB();
$errors = [];
$old    = [];
$prefilledRollNo = getIdPreview('roll');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $old = $_POST;

        // Required fields
        $roll_no    = trim($old['roll_no']     ?? '');
        $paper_type = trim($old['paper_type']  ?? '');
        $company    = trim($old['company']     ?? '');
        $width_mm   = trim($old['width_mm']    ?? '');
        $length_mtr = trim($old['length_mtr']  ?? '');

        if ($roll_no === '') {
          $generated = getNextId('roll');
          if ($generated) {
            $roll_no = $generated;
            $old['roll_no'] = $generated;
          } else {
            $errors[] = 'Roll No is required.';
          }
        }
        if ($paper_type === '') $errors[] = 'Paper Type is required.';
        if ($company === '')    $errors[] = 'Company is required.';
        if (!is_numeric($width_mm) || $width_mm <= 0)   $errors[] = 'Width must be a positive number.';
        if (!is_numeric($length_mtr) || $length_mtr <= 0) $errors[] = 'Length must be a positive number.';

        // Unique roll_no check
        if ($roll_no !== '' && empty($errors)) {
            $chk = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ? LIMIT 1");
            $chk->bind_param('s', $roll_no);
            $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $errors[] = 'Roll No "' . e($roll_no) . '" already exists.';
            }
        }

        if (empty($errors)) {
            $validStatuses = ['Main','Stock','Slitting','Job Assign','In Production','Consumed'];
            $fields = [
                'roll_no', 'paper_type', 'company', 'width_mm', 'length_mtr',
                'gsm', 'weight_kg', 'purchase_rate', 'lot_batch_no', 'company_roll_no',
                'status', 'job_no', 'job_size', 'job_name', 'date_received', 'date_used', 'remarks'
            ];
            $data = [];
            foreach ($fields as $f) {
                $v = trim($old[$f] ?? '');
                $data[$f] = ($v === '') ? null : $v;
            }
              $data['roll_no'] = $roll_no;
            $data['status']     = in_array($data['status'] ?? '', $validStatuses) ? $data['status'] : 'Main';
            $data['created_by'] = $_SESSION['user_id'];

            $stmt = $db->prepare("INSERT INTO paper_stock
                (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate,
                 lot_batch_no, company_roll_no, status, job_no, job_size, job_name,
                 date_received, date_used, remarks, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param(
                'sssddddssssssssssi',
                $data['roll_no'], $data['paper_type'], $data['company'],
                $data['width_mm'], $data['length_mtr'],
                $data['gsm'], $data['weight_kg'], $data['purchase_rate'],
                $data['lot_batch_no'], $data['company_roll_no'],
                $data['status'], $data['job_no'], $data['job_size'], $data['job_name'],
                $data['date_received'], $data['date_used'], $data['remarks'],
                $data['created_by']
            );

            if ($stmt->execute()) {
                // Log inventory
                $log = $db->prepare("INSERT INTO inventory_logs (action_type, roll_no, paper_stock_id, quantity_change, description, performed_by)
                                     VALUES ('IN', ?, ?, ?, 'Roll added to stock', ?)");
                $newId = $db->insert_id;
                $log->bind_param('sidd', $data['roll_no'], $newId, $data['length_mtr'], $_SESSION['user_id']);
                $log->execute();

                setFlash('success', 'Roll ' . $data['roll_no'] . ' added successfully.');
                redirect(BASE_URL . '/modules/paper_stock/index.php');
            } else {
                $errors[] = 'Database error: ' . $db->error;
            }
        }
    }
}

$csrf = generateCSRF();
$pageTitle = 'Add Paper Roll';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/paper_stock/index.php">Paper Stock</a>
  <span class="breadcrumb-sep">›</span>
  <span>Add Roll</span>
</div>

<div class="page-header">
  <div><h1>Add Paper Roll</h1><p>Register a new roll into the inventory.</p></div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Back
    </a>
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
          <label>Roll No <span style="color:red">*</span></label>
             <input type="text" name="roll_no" class="form-control" placeholder="<?= e($prefilledRollNo ?: 'SLC/26/001') ?>"
               value="<?= e($old['roll_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status" class="form-control">
            <?php foreach (['Main','Stock','Slitting','Job Assign','In Production','Consumed'] as $s): ?>
            <option value="<?= $s ?>" <?= ($old['status'] ?? 'Main') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Paper Company <span style="color:red">*</span></label>
          <input type="text" name="company" class="form-control" placeholder="JK Paper / West Coast…"
                 value="<?= e($old['company'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Paper Type <span style="color:red">*</span></label>
          <input type="text" name="paper_type" class="form-control" placeholder="Maplitho / Chromo / Thermal…"
                 value="<?= e($old['paper_type'] ?? '') ?>" required>
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
          <input type="number" id="width_mm" name="width_mm" class="form-control" placeholder="330"
                 value="<?= e($old['width_mm'] ?? '') ?>" step="0.01" min="1" required>
        </div>
        <div class="form-group">
          <label>Length (MTR) <span style="color:red">*</span></label>
          <input type="number" id="length_mtr" name="length_mtr" class="form-control" placeholder="4200"
                 value="<?= e($old['length_mtr'] ?? '') ?>" step="0.01" min="1" required>
        </div>
        <div class="form-group">
          <label>GSM</label>
          <input type="number" name="gsm" class="form-control" placeholder="70"
                 value="<?= e($old['gsm'] ?? '') ?>" step="0.01">
        </div>
        <div class="form-group">
          <label>Weight (KG)</label>
          <input type="number" name="weight_kg" class="form-control" placeholder="97.0"
                 value="<?= e($old['weight_kg'] ?? '') ?>" step="0.01">
        </div>
      </div>

      <!-- Auto-calculated SQM display -->
      <div class="calc-grid mt-12">
        <div class="calc-item">
          <small>Calculated SQM</small>
          <strong class="green" id="sqm_display">—</strong>
          <input type="hidden" id="sqm" name="sqm">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-16">
    <div class="card-header"><span class="card-title">Purchase Details</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Lot / Batch No</label>
          <input type="text" name="lot_batch_no" class="form-control" placeholder="LOT-A12"
                 value="<?= e($old['lot_batch_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Company Roll No</label>
          <input type="text" name="company_roll_no" class="form-control" placeholder="JK-8721"
                 value="<?= e($old['company_roll_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Purchase Rate (per SQM)</label>
          <input type="number" name="purchase_rate" class="form-control" placeholder="88.00"
                 value="<?= e($old['purchase_rate'] ?? '') ?>" step="0.01">
        </div>
        <div class="form-group">
          <label>Date Received</label>
          <input type="date" name="date_received" class="form-control"
                 value="<?= e($old['date_received'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="form-group">
          <label>Date Used</label>
          <input type="date" name="date_used" class="form-control"
                 value="<?= e($old['date_used'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job No</label>
          <input type="text" name="job_no" class="form-control" placeholder="JC-2026-001"
                 value="<?= e($old['job_no'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job Name</label>
          <input type="text" name="job_name" class="form-control" placeholder="Haldirams Namkeen Label"
                 value="<?= e($old['job_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Job Size</label>
          <input type="text" name="job_size" class="form-control" placeholder="100x75 mm"
                 value="<?= e($old['job_size'] ?? '') ?>">
        </div>
        <div class="form-group col-span-2">
          <label>Remarks</label>
          <textarea name="remarks" class="form-control" rows="2"
                    placeholder="Any notes about this roll…"><?= e($old['remarks'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-8">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Roll</button>
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
