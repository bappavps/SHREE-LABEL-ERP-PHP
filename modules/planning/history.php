<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

function planning_history_department_options() {
    return ['label-printing', 'printing', 'slitting', 'packing', 'dispatch', 'general'];
}

function planning_history_department_label($department) {
    $department = trim((string)$department);
    if ($department === '') return 'General';
    return ucwords(str_replace('-', ' ', $department));
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

$department = trim((string)($_GET['department'] ?? 'label-printing'));
if ($department === '') $department = 'label-printing';
$day = max(0, min(31, (int)($_GET['day'] ?? 0)));
$month = max(0, min(12, (int)($_GET['month'] ?? 0)));
$year = max(0, (int)($_GET['year'] ?? 0));

$where = ["p.department = ?", "LOWER(TRIM(COALESCE(p.status, ''))) = 'finished'"];
$types = 's';
$params = [$department];
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
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$years = [];
$yearStmt = $db->prepare("SELECT DISTINCT YEAR(COALESCE(NULLIF(updated_at, '0000-00-00 00:00:00'), created_at)) AS y
    FROM planning
    WHERE department = ? AND LOWER(TRIM(COALESCE(status, ''))) = 'finished'
    ORDER BY y DESC");
$yearStmt->bind_param('s', $department);
$yearStmt->execute();
foreach ($yearStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $yr) {
    $y = (int)($yr['y'] ?? 0);
    if ($y > 0) $years[] = $y;
}
if (empty($years)) $years[] = (int)date('Y');

$deptOptions = planning_history_department_options();
$boardUrl = appUrl('modules/planning/index.php?department=' . rawurlencode($department));
$historyUrlBase = appUrl('modules/planning/history.php');
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
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="9" class="table-empty"><i class="bi bi-inbox"></i>No finished planning jobs found for this filter.</td>
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
            ?>
            <tr>
              <td><?= e($archivedOn !== '' ? date('d M Y, h:i A', strtotime($archivedOn)) : '—') ?></td>
              <td style="font-weight:700;color:#1d4ed8"><?= e($row['job_no'] ?: '—') ?></td>
              <td><?= e($jobName !== '' ? $jobName : '—') ?></td>
              <td><?= e((string)($row['client_name'] ?? '—')) ?></td>
              <td><span class="history-status-pill"><?= e((string)($row['status'] ?? 'Finished')) ?></span></td>
              <td><span class="history-priority-pill history-priority-<?= e(strtolower($priority)) ?>"><?= e($priority) ?></span></td>
              <td><?= e($orderDate !== '' ? $orderDate : '—') ?></td>
              <td><?= e($dispatchDate !== '' ? $dispatchDate : '—') ?></td>
              <td><?= e($remarks !== '' ? $remarks : '—') ?></td>
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
.history-priority-pill { background: #e2e8f0; color: #334155; }
.history-priority-normal { background: #dbeafe; color: #1d4ed8; }
.history-priority-high { background: #ffedd5; color: #c2410c; }
.history-priority-urgent { background: #fee2e2; color: #b91c1c; }
@media (max-width: 980px) {
  .planning-history-filters { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
  .planning-history-filters { grid-template-columns: 1fr; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>