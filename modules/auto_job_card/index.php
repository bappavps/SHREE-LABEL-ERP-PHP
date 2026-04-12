<?php
// ============================================================
// ERP System — Auto Slitting Terminal (Industrial Slitting)
// Full port of Firebase Auto Planner + Manual Terminal
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$csrfToken = generateCSRF();
$userName = $_SESSION['user_name'] ?? 'Operator';

$pageTitle = 'Auto Slitting Terminal';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Auto Slitting Terminal</span>
</div>

<!-- ── Page Header ── -->
<div class="page-header" style="border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:0">
  <div>
    <h1 style="font-size:1.5rem;font-weight:900;text-transform:uppercase;letter-spacing:-.02em">
      <i class="bi bi-scissors" style="margin-right:8px;opacity:.7"></i>Industrial Slitting Terminal
    </h1>
    <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:#64748b;margin-top:2px">
      Decision Support &amp; Multi-Unit Technical Controller
    </p>
  </div>
  <div class="d-flex gap-8">
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Paper Stock</a>
    <a href="<?= BASE_URL ?>/modules/planning/index.php" class="btn btn-ghost"><i class="bi bi-kanban"></i> Planning Board</a>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     AUTO PLANNER INTEGRATION
     ═══════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:16px;border:none;border-radius:16px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.12)">
  <div style="background:#0f172a;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between">
    <div style="display:flex;align-items:center;gap:10px">
      <i class="bi bi-lightning-charge-fill" style="color:var(--brand);font-size:1.1rem"></i>
      <span style="font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.12em">Auto Planner Integration</span>
    </div>
    <span style="background:rgba(34,197,94,.15);color:#22c55e;padding:3px 10px;border-radius:20px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;border:1px solid rgba(34,197,94,.3)">Decision Support Active</span>
  </div>
  <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:24px">

    <!-- LEFT: Planning Job List -->
    <div>
      <label style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.1em;display:block;margin-bottom:8px">Search Active Planning Jobs</label>
      <div style="position:relative;margin-bottom:10px">
        <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.85rem"></i>
        <input type="text" id="plannerSearch" class="form-control" placeholder="Search job name or material..." style="padding-left:36px;border-radius:12px;height:40px">
      </div>
      <!-- Status Filter Tabs -->
      <div id="plannerStatusTabs" style="display:flex;gap:4px;margin-bottom:10px;flex-wrap:wrap"></div>
      <div id="plannerJobList" style="max-height:350px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;padding:8px;background:#f8fafc">
        <div style="padding:40px 0;text-align:center;color:#94a3b8"><i class="bi bi-hourglass-split" style="font-size:1.5rem;display:block;margin-bottom:8px"></i><span style="font-size:.8rem">Loading planning jobs...</span></div>
      </div>
    </div>

    <!-- RIGHT: Job Detail + Analyze Button -->
    <div style="display:flex;flex-direction:column;justify-content:center;align-items:center;padding:20px;background:#f8fafc;border-radius:12px;border:2px dashed #e2e8f0" id="plannerDetailWrap">
      <div style="text-align:center;opacity:.3" id="plannerEmptyHint">
        <i class="bi bi-grid" style="font-size:2.5rem;display:block;margin-bottom:8px"></i>
        <p style="font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em">Select job from the list to auto-populate terminal</p>
      </div>
      <div id="plannerJobDetail" style="display:none;width:100%;max-width:380px"></div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MANUAL SLITTING TERMINAL — 4-Panel Grid
     ═══════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:16px;margin-top:16px;min-height:500px">

  <!-- PANEL 1: Load Jumbos -->
  <div class="card" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);display:flex;flex-direction:column">
    <div style="background:#0f172a;color:#fff;padding:12px 16px;display:flex;align-items:center;gap:8px">
      <i class="bi bi-plus-circle" style="color:var(--brand)"></i>
      <span style="font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em">Load Jumbos</span>
    </div>
    <div style="padding:12px;flex:1;display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;gap:8px">
        <input type="text" id="rollSearch" class="form-control" placeholder="Scan Roll ID..." style="border-radius:10px;height:40px;font-weight:700;text-transform:uppercase;flex:1">
        <button class="btn btn-primary" id="btnSearchRoll" style="height:40px;width:40px;border-radius:10px;padding:0"><i class="bi bi-search"></i></button>
      </div>
      <div id="loadedRollsList" style="flex:1;overflow-y:auto;min-height:100px">
        <div style="padding:60px 0;text-align:center;opacity:.2">
          <i class="bi bi-box" style="font-size:2rem;display:block;margin-bottom:6px"></i>
          <span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Batch Empty</span>
        </div>
      </div>
    </div>
  </div>

  <!-- PANEL 2: Batch Status -->
  <div class="card" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);display:flex;flex-direction:column">
    <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:12px 16px;display:flex;align-items:center;gap:8px">
      <i class="bi bi-activity" style="color:var(--brand)"></i>
      <span style="font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em">Batch Status</span>
    </div>
    <div style="padding:12px;flex:1;overflow-y:auto" id="batchStatusPanel">
      <div style="padding:60px 0;text-align:center;opacity:.2">
        <i class="bi bi-bar-chart" style="font-size:2rem;display:block;margin-bottom:6px"></i>
        <span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Load rolls to see status</span>
      </div>
    </div>
    <div style="padding:12px;border-top:1px solid #e2e8f0">
      <button class="btn btn-primary" id="btnExecuteBatch" style="width:100%;border-radius:10px;height:44px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;display:none">
        <i class="bi bi-lightning-charge-fill"></i> Execute Slitting Batch
      </button>
    </div>
  </div>

  <!-- PANEL 3: Configuration Terminal (2-col wide) -->
  <div class="card" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);display:flex;flex-direction:column">
    <div style="background:#0f172a;color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:8px">
        <i class="bi bi-sliders" style="color:var(--brand)"></i>
        <span style="font-size:.65rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em">Configuration Terminal</span>
      </div>
      <span id="configRollLabel" style="font-size:.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase">No Roll Selected</span>
    </div>
    <div style="padding:16px;flex:1;overflow-y:auto" id="configPanel">
      <div style="padding:80px 0;text-align:center;opacity:.2">
        <i class="bi bi-sliders2" style="font-size:2rem;display:block;margin-bottom:6px"></i>
        <span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Select a loaded roll to configure slitting pattern</span>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     STOCK OPTIONS MODAL
     ═══════════════════════════════════════════════════════ -->
