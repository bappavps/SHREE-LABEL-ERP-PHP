<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

redirect(BASE_URL . '/modules/planning/barcode/index.php');

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

function barcodePlanningCalcPair($qtyValue, $meterValue, $upsValue, $heightValue): array {
    $qty = barcodePlanningNumber($qtyValue);
    $meter = barcodePlanningNumber($meterValue);
    $ups = barcodePlanningNumber($upsValue);
    $heightMm = barcodePlanningNumber($heightValue);
    $repeatM = $heightMm > 0 ? ($heightMm / 1000) : 0;

    if ($qty > 0 && $meter <= 0 && $ups > 0 && $repeatM > 0) {
        $meter = ($qty / $ups) * $repeatM;
    } elseif ($meter > 0 && $qty <= 0 && $ups > 0 && $repeatM > 0) {
        $qty = ($meter / $repeatM) * $ups;
    }

    return [
        'qty' => $qty > 0 ? round($qty) : 0,
        'meter' => $meter > 0 ? round($meter, 2) : 0,
    ];
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

function barcodePlanningMaterialSuggestions(mysqli $db): array {
    $bucket = [];
    $queries = [
        "SELECT DISTINCT material_type AS value FROM sales_orders WHERE TRIM(COALESCE(material_type, '')) <> ''",
        "SELECT DISTINCT paper_type AS value FROM paper_stock WHERE TRIM(COALESCE(paper_type, '')) <> ''",
    ];
    foreach ($queries as $sql) {
        $res = $db->query($sql);
        if (!$res) {
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $value = trim((string)($row['value'] ?? ''));
            if ($value === '' || strtoupper($value) === 'NULL') {
                continue;
            }
            $bucket[strtolower($value)] = $value;
        }
    }

        $planRes = $db->query("SELECT extra_data FROM planning WHERE LOWER(COALESCE(department, '')) IN ('barcode','rotery','rotary')");
        if ($planRes) {
          while ($row = $planRes->fetch_assoc()) {
            $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
            if (!is_array($extra)) {
              continue;
            }
            $value = trim((string)($extra['material_type'] ?? ''));
            if ($value === '') {
              continue;
            }
            $bucket[strtolower($value)] = $value;
          }
        }

    natcasesort($bucket);
    return array_values($bucket);
}

function barcodePlanningCoreSuggestions(mysqli $db): array {
    $bucket = [];
    $res = $db->query("SELECT DISTINCT core FROM master_die_tooling WHERE TRIM(COALESCE(core, '')) <> '' ORDER BY core ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $value = trim((string)($row['core'] ?? ''));
            if ($value === '') {
                continue;
            }
            $bucket[strtolower($value)] = $value;
        }
    }
    natcasesort($bucket);
    return array_values($bucket);
}

function barcodePlanningFetchRows(mysqli $db): array {
    $rows = [];
    $sql = "SELECT id, job_no, job_name, scheduled_date, status, priority, notes, sequence_order, created_at, updated_at, extra_data
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
        $width = barcodePlanningFormatNumber($extra['width_mm'] ?? '');
        $height = barcodePlanningFormatNumber($extra['height_mm'] ?? '');
        $row['planning_date'] = trim((string)($extra['planning_date'] ?? ''));
        $row['dispatch_date'] = trim((string)($extra['dispatch_date'] ?? ($row['scheduled_date'] ?? '')));
        $row['material_type'] = trim((string)($extra['material_type'] ?? ''));
        $row['width_mm'] = $width;
        $row['height_mm'] = $height;
        $row['size_label'] = trim($width . ($width !== '' || $height !== '' ? ' x ' : '') . $height, ' x');
        $row['ups'] = barcodePlanningFormatNumber($extra['ups'] ?? '');
        $row['core_size'] = trim((string)($extra['core_size'] ?? ''));
        $row['order_quantity'] = barcodePlanningFormatNumber($extra['order_quantity'] ?? '', 0);
        $row['order_meter'] = barcodePlanningFormatNumber($extra['order_meter'] ?? '');
        $rows[] = $row;
    }
    return $rows;
}

