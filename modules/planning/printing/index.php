<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle   = 'Flexo Printing — Job Planning';
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
           p.priority AS planning_priority, p.machine, p.operator_name, p.scheduled_date,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE j.job_type = 'Printing'
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
      {$dateWhere}
    ORDER BY j.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Parse extra_data for each row
foreach ($rows as &$r) {
    $extra = json_decode((string)($r['extra_data'] ?? '{}'), true) ?: [];
    $r['mkd_job_sl_no'] = trim((string)($extra['mkd_job_sl_no'] ?? ''));
    $r['die']           = trim((string)($extra['die'] ?? ''));
    $r['plate_no']      = trim((string)($extra['plate_no'] ?? ''));
    $r['label_size']    = trim((string)($extra['label_size'] ?? ''));
    $r['order_qty']     = $extra['order_qty'] ?? '';
    $r['actual_qty']    = $extra['actual_qty'] ?? '';
    $cl = $extra['colour_lanes'] ?? [];
    $r['colour_summary'] = is_array($cl) ? implode(', ', array_filter($cl)) : '';
    $al = $extra['anilox_lanes'] ?? [];
    $r['anilox_summary'] = is_array($al) ? implode(', ', array_filter($al)) : '';
    $r['prepared_by'] = trim((string)($extra['prepared_by'] ?? ''));
    $r['filled_by']   = trim((string)($extra['filled_by'] ?? ''));
    $r['electricity'] = trim((string)($extra['electricity'] ?? ''));
    $r['time_spent']  = trim((string)($extra['time_spent'] ?? ''));
}
unset($r);

$total     = count($rows);
$pending   = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
$running   = count(array_filter($rows, fn($r) => $r['status'] === 'Running'));
$queued    = count(array_filter($rows, fn($r) => $r['status'] === 'Queued'));
$completed = count(array_filter($rows, fn($r) => in_array($r['status'], ['Completed','QC Passed','Closed','Finalized'])));
$hold      = count(array_filter($rows, fn($r) => str_contains(strtolower($r['status'] ?? ''), 'hold')));

