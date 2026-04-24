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

$pageTitle = 'Print Carton Report';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/inventory/carton/index.php">Carton Stock</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Print Report</span>
</div>

<div class="page-header">
  <div>
    <h1>Carton Report (Printable)</h1>
    <p><?= e(ucfirst($period)) ?> | From <?= e($fromDate) ?> To <?= e($toDate) ?></p>
  </div>
  <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="card">
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
