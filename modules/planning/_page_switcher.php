<?php
$planningPageKey = trim((string)($planningPageKey ?? 'label-printing'));
$planningPageRoutes = [
    'label-printing' => [
        'label' => 'Label Printing',
        'url' => appUrl('/modules/planning/label/index.php'),
    ],
    'jumbo-slitting' => [
        'label' => 'Jumbo Slitting',
        'url' => appUrl('/modules/planning/slitting/index.php'),
    ],
    'printing' => [
        'label' => 'Printing',
        'url' => appUrl('/modules/planning/printing/index.php'),
    ],
    'die-cutting' => [
        'label' => 'Die-Cutting',
        'url' => appUrl('/modules/planning/flatbed/index.php'),
    ],
    'barcode' => [
      'label' => 'Barcode',
      'url' => appUrl('/modules/planning/barcode/index.php'),
    ],
    'label-slitting' => [
        'label' => 'Label Slitting',
        'url' => appUrl('/modules/planning/label-slitting/index.php'),
    ],
    'batch-printing' => [
        'label' => 'Batch Printing',
        'url' => appUrl('/modules/planning/batch/index.php'),
    ],
    'packaging' => [
        'label' => 'Packaging',
        'url' => appUrl('/modules/planning/packing/index.php'),
    ],
    'dispatch' => [
        'label' => 'Dispatch',
        'url' => appUrl('/modules/planning/dispatch/index.php'),
    ],
];

if (!isset($planningPageRoutes[$planningPageKey])) {
    $planningPageKey = 'label-printing';
}

$planningPageRouteMap = [];
foreach ($planningPageRoutes as $routeKey => $routeMeta) {
    $planningPageRouteMap[$routeKey] = (string)($routeMeta['url'] ?? '');
}
?>
<style>
.planning-page-switcher-shell{margin:0 0 16px;display:flex;justify-content:flex-end}
.planning-page-switcher{padding:10px 12px;border:1px solid #d9e5f2;border-radius:12px;background:#f8fbff;display:flex;align-items:center;gap:10px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.planning-page-switcher-label{font-size:.78rem;font-weight:800;color:#475569;white-space:nowrap}
.planning-page-switcher-select{min-width:210px;max-width:210px;width:210px;font-weight:700;background:#fff}
@media(max-width:700px){.planning-page-switcher-shell{justify-content:stretch}.planning-page-switcher{width:100%;flex-direction:column;align-items:stretch}.planning-page-switcher-select{width:100%;min-width:0;max-width:none}}
</style>

<div class="planning-page-switcher-shell no-print">
  <div class="planning-page-switcher">
    <span class="planning-page-switcher-label">Department</span>
    <select id="planning-page-switch" class="form-control planning-page-switcher-select" aria-label="Planning department switcher">
      <?php foreach ($planningPageRoutes as $routeKey => $routeMeta): ?>
        <option value="<?= e($routeKey) ?>" <?= $routeKey === $planningPageKey ? 'selected' : '' ?>><?= e($routeMeta['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<script>
(function(){
  var pageSwitch = document.getElementById('planning-page-switch');
  if (!pageSwitch || pageSwitch.dataset.bound === '1') {
    return;
  }
  pageSwitch.dataset.bound = '1';

  var planningPageRouteMap = <?= json_encode($planningPageRouteMap, JSON_UNESCAPED_SLASHES) ?>;
  pageSwitch.addEventListener('change', function(){
    var selected = String(pageSwitch.value || '').trim();
    if (!planningPageRouteMap[selected]) {
      return;
    }
    window.location.href = planningPageRouteMap[selected];
  });
})();
</script>