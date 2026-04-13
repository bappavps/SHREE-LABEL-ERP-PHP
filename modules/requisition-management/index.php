<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Requisition Management';
$csrf = generateCSRF();

$rmCanAdmin = hasRole('admin', 'manager', 'system_admin', 'super_admin') || isAdmin();
$rmCanAccounts = hasRole('accounts', 'account', 'purchase', 'admin', 'system_admin', 'super_admin') || isAdmin();

$rmDepartments = erp_get_job_card_departments();
if (!in_array('Others', $rmDepartments, true)) {
  $rmDepartments[] = 'Others';
}
$rmCategories = ['Paper', 'Ink', 'Plate', 'Consumable', 'Stationary', 'Others'];
$rmUnits = ['Kg', 'Nos', 'Meter'];
$rmSettings = getAppSettings();
$rmCompanyName = function_exists('getErpDisplayName')
  ? getErpDisplayName((string)($rmSettings['company_name'] ?? APP_NAME))
  : APP_NAME;

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Purchase</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Requisition Management</span>
</div>

<div class="page-header" style="margin-bottom:12px;">
  <div>
    <h1 style="margin:0;">Requisition Management</h1>
    <p style="margin:4px 0 0;color:#64748b;">Submitted → Pending → Approved/Rejected → PO Created</p>
  </div>
</div>

