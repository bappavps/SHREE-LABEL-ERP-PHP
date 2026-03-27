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
            <input type="text" id="execOperator" class="form-control" style="height:34px;font-size:.8rem" placeholder="Operator name">
          </div>
          <div class="form-group">
            <label>Machine</label>
            <select id="execMachine" class="form-control" style="height:34px;font-size:.8rem">
              <option value="">Select machine…</option>
            </select>
          </div>
        </div>
        <button class="btn btn-primary btn-full" id="btnExecuteBatch" disabled><i class="bi bi-play-circle"></i> Execute Batch</button>
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
  <div class="slt-modal">
    <div class="slt-modal-head">
      <h3><i class="bi bi-bar-chart-steps"></i> Stock Analysis — Slitting Options</h3>
      <button class="slt-modal-close" onclick="SLT.closeModal('stockModal')">&times;</button>
    </div>
    <div class="slt-modal-body">
      <div id="stockAnalysisContent">
        <div class="slt-empty"><i class="bi bi-hourglass-split"></i><p>Analyzing stock…</p></div>
      </div>
    </div>
    <div class="slt-modal-foot">
      <div id="stockModalSummary" style="font-size:.82rem;color:var(--text-muted)"></div>
      <button class="btn btn-primary" id="btnDeployTerminal" style="display:none"><i class="bi bi-box-arrow-in-right"></i> Deploy to Terminal</button>
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
      <span></span>
      <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print Report</button>
    </div>
  </div>
</div>

</main>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>

