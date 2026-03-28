<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle = 'Jumbo Job Cards';
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

$jobsStmt = $db->prepare("\n  SELECT j.*,\n         ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,\n         ps.status AS roll_status, ps.lot_batch_no,\n         p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,\n         COALESCE(req.pending_count, 0) AS pending_change_requests\n  FROM jobs j\n  LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no\n  LEFT JOIN planning p ON j.planning_id = p.id\n  LEFT JOIN (\n    SELECT job_id, COUNT(*) AS pending_count\n    FROM job_change_requests\n    WHERE request_type = 'jumbo_roll_update' AND status = 'Pending'\n    GROUP BY job_id\n  ) req ON req.job_id = j.id\n  WHERE j.job_type = 'Slitting'\n    AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')\n  ORDER BY j.created_at DESC, j.id DESC\n");
$jobsStmt->execute();
$allJumboRows = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($allJumboRows as $row) {
  $row['extra_data_parsed'] = json_decode((string)($row['extra_data'] ?? '{}'), true) ?: [];
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
    $stsClass = match($sts) { 'Pending'=>'pending', 'Closed','Finalized'=>'completed', default=>'pending' };
    $rSts = $job['roll_status'] ?? '';
    $rStsClass = strtolower(str_replace(' ', '', $rSts)) === 'slitting' ? 'slitting' : $stsClass;
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $hasPendingRequest = (int)($job['pending_change_requests'] ?? 0) > 0;
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedAt = $job['started_at'] ? date('d M Y, H:i', strtotime($job['started_at'])) : '—';
    $completedAt = $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : '—';
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
  ?>
  <div class="jc-card" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openJobDetail(<?= $job['id'] ?>)">
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
      <?php if ($job['planning_job_name']): ?>
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
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
      <?php if ($job['planning_job_name']): ?>
      <div class="jc-card-row"><span class="jc-label">Job Name</span><span class="jc-value"><?= e($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
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

<!-- ═══ PRINT AREA (hidden, used for browser print) ═══ -->
<div class="jc-print-area" id="jcPrintArea"></div>

<script>
const CSRF = '<?= e($csrf) ?>';
const BASE_URL = '<?= BASE_URL ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const APP_FOOTER_LEFT = <?= json_encode($appFooterLeft, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const APP_FOOTER_RIGHT = <?= json_encode($appFooterRight, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode(array_values(array_merge($activeJobs, $historyJobs)), JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const JC_AUTO_REFRESH_MS = 45000;

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
function openJobDetail(id, mode) {
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

  // Job Info
  html += `<div class="jc-detail-section"><h3><i class="bi bi-info-circle"></i> Job Information</h3><div class="jc-detail-grid">
    <div class="jc-detail-item"><span class="dl">Job No</span><span class="dv" style="color:var(--jc-brand)">${esc(job.job_no)}</span></div>
    <div class="jc-detail-item"><span class="dl">Job Name</span><span class="dv">${esc(job.planning_job_name||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Department</span><span class="dv">Jumbo Slitting</span></div>
    <div class="jc-detail-item"><span class="dl">Priority</span><span class="dv">${esc(job.planning_priority||'Normal')}</span></div>
    <div class="jc-detail-item"><span class="dl">Planning Status</span><span class="dv">${esc(job.planning_status||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Sequence</span><span class="dv">#${job.sequence_order||1}</span></div>
  </div></div>`;

  // Material Info
  html += `<div class="jc-detail-section"><h3><i class="bi bi-box"></i> Material Information</h3><div class="jc-detail-grid">
    <div class="jc-detail-item"><span class="dl">Roll No</span><span class="dv" style="color:var(--jc-brand)">${esc(job.roll_no||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Paper Type</span><span class="dv">${esc(job.paper_type||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">GSM</span><span class="dv">${esc(job.gsm||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Width × Length</span><span class="dv">${esc((job.width_mm||'—')+'mm × '+(job.length_mtr||'—')+'m')}</span></div>
    <div class="jc-detail-item"><span class="dl">Weight</span><span class="dv">${job.weight_kg ? job.weight_kg+' kg' : '—'}</span></div>
    <div class="jc-detail-item"><span class="dl">Supplier</span><span class="dv">${esc(job.company||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Lot/Batch</span><span class="dv">${esc(job.lot_batch_no||'—')}</span></div>
    <div class="jc-detail-item"><span class="dl">Roll Status</span><span class="dv">${esc(job.roll_status||'—')}</span></div>
  </div></div>`;

  // Timeline
  html += `<div class="jc-detail-section"><h3><i class="bi bi-clock-history"></i> Timeline</h3><div class="jc-timeline">
    <div class="jc-timeline-item"><span class="tl-label">Created</span><span class="tl-val">${createdAt}</span></div>
    <div class="jc-timeline-item"><span class="tl-label">Started</span><span class="tl-val blue">${startedAt}</span></div>
    <div class="jc-timeline-item"><span class="tl-label">Closed</span><span class="tl-val green">${completedAt}</span></div>`;
  if (sts === 'Running' && startedTs) {
    html += `<div class="jc-timeline-item"><span class="tl-label">Elapsed</span><span class="tl-val jc-timer" data-started="${startedTs}" style="color:var(--jc-blue);font-size:.9rem">00:00:00</span></div>`;
  } else if (dur !== null && dur !== undefined) {
    html += `<div class="jc-timeline-item"><span class="tl-label">Duration</span><span class="tl-val green">${Math.floor(dur/60)}h ${dur%60}m</span></div>`;
  }
  html += `</div></div>`;

  // Notes
  if (job.notes) {
    html += `<div class="jc-detail-section"><h3><i class="bi bi-sticky"></i> Notes</h3><div style="font-size:.82rem;color:#475569;line-height:1.5;background:#f8fafc;padding:12px;border-radius:8px">${esc(job.notes)}</div></div>`;
  }

  // Parent Roll Details
  if (extra.parent_roll) {
    const p = extra.parent_details || {};
    html += `<div class="jc-detail-section"><h3><i class="bi bi-inbox"></i> Parent Roll</h3><div style="font-size:.75rem;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.73rem"><tr style="background:#f3f4f6"><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Roll No</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Paper Company</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Material</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Width</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Length</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Weight</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Sqr Mtr</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">GSM</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Remarks</th></tr><tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:var(--jc-brand);font-weight:700">${esc(p.roll_no || extra.parent_roll || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc(p.company || job.company || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc(p.paper_type || job.paper_type || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((p.width_mm ?? job.width_mm ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((p.length_mtr ?? job.length_mtr ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((p.weight_kg ?? job.weight_kg ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((p.sqm ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((p.gsm ?? job.gsm ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc(p.remarks || '—')}</td></tr></table></div></div>`;
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
    let allRollHtml = '<table style="width:100%;border-collapse:collapse;font-size:.73rem"><tr style="background:#f3f4f6"><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Parent Roll No</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Child Roll NO.</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Width</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Length</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Type</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Weight</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Sqr Mtr</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">GSM</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Wastage</th><th style="padding:8px;text-align:left;border-bottom:1px solid #d1d5db;font-weight:700">Remarks</th></tr>';
    allRows.forEach(function(r) {
      allRollHtml += `<tr><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:#334155;font-weight:700">${esc(r.parent_roll_no || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb;color:var(--jc-brand);font-weight:700">${esc(r.roll_no || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.width ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.length ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc(r.type || '—')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.weight_kg ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.sqm ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.gsm ?? '—') + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc((r.wastage ?? 0) + '')}</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">${esc(r.remarks || '—')}</td></tr>`;
    });
    allRollHtml += '</table>';
    html += `<div class="jc-detail-section"><h3><i class="bi bi-table"></i> All Child Rolls</h3><div style="font-size:.75rem;overflow-x:auto">${allRollHtml}</div></div>`;
  }

  document.getElementById('dm-body').innerHTML = html;
  updateTimers();

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += '<select id="dm-print-template" class="form-control" style="height:32px;min-width:140px"><option value="executive">Executive</option><option value="compact">Compact</option></select>';
  fHtml += `<button class="jc-action-btn jc-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Job Card Print</button>`;
  fHtml += `<button class="jc-action-btn jc-btn-view" onclick="printLabelsForJob(${job.id})"><i class="bi bi-upc-scan"></i> Label Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (sts === 'Pending') fHtml += `<button class="jc-action-btn jc-btn-complete" onclick="updateJobStatus(${job.id}, 'Closed')"><i class="bi bi-check-lg"></i> Close Jumbo Job</button>`;
  fHtml += `<button class="jc-action-btn jc-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  document.getElementById('jcDetailModal').classList.add('active');
}

function closeDetail() {
  document.getElementById('jcDetailModal').classList.remove('active');
}
document.getElementById('jcDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});

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
function printJobCard(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  openJobDetail(id);
  const modalBody = document.getElementById('dm-body');
  if (!modalBody) return;
  const template = document.getElementById('dm-print-template')?.value || 'executive';
  const nowText = new Date().toLocaleString();
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
        </div>
      </header>
      <main class="jc-print-content">${modalBody.innerHTML}</main>
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

function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
