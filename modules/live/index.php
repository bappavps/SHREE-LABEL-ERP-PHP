<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Live Floor';
$db = getDB();

$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;

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
/* ═══════════════════════════════════════════════════════════
   LIVE FLOOR — HORIZONTAL TIMELINE UI  (Complete Rebuild)
   ═══════════════════════════════════════════════════════════ */

/* ── Live Header Bar ── */
.fl-header{
  background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
  color:#fff;border-radius:16px;padding:22px 32px;
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:24px;box-shadow:0 8px 32px rgba(15,52,96,.3);
  position:relative;overflow:hidden;
}
.fl-header::after{
  content:'';position:absolute;top:-60%;right:-8%;width:280px;height:280px;
  background:radial-gradient(circle,rgba(255,255,255,.035) 0%,transparent 70%);border-radius:50%;
}
.fl-h-left h1{font-size:1.6rem;font-weight:800;display:flex;align-items:center;gap:12px;margin:0}
.fl-h-left p{opacity:.65;font-size:.8rem;margin:5px 0 0;font-weight:500}
.fl-live-dot{width:10px;height:10px;background:#00e676;border-radius:50%;display:inline-block;animation:flBlink 1.2s ease-in-out infinite}
@keyframes flBlink{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(0,230,118,.6)}50%{opacity:.5;box-shadow:0 0 0 8px rgba(0,230,118,0)}}
.fl-h-right{text-align:right;position:relative;z-index:2}
.fl-time{font-size:2.6rem;font-weight:900;font-family:'Courier New',monospace;letter-spacing:2px;line-height:1}
.fl-date{font-size:.85rem;opacity:.7;margin-top:5px;font-weight:500}
.fl-day{font-size:.72rem;opacity:.5;margin-top:2px;text-transform:uppercase;letter-spacing:1.5px}

