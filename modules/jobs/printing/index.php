<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isOperatorView = (string)($_GET['view'] ?? '') === 'operator';
$pageTitle = $isOperatorView ? 'Flexo Operator' : 'Flexo Printing Jobs';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';

// Fetch Printing job cards with roll, planning and previous slitting job details
$jobs = $db->query("
    SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           ps.status AS roll_status, ps.lot_batch_no,
           p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE j.job_type = 'Printing'
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
    ORDER BY j.created_at DESC
    LIMIT 200
")->fetch_all(MYSQLI_ASSOC);

// Parse extra_data for each job
foreach ($jobs as &$j) {
    $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
    $planningName = trim((string)($j['planning_job_name'] ?? ''));
    if ($planningName !== '') {
      $j['display_job_name'] = $planningName;
    } else {
      $deptRaw = trim((string)($j['department'] ?? 'flexo_printing'));
      $dept = ucwords(str_replace('_', ' ', $deptRaw));
      $jobNo = trim((string)($j['job_no'] ?? ''));
      $j['display_job_name'] = $jobNo !== '' ? ($jobNo . ' (' . $dept . ')') : ($dept !== '' ? $dept : '—');
    }
}
unset($j);

// Notification count
$notifCount = 0;
$nRes = $db->query("SELECT COUNT(*) as cnt FROM job_notifications WHERE (department = 'flexo_printing' OR department IS NULL) AND is_read = 0");
if ($nRes) $notifCount = (int)$nRes->fetch_assoc()['cnt'];

// Split active vs history
$activeJobs  = array_values(array_filter($jobs, fn($j) => !in_array($j['status'], ['Completed','QC Passed','Closed','Finalized'])));
$historyJobs = array_values(array_filter($jobs, fn($j) => in_array($j['status'], ['Completed','QC Passed','Closed','Finalized'])));
$activeCount  = count($activeJobs);
$historyCount = count($historyJobs);

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <?php if ($isOperatorView): ?>
    <span>Operator</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Machine Operators</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Flexo Operator</span>
  <?php else: ?>
    <span>Production</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Job Cards</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Flexo Printing</span>
  <?php endif; ?>
</div>

<style>
:root{--fp-brand:#8b5cf6;--fp-brand-dim:rgba(139,92,246,.08);--fp-orange:#f97316;--fp-blue:#3b82f6;--fp-green:#22c55e;--fp-red:#ef4444}
.fp-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.fp-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.fp-header h1 i{font-size:1.6rem;color:var(--fp-brand)}
.fp-header-meta{font-size:.75rem;color:#64748b;font-weight:600}
.fp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.fp-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:box-shadow .15s}
.fp-stat:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
.fp-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.fp-stat-val{font-size:1.5rem;font-weight:900;line-height:1}
.fp-stat-label{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.fp-filters{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.fp-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:240px;outline:none;transition:border .15s}
.fp-search:focus{border-color:var(--fp-brand)}
.fp-filter-btn{padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.fp-filter-btn.active{background:var(--fp-brand);border-color:var(--fp-brand);color:#fff}
.fp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px}
.fp-card{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden;transition:all .2s;border-left:4px solid var(--fp-brand);cursor:pointer}
.fp-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.fp-card.fp-queued{opacity:.7;border-left-color:#94a3b8}
.fp-card-head{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border,#e2e8f0);background:linear-gradient(135deg,#faf5ff,#fff)}
.fp-card-head .fp-jobno{font-weight:900;font-size:.85rem;color:#0f172a;display:flex;align-items:center;gap:8px}
.fp-card-head .fp-jobno i{color:var(--fp-brand);font-size:1rem}
.fp-card-body{padding:14px 18px}
.fp-card-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.78rem}
.fp-card-row .fp-label{color:#94a3b8;font-weight:700;font-size:.65rem;text-transform:uppercase;letter-spacing:.03em}
.fp-card-row .fp-value{font-weight:700;color:#1e293b}
.fp-card-foot{padding:12px 18px;border-top:1px solid var(--border,#e2e8f0);display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
.fp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.fp-badge-queued{background:#f1f5f9;color:#64748b}
.fp-badge-pending{background:#fef3c7;color:#92400e}
.fp-badge-running{background:#dbeafe;color:#1e40af;animation:pulse-fp 2s infinite}
.fp-badge-completed{background:#dcfce7;color:#166534}
.fp-badge-slitting{background:#ede9fe;color:#6d28d9}
.fp-badge-urgent{background:#fee2e2;color:#991b1b}
.fp-badge-high{background:#ffedd5;color:#9a3412}
.fp-badge-normal{background:#e0f2fe;color:#075985}
@keyframes pulse-fp{0%,100%{opacity:1}50%{opacity:.6}}
.fp-action-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.fp-btn-start{background:var(--fp-brand);color:#fff}
.fp-btn-start:hover{background:#7c3aed}
.fp-btn-start:disabled{opacity:.4;cursor:not-allowed}
.fp-btn-complete{background:var(--fp-green);color:#fff}
.fp-btn-complete:hover{background:#16a34a}
.fp-btn-view{background:#f1f5f9;color:#475569;border:1px solid var(--border,#e2e8f0)}
.fp-btn-view:hover{background:#e2e8f0}
.fp-btn-print{background:var(--fp-brand);color:#fff}
.fp-btn-print:hover{background:#7c3aed}
.fp-btn-delete{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
.fp-btn-delete:hover{background:#fecaca}
.fp-time{font-size:.6rem;color:#94a3b8;font-weight:600}
.fp-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.fp-empty i{font-size:3rem;opacity:.3}
.fp-empty p{margin-top:12px;font-size:.9rem;font-weight:600}
.fp-timer{font-size:.75rem;font-weight:800;color:var(--fp-brand);font-family:'Courier New',monospace}
.fp-notif-badge{background:#ef4444;color:#fff;font-size:9px;font-weight:800;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-left:6px}
.fp-gate-info{font-size:.6rem;color:#f59e0b;font-weight:700;display:flex;align-items:center;gap:4px;background:#fef3c7;padding:4px 8px;border-radius:6px}

/* ── Detail Modal ── */
.fp-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.fp-modal-overlay.active{display:flex}
.fp-modal{background:#fff;border-radius:16px;max-width:720px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.fp-modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#faf5ff,#fff);border-radius:16px 16px 0 0}
.fp-modal-header h2{font-size:1.1rem;font-weight:900;display:flex;align-items:center;gap:10px}
.fp-modal-header h2 i{color:var(--fp-brand)}
.fp-modal-body{padding:24px}
.fp-detail-section{margin-bottom:20px}
.fp-detail-section h3{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.fp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
.fp-detail-item{display:flex;flex-direction:column;gap:2px}
.fp-detail-item .dl{font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.fp-detail-item .dv{font-size:.82rem;font-weight:700;color:#1e293b}
.fp-timeline{display:flex;gap:20px;flex-wrap:wrap}
.fp-timeline-item{display:flex;flex-direction:column;gap:2px}
.fp-timeline-item .tl-label{font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8}
.fp-timeline-item .tl-val{font-size:.75rem;font-weight:700;color:#1e293b}
.fp-timeline-item .tl-val.green{color:#16a34a}
.fp-timeline-item .tl-val.purple{color:#8b5cf6}
.fp-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.fp-form-group{display:flex;flex-direction:column;gap:4px}
.fp-form-group label{font-size:.6rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
.fp-form-group input,.fp-form-group select,.fp-form-group textarea{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;font-weight:600;font-family:inherit}
.fp-form-group textarea{min-height:60px;resize:vertical}
.fp-form-group input:focus,.fp-form-group select:focus,.fp-form-group textarea:focus{outline:none;border-color:var(--fp-brand);box-shadow:0 0 0 2px rgba(139,92,246,.1)}
.fp-modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}

.fp-tabs{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.fp-tab-btn{padding:7px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:999px;cursor:pointer;color:#64748b;transition:all .15s}
.fp-tab-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.fp-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.62rem;margin-left:6px}
.fp-tab-btn.active .fp-tab-count{background:rgba(255,255,255,.2);color:#fff}
.fp-card-check{width:16px;height:16px;cursor:pointer;accent-color:var(--fp-brand);flex-shrink:0;margin-right:2px}
@media print{.no-print,.breadcrumb,.page-header,.fp-modal-overlay{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:600px){.fp-grid{grid-template-columns:1fr}.fp-stats{grid-template-columns:repeat(2,1fr)}.fp-detail-grid{grid-template-columns:1fr}.fp-form-row{grid-template-columns:1fr}}
</style>

<div class="fp-header no-print">
  <div>
    <h1><i class="bi bi-printer"></i> <?= $isOperatorView ? 'Flexo Operator' : 'Flexo Printing Jobs' ?>
      <?php if ($notifCount > 0): ?><span class="fp-notif-badge"><?= $notifCount ?></span><?php endif; ?>
    </h1>
    <div class="fp-header-meta">
      <?= $isOperatorView
        ? 'Operator execution board for Flexo printing job cards.'
        : 'Auto-generated for printing after slitting &middot; Sequential gating from Jumbo Slitting' ?>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="fp-action-btn fp-btn-view" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<?php
$totalJobs = count($jobs);
$pendingJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Pending'));
$runningJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Running'));
$completedJobs = count(array_filter($jobs, fn($j) => in_array($j['status'], ['Completed','QC Passed'])));
$queuedJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Queued'));
?>
<div class="fp-stats no-print">
  <div class="fp-stat">
    <div class="fp-stat-icon" style="background:#faf5ff;color:var(--fp-brand)"><i class="bi bi-printer"></i></div>
    <div><div class="fp-stat-val"><?= $totalJobs ?></div><div class="fp-stat-label">Total Print Jobs</div></div>
  </div>
  <div class="fp-stat">
    <div class="fp-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="fp-stat-val"><?= $queuedJobs ?></div><div class="fp-stat-label">Queued</div></div>
  </div>
  <div class="fp-stat">
    <div class="fp-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="fp-stat-val"><?= $pendingJobs ?></div><div class="fp-stat-label">Pending</div></div>
  </div>
  <div class="fp-stat">
    <div class="fp-stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="bi bi-play-circle"></i></div>
    <div><div class="fp-stat-val"><?= $runningJobs ?></div><div class="fp-stat-label">Running</div></div>
  </div>
  <div class="fp-stat">
    <div class="fp-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle"></i></div>
    <div><div class="fp-stat-val"><?= $completedJobs ?></div><div class="fp-stat-label">Completed</div></div>
  </div>
</div>

<div class="fp-tabs no-print">
  <button id="fpTabBtnActive" class="fp-tab-btn active" type="button" onclick="switchFPTab('active')">Job Cards <span class="fp-tab-count"><?= $activeCount ?></span></button>
  <button id="fpTabBtnHistory" class="fp-tab-btn" type="button" onclick="switchFPTab('history')">History <span class="fp-tab-count"><?= $historyCount ?></span></button>
</div>

<div id="fpPanelActive">
<div class="fp-filters no-print">
  <input type="text" class="fp-search" id="fpSearch" placeholder="Search by job no, roll, company&hellip;">
  <button class="fp-filter-btn" onclick="filterFP('all',this)">All</button>
  <button class="fp-filter-btn active" onclick="filterFP('Queued',this)">Queued</button>
  <button class="fp-filter-btn" onclick="filterFP('Pending',this)">Pending</button>
  <button class="fp-filter-btn" onclick="filterFP('Running',this)">Running</button>
  <button class="fp-filter-btn" onclick="filterFP('Completed',this)">Completed</button>
  <button id="fpPrintSelBtn" onclick="printSelectedJobs()" style="display:none;padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--fp-brand);color:#fff;border-radius:20px;cursor:pointer;align-items:center;gap:6px;letter-spacing:.04em"><i class="bi bi-printer-fill"></i> Print Selected (<span id="fpSelCount">0</span>)</button>
</div>

<div class="fp-grid no-print" id="fpGrid">
<?php if (empty($jobs)): ?>
  <div class="fp-empty" style="grid-column:1/-1">
    <i class="bi bi-inbox"></i>
    <p>No printing job cards yet. They are auto-created when slitting operations execute.</p>
  </div>
<?php else: ?>
  <?php foreach ($jobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Queued'=>'queued', 'Pending'=>'pending', 'Running'=>'running', 'Completed','QC Passed'=>'completed', default=>'pending' };
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0;
    $dur = $job['duration_minutes'] ?? null;
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
    // Sequencing gate: can only start if previous slitting job is completed
    $prevDone = true;
    if ($job['previous_job_id'] && $job['prev_job_status'] && !in_array($job['prev_job_status'], ['Completed','QC Passed'])) {
        $prevDone = false;
    }
    $isQueued = ($sts === 'Queued');
  ?>
  <div class="fp-card <?= $isQueued ? 'fp-queued' : '' ?>" data-status="<?= e($sts) ?>" data-lockstate="<?= $prevDone ? 'unlocked' : 'locked' ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openPrintDetail(<?= $job['id'] ?>)">
    <div class="fp-card-head">
      <div class="fp-jobno"><i class="bi bi-printer-fill"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="fp-badge fp-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="fp-badge fp-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="fp-card-body">
      <?php if ($job['planning_job_name']): ?>
      <div class="fp-card-row"><span class="fp-label">Job Name</span><span class="fp-value"><?= e($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
      <div class="fp-card-row"><span class="fp-label">Roll No</span><span class="fp-value" style="color:var(--fp-brand)"><?= e($job['roll_no'] ?? '—') ?></span></div>
      <div class="fp-card-row"><span class="fp-label">Material</span><span class="fp-value"><?= e($job['paper_type'] ?? '—') ?></span></div>
      <div class="fp-card-row"><span class="fp-label">Dimension</span><span class="fp-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <?php if ($isQueued || !$prevDone): ?>
      <div class="fp-card-row">
        <span class="fp-gate-info"><i class="bi bi-lock-fill"></i> Waiting for slitting: <?= e($job['prev_job_no'] ?? '—') ?> (<?= e($job['prev_job_status'] ?? '—') ?>)</span>
      </div>
      <?php endif; ?>
      <?php if ($sts === 'Running' && $startedTs): ?>
      <div class="fp-card-row"><span class="fp-label">Elapsed</span><span class="fp-timer" data-started="<?= $startedTs ?>">00:00:00</span></div>
      <?php elseif ($dur !== null): ?>
      <div class="fp-card-row"><span class="fp-label">Duration</span><span class="fp-value"><?= floor($dur/60) ?>h <?= $dur%60 ?>m</span></div>
      <?php endif; ?>
    </div>
    <div class="fp-card-foot">
      <div class="fp-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <input type="checkbox" class="fp-card-check" data-id="<?= $job['id'] ?>" onclick="event.stopPropagation();updatePrintCount()" title="Select for bulk print">
        <?php if ($sts === 'Pending' && $prevDone): ?>
          <button class="fp-action-btn fp-btn-start" onclick="updateFPStatus(<?= $job['id'] ?>,'Running')"><i class="bi bi-play-fill"></i> Start</button>
        <?php elseif ($sts === 'Pending' && !$prevDone): ?>
          <button class="fp-action-btn fp-btn-start" disabled title="Slitting job must complete first"><i class="bi bi-lock-fill"></i> Locked</button>
        <?php elseif ($sts === 'Running'): ?>
          <button class="fp-action-btn fp-btn-complete" onclick="openPrintDetail(<?= $job['id'] ?>,'complete')"><i class="bi bi-check-lg"></i> Complete</button>
        <?php endif; ?>
        <button class="fp-action-btn fp-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div><!-- end fpPanelActive -->

<div id="fpPanelHistory" style="display:none">
<div class="card no-print" style="margin-top:8px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <span class="card-title"><i class="bi bi-clock-history"></i> Flexo Printing History (Completed / QC Passed)</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="text" id="fpHistorySearch" placeholder="Search history..." oninput="filterHistory(this.value)" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;outline:none">
      <span style="font-size:.72rem;color:#64748b;font-weight:700"><?= $historyCount ?> records</span>
      <button onclick="window.print()" style="padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;background:var(--fp-brand);color:#fff;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><i class="bi bi-printer"></i> Print</button>
    </div>
  </div>
  <div style="overflow:auto">
    <table id="fpHistoryTable" style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Job No</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Job Name</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Roll No</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Material</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Status</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Started</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Completed</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Duration</th>
        </tr>
      </thead>
      <tbody id="fpHistoryBody">
        <?php if (empty($historyJobs)): ?>
          <tr><td colspan="8" style="padding:20px;text-align:center;color:#94a3b8">No completed jobs yet.</td></tr>
        <?php else: ?>
          <?php foreach ($historyJobs as $h):
            $hDur = $h['duration_minutes'] ?? null;
            $hDurStr = ($hDur !== null) ? (floor($hDur/60).'h '.($hDur%60).'m') : '—';
            $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
            $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : '—';
            $hSearch = e(strtolower(($h['job_no']??'').' '.($h['planning_job_name']??'').' '.($h['display_job_name']??'').' '.($h['roll_no']??'')));
          ?>
          <tr data-search="<?= $hSearch ?>" style="cursor:pointer" onclick="openPrintDetail(<?= $h['id'] ?>)" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;font-weight:700;color:var(--fp-brand)"><?= e($h['job_no']) ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['planning_job_name'] ?? $h['display_job_name'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['roll_no'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['paper_type'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><span class="fp-badge fp-badge-completed"><?= e($h['status']) ?></span></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hStarted ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hCompleted ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hDurStr ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- end fpPanelHistory -->

<!-- ═══ DETAIL MODAL ═══ -->
<div class="fp-modal-overlay" id="fpDetailModal">
  <div class="fp-modal">
    <div class="fp-modal-header">
      <h2><i class="bi bi-printer-fill"></i> <span id="dm-jobno"></span></h2>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="dm-status-badge" class="fp-badge"></span>
        <button class="fp-action-btn fp-btn-view" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div class="fp-modal-body" id="dm-body"></div>
    <div class="fp-modal-footer" id="dm-footer"></div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const BASE_URL = '<?= BASE_URL ?>';
const IS_OPERATOR_VIEW = <?= $isOperatorView ? 'true' : 'false' ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode($jobs, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
let activeStatusFilter = 'Queued';

function getFieldVal(form, name) {
  return String(form.querySelector('[name="' + name + '"]')?.value || '').trim();
}

function getNumberFieldVal(form, name) {
  const raw = getFieldVal(form, name);
  if (raw === '') return '';
  const n = Number(raw);
  return Number.isFinite(n) ? n : raw;
}

function normalizeCardData(job, extra) {
  const out = Object.assign({}, extra || {});
  out.mkd_job_sl_no = String(out.mkd_job_sl_no || '').trim();
  out.job_date = String(out.job_date || '').trim();
  out.job_name = String(out.job_name || resolvePrintDisplayName(job)).trim();
  out.die = String(out.die || '').trim();
  out.plate_no = String(out.plate_no || '').trim();
  out.material_company = String(out.material_company || job.company || '').trim();
  out.material_name = String(out.material_name || job.paper_type || '').trim();
  out.order_mtr = out.order_mtr ?? '';
  out.order_qty = out.order_qty ?? '';
  out.reel_no_c1 = String(out.reel_no_c1 || '').trim();
  out.reel_no_c2 = String(out.reel_no_c2 || '').trim();
  out.width_c1 = out.width_c1 ?? (job.width_mm || '');
  out.width_c2 = out.width_c2 ?? (job.width_mm || '');
  out.length_c1 = out.length_c1 ?? (job.length_mtr || '');
  out.length_c2 = out.length_c2 ?? (job.length_mtr || '');
  out.label_size = String(out.label_size || '').trim();
  out.repeat_mm = String(out.repeat_mm || '').trim();
  out.direction = String(out.direction || '').trim();
  out.actual_qty = out.actual_qty ?? '';
  out.electricity = String(out.electricity || '').trim();
  out.time_spent = String(out.time_spent || '').trim();
  out.prepared_by = String(out.prepared_by || '').trim();
  out.filled_by = String(out.filled_by || '').trim();
  if (!Array.isArray(out.colour_lanes)) out.colour_lanes = ['', '', '', '', '', '', '', ''];
  if (!Array.isArray(out.anilox_lanes)) out.anilox_lanes = ['', '', '', '', '', '', '', ''];
  out.colour_lanes = out.colour_lanes.slice(0, 8).concat(Array(Math.max(0, 8 - out.colour_lanes.length)).fill('')).map(v => String(v || '').trim());
  out.anilox_lanes = out.anilox_lanes.slice(0, 8).concat(Array(Math.max(0, 8 - out.anilox_lanes.length)).fill('')).map(v => String(v || '').trim());
  return out;
}

function resolvePrintDisplayName(job) {
  if (job && String(job.display_job_name || '').trim() !== '') return String(job.display_job_name).trim();
  if (job && String(job.planning_job_name || '').trim() !== '') return String(job.planning_job_name).trim();
  const jobNo = String(job?.job_no || '').trim();
  const dept = String(job?.department || 'flexo_printing').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  if (jobNo !== '') return `${jobNo} (${dept})`;
  return dept || '—';
}

function operatorTabMatch(card, status) {
  const lockState = String(card.dataset.lockstate || '').toLowerCase();
  const cardStatus = String(card.dataset.status || '').trim();

  if (status === 'Queued') return lockState === 'locked';
  if (status === 'Pending') return lockState !== 'locked' && (cardStatus === 'Pending' || cardStatus === 'Queued');
  if (status === 'all') return true;
  return cardStatus === status;
}

function applyFPFilters() {
  const q = String(document.getElementById('fpSearch')?.value || '').toLowerCase();
  document.querySelectorAll('.fp-card').forEach(card => {
    const searchOk = (card.dataset.search || '').includes(q);
    const statusOk = operatorTabMatch(card, activeStatusFilter);
    card.style.display = (searchOk && statusOk) ? '' : 'none';
  });
}

// ─── Filters ────────────────────────────────────────────────
function filterFP(status, btn) {
  activeStatusFilter = status;
  document.querySelectorAll('.fp-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  applyFPFilters();
}

document.getElementById('fpSearch').addEventListener('input', function() {
  applyFPFilters();
});

applyFPFilters();

// ─── Tab switching ──────────────────────────────────────────
function switchFPTab(tab) {
  const panelActive  = document.getElementById('fpPanelActive');
  const panelHistory = document.getElementById('fpPanelHistory');
  const btnActive    = document.getElementById('fpTabBtnActive');
  const btnHistory   = document.getElementById('fpTabBtnHistory');
  if (tab === 'history') {
    panelActive.style.display  = 'none';
    panelHistory.style.display = '';
    btnActive.classList.remove('active');
    btnHistory.classList.add('active');
  } else {
    panelActive.style.display  = '';
    panelHistory.style.display = 'none';
    btnActive.classList.add('active');
    btnHistory.classList.remove('active');
  }
}

// ─── History search ──────────────────────────────────────────
function filterHistory(q) {
  q = (q || '').toLowerCase();
  document.querySelectorAll('#fpHistoryBody tr[data-search]').forEach(row => {
    row.style.display = (row.dataset.search || '').includes(q) ? '' : 'none';
  });
}

// ─── Multi-select print ──────────────────────────────────────
function updatePrintCount() {
  const checked = document.querySelectorAll('.fp-card-check:checked').length;
  const btn = document.getElementById('fpPrintSelBtn');
  if (btn) {
    btn.style.display = checked > 0 ? 'inline-flex' : 'none';
    const cnt = btn.querySelector('#fpSelCount');
    if (cnt) cnt.textContent = checked;
  }
}

async function printSelectedJobs() {
  const checkedIds = Array.from(document.querySelectorAll('.fp-card-check:checked')).map(c => parseInt(c.dataset.id));
  if (!checkedIds.length) { alert('No job cards selected.'); return; }
  const selectedJobs = ALL_JOBS.filter(j => checkedIds.includes(j.id));
  let pages = '';
  for (const [idx, job] of selectedJobs.entries()) {
    const extra = job.extra_data_parsed || {};
    const card = normalizeCardData(job, extra);
    const created   = job.created_at   ? new Date(job.created_at).toLocaleString()   : '—';
    const started   = job.started_at   ? new Date(job.started_at).toLocaleString()   : '—';
    const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
    const dur = job.duration_minutes;
    const pb  = idx < selectedJobs.length - 1 ? 'page-break-after:always;' : '';
    const jqrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
    const jqrDataUrl = await generateQR(jqrUrl);
    const jqrHtml = jqrDataUrl ? `<div style="text-align:center;margin-left:12px"><img src="${jqrDataUrl}" style="width:90px;height:90px;display:block"><div style="font-size:.5rem;color:#94a3b8;margin-top:2px;text-align:center">Scan to open</div></div>` : '';
    pages += `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:24px;max-width:700px;margin:0 auto;${pb}">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:3px solid #8b5cf6;padding-bottom:12px">
        <div>${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:40px;margin-bottom:4px;display:block">` : ''}<div style="font-weight:900;font-size:1.1rem">${esc(COMPANY.name)}</div><div style="font-size:.7rem;color:#64748b">${esc(COMPANY.address)}</div>${COMPANY.gst ? `<div style="font-size:.65rem;color:#94a3b8">GST: ${esc(COMPANY.gst)}</div>` : ''}</div>
        <div style="display:flex;align-items:flex-start"><div style="text-align:right"><div style="font-size:1.2rem;font-weight:900;color:#8b5cf6">${esc(job.job_no)}</div><div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b">Flexo Printing Job Card</div><div style="font-size:.65rem;color:#94a3b8;margin-top:4px">${created}</div></div>${jqrHtml}</div>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:16px">
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc;width:38%">MKD Job SL NO</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.mkd_job_sl_no||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Date</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.job_date||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Job Name</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(resolvePrintDisplayName(job))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Die</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.die||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Plate No</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.plate_no||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Roll No</td><td style="padding:6px 10px;border:1px solid #e2e8f0;color:#8b5cf6;font-weight:700">${esc(job.roll_no||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Material</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.material_name||job.paper_type||'—')} / ${esc(String(job.gsm||'—'))} GSM</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Dimension</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((job.width_mm||'—')+'mm × '+(job.length_mtr||'—')+'m')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Label Size / Repeat / Dir</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.label_size||'—')+' / '+(card.repeat_mm||'—')+' / '+(card.direction||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Order MTR / QTY</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.order_mtr||'—')+' / '+(card.order_qty||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Reel No C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.reel_no_c1||'—')+' / '+(card.reel_no_c2||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Width C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.width_c1||'—')+' / '+(card.width_c2||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Length C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.length_c1||'—')+' / '+(card.length_c2||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Colour 1-8</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.colour_lanes.filter(Boolean).join(', ')||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Anilox 1-8</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.anilox_lanes.filter(Boolean).join(', ')||'—')}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Actual Qty / Electricity / Time</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.actual_qty||'—')+' / '+(card.electricity||'—')+' / '+(card.time_spent||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Prepared By / Filled By</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.prepared_by||'—')+' / '+(card.filled_by||'—'))}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Status</td><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700">${esc(job.status)}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Started</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${started}</td></tr>
        <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Completed</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${completed}</td></tr>
        ${dur !== null && dur !== undefined ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Duration</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${Math.floor(dur/60)}h ${dur%60}m</td></tr>` : ''}
      </table>
      <div style="margin-top:30px;display:flex;justify-content:space-between;font-size:.7rem;color:#94a3b8">
        <div>Operator Signature: _____________________</div>
        <div>Supervisor Signature: _____________________</div>
      </div>
    </div>`;
  }
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Flexo Job Cards (${selectedJobs.length})</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${pages}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

// ─── Live timers ────────────────────────────────────────────
function updateTimers() {
  document.querySelectorAll('.fp-timer[data-started]').forEach(el => {
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

// ─── Status update ──────────────────────────────────────────
async function updateFPStatus(id, newStatus) {
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

// ─── Submit operator extra data + complete ──────────────────
async function submitAndComplete(id) {
  const job = ALL_JOBS.find(j => j.id == id) || {};
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateFPStatus(id, 'Completed');

  const baseExtra = normalizeCardData(job, job.extra_data_parsed || {});
  const extraData = Object.assign({}, baseExtra, {
    ink_colors: form.querySelector('[name=ink_colors]')?.value || '',
    cylinder_ref: form.querySelector('[name=cylinder_ref]')?.value || '',
    impression_count: form.querySelector('[name=impression_count]')?.value || '',
    print_speed: form.querySelector('[name=print_speed]')?.value || '',
    color_match_status: form.querySelector('[name=color_match_status]')?.value || 'Matched',
    wastage_meters: form.querySelector('[name=wastage_meters]')?.value || '',
    operator_notes: form.querySelector('[name=operator_notes]')?.value || '',
    defects: Array.from(form.querySelectorAll('[name=defects]:checked')).map(c=>c.value),
    mkd_job_sl_no: getFieldVal(form, 'mkd_job_sl_no'),
    job_date: getFieldVal(form, 'job_date'),
    job_name: getFieldVal(form, 'job_name') || resolvePrintDisplayName(job),
    die: getFieldVal(form, 'die'),
    plate_no: getFieldVal(form, 'plate_no'),
    material_company: getFieldVal(form, 'material_company'),
    material_name: getFieldVal(form, 'material_name'),
    order_mtr: getNumberFieldVal(form, 'order_mtr'),
    order_qty: getNumberFieldVal(form, 'order_qty'),
    reel_no_c1: getFieldVal(form, 'reel_no_c1'),
    reel_no_c2: getFieldVal(form, 'reel_no_c2'),
    width_c1: getNumberFieldVal(form, 'width_c1'),
    width_c2: getNumberFieldVal(form, 'width_c2'),
    length_c1: getNumberFieldVal(form, 'length_c1'),
    length_c2: getNumberFieldVal(form, 'length_c2'),
    label_size: getFieldVal(form, 'label_size'),
    repeat_mm: getFieldVal(form, 'repeat_mm'),
    direction: getFieldVal(form, 'direction'),
    actual_qty: getNumberFieldVal(form, 'actual_qty'),
    electricity: getFieldVal(form, 'electricity'),
    time_spent: getFieldVal(form, 'time_spent'),
    prepared_by: getFieldVal(form, 'prepared_by'),
    filled_by: getFieldVal(form, 'filled_by'),
    colour_lanes: Array.from({ length: 8 }, (_, i) => getFieldVal(form, 'colour_lane_' + (i + 1))),
    anilox_lanes: Array.from({ length: 8 }, (_, i) => getFieldVal(form, 'anilox_lane_' + (i + 1)))
  });

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

  await updateFPStatus(id, 'Completed');
}

// ─── Detail modal ───────────────────────────────────────────
function openPrintDetail(id, mode) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;

  const sts = job.status;
  const stsClass = {Queued:'queued',Pending:'pending',Running:'running',Completed:'completed','QC Passed':'completed'}[sts]||'pending';
  const extra = job.extra_data_parsed || {};
  const card = normalizeCardData(job, extra);
  const createdAt = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;
  const startedTs = job.started_at ? new Date(job.started_at).getTime() : 0;
  const prevDone = !job.previous_job_id || !job.prev_job_status || ['Completed','QC Passed'].includes(job.prev_job_status);

  document.getElementById('dm-jobno').textContent = job.job_no;
  const badge = document.getElementById('dm-status-badge');
  badge.textContent = sts;
  badge.className = 'fp-badge fp-badge-' + stsClass;

  let html = '';

  // Sequencing info
  if (job.prev_job_no) {
    const pvColor = prevDone ? '#16a34a' : '#f59e0b';
    html += `<div class="fp-detail-section" style="padding:12px;background:${prevDone?'#f0fdf4':'#fef3c7'};border-radius:10px;border-left:4px solid ${pvColor}">
      <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700">
        <i class="bi bi-${prevDone?'check-circle-fill':'lock-fill'}" style="color:${pvColor}"></i>
        Previous Job: <span style="color:var(--fp-brand)">${esc(job.prev_job_no)}</span>
        — <span style="color:${pvColor}">${esc(job.prev_job_status||'—')}</span>
        ${prevDone?'<span style="font-size:.65rem;color:#16a34a">(Ready for printing)</span>':'<span style="font-size:.65rem;color:#f59e0b">(Slitting must complete first)</span>'}
      </div>
    </div>`;
  }

  // Job Info
  html += `<div class="fp-detail-section"><h3><i class="bi bi-info-circle"></i> Job Information</h3><div class="fp-detail-grid">
    <div class="fp-detail-item"><span class="dl">Job No</span><span class="dv" style="color:var(--fp-brand)">${esc(job.job_no)}</span></div>
    <div class="fp-detail-item"><span class="dl">Job Name</span><span class="dv">${esc(resolvePrintDisplayName(job))}</span></div>
    <div class="fp-detail-item"><span class="dl">Department</span><span class="dv">Flexo Printing</span></div>
    <div class="fp-detail-item"><span class="dl">Priority</span><span class="dv">${esc(job.planning_priority||'Normal')}</span></div>
    <div class="fp-detail-item"><span class="dl">Sequence</span><span class="dv">#${job.sequence_order||2}</span></div>
  </div></div>`;

  // Material Info
  html += `<div class="fp-detail-section"><h3><i class="bi bi-box"></i> Material Information</h3><div class="fp-detail-grid">
    <div class="fp-detail-item"><span class="dl">Roll No</span><span class="dv" style="color:var(--fp-brand)">${esc(job.roll_no||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Paper Type</span><span class="dv">${esc(job.paper_type||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">GSM</span><span class="dv">${esc(job.gsm||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Width × Length</span><span class="dv">${esc((job.width_mm||'—')+'mm × '+(job.length_mtr||'—')+'m')}</span></div>
    <div class="fp-detail-item"><span class="dl">Weight</span><span class="dv">${job.weight_kg ? job.weight_kg+' kg' : '—'}</span></div>
    <div class="fp-detail-item"><span class="dl">Supplier</span><span class="dv">${esc(job.company||'—')}</span></div>
  </div></div>`;

  html += `<div class="fp-detail-section"><h3><i class="bi bi-journal-text"></i> Printing Job Card Fields</h3><div class="fp-detail-grid">
    <div class="fp-detail-item"><span class="dl">MKD Job SL NO</span><span class="dv">${esc(card.mkd_job_sl_no||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Date</span><span class="dv">${esc(card.job_date||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Job Name</span><span class="dv">${esc(card.job_name||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Die</span><span class="dv">${esc(card.die||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Plate No</span><span class="dv">${esc(card.plate_no||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Material Company</span><span class="dv">${esc(card.material_company||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Material Name</span><span class="dv">${esc(card.material_name||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Order MTR</span><span class="dv">${esc(card.order_mtr||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Order QTY</span><span class="dv">${esc(card.order_qty||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Label Size</span><span class="dv">${esc(card.label_size||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Repeat</span><span class="dv">${esc(card.repeat_mm||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Direction</span><span class="dv">${esc(card.direction||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Reel No C1/C2</span><span class="dv">${esc((card.reel_no_c1||'—') + ' / ' + (card.reel_no_c2||'—'))}</span></div>
    <div class="fp-detail-item"><span class="dl">Width C1/C2</span><span class="dv">${esc((card.width_c1||'—') + ' / ' + (card.width_c2||'—'))}</span></div>
    <div class="fp-detail-item"><span class="dl">Length C1/C2</span><span class="dv">${esc((card.length_c1||'—') + ' / ' + (card.length_c2||'—'))}</span></div>
    <div class="fp-detail-item"><span class="dl">Colour 1-8</span><span class="dv">${esc(card.colour_lanes.filter(Boolean).join(', ') || '—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Anilox 1-8</span><span class="dv">${esc(card.anilox_lanes.filter(Boolean).join(', ') || '—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Actual QTY</span><span class="dv">${esc(card.actual_qty||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Electricity</span><span class="dv">${esc(card.electricity||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Time</span><span class="dv">${esc(card.time_spent||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Prepared By</span><span class="dv">${esc(card.prepared_by||'—')}</span></div>
    <div class="fp-detail-item"><span class="dl">Filled By</span><span class="dv">${esc(card.filled_by||'—')}</span></div>
  </div></div>`;

  // Timeline
  html += `<div class="fp-detail-section"><h3><i class="bi bi-clock-history"></i> Timeline</h3><div class="fp-timeline">
    <div class="fp-timeline-item"><span class="tl-label">Created</span><span class="tl-val">${createdAt}</span></div>
    <div class="fp-timeline-item"><span class="tl-label">Started</span><span class="tl-val purple">${startedAt}</span></div>
    <div class="fp-timeline-item"><span class="tl-label">Completed</span><span class="tl-val green">${completedAt}</span></div>`;
  if (sts === 'Running' && startedTs) {
    html += `<div class="fp-timeline-item"><span class="tl-label">Elapsed</span><span class="tl-val fp-timer" data-started="${startedTs}" style="color:var(--fp-brand);font-size:.9rem">00:00:00</span></div>`;
  } else if (dur !== null && dur !== undefined) {
    html += `<div class="fp-timeline-item"><span class="tl-label">Duration</span><span class="tl-val green">${Math.floor(dur/60)}h ${dur%60}m</span></div>`;
  }
  html += `</div></div>`;

  // Notes
  if (job.notes) {
    html += `<div class="fp-detail-section"><h3><i class="bi bi-sticky"></i> Notes</h3><div style="font-size:.82rem;color:#475569;line-height:1.5;background:#f8fafc;padding:12px;border-radius:8px">${esc(job.notes)}</div></div>`;
  }

  // Operator data (if already submitted)
  if (extra.ink_colors || extra.cylinder_ref || extra.impression_count || extra.operator_notes || card.mkd_job_sl_no || card.label_size || card.actual_qty) {
    html += `<div class="fp-detail-section"><h3><i class="bi bi-person-badge"></i> Operator Submission</h3><div class="fp-detail-grid">
      <div class="fp-detail-item"><span class="dl">Ink Colors</span><span class="dv">${esc(extra.ink_colors||'—')}</span></div>
      <div class="fp-detail-item"><span class="dl">Cylinder Ref</span><span class="dv">${esc(extra.cylinder_ref||'—')}</span></div>
      <div class="fp-detail-item"><span class="dl">Impressions</span><span class="dv">${esc(extra.impression_count||'—')}</span></div>
      <div class="fp-detail-item"><span class="dl">Print Speed</span><span class="dv">${esc(extra.print_speed||'—')} m/min</span></div>
      <div class="fp-detail-item"><span class="dl">Color Match</span><span class="dv">${esc(extra.color_match_status||'—')}</span></div>
      <div class="fp-detail-item"><span class="dl">Wastage</span><span class="dv">${esc(extra.wastage_meters||'—')} m</span></div>
      <div class="fp-detail-item"><span class="dl">Notes</span><span class="dv">${esc(extra.operator_notes||'—')}</span></div>
      ${extra.defects && extra.defects.length ? `<div class="fp-detail-item"><span class="dl">Defects</span><span class="dv">${extra.defects.join(', ')}</span></div>` : ''}
    </div></div>`;
  }

  // Operator input form (only for Running jobs or when completing)
  if (sts === 'Running' || mode === 'complete' || IS_OPERATOR_VIEW) {
    html += `<div class="fp-detail-section"><h3><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</h3>
    <form id="dm-operator-form">
      <div class="fp-form-row">
        <div class="fp-form-group"><label>MKD Job SL NO</label><input type="text" name="mkd_job_sl_no" value="${esc(card.mkd_job_sl_no||'')}"></div>
        <div class="fp-form-group"><label>Date</label><input type="date" name="job_date" value="${esc(card.job_date||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Job Name</label><input type="text" name="job_name" value="${esc(card.job_name||'')}"></div>
        <div class="fp-form-group"><label>Die</label><input type="text" name="die" value="${esc(card.die||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Plate No</label><input type="text" name="plate_no" value="${esc(card.plate_no||'')}"></div>
        <div class="fp-form-group"><label>Material Company</label><input type="text" name="material_company" value="${esc(card.material_company||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Material Name</label><input type="text" name="material_name" value="${esc(card.material_name||'')}"></div>
        <div class="fp-form-group"><label>Order MTR</label><input type="number" step="0.01" name="order_mtr" value="${esc(card.order_mtr||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Order QTY</label><input type="number" step="1" name="order_qty" value="${esc(card.order_qty||'')}"></div>
        <div class="fp-form-group"><label>Label Size</label><input type="text" name="label_size" value="${esc(card.label_size||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Repeat</label><input type="text" name="repeat_mm" value="${esc(card.repeat_mm||'')}"></div>
        <div class="fp-form-group"><label>Direction</label><input type="text" name="direction" value="${esc(card.direction||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Reel No C1</label><input type="text" name="reel_no_c1" value="${esc(card.reel_no_c1||'')}"></div>
        <div class="fp-form-group"><label>Reel No C2</label><input type="text" name="reel_no_c2" value="${esc(card.reel_no_c2||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Width C1</label><input type="number" step="0.01" name="width_c1" value="${esc(card.width_c1||'')}"></div>
        <div class="fp-form-group"><label>Width C2</label><input type="number" step="0.01" name="width_c2" value="${esc(card.width_c2||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Length C1</label><input type="number" step="0.01" name="length_c1" value="${esc(card.length_c1||'')}"></div>
        <div class="fp-form-group"><label>Length C2</label><input type="number" step="0.01" name="length_c2" value="${esc(card.length_c2||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Ink Colors</label><input type="text" name="ink_colors" value="${esc(extra.ink_colors||'')}" placeholder="e.g. CMYK, PMS 185"></div>
        <div class="fp-form-group"><label>Cylinder Reference</label><input type="text" name="cylinder_ref" value="${esc(extra.cylinder_ref||'')}" placeholder="e.g. CYL-A-001"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Impression Count</label><input type="number" name="impression_count" value="${esc(extra.impression_count||'')}" placeholder="e.g. 15000"></div>
        <div class="fp-form-group"><label>Print Speed (m/min)</label><input type="number" step="0.1" name="print_speed" value="${esc(extra.print_speed||'')}" placeholder="e.g. 120"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Color Match Status</label><select name="color_match_status">
          <option value="Matched"${extra.color_match_status==='Matched'?' selected':''}>Matched</option>
          <option value="Slight Variation"${extra.color_match_status==='Slight Variation'?' selected':''}>Slight Variation</option>
          <option value="Mismatch"${extra.color_match_status==='Mismatch'?' selected':''}>Mismatch</option>
        </select></div>
        <div class="fp-form-group"><label>Wastage (meters)</label><input type="number" step="0.1" name="wastage_meters" value="${esc(extra.wastage_meters||'')}" placeholder="e.g. 5.2"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Defects Found</label>
          <div style="display:flex;gap:10px;flex-wrap:wrap;padding:6px 0">
            <label style="font-size:.75rem;font-weight:600;cursor:pointer"><input type="checkbox" name="defects" value="Ink Smudge"${(extra.defects||[]).includes('Ink Smudge')?' checked':''}> Ink Smudge</label>
            <label style="font-size:.75rem;font-weight:600;cursor:pointer"><input type="checkbox" name="defects" value="Misalignment"${(extra.defects||[]).includes('Misalignment')?' checked':''}> Misalignment</label>
            <label style="font-size:.75rem;font-weight:600;cursor:pointer"><input type="checkbox" name="defects" value="Color Fade"${(extra.defects||[]).includes('Color Fade')?' checked':''}> Color Fade</label>
            <label style="font-size:.75rem;font-weight:600;cursor:pointer"><input type="checkbox" name="defects" value="Plate Damage"${(extra.defects||[]).includes('Plate Damage')?' checked':''}> Plate Damage</label>
          </div>
        </div>
        <div class="fp-form-group"><label>&nbsp;</label></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Colour Lanes (1-8)</label>
          <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">
            ${Array.from({length:8}, (_,i)=>`<input type="text" name="colour_lane_${i+1}" value="${esc(card.colour_lanes[i]||'')}" placeholder="${i+1}">`).join('')}
          </div>
        </div>
        <div class="fp-form-group"><label>Anilox Lanes (1-8)</label>
          <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">
            ${Array.from({length:8}, (_,i)=>`<input type="text" name="anilox_lane_${i+1}" value="${esc(card.anilox_lanes[i]||'')}" placeholder="${i+1}">`).join('')}
          </div>
        </div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Mark Andy Actual QTY</label><input type="number" step="1" name="actual_qty" value="${esc(card.actual_qty||'')}"></div>
        <div class="fp-form-group"><label>Electricity</label><input type="text" name="electricity" value="${esc(card.electricity||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Time</label><input type="text" name="time_spent" value="${esc(card.time_spent||'')}"></div>
        <div class="fp-form-group"><label>Prepared By</label><input type="text" name="prepared_by" value="${esc(card.prepared_by||'')}"></div>
      </div>
      <div class="fp-form-row">
        <div class="fp-form-group"><label>Filled By</label><input type="text" name="filled_by" value="${esc(card.filled_by||'')}"></div>
        <div class="fp-form-group"><label>&nbsp;</label></div>
      </div>
      <div class="fp-form-group" style="margin-bottom:0"><label>Operator Notes</label><textarea name="operator_notes" placeholder="Observations, adjustments, ink changes&hellip;">${esc(extra.operator_notes||'')}</textarea></div>
    </form></div>`;
  }

  document.getElementById('dm-body').innerHTML = html;
  updateTimers();

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += `<button class="fp-action-btn fp-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (sts === 'Pending' && prevDone) fHtml += `<button class="fp-action-btn fp-btn-start" onclick="updateFPStatus(${job.id},'Running')"><i class="bi bi-play-fill"></i> Start Job</button>`;
  if (sts === 'Pending' && !prevDone) fHtml += `<button class="fp-action-btn fp-btn-start" disabled><i class="bi bi-lock-fill"></i> Waiting for Slitting</button>`;
  if (sts === 'Running') fHtml += `<button class="fp-action-btn fp-btn-complete" onclick="submitAndComplete(${job.id})"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  if (!IS_OPERATOR_VIEW) {
    fHtml += `<button class="fp-action-btn fp-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  }
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  document.getElementById('fpDetailModal').classList.add('active');
}

function closeDetail() {
  document.getElementById('fpDetailModal').classList.remove('active');
}
document.getElementById('fpDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});

// ─── Delete job (admin) ─────────────────────────────────────
async function deleteJob(id) {
  if (!confirm('Delete this job card? If linked reset logic applies, related queued jobs may also be rolled back.')) return;
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
  const extra = job.extra_data_parsed || {};
  const card = normalizeCardData(job, extra);
  const created = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const started = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;

  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  const qrHtml = qrDataUrl ? `<div style="text-align:center;margin-left:12px"><img src="${qrDataUrl}" style="width:90px;height:90px;display:block"><div style="font-size:.5rem;color:#94a3b8;margin-top:2px;text-align:center">Scan to open</div></div>` : '';

  const html = `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:24px;max-width:700px;margin:0 auto">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:3px solid #8b5cf6;padding-bottom:12px">
      <div>${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:40px;margin-bottom:4px;display:block">` : ''}
        <div style="font-weight:900;font-size:1.1rem">${esc(COMPANY.name)}</div>
        <div style="font-size:.7rem;color:#64748b">${esc(COMPANY.address)}</div>
        ${COMPANY.gst ? `<div style="font-size:.65rem;color:#94a3b8">GST: ${esc(COMPANY.gst)}</div>` : ''}
      </div>
      <div style="display:flex;align-items:flex-start">
        <div style="text-align:right">
          <div style="font-size:1.2rem;font-weight:900;color:#8b5cf6">${esc(job.job_no)}</div>
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b">Flexo Printing Job Card</div>
          <div style="font-size:.65rem;color:#94a3b8;margin-top:4px">${created}</div>
        </div>
        ${qrHtml}
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:16px">
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc;width:35%">MKD Job SL NO</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.mkd_job_sl_no||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Date</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.job_date||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc;width:35%">Job Name</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(resolvePrintDisplayName(job))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Die</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.die||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Plate No</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.plate_no||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Roll No</td><td style="padding:6px 10px;border:1px solid #e2e8f0;color:#8b5cf6;font-weight:700">${esc(job.roll_no||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Material Company</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.material_company||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Material Name</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.material_name||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Material / GSM</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.paper_type||'—')} / ${esc(job.gsm||'—')} GSM</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Dimension</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((job.width_mm||'—')+'mm × '+(job.length_mtr||'—')+'m')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Reel No C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.reel_no_c1||'—') + ' / ' + (card.reel_no_c2||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Width C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.width_c1||'—') + ' / ' + (card.width_c2||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Length C1 / C2</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.length_c1||'—') + ' / ' + (card.length_c2||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Label Size / Repeat / Direction</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.label_size||'—') + ' / ' + (card.repeat_mm||'—') + ' / ' + (card.direction||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Order MTR / QTY</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.order_mtr||'—') + ' / ' + (card.order_qty||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Weight</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${job.weight_kg ? job.weight_kg+' kg' : '—'}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Supplier</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.company||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Status</td><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700">${esc(job.status)}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Started</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${started}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Completed</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${completed}</td></tr>
      ${dur !== null && dur !== undefined ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Duration</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${Math.floor(dur/60)}h ${dur%60}m</td></tr>` : ''}
      ${job.prev_job_no ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Slitting Job</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.prev_job_no)} (${esc(job.prev_job_status||'—')})</td></tr>` : ''}
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Colour 1-8</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.colour_lanes.filter(Boolean).join(', ') || '—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Anilox 1-8</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(card.anilox_lanes.filter(Boolean).join(', ') || '—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Actual Qty / Electricity / Time</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.actual_qty||'—') + ' / ' + (card.electricity||'—') + ' / ' + (card.time_spent||'—'))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Prepared By / Filled By</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((card.prepared_by||'—') + ' / ' + (card.filled_by||'—'))}</td></tr>
    </table>
    ${extra.ink_colors || extra.impression_count ? `
    <div style="font-weight:800;font-size:.75rem;text-transform:uppercase;color:#64748b;margin-bottom:8px">Printing Data</div>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:16px">
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc;width:35%">Ink Colors</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.ink_colors||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Cylinder Ref</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.cylinder_ref||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Impressions</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.impression_count||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Speed</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.print_speed||'—')} m/min</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Color Match</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.color_match_status||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Wastage</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.wastage_meters||'—')} m</td></tr>
      ${extra.defects && extra.defects.length ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Defects</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${extra.defects.join(', ')}</td></tr>` : ''}
      ${extra.operator_notes ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Notes</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(extra.operator_notes)}</td></tr>` : ''}
    </table>` : ''}
    ${job.notes ? `<div style="font-size:.75rem;color:#475569;margin-bottom:16px;padding:10px;background:#f8fafc;border-radius:8px"><strong>Notes:</strong> ${esc(job.notes)}</div>` : ''}
    <div style="margin-top:40px;display:flex;justify-content:space-between;font-size:.7rem;color:#94a3b8">
      <div>Operator Signature: _____________________</div>
      <div>Supervisor Signature: _____________________</div>
    </div>
  </div>`;

  const w = window.open('', '_blank', 'width=800,height=900');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${html}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
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
  if (autoId) setTimeout(function(){ try { openPrintDetail(parseInt(autoId)); } catch(e){} }, 600);
})();
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
