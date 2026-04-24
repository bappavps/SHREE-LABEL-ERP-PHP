<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/functions.php';

$db = carton_db();
carton_ensure_tables($db);

$period = strtolower(trim((string)($_GET['period'] ?? 'daily')));
if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
    $period = 'daily';
}

[$fromDate, $toDate] = carton_period_bounds($period);
$fromTs = $fromDate . ' 00:00:00';
$toTs = $toDate . ' 23:59:59';

$sql = "SELECT
    ci.id,
    ci.item_name,
    COALESCE(SUM(CASE WHEN cs.created_at BETWEEN ? AND ? AND cs.type='ADD' THEN cs.qty ELSE 0 END), 0) AS added,
    COALESCE(SUM(CASE WHEN cs.created_at BETWEEN ? AND ? AND cs.type='CONSUME' THEN cs.qty ELSE 0 END), 0) AS consumed,
    COALESCE(SUM(CASE WHEN cs.created_at <= ? AND cs.type='ADD' THEN cs.qty ELSE 0 END), 0) AS total_added_till,
    COALESCE(SUM(CASE WHEN cs.created_at <= ? AND cs.type='CONSUME' THEN cs.qty ELSE 0 END), 0) AS total_consumed_till
FROM carton_items ci
LEFT JOIN carton_stock cs ON cs.item_id = ci.id
GROUP BY ci.id, ci.item_name
ORDER BY ci.item_name ASC";

$rows = [];
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ssssss', $fromTs, $toTs, $fromTs, $toTs, $toTs, $toTs);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$totalAdded = 0;
$totalConsumed = 0;
$totalClosing = 0;
foreach ($rows as $r) {
    $a = (int)($r['added'] ?? 0);
    $c = (int)($r['consumed'] ?? 0);
    $closing = max(0, ((int)($r['total_added_till'] ?? 0)) - ((int)($r['total_consumed_till'] ?? 0)));
    $totalAdded += $a;
    $totalConsumed += $c;
    $totalClosing += $closing;
}

$pageTitle = 'Carton Report';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/inventory/carton/index.php">Carton Stock</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Report</span>
</div>

<div class="page-header">
  <div>
    <h1>Carton Report</h1>
    <p>Period-wise Added / Consumed / Closing report.</p>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:16px;">
    <form method="get" action="" style="display:flex;gap:8px;align-items:end;">
      <div>
        <label>Period</label>
        <select class="form-control" name="period">
          <option value="daily" <?= $period === 'daily' ? 'selected' : '' ?>>Daily</option>
          <option value="weekly" <?= $period === 'weekly' ? 'selected' : '' ?>>Weekly</option>
          <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
          <option value="yearly" <?= $period === 'yearly' ? 'selected' : '' ?>>Yearly</option>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Apply</button>
      <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/print.php?period=<?= e($period) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
    </form>
  </div>
</div>

<div class="card" style="margin-top:14px;">
  <div class="card-header">
    <strong><?= e(ucfirst($period)) ?> Report</strong>
    <span style="float:right;color:#6b7280;">From <?= e($fromDate) ?> To <?= e($toDate) ?></span>
  </div>
  <div class="card-body" style="padding:12px 16px;display:flex;gap:16px;flex-wrap:wrap;">
    <div><strong>Added:</strong> <?= (int)$totalAdded ?></div>
    <div><strong>Consumed:</strong> <?= (int)$totalConsumed ?></div>
    <div><strong>Closing:</strong> <?= (int)$totalClosing ?></div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Item</th>
          <th>Added</th>
          <th>Consumed</th>
          <th>Closing</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="4" style="text-align:center;color:#6b7280;">No data found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $added = (int)($r['added'] ?? 0);
              $consumed = (int)($r['consumed'] ?? 0);
              $closing = max(0, ((int)($r['total_added_till'] ?? 0)) - ((int)($r['total_consumed_till'] ?? 0)));
            ?>
            <tr>
              <td><?= e($r['item_name']) ?></td>
              <td><?= $added ?></td>
              <td><?= $consumed ?></td>
              <td><strong><?= $closing ?></strong></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
