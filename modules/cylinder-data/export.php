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
if (!in_array($format, ['excel', 'pdf'], true)) {
  $format = 'excel';
}

$scopeWhere = '';
if ($scopeLabel !== '') {
  $safeLabel = $db->real_escape_string(mb_strtolower(trim($scopeLabel), 'UTF-8'));
  $scopeWhere = " WHERE LOWER(COALESCE(cylinder_type, '')) = '{$safeLabel}'";
} elseif ($scope !== '') {
  $safeScope = $db->real_escape_string($scope);
  $scopeWhere = " WHERE LOWER(COALESCE(cylinder_type, '')) LIKE '%{$safeScope}%'";
}

$moduleLabel = $scopeLabel !== '' ? ($scopeLabel . ' Cylinder Data') : dieToolingModuleLabel();
$tableName = dieToolingTableName();
$columns = dieToolingColumns();
$title = ($mode === 'design' ? 'Design ' : 'Master ') . $moduleLabel;
$dateNow = date('d M Y h:i A');
$appSettings = getAppSettings();
$companyName = trim((string)($appSettings['company_name'] ?? APP_NAME)) ?: APP_NAME;
$companyTagline = trim((string)($appSettings['company_tagline'] ?? 'ERP Master System'));
$companyAddress = trim((string)($appSettings['company_address'] ?? ''));
$companyPhone = trim((string)($appSettings['company_phone'] ?? $appSettings['company_mobile'] ?? ''));
$companyEmail = trim((string)($appSettings['company_email'] ?? ''));
$logoPath = trim((string)($appSettings['logo_path'] ?? ''));
$logoUrl = $logoPath !== '' ? appUrl($logoPath) : '';

