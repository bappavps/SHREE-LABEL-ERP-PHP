<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Job Reports';
$db = getDB();

function jrNormalizeDepartment(array $job): array {
  $rawDept = strtolower(trim((string)($job['department'] ?? '')));
  $rawType = strtolower(trim((string)($job['job_type'] ?? '')));

  if (in_array($rawDept, ['jumbo_slitting', 'jumbo slitting'], true)) {
    return ['key' => 'jumbo_slitting', 'label' => 'Jumbo Slitting', 'class' => 'jr-dept-slitting'];
  }
  if (in_array($rawDept, ['flexo_printing', 'flexo printing'], true)) {
    return ['key' => 'flexo_printing', 'label' => 'Flexo Printing', 'class' => 'jr-dept-printing'];
  }
  if (in_array($rawDept, ['flatbed', 'die-cutting', 'die_cutting', 'die cutting'], true)) {
    return ['key' => 'flatbed', 'label' => 'Die Cutting', 'class' => 'jr-dept-slitting'];
  }
  if ($rawDept === 'barcode') {
    return ['key' => 'barcode', 'label' => 'Barcode', 'class' => 'jr-dept-printing'];
  }
  if (in_array($rawDept, ['label_slitting', 'label slitting', 'label-slitting'], true)) {
    return ['key' => 'label_slitting', 'label' => 'Label Slitting', 'class' => 'jr-dept-slitting'];
  }

  // Legacy fallback by job_type
  if ($rawType === 'slitting') {
    return ['key' => 'jumbo_slitting', 'label' => 'Jumbo Slitting', 'class' => 'jr-dept-slitting'];
  }
  if ($rawType === 'printing') {
    return ['key' => 'flexo_printing', 'label' => 'Flexo Printing', 'class' => 'jr-dept-printing'];
  }

  return ['key' => 'unknown', 'label' => 'Unknown', 'class' => 'jr-dept-printing'];
}

function jrPauseSeconds(array $extra): int {
  $total = 0;
  $segments = $extra['timer_pause_segments'] ?? [];
  if (is_array($segments)) {
    foreach ($segments as $row) {
      $total += max(0, (int)($row['seconds'] ?? 0));
    }
  }

  $timerState = strtolower(trim((string)($extra['timer_state'] ?? '')));
  if ($timerState === 'paused') {
    $pausedAt = trim((string)($extra['timer_pause_started_at'] ?? ($extra['timer_paused_at'] ?? '')));
    if ($pausedAt !== '') {
      $pausedTs = strtotime($pausedAt);
      if ($pausedTs !== false && $pausedTs > 0) {
        $total += max(0, time() - $pausedTs);
      }
    }
  }

  return $total;
}

function jrWastagePct(array $extra): ?float {
  foreach (['wastage_percentage', 'wastage_percent', 'label_slitting_wastage_percentage', 'die_cutting_wastage_percentage'] as $k) {
    if (isset($extra[$k]) && is_numeric($extra[$k])) {
      return (float)$extra[$k];
    }
  }
  return null;
}

function jrFirstNonEmpty(array $values, string $fallback = 'N/A'): string {
  foreach ($values as $value) {
    if (!is_string($value)) {
      continue;
    }
    $value = trim($value);
    if ($value !== '') {
      return $value;
    }
  }
  return $fallback;
}

// Date filters
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$deptFilter = $_GET['dept'] ?? 'all';

$where = "(j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')";
$where .= " AND DATE(j.created_at) BETWEEN '" . $db->real_escape_string($dateFrom) . "' AND '" . $db->real_escape_string($dateTo) . "'";
if ($deptFilter === 'jumbo_slitting') {
  $where .= " AND (j.department IN ('jumbo_slitting','jumbo slitting') OR j.job_type = 'Slitting')";
} elseif ($deptFilter === 'flexo_printing') {
  $where .= " AND (j.department IN ('flexo_printing','flexo printing') OR j.job_type = 'Printing')";
} elseif ($deptFilter === 'die_cutting') {
  $where .= " AND (j.department IN ('flatbed','die-cutting','die_cutting','die cutting'))";
} elseif ($deptFilter === 'barcode') {
  $where .= " AND (j.department = 'barcode')";
} elseif ($deptFilter === 'label_slitting') {
  $where .= " AND (j.department IN ('label_slitting','label slitting','label-slitting'))";
}