<div class="planning-modal" id="stockModal" style="display:none">
  <div class="planning-modal-card" style="width:min(1200px,96%);max-height:90vh">
    <div style="background:#0f172a;color:#fff;padding:16px 20px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
          <i class="bi bi-bar-chart-fill" style="color:var(--brand)"></i>
          <div>
            <div style="font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em">Stock Decision Support</div>
            <div style="font-size:.55rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em">Compare efficiencies and technical yield</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
          <!-- Supplier Filter -->
          <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.05);padding:6px 14px;border-radius:12px">
            <span style="font-size:.55rem;font-weight:900;text-transform:uppercase;color:#94a3b8">Supplier:</span>
            <select id="supplierFilter" class="form-control" style="background:transparent;border:none;color:#fff;font-size:.7rem;font-weight:800;min-width:160px;height:30px;padding:0 6px;cursor:pointer">
              <option value="all" style="color:#000">ALL SUPPLIERS</option>
            </select>
          </div>
          <span id="stockModalBadge" style="font-size:.6rem;font-weight:700;color:#94a3b8"></span>
          <button type="button" class="btn btn-ghost btn-sm" onclick="closeStockModal()" style="color:#fff"><i class="bi bi-x-lg"></i></button>
        </div>
      </div>
    </div>
    <div style="padding:16px;overflow-y:auto;max-height:calc(90vh - 140px)" id="stockModalContent">
      <div style="text-align:center;padding:40px;color:#94a3b8"><i class="bi bi-hourglass-split" style="font-size:2rem"></i><p style="margin-top:8px">Analyzing...</p></div>
    </div>
    <div style="border-top:1px solid #e2e8f0;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;background:#f8fafc" id="stockModalFooter">
      <div id="productionSummary" style="font-size:.75rem;font-weight:700"></div>
      <button class="btn btn-primary" id="btnDeployTerminal" style="border-radius:10px;font-weight:800;text-transform:uppercase;font-size:.7rem;letter-spacing:.06em;display:none" onclick="deployToTerminal()">
        <i class="bi bi-arrow-right-circle"></i> Deploy Selection to Terminal
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     REPORT MODAL
     ═══════════════════════════════════════════════════════ -->
<div class="planning-modal" id="reportModal" style="display:none">
  <div class="planning-modal-card" style="width:min(900px,96%);max-height:90vh">
    <div style="background:#0f172a;color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em"><i class="bi bi-file-earmark-text" style="margin-right:8px"></i>Slitting Report</span>
      <div style="display:flex;gap:8px">
        <button class="btn btn-ghost btn-sm" onclick="printReport()" style="color:#fff"><i class="bi bi-printer"></i> Print</button>
        <button class="btn btn-ghost btn-sm" onclick="closeReportModal()" style="color:#fff"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div style="padding:20px;overflow-y:auto" id="reportContent"></div>
  </div>
</div>

<form id="csrf-form" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
</form>