/* ── Stats Row ── */
.fl-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.fl-stat{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,.05);border-bottom:4px solid #e0e0e0;transition:transform .2s}
.fl-stat:hover{transform:translateY(-2px)}
.fl-stat-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#8892a4;margin-bottom:4px}
.fl-stat-num{font-size:1.9rem;font-weight:900}
.fl-stat.s-total{border-bottom-color:#1a1a2e}.fl-stat.s-total .fl-stat-num{color:#1a1a2e}
.fl-stat.s-pend{border-bottom-color:#ff9800}.fl-stat.s-pend .fl-stat-num{color:#ff9800}
.fl-stat.s-run{border-bottom-color:#2196f3}.fl-stat.s-run .fl-stat-num{color:#2196f3}
.fl-stat.s-done{border-bottom-color:#4caf50}.fl-stat.s-done .fl-stat-num{color:#4caf50}

/* ── Job Cards List ── */
.fl-jobs{display:flex;flex-direction:column;gap:14px}

.fl-card{
  background:#fff;border-radius:16px;
  box-shadow:0 4px 20px rgba(0,0,0,.06);
  overflow:hidden;
  animation:flCardIn .4s ease-out both;
}
@keyframes flCardIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* Card Header */
.fl-card-head{
  padding:12px 20px;text-align:center;
  border-bottom:1px solid #eef0f4;
  background:linear-gradient(180deg,#fafbfd,#fff);
}
.fl-card-jobno{font-size:.92rem;font-weight:900;color:#1a1a2e;letter-spacing:.3px}
.fl-card-name{font-size:.78rem;color:#5a6478;margin-top:2px;font-weight:600}
.fl-card-detail{font-size:.68rem;color:#94a3b8;margin-top:2px}
.fl-card-pri{
  display:inline-block;font-size:.55rem;font-weight:800;
  text-transform:uppercase;letter-spacing:1px;
  padding:2px 10px;border-radius:20px;margin-top:5px;
}
.fl-card-pri.urgent{background:#fde8e8;color:#dc2626}
.fl-card-pri.high{background:#fff3e0;color:#e65100}
.fl-card-pri.normal{background:#e8f5e9;color:#2e7d32}
.fl-card-pri.low{background:#e3f2fd;color:#1565c0}

/* ── Horizontal Timeline ── */
.fl-timeline{padding:16px 20px 14px;overflow-x:auto;-webkit-overflow-scrolling:touch}
.fl-timeline::-webkit-scrollbar{height:3px}
.fl-timeline::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}

.fl-track{
  display:flex;align-items:flex-start;justify-content:center;
  min-width:520px;position:relative;padding:0 6px;
}

/* Single Stage Node */
.fl-node{
  display:flex;flex-direction:column;align-items:center;
  position:relative;flex:1;min-width:80px;
}

/* Connecting line */
.fl-node:not(:last-child)::after{
  content:'';position:absolute;top:16px;
  left:calc(50% + 16px);width:calc(100% - 32px);
  height:3px;background:#e0e0e0;z-index:1;border-radius:2px;
}
.fl-node.done:not(:last-child)::after{
  background:linear-gradient(90deg,#4caf50,#66bb6a);
}
.fl-node.now:not(:last-child)::after{
  background:linear-gradient(90deg,#ff9800 0%,#e0e0e0 60%);
}

/* Circle */
.fl-circle{
  width:32px;height:32px;border-radius:50%;
  border:3px solid #ddd;background:#f5f5f5;
  display:flex;align-items:center;justify-content:center;
  position:relative;z-index:5;transition:all .3s;
  font-size:.85rem;
}

/* Completed */
.fl-node.done .fl-circle{
  border-color:#4caf50;background:#e8f5e9;
}
.fl-node.done .fl-circle::after{
  content:'✓';font-size:.85rem;font-weight:900;color:#2e7d32;
}

/* Active — RED border + YELLOW fill + PULSE */
.fl-node.now .fl-circle{
  border-color:#dc2626;background:#fdd835;
  box-shadow:0 0 0 0 rgba(220,38,38,.4);
  animation:flPulse 1.8s ease-out infinite;
}
@keyframes flPulse{
  0%{box-shadow:0 0 0 0 rgba(220,38,38,.45)}
  40%{box-shadow:0 0 0 10px rgba(220,38,38,.1)}
  100%{box-shadow:0 0 0 16px rgba(220,38,38,0)}
}
.fl-node.now .fl-circle::before{
  content:'';position:absolute;inset:-6px;border-radius:50%;
  border:2px solid rgba(220,38,38,.18);
  animation:flRing 2.2s ease-out infinite;
}
@keyframes flRing{
  0%{transform:scale(.85);opacity:1}
  100%{transform:scale(1.3);opacity:0}
}

/* Future / inactive */
.fl-node.later .fl-circle{border-color:#d6d9e0;background:#f0f0f0;opacity:.45}

/* Stage label */
.fl-stage-name{
  margin-top:8px;font-size:.68rem;font-weight:700;
  color:#5a6478;text-align:center;white-space:nowrap;
}
.fl-node.now  .fl-stage-name{color:#dc2626;font-weight:800}
.fl-node.done .fl-stage-name{color:#2e7d32}
.fl-node.later .fl-stage-name{color:#b0b8c8}

/* Time below */
.fl-stage-time{
  margin-top:2px;font-size:.62rem;font-weight:700;
  color:#4caf50;font-family:'Courier New',monospace;
}
.fl-node.later .fl-stage-time{color:#c8cdd6}
.fl-node.now   .fl-stage-time{color:#ff6f00}

/* ── Empty & Loader ── */
.fl-empty{text-align:center;padding:70px 20px;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.05)}
.fl-empty-icon{font-size:3.5rem;opacity:.2;margin-bottom:14px}
.fl-empty p{font-size:.95rem;color:#94a3b8;font-weight:600}
.fl-loader{text-align:center;padding:50px}
.fl-spinner{width:36px;height:36px;border:4px solid #e0e0e0;border-top-color:#1a1a2e;border-radius:50%;animation:flSpin .7s linear infinite;margin:0 auto 14px}
@keyframes flSpin{to{transform:rotate(360deg)}}
.fl-loader p{color:#8892a4;font-size:.85rem;font-weight:600}

/* ── Legend ── */
.fl-legend{
  display:flex;gap:26px;flex-wrap:wrap;margin-top:22px;
  padding:14px 22px;background:#fff;border-radius:12px;
  box-shadow:0 2px 10px rgba(0,0,0,.04);font-size:.78rem;font-weight:600;color:#5a6478;
}
.fl-lg-item{display:flex;align-items:center;gap:8px}
.fl-lg-dot{width:18px;height:18px;border-radius:50%;border:3px solid}
.fl-lg-dot.d-done{border-color:#4caf50;background:#e8f5e9}
.fl-lg-dot.d-now{border-color:#dc2626;background:#fdd835}
.fl-lg-dot.d-later{border-color:#d6d9e0;background:#f0f0f0;opacity:.45}

/* ── Responsive ── */
@media(max-width:900px){
  .fl-stats{grid-template-columns:repeat(2,1fr)}
  .fl-header{flex-direction:column;gap:14px;text-align:center;padding:20px}
  .fl-h-right{text-align:center}
}
@media(max-width:600px){
  .fl-stats{grid-template-columns:1fr 1fr}
  .fl-time{font-size:2rem}
  .fl-h-left h1{font-size:1.25rem}
  .fl-card-head{padding:14px 16px}
  .fl-timeline{padding:18px 12px}
}
@media print{.fl-header,.fl-stats,.fl-legend,.breadcrumb{display:none!important}}
</style>

<!-- ═══ LIVE HEADER ═══ -->
<div class="fl-header">
  <div class="fl-h-left">
    <h1>🏭 Live Floor Plan <span class="fl-live-dot"></span></h1>
    <p>Real-time Production Monitoring &middot; Auto-refresh 60s</p>
  </div>
  <div class="fl-h-right">
    <div class="fl-time" id="flTime">--:--:--</div>
    <div class="fl-date" id="flDate"></div>
    <div class="fl-day" id="flDay"></div>
  </div>
</div>

<!-- ═══ STATS ═══ -->
<div class="fl-stats">
  <div class="fl-stat s-total"><div class="fl-stat-label">Total Jobs</div><div class="fl-stat-num" id="sTotal">0</div></div>
  <div class="fl-stat s-pend"><div class="fl-stat-label">Pending</div><div class="fl-stat-num" id="sPend">0</div></div>
  <div class="fl-stat s-run"><div class="fl-stat-label">In Progress</div><div class="fl-stat-num" id="sRun">0</div></div>
  <div class="fl-stat s-done"><div class="fl-stat-label">Completed</div><div class="fl-stat-num" id="sDone">0</div></div>
</div>

<!-- ═══ JOB CARDS ═══ -->
<div class="fl-jobs" id="flJobs">
  <div class="fl-loader"><div class="fl-spinner"></div><p>Loading production data…</p></div>
</div>

<!-- ═══ LEGEND ═══ -->
<div class="fl-legend">
  <div class="fl-lg-item"><div class="fl-lg-dot d-done"></div> Completed Stage</div>
  <div class="fl-lg-item"><div class="fl-lg-dot d-now"></div> Active Stage (Pulse)</div>
  <div class="fl-lg-item"><div class="fl-lg-dot d-later"></div> Upcoming Stage</div>
</div>

<script>
console.log('NEW UI LOADED — Live Floor Horizontal Timeline v2');

const FL_API = '<?= BASE_URL ?>/modules/jobs/api.php';
const FL_CSRF = '<?= e($csrf) ?>';
const FL_STAGES = ['Planning','Slitting','Printing','Flat Binding','Packaging','Dispatch'];

/* ─── Live Clock ─── */
function flTick(){
  const n=new Date();
  document.getElementById('flTime').textContent=n.toLocaleTimeString('en-IN',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('flDate').textContent=n.toLocaleDateString('en-IN',{year:'numeric',month:'long',day:'numeric'});
  document.getElementById('flDay').textContent=n.toLocaleDateString('en-IN',{weekday:'long'});
}
flTick(); setInterval(flTick,1000);

/* ─── Stage Detection ─── */
function getStageIdx(job){
  const s=(job.status||'').toLowerCase();
  const ps=(job.planning_status||'').toLowerCase();

  // Only Dispatch if actually closed/finalized
  if(s==='closed'||s==='finalized') return 5;

  // Packaging
  if(ps.includes('packaging')||ps.includes('packing')) return 4;

  // Flat Binding
  if(ps.includes('binding')||ps.includes('flat')) return 3;

  // Printing
  if(ps.includes('printing')) return 2;

  // Slitting — Running on machine OR slitting prep
  if(s==='running') return 1;
  if(ps.includes('slitting')||ps.includes('preparing')) return 1;

  // Completed but not closed = still Slitting stage done
  if(s==='completed'||s==='qc passed'||s==='qc failed') return 1;

  // Queued = waiting at Slitting
  if(s==='queued') return 1;

  // Default = Planning
  return 0;
}

function fmtTime(dt){
  try{const d=new Date(dt);if(isNaN(d))return '—';return d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:false});}catch(e){return '—';}
}

/* ─── Real timestamps from job data ─── */
function stageTime(job,idx){
  const cur=getStageIdx(job);
  if(idx>cur) return '—';

  // Parse extra_data for timestamps
  let extra={};
  try{extra=JSON.parse(job.extra_data||'{}');}catch(e){}

  // Stage 0: Planning → use created_at
  if(idx===0) return job.created_at?fmtTime(job.created_at):'—';

  // Stage 1: Slitting → use started_at (when machine started)
  if(idx===1){
    if(job.started_at) return fmtTime(job.started_at);
    return job.updated_at?fmtTime(job.updated_at):'—';
  }

  // Stage 2: Printing → use completed_at of slitting or updated_at
  if(idx===2) return job.completed_at?fmtTime(job.completed_at):(job.updated_at?fmtTime(job.updated_at):'—');

  // Stage 3: Flat Binding
  if(idx===3) return job.updated_at?fmtTime(job.updated_at):'—';

  // Stage 4: Packaging
  if(idx===4) return job.updated_at?fmtTime(job.updated_at):'—';

  // Stage 5: Dispatch → use completed_at
  if(idx===5) return job.completed_at?fmtTime(job.completed_at):(job.updated_at?fmtTime(job.updated_at):'—');

  return '—';
}

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

/* ─── Fetch & Render ─── */
async function flLoad(){
  try{
    const p=new URLSearchParams({action:'list_jobs',csrf_token:FL_CSRF,job_type:'Slitting',limit:'500'});
    const r=await fetch(FL_API+'?'+p.toString());
    const d=await r.json();
    if(!d.ok||!d.jobs)throw new Error('API error');

    const today=new Date();today.setHours(0,0,0,0);
    const jobs=d.jobs.filter(j=>{
      if(j.deleted_at)return false;
      if(['Pending','Running','Queued'].includes(j.status))return true;
      if(j.completed_at){const cd=new Date(j.completed_at);cd.setHours(0,0,0,0);return cd.getTime()===today.getTime();}
      return true;
    });

    renderJobs(jobs);
    renderStats(jobs);
  }catch(e){
    document.getElementById('flJobs').innerHTML='<div class="fl-empty"><div class="fl-empty-icon">⚠️</div><p>Unable to load production data</p></div>';
  }
}

function renderJobs(jobs){
  const box=document.getElementById('flJobs');
  if(!jobs.length){box.innerHTML='<div class="fl-empty"><div class="fl-empty-icon">🏭</div><p>No active jobs on the floor right now</p></div>';return;}

  const priOrd={Urgent:0,High:1,Normal:2,Low:3};
  jobs.sort((a,b)=>{
    const ra=a.status==='Running'?0:1,rb=b.status==='Running'?0:1;
    if(ra!==rb)return ra-rb;
    const sa=getStageIdx(a),sb=getStageIdx(b);
    if(sa!==sb)return sb-sa;
    return(priOrd[a.planning_priority]||2)-(priOrd[b.planning_priority]||2);
  });

  let html='';
  jobs.forEach((job,i)=>{
    const cur=getStageIdx(job);
    const pri=(job.planning_priority||'Normal').toLowerCase();

    // Timeline nodes
    let nodes='';
    FL_STAGES.forEach((stage,idx)=>{
      let cls='later';
      if(idx<cur)cls='done';
      else if(idx===cur)cls='now';
      nodes+=`<div class="fl-node ${cls}"><div class="fl-circle"></div><div class="fl-stage-name">${stage}</div><div class="fl-stage-time">${stageTime(job,idx)}</div></div>`;
    });

    // Details
    let det=[];
    if(job.roll_no)det.push('Roll: '+job.roll_no);
    if(job.paper_type)det.push(job.paper_type);
    if(job.gsm)det.push(job.gsm+'gsm');
    if(job.width_mm)det.push(job.width_mm+'mm');
    const detStr=det.join(' • ')||'Jumbo Slitting Job';

    html+=`<div class="fl-card" style="animation-delay:${i*0.05}s">
      <div class="fl-card-head">
        <div class="fl-card-jobno">${esc(job.job_no)} <span style="display:inline-block;font-size:.58rem;font-weight:800;padding:1px 8px;border-radius:10px;margin-left:6px;vertical-align:middle;${job.status==='Running'?'background:#dbeafe;color:#1e40af':job.status==='Pending'||job.status==='Queued'?'background:#fef3c7;color:#92400e':'background:#dcfce7;color:#166534'}">${esc(job.status)}</span></div>
        <div class="fl-card-name">${esc(job.planning_job_name||'Job Card')}</div>
        <div class="fl-card-detail">${esc(detStr)}</div>
        ${pri!=='normal'?`<div class="fl-card-pri ${pri}">${pri}</div>`:''}
      </div>
      <div class="fl-timeline"><div class="fl-track">${nodes}</div></div>
    </div>`;
  });
  box.innerHTML=html;
}

function renderStats(jobs){
  document.getElementById('sTotal').textContent=jobs.length;
  document.getElementById('sPend').textContent=jobs.filter(j=>['Pending','Queued'].includes(j.status)).length;
  document.getElementById('sRun').textContent=jobs.filter(j=>j.status==='Running').length;
  document.getElementById('sDone').textContent=jobs.filter(j=>['Closed','Finalized','Completed','QC Passed'].includes(j.status)).length;
}

/* ─── Init ─── */
flLoad();
setInterval(flLoad,60000);

/* ─── Midnight full reload ─── */
(function(){const n=new Date(),m=new Date(n);m.setHours(24,0,0,0);setTimeout(()=>location.reload(),m-n);})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
