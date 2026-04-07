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
$whereOrderPeriod = periodWhere('created_at', $period);

function safeQuery(mysqli $db, string $sql) {
  try {
    return $db->query($sql);
  } catch (Throwable $e) {
    return false;
  }
}

function safeValue($result, string $key, $default = 0) {
  if (!$result) {
    return $default;
  }
  $row = $result->fetch_assoc();
  return $row[$key] ?? $default;
}

// ── KPI Queries ──────────────────────────────────────────────
$kpi = [];

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')");
$kpi['stock_available'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM estimates WHERE LOWER(COALESCE(status,'')) NOT IN ('rejected','converted','cancelled')");
$kpi['estimates_active'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM sales_orders WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','dispatched','cancelled','closed')");
$kpi['orders_active'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM planning WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','closed','finalized','cancelled','done')");
$kpi['jobs_pending'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT IFNULL(SUM(length_mtr),0) AS total FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')");
$kpi['total_stock_mtr'] = number_format((float)safeValue($r, 'total', 0), 0);

$r = safeQuery($db, "SELECT IFNULL(SUM(selling_price),0) AS total FROM estimates WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$kpi['monthly_estimates_value'] = number_format((float)safeValue($r, 'total', 0), 2);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM paper_stock WHERE $whereRollPeriod");
$kpi['rolls_added_period'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM jobs WHERE $whereJobPeriod");
$kpi['jobs_created_period'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) IN ('pending','queued') AND $whereJobPeriod");
$kpi['jobs_pending_period'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at IS NOT NULL AND $whereJobCompletedPeriod");
$kpi['jobs_completed_period'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM jobs WHERE LOWER(status) = 'running'");
$kpi['jobs_running_now'] = (int)safeValue($r, 'c', 0);

$r = safeQuery($db, "SELECT COUNT(*) AS c FROM paper_stock WHERE status='Available' AND length_mtr < 500");
$kpi['rolls_low_stock'] = (int)safeValue($r, 'c', 0);

// ── Breakdown: Paper Roll Status ────────────────────────────
$paperStatusBreakdown = [];
$r = safeQuery($db, "SELECT COALESCE(NULLIF(TRIM(status),''),'Unknown') AS status_name, COUNT(*) AS c FROM paper_stock WHERE $whereRollPeriod GROUP BY status_name ORDER BY c DESC");
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
$r = safeQuery($db, "SELECT COALESCE(NULLIF(TRIM(status),''),'Unknown') AS status_name, COUNT(*) AS c FROM jobs WHERE $whereJobPeriod GROUP BY status_name ORDER BY c DESC");
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

$r = safeQuery($db, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM paper_stock WHERE created_at >= '$trendStart' GROUP BY ym");
if ($r) {
  while ($row = $r->fetch_assoc()) {
    $rollTrendMap[(string)$row['ym']] = (int)$row['c'];
  }
}

$r = safeQuery($db, "SELECT DATE_FORMAT(completed_at, '%Y-%m') AS ym, COUNT(*) AS c FROM jobs WHERE completed_at IS NOT NULL AND LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at >= '$trendStart' GROUP BY ym");
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
$res = safeQuery($db, "SELECT id, order_no, client_name, status, selling_price, created_at FROM sales_orders ORDER BY id DESC LIMIT 8");
if ($res) {
    while ($row = $res->fetch_assoc()) $recentOrders[] = $row;
}

// ── Recent Production Jobs ──────────────────────────────────
$recentProductionJobs = [];
$res = safeQuery($db, "
  SELECT
    j.id,
    j.job_no,
    j.department,
    j.job_type,
    j.status,
    j.created_at,
    j.updated_at,
    j.completed_at,
    p.job_name,
    p.job_no AS planning_no
  FROM jobs j
  LEFT JOIN planning p ON p.id = j.planning_id
  WHERE (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
  ORDER BY j.id DESC
  LIMIT 10
");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $recentProductionJobs[] = $row;
  }
}

// ── Planning Pipeline by Department ─────────────────────────
$planningByDepartment = [];
$res = safeQuery($db, "
  SELECT
    COALESCE(NULLIF(TRIM(department), ''), 'unassigned') AS department_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('pending','queued','preparing slitting','in progress','on hold') THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('completed','closed','finalized','done') THEN 1 ELSE 0 END) AS done_count
  FROM planning
  WHERE 1=1
  GROUP BY department_name
  ORDER BY total_count DESC
  LIMIT 12
");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $planningByDepartment[] = [
      'department' => (string)$row['department_name'],
      'total' => (int)$row['total_count'],
      'open' => (int)$row['open_count'],
      'done' => (int)$row['done_count'],
    ];
  }
}

// ── Department Workload (jobs) ──────────────────────────────
$jobWorkloadByDepartment = [];
$res = safeQuery($db, "
  SELECT
    COALESCE(NULLIF(TRIM(department), ''), 'unassigned') AS department_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN LOWER(COALESCE(status,'')) = 'running' THEN 1 ELSE 0 END) AS running_count,
    SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('pending','queued') THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('completed','closed','finalized','qc passed') THEN 1 ELSE 0 END) AS completed_count
  FROM jobs
  WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
  GROUP BY department_name
  ORDER BY running_count DESC, pending_count DESC, total_count DESC
  LIMIT 10
");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $jobWorkloadByDepartment[] = [
      'department' => (string)$row['department_name'],
      'total' => (int)$row['total_count'],
      'running' => (int)$row['running_count'],
      'pending' => (int)$row['pending_count'],
      'completed' => (int)$row['completed_count'],
    ];
  }
}

// ── Top Clients by Order Value (period based) ───────────────
$topClients = [];
$res = safeQuery($db, "
  SELECT
    COALESCE(NULLIF(TRIM(client_name), ''), 'Unknown') AS client_name,
    COUNT(*) AS total_orders,
    IFNULL(SUM(selling_price), 0) AS total_value
  FROM sales_orders
  WHERE $whereOrderPeriod
  GROUP BY client_name
  ORDER BY total_value DESC
  LIMIT 8
");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $topClients[] = [
      'client_name' => (string)$row['client_name'],
      'orders' => (int)$row['total_orders'],
      'value' => (float)$row['total_value'],
    ];
  }
}

