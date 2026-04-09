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
.mjs-actions{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.mjs-btn{border:none;border-radius:8px;padding:9px 14px;font-size:.78rem;font-weight:800;cursor:pointer}
.mjs-btn.primary{background:#16a34a;color:#fff}
.mjs-btn.secondary{background:#0f172a;color:#fff}
.mjs-btn.light{background:#fff;border:1px solid var(--border);color:#334155}
.mjs-pill{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:800;background:#f8fafc;border:1px solid var(--border);color:#475569}
.mjs-kpi{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.mjs-kpi .box{border:1px solid var(--border);border-radius:10px;padding:10px;background:#f8fafc}
.mjs-kpi .k{font-size:.63rem;text-transform:uppercase;color:#64748b;font-weight:800;letter-spacing:.05em}
.mjs-kpi .v{font-size:1rem;font-weight:900;color:#0f172a;margin-top:4px}
.mjs-log{margin-top:10px;border:1px solid var(--border);border-radius:8px;padding:10px;max-height:220px;overflow:auto;background:#f8fafc;font-size:.76rem;line-height:1.45}
.mjs-ok{color:#166534}
.mjs-bad{color:#991b1b}
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
@media(max-width:1100px){.mjs-wrap{grid-template-columns:1fr}}
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

  <div class="mjs-card">
    <div class="mjs-head"><h3>2) Allocation Matrix + Execute</h3><span class="mjs-pill" id="mjsRemainBadge">Remaining: 0 mm</span></div>
    <div class="mjs-body">
      <table class="mjs-alloc-table">
        <thead>
          <tr>
            <th>Plan</th>
            <th>Width (mm)</th>
            <th>Route</th>
            <th>Dest</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="mjsAllocBody">
          <tr><td colspan="5" style="color:#64748b">Select plans from left panel.</td></tr>
        </tbody>
      </table>

      <div class="mjs-kpi" style="margin-top:10px">
        <div class="box"><div class="k">Parent Width</div><div class="v" id="mjsParentWidth">0 mm</div></div>
        <div class="box"><div class="k">Allocated</div><div class="v" id="mjsAllocated">0 mm</div></div>
        <div class="box"><div class="k">Remainder</div><div class="v" id="mjsRemainder">0 mm</div></div>
      </div>

      <div class="mjs-actions">
        <div style="display:flex;align-items:center;gap:8px">
          <label style="font-size:.72rem;font-weight:800;color:#64748b">Remainder</label>
          <select id="mjsRemainderAction" class="mjs-select" style="width:140px;height:34px">
            <option value="STOCK">STOCK</option>
            <option value="ADJUST">ADJUST</option>
          </select>
        </div>
        <div style="display:flex;gap:8px">
          <button class="mjs-btn light" id="mjsValidate">Validate</button>
          <button class="mjs-btn secondary" id="mjsExecute">Execute Multi-Plan Batch</button>
        </div>
      </div>

      <div class="mjs-log" id="mjsLog"></div>
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
  let rollAllocations = {};
  let departmentOptions = [];
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
    parentWidth: document.getElementById('mjsParentWidth'),
    allocated: document.getElementById('mjsAllocated'),
    remainder: document.getElementById('mjsRemainder'),
    remainBadge: document.getElementById('mjsRemainBadge'),
    remainderAction: document.getElementById('mjsRemainderAction'),
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

  function getActiveAllocations() {
    const active = getActiveParent();
    if (!active) return [];
    const key = String(active.roll_no || '');
    if (!rollAllocations[key]) rollAllocations[key] = [];
    return rollAllocations[key];
  }

  function setActiveAllocations(rows) {
    const active = getActiveParent();
    if (!active) return;
    const key = String(active.roll_no || '');
    rollAllocations[key] = Array.isArray(rows) ? rows : [];
  }

  function selectedPlans() {
    return plans.filter(p => {
      const idKey = 'id:' + (parseInt(p.id || 0, 10) || 0);
      const planKey = 'plan:' + String(p.job_no || '').trim().toUpperCase();
      return !!selectedPlanMap[idKey] || (planKey !== 'plan:' && !!selectedPlanMap[planKey]);
    });
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

  function normalizeAllocationsForRoll(existingAllocations) {
    const map = {};
    (existingAllocations || []).forEach(row => {
      const k = getPlanKeyFromObj(row);
      if (k) map[k] = row;
    });
    return selectedPlans().map((p, i) => {
      const key = getPlanKeyFromObj(p);
      const prev = map[key] || null;
      const suggestedWidth = parseFloat(p.label_width_mm || p.paper_size || 0) || 0;
      return {
        planning_id: parseInt(p.id || 0, 10) || 0,
        plan_no: String(p.job_no || '').trim(),
        job_name: String(p.job_name || '').trim(),
        job_size: String(p.label_length_mm || p.label_width_mm || p.paper_size || '').trim(),
        department_route: parseRoute((prev && prev.department_route) || p.department_route || p.department),
        destination: (prev && prev.destination) || 'JOB',
        allocated_width_mm: prev ? (parseFloat(prev.allocated_width_mm) || 0) : suggestedWidth,
        allocation_sequence: i + 1,
      };
    });
  }

  function syncAllocationsFromSelectionAllRolls() {
    parentRolls.forEach(r => {
      const key = String(r.roll_no || '');
      rollAllocations[key] = normalizeAllocationsForRoll(rollAllocations[key] || []);
    });
    renderAllocations();
  }

  function addOrActivateParentRoll(row) {
    if (!row || !row.roll_no) return;
    const rollNo = String(row.roll_no || '').trim();
    if (!rollNo) return;
    const exists = parentRolls.find(r => String(r.roll_no) === rollNo);
    if (!exists) {
      parentRolls.push(row);
      rollAllocations[rollNo] = normalizeAllocationsForRoll([]);
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
    delete rollAllocations[target];
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
        syncAllocationsFromSelectionAllRolls();
        renderPlans();
      });
    });

    const selectedCount = Object.keys(selectedPlanMap).length;
    el.planCount.textContent = selectedCount + ' selected';
  }

  function totals() {
    const active = getActiveParent();
    const allocations = getActiveAllocations();
    const pw = active ? (parseFloat(active.width_mm) || 0) : 0;
    const used = allocations.reduce((s, a) => s + (parseFloat(a.allocated_width_mm) || 0), 0);
    const rem = pw - used;
    return {pw, used, rem};
  }

  function renderAllocations() {
    const allocations = getActiveAllocations();
    if (!allocations.length) {
      el.allocBody.innerHTML = '<tr><td colspan="5" style="color:#64748b">Select plans from left panel.</td></tr>';
    } else {
      el.allocBody.innerHTML = allocations.map((a, idx) => `
        <tr>
          <td>
            <div style="font-weight:800">${esc(a.plan_no || 'N/A')}</div>
            <div style="font-size:.68rem;color:#64748b">${esc(a.job_name || '')}</div>
          </td>
          <td><input data-idx="${idx}" data-k="allocated_width_mm" type="number" min="0" step="0.01" value="${esc(a.allocated_width_mm)}"></td>
          <td>
            <select multiple data-idx="${idx}" data-k="department_route" class="mjs-route-select">
              ${routeOptionsMarkup(a.department_route)}
            </select>
          </td>
          <td>
            <select data-idx="${idx}" data-k="destination">
              <option value="JOB" ${a.destination === 'JOB' ? 'selected' : ''}>JOB</option>
              <option value="STOCK" ${a.destination === 'STOCK' ? 'selected' : ''}>STOCK</option>
            </select>
          </td>
          <td><button class="mjs-btn light" data-del="${idx}" style="padding:6px 10px">Remove</button></td>
        </tr>
      `).join('');
    }

    el.allocBody.querySelectorAll('input,select').forEach(inp => {
      inp.addEventListener('input', () => {
        const idx = parseInt(inp.getAttribute('data-idx') || '-1', 10);
        const k = inp.getAttribute('data-k');
        if (idx < 0 || !allocations[idx] || !k) return;
        if (k === 'allocated_width_mm') {
          allocations[idx][k] = parseFloat(inp.value || 0) || 0;
        } else if (k === 'department_route' && inp.multiple) {
          const route = Array.from(inp.selectedOptions).map(o => o.value).filter(Boolean).join(', ');
          allocations[idx][k] = parseRoute(route);
        } else {
          allocations[idx][k] = inp.value;
        }
        refreshTotalsUI();
      });
      inp.addEventListener('change', () => {
        const idx = parseInt(inp.getAttribute('data-idx') || '-1', 10);
        const k = inp.getAttribute('data-k');
        if (idx < 0 || !allocations[idx] || !k) return;
        if (k === 'allocated_width_mm') {
          allocations[idx][k] = parseFloat(inp.value || 0) || 0;
        } else if (k === 'department_route' && inp.multiple) {
          const route = Array.from(inp.selectedOptions).map(o => o.value).filter(Boolean).join(', ');
          allocations[idx][k] = parseRoute(route);
        } else {
          allocations[idx][k] = inp.value;
        }
        refreshTotalsUI();
      });
    });

    el.allocBody.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.getAttribute('data-del') || '-1', 10);
        if (idx < 0) return;
        const dropped = allocations[idx];
        allocations.splice(idx, 1);
        setActiveAllocations(allocations);
        if (dropped && parentRolls.length) {
          const planKey = getPlanKeyFromObj(dropped);
          let stillUsed = false;
          parentRolls.forEach(r => {
            const rows = rollAllocations[String(r.roll_no) || ''] || [];
            if (rows.some(rr => getPlanKeyFromObj(rr) === planKey)) {
              stillUsed = true;
            }
          });
          if (!stillUsed && planKey) {
            delete selectedPlanMap[planKey];
          }
        }
        renderPlans();
        renderAllocations();
      });
    });

    refreshTotalsUI();
  }

  function refreshTotalsUI() {
    const t = totals();
    el.parentWidth.textContent = t.pw.toFixed(2) + ' mm';
    el.allocated.textContent = t.used.toFixed(2) + ' mm';
    el.remainder.textContent = t.rem.toFixed(2) + ' mm';
    el.remainBadge.textContent = 'Remaining: ' + t.rem.toFixed(2) + ' mm';
    el.remainBadge.style.background = t.rem < -0.5 ? '#fee2e2' : '#f8fafc';
    el.remainBadge.style.color = t.rem < -0.5 ? '#991b1b' : '#475569';
  }

  async function loadMachines() {
    const data = await apiGet('get_machines');
    if (!data.ok) {
      return;
    }
    departmentOptions = Array.isArray(data.departments) ? data.departments.map(x => String(x || '').trim()).filter(Boolean) : [];
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
    if (!Object.keys(selectedPlanMap).length) {
      if (showToast) log('Select at least one plan allocation', false);
      return false;
    }
    for (let p = 0; p < parentRolls.length; p++) {
      const roll = parentRolls[p];
      const rollNo = String(roll.roll_no || '');
      const allocations = rollAllocations[rollNo] || [];
      if (!allocations.length) {
        if (showToast) log('No allocations found for roll: ' + rollNo, false);
        return false;
      }
      const seen = {};
      for (let i = 0; i < allocations.length; i++) {
        const a = allocations[i];
        if (!a.plan_no) {
          if (showToast) log('Roll ' + rollNo + ' allocation #' + (i + 1) + ' missing plan no', false);
          return false;
        }
        const key = getPlanKeyFromObj(a);
        if (seen[key]) {
          if (showToast) log('Roll ' + rollNo + ' has duplicate plan allocation: ' + a.plan_no, false);
          return false;
        }
        seen[key] = true;
        if ((parseFloat(a.allocated_width_mm) || 0) <= 0) {
          if (showToast) log('Roll ' + rollNo + ' allocation #' + (i + 1) + ' width must be > 0', false);
          return false;
        }
        const route = parseRoute(a.department_route);
        if (!route) {
          if (showToast) log('Roll ' + rollNo + ' allocation #' + (i + 1) + ' missing department route', false);
          return false;
        }
        allocations[i].department_route = route;
      }
      const pw = parseFloat(roll.width_mm) || 0;
      const used = allocations.reduce((s, a) => s + (parseFloat(a.allocated_width_mm) || 0), 0);
      const rem = pw - used;
      if (rem < -0.5) {
        if (showToast) log('Over-allocation on roll ' + rollNo + '. Reduce widths.', false);
        return false;
      }
    }
    return true;
  }

  async function executeMultiPlan() {
    if (!validateBeforeExecute(true)) return;

    el.execute.disabled = true;
    const runs = [];
    const allCreatedCards = [];
    const allChildRolls = [];
    for (let i = 0; i < parentRolls.length; i++) {
      const roll = parentRolls[i];
      const rollNo = String(roll.roll_no || '');
      const allocations = rollAllocations[rollNo] || [];
      const payload = allocations.map((a, idx) => ({
        planning_id: a.planning_id || 0,
        plan_no: a.plan_no,
        job_name: a.job_name,
        job_size: a.job_size,
        department_route: parseRoute(a.department_route),
        destination: a.destination,
        allocated_width_mm: parseFloat(a.allocated_width_mm) || 0,
        allocation_sequence: idx + 1,
      }));

      const data = await apiPost('execute_multi_plan_batch', {
        parent_roll_no: rollNo,
        plan_allocations: JSON.stringify(payload),
        remainder_action: el.remainderAction.value || 'STOCK',
        operator_name: operator,
      });
      if (!data.ok) {
        log('Execute failed for ' + rollNo + ': ' + (data.error || 'Unknown error'), false);
        el.execute.disabled = false;
        return;
      }
      runs.push(data);
      (data.created_job_cards || []).forEach(j => allCreatedCards.push(j));
      (data.child_rolls || []).forEach(ch => allChildRolls.push(ch));
      log('Success! Roll ' + rollNo + ' -> Batch ' + data.batch_no, true);
    }

    const summary = {
      batch_no: runs.length === 1 ? runs[0].batch_no : ('MULTI (' + runs.length + ' batches)'),
      parent_roll: parentRolls.map(r => r.roll_no).join(', '),
      child_rolls: allChildRolls,
      created_job_cards: allCreatedCards,
      remainder_width_mm: runs.reduce((s, r) => s + (parseFloat(r.remainder_width_mm) || 0), 0).toFixed(2),
      remainder_action: el.remainderAction.value || 'STOCK',
    };

    rollAllocations = {};
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
