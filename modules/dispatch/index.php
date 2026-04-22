<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$csrfToken = generateCSRF();
$moduleVersion = time();

$appSettings  = getAppSettings();
$co_name      = $appSettings['company_name']    ?? (defined('APP_NAME') ? APP_NAME : 'ERP');
$co_tagline   = $appSettings['company_tagline'] ?? '';
$co_address   = $appSettings['company_address'] ?? '';
$co_phone     = $appSettings['company_mobile']  ?? ($appSettings['company_phone'] ?? '');
$co_email     = $appSettings['company_email']   ?? '';
$co_gst       = $appSettings['company_gst']     ?? '';
$co_logo      = ($appSettings['logo_path'] ?? '') ? BASE_URL . '/' . $appSettings['logo_path'] : '';

$pageTitle = 'Dispatch';
include __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?= e(BASE_URL) ?>/modules/dispatch/css/dispatch.css?v=<?= (int)$moduleVersion ?>">

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Quality & Logistics</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Dispatch</span>
</div>

<div class="page-header">
  <div>
    <h1>Dispatch Entry System</h1>
    <p>Manage finished goods dispatches, challans, transport tracking, and delivery statuses in one workspace.</p>
  </div>
</div>

<div class="dispatch-module"
     id="dispatchModule"
     data-api-url="<?= e(BASE_URL) ?>/modules/dispatch/api/dispatch_api.php"
     data-csrf-token="<?= e($csrfToken) ?>"
     data-co-name="<?= e($co_name) ?>"
     data-co-tagline="<?= e($co_tagline) ?>"
     data-co-address="<?= e($co_address) ?>"
     data-co-phone="<?= e($co_phone) ?>"
     data-co-email="<?= e($co_email) ?>"
     data-co-gst="<?= e($co_gst) ?>"
     data-co-logo="<?= e($co_logo) ?>">

  <div class="ds-tab-nav" role="tablist" aria-label="Dispatch views">
    <button type="button" class="ds-tab-btn is-active" data-ds-tab="operations" aria-selected="true">Dispatch Operations</button>
    <button type="button" class="ds-tab-btn" data-ds-tab="reports" aria-selected="false">Dispatch Reports</button>
  </div>

  <section class="ds-tab-panel is-active ds-panel-operations" data-ds-panel="operations">

  <div class="card ds-card ds-summary-card-wrap ds-tone-indigo">
    <div class="ds-summary-grid">
      <div class="ds-kpi ds-kpi-blue">
        <span><i class="bi bi-truck"></i> Total Dispatches</span>
        <strong id="dsKpiTotalDispatch">0</strong>
        <small>All shipment records</small>
      </div>
      <div class="ds-kpi ds-kpi-purple">
        <span><i class="bi bi-box-seam"></i> Dispatch Quantity</span>
        <strong id="dsKpiTotalQty">0</strong>
        <small>Total units dispatched</small>
      </div>
      <div class="ds-kpi ds-kpi-yellow">
        <span><i class="bi bi-hourglass-split"></i> Pending Delivery</span>
        <strong id="dsKpiPendingTransit">0</strong>
        <small>Pending and in transit</small>
      </div>
      <div class="ds-kpi ds-kpi-green">
        <span><i class="bi bi-check2-circle"></i> Delivered</span>
        <strong id="dsKpiDelivered">0</strong>
        <small>Completed deliveries</small>
      </div>
      <div class="ds-kpi ds-kpi-orange">
        <span><i class="bi bi-currency-rupee"></i> Transport Cost</span>
        <strong id="dsKpiTotalCost">0</strong>
        <small>Logistics spend</small>
      </div>
    </div>
    <div class="ds-category-summary-wrap">
      <div class="ds-category-summary-head">Category-wise Dispatch Summary</div>
      <div class="ds-category-summary-grid" id="dsCategorySummaryGrid">
        <div class="ds-category-tile"><span>POS &amp; Paper Roll</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>1 Ply</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>2 Ply</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>Barcode</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>Printing Roll</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>Ribbon</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>Core</span><strong>0</strong><small>0 dispatches</small></div>
        <div class="ds-category-tile"><span>Carton</span><strong>0</strong><small>0 dispatches</small></div>
      </div>
    </div>
  </div>

  <div class="ds-main-grid">
    <div class="card ds-card ds-card-blue ds-tone-sky">
      <div class="card-header ds-card-header">
        <span class="card-title">Dispatch Entry</span>
        <span class="ds-muted" id="dsFormModeLabel">New Entry</span>
      </div>
      <div class="ds-card-body">
        <form id="dsEntryForm" autocomplete="off">
          <input type="hidden" id="dsEntryPk" value="0">
          <div class="ds-form-grid">
            <div class="ds-field">
              <label for="dsDispatchId">Dispatch ID</label>
              <input id="dsDispatchId" type="text" readonly>
            </div>
            <div class="ds-field">
              <label for="dsEntryDate">Date</label>
              <input id="dsEntryDate" type="date" required>
            </div>
            <div class="ds-field full">
              <label for="dsClientName">Client Name</label>
              <input id="dsClientName" type="text" required>
            </div>

            <div class="ds-field">
              <label for="dsItemName">Item Name</label>
              <input id="dsItemName" type="text" required>
            </div>
            <div class="ds-field">
              <label for="dsPackingId">Packing ID</label>
              <input id="dsPackingId" type="text">
            </div>
            <div class="ds-field">
              <label for="dsBatchNo">Batch No</label>
              <input id="dsBatchNo" type="text">
            </div>
            <div class="ds-field">
              <label for="dsSize">Size</label>
              <input id="dsSize" type="text">
            </div>
            <div class="ds-field">
              <label for="dsAvailableQty">Available Qty</label>
              <input id="dsAvailableQty" type="number" step="0.001" readonly>
            </div>
            <div class="ds-field">
              <label for="dsDispatchQty">Dispatch Qty</label>
              <input id="dsDispatchQty" type="number" min="0" step="0.001" required>
            </div>
            <div class="ds-field">
              <label for="dsUnit">Unit</label>
              <select id="dsUnit">
                <option value="PCS">Pcs</option>
                <option value="Roll">Roll</option>
                <option value="Carton">Carton</option>
              </select>
            </div>

            <div class="ds-field full ds-batch-section">
              <div class="ds-batch-head">
                <label style="color: #7c3aed; font-weight: 600;">Batch-wise Dispatch</label>
                <button type="button" class="ds-btn" id="dsLoadBatchesBtn">
                  <i class="bi bi-arrow-repeat"></i> Load Batches
                </button>
              </div>
              <div class="ds-batch-wrap" id="dsBatchWrap">
                <table class="ds-batch-table" id="dsBatchTable">
                  <thead>
                    <tr>
                      <th>Batch No</th>
                      <th>Packing ID</th>
                      <th>Available Qty</th>
                      <th>Dispatch Qty</th>
                    </tr>
                  </thead>
                  <tbody id="dsBatchTableBody">
                    <tr><td colspan="4" class="ds-empty">Select item and click Load Batches.</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="ds-batch-total" id="dsBatchTotal">Total Dispatch: 0</div>
            </div>

            <div class="ds-field">
              <label for="dsInvoiceNo">Invoice Number</label>
              <input id="dsInvoiceNo" type="text" placeholder="Manual or from accounts ref">
            </div>
            <div class="ds-field">
              <label for="dsInvoiceDate">Invoice Date</label>
              <input id="dsInvoiceDate" type="date">
            </div>

            <div class="ds-field">
              <label for="dsTransportType">Transport Type</label>
              <select id="dsTransportType">
                <option value="Own Vehicle">Own Vehicle</option>
                <option value="Transport">Transport</option>
                <option value="Courier">Courier</option>
                <option value="Toto">Toto</option>
              </select>
            </div>
            <div class="ds-field">
              <label for="dsVehicleNo">Vehicle Number</label>
              <input id="dsVehicleNo" type="text">
            </div>
            <div class="ds-field">
              <label for="dsTransportName">Transport Name</label>
              <input id="dsTransportName" type="text">
            </div>
            <div class="ds-field">
              <label for="dsDriverName">Driver Name</label>
              <input id="dsDriverName" type="text">
            </div>
            <div class="ds-field">
              <label for="dsDriverPhone">Driver Phone</label>
              <input id="dsDriverPhone" type="text">
            </div>

            <div class="ds-field">
              <label for="dsTransportCost">Transport Cost</label>
              <input id="dsTransportCost" type="number" min="0" step="0.01" value="0">
            </div>
            <div class="ds-field">
              <label for="dsPaidBy">Paid By</label>
              <select id="dsPaidBy">
                <option value="Company">Company</option>
                <option value="Client">Client</option>
              </select>
            </div>

            <div class="ds-field">
              <label for="dsDispatchDate">Dispatch Date</label>
              <input id="dsDispatchDate" type="date">
            </div>
            <div class="ds-field">
              <label for="dsExpectedDeliveryDate">Expected Delivery Date</label>
              <input id="dsExpectedDeliveryDate" type="date">
            </div>
            <div class="ds-field">
              <label for="dsDeliveryStatus">Delivery Status</label>
              <select id="dsDeliveryStatus">
                <option value="Pending">Pending</option>
                <option value="In Transit">In Transit</option>
                <option value="Delivered">Delivered</option>
              </select>
            </div>

            <div class="ds-field full">
              <label for="dsRemarks">Remarks</label>
              <textarea id="dsRemarks" rows="2" placeholder="Additional dispatch notes..."></textarea>
            </div>
          </div>

          <div class="ds-form-actions">
            <button type="button" class="btn" id="dsResetBtn">Reset</button>
            <button type="submit" class="btn btn-primary" id="dsSaveBtn">
              <i class="bi bi-check2-circle"></i> Save Dispatch
            </button>
          </div>
        </form>
      </div>
    </div>

  </div>

  <div class="ds-dashboard-grid">
    <div class="card ds-card ds-card-orange ds-dashboard-left ds-tone-amber">
      <div class="card-header ds-card-header ds-table-header">
        <span class="card-title">Dispatch Entries</span>
        <div class="ds-actions">
          <button type="button" class="ds-btn ds-btn-green" id="dsExportExcelBtn"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
          <button type="button" class="ds-btn ds-btn-red" id="dsExportPdfBtn"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
          <button type="button" class="ds-btn ds-btn-blue" id="dsPrintTableBtn"><i class="bi bi-printer"></i> Print</button>
        </div>
      </div>

      <div class="ds-filter-bar">
        <div class="ds-field-inline">
          <label>From</label>
          <input type="date" id="dsFilterFrom">
        </div>
        <div class="ds-field-inline">
          <label>To</label>
          <input type="date" id="dsFilterTo">
        </div>
        <div class="ds-field-inline">
          <label>Client</label>
          <input type="text" id="dsFilterClient" placeholder="Client filter">
        </div>
        <div class="ds-field-inline">
          <label>Item</label>
          <input type="text" id="dsFilterItem" placeholder="Item filter">
        </div>
        <div class="ds-field-inline">
          <label>Status</label>
          <select id="dsFilterStatus" title="Ready to Dispatch = pending before transit, Dispatched = in transit, Delivered = client received">
            <option value="">All</option>
            <option value="Pending">Ready to Dispatch</option>
            <option value="In Transit">Dispatched</option>
            <option value="Delivered">Delivered</option>
          </select>
        </div>
        <div class="ds-search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="dsSearchInput" placeholder="Live search...">
        </div>
        <button type="button" class="ds-btn" id="dsFilterApplyBtn">Apply</button>
        <button type="button" class="ds-btn" id="dsFilterResetBtn">Reset</button>
      </div>

      <div class="ds-table-wrap">
        <table class="ds-table" id="dsTable">
          <thead>
            <tr>
              <th>Dispatch ID</th>
              <th>Date</th>
              <th>Client</th>
              <th>Item</th>
              <th>Qty</th>
              <th>Invoice No</th>
              <th>Transport Type</th>
              <th>Delivery Status</th>
              <th>Cost</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="dsTableBody">
            <tr><td colspan="10" class="ds-empty">Loading dispatch entries...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card ds-card ds-card-green ds-dashboard-right ds-tone-emerald">
      <div class="card-header ds-card-header">
        <span class="card-title">Dispatch Insights</span>
      </div>
      <div class="ds-chart-grid">
        <div class="ds-chart-card full">
          <h4>Item-wise Dispatch by Finished Goods Tab</h4>
          <div class="ds-item-tab-wrap" id="dsInsightTabs"></div>
          <div class="ds-item-insight-topbar">
            <div class="ds-item-insight-label">Showing top dispatched items for selected category</div>
            <select id="dsItemTopLimit" class="ds-item-limit-select">
              <option value="5">Top 5</option>
              <option value="10" selected>Top 10</option>
            </select>
          </div>
          <div class="ds-item-insight-meta" id="dsItemInsightMeta">Select a tab to view item-wise dispatch breakdown.</div>
          <canvas id="dsChartItemWise" height="200"></canvas>
          <div class="ds-item-insight-list" id="dsItemInsightList"></div>
        </div>
        <div class="ds-chart-card">
          <h4>Dispatch Quantity (Monthly)</h4>
          <canvas id="dsChartMonthly" height="170"></canvas>
        </div>
        <div class="ds-chart-card">
          <h4>Client Distribution</h4>
          <canvas id="dsChartClient" height="170"></canvas>
        </div>
        <div class="ds-chart-card full">
          <h4>Transport Cost Analysis</h4>
          <canvas id="dsChartCost" height="190"></canvas>
        </div>
      </div>
    </div>
  </div>

  </section>

  <section class="ds-tab-panel ds-panel-reports" data-ds-panel="reports" hidden>
    <div class="card ds-card ds-card-blue ds-report-filter-card ds-tone-cobalt">
      <div class="card-header ds-card-header ds-report-hero">
        <div>
          <span class="card-title">Dispatch Reports</span>
          <p class="ds-muted">Advanced drill-down analytics for transport cost, products, clients, and monthly trends.</p>
        </div>
        <button type="button" class="ds-btn" id="dsReportClearDrillBtn"><i class="bi bi-sliders2"></i> Clear Drill-down</button>
      </div>
      <div class="ds-card-body">
        <div class="ds-report-filter-grid">
          <div class="ds-field-inline">
            <label>From</label>
            <input type="date" id="dsReportFrom">
          </div>
          <div class="ds-field-inline">
            <label>To</label>
            <input type="date" id="dsReportTo">
          </div>
          <div class="ds-field-inline">
            <label>Client</label>
            <select id="dsReportClient">
              <option value="">All Clients</option>
            </select>
          </div>
          <div class="ds-field-inline">
            <label>Transport Type</label>
            <select id="dsReportTransportType">
              <option value="">All Transport Types</option>
            </select>
          </div>
          <div class="ds-field-inline">
            <label>Item</label>
            <select id="dsReportItem">
              <option value="">All Items</option>
            </select>
          </div>
          <button type="button" class="ds-btn ds-btn-blue" id="dsReportApplyBtn"><i class="bi bi-bar-chart-line"></i> Apply</button>
          <button type="button" class="ds-btn" id="dsReportResetBtn">Reset</button>
        </div>
      </div>
    </div>

    <div class="ds-report-kpi-grid">
      <div class="ds-report-kpi ds-report-kpi-blue">
        <div class="ds-report-kpi-icon"><i class="bi bi-cash-stack"></i></div>
        <div class="ds-report-kpi-copy">
          <span>Total Transport Cost</span>
          <strong id="dsReportKpiCost">Rs 0.00</strong>
          <div class="ds-report-kpi-meta">
            <span class="ds-report-trend" id="dsReportTrendCost">-</span>
            <small>This month vs last month</small>
          </div>
        </div>
      </div>
      <div class="ds-report-kpi ds-report-kpi-green">
        <div class="ds-report-kpi-icon"><i class="bi bi-box-seam"></i></div>
        <div class="ds-report-kpi-copy">
          <span>Total Dispatch Qty</span>
          <strong id="dsReportKpiQty">0</strong>
          <div class="ds-report-kpi-meta">
            <span class="ds-report-trend" id="dsReportTrendQty">-</span>
            <small>This month vs last month</small>
          </div>
        </div>
      </div>
      <div class="ds-report-kpi ds-report-kpi-purple">
        <div class="ds-report-kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="ds-report-kpi-copy">
          <span>Avg Cost per Dispatch</span>
          <strong id="dsReportKpiAvgCost">Rs 0.00</strong>
          <div class="ds-report-kpi-meta">
            <span class="ds-report-trend" id="dsReportTrendAvg">-</span>
            <small>This month vs last month</small>
          </div>
        </div>
      </div>
      <div class="ds-report-kpi ds-report-kpi-orange">
        <div class="ds-report-kpi-icon"><i class="bi bi-people"></i></div>
        <div class="ds-report-kpi-copy">
          <span>Total Clients</span>
          <strong id="dsReportKpiClients">0</strong>
          <div class="ds-report-kpi-meta">
            <span class="ds-report-trend" id="dsReportTrendClients">-</span>
            <small>This month vs last month</small>
          </div>
        </div>
      </div>
    </div>

    <div class="ds-report-chart-grid">
      <div class="card ds-card ds-report-chart-card ds-tone-violet">
        <div class="card-header ds-card-header">
          <span class="card-title">Transport Type Analysis</span>
          <span class="ds-muted">Click a segment to filter the drill-down table</span>
        </div>
        <div class="ds-card-body">
          <canvas id="dsReportTransportChart" height="240"></canvas>
        </div>
      </div>

      <div class="card ds-card ds-report-chart-card ds-tone-cyan">
        <div class="card-header ds-card-header">
          <span class="card-title">Monthly Trend</span>
          <span class="ds-muted">Smooth cost trend with peak month highlight</span>
        </div>
        <div class="ds-card-body">
          <canvas id="dsReportMonthlyChart" height="240"></canvas>
        </div>
      </div>

      <div class="card ds-card ds-report-chart-card ds-tone-rose">
        <div class="card-header ds-card-header">
          <span class="card-title">Product-wise Cost Analysis</span>
          <span class="ds-muted">Click a bar to narrow client and detail view</span>
        </div>
        <div class="ds-card-body">
          <canvas id="dsReportProductChart" height="280"></canvas>
        </div>
      </div>

      <div class="card ds-card ds-report-chart-card ds-tone-lime">
        <div class="card-header ds-card-header">
          <span class="card-title">Client-wise Cost Analysis</span>
          <span class="ds-muted">Click a client to focus item-level dispatch rows</span>
        </div>
        <div class="ds-card-body">
          <canvas id="dsReportClientChart" height="280"></canvas>
        </div>
      </div>
    </div>

    <div class="card ds-card ds-card-green ds-report-table-card ds-tone-mint">
      <div class="card-header ds-card-header ds-table-header">
        <div>
          <span class="card-title">Dispatch Detail Table</span>
          <div class="ds-report-active-filters" id="dsReportActiveFilters">All dispatch report rows are visible.</div>
        </div>
        <div class="ds-report-table-tools">
          <div class="ds-search-wrap ds-report-search">
            <i class="bi bi-search"></i>
            <input type="text" id="dsReportSearchInput" placeholder="Search client, item, batch...">
          </div>
          <select id="dsReportSortBy" class="ds-item-limit-select">
            <option value="cost_desc">Highest Cost</option>
            <option value="cost_asc">Lowest Cost</option>
            <option value="qty_desc">Highest Qty</option>
            <option value="qty_asc">Lowest Qty</option>
            <option value="client_asc">Client A-Z</option>
            <option value="item_asc">Item A-Z</option>
          </select>
          <button type="button" class="ds-btn ds-btn-blue" id="dsReportPrintBtn"><i class="bi bi-printer"></i> Print Report</button>
          <button type="button" class="ds-btn ds-btn-red" id="dsReportPdfBtn"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
        </div>
      </div>
      <div class="ds-table-wrap ds-report-table-wrap">
        <table class="ds-table ds-report-table">
          <thead>
            <tr>
              <th>Client</th>
              <th>Item</th>
              <th>Batch</th>
              <th>Transport Type</th>
              <th>Qty</th>
              <th>Cost</th>
            </tr>
          </thead>
          <tbody id="dsReportTableBody">
            <tr><td colspan="6" class="ds-empty">Open the reports tab to load dispatch analytics.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <div class="modal-overlay ds-modal" id="dsViewModal" style="display:none" aria-hidden="true">
    <div class="modal-card ds-modal-card">
      <div class="modal-head ds-modal-head">
        <h3>Dispatch Challan Preview</h3>
        <button type="button" class="btn" id="dsCloseViewModal">Close</button>
      </div>
      <div class="ds-modal-body" id="dsChallanPreview"></div>
      <div class="ds-modal-actions">
        <button type="button" class="btn ds-btn-blue" id="dsPrintChallanBtn"><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn ds-btn-red" id="dsPdfChallanBtn"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
      </div>
    </div>
  </div>

  <div class="ds-loading" id="dsLoading" style="display:none" aria-hidden="true">
    <div class="ds-spinner"></div>
    <span>Processing...</span>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="<?= e(BASE_URL) ?>/modules/dispatch/js/dispatch.js?v=<?= (int)$moduleVersion ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
