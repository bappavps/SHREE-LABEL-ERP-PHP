<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$pageTitle = 'Production Summary';

function pm_text($value): string {
    return trim((string)($value ?? ''));
}

function pm_department_label($dept, $fallbackType = ''): string {
    $dept = strtolower(trim((string)$dept));
    if ($dept === '') {
        $dept = strtolower(trim((string)$fallbackType));
    }

    $map = [
        'jumbo slitting' => 'Jumbo Slitting',
        'jumbo-slitting' => 'Jumbo Slitting',
        'jumbo_slitting' => 'Jumbo Slitting',
      'jumbo' => 'Jumbo Slitting',
        'printing' => 'Printing',
        'flexo printing' => 'Printing',
        'flexo_printing' => 'Printing',
        'die-cutting' => 'Die-Cutting',
        'die cutting' => 'Die-Cutting',
        'flatbed' => 'Die-Cutting',
        'barcode' => 'Barcode',
        'label-slitting' => 'Label Slitting',
        'label slitting' => 'Label Slitting',
        'packaging' => 'Packaging',
        'packing' => 'Packaging',
        'dispatch' => 'Dispatch',
        'slitting' => 'Slitting',
        'printing_planning' => 'Printing',
        'pos' => 'POS Roll',
        'pos roll' => 'POS Roll',
        'pos_roll' => 'POS Roll',
        'paperroll' => 'Paper Roll',
        'paper roll' => 'Paper Roll',
        'paper_roll' => 'Paper Roll',
        'oneply' => 'One Ply',
        'one ply' => 'One Ply',
        'one_ply' => 'One Ply',
        'paper_roll_1ply' => 'One Ply',
        'twoply' => 'Two Ply',
        'two ply' => 'Two Ply',
        'two_ply' => 'Two Ply',
        'paper_roll_2ply' => 'Two Ply',
    ];

    if (isset($map[$dept])) return $map[$dept];
    if ($dept === '') return 'Not Started';
    return ucwords(str_replace(['_', '-'], ' ', $dept));
}

function pm_bucket_status(array $row): string {
  $jobStatus = strtolower(pm_text(($row['effective_status'] ?? '') ?: ($row['latest_job_status'] ?: $row['active_job_status'])));
    $boardStatus = strtolower(pm_text($row['board_status']));
    $planningStatus = strtolower(pm_text($row['planning_status']));

    if (in_array($jobStatus, ['ready to dispatch', 'ready to dispatched', 'ready to dispathce', 'packed', 'packing done', 'finished barcode', 'finished production'], true)) return 'Packed';

    $haystack = $jobStatus . ' ' . $boardStatus . ' ' . $planningStatus;
    if (strpos($haystack, 'running') !== false || strpos($haystack, 'in progress') !== false) return 'Running';
    if (strpos($haystack, 'pause') !== false || strpos($haystack, 'hold') !== false) return 'On Hold';
    if (
        strpos($haystack, 'completed') !== false ||
        strpos($haystack, 'finalized') !== false ||
        strpos($haystack, 'closed') !== false ||
        strpos($haystack, 'qc passed') !== false ||
        strpos($haystack, 'dispatch') !== false ||
        strpos($haystack, 'finished') !== false ||
        strpos($haystack, 'barcoded') !== false ||
        strpos($haystack, 'packing done') !== false ||
        strpos($haystack, 'packed') !== false
    ) return 'Completed';
    if (strpos($haystack, 'pending') !== false || strpos($haystack, 'queued') !== false || strpos($haystack, 'preparing') !== false) return 'Pending';
    return 'Pending';
}

  function pm_display_status($status): string {
    $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));
    if (in_array($norm, ['ready to dispatch', 'ready to dispatched', 'ready to dispathce', 'packing done', 'packed', 'finished barcode', 'finished production'], true)) {
      return 'Ready to Dispatch';
    }
    if ($norm === 'pending') {
      return 'Pending';
    }
    if (in_array($norm, ['queued', 'running', 'in progress', 'preparing'], true)) {
      return 'Production';
    }
    if ($norm === 'packing') {
      return 'Packing';
    }
    if (in_array($norm, ['finished production', 'dispatched', 'in transit'], true)) {
      return 'Dispatched';
    }
    if ($norm === 'delivered') {
      return 'Delivered';
    }
    return pm_text($status);
  }

  function pm_decode_json_assoc($json): array {
    if (is_array($json)) {
      return $json;
    }
    if (!is_string($json) || trim($json) === '') {
      return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
  }

  function pm_status_priority_from_job($status, array $extra): array {
    $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));

    $finishedFlag = (int)($extra['finished_production_flag'] ?? 0) === 1
      || pm_text($extra['finished_production_at'] ?? '') !== '';
    $packedFlag = (int)($extra['packing_done_flag'] ?? 0) === 1
      || (int)($extra['packing_packed_flag'] ?? 0) === 1
      || pm_text($extra['packing_done_at'] ?? '') !== '';

    if ($finishedFlag || in_array($norm, ['finished production', 'dispatched', 'dispatch', 'shipped'], true)) {
      return [
        'priority' => 5,
        'status' => ($norm === 'dispatched' || $norm === 'dispatch' || $norm === 'shipped') ? 'Dispatched' : 'Ready to Dispatch',
      ];
    }
    if ($packedFlag || in_array($norm, ['packed', 'packing done'], true)) {
      return ['priority' => 4, 'status' => 'Ready to Dispatch'];
    }
    if (in_array($norm, ['completed', 'complete', 'closed', 'finalized', 'qc passed', 'qc failed'], true)) {
      return ['priority' => 3, 'status' => 'Completed'];
    }
    if (in_array($norm, ['running', 'in progress', 'inprogress'], true)) {
      return ['priority' => 2, 'status' => 'Running'];
    }
    return ['priority' => 1, 'status' => pm_display_status($status !== '' ? $status : 'Pending')];
  }
