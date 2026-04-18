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
   LIVE FLOOR  v3  —  Full Pipeline View
   ═══════════════════════════════════════════════════════════ */

/* ── Live Header Bar ── */
.fl-header{
  background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
  color:#fff;border-radius:16px;padding:22px 32px;
  display:flex;justify-content:space-between;align-items:center;
  margin-bottom:20px;box-shadow:0 8px 32px rgba(15,52,96,.3);
  position:relative;overflow:hidden;gap:16px;
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
  min-width:620px;position:relative;padding:0 10px;
}

/* Single Stage Node */
.fl-node{
  display:flex;flex-direction:column;align-items:center;
  position:relative;flex:1;min-width:96px;
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
  box-shadow:inset 0 0 0 1px rgba(148,163,184,.25);
}
.fl-circle::before{
  content:'';width:8px;height:8px;border-radius:50%;
  background:#94a3b8;
}

/* Completed */
.fl-node.done .fl-circle{
  border-color:#4caf50;background:#e8f5e9;
}
.fl-node.done .fl-circle::before{background:#2e7d32}
.fl-node.done .fl-circle::after{
  content:'✓';font-size:.85rem;font-weight:900;color:#2e7d32;
}

/* Active — RED border + YELLOW fill + PULSE */
.fl-node.now .fl-circle{
  border-color:#dc2626;background:#fdd835;
  box-shadow:0 0 0 0 rgba(220,38,38,.4);
  animation:flPulse 1.8s ease-out infinite;
}
.fl-node.now .fl-circle::before{background:#dc2626}
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
@media print{.fl-header,.fl-stats,.fl-legend,.breadcrumb,.fl-filters{display:none!important}}

/* ── Stats: 5 cols ── */
.fl-stats{grid-template-columns:repeat(5,1fr)!important}
.fl-stat.s-plan{border-bottom-color:#8b5cf6}.fl-stat.s-plan .fl-stat-num{color:#7c3aed}
.fl-stat.s-slit{border-bottom-color:#f59e0b}.fl-stat.s-slit .fl-stat-num{color:#d97706}
.fl-stat.s-print{border-bottom-color:#3b82f6}.fl-stat.s-print .fl-stat-num{color:#1d4ed8}
@media(max-width:1100px){.fl-stats{grid-template-columns:repeat(3,1fr)!important}}
@media(max-width:700px){.fl-stats{grid-template-columns:1fr 1fr!important}}

/* ── Mobile hardening ── */
@media (max-width:900px){
  .fl-filters{
    padding:10px 10px;
    gap:6px;
  }

/* ── Anti-overflow safety for small devices ── */
.fl-header,
.fl-stats,
.fl-filters,
.fl-jobs,
.fl-legend,
.fl-card,
.fl-card-head,
.fl-timeline{
  max-width:100%;
  box-sizing:border-box;
}

@media (max-width:640px){
  .fl-filters{
    display:block;
    overflow:visible;
  }
  .fl-filter-label{
    display:block;
    margin-bottom:6px;
  }
  .fl-filter-sep{display:none}

  /* Keep filter chips usable on tiny screens with horizontal swipe */
  .fl-filter-chip-row{
    display:flex;
    gap:6px;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    padding-bottom:4px;
  }

  .fl-filter-btn{
    white-space:nowrap;
    flex:0 0 auto;
  }

  .fl-search{
    display:block;
    width:100%;
    margin:8px 0 0 0;
  }

  .fl-card-head{
    overflow:hidden;
    flex-wrap:nowrap;
  }
  .fl-card-info{
    min-width:0;
    overflow:hidden;
  }
  .fl-card-row1,
  .fl-card-det{
    min-width:0;
  }
  .fl-card-jobno,
  .fl-card-name,
  .fl-card-det{
    word-break:break-word;
  }

  .fl-legend{
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    flex-wrap:nowrap;
    white-space:nowrap;
  }
}
  .fl-filter-sep{display:none}
  .fl-search{
    margin-left:0;
    width:100%;
    min-width:0;
    order:99;
  }
}

@media (max-width:640px){
  .fl-header{
    padding:14px;
    border-radius:12px;
  }
  .fl-h-left h1{
    font-size:1.05rem;
    gap:7px;
    flex-wrap:wrap;
    justify-content:center;
  }
  .fl-h-left p{font-size:.72rem}
  .fl-time{font-size:1.45rem;letter-spacing:1px}
  .fl-date{font-size:.73rem}
  .fl-day{font-size:.62rem;letter-spacing:1px}

  .fl-stats{
    grid-template-columns:1fr 1fr !important;
    gap:8px;
    margin-bottom:14px;
  }
  .fl-stat{
    padding:11px 10px;
    border-radius:10px;
  }
  .fl-stat-label{font-size:.6rem}
  .fl-stat-num{font-size:1.2rem}

  .fl-filters{
    border-radius:10px;
  }
  .fl-filter-label{
    width:100%;
    margin-bottom:4px;
  }
  .fl-filter-btn{
    font-size:.66rem;
    padding:5px 10px;
  }

  .fl-card{
    border-radius:12px;
  }
  .fl-card-head{
    padding:9px 10px;
    gap:8px;
  }
  .fl-card-thumb,
  .fl-card-thumb-ph{
    width:42px;
    height:42px;
    border-radius:7px;
  }
  .fl-card-row1{gap:6px}
  .fl-card-jobno{font-size:.78rem}
  .fl-card-name{
    font-size:.7rem;
    white-space:normal;
    overflow:visible;
    text-overflow:clip;
  }
  .fl-card-det{font-size:.6rem;gap:6px}

  .fl-timeline{
    padding:12px 8px 10px;
  }
  .fl-track{
    min-width:460px;
    padding:0 2px;
  }
  .fl-node{min-width:70px}
  .fl-circle{width:28px;height:28px}
  .fl-stage-name{font-size:.62rem}
  .fl-stage-time{font-size:.56rem}

  .fl-legend{
    gap:10px;
    padding:10px;
    font-size:.66rem;
    border-radius:10px;
  }
  .fl-lg-item{gap:6px}
  .fl-lg-dot{width:14px;height:14px;border-width:2px}
}

@media (max-width:420px){
  .fl-stats{grid-template-columns:1fr !important}
  .fl-filter-btn{padding:5px 8px;font-size:.64rem}
  .fl-track{min-width:420px}
}

/* ── Dept Badges ── */
.fl-dept-badge{font-size:.57rem;font-weight:800;padding:2px 8px;border-radius:10px;display:inline-block;vertical-align:middle;text-transform:uppercase;letter-spacing:.4px}
.db-plan{background:#ede9fe;color:#7c3aed}
.db-slit{background:#fef3c7;color:#92400e}
.db-print{background:#dbeafe;color:#1e40af}
.db-die{background:#ccfbf1;color:#0f766e}
.db-lsl{background:#ffedd5;color:#c2410c}

/* ── Left dept border on card ── */
.fl-card.dept-slit{border-left:4px solid #f59e0b}
.fl-card.dept-print{border-left:4px solid #3b82f6}
.fl-card.dept-die{border-left:4px solid #0f766e}
.fl-card.dept-lsl{border-left:4px solid #c2410c}
.fl-card.dept-plan{border-left:4px solid #8b5cf6}

/* ── Card header flex layout ── */
.fl-card-head{padding:11px 18px;border-bottom:1px solid #f0f2f6;display:flex;align-items:flex-start;gap:10px;text-align:left}
.fl-card-thumb{width:50px;height:50px;border-radius:8px;object-fit:contain;border:1.5px solid #e2e8f0;background:#f8fafc;flex-shrink:0}
.fl-card-thumb-ph{width:50px;height:50px;border-radius:8px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.fl-card-info{flex:1;min-width:0}
.fl-card-row1{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:3px}
.fl-status-badge{font-size:.57rem;font-weight:800;padding:2px 8px;border-radius:10px;display:inline-block;vertical-align:middle;text-transform:uppercase;letter-spacing:.4px}
.fl-card-name{font-size:.77rem;color:#5a6478;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fl-card-det{font-size:.64rem;color:#94a3b8;margin-top:2px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.fl-cr-badge{display:inline-flex;align-items:center;gap:3px;background:#fef3c7;color:#92400e;border-radius:8px;font-size:.58rem;font-weight:800;padding:2px 7px;animation:flCrPulse 2s ease-in-out infinite}
@keyframes flCrPulse{0%,100%{opacity:1}50%{opacity:.65}}
.fl-dispatch-badge{font-size:.6rem;font-weight:700;background:#fef9c3;color:#854d0e;padding:2px 7px;border-radius:8px}
.fl-card-pri.urgent{background:#fde8e8;color:#dc2626}
.fl-card-pri.high{background:#fff3e0;color:#e65100}
.fl-card-pri.normal{display:none}
.fl-card-pri.low{background:#e3f2fd;color:#1565c0}

/* ── Filter Tabs ── */
.fl-filters{display:flex;gap:8px;margin-bottom:16px;background:#fff;padding:10px 16px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.05);flex-wrap:wrap;align-items:center}
.fl-filter-label{font-size:.68rem;font-weight:700;color:#8892a4;text-transform:uppercase;letter-spacing:.7px;margin-right:2px;flex-shrink:0}
.fl-filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid #e2e8f0;background:#fff;font-size:.72rem;font-weight:700;color:#64748b;cursor:pointer;transition:all .15s;white-space:nowrap}
.fl-filter-btn:hover{border-color:#94a3b8;color:#1e293b}
.fl-filter-btn.active{background:#1a1a2e;color:#fff;border-color:#1a1a2e}
.fl-filter-btn.fp.active{background:#8b5cf6;border-color:#8b5cf6}
.fl-filter-btn.fs.active{background:#d97706;border-color:#d97706}
.fl-filter-btn.fpr.active{background:#1d4ed8;border-color:#1d4ed8}
.fl-filter-btn.ff.active{background:#0f766e;border-color:#0f766e}
.fl-filter-sep{width:1px;height:20px;background:#e2e8f0;margin:0 2px;align-self:center}
.fl-search{margin-left:auto;padding:5px 12px;border-radius:20px;border:1.5px solid #e2e8f0;font-size:.72rem;font-weight:600;color:#1e293b;outline:none;width:170px}
.fl-search:focus{border-color:#60a5fa}
.fl-cnt{display:inline-block;min-width:16px;height:16px;line-height:16px;text-align:center;border-radius:8px;background:rgba(0,0,0,.1);font-size:.6rem;padding:0 4px;margin-left:4px}
.fl-filter-btn.active .fl-cnt{background:rgba(255,255,255,.3)}
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
  <div class="fl-stat s-total"><div class="fl-stat-label">Total Active</div><div class="fl-stat-num" id="sTotal">0</div></div>
  <div class="fl-stat s-plan"><div class="fl-stat-label">Planning</div><div class="fl-stat-num" id="sPlan">0</div></div>
  <div class="fl-stat s-slit"><div class="fl-stat-label">Slitting</div><div class="fl-stat-num" id="sSlit">0</div></div>
  <div class="fl-stat s-print"><div class="fl-stat-label">Printing</div><div class="fl-stat-num" id="sPrint">0</div></div>
  <div class="fl-stat s-done"><div class="fl-stat-label">Finishing / Done</div><div class="fl-stat-num" id="sDone">0</div></div>
</div>

<!-- ═══ FILTER TABS ═══ -->
<div class="fl-filters">
  <span class="fl-filter-label">Filter:</span>
  <button class="fl-filter-btn active" data-filter="all">All <span class="fl-cnt" id="fcAll">0</span></button>
  <button class="fl-filter-btn fp" data-filter="plan">📋 Planning <span class="fl-cnt" id="fcPlan">0</span></button>
  <button class="fl-filter-btn fs" data-filter="slit">🔪 Slitting <span class="fl-cnt" id="fcSlit">0</span></button>
  <button class="fl-filter-btn fpr" data-filter="print">🖨️ Printing <span class="fl-cnt" id="fcPrint">0</span></button>
  <button class="fl-filter-btn ff" data-filter="finish">✂️ Finishing <span class="fl-cnt" id="fcFinish">0</span></button>
  <div class="fl-filter-sep"></div>
  <button class="fl-filter-btn" data-filter="running">🔴 Running</button>
  <button class="fl-filter-btn" data-filter="urgent">⚡ Urgent/High</button>
  <input class="fl-search" type="text" id="flSearch" placeholder="Search job / name…" autocomplete="off">
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
  <span style="margin:0 6px;color:#e2e8f0">|</span>
  <div class="fl-lg-item"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:#f59e0b;margin-right:4px"></span>Slitting (JMB)</div>
  <div class="fl-lg-item"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:#3b82f6;margin-right:4px"></span>Printing (FLX)</div>
  <div class="fl-lg-item"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:#0f766e;margin-right:4px"></span>Die-Cutting (DCT)</div>
  <div class="fl-lg-item"><span style="display:inline-block;width:11px;height:11px;border-radius:2px;background:#c2410c;margin-right:4px"></span>Label-Slitting (LSL)</div>
</div>

<script>
console.log('Live Floor v3 — Full Pipeline');

const FL_API  = '<?= BASE_URL ?>/modules/jobs/api.php';
const FL_CSRF = '<?= e($csrf) ?>';
const FL_STAGES = ['Planning','Jumbo Slitting','Flexo Printing','Diecutting / Barcode','Label Slitting','Packing','Dispatched'];

/* ─── Global state ─── */
let FL_ALL_JOBS      = [];
let FL_ACTIVE_FILTER = 'all';
let FL_SEARCH        = '';

/* ─── Live Clock ─── */
function flTick(){
  const n=new Date();
  document.getElementById('flTime').textContent=n.toLocaleTimeString('en-IN',{hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  document.getElementById('flDate').textContent=n.toLocaleDateString('en-IN',{year:'numeric',month:'long',day:'numeric'});
  document.getElementById('flDay').textContent=n.toLocaleDateString('en-IN',{weekday:'long'});
}
flTick(); setInterval(flTick,1000);

/* ─── Dept helper ─── */
function getDept(job){
  const jt  = String(job.job_type  ||'').toLowerCase();
  const dept= String(job.department||'').toLowerCase();
  const jn  = String(job.job_no    ||'').toUpperCase();
  if(jt==='planning') return 'plan';
  if(jt==='slitting'||dept.includes('slitting')||dept.includes('jumbo')||jn.startsWith('JMB/')) return 'slit';
  if(jt==='printing'||dept.includes('print')||jn.startsWith('FLX/')) return 'print';
  if(dept.includes('label')||jn.startsWith('LSL/')) return 'lsl';
  if(dept.includes('flatbed')||dept.includes('die')||jn.startsWith('DCT/')||jn.startsWith('ROT/')) return 'die';
  if(jt==='finishing') return 'die';
  return 'plan';
}
const DEPT_LABEL = {plan:'PLN',slit:'JMB',print:'FLX',die:'DCT',lsl:'LSL'};
const DEPT_ICON  = {plan:'📋',slit:'🔪',print:'🖨️',die:'✂️',lsl:'🏷️'};
const DEPT_CLASS = {plan:'db-plan',slit:'db-slit',print:'db-print',die:'db-die',lsl:'db-lsl'};
const CARD_CLASS = {plan:'dept-plan',slit:'dept-slit',print:'dept-print',die:'dept-die',lsl:'dept-lsl'};

function normStatus(v){
  return String(v||'').toLowerCase().replace(/[-_]/g,' ').trim();
}

function isDoneStatus(v){
  return ['closed','finalized','completed','qc passed','qc failed','dispatched','packing done'].includes(normStatus(v));
}

/* ─── Stage Detection ─── */
function getStageIdx(job){
  const s    = normStatus(job.status||'');
  const ps   = normStatus(job.planning_status||'');
  const dept = getDept(job);

  if(ps.includes('dispatch') || s==='dispatched') return 6;
  if(ps.includes('packing') || s==='packing done' || s==='packed') return 5;

  if(dept==='lsl'){
    return isDoneStatus(s) ? 5 : 4;
  }
  if(dept==='die'){
    return isDoneStatus(s) ? 4 : 3;
  }
  if(dept==='print'){
    return isDoneStatus(s) ? 3 : 2;
  }
  if(dept==='slit'){
    return isDoneStatus(s) ? 2 : 1;
  }

  // Planning-status fallback for rows that are not stage job cards.
  if(ps.includes('label slitting')) return 4;
  if(ps.includes('barcode')||ps.includes('die')||ps.includes('flat')||ps.includes('binding')) return 3;
  if(ps.includes('printing')) return 2;
  if(ps.includes('slitting')||ps.includes('preparing')) return 1;
  return 0;
}

function fmtStageDate(dt){
  try{const d=new Date(dt);if(isNaN(d))return '—';return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return '—';}
}

function isDispatchDone(job){
  return getStageIdx(job)>=6 && isDoneStatus(job.status||'');
}

function getDieStageName(job){
  const dept=String(job.department||'').toLowerCase();
  if(dept.includes('label')||String(job.job_no||'').toUpperCase().startsWith('LSL/')) return 'Label Slitting';
  const die=String(job.planning_die||'').toLowerCase();
  if(die.includes('rotary')) return 'Rotary Die';
  if(die.includes('flat'))   return 'Flatbed';
  return 'Die / LSL';
}

function isRunningStage(job,idx){
  const s   = normStatus(job.status||'');
  const dept= getDept(job);
  if(s!=='running') return false;
  if(idx===1) return dept==='slit';
  if(idx===2) return dept==='print';
  if(idx===3) return dept==='die';
  if(idx===4) return dept==='lsl';
  if(idx===5) return s==='running' && (String(job.department||'').toLowerCase().includes('pack')||String(job.department||'').toLowerCase().includes('dispatch'));
  return false;
}

function getStageName(job,idx){
  const cur=getStageIdx(job);
  const doneNames=['Planned','Jumbo Slitted','Flexo Printed','Die/Barcode Done','Label Slitted','Packed','Dispatched'];
  if(idx<cur) return doneNames[idx]||'Done';
  const active  =['Planning','Jumbo Slitting','Flexo Printing','Diecutting / Barcode','Label Slitting','Packing','Dispatched'];
  const inactive=['Planning','Jumbo Slitting','Flexo Printing','Diecutting / Barcode','Label Slitting','Packing','Dispatched'];
  if(idx===cur){
    if(idx===0) return 'Planning';
    if(idx===6) return isDispatchDone(job) ? 'Dispatched' : 'Preparing Dispatch';
    return isRunningStage(job,idx) ? active[idx]||'' : 'Preparing '+active[idx];
  }
  return inactive[idx]||'—';
}

function stageTime(job,idx){
  const cur=getStageIdx(job);
  const planDate=job.planning_created_at||job.created_at||new Date();
  if(idx===0) return fmtStageDate(planDate);
  if(idx===6) return isDispatchDone(job)?fmtStageDate(job.completed_at):fmtStageDate(job.planning_dispatch_date||planDate);
  if(idx<=cur){
    if(idx===1&&job.started_at) return fmtStageDate(job.started_at);
    if(idx>=2&&job.completed_at&&cur>idx) return fmtStageDate(job.completed_at);
    return fmtStageDate(new Date());
  }
  return fmtStageDate(new Date());
}

function findRefByPrefix(job,prefix){
  const re=new RegExp('(?:^|[^A-Z])('+prefix+'\\/\\d{4}\\/\\d{3,6})','i');
  const fields=[job.job_no,job.prev_job_no,job.planning_job_name,job.notes];
  for(let i=0;i<fields.length;i++){
    const m=String(fields[i]||'').match(re);
    if(m&&m[1]) return m[1].toUpperCase();
  }
  return '';
}

function getDisplayJobRef(job){
  const dept=getDept(job);
  const cur=getStageIdx(job);
  if(dept==='plan')  return findRefByPrefix(job,'PLN')||String(job.planning_job_name||'').trim()||String(job.job_no||'—').trim();
  if(dept==='slit')  return findRefByPrefix(job,'JMB')||String(job.job_no||'—').trim();
  if(dept==='die')   return findRefByPrefix(job,'DCT')||findRefByPrefix(job,'ROT')||String(job.job_no||'—').trim();
  if(dept==='lsl')   return findRefByPrefix(job,'LSL')||String(job.job_no||'—').trim();
  if(cur>=2) return findRefByPrefix(job,'FLX')||findRefByPrefix(job,'JMB')||String(job.job_no||'—').trim();
  return String(job.job_no||'—').trim();
}

function stBadge(status){
  const s=normStatus(status||'');
  const map={running:'background:#dbeafe;color:#1e40af',pending:'background:#fef3c7;color:#92400e',queued:'background:#fef3c7;color:#92400e',closed:'background:#dcfce7;color:#166534',finalized:'background:#dcfce7;color:#166534',completed:'background:#dcfce7;color:#166534','qc passed':'background:#dcfce7;color:#166534','qc failed':'background:#fee2e2;color:#dc2626','packing done':'background:#dcfce7;color:#166534',dispatched:'background:#dcfce7;color:#166534'};
  return map[s]||'background:#f1f5f9;color:#64748b';
}

function getChainRootId(job, byId){
  let cur = Number((job && job.id) || 0);
  let prev = Number((job && job.previous_job_id) || 0);
  const seen = new Set();
  while(prev>0 && byId.has(prev) && !seen.has(prev)){
    seen.add(prev);
    cur = prev;
    const parent = byId.get(prev);
    prev = Number((parent && parent.previous_job_id) || 0);
  }
  return cur>0 ? ('id:'+cur) : '';
}

function logicalJobKey(job, byId){
  const root = getChainRootId(job, byId);
  if(root) return root;
  const planningId = Number((job && job.planning_id) || 0);
  if(planningId>0) return 'pln:'+planningId;
  const name = String((job && job.planning_job_name) || '').trim().toLowerCase();
  if(name) return 'name:'+name;
  const jobNo = String((job && job.job_no) || '').trim().toLowerCase();
  if(jobNo) return 'job:'+jobNo;
  return 'row:' + String((job && job.id) || Math.random());
}

function betterLiveCard(a,b){
  const ar = normStatus(a.status)==='running'?1:0;
  const br = normStatus(b.status)==='running'?1:0;
  if(ar!==br) return ar>br ? a : b;
  const as = getStageIdx(a), bs = getStageIdx(b);
  if(as!==bs) return as>bs ? a : b;
  const priOrd={urgent:0,high:1,normal:2,low:3};
  const ap = priOrd[String(a.planning_priority||'normal').toLowerCase()] ?? 2;
  const bp = priOrd[String(b.planning_priority||'normal').toLowerCase()] ?? 2;
  if(ap!==bp) return ap<bp ? a : b;
  return Number(a.id||0) >= Number(b.id||0) ? a : b;
}

function dedupeLogicalJobs(rows){
  const byId = new Map();
  rows.forEach(j=>{ const id=Number(j.id||0); if(id>0) byId.set(id,j); });
  const pick = new Map();
  rows.forEach(j=>{
    const key = logicalJobKey(j, byId);
    if(!pick.has(key)){ pick.set(key,j); return; }
    pick.set(key, betterLiveCard(pick.get(key), j));
  });
  return Array.from(pick.values());
}

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

/* ─── Filter predicate ─── */
function matchFilter(job){
  const dept=getDept(job);
  const s=normStatus(job.status||'');
  const pri=(job.planning_priority||'Normal').toLowerCase();
  if(FL_ACTIVE_FILTER==='plan'   && dept!=='plan') return false;
  if(FL_ACTIVE_FILTER==='slit'   && dept!=='slit') return false;
  if(FL_ACTIVE_FILTER==='print'  && dept!=='print') return false;
  if(FL_ACTIVE_FILTER==='finish' && !['die','lsl'].includes(dept)) return false;
  if(FL_ACTIVE_FILTER==='running' && s!=='running') return false;
  if(FL_ACTIVE_FILTER==='urgent' && !['urgent','high'].includes(pri)) return false;
  if(FL_SEARCH){
    const q=FL_SEARCH.toLowerCase();
    const hay=(String(job.job_no||'')+' '+String(job.planning_job_name||'')+' '+String(job.roll_no||'')).toLowerCase();
    if(!hay.includes(q)) return false;
  }
  return true;
}

/* ─── Fetch & Render ─── */
async function flLoad(){
  try{
    const p=new URLSearchParams({action:'list_live_floor',csrf_token:FL_CSRF,limit:'600'});
    const r=await fetch(FL_API+'?'+p.toString(),{cache:'no-store'});
    const d=await r.json();
    if(!d.ok||!Array.isArray(d.jobs)) throw new Error('API error');

    const today=new Date(); today.setHours(0,0,0,0);
    const isVisible=(j)=>{
      if(j.deleted_at) return false;
      // Normalize status for this page; keep durable packing flag authoritative.
      if (j && j.extra_data_parsed && Number(j.extra_data_parsed.packing_done_flag||0) === 1) {
        j.status = 'Packing Done';
        if (!j.completed_at && j.extra_data_parsed.packing_done_at) {
          j.completed_at = j.extra_data_parsed.packing_done_at;
        }
      }

      if(['pending','running','queued'].includes(normStatus(j.status))) return true;
      if(j.completed_at){const cd=new Date(j.completed_at);cd.setHours(0,0,0,0);return cd.getTime()===today.getTime();}
      return true;
    };

    const allJobs=(d.jobs||[]).filter(isVisible);
    const slitting =allJobs.filter(j=>String(j.job_type||'')==='Slitting');
    const printing =allJobs.filter(j=>String(j.job_type||'')==='Printing');
    const finishing=allJobs.filter(j=>String(j.job_type||'')==='Finishing');
    const planOnly =allJobs.filter(j=>String(j.job_type||'')==='Planning');

    const printByPrevId=new Set(printing.map(j=>Number(j.previous_job_id||0)).filter(v=>v>0));
    const slittingVis=slitting.filter(j=>{
      const done=isDoneStatus(j.status||'');
      if(!done) return true;
      return !printByPrevId.has(Number(j.id||0));
    });

    FL_ALL_JOBS=dedupeLogicalJobs([...slittingVis,...printing,...finishing,...planOnly]);

    renderStats(FL_ALL_JOBS);
    renderFilterCounts(FL_ALL_JOBS);
    renderJobs(FL_ALL_JOBS.filter(matchFilter));
  }catch(e){
    document.getElementById('flJobs').innerHTML='<div class="fl-empty"><div class="fl-empty-icon">⚠️</div><p>Unable to load production data</p></div>';
  }
}

function renderJobs(jobs){
  const box=document.getElementById('flJobs');
  if(!jobs.length){
    box.innerHTML='<div class="fl-empty"><div class="fl-empty-icon">🏭</div><p>No jobs match the current filter</p></div>';
    return;
  }

  const priOrd={Urgent:0,High:1,Normal:2,Low:3};
  jobs.sort((a,b)=>{
    const ra=normStatus(a.status)==='running'?0:1,rb=normStatus(b.status)==='running'?0:1;
    if(ra!==rb) return ra-rb;
    const sa=getStageIdx(a),sb=getStageIdx(b);
    if(sa!==sb) return sb-sa;
    return(priOrd[a.planning_priority]||2)-(priOrd[b.planning_priority]||2);
  });

  let html='';
  jobs.forEach((job,i)=>{
    const cur   = getStageIdx(job);
    const dept  = getDept(job);
    const pri   = (job.planning_priority||'Normal').toLowerCase();
    const ref   = getDisplayJobRef(job);
    const cr    = Number(job.pending_change_requests||0);
    const imgURL= String(job.planning_image_url||'').trim();
    const dispDate = String(job.planning_dispatch_date||'').trim();

    const thumb = imgURL
      ? `<img src="${esc(imgURL)}" class="fl-card-thumb" alt="Label" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
        +`<div class="fl-card-thumb-ph" style="display:none">${DEPT_ICON[dept]||'📋'}</div>`
      : `<div class="fl-card-thumb-ph">${DEPT_ICON[dept]||'📋'}</div>`;

    let det=[];
    if(job.roll_no)    det.push('Roll: '+esc(job.roll_no));
    if(job.paper_type) det.push(esc(job.paper_type));
    if(job.gsm)        det.push(esc(job.gsm)+'gsm');
    if(job.width_mm)   det.push(esc(job.width_mm)+'mm');

    let nodes='';
    FL_STAGES.forEach((stage,idx)=>{
      let cls='later';
      if(idx<cur) cls='done';
      else if(idx===cur) cls='now';
      nodes+=`<div class="fl-node ${cls}"><div class="fl-circle"></div><div class="fl-stage-name">${getStageName(job,idx)}</div><div class="fl-stage-time">${stageTime(job,idx)}</div></div>`;
    });

    html+=`<div class="fl-card ${CARD_CLASS[dept]||''}" style="animation-delay:${Math.min(i,15)*.04}s">
      <div class="fl-card-head">
        ${thumb}
        <div class="fl-card-info">
          <div class="fl-card-row1">
            <span class="fl-card-jobno" style="font-size:.9rem;font-weight:900;color:#1a1a2e">${esc(ref)}</span>
            <span class="fl-status-badge" style="${stBadge(job.status)}">${esc(job.status||'Pending')}</span>
            <span class="fl-dept-badge ${DEPT_CLASS[dept]||''}">${DEPT_LABEL[dept]||'—'}</span>
            ${cr>0?`<span class="fl-cr-badge">⚠ ${cr} Change Req</span>`:''}
            ${pri!=='normal'?`<span class="fl-card-pri ${pri}">${pri}</span>`:''}
          </div>
          <div class="fl-card-name">${esc(job.planning_job_name||job.job_no||'Job Card')}</div>
          <div class="fl-card-det">
            ${det.length?`<span>${det.join(' · ')}</span>`:''}
            ${dispDate?`<span class="fl-dispatch-badge">📅 ${fmtStageDate(dispDate)}</span>`:''}
          </div>
        </div>
      </div>
      <div class="fl-timeline"><div class="fl-track">${nodes}</div></div>
    </div>`;
  });
  box.innerHTML=html;
}

function renderStats(jobs){
  const planC  = jobs.filter(j=>getDept(j)==='plan').length;
  const slitC  = jobs.filter(j=>getDept(j)==='slit').length;
  const printC = jobs.filter(j=>getDept(j)==='print').length;
  const finC   = jobs.filter(j=>['die','lsl'].includes(getDept(j))||isDispatchDone(j)).length;
  document.getElementById('sTotal').textContent = jobs.length;
  document.getElementById('sPlan').textContent  = planC;
  document.getElementById('sSlit').textContent  = slitC;
  document.getElementById('sPrint').textContent = printC;
  document.getElementById('sDone').textContent  = finC;
}

function renderFilterCounts(jobs){
  document.getElementById('fcAll').textContent    = jobs.length;
  document.getElementById('fcPlan').textContent   = jobs.filter(j=>getDept(j)==='plan').length;
  document.getElementById('fcSlit').textContent   = jobs.filter(j=>getDept(j)==='slit').length;
  document.getElementById('fcPrint').textContent  = jobs.filter(j=>getDept(j)==='print').length;
  document.getElementById('fcFinish').textContent = jobs.filter(j=>['die','lsl'].includes(getDept(j))).length;
}

/* ─── Filter buttons ─── */
document.querySelectorAll('.fl-filter-btn[data-filter]').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.fl-filter-btn[data-filter]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    FL_ACTIVE_FILTER=btn.dataset.filter;
    renderJobs(FL_ALL_JOBS.filter(matchFilter));
  });
});

document.getElementById('flSearch').addEventListener('input',function(){
  FL_SEARCH=this.value.trim();
  renderJobs(FL_ALL_JOBS.filter(matchFilter));
});

/* ─── Init ─── */
flLoad();
setInterval(flLoad,10000);

document.addEventListener('visibilitychange',()=>{if(!document.hidden) flLoad();});
window.addEventListener('focus',flLoad);

/* ─── Midnight full reload ─── */
(function(){const n=new Date(),m=new Date(n);m.setHours(24,0,0,0);setTimeout(()=>location.reload(),m-n);})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<?php ob_start(); ?>

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
  const dept=(job.department||'').toLowerCase();
  const jn=String(job.job_no||'').toUpperCase();
  const pp=(job.printing_planning||'').toLowerCase();

  // Dispatch — only when planning has explicitly progressed to dispatch
  if(ps.includes('dispatch')) return 5;

  // Packaging
  if(ps.includes('packaging')||ps.includes('packing')) return 4;

  // Flat Binding
  if(ps.includes('binding')||ps.includes('flat')) return 3;

  // Printing Done — completed printing job advances past Printing stage
  if(pp.includes('printing') && (pp.includes('done')||pp.includes('completed'))) return 3;

  // Printing — FLX job completed but printing_planning not yet set
  if((dept.includes('print')||jn.startsWith('FLX/')) && (s==='completed'||s==='closed'||s==='finalized')) return 3;

  // Printing
  if(ps.includes('printing')) return 2;

  // Printing-area jobs should stay on Printing stage
  if(dept.includes('print')||jn.startsWith('FLX/')) return 2;

  // Slitting — Running on machine OR slitting prep
  if(s==='running') return 1;
  if(ps.includes('slitting')||ps.includes('preparing')) return 1;

  // Closed/Finalized/Completed at slitting = move active node to Printing
  if(s==='closed'||s==='finalized'||s==='completed'||s==='qc passed'||s==='qc failed') return 2;

  // Queued = waiting at Slitting
  if(s==='queued') return 1;

  // Default = Planning
  return 0;
}

function fmtTime(dt){
  try{const d=new Date(dt);if(isNaN(d))return '—';return d.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:false});}catch(e){return '—';}
}

function fmtStageDate(dt){
  try{
    const d=new Date(dt);
    if(isNaN(d)) return '—';
    return d.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});
  }catch(e){
    return '—';
  }
}

function isDispatchDone(job){
  const s=String(job.status||'');
  return getStageIdx(job)===5 && ['Closed','Finalized','Completed','QC Passed','QC Failed','Dispatched'].includes(s);
}

function findRefByPrefix(job,prefix){
  const fields=[job.job_no,job.prev_job_no,job.planning_job_name,job.notes];
  const re=new RegExp('(?:^|[^A-Z])('+prefix+'\\/\\d{4}\\/\\d{3,6})','i');
  for(let i=0;i<fields.length;i++){
    const txt=String(fields[i]||'');
    const m=txt.match(re);
    if(m&&m[1]) return m[1].toUpperCase();
  }
  return '';
}

function getDisplayJobRef(job,cur){
  const planRef=findRefByPrefix(job,'PLN');
  const jumboRef=findRefByPrefix(job,'JMB');
  const flexoRef=findRefByPrefix(job,'FLX');

  if(cur===0) return planRef||String(job.planning_job_name||'').trim()||String(job.job_no||'').trim()||'—';
  if(cur===1) return jumboRef||String(job.job_no||'').trim()||'—';
  if(cur>=2) return flexoRef||jumboRef||String(job.job_no||'').trim()||'—';
  return String(job.job_no||'').trim()||'—';
}

/* ─── Init ─── */
flLoad();
setInterval(flLoad,10000);

// Refresh immediately when user returns to the tab/window.
document.addEventListener('visibilitychange', function(){
  if(!document.hidden) flLoad();
});
window.addEventListener('focus', flLoad);

/* ─── Midnight full reload ─── */
(function(){const n=new Date(),m=new Date(n);m.setHours(24,0,0,0);setTimeout(()=>location.reload(),m-n);})();
</script>

<?php ob_end_clean(); ?>
