<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

function barcodePlanningNumber($value): float {
    $text = trim((string)$value);
    if ($text === '') {
        return 0.0;
    }
    $text = str_replace(',', '', $text);
    return is_numeric($text) ? (float)$text : 0.0;
}

function barcodePlanningFormatNumber($value, int $decimals = 2): string {
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return trim((string)$value);
    }
    $formatted = number_format((float)$value, $decimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function barcodePlanningStatusOptions(): array {
    return ['Pending', 'Queued', 'In Progress', 'Completed', 'On Hold'];
}

function barcodePlanningIdMeta(): array {
    $idg = getPrefixSettings();
    $modulePrefix = strtoupper(trim((string)($idg['modules']['planning']['prefix'] ?? 'PLN')));
    if ($modulePrefix === '') {
        $modulePrefix = 'PLN';
    }
    // Barcode planning keeps master planning prefix and appends BAR scope.
    $barcodePrefix = stripos($modulePrefix, 'BAR') !== false ? $modulePrefix : ($modulePrefix . '-BAR');
    $separator = '/';
    $padding = 4;
    $yearToken = date('Y');
    $prefixExpr = $barcodePrefix . $separator . $yearToken . $separator;

    return [
        'prefix' => $barcodePrefix,
        'separator' => $separator,
        'padding' => $padding,
        'year_token' => $yearToken,
        'prefix_expr' => strtoupper($prefixExpr),
    ];
}

function barcodePlanningSequenceFromId($value, string $prefixExpr): int {
    $jobNo = strtoupper(trim((string)$value));
    if ($jobNo === '') {
        return 0;
    }
    if (stripos($jobNo, $prefixExpr) !== 0) {
        return 0;
    }
    $seqPart = trim((string)substr($jobNo, strlen($prefixExpr)));
    if ($seqPart === '' || !ctype_digit($seqPart)) {
        return 0;
    }
    return (int)$seqPart;
}

function barcodePlanningPreviewId(mysqli $db): string {
    $meta = barcodePlanningIdMeta();
    $max = 0;
    $res = $db->query("SELECT job_no FROM planning WHERE LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary')");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $max = max($max, barcodePlanningSequenceFromId($row['job_no'] ?? '', $meta['prefix_expr']));
        }
    }
    return buildFormattedId($meta['prefix'], $meta['year_token'], $max + 1, $meta['separator'], $meta['padding']);
}

function barcodePlanningNextSerial(mysqli $db): int {
    $res = $db->query("SELECT COALESCE(MAX(sequence_order), 0) + 1 AS next_serial FROM planning WHERE LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary')");
    $row = $res ? $res->fetch_assoc() : null;
    return max(1, (int)($row['next_serial'] ?? 1));
}

function barcodePlanningNextRecordId(mysqli $db): int {
    $res = $db->query("SHOW TABLE STATUS LIKE 'planning'");
    $row = $res ? $res->fetch_assoc() : null;
    return max(1, (int)($row['Auto_increment'] ?? 1));
}

function barcodePlanningCalcPair($qtyValue, $meterValue, $pcsPerRollValue, $upInProductionValue, $repeatValue): array {
    $qty = barcodePlanningNumber($qtyValue);
    $meter = barcodePlanningNumber($meterValue);
    $pcsPerRoll = barcodePlanningNumber($pcsPerRollValue);
    $upInProduction = barcodePlanningNumber($upInProductionValue);
    $repeat = barcodePlanningNumber($repeatValue);

    if ($qty > 0 && $meter <= 0) {
        if ($upInProduction > 0 && $repeat > 0) {
            $meter = ($qty / $upInProduction) * ($repeat / 1000);
        } elseif ($pcsPerRoll > 0) {
            $meter = $qty / $pcsPerRoll;
        }
    } elseif ($meter > 0 && $qty <= 0) {
        if ($upInProduction > 0 && $repeat > 0) {
            $qty = ($meter / ($repeat / 1000)) * $upInProduction;
        } elseif ($pcsPerRoll > 0) {
            $qty = $meter * $pcsPerRoll;
        }
    }

    return [
        'qty' => $qty > 0 ? round($qty) : 0,
        'meter' => $meter > 0 ? round($meter, 2) : 0,
    ];
}

function barcodePlanningFirstNumber($value): float {
    $text = str_replace(',', '.', trim((string)$value));
    if ($text === '') {
        return 0.0;
    }
    if (!preg_match('/-?\d+(?:\.\d+)?/', $text, $matches)) {
        return 0.0;
    }
    return is_numeric($matches[0]) ? (float)$matches[0] : 0.0;
}

function barcodePlanningResolvePaperAndMargin($barcodeSizeValue, $upInRollValue, $upInProductionValue, $labelGapValue, $paperSizeValue, $bothSideGapValue, ?string $preferredSource = null): array {
    $width = barcodePlanningFirstNumber($barcodeSizeValue);
    $upInRoll = barcodePlanningNumber($upInRollValue);
    $upInProduction = barcodePlanningNumber($upInProductionValue);
    $labelGap = barcodePlanningNumber($labelGapValue);
    $paperSize = barcodePlanningFirstNumber($paperSizeValue);
    $bothSideGap = barcodePlanningNumber($bothSideGapValue);

    if ($width <= 0 || $upInProduction <= 0) {
        return [
            'paper_size' => trim((string)$paperSizeValue),
            'both_side_gap' => trim((string)$bothSideGapValue),
            'layout_width' => '',
        ];
    }

    $layoutWidth = ($width * $upInProduction) + ($labelGap * max($upInRoll - 1, 0));
    $source = $preferredSource;
    if ($source === null) {
        if ($paperSize > 0) {
            $source = 'paper_size';
        } elseif ($bothSideGap !== 0.0 || trim((string)$bothSideGapValue) !== '') {
            $source = 'both_side_gap';
        }
    }

    if ($source === 'both_side_gap' && trim((string)$bothSideGapValue) !== '') {
        $paperSize = $layoutWidth + $bothSideGap;
    } elseif ($source === 'paper_size' && trim((string)$paperSizeValue) !== '') {
        $bothSideGap = $paperSize - $layoutWidth;
    }

    return [
        'paper_size' => trim((string)$paperSizeValue) !== '' || $source === 'both_side_gap'
            ? barcodePlanningFormatNumber($paperSize)
            : '',
        'both_side_gap' => trim((string)$bothSideGapValue) !== '' || $source === 'paper_size'
            ? barcodePlanningFormatNumber($bothSideGap)
            : '',
        'layout_width' => barcodePlanningFormatNumber($layoutWidth),
    ];
}

function barcodePlanningSuggestionBucket(array $rows, string $key): array {
    $bucket = [];
    foreach ($rows as $row) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $bucket[strtolower($value)] = $value;
    }
    natcasesort($bucket);
    return array_values($bucket);
}

function barcodePlanningMergeSuggestionLists(array ...$lists): array {
    $bucket = [];
    foreach ($lists as $list) {
        foreach ($list as $value) {
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $bucket[strtolower($text)] = $text;
        }
    }
    natcasesort($bucket);
    return array_values($bucket);
}

function barcodePlanningMasterJobRows(array $masterRows): array {
    $bucket = [];
    foreach ($masterRows as $row) {
        $jobName = trim((string)($row['use'] ?? ''));
        if ($jobName === '') {
            continue;
        }
        $lookup = strtolower($jobName . '|' . trim((string)($row['barcode_size'] ?? '')) . '|' . trim((string)($row['die_type'] ?? '')));
        if (isset($bucket[$lookup])) {
            continue;
        }
        $bucket[$lookup] = [
            'job_name' => $jobName,
            'job_label' => trim($jobName . ($row['barcode_size'] !== '' ? ' | ' . $row['barcode_size'] : '')),
            'barcode_size' => trim((string)($row['barcode_size'] ?? '')),
            'die_type' => trim((string)($row['die_type'] ?? '')),
            'paper_size' => trim((string)($row['paper_size'] ?? '')),
            'up_in_roll' => trim((string)($row['up_in_roll'] ?? '')),
            'up_in_production' => trim((string)($row['up_in_production'] ?? '')),
            'repeat' => trim((string)($row['repeat'] ?? '')),
            'core' => trim((string)($row['core'] ?? '')),
        ];
    }
    $values = array_values($bucket);
    usort($values, static function (array $a, array $b): int {
        return strcasecmp((string)$a['job_name'], (string)$b['job_name']);
    });
    return $values;
}

function barcodePlanningMasterRows(mysqli $db): array {
    $rows = [];
    $sql = "SELECT barcode_size, ups_in_roll, up_in_die, label_gap, paper_size, cylender, repeat_size, used_for, die_type, core, pices_per_roll
            FROM master_die_tooling
            WHERE TRIM(COALESCE(barcode_size, '')) <> ''
            ORDER BY barcode_size ASC, id ASC";
    $res = $db->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'barcode_size' => trim((string)($row['barcode_size'] ?? '')),
            'up_in_roll' => trim((string)($row['ups_in_roll'] ?? '')),
            'up_in_production' => trim((string)($row['up_in_die'] ?? '')),
            'label_gap' => trim((string)($row['label_gap'] ?? '')),
            'paper_size' => trim((string)($row['paper_size'] ?? '')),
            'cylinder' => trim((string)($row['cylender'] ?? '')),
            'repeat' => trim((string)($row['repeat_size'] ?? '')),
            'use' => trim((string)($row['used_for'] ?? '')),
            'die_type' => trim((string)($row['die_type'] ?? '')),
            'core' => trim((string)($row['core'] ?? '')),
            'pcs_per_roll' => trim((string)($row['pices_per_roll'] ?? '')),
        ];
    }
    return $rows;
}

function barcodePlanningPaperStockTypes(mysqli $db): array {
    $types = [];
    $res = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE TRIM(COALESCE(paper_type, '')) <> '' ORDER BY paper_type ASC");
    if (!$res) {
        return $types;
    }
    while ($row = $res->fetch_assoc()) {
        $value = trim((string)($row['paper_type'] ?? ''));
        if ($value === '') {
            continue;
        }
        $types[strtolower($value)] = $value;
    }
    return array_values($types);
}