$jobs = $db->query("
  SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.gsm, ps.weight_kg,
       p.job_name AS planning_job_name, p.priority AS planning_priority,
       p.machine AS planning_machine, p.operator_name AS planning_operator_name,
       p.department AS planning_department,
       u.name AS operator_user_name
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
  LEFT JOIN users u ON j.operator_id = u.id
    WHERE $where
    ORDER BY j.created_at DESC
    LIMIT 500
")->fetch_all(MYSQLI_ASSOC);

// Parse extra data
foreach ($jobs as &$j) {
    $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
  $deptInfo = jrNormalizeDepartment($j);
  $j['report_dept_key'] = $deptInfo['key'];
  $j['report_dept_label'] = $deptInfo['label'];
  $j['report_dept_class'] = $deptInfo['class'];
  $extra = $j['extra_data_parsed'];
  $j['report_machine_name'] = jrFirstNonEmpty([
    (string)($extra['machine'] ?? ''),
    (string)($extra['machine_name'] ?? ''),
    (string)($j['planning_machine'] ?? ''),
  ], 'N/A');
  $j['report_operator_name'] = jrFirstNonEmpty([
    (string)($extra['operator_name'] ?? ''),
    (string)($j['operator_user_name'] ?? ''),
    (string)($j['planning_operator_name'] ?? ''),
  ], 'Unassigned');
}
unset($j);

// KPIs
$totalJobs = count($jobs);
$completed = array_filter($jobs, fn($j) => in_array($j['status'], ['Completed','QC Passed']));
$running   = array_filter($jobs, fn($j) => $j['status'] === 'Running');
$pending   = array_filter($jobs, fn($j) => $j['status'] === 'Pending');
$completedCount = count($completed);
$avgDuration = 0;
$durations = array_filter(array_column($completed, 'duration_minutes'), fn($d) => $d > 0);
if (count($durations) > 0) $avgDuration = round(array_sum($durations) / count($durations));

$completionRate = $totalJobs > 0 ? round(($completedCount / $totalJobs) * 100, 1) : 0.0;
$totalRunSeconds = 0;
$totalPauseSeconds = 0;
$issueCount = 0;
$wastageSum = 0.0;
$wastageCount = 0;

foreach ($jobs as $j) {
  $extra = $j['extra_data_parsed'] ?? [];
  $totalRunSeconds += max(0, (int)($extra['timer_accumulated_seconds'] ?? 0));
  $totalPauseSeconds += jrPauseSeconds($extra);

  $status = strtolower(trim((string)($j['status'] ?? '')));
  if ($status === 'hold' || str_starts_with($status, 'hold for')) {
    $issueCount++;
  }

  $notes = strtolower((string)($j['notes'] ?? ''));
  foreach (['issue', 'error', 'delay', 'breakdown', 'reject'] as $kw) {
    if (strpos($notes, $kw) !== false) {
      $issueCount++;
      break;
    }
  }

  $wastage = jrWastagePct($extra);
  if ($wastage !== null) {
    $wastageSum += $wastage;
    $wastageCount++;
  }
}

$utilizationRate = ($totalRunSeconds + $totalPauseSeconds) > 0
  ? round(($totalRunSeconds / ($totalRunSeconds + $totalPauseSeconds)) * 100, 1)
  : 0.0;
$totalWastagePct = $wastageCount > 0 ? round($wastageSum / $wastageCount, 1) : 0.0;
$runHoursText = floor($totalRunSeconds / 3600) . 'h ' . floor(($totalRunSeconds % 3600) / 60) . 'm';
$pauseHoursText = floor($totalPauseSeconds / 3600) . 'h ' . floor(($totalPauseSeconds % 3600) / 60) . 'm';

// Department/Machine/Manpower summary
$departmentReport = [];
$machineReport = [];
$manpowerReport = [];
foreach ($jobs as $j) {
  $status = (string)($j['status'] ?? '');
  $isCompleted = in_array($status, ['Completed', 'QC Passed'], true);

  $deptLabel = (string)($j['report_dept_label'] ?? 'Unknown');
  if (!isset($departmentReport[$deptLabel])) {
    $departmentReport[$deptLabel] = ['total' => 0, 'completed' => 0];
  }
  $departmentReport[$deptLabel]['total']++;
  if ($isCompleted) {
    $departmentReport[$deptLabel]['completed']++;
  }

  $machineName = (string)($j['report_machine_name'] ?? 'N/A');
  if (!isset($machineReport[$machineName])) {
    $machineReport[$machineName] = ['total' => 0, 'completed' => 0];
  }
  $machineReport[$machineName]['total']++;
  if ($isCompleted) {
    $machineReport[$machineName]['completed']++;
  }

  $manName = (string)($j['report_operator_name'] ?? 'Unassigned');
  if (!isset($manpowerReport[$manName])) {
    $manpowerReport[$manName] = ['total' => 0, 'completed' => 0];
  }
  $manpowerReport[$manName]['total']++;
  if ($isCompleted) {
    $manpowerReport[$manName]['completed']++;
  }
}
$summarySorter = static function(array $a, array $b): int {
  if (($a['total'] ?? 0) === ($b['total'] ?? 0)) {
    return ($b['completed'] ?? 0) <=> ($a['completed'] ?? 0);
  }
  return ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
};
uasort($departmentReport, $summarySorter);
uasort($machineReport, $summarySorter);
uasort($manpowerReport, $summarySorter);

// Department breakdown
$slittingJobs = array_filter($jobs, fn($j) => in_array(($j['report_dept_key'] ?? ''), ['jumbo_slitting', 'flatbed', 'label_slitting'], true));
$printingJobs = array_filter($jobs, fn($j) => in_array(($j['report_dept_key'] ?? ''), ['flexo_printing', 'barcode'], true));
$slittingCompleted = count(array_filter($slittingJobs, fn($j) => in_array($j['status'], ['Completed','QC Passed'])));
$printingCompleted = count(array_filter($printingJobs, fn($j) => in_array($j['status'], ['Completed','QC Passed'])));

// Daily completed chart data (last 14 days)
$chartDays = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartDays[$d] = ['slitting' => 0, 'printing' => 0];
}
foreach ($completed as $j) {
    $d = date('Y-m-d', strtotime($j['completed_at'] ?? $j['updated_at']));
    if (isset($chartDays[$d])) {
    $key = in_array(($j['report_dept_key'] ?? ''), ['jumbo_slitting', 'flatbed', 'label_slitting'], true) ? 'slitting' : 'printing';
        $chartDays[$d][$key]++;
    }
}