<style>
.rm-shell{
  --rm-primary:#0f766e;
  --rm-primary-soft:#ccfbf1;
  --rm-accent:#f59e0b;
  --rm-danger:#dc2626;
  --rm-ink:#1e293b;
  --rm-muted:#64748b;
  position:relative;
  border:1px solid #dbeafe;
  border-radius:18px;
  background:linear-gradient(145deg,#f8fbff 0%,#f0fdfa 45%,#fff7ed 100%);
  padding:14px;
  overflow:hidden;
  box-shadow:0 10px 30px rgba(15,23,42,.08);
}
.rm-shell::before,
.rm-shell::after{
  content:'';
  position:absolute;
  width:280px;
  height:280px;
  border-radius:50%;
  pointer-events:none;
}
.rm-shell::before{
  top:-180px;
  left:-120px;
  background:radial-gradient(circle,rgba(20,184,166,.28) 0%,rgba(20,184,166,0) 72%);
}
.rm-shell::after{
  right:-130px;
  bottom:-210px;
  background:radial-gradient(circle,rgba(251,191,36,.24) 0%,rgba(251,191,36,0) 74%);
}
.rm-shell > *{position:relative;z-index:1}

.rm-headline{
  background:linear-gradient(100deg,#0f766e 0%,#0e7490 48%,#0891b2 100%);
  color:#fff;
  padding:12px 14px;
  border-radius:14px;
  margin-bottom:12px;
  box-shadow:0 8px 22px rgba(8,145,178,.3);
}
.rm-headline strong{display:block;font-size:.96rem;letter-spacing:.2px}
.rm-headline span{font-size:.78rem;opacity:.9}

.rm-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.rm-tab{
  padding:9px 13px;
  border:1px solid #cbd5e1;
  background:#fff;
  border-radius:999px;
  cursor:pointer;
  font-weight:700;
  font-size:.76rem;
  color:var(--rm-ink);
  transition:all .2s ease;
}
.rm-tabs::-webkit-scrollbar{height:6px}
.rm-tabs::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:999px}
.rm-tab:hover{transform:translateY(-1px);border-color:#94a3b8;box-shadow:0 6px 14px rgba(15,23,42,.08)}
.rm-tab.active{
  background:linear-gradient(95deg,#0f766e,#0e7490);
  color:#fff;
  border-color:#0f766e;
  box-shadow:0 8px 18px rgba(15,118,110,.35);
}
.rm-tab.disabled{opacity:.55;cursor:not-allowed}

.rm-pane{display:none}
.rm-pane.active{display:block;animation:rmFadeIn .22s ease}

.rm-panel .card{
  border:1px solid #dbe4f0;
  border-radius:14px;
  background:#fff;
  box-shadow:0 8px 20px rgba(15,23,42,.07);
}
.rm-panel .card-header{
  border-bottom:1px solid #e2e8f0;
  background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);
  border-top-left-radius:14px;
  border-top-right-radius:14px;
}
.rm-panel .card-title{color:#0f172a;font-weight:800;letter-spacing:.2px}

#rm-panel-user .card{
  border:1px solid #bae6fd;
  box-shadow:0 12px 28px rgba(14,116,144,.12);
}
#rm-panel-user .card-header{
  background:linear-gradient(98deg,#0ea5e9 0%,#14b8a6 52%,#22c55e 100%);
  border-bottom:none;
}
#rm-panel-user .card-header .card-title{color:#fff}

#rm-new-form{
  background:linear-gradient(180deg,#ffffff 0%,#f0fdfa 100%);
  border:1px solid #ccfbf1;
  border-radius:12px;
  padding:12px;
}
#rm-new-form .rm-section-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:8px;
  padding:8px 10px;
  border:1px dashed #67e8f9;
  border-radius:10px;
  background:linear-gradient(90deg,#ecfeff,#f0fdfa);
}
#rm-new-form .rm-section-head label{
  margin:0;
  color:#0f766e;
  font-size:.78rem;
  letter-spacing:.2px;
}

.rm-item-row{
  border:1px solid #bfdbfe;
  border-radius:11px;
  padding:10px;
  background:linear-gradient(168deg,#eff6ff 0%,#f8fafc 46%,#ecfeff 100%);
  box-shadow:0 6px 14px rgba(30,41,59,.06);
}
.rm-item-row .rm-item-serial{
  font-size:.8rem;
  color:#075985;
  background:#e0f2fe;
  border:1px solid #bae6fd;
  padding:3px 8px;
  border-radius:999px;
}
.rm-item-row input,
.rm-item-row select{
  background:#ffffff;
}

.rm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.rm-grid .rm-col-2{grid-column:span 2}
.rm-grid label{display:block;font-size:.72rem;font-weight:800;color:#334155;margin-bottom:4px}
.rm-grid input,.rm-grid select,.rm-grid textarea{
  width:100%;
  border:1px solid #cbd5e1;
  border-radius:10px;
  padding:8px 10px;
  font-size:.82rem;
  font-family:inherit;
  background:#fff;
  color:#0f172a;
}
.rm-grid input:focus,.rm-grid select:focus,.rm-grid textarea:focus{
  outline:none;
  border-color:#0ea5e9;
  box-shadow:0 0 0 3px rgba(14,165,233,.16);
}

.rm-note{
  background:linear-gradient(165deg,#f8fafc 0%,#ecfeff 100%);
  border:1px solid #cfe4ff;
  color:#334155;
  font-size:.8rem;
  padding:10px;
  border-radius:10px;
}

.rm-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.rm-queue-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}

.rm-table{border-collapse:separate;border-spacing:0}
.rm-table thead th{
  background:#ecfeff;
  color:#0f172a;
  font-size:.72rem;
  letter-spacing:.3px;
  text-transform:uppercase;
  border-bottom:1px solid #bae6fd;
}
.rm-table tbody tr:nth-child(even){background:#f8fafc}
.rm-table tbody tr:hover{background:#ecfeff}
.rm-table td,.rm-table th{vertical-align:middle}
.rm-table tbody tr.rm-clickable-row{cursor:pointer}
.rm-table tbody tr.rm-clickable-row:hover{background:#dff7ff}
.rm-panel .card-body{overflow:auto}
.rm-panel .table{min-width:860px}

.rm-thumb{
  width:44px;
  height:44px;
  border-radius:8px;
  border:1px solid #cbd5e1;
  object-fit:cover;
  display:block;
  background:#e2e8f0;
}
.rm-thumb-empty{
  width:44px;
  height:44px;
  border-radius:8px;
  border:1px dashed #cbd5e1;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:.65rem;
  color:#64748b;
}

.rm-table .urgent{color:var(--rm-danger);font-weight:800}
.rm-status{padding:2px 8px;border-radius:999px;font-size:.68rem;font-weight:800;text-transform:uppercase;display:inline-flex}
.rm-pending{background:#fef3c7;color:#92400e}
.rm-approved{background:#dcfce7;color:#166534}
.rm-rejected{background:#fee2e2;color:#991b1b}
.rm-po-created{background:#dbeafe;color:#1e3a8a}

.rm-panel .btn.btn-primary{
  background:linear-gradient(95deg,#0f766e,#0e7490);
  border-color:#0f766e;
}
.rm-panel .btn.btn-primary:hover{filter:brightness(1.05)}

.rm-detail-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:12000;padding:18px;overflow:auto}
.rm-detail-dialog{max-width:980px;margin:0 auto;border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 20px 45px rgba(2,6,23,.3)}
.rm-detail-head{padding:12px 14px;background:linear-gradient(95deg,#0f766e,#0e7490);color:#fff;display:flex;justify-content:space-between;align-items:center;gap:8px}
.rm-detail-head h5{margin:0;font-size:.95rem;font-weight:800}
.rm-detail-body{padding:14px}
.rm-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:10px}
.rm-detail-card{background:#f8fafc;border:1px solid #dbeafe;border-radius:10px;padding:8px 10px}
.rm-detail-card span{display:block;color:#64748b;font-size:.68rem;text-transform:uppercase;font-weight:700}
.rm-detail-card strong{display:block;color:#0f172a;font-size:.86rem;margin-top:2px}
.rm-items-wrap{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.rm-item-tile{border:1px solid #dbeafe;border-radius:12px;padding:10px;background:#ffffff}
.rm-item-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.rm-item-img{width:100%;max-height:170px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc}
.rm-detail-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:12px;padding-top:12px;border-top:1px dashed #cbd5e1}
.rm-btn-accept{background:#15803d;color:#fff;border-color:#15803d}
.rm-btn-reject{background:#b91c1c;color:#fff;border-color:#b91c1c}
.rm-edit-item-row{border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;padding:8px}

.rm-confirm-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:13000;padding:14px}
.rm-confirm-dialog{max-width:430px;margin:10vh auto 0;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 44px rgba(2,6,23,.34)}
.rm-confirm-head{padding:11px 12px;background:linear-gradient(95deg,#0e7490,#0f766e);color:#fff;font-weight:800;font-size:.86rem}
.rm-confirm-body{padding:14px;color:#334155;font-size:.86rem}
.rm-confirm-actions{display:flex;justify-content:flex-end;gap:8px;padding:0 14px 14px}

@keyframes rmFadeIn{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:translateY(0)}}

@media (max-width: 900px){
  .rm-shell{padding:11px}
  .rm-headline{padding:10px 11px}
  .rm-tabs{
    flex-wrap:nowrap;
    overflow-x:auto;
    overflow-y:hidden;
    padding-bottom:4px;
    -webkit-overflow-scrolling:touch;
  }
  .rm-tab{
    flex:0 0 auto;
    min-height:38px;
    white-space:nowrap;
  }
  .rm-grid{grid-template-columns:1fr}
  .rm-grid .rm-col-2{grid-column:span 1}
  .rm-tab{font-size:.72rem}
  #rm-item-rows .rm-item-row .rm-grid{grid-template-columns:1fr !important}
  .rm-item-row{padding:8px}

  .rm-panel .table{min-width:760px}
  .rm-table th,.rm-table td{font-size:.73rem;padding:7px 6px}
  .rm-thumb,.rm-thumb-empty{width:38px;height:38px}

  .rm-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .rm-queue-grid{grid-template-columns:1fr;gap:8px}

  .rm-detail-modal{padding:8px}
  .rm-detail-dialog{max-width:100%;border-radius:12px}
  .rm-detail-head{padding:10px 10px}
  .rm-detail-body{padding:10px}
  .rm-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .rm-items-wrap{grid-template-columns:1fr}
  .rm-item-img{max-height:130px}
  .rm-detail-actions{position:sticky;bottom:0;background:#fff;z-index:2;padding:10px 0 0;margin-top:10px}
  .rm-detail-actions .btn{flex:1 1 calc(50% - 8px);min-height:38px}
  .rm-confirm-modal{padding:8px}
  .rm-confirm-dialog{margin-top:18vh;max-width:100%}
}

@media (max-width: 520px){
  .rm-shell{padding:9px;border-radius:14px}
  .rm-headline strong{font-size:.86rem}
  .rm-headline span{font-size:.72rem}
  .rm-kpi-grid{grid-template-columns:1fr}
  .rm-detail-grid{grid-template-columns:1fr}
  .rm-detail-actions .btn{flex:1 1 100%}
}
</style>

<div id="rm-toast-anchor" style="position:fixed;top:16px;right:16px;z-index:9999;"></div>

<div class="rm-shell">
  <div class="rm-headline">
    <strong>Unified Requisition Workflow</strong>
    <span>Request creation, approval routing, and PO conversion in one visual panel.</span>
  </div>

  <div class="rm-tabs no-print">
    <button type="button" class="rm-tab active" data-pane="dashboard">Dashboard</button>
    <button type="button" class="rm-tab" data-pane="user">New Requisition</button>
    <button type="button" class="rm-tab" data-pane="my">My Requests</button>
    <button type="button" class="rm-tab<?= $rmCanAdmin ? '' : ' disabled' ?>" data-pane="admin">Approval Panel (Admin)</button>
    <button type="button" class="rm-tab<?= $rmCanAccounts ? '' : ' disabled' ?>" data-pane="accounts">PO Management (Accounts)</button>
  </div>

  <div class="rm-pane active" id="pane-dashboard">
    <?php include __DIR__ . '/dashboard.php'; ?>
  </div>
  <div class="rm-pane" id="pane-user">
    <?php include __DIR__ . '/user.php'; ?>
  </div>
  <div class="rm-pane" id="pane-my">
    <?php include __DIR__ . '/my.php'; ?>
  </div>
  <div class="rm-pane" id="pane-admin">
    <?php include __DIR__ . '/admin.php'; ?>
  </div>
  <div class="rm-pane" id="pane-accounts">
    <?php include __DIR__ . '/accounts.php'; ?>
  </div>
</div>

<div class="rm-detail-modal" id="rm-detail-modal" aria-hidden="true">
  <div class="rm-detail-dialog">
    <div class="rm-detail-head">
      <h5>Requisition Details</h5>
      <button type="button" class="btn btn-sm" onclick="rmCloseDetailModal()">Close</button>
    </div>
    <div class="rm-detail-body">
      <div class="rm-detail-grid">
        <div class="rm-detail-card"><span>Req ID</span><strong id="rm-detail-id">-</strong></div>
        <div class="rm-detail-card"><span>Requested By</span><strong id="rm-detail-user">-</strong></div>
        <div class="rm-detail-card"><span>Department</span><strong id="rm-detail-department">-</strong></div>
        <div class="rm-detail-card"><span>Status</span><strong id="rm-detail-status">-</strong></div>
        <div class="rm-detail-card"><span>Required Date</span><strong id="rm-detail-required">-</strong></div>
        <div class="rm-detail-card"><span>Priority</span><strong id="rm-detail-priority">-</strong></div>
        <div class="rm-detail-card"><span>Total Qty</span><strong id="rm-detail-qty">-</strong></div>
        <div class="rm-detail-card"><span>Created</span><strong id="rm-detail-created">-</strong></div>
      </div>

      <div class="rm-grid" style="margin-bottom:10px;">
        <div>
          <label>Edit Required Date</label>
          <input type="date" id="rm-edit-required-date">
        </div>
        <div>
          <label>Edit Priority</label>
          <select id="rm-edit-priority">
            <option value="Normal">Normal</option>
            <option value="Urgent">Urgent</option>
          </select>
        </div>
        <div class="rm-col-2">
          <label>Remarks / Admin Comment</label>
          <textarea id="rm-edit-remarks" rows="2" placeholder="Write comments..."></textarea>
        </div>
      </div>

      <div class="rm-items-wrap" id="rm-detail-items"></div>

      <div class="rm-detail-actions">
        <button type="button" class="btn" id="rm-btn-print" onclick="rmPrintDetail()"><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn" id="rm-btn-edit" onclick="rmUpdateRequisitionFromDetail()"><i class="bi bi-pencil-square"></i> Edit/Save</button>
        <button type="button" class="btn" id="rm-btn-delete" onclick="rmDeleteRequisitionFromDetail()"><i class="bi bi-trash"></i> Delete</button>
        <button type="button" class="btn rm-btn-accept" id="rm-btn-accept" onclick="rmAdminDecisionFromDetail('approved')"><i class="bi bi-check-circle"></i> Accept</button>
        <button type="button" class="btn rm-btn-reject" id="rm-btn-reject" onclick="rmAdminDecisionFromDetail('rejected')"><i class="bi bi-x-circle"></i> Reject</button>
      </div>
    </div>
  </div>
</div>

<div class="rm-confirm-modal" id="rm-confirm-modal" aria-hidden="true">
  <div class="rm-confirm-dialog">
    <div class="rm-confirm-head" id="rm-confirm-title">Please Confirm</div>
    <div class="rm-confirm-body" id="rm-confirm-message">Are you sure?</div>
    <div class="rm-confirm-actions">
      <button type="button" class="btn" id="rm-confirm-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="rm-confirm-ok">OK</button>
    </div>
  </div>
</div>

<script>
const RM_API = '<?= BASE_URL ?>/modules/requisition-management/api.php';
const RM_CSRF = <?= json_encode($csrf) ?>;
const RM_CAN_ADMIN = <?= $rmCanAdmin ? 'true' : 'false' ?>;
const RM_CAN_ACCOUNTS = <?= $rmCanAccounts ? 'true' : 'false' ?>;
const RM_COMPANY_NAME = <?= json_encode((string)$rmCompanyName) ?>;
let rmDetailRow = null;
let rmDetailSource = 'general';
let rmConfirmResolver = null;

function rmNotify(msg, type) {
  if (typeof window.showERPToast === 'function') {
    window.showERPToast(msg, type || 'info');
    return;
  }
  const node = document.createElement('div');
  node.style.cssText = 'background:#111827;color:#fff;padding:10px 12px;border-radius:8px;margin-top:8px;box-shadow:0 6px 18px rgba(0,0,0,.2);font-size:.8rem';
  node.textContent = msg;
  document.getElementById('rm-toast-anchor').appendChild(node);
  setTimeout(() => node.remove(), 2500);
}

function rmStatusBadge(status) {
  const s = String(status || '').toLowerCase();
  const cls = s === 'approved' ? 'rm-approved'
    : s === 'rejected' ? 'rm-rejected'
    : s === 'po_created' ? 'rm-po-created'
    : 'rm-pending';
  return `<span class="rm-status ${cls}">${s.replace('_',' ')}</span>`;
}

function rmItemSummaryHtml(r) {
  if (Array.isArray(r.items) && r.items.length) {
    return r.items.map((it, idx) => {
      const line = `${idx + 1}. ${it.item_name} - ${it.qty} ${it.unit} (${it.category})`;
      const img = it.item_image_url ? ' <span style="font-size:.68rem;color:#0369a1;font-weight:700;">[Image]</span>' : '';
      return `<div>${line}${img}</div>`;
    }).join('');
  }
  return r.item_name || '-';
}

function rmThumbHtml(r) {
  const first = Array.isArray(r.items) && r.items.length ? r.items.find(it => it.item_image_url) : null;
  if (first && first.item_image_url) {
    return `<img class="rm-thumb" src="${first.item_image_url}" alt="Item image">`;
  }
  return '<div class="rm-thumb-empty">No Img</div>';
}

function rmEditableItemRowHtml(it, idx) {
  const itemId = it && it.id ? it.id : '';
  const itemName = it && it.item_name ? it.item_name : '';
  const category = it && it.category ? it.category : 'Others';
  const qty = it && it.qty ? it.qty : '';
  const unit = it && it.unit ? it.unit : 'Nos';
  const itemRemarks = it && it.item_remarks ? it.item_remarks : '';
  const imageUrl = it && it.item_image_url ? it.item_image_url : '';
  return `
    <div class="rm-edit-item-row" data-item-id="${rmEsc(itemId)}">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
        <div style="font-size:.72rem;font-weight:800;color:#0f172a;" class="rm-edit-item-serial">Item #${idx + 1}</div>
        <button type="button" class="btn btn-sm" onclick="rmRemoveEditableItemRow(this)"><i class="bi bi-trash"></i> Remove</button>
      </div>
      <div class="rm-grid" style="grid-template-columns:2fr 1fr 1fr 1fr;gap:8px;">
        <div>
          <label>Item Name</label>
          <input type="text" class="rm-edit-item-name" value="${rmEsc(itemName)}" required>
        </div>
        <div>
          <label>Category</label>
          <select class="rm-edit-item-category">
            ${['Paper','Ink','Plate','Consumable','Stationary','Others'].map(c => `<option value="${c}" ${String(category) === c ? 'selected' : ''}>${c}</option>`).join('')}
          </select>
        </div>
        <div>
          <label>Qty</label>
          <input type="number" min="0.01" step="0.01" class="rm-edit-item-qty" value="${rmEsc(qty)}" required>
        </div>
        <div>
          <label>Unit</label>
          <select class="rm-edit-item-unit">
            ${['Kg','Nos','Meter'].map(u => `<option value="${u}" ${String(unit) === u ? 'selected' : ''}>${u}</option>`).join('')}
          </select>
        </div>
      </div>
      <div class="rm-grid" style="grid-template-columns:1fr 180px;gap:8px;margin-top:8px;align-items:start;">
        <div>
          <label>Item Remark</label>
          <input type="text" class="rm-edit-item-remarks" value="${rmEsc(itemRemarks)}" placeholder="Item remark...">
        </div>
        <div>
          <label>Change Image</label>
          <input type="file" class="rm-edit-item-image" accept="image/*" capture="environment">
          <div style="margin-top:6px;">
            ${imageUrl
              ? `<a href="${imageUrl}" target="_blank" rel="noopener"><img src="${imageUrl}" class="rm-edit-item-image-preview" alt="Item image" style="width:100%;height:80px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1"></a>`
              : '<div class="rm-note rm-edit-item-image-empty" style="text-align:center;padding:18px 8px;">No image</div>'}
          </div>
        </div>
      </div>
    </div>
  `;
}

function rmReindexEditableItemRows() {
  document.querySelectorAll('#rm-edit-item-rows .rm-edit-item-row').forEach((row, idx) => {
    const serial = row.querySelector('.rm-edit-item-serial');
    if (serial) serial.textContent = `Item #${idx + 1}`;
  });
}

function rmBindEditableImagePreview(row) {
  if (!row) return;
  const input = row.querySelector('.rm-edit-item-image');
  if (!input) return;
  input.addEventListener('change', () => {
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) return;
    if (!/^image\//i.test(file.type)) {
      rmNotify('Please select a valid image file', 'bad');
      input.value = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
      const src = e.target && e.target.result ? String(e.target.result) : '';
      let preview = row.querySelector('.rm-edit-item-image-preview');
      if (!preview) {
        const empty = row.querySelector('.rm-edit-item-image-empty');
        if (empty) empty.remove();
        preview = document.createElement('img');
        preview.className = 'rm-edit-item-image-preview';
        preview.alt = 'Item image';
        preview.style.cssText = 'width:100%;height:80px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1';
        const box = row.querySelector('.rm-edit-item-image')?.parentElement?.querySelector('div');
        if (box) box.appendChild(preview);
      }
      preview.src = src;
    };
    reader.readAsDataURL(file);
  });
}

function rmAddEditableItemRow() {
  const host = document.getElementById('rm-edit-item-rows');
  if (!host) return;
  host.insertAdjacentHTML('beforeend', rmEditableItemRowHtml({}, host.querySelectorAll('.rm-edit-item-row').length));
  const rows = host.querySelectorAll('.rm-edit-item-row');
  const last = rows.length ? rows[rows.length - 1] : null;
  rmBindEditableImagePreview(last);
  rmReindexEditableItemRows();
}

function rmRemoveEditableItemRow(btn) {
  const host = document.getElementById('rm-edit-item-rows');
  if (!host) return;
  const rows = host.querySelectorAll('.rm-edit-item-row');
  if (rows.length <= 1) {
    rmNotify('At least one item is required', 'bad');
    return;
  }
  const row = btn.closest('.rm-edit-item-row');
  if (row) row.remove();
  rmReindexEditableItemRows();
}

function rmEsc(str) {
  return String(str || '').replace(/[&<>'"]/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;'
  }[ch]));
}

function rmAbsUrl(url) {
  const raw = String(url || '').trim();
  if (!raw) return '';
  if (/^https?:\/\//i.test(raw) || /^data:/i.test(raw)) return raw;
  if (raw.startsWith('/')) return window.location.origin + raw;
  const base = window.location.href.replace(/\/[^/]*$/, '/');
  return new URL(raw, base).toString();
}

function rmActivatePane(pane) {
  const targetTab = document.querySelector(`.rm-tab[data-pane="${pane}"]`);
  const targetPane = document.getElementById('pane-' + pane);
  if (!targetTab || !targetPane) return;
  document.querySelectorAll('.rm-tab').forEach(b => b.classList.remove('active'));
  targetTab.classList.add('active');
  document.querySelectorAll('.rm-pane').forEach(p => p.classList.remove('active'));
  targetPane.classList.add('active');
}

async function rmApiGet(params = {}) {
  const qs = new URLSearchParams(params);
  const res = await fetch(RM_API + '?' + qs.toString(), { credentials: 'same-origin' });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}

async function rmApiPost(formData) {
  formData.append('csrf_token', RM_CSRF);
  const res = await fetch(RM_API, { method: 'POST', body: formData, credentials: 'same-origin' });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function rmCustomConfirm(message, title = 'Please Confirm') {
  const modal = document.getElementById('rm-confirm-modal');
  const titleNode = document.getElementById('rm-confirm-title');
  const messageNode = document.getElementById('rm-confirm-message');
  const okBtn = document.getElementById('rm-confirm-ok');
  const cancelBtn = document.getElementById('rm-confirm-cancel');

  if (!modal || !titleNode || !messageNode || !okBtn || !cancelBtn) {
    return Promise.resolve(false);
  }

  titleNode.textContent = title;
  messageNode.textContent = String(message || 'Are you sure?');
  modal.style.display = 'block';
  modal.setAttribute('aria-hidden', 'false');

  return new Promise((resolve) => {
    rmConfirmResolver = resolve;
    const done = (result) => {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      modal.removeEventListener('click', onBackdrop);
      if (rmConfirmResolver) {
        rmConfirmResolver = null;
        resolve(result);
      }
    };
    const onOk = () => done(true);
    const onCancel = () => done(false);
    const onBackdrop = (e) => {
      if (e.target === modal) done(false);
    };

    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
    modal.addEventListener('click', onBackdrop);
  });
}

function rmSetText(id, value) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = String(value ?? '0');
}

function rmReindexItemRows() {
  document.querySelectorAll('#rm-item-rows .rm-item-row').forEach((row, i) => {
    const serial = row.querySelector('.rm-item-serial');
    if (serial) serial.textContent = `Item #${i + 1}`;
  });
}

function rmBindItemImagePreview(row) {
  if (!row) return;
  const input = row.querySelector('input[name="item_image[]"]');
  const wrap = row.querySelector('.rm-item-preview-wrap');
  const img = row.querySelector('.rm-item-preview');
  if (!input || !wrap || !img) return;

  input.addEventListener('change', () => {
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) {
      img.removeAttribute('src');
      wrap.style.display = 'none';
      return;
    }
    if (!/^image\//i.test(file.type)) {
      rmNotify('Please select a valid image file', 'bad');
      input.value = '';
      img.removeAttribute('src');
      wrap.style.display = 'none';
      return;
    }
    const reader = new FileReader();
    reader.onload = (e) => {
      img.src = e.target && e.target.result ? String(e.target.result) : '';
      wrap.style.display = '';
    };
    reader.readAsDataURL(file);
  });
}

function rmAddItemRow() {
  const host = document.getElementById('rm-item-rows');
  const tpl = document.getElementById('rm-item-row-template');
  if (!host || !tpl) return;
  const node = tpl.content.cloneNode(true);
  host.appendChild(node);
  const rows = host.querySelectorAll('.rm-item-row');
  const lastRow = rows.length ? rows[rows.length - 1] : null;
  rmBindItemImagePreview(lastRow);
  rmReindexItemRows();
}

function rmRemoveItemRow(btn) {
  const host = document.getElementById('rm-item-rows');
  if (!host) return;
  const rows = host.querySelectorAll('.rm-item-row');
  if (rows.length <= 1) {
    rmNotify('At least one item is required', 'bad');
    return;
  }
  const row = btn.closest('.rm-item-row');
  if (row) row.remove();
  rmReindexItemRows();
}

async function rmLoadDashboard() {
  try {
    const data = await rmApiGet({ action: 'dashboard_summary' });
    const s = data.summary || {};
    rmSetText('rm-kpi-total', s.total_requisitions || 0);
    rmSetText('rm-kpi-pending', s.pending || 0);
    rmSetText('rm-kpi-approved', s.approved || 0);
    rmSetText('rm-kpi-rejected', s.rejected || 0);
    rmSetText('rm-kpi-po', s.po_created || 0);

    const adminWrap = document.getElementById('rm-admin-queue-wrap');
    if (adminWrap) {
      if (RM_CAN_ADMIN) {
        adminWrap.style.display = '';
        rmSetText('rm-kpi-admin-pending', s.pending_admin_queue || 0);
      } else {
        adminWrap.style.display = 'none';
      }
    }

    const accWrap = document.getElementById('rm-accounts-queue-wrap');
    if (accWrap) {
      if (RM_CAN_ACCOUNTS) {
        accWrap.style.display = '';
        rmSetText('rm-kpi-accounts-approved', s.approved_accounts_queue || 0);
        rmSetText('rm-kpi-accounts-po-count', s.total_purchase_orders || 0);
      } else {
        accWrap.style.display = 'none';
      }
    }
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

function rmSetTabs() {
  document.querySelectorAll('.rm-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const pane = btn.dataset.pane;
      if (pane === 'admin' && !RM_CAN_ADMIN) return rmNotify('Admin/Manager role required', 'bad');
      if (pane === 'accounts' && !RM_CAN_ACCOUNTS) return rmNotify('Accounts/Purchase role required', 'bad');
      rmActivatePane(pane);
    });
  });
}

async function rmLoadMy() {
  try {
    const data = await rmApiGet({ action: 'list_my' });
    const body = document.querySelector('#rm-my-table tbody');
    if (!body) return;
    if (!data.rows.length) {
      body.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#64748b">No requisitions found.</td></tr>';
      return;
    }
    body.innerHTML = data.rows.map(r => `
      <tr>
        <td>#${r.id}</td>
        <td>${r.created_at || '-'}</td>
        <td>${rmThumbHtml(r)}</td>
        <td>${rmItemSummaryHtml(r)}</td>
        <td>${r.qty} ${r.unit || ''}</td>
        <td>${rmStatusBadge(r.status)}</td>
        <td>${r.current_stock_hint || 'N/A'}</td>
        <td>${r.admin_comment || '-'}</td>
        <td>
          <button class="btn btn-sm" type="button" onclick="rmOpenDetailById(${r.id}, 'my')"><i class="bi bi-pencil-square"></i> Edit</button>
          <button class="btn btn-sm btn-primary" type="button" onclick="rmPrintById(${r.id})"><i class="bi bi-printer"></i> Print</button>
        </td>
      </tr>
    `).join('');
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

async function rmLoadAdmin() {
  if (!RM_CAN_ADMIN) return;
  try {
    const data = await rmApiGet({ action: 'list_admin' });
    const body = document.querySelector('#rm-admin-table tbody');
    if (!body) return;
    if (!data.rows.length) {
      body.innerHTML = '<tr><td colspan="11" style="text-align:center;color:#64748b">No requisitions found.</td></tr>';
      return;
    }
    body.innerHTML = data.rows.map(r => {
      const pr = String(r.priority || '').toLowerCase() === 'urgent' ? 'urgent' : '';
      const attach = r.attachment
        ? `<a href="${r.attachment_url}" target="_blank" rel="noopener">View</a>`
        : '-';
      return `
      <tr class="rm-clickable-row" onclick="rmOpenDetailById(${r.id}, 'admin')">
        <td>#${r.id}</td>
        <td>${r.user_name || ('User '+r.user_id)}</td>
        <td>${r.department}</td>
        <td>${rmItemSummaryHtml(r)}</td>
        <td>${r.category}</td>
        <td>${r.qty} ${r.unit}</td>
        <td>${rmStatusBadge(r.status)}</td>
        <td>${r.required_date || '-'}</td>
        <td class="${pr}">${r.priority}</td>
        <td>${attach}</td>
        <td><button class="btn btn-sm btn-primary" type="button" onclick="event.stopPropagation(); rmOpenDetailById(${r.id}, 'admin')">Open</button></td>
      </tr>`;
    }).join('');
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

async function rmLoadApproved() {
  if (!RM_CAN_ACCOUNTS) return;
  try {
    const [approved, pos] = await Promise.all([
      rmApiGet({ action: 'list_approved_accounts' }),
      rmApiGet({ action: 'list_po' })
    ]);

    const b1 = document.querySelector('#rm-acc-table tbody');
    if (b1) {
      if (!approved.rows.length) {
        b1.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#64748b">No approved requisitions.</td></tr>';
      } else {
        b1.innerHTML = approved.rows.map(r => `
          <tr>
            <td>#${r.id}</td>
            <td>${r.department}</td>
            <td>${rmItemSummaryHtml(r)}</td>
            <td>${r.category}</td>
            <td>${r.qty}</td>
            <td>${r.unit}</td>
            <td>${r.required_date || '-'}</td>
            <td>${r.approved_by_name || '-'}</td>
            <td><button class="btn btn-sm btn-primary" onclick='rmOpenPoModal(${JSON.stringify(r)})'>Generate PO</button></td>
          </tr>
        `).join('');
      }
    }

    const b2 = document.querySelector('#rm-po-table tbody');
    if (b2) {
      if (!pos.rows.length) {
        b2.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#64748b">No purchase orders yet.</td></tr>';
      } else {
        b2.innerHTML = pos.rows.map(p => `
          <tr>
            <td>#${p.id}</td>
            <td>#${p.requisition_id}</td>
            <td>${p.vendor_name}</td>
            <td>${p.rate}</td>
            <td>${p.gst}</td>
            <td>${p.total_amount}</td>
            <td>${p.delivery_date || '-'}</td>
            <td>${p.payment_terms || '-'}</td>
            <td>${p.created_at || '-'}</td>
          </tr>
        `).join('');
      }
    }
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

async function rmOpenDetailById(id, source = 'general') {
  try {
    const data = await rmApiGet({ action: 'get_requisition_detail', id });
    const r = data.row || null;
    if (!r) return;
    rmDetailRow = r;
    rmDetailSource = source;

    document.getElementById('rm-detail-id').textContent = '#' + r.id;
    document.getElementById('rm-detail-user').textContent = r.user_name || ('User ' + r.user_id);
    document.getElementById('rm-detail-department').textContent = r.department || '-';
    document.getElementById('rm-detail-status').innerHTML = rmStatusBadge(r.status);
    document.getElementById('rm-detail-required').textContent = r.required_date || '-';
    document.getElementById('rm-detail-priority').textContent = r.priority || '-';
    document.getElementById('rm-detail-qty').textContent = `${r.qty || 0} ${r.unit || ''}`;
    document.getElementById('rm-detail-created').textContent = r.created_at_fmt || '-';

    const requiredInput = document.getElementById('rm-edit-required-date');
    const priorityInput = document.getElementById('rm-edit-priority');
    const remarksInput = document.getElementById('rm-edit-remarks');
    requiredInput.value = r.required_date || '';
    priorityInput.value = r.priority || 'Normal';
    remarksInput.value = r.remarks || r.admin_comment || '';

    const itemsWrap = document.getElementById('rm-detail-items');
    const items = Array.isArray(r.items) ? r.items : [];
    if (!items.length) {
      itemsWrap.innerHTML = '<div class="rm-note">No item details available.</div>';
    } else {
      const isMySource = rmDetailSource === 'my';
      if (isMySource && r.can_edit) {
        itemsWrap.innerHTML = `
          <div class="rm-section-head" style="margin-bottom:8px;">
            <label style="margin:0">Editable Items (Before Approval)</label>
            <button type="button" class="btn btn-sm btn-primary" onclick="rmAddEditableItemRow()"><i class="bi bi-plus-circle"></i> Add Item</button>
          </div>
          <div id="rm-edit-item-rows" style="display:grid;gap:8px;">${items.map((it, idx) => rmEditableItemRowHtml(it, idx)).join('')}</div>
        `;
        itemsWrap.querySelectorAll('.rm-edit-item-row').forEach((row) => rmBindEditableImagePreview(row));
        rmReindexEditableItemRows();
      } else {
        itemsWrap.innerHTML = items.map(it => `
          <div class="rm-item-tile">
            <div class="rm-item-head">
              <strong>#${it.sl_no} ${rmEsc(it.item_name)}</strong>
              ${rmStatusBadge(r.status)}
            </div>
            <div style="font-size:.8rem;color:#334155;margin-bottom:6px;">${rmEsc(it.qty)} ${rmEsc(it.unit)} | ${rmEsc(it.category)}</div>
            ${it.item_image_url ? `<img class="rm-item-img" src="${it.item_image_url}" alt="Item image">` : '<div class="rm-note" style="text-align:center;">No image</div>'}
            <div style="margin-top:6px;font-size:.78rem;color:#475569;">${rmEsc(it.item_remarks || '-')}</div>
          </div>
        `).join('');
      }
    }

    const isPending = String(r.status || '') === 'pending';
    const isMySource = rmDetailSource === 'my';
    const isAdminSource = rmDetailSource === 'admin';
    const acceptBtn = document.getElementById('rm-btn-accept');
    const rejectBtn = document.getElementById('rm-btn-reject');
    acceptBtn.style.display = (isAdminSource && RM_CAN_ADMIN) ? '' : 'none';
    rejectBtn.style.display = (isAdminSource && RM_CAN_ADMIN) ? '' : 'none';
    acceptBtn.disabled = !isPending;
    rejectBtn.disabled = !isPending;
    acceptBtn.title = isPending ? '' : 'Only pending requisition can be accepted';
    rejectBtn.title = isPending ? '' : 'Only pending requisition can be rejected';

    const editBtn = document.getElementById('rm-btn-edit');
    editBtn.style.display = (isMySource || isAdminSource || r.can_edit) ? '' : 'none';
    if (isMySource && !r.can_edit) {
      editBtn.disabled = true;
      editBtn.title = 'Only pending requisition can be edited';
    } else {
      editBtn.disabled = false;
      editBtn.title = '';
    }
    document.getElementById('rm-btn-delete').style.display = isAdminSource ? '' : ((!isMySource && r.can_delete) ? '' : 'none');

    const modal = document.getElementById('rm-detail-modal');
    modal.style.display = 'block';
    modal.setAttribute('aria-hidden', 'false');
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

function rmCloseDetailModal() {
  const modal = document.getElementById('rm-detail-modal');
  modal.style.display = 'none';
  modal.setAttribute('aria-hidden', 'true');
  rmDetailRow = null;
  rmDetailSource = 'general';
}

async function rmPrintById(id) {
  const data = await rmApiGet({ action: 'get_requisition_detail', id });
  rmDetailRow = data.row || null;
  rmDetailSource = 'my';
  if (!rmDetailRow) {
    rmNotify('Requisition details not found', 'bad');
    return;
  }
  rmPrintDetail();
}

async function rmAdminDecisionFromDetail(decision) {
  if (!rmDetailRow) return;
  try {
    const fd = new FormData();
    fd.append('action', 'admin_decide');
    fd.append('id', String(rmDetailRow.id));
    fd.append('decision', decision);
    fd.append('approved_qty', String(rmDetailRow.qty || 0));
    fd.append('admin_comment', document.getElementById('rm-edit-remarks').value || '');
    await rmApiPost(fd);
    rmNotify(decision === 'approved' ? 'Accepted successfully' : 'Rejected successfully', 'good');
    rmCloseDetailModal();
    rmLoadDashboard();
    rmLoadAdmin();
    rmLoadMy();
    rmLoadApproved();
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

async function rmUpdateRequisitionFromDetail() {
  if (!rmDetailRow) return;
  try {
    const fd = new FormData();
    fd.append('action', 'update_requisition');
    fd.append('id', String(rmDetailRow.id));
    fd.append('required_date', document.getElementById('rm-edit-required-date').value || '');
    fd.append('priority', document.getElementById('rm-edit-priority').value || 'Normal');
    fd.append('remarks', document.getElementById('rm-edit-remarks').value || '');

    if (rmDetailSource === 'my') {
      const itemRows = document.querySelectorAll('#rm-edit-item-rows .rm-edit-item-row');
      if (!itemRows.length) {
        rmNotify('At least one item is required', 'bad');
        return;
      }
      itemRows.forEach((row, idx) => {
        fd.append('item_id[]', row.getAttribute('data-item-id') || '');
        fd.append('item_name[]', (row.querySelector('.rm-edit-item-name')?.value || '').trim());
        fd.append('category[]', row.querySelector('.rm-edit-item-category')?.value || '');
        fd.append('qty[]', row.querySelector('.rm-edit-item-qty')?.value || '0');
        fd.append('unit[]', row.querySelector('.rm-edit-item-unit')?.value || '');
        fd.append('item_remarks[]', (row.querySelector('.rm-edit-item-remarks')?.value || '').trim());
        const imageInput = row.querySelector('.rm-edit-item-image');
        if (imageInput && imageInput.files && imageInput.files[0]) {
          fd.append('item_image_new_' + idx, imageInput.files[0]);
        }
      });
    }

    await rmApiPost(fd);
    rmNotify('Requisition updated', 'good');
    await rmOpenDetailById(rmDetailRow.id, rmDetailSource || 'general');
    rmLoadMy();
    rmLoadAdmin();
    rmLoadDashboard();
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

async function rmDeleteRequisitionFromDetail() {
  if (!rmDetailRow) return;
  const ok = await rmCustomConfirm('Delete this requisition?', 'Delete Requisition');
  if (!ok) return;
  try {
    const fd = new FormData();
    fd.append('action', 'delete_requisition');
    fd.append('id', String(rmDetailRow.id));
    await rmApiPost(fd);
    rmNotify('Requisition deleted', 'good');
    rmCloseDetailModal();
    rmLoadMy();
    rmLoadAdmin();
    rmLoadDashboard();
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

function rmPrintDetail() {
  if (!rmDetailRow) return;
  const items = Array.isArray(rmDetailRow.items) ? rmDetailRow.items : [];
  const requester = rmDetailRow.user_name || ('User ' + (rmDetailRow.user_id || '-'));
  const requesterId = rmDetailRow.user_id || '-';
  const approvedBy = rmDetailRow.approved_by_name || '-';
  const created = rmDetailRow.created_at_fmt || '-';
  const remarks = rmDetailRow.remarks || rmDetailRow.admin_comment || '-';
  const printedAt = new Date().toLocaleString();

  const rows = items.map(it => {
    const img = it.item_image_url ? `<img src="${rmAbsUrl(it.item_image_url)}" alt="item">` : '<span style="color:#94a3b8">No image</span>';
    return `<tr>
      <td>${rmEsc(it.sl_no)}</td>
      <td>${rmEsc(it.item_name)}</td>
      <td>${rmEsc(it.category)}</td>
      <td>${rmEsc(it.qty)} ${rmEsc(it.unit)}</td>
      <td>${rmEsc(it.item_remarks || '-')}</td>
      <td>${img}</td>
    </tr>`;
  }).join('');

  const html = `
    <html>
      <head>
        <title>Requisition #${rmDetailRow.id}</title>
        <style>
          @page{margin:12mm}
          *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;box-sizing:border-box}
          body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;margin:0}
          .sheet{border:1px solid #cbd5e1;border-radius:10px;overflow:hidden}
          .head{padding:12px 14px;background:linear-gradient(95deg,#14532d,#166534);color:#fff;display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
          .head h2{margin:0;font-size:1rem}
          .head .sub{font-size:.72rem;opacity:.9;margin-top:2px}
          .head .meta{font-size:.7rem;text-align:right;line-height:1.45}
          .titlebar{padding:7px 12px;background:#dcfce7;color:#166534;font-size:.72rem;font-weight:800;display:flex;justify-content:space-between;border-bottom:1px solid #bbf7d0}
          .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:10px 12px;border-bottom:1px solid #e2e8f0}
          .cell{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 8px}
          .cell span{display:block;font-size:.58rem;text-transform:uppercase;color:#64748b;font-weight:800;letter-spacing:.04em}
          .cell strong{display:block;font-size:.74rem;margin-top:2px}
          .section{padding:10px 12px}
          .section-title{font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#166534;background:#dcfce7;padding:5px 8px;border-radius:4px}
          table{width:100%;border-collapse:collapse}
          th,td{border:1px solid #cbd5e1;padding:6px;text-align:left;font-size:.72rem;vertical-align:top}
          th{background:#f8fafc;font-size:.64rem;text-transform:uppercase;letter-spacing:.04em;color:#334155}
          img{width:86px;height:86px;object-fit:cover;border:1px solid #cbd5e1;border-radius:6px;display:block}
          .foot-sign{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-top:2px solid #166534;background:#f0fdf4;color:#475569;font-size:.68rem}
          .sign-box{flex:1}
          .sign-line{padding-top:20px;border-bottom:1px solid #64748b;min-height:22px;margin-bottom:5px;font-size:.72rem;font-weight:700;color:#0f172a}
          .sign-label{font-size:.64rem;color:#475569}
          .foot-doc{padding:6px 12px;font-size:.58rem;color:#64748b;display:flex;justify-content:space-between;border-top:1px solid #bbf7d0;background:#f8fffa}
        </style>
      </head>
      <body>
        <div class="sheet">
          <div class="head">
            <div>
              <h2>${rmEsc(RM_COMPANY_NAME || 'ERP')}</h2>
              <div class="sub">Requisition Management Document</div>
            </div>
            <div class="meta">
              <div>Req ID: <strong>#${rmEsc(rmDetailRow.id)}</strong></div>
              <div>Printed: ${rmEsc(printedAt)}</div>
            </div>
          </div>
          <div class="titlebar">
            <span>Requirement Sheet</span>
            <span>Status: ${rmEsc(rmDetailRow.status || '-')}</span>
          </div>

          <div class="grid">
            <div class="cell"><span>Requested By</span><strong>${rmEsc(requester)}</strong></div>
            <div class="cell"><span>Requester ID</span><strong>${rmEsc(requesterId)}</strong></div>
            <div class="cell"><span>Department</span><strong>${rmEsc(rmDetailRow.department || '-')}</strong></div>
            <div class="cell"><span>Priority</span><strong>${rmEsc(rmDetailRow.priority || '-')}</strong></div>
            <div class="cell"><span>Required Date</span><strong>${rmEsc(rmDetailRow.required_date || '-')}</strong></div>
            <div class="cell"><span>Total Quantity</span><strong>${rmEsc(rmDetailRow.qty || 0)} ${rmEsc(rmDetailRow.unit || '')}</strong></div>
            <div class="cell"><span>Created At</span><strong>${rmEsc(created)}</strong></div>
            <div class="cell"><span>Approved By</span><strong>${rmEsc(approvedBy)}</strong></div>
          </div>

          <div class="section">
            <div class="section-title">Item Details</div>
            <table>
              <thead><tr><th>SL</th><th>Item</th><th>Category</th><th>Qty</th><th>Remarks</th><th>Image</th></tr></thead>
              <tbody>${rows || '<tr><td colspan="6" style="text-align:center;color:#64748b">No items available</td></tr>'}</tbody>
            </table>
          </div>

          <div class="section" style="padding-top:0">
            <div class="section-title">Notes</div>
            <div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:.72rem;background:#fff">${rmEsc(remarks)}</div>
          </div>

          <div class="foot-sign">
            <div class="sign-box">
              <div class="sign-line">${rmEsc(requester)}</div>
              <div class="sign-label">Requested By Signature</div>
            </div>
            <div class="sign-box">
              <div class="sign-line">${rmEsc(approvedBy)}</div>
              <div class="sign-label">Approval Signature</div>
            </div>
          </div>
          <div class="foot-doc">
            <span>Document: Requisition Print | ${rmEsc(RM_COMPANY_NAME || '')}</span>
            <span>Req #${rmEsc(rmDetailRow.id)} | Generated from Requisition Module</span>
          </div>
        </div>
      </body>
    </html>`;
  const w = window.open('', '_blank', 'width=980,height=900');
  if (!w) {
    rmNotify('Please allow popups to print', 'bad');
    return;
  }
  w.document.open();
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 450);
}

let rmPoReq = null;
function rmOpenPoModal(req) {
  rmPoReq = req;
  const m = document.getElementById('rm-po-modal');
  const f = document.getElementById('rm-po-form');
  f.reset();
  f.requisition_id.value = req.id;
  document.getElementById('rm-po-autofill').innerHTML =
    `<strong>Req #${req.id}</strong> | ${req.item_name} | Qty: ${req.qty} ${req.unit}`;
  m.style.display = 'block';
  m.setAttribute('aria-hidden', 'false');
}
function rmClosePoModal() {
  const m = document.getElementById('rm-po-modal');
  m.style.display = 'none';
  m.setAttribute('aria-hidden', 'true');
}

async function rmSubmitPO() {
  try {
    const f = document.getElementById('rm-po-form');
    const fd = new FormData(f);
    fd.append('action', 'create_po');
    await rmApiPost(fd);
    rmNotify('PO created successfully', 'good');
    rmClosePoModal();
    rmLoadDashboard();
    rmLoadApproved();
    rmLoadMy();
  } catch (e) {
    rmNotify(e.message, 'bad');
  }
}

document.getElementById('rm-new-form')?.addEventListener('submit', async function(e){
  e.preventDefault();
  try {
    const fd = new FormData(this);
    fd.append('action', 'create_requisition');
    await rmApiPost(fd);
    rmNotify('Requisition submitted', 'good');
    this.reset();
    document.querySelectorAll('.rm-item-preview').forEach(img => img.removeAttribute('src'));
    document.querySelectorAll('.rm-item-preview-wrap').forEach(wrap => { wrap.style.display = 'none'; });
    const rowsHost = document.getElementById('rm-item-rows');
    if (rowsHost) {
      rowsHost.innerHTML = '';
      rmAddItemRow();
    }
    rmLoadDashboard();
    rmLoadMy();
    rmActivatePane('my');
    rmLoadMy();
  } catch (err) {
    rmNotify(err.message, 'bad');
  }
});

document.addEventListener('DOMContentLoaded', () => {
  rmAddItemRow();
  rmSetTabs();
  rmLoadDashboard();
  rmLoadMy();
  rmLoadAdmin();
  rmLoadApproved();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
