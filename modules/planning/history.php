<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$isUserAdmin = isAdmin();
$csrfToken = generateCSRF();

function planning_history_department_options() {
  return ['label-printing', 'printing', 'slitting', 'packing', 'dispatch', 'general', 'barcode', 'paperroll', 'one_ply', 'two_ply'];
}

function planning_history_department_label($department) {
    $department = trim((string)$department);
    if ($department === '') return 'General';
  if ($department === 'one_ply') return 'One Ply';
  if ($department === 'two_ply') return 'Two Ply';
  if ($department === 'paperroll') return 'PosRoll';
    return ucwords(str_replace('-', ' ', $department));
}

function planning_history_board_url($department) {
  $department = trim((string)$department);
  if ($department === 'barcode') {
    return appUrl('modules/planning/barcode/index.php');
  }
  if ($department === 'paperroll') {
    return appUrl('modules/planning/paperroll/index.php');
  }
  if ($department === 'one_ply') {
    return appUrl('modules/planning/oneply/index.php');
  }
  if ($department === 'two_ply') {
    return appUrl('modules/planning/twoply/index.php');
  }
  return appUrl('modules/planning/index.php?department=' . rawurlencode($department));
}

function planning_history_department_where($department, $alias = 'p') {
  $department = trim((string)$department);
  $prefix = trim((string)$alias);
  $prefix = $prefix !== '' ? ($prefix . '.') : '';
  if ($department === 'one_ply') {
    return [
      'sql' => "LOWER(COALESCE({$prefix}department, '')) = 'paperroll' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT({$prefix}extra_data, '$.planning_type'))) = 'one_ply'",
      'types' => '',
      'params' => [],
    ];
  }
  if ($department === 'two_ply') {
    return [
      'sql' => "LOWER(COALESCE({$prefix}department, '')) = 'paperroll' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT({$prefix}extra_data, '$.planning_type'))) = 'two_ply'",
      'types' => '',
      'params' => [],
    ];
  }
  return [
    'sql' => "{$prefix}department = ?",
    'types' => 's',
    'params' => [$department],
  ];
}

function planning_history_try_parse_date($val) {
    $s = trim((string)$val);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^\d{2}[\.\/-]\d{2}[\.\/-]\d{2,4}$/', $s)) {
        $parts = preg_split('/[\.\/-]/', $s);
        if (count($parts) === 3) {
            $y = strlen($parts[2]) === 2 ? ('20' . $parts[2]) : $parts[2];
            return sprintf('%04d-%02d-%02d', (int)$y, (int)$parts[1], (int)$parts[0]);
        }
    }
    return '';
}

function planning_history_dispatch_date(array $row, array $extra) {
    $dispatch = planning_history_try_parse_date($extra['dispatch_date'] ?? '');
    if ($dispatch !== '') return $dispatch;
    $scheduled = planning_history_try_parse_date($row['scheduled_date'] ?? '');
    if ($scheduled !== '') return $scheduled;
    $orderDate = planning_history_try_parse_date($extra['order_date'] ?? ($row['order_date'] ?? ''));
    if ($orderDate === '') return '';
    try {
        return (new DateTimeImmutable($orderDate))->modify('+10 days')->format('Y-m-d');
    } catch (Exception $e) {
        return '';
    }
}

function planning_history_status_label($value) {
  $raw = trim((string)$value);
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', $raw)));
  if (in_array($norm, ['finished', 'finished production', 'packed', 'dispatched', 'complete'], true)) {
    return 'Finished Production';
  }
  if (in_array($norm, ['finished barcode', 'finised barcode'], true)) {
    return 'Finished Barcode';
  }
  // If row is archived via linked job state but planning status is old, show terminal label.
  return 'Finished Production';
}

