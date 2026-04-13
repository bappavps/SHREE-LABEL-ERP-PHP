<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Plate Data & Tools Master';

$tabItems = [
	['key' => 'plate_management_master', 'label' => 'Plate Management Master', 'icon' => 'grid'],
	['key' => 'flatbed_printing_die_master', 'label' => 'Flatbed Printing Die Master', 'icon' => 'layout-wtf'],
	['key' => 'rotary_printing_die_master', 'label' => 'Rotary Printing Die Master', 'icon' => 'gear-wide-connected'],
	['key' => 'barcode_die_master', 'label' => 'Barcode Die Master', 'icon' => 'upc-scan'],
	['key' => 'magnetic_printing_cylinder_master', 'label' => 'Magnetic Printing Cylinder Master', 'icon' => 'circle-square'],
	['key' => 'sheeter_printing_cylinder_master', 'label' => 'Sheeter Printing Cylinder Master', 'icon' => 'layers-half'],
	['key' => 'magnetic_barcode_cylinder_master', 'label' => 'Magnetic Barcode Cylinder Master', 'icon' => 'bullseye'],
	['key' => 'anilox_master', 'label' => 'Anilox Master', 'icon' => 'droplet-half'],
	['key' => 'paper_roll_concept', 'label' => 'Paper-Roll', 'icon' => 'journal-richtext'],
];

$tabKeys = array_map(function ($tab) {
	return (string)$tab['key'];
}, $tabItems);

$requestedTab = trim((string)($_GET['tab'] ?? 'plate_management_master'));
$activeTab = $requestedTab;
if ($requestedTab === '__none__') {
	$activeTab = '';
} elseif (!in_array($activeTab, $tabKeys, true)) {
	$activeTab = 'plate_management_master';
}

$tabBasePath = BASE_URL . '/modules/master/plate-data-tools-master.php';

$moduleTabKeys = [
	'plate_management_master',
	'flatbed_printing_die_master',
	'rotary_printing_die_master',
	'barcode_die_master',
	'anilox_master',
];

