<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isAdminUser = isAdmin();
$csrfToken = generateCSRF();
$moduleVersion = @filemtime(__DIR__ . '/js/finished.js') ?: time();

$appSettings  = getAppSettings();
$co_name      = $appSettings['company_name']    ?? (defined('APP_NAME') ? APP_NAME : 'ERP');
$co_tagline   = $appSettings['company_tagline'] ?? '';
$co_address   = $appSettings['company_address'] ?? '';
$co_phone     = $appSettings['company_mobile']  ?? ($appSettings['company_phone'] ?? '');
$co_email     = $appSettings['company_email']   ?? '';
$co_gst       = $appSettings['company_gst']     ?? '';
$co_logo      = ($appSettings['logo_path'] ?? '') ? BASE_URL . '/' . $appSettings['logo_path'] : '';

$pageTitle = 'Finished Goods';
include __DIR__ . '/../../../includes/header.php';
?>

<link rel="stylesheet" href="<?= e(BASE_URL) ?>/modules/inventory/finished/css/finished.css?v=<?= (int)$moduleVersion ?>">

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Finished Goods</span>
</div>

<div class="fg-stock-module"
     id="fgStockModule"
     data-api-url="<?= e(BASE_URL) ?>/modules/inventory/finished/api/finished_api.php"
  data-dispatch-url="<?= e(BASE_URL) ?>/modules/dispatch/index.php"
     data-csrf-token="<?= e($csrfToken) ?>"
     data-is-admin="<?= $isAdminUser ? '1' : '0' ?>"
     data-co-name="<?= e($co_name) ?>"
     data-co-tagline="<?= e($co_tagline) ?>"
     data-co-address="<?= e($co_address) ?>"
     data-co-phone="<?= e($co_phone) ?>"
     data-co-email="<?= e($co_email) ?>"
     data-co-gst="<?= e($co_gst) ?>"
     data-co-logo="<?= e($co_logo) ?>">

  <div class="page-header fg-page-header">
    <div>
      <h1>Finished Good Stock</h1>
      <p>Modular inventory board with tab-wise stock control, summary, and API-based operations.</p>
    </div>
    <div class="fg-top-actions">
      <button class="fg-act-btn orange" type="button" data-fg-action="open-add">
        <i class="bi bi-plus-circle"></i> Add Stock
      </button>
      <?php if ($isAdminUser): ?>
      <button class="fg-act-btn blue" type="button" data-fg-action="open-import">
        <i class="bi bi-upload"></i> Import Excel
      </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card fg-card">
    <div class="fg-header-strip" id="fgHeaderStrip"></div>

    <div class="fg-tab-wrap">
      <div class="fg-tabs" id="fgTabs" role="tablist" aria-label="Finished Goods Category Tabs"></div>
    </div>

    <div class="fg-summary" id="fgSummaryCards">
      <div class="fg-summary-card fg-card-total-items">
        <small><i class="bi bi-box-seam"></i> Total Items</small>
        <strong id="fgSummaryItems">0</strong>
      </div>
      <div class="fg-summary-card fg-card-total-qty">
        <small><i class="bi bi-123"></i> Total Quantity</small>
        <strong id="fgSummaryQty">0</strong>
      </div>
      <div class="fg-summary-card fg-card-opening">
        <small><i class="bi bi-archive"></i> Opening Stock</small>
        <strong id="fgSummaryOpening">0</strong>
      </div>
      <div class="fg-summary-card fg-card-inward">
        <small><i class="bi bi-box-arrow-in-down"></i> Inward</small>
        <strong id="fgSummaryInward">0</strong>
      </div>
      <div class="fg-summary-card fg-card-dispatch">
        <small><i class="bi bi-truck"></i> Dispatch</small>
        <strong id="fgSummaryDispatch">0</strong>
      </div>
      <div class="fg-summary-card fg-card-closing">
        <small><i class="bi bi-check2-circle"></i> Closing Stock</small>
        <strong id="fgSummaryClosing">0</strong>
      </div>
    </div>

    <div class="fg-report-months" id="fgReportMonths" style="display:none"></div>

    <div class="fg-item-summary-box" id="fgItemSummaryBox">
      <div class="fg-item-summary-head">
        <strong>Item-wise Quantity Summary</strong>
        <span id="fgItemSummaryMeta">0 item(s)</span>
      </div>
      <div class="fg-item-summary-controls">
        <select id="fgItemSummarySelect">
          <option value="">No item found</option>
        </select>
        <button type="button" class="fg-act-btn blue" data-fg-action="view-item-details" id="fgViewItemDetailsBtn" disabled>
          <i class="bi bi-box-arrow-up-right"></i> View Details
        </button>
      </div>
      <div class="fg-item-summary-total" id="fgItemSummaryTotal">Selected Item Total: 0 PCS</div>
    </div>

    <div class="fg-toolbar">
      <div class="fg-toolbar-left">
        <div class="fg-search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="fgSearchInput" placeholder="Search in current tab...">
        </div>
        <div class="fg-report-filter-wrap">
          <i class="bi bi-calendar3"></i>
          <select id="fgReportPeriod">
            <option value="day">Today</option>
            <option value="week">This Week</option>
            <option value="month" selected>This Month</option>
            <option value="year">This Year</option>
            <option value="last_3_months">Last 3 Months</option>
          </select>
        </div>
      </div>
      <div class="fg-toolbar-right">
        <button type="button" class="fg-act-btn" data-fg-action="toggle-columns">
          <i class="bi bi-sliders2"></i> Columns
        </button>
        <button type="button" class="fg-act-btn" id="fgResetFilterBtn" data-fg-action="reset-filter" disabled style="opacity:0.45">
          <i class="bi bi-x-circle"></i> Reset Filter
        </button>
        <button type="button" class="fg-act-btn green" data-fg-action="export-excel">
          <i class="bi bi-file-earmark-excel"></i> Export Excel
        </button>
        <button type="button" class="fg-act-btn red" data-fg-action="export-pdf">
          <i class="bi bi-file-earmark-pdf"></i> Export PDF
        </button>
        <button type="button" class="fg-act-btn blue" data-fg-action="print-view">
          <i class="bi bi-printer"></i> Print
        </button>
      </div>
    </div>

    <div class="fg-column-menu" id="fgColumnMenu" style="display:none"></div>

    <div class="fg-table-wrap">
      <table class="fg-table" id="fgTable">
        <thead id="fgTableHead"></thead>
        <tbody id="fgTableBody">
          <tr><td class="fg-empty" colspan="99">Loading...</td></tr>
        </tbody>
      </table>
    </div>

    <div class="fg-pagination" id="fgPagination"></div>
  </div>

  <div class="modal-overlay fg-modal" id="fgEntryModal" style="display:none" aria-hidden="true">
    <div class="modal-card fg-modal-card">
      <div class="modal-head fg-modal-head">
        <h3 id="fgEntryModalTitle">Add Stock Entry</h3>
        <button type="button" class="fg-act-btn" data-fg-action="close-entry-modal"><i class="bi bi-x"></i> Close</button>
      </div>
      <form id="fgEntryForm" class="modal-body-pad fg-modal-body">
        <div class="fg-form-grid" id="fgEntryFormGrid"></div>
        <div class="fg-modal-actions">
          <button type="button" class="btn" data-fg-action="close-entry-modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="fgEntrySubmitBtn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay fg-modal" id="fgImportModal" style="display:none" aria-hidden="true">
    <div class="modal-card fg-modal-card fg-import-card">
      <div class="modal-head fg-modal-head">
        <h3>Excel Import With Mapping</h3>
        <button type="button" class="btn btn-sm" data-fg-action="close-import-modal">Close</button>
      </div>
      <div class="modal-body-pad fg-modal-body">
        <div class="fg-import-top">
          <input type="file" id="fgImportFile" accept=".xlsx,.xls,.csv">
          <button type="button" class="btn btn-secondary" data-fg-action="parse-import">Parse File</button>
        </div>
        <div class="fg-import-note">Upload Excel or CSV, map columns, preview, then import via API.</div>
        <div id="fgImportMapping" class="fg-import-mapping"></div>
        <div id="fgImportPreview" class="fg-import-preview"></div>
        <div class="fg-modal-actions">
          <button type="button" class="btn" data-fg-action="close-import-modal">Cancel</button>
          <button type="button" class="btn btn-primary" data-fg-action="submit-import">Import Rows</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="<?= e(BASE_URL) ?>/modules/inventory/finished/js/finished.js?v=<?= (int)$moduleVersion ?>"></script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