$department = trim((string)($_GET['department'] ?? 'label-printing'));
if ($department === '') $department = 'label-printing';
$deptOptions = planning_history_department_options();
if (!in_array($department, $deptOptions, true)) {
  $department = 'label-printing';
}
$day = max(0, min(31, (int)($_GET['day'] ?? 0)));
$month = max(0, min(12, (int)($_GET['month'] ?? 0)));
$year = max(0, (int)($_GET['year'] ?? 0));
$queryBase = [
  'department' => $department,
  'day' => $day,
  'month' => $month,
  'year' => $year,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  if ($action === 'delete_history_planning') {
    if (!$isUserAdmin) {
      setFlash('error', 'Access denied. Admin only.');
    } elseif (!verifyCSRF($_POST['csrf_token'] ?? '')) {
      setFlash('error', 'Invalid security token.');
    } else {
      $deleteId = (int)($_POST['id'] ?? 0);
      if ($deleteId > 0) {
        $del = $db->prepare('DELETE FROM planning WHERE id = ? LIMIT 1');
        if ($del) {
          $del->bind_param('i', $deleteId);
          $del->execute();
          if ($del->affected_rows > 0) {
            setFlash('success', 'Planning row deleted from history.');
          } else {
            setFlash('error', 'Planning row not found or already deleted.');
          }
          $del->close();
        } else {
          setFlash('error', 'Could not prepare delete operation.');
        }
      } else {
        setFlash('error', 'Invalid planning id.');
      }
    }
    redirect(appUrl('modules/planning/history.php?' . http_build_query($queryBase)));
  }
}

$deptWhere = planning_history_department_where($department);
$archivedStatusSql = "LOWER(TRIM(REPLACE(REPLACE(COALESCE(p.status, ''), '-', ' '), '_', ' '))) IN ('finished','finished production','finished barcode','finised barcode','packed','dispatched','complete')";
$archivedJobsSql = "EXISTS (
    SELECT 1 FROM jobs j
    WHERE j.planning_id = p.id
      AND (
        LOWER(TRIM(REPLACE(REPLACE(COALESCE(j.status, ''), '-', ' '), '_', ' '))) IN ('finished','finished production','finished barcode','packed','dispatched','complete','closed','finalized','completed','qc passed')
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_production_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_barcode_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_done_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_packed_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_production_at\":%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_barcode_at\":%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_done_at\":%'
      )
  )";
$where = [$deptWhere['sql'], "($archivedStatusSql OR $archivedJobsSql)"];
$types = (string)($deptWhere['types'] ?? '');
$params = (array)($deptWhere['params'] ?? []);
$dateExpr = "COALESCE(NULLIF(p.updated_at, '0000-00-00 00:00:00'), p.created_at)";
if ($day > 0) {
    $where[] = "DAY($dateExpr) = ?";
    $types .= 'i';
    $params[] = $day;
}
if ($month > 0) {
    $where[] = "MONTH($dateExpr) = ?";
    $types .= 'i';
    $params[] = $month;
}
if ($year > 0) {
    $where[] = "YEAR($dateExpr) = ?";
    $types .= 'i';
    $params[] = $year;
}

$sql = "SELECT p.*, so.order_no, so.client_name
    FROM planning p
    LEFT JOIN sales_orders so ON so.id = p.sales_order_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $dateExpr DESC, p.id DESC";
$stmt = $db->prepare($sql);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$linkedJobNoByPlanningId = [];
$planningIds = [];
foreach ($rows as $historyRow) {
  $pid = (int)($historyRow['id'] ?? 0);
  if ($pid > 0) {
    $planningIds[] = $pid;
  }
}
$planningIds = array_values(array_unique($planningIds));
if (!empty($planningIds)) {
  $ph = implode(',', array_fill(0, count($planningIds), '?'));
  $typesJobs = str_repeat('i', count($planningIds));
  $jobLinkSql = "SELECT planning_id, job_no, sequence_order, completed_at, updated_at, created_at, id
    FROM jobs
    WHERE planning_id IN ($ph)
      AND TRIM(COALESCE(job_no, '')) <> ''
      AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
    ORDER BY planning_id ASC,
      COALESCE(sequence_order, 0) DESC,
      COALESCE(NULLIF(completed_at, '0000-00-00 00:00:00'), NULLIF(updated_at, '0000-00-00 00:00:00'), created_at) DESC,
      id DESC";
  $jobLinkStmt = $db->prepare($jobLinkSql);
  if ($jobLinkStmt) {
    $jobLinkStmt->bind_param($typesJobs, ...$planningIds);
    $jobLinkStmt->execute();
    foreach ($jobLinkStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $jobLinkRow) {
      $pid = (int)($jobLinkRow['planning_id'] ?? 0);
      if ($pid <= 0 || isset($linkedJobNoByPlanningId[$pid])) {
        continue;
      }
      $jobNo = trim((string)($jobLinkRow['job_no'] ?? ''));
      if ($jobNo !== '') {
        $linkedJobNoByPlanningId[$pid] = $jobNo;
      }
    }
    $jobLinkStmt->close();
  }
}

