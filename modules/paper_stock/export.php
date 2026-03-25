<?php
// ============================================================
// Shree Label ERP — Paper Stock: Bulk Export (CSV / PDF)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db     = getDB();
$format = $_GET['format'] ?? 'csv';
$mode   = $_GET['mode']   ?? 'all';

// Build WHERE from same filters as index.php
$fSearch   = trim($_GET['search']    ?? '');
$fRollNo   = trim($_GET['roll_no']   ?? '');
$fLotNo    = trim($_GET['lot_no']    ?? '');
$fStatus   = trim($_GET['status']    ?? '');
$fCompany  = trim($_GET['company']   ?? '');
$fType     = trim($_GET['type']      ?? '');
$fGsm      = trim($_GET['gsm']       ?? '');
$fDateFrom = trim($_GET['date_from'] ?? '');
$fDateTo   = trim($_GET['date_to']   ?? '');

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

if (empty($rolls)) {
    setFlash('error', 'No records found to export.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

$columns = [
    'roll_no'         => 'Roll No',
    'status'          => 'Status',
    'company'         => 'Company',
    'paper_type'      => 'Paper Type',
    'width_mm'        => 'Width (mm)',
    'length_mtr'      => 'Length (mtr)',
    'gsm'             => 'GSM',
    'weight_kg'       => 'Weight (kg)',
    'purchase_rate'   => 'Purchase Rate',
    'lot_batch_no'    => 'Lot / Batch No',
    'company_roll_no' => 'Company Roll No',
    'job_no'          => 'Job No',
    'date_received'   => 'Date Received',
    'date_used'       => 'Date Used',
    'remarks'         => 'Remarks',
];

// ── CSV / Excel Export ────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'Paper_Stock_Export_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, array_values($columns));

    foreach ($rolls as $r) {
        $row = [];
        foreach (array_keys($columns) as $key) {
            $row[] = $r[$key] ?? '';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ── PDF Export (HTML for print) ───────────────────────────────
if ($format === 'pdf') {
    $totalMtr = 0;
    $totalSqm = 0;
    foreach ($rolls as $r) {
        $totalMtr += (float)($r['length_mtr'] ?? 0);
        $totalSqm += ((float)($r['width_mm'] ?? 0) / 1000) * (float)($r['length_mtr'] ?? 0);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Paper Stock Report — <?= date('d M Y') ?></title>
<style>
@page { size: landscape; margin: 12mm; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10px; color: #1e293b; }

.report-header {
    display: flex; justify-content: space-between; align-items: flex-end;
    border-bottom: 3px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px;
}
.report-title { font-size: 18px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: .05em; }
.report-subtitle { font-size: 10px; color: #64748b; margin-top: 2px; }
.report-meta { text-align: right; font-size: 10px; color: #64748b; }
.report-meta strong { color: #0f172a; }

.summary-bar {
    display: flex; gap: 24px; background: #f8fafc; border: 1px solid #e2e8f0;
    border-radius: 8px; padding: 8px 16px; margin-bottom: 12px; font-size: 10px; font-weight: 700;
}
.summary-bar span { color: #64748b; }
.summary-bar strong { color: #0f172a; margin-left: 4px; }

table { width: 100%; border-collapse: collapse; font-size: 9px; }
th { background: #0f172a; color: #fff; padding: 6px 8px; text-align: left; font-size: 8px;
     text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
tr:nth-child(even) { background: #f8fafc; }
.num { text-align: right; font-family: monospace; }
.footer-row { font-size: 9px; color: #64748b; text-align: center; padding-top: 12px; border-top: 2px solid #e2e8f0; margin-top: 8px; }

@media print {
    body { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .no-print { display: none !important; }
}
</style>
</head>
<body>
<div class="no-print" style="padding:12px;background:#fefce8;border-bottom:2px solid #fde68a;text-align:center;font-size:13px;font-weight:700;color:#92400e">
    Press <kbd>Ctrl+P</kbd> to print or save as PDF.
    <button onclick="window.print()" style="margin-left:12px;padding:6px 18px;border:none;background:#0f172a;color:#fff;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px">
        <i class="bi bi-printer"></i> Print / Save PDF
    </button>
    <button onclick="window.close()" style="margin-left:8px;padding:6px 18px;border:1px solid #cbd5e1;background:#fff;color:#64748b;border-radius:8px;font-weight:700;cursor:pointer;font-size:12px">Close</button>
</div>

<div style="padding:16px">
<div class="report-header">
    <div>
        <div class="report-title">Paper Stock Report</div>
        <div class="report-subtitle">Shree Label ERP — Inventory Export</div>
    </div>
    <div class="report-meta">
        <div>Generated: <strong><?= date('d M Y, h:i A') ?></strong></div>
        <div>Records: <strong><?= count($rolls) ?></strong></div>
    </div>
</div>

<div class="summary-bar">
    <span>Total Rolls: <strong><?= count($rolls) ?></strong></span>
    <span>Total MTR: <strong><?= number_format($totalMtr, 0) ?></strong></span>
    <span>Total SQM: <strong><?= number_format($totalSqm, 2) ?></strong></span>
</div>

<table>
<thead>
<tr>
    <th>#</th>
    <?php foreach ($columns as $label): ?>
    <th><?= $label ?></th>
    <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($rolls as $i => $r): ?>
<tr>
    <td><?= $i + 1 ?></td>
    <?php foreach (array_keys($columns) as $key): ?>
    <td class="<?= in_array($key, ['width_mm','length_mtr','gsm','weight_kg','purchase_rate']) ? 'num' : '' ?>"><?php
        $val = $r[$key] ?? '';
        if ($key === 'purchase_rate' && $val !== '') echo '₹' . number_format((float)$val, 2);
        elseif ($key === 'length_mtr' && $val !== '') echo number_format((float)$val, 0);
        elseif ($key === 'date_received' || $key === 'date_used') echo $val ? date('d M Y', strtotime($val)) : '-';
        else echo e($val !== '' ? $val : '-');
    ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="footer-row">
    Paper Stock Report — Shree Label ERP — Generated <?= date('d M Y, h:i A') ?> — <?= count($rolls) ?> records
</div>
</div>

<script>window.onafterprint = function() {};</script>
</body>
</html>
<?php
    exit;
}

setFlash('error', 'Invalid export format.');
redirect(BASE_URL . '/modules/paper_stock/index.php');
