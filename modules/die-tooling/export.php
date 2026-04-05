<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_common.php';

$db = getDB();
ensureDieToolingSchema($db);

$mode = (($_GET['mode'] ?? 'master') === 'design') ? 'design' : 'master';
$format = strtolower(trim((string)($_GET['format'] ?? 'excel')));
$scope = mb_strtolower(trim((string)($_GET['scope'] ?? '')), 'UTF-8');
$scopeLabel = trim((string)($_GET['scope_label'] ?? ''));
$allowedTypes = dieToolingNormalizeTypeList(array_filter(array_map('trim', explode(',', (string)($_GET['allowed_types'] ?? '')))));
$entityLabelOverride = trim((string)($_GET['entity_label'] ?? ''));
if (!in_array($format, ['excel', 'pdf'], true)) {
  $format = 'excel';
}

$scopeWhere = '';
if ($scopeLabel !== '') {
  $safeLabel = $db->real_escape_string(mb_strtolower(trim($scopeLabel), 'UTF-8'));
  $scopeWhere = " WHERE LOWER(COALESCE(die_type, '')) = '{$safeLabel}'";
} elseif ($scope !== '') {
  $safeScope = $db->real_escape_string($scope);
  $scopeWhere = " WHERE LOWER(COALESCE(die_type, '')) LIKE '%{$safeScope}%'";
} elseif ($allowedTypes) {
  $scopeWhere = dieToolingBuildAllowedTypeWhere($db, $allowedTypes, 'die_type');
}

