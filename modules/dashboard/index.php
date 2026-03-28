<?php
// ============================================================
// ERP System — Dashboard
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

$allowedPeriods = ['today', 'week', 'month', 'year', 'all'];
$period = strtolower((string)($_GET['period'] ?? 'month'));
if (!in_array($period, $allowedPeriods, true)) {
  $period = 'month';
}

function periodWhere(string $column, string $period): string {
  return match ($period) {
    'today' => "DATE($column) = CURDATE()",
    'week'  => "YEARWEEK($column, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "DATE_FORMAT($column, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'year'  => "YEAR($column) = YEAR(CURDATE())",
    default => '1=1',
  };
}

$periodLabels = [
  'today' => 'Today',
  'week'  => 'This Week',
  'month' => 'This Month',
  'year'  => 'This Year',
  'all'   => 'All Time',
];

$whereRollPeriod = periodWhere('created_at', $period);
$whereJobPeriod = periodWhere('created_at', $period);
$whereJobCompletedPeriod = periodWhere('completed_at', $period);

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

$r = $db->query("SELECT COUNT(*) AS c FROM paper_stock WHERE $whereRollPeriod");
$kpi['rolls_added_period'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM jobs WHERE $whereJobPeriod");
$kpi['jobs_created_period'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at IS NOT NULL AND $whereJobCompletedPeriod");
$kpi['jobs_completed_period'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) = 'running'");
$kpi['jobs_running_now'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

$r = $db->query("SELECT COUNT(*) AS c FROM paper_stock WHERE status='Available' AND length_mtr < 500");
$kpi['rolls_low_stock'] = $r ? (int)$r->fetch_assoc()['c'] : 0;

// ── Breakdown: Paper Roll Status ────────────────────────────
$paperStatusBreakdown = [];
$r = $db->query("SELECT COALESCE(NULLIF(TRIM(status),''),'Unknown') AS status_name, COUNT(*) AS c FROM paper_stock WHERE $whereRollPeriod GROUP BY status_name ORDER BY c DESC");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $paperStatusBreakdown[] = [
      'label' => (string)$row['status_name'],
      'count' => (int)$row['c'],
    ];
  }
}

// ── Breakdown: Job Status ───────────────────────────────────
$jobStatusBreakdown = [];
$r = $db->query("SELECT COALESCE(NULLIF(TRIM(status),''),'Unknown') AS status_name, COUNT(*) AS c FROM jobs WHERE $whereJobPeriod GROUP BY status_name ORDER BY c DESC");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $jobStatusBreakdown[] = [
      'label' => (string)$row['status_name'],
      'count' => (int)$row['c'],
    ];
  }
}

// ── Trend: last 6 months for rolls and jobs ────────────────
$trendStart = date('Y-m-01', strtotime('-5 months'));
$monthKeys = [];
$monthLabels = [];
for ($i = 5; $i >= 0; $i--) {
  $ts = strtotime("-$i months");
  $monthKeys[] = date('Y-m', $ts);
  $monthLabels[] = date('M', $ts);
}

$rollTrendMap = [];
$jobTrendMap = [];

$r = $db->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM paper_stock WHERE created_at >= '$trendStart' GROUP BY ym");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $rollTrendMap[(string)$row['ym']] = (int)$row['c'];
  }
}

$r = $db->query("SELECT DATE_FORMAT(completed_at, '%Y-%m') AS ym, COUNT(*) AS c FROM jobs WHERE completed_at IS NOT NULL AND LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at >= '$trendStart' GROUP BY ym");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $jobTrendMap[(string)$row['ym']] = (int)$row['c'];
  }
}

$rollTrend = [];
$jobTrend = [];
foreach ($monthKeys as $mk) {
  $rollTrend[] = $rollTrendMap[$mk] ?? 0;
  $jobTrend[] = $jobTrendMap[$mk] ?? 0;
}

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

