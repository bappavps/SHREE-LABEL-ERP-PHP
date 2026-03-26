<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$supplierId = (int)($_GET['id'] ?? 0);

if ($supplierId <= 0) {
  setFlash('error', 'Invalid supplier id.');
  redirect(BASE_URL . '/modules/master/index.php?tab=suppliers');
}

$stmt = $db->prepare("SELECT * FROM master_suppliers WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $supplierId);
$stmt->execute();
$supplier = $stmt->get_result()->fetch_assoc();

if (!$supplier) {
  setFlash('error', 'Supplier not found.');
  redirect(BASE_URL . '/modules/master/index.php?tab=suppliers');
}

$pageTitle = 'Supplier Details';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/master/index.php?tab=suppliers">Master</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Supplier Details</span>
</div>

<div class="page-header">
  <div>
    <h1>Supplier Details</h1>
    <p>Complete profile of supplier for procurement workflow.</p>
  </div>
</div>

<div class="card settings-card settings-modern">
  <div class="settings-body">
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span class="card-title"><?= e($supplier['name']) ?></span>
        <div style="display:flex;gap:8px;align-items:center">
          <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/modules/master/index.php?tab=suppliers"><i class="bi bi-arrow-left"></i> Back</a>
          <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>/modules/master/index.php?tab=suppliers&edit_supplier_id=<?= (int)$supplier['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
        </div>
      </div>
      <div class="card-body" style="padding:18px">
        <div class="form-grid-2">
          <div class="form-group">
            <label>GST Number</label>
            <input type="text" value="<?= e((string)($supplier['gst_number'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Contact Person</label>
            <input type="text" value="<?= e((string)($supplier['contact_person'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="text" value="<?= e((string)($supplier['phone'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="text" value="<?= e((string)($supplier['email'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>City</label>
            <input type="text" value="<?= e((string)($supplier['city'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>State</label>
            <input type="text" value="<?= e((string)($supplier['state'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group col-span-2">
            <label>Address</label>
            <textarea readonly rows="3"><?= e((string)($supplier['address'] ?? '-')) ?></textarea>
          </div>
          <div class="form-group col-span-2">
            <label>Notes</label>
            <textarea readonly rows="3"><?= e((string)($supplier['notes'] ?? '-')) ?></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
