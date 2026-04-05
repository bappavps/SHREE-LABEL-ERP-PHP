<?php
// ============================================================
// ERP System — Industrial Slitting Terminal
// Full slitting module: Auto Planner + Manual Terminal + History
// SAFE: Does NOT modify any existing modules or tables.
// ============================================================
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/setup_tables.php';

ensureSlittingTables();

$db   = getDB();
$csrf = generateCSRF();

// Pre-load URL params
$preloadRoll  = trim($_GET['rollNo'] ?? '');
$preloadRolls = trim($_GET['rolls'] ?? '');
$sourceFlow   = trim($_GET['from'] ?? '');
$acceptRequestId = (int)($_GET['request_id'] ?? 0);
$acceptJobId = (int)($_GET['job_id'] ?? 0);
$acceptOldParentRoll = trim($_GET['old_parent_roll'] ?? '');
$acceptOldParentPrevStatus = trim($_GET['old_parent_prev_status'] ?? 'Main');
$acceptPlanningId = (int)($_GET['planning_id'] ?? 0);
$acceptPlanNo = trim($_GET['plan_no'] ?? '');
$isAcceptMode = ($sourceFlow === 'jumbo_accept' || $acceptRequestId > 0 || $acceptJobId > 0);

$pageTitle = 'Industrial Slitting Terminal';
include __DIR__ . '/../../../includes/header.php';
?>

<style>
/* ── Slitting Terminal Styles ─────────────────────────────── */
.slt-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px}
.slt-header h1{margin:0;font-size:1.35rem;font-weight:800;display:flex;align-items:center;gap:10px}
.slt-header h1 i{font-size:1.4rem;color:var(--brand)}
.slt-header p{margin:2px 0 0;color:var(--text-muted);font-size:.82rem}

/* Tabs */
.slt-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border);padding-bottom:0}
.slt-tab{padding:10px 20px;font-size:.85rem;font-weight:600;color:var(--text-muted);cursor:pointer;border:none;background:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s}
.slt-tab:hover{color:var(--text-main)}
.slt-tab.active{color:var(--brand);border-bottom-color:var(--brand)}
.slt-tab-panel{display:none}
.slt-tab-panel.active{display:block}

