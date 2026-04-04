<?php
// ============================================================
// ERP System — Paper Stock: Export (CSV / PDF)
// Column-aware, selection-aware, with company header & summary
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db     = getDB();
$format = $_GET['format'] ?? 'csv';
$mode   = $_GET['mode']   ?? 'all';
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? APP_NAME;
$companyTagline = $appSettings['company_tagline'] ?? '';
$companyAddress = trim((string)($appSettings['erp_address'] ?? '')) !== '' ? (string)$appSettings['erp_address'] : (string)($appSettings['company_address'] ?? '');
$companyPhone = trim((string)($appSettings['erp_phone'] ?? '')) !== '' ? (string)$appSettings['erp_phone'] : (string)($appSettings['company_mobile'] ?? ($appSettings['company_phone'] ?? ''));
$companyEmail = trim((string)($appSettings['erp_email'] ?? '')) !== '' ? (string)$appSettings['erp_email'] : (string)($appSettings['company_email'] ?? '');
$companyGST = trim((string)($appSettings['erp_gst'] ?? '')) !== '' ? (string)$appSettings['erp_gst'] : (string)($appSettings['company_gst'] ?? '');
$erpLogoPath = (string)($appSettings['erp_logo_path'] ?? '');
$logoPath = $erpLogoPath !== '' ? $erpLogoPath : (string)($appSettings['logo_path'] ?? '');
$logoUrl = $logoPath ? appUrl($logoPath) : '';

// ── All 19 columns (master list) ─────────────────────────────
$allColumns = [
    'roll_no'         => 'Roll No',
    'status'          => 'Status',
    'company'         => 'Paper Company',
    'paper_type'      => 'Paper Type',
    'width_mm'        => 'Width (MM)',
    'length_mtr'      => 'Length (MTR)',
    'sqm'             => 'SQM',
    'gsm'             => 'GSM',
    'weight_kg'       => 'Weight (KG)',
    'purchase_rate'   => 'Purchase Rate',
    'date_received'   => 'Date Received',
    'date_used'       => 'Date Used',
    'job_no'          => 'Job No',
    'job_size'        => 'Job Size',
    'job_name'        => 'Job Name',
    'lot_batch_no'    => 'Lot / Batch No',
    'company_roll_no' => 'Company Roll No',
    'remarks'         => 'Remarks',
];

// ── Determine visible columns ─────────────────────────────────
$visibleColsParam = trim($_GET['visible_cols'] ?? '');
if ($visibleColsParam !== '') {
    $requestedCols = array_filter(explode(',', $visibleColsParam));
    $columns = [];
    foreach ($requestedCols as $key) {
        $key = trim($key);
        if (isset($allColumns[$key])) $columns[$key] = $allColumns[$key];
    }
    if (empty($columns)) $columns = $allColumns;
} else {
    $columns = $allColumns;
}

// ── Build WHERE clause ────────────────────────────────────────
$where  = ['1=1'];
$params = [];
$types  = '';