$rows = [];
$res = $db->query("SELECT * FROM master_die_tooling{$scopeWhere} ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$entityLabel = $entityLabelOverride !== '' ? $entityLabelOverride : ($scopeLabel !== '' ? ($scopeLabel . ' Barcode Die') : 'Barcode Die');
$title = $mode === 'design' ? ('Design ' . $entityLabel) : ('Master ' . $entityLabel);
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
  $fileName = 'barcode-die-' . ($scopeLabel !== '' ? strtolower(str_replace(' ', '-', $scopeLabel)) . '-' : '') . $mode . '-' . date('Ymd_His') . '.xls';
  header('Content-Type: application/vnd.ms-excel');
  header('Content-Disposition: attachment; filename="' . $fileName . '"');
  header('Pragma: no-cache');
  header('Cache-Control: no-cache, must-revalidate');

  $numericCols = ['ups_in_roll','up_in_die','label_gap','repeat_size','pices_per_roll'];
  $colCount = 13; // SL + 12 data columns
  $mergeCount = $colCount - 1;

  // Helper functions for XML Excel
  function dtXmlEsc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  function dtXlCell($val, $styleId = 's_body') {
    $escaped = dtXmlEsc($val);
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>";
  }
  function dtXlNumCell($val, $styleId = 's_num') {
    $n = is_numeric($val) ? $val : 0;
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"Number\">{$n}</Data></Cell>";
  }
  function dtXlMergeCell($val, $mergeAcross, $styleId = 's_body') {
    $escaped = dtXmlEsc($val);
    return "<Cell ss:StyleID=\"{$styleId}\" ss:MergeAcross=\"{$mergeAcross}\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>";
  }

  $colWidths = [40, 110, 90, 80, 80, 75, 100, 90, 80, 90, 80, 75, 80];

  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
  <Style ss:ID="s_company">
    <Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#0F172A"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="s_tagline">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Italic="1" ss:Color="#64748B"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="s_detail">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#475569"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="s_title">
    <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#0F172A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="s_header">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1E40AF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2563EB"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2563EB"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/>
    </Borders>
  </Style>
  <Style ss:ID="s_header_r">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1E40AF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2563EB"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#2563EB"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E3A8A"/>
    </Borders>
  </Style>
  <Style ss:ID="s_body">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_body_alt">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/>
    <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_num">
    <Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_num_alt">
    <Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/>
    <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_sl">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#94A3B8"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_sl_alt">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#94A3B8"/>
    <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <Style ss:ID="s_sum_label">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#0F172A"/>
    <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    </Borders>
  </Style>
  <Style ss:ID="s_sum_val">
    <Font ss:FontName="Consolas" ss:Size="11" ss:Bold="1" ss:Color="#1D4ED8"/>
    <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    </Borders>
  </Style>
  <Style ss:ID="s_sum_title">
    <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1E40AF" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="s_footer">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#94A3B8"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0F172A"/>
    </Borders>
  </Style>
  <Style ss:ID="s_blank"><Alignment ss:Vertical="Center"/></Style>
</Styles>

<Worksheet ss:Name="<?= dtXmlEsc($entityLabel) ?>">
<Table>
<?php foreach ($colWidths as $w): ?>
  <Column ss:AutoFitWidth="0" ss:Width="<?= $w ?>"/>
<?php endforeach; ?>

  <Row ss:Height="28">
    <?= dtXlMergeCell($companyName, $mergeCount, 's_company') ?>
  </Row>
<?php if ($companyTagline !== ''): ?>
  <Row ss:Height="18">
    <?= dtXlMergeCell($companyTagline, $mergeCount, 's_tagline') ?>
  </Row>
<?php endif; ?>
<?php if ($companyAddress !== ''): ?>
  <Row ss:Height="16">
    <?= dtXlMergeCell($companyAddress, $mergeCount, 's_detail') ?>
  </Row>
<?php endif; ?>
<?php
  $contactParts = [];
  if ($companyPhone !== '') $contactParts[] = 'Ph: ' . $companyPhone;
  if ($companyEmail !== '') $contactParts[] = $companyEmail;
  if ($contactParts):
?>
  <Row ss:Height="16">
    <?= dtXlMergeCell(implode('  |  ', $contactParts), $mergeCount, 's_detail') ?>
  </Row>
<?php endif; ?>

  <Row ss:Height="8"><Cell ss:StyleID="s_blank"/></Row>

  <Row ss:Height="26">
    <?= dtXlMergeCell(strtoupper($entityLabel) . ' REPORT — ' . date('d M Y, h:i A') . '  |  Total: ' . count($rows) . ' records', $mergeCount, 's_title') ?>
  </Row>

  <Row ss:Height="6"><Cell ss:StyleID="s_blank"/></Row>

  <Row ss:Height="24">
    <Cell ss:StyleID="s_header"><Data ss:Type="String">SL No.</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Barcode Size</Data></Cell>
    <Cell ss:StyleID="s_header_r"><Data ss:Type="String">Ups in Roll</Data></Cell>
    <Cell ss:StyleID="s_header_r"><Data ss:Type="String">UPS in Die</Data></Cell>
    <Cell ss:StyleID="s_header_r"><Data ss:Type="String">Label Gap</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Paper Size</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Cylinder</Data></Cell>
    <Cell ss:StyleID="s_header_r"><Data ss:Type="String">Repeat</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Used IN</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Die Type</Data></Cell>
    <Cell ss:StyleID="s_header"><Data ss:Type="String">Core</Data></Cell>
    <Cell ss:StyleID="s_header_r"><Data ss:Type="String">Pcs/Roll</Data></Cell>
  </Row>

<?php foreach ($rows as $idx => $row):
  $alt = ($idx % 2 === 1);
  $slStyle = $alt ? 's_sl_alt' : 's_sl';
  $bStyle  = $alt ? 's_body_alt' : 's_body';
  $nStyle  = $alt ? 's_num_alt' : 's_num';
?>
  <Row ss:Height="20">
    <?= dtXlNumCell($idx + 1, $slStyle) ?>
    <?= dtXlCell((string)$row['barcode_size'], $bStyle) ?>
    <?= dtXlCell((string)$row['ups_in_roll'], $nStyle) ?>
    <?= dtXlCell((string)$row['up_in_die'], $nStyle) ?>
    <?= dtXlCell((string)$row['label_gap'], $nStyle) ?>
    <?= dtXlCell((string)$row['paper_size'], $bStyle) ?>
    <?= dtXlCell((string)$row['cylender'], $bStyle) ?>
    <?= dtXlCell(dieToolingFormatDisplayNumber($row['repeat_size']), $nStyle) ?>
    <?= dtXlCell((string)$row['used_for'], $bStyle) ?>
    <?= dtXlCell((string)$row['die_type'], $bStyle) ?>
    <?= dtXlCell((string)$row['core'], $bStyle) ?>
    <?= dtXlCell((string)$row['pices_per_roll'], $nStyle) ?>
  </Row>
<?php endforeach; ?>

  <Row ss:Height="10"><Cell ss:StyleID="s_blank"/></Row>

  <Row ss:Height="24">
    <?= dtXlMergeCell('SUMMARY', $mergeCount, 's_sum_title') ?>
  </Row>
  <Row ss:Height="22">
    <?= dtXlCell('Total Records', 's_sum_label') ?>
    <?= dtXlNumCell(count($rows), 's_sum_val') ?>
  </Row>
  <Row ss:Height="22">
    <?= dtXlCell('Report Type', 's_sum_label') ?>
    <?= dtXlCell($entityLabel, 's_sum_val') ?>
  </Row>
  <Row ss:Height="22">
    <?= dtXlCell('Mode', 's_sum_label') ?>
    <?= dtXlCell($mode === 'design' ? 'Design' : 'Master', 's_sum_val') ?>
  </Row>

  <Row ss:Height="8"><Cell ss:StyleID="s_blank"/></Row>

  <Row ss:Height="18">
    <?= dtXlMergeCell($companyName . ' — ' . $entityLabel . ' Report  |  Generated: ' . date('d M Y, h:i A') . '  |  ' . count($rows) . ' records', $mergeCount, 's_footer') ?>
  </Row>

</Table>
</Worksheet>
</Workbook>
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
            <td class="sl"><?= e($scope !== '' ? (string)($idx + 1) : (trim((string)($row['sl_no'] ?? '')) !== '' ? (string)$row['sl_no'] : (string)($idx + 1))) ?></td>
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