<style>
.db-focus-card{background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0;border-radius:14px;padding:16px 18px;margin-bottom:16px;box-shadow:0 12px 24px rgba(15,23,42,.18)}
.db-focus-top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.db-focus-title{font-size:.86rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#cbd5e1}
.db-period{display:flex;gap:7px;flex-wrap:wrap}
.db-period-btn{padding:6px 12px;border-radius:999px;border:1px solid rgba(148,163,184,.35);font-size:.68rem;font-weight:800;text-transform:uppercase;color:#cbd5e1;text-decoration:none;transition:all .14s;background:rgba(255,255,255,.03)}
.db-period-btn:hover{background:rgba(255,255,255,.12);color:#fff}
.db-period-btn.active{background:#22c55e;border-color:#22c55e;color:#052e16}
.db-focus-kpi{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:10px;margin-top:14px}
.db-mini{background:rgba(255,255,255,.06);border:1px solid rgba(148,163,184,.2);padding:10px 12px;border-radius:10px}
.db-mini-v{font-size:1.25rem;font-weight:900;line-height:1;color:#fff}
.db-mini-l{font-size:.62rem;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;font-weight:800;margin-top:3px}
.db-grid2{display:grid;grid-template-columns:1.1fr 1.1fr 1.3fr;gap:14px;margin-bottom:20px}
.db-block{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;padding:14px}
.db-block h3{margin:0 0 10px;font-size:.84rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.db-list{display:grid;gap:8px}
.db-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
.db-row-top{display:flex;justify-content:space-between;align-items:center;gap:8px}
.db-label{font-size:.72rem;color:#334155;font-weight:700}
.db-num{font-size:.72rem;color:#0f172a;font-weight:900}
.db-bar{height:8px;border-radius:999px;background:#eef2ff;overflow:hidden}
.db-fill{height:100%;border-radius:999px}
.db-trend{display:grid;grid-template-columns:repeat(6,minmax(36px,1fr));gap:8px;align-items:end;height:160px;margin-top:8px}
.db-col{display:flex;flex-direction:column;align-items:center;gap:6px}
.db-stack{width:100%;display:flex;flex-direction:column;justify-content:flex-end;gap:3px;height:120px}
.db-roll{background:#22c55e;border-radius:6px 6px 3px 3px}
.db-job{background:#3b82f6;border-radius:3px 3px 6px 6px}
.db-col-l{font-size:.62rem;color:#64748b;font-weight:800;text-transform:uppercase}
.db-legend{display:flex;gap:12px;margin-top:8px;font-size:.67rem;color:#64748b;font-weight:700;flex-wrap:wrap}
.db-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px}
@media (max-width:1100px){.db-grid2{grid-template-columns:1fr 1fr}.db-grid2 .db-block:last-child{grid-column:1/-1}}
@media (max-width:760px){.db-focus-kpi{grid-template-columns:repeat(2,minmax(120px,1fr))}.db-grid2{grid-template-columns:1fr}}
</style>

<?php
$paperTotal = array_sum(array_column($paperStatusBreakdown, 'count'));
$jobTotal = array_sum(array_column($jobStatusBreakdown, 'count'));
$trendMax = max(1, (int)max(array_merge($rollTrend, $jobTrend)));
$barPalette = ['#16a34a','#0ea5e9','#f59e0b','#8b5cf6','#ef4444','#14b8a6','#64748b'];
?>

<div class="db-focus-card">
  <div class="db-focus-top">
    <div>
      <div class="db-focus-title">Dashboard Focus</div>
      <div style="font-size:1rem;font-weight:900;color:#fff">Paper Rolls & Job Status</div>
      <div style="font-size:.72rem;color:#94a3b8">Showing data for <strong style="color:#e2e8f0"><?= e($periodLabels[$period] ?? 'This Month') ?></strong></div>
    </div>
    <div class="db-period">
      <?php foreach ($allowedPeriods as $p): ?>
        <a href="?period=<?= e($p) ?>" class="db-period-btn <?= $period === $p ? 'active' : '' ?>"><?= e($periodLabels[$p]) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="db-focus-kpi">
    <div class="db-mini"><div class="db-mini-v"><?= $kpi['rolls_added_period'] ?></div><div class="db-mini-l">Rolls Added</div></div>
    <div class="db-mini"><div class="db-mini-v"><?= $kpi['jobs_created_period'] ?></div><div class="db-mini-l">Jobs Created</div></div>
    <div class="db-mini"><div class="db-mini-v"><?= $kpi['jobs_completed_period'] ?></div><div class="db-mini-l">Jobs Completed</div></div>
    <div class="db-mini"><div class="db-mini-v"><?= $kpi['jobs_running_now'] ?></div><div class="db-mini-l">Jobs Running</div></div>
    <div class="db-mini"><div class="db-mini-v"><?= $kpi['rolls_low_stock'] ?></div><div class="db-mini-l">Low Stock Rolls</div></div>
  </div>
</div>

<div class="db-grid2">
  <div class="db-block">
    <h3><i class="bi bi-distribute-vertical"></i> Paper Roll Status Mix</h3>
    <div class="db-list">
      <?php if (empty($paperStatusBreakdown)): ?>
        <div class="text-muted" style="font-size:.8rem">No paper roll data found for selected period.</div>
      <?php else: ?>
        <?php foreach ($paperStatusBreakdown as $i => $item):
          $pct = $paperTotal > 0 ? round(($item['count'] / $paperTotal) * 100, 1) : 0;
          $color = $barPalette[$i % count($barPalette)];
        ?>
          <div class="db-row">
            <div>
              <div class="db-row-top"><span class="db-label"><?= e($item['label']) ?></span><span class="db-num"><?= (int)$item['count'] ?> (<?= $pct ?>%)</span></div>
              <div class="db-bar"><div class="db-fill" style="width:<?= $pct ?>%;background:<?= e($color) ?>"></div></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="db-block">
    <h3><i class="bi bi-kanban"></i> Job Status Distribution</h3>
    <div class="db-list">
      <?php if (empty($jobStatusBreakdown)): ?>
        <div class="text-muted" style="font-size:.8rem">No job data found for selected period.</div>
      <?php else: ?>
        <?php foreach ($jobStatusBreakdown as $i => $item):
          $pct = $jobTotal > 0 ? round(($item['count'] / $jobTotal) * 100, 1) : 0;
          $color = $barPalette[$i % count($barPalette)];
        ?>
          <div class="db-row">
            <div>
              <div class="db-row-top"><span class="db-label"><?= e($item['label']) ?></span><span class="db-num"><?= (int)$item['count'] ?> (<?= $pct ?>%)</span></div>
              <div class="db-bar"><div class="db-fill" style="width:<?= $pct ?>%;background:<?= e($color) ?>"></div></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="db-block">
    <h3><i class="bi bi-bar-chart-line"></i> 6-Month Trend</h3>
    <div class="db-trend">
      <?php foreach ($monthLabels as $i => $ml):
        $rollVal = (int)($rollTrend[$i] ?? 0);
        $jobVal = (int)($jobTrend[$i] ?? 0);
        $rollH = max(4, (int)round(($rollVal / $trendMax) * 90));
        $jobH = max(4, (int)round(($jobVal / $trendMax) * 90));
      ?>
        <div class="db-col">
          <div class="db-stack">
            <div class="db-roll" style="height:<?= $rollH ?>px" title="Rolls: <?= $rollVal ?>"></div>
            <div class="db-job" style="height:<?= $jobH ?>px" title="Jobs: <?= $jobVal ?>"></div>
          </div>
          <div class="db-col-l"><?= e($ml) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="db-legend">
      <span><span class="db-dot" style="background:#22c55e"></span> Rolls Added</span>
      <span><span class="db-dot" style="background:#3b82f6"></span> Jobs Completed</span>
    </div>
  </div>
</div>

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


  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
