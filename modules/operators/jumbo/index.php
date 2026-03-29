<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle = 'Machine Jumbo Operator';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$footerErpName = getErpDisplayName((string)$companyName);
$appFooterLeft = 'Version : ' . APP_VERSION;
$appFooterRight = '© ' . date('Y') . ' ' . $footerErpName . ' • ERP Master System v' . APP_VERSION . ' | @ Developed by Mriganka Bhusan Debnath';

// Load Jumbo jobs from DB so new auto-slitting cards appear immediately.
$activeJobs = [];
$historyJobs = [];

$db->query("CREATE TABLE IF NOT EXISTS job_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  request_type VARCHAR(50) NOT NULL DEFAULT 'jumbo_roll_update',
  payload_json LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  requested_by INT NULL,
  requested_by_name VARCHAR(120) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_by_name VARCHAR(120) NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jcr_job_id (job_id),
  INDEX idx_jcr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$jobsStmt = $db->prepare("\n  SELECT j.*,\n         ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,\n         ps.status AS roll_status, ps.lot_batch_no,\n         p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,\n         COALESCE(req.pending_count, 0) AS pending_change_requests\n  FROM jobs j\n  LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no\n  LEFT JOIN planning p ON j.planning_id = p.id\n  LEFT JOIN (\n    SELECT job_id, COUNT(*) AS pending_count\n    FROM job_change_requests\n    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'\n    GROUP BY job_id\n  ) req ON req.job_id = j.id\n  WHERE j.job_type = 'Slitting'\n    AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')\n  ORDER BY j.created_at DESC, j.id DESC\n");
$jobsStmt->execute();
$allJumboRows = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Collect all roll numbers for live data lookup (multi-parent support)
$allRollNos = [];
foreach ($allJumboRows as $row) {
  $extra = json_decode((string)($row['extra_data'] ?? '{}'), true) ?: [];
  $jobRoll = trim((string)($row['roll_no'] ?? ''));
  if ($jobRoll !== '') $allRollNos[$jobRoll] = true;
  $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
  if ($parentRoll !== '') $allRollNos[$parentRoll] = true;
  $parentRollsRaw = $extra['parent_rolls'] ?? [];
  if (is_string($parentRollsRaw)) {
    $parentRollsRaw = preg_split('/\s*,\s*/', trim($parentRollsRaw), -1, PREG_SPLIT_NO_EMPTY);
  }
  if (is_array($parentRollsRaw)) {
    foreach ($parentRollsRaw as $pr) {
      $prn = trim((string)$pr);
      if ($prn !== '') $allRollNos[$prn] = true;
    }
  }
  foreach (['child_rolls', 'stock_rolls'] as $bucket) {
    $rows2 = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
    foreach ($rows2 as $r) {
      $rn = trim((string)($r['roll_no'] ?? ''));
      if ($rn !== '') $allRollNos[$rn] = true;
      $prn = trim((string)($r['parent_roll_no'] ?? ''));
      if ($prn !== '') $allRollNos[$prn] = true;
    }
  }
}
$rollMap = [];
if (!empty($allRollNos)) {
  $rollNos = array_values(array_keys($allRollNos));
  $ph = implode(',', array_fill(0, count($rollNos), '?'));
  $types = str_repeat('s', count($rollNos));
  $sql = "SELECT roll_no, remarks, status, width_mm, length_mtr, gsm, weight_kg, paper_type, company FROM paper_stock WHERE roll_no IN ($ph)";
  $rmStmt = $db->prepare($sql);
  if ($rmStmt) {
    $rmStmt->bind_param($types, ...$rollNos);
    $rmStmt->execute();
    $rmRows = $rmStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rmRows as $r) {
      $k = (string)($r['roll_no'] ?? '');
      if ($k === '') continue;
      $rollMap[$k] = [
          'remarks'    => (string)($r['remarks'] ?? ''),
          'status'     => (string)($r['status'] ?? ''),
          'width_mm'   => (float)($r['width_mm'] ?? 0),
          'length_mtr' => (float)($r['length_mtr'] ?? 0),
          'gsm'        => (float)($r['gsm'] ?? 0),
          'weight_kg'  => (float)($r['weight_kg'] ?? 0),
          'paper_type' => (string)($r['paper_type'] ?? ''),
          'company'    => (string)($r['company'] ?? ''),
      ];
    }
  }
}

foreach ($allJumboRows as $row) {
  $extra = json_decode((string)($row['extra_data'] ?? '{}'), true) ?: [];
  $row['extra_data_parsed'] = $extra;
  $row['live_roll_map'] = [];
  // Build live_roll_map for this job
  $jobRoll = trim((string)($row['roll_no'] ?? ''));
  if ($jobRoll !== '' && isset($rollMap[$jobRoll])) {
    $row['live_roll_map'][$jobRoll] = $rollMap[$jobRoll];
  }
  $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
  if ($parentRoll !== '' && isset($rollMap[$parentRoll])) {
    $row['live_roll_map'][$parentRoll] = $rollMap[$parentRoll];
  }
  $parentRollsRaw = $extra['parent_rolls'] ?? [];
  if (is_string($parentRollsRaw)) {
    $parentRollsRaw = preg_split('/\s*,\s*/', trim($parentRollsRaw), -1, PREG_SPLIT_NO_EMPTY);
  }
  if (is_array($parentRollsRaw)) {
    foreach ($parentRollsRaw as $pr) {
      $prn = trim((string)$pr);
      if ($prn !== '' && isset($rollMap[$prn])) {
        $row['live_roll_map'][$prn] = $rollMap[$prn];
      }
    }
  }
  foreach (['child_rolls', 'stock_rolls'] as $bucket) {
    $rows2 = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
    foreach ($rows2 as &$r2) {
      $rn = trim((string)($r2['roll_no'] ?? ''));
      if ($rn !== '' && isset($rollMap[$rn])) {
        $row['live_roll_map'][$rn] = $rollMap[$rn];
        $r2['remarks_live'] = (string)($rollMap[$rn]['remarks'] ?? '');
        $r2['status_live']  = (string)($rollMap[$rn]['status'] ?? '');
      }
    }
    unset($r2);
    $extra[$bucket] = $rows2;
  }
  $row['extra_data_parsed'] = $extra;
  $statusLower = strtolower(trim((string)($row['status'] ?? '')));
  if (in_array($statusLower, ['closed', 'finalized'], true)) {
    $historyJobs[] = $row;
  } else {
    $activeJobs[] = $row;
  }
}

// Notification count
$notifCount = 0;

// ════════════════════════════════════════════════════════════
// DYNAMIC COUNT CALCULATIONS FOR TOP SUMMARY
// ════════════════════════════════════════════════════════════

