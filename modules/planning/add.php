<?php
// ============================================================
// ERP System — Planning: Add
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$errors = [];
$statuses   = ['Running', 'Completed', 'Hold', 'Hold for Payment', 'Hold for Approval', 'Pending'];
$priorities = ['Low','Normal','High','Urgent'];
$planningJobPreview = previewNextId('planning') ?: 'Auto-generated on save';

// Load open sales orders for dropdown
$soList = $db->query(
    "SELECT id, order_no, client_name FROM sales_orders
     WHERE status NOT IN ('Completed','Dispatched','Cancelled')
     ORDER BY id DESC"
)->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $jobName      = trim($_POST['job_name']      ?? '');
        $soId         = (int)($_POST['sales_order_id'] ?? 0);
        $machine      = trim($_POST['machine']       ?? '');
        $operatorName = trim($_POST['operator_name'] ?? '');
        $scheduledDate= trim($_POST['scheduled_date'] ?? '');
        $priority     = $_POST['priority']           ?? 'Normal';
        $status       = 'Pending';
        $notes        = trim($_POST['notes']         ?? '');

        if ($jobName === '')             $errors[] = 'Job name is required.';
        if (!in_array($priority,$priorities)) $errors[] = 'Invalid priority.';
        if (!in_array($status,$statuses))     $errors[] = 'Invalid status.';

        if (empty($errors)) {
            $soIdVal = $soId > 0 ? $soId : null;
            $machVal = $machine   !== '' ? $machine   : null;
            $opVal   = $operatorName !== '' ? $operatorName : null;
            $dtVal   = $scheduledDate !== '' ? $scheduledDate : null;
            $notesVal= $notes !== '' ? $notes : null;

            // Auto-generate planning job number
            $planJobNo = getNextId('planning');
            if (!$planJobNo) $planJobNo = 'PLN-' . date('Ymd') . '-' . rand(1000,9999);

            $stmt = $db->prepare(
                "INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name,
                 scheduled_date, priority, status, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param('sisssssssi',
                $planJobNo, $soIdVal, $jobName, $machVal, $opVal,
                $dtVal, $priority, $status, $notesVal,
                $_SESSION['user_id']
            );
            if ($stmt->execute()) {
              planningCreateNotifications($db, $planJobNo, $jobName, 'general', 'added');
              setFlash('success',"Job '{$planJobNo} - {$jobName}' added to planning.");
                redirect(BASE_URL.'/modules/planning/index.php');
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
}

$pageTitle = 'Add Planning Job';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Planning</a><span class="breadcrumb-sep">›</span><span>Add</span>
</div>
<div class="page-header">
  <div><h1>Add Planning Job</h1><p>Schedule a production job.</p></div>
  <a href="index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Errors:</strong>
  <ul style="margin:6px 0 0 18px"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach;?></ul>
</div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
  <div class="card">
    <div class="card-body">
      <div class="planning-job-preview-box" style="margin:0 0 16px">
        <span class="planning-job-preview-label">Job ID</span>
        <strong><?= e($planningJobPreview) ?></strong>
        <small>Final number is assigned when you save.</small>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Job Name <span class="req">*</span></label>
          <input type="text" name="job_name" class="form-control" required value="<?= e($_POST['job_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Linked Sales Order</label>
          <select name="sales_order_id" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($soList as $so): ?>
            <option value="<?= $so['id'] ?>" <?= ($_POST['sales_order_id']??'')==$so['id']?'selected':'' ?>>
              <?= e($so['order_no']) ?> — <?= e($so['client_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Machine</label>
          <input type="text" name="machine" class="form-control" placeholder="e.g. Flexo Press #1" value="<?= e($_POST['machine'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Operator</label>
          <input type="text" name="operator_name" class="form-control" value="<?= e($_POST['operator_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Scheduled Date</label>
          <input type="date" name="scheduled_date" class="form-control" value="<?= e($_POST['scheduled_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-control">
            <?php foreach ($priorities as $p): ?>
            <option value="<?= $p ?>" <?= ($_POST['priority']??'Normal')==$p?'selected':'' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <input type="text" class="form-control" value="Pending" readonly>
          <input type="hidden" name="status" value="Pending">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Add to Planning</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<style>
.req{color:var(--danger)}
.planning-job-preview-box {
  padding: 12px 14px;
  border: 1px solid #dbeafe;
  background: #eff6ff;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.planning-job-preview-label {
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #1d4ed8;
}
.planning-job-preview-box strong {
  font-size: 1rem;
  color: #0f172a;
}
.planning-job-preview-box small {
  color: #475569;
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>