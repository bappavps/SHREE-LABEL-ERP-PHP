<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Leave Management System';
$csrf = generateCSRF();

$lmCanAdminByRole = hasRole('admin', 'manager', 'system_admin', 'super_admin') || isAdmin();
$lmCanAdminByPolicy = function_exists('canAccessPath') ? canAccessPath('/modules/leave-management/admin.php') : true;
$lmCanAdmin = $lmCanAdminByRole && $lmCanAdminByPolicy;
$lmDepartments = erp_get_job_card_departments();
if (!in_array('Others', $lmDepartments, true)) {
    $lmDepartments[] = 'Others';
}

$lmLeaveTypes = ['Sick', 'Casual', 'Emergency', 'Other'];
$lmVoiceLanguages = [
    ['code' => 'hi-IN', 'label' => 'Hindi (हिन्दी + English mix)'],
    ['code' => 'en-IN', 'label' => 'English'],
    ['code' => 'bn-BD', 'label' => 'Bangla'],
];

$lmSettings = getAppSettings();
$lmCompanyName = function_exists('getErpDisplayName')
    ? getErpDisplayName((string)($lmSettings['company_name'] ?? APP_NAME))
    : APP_NAME;
$lmLogoPath = trim((string)($lmSettings['erp_logo_path'] ?? ($lmSettings['logo_path'] ?? '')));
$lmLogoUrl = $lmLogoPath !== '' ? appUrl($lmLogoPath) : appUrl('assets/img/logo.svg');
$lmEmployeeName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? 'Employee')));
$lmDefaultDepartment = '';

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>HR &amp; Workforce</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Leave Management System</span>
</div>

<div class="page-header" style="margin-bottom:12px;">
  <div>
    <h1 style="margin:0;">Leave Management System</h1>
    <p style="margin:4px 0 0;color:#64748b;">Voice-friendly leave requests for operators and a clean approval workflow for managers.</p>
  </div>
</div>