$dieManagementTabs = [
	'flatbed_printing_die_master',
	'rotary_printing_die_master',
	'barcode_die_master',
];
$printingDieTabs = [
	'flatbed_printing_die_master',
	'rotary_printing_die_master',
];
$barcodeDieTabs = [
	'barcode_die_master',
];
$activeModuleHtml = '';
if (in_array($activeTab, $moduleTabKeys, true)) {
	ob_start();

	if ($activeTab === 'plate_management_master') {
		$dieToolingEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=plate_management_master';
		$dieToolingPageTitleOverride = 'Plate Management Master';
		$dieToolingModuleLabelOverride = 'Plate Data';
		require __DIR__ . '/../plate-data/index.php';
	} elseif ($activeTab === 'flatbed_printing_die_master') {
		$dieToolingEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=flatbed_printing_die_master';
		$dieToolingPageTitleOverride = 'Flatbed Printing Die Master';
		$dieToolingEntityLabelOverride = 'Flatbed Printing Die';
		$dieToolingDieTypeScope = 'flatbed';
		$dieToolingDieTypeScopeLabel = 'Flatbed';
		require __DIR__ . '/../die-tooling/index.php';
	} elseif ($activeTab === 'rotary_printing_die_master') {
		$dieToolingEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=rotary_printing_die_master';
		$dieToolingPageTitleOverride = 'Rotary Die Master';
		$dieToolingEntityLabelOverride = 'Rotary Die';
		$dieToolingDieTypeScope = 'rotary';
		$dieToolingDieTypeScopeLabel = 'Rotary';
		require __DIR__ . '/../die-tooling/index.php';
	} elseif ($activeTab === 'barcode_die_master') {
		$barcodeDieWorkspaceEmbedded = true;
		$barcodeDieWorkspaceModeOverride = 'master';
		$barcodeDieWorkspaceBasePathOverride = $tabBasePath . '?tab=barcode_die_master&mode=master';
		require __DIR__ . '/../plate-tools/die-management/barcode/index.php';
	} elseif ($activeTab === 'anilox_master') {
		$aniloxDataEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=anilox_master';
		$aniloxDataLabelOverride = 'Anilox Stock';
		$aniloxDataPageTitleOverride = 'Anilox Master';
		require __DIR__ . '/../anilox-data/index.php';
	}

	$activeModuleHtml = ob_get_clean();
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.ptm-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.ptm-title{display:flex;align-items:center;gap:8px;font-size:1.1rem;font-weight:800;color:#0f172a}
.ptm-sub{margin-top:4px;font-size:.84rem;color:#64748b}
.ptm-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#ecfeff;color:#0e7490;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.ptm-tabs{display:flex;gap:8px;overflow:auto;padding:4px 2px 8px}
.ptm-tab{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:.76rem;font-weight:700;white-space:nowrap;cursor:pointer;transition:all .15s ease;text-decoration:none}
.ptm-tab i{font-size:.88rem;opacity:.75}
.ptm-tab:hover{border-color:#93c5fd;color:#1d4ed8;background:#eff6ff}
.ptm-tab.active{border-color:#1d4ed8;background:#dbeafe;color:#1e3a8a;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.ptm-tab-wrap{display:flex;flex-wrap:wrap;gap:8px}
.ptm-panel{display:none}
.ptm-panel.active{display:block;animation:ptmFade .18s ease}
.ptm-panel-card{border:1px solid var(--border);border-radius:12px;background:#fff;padding:12px}
.ptm-empty{padding:22px 10px;text-align:center;color:#94a3b8;font-size:.84rem}
.ptm-empty i{font-size:1.2rem;display:block;margin-bottom:7px;color:#cbd5e1}
.ptm-paper-wrap{border:1px solid #fbcfe8;border-radius:12px;background:linear-gradient(145deg,#fff1f7 0%,#fff7ed 56%,#fffbeb 100%);padding:12px}
.ptm-paper-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.ptm-paper-title{font-size:1rem;font-weight:800;color:#9d174d;display:flex;align-items:center;gap:8px}
.ptm-paper-sub{font-size:.78rem;color:#831843;margin-top:3px}
.ptm-paper-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#ffe4e6;color:#be123c;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.ptm-paper-tools{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.ptm-paper-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 11px;border-radius:9px;border:1px solid #f9a8d4;background:#fff;color:#9d174d;font-size:.74rem;font-weight:700;cursor:pointer;text-decoration:none}
.ptm-paper-btn:hover{background:#fff1f2;border-color:#fb7185}
.ptm-paper-btn-primary{background:#e11d48;color:#fff;border-color:#be123c}
.ptm-paper-btn-primary:hover{background:#be123c;border-color:#9f1239}
.ptm-paper-file{display:none}
.ptm-paper-form{display:none;margin-bottom:10px;padding:10px;border-radius:10px;background:#fff;border:1px solid #fbcfe8}
.ptm-paper-form-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:8px}
.ptm-paper-form label{display:block;font-size:.68rem;color:#9f1239;font-weight:700;margin-bottom:3px}
.ptm-paper-form input,.ptm-paper-form select{width:100%;height:32px;border:1px solid #f9a8d4;border-radius:8px;padding:0 8px;font-size:.74rem;color:#4a044e;background:#fff}
.ptm-paper-form-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
.ptm-paper-table-wrap{overflow:auto;border:1px solid #fecdd3;border-radius:10px;background:#fff}
.ptm-paper-table{width:100%;min-width:1150px;border-collapse:separate;border-spacing:0}
.ptm-paper-table th{position:sticky;top:0;background:linear-gradient(180deg,#ffe4e6 0%,#fecdd3 100%);color:#881337;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;font-weight:800;padding:9px 10px;border-bottom:1px solid #fda4af;white-space:nowrap}
.ptm-paper-table td{padding:8px 10px;border-bottom:1px solid #ffe4e6;font-size:.76rem;color:#4a044e;white-space:nowrap}
.ptm-paper-table tbody tr:nth-child(even) td{background:#fff7fb}
.ptm-paper-table tbody tr:hover td{background:#fff1f2}
.ptm-th-wrap{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ptm-filter-btn{border:1px solid #fda4af;background:#fff;color:#9d174d;border-radius:6px;padding:1px 4px;font-size:.68rem;cursor:pointer;line-height:1}
.ptm-filter-btn.active{background:#e11d48;color:#fff;border-color:#be123c}
.ptm-row-actions{display:flex;align-items:center;gap:6px}
.ptm-row-btn{border:1px solid #f9a8d4;background:#fff;color:#9d174d;border-radius:7px;padding:2px 6px;cursor:pointer}
.ptm-row-btn:hover{background:#ffe4e6}
@keyframes ptmFade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
@media (max-width:768px){.ptm-title{font-size:1rem}.ptm-sub{font-size:.76rem}.ptm-tab-wrap{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:6px;padding-bottom:4px;-webkit-overflow-scrolling:touch}.ptm-tab{height:34px;padding:0 10px;font-size:.72rem;flex:0 0 auto}.ptm-panel-card{padding:8px}}
@media (max-width:900px){.ptm-paper-form-grid{grid-template-columns:repeat(2,minmax(150px,1fr))}}
@media (max-width:768px){.ptm-paper-tools{gap:6px}.ptm-paper-btn{height:32px;padding:0 9px;font-size:.7rem}}
@media (max-width:360px){.ptm-title{font-size:.9rem}.ptm-sub{font-size:.7rem}.ptm-chip{font-size:.62rem;padding:5px 8px}.ptm-tab{height:32px;padding:0 8px;font-size:.68rem}.ptm-tab i{font-size:.8rem}.ptm-panel-card{padding:6px;border-radius:10px}}
</style>

<div class="card">
	<div class="card-header ptm-head">
		<div>
			<div class="ptm-title"><i class="bi bi-grid-3x3-gap"></i> Plate Data &amp; Tools Master</div>
			<div class="ptm-sub">Tabbed workspace. Plate Data page is loaded inside Plate Management Master tab.</div>
		</div>
		<span class="ptm-chip"><i class="bi bi-lightning-charge"></i> Single-Page Tab Workspace</span>
	</div>
	<div style="padding:12px 14px 6px;">
		<div class="ptm-tab-wrap" id="ptmTabs" role="tablist" aria-label="Plate Data and Tools Master Tabs">
			<?php foreach ($tabItems as $tab): ?>
				<?php $tabHref = ($activeTab === $tab['key']) ? ($tabBasePath . '?tab=__none__') : ($tabBasePath . '?tab=' . urlencode((string)$tab['key'])); ?>
				<a class="ptm-tab<?= $activeTab === $tab['key'] ? ' active' : '' ?>" href="<?= e($tabHref) ?>" role="tab" aria-selected="<?= $activeTab === $tab['key'] ? 'true' : 'false' ?>">
					<i class="bi bi-<?= e($tab['icon']) ?>"></i>
					<span><?= e($tab['label']) ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<?php foreach ($tabItems as $tab): ?>
	<section class="ptm-panel<?= $activeTab === $tab['key'] ? ' active' : '' ?>" data-ptm-panel="<?= e($tab['key']) ?>" role="tabpanel">
		<div class="ptm-panel-card">
			<?php if ($activeTab === $tab['key'] && in_array($tab['key'], $moduleTabKeys, true)): ?>
				<?= $activeModuleHtml ?>
			<?php elseif ($tab['key'] === 'paper_roll_concept'): ?>
				<div class="ptm-paper-wrap">
					<div class="ptm-paper-head">
						<div>
							<div class="ptm-paper-title"><i class="bi bi-journal-richtext"></i> Paper-Roll</div>
							<div class="ptm-paper-sub">Dedicated roll concept sheet with a separate color style from the other tabs.</div>
						</div>
						<span class="ptm-paper-chip"><i class="bi bi-palette2"></i> Rose Theme</span>
					</div>

					<div class="ptm-paper-tools">
						<button type="button" class="ptm-paper-btn ptm-paper-btn-primary" id="ptmPaperAddBtn"><i class="bi bi-plus-circle"></i> Add Row</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperPrintBtn"><i class="bi bi-printer"></i> Print</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperExportExcelBtn"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperImportBtn"><i class="bi bi-upload"></i> Bulk Excel Import</button>
						<input type="file" id="ptmPaperImportFile" class="ptm-paper-file" accept=".xlsx,.xls">
					</div>

					<form id="ptmPaperForm" class="ptm-paper-form">
						<div class="ptm-paper-form-grid">
							<div><label>Sl no.</label><input type="number" min="1" step="1" name="sl_no" required></div>
							<div><label>Item Name</label><input type="text" name="item_name" required></div>
							<div><label>Item</label><select name="item"><option value="All" selected>All</option><option value="POS">POS</option><option value="1Ply">1Ply</option><option value="2Ply">2Ply</option></select></div>
							<div><label>Width ( mm)</label><input type="text" name="width_mm"></div>
							<div><label>Lenght(mtr)</label><input type="text" name="length_mtr"></div>
							<div><label>Paper Type</label><select name="paper_type"><option value="Thermal">Thermal</option><option value="Maplito">Maplito</option><option value="Yellow Paper">Yellow Paper</option></select></div>
							<div><label>GSM</label><input type="text" name="gsm"></div>
							<div><label>Dia</label><input type="text" name="dia"></div>
							<div><label>Core</label><input type="text" name="core"></div>
							<div><label>Size</label><input type="text" name="size"></div>
							<div><label>Core Type</label><select name="core_type"><option value="All" selected>All</option><option value="Paper Core">Paper Core</option><option value="Plastic Core">Plastic Core</option></select></div>
						</div>
						<div class="ptm-paper-form-actions">
							<button type="button" class="ptm-paper-btn" id="ptmPaperFormCancel">Cancel</button>
							<button type="submit" class="ptm-paper-btn ptm-paper-btn-primary" id="ptmPaperFormSave">Save</button>
						</div>
					</form>

					<div class="ptm-paper-table-wrap">
						<table class="ptm-paper-table" id="ptmPaperTable">
							<thead>
								<tr>
									<th><div class="ptm-th-wrap"><span>Sl no.</span><button type="button" class="ptm-filter-btn" data-col="sl_no" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Item Name</span><button type="button" class="ptm-filter-btn" data-col="item_name" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Item</span><button type="button" class="ptm-filter-btn" data-col="item" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Width ( mm)</span><button type="button" class="ptm-filter-btn" data-col="width_mm" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Lenght(mtr)</span><button type="button" class="ptm-filter-btn" data-col="length_mtr" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Paper Type</span><button type="button" class="ptm-filter-btn" data-col="paper_type" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>GSM</span><button type="button" class="ptm-filter-btn" data-col="gsm" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Dia</span><button type="button" class="ptm-filter-btn" data-col="dia" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Core</span><button type="button" class="ptm-filter-btn" data-col="core" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Size</span><button type="button" class="ptm-filter-btn" data-col="size" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Core Type</span><button type="button" class="ptm-filter-btn" data-col="core_type" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody id="ptmPaperTbody"></tbody>
						</table>
					</div>
				</div>
			<?php else: ?>
				<div class="ptm-empty">
					<i class="bi bi-tools"></i>
					<div><?= e($tab['label']) ?> section ready for implementation.</div>
				</div>
			<?php endif; ?>
		</div>
	</section>
<?php endforeach; ?>

<?php if ($activeTab === 'paper_roll_concept'): ?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<script>
(function () {
	var storageKey = 'ptm_paper_roll_rows_v1';
	var rows = [];
	var filters = {};
	var editIndex = -1;

	var tableBody = document.getElementById('ptmPaperTbody');
	var form = document.getElementById('ptmPaperForm');
	var addBtn = document.getElementById('ptmPaperAddBtn');
	var printBtn = document.getElementById('ptmPaperPrintBtn');
	var cancelBtn = document.getElementById('ptmPaperFormCancel');
	var exportExcelBtn = document.getElementById('ptmPaperExportExcelBtn');
	var importBtn = document.getElementById('ptmPaperImportBtn');
	var importFile = document.getElementById('ptmPaperImportFile');
	if (!tableBody || !form || !addBtn || !cancelBtn || !importFile) return;

	var columns = [
		{ key: 'sl_no', label: 'Sl no.' },
		{ key: 'item_name', label: 'Item Name' },
		{ key: 'item', label: 'Item' },
		{ key: 'width_mm', label: 'Width ( mm)' },
		{ key: 'length_mtr', label: 'Lenght(mtr)' },
		{ key: 'paper_type', label: 'Paper Type' },
		{ key: 'gsm', label: 'GSM' },
		{ key: 'dia', label: 'Dia' },
		{ key: 'core', label: 'Core' },
		{ key: 'size', label: 'Size' },
		{ key: 'core_type', label: 'Core Type' }
	];

	function normalize(value) {
		return String(value == null ? '' : value).trim().toLowerCase();
	}

	function nextSlNo() {
		var max = 0;
		rows.forEach(function (r) {
			var v = parseInt(r.sl_no || 0, 10) || 0;
			if (v > max) max = v;
		});
		return max + 1;
	}

	function loadRows() {
		try {
			var raw = localStorage.getItem(storageKey);
			rows = raw ? JSON.parse(raw) : [];
			if (!Array.isArray(rows)) rows = [];
		} catch (e) {
			rows = [];
		}
	}

	function saveRows() {
		localStorage.setItem(storageKey, JSON.stringify(rows));
	}

	function filteredRows() {
		return rows.filter(function (row) {
			return columns.every(function (col) {
				var fv = normalize(filters[col.key] || '');
				if (!fv) return true;
				return normalize(row[col.key] || '').indexOf(fv) !== -1;
			});
		});
	}

	function render() {
		var visible = filteredRows();
		if (!visible.length) {
			tableBody.innerHTML = '<tr><td colspan="12" style="text-align:center;color:#9f1239">No data found</td></tr>';
		} else {
			tableBody.innerHTML = visible.map(function (row) {
				var idx = rows.indexOf(row);
				return '<tr>' +
					'<td>' + (row.sl_no || '') + '</td>' +
					'<td>' + (row.item_name || '') + '</td>' +
					'<td>' + (row.item || '') + '</td>' +
					'<td>' + (row.width_mm || '') + '</td>' +
					'<td>' + (row.length_mtr || '') + '</td>' +
					'<td>' + (row.paper_type || '') + '</td>' +
					'<td>' + (row.gsm || '') + '</td>' +
					'<td>' + (row.dia || '') + '</td>' +
					'<td>' + (row.core || '') + '</td>' +
					'<td>' + (row.size || '') + '</td>' +
					'<td>' + (row.core_type || '') + '</td>' +
					'<td><div class="ptm-row-actions">' +
						'<button type="button" class="ptm-row-btn" data-edit="' + idx + '" title="Edit"><i class="bi bi-pencil"></i></button>' +
						'<button type="button" class="ptm-row-btn" data-del="' + idx + '" title="Delete"><i class="bi bi-trash"></i></button>' +
					'</div></td>' +
				'</tr>';
			}).join('');
		}

		document.querySelectorAll('.ptm-filter-btn').forEach(function (btn) {
			var col = btn.getAttribute('data-col') || '';
			btn.classList.toggle('active', !!normalize(filters[col] || ''));
		});
	}

	function showForm(show) {
		form.style.display = show ? 'block' : 'none';
	}

	function clearForm() {
		form.reset();
		form.elements.sl_no.value = nextSlNo();
		editIndex = -1;
		document.getElementById('ptmPaperFormSave').textContent = 'Save';
	}

	function fillForm(row) {
		columns.forEach(function (col) {
			if (form.elements[col.key]) form.elements[col.key].value = row[col.key] || '';
		});
	}

	function collectFormRow() {
		var data = {};
		columns.forEach(function (col) {
			data[col.key] = String((form.elements[col.key] && form.elements[col.key].value) || '').trim();
		});
		return data;
	}

	function headerToKey(header) {
		var h = normalize(header).replace(/[^a-z0-9]+/g, '_');
		if (h === 'sl_no' || h === 'slno') return 'sl_no';
		if (h === 'item_name') return 'item_name';
		if (h === 'item') return 'item';
		if (h === 'width_mm' || h === 'width') return 'width_mm';
		if (h === 'lenght_mtr' || h === 'length_mtr' || h === 'lenght' || h === 'length') return 'length_mtr';
		if (h === 'paper_type') return 'paper_type';
		if (h === 'gsm') return 'gsm';
		if (h === 'dia') return 'dia';
		if (h === 'core') return 'core';
		if (h === 'size') return 'size';
		if (h === 'core_type') return 'core_type';
		return '';
	}

	addBtn.addEventListener('click', function () {
		clearForm();
		showForm(true);
	});

	cancelBtn.addEventListener('click', function () {
		clearForm();
		showForm(false);
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var row = collectFormRow();
		if (!row.sl_no) row.sl_no = String(nextSlNo());
		if (!row.item_name) {
			alert('Item Name is required.');
			return;
		}
		if (editIndex >= 0) rows[editIndex] = row;
		else rows.push(row);
		saveRows();
		clearForm();
		showForm(false);
		render();
	});

	tableBody.addEventListener('click', function (e) {
		var editBtn = e.target.closest('[data-edit]');
		if (editBtn) {
			var idx = parseInt(editBtn.getAttribute('data-edit') || '-1', 10);
			if (idx >= 0 && rows[idx]) {
				editIndex = idx;
				fillForm(rows[idx]);
				document.getElementById('ptmPaperFormSave').textContent = 'Update';
				showForm(true);
			}
			return;
		}
		var delBtn = e.target.closest('[data-del]');
		if (delBtn) {
			var didx = parseInt(delBtn.getAttribute('data-del') || '-1', 10);
			if (didx >= 0 && rows[didx] && confirm('Delete this row?')) {
				rows.splice(didx, 1);
				saveRows();
				render();
			}
		}
	});

	document.querySelectorAll('.ptm-filter-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var col = btn.getAttribute('data-col') || '';
			if (!col) return;
			var label = (columns.find(function (c) { return c.key === col; }) || {}).label || col;
			var current = filters[col] || '';
			var value = prompt('Filter ' + label + ' (empty = clear):', current);
			if (value === null) return;
			filters[col] = String(value || '').trim();
			render();
		});
	});

	importBtn.addEventListener('click', function () {
		importFile.click();
	});

	importFile.addEventListener('change', function (e) {
		var file = e.target.files && e.target.files[0];
		if (!file) return;
		if (typeof XLSX === 'undefined') {
			alert('Excel import library not loaded.');
			return;
		}
		var reader = new FileReader();
		reader.onload = function (evt) {
			var wb = XLSX.read(evt.target.result, { type: 'array' });
			var sheet = wb.Sheets[wb.SheetNames[0]];
			var raw = XLSX.utils.sheet_to_json(sheet, { defval: '' });
			raw.forEach(function (entry) {
				var row = {
					sl_no: '', item_name: '', item: '', width_mm: '', length_mtr: '', paper_type: '', gsm: '', dia: '', core: '', size: '', core_type: ''
				};
				Object.keys(entry).forEach(function (k) {
					var key = headerToKey(k);
					if (key) row[key] = String(entry[k] == null ? '' : entry[k]).trim();
				});
				if (!row.sl_no) row.sl_no = String(nextSlNo());
				if (row.item_name) rows.push(row);
			});
			saveRows();
			render();
			importFile.value = '';
		};
		reader.readAsArrayBuffer(file);
	});

	exportExcelBtn.addEventListener('click', async function () {
		var data = filteredRows();
		if (!data.length) {
			alert('No rows available to export.');
			return;
		}

		if (typeof ExcelJS === 'undefined') {
			if (typeof XLSX !== 'undefined') {
				var fallback = data.map(function (r) {
					return {
						'Sl no.': r.sl_no || '',
						'Item Name': r.item_name || '',
						'Item': r.item || '',
						'Width ( mm)': r.width_mm || '',
						'Lenght(mtr)': r.length_mtr || '',
						'Paper Type': r.paper_type || '',
						'GSM': r.gsm || '',
						'Dia': r.dia || '',
						'Core': r.core || '',
						'Size': r.size || '',
						'Core Type': r.core_type || ''
					};
				});
				var ws = XLSX.utils.json_to_sheet(fallback);
				var wb = XLSX.utils.book_new();
				XLSX.utils.book_append_sheet(wb, ws, 'PaperRoll');
				XLSX.writeFile(wb, 'paper-roll.xlsx');
				return;
			}
			alert('Excel export library not loaded.');
			return;
		}

		var workbook = new ExcelJS.Workbook();
		workbook.creator = 'Calipot ERP';
		workbook.created = new Date();

		var sheet = workbook.addWorksheet('PaperRoll', {
			views: [{ state: 'frozen', ySplit: 4 }],
			pageSetup: { paperSize: 9, orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0 }
		});

		sheet.mergeCells('A1:L1');
		sheet.getCell('A1').value = 'Paper-Roll Register';
		sheet.getCell('A1').font = { bold: true, size: 18, color: { argb: 'FFFFFFFF' } };
		sheet.getCell('A1').alignment = { vertical: 'middle', horizontal: 'center' };
		sheet.getCell('A1').fill = {
			type: 'pattern',
			pattern: 'solid',
			fgColor: { argb: 'FFBE185D' }
		};
		sheet.getRow(1).height = 28;

		sheet.mergeCells('A2:L2');
		sheet.getCell('A2').value = 'Modern Export Sheet | Filter-Applied Data';
		sheet.getCell('A2').font = { italic: true, size: 11, color: { argb: 'FF9D174D' } };
		sheet.getCell('A2').alignment = { vertical: 'middle', horizontal: 'center' };
		sheet.getCell('A2').fill = {
			type: 'pattern',
			pattern: 'solid',
			fgColor: { argb: 'FFFCE7F3' }
		};
		sheet.getRow(2).height = 20;

		sheet.mergeCells('A3:L3');
		sheet.getCell('A3').value = 'Generated: ' + new Date().toLocaleString();
		sheet.getCell('A3').font = { size: 10, color: { argb: 'FF831843' } };
		sheet.getCell('A3').alignment = { vertical: 'middle', horizontal: 'right' };
		sheet.getRow(3).height = 18;

		var headers = ['Sl no.', 'Item Name', 'Item', 'Width ( mm)', 'Lenght(mtr)', 'Paper Type', 'GSM', 'Dia', 'Core', 'Size', 'Core Type', 'Status'];
		sheet.addRow(headers);
		var headerRow = sheet.getRow(4);
		headerRow.height = 22;
		headerRow.eachCell(function (cell) {
			cell.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 10 };
			cell.alignment = { vertical: 'middle', horizontal: 'center', wrapText: true };
			cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFDB2777' } };
			cell.border = {
				top: { style: 'thin', color: { argb: 'FFFBCFE8' } },
				left: { style: 'thin', color: { argb: 'FFFBCFE8' } },
				bottom: { style: 'thin', color: { argb: 'FFFBCFE8' } },
				right: { style: 'thin', color: { argb: 'FFFBCFE8' } }
			};
		});

		sheet.columns = [
			{ width: 9 }, { width: 24 }, { width: 12 }, { width: 14 }, { width: 14 },
			{ width: 16 }, { width: 10 }, { width: 10 }, { width: 10 }, { width: 12 },
			{ width: 14 }, { width: 14 }
		];

		data.forEach(function (r, idx) {
			var row = sheet.addRow([
				r.sl_no || '',
				r.item_name || '',
				r.item || '',
				r.width_mm || '',
				r.length_mtr || '',
				r.paper_type || '',
				r.gsm || '',
				r.dia || '',
				r.core || '',
				r.size || '',
				r.core_type || '',
				'Active'
			]);
			row.eachCell(function (cell) {
				cell.alignment = { vertical: 'middle', horizontal: 'center' };
				cell.fill = {
					type: 'pattern',
					pattern: 'solid',
					fgColor: { argb: idx % 2 === 0 ? 'FFFFF7FB' : 'FFFFEEF6' }
				};
				cell.border = {
					top: { style: 'thin', color: { argb: 'FFFCE7F3' } },
					left: { style: 'thin', color: { argb: 'FFFCE7F3' } },
					bottom: { style: 'thin', color: { argb: 'FFFCE7F3' } },
					right: { style: 'thin', color: { argb: 'FFFCE7F3' } }
				};
			});
		});

		var footerStart = sheet.lastRow.number + 2;
		sheet.mergeCells('A' + footerStart + ':L' + footerStart);
		sheet.getCell('A' + footerStart).value = 'Footer: Paper-Roll master export generated from Calipot ERP';
		sheet.getCell('A' + footerStart).font = { size: 10, color: { argb: 'FF9D174D' }, bold: true };
		sheet.getCell('A' + footerStart).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFDF2F8' } };
		sheet.getCell('A' + footerStart).alignment = { vertical: 'middle', horizontal: 'left' };

		sheet.mergeCells('A' + (footerStart + 1) + ':L' + (footerStart + 1));
		sheet.getCell('A' + (footerStart + 1)).value = 'End of Report';
		sheet.getCell('A' + (footerStart + 1)).font = { size: 9, color: { argb: 'FFBE185D' }, italic: true };
		sheet.getCell('A' + (footerStart + 1)).alignment = { vertical: 'middle', horizontal: 'right' };

		var buffer = await workbook.xlsx.writeBuffer();
		var blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'paper-roll-modern.xlsx';
		document.body.appendChild(link);
		link.click();
		link.remove();
		URL.revokeObjectURL(link.href);
	});

	printBtn.addEventListener('click', function () {
		var data = filteredRows();
		if (!data.length) {
			alert('No rows available to print.');
			return;
		}

		var tableHtml = '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:12px">';
		tableHtml += '<thead><tr>' + columns.map(function (c) { return '<th style="background:#fce7f3">' + c.label + '</th>'; }).join('') + '</tr></thead><tbody>';
		tableHtml += data.map(function (r) {
			return '<tr>' + columns.map(function (c) {
				var val = String(r[c.key] == null ? '' : r[c.key]);
				return '<td>' + val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>';
			}).join('') + '</tr>';
		}).join('');
		tableHtml += '</tbody></table>';

		var w = window.open('', '_blank', 'width=1200,height=800');
		if (!w) {
			alert('Print popup blocked. Please allow popups and try again.');
			return;
		}

		w.document.write('<!doctype html><html><head><title>Paper-Roll Print</title></head><body>');
		w.document.write('<h2 style="font-family:Arial,sans-serif;margin:0 0 10px 0">Paper-Roll</h2>');
		w.document.write(tableHtml);
		w.document.write('</body></html>');
		w.document.close();
		w.focus();
		w.print();
	});

	loadRows();
	clearForm();
	render();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