if ($mode === 'selected' && !empty($_GET['ids'])) {
    $ids = array_map('intval', explode(',', $_GET['ids']));
    $ids = array_filter($ids, function($id) { return $id > 0; });
    if (empty($ids)) {
        setFlash('error', 'No valid rows selected for export.');
        redirect(BASE_URL . '/modules/paper_stock/index.php');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "id IN ($placeholders)";
    $params  = array_merge($params, $ids);
    $types  .= str_repeat('i', count($ids));
} else {
    $fSearch   = trim($_GET['search']    ?? '');
    $fRollNo   = trim($_GET['roll_no']   ?? '');
    $fLotNo    = trim($_GET['lot_no']    ?? '');
    $fStatus   = trim($_GET['status']    ?? '');
    $fCompany  = trim($_GET['company']   ?? '');
    $fType     = trim($_GET['type']      ?? '');
    $fGsm      = trim($_GET['gsm']       ?? '');
    $fDateFrom = trim($_GET['date_from'] ?? '');
    $fDateTo   = trim($_GET['date_to']   ?? '');

    if ($fSearch !== '') {
        $like    = '%' . $fSearch . '%';
        $where[] = '(roll_no LIKE ? OR company LIKE ? OR paper_type LIKE ? OR lot_batch_no LIKE ? OR job_no LIKE ? OR remarks LIKE ?)';
        $params  = array_merge($params, [$like,$like,$like,$like,$like,$like]);
        $types  .= 'ssssss';
    }
    if ($fRollNo !== '') { $like = '%'.$fRollNo.'%'; $where[] = 'roll_no LIKE ?'; $params[] = $like; $types .= 's'; }
    if ($fLotNo !== '') { $like = '%'.$fLotNo.'%'; $where[] = 'lot_batch_no LIKE ?'; $params[] = $like; $types .= 's'; }
    if ($fStatus !== '') { $where[] = 'status = ?'; $params[] = $fStatus; $types .= 's'; }
    if ($fCompany !== '') { $where[] = 'company = ?'; $params[] = $fCompany; $types .= 's'; }
    if ($fType !== '') { $where[] = 'paper_type = ?'; $params[] = $fType; $types .= 's'; }
    if ($fGsm !== '') { $where[] = 'gsm = ?'; $params[] = $fGsm; $types .= 'd'; }
    if ($fDateFrom !== '') { $where[] = 'date_received >= ?'; $params[] = $fDateFrom; $types .= 's'; }
    if ($fDateTo !== '') { $where[] = 'date_received <= ?'; $params[] = $fDateTo; $types .= 's'; }
}

$whereSQL = implode(' AND ', $where);
$stmt = $db->prepare("SELECT * FROM paper_stock WHERE {$whereSQL} ORDER BY id DESC");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Compute SQM for each roll
foreach ($rolls as &$r) {
    $r['sqm'] = round(((float)($r['width_mm'] ?? 0) / 1000) * (float)($r['length_mtr'] ?? 0), 2);
}
unset($r);

if (empty($rolls)) {
    setFlash('error', 'No records found to export.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

// ── Summary totals ────────────────────────────────────────────
$totalMtr = 0; $totalSqm = 0; $totalWeight = 0;
foreach ($rolls as $r) {
    $totalMtr    += (float)($r['length_mtr'] ?? 0);
    $totalSqm    += (float)($r['sqm'] ?? 0);
    $totalWeight += (float)($r['weight_kg'] ?? 0);
}

// ── IDs Only (for delete-all AJAX) ────────────────────────────
if ($format === 'ids') {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode(',', array_column($rolls, 'id'));
    exit;
}

// Helper: format cell value for display
function fmtCell($key, $val) {
    if ($val === null || $val === '') return '-';
    if ($key === 'purchase_rate') return '₹' . number_format((float)$val, 2);
    if ($key === 'length_mtr') return number_format((float)$val, 0);
    if ($key === 'sqm') return number_format((float)$val, 2);
    if ($key === 'width_mm' || $key === 'gsm' || $key === 'weight_kg') return is_numeric($val) ? $val : e($val);
    if ($key === 'date_received' || $key === 'date_used') return $val ? date('d M Y', strtotime($val)) : '-';
    return e($val);
}

// ── CSV / Excel Export ────────────────────────────────────────
if ($format === 'csv' || $format === 'xlsx') {

    // --- Helper: XML-escape a string ---
    function xmlEsc($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // --- Helper: build an Excel XML cell ---
    function xlCell($val, $styleId = 's_body', $type = 'String') {
        $escaped = xmlEsc($val);
        return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"{$type}\">{$escaped}</Data></Cell>";
    }
    function xlNumCell($val, $styleId = 's_num') {
        $n = is_numeric($val) ? $val : 0;
        return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"Number\">{$n}</Data></Cell>";
    }
    function xlMergeCell($val, $mergeAcross, $styleId = 's_body') {
        $escaped = xmlEsc($val);
        return "<Cell ss:StyleID=\"{$styleId}\" ss:MergeAcross=\"{$mergeAcross}\"><Data ss:Type=\"String\">{$escaped}</Data></Cell>";
    }

    $numericCols = ['width_mm','length_mtr','sqm','gsm','weight_kg','purchase_rate'];
    $colCount = count($columns) + 1; // +1 for SL column
    $mergeCount = $colCount - 1;     // MergeAcross value

    $filename = 'Paper_Stock_Export_' . date('Y-m-d_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Cache-Control: no-cache, must-revalidate');

    // --- Column widths ---
    $colWidths = [40]; // SL column
    foreach (array_keys($columns) as $key) {
        if ($key === 'roll_no') $colWidths[] = 100;
        elseif ($key === 'company') $colWidths[] = 130;
        elseif ($key === 'paper_type') $colWidths[] = 110;
        elseif ($key === 'job_name') $colWidths[] = 140;
        elseif ($key === 'remarks') $colWidths[] = 150;
        elseif ($key === 'status') $colWidths[] = 90;
        elseif ($key === 'date_received' || $key === 'date_used') $colWidths[] = 95;
        elseif ($key === 'purchase_rate') $colWidths[] = 100;
        elseif ($key === 'lot_batch_no' || $key === 'company_roll_no') $colWidths[] = 110;
        elseif (in_array($key, $numericCols)) $colWidths[] = 80;
        else $colWidths[] = 100;
    }

    // --- Status color mapping ---
    $statusColors = [
        'main'          => ['bg' => '#EDE9FE', 'fg' => '#6D28D9'],
        'stock'         => ['bg' => '#DCFCE7', 'fg' => '#15803D'],
        'slitting'      => ['bg' => '#FFF7ED', 'fg' => '#C2410C'],
        'job-assign'    => ['bg' => '#FEF2F2', 'fg' => '#B91C1C'],
        'in-production' => ['bg' => '#ECFEFF', 'fg' => '#0E7490'],
        'consumed'      => ['bg' => '#F1F5F9', 'fg' => '#475569'],
        'available'     => ['bg' => '#EFF6FF', 'fg' => '#1D4ED8'],
        'assigned'      => ['bg' => '#FEFCE8', 'fg' => '#A16207'],
    ];

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">

<Styles>
  <!-- Company name -->
  <Style ss:ID="s_company">
    <Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#0F172A"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <!-- Company tagline -->
  <Style ss:ID="s_tagline">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Italic="1" ss:Color="#64748B"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <!-- Company detail line -->
  <Style ss:ID="s_detail">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#475569"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <!-- Report title bar -->
  <Style ss:ID="s_title">
    <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#0F172A" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#0F172A"/>
    </Borders>
  </Style>
  <!-- Column header -->
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
  <!-- Column header (numeric, right-aligned) -->
  <Style ss:ID="s_header_num">
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
  <!-- Body cell (white row) -->
  <Style ss:ID="s_body">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#1E293B"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <!-- Body cell (alternate gray row) -->
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
  <!-- Numeric cell (white row) -->
  <Style ss:ID="s_num">
    <Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <!-- Numeric cell (alternate gray row) -->
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
  <!-- SL No cell (centered, white) -->
  <Style ss:ID="s_sl">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#94A3B8"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <!-- SL No cell (alternate gray) -->
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
  <!-- Currency (₹) white row -->
  <Style ss:ID="s_cur">
    <Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/>
    <NumberFormat ss:Format="₹#,##0.00"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <!-- Currency (₹) alt row -->
  <Style ss:ID="s_cur_alt">
    <Font ss:FontName="Consolas" ss:Size="10" ss:Color="#1E293B"/>
    <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    <NumberFormat ss:Format="₹#,##0.00"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
  <!-- Summary label -->
  <Style ss:ID="s_sum_label">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#0F172A"/>
    <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
    </Borders>
  </Style>
  <!-- Summary value -->
  <Style ss:ID="s_sum_val">
    <Font ss:FontName="Consolas" ss:Size="11" ss:Bold="1" ss:Color="#15803D"/>
    <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BBF7D0"/>
    </Borders>
  </Style>
  <!-- Summary title bar -->
  <Style ss:ID="s_sum_title">
    <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#15803D" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#166534"/>
    </Borders>
  </Style>
  <!-- Footer -->
  <Style ss:ID="s_footer">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Italic="1" ss:Color="#94A3B8"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0F172A"/>
    </Borders>
  </Style>
  <!-- Blank -->
  <Style ss:ID="s_blank">
    <Alignment ss:Vertical="Center"/>
  </Style>
  <!-- Status styles (dynamic) -->
<?php foreach ($statusColors as $slug => $colors): ?>
  <Style ss:ID="s_st_<?= str_replace('-','_',$slug) ?>">
    <Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="<?= $colors['fg'] ?>"/>
    <Interior ss:Color="<?= $colors['bg'] ?>" ss:Pattern="Solid"/>
    <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
      <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    </Borders>
  </Style>
<?php endforeach; ?>
</Styles>

<Worksheet ss:Name="Paper Stock">
<Table>
<?php foreach ($colWidths as $w): ?>
  <Column ss:AutoFitWidth="0" ss:Width="<?= $w ?>"/>
<?php endforeach; ?>

  <!-- Company Name -->
  <Row ss:Height="28">
    <?= xlMergeCell($companyName, $mergeCount, 's_company') ?>
  </Row>
<?php if ($companyTagline): ?>
  <Row ss:Height="18">
    <?= xlMergeCell($companyTagline, $mergeCount, 's_tagline') ?>
  </Row>
<?php endif; ?>
<?php if ($companyAddress): ?>
  <Row ss:Height="16">
    <?= xlMergeCell($companyAddress, $mergeCount, 's_detail') ?>
  </Row>
<?php endif; ?>
<?php
  $contactParts = [];
  if ($companyPhone) $contactParts[] = 'Ph: ' . $companyPhone;
  if ($companyEmail) $contactParts[] = $companyEmail;
  if ($companyGST) $contactParts[] = 'GST: ' . $companyGST;
  if ($contactParts):
?>
  <Row ss:Height="16">
    <?= xlMergeCell(implode('  |  ', $contactParts), $mergeCount, 's_detail') ?>
  </Row>
<?php endif; ?>

  <!-- Blank row -->
  <Row ss:Height="8"><Cell ss:StyleID="s_blank"/></Row>

  <!-- Report Title Bar -->
  <Row ss:Height="26">
    <?= xlMergeCell('PAPER STOCK REPORT — ' . date('d M Y, h:i A') . '  |  ' . ($mode === 'selected' ? 'Selected: ' . count($rolls) . ' roll(s)' : 'All Rolls: ' . count($rolls)), $mergeCount, 's_title') ?>
  </Row>

  <!-- Blank row -->
  <Row ss:Height="6"><Cell ss:StyleID="s_blank"/></Row>

  <!-- Column Headers -->
  <Row ss:Height="24">
    <Cell ss:StyleID="s_header"><Data ss:Type="String">SL No.</Data></Cell>
<?php foreach ($columns as $key => $label):
    $isNum = in_array($key, $numericCols);
?>
    <Cell ss:StyleID="<?= $isNum ? 's_header_num' : 's_header' ?>"><Data ss:Type="String"><?= xmlEsc($label) ?></Data></Cell>
<?php endforeach; ?>
  </Row>

  <!-- Data Rows -->
<?php foreach ($rolls as $i => $r):
    $alt = ($i % 2 === 1);
    $slStyle = $alt ? 's_sl_alt' : 's_sl';
    $bodyStyle = $alt ? 's_body_alt' : 's_body';
    $numStyle = $alt ? 's_num_alt' : 's_num';
    $curStyle = $alt ? 's_cur_alt' : 's_cur';
?>
  <Row ss:Height="20">
    <?= xlNumCell($i + 1, $slStyle) ?>
<?php foreach (array_keys($columns) as $key):
    $val = $r[$key] ?? '';
    $isNum = in_array($key, $numericCols);

    // Status column — use colored style
    if ($key === 'status' && $val !== '') {
        $slug = strtolower(str_replace(' ', '-', trim($val)));
        $stStyleId = isset($statusColors[$slug]) ? 's_st_' . str_replace('-','_',$slug) : $bodyStyle;
        echo '    ' . xlCell(strtoupper($val), $stStyleId) . "\n";
    }
    // Currency column
    elseif ($key === 'purchase_rate') {
        echo '    ' . xlNumCell((float)$val, $curStyle) . "\n";
    }
    // SQM (computed)
    elseif ($key === 'sqm') {
        echo '    ' . xlNumCell(round((float)($r['sqm'] ?? 0), 2), $numStyle) . "\n";
    }
    // Other numeric
    elseif ($isNum) {
        echo '    ' . xlNumCell($val, $numStyle) . "\n";
    }
    // Date columns
    elseif (($key === 'date_received' || $key === 'date_used') && $val) {
        echo '    ' . xlCell(date('d M Y', strtotime($val)), $bodyStyle) . "\n";
    }
    // Text
    else {
        echo '    ' . xlCell($val !== '' ? $val : '-', $bodyStyle) . "\n";
    }
endforeach; ?>
  </Row>
<?php endforeach; ?>

  <!-- Blank row -->
  <Row ss:Height="10"><Cell ss:StyleID="s_blank"/></Row>

  <!-- Summary Title -->
  <Row ss:Height="24">
    <?= xlMergeCell('SUMMARY', $mergeCount, 's_sum_title') ?>
  </Row>

  <!-- Summary Rows -->
  <Row ss:Height="22">
    <?= xlCell('Total Rolls', 's_sum_label') ?>
    <?= xlNumCell(count($rolls), 's_sum_val') ?>
  </Row>
  <Row ss:Height="22">
    <?= xlCell('Total Running Meter (MTR)', 's_sum_label') ?>
    <?= xlNumCell(round($totalMtr, 0), 's_sum_val') ?>
  </Row>
  <Row ss:Height="22">
    <?= xlCell('Total Surface Area (SQM)', 's_sum_label') ?>
    <?= xlNumCell(round($totalSqm, 2), 's_sum_val') ?>
  </Row>
  <Row ss:Height="22">
    <?= xlCell('Total Weight (KG)', 's_sum_label') ?>
    <?= xlNumCell(round($totalWeight, 2), 's_sum_val') ?>
  </Row>

  <!-- Blank row -->
  <Row ss:Height="8"><Cell ss:StyleID="s_blank"/></Row>

  <!-- Footer -->
  <Row ss:Height="18">
    <?= xlMergeCell($companyName . ' — Paper Stock Report  |  Generated: ' . date('d M Y, h:i A') . '  |  ' . count($rolls) . ' records', $mergeCount, 's_footer') ?>
  </Row>

</Table>
</Worksheet>
</Workbook>
<?php
    exit;
}

// ── PDF Export (print-ready HTML, A4 landscape) ───────────────
if ($format === 'pdf') {
    $numericCols = ['width_mm','length_mtr','sqm','gsm','weight_kg','purchase_rate'];
    $colCount = count($columns);
    $usePortrait = $colCount <= 8;
    $pageSize = $usePortrait ? 'A4 portrait' : 'A4 landscape';
    $fontSize = $colCount > 14 ? '9px' : ($colCount > 10 ? '10px' : '11px');
    $thFontSize = $colCount > 14 ? '8.5px' : ($colCount > 10 ? '9px' : '10px');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Paper Stock Report — <?= date('d M Y') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
@page { size: <?= $pageSize ?>; margin: 10mm 8mm 14mm 8mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: <?= $fontSize ?>; color: #1e293b; }

/* ── Company Header ── */
.company-header {
    display: flex; align-items: center; gap: 16px;
    border-bottom: 3px solid #0f172a; padding-bottom: 12px; margin-bottom: 10px;
}
.company-logo { width: 60px; height: 60px; border-radius: 10px; object-fit: contain; border: 1px solid #e2e8f0; }
.company-info { flex: 1; }
.company-name { font-size: 22px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: .03em; line-height: 1.2; }
.company-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
.company-address { font-size: 10px; color: #475569; margin-top: 3px; line-height: 1.4; }
.company-contact { font-size: 10px; color: #64748b; margin-top: 3px; line-height: 1.4; }
.report-meta { text-align: right; min-width: 180px; }
.report-meta div { font-size: 10px; color: #64748b; line-height: 1.7; }
.report-meta strong { color: #0f172a; }

/* ── Report Title Bar ── */
.report-title-bar {
    display: flex; justify-content: space-between; align-items: center;
    background: #0f172a; color: #fff; border-radius: 8px;
    padding: 10px 18px; margin-bottom: 10px; font-size: 13px; font-weight: 700;
}
.report-title-bar .mode-badge {
    background: rgba(255,255,255,.15); border-radius: 6px; padding: 4px 12px;
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em;
}

/* ── Summary Cards ── */
.summary-cards {
    display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap;
}
.summary-card {
    flex: 1; min-width: 120px; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 8px; padding: 8px 14px; text-align: center;
}
.summary-card .sc-label { font-size: 10px; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: .08em; }
.summary-card .sc-value { font-size: 18px; font-weight: 900; color: #0f172a; margin-top: 2px; }
.summary-card .sc-unit { font-size: 10px; color: #64748b; font-weight: 600; }
.summary-card.accent { border-color: #bbf7d0; background: #f0fdf4; }
.summary-card.accent .sc-value { color: #16a34a; }

/* ── Table ── */
table { width: 100%; border-collapse: collapse; font-size: <?= $fontSize ?>; margin-bottom: 8px; }
thead { display: table-header-group; }
th {
    background: #0f172a; color: #fff; padding: 6px 8px; text-align: left;
    font-size: <?= $thFontSize ?>; text-transform: uppercase; letter-spacing: .04em;
    white-space: nowrap; border: 1px solid #1e293b;
}
td { padding: 5px 8px; border: 1px solid #e2e8f0; }
tr:nth-child(even) { background: #f8fafc; }
tr:hover { background: #fefce8; }
.num { text-align: right; font-family: 'Consolas', monospace; }
.sl-col { text-align: center; width: 34px; color: #94a3b8; font-size: 9px; }

/* Status colors in PDF */
.st-main { background: #faf5ff; color: #7c3aed; }
.st-stock { background: #f0fdf4; color: #16a34a; }
.st-slitting { background: #fff7ed; color: #ea580c; }
.st-job-assign { background: #fef2f2; color: #dc2626; }
.st-in-production { background: #ecfeff; color: #0891b2; }
.st-consumed { background: #f1f5f9; color: #64748b; }
.st-available { background: #eff6ff; color: #2563eb; }
.st-assigned { background: #fefce8; color: #ca8a04; }

.status-pill {
    display: inline-block; padding: 3px 10px; border-radius: 10px;
    font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
}

/* ── Footer ── */
.report-footer {
    display: flex; justify-content: space-between; align-items: center;
    border-top: 2px solid #0f172a; padding-top: 8px; margin-top: 10px;
    font-size: 10px; color: #94a3b8;
}
.report-footer strong { color: #0f172a; }

/* ── Print toolbar ── */
.print-toolbar {
    padding: 14px 20px; background: linear-gradient(135deg, #fefce8, #fff7ed);
    border-bottom: 2px solid #fde68a; text-align: center;
    font-size: 13px; font-weight: 700; color: #92400e;
    display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;
}
.print-toolbar button {
    padding: 8px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 12px;
    display: inline-flex; align-items: center; gap: 6px;
}
.print-toolbar .btn-print { border: none; background: #0f172a; color: #fff; }
.print-toolbar .btn-close { border: 1px solid #cbd5e1; background: #fff; color: #64748b; }

@media print {
    body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .no-print { display: none !important; }
}
</style>
</head>
<body>

<div class="print-toolbar no-print">
    <span><i class="bi bi-file-earmark-pdf" style="font-size:16px"></i> Paper Stock Report Ready</span>
    <button class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>
    <button class="btn-close" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
</div>

<div style="padding:12px 16px">

<!-- Company Header -->
<div class="company-header">
    <?php if ($logoUrl): ?>
    <img src="<?= e($logoUrl) ?>" class="company-logo" alt="Logo">
    <?php endif; ?>
    <div class="company-info">
        <div class="company-name"><?= e($companyName) ?></div>
        <?php if ($companyTagline): ?><div class="company-sub"><?= e($companyTagline) ?></div><?php endif; ?>
        <?php if ($companyAddress): ?><div class="company-address"><?= e($companyAddress) ?></div><?php endif; ?>
        <?php
        $contactLine = [];
        if ($companyPhone) $contactLine[] = 'Ph: ' . $companyPhone;
        if ($companyEmail) $contactLine[] = $companyEmail;
        if ($companyGST) $contactLine[] = 'GST: ' . $companyGST;
        if ($contactLine): ?>
        <div class="company-contact"><?= e(implode('  |  ', $contactLine)) ?></div>
        <?php endif; ?>
    </div>
    <div class="report-meta">
        <div>Generated: <strong><?= date('d M Y, h:i A') ?></strong></div>
        <div>Records: <strong><?= count($rolls) ?></strong></div>
        <div>Columns: <strong><?= $colCount ?></strong></div>
        <div>Mode: <strong><?= $mode === 'selected' ? 'Selected' : 'All Rolls' ?></strong></div>
    </div>
</div>

<!-- Report Title -->
<div class="report-title-bar">
    <span><i class="bi bi-grid"></i> PAPER STOCK REPORT</span>
    <span class="mode-badge"><?= $mode === 'selected' ? 'Selected: ' . count($rolls) . ' roll(s)' : 'Full Export: ' . count($rolls) . ' rolls' ?></span>
</div>

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="summary-card">
        <div class="sc-label">Total Rolls</div>
        <div class="sc-value"><?= number_format(count($rolls)) ?></div>
    </div>
    <div class="summary-card accent">
        <div class="sc-label">Running Meter</div>
        <div class="sc-value"><?= number_format($totalMtr, 0) ?></div>
        <div class="sc-unit">MTR</div>
    </div>
    <div class="summary-card accent">
        <div class="sc-label">Surface Area</div>
        <div class="sc-value"><?= number_format($totalSqm, 2) ?></div>
        <div class="sc-unit">SQM</div>
    </div>
    <div class="summary-card">
        <div class="sc-label">Total Weight</div>
        <div class="sc-value"><?= number_format($totalWeight, 2) ?></div>
        <div class="sc-unit">KG</div>
    </div>
</div>

<!-- Data Table -->
<table>
<thead>
<tr>
    <th class="sl-col">#</th>
    <?php foreach ($columns as $key => $label): ?>
    <th<?= in_array($key, $numericCols) ? ' style="text-align:right"' : '' ?>><?= e($label) ?></th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($rolls as $i => $r):
    $statusSlug = strtolower(str_replace(' ', '-', trim($r['status'] ?? '')));
    $statusClass = in_array($statusSlug, ['main','stock','slitting','job-assign','in-production','consumed','available','assigned']) ? 'st-' . $statusSlug : '';
?>
<tr>
    <td class="sl-col"><?= $i + 1 ?></td>
    <?php foreach (array_keys($columns) as $key):
        $val = $r[$key] ?? '';
        $isNum = in_array($key, $numericCols);
    ?>
    <td class="<?= $isNum ? 'num' : '' ?> <?= $key === 'status' ? $statusClass : '' ?>"><?php
        if ($key === 'status' && $val !== '') {
            echo '<span class="status-pill ' . $statusClass . '">' . e($val) . '</span>';
        } else {
            echo fmtCell($key, $val);
        }
    ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- Footer -->
<div class="report-footer">
    <div><strong><?= e($companyName) ?></strong> — Paper Stock Report</div>
    <div>Generated: <?= date('d M Y, h:i A') ?> | <?= count($rolls) ?> records | <?= $colCount ?> columns</div>
</div>

</div>
</body>
</html>
<?php
    exit;
}

setFlash('error', 'Invalid export format.');
redirect(BASE_URL . '/modules/paper_stock/index.php');