$printSummary = strtolower(trim((string)($_GET['print_summary'] ?? '')));
if (!in_array($printSummary, ['weekly', 'monthly'], true)) {
  $printSummary = '';
}

if ($printSummary !== '') {
  $bucket = [];
  foreach ($rows as $row) {
    $archivedOn = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
    $ts = $archivedOn !== '' ? strtotime($archivedOn) : false;
    if ($ts === false) {
      continue;
    }
    if ($printSummary === 'weekly') {
      $key = date('o-\\WW', $ts);
      $label = 'Week ' . date('W, Y', $ts);
    } else {
      $key = date('Y-m', $ts);
      $label = date('F Y', $ts);
    }
    if (!isset($bucket[$key])) {
      $bucket[$key] = ['label' => $label, 'count' => 0];
    }
    $bucket[$key]['count']++;
  }
  krsort($bucket);

  $title = $printSummary === 'weekly' ? 'Weekly Summary' : 'Monthly Summary';
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title><?= e('Planning History ' . $title) ?></title>
    <style>
      body{font-family:Arial,sans-serif;padding:20px;color:#0f172a}
      .head{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:14px}
      .muted{color:#64748b;font-size:12px}
      table{width:100%;border-collapse:collapse}
      th,td{border:1px solid #dbe5f0;padding:8px 10px;text-align:left}
      th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
      .count{font-weight:700}
    </style>
  </head>
  <body>
    <div class="head">
      <div>
        <h2 style="margin:0 0 6px">Planning History <?= e($title) ?></h2>
        <div class="muted">Department: <?= e(planning_history_department_label($department)) ?></div>
      </div>
      <div class="muted">Printed: <?= e(date('d M Y h:i A')) ?></div>
    </div>
    <table>
      <thead>
        <tr>
          <th><?= $printSummary === 'weekly' ? 'Week' : 'Month' ?></th>
          <th>Total Archived Planning</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bucket)): ?>
          <tr><td colspan="2">No data found for this filter.</td></tr>
        <?php else: ?>
          <?php foreach ($bucket as $row): ?>
            <tr>
              <td><?= e($row['label']) ?></td>
              <td class="count"><?= (int)$row['count'] ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <script>window.onload=function(){window.print();};</script>
  </body>
  </html>
  <?php
  exit;
}

$years = [];
$yearWhere = planning_history_department_where($department, '');
$yearArchivedStatusSql = "LOWER(TRIM(REPLACE(REPLACE(COALESCE(p.status, ''), '-', ' '), '_', ' '))) IN ('finished','finished production','finished barcode','finised barcode','packed','dispatched','complete')";
$yearArchivedJobsSql = "EXISTS (
    SELECT 1 FROM jobs j
    WHERE j.planning_id = p.id
      AND (
        LOWER(TRIM(REPLACE(REPLACE(COALESCE(j.status, ''), '-', ' '), '_', ' '))) IN ('finished','finished production','finished barcode','packed','dispatched','complete','closed','finalized','completed','qc passed')
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_production_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_barcode_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_done_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_packed_flag\":1%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_production_at\":%'
        OR COALESCE(j.extra_data, '') LIKE '%\"finished_barcode_at\":%'
        OR COALESCE(j.extra_data, '') LIKE '%\"packing_done_at\":%'
      )
  )";
$yearSql = "SELECT DISTINCT YEAR(COALESCE(NULLIF(p.updated_at, '0000-00-00 00:00:00'), p.created_at)) AS y
    FROM planning p
    WHERE " . $yearWhere['sql'] . " AND ($yearArchivedStatusSql OR $yearArchivedJobsSql)
  ORDER BY y DESC";
$yearStmt = $db->prepare($yearSql);
if ((string)$yearWhere['types'] !== '') {
  $yearStmt->bind_param($yearWhere['types'], ...$yearWhere['params']);
}
$yearStmt->execute();
foreach ($yearStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $yr) {
    $y = (int)($yr['y'] ?? 0);
    if ($y > 0) $years[] = $y;
}
if (empty($years)) $years[] = (int)date('Y');

