<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/setup_tables.php';

ensureSlittingTables();

$db = getDB();
$csrf = generateCSRF();
$pageTitle = 'Multi Job Slitting';
$initialRolls = [];
$rollsParam = trim((string)($_GET['rolls'] ?? ''));
$singleRollParam = trim((string)($_GET['rollNo'] ?? ''));
if ($rollsParam !== '') {
  foreach (explode(',', $rollsParam) as $rn) {
    $rn = trim((string)$rn);
    if ($rn !== '') {
      $initialRolls[] = $rn;
    }
  }
}
if ($singleRollParam !== '') {
  $initialRolls[] = $singleRollParam;
}
$initialRolls = array_values(array_unique($initialRolls));
include __DIR__ . '/../../../includes/header.php';
?>

<style>
.mjs-wrap{display:grid;grid-template-columns:1.1fr .9fr;gap:16px}
.mjs-card{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow-sm)}
.mjs-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px}
.mjs-head h3{margin:0;font-size:.9rem;font-weight:800}
.mjs-body{padding:14px 16px}
.mjs-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mjs-input,.mjs-select{width:100%;height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.82rem}
.mjs-input:focus,.mjs-select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(34,197,94,.1)}
.mjs-plans{display:flex;flex-direction:column;gap:8px;max-height:300px;overflow:auto}
.mjs-plan{border:1px solid var(--border);border-radius:8px;padding:10px;cursor:pointer}
.mjs-plan.active{border-color:var(--brand);background:#f0fdf4}
.mjs-plan .n{font-size:.8rem;font-weight:800}
.mjs-plan .m{font-size:.72rem;color:#64748b;margin-top:3px}
.mjs-alloc-table{width:100%;border-collapse:collapse}
.mjs-alloc-table th,.mjs-alloc-table td{padding:7px 8px;border-bottom:1px solid #f1f5f9;font-size:.78rem;text-align:left}
.mjs-alloc-table th{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.mjs-alloc-table input,.mjs-alloc-table select{height:32px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:.78rem;width:100%}
.mjs-alloc-table .mjs-route-select{height:auto;min-height:68px;padding:6px 8px}
.mjs-alloc-list{display:grid;gap:10px;padding:10px;background:#f8fafc}
.mjs-alloc-item{border:1px solid #dbeafe;border-radius:12px;background:#fff;box-shadow:0 6px 18px rgba(15,23,42,.06);overflow:hidden}
.mjs-alloc-item-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;padding:10px 12px;border-bottom:1px solid #eff6ff;background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%)}
.mjs-alloc-item-plan{font-size:.84rem;font-weight:900;color:#0f172a}
.mjs-alloc-item-job{margin-top:2px;font-size:.72rem;color:#64748b}
.mjs-alloc-item-fields{padding:10px 12px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.mjs-field-group{display:grid;gap:6px}
.mjs-field-group-full{grid-column:1 / -1}
.mjs-field-label{font-size:.64rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.mjs-inline-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.mjs-inline-controls input,.mjs-inline-controls select{flex:1 1 160px;min-width:120px}
.mjs-unit-chip{display:inline-flex;align-items:center;height:32px;padding:0 10px;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;font-size:.7rem;font-weight:800;color:#475569}
.mjs-route-cell{min-width:240px}
.mjs-route-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.mjs-route-card{display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;transition:all .15s}
.mjs-route-card:hover{border-color:#86efac;background:#f0fdf4}
.mjs-route-card.active{border-color:var(--brand);background:#f0fdf4;box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.mjs-route-card input{margin-top:2px;width:auto;height:auto}
.mjs-route-card span{font-size:.74rem;font-weight:700;color:#334155;line-height:1.2}
.mjs-plan-cell{min-width:280px}
.mjs-slit-box{margin-top:8px;padding:10px;border:1px solid #dcfce7;border-radius:10px;background:#f0fdf4;overflow-x:auto}
.mjs-slit-head{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:8px;margin-bottom:8px}
.mjs-slit-title{font-size:.68rem;font-weight:900;color:#166534;text-transform:uppercase;letter-spacing:.05em;min-width:0}
.mjs-slit-grid{display:grid;gap:6px;min-width:860px}
.mjs-slit-row{display:grid;grid-template-columns:72px 100px 64px 118px 110px 180px auto;gap:6px;align-items:center}
.mjs-slit-row span{font-size:.7rem;font-weight:800;color:#475569}
.mjs-slit-row input,.mjs-slit-row select{width:100%;height:32px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:.74rem;min-width:0}
.mjs-slit-row select[data-run-k="plan_key"]{max-width:180px}
.mjs-slit-row .mjs-btn{padding:7px 10px;font-size:.72rem}
.mjs-slit-row-head{font-size:.64rem;font-weight:900;color:#166534;text-transform:uppercase;letter-spacing:.05em}
.mjs-slit-row-head div{padding:0 2px}
.mjs-slit-summary{font-size:.68rem;color:#64748b;margin-top:8px}
.mjs-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.mjs-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mjs-check-card{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;transition:all .18s}
.mjs-check-card:hover{border-color:#86efac;background:#f0fdf4}
.mjs-check-card.active{border-color:var(--brand);background:#f0fdf4;box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.mjs-check-card input{margin-top:2px}
.mjs-check-card strong{display:block;font-size:.8rem;color:var(--text-main)}
.mjs-check-card span{display:block;font-size:.7rem;color:var(--text-muted);margin-top:2px}
.mjs-choice-empty{padding:10px 12px;border:1px dashed var(--border);border-radius:10px;font-size:.76rem;color:var(--text-muted);background:#f8fafc}
.mjs-dept-summary{margin-top:8px;padding:10px 12px;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4}
.mjs-dept-summary strong{display:block;font-size:.76rem;color:#166534;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.mjs-dept-summary div{font-size:.8rem;font-weight:700;color:#14532d}
.mjs-dept-summary span{display:block;font-size:.72rem;color:#166534;margin-top:4px}
.mjs-dept-summary.is-empty{border-color:#e2e8f0;background:#f8fafc}
.mjs-dept-summary.is-empty strong,.mjs-dept-summary.is-empty div,.mjs-dept-summary.is-empty span{color:#64748b}
.mjs-btn{border:none;border-radius:8px;padding:9px 14px;font-size:.78rem;font-weight:800;cursor:pointer}
.mjs-btn.primary{background:#16a34a;color:#fff}
.mjs-btn.secondary{background:#0f172a;color:#fff}
.mjs-btn.light{background:#fff;border:1px solid var(--border);color:#334155}
.mjs-btn.remove{background:#dc2626;border:1px solid #b91c1c;color:#fff}
.mjs-btn.remove:hover{background:#b91c1c}
.mjs-btn.remove:disabled{background:#fecaca;border-color:#fca5a5;color:#7f1d1d;cursor:not-allowed}
.mjs-btn.add-part{background:#f97316;border:1px solid #ea580c;color:#fff;box-shadow:0 2px 8px rgba(249,115,22,.35)}
.mjs-btn.add-part:hover{background:#ea580c}
.mjs-pill{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:800;background:#f8fafc;border:1px solid var(--border);color:#475569}
.mjs-kpi{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.mjs-kpi .box{border:1px solid var(--border);border-radius:10px;padding:10px;background:#f8fafc}
.mjs-kpi .k{font-size:.63rem;text-transform:uppercase;color:#64748b;font-weight:800;letter-spacing:.05em}
.mjs-kpi .v{font-size:1rem;font-weight:900;color:#0f172a;margin-top:4px}
.mjs-kpi-sub{display:block;margin-top:4px;font-size:.7rem;font-weight:700;color:#475569}
.mjs-log{margin-top:10px;border:1px solid var(--border);border-radius:8px;padding:10px;max-height:220px;overflow:auto;background:#f8fafc;font-size:.76rem;line-height:1.45}
.mjs-ok{color:#166534}
.mjs-bad{color:#991b1b}
.mjs-card-right{border:1px solid #dbeafe;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%)}
.mjs-head-right{background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);color:#e2e8f0;border-bottom:none;border-top-left-radius:12px;border-top-right-radius:12px}
.mjs-head-right h3{color:#fff;letter-spacing:.01em}
.mjs-subtitle{font-size:.72rem;color:#cbd5e1;font-weight:600;margin-top:3px}
.mjs-remain-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);color:#fff}
.mjs-alloc-shell{background:#fff;border:1px solid #dbeafe;border-radius:12px;overflow:hidden;box-shadow:0 8px 22px rgba(15,23,42,.06)}
.mjs-alloc-shell-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
.mjs-alloc-shell-title{font-size:.68rem;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#1e3a8a}
.mjs-alloc-shell-note{font-size:.7rem;color:#475569}
.mjs-alloc-scroll{max-height:420px;overflow:auto}
.mjs-alloc-table thead th{position:sticky;top:0;z-index:2;background:#f8fafc}
.mjs-kpi-modern .box{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px solid #dbeafe}
.mjs-jobwise{margin-top:12px;border:1px solid #dbeafe;border-radius:12px;background:#fff;overflow:hidden}
.mjs-jobwise-head{padding:10px 12px;border-bottom:1px solid #e2e8f0;background:#f8fafc;font-size:.68rem;font-weight:900;letter-spacing:.06em;text-transform:uppercase;color:#1e3a8a}
.mjs-jobwise-body{padding:10px 12px}
.mjs-jobwise-table{width:100%;border-collapse:collapse}
.mjs-jobwise-table th,.mjs-jobwise-table td{padding:7px 8px;border-bottom:1px solid #f1f5f9;font-size:.76rem;text-align:left;vertical-align:top}
.mjs-jobwise-table th{font-size:.64rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.mjs-jobwise-total{background:#f8fafc;font-weight:800;color:#0f172a}
.mjs-jobwise-empty{font-size:.76rem;color:#64748b}
.mjs-jobwise-note{font-size:.7rem;color:#64748b;margin-bottom:8px}
.mjs-jobwise-card{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-top:8px}
.mjs-jobwise-card-head{padding:8px 10px;font-size:.66rem;font-weight:900;letter-spacing:.05em;text-transform:uppercase}
.mjs-jobwise-card-head.job{background:#eff6ff;color:#1e3a8a}
.mjs-jobwise-card-head.stock{background:#fff7ed;color:#9a3412}
.mjs-jobwise-row-job td{background:#f8fbff}
.mjs-jobwise-row-stock td{background:#fffaf5}
.mjs-tag{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:.66rem;font-weight:800;margin:1px 3px 1px 0}
.mjs-tag.job{background:#dbeafe;color:#1e3a8a}
.mjs-tag.stock{background:#fed7aa;color:#9a3412}
.mjs-tag.status-job{background:#dcfce7;color:#166534}
.mjs-tag.status-stock{background:#fee2e2;color:#991b1b}
.mjs-actions-modern{padding:12px;border:1px solid #dbeafe;border-radius:12px;background:#fff;gap:12px}
.mjs-actions-label{font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:900}
.mjs-actions-right{display:flex;gap:8px;flex-wrap:wrap}
.mjs-log-modern{background:#0b1220;color:#e2e8f0;border:1px solid #1e293b;max-height:260px}
.mjs-log-modern .mjs-ok{color:#86efac}
.mjs-log-modern .mjs-bad{color:#fca5a5}
.mjs-modal-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(15,23,42,.55);backdrop-filter:blur(3px)}
.mjs-modal-overlay.open{display:flex;align-items:center;justify-content:center}
.mjs-modal{background:#fff;border-radius:16px;width:min(1180px,96vw);max-height:88vh;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,.25);display:flex;flex-direction:column}
.mjs-modal-head{background:#0f172a;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.mjs-modal-head h3{margin:0;font-size:.92rem;font-weight:800}
.mjs-modal-close{border:none;background:none;color:#cbd5e1;font-size:1.25rem;cursor:pointer}
.mjs-modal-close:hover{color:#fff}
.mjs-modal-body{padding:16px 20px;overflow:auto;flex:1;background:#f8fafc}
.mjs-modal-foot{padding:12px 20px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff}
.mjs-modal-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.mjs-parent-table-wrap{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.mjs-parent-table{width:100%;border-collapse:collapse}
.mjs-parent-table th,.mjs-parent-table td{padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:.78rem;text-align:left;vertical-align:middle}
.mjs-parent-table th{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#f8fafc}
.mjs-parent-filter-row th{background:#fff;padding:8px 10px}
.mjs-parent-filter-row input,.mjs-parent-filter-row select{width:100%;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:.74rem}
.mjs-parent-table tr:hover{background:#f0fdf4}
.mjs-parent-table .pick-btn{border:none;border-radius:8px;background:#16a34a;color:#fff;padding:7px 10px;font-size:.72rem;font-weight:800;cursor:pointer}
.mjs-parent-table .pick-btn:hover{background:#15803d}
.mjs-empty{padding:24px 16px;text-align:center;color:#64748b;font-size:.8rem}
.mjs-pager{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.mjs-parent-list{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.mjs-parent-chip{border:1px solid var(--border);background:#fff;border-radius:999px;padding:6px 10px;font-size:.74rem;display:flex;align-items:center;gap:8px;cursor:pointer}
.mjs-parent-chip.active{border-color:var(--brand);background:#f0fdf4}
.mjs-parent-chip .close{border:none;background:none;color:#991b1b;font-weight:800;cursor:pointer;line-height:1}
@media(max-width:1100px){.mjs-wrap{grid-template-columns:1fr}.mjs-alloc-scroll{max-height:none}}
@media(max-width:900px){
  .mjs-alloc-item-fields{grid-template-columns:1fr}
  .mjs-inline-controls input,.mjs-inline-controls select{flex:1 1 100%}
  .mjs-slit-grid{min-width:0}
  .mjs-slit-row{grid-template-columns:1fr 1fr}
  .mjs-slit-row span{grid-column:1 / -1}
  .mjs-route-grid{grid-template-columns:1fr}
  .mjs-choice-grid{grid-template-columns:1fr}
  .mjs-actions-modern{display:grid;grid-template-columns:1fr}
  .mjs-actions-right{display:grid;grid-template-columns:1fr}
  .mjs-actions-right .mjs-btn{width:100%}
  .mjs-kpi{grid-template-columns:1fr}
  .mjs-head-right{align-items:flex-start}
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <a href="<?= BASE_URL ?>/modules/inventory/slitting/index.php">Slitting</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Multi Job Slitting</span>
</div>

<div class="page-header">
  <div>
    <h1><i class="bi bi-diagram-3"></i> Multi Job Slitting</h1>
    <p>One parent roll split across multiple plans with one-click job card creation.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/modules/inventory/slitting/index.php"><i class="bi bi-arrow-left"></i> Back to Single Slitting</a>
  </div>
</div>

<div class="mjs-wrap">
  <div class="mjs-card">
    <div class="mjs-head"><h3>1) Parent Roll + Plan Picker</h3><span class="mjs-pill" id="mjsPlanCount">0 selected</span></div>
    <div class="mjs-body">
      <div class="mjs-grid" style="margin-bottom:10px;grid-template-columns:1fr">
        <div>
          <label style="font-size:.68rem;font-weight:800;color:#64748b;text-transform:uppercase">Parent Roll No</label>
          <input id="mjsParentRoll" class="mjs-input" placeholder="Type parent roll no and press Enter">
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-bottom:10px">
        <button class="mjs-btn light" id="mjsLoadParent"><i class="bi bi-search"></i> Browse Parent Stock</button>
        <button class="mjs-btn light" id="mjsAddParent"><i class="bi bi-plus-circle"></i> Add Another Roll</button>
        <button class="mjs-btn light" id="mjsLoadPlans"><i class="bi bi-list-task"></i> Load Planning Queue</button>
      </div>
      <div id="mjsParentList" class="mjs-parent-list"></div>
      <div id="mjsParentMeta" style="margin-bottom:10px;font-size:.78rem;color:#475569"></div>
      <div class="mjs-plans" id="mjsPlanList">
        <div style="font-size:.78rem;color:#64748b">Load planning queue to select multiple plans.</div>
      </div>
    </div>
  </div>

  <div class="mjs-card mjs-card-right">
    <div class="mjs-head mjs-head-right">
      <div>
        <h3>2) Allocation Matrix + Execute</h3>
        <div class="mjs-subtitle">Set width, route, slit quantity and length, then validate before execution</div>
      </div>
      <span class="mjs-pill mjs-remain-pill" id="mjsRemainBadge">Remaining: 0 mm</span>
    </div>
    <div class="mjs-body">
      <div class="mjs-alloc-shell">
        <div class="mjs-alloc-shell-head">
          <div class="mjs-alloc-shell-title">Active Allocations</div>
          <div class="mjs-alloc-shell-note">Tip: Use Slit Runs in each plan to define Qty + Length</div>
        </div>
        <div class="mjs-alloc-scroll">
          <div id="mjsAllocBody" class="mjs-alloc-list">
            <div class="mjs-empty">Select plans from left panel.</div>
          </div>
        </div>
      </div>

      <div class="mjs-jobwise">
        <div class="mjs-jobwise-head">Job Wise Details (PLN)</div>
        <div class="mjs-jobwise-body" id="mjsJobWiseBody">
          <div class="mjs-jobwise-empty">No JOB rows available yet.</div>
        </div>
      </div>

      <div class="mjs-kpi mjs-kpi-modern" style="margin-top:12px">
        <div class="box"><div class="k">Parent Width</div><div class="v" id="mjsParentWidth">0 mm</div></div>
        <div class="box"><div class="k">Allocated</div><div class="v" id="mjsAllocated">0 mm</div></div>
        <div class="box"><div class="k">Remainder</div><div class="v" id="mjsRemainder">0 mm</div></div>
      </div>

      <div style="margin-top:12px;padding:12px;border:1px solid #dbeafe;border-radius:12px;background:#fff">
        <div style="font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:900;margin-bottom:8px">Job Card Departments</div>
        <div id="mjsDeptChooser" class="mjs-choice-grid"></div>
        <div id="mjsDeptIssueHint" class="mjs-dept-summary is-empty" style="margin-top:10px">
          <strong>Job Issue Department</strong>
          <div>No department selected yet</div>
          <span>Only selected departments will receive auto-issued job cards after execution.</span>
        </div>
      </div>

      <div class="mjs-actions mjs-actions-modern">
        <div style="display:flex;align-items:center;gap:8px">
          <label class="mjs-actions-label">Remainder Action</label>
          <select id="mjsRemainderAction" class="mjs-select" style="width:140px;height:34px">
            <option value="STOCK">STOCK</option>
            <option value="ADJUST">ADJUST</option>
          </select>
        </div>
        <div class="mjs-actions-right">
          <button class="mjs-btn light" id="mjsValidate">Validate</button>
          <button class="mjs-btn secondary" id="mjsExecute">Execute Multi-Plan Batch</button>
        </div>
      </div>

      <div class="mjs-log mjs-log-modern" id="mjsLog"></div>
    </div>
  </div>
</div>

<div class="mjs-modal-overlay" id="mjsParentModal">
  <div class="mjs-modal">
    <div class="mjs-modal-head">
      <h3><i class="bi bi-box-seam"></i> Select Parent Roll From Paper Stock</h3>
      <button type="button" class="mjs-modal-close" id="mjsParentModalClose">&times;</button>
    </div>
    <div class="mjs-modal-body">
      <div class="mjs-modal-tools">
        <button class="mjs-btn light" id="mjsParentSearchBtn"><i class="bi bi-search"></i> Apply Filters</button>
        <button class="mjs-btn light" id="mjsParentFilterReset"><i class="bi bi-arrow-counterclockwise"></i> Reset Filters</button>
        <span class="mjs-pill" id="mjsParentCount">0 rows</span>
      </div>
      <div class="mjs-parent-table-wrap">
        <table class="mjs-parent-table">
          <thead>
            <tr>
              <th>Roll No</th>
              <th>Paper</th>
              <th>Supplier</th>
              <th>Width</th>
              <th>Length</th>
              <th>Status</th>
              <th>Job Ref</th>
              <th></th>
            </tr>
            <tr class="mjs-parent-filter-row">
              <th><input id="mjsParentColRoll" placeholder="Roll no"></th>
              <th><input id="mjsParentColPaper" placeholder="Material type"></th>
              <th><input id="mjsParentColCompany" placeholder="Company"></th>
              <th><input id="mjsParentColWidth" type="number" min="0" step="0.01" placeholder="Min mm"></th>
              <th></th>
              <th><input id="mjsParentColStatus" placeholder="Status"></th>
              <th><input id="mjsParentColJob" placeholder="Job ref"></th>
              <th></th>
            </tr>
          </thead>
          <tbody id="mjsParentTableBody">
            <tr><td colspan="8" class="mjs-empty">Apply filters to load available parent rolls.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="mjs-modal-foot">
      <div id="mjsParentModalSummary" style="font-size:.78rem;color:#64748b">Only non-consumed paper stock is shown here.</div>
      <div class="mjs-pager">
        <button class="mjs-btn light" id="mjsParentPrev">Prev</button>
        <span class="mjs-pill" id="mjsParentPager">Page 1 / 1</span>
        <button class="mjs-btn light" id="mjsParentNext">Next</button>
        <button class="mjs-btn light" id="mjsParentModalDone">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="mjs-modal-overlay" id="mjsSuccessModal">
  <div class="mjs-modal" style="max-width:760px">
    <div class="mjs-modal-head">
      <h3><i class="bi bi-check2-circle"></i> Multi Job Slitting Completed</h3>
      <button type="button" class="mjs-modal-close" id="mjsSuccessModalClose">&times;</button>
    </div>
    <div class="mjs-modal-body">
      <div id="mjsSuccessContent" style="display:flex;flex-direction:column;gap:10px;font-size:.82rem;color:#334155"></div>
    </div>
    <div class="mjs-modal-foot">
      <div style="font-size:.78rem;color:#64748b">Planning queue has been refreshed after execution.</div>
      <button class="mjs-btn secondary" id="mjsSuccessDone">Done</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>

<script>
(() => {
  const API = '<?= BASE_URL ?>/modules/inventory/slitting/api.php';
  const CSRF = '<?= e($csrf) ?>';
  const PRESELECTED_ROLLS = <?= json_encode($initialRolls, JSON_UNESCAPED_UNICODE) ?>;
  const operator = '<?= e(trim((string)($_SESSION['user_name'] ?? '')) ?: 'Operator') ?>';

  let parentRolls = [];
  let activeParentKey = '';
  let plans = [];
  let selectedPlanMap = {};
  let rollConfigs = {};
  let departmentOptions = [];
  let selectedExecutionDepartments = [];
  let parentBrowseRows = [];
  let parentBrowsePage = 1;
  let parentBrowsePages = 1;
  let parentBrowseTotal = 0;
  const parentBrowseLimit = 50;

  const el = {
    parentRoll: document.getElementById('mjsParentRoll'),
    loadParent: document.getElementById('mjsLoadParent'),
    addParent: document.getElementById('mjsAddParent'),
    loadPlans: document.getElementById('mjsLoadPlans'),
    parentList: document.getElementById('mjsParentList'),
    parentMeta: document.getElementById('mjsParentMeta'),
    planList: document.getElementById('mjsPlanList'),
    planCount: document.getElementById('mjsPlanCount'),
    allocBody: document.getElementById('mjsAllocBody'),
    jobWiseBody: document.getElementById('mjsJobWiseBody'),
    parentWidth: document.getElementById('mjsParentWidth'),
    allocated: document.getElementById('mjsAllocated'),
    remainder: document.getElementById('mjsRemainder'),
    remainBadge: document.getElementById('mjsRemainBadge'),
    remainderAction: document.getElementById('mjsRemainderAction'),
    deptChooser: document.getElementById('mjsDeptChooser'),
    deptIssueHint: document.getElementById('mjsDeptIssueHint'),
    validate: document.getElementById('mjsValidate'),
    execute: document.getElementById('mjsExecute'),
    log: document.getElementById('mjsLog'),
    parentModal: document.getElementById('mjsParentModal'),
    parentModalClose: document.getElementById('mjsParentModalClose'),
    parentModalDone: document.getElementById('mjsParentModalDone'),
    parentColRoll: document.getElementById('mjsParentColRoll'),
    parentColPaper: document.getElementById('mjsParentColPaper'),
    parentColCompany: document.getElementById('mjsParentColCompany'),
    parentColWidth: document.getElementById('mjsParentColWidth'),
    parentColStatus: document.getElementById('mjsParentColStatus'),
    parentColJob: document.getElementById('mjsParentColJob'),
    parentSearchBtn: document.getElementById('mjsParentSearchBtn'),
    parentFilterReset: document.getElementById('mjsParentFilterReset'),
    parentCount: document.getElementById('mjsParentCount'),
    parentTableBody: document.getElementById('mjsParentTableBody'),
    parentModalSummary: document.getElementById('mjsParentModalSummary'),
    parentPrev: document.getElementById('mjsParentPrev'),
    parentNext: document.getElementById('mjsParentNext'),
    parentPager: document.getElementById('mjsParentPager'),
    successModal: document.getElementById('mjsSuccessModal'),
    successModalClose: document.getElementById('mjsSuccessModalClose'),
    successDone: document.getElementById('mjsSuccessDone'),
    successContent: document.getElementById('mjsSuccessContent'),
  };

  function log(msg, ok=true) {
    const row = document.createElement('div');
    row.className = ok ? 'mjs-ok' : 'mjs-bad';
    row.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    el.log.prepend(row);
  }

  function esc(s){return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}

  function getPlanKeyFromObj(obj) {
    const id = parseInt(obj && obj.planning_id ? obj.planning_id : (obj && obj.id ? obj.id : 0), 10) || 0;
    if (id > 0) return 'id:' + id;
    const planNo = String((obj && (obj.plan_no || obj.job_no)) || '').trim().toUpperCase();
    return planNo ? ('plan:' + planNo) : '';
  }

  function getActiveParent() {
    if (!activeParentKey) return null;
    return parentRolls.find(r => String(r.roll_no) === String(activeParentKey)) || null;
  }

  function selectedPlans() {
    return plans.filter(p => {
      const idKey = 'id:' + (parseInt(p.id || 0, 10) || 0);
      const planKey = 'plan:' + String(p.job_no || '').trim().toUpperCase();
      return !!selectedPlanMap[idKey] || (planKey !== 'plan:' && !!selectedPlanMap[planKey]);
    });
  }

  function firstSelectedPlanChoice() {
    return planChoicesForRuns()[0] || {
      planning_id: 0,
      plan_no: '',
      job_name: '',
      job_size: '',
      key: ''
    };
  }

  function createDefaultRow(parentRoll) {
    const parentLength = parseFloat(parentRoll && parentRoll.length_mtr ? parentRoll.length_mtr : 0) || 0;
    const fallback = firstSelectedPlanChoice();
    return {
      width_mm: 0,
      length_mtr: parentLength,
      qty: 1,
      destination: fallback.plan_no ? 'JOB' : 'STOCK',
      planning_id: fallback.planning_id,
      plan_no: fallback.plan_no,
      job_name: fallback.job_name,
      job_size: fallback.job_size,
    };
  }

  function ensureRollConfig(roll) {
    const rollNo = String(roll && roll.roll_no ? roll.roll_no : '').trim();
    if (!rollNo) return null;
    if (!rollConfigs[rollNo]) {
      rollConfigs[rollNo] = {
        rows: [createDefaultRow(roll)],
      };
    }
    if (!Array.isArray(rollConfigs[rollNo].rows) || !rollConfigs[rollNo].rows.length) {
      rollConfigs[rollNo].rows = [createDefaultRow(roll)];
    }
    return rollConfigs[rollNo];
  }

  function planKeyFromRow(row) {
    return getPlanKeyFromObj(row || {});
  }

  function buildAllocationsFromRows(roll) {
    const rollNo = String(roll && roll.roll_no ? roll.roll_no : '').trim();
    const parentLength = parseFloat(roll && roll.length_mtr ? roll.length_mtr : 0) || 0;
    const cfg = rollConfigs[rollNo];
    const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
    const grouped = [];
    const jobGroups = {};
    const stockRows = [];

    rows.forEach(row => {
      const width = parseFloat(row.width_mm || 0) || 0;
      const length = parentLength;
      const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
      const dest = String(row.destination || '').toUpperCase() === 'STOCK' ? 'STOCK' : 'JOB';
      if (width <= 0 || length <= 0) return;
      const normalizedRow = {
        width_mm: width,
        length_mtr: length,
        qty,
        destination: dest,
        planning_id: dest === 'JOB' ? (parseInt(row.planning_id || 0, 10) || 0) : 0,
        plan_no: dest === 'JOB' ? String(row.plan_no || '').trim() : '',
        job_name: dest === 'JOB' ? String(row.job_name || '').trim() : '',
        job_size: dest === 'JOB' ? String(row.job_size || '').trim() : '',
      };
      if (dest === 'STOCK') {
        stockRows.push(normalizedRow);
        return;
      }
      const key = planKeyFromRow(normalizedRow);
      if (!key) {
        stockRows.push(Object.assign({}, normalizedRow, {destination: 'STOCK', planning_id: 0, plan_no: '', job_name: '', job_size: ''}));
        return;
      }
      if (!jobGroups[key]) {
        jobGroups[key] = {
          planning_id: normalizedRow.planning_id,
          plan_no: normalizedRow.plan_no,
          job_name: normalizedRow.job_name,
          job_size: normalizedRow.job_size,
          destination: 'JOB',
          department_route: selectedDepartmentRoute(),
          slit_runs: [],
        };
      }
      jobGroups[key].slit_runs.push(normalizedRow);
    });

    Object.keys(jobGroups).forEach(key => {
      const group = jobGroups[key];
      group.allocated_width_mm = group.slit_runs.reduce((sum, row) => {
        const w = parseFloat(row.width_mm || 0) || 0;
        const q = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        return sum + (w * q);
      }, 0);
      grouped.push(group);
    });

    if (stockRows.length) {
      grouped.push({
        planning_id: 0,
        plan_no: '',
        job_name: '',
        job_size: '',
        destination: 'STOCK',
        department_route: selectedDepartmentRoute(),
        allocated_width_mm: stockRows.reduce((sum, row) => {
          const w = parseFloat(row.width_mm || 0) || 0;
          const q = Math.max(1, parseInt(row.qty || 1, 10) || 1);
          return sum + (w * q);
        }, 0),
        slit_runs: stockRows,
      });
    }

    return grouped.map((group, idx) => Object.assign({}, group, {allocation_sequence: idx + 1}));
  }

  function buildCombinedParentPayloads() {
    return parentRolls.map((roll) => ({
      parent_roll_no: String(roll && roll.roll_no ? roll.roll_no : '').trim(),
      plan_allocations: buildAllocationsFromRows(roll)
    })).filter(item => item.parent_roll_no && Array.isArray(item.plan_allocations) && item.plan_allocations.length > 0);
  }

  function planChoicesForRuns() {
    return selectedPlans().map(p => ({
      key: getPlanKeyFromObj(p),
      planning_id: parseInt(p.id || 0, 10) || 0,
      plan_no: String(p.job_no || '').trim(),
      job_name: String(p.job_name || '').trim(),
      job_size: String(p.label_length_mm || p.label_width_mm || p.paper_size || '').trim(),
    })).filter(p => p.key && p.plan_no);
  }

  function runPlanKey(run, fallbackAllocation) {
    const fromRun = getPlanKeyFromObj(run || {});
    if (fromRun) return fromRun;
    return getPlanKeyFromObj(fallbackAllocation || {});
  }

  function parseDepartmentList(value) {
    const raw = Array.isArray(value)
      ? value.map(v => String(v || '').trim()).filter(Boolean)
      : String(value || '').split(',').map(v => v.trim()).filter(Boolean);
    if (!departmentOptions.length) return Array.from(new Set(raw));
    const out = [];
    raw.forEach(part => {
      const match = departmentOptions.find(dep => String(dep).toLowerCase() === String(part).toLowerCase());
      if (match && !out.includes(match)) out.push(match);
    });
    return out;
  }

  function selectedDepartmentRoute() {
    const picked = parseDepartmentList(selectedExecutionDepartments);
    if (picked.length) return picked.join(', ');
    return parseRoute('');
  }

  function renderDepartmentIssueHint() {
    if (!el.deptIssueHint) return;
    const picked = parseDepartmentList(selectedExecutionDepartments);
    const isEmpty = !picked.length;
    el.deptIssueHint.classList.toggle('is-empty', isEmpty);
    const title = el.deptIssueHint.querySelector('div');
    const desc = el.deptIssueHint.querySelector('span');
    if (title) title.textContent = isEmpty ? 'No department selected yet' : picked.join(', ');
    if (desc) {
      desc.textContent = isEmpty
        ? 'Select one or more departments. Final job cards will be generated only for selected departments.'
        : 'After execution, job cards will be issued only to selected departments below.';
    }
  }

  function renderDepartmentChooser() {
    if (!el.deptChooser) return;
    if (!departmentOptions.length) {
      el.deptChooser.innerHTML = '<div class="mjs-choice-empty">No departments available from Machine Master.</div>';
      renderDepartmentIssueHint();
      return;
    }
    const picked = parseDepartmentList(selectedExecutionDepartments);
    el.deptChooser.innerHTML = departmentOptions.map((dept, idx) => {
      const checked = picked.includes(dept);
      return `<label class="mjs-check-card ${checked ? 'active' : ''}">
        <input type="checkbox" data-dept-index="${idx}" ${checked ? 'checked' : ''}>
        <div><strong>${esc(dept)}</strong><span>You can add or uncheck before final job card generation</span></div>
      </label>`;
    }).join('');
    el.deptChooser.querySelectorAll('[data-dept-index]').forEach(inp => {
      inp.addEventListener('change', function(){
        const dept = departmentOptions[Number(this.getAttribute('data-dept-index') || 0)] || '';
        if (!dept) return;
        if (this.checked) {
          if (!selectedExecutionDepartments.includes(dept)) selectedExecutionDepartments.push(dept);
        } else {
          selectedExecutionDepartments = selectedExecutionDepartments.filter(item => String(item).toLowerCase() !== String(dept).toLowerCase());
        }
        renderDepartmentChooser();
      });
    });
    renderDepartmentIssueHint();
  }

  function renderParentRollChips() {
    if (!parentRolls.length) {
      el.parentList.innerHTML = '<span style="font-size:.74rem;color:#64748b">No parent roll selected yet.</span>';
      return;
    }
    el.parentList.innerHTML = parentRolls.map(r => {
      const rollNo = String(r.roll_no || '');
      const active = String(activeParentKey) === rollNo;
      return `<div class="mjs-parent-chip ${active ? 'active' : ''}" data-parent-switch="${esc(rollNo)}">
        <span>${esc(rollNo)}</span>
        <button type="button" class="close" data-parent-remove="${esc(rollNo)}">&times;</button>
      </div>`;
    }).join('');
  }

  function refreshParentMeta() {
    const active = getActiveParent();
    if (!active) {
      el.parentMeta.innerHTML = '<span class="mjs-bad">Pick at least one parent roll</span>';
      return;
    }
    el.parentMeta.innerHTML = `<strong>Active: ${esc(active.roll_no)}</strong> | ${esc(active.paper_type)} | ${esc(active.company)} | ${esc(active.width_mm)}mm x ${esc(active.length_mtr)}m`;
  }

  function syncAllocationsFromSelectionAllRolls() {
    renderAllocations();
  }

  function addOrActivateParentRoll(row) {
    if (!row || !row.roll_no) return;
    const rollNo = String(row.roll_no || '').trim();
    if (!rollNo) return;
    const exists = parentRolls.find(r => String(r.roll_no) === rollNo);
    if (!exists) {
      parentRolls.push(row);
      ensureRollConfig(row);
      log('Parent added: ' + rollNo, true);
    }
    activeParentKey = rollNo;
    renderParentRollChips();
    refreshParentMeta();
    renderAllocations();
  }

  function removeParentRoll(rollNo) {
    const target = String(rollNo || '').trim();
    if (!target) return;
    parentRolls = parentRolls.filter(r => String(r.roll_no) !== target);
    delete rollConfigs[target];
    if (String(activeParentKey) === target) {
      activeParentKey = parentRolls.length ? String(parentRolls[0].roll_no) : '';
    }
    renderParentRollChips();
    refreshParentMeta();
    renderAllocations();
    refreshTotalsUI();
  }

  async function apiGet(action, params={}) {
    const q = new URLSearchParams(params);
    q.set('action', action);
    const res = await fetch(API + '?' + q.toString(), {credentials:'same-origin'});
    return await res.json();
  }

  async function apiPost(action, formObj={}) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', action);
    Object.keys(formObj).forEach(k => fd.append(k, formObj[k]));
    const res = await fetch(API, {method:'POST', body:fd, credentials:'same-origin'});
    return await res.json();
  }

  function openParentModal(searchTerm='') {
    el.parentModal.classList.add('open');
    if (String(searchTerm || '').trim() !== '') {
      el.parentColRoll.value = String(searchTerm || '').trim();
    }
    parentBrowsePage = 1;
    browseParentRolls(parentBrowsePage);
  }

  function closeParentModal() {
    el.parentModal.classList.remove('open');
  }

  function openSuccessModal(data) {
    const childRolls = Array.isArray(data.child_rolls) ? data.child_rolls : [];
    const jobCards = Array.isArray(data.created_job_cards) ? data.created_job_cards : [];
    el.successContent.innerHTML = `
      <div style="padding:12px 14px;border:1px solid #bbf7d0;border-radius:12px;background:#f0fdf4;color:#166534;font-weight:800">
        Batch ${esc(data.batch_no || '')} executed successfully.
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
        <div class="box" style="border:1px solid var(--border);border-radius:10px;padding:12px;background:#f8fafc"><div class="k" style="font-size:.65rem;text-transform:uppercase;color:#64748b">Parent Roll</div><div class="v" style="font-size:1rem;font-weight:900;color:#0f172a">${esc(data.parent_roll || '')}</div></div>
        <div class="box" style="border:1px solid var(--border);border-radius:10px;padding:12px;background:#f8fafc"><div class="k" style="font-size:.65rem;text-transform:uppercase;color:#64748b">Child Rolls</div><div class="v" style="font-size:1rem;font-weight:900;color:#0f172a">${childRolls.length}</div></div>
        <div class="box" style="border:1px solid var(--border);border-radius:10px;padding:12px;background:#f8fafc"><div class="k" style="font-size:.65rem;text-transform:uppercase;color:#64748b">Job Cards</div><div class="v" style="font-size:1rem;font-weight:900;color:#0f172a">${jobCards.length}</div></div>
      </div>
      <div style="padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:#fff">
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:8px">Created Job Cards</div>
        <div>${jobCards.length ? esc(jobCards.map(j => (j.job_no || '') + ' [' + (j.type || '') + ']').join(', ')) : 'None'}</div>
      </div>
      <div style="padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:#fff">
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:8px">Remainder</div>
        <div>${esc(String(data.remainder_width_mm || 0))} mm (${esc(data.remainder_action || 'STOCK')})</div>
      </div>`;
    el.successModal.classList.add('open');
  }

  function closeSuccessModal() {
    el.successModal.classList.remove('open');
  }

  function renderParentRows() {
    if (!parentBrowseRows.length) {
      el.parentTableBody.innerHTML = '<tr><td colspan="8" class="mjs-empty">No matching parent rolls found.</td></tr>';
      el.parentCount.textContent = '0 rows';
      el.parentPager.textContent = 'Page ' + parentBrowsePage + ' / ' + parentBrowsePages;
      el.parentPrev.disabled = parentBrowsePage <= 1;
      el.parentNext.disabled = parentBrowsePage >= parentBrowsePages;
      return;
    }

    el.parentTableBody.innerHTML = parentBrowseRows.map((row, idx) => `
      <tr>
        <td><strong>${esc(row.roll_no || '')}</strong></td>
        <td>${esc(row.paper_type || '')}</td>
        <td>${esc(row.company || '')}</td>
        <td>${esc(row.width_mm || '')} mm</td>
        <td>${esc(row.length_mtr || '')} m</td>
        <td>${esc(row.status || '')}</td>
        <td>${esc(row.job_no || row.job_name || '')}</td>
        <td><button type="button" class="pick-btn" data-parent-pick="${idx}">Pick</button></td>
      </tr>
    `).join('');
    const start = parentBrowseTotal > 0 ? (((parentBrowsePage - 1) * parentBrowseLimit) + 1) : 0;
    const end = start > 0 ? (start + parentBrowseRows.length - 1) : 0;
    el.parentCount.textContent = start > 0
      ? ('Showing ' + start + '-' + end + ' of ' + parentBrowseTotal)
      : '0 rows';
    el.parentPager.textContent = 'Page ' + parentBrowsePage + ' / ' + parentBrowsePages;
    el.parentPrev.disabled = parentBrowsePage <= 1;
    el.parentNext.disabled = parentBrowsePage >= parentBrowsePages;

    el.parentTableBody.querySelectorAll('[data-parent-pick]').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.getAttribute('data-parent-pick') || '-1', 10);
        if (idx < 0 || !parentBrowseRows[idx]) return;
        pickParentRoll(parentBrowseRows[idx]);
      });
    });
  }

  async function browseParentRolls(page=1) {
    el.parentTableBody.innerHTML = '<tr><td colspan="8" class="mjs-empty">Loading available parent rolls…</td></tr>';
    el.parentPager.textContent = 'Loading...';
    const data = await apiGet('browse_parent_rolls', {
      roll_no: String(el.parentColRoll.value || '').trim(),
      paper_type: String(el.parentColPaper.value || '').trim(),
      company: String(el.parentColCompany.value || '').trim(),
      width_mm: parseFloat(el.parentColWidth.value || 0) || 0,
      status: String(el.parentColStatus.value || '').trim(),
      job_ref: String(el.parentColJob.value || '').trim(),
      limit: parentBrowseLimit,
      page
    });
    if (!data.ok) {
      parentBrowseRows = [];
      parentBrowseTotal = 0;
      parentBrowsePages = 1;
      el.parentTableBody.innerHTML = '<tr><td colspan="8" class="mjs-empty">Failed to load parent rolls.</td></tr>';
      el.parentCount.textContent = '0 rows';
      el.parentPager.textContent = 'Page 1 / 1';
      el.parentModalSummary.textContent = data.error || 'Failed to load parent rolls.';
      log(data.error || 'Failed to load parent rolls', false);
      return;
    }

    parentBrowseRows = Array.isArray(data.rows) ? data.rows : [];
    parentBrowsePage = parseInt(data.page || page || 1, 10) || 1;
    parentBrowsePages = parseInt(data.pages || 1, 10) || 1;
    parentBrowseTotal = parseInt(data.total || parentBrowseRows.length || 0, 10) || 0;
    el.parentModalSummary.textContent = parentBrowseRows.length
      ? 'Column header filters applied from Paper Stock style.'
      : 'No available rolls matched your filters.';
    renderParentRows();
  }

  async function pickParentRoll(row) {
    const rollNo = String((row && row.roll_no) || '').trim();
    if (!rollNo) return;
    el.parentRoll.value = rollNo;
    closeParentModal();
    await loadParentByRollNo(rollNo);
  }

  function parseRoute(v) {
    const raw = String(v || '').split(',').map(x => x.trim()).filter(Boolean);
    if (!raw.length) {
      if (departmentOptions.includes('Jumbo Slitting') && departmentOptions.includes('Printing')) {
        return 'Jumbo Slitting, Printing';
      }
      return departmentOptions.length ? departmentOptions[0] : 'Jumbo Slitting, Printing';
    }

    if (!departmentOptions.length) {
      return Array.from(new Set(raw)).join(', ');
    }

    const map = {};
    departmentOptions.forEach(d => { map[String(d).toLowerCase()] = d; });
    const normalized = [];
    raw.forEach(part => {
      const key = String(part).toLowerCase();
      if (map[key] && !normalized.includes(map[key])) normalized.push(map[key]);
    });

    if (!normalized.length) {
      if (departmentOptions.includes('Jumbo Slitting') && departmentOptions.includes('Printing')) {
        return 'Jumbo Slitting, Printing';
      }
      return departmentOptions[0];
    }

    return normalized.join(', ');
  }

  function routeOptionsMarkup(currentRoute) {
    const selected = parseRoute(currentRoute).split(',').map(x => x.trim()).filter(Boolean);
    if (!departmentOptions.length) {
      return '<option value="Jumbo Slitting" selected>Jumbo Slitting</option><option value="Printing" selected>Printing</option>';
    }
    return departmentOptions.map(dep => `<option value="${esc(dep)}" ${selected.includes(dep) ? 'selected' : ''}>${esc(dep)}</option>`).join('');
  }

  function routeCardMarkup(idx, currentRoute) {
    const selected = parseRoute(currentRoute).split(',').map(x => x.trim()).filter(Boolean);
    const choices = departmentOptions.length ? departmentOptions : ['Jumbo Slitting', 'Printing'];
    return `<div class="mjs-route-grid" data-route-wrap="${idx}">
      ${choices.map(dep => {
        const checked = selected.includes(dep);
        return `<label class="mjs-route-card ${checked ? 'active' : ''}">
          <input type="checkbox" data-route-idx="${idx}" data-route-value="${esc(dep)}" ${checked ? 'checked' : ''}>
          <span>${esc(dep)}</span>
        </label>`;
      }).join('')}
    </div>`;
  }

  function renderPlans() {
    const list = plans.map(p => {
      const id = parseInt(p.id || 0, 10) || 0;
      const key = id > 0 ? ('id:' + id) : ('plan:' + String(p.job_no || '').trim().toUpperCase());
      const active = !!selectedPlanMap[key];
      return `<div class="mjs-plan ${active ? 'active' : ''}" data-key="${esc(key)}">
        <div class="n">${esc(p.job_no || 'N/A')} - ${esc(p.job_name || '')}</div>
        <div class="m">Dept: ${esc(p.department_route || p.department || '')} | Width: ${esc(p.label_width_mm || p.paper_size || '')}</div>
      </div>`;
    }).join('');
    el.planList.innerHTML = list || '<div style="font-size:.78rem;color:#64748b">No planning jobs found.</div>';

    el.planList.querySelectorAll('.mjs-plan').forEach(card => {
      card.addEventListener('click', () => {
        const key = card.getAttribute('data-key');
        if (selectedPlanMap[key]) delete selectedPlanMap[key]; else selectedPlanMap[key] = true;
        parentRolls.forEach(r => ensureRollConfig(r));
        Object.keys(rollConfigs).forEach(rollNo => {
          const cfg = rollConfigs[rollNo];
          if (!cfg || !Array.isArray(cfg.rows)) return;
          cfg.rows = cfg.rows.map(row => {
            if (String(row.destination || '').toUpperCase() === 'STOCK') return row;
            const keyForRow = planKeyFromRow(row);
            if (keyForRow && selectedPlanMap[keyForRow]) return row;
            const fallback = firstSelectedPlanChoice();
            return Object.assign({}, row, {
              planning_id: fallback.planning_id,
              plan_no: fallback.plan_no,
              job_name: fallback.job_name,
              job_size: fallback.job_size,
              destination: fallback.plan_no ? 'JOB' : 'STOCK',
            });
          });
        });
        renderAllocations();
        renderPlans();
      });
    });

    const selectedCount = Object.keys(selectedPlanMap).length;
    el.planCount.textContent = selectedCount + ' selected';
  }

  function totals() {
    const pw = parentRolls.reduce((sum, roll) => sum + (parseFloat(roll.width_mm) || 0), 0);
    const used = parentRolls.reduce((sum, roll) => {
      const cfg = ensureRollConfig(roll);
      const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
      return sum + rows.reduce((rowSum, row) => {
        const width = parseFloat(row.width_mm || 0) || 0;
        const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        return rowSum + (width * qty);
      }, 0);
    }, 0);
    const rem = pw - used;
    return {pw, used, rem};
  }

  function renderAllocations() {
    if (!parentRolls.length) {
      el.allocBody.innerHTML = '<div class="mjs-empty">Select parent rolls from left panel.</div>';
    } else {
      el.allocBody.innerHTML = parentRolls.map((roll, rollIdx) => {
        const cfg = ensureRollConfig(roll);
        const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
        const parentLength = parseFloat(roll.length_mtr) || 0;
        const totalUsedWidth = rows.reduce((sum, row) => {
          const width = parseFloat(row.width_mm || 0) || 0;
          const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
          return sum + (width * qty);
        }, 0);
        return `
        <div class="mjs-alloc-item" data-roll-card="${esc(roll.roll_no)}">
          <div class="mjs-alloc-item-head">
            <div>
              <div class="mjs-alloc-item-plan">${esc(roll.roll_no || 'N/A')}</div>
              <div class="mjs-alloc-item-job">${esc(roll.paper_type || '')} · ${esc(roll.company || '')} · ${esc(roll.width_mm || '')}mm × ${esc(roll.length_mtr || '')}m</div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
              <span class="mjs-pill" data-roll-used="${esc(roll.roll_no)}">Used: ${totalUsedWidth.toFixed(2)} mm</span>
              <span class="mjs-pill" data-roll-remain="${esc(roll.roll_no)}">Remain: ${(Math.max(0, (parseFloat(roll.width_mm) || 0) - totalUsedWidth)).toFixed(2)} mm</span>
            </div>
          </div>
          <div class="mjs-alloc-item-fields">
            <div class="mjs-field-group mjs-field-group-full">
              <div class="mjs-slit-box">
                <div class="mjs-slit-head">
                  <div class="mjs-slit-title">Manual Slit Rows</div>
                  <button type="button" class="mjs-btn add-part" data-add-row="${esc(roll.roll_no)}" style="padding:6px 10px">Add Row</button>
                </div>
                <div class="mjs-slit-grid">
                  <div class="mjs-slit-row mjs-slit-row-head">
                    <div>Row</div>
                    <div>Width (mm)</div>
                    <div>Qty</div>
                    <div>Length (m)</div>
                    <div>Destination</div>
                    <div>Job Assign</div>
                    <div>Action</div>
                  </div>
                  ${rows.map((run, runIdx) => {
                    const choices = planChoicesForRuns();
                    const selectedKey = runPlanKey(run, run);
                    return `
                    <div class="mjs-slit-row">
                      <span>Row ${runIdx + 1}</span>
                      <input data-roll="${esc(roll.roll_no)}" data-row-idx="${runIdx}" data-row-k="width_mm" type="number" min="0.01" step="0.01" value="${esc(run.width_mm)}" title="Width in mm" placeholder="Width (mm)">
                      <input data-roll="${esc(roll.roll_no)}" data-row-idx="${runIdx}" data-row-k="qty" type="number" min="1" step="1" value="${esc(run.qty)}" title="Quantity of slit" placeholder="Qty">
                      <input data-roll="${esc(roll.roll_no)}" data-row-idx="${runIdx}" data-row-k="length_mtr" type="number" min="0.01" step="0.01" value="${esc(parentLength)}" title="Length is auto-set from parent roll" placeholder="Length (m)" disabled>
                      <select data-roll="${esc(roll.roll_no)}" data-row-idx="${runIdx}" data-row-k="destination">
                        <option value="JOB" ${run.destination === 'JOB' ? 'selected' : ''}>JOB</option>
                        <option value="STOCK" ${run.destination === 'STOCK' ? 'selected' : ''}>STOCK</option>
                      </select>
                      <select data-roll="${esc(roll.roll_no)}" data-row-idx="${runIdx}" data-row-k="plan_key" ${run.destination === 'STOCK' ? 'disabled' : ''}>
                        <option value="">Select plan</option>
                        ${choices.map(choice => `<option value="${esc(choice.key)}" ${selectedKey === choice.key ? 'selected' : ''}>${esc(choice.plan_no)} - ${esc(choice.job_name || '')}</option>`).join('')}
                      </select>
                      <button type="button" class="mjs-btn remove" data-del-row="${esc(roll.roll_no)}:${runIdx}" ${rows.length <= 1 ? 'disabled' : ''}>Remove</button>
                    </div>
                  `;
                  }).join('')}
                </div>
                <div class="mjs-slit-summary" data-roll-summary="${esc(roll.roll_no)}">Parent length: ${parentLength.toFixed(2)} m | Width used: ${totalUsedWidth.toFixed(2)} mm | Mixed jobs and stock allowed per row.</div>
              </div>
            </div>
          </div>
        </div>
      `;
      }).join('');
    }

    el.allocBody.querySelectorAll('[data-roll][data-row-k]').forEach(inp => {
      inp.addEventListener('input', () => {
        const rollNo = String(inp.getAttribute('data-roll') || '');
        const rowIdx = parseInt(inp.getAttribute('data-row-idx') || '-1', 10);
        const rowKey = String(inp.getAttribute('data-row-k') || '');
        const roll = parentRolls.find(r => String(r.roll_no) === rollNo);
        const cfg = roll ? ensureRollConfig(roll) : null;
        if (!cfg || rowIdx < 0 || !cfg.rows[rowIdx]) return;
        if (rowKey === 'width_mm') cfg.rows[rowIdx][rowKey] = parseFloat(inp.value || 0) || 0;
        else if (rowKey === 'length_mtr') {
          const forcedLength = parseFloat(roll && roll.length_mtr ? roll.length_mtr : 0) || 0;
          cfg.rows[rowIdx].length_mtr = forcedLength;
        }
        else if (rowKey === 'qty') cfg.rows[rowIdx][rowKey] = Math.max(1, parseInt(inp.value || 1, 10) || 1);
        else if (rowKey === 'destination') {
          const nextDest = String(inp.value || '').toUpperCase() === 'STOCK' ? 'STOCK' : 'JOB';
          cfg.rows[rowIdx].destination = nextDest;
          if (nextDest === 'STOCK') {
            cfg.rows[rowIdx].planning_id = 0;
            cfg.rows[rowIdx].plan_no = '';
            cfg.rows[rowIdx].job_name = '';
            cfg.rows[rowIdx].job_size = '';
          } else {
            const fallback = firstSelectedPlanChoice();
            if (!String(cfg.rows[rowIdx].plan_no || '').trim()) {
              cfg.rows[rowIdx].planning_id = fallback.planning_id;
              cfg.rows[rowIdx].plan_no = fallback.plan_no;
              cfg.rows[rowIdx].job_name = fallback.job_name;
              cfg.rows[rowIdx].job_size = fallback.job_size;
            }
          }
          renderAllocations();
          return;
        } else if (rowKey === 'plan_key') {
          const choices = planChoicesForRuns();
          const picked = choices.find(choice => choice.key === String(inp.value || '').trim());
          if (picked) {
            cfg.rows[rowIdx].planning_id = picked.planning_id;
            cfg.rows[rowIdx].plan_no = picked.plan_no;
            cfg.rows[rowIdx].job_name = picked.job_name;
            cfg.rows[rowIdx].job_size = picked.job_size;
            cfg.rows[rowIdx].destination = 'JOB';
          }
          renderAllocations();
          return;
        }
        refreshTotalsUI();
      });
      inp.addEventListener('change', () => {
        inp.dispatchEvent(new Event('input'));
        refreshTotalsUI();
      });
    });

    el.allocBody.querySelectorAll('[data-add-row]').forEach(btn => {
      btn.addEventListener('click', () => {
        const rollNo = String(btn.getAttribute('data-add-row') || '');
        const roll = parentRolls.find(r => String(r.roll_no) === rollNo);
        const cfg = roll ? ensureRollConfig(roll) : null;
        if (!cfg || !roll) return;
        cfg.rows.push(createDefaultRow(roll));
        renderAllocations();
      });
    });

    el.allocBody.querySelectorAll('[data-del-row]').forEach(btn => {
      btn.addEventListener('click', () => {
        const token = String(btn.getAttribute('data-del-row') || '');
        const parts = token.split(':');
        const rollNo = parts[0] || '';
        const rowIdx = parseInt(parts[1] || '-1', 10);
        const roll = parentRolls.find(r => String(r.roll_no) === String(rollNo));
        const cfg = roll ? ensureRollConfig(roll) : null;
        if (!cfg || rowIdx < 0 || cfg.rows.length <= 1) return;
        cfg.rows.splice(rowIdx, 1);
        renderAllocations();
      });
    });

    refreshTotalsUI();
  }

  function refreshTotalsUI() {
    const t = totals();
    const jobSummaryMap = {};
    let parentRunningTotal = 0;
    let usedRunningTotal = 0;
    let remainRunningTotal = 0;
    let parentSqmTotal = 0;
    let usedSqmTotal = 0;

    parentRolls.forEach(roll => {
      const parentWidth = parseFloat(roll.width_mm || 0) || 0;
      const parentLength = parseFloat(roll.length_mtr || 0) || 0;
      parentRunningTotal += parentLength;
      parentSqmTotal += (parentWidth / 1000) * parentLength;

      const cfg = ensureRollConfig(roll);
      const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
      rows.forEach((row, rowIdx) => {
        const isJob = String(row.destination || '').toUpperCase() === 'JOB';
        const planNo = String(row.plan_no || '').trim();
        const planLabel = planNo || 'UNASSIGNED';
        const rollNo = String(roll.roll_no || '').trim();
        const rowWidth = parseFloat(row.width_mm || 0) || 0;
        const rowLength = parseFloat(row.length_mtr || 0) || 0;
        const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        const rowRunning = rowLength * qty;
        const rowSqm = (rowWidth / 1000) * rowLength * qty;
        if (isJob && planLabel !== 'UNASSIGNED' && rowWidth > 0) {
          if (!jobSummaryMap[planLabel]) {
            jobSummaryMap[planLabel] = {
              plan_no: planLabel,
              job_name: String(row.job_name || '').trim(),
              rolls: {},
              slit_rows: 0,
              slit_qty: 0,
              width_mm: 0,
              running_mtr: 0,
              sqm: 0,
              status_counts: {},
            };
          }
          jobSummaryMap[planLabel].rolls[rollNo] = true;
          jobSummaryMap[planLabel].slit_rows += 1;
          jobSummaryMap[planLabel].slit_qty += qty;
          jobSummaryMap[planLabel].width_mm += (rowWidth * qty);
          jobSummaryMap[planLabel].running_mtr += rowRunning;
          jobSummaryMap[planLabel].sqm += rowSqm;
          if (!jobSummaryMap[planLabel].status_counts['Job Assign']) {
            jobSummaryMap[planLabel].status_counts['Job Assign'] = 0;
          }
          jobSummaryMap[planLabel].status_counts['Job Assign'] += qty;
        }

        usedSqmTotal += ((rowWidth / 1000) * rowLength * qty);
      });

      const usedWidth = rows.reduce((sum, row) => {
        const width = parseFloat(row.width_mm || 0) || 0;
        const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        return sum + (width * qty);
      }, 0);
      const remainWidth = Math.max(0, parentWidth - usedWidth);

      if (usedWidth > 0.0001) {
        usedRunningTotal += parentLength;
      }
      if (remainWidth > 0.0001) {
        remainRunningTotal += parentLength;
      }
    });

    const remainSqmTotal = Math.max(0, parentSqmTotal - usedSqmTotal);

    const jobRows = Object.keys(jobSummaryMap).sort().map((planNo) => {
      const j = jobSummaryMap[planNo];
      const rollList = Object.keys(j.rolls).filter(Boolean);
      return {
        plan_no: j.plan_no,
        job_name: j.job_name,
        roll_count: rollList.length,
        roll_list: rollList,
        slit_rows: j.slit_rows,
        slit_qty: j.slit_qty,
        width_mm: j.width_mm,
        running_mtr: j.running_mtr,
        sqm: j.sqm,
        status_counts: j.status_counts,
      };
    });

    if (el.jobWiseBody) {
      if (!jobRows.length) {
        el.jobWiseBody.innerHTML = '<div class="mjs-jobwise-empty">No JOB rows available yet.</div>';
      } else {
        const totalRunning = jobRows.reduce((sum, r) => sum + r.running_mtr, 0);
        const totalSqm = jobRows.reduce((sum, r) => sum + r.sqm, 0);
        const totalWidth = jobRows.reduce((sum, r) => sum + r.width_mm, 0);
        const totalRows = jobRows.reduce((sum, r) => sum + r.slit_rows, 0);
        const totalQty = jobRows.reduce((sum, r) => sum + r.slit_qty, 0);
        el.jobWiseBody.innerHTML = `
          <div class="mjs-jobwise-note">Child roll names are auto-generated during execution based on job card format.</div>
          <div class="mjs-jobwise-card">
            <div class="mjs-jobwise-card-head job">PLN Wise Job Allocation</div>
            <table class="mjs-jobwise-table">
              <thead>
                <tr>
                  <th>PLN / Job</th>
                  <th>Parent Roll Names</th>
                  <th>Child Roll Name / Status</th>
                  <th>Rows / Qty</th>
                  <th>Width (mm)</th>
                  <th>Running (mtr)</th>
                  <th>Sqm</th>
                </tr>
              </thead>
              <tbody>
                ${jobRows.map(r => `
                  <tr class="mjs-jobwise-row-job">
                    <td><strong>${esc(r.plan_no)}</strong><br><span style="color:#64748b">${esc(r.job_name || '')}</span></td>
                    <td>${r.roll_list.map(x => `<span class="mjs-tag job">${esc(x)}</span>`).join('')}<br><span style="color:#64748b">${esc(r.roll_list.join(', '))}</span></td>
                    <td><span class="mjs-tag job">Auto Generated on Execute</span><br>${Object.keys(r.status_counts || {}).map(k => `<span class="mjs-tag status-job">${esc(k + ' x' + (r.status_counts[k] || 0))}</span>`).join('')}</td>
                    <td>${esc(r.slit_rows)} / ${esc(r.slit_qty)}</td>
                    <td>${esc(r.width_mm.toFixed(2))}</td>
                    <td>${esc(r.running_mtr.toFixed(2))}</td>
                    <td>${esc(r.sqm.toFixed(2))}</td>
                  </tr>
                `).join('')}
                <tr class="mjs-jobwise-total">
                  <td>Total</td>
                  <td>-</td>
                  <td>-</td>
                  <td>${esc(totalRows)} / ${esc(totalQty)}</td>
                  <td>${esc(totalWidth.toFixed(2))}</td>
                  <td>${esc(totalRunning.toFixed(2))}</td>
                  <td>${esc(totalSqm.toFixed(2))}</td>
                </tr>
              </tbody>
            </table>
          </div>`;
      }
    }

    el.parentWidth.innerHTML = t.pw.toFixed(2) + ' mm' + '<span class="mjs-kpi-sub">' + parentRunningTotal.toFixed(2) + ' mtr | ' + parentSqmTotal.toFixed(2) + ' sqm</span>';
    el.allocated.innerHTML = t.used.toFixed(2) + ' mm' + '<span class="mjs-kpi-sub">' + usedRunningTotal.toFixed(2) + ' mtr | ' + usedSqmTotal.toFixed(2) + ' sqm</span>';
    el.remainder.innerHTML = t.rem.toFixed(2) + ' mm' + '<span class="mjs-kpi-sub">' + remainRunningTotal.toFixed(2) + ' mtr | ' + remainSqmTotal.toFixed(2) + ' sqm</span>';
    el.remainBadge.textContent = 'Remaining: ' + t.rem.toFixed(2) + ' mm';
    el.remainBadge.style.background = t.rem < -0.5 ? '#fee2e2' : '#f8fafc';
    el.remainBadge.style.color = t.rem < -0.5 ? '#991b1b' : '#475569';

    parentRolls.forEach(roll => {
      const rollNo = String(roll && roll.roll_no ? roll.roll_no : '').trim();
      if (!rollNo) return;
      const cfg = ensureRollConfig(roll);
      const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
      const parentWidth = parseFloat(roll.width_mm || 0) || 0;
      const parentLen = parseFloat(roll.length_mtr || 0) || 0;
      const usedWidth = rows.reduce((sum, row) => {
        const width = parseFloat(row.width_mm || 0) || 0;
        const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        return sum + (width * qty);
      }, 0);
      const remainWidth = Math.max(0, parentWidth - usedWidth);

      const usedBadge = el.allocBody.querySelector('[data-roll-used="' + rollNo + '"]');
      if (usedBadge) usedBadge.textContent = 'Used: ' + usedWidth.toFixed(2) + ' mm';

      const remainBadge = el.allocBody.querySelector('[data-roll-remain="' + rollNo + '"]');
      if (remainBadge) remainBadge.textContent = 'Remain: ' + remainWidth.toFixed(2) + ' mm';

      const summary = el.allocBody.querySelector('[data-roll-summary="' + rollNo + '"]');
      if (summary) {
        summary.textContent = 'Parent length: ' + parentLen.toFixed(2) + ' m | Width used: ' + usedWidth.toFixed(2) + ' mm | Mixed jobs and stock allowed per row.';
      }
    });
  }

  async function loadMachines() {
    const data = await apiGet('get_machines');
    if (!data.ok) {
      return;
    }
    departmentOptions = Array.isArray(data.departments) ? data.departments.map(x => String(x || '').trim()).filter(Boolean) : [];
    const preferred = parseRoute('').split(',').map(x => x.trim()).filter(Boolean);
    selectedExecutionDepartments = preferred.length ? preferred : (departmentOptions.slice(0, 1));
    renderDepartmentChooser();
    renderAllocations();
  }

  async function loadPlans() {
    const data = await apiGet('get_planning_jobs');
    if (!data.ok) {
      log(data.error || 'Failed to load planning jobs', false);
      return;
    }
    plans = data.jobs || [];
    syncAllocationsFromSelectionAllRolls();
    renderPlans();
    log('Planning queue loaded: ' + plans.length + ' jobs', true);
  }

  async function loadParentByRollNo(rn) {
    if (!rn) return;
    const data = await apiGet('search_roll', {q: rn});
    if (!data.ok || !data.roll) {
      el.parentMeta.innerHTML = '<span class="mjs-bad">Parent roll not found</span>';
      refreshTotalsUI();
      log('Parent roll not found: ' + rn, false);
      return;
    }
    addOrActivateParentRoll(data.roll);
    refreshTotalsUI();
  }

  async function loadParent() {
    const rn = String(el.parentRoll.value || '').trim();
    openParentModal(rn);
  }

  function resetParentFilters() {
    el.parentColRoll.value = '';
    el.parentColPaper.value = '';
    el.parentColCompany.value = '';
    el.parentColWidth.value = '';
    el.parentColStatus.value = '';
    el.parentColJob.value = '';
  }

  async function addParent() {
    el.parentRoll.value = '';
    resetParentFilters();
    openParentModal('');
  }

  async function bootstrapPreselectedRolls() {
    if (!Array.isArray(PRESELECTED_ROLLS) || !PRESELECTED_ROLLS.length) {
      return;
    }
    for (let i = 0; i < PRESELECTED_ROLLS.length; i++) {
      const rn = String(PRESELECTED_ROLLS[i] || '').trim();
      if (!rn) continue;
      await loadParentByRollNo(rn);
    }
  }

  function validateBeforeExecute(showToast=true) {
    if (!parentRolls.length) {
      if (showToast) log('Load at least one parent roll first', false);
      return false;
    }
    const route = selectedDepartmentRoute();
    if (!route) {
      if (showToast) log('Select at least one department for job card issuing', false);
      return false;
    }

    let hasAnyRow = false;
    let hasAnyJobRow = false;
    for (let p = 0; p < parentRolls.length; p++) {
      const roll = parentRolls[p];
      const rollNo = String(roll.roll_no || '');
      const cfg = ensureRollConfig(roll);
      const rows = cfg && Array.isArray(cfg.rows) ? cfg.rows : [];
      if (!rows.length) {
        if (showToast) log('No slit rows found for roll: ' + rollNo, false);
        return false;
      }
      const parentWidth = parseFloat(roll.width_mm) || 0;
      const parentLength = parseFloat(roll.length_mtr) || 0;
      let usedWidth = 0;

      for (let rowIdx = 0; rowIdx < rows.length; rowIdx++) {
        const row = rows[rowIdx];
        hasAnyRow = true;
        row.length_mtr = parentLength;
        const runWidth = parseFloat(row.width_mm || 0) || 0;
        if (runWidth <= 0) {
          if (showToast) log('Roll ' + rollNo + ' row #' + (rowIdx + 1) + ' width must be > 0', false);
          return false;
        }
        const qty = Math.max(1, parseInt(row.qty || 1, 10) || 1);
        row.qty = qty;
        if (qty <= 0) {
          if (showToast) log('Roll ' + rollNo + ' row #' + (rowIdx + 1) + ' qty must be > 0', false);
          return false;
        }
        usedWidth += (runWidth * qty);
        const runLength = parseFloat(row.length_mtr || 0) || 0;
        if (runLength <= 0) {
          if (showToast) log('Roll ' + rollNo + ' row #' + (rowIdx + 1) + ' length must be > 0', false);
          return false;
        }
        if (parentLength > 0 && runLength > parentLength) {
          if (showToast) log('Roll ' + rollNo + ' row #' + (rowIdx + 1) + ' length exceeds parent length', false);
          return false;
        }
        const dest = String(row.destination || '').toUpperCase() === 'STOCK' ? 'STOCK' : 'JOB';
        row.destination = dest;
        if (dest === 'JOB') {
          hasAnyJobRow = true;
          if (!String(row.plan_no || '').trim()) {
            if (showToast) log('Roll ' + rollNo + ' row #' + (rowIdx + 1) + ' missing target plan for JOB destination', false);
            return false;
          }
        }
      }

      const rem = parentWidth - usedWidth;
      if (rem < -0.5) {
        if (showToast) log('Over-allocation on roll ' + rollNo + '. Reduce widths.', false);
        return false;
      }
    }

    if (!hasAnyRow) {
      if (showToast) log('Add at least one slit row before execute', false);
      return false;
    }
    if (hasAnyJobRow && !Object.keys(selectedPlanMap).length) {
      if (showToast) log('Select at least one plan for JOB rows', false);
      return false;
    }
    return true;
  }

  async function executeMultiPlan() {
    if (!validateBeforeExecute(true)) return;

    el.execute.disabled = true;
    const parentPayloads = buildCombinedParentPayloads();
    if (!parentPayloads.length) {
      log('No valid slit rows found for selected parent rolls', false);
      el.execute.disabled = false;
      return;
    }

    const data = await apiPost('execute_multi_plan_batch_combined', {
      parent_payloads: JSON.stringify(parentPayloads),
      remainder_action: el.remainderAction.value || 'STOCK',
      operator_name: operator,
    });
    if (!data.ok) {
      log('Execute failed: ' + (data.error || 'Unknown error'), false);
      el.execute.disabled = false;
      return;
    }

    const allCreatedCards = Array.isArray(data.created_job_cards) ? data.created_job_cards : [];
    const allChildRolls = Array.isArray(data.child_rolls) ? data.child_rolls : [];
    log('Success! Combined batch ' + data.batch_no + ' created for ' + parentPayloads.length + ' parent rolls', true);

    const summary = {
      batch_no: data.batch_no || '',
      parent_roll: parentRolls.map(r => r.roll_no).join(', '),
      child_rolls: allChildRolls,
      created_job_cards: allCreatedCards,
      remainder_width_mm: parseFloat(data.remainder_width_mm || 0).toFixed(2),
      remainder_action: el.remainderAction.value || 'STOCK',
    };

    rollConfigs = {};
    parentRolls = [];
    activeParentKey = '';
    selectedPlanMap = {};
    el.parentRoll.value = '';
    el.parentMeta.innerHTML = '<span class="mjs-bad">Pick at least one parent roll</span>';
    renderParentRollChips();
    renderPlans();
    renderAllocations();
    refreshTotalsUI();
    await loadPlans();
    openSuccessModal(summary);
    el.execute.disabled = false;
  }

  el.loadPlans.addEventListener('click', loadPlans);
  el.loadParent.addEventListener('click', loadParent);
  el.addParent.addEventListener('click', addParent);
  el.parentRoll.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); loadParent(); } });
  el.validate.addEventListener('click', () => validateBeforeExecute(true));
  el.execute.addEventListener('click', executeMultiPlan);
  el.parentSearchBtn.addEventListener('click', () => browseParentRolls(1));
  el.parentFilterReset.addEventListener('click', () => {
    resetParentFilters();
    browseParentRolls(1);
  });
  [el.parentColRoll, el.parentColPaper, el.parentColCompany, el.parentColWidth, el.parentColStatus, el.parentColJob].forEach(inp => {
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        parentBrowsePage = 1;
        browseParentRolls(parentBrowsePage);
      }
    });
  });
  el.parentPrev.addEventListener('click', () => {
    if (parentBrowsePage <= 1) return;
    browseParentRolls(parentBrowsePage - 1);
  });
  el.parentNext.addEventListener('click', () => {
    if (parentBrowsePage >= parentBrowsePages) return;
    browseParentRolls(parentBrowsePage + 1);
  });
  el.parentModalClose.addEventListener('click', closeParentModal);
  el.parentModalDone.addEventListener('click', closeParentModal);
  el.parentModal.addEventListener('click', (e) => {
    if (e.target === el.parentModal) closeParentModal();
  });
  el.parentList.addEventListener('click', (e) => {
    const removeBtn = e.target.closest('[data-parent-remove]');
    if (removeBtn) {
      e.stopPropagation();
      const rollNo = removeBtn.getAttribute('data-parent-remove') || '';
      removeParentRoll(rollNo);
      return;
    }
    const switchChip = e.target.closest('[data-parent-switch]');
    if (switchChip) {
      const rollNo = switchChip.getAttribute('data-parent-switch') || '';
      if (rollNo) {
        activeParentKey = rollNo;
        renderParentRollChips();
        refreshParentMeta();
        renderAllocations();
      }
    }
  });
  el.successModalClose.addEventListener('click', closeSuccessModal);
  el.successDone.addEventListener('click', closeSuccessModal);
  el.successModal.addEventListener('click', (e) => {
    if (e.target === el.successModal) closeSuccessModal();
  });

  (async () => {
    await loadMachines();
    await loadPlans();
    renderParentRollChips();
    refreshParentMeta();
    renderAllocations();
    await bootstrapPreselectedRolls();
  })();
})();
</script>
