<?php
// ============================================================
// Shree Label ERP — Estimates: List
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

$fSearch = trim($_GET['search'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

$where = ['1=1']; $params = []; $types = '';
if ($fSearch !== '') {
    $like    = '%' . $fSearch . '%';
    $where[] = '(estimate_no LIKE ? OR client_name LIKE ?)';
    $params  = array_merge($params, [$like, $like]); $types .= 'ss';
}
if ($fStatus !== '') {
    $where[] = 'status = ?'; $params[] = $fStatus; $types .= 's';
}
$whereSQL = implode(' AND ', $where);

$perPage = 20; $page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*$perPage;

$totalQ = $db->prepare("SELECT COUNT(*) AS c FROM estimates WHERE {$whereSQL}");
if ($types) $totalQ->bind_param($types, ...$params);
$totalQ->execute();
$total = (int)$totalQ->get_result()->fetch_assoc()['c'];

$stmt = $db->prepare("SELECT e.*, u.name AS created_by_name FROM estimates e LEFT JOIN users u ON u.id=e.created_by WHERE {$whereSQL} ORDER BY e.id DESC LIMIT ? OFFSET ?");
$stmt->bind_param($types . 'ii', ...[...$params, $perPage, $offset]);
$stmt->execute();
$estimates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statuses = ['Draft','Sent','Approved','Rejected','Converted'];
$pageTitle = 'Estimates';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span><span>Estimates</span>
</div>
<div class="page-header">
  <div><h1>Estimates</h1><p>Job costing and quotations.</p></div>
  <div class="page-header-actions">
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Estimate</a>
  </div>
</div>

<form method="GET" class="filter-bar mb-0">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="search" placeholder="Estimate No / Client" value="<?= e($fSearch) ?>">
  </div>
  <div class="filter-group">
    <label>Status</label>
    <select name="status">
      <option value="">All Status</option>
      <?php foreach ($statuses as $s): ?>
      <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group" style="justify-content:flex-end"><label>&nbsp;</label>
    <div class="d-flex gap-8">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
      <a href="index.php" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> Reset</a>
    </div>
  </div>
</form>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">All Estimates <span class="badge badge-consumed" style="margin-left:6px"><?= $total ?></span></span>
    <span class="text-muted text-sm"><?= min($total,$offset+1) ?>–<?= min($total,$offset+$perPage) ?> of <?= $total ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Estimate No</th><th>Client</th><th>Label (mm)</th>
          <th>Qty</th><th>Total Cost</th><th>Selling Price</th>
          <th>Status</th><th>Date</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($estimates)): ?>
        <tr><td colspan="10" class="table-empty"><i class="bi bi-inbox"></i>No estimates found.</td></tr>
        <?php else: ?>
        <?php foreach ($estimates as $i => $e): ?>
        <tr>
          <td class="text-muted"><?= $offset+$i+1 ?></td>
          <td class="fw-600"><a href="view.php?id=<?= $e['id'] ?>" class="text-blue"><?= e($e['estimate_no']) ?></a></td>
          <td><?= e($e['client_name']) ?></td>
          <td class="text-muted"><?= e($e['label_length_mm']) ?> × <?= e($e['label_width_mm']) ?></td>
          <td><?= number_format((int)$e['quantity']) ?></td>
          <td>₹<?= number_format((float)$e['total_cost'],2) ?></td>
          <td class="fw-600 text-green">₹<?= number_format((float)$e['selling_price'],2) ?></td>
          <td><?= statusBadge($e['status']) ?></td>
          <td class="text-muted"><?= formatDate($e['created_at']) ?></td>
          <td>
            <div class="row-actions">
              <a href="view.php?id=<?= $e['id'] ?>"   class="btn btn-blue btn-sm" title="View"><i class="bi bi-eye"></i></a>
              <a href="edit.php?id=<?= $e['id'] ?>"   class="btn btn-secondary btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
              <?php if ($e['status'] === 'Approved'): ?>
              <a href="convert.php?id=<?= $e['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-amber btn-sm" title="Convert to Order"
                 data-confirm="Convert estimate <?= e($e['estimate_no']) ?> to a Sales Order?">
                <i class="bi bi-arrow-right-circle"></i>
              </a>
              <?php endif; ?>
              <a href="delete.php?id=<?= $e['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-danger btn-sm" title="Delete"
                 data-confirm="Delete estimate <?= e($e['estimate_no']) ?>?">
                <i class="bi bi-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
  $qStr = http_build_query(['search'=>$fSearch,'status'=>$fStatus,'page'=>'{page}']);
  echo paginationBar($total, $perPage, $page, BASE_URL . '/modules/estimate/index.php?' . $qStr);
  ?>
  <div style="padding:8px 14px"></div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
