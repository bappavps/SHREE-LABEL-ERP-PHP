<?php
// ============================================================
// Shree Label ERP — Sales Orders: Add (manual)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$errors = [];

$materialTypes = ['BOPP','PET','PVC','Paper','Thermal','Polyester','Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $clientName   = trim($_POST['client_name']     ?? '');
        $labelLength  = trim($_POST['label_length_mm'] ?? '');
        $labelWidth   = trim($_POST['label_width_mm']  ?? '');
        $quantity     = trim($_POST['quantity']        ?? '');
        $materialType = trim($_POST['material_type']   ?? '');
        $sellingPrice = trim($_POST['selling_price']   ?? '0');
        $notes        = trim($_POST['notes']           ?? '');

        if ($clientName === '')             $errors[] = 'Client name is required.';
        if (!is_numeric($labelLength)||(float)$labelLength<=0) $errors[] = 'Label length must be positive.';
        if (!is_numeric($labelWidth) ||(float)$labelWidth <=0) $errors[] = 'Label width must be positive.';
        if (!is_numeric($quantity)   ||(int)$quantity      <=0) $errors[] = 'Quantity must be a positive integer.';
        if ($materialType === '')           $errors[] = 'Material type is required.';
        if (!is_numeric($sellingPrice)||(float)$sellingPrice<0) $errors[] = 'Selling price must be 0 or positive.';

        if (empty($errors)) {
            $orderNo = generateDocNo('SO','sales_orders','order_no');
            $stmt = $db->prepare(
                "INSERT INTO sales_orders
                 (order_no, client_name, label_length_mm, label_width_mm, quantity,
                  material_type, selling_price, notes, status, created_by)
                 VALUES (?,?,?,?,?,?,?,?,'Pending',?)"
            );
            $stmt->bind_param('ssddisdsi',
                $orderNo, $clientName,
                (float)$labelLength, (float)$labelWidth, (int)$quantity,
                $materialType, (float)$sellingPrice,
                $notes, $_SESSION['user_id']
            );
            if ($stmt->execute()) {
                setFlash('success',"Sales Order {$orderNo} created.");
                redirect(BASE_URL.'/modules/sales_order/index.php');
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
}

$pageTitle = 'New Sales Order';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Sales Orders</a><span class="breadcrumb-sep">›</span><span>New</span>
</div>
<div class="page-header">
  <div><h1>New Sales Order</h1><p>Create an order manually (without an estimate).</p></div>
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
    <div class="card-header"><span class="card-title">Order Details</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Client Name <span class="req">*</span></label>
          <input type="text" name="client_name" class="form-control" required value="<?= e($_POST['client_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Material Type <span class="req">*</span></label>
          <select name="material_type" class="form-control" required>
            <option value="">— select —</option>
            <?php foreach ($materialTypes as $mt): ?>
            <option value="<?= $mt ?>" <?= ($_POST['material_type']??'')===$mt?'selected':'' ?>><?= $mt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Label Length (mm) <span class="req">*</span></label>
          <input type="number" name="label_length_mm" class="form-control" min="1" step="0.01" required value="<?= e($_POST['label_length_mm'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Label Width (mm) <span class="req">*</span></label>
          <input type="number" name="label_width_mm" class="form-control" min="1" step="0.01" required value="<?= e($_POST['label_width_mm'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Quantity (pcs) <span class="req">*</span></label>
          <input type="number" name="quantity" class="form-control" min="1" required value="<?= e($_POST['quantity'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Selling Price (₹)</label>
          <input type="number" name="selling_price" class="form-control" min="0" step="0.01" value="<?= e($_POST['selling_price'] ?? '0') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Create Order</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<style>.req{color:var(--danger)}</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
