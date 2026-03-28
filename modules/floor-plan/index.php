<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Live Floor Plan';
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

$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

// Stages in order
$stages = ['Planning', 'Slitting', 'Printing', 'Flat Binding', 'Packaging', 'Dispatch', 'QC'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> | <?= e($companyName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --brand: #1e40af; --brand-dark: #1e3a8a; --success: #16a34a; --warning: #ea580c; --danger: #dc2626; --info: #0891b2; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f9ff, #e0f2fe); color: #1e293b; line-height: 1.6; overflow-x: hidden; }
    
    .container { max-width: 1600px; margin: 0 auto; padding: 20px; }
    
    .header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: white; padding: 25px 30px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 8px 24px rgba(30,64,175,.2); }
    .header { display: flex; justify-content: space-between; align-items: center; }
    .header-left h1 { font-size: 2.2rem; font-weight: 900; display: flex; align-items: center; gap: 15px; margin-bottom: 8px; }
    .header-left h1 i { font-size: 2.8rem; }
    .header-time { font-size: 2.5rem; font-weight: 800; font-family: 'Courier New', monospace; }
    .header-date { font-size: .95rem; opacity: .9; margin-top: 5px; }
    
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
    .stat-card { background: white; padding: 20px; border-radius: 12px; border-left: 5px solid var(--brand); box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .stat-card h3 { font-size: .75rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }
    .stat-card .value { font-size: 2.2rem; font-weight: 900; color: var(--brand); }
    .stat-card.pending { border-left-color: var(--warning); }
    .stat-card.pending .value { color: var(--warning); }
    .stat-card.running { border-left-color: var(--info); }
    .stat-card.running .value { color: var(--info); }
    .stat-card.completed { border-left-color: var(--success); }
    .stat-card.completed .value { color: var(--success); }

    .floor-plan { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 8px 24px rgba(0,0,0,.1); }
    .floor-plan-title { font-size: 1.3rem; font-weight: 900; margin-bottom: 30px; color: var(--brand); display: flex; align-items: center; gap: 12px; }
    .floor-plan-title i { font-size: 1.8rem; }

    .stages-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
    .stage { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 12px; padding: 20px; border-top: 4px solid var(--brand); min-height: 400px; }
    .stage-title { font-size: 1.1rem; font-weight: 900; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; color: var(--brand); }
    .stage-count { display: inline-block; background: var(--brand); color: white; padding: 2px 10px; border-radius: 999px; font-size: .75rem; font-weight: 800; }
    
    .jobs { display: flex; flex-direction: column; gap: 12px; }
    .job-item { background: white; border-radius: 10px; padding: 15px; border-left: 4px solid var(--info); box-shadow: 0 2px 8px rgba(0,0,0,.06); animation: slideIn .3s ease-out; transition: all .2s; }
    .job-item:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,.12); }
    .job-item-no { font-size: .9rem; font-weight: 900; color: var(--brand); word-break: break-word; margin-bottom: 6px; }
    .job-item-name { font-size: .8rem; color: #64748b; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .job-item-meta { display: flex; gap: 8px; font-size: .7rem; color: #94a3b8; }
    .job-item-meta span { display: flex; align-items: center; gap: 3px; }
    
    .job-item.running { border-left-color: var(--info); }
    .job-item.pending { border-left-color: var(--warning); background: #fffbeb; }
    .job-item.completed { border-left-color: var(--success); background: #f0fdf4; }
    
    @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
    
    .running-indicator { display: inline-block; width: 8px; height: 8px; background: var(--info); border-radius: 50%; animation: pulse .8s infinite; margin-right: 4px; }
    
    .empty { text-align: center; padding: 40px 20px; color: #94a3b8; }
    .empty i { font-size: 2.5rem; opacity: .3; margin-bottom: 10px; }

    .refresh-btn { padding: 10px 20px; background: var(--brand); color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; margin-top: 20px; }
    .refresh-btn:hover { background: var(--brand-dark); }
    .refresh-btn.loading { opacity: .7; pointer-events: none; animation: spin .6s linear infinite; }
    
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    .legend { background: white; border-radius: 12px; padding: 15px; margin-top: 20px; display: flex; gap: 20px; flex-wrap: wrap; font-size: .85rem; }
    .legend-item { display: flex; align-items: center; gap: 8px; }
    .legend-box { width: 16px; height: 16px; border-left: 3px solid; border-radius: 2px; }

    @media (max-width: 1024px) {
      .stages-container { grid-template-columns: repeat(2, 1fr); }
      .header { flex-direction: column; gap: 20px; text-align: center; }
      .header-left h1 { justify-content: center; }
    }

    @media (max-width: 640px) {
      .stages-container { grid-template-columns: 1fr; }
      .header { padding: 20px; }
      .header-left h1 { font-size: 1.5rem; }
      .header-time { font-size: 2rem; }
      .stats { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="header-left">
      <h1><i class="bi bi-diagram-3"></i> Live Floor Plan</h1>
      <p style="opacity:.9;margin:0">Real-time production status - <?= date('l, F j, Y') ?></p>
    </div>
    <div style="text-align:right">
      <div class="header-time" id="currentTime">00:00:00</div>
      <div class="header-date" id="currentDate"></div>
    </div>
  </div>

  <div class="stats" id="statsContainer">
    <div class="stat-card"><h3>Total Active</h3><div class="value" id="statTotal">0</div></div>
    <div class="stat-card pending"><h3>Pending</h3><div class="value" id="statPending">0</div></div>
    <div class="stat-card running"><h3>In Progress</h3><div class="value" id="statRunning">0</div></div>
    <div class="stat-card completed"><h3>Completed</h3><div class="value" id="statCompleted">0</div></div>
  </div>

  <div class="floor-plan">
    <div class="floor-plan-title"><i class="bi bi-bullseye"></i> Production Flow</div>
    <div class="stages-container" id="stagesContainer"></div>
  </div>

  <button class="refresh-btn" onclick="loadFloorPlan()"><i class="bi bi-arrow-clockwise"></i> Refresh Now</button>

  <div class="legend">
    <div class="legend-item"><div class="legend-box" style="border-left-color:var(--warning)"></div> Pending</div>
    <div class="legend-item"><div class="legend-box" style="border-left-color:var(--info)"></div> Running/In Progress</div>
    <div class="legend-item"><div class="legend-box" style="border-left-color:var(--success)"></div> Completed</div>
    <div class="legend-item"><i class="bi bi-circle-fill" style="color:var(--info);animation:pulse .8s infinite;font-size:.5rem;margin:0 4px"></i> Currently Running</div>
  </div>
</div>

<script>
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const CSRF = '<?= e($csrf) ?>';
const STAGES = <?= json_encode($stages) ?>;
let refreshInterval;

// Update time
function updateTime() {
  const now = new Date();
  document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-IN', { hour12: false });
  document.getElementById('currentDate').textContent = now.toLocaleDateString('en-IN', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
}

async function loadFloorPlan() {
  const btn = document.querySelector('.refresh-btn');
  btn.classList.add('loading');
  
  try {
    const params = new URLSearchParams({ action: 'list_jobs', csrf_token: CSRF, job_type: 'Slitting', limit: 500 });
    const res = await fetch(API_BASE + '?' + params.toString());
    const data = await res.json();
    
    if (!data.ok || !data.jobs) throw new Error('Failed to load jobs');
    
    const jobs = data.jobs.sort((a, b) => {
      const stageA = getJobStage(a);
      const stageB = getJobStage(b);
      return STAGES.indexOf(stageA) - STAGES.indexOf(stageB);
    });

    renderFloorPlan(jobs);
    updateStats(jobs);
  } catch (err) {
    document.getElementById('stagesContainer').innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>Error loading floor plan: ' + err.message + '</p></div>';
  } finally {
    btn.classList.remove('loading');
  }
}

function getJobStage(job) {
  const status = String(job.status || 'Pending').toLowerCase();
  const planStatus = String(job.planning_status || '').toLowerCase();
  
  if (planStatus.includes('planning') || status === 'pending') return 'Planning';
  if (planStatus.includes('slitting') || planStatus.includes('preparing')) return 'Slitting';
  if (planStatus.includes('printing')) return 'Printing';
  if (planStatus.includes('binding') || planStatus.includes('flat')) return 'Flat Binding';
  if (planStatus.includes('packaging')) return 'Packaging';
  if (status === 'closed' || status === 'finalized') return 'Dispatch';
  if (planStatus.includes('qc') || status === 'qc') return 'QC';
  
  return 'Planning';
}

function getJobStatus(job) {
  if (job.status === 'Running') return 'running';
  if (['Closed', 'Finalized', 'Completed', 'QC Passed'].includes(job.status)) return 'completed';
  return 'pending';
}

function renderFloorPlan(jobs) {
  const grouped = {};
  STAGES.forEach(stage => grouped[stage] = []);
  
  jobs.forEach(job => {
    const stage = getJobStage(job);
    if (grouped[stage]) grouped[stage].push(job);
  });

  const html = STAGES.map(stage => {
    const stageJobs = grouped[stage] || [];
    const jobsHtml = stageJobs.length ? stageJobs.map(job => {
      const status = getJobStatus(job);
      const isRunning = job.started_at && !job.completed_at;
      return `<div class="job-item ${status}">
        <div class="job-item-no">${isRunning ? '<span class="running-indicator"></span>' : ''}${job.job_no}</div>
        <div class="job-item-name">${job.planning_job_name || 'Job Card'}</div>
        <div class="job-item-meta">
          <span><i class="bi bi-circle-fill" style="font-size:.4rem"></i> ${job.planning_priority || 'Normal'}</span>
          <span><i class="bi bi-calendar"></i> ${job.created_at ? new Date(job.created_at).toLocaleDateString('en-IN', { month: 'short', day: 'numeric' }) : '—'}</span>
        </div>
      </div>`;
    }).join('') : '<div class="empty"><i class="bi bi-inbox"></i><p>No jobs</p></div>';

    return `<div class="stage">
      <div class="stage-title">
        <i class="bi bi-circle-fill" style="font-size:.6rem"></i>
        ${stage}
        <span class="stage-count">${stageJobs.length}</span>
      </div>
      <div class="jobs">${jobsHtml}</div>
    </div>`;
  }).join('');

  document.getElementById('stagesContainer').innerHTML = html;
}

function updateStats(jobs) {
  const total = jobs.length;
  const pending = jobs.filter(j => getJobStatus(j) === 'pending').length;
  const running = jobs.filter(j => j.started_at && !j.completed_at).length;
  const completed = jobs.filter(j => getJobStatus(j) === 'completed').length;

  document.getElementById('statTotal').textContent = total;
  document.getElementById('statPending').textContent = pending;
  document.getElementById('statRunning').textContent = running;
  document.getElementById('statCompleted').textContent = completed;
}

// Initialize
updateTime();
setInterval(updateTime, 1000);
loadFloorPlan();
refreshInterval = setInterval(loadFloorPlan, 15000); // Auto-refresh every 15 seconds
</script>
</body>
</html>