$boardUrl = planning_history_board_url($department);
$historyUrlBase = appUrl('modules/planning/history.php');
$historyFilterUrl = appUrl('modules/planning/history.php?' . http_build_query($queryBase));
$weeklyPrintUrl = appUrl('modules/planning/history.php?' . http_build_query(array_merge($queryBase, ['print_summary' => 'weekly'])));
$monthlyPrintUrl = appUrl('modules/planning/history.php?' . http_build_query(array_merge($queryBase, ['print_summary' => 'monthly'])));
$flash = getFlash();
$pageTitle = 'Planning History';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="<?= e(appUrl('modules/planning/index.php')) ?>">Planning</a><span class="breadcrumb-sep">›</span>
  <span>History</span>
</div>

<div class="planning-view-switch">
  <a href="<?= e($boardUrl) ?>" class="planning-view-link"><i class="bi bi-grid-1x2"></i> Board</a>
  <a href="<?= e(appUrl('modules/planning/history.php?department=' . rawurlencode($department))) ?>" class="planning-view-link is-active"><i class="bi bi-clock-history"></i> History</a>
</div>

<div class="page-header">
  <div>
    <h1>Planning History</h1>
    <p>Only planning rows marked as Finished appear here after dispatch completion.</p>
  </div>
  <div class="history-summary-pill"><i class="bi bi-archive"></i> <?= count($rows) ?> archived rows</div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type'] ?? 'info') ?>" style="margin-bottom:12px"><?= e($flash['message'] ?? '') ?></div>
<?php endif; ?>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">Filters</span>
    <span class="badge badge-consumed"><?= e(planning_history_department_label($department)) ?></span>
  </div>
  <form method="get" action="<?= e($historyUrlBase) ?>" class="planning-history-filters">
    <div>
      <label>Department</label>
      <select name="department" class="form-control">
        <?php foreach ($deptOptions as $opt): ?>
          <option value="<?= e($opt) ?>" <?= $opt === $department ? 'selected' : '' ?>><?= e(planning_history_department_label($opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Day</label>
      <select name="day" class="form-control">
        <option value="0">All Days</option>
        <?php for ($i = 1; $i <= 31; $i++): ?>
          <option value="<?= $i ?>" <?= $day === $i ? 'selected' : '' ?>><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label>Month</label>
      <select name="month" class="form-control">
        <option value="0">All Months</option>
        <?php for ($i = 1; $i <= 12; $i++): ?>
          <option value="<?= $i ?>" <?= $month === $i ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $i, 1)) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label>Year</label>
      <select name="year" class="form-control">
        <option value="0">All Years</option>
        <?php foreach ($years as $yr): ?>
          <option value="<?= (int)$yr ?>" <?= $year === (int)$yr ? 'selected' : '' ?>><?= (int)$yr ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="planning-history-filter-actions">
      <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Apply</button>
      <a href="<?= e(appUrl('modules/planning/history.php?department=' . rawurlencode($department))) ?>" class="btn btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
      <a href="<?= e($weeklyPrintUrl) ?>" class="btn btn-ghost" target="_blank"><i class="bi bi-printer"></i> Print Weekly</a>
      <a href="<?= e($monthlyPrintUrl) ?>" class="btn btn-ghost" target="_blank"><i class="bi bi-printer"></i> Print Monthly</a>
    </div>
  </form>
