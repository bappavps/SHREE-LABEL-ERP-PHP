<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Live Floor';
$db = getDB();

// Fetch all active jobs grouped by status (not deleted, not old completed)
$activeJobs = $db->query("
    SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm,
           p.job_name AS planning_job_name, p.priority AS planning_priority,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
      AND j.status IN ('Queued','Pending','Running','Completed')
    ORDER BY
      FIELD(j.status, 'Running','Pending','Queued','Completed'),
      j.updated_at DESC
    LIMIT 300
")->fetch_all(MYSQLI_ASSOC);

// Parse extra_data
foreach ($activeJobs as &$j) {
    $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
}
unset($j);

// Group by department × status
$columns = [
    'slitting' => ['label' => 'Jumbo Slitting', 'icon' => 'bi-boxes', 'color' => '#22c55e', 'jobs' => []],
    'printing' => ['label' => 'Flexo Printing', 'icon' => 'bi-printer', 'color' => '#8b5cf6', 'jobs' => []],
];
foreach ($activeJobs as $j) {
    $dept = ($j['job_type'] === 'Slitting') ? 'slitting' : 'printing';
    $columns[$dept]['jobs'][] = $j;
}

// Counts
$runningCount = count(array_filter($activeJobs, fn($j) => $j['status'] === 'Running'));
$pendingCount = count(array_filter($activeJobs, fn($j) => $j['status'] === 'Pending'));
$queuedCount = count(array_filter($activeJobs, fn($j) => $j['status'] === 'Queued'));
$completedToday = count(array_filter($activeJobs, fn($j) => $j['status'] === 'Completed' && date('Y-m-d', strtotime($j['completed_at'] ?? '')) === date('Y-m-d')));

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Live Floor</span>
</div>

<style>
:root{--lf-green:#22c55e;--lf-purple:#8b5cf6;--lf-blue:#3b82f6;--lf-orange:#f97316;--lf-red:#ef4444}
.lf-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.lf-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.lf-header h1 i{font-size:1.6rem;color:var(--lf-blue)}
.lf-header-meta{font-size:.75rem;color:#64748b;font-weight:600}
.lf-live-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;animation:lf-pulse 1.5s infinite}
@keyframes lf-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
.lf-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.lf-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.lf-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.lf-stat-val{font-size:1.5rem;font-weight:900;line-height:1}
.lf-stat-label{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.lf-board{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;align-items:start}
.lf-column{background:#f8fafc;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
.lf-col-head{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border,#e2e8f0)}
.lf-col-title{font-weight:900;font-size:.9rem;display:flex;align-items:center;gap:8px}
.lf-col-count{background:#e2e8f0;color:#475569;font-size:.6rem;font-weight:800;padding:2px 8px;border-radius:10px}
.lf-col-body{padding:12px;display:flex;flex-direction:column;gap:10px;min-height:100px;max-height:70vh;overflow-y:auto}
.lf-col-body::-webkit-scrollbar{width:4px}.lf-col-body::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}

/* Status sub-headers */
.lf-status-divider{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;padding:8px 4px 4px;display:flex;align-items:center;gap:6px}
.lf-status-divider::after{content:'';flex:1;height:1px;background:#e2e8f0}

/* Job mini cards */
.lf-job{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:12px 14px;transition:all .15s;cursor:pointer;position:relative}
.lf-job:hover{box-shadow:0 4px 12px rgba(0,0,0,.06);transform:translateY(-1px)}
.lf-job.lf-running{border-left:3px solid var(--lf-blue);background:linear-gradient(135deg,#eff6ff,#fff)}
.lf-job.lf-pending{border-left:3px solid #f59e0b}
.lf-job.lf-queued{border-left:3px solid #94a3b8;opacity:.65}
.lf-job.lf-completed{border-left:3px solid #22c55e;opacity:.7}
.lf-job-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.lf-job-no{font-weight:900;font-size:.78rem;color:#0f172a}
.lf-badge{display:inline-flex;padding:2px 8px;border-radius:12px;font-size:.55rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.lf-badge-running{background:#dbeafe;color:#1e40af;animation:lf-pulse-badge 2s infinite}
.lf-badge-pending{background:#fef3c7;color:#92400e}
.lf-badge-queued{background:#f1f5f9;color:#64748b}
.lf-badge-completed{background:#dcfce7;color:#166534}
@keyframes lf-pulse-badge{0%,100%{opacity:1}50%{opacity:.5}}
.lf-job-info{font-size:.7rem;color:#64748b;line-height:1.5}
.lf-job-info strong{color:#1e293b;font-weight:700}
.lf-job-timer{font-family:'Courier New',monospace;font-size:.7rem;font-weight:800;color:var(--lf-blue);margin-top:4px;display:flex;align-items:center;gap:4px}
.lf-job-timer i{font-size:.6rem}
.lf-job-dur{font-size:.65rem;color:#22c55e;font-weight:700;margin-top:2px}
.lf-job-gate{font-size:.55rem;color:#f59e0b;font-weight:700;display:flex;align-items:center;gap:3px;margin-top:4px}
.lf-job-pri{position:absolute;top:8px;right:8px}
.lf-badge-urgent{background:#fee2e2;color:#991b1b;font-size:.5rem;padding:1px 6px}
.lf-badge-high{background:#ffedd5;color:#9a3412;font-size:.5rem;padding:1px 6px}
.lf-empty{padding:30px;text-align:center;color:#94a3b8;font-size:.75rem;font-weight:600}
.lf-empty i{font-size:1.5rem;display:block;margin-bottom:8px;opacity:.3}

/* Filters */
.lf-filters{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
.lf-filter-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:1px solid #e2e8f0;background:#fff;border-radius:20px;cursor:pointer;color:#64748b;transition:all .15s}
.lf-filter-btn.active{background:var(--lf-blue);border-color:var(--lf-blue);color:#fff}
.lf-auto-refresh{font-size:.65rem;color:#94a3b8;font-weight:600;display:flex;align-items:center;gap:4px;margin-left:auto}

@media(max-width:600px){.lf-board{grid-template-columns:1fr}.lf-stats{grid-template-columns:repeat(2,1fr)}}
@media print{.no-print,.breadcrumb{display:none!important}}
</style>

<div class="lf-header no-print">
  <div>
    <h1><i class="bi bi-activity"></i> Live Floor <span class="lf-live-dot"></span></h1>
    <div class="lf-header-meta">Real-time production kanban &middot; All departments &middot; Auto-refreshes every 30s</div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="lf-filter-btn" onclick="location.reload()" style="background:#f1f5f9"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<div class="lf-stats no-print">
  <div class="lf-stat">
    <div class="lf-stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="bi bi-play-circle-fill"></i></div>
    <div><div class="lf-stat-val"><?= $runningCount ?></div><div class="lf-stat-label">Running Now</div></div>
  </div>
  <div class="lf-stat">
    <div class="lf-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="lf-stat-val"><?= $pendingCount ?></div><div class="lf-stat-label">Pending</div></div>
  </div>
  <div class="lf-stat">
    <div class="lf-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="lf-stat-val"><?= $queuedCount ?></div><div class="lf-stat-label">Queued</div></div>
  </div>
  <div class="lf-stat">
    <div class="lf-stat-icon" style="background:#dcfce7;color:#22c55e"><i class="bi bi-check-circle-fill"></i></div>
    <div><div class="lf-stat-val"><?= $completedToday ?></div><div class="lf-stat-label">Done Today</div></div>
  </div>
</div>

<div class="lf-filters no-print">
  <button class="lf-filter-btn active" onclick="filterFloor('all',this)">All</button>
  <button class="lf-filter-btn" onclick="filterFloor('Running',this)">Running</button>
  <button class="lf-filter-btn" onclick="filterFloor('Pending',this)">Pending</button>
  <button class="lf-filter-btn" onclick="filterFloor('Queued',this)">Queued</button>
  <button class="lf-filter-btn" onclick="filterFloor('Completed',this)">Completed</button>
  <div class="lf-auto-refresh"><i class="bi bi-arrow-repeat"></i> Auto-refresh: <span id="lfCountdown">30</span>s</div>
</div>

<div class="lf-board" id="lfBoard">
<?php foreach ($columns as $deptKey => $col):
    $deptJobs = $col['jobs'];
    $groups = ['Running'=>[],'Pending'=>[],'Queued'=>[],'Completed'=>[]];
    foreach ($deptJobs as $j) {
        $s = $j['status'];
        if (isset($groups[$s])) $groups[$s][] = $j;
    }
?>
  <div class="lf-column">
    <div class="lf-col-head" style="border-top:3px solid <?= $col['color'] ?>">
      <div class="lf-col-title" style="color:<?= $col['color'] ?>"><i class="bi <?= $col['icon'] ?>"></i> <?= $col['label'] ?></div>
      <span class="lf-col-count"><?= count($deptJobs) ?> jobs</span>
    </div>
    <div class="lf-col-body">
      <?php if (empty($deptJobs)): ?>
        <div class="lf-empty"><i class="bi bi-inbox"></i>No active jobs</div>
      <?php else: ?>
        <?php foreach ($groups as $statusKey => $sJobs):
            if (empty($sJobs)) continue;
            $sClass = strtolower($statusKey);
        ?>
          <div class="lf-status-divider" data-status="<?= $statusKey ?>"><?= $statusKey ?> (<?= count($sJobs) ?>)</div>
          <?php foreach ($sJobs as $j):
              $pri = $j['planning_priority'] ?? 'Normal';
              $startedTs = $j['started_at'] ? strtotime($j['started_at']) * 1000 : 0;
              $dur = $j['duration_minutes'] ?? null;
              $prevNo = $j['prev_job_no'] ?? null;
              $prevSts = $j['prev_job_status'] ?? null;
              $linkUrl = ($j['job_type'] === 'Slitting')
                ? BASE_URL . '/modules/jobs/jumbo/index.php'
                : BASE_URL . '/modules/jobs/printing/index.php';
          ?>
          <div class="lf-job lf-<?= $sClass ?>" data-status="<?= $statusKey ?>" onclick="window.location='<?= $linkUrl ?>'">
            <div class="lf-job-top">
              <span class="lf-job-no" style="color:<?= $col['color'] ?>"><?= e($j['job_no']) ?></span>
              <span class="lf-badge lf-badge-<?= $sClass ?>"><?= $statusKey ?></span>
            </div>
            <?php if ($pri !== 'Normal'): ?>
              <div class="lf-job-pri"><span class="lf-badge lf-badge-<?= strtolower($pri) ?>"><?= $pri ?></span></div>
            <?php endif; ?>
            <div class="lf-job-info">
              <?php if ($j['planning_job_name']): ?><strong><?= e($j['planning_job_name']) ?></strong><br><?php endif; ?>
              <?= e($j['roll_no'] ?? '—') ?> &middot; <?= e($j['paper_type'] ?? '') ?> &middot; <?= e(($j['width_mm'] ?? '').'mm') ?>
            </div>
            <?php if ($statusKey === 'Running' && $startedTs): ?>
              <div class="lf-job-timer"><i class="bi bi-stopwatch"></i> <span class="lf-timer" data-started="<?= $startedTs ?>">00:00:00</span></div>
            <?php elseif ($statusKey === 'Completed' && $dur !== null): ?>
              <div class="lf-job-dur"><i class="bi bi-check"></i> <?= floor($dur/60) ?>h <?= $dur%60 ?>m</div>
            <?php endif; ?>
            <?php if ($statusKey === 'Queued' && $prevNo): ?>
              <div class="lf-job-gate"><i class="bi bi-lock-fill"></i> Waiting: <?= e($prevNo) ?> (<?= e($prevSts) ?>)</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<script>
// ─── Live timers ────────────────────────────────────────────
function updateTimers() {
  document.querySelectorAll('.lf-timer[data-started]').forEach(el => {
    const s = parseInt(el.dataset.started);
    if (!s) return;
    const d = Math.floor((Date.now() - s) / 1000);
    el.textContent = String(Math.floor(d/3600)).padStart(2,'0') + ':' + String(Math.floor((d%3600)/60)).padStart(2,'0') + ':' + String(d%60).padStart(2,'0');
  });
}
setInterval(updateTimers, 1000);
updateTimers();

// ─── Filter ─────────────────────────────────────────────────
function filterFloor(status, btn) {
  document.querySelectorAll('.lf-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.lf-job').forEach(j => {
    j.style.display = (status === 'all' || j.dataset.status === status) ? '' : 'none';
  });
  document.querySelectorAll('.lf-status-divider').forEach(d => {
    d.style.display = (status === 'all' || d.dataset.status === status) ? '' : 'none';
  });
}

// ─── Auto refresh countdown ─────────────────────────────────
let countdown = 30;
const cdEl = document.getElementById('lfCountdown');
setInterval(() => {
  countdown--;
  if (cdEl) cdEl.textContent = countdown;
  if (countdown <= 0) location.reload();
}, 1000);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
