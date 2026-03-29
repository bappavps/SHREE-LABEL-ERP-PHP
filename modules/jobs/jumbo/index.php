<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isOperatorView = (string)($_GET['view'] ?? '') === 'operator';
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
$jobsSql = "\n  SELECT j.*,\n         ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,\n         ps.status AS roll_status, ps.lot_batch_no,\n         p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,\n         COALESCE(req.pending_count, 0) AS pending_change_requests\n  FROM jobs j\n  LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no\n  LEFT JOIN planning p ON j.planning_id = p.id\n  LEFT JOIN (\n    SELECT job_id, COUNT(*) AS pending_count\n    FROM job_change_requests\n    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'\n    GROUP BY job_id\n  ) req ON req.job_id = j.id\n  WHERE j.job_type = 'Slitting'\n    AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')\n  ORDER BY j.created_at DESC, j.id DESC\n";
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
.jc-badge-normal{background:#e0f2fe;color:#075985}
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.6}}
.jc-action-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.jc-btn-complete{background:var(--jc-blue);color:#fff}
.jc-btn-complete:hover{background:#2563eb}
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
.jc-op-topstrip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}
.jc-op-topitem{display:grid;gap:3px}
.jc-op-topitem .k{font-size:.58rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.jc-op-topitem .v{font-size:.76rem;font-weight:800;color:#0f172a}
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
.rc-btn-reject{padding:8px 16px;border-radius:8px;border:1px solid #fecaca;background:#fee2e2;color:#dc2626;font-weight:800;font-size:.78rem;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.rc-btn-reject:hover{background:#fecaca}
.rc-loading{text-align:center;padding:20px;color:#92400e;font-size:.8rem;font-weight:600}

/* Print */
@media print{
  .no-print,.breadcrumb,.page-header,.jc-modal-overlay{display:none!important}
  .jc-print-area{display:block!important}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
.jc-print-area{display:none}
@media(max-width:600px){.jc-grid{grid-template-columns:1fr}.jc-stats{grid-template-columns:repeat(2,1fr)}.jc-detail-grid{grid-template-columns:1fr}.jc-form-row{grid-template-columns:1fr}}
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
  <button id="jcTabBtnDeletedLogs" class="jc-tab-btn" type="button" onclick="switchJumboTab('deleted_logs')"><i class="bi bi-trash3"></i> Deleted Logs</button>

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
    $stsClass = match($sts) { 'Pending'=>'pending', 'Closed','Finalized'=>'completed', default=>'pending' };
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
        <?php if ($pri !== 'Normal'): ?>
          <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
        <?php if ($hasPendingRequest): ?>
          <span class="jc-request-state">Request Pending</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['display_job_name'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Roll No</span><span class="jc-value" style="color:var(--jc-brand)"><?= e($job['roll_no'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Material</span><span class="jc-value"><?= e($job['paper_type'] ?? '—') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Dimension</span><span class="jc-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Started</span><span class="jc-value"><?= e($startedAt) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Ended</span><span class="jc-value"><?= e($completedAt) ?></span></div>
      <div class="jc-card-row"><span class="jc-label">Plan Flow</span><span class="jc-value">Pending</span></div>
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
        <?php if ($pri !== 'Normal'): ?>
          <span class="jc-badge jc-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="jc-card-body">
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['display_job_name'] ?? '—') ?></span></div>
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
        <td><?= e($h['display_job_name'] ?? '—') ?></td>
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

<div id="jcPanelDeletedLogs" style="display:none">
<div class="card no-print" style="margin-top:18px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <span class="card-title"><i class="bi bi-trash3"></i> Deleted Job Card Log</span>
    <div style="display:flex;gap:8px;align-items:center">
      <select id="dalFilter" class="form-control" style="height:32px;min-width:160px;font-size:.78rem" onchange="loadDeleteAudit()">
        <option value="">All Actions</option>
        <option value="completed">Completed Deletes</option>
        <option value="blocked">Blocked Attempts</option>
      </select>
      <button class="jc-action-btn jc-btn-view" onclick="loadDeleteAudit()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>
  </div>
  <div id="dalTableWrap" style="overflow:auto;padding:8px 0">
    <div style="padding:24px;color:#94a3b8;text-align:center;font-size:.82rem">Switch to this tab to load deletion logs.</div>
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
const JC_AUTO_REFRESH_MS = 45000;

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
    deleted_logs: document.getElementById('jcPanelDeletedLogs'),
  };
  const btns = {
    active:       document.getElementById('jcTabBtnActive'),
    history:      document.getElementById('jcTabBtnHistory'),
    deleted_logs: document.getElementById('jcTabBtnDeletedLogs'),
  };
  Object.keys(panels).forEach(function(k) {
    if (panels[k]) panels[k].style.display = (k === tab) ? '' : 'none';
    if (btns[k])   btns[k].classList.toggle('active', k === tab);
  });
  if (tab === 'history') htGoPage(1);
  if (tab === 'deleted_logs') loadDeleteAudit();
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
  if (!ids.length) { alert('No history job selected'); return; }

  const jobs = ids.map(function(id) { return ALL_JOBS.find(function(j) { return j.id == id; }); }).filter(Boolean);
  if (!jobs.length) return;

  const nowText = new Date().toLocaleString();
  let pages = '';

  jobs.forEach(function(job, idx) {
    const extra = job.extra_data_parsed || {};
    const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
    const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
    pages += `
      <div class="print-page" ${idx > 0 ? 'style="page-break-before:always"' : ''}>
        <div class="p-header">
          <div class="p-brand">
            ${COMPANY.logo ? '<img src="' + COMPANY.logo + '" style="max-height:36px;max-width:100px">' : ''}
            <div>
              <div class="p-company">${esc(COMPANY.name || 'Company')}</div>
              <div class="p-meta">${esc(COMPANY.address || '')}</div>
              ${COMPANY.gst ? '<div class="p-meta">GST: ' + esc(COMPANY.gst) + '</div>' : ''}
            </div>
          </div>
          <div style="text-align:right">
            <div class="p-title">Jumbo History Job Card</div>
            <div class="p-jobno">${esc(job.job_no || '—')}</div>
            <div class="p-meta">Printed: ${esc(nowText)}</div>
          </div>
        </div>
        <table class="p-table">
          <tr><th>Job Name</th><td>${esc(job.planning_job_name || job.display_job_name || '—')}</td><th>Status</th><td>${esc(job.status || '—')}</td></tr>
          <tr><th>Roll No</th><td>${esc(job.roll_no || '—')}</td><th>Material</th><td>${esc(job.paper_type || '—')}</td></tr>
          <tr><th>Width</th><td>${esc((job.width_mm || '—') + ' mm')}</td><th>Length</th><td>${esc((job.length_mtr || '—') + ' m')}</td></tr>
          <tr><th>GSM</th><td>${esc(job.gsm || '—')}</td><th>Weight</th><td>${esc((job.weight_kg || '—') + ' kg')}</td></tr>
          <tr><th>Started</th><td>${esc(startedAt)}</td><th>Completed</th><td>${esc(completedAt)}</td></tr>
          <tr><th>Wastage</th><td>${esc(extra.wastage_kg || extra.operator_wastage_kg || '—')} kg</td><th>Notes</th><td>${esc(extra.operator_notes || extra.operator_remarks || '—')}</td></tr>
        </table>
        <div class="p-footer">
          <span>${esc(APP_FOOTER_LEFT || '')}</span>
          <span>Page ${idx + 1} of ${jobs.length}</span>
          <span>${esc(APP_FOOTER_RIGHT || '')}</span>
        </div>
      </div>`;
  });

  const w = window.open('', '_blank', 'width=800,height=900');
  w.document.write('<!DOCTYPE html><html><head><title>History Bulk Print - ' + jobs.length + ' Job Cards</title><style>' +
    '@page{margin:10mm}' +
    '*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' +
    'body{font-family:"Segoe UI",Arial,sans-serif;color:#1f2937;margin:0;padding:0}' +
    '.print-page{padding:8px}' +
    '.p-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e40af;padding-bottom:10px;margin-bottom:14px}' +
    '.p-brand{display:flex;gap:10px;align-items:flex-start}' +
    '.p-company{font-size:1rem;font-weight:800;color:#0f172a}' +
    '.p-title{font-size:.8rem;font-weight:800;text-transform:uppercase;color:#334155}' +
    '.p-jobno{font-size:1.1rem;font-weight:900;color:#16a34a}' +
    '.p-meta{font-size:.65rem;color:#64748b}' +
    '.p-table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:.8rem}' +
    '.p-table th{background:#f8fafc;padding:8px 10px;border:1px solid #e2e8f0;font-weight:700;color:#334155;white-space:nowrap;width:15%}' +
    '.p-table td{padding:8px 10px;border:1px solid #e2e8f0;width:35%}' +
    '.p-footer{display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:6px;font-size:.6rem;color:#94a3b8}' +
  '</style></head><body>' + pages + '</body></html>');
  w.document.close();
  w.focus();
  setTimeout(function() { w.print(); }, 500);
}

document.getElementById('htSearch')?.addEventListener('input', function() {
  htGoPage(1);
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
  if (!confirm('Set this job to ' + newStatus + '?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ─── Timer overlay (Flexo-style) ────────────────────────────
let _timerInterval = null;
let _timerStart = 0;
let _timerJobId = null;

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

  _timerJobId = id;
  _timerStart = Date.now();
  const job = ALL_JOBS.find(j => j.id == id) || {};
  job.status = 'Running';
  job.started_at = new Date().toISOString();
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
  const ov = document.getElementById('jcTimerOverlay');
  if (ov) ov.remove();
  const jobId = _timerJobId;
  _timerJobId = null;
  // Open detail in complete mode (no camera — user can upload anytime from the detail)
  openJobDetail(jobId, 'complete');
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

// ─── Submit operator extra data + close ─────────────────────
async function submitAndClose(id) {
  // Gather form values
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateJobStatus(id, 'Closed');

  const extraData = {
    actual_output_weight: form.querySelector('[name=actual_output_weight]')?.value || '',
    wastage_kg: form.querySelector('[name=wastage_kg]')?.value || '',
    roll_condition: form.querySelector('[name=roll_condition]')?.value || 'Good',
    operator_notes: form.querySelector('[name=operator_notes]')?.value || '',
    defects: Array.from(form.querySelectorAll('[name=defects]:checked')).map(c=>c.value)
  };

  // Save extra data
  const fd1 = new FormData();
  fd1.append('csrf_token', CSRF);
  fd1.append('action', 'submit_extra_data');
  fd1.append('job_id', id);
  fd1.append('extra_data', JSON.stringify(extraData));
  try {
    const r1 = await fetch(API_BASE, { method: 'POST', body: fd1 });
    const d1 = await r1.json();
    if (!d1.ok) { alert('Save error: ' + (d1.error||'Unknown')); return; }
  } catch(e) { alert('Network error'); return; }

  // Now close
  await updateJobStatus(id, 'Closed');
}

// ─── Detail modal ───────────────────────────────────────────
async function openJobDetail(id, mode) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;

  const sts = job.status;
  const stsClass = {Pending:'pending',Closed:'completed',Finalized:'completed'}[sts]||'pending';
  const extra = job.extra_data_parsed || {};
  const createdAt = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;
  const startedTs = job.started_at ? new Date(job.started_at).getTime() : 0;

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
  if (sts === 'Running' && startedTs) {
    html += `<div class="jc-op-topitem"><span class="k">Elapsed</span><span class="v jc-timer" data-started="${startedTs}" style="color:var(--jc-blue);font-size:.9rem">00:00:00</span></div>`;
  } else if (dur !== null && dur !== undefined) {
    html += `<div class="jc-op-topitem"><span class="k">Duration</span><span class="v" style="color:#16a34a">${Math.floor(dur/60)}h ${dur%60}m</span></div>`;
  }
  html += `</div></div></div>`;

  // ── Notes ──
  if (job.notes) {
    html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-sticky"></i> Notes</div><div class="jc-op-b"><div style="font-size:.82rem;color:#475569;line-height:1.5;background:#f8fafc;padding:12px;border-radius:8px">${esc(job.notes)}</div></div></div>`;
  }

  // ── Job Information (no Department, no Planning Status) ──
  html += `<div class="jc-op-section"><div class="jc-op-h"><i class="bi bi-info-circle"></i> Job Information</div><div class="jc-op-b jc-op-grid-2">
    <div class="jc-op-field"><label>Job No</label><div class="fv" style="color:var(--jc-brand)">${esc(job.job_no)}</div></div>
    <div class="jc-op-field"><label>Job Name</label><div class="fv">${esc(resolveJobDisplayName(job))}</div></div>
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

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += '<select id="dm-print-template" class="form-control" style="height:32px;min-width:140px"><option value="executive">Executive</option><option value="compact">Compact</option></select>';
  fHtml += `<button class="jc-action-btn jc-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Job Card Print</button>`;
  fHtml += `<button class="jc-action-btn jc-btn-view" onclick="printLabelsForJob(${job.id})"><i class="bi bi-upc-scan"></i> Label Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (mode === 'complete' && IS_OPERATOR_VIEW) {
    fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="submitAndClose(${job.id})" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  } else if (sts === 'Pending' && IS_OPERATOR_VIEW) {
    fHtml += `<button class="jc-action-btn jc-btn-start" onclick="startJobWithTimer(${job.id})" style="background:var(--jc-brand);color:#fff;border-color:var(--jc-brand)"><i class="bi bi-play-fill"></i> Start Job</button>`;
  } else if (sts === 'Running' && IS_OPERATOR_VIEW) {
    fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="submitAndClose(${job.id})" style="background:#16a34a;color:#fff;border-color:#16a34a"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  }
  if (!IS_OPERATOR_VIEW) {
    fHtml += `<button class="jc-action-btn jc-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  }
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  document.getElementById('jcDetailModal').classList.add('active');

  // ─── Load roll change comparison if pending requests exist ───
  if (Number(job.pending_change_requests || 0) > 0) {
    await loadRollChangeComparison(job);
  }
}

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

    // Accept / Reject buttons
    html += '<div class="rc-footer">';
    html += '<button class="rc-btn-accept" onclick="acceptRollChange(' + job.id + ',' + req.id + ')"><i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll</button>';
    html += '<button class="rc-btn-reject" onclick="rejectRollChange(' + job.id + ',' + req.id + ')"><i class="bi bi-x-circle"></i> Reject Request</button>';
    html += '</div>';

    html += '</div>'; // .rc-panel
    placeholder.innerHTML = html;

  } catch (err) {
    placeholder.innerHTML = '<div class="rc-panel"><div class="rc-loading" style="color:#dc2626"><i class="bi bi-exclamation-triangle"></i> Failed to load change request: ' + esc(err.message) + '</div></div>';
  }
}

function rcRow(label, value) {
  return '<div class="rc-row"><span class="rc-label">' + esc(label) + '</span><span class="rc-val">' + esc(value) + '</span></div>';
}

// ─── Accept Roll Change (delete & recreate with new roll) ───
async function acceptRollChange(jobId, requestId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  const jobNo = job ? job.job_no : ('JOB-' + jobId);
  if (!confirm('Accept roll change for ' + jobNo + '?\n\nThis will:\n• Delete the current job card and all child rolls\n• Restore the original parent roll\n• Recreate the same job (' + jobNo + ') with the new roll\n• Same JMB & PLN IDs will be preserved')) return;

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
      alert('Roll change accepted!\n\n' + (data.job_no || jobNo) + ' recreated with new parent roll: ' + (data.new_parent_roll || '?') + '\n\nOld roll (' + (data.old_parent_roll || '?') + ') has been restored.');
      location.reload();
    } else if (data.blocked_jobs && data.blocked_jobs.length) {
      const rows = data.blocked_jobs.map(b => (b.job_no || ('ID ' + b.id)) + ' [' + b.status + ']').join('\n');
      alert((data.error || 'Blocked') + '\n\n' + rows);
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
    } else {
      alert('Error: ' + (data.error || 'Unknown'));
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
    }
  } catch (err) {
    alert('Network error: ' + err.message);
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Accept: Delete &amp; Recreate with New Roll'; }
  }
}

// ─── Reject Roll Change Request ──────────────────────────
async function rejectRollChange(jobId, requestId) {
  const reason = prompt('Reject this roll change request?\n\nOptionally enter a reason:');
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
      alert('Request rejected.');
      location.reload();
    } else {
      alert('Error: ' + (data.error || 'Unknown'));
    }
  } catch (err) {
    alert('Network error: ' + err.message);
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
  if (!confirm('Delete this job card and reset linked paper stock, planning status, and downstream queued jobs?')) return;
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
      alert((data.error || 'Delete blocked') + '\n\n' + rows);
    }
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// --- Delete Audit Log ---------------------------------------------------
async function loadDeleteAudit() {
  const wrap = document.getElementById('dalTableWrap');
  if (!wrap) return;
  const filter = (document.getElementById('dalFilter') || {}).value || '';
  wrap.innerHTML = '<p style="padding:12px;color:#64748b">Loading...</p>';
  try {
    const params = new URLSearchParams({ action: 'get_delete_audit', csrf_token: CSRF, limit: '200' });
    if (filter) params.set('status', filter);
    const res = await fetch(API_BASE + '?' + params.toString());
    const data = await res.json();
    const rows = Array.isArray(data.records) ? data.records : (Array.isArray(data.entries) ? data.entries : []);
    if (!data.ok || !rows.length) {
      wrap.innerHTML = '<p style="padding:12px;color:#64748b">' + esc(data.error || 'No deleted job records found.') + '</p>';
      return;
    }
    let tbl = '<table style="width:100%;border-collapse:collapse;font-size:.73rem"><tr style="background:#f3f4f6"><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Date/Time</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Job No</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Parent Roll</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Result</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Rolls Removed</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Stock Restored</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db">Deleted By</th></tr>';
    rows.forEach(function(e) {
      const actionStatus = String(e.action_status || e.status || '').toLowerCase();
      const resultBadge = (actionStatus === 'completed' || actionStatus === 'deleted')
        ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:.7rem">Deleted</span>'
        : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:.7rem">Blocked</span>';
      const stockOk = Number(e.parent_restored || 0) > 0
        ? '<span style="color:#16a34a">&#10003;</span>'
        : '<span style="color:#dc2626">&#10007;</span>';
      tbl += '<tr>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb;white-space:nowrap">' + esc(e.created_at || e.deleted_at || '') + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb;font-weight:700;color:var(--jc-brand)">' + esc(e.root_job_no || e.job_no || ('ID ' + (e.root_job_id || e.job_id || ''))) + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb">' + esc(e.parent_roll_no || e.parent_roll || '--') + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb">' + resultBadge + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb">' + esc(((e.removed_child_rolls ?? e.rolls_removed) ?? '--') + '') + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb;text-align:center">' + stockOk + '</td>'
        + '<td style="padding:8px;border-bottom:1px solid #e5e7eb">' + esc(e.requested_by_name || e.deleted_by || '--') + '</td>'
        + '</tr>';
    });
    tbl += '</table>';
    wrap.innerHTML = tbl;
  } catch (err) {
    wrap.innerHTML = '<p style="padding:12px;color:#dc2626">Error: ' + esc(err.message) + '</p>';
  }
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
  tmpBody.querySelectorAll('.jc-modal-only').forEach(function(el) { el.remove(); });
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
    .jc-op-form{display:grid;gap:12px}
    .jc-op-section{border:1px solid #d1e7dd;border-radius:10px;background:#fff;overflow:hidden;display:flex;flex-direction:column}
    .jc-op-h{padding:9px 11px;border-bottom:1px solid #86efac;background:#f0fdf4;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#166534}
    .jc-op-b{padding:10px;display:grid;gap:8px}
    .jc-op-grid-2{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr))}
    .jc-op-grid-3{display:grid;gap:8px;grid-template-columns:repeat(3,minmax(0,1fr))}
    .jc-op-grid-4{display:grid;gap:8px;grid-template-columns:repeat(4,minmax(0,1fr))}
    .jc-op-field{display:grid;gap:3px}
    .jc-op-field label{font-size:.58rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
    .jc-op-field .fv{padding:6px 8px;border:1px solid #d1e7dd;border-radius:6px;font-size:.78rem;font-weight:600;background:#fcfdff;min-height:18px}
    .jc-op-topstrip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}
    .jc-op-topitem{display:grid;gap:3px}
    .jc-op-topitem .k{font-size:.55rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
    .jc-op-topitem .v{font-size:.74rem;font-weight:800;color:#0f172a}
    .jc-op-roll-table{width:100%;border-collapse:collapse;font-size:.72rem}
    .jc-op-roll-table th,.jc-op-roll-table td{border:1px solid #d1e7dd;padding:6px 7px;text-align:left;vertical-align:middle}
    .jc-op-roll-table th{background:#f0fdf4;font-size:.6rem;text-transform:uppercase;letter-spacing:.04em;color:#166534}
    .jc-detail-section{margin-bottom:16px}
    .jc-detail-section h3{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:0 0 8px}
    .jc-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px}
    .jc-detail-item .dl{font-size:.62rem;color:#94a3b8;font-weight:700;text-transform:uppercase}
    .jc-detail-item .dv{font-size:.82rem;color:#0f172a;font-weight:700}
    .jc-timeline{display:flex;gap:16px;flex-wrap:wrap}
    .template-compact .jc-op-grid-2,.template-compact .jc-op-grid-3,.template-compact .jc-op-grid-4{grid-template-columns:1fr 1fr}
    .template-compact .jc-op-topstrip{grid-template-columns:repeat(2,minmax(0,1fr))}
    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #dbe3ea;padding:7px 8px;text-align:left}
    th{background:#f8fafc}
  </style></head><body>${html}</body></html>`);
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
  if (!checkedIds.length) { alert('No job cards selected'); return; }
  
  const jobs = checkedIds.map(id => ALL_JOBS.find(j => j.id == id)).filter(Boolean);
  if (!jobs.length) { alert('Could not find selected jobs'); return; }
  
  const nowText = new Date().toLocaleString();
  let pages = '';
  
  jobs.forEach((job, idx) => {
    const extra = job.extra_data_parsed || {};
    const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
    const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
    
    pages += `
      <div class="print-page" ${idx > 0 ? 'style="page-break-before:always"' : ''}>
        <div class="p-header">
          <div class="p-brand">
            ${COMPANY.logo ? '<img src="' + COMPANY.logo + '" style="max-height:36px;max-width:100px">' : ''}
            <div>
              <div class="p-company">${esc(COMPANY.name || 'Company')}</div>
              <div class="p-meta">${esc(COMPANY.address || '')}</div>
              ${COMPANY.gst ? '<div class="p-meta">GST: ' + esc(COMPANY.gst) + '</div>' : ''}
            </div>
          </div>
          <div style="text-align:right">
            <div class="p-title">Jumbo Slitting Job Card</div>
            <div class="p-jobno">${esc(job.job_no)}</div>
            <div class="p-meta">Printed: ${esc(nowText)}</div>
          </div>
        </div>
        <table class="p-table">
          <tr><th>Job Name</th><td>${esc(job.planning_job_name || job.display_job_name || '—')}</td><th>Status</th><td>${esc(job.status)}</td></tr>
          <tr><th>Roll No</th><td>${esc(job.roll_no || '—')}</td><th>Material</th><td>${esc(job.paper_type || '—')}</td></tr>
          <tr><th>Width</th><td>${esc((job.width_mm || '—') + ' mm')}</td><th>Length</th><td>${esc((job.length_mtr || '—') + ' m')}</td></tr>
          <tr><th>GSM</th><td>${esc(job.gsm || '—')}</td><th>Weight</th><td>${esc((job.weight_kg || '—') + ' kg')}</td></tr>
          <tr><th>Started</th><td>${esc(startedAt)}</td><th>Completed</th><td>${esc(completedAt)}</td></tr>
          <tr><th>Wastage</th><td>${esc(extra.wastage_kg || extra.operator_wastage_kg || '—')} kg</td><th>Notes</th><td>${esc(extra.operator_notes || extra.operator_remarks || '—')}</td></tr>
        </table>
        <div class="p-footer">
          <span>${esc(APP_FOOTER_LEFT || '')}</span>
          <span>Page ${idx + 1} of ${jobs.length}</span>
          <span>${esc(APP_FOOTER_RIGHT || '')}</span>
        </div>
      </div>`;
  });
  
  const w = window.open('', '_blank', 'width=800,height=900');
  w.document.write('<!DOCTYPE html><html><head><title>Bulk Print - ' + jobs.length + ' Job Cards</title><style>' +
    '@page{margin:10mm}' +
    '*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}' +
    'body{font-family:"Segoe UI",Arial,sans-serif;color:#1f2937;margin:0;padding:0}' +
    '.print-page{padding:8px}' +
    '.p-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #1e40af;padding-bottom:10px;margin-bottom:14px}' +
    '.p-brand{display:flex;gap:10px;align-items:flex-start}' +
    '.p-company{font-size:1rem;font-weight:800;color:#0f172a}' +
    '.p-title{font-size:.8rem;font-weight:800;text-transform:uppercase;color:#334155}' +
    '.p-jobno{font-size:1.1rem;font-weight:900;color:#16a34a}' +
    '.p-meta{font-size:.65rem;color:#64748b}' +
    '.p-table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:.8rem}' +
    '.p-table th{background:#f8fafc;padding:8px 10px;border:1px solid #e2e8f0;font-weight:700;color:#334155;white-space:nowrap;width:15%}' +
    '.p-table td{padding:8px 10px;border:1px solid #e2e8f0;width:35%}' +
    '.p-footer{display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;padding-top:6px;font-size:.6rem;color:#94a3b8}' +
  '</style></head><body>' + pages + '</body></html>');
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 500);
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
})();
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
