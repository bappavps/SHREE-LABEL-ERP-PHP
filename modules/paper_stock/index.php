<?php
// ============================================================
// Shree Label ERP — Paper Stock: List
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

// ── Filters ──────────────────────────────────────────────────
$fSearch     = trim($_GET['search']       ?? '');
$fRollNo     = trim($_GET['roll_no']      ?? '');
$fLotNo      = trim($_GET['lot_no']       ?? '');
$fStatus     = trim($_GET['status']       ?? '');
$fCompany    = trim($_GET['company']      ?? '');
$fType       = trim($_GET['type']         ?? '');
$fGsm        = trim($_GET['gsm']          ?? '');
$fDateFrom   = trim($_GET['date_from']    ?? '');
$fDateTo     = trim($_GET['date_to']      ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($fSearch !== '') {
    $like    = '%' . $fSearch . '%';
    $where[] = '(roll_no LIKE ? OR company LIKE ? OR paper_type LIKE ? OR lot_batch_no LIKE ? OR job_no LIKE ? OR job_name LIKE ? OR remarks LIKE ?)';
    $params  = array_merge($params, [$like,$like,$like,$like,$like,$like,$like]);
    $types  .= 'sssssss';
}
if ($fRollNo !== '') {
    $like    = '%' . $fRollNo . '%';
    $where[] = 'roll_no LIKE ?'; $params[] = $like; $types .= 's';
}
if ($fLotNo !== '') {
    $like    = '%' . $fLotNo . '%';
    $where[] = 'lot_batch_no LIKE ?'; $params[] = $like; $types .= 's';
}
if ($fStatus !== '') {
    $where[] = 'status = ?';      $params[] = $fStatus;  $types .= 's';
}
if ($fCompany !== '') {
    $where[] = 'company = ?';     $params[] = $fCompany; $types .= 's';
}
if ($fType !== '') {
    $where[] = 'paper_type = ?';  $params[] = $fType;    $types .= 's';
}
if ($fGsm !== '') {
    $where[] = 'gsm = ?';         $params[] = $fGsm;     $types .= 'd';
}
if ($fDateFrom !== '') {
    $where[] = 'date_received >= ?'; $params[] = $fDateFrom; $types .= 's';
}
if ($fDateTo !== '') {
    $where[] = 'date_received <= ?'; $params[] = $fDateTo;   $types .= 's';
}

$whereSQL = implode(' AND ', $where);

// Pagination & rows per page
$allowedPerPage = [10, 20, 50, 100];
$perPage = in_array((int)($_GET['per_page'] ?? 0), $allowedPerPage) ? (int)$_GET['per_page'] : 20;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$totalRes = $db->prepare("SELECT COUNT(*) AS c FROM paper_stock WHERE {$whereSQL}");
if ($types) $totalRes->bind_param($types, ...$params);
$totalRes->execute();
$total = (int)$totalRes->get_result()->fetch_assoc()['c'];

$stmt = $db->prepare("SELECT * FROM paper_stock WHERE {$whereSQL} ORDER BY id DESC LIMIT ? OFFSET ?");
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes  = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Dropdown values
$companies  = $db->query("SELECT DISTINCT company FROM paper_stock WHERE company IS NOT NULL AND company <> '' ORDER BY company");
$types_res  = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE paper_type IS NOT NULL AND paper_type <> '' ORDER BY paper_type");
$gsms_res   = $db->query("SELECT DISTINCT gsm FROM paper_stock WHERE gsm IS NOT NULL ORDER BY gsm");

// Summary: group by company + paper_type (mirrors Firebase summary cards)
$sumRes = $db->query("SELECT company, paper_type,
    COUNT(*) AS total_rolls,
    IFNULL(SUM(length_mtr),0) AS total_mtr,
    IFNULL(SUM((width_mm/1000)*length_mtr),0) AS total_sqm
    FROM paper_stock
    WHERE {$whereSQL}
    GROUP BY company, paper_type
    ORDER BY total_sqm DESC
    LIMIT 12");
// Re-fetch with same params since prepared stmt already consumed
$sumStmt = $db->prepare("SELECT company, paper_type,
    COUNT(*) AS total_rolls,
    IFNULL(SUM(length_mtr),0) AS total_mtr,
    IFNULL(SUM((width_mm/1000)*length_mtr),0) AS total_sqm
    FROM paper_stock
    WHERE {$whereSQL}
    GROUP BY company, paper_type
    ORDER BY total_sqm DESC
    LIMIT 12");
if ($types) $sumStmt->bind_param($types, ...$params);
$sumStmt->execute();
$summaryGroups = $sumStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Totals
$totStmt = $db->prepare("SELECT IFNULL(SUM(length_mtr),0) AS total_mtr, IFNULL(SUM((width_mm/1000)*length_mtr),0) AS total_sqm FROM paper_stock WHERE {$whereSQL}");
if ($types) $totStmt->bind_param($types, ...$params);
$totStmt->execute();
$totals = $totStmt->get_result()->fetch_assoc();

// Status counts (for header)
$statusMap = ['Main'=>'main','Stock'=>'stock','Slitting'=>'slitting','Job Assign'=>'job-assign','In Production'=>'in-production','Consumed'=>'consumed'];

$pageTitle = 'Paper Stock';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Paper Stock</span>
</div>

<div class="page-header">
  <div>
    <h1>Paper Stock Details</h1>
    <p>Master inventory of all parent and child paper rolls.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/modules/paper_stock/add.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> Add Roll
    </a>
  </div>
</div>

<!-- Technical Inventory Summary Cards -->
<div style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding:0 2px">
    <span style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.15em">
      <i class="bi bi-grid" style="color:var(--brand)"></i>&nbsp; Technical Inventory Summary
    </span>
    <div style="display:flex;gap:20px;align-items:center">
      <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase">
        Total MTR: <strong style="color:var(--brand);margin-left:4px"><?= number_format($totals['total_mtr'], 0) ?></strong>
      </span>
      <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase">
        Total SQM: <strong style="color:var(--brand);margin-left:4px"><?= number_format($totals['total_sqm'], 0) ?></strong>
      </span>
      <span class="badge badge-draft"><?= count($summaryGroups) ?> Technical Groups</span>
    </div>
  </div>
  <?php if (!empty($summaryGroups)): ?>
  <div class="stat-cards" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));max-height:280px;overflow-y:auto">
    <?php foreach ($summaryGroups as $g): ?>
    <div class="stat-card" style="flex-direction:column;align-items:flex-start;gap:0;padding:14px">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;width:100%;margin-bottom:8px">
        <div style="flex:1;min-width:0">
          <div style="font-size:9px;font-weight:800;color:var(--brand);text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($g['company']) ?>"><?= e($g['company'] ?: 'Unknown') ?></div>
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($g['paper_type']) ?>"><?= e($g['paper_type'] ?: 'Other') ?></div>
        </div>
        <span class="badge badge-consumed" style="font-size:9px;margin-left:6px;flex-shrink:0"><?= $g['total_rolls'] ?> ROLLS</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;border-top:1px solid #f1f5f9;padding-top:8px">
        <div>
          <div style="font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase">Length</div>
          <div style="font-size:11px;font-weight:800"><?= number_format($g['total_mtr'], 0) ?><span style="font-size:8px;opacity:.4;margin-left:2px">MTR</span></div>
        </div>
        <div style="text-align:right">
          <div style="font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase">Surface</div>
          <div style="font-size:11px;font-weight:800;color:#16a34a"><?= number_format($g['total_sqm'], 0) ?><span style="font-size:8px;opacity:.4;margin-left:2px">SQM</span></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar mb-16">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="search" placeholder="Roll · Company · Job Name…" value="<?= e($fSearch) ?>">
  </div>
  <div class="filter-group">
    <label>Roll No</label>
    <input type="text" name="roll_no" placeholder="T-2026-1001" value="<?= e($fRollNo) ?>">
  </div>
  <div class="filter-group">
    <label>Lot / Batch No</label>
    <input type="text" name="lot_no" placeholder="LOT-A12" value="<?= e($fLotNo) ?>">
  </div>
  <div class="filter-group">
    <label>Company</label>
    <select name="company">
      <option value="">All Companies</option>
      <?php while ($c = $companies->fetch_assoc()): ?>
      <option value="<?= e($c['company']) ?>" <?= $fCompany === $c['company'] ? 'selected' : '' ?>><?= e($c['company']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Paper Type</label>
    <select name="type">
      <option value="">All Types</option>
      <?php while ($t = $types_res->fetch_assoc()): ?>
      <option value="<?= e($t['paper_type']) ?>" <?= $fType === $t['paper_type'] ? 'selected' : '' ?>><?= e($t['paper_type']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>GSM</label>
    <select name="gsm">
      <option value="">All GSM</option>
      <?php while ($g = $gsms_res->fetch_assoc()): ?>
      <option value="<?= e($g['gsm']) ?>" <?= $fGsm == $g['gsm'] ? 'selected' : '' ?>><?= e($g['gsm']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Status</label>
    <select name="status">
      <option value="">All Status</option>
      <?php foreach (array_keys($statusMap) as $s): ?>
      <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Received From</label>
    <input type="date" name="date_from" value="<?= e($fDateFrom) ?>">
  </div>
  <div class="filter-group">
    <label>Received To</label>
    <input type="date" name="date_to" value="<?= e($fDateTo) ?>">
  </div>
  <div class="filter-group" style="justify-content:flex-end">
    <label>&nbsp;</label>
    <div class="d-flex gap-8">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
      <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost btn-sm"><i class="bi bi-x-circle"></i> Reset</a>
    </div>
  </div>
</form>

<!-- Rows per page + count -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;padding:0 2px">
  <form method="GET" style="display:flex;align-items:center;gap:8px">
    <?php foreach (['search'=>$fSearch,'roll_no'=>$fRollNo,'lot_no'=>$fLotNo,'company'=>$fCompany,'type'=>$fType,'gsm'=>$fGsm,'status'=>$fStatus,'date_from'=>$fDateFrom,'date_to'=>$fDateTo] as $k=>$v): ?>
      <input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
    <label style="font-size:12px;font-weight:600;color:#64748b">Rows per page:</label>
    <select name="per_page" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:600;background:#fff">
      <?php foreach ($allowedPerPage as $rp): ?>
      <option value="<?= $rp ?>" <?= $perPage === $rp ? 'selected' : '' ?>><?= $rp ?> rows</option>
      <?php endforeach; ?>
    </select>
  </form>
  <span style="font-size:12px;font-weight:600;color:#94a3b8">
    Showing <?= $total === 0 ? 0 : $offset+1 ?>–<?= min($total, $offset+$perPage) ?> of <?= $total ?> rolls |
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" style="color:var(--brand)">Reset</a>
  </span>
</div>

<!-- Bulk Action Bar -->
<div id="bulk-action-bar" style="display:none;position:sticky;top:0;z-index:50;margin-bottom:12px;padding:12px 18px;background:#0f172a;border-radius:14px;color:#fff;display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 14px 30px rgba(15,23,42,.25)">
  <div style="display:flex;align-items:center;gap:10px">
    <span style="font-size:13px;font-weight:700"><i class="bi bi-check2-square" style="color:#22c55e;margin-right:4px"></i> <span id="bulk-count">0</span> selected</span>
    <button type="button" onclick="psBulkDeselectAll()" style="background:none;border:none;color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;text-decoration:underline">Deselect All</button>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button type="button" onclick="psBulkExport('pdf')" class="btn btn-sm" style="background:#ef4444;color:#fff;border:none;font-weight:700;border-radius:8px;padding:6px 14px;font-size:11px;cursor:pointer">
      <i class="bi bi-file-earmark-pdf"></i> Export PDF
    </button>
    <button type="button" onclick="psBulkExport('csv')" class="btn btn-sm" style="background:#22c55e;color:#fff;border:none;font-weight:700;border-radius:8px;padding:6px 14px;font-size:11px;cursor:pointer">
      <i class="bi bi-file-earmark-excel"></i> Export Excel
    </button>
    <button type="button" onclick="psBulkDelete()" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;font-weight:700;border-radius:8px;padding:6px 14px;font-size:11px;cursor:pointer">
      <i class="bi bi-trash3"></i> Delete Selected
    </button>
  </div>
</div>

<!-- Export All Bar -->
<div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:8px">
  <button type="button" onclick="psBulkExport('pdf','all')" class="btn btn-ghost btn-sm" style="font-size:11px">
    <i class="bi bi-file-earmark-pdf" style="color:#ef4444"></i> Export All PDF
  </button>
  <button type="button" onclick="psBulkExport('csv','all')" class="btn btn-ghost btn-sm" style="font-size:11px">
    <i class="bi bi-file-earmark-excel" style="color:#22c55e"></i> Export All Excel
  </button>
</div>

<!-- Master Grid -->
<div class="card" style="overflow:hidden;border-radius:16px">
  <div style="background:#0f172a;color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:10px">
      <i class="bi bi-grid" style="color:var(--brand)"></i> Master Grid
      <span class="badge badge-draft" style="margin-left:4px"><?= $total ?> records</span>
    </span>
    <a href="<?= BASE_URL ?>/modules/paper_stock/add.php" class="btn btn-primary btn-sm" style="font-size:11px">
      <i class="bi bi-plus-circle"></i> Add Roll
    </a>
  </div>
  <div style="overflow-x:auto;max-height:680px;overflow-y:auto">
    <table style="min-width:2400px">
      <thead style="position:sticky;top:0;z-index:10">
        <tr style="background:#f8fafc">
          <th style="width:38px;text-align:center"><input type="checkbox" id="ps-select-all" title="Select all on this page" style="cursor:pointer;width:16px;height:16px"></th>
          <th style="width:44px;text-align:center">#</th>
          <th style="min-width:130px">Roll No</th>
          <th style="min-width:110px">Status</th>
          <th style="min-width:130px">Paper Company</th>
          <th style="min-width:130px">Paper Type</th>
          <th style="min-width:90px">Width (MM)</th>
          <th style="min-width:100px">Length (MTR)</th>
          <th style="min-width:90px">SQM</th>
          <th style="min-width:70px">GSM</th>
          <th style="min-width:100px">Weight (KG)</th>
          <th style="min-width:120px">Purchase Rate</th>
          <th style="min-width:110px">Date Received</th>
          <th style="min-width:100px">Date Used</th>
          <th style="min-width:100px">Job No</th>
          <th style="min-width:100px">Job Size</th>
          <th style="min-width:140px">Job Name</th>
          <th style="min-width:120px">Lot / Batch No</th>
          <th style="min-width:130px">Company Roll No</th>
          <th style="min-width:150px">Remarks</th>
          <th style="min-width:140px;text-align:center;position:sticky;right:0;background:#f8fafc">Actions</th>
        </tr>
      </thead>
      <tbody data-table-body>
        <?php if (empty($rolls)): ?>
        <tr><td colspan="21" class="table-empty">
          <i class="bi bi-inbox"></i> No paper rolls found.
          <a href="<?= BASE_URL ?>/modules/paper_stock/add.php">Add one</a>
        </td></tr>
        <?php else: ?>
        <?php foreach ($rolls as $i => $r): ?>
        <tr data-roll-id="<?= $r['id'] ?>">
          <td style="text-align:center"><input type="checkbox" class="ps-row-cb" value="<?= $r['id'] ?>" style="cursor:pointer;width:16px;height:16px"></td>
          <td class="text-muted" style="text-align:center"><?= $offset + $i + 1 ?></td>
          <td style="font-weight:700;color:var(--brand);font-family:monospace;font-size:13px"><?= e($r['roll_no']) ?></td>
          <td><?= statusBadge($r['status']) ?></td>
          <td><?= e($r['company']) ?></td>
          <td><?= e($r['paper_type']) ?></td>
          <td style="font-family:monospace;text-align:right"><?= e($r['width_mm']) ?></td>
          <td style="font-family:monospace;font-weight:600;text-align:right"><?= number_format((float)$r['length_mtr'], 0) ?></td>
          <td style="font-family:monospace;font-weight:700;color:#16a34a;text-align:right"><?= number_format(calcSQM($r['width_mm'], $r['length_mtr']), 2) ?></td>
          <td style="font-family:monospace;text-align:right"><?= e($r['gsm'] ?? '-') ?></td>
          <td style="font-family:monospace;text-align:right"><?= $r['weight_kg'] ? e($r['weight_kg']) : '-' ?></td>
          <td style="font-family:monospace;text-align:right"><?= $r['purchase_rate'] ? '₹'.number_format((float)$r['purchase_rate'],2) : '-' ?></td>
          <td class="text-muted"><?= formatDate($r['date_received']) ?></td>
          <td class="text-muted"><?= formatDate($r['date_used']) ?></td>
          <td style="font-family:monospace;font-weight:600"><?= e($r['job_no'] ?? '-') ?></td>
          <td><?= e($r['job_size'] ?? '-') ?></td>
          <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['job_name'] ?? '') ?>"><?= e($r['job_name'] ?? '-') ?></td>
          <td style="font-family:monospace"><?= e($r['lot_batch_no'] ?? '-') ?></td>
          <td><?= e($r['company_roll_no'] ?? '-') ?></td>
          <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-style:italic;color:#64748b" title="<?= e($r['remarks'] ?? '') ?>"><?= e($r['remarks'] ?? '-') ?></td>
          <td style="text-align:center;position:sticky;right:0;background:#fff">
            <div class="row-actions" style="justify-content:center">
              <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-blue btn-sm" title="View"><i class="bi bi-eye"></i></a>
              <a href="edit.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
              <a href="delete.php?id=<?= $r['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-danger btn-sm" title="Delete"
                 data-confirm="Delete roll <?= e($r['roll_no']) ?>? This cannot be undone.">
                <i class="bi bi-trash"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
  $qStr = http_build_query([
    'search'=>$fSearch,'roll_no'=>$fRollNo,'lot_no'=>$fLotNo,'company'=>$fCompany,
    'type'=>$fType,'gsm'=>$fGsm,'status'=>$fStatus,'date_from'=>$fDateFrom,'date_to'=>$fDateTo,
    'per_page'=>$perPage,'page'=>'{page}'
  ]);
  echo paginationBar($total, $perPage, $page, BASE_URL . '/modules/paper_stock/index.php?' . $qStr);
  ?>
  <div style="padding:8px 14px"></div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<!-- Batch Delete Form (hidden) -->
<form id="ps-batch-delete-form" method="POST" action="<?= BASE_URL ?>/modules/paper_stock/batch_delete.php" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
  <input type="hidden" name="ids" id="ps-batch-delete-ids">
</form>

<script>
(function() {
  var selectAll = document.getElementById('ps-select-all');
  var bulkBar = document.getElementById('bulk-action-bar');
  var bulkCount = document.getElementById('bulk-count');
  var filterParams = <?= json_encode([
    'search'    => $fSearch,
    'roll_no'   => $fRollNo,
    'lot_no'    => $fLotNo,
    'status'    => $fStatus,
    'company'   => $fCompany,
    'type'      => $fType,
    'gsm'       => $fGsm,
    'date_from' => $fDateFrom,
    'date_to'   => $fDateTo,
  ]) ?>;

  function getCheckboxes() {
    return document.querySelectorAll('.ps-row-cb');
  }

  function getSelectedIds() {
    var ids = [];
    getCheckboxes().forEach(function(cb) {
      if (cb.checked) ids.push(cb.value);
    });
    return ids;
  }

  function updateBulkBar() {
    var ids = getSelectedIds();
    if (ids.length > 0) {
      bulkBar.style.display = 'flex';
      bulkCount.textContent = ids.length;
    } else {
      bulkBar.style.display = 'none';
    }
    selectAll.checked = ids.length > 0 && ids.length === getCheckboxes().length;
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      var checked = this.checked;
      getCheckboxes().forEach(function(cb) { cb.checked = checked; });
      updateBulkBar();
    });
  }

  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('ps-row-cb')) updateBulkBar();
  });

  window.psBulkDeselectAll = function() {
    getCheckboxes().forEach(function(cb) { cb.checked = false; });
    selectAll.checked = false;
    updateBulkBar();
  };

  window.psBulkExport = function(format, mode) {
    var params = new URLSearchParams();
    params.set('format', format);
    if (mode === 'all') {
      params.set('mode', 'all');
      for (var k in filterParams) {
        if (filterParams[k]) params.set(k, filterParams[k]);
      }
    } else {
      var ids = getSelectedIds();
      if (ids.length === 0) { alert('Please select at least one roll.'); return; }
      params.set('mode', 'selected');
      params.set('ids', ids.join(','));
    }
    var url = '<?= BASE_URL ?>/modules/paper_stock/export.php?' + params.toString();
    if (format === 'pdf') {
      window.open(url, '_blank');
    } else {
      window.location.href = url;
    }
  };

  window.psBulkDelete = function() {
    var ids = getSelectedIds();
    if (ids.length === 0) { alert('Please select at least one roll.'); return; }
    if (!confirm('Delete ' + ids.length + ' roll(s)? This cannot be undone.')) return;
    document.getElementById('ps-batch-delete-ids').value = ids.join(',');
    document.getElementById('ps-batch-delete-form').submit();
  };
})();
</script>
