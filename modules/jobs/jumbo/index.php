<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$statusCtx = erp_status_context();
$isOperatorView = (bool)($statusCtx['is_operator_view'] ?? false);
$canManualRollEntry = hasPageAction('/modules/jobs/jumbo/index.php', 'edit')
  || hasPageAction('/modules/operators/jumbo/index.php', 'edit')
  || hasRole('manager', 'system_admin', 'super_admin')
  || isAdmin();
$canDeleteJobs = isAdmin() && !$isOperatorView;
$pageTitle = $isOperatorView ? 'Jumbo Operator' : 'Jumbo Job Cards';
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

function safeCountQuery(mysqli $db, string $sql): int {
  $res = $db->query($sql);
  if (!$res) {
    return 0;
  }
  $row = $res->fetch_assoc();
  return (int)($row['cnt'] ?? 0);
}

function jumboDepartmentLabel(string $department): string {
  $department = strtolower(trim($department));
  $map = [
    'jumbo_slitting' => 'Jumbo Slitting',
    'flexo_printing' => 'Flexo Printing',
  ];
  if (isset($map[$department])) return $map[$department];
  $department = str_replace('_', ' ', $department);
  return trim((string)preg_replace('/\s+/', ' ', ucwords($department)));
}

function jumboDisplayJobName(array $job): string {
  $planning = trim((string)($job['planning_job_name'] ?? ''));
  if ($planning !== '') return $planning;
  $jobNo = trim((string)($job['job_no'] ?? ''));
  $dept = jumboDepartmentLabel((string)($job['department'] ?? ''));
  if ($jobNo !== '') return $dept !== '' ? ($jobNo . ' (' . $dept . ')') : $jobNo;
  return $dept !== '' ? $dept : '—';
}

