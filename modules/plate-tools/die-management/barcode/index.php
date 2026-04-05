<?php
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/auth_check.php';

$barcodeDieWorkspaceEmbedded = !empty($barcodeDieWorkspaceEmbedded);
$barcodeDieWorkspaceMode = isset($barcodeDieWorkspaceModeOverride) && trim((string)$barcodeDieWorkspaceModeOverride) !== ''
  ? (((string)$barcodeDieWorkspaceModeOverride === 'design') ? 'design' : 'master')
  : ((($_GET['mode'] ?? 'design') === 'design') ? 'design' : 'master');
$barcodeDieWorkspaceBasePath = isset($barcodeDieWorkspaceBasePathOverride) && trim((string)$barcodeDieWorkspaceBasePathOverride) !== ''
    ? trim((string)$barcodeDieWorkspaceBasePathOverride)
    : (BASE_URL . '/modules/plate-tools/die-management/barcode/index.php?mode=' . $barcodeDieWorkspaceMode);
$barcodeDieWorkspacePageTitle = $barcodeDieWorkspaceMode === 'design' ? 'Barcode Die' : 'Barcode Die Master';
$barcodeDieWorkspaceHeading = $barcodeDieWorkspaceMode === 'design' ? 'Barcode Die' : 'Barcode Die Master';
$barcodeDieWorkspaceSubheading = $barcodeDieWorkspaceMode === 'design'
  ? 'Flatbed and Rotary barcode die entries are shown together in one combined workspace.'
  : 'Flatbed and Rotary barcode die master entries are shown together in one combined workspace.';

ob_start();
$_GET['mode'] = $barcodeDieWorkspaceMode;
$dieToolingEmbedded = true;
$dieToolingRedirectUrlOverride = $barcodeDieWorkspaceBasePath;
$dieToolingAllowedDieTypesOverride = ['Flatbed', 'Rotary'];
$dieToolingEntityLabelOverride = 'Barcode Die';
$dieToolingPageTitleOverride = $barcodeDieWorkspacePageTitle;
$dieToolingDieTypeScope = '';
$dieToolingDieTypeScopeLabel = '';
require __DIR__ . '/../../../die-tooling/index.php';
$barcodeDieWorkspaceHtml = ob_get_clean();

if (!$barcodeDieWorkspaceEmbedded) {
    $pageTitle = $barcodeDieWorkspacePageTitle;
  include __DIR__ . '/../../../../includes/header.php';
}
?>

<style>
.bdw-card{border:1px solid var(--border);border-radius:12px;background:#fff;margin-bottom:14px}
.bdw-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;padding:14px 16px;border-bottom:1px solid #eef2f7}
.bdw-title{display:flex;align-items:center;gap:8px;font-size:1.05rem;font-weight:800;color:#0f172a}.bdw-sub{margin-top:4px;font-size:.82rem;color:#64748b}
.bdw-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.bdw-panel{padding:0 0 2px}.bdw-panel .card:first-child{margin-top:0}
</style>

<?php if (!$barcodeDieWorkspaceEmbedded): ?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Plate Tools</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Die Management</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Barcode Die</span>
</div>
<?php endif; ?>

<div class="bdw-card">
  <div class="bdw-head">
    <div>
      <div class="bdw-title"><i class="bi bi-upc-scan"></i> <?= e($barcodeDieWorkspaceHeading) ?></div>
      <div class="bdw-sub"><?= e($barcodeDieWorkspaceSubheading) ?></div>
    </div>
    <span class="bdw-chip"><i class="bi bi-collection"></i> Flatbed + Rotary Combined</span>
  </div>
</div>

<section class="bdw-panel" role="tabpanel">
  <?= $barcodeDieWorkspaceHtml ?>
</section>

<?php if (!$barcodeDieWorkspaceEmbedded) include __DIR__ . '/../../../../includes/footer.php'; ?>