<?php
// ============================================================
// Shree Label ERP — Planning: Edit
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid planning item.'); redirect(BASE_URL.'/modules/planning/index.php'); }

$stmt = $db->prepare("SELECT * FROM planning WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
if (!$item) { setFlash('error','Planning item not found.'); redirect(BASE_URL.'/modules/planning/index.php'); }

$statuses   = ['Queued','In Progress','Completed','On Hold'];
$priorities = ['Low','Normal','High','Urgent'];
$errors = [];

$soList = $db->query(
    "SELECT id, order_no, client_name FROM sales_orders
     WHERE status NOT IN ('Completed','Dispatched','Cancelled') OR id = " . (int)$item['sales_order_id'] . "
     ORDER BY id DESC"
)->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $jobName       = trim($_POST['job_name'] ?? '');
        $salesOrderId  = (int)($_POST['sales_order_id'] ?? 0);
        $machine       = trim($_POST['machine'] ?? '');
        $operatorName  = trim($_POST['operator_name'] ?? '');
        $scheduledDate = trim($_POST['scheduled_date'] ?? '');
        $status        = $_POST['status'] ?? 'Queued';
        $priority      = $_POST['priority'] ?? 'Normal';
        $notes         = trim($_POST['notes'] ?? '');

        if ($jobName === '') $errors[] = 'Job name is required.';
        if (!in_array($status, $statuses)) $errors[] = 'Invalid status.';
        if (!in_array($priority, $priorities)) $errors[] = 'Invalid priority.';
        if ($scheduledDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
            $errors[] = 'Scheduled date format is invalid.';
        }

        if (empty($errors)) {
            $orderVal = $salesOrderId > 0 ? $salesOrderId : null;
            $machVal  = $machine !== '' ? $machine : null;
            $opVal    = $operatorName !== '' ? $operatorName : null;
            $dateVal  = $scheduledDate !== '' ? $scheduledDate : null;
            $notesVal = $notes !== '' ? $notes : null;

            $upd = $db->prepare(
                "UPDATE planning SET
                 sales_order_id=?, job_name=?, machine=?, operator_name=?,
                 scheduled_date=?, status=?, priority=?, notes=?
                 WHERE id=?"
            );
            $upd->bind_param(
                'isssssssi',
                $orderVal, $jobName, $machVal, $opVal,
                $dateVal, $status, $priority, $notesVal,
                $id
            );
            if ($upd->execute()) {
                setFlash('success','Planning item updated.');
                redirect(BASE_URL.'/modules/planning/index.php');
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
    $item = array_merge($item, $_POST);
}

$pageTitle = 'Edit Planning Job';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Planning</a><span class="breadcrumb-sep">›</span><span>Edit</span>
</div>
<div class="page-header">
  <div><h1>Edit Planning Job</h1><p><?= e($item['job_name']) ?></p></div>
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
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Job Name <span class="req">*</span></label>
          <input type="text" name="job_name" class="form-control" required value="<?= e($item['job_name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Linked Sales Order</label>
          <select name="sales_order_id" class="form-control">
            <option value="0">— none —</option>
            <?php foreach($soList as $so): ?>
            <option value="<?= $so['id'] ?>" <?= ((int)($item['sales_order_id'] ?? 0)===(int)$so['id'])?'selected':'' ?>>
              <?= e($so['order_no']) ?> · <?= e($so['client_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Machine</label>
          <input type="text" name="machine" class="form-control" value="<?= e($item['machine'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Operator</label>
          <input type="text" name="operator_name" class="form-control" value="<?= e($item['operator_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Scheduled Date</label>
          <input type="date" name="scheduled_date" class="form-control" value="<?= e($item['scheduled_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <?php foreach($statuses as $s): ?>
            <option value="<?= $s ?>" <?= ($item['status'] ?? 'Queued')===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-control">
            <?php foreach($priorities as $p): ?>
            <option value="<?= $p ?>" <?= ($item['priority'] ?? 'Normal')===$p?'selected':'' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="3"><?= e($item['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<style>.req{color:var(--danger)}</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
