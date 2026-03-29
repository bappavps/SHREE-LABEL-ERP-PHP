<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle   = 'Jumbo Slitting — Job Planning';
$db          = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';

$dateFilter = in_array($_GET['date_range'] ?? '', ['day','week','month','year']) ? $_GET['date_range'] : 'all';
$dateWhere  = match($dateFilter) {
    'day'   => "AND DATE(j.created_at) = CURDATE()",
    'week'  => "AND YEARWEEK(j.created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "AND YEAR(j.created_at) = YEAR(CURDATE()) AND MONTH(j.created_at) = MONTH(CURDATE())",
    'year'  => "AND YEAR(j.created_at) = YEAR(CURDATE())",
    default => ''
};

$rows = $db->query("
    SELECT j.*,
           ps.paper_type, ps.company AS supplier, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           p.job_name AS planning_job_name, p.status AS planning_status,
           p.priority AS planning_priority, p.machine, p.operator_name, p.scheduled_date
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    WHERE j.job_type = 'Slitting'
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
      {$dateWhere}
    ORDER BY j.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$total     = count($rows);
$pending   = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
$running   = count(array_filter($rows, fn($r) => $r['status'] === 'Running'));
$completed = count(array_filter($rows, fn($r) => in_array($r['status'], ['Completed','QC Passed','Closed','Finalized'])));
$hold      = count(array_filter($rows, fn($r) => str_contains(strtolower($r['status'] ?? ''), 'hold')));

$dateLabels = ['all'=>'All Time','day'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'];
$currentUrl = BASE_URL . '/modules/planning/slitting/index.php';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Planning</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Jumbo Slitting</span>
</div>

<style>
:root{--sl-brand:#0ea5a4}
.sl-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.sl-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.sl-header h1 i{font-size:1.6rem;color:var(--sl-brand)}
.sl-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.sl-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.sl-stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.sl-stat-val{font-size:1.4rem;font-weight:900;line-height:1}
.sl-stat-label{font-size:.62rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.sl-date-btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.sl-date-btn{padding:6px 16px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;color:#64748b;text-decoration:none;transition:all .15s}
.sl-date-btn.active{background:var(--sl-brand);border-color:var(--sl-brand);color:#fff}
.sl-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.sl-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:260px;outline:none;transition:border .15s}
.sl-search:focus{border-color:var(--sl-brand)}
.sl-tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.sl-tbl th{padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0;background:#f8fafc;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#64748b;white-space:nowrap}
.sl-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.sl-tbl tr:hover td{background:#fafcff}
.sl-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:12px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.sl-badge-pending{background:#fef3c7;color:#92400e}
.sl-badge-running{background:#dbeafe;color:#1e40af}
.sl-badge-completed,.sl-badge-qc,.sl-badge-closed,.sl-badge-finalized{background:#dcfce7;color:#166534}
.sl-badge-hold{background:#fee2e2;color:#991b1b}
.sl-badge-queued{background:#f1f5f9;color:#475569}
.sl-pri-urgent{background:#fee2e2;color:#991b1b;display:inline-flex;padding:2px 8px;border-radius:10px;font-size:.58rem;font-weight:800}
.sl-pri-high{background:#ffedd5;color:#9a3412;display:inline-flex;padding:2px 8px;border-radius:10px;font-size:.58rem;font-weight:800}
.sl-pri-normal{background:#e0f2fe;color:#075985;display:inline-flex;padding:2px 8px;border-radius:10px;font-size:.58rem;font-weight:800}
@media print{.no-print,.breadcrumb,.page-header{display:none!important}.sl-header .no-print{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:700px){.sl-stats{grid-template-columns:repeat(2,1fr)}.sl-controls{flex-direction:column;align-items:flex-start}}
</style>

<div class="sl-header no-print">
  <div>
    <h1><i class="bi bi-scissors"></i> Jumbo Slitting — Job Planning</h1>
    <div style="font-size:.75rem;color:#64748b;font-weight:600">All Jumbo Slitting job card details &middot; Filter by date range &middot; <?= $dateLabels[$dateFilter] ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="window.print()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--sl-brand);color:#fff;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-printer"></i> Print</button>
    <button onclick="location.reload()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<div class="sl-stats no-print">
  <div class="sl-stat">
    <div class="sl-stat-icon" style="background:#f0fdf4;color:#22c55e"><i class="bi bi-boxes"></i></div>
    <div><div class="sl-stat-val"><?= $total ?></div><div class="sl-stat-label">Total</div></div>
  </div>
  <div class="sl-stat">
    <div class="sl-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="sl-stat-val"><?= $pending ?></div><div class="sl-stat-label">Pending</div></div>
  </div>
  <div class="sl-stat">
    <div class="sl-stat-icon" style="background:#e0e7ff;color:#6366f1"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="sl-stat-val"><?= $running ?></div><div class="sl-stat-label">Running</div></div>
  </div>
  <div class="sl-stat">
    <div class="sl-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="sl-stat-val"><?= $completed ?></div><div class="sl-stat-label">Completed</div></div>
  </div>
  <div class="sl-stat">
    <div class="sl-stat-icon" style="background:#fee2e2;color:#dc2626"><i class="bi bi-pause-circle-fill"></i></div>
    <div><div class="sl-stat-val"><?= $hold ?></div><div class="sl-stat-label">On Hold</div></div>
  </div>
</div>

<div class="sl-date-btns no-print">
  <?php foreach ($dateLabels as $key => $label): ?>
    <a href="<?= $currentUrl ?>?date_range=<?= $key ?>" class="sl-date-btn <?= $dateFilter === $key ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="sl-controls no-print">
  <input type="text" class="sl-search" id="slSearch" placeholder="Search job no, roll, job name, supplier…" oninput="filterSlTable(this.value)">
  <span style="font-size:.72rem;color:#64748b;font-weight:700" id="slRowCount"><?= $total ?> records</span>
</div>

<div class="card">
  <div class="card-header no-print" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-table"></i> Jumbo Slitting Jobs — <?= htmlspecialchars($dateLabels[$dateFilter]) ?></span>
  </div>
  <!-- Print-only header visible only when printing -->
  <div style="display:none" class="print-only-header">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;border-bottom:2px solid #0ea5a4;padding-bottom:10px">
      <div>
        <div style="font-weight:900;font-size:1.1rem"><?= htmlspecialchars($companyName) ?></div>
        <div style="font-size:.75rem;color:#64748b"><?= htmlspecialchars($companyAddr) ?></div>
        <?php if ($companyGst): ?><div style="font-size:.65rem;color:#94a3b8">GST: <?= htmlspecialchars($companyGst) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-weight:900;font-size:1rem;color:#0ea5a4">Jumbo Slitting — Job Planning</div>
        <div style="font-size:.72rem;color:#64748b"><?= $dateLabels[$dateFilter] ?> &nbsp;|&nbsp; <?= $total ?> records &nbsp;|&nbsp; Printed: <?= date('d M Y H:i') ?></div>
      </div>
    </div>
  </div>
  <div style="overflow:auto">
    <table class="sl-tbl" id="slTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Job No</th>
          <th>Job Name</th>
          <th>Roll No</th>
          <th>Supplier / Material</th>
          <th>Width × Length</th>
          <th>GSM</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Operator</th>
          <th>Scheduled</th>
          <th>Started</th>
          <th>Completed</th>
          <th>Duration</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody id="slTbody">
      <?php if (empty($rows)): ?>
        <tr><td colspan="15" style="padding:24px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>No Jumbo Slitting jobs found for this period.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r):
          $sts = (string)($r['status'] ?? 'Pending');
          $stsCls = match(strtolower($sts)) {
            'pending'   => 'pending',
            'running'   => 'running',
            'completed' => 'completed',
            'qc passed' => 'qc',
            'closed'    => 'closed',
            'finalized' => 'finalized',
            default     => (str_contains(strtolower($sts), 'hold') ? 'hold' : 'queued')
          };
          $pri = (string)($r['planning_priority'] ?? 'Normal');
          $priCls = match(strtolower($pri)) { 'urgent'=>'sl-pri-urgent', 'high'=>'sl-pri-high', default=>'sl-pri-normal' };
          $dur = $r['duration_minutes'] ?? null;
          $durStr = ($dur !== null) ? (floor($dur/60).'h '.($dur%60).'m') : '—';
          $started   = $r['started_at']   ? date('d M Y<br>H:i', strtotime($r['started_at']))   : '—';
          $completed = $r['completed_at'] ? date('d M Y<br>H:i', strtotime($r['completed_at'])) : '—';
          $scheduled = $r['scheduled_date'] ? date('d M Y', strtotime($r['scheduled_date'])) : '—';
          $searchStr = strtolower(($r['job_no']??'').' '.($r['planning_job_name']??'').' '.($r['roll_no']??'').' '.($r['supplier']??'').' '.($r['paper_type']??'').' '.($r['operator_name']??''));
        ?>
        <tr data-search="<?= htmlspecialchars($searchStr, ENT_QUOTES) ?>">
          <td style="color:#94a3b8;font-weight:700"><?= $i+1 ?></td>
          <td style="font-weight:800;white-space:nowrap"><?php if (!empty($r['id']) && !empty($r['job_no'])): ?><a href="<?= BASE_URL ?>/modules/jobs/jumbo/index.php?auto_job=<?= (int)$r['id'] ?>" style="color:#0ea5a4;text-decoration:underline;text-underline-offset:3px" title="Open Job Card"><?= htmlspecialchars($r['job_no']) ?></a><?php else: ?><span style="color:#0ea5a4"><?= htmlspecialchars($r['job_no'] ?? '—') ?></span><?php endif; ?></td>
          <td style="max-width:160px"><?= htmlspecialchars($r['planning_job_name'] ?? '—') ?></td>
          <td style="font-weight:700;white-space:nowrap"><?= htmlspecialchars($r['roll_no'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['supplier'] ?? '—') ?><br><span style="font-size:.65rem;color:#94a3b8"><?= htmlspecialchars($r['paper_type'] ?? '') ?></span></td>
          <td style="white-space:nowrap"><?= htmlspecialchars(($r['width_mm']??'—').'mm × '.($r['length_mtr']??'—').'m') ?></td>
          <td><?= htmlspecialchars($r['gsm'] ?? '—') ?></td>
          <td><span class="sl-badge sl-badge-<?= $stsCls ?>"><?= htmlspecialchars($sts) ?></span></td>
          <td><span class="<?= $priCls ?>"><?= htmlspecialchars($pri) ?></span></td>
          <td style="white-space:nowrap"><?= htmlspecialchars($r['operator_name'] ?? '—') ?></td>
          <td style="white-space:nowrap"><?= $scheduled ?></td>
          <td style="white-space:nowrap;font-size:.72rem"><?= $started ?></td>
          <td style="white-space:nowrap;font-size:.72rem"><?= $completed ?></td>
          <td style="white-space:nowrap;font-weight:700;color:#16a34a"><?= $durStr ?></td>
          <td style="max-width:150px;font-size:.72rem;color:#64748b"><?= htmlspecialchars($r['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filterSlTable(q) {
  q = (q || '').toLowerCase();
  let visible = 0;
  document.querySelectorAll('#slTbody tr[data-search]').forEach(row => {
    const match = (row.dataset.search || '').includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const cnt = document.getElementById('slRowCount');
  if (cnt) cnt.textContent = visible + ' records';
}
</script>
<style>
@media print {
  .no-print { display: none !important; }
  .print-only-header { display: block !important; }
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
</style>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