<style>
/* ── Auto Slitting Terminal Styles ── */
.aslt-job-item { padding:10px 12px; border-radius:10px; border:2px solid #fff; background:#fff; cursor:pointer; display:flex; justify-content:space-between; align-items:center; transition:all .15s; margin-bottom:6px; }
.aslt-job-item:hover { border-color:rgba(var(--brand-rgb,34,197,94),.2); background:#fafafa; }
.aslt-job-item.selected { border-color:var(--brand); background:rgba(var(--brand-rgb,34,197,94),.05); box-shadow:0 0 0 1px var(--brand); }
.aslt-job-label { font-size:.72rem; font-weight:900; text-transform:uppercase; letter-spacing:.02em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.aslt-job-meta { font-size:.6rem; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-top:2px; }
.aslt-badge-req { font-size:.55rem; font-weight:900; background:rgba(var(--brand-rgb,34,197,94),.1); color:var(--brand); border:1px solid rgba(var(--brand-rgb,34,197,94),.2); padding:2px 8px; border-radius:20px; text-transform:uppercase; white-space:nowrap; }

.aslt-roll-item { padding:10px 12px; border-radius:10px; border:2px solid #f1f5f9; cursor:pointer; display:flex; flex-direction:column; transition:all .15s; margin-bottom:6px; position:relative; }
.aslt-roll-item:hover { background:#f8fafc; }
.aslt-roll-item.active { border-color:var(--brand); background:rgba(var(--brand-rgb,34,197,94),.04); }
.aslt-roll-item .remove-btn { position:absolute; top:4px; right:4px; width:22px; height:22px; border-radius:50%; background:#fff; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:center; cursor:pointer; opacity:0; transition:opacity .15s; font-size:.7rem; color:#64748b; }
.aslt-roll-item:hover .remove-btn { opacity:1; }
.aslt-roll-item .remove-btn:hover { color:#ef4444; border-color:#ef4444; }

.aslt-detail-card { background:#fff; border-radius:16px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,.04); padding:16px; }
.aslt-detail-row { display:flex; justify-content:space-between; padding:6px 0; font-size:.78rem; }
.aslt-detail-label { font-size:.6rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; }
.aslt-detail-value { font-weight:800; }

.aslt-stock-row { display:grid; grid-template-columns:2fr 1fr 2fr 1.5fr 1fr 1fr; gap:12px; align-items:center; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:8px; background:#fff; }
.aslt-stock-row:hover { background:#f8fafc; }
.aslt-eff-bar { width:100%; height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; margin-top:4px; }
.aslt-eff-fill { height:100%; border-radius:3px; transition:width .3s; }

.aslt-run-table { width:100%; border-collapse:collapse; }
.aslt-run-table th { font-size:.6rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; padding:6px 8px; text-align:left; border-bottom:1px solid #e2e8f0; }
.aslt-run-table td { padding:6px 8px; border-bottom:1px solid #f1f5f9; }
.aslt-run-table input { width:100%; height:34px; border:1px solid #e2e8f0; border-radius:6px; padding:0 8px; font-weight:700; font-size:.8rem; }
.aslt-run-table input:focus { border-color:var(--brand); outline:none; box-shadow:0 0 0 2px rgba(34,197,94,.15); }

.aslt-yield-box { display:inline-flex; flex-direction:column; align-items:center; justify-content:center; width:80px; height:80px; border-radius:10px; margin:4px; font-size:.6rem; font-weight:800; text-transform:uppercase; color:#fff; }
.aslt-yield-job { background:#3b82f6; }
.aslt-yield-stock { background:#22c55e; }
.aslt-yield-rem { background:#f59e0b; }

/* Qty stepper */
.qty-stepper { display:inline-flex; align-items:center; gap:4px; }
.qty-stepper button { width:24px; height:24px; border-radius:50%; border:1px solid #e2e8f0; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:.8rem; color:#374151; }
.qty-stepper button:hover { background:#f1f5f9; }
.qty-stepper span { font-weight:900; min-width:24px; text-align:center; font-size:.85rem; }

/* Wastage toggle */
.waste-toggle { display:inline-flex; border-radius:6px; overflow:hidden; border:1px solid #e2e8f0; }
.waste-toggle button { padding:5px 12px; font-size:.6rem; font-weight:900; text-transform:uppercase; border:none; cursor:pointer; background:#f1f5f9; color:#64748b; transition:all .15s; letter-spacing:.04em; }
.waste-toggle button.stock-active { background:#22c55e; color:#fff; box-shadow:0 2px 8px rgba(34,197,94,.3); }
.waste-toggle button.adjust-active { background:#f97316; color:#fff; box-shadow:0 2px 8px rgba(249,115,22,.3); }

/* Batch status per roll */
.aslt-batch-roll { padding:10px; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:8px; }
.aslt-progress-bar { width:100%; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; margin-top:6px; }
.aslt-progress-fill { height:100%; border-radius:4px; transition:width .3s; }

/* Report styles */
.report-table { width:100%; border-collapse:collapse; font-size:.78rem; margin-top:8px; }
.report-table th, .report-table td { border:1px solid #e2e8f0; padding:6px 10px; text-align:left; }
.report-table th { background:#f8fafc; font-weight:700; font-size:.65rem; text-transform:uppercase; color:#64748b; }
.sig-box { border:1px solid #e2e8f0; border-radius:8px; padding:40px 16px 8px; text-align:center; font-size:.65rem; font-weight:700; color:#94a3b8; text-transform:uppercase; min-width:140px; }

@media (max-width:1024px) {
  .aslt-stock-row { grid-template-columns:1fr 1fr; }
}
@media (max-width:768px) {
  #plannerDetailWrap ~ div[style*="grid-template-columns"] { grid-template-columns:1fr !important; }
}
</style>

<script>
(function(){
'use strict';

var API = '<?= BASE_URL ?>/modules/inventory/slitting/api.php';
var CSRF = document.querySelector('#csrf-form [name="csrf_token"]').value;
var OPERATOR = <?= json_encode($userName) ?>;

// ── State ──
var plannerJobs = [];
var selectedJob = null;
var selectedRolls = [];
var activeRollId = null;
var rollConfigs = {};
var stockOptions = [];
var selectionMap = {};
var wastagePrefs = {};
var plannerFilter = 'pending'; // status tab filter
var selectedSupplier = 'all';

// ── Helper ──
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function statusColor(s) {
  s = (s||'').toLowerCase();
  if (s === 'completed' || s === 'slitted paper') return '#22c55e';
  if (s === 'running' || s === 'in progress') return '#3b82f6';
  if (s.indexOf('hold') === 0) return '#ef4444';
  return '#94a3b8';
}

async function apiGet(action, params) {
  var url = new URL(API, window.location.origin);
  url.searchParams.set('action', action);
  if (params) Object.keys(params).forEach(function(k){ url.searchParams.set(k, params[k]); });
  try { var r = await fetch(url); return await r.json(); } catch(e) { return {ok:false, error:e.message}; }
}

async function apiPost(action, body) {
  var form = new FormData();
  form.append('action', action);
  form.append('csrf_token', CSRF);
  if (body) Object.keys(body).forEach(function(k){ form.append(k, body[k]); });
  try { var r = await fetch(API, {method:'POST', body:form}); return await r.json(); } catch(e) { return {ok:false, error:e.message}; }
}

function toast(msg, type) {
  if (typeof window.showERPToast === 'function') { window.showERPToast(msg, type); return; }
  if (typeof window.erpToast === 'function') { window.erpToast(msg, type); return; }
  alert((type === 'error' ? 'Error: ' : '') + msg);
}

// ═════════════════════════════════════════════════════════
// AUTO PLANNER
// ═════════════════════════════════════════════════════════
async function loadPlannerJobs() {
  var data = await apiGet('get_planning_jobs');
  if (!data.ok) return;
  plannerJobs = (data.jobs || []).map(function(j) {
    var extra = {};
    try { extra = JSON.parse(j.extra_data || '{}'); } catch(e) {}
    return Object.assign({}, j, {
      _name: j.material_type || extra.name || j.job_name || '',
      _material: j.material_type || extra.material || '',
      _paperSize: j.label_width_mm || extra.paper_size || '',
      _size: j.label_length_mm || extra.size || '',
      _mtrs: j.allocate_mtrs || extra.allocate_mtrs || '',
      _status: j.printing_planning || extra.printing_planning || j.status || 'Pending',
      _extra: extra
    });
  });
  renderPlannerTabs();
  renderPlannerJobs();
}

function renderPlannerTabs() {
  var el = document.getElementById('plannerStatusTabs');
  // Count statuses
  var counts = { all: plannerJobs.length };
  plannerJobs.forEach(function(j) {
    var s = (j._status || 'Pending').toLowerCase();
    counts[s] = (counts[s] || 0) + 1;
  });
  var tabs = [
    { key: 'all', label: 'All', count: counts.all },
    { key: 'pending', label: 'Pending', count: counts['pending'] || 0 },
    { key: 'running', label: 'Running', count: (counts['running']||0) + (counts['in progress']||0) },
    { key: 'completed', label: 'Completed', count: counts['completed'] || 0 }
  ];
  el.innerHTML = tabs.map(function(t) {
    var isActive = plannerFilter === t.key;
    return '<button style="padding:4px 12px;border-radius:20px;font-size:.6rem;font-weight:800;border:1px solid ' + (isActive ? 'var(--brand)' : '#e2e8f0') + ';background:' + (isActive ? 'var(--brand)' : '#fff') + ';color:' + (isActive ? '#fff' : '#64748b') + ';cursor:pointer;text-transform:uppercase;letter-spacing:.04em" onclick="AST.setPlannerFilter(\'' + t.key + '\')">' + t.label + ' <span style="opacity:.6">(' + t.count + ')</span></button>';
  }).join('');
}

function setPlannerFilter(f) {
  plannerFilter = f;
  renderPlannerTabs();
  renderPlannerJobs(document.getElementById('plannerSearch').value);
}

function renderPlannerJobs(filter) {
  var el = document.getElementById('plannerJobList');
  var jobs = plannerJobs;
  if (filter) {
    var f = filter.toLowerCase();
    jobs = jobs.filter(function(j){ return (j.job_name||'').toLowerCase().indexOf(f)>=0 || (j._material||'').toLowerCase().indexOf(f)>=0; });
  }

  // Apply status tab filter
  if (plannerFilter !== 'all') {
    jobs = jobs.filter(function(j) {
      var s = (j._status || '').toLowerCase();
      if (plannerFilter === 'running') return s === 'running' || s === 'in progress';
      return s === plannerFilter;
    });
  }

  if (!jobs.length) {
    el.innerHTML = '<div style="padding:40px 0;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:8px"></i><span style="font-size:.75rem">No jobs found for this filter</span></div>';
    return;
  }

  el.innerHTML = jobs.map(function(j) {
    var sel = selectedJob && selectedJob.id == j.id;
    return '<div class="aslt-job-item' + (sel ? ' selected' : '') + '" onclick="AST.selectJob(' + j.id + ')">' +
      '<div style="flex:1;min-width:0">' +
        '<div class="aslt-job-label">' + esc(j.job_name) + '</div>' +
        '<div class="aslt-job-meta">' + esc(j._material || 'N/A') + ' | ' + esc(j._paperSize || j._size || '?') + '</div>' +
      '</div>' +
      '<div style="display:flex;align-items:center;gap:8px">' +
        (j._mtrs ? '<span class="aslt-badge-req">REQ: ' + esc(j._mtrs) + ' MTR</span>' : '') +
        '<span class="badge" style="font-size:.55rem;font-weight:800;background:' + statusColor(j._status) + ';color:#fff;padding:3px 8px;border-radius:20px">' + esc(j._status) + '</span>' +
      '</div>' +
    '</div>';
  }).join('');
}

function selectJob(id) {
  selectedJob = plannerJobs.find(function(j){ return j.id == id; }) || null;
  renderPlannerJobs(document.getElementById('plannerSearch').value);
  renderJobDetail();
}

function renderJobDetail() {
  var detailEl = document.getElementById('plannerJobDetail');
  var hintEl = document.getElementById('plannerEmptyHint');

  if (!selectedJob) {
    detailEl.style.display = 'none';
    hintEl.style.display = '';
    return;
  }
  hintEl.style.display = 'none';
  detailEl.style.display = '';

  var j = selectedJob;
  detailEl.innerHTML =
    '<div class="aslt-detail-card" style="margin-bottom:12px">' +
      '<div style="margin-bottom:10px"><p class="aslt-detail-label">Targeting Job</p><p style="font-size:1.1rem;font-weight:900;color:var(--brand);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(j.job_name) + '</p></div>' +
      '<div style="border-top:1px solid #e2e8f0;padding-top:8px;margin-bottom:8px"><p class="aslt-detail-label">Substrate</p><p style="font-weight:800;font-size:.9rem">' + esc(j._material || 'N/A') + '</p></div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
        '<div style="background:#f8fafc;padding:10px;border-radius:10px"><p class="aslt-detail-label">Paper Size</p><p style="font-weight:800;font-size:.85rem">' + esc(j._paperSize || j._size || '—') + '</p></div>' +
        '<div style="background:#f8fafc;padding:10px;border-radius:10px"><p class="aslt-detail-label">Goal Meter</p><p style="font-weight:800;font-size:.85rem">' + esc(j._mtrs || '—') + ' M</p></div>' +
      '</div>' +
    '</div>' +
    '<button class="btn btn-primary" style="width:100%;height:48px;border-radius:12px;background:#0f172a;color:#fff;font-weight:800;text-transform:uppercase;font-size:.7rem;letter-spacing:.08em" onclick="AST.analyzeStock()">' +
      'Analyze Stock Options <i class="bi bi-chevron-right" style="margin-left:6px"></i>' +
    '</button>';
}

// ═════════════════════════════════════════════════════════
// STOCK ANALYSIS
// ═════════════════════════════════════════════════════════
async function analyzeStock() {
  if (!selectedJob) return;
  var modal = document.getElementById('stockModal');
  modal.style.display = 'flex';
  var content = document.getElementById('stockModalContent');
  content.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="bi bi-hourglass-split" style="font-size:2rem"></i><p style="margin-top:8px">Analyzing stock options...</p></div>';

  var j = selectedJob;
  var targetWidth = parseInt(j._paperSize) || parseInt(j._size) || 0;
  var material = j._material || '';
  var reqMtrs = parseFloat(j._mtrs) || 0;

  var data = await apiGet('search_rolls_by_material', {
    paper_type: material,
    target_width: targetWidth,
    target_length: 0
  });

  if (!data.ok || !data.options || !data.options.length) {
    content.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="bi bi-exclamation-triangle" style="font-size:2rem;color:#f59e0b"></i><p style="margin-top:8px;font-weight:700">No suitable stock found for <strong>' + esc(material) + '</strong> at width ≥ ' + targetWidth + 'mm</p></div>';
    document.getElementById('btnDeployTerminal').style.display = 'none';
    document.getElementById('productionSummary').innerHTML = '';
    return;
  }

  stockOptions = data.options;
  selectionMap = {};
  wastagePrefs = {};
  selectedSupplier = 'all';

  // Populate supplier dropdown with unique companies
  var supplierSelect = document.getElementById('supplierFilter');
  var uniqueSuppliers = [];
  data.options.forEach(function(opt) {
    var c = (opt.roll.company || '').trim();
    if (c && uniqueSuppliers.indexOf(c) === -1) uniqueSuppliers.push(c);
  });
  uniqueSuppliers.sort();
  supplierSelect.innerHTML = '<option value="all" style="color:#000">ALL SUPPLIERS</option>';
  uniqueSuppliers.forEach(function(s) {
    supplierSelect.innerHTML += '<option value="' + esc(s) + '" style="color:#000">' + esc(s) + '</option>';
  });
  supplierSelect.value = 'all';
  supplierSelect.onchange = function() {
    selectedSupplier = this.value;
    renderStockOptions(targetWidth, reqMtrs);
  };

  // Store context for rendering
  content._targetWidth = targetWidth;
  content._reqMtrs = reqMtrs;

  renderStockOptions(targetWidth, reqMtrs);
}

function renderStockOptions(targetWidth, reqMtrs) {
  var content = document.getElementById('stockModalContent');

  // Filter by supplier
  var filteredOptions = stockOptions;
  if (selectedSupplier !== 'all') {
    filteredOptions = stockOptions.filter(function(opt) { return (opt.roll.company || '').trim() === selectedSupplier; });
  }

  if (!filteredOptions.length) {
    content.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="bi bi-funnel" style="font-size:2rem;color:#64748b"></i><p style="margin-top:8px;font-weight:700">No stock from <strong>' + esc(selectedSupplier) + '</strong> matches this requirement</p></div>';
    content._groups = [];
    updateProductionSummary();
    return;
  }

  // Group by dimension key
  var grouped = {};
  filteredOptions.forEach(function(opt, idx) {
    var r = opt.roll;
    var key = r.width_mm + 'x' + r.length_mtr + '-' + (r.company||'');
    if (!grouped[key]) {
      grouped[key] = { roll: r, splits: opt.splits, waste_mm: opt.waste_mm, efficiency: opt.efficiency, rolls: [], key: key };
      if (selectionMap[key] === undefined) selectionMap[key] = 0;
      if (!wastagePrefs[key]) wastagePrefs[key] = 'STOCK';
    }
    grouped[key].rolls.push(r);
  });

  var groups = Object.values(grouped);
  // Sort: best fit first (lowest waste), then by efficiency
  groups.sort(function(a, b) { return a.waste_mm - b.waste_mm || b.efficiency - a.efficiency; });

  document.getElementById('stockModalBadge').textContent = 'Showing: ' + filteredOptions.length + ' of ' + stockOptions.length + ' rolls';

  // Render options
  var html = '<div style="display:grid;grid-template-columns:2fr 1fr 2fr 1.5fr 1fr 1fr;gap:12px;padding:0 14px 8px;font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em">' +
    '<span>Dimension & Priority</span><span>Wastage Control</span><span>Slitting Result</span><span>Yield Details</span><span>Efficiency</span><span>Batch Qty</span></div>';

  groups.forEach(function(g) {
    var r = g.roll;
    var effColor = g.efficiency >= 90 ? '#16a34a' : (g.efficiency >= 70 ? '#d97706' : '#dc2626');
    var priority = g.waste_mm === 0 ? 'Best Fit' : (r.width_mm >= 1000 ? 'Jumbo' : 'Alternate');
    var priBg = g.waste_mm === 0 ? '#22c55e' : (r.width_mm >= 1000 ? '#f59e0b' : '#3b82f6');
    var yieldPerRoll = (parseFloat(r.length_mtr) || 0) * g.splits;

    var stockResult = targetWidth + 'mm × ' + g.splits + ' splits';
    if (g.waste_mm > 0) stockResult += ' + ' + Math.round(g.waste_mm) + 'mm (Stock)';
    var adjustResult = Math.round(parseFloat(r.width_mm) / g.splits) + 'mm × ' + g.splits + ' parts (Adjusted)';

    html += '<div class="aslt-stock-row" data-key="' + esc(g.key) + '">' +
      // Dimension
      '<div>' +
        '<div style="font-weight:900;font-size:.8rem">' + r.width_mm + 'mm × ' + r.length_mtr + 'm</div>' +
        '<div style="font-size:.6rem;color:#94a3b8;margin-top:2px">' + esc(r.roll_no) + ' · ' + esc(r.company || '') + '</div>' +
        '<span style="display:inline-block;margin-top:4px;font-size:.5rem;font-weight:800;background:' + priBg + ';color:#fff;padding:2px 8px;border-radius:10px">' + priority + '</span>' +
      '</div>' +
      // Wastage toggle
      '<div>' +
        '<div style="font-size:.65rem;color:#94a3b8;font-weight:700;margin-bottom:4px">Waste: ' + Math.round(g.waste_mm) + 'mm</div>' +
        '<div class="waste-toggle">' +
          '<button class="' + (wastagePrefs[g.key]==='STOCK'?'stock-active':'') + '" onclick="AST.setWaste(\'' + esc(g.key) + '\',\'STOCK\')">Stock</button>' +
          '<button class="' + (wastagePrefs[g.key]==='ADJUST'?'adjust-active':'') + '" onclick="AST.setWaste(\'' + esc(g.key) + '\',\'ADJUST\')">Adjust</button>' +
        '</div>' +
      '</div>' +
      // Slitting result
      '<div>' +
        '<div style="font-size:.72rem;font-weight:700" id="resultText_' + esc(g.key) + '">' + (wastagePrefs[g.key]==='STOCK' ? stockResult : adjustResult) + '</div>' +
      '</div>' +
      // Yield
      '<div>' +
        '<div style="font-size:.7rem;font-weight:800">' + g.splits + ' units @ ' + r.length_mtr + ' mtr</div>' +
        '<div style="font-size:.6rem;color:#94a3b8">Output: ' + yieldPerRoll.toLocaleString() + ' M / roll</div>' +
      '</div>' +
      // Efficiency
      '<div>' +
        '<div style="font-size:.85rem;font-weight:900;color:' + effColor + '">' + g.efficiency + '%</div>' +
        '<div class="aslt-eff-bar"><div class="aslt-eff-fill" style="width:' + g.efficiency + '%;background:' + effColor + '"></div></div>' +
      '</div>' +
      // Qty stepper
      '<div>' +
        '<div class="qty-stepper">' +
          '<button onclick="AST.adjustQty(\'' + esc(g.key) + '\',-1)">−</button>' +
          '<span id="qty_' + esc(g.key) + '">' + (selectionMap[g.key]||0) + '</span>' +
          '<button onclick="AST.adjustQty(\'' + esc(g.key) + '\',1)">+</button>' +
        '</div>' +
        '<div style="font-size:.55rem;color:#94a3b8;text-align:center;margin-top:2px">of ' + g.rolls.length + '</div>' +
      '</div>' +
    '</div>';
  });

  content.innerHTML = html;
  content._groups = groups;
  content._targetWidth = targetWidth;
  content._reqMtrs = reqMtrs;
  updateProductionSummary();
}

function setWaste(key, mode) {
  wastagePrefs[key] = mode;
  // Update UI
  var row = document.querySelector('.aslt-stock-row[data-key="' + key + '"]');
  if (row) {
    row.querySelectorAll('.waste-toggle button').forEach(function(b){
      var bMode = b.textContent.trim().toUpperCase();
      b.className = '';
      if (bMode === mode) b.className = (mode === 'STOCK' ? 'stock-active' : 'adjust-active');
    });
  }
}

function adjustQty(key, delta) {
  var content = document.getElementById('stockModalContent');
  var groups = content._groups || [];
  var g = groups.find(function(x){ return x.key === key; });
  if (!g) return;
  var current = selectionMap[key] || 0;
  var newVal = Math.max(0, Math.min(g.rolls.length, current + delta));
  selectionMap[key] = newVal;
  var span = document.getElementById('qty_' + key);
  if (span) span.textContent = newVal;
  updateProductionSummary();
}

function updateProductionSummary() {
  var content = document.getElementById('stockModalContent');
  var groups = content._groups || [];
  var reqMtrs = content._reqMtrs || 0;

  var totalProduced = 0;
  groups.forEach(function(g) {
    var qty = selectionMap[g.key] || 0;
    var yieldPerRoll = (parseFloat(g.roll.length_mtr) || 0) * g.splits;
    totalProduced += qty * yieldPerRoll;
  });

  var summaryEl = document.getElementById('productionSummary');
  var pct = reqMtrs > 0 ? Math.min(100, Math.round(totalProduced / reqMtrs * 100)) : 0;
  var achieved = totalProduced >= reqMtrs && reqMtrs > 0;

  summaryEl.innerHTML =
    '<div style="display:flex;align-items:center;gap:12px">' +
      '<span style="font-weight:800">' + totalProduced.toLocaleString() + ' M of ' + (reqMtrs||0).toLocaleString() + ' M required</span>' +
      '<div style="width:120px;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + (achieved?'#22c55e':'#3b82f6') + ';border-radius:4px"></div></div>' +
      '<span class="badge" style="font-size:.55rem;font-weight:800;background:' + (achieved?'#22c55e':'#f59e0b') + ';color:#fff;padding:3px 8px;border-radius:10px">' + (achieved?'Target Achieved':'In Progress') + '</span>' +
    '</div>';

  // Show/hide deploy button
  var hasSelection = Object.values(selectionMap).some(function(v){ return v > 0; });
  document.getElementById('btnDeployTerminal').style.display = hasSelection ? '' : 'none';
}

function closeStockModal() {
  document.getElementById('stockModal').style.display = 'none';
}

// ═════════════════════════════════════════════════════════
// DEPLOY TO TERMINAL
// ═════════════════════════════════════════════════════════
function deployToTerminal() {
  var content = document.getElementById('stockModalContent');
  var groups = content._groups || [];
  var targetWidth = content._targetWidth || 0;
  var j = selectedJob;
  var sn = j ? (j._extra.sn || j.id) : '';

  groups.forEach(function(g) {
    var qty = selectionMap[g.key] || 0;
    if (qty <= 0) return;
    var pref = wastagePrefs[g.key] || 'STOCK';
    var rollsToUse = g.rolls.slice(0, qty);

    rollsToUse.forEach(function(roll) {
      // Add to selectedRolls if not already there
      if (!selectedRolls.find(function(r){ return r.roll_no === roll.roll_no; })) {
        selectedRolls.push(roll);
      }

      var slitWidth = pref === 'ADJUST' ? Math.round(parseFloat(roll.width_mm) / g.splits) : targetWidth;

      rollConfigs[roll.roll_no] = {
        jobNo: String(sn),
        jobName: j ? j.job_name : '',
        jobSize: j ? (j._size || j._paperSize || '') : '',
        runs: [{ width: slitWidth, length: parseFloat(roll.length_mtr) || 0, qty: g.splits }],
        remainderAction: pref
      };
    });
  });

  closeStockModal();
  renderLoadedRolls();
  renderBatchStatus();
  if (selectedRolls.length > 0) {
    activeRollId = selectedRolls[0].roll_no;
    renderLoadedRolls();
    renderConfig();
  }
  showExecuteButton();
}

// ═════════════════════════════════════════════════════════
// LOAD JUMBOS (Manual)
// ═════════════════════════════════════════════════════════
async function searchRoll() {
  var q = document.getElementById('rollSearch').value.trim();
  if (!q) return;

  var data = await apiGet('search_roll', { q: q });
  if (!data.ok) { toast('Search failed', 'error'); return; }

  if (data.roll) {
    addRoll(data.roll);
  } else if (data.suggestions && data.suggestions.length) {
    // Add first match
    addRoll(data.suggestions[0]);
  } else {
    toast('Roll not found: ' + q, 'error');
  }
  document.getElementById('rollSearch').value = '';
}

function addRoll(roll) {
  if (selectedRolls.find(function(r){ return r.roll_no === roll.roll_no; })) {
    toast('Roll already loaded', 'info');
    return;
  }
  selectedRolls.push(roll);
  if (!rollConfigs[roll.roll_no]) {
    rollConfigs[roll.roll_no] = { jobNo: '', jobName: '', jobSize: '', runs: [{ width: 0, length: parseFloat(roll.length_mtr)||0, qty: 1 }], remainderAction: 'STOCK' };
  }
  if (!activeRollId) activeRollId = roll.roll_no;
  renderLoadedRolls();
  renderBatchStatus();
  renderConfig();
  showExecuteButton();
}

function removeRoll(rollNo) {
  selectedRolls = selectedRolls.filter(function(r){ return r.roll_no !== rollNo; });
  delete rollConfigs[rollNo];
  if (activeRollId === rollNo) activeRollId = selectedRolls.length ? selectedRolls[0].roll_no : null;
  renderLoadedRolls();
  renderBatchStatus();
  renderConfig();
  showExecuteButton();
}

function setActiveRoll(rollNo) {
  activeRollId = rollNo;
  renderLoadedRolls();
  renderConfig();
}

function renderLoadedRolls() {
  var el = document.getElementById('loadedRollsList');
  if (!selectedRolls.length) {
    el.innerHTML = '<div style="padding:60px 0;text-align:center;opacity:.2"><i class="bi bi-box" style="font-size:2rem;display:block;margin-bottom:6px"></i><span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Batch Empty</span></div>';
    return;
  }
  el.innerHTML = selectedRolls.map(function(r) {
    return '<div class="aslt-roll-item' + (activeRollId===r.roll_no?' active':'') + '" onclick="AST.setActiveRoll(\'' + esc(r.roll_no) + '\')">' +
      '<span class="remove-btn" onclick="event.stopPropagation();AST.removeRoll(\'' + esc(r.roll_no) + '\')"><i class="bi bi-x"></i></span>' +
      '<span style="font-size:.78rem;font-weight:900;color:var(--brand);font-family:monospace">' + esc(r.roll_no) + '</span>' +
      '<span style="font-size:.6rem;font-weight:700;color:#94a3b8;text-transform:uppercase">' + (r.width_mm||'?') + 'mm × ' + (r.length_mtr||'?') + 'm</span>' +
    '</div>';
  }).join('');
}

// ═════════════════════════════════════════════════════════
// BATCH STATUS
// ═════════════════════════════════════════════════════════
function calculateRollStatus(rollNo) {
  var roll = selectedRolls.find(function(r){ return r.roll_no === rollNo; });
  if (!roll) return null;
  var config = rollConfigs[rollNo];
  if (!config || !config.runs || !config.runs.length) return { used: 0, remainder: parseFloat(roll.width_mm)||0, pct: 0, valid: true };

  var pw = parseFloat(roll.width_mm) || 0;
  var totalUsed = 0;
  config.runs.forEach(function(run) {
    totalUsed += (parseFloat(run.width)||0) * (parseInt(run.qty)||1);
  });

  var remainder = pw - totalUsed;
  return {
    used: Math.round(totalUsed * 100) / 100,
    remainder: Math.round(Math.max(0, remainder) * 100) / 100,
    pct: pw > 0 ? Math.min(100, Math.round(totalUsed / pw * 100)) : 0,
    valid: remainder >= -0.5
  };
}

function renderBatchStatus() {
  var el = document.getElementById('batchStatusPanel');
  if (!selectedRolls.length) {
    el.innerHTML = '<div style="padding:60px 0;text-align:center;opacity:.2"><i class="bi bi-bar-chart" style="font-size:2rem;display:block;margin-bottom:6px"></i><span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Load rolls to see status</span></div>';
    return;
  }

  el.innerHTML = selectedRolls.map(function(r) {
    var status = calculateRollStatus(r.roll_no);
    var config = rollConfigs[r.roll_no] || {};
    var barColor = status.valid ? '#22c55e' : '#ef4444';
    var statusLabel = status.valid ? 'READY' : 'EXCEEDED';
    var statusBg = status.valid ? '#dcfce7' : '#fee2e2';
    var statusTxt = status.valid ? '#16a34a' : '#dc2626';

    return '<div class="aslt-batch-roll">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">' +
        '<span style="font-size:.72rem;font-weight:900;font-family:monospace;color:var(--brand)">' + esc(r.roll_no) + '</span>' +
        '<span style="font-size:.55rem;font-weight:800;background:' + statusBg + ';color:' + statusTxt + ';padding:2px 8px;border-radius:10px">' + statusLabel + '</span>' +
      '</div>' +
      '<div style="display:flex;justify-content:space-between;font-size:.6rem;color:#64748b;font-weight:700;margin-bottom:2px">' +
        '<span>Used: ' + status.used + 'mm</span>' +
        '<span style="color:#22c55e">To Stock: ' + status.remainder + 'mm</span>' +
      '</div>' +
      '<div class="aslt-progress-bar"><div class="aslt-progress-fill" style="width:' + status.pct + '%;background:' + barColor + '"></div></div>' +
      (config.jobNo ? '<div style="font-size:.55rem;color:#3b82f6;font-weight:700;margin-top:4px">Job: ' + esc(config.jobNo) + ' — ' + esc(config.jobName||'') + '</div>' : '<div style="font-size:.55rem;color:#94a3b8;font-weight:700;margin-top:4px">STOCK RUN</div>') +
    '</div>';
  }).join('');
}

function showExecuteButton() {
  document.getElementById('btnExecuteBatch').style.display = selectedRolls.length > 0 ? '' : 'none';
}

// ═════════════════════════════════════════════════════════
// CONFIGURATION TERMINAL
// ═════════════════════════════════════════════════════════
function renderConfig() {
  var el = document.getElementById('configPanel');
  var labelEl = document.getElementById('configRollLabel');

  if (!activeRollId) {
    labelEl.textContent = 'No Roll Selected';
    el.innerHTML = '<div style="padding:80px 0;text-align:center;opacity:.2"><i class="bi bi-sliders2" style="font-size:2rem;display:block;margin-bottom:6px"></i><span style="font-size:.6rem;font-weight:800;text-transform:uppercase">Select a loaded roll to configure slitting pattern</span></div>';
    return;
  }

  var roll = selectedRolls.find(function(r){ return r.roll_no === activeRollId; });
  if (!roll) return;

  var config = rollConfigs[activeRollId] || { jobNo: '', jobName: '', jobSize: '', runs: [{ width: 0, length: parseFloat(roll.length_mtr)||0, qty: 1 }], remainderAction: 'STOCK' };
  rollConfigs[activeRollId] = config;

  labelEl.textContent = activeRollId + ' — ' + (roll.width_mm||'?') + 'mm × ' + (roll.length_mtr||'?') + 'm';

  var html = '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px">' +
    '<div><label style="font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:4px">Job Number</label>' +
      '<input class="form-control" style="border-radius:8px;height:36px;font-weight:700;font-size:.8rem" value="' + esc(config.jobNo) + '" onchange="AST.updateConfig(\'jobNo\',this.value)"></div>' +
    '<div><label style="font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:4px">Job Name</label>' +
      '<input class="form-control" style="border-radius:8px;height:36px;font-weight:700;font-size:.8rem" value="' + esc(config.jobName) + '" onchange="AST.updateConfig(\'jobName\',this.value)"></div>' +
    '<div><label style="font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:4px">Job Size</label>' +
      '<input class="form-control" style="border-radius:8px;height:36px;font-weight:700;font-size:.8rem" value="' + esc(config.jobSize) + '" onchange="AST.updateConfig(\'jobSize\',this.value)"></div>' +
  '</div>';

  // Run table
  html += '<table class="aslt-run-table"><thead><tr><th>Slit Width (MM)</th><th>Length (MTR)</th><th>Qty</th><th style="width:40px"></th></tr></thead><tbody>';
  config.runs.forEach(function(run, idx) {
    html += '<tr>' +
      '<td><input type="number" value="' + (run.width||'') + '" onchange="AST.updateRun(' + idx + ',\'width\',this.value)" placeholder="Width"></td>' +
      '<td><input type="number" value="' + (run.length||'') + '" onchange="AST.updateRun(' + idx + ',\'length\',this.value)" placeholder="Length"></td>' +
      '<td><input type="number" value="' + (run.qty||1) + '" min="1" onchange="AST.updateRun(' + idx + ',\'qty\',this.value)"></td>' +
      '<td><button class="btn btn-ghost btn-sm" onclick="AST.removeRun(' + idx + ')" style="color:#ef4444;padding:0"><i class="bi bi-x-circle"></i></button></td>' +
    '</tr>';
  });
  html += '</tbody></table>';
  html += '<button class="btn btn-ghost btn-sm" style="margin-top:8px;font-size:.7rem;font-weight:700" onclick="AST.addRun()"><i class="bi bi-plus-circle"></i> Add Slit</button>';

  // Visual yield preview
  html += '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #e2e8f0">';
  html += '<div style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:8px">Visual Yield Preview</div>';
  html += '<div style="display:flex;flex-wrap:wrap;gap:6px">';

  var labels = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  var labelIdx = 0;
  config.runs.forEach(function(run) {
    var q = parseInt(run.qty) || 1;
    var isJob = config.jobNo && config.jobNo !== '';
    for (var i = 0; i < q && labelIdx < 26; i++) {
      html += '<div class="aslt-yield-box ' + (isJob ? 'aslt-yield-job' : 'aslt-yield-stock') + '">' +
        '<span style="font-size:1rem;font-weight:900">' + labels[labelIdx] + '</span>' +
        '<span>' + (run.width||'?') + 'mm</span>' +
        '<span>' + (run.length||'?') + 'm</span>' +
      '</div>';
      labelIdx++;
    }
  });

  // Remainder box
  var status = calculateRollStatus(activeRollId);
  if (status && status.remainder > 0.5) {
    html += '<div class="aslt-yield-box aslt-yield-rem">' +
      '<span style="font-size:.7rem">REM</span>' +
      '<span>' + status.remainder + 'mm</span>' +
      '<span>' + (roll.length_mtr||'?') + 'm</span>' +
    '</div>';
  }

  html += '</div></div>';

  el.innerHTML = html;
}

function updateConfig(key, val) {
  if (!activeRollId || !rollConfigs[activeRollId]) return;
  rollConfigs[activeRollId][key] = val;
  renderBatchStatus();
}

function updateRun(idx, key, val) {
  if (!activeRollId || !rollConfigs[activeRollId]) return;
  var runs = rollConfigs[activeRollId].runs;
  if (!runs[idx]) return;
  runs[idx][key] = parseFloat(val) || 0;
  renderBatchStatus();
  renderConfig();
}

function addRun() {
  if (!activeRollId || !rollConfigs[activeRollId]) return;
  var roll = selectedRolls.find(function(r){ return r.roll_no === activeRollId; });
  rollConfigs[activeRollId].runs.push({ width: 0, length: parseFloat(roll ? roll.length_mtr : 0)||0, qty: 1 });
  renderConfig();
}

function removeRun(idx) {
  if (!activeRollId || !rollConfigs[activeRollId]) return;
  rollConfigs[activeRollId].runs.splice(idx, 1);
  if (rollConfigs[activeRollId].runs.length === 0) addRun();
  else { renderBatchStatus(); renderConfig(); }
}

// ═════════════════════════════════════════════════════════
// EXECUTE SLITTING BATCH
// ═════════════════════════════════════════════════════════
async function executeBatch() {
  if (!selectedRolls.length) return;

  // Validate all rolls
  var allValid = true;
  selectedRolls.forEach(function(r) {
    var s = calculateRollStatus(r.roll_no);
    if (!s || !s.valid || s.used <= 0) allValid = false;
  });

  if (!allValid) {
    toast('Some rolls have invalid configurations. Check all patterns.', 'error');
    return;
  }

  if (typeof window.showERPConfirm === 'function') {
    window.showERPConfirm('Execute slitting for ' + selectedRolls.length + ' roll(s)? This will mark parent rolls as Consumed and create child rolls.', executeBatchConfirmed, { title: 'Please Confirm', okLabel: 'Execute', cancelLabel: 'Cancel' });
    return;
  }

  executeBatchConfirmed();

  async function executeBatchConfirmed() {

  var results = [];
  var errors = [];

  for (var i = 0; i < selectedRolls.length; i++) {
    var roll = selectedRolls[i];
    var config = rollConfigs[roll.roll_no];
    if (!config) continue;

    var data = await apiPost('execute_batch', {
      parent_roll_no: roll.roll_no,
      runs: JSON.stringify(config.runs.map(function(r){ return { width: r.width, length: r.length, qty: r.qty, destination: config.jobNo ? 'JOB' : 'STOCK', job_no: config.jobNo, job_name: config.jobName, job_size: config.jobSize }; })),
      remainder_action: config.remainderAction || 'STOCK',
      operator_name: OPERATOR,
      job_no: config.jobNo,
      job_name: config.jobName,
      job_size: config.jobSize,
      planning_id: selectedJob && selectedJob.id ? selectedJob.id : '',
      plan_no: selectedJob && selectedJob.job_no ? selectedJob.job_no : config.jobNo
    });

    if (data.ok) {
      results.push(data);
    } else {
      errors.push(roll.roll_no + ': ' + (data.error || 'Unknown error'));
    }
  }

  if (errors.length) {
    toast('Errors: ' + errors.join('; '), 'error');
  }

  if (results.length) {
    showReport(results);
    // Clear batch
    selectedRolls = [];
    activeRollId = null;
    rollConfigs = {};
    renderLoadedRolls();
    renderBatchStatus();
    renderConfig();
    showExecuteButton();
    loadPlannerJobs(); // Refresh planner
  }
  }
}

// ═════════════════════════════════════════════════════════
// REPORT
// ═════════════════════════════════════════════════════════
function showReport(results) {
  var modal = document.getElementById('reportModal');
  var content = document.getElementById('reportContent');
  modal.style.display = 'flex';

  var html = '';
  results.forEach(function(res) {
    html += '<div style="margin-bottom:24px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">';

    // Header
    html += '<div style="background:#0f172a;color:#fff;padding:14px 16px;display:flex;justify-content:space-between;align-items:center">' +
      '<div><div style="font-size:1rem;font-weight:900;text-transform:uppercase">Production Log: Technical Slitting</div><div style="font-size:.65rem;color:#94a3b8;margin-top:2px">' + new Date().toLocaleDateString() + '</div></div>' +
      '<span style="font-size:.6rem;font-weight:800;background:rgba(59,130,246,.2);color:#60a5fa;padding:3px 10px;border-radius:10px">BATCH: ' + esc(res.batch_no) + '</span>' +
    '</div>';

    // Source Material
    html += '<div style="padding:14px 16px"><div style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:6px">Source Material (Parent)</div>' +
      '<table class="report-table"><thead><tr><th>Roll ID</th><th>Status</th></tr></thead><tbody>' +
      '<tr><td style="font-weight:800;font-family:monospace">' + esc(res.parent) + '</td><td><span class="badge badge-consumed">Consumed</span></td></tr></tbody></table></div>';

    // Output
    html += '<div style="padding:0 16px 14px"><div style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:6px">Slitting Output</div>' +
      '<table class="report-table"><thead><tr><th>Child Roll</th><th>Width (mm)</th><th>Length (m)</th><th>Destination</th></tr></thead><tbody>';

    (res.children || []).forEach(function(ch) {
      var dest = ch.is_remainder ? 'Stock (Remainder)' : (ch.dest === 'JOB' ? 'Job Assign' : 'Stock');
      var destColor = ch.is_remainder ? '#f59e0b' : (ch.dest === 'JOB' ? '#3b82f6' : '#22c55e');
      html += '<tr><td style="font-weight:800;font-family:monospace">' + esc(ch.roll_no) + '</td><td>' + ch.width + '</td><td>' + ch.length + '</td>' +
        '<td><span style="font-size:.7rem;font-weight:700;color:' + destColor + '">' + dest + '</span></td></tr>';
    });

    html += '</tbody></table></div>';

    // Signatures
    html += '<div style="padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;gap:16px;justify-content:center">' +
      '<div class="sig-box">Operator</div><div class="sig-box">QC Inspector</div><div class="sig-box">Store Manager</div>' +
    '</div>';

    html += '</div>';
  });

  content.innerHTML = html;
}

function closeReportModal() {
  document.getElementById('reportModal').style.display = 'none';
}

function printReport() {
  var content = document.getElementById('reportContent').innerHTML;
  var win = window.open('', '_blank');
  win.document.write('<html><head><title>Slitting Report</title><style>body{font-family:Inter,sans-serif;padding:20px;font-size:13px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px 10px;text-align:left}th{background:#f5f5f5;font-size:11px;text-transform:uppercase}.sig-box{border:1px solid #ccc;padding:40px 16px 8px;text-align:center;font-size:11px;min-width:140px;display:inline-block;margin:0 8px}.badge{font-size:11px;font-weight:700}</style></head><body>' + content + '</body></html>');
  win.document.close();
  win.print();
}

// ═════════════════════════════════════════════════════════
// INIT
// ═════════════════════════════════════════════════════════
function init() {
  loadPlannerJobs();

  document.getElementById('plannerSearch').addEventListener('input', function() {
    renderPlannerJobs(this.value);
  });

  document.getElementById('rollSearch').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') searchRoll();
  });
  document.getElementById('btnSearchRoll').addEventListener('click', searchRoll);
  document.getElementById('btnExecuteBatch').addEventListener('click', executeBatch);
}

// Expose to global for onclick handlers
window.AST = {
  selectJob: selectJob,
  analyzeStock: analyzeStock,
  setWaste: setWaste,
  adjustQty: adjustQty,
  setActiveRoll: setActiveRoll,
  removeRoll: removeRoll,
  updateConfig: updateConfig,
  updateRun: updateRun,
  addRun: addRun,
  removeRun: removeRun,
  setPlannerFilter: setPlannerFilter
};

window.closeStockModal = closeStockModal;
window.closeReportModal = closeReportModal;
window.deployToTerminal = deployToTerminal;
window.printReport = printReport;

document.addEventListener('DOMContentLoaded', init);

})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
