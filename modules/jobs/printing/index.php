<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle = 'Flexo Printing Jobs';
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
  <span>Flexo Printing</span>
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

@media print{.no-print,.breadcrumb,.page-header,.fp-modal-overlay{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:600px){.fp-grid{grid-template-columns:1fr}.fp-stats{grid-template-columns:repeat(2,1fr)}.fp-detail-grid{grid-template-columns:1fr}.fp-form-row{grid-template-columns:1fr}}
</style>

<div class="fp-header no-print">
  <div>
    <h1><i class="bi bi-printer"></i> Flexo Printing Jobs
      <?php if ($notifCount > 0): ?><span class="fp-notif-badge"><?= $notifCount ?></span><?php endif; ?>
    </h1>
    <div class="fp-header-meta">Auto-generated for printing after slitting &middot; Sequential gating from Jumbo Slitting</div>
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

<div class="fp-filters no-print">
  <input type="text" class="fp-search" id="fpSearch" placeholder="Search by job no, roll, company&hellip;">
  <button class="fp-filter-btn active" onclick="filterFP('all',this)">All</button>
  <button class="fp-filter-btn" onclick="filterFP('Queued',this)">Queued</button>
  <button class="fp-filter-btn" onclick="filterFP('Pending',this)">Pending</button>
  <button class="fp-filter-btn" onclick="filterFP('Running',this)">Running</button>
  <button class="fp-filter-btn" onclick="filterFP('Completed',this)">Completed</button>
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
  <div class="fp-card <?= $isQueued ? 'fp-queued' : '' ?>" data-status="<?= e($sts) ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openPrintDetail(<?= $job['id'] ?>)">
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
      <div style="display:flex;gap:6px" onclick="event.stopPropagation()">
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

<script>
const CSRF = '<?= e($csrf) ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode($jobs, JSON_HEX_TAG|JSON_HEX_APOS) ?>;

function resolvePrintDisplayName(job) {
  if (job && String(job.display_job_name || '').trim() !== '') return String(job.display_job_name).trim();
  if (job && String(job.planning_job_name || '').trim() !== '') return String(job.planning_job_name).trim();
  const jobNo = String(job?.job_no || '').trim();
  const dept = String(job?.department || 'flexo_printing').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  if (jobNo !== '') return `${jobNo} (${dept})`;
  return dept || '—';
}

// ─── Filters ────────────────────────────────────────────────
function filterFP(status, btn) {
  document.querySelectorAll('.fp-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.fp-card').forEach(card => {
    card.style.display = (status === 'all' || card.dataset.status === status) ? '' : 'none';
  });
}

document.getElementById('fpSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.fp-card').forEach(card => {
    card.style.display = (card.dataset.search || '').includes(q) ? '' : 'none';
  });
});

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
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateFPStatus(id, 'Completed');

  const extraData = {
    ink_colors: form.querySelector('[name=ink_colors]')?.value || '',
    cylinder_ref: form.querySelector('[name=cylinder_ref]')?.value || '',
    impression_count: form.querySelector('[name=impression_count]')?.value || '',
    print_speed: form.querySelector('[name=print_speed]')?.value || '',
    color_match_status: form.querySelector('[name=color_match_status]')?.value || 'Matched',
    wastage_meters: form.querySelector('[name=wastage_meters]')?.value || '',
    operator_notes: form.querySelector('[name=operator_notes]')?.value || '',
    defects: Array.from(form.querySelectorAll('[name=defects]:checked')).map(c=>c.value)
  };

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
  if (extra.ink_colors || extra.cylinder_ref || extra.impression_count || extra.operator_notes) {
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
  if (sts === 'Running' || mode === 'complete') {
    html += `<div class="fp-detail-section"><h3><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</h3>
    <form id="dm-operator-form">
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
  fHtml += `<button class="fp-action-btn fp-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
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
function printJobCard(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const extra = job.extra_data_parsed || {};
  const created = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const started = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;

  const html = `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:24px;max-width:700px;margin:0 auto">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:3px solid #8b5cf6;padding-bottom:12px">
      <div>${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:40px;margin-bottom:4px;display:block">` : ''}
        <div style="font-weight:900;font-size:1.1rem">${esc(COMPANY.name)}</div>
        <div style="font-size:.7rem;color:#64748b">${esc(COMPANY.address)}</div>
        ${COMPANY.gst ? `<div style="font-size:.65rem;color:#94a3b8">GST: ${esc(COMPANY.gst)}</div>` : ''}
      </div>
      <div style="text-align:right">
        <div style="font-size:1.2rem;font-weight:900;color:#8b5cf6">${esc(job.job_no)}</div>
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b">Flexo Printing Job Card</div>
        <div style="font-size:.65rem;color:#94a3b8;margin-top:4px">${created}</div>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:16px">
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc;width:35%">Job Name</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(resolvePrintDisplayName(job))}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Roll No</td><td style="padding:6px 10px;border:1px solid #e2e8f0;color:#8b5cf6;font-weight:700">${esc(job.roll_no||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Material / GSM</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.paper_type||'—')} / ${esc(job.gsm||'—')} GSM</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Dimension</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc((job.width_mm||'—')+'mm × '+(job.length_mtr||'—')+'m')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Weight</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${job.weight_kg ? job.weight_kg+' kg' : '—'}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Supplier</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.company||'—')}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Status</td><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700">${esc(job.status)}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Started</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${started}</td></tr>
      <tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Completed</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${completed}</td></tr>
      ${dur !== null && dur !== undefined ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Duration</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${Math.floor(dur/60)}h ${dur%60}m</td></tr>` : ''}
      ${job.prev_job_no ? `<tr><td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:700;background:#f8fafc">Slitting Job</td><td style="padding:6px 10px;border:1px solid #e2e8f0">${esc(job.prev_job_no)} (${esc(job.prev_job_status||'—')})</td></tr>` : ''}
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

function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
