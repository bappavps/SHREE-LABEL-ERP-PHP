<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Print Job Cards History';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$footerErpName = getErpDisplayName((string)$companyName);
$appFooterLeft = 'Version : ' . APP_VERSION;
$appFooterRight = '© ' . date('Y') . ' ' . $footerErpName . ' • ERP Master System v' . APP_VERSION . ' | @ Developed by Mriganka Bhusan Debnath';

$csrf = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> | <?= e($companyName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --brand: #1e40af; --brand-dark: #1e3a8a; --success: #16a34a; --warning: #ea580c; --danger: #dc2626; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; color: #1e293b; line-height: 1.6; }
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .header { background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
    .header h1 { font-size: 2rem; font-weight: 900; display: flex; align-items: center; gap: 15px; }
    .header h1 i { font-size: 2.5rem; }
    .header p { font-size: .9rem; opacity: .9; margin-top: 10px; }

    .filters { background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
    .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
    .filter-group { display: flex; flex-direction: column; gap: 6px; }
    .filter-group label { font-size: .8rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: .05em; }
    .filter-group select, .filter-group input { padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: .9rem; }
    .filter-group input:focus, .filter-group select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30,64,175,.1); }
    
    .filter-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
    .btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: .9rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all .2s; }
    .btn-primary { background: var(--brand); color: white; }
    .btn-primary:hover { background: var(--brand-dark); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(30,64,175,.3); }
    .btn-secondary { background: #e2e8f0; color: #475569; }
    .btn-secondary:hover { background: #cbd5e1; }
    .btn-success { background: var(--success); color: white; }
    .btn-success:hover { background: #15803d; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-danger:hover { background: #b91c1c; }
    .btn:disabled { opacity: .5; cursor: not-allowed; }

    .jobs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; }
    .job-card { background: white; border: 2px solid #e2e8f0; border-radius: 12px; overflow: hidden; transition: all .2s; }
    .job-card:hover { border-color: var(--brand); box-shadow: 0 8px 24px rgba(30,64,175,.15); }
    .job-card.selected { border-color: var(--success); background: #f0fdf4; box-shadow: 0 8px 24px rgba(22,163,74,.15); }
    .job-card-header { padding: 15px; background: linear-gradient(135deg, var(--brand), #3b82f6); color: white; }
    .job-card-header .job-no { font-size: 1.1rem; font-weight: 900; }
    .job-card-header .job-name { font-size: .75rem; opacity: .9; margin-top: 4px; }
    .job-card-body { padding: 15px; }
    .job-info { display: flex; margin-bottom: 10px; font-size: .85rem; }
    .job-info-label { font-weight: 700; color: #64748b; min-width: 70px; }
    .job-info-value { color: #1e293b; flex: 1; }
    .job-status-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: .7rem; font-weight: 800; text-transform: uppercase; margin-top: 10px; }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .job-checkbox { float: right; margin-top: -35px; margin-right: -5px; width: 20px; height: 20px; cursor: pointer; }

    .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .stat-box { background: white; padding: 15px; border-radius: 12px; border-left: 4px solid var(--brand); }
    .stat-box h3 { font-size: .8rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }
    .stat-box .value { font-size: 2rem; font-weight: 900; color: var(--brand); }

    .print-preview { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
    .print-preview.active { display: flex; }
    .print-preview-content { background: white; border-radius: 12px; max-width: 800px; max-height: 90vh; overflow-y: auto; padding: 30px; }
    .print-preview-close { position: absolute; top: 20px; right: 20px; background: white; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 1.5rem; cursor: pointer; }
    
    .empty-state { text-align: center; padding: 80px 20px; }
    .empty-state i { font-size: 4rem; color: #cbd5e1; margin-bottom: 20px; }
    .empty-state p { color: #94a3b8; font-size: .95rem; }

    @media (max-width: 768px) {
      .jobs-grid { grid-template-columns: 1fr; }
      .filter-row { grid-template-columns: 1fr; }
      .header h1 { font-size: 1.5rem; }
      .stats-bar { grid-template-columns: repeat(2, 1fr); }
    }

    @media print {
      body { background: white; }
      .header, .filters, .print-preview-close, .print-preview { display: none !important; }
      .print-preview.active { display: block !important; position: static; background: white; padding: 0; }
      .print-preview-content { max-width: none; max-height: none; padding: 0; }
    }
  </style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1><i class="bi bi-printer"></i> Print Job Cards History</h1>
    <p>Find and print past or finished job cards by date range. Select multiple cards and print in bulk.</p>
  </div>

  <!-- Statistics -->
  <div class="stats-bar">
    <div class="stat-box">
      <h3>Total Jobs Today</h3>
      <div class="value" id="statToday">-</div>
    </div>
    <div class="stat-box">
      <h3>Selected for Print</h3>
      <div class="value" id="statSelected">0</div>
    </div>
    <div class="stat-box">
      <h3>Completed Jobs</h3>
      <div class="value" id="statCompleted">-</div>
    </div>
  </div>

  <!-- Filters -->
  <div class="filters">
    <div class="filter-row">
      <div class="filter-group">
        <label>Date Range</label>
        <select id="dateRange" onchange="updateDateInputs()">
          <option value="today">Today</option>
          <option value="week">This Week</option>
          <option value="month">This Month</option>
          <option value="year">This Year</option>
          <option value="custom">Custom Range</option>
        </select>
      </div>
      <div class="filter-group">
        <label>From Date</label>
        <input type="date" id="dateFrom">
      </div>
      <div class="filter-group">
        <label>To Date</label>
        <input type="date" id="dateTo">
      </div>
      <div class="filter-group">
        <label>Job Status</label>
        <select id="jobStatus">
          <option value="">All Statuses</option>
          <option value="Completed">Completed Only</option>
          <option value="Closed">Closed Only</option>
          <option value="Finalized">Finalized Only</option>
        </select>
      </div>
    </div>
    <div class="filter-buttons">
      <button class="btn btn-primary" onclick="applyFilters()"><i class="bi bi-funnel"></i> Apply Filters</button>
      <button class="btn btn-secondary" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      <button class="btn btn-success" id="printBtn" onclick="printSelected()" style="margin-left:auto"><i class="bi bi-printer"></i> Print Selected</button>
      <button class="btn btn-secondary" onclick="selectAll()"><i class="bi bi-check-all"></i> Select All</button>
      <button class="btn btn-secondary" onclick="deselectAll()"><i class="bi bi-x-circle"></i> Deselect</button>
    </div>
  </div>

  <!-- Jobs Grid -->
  <div id="jobsContainer" class="jobs-grid">
    <div class="empty-state">
      <i class="bi bi-search"></i>
      <p>Click "Apply Filters" to load job cards</p>
    </div>
  </div>
</div>

<!-- Print Preview Modal -->
<div class="print-preview" id="printPreview">
  <button class="print-preview-close" onclick="closePrintPreview()"><i class="bi bi-x"></i></button>
  <div class="print-preview-content" id="printPreviewContent"></div>
</div>

<script>
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const CSRF = '<?= e($csrf) ?>';
let allJobs = [];
let selectedJobs = new Set();

function getDateRange(range) {
  const today = new Date();
  const start = new Date();
  const end = new Date();
  
  switch(range) {
    case 'today':
      start.setHours(0, 0, 0, 0);
      end.setHours(23, 59, 59, 999);
      break;
    case 'week':
      const day = today.getDay();
      start.setDate(today.getDate() - day);
      start.setHours(0, 0, 0, 0);
      end.setHours(23, 59, 59, 999);
      break;
    case 'month':
      start.setDate(1);
      start.setHours(0, 0, 0, 0);
      end.setHours(23, 59, 59, 999);
      break;
    case 'year':
      start.setMonth(0, 1);
      start.setHours(0, 0, 0, 0);
      end.setHours(23, 59, 59, 999);
      break;
    default:
      return null;
  }
  return { start: start.toISOString().split('T')[0], end: end.toISOString().split('T')[0] };
}

function updateDateInputs() {
  const range = document.getElementById('dateRange').value;
  const fromInput = document.getElementById('dateFrom');
  const toInput = document.getElementById('dateTo');
  
  if (range === 'custom') {
    fromInput.disabled = false;
    toInput.disabled = false;
  } else {
    fromInput.disabled = true;
    toInput.disabled = true;
    const dates = getDateRange(range);
    if (dates) {
      fromInput.value = dates.start;
      toInput.value = dates.end;
    }
  }
}

async function applyFilters() {
  const range = document.getElementById('dateRange').value;
  const status = document.getElementById('jobStatus').value;
  const fromDate = document.getElementById('dateFrom').value;
  const toDate = document.getElementById('dateTo').value;
  
  const container = document.getElementById('jobsContainer');
  container.innerHTML = '<div class="empty-state"><i class="bi bi-hourglass"></i><p>Loading...</p></div>';
  
  try {
    const params = new URLSearchParams({
      action: 'list_jobs',
      csrf_token: CSRF,
      job_type: 'Slitting',
      status: status || '',
      date_from: fromDate,
      date_to: toDate,
      limit: 500
    });
    
    const res = await fetch(API_BASE + '?' + params.toString());
    const data = await res.json();
    
    if (data.ok && data.jobs && data.jobs.length) {
      allJobs = data.jobs.filter(j => ['Closed', 'Finalized', 'Completed'].includes(j.status));
      selectedJobs.clear();
      renderJobs();
      updateStats();
    } else {
      container.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i><p>No job cards found for this date range</p></div>';
    }
  } catch (err) {
    container.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-triangle"></i><p>Error loading jobs: ' + err.message + '</p></div>';
  }
}

function renderJobs() {
  const container = document.getElementById('jobsContainer');
  if (!allJobs.length) {
    container.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i><p>No jobs to display</p></div>';
    return;
  }
  
  container.innerHTML = allJobs.map(job => `
    <div class="job-card ${selectedJobs.has(job.id) ? 'selected' : ''}" onclick="toggleJobSelect(${job.id}, event)">
      <input type="checkbox" class="job-checkbox" id="check-${job.id}" ${selectedJobs.has(job.id) ? 'checked' : ''} onchange="toggleJobSelect(${job.id})">
      <div class="job-card-header">
        <div class="job-no">${job.job_no}</div>
        <div class="job-name">${job.planning_job_name || 'Slitting Job'}</div>
      </div>
      <div class="job-card-body">
        <div class="job-info"><span class="job-info-label">Status:</span><span class="job-info-value">${job.status}</span></div>
        <div class="job-info"><span class="job-info-label">Date:</span><span class="job-info-value">${job.created_at ? new Date(job.created_at).toLocaleDateString() : '—'}</span></div>
        <div class="job-info"><span class="job-info-label">Roll:</span><span class="job-info-value">${job.roll_no || '—'}</span></div>
        <div class="job-info"><span class="job-info-label">Priority:</span><span class="job-info-value">${job.planning_priority || 'Normal'}</span></div>
        <span class="job-status-badge ${job.status === 'Completed' ? 'status-completed' : 'status-pending'}">${job.status}</span>
      </div>
    </div>
  `).join('');
}

function toggleJobSelect(jobId, event) {
  if (event && event.target.tagName === 'INPUT') return;
  
  const checkbox = document.getElementById('check-' + jobId);
  if (selectedJobs.has(jobId)) {
    selectedJobs.delete(jobId);
    checkbox.checked = false;
  } else {
    selectedJobs.add(jobId);
    checkbox.checked = true;
  }
  renderJobs();
  updateStats();
}

function selectAll() {
  allJobs.forEach(j => selectedJobs.add(j.id));
  renderJobs();
  updateStats();
}

function deselectAll() {
  selectedJobs.clear();
  renderJobs();
  updateStats();
}

function updateStats() {
  document.getElementById('statSelected').textContent = selectedJobs.size;
  document.getElementById('statCompleted').textContent = allJobs.filter(j => j.status === 'Completed').length;
}

function resetFilters() {
  document.getElementById('dateRange').value = 'today';
  document.getElementById('jobStatus').value = '';
  updateDateInputs();
  document.getElementById('jobsContainer').innerHTML = '<div class="empty-state"><i class="bi bi-search"></i><p>Click "Apply Filters" to load job cards</p></div>';
  selectedJobs.clear();
  allJobs = [];
}

async function printSelected() {
  if (selectedJobs.size === 0) {
    alert('Please select at least one job card to print');
    return;
  }
  
  const selectedJobsArray = allJobs.filter(j => selectedJobs.has(j.id));
  let html = '<div style="font-family:Arial;color:#333">';
  
  selectedJobsArray.forEach((job, idx) => {
    if (idx > 0) html += '<div style="page-break-before:always"></div>';
    html += generateJobPrintHTML(job);
  });
  
  html += '</div>';
  
  document.getElementById('printPreviewContent').innerHTML = html;
  document.getElementById('printPreview').classList.add('active');
  
  setTimeout(() => window.print(), 500);
}

function generateJobPrintHTML(job) {
  return `<div style="page-break-after:always;padding:40px;border:1px solid #ddd;margin-bottom:20px">
    <div style="text-align:center;margin-bottom:30px">
      <h2 style="margin:0;color:#1e40af">JOB CARD</h2>
      <p style="font-size:24px;font-weight:bold;color:#333">${job.job_no}</p>
    </div>
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px">
      <tr><td style="padding:10px;border-bottom:1px solid #ddd;width:30%"><strong>Job Name:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.planning_job_name || '—'}</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Status:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.status}</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Roll No:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.roll_no || '—'}</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Created:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.created_at ? new Date(job.created_at).toLocaleString() : '—'}</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Priority:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.planning_priority || 'Normal'}</td></tr>
      <tr><td style="padding:10px;border-bottom:1px solid #ddd"><strong>Department:</strong></td><td style="padding:10px;border-bottom:1px solid #ddd">${job.department || '—'}</td></tr>
    </table>
    <div style="margin-top:50px;display:flex;justify-content:space-between">
      <div style="text-align:center"><div style="border-top:1px solid #333;width:150px;margin-top:10px">Operator Signature</div></div>
      <div style="text-align:center"><div style="border-top:1px solid #333;width:150px;margin-top:10px">Manager Signature</div></div>
    </div>
  </div>`;
}

function closePrintPreview() {
  document.getElementById('printPreview').classList.remove('active');
}

// Initialize
updateDateInputs();
</script>
</body>
</html>
