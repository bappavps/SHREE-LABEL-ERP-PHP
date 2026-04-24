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
.fl-card.fl-card-link{cursor:pointer;transition:transform .18s ease,box-shadow .18s ease}
.fl-card.fl-card-link:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(15,23,42,.12)}
.fl-card.fl-card-link:focus-visible{outline:3px solid #60a5fa;outline-offset:2px}
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
.db-pack{background:#dcfce7;color:#166534}

/* ── Left dept border on card ── */
.fl-card.dept-slit{border-left:4px solid #f59e0b}
.fl-card.dept-print{border-left:4px solid #3b82f6}
.fl-card.dept-die{border-left:4px solid #0f766e}
.fl-card.dept-lsl{border-left:4px solid #c2410c}
.fl-card.dept-pack{border-left:4px solid #22c55e}
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
const FL_BASE = '<?= BASE_URL ?>';
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
  if(['pos','paperroll','oneply','one_ply','twoply','two_ply'].includes(dept)) return 'pack';
  if(['pos','paperroll','oneply','one_ply','twoply','two_ply'].includes(jt)) return 'pack';
  if(dept.includes('pack') || dept.includes('dispatch')) return 'pack';
  if(jt==='finishing') return 'die';
  return 'plan';
}
const DEPT_LABEL = {plan:'PLN',slit:'JMB',print:'FLX',die:'DCT',lsl:'LSL',pack:'PKG'};
const DEPT_ICON  = {plan:'📋',slit:'🔪',print:'🖨️',die:'✂️',lsl:'🏷️',pack:'📦'};
const DEPT_CLASS = {plan:'db-plan',slit:'db-slit',print:'db-print',die:'db-die',lsl:'db-lsl',pack:'db-pack'};
const CARD_CLASS = {plan:'dept-plan',slit:'dept-slit',print:'dept-print',die:'dept-die',lsl:'dept-lsl',pack:'dept-pack'};

function normStatus(v){
  return String(v||'').toLowerCase().replace(/[-_]/g,' ').trim();
}

function isDoneStatus(v){
  // Only treat these as done; 'Packing' is NOT done
  return [
    'closed','finalized','completed','qc passed','qc failed',
    'dispatched','delivered','packing done','packed','finished production','finished barcode'
  ].includes(normStatus(v));
}

/* ─── Stage Detection ─── */
function getStageIdx(job){
  const s    = normStatus(job.status||'');
  const ps   = normStatus(job.planning_status||'');
  const dept = getDept(job);

  if(ps.includes('dispatch') || s==='dispatched' || s === 'delivered') return 6;
  if(ps.includes('packing') || s==='packing done' || s==='packed' || s==='finished production' || s==='finished barcode') return 5;

  if(dept==='lsl'){
    return isDoneStatus(s) ? 5 : 4;
  }
  if(dept==='pack'){
    return isDoneStatus(s) ? 6 : 5;
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
  if(idx===5) return s==='running' && (dept==='pack' || String(job.department||'').toLowerCase().includes('pack')||String(job.department||'').toLowerCase().includes('dispatch'));
  return false;
}

function getStageName(job,idx){
  const cur=getStageIdx(job);
  const doneNames=['Planned','Jumbo Slitted','Flexo Printed','Die/Barcode Done','Label Slitted','Ready to Dispatch','Dispatched'];
  if(idx<cur) return doneNames[idx]||'Done';
  const active  =['Planning','Jumbo Slitting','Flexo Printing','Diecutting / Barcode','Label Slitting','Packing','Dispatched'];
  const inactive=['Planning','Jumbo Slitting','Flexo Printing','Diecutting / Barcode','Label Slitting','Packing','Dispatched'];
  if(idx===cur){
    if(isDoneStatus(job.status||'')) return doneNames[idx]||'Done';
    if(idx===0) return 'Planning';
    if(idx===6) return normStatus(job.status||'') === 'delivered' ? 'Delivered' : (isDispatchDone(job) ? 'Dispatched' : 'Preparing Dispatch');
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
  const map={running:'background:#dbeafe;color:#1e40af',pending:'background:#fef3c7;color:#92400e',queued:'background:#fef3c7;color:#92400e',closed:'background:#dcfce7;color:#166534',finalized:'background:#dcfce7;color:#166534',completed:'background:#dcfce7;color:#166534','qc passed':'background:#dcfce7;color:#166534','qc failed':'background:#fee2e2;color:#dc2626','packing done':'background:#dcfce7;color:#166534',packed:'background:#dcfce7;color:#166534','finished production':'background:#dcfce7;color:#166534','finished barcode':'background:#dcfce7;color:#166534',dispatched:'background:#dcfce7;color:#166534',delivered:'background:#dcfce7;color:#166534'};
  return map[s]||'background:#f1f5f9;color:#64748b';
}

function liveStatusLabel(status){
  const s = normStatus(status||'');
  if(s === 'delivered') return 'Delivered';
  return String(status||'Pending');
}

function liveStatusTitle(status){
  const s = normStatus(status||'');
  if(s === '') return 'Pending';
  return String(status||'Pending');
}

function routeByPlanningType(type){
  const key = String(type||'').trim().toLowerCase().replace(/[_\s]+/g,'-');
  const routes = {
    'jumbo-slitting': FL_BASE + '/modules/planning/slitting/index.php',
    'slitting': FL_BASE + '/modules/planning/slitting/index.php',
    'printing': FL_BASE + '/modules/planning/printing/index.php',
    'label-printing': FL_BASE + '/modules/planning/label/index.php',
    'label': FL_BASE + '/modules/planning/label/index.php',
    'die-cutting': FL_BASE + '/modules/planning/flatbed/index.php',
    'diecutting': FL_BASE + '/modules/planning/flatbed/index.php',
    'flatbed': FL_BASE + '/modules/planning/flatbed/index.php',
    'barcode': FL_BASE + '/modules/planning/barcode/index.php',
    'paperroll': FL_BASE + '/modules/planning/paperroll/index.php',
    'paper-roll': FL_BASE + '/modules/planning/paperroll/index.php',
    'pos-roll': FL_BASE + '/modules/planning/paperroll/index.php',
    'pos': FL_BASE + '/modules/planning/paperroll/index.php',
    'oneply': FL_BASE + '/modules/planning/paperroll/index.php',
    'two-ply': FL_BASE + '/modules/planning/paperroll/index.php',
    'twoply': FL_BASE + '/modules/planning/paperroll/index.php',
    'label-slitting': FL_BASE + '/modules/planning/label-slitting/index.php',
    'packaging': FL_BASE + '/modules/planning/packing/index.php',
    'packing': FL_BASE + '/modules/planning/packing/index.php',
    'dispatch': FL_BASE + '/modules/planning/dispatch/index.php',
    'batch-printing': FL_BASE + '/modules/planning/batch/index.php'
  };
  return routes[key] || '';
}

function inferPlanningRoute(job){
  const planExtra = (job && job.planning_extra_data && typeof job.planning_extra_data === 'object')
    ? job.planning_extra_data
    : {};
  const planTypeRaw = String(planExtra.planning_type || '').trim();
  if(planTypeRaw){
    const byType = routeByPlanningType(planTypeRaw);
    if(byType) return byType;
  }

  const planJobNo = String(job.job_no || '').trim().toUpperCase();
  if(planJobNo.startsWith('PLN-BAR/')) return FL_BASE + '/modules/planning/barcode/index.php';
  if(planJobNo.startsWith('PLN-POS/') || planJobNo.startsWith('PLN-1PL/') || planJobNo.startsWith('PLN-2PL/') || planJobNo.startsWith('PLN-PRL/')) return FL_BASE + '/modules/planning/paperroll/index.php';

  const planningStatus = normStatus(job.planning_status || '');
  if(planningStatus.includes('dispatch')) return FL_BASE + '/modules/planning/dispatch/index.php';
  if(planningStatus.includes('pack')) return FL_BASE + '/modules/planning/packing/index.php';
  if(planningStatus.includes('label slitting')) return FL_BASE + '/modules/planning/label-slitting/index.php';
  if(planningStatus.includes('printing')) return FL_BASE + '/modules/planning/printing/index.php';
  if(planningStatus.includes('slitting')) return FL_BASE + '/modules/planning/slitting/index.php';
  if(planningStatus.includes('barcode')) return FL_BASE + '/modules/planning/barcode/index.php';
  if(planningStatus.includes('flat') || planningStatus.includes('die') || planningStatus.includes('binding')) return FL_BASE + '/modules/planning/flatbed/index.php';

  const dieText = String(job.planning_die || '').toLowerCase();
  if(dieText.includes('rotary') || dieText.includes('barcode')) return FL_BASE + '/modules/planning/barcode/index.php';
  if(dieText.includes('flat') || dieText.includes('die')) return FL_BASE + '/modules/planning/flatbed/index.php';

  return FL_BASE + '/modules/planning/label/index.php';
}

function resolveLiveRoute(job){
  const id = Number(job && job.id);
  const isPlanningOnly = Number.isNaN(id) || String(job && job.job_type || '').toLowerCase() === 'planning';
  const jobNo = String(job && job.job_no || '').trim();

  if(!isPlanningOnly && jobNo !== ''){
    return {
      url: FL_BASE + '/modules/scan/dossier.php?jn=' + encodeURIComponent(jobNo),
      label: 'Open full job journey'
    };
  }

  return {
    url: inferPlanningRoute(job),
    label: 'Open planning board'
  };
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

function getJobSequenceToken(job){
  const fields = [job.job_no, job.prev_job_no, job.planning_job_name];
  for(let i=0;i<fields.length;i++){
    const v = String(fields[i]||'').trim().toUpperCase();
    if(!v) continue;
    const m = v.match(/(\d{4}\/\d{3,6})$/);
    // Include the full prefix before the year so different planning types with the
    // same sequence number (e.g. PLN-POS/2026/0001 vs PLN-1PL/2026/0001) don't collide.
    if(m && m[1]){
      const prefixPart = v.substring(0, v.length - m[1].length).replace(/\/$/, '');
      return (prefixPart ? prefixPart + '/' : '') + m[1];
    }
  }
  return '';
}

function logicalJobKey(job, byId){
  const planningId = Number((job && job.planning_id) || 0);
  if(planningId>0) return 'pln:'+planningId;

  const seq = getJobSequenceToken(job);
  if(seq){
    return 'seq:'+seq;
  }
  const root = getChainRootId(job, byId);
  if(root) return root;
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

const STAGE_ORDER = ['planning','slit','print','die','barcode','lsl','pos','pack','finished_production','dispatch'];
const STAGE_META = {
  planning:{label:'Planning', dept:'plan', badge:'PLN'},
  slit:{label:'Jumbo Slitting', dept:'slit', badge:'JMB'},
  print:{label:'Flexo Printing', dept:'print', badge:'FLX'},
  die:{label:'Die Cutting', dept:'die', badge:'DCT'},
  barcode:{label:'Barcode', dept:'die', badge:'BRC'},
  lsl:{label:'Label Slitting', dept:'lsl', badge:'LSL'},
  pos:{label:'POS Roll', dept:'pack', badge:'PRL'}, // POS Roll stage for paperroll jobs
  pack:{label:'Packing', dept:'pack', badge:'PKG'},
  finished_production:{label:'Finished Production', dept:'pack', badge:'FPD'},
  dispatch:{label:'Dispatch', dept:'pack', badge:'DSP'}
};

function isPlanningRow(job){
  return String(job && job.job_type || '').toLowerCase() === 'planning' || String(job && job.department || '').toLowerCase() === 'planning';
}

function parsePlanningExtra(job){
  if(job && job.planning_extra_parsed && typeof job.planning_extra_parsed === 'object') return job.planning_extra_parsed;
  try{
    const parsed = JSON.parse(String(job && job.planning_extra_data || '{}'));
    return parsed && typeof parsed === 'object' ? parsed : {};
  }catch(e){
    return {};
  }
}

function stageIndex(key){
  const idx = STAGE_ORDER.indexOf(key);
  return idx >= 0 ? idx : 999;
}

function uniqStageKeys(keys){
  const out = [];
  const seen = new Set();
  keys.forEach((key)=>{
    if(!key || seen.has(key)) return;
    seen.add(key);
    out.push(key);
  });
  return out;
}

function normalizePlanningType(type){
  return String(type||'').trim().toLowerCase().replace(/[\s-]+/g,'_');
}

function planningProgressInfo(base, planningExtra){
  const planType = normalizePlanningType(planningExtra && planningExtra.planning_type || '');
  const primary = ['pos_roll','one_ply','two_ply'].includes(planType)
    ? ''
    : String(planningExtra && planningExtra.printing_planning || '').trim();
  const fallback = String(base && (base.planning_status || base.status) || '').trim();
  const raw = primary || fallback || 'Pending';
  const norm = normStatus(raw);

  const isPaperrollType = ['pos_roll','one_ply','two_ply'].includes(planType);
  if(norm.includes('finished production')) return {raw, norm, key:'finished_production', statusText:'Finished Production'};
  if(norm.includes('finished barcode')) return {raw, norm, key: isPaperrollType ? 'pos' : 'barcode', statusText:'Finished Barcode'};
  if(norm.includes('dispatch') || norm.includes('delivered')) return {raw, norm, key:'dispatch', statusText:raw};
  if(norm.includes('packing') || norm.includes('packed')) return {raw, norm, key:'pos', statusText:raw};
  // Never show barcode stage for paperroll/PRL jobs
  if(isPaperrollType && (norm.includes('barcoded') || norm === 'barcode done' || norm.includes('barcode'))) return {raw, norm, key:'pos', statusText:raw};
  if(norm.includes('barcoded') || norm === 'barcode done' || norm.includes('barcode')) return {raw, norm, key:'barcode', statusText:raw};
  if(norm.includes('label slitting')) return {raw, norm, key:'lsl', statusText:raw};
  if(norm.includes('die') || norm.includes('flat') || norm.includes('binding')) return {raw, norm, key:'die', statusText:raw};
  if(norm.includes('printing')) return {raw, norm, key:'print', statusText:raw};
  if(norm.includes('slitting') || norm.includes('preparing')) return {raw, norm, key:'slit', statusText:raw};
  return {raw, norm, key:'planning', statusText:raw || 'Pending'};
}

function doneStatusForStage(key){
  const map = {
    planning:'Planned',
    slit:'Jumbo Slitted',
    print:'Printed',
    die:'Die Cut',
    barcode:'Barcoded',
    lsl:'Label Slitted',
    pos:'PosRoll Done',
    pack:'Packed',
    finished_production:'Finished Production',
    dispatch:'Dispatched'
  };
  return map[key] || 'Done';
}

function getStageKeyForJob(job){
  if(isPlanningRow(job)) return 'planning';
  const jt = String(job.job_type||'').toLowerCase().trim();
  const dept = String(job.department||'').toLowerCase().trim();
  const jobNo = String(job.job_no||'').toUpperCase().trim();

  // Special handling for PLN-BAR/ prefix jobs - they are barcode jobs
  if(jobNo.startsWith('PLN-BAR/')) return 'barcode';
  
  if(jt === 'slitting' || dept === 'jumbo_slitting' || dept.includes('jumbo')) return 'slit';
  if(jt === 'printing' || jt === 'flexo' || dept === 'flexo_printing' || dept.includes('print') || jobNo.startsWith('FLX/')) return 'print';
  if(dept === 'barcode' || jt === 'barcode' || jobNo.startsWith('BRC/')) return 'barcode';
  if(dept === 'pos' || dept === 'paperroll' || jt === 'pos' || jt === 'paperroll' || jobNo.startsWith('POS/') || jobNo.startsWith('OPL/') || jobNo.startsWith('TPL/') || jobNo.startsWith('PRL/')) return 'pos';
  if(dept === 'packing' || jt === 'packing') return 'pack';
  if(dept.includes('dispatch')) return 'dispatch';
  if(dept.includes('label-slitting') || dept.includes('label slitting') || jobNo.startsWith('LSL/')) return 'lsl';
  if(dept.includes('flatbed') || dept.includes('die') || jt.includes('die') || jobNo.startsWith('DCT/') || jobNo.startsWith('ROT/')) return 'die';
  return 'planning';
}

function planningStatusStageKey(status, planningExtra){
  const s = normStatus(status||'');
  const planType = normalizePlanningType(planningExtra && planningExtra.planning_type || '');
  if(s.includes('dispatch') || s.includes('delivered')) return 'dispatch';
  if(s.includes('finished production')) return 'finished_production';
  if(s.includes('packing') || s.includes('packed') || s.includes('finished barcode')) return 'pack';
  if(s.includes('label slitting')) return 'lsl';
  if((s.includes('barcode') && !s.includes('finished barcode')) || s.includes('rotary')) return 'barcode';
  if(s.includes('die') || s.includes('flat') || s.includes('binding')) return 'die';
  if(s.includes('printing')) return 'print';
  if(s.includes('pos')) return 'pos';
  if(s.includes('slitting') || s.includes('preparing')) return 'slit';
  if(['pos_roll','one_ply','two_ply'].includes(planType) && s !== '' && s !== 'pending') return 'pos';
  return 'planning';
}

function compareJobOrder(a,b){
  const seqA = Number(a.sequence_order || 0);
  const seqB = Number(b.sequence_order || 0);
  if(seqA !== seqB) return seqA - seqB;
  const timeA = String(a.created_at || '');
  const timeB = String(b.created_at || '');
  if(timeA !== timeB) return timeA.localeCompare(timeB);
  return Number(a.id || 0) - Number(b.id || 0);
}

function pickBetterStageJob(current,next){
  if(!current) return next;
  const curRun = normStatus(current.status) === 'running' ? 1 : 0;
  const nxtRun = normStatus(next.status) === 'running' ? 1 : 0;
  if(curRun !== nxtRun) return nxtRun > curRun ? next : current;
  const curDone = isDoneStatus(current.status||'') ? 1 : 0;
  const nxtDone = isDoneStatus(next.status||'') ? 1 : 0;
  if(curDone !== nxtDone) return nxtDone < curDone ? next : current;
  return compareJobOrder(current,next) <= 0 ? next : current;
}

function inferPlannedStageKeys(base, stageMap, actualJobs){
  const planningExtra = parsePlanningExtra(base);
  const progress = planningProgressInfo(base, planningExtra);
  const planningStatus = progress.norm;
  const planType = normalizePlanningType(planningExtra.planning_type || '');
  const planningDie = String(base.planning_die || planningExtra.die || '').toLowerCase();
  const actualKeys = actualJobs.map(getStageKeyForJob);
  const actualSet = new Set(actualKeys);
  const keys = ['planning'];

  const has = (key)=> actualSet.has(key) || stageMap.has(key);
  const add = (key)=>{ if(key) keys.push(key); };

  const directFlexoBypass = Boolean(planningExtra.direct_flexo_bypass) || actualJobs.some(j => Boolean(j.extra_data_parsed && j.extra_data_parsed.direct_flexo_bypass));
  const directBarcodeBypass = Boolean(planningExtra.direct_barcode_bypass) || actualJobs.some(j => Boolean(j.extra_data_parsed && j.extra_data_parsed.direct_barcode_bypass));
  // Fix: include PLN-PRL/ in paperroll detection
  const isPaperroll = ['pos_roll','one_ply','two_ply'].includes(planType)
    || /^PLN-(POS|1PL|2PL|PRL)\//.test(String(base.job_no || '').toUpperCase())
    || has('pos');
  const hasPaperrollDownstream = has('print') || has('barcode') || has('die') || has('pos') || ['print','die','barcode','pos','dispatch'].includes(progress.key);

  if(has('slit') || (!isPaperroll && (planningStatus.includes('slitting') || planningStatus.includes('preparing') || actualJobs.length > 0)) || (isPaperroll && (planningStatus.includes('slitting') || actualJobs.length > 0))){
    add('slit');
  }

  if(isPaperroll){
    if(has('print') || planningStatus.includes('printing')) add('print');
    // Never show barcode stage for paperroll/PRL jobs (Fix 4)
    if(has('pos') || planningStatus.includes('pos') || planningStatus.includes('finished barcode') || planningStatus.includes('barcode') || (!has('print') && !has('barcode') && !has('die') && actualJobs.length > 0)) add('pos');
    // Do NOT add barcode for paperroll/PRL jobs
  }else{
    // Print stage is only included if:
    // - an actual print job exists, OR
    // - planning status explicitly mentions printing, OR
    // - die/lsl stages exist (they require printing upstream)
    // NOTE: barcode alone does NOT imply flexo printing (barcode-only flow is valid).
    if(!directFlexoBypass && (has('print') || planningStatus.includes('printing') || has('die') || has('lsl'))){
      add('print');
    }

    const needsBarcode = has('barcode') || planningStatus.includes('barcode') || planningStatus.includes('rotary') || planningDie.includes('rotary') || planningDie.includes('barcode');
    const needsDie = has('die') || planningStatus.includes('die') || planningStatus.includes('flat') || planningStatus.includes('binding') || planningDie.includes('flat');

    if(needsDie) add('die');
    if(needsBarcode && !directBarcodeBypass) add('barcode');
    if(has('lsl') || planningStatus.includes('label slitting')) add('lsl');
  }

  // Always add Packing and Finished Production at the end
  add('pack');
  add('finished_production');
  if(has('dispatch') || planningStatus.includes('dispatch') || planningStatus.includes('delivered')) add('dispatch');

  actualKeys.forEach(add);
  return uniqStageKeys(keys).sort((a,b)=>stageIndex(a)-stageIndex(b));
}

function buildLiveCard(rows){
  const actualJobs = rows.filter(r => !isPlanningRow(r)).slice().sort(compareJobOrder);
  const planningRows = rows.filter(isPlanningRow).slice().sort(compareJobOrder);
  const base = planningRows[0] || actualJobs[0] || rows[0] || {};
  const planningExtra = parsePlanningExtra(base);
  const progress = planningProgressInfo(base, planningExtra);
  const stageMap = new Map();

  actualJobs.forEach((job)=>{
    const key = getStageKeyForJob(job);
    stageMap.set(key, pickBetterStageJob(stageMap.get(key), job));
  });

  // POS stage should be treated as completed when any POS-family output job is effectively completed.
  const hasCompletedPosOutput = actualJobs.some((job) => {
    const key = getStageKeyForJob(job);
    if (key !== 'pos') return false;

    const dept = normStatus(job.department || '');
    const jt = normStatus(job.job_type || '');
    const jobNo = String(job.job_no || '').toUpperCase();
    const isPosFamily = jobNo.startsWith('POS/') || jobNo.startsWith('OPL/') || jobNo.startsWith('TPL/') || jobNo.startsWith('PRL/')
      || dept === 'pos' || dept === 'pos roll' || dept === 'paperroll' || dept === 'one ply' || dept === 'two ply'
      || jt === 'pos' || jt === 'pos roll' || jt === 'paperroll' || jt === 'one ply' || jt === 'two ply';
    if (!isPosFamily) return false;

    const completedAt = String(job.completed_at || '').trim();
    const hasCompletedAt = completedAt !== '' && completedAt !== '0000-00-00 00:00:00';
    const extra = job.extra_data_parsed && typeof job.extra_data_parsed === 'object' ? job.extra_data_parsed : {};
    const hasCompletionFlag = ['finished_production_flag', 'finished_barcode_flag', 'packing_done_flag', 'packing_packed_flag', 'auto_created_from_slitting']
      .some((k) => extra[k] === 1 || extra[k] === '1' || extra[k] === true || extra[k] === 'true');

    return isDoneStatus(job.status || '') || hasCompletedAt || hasCompletionFlag;
  });

  const hasFinishedProductionEvidence = actualJobs.some((job) => {
    const s = normStatus(job.status || '');
    if (s === 'finished production' || s === 'finished barcode') return true;
    const extra = job.extra_data_parsed && typeof job.extra_data_parsed === 'object' ? job.extra_data_parsed : {};
    const finishedFlag = Number(extra.finished_production_flag || 0) === 1 || String(extra.finished_production_at || '').trim() !== '';
    return finishedFlag;
  });

  // Paperroll can be effectively completed even when raw status is still pending,
  // once child roll assignment/batch linkage has already happened.
  const hasPaperrollConsumption = actualJobs.some((job) => {
    const key = getStageKeyForJob(job);
    if (key !== 'pos') return false;

    const extra = job.extra_data_parsed && typeof job.extra_data_parsed === 'object' ? job.extra_data_parsed : {};
    const assignedChildRollCount = Number(
      extra.assigned_child_roll_count
      || extra.assigned_child_rolls_count
      || extra.child_roll_count
      || 0
    );
    const assignedLastBatchNo = String(
      extra.assigned_last_batch_no
      || extra.last_batch_no
      || extra.batch_no
      || ''
    ).trim();
    const directBypassVal = extra.direct_paperroll_bypass;
    const directBypass = directBypassVal === 1 || directBypassVal === '1' || directBypassVal === true
      || ['true', 'yes', 'y', 'on'].includes(String(directBypassVal || '').toLowerCase().trim());
    const rollConsumed = normStatus(job.roll_status || '') === 'consumed';

    return assignedChildRollCount > 0 || assignedLastBatchNo !== '' || directBypass || rollConsumed;
  });

  // --- Packing override: force POS and PaperRoll to completed BEFORE any stage selection ---
  let packingJob = actualJobs.find(j => getStageKeyForJob(j) === 'pack');
  if (packingJob) {
    if (stageMap.has('pos')) {
      const posJob = stageMap.get('pos');
      if (posJob) {
        posJob.status = 'Completed';
        posJob.is_done = true;
        stageMap.set('pos', posJob);
      }
    }
    if (stageMap.has('paperroll')) {
      const prJob = stageMap.get('paperroll');
      if (prJob) {
        prJob.status = 'Completed';
        prJob.is_done = true;
        stageMap.set('paperroll', prJob);
      }
    }
    if (typeof window !== 'undefined' && window.console) {
      console.log("OVERRIDE APPLIED", Array.from(stageMap.entries()));
    }
  }

  // Now calculate pathKeys and active stage
  const pathKeys = actualJobs.length === 0
    ? ['planning']
    : inferPlannedStageKeys(base, stageMap, actualJobs);

  const planningKey = progress.key || planningStatusStageKey(base.planning_status || base.status || '', planningExtra);

  let currentKey = 'planning';
  let activeJob = null;

  // --- Packing strict priority logic ---
  // If a packing job exists, its status always determines the Packing stage, regardless of POS or any fallback.
  // Packing status always overrides, and POS completed never marks Packing as done.
  if (packingJob) {
    // Find the index of 'pack' in pathKeys
    const packIdx = pathKeys.indexOf('pack');
    if (packIdx !== -1) {
      const s = normStatus(packingJob.status);
      if (s === 'packing') {
        currentKey = 'pack';
        activeJob = packingJob;
      } else if ([
        'packed','packing done','finalized','closed','completed','dispatched','delivered','finished production','finished barcode'
      ].includes(s)) {
        // Packing is done, move to next logical stage
        // Find first non-done stage after 'pack'
        let found = false;
        for (let i = packIdx + 1; i < pathKeys.length; i++) {
          const key = pathKeys[i];
          const job = stageMap.get(key);
          if (job && !isDoneStatus(job.status||'')) {
            currentKey = key;
            activeJob = job;
            found = true;
            break;
          }
        }
        // If all later stages are done, keep last stage as current
        if (!found) {
          currentKey = pathKeys[pathKeys.length - 1];
          activeJob = stageMap.get(currentKey) || null;
        }
      } else {
        // Any other status, treat as active
        currentKey = 'pack';
        activeJob = packingJob;
      }
    }
  } else {
    // No packing job, use default logic.
    // 1) Prefer any actively running/started job.
    // 2) Fall back to the FIRST non-done job in workflow order (the real bottleneck).
    for(let i=0;i<actualJobs.length;i++){
      const s = normStatus(actualJobs[i].status||'');
      if(s === 'running' || s === 'started' || s === 'in progress'){
        activeJob = actualJobs[i];
        currentKey = getStageKeyForJob(activeJob);
        break;
      }
    }
    if(!activeJob){
      for(let i=0;i<actualJobs.length;i++){
        const job = actualJobs[i];
        if(!isDoneStatus(job.status||'')){
          activeJob = job;
          currentKey = getStageKeyForJob(job);
          break;
        }
      }
    }
    if(!activeJob && actualJobs.length > 0){
      const lastActualKey = getStageKeyForJob(actualJobs[actualJobs.length-1]);
      currentKey = lastActualKey;
      if(stageIndex(planningKey) > stageIndex(lastActualKey) && pathKeys.includes(planningKey)){
        currentKey = planningKey;
      }
      // If all jobs are done, set currentKey to the last stage in pathKeys
      const allJobsDone = actualJobs.every(job => isDoneStatus(job.status || ''));
      if(allJobsDone && pathKeys.length > 0) {
        currentKey = pathKeys[pathKeys.length - 1];
      }
    }
    if(activeJob){
      // Trust the actual active job's stage. planningKey indicates the job's
      // intended destination/type, NOT the current active stage when real jobs exist.
      // Do NOT override currentKey with planningKey here.
    }
    if(actualJobs.length === 0){
      currentKey = planningKey || 'planning';
    }
  }

  if (hasFinishedProductionEvidence && pathKeys.includes('finished_production')) {
    currentKey = 'finished_production';
  }

  const currentIndex = Math.max(0, pathKeys.indexOf(currentKey));
  const planningLabel = String(base.planning_status || base.status || 'Planning').trim() || 'Planning';

    const stages = pathKeys.map((key, index)=>{
      let debugInfo = '';
      const meta = STAGE_META[key] || STAGE_META.planning;
      let stageJob = stageMap.get(key) || null;
      let state = 'later';
      if(index < currentIndex) state = 'done';
      else if(index === currentIndex && key === 'finished_production') state = 'done'; // terminal — always green tick
      else if(index === currentIndex && stageJob) state = 'now';
      if(actualJobs.length === 0 && key === 'planning') state = 'now';

      if (hasFinishedProductionEvidence && key === 'finished_production' && index !== currentIndex) {
        state = 'done';
      }

      let stageStatus = meta.label;

      // Finished Production: always green done when evidence exists
      if (key === 'finished_production' && hasFinishedProductionEvidence) {
        stageStatus = 'Finished Production';
        state = 'done';
      } else if(key === 'pos') {
        if (packingJob || hasCompletedPosOutput || hasPaperrollConsumption || stageMap.has('barcode') || stageMap.has('lsl') || stageMap.has('pack')) {
          // POS is completed if packing/downstream exists or any POS-family output is completed.
          stageStatus = 'Completed';
          state = 'done';
        } else if(stageJob) {
          stageStatus = liveStatusLabel(stageJob.status);
        }
      }
      // --- End POS Roll override ---
      else if(key === 'planning'){
        stageStatus = state === 'done' ? 'Planned' : planningLabel;
      } else if(stageJob) {
        // --- Packing stage logic: Packing job status is the only authority ---
        if(key === 'pack') {
          if (packingJob && stageJob && packingJob.id === stageJob.id) {
            const s = normStatus(packingJob.status);
            if(s === 'packing') {
              state = 'now';
              stageStatus = 'Packing';
            } else if(['packed','packing done','finalized','closed','completed','dispatched','delivered'].includes(s)) {
              state = 'done';
              stageStatus = liveStatusLabel(packingJob.status);
            } else {
              state = 'now';
              stageStatus = liveStatusLabel(packingJob.status);
            }
          } else if(stageJob) {
            // If for some reason another job is mapped, fallback to its status
            stageStatus = liveStatusLabel(stageJob.status);
          } else {
            stageStatus = meta.label;
          }
        } else {
          stageStatus = liveStatusLabel(stageJob.status);
        }
        if(key === planningKey && stageIndex(planningKey) >= stageIndex(getStageKeyForJob(stageJob))){
          stageStatus = progress.statusText;
        }
      } else if(key === planningKey) {
        stageStatus = progress.statusText;
      } else if(key === 'pack' && hasFinishedProductionEvidence) {
        stageStatus = 'Packed';
        state = 'done';
      } else if(index < currentIndex) {
        stageStatus = doneStatusForStage(key);
      } else if(state === 'now') {
        stageStatus = progress.statusText || planningLabel;
      } else if((key === 'pack' || key === 'finished_production') && state === 'later') {
        stageStatus = 'Queued';
      }

      let timeText = '—';
      if(key === 'planning') timeText = fmtStageDate(base.planning_created_at || base.created_at || '');
      else if(stageJob) timeText = fmtStageDate(stageJob.completed_at || stageJob.started_at || stageJob.created_at || '');
      else if(key === 'dispatch') timeText = fmtStageDate(base.planning_dispatch_date || '');

      return {
        key,
        label: meta.label,
        dept: meta.dept,
        badge: meta.badge,
        state,
        statusText: stageStatus,
        timeText,
        job: stageJob,
        debugInfo
      };
    });

  const latestJob = actualJobs.length ? actualJobs[actualJobs.length-1] : null;
  const displaySource = activeJob || latestJob || base;
  const currentStage = stages[currentIndex] || stages[0] || {dept:'plan',label:'Planning',statusText:planningLabel};

  return {
    id: String(base.id || rows[0] && rows[0].id || ''),
    job_type: actualJobs.length ? String(displaySource.job_type || '') : 'Planning',
    department: currentStage.key === 'planning' ? 'planning' : String(displaySource.department || ''),
    planning_id: Number(base.planning_id || 0),
    planning_job_name: String(base.planning_job_name || base.job_no || ''),
    planning_priority: String(base.planning_priority || 'Normal'),
    planning_status: String(base.planning_status || base.status || ''),
    planning_created_at: String(base.planning_created_at || base.created_at || ''),
    planning_dispatch_date: String(base.planning_dispatch_date || ''),
    planning_image_url: String(base.planning_image_url || ''),
    planning_die: String(base.planning_die || ''),
    job_no: String(displaySource.job_no || base.job_no || ''),
    roll_no: String(displaySource.roll_no || ''),
    paper_type: String(displaySource.paper_type || ''),
    gsm: displaySource.gsm,
    width_mm: displaySource.width_mm,
    pending_change_requests: rows.reduce((sum,row)=>sum + Number(row.pending_change_requests || 0),0),
    currentDept: currentStage.dept,
    currentBadge: currentStage.badge,
    currentStageKey: currentStage.key,
    currentStageLabel: currentStage.label,
    currentStatus: currentStage.statusText,
    displaySource,
    activeJob,
    latestJob,
    stages,
    rows
  };
}

function aggregateLiveCards(rows){
  const byId = new Map();
  rows.forEach((row)=>{
    const id = Number(row.id || 0);
    if(id > 0) byId.set(id,row);
  });

  const grouped = new Map();
  rows.forEach((row)=>{
    const key = logicalJobKey(row, byId);
    if(!grouped.has(key)) grouped.set(key, []);
    grouped.get(key).push(row);
  });

  return Array.from(grouped.values()).map(buildLiveCard);
}

function getCardDept(card){
  return String(card && card.currentDept || 'plan');
}

function getCardProgressRank(card){
  if(!card || !Array.isArray(card.stages)) return 0;
  return Math.max(0, card.stages.findIndex(stage => stage.key === card.currentStageKey));
}

function resolveCardRoute(card){
  const source = card && (card.activeJob || card.latestJob || card.displaySource || null);
  if(source && !isPlanningRow(source) && String(source.job_no || '').trim() !== ''){
    return {
      url: FL_BASE + '/modules/scan/dossier.php?jn=' + encodeURIComponent(String(source.job_no || '').trim()),
      label: 'Open full job journey'
    };
  }
  return resolveLiveRoute(card && card.displaySource ? card.displaySource : card);
}

/* ─── Filter predicate ─── */
function matchFilter(job){
  const dept=getCardDept(job);
  const s=normStatus(job.currentStatus||job.status||'');
  const pri=(job.planning_priority||'Normal').toLowerCase();
  if(FL_ACTIVE_FILTER==='plan'   && dept!=='plan') return false;
  if(FL_ACTIVE_FILTER==='slit'   && dept!=='slit') return false;
  if(FL_ACTIVE_FILTER==='print'  && dept!=='print') return false;
  if(FL_ACTIVE_FILTER==='finish' && !['die','lsl','pack'].includes(dept)) return false;
  if(FL_ACTIVE_FILTER==='running' && s!=='running') return false;
  if(FL_ACTIVE_FILTER==='urgent' && !['urgent','high'].includes(pri)) return false;
  if(FL_SEARCH){
    const q=FL_SEARCH.toLowerCase();
    const hay=(String(job.job_no||'')+' '+String(job.planning_job_name||'')+' '+String(job.roll_no||'')+' '+String(job.currentStageLabel||'')).toLowerCase();
    if(!hay.includes(q)) return false;
  }
  return true;
}

/* ─── Fetch & Render ─── */
async function flLoad(){
  try{
    const p=new URLSearchParams({action:'list_live_floor',csrf_token:FL_CSRF,limit:'600'});
    const r=await fetch(FL_API+'?'+p.toString(),{cache:'no-store'});
    let data;
    try {
      data = await r.json();
    } catch (err) {
      // If response is not JSON, try to get text and check for HTML
      const raw = await r.text();
      console.error("API response is not JSON. Raw:", raw);
      if (typeof raw === 'string' && raw.includes('<html')) {
        throw new Error('Session expired or not authenticated');
      }
      throw new Error('API returned invalid JSON');
    }
    console.log("API RAW RESPONSE:", data);
    if (typeof data === 'string' && data.includes('<html')) {
      throw new Error('Session expired or not authenticated');
    }
    const jobs = data.jobs || [];
    console.log("JOBS ARRAY:", jobs);
    if(!data.ok||!Array.isArray(jobs)) {
      console.error("Invalid API response:", data);
      throw new Error('API error');
    }

    // Normalize/parse payload fields that drive completion logic before building stage maps.
    jobs.forEach((j) => {
      if (!j || typeof j !== 'object') return;

      if (!j.extra_data_parsed || typeof j.extra_data_parsed !== 'object') {
        try {
          const parsed = JSON.parse(String(j.extra_data || '{}'));
          j.extra_data_parsed = parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
          j.extra_data_parsed = {};
        }
      }

      if (!j.planning_extra_parsed || typeof j.planning_extra_parsed !== 'object') {
        try {
          const parsed = JSON.parse(String(j.planning_extra_data || '{}'));
          j.planning_extra_parsed = parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
          j.planning_extra_parsed = {};
        }
      }

      const extra = j.extra_data_parsed || {};
      const liveStatus = normStatus(j.status || '');
      const finishedFlag = Number(extra.finished_production_flag || 0) === 1 || String(extra.finished_production_at || '').trim() !== '';
      const packingFlag = Number(extra.packing_done_flag || 0) === 1 || String(extra.packing_done_at || '').trim() !== '';

      if (finishedFlag && liveStatus !== 'dispatched' && liveStatus !== 'delivered') {
        j.status = 'Finished Production';
        if (!j.completed_at && extra.finished_production_at) {
          j.completed_at = extra.finished_production_at;
        }
      } else if (packingFlag) {
        const isTerminal = ['dispatched', 'delivered', 'finished production'].includes(liveStatus);
        if (!isTerminal) {
          j.status = 'Packing Done';
        }
        if (!j.completed_at && extra.packing_done_at) {
          j.completed_at = extra.packing_done_at;
        }
      }
    });

    FL_ALL_JOBS = aggregateLiveCards(jobs);
    renderStats(FL_ALL_JOBS);
    renderFilterCounts(FL_ALL_JOBS);
    renderJobs(FL_ALL_JOBS.filter(matchFilter));
  }catch(e){
    let msg = 'Unable to load production data';
    if (e && e.message && e.message.includes('Session expired')) {
      msg = 'Session expired or not authenticated. Please log in again.';
    }
    document.getElementById('flJobs').innerHTML='<div class="fl-empty"><div class="fl-empty-icon">⚠️</div><p>'+msg+'</p></div>';
    console.error(e);
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
    const ra=normStatus(a.currentStatus||a.status)==='running'?0:1,rb=normStatus(b.currentStatus||b.status)==='running'?0:1;
    if(ra!==rb) return ra-rb;
    const sa=getCardProgressRank(a),sb=getCardProgressRank(b);
    if(sa!==sb) return sb-sa;
    return(priOrd[a.planning_priority]||2)-(priOrd[b.planning_priority]||2);
  });

  let html='';
  jobs.forEach((job,i)=>{
    const dept  = getCardDept(job);
    const pri   = (job.planning_priority||'Normal').toLowerCase();
    const ref   = getDisplayJobRef(job.displaySource || job);
    const cr    = Number(job.pending_change_requests||0);
    const imgURL= String(job.planning_image_url||'').trim();
    const dispDate = String(job.planning_dispatch_date||'').trim();
    const route = resolveCardRoute(job);
    const cardRoute = route && route.url ? String(route.url) : '';
    const cardTitle = route && route.label ? String(route.label) : 'Open details';

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
    (job.stages||[]).forEach((stage)=>{
      // Always use resolved stageStatus for POS
      nodes+=`<div class="fl-node ${stage.state}"><div class="fl-circle"></div><div class="fl-stage-name">${esc(stage.label)}</div><div class="fl-stage-time">${esc(stage.statusText || stage.timeText || '—')}</div>${stage.debugInfo||''}</div>`;
    });

    html+=`<div class="fl-card ${CARD_CLASS[dept]||''} ${cardRoute?'fl-card-link':''}" style="animation-delay:${Math.min(i,15)*.04}s" ${cardRoute?`data-route="${esc(cardRoute)}" tabindex="0" role="link" aria-label="${esc(cardTitle)}: ${esc(ref)}" title="${esc(cardTitle)}"`:''}>
      <div class="fl-card-head">
        ${thumb}
        <div class="fl-card-info">
          <div class="fl-card-row1">
            <span class="fl-card-jobno" style="font-size:.9rem;font-weight:900;color:#1a1a2e">${esc(ref)}</span>
            <span class="fl-status-badge" style="${stBadge(job.currentStatus)}" title="${esc(liveStatusTitle(job.currentStatus))}">${esc(liveStatusLabel(job.currentStatus))}</span>
            <span class="fl-dept-badge ${DEPT_CLASS[dept]||''}">${esc(job.currentBadge || DEPT_LABEL[dept] || '—')}</span>
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

document.getElementById('flJobs').addEventListener('click',function(event){
  const card = event.target.closest('.fl-card[data-route]');
  if(!card) return;
  const route = String(card.dataset.route || '').trim();
  if(route) window.location.href = route;
});

document.getElementById('flJobs').addEventListener('keydown',function(event){
  if(event.key !== 'Enter' && event.key !== ' ') return;
  const card = event.target.closest('.fl-card[data-route]');
  if(!card) return;
  event.preventDefault();
  const route = String(card.dataset.route || '').trim();
  if(route) window.location.href = route;
});

function renderStats(jobs){
  const planC  = jobs.filter(j=>getCardDept(j)==='plan').length;
  const slitC  = jobs.filter(j=>getCardDept(j)==='slit').length;
  const printC = jobs.filter(j=>getCardDept(j)==='print').length;
  const finC   = jobs.filter(j=>['die','lsl','pack'].includes(getCardDept(j))).length;
  document.getElementById('sTotal').textContent = jobs.length;
  document.getElementById('sPlan').textContent  = planC;
  document.getElementById('sSlit').textContent  = slitC;
  document.getElementById('sPrint').textContent = printC;
  document.getElementById('sDone').textContent  = finC;
}

function renderFilterCounts(jobs){
  document.getElementById('fcAll').textContent    = jobs.length;
  document.getElementById('fcPlan').textContent   = jobs.filter(j=>getCardDept(j)==='plan').length;
  document.getElementById('fcSlit').textContent   = jobs.filter(j=>getCardDept(j)==='slit').length;
  document.getElementById('fcPrint').textContent  = jobs.filter(j=>getCardDept(j)==='print').length;
  document.getElementById('fcFinish').textContent = jobs.filter(j=>['die','lsl','pack'].includes(getCardDept(j))).length;
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

