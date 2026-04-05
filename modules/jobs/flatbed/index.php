<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isOperatorView = (string)($_GET['view'] ?? '') === 'operator';
$canDeleteJobs = isAdmin() && !$isOperatorView;
$dcPageTitleOperator = trim((string)($dcPageTitleOperator ?? '')) ?: 'Die-Cutting Operator';
$dcPageTitleProduction = trim((string)($dcPageTitleProduction ?? '')) ?: 'Die-Cutting Job Cards';
$dcOperatorBreadcrumb = trim((string)($dcOperatorBreadcrumb ?? '')) ?: $dcPageTitleOperator;
$dcProductionBreadcrumb = trim((string)($dcProductionBreadcrumb ?? '')) ?: 'Die-Cutting';
$dcHeaderIcon = trim((string)($dcHeaderIcon ?? '')) ?: 'bi-scissors';
$dcHeaderSubtitle = trim((string)($dcHeaderSubtitle ?? '')) ?: 'Auto-generated for die-cutting after flexo printing &middot; Sequential gating from Flexo Printing';
$dcDocumentTitle = trim((string)($dcDocumentTitle ?? '')) ?: 'Die-Cutting Job Card';
$dcBulkPrintTitle = trim((string)($dcBulkPrintTitle ?? '')) ?: $dcPageTitleProduction;
$dcDetailsSectionLabel = trim((string)($dcDetailsSectionLabel ?? '')) ?: 'Die-Cutting Details';
$dcDefaultFilter = trim((string)($dcDefaultFilter ?? '')) ?: 'Pending';
$dcCompareSectionTitle = trim((string)($dcCompareSectionTitle ?? '')) ?: 'Printing Production vs Plan';
$dcProducedQtyLabel = trim((string)($dcProducedQtyLabel ?? '')) ?: 'Printing Produced';
$dcProducedQtySource = trim((string)($dcProducedQtySource ?? '')) ?: 'previous';
$dcShowWeightHeightFields = isset($dcShowWeightHeightFields) ? (bool)$dcShowWeightHeightFields : false;
$dcWeightLabel = trim((string)($dcWeightLabel ?? '')) ?: 'Weight';
$dcHeightLabel = trim((string)($dcHeightLabel ?? '')) ?: 'Height';
$dcPaperWidthLabel = trim((string)($dcPaperWidthLabel ?? '')) ?: 'Width (mm)';
$dcAutoFallbackToAllOnEmptyDefault = isset($dcAutoFallbackToAllOnEmptyDefault) ? (bool)$dcAutoFallbackToAllOnEmptyDefault : true;

$pageTitle = $isOperatorView ? $dcPageTitleOperator : $dcPageTitleProduction;
$db = getDB();

// ── Company info ──────────────────────────────────────────
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$appFooterLeft = $appSettings['footer_left'] ?? '';
$appFooterRight = $appSettings['footer_right'] ?? '';

// ── Helpers ───────────────────────────────────────────────
function safeCountQueryDC($db, $sql) {
    try { $r = $db->query($sql); return $r ? (int)$r->fetch_row()[0] : 0; } catch (Exception $e) { return 0; }
}

function dcDisplayJobName($j) {
    $d = trim((string)($j['planning_job_name'] ?? ''));
    if ($d !== '') return $d;
    $jn = trim((string)($j['job_no'] ?? ''));
    return $jn !== '' ? $jn : '—';
}

// ── Department filter clause (reusable) ──
$dcWhereClause = trim((string)($dcWhereClauseOverride ?? ''));
if ($dcWhereClause === '') {
  $dcWhereClause = "(
    LOWER(COALESCE(j.department, '')) IN ('flatbed', 'die-cutting', 'die_cutting')
    OR LOWER(COALESCE(j.job_type, '')) IN ('die-cutting', 'diecutting')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) IN ('flatbed', 'die-cutting', 'die_cutting'))
  )";
}

// ── Main SQL ──────────────────────────────────────────────
$jobsSql = "
    SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           ps.status AS roll_status,
           p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
           p.extra_data AS planning_extra_data,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status, prev.extra_data AS prev_extra_data,
           grandprev.job_no AS jumbo_job_no
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    LEFT JOIN jobs grandprev ON prev.previous_job_id = grandprev.id
    WHERE {$dcWhereClause}
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
    ORDER BY j.created_at DESC
    LIMIT 300
";
$jobsRes = $db->query($jobsSql);
$jobs = $jobsRes instanceof mysqli_result ? $jobsRes->fetch_all(MYSQLI_ASSOC) : [];

// ── Process jobs ──────────────────────────────────────────
$finishStates = ['Closed', 'Finalized', 'Completed', 'QC Passed'];
foreach ($jobs as &$job) {
    $job['extra_data_parsed'] = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
    $planningExtra = json_decode((string)($job['planning_extra_data'] ?? '{}'), true) ?: [];
  if ((float)($job['gsm'] ?? 0) <= 0) {
    $parentDetails = is_array($planningExtra['parent_details'] ?? null) ? $planningExtra['parent_details'] : [];
    $parentGsm = (float)($parentDetails['gsm'] ?? 0);
    if ($parentGsm > 0) {
      $job['gsm'] = $parentGsm;
    }
  }
  if ((float)($job['gsm'] ?? 0) <= 0 && !empty($planningExtra['child_rolls']) && is_array($planningExtra['child_rolls'])) {
    foreach ($planningExtra['child_rolls'] as $childRoll) {
      $childGsm = (float)($childRoll['gsm'] ?? 0);
      if ($childGsm > 0) {
        $job['gsm'] = $childGsm;
        break;
      }
    }
  }
  $job['planning_die_size'] = (string)($planningExtra['barcode_size'] ?? ($planningExtra['size'] ?? ($planningExtra['die_size'] ?? '')));
  $sizeWidth = (string)($planningExtra['width_mm'] ?? ($planningExtra['barcode_width'] ?? ($planningExtra['width'] ?? '')));
  $sizeHeight = (string)($planningExtra['height_mm'] ?? ($planningExtra['barcode_height'] ?? ($planningExtra['height'] ?? '')));
  if ($sizeWidth === '' || $sizeHeight === '') {
    $dieSize = (string)$job['planning_die_size'];
    if ($dieSize !== '' && preg_match('/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i', $dieSize, $m)) {
      if ($sizeWidth === '') $sizeWidth = (string)$m[1];
      if ($sizeHeight === '') $sizeHeight = (string)$m[2];
    }
  }
  $job['planning_size_width_mm'] = $sizeWidth;
  $job['planning_size_height_mm'] = $sizeHeight;
  $job['planning_repeat'] = (string)($planningExtra['repeat'] ?? ($planningExtra['barcode_repeat'] ?? ($planningExtra['cylinder_repeat'] ?? ($planningExtra['pitch'] ?? ''))));
  $job['planning_order_qty'] = (string)($planningExtra['order_quantity_user'] ?? ($planningExtra['order_quantity'] ?? ($planningExtra['qty_pcs'] ?? '')));
  $job['planning_material'] = (string)($planningExtra['material_type'] ?? ($planningExtra['material'] ?? ($job['paper_type'] ?? '')));
  $job['planning_client_name'] = (string)($planningExtra['client_name'] ?? ($planningExtra['customer_name'] ?? ($planningExtra['party_name'] ?? '')));
    $imagePath = trim((string)($planningExtra['image_path'] ?? ($planningExtra['planning_image_path'] ?? '')));
    if ($imagePath !== '' && !preg_match('/^https?:\/\//i', $imagePath)) {
        $imagePath = BASE_URL . '/' . ltrim($imagePath, '/');
    }
    $job['planning_image_url'] = $imagePath;
    $job['display_job_name'] = dcDisplayJobName($job);
    $prevStatus = trim((string)($job['prev_job_status'] ?? ''));
    $hasPrev = (int)($job['previous_job_id'] ?? 0) > 0;
    $job['upstream_ready'] = !$hasPrev || in_array($prevStatus, $finishStates, true);

    // ── Parse previous job extra_data (printing production qty) ──
    $prevExtra = json_decode((string)($job['prev_extra_data'] ?? '{}'), true) ?: [];
    $job['prev_actual_qty'] = (string)(
      $prevExtra['actual_qty']
      ?? $prevExtra['production_total_qty']
      ?? $prevExtra['printed_qty']
      ?? $prevExtra['print_qty']
      ?? $prevExtra['total_qty_pcs']
      ?? $prevExtra['die_cutting_total_qty_pcs']
      ?? $prevExtra['dc_total_qty']
      ?? ''
    );
    unset($job['prev_extra_data']); // Don't send raw blob to JS

    // ── Normalize notes_display ──
    $rawNotes = trim((string)($job['notes'] ?? ''));
    $job['notes_display'] = $rawNotes;
    if ($rawNotes !== '' && stripos($rawNotes, 'Die-cutting queued from Flexo') === 0) {
        $planRef = 'N/A';
        if (preg_match('/\|\s*Plan:\s*([^|\n]+)/i', $rawNotes, $m)) {
            $planRef = trim((string)($m[1] ?? '')) ?: 'N/A';
        }
        $jumboRef = trim((string)($job['jumbo_job_no'] ?? ''));
        $flexoRef = trim((string)($job['prev_job_no'] ?? ''));
        $dcRef = trim((string)($job['job_no'] ?? ''));
        $displayName = trim((string)($job['display_job_name'] ?? ''));
        $normalized = 'Die-cutting queued from Flexo | Plan: ' . $planRef
            . ' | ' . ($jumboRef !== '' ? $jumboRef : 'N/A')
            . ' | Flexo: ' . ($flexoRef !== '' ? $flexoRef : 'N/A')
            . ' | Die-Cut: ' . ($dcRef !== '' ? $dcRef : 'N/A');
        if ($displayName !== '') {
            $normalized .= ' | Job name: ' . $displayName;
        }
        $job['notes_display'] = $normalized;
    }
}
unset($job);

$activeJobs = array_values(array_filter($jobs, function ($j) use ($finishStates) {
    return !in_array((string)($j['status'] ?? ''), $finishStates, true);
}));
$historyJobs = array_values(array_filter($jobs, function ($j) use ($finishStates) {
    return in_array((string)($j['status'] ?? ''), $finishStates, true);
}));
$activeCount = count($activeJobs);
$historyCount = count($historyJobs);

// ── Stat counts ───────────────────────────────────────────
$dcCountBase = "SELECT COUNT(*) FROM jobs j WHERE {$dcWhereClause} AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')";
$totalCount   = safeCountQueryDC($db, $dcCountBase);
$queuedCount  = safeCountQueryDC($db, $dcCountBase . " AND j.status = 'Queued'");
$pendingCount = safeCountQueryDC($db, $dcCountBase . " AND j.status = 'Pending'");
$runningCount = safeCountQueryDC($db, $dcCountBase . " AND j.status = 'Running'");
$holdCount    = safeCountQueryDC($db, $dcCountBase . " AND j.status IN ('Hold','Hold for Payment','Hold for Approval')");
$finishedCount = safeCountQueryDC($db, $dcCountBase . " AND j.status IN ('Closed','Finalized','Completed','QC Passed')");

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <?php if ($isOperatorView): ?>
    <span>Operator</span><span class="breadcrumb-sep">&#8250;</span>
    <span>Machine Operators</span><span class="breadcrumb-sep">&#8250;</span>
    <span><?= e($dcOperatorBreadcrumb) ?></span>
  <?php else: ?>
    <span>Production</span><span class="breadcrumb-sep">&#8250;</span>
    <span>Job Cards</span><span class="breadcrumb-sep">&#8250;</span>
    <span><?= e($dcProductionBreadcrumb) ?></span>
  <?php endif; ?>
</div>

<style>
:root { --dc-brand: #0ea5a4; --dc-brand-light: #ccfbf1; --dc-brand-dark: #0f766e; --dc-blue: #3b82f6; }

/* ── Stats Grid ── */
.dc-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px}
.dc-stat{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;cursor:pointer;transition:all .18s;position:relative;overflow:hidden}
.dc-stat:hover{border-color:var(--dc-brand);box-shadow:0 4px 12px rgba(14,165,164,.12)}
.dc-stat.active{border-color:var(--dc-brand);box-shadow:0 4px 16px rgba(14,165,164,.18)}
.dc-stat.active::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--dc-brand);border-radius:0 0 14px 14px}
.dc-stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.dc-stat-val{font-size:1.32rem;font-weight:900;color:#0f172a;line-height:1}
.dc-stat-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-top:2px}