<script>
// ════════════════════════════════════════════════════════════════
// Industrial Slitting Terminal — Frontend Logic
// ════════════════════════════════════════════════════════════════
const SLT = (() => {
  const API  = '<?= BASE_URL ?>/modules/inventory/slitting/api.php';
  const CSRF = '<?= e($csrf) ?>';

  // ── State ──────────────────────────────────────────────────
  let plannerJobs     = [];
  let selectedJob     = null;
  let stockOptions    = [];

  let loadedRolls     = [];   // [{roll_no, paper_type, width_mm, length_mtr, …}]
  let activeRollNo    = null; // currently selected roll for config
  let rollConfigs     = {};   // rollNo -> { runs: [{width,length,qty}], destination, job_no, job_name, job_size, remainderAction }
  let machines        = [];

  // ── Init ───────────────────────────────────────────────────
  function init() {
    bindTabs();
    bindScanInput();
    loadMachines();
    loadPlannerJobs();
    loadHistory();

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
    // Default destination: JOB for Slitting status, STOCK otherwise
    const defaultDest = (roll.status === 'Slitting') ? 'JOB' : 'STOCK';
    rollConfigs[roll.roll_no] = {
      runs: [{width: '', length: parseFloat(roll.length_mtr), qty: 1}],
      slitMode: 'MANUAL', // MANUAL or EQUAL_DIVIDE
      equalPieces: 2,
      destination: defaultDest,
      job_no: '', job_name: '', job_size: '',
      remainderAction: 'STOCK'
    };
    if (!activeRollNo) activeRollNo = roll.roll_no;
    renderLoadedRolls();
    renderConfig();
    renderBatchStatus();
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
        <input type="text" class="form-control" value="${esc(cfg.job_no)}" onchange="SLT.updateConfig('${esc(activeRollNo)}','job_no',this.value)">
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
      rollConfigs[rollNo][key] = value;
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

  // ── Auto Planner ───────────────────────────────────────────
  async function loadPlannerJobs() {
    const data = await apiGet('get_planning_jobs');
    if (!data.ok) return;
    plannerJobs = data.jobs || [];
    renderPlannerJobs();
  }

  function renderPlannerJobs(filter) {
    const el = document.getElementById('plannerJobList');
    let jobs = plannerJobs;
    if (filter) {
      const f = filter.toLowerCase();
      jobs = jobs.filter(j => (j.job_name || '').toLowerCase().includes(f) || (j.material_type || '').toLowerCase().includes(f));
    }

    if (!jobs.length) {
      el.innerHTML = '<div class="slt-empty"><i class="bi bi-inbox"></i><p>No pending jobs</p></div>';
      return;
    }

    el.innerHTML = jobs.map(j => {
      const sel = selectedJob && selectedJob.id === j.id;
      const pri = j.priority || 'Normal';
      return `<div class="slt-job-item${sel?' selected':''}" onclick="SLT.selectJob(${j.id})">
        <div>
          <div class="job-label">${esc(j.job_name)}</div>
          <div class="job-meta">${esc(j.material_type || 'N/A')} · ${esc(j.label_width_mm || '?')}mm × ${esc(j.label_length_mm || '?')}mm</div>
        </div>
        <span class="badge badge-${pri.toLowerCase()}">${esc(pri)}</span>
      </div>`;
    }).join('');

    // Search binding
    document.getElementById('plannerSearch').oninput = function() {
      renderPlannerJobs(this.value);
    };
  }

  function selectJob(id) {
    selectedJob = plannerJobs.find(j => j.id == id) || null;
    renderPlannerJobs();
    renderJobDetail();
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
      <div class="detail-row"><span class="detail-label">Job Name</span><span class="detail-value">${esc(j.job_name)}</span></div>
      <div class="detail-row"><span class="detail-label">Material</span><span class="detail-value">${esc(j.material_type || 'N/A')}</span></div>
      <div class="detail-row"><span class="detail-label">Required Width</span><span class="detail-value">${j.label_width_mm || '—'}mm</span></div>
      <div class="detail-row"><span class="detail-label">Required Length</span><span class="detail-value">${j.label_length_mm || '—'}mm</span></div>
      <div class="detail-row"><span class="detail-label">Quantity</span><span class="detail-value">${j.quantity || '—'}</span></div>
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

    const data = await apiGet('search_rolls_by_material', {
      paper_type: j.material_type || '',
      target_width: j.label_width_mm || 0,
      target_length: j.label_length_mm || 0,
    });

    if (!data.ok || !data.options || !data.options.length) {
      contentEl.innerHTML = '<div class="slt-empty"><i class="bi bi-exclamation-triangle"></i><p>No suitable stock found for this material and width</p></div>';
      document.getElementById('btnDeployTerminal').style.display = 'none';
      return;
    }

    stockOptions = data.options;

    let html = '<div class="slt-stock-options">';
    html += `<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;padding:0 14px 8px;font-size:.65rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;letter-spacing:.05em">
      <span>Roll</span><span>Efficiency</span><span>Splits</span><span>Waste</span><span>Qty</span>
    </div>`;

    data.options.forEach((opt, idx) => {
      const r = opt.roll;
      const effColor = opt.efficiency >= 90 ? '#16a34a' : (opt.efficiency >= 70 ? '#d97706' : '#dc2626');
      html += `<div class="slt-stock-opt" id="stockOpt${idx}" onclick="SLT.toggleStockOption(${idx})">
        <div>
          <div class="opt-roll">${esc(r.roll_no)}</div>
          <div class="opt-dim">${esc(r.paper_type)} · ${r.width_mm}mm × ${r.length_mtr}m</div>
        </div>
        <div>
          <span class="opt-eff" style="color:${effColor}">${opt.efficiency}%</span>
          <div class="slt-eff-bar"><div class="slt-eff-fill" style="width:${opt.efficiency}%;background:${effColor}"></div></div>
        </div>
        <div class="opt-splits">${opt.splits} splits</div>
        <div class="opt-waste">${opt.waste_mm}mm waste</div>
        <div><input type="number" class="slt-qty-input" min="0" value="0" id="stockQty${idx}" onclick="event.stopPropagation()" onchange="SLT.updateStockQty(${idx},this.value)"></div>
      </div>`;
    });
    html += '</div>';
    contentEl.innerHTML = html;

    document.getElementById('btnDeployTerminal').style.display = '';
    document.getElementById('btnDeployTerminal').onclick = () => deployToTerminal();
    updateStockSummary();
  }

  function toggleStockOption(idx) {
    const el = document.getElementById('stockOpt' + idx);
    const qtyEl = document.getElementById('stockQty' + idx);
    const current = parseInt(qtyEl.value) || 0;
    qtyEl.value = current === 0 ? 1 : 0;
    el.classList.toggle('selected', parseInt(qtyEl.value) > 0);
    updateStockSummary();
  }

  function updateStockQty(idx, val) {
    const el = document.getElementById('stockOpt' + idx);
    el.classList.toggle('selected', parseInt(val) > 0);
    updateStockSummary();
  }

  function updateStockSummary() {
    let totalRolls = 0;
    stockOptions.forEach((opt, idx) => {
      const q = parseInt(document.getElementById('stockQty' + idx)?.value) || 0;
      totalRolls += q;
    });
    document.getElementById('stockModalSummary').textContent = totalRolls > 0
      ? totalRolls + ' roll(s) selected for deployment'
      : 'Select rolls and set qty to deploy';
  }

  function deployToTerminal() {
    stockOptions.forEach((opt, idx) => {
      const q = parseInt(document.getElementById('stockQty' + idx)?.value) || 0;
      if (q <= 0) return;
      const roll = opt.roll;
      addRollToTerminal(roll);

      // Pre-configure runs based on analysis
      const tw = selectedJob?.label_width_mm || 0;
      const tl = selectedJob?.label_length_mm || 0;
      if (parseFloat(tw) > 0 && rollConfigs[roll.roll_no]) {
        const runs = [];
        for (let i = 0; i < opt.splits; i++) {
          runs.push({
            width: parseFloat(tw),
            length: parseFloat(roll.length_mtr),
            qty: 1
          });
        }
        if (!runs.length) runs.push({width: '', length: parseFloat(roll.length_mtr), qty: 1});
        rollConfigs[roll.roll_no].runs = runs;
        rollConfigs[roll.roll_no].destination = 'JOB';
        rollConfigs[roll.roll_no].job_name = selectedJob?.job_name || '';
      }
    });

    closeModal('stockModal');
    renderLoadedRolls();
    renderConfig();
    renderBatchStatus();
    showToast('Rolls deployed to terminal', 'success');
  }

  // ── Execute Batch ──────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnExecuteBatch').addEventListener('click', executeBatch);
  });

  async function executeBatch() {
    if (!loadedRolls.length) return;

    const btn = document.getElementById('btnExecuteBatch');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Executing…';

    const operator = document.getElementById('execOperator').value.trim();
    const machine  = document.getElementById('execMachine').value;

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
      fd.append('destination', cfg.destination);
      fd.append('job_no', cfg.job_no);
      fd.append('job_name', cfg.job_name);
      fd.append('job_size', cfg.job_size);

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

      // Show report for last batch
      if (results.length === 1) {
        openBatchReport(results[0].batch_id);
      }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-play-circle"></i> Execute Batch';
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

    const b = data.batch;
    const entries = data.entries || [];
    const parents = data.parents || {};

    let html = `<div class="slt-report-header">
      <h2>Slitting Traceability Report</h2>
      <p>Batch: ${esc(b.batch_no)} · Date: ${formatDate(b.created_at)}</p>
    </div>`;

    // Batch info grid
    html += `<div class="slt-report-grid">
      <div class="rg-item"><span class="rg-label">Batch No</span><span class="rg-value">${esc(b.batch_no)}</span></div>
      <div class="rg-item"><span class="rg-label">Status</span><span class="rg-value">${esc(b.status)}</span></div>
      <div class="rg-item"><span class="rg-label">Operator</span><span class="rg-value">${esc(b.operator_name || '—')}</span></div>
      <div class="rg-item"><span class="rg-label">Machine</span><span class="rg-value">${esc(b.machine || '—')}</span></div>
    </div>`;

    // Source material table
    html += '<h4 style="font-size:.82rem;font-weight:700;margin:14px 0 8px">Source Material</h4>';
    html += `<table class="slt-report-table"><thead><tr>
      <th>Roll No</th><th>Material</th><th>Company</th><th>Width</th><th>Length</th><th>Status</th>
    </tr></thead><tbody>`;
    Object.values(parents).forEach(p => {
      if (!p) return;
      html += `<tr>
        <td><strong>${esc(p.roll_no)}</strong></td>
        <td>${esc(p.paper_type)}</td>
        <td>${esc(p.company)}</td>
        <td>${p.width_mm}mm</td>
        <td>${p.length_mtr}m</td>
        <td>${statusBadge(p.status)}</td>
      </tr>`;
    });
    html += '</tbody></table>';

    // Slitting output table
    html += '<h4 style="font-size:.82rem;font-weight:700;margin:14px 0 8px">Slitting Output</h4>';
    html += `<table class="slt-report-table"><thead><tr>
      <th>#</th><th>Child Roll</th><th>Width</th><th>Length</th><th>Mode</th><th>Destination</th><th>Job</th><th>Rem</th>
    </tr></thead><tbody>`;
    entries.forEach((e, i) => {
      const destBadge = e.destination === 'JOB'
        ? '<span class="badge badge-job-assign">JOB</span>'
        : '<span class="badge badge-stock">STOCK</span>';
      const isRem = parseInt(e.is_remainder);
      html += `<tr style="${isRem?'background:#fffbeb':''}">
        <td>${i+1}</td>
        <td><strong style="font-family:monospace">${esc(e.child_roll_no)}</strong></td>
        <td>${e.slit_width_mm}mm</td>
        <td>${e.slit_length_mtr}m</td>
        <td><span class="badge ${e.mode==='WIDTH'?'badge-stock':'badge-slitting'}">${esc(e.mode)}</span></td>
        <td>${destBadge}</td>
        <td>${esc(e.job_name || e.job_no || '—')}</td>
        <td>${isRem ? '<i class="bi bi-check-circle" style="color:#d97706"></i>' : ''}</td>
      </tr>`;
    });
    html += '</tbody></table>';

    // Signature section
    html += `<div class="slt-sig-grid">
      <div class="slt-sig-box"><p><strong>Operator</strong></p></div>
      <div class="slt-sig-box"><p><strong>QC Inspector</strong></p></div>
      <div class="slt-sig-box"><p><strong>Store Manager</strong></p></div>
    </div>`;

    el.innerHTML = html;
  }

  // ── Machines ───────────────────────────────────────────────
  async function loadMachines() {
    const data = await apiGet('get_machines');
    if (!data.ok) return;
    machines = data.machines || [];
    const sel = document.getElementById('execMachine');
    machines.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.name;
      opt.textContent = m.name + (m.type ? ' (' + m.type + ')' : '');
      sel.appendChild(opt);
    });
  }

  // ── Modal helpers ──────────────────────────────────────────
  function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
  }

  // Close modal on overlay click
  document.addEventListener('click', e => {
    if (e.target.classList.contains('slt-modal-overlay') && e.target.classList.contains('open')) {
      e.target.classList.remove('open');
      document.body.style.overflow = '';
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
      'Pending':'pending','Running':'in-progress','Urgent':'urgent','High':'high','Normal':'normal','Low':'low',
      'Printing':'in-production','Fabrication':'slitting','Packing':'pending',
      'Ready to Dispatch':'approved','Dispatched':'dispatched',
    };
    const cls = map[status] || 'draft';
    return '<span class="badge badge-' + cls + '">' + esc(status) + '</span>';
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
    toggleStockOption: toggleStockOption, updateStockQty: updateStockQty,
    openModal: openModal, closeModal: closeModal,
  };
})();

document.addEventListener('DOMContentLoaded', SLT.init);
</script>
