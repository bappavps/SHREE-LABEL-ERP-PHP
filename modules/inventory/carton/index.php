<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/functions.php';

$db = carton_db();
carton_ensure_tables($db);

$pageTitle = 'Carton Inventory';
include __DIR__ . '/../../../includes/header.php';

$summarySql = "SELECT
    ci.id,
    ci.item_name,
    0 AS opening,
    COALESCE(SUM(CASE WHEN cs.type='ADD' THEN cs.qty ELSE 0 END),0) AS added,
    COALESCE(SUM(CASE WHEN cs.type='CONSUME' THEN cs.qty ELSE 0 END),0) AS consumed
FROM carton_items ci
LEFT JOIN carton_stock cs ON cs.item_id = ci.id
GROUP BY ci.id, ci.item_name
ORDER BY ci.item_name ASC";

$rows = [];
$res = $db->query($summarySql);
if ($res) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Carton Stock</span>
</div>

<div class="page-header">
  <div>
    <h1>Carton Stock Dashboard</h1>
    <p>Track carton opening, added, consumed and closing stock.</p>
  </div>
  <div class="topbar-actions" style="display:flex; gap:8px;">
    <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/inventory/carton/add_stock.php"><i class="bi bi-plus-circle"></i> Add Stock</a>
    <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/items.php"><i class="bi bi-box-seam"></i> Item Management</a>
    <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/report.php"><i class="bi bi-bar-chart"></i> Report</a>
    <a class="btn" href="<?= BASE_URL ?>/modules/inventory/carton/print.php" target="_blank"><i class="bi bi-printer"></i> Print</a>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <strong>Carton Summary</strong>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Item</th>
          <th>Opening</th>
          <th>Added</th>
          <th>Consumed</th>
          <th>Closing</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="5" style="text-align:center;color:#6b7280;">No carton item found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $opening = (int)($r['opening'] ?? 0);
              $added = (int)($r['added'] ?? 0);
              $consumed = (int)($r['consumed'] ?? 0);
              $closing = max(0, $added - $consumed);
            ?>
            <tr>
              <td><?= e($r['item_name'] ?? '') ?></td>
              <td><?= $opening ?></td>
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
