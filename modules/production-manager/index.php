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
    ];

    if (isset($map[$dept])) return $map[$dept];
    if ($dept === '') return 'Not Started';
    return ucwords(str_replace(['_', '-'], ' ', $dept));
}

function pm_bucket_status(array $row): string {
    $jobStatus = strtolower(pm_text($row['active_job_status'] ?: $row['latest_job_status']));
    $boardStatus = strtolower(pm_text($row['board_status']));
    $planningStatus = strtolower(pm_text($row['planning_status']));

    $haystack = $jobStatus . ' ' . $boardStatus . ' ' . $planningStatus;
    if (strpos($haystack, 'running') !== false || strpos($haystack, 'in progress') !== false) return 'Running';
    if (strpos($haystack, 'pause') !== false || strpos($haystack, 'hold') !== false) return 'On Hold';
    if (strpos($haystack, 'completed') !== false || strpos($haystack, 'finalized') !== false || strpos($haystack, 'closed') !== false || strpos($haystack, 'qc passed') !== false || strpos($haystack, 'dispatch') !== false) return 'Completed';
    if (strpos($haystack, 'pending') !== false || strpos($haystack, 'queued') !== false || strpos($haystack, 'preparing') !== false) return 'Pending';
    return 'Pending';
}

$q = pm_text($_GET['q'] ?? '');
$statusFilter = pm_text($_GET['status'] ?? '');
$stageFilter = pm_text($_GET['stage'] ?? '');

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