</div>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">Archived Planning Jobs</span>
    <small class="text-muted">Filtered by Finished date using the row update timestamp.</small>
  </div>
  <div class="table-wrap">
    <table class="planning-history-table">
      <thead>
        <tr>
          <th>Archived On</th>
          <th>Job No</th>
          <th>Job Name</th>
          <th>Client</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Order Date</th>
          <th>Dispatch Date</th>
          <th>Remarks</th>
          <?php if ($isUserAdmin): ?><th class="no-print">Action</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="<?= $isUserAdmin ? '10' : '9' ?>" class="table-empty"><i class="bi bi-inbox"></i>No finished planning jobs found for this filter.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
              if (!is_array($extra)) $extra = [];
              $jobName = trim((string)($extra['name'] ?? $row['job_name'] ?? ''));
              $priority = trim((string)($row['priority'] ?? $extra['priority'] ?? 'Normal')) ?: 'Normal';
              $orderDate = trim((string)($extra['order_date'] ?? $row['order_date'] ?? ''));
              $dispatchDate = planning_history_dispatch_date($row, $extra);
              $remarks = trim((string)($extra['remarks'] ?? $row['notes'] ?? ''));
              $archivedOn = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
              $planningId = (int)($row['id'] ?? 0);
              $linkedJobNo = trim((string)($linkedJobNoByPlanningId[$planningId] ?? ''));
              $jobCardUrl = $linkedJobNo !== '' ? appUrl('modules/scan/dossier.php?jn=' . rawurlencode($linkedJobNo)) : '';
            ?>
            <tr>
              <td><?= e($archivedOn !== '' ? date('d M Y, h:i A', strtotime($archivedOn)) : '—') ?></td>
              <td style="font-weight:700;color:#1d4ed8">
                <?php if ($jobCardUrl !== ''): ?>
                  <a href="<?= e($jobCardUrl) ?>" class="history-job-link" target="_blank" title="Open full job card chain">
                    <?= e($row['job_no'] ?: '—') ?>
                  </a>
                <?php else: ?>
                  <?= e($row['job_no'] ?: '—') ?>
                <?php endif; ?>
              </td>
              <td><?= e($jobName !== '' ? $jobName : '—') ?></td>
              <td><?= e((string)($row['client_name'] ?? '—')) ?></td>
              <td><span class="history-status-pill"><?= e(planning_history_status_label((string)($row['status'] ?? ''))) ?></span></td>
              <td><span class="history-priority-pill history-priority-<?= e(strtolower($priority)) ?>"><?= e($priority) ?></span></td>
              <td><?= e($orderDate !== '' ? $orderDate : '—') ?></td>
              <td><?= e($dispatchDate !== '' ? $dispatchDate : '—') ?></td>
              <td><?= e($remarks !== '' ? $remarks : '—') ?></td>
              <?php if ($isUserAdmin): ?>
                <td class="no-print">
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this planning history row? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete_history_planning">
                    <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">
                    <button type="submit" class="history-del-btn"><i class="bi bi-trash"></i> Delete</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.planning-view-switch {
  display: inline-flex;
  gap: 8px;
  margin: 0 0 14px;
  flex-wrap: wrap;
}
.planning-view-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border-radius: 999px;
  border: 1px solid #dbe5f0;
  background: #fff;
  color: #334155;
  font-size: .82rem;
  font-weight: 700;
  text-decoration: none;
}
.planning-view-link:hover { border-color: #93c5fd; color: #1d4ed8; }
.planning-view-link.is-active { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.history-summary-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: .82rem;
  font-weight: 700;
}
.planning-history-filters {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  padding: 16px;
  align-items: end;
}
.planning-history-filters label {
  display: block;
  margin-bottom: 6px;
  font-size: .75rem;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.planning-history-filter-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.planning-history-table {
  width: 100%;
  min-width: 1120px;
  border-collapse: collapse;
}
.planning-history-table th,
.planning-history-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #e5e7eb;
  text-align: left;
  vertical-align: top;
  white-space: nowrap;
}
.planning-history-table th {
  background: #f8fafc;
  color: #475569;
  font-size: .74rem;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.history-status-pill,
.history-priority-pill {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: .74rem;
  font-weight: 700;
}
.history-status-pill { background: #dcfce7; color: #166534; }
.history-job-link {
  color: #1d4ed8;
  text-decoration: none;
  border-bottom: 1px dashed rgba(29, 78, 216, .35);
}
.history-job-link:hover {
  color: #1e3a8a;
  border-bottom-color: rgba(30, 58, 138, .6);
}
.history-priority-pill { background: #e2e8f0; color: #334155; }
.history-priority-normal { background: #dbeafe; color: #1d4ed8; }
.history-priority-high { background: #ffedd5; color: #c2410c; }
.history-priority-urgent { background: #fee2e2; color: #b91c1c; }
.history-del-btn {
  border: 1px solid #fecaca;
  background: #fff1f2;
  color: #b91c1c;
  border-radius: 8px;
  padding: 5px 9px;
  font-size: .74rem;
  font-weight: 700;
  cursor: pointer;
}
.history-del-btn:hover { background: #ffe4e6; border-color: #fca5a5; }
.table-empty { text-align: center !important; color: #64748b; }
@media (max-width: 980px) {
  .planning-history-filters { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .planning-history-filters { grid-template-columns: 1fr; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>