// Count all Jumbo jobs (active + history for "Job Details" = ALL filter)
$totalCountQuery = $db->prepare("
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND deleted_at IS NULL
");
$totalCountQuery->execute();
$totalCount = $totalCountQuery->get_result()->fetch_assoc()['cnt'];

// Count Pending status
$pendingQuery = $db->prepare("
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND status = 'Pending' AND deleted_at IS NULL
");
$pendingQuery->execute();
$pendingCount = $pendingQuery->get_result()->fetch_assoc()['cnt'];

// Count Running status
$runningQuery = $db->prepare("
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND status = 'Running' AND deleted_at IS NULL
");
$runningQuery->execute();
$runningCount = $runningQuery->get_result()->fetch_assoc()['cnt'];

// Count Hold status (includes Hold, Hold for Payment, Hold for Approval)
$holdQuery = $db->prepare("
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' 
    AND (LOWER(status) = 'hold' 
         OR LOWER(status) = 'hold for payment' 
         OR LOWER(status) = 'hold for approval')
    AND deleted_at IS NULL
");
$holdQuery->execute();
$holdCount = $holdQuery->get_result()->fetch_assoc()['cnt'];

// Count Finished status (Closed, Finalized, Completed, etc.)
$finishedQuery = $db->prepare("
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' 
    AND (LOWER(status) IN ('closed', 'finalized', 'completed', 'finished', 'qc passed'))
    AND deleted_at IS NULL
");
$finishedQuery->execute();
$finishedCount = $finishedQuery->get_result()->fetch_assoc()['cnt'];

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Operator</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Machine Operators</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Jumbo Operator</span>
</div>

<style>
:root{--jc-brand:#0ea5a4;--jc-brand-dim:rgba(14,165,164,.1);--jc-orange:#f59e0b;--jc-blue:#0f766e;--jc-red:#dc2626;--jc-purple:#2563eb}
.jc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.jc-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.jc-header h1 i{font-size:1.6rem;color:var(--jc-brand)}
.jc-header-meta{font-size:.75rem;color:#64748b;font-weight:600}
.jc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.jc-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .15s;cursor:pointer}
.jc-stat:hover{box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px)}
.jc-stat.active{background:var(--jc-brand-dim);border-color:var(--jc-brand);box-shadow:0 4px 16px rgba(34,197,94,.15)}
.jc-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.jc-stat-val{font-size:1.5rem;font-weight:900;line-height:1}
.jc-stat-label{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.jc-filters{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.jc-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:240px;outline:none;transition:border .15s}
.jc-search:focus{border-color:var(--jc-brand)}
.jc-filter-btn{padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.jc-filter-btn.active{background:var(--jc-brand);border-color:var(--jc-brand);color:#fff}
.jc-tabs{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.jc-tab-btn{padding:7px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:999px;cursor:pointer;color:#64748b;transition:all .15s}
.jc-tab-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.jc-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.62rem;margin-left:6px}
.jc-tab-btn.active .jc-tab-count{background:rgba(255,255,255,.2);color:#fff}
.jc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px}
.jc-card{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden;transition:all .2s;cursor:pointer}
.jc-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.jc-card-head{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border,#e2e8f0);background:linear-gradient(135deg,#f8fafc,#fff)}
.jc-card-head .jc-jobno{font-weight:900;font-size:.85rem;color:#0f172a;display:flex;align-items:center;gap:8px}
.jc-card-head .jc-jobno i{color:var(--jc-brand);font-size:1rem}
.jc-card-body{padding:14px 18px}
.jc-card-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.78rem}
.jc-card-row .jc-label{color:#94a3b8;font-weight:700;font-size:.65rem;text-transform:uppercase;letter-spacing:.03em}
.jc-card-row .jc-value{font-weight:700;color:#1e293b}
.jc-card-foot{padding:12px 18px;border-top:1px solid var(--border,#e2e8f0);display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
.jc-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.jc-badge-queued{background:#f1f5f9;color:#64748b}
.jc-badge-pending{background:#fef3c7;color:#92400e}
.jc-badge-running{background:#dbeafe;color:#1e40af;animation:pulse-badge 2s infinite}
.jc-badge-completed{background:#dcfce7;color:#166534}
.jc-badge-slitting{background:#ede9fe;color:#6d28d9}
.jc-badge-urgent{background:#fee2e2;color:#991b1b}
.jc-badge-high{background:#ffedd5;color:#9a3412}
.jc-badge-normal{background:#e0f2fe;color:#075985}
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.6}}
.jc-action-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.jc-action-btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none;filter:grayscale(.2)}
.jc-btn-start{background:var(--jc-brand);color:#fff}
.jc-btn-start:hover{background:#7c3aed}
.jc-btn-start:disabled{opacity:.4;cursor:not-allowed}
.jc-btn-complete{background:#16a34a;color:#fff}
.jc-btn-complete:hover{background:#15803d}
.jc-btn-view{background:#f1f5f9;color:#475569;border:1px solid var(--border,#e2e8f0)}
.jc-btn-view:hover{background:#e2e8f0}
.jc-btn-print{background:#8b5cf6;color:#fff}
.jc-btn-print:hover{background:#7c3aed}
.jc-btn-delete{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
.jc-btn-delete:hover{background:#fecaca}
/* Upload area */
.jc-upload-zone{border:2px dashed #d1e7dd;border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:all .2s}
.jc-upload-zone:hover{border-color:var(--jc-brand);background:#f0fdf4}
.jc-upload-zone input[type=file]{display:none}
.jc-upload-preview{margin-top:8px}
.jc-upload-preview img{max-width:200px;max-height:150px;border-radius:8px;border:1px solid #e2e8f0}
.jc-time{font-size:.6rem;color:#94a3b8;font-weight:600}
.jc-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.jc-empty i{font-size:3rem;opacity:.3}
.jc-empty p{margin-top:12px;font-size:.9rem;font-weight:600}
.jc-timer{font-size:.75rem;font-weight:800;color:var(--jc-blue);font-family:'Courier New',monospace}
.jc-notif-badge{background:#ef4444;color:#fff;font-size:9px;font-weight:800;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-left:6px}
.jc-modal-tabs{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.jc-modal-tab{padding:6px 12px;border:1px solid #dbe3ea;background:#fff;border-radius:999px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;cursor:pointer}
.jc-modal-tab.active{background:#0f172a;color:#fff;border-color:#0f172a}
.jc-timing-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.jc-timing-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px}
.jc-timing-label{font-size:.58rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.jc-timing-value{font-size:.82rem;font-weight:800;color:#0f172a;margin-top:4px}
.jc-counter-box{background:linear-gradient(135deg,#0f766e,#0ea5a4);border-color:#0f766e;box-shadow:0 6px 18px rgba(15,118,110,.28)}
.jc-counter-box .jc-timing-label{color:rgba(255,255,255,.82)}
.jc-counter-box .jc-timing-value{color:#ffffff}
.jc-counter-box .jc-timer{font-size:1.28rem;line-height:1.15;font-weight:900;color:#ffffff;text-shadow:0 2px 8px rgba(0,0,0,.25);letter-spacing:.04em}
.jc-tab-panel{display:none}
.jc-tab-panel.active{display:block}
.jc-summary-card{background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin-bottom:16px;box-shadow:0 8px 20px rgba(15,23,42,.04)}
.jc-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.jc-summary-item{background:#f8fafc;border-radius:10px;padding:12px 14px}
.jc-summary-item .sl{font-size:.62rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.jc-summary-item .sv{font-size:.9rem;font-weight:800;color:#0f172a;margin-top:4px}
.jc-table-shell{border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 2px 10px rgba(15,23,42,.03)}
.jc-parent-shell{background:#f8fafc}
.jc-child-shell{background:#f1f5f9;margin-left:12px}
.jc-soft-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.74rem}
.jc-soft-table thead th{background:#e2e8f0;color:#334155;padding:12px 10px;text-align:left;font-weight:800;border-bottom:1px solid rgba(148,163,184,.35)}
.jc-soft-table tbody td{padding:11px 10px;color:#334155;border-bottom:1px solid rgba(148,163,184,.18)}
.jc-child-shell .jc-soft-table tbody td{color:#475569}
.jc-soft-table tbody tr:hover td{background:rgba(255,255,255,.5)}
.jc-action-bar{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%}
.jc-action-bar .jc-action-btn{justify-content:center;width:100%;padding:12px 14px;font-size:.76rem;border-radius:10px}
.jc-request-box{margin-top:14px;padding:12px 14px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}
.jc-request-state{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.jc-request-chip{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.jc-roll-check{margin-top:12px;padding:12px 14px;border-radius:10px;border:1px solid #dbe3ea;background:#f8fafc}
.jc-roll-check.ok{border-color:#bbf7d0;background:#f0fdf4}
.jc-roll-check.bad{border-color:#fecaca;background:#fef2f2}
.jc-roll-check .rk{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.jc-roll-check .rv{font-size:.82rem;font-weight:700;color:#0f172a;margin-top:4px}
.jc-roll-check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}

/* ── Detail Modal ── */
.jc-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.jc-modal-overlay.active{display:flex}
.jc-modal{background:#fff;border-radius:16px;max-width:720px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.jc-modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#f0fdf4,#fff);border-radius:16px 16px 0 0}
.jc-modal-header h2{font-size:1.1rem;font-weight:900;display:flex;align-items:center;gap:10px}
.jc-modal-header h2 i{color:var(--jc-brand)}
.jc-modal-body{padding:24px}
.jc-detail-section{margin-bottom:20px}
.jc-detail-section h3{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.jc-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
.jc-detail-item{display:flex;flex-direction:column;gap:2px}
.jc-detail-item .dl{font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.jc-detail-item .dv{font-size:.82rem;font-weight:700;color:#1e293b}
.jc-timeline{display:flex;gap:20px;flex-wrap:wrap}
.jc-timeline-item{display:flex;flex-direction:column;gap:2px}
.jc-timeline-item .tl-label{font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8}
.jc-timeline-item .tl-val{font-size:.75rem;font-weight:700;color:#1e293b}
.jc-timeline-item .tl-val.green{color:#16a34a}
.jc-timeline-item .tl-val.blue{color:#3b82f6}
.jc-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.jc-form-group{display:flex;flex-direction:column;gap:4px}
.jc-form-group label{font-size:.6rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
.jc-form-group input,.jc-form-group select,.jc-form-group textarea{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;font-weight:600;font-family:inherit}
.jc-form-group textarea{min-height:60px;resize:vertical}
.jc-form-group input:focus,.jc-form-group select:focus,.jc-form-group textarea:focus{outline:none;border-color:var(--jc-brand);box-shadow:0 0 0 2px rgba(34,197,94,.1)}
.jc-modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.jc-roll-pick-row{display:flex;gap:8px;align-items:center}
.jc-roll-pick-row input{flex:1}

/* Roll picker modal */
.jc-picker-modal{background:#fff;border-radius:14px;max-width:980px;width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.jc-picker-head{padding:16px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;position:sticky;top:0;background:#fff;z-index:2}
.jc-picker-title{font-size:.95rem;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.jc-picker-body{padding:14px 18px 16px}
.jc-picker-filters{display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:10px;align-items:end;margin-bottom:12px}
.jc-picker-filters .jc-form-group{margin:0}
.jc-picker-table-wrap{border:1px solid #e2e8f0;border-radius:10px;overflow:auto;max-height:54vh}
.jc-picker-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.75rem}
.jc-picker-table th{position:sticky;top:0;background:#e2e8f0;color:#334155;padding:10px;border-bottom:1px solid rgba(148,163,184,.35);text-align:left;font-weight:800}
.jc-picker-table td{padding:9px 10px;border-bottom:1px solid rgba(148,163,184,.18);color:#334155}
.jc-picker-empty{padding:18px;text-align:center;color:#64748b;font-size:.8rem;font-weight:700}

/* Print */
@media print{
  .no-print,.breadcrumb,.page-header,.jc-modal-overlay{display:none!important}
  .jc-print-area{display:block!important}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
.jc-print-area{display:none}
/* Timer overlay (Flexo-style) */
.jc-timer-overlay{position:fixed;inset:0;z-index:20000;background:rgba(15,23,42,.85);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;backdrop-filter:blur(4px)}
.jc-timer-jobinfo{text-align:center;color:#fff;font-size:1rem;font-weight:700}
.jc-timer-display{font-size:4rem;font-weight:900;color:#fff;font-family:'Courier New',monospace;letter-spacing:.06em;text-shadow:0 4px 24px rgba(0,0,0,.4)}
.jc-timer-actions{display:flex;gap:16px}
.jc-timer-actions button{padding:14px 32px;font-size:1rem;font-weight:800;border:none;border-radius:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-transform:uppercase}
.jc-timer-btn-cancel{background:#ef4444;color:#fff}
.jc-timer-btn-cancel:hover{background:#dc2626}
.jc-timer-btn-end{background:#16a34a;color:#fff}
.jc-timer-btn-end:hover{background:#15803d}
@media(max-width:600px){.jc-grid{grid-template-columns:1fr}.jc-stats{grid-template-columns:repeat(2,1fr)}.jc-detail-grid{grid-template-columns:1fr}.jc-form-row{grid-template-columns:1fr}.jc-summary-grid,.jc-timing-grid,.jc-action-bar,.jc-roll-check-grid,.jc-picker-filters{grid-template-columns:1fr}.jc-child-shell{margin-left:0}}
</style>

<div class="jc-header no-print">
  <div>
    <h1><i class="bi bi-person-workspace"></i> Machine Jumbo Operator
      <?php if ($notifCount > 0): ?><span class="jc-notif-badge"><?= $notifCount ?></span><?php endif; ?>
    </h1>
  </div>
  <div style="display:flex;gap:8px">
    <button class="jc-action-btn jc-btn-view" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<?php
$activeCount = $totalCount;
$historyCount = $finishedCount;
?>
<div class="jc-stats no-print">
  <div class="jc-stat active" data-filter="all" onclick="filterFromStat('all')">
    <div class="jc-stat-icon" style="background:#f0fdf4;color:#22c55e"><i class="bi bi-boxes"></i></div>
    <div><div class="jc-stat-val"><?= $totalCount ?></div><div class="jc-stat-label">Job Detials</div></div>
  </div>
  <div class="jc-stat" data-filter="Pending" onclick="filterFromStat('Pending')">
    <div class="jc-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="jc-stat-val"><?= $pendingCount ?></div><div class="jc-stat-label">Pending</div></div>
  </div>
  <div class="jc-stat" data-filter="Running" onclick="filterFromStat('Running')">
    <div class="jc-stat-icon" style="background:#e0e7ff;color:#6366f1"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="jc-stat-val"><?= $runningCount ?></div><div class="jc-stat-label">Running</div></div>
  </div>
  <div class="jc-stat" data-filter="Hold" onclick="filterFromStat('Hold')">
    <div class="jc-stat-icon" style="background:#fecdd3;color:#dc2626"><i class="bi bi-pause-circle-fill"></i></div>
    <div><div class="jc-stat-val"><?= $holdCount ?></div><div class="jc-stat-label">Hold</div></div>
  </div>
  <div class="jc-stat" data-filter="Finished" onclick="filterFromStat('Finished')">
    <div class="jc-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle"></i></div>
    <div><div class="jc-stat-val"><?= $finishedCount ?></div><div class="jc-stat-label">Finished</div></div>
  </div>
</div>

<div class="jc-tabs no-print">
  <button id="jcTabBtnActive" class="jc-tab-btn active" type="button" onclick="switchJumboTab('active')">Job Detials <span class="jc-tab-count"><?= $activeCount ?></span></button>
  <button id="jcTabBtnHistory" class="jc-tab-btn" type="button" onclick="switchJumboTab('history')">History <span class="jc-tab-count"><?= $historyCount ?></span></button>
</div>

<div id="jcPanelActive">

<div class="jc-filters no-print">
  <input type="text" class="jc-search" id="jcSearch" placeholder="Search by job no, roll, company&hellip;">
  <button class="jc-filter-btn active" onclick="filterJobs('all',this)">All</button>
  <button class="jc-filter-btn" onclick="filterJobs('Pending',this)">Pending</button>
  <button class="jc-filter-btn" onclick="filterJobs('Running',this)">Running</button>
  <button class="jc-filter-btn" onclick="filterJobs('Hold',this)">Hold</button>
  <button class="jc-filter-btn" onclick="filterJobs('Finished',this)">Finished</button>
</div>

<div class="jc-grid no-print" id="jcGrid">
<?php if (empty($activeJobs) && empty($historyJobs)): ?>
  <div class="jc-empty" style="grid-column:1/-1">
    <i class="bi bi-inbox"></i>
    <p>No active pending jumbo jobs.</p>
  </div>
<?php else: ?>
  <?php foreach ($activeJobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Pending'=>'pending', 'Running'=>'running', 'Closed','Finalized'=>'completed', default=>'pending' };
    $rSts = $job['roll_status'] ?? '';
    $rStsClass = strtolower(str_replace(' ', '', $rSts)) === 'slitting' ? 'slitting' : $stsClass;
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
  ?>
  <div class="jc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openJobDetail(<?= $job['id'] ?>)">
    <div class="jc-card-head">
      <div class="jc-jobno"><i class="bi bi-box-seam"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <?php if ((int)($job['pending_change_requests'] ?? 0) > 0): ?>
          <span class="jc-request-chip">Request Pending</span>
        <?php endif; ?>
        <span class="jc-badge jc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">JMB No</span><span class="jc-value" style="color:var(--jc-brand)"><?= e($job['job_no']) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['planning_job_name'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Priority</span><span class="jc-value"><?= e($job['planning_priority'] ?? 'Normal') ?></span></div>
    </div>
    <?php $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0; ?>
    <?php if ($sts === 'Running' && $startedTs): ?>
    <div class="jc-card-row"><span class="jc-label">Elapsed</span><span class="jc-timer" data-started="<?= $startedTs ?>" style="color:var(--jc-blue);font-weight:700">00:00:00</span></div>
    <?php endif; ?>
    <div class="jc-card-foot">
      <div class="jc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <?php if ($sts === 'Pending'): ?>
          <button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
        <?php elseif ($sts === 'Running'): ?>
          <button class="jc-action-btn jc-btn-complete" onclick="openJobDetail(<?= $job['id'] ?>,'complete')"><i class="bi bi-check-lg"></i> Complete</button>
        <?php else: ?>
          <button class="jc-action-btn jc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($historyJobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Pending'=>'pending', 'Running'=>'running', 'Closed','Finalized'=>'completed', default=>'pending' };
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
  ?>
  <div class="jc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" data-finished-only="1" style="display:none" onclick="openJobDetail(<?= $job['id'] ?>)">
    <div class="jc-card-head">
      <div class="jc-jobno"><i class="bi bi-box-seam"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="jc-badge jc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">JMB No</span><span class="jc-value" style="color:var(--jc-brand)"><?= e($job['job_no']) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['planning_job_name'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Priority</span><span class="jc-value"><?= e($job['planning_priority'] ?? 'Normal') ?></span></div>
    </div>
    <div class="jc-card-foot">
      <div class="jc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
        <button class="jc-action-btn jc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div id="jcPanelHistory" style="display:none">
<div class="card no-print" style="margin-top:18px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-clock-history"></i> Jumbo History (Closed / Finalized)</span>
    <span style="font-size:.72rem;color:#64748b;font-weight:700"><?= $historyCount ?> records</span>
  </div>
  <div style="overflow:auto">
    <table class="jc-table" style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead>
        <tr>
          <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Job No</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Plan</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Roll</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Status</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0">Closed At</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($historyJobs)): ?>
        <tr><td colspan="5" style="padding:12px;color:#94a3b8">No closed/finalized jumbo jobs yet.</td></tr>
      <?php else: ?>
        <?php foreach ($historyJobs as $h): ?>
          <tr>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;font-weight:700"><?= e($h['job_no']) ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['planning_job_name'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['roll_no'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['status']) ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['completed_at'] ?? $h['updated_at'] ?? $h['created_at'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- ═══ DETAIL MODAL ═══ -->
<div class="jc-modal-overlay" id="jcDetailModal">
  <div class="jc-modal">
    <div class="jc-modal-header">
      <h2><i class="bi bi-box-seam"></i> <span id="dm-jobno"></span></h2>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="dm-status-badge" class="jc-badge"></span>
        <button class="jc-action-btn jc-btn-view" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div class="jc-modal-body" id="dm-body">
      <!-- Populated by JS -->
    </div>
    <div class="jc-modal-footer" id="dm-footer">
      <!-- Action buttons populated by JS -->
    </div>
  </div>
</div>

<!-- ═══ ROLL PICKER MODAL ═══ -->
<div class="jc-modal-overlay" id="dmRollPickerModal">
  <div class="jc-picker-modal">
    <div class="jc-picker-head">
      <div class="jc-picker-title"><i class="bi bi-search"></i> Roll Suggestion Picker</div>
      <button class="jc-action-btn jc-btn-view" type="button" onclick="closeRollPicker()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="jc-picker-body">
      <div class="jc-picker-filters">
        <div class="jc-form-group">
          <label>Search Roll / Material / Company</label>
          <input type="text" id="rp-search" placeholder="Type roll no, paper type, company..." oninput="loadRollSuggestions()">
        </div>
        <div class="jc-form-group">
          <label>Paper Type</label>
          <select id="rp-paper-filter" onchange="loadRollSuggestions()">
            <option value="">All Paper Types</option>
          </select>
        </div>
        <div class="jc-form-group">
          <label>Company</label>
          <select id="rp-company-filter" onchange="loadRollSuggestions()">
            <option value="">All Companies</option>
          </select>
        </div>
        <button class="jc-action-btn jc-btn-view" type="button" onclick="loadRollSuggestions()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
      <div class="jc-picker-table-wrap">
        <table class="jc-picker-table">
          <thead>
            <tr>
              <th>Roll No</th>
              <th>Paper Type</th>
              <th>Company</th>
              <th>Status</th>
              <th>Width</th>
              <th>Length</th>
              <th>GSM</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="rp-roll-list"></tbody>
        </table>
      </div>
      <div id="rp-empty" class="jc-picker-empty" style="display:none">No rolls found for selected filters.</div>
    </div>
  </div>
</div>

<!-- ═══ PRINT AREA (hidden, used for browser print) ═══ -->
<div class="jc-print-area" id="jcPrintArea"></div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const BASE_URL = '<?= BASE_URL ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const APP_FOOTER_LEFT = <?= json_encode($appFooterLeft, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const APP_FOOTER_RIGHT = <?= json_encode($appFooterRight, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode(array_values(array_merge($activeJobs, $historyJobs)), JSON_HEX_TAG|JSON_HEX_APOS) ?>;
let DM_ACTIVE_JOB_ID = 0;
let DM_ROLL_FILTERS_LOADED = false;
let DM_MODAL_LOCKED = false;
const DM_AUTO_REFRESH_MS = 45000;
let _timerInterval = null;
let _timerStart = null;
let _timerJobId = null;

function switchJumboTab(tab) {
  const activePanel = document.getElementById('jcPanelActive');
  const historyPanel = document.getElementById('jcPanelHistory');
  const activeBtn = document.getElementById('jcTabBtnActive');
  const historyBtn = document.getElementById('jcTabBtnHistory');

  if (tab === 'history') {
    activePanel.style.display = 'none';
    historyPanel.style.display = '';
    activeBtn.classList.remove('active');
    historyBtn.classList.add('active');
  } else {
    activePanel.style.display = '';
    historyPanel.style.display = 'none';
    activeBtn.classList.add('active');
    historyBtn.classList.remove('active');
  }
}

// ─── Filters ────────────────────────────────────────────────
function filterJobs(status, btn) {
  document.querySelectorAll('.jc-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  updateStatBoxes(status);
  document.querySelectorAll('.jc-card').forEach(card => {
    const cardStatus = (card.dataset.status || '').toLowerCase();
    const finishedOnly = card.dataset.finishedOnly === '1';
    if (status === 'all') {
      card.style.display = finishedOnly ? 'none' : '';
      return;
    }
    if (status === 'Finished') {
      const isFinished = ['finished', 'completed', 'closed', 'finalized', 'qc passed'].includes(cardStatus);
      card.style.display = isFinished ? '' : 'none';
      return;
    }
    if (finishedOnly) {
      card.style.display = 'none';
      return;
    }
    if (status === 'Hold') {
      const isHold = cardStatus === 'hold' || cardStatus === 'hold for payment' || cardStatus === 'hold for approval';
      card.style.display = isHold ? '' : 'none';
      return;
    }
    card.style.display = (cardStatus === status.toLowerCase()) ? '' : 'none';
  });
}

// ─── Trigger filter from stat box ───────────────────────────
function filterFromStat(status) {
  const filterBtns = document.querySelectorAll('.jc-filter-btn');
  let targetBtn = null;
  
  if (status === 'all') {
    targetBtn = filterBtns[0]; // First button is ALL
  } else {
    // Find button with matching text (case-insensitive)
    targetBtn = Array.from(filterBtns).find(btn => 
      btn.textContent.trim().split('\n')[0] === status
    );
  }
  
  if (targetBtn) {
    targetBtn.click();
  }
}

// ─── Update stat box highlights ─────────────────────────────
function updateStatBoxes(status) {
  document.querySelectorAll('.jc-stat').forEach(stat => {
    stat.classList.remove('active');
  });
  
  // Find matching stat box
  const statBox = document.querySelector(`.jc-stat[data-filter="${status}"]`);
  if (statBox) {
    statBox.classList.add('active');
  }
}

document.getElementById('jcSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.jc-card').forEach(card => {
    card.style.display = (card.dataset.search || '').includes(q) ? '' : 'none';
  });
});

// ─── Live timers for running jobs ────────────────────────────
function updateTimers() {
  document.querySelectorAll('.jc-timer[data-started]').forEach(el => {
    const started = parseInt(el.dataset.started);
    if (!started) return;
    const diff = Math.floor((Date.now() - started) / 1000);
    const h = String(Math.floor(diff/3600)).padStart(2,'0');
    const m = String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    el.textContent = h + ':' + m + ':' + s;
  });
}
setInterval(updateTimers, 1000);
updateTimers();

function canAutoRefreshOperatorPage() {
  const detailOpen = document.getElementById('jcDetailModal')?.classList.contains('active');
  const pickerOpen = document.getElementById('dmRollPickerModal')?.classList.contains('active');
  return !detailOpen && !pickerOpen && !DM_MODAL_LOCKED;
}

setInterval(function() {
  if (canAutoRefreshOperatorPage()) {
    location.reload();
  }
}, DM_AUTO_REFRESH_MS);

// ─── Status update ──────────────────────────────────────────
async function updateJobStatus(id, newStatus, options = {}) {
  const reloadOnSuccess = options.reloadOnSuccess !== false;
  if (!confirm('Set this job to ' + newStatus + '?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (reloadOnSuccess) location.reload();
      return true;
    }
    else alert('Error: ' + (data.error || 'Unknown'));
    return false;
  } catch (err) { alert('Network error: ' + err.message); return false; }
}

function getJobById(id) {
  return ALL_JOBS.find(j => j.id == id);
}

function closeRollPicker() {
  document.getElementById('dmRollPickerModal')?.classList.remove('active');
}

function renderRollSuggestionRows(rows) {
  const tbody = document.getElementById('rp-roll-list');
  const empty = document.getElementById('rp-empty');
  if (!tbody || !empty) return;

  if (!Array.isArray(rows) || rows.length === 0) {
    tbody.innerHTML = '';
    empty.style.display = '';
    return;
  }

  empty.style.display = 'none';
  tbody.innerHTML = rows.map(r => `
    <tr>
      <td style="font-weight:800;color:var(--jc-brand)">${esc(r.roll_no || '-')}</td>
      <td>${esc(r.paper_type || '-')}</td>
      <td>${esc(r.company || '-')}</td>
      <td>${esc(r.status || '-')}</td>
      <td>${esc(String(r.width_mm || '-'))}</td>
      <td>${esc(String(r.length_mtr || '-'))}</td>
      <td>${esc(String(r.gsm || '-'))}</td>
      <td><button type="button" class="jc-action-btn jc-btn-complete" onclick="selectRollFromPicker('${esc(String(r.roll_no || '')).replace(/'/g, '&#39;')}')">Select</button></td>
    </tr>
  `).join('');
}

function fillSelectOptions(selectId, values, allLabel, selectedValue) {
  const el = document.getElementById(selectId);
  if (!el) return;
  const current = selectedValue ?? el.value ?? '';
  let html = `<option value="">${allLabel}</option>`;
  (values || []).forEach(v => {
    const val = String(v || '').trim();
    if (!val) return;
    html += `<option value="${esc(val)}" ${current === val ? 'selected' : ''}>${esc(val)}</option>`;
  });
  el.innerHTML = html;
}

async function loadRollSuggestions() {
  const search = String(document.getElementById('rp-search')?.value || '').trim();
  const paperType = String(document.getElementById('rp-paper-filter')?.value || '').trim();
  const company = String(document.getElementById('rp-company-filter')?.value || '').trim();

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_suggestions');
    if (search) url.searchParams.set('q', search);
    if (paperType) url.searchParams.set('paper_type', paperType);
    if (company) url.searchParams.set('company', company);
    url.searchParams.set('limit', '150');

    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      renderRollSuggestionRows([]);
      return;
    }

    if (!DM_ROLL_FILTERS_LOADED) {
      fillSelectOptions('rp-paper-filter', data.paper_types || [], 'All Paper Types', paperType);
      fillSelectOptions('rp-company-filter', data.companies || [], 'All Companies', company);
      DM_ROLL_FILTERS_LOADED = true;
    }

    renderRollSuggestionRows(data.rolls || []);
  } catch (err) {
    renderRollSuggestionRows([]);
  }
}

function selectRollFromPicker(rollNo) {
  const input = document.getElementById('dm-parent-roll-input');
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!input || !job) return;
  input.value = String(rollNo || '').trim();
  DM_PARENT_ROLL_CHANGED = true;
  refreshRollPreview();
  lookupReplacementRoll(job);
  closeRollPicker();
}

async function openRollPicker() {
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!job) return;

  const modal = document.getElementById('dmRollPickerModal');
  if (!modal) return;
  modal.classList.add('active');

  const paperFilter = document.getElementById('rp-paper-filter');
  if (paperFilter && !paperFilter.value && job.paper_type) {
    fillSelectOptions('rp-paper-filter', [job.paper_type], 'All Paper Types', String(job.paper_type));
  }

  DM_ROLL_FILTERS_LOADED = false;
  await loadRollSuggestions();
}

async function saveExecutionData(id) {
  const w = document.getElementById('dm-wastage-kg')?.value || '';
  const notes = document.getElementById('dm-operator-notes')?.value || '';
  const voiceOriginal = document.getElementById('voiceOriginalText')?.textContent || '';
  const voiceEnglish = document.getElementById('voiceEnglishText')?.textContent || '';
  
  const job = getJobById(id);
  if (!job) return false;
  const extra = job.extra_data_parsed || {};
  extra.wastage_kg = w;
  extra.operator_notes = notes;
  
  // Store voice transcription if available
  if (voiceOriginal) {
    extra.voice_input_original = voiceOriginal;
    extra.voice_input_english = voiceEnglish;
    extra.voice_language = voiceLanguage || 'bn-IN';
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'submit_extra_data');
  fd.append('job_id', id);
  fd.append('extra_data', JSON.stringify(extra));
  try {
    const r = await fetch(API_BASE, { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) {
      alert('Save error: ' + (d.error || 'Unknown'));
      return false;
    }
    return true;
  } catch (e) {
    alert('Network error while saving execution data');
    return false;
  }
}

async function startJobWithTimer(id) {
  if (!confirm('Start this job?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', 'Running');
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { alert('Error: ' + (data.error || 'Unknown')); return; }
  } catch (err) { alert('Network error: ' + err.message); return; }

  // Close modal if open
  document.getElementById('jcDetailModal').classList.remove('active');

  _timerJobId = id;
  _timerStart = Date.now();
  const job = getJobById(id) || {};
  job.status = 'Running';
  job.started_at = new Date().toISOString();
  const jobNo = job.job_no || '';
  const jobLabel = job.planning_job_name || ('Job #' + id);
  const rollNo = job.roll_no || '';
  const paperType = job.paper_type || '';

  const overlay = document.createElement('div');
  overlay.className = 'jc-timer-overlay';
  overlay.id = 'jcTimerOverlay';
  overlay.innerHTML = `
    <div class="jc-timer-jobinfo">
      <div style="font-size:1.3rem;font-weight:900;letter-spacing:.03em">${esc(jobNo)}</div>
      <div style="margin-top:4px">${esc(jobLabel)}</div>
      ${rollNo || paperType ? `<div style="margin-top:4px;font-size:.85rem;opacity:.8">${esc(rollNo)}${rollNo && paperType ? ' — ' : ''}${esc(paperType)}</div>` : ''}
    </div>
    <div class="jc-timer-display" id="jcTimerCounter">00:00:00</div>
    <div class="jc-timer-actions">
      <button class="jc-timer-btn-cancel" onclick="cancelTimer()"><i class="bi bi-x-lg"></i> Cancel</button>
      <button class="jc-timer-btn-end" onclick="endTimer()"><i class="bi bi-stop-fill"></i> End</button>
    </div>
  `;
  document.body.appendChild(overlay);

  _timerInterval = setInterval(() => {
    const diff = Math.floor((Date.now() - _timerStart) / 1000);
    const h = String(Math.floor(diff/3600)).padStart(2,'0');
    const m = String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    const el = document.getElementById('jcTimerCounter');
    if (el) el.textContent = h + ':' + m + ':' + s;
  }, 1000);
}

function cancelTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  (async () => {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_status');
    fd.append('job_id', _timerJobId);
    fd.append('status', 'Pending');
    try { await fetch(API_BASE, { method: 'POST', body: fd }); } catch(e) {}
    const j = getJobById(_timerJobId);
    if (j) j.status = 'Pending';
    _timerJobId = null;
    location.reload();
  })();
}

function endTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  const jobId = _timerJobId;
  _timerJobId = null;
  openJobDetail(jobId, 'complete');
}

async function submitAndClose(id) {
  const ok = await saveExecutionData(id);
  if (!ok) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', 'Closed');
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { alert('Close error: ' + (data.error || 'Unknown')); return; }
    DM_MODAL_LOCKED = false;
    location.reload();
  } catch (err) { alert('Network error: ' + err.message); }
}

async function uploadJumboPhoto(jobId) {
  const input = document.getElementById('jc-photo-input-' + jobId);
  if (!input || !input.files || !input.files[0]) return;
  const file = input.files[0];
  const statusEl = document.getElementById('jc-photo-status-' + jobId);
  const previewEl = document.getElementById('jc-photo-preview-' + jobId);
  if (statusEl) statusEl.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'upload_jumbo_photo');
  fd.append('job_id', jobId);
  fd.append('photo', file);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (statusEl) statusEl.innerHTML = '<i class="bi bi-check-circle" style="color:#16a34a"></i> Uploaded';
      if (previewEl) previewEl.innerHTML = `<img src="${data.photo_url}" alt="Job Photo">`;
      const job = getJobById(jobId);
      if (job) {
        if (!job.extra_data_parsed) job.extra_data_parsed = {};
        job.extra_data_parsed.jumbo_photo_url = data.photo_url || '';
        job.extra_data_parsed.jumbo_photo_path = data.photo_path || '';
      }
    } else {
      if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626">Error: ' + esc(data.error || 'Unknown') + '</span>';
    }
  } catch (err) {
    if (statusEl) statusEl.innerHTML = '<span style="color:#dc2626">Network error</span>';
  }
}

function collectEditedRows() {
  const rows = [];
  document.querySelectorAll('#dm-roll-preview-table tbody tr[data-roll]').forEach(tr => {
    const rollNo = tr.getAttribute('data-roll') || '';
    if (!rollNo) return;
    rows.push({
      bucket: tr.getAttribute('data-bucket') || 'child',
      roll_no: rollNo,
      width: Number(tr.getAttribute('data-width') || 0),
      length: Number(tr.getAttribute('data-length') || 0),
      wastage: Number(tr.getAttribute('data-wastage') || 0),
      status: tr.getAttribute('data-status') || 'Job Assign',
      remarks: tr.getAttribute('data-remarks') || ''
    });
  });
  return rows;
}

function deriveChildRollFromParent(newParentRoll, originalParentRoll, originalChildRoll) {
  const parent = String(newParentRoll || '').trim();
  const originalParent = String(originalParentRoll || '').trim();
  const originalChild = String(originalChildRoll || '').trim();
  if (!parent || !originalChild) return originalChild;
  if (originalParent && originalChild.indexOf(originalParent) === 0) {
    return parent + originalChild.slice(originalParent.length);
  }
  const dashIndex = originalChild.indexOf('-');
  if (dashIndex >= 0) {
    return parent + originalChild.slice(dashIndex);
  }
  return parent;
}

function refreshRollPreview() {
  const parentInput = document.getElementById('dm-parent-roll-input');
  const parentPreview = document.getElementById('dm-parent-roll-preview');
  if (!parentInput) return;
  const nextParent = String(parentInput.value || '').trim();
  if (parentPreview) parentPreview.textContent = nextParent || '-';

  document.querySelectorAll('#dm-roll-preview-table tbody tr[data-original-roll]').forEach(tr => {
    const originalParent = tr.getAttribute('data-original-parent') || '';
    const originalRoll = tr.getAttribute('data-original-roll') || '';
    const nextChild = deriveChildRollFromParent(nextParent, originalParent, originalRoll);
    tr.setAttribute('data-roll', nextChild);
    const cell = tr.querySelector('.dm-preview-roll');
    if (cell) cell.textContent = nextChild || '-';
    const parentCell = tr.querySelector('.dm-preview-parent');
    if (parentCell) parentCell.textContent = nextParent || '-';
  });
}

function setRequestButtonState(enabled, message) {
  const requestBtn = document.getElementById('dm-request-roll-btn-footer') || document.getElementById('dm-request-roll-btn');
  if (!requestBtn) return;
  requestBtn.disabled = !enabled;
  requestBtn.dataset.rollValid = enabled ? '1' : '0';
  requestBtn.dataset.validationMessage = String(message || 'Enter a valid suitable roll before sending request.');
}

let DM_PARENT_ROLL_CHANGED = false;

async function submitChangeRequest(id) {
  const requestBtn = document.getElementById('dm-request-roll-btn-footer') || document.getElementById('dm-request-roll-btn');
  const validationMessage = requestBtn?.dataset.validationMessage || 'Please enter a valid suitable roll before sending request.';
  if (!requestBtn || requestBtn.disabled || requestBtn.dataset.rollValid !== '1') {
    alert(validationMessage);
    return;
  }
  const rows = collectEditedRows();
  const wastage = Number(document.getElementById('dm-wastage-kg')?.value || 0);
  const remarks = document.getElementById('dm-operator-notes')?.value || '';
  const parentRollNo = String(document.getElementById('dm-parent-roll-input')?.value || '').trim();
  const job = getJobById(id);
  const originalParentRoll = String((job?.extra_data_parsed?.parent_details && job.extra_data_parsed.parent_details.roll_no) || job?.extra_data_parsed?.parent_roll || job?.roll_no || '').trim();

  if (!parentRollNo) {
    alert('Enter replacement parent roll number first.');
    return;
  }
  if (originalParentRoll && parentRollNo.toLowerCase() === originalParentRoll.toLowerCase()) {
    alert('Enter a different parent roll number before sending request.');
    return;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'submit_jumbo_change_request');
  fd.append('job_id', id);
  fd.append('parent_roll_no', parentRollNo);
  fd.append('rows_json', JSON.stringify(rows));
  fd.append('wastage_kg', String(wastage));
  fd.append('operator_remarks', remarks);

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      alert('Request error: ' + (data.error || 'Unknown'));
      return;
    }
    if (data.already_pending) alert('This job already has a pending request.');
    else alert('Change request submitted successfully.');
    location.reload();
  } catch (err) {
    alert('Network error: ' + err.message);
  }
}

function switchDetailTab(tabName) {
  document.querySelectorAll('.jc-modal-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabName);
  });
  document.querySelectorAll('.jc-tab-panel').forEach(panel => {
    panel.classList.toggle('active', panel.dataset.panel === tabName);
  });

  const execActions = document.getElementById('dm-footer-execution-actions');
  const editActions = document.getElementById('dm-footer-edit-actions');
  if (execActions) execActions.style.display = tabName === 'execution' ? 'grid' : 'none';
  if (editActions) editActions.style.display = tabName === 'edit' ? 'flex' : 'none';
}

async function lookupReplacementRoll(job) {
  const input = document.getElementById('dm-parent-roll-input');
  const box = document.getElementById('dm-roll-check');
  const msg = document.getElementById('dm-roll-check-message');
  const detail = document.getElementById('dm-roll-check-detail');
  if (!input || !box || !msg || !detail) return;

  const rollNo = String(input.value || '').trim();
  const requiredType = String(job.paper_type || '').trim().toLowerCase();
  const originalParentRoll = String((job.extra_data_parsed?.parent_details && job.extra_data_parsed.parent_details.roll_no) || job.extra_data_parsed?.parent_roll || job.roll_no || '').trim().toLowerCase();
  box.className = 'jc-roll-check';
  detail.innerHTML = '';

  if (!DM_PARENT_ROLL_CHANGED) {
    msg.textContent = 'Change parent roll number to validate and enable request.';
    setRequestButtonState(false, 'Change parent roll number first, then enter a valid available roll.');
    return;
  }

  if (!rollNo) {
    msg.textContent = 'Enter replacement parent roll number.';
    setRequestButtonState(false, 'Enter replacement parent roll number.');
    return;
  }

  if (originalParentRoll && rollNo.toLowerCase() === originalParentRoll) {
    msg.textContent = 'Enter a different parent roll number to request a roll change.';
    setRequestButtonState(false, 'Enter a different parent roll number to request a roll change.');
    return;
  }

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_lookup');
    url.searchParams.set('roll_no', rollNo);
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok || !data.roll) {
      box.classList.add('bad');
      msg.textContent = 'Roll not found. Request cannot be sent.';
      setRequestButtonState(false, 'Roll not found. Request cannot be sent.');
      return;
    }

    const roll = data.roll;
    const rollType = String(roll.paper_type || '').trim().toLowerCase();
    const typeOk = requiredType === '' || rollType === requiredType;
    const statusOk = ['main', 'stock', 'job assign', 'available'].includes(String(roll.status || '').trim().toLowerCase());

    const requiredWidth = Number(job.width_mm ?? job.extra_data_parsed?.parent_details?.width_mm ?? 0);
    const rollWidth = Number(roll.width_mm ?? 0);

    const canCheckWidth = Number.isFinite(requiredWidth) && requiredWidth > 0 && Number.isFinite(rollWidth) && rollWidth > 0;
    const widthOk = !canCheckWidth || rollWidth >= requiredWidth;

    const suitable = typeOk && statusOk && widthOk;

    let reason = 'Suitable replacement roll found for this job.';
    if (!typeOk) reason = 'Paper type mismatch. This roll is not suitable for this job.';
    else if (!statusOk) reason = 'Roll status is not suitable for replacement.';
    else if (!widthOk) reason = `Roll width ${rollWidth} is smaller than required job width ${requiredWidth}. Select same or higher width.`;

    box.classList.add(suitable ? 'ok' : 'bad');
    msg.textContent = reason;

    detail.innerHTML = `
      <div class="jc-roll-check-grid">
        <div><div class="rk">Paper Type</div><div class="rv">${esc(roll.paper_type || '-')}</div></div>
        <div><div class="rk">Company</div><div class="rv">${esc(roll.company || '-')}</div></div>
        <div><div class="rk">Status</div><div class="rv">${esc(roll.status || '-')}</div></div>
        <div><div class="rk">Width</div><div class="rv">${esc(String(roll.width_mm || '-'))}</div></div>
        <div><div class="rk">Length</div><div class="rv">${esc(String(roll.length_mtr || '-'))}</div></div>
        <div><div class="rk">GSM</div><div class="rv">${esc(String(roll.gsm || '-'))}</div></div>
      </div>`;

    setRequestButtonState(suitable, suitable ? 'Suitable replacement roll found for this job.' : msg.textContent);
  } catch (err) {
    box.classList.add('bad');
    msg.textContent = 'Lookup failed. Please try again.';
    setRequestButtonState(false, 'Lookup failed. Please try again.');
  }
}

// ─── Detail modal ───────────────────────────────────────────
async function openJobDetail(id, mode) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  DM_ACTIVE_JOB_ID = Number(job.id || 0);
  DM_MODAL_LOCKED = String(job.status || '') === 'Running';

  const sts = job.status;
  const isFinishedJob = ['closed', 'finalized', 'completed', 'finished', 'qc passed', 'qc failed'].includes(String(sts || '').toLowerCase());
  const stsClass = {Pending:'pending',Running:'running',Closed:'completed',Finalized:'completed'}[sts]||'pending';
  const extra = job.extra_data_parsed || {};
  const hasPendingRequest = Number(job.pending_change_requests || 0) > 0;
  const createdAt = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;
  const parsedStart = job.started_at ? new Date(job.started_at).getTime() : 0;
  const startedTs = Number.isFinite(parsedStart) && parsedStart > 0 ? parsedStart : (sts === 'Running' ? Date.now() : 0);
  const originalParentRoll = String((extra.parent_details && extra.parent_details.roll_no) || extra.parent_roll || job.roll_no || '').trim();

  document.getElementById('dm-jobno').textContent = job.job_no;
  const badge = document.getElementById('dm-status-badge');
  badge.textContent = sts;
  badge.className = 'jc-badge jc-badge-' + stsClass;

  let html = '';

  const viewQrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no || '')}`;
  const viewQrDataUrl = await generateQR(viewQrUrl);

  // Modal tabs
  html += `<div class="jc-modal-tabs">
    <button type="button" class="jc-modal-tab active" data-tab="execution" onclick="switchDetailTab('execution')">Job Execution</button>
    ${isFinishedJob ? '' : `<button type="button" class="jc-modal-tab" data-tab="edit" onclick="switchDetailTab('edit')">Edit Job</button>`}
  </div>`;

  const summaryHtml = `<div class="jc-summary-card"><div class="jc-summary-grid">
    <div class="jc-summary-item"><div class="sl">Job ID</div><div class="sv">${esc(job.job_no || '—')}</div></div>
    <div class="jc-summary-item"><div class="sl">Job Name</div><div class="sv">${esc(job.planning_job_name || '—')}</div></div>
    <div class="jc-summary-item"><div class="sl">Priority</div><div class="sv">${esc(job.planning_priority || 'Normal')}</div></div>
  </div></div>`;

  const timingHtml = `<div class="jc-detail-section"><h3><i class="bi bi-stopwatch"></i> Start / End Timing</h3>
    <div class="jc-timing-grid">
      <div class="jc-timing-box"><div class="jc-timing-label">Start Time</div><div class="jc-timing-value">${startedAt}</div></div>
      <div class="jc-timing-box"><div class="jc-timing-label">End Time</div><div class="jc-timing-value">${completedAt}</div></div>
      <div class="jc-timing-box"><div class="jc-timing-label">Current Status</div><div class="jc-timing-value">${esc(sts || 'Pending')}</div></div>
      <div class="jc-timing-box jc-counter-box"><div class="jc-timing-label">Counter</div><div class="jc-timing-value">${(sts === 'Running' && startedTs) ? `<span class="jc-timer" data-started="${startedTs}">00:00:00</span>` : (dur !== null && dur !== undefined ? `${Math.floor(dur/60)}h ${dur%60}m` : (sts === 'Pending' ? '<span style="font-size:.9rem;color:#94a3b8">Not Started</span>' : '--:--:--'))}</div></div>
    </div>
  </div>`;

  const shouldDisableForm = !(mode === 'complete' && sts === 'Running') || isFinishedJob;
  const formDisabledAttr = shouldDisableForm ? 'disabled' : '';
  const executionFormHtml = `<div class="jc-detail-section"><h3><i class="bi bi-pencil-square"></i> Operator Entry</h3>
    <div class="jc-form-row">
      <div class="jc-form-group"><label>Wastage (kg)</label><input type="number" step="0.01" min="0" id="dm-wastage-kg" value="${esc(extra.wastage_kg || extra.operator_wastage_kg || '')}" ${formDisabledAttr}></div>
      <div class="jc-form-group"><label>Operator Remarks (Text or Voice)</label>
        <div style="display:flex;gap:8px;align-items:flex-start">
          <div style="flex:1">
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
              <select id="voiceLangSelect" data-voice-lang="bn-IN" onchange="this.setAttribute('data-voice-lang',this.value);voiceLanguage=this.value" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:.72rem;font-weight:700;color:#334155" ${formDisabledAttr}>
                <option value="bn-IN">🇧🇩 Bengali</option>
                <option value="hi-IN">🇮🇳 Hindi</option>
                <option value="en-US">🇬🇧 English</option>
              </select>
              <span style="font-size:.62rem;color:#94a3b8;font-weight:600">Select language before speaking</span>
            </div>
            <input type="text" id="dm-operator-notes" placeholder="Type remarks or use voice input..." value="${esc(extra.operator_notes || extra.operator_remarks || '')}" ${formDisabledAttr} style="width:100%">
            <div id="voiceTranslationDisplay" style="margin-top:8px;padding:10px;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd;border-radius:8px;font-size:.75rem;line-height:1.5;display:none">
              <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px"><span style="font-size:.9rem">🎤</span><strong style="color:#0369a1;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px">Voice Translation</strong></div>
              <div><strong style="color:#0891b2">Original:</strong> <span id="voiceOriginalText" style="color:#334155"></span></div>
              <div style="margin-top:4px"><strong style="color:#16a34a">English:</strong> <span id="voiceEnglishText" style="color:#1e293b;font-weight:700"></span></div>
            </div>
          </div>
          <button type="button" id="voiceMicBtn" class="voiceInputBtn" onclick="startVoiceInput()" title="Voice Input" ${formDisabledAttr} style="padding:8px 12px;background:#0891b2;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;font-weight:700;height:fit-content">
            <i class="bi bi-mic-fill"></i> <span class="voiceStatus">Speak</span>
          </button>
        </div>
      </div>
    </div>
  </div>
  <style>
    .voiceInputBtn { transition: all .2s; }
    .voiceInputBtn:hover:not(:disabled) { transform: scale(1.05); box-shadow: 0 4px 12px rgba(8,145,178,.3); }
    .voiceInputBtn:disabled { opacity: .5; cursor: not-allowed; }
    .voiceInputBtn.listening { background: #dc2626; animation: voicePulse .8s infinite; }
    @keyframes voicePulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,.7); } 50% { box-shadow: 0 0 0 8px rgba(220,38,38,0); } }
  </style>`;

  // Parent/child roll snapshot (for execution tab clarity)
  let executionRollHtml = '';

  // Photo upload section (always available for operator)
  const existingPhoto = extra.jumbo_photo_url || '';
  const photoUploadHtml = `<div class="jc-detail-section"><h3><i class="bi bi-camera"></i> Job Photo</h3>
    <div class="jc-upload-zone" onclick="document.getElementById('jc-photo-input-${job.id}').click()">
      <input type="file" id="jc-photo-input-${job.id}" accept="image/*" capture="environment" onchange="uploadJumboPhoto(${job.id})">
      <div style="font-size:.75rem;color:#64748b"><i class="bi bi-cloud-arrow-up" style="font-size:1.5rem;color:var(--jc-brand)"></i><br>Tap to open camera</div>
      <div id="jc-photo-status-${job.id}" style="font-size:.7rem;margin-top:6px"></div>
    </div>
    <div id="jc-photo-preview-${job.id}" class="jc-upload-preview">${existingPhoto ? `<img src="${existingPhoto}" alt="Job Photo">` : ''}</div>
  </div>`;

  // Parent Roll Details (for edit tab)
  let editHtml = '';
  editHtml += summaryHtml;

  // Multi-parent roll collection (same logic as main JMB page)
  {
    const liveRollMap = job.live_roll_map || {};
    const p = extra.parent_details || {};
    const seenParents = {};
    const primaryPRN = String((p.roll_no) || extra.parent_roll || job.roll_no || '').trim();
    if (primaryPRN !== '') seenParents[primaryPRN] = true;
    const parentRollsRaw = extra.parent_rolls;
    if (Array.isArray(parentRollsRaw)) {
      parentRollsRaw.forEach(function(pr) {
        const prn = String(pr || '').trim();
        if (prn !== '') seenParents[prn] = true;
      });
    } else if (typeof parentRollsRaw === 'string' && parentRollsRaw.trim() !== '') {
      parentRollsRaw.split(',').forEach(function(pr) {
        const prn = String(pr || '').trim();
        if (prn !== '') seenParents[prn] = true;
      });
    }
    (Array.isArray(extra.child_rolls) ? extra.child_rolls : []).forEach(function(r) {
      const prn = String(r.parent_roll_no || '').trim();
      if (prn !== '') seenParents[prn] = true;
    });
    (Array.isArray(extra.stock_rolls) ? extra.stock_rolls : []).forEach(function(r) {
      const prn = String(r.parent_roll_no || '').trim();
      if (prn !== '') seenParents[prn] = true;
    });
    const allParentRollNos = Object.keys(seenParents);
    if (allParentRollNos.length > 0) {
      let parentTableHtml = '<table class="jc-soft-table"><tr><th>Roll No</th><th>Paper Company</th><th>Material</th><th>Width</th><th>Length</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Status</th><th>Remarks</th></tr>';
      allParentRollNos.forEach(function(prn) {
        const live      = liveRollMap[prn] || {};
        const isPrimary = (prn === primaryPRN);
        const company   = live.company    || (isPrimary ? (p.company    || job.company    || '') : '');
        const ptype     = live.paper_type || (isPrimary ? (p.paper_type || job.paper_type || '') : '');
        const width     = live.width_mm   !== undefined ? live.width_mm   : (isPrimary ? (p.width_mm  ?? job.width_mm  ?? '--') : '--');
        const length    = live.length_mtr !== undefined ? live.length_mtr : (isPrimary ? (p.length_mtr ?? job.length_mtr ?? '--') : '--');
        const weight    = live.weight_kg  !== undefined ? live.weight_kg  : (isPrimary ? (p.weight_kg ?? job.weight_kg ?? '--') : '--');
        const sqm       = isPrimary ? (p.sqm ?? '--') : '--';
        const gsm       = live.gsm !== undefined ? live.gsm : (isPrimary ? (p.gsm ?? job.gsm ?? '--') : '--');
        const liveStatus  = live.status  || '--';
        const liveRemarks = live.remarks !== undefined ? live.remarks : (isPrimary ? (p.remarks || '') : '');
        parentTableHtml += `<tr><td style="color:var(--jc-brand);font-weight:700">${esc(prn)}</td><td>${esc(company || '--')}</td><td>${esc(ptype || '--')}</td><td>${esc(width + '')}</td><td>${esc(length + '')}</td><td>${esc(weight + '')}</td><td>${esc(sqm + '')}</td><td>${esc(gsm + '')}</td><td>${esc(liveStatus)}</td><td>${esc(liveRemarks || '--')}</td></tr>`;
      });
      parentTableHtml += '</table>';
      executionRollHtml += `<div class="jc-detail-section"><h3><i class="bi bi-inbox"></i> Parent Roll${allParentRollNos.length > 1 ? 's' : ''}</h3><div class="jc-table-shell jc-parent-shell"><div style="overflow-x:auto">${parentTableHtml}</div></div></div>`;
    }

    // Edit tab: change parent roll section (uses first/primary parent)
    if (primaryPRN) {
      editHtml += `<div class="jc-detail-section"><h3><i class="bi bi-inbox"></i> Change Parent Roll</h3>
        <div class="jc-form-row">
          <div class="jc-form-group"><label>Parent Roll Number</label><div class="jc-roll-pick-row"><input type="text" id="dm-parent-roll-input" value="" placeholder="Current: ${esc(primaryPRN)}" onclick="openRollPicker()" autocomplete="off"><button type="button" class="jc-action-btn jc-btn-view" onclick="openRollPicker()"><i class="bi bi-search"></i> Browse</button></div></div>
          <div class="jc-form-group"><label>Requested Parent Preview</label><div class="jc-timing-box"><div class="jc-timing-value" id="dm-parent-roll-preview">-</div></div></div>
        </div>
        <div id="dm-roll-check" class="jc-roll-check"><div id="dm-roll-check-message" class="rv">Enter replacement parent roll number.</div><div id="dm-roll-check-detail"></div></div>
      </div>`;
    }
  }

  // All child rolls in one table (job assign + stock) with plan number.
  const childRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
  const stockRows = Array.isArray(extra.stock_rolls) ? extra.stock_rolls : [];
  const allRows = [];
  childRows.forEach(function(r) {
    allRows.push({
      parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '',
      roll_no: r.roll_no || '',
      type: r.paper_type || job.paper_type || '',
      width: (r.width ?? r.width_mm),
      length: (r.length ?? r.length_mtr),
      weight_kg: (r.weight_kg ?? job.weight_kg),
      sqm: (r.sqm ?? '—'),
      gsm: (r.gsm ?? job.gsm),
      wastage: (r.wastage ?? 0),
      remarks: r.remarks || '',
      status: 'Job Assign',
    });
  });
  stockRows.forEach(function(r) {
    allRows.push({
      parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '',
      roll_no: r.roll_no || '',
      type: r.paper_type || job.paper_type || '',
      width: (r.width ?? r.width_mm),
      length: (r.length ?? r.length_mtr),
      weight_kg: (r.weight_kg ?? job.weight_kg),
      sqm: (r.sqm ?? '—'),
      gsm: (r.gsm ?? job.gsm),
      wastage: (r.wastage ?? 0),
      remarks: r.remarks || '',
      status: 'Stock',
    });
  });

  if (allRows.length) {
    let executionRowsHtml = '<table class="jc-soft-table"><thead><tr><th>Parent Roll</th><th>Child Roll</th><th>Type</th><th>Width</th><th>Length</th><th>Status</th><th>Wastage</th><th>Remarks</th></tr></thead><tbody>';

    let childPreviewHtml = '<table id="dm-roll-preview-table" class="jc-soft-table"><thead><tr><th>Parent Roll No</th><th>Child Roll No.</th><th>Width</th><th>Length</th><th>Type</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Wastage</th><th>Status</th><th>Remarks</th></tr></thead><tbody>';
    let stockInfoHtml = '<table class="jc-soft-table"><thead><tr><th>Parent Roll No</th><th>Stock Roll No.</th><th>Width</th><th>Length</th><th>Type</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Wastage</th><th>Status</th><th>Remarks</th></tr></thead><tbody>';
    allRows.forEach(function(r) {
      const bucket = (r.status === 'Stock') ? 'stock' : 'child';
      executionRowsHtml += `<tr><td style="font-weight:700">${esc(r.parent_roll_no || '—')}</td><td style="color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '—')}</td><td>${esc(r.type || '—')}</td><td>${esc((r.width ?? '—') + '')}</td><td>${esc((r.length ?? '—') + '')}</td><td>${esc(r.status || '—')}</td><td>${esc((r.wastage ?? 0) + '')}</td><td>${esc(r.remarks || '—')}</td></tr>`;
      if (bucket === 'child') {
        childPreviewHtml += `<tr data-roll="${esc(r.roll_no || '')}" data-original-roll="${esc(r.roll_no || '')}" data-original-parent="${esc(originalParentRoll)}" data-bucket="child" data-width="${esc((r.width ?? 0) + '')}" data-length="${esc((r.length ?? 0) + '')}" data-wastage="${esc((r.wastage ?? 0) + '')}" data-status="${esc(r.status || 'Job Assign')}" data-remarks="${esc(r.remarks || '')}"><td class="dm-preview-parent" style="font-weight:700">${esc(originalParentRoll || r.parent_roll_no || '—')}</td><td class="dm-preview-roll" style="color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '—')}</td><td>${esc((r.width ?? '—') + '')}</td><td>${esc((r.length ?? '—') + '')}</td><td>${esc(r.type || '—')}</td><td>${esc((r.weight_kg ?? '—') + '')}</td><td>${esc((r.sqm ?? '—') + '')}</td><td>${esc((r.gsm ?? '—') + '')}</td><td>${esc((r.wastage ?? 0) + '')}</td><td>${esc(r.status || '—')}</td><td>${esc(r.remarks || '—')}</td></tr>`;
      } else {
        stockInfoHtml += `<tr><td style="font-weight:700">${esc(r.parent_roll_no || '—')}</td><td style="color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '—')}</td><td>${esc((r.width ?? '—') + '')}</td><td>${esc((r.length ?? '—') + '')}</td><td>${esc(r.type || '—')}</td><td>${esc((r.weight_kg ?? '—') + '')}</td><td>${esc((r.sqm ?? '—') + '')}</td><td>${esc((r.gsm ?? '—') + '')}</td><td>${esc((r.wastage ?? 0) + '')}</td><td>${esc(r.status || '—')}</td><td>${esc(r.remarks || '—')}</td></tr>`;
      }
    });
    executionRowsHtml += '</tbody></table>';
    childPreviewHtml += '</tbody></table>';
    stockInfoHtml += '</tbody></table>';
    executionRollHtml += `<div class="jc-detail-section"><h3><i class="bi bi-table"></i> Child / Stock Rolls</h3><div class="jc-table-shell jc-child-shell"><div style="overflow-x:auto">${executionRowsHtml}</div></div></div>`;
    editHtml += `<div class="jc-detail-section"><h3><i class="bi bi-arrow-repeat"></i> Child Roll Preview</h3><div class="jc-table-shell jc-child-shell"><div style="overflow-x:auto">${childPreviewHtml}</div></div></div>`;
    editHtml += `<div class="jc-detail-section"><h3><i class="bi bi-lock"></i> Stock Rolls (No Change)</h3><div class="jc-table-shell jc-child-shell"><div style="overflow-x:auto">${stockInfoHtml}</div></div></div>`;
  }

  html += `<div class="jc-tab-panel active" data-panel="execution">${summaryHtml}${timingHtml}${executionRollHtml}${executionFormHtml}${photoUploadHtml}</div>`;
  html += `<div class="jc-tab-panel" data-panel="edit">${editHtml}</div>`;

  if (viewQrDataUrl) {
    html = `<div class="jc-detail-section jc-qr-view-only" style="display:flex;align-items:center;justify-content:space-between;gap:14px;background:#f8fafc">
      <div>
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.05em">Job Card QR</div>
        <div style="font-size:.74rem;color:#475569">Scan to open this job card on mobile/desktop</div>
      </div>
      <div style="text-align:center">
        <img src="${viewQrDataUrl}" alt="Job QR" style="width:96px;height:96px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px">
      </div>
    </div>` + html;
  }

  document.getElementById('dm-body').innerHTML = html;

  // Footer actions (Flexo-style logic)
  let fHtml = '<div style="display:flex;gap:8px">' + (isFinishedJob ? '' : '<button class="jc-action-btn jc-btn-view" onclick="switchDetailTab(\'edit\')"><i class="bi bi-pencil-square"></i> Edit Job</button>') + '</div>';
  fHtml += '<div id="dm-footer-execution-actions" style="display:flex;gap:8px">';
  if (mode === 'complete' && sts === 'Running') {
    fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="submitAndClose(${job.id})"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  } else if (sts === 'Pending') {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-fill"></i> Start Job</button>`;
  }
  fHtml += '</div>';
  fHtml += `<div id="dm-footer-edit-actions" style="display:none;align-items:center;gap:8px;">${isFinishedJob ? `<span class="jc-request-state">Finished - Edit Locked</span>` : (hasPendingRequest ? `<span class="jc-request-state">Requesting Approval</span>` : `<button id="dm-request-roll-btn-footer" class="jc-action-btn jc-btn-complete" onclick="submitChangeRequest(${job.id})" disabled data-roll-valid="0" data-validation-message="Enter replacement parent roll number."><i class="bi bi-send"></i> Request Change Roll</button>`)}</div>`;
  document.getElementById('dm-footer').innerHTML = fHtml;

  // Disable operator entry fields unless in complete mode
  setTimeout(() => {
    const shouldDisable = !(mode === 'complete' && sts === 'Running') || isFinishedJob;
    ['dm-wastage-kg', 'dm-operator-notes', 'voiceLangSelect', 'voiceMicBtn'].forEach(elId => {
      const el = document.getElementById(elId);
      if (el) el.disabled = shouldDisable;
    });
  }, 50);

  DM_PARENT_ROLL_CHANGED = false;

  if (!hasPendingRequest) {
    setRequestButtonState(false, 'Change parent roll number first, then enter a valid available roll.');
  }

  document.getElementById('dm-parent-roll-input')?.addEventListener('input', function() {
    DM_PARENT_ROLL_CHANGED = true;
    refreshRollPreview();
    lookupReplacementRoll(job);
  });
  refreshRollPreview();
  updateTimers();
  document.getElementById('jcDetailModal').classList.add('active');
}

function closeDetail() {
  document.getElementById('jcDetailModal').classList.remove('active');
}
document.getElementById('jcDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});
document.getElementById('dmRollPickerModal').addEventListener('click', function(e) {
  if (e.target === this) closeRollPicker();
});

// ─── Voice Input with Translation ──────────────────────────
let voiceRecognition;
let isListeningToVoice = false;
let voiceLanguage = 'bn-IN'; // Bengali by default

// Initialize Web Speech API
if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  voiceRecognition = new SpeechRecognition();
  voiceRecognition.continuous = false;
  voiceRecognition.interimResults = true;
  voiceRecognition.lang = voiceLanguage;

  voiceRecognition.onstart = function() {
    isListeningToVoice = true;
    const btn = document.getElementById('voiceMicBtn');
    if (btn) {
      btn.classList.add('listening');
      btn.querySelector('.voiceStatus').textContent = 'Listening...';
      btn.innerHTML = '<i class="bi bi-mic-fill"></i> <span class="voiceStatus">Listening...</span>';
    }
  };

  voiceRecognition.onresult = function(event) {
    let interimTranscript = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const transcript = event.results[i][0].transcript;
      if (event.results[i].isFinal) {
        const noteField = document.getElementById('dm-operator-notes');
        if (noteField) {
          const current = noteField.value.trim();
          const newText = (current ? current + ' ' : '') + transcript;
          noteField.value = newText;
          
          // Auto-translate to English
          translateVoiceText(transcript);
        }
      } else {
        interimTranscript += transcript;
      }
    }
  };

  voiceRecognition.onerror = function(event) {
    console.error('Voice recognition error:', event.error);
    alert('Voice input error: ' + event.error);
    updateVoiceButton('Error');
  };

  voiceRecognition.onend = function() {
    isListeningToVoice = false;
    updateVoiceButton('Done');
    setTimeout(() => updateVoiceButton('Start'), 2000);
  };
}

function startVoiceInput() {
  if (!voiceRecognition) {
    alert('Voice input not supported in your browser. Please use Chrome, Edge, or Safari.');
    return;
  }

  if (isListeningToVoice) {
    voiceRecognition.stop();
    updateVoiceButton('Stopped');
  } else {
    // Reset translation display
    document.getElementById('voiceTranslationDisplay').style.display = 'none';
    
    // Get language from dropdown selector
    const langSelect = document.getElementById('voiceLangSelect');
    voiceLanguage = langSelect ? langSelect.value : 'bn-IN';
    voiceRecognition.lang = voiceLanguage;
    
    voiceRecognition.start();
  }
}

function updateVoiceButton(status) {
  const btn = document.getElementById('voiceMicBtn');
  if (!btn) return;
  
  btn.classList.remove('listening');
  btn.querySelector('.voiceStatus').textContent = status;
  btn.innerHTML = '<i class="bi bi-mic-fill"></i> <span class="voiceStatus">' + status + '</span>';
}

function translateVoiceText(originalText) {
  // Map speech recognition language code to translation source
  const langMap = { 'bn-IN': 'bn', 'hi-IN': 'hi', 'en-US': 'en' };
  const sourceLang = langMap[voiceLanguage] || 'en';
  
  document.getElementById('voiceOriginalText').textContent = originalText;
  document.getElementById('voiceTranslationDisplay').style.display = 'block';
  
  // If English selected, just show same text (English → English)
  if (sourceLang === 'en') {
    document.getElementById('voiceEnglishText').textContent = originalText;
    return;
  }
  
  // Always translate non-English to English via API
  // Bengali (bn) → English or Hindi (hi) → English
  document.getElementById('voiceEnglishText').textContent = 'Translating...';
  
  const encodedText = encodeURIComponent(originalText);
  const langPair = sourceLang + '|en';
  
  fetch('https://api.mymemory.translated.net/get?q=' + encodedText + '&langpair=' + langPair)
    .then(r => r.json())
    .then(data => {
      if (data.responseStatus === 200 && data.responseData && data.responseData.translatedText) {
        const translated = data.responseData.translatedText;
        // MyMemory sometimes returns same text if it fails — check
        if (translated && translated !== originalText) {
          document.getElementById('voiceEnglishText').textContent = translated;
        } else {
          // Try matches array for better result
          if (data.matches && data.matches.length > 0) {
            const best = data.matches.reduce((a, b) => (b.quality > a.quality ? b : a), data.matches[0]);
            document.getElementById('voiceEnglishText').textContent = best.translation || translated;
          } else {
            document.getElementById('voiceEnglishText').textContent = translated;
          }
        }
      } else {
        document.getElementById('voiceEnglishText').textContent = '[Translation unavailable — please type manually]';
      }
    })
    .catch(err => {
      console.warn('Translation API error:', err);
      document.getElementById('voiceEnglishText').textContent = '[Offline — translation unavailable]';
    });
}

// ─── Delete job (admin) ─────────────────────────────────────
async function deleteJob(id) {
  if (!confirm('Are you sure you want to delete this job card? This action is soft-delete.')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'delete_job');
  fd.append('job_id', id);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ─── Print Job Card ─────────────────────────────────────────
async function printJobCard(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  openJobDetail(id);
  const modalBody = document.getElementById('dm-body');
  if (!modalBody) return;
  const template = document.getElementById('dm-print-template')?.value || 'executive';
  const nowText = new Date().toLocaleString();
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  const qrHtml = qrDataUrl ? `<div style="text-align:right;margin-top:6px"><img src="${qrDataUrl}" style="width:80px;height:80px;display:inline-block"><div style="font-size:.5rem;color:#94a3b8;margin-top:2px">Scan to open</div></div>` : '';
  const tmpBody = document.createElement('div');
  tmpBody.innerHTML = modalBody.innerHTML;
  tmpBody.querySelectorAll('.jc-qr-view-only').forEach(function(el) { el.remove(); });
  const html = `
    <div class="jc-print-sheet template-${template}">
      <header class="jc-print-header">
        <div class="jc-print-brand-left">
          ${COMPANY.logo ? `<img src="${COMPANY.logo}" alt="Logo" style="max-height:40px;max-width:120px;display:block">` : ''}
          <div>
            <div class="jc-print-company">${esc(COMPANY.name || 'Company')}</div>
            <div class="jc-print-meta">${esc(COMPANY.address || '')}</div>
            ${COMPANY.gst ? `<div class="jc-print-meta">GST: ${esc(COMPANY.gst)}</div>` : ''}
          </div>
        </div>
        <div class="jc-print-brand-right">
          <div class="jc-print-title">Jumbo Slitting Job Card</div>
          <div class="jc-print-job">${esc(job.job_no || '—')}</div>
          <div class="jc-print-meta">Printed: ${esc(nowText)}</div>
          ${qrHtml}
        </div>
      </header>
      <main class="jc-print-content">${tmpBody.innerHTML}</main>
      <footer class="jc-print-footer">
        <span>${esc(APP_FOOTER_LEFT || '')}</span>
        <span>${esc(APP_FOOTER_RIGHT || '')}</span>
      </footer>
    </div>`;

  const w = window.open('', '_blank', 'width=800,height=900');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>
    @page{margin:12mm}
    *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
    body{font-family:'Segoe UI',Arial,sans-serif;color:#1f2937}
    .jc-print-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;border-bottom:2px solid #e2e8f0;padding-bottom:10px;margin-bottom:12px}
    .jc-print-brand-left{display:flex;gap:10px;align-items:flex-start}
    .jc-print-company{font-size:1rem;font-weight:800;color:#0f172a}
    .jc-print-title{font-size:.85rem;font-weight:800;text-transform:uppercase;color:#334155}
    .jc-print-job{font-size:1rem;font-weight:900;color:#16a34a}
    .jc-print-meta{font-size:.68rem;color:#64748b}
    .jc-print-footer{display:flex;justify-content:space-between;gap:10px;border-top:1px solid #e2e8f0;padding-top:8px;margin-top:14px;font-size:.66rem;color:#64748b}
    .jc-detail-section{margin-bottom:16px}
    .jc-detail-section h3{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:0 0 8px}
    .jc-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}
    .jc-detail-item .dl{font-size:.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase}
    .jc-detail-item .dv{font-size:.82rem;color:#0f172a;font-weight:700}
    .jc-timeline{display:flex;gap:16px;flex-wrap:wrap}
    .template-compact .jc-detail-grid{grid-template-columns:1fr}
    .template-compact .jc-detail-section{margin-bottom:12px}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #dbe3ea;padding:7px 8px;text-align:left}
    th{background:#f8fafc}
  </style></head><body>${html}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

async function printLabelsForJob(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;

  const extra = job.extra_data_parsed || {};
  const rollNos = [];

  if (extra.parent_roll) rollNos.push(String(extra.parent_roll));
  (Array.isArray(extra.child_rolls) ? extra.child_rolls : []).forEach(function(r) {
    const rn = String((r && r.roll_no) || '').trim();
    if (rn) rollNos.push(rn);
  });
  (Array.isArray(extra.stock_rolls) ? extra.stock_rolls : []).forEach(function(r) {
    const rn = String((r && r.roll_no) || '').trim();
    if (rn) rollNos.push(rn);
  });

  if (!rollNos.length && job.roll_no) {
    rollNos.push(String(job.roll_no));
  }

  const uniqueRollNos = Array.from(new Set(rollNos));
  if (!uniqueRollNos.length) {
    alert('No rolls found for label printing.');
    return;
  }

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_ids');
    url.searchParams.set('roll_nos', uniqueRollNos.join(','));
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      alert('Error: ' + (data.error || 'Unable to resolve roll IDs'));
      return;
    }

    const ids = Array.isArray(data.ids) ? data.ids.filter(Boolean) : [];
    if (!ids.length) {
      alert('No matching paper stock records found for labels.');
      return;
    }

    const labelUrl = `${BASE_URL}/modules/paper_stock/label.php?ids=${ids.join(',')}`;
    window.open(labelUrl, '_blank');
  } catch (err) {
    alert('Network error: ' + (err.message || 'Unknown error'));
  }
}

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
(function(){
  const autoId = new URLSearchParams(window.location.search).get('auto_job');
  if (autoId) setTimeout(function(){ try { openJobDetail(parseInt(autoId)); } catch(e){} }, 600);
  // Default to Pending filter
  const pendingBtn = Array.from(document.querySelectorAll('.jc-filter-btn')).find(b => b.textContent.trim() === 'Pending');
  if (pendingBtn) pendingBtn.click();
})();
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