// ── Low Stock Alert ──────────────────────────────────────────
$lowStockCount = 0;
$r = safeQuery($db, "SELECT COUNT(*) AS c FROM paper_stock WHERE status = 'Available' AND length_mtr < 500");
$lowStockCount = (int)safeValue($r, 'c', 0);

$canUseQrScanner = function_exists('canAccessPath')
  ? canAccessPath('/modules/dashboard/index.php')
  : false;

$pageTitle = 'Dashboard';
include __DIR__ . '/../../includes/header.php';
$dashboardBrand = function_exists('getErpDisplayName') ? getErpDisplayName() : APP_NAME;
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
    <p>Here's what's happening at <?= e($dashboardBrand) ?> today, <?= date('l, d M Y') ?></p>
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
.db-grid2 .db-block:nth-child(1){background:linear-gradient(145deg,#f0fdf4 0%,#ecfeff 100%);border-color:#bbf7d0}
.db-grid2 .db-block:nth-child(2){background:linear-gradient(145deg,#eff6ff 0%,#eef2ff 100%);border-color:#bfdbfe}
.db-grid2 .db-block:nth-child(3){background:linear-gradient(145deg,#fffbeb 0%,#fff7ed 100%);border-color:#fde68a}
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
.db-detail-grid{display:grid;grid-template-columns:1.2fr .95fr .95fr;gap:14px;margin-bottom:20px}
.db-detail-block{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.db-detail-grid .db-detail-block:nth-child(1){background:linear-gradient(165deg,#f8fafc 0%,#eef2ff 100%);border-color:#c7d2fe}
.db-detail-grid .db-detail-block:nth-child(2){background:linear-gradient(165deg,#f0fdf4 0%,#ecfeff 100%);border-color:#99f6e4}
.db-detail-grid .db-detail-block:nth-child(3){background:linear-gradient(165deg,#fff7ed 0%,#fef2f2 100%);border-color:#fdba74}
.db-detail-head{padding:12px 14px;border-bottom:1px solid rgba(148,163,184,.25);display:flex;justify-content:space-between;align-items:center;gap:10px}
.db-detail-head h4{margin:0;font-size:.82rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.db-detail-body{padding:0 12px 10px}
.db-mini-table{width:100%;border-collapse:collapse}
.db-mini-table th,.db-mini-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;font-size:.74rem;vertical-align:top;text-align:left}
.db-mini-table th{font-size:.64rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.db-chip{display:inline-block;padding:2px 7px;border-radius:999px;font-size:.63rem;font-weight:800}
.db-chip.running{background:#dbeafe;color:#1e3a8a}
.db-chip.pending{background:#fff7ed;color:#b45309}
.db-chip.done{background:#dcfce7;color:#166534}
.db-chip.hold{background:#fee2e2;color:#991b1b}
.db-subtle{font-size:.67rem;color:#64748b;font-weight:700}
.db-client-item{display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9}
.db-client-item:last-child{border-bottom:none}
.db-client-name{font-size:.75rem;color:#0f172a;font-weight:700}
.db-client-meta{font-size:.67rem;color:#64748b}
.db-live-lite-card{border:1px solid #bae6fd;background:linear-gradient(150deg,#ecfeff 0%,#f0f9ff 100%)}
.db-live-lite-link{display:block;padding:14px;text-decoration:none;color:inherit}
.db-live-lite-link:hover{background:rgba(255,255,255,.42)}
.db-live-lite-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}
.db-live-lite-title{font-size:.83rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.db-live-lite-chip{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;background:#0ea5e9;color:#fff;padding:3px 8px;border-radius:999px}
.db-live-lite-grid{display:grid;grid-template-columns:repeat(4,minmax(70px,1fr));gap:8px;margin-bottom:10px}
.db-live-lite-kpi{background:#fff;border:1px solid #dbeafe;border-radius:10px;padding:8px 9px}
.db-live-lite-v{font-size:1rem;font-weight:900;line-height:1;color:#0f172a}
.db-live-lite-l{font-size:.62rem;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-top:3px}
.db-live-lite-list{display:grid;gap:6px}
.db-live-lite-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;background:rgba(255,255,255,.7);border:1px dashed #cbd5e1;border-radius:8px;padding:6px 8px}
.db-live-lite-row-job{font-size:.72rem;font-weight:800;color:#0f172a}
.db-live-lite-row-meta{font-size:.64rem;color:#64748b}
.db-live-lite-cta{margin-top:10px;font-size:.72rem;font-weight:800;color:#0369a1;display:flex;align-items:center;gap:6px}
.two-col{display:grid;grid-template-columns:1.35fr 1fr;gap:14px;align-items:start}
.card-header{flex-wrap:wrap}
.db-detail-body{overflow-x:auto}
.db-mini-table{min-width:500px}
.db-trend{overflow-x:auto;padding-bottom:4px}
.two-col > .card:first-child{background:linear-gradient(160deg,#f8fafc 0%,#f0f9ff 100%);border-color:#cbd5e1}
.two-col > div > .card.mb-20:first-child{background:linear-gradient(160deg,#faf5ff 0%,#eef2ff 100%);border-color:#ddd6fe}
.two-col > div > .card.mb-20:last-child{background:linear-gradient(160deg,#f0fdf4 0%,#ecfccb 100%);border-color:#bef264}
@media (max-width:1100px){.db-grid2{grid-template-columns:1fr 1fr}.db-grid2 .db-block:last-child{grid-column:1/-1}}
@media (max-width:1100px){.db-detail-grid{grid-template-columns:1fr 1fr}.db-detail-grid .db-detail-block:first-child{grid-column:1/-1}}
@media (max-width:980px){
  .two-col{grid-template-columns:1fr}
}
@media (max-width:760px){
  .db-focus-card{padding:14px}
  .db-focus-top{align-items:flex-start}
  .db-focus-kpi{grid-template-columns:repeat(2,minmax(120px,1fr))}
  .db-grid2{grid-template-columns:1fr}
  .db-detail-grid{grid-template-columns:1fr}
  .db-live-lite-grid{grid-template-columns:repeat(2,minmax(70px,1fr))}
  .db-period{display:grid;grid-template-columns:repeat(3,minmax(80px,1fr));width:100%}
  .db-period-btn{text-align:center;padding:7px 8px;font-size:.64rem}
  .db-row-top{flex-direction:column;align-items:flex-start}
  .db-label,.db-num{font-size:.7rem}
  .db-mini-table{min-width:460px}
}
@media (max-width:480px){
  .db-focus-kpi{grid-template-columns:1fr}
  .db-live-lite-grid{grid-template-columns:1fr 1fr}
  .db-mini-table{min-width:420px}
  .db-live-lite-link{padding:12px}
  .db-detail-head h4{font-size:.76rem}
}
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

<div class="db-detail-grid">
  <div class="db-detail-block">
    <div class="db-detail-head">
      <h4><i class="bi bi-list-task"></i> Recent Production Jobs</h4>
      <a href="<?= BASE_URL ?>/modules/production-manager/index.php" class="btn btn-sm btn-ghost">Open Summary</a>
    </div>
    <div class="db-detail-body">
      <table class="db-mini-table">
        <thead>
          <tr>
            <th>Job No</th>
            <th>Planning</th>
            <th>Department</th>
            <th>Status</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentProductionJobs)): ?>
          <tr><td colspan="5" class="text-muted">No production jobs found.</td></tr>
          <?php else: ?>
          <?php foreach ($recentProductionJobs as $j): ?>
          <?php
            $jobStatus = strtolower(trim((string)($j['status'] ?? '')));
            $statusClass = 'pending';
            if ($jobStatus === 'running') $statusClass = 'running';
            elseif (in_array($jobStatus, ['completed','closed','finalized','qc passed'], true)) $statusClass = 'done';
            elseif (strpos($jobStatus, 'hold') !== false || strpos($jobStatus, 'pause') !== false) $statusClass = 'hold';
          ?>
          <tr>
            <td><strong><?= e($j['job_no'] ?? '-') ?></strong></td>
            <td>
              <?= e((string)($j['planning_no'] ?? '-')) ?>
              <div class="db-subtle"><?= e((string)($j['job_name'] ?? '-')) ?></div>
            </td>
            <td><?= e((string)($j['department'] ?: $j['job_type'] ?: '-')) ?></td>
            <td><span class="db-chip <?= e($statusClass) ?>"><?= e((string)($j['status'] ?? '-')) ?></span></td>
            <td><?= e(formatDate((string)($j['updated_at'] ?? $j['created_at'] ?? ''))) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="db-detail-block">
    <div class="db-detail-head">
      <h4><i class="bi bi-diagram-3"></i> Planning by Department</h4>
    </div>
    <div class="db-detail-body">
      <table class="db-mini-table">
        <thead>
          <tr>
            <th>Department</th>
            <th>Total</th>
            <th>Open</th>
            <th>Done</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($planningByDepartment)): ?>
          <tr><td colspan="4" class="text-muted">No planning department data found.</td></tr>
          <?php else: ?>
          <?php foreach ($planningByDepartment as $pd): ?>
          <tr>
            <td><?= e(ucwords(str_replace(['-', '_'], ' ', (string)$pd['department']))) ?></td>
            <td><strong><?= (int)$pd['total'] ?></strong></td>
            <td><?= (int)$pd['open'] ?></td>
            <td><?= (int)$pd['done'] ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="db-detail-block">
    <div class="db-detail-head">
      <h4><i class="bi bi-people"></i> Top Clients (<?= e($periodLabels[$period] ?? 'Period') ?>)</h4>
    </div>
    <div class="db-detail-body">
      <?php if (empty($topClients)): ?>
        <div class="text-muted" style="font-size:.8rem;padding:8px 0">No client order data for selected period.</div>
      <?php else: ?>
        <?php foreach ($topClients as $c): ?>
          <div class="db-client-item">
            <div>
              <div class="db-client-name"><?= e($c['client_name']) ?></div>
              <div class="db-client-meta">Orders: <?= (int)$c['orders'] ?></div>
            </div>
            <div class="db-client-name">₹<?= number_format((float)$c['value'], 2) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <hr style="border:none;border-top:1px dashed #e2e8f0;margin:10px 0">

      <table class="db-mini-table" style="margin-top:4px">
        <thead>
          <tr>
            <th>Department</th>
            <th>Run</th>
            <th>Pend</th>
            <th>Done</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobWorkloadByDepartment)): ?>
          <tr><td colspan="4" class="text-muted">No workload data found.</td></tr>
          <?php else: ?>
          <?php foreach ($jobWorkloadByDepartment as $wd): ?>
          <tr>
            <td><?= e(ucwords(str_replace(['-', '_'], ' ', (string)$wd['department']))) ?></td>
            <td><?= (int)$wd['running'] ?></td>
            <td><?= (int)$wd['pending'] ?></td>
            <td><?= (int)$wd['completed'] ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
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

  <!-- Quick Actions + QR Scanner -->
  <div>

    <!-- Live Floor Lite -->
    <div class="card mb-20 db-live-lite-card">
      <a href="<?= BASE_URL ?>/modules/live/index.php" class="db-live-lite-link" title="Open Live Floor details">
        <div class="db-live-lite-head">
          <div class="db-live-lite-title"><i class="bi bi-broadcast"></i> Live Floor Lite</div>
          <span class="db-live-lite-chip">Quick View</span>
        </div>

        <div class="db-live-lite-grid">
          <div class="db-live-lite-kpi"><div class="db-live-lite-v"><?= (int)$kpi['jobs_running_now'] ?></div><div class="db-live-lite-l">Running</div></div>
          <div class="db-live-lite-kpi"><div class="db-live-lite-v"><?= (int)$kpi['jobs_pending_period'] ?></div><div class="db-live-lite-l">Pending</div></div>
          <div class="db-live-lite-kpi"><div class="db-live-lite-v"><?= (int)$kpi['jobs_completed_period'] ?></div><div class="db-live-lite-l">Completed</div></div>
          <div class="db-live-lite-kpi"><div class="db-live-lite-v"><?= (int)$kpi['jobs_created_period'] ?></div><div class="db-live-lite-l">Created</div></div>
        </div>

        <div class="db-live-lite-list">
          <?php if (empty($recentProductionJobs)): ?>
            <div class="db-live-lite-row"><div class="db-live-lite-row-job">No active production jobs</div><div class="db-live-lite-row-meta">Live Floor</div></div>
          <?php else: ?>
            <?php foreach (array_slice($recentProductionJobs, 0, 3) as $jp): ?>
              <div class="db-live-lite-row">
                <div>
                  <div class="db-live-lite-row-job"><?= e((string)($jp['job_no'] ?? '-')) ?></div>
                  <div class="db-live-lite-row-meta"><?= e((string)($jp['department'] ?: $jp['job_type'] ?: '-')) ?></div>
                </div>
                <div class="db-live-lite-row-meta"><?= e((string)($jp['status'] ?? '-')) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="db-live-lite-cta">
          <i class="bi bi-arrow-right-circle"></i>
          Click to open full Live Floor details
        </div>
      </a>
    </div>

    <!-- ERP QR Scanner -->
    <style>
    .db-qr-type-badge{display:inline-block;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;padding:2px 8px;border-radius:999px;margin-bottom:7px}
    .db-qr-type-roll{background:#d1fae5;color:#065f46}
    .db-qr-type-job{background:#dbeafe;color:#1e40af}
    .db-qr-type-slitting{background:#ede9fe;color:#5b21b6}
    .db-qr-type-url{background:#f1f5f9;color:#475569}
    .db-qr-result-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px}
    .db-qr-result-err{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px}
    .db-qr-result-denied{background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:12px 14px}
    .db-qr-label{font-size:.82rem;font-weight:700;color:#0f172a;margin-bottom:10px;line-height:1.4}
    .db-qr-go{display:block;width:100%;padding:10px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:800;cursor:pointer;text-align:center;text-decoration:none;box-sizing:border-box}
    .db-qr-go:hover{background:#6d28d9;color:#fff}
    #db-qr-toggle{background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:.75rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px}
    #db-qr-viewport{display:none;background:#000;overflow:hidden}
    #db-qr-viewport.open{display:block}
    #db-qr-reader{background:#000;padding:0}
    #db-qr-reader video{display:block;width:100% !important;height:auto !important;max-height:none !important;object-fit:cover;background:#000}
    #db-qr-reader #qr-shaded-region{margin:0 auto !important}
    </style>
    <div class="card mb-20">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span class="card-title"><i class="bi bi-qr-code-scan" style="color:#7c3aed"></i> ERP QR Scanner</span>
        <button id="db-qr-toggle" onclick="dbQrToggle()" <?= $canUseQrScanner ? '' : 'disabled title="No scanner permission" style="opacity:.5;cursor:not-allowed"' ?>>
          <i class="bi bi-camera-video" id="db-qr-icon"></i>
          <span id="db-qr-btn-text"><?= $canUseQrScanner ? 'Tap to Scan' : 'Permission Required' ?></span>
        </button>
      </div>
      <div id="db-qr-viewport"><div id="db-qr-reader" style="width:100%"></div></div>
      <div class="card-body">
        <div id="db-qr-result" style="display:none"></div>
        <?php if (!$canUseQrScanner): ?>
        <div class="db-qr-result-denied">
          <div style="display:flex;align-items:center;gap:8px;color:#c2410c;font-weight:700;margin-bottom:8px"><i class="bi bi-shield-lock-fill"></i> Scanner Access Restricted</div>
          <div style="font-size:.8rem;color:#7c2d12">Your user group does not have permission for QR scanning. Ask admin to enable <strong>Physical Stock Scan Terminal</strong> in Group Permissions.</div>
        </div>
        <?php endif; ?>
        <div id="db-qr-idle" style="text-align:center;padding:14px 0;color:#94a3b8">
          <i class="bi bi-qr-code" style="font-size:2.2rem;opacity:.25;display:block;margin-bottom:8px"></i>
          <div style="font-size:.78rem">Tap <strong>Tap to Scan</strong> and point camera at any ERP QR code<br><span style="font-size:.7rem;opacity:.7">Roll labels &nbsp;·&nbsp; Job cards &nbsp;·&nbsp; Slitting sheets</span></div>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
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

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function(){
  'use strict';
  var RESOLVE = '<?= BASE_URL ?>/modules/scan/index.php?action=resolve';
  var SCAN_ALLOWED = <?= $canUseQrScanner ? 'true' : 'false' ?>;
  var scanner  = null;
  var active   = false;
  var cooldown = false;

  window.dbQrToggle = function(){
    if (!SCAN_ALLOWED) {
      showResult({ok: false, error: 'Access denied. Your group does not have scanner permission.'});
      return;
    }
    active ? stopScan() : startScan();
  };

  function startScan(){
    document.getElementById('db-qr-viewport').classList.add('open');
    document.getElementById('db-qr-idle').style.display   = 'none';
    document.getElementById('db-qr-result').style.display = 'none';
    document.getElementById('db-qr-btn-text').textContent  = 'Stop Camera';
    document.getElementById('db-qr-icon').className        = 'bi bi-stop-circle';
    document.getElementById('db-qr-toggle').style.background = '#ef4444';
    active = true;

    scanner = new Html5QrcodeScanner('db-qr-reader', {
      fps: 15,
      qrbox: {width: 240, height: 240},
      rememberLastUsedCamera: false
    }, false);
    scanner.render(function(text){
      if (cooldown) return;
      cooldown = true;
      setTimeout(function(){ cooldown = false; }, 3000);
      resolveQr(text);
    }, function(){});
  }

  function stopScan(){
    if (scanner){ try{ scanner.clear(); }catch(e){} scanner = null; }
    active = false;
    document.getElementById('db-qr-viewport').classList.remove('open');
    document.getElementById('db-qr-btn-text').textContent    = 'Tap to Scan';
    document.getElementById('db-qr-icon').className          = 'bi bi-camera-video';
    document.getElementById('db-qr-toggle').style.background = '#7c3aed';
  }

  function resolveQr(text){
    var fd = new FormData();
    fd.append('qr', text);
    fetch(RESOLVE, {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(res){ stopScan(); showResult(res); })
      .catch(function(){ stopScan(); showResult({ok: false, error: 'Network error.'}); });
  }

  function showResult(res){
    var box  = document.getElementById('db-qr-result');
    var idle = document.getElementById('db-qr-idle');
    idle.style.display = 'none';
    box.style.display  = '';

    if (res.ok) {
      var typeLabel = {roll:'Paper Roll', job:'Job Card', slitting:'Slitting', url:'ERP Page'}[res.type] || res.type;
      var typeCls   = {roll:'db-qr-type-roll', job:'db-qr-type-job', slitting:'db-qr-type-slitting', url:'db-qr-type-url'}[res.type] || 'db-qr-type-url';
      box.innerHTML =
        '<div class="db-qr-result-ok">'
        + '<span class="db-qr-type-badge ' + typeCls + '">' + esc(typeLabel) + '</span>'
        + '<div class="db-qr-label">' + esc(res.label || '') + '</div>'
        + '<a href="' + esc(res.url) + '" class="db-qr-go"><i class="bi bi-arrow-right-circle"></i> Open Page</a>'
        + '</div>'
        + '<div style="text-align:center;margin-top:10px">'
        + '<button onclick="dbQrReset()" style="background:none;border:none;color:#64748b;font-size:.75rem;cursor:pointer;font-weight:700"><i class="bi bi-arrow-repeat"></i> Scan Another</button>'
        + '</div>';
    } else {
      box.innerHTML =
        '<div class="db-qr-result-err">'
        + '<div style="display:flex;align-items:center;gap:8px;color:#dc2626;font-weight:700;margin-bottom:8px"><i class="bi bi-x-circle-fill"></i> Not Recognised</div>'
        + '<div style="font-size:.8rem;color:#64748b;margin-bottom:10px">' + esc(res.error || 'Unknown error') + '</div>'
        + '<button onclick="dbQrToggle()" style="background:#f1f5f9;border:none;border-radius:8px;padding:8px 14px;font-size:.78rem;font-weight:700;cursor:pointer;width:100%"><i class="bi bi-arrow-repeat"></i> Try Again</button>'
        + '</div>';
    }
  }

  window.dbQrReset = function(){
    document.getElementById('db-qr-result').style.display = 'none';
    document.getElementById('db-qr-idle').style.display   = '';
  };

  function esc(s){ var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
