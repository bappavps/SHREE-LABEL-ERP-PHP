<?php
// ============================================================
// ERP System — Paper Stock: Print Label
// Template-based label printing with live preview
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? APP_NAME;
$tenantLogoPath = (string)($appSettings['logo_path'] ?? '');
$erpLogoPath = (string)($appSettings['erp_logo_path'] ?? '');
$tenantLogoUrl = $tenantLogoPath !== '' ? appUrl($tenantLogoPath) : appUrl('assets/img/logo.svg');
$erpLogoUrl = $erpLogoPath !== '' ? appUrl($erpLogoPath) : $tenantLogoUrl;
$companyLogoUrl = $tenantLogoUrl;
$themeColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');

if (!function_exists('resolveLabelBackUrl')) {
    function resolveLabelBackUrl(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') return '';

        $base = rtrim((string)BASE_URL, '/');
        $basePrefix = $base . '/';

        if (preg_match('#^https?://#i', $raw)) {
            $parts = parse_url($raw);
            $host = strtolower((string)($parts['host'] ?? ''));
            $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($host === '' || $serverHost === '' || $host !== $serverHost) return '';

            $path = (string)($parts['path'] ?? '');
            if ($path === '' || strpos($path, $basePrefix) !== 0) return '';
            $qs = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
            return $path . $qs;
        }

        if (strpos($raw, $basePrefix) === 0) {
            return $raw;
        }

        return '';
    }
}

$cancelBackUrl = resolveLabelBackUrl((string)($_GET['back_url'] ?? ''));
$printType = strtolower(trim((string)($_GET['print_type'] ?? '')));
$sizeParam = strtolower(trim((string)($_GET['size'] ?? '')));
if ($printType === '' && in_array($sizeParam, ['40x25', '40×25', '40*25'], true)) {
    $printType = 'sticker';
}
$bundlePcsParam = trim((string)($_GET['bundle_pcs'] ?? ''));
$bundlePcs = is_numeric($bundlePcsParam) ? (string)(int)$bundlePcsParam : '';
$itemWidthParam = trim((string)($_GET['item_width'] ?? ''));
$itemLengthParam = trim((string)($_GET['item_length'] ?? ''));
$batchNoParam = trim((string)($_GET['batch_no'] ?? ''));
$batchLabelsParam = trim((string)($_GET['batch_labels'] ?? ''));
$jobNameParam = trim((string)($_GET['job_name'] ?? ''));
$rollsPerCartonParam = trim((string)($_GET['rolls_per_carton'] ?? ''));
$rollsPerCarton = is_numeric($rollsPerCartonParam) ? (string)(int)$rollsPerCartonParam : '';
$pcsPerRollParam = trim((string)($_GET['pcs_per_roll'] ?? ''));
$pcsPerRoll = is_numeric($pcsPerRollParam) ? (string)(int)$pcsPerRollParam : '';
$mixedEnabledParam = (int)($_GET['mixed_enabled'] ?? 0);
$mixedCartonsParam = trim((string)($_GET['mixed_cartons'] ?? ''));
$mixedExtraRollsParam = trim((string)($_GET['mixed_extra_rolls'] ?? ''));

