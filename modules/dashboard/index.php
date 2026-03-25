<?php
// ============================================================
// Shree Label ERP — Dashboard
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

// ── KPI Queries ──────────────────────────────────────────────
$kpi = [];

$r = $db->query("SELECT COUNT(*) AS c FROM paper_stock WHERE status = 'Available'");
$kpi['stock_available'] = $r ? $r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM estimates WHERE status NOT IN ('Rejected','Converted')");
$kpi['estimates_active'] = $r ? $r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM sales_orders WHERE status NOT IN ('Completed','Dispatched','Cancelled')");
$kpi['orders_active'] = $r ? $r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM planning WHERE status IN ('Queued','In Progress')");
$kpi['jobs_pending'] = $r ? $r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT IFNULL(SUM(length_mtr),0) AS total FROM paper_stock WHERE status='Available'");
$kpi['total_stock_mtr'] = $r ? number_format((float)$r->fetch_assoc()['total'], 0) : 0;

$r = $db->query("SELECT IFNULL(SUM(selling_price),0) AS total FROM estimates WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$kpi['monthly_estimates_value'] = $r ? number_format((float)$r->fetch_assoc()['total'], 2) : '0.00';

// ── Recent Sales Orders ──────────────────────────────────────
$recentOrders = [];
$res = $db->query("SELECT id, order_no, client_name, status, selling_price, created_at FROM sales_orders ORDER BY id DESC LIMIT 8");
if ($res) {
    while ($row = $res->fetch_assoc()) $recentOrders[] = $row;
}

// ── Low Stock Alert ──────────────────────────────────────────
$lowStockCount = 0;
$r = $db->query("SELECT COUNT(*) AS c FROM paper_stock WHERE status = 'Available' AND length_mtr < 500");
if ($r) $lowStockCount = (int)$r->fetch_assoc()['c'];

$pageTitle = 'Dashboard';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="breadcrumb">
  <i class="bi bi-house"></i>
  <span class="breadcrumb-sep">›</span>
  <span>Dashboard</span>
</div>

<!-- Welcome -->
<div class="page-header">
  <div>
    <h1>Good <?= date('H') < 12 ? 'Morning' : (date('H') < 17 ? 'Afternoon' : 'Evening') ?>,
        <?= e(explode(' ', $_SESSION['user_name'] ?? 'User')[0]) ?> 👋</h1>
    <p>Here's what's happening at <?= APP_NAME ?> today, <?= date('l, d M Y') ?></p>
  </div>
</div>

<?php if ($lowStockCount > 0): ?>
<div class="alert alert-warning">
  <span><i class="bi bi-exclamation-triangle"></i> &nbsp;
    <strong><?= $lowStockCount ?> roll(s)</strong> have less than 500 MTR remaining. Review your paper stock.
  </span>
  <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-sm btn-amber">View Stock</a>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="stat-cards mb-24">
  <div class="stat-card">
    <div class="stat-icon green"><i class="bi bi-stack"></i></div>
    <div>
      <div class="stat-value"><?= $kpi['stock_available'] ?></div>
      <div class="stat-label">Rolls Available</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon teal"><i class="bi bi-rulers"></i></div>
    <div>
      <div class="stat-value"><?= $kpi['total_stock_mtr'] ?></div>
      <div class="stat-label">Total Stock Meters</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="bi bi-calculator"></i></div>
    <div>
      <div class="stat-value"><?= $kpi['estimates_active'] ?></div>
      <div class="stat-label">Active Estimates</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon amber"><i class="bi bi-bag-check"></i></div>
    <div>
      <div class="stat-value"><?= $kpi['orders_active'] ?></div>
      <div class="stat-label">Open Orders</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple"><i class="bi bi-kanban"></i></div>
    <div>
      <div class="stat-value"><?= $kpi['jobs_pending'] ?></div>
      <div class="stat-label">Jobs in Planning</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="bi bi-currency-rupee"></i></div>
    <div>
      <div class="stat-value">₹<?= $kpi['monthly_estimates_value'] ?></div>
      <div class="stat-label">Estimates This Month</div>
    </div>
  </div>
</div>

<div class="two-col">
  <!-- Recent Orders -->
  <div class="card mb-20">
    <div class="card-header">
      <span class="card-title"><i class="bi bi-bag-check"></i> Recent Sales Orders</span>
      <a href="<?= BASE_URL ?>/modules/sales_order/index.php" class="btn btn-sm btn-ghost">View All</a>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Order No</th>
            <th>Client</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentOrders)): ?>
          <tr><td colspan="5" class="table-empty">No orders yet.</td></tr>
          <?php else: ?>
          <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/modules/sales_order/view.php?id=<?= $o['id'] ?? '' ?>"
                   class="fw-600 text-blue"><?= e($o['order_no']) ?></a></td>
            <td><?= e($o['client_name']) ?></td>
            <td>₹<?= number_format((float)$o['selling_price'], 2) ?></td>
            <td><?= statusBadge($o['status']) ?></td>
            <td class="text-muted"><?= formatDate($o['created_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Quick Actions -->
  <div>
    <div class="card mb-20">
      <div class="card-header"><span class="card-title">Quick Actions</span></div>
      <div class="card-body">
        <div style="display:grid;gap:8px">
          <a href="<?= BASE_URL ?>/modules/paper_stock/add.php"  class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Paper Roll</a>
          <a href="<?= BASE_URL ?>/modules/estimate/add.php"     class="btn btn-blue"><i class="bi bi-calculator"></i> New Estimate</a>
          <a href="<?= BASE_URL ?>/modules/sales_order/add.php"  class="btn btn-amber"><i class="bi bi-bag-plus"></i> New Sales Order</a>
          <a href="<?= BASE_URL ?>/modules/planning/add.php"     class="btn btn-secondary"><i class="bi bi-kanban"></i> Add to Planning</a>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">System Info</span></div>
      <div class="card-body">
        <table style="font-size:.82rem">
          <tbody>
            <tr><td class="text-muted" style="padding:5px 0">PHP Version</td><td class="fw-600"><?= phpversion() ?></td></tr>
            <tr><td class="text-muted" style="padding:5px 0">MySQL</td><td class="fw-600"><?= $db->server_info ?></td></tr>
            <tr><td class="text-muted" style="padding:5px 0">App Version</td><td class="fw-600"><?= APP_VERSION ?></td></tr>
            <tr><td class="text-muted" style="padding:5px 0">Server Time</td><td class="fw-600"><?= date('d M Y H:i') ?></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
