<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Live Floor Plan';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$footerErpName = getErpDisplayName((string)$companyName);

$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

$stages = ['Planning', 'Slitting', 'Printing', 'Flat Binding', 'Packaging', 'Dispatch'];
$stageIcons = ['📋', '🔪', '🖨️', '📦', '📦', '🚚'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | <?= e($companyName) ?></title>
<style>
/* ── Reset ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

/* ── Base ── */
body{
  font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
  background:#f0f2f5;
  color:#1a1a2e;
  min-height:100vh;
}

/* ── Page Container ── */
.page{max-width:1500px;margin:0 auto;padding:24px 20px}

/* ═══════════════════════════════════════
   LIVE HEADER BAR
   ═══════════════════════════════════════ */
.live-header{
  background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
  color:#fff;
  border-radius:16px;
  padding:24px 36px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:28px;
  box-shadow:0 8px 32px rgba(15,52,96,.35);
  position:relative;
  overflow:hidden;
}
.live-header::before{
  content:'';position:absolute;top:-50%;right:-10%;width:300px;height:300px;
  background:radial-gradient(circle,rgba(255,255,255,.04) 0%,transparent 70%);
  border-radius:50%;
}
.lh-left h1{font-size:1.75rem;font-weight:800;letter-spacing:-.5px;display:flex;align-items:center;gap:12px}
.lh-left h1 span{font-size:1.6rem}
.lh-left p{opacity:.7;font-size:.82rem;margin-top:4px;font-weight:500}
.lh-right{text-align:right}
.lh-time{font-size:2.8rem;font-weight:900;font-family:'Courier New',monospace;letter-spacing:2px;line-height:1}
.lh-date{font-size:.88rem;opacity:.75;margin-top:6px;font-weight:500}
.lh-day{font-size:.78rem;opacity:.55;margin-top:2px;text-transform:uppercase;letter-spacing:1.5px}

/* live dot */
.live-dot{display:inline-block;width:10px;height:10px;background:#00e676;border-radius:50%;margin-right:8px;animation:liveBlink 1.2s ease-in-out infinite}
@keyframes liveBlink{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(0,230,118,.6)}50%{opacity:.6;box-shadow:0 0 0 8px rgba(0,230,118,0)}}

/* ═══════════════════════════════════════
   STATS BAR
   ═══════════════════════════════════════ */
.stats-bar{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:16px;
  margin-bottom:28px;
}
.stat-box{
  background:#fff;
  border-radius:14px;
  padding:20px 22px;
  box-shadow:0 2px 12px rgba(0,0,0,.06);
  border-bottom:4px solid #e0e0e0;
  transition:transform .2s;
}
.stat-box:hover{transform:translateY(-2px)}
.stat-box .label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#8892a4;margin-bottom:6px}
.stat-box .num{font-size:2rem;font-weight:900}
.stat-box.total{border-bottom-color:#1a1a2e}.stat-box.total .num{color:#1a1a2e}
.stat-box.pending{border-bottom-color:#ff9800}.stat-box.pending .num{color:#ff9800}
.stat-box.progress{border-bottom-color:#2196f3}.stat-box.progress .num{color:#2196f3}
.stat-box.done{border-bottom-color:#4caf50}.stat-box.done .num{color:#4caf50}

/* ═══════════════════════════════════════
   JOB CARDS CONTAINER
   ═══════════════════════════════════════ */
.jobs-list{display:flex;flex-direction:column;gap:24px}

.job-card{
  background:#fff;
  border-radius:16px;
  box-shadow:0 4px 20px rgba(0,0,0,.07);
  overflow:hidden;
  animation:cardSlideIn .4s ease-out both;
}
@keyframes cardSlideIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}

/* ── Job Card Header ── */
.jc-header{
  padding:20px 28px;
  text-align:center;
  border-bottom:1px solid #eef0f4;
  background:linear-gradient(180deg,#fafbfd,#fff);
}
.jc-jobno{font-size:1.15rem;font-weight:900;color:#1a1a2e;letter-spacing:.3px}
.jc-name{font-size:.88rem;color:#5a6478;margin-top:4px;font-weight:600}
.jc-details{font-size:.76rem;color:#94a3b8;margin-top:3px}
.jc-priority{
  display:inline-block;
  font-size:.65rem;
  font-weight:800;
  text-transform:uppercase;
  letter-spacing:1px;
  padding:3px 12px;
  border-radius:20px;
  margin-top:8px;
}
.jc-priority.urgent{background:#fde8e8;color:#dc2626}
.jc-priority.high{background:#fff3e0;color:#e65100}
.jc-priority.normal{background:#e8f5e9;color:#2e7d32}
.jc-priority.low{background:#e3f2fd;color:#1565c0}

/* ── Timeline Section ── */
.jc-timeline{
  padding:28px 28px 24px;
  overflow-x:auto;
  -webkit-overflow-scrolling:touch;
}
.jc-timeline::-webkit-scrollbar{height:4px}
.jc-timeline::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}

.timeline-track{
  display:flex;
  align-items:flex-start;
  justify-content:center;
  min-width:680px;
  position:relative;
  padding:0 10px;
}

/* ── Single Stage Node ── */
.tl-stage{
  display:flex;
  flex-direction:column;
  align-items:center;
  position:relative;
  flex:1;
  min-width:100px;
}

/* Connecting line between circles */
.tl-stage:not(:last-child)::after{
  content:'';
  position:absolute;
  top:24px;
  left:calc(50% + 24px);
  width:calc(100% - 48px);
  height:4px;
  background:#e0e0e0;
  z-index:1;
}
.tl-stage.completed:not(:last-child)::after{
  background:linear-gradient(90deg,#4caf50,#66bb6a);
}
.tl-stage.active:not(:last-child)::after{
  background:linear-gradient(90deg,#ff9800,#e0e0e0);
}

/* ── Circle Node ── */
.tl-circle{
  width:48px;height:48px;
  border-radius:50%;
  border:4px solid #e0e0e0;
  background:#f5f5f5;
  display:flex;align-items:center;justify-content:center;
  position:relative;
  z-index:5;
  transition:all .3s;
  font-size:1.1rem;
}

/* Completed stage */
.tl-stage.completed .tl-circle{
  border-color:#4caf50;
  background:#e8f5e9;
}
.tl-stage.completed .tl-circle::after{
  content:'✓';
  font-size:1.2rem;
  font-weight:900;
  color:#2e7d32;
  position:absolute;
}

/* Active stage — red border, yellow fill, pulse animation */
.tl-stage.active .tl-circle{
  border-color:#dc2626;
  background:#fdd835;
  box-shadow:0 0 0 0 rgba(220,38,38,.4);
  animation:pulseRipple 1.8s ease-out infinite;
}
@keyframes pulseRipple{
  0%{box-shadow:0 0 0 0 rgba(220,38,38,.45)}
  40%{box-shadow:0 0 0 14px rgba(220,38,38,.12)}
  100%{box-shadow:0 0 0 22px rgba(220,38,38,0)}
}
/* inner glow ring */
.tl-stage.active .tl-circle::before{
  content:'';
  position:absolute;
  inset:-8px;
  border-radius:50%;
  border:2px solid rgba(220,38,38,.2);
  animation:ringExpand 2s ease-out infinite;
}
@keyframes ringExpand{
  0%{transform:scale(.85);opacity:1}
  100%{transform:scale(1.35);opacity:0}
}

/* Inactive/future stage */
.tl-stage.inactive .tl-circle{
  border-color:#d6d9e0;
  background:#f0f0f0;
  opacity:.5;
}

/* ── Stage Label ── */
.tl-label{
  margin-top:12px;
  font-size:.78rem;
  font-weight:700;
  color:#5a6478;
  text-align:center;
  white-space:nowrap;
}
.tl-stage.active .tl-label{color:#dc2626;font-weight:800}
.tl-stage.completed .tl-label{color:#2e7d32}
.tl-stage.inactive .tl-label{color:#b0b8c8}

/* ── Time Below Label ── */
.tl-time{
  margin-top:4px;
  font-size:.72rem;
  font-weight:700;
  color:#4caf50;
  font-family:'Courier New',monospace;
}
.tl-stage.inactive .tl-time{color:#c8cdd6}
.tl-stage.active .tl-time{color:#ff6f00}

/* ═══════════════════════════════════════
   EMPTY STATE
   ═══════════════════════════════════════ */
.empty-state{
  text-align:center;padding:80px 20px;
  background:#fff;border-radius:16px;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
}
.empty-state .icon{font-size:4rem;opacity:.25;margin-bottom:16px}
.empty-state p{font-size:1rem;color:#94a3b8;font-weight:600}

/* ═══════════════════════════════════════
   LOADING SPINNER
   ═══════════════════════════════════════ */
.loader{text-align:center;padding:60px}
.loader .spinner{
  width:40px;height:40px;
  border:4px solid #e0e0e0;
  border-top-color:#1a1a2e;
  border-radius:50%;
  animation:spin .7s linear infinite;
  margin:0 auto 16px;
}
@keyframes spin{to{transform:rotate(360deg)}}
.loader p{color:#8892a4;font-size:.88rem;font-weight:600}

/* ═══════════════════════════════════════
   FOOTER LEGEND
   ═══════════════════════════════════════ */
.legend-bar{
  display:flex;gap:28px;flex-wrap:wrap;
  margin-top:24px;padding:16px 24px;
  background:#fff;border-radius:12px;
  box-shadow:0 2px 10px rgba(0,0,0,.04);
  font-size:.8rem;font-weight:600;color:#5a6478;
}
.lg-item{display:flex;align-items:center;gap:8px}
.lg-circle{width:18px;height:18px;border-radius:50%;border:3px solid}
.lg-circle.lg-done{border-color:#4caf50;background:#e8f5e9}
.lg-circle.lg-active{border-color:#dc2626;background:#fdd835}
.lg-circle.lg-future{border-color:#d6d9e0;background:#f0f0f0;opacity:.5}

/* ═══════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════ */
@media(max-width:900px){
  .stats-bar{grid-template-columns:repeat(2,1fr)}
  .live-header{flex-direction:column;gap:16px;text-align:center;padding:20px 24px}
  .lh-right{text-align:center}
}
@media(max-width:600px){
  .stats-bar{grid-template-columns:1fr}
  .page{padding:14px 10px}
  .lh-time{font-size:2rem}
  .lh-left h1{font-size:1.3rem}
  .jc-header{padding:16px 18px}
  .jc-timeline{padding:20px 14px}
}
</style>
</head>
<body>
<div class="page">

  <!-- ═══ LIVE HEADER ═══ -->
  <div class="live-header">
    <div class="lh-left">
      <h1><span>🏭</span> Live Floor Plan</h1>
      <p><span class="live-dot"></span>Real-time Production Monitoring</p>
    </div>
    <div class="lh-right">
      <div class="lh-time" id="liveTime">--:--:--</div>
      <div class="lh-date" id="liveDate"></div>
      <div class="lh-day" id="liveDay"></div>
    </div>
  </div>

  <!-- ═══ STATS BAR ═══ -->
  <div class="stats-bar">
    <div class="stat-box total"><div class="label">Total Jobs</div><div class="num" id="sTotal">0</div></div>
    <div class="stat-box pending"><div class="label">Pending</div><div class="num" id="sPending">0</div></div>
    <div class="stat-box progress"><div class="label">In Progress</div><div class="num" id="sProgress">0</div></div>
    <div class="stat-box done"><div class="label">Completed</div><div class="num" id="sDone">0</div></div>
  </div>

  <!-- ═══ JOB CARDS ═══ -->
  <div class="jobs-list" id="jobsList">
    <div class="loader"><div class="spinner"></div><p>Loading production data...</p></div>
  </div>

  <!-- ═══ LEGEND ═══ -->
  <div class="legend-bar">
    <div class="lg-item"><div class="lg-circle lg-done"></div> Completed Stage</div>
    <div class="lg-item"><div class="lg-circle lg-active"></div> Current Active (Pulse)</div>
    <div class="lg-item"><div class="lg-circle lg-future"></div> Upcoming Stage</div>
  </div>

</div>

<script>
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const CSRF     = '<?= e($csrf) ?>';
const STAGES   = <?= json_encode($stages) ?>;

/* ─── Live Clock ─── */
function tickClock(){
  const n = new Date();
  document.getElementById('liveTime').textContent = n.toLocaleTimeString('en-IN',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('liveDate').textContent = n.toLocaleDateString('en-IN',{year:'numeric',month:'long',day:'numeric'});
  document.getElementById('liveDay').textContent  = n.toLocaleDateString('en-IN',{weekday:'long'});
}
tickClock();
setInterval(tickClock, 1000);

/* ─── Determine which stage index a job is at ─── */
function getStageIndex(job){
  const s  = String(job.status||'').toLowerCase();
  const ps = String(job.planning_status||'').toLowerCase();

  // Dispatch — terminal statuses
  if(['closed','finalized'].includes(s))                       return 5; // Dispatch
  if(s==='completed' || s==='qc passed' || s==='qc failed')   return 5;

  // Packaging
  if(ps.includes('packaging') || ps.includes('packing'))        return 4;

  // Flat Binding
  if(ps.includes('binding') || ps.includes('flat'))              return 3;

  // Printing
  if(ps.includes('printing'))                                    return 2;

  // Running (active on machine = Slitting for Jumbo)
  if(s==='running')                                              return 1;

  // Slitting prep
  if(ps.includes('slitting') || ps.includes('preparing'))        return 1;

  // Queued waits at slitting
  if(s==='queued')                                               return 1;

  // Default → Planning
  return 0;
}

/* ─── Is the stage actually completed? ─── */
function isStageCompleted(job, stageIdx){
  return stageIdx < getStageIndex(job);
}

/* ─── Is the stage the currently active one? ─── */
function isStageActive(job, stageIdx){
  return stageIdx === getStageIndex(job);
}

/* ─── Extract a display time for a stage ─── */
function getStageTime(job, stageIdx){
  const current = getStageIndex(job);
  if(stageIdx > current) return '—';

  // For the current active stage, show started_at or updated_at time
  if(stageIdx === current){
    const t = job.started_at || job.updated_at;
    return t ? fmtTime(t) : 'Now';
  }
  // For completed stages, show created/updated
  if(stageIdx === 0){
    return job.created_at ? fmtTime(job.created_at) : '—';
  }
  // Otherwise show updated_at as best approximation
  return job.updated_at ? fmtTime(job.updated_at) : '—';
}

function fmtTime(dt){
  try{
    const d = new Date(dt);
    if(isNaN(d)) return '—';
    return d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:false});
  }catch(e){return '—';}
}

/* ─── Fetch & Render ─── */
async function loadFloorPlan(){
  try{
    const params = new URLSearchParams({action:'list_jobs',csrf_token:CSRF,job_type:'Slitting',limit:'500'});
    const res  = await fetch(API_BASE+'?'+params.toString());
    const data = await res.json();
    if(!data.ok||!data.jobs) throw new Error('API error');

    // Filter only active/today's jobs (not soft-deleted, not old completed)
    const today = new Date(); today.setHours(0,0,0,0);
    const jobs = data.jobs.filter(j=>{
      if(j.deleted_at) return false;
      // Show running / pending always
      if(['Pending','Running','Queued'].includes(j.status)) return true;
      // Show completed/closed only if from today
      if(j.completed_at){
        const cd = new Date(j.completed_at); cd.setHours(0,0,0,0);
        return cd.getTime()===today.getTime();
      }
      return true;
    });

    renderJobs(jobs);
    renderStats(jobs);
  }catch(err){
    document.getElementById('jobsList').innerHTML =
      '<div class="empty-state"><div class="icon">⚠️</div><p>Unable to load floor plan data</p></div>';
  }
}

function renderJobs(jobs){
  const container = document.getElementById('jobsList');

  if(!jobs.length){
    container.innerHTML = '<div class="empty-state"><div class="icon">🏭</div><p>No active jobs on the floor right now</p></div>';
    return;
  }

  // Sort: active (running) first, then by stage descending, then by priority
  const priorityOrder = {Urgent:0,High:1,Normal:2,Low:3};
  jobs.sort((a,b)=>{
    const sa = getStageIndex(a), sb = getStageIndex(b);
    const runA = a.status==='Running'?0:1, runB = b.status==='Running'?0:1;
    if(runA!==runB) return runA-runB;
    if(sa!==sb) return sb-sa;
    return (priorityOrder[a.planning_priority]||2) - (priorityOrder[b.planning_priority]||2);
  });

  let html = '';
  jobs.forEach((job,i)=>{
    const currentStage = getStageIndex(job);
    const priority = (job.planning_priority||'Normal').toLowerCase();

    // Build timeline nodes
    let nodesHtml = '';
    STAGES.forEach((stage, idx)=>{
      let cls = 'inactive';
      if(idx < currentStage) cls = 'completed';
      else if(idx === currentStage) cls = 'active';

      const time = getStageTime(job, idx);

      nodesHtml += `
        <div class="tl-stage ${cls}">
          <div class="tl-circle"></div>
          <div class="tl-label">${stage}</div>
          <div class="tl-time">${time}</div>
        </div>`;
    });

    // Job details line
    let details = [];
    if(job.roll_no) details.push('Roll: '+job.roll_no);
    if(job.paper_type) details.push(job.paper_type);
    if(job.gsm) details.push(job.gsm+'gsm');
    const detailStr = details.join(' • ') || 'Jumbo Slitting Job';

    html += `
      <div class="job-card" style="animation-delay:${i*0.06}s">
        <div class="jc-header">
          <div class="jc-jobno">${escHtml(job.job_no)}</div>
          <div class="jc-name">${escHtml(job.planning_job_name||'Job Card')}</div>
          <div class="jc-details">${escHtml(detailStr)}</div>
          ${priority!=='normal'?`<div class="jc-priority ${priority}">${priority}</div>`:''}
        </div>
        <div class="jc-timeline">
          <div class="timeline-track">${nodesHtml}</div>
        </div>
      </div>`;
  });

  container.innerHTML = html;
}

function renderStats(jobs){
  const total    = jobs.length;
  const pending  = jobs.filter(j=>['Pending','Queued'].includes(j.status)).length;
  const progress = jobs.filter(j=>j.status==='Running').length;
  const done     = jobs.filter(j=>['Closed','Finalized','Completed','QC Passed'].includes(j.status)).length;

  document.getElementById('sTotal').textContent    = total;
  document.getElementById('sPending').textContent  = pending;
  document.getElementById('sProgress').textContent = progress;
  document.getElementById('sDone').textContent     = done;
}

function escHtml(s){
  const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;
}

/* ─── Init ─── */
loadFloorPlan();
setInterval(loadFloorPlan, 60000); // soft refresh every 60 seconds

/* ─── Midnight full reload ─── */
(function scheduleMidnight(){
  const now = new Date();
  const mid = new Date(now); mid.setHours(24,0,0,0);
  const ms  = mid.getTime()-now.getTime();
  setTimeout(()=>{ location.reload(); }, ms);
})();
</script>
</body>
</html>