$dateLabels = ['all'=>'All Time','day'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'];
$currentUrl = BASE_URL . '/modules/planning/printing/index.php';
$planningPageKey = 'printing';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Planning</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Flexo Printing</span>
</div>

<?php include __DIR__ . '/../_page_switcher.php'; ?>

<style>
:root{--pr-brand:#8b5cf6}
.pr-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.pr-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.pr-header h1 i{font-size:1.6rem;color:var(--pr-brand)}
.pr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px}
.pr-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.pr-stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.pr-stat-val{font-size:1.4rem;font-weight:900;line-height:1}
.pr-stat-label{font-size:.62rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.pr-date-btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.pr-date-btn{padding:6px 16px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;color:#64748b;text-decoration:none;transition:all .15s}
.pr-date-btn.active{background:var(--pr-brand);border-color:var(--pr-brand);color:#fff}
.pr-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.pr-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:260px;outline:none;transition:border .15s}
.pr-search:focus{border-color:var(--pr-brand)}
.pr-tbl{width:100%;border-collapse:collapse;font-size:.75rem}
.pr-tbl th{padding:9px 10px;text-align:left;border-bottom:2px solid #e2e8f0;background:#f8fafc;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#64748b;white-space:nowrap}
.pr-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.pr-tbl tr:hover td{background:#faf5ff}
.pr-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:12px;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.pr-badge-queued{background:#f1f5f9;color:#475569}
.pr-badge-pending{background:#fef3c7;color:#92400e}
.pr-badge-running{background:#dbeafe;color:#1e40af}
.pr-badge-completed,.pr-badge-qc,.pr-badge-closed,.pr-badge-finalized{background:#dcfce7;color:#166534}
.pr-badge-hold{background:#fee2e2;color:#991b1b}
.pr-pri-urgent{background:#fee2e2;color:#991b1b;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
.pr-pri-high{background:#ffedd5;color:#9a3412;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
.pr-pri-normal{background:#e0f2fe;color:#075985;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
@media print{.no-print,.breadcrumb,.page-header{display:none!important}.print-only-header{display:block!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:700px){.pr-stats{grid-template-columns:repeat(2,1fr)}.pr-controls{flex-direction:column;align-items:flex-start}}
</style>

<div class="pr-header no-print">
  <div>
    <h1><i class="bi bi-printer-fill"></i> Flexo Printing — Job Planning</h1>
    <div style="font-size:.75rem;color:#64748b;font-weight:600">All Flexo Printing job card details &middot; Filter by date range &middot; <?= $dateLabels[$dateFilter] ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="window.print()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--pr-brand);color:#fff;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-printer"></i> Print</button>
    <button onclick="location.reload()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<div class="pr-stats no-print">
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#faf5ff;color:var(--pr-brand)"><i class="bi bi-printer"></i></div>
    <div><div class="pr-stat-val"><?= $total ?></div><div class="pr-stat-label">Total</div></div>
  </div>
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="pr-stat-val"><?= $queued ?></div><div class="pr-stat-label">Queued</div></div>
  </div>
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="pr-stat-val"><?= $pending ?></div><div class="pr-stat-label">Pending</div></div>
  </div>
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="pr-stat-val"><?= $running ?></div><div class="pr-stat-label">Running</div></div>
  </div>
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="pr-stat-val"><?= $completed ?></div><div class="pr-stat-label">Completed</div></div>
  </div>
  <div class="pr-stat">
    <div class="pr-stat-icon" style="background:#fee2e2;color:#dc2626"><i class="bi bi-pause-circle-fill"></i></div>
    <div><div class="pr-stat-val"><?= $hold ?></div><div class="pr-stat-label">On Hold</div></div>
  </div>
</div>

<div class="pr-date-btns no-print">
  <?php foreach ($dateLabels as $key => $label): ?>
    <a href="<?= $currentUrl ?>?date_range=<?= $key ?>" class="pr-date-btn <?= $dateFilter === $key ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="pr-controls no-print">
  <input type="text" class="pr-search" id="prSearch" placeholder="Search job no, roll, job name, die, plate no…" oninput="filterPrTable(this.value)">
  <span style="font-size:.72rem;color:#64748b;font-weight:700" id="prRowCount"><?= $total ?> records</span>
</div>

<div class="card">
  <div class="card-header no-print" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-table"></i> Flexo Printing Jobs — <?= htmlspecialchars($dateLabels[$dateFilter]) ?></span>
  </div>
  <!-- Print-only header -->
  <div style="display:none" class="print-only-header">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;border-bottom:2px solid #8b5cf6;padding-bottom:10px">
      <div>
        <div style="font-weight:900;font-size:1.1rem"><?= htmlspecialchars($companyName) ?></div>
        <div style="font-size:.75rem;color:#64748b"><?= htmlspecialchars($companyAddr) ?></div>
        <?php if ($companyGst): ?><div style="font-size:.65rem;color:#94a3b8">GST: <?= htmlspecialchars($companyGst) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-weight:900;font-size:1rem;color:#8b5cf6">Flexo Printing — Job Planning</div>
        <div style="font-size:.72rem;color:#64748b"><?= $dateLabels[$dateFilter] ?> &nbsp;|&nbsp; <?= $total ?> records &nbsp;|&nbsp; Printed: <?= date('d M Y H:i') ?></div>
      </div>
    </div>
  </div>
  <div style="overflow:auto">
    <table class="pr-tbl" id="prTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Job No</th>
          <th>Job Name</th>
          <th>MKD SL No</th>
          <th>Roll No</th>
          <th>Material</th>
          <th>Die</th>
          <th>Plate No</th>
          <th>Label Size</th>
          <th>Order QTY</th>
          <th>Actual QTY</th>
          <th>Colours (1-8)</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Started</th>
          <th>Completed</th>
          <th>Duration</th>
          <th>Electricity</th>
          <th>Time</th>
          <th>Prepared By</th>
          <th>Filled By</th>
          <th>Prev Slitting</th>
        </tr>
      </thead>
      <tbody id="prTbody">
      <?php if (empty($rows)): ?>
        <tr><td colspan="22" style="padding:24px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>No Flexo Printing jobs found for this period.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $i => $r):
          $sts = (string)($r['status'] ?? 'Pending');
          $stsCls = match(strtolower($sts)) {
            'pending'   => 'pending',
            'queued'    => 'queued',
            'running'   => 'running',
            'completed' => 'completed',
            'qc passed' => 'qc',
            'closed'    => 'closed',
            'finalized' => 'finalized',
            default     => (str_contains(strtolower($sts), 'hold') ? 'hold' : 'queued')
          };
          $pri = (string)($r['planning_priority'] ?? 'Normal');
          $priCls = match(strtolower($pri)) { 'urgent'=>'pr-pri-urgent', 'high'=>'pr-pri-high', default=>'pr-pri-normal' };
          $dur = $r['duration_minutes'] ?? null;
          $durStr = ($dur !== null) ? (floor($dur/60).'h '.($dur%60).'m') : '—';
          $started   = $r['started_at']   ? date('d M Y<br>H:i', strtotime($r['started_at']))   : '—';
          $completed = $r['completed_at'] ? date('d M Y<br>H:i', strtotime($r['completed_at'])) : '—';
          $prevInfo  = $r['prev_job_no'] ? htmlspecialchars($r['prev_job_no'].' ('.$r['prev_job_status'].')') : '—';
          $searchStr = strtolower(
            ($r['job_no']??'').' '.($r['planning_job_name']??'').' '.
            ($r['roll_no']??'').' '.($r['supplier']??'').' '.($r['paper_type']??'').' '.
            ($r['mkd_job_sl_no']??'').' '.($r['die']??'').' '.($r['plate_no']??'').' '.
            ($r['operator_name']??'')
          );
        ?>
        <tr data-search="<?= htmlspecialchars($searchStr, ENT_QUOTES) ?>">
          <td style="color:#94a3b8;font-weight:700"><?= $i+1 ?></td>
          <td style="font-weight:800;white-space:nowrap"><?php if (!empty($r['id']) && !empty($r['job_no'])): ?><a href="<?= BASE_URL ?>/modules/jobs/printing/index.php?auto_job=<?= (int)$r['id'] ?>" style="color:var(--pr-brand);text-decoration:underline;text-underline-offset:3px" title="Open Job Card"><?= htmlspecialchars($r['job_no']) ?></a><?php else: ?><span style="color:var(--pr-brand)"><?= htmlspecialchars($r['job_no'] ?? '—') ?></span><?php endif; ?></td>
          <td style="max-width:130px"><?= htmlspecialchars($r['planning_job_name'] ?? '—') ?></td>
          <td style="font-weight:700"><?= htmlspecialchars($r['mkd_job_sl_no'] ?: '—') ?></td>
          <td style="white-space:nowrap"><?= htmlspecialchars($r['roll_no'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['supplier'] ?? '—') ?><br><span style="font-size:.62rem;color:#94a3b8"><?= htmlspecialchars($r['paper_type'] ?? '') ?></span></td>
          <td><?= htmlspecialchars($r['die'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['plate_no'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['label_size'] ?: '—') ?></td>
          <td style="font-weight:700"><?= htmlspecialchars((string)($r['order_qty'] ?: '—')) ?></td>
          <td style="font-weight:700;color:#16a34a"><?= htmlspecialchars((string)($r['actual_qty'] ?: '—')) ?></td>
          <td style="max-width:120px;font-size:.65rem"><?= htmlspecialchars($r['colour_summary'] ?: '—') ?></td>
          <td><span class="pr-badge pr-badge-<?= $stsCls ?>"><?= htmlspecialchars($sts) ?></span></td>
          <td><span class="<?= $priCls ?>"><?= htmlspecialchars($pri) ?></span></td>
          <td style="white-space:nowrap;font-size:.7rem"><?= $started ?></td>
          <td style="white-space:nowrap;font-size:.7rem"><?= $completed ?></td>
          <td style="white-space:nowrap;font-weight:700;color:#16a34a"><?= $durStr ?></td>
          <td><?= htmlspecialchars($r['electricity'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['time_spent'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['prepared_by'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['filled_by'] ?: '—') ?></td>
          <td style="font-size:.7rem;color:#64748b"><?= $prevInfo ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filterPrTable(q) {
  q = (q || '').toLowerCase();
  let visible = 0;
  document.querySelectorAll('#prTbody tr[data-search]').forEach(row => {
    const match = (row.dataset.search || '').includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  const cnt = document.getElementById('prRowCount');
  if (cnt) cnt.textContent = visible + ' records';
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