/* ── Tabs ── */
.dc-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e2e8f0;padding-bottom:0}
.dc-tab-btn{padding:10px 20px;font-size:.8rem;font-weight:800;color:#64748b;background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.04em}
.dc-tab-btn:hover{color:var(--dc-brand)}
.dc-tab-btn.active{color:var(--dc-brand);border-bottom-color:var(--dc-brand)}
.dc-tab-count{font-size:.62rem;font-weight:900;background:var(--dc-brand-light);color:var(--dc-brand-dark);padding:2px 7px;border-radius:10px;margin-left:6px}

/* ── Filters ── */
.dc-filters{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.dc-search{padding:8px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:.82rem;min-width:220px;outline:none;transition:border .15s}
.dc-search:focus{border-color:var(--dc-brand)}
.dc-filter-btn{padding:6px 14px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid #e2e8f0;background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.dc-filter-btn:hover{border-color:var(--dc-brand);color:var(--dc-brand)}
.dc-filter-btn.active{background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)}

/* ── Card Grid ── */
.dc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.dc-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;transition:all .15s;cursor:pointer;position:relative}
.dc-card:hover{border-color:var(--dc-brand);box-shadow:0 4px 16px rgba(14,165,164,.1)}
.dc-card.dc-queued{opacity:.78;border-left:4px solid #94a3b8}
.dc-card.dc-selected{outline:2px solid var(--dc-brand);outline-offset:-2px;background:#f0fdfa}
.dc-select-check{position:absolute;top:12px;right:12px;width:18px;height:18px;accent-color:var(--dc-brand);cursor:pointer;z-index:2}
.dc-card-head{padding:12px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px}
.dc-jobno{font-size:.82rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px}
.dc-card-body{padding:12px 14px;display:grid;gap:6px}
.dc-card-row{display:flex;justify-content:space-between;gap:8px;font-size:.78rem}
.dc-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em}
.dc-value{font-weight:700;color:#0f172a;text-align:right}
.dc-card-foot{padding:10px 14px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center}
.dc-time{font-size:.68rem;color:#94a3b8}

/* ── Badges ── */
.dc-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.dc-badge-queued{background:#f1f5f9;color:#64748b}
.dc-badge-pending{background:#fef3c7;color:#92400e}
.dc-badge-running{background:#dbeafe;color:#1d4ed8}
.dc-badge-completed{background:#dcfce7;color:#166534}
.dc-badge-hold{background:#fecdd3;color:#991b1b}
.dc-badge-urgent{background:#fee2e2;color:#991b1b}
.dc-badge-high{background:#fff7ed;color:#9a3412}
.dc-badge-normal{background:#f1f5f9;color:#475569}

/* ── Action Buttons ── */
.dc-action-btn{padding:6px 12px;font-size:.68rem;font-weight:800;border:1px solid #e2e8f0;background:#fff;border-radius:8px;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:5px}
.dc-action-btn:hover{background:#f8fafc}
.dc-btn-start{color:var(--dc-brand);border-color:var(--dc-brand)}
.dc-btn-start:hover{background:var(--dc-brand-light)}
.dc-btn-complete{color:#16a34a;border-color:#86efac}
.dc-btn-complete:hover{background:#f0fdf4}
.dc-btn-view{color:#475569;border-color:#e2e8f0}
.dc-btn-view:hover{background:#f1f5f9}
.dc-btn-print{color:#8b5cf6;border-color:#c4b5fd}
.dc-btn-print:hover{background:#f5f3ff}
.dc-btn-delete{color:#dc2626;border-color:#fecaca}
.dc-btn-delete:hover{background:#fee2e2}

/* ── Gate (upstream lock) ── */
.dc-gate{font-size:.68rem;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:5px 8px;margin:4px 14px 8px}

/* ── Empty ── */
.dc-empty{padding:48px 16px;text-align:center;color:#94a3b8;grid-column:1/-1}
.dc-empty i{font-size:2.5rem;opacity:.3;display:block;margin-bottom:8px}

/* ── Bulk Bar ── */
.dc-bulk-bar{display:none;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border-radius:14px;padding:14px 20px;margin-bottom:14px;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(14,165,164,.25)}

/* ── Modal ── */
.dc-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2100;display:flex;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto;opacity:0;pointer-events:none;transition:opacity .2s}
.dc-modal-overlay.active{opacity:1;pointer-events:auto}
.dc-modal{width:100%;max-width:900px;background:#fff;border-radius:16px;border:2px solid var(--dc-brand-light);box-shadow:0 20px 60px rgba(0,0,0,.18);overflow:hidden}
.dc-modal-header{padding:14px 18px;border-bottom:2px solid var(--dc-brand-light);background:linear-gradient(135deg,#f0fdfa,#ccfbf1);display:flex;justify-content:space-between;align-items:center}
.dc-modal-header h2{margin:0;font-size:1rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.dc-modal-body{padding:18px;display:grid;gap:14px}
.dc-modal-footer{padding:14px 18px;border-top:2px solid var(--dc-brand-light);background:#f8fafc;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}

/* ── Operator Sections ── */
.dc-op-form{display:grid;gap:14px}
.dc-op-section{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}
.dc-op-h{padding:10px 14px;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:var(--dc-brand-dark);background:var(--dc-brand-light);display:flex;align-items:center;gap:8px}
.dc-op-b{padding:12px 14px}
.dc-op-topstrip{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:10px}
.dc-op-topitem{display:flex;flex-direction:column;gap:2px}
.dc-op-topitem .k{font-size:.58rem;font-weight:800;text-transform:uppercase;color:#94a3b8}
.dc-op-topitem .v{font-size:.82rem;font-weight:700;color:#0f172a}
.dc-op-grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.dc-op-field label{display:block;font-size:.6rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:4px;letter-spacing:.04em}
.dc-op-field .fv{font-size:.82rem;font-weight:700;color:#0f172a;padding:6px 0}
.dc-op-field input,.dc-op-field select,.dc-op-field textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.82rem;outline:none;transition:border .15s}
.dc-op-field input:focus,.dc-op-field select:focus,.dc-op-field textarea:focus{border-color:var(--dc-brand)}
.dc-op-field textarea{min-height:80px;resize:vertical}

/* ── Timer Overlay ── */
.dc-timer-overlay{position:fixed;inset:0;z-index:9999;background:linear-gradient(135deg,#042f2e,#134e4a 30%,#0f766e 60%,#14b8a6);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;color:#fff;font-family:'Segoe UI',Arial,sans-serif;padding:24px 16px calc(24px + env(safe-area-inset-bottom));overflow:auto}
.dc-timer-jobinfo{text-align:center;opacity:.9}
.dc-timer-display{font-size:clamp(2.1rem,10vw,4.5rem);font-weight:900;letter-spacing:.12em;font-family:Consolas,'Courier New',monospace;text-shadow:0 4px 20px rgba(0,0,0,.3);text-align:center;line-height:1.1;word-break:break-word}
.dc-timer-actions{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;width:100%;max-width:760px}
.dc-timer-btn-cancel{padding:14px 32px;font-size:1rem;font-weight:800;border:2px solid rgba(255,255,255,.3);background:rgba(255,255,255,.08);color:#fff;border-radius:14px;cursor:pointer;transition:all .15s}
.dc-timer-btn-cancel:hover{background:rgba(255,255,255,.15)}
.dc-timer-btn-pause{padding:14px 32px;font-size:1rem;font-weight:800;border:none;background:#f59e0b;color:#fff;border-radius:14px;cursor:pointer;transition:all .15s;box-shadow:0 4px 16px rgba(245,158,11,.4)}
.dc-timer-btn-pause:hover{background:#d97706}
.dc-timer-btn-end{padding:14px 32px;font-size:1rem;font-weight:800;border:none;background:#ef4444;color:#fff;border-radius:14px;cursor:pointer;transition:all .15s;box-shadow:0 4px 16px rgba(239,68,68,.4)}
.dc-timer-btn-end:hover{background:#dc2626}

/* ── Upload Zone ── */
.dc-upload-zone{border:2px dashed #cbd5e1;border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:all .15s}
.dc-upload-zone:hover{border-color:var(--dc-brand);background:#f0fdfa}
.dc-upload-zone input[type=file]{display:none}
.dc-upload-preview img{max-width:260px;max-height:160px;border-radius:8px;border:1px solid #e2e8f0;margin-top:8px}

/* ── Timer History ── */
.dc-timer-history{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.dc-timer-history-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
.dc-timer-history-card h4{margin:0 0 8px;font-size:.68rem;font-weight:900;text-transform:uppercase;color:#475569;letter-spacing:.05em}
.dc-timer-history-list{display:grid;gap:4px}
.dc-timer-history-row{display:flex;gap:10px;font-size:.72rem;padding:4px 0;border-bottom:1px solid #f1f5f9}
.dc-timer-history-row .k{font-weight:800;color:#475569;min-width:56px}
.dc-timer-history-row .v{color:#0f172a}
.dc-timer-history-row.pause .v{color:#b45309}
.dc-timer-history-empty{font-size:.72rem;color:#94a3b8;padding:6px 0}

/* ── Preview Image ── */
.dc-preview{max-width:280px;max-height:180px;border:1px solid #e2e8f0;border-radius:10px;object-fit:contain;background:#fff}

/* ── Print ── */
.dc-print-area{display:none}
@media print{.no-print{display:none!important}.dc-print-area{display:block}}

/* ── Responsive ── */
@media(max-width:900px){.dc-stats{grid-template-columns:repeat(3,1fr)}.dc-op-grid-2{grid-template-columns:1fr}.dc-timer-history{grid-template-columns:1fr}}
@media(max-width:640px){.dc-stats{grid-template-columns:repeat(2,1fr)}.dc-grid{grid-template-columns:1fr}}
@media(max-width:640px){.dc-timer-overlay{justify-content:flex-start;gap:16px;padding:max(16px, env(safe-area-inset-top)) 14px max(14px, env(safe-area-inset-bottom))}.dc-timer-jobinfo{max-width:100%}.dc-timer-display{letter-spacing:.06em}.dc-timer-actions{flex-direction:column;gap:10px;max-width:420px}.dc-timer-btn-cancel,.dc-timer-btn-pause,.dc-timer-btn-end{width:100%;padding:12px 14px;font-size:.95rem}}

/* ── History Table Styles ── */
.ht-filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.ht-search{padding:8px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:.82rem;min-width:200px;outline:none;transition:border .15s}
.ht-search:focus{border-color:var(--dc-brand)}
.ht-date-input{padding:7px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:.76rem;outline:none}
.ht-date-input:focus{border-color:var(--dc-brand)}
.ht-period-btn{padding:5px 13px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid #e2e8f0;background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.ht-period-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.ht-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.ht-bulk-bar{display:none;background:linear-gradient(135deg,#0d9488,#0f766e);color:#fff;border-radius:12px;padding:12px 20px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(14,165,164,.25)}
.ht-bulk-bar.visible{display:flex}
.ht-bulk-btn{padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff}
.ht-bulk-btn:hover{background:rgba(255,255,255,.22)}
.ht-bulk-print{padding:7px 16px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(34,197,94,.3)}
.ht-bulk-print:hover{background:#16a34a}
.ht-bulk-delete{padding:7px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(220,38,38,.3)}
.ht-bulk-delete:hover{background:#b91c1c}
.ht-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.ht-table{width:100%;border-collapse:collapse;font-size:.78rem}
.ht-table thead{background:linear-gradient(135deg,#f8fafc,#f1f5f9);position:sticky;top:0;z-index:2}
.ht-table th{padding:11px 13px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;border-bottom:2px solid #e2e8f0;white-space:nowrap;cursor:pointer;user-select:none}
.ht-table th:hover{color:#0f172a}
.ht-table th .ht-sort{margin-left:3px;font-size:.52rem;opacity:.4}
.ht-table th.sorted .ht-sort{opacity:1;color:var(--dc-brand)}
.ht-table td{padding:9px 13px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-weight:600;vertical-align:middle}
.ht-table tbody tr{transition:background .1s;cursor:pointer}
.ht-table tbody tr:hover{background:#f0fdfa}
.ht-table tbody tr.ht-selected{background:#f0fdfa;outline:2px solid var(--dc-brand);outline-offset:-2px}
.ht-table .ht-cb-cell{width:34px;text-align:center}
.ht-table .ht-cb-cell input{width:16px;height:16px;accent-color:var(--dc-brand);cursor:pointer}
.ht-jobno{font-weight:900;color:#0f172a;font-size:.8rem}
.ht-dim{color:#94a3b8;font-size:.72rem}
.ht-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.ht-badge-completed{background:#dcfce7;color:#166534}
.ht-badge-closed{background:#dcfce7;color:#166534}
.ht-badge-finalized{background:#dbeafe;color:#1e40af}
.ht-badge-default{background:#f1f5f9;color:#64748b}
.ht-act-btn{padding:4px 9px;font-size:.58rem;font-weight:800;text-transform:uppercase;border:1px solid #e2e8f0;background:#fff;border-radius:6px;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:3px;color:#475569}
.ht-act-btn:hover{background:#f1f5f9}
.ht-act-btn.ht-print{color:#8b5cf6;border-color:#c4b5fd}
.ht-act-btn.ht-print:hover{background:#f5f3ff}
.ht-act-btn.ht-delete{color:#dc2626;border-color:#fecaca}
.ht-act-btn.ht-delete:hover{background:#fee2e2}
.ht-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;flex-wrap:wrap;gap:10px}
.ht-page-info{font-size:.7rem;color:#64748b;font-weight:600}
.ht-page-btns{display:flex;gap:4px}
.ht-page-btn{padding:5px 11px;border:1px solid #e2e8f0;background:#fff;border-radius:8px;font-size:.7rem;font-weight:700;cursor:pointer;color:#475569;transition:all .12s}
.ht-page-btn:hover{background:#f1f5f9}
.ht-page-btn.active{background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)}
.ht-page-btn:disabled{opacity:.4;cursor:not-allowed}
.ht-per-page{padding:5px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.7rem;outline:none}
@media(max-width:768px){.ht-table-wrap{overflow-x:auto}}
</style>

<!-- ═══ HEADER ═══ -->
<div class="dc-header no-print" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div>
    <h1 style="margin:0;font-size:1.3rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px">
      <i class="bi <?= e($dcHeaderIcon) ?>"></i> <?= e($isOperatorView ? $dcPageTitleOperator : $dcPageTitleProduction) ?>
    </h1>
    <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;font-weight:600">
      <?= $dcHeaderSubtitle ?>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="dc-action-btn dc-btn-view" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<!-- ═══ BULK PRINT BAR ═══ -->
<div class="dc-bulk-bar no-print" id="dcBulkBar">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-check2-square" style="font-size:1.1rem"></i>
    <span style="font-weight:800;font-size:.82rem"><span id="dcSelectedCount">0</span> Selected</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button style="padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff" onclick="dcSelectAll()">Select All</button>
    <button style="padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff" onclick="dcDeselectAll()">Deselect All</button>
    <button style="padding:7px 16px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px" onclick="dcBulkPrint()"><i class="bi bi-printer-fill"></i> Print Selected</button>
  </div>
</div>

<!-- ═══ STATS GRID ═══ -->
<div class="dc-stats no-print">
  <div class="dc-stat" data-filter="all" onclick="filterFromStat('all')">
    <div class="dc-stat-icon" style="background:var(--dc-brand-light);color:var(--dc-brand)"><i class="bi bi-stack"></i></div>
    <div><div class="dc-stat-val"><?= $totalCount ?></div><div class="dc-stat-label">Total</div></div>
  </div>
  <div class="dc-stat" data-filter="Queued" onclick="filterFromStat('Queued')">
    <div class="dc-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="dc-stat-val"><?= $queuedCount ?></div><div class="dc-stat-label">Queued</div></div>
  </div>
  <div class="dc-stat" data-filter="Pending" onclick="filterFromStat('Pending')">
    <div class="dc-stat-icon" style="background:#fef3c7;color:#d97706"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="dc-stat-val"><?= $pendingCount ?></div><div class="dc-stat-label">Pending</div></div>
  </div>
  <div class="dc-stat" data-filter="Running" onclick="filterFromStat('Running')">
    <div class="dc-stat-icon" style="background:#e0e7ff;color:#6366f1"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="dc-stat-val"><?= $runningCount ?></div><div class="dc-stat-label">Running</div></div>
  </div>
  <div class="dc-stat" data-filter="Hold" onclick="filterFromStat('Hold')">
    <div class="dc-stat-icon" style="background:#fecdd3;color:#dc2626"><i class="bi bi-pause-circle-fill"></i></div>
    <div><div class="dc-stat-val"><?= $holdCount ?></div><div class="dc-stat-label">Hold</div></div>
  </div>
  <div class="dc-stat" data-filter="Finished" onclick="filterFromStat('Finished')">
    <div class="dc-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle"></i></div>
    <div><div class="dc-stat-val"><?= $finishedCount ?></div><div class="dc-stat-label">Finished</div></div>
  </div>
</div>

<!-- ═══ TABS ═══ -->
<div class="dc-tabs no-print">
  <button id="dcTabBtnActive" class="dc-tab-btn active" type="button" onclick="switchDCTab('active')">Job Details <span class="dc-tab-count"><?= $activeCount ?></span></button>
  <button id="dcTabBtnHistory" class="dc-tab-btn" type="button" onclick="switchDCTab('history')">History <span class="dc-tab-count"><?= $historyCount ?></span></button>
</div>

<!-- ═══ ACTIVE PANEL ═══ -->
<div id="dcPanelActive">

<div class="dc-filters no-print">
  <input type="text" class="dc-search" id="dcSearch" placeholder="Search by job no, name, material&hellip;">
  <button class="dc-filter-btn" onclick="filterJobs('all',this)">All</button>
  <button class="dc-filter-btn" onclick="filterJobs('Queued',this)">Queued</button>
  <button class="dc-filter-btn" onclick="filterJobs('Pending',this)">Pending</button>
  <button class="dc-filter-btn" onclick="filterJobs('Running',this)">Running</button>
  <button class="dc-filter-btn" onclick="filterJobs('Hold',this)">Hold</button>
  <button class="dc-filter-btn" onclick="filterJobs('Finished',this)">Finished</button>
</div>

<div class="dc-grid no-print" id="dcGrid">
<?php if (empty($activeJobs) && empty($historyJobs)): ?>
  <div class="dc-empty">
    <i class="bi bi-inbox"></i>
    <p>No active die-cutting jobs.</p>
  </div>
<?php else: ?>
  <?php foreach ($activeJobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Queued'=>'queued', 'Pending'=>'pending', 'Running'=>'running', 'Closed','Finalized','Completed'=>'completed', default=>'pending' };
    $timerActive = !empty($job['extra_data_parsed']['timer_active']);
    $timerState = strtolower(trim((string)($job['extra_data_parsed']['timer_state'] ?? '')));
    $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0;
    $resumedTs = !empty($job['extra_data_parsed']['timer_last_resumed_at']) ? (strtotime($job['extra_data_parsed']['timer_last_resumed_at']) * 1000) : $startedTs;
    $baseSeconds = (int)round((float)($job['extra_data_parsed']['timer_accumulated_seconds'] ?? 0));
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $isLocked = !$job['upstream_ready'];
    $isQueued = ($sts === 'Queued');
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedAt = $job['started_at'] ? date('d M Y, H:i', strtotime($job['started_at'])) : '—';
    $completedAt = $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['display_job_name'] ?? '') . ' ' . ($job['planning_material'] ?? '') . ' ' . ($job['planning_die_size'] ?? ''));
  ?>
  <div class="dc-card <?= $isQueued ? 'dc-queued' : '' ?>" data-status="<?= e($sts) ?>" data-lockstate="<?= $isLocked ? 'locked' : 'unlocked' ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openJobDetail(<?= $job['id'] ?>)">
    <input type="checkbox" class="dc-select-check" data-job-id="<?= $job['id'] ?>" onclick="event.stopPropagation();dcUpdateBulkBar()" title="Select for bulk print">
    <div class="dc-card-head">
      <div class="dc-jobno"><i class="bi bi-scissors"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="dc-badge dc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="dc-badge dc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="dc-card-body">
      <div class="dc-card-row"><span class="dc-label">Job Name</span><span class="dc-value"><?= e($job['display_job_name']) ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Material</span><span class="dc-value"><?= e($job['planning_material'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Die Size</span><span class="dc-value" style="color:var(--dc-brand)"><?= e($job['planning_die_size'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Order Qty (Pcs)</span><span class="dc-value"><?= e($job['planning_order_qty'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Total Length (Mtr)</span><span class="dc-value"><?= e($job['length_mtr'] ?? '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Started</span><span class="dc-value"><?= e($startedAt) ?></span></div>
      <?php if ($sts === 'Running' && $resumedTs && $timerState !== 'paused'): ?>
      <div class="dc-card-row"><span class="dc-label">Elapsed</span><span class="dc-timer" data-base-seconds="<?= $baseSeconds ?>" data-resumed-at="<?= $resumedTs ?>" style="color:var(--dc-brand);font-weight:700;font-family:Consolas,monospace">00:00:00</span></div>
      <?php elseif ($sts === 'Running' && $timerState === 'paused'): ?>
      <div class="dc-card-row"><span class="dc-label">Timer</span><span class="dc-value" style="color:#f59e0b;font-weight:800"><i class="bi bi-pause-circle-fill"></i> Paused</span></div>
      <?php endif; ?>
      <?php if ($isLocked): ?>
      <div class="dc-gate"><i class="bi bi-lock-fill"></i> Upstream job not done yet. Prev: <?= e($job['prev_job_no'] ?: 'N/A') ?></div>
      <?php endif; ?>
    </div>
    <div class="dc-card-foot">
      <div class="dc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <?php if ($sts === 'Pending' && $isOperatorView && !$isLocked): ?>
          <button class="dc-action-btn dc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
        <?php elseif ($sts === 'Running' && $isOperatorView): ?>
          <?php if ($timerState === 'paused'): ?>
            <button class="dc-action-btn dc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-play-circle"></i> Again Start</button>
          <?php elseif ($timerActive): ?>
            <button class="dc-action-btn dc-btn-start" onclick="resumeRunningDCTimer(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-play-circle"></i> Open Timer</button>
          <?php else: ?>
            <button class="dc-action-btn dc-btn-complete" onclick="openJobDetail(<?= $job['id'] ?>,'complete');event.stopPropagation()"><i class="bi bi-check-lg"></i> Complete</button>
          <?php endif; ?>
        <?php elseif (!$isOperatorView): ?>
          <button class="dc-action-btn dc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
        <?php endif; ?>
        <button class="dc-action-btn dc-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($historyJobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Pending'=>'pending', 'Closed','Finalized'=>'completed', default=>'pending' };
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedAt = $job['started_at'] ? date('d M Y, H:i', strtotime($job['started_at'])) : '—';
    $completedAt = $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['display_job_name'] ?? '') . ' ' . ($job['planning_material'] ?? '') . ' ' . ($job['planning_die_size'] ?? ''));
  ?>
  <div class="dc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" data-finished-only="1" style="display:none" onclick="openJobDetail(<?= $job['id'] ?>)">
    <input type="checkbox" class="dc-select-check" data-job-id="<?= $job['id'] ?>" onclick="event.stopPropagation();dcUpdateBulkBar()" title="Select for bulk print">
    <div class="dc-card-head">
      <div class="dc-jobno"><i class="bi bi-scissors"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="dc-badge dc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="dc-badge dc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="dc-card-body">
      <div class="dc-card-row"><span class="dc-label">Job Name</span><span class="dc-value"><?= e($job['display_job_name']) ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Material</span><span class="dc-value"><?= e($job['planning_material'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Die Size</span><span class="dc-value" style="color:var(--dc-brand)"><?= e($job['planning_die_size'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Order Qty</span><span class="dc-value"><?= e($job['planning_order_qty'] ?: '—') ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Started</span><span class="dc-value"><?= e($startedAt) ?></span></div>
      <div class="dc-card-row"><span class="dc-label">Completed</span><span class="dc-value"><?= e($completedAt) ?></span></div>
    </div>
    <div class="dc-card-foot">
      <div class="dc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
        <?php if (!$isOperatorView): ?>
        <button class="dc-action-btn dc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
        <?php endif; ?>
        <button class="dc-action-btn dc-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<!-- ═══ HISTORY PANEL ═══ -->
<div id="dcPanelHistory" style="display:none">

<!-- History Filter Bar -->
<div class="ht-filter-bar no-print" style="margin-top:14px">
  <input type="text" class="ht-search" id="htSearch" placeholder="Search job no, name, material&hellip;">
  <span class="ht-label">Period:</span>
  <button class="ht-period-btn active" onclick="htSetPeriod('all',this)">All</button>
  <button class="ht-period-btn" onclick="htSetPeriod('today',this)">Today</button>
  <button class="ht-period-btn" onclick="htSetPeriod('week',this)">Week</button>
  <button class="ht-period-btn" onclick="htSetPeriod('month',this)">Month</button>
  <button class="ht-period-btn" onclick="htSetPeriod('year',this)">Year</button>
  <span class="ht-label" style="margin-left:4px">Custom:</span>
  <input type="date" class="ht-date-input" id="htDateFrom" title="From date">
  <span style="color:#94a3b8;font-size:.7rem">to</span>
  <input type="date" class="ht-date-input" id="htDateTo" title="To date">
  <button class="ht-period-btn" onclick="htApplyCustomDate()" style="background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)"><i class="bi bi-funnel"></i> Apply</button>
</div>

<!-- History Bulk Bar -->
<div class="ht-bulk-bar no-print" id="htBulkBar">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-check2-square" style="font-size:1.1rem"></i>
    <span style="font-weight:800;font-size:.82rem"><span id="htSelectedCount">0</span> Selected</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="ht-bulk-btn" onclick="htSelectAllVisible()">Select All</button>
    <button class="ht-bulk-btn" onclick="htDeselectAll()">Deselect All</button>
    <button class="ht-bulk-print" onclick="htBulkPrint()"><i class="bi bi-printer-fill"></i> Print Selected</button>
    <?php if ($canDeleteJobs): ?>
    <button class="ht-bulk-delete" onclick="htBulkDelete()"><i class="bi bi-trash-fill"></i> Delete Selected</button>
    <?php endif; ?>
  </div>
</div>

<!-- History Table -->
<div class="ht-table-wrap">
  <table class="ht-table" id="htTable">
    <thead>
      <tr>
        <th class="ht-cb-cell no-print"><input type="checkbox" id="htCheckAll" onchange="htToggleAll(this.checked)" title="Select all"></th>
        <th onclick="htSortCol(0)">#<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(1)">Job No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(2)">Job Name<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(3)">Material<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(4)">Die Size<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(5)">Order Qty<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(6)">Status<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(7)">Started<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(8)">Completed<span class="ht-sort">▲▼</span></th>
        <th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody id="htBody">
    <?php if (empty($historyJobs)): ?>
      <tr><td colspan="12" style="padding:40px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i><br>No finished die-cutting jobs found</td></tr>
    <?php else: ?>
      <?php foreach ($historyJobs as $idx => $h):
        $hSts = $h['status'];
        $hStsLower = strtolower(str_replace(' ', '', $hSts));
        $hStsClass = match($hStsLower) { 'closed'=>'closed','finalized'=>'finalized','completed'=>'completed', default=>'default' };
        $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
        $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : ($h['updated_at'] ? date('d M Y, H:i', strtotime($h['updated_at'])) : '—');
        $hSearch = strtolower($h['job_no'] . ' ' . ($h['display_job_name'] ?? '') . ' ' . ($h['planning_material'] ?? '') . ' ' . ($h['planning_die_size'] ?? ''));
      ?>
      <tr data-id="<?= (int)$h['id'] ?>"
          data-completed="<?= e($h['completed_at'] ?? $h['updated_at'] ?? $h['created_at'] ?? '') ?>"
          data-search="<?= e($hSearch) ?>"
          onclick="openJobDetail(<?= (int)$h['id'] ?>)">
        <td class="ht-cb-cell no-print">
          <input type="checkbox" class="ht-row-cb" data-job-id="<?= (int)$h['id'] ?>" onclick="event.stopPropagation();htUpdateBulk()">
        </td>
        <td class="ht-dim"><?= $idx + 1 ?></td>
        <td><span class="ht-jobno"><?= e($h['job_no']) ?></span></td>
        <td><?= e($h['display_job_name'] ?? '—') ?></td>
        <td><?= e($h['planning_material'] ?? '—') ?></td>
        <td style="color:var(--dc-brand);font-weight:800"><?= e($h['planning_die_size'] ?? '—') ?></td>
        <td><?= e($h['planning_order_qty'] ?? '—') ?></td>
        <td><span class="ht-badge ht-badge-<?= $hStsClass ?>"><?= e($hSts) ?></span></td>
        <td class="ht-dim"><?= $hStarted ?></td>
        <td class="ht-dim"><?= $hCompleted ?></td>
        <td class="no-print" onclick="event.stopPropagation()">
          <button class="ht-act-btn" onclick="openJobDetail(<?= (int)$h['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
          <button class="ht-act-btn ht-print" onclick="printJobCard(<?= (int)$h['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
          <?php if ($canDeleteJobs): ?>
          <button class="ht-act-btn ht-delete" onclick="deleteJob(<?= (int)$h['id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="ht-pagination no-print" id="htPagination">
    <div class="ht-page-info" id="htPageInfo">Showing 0-0 of 0</div>
    <div style="display:flex;align-items:center;gap:10px">
      <select class="ht-per-page" id="htPerPage" onchange="htGoPage(1)">
        <option value="25">25 / page</option>
        <option value="50">50 / page</option>
        <option value="100">100 / page</option>
        <option value="all">Show All</option>
      </select>
      <div class="ht-page-btns" id="htPageBtns"></div>
    </div>
  </div>
</div>

</div>

<!-- ═══ DETAIL MODAL ═══ -->
<div class="dc-modal-overlay" id="dcDetailModal">
  <div class="dc-modal">
    <div class="dc-modal-header">
      <h2><i class="bi bi-scissors"></i> <span id="dm-jobno"></span></h2>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="dm-status-badge" class="dc-badge"></span>
        <button class="dc-action-btn dc-btn-view" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div class="dc-modal-body" id="dm-body"></div>
    <div class="dc-modal-footer" id="dm-footer"></div>
  </div>
</div>

<!-- ═══ PRINT AREA ═══ -->
<div class="dc-print-area" id="dcPrintArea"></div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const BASE_URL = '<?= BASE_URL ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const APP_FOOTER_LEFT = <?= json_encode($appFooterLeft, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const APP_FOOTER_RIGHT = <?= json_encode($appFooterRight, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode(array_values(array_merge($activeJobs, $historyJobs)), JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const IS_OPERATOR_VIEW = <?= $isOperatorView ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $canDeleteJobs ? 'true' : 'false' ?>;
const DC_AUTO_REFRESH_MS = 45000;
const DC_DETAILS_SECTION_LABEL = <?= json_encode($dcDetailsSectionLabel, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_DEFAULT_FILTER_RAW = <?= json_encode($dcDefaultFilter, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_COMPARE_SECTION_TITLE = <?= json_encode($dcCompareSectionTitle, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_PRODUCED_QTY_LABEL = <?= json_encode($dcProducedQtyLabel, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_PRODUCED_QTY_SOURCE = <?= json_encode($dcProducedQtySource, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_SHOW_WEIGHT_HEIGHT_FIELDS = <?= $dcShowWeightHeightFields ? 'true' : 'false' ?>;
const DC_WEIGHT_LABEL = <?= json_encode($dcWeightLabel, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_HEIGHT_LABEL = <?= json_encode($dcHeightLabel, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_PAPER_WIDTH_LABEL = <?= json_encode($dcPaperWidthLabel, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DC_AUTO_FALLBACK_TO_ALL_ON_EMPTY_DEFAULT = <?= $dcAutoFallbackToAllOnEmptyDefault ? 'true' : 'false' ?>;

// ── Voice recording state ──
let _voiceRecorder = null;
let _voiceChunks = [];
let _lastPhotoPath = '';
let _lastVoicePath = '';

function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }

function resolveJobDisplayName(job) {
  if (job && String(job.display_job_name || '').trim() !== '') return String(job.display_job_name).trim();
  if (job && String(job.planning_job_name || '').trim() !== '') return String(job.planning_job_name).trim();
  const jobNo = String(job?.job_no || '').trim();
  return jobNo || '—';
}

function normalizeDimensionValue(value) {
  const raw = String(value ?? '').trim();
  if (!raw) return '';
  const num = raw.match(/^([0-9]+(?:\.[0-9]+)?)/);
  if (num) return num[1];
  return raw.replace(/mm/ig, '').trim();
}

function getJobSizeDimensions(job) {
  let weight = normalizeDimensionValue(job?.planning_size_width_mm ?? job?.planning_width_mm ?? '');
  let height = normalizeDimensionValue(job?.planning_size_height_mm ?? job?.planning_height_mm ?? '');
  if (!weight || !height) {
    const dieSize = String(job?.planning_die_size || '').trim();
    const m = dieSize.match(/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i);
    if (m) {
      if (!weight) weight = m[1];
      if (!height) height = m[2];
    }
  }
  return { weight, height };
}

// ═══ TAB SWITCHING ═══
function switchDCTab(tab) {
  const panels = { active: document.getElementById('dcPanelActive'), history: document.getElementById('dcPanelHistory') };
  const btns = { active: document.getElementById('dcTabBtnActive'), history: document.getElementById('dcTabBtnHistory') };
  Object.keys(panels).forEach(function(k) {
    if (panels[k]) panels[k].style.display = (k === tab) ? '' : 'none';
    if (btns[k]) btns[k].classList.toggle('active', k === tab);
  });
  if (tab === 'history') htGoPage(1);
}

// ═══ FILTERS ═══
let ACTIVE_DC_FILTER = (DC_DEFAULT_FILTER_RAW || 'Pending');

function normalizeFilterStatus(status) {
  const s = String(status || '').trim().toLowerCase();
  if (!s) return 'Pending';
  if (s === 'all') return 'all';
  if (s === 'pending') return 'Pending';
  if (s === 'queued') return 'Queued';
  if (s === 'running') return 'Running';
  if (s === 'hold') return 'Hold';
  if (s === 'finished' || s === 'completed' || s === 'closed' || s === 'finalized' || s === 'qc passed') return 'Finished';
  return status;
}

function findFilterButton(status) {
  const normalized = normalizeFilterStatus(status);
  return Array.from(document.querySelectorAll('.dc-filter-btn')).find(function(btn) {
    return btn.textContent.trim().toLowerCase() === String(normalized).toLowerCase();
  }) || null;
}

function getVisibleActiveCardCount() {
  return Array.from(document.querySelectorAll('.dc-card:not([data-finished-only="1"])')).filter(function(card) {
    return card.style.display !== 'none';
  }).length;
}

function dcCardMatchesFilter(card, status) {
  const cardStatus = (card.dataset.status || '').toLowerCase();
  const finishedOnly = card.dataset.finishedOnly === '1';
  const lockState = String(card.dataset.lockstate || '').toLowerCase();

  if (status === 'all') return !finishedOnly;
  if (status === 'Finished') return ['finished', 'completed', 'closed', 'finalized', 'qc passed'].includes(cardStatus);
  if (finishedOnly) return false;
  if (status === 'Hold') return cardStatus === 'hold' || cardStatus === 'hold for payment' || cardStatus === 'hold for approval';
  if (status === 'Queued') return cardStatus === 'queued' || lockState === 'locked';
  if (status === 'Pending') return cardStatus === 'pending';
  return cardStatus === status.toLowerCase();
}

function applyDCFilters() {
  const q = (document.getElementById('dcSearch')?.value || '').toLowerCase();
  document.querySelectorAll('.dc-card').forEach(card => {
    const searchOk = (card.dataset.search || '').includes(q);
    const statusOk = dcCardMatchesFilter(card, ACTIVE_DC_FILTER);
    card.style.display = searchOk && statusOk ? '' : 'none';
  });
}

function filterJobs(status, btn) {
  ACTIVE_DC_FILTER = normalizeFilterStatus(status);
  document.querySelectorAll('.dc-filter-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  updateStatBoxes(ACTIVE_DC_FILTER);
  applyDCFilters();
}

function filterFromStat(status) {
  let targetBtn = null;
  if (status === 'all') {
    targetBtn = findFilterButton('all');
  } else {
    targetBtn = findFilterButton(status);
  }
  if (targetBtn) targetBtn.click();
}

function updateStatBoxes(status) {
  document.querySelectorAll('.dc-stat').forEach(stat => stat.classList.remove('active'));
  const statBox = document.querySelector(`.dc-stat[data-filter="${status}"]`);
  if (statBox) statBox.classList.add('active');
}

document.getElementById('dcSearch').addEventListener('input', function() {
  applyDCFilters();
});

// ═══ HISTORY TABLE ═══
let HT_PERIOD = 'all';
let HT_PAGE = 1;
let HT_SORT = { col: -1, asc: true };

function htVisibleRows() {
  const rows = Array.from(document.querySelectorAll('#htBody tr[data-id]'));
  const q = (document.getElementById('htSearch')?.value || '').trim().toLowerCase();
  const now = new Date();
  return rows.filter(function(tr) {
    if (q && !(tr.dataset.search || '').includes(q)) return false;
    if (HT_PERIOD === 'all') return true;
    const dRaw = tr.dataset.completed || '';
    if (!dRaw) return false;
    const d = new Date(dRaw);
    if (isNaN(d.getTime())) return false;
    if (HT_PERIOD === 'today') return d.toISOString().slice(0, 10) === now.toISOString().slice(0, 10);
    if (HT_PERIOD === 'week') { const dow = now.getDay() || 7; const start = new Date(now); start.setDate(now.getDate() - dow + 1); start.setHours(0,0,0,0); return d >= start; }
    if (HT_PERIOD === 'month') return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    if (HT_PERIOD === 'year') return d.getFullYear() === now.getFullYear();
    if (HT_PERIOD === 'custom') {
      const day = d.toISOString().slice(0, 10);
      const from = document.getElementById('htDateFrom')?.value || '';
      const to = document.getElementById('htDateTo')?.value || '';
      if (from && day < from) return false;
      if (to && day > to) return false;
      return true;
    }
    return true;
  });
}

function htSetPeriod(period, btn) {
  HT_PERIOD = period;
  document.querySelectorAll('.ht-period-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const df = document.getElementById('htDateFrom'); const dt = document.getElementById('htDateTo');
  if (df) df.value = ''; if (dt) dt.value = '';
  htGoPage(1);
}

function htApplyCustomDate() {
  const from = document.getElementById('htDateFrom')?.value || '';
  const to = document.getElementById('htDateTo')?.value || '';
  if (!from && !to) return;
  HT_PERIOD = 'custom';
  document.querySelectorAll('.ht-period-btn').forEach(b => b.classList.remove('active'));
  htGoPage(1);
}

function htGoPage(page) {
  const visible = htVisibleRows();
  const perSel = document.getElementById('htPerPage')?.value || '25';
  const per = perSel === 'all' ? visible.length : parseInt(perSel, 10);
  const perSafe = per > 0 ? per : Math.max(visible.length, 1);
  const totalPages = Math.max(1, Math.ceil(visible.length / perSafe));
  HT_PAGE = Math.max(1, Math.min(page, totalPages));
  const start = (HT_PAGE - 1) * perSafe;
  const end = start + perSafe;
  document.querySelectorAll('#htBody tr[data-id]').forEach(tr => tr.style.display = 'none');
  visible.forEach((tr, i) => tr.style.display = (i >= start && i < end) ? '' : 'none');
  const shownEnd = Math.min(end, visible.length);
  const info = document.getElementById('htPageInfo');
  if (info) info.textContent = 'Showing ' + (visible.length ? (start + 1) : 0) + '–' + shownEnd + ' of ' + visible.length;
  const btns = document.getElementById('htPageBtns');
  if (btns) {
    let html = '<button class="ht-page-btn" onclick="htGoPage(' + (HT_PAGE - 1) + ')" ' + (HT_PAGE <= 1 ? 'disabled' : '') + '>‹</button>';
    for (let p = 1; p <= totalPages; p++) {
      if (totalPages > 7 && p > 2 && p < totalPages - 1 && Math.abs(p - HT_PAGE) > 1) {
        if (p === 3 || p === totalPages - 2) html += '<span style="padding:0 6px;color:#94a3b8">…</span>';
        continue;
      }
      html += '<button class="ht-page-btn ' + (p === HT_PAGE ? 'active' : '') + '" onclick="htGoPage(' + p + ')">' + p + '</button>';
    }
    html += '<button class="ht-page-btn" onclick="htGoPage(' + (HT_PAGE + 1) + ')" ' + (HT_PAGE >= totalPages ? 'disabled' : '') + '>›</button>';
    btns.innerHTML = html;
  }
  htUpdateBulk();
}

function htSortCol(colIdx) {
  const tbody = document.getElementById('htBody');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
  if (!rows.length) return;
  const asc = HT_SORT.col === colIdx ? !HT_SORT.asc : true;
  HT_SORT = { col: colIdx, asc: asc };
  rows.sort(function(a, b) {
    const aText = (a.children[colIdx + 1]?.textContent || '').trim();
    const bText = (b.children[colIdx + 1]?.textContent || '').trim();
    const aNum = parseFloat(aText); const bNum = parseFloat(bText);
    if (!isNaN(aNum) && !isNaN(bNum)) return asc ? (aNum - bNum) : (bNum - aNum);
    const aDate = Date.parse(aText); const bDate = Date.parse(bText);
    if (!isNaN(aDate) && !isNaN(bDate)) return asc ? (aDate - bDate) : (bDate - aDate);
    return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
  });
  rows.forEach(r => tbody.appendChild(r));
  document.querySelectorAll('#htTable th').forEach((th, i) => {
    th.classList.toggle('sorted', i === colIdx + 1);
    const icon = th.querySelector('.ht-sort');
    if (icon) icon.textContent = (i === colIdx + 1) ? (asc ? '▲' : '▼') : '▲▼';
  });
  htGoPage(1);
}

function htUpdateBulk() {
  const selected = document.querySelectorAll('.ht-row-cb:checked').length;
  const bar = document.getElementById('htBulkBar');
  const count = document.getElementById('htSelectedCount');
  if (count) count.textContent = selected;
  if (bar) bar.classList.toggle('visible', selected > 0);
  document.querySelectorAll('.ht-row-cb').forEach(cb => {
    const tr = cb.closest('tr'); if (tr) tr.classList.toggle('ht-selected', cb.checked);
  });
}

function htToggleAll(checked) {
  document.querySelectorAll('#htBody tr[data-id]').forEach(tr => {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb'); if (cb) cb.checked = checked;
  });
  htUpdateBulk();
}

function htSelectAllVisible() {
  document.querySelectorAll('#htBody tr[data-id]').forEach(tr => {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb'); if (cb) cb.checked = true;
  });
  const master = document.getElementById('htCheckAll'); if (master) master.checked = true;
  htUpdateBulk();
}

function htDeselectAll() {
  document.querySelectorAll('.ht-row-cb').forEach(cb => cb.checked = false);
  const master = document.getElementById('htCheckAll'); if (master) master.checked = false;
  htUpdateBulk();
}

function htBulkPrint() {
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(cb => cb.dataset.jobId);
  if (!ids.length) { alert('No history job selected'); return; }
  const jobs = ids.map(id => ALL_JOBS.find(j => j.id == id)).filter(Boolean);
  if (!jobs.length) return;
  (async function() {
    const mode = await choosePrintMode();
    if (!mode) return;
    let pages = '';
    for (let idx = 0; idx < jobs.length; idx++) {
      const job = jobs[idx];
      const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
      const qrDataUrl = await generateQR(qrUrl);
      const pb = idx < jobs.length - 1 ? 'page-break-after:always;' : '';
      let cardHtml = renderDCPrintCardHtml(job, qrDataUrl);
      if (mode === 'bw') cardHtml = printBwTransform(cardHtml);
      pages += `<div style="${pb}">${cardHtml}</div>`;
    }
    const w = window.open('', '_blank', 'width=820,height=920');
    w.document.write('<!DOCTYPE html><html><head><title>History Bulk Print - ' + jobs.length + ' <?= addslashes($dcBulkPrintTitle) ?></title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>' + pages + '</body></html>');
    w.document.close(); w.focus(); setTimeout(() => w.print(), 400);
  })();
}

async function htBulkDelete() {
  if (!IS_ADMIN) { alert('Access denied. Only system admin can delete job cards.'); return; }
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(cb => cb.dataset.jobId);
  if (!ids.length) { alert('No history jobs selected'); return; }
  if (!confirm(`Delete ${ids.length} selected job card(s)?\n\nThis will reset linked paper stock, planning status, and downstream queued jobs for each.`)) return;
  let ok = 0, failed = 0, errors = [];
  for (const id of ids) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action', 'delete_job'); fd.append('job_id', id);
    try {
      const res = await fetch(API_BASE, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) { ok++; } else { failed++; const job = ALL_JOBS.find(j => j.id == id); errors.push(`${job ? job.job_no : 'ID ' + id}: ${data.error || 'Unknown error'}`); }
    } catch (err) { failed++; errors.push('ID ' + id + ': ' + err.message); }
  }
  if (errors.length) alert(`Deleted: ${ok}, Failed: ${failed}\n\n${errors.join('\n')}`);
  if (ok > 0) location.reload();
}

document.getElementById('htSearch')?.addEventListener('input', function() { htGoPage(1); });

// ═══ LIVE TIMERS ═══
function updateTimers() {
  document.querySelectorAll('.dc-timer').forEach(el => {
    const base = Math.max(0, parseInt(el.dataset.baseSeconds || '0', 10) || 0);
    const resumedAt = parseInt(el.dataset.resumedAt || '0', 10) || 0;
    const diff = resumedAt > 0 ? Math.floor(base + ((Date.now() - resumedAt) / 1000)) : base;
    const h = String(Math.floor(diff/3600)).padStart(2,'0');
    const m = String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    el.textContent = h + ':' + m + ':' + s;
  });
}
setInterval(updateTimers, 1000);
updateTimers();

function canAutoRefreshDC() {
  return !document.getElementById('dcDetailModal')?.classList.contains('active');
}

setInterval(function() {
  if (canAutoRefreshDC()) location.reload();
}, DC_AUTO_REFRESH_MS);

// ═══ STATUS UPDATE ═══
async function updateJobStatus(id, newStatus) {
  if (!confirm('Set this job to ' + newStatus + '?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'update_status'); fd.append('job_id', id); fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload(); else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ═══ TIMER HELPERS ═══
let _timerInterval = null;
let _timerStart = 0;
let _timerJobId = null;

function isDCTimerActive(job) {
  return !!(job && job.extra_data_parsed && job.extra_data_parsed.timer_active);
}

function pushDCTimerEventLocal(extra, type, at) {
  extra.timer_events = Array.isArray(extra.timer_events) ? extra.timer_events : [];
  const last = extra.timer_events.length ? extra.timer_events[extra.timer_events.length - 1] : null;
  if (!last || String(last.type || '') !== type || String(last.at || '') !== at) {
    extra.timer_events.push({ type, at });
  }
}

function pushDCTimerSegmentLocal(extra, key, from, to) {
  const fromTs = Date.parse(String(from || '').replace(' ', 'T'));
  const toTs = Date.parse(String(to || '').replace(' ', 'T'));
  if (!Number.isFinite(fromTs) || !Number.isFinite(toTs) || toTs <= fromTs) return;
  extra[key] = Array.isArray(extra[key]) ? extra[key] : [];
  extra[key].push({ from, to, seconds: Math.floor((toTs - fromTs) / 1000) });
}

function dcSecondsToHms(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  return String(Math.floor(sec / 3600)).padStart(2,'0') + ':' + String(Math.floor((sec % 3600) / 60)).padStart(2,'0') + ':' + String(sec % 60).padStart(2,'0');
}

function dcFormatDuration(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(sec / 3600); const m = Math.floor((sec % 3600) / 60); const s = sec % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return s > 0 ? `${m}m ${s}s` : `${m}m`;
  return `${s}s`;
}

function dcFormatDateTime(value) {
  const raw = String(value || '').trim();
  if (!raw) return '—';
  const parsed = new Date(raw.replace(' ', 'T'));
  return Number.isFinite(parsed.getTime()) ? parsed.toLocaleString() : '—';
}

function dcTimerTotalSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const base = Math.max(0, Number(extra.timer_accumulated_seconds || 0));
  if (!extra.timer_active) return Math.floor(base);
  const resumedRaw = extra.timer_last_resumed_at || extra.timer_started_at || job.started_at || '';
  const resumedAt = resumedRaw ? Date.parse(String(resumedRaw).replace(' ', 'T')) : NaN;
  if (!Number.isFinite(resumedAt) || resumedAt <= 0) return Math.floor(base);
  return Math.floor(base + ((Date.now() - resumedAt) / 1000));
}

function dcDurationSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const acc = Math.max(0, Math.floor(Number(extra.timer_accumulated_seconds || 0)));
  if (acc > 0) return acc;
  const mins = Number(job.duration_minutes || 0);
  return Number.isFinite(mins) && mins > 0 ? Math.floor(mins * 60) : 0;
}

function dcPauseTotalSeconds(extra) {
  const segments = Array.isArray(extra?.timer_pause_segments) ? extra.timer_pause_segments : [];
  let total = segments.reduce((sum, row) => sum + Math.max(0, Number(row?.seconds || 0)), 0);
  const pausedAt = String(extra?.timer_pause_started_at || extra?.timer_paused_at || '').trim();
  const isPaused = String(extra?.timer_state || '').toLowerCase() === 'paused';
  if (isPaused && pausedAt) {
    const fromTs = Date.parse(pausedAt.replace(' ', 'T'));
    if (Number.isFinite(fromTs) && fromTs > 0) total += Math.max(0, Math.floor((Date.now() - fromTs) / 1000));
  }
  return total;
}

function buildDCTimerHistoryHtml(job) {
  const extra = job?.extra_data_parsed || {};
  const events = Array.isArray(extra.timer_events) ? extra.timer_events.filter(row => row && row.at) : [];
  const fallbackEvents = [];
  if (!events.length) {
    if (extra.timer_started_at || job?.started_at) fallbackEvents.push({ type: 'start', at: extra.timer_started_at || job.started_at });
    if (extra.timer_paused_at) fallbackEvents.push({ type: 'pause', at: extra.timer_paused_at });
    if (extra.timer_ended_at || job?.completed_at) fallbackEvents.push({ type: 'end', at: extra.timer_ended_at || job.completed_at });
  }
  const eventRows = (events.length ? events : fallbackEvents).sort((a, b) => Date.parse(String(a.at).replace(' ', 'T')) - Date.parse(String(b.at).replace(' ', 'T')));

  const segments = [];
  (Array.isArray(extra.timer_work_segments) ? extra.timer_work_segments : []).forEach(row => {
    if (row && row.from && row.to) segments.push({ kind: 'work', from: row.from, to: row.to, seconds: Number(row.seconds || 0) });
  });
  (Array.isArray(extra.timer_pause_segments) ? extra.timer_pause_segments : []).forEach(row => {
    if (row && row.from && row.to) segments.push({ kind: 'pause', from: row.from, to: row.to, seconds: Number(row.seconds || 0) });
  });
  segments.sort((a, b) => Date.parse(String(a.from).replace(' ', 'T')) - Date.parse(String(b.from).replace(' ', 'T')));

  const timeOnly = (val) => {
    const d = new Date(String(val || '').replace(' ', 'T'));
    if (!Number.isFinite(d.getTime())) return '—';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  };

  const summaryBits = segments.map(row => `${timeOnly(row.from)}-${timeOnly(row.to)} ${row.kind === 'pause' ? 'paused' : 'worked'}`);
  const pauseTotalSeconds = segments.filter(row => row.kind === 'pause').reduce((sum, row) => sum + Math.max(0, Number(row.seconds || 0)), 0);
  const summaryText = summaryBits.length ? summaryBits.join(', ') : 'No time ranges yet';

  const eventMap = { start: 'Start', resume: 'Again Start', pause: 'Pause', end: 'End' };
  const eventsHtml = eventRows.length
    ? eventRows.map(row => `<div class="dc-timer-history-row"><div class="k">${esc(eventMap[String(row.type || '').toLowerCase()] || 'Event')}</div><div class="v">${esc(dcFormatDateTime(row.at))}</div></div>`).join('')
    : '<div class="dc-timer-history-empty">No timer event history yet.</div>';
  const segmentsHtml = segments.length
    ? segments.map(row => `<div class="dc-timer-history-row ${row.kind}"><div class="k">${row.kind === 'pause' ? 'Paused' : 'Worked'}</div><div class="v">${esc(dcFormatDateTime(row.from))} - ${esc(dcFormatDateTime(row.to))}<br><span style="color:#64748b;font-weight:800">${esc(dcFormatDuration(row.seconds))}</span></div></div>`).join('')
    : '<div class="dc-timer-history-empty">No work/pause ranges recorded yet.</div>';

  return `<div class="dc-timer-history">
    <div class="dc-timer-history-card"><h4>Event Log</h4><div class="dc-timer-history-list">${eventsHtml}</div></div>
    <div class="dc-timer-history-card"><h4>Work / Pause Range</h4><div style="font-size:.82rem;font-weight:800;color:#0f172a;line-height:1.55;margin-bottom:8px">${esc(summaryText)}</div><div style="font-size:.78rem;font-weight:900;color:#b45309;margin-bottom:10px">Total Pause: ${esc(dcFormatDuration(pauseTotalSeconds))}</div><div class="dc-timer-history-list">${segmentsHtml}</div></div>
  </div>`;
}

// ═══ TIMER OPERATIONS ═══
async function markDCTimerEnded(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'end_timer_session'); fd.append('job_id', jobId);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to end timer');
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    const nowIso = data.timer_ended_at || new Date().toISOString();
    if (job.extra_data_parsed.timer_last_resumed_at) {
      pushDCTimerSegmentLocal(job.extra_data_parsed, 'timer_work_segments', job.extra_data_parsed.timer_last_resumed_at, nowIso);
    }
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'ended';
    job.extra_data_parsed.timer_ended_at = nowIso;
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_paused_at = '';
    job.extra_data_parsed.timer_pause_started_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = Number(data.timer_accumulated_seconds || job.extra_data_parsed.timer_accumulated_seconds || 0);
    pushDCTimerEventLocal(job.extra_data_parsed, 'end', nowIso);
  }
}

async function pauseDCTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'pause_timer_session'); fd.append('job_id', jobId);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to pause timer');
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    const nowIso = new Date().toISOString();
    if (job.extra_data_parsed.timer_last_resumed_at) {
      pushDCTimerSegmentLocal(job.extra_data_parsed, 'timer_work_segments', job.extra_data_parsed.timer_last_resumed_at, nowIso);
    }
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'paused';
    job.extra_data_parsed.timer_paused_at = data.timer_paused_at || '';
    job.extra_data_parsed.timer_pause_started_at = data.timer_paused_at || nowIso;
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = Number(data.timer_accumulated_seconds || 0);
    pushDCTimerEventLocal(job.extra_data_parsed, 'pause', job.extra_data_parsed.timer_paused_at || nowIso);
  }
}

async function resetDCTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'reset_timer_session'); fd.append('job_id', jobId);
  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to reset timer');
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.status = 'Pending';
    job.started_at = null;
    job.completed_at = null;
    job.duration_minutes = null;
    job.extra_data_parsed = job.extra_data_parsed || {};
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = 'pending';
    job.extra_data_parsed.timer_started_at = '';
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_paused_at = '';
    job.extra_data_parsed.timer_pause_started_at = '';
    job.extra_data_parsed.timer_ended_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = 0;
    job.extra_data_parsed.timer_events = [];
    job.extra_data_parsed.timer_work_segments = [];
    job.extra_data_parsed.timer_pause_segments = [];
  }
}

async function endRunningDCTimer(jobId) {
  try { await markDCTimerEnded(jobId); } catch (err) { alert('Timer end failed: ' + err.message); return; }
  const ov = document.getElementById('dcTimerOverlay');
  if (ov) ov.remove();
  openJobDetail(jobId, 'complete');
}

function dcTimerStartMs(job) {
  if (!job) return Date.now();
  const startedRaw = (job.extra_data_parsed && job.extra_data_parsed.timer_started_at) || job.started_at || '';
  const parsed = startedRaw ? Date.parse(String(startedRaw).replace(' ', 'T')) : NaN;
  return Number.isFinite(parsed) && parsed > 0 ? parsed : Date.now();
}

function showDCTimerOverlay(job) {
  if (!job) return;
  const existing = document.getElementById('dcTimerOverlay');
  if (existing) existing.remove();

  _timerJobId = Number(job.id || 0);
  _timerStart = dcTimerStartMs(job);

  const jobLabel = resolveJobDisplayName(job) || ('Job #' + _timerJobId);
  const jobNo = job.job_no || '';

  const overlay = document.createElement('div');
  overlay.className = 'dc-timer-overlay';
  overlay.id = 'dcTimerOverlay';
  overlay.innerHTML = `
    <div class="dc-timer-jobinfo">
      <div style="font-size:1.3rem;font-weight:900;letter-spacing:.03em">${esc(jobNo)}</div>
      <div style="margin-top:4px">${esc(jobLabel)}</div>
    </div>
    <div class="dc-timer-display" id="dcTimerCounter">00:00:00</div>
    <div class="dc-timer-actions">
      <button class="dc-timer-btn-cancel" onclick="cancelTimer()"><i class="bi bi-x-lg"></i> Cancel</button>
      <button class="dc-timer-btn-pause" onclick="pauseTimer()"><i class="bi bi-pause-fill"></i> Pause</button>
      <button class="dc-timer-btn-end" onclick="endTimer()"><i class="bi bi-stop-fill"></i> End</button>
    </div>
  `;
  document.body.appendChild(overlay);

  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = setInterval(() => {
    const diff = Math.max(0, dcTimerTotalSeconds(job));
    const el = document.getElementById('dcTimerCounter');
    if (el) el.textContent = dcSecondsToHms(diff);
  }, 1000);
}

function resumeRunningDCTimer(jobId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (!job || String(job.status || '') !== 'Running') {
    alert('Timer is not active for this job.');
    return;
  }
  if (!isDCTimerActive(job)) {
    alert('Timer is paused. Click Again Start.');
    return;
  }
  showDCTimerOverlay(job);
}

async function startJobWithTimer(id) {
  if (!confirm('Start this job?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'update_status'); fd.append('job_id', id); fd.append('status', 'Running');
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { alert('Error: ' + (data.error || 'Unknown')); return; }
  } catch (err) { alert('Network error: ' + err.message); return; }

  document.getElementById('dcDetailModal').classList.remove('active');
  _timerJobId = id;
  _timerStart = Date.now();
  const job = ALL_JOBS.find(j => j.id == id) || {};
  const nowIso = new Date().toISOString();
  job.status = 'Running';
  if (!job.started_at) job.started_at = nowIso;
  job.extra_data_parsed = job.extra_data_parsed || {};
  if (!job.extra_data_parsed.timer_started_at) {
    job.extra_data_parsed.timer_started_at = nowIso;
    pushDCTimerEventLocal(job.extra_data_parsed, 'start', nowIso);
  } else if (String(job.extra_data_parsed.timer_state || '').toLowerCase() === 'paused') {
    const pausedAt = job.extra_data_parsed.timer_pause_started_at || job.extra_data_parsed.timer_paused_at || '';
    if (pausedAt) {
      pushDCTimerSegmentLocal(job.extra_data_parsed, 'timer_pause_segments', pausedAt, nowIso);
    }
    pushDCTimerEventLocal(job.extra_data_parsed, 'resume', nowIso);
  }
  job.extra_data_parsed.timer_active = true;
  job.extra_data_parsed.timer_state = 'running';
  job.extra_data_parsed.timer_last_resumed_at = nowIso;
  job.extra_data_parsed.timer_paused_at = '';
  job.extra_data_parsed.timer_pause_started_at = '';
  job.extra_data_parsed.timer_ended_at = '';
  showDCTimerOverlay(job);
}

async function cancelTimer() {
  if (!_timerJobId) return;
  if (!confirm('Cancel will reset this job timer and return it to Pending. Continue?')) return;
  try {
    await resetDCTimer(_timerJobId);
  } catch (err) {
    alert('Timer reset failed: ' + err.message);
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('dcTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

async function pauseTimer() {
  const jobId = _timerJobId;
  if (!jobId) return;
  try {
    await pauseDCTimer(jobId);
  } catch (err) {
    alert('Timer pause failed: ' + err.message);
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('dcTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

function endTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const jobId = _timerJobId;
  _timerJobId = null;
  endRunningDCTimer(jobId);
}

// ═══ PHOTO UPLOAD ═══
async function uploadDCPhoto(jobId) {
  const input = document.getElementById('dc-photo-input-' + jobId);
  if (!input || !input.files || !input.files[0]) return;
  const file = input.files[0];
  const statusEl = document.getElementById('dc-photo-status-' + jobId);
  const previewEl = document.getElementById('dc-photo-preview-' + jobId);
  if (statusEl) statusEl.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'upload_die_cutting_photo'); fd.append('job_id', jobId); fd.append('photo', file);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (statusEl) statusEl.innerHTML = '<i class="bi bi-check-circle" style="color:#16a34a"></i> Uploaded';
      if (previewEl) previewEl.innerHTML = `<img src="${data.photo_url}" alt="Job Photo">`;
      _lastPhotoPath = data.photo_path || '';
      const job = ALL_JOBS.find(j => j.id == jobId);
      if (job) { job.extra_data_parsed = job.extra_data_parsed || {}; job.extra_data_parsed.die_cutting_photo_url = data.photo_url || ''; job.extra_data_parsed.die_cutting_photo_path = data.photo_path || ''; }
    } else {
      if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626">Error: ' + esc(data.error || 'Unknown') + '</span>';
    }
  } catch (err) {
    if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626">Network error</span>';
  }
}

// ═══ VOICE RECORDING ═══
function toggleVoiceRecord(jobId) {
  const btn = document.getElementById('dc-voice-btn-' + jobId);
  if (!_voiceRecorder) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('Microphone not supported.'); return; }
    navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
      _voiceChunks = [];
      _voiceRecorder = new MediaRecorder(stream);
      _voiceRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) _voiceChunks.push(e.data); };
      _voiceRecorder.onstop = function(){
        stream.getTracks().forEach(t => t.stop());
        const blob = new Blob(_voiceChunks, {type:'audio/webm'});
        const file = new File([blob], 'die-cutting-note.webm', {type:'audio/webm'});
        const fd = new FormData();
        fd.append('action', 'upload_die_cutting_voice'); fd.append('job_id', String(jobId)); fd.append('csrf_token', CSRF); fd.append('voice', file);
        fetch(API_BASE, {method:'POST', body:fd}).then(r => r.json()).then(res => {
          if (res && res.ok) { _lastVoicePath = res.voice_path || ''; const st = document.getElementById('dc-voice-status-' + jobId); if (st) st.innerHTML = '<i class="bi bi-check-circle" style="color:#16a34a"></i> Voice recorded'; }
          else { alert((res && res.error) || 'Voice upload failed'); }
        }).catch(() => alert('Voice upload failed'));
        _voiceRecorder = null;
        if (btn) btn.innerHTML = '<i class="bi bi-mic"></i> Record Voice';
      };
      _voiceRecorder.start();
      if (btn) btn.innerHTML = '<i class="bi bi-stop-circle" style="color:#dc2626"></i> Stop Recording';
    }).catch(() => alert('Microphone permission denied.'));
    return;
  }
  _voiceRecorder.stop();
}

// ═══ EXTRA DATA FORM ═══
function buildDCExtraDataFromForm(form) {
  if (!form) return null;
  return {
    die_cutting_total_qty_pcs: form.querySelector('[name=die_cutting_total_qty_pcs]')?.value || '',
    die_cutting_wastage_pcs: form.querySelector('[name=die_cutting_wastage_pcs]')?.value || '',
    die_cutting_wastage_mtr: form.querySelector('[name=die_cutting_wastage_mtr]')?.value || '',
    die_cutting_notes_text: form.querySelector('[name=die_cutting_notes_text]')?.value || '',
    die_cutting_printed_roll_length_mtr: form.querySelector('[name=die_cutting_printed_roll_length_mtr]')?.value || '',
    die_cutting_photo_path: _lastPhotoPath,
    die_cutting_voice_note_path: _lastVoicePath,
    die_cutting_submitted_at: new Date().toISOString()
  };
}

// ═══ SUBMIT & CLOSE ═══
async function submitAndClose(id) {
  const job = ALL_JOBS.find(j => j.id == id) || {};
  if (isDCTimerActive(job)) {
    alert('Timer is still running. Please End the timer before finishing this job.');
    return;
  }
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateJobStatus(id, 'Completed');
  const extraData = buildDCExtraDataFromForm(form);
  if (!extraData) return updateJobStatus(id, 'Completed');

  // Validate required fields
  if (!extraData.die_cutting_total_qty_pcs || !extraData.die_cutting_wastage_pcs || !extraData.die_cutting_wastage_mtr) {
    alert('Total Qty, Wastage Pcs and Wastage Mtr are required.');
    return;
  }

  // Save extra data
  const fd1 = new FormData();
  fd1.append('csrf_token', CSRF); fd1.append('action', 'submit_extra_data'); fd1.append('job_id', id); fd1.append('extra_data', JSON.stringify(extraData));
  try {
    const r1 = await fetch(API_BASE, { method: 'POST', body: fd1 });
    const d1 = await r1.json();
    if (!d1.ok) { alert('Save error: ' + (d1.error||'Unknown')); return; }
  } catch(e) { alert('Network error'); return; }

  // Close job
  await updateJobStatus(id, 'Completed');
}

// ═══ DETAIL MODAL ═══
async function openJobDetail(id, mode) {
  const numericId = Number(id);
  const job = ALL_JOBS.find(j => Number(j.id) === numericId) || ALL_JOBS.find(j => String(j.id) === String(id));
  if (!job) {
    alert('Job details not found. Please refresh the page once.');
    return;
  }

  const sts = job.status;
  const stsClass = {Queued:'queued',Pending:'pending',Running:'running',Closed:'completed',Finalized:'completed',Completed:'completed'}[sts]||'pending';
  const timerActive = isDCTimerActive(job);
  const timerState = String((job.extra_data_parsed || {}).timer_state || '').toLowerCase();
  const extra = job.extra_data_parsed || {};
  const createdAt = dcFormatDateTime(job.created_at);
  const startedAt = dcFormatDateTime(extra.timer_started_at || job.started_at);
  const completedAt = dcFormatDateTime(extra.timer_ended_at || job.completed_at);
  const activeSeconds = timerActive ? dcTimerTotalSeconds(job) : dcDurationSeconds(job);
  const pauseSeconds = dcPauseTotalSeconds(extra);

  // Init photo/voice state
  _lastPhotoPath = String(extra.die_cutting_photo_path || '');
  _lastVoicePath = String(extra.die_cutting_voice_note_path || '');

  document.getElementById('dm-jobno').textContent = job.job_no;
  const badge = document.getElementById('dm-status-badge');
  badge.textContent = sts;
  badge.className = 'dc-badge dc-badge-' + stsClass;

  let html = '';

  const viewQrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no || '')}`;
  const viewQrDataUrl = await generateQR(viewQrUrl);
  const nowText = new Date().toLocaleString();

  // ── Company Header ──
  html += `<div class="dc-op-section" style="border-color:#99f6e4"><div class="dc-op-b" style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;background:linear-gradient(135deg,#f0fdfa,#fff)">
    <div style="display:flex;gap:10px;align-items:flex-start">
      ${COMPANY.logo ? `<img src="${COMPANY.logo}" alt="Logo" style="max-height:38px;max-width:110px;display:block">` : ''}
      <div>
        <div style="font-size:.92rem;font-weight:800;color:#0f172a">${esc(COMPANY.name || 'Company')}</div>
        <div style="font-size:.62rem;color:#64748b">${esc(COMPANY.address || '')}</div>
        ${COMPANY.gst ? `<div style="font-size:.62rem;color:#64748b">GST: ${esc(COMPANY.gst)}</div>` : ''}
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#334155"><?= e($dcDocumentTitle) ?></div>
      <div style="font-size:.92rem;font-weight:900;color:var(--dc-brand)">${esc(job.job_no || '—')}</div>
      <div style="font-size:.58rem;color:#64748b">${esc(nowText)}</div>
    </div>
  </div></div>`;

  // ── Previous Job (Flexo Printing) ──
  if (job.prev_job_no) {
    const prevDone = ['Completed','QC Passed','Closed','Finalized'].includes(job.prev_job_status || '');
    const pvColor = prevDone ? '#16a34a' : '#f59e0b';
    html += `<div class="dc-op-section" style="padding:12px;background:${prevDone?'#f0fdf4':'#fef3c7'};border-radius:10px;border-left:4px solid ${pvColor}">
      <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700">
        <i class="bi bi-${prevDone?'check-circle-fill':'lock-fill'}" style="color:${pvColor}"></i>
        Previous Job (Flexo): <span style="color:var(--dc-brand)">${esc(job.prev_job_no)}</span>
        — <span style="color:${pvColor}">${esc(job.prev_job_status||'—')}</span>
      </div>
    </div>`;
  }

  // ── Printing Production vs Planning Quantity ──
  {
    const rawPlanQty = String(job.planning_order_qty || '').trim();
    const rawProducedQty = String(
      String(DC_PRODUCED_QTY_SOURCE || '').toLowerCase() === 'current'
        ? (extra.barcode_total_qty_pcs ?? extra.total_qty_pcs ?? extra.die_cutting_total_qty_pcs ?? extra.actual_qty ?? '')
        : (job.prev_actual_qty || '')
    ).trim();
    const hasPlanQty = rawPlanQty !== '';
    const hasProducedQty = rawProducedQty !== '';
    const planQty = Number(rawPlanQty || 0);
    const producedQty = Number(rawProducedQty || 0);
    if (hasPlanQty || hasProducedQty) {
      const canCompare = hasPlanQty && hasProducedQty;
      const diff = canCompare ? (producedQty - planQty) : 0;
      const pctText = canCompare && planQty > 0
        ? (((diff / planQty) * 100) > 0 ? '+' : '') + ((diff / planQty) * 100).toFixed(1) + '%'
        : '';
      const isExtra = canCompare && diff > 0;
      const isShort = canCompare && diff < 0;
      const diffColor = isExtra ? '#16a34a' : (isShort ? '#dc2626' : '#64748b');
      const diffLabel = canCompare ? (isExtra ? 'Extra' : (isShort ? 'Shortage' : 'Matched')) : 'Difference';
      const diffIcon = canCompare ? (isExtra ? 'bi-arrow-up-circle-fill' : (isShort ? 'bi-arrow-down-circle-fill' : 'bi-check-circle-fill')) : 'bi-dash-circle';
      html += `<div class="dc-op-section" style="border-color:#e0e7ff">
        <div class="dc-op-h" style="background:#eef2ff;color:#4338ca"><i class="bi bi-bar-chart-line"></i> ${esc(DC_COMPARE_SECTION_TITLE || 'Printing Production vs Plan')}</div>
        <div class="dc-op-b">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center">
            <div style="background:#f8fafc;border-radius:10px;padding:10px 8px">
              <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em">Planning Qty</div>
              <div style="font-size:1.2rem;font-weight:900;color:#0f172a;margin-top:4px">${hasPlanQty ? planQty.toLocaleString() : '—'}</div>
              <div style="font-size:.58rem;color:#64748b">Pcs</div>
            </div>
            <div style="background:#f8fafc;border-radius:10px;padding:10px 8px">
              <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em">${esc(DC_PRODUCED_QTY_LABEL || 'Printing Produced')}</div>
              <div style="font-size:1.2rem;font-weight:900;color:#3b82f6;margin-top:4px">${hasProducedQty ? producedQty.toLocaleString() : '—'}</div>
              <div style="font-size:.58rem;color:#64748b">Pcs</div>
            </div>
            <div style="background:${canCompare ? (isExtra?'#f0fdf4':(isShort?'#fef2f2':'#f8fafc')) : '#f8fafc'};border-radius:10px;padding:10px 8px">
              <div style="font-size:.58rem;font-weight:800;text-transform:uppercase;color:${diffColor};letter-spacing:.06em"><i class="bi ${diffIcon}"></i> ${diffLabel}</div>
              <div style="font-size:1.2rem;font-weight:900;color:${diffColor};margin-top:4px">${canCompare ? Math.abs(diff).toLocaleString() : '—'}</div>
              <div style="font-size:.62rem;font-weight:800;color:${diffColor};min-height:1em">${canCompare ? pctText : '—'}</div>
            </div>
          </div>
        </div>
      </div>`;
    }
  }

  // ── Timeline ──
  html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-clock-history"></i> Timeline</div><div class="dc-op-b"><div class="dc-op-topstrip">
    <div class="dc-op-topitem"><span class="k">Created</span><span class="v">${createdAt}</span></div>
    <div class="dc-op-topitem"><span class="k">Started</span><span class="v" style="color:#3b82f6">${startedAt}</span></div>
    <div class="dc-op-topitem"><span class="k">Completed</span><span class="v" style="color:#16a34a">${completedAt}</span></div>
    <div class="dc-op-topitem"><span class="k">Active Time</span><span class="v" style="color:#16a34a">${activeSeconds > 0 ? esc(dcFormatDuration(activeSeconds)) : '—'}</span></div>
    <div class="dc-op-topitem"><span class="k">Pause Time</span><span class="v" style="color:#b45309">${pauseSeconds > 0 ? esc(dcFormatDuration(pauseSeconds)) : '—'}</span></div>
  </div>${buildDCTimerHistoryHtml(job)}</div></div>`;

  // ── Notes ──
  if (job.notes_display || job.notes) {
    html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-sticky"></i> Notes</div><div class="dc-op-b"><div style="font-size:.82rem;color:#475569;line-height:1.5;background:#fef3c7;padding:12px;border-radius:8px;font-weight:700">${esc(job.notes_display || job.notes || '')}</div></div></div>`;
  }

  // ── Job Information ──
  html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-info-circle"></i> Job Information</div><div class="dc-op-b dc-op-grid-2">
    <div class="dc-op-field"><label>Job No</label><div class="fv" style="color:var(--dc-brand)">${esc(job.job_no)}</div></div>
    <div class="dc-op-field"><label>Job Name</label><div class="fv">${esc(resolveJobDisplayName(job))}</div></div>
    <div class="dc-op-field"><label>Client Name</label><div class="fv">${esc(job.planning_client_name || '—')}</div></div>
    <div class="dc-op-field"><label>Priority</label><div class="fv">${esc(job.planning_priority||'Normal')}</div></div>
    <div class="dc-op-field"><label>Sequence</label><div class="fv">#${job.sequence_order||1}</div></div>
  </div></div>`;

  // ── Die-Cutting Details ──
  html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-scissors"></i> ${esc(DC_DETAILS_SECTION_LABEL || 'Die-Cutting Details')}</div><div class="dc-op-b dc-op-grid-2">
    <div class="dc-op-field"><label>Material</label><div class="fv">${esc(job.planning_material || job.paper_type || '—')}</div></div>
    <div class="dc-op-field"><label>Die Size</label><div class="fv" style="color:var(--dc-brand);font-weight:900">${esc(job.planning_die_size || '—')}</div></div>
    <div class="dc-op-field"><label>Repeat</label><div class="fv">${esc(job.planning_repeat || '—')}</div></div>
    <div class="dc-op-field"><label>Order Quantity (Pcs)</label><div class="fv">${esc(job.planning_order_qty || '—')}</div></div>
    <div class="dc-op-field"><label>Total Length (Mtr)</label><div class="fv">${esc((job.length_mtr ?? '—') + '')}</div></div>
    <div class="dc-op-field"><label>Roll No</label><div class="fv">${esc(job.roll_no || '—')}</div></div>
    ${(() => {
      if (!DC_SHOW_WEIGHT_HEIGHT_FIELDS) return '';
      const dim = getJobSizeDimensions(job);
      const weightValue = dim.weight ? `${dim.weight} mm` : '—';
      const heightValue = dim.height ? `${dim.height} mm` : '—';
      return `
    <div class="dc-op-field"><label>${esc(DC_WEIGHT_LABEL || 'Weight')}</label><div class="fv">${esc(weightValue)}</div></div>
    <div class="dc-op-field"><label>${esc(DC_HEIGHT_LABEL || 'Height')}</label><div class="fv">${esc(heightValue)}</div></div>`;
    })()}
    <div class="dc-op-field"><label>${esc(DC_PAPER_WIDTH_LABEL || 'Width (mm)')}</label><div class="fv">${esc((job.width_mm ?? '—') + '')}</div></div>
    <div class="dc-op-field"><label>GSM</label><div class="fv">${esc((job.gsm ?? '—') + '')}</div></div>
  </div></div>`;

  // ── Job Preview Image ──
  const previewUrl = job.planning_image_url || '';
  if (previewUrl) {
    html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-image"></i> Job Preview</div><div class="dc-op-b" style="text-align:center">
      <img src="${esc(previewUrl)}" class="dc-preview" alt="Job Preview">
    </div></div>`;
  }

  // ── Operator Entry (readonly view of submitted data) ──
  {
    const qtyPcs = extra.die_cutting_total_qty_pcs || '';
    const wastagePcs = extra.die_cutting_wastage_pcs || '';
    const wastageMtr = extra.die_cutting_wastage_mtr || '';
    const dcNotes = extra.die_cutting_notes_text || '';
    const voiceOriginal = extra.voice_input_original || '';
    const voiceEnglish = extra.voice_input_english || '';

    let opEntryHtml = `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-person-workspace"></i> Operator Entry</div><div class="dc-op-b dc-op-grid-2">
      <div class="dc-op-field"><label>Total Qty (Pcs)</label><div class="fv">${esc(qtyPcs || '—')}</div></div>
      <div class="dc-op-field"><label>Wastage (Pcs)</label><div class="fv">${esc(wastagePcs || '—')}</div></div>
      <div class="dc-op-field"><label>Wastage (Mtr)</label><div class="fv">${esc(wastageMtr || '—')}</div></div>
      <div class="dc-op-field"><label>Notes</label><div class="fv">${esc(dcNotes || '—')}</div></div>
    </div>`;

    if (voiceOriginal || voiceEnglish) {
      opEntryHtml += `<div style="margin:0 10px 10px;padding:12px;background:linear-gradient(135deg,#f0fdfa,#ccfbf1);border-radius:8px;border-left:3px solid var(--dc-brand);font-size:.75rem">
        <div style="margin-bottom:6px"><strong style="color:var(--dc-brand)"><i class="bi bi-mic-fill"></i> Voice Input:</strong></div>`;
      if (voiceOriginal) opEntryHtml += `<div style="margin:4px 0;color:#475569"><strong>Original:</strong> ${esc(voiceOriginal)}</div>`;
      if (voiceEnglish) opEntryHtml += `<div style="margin:4px 0;color:#475569"><strong>English:</strong> ${esc(voiceEnglish)}</div>`;
      opEntryHtml += `</div>`;
    }

    opEntryHtml += `</div>`;
    html += opEntryHtml;
  }

  // ── Editable Operator Form (complete mode) ──
  if (mode === 'complete' && IS_OPERATOR_VIEW && sts === 'Running') {
    html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</div>
    <form id="dm-operator-form" class="dc-op-b" style="display:grid;gap:10px">
      <div class="dc-op-grid-2">
        <div class="dc-op-field"><label>Total Qty (Pcs)</label><input type="number" min="0" step="1" name="die_cutting_total_qty_pcs" value="${esc(extra.die_cutting_total_qty_pcs || '')}"></div>
        <div class="dc-op-field"><label>Wastage (Pcs)</label><input type="number" min="0" step="1" name="die_cutting_wastage_pcs" value="${esc(extra.die_cutting_wastage_pcs || '')}"></div>
        <div class="dc-op-field"><label>Wastage (Mtr)</label><input type="number" min="0" step="0.01" name="die_cutting_wastage_mtr" value="${esc(extra.die_cutting_wastage_mtr || '')}"></div>
        <div class="dc-op-field"><label>Printed Roll Length (Mtr)</label><input type="text" name="die_cutting_printed_roll_length_mtr" value="${esc(job.length_mtr || '')}" readonly></div>
      </div>
      <div class="dc-op-field"><label>Notes</label><textarea name="die_cutting_notes_text">${esc(extra.die_cutting_notes_text || '')}</textarea></div>
    </form></div>`;
  }

  // ── Photo Upload ──
  {
    const existingPhoto = extra.die_cutting_photo_url || '';
    html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-camera"></i> Job Photo</div><div class="dc-op-b">
      <div class="dc-upload-zone" onclick="document.getElementById('dc-photo-input-${job.id}').click()">
        <input type="file" id="dc-photo-input-${job.id}" accept="image/*" capture="environment" onchange="uploadDCPhoto(${job.id})">
        <div style="font-size:.75rem;color:#64748b"><i class="bi bi-cloud-arrow-up" style="font-size:1.5rem;color:var(--dc-brand)"></i><br>Tap to open camera</div>
        <div id="dc-photo-status-${job.id}" style="font-size:.7rem;margin-top:6px"></div>
      </div>
      <div id="dc-photo-preview-${job.id}" class="dc-upload-preview">${existingPhoto ? `<img src="${existingPhoto}" alt="Job Photo">` : ''}</div>
    </div></div>`;
  }

  // ── Voice Recording ──
  html += `<div class="dc-op-section"><div class="dc-op-h"><i class="bi bi-mic"></i> Voice Note</div><div class="dc-op-b" style="display:flex;align-items:center;gap:12px">
    <button type="button" id="dc-voice-btn-${job.id}" class="dc-action-btn dc-btn-start" onclick="toggleVoiceRecord(${job.id})"><i class="bi bi-mic"></i> Record Voice</button>
    <span id="dc-voice-status-${job.id}" style="font-size:.72rem;color:#64748b">${_lastVoicePath ? '<i class="bi bi-check-circle" style="color:#16a34a"></i> Voice recorded' : 'No voice note'}</span>
  </div></div>`;

  // ── Company Footer ──
  html += `<div style="display:flex;justify-content:space-between;gap:10px;border-top:1px solid #99f6e4;padding-top:8px;margin-top:4px;font-size:.62rem;color:#64748b">
    <span>${esc(APP_FOOTER_LEFT || '')}</span>
    <span>${esc(APP_FOOTER_RIGHT || '')}</span>
  </div>`;

  // QR code at top
  if (viewQrDataUrl) {
    html = `<div class="dc-op-section" style="margin-bottom:0"><div class="dc-op-b" style="display:flex;align-items:center;justify-content:space-between;gap:14px">
      <div>
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.05em">Job Card QR</div>
        <div style="font-size:.74rem;color:#475569">Scan to open this job card on mobile/desktop</div>
      </div>
      <div style="text-align:center">
        <img src="${viewQrDataUrl}" alt="Job QR" style="width:96px;height:96px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px">
      </div>
    </div></div>` + html;
  }

  document.getElementById('dm-body').innerHTML = '<div class="dc-op-form">' + html + '</div>';
  updateTimers();

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += `<button class="dc-action-btn dc-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Job Card Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (IS_OPERATOR_VIEW) {
    if (mode === 'complete' && sts === 'Running' && !timerActive) {
      fHtml += `<button class="dc-action-btn dc-btn-complete" onclick="submitAndClose(${job.id})" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
    } else if (sts === 'Pending') {
      fHtml += `<button class="dc-action-btn dc-btn-start" onclick="startJobWithTimer(${job.id})" style="background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)"><i class="bi bi-play-fill"></i> Start Job</button>`;
    } else if (sts === 'Running' && timerState === 'paused') {
      fHtml += `<button class="dc-action-btn dc-btn-start" onclick="startJobWithTimer(${job.id})" style="background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)"><i class="bi bi-play-circle"></i> Again Start</button>`;
    } else if (sts === 'Running' && timerActive) {
      fHtml += `<button class="dc-action-btn dc-btn-start" onclick="resumeRunningDCTimer(${job.id})" style="background:var(--dc-brand);color:#fff;border-color:var(--dc-brand)"><i class="bi bi-play-circle"></i> Open Timer</button>`;
    } else if (sts === 'Running') {
      fHtml += `<button class="dc-action-btn dc-btn-complete" onclick="openJobDetail(${job.id},'complete')" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete</button>`;
    }
  }
  if (IS_ADMIN) {
    fHtml += `<button class="dc-action-btn dc-btn-start" onclick="regenerateJobCard(${job.id})" title="Regenerate"><i class="bi bi-arrow-repeat"></i> Regenerate</button>`;
    fHtml += `<button class="dc-action-btn dc-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  }
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  // Disable form fields unless in complete mode
  setTimeout(() => {
    const form = document.getElementById('dm-operator-form');
    if (form) {
      form.querySelectorAll('input, select, textarea').forEach(el => {
        el.disabled = !(mode === 'complete' && sts === 'Running');
      });
    }
  }, 50);

  document.getElementById('dcDetailModal').classList.add('active');
}

function closeDetail() {
  document.getElementById('dcDetailModal').classList.remove('active');
}
document.getElementById('dcDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});

// ═══ DELETE JOB ═══
async function deleteJob(id) {
  if (!IS_ADMIN) { alert('Access denied. Only system admin can delete job cards.'); return; }
  if (!confirm('Delete this job card and reset linked paper stock, planning status?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'delete_job'); fd.append('job_id', id);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else if (data.blocked_jobs && data.blocked_jobs.length) {
      const rows = data.blocked_jobs.map(b => `${b.job_no || ('ID ' + b.id)} [${b.status}]`).join('\n');
      alert((data.error || 'Delete blocked') + '\n\n' + rows);
    }
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ═══ REGENERATE JOB CARD ═══
async function regenerateJobCard(id) {
  if (!IS_ADMIN) { alert('Access denied.'); return; }
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) { alert('Job not found.'); return; }
  const reason = prompt('Reason for regeneration (required):', 'Die correction / planning update');
  if (reason === null) return;
  const reasonText = String(reason || '').trim();
  if (!reasonText) { alert('Reason is required.'); return; }
  const notesAppend = prompt('Describe what changed (optional):', '');
  if (notesAppend === null) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF); fd.append('action', 'regenerate_job_card'); fd.append('job_id', String(id));
  fd.append('reason', reasonText); fd.append('notes_append', String(notesAppend || '').trim());
  fd.append('changes_json', JSON.stringify({}));

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { alert('Regenerate failed: ' + (data.error || 'Unknown error')); return; }
    alert('Job card regenerated successfully. Status reset to Pending.');
    location.reload();
  } catch (err) { alert('Network error: ' + err.message); }
}

// ═══ PRINT MODE CHOOSER ═══
function choosePrintMode() {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `<div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center;font-family:'Segoe UI',Arial,sans-serif">
      <div style="font-size:1.1rem;font-weight:900;color:#0f172a;margin-bottom:4px">Print Mode</div>
      <div style="font-size:.78rem;color:#64748b;margin-bottom:20px">Select print mode for best output quality</div>
      <div style="display:flex;gap:12px;justify-content:center">
        <button id="_pm_color" style="flex:1;padding:16px 12px;border:2px solid #0ea5a4;border-radius:12px;background:linear-gradient(135deg,#f0fdfa,#ccfbf1);cursor:pointer;transition:all .15s">
          <div style="font-size:1.5rem;margin-bottom:6px">🎨</div>
          <div style="font-weight:800;font-size:.88rem;color:#0f766e">Color</div>
          <div style="font-size:.65rem;color:#64748b;margin-top:2px">Full color print</div>
        </button>
        <button id="_pm_bw" style="flex:1;padding:16px 12px;border:2px solid #64748b;border-radius:12px;background:linear-gradient(135deg,#f8fafc,#e2e8f0);cursor:pointer;transition:all .15s">
          <div style="font-size:1.5rem;margin-bottom:6px">⬛</div>
          <div style="font-weight:800;font-size:.88rem;color:#0f172a">Black & White</div>
          <div style="font-size:.65rem;color:#64748b;margin-top:2px">High contrast B&W</div>
        </button>
      </div>
      <button id="_pm_cancel" style="margin-top:16px;padding:8px 24px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b;font-size:.76rem;font-weight:700;cursor:pointer">Cancel</button>
    </div>`;
    document.body.appendChild(overlay);
    document.getElementById('_pm_color').onclick = () => { document.body.removeChild(overlay); resolve('color'); };
    document.getElementById('_pm_bw').onclick = () => { document.body.removeChild(overlay); resolve('bw'); };
    document.getElementById('_pm_cancel').onclick = () => { document.body.removeChild(overlay); resolve(null); };
    overlay.onclick = e => { if (e.target === overlay) { document.body.removeChild(overlay); resolve(null); } };
  });
}

function printBwTransform(html) {
  const map = {
    '0ea5a4':'000000','0f766e':'000000','14b8a6':'000000','0d9488':'000000',
    '166534':'000000','15803d':'000000','16a34a':'000000',
    '1e40af':'000000','5b21b6':'000000','6d28d9':'000000',
    'a16207':'000000','92400e':'000000','d97706':'333333',
    '9d174d':'000000','0891b2':'333333',
    'dcfce7':'e0e0e0','f0fdf4':'f0f0f0','d1fae5':'e0e0e0',
    'f0fdfa':'f0f0f0','ccfbf1':'e0e0e0','99f6e4':'cccccc',
    'dbeafe':'d8d8d8','ede9fe':'e0e0e0',
    'fef3c7':'e8e8e8','fffde7':'f0f0f0',
    'e0f7fa':'e8e8e8','fce4ec':'e8e8e8','eceff1':'e0e0e0','fee2e2':'e8e8e8',
    'bbf7d0':'999999','bfdbfe':'999999','c4b5fd':'999999',
    'd1e7dd':'999999','bae6fd':'999999','fcd34d':'999999',
    'cbd5e1':'aaaaaa','e2e8f0':'bbbbbb',
    'f8fafc':'f2f2f2','f1f5f9':'efefef',
  };
  let result = html;
  for (const [from, to] of Object.entries(map)) {
    result = result.replace(new RegExp('#' + from, 'gi'), '#' + to);
  }
  return result;
}

// ═══ PRINT CARD HTML ═══
function renderDCPrintCardHtml(job, qrDataUrl) {
  const extra = job.extra_data_parsed || {};
  const created = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const started = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = Number(job.duration_minutes);
  const durText = Number.isFinite(dur) ? `${Math.floor(dur/60)}h ${dur%60}m` : '—';

  const qrHtml = qrDataUrl
    ? `<div style="text-align:center;margin-left:12px"><img src="${qrDataUrl}" style="width:90px;height:90px;display:block"><div style="font-size:.56rem;color:#64748b;margin-top:2px">Scan job card</div></div>`
    : '';

  const existingPhoto = extra.die_cutting_photo_url || '';
  const photoHtml = existingPhoto
    ? `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#a16207;background:#fef3c7;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Job Photo</div><div style="margin-bottom:6px"><img src="${esc(existingPhoto)}" style="max-width:300px;max-height:180px;border-radius:8px;border:1px solid #e2e8f0"></div></td></tr>`
    : '';

  const previewUrl = job.planning_image_url || '';
  const previewHtml = previewUrl
    ? `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#0f766e;background:#ccfbf1;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Job Preview</div><div style="margin-bottom:6px"><img src="${esc(previewUrl)}" style="max-width:300px;max-height:180px;border-radius:8px;border:1px solid #e2e8f0"></div></td></tr>`
    : '';
  const dimensions = getJobSizeDimensions(job);
  const weightText = dimensions.weight ? `${dimensions.weight} mm` : '—';
  const heightText = dimensions.height ? `${dimensions.height} mm` : '—';
  const weightHeightRow = DC_SHOW_WEIGHT_HEIGHT_FIELDS
    ? `<tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">${esc(DC_WEIGHT_LABEL || 'Weight')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(weightText)}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">${esc(DC_HEIGHT_LABEL || 'Height')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(heightText)}</td></tr>`
    : '';

  return `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:20px;max-width:760px;margin:0 auto;color:#0f172a">
    <div style="border:2px solid #0f766e;border-radius:12px;overflow:hidden">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:12px 14px;background:#f0fdfa;border-bottom:2px solid #0f766e">
        <div>
          ${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:36px;margin-bottom:4px;display:block">` : ''}
          <div style="font-weight:900;font-size:1.02rem;letter-spacing:.02em">${esc(COMPANY.name || 'Company')}</div>
          <div style="font-size:.66rem;color:#475569">${esc(COMPANY.address || '')}</div>
          ${COMPANY.gst ? `<div style="font-size:.62rem;color:#64748b">GST: ${esc(COMPANY.gst)}</div>` : ''}
        </div>
        <div style="display:flex;align-items:flex-start">
          <div style="text-align:right">
            <div style="font-size:.66rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#475569">Department</div>
            <div style="font-size:.92rem;font-weight:900;color:#0f766e">Die-Cutting</div>
            <div style="font-size:1.2rem;font-weight:900;color:#0f172a;line-height:1.1">${esc(job.job_no || '—')}</div>
            <div style="font-size:.6rem;color:#64748b">Generated: ${esc(created)}</div>
          </div>
          ${qrHtml}
        </div>
      </div>
      <div style="padding:8px 14px;background:#ccfbf1;border-bottom:1px solid #99f6e4;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:.76rem;font-weight:900;color:#0f766e">Job: ${esc(job.planning_job_name || '—')}</div>
        <div style="font-size:.68rem;font-weight:700;color:#0d9488">Priority: ${esc(job.planning_priority || 'Normal')}</div>
      </div>
      <div style="padding:10px 12px;border-bottom:1px solid #cbd5e1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;font-size:.66rem">
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Status</div><div style="font-weight:800">${esc(job.status || '—')}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Started</div><div style="font-weight:700">${esc(started)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Completed</div><div style="font-weight:700">${esc(completed)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Duration</div><div style="font-weight:700">${esc(durText)}</div></div>
      </div>
      <div style="padding:10px 12px">
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#0f766e;background:#ccfbf1;padding:5px 8px;border-radius:4px">${esc(DC_DETAILS_SECTION_LABEL || 'Die-Cutting Details')}</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;margin-bottom:10px">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_job_name || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job No</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-weight:700;color:#0f766e">${esc(job.job_no || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Client Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_client_name || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Material</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_material || job.paper_type || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Die Size</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-weight:700;color:#0f766e">${esc(job.planning_die_size || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Repeat</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_repeat || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Order Qty</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_order_qty || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Roll No</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.roll_no || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Total Length</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.length_mtr || '—') + ' m')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">GSM</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.gsm || '—')}</td></tr>
          ${weightHeightRow}
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">${esc(DC_PAPER_WIDTH_LABEL || 'Width (mm)')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.width_mm || '—') + ' mm')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800"></td><td style="padding:5px 7px;border:1px solid #cbd5e1"></td></tr>
        </table>
        ${previewHtml ? `<table style="width:100%">${previewHtml}</table>` : ''}
        ${(() => {
          const rawPlanQty = String(job.planning_order_qty || '').trim();
          const rawProducedQty = String(
            String(DC_PRODUCED_QTY_SOURCE || '').toLowerCase() === 'current'
              ? (extra.barcode_total_qty_pcs ?? extra.total_qty_pcs ?? extra.die_cutting_total_qty_pcs ?? extra.actual_qty ?? '')
              : (job.prev_actual_qty || '')
          ).trim();
          const hasPlanQty = rawPlanQty !== '';
          const hasProducedQty = rawProducedQty !== '';
          const planQty = Number(rawPlanQty || 0);
          const producedQty = Number(rawProducedQty || 0);
          if (!hasPlanQty && !hasProducedQty) return '';
          const canCompare = hasPlanQty && hasProducedQty;
          const diff = canCompare ? (producedQty - planQty) : 0;
          const isExtra = canCompare && diff > 0;
          const isShort = canCompare && diff < 0;
          const diffLabel = canCompare ? (isExtra ? 'Extra' : (isShort ? 'Shortage' : 'Matched')) : 'Difference';
          const diffColor = isExtra ? '#16a34a' : (isShort ? '#dc2626' : '#475569');
          const pctText = canCompare && planQty > 0
            ? (((diff / planQty) * 100) > 0 ? '+' : '') + ((diff / planQty) * 100).toFixed(1) + '%'
            : '';
          return `<div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#4338ca;background:#eef2ff;padding:5px 8px;border-radius:4px;margin-top:8px">${esc(DC_COMPARE_SECTION_TITLE || 'Printing Production vs Plan')}</div>
          <table style="width:100%;border-collapse:collapse;font-size:.72rem;margin-bottom:10px">
            <tr>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:16%">Planning Qty</td>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;width:17%;font-weight:700">${hasPlanQty ? planQty.toLocaleString() + ' Pcs' : '—'}</td>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:16%">${esc(DC_PRODUCED_QTY_LABEL || 'Printing Produced')}</td>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;width:17%;font-weight:700;color:#3b82f6">${hasProducedQty ? producedQty.toLocaleString() + ' Pcs' : '—'}</td>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;background:${canCompare ? (isExtra?'#f0fdf4':(isShort?'#fef2f2':'#f8fafc')) : '#f8fafc'};font-weight:800;width:16%;color:${diffColor}">${diffLabel}</td>
              <td style="padding:5px 7px;border:1px solid #cbd5e1;font-weight:900;color:${diffColor};width:18%">${canCompare ? (Math.abs(diff).toLocaleString() + ' Pcs' + (pctText ? ' (' + pctText + ')' : '')) : '—'}</td>
            </tr>
          </table>`;
        })()}
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#0f766e;background:#ccfbf1;padding:5px 8px;border-radius:4px;margin-top:8px">Execution Summary</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Total Qty (Pcs)</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.die_cutting_total_qty_pcs || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Wastage (Pcs)</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.die_cutting_wastage_pcs || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Wastage (Mtr)</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.die_cutting_wastage_mtr || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Notes</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.die_cutting_notes_text || '—')}</td></tr>
        </table>
        ${photoHtml ? `<table style="width:100%">${photoHtml}</table>` : ''}
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;border-top:2px solid #0f766e;background:#f0fdfa">
        <div style="font-size:.68rem;color:#475569">Operator Signature: _____________________</div>
        <div style="font-size:.68rem;color:#475569">Supervisor Signature: _____________________</div>
      </div>
      <div style="padding:6px 12px;font-size:.58rem;color:#64748b;display:flex;justify-content:space-between;border-top:1px solid #99f6e4">
        <span>Document: <?= addslashes($dcDocumentTitle) ?> | ${esc(COMPANY.name || '')}</span>
        <span>Printed at ${esc(new Date().toLocaleString())}</span>
      </div>
    </div>
  </div>`;
}

// ═══ PRINT JOB CARD ═══
async function printJobCard(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const mode = await choosePrintMode();
  if (!mode) return;
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  let html = renderDCPrintCardHtml(job, qrDataUrl);
  if (mode === 'bw') html = printBwTransform(html);
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${html}</body></html>`);
  w.document.close(); w.focus(); setTimeout(() => w.print(), 400);
}

// ═══ MULTI-SELECT BULK ═══
function dcUpdateBulkBar() {
  const checked = document.querySelectorAll('.dc-select-check:checked');
  const bar = document.getElementById('dcBulkBar');
  const countEl = document.getElementById('dcSelectedCount');
  checked.forEach(cb => { const card = cb.closest('.dc-card'); if (card) card.classList.toggle('dc-selected', cb.checked); });
  document.querySelectorAll('.dc-select-check:not(:checked)').forEach(cb => { const card = cb.closest('.dc-card'); if (card) card.classList.remove('dc-selected'); });
  if (checked.length > 0) { bar.style.display = 'flex'; countEl.textContent = checked.length; } else { bar.style.display = 'none'; }
}

function dcSelectAll() {
  document.querySelectorAll('.dc-card:not([style*="display: none"]):not([style*="display:none"]) .dc-select-check').forEach(cb => cb.checked = true);
  dcUpdateBulkBar();
}

function dcDeselectAll() {
  document.querySelectorAll('.dc-select-check').forEach(cb => cb.checked = false);
  dcUpdateBulkBar();
}

function dcBulkPrint() {
  const checkedIds = Array.from(document.querySelectorAll('.dc-select-check:checked')).map(cb => cb.dataset.jobId);
  if (!checkedIds.length) { alert('No job cards selected'); return; }
  const jobs = checkedIds.map(id => ALL_JOBS.find(j => j.id == id)).filter(Boolean);
  if (!jobs.length) return;
  (async function() {
    const mode = await choosePrintMode();
    if (!mode) return;
    let pages = '';
    for (let idx = 0; idx < jobs.length; idx++) {
      const job = jobs[idx];
      const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
      const qrDataUrl = await generateQR(qrUrl);
      const pb = idx < jobs.length - 1 ? 'page-break-after:always;' : '';
      let cardHtml = renderDCPrintCardHtml(job, qrDataUrl);
      if (mode === 'bw') cardHtml = printBwTransform(cardHtml);
      pages += `<div style="${pb}">${cardHtml}</div>`;
    }
    const w = window.open('', '_blank', 'width=820,height=920');
    w.document.write('<!DOCTYPE html><html><head><title>Bulk Print - ' + jobs.length + ' <?= addslashes($dcBulkPrintTitle) ?></title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>' + pages + '</body></html>');
    w.document.close(); w.focus(); setTimeout(() => w.print(), 400);
  })();
}

// ═══ QR GENERATOR ═══
function generateQR(text) {
  return new Promise(function(resolve) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden';
    document.body.appendChild(el);
    const inner = document.createElement('div');
    el.appendChild(inner);
    try {
      new QRCode(inner, { text: text, width: 160, height: 160, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    } catch(e) { document.body.removeChild(el); resolve(''); return; }
    setTimeout(function() {
      const canvas = inner.querySelector('canvas');
      const img = inner.querySelector('img');
      let url = '';
      if (canvas) url = canvas.toDataURL('image/png');
      else if (img && img.src && img.src.startsWith('data:')) url = img.src;
      document.body.removeChild(el);
      resolve(url);
    }, 150);
  });
}

// ═══ INIT ═══
(function(){
  const autoId = new URLSearchParams(window.location.search).get('auto_job');
  if (autoId) setTimeout(function(){ try { openJobDetail(parseInt(autoId)); } catch(e){} }, 600);
})();

// Default filter on page load can be overridden by wrapper modules.
(function(){
  const defaultFilter = normalizeFilterStatus(DC_DEFAULT_FILTER_RAW || 'Pending');
  const targetBtn = findFilterButton(defaultFilter);
  if (targetBtn) {
    filterJobs(defaultFilter, targetBtn);
    if (DC_AUTO_FALLBACK_TO_ALL_ON_EMPTY_DEFAULT && String(defaultFilter).toLowerCase() !== 'all' && getVisibleActiveCardCount() === 0) {
      const allBtn = findFilterButton('all');
      if (allBtn) filterJobs('all', allBtn);
    }
    return;
  }
  const allBtn = findFilterButton('all');
  if (allBtn) filterJobs('all', allBtn);
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
