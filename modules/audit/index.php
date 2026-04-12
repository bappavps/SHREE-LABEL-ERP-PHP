<?php
// ============================================================
// ERP System — Audit Hub: Physical Stock Check
// Full inventory reconciliation dashboard
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/setup_tables.php';

ensureAuditTables();

$db   = getDB();
$csrf = generateCSRF();
$isUserAdmin = isAdmin();

$pageTitle = 'Audit Hub';
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Audit Hub Styles ─────────────────────────────────────── */
.ah-top-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px}
.ah-top-bar h1{margin:0;font-size:1.35rem;font-weight:800}
.ah-top-bar p{margin:2px 0 0;color:var(--text-muted);font-size:.82rem}

.ah-sessions{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:20px}
.ah-sess-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px;cursor:pointer;transition:all .18s;position:relative}
.ah-sess-card:hover{border-color:#86efac;box-shadow:0 4px 16px rgba(0,0,0,.08)}
.ah-sess-card.active{border-color:var(--brand);box-shadow:0 0 0 3px rgba(34,197,94,.15)}
.ah-sess-card .sess-name{font-size:.88rem;font-weight:700;color:var(--text-main);margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ah-sess-card .sess-id{font-size:.7rem;font-family:monospace;color:var(--text-muted)}
.ah-sess-card .sess-date{font-size:.7rem;color:var(--text-muted);margin-top:4px}
.ah-sess-card .sess-badge{position:absolute;top:12px;right:12px}
.ah-sess-del{position:absolute;bottom:10px;right:12px;background:none;border:none;color:#dc2626;cursor:pointer;font-size:.85rem;padding:4px 6px;border-radius:6px;transition:all .15s;z-index:2;opacity:.6}
.ah-sess-del:hover{opacity:1;background:rgba(220,38,38,.08)}

.ah-panel{display:none;background:#fff;border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-md);margin-bottom:20px}
.ah-panel.visible{display:block}
.ah-panel-head{background:#0f172a;color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.ah-panel-head .panel-title{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:10px}
.ah-panel-head .panel-meta{font-size:.72rem;color:#94a3b8;display:flex;gap:16px;flex-wrap:wrap}
.ah-panel-body{padding:20px 24px}

/* Scan input */
.ah-scan-bar{display:flex;gap:10px;align-items:stretch;margin-bottom:20px;flex-wrap:wrap}
.ah-scan-input{flex:1;min-width:200px;height:48px;border:2px solid var(--border);border-radius:12px;padding:0 16px;font-size:1rem;font-weight:600;font-family:monospace;transition:border-color .2s}
.ah-scan-input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(34,197,94,.1)}
.ah-scan-btn{height:48px;padding:0 24px;border-radius:12px;border:none;background:var(--brand);color:#fff;font-size:.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .15s}
.ah-scan-btn:hover{background:var(--brand-dark)}
.ah-scan-btn:disabled{opacity:.5;cursor:not-allowed}
.ah-cam-btn{height:48px;width:48px;border-radius:12px;border:2px solid var(--border);background:#fff;color:var(--text-muted);font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.ah-cam-btn:hover{border-color:var(--brand);color:var(--brand)}
.ah-cam-btn.active{border-color:#7c3aed;color:#7c3aed;background:#faf5ff}

/* Camera viewport */
.ah-camera-wrap{display:none;margin-bottom:20px;background:#000;border-radius:12px;overflow:hidden;max-width:420px}
.ah-camera-wrap.open{display:block}
.ah-camera-wrap #ah-camera-reader{text-align:center}
.ah-camera-wrap #ah-camera-reader video{display:block;margin:0 auto;width:100% !important;height:auto !important;max-height:none !important;object-fit:cover;background:#000}
.ah-camera-wrap #ah-camera-reader #qr-shaded-region{margin:0 auto !important}

/* Duplicate popup */
.ah-dup-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(.8);z-index:9999;background:#fff;border:3px solid #dc2626;border-radius:16px;padding:28px 36px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);opacity:0;transition:all .2s ease}
.ah-dup-popup.show{display:block;opacity:1;transform:translate(-50%,-50%) scale(1)}
.ah-dup-popup .dup-icon{font-size:2.5rem;color:#dc2626;margin-bottom:8px}
.ah-dup-popup .dup-title{font-size:1.1rem;font-weight:800;color:#dc2626;margin-bottom:4px}
.ah-dup-popup .dup-roll{font-family:monospace;font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:4px}
.ah-dup-popup .dup-msg{font-size:.82rem;color:#64748b}
.ah-dup-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(220,38,38,.08)}
.ah-dup-overlay.show{display:block}

/* Scan feedback ring */
.ah-feedback{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:160px;height:160px;border-radius:50%;border:6px solid transparent;pointer-events:none;align-items:center;justify-content:center}
.ah-feedback.fb-success{border-color:#22c55e;background:rgba(34,197,94,.12)}
.ah-feedback.fb-warning{border-color:#f59e0b;background:rgba(245,158,11,.12)}
.ah-feedback.fb-error{border-color:#ef4444;background:rgba(239,68,68,.12)}
.ah-feedback i{font-size:3rem}
.ah-feedback.fb-success i{color:#22c55e}
.ah-feedback.fb-warning i{color:#f59e0b}
.ah-feedback.fb-error i{color:#ef4444}
@keyframes ahPulse{0%{transform:translate(-50%,-50%) scale(.5);opacity:0}50%{transform:translate(-50%,-50%) scale(1.1);opacity:1}100%{transform:translate(-50%,-50%) scale(1);opacity:0}}

/* Stats cards */
.ah-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px}
.ah-stat{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px 16px;text-align:center}
.ah-stat .stat-val{font-size:1.5rem;font-weight:900;color:#0f172a}
.ah-stat .stat-label{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-top:2px}
.ah-stat.green{border-color:#bbf7d0;background:#f0fdf4}.ah-stat.green .stat-val{color:#16a34a}
.ah-stat.red{border-color:#fecaca;background:#fef2f2}.ah-stat.red .stat-val{color:#dc2626}
.ah-stat.amber{border-color:#fde68a;background:#fffbeb}.ah-stat.amber .stat-val{color:#d97706}
.ah-stat.blue{border-color:#bfdbfe;background:#eff6ff}.ah-stat.blue .stat-val{color:#2563eb}
.ah-stat.purple{border-color:#ddd6fe;background:#faf5ff}.ah-stat.purple .stat-val{color:#7c3aed}

/* Reconciliation tabs */
.ah-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.ah-tab{padding:10px 20px;font-size:.82rem;font-weight:700;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;display:flex;align-items:center;gap:6px}
.ah-tab:hover{color:#0f172a}
.ah-tab.active{color:var(--brand);border-bottom-color:var(--brand)}
.ah-tab .tab-count{background:#e2e8f0;color:#475569;border-radius:8px;padding:2px 8px;font-size:.7rem;font-weight:800}
.ah-tab.active .tab-count{background:rgba(34,197,94,.15);color:#16a34a}
.ah-tab-panel{display:none;padding:16px 0}
.ah-tab-panel.active{display:block}

/* Reconciliation table */
.ah-tbl-wrap{overflow-x:auto;max-height:400px}
.ah-tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.ah-tbl thead th{position:sticky;top:0;background:#f8fafc;padding:8px 12px;text-align:left;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.ah-tbl tbody td{padding:8px 12px;border-bottom:1px solid #f1f5f9;color:var(--text-main)}
.ah-tbl tbody tr:hover{background:#f8fafc}
.ah-tbl .mono{font-family:monospace;font-weight:600}
.ah-tbl .cb-col{width:36px;text-align:center}

/* Bulk action bar */
.ah-bulk-bar{display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:12px 18px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ah-bulk-bar.visible{display:flex}
.ah-bulk-bar .bulk-info{font-size:.82rem;font-weight:700;color:#92400e;display:flex;align-items:center;gap:8px}
.ah-bulk-bar .bulk-actions{display:flex;gap:8px;flex-wrap:wrap}

/* Actions row */
.ah-actions-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0}

/* New session dialog */
.ah-dialog-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);align-items:center;justify-content:center}
.ah-dialog-overlay.open{display:flex}
.ah-dialog{background:#fff;border-radius:16px;padding:28px;width:90%;max-width:440px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.ah-dialog h3{margin:0 0 6px;font-size:1.1rem;font-weight:800}
.ah-dialog p{margin:0 0 20px;color:var(--text-muted);font-size:.82rem}
.ah-dialog input{width:100%;height:44px;border:2px solid var(--border);border-radius:10px;padding:0 14px;font-size:.92rem;margin-bottom:16px}
.ah-dialog input:focus{outline:none;border-color:var(--brand)}
.ah-dialog-btns{display:flex;gap:10px;justify-content:flex-end}

/* Scanned feed */
.ah-feed{max-height:300px;overflow-y:auto;scrollbar-width:thin}
.ah-feed-item{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:.82rem}
.ah-feed-item:last-child{border:none}
.ah-feed-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.ah-feed-dot.matched{background:#22c55e}
.ah-feed-dot.unknown{background:#ef4444}
.ah-feed-roll{font-family:monospace;font-weight:700;min-width:100px}
.ah-feed-type{color:var(--text-muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ah-feed-time{color:#94a3b8;font-size:.72rem;white-space:nowrap}
.ah-feed-del{background:none;border:none;color:#dc2626;cursor:pointer;font-size:.82rem;padding:4px;border-radius:4px;opacity:.5}
.ah-feed-del:hover{opacity:1;background:rgba(220,38,38,.08)}

/* Empty state */
.ah-empty{text-align:center;padding:40px;color:#94a3b8}
.ah-empty i{font-size:2.5rem;opacity:.3;display:block;margin-bottom:10px}
.ah-empty p{font-size:.88rem}

/* ── Mobile Responsive ─────────────────────────────────────── */
@media(max-width:768px){
  .ah-top-bar{flex-direction:column;align-items:flex-start}
  .ah-top-bar h1{font-size:1.1rem}
  .ah-sessions{grid-template-columns:1fr}
  .ah-panel-head{flex-direction:column;align-items:flex-start;padding:14px 16px}
  .ah-panel-head .panel-meta{flex-direction:column;gap:4px}
  .ah-panel-body{padding:14px 16px}
  .ah-scan-bar{flex-direction:column}
  .ah-scan-input{min-width:unset;height:52px;font-size:.95rem}
  .ah-scan-btn{justify-content:center}
  .ah-cam-btn{width:100%;justify-content:center}
  .ah-stats{grid-template-columns:repeat(3,1fr);gap:8px}
  .ah-stat{padding:10px 8px}
  .ah-stat .stat-val{font-size:1.15rem}
  .ah-tabs{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .ah-tab{padding:8px 14px;font-size:.76rem;white-space:nowrap}
  .ah-tbl-wrap{max-height:300px}
  .ah-tbl{font-size:.72rem}
  .ah-tbl thead th,.ah-tbl tbody td{padding:6px 8px}
  .ah-actions-row{flex-direction:column;align-items:stretch;gap:8px}
  .ah-actions-row .btn{text-align:center;justify-content:center}
  .ah-dialog{width:95%;padding:20px}
  .ah-bulk-bar{flex-direction:column;align-items:stretch}
}
@media(max-width:480px){
  .ah-stats{grid-template-columns:repeat(2,1fr)}
  .ah-stat .stat-val{font-size:1rem}
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">›</span>
  <span>Physical Stock Check</span>
  <span class="breadcrumb-sep">›</span>
  <span>Audit Hub</span>
</div>

<!-- ── Page Header ─────────────────────────────────────────── -->
<div class="ah-top-bar">
  <div>
    <h1><i class="bi bi-clipboard-check" style="color:var(--brand)"></i> Audit Hub</h1>
    <p>Create audit sessions, scan rolls, and reconcile physical inventory against ERP data.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="openNewSessionDialog()"><i class="bi bi-plus-circle"></i> New Audit Session</button>
  </div>
</div>

<!-- ── Session Cards ───────────────────────────────────────── -->
<div style="margin-bottom:6px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8">
  <i class="bi bi-collection"></i> Recent Sessions
</div>
<div class="ah-sessions" id="ah-sessions">
</div>

<!-- ── Active Session Panel ────────────────────────────────── -->
<div class="ah-panel" id="ah-panel">
  <div class="ah-panel-head">
    <div>
      <div class="panel-title">
        <i class="bi bi-shield-check"></i>
        <span id="ap-name">—</span>
        <span id="ap-status-badge"></span>
      </div>
      <div class="panel-meta">
        <span><i class="bi bi-hash"></i> <span id="ap-id">—</span></span>
        <span><i class="bi bi-person"></i> <span id="ap-by">—</span></span>
        <span><i class="bi bi-calendar3"></i> <span id="ap-date">—</span></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)" onclick="exportCSV()"><i class="bi bi-file-earmark-excel"></i> Export CSV</button>
      <button class="btn btn-sm" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)" onclick="exportPrintReport()"><i class="bi bi-printer"></i> Print Report</button>
    </div>
  </div>

  <div class="ah-panel-body">
    <!-- Scan Input -->
    <div class="ah-scan-bar" id="ah-scan-bar">
      <input type="text" class="ah-scan-input" id="ah-scan-input" placeholder="Enter or scan Roll No..." autocomplete="off">
      <button class="ah-scan-btn" id="ah-scan-btn" onclick="submitScan()"><i class="bi bi-upc-scan"></i> Scan</button>
      <button class="ah-cam-btn" id="ah-cam-toggle" onclick="toggleCamera()" title="Toggle Camera"><i class="bi bi-camera-video"></i></button>
    </div>
    <div class="ah-camera-wrap" id="ah-camera-wrap">
      <div id="ah-camera-reader" style="width:100%"></div>
    </div>

    <!-- Stats Cards -->
    <div class="ah-stats" id="ah-stats">
      <div class="ah-stat blue"><div class="stat-val" id="st-erp">0</div><div class="stat-label">ERP Rolls</div></div>
      <div class="ah-stat purple"><div class="stat-val" id="st-scanned">0</div><div class="stat-label">Scanned</div></div>
      <div class="ah-stat green"><div class="stat-val" id="st-matched">0</div><div class="stat-label">Matched</div></div>
      <div class="ah-stat red"><div class="stat-val" id="st-missing">0</div><div class="stat-label">Missing</div></div>
      <div class="ah-stat amber"><div class="stat-val" id="st-extra">0</div><div class="stat-label">Extra</div></div>
      <div class="ah-stat green"><div class="stat-val" id="st-percent">0%</div><div class="stat-label">Match Rate</div></div>
    </div>

    <!-- Reconciliation Tabs -->
    <div class="ah-tabs">
      <div class="ah-tab active" data-tab="scanned"><i class="bi bi-list-check"></i> Scanned <span class="tab-count" id="tc-scanned">0</span></div>
      <div class="ah-tab" data-tab="matched"><i class="bi bi-check-circle"></i> Matched <span class="tab-count" id="tc-matched">0</span></div>
      <div class="ah-tab" data-tab="missing"><i class="bi bi-exclamation-triangle"></i> Missing <span class="tab-count" id="tc-missing">0</span></div>
      <div class="ah-tab" data-tab="extra"><i class="bi bi-question-circle"></i> Extra <span class="tab-count" id="tc-extra">0</span></div>
    </div>

    <!-- Scanned Tab -->
    <div class="ah-tab-panel active" id="tp-scanned">
      <div class="ah-feed" id="ah-feed"></div>
      <div class="ah-empty" id="ah-feed-empty"><i class="bi bi-upc-scan"></i><p>No rolls scanned yet. Start scanning above.</p></div>
    </div>

    <!-- Matched Tab -->
    <div class="ah-tab-panel" id="tp-matched">
      <div class="ah-tbl-wrap">
        <table class="ah-tbl" id="tbl-matched">
          <thead><tr><th>#</th><th>Roll No</th><th>Paper Type</th><th>Company</th><th>Width</th><th>Length</th><th>ERP Status</th><th>Scan Time</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="ah-empty" id="em-matched" style="display:none"><i class="bi bi-check-circle"></i><p>No matched rolls yet.</p></div>
    </div>

    <!-- Missing Tab -->
    <div class="ah-tab-panel" id="tp-missing">
      <?php if ($isUserAdmin): ?>
      <div class="ah-bulk-bar" id="bb-missing">
        <div class="bulk-info"><i class="bi bi-exclamation-triangle"></i> <span id="bb-missing-count">0</span> selected</div>
        <div class="bulk-actions">
          <button class="btn btn-sm btn-danger" onclick="bulkRemoveMissing()"><i class="bi bi-trash3"></i> Mark as Consumed</button>
        </div>
      </div>
      <?php endif; ?>
      <div class="ah-tbl-wrap">
        <table class="ah-tbl" id="tbl-missing">
          <thead><tr><?php if ($isUserAdmin): ?><th class="cb-col"><input type="checkbox" id="cb-all-missing"></th><?php endif; ?><th>#</th><th>Roll No</th><th>Paper Type</th><th>Company</th><th>Width</th><th>Length</th><th>ERP Status</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="ah-empty" id="em-missing" style="display:none"><i class="bi bi-emoji-smile"></i><p>No missing rolls — everything accounted for!</p></div>
    </div>

    <!-- Extra Tab -->
    <div class="ah-tab-panel" id="tp-extra">
      <?php if ($isUserAdmin): ?>
      <div class="ah-bulk-bar" id="bb-extra">
        <div class="bulk-info"><i class="bi bi-question-circle"></i> <span id="bb-extra-count">0</span> selected</div>
        <div class="bulk-actions">
          <button class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none" onclick="bulkAddExtra()"><i class="bi bi-plus-circle"></i> Register in Stock</button>
        </div>
      </div>
      <?php endif; ?>
      <div class="ah-tbl-wrap">
        <table class="ah-tbl" id="tbl-extra">
          <thead><tr><?php if ($isUserAdmin): ?><th class="cb-col"><input type="checkbox" id="cb-all-extra"></th><?php endif; ?><th>#</th><th>Roll No</th><th>Paper Type</th><th>Dimension</th><th>Scan Time</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="ah-empty" id="em-extra" style="display:none"><i class="bi bi-emoji-smile"></i><p>No extra rolls found.</p></div>
    </div>

    <!-- Actions Row -->
    <?php if ($isUserAdmin): ?>
    <div class="ah-actions-row" id="ah-actions-row" style="display:none">
      <span style="font-size:.78rem;color:var(--text-muted)"><i class="bi bi-info-circle"></i> Finalize locks this session permanently.</span>
      <button class="btn btn-danger" id="ah-finalize-btn" onclick="finalizeSession()"><i class="bi bi-lock"></i> Finalize Session</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── New Session Dialog ──────────────────────────────────── -->
<div class="ah-dialog-overlay" id="ah-new-dialog">
  <div class="ah-dialog">
    <h3><i class="bi bi-plus-circle" style="color:var(--brand)"></i> New Audit Session</h3>
    <p>Give this audit session a descriptive name (e.g., "March 2026 Full Audit").</p>
    <input type="text" id="ah-new-name" placeholder="Session name..." maxlength="255">
    <div class="ah-dialog-btns">
      <button class="btn btn-secondary" onclick="closeNewSessionDialog()">Cancel</button>
      <button class="btn btn-primary" id="ah-create-btn" onclick="createSession()"><i class="bi bi-check-lg"></i> Create</button>
    </div>
  </div>
</div>

<!-- ── Feedback Ring ───────────────────────────────────────── -->
<div class="ah-feedback" id="ah-feedback"><i class="bi bi-check-lg"></i></div>

<!-- ── Duplicate Popup ─────────────────────────────────────── -->
<div class="ah-dup-overlay" id="ah-dup-overlay"></div>
<div class="ah-dup-popup" id="ah-dup-popup">
  <div class="dup-icon"><i class="bi bi-ban"></i></div>
  <div class="dup-title">Duplicate Scan Not Allowed</div>
  <div class="dup-roll" id="ah-dup-roll">—</div>
  <div class="dup-msg">This roll has already been scanned in this session.</div>
</div>

<!-- ── html5-qrcode CDN ────────────────────────────────────── -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
(function(){
'use strict';

var API  = '<?= BASE_URL ?>/modules/audit/api.php';
var CSRF = '<?= e($csrf) ?>';
var IS_ADMIN = <?= $isUserAdmin ? 'true' : 'false' ?>;

var activeSessionId = null;
var activeSession   = null;
var reconData       = null;
var html5Scanner    = null;
var cameraActive    = false;
var lastScannedCode = '';
var scanCooldown    = false;

// ── Audio feedback ────────────────────────────────────────
var audioCtx = null;
function getAudioCtx(){ if(!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); return audioCtx; }

function playTone(freq, duration, type){
  try {
    var ctx = getAudioCtx();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = type || 'sine';
    osc.frequency.value = freq;
    gain.gain.value = 0.3;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + duration/1000);
  } catch(e){}
}

function showFeedback(type){
  var el = document.getElementById('ah-feedback');
  el.className = 'ah-feedback';
  el.style.display = 'none';
  var icon = el.querySelector('i');
  if(type==='success'){ el.classList.add('fb-success'); icon.className='bi bi-check-lg'; playTone(880,100,'sine'); }
  else if(type==='warning'){ el.classList.add('fb-warning'); icon.className='bi bi-exclamation-lg'; playTone(440,200,'sine'); }
  else { el.classList.add('fb-error'); icon.className='bi bi-x-lg'; playTone(220,300,'square'); }
  el.style.display = 'flex';
  el.style.animation = 'none';
  void el.offsetWidth;
  el.style.animation = 'ahPulse .6s ease-out forwards';
}

// ── Helpers ───────────────────────────────────────────────
function $(id){ return document.getElementById(id); }

function postAPI(action, data, cb){
  var fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  for(var k in data) fd.append(k, data[k]);
  fetch(API, {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(cb)
    .catch(function(e){ alert('Request failed: '+e.message); });
}

function getAPI(action, params, cb){
  var url = API + '?action=' + action;
  for(var k in params) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  fetch(url, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(cb)
    .catch(function(e){ alert('Request failed: '+e.message); });
}

function fmtDate(d){
  if(!d) return '—';
  var dt = new Date(d.replace(' ','T'));
  return dt.toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) + ' ' + dt.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',hour12:true});
}

function fmtShortTime(d){
  if(!d) return '';
  var dt = new Date(d.replace(' ','T'));
  return dt.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}

function escHtml(s){
  var d = document.createElement('div'); d.textContent = s||''; return d.innerHTML;
}

function statusBadgeHtml(status){
  if(status==='In Progress') return '<span class="badge badge-pending">In Progress</span>';
  if(status==='Finalized') return '<span class="badge badge-completed">Finalized</span>';
  return '<span class="badge badge-draft">'+escHtml(status)+'</span>';
}

function erpStatusBadgeHtml(s){
  var map = {Main:'main',Stock:'stock',Slitting:'slitting','Job Assign':'job-assign','In Production':'in-production',Consumed:'consumed',Available:'available',Assigned:'assigned'};
  var cls = map[s] || 'draft';
  return '<span class="badge badge-'+cls+'">'+escHtml(s||'—')+'</span>';
}

// ── Load Sessions ─────────────────────────────────────────
function loadSessions(){
  getAPI('list_sessions', {}, function(res){
    if(!res.ok) return;
    var cont = $('ah-sessions');
    if(res.sessions.length === 0){
      cont.innerHTML = '<div class="ah-empty" style="grid-column:1/-1"><i class="bi bi-inbox"></i><p>No audit sessions yet. Click <strong>"New Audit Session"</strong> to begin.</p></div>';
      return;
    }
    var html = '';
    res.sessions.forEach(function(s){
      var isActive = activeSessionId && activeSessionId == s.id;
      html += '<div class="ah-sess-card'+(isActive?' active':'')+'" data-sid="'+s.id+'" onclick="window.ahSelectSession('+s.id+')">';
      if(IS_ADMIN){
        html += '<button class="ah-sess-del" onclick="event.stopPropagation();deleteSession('+s.id+',\'' + escHtml(s.session_name).replace(/'/g,'\\&#39;') + '\')" title="Delete Session"><i class="bi bi-trash3"></i></button>';
      }
      html += '<div class="sess-name">'+escHtml(s.session_name)+'</div>'
        + '<div class="sess-id">'+escHtml(s.audit_id)+'</div>'
        + '<div class="sess-date"><i class="bi bi-calendar3"></i> '+fmtDate(s.created_at)+' &nbsp;·&nbsp; <i class="bi bi-person"></i> '+escHtml(s.created_by_name||'System')+'</div>'
        + '<div class="sess-badge">'+statusBadgeHtml(s.status)+'</div>'
        + '</div>';
    });
    cont.innerHTML = html;
  });
}

// ── Select Session ────────────────────────────────────────
window.ahSelectSession = function(id){
  activeSessionId = id;
  document.querySelectorAll('.ah-sess-card').forEach(function(c){ c.classList.toggle('active', c.dataset.sid == id); });
  loadSessionDetail();
};

function loadSessionDetail(){
  if(!activeSessionId) return;
  getAPI('get_session', {id:activeSessionId}, function(res){
    if(!res.ok){ alert(res.error); return; }
    activeSession = res.session;
    renderPanel();
    loadReconciliation();
  });
}

function renderPanel(){
  var s = activeSession;
  $('ah-panel').classList.add('visible');
  $('ap-name').textContent = s.session_name;
  $('ap-id').textContent = s.audit_id;
  $('ap-status-badge').innerHTML = statusBadgeHtml(s.status);
  $('ap-by').textContent = s.created_by_name || 'System';
  $('ap-date').textContent = fmtDate(s.created_at);

  var isFinalized = s.status === 'Finalized';
  $('ah-scan-bar').style.display = isFinalized ? 'none' : '';
  if(IS_ADMIN && $('ah-actions-row')){
    $('ah-actions-row').style.display = isFinalized ? 'none' : '';
  }

  renderFeed(s.scanned_rolls || []);

  // Focus scan input after panel renders
  if(!isFinalized){ setTimeout(function(){ $('ah-scan-input').focus(); }, 100); }
}

function renderFeed(scans){
  var container = $('ah-feed');
  var empty = $('ah-feed-empty');
  $('tc-scanned').textContent = scans.length;

  if(scans.length===0){ container.innerHTML=''; empty.style.display=''; return; }
  empty.style.display='none';

  var html='';
  scans.forEach(function(s){
    var isFinalized = activeSession && activeSession.status === 'Finalized';
    html += '<div class="ah-feed-item">'
      + '<div class="ah-feed-dot '+(s.status==='Matched'?'matched':'unknown')+'"></div>'
      + '<div class="ah-feed-roll">'+escHtml(s.roll_no)+'</div>'
      + '<div class="ah-feed-type">'+escHtml(s.paper_type||'Unknown')+(s.dimension?' · '+escHtml(s.dimension):'')+'</div>'
      + '<div class="ah-feed-time">'+fmtShortTime(s.scan_time)+'</div>'
      + (IS_ADMIN && !isFinalized ? '<button class="ah-feed-del" onclick="deleteScan('+s.id+')" title="Remove"><i class="bi bi-x-lg"></i></button>' : '')
      + '</div>';
  });
  container.innerHTML = html;
}

// ── Reconciliation ────────────────────────────────────────
function loadReconciliation(){
  if(!activeSessionId) return;
  getAPI('reconcile', {session_id:activeSessionId}, function(res){
    if(!res.ok) return;
    reconData = res;

    $('st-erp').textContent = res.total_erp.toLocaleString();
    $('st-scanned').textContent = res.total_scanned.toLocaleString();
    $('st-matched').textContent = res.matched_count.toLocaleString();
    $('st-missing').textContent = res.missing_count.toLocaleString();
    $('st-extra').textContent = res.extra_count.toLocaleString();
    $('st-percent').textContent = res.match_percent + '%';

    $('tc-matched').textContent = res.matched_count;
    $('tc-missing').textContent = res.missing_count;
    $('tc-extra').textContent = res.extra_count;

    renderMatchedTable(res.matched);
    renderMissingTable(res.missing);
    renderExtraTable(res.extra);
  });
}

function renderMatchedTable(items){
  var tbody = document.querySelector('#tbl-matched tbody');
  var empty = $('em-matched');
  if(items.length===0){ tbody.innerHTML=''; empty.style.display=''; return; }
  empty.style.display='none';
  var html='';
  items.forEach(function(m, i){
    var e = m.erp || {};
    html += '<tr><td>'+(i+1)+'</td><td class="mono">'+escHtml(m.roll_no)+'</td><td>'+escHtml(e.paper_type||m.paper_type||'')+'</td><td>'+escHtml(e.company||'')+'</td><td>'+(e.width_mm?parseInt(e.width_mm)+'mm':'—')+'</td><td>'+(e.length_mtr?parseFloat(e.length_mtr).toLocaleString()+'m':'—')+'</td><td>'+erpStatusBadgeHtml(e.status)+'</td><td>'+fmtShortTime(m.scan_time)+'</td></tr>';
  });
  tbody.innerHTML = html;
}

function renderMissingTable(items){
  var tbody = document.querySelector('#tbl-missing tbody');
  var empty = $('em-missing');
  if(items.length===0){ tbody.innerHTML=''; empty.style.display=''; if(IS_ADMIN && $('bb-missing')) $('bb-missing').classList.remove('visible'); return; }
  empty.style.display='none';
  var html='';
  items.forEach(function(m, i){
    html += '<tr>'
      +(IS_ADMIN?'<td class="cb-col"><input type="checkbox" class="cb-miss" value="'+escHtml(m.roll_no)+'"></td>':'')
      +'<td>'+(i+1)+'</td><td class="mono">'+escHtml(m.roll_no)+'</td><td>'+escHtml(m.paper_type||'')+'</td><td>'+escHtml(m.company||'')+'</td><td>'+(m.width_mm?parseInt(m.width_mm)+'mm':'—')+'</td><td>'+(m.length_mtr?parseFloat(m.length_mtr).toLocaleString()+'m':'—')+'</td><td>'+erpStatusBadgeHtml(m.status)+'</td></tr>';
  });
  tbody.innerHTML = html;
  if(IS_ADMIN) updateBulkMissing();
}

function renderExtraTable(items){
  var tbody = document.querySelector('#tbl-extra tbody');
  var empty = $('em-extra');
  if(items.length===0){ tbody.innerHTML=''; empty.style.display=''; if(IS_ADMIN && $('bb-extra')) $('bb-extra').classList.remove('visible'); return; }
  empty.style.display='none';
  var html='';
  items.forEach(function(m, i){
    html += '<tr>'
      +(IS_ADMIN?'<td class="cb-col"><input type="checkbox" class="cb-extra" value="'+escHtml(m.roll_no)+'"></td>':'')
      +'<td>'+(i+1)+'</td><td class="mono">'+escHtml(m.roll_no)+'</td><td>'+escHtml(m.paper_type||'')+'</td><td>'+escHtml(m.dimension||'')+'</td><td>'+fmtShortTime(m.scan_time)+'</td></tr>';
  });
  tbody.innerHTML = html;
  if(IS_ADMIN) updateBulkExtra();
}

// ── Bulk selection handlers ───────────────────────────────
if(IS_ADMIN){
  document.addEventListener('change', function(e){
    if(e.target.id==='cb-all-missing'){
      document.querySelectorAll('.cb-miss').forEach(function(c){ c.checked = e.target.checked; });
      updateBulkMissing();
    }
    if(e.target.classList.contains('cb-miss')) updateBulkMissing();
    if(e.target.id==='cb-all-extra'){
      document.querySelectorAll('.cb-extra').forEach(function(c){ c.checked = e.target.checked; });
      updateBulkExtra();
    }
    if(e.target.classList.contains('cb-extra')) updateBulkExtra();
  });
}

function updateBulkMissing(){
  var checked = document.querySelectorAll('.cb-miss:checked');
  var bar = $('bb-missing');
  if(!bar) return;
  if(checked.length > 0){ bar.classList.add('visible'); $('bb-missing-count').textContent = checked.length; }
  else bar.classList.remove('visible');
}

function updateBulkExtra(){
  var checked = document.querySelectorAll('.cb-extra:checked');
  var bar = $('bb-extra');
  if(!bar) return;
  if(checked.length > 0){ bar.classList.add('visible'); $('bb-extra-count').textContent = checked.length; }
  else bar.classList.remove('visible');
}

// ── Tabs ──────────────────────────────────────────────────
document.querySelectorAll('.ah-tab').forEach(function(tab){
  tab.addEventListener('click', function(){
    document.querySelectorAll('.ah-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.ah-tab-panel').forEach(function(p){ p.classList.remove('active'); });
    tab.classList.add('active');
    $('tp-'+tab.dataset.tab).classList.add('active');
  });
});

// ── Scan Submission ───────────────────────────────────────
function submitScan(){
  var input = $('ah-scan-input');
  var val = input.value.trim();
  if(!val || !activeSessionId) return;

  $('ah-scan-btn').disabled = true;
  postAPI('scan_roll', {session_id: activeSessionId, roll_no: val}, function(res){
    $('ah-scan-btn').disabled = false;
    input.value = '';
    if(!cameraActive) input.focus();

    if(!res.ok){
      if(res.duplicate){ showFeedback('warning'); showDupPopup(val); }
      else { showFeedback('error'); alert(res.error); }
      return;
    }

    showFeedback(res.status === 'Matched' ? 'success' : 'error');
    loadSessionDetail();
  });
}
window.submitScan = submitScan;

$('ah-scan-input').addEventListener('keydown', function(e){
  if(e.key === 'Enter'){ e.preventDefault(); submitScan(); }
});

// ── Camera ────────────────────────────────────────────────
function toggleCamera(){
  if(cameraActive){ stopCamera(); return; }
  startCamera();
}
window.toggleCamera = toggleCamera;

function startCamera(){
  $('ah-camera-wrap').classList.add('open');
  $('ah-cam-toggle').classList.add('active');
  cameraActive = true;

  setTimeout(function(){
    html5Scanner = new Html5QrcodeScanner('ah-camera-reader', {
      fps: 15,
      qrbox: {width: 250, height: 250},
      rememberLastUsedCamera: false
    }, false);
    html5Scanner.render(function(text){
      if(scanCooldown && text === lastScannedCode) return;
      lastScannedCode = text;
      scanCooldown = true;
      setTimeout(function(){ scanCooldown = false; }, 3000);
      $('ah-scan-input').value = text;
      submitScan();
    }, function(){});
  }, 100);
}

function showDupPopup(rollNo){
  $('ah-dup-roll').textContent = rollNo;
  $('ah-dup-overlay').classList.add('show');
  $('ah-dup-popup').classList.add('show');
  playTone(220, 300, 'square');
  setTimeout(function(){
    $('ah-dup-overlay').classList.remove('show');
    $('ah-dup-popup').classList.remove('show');
  }, 2000);
}

function stopCamera(){
  if(html5Scanner){
    try { html5Scanner.clear(); } catch(e){}
    html5Scanner = null;
  }
  $('ah-camera-wrap').classList.remove('open');
  $('ah-cam-toggle').classList.remove('active');
  cameraActive = false;
  $('ah-camera-reader').innerHTML = '';
}

// ── Delete Scan ───────────────────────────────────────────
window.deleteScan = function(scanId){
  var run = function(){
    postAPI('delete_scan', {scan_id: scanId, session_id: activeSessionId}, function(res){
      if(!res.ok){ alert(res.error); return; }
      loadSessionDetail();
    });
  };
  if (typeof window.showERPConfirm === 'function') { window.showERPConfirm('Remove this scanned entry?', run, { title:'Please Confirm', okLabel:'Delete', cancelLabel:'Cancel' }); return; }
  run();
};

// ── Bulk Remove Missing ───────────────────────────────────
window.bulkRemoveMissing = function(){
  var checked = document.querySelectorAll('.cb-miss:checked');
  if(checked.length === 0) return;
  var run = function(){
    var rollNos = [];
    checked.forEach(function(c){ rollNos.push(c.value); });
    postAPI('bulk_remove_missing', {session_id: activeSessionId, roll_nos: JSON.stringify(rollNos)}, function(res){
      if(!res.ok){ alert(res.error); return; }
      alert(res.updated + ' roll(s) marked as Consumed.');
      loadReconciliation();
    });
  };
  var msg = 'Mark '+checked.length+' missing roll(s) as Consumed in Paper Stock?\nThis will update actual inventory.';
  if (typeof window.showERPConfirm === 'function') { window.showERPConfirm(msg, run, { title:'Please Confirm', okLabel:'Proceed', cancelLabel:'Cancel' }); return; }
  run();
};

// ── Bulk Add Extra ────────────────────────────────────────
window.bulkAddExtra = function(){
  var checked = document.querySelectorAll('.cb-extra:checked');
  if(checked.length === 0) return;
  var run = function(){
    var rollNos = [];
    checked.forEach(function(c){ rollNos.push(c.value); });
    postAPI('bulk_add_extra', {session_id: activeSessionId, roll_nos: JSON.stringify(rollNos)}, function(res){
      if(!res.ok){ alert(res.error); return; }
      alert(res.created + ' roll(s) registered in stock.');
      loadReconciliation();
    });
  };
  var msg = 'Register '+checked.length+' extra roll(s) as new Stock in Paper Stock?';
  if (typeof window.showERPConfirm === 'function') { window.showERPConfirm(msg, run, { title:'Please Confirm', okLabel:'Proceed', cancelLabel:'Cancel' }); return; }
  run();
};

// ── Finalize Session ──────────────────────────────────────
window.finalizeSession = function(){
  if(!activeSessionId) return;
  var run = function(){
    postAPI('finalize', {session_id: activeSessionId}, function(res){
      if(!res.ok){ alert(res.error); return; }
      loadSessions();
      loadSessionDetail();
    });
  };
  var msg = 'Finalize this audit session? This locks all data permanently and cannot be undone.';
  if (typeof window.showERPConfirm === 'function') { window.showERPConfirm(msg, run, { title:'Please Confirm', okLabel:'Finalize', cancelLabel:'Cancel' }); return; }
  run();
};

// ── New Session Dialog ────────────────────────────────────
window.openNewSessionDialog = function(){ $('ah-new-dialog').classList.add('open'); $('ah-new-name').value=''; setTimeout(function(){ $('ah-new-name').focus(); },100); };
window.closeNewSessionDialog = function(){ $('ah-new-dialog').classList.remove('open'); };

// Close dialog on overlay click
$('ah-new-dialog').addEventListener('click', function(e){ if(e.target === this) closeNewSessionDialog(); });

window.createSession = function(){
  var name = $('ah-new-name').value.trim();
  if(!name){ $('ah-new-name').focus(); return; }
  $('ah-create-btn').disabled = true;
  postAPI('create_session', {session_name: name}, function(res){
    $('ah-create-btn').disabled = false;
    if(!res.ok){ alert(res.error); return; }
    closeNewSessionDialog();
    activeSessionId = res.id;
    loadSessions();
    setTimeout(function(){ loadSessionDetail(); }, 300);
  });
};

$('ah-new-name').addEventListener('keydown', function(e){
  if(e.key==='Enter'){ e.preventDefault(); createSession(); }
});

// ── Export ─────────────────────────────────────────────────
window.exportCSV = function(){
  if(!activeSessionId) return;
  window.location.href = API + '?action=export_csv&session_id=' + activeSessionId;
};

window.exportPrintReport = function(){
  if(!activeSessionId || !reconData) return;
  var s = activeSession;
  var appSettings = <?= json_encode(getAppSettings()) ?>;
  var companyName = appSettings.company_name || 'ERP';

  var w = window.open('','_blank');
  if(!w) return;

  var matchedHtml = '<table><thead><tr><th>#</th><th>Roll No</th><th>Paper Type</th><th>Company</th><th>Status</th><th>Scan Time</th></tr></thead><tbody>';
  (reconData.matched||[]).forEach(function(m,i){
    var e = m.erp||{};
    matchedHtml += '<tr><td>'+(i+1)+'</td><td>'+escHtml(m.roll_no)+'</td><td>'+escHtml(e.paper_type||'')+'</td><td>'+escHtml(e.company||'')+'</td><td>'+escHtml(e.status||'')+'</td><td>'+escHtml(m.scan_time||'')+'</td></tr>';
  });
  matchedHtml += '</tbody></table>';

  var missingHtml = '<table><thead><tr><th>#</th><th>Roll No</th><th>Paper Type</th><th>Company</th><th>Status</th></tr></thead><tbody>';
  (reconData.missing||[]).forEach(function(m,i){
    missingHtml += '<tr><td>'+(i+1)+'</td><td>'+escHtml(m.roll_no)+'</td><td>'+escHtml(m.paper_type||'')+'</td><td>'+escHtml(m.company||'')+'</td><td>'+escHtml(m.status||'')+'</td></tr>';
  });
  missingHtml += '</tbody></table>';

  var extraHtml = '<table><thead><tr><th>#</th><th>Roll No</th><th>Scan Time</th></tr></thead><tbody>';
  (reconData.extra||[]).forEach(function(m,i){
    extraHtml += '<tr><td>'+(i+1)+'</td><td>'+escHtml(m.roll_no)+'</td><td>'+escHtml(m.scan_time||'')+'</td></tr>';
  });
  extraHtml += '</tbody></table>';

  var html = '<!doctype html><html><head><title>Audit Report — '+escHtml(s.session_name)+'</title>'
    +'<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">'
    +'<style>@page{size:A4 portrait;margin:10mm}*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1e293b}'
    +'.header{display:flex;justify-content:space-between;border-bottom:3px solid #0f172a;padding-bottom:10px;margin-bottom:14px}'
    +'.cname{font-size:20px;font-weight:900;text-transform:uppercase}.cmeta{font-size:10px;color:#64748b;margin-top:3px}'
    +'.title-bar{background:#0f172a;color:#fff;border-radius:8px;padding:10px 18px;margin-bottom:14px;font-size:13px;font-weight:700;display:flex;justify-content:space-between;align-items:center}'
    +'.stats{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}.stat{flex:1;min-width:80px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;text-align:center}'
    +'.stat .val{font-size:18px;font-weight:900}.stat .lbl{font-size:9px;text-transform:uppercase;color:#94a3b8;font-weight:800;letter-spacing:.08em}'
    +'.stat.green{border-color:#bbf7d0;background:#f0fdf4}.stat.green .val{color:#16a34a}'
    +'.stat.red{border-color:#fecaca;background:#fef2f2}.stat.red .val{color:#dc2626}'
    +'.stat.amber{border-color:#fde68a;background:#fffbeb}.stat.amber .val{color:#d97706}'
    +'h3{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#475569;margin:14px 0 6px;padding:6px 0;border-bottom:1px solid #e2e8f0}'
    +'table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:10px}th{background:#0f172a;color:#fff;padding:5px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.04em;border:1px solid #1e293b}'
    +'td{padding:4px 8px;border:1px solid #e2e8f0}tr:nth-child(even){background:#f8fafc}'
    +'.toolbar{padding:12px;background:linear-gradient(135deg,#fefce8,#fff7ed);border-bottom:2px solid #fde68a;text-align:center;font-weight:700;display:flex;align-items:center;justify-content:center;gap:12px}'
    +'.toolbar button{padding:8px 20px;border-radius:10px;font-weight:700;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:6px}'
    +'.btn-p{border:none;background:#0f172a;color:#fff}.btn-c{border:1px solid #cbd5e1;background:#fff;color:#64748b}'
    +'@media print{.toolbar{display:none!important}body{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}</style></head><body>'
    +'<div class="toolbar"><span><i class="bi bi-clipboard-check"></i> Audit Report Ready</span><button class="btn-p" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button><button class="btn-c" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button></div>'
    +'<div style="padding:12px 16px">'
    +'<div class="header"><div><div class="cname">'+escHtml(companyName)+'</div><div class="cmeta">Physical Stock Check — Audit Report</div></div><div style="text-align:right;font-size:10px;color:#64748b"><div>Session: <strong>'+escHtml(s.session_name)+'</strong></div><div>ID: '+escHtml(s.audit_id)+'</div><div>Date: '+new Date().toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'})+'</div></div></div>'
    +'<div class="title-bar"><span><i class="bi bi-shield-check"></i> AUDIT RECONCILIATION REPORT</span><span style="font-size:10px;opacity:.7">'+escHtml(s.status)+'</span></div>'
    +'<div class="stats">'
    +'<div class="stat"><div class="val">'+reconData.total_erp+'</div><div class="lbl">ERP Rolls</div></div>'
    +'<div class="stat"><div class="val">'+reconData.total_scanned+'</div><div class="lbl">Scanned</div></div>'
    +'<div class="stat green"><div class="val">'+reconData.matched_count+'</div><div class="lbl">Matched</div></div>'
    +'<div class="stat red"><div class="val">'+reconData.missing_count+'</div><div class="lbl">Missing</div></div>'
    +'<div class="stat amber"><div class="val">'+reconData.extra_count+'</div><div class="lbl">Extra</div></div>'
    +'<div class="stat green"><div class="val">'+reconData.match_percent+'%</div><div class="lbl">Match Rate</div></div>'
    +'</div>'
    +'<h3><i class="bi bi-check-circle"></i> Matched Rolls ('+reconData.matched_count+')</h3>'+matchedHtml
    +'<h3><i class="bi bi-exclamation-triangle"></i> Missing Rolls ('+reconData.missing_count+')</h3>'+missingHtml
    +'<h3><i class="bi bi-question-circle"></i> Extra / Unregistered Rolls ('+reconData.extra_count+')</h3>'+extraHtml
    +'<div style="border-top:2px solid #0f172a;padding-top:8px;margin-top:14px;font-size:10px;color:#94a3b8;display:flex;justify-content:space-between"><div><strong>'+escHtml(companyName)+'</strong> — Audit Report</div><div>Generated: '+new Date().toLocaleString('en-IN')+'</div></div>'
    +'</div></body></html>';

  w.document.write(html);
  w.document.close();
};

// ── Delete Session ────────────────────────────────────────
window.deleteSession = function(id, name){
  if(!IS_ADMIN) return;
  var run = function(){
    postAPI('delete_session', {session_id: id}, function(res){
      if(!res.ok){ alert(res.error); return; }
      if(activeSessionId == id){
        activeSessionId = null;
        activeSession = null;
        $('ah-panel').classList.remove('visible');
      }
      loadSessions();
    });
  };
  var msg = 'Delete session "'+name+'"?\nAll scanned data in this session will be permanently removed.';
  if (typeof window.showERPConfirm === 'function') { window.showERPConfirm(msg, run, { title:'Please Confirm', okLabel:'Delete', cancelLabel:'Cancel' }); return; }
  run();
};

// ── Init ──────────────────────────────────────────────────
loadSessions();

})();
</script>