function barcodePlanningExportExcel(array $rows, string $companyName): void {
    $fileName = 'barcode-planning-' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo '<table border="1">';
    echo '<tr><th colspan="11" style="background:#0f172a;color:#ffffff;font-size:16px">' . e($companyName) . ' - Barcode Planning</th></tr>';
    echo '<tr><th colspan="11" style="background:#eff6ff;color:#1e3a8a">Exported: ' . e(date('d M Y h:i A')) . '</th></tr>';
    echo '<tr>';
    foreach (['S.N', 'Planning ID', 'Date', 'Dispatch Date', 'Job Name', 'Material Type', 'Size', 'UPS', 'Core Size', 'Order Qty', 'Order Mtr'] as $heading) {
        echo '<th style="background:#dbeafe;color:#1e3a8a">' . e($heading) . '</th>';
    }
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . e((string)($row['sequence_order'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['job_no'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['planning_date'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['dispatch_date'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['job_name'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['material_type'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['size_label'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['ups'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['core_size'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['order_quantity'] ?? '')) . '</td>';
        echo '<td>' . e((string)($row['order_meter'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

function barcodePlanningExportPrint(array $rows, string $companyName, string $companyAddress): void {
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
    table{width:100%;border-collapse:collapse;font-size:12px}
    th,td{border:1px solid #cbd5e1;padding:8px 10px;text-align:left}
    th{background:#eff6ff;color:#1e3a8a;text-transform:uppercase;font-size:11px;letter-spacing:.04em}
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
        <th>S.N</th>
        <th>Planning ID</th>
        <th>Date</th>
        <th>Dispatch Date</th>
        <th>Job Name</th>
        <th>Material Type</th>
        <th>Size</th>
        <th>UPS</th>
        <th>Core Size</th>
        <th>Order Qty</th>
        <th>Order Mtr</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="11" style="text-align:center;color:#64748b">No barcode planning entries found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= e((string)($row['sequence_order'] ?? '')) ?></td>
            <td><?= e((string)($row['job_no'] ?? '')) ?></td>
            <td><?= e((string)($row['planning_date'] ?? '')) ?></td>
            <td><?= e((string)($row['dispatch_date'] ?? '')) ?></td>
            <td><?= e((string)($row['job_name'] ?? '')) ?></td>
            <td><?= e((string)($row['material_type'] ?? '')) ?></td>
            <td><?= e((string)($row['size_label'] ?? '')) ?></td>
            <td><?= e((string)($row['ups'] ?? '')) ?></td>
            <td><?= e((string)($row['core_size'] ?? '')) ?></td>
            <td><?= e((string)($row['order_quantity'] ?? '')) ?></td>
            <td><?= e((string)($row['order_meter'] ?? '')) ?></td>
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
$pageTitle = 'BarCode Planning';
$planningPageKey = 'barcode';
$canAdd = currentPageAction('add');
$canDelete = currentPageAction('delete');
$csrfToken = generateCSRF();
$errors = [];
$today = date('Y-m-d');
$dispatchDefault = date('Y-m-d', strtotime('+12 days'));
$defaultStatus = erp_status_page_default('planning.barcode');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRF($token)) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'add_barcode_planning') {
            if (!$canAdd) {
                $errors[] = 'You do not have permission to add barcode planning.';
            } else {
                $planningDate = trim((string)($_POST['planning_date'] ?? $today));
                $dispatchDate = trim((string)($_POST['dispatch_date'] ?? $dispatchDefault));
                $jobName = trim((string)($_POST['job_name'] ?? ''));
                $materialType = trim((string)($_POST['material_type'] ?? ''));
                $widthMm = barcodePlanningNumber($_POST['width_mm'] ?? '');
                $heightMm = barcodePlanningNumber($_POST['height_mm'] ?? '');
                $ups = barcodePlanningNumber($_POST['ups'] ?? '');
                $coreSize = trim((string)($_POST['core_size'] ?? ''));
                $orderQtyInput = $_POST['order_quantity'] ?? '';
                $orderMeterInput = $_POST['order_meter'] ?? '';
                if ($jobName === '') $errors[] = 'Job Name is required.';
                if ($planningDate === '') $errors[] = 'Date is required.';
                if ($dispatchDate === '') $errors[] = 'Dispatch date is required.';
                if ($widthMm <= 0) $errors[] = 'Width is required.';
                if ($heightMm <= 0) $errors[] = 'Height is required.';
                if ($ups <= 0) $errors[] = 'UPS is required.';
                $calculated = barcodePlanningCalcPair($orderQtyInput, $orderMeterInput, $ups, $heightMm);
                if ($calculated['qty'] <= 0 && $calculated['meter'] <= 0) {
                    $errors[] = 'Enter Quantity or Meter to calculate the planning quantity.';
                }
                if (empty($errors)) {
                    $planJobNo = previewNextId('planning_barcode') ?: 'Auto-generated';
                    $planJobNo = getNextId('planning_barcode') ?: $planJobNo;
                    $sequenceOrder = barcodePlanningNextSerial($db);
                    $payload = [
                        'planning_date' => $planningDate,
                        'dispatch_date' => $dispatchDate,
                        'material_type' => $materialType,
                        'width_mm' => barcodePlanningFormatNumber($widthMm),
                        'height_mm' => barcodePlanningFormatNumber($heightMm),
                        'ups' => barcodePlanningFormatNumber($ups),
                        'core_size' => $coreSize,
                        'order_quantity' => barcodePlanningFormatNumber($calculated['qty'], 0),
                        'order_meter' => barcodePlanningFormatNumber($calculated['meter']),
                        'planning_mode' => 'barcode',
                    ];
                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $notes = 'Barcode planning entry';
                    $createdBy = (int)($_SESSION['user_id'] ?? 0);
                    $stmt = $db->prepare("INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by, sequence_order) VALUES (?, NULL, ?, NULL, NULL, NULLIF(?, ''), ?, 'Normal', ?, 'barcode', ?, ?, ?)");
                    if ($stmt) {
                      $stmt->bind_param('ssssssii', $planJobNo, $jobName, $dispatchDate, $defaultStatus, $notes, $payloadJson, $createdBy, $sequenceOrder);
                        if ($stmt->execute()) {
                            if (function_exists('planningCreateNotifications')) {
                                planningCreateNotifications($db, $planJobNo, $jobName, 'barcode', 'added');
                            }
                            setFlash('success', 'Barcode planning entry added successfully.');
                            redirect(BASE_URL . '/modules/planning/rotery/index.php');
                        }
                        $errors[] = 'Database error: ' . $db->error;
                        $stmt->close();
                    } else {
                        $errors[] = 'Could not prepare database statement.';
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
                        redirect(BASE_URL . '/modules/planning/rotery/index.php');
                    }
                }
                $errors[] = 'Unable to delete the selected entry.';
            }
        }
    }
}

$rows = barcodePlanningFetchRows($db);
$flash = getFlash();
$materialSuggestions = barcodePlanningMaterialSuggestions($db);
$coreSuggestions = barcodePlanningCoreSuggestions($db);
$previewPlanningId = previewNextId('planning_barcode') ?: 'Auto-generated on save';
$previewSerial = barcodePlanningNextSerial($db);
$previewRecordId = barcodePlanningNextRecordId($db);
$totalEntries = count($rows);
$todayEntries = 0;
$pendingEntries = 0;
$meterTotal = 0.0;
foreach ($rows as $row) {
    if (($row['planning_date'] ?? '') === $today) $todayEntries++;
  if (strcasecmp(erp_status_page_normalize((string)($row['status'] ?? ''), 'planning.barcode'), $defaultStatus) === 0) $pendingEntries++;
    $meterTotal += barcodePlanningNumber($row['order_meter'] ?? 0);
}

$appSettings = getAppSettings();
$companyName = trim((string)($appSettings['company_name'] ?? APP_NAME)) ?: APP_NAME;
$companyAddress = trim((string)($appSettings['company_address'] ?? ''));
$exportFormat = strtolower(trim((string)($_GET['export'] ?? '')));
if ($exportFormat === 'excel') {
    barcodePlanningExportExcel($rows, $companyName);
}
if ($exportFormat === 'pdf') {
    barcodePlanningExportPrint($rows, $companyName, $companyAddress);
}

include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Design &amp; Prepress</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Planning</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>BarCode Planning</span>
</div>

<?php include __DIR__ . '/../_page_switcher.php'; ?>

<style>
:root{--bc-brand:#0ea5a4;--bc-brand-soft:#ecfeff;--bc-border:#d9edf0;--bc-text:#0f172a;--bc-muted:#64748b}
.bc-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.bc-header h1{font-size:1.45rem;font-weight:900;display:flex;align-items:center;gap:10px}.bc-header h1 i{font-size:1.55rem;color:var(--bc-brand)}
.bc-header p{margin-top:4px;color:var(--bc-muted);font-size:.82rem;font-weight:600}.bc-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.bc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px}.bc-stat{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.bc-stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}.bc-stat-value{font-size:1.35rem;font-weight:900;color:var(--bc-text);line-height:1}.bc-stat-label{font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-top:4px}
.bc-table-wrap{overflow:auto}.bc-table{width:100%;min-width:1180px;border-collapse:collapse;font-size:.79rem}.bc-table th{padding:10px 12px;text-align:left;border-bottom:2px solid #dbe7ef;background:#f8fbff;font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#5b6b82;white-space:nowrap}.bc-table td{padding:10px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle}.bc-table tbody tr:hover td{background:#fbfeff}
.bc-size-chip{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:800;font-size:.7rem}.bc-num{font-family:Consolas,monospace;font-weight:700;color:#0f172a}.bc-muted{color:#64748b}.bc-empty{padding:42px 18px;text-align:center;color:#94a3b8}.bc-empty i{display:block;font-size:2.4rem;opacity:.3;margin-bottom:8px}
.bc-toolbar-card{margin-bottom:16px;background:#f8fbff;border:1px solid var(--bc-border);border-radius:14px;padding:12px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.bc-toolbar-title{font-size:.9rem;font-weight:800;color:#0f172a}.bc-toolbar-sub{font-size:.76rem;color:#64748b;font-weight:600;margin-top:2px}.bc-delete-form{display:inline}.planning-modal{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:1200;align-items:center;justify-content:center;padding:20px}.planning-modal-card{width:min(920px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:12px;box-shadow:0 22px 54px rgba(0,0,0,.22);border:1px solid #e5e7eb}.planning-modal-head,.planning-modal-foot{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #eef2f7}.planning-modal-foot{border-bottom:none;border-top:1px solid #eef2f7}.planning-modal-head h3{font-size:1.05rem}.planning-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px 16px}.planning-grid label{display:block;margin-bottom:5px;font-size:.75rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.planning-job-preview-box{margin:14px 16px 0;padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.planning-job-preview-box strong{display:block;font-size:1rem;color:#0f172a}.planning-job-preview-label{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8;display:block;margin-bottom:5px}.planning-job-preview-box small{color:#475569;display:block;font-size:.74rem}.bc-calc-note{grid-column:1/-1;padding:10px 12px;border:1px dashed #bae6fd;background:#f0f9ff;border-radius:10px;color:#0f766e;font-size:.76rem;font-weight:600}.bc-field-inline{position:relative}.bc-form-hint{font-size:.72rem;color:#94a3b8;margin-top:5px}.bc-status-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.bc-toolbar-count{font-size:.82rem;color:#475569;font-weight:700}
@media(max-width:920px){.planning-grid,.planning-job-preview-box{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.bc-header,.bc-toolbar-card{align-items:stretch}.bc-actions{width:100%}.bc-actions .btn,.bc-actions a{flex:1 1 auto;justify-content:center}.planning-grid,.planning-job-preview-box{grid-template-columns:1fr}.bc-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.bc-stats{grid-template-columns:1fr}.bc-table{min-width:980px}}
</style>

<div class="bc-header">
  <div>
    <h1><i class="bi bi-upc-scan"></i> BarCode Planning</h1>
    <p>Manage barcode planning entries with auto planning ID, dispatch scheduling, and quantity-to-meter conversion.</p>
  </div>
  <div class="bc-actions no-print">
    <a class="btn btn-ghost" href="<?= e(BASE_URL . '/modules/planning/rotery/index.php?export=pdf') ?>" target="_blank"><i class="bi bi-printer"></i> Print / PDF</a>
    <a class="btn btn-ghost" href="<?= e(BASE_URL . '/modules/planning/rotery/index.php?export=excel') ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
    <?php if ($canAdd): ?><button type="button" class="btn btn-primary" id="btn-open-barcode-modal"><i class="bi bi-plus-circle"></i> Add Barcode Planning</button><?php endif; ?>
  </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type'] ?? 'info') ?>" style="margin-bottom:14px"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger" style="margin-bottom:14px"><strong>Could not save barcode planning.</strong><ul style="margin:8px 0 0 18px"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="bc-stats">
  <div class="bc-stat"><div class="bc-stat-icon" style="background:#ecfeff;color:#0f766e"><i class="bi bi-list-ol"></i></div><div><div class="bc-stat-value"><?= $totalEntries ?></div><div class="bc-stat-label">Total Plans</div></div></div>
  <div class="bc-stat"><div class="bc-stat-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-calendar2-check"></i></div><div><div class="bc-stat-value"><?= $todayEntries ?></div><div class="bc-stat-label">Today Added</div></div></div>
  <div class="bc-stat"><div class="bc-stat-icon" style="background:#fef3c7;color:#d97706"><i class="bi bi-hourglass-split"></i></div><div><div class="bc-stat-value"><?= $pendingEntries ?></div><div class="bc-stat-label">Pending</div></div></div>
  <div class="bc-stat"><div class="bc-stat-icon" style="background:#f5f3ff;color:#7c3aed"><i class="bi bi-rulers"></i></div><div><div class="bc-stat-value"><?= e(barcodePlanningFormatNumber($meterTotal)) ?></div><div class="bc-stat-label">Planned Meter</div></div></div>
</div>

<div class="bc-toolbar-card no-print">
  <div><div class="bc-toolbar-title">Barcode Planning Register</div><div class="bc-toolbar-sub">Auto serial, planning ID, dispatch date, and order calculation are tracked from one modal flow.</div></div>
  <div class="bc-toolbar-count"><?= $totalEntries ?> entries</div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="bi bi-table"></i> Barcode Planning List</span></div>
  <div class="bc-table-wrap">
    <table class="bc-table">
      <thead><tr><th>S.N</th><th>Planning ID</th><th>Date</th><th>Dispatch Date</th><th>Job Name</th><th>Material Type</th><th>Size</th><th>UPS</th><th>Core Size</th><th>Order Qty</th><th>Order Mtr</th><th>Status</th><th class="no-print">Actions</th></tr></thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="13" class="bc-empty"><i class="bi bi-inbox"></i>No barcode planning entries found yet.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="bc-num"><?= e((string)($row['sequence_order'] ?? '')) ?></td>
              <td><div style="font-weight:800;color:#0f172a"><?= e((string)($row['job_no'] ?? '—')) ?></div><div class="bc-muted" style="font-size:.7rem">Record ID: <?= (int)($row['id'] ?? 0) ?></div></td>
              <td><?= e((string)($row['planning_date'] ?: '—')) ?></td>
              <td><?= e((string)($row['dispatch_date'] ?: '—')) ?></td>
              <td><div style="font-weight:800;color:#0f172a"><?= e((string)($row['job_name'] ?? '—')) ?></div><div class="bc-muted" style="font-size:.7rem">Created <?= e(date('d M Y', strtotime((string)($row['created_at'] ?? 'now')))) ?></div></td>
              <td><?= e((string)($row['material_type'] ?: '—')) ?></td>
              <td><span class="bc-size-chip"><?= e((string)($row['size_label'] ?: '—')) ?></span></td>
              <td class="bc-num"><?= e((string)($row['ups'] ?: '—')) ?></td>
              <td><?= e((string)($row['core_size'] ?: '—')) ?></td>
              <td class="bc-num"><?= e((string)($row['order_quantity'] ?: '—')) ?></td>
              <td class="bc-num"><?= e((string)($row['order_meter'] ?: '—')) ?></td>
              <td><span class="bc-status-badge"><?= e(erp_status_page_normalize((string)($row['status'] ?? $defaultStatus), 'planning.barcode')) ?></span></td>
              <td class="no-print">
                <?php if ($canDelete): ?>
                  <form method="post" class="bc-delete-form" data-confirm="Delete this barcode planning entry?"><input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="delete_barcode_planning"><input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>"><button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button></form>
                <?php else: ?>
                  <span class="bc-muted">Read only</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canAdd): ?>
<div class="planning-modal" id="modal-barcode-planning" style="display:none">
  <div class="planning-modal-card">
    <div class="planning-modal-head"><h3>Add Barcode Planning</h3><button type="button" class="btn btn-ghost btn-sm" id="btn-close-barcode-modal"><i class="bi bi-x-lg"></i></button></div>
    <form method="post" id="form-barcode-planning">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="action" value="add_barcode_planning">
      <div class="planning-job-preview-box">
        <div><span class="planning-job-preview-label">Serial No</span><strong><?= $previewSerial ?></strong><small>Auto from barcode planning queue.</small></div>
        <div><span class="planning-job-preview-label">Planning ID</span><strong><?= e($previewPlanningId) ?></strong><small>Final ID is assigned on save.</small></div>
        <div><span class="planning-job-preview-label">Record ID</span><strong>#<?= $previewRecordId ?></strong><small>Database record number preview.</small></div>
      </div>
      <div class="planning-grid">
        <div><label for="barcode-planning-date">Date</label><input type="date" class="form-control" id="barcode-planning-date" name="planning_date" value="<?= e($today) ?>" required></div>
        <div><label for="barcode-dispatch-date">Dispatch Date</label><input type="date" class="form-control" id="barcode-dispatch-date" name="dispatch_date" value="<?= e($dispatchDefault) ?>" required><div class="bc-form-hint">Default is 12 days after planning date and remains editable.</div></div>
        <div><label>Status</label><input type="text" class="form-control" value="<?= e($defaultStatus) ?>" readonly></div>
        <div style="grid-column:1 / -1"><label for="barcode-job-name">Job Name</label><input type="text" class="form-control" id="barcode-job-name" name="job_name" placeholder="Enter barcode job name" required></div>
        <div><label for="barcode-material-type">Material Type</label><input type="text" class="form-control" id="barcode-material-type" name="material_type" list="barcode-material-options" placeholder="e.g. Chromo / PP / PET"></div>
        <div><label for="barcode-width-mm">Width (mm)</label><input type="number" class="form-control" id="barcode-width-mm" name="width_mm" min="0" step="0.01" placeholder="Width" required></div>
        <div><label for="barcode-height-mm">Height (mm)</label><input type="number" class="form-control" id="barcode-height-mm" name="height_mm" min="0" step="0.01" placeholder="Height" required></div>
        <div><label for="barcode-ups">UPS</label><input type="number" class="form-control" id="barcode-ups" name="ups" min="0" step="0.01" placeholder="UPS" required></div>
        <div><label for="barcode-core-size">Core Size</label><input type="text" class="form-control" id="barcode-core-size" name="core_size" list="barcode-core-options" placeholder="e.g. 1 inch, 3 inch"></div>
        <div class="bc-field-inline"><label for="barcode-order-qty">Order Quantity</label><input type="number" class="form-control" id="barcode-order-qty" name="order_quantity" min="0" step="1" placeholder="Enter quantity"></div>
        <div class="bc-field-inline"><label for="barcode-order-meter">Order Meter</label><input type="number" class="form-control" id="barcode-order-meter" name="order_meter" min="0" step="0.01" placeholder="Enter meter"></div>
        <div class="bc-calc-note">Quantity দিলে meter auto calculate হবে, আর meter দিলে quantity auto calculate হবে। Formula: Meter = (Quantity / UPS) x (Height / 1000).</div>
      </div>
      <div class="planning-modal-foot"><button type="button" class="btn btn-ghost" id="btn-cancel-barcode-modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Save Barcode Planning</button></div>
    </form>
  </div>
</div>

<datalist id="barcode-material-options"><?php foreach ($materialSuggestions as $materialOption): ?><option value="<?= e($materialOption) ?>"></option><?php endforeach; ?></datalist>
<datalist id="barcode-core-options"><?php foreach ($coreSuggestions as $coreOption): ?><option value="<?= e($coreOption) ?>"></option><?php endforeach; ?></datalist>
<?php endif; ?>

<script>
(function(){
  var modal = document.getElementById('modal-barcode-planning');
  var openBtn = document.getElementById('btn-open-barcode-modal');
  var closeBtn = document.getElementById('btn-close-barcode-modal');
  var cancelBtn = document.getElementById('btn-cancel-barcode-modal');
  var planningDateInput = document.getElementById('barcode-planning-date');
  var dispatchDateInput = document.getElementById('barcode-dispatch-date');
  var qtyInput = document.getElementById('barcode-order-qty');
  var meterInput = document.getElementById('barcode-order-meter');
  var upsInput = document.getElementById('barcode-ups');
  var heightInput = document.getElementById('barcode-height-mm');
  var form = document.getElementById('form-barcode-planning');
  var hasErrors = <?= !empty($errors) ? 'true' : 'false' ?>;
  var syncing = false;
  function openModal(){ if (!modal) return; modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
  function closeModal(){ if (!modal) return; modal.style.display = 'none'; document.body.style.overflow = ''; }
  function pad(value){ return String(value).padStart(2, '0'); }
  function addDays(dateText, days){ if (!dateText) return ''; var parts = dateText.split('-'); if (parts.length !== 3) return ''; var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])); if (Number.isNaN(date.getTime())) return ''; date.setDate(date.getDate() + days); return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()); }
  function parseNum(value){ var parsed = parseFloat(String(value || '').replace(/,/g, '')); return Number.isFinite(parsed) ? parsed : 0; }
  function formatNum(value, decimals){ if (!Number.isFinite(value) || value <= 0) return ''; return String(Number(value.toFixed(decimals))); }
  function calcMeterFromQty(){ var qty = parseNum(qtyInput && qtyInput.value); var ups = parseNum(upsInput && upsInput.value); var height = parseNum(heightInput && heightInput.value); if (qty <= 0 || ups <= 0 || height <= 0) return ''; return formatNum((qty / ups) * (height / 1000), 2); }
  function calcQtyFromMeter(){ var meter = parseNum(meterInput && meterInput.value); var ups = parseNum(upsInput && upsInput.value); var height = parseNum(heightInput && heightInput.value); if (meter <= 0 || ups <= 0 || height <= 0) return ''; return formatNum((meter / (height / 1000)) * ups, 0); }
  function syncFromQty(){ if (syncing || !meterInput) return; syncing = true; meterInput.value = calcMeterFromQty(); syncing = false; }
  function syncFromMeter(){ if (syncing || !qtyInput) return; syncing = true; qtyInput.value = calcQtyFromMeter(); syncing = false; }
  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modal) modal.addEventListener('click', function(event){ if (event.target === modal) closeModal(); });
  if (planningDateInput && dispatchDateInput) planningDateInput.addEventListener('change', function(){ dispatchDateInput.value = addDays(planningDateInput.value, 12); });
  if (qtyInput) qtyInput.addEventListener('input', syncFromQty);
  if (meterInput) meterInput.addEventListener('input', syncFromMeter);
  if (upsInput) upsInput.addEventListener('input', function(){ if (parseNum(qtyInput && qtyInput.value) > 0) syncFromQty(); else if (parseNum(meterInput && meterInput.value) > 0) syncFromMeter(); });
  if (heightInput) heightInput.addEventListener('input', function(){ if (parseNum(qtyInput && qtyInput.value) > 0) syncFromQty(); else if (parseNum(meterInput && meterInput.value) > 0) syncFromMeter(); });
  if (form) form.addEventListener('submit', function(){ if (parseNum(qtyInput && qtyInput.value) > 0 && (!meterInput || parseNum(meterInput.value) <= 0)) syncFromQty(); else if (parseNum(meterInput && meterInput.value) > 0 && (!qtyInput || parseNum(qtyInput.value) <= 0)) syncFromMeter(); });
  if (hasErrors) openModal();
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
