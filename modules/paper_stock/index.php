<?php
// ============================================================
// ERP System — Paper Stock: List
// Unified filter system + Excel-style column filters
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db   = getDB();
$csrf = generateCSRF();

$allowedPerPage = [10, 20, 50, 100];
$perPageRaw = strtolower(trim((string)($_GET['per_page'] ?? '20')));
$isAllRows = ($perPageRaw === 'all');
$perPage = in_array((int)$perPageRaw, $allowedPerPage) ? (int)$perPageRaw : 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$allowedSort = ['roll_no','status','company','paper_type','width_mm','length_mtr','sqm','gsm','weight_kg','purchase_rate','date_received','date_used','job_no','job_size','job_name','lot_batch_no','company_roll_no','remarks'];
$sortCol = in_array($_GET['sort'] ?? '', $allowedSort) ? $_GET['sort'] : 'id';
$sortDir = (strtolower($_GET['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

$totalRes = $db->query('SELECT COUNT(*) AS c FROM paper_stock');
$total = (int)$totalRes->fetch_assoc()['c'];
$perPage = $isAllRows ? max(1, $total) : $perPage;
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

if ($isAllRows) {
  $stmt = $db->prepare("SELECT * FROM paper_stock ORDER BY `{$sortCol}` {$sortDir}");
  $stmt->execute();
  $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $page = 1;
  $totalPages = 1;
  $offset = 0;
} else {
  $stmt = $db->prepare("SELECT * FROM paper_stock ORDER BY `{$sortCol}` {$sortDir} LIMIT ? OFFSET ?");
  $stmt->bind_param('ii', $perPage, $offset);
  $stmt->execute();
  $rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$companies  = $db->query("SELECT DISTINCT company FROM paper_stock WHERE company IS NOT NULL AND company <> '' ORDER BY company")->fetch_all(MYSQLI_ASSOC);
$paperTypes = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE paper_type IS NOT NULL AND paper_type <> '' ORDER BY paper_type")->fetch_all(MYSQLI_ASSOC);
$gsmValues  = $db->query("SELECT DISTINCT gsm FROM paper_stock WHERE gsm IS NOT NULL ORDER BY gsm")->fetch_all(MYSQLI_ASSOC);
$statusValues = ['Available', 'Assigned', 'Consumed', 'Main', 'Stock', 'Slitting', 'Job Assign', 'In Production'];

$sumStmt = $db->query("SELECT company, paper_type, COUNT(*) AS total_rolls,
    IFNULL(SUM(length_mtr),0) AS total_mtr,
    IFNULL(SUM((width_mm/1000)*length_mtr),0) AS total_sqm
    FROM paper_stock
    GROUP BY company, paper_type ORDER BY total_sqm DESC");
$summaryGroups = $sumStmt->fetch_all(MYSQLI_ASSOC);

$totStmt = $db->query("SELECT IFNULL(SUM(length_mtr),0) AS total_mtr, IFNULL(SUM((width_mm/1000)*length_mtr),0) AS total_sqm FROM paper_stock");
$totals = $totStmt->fetch_assoc();

$allColumns = [
    'roll_no'         => ['Roll No',        'min-width:150px', 'left'],
    'status'          => ['Status',         'min-width:120px', 'left'],
    'company'         => ['Paper Company',  'min-width:150px', 'left'],
    'paper_type'      => ['Paper Type',     'min-width:160px', 'left'],
    'width_mm'        => ['Width (MM)',     'min-width:100px', 'right'],
    'length_mtr'      => ['Length (MTR)',   'min-width:120px', 'right'],
    'sqm'             => ['SQM',            'min-width:100px', 'right'],
    'gsm'             => ['GSM',            'min-width:80px',  'right'],
    'weight_kg'       => ['Weight (KG)',    'min-width:110px', 'right'],
    'purchase_rate'   => ['Purchase Rate',  'min-width:120px', 'right'],
    'date_received'   => ['Date Received',  'min-width:120px', 'left'],
    'date_used'       => ['Date Used',      'min-width:110px', 'left'],
    'job_no'          => ['Job No',         'min-width:120px', 'left'],
    'job_size'        => ['Job Size',       'min-width:120px', 'left'],
    'job_name'        => ['Job Name',       'min-width:150px', 'left'],
    'lot_batch_no'    => ['Lot / Batch No', 'min-width:130px', 'left'],
    'company_roll_no' => ['Company Roll No','min-width:140px', 'left'],
    'remarks'         => ['Remarks',        'min-width:170px', 'left'],
];

  // Distinct values for Excel-style column filters from full table (not current page)
  $colFilterData = [];
  foreach (array_keys($allColumns) as $colKey) {
    $sql = "SELECT DISTINCT `{$colKey}` AS v
        FROM paper_stock
        WHERE `{$colKey}` IS NOT NULL
          AND TRIM(CAST(`{$colKey}` AS CHAR)) <> ''
        ORDER BY `{$colKey}`";
    $res = $db->query($sql);
    $vals = [];
    if ($res) {
      while ($row = $res->fetch_assoc()) {
        $v = trim((string)($row['v'] ?? ''));
        if ($v !== '' && $v !== '-') $vals[] = $v;
      }
    }

    $blankSql = "SELECT COUNT(*) AS c
           FROM paper_stock
           WHERE `{$colKey}` IS NULL
            OR TRIM(CAST(`{$colKey}` AS CHAR)) = ''
            OR TRIM(CAST(`{$colKey}` AS CHAR)) = '-'";
    $blankRes = $db->query($blankSql);
    $hasBlank = false;
    if ($blankRes) {
      $blankRow = $blankRes->fetch_assoc();
      $hasBlank = ((int)($blankRow['c'] ?? 0)) > 0;
    }

    $colFilterData[$colKey] = [
      'values' => array_values(array_unique($vals)),
      'hasBlank' => $hasBlank,
    ];
  }

function sortUrl($col, $curSort, $curDir, $perPageRaw) {
    $dir = ($curSort === $col && $curDir === 'ASC') ? 'desc' : 'asc';
  return '?' . http_build_query(['sort'=>$col,'dir'=>$dir,'per_page'=>$perPageRaw,'page'=>1]);
}
function sortIcon($col, $curSort, $curDir) {
    if ($curSort !== $col) return '<i class="bi bi-arrow-down-up" style="opacity:.3;font-size:9px"></i>';
    return $curDir === 'ASC'
        ? '<i class="bi bi-arrow-up" style="color:#16a34a;font-size:9px"></i>'
        : '<i class="bi bi-arrow-down" style="color:#16a34a;font-size:9px"></i>';
}

$pageTitle = 'Paper Stock';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.ps-action-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.ps-action-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:.78rem;font-weight:700;border:1px solid var(--border);background:#fff;color:var(--text-main);cursor:pointer;text-decoration:none;transition:all .15s}
.ps-action-btn:hover{border-color:#94a3b8;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.ps-action-btn.green{color:#16a34a;border-color:#bbf7d0}.ps-action-btn.green:hover{background:#f0fdf4}
.ps-action-btn.blue{color:#2563eb;border-color:#bfdbfe}.ps-action-btn.blue:hover{background:#eff6ff}
.ps-action-btn.red{color:#dc2626;border-color:#fecaca}.ps-action-btn.red:hover{background:#fef2f2}
.ps-action-btn.orange{color:#ea580c;border-color:#fdba74}.ps-action-btn.orange:hover{background:#fff7ed}
.ps-action-btn[disabled]{opacity:.45;cursor:not-allowed;pointer-events:none}

.ps-summary-wrap{max-height:300px;overflow-y:auto;scrollbar-width:thin}
.ps-summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
.ps-summary-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px;box-shadow:var(--shadow-sm)}

.ps-quick-filter{display:flex;gap:8px;flex-wrap:nowrap;overflow-x:auto;align-items:center;background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow-sm);margin-bottom:12px}
.ps-qf-item{display:flex;flex-direction:column;gap:3px;min-width:150px;flex:1;justify-content:center}
.ps-qf-item label{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}
.ps-qf-item input,.ps-qf-item select{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.8rem;background:#fff}
.ps-qf-item input:focus,.ps-qf-item select:focus{outline:none;border-color:#86efac;box-shadow:0 0 0 3px rgba(34,197,94,.1)}
.ps-qf-actions{display:flex;align-items:center;gap:8px;min-width:max-content;padding-top:18px}
.ps-qf-reset{height:36px;display:inline-flex;align-items:center;justify-content:center;padding:0 14px;border-radius:8px;background:#f97316;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap}

.ps-qf-picker{position:relative}
.ps-qf-picker-btn{height:36px;width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff;font-size:.8rem;color:var(--text-main);cursor:pointer;text-align:left}
.ps-qf-picker-btn .muted{color:#94a3b8}
.ps-qf-picker-btn.active{border-color:#fdba74;background:#fff7ed}
.ps-qf-popup{display:none;position:fixed;z-index:240;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.18);width:260px;overflow:hidden}
.ps-qf-popup.open{display:block}
.ps-qf-popup-head{padding:10px;border-bottom:1px solid #f1f5f9}
.ps-qf-popup-search{width:100%;height:34px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem;background:#f8fafc}
.ps-qf-popup-list{max-height:220px;overflow-y:auto;padding:6px 0}
.ps-qf-popup-item{display:flex;align-items:center;padding:7px 10px;font-size:.78rem;cursor:pointer}
.ps-qf-popup-item:hover{background:#f8fafc}
.ps-qf-popup-item.active{background:#fff7ed;color:#ea580c;font-weight:700}

.ps-selected-summary{display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:.78rem;color:#92400e;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ps-selected-summary.visible{display:flex}
.ps-selected-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ps-selected-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}

.ps-col-panel{display:none;position:absolute;right:0;top:100%;z-index:150;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15);padding:12px;min-width:250px;max-height:430px;overflow-y:auto}
.ps-col-panel.open{display:block}
.ps-col-panel-tools{display:flex;gap:6px;margin-bottom:8px}
.ps-col-panel-tools button{padding:4px 8px;border:1px solid var(--border);border-radius:6px;background:#fff;font-size:.7rem;font-weight:700;cursor:pointer}
.ps-col-panel label{display:flex;align-items:center;gap:8px;padding:5px 0;font-size:.78rem;cursor:pointer}

.ps-grid-header{background:#0f172a;color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;border-radius:16px 16px 0 0}

.table-wrap{overflow:auto;max-height:700px}
#ps-table{min-width:2400px;border-collapse:separate;border-spacing:0}
#ps-table thead th{position:sticky;top:0;background:#f8fafc;z-index:12;border-bottom:1px solid #e2e8f0;white-space:nowrap}
#ps-table .sticky-check{position:sticky;left:0;z-index:18;background:#fff;min-width:44px}
#ps-table thead .sticky-check{background:#f8fafc;z-index:22}
#ps-table .sticky-sl{position:sticky;left:44px;z-index:17;background:#fff;min-width:64px}
#ps-table thead .sticky-sl{background:#f8fafc;z-index:21}
#ps-table .sticky-roll{position:sticky;left:108px;z-index:16;background:#fff}
#ps-table thead .sticky-roll{background:#f8fafc;z-index:20}
#ps-table .sticky-action{position:sticky;right:0;z-index:16;background:#fff}
#ps-table thead .sticky-action{background:#f8fafc;z-index:20}

.ps-row-selected td{background:#ecfdf5 !important}
.ps-row-selected .sticky-check,.ps-row-selected .sticky-sl,.ps-row-selected .sticky-roll,.ps-row-selected .sticky-action{background:#d1fae5 !important}

.col-filter-wrap{position:relative;display:inline-flex;align-items:center}
.col-filter-btn{background:none;border:none;cursor:pointer;padding:2px 4px;color:#94a3b8;font-size:10px;margin-left:4px;border-radius:4px;transition:all .12s}
.col-filter-btn:hover,.col-filter-btn.active{color:#16a34a;background:rgba(34,197,94,.12)}
.col-filter-btn .filter-count{display:none;position:absolute;top:-5px;right:-6px;background:#ef4444;color:#fff;border-radius:8px;font-size:7px;padding:1px 3px;line-height:1;min-width:11px;text-align:center}
.col-filter-btn.active .filter-count{display:block}

.cfp{display:none;position:fixed;top:0;left:0;z-index:220;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.18);min-width:250px;max-width:320px;overflow:hidden}
.cfp.open{display:block}
.cfp.anchor-right{left:auto;right:0}
.cfp-head{padding:10px;border-bottom:1px solid #f1f5f9}
.cfp-search{width:100%;border:1px solid var(--border);border-radius:8px;padding:7px 10px;font-size:.78rem;background:#f8fafc}
.cfp-search:focus{outline:none;border-color:#86efac;background:#fff}
.cfp-select-all{display:flex;align-items:center;gap:8px;font-size:.75rem;font-weight:700;color:#64748b;margin-top:8px}
.cfp-list{max-height:220px;overflow-y:auto;padding:6px 0}
.cfp-item{display:flex;align-items:center;gap:8px;padding:5px 10px;font-size:.78rem;cursor:pointer}
.cfp-item:hover{background:#f8fafc}
.cfp-item span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cfp-foot{display:flex;justify-content:flex-end;gap:6px;padding:8px 10px;border-top:1px solid #f1f5f9;background:#f8fafc}
.cfp-foot button{padding:5px 10px;border-radius:6px;border:1px solid var(--border);background:#fff;font-size:.72rem;font-weight:700;cursor:pointer}
.cfp-foot .ok{background:#0f172a;color:#fff;border-color:#0f172a}
.cfp-foot .apply{background:#16a34a;color:#fff;border-color:#16a34a}

.ps-row-actions{display:flex;gap:3px;justify-content:center}
.ps-row-actions a,.ps-row-actions button{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid var(--border);background:#fff;color:var(--text-muted);font-size:.78rem;cursor:pointer;text-decoration:none;padding:0}
.ps-row-actions .act-view{color:#2563eb;border-color:#bfdbfe}
.ps-row-actions .act-edit{color:#b45309;border-color:#fde68a}
.ps-row-actions .act-slit{color:#7c3aed;border-color:#ddd6fe}
.ps-row-actions .act-art{color:#0d9488;border-color:#99f6e4}
.ps-row-actions .act-print{color:#4b5563;border-color:#d1d5db}
.ps-row-actions .act-del{color:#dc2626;border-color:#fecaca}

.ps-pagination-footer{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px;border-top:1px solid #e2e8f0;background:#f8fafc}
.ps-page-left,.ps-page-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:.78rem;color:#64748b}
.ps-page-left input{width:70px;height:32px;border:1px solid var(--border);border-radius:8px;padding:0 8px}

@media print{
  .no-print{display:none !important}
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Paper Stock</span>
</div>

<div class="page-header" style="flex-wrap:wrap;gap:14px">
  <div>
    <h1>Paper Stock Details</h1>
    <p>Master inventory of all parent and child paper rolls.</p>
  </div>
  <div class="ps-action-bar no-print">
    <button type="button" class="ps-action-btn red" onclick="psBulkExport('pdf','all')"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
    <button type="button" class="ps-action-btn green" onclick="psBulkExport('csv','all')"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
    <button type="button" id="top-print-label-btn" class="ps-action-btn blue" onclick="psPrintLabels()" disabled><i class="bi bi-printer"></i> Print Label</button>
    <button type="button" class="ps-action-btn blue" onclick="psPrintStockReport()"><i class="bi bi-file-earmark-richtext"></i> Print Stock Report</button>
    <div style="position:relative;display:inline-block">
      <button type="button" onclick="toggleColPanel()" class="ps-action-btn" id="col-toggle-btn">
        <i class="bi bi-sliders2"></i> Columns
      </button>
      <div class="ps-col-panel" id="col-panel">
        <div style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;letter-spacing:.1em">Toggle Columns</div>
        <div class="ps-col-panel-tools">
          <button type="button" onclick="setAllColumns(true)">Select All</button>
          <button type="button" onclick="resetColumns()">Reset</button>
        </div>
        <?php foreach ($allColumns as $key => [$label, $s, $a]): ?>
        <label><input type="checkbox" class="col-toggle-cb" data-col="<?= $key ?>" checked> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="ps-action-btn orange">Reset Page</a>
  </div>
</div>

<div style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding:0 2px;flex-wrap:wrap;gap:8px">
    <span style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.15em">
      <i class="bi bi-grid" style="color:var(--brand)"></i>&nbsp; Technical Inventory Summary
    </span>
    <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
      <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase">
        Total Meter: <strong style="color:#0f172a;font-size:13px;margin-left:4px"><?= number_format($totals['total_mtr'], 0) ?></strong>
      </span>
      <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase">
        Total SQM: <strong style="color:#16a34a;font-size:13px;margin-left:4px"><?= number_format($totals['total_sqm'], 0) ?></strong>
      </span>
      <span class="badge badge-draft"><?= count($summaryGroups) ?> Technical Groups</span>
    </div>
  </div>
  <?php if (!empty($summaryGroups)): ?>
  <div class="ps-summary-wrap">
    <div class="ps-summary-grid">
      <?php foreach ($summaryGroups as $g): ?>
      <div class="ps-summary-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div style="min-width:0;flex:1">
            <div style="font-size:9px;font-weight:800;color:var(--brand);text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($g['company']) ?>"><?= e($g['company'] ?: 'Unknown') ?></div>
            <div style="font-size:12px;font-weight:800;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($g['paper_type']) ?>"><?= e($g['paper_type'] ?: 'Other') ?></div>
          </div>
          <span class="badge badge-consumed" style="font-size:9px;flex-shrink:0;margin-left:6px"><?= $g['total_rolls'] ?> ROLLS</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;border-top:1px solid #f1f5f9;padding-top:8px">
          <div>
            <div style="font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase">Running Length</div>
            <div style="font-size:12px;font-weight:800"><?= number_format($g['total_mtr'], 0) ?> <span style="font-size:8px;opacity:.4">MTR</span></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase">Surface Area</div>
            <div style="font-size:12px;font-weight:800;color:#16a34a"><?= number_format($g['total_sqm'], 0) ?> <span style="font-size:8px;opacity:.4">SQM</span></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="ps-quick-filter no-print" id="ps-quick-filter">
  <div class="ps-qf-item">
    <label>Global Search</label>
    <input type="text" id="qf-search" placeholder="Search everything...">
  </div>
  <div class="ps-qf-item">
    <label>Lot / Batch No</label>
    <input type="text" id="qf-lot" placeholder="Enter Lot ID...">
  </div>
  <div class="ps-qf-item">
    <label>Roll No</label>
    <input type="text" id="qf-roll" placeholder="Enter Roll ID...">
  </div>
  <div class="ps-qf-item">
    <label>Company</label>
    <div class="ps-qf-picker">
      <input type="hidden" id="qf-company" value="">
      <button type="button" class="ps-qf-picker-btn" data-qf-picker="company"><span class="muted">All Companies</span><i class="bi bi-chevron-down"></i></button>
      <div class="ps-qf-popup" id="qf-popup-company"></div>
    </div>
  </div>
  <div class="ps-qf-item">
    <label>Paper Type</label>
    <div class="ps-qf-picker">
      <input type="hidden" id="qf-type" value="">
      <button type="button" class="ps-qf-picker-btn" data-qf-picker="type"><span class="muted">All Types</span><i class="bi bi-chevron-down"></i></button>
      <div class="ps-qf-popup" id="qf-popup-type"></div>
    </div>
  </div>
  <div class="ps-qf-item">
    <label>GSM</label>
    <div class="ps-qf-picker">
      <input type="hidden" id="qf-gsm" value="">
      <button type="button" class="ps-qf-picker-btn" data-qf-picker="gsm"><span class="muted">All GSM</span><i class="bi bi-chevron-down"></i></button>
      <div class="ps-qf-popup" id="qf-popup-gsm"></div>
    </div>
  </div>
  <div class="ps-qf-item">
    <label>Status</label>
    <div class="ps-qf-picker">
      <input type="hidden" id="qf-status" value="">
      <button type="button" class="ps-qf-picker-btn" data-qf-picker="status"><span class="muted">All Status</span><i class="bi bi-chevron-down"></i></button>
      <div class="ps-qf-popup" id="qf-popup-status"></div>
    </div>
  </div>
  <div class="ps-qf-actions">
    <button type="button" class="ps-qf-reset" onclick="resetQuickFilters()">Reset</button>
  </div>
</div>

<div id="ps-selected-summary" class="ps-selected-summary no-print">
  <div class="ps-selected-meta">
    <strong><i class="bi bi-check2-square" style="color:var(--brand)"></i> <span id="sel-count">0</span> selected</strong>
    <span>MTR: <strong id="sel-mtr">0</strong></span>
    <span>SQM: <strong id="sel-sqm">0</strong></span>
  </div>
  <div class="ps-selected-actions">
    <button type="button" class="btn btn-sm" style="background:#ef4444;color:#fff;border:none" onclick="psBulkExport('pdf','selected')"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
    <button type="button" class="btn btn-sm" style="background:#22c55e;color:#fff;border:none" onclick="psBulkExport('csv','selected')"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
    <button type="button" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none" onclick="psBulkDelete()"><i class="bi bi-trash3"></i> Delete</button>
  </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:0 2px;flex-wrap:wrap;gap:8px" class="no-print">
  <form method="GET" style="display:flex;align-items:center;gap:8px">
    <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
    <input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>">
    <label style="font-size:12px;font-weight:600;color:#64748b">Rows:</label>
    <select name="per_page" onchange="this.form.submit()" style="height:32px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:600;background:#fff">
      <?php foreach ($allowedPerPage as $rp): ?>
      <option value="<?= $rp ?>" <?= (!$isAllRows && $perPage === $rp) ? 'selected' : '' ?>><?= $rp ?></option>
      <?php endforeach; ?>
      <option value="all" <?= $isAllRows ? 'selected' : '' ?>>ALL</option>
    </select>
  </form>
  <span style="font-size:12px;font-weight:600;color:#94a3b8">
    <?= $total === 0 ? 0 : $offset+1 ?>–<?= min($total, $offset+$perPage) ?> of <?= number_format($total) ?> rolls
  </span>
</div>

<div class="card" style="overflow:hidden;border-radius:16px">
  <div class="ps-grid-header">
    <span style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:10px">
      <i class="bi bi-grid" style="color:var(--brand)"></i> Master Grid
      <span class="badge badge-draft" style="margin-left:4px" id="grid-record-badge"><?= number_format($total) ?> records</span>
    </span>
    <a href="<?= BASE_URL ?>/modules/paper_stock/add.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:10px;font-size:.78rem;font-weight:700;background:#f97316;color:#fff;border:none;text-decoration:none">
      <i class="bi bi-plus-circle"></i> Add Roll
    </a>
  </div>

  <div class="table-wrap">
    <table id="ps-table">
      <thead>
        <tr>
          <th class="sticky-check" style="text-align:center"><input type="checkbox" id="ps-select-all" style="cursor:pointer;width:16px;height:16px"></th>
          <th class="sticky-sl" style="text-align:center">SL No.</th>
          <?php foreach ($allColumns as $colKey => [$colLabel, $colStyle, $colAlign]): ?>
          <th class="ps-col ps-col-<?= $colKey ?> <?= $colKey === 'roll_no' ? 'sticky-roll' : '' ?>" data-col-key="<?= $colKey ?>" style="<?= $colStyle ?>;text-align:<?= $colAlign ?>">
            <span class="col-filter-wrap">
              <a href="<?= sortUrl($colKey, $sortCol, $sortDir, $perPageRaw) ?>" style="color:inherit;text-decoration:none">
                <?= e($colLabel) ?> <?= sortIcon($colKey, $sortCol, $sortDir) ?>
              </a>
              <button type="button" class="col-filter-btn no-print" data-filter-col="<?= $colKey ?>" title="Filter <?= e($colLabel) ?>">
                <i class="bi bi-funnel-fill"></i><span class="filter-count"></span>
              </button>
              <div class="cfp no-print" id="cfp-<?= $colKey ?>"></div>
            </span>
          </th>
          <?php endforeach; ?>
          <th class="sticky-action" style="min-width:190px;text-align:center">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rolls)): ?>
        <tr class="ps-empty-row"><td colspan="<?= count($allColumns) + 3 ?>" class="table-empty"><i class="bi bi-inbox"></i> No paper rolls found.</td></tr>
      <?php else: ?>
        <?php foreach ($rolls as $i => $r): $sqm = calcSQM($r['width_mm'], $r['length_mtr']); ?>
        <tr class="ps-data-row" data-id="<?= $r['id'] ?>" data-mtr="<?= (float)$r['length_mtr'] ?>" data-sqm="<?= round($sqm,2) ?>">
          <td class="sticky-check" style="text-align:center"><input type="checkbox" class="ps-row-cb" value="<?= $r['id'] ?>" style="cursor:pointer;width:16px;height:16px"></td>
          <td class="sticky-sl" style="text-align:center;font-size:.75rem" data-sl="<?= $offset + $i + 1 ?>"><?= $offset + $i + 1 ?></td>
          <td class="ps-col ps-col-roll_no sticky-roll" style="font-weight:700;font-family:monospace"><a href="view.php?id=<?= $r['id'] ?>" style="color:#f97316;text-decoration:none"><?= e($r['roll_no']) ?></a></td>
          <td class="ps-col ps-col-status"><?= statusBadge($r['status']) ?></td>
          <td class="ps-col ps-col-company"><?= e($r['company']) ?></td>
          <td class="ps-col ps-col-paper_type"><?= e($r['paper_type']) ?></td>
          <td class="ps-col ps-col-width_mm" style="font-family:monospace;text-align:right"><?= e($r['width_mm']) ?></td>
          <td class="ps-col ps-col-length_mtr" style="font-family:monospace;font-weight:600;text-align:right"><?= number_format((float)$r['length_mtr'], 0) ?></td>
          <td class="ps-col ps-col-sqm" style="font-family:monospace;font-weight:700;text-align:right;color:#16a34a"><?= number_format($sqm, 2) ?></td>
          <td class="ps-col ps-col-gsm" style="font-family:monospace;text-align:right"><?= $r['gsm'] !== null ? e($r['gsm']) : '-' ?></td>
          <td class="ps-col ps-col-weight_kg" style="font-family:monospace;text-align:right"><?= $r['weight_kg'] !== null ? e($r['weight_kg']) : '-' ?></td>
          <td class="ps-col ps-col-purchase_rate" style="font-family:monospace;text-align:right"><?= $r['purchase_rate'] ? '₹'.number_format((float)$r['purchase_rate'],2) : '-' ?></td>
          <td class="ps-col ps-col-date_received text-muted"><?= formatDate($r['date_received']) ?></td>
          <td class="ps-col ps-col-date_used text-muted"><?= formatDate($r['date_used']) ?></td>
          <td class="ps-col ps-col-job_no" style="font-family:monospace;font-weight:600"><?= e($r['job_no'] ?? '-') ?></td>
          <td class="ps-col ps-col-job_size"><?= e($r['job_size'] ?? '-') ?></td>
          <td class="ps-col ps-col-job_name"><?= e($r['job_name'] ?? '-') ?></td>
          <td class="ps-col ps-col-lot_batch_no" style="font-family:monospace"><?= e($r['lot_batch_no'] ?? '-') ?></td>
          <td class="ps-col ps-col-company_roll_no"><?= e($r['company_roll_no'] ?? '-') ?></td>
          <td class="ps-col ps-col-remarks" style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b" title="<?= e($r['remarks'] ?? '') ?>"><?= e($r['remarks'] ?? '-') ?></td>
          <td class="sticky-action" style="text-align:center">
            <div class="ps-row-actions">
              <a href="view.php?id=<?= $r['id'] ?>" class="act-view" title="View"><i class="bi bi-eye"></i></a>
              <a href="edit.php?id=<?= $r['id'] ?>" class="act-edit" title="Edit"><i class="bi bi-pencil"></i></a>
              <a href="#" class="act-slit" title="Slitting" onclick="alert('Slitting feature coming soon');return false"><i class="bi bi-scissors"></i></a>
              <a href="#" class="act-art" title="Artwork" onclick="alert('Artwork feature coming soon');return false"><i class="bi bi-image"></i></a>
              <a href="#" class="act-print" title="Print Label" onclick="psPrintSingleLabel(<?= $r['id'] ?>);return false"><i class="bi bi-printer"></i></a>
              <a href="delete.php?id=<?= $r['id'] ?>&csrf=<?= $csrf ?>" class="act-del" title="Delete" data-confirm="Delete roll <?= e($r['roll_no']) ?>? This cannot be undone."><i class="bi bi-trash"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  if (!$isAllRows) {
      $qStr = http_build_query(['sort'=>$sortCol,'dir'=>strtolower($sortDir),'per_page'=>$perPageRaw,'page'=>'{page}']);
      echo paginationBar($total, $perPage, $page, BASE_URL . '/modules/paper_stock/index.php?' . $qStr);
  }
  ?>

  <div class="ps-pagination-footer no-print">
    <div class="ps-page-left">
      <span>Total pages: <strong><?= $totalPages ?></strong></span>
      <form method="GET" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="per_page" value="<?= e($perPageRaw) ?>">
        <input type="hidden" name="sort" value="<?= e($sortCol) ?>">
        <input type="hidden" name="dir" value="<?= e(strtolower($sortDir)) ?>">
        <label>Go to page</label>
        <input type="number" min="1" max="<?= $totalPages ?>" name="page" value="<?= $page ?>">
        <button type="submit" class="btn btn-sm btn-secondary">Go</button>
      </form>
    </div>
    <div class="ps-page-right">
      <span>Current page: <strong><?= $page ?></strong> / <?= $totalPages ?></span>
      <span>Showing <strong id="showing-count"><?= count($rolls) ?></strong> rows on this page</span>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<form id="ps-batch-delete-form" method="POST" action="<?= BASE_URL ?>/modules/paper_stock/batch_delete.php" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="ids" id="ps-batch-delete-ids">
</form>

<script>
(function(){
  'use strict';

  var table = document.getElementById('ps-table');
  var selectAll = document.getElementById('ps-select-all');
  var selSummary = document.getElementById('ps-selected-summary');
  var topPrintLabelBtn = document.getElementById('top-print-label-btn');
  var showingCount = document.getElementById('showing-count');
  var recordBadge = document.getElementById('grid-record-badge');
  var isAllRowsMode = <?= $isAllRows ? 'true' : 'false' ?>;

  var qf = {
    search: document.getElementById('qf-search'),
    lot: document.getElementById('qf-lot'),
    roll: document.getElementById('qf-roll'),
    company: document.getElementById('qf-company'),
    type: document.getElementById('qf-type'),
    gsm: document.getElementById('qf-gsm'),
    status: document.getElementById('qf-status')
  };

  var colKeys = <?= json_encode(array_keys($allColumns)) ?>;
  var columnFilterSource = <?= json_encode($colFilterData, JSON_UNESCAPED_UNICODE) ?>;
  var quickFilterOptions = {
    company: <?= json_encode(array_map(function($row){ return $row['company']; }, $companies), JSON_UNESCAPED_UNICODE) ?>,
    type: <?= json_encode(array_map(function($row){ return $row['paper_type']; }, $paperTypes), JSON_UNESCAPED_UNICODE) ?>,
    gsm: <?= json_encode(array_map(function($row){ return (string)$row['gsm']; }, $gsmValues), JSON_UNESCAPED_UNICODE) ?>,
    status: <?= json_encode(array_values($statusValues), JSON_UNESCAPED_UNICODE) ?>
  };
  var activeColFilters = {};
  var draftColFilters = {};
  var activeCfpCol = null;
  var activeQuickPopup = null;

  function ensureAllRowsMode(){
    if (isAllRowsMode) return true;
    var u = new URL(window.location.href);
    u.searchParams.set('per_page', 'all');
    u.searchParams.set('page', '1');
    window.location.href = u.toString();
    return false;
  }

  function dataRows(){ return table.querySelectorAll('tbody tr.ps-data-row'); }
  function cbs(){ return table.querySelectorAll('tbody .ps-row-cb'); }
  function selectedIds(){ var out=[]; cbs().forEach(function(cb){ if(cb.checked) out.push(cb.value); }); return out; }

  function cellText(tr, col){
    var td = tr.querySelector('.ps-col-' + col);
    return td ? (td.textContent || '').replace(/\s+/g, ' ').trim() : '';
  }

  function quickMatches(tr){
    var search = (qf.search.value || '').toLowerCase().trim();
    var lot = (qf.lot.value || '').toLowerCase().trim();
    var roll = (qf.roll.value || '').toLowerCase().trim();
    var company = (qf.company.value || '').toLowerCase().trim();
    var type = (qf.type.value || '').toLowerCase().trim();
    var gsm = (qf.gsm.value || '').toLowerCase().trim();
    var status = (qf.status.value || '').toLowerCase().trim();

    var rowText = (tr.textContent || '').toLowerCase();
    if (search && rowText.indexOf(search) === -1) return false;

    if (lot && cellText(tr, 'lot_batch_no').toLowerCase().indexOf(lot) === -1) return false;
    if (roll && cellText(tr, 'roll_no').toLowerCase().indexOf(roll) === -1) return false;
    if (company && cellText(tr, 'company').toLowerCase() !== company) return false;
    if (type && cellText(tr, 'paper_type').toLowerCase() !== type) return false;
    if (gsm && cellText(tr, 'gsm').toLowerCase() !== gsm) return false;
    if (status && cellText(tr, 'status').toLowerCase().indexOf(status) === -1) return false;

    return true;
  }

  function columnMatches(tr){
    for (var col in activeColFilters) {
      var set = activeColFilters[col];
      var txt = cellText(tr, col).toLowerCase();
      var isBlank = txt === '' || txt === '-';
      var blankToken = '__blank__';
      if (isBlank) {
        if (!set.has(blankToken)) return false;
      } else if (!set.has(txt)) {
        return false;
      }
    }
    return true;
  }

  function applyAllFilters(){
    var visible = 0;
    dataRows().forEach(function(tr){
      var show = quickMatches(tr) && columnMatches(tr);
      tr.style.display = show ? '' : 'none';
      if (!show) {
        var cb = tr.querySelector('.ps-row-cb');
        if (cb) cb.checked = false;
        tr.classList.remove('ps-row-selected');
      } else {
        visible++;
      }
    });
    showingCount.textContent = visible;
    recordBadge.textContent = visible + ' visible';
    updateSelectionUI();
  }

  function updateSelectionUI(){
    var ids = selectedIds();
    var n = ids.length;
    topPrintLabelBtn.disabled = n === 0;

    var visCbs = [];
    cbs().forEach(function(cb){ if (cb.closest('tr').style.display !== 'none') visCbs.push(cb); });
    selectAll.checked = visCbs.length > 0 && visCbs.every(function(cb){ return cb.checked; });

    if (n === 0) {
      selSummary.className = 'ps-selected-summary no-print';
      document.getElementById('sel-count').textContent = '0';
      document.getElementById('sel-mtr').textContent = '0';
      document.getElementById('sel-sqm').textContent = '0';
      return;
    }

    var mtr=0, sqm=0;
    cbs().forEach(function(cb){
      var tr = cb.closest('tr');
      tr.classList.toggle('ps-row-selected', cb.checked);
      if (!cb.checked) return;
      mtr += parseFloat(tr.dataset.mtr || 0);
      sqm += parseFloat(tr.dataset.sqm || 0);
    });

    document.getElementById('sel-count').textContent = n;
    document.getElementById('sel-mtr').textContent = mtr.toLocaleString(undefined, {maximumFractionDigits:0});
    document.getElementById('sel-sqm').textContent = sqm.toLocaleString(undefined, {maximumFractionDigits:0});
    selSummary.className = 'ps-selected-summary no-print visible';
  }

  selectAll.addEventListener('change', function(){
    var checked = this.checked;
    cbs().forEach(function(cb){
      if (cb.closest('tr').style.display !== 'none') cb.checked = checked;
      cb.closest('tr').classList.toggle('ps-row-selected', cb.checked);
    });
    updateSelectionUI();
  });

  document.addEventListener('change', function(e){
    if (e.target.classList.contains('ps-row-cb')) {
      e.target.closest('tr').classList.toggle('ps-row-selected', e.target.checked);
      updateSelectionUI();
    }
  });

  Object.keys(qf).forEach(function(k){
    qf[k].addEventListener('input', function(){
      if (!ensureAllRowsMode()) return;
      applyAllFilters();
    });
    qf[k].addEventListener('change', function(){
      if (!ensureAllRowsMode()) return;
      applyAllFilters();
    });
  });

  window.resetQuickFilters = function(){
    Object.keys(qf).forEach(function(k){ qf[k].value = ''; });
    document.querySelectorAll('.ps-qf-picker-btn').forEach(function(btn){
      var label = btn.querySelector('span');
      var picker = btn.dataset.qfPicker;
      if (picker === 'company') label.textContent = 'All Companies';
      if (picker === 'type') label.textContent = 'All Types';
      if (picker === 'gsm') label.textContent = 'All GSM';
      if (picker === 'status') label.textContent = 'All Status';
      label.className = 'muted';
      btn.classList.remove('active');
    });
    applyAllFilters();
  };

  function renderQuickPopup(key){
    var popup = document.getElementById('qf-popup-' + key);
    var values = quickFilterOptions[key] || [];
    var current = qf[key].value || '';
    var html = '<div class="ps-qf-popup-head"><input type="text" class="ps-qf-popup-search" data-qf-popup-search="' + key + '" placeholder="Search..."></div>';
    html += '<div class="ps-qf-popup-list">';
    html += '<div class="ps-qf-popup-item' + (current === '' ? ' active' : '') + '" data-qf-value="" data-qf-key="' + key + '">All</div>';
    values.forEach(function(v){
      var value = String(v);
      html += '<div class="ps-qf-popup-item' + (current === value ? ' active' : '') + '" data-qf-value="' + value.replace(/"/g, '&quot;') + '" data-qf-key="' + key + '">' + value + '</div>';
    });
    html += '</div>';
    popup.innerHTML = html;
  }

  function updateQuickPickerLabel(key){
    var btn = document.querySelector('.ps-qf-picker-btn[data-qf-picker="' + key + '"]');
    if (!btn) return;
    var span = btn.querySelector('span');
    var val = qf[key].value || '';
    if (!val) {
      if (key === 'company') span.textContent = 'All Companies';
      if (key === 'type') span.textContent = 'All Types';
      if (key === 'gsm') span.textContent = 'All GSM';
      if (key === 'status') span.textContent = 'All Status';
      span.className = 'muted';
      btn.classList.remove('active');
      return;
    }
    span.textContent = val;
    span.className = '';
    btn.classList.add('active');
  }

  function closeQuickPopup(){
    if (!activeQuickPopup) return;
    var popup = document.getElementById('qf-popup-' + activeQuickPopup);
    if (popup) popup.classList.remove('open');
    activeQuickPopup = null;
  }

  function buildUniqueValues(col){
    var src = columnFilterSource[col] || { values: [], hasBlank: false };
    var vals = (src.values || []).map(function(v){ return String(v); }).sort(function(a,b){
      var na = parseFloat(a), nb = parseFloat(b);
      if (!isNaN(na) && !isNaN(nb)) return na - nb;
      return a.localeCompare(b);
    });
    return {vals: vals, hasBlank: !!src.hasBlank};
  }

  function renderCfp(col){
    var pop = document.getElementById('cfp-' + col);
    var data = buildUniqueValues(col);
    var active = activeColFilters[col];
    var draft = new Set(active ? Array.from(active) : []);
    if (!active) {
      data.vals.forEach(function(v){ draft.add(v.toLowerCase()); });
      if (data.hasBlank) draft.add('__blank__');
    }
    draftColFilters[col] = draft;

    var html = '<div class="cfp-head">';
    html += '<input type="text" class="cfp-search" data-col="' + col + '" placeholder="Search values...">';
    html += '<label class="cfp-select-all"><input type="checkbox" data-col="' + col + '"> <span>Select All</span></label>';
    html += '</div>';
    html += '<div class="cfp-list">';
    html += '<label class="cfp-item" data-val="__blank__"><input type="checkbox" value="__blank__"> <span>Blanks</span></label>';
    data.vals.forEach(function(v){
      html += '<label class="cfp-item" data-val="' + v.toLowerCase().replace(/"/g,'&quot;') + '"><input type="checkbox" value="' + v.replace(/"/g,'&quot;') + '"> <span>' + v + '</span></label>';
    });
    html += '</div>';
    html += '<div class="cfp-foot">';
    html += '<button type="button" class="ok" data-col="' + col + '">OK</button>';
    html += '<button type="button" class="apply" data-col="' + col + '">Apply</button>';
    html += '</div>';
    pop.innerHTML = html;

    pop.querySelectorAll('.cfp-list input[type=checkbox]').forEach(function(cb){
      var token = cb.value === '__blank__' ? '__blank__' : cb.value.toLowerCase();
      cb.checked = draft.has(token);
    });

    syncSelectAll(col);
  }

  function syncSelectAll(col){
    var pop = document.getElementById('cfp-' + col);
    var all = pop.querySelectorAll('.cfp-list input[type=checkbox]');
    var checked = pop.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    var sa = pop.querySelector('.cfp-select-all input');
    if (sa) sa.checked = all.length > 0 && checked.length === all.length;
  }

  function commitDraft(col){
    var pop = document.getElementById('cfp-' + col);
    var selected = pop.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    var total = pop.querySelectorAll('.cfp-list input[type=checkbox]').length;

    if (selected.length === total || selected.length === 0) {
      delete activeColFilters[col];
    } else {
      var set = new Set();
      selected.forEach(function(cb){
        var token = cb.value === '__blank__' ? '__blank__' : cb.value.toLowerCase();
        set.add(token);
      });
      activeColFilters[col] = set;
    }

    updateFilterButton(col);
    closeCfp();
    applyAllFilters();
  }

  function updateFilterButton(col){
    var btn = document.querySelector('.col-filter-btn[data-filter-col="' + col + '"]');
    if (!btn) return;
    var count = btn.querySelector('.filter-count');
    if (activeColFilters[col]) {
      btn.classList.add('active');
      count.textContent = activeColFilters[col].size;
    } else {
      btn.classList.remove('active');
      count.textContent = '';
    }
  }

  function closeCfp(){
    if (!activeCfpCol) return;
    var pop = document.getElementById('cfp-' + activeCfpCol);
    if (pop) pop.classList.remove('open');
    activeCfpCol = null;
  }

  document.addEventListener('click', function(e){
    var qfBtn = e.target.closest('.ps-qf-picker-btn');
    if (qfBtn) {
      if (!ensureAllRowsMode()) return;
      var key = qfBtn.dataset.qfPicker;
      if (activeQuickPopup === key) { closeQuickPopup(); return; }
      closeQuickPopup();
      renderQuickPopup(key);
      var popup = document.getElementById('qf-popup-' + key);
      var btnRect = qfBtn.getBoundingClientRect();
      var left = Math.max(8, Math.min(btnRect.left, window.innerWidth - 280));
      popup.style.left = left + 'px';
      popup.style.top = (btnRect.bottom + 6) + 'px';
      popup.classList.add('open');
      activeQuickPopup = key;
      popup.dataset.qfKey = key;
      popup.querySelector('.ps-qf-popup-search').focus();
      return;
    }

    var qfItem = e.target.closest('.ps-qf-popup-item');
    if (qfItem) {
      var key = qfItem.dataset.qfKey;
      qf[key].value = qfItem.dataset.qfValue || '';
      updateQuickPickerLabel(key);
      closeQuickPopup();
      applyAllFilters();
      return;
    }

    var btn = e.target.closest('.col-filter-btn');
    if (btn) {
      if (!ensureAllRowsMode()) return;
      var col = btn.dataset.filterCol;
      if (activeCfpCol === col) { closeCfp(); return; }
      closeCfp();
      renderCfp(col);
      var pop = document.getElementById('cfp-' + col);
      var rect = btn.getBoundingClientRect();
      var popupW = 290;
      var left = Math.max(8, Math.min(rect.left, window.innerWidth - popupW - 8));
      var top = rect.bottom + 6;
      pop.style.left = left + 'px';
      pop.style.top = top + 'px';
      pop.classList.add('open');
      activeCfpCol = col;
      pop.querySelector('.cfp-search').focus();
      return;
    }

    if (activeCfpCol) {
      var popup = document.getElementById('cfp-' + activeCfpCol);
      if (popup && !popup.contains(e.target)) closeCfp();
    }

    if (activeQuickPopup) {
      var qPopup = document.getElementById('qf-popup-' + activeQuickPopup);
      if (qPopup && !qPopup.contains(e.target)) closeQuickPopup();
    }

    var cp = document.getElementById('col-panel'), cb = document.getElementById('col-toggle-btn');
    if (cp.classList.contains('open') && !cp.contains(e.target) && !cb.contains(e.target)) cp.classList.remove('open');

    var link = e.target.closest('[data-confirm]');
    if (link && !confirm(link.dataset.confirm)) e.preventDefault();
  });

  document.addEventListener('scroll', function(){
    if (activeQuickPopup) {
      var popup = document.getElementById('qf-popup-' + activeQuickPopup);
      if (popup && popup.classList.contains('open')) {
        var btn = document.querySelector('[data-qf-picker="' + activeQuickPopup + '"]');
        if (btn) {
          var rect = btn.getBoundingClientRect();
          var left = Math.max(8, Math.min(rect.left, window.innerWidth - 280));
          popup.style.left = left + 'px';
          popup.style.top = (rect.bottom + 6) + 'px';
        }
      }
    }
  }, true);

  document.addEventListener('change', function(e){
    if (!activeCfpCol) return;
    var popup = document.getElementById('cfp-' + activeCfpCol);
    if (!popup || !popup.contains(e.target)) return;

    if (e.target.closest('.cfp-select-all')) {
      popup.querySelectorAll('.cfp-list input[type=checkbox]').forEach(function(cb){ cb.checked = e.target.checked; });
    }
    syncSelectAll(activeCfpCol);
  });

  document.addEventListener('input', function(e){
    if (e.target.classList.contains('ps-qf-popup-search')) {
      var key = e.target.dataset.qfPopupSearch;
      var q = e.target.value.toLowerCase();
      document.querySelectorAll('#qf-popup-' + key + ' .ps-qf-popup-item').forEach(function(item){
        var text = (item.textContent || '').toLowerCase();
        item.style.display = text.indexOf(q) >= 0 ? '' : 'none';
      });
      return;
    }

    if (!e.target.classList.contains('cfp-search')) return;
    var col = e.target.dataset.col;
    var q = e.target.value.toLowerCase();
    document.querySelectorAll('#cfp-' + col + ' .cfp-item').forEach(function(item){
      var v = item.dataset.val || '';
      item.style.display = v.indexOf(q) >= 0 ? '' : 'none';
    });
  });

  document.addEventListener('click', function(e){
    if (e.target.classList.contains('ok') || e.target.classList.contains('apply')) {
      commitDraft(e.target.dataset.col);
    }
  });

  window.psBulkExport = function(format, mode){
    var p = new URLSearchParams();
    p.set('format', format);
    p.set('mode', mode);

    if (mode === 'selected') {
      var ids = selectedIds();
      if (!ids.length) { alert('Select at least one roll.'); return; }
      p.set('ids', ids.join(','));
    }

    var visibleCols = [];
    colKeys.forEach(function(col){
      var th = document.querySelector('th.ps-col-' + col);
      if (th && th.style.display !== 'none') visibleCols.push(col);
    });
    p.set('visible_cols', visibleCols.join(','));

    var url = '<?= BASE_URL ?>/modules/paper_stock/export.php?' + p.toString();
    if (format === 'pdf') window.open(url, '_blank'); else window.location.href = url;
  };

  window.psPrintLabels = function(){
    var ids = selectedIds();
    if (!ids.length) { return; }
    window.open('<?= BASE_URL ?>/modules/paper_stock/export.php?format=pdf&mode=selected&ids=' + ids.join(','), '_blank');
  };

  window.psPrintSingleLabel = function(id){
    window.open('<?= BASE_URL ?>/modules/paper_stock/export.php?format=pdf&mode=selected&ids=' + id, '_blank');
  };

  window.psBulkDelete = function(){
    var ids = selectedIds();
    if (!ids.length) { alert('Select at least one roll.'); return; }
    if (!confirm('Delete ' + ids.length + ' roll(s)? This cannot be undone.')) return;
    document.getElementById('ps-batch-delete-ids').value = ids.join(',');
    document.getElementById('ps-batch-delete-form').submit();
  };

  window.toggleColPanel = function(){ document.getElementById('col-panel').classList.toggle('open'); };

  function applyColumnVisibility(col, show){
    document.querySelectorAll('.ps-col-' + col).forEach(function(el){ el.style.display = show ? '' : 'none'; });
  }

  window.setAllColumns = function(show){
    document.querySelectorAll('.col-toggle-cb').forEach(function(cb){
      cb.checked = show;
      applyColumnVisibility(cb.dataset.col, show);
    });
    if (show) localStorage.removeItem('ps_col_prefs');
    else {
      var prefs = {};
      document.querySelectorAll('.col-toggle-cb').forEach(function(cb){ prefs[cb.dataset.col] = cb.checked; });
      localStorage.setItem('ps_col_prefs', JSON.stringify(prefs));
    }
  };

  window.resetColumns = function(){
    setAllColumns(true);
  };

  document.querySelectorAll('.col-toggle-cb').forEach(function(cb){
    cb.addEventListener('change', function(){
      applyColumnVisibility(this.dataset.col, this.checked);
      var prefs = JSON.parse(localStorage.getItem('ps_col_prefs') || '{}');
      prefs[this.dataset.col] = this.checked;
      localStorage.setItem('ps_col_prefs', JSON.stringify(prefs));
    });
  });

  (function restoreCols(){
    var prefs = JSON.parse(localStorage.getItem('ps_col_prefs') || '{}');
    for (var col in prefs) {
      var cb = document.querySelector('.col-toggle-cb[data-col="' + col + '"]');
      if (cb) {
        cb.checked = !!prefs[col];
        applyColumnVisibility(col, !!prefs[col]);
      }
    }
  })();

  window.psPrintStockReport = function(){
    var visibleCols = [];
    colKeys.forEach(function(col){
      var th = document.querySelector('th.ps-col-' + col);
      if (th && th.style.display !== 'none') visibleCols.push(col);
    });

    var rows = [];
    dataRows().forEach(function(tr){
      if (tr.style.display === 'none') return;
      var row = [];
      row.push(tr.querySelector('.sticky-sl').textContent.trim());
      visibleCols.forEach(function(col){ row.push(cellText(tr, col)); });
      rows.push(row);
    });

    var labels = ['SL No.'];
    visibleCols.forEach(function(col){
      var th = document.querySelector('th.ps-col-' + col + ' a');
      labels.push((th ? th.textContent : col).replace(/\s+/g, ' ').trim());
    });

    var w = window.open('', '_blank');
    if (!w) return;

    var tableHtml = '<table><thead><tr>';
    labels.forEach(function(h){ tableHtml += '<th>' + h + '</th>'; });
    tableHtml += '</tr></thead><tbody>';
    rows.forEach(function(r){
      tableHtml += '<tr>';
      r.forEach(function(c){ tableHtml += '<td>' + (c || '-') + '</td>'; });
      tableHtml += '</tr>';
    });
    tableHtml += '</tbody></table>';

    w.document.write('<!doctype html><html><head><title>Stock Report</title><style>' +
      '@page{size:A4;margin:10mm;}*{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:10px;color:#111827}' +
      '.head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #111827;padding-bottom:8px;margin-bottom:10px}' +
      '.head h2{margin:0;font-size:16px}table{width:100%;border-collapse:collapse;font-size:9px}th{background:#0f172a;color:#fff;padding:6px;text-align:left}td{padding:5px;border-bottom:1px solid #e5e7eb}tr:nth-child(even){background:#f8fafc}' +
      '</style></head><body>' +
      '<div class="head"><div><h2>Paper Stock Report</h2><div>Visible columns only</div></div><div>Generated: <?= date('d M Y, h:i A') ?></div></div>' +
      tableHtml +
      '<script>window.onload=function(){window.print();};<\/script>' +
      '</body></html>');
    w.document.close();
  };

  ['company','type','gsm','status'].forEach(updateQuickPickerLabel);

  applyAllFilters();
})();
</script>
