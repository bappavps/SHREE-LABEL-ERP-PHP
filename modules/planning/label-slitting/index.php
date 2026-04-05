<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle   = 'Label Slitting — Job Planning';
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

// ── Label slitting department filter (same as job card page) ──
$dcWhere = "(
    LOWER(COALESCE(j.department, '')) IN ('label-slitting', 'label_slitting', 'label slitting')
    OR LOWER(COALESCE(j.job_type, '')) IN ('label-slitting', 'label slitting', 'label_slitting')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) IN ('label-slitting', 'label_slitting', 'label slitting'))
)";

$rows = $db->query("
    SELECT j.*,
           ps.paper_type, ps.company AS supplier, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           p.job_name AS planning_job_name, p.status AS planning_status,
           p.priority AS planning_priority, p.machine, p.operator_name, p.scheduled_date,
           p.extra_data AS planning_extra_data,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE {$dcWhere}
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
      {$dateWhere}
    ORDER BY j.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Parse extra_data for each row
foreach ($rows as &$r) {
    $extra = json_decode((string)($r['extra_data'] ?? '{}'), true) ?: [];
    $planExtra = json_decode((string)($r['planning_extra_data'] ?? '{}'), true) ?: [];

    // Planning-level die-cutting info
    $r['die_size']   = trim((string)($planExtra['size'] ?? ($planExtra['die_size'] ?? '')));
    $r['die_repeat'] = trim((string)($planExtra['repeat'] ?? ''));
    $r['order_qty']  = trim((string)($planExtra['qty_pcs'] ?? ''));
    $r['material']   = trim((string)($planExtra['material'] ?? ($r['paper_type'] ?? '')));
    $r['die_type']   = trim((string)($planExtra['die'] ?? ''));

    // Operator-submitted die-cutting data
    $r['dc_total_qty']    = trim((string)($extra['die_cutting_total_qty_pcs'] ?? ''));
    $r['dc_wastage_pcs']  = trim((string)($extra['die_cutting_wastage_pcs'] ?? ''));
    $r['dc_wastage_mtr']  = trim((string)($extra['die_cutting_wastage_mtr'] ?? ''));
    $r['dc_roll_length']  = trim((string)($extra['die_cutting_printed_roll_length_mtr'] ?? ''));
    $r['dc_notes']        = trim((string)($extra['die_cutting_notes_text'] ?? ''));
}
unset($r);

$total     = count($rows);
$queued    = count(array_filter($rows, fn($r) => $r['status'] === 'Queued'));
$pending   = count(array_filter($rows, fn($r) => $r['status'] === 'Pending'));
$running   = count(array_filter($rows, fn($r) => $r['status'] === 'Running'));
$completed = count(array_filter($rows, fn($r) => in_array($r['status'], ['Completed','QC Passed','Closed','Finalized'])));
$hold      = count(array_filter($rows, fn($r) => str_contains(strtolower($r['status'] ?? ''), 'hold')));

$dateLabels = ['all'=>'All Time','day'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'];
$currentUrl = BASE_URL . '/modules/planning/label-slitting/index.php';
$planningPageKey = 'label-slitting';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/planning/index.php">Job Planning</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Label Slitting</span>
</div>

<?php include __DIR__ . '/../_page_switcher.php'; ?>

<style>
:root{--dc-brand:#f97316}
.dc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.dc-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.dc-header h1 i{font-size:1.6rem;color:var(--dc-brand)}
.dc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:20px}
.dc-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.dc-stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.dc-stat-val{font-size:1.4rem;font-weight:900;line-height:1}
.dc-stat-label{font-size:.62rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.dc-date-btns{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.dc-date-btn{padding:6px 16px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;color:#64748b;text-decoration:none;transition:all .15s}
.dc-date-btn.active{background:var(--dc-brand);border-color:var(--dc-brand);color:#fff}
.dc-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.dc-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:260px;outline:none;transition:border .15s}
.dc-search:focus{border-color:var(--dc-brand)}
.dc-tbl{width:100%;border-collapse:collapse;font-size:.75rem}
.dc-tbl th{padding:9px 10px;text-align:left;border-bottom:2px solid #e2e8f0;background:#f8fafc;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:#64748b;white-space:nowrap}
.dc-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.dc-tbl tr:hover td{background:#f0fdfa}
.dc-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:12px;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.dc-badge-queued{background:#f1f5f9;color:#475569}
.dc-badge-pending{background:#fef3c7;color:#92400e}
.dc-badge-running{background:#dbeafe;color:#1e40af}
.dc-badge-completed,.dc-badge-qc,.dc-badge-closed,.dc-badge-finalized{background:#dcfce7;color:#166534}
.dc-badge-hold{background:#fee2e2;color:#991b1b}
.dc-pri-urgent{background:#fee2e2;color:#991b1b;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
.dc-pri-high{background:#ffedd5;color:#9a3412;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
.dc-pri-normal{background:#e0f2fe;color:#075985;display:inline-flex;padding:2px 7px;border-radius:10px;font-size:.57rem;font-weight:800}
.dc-num{text-align:right;font-family:'Consolas',monospace;font-weight:700}
@media print{.no-print,.breadcrumb,.page-header{display:none!important}.print-only-header{display:block!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:700px){.dc-stats{grid-template-columns:repeat(2,1fr)}.dc-controls{flex-direction:column;align-items:flex-start}}
</style>

<div class="dc-header no-print">
  <div>
    <h1><i class="bi bi-layout-split"></i> Label Slitting — Job Planning</h1>
    <div style="font-size:.75rem;color:#64748b;font-weight:600">All Label Slitting job card details &middot; Filter by date range &middot; <?= $dateLabels[$dateFilter] ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button onclick="window.print()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--dc-brand);color:#fff;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-printer"></i> Print</button>
    <button onclick="location.reload()" style="padding:7px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<div class="dc-stats no-print">
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#f0fdfa;color:var(--dc-brand)"><i class="bi bi-layout-split"></i></div>
    <div><div class="dc-stat-val"><?= $total ?></div><div class="dc-stat-label">Total</div></div>
  </div>
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="dc-stat-val"><?= $queued ?></div><div class="dc-stat-label">Queued</div></div>
  </div>
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="dc-stat-val"><?= $pending ?></div><div class="dc-stat-label">Pending</div></div>
  </div>
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="dc-stat-val"><?= $running ?></div><div class="dc-stat-label">Running</div></div>
  </div>
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="dc-stat-val"><?= $completed ?></div><div class="dc-stat-label">Completed</div></div>
  </div>
  <div class="dc-stat">
    <div class="dc-stat-icon" style="background:#fee2e2;color:#dc2626"><i class="bi bi-pause-circle-fill"></i></div>
    <div><div class="dc-stat-val"><?= $hold ?></div><div class="dc-stat-label">On Hold</div></div>
  </div>
</div>

<div class="dc-date-btns no-print">
  <?php foreach ($dateLabels as $key => $label): ?>
    <a href="<?= $currentUrl ?>?date_range=<?= $key ?>" class="dc-date-btn <?= $dateFilter === $key ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="dc-controls no-print">
  <input type="text" class="dc-search" id="dcSearch" placeholder="Search job no, roll, job name, die, supplier…" oninput="filterDcTable(this.value)">
  <span style="font-size:.72rem;color:#64748b;font-weight:700" id="dcRowCount"><?= $total ?> records</span>
</div>

<div class="card">
  <div class="card-header no-print" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-table"></i> Label Slitting Jobs — <?= htmlspecialchars($dateLabels[$dateFilter]) ?></span>
  </div>
  <!-- Print-only header -->
  <div style="display:none" class="print-only-header">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;border-bottom:2px solid #f97316;padding-bottom:10px">
      <div>
        <div style="font-weight:900;font-size:1.1rem"><?= htmlspecialchars($companyName) ?></div>
        <div style="font-size:.75rem;color:#64748b"><?= htmlspecialchars($companyAddr) ?></div>
        <?php if ($companyGst): ?><div style="font-size:.65rem;color:#94a3b8">GST: <?= htmlspecialchars($companyGst) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-weight:900;font-size:1rem;color:#f97316">Label Slitting — Job Planning</div>
        <div style="font-size:.72rem;color:#64748b"><?= $dateLabels[$dateFilter] ?> &nbsp;|&nbsp; <?= $total ?> records &nbsp;|&nbsp; Printed: <?= date('d M Y H:i') ?></div>
      </div>
    </div>
  </div>
  <div style="overflow:auto">
    <table class="dc-tbl" id="dcTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Job No</th>
          <th>Job Name</th>
          <th>Roll No</th>
          <th>Supplier / Material</th>
          <th>Slitting Type</th>
          <th>Label Finish Size</th>
          <th>Repeate Gap</th>
          <th>Order QTY</th>
          <th>Slitting QTY</th>
          <th>Wastage %</th>
          <th>Wastage MTR</th>
          <th>Roll Len (MTR)</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Operator</th>
          <th>Started</th>
          <th>Completed</th>
          <th>Duration</th>
          <th>Prev Department</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody id="dcTbody">
      <?php if (empty($rows)): ?>
        <tr><td colspan="21" style="padding:24px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>No Label Slitting jobs found for this period.</td></tr>
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
          $priCls = match(strtolower($pri)) { 'urgent'=>'dc-pri-urgent', 'high'=>'dc-pri-high', default=>'dc-pri-normal' };
          $dur = $r['duration_minutes'] ?? null;
          $durStr = ($dur !== null) ? (floor($dur/60).'h '.($dur%60).'m') : '—';
          $started   = $r['started_at']   ? date('d M Y<br>H:i', strtotime($r['started_at']))   : '—';
          $completed = $r['completed_at'] ? date('d M Y<br>H:i', strtotime($r['completed_at'])) : '—';
          $prevInfo  = $r['prev_job_no'] ? htmlspecialchars($r['prev_job_no'].' ('.$r['prev_job_status'].')') : '—';
          $jobName   = trim((string)($r['planning_job_name'] ?? ''));
          if ($jobName === '') $jobName = trim((string)($r['job_no'] ?? '—'));
          $searchStr = strtolower(
            ($r['job_no']??'').' '.($r['planning_job_name']??'').' '.
            ($r['roll_no']??'').' '.($r['supplier']??'').' '.($r['paper_type']??'').' '.
            ($r['die_type']??'').' '.($r['die_size']??'').' '.
            ($r['operator_name']??'').' '.($r['dc_notes']??'')
          );
        ?>
        <tr data-search="<?= htmlspecialchars($searchStr, ENT_QUOTES) ?>">
          <td style="color:#94a3b8;font-weight:700"><?= $i+1 ?></td>
          <td style="font-weight:800;white-space:nowrap"><?php if (!empty($r['id']) && !empty($r['job_no'])): ?><a href="<?= BASE_URL ?>/modules/jobs/label-slitting/index.php?auto_job=<?= (int)$r['id'] ?>" style="color:var(--dc-brand);text-decoration:underline;text-underline-offset:3px" title="Open Job Card"><?= htmlspecialchars($r['job_no']) ?></a><?php else: ?><span style="color:var(--dc-brand)"><?= htmlspecialchars($r['job_no'] ?? '—') ?></span><?php endif; ?></td>
          <td style="max-width:130px"><?= htmlspecialchars($jobName) ?></td>
          <td style="white-space:nowrap;font-weight:700"><?= htmlspecialchars($r['roll_no'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['supplier'] ?? '—') ?><br><span style="font-size:.62rem;color:#94a3b8"><?= htmlspecialchars($r['material'] ?: ($r['paper_type'] ?? '')) ?></span></td>
          <td style="font-weight:700"><?= htmlspecialchars($r['die_type'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['die_size'] ?: '—') ?></td>
          <td><?= htmlspecialchars($r['die_repeat'] ?: '—') ?></td>
          <td class="dc-num"><?= htmlspecialchars($r['order_qty'] ?: '—') ?></td>
          <td class="dc-num" style="color:#f97316"><?= htmlspecialchars($r['dc_total_qty'] ?: '—') ?></td>
          <td class="dc-num" style="color:#dc2626"><?= htmlspecialchars($r['dc_wastage_pcs'] ?: '—') ?></td>
          <td class="dc-num" style="color:#dc2626"><?= htmlspecialchars($r['dc_wastage_mtr'] ?: '—') ?></td>
          <td class="dc-num"><?= htmlspecialchars($r['dc_roll_length'] ?: '—') ?></td>
          <td><span class="dc-badge dc-badge-<?= $stsCls ?>"><?= htmlspecialchars($sts) ?></span></td>
          <td><span class="<?= $priCls ?>"><?= htmlspecialchars($pri) ?></span></td>
          <td style="white-space:nowrap"><?= htmlspecialchars($r['operator_name'] ?? '—') ?></td>
          <td style="white-space:nowrap;font-size:.7rem"><?= $started ?></td>
          <td style="white-space:nowrap;font-size:.7rem"><?= $completed ?></td>
          <td style="white-space:nowrap;font-weight:700;color:#16a34a"><?= $durStr ?></td>
          <td style="font-size:.7rem;color:#64748b"><?= $prevInfo ?></td>
          <td style="max-width:130px;font-size:.7rem;color:#64748b"><?= htmlspecialchars($r['dc_notes'] ?: ($r['notes'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function filterDcTable(q) {
  q = (q || '').toLowerCase();
  let visible = 0;
  document.querySelectorAll('#dcTbody tr[data-search]').forEach(row => {
    const match = (row.dataset.search || '').includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  document.getElementById('dcRowCount').textContent = visible + ' records';
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>

