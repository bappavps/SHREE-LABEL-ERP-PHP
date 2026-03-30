<?php
// ============================================================
// ERP System — Sidebar Navigation
// ============================================================

$currentFile = $_SERVER['PHP_SELF'] ?? '';
$appSettings = function_exists('getAppSettings') ? getAppSettings() : [];
$sidebarCompanyName = trim((string)($appSettings['company_name'] ?? ''));
$sidebarCompanyName = function_exists('getErpDisplayName') ? getErpDisplayName($sidebarCompanyName) : APP_NAME;
$sidebarLogoPath = (string)($appSettings['logo_path'] ?? '');

function navItem($href, $icon, $label, $currentFile) {
    if (function_exists('canAccessPath') && !canAccessPath($href)) {
        return '';
    }
    $isActive = strpos($currentFile, $href) !== false;
    return '<a href="' . BASE_URL . $href . '" class="nav-item' . ($isActive ? ' active' : '') . '">'
         . '<i class="bi bi-' . $icon . '"></i>'
         . '<span>' . e($label) . '</span>'
         . '</a>';
}

function navSubItem($href, $label, $currentFile, $aliases = [], $extraClass = '', $icon = '') {
  if (function_exists('canAccessPath') && !canAccessPath($href)) {
    return '';
  }
  $isActive = strpos($currentFile, $href) !== false;
  if (!$isActive && !empty($aliases)) {
    foreach ($aliases as $alias) {
      if (strpos($currentFile, $alias) !== false) {
        $isActive = true;
        break;
      }
    }
  }
  $iconHtml = $icon ? '<i class="bi bi-' . $icon . '" style="font-size:12px;opacity:.7;min-width:16px;text-align:center"></i>' : '';
  return '<a href="' . BASE_URL . $href . '" class="nav-sub-item' . ($extraClass ? ' ' . trim($extraClass) : '') . ($isActive ? ' active' : '') . '">'
     . $iconHtml
     . '<span>' . e($label) . '</span>'
     . '</a>';
}
?>
<nav class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">
      <?php if ($sidebarLogoPath !== ''): ?>
        <img src="<?= e(appUrl($sidebarLogoPath)) ?>" alt="Logo" class="sidebar-logo-img">
      <?php else: ?>
        <i class="bi bi-layers"></i>
      <?php endif; ?>
    </div>
    <span class="brand-name"><?= e($sidebarCompanyName) ?></span>
  </div>

  <div class="sidebar-nav">

    <?= navItem('/modules/dashboard/index.php',    'grid',             'Dashboard',       $currentFile) ?>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-calculator"></i><span>Sales &amp; Estimating</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/estimate/index.php',   'Calculator', $currentFile, ['/modules/calculator/index.php'], '', 'calculator') ?>
        <?= navSubItem('/modules/estimates/index.php',  'Estimates',  $currentFile, [], '', 'file-earmark-text') ?>
        <?= navSubItem('/modules/quotations/index.php', 'Quotations', $currentFile, [], '', 'card-text') ?>
        <?= navSubItem('/modules/sales_order/index.php','Sales Orders', $currentFile, [], '', 'cart-check') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-palette"></i><span>Design &amp; Prepress</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/artwork/index.php',  'Artwork Gallery', $currentFile, [], '', 'palette2') ?>
        <div class="nav-sub-nest">
          <a href="#" class="nav-sub-parent-toggle" aria-expanded="false">
            <span>Job Planning</span>
            <i class="bi bi-chevron-down"></i>
          </a>
          <div class="nav-sub-children">
            <?= navSubItem('/modules/planning/label/index.php',          'Label Printing',  $currentFile, [], 'nav-sub-item-nested', 'tag') ?>
            <?= navSubItem('/modules/planning/slitting/index.php',       'Jumbo Slitting',  $currentFile, [], 'nav-sub-item-nested', 'scissors') ?>
            <?= navSubItem('/modules/planning/printing/index.php',       'Printing',        $currentFile, [], 'nav-sub-item-nested', 'printer') ?>
            <?= navSubItem('/modules/planning/flatbed/index.php',        'Flatbed',         $currentFile, [], 'nav-sub-item-nested', 'layout-wtf') ?>
            <?= navSubItem('/modules/planning/rotery/index.php',         'Rotery Die',      $currentFile, [], 'nav-sub-item-nested', 'gear-wide-connected') ?>
            <?= navSubItem('/modules/planning/label-slitting/index.php', 'Label Slitting',  $currentFile, [], 'nav-sub-item-nested', 'layout-split') ?>
            <?= navSubItem('/modules/planning/batch/index.php',          'Batch Printing',  $currentFile, [], 'nav-sub-item-nested', 'stack') ?>
            <?= navSubItem('/modules/planning/packing/index.php',        'Packaging',       $currentFile, [], 'nav-sub-item-nested', 'box') ?>
            <?= navSubItem('/modules/planning/dispatch/index.php',       'Dispatch',        $currentFile, [], 'nav-sub-item-nested', 'truck') ?>
          </div>
        </div>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-person-gear"></i><span>Machine Operator</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/operators/jumbo/index.php',          'Jumbo Operator',           $currentFile, [], 'nav-sub-item-nested', 'device-ssd') ?>
        <?= navSubItem('/modules/operators/pos/index.php',            'POS Roll Operator',        $currentFile, [], 'nav-sub-item-nested', 'receipt') ?>
        <?= navSubItem('/modules/operators/oneply/index.php',         'Only Ply Operator',        $currentFile, [], 'nav-sub-item-nested', 'layers') ?>
        <?= navSubItem('/modules/operators/printing/index.php',       'Flexo Operator',           $currentFile, [], 'nav-sub-item-nested', 'printer') ?>
        <?= navSubItem('/modules/operators/flatbed/index.php',        'Flat Bed Operator',        $currentFile, [], 'nav-sub-item-nested', 'layout-wtf') ?>
        <?= navSubItem('/modules/operators/rotery/index.php',         'Rotery Die Operator',      $currentFile, [], 'nav-sub-item-nested', 'gear-wide-connected') ?>
        <?= navSubItem('/modules/operators/label-slitting/index.php', 'Label Slitting Operator',  $currentFile, [], 'nav-sub-item-nested', 'layout-split') ?>
        <?= navSubItem('/modules/operators/packing/index.php',        'Packing Operator',         $currentFile, [], 'nav-sub-item-nested', 'box-seam') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-box-seam"></i><span>Inventory Hub</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/paper_stock/index.php', 'Paper Stock', $currentFile, ['/modules/paper-stock/index.php'], '', 'journal-text') ?>
        <div class="nav-sub-nest">
          <a href="#" class="nav-sub-parent-toggle" aria-expanded="false">
            <span>Physical Stock Check</span>
            <i class="bi bi-chevron-down"></i>
          </a>
          <div class="nav-sub-children">
            <?= navSubItem('/modules/audit/index.php', 'Audit Hub', $currentFile, [], 'nav-sub-item-nested', 'clipboard-check') ?>
            <?= navSubItem('/modules/scan/index.php', 'Scan Terminal', $currentFile, [], 'nav-sub-item-nested', 'upc-scan') ?>
          </div>
        </div>
        <?= navSubItem('/modules/inventory/slitting/index.php', 'Slitting', $currentFile, [], '', 'scissors') ?>
        <?= navSubItem('/modules/inventory/finished/index.php', 'Finished Good', $currentFile, [], '', 'check2-circle') ?>
        <?= navSubItem('/modules/inventory/die/index.php', 'Die Tooling', $currentFile, [], '', 'tools') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-diagram-3"></i><span>Production</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <div class="nav-sub-nest">
          <a href="#" class="nav-sub-parent-toggle" aria-expanded="false">
            <span>Job Cards</span>
            <i class="bi bi-chevron-down"></i>
          </a>
          <div class="nav-sub-children">
            <?= navSubItem('/modules/jobs/jumbo/index.php',          'Jumbo Job',       $currentFile, [], 'nav-sub-item-nested', 'device-ssd') ?>
            <?= navSubItem('/modules/jobs/pos/index.php',            'POS Roll',        $currentFile, [], 'nav-sub-item-nested', 'receipt') ?>
            <?= navSubItem('/modules/jobs/oneply/index.php',         'One Ply',         $currentFile, [], 'nav-sub-item-nested', 'layers') ?>
            <?= navSubItem('/modules/jobs/printing/index.php',       'Flexo Printing',  $currentFile, [], 'nav-sub-item-nested', 'printer') ?>
            <?= navSubItem('/modules/jobs/flatbed/index.php',        'Flat Bed',        $currentFile, [], 'nav-sub-item-nested', 'layout-wtf') ?>
            <?= navSubItem('/modules/jobs/rotery/index.php',         'Rotery Die',      $currentFile, [], 'nav-sub-item-nested', 'gear-wide-connected') ?>
            <?= navSubItem('/modules/jobs/label-slitting/index.php', 'Label Slitting',  $currentFile, [], 'nav-sub-item-nested', 'layout-split') ?>
            <?= navSubItem('/modules/jobs/packing/index.php',        'Packing Slip',    $currentFile, [], 'nav-sub-item-nested', 'box-seam') ?>
          </div>
        </div>
        <?= navSubItem('/modules/bom/index.php',  'BOM Master', $currentFile, [], '', 'diagram-3') ?>
        <?= navSubItem('/modules/live/index.php', 'Live Floor', $currentFile, [], '', 'broadcast') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-bag-check"></i><span>Purchase</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/purchase/index.php', 'Purchase Order', $currentFile, [], '', 'cart3') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-patch-check"></i><span>Quality &amp; Logistic</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/qc/index.php',       'QC Report', $currentFile, [], '', 'clipboard2-check') ?>
        <?= navSubItem('/modules/dispatch/index.php', 'Dispatch',  $currentFile, [], '', 'truck') ?>
        <?= navSubItem('/modules/billing/index.php',  'Billing',   $currentFile, [], '', 'receipt-cutoff') ?>
      </div>
    </div>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-graph-up-arrow"></i><span>Analytics</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/performance/index.php', 'Performance', $currentFile, [], '', 'speedometer2') ?>
        <?= navSubItem('/modules/reports/index.php',     'Reports',     $currentFile, [], '', 'bar-chart-line') ?>
        <?= navSubItem('/modules/reports/jobs.php',      'Job Reports', $currentFile, [], '', 'briefcase') ?>
      </div>
    </div>

    <?= navItem('/modules/approval/index.php', 'check2-square', 'Job Approval', $currentFile) ?>

    <div class="nav-group">
      <a href="#" class="nav-item nav-group-toggle" aria-expanded="false">
        <span class="nav-item-main"><i class="bi bi-gear"></i><span>Administration</span></span>
        <i class="bi bi-chevron-down"></i>
      </a>
      <div class="nav-sub">
        <?= navSubItem('/modules/master/index.php',       'Master Data',              $currentFile, [], '', 'database') ?>
        <?= navSubItem('/modules/stock-import/index.php', 'Stock Import and Export',  $currentFile, [], '', 'arrow-left-right') ?>
        <?= navSubItem('/modules/users/index.php',        'User Management',          $currentFile, [], '', 'people') ?>
        <?= navSubItem('/modules/users/groups.php',       'Groups & Permissions',     $currentFile, [], '', 'shield-check') ?>
        <?= navSubItem('/modules/print/index.php',        'Print Studio',             $currentFile, [], '', 'printer') ?>
        <?= navSubItem('/modules/pricing/index.php',      'Pricing Login',            $currentFile, [], '', 'currency-rupee') ?>
        <?= navSubItem('/modules/settings/index.php',     'Settings',                 $currentFile, [], '', 'sliders') ?>
        <?= navSubItem('/modules/settings/index.php?tab=backup', 'Backup & Restore',  $currentFile, ['/modules/settings/index.php'], '', 'cloud-arrow-down') ?>
      </div>
    </div>

  </div>

  <div class="sidebar-footer">
    <?= navItem('/auth/logout.php', 'box-arrow-left', 'Logout', '') ?>
    <style>.sidebar-footer .nav-item { color: #f87171; }
           .sidebar-footer .nav-item:hover { background: rgba(239,68,68,.12); }</style>
  </div>
</nav>

<script>
(function(){
  var sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;

  // Remove nested parents that have no visible sub-items.
  sidebar.querySelectorAll('.nav-sub-nest').forEach(function(nest){
    var hasChild = !!nest.querySelector('.nav-sub-children .nav-sub-item');
    if (!hasChild) {
      nest.style.display = 'none';
    }
  });

  // Hide top-level groups that have no visible links.
  sidebar.querySelectorAll('.nav-group').forEach(function(group){
    var hasDirect = !!group.querySelector('.nav-sub > .nav-sub-item');
    var hasNested = !!group.querySelector('.nav-sub-nest:not([style*="display: none"]) .nav-sub-item');
    if (!hasDirect && !hasNested) {
      group.style.display = 'none';
    }
  });
})();
</script>