$rows = [];
$res = $db->query("SELECT * FROM {$tableName}{$scopeWhere} ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

if ($format === 'excel') {
  $fileName = strtolower(str_replace(' ', '-', $moduleLabel)) . '-' . $mode . '-' . date('Ymd_His') . '.xls';
  header('Content-Type: application/vnd.ms-excel');
  header('Content-Disposition: attachment; filename="' . $fileName . '"');
  header('Pragma: no-cache');
  header('Cache-Control: no-cache, must-revalidate');

  function cylXmlEsc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  function cylCell($val, $styleId = 's_body') {
    $escaped = cylXmlEsc($val);
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>";
  }

  function cylNumCell($val, $styleId = 's_num') {
    $n = is_numeric($val) ? $val : 0;
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"Number\">{$n}</Data></Cell>";
  }

  function cylMergeCell($val, $mergeAcross, $styleId = 's_body') {
    $escaped = cylXmlEsc($val);
    return "<Cell ss:StyleID=\"{$styleId}\" ss:MergeAcross=\"{$mergeAcross}\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>";
  }

  $colCount = count($columns) + 1;
  $mergeCount = $colCount - 1;

  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
  echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
  ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Styles>
  <Style ss:ID="s_company"><Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#0F172A"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_tagline"><Font ss:FontName="Calibri" ss:Size="10" ss:Italic="1" ss:Color="#64748B"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_detail"><Font ss:FontName="Calibri" ss:Size="9" ss:Color="#475569"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_title"><Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#0F172A" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_header"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E40AF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style>
  <Style ss:ID="s_body"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="s_body_alt"><Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="s_num"><Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_num_alt"><Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_sl"><Font ss:FontName="Calibri" ss:Size="9" ss:Color="#94A3B8"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_sl_alt"><Font ss:FontName="Calibri" ss:Size="9" ss:Color="#94A3B8"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_sum_title"><Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1E40AF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_sum_label"><Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#0F172A"/><Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="s_sum_val"><Font ss:FontName="Consolas" ss:Size="11" ss:Bold="1" ss:Color="#1D4ED8"/><Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/><Alignment ss:Horizontal="Right" ss:Vertical="Center"/></Style>
  <Style ss:ID="s_footer"><Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#94A3B8"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style>
</Styles>

<Worksheet ss:Name="<?= cylXmlEsc($moduleLabel) ?>">
<Table>
  <Column ss:AutoFitWidth="0" ss:Width="40"/>
  <?php foreach ($columns as $key => $label): ?>
    <Column ss:AutoFitWidth="0" ss:Width="130"/>
  <?php endforeach; ?>

  <Row ss:Height="28"><?= cylMergeCell($companyName, $mergeCount, 's_company') ?></Row>
  <?php if ($companyTagline !== ''): ?><Row ss:Height="18"><?= cylMergeCell($companyTagline, $mergeCount, 's_tagline') ?></Row><?php endif; ?>
  <?php if ($companyAddress !== ''): ?><Row ss:Height="16"><?= cylMergeCell($companyAddress, $mergeCount, 's_detail') ?></Row><?php endif; ?>
  <?php
    $contactParts = [];
    if ($companyPhone !== '') $contactParts[] = 'Ph: ' . $companyPhone;
    if ($companyEmail !== '') $contactParts[] = $companyEmail;
  ?>
  <?php if ($contactParts): ?><Row ss:Height="16"><?= cylMergeCell(implode('  |  ', $contactParts), $mergeCount, 's_detail') ?></Row><?php endif; ?>

  <Row ss:Height="8"><Cell ss:StyleID="s_body"/></Row>
  <Row ss:Height="26"><?= cylMergeCell(strtoupper($moduleLabel) . ' REPORT — ' . date('d M Y, h:i A') . '  |  Total: ' . count($rows) . ' records', $mergeCount, 's_title') ?></Row>
  <Row ss:Height="6"><Cell ss:StyleID="s_body"/></Row>

  <Row ss:Height="24">
    <Cell ss:StyleID="s_header"><Data ss:Type="String">SL No.</Data></Cell>
    <?php foreach ($columns as $label): ?>
      <Cell ss:StyleID="s_header"><Data ss:Type="String"><?= cylXmlEsc($label) ?></Data></Cell>
    <?php endforeach; ?>
  </Row>

  <?php if (!$rows): ?>
    <Row ss:Height="22">
      <?= cylMergeCell('No records found.', $mergeCount, 's_body') ?>
    </Row>
  <?php else: ?>
    <?php foreach ($rows as $index => $row): ?>
      <?php
        $isAlt = ($index % 2) === 1;
        $slStyle = $isAlt ? 's_sl_alt' : 's_sl';
        $textStyle = $isAlt ? 's_body_alt' : 's_body';
        $numStyle = $isAlt ? 's_num_alt' : 's_num';
        $displaySlNo = trim((string)($row['sl_no'] ?? ''));
        if ($scope !== '') {
          $displaySlNo = (string)($index + 1);
        } elseif ($displaySlNo === '') {
          $displaySlNo = (string)($index + 1);
        }
      ?>
      <Row ss:Height="22">
        <?= cylCell($displaySlNo, $slStyle) ?>
        <?php foreach ($columns as $key => $label): ?>
          <?php $val = trim((string)($row[$key] ?? '')); ?>
          <?php if ($val !== '' && is_numeric($val)): ?>
            <?= cylNumCell($val, $numStyle) ?>
          <?php else: ?>
            <?= cylCell(dieToolingFormatDisplayNumber($val), $textStyle) ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </Row>
    <?php endforeach; ?>
  <?php endif; ?>

  <Row ss:Height="8"><Cell ss:StyleID="s_body"/></Row>
  <Row ss:Height="24"><?= cylMergeCell('SUMMARY', $mergeCount, 's_sum_title') ?></Row>
  <Row ss:Height="22">
    <?= cylMergeCell('Total Rows', max(0, $mergeCount - 1), 's_sum_label') ?>
    <?= cylNumCell(count($rows), 's_sum_val') ?>
  </Row>
  <Row ss:Height="22"><?= cylMergeCell('Generated on ' . $dateNow, $mergeCount, 's_footer') ?></Row>
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
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; color: #0f172a; margin: 20px; }
    .head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid #0f172a; padding-bottom: 14px; margin-bottom: 16px; }
    .brand { display: flex; gap: 14px; align-items: flex-start; }
    .brand img { width: 72px; max-height: 72px; object-fit: contain; }
    .name { font-size: 22px; font-weight: 700; }
    .tagline { color: #475569; margin-top: 2px; }
    .contact { margin-top: 6px; font-size: 12px; color: #475569; line-height: 1.6; }
    .report { text-align: right; }
    .report h1 { margin: 0 0 4px; font-size: 20px; }
    .report .meta { font-size: 12px; color: #475569; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #cbd5e1; padding: 6px 7px; font-size: 11px; text-align: left; }
    th { background: #e2e8f0; }
    tbody tr:nth-child(even) td { background: #f8fafc; }
    @media print {
      body { margin: 0; }
      .no-print { display: none !important; }
      .head { margin-bottom: 12px; }
    }
  </style>
</head>
<body>
  <div class="no-print" style="margin-bottom:12px;">
    <button onclick="window.print()">Print</button>
  </div>
  <div class="head">
    <div class="brand">
      <?php if ($logoUrl !== ''): ?>
        <img src="<?= e($logoUrl) ?>" alt="Logo">
      <?php endif; ?>
      <div>
        <div class="name"><?= e($companyName) ?></div>
        <?php if ($companyTagline !== ''): ?><div class="tagline"><?= e($companyTagline) ?></div><?php endif; ?>
        <div class="contact">
          <?php if ($companyAddress !== ''): ?><div><?= nl2br(e($companyAddress)) ?></div><?php endif; ?>
          <?php if ($companyPhone !== ''): ?><div>Phone: <?= e($companyPhone) ?></div><?php endif; ?>
          <?php if ($companyEmail !== ''): ?><div>Email: <?= e($companyEmail) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="report">
      <h1><?= e($title) ?></h1>
      <div class="meta">Generated: <?= e($dateNow) ?></div>
      <div class="meta">Total Rows: <?= count($rows) ?></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Sl. No.</th>
        <?php foreach ($columns as $label): ?>
          <th><?= e($label) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= count($columns) + 1 ?>">No records found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $index => $row): ?>
          <?php
            $displaySlNo = trim((string)($row['sl_no'] ?? ''));
            if ($scope !== '') {
              $displaySlNo = (string)($index + 1);
            } elseif ($displaySlNo === '') {
              $displaySlNo = (string)($index + 1);
            }
          ?>
          <tr>
            <td><?= e($displaySlNo) ?></td>
            <?php foreach ($columns as $key => $label): ?>
              <td><?= e(dieToolingFormatDisplayNumber((string)($row[$key] ?? ''))) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
