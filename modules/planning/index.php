<?php
// ============================================================
// ERP System — Planning: List
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

$fSearch   = trim($_GET['search']   ?? '');
$fStatus   = trim($_GET['status']   ?? '');
$fPriority = trim($_GET['priority'] ?? '');

$where = ['1=1']; $params = []; $types = '';
if ($fSearch !== '') {
    $like    = '%'.$fSearch.'%';
    $where[] = '(p.job_name LIKE ? OR p.machine LIKE ? OR p.operator_name LIKE ?)';
    $params  = array_merge($params,[$like,$like,$like]); $types .= 'sss';
}
if ($fStatus !== '') {
    $where[] = 'p.status = ?'; $params[] = $fStatus; $types .= 's';
}
if ($fPriority !== '') {
    $where[] = 'p.priority = ?'; $params[] = $fPriority; $types .= 's';
}
$whereSQL = implode(' AND ', $where);

$perPage = 25; $page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*$perPage;

$totalQ = $db->prepare("SELECT COUNT(*) AS c FROM planning p WHERE {$whereSQL}");
if ($types) $totalQ->bind_param($types,...$params);
$totalQ->execute();
$total = (int)$totalQ->get_result()->fetch_assoc()['c'];

$listQ = $db->prepare(
    "SELECT p.*, so.order_no FROM planning p
     LEFT JOIN sales_orders so ON so.id = p.sales_order_id
     WHERE {$whereSQL}
     ORDER BY
       FIELD(p.priority,'Urgent','High','Normal','Low'),
       p.scheduled_date ASC, p.id DESC
     LIMIT ? OFFSET ?"
);
$listQ->bind_param($types.'ii',...[...$params,$perPage,$offset]);
$listQ->execute();
$jobs = $listQ->get_result()->fetch_all(MYSQLI_ASSOC);

$statuses   = ['Queued','In Progress','Completed','On Hold'];
$priorities = ['Low','Normal','High','Urgent'];
$pageTitle  = 'Planning Board';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>Planning</span>
</div>
<div class="page-header">
  <div><h1>Planning Board</h1><p>Schedule and track production jobs.</p></div>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Job</a>
</div>

<form method="GET" class="filter-bar mb-0">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="search" placeholder="Job name / Machine / Operator" value="<?= e($fSearch) ?>">
  </div>
  <div class="filter-group">
    <label>Status</label>
    <select name="status">
      <option value="">All Status</option>
      <?php foreach($statuses as $s): ?>
      <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Priority</label>
    <select name="priority">
      <option value="">All Priority</option>
      <?php foreach($priorities as $p): ?>
      <option value="<?= $p ?>" <?= $fPriority===$p?'selected':'' ?>><?= $p ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group" style="justify-content:flex-end"><label>&nbsp;</label>
    <div class="d-flex gap-8">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
      <a href="index.php" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> Reset</a>
    </div>
  </div>
</form>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">Jobs <span class="badge badge-consumed" style="margin-left:6px"><?= $total ?></span></span>
    <span class="text-muted text-sm"><?= min($total,$offset+1) ?>–<?= min($total,$offset+$perPage) ?> of <?= $total ?></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Job Name</th><th>Order</th><th>Machine</th>
          <th>Operator</th><th>Priority</th><th>Status</th><th>Scheduled</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($jobs)): ?>
        <tr><td colspan="9" class="table-empty"><i class="bi bi-inbox"></i>No planning jobs found.</td></tr>
        <?php else: ?>
        <?php foreach ($jobs as $i => $j): ?>
        <tr>
          <td class="text-muted"><?= $offset+$i+1 ?></td>
          <td class="fw-600"><?= e($j['job_name']) ?></td>
          <td>
            <?php if ($j['order_no']): ?>
            <a href="<?= BASE_URL ?>/modules/sales_order/view.php?id=<?= $j['sales_order_id'] ?>" class="text-blue text-sm">
              <?= e($j['order_no']) ?>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-muted"><?= e($j['machine'] ?? '—') ?></td>
          <td class="text-muted"><?= e($j['operator_name'] ?? '—') ?></td>
          <td><?php
            $pc = ['Urgent'=>'danger','High'=>'warning','Normal'=>'info','Low'=>'consumed'];
            $cls = $pc[$j['priority']] ?? 'consumed';
            echo "<span class=\"badge badge-{$cls}\">{$j['priority']}</span>";
          ?></td>
          <td><?= statusBadge($j['status']) ?></td>
          <td class="text-muted">
            <?= $j['scheduled_date'] ? formatDate($j['scheduled_date']) : '—' ?>
          </td>
          <td>
            <div class="row-actions">
              <a href="edit.php?id=<?= $j['id'] ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
              <a href="delete.php?id=<?= $j['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-danger btn-sm" title="Delete"
                 data-confirm="Delete planning job: <?= e($j['job_name']) ?>?"><i class="bi bi-trash"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
  $qStr = http_build_query(['search'=>$fSearch,'status'=>$fStatus,'priority'=>$fPriority,'page'=>'{page}']);
  echo paginationBar($total, $perPage, $page, BASE_URL.'/modules/planning/index.php?'.$qStr);
  ?>
  <div style="padding:8px 14px"></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