// ── Auto-create print_templates table if missing ──────────────
@$db->query("CREATE TABLE IF NOT EXISTS print_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  document_type VARCHAR(50) NOT NULL DEFAULT 'Industrial Label',
  paper_width DECIMAL(8,2) NOT NULL DEFAULT 210,
  paper_height DECIMAL(8,2) NOT NULL DEFAULT 297,
  elements LONGTEXT DEFAULT NULL,
  background LONGTEXT DEFAULT NULL,
  thumbnail LONGTEXT DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add is_system column if missing
try { $db->query("ALTER TABLE print_templates ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default"); } catch (Exception $e) {}

erp_ensure_print_studio_system_templates($db);

// ── Get roll data ─────────────────────────────────────────────
$idsParam = trim($_GET['ids'] ?? '');
if ($idsParam === '') {
    setFlash('error', 'No rolls selected for label printing.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}
$ids = array_filter(array_map('intval', explode(',', $idsParam)), function($id) { return $id > 0; });
if (empty($ids)) {
    setFlash('error', 'Invalid roll IDs.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT * FROM paper_stock WHERE id IN ($placeholders) ORDER BY id DESC");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$jobNoToId = [];
$rollNoToJobNo = [];
$jobNos = [];
foreach ($rolls as $rJob) {
    $jn = trim((string)($rJob['job_no'] ?? ''));
    if ($jn !== '') {
        $jobNos[] = $jn;
    }
}
$jobNos = array_values(array_unique($jobNos));
if (!empty($jobNos)) {
    $jobPlaceholders = implode(',', array_fill(0, count($jobNos), '?'));
    $sqlJobMap = "SELECT id, job_no FROM jobs WHERE job_no IN ($jobPlaceholders) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    $stmtJobMap = $db->prepare($sqlJobMap);
    if ($stmtJobMap) {
        $stmtJobMap->bind_param(str_repeat('s', count($jobNos)), ...$jobNos);
        $stmtJobMap->execute();
        $resJobMap = $stmtJobMap->get_result();
        while ($rowJob = $resJobMap->fetch_assoc()) {
            $key = strtoupper(trim((string)($rowJob['job_no'] ?? '')));
            if ($key !== '') {
                $jobNoToId[$key] = (int)($rowJob['id'] ?? 0);
            }
        }
        $stmtJobMap->close();
    }
}

$rollNos = [];
foreach ($rolls as $rRoll) {
    $rn = trim((string)($rRoll['roll_no'] ?? ''));
    if ($rn !== '') {
        $rollNos[] = $rn;
    }
}
$rollNos = array_values(array_unique($rollNos));
if (!empty($rollNos)) {
    $rollPlaceholders = implode(',', array_fill(0, count($rollNos), '?'));
    $sqlRollMap = "
        SELECT j.roll_no, j.job_no, j.id
        FROM jobs j
        INNER JOIN (
            SELECT roll_no, MAX(id) AS max_id
            FROM jobs
            WHERE roll_no IN ($rollPlaceholders)
              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
              AND TRIM(COALESCE(job_no, '')) <> ''
            GROUP BY roll_no
        ) latest ON latest.max_id = j.id
    ";
    $stmtRollMap = $db->prepare($sqlRollMap);
    if ($stmtRollMap) {
        $stmtRollMap->bind_param(str_repeat('s', count($rollNos)), ...$rollNos);
        $stmtRollMap->execute();
        $resRollMap = $stmtRollMap->get_result();
        while ($rowRoll = $resRollMap->fetch_assoc()) {
            $rollKey = strtoupper(trim((string)($rowRoll['roll_no'] ?? '')));
            $jn = trim((string)($rowRoll['job_no'] ?? ''));
            if ($rollKey !== '' && $jn !== '') {
                $rollNoToJobNo[$rollKey] = $jn;
                $jnKey = strtoupper($jn);
                if (!isset($jobNoToId[$jnKey])) {
                    $jobNoToId[$jnKey] = (int)($rowRoll['id'] ?? 0);
                }
            }
        }
        $stmtRollMap->close();
    }
}

foreach ($rolls as &$r) {
    $r['sqm'] = round(((float)($r['width_mm'] ?? 0) / 1000) * (float)($r['length_mtr'] ?? 0), 2);
}
unset($r);

if (empty($rolls)) {
    setFlash('error', 'No rolls found.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

// ── Load label templates ──────────────────────────────────────
$tplRes = $db->query("SELECT * FROM print_templates WHERE document_type IN ('Industrial Label', 'POS Roll Sticker', 'Packing Label') ORDER BY is_default DESC, is_system DESC, name ASC");
$templates = [];
if ($tplRes) { while ($t = $tplRes->fetch_assoc()) $templates[] = $t; }

// JS-safe roll data (includes Firebase Print Studio compatible aliases)
$companyAddr = $appSettings['company_address'] ?? '';
$companyAddrSafe = trim((string)$companyAddr) !== '' ? (string)$companyAddr : '-';
$printNow = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$printDateSlash = $printNow->format('n/j/Y');
$printDateFormatted = $printNow->format('d M Y');
$printDateDdMmYyyy = $printNow->format('d/m/Y');
$printDateYmd = $printNow->format('Y-m-d');
$jsRolls = array_map(function($r) use ($companyName, $companyAddrSafe, $printDateSlash, $printDateFormatted, $printDateDdMmYyyy, $printDateYmd, $bundlePcs, $itemWidthParam, $itemLengthParam, $batchNoParam, $batchLabelsParam, $jobNameParam, $rollsPerCarton, $pcsPerRoll, $jobNoToId, $rollNoToJobNo, $printType, $erpLogoUrl, $tenantLogoUrl) {
    $dateFormatted  = ($r['date_received'] ?? '') ? date('d M Y', strtotime($r['date_received'])) : '';
    $dateSlash      = ($r['date_received'] ?? '') ? date('n/j/Y', strtotime($r['date_received'])) : '';
    $dateDdMmYyyy   = ($r['date_received'] ?? '') ? date('d/m/Y', strtotime($r['date_received'])) : '';
    $dateYmd        = ($r['date_received'] ?? '') ? date('Y-m-d', strtotime($r['date_received'])) : '';
    $lengthVal      = number_format((float)($r['length_mtr'] ?? 0), 0);
    $sqmVal         = number_format((float)($r['sqm'] ?? 0), 2);
    $weightVal      = ($r['weight_kg'] !== null && $r['weight_kg'] !== '') ? (string)$r['weight_kg'] : '0';
    $widthVal       = (string)(int)(float)($r['width_mm'] ?? 0);
    $gsmVal         = (string)(int)(float)($r['gsm'] ?? 0);
    $rollNo         = $r['roll_no'] ?? '';
    $paperType      = $r['paper_type'] ?? '';
    $paperCompany   = $r['company'] ?? '';
    $companyRollNo  = $r['company_roll_no'] ?? '';
    $jobNoRaw       = trim((string)($r['job_no'] ?? ''));
    $rollNoForMap   = strtoupper(trim((string)($r['roll_no'] ?? '')));
    $jobNo          = $jobNoRaw !== '' ? $jobNoRaw : (string)($rollNoToJobNo[$rollNoForMap] ?? '');
    $jobNoKey       = strtoupper(trim((string)$jobNo));
    $mappedJobId    = (int)($jobNoToId[$jobNoKey] ?? 0);
    $jobToken       = '';
    if ($mappedJobId > 0) {
        $jobToken = 'J' . strtoupper(base_convert((string)$mappedJobId, 10, 36));
    } elseif ($jobNo !== '') {
        $jobToken = 'JOB:' . $jobNo;
    }
    $rollToken = 'R' . strtoupper(base_convert((string)((int)$r['id']), 10, 36));
    $lotBatch       = $r['lot_batch_no'] ?? '';
    $companySource  = $companyRollNo !== '' ? $companyRollNo : $paperCompany;
    $companyCodeRaw = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$companySource));
    $companyCode    = $companyCodeRaw !== '' ? substr($companyCodeRaw, 0, 2) : 'NA';
    $batchBaseRaw   = trim((string)$batchNoParam) !== '' ? trim((string)$batchNoParam) : $rollNo;
    $posBatchNo     = $batchBaseRaw !== '' ? ($batchBaseRaw . ' / ' . $companyCode) : ('NA / ' . $companyCode);
    $batchLabelsVal = trim((string)$batchLabelsParam);
    $itemWidthVal   = trim((string)$itemWidthParam) !== '' ? trim((string)$itemWidthParam) : $widthVal;
    $itemLengthVal  = trim((string)$itemLengthParam) !== '' ? trim((string)$itemLengthParam) : '';
    $templateWidthVal = in_array($printType, ['sticker', 'label'], true) ? $itemWidthVal : $widthVal;
    $bundlePcsVal   = trim((string)$bundlePcs) !== '' ? trim((string)$bundlePcs) : '0';
    $rollsPerCartonVal = trim((string)$rollsPerCarton) !== '' ? trim((string)$rollsPerCarton) : '0';
    $pcsPerRollFallback = '';
    $pcsRollKeys = ['barcode_in_1_roll', 'barcode_per_roll', 'barcode_qty_per_roll', 'pcs_per_roll', 'pieces_per_roll', 'qty_per_roll', 'quantity_per_roll', 'planning_pcs_per_roll'];
    foreach ($pcsRollKeys as $pcsKey) {
        if (isset($r[$pcsKey]) && is_numeric((string)$r[$pcsKey]) && (int)$r[$pcsKey] > 0) {
            $pcsPerRollFallback = (string)(int)$r[$pcsKey];
            break;
        }
    }
    $pcsPerRollVal = trim((string)$pcsPerRoll) !== '' && is_numeric((string)$pcsPerRoll) && (int)$pcsPerRoll > 0
        ? (string)(int)$pcsPerRoll
        : $pcsPerRollFallback;
    $totalPcsPerCartonVal = (is_numeric($pcsPerRollVal) && is_numeric($rollsPerCartonVal) && (int)$rollsPerCartonVal > 0)
        ? (string)((int)$pcsPerRollVal * (int)$rollsPerCartonVal)
        : '';
    $resolvedJobName = trim((string)$jobNameParam) !== '' ? trim((string)$jobNameParam) : (string)($r['job_name'] ?? '');
    $productTitle = 'SLC - ' . ($resolvedJobName !== '' ? $resolvedJobName : '-');
    return [
        // ── Original PHP keys ──
        'id'              => (int)$r['id'],
        'roll_no'         => $rollNo,
        'status'          => $r['status'] ?? '',
        'company'         => $paperCompany,
        'paper_type'      => $paperType,
        'width_mm'        => $widthVal,
        'length_mtr'      => $lengthVal,
        'sqm'             => $sqmVal,
        'gsm'             => $gsmVal,
        'weight_kg'       => $weightVal,
        'purchase_rate'   => $r['purchase_rate'] ? '₹' . number_format((float)$r['purchase_rate'], 2) : '',
        'date_received'   => $dateFormatted,
        'date_used'       => ($r['date_used'] ?? '') ? date('d M Y', strtotime($r['date_used'])) : '',
        'job_no'          => $jobNo,
        'job_size'        => $r['job_size'] ?? '',
        'job_name'        => $resolvedJobName,
        'lot_batch_no'    => $lotBatch,
        'company_roll_no' => $companyRollNo,
        'company_code'    => $companyCode,
        'remarks'         => $r['remarks'] ?? '',
        'company_name'    => $companyName,
        'company_address' => $companyAddrSafe,
        'erp_logo_url'    => $erpLogoUrl,
        'tenant_logo_url' => $tenantLogoUrl,
        'erp_logo'        => $erpLogoUrl,
        'tenant_logo'     => $tenantLogoUrl,
        'paper_roll_title'=> 'PAPER ROLL',
        'item_width'      => $itemWidthVal,
        'item_length'     => $itemLengthVal,
        'bundle_pcs'      => $bundlePcsVal,
        'rolls_per_shrink_wrap' => $bundlePcsVal,
        'rolls_per_carton' => $rollsPerCartonVal,
        'pcs_per_roll'    => $pcsPerRollVal,
        'total_pcs_per_carton' => $totalPcsPerCartonVal,
        'batch_no'        => $posBatchNo,
        'batch_labels'    => $batchLabelsVal,
        'batch_display'   => $batchLabelsVal !== '' ? ($batchLabelsVal . ' / ' . $companyCode) : $posBatchNo,
        'pos_batch_no'    => $posBatchNo,
        'product_title'   => $productTitle,
        'size_mm'         => $itemWidthVal,
        'total_quantity_pcs' => $rollsPerCartonVal,
        'manufacturer_label' => 'Manufacturere by :',
        'made_in_country' => 'MADE IN INDIA',
        'job_token'       => $jobToken,
        'roll_token'      => $rollToken,
        'scan_job_url'    => $jobNo !== '' ? (BASE_URL . '/modules/scan/job.php?jn=' . rawurlencode($jobNo)) : '',
        // Prefer job-target payload so sticker and label barcodes resolve like job QR flow.
        'barcode_value'   => $jobToken !== '' ? $jobToken : ($jobNo !== '' ? ('JOB:' . $jobNo) : $rollToken),
        'scan_barcode_url'=> BASE_URL . '/modules/scan/index.php?qr=' . rawurlencode($jobToken !== '' ? $jobToken : ($jobNo !== '' ? ('JOB:' . $jobNo) : $rollToken)),
        // ── Firebase Print Studio aliases ──
        'paper_company'       => $paperCompany,
        'width'               => $templateWidthVal,
        'length'              => $lengthVal,
        'weight'              => $weightVal,
        'roll_url'            => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$r['id'],
        'view_url'            => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$r['id'],
        'job_card_url'        => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$r['id'],
        'job.companyName'     => $companyName,
        'job.companyAddress'  => $companyAddrSafe,
        'job.erpLogo'         => $erpLogoUrl,
        'job.tenantLogo'      => $tenantLogoUrl,
        'job.erpLogoUrl'      => $erpLogoUrl,
        'job.tenantLogoUrl'   => $tenantLogoUrl,
        // Label Printing aliases
        'label_job_name'      => $resolvedJobName,
        'label_size'          => ($itemWidthVal !== '' && $itemLengthVal !== '') ? ($itemWidthVal . ' × ' . $itemLengthVal . ' mm') : (($itemWidthVal !== '') ? ($itemWidthVal . ' mm') : ''),
        'label_width'         => $itemWidthVal,
        'label_height'        => $itemLengthVal,
        'label_material'      => $paperType,
        'label_core_size'     => (string)($r['job_size'] ?? ''),
        'label_qty_per_roll'  => $pcsPerRollVal,
        'label_repeat_mm'     => '',
        'label_die_no'        => '',
        'label_plate_no'      => '',
        'label_direction'     => '',
        'label_dispatch_date' => '',
        'label_job_no'        => $jobNo,
        'label_batch_no'      => $posBatchNo,
        'label_company_name'  => $companyName,
        'label_company_address' => $companyAddrSafe,
        // Use actual print date for template field job.date.
        'job.date'            => $printDateSlash,
        'print_date'          => $printDateSlash,
        'today_date'          => $printDateFormatted,
        'received_date'       => $dateFormatted,
        'job.receivedDate'    => $dateSlash,
        'job.date_ddmmyyyy'   => $printDateDdMmYyyy,
        'job.date_yyyymmdd'   => $printDateYmd,
        'received_date_ddmmyyyy' => $dateDdMmYyyy,
        'received_date_yyyymmdd' => $dateYmd,
        'job.batchId'         => $jobNo ?: $lotBatch,
        'job.machineId'       => '',
        'job.operator'        => '',
        'job.jobNo'           => $jobNo,
        'job.type'            => '',
        'job.status'          => $r['status'] ?? '',
        'job.notes'           => $r['remarks'] ?? '',
        'job.planningId'      => '',
        'job.planningJobName' => $resolvedJobName,
        'job.planningStatus'  => '',
        'job.planningPriority'=> '',
        'job.planningDate'    => '',
        'job.planningDie'     => '',
        'job.planningPlateNo' => '',
        'job.planningLabelSize' => $r['job_size'] ?? '',
        'job.planningRepeatMm' => '',
        'job.planningDirection' => '',
        'job.planningOrderMtr' => $lengthVal,
        'job.planningOrderQty' => '',
        'job.planningCoreSize' => '',
        'job.planningQtyPerRoll' => '',
        'job.planningMaterial' => $paperType,
        'job.planningPaperSize' => $r['job_size'] ?? '',
        'job.planningRemarks' => $r['remarks'] ?? '',
        'job.planningDispatchDate' => '',
        'job.rollNo'          => $rollNo,
        'job.paperType'       => $paperType,
        'job.paperCompany'    => $paperCompany,
        'job.width'           => $templateWidthVal,
        'job.length'          => $lengthVal,
        'job.gsm'             => $gsmVal,
        'job.weight'          => $weightVal,
        'job.sqm'             => $sqmVal,
        'job.lotBatchNo'      => $lotBatch,
        'job.previousJobNo'   => '',
        'job.previousJobStatus' => '',
        'job.department'      => '',
        'job.durationMinutes' => '',
        'job.startedAt'       => '',
        'job.completedAt'     => '',
        'sourceMaterials'     => '',
        'slittingOutputs'     => '',
    ];
}, $rolls);

$jsTemplates = array_map(function($t) {
    $bg = json_decode($t['background'] ?: '{}', true) ?: [];
    return [
        'id'         => (int)$t['id'],
        'name'       => $t['name'],
        'paperWidth' => (float)$t['paper_width'],
        'paperHeight'=> (float)$t['paper_height'],
        'elements'   => json_decode($t['elements'] ?: '[]', true) ?: [],
        'background' => ['image' => $bg['image'] ?? '', 'opacity' => (float)($bg['opacity'] ?? 1)],
        'isDefault'  => (bool)$t['is_default'],
        'isSystem'   => (bool)($t['is_system'] ?? false),
        'thumbnail'  => $t['thumbnail'] ?? '',
    ];
}, $templates);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print Labels — <?= e($companyName) ?></title>
<link rel="icon" href="<?= e($companyLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($companyLogoUrl) ?>">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.php">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Roboto:wght@400;500;700;900&family=Montserrat:wght@400;600;700;900&family=Poppins:wght@400;500;600;700;900&family=Oswald:wght@400;500;600;700&family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --brand: #f97316; --dark: #0f172a; --border: #e2e8f0; --muted: #94a3b8; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #1e293b; }

/* ── Top Toolbar ── */
.label-toolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 14px 24px; background: #fff; border-bottom: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,.06); flex-wrap: wrap;
}
.label-toolbar h1 { font-size: 15px; font-weight: 900; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.label-toolbar h1 .count-badge {
    background: var(--brand); color: #fff; font-size: 10px; font-weight: 800;
    padding: 2px 8px; border-radius: 10px;
}
.toolbar-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.toolbar-actions button, .toolbar-actions select {
    padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;
    border: 1px solid var(--border); background: #fff; display: inline-flex; align-items: center; gap: 6px;
}
.zoom-controls { display:inline-flex; align-items:center; gap:6px; padding:4px; border:1px solid var(--border); border-radius:10px; background:#fff; }
.zoom-controls .zoom-btn { min-width:34px; justify-content:center; padding:8px 10px; }
.zoom-controls .zoom-label { min-width:54px; text-align:center; font-size:11px; font-weight:800; color:#475569; }
.toolbar-actions .btn-print { background: var(--dark); color: #fff; border-color: var(--dark); }
.toolbar-actions .btn-print:hover { background: #1e293b; }
.toolbar-actions .btn-close { color: #64748b; }
.toolbar-actions .btn-close:hover { background: #f8fafc; }
.toolbar-actions select { min-width: 200px; }

/* ── Sidebar + Preview Split ── */
.label-layout { display: flex; height: calc(100vh - 62px); }
.label-sidebar {
    width: 280px; min-width: 260px; background: #fff; border-right: 1px solid var(--border);
    overflow-y: auto; padding: 16px; flex-shrink: 0;
}
.label-preview-area {
    flex: 1; overflow: auto; padding: 30px;
    display: flex; flex-wrap: wrap; gap: 24px; align-content: flex-start; justify-content: center;
}

/* ── Template Card ── */
.tpl-section-title {
    font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em;
    color: var(--muted); margin-bottom: 10px;
}
.tpl-card {
    padding: 10px 12px; border: 2px solid var(--border); border-radius: 10px;
    cursor: pointer; margin-bottom: 8px; transition: all .15s;
}
.tpl-card:hover { border-color: #94a3b8; background: #f8fafc; }
.tpl-card.active { border-color: var(--brand); background: #fff7ed; }
.tpl-card .tpl-name { font-size: 12px; font-weight: 700; color: var(--dark); }
.tpl-card .tpl-size { font-size: 10px; color: var(--muted); margin-top: 2px; }
.tpl-card .tpl-badge { display: inline-block; font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: 1px 6px; border-radius: 6px; margin-top: 4px; }
.tpl-badge.system { background: #dbeafe; color: #2563eb; }
.tpl-badge.default { background: #dcfce7; color: #16a34a; }

/* ── Roll selector ── */
.roll-section { margin-top: 20px; border-top: 1px solid var(--border); padding-top: 14px; }
.roll-item {
    display: flex; align-items: center; gap: 8px; padding: 6px 8px;
    border-radius: 8px; font-size: 11px; cursor: pointer; transition: background .1s;
}
.roll-item:hover { background: #f8fafc; }
.roll-item.active { background: #fff7ed; font-weight: 700; }
.roll-item input { cursor: pointer; }
.roll-item .roll-id { font-family: monospace; color: var(--brand); font-weight: 700; }

/* ── Label Preview Card ── */
.label-card {
    background: #fff; border: 1px solid #d1d5db; border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,.08); position: relative; overflow: hidden;
    page-break-inside: avoid; break-inside: avoid;
}
.label-canvas { position: relative; overflow: hidden; }
.label-el { position: absolute; white-space: pre-wrap; }
.label-line { position: absolute; }
.label-roll-id {
    position: absolute; top: 4px; right: 6px; font-size: 7px; font-weight: 700;
    color: var(--muted); background: rgba(255,255,255,.8); padding: 1px 4px; border-radius: 3px;
}

/* ── Print styles ── */
@media print {
    .no-print { display: none !important; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .label-layout { height: auto; display: block; overflow: visible; }
    .label-sidebar { display: none; }
    .label-preview-area {
        padding: 0 !important; margin: 0 !important; gap: 0 !important;
        display: block !important; overflow: visible !important;
        background: #fff !important;
        zoom: 1 !important;
    }
    .label-card {
        box-shadow: none !important; border: none !important; border-radius: 0 !important;
        page-break-after: always; page-break-inside: avoid;
        margin: 0 !important; padding: 0 !important;
        overflow: hidden !important;
        /* Keep JS-set pixel dimensions — they match @page size at 3.78px/mm (96 DPI) */
    }
    .label-card:last-child { page-break-after: auto; }
}

@page { margin: 0; }
#dynamic-page-style { }
/* ── Built-in Default Label ── */
.builtin-label {
    width: 100%; height: 100%; display: flex; flex-direction: column;
    font-family: 'Segoe UI', Arial, sans-serif; padding: 14px 16px 10px;
}
.bl-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.bl-company { font-size: 14px; font-weight: 900; color: #0f172a; line-height: 1.2; flex: 1; }
.bl-qr { flex-shrink: 0; width: 72px; height: 72px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.bl-qr svg, .bl-qr canvas, .bl-qr img { display: block; width: 70px !important; height: 70px !important; }
.bl-divider { height: 2px; background: linear-gradient(90deg, #f97316, #fb923c, transparent); margin: 6px 0 8px; border-radius: 2px; }
.bl-roll-no {
    font-size: 22px; font-weight: 900; color: #f97316; font-family: 'Consolas', 'Courier New', monospace;
    letter-spacing: 1px; margin-bottom: 6px; line-height: 1.1;
}
.bl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; flex: 1; }
.bl-field { display: flex; flex-direction: column; }
.bl-field-label { font-size: 7px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .6px; line-height: 1.2; }
.bl-field-value { font-size: 10px; font-weight: 700; color: #1e293b; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bl-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 6px; border-top: 1px solid #e2e8f0; }
.bl-status {
    display: inline-block; font-size: 9px; font-weight: 800; text-transform: uppercase;
    padding: 2px 10px; border-radius: 8px; letter-spacing: .5px;
}
.bl-status.available { background: #dcfce7; color: #16a34a; }
.bl-status.in-use, .bl-status.in_use { background: #dbeafe; color: #2563eb; }
.bl-status.finished { background: #fee2e2; color: #dc2626; }
.bl-status.reserved { background: #fef3c7; color: #d97706; }
.bl-status.default-status { background: #f1f5f9; color: #64748b; }
.bl-date { font-size: 8px; color: #94a3b8; font-weight: 600; }

/* ── Built-in POS Roll Sticker 40x25 ── */
.pos-label {
    width: 100%; height: 100%;
    font-family: 'Segoe UI', Arial, sans-serif;
    padding: 2px 3px;
    display: flex;
    flex-direction: column;
    color: #000;
}
.pos-title { font-size: 8px; font-weight: 900; line-height: 1; letter-spacing: .2px; }
.pos-line { font-size: 6.2px; font-weight: 700; line-height: 1.05; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pos-spacer { flex: 1; }
.pos-batch { font-size: 6px; font-weight: 800; line-height: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pos-barcode {
    height: 38%;
    margin-top: 1px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.pos-barcode svg { width: 100% !important; height: 100% !important; }

/* ── Built-in Packing Label 150x100 ── */
.pk-label-150x100 {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    text-align: center;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    padding: 12px 14px;
    gap: 2px;
    background: #fff;
}
.pk150-title { 
    font-size: 20px; 
    font-weight: bold; 
    line-height: 1.1; 
    text-transform: uppercase; 
    color: #000;
    margin: 0;
}
.pk150-line { 
    font-size: 13px; 
    font-weight: bold; 
    line-height: 1.2; 
    color: #000;
    margin: 2px 0 0 0;
}
.pk150-divider {
    height: 2px;
    background: #000;
    margin: 4px 0;
    flex: 0 0 auto;
}
.pk150-barcode-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 2px 0;
    flex: 0 0 auto;
}
.pk150-barcode-wrap svg {
    max-width: 78%;
    height: auto;
}
.pk150-product { 
    font-size: 13px; 
    font-weight: bold; 
    line-height: 1.1; 
    color: #000;
    margin: 2px 0 0 0;
}
.pk150-meta { 
    font-size: 11px; 
    font-weight: bold; 
    line-height: 1.1; 
    color: #000;
    margin: 1px 0 0 0;
}
.pk150-mfg-label { 
    font-size: 10px; 
    font-weight: bold; 
    line-height: 1.1; 
    color: #000;
    margin-top: 2px;
}
.pk150-company { 
    font-size: 13px; 
    font-weight: bold; 
    line-height: 1.1; 
    text-transform: uppercase; 
    color: #000;
    margin: 1px 0 0 0;
}
.pk150-address { 
    font-size: 9px; 
    font-weight: normal; 
    line-height: 1.2; 
    color: #000;
    max-width: 95%; 
    margin: 1px auto 0;
}
.pk150-origin { 
    font-size: 12px; 
    font-weight: bold; 
    line-height: 1.1; 
    color: #000;
    margin-top: 2px;
}
</style>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
// Fallback: if CDN fails, provide a minimal placeholder generator
if (typeof qrcode === 'undefined') {
    window.qrcode = function() {
        return {
            addData: function(){},
            make: function(){},
            getModuleCount: function(){ return 21; },
            createSvgTag: function(){ return '<svg viewBox="0 0 70 70" width="70" height="70"><rect fill="#f1f5f9" width="70" height="70" rx="4"/><text x="35" y="35" text-anchor="middle" dy=".3em" fill="#94a3b8" font-size="10" font-family="sans-serif">QR</text></svg>'; },
            createImgTag: function(){ return '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" width="70" height="70" alt="QR">'; }
        };
    };
}
</script>
</head>
<body>

<!-- Toolbar -->
<div class="label-toolbar no-print">
    <h1>
        <i class="bi bi-printer" style="color:var(--brand)"></i>
        Print Labels
        <span class="count-badge"><?= count($rolls) ?> roll<?= count($rolls) > 1 ? 's' : '' ?></span>
    </h1>
    <div class="toolbar-actions">
        <div class="zoom-controls no-print" title="Preview zoom only">
            <button type="button" class="zoom-btn" onclick="adjustPreviewZoom(-10)"><i class="bi bi-dash-lg"></i></button>
            <span class="zoom-label" id="zoom-label">100%</span>
            <button type="button" class="zoom-btn" onclick="adjustPreviewZoom(10)"><i class="bi bi-plus-lg"></i></button>
            <button type="button" class="zoom-btn" onclick="resetPreviewZoom()" title="Reset zoom"><i class="bi bi-arrow-counterclockwise"></i></button>
        </div>
        <select id="tpl-quick-select" onchange="selectTemplateById(this.value)">
            <?php foreach ($templates as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= $t['paper_width'] ?>×<?= $t['paper_height'] ?>mm)</option>
            <?php endforeach; ?>
            <?php if (empty($templates)): ?>
            <option value="0">No templates found</option>
            <?php endif; ?>
        </select>
        <button class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print Labels</button>
        <button class="btn-close" onclick="handleLabelCancel()"><i class="bi bi-x-lg"></i> Cancel</button>
    </div>
</div>

<div class="label-layout">
    <!-- Sidebar -->
    <div class="label-sidebar no-print">
        <div class="tpl-section-title"><i class="bi bi-palette"></i> Label Templates</div>
        <?php if (empty($templates)): ?>
        <div style="font-size:11px;color:var(--muted);padding:10px 0">No label templates found. <a href="<?= BASE_URL ?>/modules/print/index.php" style="color:var(--brand);font-weight:700">Create one in Print Studio</a></div>
        <?php endif; ?>
        <div id="tpl-list">
        <?php foreach ($templates as $t): ?>
        <div class="tpl-card<?= $t['is_default'] ? ' active' : '' ?>" data-tpl-id="<?= $t['id'] ?>" onclick="selectTemplateById(<?= $t['id'] ?>)">
            <div class="tpl-name"><?= e($t['name']) ?></div>
            <div class="tpl-size"><?= $t['paper_width'] ?> × <?= $t['paper_height'] ?> mm</div>
            <?php if ($t['is_system'] ?? false): ?><span class="tpl-badge system">System</span><?php endif; ?>
            <?php if ($t['is_default']): ?><span class="tpl-badge default">Default</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="roll-section">
            <div class="tpl-section-title"><i class="bi bi-list-check"></i> Rolls to Print</div>
            <label style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" id="select-all-rolls" checked onchange="toggleAllRolls(this.checked)">
                Select All (<?= count($rolls) ?>)
            </label>
            <?php foreach ($rolls as $r): ?>
            <label class="roll-item active" data-roll-id="<?= $r['id'] ?>">
                <input type="checkbox" class="roll-cb" value="<?= $r['id'] ?>" checked onchange="updatePreview()">
                <span class="roll-id"><?= e($r['roll_no']) ?></span>
                <span style="color:var(--muted);font-size:10px"><?= e($r['company']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Preview Area -->
    <div class="label-preview-area" id="label-preview-area">
        <!-- Labels rendered by JS -->
    </div>
</div>

<script>
(function(){
'use strict';

var rolls = <?= json_encode($jsRolls, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var templates = <?= json_encode($jsTemplates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var cancelBackUrl = <?= json_encode($cancelBackUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var baseUrl = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var printType = <?= json_encode($printType, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var bundlePcs = <?= json_encode($bundlePcs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var itemWidthParam = <?= json_encode($itemWidthParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var batchNoParam = <?= json_encode($batchNoParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var batchLabelsParam = <?= json_encode($batchLabelsParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var jobNameParam = <?= json_encode($jobNameParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var rollsPerCarton = <?= json_encode($rollsPerCarton, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var mixedEnabledParam = <?= json_encode($mixedEnabledParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var mixedCartonsParam = <?= json_encode($mixedCartonsParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var mixedExtraRollsParam = <?= json_encode($mixedExtraRollsParam, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var previewZoom = 100;
var activeTemplateId = 0;

// Find default template
for (var i = 0; i < templates.length; i++) {
    if (templates[i].isDefault) { activeTemplateId = templates[i].id; break; }
}
if (!activeTemplateId && templates.length) activeTemplateId = templates[0].id;

if (printType === 'sticker') {
    for (var j = 0; j < templates.length; j++) {
        if (String(templates[j].name || '').toLowerCase() === 'pos roll sticker 40x25') {
            activeTemplateId = templates[j].id;
            break;
        }
    }
} else if (printType === 'label') {
    for (var k = 0; k < templates.length; k++) {
        var tplName = String(templates[k].name || '').toLowerCase();
        if (tplName === 'label printing 150x100' || tplName === 'packing label 150x100') {
            activeTemplateId = templates[k].id;
            break;
        }
    }
}

/* ── Font ID → CSS family mapping (matches Firebase Print Studio) ── */
var FONT_MAP = {
    'inter':      "'Inter', sans-serif",
    'roboto':     "'Roboto', sans-serif",
    'montserrat': "'Montserrat', sans-serif",
    'poppins':    "'Poppins', sans-serif",
    'oswald':     "'Oswald', sans-serif",
    'open-sans':  "'Open Sans', sans-serif",
    'arial':      "Arial, sans-serif",
    'helvetica':  "Helvetica, sans-serif",
    'mono':       "ui-monospace, SFMono-Regular, 'Courier New', monospace"
};
function getFontFamily(id) {
    if (!id) return 'sans-serif';
    // If it's already a CSS value (contains comma or quotes), use as-is
    if (id.indexOf(',') !== -1 || id.indexOf("'") !== -1) return id;
    return FONT_MAP[id.toLowerCase()] || ("'" + id + "', sans-serif");
}

function getTemplate(id) {
    for (var i = 0; i < templates.length; i++) {
        if (templates[i].id === id) return templates[i];
    }
    return templates[0] || null;
}

function getSelectedRollIds() {
    var out = [];
    document.querySelectorAll('.roll-cb').forEach(function(cb) {
        if (cb.checked) out.push(parseInt(cb.value));
    });
    return out;
}

function applyPreviewZoom() {
    var area = document.getElementById('label-preview-area');
    if (!area) return;
    var z = Math.max(50, Math.min(300, previewZoom));
    previewZoom = z;
    area.style.zoom = (z / 100);
    var lbl = document.getElementById('zoom-label');
    if (lbl) lbl.textContent = z + '%';
}

window.adjustPreviewZoom = function(step) {
    previewZoom = (previewZoom || 100) + (Number(step) || 0);
    applyPreviewZoom();
};

window.resetPreviewZoom = function() {
    previewZoom = 100;
    applyPreviewZoom();
};

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s != null ? s : '');
    return d.innerHTML;
}

/* ── QR Code Generator (optional size, default 70px) ── */
function generateQR(data, size) {
    size = size || 70;
    try {
        if (typeof qrcode !== 'function') throw new Error('QR lib not loaded');
        var qr = qrcode(0, 'M');
        qr.addData(String(data || ''));
        qr.make();
        var modules = qr.getModuleCount();
        var cellSize = Math.max(1, Math.floor(size / modules));
        return qr.createSvgTag(cellSize, 0);
    } catch (e) {
        return '<svg viewBox="0 0 70 70" width="' + size + '" height="' + size + '"><rect fill="#f1f5f9" width="70" height="70" rx="6"/><text x="35" y="32" text-anchor="middle" fill="#94a3b8" font-size="9" font-family="sans-serif">QR Code</text><text x="35" y="44" text-anchor="middle" fill="#cbd5e1" font-size="7" font-family="sans-serif">unavailable</text></svg>';
    }
}

/* ── Status class helper ── */
function getStatusClass(status) {
    var s = String(status || '').toLowerCase().replace(/[\s-]/g, '_');
    if (s === 'available' || s === 'in_stock') return 'available';
    if (s === 'in_use' || s === 'in_process') return 'in-use';
    if (s === 'finished' || s === 'used' || s === 'consumed') return 'finished';
    if (s === 'reserved' || s === 'hold') return 'reserved';
    return 'default-status';
}

/* ── Placeholder replacer (supports {key}, {{key}}, and {{dotted.key}}) ── */
function replacePlaceholders(text, roll) {
    return String(text || '').replace(/\{\{([^}]+)\}\}|\{(\w+)\}/g, function(match, dblKey, sglKey) {
        var key = (dblKey || sglKey || '').trim();
        if (roll[key] !== undefined && roll[key] !== null) return String(roll[key]);
        return '';
    });
}

function getBuiltinLayout(tpl) {
    var els = (tpl && tpl.elements) ? tpl.elements : [];
    for (var i = 0; i < els.length; i++) {
        if (els[i].type === 'builtin') return String(els[i].layout || 'default_stock_label');
    }
    return 'default_stock_label';
}

function normalizeStickerWidth(v) {
    var raw = String(v || '').replace(/\s*mm$/i, '').trim();
    if (!raw) return '';
    var n = Number(raw.replace(/,/g, ''));
    if (isNaN(n) || n <= 0 || n > 2000) return '';
    return Math.abs(n - Math.round(n)) < 0.001 ? String(Math.round(n)) : String(n.toFixed(2)).replace(/\.00$/, '');
}

/* ── Built-in Professional Label (for system/default template) ── */
function renderBuiltinLabel(roll, tpl) {
    var scale = 3.78;
    var w = tpl.paperWidth * scale;
    var h = tpl.paperHeight * scale;

    var card = document.createElement('div');
    card.className = 'label-card';
    card.style.width = w + 'px';
    card.style.height = h + 'px';

    try {
        var builtinLayout = getBuiltinLayout(tpl);

        if (builtinLayout === 'pos_roll_sticker_40x25') {
            var companySource = String(roll.company_roll_no || roll.company || '');
            var companyCodeRaw = companySource.replace(/[^A-Za-z]/g, '').toUpperCase();
            var companyCode = companyCodeRaw ? companyCodeRaw.substring(0, 2) : 'NA';
            var batchBase = String(roll.pos_batch_no || roll.batch_no || batchNoParam || '').trim() || String(roll.roll_no || '').trim() || 'NA';
            var batchNo = /\/\s*[A-Za-z]{2,}$/.test(batchBase) ? batchBase : (batchBase + ' / ' + companyCode);
            var batchDisplay = String(roll.batch_display || batchNo);
            var widthSource = normalizeStickerWidth(itemWidthParam) || normalizeStickerWidth(roll.width_mm) || '0';
            var sizeText = (widthSource || '0') + ' mm';
            var bundleText = bundlePcs && String(bundlePcs).trim() !== '' ? String(bundlePcs) : '0';
            var materialText = String(roll.paper_type || 'THERMAL PAPER').toUpperCase();
            var barcodeValue = String(roll.barcode_value || roll.job_token || roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id));
            var barcodeId = 'pos-bc-' + String(roll.id || Math.floor(Math.random() * 100000));

            var posHtml = '';
            posHtml += '<div class="pos-label">';
            posHtml += '<div class="pos-title">PAPER ROLL</div>';
            posHtml += '<div class="pos-line">' + escHtml(materialText) + '</div>';
            posHtml += '<div class="pos-line">Size: ' + escHtml(sizeText) + '</div>';
            posHtml += '<div class="pos-line">Bundle: ' + escHtml(bundleText) + ' PCS</div>';
            posHtml += '<div class="pos-spacer"></div>';
            posHtml += '<div class="pos-batch">' + escHtml(batchDisplay) + '</div>';
            posHtml += '<div class="pos-barcode"><svg id="' + escHtml(barcodeId) + '"></svg></div>';
            posHtml += '</div>';
            card.innerHTML = posHtml;

            if (typeof JsBarcode !== 'undefined') {
                var posSvg = card.querySelector('#' + barcodeId);
                if (posSvg) {
                    try {
                        JsBarcode(posSvg, barcodeValue, {
                            format: 'CODE128',
                            width: 0.9,
                            height: 16,
                            displayValue: false,
                            margin: 1
                        });
                    } catch (e) {
                        posSvg.outerHTML = '<div style="font-size:6px;color:#999">Barcode Error</div>';
                    }
                }
            }
            return card;
        }

        if (builtinLayout === 'packing_label_150x100') {
            var labelCompanySource = String(roll.company_roll_no || roll.company || '');
            var labelCompanyCodeRaw = labelCompanySource.replace(/[^A-Za-z]/g, '').toUpperCase();
            var labelCompanyCode = labelCompanyCodeRaw ? labelCompanyCodeRaw.substring(0, 2) : 'NA';
            var rawBatchLabels = String(roll.batch_labels || batchLabelsParam || '').trim();
            var batchTextBase = rawBatchLabels !== '' ? rawBatchLabels : String(batchNoParam || roll.batch_no || roll.roll_no || 'NA').trim();
            if (batchTextBase.indexOf(',') !== -1) {
                var parts = batchTextBase.split(',').map(function(v) { return String(v || '').trim(); }).filter(function(v) { return v !== ''; });
                if (parts.length > 1) {
                    batchTextBase = 'MIXED: ' + parts.join(' + ');
                }
            }
            var batchText = batchTextBase + ' / ' + labelCompanyCode;
            var labelPaperType = String(roll.paper_type || 'PAPER').trim().toUpperCase();
            var labelJobName = String(roll.job_name || jobNameParam || '-').trim();
            var labelWidth = normalizeStickerWidth(itemWidthParam) || normalizeStickerWidth(roll.item_width) || normalizeStickerWidth(roll.width_mm) || '0';
            var labelRpc = String(roll.rolls_per_carton || rollsPerCarton || '0').trim();
            if (!labelRpc || labelRpc === '0') {
                labelRpc = String(bundlePcs || roll.bundle_pcs || '0').trim();
            }
            var labelCompanyName = String(roll.company_name || '').trim() || 'COMPANY';
            var labelCompanyAddress = String(roll.company_address || roll['job.companyAddress'] || '').trim() || '-';
            var barcodeValue = String(roll.barcode_value || roll.job_token || (batchTextBase && batchTextBase !== 'NA' ? batchTextBase : roll.roll_no || roll.id));
            var barcodeId = 'pk150-bc-' + String(roll.id || Math.floor(Math.random() * 100000));

            var html150 = '';
            html150 += '<div class="pk-label-150x100">';
            html150 += '<div class="pk150-title">' + escHtml(labelPaperType) + '</div>';
            html150 += '<div class="pk150-line">' + escHtml(batchText) + '</div>';
            html150 += '<div class="pk150-divider"></div>';
            html150 += '<div class="pk150-barcode-wrap"><svg id="' + escHtml(barcodeId) + '"></svg></div>';
            html150 += '<div class="pk150-divider"></div>';
            html150 += '<div class="pk150-product">' + escHtml(labelJobName || '-') + '</div>';
            html150 += '<div class="pk150-meta">Qty: ' + escHtml(labelRpc || '0') + ' Pcs | ' + escHtml(labelWidth) + ' mm</div>';
            if (Number(mixedEnabledParam || 0) === 1) {
                html150 += '<div class="pk150-meta">Mixed Cartons: ' + escHtml(String(mixedCartonsParam || '0')) + ' | Mixed Extra: ' + escHtml(String(mixedExtraRollsParam || '0')) + '</div>';
            }
            html150 += '<div class="pk150-mfg-label">Manufactured by:</div>';
            html150 += '<div class="pk150-company">' + escHtml(labelCompanyName) + '</div>';
            html150 += '<div class="pk150-address">' + escHtml(labelCompanyAddress) + '</div>';
            html150 += '<div class="pk150-origin">MADE IN INDIA</div>';
            html150 += '</div>';
            card.innerHTML = html150;

            // Generate barcode
            if (typeof JsBarcode !== 'undefined') {
                var pk150Svg = card.querySelector('#' + barcodeId);
                if (pk150Svg) {
                    try {
                        JsBarcode(pk150Svg, barcodeValue, {
                            format: 'CODE128',
                            width: 0.85,
                            height: 24,
                            displayValue: true,
                            fontSize: 9,
                            margin: 2
                        });
                    } catch (e) {
                        pk150Svg.outerHTML = '<div style="font-size:9px;color:#000;padding:4px">Barcode Error</div>';
                    }
                }
            }
            return card;
        }

        var qrData = roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id);

        var qrSvg = generateQR(qrData);
        var statusCls = getStatusClass(roll.status);

        var html = '<div class="builtin-label">';
        html += '<div class="bl-header">';
        html += '<div class="bl-company">' + escHtml(roll.company_name) + '</div>';
        html += '<div class="bl-qr">' + qrSvg + '</div>';
        html += '</div>';
        html += '<div class="bl-divider"></div>';
        html += '<div class="bl-roll-no">' + escHtml(roll.roll_no) + '</div>';
        html += '<div class="bl-grid">';
        html += '<div class="bl-field"><span class="bl-field-label">Company / Mill</span><span class="bl-field-value">' + escHtml(roll.company || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Paper Type</span><span class="bl-field-value">' + escHtml(roll.paper_type || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Width</span><span class="bl-field-value">' + escHtml(roll.width_mm || '—') + ' mm</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Length</span><span class="bl-field-value">' + escHtml(roll.length_mtr || '—') + ' MTR</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">GSM</span><span class="bl-field-value">' + escHtml(roll.gsm || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Weight</span><span class="bl-field-value">' + escHtml(roll.weight_kg || '—') + ' KG</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">SQM</span><span class="bl-field-value">' + escHtml(roll.sqm || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Lot / Batch</span><span class="bl-field-value">' + escHtml(roll.lot_batch_no || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Job No</span><span class="bl-field-value">' + escHtml(roll.job_no || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Job Size</span><span class="bl-field-value">' + escHtml(roll.job_size || '—') + '</span></div>';
        html += '</div>';
        html += '<div class="bl-footer">';
        html += '<span class="bl-status ' + statusCls + '">' + escHtml(roll.status || 'Unknown') + '</span>';
        html += '<span class="bl-date">' + escHtml(roll.date_received || '') + '</span>';
        html += '</div>';
        html += '</div>';
        card.innerHTML = html;
    } catch (e) {
        card.innerHTML = '<div style="padding:16px;font-size:12px;color:#ef4444"><b>Render Error</b><br>' + escHtml(roll.roll_no) + '</div>';
    }
    return card;
}

/* ── Print Studio custom template renderer ── */
function renderCustomLabel(roll, tpl) {
    var scale = 3.78;
    var w = tpl.paperWidth * scale;
    var h = tpl.paperHeight * scale;

    var card = document.createElement('div');
    card.className = 'label-card';
    card.style.width = w + 'px';
    card.style.height = h + 'px';

    var canvas = document.createElement('div');
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.position = 'relative';
    canvas.style.overflow = 'hidden';

    // Background image
    if (tpl.background && tpl.background.image) {
        var bgDiv = document.createElement('div');
        bgDiv.style.position = 'absolute';
        bgDiv.style.inset = '0';
        bgDiv.style.zIndex = '0';
        bgDiv.style.pointerEvents = 'none';
        bgDiv.style.opacity = (tpl.background.opacity != null) ? tpl.background.opacity : 1;
        var bgImg = document.createElement('img');
        bgImg.src = tpl.background.image;
        bgImg.style.width = '100%';
        bgImg.style.height = '100%';
        bgImg.style.objectFit = 'contain';
        bgDiv.appendChild(bgImg);
        canvas.appendChild(bgDiv);
    }

    (tpl.elements || []).forEach(function(el) {
        // Print Studio format: {x, y, width, height, content, style:{...}, type, rotate}
        var sty = el.style || {};
        var px = parseFloat(el.x) || 0;
        var py = parseFloat(el.y) || 0;
        var pw = parseFloat(el.width) || 0;
        var ph = parseFloat(el.height) || 0;
        var rot = parseFloat(el.rotate) || 0;

        if (el.type === 'text') {
            var align = sty.textAlign || 'left';
            var jc = align === 'center' ? 'center' : (align === 'right' ? 'flex-end' : 'flex-start');
            var div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = px + 'px';
            div.style.top = py + 'px';
            div.style.width = pw + 'px';
            div.style.height = ph + 'px';
            div.style.overflow = 'hidden';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.justifyContent = jc;
            div.style.zIndex = '1';
            if (sty.backgroundColor && sty.backgroundColor !== 'transparent') {
                div.style.backgroundColor = sty.backgroundColor;
            }
            if (parseFloat(sty.borderWidth) > 0) {
                div.style.border = (sty.borderWidth || 1) + 'px ' + (sty.lineStyle || 'solid') + ' ' + (sty.borderColor || '#000');
            }
            if (parseFloat(sty.borderRadius) > 0) {
                div.style.borderRadius = sty.borderRadius + 'px';
            }
            if (rot) div.style.transform = 'rotate(' + rot + 'deg)';
            var content = replacePlaceholders(el.content || '', roll);
            var textInner = document.createElement('div');
            textInner.style.width = '100%';
            textInner.style.textAlign = align;
            textInner.style.fontSize = (sty.fontSize || 14) + 'px';
            textInner.style.fontWeight = sty.fontWeight || 'normal';
            textInner.style.fontFamily = getFontFamily(sty.fontFamily);
            textInner.style.color = sty.color || '#000';
            textInner.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            textInner.style.wordBreak = 'break-word';
            textInner.style.lineHeight = '1.3';
            textInner.textContent = content;
            div.appendChild(textInner);
            canvas.appendChild(div);
        }
        else if (el.type === 'qr') {
            var qrWrap = document.createElement('div');
            qrWrap.style.position = 'absolute';
            qrWrap.style.left = px + 'px';
            qrWrap.style.top = py + 'px';
            qrWrap.style.width = pw + 'px';
            qrWrap.style.height = ph + 'px';
            qrWrap.style.display = 'flex';
            qrWrap.style.alignItems = 'center';
            qrWrap.style.justifyContent = 'center';
            qrWrap.style.zIndex = '1';
            if (rot) qrWrap.style.transform = 'rotate(' + rot + 'deg)';
            var qrContent = replacePlaceholders(el.content || '', roll);
            if (!qrContent || qrContent === '') {
                qrContent = roll.scan_barcode_url || roll.scan_job_url || roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id);
            }
            var qrSz = Math.min(pw, ph);
            qrWrap.innerHTML = generateQR(qrContent, qrSz);
            var svgEl = qrWrap.querySelector('svg');
            if (svgEl) {
                svgEl.style.width = qrSz + 'px';
                svgEl.style.height = qrSz + 'px';
            }
            canvas.appendChild(qrWrap);
        }
        else if (el.type === 'barcode') {
            var bcWrap = document.createElement('div');
            bcWrap.style.position = 'absolute';
            bcWrap.style.left = px + 'px';
            bcWrap.style.top = py + 'px';
            bcWrap.style.width = pw + 'px';
            bcWrap.style.height = ph + 'px';
            bcWrap.style.display = 'flex';
            bcWrap.style.alignItems = 'center';
            bcWrap.style.justifyContent = 'center';
            bcWrap.style.zIndex = '1';
            if (rot) bcWrap.style.transform = 'rotate(' + rot + 'deg)';
            var bcVal = replacePlaceholders(el.content || el.placeholder || '', roll);
            if (!bcVal || bcVal === '') {
                bcVal = roll.barcode_value || roll.job_token || roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id);
            }

            // For POS roll sticker/label print mode, force barcode payload to unified job scan token.
            if (printType === 'sticker' || printType === 'label') {
                var rollUrl = String(roll.view_url || roll.roll_url || '');
                var preferredStickerPayload = String(roll.barcode_value || roll.job_token || '');
                if (preferredStickerPayload) {
                    var normalizedBc = String(bcVal || '').trim();
                    var normalizedRollUrl = String(rollUrl || '').trim();
                    var looksLikePaperStockUrl = normalizedBc.indexOf('/modules/paper_stock/view.php?id=') !== -1;
                    if (!normalizedBc || looksLikePaperStockUrl || (normalizedRollUrl !== '' && normalizedBc === normalizedRollUrl)) {
                        bcVal = preferredStickerPayload;
                    }
                }
            }

            var bcFmt = el.barcodeType || 'CODE128';
            if (bcFmt === 'EAN13' || bcFmt === 'UPC') bcVal = '123456789012';
            if (typeof JsBarcode !== 'undefined') {
                var svgNS = 'http://www.w3.org/2000/svg';
                var bcSvg = document.createElementNS(svgNS, 'svg');
                bcWrap.appendChild(bcSvg);
                try {
                    var isCompact = (bcFmt === 'CODE128') && ((pw || 0) <= 90 || (ph || 0) <= 30 || String(bcVal).length <= 10);
                    JsBarcode(bcSvg, bcVal, {
                        format: bcFmt,
                        height: isCompact ? Math.max(12, (ph || 24) - 4) : Math.max(20, (ph || 50) - 30),
                        width: isCompact ? 0.9 : 1.5,
                        fontSize: isCompact ? 0 : 10,
                        displayValue: !isCompact,
                        margin: isCompact ? 1 : 4
                    });
                    bcSvg.style.width = '100%';
                    bcSvg.style.height = '100%';
                    bcSvg.style.display = 'block';
                    bcSvg.setAttribute('preserveAspectRatio', 'none');
                } catch(e) {
                    bcWrap.innerHTML = '<div style="font-size:10px;color:#94a3b8;text-align:center">Barcode Error</div>';
                }
            } else {
                bcWrap.innerHTML = generateQR(bcVal, Math.min(pw, ph));
            }
            canvas.appendChild(bcWrap);
        }
        else if (el.type === 'field' || el.type === 'title') {
            var align = sty.textAlign || 'left';
            var jc = align === 'center' ? 'center' : (align === 'right' ? 'flex-end' : 'flex-start');
            var div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = px + 'px';
            div.style.top = py + 'px';
            div.style.width = pw + 'px';
            div.style.height = ph + 'px';
            div.style.overflow = 'hidden';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.justifyContent = jc;
            div.style.zIndex = '1';
            if (sty.backgroundColor && sty.backgroundColor !== 'transparent') div.style.backgroundColor = sty.backgroundColor;
            if (parseFloat(sty.borderWidth) > 0) div.style.border = (sty.borderWidth || 1) + 'px ' + (sty.lineStyle || 'solid') + ' ' + (sty.borderColor || '#000');
            if (parseFloat(sty.borderRadius) > 0) div.style.borderRadius = sty.borderRadius + 'px';
            if (rot) div.style.transform = 'rotate(' + rot + 'deg)';
            var rawText = el.type === 'field' ? (el.placeholder || el.content || '') : (el.content || '');
            var content = replacePlaceholders(rawText, roll);
            var textInner = document.createElement('div');
            textInner.style.width = '100%';
            textInner.style.textAlign = align;
            textInner.style.fontSize = (sty.fontSize || 14) + 'px';
            textInner.style.fontWeight = sty.fontWeight || 'normal';
            textInner.style.fontFamily = getFontFamily(sty.fontFamily);
            textInner.style.color = sty.color || '#000';
            textInner.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            textInner.style.wordBreak = 'break-word';
            textInner.style.lineHeight = '1.3';
            textInner.textContent = content;
            div.appendChild(textInner);
            canvas.appendChild(div);
        }
        else if (el.type === 'line' || el.type === 'divider') {
            var line = document.createElement('div');
            line.style.position = 'absolute';
            line.style.left = px + 'px';
            line.style.top = py + 'px';
            line.style.width = pw + 'px';
            line.style.height = Math.max(ph, parseFloat(sty.borderWidth) || 1) + 'px';
            line.style.background = sty.borderColor || sty.color || '#000';
            line.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            line.style.zIndex = '1';
            if (rot) line.style.transform = 'rotate(' + rot + 'deg)';
            canvas.appendChild(line);
        }
        else if (el.type === 'rect' || el.type === 'rectangle' || el.type === 'shape' || el.type === 'circle') {
            var rect = document.createElement('div');
            rect.style.position = 'absolute';
            rect.style.left = px + 'px';
            rect.style.top = py + 'px';
            rect.style.width = pw + 'px';
            rect.style.height = ph + 'px';
            if (sty.backgroundColor && sty.backgroundColor !== 'transparent') {
                rect.style.backgroundColor = sty.backgroundColor;
            }
            if (parseFloat(sty.borderWidth) > 0) {
                rect.style.border = (sty.borderWidth || 1) + 'px ' + (sty.lineStyle || 'solid') + ' ' + (sty.borderColor || '#000');
            }
            var br = parseFloat(sty.borderRadius) || 0;
            if (el.type === 'circle') br = Math.max(pw, ph);
            if (br > 0) rect.style.borderRadius = br + 'px';
            rect.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            rect.style.zIndex = '1';
            if (rot) rect.style.transform = 'rotate(' + rot + 'deg)';
            canvas.appendChild(rect);
        }
        else if (el.type === 'image') {
            var imgWrap = document.createElement('div');
            imgWrap.style.position = 'absolute';
            imgWrap.style.left = px + 'px';
            imgWrap.style.top = py + 'px';
            imgWrap.style.width = pw + 'px';
            imgWrap.style.height = ph + 'px';
            imgWrap.style.zIndex = '1';
            imgWrap.style.overflow = 'hidden';
            if (rot) imgWrap.style.transform = 'rotate(' + rot + 'deg)';
            if (parseFloat(sty.borderRadius) > 0) {
                imgWrap.style.borderRadius = sty.borderRadius + 'px';
            }
            var imgSrc = replacePlaceholders(el.content || el.src || el.placeholder || '', roll);
            if (imgSrc) {
                var img = document.createElement('img');
                img.src = imgSrc;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'contain';
                img.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
                img.style.display = 'block';
                img.onerror = function() { this.style.display = 'none'; };
                imgWrap.appendChild(img);
            } else {
                imgWrap.style.backgroundColor = '#f1f5f9';
                imgWrap.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#94a3b8;font-size:10px">No Image</div>';
            }
            canvas.appendChild(imgWrap);
        }
        else if (el.type === 'table') {
            // Table element — render as simple grid
            var tbl = document.createElement('div');
            tbl.style.position = 'absolute';
            tbl.style.left = px + 'px';
            tbl.style.top = py + 'px';
            tbl.style.width = pw + 'px';
            tbl.style.height = ph + 'px';
            tbl.style.zIndex = '1';
            tbl.style.fontSize = (sty.fontSize || 10) + 'px';
            tbl.style.fontFamily = getFontFamily(sty.fontFamily);
            tbl.style.color = sty.color || '#000';
            tbl.style.border = '1px solid ' + (sty.borderColor || '#000');
            tbl.style.overflow = 'hidden';
            var content = replacePlaceholders(el.content || '', roll);
            tbl.textContent = content;
            canvas.appendChild(tbl);
        }
    });

    card.appendChild(canvas);
    return card;
}

/* ── Main render dispatcher ── */
function renderLabel(roll, tpl) {
    var els = tpl.elements || [];
    // Check for builtin marker
    for (var i = 0; i < els.length; i++) {
        if (els[i].type === 'builtin') return renderBuiltinLabel(roll, tpl);
    }
    // Empty elements → fallback to built-in
    if (els.length === 0) return renderBuiltinLabel(roll, tpl);

    // Custom Print Studio template
    return renderCustomLabel(roll, tpl);
}

window.updatePreview = function() {
    var area = document.getElementById('label-preview-area');
    area.innerHTML = '';
    var tpl = getTemplate(activeTemplateId);
    if (!tpl) {
        area.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:14px;padding:40px"><i class="bi bi-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:10px"></i>No template selected.</div>';
        return;
    }

    // Dynamic @page size based on template paper dimensions
    var dynStyle = document.getElementById('dynamic-page-style');
    if (!dynStyle) {
        dynStyle = document.createElement('style');
        dynStyle.id = 'dynamic-page-style';
        document.head.appendChild(dynStyle);
    }
    dynStyle.textContent = '@page { size: ' + tpl.paperWidth + 'mm ' + tpl.paperHeight + 'mm; margin: 0; }';

    var selectedIds = getSelectedRollIds();
    var rendered = 0;
    for (var i = 0; i < rolls.length; i++) {
        if (selectedIds.indexOf(rolls[i].id) === -1) continue;
        area.appendChild(renderLabel(rolls[i], tpl));
        rendered++;
    }

    if (rendered === 0) {
        area.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:14px;padding:40px"><i class="bi bi-check2-square" style="font-size:32px;display:block;margin-bottom:10px"></i>No rolls selected. Check rolls in the sidebar to preview labels.</div>';
    }

    // Sync sidebar roll items
    document.querySelectorAll('.roll-item').forEach(function(item) {
        var cb = item.querySelector('.roll-cb');
        item.classList.toggle('active', cb && cb.checked);
    });
};

window.selectTemplateById = function(id) {
    activeTemplateId = parseInt(id);
    document.querySelectorAll('.tpl-card').forEach(function(c) {
        c.classList.toggle('active', parseInt(c.dataset.tplId) === activeTemplateId);
    });
    var sel = document.getElementById('tpl-quick-select');
    if (sel) sel.value = activeTemplateId;
    updatePreview();
};

window.toggleAllRolls = function(checked) {
    document.querySelectorAll('.roll-cb').forEach(function(cb) { cb.checked = checked; });
    updatePreview();
};

function showCancelNotice(message) {
    var existing = document.getElementById('label-cancel-notice');
    if (existing) existing.remove();
    var box = document.createElement('div');
    box.id = 'label-cancel-notice';
    box.style.position = 'fixed';
    box.style.top = '14px';
    box.style.right = '14px';
    box.style.zIndex = '99999';
    box.style.background = '#fef3c7';
    box.style.color = '#92400e';
    box.style.border = '1px solid #f59e0b';
    box.style.borderRadius = '10px';
    box.style.padding = '10px 12px';
    box.style.boxShadow = '0 6px 18px rgba(0,0,0,.12)';
    box.style.fontSize = '12px';
    box.style.fontWeight = '700';
    box.textContent = message;
    document.body.appendChild(box);
}

function appendCancelledFlag(urlText) {
    try {
        var u = new URL(urlText, window.location.origin);
        u.searchParams.set('label_cancelled', '1');
        return u.toString();
    } catch (e) {
        return String(baseUrl || '') + '/modules/jobs/printing/index.php?label_cancelled=1';
    }
}

function buildCancelTarget() {
    if (cancelBackUrl) return appendCancelledFlag(cancelBackUrl);

    var ref = String(document.referrer || '').trim();
    if (ref) {
        try {
            var ru = new URL(ref);
            if (ru.origin === window.location.origin) {
                ru.searchParams.set('label_cancelled', '1');
                return ru.toString();
            }
        } catch (e) {}
    }

    return String(baseUrl || '') + '/modules/dashboard/index.php?label_cancelled=1';
}

window.handleLabelCancel = function() {
    var target = buildCancelTarget();
    showCancelNotice('Label printing cancelled. Returning to job card...');

    setTimeout(function() {
        try {
            if (window.opener && !window.opener.closed) {
                // Keep user on the exact opener page unless explicit back_url was provided.
                if (cancelBackUrl) {
                    window.opener.location.href = target;
                }
                try { window.opener.focus(); } catch (ef) {}
                window.close();
                return;
            }
        } catch (e) {}
        window.location.href = target;
    }, 420);
};

// Initial render
selectTemplateById(activeTemplateId);
applyPreviewZoom();

})();
</script>
</body>
</html>
