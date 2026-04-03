<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_common.php';

$db = getDB();
ensureDieToolingSchema($db);

$mode = (($_GET['mode'] ?? 'master') === 'design') ? 'design' : 'master';
$format = strtolower(trim((string)($_GET['format'] ?? 'excel')));
if (!in_array($format, ['excel', 'pdf'], true)) {
  $format = 'excel';
}

$rows = [];
$res = $db->query("SELECT * FROM master_die_tooling ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$title = $mode === 'design' ? 'Design Barcode Die' : 'Master Barcode Die';
$dateNow = date('d M Y h:i A');
$appSettings = getAppSettings();
$companyName = trim((string)($appSettings['company_name'] ?? APP_NAME)) ?: APP_NAME;
$companyTagline = trim((string)($appSettings['company_tagline'] ?? 'ERP Master System'));
$companyAddress = trim((string)($appSettings['company_address'] ?? ''));
$companyPhone = trim((string)($appSettings['company_phone'] ?? $appSettings['company_mobile'] ?? ''));
$companyEmail = trim((string)($appSettings['company_email'] ?? ''));
$logoPath = trim((string)($appSettings['logo_path'] ?? ''));
$logoUrl = $logoPath !== '' ? appUrl($logoPath) : '';

if ($format === 'excel') {
  $fileName = 'barcode-die-' . $mode . '-' . date('Ymd_His') . '.xls';
  header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $fileName . '"');
  echo "\xEF\xBB\xBF";
  ?>
  <html>
  <head>
    <meta charset="UTF-8">
    <style>
      body { font-family: Calibri, Arial, sans-serif; }
      .title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
      .meta { font-size: 11px; color: #475569; margin-bottom: 10px; }
      table { border-collapse: collapse; width: 100%; }
      th {
        background: #dbeafe;
        color: #1e3a8a;
        border: 1px solid #93c5fd;
        text-align: center;
        padding: 7px;
        font-size: 11px;
      }
      td {
        border: 1px solid #cbd5e1;
        padding: 6px;
        font-size: 10.5px;
      }
      tr:nth-child(even) td { background: #f8fafc; }
    </style>
  </head>
  <body>
    <div class="title"><?= e($title) ?> Export</div>
    <div class="meta">Generated: <?= e($dateNow) ?></div>
    <table>
      <thead>
        <tr>
          <th>Sl. No.</th>
          <th>BarCode Size</th>
          <th>Ups in Roll</th>
          <th>UPS in Die</th>
          <th>Label Gap</th>
          <th>Paper Size</th>
          <th>Cylender</th>
          <th>Repeat</th>
          <th>Used IN</th>
          <th>Die Type</th>
          <th>Core</th>
          <th>Pices per Roll</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" style="text-align:center;color:#64748b;">No data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $row): ?>
          <tr>
            <td><?= e(trim((string)($row['sl_no'] ?? '')) !== '' ? (string)$row['sl_no'] : (string)($idx + 1)) ?></td>
            <td><?= e((string)$row['barcode_size']) ?></td>
            <td><?= e((string)$row['ups_in_roll']) ?></td>
            <td><?= e((string)$row['up_in_die']) ?></td>
            <td><?= e((string)$row['label_gap']) ?></td>
            <td><?= e((string)$row['paper_size']) ?></td>
            <td><?= e((string)$row['cylender']) ?></td>
            <td><?= e(dieToolingFormatDisplayNumber($row['repeat_size'])) ?></td>
            <td><?= e((string)$row['used_for']) ?></td>
            <td><?= e((string)$row['die_type']) ?></td>
            <td><?= e((string)$row['core']) ?></td>
            <td><?= e((string)$row['pices_per_roll']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($title) ?> Print</title>
<style>
@page { size: A4 landscape; margin: 10mm 8mm 12mm 8mm; }
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; margin: 0; font-size: 11px; }
.wrap { padding: 12px 16px; }
.print-toolbar {
  padding: 12px 16px;
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #eff6ff, #f8fafc);
  border-bottom: 1px solid #dbeafe;
}
.print-toolbar button {
  border: 0;
  border-radius: 8px;
  padding: 8px 14px;
  cursor: pointer;
  font-weight: 600;
}
.btn-print { background: #0f172a; color: #fff; }
.btn-close { background: #fff; border: 1px solid #cbd5e1; color: #334155; }

.company-header {
  display: flex;
  align-items: center;
  gap: 14px;
  border-bottom: 2px solid #0f172a;
  padding-bottom: 10px;
  margin-bottom: 10px;
}
.company-logo {
  width: 54px;
  height: 54px;
  object-fit: contain;
  border: 1px solid #dbeafe;
  border-radius: 8px;
  background: #fff;
}
.company-info { flex: 1; }
.company-name { font-size: 20px; font-weight: 900; line-height: 1.1; }
.company-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
.company-meta { font-size: 10px; color: #475569; margin-top: 3px; }

.report-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #0f172a;
  color: #fff;
  border-radius: 8px;
  padding: 9px 14px;
  margin: 8px 0 10px;
}
.report-title strong { font-size: 14px; letter-spacing: .02em; }
.report-badge {
  background: rgba(255,255,255,.14);
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.summary-row {
  display: flex;
  gap: 10px;
  margin: 8px 0 12px;
}
.summary-item {
  flex: 1;
  border: 1px solid #dbeafe;
  border-radius: 8px;
  padding: 7px 10px;
  background: #f8fafc;
}
.summary-item .k { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; }
.summary-item .v { font-size: 15px; font-weight: 800; color: #0f172a; margin-top: 1px; }

table { width: 100%; border-collapse: collapse; font-size: 11px; }
th {
  border: 1px solid #93c5fd;
  background: linear-gradient(180deg, #dbeafe, #bfdbfe);
  color: #1e3a8a;
  padding: 7px 6px;
  text-transform: uppercase;
  letter-spacing: .03em;
  font-size: 10px;
  white-space: nowrap;
}
td { border: 1px solid #cbd5e1; padding: 6px 6px; }
tr:nth-child(even) { background: #f8fafc; }
.sl { width: 42px; text-align: center; color: #475569; font-weight: 700; }
.num { text-align: right; font-variant-numeric: tabular-nums; }

tfoot td {
  border: 0;
  padding-top: 8px;
  font-size: 10px;
  color: #64748b;
}

@media print {
  .no-print { display: none !important; }
  body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}
</style>
</head>
<body>
  <div class="print-toolbar no-print">
    <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
    <button class="btn-close" onclick="window.close()">Close</button>
  </div>
  <div class="wrap">
    <div class="company-header">
      <?php if ($logoUrl !== ''): ?>
        <img src="<?= e($logoUrl) ?>" alt="Logo" class="company-logo">
      <?php endif; ?>
      <div class="company-info">
        <div class="company-name"><?= e($companyName) ?></div>
        <?php if ($companyTagline !== ''): ?><div class="company-sub"><?= e($companyTagline) ?></div><?php endif; ?>
        <div class="company-meta">
          <?php if ($companyAddress !== ''): ?><?= e($companyAddress) ?><?php endif; ?>
          <?php if ($companyPhone !== '' || $companyEmail !== ''): ?>
            <?= $companyAddress !== '' ? ' | ' : '' ?>
            <?= e($companyPhone) ?><?= ($companyPhone !== '' && $companyEmail !== '') ? ' | ' : '' ?><?= e($companyEmail) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="report-title">
      <strong><?= e($title) ?> Report</strong>
      <span class="report-badge">Generated <?= e($dateNow) ?></span>
    </div>

    <div class="summary-row">
      <div class="summary-item">
        <div class="k">Total Rows</div>
        <div class="v"><?= count($rows) ?></div>
      </div>
      <div class="summary-item">
        <div class="k">Mode</div>
        <div class="v"><?= e($mode === 'design' ? 'Design' : 'Master') ?></div>
      </div>
      <div class="summary-item">
        <div class="k">Report Type</div>
        <div class="v">Barcode Die</div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Sl. No.</th>
          <th>BarCode Size</th>
          <th>Ups in Roll</th>
          <th>UPS in Die</th>
          <th>Label Gap</th>
          <th>Paper Size</th>
          <th>Cylender</th>
          <th>Repeat</th>
          <th>Used IN</th>
          <th>Die Type</th>
          <th>Core</th>
          <th>Pices per Roll</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="12" style="text-align:center;color:#64748b;">No data available.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $row): ?>
          <tr>
            <td class="sl"><?= e(trim((string)($row['sl_no'] ?? '')) !== '' ? (string)$row['sl_no'] : (string)($idx + 1)) ?></td>
            <td><?= e((string)$row['barcode_size']) ?></td>
            <td class="num"><?= e((string)$row['ups_in_roll']) ?></td>
            <td class="num"><?= e((string)$row['up_in_die']) ?></td>
            <td class="num"><?= e((string)$row['label_gap']) ?></td>
            <td><?= e((string)$row['paper_size']) ?></td>
            <td><?= e((string)$row['cylender']) ?></td>
            <td class="num"><?= e(dieToolingFormatDisplayNumber($row['repeat_size'])) ?></td>
            <td><?= e((string)$row['used_for']) ?></td>
            <td><?= e((string)$row['die_type']) ?></td>
            <td><?= e((string)$row['core']) ?></td>
            <td class="num"><?= e((string)$row['pices_per_roll']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="12">Generated by ERP system on <?= e($dateNow) ?></td></tr>
      </tfoot>
    </table>
  </div>
</body>
</html>
