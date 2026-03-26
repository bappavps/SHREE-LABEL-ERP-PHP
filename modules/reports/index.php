<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

// ============================================================
// Fetch all paper_stock data
// ============================================================
$rows = [];
$res = $db->query("SELECT * FROM paper_stock ORDER BY date_received DESC, id DESC");
if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }

// Auto-compute SQM from Width and Length for every row
foreach ($rows as &$_r) {
  $w = (float)($_r['width_mm'] ?? 0);
  $l = (float)($_r['length_mtr'] ?? 0);
  $_r['sqm'] = ($w > 0 && $l > 0) ? round(($w / 1000) * $l, 2) : 0;
}
unset($_r);

// ============================================================
// Server-side KPI Metrics
// ============================================================
$totalRolls  = count($rows);
$totalWeight = 0;
$totalSqm    = 0;
$companiesSet = [];
$paperTypesSet = [];

foreach ($rows as $r) {
  $totalWeight += (float)($r['weight_kg'] ?? 0);
  $totalSqm   += (float)($r['sqm'] ?? 0);
  $co = trim((string)($r['company'] ?? ''));
  $pt = trim((string)($r['paper_type'] ?? ''));
  if ($co !== '') $companiesSet[$co] = true;
  if ($pt !== '') $paperTypesSet[$pt] = true;
}
$totalCompanies  = count($companiesSet);
$totalPaperTypes = count($paperTypesSet);

// ============================================================
// Build column filter distinct values
// ============================================================
$columnKeys = [
  ['id'=>'roll_no',          'label'=>'Roll No'],
  ['id'=>'status',           'label'=>'Status'],
  ['id'=>'company',          'label'=>'Paper Company'],
  ['id'=>'paper_type',       'label'=>'Paper Type'],
  ['id'=>'width_mm',         'label'=>'Width (MM)'],
  ['id'=>'length_mtr',       'label'=>'Length (MTR)'],
  ['id'=>'sqm',              'label'=>'SQM'],
  ['id'=>'gsm',              'label'=>'GSM'],
  ['id'=>'weight_kg',        'label'=>'Weight (KG)'],
  ['id'=>'purchase_rate',    'label'=>'Purchase Rate'],
  ['id'=>'date_received',    'label'=>'Date Received'],
  ['id'=>'date_used',        'label'=>'Date Used'],
  ['id'=>'job_no',           'label'=>'Job No'],
  ['id'=>'job_size',         'label'=>'Job Size'],
  ['id'=>'job_name',         'label'=>'Job Name'],
  ['id'=>'lot_batch_no',     'label'=>'Lot / Batch No'],
  ['id'=>'company_roll_no',  'label'=>'Company Roll No'],
  ['id'=>'remarks',          'label'=>'Remarks'],
];

$filterDistinct = [];
foreach ($columnKeys as $col) {
  $vals = [];
  foreach ($rows as $r) {
    $v = trim((string)($r[$col['id']] ?? ''));
    if ($v !== '' && !isset($vals[$v])) $vals[$v] = true;
  }
  ksort($vals);
  $filterDistinct[$col['id']] = array_keys($vals);
}

