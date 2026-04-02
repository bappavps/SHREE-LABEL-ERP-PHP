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

$jobsStmt = $db->prepare("\n  SELECT j.*,\n         ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,\n         ps.status AS roll_status, ps.lot_batch_no,\n         p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,\n         COALESCE(req.pending_count, 0) AS pending_change_requests,\n         lreq.latest_request_id, lreq.latest_request_status, lreq.latest_request_review_note, lreq.latest_request_reviewed_at\n  FROM jobs j\n  LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no\n  LEFT JOIN planning p ON j.planning_id = p.id\n  LEFT JOIN (\n    SELECT job_id, COUNT(*) AS pending_count\n    FROM job_change_requests\n    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'\n    GROUP BY job_id\n  ) req ON req.job_id = j.id\n  LEFT JOIN (\n    SELECT t.job_id, t.id AS latest_request_id, t.status AS latest_request_status, t.review_note AS latest_request_review_note, t.reviewed_at AS latest_request_reviewed_at\n    FROM job_change_requests t\n    INNER JOIN (\n      SELECT job_id, MAX(id) AS max_id\n      FROM job_change_requests\n      WHERE request_type = 'jumbo_roll_update'\n      GROUP BY job_id\n    ) mx ON mx.job_id = t.job_id AND mx.max_id = t.id\n    WHERE t.request_type = 'jumbo_roll_update'\n  ) lreq ON lreq.job_id = j.id\n  WHERE j.job_type = 'Slitting'\n    AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')\n  ORDER BY j.created_at DESC, j.id DESC\n");
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
.jc-request-chip.pending{background:#fef3c7;color:#92400e}
.jc-request-chip.rejected{background:#fee2e2;color:#991b1b}
.jc-request-state.rejected{background:#fee2e2;color:#991b1b}
.jc-roll-check{margin-top:12px;padding:12px 14px;border-radius:10px;border:1px solid #dbe3ea;background:#f8fafc}
.jc-roll-check.ok{border-color:#bbf7d0;background:#f0fdf4}
.jc-roll-check.bad{border-color:#fecaca;background:#fef2f2}
.jc-roll-check .rk{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.jc-roll-check .rv{font-size:.82rem;font-weight:700;color:#0f172a;margin-top:4px}
.jc-roll-check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}
.jc-parent-ref{margin-top:10px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}
.jc-parent-ref h4{margin:0 0 8px;font-size:.64rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#475569}
.jc-parent-ref-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.jc-parent-ref-item{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px}
.jc-parent-ref-item .k{font-size:.58rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.jc-parent-ref-item .v{font-size:.78rem;font-weight:800;color:#0f172a;margin-top:2px}
.jc-inline-suggest{margin-top:8px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;max-height:200px;overflow:auto;display:none}
.jc-inline-suggest-row{display:grid;grid-template-columns:1.2fr 1fr .7fr .7fr auto;gap:8px;align-items:center;padding:8px 10px;border-bottom:1px solid #eef2f7}
.jc-inline-suggest-row:last-child{border-bottom:none}
.jc-inline-suggest-row .rn{font-weight:900;color:var(--jc-brand)}
.jc-inline-suggest-row .meta{font-size:.68rem;color:#64748b;font-weight:700}
.jc-inline-empty{padding:10px;font-size:.72rem;color:#64748b;text-align:center}
.jc-parent-select-row{transition:background .15s}
.jc-parent-select-row-selected td{background:#fef2f2 !important}
.jc-auto-meta{margin-top:8px;font-size:.7rem;color:#475569;font-weight:700}
.jc-auto-list{margin-top:8px;border:1px solid #dbe3ea;border-radius:10px;background:#fff;display:none;overflow:auto;max-height:220px}
.jc-auto-list table{width:100%;border-collapse:collapse;font-size:.72rem}
.jc-auto-list th,.jc-auto-list td{padding:8px 10px;border-bottom:1px solid #eef2f7;text-align:left}
.jc-auto-list thead th{position:sticky;top:0;background:#f8fafc;font-size:.64rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.jc-auto-list tr.jc-auto-best td{background:#ecfdf5}
.dm-edit-input{width:100%;min-width:70px;height:30px;border:1px solid #cbd5e1;border-radius:6px;padding:4px 6px;font-size:.72rem;font-weight:700}

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
.jc-timer-btn-pause{background:#f59e0b;color:#fff}
.jc-timer-btn-pause:hover{background:#d97706}
.jc-timer-btn-end{background:#16a34a;color:#fff}
.jc-timer-btn-end:hover{background:#15803d}
@media(max-width:600px){.jc-grid{grid-template-columns:1fr}.jc-stats{grid-template-columns:repeat(2,1fr)}.jc-detail-grid{grid-template-columns:1fr}.jc-form-row{grid-template-columns:1fr}.jc-summary-grid,.jc-timing-grid,.jc-action-bar,.jc-roll-check-grid,.jc-picker-filters{grid-template-columns:1fr}.jc-child-shell{margin-left:0}.jc-roll-pick-row{flex-wrap:wrap}.jc-roll-pick-row input{min-width:0;width:100%}.dm-change-section .jc-roll-pick-row{flex-direction:column;align-items:stretch}.dm-change-section .jc-roll-pick-row input{width:100%}.dm-change-section .jc-roll-pick-row button{width:100%;justify-content:center}.dm-change-section h3{font-size:.85rem}.dm-subst-detail-grid{grid-template-columns:1fr 1fr !important}#dm-edit-parent-table{font-size:.72rem}#dm-edit-parent-table th,#dm-edit-parent-table td{padding:6px 4px}.dm-change-roll-detail .jc-roll-check-grid{grid-template-columns:1fr 1fr}#dm-roll-change-sections{margin:0 -4px}}
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
    $timerActive = !empty($job['extra_data_parsed']['timer_active']);
    $timerState = strtolower(trim((string)($job['extra_data_parsed']['timer_state'] ?? '')));
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
          <span class="jc-request-chip pending">Request Pending</span>
        <?php elseif (strtolower(trim((string)($job['latest_request_status'] ?? ''))) === 'rejected'): ?>
          <span class="jc-request-chip rejected">Request Rejected</span>
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
    <?php if ($sts === 'Running' && $startedTs && $timerState !== 'paused'): ?>
    <div class="jc-card-row"><span class="jc-label">Elapsed</span><span class="jc-timer" data-started="<?= $startedTs ?>" style="color:var(--jc-blue);font-weight:700">00:00:00</span></div>
    <?php endif; ?>
    <div class="jc-card-foot">
      <div class="jc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <?php if ($sts === 'Pending'): ?>
          <button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
        <?php elseif ($sts === 'Running'): ?>
          <?php if ($timerState === 'paused'): ?>
            <button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-circle"></i> Again Start</button>
          <?php elseif ($timerActive): ?>
            <button class="jc-action-btn jc-btn-start" onclick="resumeRunningJumboTimer(<?= $job['id'] ?>)"><i class="bi bi-play-circle"></i> Open Timer</button>
          <?php else: ?>
            <button class="jc-action-btn jc-btn-complete" onclick="openJobDetail(<?= $job['id'] ?>,'complete')"><i class="bi bi-check-lg"></i> Complete</button>
          <?php endif; ?>
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
<style>
.ht-filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.ht-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:200px;outline:none;transition:border .15s}
.ht-search:focus{border-color:var(--jc-brand)}
.ht-date-input{padding:7px 12px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.76rem;outline:none}
.ht-date-input:focus{border-color:var(--jc-brand)}
.ht-period-btn{padding:5px 13px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.ht-period-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.ht-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.ht-bulk-bar{display:none;background:linear-gradient(135deg,#15803d,#166534);color:#fff;border-radius:12px;padding:12px 20px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(21,128,61,.25)}
.ht-bulk-bar.visible{display:flex}
.ht-bulk-btn{padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff}
.ht-bulk-btn:hover{background:rgba(255,255,255,.22)}
.ht-bulk-print{padding:7px 16px;background:var(--jc-brand);color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px}
.ht-bulk-print:hover{opacity:.9}
.ht-table-wrap{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
.ht-table{width:100%;border-collapse:collapse;font-size:.78rem}
.ht-table thead{background:linear-gradient(135deg,#f8fafc,#f1f5f9);position:sticky;top:0;z-index:2}
.ht-table th{padding:11px 13px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;border-bottom:2px solid #e2e8f0;white-space:nowrap;cursor:pointer;user-select:none}
.ht-table th:hover{color:#0f172a}
.ht-table th .ht-sort{margin-left:3px;font-size:.52rem;opacity:.4}
.ht-table th.sorted .ht-sort{opacity:1;color:var(--jc-brand)}
.ht-table td{padding:9px 13px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-weight:600;vertical-align:middle}
.ht-table tbody tr{transition:background .1s;cursor:pointer}
.ht-table tbody tr:hover{background:#f0fdf4}
.ht-table tbody tr.ht-selected{background:#f0fdf4;outline:2px solid var(--jc-brand);outline-offset:-2px}
.ht-table .ht-cb-cell{width:34px;text-align:center}
.ht-table .ht-cb-cell input{width:16px;height:16px;accent-color:var(--jc-brand);cursor:pointer}
.ht-jobno{font-weight:900;color:#0f172a;font-size:.8rem}
.ht-dim{color:#94a3b8;font-size:.72rem}
.ht-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.ht-badge-completed{background:#dcfce7;color:#166534}
.ht-badge-closed{background:#dcfce7;color:#166534}
.ht-badge-finalized{background:#dbeafe;color:#1e40af}
.ht-badge-qcpassed{background:#d1fae5;color:#065f46}
.ht-badge-default{background:#f1f5f9;color:#64748b}
.ht-act-btn{padding:4px 9px;font-size:.58rem;font-weight:800;text-transform:uppercase;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:6px;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:3px;color:#475569}
.ht-act-btn:hover{background:#f1f5f9}
.ht-act-btn.ht-print{color:#8b5cf6;border-color:#c4b5fd}
.ht-act-btn.ht-print:hover{background:#f5f3ff}
.ht-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;flex-wrap:wrap;gap:10px}
.ht-page-info{font-size:.7rem;color:#64748b;font-weight:600}
.ht-page-btns{display:flex;gap:4px}
.ht-page-btn{padding:5px 11px;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:8px;font-size:.7rem;font-weight:700;cursor:pointer;color:#475569;transition:all .12s}
.ht-page-btn:hover{background:#f1f5f9}
.ht-page-btn.active{background:var(--jc-brand);color:#fff;border-color:var(--jc-brand)}
.ht-page-btn:disabled{opacity:.4;cursor:not-allowed}
.ht-per-page{padding:5px 10px;border:1px solid var(--border,#e2e8f0);border-radius:8px;font-size:.7rem;outline:none}
@media(max-width:768px){.ht-table-wrap{overflow-x:auto}}
</style>

<div class="ht-filter-bar no-print" style="margin-top:14px">
  <input type="text" class="ht-search" id="htSearch" placeholder="Search job no, roll, company, material&hellip;">
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
  <button class="ht-period-btn" onclick="htApplyCustomDate()" style="background:var(--jc-brand);color:#fff;border-color:var(--jc-brand)"><i class="bi bi-funnel"></i> Apply</button>
</div>

<div class="ht-bulk-bar no-print" id="htBulkBar">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-check2-square" style="font-size:1.1rem"></i>
    <span style="font-weight:800;font-size:.82rem"><span id="htSelectedCount">0</span> Selected</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button class="ht-bulk-btn" onclick="htSelectAllVisible()">Select All</button>
    <button class="ht-bulk-btn" onclick="htDeselectAll()">Deselect All</button>
    <button class="ht-bulk-print" onclick="htBulkPrint()"><i class="bi bi-printer-fill"></i> Print Selected</button>
  </div>
</div>

<div class="ht-table-wrap">
  <table class="ht-table" id="htTable">
    <thead>
      <tr>
        <th class="ht-cb-cell no-print"><input type="checkbox" id="htCheckAll" onchange="htToggleAll(this.checked)" title="Select all"></th>
        <th onclick="htSortCol(0)">#<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(1)">Job No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(2)">Job Name<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(3)">Roll No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(4)">Material<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(5)">Status<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(6)">Started<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(7)">Completed<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(8)">Duration<span class="ht-sort">▲▼</span></th>
        <th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody id="htBody">
    <?php if (empty($historyJobs)): ?>
      <tr><td colspan="11" style="padding:40px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i><br>No completed jobs yet</td></tr>
    <?php else: ?>
      <?php foreach ($historyJobs as $idx => $h):
        $hSts = $h['status'];
        $hStsLower = strtolower(str_replace(' ', '', $hSts));
        $hStsClass = match($hStsLower) { 'closed'=>'closed','finalized'=>'finalized','completed'=>'completed','qcpassed'=>'qcpassed', default=>'default' };
        $hDur = $h['duration_minutes'] ?? null;
        $hDurStr = ($hDur !== null) ? (floor($hDur/60).'h '.($hDur%60).'m') : '—';
        $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
        $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : ($h['updated_at'] ? date('d M Y, H:i', strtotime($h['updated_at'])) : '—');
        $hSearch = strtolower(($h['job_no']??'').' '.($h['planning_job_name']??'').' '.($h['roll_no']??'').' '.($h['paper_type']??'').' '.($h['company']??''));
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
        <td><?= e($h['planning_job_name'] ?? '—') ?></td>
        <td style="color:var(--jc-brand);font-weight:800"><?= e($h['roll_no'] ?? '—') ?></td>
        <td><?= e($h['paper_type'] ?? '—') ?></td>
        <td><span class="ht-badge ht-badge-<?= $hStsClass ?>"><?= e($hSts) ?></span></td>
        <td class="ht-dim"><?= $hStarted ?></td>
        <td class="ht-dim"><?= $hCompleted ?></td>
        <td class="ht-dim"><?= $hDurStr ?></td>
        <td class="no-print" onclick="event.stopPropagation()">
          <button class="ht-act-btn" onclick="openJobDetail(<?= (int)$h['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
          <button class="ht-act-btn ht-print" onclick="printJobCard(<?= (int)$h['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="ht-pagination no-print" id="htPagination">
    <div class="ht-page-info" id="htPageInfo">Showing 0–0 of 0</div>
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
      <div id="rp-changing-roll-banner" style="display:none;background:#fff3e0;border:1px solid #f59e0b;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:.85rem;color:#92400e">
        <i class="bi bi-arrow-repeat"></i> <strong>Changing Roll:</strong> <span id="rp-changing-roll-text"></span>
      </div>
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
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const BASE_URL = '<?= BASE_URL ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const APP_FOOTER_LEFT = <?= json_encode($appFooterLeft, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const APP_FOOTER_RIGHT = <?= json_encode($appFooterRight, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode(array_values(array_merge($activeJobs, $historyJobs)), JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const IS_ADMIN = false;
let DM_ACTIVE_JOB_ID = 0;
let DM_ROLL_FILTERS_LOADED = false;
let DM_MODAL_LOCKED = false;
let DM_SELECTED_PARENT_ROLLS = [];
let DM_AUTO_REQUIRED_WIDTH = 0;
const DM_AUTO_REFRESH_MS = 45000;
let _timerInterval = null;
let _timerStart = null;
let _timerJobId = null;
let _timerBaseSeconds = 0;

function isJumboTimerActive(job) {
  return !!(job && job.extra_data_parsed && job.extra_data_parsed.timer_active);
}

function jumboTimerStartMs(job) {
  if (!job) return Date.now();
  const startedRaw = (job.extra_data_parsed && job.extra_data_parsed.timer_started_at) || job.started_at || '';
  const parsed = startedRaw ? Date.parse(String(startedRaw).replace(' ', 'T')) : NaN;
  return Number.isFinite(parsed) && parsed > 0 ? parsed : Date.now();
}

function jumboTimerTotalSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const base = Math.max(0, Number(extra.timer_accumulated_seconds || 0));
  if (!extra.timer_active) return Math.floor(base);
  const resumedRaw = extra.timer_last_resumed_at || extra.timer_started_at || job.started_at || '';
  const resumedAt = resumedRaw ? Date.parse(String(resumedRaw).replace(' ', 'T')) : NaN;
  if (!Number.isFinite(resumedAt) || resumedAt <= 0) return Math.floor(base);
  return Math.floor(base + ((Date.now() - resumedAt) / 1000));
}

function showJumboTimerOverlay(job) {
  if (!job) return;
  const existing = document.getElementById('jcTimerOverlay');
  if (existing) existing.remove();

  _timerJobId = Number(job.id || 0);
  _timerStart = jumboTimerStartMs(job);
  _timerBaseSeconds = jumboTimerTotalSeconds(job);

  const jobNo = job.job_no || '';
  const jobLabel = job.planning_job_name || ('Job #' + _timerJobId);
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
      <button class="jc-timer-btn-pause" onclick="pauseTimer()"><i class="bi bi-pause-fill"></i> Pause</button>
      <button class="jc-timer-btn-end" onclick="endTimer()"><i class="bi bi-stop-fill"></i> End</button>
    </div>
  `;
  document.body.appendChild(overlay);

  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = setInterval(() => {
    const diff = Math.max(0, jumboTimerTotalSeconds(job));
    const h = String(Math.floor(diff / 3600)).padStart(2, '0');
    const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
    const s = String(diff % 60).padStart(2, '0');
    const el = document.getElementById('jcTimerCounter');
    if (el) el.textContent = h + ':' + m + ':' + s;
  }, 1000);
}

function resumeRunningJumboTimer(jobId) {
  const job = getJobById(jobId);
  if (!job || String(job.status || '') !== 'Running') {
    alert('Timer is not active for this job.');
    return;
  }
  if (!isJumboTimerActive(job)) {
    alert('Timer is paused. Click Again Start.');
    return;
  }
  showJumboTimerOverlay(job);
}

async function pauseJumboTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'pause_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to pause timer');

  const job = getJobById(jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'paused';
    job.extra_data_parsed.timer_paused_at = data.timer_paused_at || '';
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = Number(data.timer_accumulated_seconds || 0);
  }
}

async function resetJumboTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'reset_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to reset timer');

  const job = getJobById(jobId);
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
    job.extra_data_parsed.timer_ended_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = 0;
  }
}

async function markJumboTimerEnded(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'end_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to end timer');

  const job = getJobById(jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'ended';
    job.extra_data_parsed.timer_ended_at = data.timer_ended_at || '';
  }
}

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
    htGoPage(1);
  } else {
    activePanel.style.display = '';
    historyPanel.style.display = 'none';
    activeBtn.classList.add('active');
    historyBtn.classList.remove('active');
  }
}

// ─── History table: search/filter/sort/pagination/bulk ──────
let HT_PERIOD = 'all';
let HT_PAGE = 1;
let HT_SORT = { col: -1, asc: true };

function htVisibleRows() {
  const rows = Array.from(document.querySelectorAll('#htBody tr[data-id]'));
  const q = (document.getElementById('htSearch')?.value || '').trim().toLowerCase();
  const now = new Date();
  const from = document.getElementById('htDateFrom')?.value || '';
  const to = document.getElementById('htDateTo')?.value || '';

  return rows.filter(function(tr) {
    if (q && !(tr.dataset.search || '').includes(q)) return false;
    if (HT_PERIOD === 'all') return true;

    const dRaw = tr.dataset.completed || '';
    if (!dRaw) return false;
    const d = new Date(dRaw);
    if (isNaN(d.getTime())) return false;

    if (HT_PERIOD === 'today') {
      return d.toISOString().slice(0, 10) === now.toISOString().slice(0, 10);
    }
    if (HT_PERIOD === 'week') {
      const dow = now.getDay() || 7;
      const start = new Date(now);
      start.setDate(now.getDate() - dow + 1);
      start.setHours(0, 0, 0, 0);
      return d >= start;
    }
    if (HT_PERIOD === 'month') {
      return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    }
    if (HT_PERIOD === 'year') {
      return d.getFullYear() === now.getFullYear();
    }
    if (HT_PERIOD === 'custom') {
      const day = d.toISOString().slice(0, 10);
      if (from && day < from) return false;
      if (to && day > to) return false;
      return true;
    }
    return true;
  });
}

function htSetPeriod(period, btn) {
  HT_PERIOD = period;
  document.querySelectorAll('.ht-period-btn').forEach(function(b) { b.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  const df = document.getElementById('htDateFrom');
  const dt = document.getElementById('htDateTo');
  if (df) df.value = '';
  if (dt) dt.value = '';
  htGoPage(1);
}

function htApplyCustomDate() {
  const from = document.getElementById('htDateFrom')?.value || '';
  const to = document.getElementById('htDateTo')?.value || '';
  if (!from && !to) return;
  HT_PERIOD = 'custom';
  document.querySelectorAll('.ht-period-btn').forEach(function(b) { b.classList.remove('active'); });
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

  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) { tr.style.display = 'none'; });
  visible.forEach(function(tr, i) {
    tr.style.display = (i >= start && i < end) ? '' : 'none';
  });

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
    const aNum = parseFloat(aText);
    const bNum = parseFloat(bText);
    if (!isNaN(aNum) && !isNaN(bNum)) return asc ? (aNum - bNum) : (bNum - aNum);
    const aDate = Date.parse(aText);
    const bDate = Date.parse(bText);
    if (!isNaN(aDate) && !isNaN(bDate)) return asc ? (aDate - bDate) : (bDate - aDate);
    return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
  });

  rows.forEach(function(r) { tbody.appendChild(r); });
  document.querySelectorAll('#htTable th').forEach(function(th, i) {
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
  document.querySelectorAll('.ht-row-cb').forEach(function(cb) {
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('ht-selected', cb.checked);
  });
}

function htToggleAll(checked) {
  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb');
    if (cb) cb.checked = checked;
  });
  htUpdateBulk();
}

function htSelectAllVisible() {
  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb');
    if (cb) cb.checked = true;
  });
  const master = document.getElementById('htCheckAll');
  if (master) master.checked = true;
  htUpdateBulk();
}

function htDeselectAll() {
  document.querySelectorAll('.ht-row-cb').forEach(function(cb) { cb.checked = false; });
  const master = document.getElementById('htCheckAll');
  if (master) master.checked = false;
  htUpdateBulk();
}

function htBulkPrint() {
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(function(cb) { return cb.dataset.jobId; });
  if (!ids.length) { alert('No history job selected'); return; }
  const jobs = ids.map(function(id) { return ALL_JOBS.find(function(j) { return j.id == id; }); }).filter(Boolean);
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
      let cardHtml = renderJumboPrintCardHtml(job, qrDataUrl);
      if (mode === 'bw') cardHtml = printBwTransform(cardHtml);
      pages += `<div style="${pb}">${cardHtml}</div>`;
    }
    const w = window.open('', '_blank', 'width=820,height=920');
    w.document.write('<!DOCTYPE html><html><head><title>Bulk Print - ' + jobs.length + ' Job Cards</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>' + pages + '</body></html>');
    w.document.close();
    w.focus();
    setTimeout(function() { w.print(); }, 400);
  })();
}

document.getElementById('htSearch')?.addEventListener('input', function() {
  htGoPage(1);
});

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
  const timerActive = !!document.getElementById('jcTimerOverlay');
  return !detailOpen && !pickerOpen && !timerActive && !DM_MODAL_LOCKED;
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
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!job) return;
  const trimmed = String(rollNo || '').trim();

  if (DM_ACTIVE_CHANGE_ROLL) {
    // Multi-roll mode: put into the correct brown section input
    const inp = document.querySelector('.dm-change-roll-input[data-for-roll=\"' + DM_ACTIVE_CHANGE_ROLL + '\"]');
    if (inp) {
      inp.value = trimmed;
      if (DM_ROLL_CHANGE_MAP[DM_ACTIVE_CHANGE_ROLL]) DM_ROLL_CHANGE_MAP[DM_ACTIVE_CHANGE_ROLL].newRollNo = trimmed;
      lookupSubstituteRoll(DM_ACTIVE_CHANGE_ROLL, trimmed);
    }
  } else {
    // Fallback: legacy single input
    const input = document.getElementById('dm-parent-roll-input');
    if (input) {
      input.value = trimmed;
      DM_PARENT_ROLL_CHANGED = true;
      refreshRollPreview();
      lookupReplacementRoll(job);
    }
  }
  closeRollPicker();
}

async function openRollPicker() {
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!job) return;

  const modal = document.getElementById('dmRollPickerModal');
  if (!modal) return;
  modal.classList.add('active');

  // Show which roll is being changed at top of picker
  const banner = document.getElementById('rp-changing-roll-banner');
  const bannerText = document.getElementById('rp-changing-roll-text');
  if (banner && bannerText) {
    if (DM_ACTIVE_CHANGE_ROLL) {
      const row = document.querySelector('#dm-edit-parent-table tr[data-roll=\"' + DM_ACTIVE_CHANGE_ROLL + '\"]');
      const cells = row ? row.querySelectorAll('td') : [];
      const company = cells[2]?.textContent || '';
      const material = cells[3]?.textContent || '';
      const width = cells[4]?.textContent || '';
      bannerText.textContent = DM_ACTIVE_CHANGE_ROLL + (company && company !== '--' ? ' | ' + company : '') + (material && material !== '--' ? ' | ' + material : '') + (width && width !== '--' ? ' | Width: ' + width + 'mm' : '');
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
    }
  }

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
  job.extra_data_parsed = job.extra_data_parsed || {};
  job.extra_data_parsed.timer_active = true;
  job.extra_data_parsed.timer_state = 'running';
  job.extra_data_parsed.timer_started_at = new Date().toISOString();
  job.extra_data_parsed.timer_ended_at = '';

  showJumboTimerOverlay(job);
}

async function cancelTimer() {
  if (!_timerJobId) return;
  if (!confirm('Cancel will reset this job timer and return it to Pending. Continue?')) return;
  try {
    await resetJumboTimer(_timerJobId);
  } catch (err) {
    alert('Timer reset failed: ' + err.message);
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

async function pauseTimer() {
  const jobId = _timerJobId;
  if (!jobId) return;
  try {
    await pauseJumboTimer(jobId);
  } catch (err) {
    alert('Timer pause failed: ' + err.message);
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

async function endTimer() {
  const jobId = _timerJobId;
  if (!jobId) return;

  try {
    await markJumboTimerEnded(jobId);
  } catch (err) {
    alert('Timer end failed: ' + err.message);
    return;
  }

  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
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
    // Keep child roll row identity intact; only parent reference changes in preview.
    const rollInput = tr.querySelector('[data-field="roll_no"]');
    const stableRoll = String(rollInput?.value || tr.getAttribute('data-original-roll') || tr.getAttribute('data-roll') || '').trim();
    tr.setAttribute('data-roll', stableRoll);
    const parentCell = tr.querySelector('.dm-preview-parent');
    if (parentCell) parentCell.textContent = nextParent || '-';
  });
}

function syncParentSelectionUI() {
  const checks = Array.from(document.querySelectorAll('.dm-parent-select-cb'));
  DM_SELECTED_PARENT_ROLLS = checks.filter(cb => cb.checked).map(cb => String(cb.value || '').trim()).filter(Boolean);
  checks.forEach(cb => {
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('jc-parent-select-row-selected', !!cb.checked);
  });
  const hint = document.getElementById('dm-parent-selection-hint');
  if (hint) {
    hint.textContent = DM_SELECTED_PARENT_ROLLS.length
      ? ('Selected parent roll(s): ' + DM_SELECTED_PARENT_ROLLS.join(', '))
      : 'Select at least one parent roll for auto search.';
  }
}

function getRequiredWidthForAutoSearch(job) {
  if (!job) return 0;
  const editableRows = Array.from(document.querySelectorAll('#dm-roll-preview-table tbody tr[data-roll]'));
  let editableSum = 0;
  editableRows.forEach(tr => {
    const statusEl = tr.querySelector('[data-field="status"]');
    const widthEl = tr.querySelector('[data-field="width"]');
    const statusRaw = String(statusEl?.value || tr.dataset.status || 'Job Assign').toLowerCase();
    if (!statusRaw.includes('job')) return;
    const w = Number(widthEl?.value || tr.dataset.width || 0);
    if (Number.isFinite(w) && w > 0) editableSum += w;
  });
  if (editableSum > 0) return Number(editableSum.toFixed(2));

  const extra = job.extra_data_parsed || {};
  const childRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
  let sum = 0;
  childRows.forEach(r => {
    const w = Number(r.width ?? r.width_mm ?? 0);
    if (Number.isFinite(w) && w > 0) sum += w;
  });
  if (sum > 0) return Number(sum.toFixed(2));

  const selectedChecks = Array.from(document.querySelectorAll('.dm-parent-select-cb:checked'));
  let parentSum = 0;
  selectedChecks.forEach(cb => {
    const tr = cb.closest('tr');
    const w = Number(tr?.dataset?.width || 0);
    if (Number.isFinite(w) && w > 0) parentSum += w;
  });
  if (parentSum > 0) return Number(parentSum.toFixed(2));

  const fallback = Number(job.width_mm ?? extra.parent_details?.width_mm ?? 0);
  return Number.isFinite(fallback) && fallback > 0 ? Number(fallback.toFixed(2)) : 0;
}

function renderAutoSearchCandidates(rows, requiredWidth) {
  const box = document.getElementById('dm-auto-roll-list');
  if (!box) return;
  const list = Array.isArray(rows) ? rows : [];
  if (!list.length) {
    box.style.display = 'block';
    box.innerHTML = '<div class="jc-inline-empty">No substitute rolls found for required width ' + esc(String(requiredWidth || 0)) + ' mm.</div>';
    return;
  }

  box.style.display = 'block';
  box.innerHTML = '<table><thead><tr><th>Roll</th><th>Company</th><th>Type</th><th>Width</th><th>Length</th><th>Wastage</th><th>Action</th></tr></thead><tbody>'
    + list.map((r, idx) => {
      const rn = String(r.roll_no || '').trim();
      const wastage = Number(r.wastage_mm || 0);
      return '<tr class="' + (idx === 0 ? 'jc-auto-best' : '') + '">'
        + '<td style="font-weight:800;color:var(--jc-brand)">' + esc(rn || '-') + (idx === 0 ? ' <span style="font-size:.6rem;color:#16a34a">BEST</span>' : '') + '</td>'
        + '<td>' + esc(String(r.company || '-')) + '</td>'
        + '<td>' + esc(String(r.paper_type || '-')) + '</td>'
        + '<td>' + esc(String(r.width_mm || '-')) + '</td>'
        + '<td>' + esc(String(r.length_mtr || '-')) + '</td>'
        + '<td>' + esc(String(wastage.toFixed(2))) + '</td>'
        + '<td><button type="button" class="jc-action-btn jc-btn-complete" onclick="selectAutoCandidate(\'' + escJs(rn) + '\')">Use</button></td>'
        + '</tr>';
    }).join('')
    + '</tbody></table>';
}

function selectAutoCandidate(rollNo) {
  const input = document.getElementById('dm-parent-roll-input');
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!input || !job) return;
  input.value = String(rollNo || '').trim();
  DM_PARENT_ROLL_CHANGED = true;
  refreshRollPreview();
  lookupReplacementRoll(job);
}

async function runAutoSearchForParents() {
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!job) return;
  syncParentSelectionUI();
  if (!DM_SELECTED_PARENT_ROLLS.length) {
    alert('Select at least one parent roll before auto search.');
    return;
  }

  const requiredWidth = getRequiredWidthForAutoSearch(job);
  DM_AUTO_REQUIRED_WIDTH = requiredWidth;
  const meta = document.getElementById('dm-auto-search-meta');
  if (meta) meta.textContent = requiredWidth > 0
    ? ('Required width from job size: ' + requiredWidth + ' mm')
    : 'Required width could not be derived from job data.';

  if (!(requiredWidth > 0)) {
    renderAutoSearchCandidates([], 0);
    return;
  }

  const selectedRow = document.querySelector('.dm-parent-select-cb:checked')?.closest('tr');
  const company = String(selectedRow?.dataset?.company || job.company || '').trim();
  const paperType = String(selectedRow?.dataset?.paperType || job.paper_type || '').trim();

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_suggestions');
    if (paperType) url.searchParams.set('paper_type', paperType);
    if (company) url.searchParams.set('company', company);
    url.searchParams.set('limit', '300');
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      renderAutoSearchCandidates([], requiredWidth);
      return;
    }

    const selectedSet = new Set(DM_SELECTED_PARENT_ROLLS.map(s => s.toLowerCase()));
    const all = (Array.isArray(data.rolls) ? data.rolls : []).filter(r => {
      const rn = String(r.roll_no || '').trim().toLowerCase();
      if (!rn || selectedSet.has(rn)) return false;
      const w = Number(r.width_mm || 0);
      return Number.isFinite(w) && w >= requiredWidth;
    });

    const exact = all.filter(r => Math.abs(Number(r.width_mm || 0) - requiredWidth) < 0.01)
      .sort((a, b) => Number(b.length_mtr || 0) - Number(a.length_mtr || 0));
    if (exact.length) {
      const bestExact = exact[0];
      const input = document.getElementById('dm-parent-roll-input');
      if (input) {
        input.value = String(bestExact.roll_no || '').trim();
        DM_PARENT_ROLL_CHANGED = true;
        refreshRollPreview();
        lookupReplacementRoll(job);
      }
      renderAutoSearchCandidates(exact.slice(0, 20).map(r => ({ ...r, wastage_mm: Number(r.width_mm || 0) - requiredWidth })), requiredWidth);
      return;
    }

    const bigger = all
      .map(r => {
        const width = Number(r.width_mm || 0);
        return {
          ...r,
          width_mm: width,
          length_mtr: Number(r.length_mtr || 0),
          wastage_mm: Number((width - requiredWidth).toFixed(2)),
          id_num: Number(r.id || 0),
        };
      })
      .sort((a, b) => {
        if (a.wastage_mm !== b.wastage_mm) return a.wastage_mm - b.wastage_mm;
        if (a.width_mm !== b.width_mm) return a.width_mm - b.width_mm;
        if (a.length_mtr !== b.length_mtr) return b.length_mtr - a.length_mtr;
        return b.id_num - a.id_num;
      });

    renderAutoSearchCandidates(bigger.slice(0, 40), requiredWidth);
  } catch (err) {
    renderAutoSearchCandidates([], requiredWidth);
  }
}

function renderInlineParentSuggestions(job, rows) {
  const box = document.getElementById('dm-inline-parent-suggest');
  if (!box) return;
  const list = Array.isArray(rows) ? rows : [];
  if (!list.length) {
    box.innerHTML = '<div class="jc-inline-empty">No suitable roll suggestions found.</div>';
    box.style.display = 'block';
    return;
  }
  box.innerHTML = list.slice(0, 8).map(r => {
    const rn = String(r.roll_no || '').trim();
    const pt = String(r.paper_type || '-');
    const co = String(r.company || '-');
    const wd = String(r.width_mm ?? '-');
    const ln = String(r.length_mtr ?? '-');
    return '<div class="jc-inline-suggest-row">'
      + '<div class="rn">' + esc(rn || '-') + '</div>'
      + '<div class="meta">' + esc(pt) + ' • ' + esc(co) + '</div>'
      + '<div class="meta">W: ' + esc(wd) + '</div>'
      + '<div class="meta">L: ' + esc(ln) + '</div>'
      + '<button type="button" class="jc-action-btn jc-btn-complete" onclick="selectInlineParentSuggestion(\'' + escJs(rn) + '\')">Use</button>'
      + '</div>';
  }).join('');
  box.style.display = 'block';
}

async function loadInlineParentSuggestions(job) {
  const input = document.getElementById('dm-parent-roll-input');
  const box = document.getElementById('dm-inline-parent-suggest');
  if (!input || !box || !job) return;
  const q = String(input.value || '').trim();
  if (!DM_PARENT_ROLL_CHANGED || q.length < 2) {
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }
  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_suggestions');
    url.searchParams.set('q', q);
    if (job.paper_type) url.searchParams.set('paper_type', String(job.paper_type));
    if (job.company) url.searchParams.set('company', String(job.company));
    url.searchParams.set('limit', '20');
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      box.style.display = 'none';
      return;
    }
    renderInlineParentSuggestions(job, data.rolls || []);
  } catch (err) {
    box.style.display = 'none';
  }
}

function selectInlineParentSuggestion(rollNo) {
  const input = document.getElementById('dm-parent-roll-input');
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!input || !job) return;
  input.value = String(rollNo || '').trim();
  DM_PARENT_ROLL_CHANGED = true;
  refreshRollPreview();
  lookupReplacementRoll(job);
  const box = document.getElementById('dm-inline-parent-suggest');
  if (box) box.style.display = 'none';
}

function setRequestButtonState(enabled, message) {
  const requestBtn = document.getElementById('dm-request-roll-btn-footer') || document.getElementById('dm-request-roll-btn');
  if (!requestBtn) return;
  requestBtn.disabled = !enabled;
  requestBtn.dataset.rollValid = enabled ? '1' : '0';
  requestBtn.dataset.validationMessage = String(message || 'Enter a valid suitable roll before sending request.');
}

function escJs(v) {
  return String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '');
}

let DM_PARENT_ROLL_CHANGED = false;

let DM_REASON_VOICE = null;
let DM_REASON_LISTENING = false;
let DM_REASON_VOICE_LANG = 'hi-IN';

function startReasonVoice() {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) { alert('Voice input not supported. Use Chrome, Edge, or Safari.'); return; }
  if (!DM_REASON_VOICE) {
    DM_REASON_VOICE = new SpeechRecognition();
    DM_REASON_VOICE.continuous = false;
    DM_REASON_VOICE.interimResults = true;
    DM_REASON_VOICE.onstart = function() {
      DM_REASON_LISTENING = true;
      const btn = document.getElementById('dm-reason-voice-btn');
      if (btn) { btn.classList.add('listening'); btn.innerHTML = '<i class="bi bi-mic-fill"></i> <span class="voiceStatus">Listening...</span>'; btn.style.background = '#dc2626'; }
    };
    DM_REASON_VOICE.onresult = function(e) {
      for (let i = e.resultIndex; i < e.results.length; i++) {
        if (e.results[i].isFinal) {
          const transcript = e.results[i][0].transcript;
          const field = document.getElementById('dm-change-reason');
          if (field) { const cur = field.value.trim(); field.value = (cur ? cur + ' ' : '') + transcript; }
          translateReasonVoice(transcript);
        }
      }
    };
    DM_REASON_VOICE.onerror = function(e) {
      console.error('Voice error:', e.error);
      updateReasonVoiceBtn('Error');
    };
    DM_REASON_VOICE.onend = function() {
      DM_REASON_LISTENING = false;
      updateReasonVoiceBtn('Done');
      setTimeout(() => updateReasonVoiceBtn('Speak'), 2000);
    };
  }
  if (DM_REASON_LISTENING) {
    DM_REASON_VOICE.stop();
    updateReasonVoiceBtn('Stopped');
  } else {
    document.getElementById('dm-reason-voice-display').style.display = 'none';
    const langSel = document.getElementById('dm-reason-lang-select');
    DM_REASON_VOICE_LANG = langSel ? langSel.value : 'hi-IN';
    DM_REASON_VOICE.lang = DM_REASON_VOICE_LANG;
    DM_REASON_VOICE.start();
  }
}

function updateReasonVoiceBtn(status) {
  const btn = document.getElementById('dm-reason-voice-btn');
  if (!btn) return;
  btn.classList.remove('listening');
  btn.innerHTML = '<i class="bi bi-mic-fill"></i> <span class="voiceStatus">' + status + '</span>';
  btn.style.background = status === 'Listening...' ? '#dc2626' : '#d97706';
}

function translateReasonVoice(originalText) {
  const langMap = { 'bn-IN': 'bn', 'hi-IN': 'hi', 'en-US': 'en' };
  const sourceLang = langMap[DM_REASON_VOICE_LANG] || 'en';
  document.getElementById('dm-reason-voice-original').textContent = originalText;
  document.getElementById('dm-reason-voice-display').style.display = 'block';
  if (sourceLang === 'en') { document.getElementById('dm-reason-voice-english').textContent = originalText; return; }
  document.getElementById('dm-reason-voice-english').textContent = 'Translating...';
  fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(originalText) + '&langpair=' + sourceLang + '|en')
    .then(r => r.json())
    .then(data => {
      if (data.responseStatus === 200 && data.responseData?.translatedText) {
        document.getElementById('dm-reason-voice-english').textContent = data.responseData.translatedText;
      } else { document.getElementById('dm-reason-voice-english').textContent = originalText; }
    })
    .catch(() => { document.getElementById('dm-reason-voice-english').textContent = originalText; });
}

// ─── QR scan result → roll number extraction ────────────────
function extractRollNoFromQr(scanned, callback) {
  const text = String(scanned || '').trim();
  if (!text) { callback(''); return; }

  // If it's a URL with ?id= parameter, look up roll_no via API
  if (text.includes('paper_stock/view.php') && text.includes('id=')) {
    try {
      const url = new URL(text, window.location.origin);
      const stockId = url.searchParams.get('id');
      if (stockId) {
        fetch(API_BASE + '?action=get_roll_by_id&id=' + encodeURIComponent(stockId))
          .then(r => r.json())
          .then(data => {
            if (data.ok && data.roll && data.roll.roll_no) {
              callback(String(data.roll.roll_no).trim());
            } else {
              // Fallback: use the ID itself
              callback(stockId);
            }
          })
          .catch(() => callback(stockId));
        return;
      }
    } catch(e) {}
  }

  // If it's a URL with /roll/ path (e.g. .../roll/T-1038-A)
  const rollPathMatch = text.match(/\/roll\/([^\/?#]+)/);
  if (rollPathMatch) {
    callback(decodeURIComponent(rollPathMatch[1]));
    return;
  }

  // If it's any other URL, try to extract meaningful last segment
  if (text.startsWith('http://') || text.startsWith('https://')) {
    try {
      const url = new URL(text);
      // Check for roll_no param
      const rnParam = url.searchParams.get('roll_no') || url.searchParams.get('rn');
      if (rnParam) { callback(rnParam); return; }
      // Last path segment
      const segments = url.pathname.split('/').filter(Boolean);
      if (segments.length) { callback(decodeURIComponent(segments[segments.length - 1])); return; }
    } catch(e) {}
  }

  // Check for "Roll:1363 | Type: Silver..." label format — extract just the number/code
  const rollLabelMatch = text.match(/^Roll\s*[:：]\s*([^\s|,]+)/i);
  if (rollLabelMatch) {
    callback(rollLabelMatch[1].trim());
    return;
  }

  // Not a URL — use as-is (plain roll number)
  callback(text);
}

// ─── QR Scanner for roll input ──────────────────────────────
let DM_QR_SCANNER = null;
let DM_ACTIVE_CHANGE_ROLL = ''; // which original parent roll the picker/QR targets

function openQrScannerFor(origRoll) {
  DM_ACTIVE_CHANGE_ROLL = origRoll;
  // Clean up any previous scanner first
  if (DM_QR_SCANNER) { try { DM_QR_SCANNER.clear(); } catch(e){} DM_QR_SCANNER = null; }

  // Create or reset scanner modal overlay
  let overlay = document.getElementById('dm-qr-scanner-overlay');
  if (overlay) {
    const reader = document.getElementById('dm-qr-reader');
    if (reader) reader.innerHTML = '';
  } else {
    overlay = document.createElement('div');
    overlay.id = 'dm-qr-scanner-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:10000;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px';
    overlay.innerHTML = `
      <div style="background:#fff;border-radius:16px;padding:20px;max-width:400px;width:100%">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <h3 style="margin:0;font-size:1rem"><i class="bi bi-qr-code-scan"></i> Scan Roll QR Code</h3>
          <button onclick="closeQrScanner()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:#64748b"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="dm-qr-reader" style="width:100%"></div>
        <div id="dm-qr-status" style="text-align:center;margin-top:10px;font-size:.82rem;color:#64748b">Point camera at QR/barcode on roll label</div>
      </div>`;
    document.body.appendChild(overlay);
  }
  overlay.style.display = 'flex';

  // Start scanner after short delay so DOM is ready
  setTimeout(function() {
    try {
      DM_QR_SCANNER = new Html5QrcodeScanner('dm-qr-reader', {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        rememberLastUsedCamera: false,
        formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.CODE_128, Html5QrcodeSupportedFormats.CODE_39, Html5QrcodeSupportedFormats.EAN_13]
      }, false);
      DM_QR_SCANNER.render(function(decodedText) {
        const scanned = String(decodedText || '').trim();
        if (!scanned || !DM_ACTIVE_CHANGE_ROLL) return;
        extractRollNoFromQr(scanned, function(rollNo) {
          if (!rollNo) return;
          const inp = document.querySelector('.dm-change-roll-input[data-for-roll="' + DM_ACTIVE_CHANGE_ROLL + '"]');
          if (inp) {
            inp.value = rollNo;
            if (DM_ROLL_CHANGE_MAP[DM_ACTIVE_CHANGE_ROLL]) DM_ROLL_CHANGE_MAP[DM_ACTIVE_CHANGE_ROLL].newRollNo = rollNo;
            lookupSubstituteRoll(DM_ACTIVE_CHANGE_ROLL, rollNo);
          }
          closeQrScanner();
        });
      }, function(err) {
        // Ignore continuous scan misses
      });
    } catch(e) {
      console.error('QR Scanner init error:', e);
      const statusEl = document.getElementById('dm-qr-status');
      if (statusEl) statusEl.textContent = 'Error: ' + e.message;
    }
  }, 150);
}

function closeQrScanner() {
  if (DM_QR_SCANNER) { try { DM_QR_SCANNER.clear(); } catch(e){} DM_QR_SCANNER = null; }
  const overlay = document.getElementById('dm-qr-scanner-overlay');
  if (overlay) overlay.style.display = 'none';
  const reader = document.getElementById('dm-qr-reader');
  if (reader) reader.innerHTML = '';
}

// ─── Per-roll change sections ───────────────────────────────
let DM_ROLL_CHANGE_MAP = {};    // { originalRollNo: { newRollNo, validated, details } }

function rebuildRollChangeSections() {
  const container = document.getElementById('dm-roll-change-sections');
  if (!container) return;
  const checked = Array.from(document.querySelectorAll('#dm-edit-parent-table .dm-parent-select-cb:checked'));
  const checkedRolls = checked.map(cb => cb.value);

  // Remove sections for unchecked rolls
  Object.keys(DM_ROLL_CHANGE_MAP).forEach(rn => {
    if (!checkedRolls.includes(rn)) delete DM_ROLL_CHANGE_MAP[rn];
  });

  // Preserve existing input values
  container.querySelectorAll('.dm-change-section').forEach(sec => {
    const origRoll = sec.dataset.originalRoll;
    const inp = sec.querySelector('.dm-change-roll-input');
    if (origRoll && inp && DM_ROLL_CHANGE_MAP[origRoll]) {
      DM_ROLL_CHANGE_MAP[origRoll].newRollNo = inp.value.trim();
    }
  });

  if (checkedRolls.length === 0) {
    container.innerHTML = '';
    updateChangeRequestButton();
    return;
  }

  let html = '';
  checkedRolls.forEach(function(origRoll) {
    const existing = DM_ROLL_CHANGE_MAP[origRoll] || {};
    DM_ROLL_CHANGE_MAP[origRoll] = existing;
    const safeId = CSS.escape(origRoll);
    const inputVal = existing.newRollNo || '';
    // Get parent roll info from table row
    const row = document.querySelector('#dm-edit-parent-table tr[data-roll=\"' + origRoll + '\"]');
    const cells = row ? row.querySelectorAll('td') : [];
    const company = cells[2]?.textContent || '';
    const material = cells[3]?.textContent || '';
    const width = cells[4]?.textContent || '';

    html += `<div class="dm-change-section jc-detail-section" data-original-roll="${esc(origRoll)}" style="background:#8b4513;border-radius:12px;padding:14px;margin-bottom:16px">
      <h3 style="color:#fff;margin-top:0"><i class="bi bi-arrow-repeat"></i> Change: <span style="color:#fbbf24">${esc(origRoll)}</span>
        <span style="font-size:.75rem;font-weight:400;color:#d4a574">${company && company !== '--' ? ' | ' + esc(company) : ''}${material && material !== '--' ? ' | ' + esc(material) : ''}${width && width !== '--' ? ' | W:' + esc(width) : ''}</span>
      </h3>
      <div class="jc-form-row" style="margin-bottom:10px">
        <div class="jc-form-group"><label style="color:#f5f5f5">Substitute Roll</label>
          <div class="jc-roll-pick-row">
            <input type="text" class="dm-change-roll-input" data-for-roll="${esc(origRoll)}" value="${esc(inputVal)}" placeholder="Enter substitute roll number" autocomplete="off" style="border:1px solid #d4a574;padding:8px 12px">
            <button type="button" class="jc-action-btn jc-btn-view" onclick="openRollPickerFor('${esc(origRoll).replace(/'/g, '&#39;')}')" style="background:#d4a574;color:#1f1f1f"><i class="bi bi-search"></i> Browse</button>
            <button type="button" class="jc-action-btn jc-btn-view" onclick="openQrScannerFor('${esc(origRoll).replace(/'/g, '&#39;')}')" style="background:#d4a574;color:#1f1f1f"><i class="bi bi-qr-code-scan"></i> QR</button>
          </div>
        </div>
      </div>
      <div class="dm-change-roll-detail" data-detail-for="${esc(origRoll)}">${existing.detailHtml || ''}</div>
    </div>`;
  });
  container.innerHTML = html;

  // Attach input listeners for each section
  container.querySelectorAll('.dm-change-roll-input').forEach(inp => {
    inp.addEventListener('input', function() {
      const origRoll = this.dataset.forRoll;
      if (DM_ROLL_CHANGE_MAP[origRoll]) DM_ROLL_CHANGE_MAP[origRoll].newRollNo = this.value.trim();
      lookupSubstituteRoll(origRoll, this.value.trim());
    });
  });
}

function openRollPickerFor(origRoll) {
  DM_ACTIVE_CHANGE_ROLL = origRoll;
  openRollPicker();
}

async function lookupSubstituteRoll(origRoll, rollNo) {
  const job = getJobById(DM_ACTIVE_JOB_ID);
  if (!job) return;
  const detailBox = document.querySelector('.dm-change-roll-detail[data-detail-for=\"' + origRoll + '\"]');
  if (!detailBox) return;
  const entry = DM_ROLL_CHANGE_MAP[origRoll] || {};
  DM_ROLL_CHANGE_MAP[origRoll] = entry;
  entry.newRollNo = rollNo;

  if (!rollNo) {
    entry.validated = false;
    entry.detailHtml = '';
    detailBox.innerHTML = '';
    updateChangeRequestButton();
    return;
  }

  if (rollNo.toLowerCase() === origRoll.toLowerCase()) {
    entry.validated = false;
    entry.detailHtml = '<div style="color:#fca5a5;padding:6px 0;font-size:.82rem">Same as original roll. Enter a different roll.</div>';
    detailBox.innerHTML = entry.detailHtml;
    updateChangeRequestButton();
    return;
  }

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_lookup');
    url.searchParams.set('roll_no', rollNo);
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok || !data.roll) {
      entry.validated = false;
      entry.detailHtml = '<div style="color:#fca5a5;padding:6px 0;font-size:.82rem"><i class="bi bi-x-circle"></i> Roll not found.</div>';
      detailBox.innerHTML = entry.detailHtml;
      updateChangeRequestButton();
      return;
    }
    const roll = data.roll;
    const requiredType = String(job.paper_type || '').trim().toLowerCase();
    const rollType = String(roll.paper_type || '').trim().toLowerCase();
    const typeOk = requiredType === '' || rollType === requiredType;
    const statusOk = ['main', 'stock', 'job assign', 'available'].includes(String(roll.status || '').trim().toLowerCase());
    const suitable = typeOk && statusOk;
    let statusMsg = suitable ? '<span style="color:#86efac"><i class="bi bi-check-circle"></i> Suitable roll</span>' : '<span style="color:#fca5a5"><i class="bi bi-exclamation-triangle"></i> ' + (!typeOk ? 'Paper type mismatch' : 'Status not suitable') + '</span>';

    entry.validated = suitable;
    entry.detailHtml = `<div style="background:rgba(0,0,0,.2);border-radius:8px;padding:10px;margin-top:6px">
      <div style="margin-bottom:6px;font-size:.82rem">${statusMsg}</div>
      <div class="dm-subst-detail-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:.8rem">
        <div><span style="color:#d4a574">Paper Type:</span> <span style="color:#fff">${esc(roll.paper_type || '-')}</span></div>
        <div><span style="color:#d4a574">Company:</span> <span style="color:#fff">${esc(roll.company || '-')}</span></div>
        <div><span style="color:#d4a574">Status:</span> <span style="color:#fff">${esc(roll.status || '-')}</span></div>
        <div><span style="color:#d4a574">Width:</span> <span style="color:#fff">${esc(String(roll.width_mm || '-'))}</span></div>
        <div><span style="color:#d4a574">Length:</span> <span style="color:#fff">${esc(String(roll.length_mtr || '-'))}</span></div>
        <div><span style="color:#d4a574">GSM:</span> <span style="color:#fff">${esc(String(roll.gsm || '-'))}</span></div>
      </div>
    </div>`;
    detailBox.innerHTML = entry.detailHtml;
    updateChangeRequestButton();
  } catch (err) {
    entry.validated = false;
    entry.detailHtml = '<div style="color:#fca5a5;padding:6px 0;font-size:.82rem">Lookup failed. Try again.</div>';
    detailBox.innerHTML = entry.detailHtml;
    updateChangeRequestButton();
  }
}

function updateChangeRequestButton() {
  const btn = document.getElementById('dm-request-roll-btn-footer');
  if (!btn) return;
  const checkedRolls = Array.from(document.querySelectorAll('#dm-edit-parent-table .dm-parent-select-cb:checked')).map(cb => cb.value);
  if (checkedRolls.length === 0) {
    btn.disabled = true;
    btn.dataset.rollValid = '0';
    btn.dataset.validationMessage = 'Select at least one parent roll to change.';
    return;
  }
  let allValid = true;
  let missing = [];
  checkedRolls.forEach(rn => {
    const entry = DM_ROLL_CHANGE_MAP[rn];
    if (!entry || !entry.newRollNo) { allValid = false; missing.push(rn); }
    else if (!entry.validated) { allValid = false; missing.push(rn); }
  });
  if (allValid) {
    btn.disabled = false;
    btn.dataset.rollValid = '1';
    btn.dataset.validationMessage = '';
  } else {
    btn.disabled = true;
    btn.dataset.rollValid = '0';
    btn.dataset.validationMessage = missing.length ? 'Enter valid substitute roll for: ' + missing.join(', ') : 'Enter valid substitute rolls for all selected parent rolls.';
  }
}

async function submitChangeRequest(id) {
  const requestBtn = document.getElementById('dm-request-roll-btn-footer') || document.getElementById('dm-request-roll-btn');
  const validationMessage = requestBtn?.dataset.validationMessage || 'Please enter valid substitute rolls for all selected parent rolls.';
  if (!requestBtn || requestBtn.disabled || requestBtn.dataset.rollValid !== '1') {
    alert(validationMessage);
    return;
  }
  const rows = collectEditedRows();
  const wastage = Number(document.getElementById('dm-wastage-kg')?.value || 0);
  const remarks = document.getElementById('dm-operator-notes')?.value || '';
  const changeReason = String(document.getElementById('dm-change-reason')?.value || '').trim();

  // Build roll change mappings from all brown sections
  const rollChanges = [];
  Object.keys(DM_ROLL_CHANGE_MAP).forEach(origRoll => {
    const entry = DM_ROLL_CHANGE_MAP[origRoll];
    if (entry && entry.newRollNo && entry.validated) {
      rollChanges.push({ original_roll: origRoll, substitute_roll: entry.newRollNo });
    }
  });

  if (rollChanges.length === 0) {
    alert('Select parent rolls and enter valid substitute rolls.');
    return;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'submit_jumbo_change_request');
  fd.append('job_id', id);
  fd.append('parent_roll_no', rollChanges[0].substitute_roll);
  fd.append('roll_changes_json', JSON.stringify(rollChanges));
  fd.append('rows_json', JSON.stringify(rows));
  fd.append('wastage_kg', String(wastage));
  fd.append('operator_remarks', remarks);
  fd.append('change_reason', changeReason);

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

    const requiredWidth = Number(getRequiredWidthForAutoSearch(job) || job.width_mm || job.extra_data_parsed?.parent_details?.width_mm || 0);
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
  const timerActive = isJumboTimerActive(job);
  const timerState = String((job.extra_data_parsed && job.extra_data_parsed.timer_state) || '').toLowerCase();
  const isFinishedJob = ['closed', 'finalized', 'completed', 'finished', 'qc passed', 'qc failed'].includes(String(sts || '').toLowerCase());
  const stsClass = {Pending:'pending',Running:'running',Closed:'completed',Finalized:'completed'}[sts]||'pending';
  const extra = job.extra_data_parsed || {};
  const hasPendingRequest = Number(job.pending_change_requests || 0) > 0;
  const latestReqStatus = String(job.latest_request_status || '').trim().toLowerCase();
  const hasRejectedRequest = (!hasPendingRequest && latestReqStatus === 'rejected');
  const latestReviewNote = String(job.latest_request_review_note || '').trim();
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
      <div class="jc-timing-box jc-counter-box"><div class="jc-timing-label">Counter</div><div class="jc-timing-value">${(sts === 'Running' && timerState === 'paused') ? `${Math.floor((Number(extra.timer_accumulated_seconds || 0))/3600).toString().padStart(2,'0')}:${Math.floor((Number(extra.timer_accumulated_seconds || 0)%3600)/60).toString().padStart(2,'0')}:${Math.floor(Number(extra.timer_accumulated_seconds || 0)%60).toString().padStart(2,'0')}` : ((sts === 'Running' && startedTs) ? `<span class="jc-timer" data-started="${startedTs}">00:00:00</span>` : (dur !== null && dur !== undefined ? `${Math.floor(dur/60)}h ${dur%60}m` : (sts === 'Pending' ? '<span style="font-size:.9rem;color:#94a3b8">Not Started</span>' : '--:--:--')))}</div></div>
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

  // Edit tab: same layout as execution tab + brown parent selection box (NO timing, NO operator entry, NO photo)
  let editHtml = '';
  editHtml += summaryHtml;

  // Multi-parent roll collection (same logic as main JMB page)
  {
    const liveRollMap = job.live_roll_map || {};
    const p = extra.parent_details || {};
    const refChildRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
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
    const allParentRollNos = Object.keys(seenParents).sort(function(a, b) {
      return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    });
    const parentRowsMeta = [];
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
        parentRowsMeta.push({
          roll_no: prn,
          company: company || '',
          paper_type: ptype || '',
          width_mm: Number(width || 0),
          length_mtr: Number(length || 0),
          status: liveStatus || '',
          remarks: liveRemarks || ''
        });
        parentTableHtml += `<tr><td style="color:var(--jc-brand);font-weight:700">${esc(prn)}</td><td>${esc(company || '--')}</td><td>${esc(ptype || '--')}</td><td>${esc(width + '')}</td><td>${esc(length + '')}</td><td>${esc(weight + '')}</td><td>${esc(sqm + '')}</td><td>${esc(gsm + '')}</td><td>${esc(liveStatus)}</td><td>${esc(liveRemarks || '--')}</td></tr>`;
      });
      parentTableHtml += '</table>';
      executionRollHtml += `<div class="jc-detail-section"><h3><i class="bi bi-inbox"></i> Parent Roll${allParentRollNos.length > 1 ? 's' : ''}</h3><div class="jc-table-shell jc-parent-shell"><div style="overflow-x:auto">${parentTableHtml}</div></div></div>`;

      // Edit tab: parent roll table WITH checkboxes for selection
      let editParentTableHtml = '<table class="jc-soft-table" id="dm-edit-parent-table"><tr><th style="width:40px"><i class="bi bi-check2-square"></i></th><th>Roll No</th><th>Paper Company</th><th>Material</th><th>Width</th><th>Length</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Status</th><th>Remarks</th></tr>';
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
        editParentTableHtml += `<tr data-roll="${esc(prn)}"><td style="text-align:center"><input type="checkbox" class="dm-parent-select-cb" value="${esc(prn)}"></td><td style="color:var(--jc-brand);font-weight:700">${esc(prn)}</td><td>${esc(company || '--')}</td><td>${esc(ptype || '--')}</td><td>${esc(width + '')}</td><td>${esc(length + '')}</td><td>${esc(weight + '')}</td><td>${esc(sqm + '')}</td><td>${esc(gsm + '')}</td><td>${esc(liveStatus)}</td><td>${esc(liveRemarks || '--')}</td></tr>`;
      });
      editParentTableHtml += '</table>';
      editHtml += `<div class="jc-detail-section"><h3><i class="bi bi-inbox"></i> Parent Roll${allParentRollNos.length > 1 ? 's' : ''} <span style="font-size:.75rem;font-weight:400;color:#64748b">(select to change)</span></h3><div class="jc-table-shell jc-parent-shell"><div style="overflow-x:auto">${editParentTableHtml}</div></div></div>`;
      // Dynamic container for brown change sections (one per checked roll)
      editHtml += `<div id="dm-roll-change-sections"></div>`;
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
    allRows.sort(function(a, b) {
      return String(a.roll_no || '').localeCompare(String(b.roll_no || ''), undefined, { numeric: true, sensitivity: 'base' });
    });
    let executionRowsHtml = '<table class="jc-soft-table"><thead><tr><th>Parent Roll</th><th>Child Roll</th><th>Type</th><th>Width</th><th>Length</th><th>Status</th><th>Wastage</th><th>Remarks</th></tr></thead><tbody>';
    allRows.forEach(function(r) {
      executionRowsHtml += `<tr><td style="font-weight:700">${esc(r.parent_roll_no || '—')}</td><td style="color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '—')}</td><td>${esc(r.type || '—')}</td><td>${esc((r.width ?? '—') + '')}</td><td>${esc((r.length ?? '—') + '')}</td><td>${esc(r.status || '—')}</td><td>${esc((r.wastage ?? 0) + '')}</td><td>${esc(r.remarks || '—')}</td></tr>`;
    });
    executionRowsHtml += '</tbody></table>';
    executionRollHtml += `<div class="jc-detail-section"><h3><i class="bi bi-table"></i> Child / Stock Rolls</h3><div class="jc-table-shell jc-child-shell"><div style="overflow-x:auto">${executionRowsHtml}</div></div></div>`;
    // Edit tab uses the exact same child/stock table (read-only, same as execution)
    editHtml += `<div class="jc-detail-section"><h3><i class="bi bi-table"></i> Child / Stock Rolls</h3><div class="jc-table-shell jc-child-shell"><div style="overflow-x:auto">${executionRowsHtml}</div></div></div>`;
  }

  // Edit tab: reason for change (text + voice)
  editHtml += `<div class="jc-detail-section" style="background:#fef3c7;border-radius:12px;padding:14px;margin-bottom:16px">
    <h3 style="margin-top:0;color:#92400e"><i class="bi bi-chat-left-text"></i> Reason for Change</h3>
    <div style="display:flex;gap:8px;align-items:flex-start">
      <div style="flex:1">
        <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px">
          <select id="dm-reason-lang-select" onchange="this.setAttribute('data-voice-lang',this.value)" style="padding:4px 8px;border:1px solid #d97706;border-radius:6px;font-size:.72rem;font-weight:700;color:#92400e">
            <option value="bn-IN">🇧🇩 Bengali</option>
            <option value="hi-IN" selected>🇮🇳 Hindi</option>
            <option value="en-US">🇬🇧 English</option>
          </select>
          <span style="font-size:.62rem;color:#92400e80;font-weight:600">Select language before speaking</span>
        </div>
        <input type="text" id="dm-change-reason" placeholder="Type reason or use voice..." style="width:100%;border:1px solid #d97706;border-radius:8px;padding:8px 12px;font-size:.85rem">
        <div id="dm-reason-voice-display" style="margin-top:8px;padding:10px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #f59e0b;border-radius:8px;font-size:.75rem;line-height:1.5;display:none">
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px"><span style="font-size:.9rem">🎤</span><strong style="color:#92400e;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px">Voice Translation</strong></div>
          <div><strong style="color:#d97706">Original:</strong> <span id="dm-reason-voice-original" style="color:#92400e"></span></div>
          <div style="margin-top:4px"><strong style="color:#16a34a">English:</strong> <span id="dm-reason-voice-english" style="color:#1e293b;font-weight:700"></span></div>
        </div>
      </div>
      <button type="button" id="dm-reason-voice-btn" class="voiceInputBtn" onclick="startReasonVoice()" title="Voice Input" style="padding:8px 12px;background:#d97706;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:6px;font-weight:700;height:fit-content">
        <i class="bi bi-mic-fill"></i> <span class="voiceStatus">Speak</span>
      </button>
    </div>
  </div>`;

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
  if (mode === 'complete' && sts === 'Running' && !timerActive && timerState !== 'paused') {
    fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="submitAndClose(${job.id})"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  } else if (sts === 'Pending') {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-fill"></i> Start Job</button>`;
  } else if (sts === 'Running' && timerState === 'paused') {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-circle"></i> Again Start</button>`;
  } else if (sts === 'Running' && timerActive) {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="resumeRunningJumboTimer(${job.id})"><i class="bi bi-play-circle"></i> Open Timer</button>`;
  } else if (sts === 'Running') {
    fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="openJobDetail(${job.id},'complete')"><i class="bi bi-check-lg"></i> Complete</button>`;
  }
  fHtml += '</div>';
  fHtml += `<div id="dm-footer-edit-actions" style="display:none;align-items:center;gap:8px;flex-wrap:wrap;">${isFinishedJob ? `<span class="jc-request-state">Finished - Edit Locked</span>` : (hasPendingRequest ? `<span class="jc-request-state">Requesting Approval</span>` : (hasRejectedRequest ? `<span class="jc-request-state rejected">Request Rejected</span>${latestReviewNote ? `<span style="font-size:.72rem;color:#991b1b;font-weight:700">Reason: ${esc(latestReviewNote)}</span>` : ''}<button id="dm-request-roll-btn-footer" class="jc-action-btn jc-btn-complete" onclick="submitChangeRequest(${job.id})" disabled data-roll-valid="0" data-validation-message="Enter replacement parent roll number."><i class="bi bi-arrow-repeat"></i> Send Request Again</button>` : `<button id="dm-request-roll-btn-footer" class="jc-action-btn jc-btn-complete" onclick="submitChangeRequest(${job.id})" disabled data-roll-valid="0" data-validation-message="Enter replacement parent roll number."><i class="bi bi-send"></i> Request Change Roll</button>`))}</div>`;
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

  document.querySelectorAll('.dm-parent-select-cb').forEach(cb => {
    cb.addEventListener('change', function() {
      // Highlight selected parent roll rows with light color
      document.querySelectorAll('#dm-edit-parent-table tr[data-roll]').forEach(tr => {
        const chk = tr.querySelector('.dm-parent-select-cb');
        if (chk && chk.checked) {
          tr.style.backgroundColor = '#fff3e0';
        } else {
          tr.style.backgroundColor = '';
        }
      });
      // Rebuild brown change sections for all checked rolls
      rebuildRollChangeSections();
    });
  });

  document.getElementById('dm-parent-roll-input')?.addEventListener('input', function() {
    // legacy fallback – not used with per-section inputs
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
  if (!IS_ADMIN) { alert('Access denied. Only system admin can delete job cards.'); return; }
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

// ─── Print Mode Chooser & B&W Transform ────────────────────
function choosePrintMode() {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `<div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center;font-family:'Segoe UI',Arial,sans-serif">
      <div style="font-size:1.1rem;font-weight:900;color:#0f172a;margin-bottom:4px">Print Mode</div>
      <div style="font-size:.78rem;color:#64748b;margin-bottom:20px">Select print mode for best output quality</div>
      <div style="display:flex;gap:12px;justify-content:center">
        <button id="_pm_color" style="flex:1;padding:16px 12px;border:2px solid #22c55e;border-radius:12px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);cursor:pointer;transition:all .15s">
          <div style="font-size:1.5rem;margin-bottom:6px">🎨</div>
          <div style="font-weight:800;font-size:.88rem;color:#166534">Color</div>
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
    '166534':'000000','15803d':'000000','16a34a':'000000',
    '1e40af':'000000','5b21b6':'000000','6d28d9':'000000',
    'a16207':'000000','92400e':'000000','d97706':'333333',
    '0f766e':'000000','9d174d':'000000','0891b2':'333333',
    'dcfce7':'e0e0e0','f0fdf4':'f0f0f0','d1fae5':'e0e0e0',
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

// ─── Print Job Card ─────────────────────────────────────────
function renderJumboPrintCardHtml(job, qrDataUrl) {
  const extra = job.extra_data_parsed || {};
  const liveRollMap = job.live_roll_map || {};
  const created = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const started = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = Number(job.duration_minutes);
  const durText = Number.isFinite(dur) ? `${Math.floor(dur/60)}h ${dur%60}m` : '—';
  const p = extra.parent_details || {};
  const primaryPRN = String((p.roll_no) || extra.parent_roll || job.roll_no || '').trim();

  const qrHtml = qrDataUrl
    ? `<div style="text-align:center;margin-left:12px"><img src="${qrDataUrl}" style="width:90px;height:90px;display:block"><div style="font-size:.56rem;color:#64748b;margin-top:2px">Scan job card</div></div>`
    : '';

  // Collect parent rolls
  const seenParents = {};
  if (primaryPRN !== '') seenParents[primaryPRN] = true;
  const parentRollsRaw = extra.parent_rolls;
  if (Array.isArray(parentRollsRaw)) parentRollsRaw.forEach(pr => { const s = String(pr||'').trim(); if (s) seenParents[s] = true; });
  else if (typeof parentRollsRaw === 'string' && parentRollsRaw.trim()) parentRollsRaw.split(',').forEach(pr => { const s = String(pr||'').trim(); if (s) seenParents[s] = true; });
  (Array.isArray(extra.child_rolls) ? extra.child_rolls : []).forEach(r => { const s = String(r.parent_roll_no||'').trim(); if (s) seenParents[s] = true; });
  (Array.isArray(extra.stock_rolls) ? extra.stock_rolls : []).forEach(r => { const s = String(r.parent_roll_no||'').trim(); if (s) seenParents[s] = true; });
  const allParentRollNos = Object.keys(seenParents).sort((a,b) => a.localeCompare(b, undefined, {numeric:true}));

  // Parent rolls table
  let parentTableHtml = '';
  if (allParentRollNos.length) {
    parentTableHtml = `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#166534;background:#dcfce7;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Parent Roll${allParentRollNos.length > 1 ? 's' : ''}</div>
      <table style="width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:6px">
        <thead><tr>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Roll No</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Paper Company</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Material</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Width (mm)</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Length (m)</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Weight (kg)</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">GSM</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Status</th>
          <th style="padding:5px 6px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:800;font-size:.62rem">Remarks</th>
        </tr></thead><tbody>`;
    allParentRollNos.forEach(prn => {
      const live = liveRollMap[prn] || {};
      const isPrimary = (prn === primaryPRN);
      const company = live.company || (isPrimary ? (p.company || job.company || '') : '');
      const ptype = live.paper_type || (isPrimary ? (p.paper_type || job.paper_type || '') : '');
      const width = live.width_mm !== undefined ? live.width_mm : (isPrimary ? (p.width_mm ?? job.width_mm ?? '—') : '—');
      const length = live.length_mtr !== undefined ? live.length_mtr : (isPrimary ? (p.length_mtr ?? job.length_mtr ?? '—') : '—');
      const weight = live.weight_kg !== undefined ? live.weight_kg : (isPrimary ? (p.weight_kg ?? job.weight_kg ?? '—') : '—');
      const gsm = live.gsm !== undefined ? live.gsm : (isPrimary ? (p.gsm ?? job.gsm ?? '—') : '—');
      const rstatus = live.status || '—';
      const remarks = live.remarks !== undefined ? live.remarks : (isPrimary ? (p.remarks || '') : '');
      parentTableHtml += `<tr>
        <td style="padding:5px 6px;border:1px solid #d1e7dd;font-weight:800;color:#166534">${esc(prn)}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(company||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(ptype||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(width+'')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(length+'')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(weight+'')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(gsm+'')}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(rstatus)}</td>
        <td style="padding:5px 6px;border:1px solid #d1e7dd">${esc(remarks||'—')}</td>
      </tr>`;
    });
    parentTableHtml += '</tbody></table></td></tr>';
  }

  // Child / Stock rolls table
  const childRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
  const stockRows = Array.isArray(extra.stock_rolls) ? extra.stock_rolls : [];
  const allRows = [];
  childRows.forEach(r => allRows.push({ parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '', roll_no: r.roll_no || '', type: r.paper_type || job.paper_type || '', width: (r.width ?? r.width_mm ?? '—'), length: (r.length ?? r.length_mtr ?? '—'), weight_kg: (r.weight_kg ?? '—'), gsm: (r.gsm ?? job.gsm ?? '—'), wastage: (r.wastage ?? 0), remarks: r.remarks || '', status: 'Job Assign' }));
  stockRows.forEach(r => allRows.push({ parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '', roll_no: r.roll_no || '', type: r.paper_type || job.paper_type || '', width: (r.width ?? r.width_mm ?? '—'), length: (r.length ?? r.length_mtr ?? '—'), weight_kg: (r.weight_kg ?? '—'), gsm: (r.gsm ?? job.gsm ?? '—'), wastage: (r.wastage ?? 0), remarks: r.remarks || '', status: 'Stock' }));
  allRows.sort((a,b) => String(a.roll_no||'').localeCompare(String(b.roll_no||''), undefined, {numeric:true}));

  let childTableHtml = '';
  if (allRows.length) {
    childTableHtml = `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#1e40af;background:#dbeafe;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Child / Stock Rolls (${allRows.length})</div>
      <table style="width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:6px">
        <thead><tr>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">#</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Parent Roll</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Child Roll</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Type</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Width</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Length</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Status</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Wastage</th>
          <th style="padding:5px 6px;border:1px solid #bfdbfe;background:#dbeafe;color:#1e40af;font-weight:800;font-size:.62rem">Remarks</th>
        </tr></thead><tbody>`;
    allRows.forEach((r, i) => {
      const bg = i % 2 === 0 ? '#ffffff' : '#f8fafc';
      childTableHtml += `<tr style="background:${bg}">
        <td style="padding:5px 6px;border:1px solid #e2e8f0;color:#94a3b8;text-align:center">${i+1}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0;font-weight:700">${esc(r.parent_roll_no||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0;font-weight:800;color:#166534">${esc(r.roll_no||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.type||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.width+'')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.length+'')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.status||'—')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.wastage+'')}</td>
        <td style="padding:5px 6px;border:1px solid #e2e8f0">${esc(r.remarks||'—')}</td>
      </tr>`;
    });
    childTableHtml += '</tbody></table></td></tr>';
  }

  // Photo
  const existingPhoto = extra.jumbo_photo_url || '';
  const photoHtml = existingPhoto
    ? `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#a16207;background:#fef3c7;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Job Photo</div><div style="margin-bottom:6px"><img src="${esc(existingPhoto)}" style="max-width:300px;max-height:180px;border-radius:8px;border:1px solid #e2e8f0"></div></td></tr>`
    : '';

  return `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:20px;max-width:760px;margin:0 auto;color:#0f172a">
    <div style="border:2px solid #166534;border-radius:12px;overflow:hidden">
      <!-- HEADER -->
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:12px 14px;background:#f0fdf4;border-bottom:2px solid #166534">
        <div>
          ${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:36px;margin-bottom:4px;display:block">` : ''}
          <div style="font-weight:900;font-size:1.02rem;letter-spacing:.02em">${esc(COMPANY.name || 'Company')}</div>
          <div style="font-size:.66rem;color:#475569">${esc(COMPANY.address || '')}</div>
          ${COMPANY.gst ? `<div style="font-size:.62rem;color:#64748b">GST: ${esc(COMPANY.gst)}</div>` : ''}
        </div>
        <div style="display:flex;align-items:flex-start">
          <div style="text-align:right">
            <div style="font-size:.66rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#475569">Department</div>
            <div style="font-size:.92rem;font-weight:900;color:#166534">Jumbo Slitting</div>
            <div style="font-size:1.2rem;font-weight:900;color:#0f172a;line-height:1.1">${esc(job.job_no || '—')}</div>
            <div style="font-size:.6rem;color:#64748b">Generated: ${esc(created)}</div>
          </div>
          ${qrHtml}
        </div>
      </div>

      <!-- DATE BAR -->
      <div style="padding:8px 14px;background:#dcfce7;border-bottom:1px solid #bbf7d0;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:.76rem;font-weight:900;color:#166534">Job: ${esc(job.planning_job_name || '—')}</div>
        <div style="font-size:.68rem;font-weight:700;color:#15803d">Priority: ${esc(job.planning_priority || 'Normal')}</div>
      </div>

      <!-- STATUS BAR -->
      <div style="padding:10px 12px;border-bottom:1px solid #cbd5e1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;font-size:.66rem">
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Status</div><div style="font-weight:800">${esc(job.status || '—')}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Started</div><div style="font-weight:700">${esc(started)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Completed</div><div style="font-weight:700">${esc(completed)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Duration</div><div style="font-weight:700">${esc(durText)}</div></div>
      </div>

      <div style="padding:10px 12px">
        <!-- JOB DETAILS -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#166534;background:#dcfce7;padding:5px 8px;border-radius:4px">Job Details</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;margin-bottom:10px">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.planning_job_name || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job No</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-weight:700;color:#166534">${esc(job.job_no || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Roll No</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.roll_no || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Material</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.paper_type || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Width</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.width_mm || '—') + ' mm')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Length</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.length_mtr || '—') + ' m')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">GSM</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.gsm || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Weight</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.weight_kg || '—') + ' kg')}</td></tr>
        </table>

        <!-- PARENT ROLLS -->
        ${parentTableHtml ? `<table style="width:100%">${parentTableHtml}</table>` : ''}

        <!-- CHILD / STOCK ROLLS -->
        ${childTableHtml ? `<table style="width:100%">${childTableHtml}</table>` : ''}

        <!-- EXECUTION SUMMARY -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#166534;background:#dcfce7;padding:5px 8px;border-radius:4px;margin-top:8px">Execution Summary</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Wastage (kg)</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.wastage_kg || extra.operator_wastage_kg || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Operator</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.operator_name || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Remarks</td><td colspan="3" style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.operator_notes || extra.operator_remarks || '—')}</td></tr>
        </table>

        <!-- PHOTO -->
        ${photoHtml ? `<table style="width:100%">${photoHtml}</table>` : ''}
      </div>

      <!-- FOOTER -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;border-top:2px solid #166534;background:#f0fdf4">
        <div style="font-size:.68rem;color:#475569">Operator Signature: _____________________</div>
        <div style="font-size:.68rem;color:#475569">Supervisor Signature: _____________________</div>
      </div>
      <div style="padding:6px 12px;font-size:.58rem;color:#64748b;display:flex;justify-content:space-between;border-top:1px solid #bbf7d0">
        <span>Document: Jumbo Slitting Job Card | ${esc(COMPANY.name || '')}</span>
        <span>Printed at ${esc(new Date().toLocaleString())}</span>
      </div>
    </div>
  </div>`;
}

async function printJobCard(id) {
  const mode = await choosePrintMode();
  if (!mode) return;
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  let html = renderJumboPrintCardHtml(job, qrDataUrl);
  if (mode === 'bw') html = printBwTransform(html);
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${html}</body></html>`);
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
