<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$clientId = (int)($_GET['id'] ?? 0);

if ($clientId <= 0) {
  setFlash('error', 'Invalid client id.');
  redirect(BASE_URL . '/modules/master/index.php?tab=clients');
}

$stmt = $db->prepare("SELECT * FROM master_clients WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $clientId);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
  setFlash('error', 'Client not found.');
  redirect(BASE_URL . '/modules/master/index.php?tab=clients');
}

$pageTitle = 'Client Details';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/master/index.php?tab=clients">Master</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Client Details</span>
</div>

<div class="page-header">
  <div>
    <h1>Client Details</h1>
    <p>Client profile with credit controls for future sales restriction logic.</p>
  </div>
</div>

<div class="card settings-card settings-modern">
  <div class="settings-body">
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span class="card-title"><?= e($client['name']) ?></span>
        <div style="display:flex;gap:8px;align-items:center">
          <a class="btn btn-sm btn-secondary" href="<?= BASE_URL ?>/modules/master/index.php?tab=clients"><i class="bi bi-arrow-left"></i> Back</a>
          <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>/modules/master/index.php?tab=clients&edit_client_id=<?= (int)$client['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
        </div>
      </div>
      <div class="card-body" style="padding:18px">
        <div class="form-grid-2">
          <div class="form-group">
            <label>Contact Person</label>
            <input type="text" value="<?= e((string)($client['contact_person'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="text" value="<?= e((string)($client['phone'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="text" value="<?= e((string)($client['email'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Credit Period (days)</label>
            <input type="text" value="<?= e((string)((int)($client['credit_period_days'] ?? 0))) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Credit Limit</label>
            <input type="text" value="<?= e(number_format((float)($client['credit_limit'] ?? 0), 2)) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Credit Control Status</label>
            <input type="text" value="Ready for Sales credit restriction checks" readonly>
          </div>
          <div class="form-group">
            <label>City</label>
            <input type="text" value="<?= e((string)($client['city'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group">
            <label>State</label>
            <input type="text" value="<?= e((string)($client['state'] ?? '-')) ?>" readonly>
          </div>
          <div class="form-group col-span-2">
            <label>Address</label>
            <textarea readonly rows="3"><?= e((string)($client['address'] ?? '-')) ?></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
