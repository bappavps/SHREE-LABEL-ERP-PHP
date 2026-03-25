<?php
// ============================================================
// ERP System — Sales Orders: List
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
    $where[] = '(so.order_no LIKE ? OR so.client_name LIKE ?)';
    $params  = array_merge($params, [$like, $like]); $types .= 'ss';
}
if ($fStatus !== '') {
    $where[] = 'so.status = ?'; $params[] = $fStatus; $types .= 's';
}
$whereSQL = implode(' AND ', $where);

$perPage = 20; $page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*$perPage;

$totalQ = $db->prepare("SELECT COUNT(*) AS c FROM sales_orders so WHERE {$whereSQL}");
if ($types) $totalQ->bind_param($types, ...$params);
$totalQ->execute();
$total = (int)$totalQ->get_result()->fetch_assoc()['c'];

$listQ = $db->prepare(
    "SELECT so.*, e.estimate_no FROM sales_orders so
     LEFT JOIN estimates e ON e.id = so.estimate_id
     WHERE {$whereSQL}
     ORDER BY so.id DESC LIMIT ? OFFSET ?"
);
$listQ->bind_param($types.'ii', ...[...$params, $perPage, $offset]);
$listQ->execute();
$orders = $listQ->get_result()->fetch_all(MYSQLI_ASSOC);

$statuses = ['Pending','In Production','Completed','Dispatched','Cancelled'];
$pageTitle = 'Sales Orders';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>Sales Orders</span>
</div>
<div class="page-header">
  <div><h1>Sales Orders</h1><p>Track all customer orders.</p></div>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> New Order</a>
</div>

<form method="GET" class="filter-bar mb-0">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="search" placeholder="Order No / Client" value="<?= e($fSearch) ?>">
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
    <span class="card-title">All Orders <span class="badge badge-consumed" style="margin-left:6px"><?= $total ?></span></span>
    <span class="text-muted text-sm"><?= min($total,$offset+1) ?>–<?= min($total,$offset+$perPage) ?> of <?= $total ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Order No</th><th>Client</th><th>Label (mm)</th>
          <th>Qty</th><th>Selling Price</th><th>Estimate</th>
          <th>Status</th><th>Date</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="10" class="table-empty"><i class="bi bi-inbox"></i>No sales orders found.</td></tr>
        <?php else: ?>
        <?php foreach ($orders as $i => $o): ?>
        <tr>
          <td class="text-muted"><?= $offset+$i+1 ?></td>
          <td class="fw-600"><a href="view.php?id=<?= $o['id'] ?>" class="text-blue"><?= e($o['order_no']) ?></a></td>
          <td><?= e($o['client_name']) ?></td>
          <td class="text-muted"><?= e($o['label_length_mm']) ?> × <?= e($o['label_width_mm']) ?></td>
          <td><?= number_format((int)$o['quantity']) ?></td>
          <td class="fw-600 text-green">₹<?= number_format((float)$o['selling_price'],2) ?></td>
          <td>
            <?php if ($o['estimate_no']): ?>
            <a href="<?= BASE_URL ?>/modules/estimate/view.php?id=<?= $o['estimate_id'] ?>" class="text-blue text-sm">
              <?= e($o['estimate_no']) ?>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= statusBadge($o['status']) ?></td>
          <td class="text-muted"><?= formatDate($o['created_at']) ?></td>
          <td>
            <div class="row-actions">
              <a href="view.php?id=<?= $o['id'] ?>"   class="btn btn-blue btn-sm" title="View"><i class="bi bi-eye"></i></a>
              <a href="edit.php?id=<?= $o['id'] ?>"   class="btn btn-secondary btn-sm" title="Edit Status"><i class="bi bi-pencil"></i></a>
              <a href="delete.php?id=<?= $o['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-danger btn-sm" title="Delete"
                 data-confirm="Delete order <?= e($o['order_no']) ?>?"><i class="bi bi-trash"></i></a>
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
  echo paginationBar($total, $perPage, $page, BASE_URL . '/modules/sales_order/index.php?' . $qStr);
  ?>
  <div style="padding:8px 14px"></div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