<style>
.lm-shell{
  --lm-primary:#0f4c81;
  --lm-primary-deep:#123c69;
  --lm-accent:#f97316;
  --lm-success:#15803d;
  --lm-danger:#dc2626;
  --lm-ink:#0f172a;
  --lm-muted:#64748b;
  position:relative;
  padding:16px;
  border-radius:22px;
  border:1px solid #c7d2fe;
  background:linear-gradient(145deg,#f8fbff 0%,#eefbf6 50%,#fff7ed 100%);
  box-shadow:0 18px 40px rgba(15,23,42,.09);
  overflow:hidden;
}
.lm-shell::before,
.lm-shell::after{
  content:'';
  position:absolute;
  border-radius:50%;
  pointer-events:none;
}
.lm-shell::before{
  width:340px;
  height:340px;
  top:-210px;
  left:-110px;
  background:radial-gradient(circle,rgba(59,130,246,.16) 0%,rgba(59,130,246,0) 70%);
}
.lm-shell::after{
  width:300px;
  height:300px;
  right:-140px;
  bottom:-170px;
  background:radial-gradient(circle,rgba(249,115,22,.14) 0%,rgba(249,115,22,0) 72%);
}
.lm-shell > *{position:relative;z-index:1}

.lm-hero{
  display:grid;
  grid-template-columns:minmax(0,1.5fr) minmax(280px,.9fr);
  gap:14px;
  margin-bottom:14px;
}
.lm-hero-banner{
  padding:18px;
  border-radius:18px;
  color:#fff;
  background:linear-gradient(120deg,#0f4c81 0%,#0f766e 50%,#1d4ed8 100%);
  box-shadow:0 18px 36px rgba(15,76,129,.3);
}
.lm-hero-banner h2{margin:0 0 8px;font-size:1.42rem;line-height:1.2}
.lm-hero-banner p{margin:0;max-width:700px;font-size:.93rem;opacity:.92}
.lm-hero-points{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:14px;
}
.lm-hero-points span{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:7px 11px;
  border-radius:999px;
  background:rgba(255,255,255,.16);
  font-size:.78rem;
  font-weight:700;
}

.lm-summary-card{
  padding:16px;
  border-radius:18px;
  background:#fff;
  border:1px solid #dbeafe;
  box-shadow:0 14px 28px rgba(15,23,42,.08);
}
.lm-summary-card h3{margin:0 0 12px;font-size:1rem;color:#0f172a}
.lm-stats{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
}
.lm-stat{
  padding:12px;
  border-radius:14px;
  color:#0f172a;
  background:linear-gradient(160deg,#ffffff 0%,#eff6ff 100%);
  border:1px solid #dbeafe;
}
.lm-stat strong{display:block;font-size:1.3rem;line-height:1.1}
.lm-stat span{display:block;margin-top:3px;font-size:.78rem;color:#475569;font-weight:700}

.lm-tabs{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.lm-tab{
  border:none;
  border-radius:999px;
  padding:11px 16px;
  font-size:.82rem;
  font-weight:800;
  letter-spacing:.2px;
  color:#0f172a;
  background:#fff;
  border:1px solid #cbd5e1;
  cursor:pointer;
  transition:transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.lm-tab:hover{transform:translateY(-1px);box-shadow:0 10px 18px rgba(15,23,42,.07)}
.lm-tab.active{
  color:#fff;
  border-color:#0f4c81;
  background:linear-gradient(96deg,#0f4c81,#0f766e);
  box-shadow:0 12px 24px rgba(15,76,129,.28);
}
.lm-tab-badge{
  display:none;
  margin-left:6px;
  min-width:20px;
  height:20px;
  padding:0 6px;
  border-radius:999px;
  font-size:.68rem;
  line-height:20px;
  text-align:center;
  font-weight:900;
  background:#dc2626;
  color:#fff;
}
.lm-tab-badge.is-visible{display:inline-block}
.lm-tab-badge.is-alert{animation:lmBadgePulse 1.1s ease-in-out infinite}

.rm-pane{display:none}
.rm-pane.active{display:block;animation:lmFadeIn .2s ease}

.card{
  border-radius:18px;
  background:#fff;
  border:1px solid #dbe7f3;
  box-shadow:0 14px 28px rgba(15,23,42,.07);
}
.card-header{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  padding:16px 18px;
  border-bottom:1px solid #e2e8f0;
  background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);
  border-top-left-radius:18px;
  border-top-right-radius:18px;
}
.card-title{font-size:1rem;font-weight:800;color:#0f172a}
.lm-subtitle{margin-top:4px;font-size:.82rem;color:#64748b}
.card-body{padding:18px;overflow:auto}

.lm-form-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
}
.lm-form-grid label,
.lm-voice-head label{
  display:block;
  margin-bottom:6px;
  font-size:.74rem;
  font-weight:800;
  letter-spacing:.04em;
  color:#334155;
  text-transform:uppercase;
}
.lm-form-grid input,
.lm-form-grid select,
.lm-form-grid textarea,
.lm-voice-head select,
.lm-admin-editor textarea{
  width:100%;
  border:1px solid #cbd5e1;
  border-radius:12px;
  padding:11px 12px;
  background:#fff;
  color:#0f172a;
  font:inherit;
}
.lm-form-grid textarea,
.lm-admin-editor textarea{resize:vertical;min-height:110px}
.lm-form-grid input:focus,
.lm-form-grid select:focus,
.lm-form-grid textarea:focus,
.lm-voice-head select:focus,
.lm-admin-editor textarea:focus{
  outline:none;
  border-color:#38bdf8;
  box-shadow:0 0 0 4px rgba(56,189,248,.14);
}
.lm-span-2{grid-column:span 2}

.lm-voice-card{
  margin-top:14px;
  border-radius:18px;
  padding:16px;
  border:1px solid #bae6fd;
  background:linear-gradient(145deg,#ecfeff 0%,#eff6ff 56%,#fef3c7 100%);
}
.lm-voice-head{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:14px;
  margin-bottom:14px;
}
.lm-voice-head strong{display:block;font-size:1rem;color:#0f172a}
.lm-voice-head span{display:block;margin-top:4px;font-size:.82rem;color:#475569;max-width:620px}
.lm-voice-lang{width:180px;max-width:100%}
.lm-voice-actions{display:flex;gap:10px;flex-wrap:wrap}
.lm-big-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:10px;
  min-height:56px;
  padding:12px 18px;
  border:none;
  border-radius:16px;
  font-size:1rem;
  font-weight:800;
  cursor:pointer;
}
.lm-btn-record{color:#fff;background:linear-gradient(96deg,#dc2626,#f97316)}
.lm-btn-record.is-recording{background:linear-gradient(96deg,#991b1b,#dc2626)}
.lm-btn-muted{color:#0f172a;background:#fff;border:1px solid #cbd5e1}
.lm-voice-status{
  margin-top:14px;
  padding:11px 12px;
  border-radius:12px;
  background:rgba(255,255,255,.68);
  border:1px dashed #7dd3fc;
  color:#0f172a;
  font-size:.86rem;
}
.lm-voice-note{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;
  margin-top:10px;
  font-size:.8rem;
  color:#334155;
}
.lm-voice-note > div{
  display:flex;
  align-items:center;
  gap:8px;
  padding:10px 12px;
  border-radius:12px;
  background:rgba(255,255,255,.72);
  border:1px solid rgba(186,230,253,.95);
}

.lm-submit-row{
  display:flex;
  justify-content:flex-end;
  margin-top:16px;
}
.lm-primary-submit{
  display:inline-flex;
  align-items:center;
  gap:10px;
  border:none;
  border-radius:16px;
  padding:14px 22px;
  font-size:1rem;
  font-weight:800;
  color:#fff;
  background:linear-gradient(96deg,#0f766e,#16a34a);
  box-shadow:0 14px 28px rgba(21,128,61,.26);
  cursor:pointer;
}

.lm-table-wrap{overflow:auto}
.rm-table{width:100%;min-width:840px;border-collapse:separate;border-spacing:0}
.rm-table thead th{
  background:#eff6ff;
  border-bottom:1px solid #cbd5e1;
  color:#0f172a;
  font-size:.74rem;
  letter-spacing:.04em;
  text-transform:uppercase;
}
.rm-table th,.rm-table td{padding:12px 10px;vertical-align:middle}
.rm-table tbody tr:nth-child(even){background:#fafcff}
.rm-table tbody tr:hover{background:#effcf6}
.lm-empty{text-align:center;color:#64748b;padding:22px !important}

.lm-status{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 10px;
  border-radius:999px;
  font-size:.74rem;
  font-weight:800;
  text-transform:capitalize;
}
.lm-status.pending{background:#fef3c7;color:#92400e}
.lm-status.approved{background:#dcfce7;color:#166534}
.lm-status.rejected{background:#fee2e2;color:#991b1b}

.lm-action-row{display:flex;gap:8px;flex-wrap:wrap}
.lm-mini-btn{
  display:inline-flex;
  align-items:center;
  gap:6px;
  border:none;
  border-radius:10px;
  padding:8px 10px;
  font-size:.78rem;
  font-weight:800;
  cursor:pointer;
}
.lm-mini-btn.view{background:#e0f2fe;color:#075985}
.lm-mini-btn.print{background:#dcfce7;color:#166534}
.lm-mini-btn.approve{background:#166534;color:#fff}
.lm-mini-btn.reject{background:#b91c1c;color:#fff}
.lm-mini-btn.save{background:#0f4c81;color:#fff}
.lm-mini-btn.danger{background:#fee2e2;color:#991b1b}
.lm-mini-btn.danger:hover{background:#fca5a5}

.lm-modal{
  position:fixed;
  inset:0;
  display:none;
  align-items:center;
  justify-content:center;
  padding:20px;
  z-index:1200;
  background:rgba(15,23,42,.58);
  backdrop-filter:blur(4px);
}
.lm-modal.is-open{display:flex}
.lm-modal-dialog{
  width:min(960px,100%);
  max-height:calc(100vh - 40px);
  overflow:auto;
  border-radius:22px;
  background:#fff;
  border:1px solid rgba(226,232,240,.95);
  box-shadow:0 30px 60px rgba(15,23,42,.24);
}
.lm-modal-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  padding:18px 18px 14px;
  border-bottom:1px solid #e2e8f0;
}
.lm-modal-head h3{margin:0;font-size:1.08rem;color:#0f172a}
.lm-modal-head p{margin:5px 0 0;font-size:.82rem;color:#64748b}
.lm-close{
  width:40px;
  height:40px;
  border:none;
  border-radius:12px;
  background:#f8fafc;
  color:#0f172a;
  cursor:pointer;
}
.lm-modal-body{padding:18px}
.lm-detail-grid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:12px;
  margin-bottom:14px;
}
.lm-detail-card{
  padding:14px;
  border-radius:16px;
  background:linear-gradient(160deg,#ffffff 0%,#eff6ff 100%);
  border:1px solid #dbeafe;
}
.lm-detail-card small{display:block;color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
.lm-detail-card strong{display:block;margin-top:6px;font-size:.95rem;color:#0f172a}
.lm-detail-status-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-top:6px;
  padding:8px 14px;
  border-radius:999px;
  font-size:1rem;
  font-weight:900;
  letter-spacing:.04em;
  text-transform:uppercase;
}
.lm-detail-status-pill.approved{background:#dcfce7;color:#166534}
.lm-detail-status-pill.rejected{background:#fee2e2;color:#991b1b}
.lm-detail-status-pill.pending{background:#fef3c7;color:#92400e}
.lm-detail-block{
  margin-top:14px;
  padding:14px;
  border-radius:16px;
  background:#f8fafc;
  border:1px solid #e2e8f0;
}
.lm-detail-block h4{margin:0 0 10px;font-size:.92rem;color:#0f172a}
.lm-detail-text{
  color:#1e293b;
  white-space:pre-wrap;
  line-height:1.55;
  min-height:22px;
}
.lm-detail-meta{font-size:.78rem;color:#64748b}
.lm-admin-editor{display:none}
.lm-admin-editor.is-visible{display:block}
.lm-modal-actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  flex-wrap:wrap;
  padding:0 18px 18px;
}

.lm-toast{
  position:fixed;
  right:18px;
  bottom:18px;
  max-width:360px;
  padding:12px 14px;
  border-radius:14px;
  color:#fff;
  font-size:.9rem;
  font-weight:700;
  z-index:1300;
  display:none;
  box-shadow:0 18px 36px rgba(15,23,42,.2);
}
.lm-toast.show{display:block;animation:lmToastIn .18s ease}
.lm-toast.success{background:linear-gradient(96deg,#15803d,#16a34a)}
.lm-toast.error{background:linear-gradient(96deg,#991b1b,#dc2626)}
.lm-toast.info{background:linear-gradient(96deg,#0f4c81,#2563eb)}

@keyframes lmFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes lmToastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes lmBadgePulse{
  0%{transform:scale(1);box-shadow:0 0 0 0 rgba(220,38,38,.5)}
  70%{transform:scale(1.08);box-shadow:0 0 0 9px rgba(220,38,38,0)}
  100%{transform:scale(1);box-shadow:0 0 0 0 rgba(220,38,38,0)}
}

@media (max-width: 980px){
  .lm-hero{grid-template-columns:1fr}
  .lm-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}

@media (max-width: 768px){
  .lm-shell{padding:12px;border-radius:18px}
  .lm-tabs{flex-wrap:nowrap;overflow:auto;padding-bottom:4px}
  .lm-tab{white-space:nowrap}
  .lm-form-grid,
  .lm-stats,
  .lm-detail-grid,
  .lm-voice-note{grid-template-columns:1fr}
  .lm-span-2{grid-column:span 1}
  .lm-voice-head{flex-direction:column;align-items:stretch}
  .lm-voice-lang{width:100%}
  .lm-submit-row{justify-content:stretch}
  .lm-primary-submit{width:100%;justify-content:center}
  .lm-modal{padding:12px}
  .lm-modal-dialog{max-height:calc(100vh - 24px)}
}

@media print{
  body *{visibility:hidden !important}
}
</style>

<div class="lm-shell">
  <div class="lm-hero">
    <div class="lm-hero-banner">
      <h2>Easy leave request for every employee</h2>
      <p>Operators can submit by form or voice. Admin can fix wording, approve or reject requests, and print a professional A4 leave application.</p>
      <div class="lm-hero-points">
        <span><i class="bi bi-mic-fill"></i> Voice-first input</span>
        <span><i class="bi bi-translate"></i> Bangla / Hindi / English</span>
        <span><i class="bi bi-printer-fill"></i> A4 print-ready output</span>
      </div>
    </div>
    <div class="lm-summary-card">
      <h3>Quick Overview</h3>
      <div class="lm-stats">
        <div class="lm-stat"><strong id="lm-stat-total">0</strong><span>My Requests</span></div>
        <div class="lm-stat"><strong id="lm-stat-pending">0</strong><span>Pending</span></div>
        <div class="lm-stat"><strong id="lm-stat-approved">0</strong><span>Approved</span></div>
        <div class="lm-stat"><strong id="lm-stat-review">0</strong><span>Need Admin Review</span></div>
      </div>
    </div>
  </div>

  <div class="lm-tabs" id="lm-tabs">
    <button type="button" class="lm-tab active" data-pane="apply"><i class="bi bi-pencil-square"></i> Apply Leave</button>
    <button type="button" class="lm-tab" data-pane="my"><i class="bi bi-card-checklist"></i> My Leaves</button>
    <?php if ($lmCanAdmin): ?>
      <button type="button" class="lm-tab" data-pane="admin"><i class="bi bi-shield-check"></i> Admin Approval Panel <span class="lm-tab-badge" id="lm-admin-tab-badge">0</span></button>
    <?php endif; ?>
  </div>

  <?php include __DIR__ . '/apply.php'; ?>
  <?php include __DIR__ . '/my.php'; ?>
  <?php if ($lmCanAdmin) include __DIR__ . '/admin.php'; ?>
</div>

<div class="lm-modal" id="lm-detail-modal" aria-hidden="true">
  <div class="lm-modal-dialog">
    <div class="lm-modal-head">
      <div>
        <h3 id="lm-detail-title">Leave Detail</h3>
        <p id="lm-detail-subtitle">Review leave request information.</p>
      </div>
      <button type="button" class="lm-close" id="lm-detail-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="lm-modal-body">
      <div class="lm-detail-grid" id="lm-detail-grid"></div>
      <div class="lm-detail-block">
        <h4>Reason</h4>
        <div class="lm-detail-text" id="lm-detail-reason-view"></div>
        <div class="lm-admin-editor" id="lm-detail-reason-editor">
          <textarea id="lm-detail-reason-input" rows="5" placeholder="Correct or complete the leave reason."></textarea>
        </div>
      </div>
      <div class="lm-detail-block lm-admin-editor" id="lm-detail-admin-editor">
        <h4>Admin Remark</h4>
        <textarea id="lm-detail-admin-remark" rows="4" placeholder="Add remarks for approval or rejection."></textarea>
      </div>
    </div>
    <div class="lm-modal-actions" id="lm-modal-actions"></div>
  </div>
</div>

<div class="lm-toast info" id="lm-toast"></div>

<script>
(function(){
  var apiUrl = <?= json_encode(appUrl('modules/leave-management/api.php')) ?>;
  var canAdmin = <?= $lmCanAdmin ? 'true' : 'false' ?>;
  var companyInfo = <?= json_encode([
      'name' => $lmCompanyName,
      'logoUrl' => $lmLogoUrl,
      'employeeName' => $lmEmployeeName,
  ]) ?>;

  var state = {
    myLeaves: [],
    adminLeaves: [],
    currentDetail: null,
    currentSource: 'my',
    voiceBlob: null,
    voiceMimeType: 'audio/webm',
    mediaRecorder: null,
    mediaStream: null,
    voiceChunks: [],
    speechRecognition: null,
    speechRecognitionEn: null,
    hiTranscript: '',
    enTranscript: '',
    recording: false,
    speechSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition)
  };

  var els = {
    tabs: document.getElementById('lm-tabs'),
    applyForm: document.getElementById('lm-apply-form'),
    fromDate: document.getElementById('lm-from-date'),
    toDate: document.getElementById('lm-to-date'),
    totalDays: document.getElementById('lm-total-days'),
    reason: document.getElementById('lm-reason'),
    department: document.getElementById('lm-department'),
    submitBtn: document.getElementById('lm-submit-btn'),
    myTableBody: document.querySelector('#lm-my-table tbody'),
    adminTableBody: document.querySelector('#lm-admin-table tbody'),
    recordBtn: document.getElementById('lm-record-btn'),
    clearVoiceBtn: document.getElementById('lm-clear-voice-btn'),
    voiceLanguage: document.getElementById('lm-voice-language'),
    voiceStatus: document.getElementById('lm-voice-status'),
    sttState: document.getElementById('lm-stt-state'),
    audioState: document.getElementById('lm-audio-state'),
    adminTabBadge: document.getElementById('lm-admin-tab-badge'),
    toast: document.getElementById('lm-toast'),
    detailModal: document.getElementById('lm-detail-modal'),
    detailClose: document.getElementById('lm-detail-close'),
    detailTitle: document.getElementById('lm-detail-title'),
    detailSubtitle: document.getElementById('lm-detail-subtitle'),
    detailGrid: document.getElementById('lm-detail-grid'),
    detailReasonView: document.getElementById('lm-detail-reason-view'),
    detailReasonEditor: document.getElementById('lm-detail-reason-editor'),
    detailReasonInput: document.getElementById('lm-detail-reason-input'),
    detailAdminEditor: document.getElementById('lm-detail-admin-editor'),
    detailAdminRemark: document.getElementById('lm-detail-admin-remark'),
    modalActions: document.getElementById('lm-modal-actions'),
    statTotal: document.getElementById('lm-stat-total'),
    statPending: document.getElementById('lm-stat-pending'),
    statApproved: document.getElementById('lm-stat-approved'),
    statReview: document.getElementById('lm-stat-review')
  };

  if (els.sttState) {
    els.sttState.textContent = state.speechSupported ? 'Available in this browser' : 'Not available, audio will still be saved';
  }

  bindEvents();
  loadMyLeaves();
  if (canAdmin) {
    loadAdminLeaves();
  }

  function bindEvents() {
    if (els.tabs) {
      els.tabs.addEventListener('click', function(event){
        var button = event.target.closest('.lm-tab');
        if (!button) return;
        setActivePane(button.getAttribute('data-pane'));
      });
    }

    [els.fromDate, els.toDate].forEach(function(input){
      if (!input) return;
      input.addEventListener('change', updateTotalDays);
    });

    if (els.applyForm) {
      els.applyForm.addEventListener('submit', submitLeaveRequest);
    }

    if (els.recordBtn) {
      els.recordBtn.addEventListener('click', function(){
        if (state.recording) {
          stopVoiceRecording();
        } else {
          startVoiceRecording();
        }
      });
    }

    if (els.clearVoiceBtn) {
      els.clearVoiceBtn.addEventListener('click', clearVoiceRecording);
    }

    if (els.detailClose) {
      els.detailClose.addEventListener('click', closeDetailModal);
    }

    if (els.detailModal) {
      els.detailModal.addEventListener('click', function(event){
        if (event.target === els.detailModal) {
          closeDetailModal();
        }
      });
    }
  }

  function setActivePane(name) {
    Array.prototype.forEach.call(document.querySelectorAll('.lm-tab'), function(tab){
      tab.classList.toggle('active', tab.getAttribute('data-pane') === name);
    });
    Array.prototype.forEach.call(document.querySelectorAll('.rm-pane'), function(pane){
      pane.classList.toggle('active', pane.id === 'lm-panel-' + name);
    });
  }

  function updateTotalDays() {
    if (!els.fromDate || !els.toDate || !els.totalDays) return;
    var from = els.fromDate.value;
    var to = els.toDate.value;
    if (!from || !to) {
      els.totalDays.value = '';
      return;
    }
    var start = new Date(from + 'T00:00:00');
    var end = new Date(to + 'T00:00:00');
    var diff = Math.floor((end - start) / 86400000) + 1;
    els.totalDays.value = diff > 0 ? diff : '';
  }

  async function submitLeaveRequest(event) {
    event.preventDefault();

    if (!els.department.value) {
      showToast('Please select a department.', 'error');
      return;
    }
    if (!els.fromDate.value || !els.toDate.value) {
      showToast('Please select leave dates.', 'error');
      return;
    }
    updateTotalDays();
    if (!els.totalDays.value) {
      showToast('Leave dates are invalid.', 'error');
      return;
    }

    var formData = new FormData(els.applyForm);
    formData.append('action', 'create_leave');
    if (state.voiceBlob) {
      var extension = mimeToExtension(state.voiceMimeType);
      formData.append('voice_file', state.voiceBlob, 'leave_voice.' + extension);
    }

    setSubmitState(true);
    try {
      var response = await requestJson(formData);
      if (!response.ok) throw new Error(response.message || 'Unable to submit leave request.');
      showToast('Leave request submitted successfully.', 'success');
      els.applyForm.reset();
      els.totalDays.value = '';
      clearVoiceRecording(false);
      loadMyLeaves(true);
      if (canAdmin) loadAdminLeaves();
      setActivePane('my');
    } catch (error) {
      showToast(error.message || 'Submission failed.', 'error');
    } finally {
      setSubmitState(false);
    }
  }

  function setSubmitState(loading) {
    if (!els.submitBtn) return;
    els.submitBtn.disabled = loading;
    els.submitBtn.style.opacity = loading ? '0.7' : '1';
  }

  async function loadMyLeaves(silent) {
    try {
      var response = await requestJson({ action: 'list_my' });
      if (!response.ok) throw new Error(response.message || 'Unable to load leave requests.');
      state.myLeaves = Array.isArray(response.data) ? response.data : [];
      renderMyLeaves();
      updateSummary();
      if (!silent && state.myLeaves.length === 0) {
        showToast('No leave requests found yet.', 'info');
      }
    } catch (error) {
      renderErrorRow(els.myTableBody, 7, error.message || 'Unable to load leave requests.');
    }
  }

  async function loadAdminLeaves() {
    if (!canAdmin || !els.adminTableBody) return;
    try {
      var response = await requestJson({ action: 'list_admin' });
      if (!response.ok) throw new Error(response.message || 'Unable to load admin leave requests.');
      state.adminLeaves = Array.isArray(response.data) ? response.data : [];
      renderAdminLeaves();
      updateSummary();
    } catch (error) {
      renderErrorRow(els.adminTableBody, 9, error.message || 'Unable to load admin leave requests.');
    }
  }

  function renderMyLeaves() {
    if (!els.myTableBody) return;
    if (!state.myLeaves.length) {
      els.myTableBody.innerHTML = '<tr><td colspan="8" class="lm-empty">No leave requests submitted yet.</td></tr>';
      return;
    }
    els.myTableBody.innerHTML = state.myLeaves.map(function(item){
      var printButton = item.status === 'approved'
        ? '<button type="button" class="lm-mini-btn print" data-action="print" data-id="' + item.id + '"><i class="bi bi-printer-fill"></i> Print</button>'
        : '';
      return '<tr>' +
        '<td><strong>' + escapeHtml(item.leave_code) + '</strong></td>' +
        '<td>' + escapeHtml(formatDate(item.created_at)) + '</td>' +
        '<td>' + escapeHtml(formatDate(item.from_date)) + ' - ' + escapeHtml(formatDate(item.to_date)) + '</td>' +
        '<td>' + escapeHtml(String(item.total_days)) + '</td>' +
        '<td>' + escapeHtml(item.leave_type) + '</td>' +
        '<td>' + reasonPreview(item.reason_text) + '</td>' +
        '<td>' + statusBadge(item.status) + '</td>' +
        '<td><div class="lm-action-row">' +
          '<button type="button" class="lm-mini-btn view" data-action="view" data-id="' + item.id + '"><i class="bi bi-eye"></i> View</button>' +
          printButton +
        '</div></td>' +
      '</tr>';
    }).join('');
    bindTableButtons(els.myTableBody, 'my');
  }

  function renderAdminLeaves() {
    if (!els.adminTableBody) return;
    if (!state.adminLeaves.length) {
      els.adminTableBody.innerHTML = '<tr><td colspan="9" class="lm-empty">No leave requests in the approval queue.</td></tr>';
      return;
    }
    els.adminTableBody.innerHTML = state.adminLeaves.map(function(item){
      return '<tr>' +
        '<td><strong>' + escapeHtml(item.leave_code) + '</strong></td>' +
        '<td>' + escapeHtml(item.employee_name) + '</td>' +
        '<td>' + escapeHtml(item.department) + '</td>' +
        '<td>' + escapeHtml(item.leave_type) + '</td>' +
        '<td>' + escapeHtml(formatDate(item.from_date)) + ' - ' + escapeHtml(formatDate(item.to_date)) + '</td>' +
        '<td>' + escapeHtml(String(item.total_days)) + '</td>' +
        '<td>' + reasonPreview(item.reason_text) + '</td>' +
        '<td>' + statusBadge(item.status) + '</td>' +
        '<td><div class="lm-action-row">' +
          '<button type="button" class="lm-mini-btn view" data-action="view" data-id="' + item.id + '"><i class="bi bi-search"></i> Open</button>' +
          '<button type="button" class="lm-mini-btn danger" data-action="delete" data-id="' + item.id + '"><i class="bi bi-trash"></i> Delete</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');
    bindTableButtons(els.adminTableBody, 'admin');
  }

  function bindTableButtons(container, source) {
    Array.prototype.forEach.call(container.querySelectorAll('button[data-action]'), function(button){
      button.addEventListener('click', function(){
        var id = this.getAttribute('data-id');
        var action = this.getAttribute('data-action');
        if (action === 'view') {
          openLeaveDetail(id, source);
        } else if (action === 'print') {
          openLeaveDetail(id, source, true);
        } else if (action === 'delete') {
          deleteLeave(id);
        }
      });
    });
  }

  async function openLeaveDetail(id, source, printAfterLoad) {
    try {
      var response = await requestJson({ action: 'get_detail', id: id });
      if (!response.ok) throw new Error(response.message || 'Unable to load leave detail.');
      state.currentDetail = response.data;
      state.currentSource = source || 'my';
      renderDetailModal();
      if (printAfterLoad) {
        printCurrentLeave();
      } else {
        els.detailModal.classList.add('is-open');
        els.detailModal.setAttribute('aria-hidden', 'false');
      }
    } catch (error) {
      showToast(error.message || 'Unable to load detail.', 'error');
    }
  }

  function renderDetailModal() {
    var detail = state.currentDetail;
    if (!detail) return;

    els.detailTitle.textContent = detail.leave_code + ' • ' + detail.employee_name;
    els.detailSubtitle.textContent = 'Applied on ' + formatDate(detail.created_at) + ' • Status: ' + capitalize(detail.status);

    var cards = [
      { label: 'Department', value: detail.department },
      { label: 'Leave Type', value: detail.leave_type },
      { label: 'Leave Dates', value: formatDate(detail.from_date) + ' to ' + formatDate(detail.to_date) },
      { label: 'Total Days', value: String(detail.total_days) },
      { label: 'Employee Email', value: detail.employee_email || 'N/A' },
      { label: 'Status', value: detail.status, isStatus: true },
      { label: 'Approved By', value: detail.approved_by_name || 'Pending review' }
    ];
    els.detailGrid.innerHTML = cards.map(function(card){
      if (card.isStatus) {
        return '<div class="lm-detail-card"><small>' + escapeHtml(card.label) + '</small><span class="lm-detail-status-pill ' + escapeHtml(String(card.value || '').toLowerCase()) + '">' + escapeHtml(capitalize(card.value)) + '</span></div>';
      }
      return '<div class="lm-detail-card"><small>' + escapeHtml(card.label) + '</small><strong>' + escapeHtml(card.value) + '</strong></div>';
    }).join('');

    els.detailReasonView.textContent = detail.reason_text || 'No written reason provided. Voice file may be stored for admin reference.';
    els.detailReasonInput.value = detail.reason_text || '';
    els.detailAdminRemark.value = detail.admin_remark || '';

    var adminMode = canAdmin && state.currentSource === 'admin';
    els.detailReasonEditor.classList.toggle('is-visible', adminMode);
    els.detailAdminEditor.classList.toggle('is-visible', adminMode);
    els.modalActions.innerHTML = '';

    appendActionButton('Close', 'view', closeDetailModal);

    if (detail.status === 'approved' || adminMode) {
      appendActionButton('Print / Download PDF', 'print', printCurrentLeave, 'bi bi-printer-fill');
    }

    if (adminMode) {
      appendActionButton('Save Changes', 'save', function(){ saveAdminDecision('save'); }, 'bi bi-save-fill');
      appendActionButton('Approve', 'approve', function(){ saveAdminDecision('approved'); }, 'bi bi-check2-circle');
      appendActionButton('Reject', 'reject', function(){ saveAdminDecision('rejected'); }, 'bi bi-x-circle');
    }
  }

  function appendActionButton(label, tone, handler, iconClass) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'lm-mini-btn ' + tone;
    button.innerHTML = (iconClass ? '<i class="' + iconClass + '"></i> ' : '') + '<span>' + escapeHtml(label) + '</span>';
    button.addEventListener('click', handler);
    els.modalActions.appendChild(button);
  }

  async function deleteLeave(id) {
    if (!confirm('এই leave request টি delete করবেন? এটি পূর্বাবস্থায় ফেরানো যাবে না।')) return;
    try {
      var formData = new FormData();
      formData.append('action', 'delete_leave');
      formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');
      formData.append('id', id);
      var response = await requestJson(formData);
      if (!response.ok) throw new Error(response.message || 'Delete failed.');
      showToast('Leave request deleted.', 'success');
      loadAdminLeaves();
      updateSummary();
    } catch (error) {
      showToast(error.message || 'Could not delete leave request.', 'error');
    }
  }

  async function saveAdminDecision(decision) {
    if (!state.currentDetail) return;
    var formData = new FormData();
    formData.append('action', 'admin_update');
    formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content') || '');
    formData.append('id', state.currentDetail.id);
    formData.append('decision', decision);
    formData.append('reason_text', els.detailReasonInput.value || '');
    formData.append('admin_remark', els.detailAdminRemark.value || '');

    try {
      var response = await requestJson(formData);
      if (!response.ok) throw new Error(response.message || 'Unable to update leave request.');
      showToast(response.message || 'Leave request updated.', 'success');
      closeDetailModal();
      loadMyLeaves(true);
      loadAdminLeaves();
    } catch (error) {
      showToast(error.message || 'Update failed.', 'error');
    }
  }

  function closeDetailModal() {
    els.detailModal.classList.remove('is-open');
    els.detailModal.setAttribute('aria-hidden', 'true');
  }

  function updateSummary() {
    var pending = 0;
    var approved = 0;
    state.myLeaves.forEach(function(item){
      if (item.status === 'pending') pending += 1;
      if (item.status === 'approved') approved += 1;
    });
    els.statTotal.textContent = String(state.myLeaves.length);
    els.statPending.textContent = String(pending);
    els.statApproved.textContent = String(approved);
    if (canAdmin) {
      var reviewCount = 0;
      state.adminLeaves.forEach(function(item){
        if (item.status === 'pending') reviewCount += 1;
      });
      els.statReview.textContent = String(reviewCount);
      setAdminTabBadge(reviewCount);
    } else {
      els.statReview.textContent = '0';
      setAdminTabBadge(0);
    }
  }

  function setAdminTabBadge(count) {
    if (!els.adminTabBadge) return;
    var n = parseInt(count, 10) || 0;
    els.adminTabBadge.textContent = String(n);
    els.adminTabBadge.classList.toggle('is-visible', n > 0);
    els.adminTabBadge.classList.toggle('is-alert', n > 0);
  }

  async function startVoiceRecording() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof window.MediaRecorder === 'undefined') {
      showToast('This browser does not support voice recording.', 'error');
      return;
    }

    try {
      clearVoiceRecording(false);
      var stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      state.mediaStream = stream;
      state.voiceChunks = [];

      var recorder = new MediaRecorder(stream);
      state.mediaRecorder = recorder;
      state.voiceMimeType = recorder.mimeType || 'audio/webm';
      recorder.ondataavailable = function(event){
        if (event.data && event.data.size > 0) {
          state.voiceChunks.push(event.data);
        }
      };
      recorder.onstop = function(){
        if (state.voiceChunks.length) {
          state.voiceBlob = new Blob(state.voiceChunks, { type: state.voiceMimeType });
          setVoiceStatus('Voice recorded successfully. It will be uploaded with the leave request.', 'Voice file ready');
        } else {
          state.voiceBlob = null;
          setVoiceStatus('No audio was captured. Please record again.', 'No audio captured');
        }
        releaseMediaStream();
      };
      recorder.start();
      state.recording = true;
      setSubmitState(true);
      els.recordBtn.classList.add('is-recording');
      els.recordBtn.querySelector('span').textContent = 'Stop Recording';
      setVoiceStatus('Recording started. Speak in Hindi or English — both will be captured.', 'Recording...');
      startSpeechRecognition();
    } catch (error) {
      releaseMediaStream();
      showToast('Microphone permission was not granted.', 'error');
    }
  }

  function stopVoiceRecording() {
    if (state.mediaRecorder && state.mediaRecorder.state !== 'inactive') {
      state.mediaRecorder.stop();
    } else {
      releaseMediaStream();
    }
    stopSpeechRecognition();
    state.recording = false;
    setSubmitState(false);
    els.recordBtn.classList.remove('is-recording');
    els.recordBtn.querySelector('span').textContent = 'Record Voice';
  }

  function clearVoiceRecording(showMessage) {
    stopSpeechRecognition();
    if (state.recording) {
      stopVoiceRecording();
    }
    state.voiceBlob = null;
    state.voiceChunks = [];
    state.voiceMimeType = 'audio/webm';
    state.hiTranscript = '';
    state.enTranscript = '';
    releaseMediaStream();
    if (showMessage !== false) {
      setVoiceStatus('Voice input cleared. You can record again.', 'Ready');
    } else {
      setVoiceStatus('Voice input is optional. You can submit with form input, voice input, or both.', 'Ready');
    }
  }

  function releaseMediaStream() {
    if (state.mediaStream) {
      state.mediaStream.getTracks().forEach(function(track){ track.stop(); });
      state.mediaStream = null;
    }
    state.mediaRecorder = null;
  }

  function startSpeechRecognition() {
    stopSpeechRecognition();
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return;
    try {
      var lang = (els.voiceLanguage && els.voiceLanguage.value) ? els.voiceLanguage.value : 'hi-IN';
      var recognition = new SpeechRecognition();
      recognition.lang = lang;
      recognition.continuous = false;
      recognition.interimResults = true;
      recognition.onresult = function(event) {
        var finals = '';
        var interim = '';
        for (var i = 0; i < event.results.length; i++) {
          if (event.results[i].isFinal) {
            finals += event.results[i][0].transcript + ' ';
          } else {
            interim += event.results[i][0].transcript;
          }
        }
        finals = finals.trim();
        if (finals) {
          state.hiTranscript = (state.hiTranscript ? state.hiTranscript + ' ' : '') + finals;
        }
        var display = (state.hiTranscript + (interim ? ' ' + interim : '')).trim();
        if (els.reason && display) {
          els.reason.value = display;
        }
        if (finals) {
          setVoiceStatus('Text captured. Keep speaking freely.', 'Recording with speech-to-text');
        }
      };
      recognition.onerror = function(e) {
        if (state.recording && e.error !== 'aborted' && e.error !== 'not-allowed') {
          setVoiceStatus('Listening resumed…', 'Reconnecting...');
        }
      };
      recognition.onend = function() {
        if (state.recording) {
          try { recognition.start(); } catch(e) {}
        }
      };
      recognition.start();
      state.speechRecognition = recognition;
    } catch(error) {
      setVoiceStatus('Speech-to-text could not start. Audio file will still be saved.', 'Audio only');
    }
  }

  function stopSpeechRecognition() {
    if (state.speechRecognition) {
      try { state.speechRecognition.stop(); } catch (error) {}
      state.speechRecognition = null;
    }
    if (state.speechRecognitionEn) {
      try { state.speechRecognitionEn.stop(); } catch (error) {}
      state.speechRecognitionEn = null;
    }
  }

  function setVoiceStatus(message, audioState) {
    if (els.voiceStatus) els.voiceStatus.textContent = message;
    if (els.audioState && audioState) els.audioState.textContent = audioState;
  }

  function printCurrentLeave() {
    if (!state.currentDetail) return;
    var detail = state.currentDetail;
    var printWindow = window.open('', '_blank', 'width=900,height=1200');
    if (!printWindow) {
      showToast('Popup blocked. Please allow popups for printing.', 'error');
      return;
    }

    var approvalText = detail.status === 'approved'
      ? 'Approved by ' + escapeHtml(detail.approved_by_name || 'Admin') + ' on ' + escapeHtml(formatDate(detail.approved_date || detail.created_at))
      : 'Pending management approval';

    var html = '' +
      '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' + escapeHtml(detail.leave_code) + '</title>' +
      '<style>' +
      '@page{size:A4;margin:18mm 16mm 18mm 16mm;}' +
      'body{font-family:Arial,sans-serif;color:#0f172a;margin:0;background:#f8fafc;}' +
      '.sheet{width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:0;}' +
      '.header{display:flex;align-items:center;justify-content:space-between;padding:18mm 16mm 10mm;border-bottom:3px solid #0f4c81;background:linear-gradient(135deg,#eff6ff 0%,#ecfeff 100%);}' +
      '.brand{display:flex;align-items:center;gap:14px;}' +
      '.brand img{width:70px;height:70px;object-fit:contain;border-radius:14px;background:#fff;border:1px solid #dbeafe;padding:6px;}' +
      '.brand h1{margin:0;font-size:20px;line-height:1.15;color:#0f172a;}' +
      '.brand p{margin:6px 0 0;font-size:12px;color:#475569;}' +
      '.badge{padding:8px 12px;border-radius:999px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.08em;background:' + (detail.status === 'approved' ? '#dcfce7;color:#166534' : detail.status === 'rejected' ? '#fee2e2;color:#991b1b' : '#fef3c7;color:#92400e') + ';}' +
      '.body{padding:14mm 16mm;}' +
      '.title{text-align:center;margin:0 0 12mm;font-size:22px;color:#0f4c81;letter-spacing:.03em;}' +
      '.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:10mm;}' +
      '.cell{padding:10px 12px;border:1px solid #dbeafe;border-radius:12px;background:#f8fbff;}' +
      '.cell small{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;}' +
      '.cell strong{display:block;margin-top:6px;font-size:14px;color:#0f172a;}' +
      '.reason{padding:14px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;min-height:88px;line-height:1.6;white-space:pre-wrap;}' +
      '.approval{margin-top:14mm;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;}' +
      '.sign{padding-top:28px;border-top:1px solid #94a3b8;font-size:12px;color:#334155;}' +
      '.footer{padding:10mm 16mm 14mm;border-top:1px solid #dbeafe;font-size:11px;color:#64748b;display:flex;justify-content:space-between;gap:12px;}' +
      '</style></head><body><div class="sheet">' +
      '<div class="header"><div class="brand"><img src="' + escapeHtml(companyInfo.logoUrl) + '" alt="Logo"><div><h1>' + escapeHtml(companyInfo.name) + '</h1><p>Professional Leave Application</p></div></div><div class="badge">' + escapeHtml(capitalize(detail.status)) + '</div></div>' +
      '<div class="body"><h2 class="title">Leave Application</h2>' +
      '<div class="grid">' +
      '<div class="cell"><small>Leave ID</small><strong>' + escapeHtml(detail.leave_code) + '</strong></div>' +
      '<div class="cell"><small>Employee Name</small><strong>' + escapeHtml(detail.employee_name) + '</strong></div>' +
      '<div class="cell"><small>Department</small><strong>' + escapeHtml(detail.department) + '</strong></div>' +
      '<div class="cell"><small>Leave Type</small><strong>' + escapeHtml(detail.leave_type) + '</strong></div>' +
      '<div class="cell"><small>Leave Period</small><strong>' + escapeHtml(formatDate(detail.from_date)) + ' to ' + escapeHtml(formatDate(detail.to_date)) + '</strong></div>' +
      '<div class="cell"><small>Total Days</small><strong>' + escapeHtml(String(detail.total_days)) + '</strong></div>' +
      '</div>' +
      '<div class="cell" style="margin-bottom:10mm;"><small>Application Date</small><strong>' + escapeHtml(formatDate(detail.created_at)) + '</strong></div>' +
      '<div><h3 style="margin:0 0 8px;font-size:15px;color:#0f172a;">Reason for Leave</h3><div class="reason">' + escapeHtml(detail.reason_text || 'Voice-only request submitted. Please refer to admin note if text was completed later.') + '</div></div>' +
      '<div class="approval"><div class="sign">Employee Signature<br><strong>' + escapeHtml(detail.employee_name) + '</strong></div><div class="sign">Admin Approval Signature<br><strong>' + approvalText + '</strong></div></div>' +
      '</div><div class="footer"><div>Generated from ERP Leave Management System</div><div>' + escapeHtml(companyInfo.name) + '</div></div></div></body></html>';

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(function(){
      printWindow.print();
    }, 300);
  }

  async function requestJson(payload) {
    var options = { credentials: 'same-origin' };
    var response;
    if (payload instanceof FormData) {
      options.method = 'POST';
      options.body = payload;
      response = await fetch(apiUrl, options);
    } else {
      var params = new URLSearchParams(payload || {});
      response = await fetch(apiUrl + '?' + params.toString(), options);
    }
    var text = await response.text();
    var data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error('Leave API returned invalid response. ' + text.slice(0, 180));
    }
    return data;
  }

  function renderErrorRow(container, colspan, message) {
    if (!container) return;
    container.innerHTML = '<tr><td colspan="' + colspan + '" class="lm-empty">' + escapeHtml(message) + '</td></tr>';
  }

  function statusBadge(status) {
    return '<span class="lm-status ' + escapeHtml(status) + '">' + escapeHtml(capitalize(status)) + '</span>';
  }

  function reasonPreview(reasonText) {
    var raw = String(reasonText || '');
    var text = raw.trim();
    if (!text) {
      return '<span style="color:#64748b;font-weight:700;">Voice only</span>';
    }
    if (text.length > 72) {
      text = text.slice(0, 72) + '...';
    }
    return '<span title="' + escapeHtml(raw) + '">' + escapeHtml(text) + '</span>';
  }

  function mimeToExtension(mimeType) {
    var map = {
      'audio/webm': 'webm',
      'audio/ogg': 'ogg',
      'audio/mp4': 'm4a',
      'audio/mpeg': 'mp3',
      'audio/wav': 'wav',
      'audio/x-wav': 'wav'
    };
    return map[mimeType] || 'webm';
  }

  function formatDate(value) {
    if (!value) return 'N/A';
    var date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function capitalize(value) {
    value = String(value || '');
    return value.charAt(0).toUpperCase() + value.slice(1);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showToast(message, tone) {
    if (!els.toast) return;
    els.toast.className = 'lm-toast ' + (tone || 'info') + ' show';
    els.toast.textContent = message;
    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(function(){
      els.toast.classList.remove('show');
    }, 2800);
  }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>