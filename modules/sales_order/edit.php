<?php
// ============================================================
// Shree Label ERP — Sales Orders: Edit (Status update)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid order.'); redirect(BASE_URL.'/modules/sales_order/index.php'); }

$stmt = $db->prepare("SELECT * FROM sales_orders WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { setFlash('error','Order not found.'); redirect(BASE_URL.'/modules/sales_order/index.php'); }

$statuses = ['Pending','In Production','Completed','Dispatched','Cancelled'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $status = $_POST['status'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        if (!in_array($status, $statuses)) $errors[] = 'Invalid status selected.';
        if (empty($errors)) {
            $upd = $db->prepare("UPDATE sales_orders SET status=?, notes=? WHERE id=?");
            $upd->bind_param('ssi', $status, $notes, $id);
            if ($upd->execute()) {
                setFlash('success','Order updated.');
                redirect(BASE_URL.'/modules/sales_order/view.php?id='.$id);
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
}

$pageTitle = 'Edit Order — '.$order['order_no'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Sales Orders</a><span class="breadcrumb-sep">›</span>
  <a href="view.php?id=<?= $id ?>"><?= e($order['order_no']) ?></a><span class="breadcrumb-sep">›</span>
  <span>Edit</span>
</div>
<div class="page-header">
  <div><h1>Update Order</h1><p><?= e($order['order_no']) ?> — <?= e($order['client_name']) ?></p></div>
  <a href="view.php?id=<?= $id ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <?php foreach($errors as $e): ?><p style="margin:2px 0"><?= e($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
  <div class="card">
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
            <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $order['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="3"><?= e($order['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