function barcodePlanningMasterDistinctValues(mysqli $db, string $column): array {
    if (!preg_match('/^[a-z_]+$/i', $column)) {
        return [];
    }
    $values = [];
    $sql = "SELECT DISTINCT {$column} AS value FROM master_die_tooling WHERE TRIM(COALESCE({$column}, '')) <> '' ORDER BY {$column} ASC";
    $res = $db->query($sql);
    if (!$res) {
        return $values;
    }
    while ($row = $res->fetch_assoc()) {
        $value = trim((string)($row['value'] ?? ''));
        if ($value === '') {
            continue;
        }
        $values[strtolower($value)] = $value;
    }
    return array_values($values);
}

function barcodePlanningFetchRows(mysqli $db): array {
    $rows = [];
    $sql = "SELECT id, job_no, job_name, scheduled_date, status, notes, sequence_order, created_at, updated_at, extra_data
            FROM planning
            WHERE LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary')
            ORDER BY sequence_order ASC, id ASC";
    $res = $db->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetch_assoc()) {
        $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
        if (!is_array($extra)) {
            $extra = [];
        }
        $orderQtyDisplay = trim((string)($extra['order_quantity_user'] ?? ''));
        if ($orderQtyDisplay === '') {
            $orderQtyDisplay = barcodePlanningFormatNumber($extra['order_quantity'] ?? '', 0);
        }
        $orderMeterDisplay = trim((string)($extra['order_meter_user'] ?? ''));
        if ($orderMeterDisplay === '') {
            $orderMeterDisplay = barcodePlanningFormatNumber($extra['order_meter'] ?? '');
        }
        $barcodeSize = trim((string)($extra['barcode_size'] ?? ''));
        if ($barcodeSize === '') {
            $width = trim((string)($extra['width_mm'] ?? ''));
            $height = trim((string)($extra['height_mm'] ?? ''));
            if ($width !== '' || $height !== '') {
                $barcodeSize = trim($width . ($width !== '' && $height !== '' ? ' x ' : '') . $height);
            }
        }
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'sl_no' => max(1, (int)($row['sequence_order'] ?? 0)),
            'planning_id' => trim((string)($row['job_no'] ?? '')),
            'status' => trim((string)($row['status'] ?? 'Pending')) ?: 'Pending',
            'job_name' => trim((string)($row['job_name'] ?? '')),
            'planning_date' => trim((string)($extra['planning_date'] ?? '')),
            'dispatch_date' => trim((string)($extra['dispatch_date'] ?? ($row['scheduled_date'] ?? ''))),
            'material_type' => trim((string)($extra['material_type'] ?? '')),
            'order_quantity' => $orderQtyDisplay,
            'order_meter' => $orderMeterDisplay,
            'pcs_per_roll' => barcodePlanningFormatNumber($extra['pcs_per_roll'] ?? ''),
            'barcode_size' => $barcodeSize,
            'up_in_roll' => barcodePlanningFormatNumber($extra['up_in_roll'] ?? ''),
            'up_in_production' => barcodePlanningFormatNumber($extra['up_in_production'] ?? ($extra['ups'] ?? '')),
            'label_gap' => barcodePlanningFormatNumber($extra['label_gap'] ?? ''),
            'both_side_gap' => barcodePlanningFormatNumber($extra['both_side_gap'] ?? ''),
            'paper_size' => trim((string)($extra['paper_size'] ?? '')),
            'cylinder' => trim((string)($extra['cylinder'] ?? ($extra['cylender'] ?? ''))),
            'repeat' => barcodePlanningFormatNumber($extra['repeat'] ?? ($extra['height_mm'] ?? '')),
            'use' => trim((string)($extra['use'] ?? ($extra['used_for'] ?? ''))),
            'die_type' => trim((string)($extra['die_type'] ?? '')),
            'core' => trim((string)($extra['core'] ?? ($extra['core_size'] ?? ''))),
            'notes' => trim((string)($row['notes'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'updated_at' => trim((string)($row['updated_at'] ?? '')),
        ];
    }
    return $rows;
}

function barcodePlanningExportHeaders(): array {
    return [
        'SL.No.', 'Planning ID', 'Status', 'Job Name', 'Order Quantity', 'Order Meter', 'PCS PER ROLL',
        'Barcode Size', 'Up in Roll', 'Up in Production', 'Label Gap', 'Both Side Gap', 'Paper Size',
        'Cylinder', 'Repeat', 'USE', 'Die Type', 'CORE',
    ];
}

function barcodePlanningExportValues(array $row): array {
    return [
        $row['sl_no'] ?? '', $row['planning_id'] ?? '', $row['status'] ?? '', $row['job_name'] ?? '',
        $row['order_quantity'] ?? '', $row['order_meter'] ?? '', $row['pcs_per_roll'] ?? '',
        $row['barcode_size'] ?? '', $row['up_in_roll'] ?? '', $row['up_in_production'] ?? '',
        $row['label_gap'] ?? '', $row['both_side_gap'] ?? '', $row['paper_size'] ?? '',
        $row['cylinder'] ?? '', $row['repeat'] ?? '', $row['use'] ?? '', $row['die_type'] ?? '', $row['core'] ?? '',
    ];
}

function barcodePlanningExportExcel(array $rows, string $companyName): void {
    $fileName = 'barcode-planning-' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $headers = barcodePlanningExportHeaders();
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($headers) . '" style="background:#0f172a;color:#ffffff;font-size:16px">' . e($companyName) . ' - Barcode Planning</th></tr>';
    echo '<tr><th colspan="' . count($headers) . '" style="background:#eff6ff;color:#1e3a8a">Exported: ' . e(date('d M Y h:i A')) . '</th></tr>';
    echo '<tr>';
    foreach ($headers as $heading) {
        echo '<th style="background:#dbeafe;color:#1e3a8a">' . e($heading) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach (barcodePlanningExportValues($row) as $value) {
            echo '<td>' . e((string)$value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

function barcodePlanningExportPrint(array $rows, string $companyName, string $companyAddress): void {
    $headers = barcodePlanningExportHeaders();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Barcode Planning Print</title>
  <style>
    body{font-family:Arial,sans-serif;color:#0f172a;padding:24px}
    .print-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1d4ed8;padding-bottom:12px;margin-bottom:18px}
    .print-title{font-size:22px;font-weight:800}
    .print-meta{font-size:12px;color:#475569;text-align:right}
    table{width:100%;border-collapse:collapse;font-size:11px}
    th,td{border:1px solid #cbd5e1;padding:7px 8px;text-align:left}
    th{background:#eff6ff;color:#1e3a8a;text-transform:uppercase;font-size:10px;letter-spacing:.04em}
    tbody tr:nth-child(even) td{background:#f8fafc}
  </style>
</head>
<body>
  <div class="print-head">
    <div>
      <div class="print-title"><?= e($companyName) ?></div>
      <div><?= e($companyAddress) ?></div>
      <div style="margin-top:4px;font-size:13px;font-weight:700">Barcode Planning</div>
    </div>
    <div class="print-meta">
      <div>Printed: <?= e(date('d M Y h:i A')) ?></div>
      <div>Total Entries: <?= count($rows) ?></div>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <?php foreach ($headers as $heading): ?>
          <th><?= e($heading) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($headers) ?>" style="text-align:center;color:#64748b">No barcode planning entries found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <?php foreach (barcodePlanningExportValues($row) as $value): ?>
              <td><?= e((string)$value) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  <script>window.onload=function(){window.print();};</script>
</body>
</html>
<?php
    exit;
}

$db = getDB();
$pageTitle = 'Barcode Planning';
$planningPageKey = 'barcode';
$canAdd = currentPageAction('add');
$canEdit = currentPageAction('edit');
$canDelete = currentPageAction('delete');
$csrfToken = generateCSRF();
$errors = [];
$today = date('Y-m-d');
$dispatchDefault = date('Y-m-d', strtotime('+12 days'));
$statusOptions = barcodePlanningStatusOptions();
$masterRows = barcodePlanningMasterRows($db);
$coreOptions = barcodePlanningMasterDistinctValues($db, 'core');
$barcodeSizeOptions = barcodePlanningMasterDistinctValues($db, 'barcode_size');
$paperSizeOptions = barcodePlanningMasterDistinctValues($db, 'paper_size');
$dieTypeOptions = barcodePlanningMasterDistinctValues($db, 'die_type');
$useOptions = barcodePlanningMasterDistinctValues($db, 'used_for');
$masterJobRows = barcodePlanningMasterJobRows($masterRows);
$jobNameOptions = array_values(array_filter(array_map(static function (array $row): string {
    return trim((string)($row['job_label'] ?? ''));
}, $masterJobRows)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRF($token)) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'save_barcode_planning') {
            $editingId = (int)($_POST['edit_id'] ?? 0);
            $isEdit = $editingId > 0;
            if (($isEdit && !$canEdit) || (!$isEdit && !$canAdd)) {
                $errors[] = 'You do not have permission to save barcode planning.';
            } else {
                $planningDate = trim((string)($_POST['planning_date'] ?? $today));
                $dispatchDate = trim((string)($_POST['dispatch_date'] ?? $dispatchDefault));
                $status = trim((string)($_POST['status'] ?? 'Pending')) ?: 'Pending';
                $jobName = trim((string)($_POST['job_name'] ?? ''));
                $materialType = trim((string)($_POST['material_type'] ?? ''));
                $orderQtyInput = $_POST['order_quantity'] ?? '';
                $orderMeterInput = $_POST['order_meter'] ?? '';
                $orderQtyInputRaw = trim((string)$orderQtyInput);
                $orderMeterInputRaw = trim((string)$orderMeterInput);
                $pcsPerRoll = trim((string)($_POST['pcs_per_roll'] ?? ''));
                $barcodeSize = trim((string)($_POST['barcode_size'] ?? ''));
                $upInRoll = trim((string)($_POST['up_in_roll'] ?? ''));
                $upInProduction = trim((string)($_POST['up_in_production'] ?? ''));
                $labelGap = trim((string)($_POST['label_gap'] ?? ''));
                $bothSideGap = trim((string)($_POST['both_side_gap'] ?? ''));
                $paperSize = trim((string)($_POST['paper_size'] ?? ''));
                $cylinder = trim((string)($_POST['cylinder'] ?? ''));
                $repeat = trim((string)($_POST['repeat'] ?? ''));
                $use = trim((string)($_POST['use'] ?? ''));
                $dieType = trim((string)($_POST['die_type'] ?? ''));
                $core = trim((string)($_POST['core'] ?? ''));
                $slNo = max(1, (int)($_POST['sl_no'] ?? 0));
                $paperLayout = barcodePlanningResolvePaperAndMargin(
                    $barcodeSize,
                    $upInRoll,
                    $upInProduction,
                    $labelGap,
                    $paperSize,
                    $bothSideGap,
                    $paperSize !== '' ? 'paper_size' : ($bothSideGap !== '' ? 'both_side_gap' : null)
                );
                if ($paperLayout['paper_size'] !== '') {
                    $paperSize = $paperLayout['paper_size'];
                }
                if ($paperLayout['both_side_gap'] !== '') {
                    $bothSideGap = $paperLayout['both_side_gap'];
                }

                if ($planningDate === '') $errors[] = 'Planning date is required.';
                if ($dispatchDate === '') $errors[] = 'Dispatch date is required.';
                if ($jobName === '') $errors[] = 'Job Name is required.';
                if (!in_array($status, $statusOptions, true)) $errors[] = 'Invalid status selected.';
                if ($pcsPerRoll === '') $errors[] = 'PCS PER ROLL is required.';
                if ($barcodeSize === '') $errors[] = 'Barcode Size is required.';
                if ($upInRoll === '') $errors[] = 'Up in Roll is required.';
                if ($upInProduction === '') $errors[] = 'Up in Production is required.';
                if ($labelGap === '') $errors[] = 'Label Gap is required.';
                if ($bothSideGap === '') $errors[] = 'Both Side Gap is required.';
                if ($paperSize === '') $errors[] = 'Paper Size is required.';
                if ($cylinder === '') $errors[] = 'Cylinder is required.';
                if ($repeat === '') $errors[] = 'Repeat is required.';
                if ($use === '') $errors[] = 'USE is required.';
                if ($dieType === '') $errors[] = 'Die Type is required.';
                if ($core === '') $errors[] = 'CORE is required.';

                $enteredQty = barcodePlanningNumber($orderQtyInput);
                $enteredMeter = barcodePlanningNumber($orderMeterInput);
                $calculated = barcodePlanningCalcPair($orderQtyInput, $orderMeterInput, $pcsPerRoll, $upInProduction, $repeat);
                $finalQty = $enteredQty > 0 ? round($enteredQty) : (int)$calculated['qty'];
                $finalMeter = $enteredMeter > 0 ? round($enteredMeter, 2) : (float)$calculated['meter'];
                if ($finalQty <= 0 && $finalMeter <= 0) {
                    $errors[] = 'Enter Order Quantity or Order Meter to calculate planning values.';
                }

                if (empty($errors)) {
                    if (!$isEdit && $slNo <= 0) {
                        $slNo = barcodePlanningNextSerial($db);
                    }
                    $planningId = trim((string)($_POST['planning_id'] ?? ''));
                    if ($isEdit) {
                        $idStmt = $db->prepare("SELECT job_no FROM planning WHERE id = ? AND LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary') LIMIT 1");
                        if ($idStmt) {
                            $idStmt->bind_param('i', $editingId);
                            $idStmt->execute();
                            $idRow = $idStmt->get_result()->fetch_assoc() ?: [];
                            $planningId = trim((string)($idRow['job_no'] ?? $planningId));
                            $idStmt->close();
                        }
                    }
                    if ($planningId === '') {
                        $planningId = barcodePlanningPreviewId($db);
                    }

                    $payload = [
                        'planning_date' => $planningDate,
                        'dispatch_date' => $dispatchDate,
                        'material_type' => $materialType,
                        'order_quantity' => barcodePlanningFormatNumber($finalQty, 0),
                        'order_meter' => barcodePlanningFormatNumber($finalMeter),
                        'order_quantity_user' => $orderQtyInputRaw,
                        'order_meter_user' => $orderMeterInputRaw,
                        'pcs_per_roll' => barcodePlanningFormatNumber($pcsPerRoll),
                        'barcode_size' => $barcodeSize,
                        'up_in_roll' => barcodePlanningFormatNumber($upInRoll),
                        'up_in_production' => barcodePlanningFormatNumber($upInProduction),
                        'label_gap' => barcodePlanningFormatNumber($labelGap),
                        'both_side_gap' => barcodePlanningFormatNumber($bothSideGap),
                        'paper_size' => $paperSize,
                        'job_label' => trim($jobName
                            . ($barcodeSize !== '' ? ' | ' . $barcodeSize : '')
                            . ($upInProduction !== '' ? ' | ' . barcodePlanningFormatNumber($upInProduction) . ' Ups' : '')),
                        'cylinder' => $cylinder,
                        'repeat' => barcodePlanningFormatNumber($repeat),
                        'use' => $use,
                        'die_type' => $dieType,
                        'core' => $core,
                    ];
                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $notes = 'Barcode planning entry';

                    if ($isEdit) {
                        $stmt = $db->prepare("UPDATE planning SET job_no = ?, job_name = ?, scheduled_date = NULLIF(?, ''), status = ?, notes = ?, sequence_order = ?, extra_data = ?, department = 'barcode' WHERE id = ? AND LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary')");
                        if ($stmt) {
                            $stmt->bind_param('sssssisi', $planningId, $jobName, $dispatchDate, $status, $notes, $slNo, $payloadJson, $editingId);
                            if ($stmt->execute()) {
                                setFlash('success', 'Barcode planning entry updated successfully.');
                                redirect(BASE_URL . '/modules/planning/barcode/index.php');
                            }
                            $errors[] = 'Database error: ' . $db->error;
                            $stmt->close();
                        } else {
                            $errors[] = 'Could not prepare update statement.';
                        }
                    } else {
                        $createdBy = (int)($_SESSION['user_id'] ?? 0);
                        $stmt = $db->prepare("INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by, sequence_order) VALUES (?, NULL, ?, NULL, NULL, NULLIF(?, ''), ?, 'Normal', ?, 'barcode', ?, ?, ?)");
                        if ($stmt) {
                            $stmt->bind_param('ssssssii', $planningId, $jobName, $dispatchDate, $status, $notes, $payloadJson, $createdBy, $slNo);
                            if ($stmt->execute()) {
                                if (function_exists('planningCreateNotifications')) {
                                    planningCreateNotifications($db, $planningId, $jobName, 'barcode', 'added');
                                }
                                setFlash('success', 'Barcode planning entry added successfully.');
                                redirect(BASE_URL . '/modules/planning/barcode/index.php');
                            }
                            $errors[] = 'Database error: ' . $db->error;
                            $stmt->close();
                        } else {
                            $errors[] = 'Could not prepare insert statement.';
                        }
                    }
                }
            }
        } elseif ($action === 'delete_barcode_planning') {
            if (!$canDelete) {
                $errors[] = 'You do not have permission to delete barcode planning.';
            } else {
                $deleteId = (int)($_POST['id'] ?? 0);
                if ($deleteId > 0) {
                    $stmt = $db->prepare("DELETE FROM planning WHERE id = ? AND LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary') LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $deleteId);
                        $stmt->execute();
                        $stmt->close();
                        setFlash('success', 'Barcode planning entry deleted.');
                        redirect(BASE_URL . '/modules/planning/barcode/index.php');
                    }
                }
                $errors[] = 'Unable to delete the selected entry.';
            }
        }
    }
}

$rows = barcodePlanningFetchRows($db);
$paperStockTypeOptions = barcodePlanningPaperStockTypes($db);
$materialTypeOptions = barcodePlanningSuggestionBucket($rows, 'material_type');
$orderQtyOptions = barcodePlanningSuggestionBucket($rows, 'order_quantity');
$orderMeterOptions = barcodePlanningSuggestionBucket($rows, 'order_meter');
$pcsPerRollOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'pices_per_roll'),
    barcodePlanningSuggestionBucket($rows, 'pcs_per_roll')
);
$upInRollOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'ups_in_roll'),
    barcodePlanningSuggestionBucket($rows, 'up_in_roll')
);
$upInProductionOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'up_in_die'),
    barcodePlanningSuggestionBucket($rows, 'up_in_production')
);
$labelGapOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'label_gap'),
    barcodePlanningSuggestionBucket($rows, 'label_gap')
);
$bothGapOptions = barcodePlanningSuggestionBucket($rows, 'both_side_gap');
$cylinderOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'cylender'),
    barcodePlanningSuggestionBucket($rows, 'cylinder')
);
$repeatOptions = barcodePlanningMergeSuggestionLists(
    barcodePlanningMasterDistinctValues($db, 'repeat_size'),
    barcodePlanningSuggestionBucket($rows, 'repeat')
);
$materialTypeOptions = barcodePlanningMergeSuggestionLists($paperStockTypeOptions, $materialTypeOptions);
$coreOptions = barcodePlanningMergeSuggestionLists($coreOptions, barcodePlanningSuggestionBucket($rows, 'core'));
$paperSizeOptions = barcodePlanningMergeSuggestionLists($paperSizeOptions, barcodePlanningSuggestionBucket($rows, 'paper_size'));
$dieTypeOptions = barcodePlanningMergeSuggestionLists($dieTypeOptions, barcodePlanningSuggestionBucket($rows, 'die_type'));
$dieTypeOptions = barcodePlanningMergeSuggestionLists($dieTypeOptions, ['Flatbed', 'Rotary']);
$useOptions = barcodePlanningMergeSuggestionLists($useOptions, barcodePlanningSuggestionBucket($rows, 'use'));
$flash = getFlash();
$previewPlanningId = barcodePlanningPreviewId($db);
$previewSerial = barcodePlanningNextSerial($db);
$previewRecordId = barcodePlanningNextRecordId($db);
$appSettings = getAppSettings();
$companyName = trim((string)($appSettings['company_name'] ?? APP_NAME)) ?: APP_NAME;
$companyAddress = trim((string)($appSettings['company_address'] ?? ''));
$exportFormat = strtolower(trim((string)($_GET['export'] ?? '')));
if ($exportFormat === 'excel') barcodePlanningExportExcel($rows, $companyName);
if ($exportFormat === 'pdf') barcodePlanningExportPrint($rows, $companyName, $companyAddress);