/* ── Delete planning row ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && pm_text($_POST['action'] ?? '') === 'delete_planning') {
    $delId   = (int)($_POST['planning_id'] ?? 0);
    $delCsrf = pm_text($_POST['csrf_token'] ?? '');
    if ($delId > 0 && validateCSRF($delCsrf)) {
        $db->query("UPDATE planning SET deleted_at=NOW() WHERE id=" . $delId);
    }
    $qs = http_build_query(array_filter([
        'q'      => pm_text($_GET['q'] ?? ''),
        'status' => pm_text($_GET['status'] ?? ''),
        'stage'  => pm_text($_GET['stage'] ?? ''),
    ]));
    header('Location: ' . BASE_URL . '/modules/production-manager/index.php' . ($qs ? '?' . $qs : ''));
    exit;
}
$q = pm_text($_GET['q'] ?? '');
$statusFilter = pm_text($_GET['status'] ?? '');
$stageFilter = pm_text($_GET['stage'] ?? '');
if (in_array(strtolower($statusFilter), ['ready to dispatch', 'ready to dispatched', 'ready to dispathce'], true)) {
  $statusFilter = 'Packed';
}

$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(p.job_no LIKE ? OR p.job_name LIKE ? OR p.notes LIKE ? OR a.job_no LIKE ? OR l.job_no LIKE ?)";
    $like = '%' . $q . '%';
    $types .= 'sssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "SELECT
    p.id,
    p.job_no,
    p.job_name,
    p.priority,
    p.status AS planning_status,
    p.department AS planning_department,
    p.notes,
    p.updated_at AS planning_updated_at,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.printing_planning')) ELSE '' END AS board_status,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.department_route')) ELSE '' END AS department_route,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.dispatch_date')) ELSE '' END AS dispatch_date,
    a.job_no AS active_job_no,
    a.department AS active_job_department,
    a.job_type AS active_job_type,
    a.status AS active_job_status,
    a.updated_at AS active_job_updated_at,
    l.job_no AS latest_job_no,
    l.department AS latest_job_department,
    l.job_type AS latest_job_type,
    l.status AS latest_job_status,
    l.updated_at AS latest_job_updated_at,
    l.completed_at AS latest_job_completed_at,
    js.total_jobs,
    js.completed_jobs,
    js.running_jobs,
    js.pending_jobs,
    js.chain_summary,
    js.last_job_at
FROM planning p
LEFT JOIN (
    SELECT j1.*
    FROM jobs j1
    INNER JOIN (
        SELECT planning_id, MAX(id) AS max_id
        FROM jobs
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
          AND status IN ('Running','Pending','Queued')
        GROUP BY planning_id
    ) x ON x.max_id = j1.id
) a ON a.planning_id = p.id
LEFT JOIN (
    SELECT j2.*
    FROM jobs j2
    INNER JOIN (
        SELECT planning_id, MAX(id) AS max_id
        FROM jobs
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        GROUP BY planning_id
    ) y ON y.max_id = j2.id
) l ON l.planning_id = p.id
LEFT JOIN (
    SELECT
        planning_id,
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN status IN ('Completed','Closed','Finalized','QC Passed') THEN 1 ELSE 0 END) AS completed_jobs,
        SUM(CASE WHEN status = 'Running' THEN 1 ELSE 0 END) AS running_jobs,
        SUM(CASE WHEN status IN ('Pending','Queued') THEN 1 ELSE 0 END) AS pending_jobs,
        GROUP_CONCAT(CONCAT(COALESCE(NULLIF(department, ''), job_type), ':', status) ORDER BY id SEPARATOR ' | ') AS chain_summary,
        MAX(updated_at) AS last_job_at
    FROM jobs
    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
    GROUP BY planning_id
) js ON js.planning_id = p.id";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT 500';

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$rows = [];
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
}

$effectiveStatusByPlanning = [];
if (!empty($rows)) {
  $planningIds = array_values(array_unique(array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
  }, $rows)));
  $planningIds = array_values(array_filter($planningIds, static function ($id) {
    return $id > 0;
  }));

  if (!empty($planningIds)) {
    $in = implode(',', array_fill(0, count($planningIds), '?'));
    $types2 = str_repeat('i', count($planningIds));
    $jobSql = "SELECT planning_id, status, extra_data, updated_at, id
           FROM jobs
           WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
           AND planning_id IN ($in)
           ORDER BY planning_id ASC, id ASC";
    $jobStmt = $db->prepare($jobSql);
    if ($jobStmt) {
      $jobStmt->bind_param($types2, ...$planningIds);
      $jobStmt->execute();
      $jobRes = $jobStmt->get_result();
      if ($jobRes instanceof mysqli_result) {
        while ($jobRow = $jobRes->fetch_assoc()) {
          $pid = (int)($jobRow['planning_id'] ?? 0);
          if ($pid <= 0) {
            continue;
          }
          $extra = pm_decode_json_assoc($jobRow['extra_data'] ?? null);
          $evaluated = pm_status_priority_from_job((string)($jobRow['status'] ?? ''), $extra);
          $priority = (int)($evaluated['priority'] ?? 0);
          $statusText = pm_text($evaluated['status'] ?? '');
          $updatedAt = pm_text($jobRow['updated_at'] ?? '');
          $jobId = (int)($jobRow['id'] ?? 0);

          if (!isset($effectiveStatusByPlanning[$pid])) {
            $effectiveStatusByPlanning[$pid] = [
              'priority' => $priority,
              'status' => $statusText,
              'updated_at' => $updatedAt,
              'job_id' => $jobId,
            ];
            continue;
          }

          $current = $effectiveStatusByPlanning[$pid];
          $shouldReplace = false;
          if ($priority > (int)$current['priority']) {
            $shouldReplace = true;
          } elseif ($priority === (int)$current['priority']) {
            if ($updatedAt !== '' && ($current['updated_at'] === '' || strcmp($updatedAt, (string)$current['updated_at']) >= 0)) {
              $shouldReplace = true;
            } elseif ($updatedAt === (string)$current['updated_at'] && $jobId >= (int)$current['job_id']) {
              $shouldReplace = true;
            }
          }

          if ($shouldReplace) {
            $effectiveStatusByPlanning[$pid] = [
              'priority' => $priority,
              'status' => $statusText,
              'updated_at' => $updatedAt,
              'job_id' => $jobId,
            ];
          }
        }
      }
      $jobStmt->close();
    }
  }
}

$filtered = [];
foreach ($rows as $row) {
  $planningId = (int)($row['id'] ?? 0);
  if ($planningId > 0 && isset($effectiveStatusByPlanning[$planningId]['status'])) {
    $row['effective_status'] = pm_text($effectiveStatusByPlanning[$planningId]['status']);
  }

  $currentDept = pm_department_label($row['latest_job_department'] ?: $row['active_job_department'] ?: $row['planning_department'], $row['latest_job_type'] ?: $row['active_job_type']);
    $bucket = pm_bucket_status($row);

    if ($statusFilter !== '') {
      if (strcasecmp($statusFilter, 'Completed') === 0) {
        if (!in_array($bucket, ['Completed', 'Packed'], true)) {
          continue;
        }
      } elseif (strcasecmp($bucket, $statusFilter) !== 0) {
        continue;
      }
    }
    if ($stageFilter !== '' && stripos($currentDept, $stageFilter) === false) {
        continue;
    }

    $row['current_department_label'] = $currentDept;
    $row['bucket_status'] = $bucket;
    $filtered[] = $row;
}

$total = count($filtered);
$running = 0;
$pending = 0;
$completed = 0;
$hold = 0;

foreach ($filtered as $row) {
    $bucket = $row['bucket_status'];
    if ($bucket === 'Running') $running++;
    elseif ($bucket === 'Pending') $pending++;
  elseif ($bucket === 'Completed' || $bucket === 'Packed') $completed++;
    elseif ($bucket === 'On Hold') $hold++;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production Summary</span>
</div>

<style>
:root{
  --pm-ink:#1e293b;
  --pm-slate:#64748b;
  --pm-border:#e2e8f0;
  --pm-surface:#f8fafc;
  --pm-brand:#6366f1;
  --pm-accent:#f59e0b;
}
body{background:#f1f5f9}
.pm-wrap{
  display:flex;
  flex-direction:column;
  gap:16px;
  position:relative;
}
.pm-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
  background:linear-gradient(120deg,#4f46e5 0%,#7c3aed 50%,#a21caf 100%);
  padding:22px 26px;
  border-radius:18px;
  color:#fff;
  box-shadow:0 12px 32px rgba(99,102,241,.28);
}
.pm-head h1{margin:0;font-size:1.45rem;font-weight:900;letter-spacing:.01em}
.pm-head p{margin:6px 0 0;opacity:.88;font-size:.83rem;max-width:680px}
/* ── Stat Cards ── */
.pm-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px}
.pm-stat{
  border-radius:14px;
  padding:14px 16px;
  border:1.5px solid transparent;
  box-shadow:0 4px 16px rgba(0,0,0,.07);
}
.pm-stat-total{background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-color:#c4b5fd}
.pm-stat-total .k{color:#5b21b6}
.pm-stat-total .v{color:#4c1d95}
.pm-stat-running{background:linear-gradient(135deg,#dbeafe,#bfdbfe);border-color:#93c5fd}
.pm-stat-running .k{color:#1d4ed8}
.pm-stat-running .v{color:#1e3a8a}
.pm-stat-pending{background:linear-gradient(135deg,#fef9c3,#fde68a);border-color:#fcd34d}
.pm-stat-pending .k{color:#92400e}
.pm-stat-pending .v{color:#78350f}
.pm-stat-hold{background:linear-gradient(135deg,#fee2e2,#fecaca);border-color:#f87171}
.pm-stat-hold .k{color:#991b1b}
.pm-stat-hold .v{color:#7f1d1d}
.pm-stat-done{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-color:#6ee7b7}
.pm-stat-done .k{color:#065f46}
.pm-stat-done .v{color:#064e3b}
.pm-stat .k{display:block;font-size:.67rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
.pm-stat .v{display:block;font-size:1.5rem;font-weight:900;line-height:1.15}
/* ── Filters ── */
.pm-filters{
  display:grid;
  grid-template-columns:2fr 1fr 1fr auto;
  gap:10px;
  background:#fff;
  border:1.5px solid var(--pm-border);
  padding:12px;
  border-radius:14px;
  box-shadow:0 2px 8px rgba(0,0,0,.05);
}
.pm-filters input,.pm-filters select{
  width:100%;
  border:1.5px solid #e2e8f0;
  background:#f8fafc;
  border-radius:10px;
  padding:9px 11px;
  font-size:.86rem;
  color:var(--pm-ink);
}
.pm-filters input:focus,.pm-filters select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.pm-filters button{
  border:0;
  border-radius:10px;
  background:linear-gradient(120deg,#6366f1,#7c3aed);
  color:#fff;
  font-weight:800;
  padding:9px 16px;
  cursor:pointer;
  font-size:.86rem;
}
.pm-filters button:hover{background:linear-gradient(120deg,#4f46e5,#6d28d9)}
/* ── Table ── */
.pm-table{
  background:#fff;
  border:1.5px solid var(--pm-border);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 6px 24px rgba(0,0,0,.07);
}
.pm-table-wrap{overflow:auto;max-height:68vh}
.pm-table table{width:100%;border-collapse:separate;border-spacing:0;min-width:1320px}
.pm-table th,.pm-table td{padding:9px 11px;border-bottom:1px solid #f1f5f9;font-size:.8rem;vertical-align:top}
.pm-table th{
  background:linear-gradient(180deg,#f8faff 0%,#f1f5f9 100%);
  text-transform:uppercase;
  font-size:.63rem;
  letter-spacing:.09em;
  color:#475569;
  font-weight:800;
  text-align:left;
  position:sticky;
  top:0;
  z-index:1;
  border-bottom:2px solid #e2e8f0;
}
/* Per-row pastel bands */
.pm-table tbody tr.rc0 td{background:#f0f4ff}
.pm-table tbody tr.rc1 td{background:#f0fdf4}
.pm-table tbody tr.rc2 td{background:#fdf4ff}
.pm-table tbody tr.rc3 td{background:#fffbeb}
.pm-table tbody tr.rc4 td{background:#fff1f2}
.pm-table tbody tr.rc5 td{background:#f0fdfa}
.pm-table tbody tr.rc0:hover td{background:#dde8ff}
.pm-table tbody tr.rc1:hover td{background:#dcfce7}
.pm-table tbody tr.rc2:hover td{background:#f3e8ff}
.pm-table tbody tr.rc3:hover td{background:#fef3c7}
.pm-table tbody tr.rc4:hover td{background:#ffe4e6}
.pm-table tbody tr.rc5:hover td{background:#ccfbf1}
/* Badges */
.pm-badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.65rem;font-weight:800;letter-spacing:.03em;border:1.5px solid transparent}
.pm-badge.pending{background:#fff7ed;color:#c2410c;border-color:#fb923c}
.pm-badge.running{background:#eff6ff;color:#1d4ed8;border-color:#60a5fa}
.pm-badge.completed{background:#f0fdf4;color:#15803d;border-color:#4ade80}
.pm-badge.hold{background:#fff1f2;color:#be123c;border-color:#fb7185}
/* Planning status badge */
.pm-ps-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:.62rem;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.pm-ps-badge.ps-open{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.pm-ps-badge.ps-closed{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
.pm-ps-badge.ps-dispatch{background:#fdf4ff;color:#7e22ce;border-color:#e9d5ff}
.pm-muted{color:#94a3b8;font-size:.73rem;margin-top:2px}
.pm-chain{max-width:340px;white-space:normal;line-height:1.45;color:#334155;font-size:.75rem}
.pm-link{
  display:inline-block;
  padding:5px 10px;
  border-radius:8px;
  background:linear-gradient(120deg,#e0e7ff,#ede9fe);
  color:#3730a3;
  text-decoration:none;
  font-size:.71rem;
  font-weight:800;
  border:1px solid #c7d2fe;
}
.pm-link:hover{background:linear-gradient(120deg,#c7d2fe,#ddd6fe);color:#312e81}
@media (max-width:980px){
  .pm-head{flex-direction:column;align-items:flex-start}
  .pm-stats{grid-template-columns:repeat(2,minmax(120px,1fr))}
  .pm-filters{grid-template-columns:1fr}
}
</style>

<div class="pm-wrap">
  <section class="pm-head">
    <div>
      <h1><i class="bi bi-kanban"></i> Production Summary Console</h1>
      <p>All production visibility in one view: planning, job cards, current stage, progress, and full journey snapshot.</p>
    </div>
    <a class="pm-link" href="<?= BASE_URL ?>/modules/live/index.php">Live Floor View</a>
  </section>

  <section class="pm-stats">
    <div class="pm-stat pm-stat-total"><span class="k">📊 Total Plans</span><span class="v"><?= (int)$total ?></span></div>
    <div class="pm-stat pm-stat-running"><span class="k">🔵 Running</span><span class="v"><?= (int)$running ?></span></div>
    <div class="pm-stat pm-stat-pending"><span class="k">🟡 Pending</span><span class="v"><?= (int)$pending ?></span></div>
    <div class="pm-stat pm-stat-hold"><span class="k">🔴 On Hold</span><span class="v"><?= (int)$hold ?></span></div>
    <div class="pm-stat pm-stat-done"><span class="k">✅ Completed</span><span class="v"><?= (int)$completed ?></span></div>
  </section>

  <form class="pm-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by planning/job no, job name, notes">
    <select name="status">
      <option value="">All Status</option>
      <?php foreach (['Pending','Running','On Hold','Packed','Completed'] as $opt): ?>
        <option value="<?= e($opt) ?>" <?= strcasecmp($statusFilter, $opt) === 0 ? 'selected' : '' ?>><?= e($opt === 'Packed' ? 'Ready to Dispatch' : $opt) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="stage" value="<?= e($stageFilter) ?>" placeholder="Filter stage (e.g. Barcode)">
    <button type="submit"><i class="bi bi-funnel"></i> Filter</button>
  </form>

  <section class="pm-table">
    <div class="pm-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Serial No</th>
            <th>Planning No</th>
            <th>Job No</th>
            <th>Job Name</th>
            <th>Priority</th>
            <th>Production Status</th>
            <th>Current Stage</th>
            <th>Current Position</th>
            <th>Previous Active Card</th>
            <th>Latest Job Card</th>
            <th>Progress</th>
            <th>Department Route</th>
            <th>Chain Snapshot</th>
            <th>Last Update</th>
            <th>Details</th>
            <th></th>
          </tr>
        <tbody>
        <?php if (empty($filtered)): ?>
          <tr><td colspan="15" class="pm-muted">No data found for selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($filtered as $idx => $row): ?>
            <?php
              $rowColorClass = 'rc' . ($idx % 6);
              $bucket = (string)$row['bucket_status'];
              $bucketClass = 'pending';
              if ($bucket === 'Running') $bucketClass = 'running';
              elseif ($bucket === 'Completed' || $bucket === 'Packed') $bucketClass = 'completed';
              elseif ($bucket === 'On Hold') $bucketClass = 'hold';

              $activeJobNo = pm_text($row['active_job_no']);
              $latestJobNo = pm_text($row['latest_job_no']);
              $planNo = pm_text($row['job_no']);
              $prevActiveJobNo = '-';
              if ($activeJobNo !== '' && $latestJobNo !== '' && strcasecmp($activeJobNo, $latestJobNo) !== 0) {
                  $prevActiveJobNo = $activeJobNo;
              } elseif ($activeJobNo !== '' && $latestJobNo === '') {
                  $prevActiveJobNo = $activeJobNo;
              }
              $viewJobNo = $latestJobNo !== '' ? $latestJobNo : ($activeJobNo !== '' ? $activeJobNo : $planNo);
              $planningNo = $planNo !== '' ? $planNo : ('PLAN-' . (int)$row['id']);
              $serialNo = (int)$idx + 1;

              $curPos = pm_text($row['latest_job_status']);
              if ($curPos === '') $curPos = pm_text($row['active_job_status']);
              if ($curPos === '') $curPos = pm_text($row['board_status']);
              if ($curPos === '') $curPos = pm_text($row['planning_status']);
                if (pm_text($row['effective_status'] ?? '') !== '') {
                  $curPos = pm_text($row['effective_status']);
                }
              $curPos = pm_display_status($curPos);

              $route = pm_text($row['department_route']);
              if ($route === '') $route = pm_text($row['planning_department']);

              $lastAt = pm_text($row['latest_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['active_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['last_job_at']);
              if ($lastAt === '') $lastAt = pm_text($row['planning_updated_at']);
            ?>
            <tr class="<?= $rowColorClass ?>">
              <td><strong><?= (int)$serialNo ?></strong></td>
              <td><strong><?= e($planningNo) ?></strong><div class="pm-muted">ID: <?= (int)$row['id'] ?></div></td>
              <td><strong><?= e($viewJobNo !== '' ? $viewJobNo : '-') ?></strong></td>
              <td><?= e(pm_text($row['job_name']) !== '' ? $row['job_name'] : '-') ?></td>
              <td><?= e(pm_text($row['priority']) !== '' ? $row['priority'] : 'Normal') ?></td>
              <td>
                <span class="pm-badge <?= e($bucketClass) ?>"><?= e(pm_display_status($bucket)) ?></span>
                <?php if (pm_text($row['board_status']) !== ''): ?>
                  <div class="pm-muted">Board: <?= e(pm_display_status($row['board_status'])) ?></div>
                <?php endif; ?>
              </td>
              <td><strong><?= e($row['current_department_label']) ?></strong></td>
              <td><?= e($curPos !== '' ? $curPos : '-') ?></td>
              <td><?= e($prevActiveJobNo) ?></td>
              <td><?= e($latestJobNo !== '' ? $latestJobNo : '-') ?></td>
              <td>
                <?php $totalJobs = (int)($row['total_jobs'] ?? 0); $doneJobs = (int)($row['completed_jobs'] ?? 0); ?>
                <strong><?= $doneJobs ?>/<?= $totalJobs ?></strong>
                <div class="pm-muted">Running: <?= (int)($row['running_jobs'] ?? 0) ?> | Pending: <?= (int)($row['pending_jobs'] ?? 0) ?></div>
              </td>
              <td><?= e($route !== '' ? $route : '-') ?></td>
              <td class="pm-chain"><?= e(pm_text($row['chain_summary']) !== '' ? $row['chain_summary'] : '-') ?></td>
              <td><?= e($lastAt !== '' ? $lastAt : '-') ?></td>
              <td>
                <?php if ($viewJobNo !== ''): ?>
                  <a class="pm-link" href="<?= BASE_URL ?>/modules/scan/dossier.php?jn=<?= urlencode($viewJobNo) ?>" target="_blank" rel="noopener">Full Details</a>
                <?php else: ?>
                  <span class="pm-muted">No link</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center">
                <button type="button" class="pm-del-btn" data-id="<?= (int)$row['id'] ?>" data-label="<?= e($planningNo) ?>" title="Delete planning row"><i class="bi bi-trash"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<!-- Delete confirm modal -->
<div id="pm-del-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 28px 20px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 8px;font-size:1rem;color:#0f172a"><i class="bi bi-exclamation-triangle-fill" style="color:#ef4444"></i> Delete Planning Row?</h3>
    <p style="margin:0 0 18px;font-size:.86rem;color:#475569">This will soft-delete <strong id="pm-del-label"></strong>. Job cards linked to it will remain intact.</p>
    <form method="POST" id="pm-del-form">
      <input type="hidden" name="action" value="delete_planning">
      <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
      <input type="hidden" name="planning_id" id="pm-del-id" value="">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
      <input type="hidden" name="stage" value="<?= e($stageFilter) ?>">
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" id="pm-del-cancel" class="btn btn-light">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>

<style>
.pm-del-btn{
  border:none;background:none;cursor:pointer;
  color:#94a3b8;padding:4px 6px;border-radius:7px;
  font-size:.9rem;transition:color .15s,background .15s;
}
.pm-del-btn:hover{color:#ef4444;background:#fee2e2}
</style>

<script>
(function(){
  var modal  = document.getElementById('pm-del-modal');
  var delId  = document.getElementById('pm-del-id');
  var delLbl = document.getElementById('pm-del-label');
  document.querySelectorAll('.pm-del-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      delId.value  = btn.dataset.id;
      delLbl.textContent = btn.dataset.label;
      modal.style.display = 'flex';
    });
  });
  document.getElementById('pm-del-cancel').addEventListener('click', function(){
    modal.style.display = 'none';
  });
  modal.addEventListener('click', function(e){ if(e.target===modal) modal.style.display='none'; });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
