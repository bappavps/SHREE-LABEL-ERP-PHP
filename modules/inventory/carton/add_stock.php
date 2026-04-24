<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/functions.php';

// Legacy page is no longer used; redirect to active carton workflow.
redirect(BASE_URL . '/modules/inventory/finished/index.php');

$db = carton_db();
carton_ensure_tables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Security token mismatch. Please retry.');
        redirect(BASE_URL . '/modules/inventory/carton/add_stock.php');
    }

    $itemId = carton_int($_POST['item_id'] ?? 0);
    $qty = carton_int($_POST['qty'] ?? 0);
    $remarks = carton_clean_text($_POST['remarks'] ?? '', 2000);

    if ($itemId <= 0 || $qty <= 0) {
        setFlash('error', 'Item and quantity are required.');
        redirect(BASE_URL . '/modules/inventory/carton/add_stock.php');
    }

    if (!carton_add_stock_entry($db, $itemId, $qty, 'ADD', 'MANUAL', null, $remarks)) {
        setFlash('error', 'Unable to add carton stock.');
        redirect(BASE_URL . '/modules/inventory/carton/add_stock.php');
    }

    setFlash('success', 'Carton stock added successfully.');
    redirect(BASE_URL . '/modules/inventory/carton/add_stock.php');
}

$pageTitle = 'Add Carton Stock';
include __DIR__ . '/../../../includes/header.php';

$csrfToken = generateCSRF();
$items = [];
$stmt = $db->prepare("SELECT id, item_name FROM carton_items WHERE status='ACTIVE' ORDER BY item_name ASC");
if ($stmt) {
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$recent = [];
$recentSql = "SELECT cs.id, ci.item_name, cs.qty, cs.type, cs.ref_type, cs.remarks, cs.created_at
              FROM carton_stock cs
              INNER JOIN carton_items ci ON ci.id = cs.item_id
              WHERE cs.type='ADD' AND cs.ref_type='MANUAL'
              ORDER BY cs.id DESC LIMIT 20";
$recentRes = $db->query($recentSql);
if ($recentRes) {
    $recent = $recentRes->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/inventory/carton/index.php">Carton Stock</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Add Stock</span>
</div>

<div class="page-header">
  <div>
    <h1>Add Carton Stock</h1>
    <p>Manual carton stock inward entry.</p>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong>Manual Add</strong></div>
  <div class="card-body" style="padding:16px;">
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

      <div class="form-grid" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
        <div>
          <label>Item</label>
          <select class="form-control" name="item_id" required>
            <option value="">Select item</option>
            <?php foreach ($items as $it): ?>
              <option value="<?= (int)$it['id'] ?>"><?= e($it['item_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Quantity</label>
          <input class="form-control" type="number" name="qty" min="1" step="1" required>
        </div>
        <div>
          <label>Remarks</label>
          <input class="form-control" type="text" name="remarks" maxlength="2000" placeholder="Optional remarks">
        </div>
      </div>

      <div style="margin-top:14px;display:flex;gap:8px;">
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-circle"></i> Add Stock</button>
        <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/index.php">Back</a>
      </div>
    </form>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <div class="card-header"><strong>Recent Manual Adds</strong></div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Item</th>
          <th>Qty</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recent)): ?>
          <tr><td colspan="4" style="text-align:center;color:#6b7280;">No records found.</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $row): ?>
            <tr>
              <td><?= e($row['created_at']) ?></td>
              <td><?= e($row['item_name']) ?></td>
              <td><?= (int)$row['qty'] ?></td>
              <td><?= e((string)($row['remarks'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