$filtered = [];
foreach ($rows as $row) {
    $currentDept = pm_department_label($row['active_job_department'] ?: $row['latest_job_department'] ?: $row['planning_department'], $row['active_job_type'] ?: $row['latest_job_type']);
    $bucket = pm_bucket_status($row);

    if ($statusFilter !== '' && strcasecmp($bucket, $statusFilter) !== 0) {
        continue;
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
    elseif ($bucket === 'Completed') $completed++;
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
  --pm-ink:#102a43;
  --pm-slate:#486581;
  --pm-border:#d9e2ec;
  --pm-surface:#f8fbff;
  --pm-brand:#005f73;
  --pm-accent:#ee9b00;
}
.pm-wrap{
  display:flex;
  flex-direction:column;
  gap:16px;
  position:relative;
}
.pm-wrap::before,
.pm-wrap::after{
  content:'';
  position:fixed;
  z-index:0;
  pointer-events:none;
  filter:blur(28px);
  opacity:.45;
}
.pm-wrap::before{
  width:260px;
  height:260px;
  top:120px;
  right:60px;
  background:radial-gradient(circle,#94d2bd 0%,transparent 72%);
}
.pm-wrap::after{
  width:300px;
  height:300px;
  bottom:40px;
  left:40px;
  background:radial-gradient(circle,#ffddd2 0%,transparent 72%);
}
.pm-wrap > *{
  position:relative;
  z-index:1;
}
.pm-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
  background:linear-gradient(120deg,#003049 0%,#005f73 55%,#0a9396 100%);
  padding:22px 24px;
  border-radius:18px;
  color:#fff;
  box-shadow:0 16px 40px rgba(0,48,73,.28);
}
.pm-head h1{margin:0;font-size:1.55rem;font-weight:900;letter-spacing:.01em}
.pm-head p{margin:6px 0 0;opacity:.9;font-size:.84rem;max-width:680px}
.pm-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px}
.pm-stat{
  background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
  border-radius:14px;
  padding:13px 14px;
  border:1px solid var(--pm-border);
  box-shadow:0 8px 20px rgba(16,42,67,.08);
}
.pm-stat .k{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--pm-slate);font-weight:800}
.pm-stat .v{display:block;font-size:1.45rem;font-weight:900;color:var(--pm-ink);line-height:1.15}
.pm-filters{
  display:grid;
  grid-template-columns:2fr 1fr 1fr auto;
  gap:10px;
  background:rgba(255,255,255,.92);
  backdrop-filter:blur(6px);
  border:1px solid var(--pm-border);
  padding:12px;
  border-radius:14px;
}
.pm-filters input,.pm-filters select{
  width:100%;
  border:1px solid #bcccdc;
  background:#fff;
  border-radius:10px;
  padding:10px 11px;
  font-size:.86rem;
  color:var(--pm-ink);
}
.pm-filters input:focus,.pm-filters select:focus{outline:none;border-color:#0a9396;box-shadow:0 0 0 3px rgba(10,147,150,.18)}
.pm-filters button{
  border:0;
  border-radius:10px;
  background:linear-gradient(120deg,var(--pm-brand),#0a9396);
  color:#fff;
  font-weight:800;
  padding:10px 14px;
  cursor:pointer;
}
.pm-table{
  background:rgba(255,255,255,.92);
  border:1px solid var(--pm-border);
  border-radius:14px;
  overflow:hidden;
  box-shadow:0 10px 30px rgba(16,42,67,.08);
}
.pm-table-wrap{overflow:auto;max-height:68vh}
.pm-table table{width:100%;border-collapse:separate;border-spacing:0;min-width:1320px}
.pm-table th,.pm-table td{padding:10px;border-bottom:1px solid #ebf0f6;font-size:.81rem;vertical-align:top}
.pm-table th{
  background:#f2f7fb;
  text-transform:uppercase;
  font-size:.66rem;
  letter-spacing:.08em;
  color:#334e68;
  text-align:left;
  position:sticky;
  top:0;
  z-index:1;
}
.pm-table tbody tr:nth-child(even) td{background:#fbfdff}
.pm-table tr:hover td{background:#f1f7ff}
.pm-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:.66rem;font-weight:900;letter-spacing:.03em;border:1px solid transparent}
.pm-badge.pending{background:#fff4e6;color:#9a3412;border-color:#fdba74}
.pm-badge.running{background:#e0f2fe;color:#0c4a6e;border-color:#7dd3fc}
.pm-badge.completed{background:#dcfce7;color:#14532d;border-color:#86efac}
.pm-badge.hold{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.pm-muted{color:#627d98;font-size:.75rem}
.pm-chain{max-width:360px;white-space:normal;line-height:1.45;color:#243b53}
.pm-link{
  display:inline-block;
  padding:6px 9px;
  border-radius:9px;
  background:#d9f3f4;
  color:#0f172a;
  text-decoration:none;
  font-size:.72rem;
  font-weight:800;
  border:1px solid #94d2bd;
}
.pm-link:hover{background:#c2ecee;color:#05293a}
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
    <div class="pm-stat"><span class="k">Total Plans</span><span class="v"><?= (int)$total ?></span></div>
    <div class="pm-stat"><span class="k">Running</span><span class="v"><?= (int)$running ?></span></div>
    <div class="pm-stat"><span class="k">Pending</span><span class="v"><?= (int)$pending ?></span></div>
    <div class="pm-stat"><span class="k">On Hold</span><span class="v"><?= (int)$hold ?></span></div>
    <div class="pm-stat"><span class="k">Completed</span><span class="v"><?= (int)$completed ?></span></div>
  </section>

  <form class="pm-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by planning/job no, job name, notes">
    <select name="status">
      <option value="">All Status</option>
      <?php foreach (['Pending','Running','On Hold','Completed'] as $opt): ?>
        <option value="<?= e($opt) ?>" <?= strcasecmp($statusFilter, $opt) === 0 ? 'selected' : '' ?>><?= e($opt) ?></option>
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
            <th>Planning Status</th>
            <th>Current Stage</th>
            <th>Current Position</th>
            <th>Active Job Card</th>
            <th>Latest Job Card</th>
            <th>Progress</th>
            <th>Department Route</th>
            <th>Chain Snapshot</th>
            <th>Last Update</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($filtered)): ?>
          <tr><td colspan="15" class="pm-muted">No data found for selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($filtered as $idx => $row): ?>
            <?php
              $bucket = (string)$row['bucket_status'];
              $bucketClass = 'pending';
              if ($bucket === 'Running') $bucketClass = 'running';
              elseif ($bucket === 'Completed') $bucketClass = 'completed';
              elseif ($bucket === 'On Hold') $bucketClass = 'hold';

              $activeJobNo = pm_text($row['active_job_no']);
              $latestJobNo = pm_text($row['latest_job_no']);
              $planNo = pm_text($row['job_no']);
              $viewJobNo = $activeJobNo !== '' ? $activeJobNo : ($latestJobNo !== '' ? $latestJobNo : $planNo);
              $planningNo = $planNo !== '' ? $planNo : ('PLAN-' . (int)$row['id']);
              $serialNo = (int)$idx + 1;

              $curPos = pm_text($row['active_job_status']);
              if ($curPos === '') $curPos = pm_text($row['latest_job_status']);
              if ($curPos === '') $curPos = pm_text($row['board_status']);
              if ($curPos === '') $curPos = pm_text($row['planning_status']);

              $route = pm_text($row['department_route']);
              if ($route === '') $route = pm_text($row['planning_department']);

              $lastAt = pm_text($row['active_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['latest_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['last_job_at']);
              if ($lastAt === '') $lastAt = pm_text($row['planning_updated_at']);
            ?>
            <tr>
              <td><strong><?= (int)$serialNo ?></strong></td>
              <td><strong><?= e($planningNo) ?></strong><div class="pm-muted">ID: <?= (int)$row['id'] ?></div></td>
              <td><strong><?= e($viewJobNo !== '' ? $viewJobNo : '-') ?></strong></td>
              <td><?= e(pm_text($row['job_name']) !== '' ? $row['job_name'] : '-') ?></td>
              <td><?= e(pm_text($row['priority']) !== '' ? $row['priority'] : 'Normal') ?></td>
              <td>
                <span class="pm-badge <?= e($bucketClass) ?>"><?= e($bucket) ?></span>
                <div class="pm-muted">Board: <?= e(pm_text($row['board_status']) !== '' ? $row['board_status'] : '-') ?></div>
              </td>
              <td><strong><?= e($row['current_department_label']) ?></strong></td>
              <td><?= e($curPos !== '' ? $curPos : '-') ?></td>
              <td><?= e($activeJobNo !== '' ? $activeJobNo : '-') ?></td>
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
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
