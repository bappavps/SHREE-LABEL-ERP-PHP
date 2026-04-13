<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// == Paper-Roll Concept: DB backend =========================================
$prcDb = getDB();
$prcDb->query("CREATE TABLE IF NOT EXISTS paper_roll_concept (
	id INT AUTO_INCREMENT PRIMARY KEY,
	sl_no INT NOT NULL DEFAULT 0,
	item_name VARCHAR(200) NOT NULL DEFAULT '',
	item VARCHAR(50) NOT NULL DEFAULT 'All',
	width_mm VARCHAR(50) NOT NULL DEFAULT '',
	length_mtr VARCHAR(50) NOT NULL DEFAULT '',
	paper_type VARCHAR(100) NOT NULL DEFAULT '',
	gsm VARCHAR(50) NOT NULL DEFAULT '',
	dia VARCHAR(50) NOT NULL DEFAULT '',
	core VARCHAR(100) NOT NULL DEFAULT '',
	size VARCHAR(100) NOT NULL DEFAULT '',
	core_type VARCHAR(100) NOT NULL DEFAULT 'All',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$prcFlash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prc_action'])) {
	if (!verifyCSRF((string)($_POST['csrf_token'] ?? ''))) {
		$prcFlash = ['type' => 'error', 'msg' => 'Invalid CSRF token.'];
	} else {
		$prcAction = (string) $_POST['prc_action'];

		if ($prcAction === 'prc_save') {
			$editId    = (int) ($_POST['prc_edit_id'] ?? 0);
			$slNo      = max(1, (int) ($_POST['sl_no'] ?? 1));
			$itemName  = trim((string) ($_POST['item_name'] ?? ''));
			$item      = trim((string) ($_POST['item'] ?? 'All'));
			$widthMm   = trim((string) ($_POST['width_mm'] ?? ''));
			$lengthMtr = trim((string) ($_POST['length_mtr'] ?? ''));
			$paperType = trim((string) ($_POST['paper_type'] ?? ''));
			$gsm       = trim((string) ($_POST['gsm'] ?? ''));
			$dia       = trim((string) ($_POST['dia'] ?? ''));
			$core      = trim((string) ($_POST['core'] ?? ''));
			$size      = trim((string) ($_POST['size'] ?? ''));
			$coreType  = trim((string) ($_POST['core_type'] ?? 'All'));
			if ($itemName === '') {
				$prcFlash = ['type' => 'error', 'msg' => 'Item Name is required.'];
			} else {
				if ($editId > 0) {
					$st = $prcDb->prepare('UPDATE paper_roll_concept SET sl_no=?,item_name=?,item=?,width_mm=?,length_mtr=?,paper_type=?,gsm=?,dia=?,core=?,size=?,core_type=? WHERE id=?');
					$st->bind_param('issssssssssi', $slNo, $itemName, $item, $widthMm, $lengthMtr, $paperType, $gsm, $dia, $core, $size, $coreType, $editId);
				} else {
					$st = $prcDb->prepare('INSERT INTO paper_roll_concept (sl_no,item_name,item,width_mm,length_mtr,paper_type,gsm,dia,core,size,core_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
					$st->bind_param('issssssssss', $slNo, $itemName, $item, $widthMm, $lengthMtr, $paperType, $gsm, $dia, $core, $size, $coreType);
				}
				if ($st->execute()) {
					$prcFlash = ['type' => 'success', 'msg' => $editId > 0 ? 'Row updated.' : 'Row added.'];
				} else {
					$prcFlash = ['type' => 'error', 'msg' => 'DB error: ' . $prcDb->error];
				}
				$st->close();
			}
		} elseif ($prcAction === 'prc_delete') {
			$delId = (int) ($_POST['prc_id'] ?? 0);
			if ($delId > 0) {
				$st = $prcDb->prepare('DELETE FROM paper_roll_concept WHERE id=? LIMIT 1');
				$st->bind_param('i', $delId);
				$st->execute();
				$st->close();
				$prcFlash = ['type' => 'success', 'msg' => 'Row deleted.'];
			}
		} elseif ($prcAction === 'prc_bulk_delete') {
			$ids = array_map('intval', (array) ($_POST['prc_ids'] ?? []));
			$ids = array_values(array_filter($ids, fn($id) => $id > 0));
			if ($ids) {
				$ph    = implode(',', array_fill(0, count($ids), '?'));
				$types = str_repeat('i', count($ids));
				$st = $prcDb->prepare("DELETE FROM paper_roll_concept WHERE id IN ($ph)");
				$st->bind_param($types, ...$ids);
				$st->execute();
				$st->close();
				$prcFlash = ['type' => 'success', 'msg' => count($ids) . ' row(s) deleted.'];
			}
		} elseif ($prcAction === 'prc_import') {
			$jsonRows   = (string) ($_POST['prc_import_json'] ?? '');
			$importData = json_decode($jsonRows, true);
			if (!is_array($importData)) {
				$prcFlash = ['type' => 'error', 'msg' => 'Invalid import data.'];
			} else {
				$added = 0;
				$skipped = 0;
				foreach ($importData as $r) {
					$iName = trim((string) ($r['item_name'] ?? ''));
					if ($iName === '') { $skipped++; continue; }
					$autoSlRes = $prcDb->query('SELECT COALESCE(MAX(sl_no),0)+1 AS n FROM paper_roll_concept');
					$autoSl    = (int) (($autoSlRes ? $autoSlRes->fetch_assoc() : ['n' => 1])['n'] ?? 1);
					$iSlNo  = (int) ($r['sl_no'] ?? $autoSl) ?: $autoSl;
					$iItem  = trim((string) ($r['item'] ?? 'All')) ?: 'All';
					$iW     = trim((string) ($r['width_mm'] ?? ''));
					$iL     = trim((string) ($r['length_mtr'] ?? ''));
					$iPt    = trim((string) ($r['paper_type'] ?? ''));
					$iG     = trim((string) ($r['gsm'] ?? ''));
					$iD     = trim((string) ($r['dia'] ?? ''));
					$iC     = trim((string) ($r['core'] ?? ''));
					$iS     = trim((string) ($r['size'] ?? ''));
					$iCt    = trim((string) ($r['core_type'] ?? 'All')) ?: 'All';
					$st = $prcDb->prepare('INSERT INTO paper_roll_concept (sl_no,item_name,item,width_mm,length_mtr,paper_type,gsm,dia,core,size,core_type) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
					$st->bind_param('issssssssss', $iSlNo, $iName, $iItem, $iW, $iL, $iPt, $iG, $iD, $iC, $iS, $iCt);
					$st->execute() ? $added++ : $skipped++;
					$st->close();
				}
				$prcFlash = ['type' => 'success', 'msg' => "Imported $added row(s). Skipped: $skipped."];
			}
		}
	}
	$redirTab = urlencode('paper_roll_concept');
	header('Location: ' . BASE_URL . '/modules/master/plate-data-tools-master.php?tab=' . $redirTab
		. '&prc_flash=' . urlencode($prcFlash['type'])
		. '&prc_msg='   . urlencode($prcFlash['msg']));
	exit;
}

if (isset($_GET['prc_flash'])) {
	$prcFlash = ['type' => (string) $_GET['prc_flash'], 'msg' => (string) ($_GET['prc_msg'] ?? '')];
}

$prcRows   = [];
$prcNextSl = 1;
$prcRes = $prcDb->query('SELECT * FROM paper_roll_concept ORDER BY sl_no ASC, id ASC');
if ($prcRes) {
	while ($pr = $prcRes->fetch_assoc()) {
		$prcRows[] = $pr;
	}
}
$prcNextSl = $prcRows ? ((int) end($prcRows)['sl_no'] + 1) : 1;

$prcItemValues = [];
$prcPaperTypeValues = [];
$prcCoreTypeValues = [];
foreach ($prcRows as $prcOptRow) {
	$optItem = trim((string)($prcOptRow['item'] ?? ''));
	if ($optItem !== '') {
		$prcItemValues[strtolower($optItem)] = $optItem;
	}
	$optPaperType = trim((string)($prcOptRow['paper_type'] ?? ''));
	if ($optPaperType !== '') {
		$prcPaperTypeValues[strtolower($optPaperType)] = $optPaperType;
	}
	$optCoreType = trim((string)($prcOptRow['core_type'] ?? ''));
	if ($optCoreType !== '') {
		$prcCoreTypeValues[strtolower($optCoreType)] = $optCoreType;
	}
}
natcasesort($prcItemValues);
natcasesort($prcPaperTypeValues);
natcasesort($prcCoreTypeValues);
$prcItemValues = array_values($prcItemValues);
$prcPaperTypeValues = array_values($prcPaperTypeValues);
$prcCoreTypeValues = array_values($prcCoreTypeValues);
if (!$prcItemValues) {
	$prcItemValues = ['All'];
}
if (!$prcCoreTypeValues) {
	$prcCoreTypeValues = ['All'];
}
// == END Paper-Roll Concept backend =========================================

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
.ptm-paper-chip-wrap{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.ptm-reset-filter-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #f9a8d4;background:#fff;color:#9d174d;font-size:.72rem;font-weight:700;cursor:pointer}
.ptm-reset-filter-btn:hover{background:#fff1f2;border-color:#fb7185}
.ptm-paper-tools{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.ptm-paper-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 11px;border-radius:9px;border:1px solid #f9a8d4;background:#fff;color:#9d174d;font-size:.74rem;font-weight:700;cursor:pointer;text-decoration:none}
.ptm-paper-btn:hover{background:#fff1f2;border-color:#fb7185}
.ptm-paper-btn-primary{background:#e11d48;color:#fff;border-color:#be123c}
.ptm-paper-btn-primary:hover{background:#be123c;border-color:#9f1239}
.ptm-paper-btn-danger{background:#b91c1c;color:#fff;border-color:#991b1b}
.ptm-paper-btn-danger:hover{background:#991b1b;border-color:#7f1d1d}
.ptm-paper-btn[disabled]{opacity:.55;cursor:not-allowed}
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
.ptm-filter-pop{position:fixed;z-index:1500;width:230px;max-height:320px;overflow:hidden;background:#fff;border:1px solid #fda4af;border-radius:10px;box-shadow:0 10px 24px rgba(159,18,57,.18);display:none}
.ptm-filter-pop-head{padding:8px;border-bottom:1px solid #fecdd3;background:#fff7fb}
.ptm-filter-pop-head input{width:100%;height:30px;border:1px solid #f9a8d4;border-radius:7px;padding:0 8px;font-size:.74rem}
.ptm-filter-pop-list{max-height:210px;overflow:auto;padding:6px 8px}
.ptm-filter-pop-opt{display:flex;align-items:center;gap:7px;padding:3px 0;font-size:.74rem;color:#4a044e}
.ptm-filter-pop-opt input{margin:0}
.ptm-filter-pop-foot{display:flex;justify-content:flex-end;gap:6px;padding:8px;border-top:1px solid #fecdd3;background:#fff7fb}
.ptm-filter-pop-foot button{height:28px;border:1px solid #f9a8d4;background:#fff;color:#9d174d;border-radius:7px;padding:0 8px;font-size:.72rem;cursor:pointer}
.ptm-filter-pop-foot button.ptm-apply{background:#e11d48;border-color:#be123c;color:#fff}
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
				<?= $activeModuleHtml ?>			<?php elseif ($tab['key'] === 'paper_roll_concept'): ?>
				<?php
				$prcCsrf = generateCSRF();
				if ($prcFlash['msg'] !== ''): ?>
				<div class="ptm-alert ptm-alert-<?= e($prcFlash['type']) ?>"><?= e($prcFlash['msg']) ?></div>
				<?php endif; ?>
				<div class="ptm-paper-wrap">
					<div class="ptm-paper-head">
						<div>
							<div class="ptm-paper-title"><i class="bi bi-journal-richtext"></i> Paper-Roll Master</div>
							<div class="ptm-paper-sub">All data is stored in the database. Use Add Row to insert, or Bulk Excel Import to upload many rows at once.</div>
						</div>
						<div class="ptm-paper-chip-wrap">
							<span class="ptm-paper-chip"><i class="bi bi-database-fill-check"></i> DB Backed</span>
							<button type="button" class="ptm-reset-filter-btn" id="ptmPaperResetFiltersBtn"><i class="bi bi-arrow-counterclockwise"></i> Reset Filters</button>
						</div>
					</div>

					<div class="ptm-paper-tools">
						<button type="button" class="ptm-paper-btn ptm-paper-btn-primary" id="ptmPaperAddBtn"><i class="bi bi-plus-circle"></i> Add Row</button>
						<button type="button" class="ptm-paper-btn ptm-paper-btn-danger" id="ptmPaperBulkDeleteBtn" disabled><i class="bi bi-trash3"></i> Bulk Delete</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperPrintBtn"><i class="bi bi-printer"></i> Print</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperExportExcelBtn"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
						<button type="button" class="ptm-paper-btn" id="ptmPaperImportBtn"><i class="bi bi-upload"></i> Bulk Excel Import</button>
						<input type="file" id="ptmPaperImportFile" class="ptm-paper-file" accept=".xlsx,.xls">
					</div>

					<!-- Add / Edit form  (POST to server) -->
					<form id="ptmPaperForm" class="ptm-paper-form" method="post" action="<?= e($tabBasePath . '?tab=paper_roll_concept') ?>">
						<input type="hidden" name="csrf_token" value="<?= e($prcCsrf) ?>">
						<input type="hidden" name="prc_action" value="prc_save">
						<input type="hidden" name="prc_edit_id" id="ptmPaperEditId" value="0">
						<div class="ptm-paper-form-grid">
							<div><label>Sl no.</label><input type="number" min="1" step="1" name="sl_no" id="ptmF_sl_no" value="<?= (int)$prcNextSl ?>" required></div>
							<div><label>Item Name</label><input type="text" name="item_name" id="ptmF_item_name" required></div>
							<div><label>Item</label><select name="item" id="ptmF_item"><?php foreach ($prcItemValues as $itemOpt): ?><option value="<?= e($itemOpt) ?>" <?= strtolower($itemOpt) === 'all' ? 'selected' : '' ?>><?= e($itemOpt) ?></option><?php endforeach; ?></select></div>
							<div><label>Width (mm)</label><input type="text" name="width_mm" id="ptmF_width_mm"></div>
							<div><label>Length (mtr)</label><input type="text" name="length_mtr" id="ptmF_length_mtr"></div>
							<div><label>Paper Type</label><select name="paper_type" id="ptmF_paper_type"><option value="">—</option><?php foreach ($prcPaperTypeValues as $paperTypeOpt): ?><option value="<?= e($paperTypeOpt) ?>"><?= e($paperTypeOpt) ?></option><?php endforeach; ?></select></div>
							<div><label>GSM</label><input type="text" name="gsm" id="ptmF_gsm"></div>
							<div><label>Dia</label><input type="text" name="dia" id="ptmF_dia"></div>
							<div><label>Core</label><input type="text" name="core" id="ptmF_core"></div>
							<div><label>Size</label><input type="text" name="size" id="ptmF_size"></div>
							<div><label>Core Type</label><select name="core_type" id="ptmF_core_type"><?php foreach ($prcCoreTypeValues as $coreTypeOpt): ?><option value="<?= e($coreTypeOpt) ?>" <?= strtolower($coreTypeOpt) === 'all' ? 'selected' : '' ?>><?= e($coreTypeOpt) ?></option><?php endforeach; ?></select></div>
						</div>
						<div class="ptm-paper-form-actions">
							<button type="button" class="ptm-paper-btn" id="ptmPaperFormCancel">Cancel</button>
							<button type="submit" class="ptm-paper-btn ptm-paper-btn-primary" id="ptmPaperFormSave">Save</button>
						</div>
					</form>

					<!-- Bulk actions (hidden forms) -->
					<form id="ptmDeleteForm" method="post" action="<?= e($tabBasePath . '?tab=paper_roll_concept') ?>" style="display:none">
						<input type="hidden" name="csrf_token" value="<?= e($prcCsrf) ?>">
						<input type="hidden" name="prc_action" value="prc_delete">
						<input type="hidden" name="prc_id" id="ptmDeleteId" value="0">
					</form>
					<form id="ptmBulkDeleteForm" method="post" action="<?= e($tabBasePath . '?tab=paper_roll_concept') ?>" style="display:none">
						<input type="hidden" name="csrf_token" value="<?= e($prcCsrf) ?>">
						<input type="hidden" name="prc_action" value="prc_bulk_delete">
						<div id="ptmBulkDeleteIds"></div>
					</form>
					<form id="ptmImportForm" method="post" action="<?= e($tabBasePath . '?tab=paper_roll_concept') ?>" style="display:none">
						<input type="hidden" name="csrf_token" value="<?= e($prcCsrf) ?>">
						<input type="hidden" name="prc_action" value="prc_import">
						<input type="hidden" name="prc_import_json" id="ptmImportJson" value="">
					</form>

					<div class="ptm-paper-table-wrap">
						<table class="ptm-paper-table" id="ptmPaperTable">
							<thead>
								<tr>
									<th><input type="checkbox" id="ptmPaperSelectAll" title="Select all"></th>
									<th><div class="ptm-th-wrap"><span>Sl no.</span><button type="button" class="ptm-filter-btn" data-col="1" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Item Name</span><button type="button" class="ptm-filter-btn" data-col="2" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Item</span><button type="button" class="ptm-filter-btn" data-col="3" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Width (mm)</span><button type="button" class="ptm-filter-btn" data-col="4" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Length (mtr)</span><button type="button" class="ptm-filter-btn" data-col="5" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Paper Type</span><button type="button" class="ptm-filter-btn" data-col="6" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>GSM</span><button type="button" class="ptm-filter-btn" data-col="7" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Dia</span><button type="button" class="ptm-filter-btn" data-col="8" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Core</span><button type="button" class="ptm-filter-btn" data-col="9" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Size</span><button type="button" class="ptm-filter-btn" data-col="10" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th><div class="ptm-th-wrap"><span>Core Type</span><button type="button" class="ptm-filter-btn" data-col="11" title="Filter"><i class="bi bi-funnel"></i></button></div></th>
									<th>Action</th>
								</tr>
							</thead>
							<tbody id="ptmPaperTbody">
							<?php if (!$prcRows): ?>
								<tr><td colspan="13" style="text-align:center;color:#9f1239;padding:18px">No data. Click "Add Row" to begin.</td></tr>
							<?php else: foreach ($prcRows as $prcRow): ?>
								<tr data-id="<?= (int)$prcRow['id'] ?>" data-row='<?= e(json_encode($prcRow, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
									<td><input type="checkbox" class="ptm-row-cb" value="<?= (int)$prcRow['id'] ?>"></td>
									<td><?= e((string)$prcRow['sl_no']) ?></td>
									<td><?= e((string)$prcRow['item_name']) ?></td>
									<td><?= e((string)$prcRow['item']) ?></td>
									<td><?= e((string)$prcRow['width_mm']) ?></td>
									<td><?= e((string)$prcRow['length_mtr']) ?></td>
									<td><?= e((string)$prcRow['paper_type']) ?></td>
									<td><?= e((string)$prcRow['gsm']) ?></td>
									<td><?= e((string)$prcRow['dia']) ?></td>
									<td><?= e((string)$prcRow['core']) ?></td>
									<td><?= e((string)$prcRow['size']) ?></td>
									<td><?= e((string)$prcRow['core_type']) ?></td>
									<td><div class="ptm-row-actions">
										<button type="button" class="ptm-row-btn" data-prc-edit="<?= (int)$prcRow['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
										<button type="button" class="ptm-row-btn" data-prc-del="<?= (int)$prcRow['id'] ?>" title="Delete"><i class="bi bi-trash"></i></button>
									</div></td>
								</tr>
							<?php endforeach; endif; ?>
							</tbody>
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
	var addBtn         = document.getElementById('ptmPaperAddBtn');
	var bulkDeleteBtn  = document.getElementById('ptmPaperBulkDeleteBtn');
	var selectAllBox   = document.getElementById('ptmPaperSelectAll');
	var printBtn       = document.getElementById('ptmPaperPrintBtn');
	var exportExcelBtn = document.getElementById('ptmPaperExportExcelBtn');
	var importBtn      = document.getElementById('ptmPaperImportBtn');
	var importFile     = document.getElementById('ptmPaperImportFile');
	var form           = document.getElementById('ptmPaperForm');
	var cancelBtn      = document.getElementById('ptmPaperFormCancel');
	var tableBody      = document.getElementById('ptmPaperTbody');
	var editIdInput    = document.getElementById('ptmPaperEditId');

	var deleteForm     = document.getElementById('ptmDeleteForm');
	var deleteIdInput  = document.getElementById('ptmDeleteId');
	var bulkForm       = document.getElementById('ptmBulkDeleteForm');
	var bulkIdsDiv     = document.getElementById('ptmBulkDeleteIds');
	var importForm     = document.getElementById('ptmImportForm');
	var importJsonInput= document.getElementById('ptmImportJson');
	var filterButtons  = document.querySelectorAll('.ptm-filter-btn[data-col]');
	var resetFiltersBtn = document.getElementById('ptmPaperResetFiltersBtn');

	var filterState = {};
	var filterPopup = document.createElement('div');
	filterPopup.className = 'ptm-filter-pop';
	document.body.appendChild(filterPopup);

	function safeText(v) {
		return String(v==null?'':v).replace(/[&<>"']/g, function(c){
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]||c;
		});
	}
	function normalize(v){ return String(v==null?'':v).trim().toLowerCase(); }
	function colCellText(tr, colIndex) {
		var cell = tr && tr.children ? tr.children[colIndex] : null;
		return cell ? String(cell.textContent || '').trim() : '';
	}
	function columnDistinctValues(colIndex) {
		var values = {};
		document.querySelectorAll('#ptmPaperTbody tr[data-row]').forEach(function(tr){
			var v = colCellText(tr, colIndex);
			var key = normalize(v);
			if (key === '') key = '__BLANK__';
			if (!values[key]) values[key] = (v === '' ? '(Blanks)' : v);
		});
		if (!values.__BLANK__) values.__BLANK__ = '(Blanks)';
		var out = Object.keys(values).map(function(k){ return {key:k, label:values[k]}; });
		out.sort(function(a,b){ return a.label.localeCompare(b.label, undefined, {numeric:true, sensitivity:'base'}); });
		return out;
	}
	function updateFilterButtonStates() {
		filterButtons.forEach(function(btn){
			var col = String(btn.getAttribute('data-col') || '');
			var st = filterState[col];
			btn.classList.toggle('active', !!(st && st.active));
		});
	}
	function applyTableFilters() {
		document.querySelectorAll('#ptmPaperTbody tr[data-row]').forEach(function(tr){
			var visible = true;
			Object.keys(filterState).forEach(function(colKey){
				if (!visible) return;
				var state = filterState[colKey];
				if (!state || !state.active) return;
				var raw = colCellText(tr, parseInt(colKey, 10));
				var normalized = normalize(raw);
				if (normalized === '') normalized = '__BLANK__';
				if (!state.allowed[normalized]) visible = false;
			});
			tr.style.display = visible ? '' : 'none';
		});
		updateFilterButtonStates();
	}
	function closeFilterPopup() {
		filterPopup.style.display = 'none';
		filterPopup.innerHTML = '';
	}
	function openFilterPopup(btn) {
		var col = String(btn.getAttribute('data-col') || '');
		if (!col) return;
		var values = columnDistinctValues(parseInt(col, 10));
		if (!values.length) return;

		var allAllowed = {};
		values.forEach(function(v){ allAllowed[v.key] = true; });
		if (!filterState[col]) {
			filterState[col] = {active:false, allowed:allAllowed};
		}
		var state = filterState[col];
		var normalizedAllowed = {};
		values.forEach(function(v){
			normalizedAllowed[v.key] = state.allowed && Object.prototype.hasOwnProperty.call(state.allowed, v.key)
				? !!state.allowed[v.key]
				: true;
		});
		state.allowed = normalizedAllowed;
		filterState[col] = state;
		var tempAllowed = Object.assign({}, state.allowed);

		filterPopup.innerHTML = '';
		var head = document.createElement('div');
		head.className = 'ptm-filter-pop-head';
		head.innerHTML = '<input type="text" placeholder="Search value..." id="ptmFilterSearch">';
		filterPopup.appendChild(head);

		var list = document.createElement('div');
		list.className = 'ptm-filter-pop-list';
		filterPopup.appendChild(list);

		var foot = document.createElement('div');
		foot.className = 'ptm-filter-pop-foot';
		foot.innerHTML = '<button type="button" id="ptmFilterReset">Reset</button><button type="button" class="ptm-apply" id="ptmFilterApply">Apply</button>';
		filterPopup.appendChild(foot);

		function renderList(searchText) {
			var needle = normalize(searchText || '');
			list.innerHTML = '';

			var allChecked = values.every(function(v){ return !!tempAllowed[v.key]; });
			var selectAllRow = document.createElement('label');
			selectAllRow.className = 'ptm-filter-pop-opt';
			selectAllRow.style.fontWeight = '700';
			selectAllRow.innerHTML = '<input type="checkbox" data-select-all="1" ' + (allChecked ? 'checked' : '') + '> <span>(Select All)</span>';
			list.appendChild(selectAllRow);

			values.forEach(function(v){
				if (needle && normalize(v.label).indexOf(needle) === -1) return;
				var row = document.createElement('label');
				row.className = 'ptm-filter-pop-opt';
				row.innerHTML = '<input type="checkbox" data-key="'+safeText(v.key)+'" '+(tempAllowed[v.key] ? 'checked' : '')+'> <span>'+safeText(v.label)+'</span>';
				list.appendChild(row);
			});
		}

		renderList('');
		var searchEl = head.querySelector('#ptmFilterSearch');
		searchEl.addEventListener('input', function(){ renderList(this.value || ''); });
		list.addEventListener('change', function(e){
			var target = e.target;
			if (!target || target.tagName !== 'INPUT') return;
			if (target.hasAttribute('data-select-all')) {
				var checkedAll = !!target.checked;
				values.forEach(function(v){ tempAllowed[v.key] = checkedAll; });
				renderList(searchEl.value || '');
				return;
			}
			var key = String(target.getAttribute('data-key') || '');
			if (!key) return;
			tempAllowed[key] = !!target.checked;
			renderList(searchEl.value || '');
		});

		foot.querySelector('#ptmFilterReset').addEventListener('click', function(){
			delete filterState[col];
			closeFilterPopup();
			applyTableFilters();
		});
		foot.querySelector('#ptmFilterApply').addEventListener('click', function(){
			var allowed = Object.assign({}, tempAllowed);
			state.allowed = allowed;
			var allowedCount = values.filter(function(v){ return !!allowed[v.key]; }).length;
			state.active = allowedCount < values.length;
			filterState[col] = state;
			closeFilterPopup();
			applyTableFilters();
		});

		var rect = btn.getBoundingClientRect();
		filterPopup.style.left = (rect.left + window.scrollX) + 'px';
		filterPopup.style.top = (rect.bottom + window.scrollY + 6) + 'px';
		filterPopup.style.display = 'block';
	}

	// ── Show/hide form ─────────────────────────────────────────────────
	function showForm(show) { form.style.display = show ? 'block' : 'none'; }

	function clearForm() {
		form.reset();
		editIdInput.value = '0';
		document.getElementById('ptmPaperFormSave').textContent = 'Save';
	}

	if (addBtn) addBtn.addEventListener('click', function () {
		clearForm();
		showForm(true);
	});
	if (cancelBtn) cancelBtn.addEventListener('click', function () {
		clearForm();
		showForm(false);
	});

	// ── Edit: fill form from row data ──────────────────────────────────
	if (tableBody) tableBody.addEventListener('click', function (e) {
		var editBtn = e.target.closest('[data-prc-edit]');
		if (editBtn) {
			var tr = editBtn.closest('tr');
			var row = {};
			try { row = JSON.parse(tr.getAttribute('data-row') || '{}'); } catch(x){}
			editIdInput.value = row.id || '0';
			['sl_no','item_name','item','width_mm','length_mtr','paper_type','gsm','dia','core','size','core_type'].forEach(function(k){
				var el = document.getElementById('ptmF_' + k);
				if (el) el.value = row[k] || '';
			});
			document.getElementById('ptmPaperFormSave').textContent = 'Update';
			showForm(true);
			return;
		}
		var delBtn = e.target.closest('[data-prc-del]');
		if (delBtn) {
			var id = parseInt(delBtn.getAttribute('data-prc-del') || '0', 10);
			if (id > 0 && confirm('Delete this row?')) {
				deleteIdInput.value = id;
				deleteForm.submit();
			}
		}
	});

	// ── Checkbox / Bulk delete ─────────────────────────────────────────
	function getSelectedIds() {
		return Array.prototype.map.call(
			document.querySelectorAll('.ptm-row-cb:checked'),
			function(cb){ return parseInt(cb.value, 10); }
		).filter(function(id){ return id > 0; });
	}
	function updateBulkBtn() {
		var n = getSelectedIds().length;
		if (bulkDeleteBtn) {
			bulkDeleteBtn.disabled = n === 0;
			bulkDeleteBtn.innerHTML = '<i class="bi bi-trash3"></i> Bulk Delete' + (n ? ' ('+n+')' : '');
		}
	}
	if (tableBody) tableBody.addEventListener('change', function(e){
		if (e.target.classList.contains('ptm-row-cb')) updateBulkBtn();
	});
	if (selectAllBox) selectAllBox.addEventListener('change', function(){
		document.querySelectorAll('.ptm-row-cb').forEach(function(cb){ cb.checked = selectAllBox.checked; });
		updateBulkBtn();
	});
	if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', function(){
		var ids = getSelectedIds();
		if (!ids.length) return;
		if (!confirm('Delete ' + ids.length + ' selected row(s)?')) return;
		bulkIdsDiv.innerHTML = ids.map(function(id){
			return '<input type="hidden" name="prc_ids[]" value="'+id+'">';
		}).join('');
		bulkForm.submit();
	});

	filterButtons.forEach(function(btn){
		btn.addEventListener('click', function(e){
			e.stopPropagation();
			if (filterPopup.style.display === 'block') {
				closeFilterPopup();
			}
			openFilterPopup(btn);
		});
	});
	if (resetFiltersBtn) {
		resetFiltersBtn.addEventListener('click', function(){
			filterState = {};
			closeFilterPopup();
			applyTableFilters();
		});
	}
	document.addEventListener('click', function(e){
		if (filterPopup.style.display !== 'block') return;
		if (filterPopup.contains(e.target)) return;
		if (e.target.closest && e.target.closest('.ptm-filter-btn')) return;
		closeFilterPopup();
	});

	// ── Excel Import ───────────────────────────────────────────────────
	function headerToKey(header) {
		var h = normalize(header).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
		if (h === 'sl_no' || h === 'slno')                                    return 'sl_no';
		if (h === 'item_name')                                                return 'item_name';
		if (h === 'item')                                                     return 'item';
		if (h === 'width_mm' || h === 'width')                               return 'width_mm';
		if (h === 'lenght_mtr'||h==='length_mtr'||h==='lenght'||h==='length') return 'length_mtr';
		if (h === 'paper_type')  return 'paper_type';
		if (h === 'gsm')         return 'gsm';
		if (h === 'dia')         return 'dia';
		if (h === 'core')        return 'core';
		if (h === 'size')        return 'size';
		if (h === 'core_type')   return 'core_type';
		return '';
	}
	function detectHeaderRow(matrix) {
		var expected = ['sl_no','item_name','item','width_mm','length_mtr','paper_type','gsm','dia','core','size','core_type'];
		var best = {idx:-1, score:-1};
		for (var i=0; i<Math.min(matrix.length,15); i++) {
			var score=0;
			(matrix[i]||[]).forEach(function(c){ if(expected.indexOf(headerToKey(c))>-1) score++; });
			if(score>best.score) best={idx:i,score:score};
		}
		return best.score >= 4 ? best.idx : -1;
	}

	if (importBtn) importBtn.addEventListener('click', function(){ importFile.click(); });

	if (importFile) importFile.addEventListener('change', function(e){
		var file = e.target.files && e.target.files[0];
		if (!file) return;
		if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
		var reader = new FileReader();
		reader.onload = function(evt){
			var wb     = XLSX.read(evt.target.result, {type:'array'});
			var sheet  = wb.Sheets[wb.SheetNames[0]];
			var matrix = XLSX.utils.sheet_to_json(sheet, {header:1, defval:''});
			var hIdx   = detectHeaderRow(matrix);
			if (hIdx < 0) { alert('Header row not detected. Use exported format.'); importFile.value=''; return; }
			var hMap   = (matrix[hIdx]||[]).map(headerToKey);
			var added=0, skipped=0, importRows=[];
			for (var r=hIdx+1; r<matrix.length; r++) {
				var vals = matrix[r]||[];
				var row  = {sl_no:'',item_name:'',item:'All',width_mm:'',length_mtr:'',paper_type:'',gsm:'',dia:'',core:'',size:'',core_type:'All'};
				for (var c=0; c<hMap.length; c++) {
					if (hMap[c]) row[hMap[c]] = String(vals[c]==null?'':vals[c]).trim();
				}
				var hasAny = Object.keys(row).some(function(k){ return normalize(row[k])!==''; });
				if (!hasAny) continue;
				if (row.item_name) { importRows.push(row); added++; } else skipped++;
			}
			if (!importRows.length) { alert('No valid rows found.'); importFile.value=''; return; }
			importJsonInput.value = JSON.stringify(importRows);
			importForm.submit();
		};
		reader.readAsArrayBuffer(file);
	});

	// ── Excel Export (ExcelJS / XLSX fallback) ─────────────────────────
	function collectTableData() {
		var data = [];
		document.querySelectorAll('#ptmPaperTbody tr[data-row]').forEach(function(tr){
			try{ data.push(JSON.parse(tr.getAttribute('data-row'))); } catch(x){}
		});
		return data;
	}

	if (exportExcelBtn) exportExcelBtn.addEventListener('click', async function(){
		var data = collectTableData();
		if (!data.length) { alert('No rows to export.'); return; }

		if (typeof ExcelJS !== 'undefined') {
			var workbook = new ExcelJS.Workbook();
			workbook.creator = 'Calipot ERP';
			var sheet = workbook.addWorksheet('PaperRoll', {
				views:[{state:'frozen',ySplit:4}],
				pageSetup:{paperSize:9,orientation:'landscape',fitToPage:true,fitToWidth:1,fitToHeight:0}
			});
			sheet.mergeCells('A1:L1'); sheet.getCell('A1').value='Paper-Roll Register';
			sheet.getCell('A1').font={bold:true,size:18,color:{argb:'FFFFFFFF'}};
			sheet.getCell('A1').alignment={vertical:'middle',horizontal:'center'};
			sheet.getCell('A1').fill={type:'pattern',pattern:'solid',fgColor:{argb:'FFBE185D'}};
			sheet.getRow(1).height=28;
			sheet.mergeCells('A2:L2'); sheet.getCell('A2').value='Modern Export Sheet';
			sheet.getCell('A2').font={italic:true,size:11,color:{argb:'FF9D174D'}};
			sheet.getCell('A2').alignment={vertical:'middle',horizontal:'center'};
			sheet.getCell('A2').fill={type:'pattern',pattern:'solid',fgColor:{argb:'FFFCE7F3'}};
			sheet.getRow(2).height=20;
			sheet.mergeCells('A3:L3'); sheet.getCell('A3').value='Generated: '+new Date().toLocaleString();
			sheet.getCell('A3').font={size:10,color:{argb:'FF831843'}};
			sheet.getCell('A3').alignment={vertical:'middle',horizontal:'right'};
			sheet.getRow(3).height=18;
			var hdrs=['Sl no.','Item Name','Item','Width ( mm)','Lenght(mtr)','Paper Type','GSM','Dia','Core','Size','Core Type','Status'];
			sheet.addRow(hdrs);
			var hRow=sheet.getRow(4); hRow.height=22;
			hRow.eachCell(function(cell){
				cell.font={bold:true,color:{argb:'FFFFFFFF'},size:10};
				cell.alignment={vertical:'middle',horizontal:'center',wrapText:true};
				cell.fill={type:'pattern',pattern:'solid',fgColor:{argb:'FFDB2777'}};
				cell.border={top:{style:'thin',color:{argb:'FFFBCFE8'}},left:{style:'thin',color:{argb:'FFFBCFE8'}},bottom:{style:'thin',color:{argb:'FFFBCFE8'}},right:{style:'thin',color:{argb:'FFFBCFE8'}}};
			});
			sheet.columns=[{width:9},{width:24},{width:12},{width:14},{width:14},{width:16},{width:10},{width:10},{width:10},{width:12},{width:14},{width:14}];
			data.forEach(function(r,idx){
				var row=sheet.addRow([r.sl_no||'',r.item_name||'',r.item||'',r.width_mm||'',r.length_mtr||'',r.paper_type||'',r.gsm||'',r.dia||'',r.core||'',r.size||'',r.core_type||'','Active']);
				row.eachCell(function(cell){
					cell.alignment={vertical:'middle',horizontal:'center'};
					cell.fill={type:'pattern',pattern:'solid',fgColor:{argb:idx%2===0?'FFFFF7FB':'FFFFEEF6'}};
					cell.border={top:{style:'thin',color:{argb:'FFFCE7F3'}},left:{style:'thin',color:{argb:'FFFCE7F3'}},bottom:{style:'thin',color:{argb:'FFFCE7F3'}},right:{style:'thin',color:{argb:'FFFCE7F3'}}};
				});
			});
			var buf=await workbook.xlsx.writeBuffer();
			var blob=new Blob([buf],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
			var link=document.createElement('a'); link.href=URL.createObjectURL(blob); link.download='paper-roll-modern.xlsx';
			document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(link.href);
		} else {
			var fb=data.map(function(r){return{'Sl no.':r.sl_no||'','Item Name':r.item_name||'','Item':r.item||'','Width ( mm)':r.width_mm||'','Lenght(mtr)':r.length_mtr||'','Paper Type':r.paper_type||'','GSM':r.gsm||'','Dia':r.dia||'','Core':r.core||'','Size':r.size||'','Core Type':r.core_type||''};});
			var ws=XLSX.utils.json_to_sheet(fb); var wb2=XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb2,ws,'PaperRoll'); XLSX.writeFile(wb2,'paper-roll.xlsx');
		}
	});

	// ── Print ──────────────────────────────────────────────────────────
	if (printBtn) printBtn.addEventListener('click', function(){
		var data = collectTableData();
		if (!data.length) { alert('No rows to print.'); return; }
		var cols = ['sl_no','item_name','item','width_mm','length_mtr','paper_type','gsm','dia','core','size','core_type'];
		var labels = ['Sl no.','Item Name','Item','Width (mm)','Length (mtr)','Paper Type','GSM','Dia','Core','Size','Core Type'];
		var tbl = '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:12px">';
		tbl += '<thead><tr>'+labels.map(function(l){return '<th style="background:#fce7f3">'+safeText(l)+'</th>';}).join('')+'</tr></thead><tbody>';
		tbl += data.map(function(r){return '<tr>'+cols.map(function(k){return '<td>'+safeText(r[k]||'')+'</td>';}).join('')+'</tr>';}).join('');
		tbl += '</tbody></table>';
		var w=window.open('','_blank','width=1200,height=800');
		if(!w){alert('Popup blocked.');return;}
		w.document.write('<!doctype html><html><head><title>Paper-Roll</title></head><body>');
		w.document.write('<h2 style="font-family:Arial,sans-serif">Paper-Roll Master</h2>'+tbl);
		w.document.write('</body></html>');
		w.document.close(); w.focus(); w.print();
	});
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