// JSON encode for JS
$jsRows = json_encode(array_values($rows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsColumnKeys = json_encode($columnKeys, JSON_HEX_TAG);
$jsFilterDistinct = json_encode($filterDistinct, JSON_HEX_TAG);

$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$userName = $_SESSION['user_name'] ?? 'User';
$reportDate = date('d M Y');

$pageTitle = 'Stock Intelligence Hub';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Analytics</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Reports</span>
</div>

<!-- ======================== 1. DASHBOARD HEADER ======================== -->
<div class="rpt-header no-print">
  <div>
    <h1 class="rpt-title">Stock Intelligence Hub</h1>
    <p class="rpt-subtitle">Multi-Perspective Inventory Analytics &amp; Reporting</p>
  </div>
  <div class="rpt-header-actions">
    <button class="btn rpt-btn-outline" onclick="rptExportCSV()"><i class="bi bi-file-earmark-spreadsheet" style="color:#10b981"></i> Export Excel</button>
    <button class="btn rpt-btn-dark" onclick="rptPrint()"><i class="bi bi-printer"></i> Print Filtered Report</button>
  </div>
</div>

<!-- ======================== 2. KPI SUMMARY CARDS ======================== -->
<div class="rpt-kpi-grid no-print">
  <div class="rpt-kpi-card">
    <div class="rpt-kpi-icon" style="background:rgba(34,197,94,.08)"><i class="bi bi-box-seam" style="color:#22c55e"></i></div>
    <div class="rpt-kpi-label">Total Rolls</div>
    <div class="rpt-kpi-value" style="color:#22c55e" id="rpt-kpi-rolls"><?= number_format($totalRolls) ?></div>
  </div>
  <div class="rpt-kpi-card">
    <div class="rpt-kpi-icon" style="background:rgba(225,29,72,.06)"><i class="bi bi-speedometer2" style="color:#e11d48"></i></div>
    <div class="rpt-kpi-label">Total Weight</div>
    <div class="rpt-kpi-value" style="color:#e11d48" id="rpt-kpi-weight"><?= number_format($totalWeight, 1) ?> KG</div>
  </div>
  <div class="rpt-kpi-card">
    <div class="rpt-kpi-icon" style="background:rgba(16,185,129,.06)"><i class="bi bi-aspect-ratio" style="color:#10b981"></i></div>
    <div class="rpt-kpi-label">Total SQM</div>
    <div class="rpt-kpi-value" style="color:#10b981" id="rpt-kpi-sqm"><?= number_format($totalSqm, 1) ?></div>
  </div>
  <div class="rpt-kpi-card">
    <div class="rpt-kpi-icon" style="background:rgba(59,130,246,.06)"><i class="bi bi-building" style="color:#3b82f6"></i></div>
    <div class="rpt-kpi-label">Companies</div>
    <div class="rpt-kpi-value" style="color:#3b82f6" id="rpt-kpi-companies"><?= $totalCompanies ?></div>
  </div>
  <div class="rpt-kpi-card">
    <div class="rpt-kpi-icon" style="background:rgba(139,92,246,.06)"><i class="bi bi-layers" style="color:#8b5cf6"></i></div>
    <div class="rpt-kpi-label">Paper Types</div>
    <div class="rpt-kpi-value" style="color:#8b5cf6" id="rpt-kpi-types"><?= $totalPaperTypes ?></div>
  </div>
</div>

<!-- ======================== 3. ANALYTICS SECTION ======================== -->
<div class="rpt-analytics-grid no-print">
  <!-- Left: Config Panel -->
  <div class="rpt-config-card">
    <div class="rpt-config-header">
      <i class="bi bi-gear" style="color:#22c55e"></i>
      <span>Analysis Configuration</span>
    </div>
    <div class="rpt-config-body">
      <div class="rpt-config-group">
        <label>Select Analysis Type</label>
        <select id="rpt-analysis-type" onchange="rptUpdateChart()">
          <option value="Status Distribution">Status Distribution</option>
          <option value="Company Wise Stock">Company Wise Stock</option>
          <option value="Paper Item Wise Stock" selected>Paper Item Wise Stock</option>
          <option value="GSM Wise Distribution">GSM Wise Distribution</option>
          <option value="Width Wise Distribution">Width Wise Distribution</option>
          <option value="Production Analysis">Production Usage Analysis</option>
        </select>
      </div>
      <div class="rpt-config-group">
        <label>Select Field for Graph</label>
        <select id="rpt-graph-field" onchange="rptUpdateChart()">
          <option value="company">Company</option>
          <option value="paper_type" selected>Paper Item</option>
          <option value="status">Status</option>
          <option value="gsm">GSM</option>
          <option value="width_mm">Width</option>
          <option value="job_name">Job Name</option>
        </select>
      </div>
      <div class="rpt-config-dataset">
        <div class="rpt-config-dataset-icon"><i class="bi bi-graph-up-arrow" style="color:#22c55e"></i></div>
        <div>
          <div class="rpt-config-dataset-label">Active Dataset</div>
          <div class="rpt-config-dataset-value" id="rpt-active-count"><?= number_format($totalRolls) ?> Records</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Chart Panel -->
  <div class="rpt-chart-card">
    <div class="rpt-chart-header">
      <div class="rpt-chart-title"><i class="bi bi-bar-chart-line" style="color:#22c55e"></i> Graphical Visualization</div>
      <span class="rpt-badge-live">LIVE DATA</span>
    </div>
    <div class="rpt-chart-body">
      <canvas id="rpt-chart-canvas"></canvas>
    </div>
  </div>
</div>

<!-- ======================== 4. FILTERED DATA TABLE ======================== -->
<div class="rpt-table-card no-print">
  <div class="rpt-table-header">
    <div>
      <div class="rpt-table-title"><i class="bi bi-file-text" style="color:#22c55e"></i> Filtered Technical Registry</div>
      <div class="rpt-table-sub">Apply Excel-style column filters to refine report data.</div>
    </div>
    <div class="rpt-table-controls">
      <div class="rpt-search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="rpt-global-search" placeholder="Global Keyword..." oninput="rptApplyFilters()">
      </div>
      <button class="rpt-clear-btn" title="Clear all filters" onclick="rptClearAllFilters()"><i class="bi bi-funnel-fill"></i></button>
    </div>
  </div>
  <div class="rpt-table-scroll" id="rpt-table-scroll">
    <table class="rpt-table" id="rpt-table">
      <thead>
        <tr>
          <th style="min-width:60px">
            <div class="rpt-th-inner"><span>Sl No</span></div>
          </th>
          <?php foreach ($columnKeys as $col): ?>
          <th>
            <div class="rpt-th-inner">
              <span><?= e($col['label']) ?></span>
              <button class="rpt-filter-btn" data-col="<?= e($col['id']) ?>" onclick="rptOpenColFilter(this, '<?= e($col['id']) ?>')" title="Filter <?= e($col['label']) ?>"><i class="bi bi-chevron-down"></i></button>
            </div>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody id="rpt-tbody">
        <?php if (empty($rows)): ?>
          <tr><td colspan="20" style="text-align:center;padding:60px 20px;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:12px"></i>No paper stock records found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
          <tr data-idx="<?= $i ?>">
            <td style="text-align:center;font-size:.75rem;font-weight:700;color:#94a3b8"><?= $i + 1 ?></td>
            <?php foreach ($columnKeys as $col):
              $val = trim((string)($r[$col['id']] ?? ''));
            ?>
            <td data-col="<?= $col['id'] ?>">
              <?php if ($col['id'] === 'status' && $val !== ''): ?>
                <span class="badge badge-<?= strtolower(str_replace(' ', '-', $val)) ?>"><?= e($val) ?></span>
              <?php elseif ($col['id'] === 'sqm'): ?>
                <span style="font-weight:700;color:#16a34a"><?= $val !== '' && $val !== '0' ? e($val) : '<span style="opacity:.3">-</span>' ?></span>
              <?php elseif ($col['id'] === 'roll_no'): ?>
                <span style="color:var(--brand);font-weight:900;font-family:monospace"><?= e($val) ?></span>
              <?php else: ?>
                <?= $val !== '' ? e($val) : '<span style="opacity:.3">-</span>' ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <!-- Summary Footer -->
  <div class="rpt-table-footer">
    <div class="rpt-footer-stats">
      <span>Records: <strong id="rpt-footer-count"><?= number_format($totalRolls) ?></strong></span>
      <span>Net SQM: <strong id="rpt-footer-sqm" style="color:var(--brand)"><?= number_format($totalSqm, 1) ?></strong></span>
      <span>Net Weight: <strong id="rpt-footer-weight" style="color:#e11d48"><?= number_format($totalWeight, 1) ?> KG</strong></span>
    </div>
    <div class="rpt-footer-label">Filtered Registry View</div>
  </div>
</div>

<!-- ======================== 5. COLUMN FILTER POPUP ======================== -->
<div id="rpt-cf-popup" class="rpt-cf-popup" style="display:none">
  <div class="rpt-cf-header">
    <span id="rpt-cf-title">Filter</span>
    <button onclick="rptCloseColFilter()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:1rem"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="rpt-cf-search"><input type="text" id="rpt-cf-search" placeholder="Search values..." oninput="rptFilterCfList()"></div>
  <div class="rpt-cf-actions">
    <button onclick="rptCfSelectAll()">Select All</button>
    <button onclick="rptCfDeselectAll()">Deselect All</button>
  </div>
  <div class="rpt-cf-list" id="rpt-cf-list"></div>
  <div class="rpt-cf-footer">
    <button class="btn rpt-btn-dark" onclick="rptApplyCf()" style="width:100%;padding:8px;font-size:.7rem">Apply Filter</button>
  </div>
</div>

<!-- ======================== 6. PRINTABLE A4 REPORT ======================== -->
<div id="rpt-print-area">
  <div class="rpt-print-header">
    <div>
      <h1 class="rpt-print-company"><?= e($companyName) ?></h1>
      <p class="rpt-print-subtitle">Industrial Technical Registry Report</p>
    </div>
    <div style="text-align:right">
      <h2 class="rpt-print-title">Paper Stock Audit</h2>
      <p class="rpt-print-date">REPORT DATE: <?= e($reportDate) ?></p>
    </div>
  </div>
  <div class="rpt-print-summary">
    <div>
      <p style="color:var(--brand);font-weight:900;margin-bottom:6px">APPLIED FILTERS:</p>
      <div id="rpt-print-filters" style="opacity:.7;font-size:9px;text-transform:uppercase">ALL RECORDS INCLUDED</div>
    </div>
    <div style="text-align:right">
      <p id="rpt-print-total-rolls" style="font-size:1.1rem;font-weight:900">TOTAL ROLLS: <?= number_format($totalRolls) ?></p>
      <p id="rpt-print-total-sqm" style="font-size:1.1rem;font-weight:900">TOTAL SQM: <?= number_format($totalSqm, 1) ?></p>
      <p id="rpt-print-total-weight" style="font-size:1.1rem;font-weight:900">TOTAL WEIGHT: <?= number_format($totalWeight, 1) ?> KG</p>
    </div>
  </div>
  <table class="rpt-print-table" id="rpt-print-table">
    <thead>
      <tr>
        <th>Sl</th><th>Roll No</th><th>Company</th><th>Paper Item</th><th>GSM</th><th>Width</th><th>SQM</th><th>Weight</th><th>Status</th>
      </tr>
    </thead>
    <tbody id="rpt-print-tbody"></tbody>
  </table>
  <div class="rpt-print-footer">
    <p>Generated by <?= e($userName) ?></p>
    <p>System Ver 3.0 &bull; Confidential Management Report</p>
  </div>
</div>

<!-- ======================== STYLES ======================== -->
<style>
/* ---- KPI Cards ---- */
.rpt-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
.rpt-title { font-size:1.8rem; font-weight:900; text-transform:uppercase; letter-spacing:-.04em; color:#0f172a; line-height:1.1; }
.rpt-subtitle { font-size:.7rem; font-weight:600; text-transform:uppercase; letter-spacing:.12em; color:#94a3b8; margin-top:4px; }
.rpt-header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.rpt-btn-outline { border:2px solid #e2e8f0; background:white; font-weight:800; font-size:.68rem; text-transform:uppercase; letter-spacing:.08em; padding:10px 22px; border-radius:12px; display:flex; align-items:center; gap:8px; cursor:pointer; transition:all .15s; }
.rpt-btn-outline:hover { border-color:#cbd5e1; background:#f8fafc; }
.rpt-btn-dark { background:#0f172a; color:white; font-weight:800; font-size:.68rem; text-transform:uppercase; letter-spacing:.08em; padding:10px 26px; border-radius:12px; border:none; display:flex; align-items:center; gap:8px; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,.15); transition:all .15s; }
.rpt-btn-dark:hover { background:#000; }

.rpt-kpi-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:16px; margin-bottom:28px; }
@media(max-width:900px) { .rpt-kpi-grid { grid-template-columns:repeat(3,1fr); } }
@media(max-width:600px) { .rpt-kpi-grid { grid-template-columns:repeat(2,1fr); } }
.rpt-kpi-card { background:white; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.05); padding:24px 16px; display:flex; flex-direction:column; align-items:center; text-align:center; gap:8px; transition:transform .2s; }
.rpt-kpi-card:hover { transform:scale(1.04); }
.rpt-kpi-icon { width:44px; height:44px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; }
.rpt-kpi-label { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.12em; color:#94a3b8; }
.rpt-kpi-value { font-size:1.6rem; font-weight:900; letter-spacing:-.03em; }

/* ---- Analytics Grid ---- */
.rpt-analytics-grid { display:grid; grid-template-columns:1fr 2fr; gap:24px; margin-bottom:28px; }
@media(max-width:900px) { .rpt-analytics-grid { grid-template-columns:1fr; } }

.rpt-config-card { background:#0f172a; color:white; border-radius:24px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.12); }
.rpt-config-header { display:flex; align-items:center; gap:10px; padding:24px 28px; border-bottom:1px solid rgba(255,255,255,.08); font-size:.7rem; font-weight:900; text-transform:uppercase; letter-spacing:.1em; }
.rpt-config-body { padding:28px; }
.rpt-config-group { margin-bottom:24px; }
.rpt-config-group label { display:block; font-size:9px; font-weight:800; text-transform:uppercase; opacity:.4; margin-bottom:8px; letter-spacing:.08em; }
.rpt-config-group select { width:100%; height:44px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); border-radius:10px; color:white; padding:0 12px; font-weight:700; font-size:.82rem; font-family:inherit; appearance:auto; cursor:pointer; }
.rpt-config-group select option { background:#1e293b; color:white; }

.rpt-config-dataset { margin-top:28px; padding-top:24px; border-top:1px solid rgba(255,255,255,.08); display:flex; align-items:center; gap:14px; }
.rpt-config-dataset-icon { width:42px; height:42px; background:rgba(34,197,94,.15); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
.rpt-config-dataset-label { font-size:9px; font-weight:800; text-transform:uppercase; opacity:.4; letter-spacing:.08em; }
.rpt-config-dataset-value { font-size:1.15rem; font-weight:800; }

.rpt-chart-card { background:white; border-radius:24px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.06); display:flex; flex-direction:column; }
.rpt-chart-header { display:flex; align-items:center; justify-content:space-between; padding:20px 28px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
.rpt-chart-title { font-size:.7rem; font-weight:900; text-transform:uppercase; letter-spacing:.1em; display:flex; align-items:center; gap:10px; }
.rpt-badge-live { display:inline-flex; align-items:center; padding:3px 10px; border-radius:6px; background:#10b981; color:white; font-size:9px; font-weight:900; letter-spacing:.06em; }
.rpt-chart-body { flex:1; padding:24px; min-height:360px; display:flex; align-items:center; justify-content:center; position:relative; }
.rpt-chart-body canvas { max-width:100%; max-height:340px; }

/* ---- Data Table ---- */
.rpt-table-card { background:white; border-radius:24px; overflow:hidden; box-shadow:0 10px 50px rgba(0,0,0,.08); margin-bottom:28px; }
.rpt-table-header { background:#0f172a; color:white; padding:20px 28px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.rpt-table-title { font-size:.7rem; font-weight:900; text-transform:uppercase; letter-spacing:.1em; display:flex; align-items:center; gap:10px; }
.rpt-table-sub { font-size:9px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.08em; margin-top:4px; }
.rpt-table-controls { display:flex; align-items:center; gap:12px; }
.rpt-search-box { position:relative; }
.rpt-search-box i { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#64748b; font-size:.8rem; }
.rpt-search-box input { height:34px; width:220px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); border-radius:10px; color:white; padding:0 12px 0 32px; font-size:.75rem; font-weight:700; font-family:inherit; }
.rpt-search-box input::placeholder { color:#475569; }
.rpt-search-box input:focus { outline:none; border-color:rgba(255,255,255,.25); }
.rpt-clear-btn { background:none; border:none; color:#f87171; cursor:pointer; font-size:1rem; padding:6px; border-radius:8px; transition:background .15s; }
.rpt-clear-btn:hover { background:rgba(255,255,255,.08); }

.rpt-table-scroll { max-height:600px; overflow:auto; }
.rpt-table { width:100%; border-collapse:separate; border-spacing:0; min-width:2200px; font-size:.72rem; }
.rpt-table thead { position:sticky; top:0; z-index:10; }
.rpt-table th { background:#f1f5f9; padding:0; height:42px; border-bottom:2px solid #e2e8f0; border-right:1px solid #e2e8f0; white-space:nowrap; }
.rpt-th-inner { display:flex; align-items:center; justify-content:space-between; padding:0 10px; gap:6px; height:100%; }
.rpt-th-inner span { font-size:9px; font-weight:900; text-transform:uppercase; color:#475569; letter-spacing:.03em; }
.rpt-filter-btn { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:.65rem; padding:2px 4px; border-radius:4px; transition:all .15s; }
.rpt-filter-btn:hover { color:#6366f1; background:rgba(99,102,241,.08); }
.rpt-filter-btn.active { color:#6366f1; background:rgba(99,102,241,.12); }
.rpt-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; border-right:1px solid #f1f5f9; text-align:center; font-weight:600; white-space:nowrap; }
.rpt-table tbody tr { transition:background .1s; }
.rpt-table tbody tr:hover { background:#f8fafc; }
.rpt-table tbody tr.rpt-row-hidden { display:none; }

.rpt-table-footer { background:#f8fafc; padding:16px 28px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; }
.rpt-footer-stats { display:flex; gap:32px; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
.rpt-footer-stats strong { font-size:.9rem; }
.rpt-footer-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; font-style:italic; }

/* ---- Column Filter Popup ---- */
.rpt-cf-popup { position:fixed; z-index:9999; width:260px; max-height:380px; background:white; border-radius:14px; box-shadow:0 20px 50px rgba(0,0,0,.18); display:flex; flex-direction:column; overflow:hidden; border:1px solid #e2e8f0; }
.rpt-cf-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9; font-size:.72rem; font-weight:900; text-transform:uppercase; letter-spacing:.06em; }
.rpt-cf-search { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
.rpt-cf-search input { width:100%; padding:6px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:.75rem; font-family:inherit; }
.rpt-cf-search input:focus { outline:none; border-color:#6366f1; }
.rpt-cf-actions { display:flex; gap:8px; padding:6px 12px; border-bottom:1px solid #f1f5f9; }
.rpt-cf-actions button { background:none; border:none; color:#6366f1; font-size:9px; font-weight:800; text-transform:uppercase; cursor:pointer; padding:2px 6px; border-radius:4px; }
.rpt-cf-actions button:hover { background:rgba(99,102,241,.06); }
.rpt-cf-list { flex:1; overflow-y:auto; padding:8px 12px; max-height:200px; }
.rpt-cf-list label { display:flex; align-items:center; gap:8px; padding:4px 6px; border-radius:6px; cursor:pointer; font-size:.72rem; font-weight:600; color:#374151; transition:background .1s; }
.rpt-cf-list label:hover { background:#f8fafc; }
.rpt-cf-list input[type=checkbox] { accent-color:#6366f1; width:14px; height:14px; cursor:pointer; }
.rpt-cf-footer { padding:8px 12px; border-top:1px solid #f1f5f9; }

/* ---- Print Area ---- */
#rpt-print-area { display:none; }
.rpt-print-header { display:flex; justify-content:space-between; align-items:flex-end; padding-bottom:24px; border-bottom:4px solid #000; }
.rpt-print-company { font-size:2rem; font-weight:900; letter-spacing:-.04em; text-transform:uppercase; margin:0; }
.rpt-print-subtitle { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.12em; opacity:.6; }
.rpt-print-title { font-size:1.2rem; font-weight:900; text-transform:uppercase; }
.rpt-print-date { font-size:.8rem; font-weight:700; }
.rpt-print-summary { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:24px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:.75rem; font-weight:700; }
.rpt-print-table { width:100%; border-collapse:collapse; margin-top:24px; }
.rpt-print-table th { background:#f1f5f9; padding:8px 10px; text-align:left; font-size:9px; font-weight:900; text-transform:uppercase; border-bottom:2px solid #000; border-right:1px solid rgba(0,0,0,.08); }
.rpt-print-table th:last-child { border-right:none; }
.rpt-print-table td { padding:6px 10px; font-size:10px; font-weight:600; border-bottom:1px solid rgba(0,0,0,.06); border-right:1px solid rgba(0,0,0,.06); }
.rpt-print-table td:last-child { border-right:none; }
.rpt-print-footer { display:flex; justify-content:space-between; margin-top:60px; padding-top:24px; border-top:1px dashed rgba(0,0,0,.15); font-size:9px; font-weight:900; text-transform:uppercase; opacity:.4; }

@media print {
  body * { visibility:hidden!important; }
  #rpt-print-area, #rpt-print-area * { visibility:visible!important; }
  #rpt-print-area { display:block!important; position:absolute!important; left:0!important; top:0!important; width:100%!important; background:white!important; padding:10mm!important; }
  .no-print { display:none!important; }
  @page { size:A4 landscape; margin:10mm; }
}
</style>

<!-- ======================== CHART.JS CDN ======================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<script>
// ============================================================
// DATA & STATE
// ============================================================
const RPT_ROWS = <?= $jsRows ?>;
const RPT_COLS = <?= $jsColumnKeys ?>;
const RPT_FILTER_DISTINCT = <?= $jsFilterDistinct ?>;
const CHART_COLORS = ['#E4892B','#A33131','#10b981','#3b82f6','#8b5cf6','#f59e0b','#ec4899','#06b6d4','#14b8a6','#f43f5e'];

let rptColFilters = {}; // { col_id: [selected_values] }
let rptChartInstance = null;

// ============================================================
// HELPERS
// ============================================================
function rptEsc(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function rptFmt(n, d) { return Number(n||0).toLocaleString(undefined, d !== undefined ? {minimumFractionDigits:d,maximumFractionDigits:d} : {}); }

// ============================================================
// CLIENT-SIDE FILTERING
// ============================================================
function rptGetFilteredIndices() {
  const search = (document.getElementById('rpt-global-search').value || '').toLowerCase().trim();
  const indices = [];

  for (let i = 0; i < RPT_ROWS.length; i++) {
    const row = RPT_ROWS[i];
    let pass = true;

    // Global search
    if (search) {
      let found = false;
      for (const key of Object.keys(row)) {
        if (String(row[key] || '').toLowerCase().includes(search)) { found = true; break; }
      }
      if (!found) { pass = false; }
    }

    // Column filters
    if (pass) {
      for (const [col, selected] of Object.entries(rptColFilters)) {
        if (selected && selected.length > 0) {
          const val = String(row[col] || '').trim();
          if (!selected.includes(val)) { pass = false; break; }
        }
      }
    }

    if (pass) indices.push(i);
  }
  return indices;
}

function rptApplyFilters() {
  const visible = rptGetFilteredIndices();
  const visibleSet = new Set(visible);
  const tbody = document.getElementById('rpt-tbody');
  const trs = tbody.querySelectorAll('tr[data-idx]');

  let slCounter = 0;
  trs.forEach(tr => {
    const idx = parseInt(tr.dataset.idx, 10);
    if (visibleSet.has(idx)) {
      tr.classList.remove('rpt-row-hidden');
      slCounter++;
      const slTd = tr.querySelector('td:first-child');
      if (slTd) slTd.textContent = slCounter;
    } else {
      tr.classList.add('rpt-row-hidden');
    }
  });

  // Update metrics from filtered data
  let totalWeight = 0, totalSqm = 0;
  const companies = new Set(), types = new Set();
  visible.forEach(i => {
    const r = RPT_ROWS[i];
    totalWeight += Number(r.weight_kg || 0);
    totalSqm += (typeof erpCalcSQM === 'function') ? erpCalcSQM(r.width_mm, r.length_mtr) : Number(r.sqm || 0);
    if (r.company) companies.add(r.company);
    if (r.paper_type) types.add(r.paper_type);
  });

  // Update KPI cards
  document.getElementById('rpt-kpi-rolls').textContent = rptFmt(visible.length);
  document.getElementById('rpt-kpi-weight').textContent = rptFmt(totalWeight, 1) + ' KG';
  document.getElementById('rpt-kpi-sqm').textContent = rptFmt(totalSqm, 1);
  document.getElementById('rpt-kpi-companies').textContent = companies.size;
  document.getElementById('rpt-kpi-types').textContent = types.size;

  // Update footer
  document.getElementById('rpt-footer-count').textContent = rptFmt(visible.length);
  document.getElementById('rpt-footer-sqm').textContent = rptFmt(totalSqm, 1);
  document.getElementById('rpt-footer-weight').textContent = rptFmt(totalWeight, 1) + ' KG';

  // Update active dataset
  document.getElementById('rpt-active-count').textContent = rptFmt(visible.length) + ' Records';

  // Update filter buttons (highlight active)
  document.querySelectorAll('.rpt-filter-btn').forEach(btn => {
    const col = btn.dataset.col;
    if (rptColFilters[col] && rptColFilters[col].length > 0) btn.classList.add('active');
    else btn.classList.remove('active');
  });

  // Update chart with filtered data
  rptUpdateChart();
}

function rptClearAllFilters() {
  rptColFilters = {};
  document.getElementById('rpt-global-search').value = '';
  rptApplyFilters();
}

// ============================================================
// COLUMN FILTER POPUP
// ============================================================
let cfActiveCol = null;
let cfDraft = [];

function rptOpenColFilter(btn, col) {
  const popup = document.getElementById('rpt-cf-popup');
  const rect = btn.getBoundingClientRect();

  cfActiveCol = col;
  const colDef = RPT_COLS.find(c => c.id === col);
  document.getElementById('rpt-cf-title').textContent = colDef ? colDef.label : col;
  document.getElementById('rpt-cf-search').value = '';

  // Build checkbox list
  const distinct = RPT_FILTER_DISTINCT[col] || [];
  const selected = rptColFilters[col] || [];
  cfDraft = selected.length > 0 ? [...selected] : [...distinct];

  rptRenderCfList(distinct);

  // Position popup
  popup.style.display = 'flex';
  popup.style.flexDirection = 'column';
  let top = rect.bottom + 6;
  let left = rect.left;
  if (left + 260 > window.innerWidth) left = window.innerWidth - 270;
  if (top + 380 > window.innerHeight) top = rect.top - 386;
  popup.style.top = Math.max(4, top) + 'px';
  popup.style.left = Math.max(4, left) + 'px';
}

function rptRenderCfList(allValues) {
  const search = (document.getElementById('rpt-cf-search').value || '').toLowerCase();
  const listEl = document.getElementById('rpt-cf-list');
  const filtered = search ? allValues.filter(v => v.toLowerCase().includes(search)) : allValues;

  let html = '';
  filtered.forEach(v => {
    const checked = cfDraft.includes(v) ? 'checked' : '';
    html += '<label><input type="checkbox" value="'+rptEsc(v)+'" '+checked+' onchange="rptCfToggle(this.value, this.checked)"><span>'+rptEsc(v || '(blank)')+'</span></label>';
  });
  if (filtered.length === 0) html = '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:.75rem">No values</div>';
  listEl.innerHTML = html;
}

function rptFilterCfList() {
  const distinct = RPT_FILTER_DISTINCT[cfActiveCol] || [];
  rptRenderCfList(distinct);
}

function rptCfToggle(val, checked) {
  if (checked && !cfDraft.includes(val)) cfDraft.push(val);
  if (!checked) cfDraft = cfDraft.filter(v => v !== val);
}

function rptCfSelectAll() {
  cfDraft = [...(RPT_FILTER_DISTINCT[cfActiveCol] || [])];
  rptRenderCfList(RPT_FILTER_DISTINCT[cfActiveCol] || []);
}

function rptCfDeselectAll() {
  cfDraft = [];
  rptRenderCfList(RPT_FILTER_DISTINCT[cfActiveCol] || []);
}

function rptApplyCf() {
  const distinct = RPT_FILTER_DISTINCT[cfActiveCol] || [];
  if (cfDraft.length === distinct.length || cfDraft.length === 0) {
    delete rptColFilters[cfActiveCol];
  } else {
    rptColFilters[cfActiveCol] = [...cfDraft];
  }
  rptCloseColFilter();
  rptApplyFilters();
}

function rptCloseColFilter() {
  document.getElementById('rpt-cf-popup').style.display = 'none';
  cfActiveCol = null;
}

// Close popup on outside click
document.addEventListener('click', function(e) {
  const popup = document.getElementById('rpt-cf-popup');
  if (popup.style.display !== 'none' && !popup.contains(e.target) && !e.target.closest('.rpt-filter-btn')) {
    rptCloseColFilter();
  }
});

// ============================================================
// CHART.JS
// ============================================================
function rptUpdateChart() {
  const field = document.getElementById('rpt-graph-field').value;
  const type = document.getElementById('rpt-analysis-type').value;
  const isDistribution = type.includes('Distribution');

  const visible = rptGetFilteredIndices();
  const grouped = {};
  visible.forEach(i => {
    const r = RPT_ROWS[i];
    const key = String(r[field] || 'Unspecified').trim() || 'Unspecified';
    if (!grouped[key]) grouped[key] = { count:0, sqm:0, weight:0 };
    grouped[key].count++;
    grouped[key].sqm += (typeof erpCalcSQM === 'function') ? erpCalcSQM(r.width_mm, r.length_mtr) : Number(r.sqm || 0);
    grouped[key].weight += Number(r.weight_kg || 0);
  });

  // Sort by SQM descending, limit to top 10
  const entries = Object.entries(grouped)
    .map(([name, d]) => ({ name, ...d }))
    .sort((a, b) => b.sqm - a.sqm)
    .slice(0, 10);

  const labels = entries.map(e => e.name);
  const sqmData = entries.map(e => Math.round(e.sqm * 100) / 100);
  const colors = labels.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]);

  if (rptChartInstance) { rptChartInstance.destroy(); rptChartInstance = null; }

  const canvas = document.getElementById('rpt-chart-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  if (isDistribution) {
    rptChartInstance = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: sqmData,
          backgroundColor: colors,
          borderWidth: 0,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: { font: { size:10, weight:'bold', family:'Inter,sans-serif' }, padding:14, usePointStyle:true, pointStyleWidth:10 }
          },
          tooltip: {
            backgroundColor: '#0f172a',
            titleFont: { size:11, weight:'bold', family:'Inter,sans-serif' },
            bodyFont: { size:10, family:'Inter,sans-serif' },
            padding: 12,
            cornerRadius: 8
          }
        }
      }
    });
  } else {
    rptChartInstance = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'SQM',
          data: sqmData,
          backgroundColor: '#E4892B',
          borderRadius: 6,
          barPercentage: 0.7,
          categoryPercentage: 0.8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0f172a',
            titleFont: { size:11, weight:'bold', family:'Inter,sans-serif' },
            bodyFont: { size:10, family:'Inter,sans-serif' },
            padding: 12,
            cornerRadius: 8
          }
        },
        scales: {
          x: { grid: { display:false }, ticks: { font: { size:9, weight:'bold', family:'Inter,sans-serif' } } },
          y: { grid: { color:'rgba(0,0,0,.04)' }, ticks: { font: { size:9, family:'Inter,sans-serif' } } }
        }
      }
    });
  }
}

// ============================================================
// CSV EXPORT
// ============================================================
function rptExportCSV() {
  const visible = rptGetFilteredIndices();
  if (visible.length === 0) { alert('No data to export.'); return; }

  const colLabels = RPT_COLS.map(c => c.label);
  const colIds = RPT_COLS.map(c => c.id);

  let csv = '\uFEFF'; // BOM for Excel
  csv += '"Sl No",' + colLabels.map(l => '"'+l.replace(/"/g,'""')+'"').join(',') + '\n';

  visible.forEach((i, idx) => {
    const r = RPT_ROWS[i];
    csv += '"' + (idx + 1) + '",';
    csv += colIds.map(id => {
      let v = String(r[id] ?? '').replace(/"/g,'""');
      if (id === 'sqm' && typeof erpCalcSQM === 'function') v = erpCalcSQM(r.width_mm, r.length_mtr).toFixed(2);
      return '"'+v+'"';
    }).join(',') + '\n';
  });

  const d = new Date();
  const fname = 'Stock_Report_' + d.getFullYear() + String(d.getMonth()+1).padStart(2,'0') + String(d.getDate()).padStart(2,'0') + '.csv';
  const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = fname;
  a.click();
  URL.revokeObjectURL(a.href);
}

// ============================================================
// PRINT
// ============================================================
function rptPrint() {
  const visible = rptGetFilteredIndices();
  const tbody = document.getElementById('rpt-print-tbody');

  // Build filter summary
  const filtersEl = document.getElementById('rpt-print-filters');
  const search = (document.getElementById('rpt-global-search').value || '').trim();
  let filterLines = [];
  for (const [col, sel] of Object.entries(rptColFilters)) {
    if (sel && sel.length > 0) {
      const colDef = RPT_COLS.find(c => c.id === col);
      filterLines.push((colDef ? colDef.label : col) + ': ' + sel.join(', '));
    }
  }
  if (search) filterLines.push('Search: ' + search);
  filtersEl.textContent = filterLines.length > 0 ? filterLines.join(' | ') : 'ALL RECORDS INCLUDED';

  // Build totals
  let totalWeight = 0, totalSqm = 0;
  visible.forEach(i => {
    totalWeight += Number(RPT_ROWS[i].weight_kg || 0);
    totalSqm += (typeof erpCalcSQM === 'function') ? erpCalcSQM(RPT_ROWS[i].width_mm, RPT_ROWS[i].length_mtr) : Number(RPT_ROWS[i].sqm || 0);
  });
  document.getElementById('rpt-print-total-rolls').textContent = 'TOTAL ROLLS: ' + rptFmt(visible.length);
  document.getElementById('rpt-print-total-sqm').textContent = 'TOTAL SQM: ' + rptFmt(totalSqm, 1);
  document.getElementById('rpt-print-total-weight').textContent = 'TOTAL WEIGHT: ' + rptFmt(totalWeight, 1) + ' KG';

  // Build table rows
  let html = '';
  let slNo = 0;
  visible.forEach(i => {
    slNo++;
    const r = RPT_ROWS[i];
    const sqmVal = (typeof erpCalcSQM === 'function') ? erpCalcSQM(r.width_mm, r.length_mtr).toFixed(2) : (r.sqm || '-');
    html += '<tr>';
    html += '<td style="text-align:center;font-size:9px;font-weight:700;color:#94a3b8">' + slNo + '</td>';
    html += '<td style="font-family:monospace;font-weight:700">' + rptEsc(r.roll_no || '-') + '</td>';
    html += '<td>' + rptEsc(r.company || '-') + '</td>';
    html += '<td>' + rptEsc(r.paper_type || '-') + '</td>';
    html += '<td style="text-align:center">' + rptEsc(r.gsm || '-') + '</td>';
    html += '<td style="text-align:center">' + rptEsc(r.width_mm ? r.width_mm + 'mm' : '-') + '</td>';
    html += '<td style="text-align:center;font-weight:700">' + rptEsc(sqmVal) + '</td>';
    html += '<td style="text-align:center;font-weight:700">' + rptEsc(r.weight_kg ? r.weight_kg + ' kg' : '-') + '</td>';
    html += '<td style="font-weight:900;text-transform:uppercase;font-size:9px">' + rptEsc(r.status || '-') + '</td>';
    html += '</tr>';
  });
  tbody.innerHTML = html;

  window.print();
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
  rptUpdateChart();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
