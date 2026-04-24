<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/functions.php';

$db = carton_db();
carton_ensure_tables($db);
$isAdminUser = isAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Security token mismatch. Please retry.');
        redirect(BASE_URL . '/modules/inventory/carton/items.php');
    }

    if (!$isAdminUser) {
        setFlash('error', 'Only admin can modify carton items.');
        redirect(BASE_URL . '/modules/inventory/carton/items.php');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $itemName = carton_clean_text($_POST['item_name'] ?? '', 255);
        $status = strtoupper(carton_clean_text($_POST['status'] ?? 'ACTIVE', 20));
        if ($status !== 'ACTIVE' && $status !== 'INACTIVE') {
            $status = 'ACTIVE';
        }

        if ($itemName === '') {
            setFlash('error', 'Item name is required.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }

        $stmt = $db->prepare('INSERT INTO carton_items (item_name, status, created_at) VALUES (?, ?, NOW())');
        if (!$stmt) {
            setFlash('error', 'Unable to prepare item insert.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }
        $stmt->bind_param('ss', $itemName, $status);
        if ($stmt->execute()) {
            setFlash('success', 'Item added successfully.');
        } else {
            setFlash('error', 'Unable to add item. Maybe duplicate name.');
        }
        redirect(BASE_URL . '/modules/inventory/carton/items.php');
    }

    if ($action === 'edit') {
        $id = carton_int($_POST['id'] ?? 0);
        $itemName = carton_clean_text($_POST['item_name'] ?? '', 255);
        $status = strtoupper(carton_clean_text($_POST['status'] ?? 'ACTIVE', 20));
        if ($status !== 'ACTIVE' && $status !== 'INACTIVE') {
            $status = 'ACTIVE';
        }

        if ($id <= 0 || $itemName === '') {
            setFlash('error', 'Invalid item data.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }

        $stmt = $db->prepare('UPDATE carton_items SET item_name = ?, status = ? WHERE id = ?');
        if (!$stmt) {
            setFlash('error', 'Unable to prepare item update.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }
        $stmt->bind_param('ssi', $itemName, $status, $id);
        if ($stmt->execute()) {
            setFlash('success', 'Item updated successfully.');
        } else {
            setFlash('error', 'Unable to update item.');
        }
        redirect(BASE_URL . '/modules/inventory/carton/items.php');
    }

    if ($action === 'delete') {
        $id = carton_int($_POST['id'] ?? 0);
        if ($id <= 0) {
            setFlash('error', 'Invalid item id.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }

        $checkStmt = $db->prepare('SELECT COUNT(*) AS c FROM carton_stock WHERE item_id = ?');
        $checkStmt->bind_param('i', $id);
        $checkStmt->execute();
        $count = (int)(($checkStmt->get_result()->fetch_assoc())['c'] ?? 0);

        if ($count > 0) {
            $stmt = $db->prepare("UPDATE carton_items SET status = 'INACTIVE' WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            setFlash('warning', 'Item has stock history, so marked as INACTIVE instead of delete.');
            redirect(BASE_URL . '/modules/inventory/carton/items.php');
        }

        $stmt = $db->prepare('DELETE FROM carton_items WHERE id = ?');
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            setFlash('success', 'Item deleted successfully.');
        } else {
            setFlash('error', 'Unable to delete item.');
        }
        redirect(BASE_URL . '/modules/inventory/carton/items.php');
    }
}

$pageTitle = 'Carton Item Management';
include __DIR__ . '/../../../includes/header.php';

$csrfToken = generateCSRF();
$editId = carton_int($_GET['edit_id'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = $db->prepare('SELECT id, item_name, status FROM carton_items WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editRow = $stmt->get_result()->fetch_assoc();
    }
}

$items = [];
$res = $db->query('SELECT id, item_name, status, created_at FROM carton_items ORDER BY item_name ASC');
if ($res) {
    $items = $res->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/inventory/carton/index.php">Carton Stock</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Item Management</span>
</div>

<div class="page-header">
  <div>
    <h1>Carton Item Management</h1>
    <p>Add, edit and delete carton items (admin only for changes).</p>
  </div>
</div>

<div class="card">
  <div class="card-header"><strong><?= $editRow ? 'Edit Item' : 'Add New Item' ?></strong></div>
  <div class="card-body" style="padding:16px;">
    <?php if (!$isAdminUser): ?>
      <div class="alert alert-warning">Only admin can add/edit/delete item.</div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
      <?php if ($editRow): ?>
        <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;">
        <div>
          <label>Item Name</label>
          <input class="form-control" type="text" name="item_name" maxlength="255" required value="<?= e((string)($editRow['item_name'] ?? '')) ?>" <?= $isAdminUser ? '' : 'disabled' ?>>
        </div>
        <div>
          <label>Status</label>
          <select class="form-control" name="status" <?= $isAdminUser ? '' : 'disabled' ?>>
            <?php $st = strtoupper((string)($editRow['status'] ?? 'ACTIVE')); ?>
            <option value="ACTIVE" <?= $st === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
            <option value="INACTIVE" <?= $st === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary" type="submit" <?= $isAdminUser ? '' : 'disabled' ?>>
            <i class="bi bi-check2-circle"></i> <?= $editRow ? 'Update' : 'Add' ?>
          </button>
          <?php if ($editRow): ?>
            <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/items.php">Cancel</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <div class="card-header"><strong>Items</strong></div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Item Name</th>
          <th>Status</th>
          <th>Created At</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="5" style="text-align:center;color:#6b7280;">No item found.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= e($row['item_name']) ?></td>
              <td><?= e($row['status']) ?></td>
              <td><?= e($row['created_at']) ?></td>
              <td style="display:flex;gap:6px;">
                <a class="btn btn-sm" href="<?= BASE_URL ?>/modules/inventory/carton/items.php?edit_id=<?= (int)$row['id'] ?>">Edit</a>
                <form method="post" action="" onsubmit="return confirm('Delete this item?');" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn btn-sm" type="submit" <?= $isAdminUser ? '' : 'disabled' ?>>Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