include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Design &amp; Prepress</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Planning</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Barcode</span>
</div>

<?php include __DIR__ . '/../_page_switcher.php'; ?>

<style>
:root{--bc-brand:#0ea5a4;--bc-border:#d9edf0;--bc-muted:#64748b;--bc-text:#0f172a}
.bc-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.bc-header h1{font-size:1.45rem;font-weight:900;display:flex;align-items:center;gap:10px;color:var(--bc-text)}
.bc-header h1 i{font-size:1.5rem;color:var(--bc-brand)}
.bc-header p{margin-top:4px;color:var(--bc-muted);font-size:.82rem;font-weight:600}
.bc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.bc-toolbar-card{margin-bottom:14px;background:#f8fbff;border:1px solid var(--bc-border);border-radius:14px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.bc-toolbar-title{font-size:.9rem;font-weight:800;color:var(--bc-text)}.bc-toolbar-sub{font-size:.76rem;color:var(--bc-muted);font-weight:600;margin-top:2px}.bc-count{font-size:.82rem;color:#475569;font-weight:700}
.bc-table-wrap{overflow:auto}.bc-table{width:100%;min-width:2220px;border-collapse:collapse;font-size:.77rem}.bc-table th{padding:10px 12px;text-align:left;border-bottom:2px solid #dbe7ef;background:#f8fbff;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#5b6b82;white-space:nowrap}.bc-table td{padding:10px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle;white-space:nowrap}.bc-table tbody tr:hover td{background:#fbfeff}
.bc-num{font-family:Consolas,monospace;font-weight:700;color:#0f172a}.bc-status-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.bc-status-pending{background:#fef3c7;color:#92400e}.bc-status-queued{background:#e0f2fe;color:#075985}.bc-status-inprogress{background:#dbeafe;color:#1d4ed8}.bc-status-completed{background:#dcfce7;color:#166534}.bc-status-onhold{background:#fee2e2;color:#991b1b}
.bc-cell-strong{font-weight:800;color:#0f172a}.bc-empty{padding:42px 18px;text-align:center;color:#94a3b8}.bc-empty i{display:block;font-size:2.4rem;opacity:.3;margin-bottom:8px}.bc-actions-cell{display:flex;gap:6px;align-items:center}.bc-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:10px;border:1px solid #dbe4f0;background:#fff;color:#475569;cursor:pointer}.bc-icon-btn.view{color:#0369a1;border-color:#bae6fd}.bc-icon-btn.edit{color:#2563eb;border-color:#bfdbfe}.bc-icon-btn.delete{color:#dc2626;border-color:#fecaca}.bc-delete-form{display:inline}
.planning-modal{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:1200;align-items:center;justify-content:center;padding:20px}.planning-modal-card{width:min(1160px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 22px 54px rgba(0,0,0,.22);border:1px solid #e5e7eb}.planning-modal-head,.planning-modal-foot{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eef2f7}.planning-modal-foot{border-bottom:none;border-top:1px solid #eef2f7}.planning-modal-head h3{font-size:1.05rem}
.planning-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:14px 16px}.planning-grid > div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px}.planning-grid > div:nth-child(4n+1){background:#f0f9ff;border-color:#bfdbfe}.planning-grid > div:nth-child(4n+2){background:#ecfeff;border-color:#99f6e4}.planning-grid > div:nth-child(4n+3){background:#fefce8;border-color:#fde68a}.planning-grid > div:nth-child(4n){background:#f8fafc;border-color:#e2e8f0}.planning-grid label{display:block;margin-bottom:5px;font-size:.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em}.planning-job-preview-box{margin:14px 16px 0;padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.planning-job-preview-box strong{display:block;font-size:1rem;color:#0f172a}.planning-job-preview-label{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8;display:block;margin-bottom:5px}.planning-job-preview-box small{color:#475569;display:block;font-size:.74rem}
.bc-section-title{grid-column:1/-1;font-size:.74rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#0f172a;padding:8px 12px;border-radius:10px;border:1px solid #cbd5e1}.bc-section-title.basic{background:#eff6ff;border-color:#bfdbfe;color:#1e40af}.bc-section-title.order{background:#ecfeff;border-color:#99f6e4;color:#0f766e}.bc-section-title.barcode{background:#fefce8;border-color:#fde68a;color:#a16207}.bc-section-title.tools{background:#f5f3ff;border-color:#ddd6fe;color:#6d28d9}
.bc-picker-inline{display:flex;align-items:center;gap:8px}.bc-picker-inline .form-control{flex:1}.bc-picker-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #cbd5e1;background:#fff;border-radius:10px;color:#334155;cursor:pointer}
.bc-picker-search{padding:12px 16px;border-bottom:1px solid #eef2f7;display:grid;grid-template-columns:1fr 180px;gap:10px;align-items:center}.bc-picker-search input{width:100%}.bc-picker-search select{height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;background:#fff}.bc-picker-list{max-height:360px;overflow:auto;padding:10px 12px}.bc-picker-head,.bc-picker-item{display:grid;grid-template-columns:64px 1.4fr 1fr 1fr 1fr;gap:10px;align-items:center}.bc-picker-head{position:sticky;top:0;z-index:2;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 10px;font-size:.67rem;font-weight:800;letter-spacing:.04em;color:#1e3a8a;text-transform:uppercase}.bc-picker-item{width:100%;text-align:left;padding:10px;border:1px solid #dbeafe;background:#fff;border-radius:10px;cursor:pointer}.bc-picker-item + .bc-picker-item{margin-top:8px}.bc-picker-item .job{font-weight:700;color:#0f172a}.bc-picker-item .muted{font-size:.72rem;color:#64748b}
.bc-field-suggest{position:fixed;z-index:1400;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 30px rgba(15,23,42,.18);max-height:220px;overflow:auto;min-width:220px}.bc-field-suggest-item{display:block;width:100%;text-align:left;padding:8px 10px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer;font-size:.82rem;color:#0f172a}.bc-field-suggest-item:last-child{border-bottom:0}.bc-field-suggest-item:hover{background:#eff6ff}
.bc-calc-note{grid-column:1/-1;padding:10px 12px;border:1px dashed #bae6fd;background:#f0f9ff;border-radius:10px;color:#0f766e;font-size:.76rem;font-weight:600}.bc-form-hint{font-size:.72rem;color:#94a3b8;margin-top:5px}.bc-view-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}.bc-view-card{border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#fff}.bc-view-card span{display:block;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:5px}.bc-view-card strong{display:block;color:#0f172a;font-size:.86rem;word-break:break-word}
@media(max-width:1100px){.planning-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.bc-view-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:800px){.planning-grid,.planning-job-preview-box,.bc-view-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bc-actions{width:100%}.bc-picker-head,.bc-picker-item{grid-template-columns:60px 1.3fr 1fr 1fr}.bc-picker-search{grid-template-columns:1fr}}@media(max-width:640px){.planning-grid,.planning-job-preview-box,.bc-view-grid{grid-template-columns:1fr}.bc-actions .btn,.bc-actions a{flex:1 1 auto;justify-content:center}.bc-table{min-width:1800px}.bc-picker-head,.bc-picker-item{grid-template-columns:50px 1fr 1fr}.bc-picker-search{grid-template-columns:1fr}}
</style>

<div class="bc-header">
  <div>
    <h1><i class="bi bi-upc-scan"></i> Barcode Planning</h1>
    <p>Exact ERP barcode planning register with full barcode die fields, compact planning ID, and quantity-meter calculation.</p>
  </div>
  <div class="bc-actions no-print">
    <a class="btn btn-ghost" href="<?= e(BASE_URL . '/modules/planning/barcode/index.php?export=pdf') ?>" target="_blank"><i class="bi bi-printer"></i> Print / PDF</a>
    <a class="btn btn-ghost" href="<?= e(BASE_URL . '/modules/planning/barcode/index.php?export=excel') ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
    <?php if ($canAdd): ?><button type="button" class="btn btn-primary" id="btn-open-barcode-modal"><i class="bi bi-plus-circle"></i> Add Barcode Planning</button><?php endif; ?>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type'] ?? 'info') ?>" style="margin-bottom:14px"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-bottom:14px"><strong>Could not save barcode planning.</strong><ul style="margin:8px 0 0 18px"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="bc-toolbar-card no-print"><div><div class="bc-toolbar-title">Barcode Planning Register</div><div class="bc-toolbar-sub">Showing all requested barcode planning fields in the exact column sequence.</div></div><div class="bc-count"><?= count($rows) ?> entries</div></div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="bi bi-table"></i> Barcode Planning List</span></div>
  <div class="bc-table-wrap">
    <table class="bc-table">
      <thead><tr><th>SL.No.</th><th>Planning ID</th><th>Status</th><th>Job Name</th><th>Order Quantity</th><th>Order Meter</th><th>PCS PER ROLL</th><th>Barcode Size</th><th>Up in Roll</th><th>Up in Production</th><th>Label Gap</th><th>Both Side Gap</th><th>Paper Size</th><th>Cylinder</th><th>Repeat</th><th>USE</th><th>Die Type</th><th>CORE</th><th class="no-print">Action</th></tr></thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="19" class="bc-empty"><i class="bi bi-inbox"></i>No barcode planning entries found yet.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <?php $statusClass = strtolower(str_replace([' ', '-'], '', (string)($row['status'] ?? 'Pending'))); $rowPayload = htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>
            <tr data-row="<?= $rowPayload ?>">
              <td class="bc-num"><?= e((string)$row['sl_no']) ?></td><td class="bc-cell-strong"><?= e((string)$row['planning_id']) ?></td><td><span class="bc-status-badge bc-status-<?= e($statusClass) ?>"><?= e((string)$row['status']) ?></span></td><td><span class="bc-cell-strong"><?= e((string)$row['job_name']) ?></span></td><td class="bc-num"><?= e((string)$row['order_quantity']) ?></td><td class="bc-num"><?= e((string)$row['order_meter']) ?></td><td class="bc-num"><?= e((string)$row['pcs_per_roll']) ?></td><td><?= e((string)$row['barcode_size']) ?></td><td class="bc-num"><?= e((string)$row['up_in_roll']) ?></td><td class="bc-num"><?= e((string)$row['up_in_production']) ?></td><td class="bc-num"><?= e((string)$row['label_gap']) ?></td><td class="bc-num"><?= e((string)$row['both_side_gap']) ?></td><td><?= e((string)$row['paper_size']) ?></td><td><?= e((string)$row['cylinder']) ?></td><td class="bc-num"><?= e((string)$row['repeat']) ?></td><td><?= e((string)$row['use']) ?></td><td><?= e((string)$row['die_type']) ?></td><td><?= e((string)$row['core']) ?></td>
              <td class="no-print"><div class="bc-actions-cell"><button type="button" class="bc-icon-btn view btn-view-barcode" title="View"><i class="bi bi-eye"></i></button><?php if ($canEdit): ?><button type="button" class="bc-icon-btn edit btn-edit-barcode" title="Edit"><i class="bi bi-pencil"></i></button><?php endif; ?><?php if ($canDelete): ?><form method="post" class="bc-delete-form" onsubmit="return confirm('Delete this barcode planning entry?');"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="delete_barcode_planning"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button type="submit" class="bc-icon-btn delete" title="Delete"><i class="bi bi-trash"></i></button></form><?php endif; ?></div></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canAdd || $canEdit): ?>
<div class="planning-modal" id="modal-barcode-planning" style="display:none"><div class="planning-modal-card"><div class="planning-modal-head"><h3 id="barcode-modal-title">Add Barcode Planning</h3><button type="button" class="btn btn-ghost btn-sm" id="btn-close-barcode-modal"><i class="bi bi-x-lg"></i></button></div><form method="post" id="form-barcode-planning"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="save_barcode_planning"><input type="hidden" name="edit_id" id="barcode-edit-id" value="0"><div class="planning-job-preview-box"><div><span class="planning-job-preview-label">SL.No.</span><strong id="barcode-preview-sl"><?= $previewSerial ?></strong><small>Auto serial number.</small></div><div><span class="planning-job-preview-label">Planning ID</span><strong id="barcode-preview-id"><?= e($previewPlanningId) ?></strong><small>Unique auto-generated code.</small></div><div><span class="planning-job-preview-label">Record ID</span><strong id="barcode-preview-record">#<?= $previewRecordId ?></strong><small>Database preview.</small></div></div><div class="planning-grid">
        <div class="bc-section-title basic">Basic Info</div>
        <div><label for="barcode-sl-no">SL.No.</label><input type="number" class="form-control" id="barcode-sl-no" name="sl_no" min="1" value="<?= $previewSerial ?>" required></div>
        <div><label for="barcode-planning-id">Planning ID</label><input type="text" class="form-control" id="barcode-planning-id" name="planning_id" value="<?= e($previewPlanningId) ?>" readonly></div>
        <div><label for="barcode-status">Status</label><select class="form-control" id="barcode-status" name="status"><?php foreach ($statusOptions as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $statusOption === 'Pending' ? 'selected' : '' ?>><?= e($statusOption) ?></option><?php endforeach; ?></select></div>
        <div style="grid-column:1 / -1"><label for="barcode-job-name">Job Name</label><div class="bc-picker-inline"><input type="text" class="form-control" id="barcode-job-name" name="job_name" list="barcode-job-options" placeholder="Search job name from barcode master" required><button type="button" class="bc-picker-btn" id="barcode-job-picker-btn" title="Search job name"><i class="bi bi-search"></i></button></div><div class="bc-form-hint">Selecting Job Name from barcode master data auto-fills related fields. You can edit all fields before saving.</div><div class="bc-form-hint" id="barcode-size-indicator">Selected Size: —</div></div>
        <div class="bc-section-title order">Order & Planning</div>
        <div><label for="barcode-planning-date">Planning Date</label><input type="date" class="form-control" id="barcode-planning-date" name="planning_date" value="<?= e($today) ?>" required></div>
        <div><label for="barcode-dispatch-date">Dispatch Date</label><input type="date" class="form-control" id="barcode-dispatch-date" name="dispatch_date" value="<?= e($dispatchDefault) ?>" required><div class="bc-form-hint">Default is 12 days after planning date and remains editable.</div></div>
        <div><label for="barcode-material-type">Material Type</label><input type="text" class="form-control" id="barcode-material-type" name="material_type" list="barcode-material-options" placeholder="Optional material type"></div>
        <div><label for="barcode-order-qty">Order Quantity</label><input type="number" class="form-control" id="barcode-order-qty" name="order_quantity" list="barcode-order-qty-options" min="0" step="1" placeholder="Enter quantity" required></div>
        <div><label for="barcode-order-meter">Order Meter</label><input type="number" class="form-control" id="barcode-order-meter" name="order_meter" list="barcode-order-meter-options" min="0" step="0.01" placeholder="Enter meter"></div>
        <div><label for="barcode-pcs-per-roll">PCS PER ROLL</label><input type="text" inputmode="decimal" class="form-control" id="barcode-pcs-per-roll" name="pcs_per_roll" list="barcode-pcs-roll-options" placeholder="Pieces per roll" required></div>
        <div class="bc-section-title barcode">Barcode Layout</div>
        <div><label for="barcode-size">Barcode Size</label><input type="text" class="form-control" id="barcode-size" name="barcode_size" list="barcode-size-options" placeholder="e.g. 33mm x 15mm" required></div>
        <div><label for="barcode-up-roll">Up in Roll</label><input type="text" inputmode="decimal" class="form-control" id="barcode-up-roll" name="up_in_roll" list="barcode-up-roll-options" placeholder="Up in roll" required></div>
        <div><label for="barcode-up-production">Up in Production</label><input type="text" inputmode="decimal" class="form-control" id="barcode-up-production" name="up_in_production" list="barcode-up-production-options" placeholder="Up in production" required></div>
        <div><label for="barcode-label-gap">Label Gap</label><input type="text" inputmode="decimal" class="form-control" id="barcode-label-gap" name="label_gap" list="barcode-label-gap-options" placeholder="Label gap" required></div>
        <div><label for="barcode-both-gap">Both Side Gap</label><input type="text" inputmode="decimal" class="form-control" id="barcode-both-gap" name="both_side_gap" list="barcode-both-gap-options" placeholder="Both side gap" required></div>
        <div><label for="barcode-paper-size">Paper Size</label><input type="text" class="form-control" id="barcode-paper-size" name="paper_size" list="barcode-paper-size-options" placeholder="Paper size" required></div>
        <div class="bc-section-title tools">Die & Tooling</div>
        <div><label for="barcode-cylinder">Cylinder</label><input type="text" class="form-control" id="barcode-cylinder" name="cylinder" list="barcode-cylinder-options" placeholder="Cylinder" required></div>
        <div><label for="barcode-repeat">Repeat</label><input type="text" inputmode="decimal" class="form-control" id="barcode-repeat" name="repeat" list="barcode-repeat-options" placeholder="Repeat" required></div>
        <div><label for="barcode-use">USE</label><input type="text" class="form-control" id="barcode-use" name="use" list="barcode-use-options" placeholder="Use" required></div>
        <div><label for="barcode-die-type">Die Type</label><input type="text" class="form-control" id="barcode-die-type" name="die_type" list="barcode-die-type-options" placeholder="Die type" required></div>
        <div><label for="barcode-core">CORE</label><input type="text" class="form-control" id="barcode-core" name="core" list="barcode-core-options" placeholder="Core" required></div>
        <div class="bc-calc-note">If Quantity is entered, Meter is auto-calculated. If Meter is entered, Quantity is auto-calculated. Formula: (Quantity / Up in Production) x (Repeat / 1000). If Up in Production or Repeat is missing, the calculation falls back to PCS PER ROLL. Paper Size and Both Side Gap also stay linked by the barcode width formula.</div>
      </div><div class="planning-modal-foot"><button type="button" class="btn btn-ghost" id="btn-cancel-barcode-modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Save Barcode Planning</button></div></form></div></div>
<?php endif; ?>

<div class="planning-modal" id="modal-barcode-view" style="display:none"><div class="planning-modal-card"><div class="planning-modal-head"><h3>Barcode Planning Details</h3><button type="button" class="btn btn-ghost btn-sm" id="btn-close-barcode-view"><i class="bi bi-x-lg"></i></button></div><div id="barcode-view-grid" class="bc-view-grid"></div></div></div>

<datalist id="barcode-size-options"><?php foreach ($barcodeSizeOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-job-options"><?php foreach ($jobNameOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-material-options"><?php foreach ($materialTypeOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-order-qty-options"><?php foreach ($orderQtyOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-order-meter-options"><?php foreach ($orderMeterOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-pcs-roll-options"><?php foreach ($pcsPerRollOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-paper-size-options"><?php foreach ($paperSizeOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-up-roll-options"><?php foreach ($upInRollOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-up-production-options"><?php foreach ($upInProductionOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-label-gap-options"><?php foreach ($labelGapOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-both-gap-options"><?php foreach ($bothGapOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-cylinder-options"><?php foreach ($cylinderOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-repeat-options"><?php foreach ($repeatOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-die-type-options"><?php foreach ($dieTypeOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-use-options"><?php foreach ($useOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-core-options"><?php foreach ($coreOptions as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>

<div class="planning-modal" id="modal-barcode-job-picker" style="display:none">
    <div class="planning-modal-card" style="width:min(760px,100%)">
        <div class="planning-modal-head"><h3>Search Barcode Job Name</h3><button type="button" class="btn btn-ghost btn-sm" id="btn-close-job-picker"><i class="bi bi-x-lg"></i></button></div>
        <div class="bc-picker-search"><input type="text" class="form-control" id="barcode-job-picker-search" placeholder="Search by job name, barcode size, die type..."><select id="barcode-job-picker-sort"><option value="asc">Ascending (A-Z)</option><option value="desc">Descending (Z-A)</option></select></div>
        <div class="bc-picker-list" id="barcode-job-picker-list"></div>
    </div>
</div>

<script>
(function(){
  var modal = document.getElementById('modal-barcode-planning');
  var viewModal = document.getElementById('modal-barcode-view');
  var openBtn = document.getElementById('btn-open-barcode-modal');
  var closeBtn = document.getElementById('btn-close-barcode-modal');
  var cancelBtn = document.getElementById('btn-cancel-barcode-modal');
  var viewCloseBtn = document.getElementById('btn-close-barcode-view');
  var planningDateInput = document.getElementById('barcode-planning-date');
  var dispatchDateInput = document.getElementById('barcode-dispatch-date');
  var qtyInput = document.getElementById('barcode-order-qty');
  var meterInput = document.getElementById('barcode-order-meter');
  var pcsPerRollInput = document.getElementById('barcode-pcs-per-roll');
    var upInRollInput = document.getElementById('barcode-up-roll');
  var upInProductionInput = document.getElementById('barcode-up-production');
    var labelGapInput = document.getElementById('barcode-label-gap');
    var bothGapInput = document.getElementById('barcode-both-gap');
    var paperSizeInput = document.getElementById('barcode-paper-size');
  var repeatInput = document.getElementById('barcode-repeat');
  var barcodeSizeInput = document.getElementById('barcode-size');
  var form = document.getElementById('form-barcode-planning');
    var sizeIndicator = document.getElementById('barcode-size-indicator');
    var jobNameInput = document.getElementById('barcode-job-name');
    var jobPickerBtn = document.getElementById('barcode-job-picker-btn');
    var jobPickerModal = document.getElementById('modal-barcode-job-picker');
    var jobPickerCloseBtn = document.getElementById('btn-close-job-picker');
    var jobPickerSearch = document.getElementById('barcode-job-picker-search');
    var jobPickerSort = document.getElementById('barcode-job-picker-sort');
    var jobPickerList = document.getElementById('barcode-job-picker-list');
  var previewSl = document.getElementById('barcode-preview-sl');
  var previewId = document.getElementById('barcode-preview-id');
  var previewRecord = document.getElementById('barcode-preview-record');
  var modalTitle = document.getElementById('barcode-modal-title');
  var editIdInput = document.getElementById('barcode-edit-id');
  var viewGrid = document.getElementById('barcode-view-grid');
  var masterRows = <?= json_encode($masterRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var masterJobRows = <?= json_encode($masterJobRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var hasErrors = <?= !empty($errors) ? 'true' : 'false' ?>;
  var defaultState = { sl_no: <?= (int)$previewSerial ?>, planning_id: <?= json_encode($previewPlanningId) ?>, record_label: <?= json_encode('#' . $previewRecordId) ?>, planning_date: <?= json_encode($today) ?>, dispatch_date: <?= json_encode($dispatchDefault) ?>, status: 'Pending' };
  var syncing = false;
    var layoutSyncing = false;
        var lastLayoutSource = 'paper_size';

  function openModal(){ if(!modal) return; modal.style.display='flex'; document.body.style.overflow='hidden'; }
  function closeModal(){ if(!modal) return; modal.style.display='none'; document.body.style.overflow=''; }
  function openViewModal(){ if(!viewModal) return; viewModal.style.display='flex'; document.body.style.overflow='hidden'; }
  function closeViewModal(){ if(!viewModal) return; viewModal.style.display='none'; document.body.style.overflow=''; }
  function pad(value){ return String(value).padStart(2,'0'); }
  function addDays(dateText,days){ if(!dateText) return ''; var parts=dateText.split('-'); if(parts.length!==3) return ''; var date=new Date(Number(parts[0]), Number(parts[1])-1, Number(parts[2])); if(Number.isNaN(date.getTime())) return ''; date.setDate(date.getDate()+days); return date.getFullYear()+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate()); }
  function parseNum(value){ var parsed=parseFloat(String(value||'').replace(/,/g,'')); return Number.isFinite(parsed)?parsed:0; }
    function normalizeText(value){ return String(value||'').trim().toLowerCase(); }
    function safeText(value){ return String(value==null?'':value).replace(/[&<>\"']/g, function(ch){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'})[ch] || ch; }); }
    function formatNum(value,decimals){ if(!Number.isFinite(value)||value<=0) return ''; return String(Number(value.toFixed(decimals))); }
        function formatDerivedNum(value,decimals){ if(!Number.isFinite(value)) return ''; return String(Number(value.toFixed(decimals))); }
        function firstNumber(value){ var match=String(value||'').replace(/,/g,'.').match(/-?\d+(?:\.\d+)?/); return match ? parseFloat(match[0]) || 0 : 0; }
    function updateSizeIndicator(sizeValue){ if(!sizeIndicator) return; var v=String(sizeValue||'').trim(); sizeIndicator.textContent='Selected Size: '+(v!==''?v:'—'); }
        function calcLayoutWidth(){ var width=firstNumber(barcodeSizeInput&&barcodeSizeInput.value); var upInProduction=parseNum(upInProductionInput&&upInProductionInput.value); var upInRoll=parseNum(upInRollInput&&upInRollInput.value); var labelGap=parseNum(labelGapInput&&labelGapInput.value); if(width<=0||upInProduction<=0) return 0; return (width*upInProduction) + (labelGap*Math.max(upInRoll-1,0)); }
        function syncMarginFromPaper(){ if(layoutSyncing||!paperSizeInput||!bothGapInput) return; var layoutWidth=calcLayoutWidth(); var paper=parseNum(paperSizeInput.value); if(layoutWidth<=0||String(paperSizeInput.value||'').trim()==='') return; layoutSyncing=true; bothGapInput.value = formatDerivedNum(paper-layoutWidth,2); layoutSyncing=false; lastLayoutSource='paper_size'; }
        function syncPaperFromMargin(){ if(layoutSyncing||!paperSizeInput||!bothGapInput) return; var layoutWidth=calcLayoutWidth(); if(layoutWidth<=0||String(bothGapInput.value||'').trim()==='') return; var margin=parseNum(bothGapInput.value); layoutSyncing=true; paperSizeInput.value = formatDerivedNum(layoutWidth+margin,2); layoutSyncing=false; lastLayoutSource='both_side_gap'; }
        function hydrateLayoutFields(){ if(!paperSizeInput||!bothGapInput) return; var paperRaw=String(paperSizeInput.value||'').trim(); var marginRaw=String(bothGapInput.value||'').trim(); if(lastLayoutSource==='both_side_gap'&&marginRaw!==''){ syncPaperFromMargin(); } else if(paperRaw!==''){ syncMarginFromPaper(); } else if(marginRaw!==''){ syncPaperFromMargin(); } }
    function calcMeterFromQty(){ var qty=parseNum(qtyInput&&qtyInput.value); var pcsPerRoll=parseNum(pcsPerRollInput&&pcsPerRollInput.value); var upInProduction=parseNum(upInProductionInput&&upInProductionInput.value); var repeat=parseNum(repeatInput&&repeatInput.value); if(qty<=0) return ''; if(upInProduction>0&&repeat>0) return formatNum((qty/upInProduction)*(repeat/1000),2); if(pcsPerRoll>0) return formatNum(qty/pcsPerRoll,2); return ''; }
    function calcQtyFromMeter(){ var meter=parseNum(meterInput&&meterInput.value); var pcsPerRoll=parseNum(pcsPerRollInput&&pcsPerRollInput.value); var upInProduction=parseNum(upInProductionInput&&upInProductionInput.value); var repeat=parseNum(repeatInput&&repeatInput.value); if(meter<=0) return ''; if(upInProduction>0&&repeat>0) return formatNum((meter/(repeat/1000))*upInProduction,0); if(pcsPerRoll>0) return formatNum(meter*pcsPerRoll,0); return ''; }
  function syncFromQty(){ if(syncing||!meterInput) return; syncing=true; meterInput.value=calcMeterFromQty(); syncing=false; }
  function syncFromMeter(){ if(syncing||!qtyInput) return; syncing=true; qtyInput.value=calcQtyFromMeter(); syncing=false; }
    function findMasterRowByBarcodeSize(value){ var normalized=normalizeText(value); if(!normalized) return null; var wantedSig=sizeSignature(value); for(var i=0;i<masterRows.length;i++){ var row=masterRows[i]||{}; var rowSize=normalizeText(row.barcode_size||''); if(rowSize===normalized){ return row; } if(wantedSig!==''&&sizeSignature(row.barcode_size||'')===wantedSig){ return row; } } return null; }
    function masterValueBySize(sizeValue, key){ var wantedSig=sizeSignature(sizeValue); if(!wantedSig) return ''; for(var i=0;i<masterRows.length;i++){ var row=masterRows[i]||{}; if(sizeSignature(row.barcode_size||'')!==wantedSig) continue; var val=String(row[key]||'').trim(); if(val!=='') return val; } return ''; }
        function parseUpsToken(value){ var m=String(value||'').match(/-?\d+(?:\.\d+)?/); return m ? normalizeText(m[0]) : ''; }
        function sizeSignature(value){
            var nums = String(value||'').replace(/,/g,'.').match(/-?\d+(?:\.\d+)?/g) || [];
            if (!nums.length) return normalizeText(value).replace(/mm/g,'').replace(/\s+/g,' ').trim();
            return nums.slice(0, 3).map(function(n){ var x=parseFloat(n); return Number.isFinite(x) ? String(x) : normalizeText(n); }).join('x');
        }
        function buildJobLabel(jobName,size,upsInProduction){ var j=String(jobName||'').trim(); var s=String(size||'').trim(); var u=String(upsInProduction||'').trim(); if(j===''&&s===''&&u==='') return ''; var out=j; if(s!==''){ out += (out!==''?' | ':'') + s; } if(u!==''){ out += (out!==''?' | ':'') + u + ' Ups'; } return out; }
        function findMasterRowByJobName(value){
            var raw = String(value||'').trim();
            if(!raw) return null;
            var normalizedRaw = normalizeText(raw);

            for (var x = 0; x < masterJobRows.length; x++) {
                var mj = masterJobRows[x] || {};
                if (normalizeText(mj.job_label || '') === normalizedRaw) {
                    var byLabel = (masterRows || []).find(function(r){
                        return normalizeText(r.use||'') === normalizeText(mj.job_name||'')
                            && normalizeText(r.barcode_size||'') === normalizeText(mj.barcode_size||'')
                            && normalizeText(String(r.up_in_production||'')) === normalizeText(String(mj.up_in_production||''));
                    });
                    if (byLabel) return byLabel;
                }
            }

            var parts = raw.split('|').map(function(p){ return String(p||'').trim(); });
            var jobPart = normalizeText(parts[0] || raw);
            var sizePart = normalizeText(parts[1] || '');
            var upsPart = parseUpsToken(parts[2] || '');
            if(!jobPart) return null;

            var candidates = [];
            for (var i=0;i<masterRows.length;i++) {
                var row = masterRows[i] || {};
                if (normalizeText(row.use||'') !== jobPart) continue;
                candidates.push(row);
            }
            if (!candidates.length) {
                for (var j=0;j<masterRows.length;j++) {
                    var row2 = masterRows[j] || {};
                    if (normalizeText(row2.use||'').indexOf(jobPart) !== -1) candidates.push(row2);
                }
            }
            if (!candidates.length) return null;

            if (sizePart) {
                var wantedSig = sizeSignature(sizePart);
                var sizeMatch = candidates.find(function(r){
                    var rowSize = normalizeText(r.barcode_size||'');
                    if (rowSize === sizePart) return true;
                    var rowSig = sizeSignature(rowSize);
                    return rowSig !== '' && wantedSig !== '' && rowSig === wantedSig;
                });
                if (sizeMatch) return sizeMatch;
            }
            if (upsPart) {
                var upsMatch = candidates.find(function(r){ return parseUpsToken(r.up_in_production||'') === upsPart; });
                if (upsMatch) return upsMatch;
            }
            return candidates[0] || null;
        }
  function assignIfPresent(id,value){ var el=document.getElementById(id); if(!el) return; el.value=value==null?'':String(value); }
    function applyMasterRow(row, updateJobName){ if(!row) return; var sizeVal=row.barcode_size||''; var labelGapVal=String(row.label_gap||'').trim()||masterValueBySize(sizeVal,'label_gap'); var paperVal=String(row.paper_size||'').trim()||masterValueBySize(sizeVal,'paper_size'); var cylVal=String(row.cylinder||'').trim()||masterValueBySize(sizeVal,'cylinder'); var repVal=String(row.repeat||'').trim()||masterValueBySize(sizeVal,'repeat'); var useVal=String(row.use||'').trim()||masterValueBySize(sizeVal,'use'); var dieVal=String(row.die_type||'').trim()||masterValueBySize(sizeVal,'die_type'); var coreVal=String(row.core||'').trim()||masterValueBySize(sizeVal,'core'); assignIfPresent('barcode-size', sizeVal); assignIfPresent('barcode-pcs-per-roll', row.pcs_per_roll||''); assignIfPresent('barcode-up-roll', row.up_in_roll||''); assignIfPresent('barcode-up-production', row.up_in_production||''); assignIfPresent('barcode-label-gap', labelGapVal); assignIfPresent('barcode-paper-size', paperVal); assignIfPresent('barcode-both-gap', ''); assignIfPresent('barcode-cylinder', cylVal); assignIfPresent('barcode-repeat', repVal); assignIfPresent('barcode-use', useVal); assignIfPresent('barcode-die-type', dieVal); assignIfPresent('barcode-core', coreVal); if(updateJobName){ assignIfPresent('barcode-job-name', buildJobLabel(useVal||row.use||'', sizeVal, row.up_in_production||'')); } lastLayoutSource='paper_size'; updateSizeIndicator(sizeVal); hydrateLayoutFields(); if(parseNum(qtyInput&&qtyInput.value)>0) syncFromQty(); else if(parseNum(meterInput&&meterInput.value)>0) syncFromMeter(); }
    function autofillFromMaster(){ var row=findMasterRowByBarcodeSize(barcodeSizeInput&&barcodeSizeInput.value); if(!row) return; applyMasterRow(row, false); }
    function autofillFromJobName(){ var row=findMasterRowByJobName(jobNameInput&&jobNameInput.value); if(!row) return; assignIfPresent('barcode-size', row.barcode_size||''); applyMasterRow(row, true); }
    function openJobPicker(){ if(!jobPickerModal) return; renderJobPickerList(); jobPickerModal.style.display='flex'; document.body.style.overflow='hidden'; if(jobPickerSearch){ jobPickerSearch.value=''; jobPickerSearch.focus(); renderJobPickerList(); } }
    function closeJobPicker(){ if(!jobPickerModal) return; jobPickerModal.style.display='none'; document.body.style.overflow=''; }
    function renderJobPickerList(){ if(!jobPickerList) return; var needle=normalizeText(jobPickerSearch&&jobPickerSearch.value); var dir=String((jobPickerSort&&jobPickerSort.value)||'asc').trim()==='desc'?'desc':'asc'; var html=[]; var rowNo=0; var matches=[]; for(var i=0;i<masterJobRows.length;i++){ var item=masterJobRows[i]||{}; var hay=normalizeText(item.job_name)+' '+normalizeText(item.barcode_size)+' '+normalizeText(item.die_type)+' '+normalizeText(item.paper_size)+' '+normalizeText(item.up_in_roll)+' '+normalizeText(item.up_in_production); if(needle && hay.indexOf(needle)===-1) continue; matches.push({idx:i,item:item}); }
        matches.sort(function(a,b){ var aj=normalizeText(a.item.job_name); var bj=normalizeText(b.item.job_name); var cmp=aj.localeCompare(bj, undefined, { numeric:true, sensitivity:'base' }); if(cmp===0){ cmp=normalizeText(a.item.barcode_size).localeCompare(normalizeText(b.item.barcode_size), undefined, { numeric:true, sensitivity:'base' }); } return dir==='asc' ? cmp : -cmp; });
        matches.forEach(function(entry){ var item=entry.item||{}; rowNo += 1; html.push('<button type="button" class="bc-picker-item" data-idx="'+entry.idx+'"><span class="muted">'+rowNo+'</span><span class="job">'+safeText(item.job_name?String(item.job_name):'—')+'</span><span>'+safeText(item.barcode_size?String(item.barcode_size):'—')+'</span><span>'+safeText(item.up_in_roll?String(item.up_in_roll):'—')+'</span><span>'+safeText(item.up_in_production?String(item.up_in_production):'—')+'</span></button>'); });
        var head = '<div class="bc-picker-head"><span>SL No.</span><span>Job Name</span><span>BarCode Size</span><span>Ups in Roll</span><span>UPS in Die</span></div>';
        jobPickerList.innerHTML = html.length ? (head + html.join('')) : '<div class="bc-empty" style="padding:18px">No matching barcode job name found.</div>';
    }
    function resetFormForAdd(){ if(!form) return; form.reset(); editIdInput.value='0'; if(modalTitle) modalTitle.textContent='Add Barcode Planning'; assignIfPresent('barcode-sl-no', defaultState.sl_no); assignIfPresent('barcode-planning-id', defaultState.planning_id); assignIfPresent('barcode-planning-date', defaultState.planning_date); assignIfPresent('barcode-dispatch-date', defaultState.dispatch_date); assignIfPresent('barcode-status', defaultState.status); lastLayoutSource='paper_size'; updateSizeIndicator(''); previewSl.textContent=String(defaultState.sl_no); previewId.textContent=defaultState.planning_id; previewRecord.textContent=defaultState.record_label; hydrateLayoutFields(); }
    function openEdit(row){ if(!form||!row) return; resetFormForAdd(); if(modalTitle) modalTitle.textContent='Edit Barcode Planning'; editIdInput.value=String(row.id||0); assignIfPresent('barcode-sl-no', row.sl_no||''); assignIfPresent('barcode-planning-id', row.planning_id||''); assignIfPresent('barcode-status', row.status||'Pending'); assignIfPresent('barcode-job-name', row.job_name||''); assignIfPresent('barcode-planning-date', row.planning_date||defaultState.planning_date); assignIfPresent('barcode-dispatch-date', row.dispatch_date||defaultState.dispatch_date); assignIfPresent('barcode-material-type', row.material_type||''); assignIfPresent('barcode-order-qty', row.order_quantity||''); assignIfPresent('barcode-order-meter', row.order_meter||''); assignIfPresent('barcode-pcs-per-roll', row.pcs_per_roll||''); assignIfPresent('barcode-size', row.barcode_size||''); assignIfPresent('barcode-up-roll', row.up_in_roll||''); assignIfPresent('barcode-up-production', row.up_in_production||''); assignIfPresent('barcode-label-gap', row.label_gap||''); assignIfPresent('barcode-both-gap', row.both_side_gap||''); assignIfPresent('barcode-paper-size', row.paper_size||''); assignIfPresent('barcode-cylinder', row.cylinder||''); assignIfPresent('barcode-repeat', row.repeat||''); assignIfPresent('barcode-use', row.use||''); assignIfPresent('barcode-die-type', row.die_type||''); assignIfPresent('barcode-core', row.core||''); lastLayoutSource = String(row.both_side_gap||'').trim() !== '' && String(row.paper_size||'').trim() === '' ? 'both_side_gap' : 'paper_size'; hydrateLayoutFields(); updateSizeIndicator(row.barcode_size||''); previewSl.textContent=String(row.sl_no||''); previewId.textContent=String(row.planning_id||''); previewRecord.textContent='#'+String(row.id||''); openModal(); }
  function openView(row){ if(!viewGrid||!row) return; var labels=[['SL.No.',row.sl_no],['Planning ID',row.planning_id],['Status',row.status],['Job Name',row.job_name],['Order Quantity',row.order_quantity],['Order Meter',row.order_meter],['PCS PER ROLL',row.pcs_per_roll],['Barcode Size',row.barcode_size],['Up in Roll',row.up_in_roll],['Up in Production',row.up_in_production],['Label Gap',row.label_gap],['Both Side Gap',row.both_side_gap],['Paper Size',row.paper_size],['Cylinder',row.cylinder],['Repeat',row.repeat],['USE',row.use],['Die Type',row.die_type],['CORE',row.core],['Planning Date',row.planning_date],['Dispatch Date',row.dispatch_date],['Material Type',row.material_type]]; var html=''; labels.forEach(function(item){ html+='<div class="bc-view-card"><span>'+item[0]+'</span><strong>'+(item[1]?String(item[1]):'—')+'</strong></div>'; }); viewGrid.innerHTML=html; openViewModal(); }
  if(openBtn) openBtn.addEventListener('click', function(){ resetFormForAdd(); openModal(); });
  if(closeBtn) closeBtn.addEventListener('click', closeModal);
  if(cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if(viewCloseBtn) viewCloseBtn.addEventListener('click', closeViewModal);
  if(modal) modal.addEventListener('click', function(event){ if(event.target===modal) closeModal(); });
  if(viewModal) viewModal.addEventListener('click', function(event){ if(event.target===viewModal) closeViewModal(); });
  if(planningDateInput&&dispatchDateInput){ planningDateInput.addEventListener('change', function(){ if(String(editIdInput&&editIdInput.value||'0')==='0'){ dispatchDateInput.value=addDays(planningDateInput.value,12); } }); }
  if(qtyInput) qtyInput.addEventListener('input', syncFromQty);
  if(meterInput) meterInput.addEventListener('input', syncFromMeter);
    [pcsPerRollInput, upInProductionInput, repeatInput].forEach(function(el){ if(!el) return; el.addEventListener('input', function(){ if(parseNum(qtyInput&&qtyInput.value)>0) syncFromQty(); else if(parseNum(meterInput&&meterInput.value)>0) syncFromMeter(); }); });
    [barcodeSizeInput, upInRollInput, upInProductionInput, labelGapInput].forEach(function(el){ if(!el) return; el.addEventListener('input', hydrateLayoutFields); el.addEventListener('change', hydrateLayoutFields); });
        if(barcodeSizeInput){ barcodeSizeInput.addEventListener('change', autofillFromMaster); barcodeSizeInput.addEventListener('blur', autofillFromMaster); barcodeSizeInput.addEventListener('input', function(){ updateSizeIndicator(barcodeSizeInput.value); autofillFromMaster(); hydrateLayoutFields(); }); }
        if(paperSizeInput){ paperSizeInput.addEventListener('input', function(){ lastLayoutSource='paper_size'; syncMarginFromPaper(); }); paperSizeInput.addEventListener('change', function(){ lastLayoutSource='paper_size'; syncMarginFromPaper(); }); }
        if(bothGapInput){ bothGapInput.addEventListener('input', function(){ lastLayoutSource='both_side_gap'; syncPaperFromMargin(); }); bothGapInput.addEventListener('change', function(){ lastLayoutSource='both_side_gap'; syncPaperFromMargin(); }); }
    if(jobNameInput){ jobNameInput.addEventListener('change', autofillFromJobName); jobNameInput.addEventListener('blur', autofillFromJobName); jobNameInput.addEventListener('input', autofillFromJobName); }
    if(jobPickerBtn){ jobPickerBtn.addEventListener('click', openJobPicker); }
    if(jobPickerCloseBtn){ jobPickerCloseBtn.addEventListener('click', closeJobPicker); }
    if(jobPickerSearch){ jobPickerSearch.addEventListener('input', renderJobPickerList); }
    if(jobPickerSort){ jobPickerSort.addEventListener('change', renderJobPickerList); }
    if(jobPickerModal){ jobPickerModal.addEventListener('click', function(event){ if(event.target===jobPickerModal) closeJobPicker(); }); }
    if(jobPickerList){ jobPickerList.addEventListener('click', function(event){ var button=event.target&&event.target.closest?event.target.closest('.bc-picker-item'):null; if(!button) return; var idx=parseInt(button.getAttribute('data-idx')||'',10); if(!Number.isFinite(idx)||idx<0||idx>=masterJobRows.length) return; var picked=masterJobRows[idx]||{}; assignIfPresent('barcode-job-name', buildJobLabel(picked.job_name||'', picked.barcode_size||'', picked.up_in_production||'')); assignIfPresent('barcode-size', picked.barcode_size||''); autofillFromJobName(); closeJobPicker(); }); }
    if(form){ form.addEventListener('submit', function(){ if(parseNum(qtyInput&&qtyInput.value)>0&&(!meterInput||parseNum(meterInput.value)<=0)){ syncFromQty(); } else if(parseNum(meterInput&&meterInput.value)>0&&(!qtyInput||parseNum(qtyInput.value)<=0)){ syncFromMeter(); } if(paperSizeInput&&String(paperSizeInput.value||'').trim()!==''){ syncMarginFromPaper(); } else if(bothGapInput&&String(bothGapInput.value||'').trim()!==''){ syncPaperFromMargin(); } }); }

    var fieldSuggest=document.createElement('div');
    fieldSuggest.className='bc-field-suggest';
    fieldSuggest.style.display='none';
    document.body.appendChild(fieldSuggest);
    var activeSuggestInput=null;

    function listValuesFromInput(input){
        if(!input) return [];
        var listId=String(input.getAttribute('list')||'').trim();
        if(!listId) return [];
        var listEl=document.getElementById(listId);
        if(!listEl) return [];
        return Array.prototype.map.call(listEl.querySelectorAll('option'), function(opt){ return String(opt.value||'').trim(); }).filter(function(v){ return v!==''; });
    }

    function hideFieldSuggest(){
        fieldSuggest.style.display='none';
        fieldSuggest.innerHTML='';
        activeSuggestInput=null;
    }

    function placeFieldSuggest(input){
        var r=input.getBoundingClientRect();
        fieldSuggest.style.left=(r.left+window.scrollX)+'px';
        fieldSuggest.style.top=(r.bottom+window.scrollY+4)+'px';
        fieldSuggest.style.minWidth=Math.max(220, r.width)+'px';
    }

    function showFieldSuggest(input, forceAll){
        var all=listValuesFromInput(input);
        if(!all.length){ hideFieldSuggest(); return; }
        var needle=forceAll ? '' : normalizeText(input.value||'');
        var filtered=all.filter(function(v){ var n=normalizeText(v); return needle==='' || n.indexOf(needle)!==-1; }).slice(0, 50);
        if(!filtered.length){ hideFieldSuggest(); return; }
        fieldSuggest.innerHTML=filtered.map(function(v){ return '<button type="button" class="bc-field-suggest-item" data-value="'+safeText(v)+'">'+safeText(v)+'</button>'; }).join('');
        activeSuggestInput=input;
        placeFieldSuggest(input);
        fieldSuggest.style.display='block';
    }

    fieldSuggest.addEventListener('mousedown', function(event){
        var btn=event.target&&event.target.closest?event.target.closest('.bc-field-suggest-item'):null;
        if(!btn||!activeSuggestInput) return;
        event.preventDefault();
        activeSuggestInput.value=String(btn.getAttribute('data-value')||'');
        activeSuggestInput.dispatchEvent(new Event('input', { bubbles:true }));
        activeSuggestInput.dispatchEvent(new Event('change', { bubbles:true }));
        hideFieldSuggest();
    });

    document.addEventListener('click', function(event){
        if(fieldSuggest.style.display==='none') return;
        if(activeSuggestInput && (event.target===activeSuggestInput || activeSuggestInput.contains(event.target))) return;
        if(event.target&&event.target.closest&&event.target.closest('.bc-field-suggest')) return;
        hideFieldSuggest();
    });

    window.addEventListener('resize', function(){ if(activeSuggestInput&&fieldSuggest.style.display!=='none') placeFieldSuggest(activeSuggestInput); });
    window.addEventListener('scroll', function(){ if(activeSuggestInput&&fieldSuggest.style.display!=='none') placeFieldSuggest(activeSuggestInput); }, true);

    [
        'barcode-size','barcode-up-roll','barcode-up-production','barcode-label-gap','barcode-both-gap','barcode-paper-size',
        'barcode-cylinder','barcode-repeat','barcode-use','barcode-die-type','barcode-core'
    ].forEach(function(id){
        var el=document.getElementById(id);
        if(!el) return;
        el.addEventListener('focus', function(){ showFieldSuggest(el, true); });
        el.addEventListener('click', function(){ showFieldSuggest(el, true); });
        el.addEventListener('input', function(){ showFieldSuggest(el); });
        el.addEventListener('blur', function(){ setTimeout(function(){ if(document.activeElement!==el){ hideFieldSuggest(); } }, 120); });
    });

  document.querySelectorAll('.btn-edit-barcode').forEach(function(btn){ btn.addEventListener('click', function(){ var row=this.closest('tr'); if(!row) return; try{ openEdit(JSON.parse(row.getAttribute('data-row')||'{}')); } catch(err){} }); });
  document.querySelectorAll('.btn-view-barcode').forEach(function(btn){ btn.addEventListener('click', function(){ var row=this.closest('tr'); if(!row) return; try{ openView(JSON.parse(row.getAttribute('data-row')||'{}')); } catch(err){} }); });
  if(hasErrors) openModal();
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>