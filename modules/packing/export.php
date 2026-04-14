<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_data.php';

$db = getDB();
$tab = trim((string)($_GET['tab'] ?? 'printing_label'));
$allowedTabs = packing_tab_keys();
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'printing_label';
}

$mode = trim((string)($_GET['mode'] ?? 'selected'));
$search = trim((string)($_GET['search'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$autoprint = trim((string)($_GET['autoprint'] ?? '0')) === '1';
$printType = strtolower(trim((string)($_GET['print_type'] ?? '')));

$data = packing_fetch_ready_rows($db, [
    'search' => $search,
    'from' => $from,
    'to' => $to,
]);
$rows = $data['rows_by_tab'][$tab] ?? [];

if ($mode === 'selected') {
    $idsParam = trim((string)($_GET['ids'] ?? ''));
    $ids = array_filter(array_map('intval', explode(',', $idsParam)), static function(int $id): bool {
        return $id > 0;
    });
    $idMap = [];
    foreach ($ids as $id) {
        $idMap[$id] = true;
    }

    $rows = array_values(array_filter($rows, static function(array $row) use ($idMap): bool {
        return isset($idMap[(int)($row['id'] ?? 0)]);
    }));
}

$appSettings = getAppSettings();
$companyName = trim((string)($appSettings['company_name'] ?? APP_NAME)) ?: APP_NAME;
$companyTagline = trim((string)($appSettings['company_tagline'] ?? ''));
$logoPath = trim((string)($appSettings['erp_logo_path'] ?? ($appSettings['logo_path'] ?? '')));
$logoUrl = $logoPath !== '' ? appUrl($logoPath) : '';
$reportTitle = packing_tab_label($tab) . ' - Packing Ready Report';
if ($printType === 'sticker') {
  $reportTitle = packing_tab_label($tab) . ' - Sticker Print';
} elseif ($printType === 'label') {
  $reportTitle = packing_tab_label($tab) . ' - Label Print';
}
$generatedAt = date('d M Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($reportTitle) ?></title>
<style>
* { box-sizing: border-box; }
body { margin: 16px; font-family: Arial, sans-serif; color: #0f172a; }
.head { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; border-bottom:2px solid #0f172a; padding-bottom:12px; margin-bottom:12px; }
.brand { display:flex; gap:12px; align-items:flex-start; }
.brand img { width:60px; max-height:60px; object-fit:contain; }
.name { font-size:20px; font-weight:700; }
.tagline { font-size:12px; color:#475569; margin-top:2px; }
.meta { text-align:right; font-size:12px; color:#475569; }
.meta .title { font-size:18px; font-weight:700; color:#0f172a; margin-bottom:3px; }
table { width:100%; border-collapse:collapse; }
th, td { border:1px solid #cbd5e1; padding:6px 7px; font-size:11px; text-align:left; }
th { background:#e2e8f0; }
tbody tr:nth-child(even) td { background:#f8fafc; }
.badge { display:inline-block; border-radius:10px; padding:2px 8px; font-size:10px; font-weight:700; background:#dcfce7; color:#166534; }
.actions { margin-bottom:10px; }
.actions button { border:1px solid #cbd5e1; border-radius:8px; background:#fff; padding:6px 10px; cursor:pointer; }
.empty { padding:30px; text-align:center; color:#64748b; border:1px solid #cbd5e1; border-radius:10px; }
@media print {
  body { margin: 0; }
  .actions { display:none !important; }
}
</style>
</head>
<body>
  <div class="actions"><button onclick="window.print()">Print / Save PDF</button></div>
  <div class="head">
    <div class="brand">
      <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="Logo"><?php endif; ?>
      <div>
        <div class="name"><?= e($companyName) ?></div>
        <?php if ($companyTagline !== ''): ?><div class="tagline"><?= e($companyTagline) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="meta">
      <div class="title"><?= e($reportTitle) ?></div>
      <div>Generated: <?= e($generatedAt) ?></div>
      <div>Total Rows: <?= count($rows) ?></div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No packing-ready records found.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>SL</th>
        <th>Plan No</th>
        <th>Plan Name</th>
        <th>Last Job No</th>
        <th>Roll No</th>
        <th>Type</th>
        <th>Last Department</th>
        <th>Status</th>
        <th>Completed At</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $row): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($row['plan_no'] !== '' ? $row['plan_no'] : '-') ?></td>
        <td><?= e($row['plan_name'] !== '' ? $row['plan_name'] : '-') ?></td>
        <td><?= e($row['job_no']) ?></td>
        <td><?= e($row['roll_no'] !== '' ? $row['roll_no'] : '-') ?></td>
        <td><?= e($row['tab_label']) ?></td>
        <td><?= e($row['last_department']) ?></td>
        <td><span class="badge"><?= e($row['status']) ?></span></td>
        <td><?= e($row['event_time'] !== '' ? date('d M Y H:i', strtotime($row['event_time'])) : '-') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php if ($autoprint): ?>
<script>
window.addEventListener('load', function () {
  window.print();
});
</script>
<?php endif; ?>
</body>
</html>
