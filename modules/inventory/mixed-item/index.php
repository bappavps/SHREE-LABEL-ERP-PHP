<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$pageTitle = 'Mixed Item';
$csrfToken = generateCSRF();
$moduleVersion = @filemtime(__DIR__ . '/js/mixed_item.js') ?: time();

include __DIR__ . '/../../../includes/header.php';
?>

<link rel="stylesheet" href="<?= e(BASE_URL) ?>/modules/inventory/mixed-item/css/mixed_item.css?v=<?= (int)$moduleVersion ?>">

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Mixed Item</span>
</div>

<div class="mix-page" id="mixPage"
     data-api-url="<?= e(BASE_URL) ?>/modules/inventory/mixed-item/api/mixed_item_api.php"
     data-csrf-token="<?= e($csrfToken) ?>"
     data-packing-url="<?= e(BASE_URL) ?>/modules/packing/index.php"
     data-planning-url="<?= e(BASE_URL) ?>/modules/planning/packing/index.php">

  <div class="page-header mix-head">
    <div>
      <h1>Mixed Item</h1>
      <p>Extra production pool by category. Select single/multiple rows and hand over to packing or planning.</p>
    </div>
    <div class="mix-head-actions">
      <select id="mixAssignTarget">
        <option value="packing">Packing Operator</option>
        <option value="planning">Planning</option>
      </select>
      <button type="button" class="mix-btn mix-btn-green" id="mixAssignBtn">Send Selected</button>
      <button type="button" class="mix-btn" id="mixOpenBoardBtn">Open Target Board</button>
    </div>
  </div>

  <div class="card mix-card">
    <div class="mix-tabs" id="mixTabs" role="tablist" aria-label="Mixed item category tabs"></div>

    <div class="mix-summary" id="mixSummary">
      <div class="mix-pill">
        <small>Total Item</small>
        <strong id="mixTotalItems">0</strong>
      </div>
      <div class="mix-pill">
        <small>Total Extra</small>
        <strong id="mixTotalExtra">0</strong>
      </div>
      <div class="mix-pill">
        <small>Selected</small>
        <strong id="mixSelectedCount">0</strong>
      </div>
    </div>

    <div class="mix-toolbar">
      <input id="mixSearch" type="text" placeholder="Search item, batch, size...">
    </div>

    <div class="mix-table-wrap">
      <table class="mix-table">
        <thead id="mixThead"></thead>
        <tbody id="mixTbody">
          <tr><td class="mix-empty" colspan="99">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="<?= e(BASE_URL) ?>/modules/inventory/mixed-item/js/mixed_item.js?v=<?= (int)$moduleVersion ?>"></script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