$db->query("CREATE TABLE IF NOT EXISTS job_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  request_type VARCHAR(80) NOT NULL,
  payload_json LONGTEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Pending',
  requested_by INT NULL,
  requested_by_name VARCHAR(150) NULL,
  requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_by_name VARCHAR(150) NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  INDEX idx_job_id (job_id),
  INDEX idx_request_type (request_type),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$allJumboRows = [];
$jobsSql = "\n  SELECT j.*,\n         ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,\n         ps.status AS roll_status, ps.lot_batch_no,\n         p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,\n         COALESCE(req.pending_count, 0) AS pending_change_requests,\n         lreq.latest_request_id, lreq.latest_request_status, lreq.latest_request_review_note, lreq.latest_request_reviewed_at\n  FROM jobs j\n  LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no\n  LEFT JOIN planning p ON j.planning_id = p.id\n  LEFT JOIN (\n    SELECT job_id, COUNT(*) AS pending_count\n    FROM job_change_requests\n    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'\n    GROUP BY job_id\n  ) req ON req.job_id = j.id\n  LEFT JOIN (\n    SELECT t.job_id, t.id AS latest_request_id, t.status AS latest_request_status, t.review_note AS latest_request_review_note, t.reviewed_at AS latest_request_reviewed_at\n    FROM job_change_requests t\n    INNER JOIN (\n      SELECT job_id, MAX(id) AS max_id\n      FROM job_change_requests\n      WHERE request_type = 'jumbo_roll_update'\n      GROUP BY job_id\n    ) mx ON mx.job_id = t.job_id AND mx.max_id = t.id\n    WHERE t.request_type = 'jumbo_roll_update'\n  ) lreq ON lreq.job_id = j.id\n  WHERE j.job_type = 'Slitting'\n    AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')\n  ORDER BY j.created_at DESC, j.id DESC\n";
$jobsStmt = $db->prepare($jobsSql);
if ($jobsStmt) {
  $jobsStmt->execute();
  $allJumboRows = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
  // Fallback for live schema drift (missing columns like planning.priority, etc.)
  $fallbackSql = "
    SELECT j.*
    FROM jobs j
    WHERE j.job_type = 'Slitting'
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
    ORDER BY j.created_at DESC, j.id DESC
  ";
  $fallbackResult = $db->query($fallbackSql);
  if ($fallbackResult) {
    $allJumboRows = $fallbackResult->fetch_all(MYSQLI_ASSOC);
  }
}

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
    $rows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
    foreach ($rows as $r) {
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
  $stmt = $db->prepare($sql);
  if ($stmt) {
    $stmt->bind_param($types, ...$rollNos);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
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
  $row['display_job_name'] = jumboDisplayJobName($row);
  $row['extra_data_parsed'] = $extra;
  $row['live_roll_map'] = [];

  $jobRoll = trim((string)($row['roll_no'] ?? ''));
  if ($jobRoll !== '' && isset($rollMap[$jobRoll])) {
    $row['live_roll_map'][$jobRoll] = $rollMap[$jobRoll];
  }
  $parentRoll = trim((string)($extra['parent_roll'] ?? (($extra['parent_details']['roll_no'] ?? ''))));
  if ($parentRoll !== '' && isset($rollMap[$parentRoll])) {
    $row['live_roll_map'][$parentRoll] = $rollMap[$parentRoll];
    $row['live_parent_remarks'] = (string)($rollMap[$parentRoll]['remarks'] ?? '');
  } else {
    $row['live_parent_remarks'] = '';
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
    $rows = is_array($extra[$bucket] ?? null) ? $extra[$bucket] : [];
    foreach ($rows as &$r) {
      $rn = trim((string)($r['roll_no'] ?? ''));
      if ($rn !== '' && isset($rollMap[$rn])) {
        $row['live_roll_map'][$rn] = $rollMap[$rn];
        $r['remarks_live'] = (string)($rollMap[$rn]['remarks'] ?? '');
          $r['status_live']  = (string)($rollMap[$rn]['status'] ?? '');
      }
    }
    unset($r);
    $extra[$bucket] = $rows;
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
$totalCount = safeCountQuery($db, "
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND deleted_at IS NULL
");

// Count Pending status
$pendingCount = safeCountQuery($db, "
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND status = 'Pending' AND deleted_at IS NULL
");

// Count Running status
$runningCount = safeCountQuery($db, "
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' AND status = 'Running' AND deleted_at IS NULL
");

// Count Hold status (includes Hold, Hold for Payment, Hold for Approval)
$holdCount = safeCountQuery($db, "
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' 
    AND (LOWER(status) = 'hold' 
         OR LOWER(status) = 'hold for payment' 
         OR LOWER(status) = 'hold for approval')
    AND deleted_at IS NULL
");

// Count Finished status (Closed, Finalized, Completed, etc.)
$finishedCount = safeCountQuery($db, "
  SELECT COUNT(*) as cnt
  FROM jobs
  WHERE job_type = 'Slitting' 
    AND (LOWER(status) IN ('closed', 'finalized', 'completed', 'finished', 'qc passed'))
    AND deleted_at IS NULL
");

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Cards</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Jumbo Job</span>
</div>

<style>
:root{--jc-brand:#22c55e;--jc-brand-dim:rgba(34,197,94,.08);--jc-orange:#f97316;--jc-blue:#3b82f6;--jc-red:#ef4444;--jc-purple:#8b5cf6}
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
.jc-badge-normal{background:#dcfce7;color:#166534}
.jc-job-name{font-size:1.2rem;line-height:1.4;font-weight:1000;color:#0f172a}
.jc-job-name{font-size:1.16rem;line-height:1.25;font-weight:900;color:#0f172a}
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.6}}
.jc-action-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
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
.jc-time{font-size:.6rem;color:#94a3b8;font-weight:600}
.jc-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.jc-empty i{font-size:3rem;opacity:.3}
.jc-empty p{margin-top:12px;font-size:.9rem;font-weight:600}
.jc-timer{font-size:.75rem;font-weight:800;color:var(--jc-blue);font-family:'Courier New',monospace}
.jc-notif-badge{background:#ef4444;color:#fff;font-size:9px;font-weight:800;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-left:6px}
.jc-request-state{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid #fecaca;background:#fff1f2;color:#dc2626;font-size:.6rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;animation:request-blink 1s linear infinite}
@keyframes request-blink{0%,100%{opacity:1}50%{opacity:.2}}

/* ── Detail Modal ── */
.jc-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.jc-modal-overlay.active{display:flex}
.jc-modal{background:#fff;border-radius:16px;max-width:760px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.jc-modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#f0fdf4,#fff);border-radius:16px 16px 0 0}
.jc-modal-header h2{font-size:1.1rem;font-weight:900;display:flex;align-items:center;gap:10px}
.jc-modal-header h2 i{color:var(--jc-brand)}
.jc-modal-body{padding:16px}
.jc-modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}

/* ── Bordered Sections (Flexo-style) ── */
.jc-op-shell{border:1px solid #e2e8f0;border-radius:12px;background:#fff;overflow:hidden}
.jc-op-form{padding:12px;display:grid;gap:12px}
.jc-op-section{border:1px solid #d1e7dd;border-radius:10px;background:#fff;overflow:hidden;display:flex;flex-direction:column}
.jc-op-h{padding:9px 11px;border-bottom:1px solid #86efac;background:#f0fdf4;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#166534}
.jc-op-b{padding:10px;display:grid;gap:8px}
.jc-op-b + .jc-op-b{border-top:1px solid #f0fdf4;margin-top:0}
.jc-op-grid-2{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr))}
.jc-op-grid-3{display:grid;gap:8px;grid-template-columns:repeat(3,minmax(0,1fr))}
.jc-op-grid-4{display:grid;gap:8px;grid-template-columns:repeat(4,minmax(0,1fr))}
.jc-op-field{display:grid;gap:5px}
.jc-op-field label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
.jc-op-field .fv{padding:8px 10px;border:1px solid #d1e7dd;border-radius:8px;font-size:.8rem;font-weight:600;background:#fcfdff;min-height:20px}
.jc-op-topstrip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}
.jc-op-topitem{display:grid;gap:3px}
.jc-op-topitem .k{font-size:.58rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.jc-op-topitem .v{font-size:.76rem;font-weight:800;color:#0f172a}
.jc-timer-history{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
.jc-timer-history-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px}
.jc-timer-history-card h4{margin:0 0 8px;font-size:.74rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.jc-timer-history-list{display:grid;gap:8px}
.jc-timer-history-row{display:grid;grid-template-columns:96px 1fr;gap:10px;padding:8px 10px;border-radius:10px;background:#f8fafc;align-items:start}
.jc-timer-history-row.work{background:#f0fdf4}
.jc-timer-history-row.pause{background:#fff7ed}
.jc-timer-history-row .k{font-size:.68rem;font-weight:900;letter-spacing:.03em;text-transform:uppercase;color:#64748b}
.jc-timer-history-row .v{font-size:.82rem;font-weight:700;color:#0f172a;line-height:1.45}
.jc-timer-history-empty{font-size:.78rem;color:#94a3b8;font-weight:700}
.jc-op-roll-table{width:100%;border-collapse:collapse;font-size:.75rem}
.jc-op-roll-table th,.jc-op-roll-table td{border:1px solid #d1e7dd;padding:7px 8px;text-align:left;vertical-align:middle}
.jc-op-roll-table th{background:#f0fdf4;font-size:.62rem;text-transform:uppercase;letter-spacing:.04em;color:#166534}

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
/* ── Timer Overlay ── */
.jc-timer-overlay{position:fixed;inset:0;z-index:20000;background:rgba(15,23,42,.85);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;backdrop-filter:blur(4px)}
.jc-timer-display{font-size:4rem;font-weight:900;font-variant-numeric:tabular-nums;color:#fff;letter-spacing:.04em;text-shadow:0 2px 12px rgba(0,0,0,.3)}
.jc-timer-jobinfo{color:rgba(255,255,255,.7);font-size:1rem;text-align:center;font-weight:600}
.jc-timer-actions{display:flex;gap:16px}
.jc-timer-actions button{padding:12px 32px;font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;border:none;border-radius:999px;cursor:pointer;transition:all .15s}
.jc-timer-btn-cancel{background:#64748b;color:#fff}
.jc-timer-btn-cancel:hover{background:#475569}
.jc-timer-btn-end{background:#16a34a;color:#fff}
.jc-timer-btn-end:hover{background:#15803d}

/* Start Verification Modal */
.jv-overlay{position:fixed;inset:0;z-index:30000;background:rgba(15,23,42,.72);display:flex;align-items:center;justify-content:center;padding:16px}
.jv-modal{background:#fff;border-radius:14px;max-width:860px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 22px 56px rgba(2,6,23,.35)}
.jv-head{padding:14px 16px;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#f0fdf4,#ffffff);display:flex;justify-content:space-between;align-items:center;gap:10px}
.jv-title{font-size:1rem;font-weight:900;color:#14532d;display:flex;align-items:center;gap:8px}
.jv-sub{font-size:.72rem;color:#475569;font-weight:700}
.jv-body{padding:14px;display:grid;gap:12px}
.jv-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:12px}
.jv-card{border:1px solid #d1e7dd;border-radius:10px;background:#fff;overflow:hidden}
.jv-card h4{margin:0;padding:9px 11px;font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;color:#166534;background:#f0fdf4;border-bottom:1px solid #d1e7dd}
.jv-roll-list{max-height:280px;overflow:auto}
.jv-roll-row{display:grid;grid-template-columns:1fr auto;gap:8px;padding:9px 11px;border-bottom:1px solid #f1f5f9}
.jv-roll-row:last-child{border-bottom:none}
.jv-roll-no{font-size:.8rem;font-weight:900;color:#0f172a}
.jv-roll-meta{font-size:.68rem;color:#64748b;font-weight:700}
.jv-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:3px 9px;font-size:.62rem;font-weight:900;letter-spacing:.03em;text-transform:uppercase}
.jv-pill-pending{background:#fef9c3;color:#854d0e}
.jv-pill-ok{background:#dcfce7;color:#166534}
.jv-tools{display:grid;gap:10px;padding:11px}
.jv-tool-btn{padding:8px 11px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#334155;font-size:.73rem;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.jv-tool-btn:hover{background:#f8fafc}
.jv-tool-btn-main{background:#166534;border-color:#166534;color:#fff}
.jv-tool-btn-main:hover{background:#14532d}
.jv-tool-btn-stop{background:#fee2e2;border-color:#fecaca;color:#b91c1c}
.jv-tool-btn-stop:hover{background:#fecaca}
.jv-readonly{width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:700;background:#f8fafc;color:#0f172a}
.jv-input{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.8rem;font-weight:700;color:#0f172a}
.jv-input:focus{outline:none;border-color:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.14)}
.jv-input:disabled{background:#f1f5f9;color:#94a3b8;cursor:not-allowed;opacity:.6}
.jv-tool-btn:disabled{opacity:.5;cursor:not-allowed;color:#94a3b8}
.jv-manual-section-restricted{opacity:.7;animation:pulse-restrict 2s infinite}
@keyframes pulse-restrict{0%,100%{opacity:.7}50%{opacity:.85}}
.jv-scan-box{border:1px dashed #94a3b8;border-radius:10px;padding:8px;background:#f8fafc;min-height:160px}
.jv-scanner{width:100%;min-height:140px}
.jv-msg{padding:9px 11px;border-radius:9px;font-size:.76rem;font-weight:800;display:none}
.jv-msg.show{display:block}
.jv-msg-info{background:#e0f2fe;color:#075985;border:1px solid #bae6fd}
.jv-msg-ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.jv-msg-warn{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.jv-msg-bad{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.jv-foot{padding:12px 14px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.jv-progress{font-size:.78rem;font-weight:900;color:#0f172a}
.jv-actions{display:flex;gap:8px;flex-wrap:wrap}
.jv-toast-wrap{position:fixed;right:14px;top:14px;z-index:31000;display:grid;gap:8px;max-width:360px}
.jv-toast{border-radius:10px;padding:10px 12px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 10px 24px rgba(2,6,23,.18);font-size:.78rem;font-weight:800;color:#0f172a;animation:jv-toast-in .16s ease}
.jv-toast.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.jv-toast.warn{border-color:#fde68a;background:#fffbeb;color:#92400e}
.jv-toast.bad{border-color:#fecaca;background:#fef2f2;color:#991b1b}
@keyframes jv-toast-in{from{transform:translateY(-6px);opacity:0}to{transform:translateY(0);opacity:1}}
.jx-overlay{position:fixed;inset:0;z-index:32000;background:rgba(15,23,42,.62);display:flex;align-items:center;justify-content:center;padding:14px}
.jx-modal{width:100%;max-width:460px;background:#fff;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 20px 52px rgba(2,6,23,.3);overflow:hidden}
.jx-head{padding:11px 13px;background:linear-gradient(135deg,#f8fafc,#ffffff);border-bottom:1px solid #e2e8f0;font-size:.82rem;font-weight:900;color:#0f172a}
.jx-body{padding:12px 13px;display:grid;gap:10px}
.jx-msg{font-size:.8rem;line-height:1.45;color:#334155;font-weight:700;white-space:pre-line}
.jx-input{width:100%;padding:9px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.8rem;font-weight:700;color:#0f172a}
.jx-input:focus{outline:none;border-color:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.14)}
.jx-foot{padding:11px 13px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:8px}
@media(max-width:860px){.jv-grid{grid-template-columns:1fr}}
@media(max-width:640px){.jv-overlay{padding:0;align-items:stretch}.jv-modal{max-width:none;max-height:100dvh;height:100dvh;border-radius:0}.jv-head{padding:12px;align-items:flex-start}.jv-title{font-size:.92rem}.jv-sub{font-size:.68rem;line-height:1.45}.jv-body{padding:12px;gap:10px}.jv-grid{grid-template-columns:1fr;gap:10px}.jv-grid>.jv-card:first-child{order:2}.jv-grid>.jv-card:last-child{order:1}.jv-roll-list{max-height:none}.jv-roll-row{grid-template-columns:1fr;gap:6px;padding:10px}.jv-tools{padding:10px}.jv-tool-btn{width:100%;min-height:42px;font-size:.82rem}.jv-readonly,.jv-input{font-size:.9rem;padding:10px 12px}.jv-scan-box{min-height:260px;padding:6px}.jv-scanner{min-height:248px}.jv-foot{padding:12px;align-items:stretch}.jv-progress{width:100%;font-size:.84rem}.jv-actions{width:100%}.jv-actions .jc-action-btn{flex:1 1 100%;justify-content:center;min-height:44px}.jx-overlay{padding:0;align-items:flex-end}.jx-modal{max-width:none;border-radius:16px 16px 0 0}}
/* ── Upload area ── */
.jc-upload-zone{border:2px dashed #d1e7dd;border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:all .2s}
.jc-upload-zone:hover{border-color:var(--jc-brand);background:#f0fdf4}
.jc-upload-zone input[type=file]{display:none}
.jc-upload-preview{margin-top:8px}
.jc-upload-preview img{max-width:200px;max-height:150px;border-radius:8px;border:1px solid #e2e8f0}

/* ── Roll Change Comparison Panel ── */
.rc-panel{background:linear-gradient(135deg,#fffbeb,#fef3c7);border:2px solid #f59e0b;border-radius:12px;padding:16px;margin-bottom:20px}
.rc-panel-header{display:flex;align-items:center;gap:8px;margin-bottom:14px;font-size:.8rem;font-weight:900;color:#92400e;text-transform:uppercase;letter-spacing:.05em}
.rc-panel-header i{font-size:1rem;color:#f59e0b}
.rc-compare{display:grid;grid-template-columns:1fr 1fr;gap:0;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb}
.rc-side{padding:14px}
.rc-side-left{background:#f0fdf4;border-right:2px dashed #d1d5db}
.rc-side-right{background:#fef2f2}
.rc-side-title{font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.rc-side-left .rc-side-title{color:#166534}
.rc-side-right .rc-side-title{color:#991b1b}
.rc-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.75rem;border-bottom:1px solid rgba(0,0,0,.05)}
.rc-row:last-child{border-bottom:none}
.rc-label{color:#64748b;font-weight:600}
.rc-val{color:#1e293b;font-weight:800;text-align:right;max-width:55%;word-break:break-word}
.rc-remarks-box{margin-top:10px;padding:8px 10px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-size:.72rem;color:#475569;line-height:1.4}
.rc-remarks-box strong{color:#1e293b}
.rc-footer{margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.rc-btn-accept{padding:8px 20px;border-radius:8px;border:none;font-weight:800;font-size:.78rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;box-shadow:0 2px 8px rgba(22,163,74,.3);transition:all .15s}
.rc-btn-accept:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(22,163,74,.4)}
.rc-btn-update{padding:8px 16px;border-radius:8px;border:1px solid #bfdbfe;background:#dbeafe;color:#1d4ed8;font-weight:800;font-size:.78rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.rc-btn-update:hover{background:#bfdbfe}
.rc-btn-reject{padding:8px 16px;border-radius:8px;border:1px solid #fecaca;background:#fee2e2;color:#dc2626;font-weight:800;font-size:.78rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.rc-btn-reject:hover{background:#fecaca}
.rc-loading{text-align:center;padding:20px;color:#92400e;font-size:.8rem;font-weight:600}
.rc-summary{margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rc-summary-box{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px}
.rc-summary-box h4{margin:0 0 6px;font-size:.64rem;color:#475569;text-transform:uppercase;letter-spacing:.05em}
.rc-summary-box ul{margin:0;padding-left:16px;font-size:.72rem;color:#334155}
.rc-editor{margin-top:12px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:10px;overflow:hidden}
.rc-editor-head{padding:9px 10px;background:#e2e8f0;border-bottom:1px solid #cbd5e1;font-size:.72rem;font-weight:800;color:#1e293b;display:flex;justify-content:space-between;gap:8px;align-items:center}
.rc-editor-body{padding:10px;display:grid;gap:10px}
.rc-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rc-group{border:1px solid #d1d5db;border-radius:8px;background:#fff;overflow:hidden}
.rc-group h5{margin:0;padding:8px 10px;font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e5e7eb}
.rc-group-old h5{background:#f1f5f9;color:#475569}
.rc-group-new h5{background:#dbeafe;color:#1d4ed8}
.rc-roll-table{width:100%;border-collapse:collapse;font-size:.7rem}
.rc-roll-table th,.rc-roll-table td{border:1px solid #e2e8f0;padding:6px 7px;text-align:left;vertical-align:middle}
.rc-roll-table th{background:#f8fafc;color:#475569;font-size:.6rem;text-transform:uppercase;letter-spacing:.04em}
.rc-roll-table input,.rc-roll-table select{width:100%;min-width:68px;height:28px;border:1px solid #cbd5e1;border-radius:6px;padding:4px 6px;font-size:.72rem;font-weight:600}
.rc-editor-note{font-size:.7rem;color:#475569}
.rc-editor-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.rc-btn-apply{padding:8px 16px;border:none;border-radius:8px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;font-weight:800;font-size:.76rem;cursor:pointer}
.rc-btn-apply:disabled{opacity:.6;cursor:not-allowed}
.rc-badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#dbeafe;color:#1e40af;padding:3px 9px;font-size:.62rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase}

/* Print */
@media print{
  .no-print,.breadcrumb,.page-header,.jc-modal-overlay{display:none!important}
  .jc-print-area{display:block!important}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
.jc-print-area{display:none}
@media(max-width:600px){.jc-grid{grid-template-columns:1fr}.jc-stats{grid-template-columns:repeat(2,1fr)}.jc-detail-grid{grid-template-columns:1fr}.jc-form-row{grid-template-columns:1fr}.jc-card-foot>div:last-child{display:flex;flex-wrap:wrap;gap:6px;width:100%}.jc-card-foot .jc-action-btn{flex:1 1 calc(50% - 6px);justify-content:center}.jc-op-topstrip,.jc-timer-history{grid-template-columns:1fr}.jc-timer-history-row{grid-template-columns:1fr}.jc-timer-overlay{padding:18px 14px;gap:18px}.jc-timer-display{font-size:2.4rem;line-height:1.1;text-align:center}.jc-timer-actions{width:100%;flex-wrap:wrap}.jc-timer-actions button{width:100%;padding:13px 16px}}
</style>

<div class="jc-header no-print">
  <div>
    <h1><i class="bi bi-boxes"></i> Jumbo Job Cards
      <?php if ($notifCount > 0): ?><span class="jc-notif-badge"><?= $notifCount ?></span><?php endif; ?>
    </h1>
  </div>
  <div style="display:flex;gap:8px">
    <button class="jc-action-btn jc-btn-view" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<!-- Multi-Select Bulk Print Toolbar -->
<div id="jcBulkBar" class="no-print" style="display:none;background:linear-gradient(135deg,#1e40af,#1e3a8a);color:#fff;border-radius:12px;padding:14px 20px;margin-bottom:16px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(30,64,175,.25)">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-check2-square" style="font-size:1.2rem"></i>
    <span style="font-weight:800;font-size:.85rem"><span id="jcSelectedCount">0</span> Job Card(s) Selected</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button onclick="jcSelectAll()" style="padding:6px 14px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;font-weight:700;font-size:.72rem;cursor:pointer">Select All</button>
    <button onclick="jcDeselectAll()" style="padding:6px 14px;background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:8px;font-weight:700;font-size:.72rem;cursor:pointer">Deselect All</button>
    <button onclick="jcBulkPrint()" style="padding:8px 18px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.78rem;cursor:pointer;display:flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(34,197,94,.3)"><i class="bi bi-printer-fill"></i> Print Selected</button>
  </div>
</div>
<style>
.jc-select-check{position:absolute;top:8px;left:8px;z-index:10;width:20px;height:20px;accent-color:#22c55e;cursor:pointer}
.jc-card{position:relative}
.jc-card.jc-selected{outline:2px solid #22c55e;outline-offset:-2px;background:#f0fdf4}
</style>

<?php
$activeCount = $totalCount;
$historyCount = $finishedCount;
?>
<div class="jc-stats no-print">
  <div class="jc-stat" data-filter="all" onclick="filterFromStat('all')">
    <div class="jc-stat-icon" style="background:#f0fdf4;color:#22c55e"><i class="bi bi-boxes"></i></div>
    <div><div class="jc-stat-val"><?= $totalCount ?></div><div class="jc-stat-label">Job Detials</div></div>
  </div>
  <div class="jc-stat active" data-filter="Pending" onclick="filterFromStat('Pending')">
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
  <button class="jc-filter-btn" onclick="filterJobs('all',this)">All</button>
  <button class="jc-filter-btn active" onclick="filterJobs('Pending',this)">Pending</button>
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
    $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0;
    $resumedTs = !empty($job['extra_data_parsed']['timer_last_resumed_at']) ? (strtotime($job['extra_data_parsed']['timer_last_resumed_at']) * 1000) : $startedTs;
    $baseSeconds = (int)round((float)($job['extra_data_parsed']['timer_accumulated_seconds'] ?? 0));
    $rSts = $job['roll_status'] ?? '';
    $rStsClass = strtolower(str_replace(' ', '', $rSts)) === 'slitting' ? 'slitting' : $stsClass;
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $hasPendingRequest = (int)($job['pending_change_requests'] ?? 0) > 0;
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedAt = $job['started_at'] ? date('d M Y, H:i', strtotime($job['started_at'])) : '—';
    $completedAt = $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['display_job_name'] ?? ''));
  ?>
  <div class="jc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openJobDetail(<?= $job['id'] ?>)">
    <input type="checkbox" class="jc-select-check" data-job-id="<?= $job['id'] ?>" onclick="event.stopPropagation();jcUpdateBulkBar()" title="Select for bulk print">
    <div class="jc-card-head">
      <div class="jc-jobno"><i class="bi bi-box-seam"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="jc-badge jc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php if ($hasPendingRequest): ?>
          <span class="jc-request-state">Request Pending</span>
        <?php elseif (strtolower(trim((string)($job['latest_request_status'] ?? ''))) === 'rejected'): ?>
          <span class="jc-request-state" style="background:#fee2e2;color:#991b1b">Request Rejected</span>
        <?php elseif (strtolower(trim((string)($job['latest_request_status'] ?? ''))) === 'approved'): ?>
          <span class="jc-request-state" style="background:#dcfce7;color:#166534">Accepted Done</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value jc-job-name"><?= e($job['display_job_name'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Roll No</span><span class="jc-value" style="color:var(--jc-brand)"><?= e($job['roll_no'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Material</span><span class="jc-value"><?= e($job['paper_type'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Dimension</span><span class="jc-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Started</span><span class="jc-value"><?= e($startedAt) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Ended</span><span class="jc-value"><?= e($completedAt) ?></span></div>
      <?php if ($sts === 'Running' && $resumedTs): ?>
      <div class="jc-card-row"><span class="jc-label">Elapsed</span><span class="jc-timer" data-base-seconds="<?= $baseSeconds ?>" data-resumed-at="<?= $resumedTs ?>" style="color:var(--jc-blue);font-weight:700">00:00:00</span></div>
      <?php endif; ?>
    </div>
    <div class="jc-card-foot">
      <div class="jc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <?php if ($sts === 'Pending' && $isOperatorView): ?>
          <button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
        <?php elseif ($sts === 'Running' && $isOperatorView): ?>
          <?php if ($timerActive): ?>
            <button class="jc-action-btn jc-btn-start" onclick="endRunningJumboTimer(<?= $job['id'] ?>)"><i class="bi bi-stop-fill"></i> End Timer</button>
          <?php else: ?>
            <button class="jc-action-btn jc-btn-complete" onclick="openJobDetail(<?= $job['id'] ?>,'complete')"><i class="bi bi-check-lg"></i> Complete</button>
          <?php endif; ?>
        <?php else: ?>
          <button class="jc-action-btn jc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
        <?php endif; ?>
        <button class="jc-action-btn jc-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
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
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['display_job_name'] ?? ''));
  ?>
  <div class="jc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" data-finished-only="1" style="display:none" onclick="openJobDetail(<?= $job['id'] ?>)">
    <input type="checkbox" class="jc-select-check" data-job-id="<?= $job['id'] ?>" onclick="event.stopPropagation();jcUpdateBulkBar()" title="Select for bulk print">
    <div class="jc-card-head">
      <div class="jc-jobno"><i class="bi bi-box-seam"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="jc-badge jc-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value jc-job-name"><?= e($job['display_job_name'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Roll No</span><span class="jc-value" style="color:var(--jc-brand)"><?= e($job['roll_no'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Material</span><span class="jc-value"><?= e($job['paper_type'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Dimension</span><span class="jc-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Started</span><span class="jc-value"><?= e($startedAt) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Ended</span><span class="jc-value"><?= e($completedAt) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Plan Flow</span><span class="jc-value">Finished</span></div>
    </div>
    <div class="jc-card-foot">
      <div class="jc-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
        <button class="jc-action-btn jc-btn-view" onclick="openJobDetail(<?= $job['id'] ?>)"><i class="bi bi-folder2-open"></i> Open</button>
        <button class="jc-action-btn jc-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div id="jcPanelHistory" style="display:none">

<style>
/* ── History Table Styles ── */
.ht-filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.ht-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:200px;outline:none;transition:border .15s}
.ht-search:focus{border-color:var(--jc-brand)}
.ht-date-input{padding:7px 12px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.76rem;outline:none}
.ht-date-input:focus{border-color:var(--jc-brand)}
.ht-period-btn{padding:5px 13px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.ht-period-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.ht-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.ht-bulk-bar{display:none;background:linear-gradient(135deg,#1e40af,#1e3a8a);color:#fff;border-radius:12px;padding:12px 20px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(30,64,175,.25)}
.ht-bulk-bar.visible{display:flex}
.ht-bulk-btn{padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff}
.ht-bulk-btn:hover{background:rgba(255,255,255,.22)}
.ht-bulk-print{padding:7px 16px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(34,197,94,.3)}
.ht-bulk-print:hover{background:#16a34a}
.ht-bulk-delete{padding:7px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(220,38,38,.3)}
.ht-bulk-delete:hover{background:#b91c1c}
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
.ht-jobname{font-size:.88rem;font-weight:900;color:#0f172a}
.ht-dim{color:#94a3b8;font-size:.72rem}
.ht-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.ht-badge-completed{background:#dcfce7;color:#166534}
.ht-badge-closed{background:#dcfce7;color:#166534}
.ht-badge-finalized{background:#dbeafe;color:#1e40af}
.ht-badge-default{background:#f1f5f9;color:#64748b}
.ht-act-btn{padding:4px 9px;font-size:.58rem;font-weight:800;text-transform:uppercase;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:6px;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:3px;color:#475569}
.ht-act-btn:hover{background:#f1f5f9}
.ht-act-btn.ht-print{color:#8b5cf6;border-color:#c4b5fd}
.ht-act-btn.ht-print:hover{background:#f5f3ff}
.ht-act-btn.ht-delete{color:#dc2626;border-color:#fecaca}
.ht-act-btn.ht-delete:hover{background:#fee2e2}
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

<!-- History Filter Bar -->
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
        <th onclick="htSortCol(3)">Roll No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(4)">Material<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(5)">Dimension<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(6)">GSM<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(7)">Status<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(8)">Started<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(9)">Completed<span class="ht-sort">▲▼</span></th>
        <th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody id="htBody">
    <?php if (empty($historyJobs)): ?>
      <tr><td colspan="12" style="padding:40px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i><br>No finished jobs found</td></tr>
    <?php else: ?>
      <?php foreach ($historyJobs as $idx => $h):
        $hSts = $h['status'];
        $hStsLower = strtolower(str_replace(' ', '', $hSts));
        $hStsClass = match($hStsLower) { 'closed'=>'closed','finalized'=>'finalized','completed'=>'completed', default=>'default' };
        $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
        $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : ($h['updated_at'] ? date('d M Y, H:i', strtotime($h['updated_at'])) : '—');
        $hDim = (($h['width_mm'] ?? '—') . 'mm × ' . ($h['length_mtr'] ?? '—') . 'm');
        $hSearch = strtolower($h['job_no'] . ' ' . ($h['roll_no'] ?? '') . ' ' . ($h['company'] ?? '') . ' ' . ($h['display_job_name'] ?? '') . ' ' . ($h['paper_type'] ?? ''));
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
        <td><span class="ht-jobname"><?= e($h['display_job_name'] ?? '—') ?></span></td>
        <td style="color:var(--jc-brand);font-weight:800"><?= e($h['roll_no'] ?? '—') ?></td>
        <td><?= e($h['paper_type'] ?? '—') ?></td>
        <td class="ht-dim"><?= e($hDim) ?></td>
        <td class="ht-dim"><?= e($h['gsm'] ?? '—') ?></td>
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

<!-- ═══ PRINT AREA (hidden, used for browser print) ═══ -->
<div class="jc-print-area" id="jcPrintArea"></div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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
const CAN_MANUAL_ROLL_ENTRY = <?= $canManualRollEntry ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $canDeleteJobs ? 'true' : 'false' ?>;
const JC_AUTO_REFRESH_MS = 45000;
const RC_REQUEST_CACHE = {};
let RC_EDITOR_STATE = null;

function formatDepartmentLabel(dept) {
  const map = { jumbo_slitting: 'Jumbo Slitting', flexo_printing: 'Flexo Printing' };
  const key = String(dept || '').trim().toLowerCase();
  if (map[key]) return map[key];
  return String(dept || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).trim();
}

function resolveJobDisplayName(job) {
  if (job && String(job.display_job_name || '').trim() !== '') return String(job.display_job_name).trim();
  if (job && String(job.planning_job_name || '').trim() !== '') return String(job.planning_job_name).trim();
  const jobNo = String(job?.job_no || '').trim();
  const dept = formatDepartmentLabel(job?.department);
  if (jobNo !== '') return dept ? `${jobNo} (${dept})` : jobNo;
  return dept || '—';
}

function switchJumboTab(tab) {
  const panels = {
    active:       document.getElementById('jcPanelActive'),
    history:      document.getElementById('jcPanelHistory'),
  };
  const btns = {
    active:       document.getElementById('jcTabBtnActive'),
    history:      document.getElementById('jcTabBtnHistory'),
  };
  Object.keys(panels).forEach(function(k) {
    if (panels[k]) panels[k].style.display = (k === tab) ? '' : 'none';
    if (btns[k])   btns[k].classList.toggle('active', k === tab);
  });
  if (tab === 'history') htGoPage(1);
}

// \u2500\u2500\u2500 Filters \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
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

// ─── History table: search/filter/sort/pagination ─────────
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
  if (!ids.length) { jumboNotify('No history job selected', 'warn'); return; }

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
    w.document.write('<!DOCTYPE html><html><head><title>History Bulk Print - ' + jobs.length + ' Job Cards</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>' + pages + '</body></html>');
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 400);
  })();
}

async function htBulkDelete() {
  if (!IS_ADMIN) { jumboNotify('Access denied. Only system admin can delete job cards.', 'bad'); return; }
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(cb => cb.dataset.jobId);
  if (!ids.length) { jumboNotify('No history jobs selected', 'warn'); return; }
  if (!(await jumboConfirm(`Delete ${ids.length} selected job card(s)?\n\nThis will reset linked paper stock, planning status, and downstream queued jobs for each.`, 'Confirm Bulk Delete'))) return;

  let ok = 0, failed = 0, errors = [];
  for (const id of ids) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_job');
    fd.append('job_id', id);
    try {
      const res = await fetch(API_BASE, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) { ok++; }
      else {
        failed++;
        const job = ALL_JOBS.find(j => j.id == id);
        const label = job ? job.job_no : ('ID ' + id);
        errors.push(`${label}: ${data.error || 'Unknown error'}`);
      }
    } catch (err) { failed++; errors.push('ID ' + id + ': ' + err.message); }
  }
  if (errors.length) jumboNotify(`Deleted: ${ok}, Failed: ${failed}`, failed > 0 ? 'warn' : 'ok');
  if (ok > 0) location.reload();
}

document.getElementById('htSearch')?.addEventListener('input', function() {
  htGoPage(1);
});

// ─── Live timers for running jobs ────────────────────────────
function updateTimers() {
  document.querySelectorAll('.jc-timer').forEach(el => {
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

function canAutoRefreshMainJumboPage() {
  return !document.getElementById('jcDetailModal')?.classList.contains('active');
}

setInterval(function() {
  if (canAutoRefreshMainJumboPage()) {
    location.reload();
  }
}, JC_AUTO_REFRESH_MS);

// ─── Status update ──────────────────────────────────────────
async function updateJobStatus(id, newStatus) {
  if (!(await jumboConfirm('Set this job to ' + newStatus + '?', 'Confirm Status Change'))) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else jumboNotify('Error: ' + (data.error || 'Unknown'), 'bad');
  } catch (err) { jumboNotify('Network error: ' + err.message, 'bad'); }
}

// ─── Timer overlay (Flexo-style) ────────────────────────────
let _timerInterval = null;
let _timerStart = 0;
let _timerJobId = null;

function isJumboTimerActive(job) {
  return !!(job && job.extra_data_parsed && job.extra_data_parsed.timer_active);
}

function jumboSecondsToHms(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = String(Math.floor(sec / 3600)).padStart(2, '0');
  const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
  const s = String(sec % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

function jumboFormatDuration(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return s > 0 ? `${m}m ${s}s` : `${m}m`;
  return `${s}s`;
}

function jumboFormatDateTime(value) {
  const raw = String(value || '').trim();
  if (!raw) return '—';
  const parsed = new Date(raw.replace(' ', 'T'));
  return Number.isFinite(parsed.getTime()) ? parsed.toLocaleString() : '—';
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

function jumboDurationSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const acc = Math.max(0, Math.floor(Number(extra.timer_accumulated_seconds || 0)));
  if (acc > 0) return acc;
  const mins = Number(job.duration_minutes || 0);
  return Number.isFinite(mins) && mins > 0 ? Math.floor(mins * 60) : 0;
}

function jumboPauseTotalSeconds(extra) {
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

function buildJumboTimerHistoryHtml(job) {
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
  const pauseTotalSeconds = segments
    .filter(row => row.kind === 'pause')
    .reduce((sum, row) => sum + Math.max(0, Number(row.seconds || 0)), 0);
  const summaryText = summaryBits.length ? summaryBits.join(', ') : 'No time ranges yet';

  const eventMap = { start: 'Start', resume: 'Again Start', pause: 'Pause', end: 'End' };
  const eventsHtml = eventRows.length
    ? eventRows.map(row => `<div class="jc-timer-history-row"><div class="k">${esc(eventMap[String(row.type || '').toLowerCase()] || 'Event')}</div><div class="v">${esc(jumboFormatDateTime(row.at))}</div></div>`).join('')
    : '<div class="jc-timer-history-empty">No timer event history yet.</div>';
  const segmentsHtml = segments.length
    ? segments.map(row => `<div class="jc-timer-history-row ${row.kind}"><div class="k">${row.kind === 'pause' ? 'Paused' : 'Worked'}</div><div class="v">${esc(jumboFormatDateTime(row.from))} - ${esc(jumboFormatDateTime(row.to))}<br><span style="color:#64748b;font-weight:800">${esc(jumboFormatDuration(row.seconds))}</span></div></div>`).join('')
    : '<div class="jc-timer-history-empty">No work/pause ranges recorded yet.</div>';

  return `<div class="jc-timer-history">
    <div class="jc-timer-history-card"><h4>Event Log</h4><div class="jc-timer-history-list">${eventsHtml}</div></div>
    <div class="jc-timer-history-card"><h4>Work / Pause Range</h4><div style="font-size:.82rem;font-weight:800;color:#0f172a;line-height:1.55;margin-bottom:8px">${esc(summaryText)}</div><div style="font-size:.78rem;font-weight:900;color:#b45309;margin-bottom:10px">Total Pause: ${esc(jumboFormatDuration(pauseTotalSeconds))}</div><div class="jc-timer-history-list">${segmentsHtml}</div></div>
  </div>`;
}

async function markJumboTimerEnded(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'end_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to end timer');

  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'ended';
    job.extra_data_parsed.timer_ended_at = data.timer_ended_at || '';
  }
}

async function endRunningJumboTimer(jobId) {
  try {
    await markJumboTimerEnded(jobId);
  } catch (err) {
    jumboNotify('Timer end failed: ' + err.message, 'bad');
    return;
  }

  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  openJobDetail(jobId, 'complete');
}

let _jumboVerifierScanner = null;
let _jumboVerifierNativeStream = null;
let _jumboVerifierNativeTimer = null;
let _jumboVerifierScannerMode = '';

function jumboNotify(text, kind) {
  const t = String(text || '').trim();
  if (!t) return;
  if (typeof showToast === 'function') {
    showToast(t, kind === 'bad' ? 'error' : (kind || 'info'));
    return;
  }

  let wrap = document.getElementById('jvToastWrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'jvToastWrap';
    wrap.className = 'jv-toast-wrap';
    document.body.appendChild(wrap);
  }
  const toast = document.createElement('div');
  toast.className = 'jv-toast ' + (kind || 'info');
  toast.textContent = t;
  wrap.appendChild(toast);
  setTimeout(function() {
    if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
    if (wrap && wrap.childElementCount === 0 && wrap.parentNode) wrap.parentNode.removeChild(wrap);
  }, 2600);
}

function jumboConfirm(message, title) {
  return new Promise(function(resolve) {
    const overlay = document.createElement('div');
    overlay.className = 'jx-overlay';
    overlay.innerHTML = `
      <div class="jx-modal">
        <div class="jx-head">${esc(title || 'Please Confirm')}</div>
        <div class="jx-body"><div class="jx-msg">${esc(message || '')}</div></div>
        <div class="jx-foot">
          <button type="button" class="jc-action-btn jc-btn-view" id="jxCancelBtn">Cancel</button>
          <button type="button" class="jc-action-btn jc-btn-start" id="jxOkBtn">Confirm</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    let done = false;
    function finish(val) {
      if (done) return;
      done = true;
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      resolve(!!val);
    }

    overlay.querySelector('#jxCancelBtn').addEventListener('click', function(){ finish(false); });
    overlay.querySelector('#jxOkBtn').addEventListener('click', function(){ finish(true); });
    overlay.addEventListener('click', function(ev) { if (ev.target === overlay) finish(false); });
  });
}

function jumboPrompt(message, defaultValue, title) {
  return new Promise(function(resolve) {
    const overlay = document.createElement('div');
    overlay.className = 'jx-overlay';
    overlay.innerHTML = `
      <div class="jx-modal">
        <div class="jx-head">${esc(title || 'Input Required')}</div>
        <div class="jx-body">
          <div class="jx-msg">${esc(message || '')}</div>
          <input type="text" class="jx-input" id="jxPromptInput" value="${esc(defaultValue || '')}">
        </div>
        <div class="jx-foot">
          <button type="button" class="jc-action-btn jc-btn-view" id="jxPromptCancel">Cancel</button>
          <button type="button" class="jc-action-btn jc-btn-start" id="jxPromptOk">Submit</button>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);

    const input = overlay.querySelector('#jxPromptInput');
    if (input) {
      input.focus();
      input.select();
    }

    let done = false;
    function finish(val) {
      if (done) return;
      done = true;
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      resolve(val);
    }

    overlay.querySelector('#jxPromptCancel').addEventListener('click', function(){ finish(null); });
    overlay.querySelector('#jxPromptOk').addEventListener('click', function(){ finish(String(input?.value || '')); });
    overlay.addEventListener('click', function(ev) { if (ev.target === overlay) finish(null); });
    overlay.addEventListener('keydown', function(ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); finish(null); }
      if (ev.key === 'Enter') { ev.preventDefault(); finish(String(input?.value || '')); }
    });
  });
}

function jumboNormalizeRollNo(val) {
  return String(val || '').trim().toUpperCase();
}

function jumboExtractRollNoFromQr(rawValue) {
  const raw = String(rawValue || '').trim();
  if (!raw) return '';

  try {
    const u = new URL(raw);
    const qRoll = u.searchParams.get('roll_no') || u.searchParams.get('roll') || u.searchParams.get('rn') || '';
    if (String(qRoll).trim()) return String(qRoll).trim();
  } catch (e) {}

  const named = raw.match(/(?:roll\s*no|roll_no|roll)\s*[:=\-]\s*([A-Za-z0-9._\/-]+)/i);
  if (named && named[1]) return named[1].trim();

  const tokens = raw.split(/[^A-Za-z0-9._\/-]+/).filter(Boolean);
  if (tokens.length) return tokens[tokens.length - 1].trim();

  return raw;
}

function jumboBuildRequiredParentRolls(job) {
  const extra = (job && job.extra_data_parsed) ? job.extra_data_parsed : {};
  const out = [];
  const uniq = {};

  function addRoll(rollNo, meta) {
    const raw = String(rollNo || '').trim();
    const n = jumboNormalizeRollNo(raw);
    if (!n || uniq[n]) return;
    uniq[n] = true;
    out.push({
      roll_no: raw,
      norm: n,
      paper_type: String((meta && meta.paper_type) || ''),
      width: (meta && (meta.width ?? meta.width_mm)) ?? '',
      length: (meta && (meta.length ?? meta.length_mtr)) ?? ''
    });
  }

  const p = extra.parent_details || {};
  addRoll(p.roll_no || extra.parent_roll || job?.roll_no || '', {
    paper_type: p.paper_type || job?.paper_type || '',
    width: p.width_mm ?? job?.width_mm ?? '',
    length: p.length_mtr ?? job?.length_mtr ?? ''
  });

  const parentRollsRaw = extra.parent_rolls;
  if (Array.isArray(parentRollsRaw)) {
    parentRollsRaw.forEach(function(pr) {
      addRoll(pr, { paper_type: job?.paper_type || '', width: job?.width_mm ?? '', length: job?.length_mtr ?? '' });
    });
  } else if (typeof parentRollsRaw === 'string' && parentRollsRaw.trim() !== '') {
    parentRollsRaw.split(',').forEach(function(pr) {
      addRoll(pr, { paper_type: job?.paper_type || '', width: job?.width_mm ?? '', length: job?.length_mtr ?? '' });
    });
  }

  (Array.isArray(extra.child_rolls) ? extra.child_rolls : []).forEach(function(r) {
    addRoll(r && r.parent_roll_no ? r.parent_roll_no : '', {
      paper_type: r?.paper_type || '',
      width: r?.width ?? r?.width_mm ?? '',
      length: r?.length ?? r?.length_mtr ?? ''
    });
  });
  (Array.isArray(extra.stock_rolls) ? extra.stock_rolls : []).forEach(function(r) {
    addRoll(r && r.parent_roll_no ? r.parent_roll_no : '', {
      paper_type: r?.paper_type || '',
      width: r?.width ?? r?.width_mm ?? '',
      length: r?.length ?? r?.length_mtr ?? ''
    });
  });

  return out;
}

function jumboShowVerificationMessage(el, kind, text) {
  if (!el) return;
  el.className = 'jv-msg show';
  const k = String(kind || 'info');
  if (k === 'ok') el.classList.add('jv-msg-ok');
  else if (k === 'warn') el.classList.add('jv-msg-warn');
  else if (k === 'bad') el.classList.add('jv-msg-bad');
  else el.classList.add('jv-msg-info');
  el.innerHTML = esc(text || '');
}

function jumboCloseVerifierScanner() {
  if (_jumboVerifierNativeTimer) {
    clearInterval(_jumboVerifierNativeTimer);
    _jumboVerifierNativeTimer = null;
  }
  if (_jumboVerifierNativeStream) {
    try {
      (_jumboVerifierNativeStream.getTracks() || []).forEach(function(t) { try { t.stop(); } catch (e) {} });
    } catch (e) {}
    _jumboVerifierNativeStream = null;
  }
  if (!_jumboVerifierScanner) return Promise.resolve();
  const inst = _jumboVerifierScanner;
  _jumboVerifierScanner = null;
  _jumboVerifierScannerMode = '';
  if (inst && typeof inst.clear === 'function' && typeof inst.render === 'function') {
    return inst.clear().catch(function(){});
  }
  if (inst && typeof inst.clear === 'function' && typeof inst.stop !== 'function') {
    return inst.clear().catch(function(){});
  }
  if (inst && typeof inst.stop === 'function') {
    return inst.stop().catch(function(){ }).then(function(){
      return (typeof inst.clear === 'function') ? inst.clear().catch(function(){}) : Promise.resolve();
    });
  }
  return Promise.resolve();
}

async function jumboStartNativeBarcodeScanner(containerId, onDecoded) {
  const container = document.getElementById(containerId);
  if (!container) throw new Error('Scanner container not found');
  if (!('BarcodeDetector' in window)) throw new Error('BarcodeDetector API unavailable');
  if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
    throw new Error('Camera API unavailable');
  }

  const stream = await navigator.mediaDevices.getUserMedia({
    video: {
      facingMode: { ideal: 'environment' },
      width: { ideal: 1280 },
      height: { ideal: 720 }
    }
  });
  _jumboVerifierNativeStream = stream;

  const video = document.createElement('video');
  video.setAttribute('playsinline', 'true');
  video.autoplay = true;
  video.muted = true;
  video.style.width = '100%';
  video.style.borderRadius = '8px';
  video.srcObject = stream;
  container.innerHTML = '';
  container.appendChild(video);

  await video.play();

  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d');
  const detector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8'] });

  _jumboVerifierNativeTimer = setInterval(async function() {
    try {
      if (!video.videoWidth || !video.videoHeight) return;
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const codes = await detector.detect(canvas);
      if (Array.isArray(codes) && codes.length) {
        const raw = String(codes[0].rawValue || '').trim();
        if (raw) onDecoded(raw);
      }
    } catch (e) {}
  }, 350);
}

async function jumboOpenRollVerification(job) {
  const required = jumboBuildRequiredParentRolls(job);
  if (!required.length) {
    return { ok: false, error: 'No parent rolls found for this job.' };
  }

  const rowMap = {};
  required.forEach(function(r){ rowMap[r.norm] = r; });
  const matched = {};
  const methods = {};

  const overlay = document.createElement('div');
  overlay.className = 'jv-overlay';
  overlay.innerHTML = `
    <div class="jv-modal">
      <div class="jv-head">
        <div>
          <div class="jv-title"><i class="bi bi-upc-scan"></i> Roll Verification Required</div>
          <div class="jv-sub">Job ${esc(job.job_no || ('#' + job.id))} - verify all parent rolls before starting.</div>
        </div>
        <button type="button" class="jc-action-btn jc-btn-view" id="jvCloseBtn"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="jv-body">
        <div id="jvMsg" class="jv-msg"></div>
        <div class="jv-grid">
          <div class="jv-card">
            <h4>Required Parent Rolls</h4>
            <div class="jv-roll-list" id="jvRollList"></div>
          </div>
          <div class="jv-card">
            <h4>Scan or Manual Entry</h4>
            <div class="jv-tools">
              <button type="button" class="jv-tool-btn jv-tool-btn-main" id="jvStartScan"><i class="bi bi-camera"></i> Start QR Scanner</button>
              <button type="button" class="jv-tool-btn jv-tool-btn-stop" id="jvStopScan" style="display:none"><i class="bi bi-stop-fill"></i> Stop Scanner</button>
              <input type="file" id="jvScanFile" accept="image/*" capture="environment" style="display:none">
              <button type="button" class="jv-tool-btn" id="jvScanFromPhoto"><i class="bi bi-image"></i> Scan From Camera Photo</button>
              <label style="font-size:.63rem;font-weight:900;color:#475569;text-transform:uppercase">Last Scan Value</label>
              <input type="text" class="jv-readonly" id="jvLastValue" readonly placeholder="No scan yet">
              <div id="jvManualSection" style="display:flex;flex-direction:column;gap:4px">
                <div style="display:flex;align-items:center;gap:6px">
                  <label style="font-size:.63rem;font-weight:900;color:#475569;text-transform:uppercase">Manual Roll Input</label>
                  <span id="jvManualLock" class="jv-pill" style="font-size:.55rem;display:none;background:#fee2e2;color:#991b1b"><i class="bi bi-lock"></i> Operators: Scan Only</span>
                </div>
                <input type="text" class="jv-input" id="jvManualValue" placeholder="Type roll no and add">
                <button type="button" class="jv-tool-btn" id="jvAddManual"><i class="bi bi-keyboard"></i> Add Manual</button>
              </div>
              <div class="jv-scan-box"><div id="jvScanner" class="jv-scanner"></div></div>
            </div>
          </div>
        </div>
      </div>
      <div class="jv-foot">
        <div class="jv-progress" id="jvProgress">Matched 0 / ${required.length}</div>
        <div class="jv-actions">
          <button type="button" class="jc-action-btn jc-btn-view" id="jvCancel">Cancel</button>
          <button type="button" class="jc-action-btn jc-btn-start" id="jvProceed" disabled>Start Job</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);

  const rollListEl = overlay.querySelector('#jvRollList');
  const msgEl = overlay.querySelector('#jvMsg');
  const progressEl = overlay.querySelector('#jvProgress');
  const proceedBtn = overlay.querySelector('#jvProceed');
  const lastValEl = overlay.querySelector('#jvLastValue');
  const manualEl = overlay.querySelector('#jvManualValue');
  const manualBtn = overlay.querySelector('#jvAddManual');
  const manualLockBadge = overlay.querySelector('#jvManualLock');
  const manualSection = overlay.querySelector('#jvManualSection');
  const startScanBtn = overlay.querySelector('#jvStartScan');
  const stopScanBtn = overlay.querySelector('#jvStopScan');
  const scanFromPhotoBtn = overlay.querySelector('#jvScanFromPhoto');
  const scanFileInput = overlay.querySelector('#jvScanFile');

  // Apply permission controls for manual entry
  const manualEntryRestricted = !CAN_MANUAL_ROLL_ENTRY;
  if (manualEntryRestricted) {
    if (manualLockBadge) manualLockBadge.style.display = '';
    if (manualSection) manualSection.classList.add('jv-manual-section-restricted');
    if (manualEl) {
      manualEl.disabled = true;
      manualEl.style.opacity = '0.5';
      manualEl.style.backgroundColor = '#f8fafc';
      manualEl.style.cursor = 'not-allowed';
      manualEl.placeholder = 'Manual entry disabled. Use QR Scanner only';
    }
    if (manualBtn) {
      manualBtn.disabled = true;
      manualBtn.style.opacity = '0.5';
      manualBtn.style.backgroundColor = '#f1f5f9';
      manualBtn.style.cursor = 'not-allowed';
      manualBtn.title = 'Manual roll entry is disabled for your account. Please use QR Scanner.';
    }
  }

  function renderList() {
    rollListEl.innerHTML = required.map(function(r) {
      const ok = !!matched[r.norm];
      const method = methods[r.norm] ? ` (${methods[r.norm]})` : '';
      return `<div class="jv-roll-row">
        <div>
          <div class="jv-roll-no">${esc(r.roll_no)}</div>
          <div class="jv-roll-meta">${esc(r.paper_type || '--')} | ${esc(String(r.width || '--'))} x ${esc(String(r.length || '--'))}</div>
        </div>
        <div><span class="jv-pill ${ok ? 'jv-pill-ok' : 'jv-pill-pending'}">${ok ? ('Matched' + method) : 'Pending'}</span></div>
      </div>`;
    }).join('');

    const matchedCount = Object.keys(matched).length;
    progressEl.textContent = `Matched ${matchedCount} / ${required.length}`;
    proceedBtn.disabled = matchedCount !== required.length;
  }

  function processRawValue(raw, method) {
    const source = String(method || 'qr');
    const shown = String(raw || '').trim();
    if (lastValEl) lastValEl.value = shown;

    const extracted = jumboExtractRollNoFromQr(raw);
    const norm = jumboNormalizeRollNo(extracted);
    if (!norm) {
      jumboShowVerificationMessage(msgEl, 'bad', 'Could not detect a valid roll number.');
      return;
    }
    if (!rowMap[norm]) {
      jumboShowVerificationMessage(msgEl, 'bad', `Scanned roll ${extracted} is not assigned to this job.`);
      return;
    }
    if (matched[norm]) {
      jumboShowVerificationMessage(msgEl, 'warn', `Roll ${rowMap[norm].roll_no} already matched.`);
      return;
    }
    matched[norm] = true;
    methods[norm] = source.toUpperCase();
    renderList();
    const done = Object.keys(matched).length === required.length;
    jumboShowVerificationMessage(msgEl, done ? 'ok' : 'info', done ? 'All parent rolls matched. You can start the job now.' : `Matched ${rowMap[norm].roll_no}. Continue remaining rolls.`);
  }

  async function startScanner() {
    const hasHtml5Scanner = (typeof Html5QrcodeScanner === 'function');
    const hasNative = ('BarcodeDetector' in window);
    if (!hasHtml5Scanner && !hasNative) {
      jumboShowVerificationMessage(msgEl, 'bad', 'Scanner is unavailable on this device/browser. Use manual input.');
      return;
    }
    await jumboCloseVerifierScanner();

    startScanBtn.style.display = 'none';
    stopScanBtn.style.display = '';
    jumboShowVerificationMessage(msgEl, 'info', 'Scanner opening...');

    const onScan = function(decodedText) { processRawValue(decodedText, 'qr'); };
    const onErr = function() {};

    if (hasHtml5Scanner) {
      try {
        const readerEl = document.getElementById('jvScanner');
        if (readerEl) readerEl.innerHTML = '';
        const scanner = new Html5QrcodeScanner('jvScanner', {
          fps: 10,
          qrbox: { width: 250, height: 250 },
          rememberLastUsedCamera: false,
          formatsToSupport: [
            Html5QrcodeSupportedFormats.QR_CODE,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8
          ]
        }, false);
        _jumboVerifierScanner = scanner;
        _jumboVerifierScannerMode = 'html5-render';
        scanner.render(onScan, onErr);
        jumboShowVerificationMessage(msgEl, 'info', 'Scanner started. Point camera at parent roll QR code.');
        return;
      } catch (fallbackErr) {
        // Fall through to native fallback below.
      }
    }

    if (hasNative) {
      try {
        await jumboStartNativeBarcodeScanner('jvScanner', onScan);
        jumboShowVerificationMessage(msgEl, 'info', 'Native scanner started. Point camera at parent roll QR code.');
        return;
      } catch (nativeErr) {}
    }

    startScanBtn.style.display = '';
    stopScanBtn.style.display = 'none';
    jumboShowVerificationMessage(msgEl, 'bad', 'Camera could not start. Allow camera permission, then retry. You can also use Scan From Camera Photo.');
  }

  async function stopScanner() {
    await jumboCloseVerifierScanner();
    startScanBtn.style.display = '';
    stopScanBtn.style.display = 'none';
    const stopMsg = CAN_MANUAL_ROLL_ENTRY
      ? 'Scanner stopped. You can use manual input or restart scanner.'
      : 'Scanner stopped. Restart scanner to continue verification.';
    jumboShowVerificationMessage(msgEl, 'info', stopMsg);
  }

  renderList();

  // Show permission-based initial message
  if (!CAN_MANUAL_ROLL_ENTRY) {
    jumboShowVerificationMessage(msgEl, 'info', '<strong>⚠️ Scan Only Mode:</strong> Your account cannot use manual roll entry. Please verify rolls using QR Scanner and then start the job.');
  } else {
    jumboShowVerificationMessage(msgEl, 'info', 'Verify parent rolls by scanning QR codes or manually entering roll numbers.');
  }

  async function scanFromImageFile(file) {
    if (!file) return;
    if (typeof Html5Qrcode !== 'function') {
      jumboShowVerificationMessage(msgEl, 'bad', 'Scanner library is unavailable for image decode.');
      return;
    }
    const tmpId = 'jvScanTmp_' + Date.now();
    const tmp = document.createElement('div');
    tmp.id = tmpId;
    tmp.style.display = 'none';
    document.body.appendChild(tmp);
    const qr = new Html5Qrcode(tmpId);
    try {
      const decoded = await qr.scanFile(file, true);
      processRawValue(decoded, 'qr');
      jumboShowVerificationMessage(msgEl, 'ok', 'QR decoded from image successfully.');
    } catch (err) {
      jumboShowVerificationMessage(msgEl, 'bad', 'Could not decode QR from image. Try clearer photo or live camera scan.');
    } finally {
      try { await qr.clear(); } catch (e) {}
      if (tmp && tmp.parentNode) tmp.parentNode.removeChild(tmp);
    }
  }

  setTimeout(function() { startScanner(); }, 150);

  return new Promise(function(resolve) {
    let done = false;
    function finish(result) {
      if (done) return;
      done = true;
      jumboCloseVerifierScanner().finally(function() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        resolve(result);
      });
    }

    overlay.querySelector('#jvCloseBtn').addEventListener('click', function(){ finish({ ok: false, error: 'Verification cancelled.' }); });
    overlay.querySelector('#jvCancel').addEventListener('click', function(){ finish({ ok: false, error: 'Verification cancelled.' }); });
    overlay.querySelector('#jvProceed').addEventListener('click', function(){
      if (Object.keys(matched).length !== required.length) {
        jumboShowVerificationMessage(msgEl, 'bad', 'All assigned rolls must be matched before starting.');
        return;
      }
      const verified = required.filter(function(r){ return !!matched[r.norm]; }).map(function(r){ return r.roll_no; });
      finish({ ok: true, verified_rolls: verified });
    });

    startScanBtn.addEventListener('click', startScanner);
    stopScanBtn.addEventListener('click', stopScanner);
    scanFromPhotoBtn.addEventListener('click', function() { if (scanFileInput) scanFileInput.click(); });
    scanFileInput.addEventListener('change', function() {
      const file = scanFileInput.files && scanFileInput.files[0] ? scanFileInput.files[0] : null;
      scanFromImageFile(file);
      scanFileInput.value = '';
    });
    overlay.querySelector('#jvAddManual').addEventListener('click', function() {
      // Permission check: operators cannot manually enter rolls
      if (!CAN_MANUAL_ROLL_ENTRY) {
        jumboShowVerificationMessage(msgEl, 'bad', 'Manual roll entry is disabled for your account. Please use QR Scanner to verify rolls.');
        return;
      }
      const raw = String(manualEl.value || '').trim();
      if (!raw) {
        jumboShowVerificationMessage(msgEl, 'warn', 'Enter a roll number first.');
        return;
      }
      processRawValue(raw, 'manual');
      manualEl.value = '';
      manualEl.focus();
    });
    manualEl.addEventListener('keydown', function(ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        // Permission check: operators cannot manually enter rolls
        if (!CAN_MANUAL_ROLL_ENTRY) {
          jumboShowVerificationMessage(msgEl, 'bad', 'Manual roll entry is disabled for your account. Please use QR Scanner to verify rolls.');
          return;
        }
        overlay.querySelector('#jvAddManual').click();
      }
    });
  });
}

async function startJobWithTimer(id) {
  if (!(await jumboConfirm('Start this job?', 'Confirm Job Start'))) return;

  let job = ALL_JOBS.find(j => j.id == id) || null;
  const verification = await jumboOpenRollVerification(job || { id: id, job_no: id, extra_data_parsed: {} });
  if (!verification.ok) {
    jumboNotify(verification.error || 'Roll verification is required before starting this job.', 'warn');
    return;
  }

  const verifiedRolls = Array.isArray(verification.verified_rolls)
    ? verification.verified_rolls.map(function(v) { return String(v || '').trim(); }).filter(Boolean)
    : [];
  if (!verifiedRolls.length) {
    jumboNotify('Roll verification did not capture any parent roll. Please verify again.', 'bad');
    return;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', 'Running');
  fd.append('verified_rolls_json', JSON.stringify(verifiedRolls));
  fd.append('verified_rolls_csv', verifiedRolls.join(','));
  fd.append('verified_rolls_count', String(verifiedRolls.length));
  verifiedRolls.forEach(function(rollNo) {
    fd.append('verified_rolls[]', rollNo);
  });
  fd.append('verified_rolls_mode', 'qr_manual');
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const raw = await res.text();
    let data = null;
    try { data = JSON.parse(raw); } catch (parseErr) {
      jumboNotify('Server returned invalid response. ' + raw.slice(0, 120), 'bad');
      return;
    }
    if (!data.ok) { jumboNotify('Start failed: ' + (data.error || 'Unknown error'), 'bad'); return; }
  } catch (err) { jumboNotify('Network error: ' + err.message, 'bad'); return; }

  document.getElementById('jcDetailModal').classList.remove('active');
  _timerJobId = id;
  _timerStart = Date.now();
  job = ALL_JOBS.find(j => j.id == id) || {};
  job.status = 'Running';
  job.started_at = new Date().toISOString();
  job.extra_data_parsed = job.extra_data_parsed || {};
  job.extra_data_parsed.timer_active = true;
  job.extra_data_parsed.timer_state = 'running';
  job.extra_data_parsed.timer_started_at = new Date().toISOString();
  job.extra_data_parsed.timer_ended_at = '';
  const jobLabel = resolveJobDisplayName(job) || ('Job #' + id);
  const jobNo = job.job_no || '';
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
  jumboNotify('Job started successfully.', 'ok');

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
    _timerJobId = null;
    location.reload();
  })();
}

function endTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const jobId = _timerJobId;
  _timerJobId = null;
  endRunningJumboTimer(jobId);
}

// ─── Upload photo for jumbo job ─────────────────────────────
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
      const job = ALL_JOBS.find(j => j.id == jobId);
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

function buildJumboExtraDataFromForm(form) {
  if (!form) return null;
  return {
    actual_output_weight: form.querySelector('[name=actual_output_weight]')?.value || '',
    wastage_kg: form.querySelector('[name=wastage_kg]')?.value || '',
    roll_condition: form.querySelector('[name=roll_condition]')?.value || 'Good',
    operator_notes: form.querySelector('[name=operator_notes]')?.value || '',
    defects: Array.from(form.querySelectorAll('[name=defects]:checked')).map(c=>c.value)
  };
}

// ─── Submit operator extra data + close ─────────────────────
async function submitAndClose(id) {
  const job = ALL_JOBS.find(j => j.id == id) || {};
  if (isJumboTimerActive(job)) {
    jumboNotify('Timer is still running. Please End the timer before finishing this job.', 'warn');
    return;
  }
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateJobStatus(id, 'Closed');
  const extraData = buildJumboExtraDataFromForm(form);
  if (!extraData) return updateJobStatus(id, 'Closed');

  // Save extra data
  const fd1 = new FormData();
  fd1.append('csrf_token', CSRF);
  fd1.append('action', 'submit_extra_data');
  fd1.append('job_id', id);
  fd1.append('extra_data', JSON.stringify(extraData));
  try {
    const r1 = await fetch(API_BASE, { method: 'POST', body: fd1 });
    const d1 = await r1.json();
    if (!d1.ok) { jumboNotify('Save error: ' + (d1.error||'Unknown'), 'bad'); return; }
  } catch(e) { jumboNotify('Network error', 'bad'); return; }

  // Now close
  await updateJobStatus(id, 'Closed');
}

// ─── Detail modal ───────────────────────────────────────────
async function openJobDetail(id, mode) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;

  const sts = job.status;
  const stsClass = {Pending:'pending',Running:'running',Closed:'completed',Finalized:'completed'}[sts]||'pending';
  const timerActive = isJumboTimerActive(job);
  const extra = job.extra_data_parsed || {};
  const createdAt = jumboFormatDateTime(job.created_at);
  const startedAt = jumboFormatDateTime(extra.timer_started_at || job.started_at);
  const completedAt = jumboFormatDateTime(extra.timer_ended_at || job.completed_at);
  const activeSeconds = timerActive ? jumboTimerTotalSeconds(job) : jumboDurationSeconds(job);
  const pauseSeconds = jumboPauseTotalSeconds(extra);

  document.getElementById('dm-jobno').textContent = job.job_no;
  const badge = document.getElementById('dm-status-badge');
  badge.textContent = sts;
  badge.className = 'jc-badge jc-badge-' + stsClass;

  let html = '';

  const viewQrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no || '')}`;
  const viewQrDataUrl = await generateQR(viewQrUrl);
  const nowText = new Date().toLocaleString();

  // ── Company Header ──
  html += `<div class="jc-modal-only" style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:12px;border:1px solid #d1e7dd;border-radius:10px;background:linear-gradient(135deg,#f0fdf4,#fff)">
    <div style="display:flex;gap:10px;align-items:flex-start">
      ${COMPANY.logo ? `<img src="${COMPANY.logo}" alt="Logo" style="max-height:38px;max-width:110px;display:block">` : ''}
      <div>
        <div style="font-size:.92rem;font-weight:800;color:#0f172a">${esc(COMPANY.name || 'Company')}</div>
        <div style="font-size:.62rem;color:#64748b">${esc(COMPANY.address || '')}</div>
        ${COMPANY.gst ? `<div style="font-size:.62rem;color:#64748b">GST: ${esc(COMPANY.gst)}</div>` : ''}
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#334155">Jumbo Slitting Job Card</div>
      <div style="font-size:.92rem;font-weight:900;color:var(--jc-brand)">${esc(job.job_no || '—')}</div>
      <div style="font-size:.58rem;color:#64748b">${esc(nowText)}</div>
    </div>
  </div>`;

  // ── Timeline ──
  html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-clock-history"></i> Timeline</div><div class="jc-op-b"><div class="jc-op-topstrip">
    <div class="jc-op-topitem"><span class="k">Created</span><span class="v">${createdAt}</span></div>
    <div class="jc-op-topitem"><span class="k">Started</span><span class="v" style="color:#3b82f6">${startedAt}</span></div>
    <div class="jc-op-topitem"><span class="k">Closed</span><span class="v" style="color:#16a34a">${completedAt}</span></div>`;
  html += `<div class="jc-op-topitem"><span class="k">Active Time</span><span class="v" style="color:#16a34a">${activeSeconds > 0 ? esc(jumboFormatDuration(activeSeconds)) : '—'}</span></div>`;
  html += `<div class="jc-op-topitem"><span class="k">Pause Time</span><span class="v" style="color:#b45309">${pauseSeconds > 0 ? esc(jumboFormatDuration(pauseSeconds)) : '—'}</span></div>`;
  html += `</div>${buildJumboTimerHistoryHtml(job)}</div></div>`;

  // ── Notes ──
  if (job.notes) {
    html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-sticky"></i> Notes</div><div class="jc-op-b"><div style="font-size:.82rem;color:#475569;line-height:1.5;background:#f8fafc;padding:12px;border-radius:8px">${esc(job.notes)}</div></div></div>`;
  }

  // ── Job Information (no Department, no Planning Status) ──
  html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-info-circle"></i> Job Information</div><div class="jc-op-b jc-op-grid-2">
    <div class="jc-op-field"><label>Job No</label><div class="fv" style="color:var(--jc-brand)">${esc(job.job_no)}</div></div>
    <div class="jc-op-field"><label>Job Name</label><div class="fv" style="font-size:1.1rem;font-weight:900;line-height:1.35;color:#0f172a">${esc(resolveJobDisplayName(job))}</div></div>
    <div class="jc-op-field"><label>Priority</label><div class="fv">${esc(job.planning_priority||'Normal')}</div></div>
    <div class="jc-op-field"><label>Sequence</label><div class="fv">#${job.sequence_order||1}</div></div>
  </div></div>`;

  // Parent Roll(s) -- collect all unique parent rolls referenced by child/stock rows
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
      let parentTableHtml = '<table class="jc-op-roll-table"><tr><th>Roll No</th><th>Paper Company</th><th>Material</th><th>Width</th><th>Length</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Status</th><th>Remarks</th></tr>';
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
      html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-inbox"></i> Parent Roll${allParentRollNos.length > 1 ? 's' : ''}</div><div class="jc-op-b" style="overflow-x:auto">${parentTableHtml}</div></div>`;
    }
  }

  // All child rolls in one table (job assign + stock) with plan number and live status.
  const childRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
  const stockRows = Array.isArray(extra.stock_rolls) ? extra.stock_rolls : [];
  const allRows = [];
  childRows.forEach(function(r) {
    const rrn = String(r.roll_no || '').trim();
    const liveEntry = (job.live_roll_map && job.live_roll_map[rrn]) ? job.live_roll_map[rrn] : {};
    allRows.push({
      parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '',
      roll_no: r.roll_no || '',
      type: r.paper_type || job.paper_type || '',
      width: (r.width ?? r.width_mm),
      length: (r.length ?? r.length_mtr),
      weight_kg: (r.weight_kg ?? job.weight_kg),
      sqm: (r.sqm ?? '--'),
      gsm: (r.gsm ?? job.gsm),
      wastage: (r.wastage ?? 0),
      remarks: liveEntry.remarks !== undefined ? liveEntry.remarks : (r.remarks || ''),
      status: liveEntry.status || r.status_live || 'Job Assign',
    });
  });
  stockRows.forEach(function(r) {
    const rrn = String(r.roll_no || '').trim();
    const liveEntry = (job.live_roll_map && job.live_roll_map[rrn]) ? job.live_roll_map[rrn] : {};
    allRows.push({
      parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '',
      roll_no: r.roll_no || '',
      type: r.paper_type || job.paper_type || '',
      width: (r.width ?? r.width_mm),
      length: (r.length ?? r.length_mtr),
      weight_kg: (r.weight_kg ?? job.weight_kg),
      sqm: (r.sqm ?? '--'),
      gsm: (r.gsm ?? job.gsm),
      wastage: (r.wastage ?? 0),
      remarks: liveEntry.remarks !== undefined ? liveEntry.remarks : (r.remarks || ''),
      status: liveEntry.status || r.status_live || 'Stock',
    });
  });

  if (allRows.length) {
    let allRollHtml = '<table class="jc-op-roll-table"><tr><th>Parent Roll No</th><th>Child Roll NO.</th><th>Width</th><th>Length</th><th>Type</th><th>Weight</th><th>Sqr Mtr</th><th>GSM</th><th>Wastage</th><th>Status</th><th>Remarks</th></tr>';
    allRows.forEach(function(r) {
      allRollHtml += `<tr><td style="color:#334155;font-weight:700">${esc(r.parent_roll_no || '--')}</td><td style="color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '--')}</td><td>${esc((r.width ?? '--') + '')}</td><td>${esc((r.length ?? '--') + '')}</td><td>${esc(r.type || '--')}</td><td>${esc((r.weight_kg ?? '--') + '')}</td><td>${esc((r.sqm ?? '--') + '')}</td><td>${esc((r.gsm ?? '--') + '')}</td><td>${esc((r.wastage ?? 0) + '')}</td><td>${esc(r.status || '--')}</td><td>${esc(r.remarks || '--')}</td></tr>`;
    });
    allRollHtml += '</table>';
    html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-table"></i> All Child Rolls</div><div class="jc-op-b" style="overflow-x:auto">${allRollHtml}</div></div>`;
  }

  // ── Operator Entry (after all rolls) ──
  {
    const opWaste = extra.wastage_kg || extra.operator_wastage_kg || '';
    const opNotes = extra.operator_notes || extra.operator_remarks || '';
    const voiceOriginal = extra.voice_input_original || '';
    const voiceEnglish = extra.voice_input_english || '';
    
    let opEntryHtml = `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-person-workspace"></i> Operator Entry</div><div class="jc-op-b jc-op-grid-2">
      <div class="jc-op-field"><label>Wastage (kg)</label><div class="fv">${esc(opWaste || '--')}</div></div>
      <div class="jc-op-field"><label>Operator Remarks</label><div class="fv">${esc(opNotes || '--')}</div></div>
    </div>`;
    
    if (voiceOriginal || voiceEnglish) {
      opEntryHtml += `<div style="margin:0 10px 10px;padding:12px;background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-radius:8px;border-left:3px solid #0891b2;font-size:.75rem">
        <div style="margin-bottom:6px"><strong style="color:#0891b2"><i class="bi bi-mic-fill"></i> Voice Input:</strong></div>`;
      if (voiceOriginal) opEntryHtml += `<div style="margin:4px 0;color:#475569"><strong>Original:</strong> ${esc(voiceOriginal)}</div>`;
      if (voiceEnglish) opEntryHtml += `<div style="margin:4px 0;color:#475569"><strong>English:</strong> ${esc(voiceEnglish)}</div>`;
      opEntryHtml += `</div>`;
    }
    
    opEntryHtml += `</div>`;
    html += opEntryHtml;
  }

  // ── Editable Operator Form (only in complete mode, operator view) ──
  if (mode === 'complete' && IS_OPERATOR_VIEW && sts === 'Running') {
    html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</div>
    <form id="dm-operator-form" class="jc-op-b" style="display:grid;gap:10px">
      <div class="jc-op-field"><label>Output Weight (kg)</label><input type="number" name="actual_output_weight" value="${esc(extra.actual_output_weight || '')}"></div>
      <div class="jc-op-field"><label>Wastage (kg)</label><input type="number" step="0.01" name="wastage_kg" value="${esc(extra.wastage_kg || extra.operator_wastage_kg || '')}"></div>
      <div class="jc-op-field"><label>Roll Condition</label><select name="roll_condition"><option value="Good" ${extra.roll_condition === 'Good' ? 'selected' : ''}>Good</option><option value="Fair" ${extra.roll_condition === 'Fair' ? 'selected' : ''}>Fair</option><option value="Poor" ${extra.roll_condition === 'Poor' ? 'selected' : ''}>Poor</option></select></div>
      <div class="jc-op-field"><label>Operator Notes</label><textarea name="operator_notes" style="min-height:80px">${esc(extra.operator_notes || extra.operator_remarks || '')}</textarea></div>
      <div class="jc-op-field"><label>Defects Found</label><div style="display:grid;gap:6px">
        <label><input type="checkbox" name="defects" value="Length Issue" ${Array.isArray(extra.defects) && extra.defects.includes('Length Issue') ? 'checked' : ''}> Length Issue</label>
        <label><input type="checkbox" name="defects" value="Width Issue" ${Array.isArray(extra.defects) && extra.defects.includes('Width Issue') ? 'checked' : ''}> Width Issue</label>
        <label><input type="checkbox" name="defects" value="Quality Issue" ${Array.isArray(extra.defects) && extra.defects.includes('Quality Issue') ? 'checked' : ''}> Quality Issue</label>
        <label><input type="checkbox" name="defects" value="Damage" ${Array.isArray(extra.defects) && extra.defects.includes('Damage') ? 'checked' : ''}> Damage</label>
      </div></div>
    </form></div>`;
  }

  // ── Photo Upload (always available) ──
  {
    const existingPhoto = extra.jumbo_photo_url || '';
    html += `<div class="jc-op-section jc-modal-only"><div class="jc-op-h"><i class="bi bi-camera"></i> Job Photo</div><div class="jc-op-b">
      <div class="jc-upload-zone" onclick="document.getElementById('jc-photo-input-${job.id}').click()">
        <input type="file" id="jc-photo-input-${job.id}" accept="image/*" capture="environment" onchange="uploadJumboPhoto(${job.id})">
        <div style="font-size:.75rem;color:#64748b"><i class="bi bi-cloud-arrow-up" style="font-size:1.5rem;color:var(--jc-brand)"></i><br>Tap to open camera</div>
        <div id="jc-photo-status-${job.id}" style="font-size:.7rem;margin-top:6px"></div>
      </div>
      <div id="jc-photo-preview-${job.id}" class="jc-upload-preview">${existingPhoto ? `<img src="${existingPhoto}" alt="Job Photo">` : ''}</div>
    </div></div>`;
  }

  // ── Company Footer ──
  html += `<div class="jc-modal-only" style="display:flex;justify-content:space-between;gap:10px;border-top:1px solid #d1e7dd;padding-top:8px;margin-top:4px;font-size:.62rem;color:#64748b">
    <span>${esc(APP_FOOTER_LEFT || '')}</span>
    <span>${esc(APP_FOOTER_RIGHT || '')}</span>
  </div>`;

  if (viewQrDataUrl) {
    html = `<div class="jc-op-section jc-modal-only" style="margin-bottom:0"><div class="jc-op-b" style="display:flex;align-items:center;justify-content:space-between;gap:14px">
      <div>
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.05em">Job Card QR</div>
        <div style="font-size:.74rem;color:#475569">Scan to open this job card on mobile/desktop</div>
      </div>
      <div style="text-align:center">
        <img src="${viewQrDataUrl}" alt="Job QR" style="width:96px;height:96px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px">
      </div>
    </div></div>` + html;
  }

  document.getElementById('dm-body').innerHTML = '<div class="jc-op-form">' + html + '</div>';
  updateTimers();

  // Footer actions (Flexo-style logic)
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += '<select id="dm-print-template" class="form-control" style="height:32px;min-width:140px"><option value="executive">Executive</option><option value="compact">Compact</option></select>';
  fHtml += `<button class="jc-action-btn jc-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Job Card Print</button>`;
  fHtml += `<button class="jc-action-btn jc-btn-view" onclick="printLabelsForJob(${job.id})"><i class="bi bi-upc-scan"></i> Label Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (IS_OPERATOR_VIEW) {
    if (mode === 'complete' && sts === 'Running' && !timerActive) {
      fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="submitAndClose(${job.id})" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
    } else if (sts === 'Pending') {
      fHtml += `<button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(${job.id})" style="background:var(--jc-brand);color:#fff;border-color:var(--jc-brand)"><i class="bi bi-play-fill"></i> Start Job</button>`;
    } else if (sts === 'Running' && timerActive) {
      fHtml += `<button class="jc-action-btn jc-btn-start" onclick="endRunningJumboTimer(${job.id})" style="background:#dc2626;color:#fff;border-color:#dc2626"><i class="bi bi-stop-fill"></i> End Timer</button>`;
    } else if (sts === 'Running') {
      fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="openJobDetail(${job.id},'complete')" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete</button>`;
    }
    // No End/Complete in modal for Running unless in complete mode
  }
  if (IS_ADMIN) {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="regenerateJobCard(${job.id})" title="Regenerate with reason"><i class="bi bi-arrow-repeat"></i> Regenerate</button>`;
    fHtml += `<button class="jc-action-btn jc-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  }
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  // Disable form fields unless in complete mode
  setTimeout(() => {
    const form = document.getElementById('dm-operator-form');
    if (form) {
      const formEls = form.querySelectorAll('input, select, textarea');
      formEls.forEach(el => {
        if (mode === 'complete' && sts === 'Running') {
          el.disabled = false;
        } else {
          el.disabled = true;
        }
      });
    }
  }, 50);

  document.getElementById('jcDetailModal').classList.add('active');

  // ─── Load roll change comparison if pending requests exist ───
  if (Number(job.pending_change_requests || 0) > 0) {
    await loadRollChangeComparison(job);
  }
}

const _openJobDetailCore = openJobDetail;
openJobDetail = async function(id, mode) {
  try {
    return await _openJobDetailCore(id, mode);
  } catch (err) {
    const job = ALL_JOBS.find(j => j.id == id) || {};
    const msg = (err && err.message) ? err.message : 'Unknown error';
    try { console.error('openJobDetail failed', err); } catch (e) {}

    try {
      const titleEl = document.getElementById('dm-jobno');
      const badgeEl = document.getElementById('dm-status-badge');
      const bodyEl = document.getElementById('dm-body');
      const footEl = document.getElementById('dm-footer');
      const modalEl = document.getElementById('jcDetailModal');

      if (titleEl) titleEl.textContent = job.job_no || ('Job #' + id);
      if (badgeEl) {
        const sts = String(job.status || 'Pending');
        const stsClass = {Pending:'pending',Running:'running',Closed:'completed',Finalized:'completed'}[sts] || 'pending';
        badgeEl.textContent = sts;
        badgeEl.className = 'jc-badge jc-badge-' + stsClass;
      }

      if (bodyEl) {
        bodyEl.innerHTML = '<div class="jc-op-form">'
          + '<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-exclamation-triangle"></i> Safe Mode</div>'
          + '<div class="jc-op-b">'
          + '<div style="font-size:.8rem;font-weight:800;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:10px;border-radius:8px">'
          + 'Full job card render failed, so a safe fallback view is shown.'
          + '</div>'
          + '<div class="jc-op-grid-2">'
          + '<div class="jc-op-field"><label>Job No</label><div class="fv">' + esc(job.job_no || ('#' + id)) + '</div></div>'
          + '<div class="jc-op-field"><label>Status</label><div class="fv">' + esc(job.status || 'Pending') + '</div></div>'
          + '<div class="jc-op-field"><label>Job Name</label><div class="fv">' + esc(resolveJobDisplayName(job)) + '</div></div>'
          + '<div class="jc-op-field"><label>Roll No</label><div class="fv">' + esc(job.roll_no || '--') + '</div></div>'
          + '</div>'
          + '<div style="font-size:.74rem;color:#991b1b;font-weight:800">Error: ' + esc(msg) + '</div>'
          + '</div></div></div>';
      }

      if (footEl) {
        footEl.innerHTML = '<div></div><div style="display:flex;gap:8px">'
          + '<button class="jc-action-btn jc-btn-view" onclick="closeDetail()"><i class="bi bi-x-lg"></i> Close</button>'
          + (job && job.id ? ('<button class="jc-action-btn jc-btn-print" onclick="printJobCard(' + Number(job.id) + ')"><i class="bi bi-printer"></i> Print</button>') : '')
          + '</div>';
      }

      if (modalEl) modalEl.classList.add('active');
      jumboNotify('Job card opened in safe mode. Full view render failed.', 'warn');
    } catch (fallbackErr) {
      jumboNotify('Job card open failed: ' + msg, 'bad');
    }
  }
};

// ─── Roll Change Comparison Panel ─────────────────────────
async function loadRollChangeComparison(job) {
  const bodyEl = document.getElementById('dm-body');
  const footerEl = document.getElementById('dm-footer');
  // Prepend loading placeholder
  const placeholder = document.createElement('div');
  placeholder.id = 'rc-comparison-panel';
  placeholder.innerHTML = '<div class="rc-panel"><div class="rc-loading"><i class="bi bi-hourglass-split"></i> Loading operator change request...</div></div>';
  bodyEl.insertBefore(placeholder, bodyEl.firstChild);

  try {
    const params = new URLSearchParams({ action: 'list_jumbo_change_requests', csrf_token: CSRF, job_id: job.id, status: 'Pending', limit: '1' });
    const res = await fetch(API_BASE + '?' + params.toString());
    const data = await res.json();
    if (!data.ok || !data.requests || !data.requests.length) {
      placeholder.remove();
      return;
    }
    const req = data.requests[0];
    RC_REQUEST_CACHE[String(req.id || '')] = req;
    const reqPayload = req.payload || {};
    const newRollNo = String(reqPayload.parent_roll_no || '').trim();
    if (!newRollNo) { placeholder.remove(); return; }

    // Fetch new roll details
    const rollParams = new URLSearchParams({ action: 'get_roll_lookup', csrf_token: CSRF, roll_no: newRollNo });
    const rollRes = await fetch(API_BASE + '?' + rollParams.toString());
    const rollData = await rollRes.json();
    const newRoll = (rollData.ok && rollData.roll) ? rollData.roll : {};

    // Current job parent details
    const extra = job.extra_data_parsed || {};
    const p = extra.parent_details || {};
    const curRollNo = String(p.roll_no || extra.parent_roll || job.roll_no || '').trim();
    const liveMap = job.live_roll_map || {};
    const curLive = liveMap[curRollNo] || {};
    const oldStatusRaw = String(p.original_status || curLive.status || 'Main').trim();
    const oldPrevStatus = oldStatusRaw === '' || oldStatusRaw.toLowerCase() === 'slitting' ? 'Main' : oldStatusRaw;

    const rows = Array.isArray(reqPayload.rows) ? reqPayload.rows : [];
    const oldSummary = summarizeRequestRows(rows, curRollNo);
    const newSummary = summarizeRequestRows(rows, newRollNo);

    // Build comparison HTML
    let html = '<div class="rc-panel">';
    html += '<div class="rc-panel-header"><i class="bi bi-arrow-left-right"></i> Operator Roll Change Request';
    html += '<span style="margin-left:auto;font-size:.6rem;font-weight:600;color:#78716c">by ' + esc(req.requested_by_name || 'Operator') + ' &bull; ' + (req.requested_at ? new Date(req.requested_at).toLocaleString() : '') + '</span>';
    html += '</div>';

    html += '<div class="rc-compare">';

    // ── Left: Current Job ──
    html += '<div class="rc-side rc-side-left">';
    html += '<div class="rc-side-title"><i class="bi bi-clipboard-check"></i> Current Job (Planned)</div>';
    html += rcRow('Parent Roll', curRollNo || '--');
    html += rcRow('Company', curLive.company || p.company || job.company || '--');
    html += rcRow('Paper Type', curLive.paper_type || p.paper_type || job.paper_type || '--');
    html += rcRow('Width (mm)', (curLive.width_mm ?? p.width_mm ?? job.width_mm ?? '--') + '');
    html += rcRow('Length (m)', (curLive.length_mtr ?? p.length_mtr ?? job.length_mtr ?? '--') + '');
    html += rcRow('Weight (kg)', (curLive.weight_kg ?? p.weight_kg ?? job.weight_kg ?? '--') + '');
    html += rcRow('GSM', (curLive.gsm ?? p.gsm ?? job.gsm ?? '--') + '');
    html += rcRow('Status', curLive.status || job.roll_status || '--');
    html += '</div>';

    // ── Right: Operator Request ──
    html += '<div class="rc-side rc-side-right">';
    html += '<div class="rc-side-title"><i class="bi bi-person-workspace"></i> Operator Request (New Roll)</div>';
    html += rcRow('Parent Roll', newRollNo);
    html += rcRow('Company', newRoll.company || '--');
    html += rcRow('Paper Type', newRoll.paper_type || '--');
    html += rcRow('Width (mm)', (newRoll.width_mm ?? '--') + '');
    html += rcRow('Length (m)', (newRoll.length_mtr ?? '--') + '');
    html += rcRow('Weight (kg)', (newRoll.weight_kg ?? '--') + '');
    html += rcRow('GSM', (newRoll.gsm ?? '--') + '');
    html += rcRow('Status', newRoll.status || '--');
    html += '</div>';

    html += '</div>'; // .rc-compare

    // Operator remarks box
    const opRemarks = String(reqPayload.operator_remarks || '').trim();
    if (opRemarks) {
      html += '<div class="rc-remarks-box"><strong><i class="bi bi-chat-left-text"></i> Operator Remarks:</strong> ' + esc(opRemarks) + '</div>';
    }

    html += '<div class="rc-summary">';
    html += '<div class="rc-summary-box"><h4>Old Roll Slits (' + esc(curRollNo || '--') + ')</h4>' + oldSummary + '</div>';
    html += '<div class="rc-summary-box"><h4>Suggested Slits (' + esc(newRollNo || '--') + ')</h4>' + newSummary + '</div>';
    html += '</div>';

    // Accept (open edit mode) / Reject buttons
    html += '<div class="rc-footer">';
    html += '<button class="rc-btn-accept" onclick="openAcceptSlittingEditor(' + job.id + ',' + req.id + ',\'' + escJs(curRollNo) + '\',\'' + escJs(oldPrevStatus) + '\',\'' + escJs(newRollNo) + '\')"><i class="bi bi-check-circle"></i> Accept &amp; Open Inventory Slitting</button>';
    html += '<button class="rc-btn-reject" onclick="rejectRollChange(' + job.id + ',' + req.id + ')"><i class="bi bi-x-circle"></i> Reject Request</button>';
    html += '</div>';

    html += '<div id="rc-editor-wrap"></div>';

    html += '</div>'; // .rc-panel
    placeholder.innerHTML = html;

  } catch (err) {
    placeholder.innerHTML = '<div class="rc-panel"><div class="rc-loading" style="color:#dc2626"><i class="bi bi-exclamation-triangle"></i> Failed to load change request: ' + esc(err.message) + '</div></div>';
  }
}

function escJs(v) {
  return String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, '\\n').replace(/\r/g, '');
}

function summarizeRequestRows(rows, parentRollNo) {
  const list = Array.isArray(rows) ? rows : [];
  const parent = String(parentRollNo || '').trim().toLowerCase();
  const filtered = list.filter(r => {
    const pr = String((r && r.parent_roll_no) || '').trim().toLowerCase();
    return parent === '' || pr === '' || pr === parent;
  });
  if (!filtered.length) return '<div class="rc-editor-note">No rows found.</div>';
  const lines = filtered.slice(0, 6).map(r => {
    const roll = String((r && r.roll_no) || '--').trim() || '--';
    const width = Number((r && r.width) || 0);
    const length = Number((r && r.length) || 0);
    const bucket = String((r && r.bucket) || 'child').toLowerCase() === 'stock' ? 'Stock' : 'Child';
    return '<li><strong>' + esc(roll) + '</strong> • ' + esc(bucket) + ' • ' + esc(width + ' x ' + length) + '</li>';
  });
  const more = filtered.length > 6 ? ('<li>+' + (filtered.length - 6) + ' more row(s)</li>') : '';
  return '<ul>' + lines.join('') + more + '</ul>';
}

function openManagerRollUpdateEditor(jobId, requestId, oldParentRoll, oldParentPrevStatus) {
  const wrap = document.getElementById('rc-editor-wrap');
  if (!wrap) return;
  const job = ALL_JOBS.find(j => Number(j.id) === Number(jobId));
  const req = RC_REQUEST_CACHE[String(requestId)] || null;
  if (!job || !req) {
    wrap.innerHTML = '<div class="rc-editor"><div class="rc-editor-body"><div class="rc-editor-note" style="color:#dc2626">Unable to load request state for update.</div></div></div>';
    return;
  }

  const payload = req.payload || {};
  const newParentRoll = String(payload.parent_roll_no || '').trim();
  const allRows = Array.isArray(payload.rows) ? payload.rows : [];
  const oldParent = String(oldParentRoll || '').trim();

  const oldRows = allRows.filter(r => String((r && r.parent_roll_no) || '').trim().toLowerCase() === oldParent.toLowerCase());
  const suggestedRowsRaw = allRows.filter(r => {
    const pr = String((r && r.parent_roll_no) || '').trim().toLowerCase();
    return pr === '' || pr === String(newParentRoll).toLowerCase();
  });
  const suggestedRows = suggestedRowsRaw.length ? suggestedRowsRaw : allRows;

  RC_EDITOR_STATE = {
    jobId: Number(jobId),
    requestId: Number(requestId),
    oldParentRoll: oldParent,
    oldParentPrevStatus: String(oldParentPrevStatus || 'Main'),
    newParentRoll: newParentRoll,
    suggestedSelected: true,
  };

  let html = '<div class="rc-editor">';
  html += '<div class="rc-editor-head"><span><i class="bi bi-sliders"></i> Manager Accept Mode</span><span class="rc-badge"><i class="bi bi-stars"></i> Suggested Roll Selected</span></div>';
  html += '<div class="rc-editor-body">';
  html += '<div class="rc-editor-note">Old parent <strong>' + esc(oldParent || '--') + '</strong> will be restored to previous status <strong>' + esc(RC_EDITOR_STATE.oldParentPrevStatus) + '</strong> after update.</div>';
  html += '<div class="rc-editor-grid">';
  html += '<div class="rc-group rc-group-old"><h5>Auto Slit (Suggested Read-only Snapshot)</h5>' + renderUpdateRowsTable(oldRows, false, 'old') + '</div>';
  html += '<div class="rc-group rc-group-new"><h5>Manual Slit (Manager Editable)</h5>' + renderUpdateRowsTable(suggestedRows, true, 'new') + '</div>';
  html += '</div>';
  html += '<div class="rc-editor-actions">';
  html += '<button class="rc-btn-update" onclick="toggleSuggestedRollSelection()"><i class="bi bi-check2-circle"></i> Use Suggested Roll</button>';
  html += '<button id="rc-btn-apply-update" class="rc-btn-apply" onclick="applyManagerRollUpdate()"><i class="bi bi-save2"></i> Accept &amp; Update</button>';
  html += '</div>';
  html += '</div>';
  html += '</div>';

  wrap.innerHTML = html;
}

function openAcceptSlittingEditor(jobId, requestId, oldParentRoll, oldParentPrevStatus, newParentRoll) {
  const job = ALL_JOBS.find(j => Number(j.id || 0) === Number(jobId || 0)) || null;
  const planningId = Number(job && job.planning_id ? job.planning_id : 0);
  const target = new URL(BASE_URL + '/modules/inventory/slitting/index.php', window.location.origin);
  target.searchParams.set('from', 'jumbo_accept');
  target.searchParams.set('job_id', String(jobId || ''));
  target.searchParams.set('request_id', String(requestId || ''));
  target.searchParams.set('old_parent_roll', String(oldParentRoll || ''));
  target.searchParams.set('old_parent_prev_status', String(oldParentPrevStatus || 'Main'));
  if (planningId > 0) {
    target.searchParams.set('planning_id', String(planningId));
  }
  if (String(newParentRoll || '').trim()) {
    target.searchParams.set('rollNo', String(newParentRoll).trim());
  }
  window.location.href = target.toString();
}

function renderUpdateRowsTable(rows, editable, prefix) {
  const list = Array.isArray(rows) ? rows : [];
  if (!list.length) return '<div class="rc-editor-note" style="padding:8px">No rows available.</div>';
  let html = '<table class="rc-roll-table"><thead><tr><th>Roll</th><th>Bucket</th><th>Width</th><th>Length</th><th>Status</th><th>Remarks</th></tr></thead><tbody>';
  list.forEach((r, idx) => {
    const roll = String((r && r.roll_no) || '').trim();
    const bucket = String((r && r.bucket) || 'child').toLowerCase() === 'stock' ? 'stock' : 'child';
    const width = Number((r && r.width) || 0);
    const length = Number((r && r.length) || 0);
    const status = String((r && r.status) || (bucket === 'stock' ? 'Stock' : 'Job Assign'));
    const remarks = String((r && r.remarks) || '');
    if (editable) {
      html += '<tr>';
      html += '<td><input data-rc-field="roll_no" data-rc-row="' + idx + '" value="' + esc(roll) + '"></td>';
      html += '<td><select data-rc-field="bucket" data-rc-row="' + idx + '"><option value="child"' + (bucket === 'child' ? ' selected' : '') + '>Child</option><option value="stock"' + (bucket === 'stock' ? ' selected' : '') + '>Stock</option></select></td>';
      html += '<td><input type="number" step="0.01" min="0" data-rc-field="width" data-rc-row="' + idx + '" value="' + esc(width + '') + '"></td>';
      html += '<td><input type="number" step="0.01" min="0" data-rc-field="length" data-rc-row="' + idx + '" value="' + esc(length + '') + '"></td>';
      html += '<td><input data-rc-field="status" data-rc-row="' + idx + '" value="' + esc(status) + '"></td>';
      html += '<td><input data-rc-field="remarks" data-rc-row="' + idx + '" value="' + esc(remarks) + '"></td>';
      html += '</tr>';
    } else {
      html += '<tr><td>' + esc(roll || '--') + '</td><td>' + esc(bucket) + '</td><td>' + esc(width + '') + '</td><td>' + esc(length + '') + '</td><td>' + esc(status || '--') + '</td><td>' + esc(remarks || '--') + '</td></tr>';
    }
  });
  html += '</tbody></table>';
  return html;
}

function toggleSuggestedRollSelection() {
  if (!RC_EDITOR_STATE) return;
  RC_EDITOR_STATE.suggestedSelected = true;
  jumboNotify('Suggested roll selected. Old parent roll will be restored to previous status on Update.', 'info');
}

function collectManagerUpdateRows() {
  const rows = [];
  const nodes = document.querySelectorAll('[data-rc-field="roll_no"]');
  nodes.forEach(node => {
    const idx = String(node.getAttribute('data-rc-row') || '').trim();
    if (idx === '') return;
    const q = (field) => document.querySelector('[data-rc-field="' + field + '"][data-rc-row="' + idx + '"]');
    const rollNo = String(q('roll_no')?.value || '').trim();
    if (!rollNo) return;
    const bucket = String(q('bucket')?.value || 'child').trim().toLowerCase() === 'stock' ? 'stock' : 'child';
    const width = Number(q('width')?.value || 0);
    const length = Number(q('length')?.value || 0);
    const status = String(q('status')?.value || (bucket === 'stock' ? 'Stock' : 'Job Assign')).trim();
    const remarks = String(q('remarks')?.value || '').trim();
    rows.push({
      roll_no: rollNo,
      bucket: bucket,
      parent_roll_no: RC_EDITOR_STATE ? RC_EDITOR_STATE.newParentRoll : '',
      width: width,
      length: length,
      status: status,
      remarks: remarks,
      wastage: 0,
    });
  });
  return rows;
}

async function applyManagerRollUpdate() {
  if (!RC_EDITOR_STATE) return;
  if (!RC_EDITOR_STATE.suggestedSelected) {
    jumboNotify('Please select suggested roll before Accept & Update.', 'warn');
    return;
  }
  const rows = collectManagerUpdateRows();
  if (!rows.length) {
    jumboNotify('No suggested rows found to update.', 'warn');
    return;
  }

  const btn = document.getElementById('rc-btn-apply-update');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Updating...'; }

  const note = await jumboPrompt('Optional manager note for this update:', 'Updated in manager embedded mode', 'Manager Note');
  if (note === null) {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2"></i> Update'; }
    return;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'apply_jumbo_manager_roll_update');
  fd.append('job_id', String(RC_EDITOR_STATE.jobId));
  fd.append('request_id', String(RC_EDITOR_STATE.requestId));
  fd.append('old_parent_roll_no', String(RC_EDITOR_STATE.oldParentRoll || ''));
  fd.append('old_parent_prev_status', String(RC_EDITOR_STATE.oldParentPrevStatus || 'Main'));
  fd.append('rows_json', JSON.stringify(rows));
  fd.append('review_note', String(note || ''));

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      jumboNotify('Request accepted and update applied successfully. Same job card/job number kept; selected roll group updated.', 'ok');
      location.reload();
      return;
    }
    jumboNotify('Update failed: ' + (data.error || 'Unknown'), 'bad');
  } catch (err) {
    jumboNotify('Network error: ' + err.message, 'bad');
  }
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-save2"></i> Accept &amp; Update'; }
}

function rcRow(label, value) {
  return '<div class="rc-row"><span class="rc-label">' + esc(label) + '</span><span class="rc-val">' + esc(value) + '</span></div>';
}

// ─── Accept Roll Change (delete & recreate with new roll) ───
async function acceptRollChange(jobId, requestId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  const jobNo = job ? job.job_no : ('JOB-' + jobId);
  if (!(await jumboConfirm('Accept roll change for ' + jobNo + '?\n\nThis will:\n- Delete the current job card and all child rolls\n- Restore the original parent roll\n- Recreate the same job (' + jobNo + ') with the new roll\n- Same JMB & PLN IDs will be preserved', 'Confirm Roll Change'))) return;

  const btn = document.querySelector('.rc-btn-accept');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...'; }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'accept_roll_change');
  fd.append('job_id', jobId);
  fd.append('request_id', requestId);

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      jumboNotify('Roll change accepted. ' + (data.job_no || jobNo) + ' recreated with new parent roll: ' + (data.new_parent_roll || '?') + '.', 'ok');
      location.reload();
    } else if (data.blocked_jobs && data.blocked_jobs.length) {
      const rows = data.blocked_jobs.map(b => (b.job_no || ('ID ' + b.id)) + ' [' + b.status + ']').join('\n');
      jumboNotify((data.error || 'Blocked') + '. Check dependent jobs first.', 'warn');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
    } else {
      jumboNotify('Error: ' + (data.error || 'Unknown'), 'bad');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
    }
  } catch (err) {
    jumboNotify('Network error: ' + err.message, 'bad');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
  }
}

// ─── Reject Roll Change Request ──────────────────────────
async function rejectRollChange(jobId, requestId) {
  const reason = await jumboPrompt('Reject this roll change request?\n\nOptionally enter a reason:', '', 'Reject Request');
  if (reason === null) return; // Cancelled

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'review_jumbo_change_request');
  fd.append('request_id', requestId);
  fd.append('decision', 'Rejected');
  fd.append('review_note', reason || '');

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      jumboNotify('Request rejected.', 'ok');
      location.reload();
    } else {
      jumboNotify('Error: ' + (data.error || 'Unknown'), 'bad');
    }
  } catch (err) {
    jumboNotify('Network error: ' + err.message, 'bad');
  }
}

function closeDetail() {
  document.getElementById('jcDetailModal').classList.remove('active');
}
document.getElementById('jcDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});

// ─── Delete job (admin) ─────────────────────────────────────
async function deleteJob(id) {
  if (!IS_ADMIN) { jumboNotify('Access denied. Only system admin can delete job cards.', 'bad'); return; }
  if (!(await jumboConfirm('Delete this job card and reset linked paper stock, planning status, and downstream queued jobs?', 'Confirm Delete'))) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'delete_job');
  fd.append('job_id', id);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else if (data.blocked_jobs && data.blocked_jobs.length) {
      const rows = data.blocked_jobs.map(b => `${b.job_no || ('ID ' + b.id)} [${b.status}]`).join('\n');
      jumboNotify((data.error || 'Delete blocked') + '. Resolve dependent jobs first.', 'warn');
    }
    else jumboNotify('Error: ' + (data.error || 'Unknown'), 'bad');
  } catch (err) { jumboNotify('Network error: ' + err.message, 'bad'); }
}

// ─── Regenerate same job card (admin) ──────────────────────
async function regenerateJobCard(id) {
  if (!IS_ADMIN) { jumboNotify('Access denied. Only system admin can regenerate job cards.', 'bad'); return; }
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) { jumboNotify('Job not found.', 'bad'); return; }

  const reason = await jumboPrompt('Reason for regeneration (required):', 'Roll correction / planning update', 'Regenerate Job Card');
  if (reason === null) return;
  const reasonText = String(reason || '').trim();
  if (!reasonText) { jumboNotify('Reason is required.', 'warn'); return; }

  const notesAppend = await jumboPrompt('Describe what changed (optional):', '', 'Regeneration Notes');
  if (notesAppend === null) return;

  const currentRoll = String(job.roll_no || '').trim();
  const newRollPrompt = await jumboPrompt('Parent Roll No change (optional). Keep blank to retain current roll:', currentRoll, 'Parent Roll Update');
  if (newRollPrompt === null) return;
  const newRoll = String(newRollPrompt || '').trim();

  let changes = {};
  const form = document.getElementById('dm-operator-form');
  if (form) {
    const payload = buildJumboExtraDataFromForm(form);
    if (payload) changes = payload;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'regenerate_job_card');
  fd.append('job_id', String(id));
  fd.append('reason', reasonText);
  fd.append('notes_append', String(notesAppend || '').trim());
  if (newRoll !== '' && newRoll !== currentRoll) {
    fd.append('roll_no', newRoll);
  }
  fd.append('changes_json', JSON.stringify(changes));

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      jumboNotify('Regenerate failed: ' + (data.error || 'Unknown error'), 'bad');
      return;
    }
    jumboNotify('Job card regenerated successfully. Same job number kept and status reset to Pending.', 'ok');
    location.reload();
  } catch (err) {
    jumboNotify('Network error: ' + err.message, 'bad');
  }
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
  const priRaw = String(job.planning_priority || 'Normal');
  const priKey = priRaw.trim().toLowerCase();
  const priColor = priKey === 'urgent' ? '#dc2626' : '#15803d';
  const printJobName = job.display_job_name || job.planning_job_name || '—';
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

  const childRows = Array.isArray(extra.child_rolls) ? extra.child_rolls : [];
  const stockRows = Array.isArray(extra.stock_rolls) ? extra.stock_rolls : [];
  const allRows = [];
  childRows.forEach(r => allRows.push({ parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '', roll_no: r.roll_no || '', type: r.paper_type || job.paper_type || '', width: (r.width ?? r.width_mm ?? '—'), length: (r.length ?? r.length_mtr ?? '—'), wastage: (r.wastage ?? 0), remarks: r.remarks || '', status: 'Job Assign' }));
  stockRows.forEach(r => allRows.push({ parent_roll_no: r.parent_roll_no || extra.parent_roll || job.roll_no || '', roll_no: r.roll_no || '', type: r.paper_type || job.paper_type || '', width: (r.width ?? r.width_mm ?? '—'), length: (r.length ?? r.length_mtr ?? '—'), wastage: (r.wastage ?? 0), remarks: r.remarks || '', status: 'Stock' }));
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

  const existingPhoto = extra.jumbo_photo_url || '';
  const photoHtml = existingPhoto
    ? `<tr><td colspan="4" style="padding:0"><div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#a16207;background:#fef3c7;padding:6px 8px;border-radius:4px;margin:8px 0 4px">Job Photo</div><div style="margin-bottom:6px"><img src="${esc(existingPhoto)}" style="max-width:300px;max-height:180px;border-radius:8px;border:1px solid #e2e8f0"></div></td></tr>`
    : '';

  return `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:20px;max-width:760px;margin:0 auto;color:#0f172a">
    <div style="border:2px solid #166534;border-radius:12px;overflow:hidden">
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
      <div style="padding:8px 14px;background:#dcfce7;border-bottom:1px solid #bbf7d0;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:1rem;font-weight:900;color:#14532d;line-height:1.25">Job: ${esc(printJobName)}</div>
        <div style="font-size:.68rem;font-weight:700;color:${priColor}">Priority: ${esc(priRaw)}</div>
      </div>
      <div style="padding:10px 12px;border-bottom:1px solid #cbd5e1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;font-size:.66rem">
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Status</div><div style="font-weight:800">${esc(job.status || '—')}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Started</div><div style="font-weight:700">${esc(started)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Completed</div><div style="font-weight:700">${esc(completed)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800;font-size:.58rem">Duration</div><div style="font-weight:700">${esc(durText)}</div></div>
      </div>
      <div style="padding:10px 12px">
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#166534;background:#dcfce7;padding:5px 8px;border-radius:4px">Job Details</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;margin-bottom:10px">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-size:.82rem;font-weight:900;color:#0f172a">${esc(printJobName)}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job No</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-weight:700;color:#166534">${esc(job.job_no || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Roll No</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.roll_no || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Material</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.paper_type || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Width</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.width_mm || '—') + ' mm')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Length</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.length_mtr || '—') + ' m')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">GSM</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(job.gsm || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Weight</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((job.weight_kg || '—') + ' kg')}</td></tr>
        </table>
        ${parentTableHtml ? `<table style="width:100%">${parentTableHtml}</table>` : ''}
        ${childTableHtml ? `<table style="width:100%">${childTableHtml}</table>` : ''}
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#166534;background:#dcfce7;padding:5px 8px;border-radius:4px;margin-top:8px">Execution Summary</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Wastage (kg)</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.wastage_kg || extra.operator_wastage_kg || '—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Operator</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.operator_name || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Remarks</td><td colspan="3" style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.operator_notes || extra.operator_remarks || '—')}</td></tr>
        </table>
        ${photoHtml ? `<table style="width:100%">${photoHtml}</table>` : ''}
      </div>
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
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  const html = renderJumboPrintCardHtml(job, qrDataUrl);
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${html}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

// ─── Multi-Select Bulk Print ────────────────────────────────
function jcUpdateBulkBar() {
  const checked = document.querySelectorAll('.jc-select-check:checked');
  const bar = document.getElementById('jcBulkBar');
  const countEl = document.getElementById('jcSelectedCount');
  
  checked.forEach(cb => {
    const card = cb.closest('.jc-card');
    if (card) card.classList.toggle('jc-selected', cb.checked);
  });
  document.querySelectorAll('.jc-select-check:not(:checked)').forEach(cb => {
    const card = cb.closest('.jc-card');
    if (card) card.classList.remove('jc-selected');
  });
  
  if (checked.length > 0) {
    bar.style.display = 'flex';
    countEl.textContent = checked.length;
  } else {
    bar.style.display = 'none';
  }
}

function jcSelectAll() {
  document.querySelectorAll('.jc-card:not([style*="display: none"]):not([style*="display:none"]) .jc-select-check').forEach(cb => cb.checked = true);
  jcUpdateBulkBar();
}

function jcDeselectAll() {
  document.querySelectorAll('.jc-select-check').forEach(cb => cb.checked = false);
  jcUpdateBulkBar();
}

function jcBulkPrint() {
  const checkedIds = Array.from(document.querySelectorAll('.jc-select-check:checked')).map(cb => cb.dataset.jobId);
  if (!checkedIds.length) { jumboNotify('No job cards selected', 'warn'); return; }
  const jobs = checkedIds.map(id => ALL_JOBS.find(j => j.id == id)).filter(Boolean);
  if (!jobs.length) { jumboNotify('Could not find selected jobs', 'bad'); return; }

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
    setTimeout(() => w.print(), 400);
  })();
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
    jumboNotify('No rolls found for label printing.', 'warn');
    return;
  }

  try {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.set('action', 'get_roll_ids');
    url.searchParams.set('roll_nos', uniqueRollNos.join(','));
    const res = await fetch(url.toString());
    const data = await res.json();
    if (!data.ok) {
      jumboNotify('Error: ' + (data.error || 'Unable to resolve roll IDs'), 'bad');
      return;
    }

    const ids = Array.isArray(data.ids) ? data.ids.filter(Boolean) : [];
    if (!ids.length) {
      jumboNotify('No matching paper stock records found for labels.', 'warn');
      return;
    }

    const labelUrl = `${BASE_URL}/modules/paper_stock/label.php?ids=${ids.join(',')}`;
    window.open(labelUrl, '_blank');
  } catch (err) {
    jumboNotify('Network error: ' + (err.message || 'Unknown error'), 'bad');
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
})();
(function(){
  const qs = new URLSearchParams(window.location.search);
  if (qs.get('accepted_done') !== '1') return;
  const msg = 'Accepted done. Paper roll update applied successfully.';
  jumboNotify(msg, 'ok');
})();
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
// Default to Pending filter on page load
(function(){
  const pendingBtn = document.querySelector('.jc-filter-btn.active');
  if (pendingBtn && pendingBtn.textContent.trim() === 'Pending') filterJobs('Pending', pendingBtn);
})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