/* Auto Planner */
.slt-planner{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.slt-plan-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:18px;box-shadow:var(--shadow-sm)}
.slt-plan-card h3{font-size:.88rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.slt-plan-card h3 i{color:var(--brand)}
.slt-job-list{max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
.slt-job-item{padding:10px 12px;border:1px solid var(--border);border-radius:8px;cursor:pointer;transition:all .18s;display:flex;justify-content:space-between;align-items:center}
.slt-job-item:hover{border-color:#86efac;background:#f0fdf4}
.slt-job-item.selected{border-color:var(--brand);background:#f0fdf4;box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.slt-job-item .job-label{font-size:.82rem;font-weight:600}
.slt-job-item .job-meta{font-size:.7rem;color:var(--text-muted)}
.slt-job-detail{font-size:.82rem}
.slt-job-detail .detail-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9}
.slt-job-detail .detail-row:last-child{border:none}
.slt-job-detail .detail-label{color:var(--text-muted);font-weight:500}
.slt-job-detail .detail-value{font-weight:700}

/* Stock Analysis Modal */
.slt-modal-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(15,23,42,.5);backdrop-filter:blur(3px)}
.slt-modal-overlay.open{display:flex;align-items:center;justify-content:center}
.slt-modal{background:#fff;border-radius:16px;width:90%;max-width:900px;max-height:85vh;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.25);display:flex;flex-direction:column}
.slt-modal-head{background:#0f172a;color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center}
.slt-modal-head h3{font-size:.95rem;font-weight:700;display:flex;align-items:center;gap:8px}
.slt-modal-close{background:none;border:none;color:#94a3b8;font-size:1.2rem;cursor:pointer;padding:4px}
.slt-modal-close:hover{color:#fff}
.slt-modal-body{padding:20px 24px;overflow-y:auto;flex:1}
.slt-modal-foot{padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px}

/* Stock option cards */
.slt-stock-options{display:flex;flex-direction:column;gap:10px}
.slt-stock-opt{border:1px solid var(--border);border-radius:10px;padding:14px;display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:center;transition:all .18s}
.slt-stock-opt:hover{border-color:#86efac}
.slt-stock-opt.selected{border-color:var(--brand);background:#f0fdf4}
.slt-stock-opt .opt-roll{font-size:.82rem;font-weight:700}
.slt-stock-opt .opt-dim{font-size:.75rem;color:var(--text-muted)}
.slt-stock-opt .opt-eff{font-size:.82rem;font-weight:700}
.slt-stock-opt .opt-splits{font-size:.82rem;font-weight:600}
.slt-stock-opt .opt-waste{font-size:.75rem;color:#b45309}
.slt-eff-bar{height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;width:80px;display:inline-block;vertical-align:middle;margin-left:6px}
.slt-eff-fill{height:100%;background:var(--brand);border-radius:3px;transition:width .3s}
.slt-qty-input{width:60px;height:30px;border:1px solid var(--border);border-radius:6px;text-align:center;font-weight:700;font-size:.82rem}

/* Status filter tabs */
.slt-status-tab{padding:4px 12px;border-radius:20px;font-size:.65rem;font-weight:800;border:1px solid var(--border);background:#fff;color:#64748b;cursor:pointer;text-transform:uppercase;letter-spacing:.04em;transition:all .15s}
.slt-status-tab:hover{border-color:var(--brand);color:var(--brand)}
.slt-status-tab.active{background:var(--brand);border-color:var(--brand);color:#fff}

/* Stock analysis grid row (7 columns: Sr#, Dimension, Waste, Result, Yield, Eff, Qty) */
.slt-stock-grid{display:grid;grid-template-columns:50px 2fr 1fr 2fr 1.5fr 1fr 1fr;gap:12px;align-items:center;padding:12px 14px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;background:#fff;transition:all .15s}
.slt-stock-grid:hover{border-color:#86efac;background:#f0fdf4}
.slt-stock-eff-bar{width:100%;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-top:4px}
.slt-stock-eff-fill{height:100%;border-radius:3px;transition:width .3s}

/* Waste toggle (STOCK / ADJUST) */
.slt-waste-toggle{display:inline-flex;border-radius:8px;overflow:hidden;border:1px solid var(--border);background:#f1f5f9}
.slt-waste-toggle button{padding:5px 12px;font-size:.62rem;font-weight:900;text-transform:uppercase;border:none;cursor:pointer;background:transparent;color:#64748b;transition:all .15s;letter-spacing:.04em}
.slt-waste-toggle button.stock-active{background:#22c55e;color:#fff;box-shadow:0 2px 8px rgba(34,197,94,.3)}
.slt-waste-toggle button.adjust-active{background:#f97316;color:#fff;box-shadow:0 2px 8px rgba(249,115,22,.3)}

/* Qty stepper */
.slt-qty-stepper{display:inline-flex;align-items:center;gap:4px}
.slt-qty-stepper button{width:26px;height:26px;border-radius:50%;border:1px solid var(--border);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.85rem;color:#374151;transition:all .15s}
.slt-qty-stepper button:hover{background:#f1f5f9;border-color:var(--brand)}
.slt-qty-stepper span{font-weight:900;min-width:24px;text-align:center;font-size:.88rem}

/* Priority badge */
.slt-priority-badge{display:inline-block;font-size:.55rem;font-weight:800;color:#fff;padding:2px 8px;border-radius:10px;text-transform:uppercase}

/* 3-Column Terminal */
.slt-terminal{display:grid;grid-template-columns:280px 1fr 360px;gap:16px;min-height:500px}
.slt-col{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.slt-col-head{background:#f8fafc;padding:12px 16px;border-bottom:1px solid var(--border);font-size:.82rem;font-weight:700;display:flex;align-items:center;gap:8px}
.slt-col-head i{color:var(--brand)}
.slt-col-body{padding:14px;flex:1;overflow-y:auto}

/* Col 1: Load Rolls */
.slt-scan-input{width:100%;height:44px;border:2px solid var(--border);border-radius:10px;padding:0 14px;font-size:.88rem;font-weight:600;font-family:monospace;transition:border-color .2s}
.slt-scan-input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(34,197,94,.1)}
.slt-roll-list{margin-top:12px;display:flex;flex-direction:column;gap:8px}
.slt-roll-card{border:1px solid var(--border);border-radius:8px;padding:10px 12px;position:relative;transition:all .18s;cursor:pointer}
.slt-roll-card:hover{border-color:#86efac}
.slt-roll-card.active{border-color:var(--brand);background:#f0fdf4}
.slt-roll-card .rc-no{font-size:.85rem;font-weight:700;font-family:monospace}
.slt-roll-card .rc-dim{font-size:.72rem;color:var(--text-muted);margin-top:2px}
.slt-roll-card .rc-status{position:absolute;top:8px;right:8px}
.slt-roll-remove{position:absolute;bottom:6px;right:8px;background:none;border:none;color:#dc2626;cursor:pointer;font-size:.78rem;opacity:.5}
.slt-roll-remove:hover{opacity:1}

/* Col 2: Batch Status */
.slt-batch-card{border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px}
.slt-batch-roll{font-size:.88rem;font-weight:700;font-family:monospace;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
.slt-batch-badge{display:inline-flex;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700}
.slt-batch-badge.ready{background:#dcfce7;color:#166534}
.slt-batch-badge.exceeded{background:#fef2f2;color:#991b1b}
.slt-batch-badge.empty{background:#f3f4f6;color:#6b7280}
.slt-batch-info{font-size:.78rem;color:var(--text-muted);display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.slt-batch-info span{display:flex;justify-content:space-between}
.slt-batch-info strong{color:var(--text-main)}
.slt-util-bar{height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;margin-top:6px}
.slt-util-fill{height:100%;border-radius:4px;transition:width .3s}
.slt-util-fill.ok{background:var(--brand)}
.slt-util-fill.warn{background:#f59e0b}
.slt-util-fill.over{background:#ef4444}
.slt-execute-bar{margin-top:16px;padding-top:14px;border-top:1px solid var(--border)}
.slt-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.slt-machine-grid{grid-template-columns:1fr}
.slt-check-card{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;transition:all .18s}
.slt-check-card:hover{border-color:#86efac;background:#f0fdf4}
.slt-check-card.active{border-color:var(--brand);background:#f0fdf4;box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.slt-check-card input{margin-top:2px}
.slt-check-card strong{display:block;font-size:.8rem;color:var(--text-main)}
.slt-check-card span{display:block;font-size:.7rem;color:var(--text-muted);margin-top:2px}
.slt-choice-empty{padding:10px 12px;border:1px dashed var(--border);border-radius:10px;font-size:.76rem;color:var(--text-muted);background:#f8fafc}
.slt-dept-summary{margin-top:8px;padding:10px 12px;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4}
.slt-dept-summary strong{display:block;font-size:.76rem;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.slt-dept-summary div{font-size:.8rem;font-weight:700;color:#14532d}
.slt-dept-summary span{display:block;font-size:.72rem;color:#166534;margin-top:4px}
.slt-dept-summary.is-empty{border-color:#e2e8f0;background:#f8fafc}
.slt-dept-summary.is-empty strong,.slt-dept-summary.is-empty div,.slt-dept-summary.is-empty span{color:#64748b}

/* Col 3: Config */
.slt-config-header{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:.78rem}
.slt-config-header .ch-roll{font-weight:700;font-family:monospace;font-size:.88rem}
.slt-config-header .ch-dim{color:var(--text-muted);margin-top:2px}
.slt-job-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px}
.slt-job-fields .form-group label{font-size:.62rem}
.slt-job-fields .form-control{height:34px;font-size:.8rem;padding:0 8px}

/* Slit runs table */
.slt-runs-table{width:100%;border-collapse:collapse;margin-bottom:12px}
.slt-runs-table th{background:#f8fafc;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);padding:8px;text-align:left;border-bottom:1px solid var(--border);font-weight:700}
.slt-runs-table th:nth-child(1){width:32%}
.slt-runs-table th:nth-child(2){width:28%}
.slt-runs-table th:nth-child(3){width:16%}
.slt-runs-table th:nth-child(4){width:14%}
.slt-runs-table th:nth-child(5){width:10%}
.slt-runs-table td{padding:6px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.slt-runs-table input{width:100%;height:32px;border:1px solid var(--border);border-radius:6px;padding:0 6px;font-size:.82rem;font-weight:600;text-align:center;min-width:48px}
.slt-runs-table input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(34,197,94,.08)}
.slt-runs-table .run-del{background:none;border:none;color:#dc2626;cursor:pointer;font-size:.82rem;opacity:.5;padding:4px}
.slt-runs-table .run-del:hover{opacity:1}
.slt-add-run{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f8fafc;border:1px dashed var(--border);border-radius:8px;cursor:pointer;font-size:.78rem;font-weight:600;color:var(--text-muted);transition:all .15s;border-style:dashed}
.slt-add-run:hover{border-color:var(--brand);color:var(--brand);background:#f0fdf4}

/* Remainder options */
.slt-remainder{margin-top:14px;padding:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px}
.slt-remainder h4{font-size:.75rem;font-weight:700;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.slt-remainder label{display:flex;align-items:center;gap:8px;font-size:.8rem;cursor:pointer;padding:4px 0}
.slt-remainder input[type=radio]{accent-color:var(--brand)}

/* Slit mode toggle */
.slt-mode-btn{padding:7px 16px;border:1.5px solid var(--border);border-radius:8px;background:#f8fafc;font-size:.78rem;font-weight:600;cursor:pointer;color:var(--text-muted);transition:all .18s;display:inline-flex;align-items:center;gap:6px}
.slt-mode-btn:hover{border-color:#86efac;color:var(--brand)}
.slt-mode-btn.active{background:#f0fdf4;border-color:var(--brand);color:var(--brand);box-shadow:0 0 0 3px rgba(34,197,94,.1)}

/* Visual yield preview */
.slt-yield{margin-top:16px}
.slt-yield h4{font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.slt-yield-bar{display:flex;height:40px;border-radius:8px;overflow:hidden;border:1px solid var(--border);background:#f1f5f9}
.slt-yield-part{display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;color:#fff;transition:width .3s;min-width:20px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px}
.slt-yield-waste{background:#e5e7eb;color:#6b7280;font-style:italic}

/* Yield color palette */
.yc-0{background:#22c55e} .yc-1{background:#3b82f6} .yc-2{background:#f59e0b}
.yc-3{background:#8b5cf6} .yc-4{background:#ef4444} .yc-5{background:#06b6d4}
.yc-6{background:#ec4899} .yc-7{background:#14b8a6} .yc-8{background:#f97316}
.yc-9{background:#6366f1}

/* History */
.slt-history-table{width:100%;border-collapse:collapse}
.slt-history-table th{background:#f8fafc;font-size:.67rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);font-weight:700;white-space:nowrap;position:sticky;top:0;z-index:1}
.slt-history-table td{padding:10px 12px;font-size:.82rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.slt-history-table tbody tr:hover{background:#eff6ff}
.slt-view-btn{background:none;border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:.72rem;font-weight:600;cursor:pointer;color:var(--brand);transition:all .15s}
.slt-view-btn:hover{border-color:var(--brand);background:#f0fdf4}

/* Report modal (A4 print) */
.slt-report{max-width:800px}
.slt-report-body{font-size:.82rem}
.slt-report-header{text-align:center;padding-bottom:14px;border-bottom:2px solid #0f172a;margin-bottom:14px}
.slt-report-header h2{font-size:1.1rem;font-weight:800}
.slt-report-header p{color:var(--text-muted);font-size:.78rem}
.slt-report-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.slt-report-grid .rg-item{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f1f5f9;font-size:.78rem}
.slt-report-grid .rg-label{color:var(--text-muted)}
.slt-report-grid .rg-value{font-weight:700}
.slt-report-table{width:100%;border-collapse:collapse;margin-bottom:14px}
.slt-report-table th{background:#f8fafc;font-size:.65rem;text-transform:uppercase;padding:8px;text-align:left;border:1px solid var(--border);font-weight:700}
.slt-report-table td{padding:7px 8px;font-size:.78rem;border:1px solid var(--border)}
.slt-sig-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:30px}
.slt-sig-box{text-align:center;padding-top:40px;border-top:1px solid #374151}
.slt-sig-box p{font-size:.72rem;color:var(--text-muted);margin-top:4px}

/* Empty state */
.slt-empty{text-align:center;padding:40px 20px;color:var(--text-muted)}
.slt-empty i{font-size:2.5rem;color:#d1d5db;display:block;margin-bottom:10px}
.slt-empty p{font-size:.85rem}

/* Slitting Diagram */
.slt-diagram-parent{background:linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%);border:2px solid #94a3b8;border-radius:8px;height:44px;display:flex;align-items:center;justify-content:center;position:relative}
.slt-diagram-label{font-size:.72rem;font-weight:700;color:#475569;display:flex;align-items:center;gap:4px}
.slt-diagram-children{display:flex;gap:2px;min-height:70px}
.slt-diagram-child{border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:6px 2px;min-width:30px;transition:all .2s;overflow:hidden}
.slt-dc-name{font-size:.65rem;font-weight:700;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.slt-dc-dim{font-size:.58rem;color:#64748b;margin-top:2px}

/* Mobile responsive */
@media(max-width:1024px){
  .slt-terminal{grid-template-columns:1fr}
  .slt-planner{grid-template-columns:1fr}
  .slt-stock-opt{grid-template-columns:1fr 1fr;gap:8px}
}
@media(max-width:600px){
  .slt-job-fields{grid-template-columns:1fr}
  .slt-stock-opt{grid-template-columns:1fr}
}

/* Print styles for report */
@media print{
  body *{visibility:hidden}
  #reportModal,#reportModal *{visibility:visible}
  #reportModal{position:absolute;left:0;top:0;width:100%}
  .slt-modal-head,.slt-modal-close,.slt-modal-foot,.no-print{display:none!important}
  .slt-modal{max-width:100%;max-height:none;box-shadow:none}
}
</style>

<main class="page-content">

<!-- Breadcrumb -->
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">/</span>
  <a href="<?= BASE_URL ?>/modules/paper_stock/index.php">Inventory</a>
  <span class="breadcrumb-sep">/</span>
  <span>Slitting Terminal</span>
</div>

<!-- Header -->
<div class="slt-header">
  <div>
    <h1><i class="bi bi-scissors"></i> Industrial Slitting Terminal</h1>
    <p>Precision roll slitting with auto-planning, batch execution &amp; traceability</p>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-secondary" onclick="SLT.refreshAll()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<!-- Tabs -->
<div class="slt-tabs">
  <button class="slt-tab active" data-tab="terminal"><i class="bi bi-cpu"></i> Terminal</button>
  <button class="slt-tab" data-tab="history"><i class="bi bi-clock-history"></i> History</button>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: TERMINAL                                               -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="slt-tab-panel active" id="panelTerminal">

  <!-- Auto Planner Section -->
  <div class="slt-planner">
    <!-- Left: Job List -->
    <div class="slt-plan-card">
      <h3><i class="bi bi-lightning-charge"></i> Auto Planner — Job Queue</h3>
      <div style="margin-bottom:10px">
        <input type="text" id="plannerSearch" class="form-control" placeholder="Search jobs…" style="height:34px;font-size:.82rem">
      </div>
      <!-- Status Filter Tabs -->
      <div id="plannerStatusTabs" style="display:flex;gap:4px;margin-bottom:10px;flex-wrap:wrap"></div>
      <div class="slt-job-list" id="plannerJobList">
        <div class="slt-empty"><i class="bi bi-inbox"></i><p>Loading jobs…</p></div>
      </div>
    </div>

    <!-- Right: Job Details + Analyze -->
    <div class="slt-plan-card">
      <h3><i class="bi bi-info-circle"></i> Selected Job</h3>
      <div id="plannerJobDetail">
        <div class="slt-empty"><i class="bi bi-hand-index"></i><p>Select a job from the list</p></div>
      </div>
      <div id="plannerAnalyzeWrap" style="display:none;margin-top:14px">
        <button class="btn btn-primary btn-full" id="btnAnalyzeStock"><i class="bi bi-search"></i> Analyze Stock Options</button>
      </div>
    </div>
  </div>

  <!-- 3-Column Terminal -->
  <div class="slt-terminal">

    <!-- Col 1: Load Rolls -->
    <div class="slt-col">
      <div class="slt-col-head"><i class="bi bi-upc-scan"></i> Load Rolls</div>
      <div class="slt-col-body">
        <input type="text" id="rollScanInput" class="slt-scan-input" placeholder="Scan or type roll no…" autocomplete="off">
        <div id="rollSuggestions" style="display:none;margin-top:6px;max-height:120px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;background:#fff"></div>
        <div class="slt-roll-list" id="loadedRollsList">
          <div class="slt-empty"><i class="bi bi-box-seam"></i><p>No rolls loaded</p></div>
        </div>
      </div>
    </div>

    <!-- Col 2: Batch Status -->
    <div class="slt-col">
      <div class="slt-col-head"><i class="bi bi-clipboard-data"></i> Batch Status</div>
      <div class="slt-col-body" id="batchStatusBody">
        <div class="slt-empty"><i class="bi bi-bar-chart"></i><p>Load rolls and configure slits to see batch status</p></div>
      </div>
      <div class="slt-execute-bar" id="executeBar" style="display:none;padding:14px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
          <div class="form-group">
            <label>Operator</label>
            <input type="text" id="execOperator" class="form-control" style="height:34px;font-size:.8rem;background:#f8fafc" placeholder="Auto detected from login" readonly>
          </div>
          <div class="form-group" style="grid-column:1 / -1">
            <label>Department</label>
            <div id="execDepartmentChooser" class="slt-choice-grid"></div>
            <div id="execDepartmentIssueHint" class="slt-dept-summary is-empty">
              <strong>Job Issue Department</strong>
              <div>No department selected yet</div>
              <span>Only selected departments will receive auto-issued job cards after execution.</span>
            </div>
          </div>
          <input type="hidden" id="execMachine" value="">
        </div>
        <button class="btn btn-primary btn-full" id="btnExecuteBatch" disabled><i class="bi bi-play-circle"></i> <?= $isAcceptMode ? 'Update Roll' : 'Execute Batch' ?></button>
      </div>
    </div>

    <!-- Col 3: Configuration -->
    <div class="slt-col">
      <div class="slt-col-head"><i class="bi bi-sliders"></i> Configuration</div>
      <div class="slt-col-body" id="configBody">
        <div class="slt-empty"><i class="bi bi-gear"></i><p>Select a roll to configure slitting</p></div>
      </div>
    </div>

  </div><!-- /.slt-terminal -->

</div><!-- /#panelTerminal -->

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TAB: HISTORY                                                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="slt-tab-panel" id="panelHistory">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="bi bi-clock-history"></i> Slitting History</span>
    </div>
    <div class="table-wrap" style="max-height:600px;overflow-y:auto">
      <table class="slt-history-table">
        <thead>
          <tr>
            <th>Batch No</th>
            <th>Date</th>
            <th>Operator</th>
            <th>Machine</th>
            <th>Parent Roll(s)</th>
            <th>Children</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="historyTableBody">
          <tr><td colspan="8" class="table-empty"><i class="bi bi-inbox"></i>Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: Stock Analysis                                       -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="slt-modal-overlay" id="stockModal">
  <div class="slt-modal" style="max-width:1200px">
    <div class="slt-modal-head">
      <h3><i class="bi bi-bar-chart-steps"></i> Stock Decision Support</h3>
      <div style="display:flex;align-items:center;gap:12px">
        <!-- Supplier Filter -->
        <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);padding:5px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.15)">
          <span style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8">Supplier:</span>
          <select id="supplierFilter" style="background:#1e293b;border:1px solid rgba(255,255,255,.2);color:#fff;font-size:.72rem;font-weight:700;min-width:180px;cursor:pointer;outline:none;padding:4px 8px;border-radius:6px;-webkit-appearance:auto;appearance:auto">
            <option value="all">ALL SUPPLIERS</option>
          </select>
        </div>
        <span id="stockModalCount" style="font-size:.65rem;font-weight:700;color:#94a3b8"></span>
        <button class="slt-modal-close" onclick="SLT.closeModal('stockModal')">&times;</button>
      </div>
    </div>
    <div class="slt-modal-body">
      <div id="stockAnalysisContent">
        <div class="slt-empty"><i class="bi bi-hourglass-split"></i><p>Analyzing stock…</p></div>
      </div>
    </div>
    <div class="slt-modal-foot">
      <div id="stockModalSummary" style="font-size:.82rem;color:var(--text-muted)"></div>
      <button class="btn btn-primary" id="btnDeployTerminal" style="display:none"><i class="bi bi-box-arrow-in-right"></i> Deploy Selection to Terminal</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MODAL: Traceability Report                                  -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="slt-modal-overlay" id="reportModal">
  <div class="slt-modal slt-report">
    <div class="slt-modal-head">
      <h3><i class="bi bi-file-earmark-text"></i> Slitting Traceability Report</h3>
      <button class="slt-modal-close" onclick="SLT.closeModal('reportModal')">&times;</button>
    </div>
    <div class="slt-modal-body slt-report-body" id="reportContent">
    </div>
    <div class="slt-modal-foot no-print">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted)">Template</label>
        <select id="reportTemplateSelect" class="form-control" style="min-width:180px;height:36px">
          <option value="executive">Executive</option>
          <option value="compact">Compact</option>
        </select>
      </div>
      <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
    </div>
  </div>
</div>

</main>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// ════════════════════════════════════════════════════════════════
// Industrial Slitting Terminal — Frontend Logic
// ════════════════════════════════════════════════════════════════
const SLT = (() => {
  const API  = '<?= BASE_URL ?>/modules/inventory/slitting/api.php';
  const JOBS_API = '<?= BASE_URL ?>/modules/jobs/api.php';
  const CSRF = '<?= e($csrf) ?>';
  const CURRENT_OPERATOR = '<?= e(trim((string)($_SESSION['user_name'] ?? '')) ?: 'Operator') ?>';
  const SOURCE_FLOW = '<?= e($sourceFlow) ?>';
  const ACCEPT_REQUEST_ID = <?= (int)$acceptRequestId ?>;
  const ACCEPT_JOB_ID = <?= (int)$acceptJobId ?>;
  const ACCEPT_OLD_PARENT_ROLL = '<?= e($acceptOldParentRoll) ?>';
  const ACCEPT_OLD_PARENT_PREV_STATUS = '<?= e($acceptOldParentPrevStatus) ?>';
  const ACCEPT_PLANNING_ID = <?= (int)$acceptPlanningId ?>;
  const ACCEPT_PLAN_NO = '<?= e($acceptPlanNo) ?>';
  const DEFAULT_DEPARTMENTS = <?= json_encode(erp_default_department_selection(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  // ── State ──────────────────────────────────────────────────
  let plannerJobs     = [];
  let selectedJob     = null;

  let loadedRolls     = [];   // [{roll_no, paper_type, width_mm, length_mtr, …}]
  let activeRollNo    = null; // currently selected roll for config
  let rollConfigs     = {};   // rollNo -> { runs: [{width,length,qty}], destination, job_no, job_name, job_size, remainderAction }
  let machines        = [];
  let machineDepartments = [];
  let selectedMachineDepartments = [];
  let departmentSelectionTouched = false;
  let activePlannerDepartmentSeed = [];
  let plannerFilter   = 'all'; // status tab filter
  let allStockOptions = [];    // raw options from API (unfiltered)
  let allStockGroups  = [];    // grouped options across all suppliers
  let selectedSupplier = 'all';
  let selectionMap    = {};    // key -> qty selected
  let wastagePrefs    = {};    // key -> 'STOCK' | 'ADJUST'
  let reportState     = { data: null, template: 'executive' };
  let acceptRequestWidthMm = 0;
  let acceptWidthMismatchWarned = false;

  // ── Init ───────────────────────────────────────────────────
  async function init() {
    try {
      setExecuteButtonLabel();
      if (isAcceptMode()) {
        plannerFilter = 'all';
        const plannerSearch = document.getElementById('plannerSearch');
        if (plannerSearch) {
          plannerSearch.value = '';
          plannerSearch.disabled = true;
        }
        await loadAcceptRequestContext();
      }
      bindTabs();
      bindScanInput();
      loadMachines();
      loadPlannerJobs();
      loadHistory();
      // Keep planner queue current without requiring manual page reload.
      setInterval(loadPlannerJobs, 12000);

      // URL param: ?rollNo=XXXX or ?rolls=A,B,C
      const preload = '<?= e($preloadRoll) ?>';
      const preloadMulti = '<?= e($preloadRolls) ?>';
      if (preloadMulti) {
        const rollList = preloadMulti.split(',').map(s => s.trim()).filter(Boolean);
        let delay = 500;
        rollList.forEach(rn => {
          setTimeout(() => searchAndLoadRoll(rn), delay);
          delay += 400;
        });
      } else if (preload) {
        setTimeout(() => searchAndLoadRoll(preload), 500);
      }
    } catch (err) {
      const msg = 'Slitting init failed: ' + (err && err.message ? err.message : 'Unknown error');
      console.error(msg, err);
      alert(msg);
    }
  }

  // ── Tabs ───────────────────────────────────────────────────
  function bindTabs() {
    document.querySelectorAll('.slt-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.slt-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.slt-tab-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel' + capitalize(tab.dataset.tab)).classList.add('active');
        if (tab.dataset.tab === 'history') loadHistory();
      });
    });
  }

  // ── Scan Input ─────────────────────────────────────────────
  function bindScanInput() {
    const input = document.getElementById('rollScanInput');
    let timer = null;
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        searchAndLoadRoll(input.value.trim());
        input.value = '';
        hideSuggestions();
      }
    });
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        const q = input.value.trim();
        if (q.length >= 2) showSuggestions(q);
        else hideSuggestions();
      }, 300);
    });
  }

  function parseDepartmentList(value) {
    const raw = Array.isArray(value)
      ? value.slice()
      : String(value == null ? '' : value).split(/\s*,\s*|\r\n|\r|\n/);
    const seen = {};
    const out = [];
    raw.forEach(function(item){
      const text = String(item == null ? '' : item).trim();
      if (!text) return;
      const norm = text.toLowerCase();
      if (seen[norm]) return;
      seen[norm] = true;
      out.push(text);
    });
    return out;
  }

  function sameText(a, b) {
    return String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase();
  }

  function getFilteredMachines() {
    if (!selectedMachineDepartments.length) {
      return machines.slice();
    }
    return machines.filter(function(machine){
      const sections = Array.isArray(machine.sections_list) ? machine.sections_list : parseDepartmentList(machine.section || '');
      return sections.some(function(section){
        return selectedMachineDepartments.some(function(selected){ return sameText(selected, section); });
      });
    });
  }

  function syncOperatorFromMachine() {
    const machineField = document.getElementById('execMachine');
    const opField = document.getElementById('execOperator');
    if (!opField || !machineField) return;
    opField.value = (machineField.value || selectedMachineDepartments.length) ? CURRENT_OPERATOR : '';
  }

  function setSelectedMachine(machineName, rerender) {
    const machineField = document.getElementById('execMachine');
    if (!machineField) return;
    machineField.value = String(machineName || '').trim();
    syncOperatorFromMachine();
    if (rerender !== false) renderMachineChooser();
  }

  function setSelectedDepartments(values, preferredMachine) {
    const picked = parseDepartmentList(values);
    selectedMachineDepartments = picked;
    activePlannerDepartmentSeed = picked.slice();
    departmentSelectionTouched = false;
    renderDepartmentChooser();
    renderMachineChooser(preferredMachine || '');
  }

  function renderDepartmentIssueHint() {
    const hint = document.getElementById('execDepartmentIssueHint');
    if (!hint) return;
    const picked = parseDepartmentList(selectedMachineDepartments);
    const title = hint.querySelector('div');
    const note = hint.querySelector('span');
    hint.classList.toggle('is-empty', !picked.length);
    if (title) {
      title.textContent = picked.length ? picked.join(', ') : 'No department selected yet';
    }
    if (note) {
      note.textContent = picked.length
        ? 'After execution, job cards will be issued only to the selected departments below. The machine will be auto-selected from the Machine Master mapping.'
        : 'Select one or more departments below. Final job cards will be generated only for the departments selected here.';
    }
  }

  function renderDepartmentChooser() {
    const wrap = document.getElementById('execDepartmentChooser');
    if (!wrap) return;
    if (!machineDepartments.length) {
      wrap.innerHTML = '<div class="slt-choice-empty">No departments available from Machine Master.</div>';
      renderDepartmentIssueHint();
      return;
    }
    wrap.innerHTML = machineDepartments.map(function(dept, idx){
      const checked = selectedMachineDepartments.some(function(selected){ return sameText(selected, dept); });
      return '<label class="slt-check-card' + (checked ? ' active' : '') + '">' +
        '<input type="checkbox" data-dept-index="' + idx + '" ' + (checked ? 'checked' : '') + '>' +
        '<div><strong>' + esc(dept) + '</strong><span>You can add or uncheck this department before final job card generation</span></div>' +
      '</label>';
    }).join('');

    wrap.querySelectorAll('input[type="checkbox"]').forEach(function(box){
      box.addEventListener('change', function(){
        const dept = machineDepartments[Number(this.getAttribute('data-dept-index') || 0)] || '';
        if (this.checked) {
          if (!selectedMachineDepartments.some(function(item){ return sameText(item, dept); })) {
            selectedMachineDepartments.push(dept);
          }
        } else {
          selectedMachineDepartments = selectedMachineDepartments.filter(function(item){ return !sameText(item, dept); });
        }
        departmentSelectionTouched = true;
        renderDepartmentChooser();
        renderMachineChooser();
      });
    });

    renderDepartmentIssueHint();
  }

  function renderMachineChooser(preferredMachine) {
    const available = getFilteredMachines();
    let currentMachine = String(document.getElementById('execMachine').value || '').trim();
    if (preferredMachine && available.some(function(machine){ return sameText(machine.name, preferredMachine); })) {
      currentMachine = preferredMachine;
    }
    if (currentMachine && !available.some(function(machine){ return sameText(machine.name, currentMachine); })) {
      currentMachine = '';
    }
    if (!currentMachine && available.length) {
      currentMachine = String(available[0].name || '').trim();
    }
    setSelectedMachine(currentMachine, false);
  }

  function applyPlannerMachineSelection() {
    const jobDepartments = selectedJob ? parseDepartmentList(selectedJob.department_route || '') : [];
    const preferredMachine = selectedJob ? String(selectedJob.machine_name || '').trim() : '';

    const plannerSeedChanged = JSON.stringify(jobDepartments) !== JSON.stringify(activePlannerDepartmentSeed);

    if (plannerSeedChanged) {
      activePlannerDepartmentSeed = jobDepartments.slice();
      departmentSelectionTouched = false;
    }

    if (!departmentSelectionTouched) {
      if (jobDepartments.length) {
        selectedMachineDepartments = jobDepartments.slice();
      } else if (!selectedJob) {
        selectedMachineDepartments = [];
      }
    }

    renderDepartmentChooser();

    if (preferredMachine) {
      renderMachineChooser(preferredMachine);
      return;
    }

    if (isAcceptMode()) {
      const available = getFilteredMachines();
      const jumboMachine = available.find(function(machine){
        const blob = String((machine.name || '') + ' ' + (machine.type || '') + ' ' + (machine.section || '')).toLowerCase();
        return blob.includes('jumbo');
      }) || machines.find(function(machine){
        const blob = String((machine.name || '') + ' ' + (machine.type || '') + ' ' + (machine.section || '')).toLowerCase();
        return blob.includes('jumbo');
      });
      renderMachineChooser(jumboMachine && jumboMachine.name ? jumboMachine.name : '');
      return;
    }

    renderMachineChooser();
  }

  async function showSuggestions(q) {
    const data = await apiGet('search_roll', {q});
    const box = document.getElementById('rollSuggestions');
    if (!data.ok) { hideSuggestions(); return; }
    let items = data.roll ? [data.roll] : (data.suggestions || []);
    if (!items.length) { hideSuggestions(); return; }

    box.innerHTML = items.map(r => `
      <div style="padding:8px 12px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between"
           onclick="SLT.pickSuggestion('${esc(r.roll_no)}')">
        <span style="font-weight:700;font-family:monospace">${esc(r.roll_no)}</span>
        <span style="color:var(--text-muted);font-size:.72rem">${esc(r.width_mm)}mm × ${esc(r.length_mtr)}m</span>
      </div>
    `).join('');
    box.style.display = 'block';
  }

  function hideSuggestions() {
    document.getElementById('rollSuggestions').style.display = 'none';
  }

  function pickSuggestion(rollNo) {
    document.getElementById('rollScanInput').value = '';
    hideSuggestions();
    searchAndLoadRoll(rollNo);
  }

  async function searchAndLoadRoll(q) {
    if (!q) return;
    // Prevent duplicate
    if (loadedRolls.find(r => r.roll_no === q)) {
      showToast('Roll already loaded', 'warn');
      return;
    }

    const data = await apiGet('search_roll', {q});
    if (!data.ok) { showToast(data.error || 'Search failed', 'error'); return; }

    if (data.roll) {
      addRollToTerminal(data.roll);
    } else if (data.suggestions && data.suggestions.length === 1) {
      addRollToTerminal(data.suggestions[0]);
    } else if (data.suggestions && data.suggestions.length > 1) {
      showToast('Multiple matches — please select from suggestions', 'warn');
      showSuggestions(q);
    } else {
      showToast('Roll not found: ' + q, 'error');
    }
  }

  function addRollToTerminal(roll) {
    if (loadedRolls.find(r => r.roll_no === roll.roll_no)) return;
    loadedRolls.push(roll);
    const autoPlan = getAutoPlanContext();
    // Default destination: STOCK unless a plan is already selected.
    const defaultDest = autoPlan.job_no ? 'JOB' : 'STOCK';
    rollConfigs[roll.roll_no] = {
      runs: [{width: '', length: parseFloat(roll.length_mtr), qty: 1}],
      slitMode: 'MANUAL', // MANUAL or EQUAL_DIVIDE
      equalPieces: 2,
      destination: defaultDest,
      job_no: autoPlan.job_no,
      job_name: autoPlan.job_name,
      job_size: autoPlan.job_size,
      remainderAction: 'STOCK'
    };
    if (!activeRollNo) activeRollNo = roll.roll_no;
    renderLoadedRolls();
    renderConfig();
    renderBatchStatus();
  }

  function autofillSelectedPlanNo() {
    if (!selectedJob) return;
    applyPlanContextToAllRolls({
      job_no: String(selectedJob.job_no || ''),
      job_name: String(selectedJob.job_name || ''),
      job_size: String(selectedJob.label_length_mm || selectedJob.label_width_mm || ''),
      forceDestinationJob: true,
      overwriteExisting: true,
    });
  }

  function looksLikePlanNo(v) {
    const s = String(v || '').trim().toUpperCase();
    return s.startsWith('PLN/') || s.startsWith('PLN-BAR/');
  }

  function findPlannerJobByPlanNo(planNo) {
    const target = String(planNo || '').trim().toUpperCase();
    if (!target) return null;
    return plannerJobs.find(j => String(j.job_no || '').trim().toUpperCase() === target) || null;
  }

  function getAutoPlanContext() {
    if (selectedJob && String(selectedJob.job_no || '').trim()) {
      return {
        job_no: String(selectedJob.job_no || ''),
        job_name: String(selectedJob.job_name || ''),
        job_size: String(selectedJob.label_length_mm || selectedJob.label_width_mm || ''),
      };
    }
    if (activeRollNo && rollConfigs[activeRollNo] && String(rollConfigs[activeRollNo].job_no || '').trim()) {
      const cfg = rollConfigs[activeRollNo];
      return {
        job_no: String(cfg.job_no || ''),
        job_name: String(cfg.job_name || ''),
        job_size: String(cfg.job_size || ''),
      };
    }
    const firstWithPlan = Object.values(rollConfigs).find(cfg => String(cfg.job_no || '').trim() !== '');
    if (firstWithPlan) {
      return {
        job_no: String(firstWithPlan.job_no || ''),
        job_name: String(firstWithPlan.job_name || ''),
        job_size: String(firstWithPlan.job_size || ''),
      };
    }
    return { job_no: '', job_name: '', job_size: '' };
  }

  function applyPlanContextToAllRolls(ctx) {
    const payload = ctx || {};
    loadedRolls.forEach(roll => {
      const cfg = rollConfigs[roll.roll_no];
      if (!cfg) return;
      if (payload.overwriteExisting || !String(cfg.job_no || '').trim()) cfg.job_no = String(payload.job_no || '');
      if (payload.overwriteExisting || !String(cfg.job_name || '').trim()) cfg.job_name = String(payload.job_name || '');
      if (payload.overwriteExisting || !String(cfg.job_size || '').trim()) cfg.job_size = String(payload.job_size || '');
      if (payload.forceDestinationJob && String(payload.job_no || '').trim()) cfg.destination = 'JOB';
    });
  }

  function removeRoll(rollNo) {
    loadedRolls = loadedRolls.filter(r => r.roll_no !== rollNo);
    delete rollConfigs[rollNo];
    if (activeRollNo === rollNo) {
      activeRollNo = loadedRolls.length ? loadedRolls[0].roll_no : null;
    }
    renderLoadedRolls();
    renderConfig();
    renderBatchStatus();
  }

  function selectRoll(rollNo) {
    activeRollNo = rollNo;
    renderLoadedRolls();
    renderConfig();
  }

  // ── Render: Loaded Rolls (Col 1) ──────────────────────────
  function renderLoadedRolls() {
    const el = document.getElementById('loadedRollsList');
    if (!loadedRolls.length) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-box-seam"></i><p>No rolls loaded</p></div>';
      return;
    }

    // 1. Build a map of roll_no to roll object
    const rollMap = {};
    loadedRolls.forEach(r => {
      rollMap[r.roll_no] = {...r, children: []};
    });

    // 2. Build parent-child relationships using roll_no pattern
    const roots = [];
    loadedRolls.forEach(r => {
      const parts = r.roll_no.split('-');
      if (parts.length === 1) {
        roots.push(rollMap[r.roll_no]);
      } else {
        const parentRollNo = parts.slice(0, -1).join('-');
        if (rollMap[parentRollNo]) {
          rollMap[parentRollNo].children.push(rollMap[r.roll_no]);
        } else {
          // Orphaned child, treat as root fallback
          roots.push(rollMap[r.roll_no]);
        }
      }
    });

    // 3. Recursive render function
    function renderTree(nodes, level) {
      return nodes.sort((a, b) => {
        // Custom sort: parent first, then children in natural order
        // For children: sort by suffix (A, B, C, 1, 2, 3, ...)
        const aParts = a.roll_no.split('-');
        const bParts = b.roll_no.split('-');
        if (aParts.length !== bParts.length) return aParts.length - bParts.length;
        // Compare last part (suffix)
        const aSuf = aParts[aParts.length - 1];
        const bSuf = bParts[bParts.length - 1];
        // Try numeric sort, else alpha
        const aNum = parseInt(aSuf, 10);
        const bNum = parseInt(bSuf, 10);
        if (!isNaN(aNum) && !isNaN(bNum)) return aNum - bNum;
        if (!isNaN(aNum)) return -1;
        if (!isNaN(bNum)) return 1;
        return aSuf.localeCompare(bSuf);
      }).map(r => {
        const isActive = r.roll_no === activeRollNo;
        const cfg = rollConfigs[r.roll_no];
        const childCount = cfg ? cfg.runs.reduce((s, run) => s + (parseFloat(run.width) > 0 ? (parseInt(run.qty) || 1) : 0), 0) : 0;
        // Indentation: use padding-left or tree icons
        let treePrefix = '';
        if (level > 0) {
          treePrefix = '<span style="display:inline-block;width:' + (level*18) + 'px"></span>';
          // Optionally, use tree lines/icons
        }
        return `
          <div class="slt-roll-card${isActive ? ' active' : ''}" onclick="SLT.selectRoll('${esc(r.roll_no)}')" style="margin-left:${level*12}px">
            <div class="rc-no">${treePrefix}${esc(r.roll_no)}</div>
            <div class="rc-dim">${esc(r.paper_type)} · ${r.width_mm}mm × ${r.length_mtr}m</div>
            ${childCount > 0 ? '<div style="font-size:.68rem;margin-top:3px;color:var(--brand);font-weight:600"><i class="bi bi-diagram-3"></i> ' + childCount + ' output roll(s)</div>' : ''}
            <div class="rc-status">${statusBadge(r.status)}</div>
            <button class="slt-roll-remove" onclick="event.stopPropagation();SLT.removeRoll('${esc(r.roll_no)}')"><i class="bi bi-x-circle"></i></button>
          </div>
          ${r.children && r.children.length ? renderTree(r.children, level + 1) : ''}
        `;
      }).join('');
    }

    el.innerHTML = renderTree(roots, 0);
  }

  // ── Render: Configuration (Col 3) ─────────────────────────
  function renderConfig() {
    const el = document.getElementById('configBody');
    if (!activeRollNo) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-gear"></i><p>Select a roll to configure slitting</p></div>';
      return;
    }

    const roll = loadedRolls.find(r => r.roll_no === activeRollNo);
    const cfg  = rollConfigs[activeRollNo];
    if (!roll || !cfg) return;

    const pw = parseFloat(roll.width_mm);
    const pl = parseFloat(roll.length_mtr);
    const plannerPlanNoOptions = plannerJobs
      .map(j => ({
        planNo: String(j.job_no || '').trim(),
        jobName: String(j.job_name || '').trim(),
      }))
      .filter(v => v.planNo !== '');

    let html = '';

    // Roll info header
    html += `<div class="slt-config-header">
      <div class="ch-roll">${esc(roll.roll_no)}</div>
      <div class="ch-dim">${esc(roll.paper_type)} · ${pw}mm × ${pl}m · ${esc(roll.company || '')}</div>
    </div>`;

    // Job fields (3 col)
    html += `<div class="slt-job-fields">
      <div class="form-group">
        <label>Destination</label>
        <select class="form-control" onchange="SLT.updateConfig('${esc(activeRollNo)}','destination',this.value)">
          <option value="STOCK"${cfg.destination==='STOCK'?' selected':''}>Stock</option>
          <option value="JOB"${cfg.destination==='JOB'?' selected':''}>Job Assign</option>
        </select>
      </div>
      <div class="form-group">
        <label>Job No</label>
        <input type="text" class="form-control" list="plannerPlanNoList" placeholder="Type/select PLN/..." value="${esc(cfg.job_no)}" onchange="SLT.updateConfig('${esc(activeRollNo)}','job_no',this.value)">
        <datalist id="plannerPlanNoList">${plannerPlanNoOptions.map(p => '<option value="' + esc(p.planNo) + '" label="' + esc(p.planNo + ' - ' + (p.jobName || 'No Name')) + '"></option>').join('')}</datalist>
      </div>
      <div class="form-group">
        <label>Job Name</label>
        <input type="text" class="form-control" value="${esc(cfg.job_name)}" onchange="SLT.updateConfig('${esc(activeRollNo)}','job_name',this.value)">
      </div>
    </div>`;

    // Slit mode toggle
    html += `<div style="display:flex;gap:8px;margin-bottom:10px">
      <button class="slt-mode-btn${cfg.slitMode==='MANUAL'?' active':''}" onclick="SLT.setSlitMode('${esc(activeRollNo)}','MANUAL')"><i class="bi bi-sliders"></i> Manual Slit</button>
      <button class="slt-mode-btn${cfg.slitMode==='EQUAL_DIVIDE'?' active':''}" onclick="SLT.setSlitMode('${esc(activeRollNo)}','EQUAL_DIVIDE')"><i class="bi bi-distribute-horizontal"></i> Equal Divide</button>
    </div>`;

    if (cfg.slitMode === 'EQUAL_DIVIDE') {
      // ── Equal Divide: pieces-only input ─────────────────────
      const eqWidth = (pw / (parseInt(cfg.equalPieces) || 2)).toFixed(2);
      html += `<div class="slt-equal-divide" style="padding:14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <label style="font-weight:700;font-size:.82rem;white-space:nowrap"><i class="bi bi-grid-3x3-gap"></i> Number of Pieces</label>
          <input type="number" min="2" max="50" value="${cfg.equalPieces}" class="form-control" style="width:80px;text-align:center;font-weight:700;font-size:1rem" onchange="SLT.updateEqualPieces('${esc(activeRollNo)}',this.value)">
          <span style="font-size:.82rem;color:var(--text-muted)">→ <strong style="color:var(--brand)">${eqWidth}mm</strong> each</span>
        </div>
        <div style="margin-top:8px;font-size:.72rem;color:#6b7280"><i class="bi bi-info-circle"></i> Parent width (${pw}mm) ÷ ${cfg.equalPieces} pieces = ${eqWidth}mm per roll. Full length preserved.</div>
      </div>`;
    } else {
      // ── Manual: normal slit runs table ──────────────────────
      // Slit runs table
      html += `<table class="slt-runs-table">
        <thead><tr><th>Width (mm)</th><th>Length (m)</th><th>Qty</th><th>Mode</th><th></th></tr></thead>
        <tbody>`;

      cfg.runs.forEach((run, idx) => {
        const mode = detectModeJS(run.length, pl);
        html += `<tr>
          <td><input type="number" step="0.1" min="0" value="${run.width}" placeholder="Width" onchange="SLT.updateRun('${esc(activeRollNo)}',${idx},'width',this.value)"></td>
          <td><input type="number" step="0.1" min="0" value="${run.length}" placeholder="${pl}" onchange="SLT.updateRun('${esc(activeRollNo)}',${idx},'length',this.value)"></td>
          <td><input type="number" min="1" value="${run.qty}" onchange="SLT.updateRun('${esc(activeRollNo)}',${idx},'qty',this.value)"></td>
          <td><span class="badge ${mode==='WIDTH'?'badge-stock':'badge-slitting'}" style="font-size:.65rem">${mode}</span></td>
          <td><button class="run-del" onclick="SLT.removeRun('${esc(activeRollNo)}',${idx})"><i class="bi bi-trash3"></i></button></td>
        </tr>`;
      });

      html += `</tbody></table>`;
      html += `<button class="slt-add-run" onclick="SLT.addRun('${esc(activeRollNo)}')"><i class="bi bi-plus-circle"></i> Add Slit Run</button>`;

      // Remainder (only for MANUAL mode)
      const {totalUsed, remainder, utilization} = calcRollStatus(roll, cfg);
      const childCount = cfg.runs.reduce((s, r) => s + (parseFloat(r.width) > 0 ? (parseInt(r.qty) || 1) : 0), 0);
      if (remainder > 0.5) {
        // Calculate what the next sequential letter would be for the remainder
        const widthChildCount = cfg.runs.reduce((s, r) => {
          const m = detectModeJS(parseFloat(r.length) || 0, pl);
          return s + (m === 'WIDTH' && parseFloat(r.width) > 0 ? (parseInt(r.qty) || 1) : 0);
        }, 0);
        let remLetter = String.fromCharCode(65 + (widthChildCount % 26));
        const remCyc = Math.floor(widthChildCount / 26);
        if (remCyc > 0) remLetter += remCyc;
        html += `<div class="slt-remainder">
          <h4><i class="bi bi-info-circle"></i> Remainder: ${remainder.toFixed(2)}mm</h4>
          <label><input type="radio" name="rem_${esc(activeRollNo)}" value="STOCK" ${cfg.remainderAction==='STOCK'?'checked':''} onchange="SLT.updateConfig('${esc(activeRollNo)}','remainderAction','STOCK')"> Create as Stock (${esc(activeRollNo)}-${remLetter})</label>
          <label><input type="radio" name="rem_${esc(activeRollNo)}" value="ADJUST" ${cfg.remainderAction==='ADJUST'?'checked':''} onchange="SLT.updateConfig('${esc(activeRollNo)}','remainderAction','ADJUST')"> Adjust — distribute ${remainder.toFixed(2)}mm evenly (+${(childCount > 0 ? (remainder / childCount).toFixed(2) : '0')}mm each)</label>
        </div>`;
      }
    }

    // ── Child roll name preview ─────────────────────────────
    const previewNames = generatePreviewNames(roll.roll_no, cfg, pl);
    if (previewNames.length > 0) {
      html += `<div style="margin-top:14px;padding:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px">
        <h4 style="font-size:.75rem;font-weight:700;color:#1e40af;margin-bottom:8px;display:flex;align-items:center;gap:6px"><i class="bi bi-diagram-3"></i> Output Preview — ${previewNames.length} Roll(s)</h4>
        <div style="display:flex;flex-wrap:wrap;gap:6px">`;
      previewNames.forEach(p => {
        const bgColor = p.isRemainder ? '#fef3c7' : (p.dest === 'JOB' ? '#dcfce7' : '#e0f2fe');
        const borderColor = p.isRemainder ? '#fde68a' : (p.dest === 'JOB' ? '#86efac' : '#93c5fd');
        const icon = p.isRemainder ? 'arrow-return-right' : 'box-seam';
        html += `<div style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:${bgColor};border:1.5px solid ${borderColor};border-radius:8px;font-size:.82rem;font-weight:700;font-family:monospace;white-space:nowrap">
          <i class="bi bi-${icon}" style="font-size:.78rem"></i>
          <span>${esc(p.name)}</span>
          <span style="color:#6b7280;font-weight:500">${p.width}mm</span>
          ${p.isRemainder ? '<span style="color:#92400e;font-size:.7rem;font-weight:600">(Stock)</span>' : ''}
        </div>`;
      });
      html += '</div></div>';
    }

    // Visual slitting diagram
    html += renderSlittingDiagram(roll, cfg);

    el.innerHTML = html;
  }

  // ── Render: Batch Status (Col 2) ──────────────────────────
  function renderBatchStatus() {
    const el = document.getElementById('batchStatusBody');
    const execBar = document.getElementById('executeBar');
    setExecuteButtonLabel();

    if (!loadedRolls.length) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-bar-chart"></i><p>Load rolls and configure slits to see batch status</p></div>';
      execBar.style.display = 'none';
      return;
    }

    let html = '';
    let allValid = true;

    loadedRolls.forEach(roll => {
      const cfg = rollConfigs[roll.roll_no];
      if (!cfg) return;
      const {totalUsed, remainder, utilization} = calcRollStatus(roll, cfg);
      const hasRuns = cfg.slitMode === 'EQUAL_DIVIDE' ? true : cfg.runs.some(r => parseFloat(r.width) > 0);
      const isExceeded = remainder < -0.01;
      const isReady = hasRuns && !isExceeded && totalUsed > 0;
      const childCount = cfg.runs.reduce((s, r) => s + (parseFloat(r.width) > 0 ? (parseInt(r.qty) || 1) : 0), 0);
      const hasRemainder = remainder > 0.5 && cfg.remainderAction === 'STOCK';

      if (!isReady) allValid = false;

      const badgeClass = !hasRuns ? 'empty' : (isExceeded ? 'exceeded' : 'ready');
      const badgeText  = !hasRuns ? 'EMPTY' : (isExceeded ? 'EXCEEDED' : 'READY');
      const barClass   = isExceeded ? 'over' : (utilization > 85 ? 'ok' : 'warn');

      html += `<div class="slt-batch-card">
        <div class="slt-batch-roll">
          <span>${esc(roll.roll_no)}</span>
          <span class="slt-batch-badge ${badgeClass}">${badgeText}</span>
        </div>
        <div class="slt-batch-info">
          <span>Parent Width <strong>${roll.width_mm}mm</strong></span>
          <span>Used <strong>${totalUsed.toFixed(2)}mm</strong></span>
          <span>Remainder <strong style="color:${isExceeded?'#dc2626':'#16a34a'}">${remainder.toFixed(2)}mm</strong></span>
          <span>Destination <strong>${esc(cfg.destination)}</strong></span>
          <span>Output Rolls <strong>${childCount}${hasRemainder ? ' + 1 Stock' : ''}</strong></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:.72rem;">
          <span style="color:var(--text-muted)">Utilization</span>
          <span style="font-weight:700">${utilization.toFixed(1)}%</span>
        </div>
        <div class="slt-util-bar"><div class="slt-util-fill ${barClass}" style="width:${Math.min(100,utilization)}%"></div></div>
      </div>`;
    });

    el.innerHTML = html;
    execBar.style.display = loadedRolls.length ? '' : 'none';
    document.getElementById('btnExecuteBatch').disabled = !allValid;
  }

  // ── Yield Preview ──────────────────────────────────────────
  function renderYieldPreview(roll, cfg) {
    const pw = parseFloat(roll.width_mm);
    if (!pw) return '';
    const {totalUsed, remainder} = calcRollStatus(roll, cfg);

    let parts = [];
    let ci = 0;
    cfg.runs.forEach(run => {
      const w = parseFloat(run.width) || 0;
      const q = parseInt(run.qty) || 1;
      for (let i = 0; i < q; i++) {
        if (w > 0) parts.push({width: w, label: w + 'mm', colorIdx: ci});
      }
      ci++;
    });

    // Only show remainder in bar if STOCK mode (not ADJUST)
    if (remainder > 0.5 && cfg.remainderAction !== 'ADJUST') {
      parts.push({width: remainder, label: remainder.toFixed(1) + ' (Stock)', colorIdx: -1});
    } else if (remainder > 0.5 && cfg.remainderAction === 'ADJUST' && parts.length > 0) {
      // For ADJUST mode, expand each part to show distributed extra
      const addEach = remainder / parts.length;
      parts = parts.map(p => ({...p, width: p.width + addEach, label: (p.width + addEach).toFixed(1) + 'mm'}));
    }

    let html = '<div class="slt-yield"><h4>Visual Yield Preview</h4><div class="slt-yield-bar">';
    parts.forEach(p => {
      const pct = (p.width / pw * 100).toFixed(1);
      const cls = p.colorIdx < 0 ? 'slt-yield-waste' : 'yc-' + (p.colorIdx % 10);
      html += `<div class="slt-yield-part ${cls}" style="width:${pct}%" title="${p.label}">${p.label}</div>`;
    });
    html += '</div></div>';
    return html;
  }

  // ── Generate preview child names ───────────────────────────
  function generatePreviewNames(rollNo, cfg, parentLength) {
    const names = [];
    let widthIdx = 0;
    let lengthIdx = 0;

    cfg.runs.forEach(run => {
      const w = parseFloat(run.width) || 0;
      const l = parseFloat(run.length) || 0;
      const q = parseInt(run.qty) || 1;
      if (w <= 0) return;

      const mode = detectModeJS(l, parentLength);
      for (let i = 0; i < q; i++) {
        let suffix;
        if (mode === 'LENGTH') {
          suffix = String(lengthIdx + 1);
          lengthIdx++;
        } else {
          suffix = String.fromCharCode(65 + (widthIdx % 26));
          const cycle = Math.floor(widthIdx / 26);
          if (cycle > 0) suffix += cycle;
          widthIdx++;
        }
        const childName = rollNo + '-' + suffix;
        const adjW = (cfg.remainderAction === 'ADJUST') ? '~adj' : w;
        names.push({name: childName, width: w, mode: mode, dest: cfg.destination, isRemainder: false});
      }
    });

    // Remainder roll preview — uses next sequential letter (e.g., D after A,B,C)
    let totalUsed = 0;
    cfg.runs.forEach(r => { totalUsed += (parseFloat(r.width) || 0) * (parseInt(r.qty) || 1); });
    const roll = loadedRolls.find(r => r.roll_no === rollNo);
    const pw = roll ? parseFloat(roll.width_mm) : 0;
    const rem = pw - totalUsed;
    if (rem > 0.5 && cfg.remainderAction === 'STOCK') {
      let remSuffix = String.fromCharCode(65 + (widthIdx % 26));
      const remCycle = Math.floor(widthIdx / 26);
      if (remCycle > 0) remSuffix += remCycle;
      names.push({name: rollNo + '-' + remSuffix, width: rem.toFixed(2), mode: 'WIDTH', dest: 'STOCK', isRemainder: true});
    }

    return names;
  }

  // ── Visual Slitting Diagram ────────────────────────────────
  function renderSlittingDiagram(roll, cfg) {
    const pw = parseFloat(roll.width_mm);
    if (!pw) return '';
    const {totalUsed, remainder} = calcRollStatus(roll, cfg);
    const names = generatePreviewNames(roll.roll_no, cfg, parseFloat(roll.length_mtr));
    if (!names.length) return '';

    let html = '<div class="slt-diagram" style="margin-top:16px">';
    html += '<h4 style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px"><i class="bi bi-scissors"></i> Slitting Diagram</h4>';

    // Parent roll bar
    html += '<div style="position:relative;margin-bottom:6px">';
    html += '<div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--text-muted);margin-bottom:2px"><span>0mm</span><span>' + pw + 'mm</span></div>';
    html += '<div class="slt-diagram-parent">';
    html += '<div class="slt-diagram-label"><i class="bi bi-arrow-left-right"></i> ' + esc(roll.roll_no) + ' (' + pw + 'mm)</div>';
    html += '</div>';
    html += '</div>';

    // Arrow
    html += '<div style="text-align:center;color:var(--brand);font-size:1.1rem;margin:4px 0"><i class="bi bi-chevron-double-down"></i></div>';

    // Child rolls bar
    html += '<div class="slt-diagram-children">';
    let ci = 0;
    names.forEach((n, idx) => {
      const w = parseFloat(n.width) || 0;
      const pct = pw > 0 ? (w / pw * 100) : 0;
      let bg, border, txtColor;
      if (n.isRemainder) {
        bg = '#fef3c7'; border = '#fde68a'; txtColor = '#92400e';
      } else if (n.dest === 'JOB') {
        bg = ['#dcfce7','#dbeafe','#fef3c7','#f3e8ff','#ffe4e6','#cffafe'][ci % 6];
        border = ['#86efac','#93c5fd','#fde68a','#c4b5fd','#fda4af','#67e8f9'][ci % 6];
        txtColor = '#1e293b';
        ci++;
      } else {
        bg = ['#e0f2fe','#dcfce7','#fef3c7','#f3e8ff','#ffe4e6','#cffafe'][ci % 6];
        border = ['#93c5fd','#86efac','#fde68a','#c4b5fd','#fda4af','#67e8f9'][ci % 6];
        txtColor = '#1e293b';
        ci++;
      }
      html += `<div class="slt-diagram-child" style="width:${pct.toFixed(1)}%;background:${bg};border:1.5px solid ${border};color:${txtColor}">
        <div class="slt-dc-name">${esc(n.name)}</div>
        <div class="slt-dc-dim">${w}mm</div>
      </div>`;
    });

    // Waste sliver if ADJUST mode (no remainder shown)
    if (remainder > 0.5 && cfg.remainderAction === 'ADJUST') {
      html += '<div class="slt-diagram-child" style="width:1%;background:#e5e7eb;border:1px dashed #9ca3af;min-width:0;overflow:hidden" title="Distributed"></div>';
    }
    html += '</div>';
    html += '</div>';
    return html;
  }

  // ── Calculation helpers ────────────────────────────────────
  function calcRollStatus(roll, cfg) {
    const pw = parseFloat(roll.width_mm) || 0;
    let totalUsed = 0;
    cfg.runs.forEach(r => {
      totalUsed += (parseFloat(r.width) || 0) * (parseInt(r.qty) || 1);
    });
    const remainder = pw - totalUsed;
    const utilization = pw > 0 ? (totalUsed / pw * 100) : 0;
    return {totalUsed, remainder, utilization};
  }

  function detectModeJS(slitLength, parentLength) {
    const sl = parseFloat(slitLength) || 0;
    const pl = parseFloat(parentLength) || 0;
    if (!sl || !pl) return 'WIDTH';
    if (sl < pl) return 'LENGTH';
    return 'WIDTH';
  }

  // ── Config update handlers ─────────────────────────────────
  function updateConfig(rollNo, key, value) {
    if (rollConfigs[rollNo]) {
      if (key === 'job_no') {
        const planNo = String(value || '').trim();
        rollConfigs[rollNo][key] = planNo;
        if (planNo === '') {
          selectedJob = null;
          applyPlanContextToAllRolls({
            job_no: '',
            job_name: '',
            job_size: '',
            overwriteExisting: true,
          });
          loadedRolls.forEach(r => {
            if (rollConfigs[r.roll_no]) {
              rollConfigs[r.roll_no].destination = 'STOCK';
            }
          });
          renderPlannerJobs(document.getElementById('plannerSearch').value);
          renderJobDetail();
        } else if (looksLikePlanNo(planNo)) {
          const matched = findPlannerJobByPlanNo(planNo);
          if (matched) {
            selectedJob = matched;
            applyPlanContextToAllRolls({
              job_no: String(matched.job_no || planNo),
              job_name: String(matched.job_name || ''),
              job_size: String(matched.label_length_mm || matched.label_width_mm || ''),
              forceDestinationJob: true,
              overwriteExisting: true,
            });
            renderPlannerJobs(document.getElementById('plannerSearch').value);
            renderJobDetail();
          } else {
            // Unknown PLN should not allow JOB destination.
            applyPlanContextToAllRolls({
              job_no: '',
              job_name: '',
              job_size: '',
              overwriteExisting: true,
            });
            loadedRolls.forEach(r => {
              if (rollConfigs[r.roll_no]) {
                rollConfigs[r.roll_no].destination = 'STOCK';
              }
            });
            showToast('Plan not found in pending planning list', 'warning');
          }
        } else {
          // Non-PLN value should keep destination as stock.
          loadedRolls.forEach(r => {
            if (rollConfigs[r.roll_no]) {
              rollConfigs[r.roll_no].destination = 'STOCK';
            }
          });
        }
      } else {
        rollConfigs[rollNo][key] = value;
        if (key === 'destination') {
          const currentPlanNo = String(rollConfigs[rollNo].job_no || '').trim();
          if (!looksLikePlanNo(currentPlanNo)) {
            rollConfigs[rollNo].destination = 'STOCK';
            showToast('Destination stays Stock until a valid PLN is selected', 'warning');
          }
        }
      }
      renderConfig();
      renderBatchStatus();
      renderLoadedRolls();
    }
  }

  function updateRun(rollNo, idx, key, value) {
    if (rollConfigs[rollNo] && rollConfigs[rollNo].runs[idx]) {
      rollConfigs[rollNo].runs[idx][key] = (key === 'qty') ? parseInt(value) || 1 : parseFloat(value) || 0;
      renderConfig();
      renderBatchStatus();
    }
  }

  function addRun(rollNo) {
    const roll = loadedRolls.find(r => r.roll_no === rollNo);
    if (!rollConfigs[rollNo]) return;
    rollConfigs[rollNo].runs.push({width: '', length: parseFloat(roll?.length_mtr || 0), qty: 1});
    renderConfig();
  }

  function removeRun(rollNo, idx) {
    if (!rollConfigs[rollNo]) return;
    rollConfigs[rollNo].runs.splice(idx, 1);
    if (rollConfigs[rollNo].runs.length === 0) {
      const roll = loadedRolls.find(r => r.roll_no === rollNo);
      rollConfigs[rollNo].runs.push({width: '', length: parseFloat(roll?.length_mtr || 0), qty: 1});
    }
    renderConfig();
    renderBatchStatus();
  }

  function setSlitMode(rollNo, mode) {
    if (!rollConfigs[rollNo]) return;
    rollConfigs[rollNo].slitMode = mode;
    if (mode === 'EQUAL_DIVIDE') {
      // Auto-generate runs from equal divide
      syncEqualDivideRuns(rollNo);
    }
    renderConfig();
    renderBatchStatus();
    renderLoadedRolls();
  }

  function updateEqualPieces(rollNo, val) {
    if (!rollConfigs[rollNo]) return;
    rollConfigs[rollNo].equalPieces = Math.max(2, parseInt(val) || 2);
    syncEqualDivideRuns(rollNo);
    renderConfig();
    renderBatchStatus();
  }

  function syncEqualDivideRuns(rollNo) {
    const cfg = rollConfigs[rollNo];
    const roll = loadedRolls.find(r => r.roll_no === rollNo);
    if (!cfg || !roll) return;
    const pw = parseFloat(roll.width_mm) || 0;
    const pl = parseFloat(roll.length_mtr) || 0;
    const pieces = parseInt(cfg.equalPieces) || 2;
    const eqWidth = parseFloat((pw / pieces).toFixed(2));
    cfg.runs = [{width: eqWidth, length: pl, qty: pieces}];
    cfg.remainderAction = 'ADJUST'; // no remainder in equal divide
  }

  function isAcceptMode() {
    if (SOURCE_FLOW === 'jumbo_accept') return true;
    if (ACCEPT_REQUEST_ID > 0) return true;
    if (ACCEPT_JOB_ID > 0) return true;
    return false;
  }

  function getExecuteButtonLabel() {
    return isAcceptMode() ? 'Update Roll' : 'Execute Batch';
  }

  function setExecuteButtonLabel() {
    const btn = document.getElementById('btnExecuteBatch');
    if (!btn) return;
    btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
  }

  function getAcceptScopedJobs(list) {
    const src = Array.isArray(list) ? list : [];
    if (!isAcceptMode()) return src;

    let scoped = src;
    if (ACCEPT_PLANNING_ID > 0) {
      scoped = scoped.filter(j => Number(j.id || 0) === ACCEPT_PLANNING_ID);
    }
    if (!scoped.length && String(ACCEPT_PLAN_NO || '').trim()) {
      const planNo = String(ACCEPT_PLAN_NO).trim().toUpperCase();
      scoped = src.filter(j => String(j.job_no || '').trim().toUpperCase() === planNo);
    }
    return scoped;
  }

  function detectWidthFromRequestPayload(payload) {
    const p = payload || {};
    const rows = Array.isArray(p.rows) ? p.rows : [];
    const childRows = rows.filter(r => String((r && r.bucket) || '').toLowerCase() !== 'stock');
    const src = childRows.length ? childRows : rows;
    for (const r of src) {
      const w = parseFloat((r && r.width) || 0);
      if (w > 0) return w;
    }
    return 0;
  }

  async function loadAcceptRequestContext() {
    if (!isAcceptMode() || ACCEPT_JOB_ID <= 0 || ACCEPT_REQUEST_ID <= 0) return;
    const data = await apiGet('list_jumbo_change_requests', {
      status: 'all',
      job_id: ACCEPT_JOB_ID,
      limit: 100,
    });
    if (!data.ok || !Array.isArray(data.requests)) {
      showToast('Could not load request context for width auto-detect', 'warning');
      return;
    }
    const req = data.requests.find(r => Number(r.id || 0) === ACCEPT_REQUEST_ID);
    if (!req) {
      showToast('Accepted request not found for width auto-detect', 'warning');
      return;
    }
    const w = detectWidthFromRequestPayload(req.payload || {});
    if (w > 0) {
      acceptRequestWidthMm = w;
      return;
    }
    showToast('Width not found in old request/job card. Please verify manually.', 'error');
  }

  // ── Auto Planner ───────────────────────────────────────────
  async function loadPlannerJobs() {
    const planningParams = {};
    if (isAcceptMode()) {
      if (ACCEPT_PLANNING_ID > 0) {
        planningParams.planning_id = ACCEPT_PLANNING_ID;
      }
      if (String(ACCEPT_PLAN_NO || '').trim()) {
        planningParams.plan_no = String(ACCEPT_PLAN_NO || '').trim();
      }
    }
    const data = await apiGet('get_planning_jobs', Object.keys(planningParams).length ? planningParams : null);
    if (!data.ok) {
      plannerJobs = [];
      document.getElementById('plannerJobList').innerHTML = '<div class="slt-empty"><i class="bi bi-exclamation-triangle"></i><p>Unable to load planning jobs</p></div>';
      showToast(data.error || 'Unable to load planning jobs', 'error');
      return;
    }
    plannerJobs = data.jobs || [];
    if (isAcceptMode()) {
      const scoped = getAcceptScopedJobs(plannerJobs);
      if (scoped.length) {
        const selectedStillValid = selectedJob && scoped.some(j => Number(j.id || 0) === Number(selectedJob.id || 0));
        if (!selectedStillValid) {
          selectedJob = scoped[0];
          autofillSelectedPlanNo();
        }
      } else {
        selectedJob = null;
      }
    }
    renderPlannerTabs();
    applyPlannerMachineSelection();
    renderPlannerJobs();
    renderJobDetail();
    renderConfig();
    renderBatchStatus();
  }

  function renderPlannerTabs() {
    const el = document.getElementById('plannerStatusTabs');
    const jobsForTabs = getAcceptScopedJobs(plannerJobs);
    const counts = { all: jobsForTabs.length };
    jobsForTabs.forEach(j => {
      const pp = normalizePlannerStatus(j.printing_planning || j.status || 'Pending');
      counts[pp] = (counts[pp] || 0) + 1;
    });
    const tabs = [
      { key: 'all', label: 'All', count: counts.all },
      { key: 'pending', label: 'Pending', count: counts['pending'] || 0 },
      { key: 'barcode ready', label: 'Barcode Ready', count: counts['barcode ready'] || 0 },
      { key: 'running', label: 'Running', count: (counts['running']||0) + (counts['in progress']||0) },
      { key: 'completed', label: 'Completed', count: counts['completed'] || 0 },
    ];
    el.innerHTML = tabs.map(t =>
      `<button class="slt-status-tab${plannerFilter===t.key?' active':''}" onclick="SLT.setPlannerFilter('${t.key}')">${t.label} <span style="opacity:.6">(${t.count})</span></button>`
    ).join('');
  }

  function setPlannerFilter(f) {
    plannerFilter = isAcceptMode() ? 'all' : f;
    renderPlannerTabs();
    renderPlannerJobs(document.getElementById('plannerSearch').value);
  }

  function renderPlannerJobs(filter) {
    const el = document.getElementById('plannerJobList');
    let jobs = getAcceptScopedJobs(plannerJobs);
    const effectiveFilter = isAcceptMode() ? '' : (filter || '');
    if (effectiveFilter) {
      const f = effectiveFilter.toLowerCase();
      jobs = jobs.filter(j => (j.job_name || '').toLowerCase().includes(f) || (j.material_type || '').toLowerCase().includes(f));
    }

    // Apply status tab filter
    if (!isAcceptMode() && plannerFilter !== 'all') {
      jobs = jobs.filter(j => {
        const s = normalizePlannerStatus(j.printing_planning || j.status || '');
        if (plannerFilter === 'running') return s === 'running' || s === 'in progress';
        return s === plannerFilter;
      });
    }

    if (!jobs.length) {
      if (isAcceptMode()) {
        el.innerHTML = '<div class="slt-empty"><i class="bi bi-exclamation-triangle"></i><p>No planner job found for this accepted request.</p></div>';
      } else {
        el.innerHTML = '<div class="slt-empty"><i class="bi bi-inbox"></i><p>No jobs found for this filter</p></div>';
      }
      return;
    }

    el.innerHTML = jobs.map(j => {
      const sel = selectedJob && selectedJob.id === j.id;
      const ppStatus = j.printing_planning || j.status || 'Pending';
      return `<div class="slt-job-item${sel?' selected':''}" onclick="SLT.selectJob(${j.id})">
        <div>
          <div class="job-label">${esc(j.job_no || '—')} - ${esc(j.job_name || 'No Name')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:6px">
          ${statusBadge(ppStatus)}
        </div>
      </div>`;
    }).join('');

    // Search binding
    document.getElementById('plannerSearch').oninput = function() {
      renderPlannerJobs(this.value);
    };
  }

  function selectJob(id) {
    selectedJob = plannerJobs.find(j => j.id == id) || null;
    if (!selectedJob) {
      showToast('Selected planning job was not found', 'error');
      return;
    }
    autofillSelectedPlanNo();
    applyPlannerMachineSelection();
    renderPlannerJobs();
    renderJobDetail();
    renderConfig();
    renderBatchStatus();
  }

  function renderJobDetail() {
    const el = document.getElementById('plannerJobDetail');
    const wrap = document.getElementById('plannerAnalyzeWrap');

    if (!selectedJob) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-hand-index"></i><p>Select a job from the list</p></div>';
      wrap.style.display = 'none';
      return;
    }

    const j = selectedJob;
    el.innerHTML = `<div class="slt-job-detail">
      <div class="detail-row"><span class="detail-label">Plan No</span><span class="detail-value">${esc(j.job_no || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Job Name</span><span class="detail-value">${esc(j.job_name)}</span></div>
      <div class="detail-row"><span class="detail-label">Material</span><span class="detail-value">${esc(j.material_type || 'N/A')}</span></div>
      <div class="detail-row"><span class="detail-label">Paper Size / Width</span><span class="detail-value">${esc(j.label_width_mm || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Size</span><span class="detail-value">${esc(j.label_length_mm || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Allocated MTRS</span><span class="detail-value">${esc(j.allocate_mtrs || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Quantity</span><span class="detail-value">${esc(j.quantity || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Die</span><span class="detail-value">${esc(j.die_type || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Core</span><span class="detail-value">${esc(j.core_size || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Direction</span><span class="detail-value">${esc(j.roll_direction || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Repeat</span><span class="detail-value">${esc(j.repeat_val || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Dispatch Date</span><span class="detail-value">${esc(j.dispatch_date || '—')}</span></div>
      <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value">${statusBadge(j.priority || 'Normal')}</span></div>
      <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${statusBadge(j.status)}</span></div>
    </div>`;

    wrap.style.display = '';
    document.getElementById('btnAnalyzeStock').onclick = () => analyzeStock();
  }

  async function analyzeStock() {
    if (!selectedJob) return;
    openModal('stockModal');

    const j = selectedJob;
    const contentEl = document.getElementById('stockAnalysisContent');
    contentEl.innerHTML = '<div class="slt-empty"><i class="bi bi-hourglass-split"></i><p>Analyzing stock options…</p></div>';

    const planningWidth = parseFloat(j.label_width_mm) || parseFloat(j.paper_size) || 0;
    const requestWidth = isAcceptMode() ? (parseFloat(acceptRequestWidthMm) || 0) : 0;
    const targetWidth = requestWidth > 0 ? requestWidth : planningWidth;
    const material = j.material_type || '';
    const reqMtrs = parseFloat(j.allocate_mtrs) || 0;

    if (isAcceptMode() && requestWidth > 0 && planningWidth > 0 && Math.abs(requestWidth - planningWidth) > 0.5 && !acceptWidthMismatchWarned) {
      acceptWidthMismatchWarned = true;
      showToast('Width mismatch: request card width ' + requestWidth + 'mm, planning width ' + planningWidth + 'mm. Using request width.', 'warning');
    }

    if (!material || !targetWidth) {
      contentEl.innerHTML = '<div class="slt-empty"><i class="bi bi-exclamation-triangle" style="color:#f59e0b"></i><p>Paper width is missing in planning/request context, so stock analysis cannot run.</p></div>';
      document.getElementById('btnDeployTerminal').style.display = 'none';
      document.getElementById('stockModalSummary').innerHTML = 'Set width in planning or ensure accepted request contains roll row widths, then try again.';
      return;
    }

    const data = await apiGet('search_rolls_by_material', {
      paper_type: material,
      target_width: targetWidth,
      target_length: 0,
    });

    if (!data.ok || !data.options || !data.options.length) {
      contentEl.innerHTML = `<div class="slt-empty"><i class="bi bi-exclamation-triangle" style="color:#f59e0b"></i><p>No suitable stock for <strong>${esc(material)}</strong> at width ≥ ${targetWidth}mm</p></div>`;
      document.getElementById('btnDeployTerminal').style.display = 'none';
      document.getElementById('stockModalSummary').innerHTML = '';
      return;
    }

    allStockOptions = data.options;
    allStockGroups = [];
    selectionMap = {};
    wastagePrefs = {};
    selectedSupplier = 'all';

    // Populate supplier dropdown
    const supplierSelect = document.getElementById('supplierFilter');
    const uniqueSuppliers = [];
    data.options.forEach(opt => {
      const c = (opt.roll.company || '').trim();
      if (c && c !== 'Unknown' && !uniqueSuppliers.includes(c)) uniqueSuppliers.push(c);
    });
    uniqueSuppliers.sort();
    supplierSelect.innerHTML = '<option value="all" style="background:#1e293b;color:#fff">ALL SUPPLIERS (' + uniqueSuppliers.length + ')</option>';
    uniqueSuppliers.forEach(s => {
      supplierSelect.innerHTML += `<option value="${esc(s)}" style="background:#1e293b;color:#fff">${esc(s)}</option>`;
    });
    supplierSelect.value = 'all';
    supplierSelect.disabled = false;
    supplierSelect.onchange = function() {
      selectedSupplier = this.value;
      renderStockOptions(targetWidth, reqMtrs);
    };

    // Store context on element for rendering/deploy
    contentEl._targetWidth = targetWidth;
    contentEl._reqMtrs = reqMtrs;

    renderStockOptions(targetWidth, reqMtrs);
  }

  function buildStockGroups(options) {
    const grouped = {};
    (options || []).forEach(opt => {
      const r = opt.roll || {};
      const key = r.width_mm + 'x' + r.length_mtr + '-' + (r.company || '');
      if (!grouped[key]) {
        grouped[key] = {
          roll: r,
          splits: opt.splits,
          waste_mm: opt.waste_mm,
          efficiency: opt.efficiency,
          possible_ways: opt.possible_ways || [],
          rolls: [],
          key
        };
        if (selectionMap[key] === undefined) selectionMap[key] = 0;
        if (!wastagePrefs[key]) wastagePrefs[key] = 'STOCK';
      }
      grouped[key].rolls.push(r);
    });

    const groups = Object.values(grouped);
    groups.sort((a, b) => parseFloat(a.roll.width_mm) - parseFloat(b.roll.width_mm) || a.waste_mm - b.waste_mm);
    return groups;
  }

  function renderStockOptions(targetWidth, reqMtrs) {
    const contentEl = document.getElementById('stockAnalysisContent');
    allStockGroups = buildStockGroups(allStockOptions);

    // Filter by supplier
    let filtered = allStockOptions;
    if (selectedSupplier !== 'all') {
      filtered = allStockOptions.filter(opt => (opt.roll.company || '').trim() === selectedSupplier);
    }

    if (!filtered.length) {
      contentEl.innerHTML = `<div class="slt-empty"><i class="bi bi-funnel" style="color:#64748b"></i><p>No stock from <strong>${esc(selectedSupplier)}</strong> matches this requirement</p></div>`;
      contentEl._groups = [];
      updateProductionSummary();
      return;
    }

    const groups = buildStockGroups(filtered);

    document.getElementById('stockModalCount').textContent = 'Showing: ' + filtered.length + ' of ' + allStockOptions.length + ' rolls';

    // Render grid header (7 columns now — added Sr#)
    let html = `<div class="slt-stock-grid" style="grid-template-columns:50px 2fr 1fr 2fr 1.5fr 1fr 1fr;font-size:.55rem;font-weight:800;text-transform:uppercase;color:var(--text-muted);letter-spacing:.06em;border:none;padding:0 14px 8px">
      <span>Sr#</span><span>Dimension & Priority</span><span>Wastage Control</span><span>Slitting Result</span><span>Yield Details</span><span>Efficiency</span><span>Batch Qty</span>
    </div>`;

    groups.forEach((g, gIdx) => {
      const r = g.roll;
      const rollSrNo = r.id || '—';
      const effColor = g.efficiency >= 90 ? '#16a34a' : (g.efficiency >= 70 ? '#d97706' : '#dc2626');
      const priority = g.waste_mm === 0 ? 'Best Fit' : (r.width_mm >= 1000 ? 'Jumbo' : 'Alternate');
      const priBg = g.waste_mm === 0 ? '#22c55e' : (r.width_mm >= 1000 ? '#f59e0b' : '#3b82f6');
      const yieldPerRoll = (parseFloat(r.length_mtr) || 0) * g.splits;
      const stockResult = targetWidth + 'mm × ' + g.splits + ' splits' + (g.waste_mm > 0 ? ' + ' + Math.round(g.waste_mm) + 'mm (Stock)' : '');
      const adjustResult = Math.round(parseFloat(r.width_mm) / g.splits) + 'mm × ' + g.splits + ' parts (Adjusted)';
      const pref = wastagePrefs[g.key] || 'STOCK';
      const ways = (g.possible_ways || []).map(function(w){
        return '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:.58rem;font-weight:800">' +
          esc(w.stock_width) + '×' + esc(w.count) + (w.stock_waste_mm > 0 ? ' +' + esc(Math.round(w.stock_waste_mm)) : '') +
          '</span>';
      }).join('');

      html += `<div class="slt-stock-grid" style="grid-template-columns:50px 2fr 1fr 2fr 1.5fr 1fr 1fr" data-key="${esc(g.key)}">
        <div style="display:flex;align-items:center;justify-content:center">
          <span style="font-weight:900;font-size:.9rem;color:var(--brand);background:rgba(34,197,94,.08);width:36px;height:36px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid rgba(34,197,94,.15)">${rollSrNo}</span>
        </div>
        <div>
          <div style="font-weight:900;font-size:.8rem">${r.width_mm}mm × ${r.length_mtr}m</div>
          <div style="font-size:.6rem;color:var(--text-muted);margin-top:2px">${esc(r.roll_no)} · ${esc(r.company || '')}</div>
          <span class="slt-priority-badge" style="background:${priBg}">${priority}</span>
          <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:8px">
            ${ways || '<span style="font-size:.58rem;color:var(--text-muted)">Single feasible way</span>'}
          </div>
        </div>
        <div>
          <div style="font-size:.65rem;color:var(--text-muted);font-weight:700;margin-bottom:4px">Waste: ${Math.round(g.waste_mm)}mm</div>
          <div class="slt-waste-toggle">
            <button class="${pref==='STOCK'?'stock-active':''}" onclick="SLT.setWaste('${esc(g.key)}','STOCK')">Stock</button>
            <button class="${pref==='ADJUST'?'adjust-active':''}" onclick="SLT.setWaste('${esc(g.key)}','ADJUST')">Adjust</button>
          </div>
        </div>
        <div>
          <div style="font-size:.72rem;font-weight:700" id="resultText_${esc(g.key)}">${pref === 'STOCK' ? stockResult : adjustResult}</div>
        </div>
        <div>
          <div style="font-size:.7rem;font-weight:800">${g.splits} units @ ${r.length_mtr} mtr</div>
          <div style="font-size:.6rem;color:var(--text-muted)">Output: ${yieldPerRoll.toLocaleString()} M / roll</div>
        </div>
        <div>
          <div style="font-size:.85rem;font-weight:900;color:${effColor}">${g.efficiency}%</div>
          <div class="slt-stock-eff-bar"><div class="slt-stock-eff-fill" style="width:${g.efficiency}%;background:${effColor}"></div></div>
        </div>
        <div>
          <div class="slt-qty-stepper">
            <button onclick="SLT.adjustQty('${esc(g.key)}',-1)">−</button>
            <span id="qty_${esc(g.key)}">${selectionMap[g.key] || 0}</span>
            <button onclick="SLT.adjustQty('${esc(g.key)}',1)">+</button>
          </div>
          <div style="font-size:.55rem;color:var(--text-muted);text-align:center;margin-top:2px">of ${g.rolls.length}</div>
        </div>
      </div>`;
    });

    contentEl.innerHTML = html;
    contentEl._groups = groups;
    contentEl._targetWidth = targetWidth;
    contentEl._reqMtrs = reqMtrs;
    updateProductionSummary();
  }

  function setWaste(key, mode) {
    wastagePrefs[key] = mode;
    const row = document.querySelector(`.slt-stock-grid[data-key="${key}"]`);
    if (row) {
      row.querySelectorAll('.slt-waste-toggle button').forEach(b => {
        const bMode = b.textContent.trim().toUpperCase();
        b.className = '';
        if (bMode === mode) b.className = (mode === 'STOCK' ? 'stock-active' : 'adjust-active');
      });
    }
    // Re-render to update result text
    const contentEl = document.getElementById('stockAnalysisContent');
    if (contentEl._targetWidth !== undefined) {
      renderStockOptions(contentEl._targetWidth, contentEl._reqMtrs);
    }
  }

  function adjustQty(key, delta) {
    const contentEl = document.getElementById('stockAnalysisContent');
    const groups = contentEl._groups || [];
    const g = groups.find(x => x.key === key);
    if (!g) return;
    const current = selectionMap[key] || 0;
    selectionMap[key] = Math.max(0, Math.min(g.rolls.length, current + delta));
    const span = document.getElementById('qty_' + key);
    if (span) span.textContent = selectionMap[key];
    updateProductionSummary();
  }

  function updateProductionSummary() {
    const contentEl = document.getElementById('stockAnalysisContent');
    const groups = allStockGroups || [];
    const reqMtrs = contentEl._reqMtrs || 0;

    let totalProduced = 0;
    groups.forEach(g => {
      const qty = selectionMap[g.key] || 0;
      const yieldPerRoll = (parseFloat(g.roll.length_mtr) || 0) * g.splits;
      totalProduced += qty * yieldPerRoll;
    });

    const summaryEl = document.getElementById('stockModalSummary');
    const pct = reqMtrs > 0 ? Math.min(100, Math.round(totalProduced / reqMtrs * 100)) : 0;
    const achieved = totalProduced >= reqMtrs && reqMtrs > 0;
    const exceeded = totalProduced > reqMtrs && reqMtrs > 0;
    const balance = Math.max(0, reqMtrs - totalProduced);
    const overBy = Math.max(0, totalProduced - reqMtrs);
    const hasSelection = Object.values(selectionMap).some(v => v > 0);

    summaryEl.innerHTML = hasSelection
      ? `<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span style="font-weight:800">${totalProduced.toLocaleString()} M of ${(reqMtrs || 0).toLocaleString()} M required</span>
          <div style="width:120px;height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden"><div style="height:100%;width:${pct}%;background:${exceeded ? '#dc2626' : (achieved ? '#22c55e' : '#3b82f6')};border-radius:4px"></div></div>
          <span style="font-size:.55rem;font-weight:800;background:${exceeded ? '#dc2626' : (achieved ? '#22c55e' : '#f59e0b')};color:#fff;padding:3px 8px;border-radius:10px">${exceeded ? 'Exceed' : (achieved ? 'Target Achieved' : 'In Progress')}</span>
          <span style="font-size:.7rem;font-weight:700;color:${exceeded ? '#dc2626' : '#64748b'}">${exceeded ? ('Over by ' + overBy.toLocaleString() + ' M') : ('Remaining ' + balance.toLocaleString() + ' M')}</span>
        </div>`
      : 'Select rolls and set qty to deploy';

    document.getElementById('btnDeployTerminal').style.display = hasSelection ? '' : 'none';
    document.getElementById('btnDeployTerminal').onclick = () => deployToTerminal();
  }

  function deployToTerminal() {
    const contentEl = document.getElementById('stockAnalysisContent');
    const groups = allStockGroups || [];
    const targetWidth = contentEl._targetWidth || 0;
    const j = selectedJob;

    groups.forEach(g => {
      const qty = selectionMap[g.key] || 0;
      if (qty <= 0) return;
      const pref = wastagePrefs[g.key] || 'STOCK';
      const rollsToUse = g.rolls.slice(0, qty);

      rollsToUse.forEach(roll => {
        addRollToTerminal(roll);

        const slitWidth = pref === 'ADJUST'
          ? Math.round(parseFloat(roll.width_mm) / g.splits)
          : targetWidth;

        if (rollConfigs[roll.roll_no]) {
          rollConfigs[roll.roll_no].runs = [{
            width: slitWidth,
            length: parseFloat(roll.length_mtr) || 0,
            qty: g.splits
          }];
          rollConfigs[roll.roll_no].remainderAction = pref;
          rollConfigs[roll.roll_no].destination = 'JOB';
          if (!String(rollConfigs[roll.roll_no].job_no || '').trim()) {
            rollConfigs[roll.roll_no].job_no = j?.job_no || '';
          }
          rollConfigs[roll.roll_no].job_name = j?.job_name || '';
          rollConfigs[roll.roll_no].job_size = j?.label_length_mm || j?.label_width_mm || '';
        }
      });
    });

    closeModal('stockModal');
    renderLoadedRolls();
    renderConfig();
    renderBatchStatus();
    showToast('Rolls deployed to terminal', 'success');
  }

  // ── Execute Batch ──────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btnExecuteBatch');
    if (btn) {
      setExecuteButtonLabel();
      btn.addEventListener('click', executeBatch);
    }
  });

  async function executeBatch() {
    if (!loadedRolls.length) return;

    const btn = document.getElementById('btnExecuteBatch');
    btn.disabled = true;
    btn.innerHTML = isAcceptMode()
      ? '<i class="bi bi-hourglass-split"></i> Updating…'
      : '<i class="bi bi-hourglass-split"></i> Executing…';

    const operator = (document.getElementById('execOperator').value || CURRENT_OPERATOR).trim();
    const machine  = document.getElementById('execMachine').value;

    if (!machine) {
      showToast('Selected department-er jonno kono active machine mapping paoa jayni', 'warning');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
      return;
    }

    if (isAcceptMode()) {
      await applyAcceptRollUpdate(btn);
      return;
    }

    const results = [];
    let hasError = false;

    for (const roll of loadedRolls) {
      const cfg = rollConfigs[roll.roll_no];
      if (!cfg) continue;

      const validRuns = cfg.runs.filter(r => parseFloat(r.width) > 0);
      if (!validRuns.length) continue;

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('action', 'execute_batch');
      fd.append('parent_roll_no', roll.roll_no);
      fd.append('runs', JSON.stringify(validRuns.map(r => ({
        width: r.width,
        length: r.length,
        qty: r.qty,
        destination: cfg.destination,
        job_no: cfg.job_no,
        job_name: cfg.job_name,
        job_size: cfg.job_size,
      }))));
      fd.append('remainder_action', cfg.remainderAction);
      fd.append('operator_name', operator);
      fd.append('machine', machine);
      fd.append('department_route', selectedMachineDepartments.join(', '));
      fd.append('destination', cfg.destination);
      fd.append('job_no', cfg.job_no);
      fd.append('job_name', cfg.job_name);
      fd.append('job_size', cfg.job_size);
      if (selectedJob && selectedJob.job_no) {
        fd.append('plan_no', selectedJob.job_no);
      }
      // Pass planning_id for status update if from planner
      if (selectedJob && selectedJob.id) {
        fd.append('planning_id', selectedJob.id);
      }
      if (isAcceptMode()) {
        fd.append('execution_scenario', 'Update Roll (Manager Accept)');
      }

      try {
        const res = await fetch(API, {method: 'POST', body: fd});
        const data = await res.json();
        if (data.ok) {
          results.push(data);
        } else {
          hasError = true;
          showToast('Error: ' + (data.error || 'Unknown'), 'error');
        }
      } catch (err) {
        hasError = true;
        showToast('Network error: ' + err.message, 'error');
      }
    }

    if (results.length) {
      showToast(results.length + ' batch(es) executed successfully!', 'success');

      // Clear terminal
      loadedRolls = [];
      activeRollNo = null;
      rollConfigs = {};
      renderLoadedRolls();
      renderConfig();
      renderBatchStatus();
      await loadPlannerJobs();
      await loadHistory();

      // Show report for last batch
      if (results.length === 1) {
        openBatchReport(results[0].batch_id);
      }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
  }

  function buildManagerRowsFromTerminal() {
    const rows = [];
    loadedRolls.forEach(roll => {
      const cfg = rollConfigs[roll.roll_no];
      if (!cfg) return;

      const rollNo = String(roll.roll_no || '').trim();
      const parentLength = parseFloat(roll.length_mtr) || 0;
      let widthIdx = 0;
      let lengthIdx = 0;
      let totalUsed = 0;

      cfg.runs.forEach(run => {
        const w = parseFloat(run.width) || 0;
        const l = parseFloat(run.length) || 0;
        const q = Math.max(1, parseInt(run.qty) || 1);
        if (w <= 0) return;

        const mode = detectModeJS(l, parentLength);
        for (let i = 0; i < q; i++) {
          let suffix;
          if (mode === 'LENGTH') {
            suffix = String(lengthIdx + 1);
            lengthIdx++;
          } else {
            suffix = String.fromCharCode(65 + (widthIdx % 26));
            const cycle = Math.floor(widthIdx / 26);
            if (cycle > 0) suffix += cycle;
            widthIdx++;
          }

          const bucket = String(cfg.destination || '').toUpperCase() === 'STOCK' ? 'stock' : 'child';
          const childLength = l > 0 ? l : parentLength;
          rows.push({
            parent_roll_no: ACCEPT_OLD_PARENT_ROLL,
            roll_no: rollNo + '-' + suffix,
            bucket: bucket,
            width: w,
            length: childLength,
            wastage: 0,
            status: bucket === 'stock' ? 'Stock' : 'Job Assign',
            remarks: ''
          });
          totalUsed += w;
        }
      });

      const parentWidth = parseFloat(roll.width_mm) || 0;
      const remainder = parentWidth - totalUsed;
      if (remainder > 0.5 && cfg.remainderAction === 'STOCK') {
        let remSuffix = String.fromCharCode(65 + (widthIdx % 26));
        const remCycle = Math.floor(widthIdx / 26);
        if (remCycle > 0) remSuffix += remCycle;
        rows.push({
          parent_roll_no: ACCEPT_OLD_PARENT_ROLL,
          roll_no: rollNo + '-' + remSuffix,
          bucket: 'stock',
          width: parseFloat(remainder.toFixed(2)),
          length: parentLength,
          wastage: 0,
          status: 'Stock',
          remarks: 'Remainder from manager update'
        });
      }
    });

    return rows;
  }

  async function applyAcceptRollUpdate(btn) {
    if (ACCEPT_JOB_ID <= 0 || ACCEPT_REQUEST_ID <= 0) {
      showToast('Request context missing (job_id/request_id). Please reopen from Accept button.', 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
      return;
    }

    const rows = buildManagerRowsFromTerminal();
    if (!rows.length) {
      showToast('No valid slit rows found for update.', 'warning');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
      return;
    }

    const body = new URLSearchParams();
    body.set('csrf_token', CSRF);
    body.set('action', 'apply_jumbo_manager_roll_update');
    body.set('job_id', String(ACCEPT_JOB_ID));
    body.set('request_id', String(ACCEPT_REQUEST_ID));
    body.set('old_parent_roll_no', String(ACCEPT_OLD_PARENT_ROLL || ''));
    body.set('old_parent_prev_status', String(ACCEPT_OLD_PARENT_PREV_STATUS || 'Main'));
    body.set('rows_json', JSON.stringify(rows));
    body.set('review_note', 'Updated from slitting manager update flow');

    try {
      const res = await fetch(JOBS_API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString(),
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data.ok) {
        const msg = data.error || 'Manager update failed';
        showToast(msg, 'error');
        alert('Update Roll failed: ' + msg);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
        return;
      }

      alert('Update Roll success: ' + String(data.new_parent_roll || 'updated'));
      showToast('Roll updated successfully. Redirecting…', 'success');
      setTimeout(() => {
        const doneUrl = new URL('<?= BASE_URL ?>/modules/jobs/jumbo/index.php', window.location.origin);
        doneUrl.searchParams.set('accepted_done', '1');
        if (ACCEPT_JOB_ID > 0) doneUrl.searchParams.set('auto_job', String(ACCEPT_JOB_ID));
        if (ACCEPT_REQUEST_ID > 0) doneUrl.searchParams.set('request_id', String(ACCEPT_REQUEST_ID));
        window.location.href = doneUrl.toString();
      }, 700);
    } catch (err) {
      showToast('Network error: ' + err.message, 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-play-circle"></i> ' + getExecuteButtonLabel();
    }
  }

  // ── History ────────────────────────────────────────────────
  async function loadHistory() {
    const data = await apiGet('list_batches');
    if (!data.ok) return;

    const el = document.getElementById('historyTableBody');
    if (!data.batches || !data.batches.length) {
      el.innerHTML = '<tr><td colspan="8" class="table-empty"><i class="bi bi-inbox"></i>No batches yet</td></tr>';
      return;
    }

    el.innerHTML = data.batches.map(b => `<tr>
      <td><strong style="font-family:monospace">${esc(b.batch_no)}</strong></td>
      <td>${formatDate(b.created_at)}</td>
      <td>${esc(b.operator_name || '—')}</td>
      <td>${esc(b.machine || '—')}</td>
      <td style="font-family:monospace;font-size:.78rem">${esc(b.parent_rolls || '—')}</td>
      <td><strong>${b.child_count || 0}</strong></td>
      <td>${statusBadge(b.status)}</td>
      <td><button class="slt-view-btn" onclick="SLT.openBatchReport(${b.id})"><i class="bi bi-eye"></i> View</button></td>
    </tr>`).join('');
  }

  // ── Batch Report ───────────────────────────────────────────
  async function openBatchReport(batchId) {
    openModal('reportModal');
    const el = document.getElementById('reportContent');
    el.innerHTML = '<div class="slt-empty"><i class="bi bi-hourglass-split"></i><p>Loading report…</p></div>';

    const data = await apiGet('get_batch', {id: batchId});
    if (!data.ok) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-exclamation-triangle"></i><p>' + esc(data.error) + '</p></div>';
      return;
    }

    reportState.data = data;
    renderBatchReport();
  }

  function renderBatchReport() {
    const data = reportState.data;
    if (!data) return;

    const el = document.getElementById('reportContent');
    const b = data.batch || {};
    const entries = data.entries || [];
    const parents = data.parents || {};
    const jobCards = data.job_cards || [];
    const planning = data.planning || {};
    const company = data.company || {};
    const template = reportState.template || 'executive';
    const jumboCards = jobCards.filter(j => (j.department || '') === 'jumbo_slitting' || (j.job_type || '') === 'Slitting');
    const printingCards = jobCards.filter(j => (j.department || '') === 'flexo_printing' || (j.job_type || '') === 'Printing');
    const primaryJumboCard = jumboCards.length ? jumboCards[0] : null;
    const isDirectFlexo = !primaryJumboCard && printingCards.length > 0;
    const primaryPrintingCard = printingCards.length ? printingCards[0] : null;
    const reportIdentity = primaryJumboCard && primaryJumboCard.job_no ? primaryJumboCard.job_no : (b.batch_no || 'N/A');
    const reportIdentityLabel = isDirectFlexo ? 'Direct Flexo Job Card ID' : 'Jumbo Job Card ID';
    const reportIdentityValue = isDirectFlexo
      ? ((primaryPrintingCard && primaryPrintingCard.job_no) ? primaryPrintingCard.job_no : (b.batch_no || 'N/A'))
      : reportIdentity;
    const executionScenario = isAcceptMode() ? 'Update Roll (Manager Accept)' : '';
    const parentRows = Object.values(parents).filter(Boolean);
    const totalWasteMm = entries.filter(e => parseInt(e.is_remainder || 0, 10) === 1).reduce((sum, e) => sum + parseFloat(e.slit_width_mm || 0), 0);
    const qrPayload = JSON.stringify({
      type: 'slitting-traceability',
      route: isDirectFlexo ? 'direct-flexo' : 'jumbo-flexo',
      jumbo_job_card_id: reportIdentity,
      direct_flexo_job_card_id: isDirectFlexo ? ((primaryPrintingCard && primaryPrintingCard.job_no) ? primaryPrintingCard.job_no : '') : '',
      batch_no: b.batch_no || '',
      planning_job_no: planning.job_no || '',
      planning_job_name: planning.job_name || '',
      child_rolls: entries.map(e => e.child_roll_no || '')
    });

    let html = '';
    html += '<div class="slt-report-sheet template-' + esc(template) + '">';
    html += '<div class="slt-report-branding">';
    html += '<div class="slt-report-brand-left">';
    html += company.logo_url
      ? '<img class="slt-report-logo" src="' + esc(company.logo_url) + '" alt="Company Logo">'
      : '<div class="slt-report-logo slt-report-logo-placeholder">SLC</div>';
    html += '<div>';
    html += '<div class="slt-report-company">' + esc(company.company_name || company.erp_name || 'Company') + '</div>';
    html += '<div class="slt-report-address">' + esc(company.company_address || '—') + '</div>';
    html += '<div class="slt-report-address">' + esc([company.company_mobile || company.company_phone, company.company_email, company.company_gst ? ('GST: ' + company.company_gst) : ''].filter(Boolean).join(' | ') || '—') + '</div>';
    html += '</div></div>';
    html += '<div class="slt-report-brand-right">';
    html += '<div class="slt-report-id-card"><div class="slt-report-id-label">' + esc(reportIdentityLabel) + '</div><div class="slt-report-id-value">' + esc(reportIdentityValue) + '</div><div class="slt-report-id-sub">Gen Date: ' + esc(formatDate(b.created_at)) + '</div></div>';
    html += '<div class="slt-report-qr-wrap"><div id="slt-report-qr"></div><div class="slt-report-qr-label">Technical Trace ID</div></div>';
    html += '</div></div>';

    html += '<div class="slt-report-section-title">Execution Identity</div>';
    html += '<div class="slt-report-meta-grid">';
    html += '<div><span>Plan No</span><strong>' + esc(planning.job_no || '—') + '</strong></div>';
    html += '<div><span>Job Name</span><strong>' + esc(planning.job_name || entries[0]?.job_name || '—') + '</strong></div>';
    html += '<div><span>Substrate</span><strong>' + esc((parentRows[0] && parentRows[0].paper_type) || '—') + '</strong></div>';
    html += '<div><span>Machine ID</span><strong>' + esc(b.machine || 'MANUAL_TERMINAL') + '</strong></div>';
    html += '<div><span>Operator</span><strong>' + esc(b.operator_name || '—') + '</strong></div>';
    html += '<div><span>Status</span><strong>' + esc(b.status || 'Completed') + '</strong></div>';
    if (executionScenario) {
      html += '<div><span>Scenario</span><strong>' + esc(executionScenario) + '</strong></div>';
    }
    html += '</div>';

    html += '<div class="slt-report-section-title">Source Material Allocation</div>';
    html += '<table class="slt-report-table nice"><thead><tr><th>Roll ID</th><th>Company</th><th>Type</th><th>Dimension</th><th>GSM</th><th>Weight</th><th>SQ. MTR</th><th>Job Context</th></tr></thead><tbody>';
    parentRows.forEach(function(p) {
      const sqm = ((parseFloat(p.width_mm || 0) / 1000) * parseFloat(p.length_mtr || 0)).toFixed(2);
      html += '<tr>';
      html += '<td><strong>' + esc(p.roll_no || '—') + '</strong></td>';
      html += '<td>' + esc(p.company || '—') + '</td>';
      html += '<td>' + esc(p.paper_type || '—') + '</td>';
      html += '<td>' + esc((p.width_mm || '—') + 'mm x ' + (p.length_mtr || '—') + 'm') + '</td>';
      html += '<td>' + esc(p.gsm || '—') + '</td>';
      html += '<td>' + esc(p.weight_kg || '—') + '</td>';
      html += '<td>' + esc(sqm) + '</td>';
      html += '<td>' + esc(planning.job_name || planning.job_no || '—') + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    html += '<div class="slt-report-section-title">Slitting Unit Outputs</div>';
    html += '<table class="slt-report-table nice"><thead><tr><th>Child Roll ID</th><th>Parent Ref</th><th>Width</th><th>Length</th><th>GSM</th><th>Qty</th><th>Wastage</th><th>Destination</th></tr></thead><tbody>';
    entries.forEach(function(e) {
      const isRem = parseInt(e.is_remainder || 0, 10) === 1;
      html += '<tr' + (isRem ? ' class="is-remainder"' : '') + '>';
      html += '<td><strong>' + esc(e.child_roll_no || '—') + '</strong></td>';
      html += '<td>' + esc(e.parent_roll_no || '—') + '</td>';
      html += '<td>' + esc((e.slit_width_mm || '—') + 'mm') + '</td>';
      html += '<td>' + esc((e.slit_length_mtr || '—') + 'm') + '</td>';
      html += '<td>' + esc((parentRows[0] && parentRows[0].gsm) || '—') + '</td>';
      html += '<td>' + esc(e.qty || '1') + '</td>';
      html += '<td>' + esc(isRem ? ((parseFloat(e.slit_width_mm || 0)).toFixed(2) + 'mm') : '0') + '</td>';
      html += '<td>' + esc(e.destination || '—') + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    html += '<div class="slt-report-bottom-grid">';
    html += '<div class="slt-report-note-box"><div class="box-title">Machine Run Log</div><div class="box-row"><span>Start:</span><strong>' + esc(formatDate(b.created_at)) + '</strong></div><div class="box-row"><span>End:</span><strong>' + esc(formatDate(b.updated_at || b.created_at)) + '</strong></div><div class="box-row accent"><span>Net Waste:</span><strong>' + esc(totalWasteMm.toFixed(2) + ' mm') + '</strong></div></div>';
    html += '<div class="slt-report-note-box"><div class="box-title">Technical Remarks</div><div class="box-body">' + esc(planning.notes || 'No specific floor deviations noted.') + '</div><div class="box-mini">Printing Job Cards: ' + esc(printingCards.map(function(j) { return j.job_no || ''; }).filter(Boolean).join(', ') || '—') + '</div></div>';
    html += '<div class="slt-report-note-box sign-box"><div class="box-title">Approval</div><div class="sign-line"></div><div class="sign-label">QC Supervisor Sign</div></div>';
    html += '</div>';
    html += '<div class="slt-report-footer">' + esc(company.footer_text || '') + '</div>';
    html += '</div>';

    el.innerHTML = html;

    const qrEl = document.getElementById('slt-report-qr');
    if (qrEl) {
      qrEl.innerHTML = '';
      if (typeof QRCode !== 'undefined') {
        new QRCode(qrEl, { text: qrPayload, width: 96, height: 96, correctLevel: QRCode.CorrectLevel.M });
      }
    }
  }

  // ── Machines ───────────────────────────────────────────────
  async function loadMachines() {
    const data = await apiGet('get_machines');
    if (!data.ok) return;
    machines = data.machines || [];
    machineDepartments = data.departments || [];
    const op = document.getElementById('execOperator');
    if (op) {
      op.value = '';
      op.readOnly = true;
    }
    applyPlannerMachineSelection();
  }

  // ── Modal helpers ──────────────────────────────────────────
  function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
    if (id === 'reportModal') {
      loadPlannerJobs();
      loadHistory();
    }
  }

  // Close modal on overlay click
  document.addEventListener('click', e => {
    if (e.target.classList.contains('slt-modal-overlay') && e.target.classList.contains('open')) {
      e.target.classList.remove('open');
      document.body.style.overflow = '';
      if (e.target.id === 'reportModal') {
        loadPlannerJobs();
        loadHistory();
      }
    }
  });

  // ── API helpers ────────────────────────────────────────────
  async function apiGet(action, params) {
    const url = new URL(API, window.location.origin);
    url.searchParams.set('action', action);
    if (params) {
      for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    }
    try {
      const res = await fetch(url);
      return await res.json();
    } catch (err) {
      return {ok: false, error: err.message};
    }
  }

  // ── Utility ────────────────────────────────────────────────
  function esc(s) {
    if (s === null || s === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function statusBadge(status) {
    if (!status) return '';
    const map = {
      'Main':'main','Stock':'stock','Slitting':'slitting','Job Assign':'job-assign',
      'Consumed':'consumed','Available':'available','Assigned':'assigned',
      'Draft':'draft','Executing':'in-progress','Completed':'completed',
      'Queued':'queued','In Progress':'in-progress','On Hold':'on-hold',
      'Pending':'pending','Barcode Ready':'approved','Preparing Slitting':'in-production','Running':'in-progress','Urgent':'urgent','High':'high','Normal':'normal','Low':'low',
      'Printing':'in-production','Fabrication':'slitting','Packing':'pending',
      'Ready to Dispatch':'approved','Dispatched':'dispatched',
    };
    const cls = map[status] || 'draft';
    return '<span class="badge badge-' + cls + '">' + esc(status) + '</span>';
  }

  function normalizePlannerStatus(status) {
    const s = String(status || '').trim().toLowerCase();
    if (!s) return 'pending';
    if (s === 'barcode_ready') return 'barcode ready';
    return s;
  }

  function formatDate(dt) {
    if (!dt) return '—';
    const d = new Date(dt);
    return d.toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'});
  }

  function capitalize(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function showToast(msg, type) {
    type = type || 'info';
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:10px;font-size:.85rem;font-weight:600;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.2);transition:opacity .3s;max-width:400px';
    const colors = {success:'#16a34a', error:'#dc2626', warn:'#d97706', info:'#2563eb'};
    toast.style.background = colors[type] || colors.info;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 300); }, 3000);
  }

  function refreshAll() {
    loadPlannerJobs();
    loadHistory();
  }

  // ── Public API ─────────────────────────────────────────────
  return {
    init: init, refreshAll: refreshAll,
    selectRoll: selectRoll, removeRoll: removeRoll, pickSuggestion: pickSuggestion,
    selectJob: selectJob, openBatchReport: openBatchReport,
    updateConfig: updateConfig, updateRun: updateRun, addRun: addRun, removeRun: removeRun,
    setSlitMode: setSlitMode, updateEqualPieces: updateEqualPieces,
    setPlannerFilter: setPlannerFilter, setWaste: setWaste, adjustQty: adjustQty,
    openModal: openModal, closeModal: closeModal,
  };
})();

document.addEventListener('DOMContentLoaded', SLT.init);
</script>
