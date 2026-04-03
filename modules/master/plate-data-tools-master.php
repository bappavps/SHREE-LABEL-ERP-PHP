<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Plate Data & Tools Master';

$tabItems = [
	['key' => 'plate_management_master', 'label' => 'Plate Management Master', 'icon' => 'grid'],
	['key' => 'flatbed_printing_die_master', 'label' => 'Flatbed Printing Die Master', 'icon' => 'layout-wtf'],
	['key' => 'rotary_printing_die_master', 'label' => 'Rotary Printing Die Master', 'icon' => 'gear-wide-connected'],
	['key' => 'flatbed_barcode_die_master', 'label' => 'Flatbed Barcode Die Master', 'icon' => 'layout-split'],
	['key' => 'rotary_barcode_die_master', 'label' => 'Rotary Barcode Die Master', 'icon' => 'qr-code-scan'],
	['key' => 'magnetic_printing_cylinder_master', 'label' => 'Magnetic Printing Cylinder Master', 'icon' => 'circle-square'],
	['key' => 'sheeter_printing_cylinder_master', 'label' => 'Sheeter Printing Cylinder Master', 'icon' => 'layers-half'],
	['key' => 'magnetic_barcode_cylinder_master', 'label' => 'Magnetic Barcode Cylinder Master', 'icon' => 'bullseye'],
	['key' => 'anilox_master', 'label' => 'Anilox Master', 'icon' => 'droplet-half'],
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
	'flatbed_barcode_die_master',
	'rotary_barcode_die_master',
	'anilox_master',
];

$dieManagementTabs = [
	'flatbed_printing_die_master',
	'rotary_printing_die_master',
	'flatbed_barcode_die_master',
	'rotary_barcode_die_master',
];
$printingDieTabs = [
	'flatbed_printing_die_master',
	'rotary_printing_die_master',
];
$barcodeDieTabs = [
	'flatbed_barcode_die_master',
	'rotary_barcode_die_master',
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
	} elseif ($activeTab === 'flatbed_barcode_die_master') {
		$dieToolingEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=flatbed_barcode_die_master';
		$dieToolingPageTitleOverride = 'Flatbed Barcode Die Master';
		$dieToolingEntityLabelOverride = 'Flatbed Barcode Die';
		$dieToolingDieTypeScope = 'flatbed';
		$dieToolingDieTypeScopeLabel = 'Flatbed';
		require __DIR__ . '/../die-tooling/index.php';
	} elseif ($activeTab === 'rotary_barcode_die_master') {
		$dieToolingEmbedded = true;
		$dieToolingRedirectUrlOverride = $tabBasePath . '?tab=rotary_barcode_die_master';
		$dieToolingPageTitleOverride = 'Rotary Barcode Die Master';
		$dieToolingEntityLabelOverride = 'Rotary Barcode Die';
		$dieToolingDieTypeScope = 'rotary';
		$dieToolingDieTypeScopeLabel = 'Rotary';
		require __DIR__ . '/../die-tooling/index.php';
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
@keyframes ptmFade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
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
			<?php else: ?>
				<div class="ptm-empty">
					<i class="bi bi-tools"></i>
					<div><?= e($tab['label']) ?> section ready for implementation.</div>
				</div>
			<?php endif; ?>
		</div>
	</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