// Priority breakdown
$priorities = ['Urgent' => 0, 'High' => 0, 'Normal' => 0];
foreach ($jobs as $j) {
    $p = $j['planning_priority'] ?? 'Normal';
    if (isset($priorities[$p])) $priorities[$p]++;
    else $priorities['Normal']++;
}

$csrf = generateCSRF();
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Reports</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Reports</span>
</div>

<style>
:root{--jr-brand:#3b82f6;--jr-green:#22c55e;--jr-purple:#8b5cf6;--jr-orange:#f97316;--jr-red:#ef4444}
.jr-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.jr-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.jr-header h1 i{font-size:1.6rem;color:var(--jr-brand)}
.jr-date-filters{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.jr-date-filters input[type=date],.jr-date-filters select{padding:7px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;font-family:inherit}
.jr-date-filters input:focus,.jr-date-filters select:focus{outline:none;border-color:var(--jr-brand)}
.jr-apply-btn{padding:7px 16px;background:var(--jr-brand);color:#fff;border:none;border-radius:8px;font-size:.7rem;font-weight:800;cursor:pointer;text-transform:uppercase}
.jr-apply-btn:hover{background:#2563eb}
.jr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px}
.jr-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.jr-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.jr-stat-val{font-size:1.5rem;font-weight:900;line-height:1}
.jr-stat-label{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.jr-charts{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px}
.jr-chart-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:20px}
.jr-chart-title{font-size:.75rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.jr-bar-chart{display:flex;flex-direction:column;gap:8px}
.jr-bar-row{display:flex;align-items:center;gap:10px;font-size:.7rem}
.jr-bar-label{width:65px;font-weight:700;color:#64748b;text-align:right;flex-shrink:0;font-size:.6rem}
.jr-bar-track{flex:1;height:24px;background:#f1f5f9;border-radius:6px;overflow:hidden;display:flex}
.jr-bar-fill{height:100%;border-radius:6px;transition:width .5s ease;display:flex;align-items:center;padding:0 6px;font-size:.55rem;font-weight:800;color:#fff}
.jr-bar-fill.slitting{background:var(--jr-green)}
.jr-bar-fill.printing{background:var(--jr-purple)}
.jr-donut-list{display:flex;flex-direction:column;gap:10px}
.jr-donut-item{display:flex;align-items:center;gap:10px}
.jr-donut-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}
.jr-donut-label{flex:1;font-size:.75rem;font-weight:700;color:#475569}
.jr-donut-val{font-size:.85rem;font-weight:900;color:#0f172a}
.jr-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.jr-table-head{padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
.jr-table-head h3{font-size:.85rem;font-weight:900}
.jr-export-btn{padding:5px 12px;font-size:.6rem;font-weight:800;text-transform:uppercase;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer}
.jr-export-btn:hover{background:#e2e8f0}
.jr-table{width:100%;border-collapse:collapse;font-size:.75rem}
.jr-table th{padding:10px 14px;background:#f8fafc;font-weight:800;text-transform:uppercase;font-size:.6rem;letter-spacing:.04em;color:#64748b;text-align:left;border-bottom:1px solid #e2e8f0}
.jr-table td{padding:8px 14px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-weight:600}
.jr-table tr:hover td{background:#f8fafc}
.jr-badge{display:inline-flex;padding:2px 8px;border-radius:12px;font-size:.55rem;font-weight:800;text-transform:uppercase}
.jr-badge-completed{background:#dcfce7;color:#166534}
.jr-badge-running{background:#dbeafe;color:#1e40af}
.jr-badge-pending{background:#fef3c7;color:#92400e}
.jr-badge-queued{background:#f1f5f9;color:#64748b}
.jr-dept-slitting{color:var(--jr-green);font-weight:800}
.jr-dept-printing{color:var(--jr-purple);font-weight:800}
.jr-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:24px}
.jr-report-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.jr-report-head{padding:12px 14px;border-bottom:1px solid #e2e8f0;font-size:.72rem;font-weight:800;text-transform:uppercase;color:#334155;display:flex;align-items:center;gap:8px}
.jr-mini-table{width:100%;border-collapse:collapse}
.jr-mini-table th,.jr-mini-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:.68rem}
.jr-mini-table th{background:#f8fafc;color:#64748b;font-weight:800;text-transform:uppercase;font-size:.58rem}
.jr-mini-table td{font-weight:600;color:#1e293b}
.jr-mini-table tr:last-child td{border-bottom:none}
.jr-cell-muted{color:#64748b;font-weight:700}
@media(max-width:768px){.jr-charts{grid-template-columns:1fr}.jr-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:980px){.jr-report-grid{grid-template-columns:1fr}}
@media print{.no-print,.breadcrumb{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
</style>

<div class="jr-header no-print">
  <h1><i class="bi bi-bar-chart-line"></i> Job Reports</h1>
  <form class="jr-date-filters" method="get">
    <input type="date" name="from" value="<?= e($dateFrom) ?>">
    <span style="color:#94a3b8;font-weight:700;font-size:.7rem">to</span>
    <input type="date" name="to" value="<?= e($dateTo) ?>">
    <select name="dept">
      <option value="all"<?= $deptFilter==='all'?' selected':'' ?>>All Departments</option>
      <option value="jumbo_slitting"<?= $deptFilter==='jumbo_slitting'?' selected':'' ?>>Jumbo Slitting</option>
      <option value="flexo_printing"<?= $deptFilter==='flexo_printing'?' selected':'' ?>>Flexo Printing</option>
      <option value="die_cutting"<?= $deptFilter==='die_cutting'?' selected':'' ?>>Die Cutting</option>
      <option value="barcode"<?= $deptFilter==='barcode'?' selected':'' ?>>Barcode</option>
      <option value="label_slitting"<?= $deptFilter==='label_slitting'?' selected':'' ?>>Label Slitting</option>
    </select>
    <button class="jr-apply-btn" type="submit"><i class="bi bi-filter"></i> Apply</button>
  </form>
</div>

<div class="jr-stats no-print">
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#dbeafe;color:var(--jr-brand)"><i class="bi bi-briefcase"></i></div>
    <div><div class="jr-stat-val"><?= $totalJobs ?></div><div class="jr-stat-label">Total Jobs</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#dcfce7;color:var(--jr-green)"><i class="bi bi-check-circle"></i></div>
    <div><div class="jr-stat-val"><?= $completedCount ?></div><div class="jr-stat-label">Completed</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#fef3c7;color:var(--jr-orange)"><i class="bi bi-clock-history"></i></div>
    <div><div class="jr-stat-val"><?= $avgDuration ? floor($avgDuration/60).'h '.($avgDuration%60).'m' : '—' ?></div><div class="jr-stat-label">Avg Duration</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#f0fdf4;color:var(--jr-green)"><i class="bi bi-boxes"></i></div>
    <div><div class="jr-stat-val"><?= $slittingCompleted ?></div><div class="jr-stat-label">Slitting Done</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#faf5ff;color:var(--jr-purple)"><i class="bi bi-printer"></i></div>
    <div><div class="jr-stat-val"><?= $printingCompleted ?></div><div class="jr-stat-label">Printing Done</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#eff6ff;color:#1d4ed8"><i class="bi bi-clipboard-data"></i></div>
    <div><div class="jr-stat-val"><?= number_format($completionRate, 1) ?>%</div><div class="jr-stat-label">Completion Rate</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#ecfeff;color:#0891b2"><i class="bi bi-cpu"></i></div>
    <div><div class="jr-stat-val"><?= number_format($utilizationRate, 1) ?>%</div><div class="jr-stat-label">Utilization</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#f0fdf4;color:#15803d"><i class="bi bi-play-circle"></i></div>
    <div><div class="jr-stat-val"><?= e($runHoursText) ?></div><div class="jr-stat-label">Total Run Time</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#fff7ed;color:#c2410c"><i class="bi bi-pause-circle"></i></div>
    <div><div class="jr-stat-val"><?= e($pauseHoursText) ?></div><div class="jr-stat-label">Total Pause Time</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#fef2f2;color:#dc2626"><i class="bi bi-exclamation-triangle"></i></div>
    <div><div class="jr-stat-val"><?= (int)$issueCount ?></div><div class="jr-stat-label">Issues</div></div>
  </div>
  <div class="jr-stat">
    <div class="jr-stat-icon" style="background:#fefce8;color:#a16207"><i class="bi bi-percent"></i></div>
    <div><div class="jr-stat-val"><?= number_format($totalWastagePct, 1) ?>%</div><div class="jr-stat-label">Avg Wastage</div></div>
  </div>
</div>

<div class="jr-charts">
  <!-- Daily completed bar chart -->
  <div class="jr-chart-card">
    <div class="jr-chart-title"><i class="bi bi-graph-up"></i> Daily Completed Jobs (Last 14 Days)</div>
    <div class="jr-bar-chart">
      <?php
        $maxDay = max(1, max(array_map(fn($d) => $d['slitting'] + $d['printing'], $chartDays)));
        foreach ($chartDays as $date => $vals):
          $total = $vals['slitting'] + $vals['printing'];
          $sW = round(($vals['slitting'] / $maxDay) * 100);
          $pW = round(($vals['printing'] / $maxDay) * 100);
      ?>
      <div class="jr-bar-row">
        <span class="jr-bar-label"><?= date('d M', strtotime($date)) ?></span>
        <div class="jr-bar-track">
          <?php if ($vals['slitting']): ?><div class="jr-bar-fill slitting" style="width:<?= $sW ?>%"><?= $vals['slitting'] ?></div><?php endif; ?>
          <?php if ($vals['printing']): ?><div class="jr-bar-fill printing" style="width:<?= $pW ?>%"><?= $vals['printing'] ?></div><?php endif; ?>
        </div>
        <span style="font-weight:800;font-size:.7rem;color:#1e293b;width:24px"><?= $total ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:16px;margin-top:10px;padding-left:75px">
      <span style="display:flex;align-items:center;gap:4px;font-size:.6rem;font-weight:700;color:#64748b"><span style="width:10px;height:10px;border-radius:2px;background:var(--jr-green)"></span> Slitting</span>
      <span style="display:flex;align-items:center;gap:4px;font-size:.6rem;font-weight:700;color:#64748b"><span style="width:10px;height:10px;border-radius:2px;background:var(--jr-purple)"></span> Printing</span>
    </div>
  </div>

  <!-- Priority breakdown -->
  <div class="jr-chart-card">
    <div class="jr-chart-title"><i class="bi bi-flag"></i> Priority Breakdown</div>
    <div class="jr-donut-list">
      <div class="jr-donut-item">
        <div class="jr-donut-dot" style="background:#ef4444"></div>
        <span class="jr-donut-label">Urgent</span>
        <span class="jr-donut-val"><?= $priorities['Urgent'] ?></span>
      </div>
      <div class="jr-donut-item">
        <div class="jr-donut-dot" style="background:#f97316"></div>
        <span class="jr-donut-label">High</span>
        <span class="jr-donut-val"><?= $priorities['High'] ?></span>
      </div>
      <div class="jr-donut-item">
        <div class="jr-donut-dot" style="background:#3b82f6"></div>
        <span class="jr-donut-label">Normal</span>
        <span class="jr-donut-val"><?= $priorities['Normal'] ?></span>
      </div>
    </div>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0">
      <div class="jr-chart-title"><i class="bi bi-building"></i> Dept Split</div>
      <div class="jr-donut-list">
        <div class="jr-donut-item">
          <div class="jr-donut-dot" style="background:var(--jr-green)"></div>
          <span class="jr-donut-label">Jumbo Slitting</span>
          <span class="jr-donut-val"><?= count($slittingJobs) ?></span>
        </div>
        <div class="jr-donut-item">
          <div class="jr-donut-dot" style="background:var(--jr-purple)"></div>
          <span class="jr-donut-label">Flexo Printing</span>
          <span class="jr-donut-val"><?= count($printingJobs) ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="jr-report-grid">
  <div class="jr-report-card">
    <div class="jr-report-head"><i class="bi bi-building"></i> Department Report</div>
    <table class="jr-mini-table">
      <thead>
        <tr><th>Department</th><th>Total</th><th>Done</th></tr>
      </thead>
      <tbody>
        <?php if (empty($departmentReport)): ?>
        <tr><td colspan="3" class="jr-cell-muted">No department data</td></tr>
        <?php else: ?>
        <?php foreach (array_slice($departmentReport, 0, 10, true) as $name => $vals): ?>
        <tr>
          <td><?= e($name) ?></td>
          <td><?= (int)$vals['total'] ?></td>
          <td><?= (int)$vals['completed'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="jr-report-card">
    <div class="jr-report-head"><i class="bi bi-gear"></i> Machine Report</div>
    <table class="jr-mini-table">
      <thead>
        <tr><th>Machine</th><th>Total</th><th>Done</th></tr>
      </thead>
      <tbody>
        <?php if (empty($machineReport)): ?>
        <tr><td colspan="3" class="jr-cell-muted">No machine data</td></tr>
        <?php else: ?>
        <?php foreach (array_slice($machineReport, 0, 10, true) as $name => $vals): ?>
        <tr>
          <td><?= e($name) ?></td>
          <td><?= (int)$vals['total'] ?></td>
          <td><?= (int)$vals['completed'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="jr-report-card">
    <div class="jr-report-head"><i class="bi bi-people"></i> Manpower Report</div>
    <table class="jr-mini-table">
      <thead>
        <tr><th>Operator</th><th>Total</th><th>Done</th></tr>
      </thead>
      <tbody>
        <?php if (empty($manpowerReport)): ?>
        <tr><td colspan="3" class="jr-cell-muted">No manpower data</td></tr>
        <?php else: ?>
        <?php foreach (array_slice($manpowerReport, 0, 10, true) as $name => $vals): ?>
        <tr>
          <td><?= e($name) ?></td>
          <td><?= (int)$vals['total'] ?></td>
          <td><?= (int)$vals['completed'] ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Job table -->
<div class="jr-table-wrap">
  <div class="jr-table-head">
    <h3><i class="bi bi-table"></i> Job Details — <?= e($dateFrom) ?> to <?= e($dateTo) ?></h3>
    <button class="jr-export-btn no-print" onclick="exportCSV()"><i class="bi bi-download"></i> Export CSV</button>
  </div>
  <div style="overflow-x:auto">
    <table class="jr-table" id="jrTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Job No</th>
          <th>Department</th>
          <th>Job Name</th>
          <th>Roll No</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Created</th>
          <th>Started</th>
          <th>Completed</th>
          <th>Duration</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $i => $j):
          $sts = $j['status'];
          $stsClass = match($sts) { 'Completed','QC Passed'=>'completed', 'Running'=>'running', 'Pending'=>'pending', default=>'queued' };
          $dept = $j['report_dept_label'] ?? ($j['job_type'] === 'Slitting' ? 'Slitting' : 'Printing');
          $deptClass = $j['report_dept_class'] ?? ($j['job_type'] === 'Slitting' ? 'jr-dept-slitting' : 'jr-dept-printing');
          $dur = $j['duration_minutes'];
          $durStr = ($dur !== null && $dur > 0) ? floor($dur/60).'h '.$dur%60 .'m' : '—';
        ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td style="font-weight:800"><?= e($j['job_no']) ?></td>
          <td><span class="<?= $deptClass ?>"><?= $dept ?></span></td>
          <td><?= e($j['planning_job_name'] ?? '—') ?></td>
          <td><?= e($j['roll_no'] ?? '—') ?></td>
          <td><span class="jr-badge jr-badge-<?= $stsClass ?>"><?= e($sts) ?></span></td>
          <td><?= e($j['planning_priority'] ?? 'Normal') ?></td>
          <td><?= $j['created_at'] ? date('d M H:i', strtotime($j['created_at'])) : '—' ?></td>
          <td><?= $j['started_at'] ? date('d M H:i', strtotime($j['started_at'])) : '—' ?></td>
          <td><?= $j['completed_at'] ? date('d M H:i', strtotime($j['completed_at'])) : '—' ?></td>
          <td style="font-weight:700"><?= $durStr ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($jobs)): ?>
        <tr><td colspan="11" style="text-align:center;padding:30px;color:#94a3b8">No jobs found for the selected period.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function exportCSV() {
  const table = document.getElementById('jrTable');
  const rows = table.querySelectorAll('tr');
  let csv = [];
  rows.forEach(row => {
    const cells = row.querySelectorAll('th,td');
    const rowData = Array.from(cells).map(c => '"' + c.textContent.replace(/"/g,'""').trim() + '"');
    csv.push(rowData.join(','));
  });
  const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'job_report_<?= $dateFrom ?>_to_<?= $dateTo ?>.csv';
  a.click();
  URL.revokeObjectURL(url);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
