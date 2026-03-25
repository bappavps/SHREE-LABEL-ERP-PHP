<?php
// ============================================================
// Shree Label ERP — Sidebar Navigation
// ============================================================

$currentFile = $_SERVER['PHP_SELF'] ?? '';

function navItem($href, $icon, $label, $currentFile) {
    $isActive = strpos($currentFile, $href) !== false;
    return '<a href="' . BASE_URL . $href . '" class="nav-item' . ($isActive ? ' active' : '') . '">'
         . '<i class="bi bi-' . $icon . '"></i>'
         . '<span>' . e($label) . '</span>'
         . '</a>';
}
?>
<nav class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-layers"></i></div>
    <span class="brand-name"><?= APP_NAME ?></span>
  </div>

  <div class="sidebar-nav">

    <p class="nav-section-label">Overview</p>
    <?= navItem('/modules/dashboard/index.php',    'grid',             'Dashboard',       $currentFile) ?>

    <p class="nav-section-label">Sales</p>
    <?= navItem('/modules/estimate/index.php',     'calculator',       'Estimator',       $currentFile) ?>
    <?= navItem('/modules/sales_order/index.php',  'bag-check',        'Sales Orders',    $currentFile) ?>

    <p class="nav-section-label">Inventory</p>
    <?= navItem('/modules/paper_stock/index.php',  'stack',            'Paper Stock',     $currentFile) ?>
    <?= navItem('/modules/stock-import/index.php', 'cloud-upload',     'Import & Export',  $currentFile) ?>

    <p class="nav-section-label">Production</p>
    <?= navItem('/modules/planning/index.php',     'kanban',           'Planning',        $currentFile) ?>

    <p class="nav-section-label">Administration</p>
    <?= navItem('/modules/users/index.php',        'people',           'Users',           $currentFile) ?>

  </div>

  <div class="sidebar-footer">
    <?= navItem('/auth/logout.php', 'box-arrow-left', 'Logout', '') ?>
    <style>.sidebar-footer .nav-item { color: #f87171; }
           .sidebar-footer .nav-item:hover { background: rgba(239,68,68,.12); }</style>
  </div>
</nav>
