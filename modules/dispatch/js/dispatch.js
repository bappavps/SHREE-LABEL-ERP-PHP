(function () {
  'use strict';

  var root = document.getElementById('dispatchModule');
  if (!root) {
    return;
  }

  var state = {
    apiUrl: String(root.getAttribute('data-api-url') || ''),
    csrf: String(root.getAttribute('data-csrf-token') || ''),
    rows: [],
    filteredRows: [],
    batchRows: [],
    cartonRatio: 0,
    availableCartons: 0,
    activeModuleTab: 'operations',
    activeInsightTab: 'pos',
    lastItemInsightHash: '',
    insightTabOrder: [
      { key: 'pos', label: 'POS', color: '#2563eb' },
      { key: 'paperroll', label: 'PaperRoll', color: '#0f766e' },
      { key: 'one_ply', label: '1 Ply', color: '#166534' },
      { key: 'two_ply', label: '2 Ply', color: '#16a34a' },
      { key: 'barcode', label: 'Barcode', color: '#7c3aed' }
    ],
    itemWiseByCategory: {},
    report: {
      loaded: false,
      rows: [],
      analysisRows: [],
      filterOptions: {
        clients: [],
        items: [],
        transportTypes: []
      },
      drill: {
        transportType: '',
        itemName: '',
        clientName: ''
      }
    },
    charts: {
      monthly: null,
      client: null,
      cost: null,
      itemWise: null,
      reportTransport: null,
      reportProduct: null,
      reportClient: null,
      reportMonthly: null
    },
    challanRow: null,
    challanBatchItems: [],
    highlightedDispatchId: 0,
    pendingOpenMode: ''
  };

  var company = {
    name: String(root.getAttribute('data-co-name') || 'ERP'),
    tagline: String(root.getAttribute('data-co-tagline') || ''),
    address: String(root.getAttribute('data-co-address') || ''),
    phone: String(root.getAttribute('data-co-phone') || ''),
    email: String(root.getAttribute('data-co-email') || ''),
    gst: String(root.getAttribute('data-co-gst') || ''),
    logo: String(root.getAttribute('data-co-logo') || '')
  };

  var reportPalette = {
    blue: '#3b82f6',
    green: '#22c55e',
    purple: '#8b5cf6',
    orange: '#f59e0b',
    yellow: '#eab308'
  };

  var nodes = {
    tabButtons: root.querySelectorAll('[data-ds-tab]'),
    tabPanels: root.querySelectorAll('[data-ds-panel]'),
    entryPk: document.getElementById('dsEntryPk'),
    form: document.getElementById('dsEntryForm'),
    formModeLabel: document.getElementById('dsFormModeLabel'),
    saveBtn: document.getElementById('dsSaveBtn'),
    resetBtn: document.getElementById('dsResetBtn'),
    loading: document.getElementById('dsLoading'),

    dispatchId: document.getElementById('dsDispatchId'),
    entryDate: document.getElementById('dsEntryDate'),
    clientName: document.getElementById('dsClientName'),
    itemName: document.getElementById('dsItemName'),
    packingId: document.getElementById('dsPackingId'),
    packingSearchBtn: document.getElementById('dsPackingSearchBtn'),
    packingIdSuggestions: document.getElementById('dsPackingIdSuggestions'),
    batchNo: document.getElementById('dsBatchNo'),
    size: document.getElementById('dsSize'),
    availableQty: document.getElementById('dsAvailableQty'),
    availableCarton: document.getElementById('dsAvailableCarton'),
    dispatchQty: document.getElementById('dsDispatchQty'),
    dispatchCarton: document.getElementById('dsDispatchCarton'),
    loadBatchesBtn: document.getElementById('dsLoadBatchesBtn'),
    batchTableBody: document.getElementById('dsBatchTableBody'),
    batchTotal: document.getElementById('dsBatchTotal'),
    unit: document.getElementById('dsUnit'),
    invoiceNo: document.getElementById('dsInvoiceNo'),
    invoiceDate: document.getElementById('dsInvoiceDate'),
    transportType: document.getElementById('dsTransportType'),
    vehicleNo: document.getElementById('dsVehicleNo'),
    transportName: document.getElementById('dsTransportName'),
    driverName: document.getElementById('dsDriverName'),
    driverPhone: document.getElementById('dsDriverPhone'),
    transportCost: document.getElementById('dsTransportCost'),
    paidBy: document.getElementById('dsPaidBy'),
    dispatchDate: document.getElementById('dsDispatchDate'),
    expectedDeliveryDate: document.getElementById('dsExpectedDeliveryDate'),
    deliveryStatus: document.getElementById('dsDeliveryStatus'),
    remarks: document.getElementById('dsRemarks'),

    filterFrom: document.getElementById('dsFilterFrom'),
    filterTo: document.getElementById('dsFilterTo'),
    filterClient: document.getElementById('dsFilterClient'),
    filterItem: document.getElementById('dsFilterItem'),
    filterStatus: document.getElementById('dsFilterStatus'),
    searchInput: document.getElementById('dsSearchInput'),
    filterApplyBtn: document.getElementById('dsFilterApplyBtn'),
    filterResetBtn: document.getElementById('dsFilterResetBtn'),

    exportExcelBtn: document.getElementById('dsExportExcelBtn'),
    exportPdfBtn: document.getElementById('dsExportPdfBtn'),
    printTableBtn: document.getElementById('dsPrintTableBtn'),

    tableBody: document.getElementById('dsTableBody'),

    viewModal: document.getElementById('dsViewModal'),
    closeViewModal: document.getElementById('dsCloseViewModal'),
    challanPreview: document.getElementById('dsChallanPreview'),
    printChallanBtn: document.getElementById('dsPrintChallanBtn'),
    pdfChallanBtn: document.getElementById('dsPdfChallanBtn'),

    kpiTotalDispatch: document.getElementById('dsKpiTotalDispatch'),
    kpiTotalQty: document.getElementById('dsKpiTotalQty'),
    kpiTotalCost: document.getElementById('dsKpiTotalCost'),
    kpiPendingTransit: document.getElementById('dsKpiPendingTransit'),
    kpiDelivered: document.getElementById('dsKpiDelivered'),
    categorySummaryGrid: document.getElementById('dsCategorySummaryGrid'),

    chartMonthly: document.getElementById('dsChartMonthly'),
    chartClient: document.getElementById('dsChartClient'),
    chartCost: document.getElementById('dsChartCost'),
    chartItemWise: document.getElementById('dsChartItemWise'),
    insightTabs: document.getElementById('dsInsightTabs'),
    itemTopLimit: document.getElementById('dsItemTopLimit'),
    itemInsightMeta: document.getElementById('dsItemInsightMeta'),
    itemInsightList: document.getElementById('dsItemInsightList'),

    reportFrom: document.getElementById('dsReportFrom'),
    reportTo: document.getElementById('dsReportTo'),
    reportClient: document.getElementById('dsReportClient'),
    reportTransportType: document.getElementById('dsReportTransportType'),
    reportItem: document.getElementById('dsReportItem'),
    reportApplyBtn: document.getElementById('dsReportApplyBtn'),
    reportResetBtn: document.getElementById('dsReportResetBtn'),
    reportClearDrillBtn: document.getElementById('dsReportClearDrillBtn'),
    reportPrintBtn: document.getElementById('dsReportPrintBtn'),
    reportPdfBtn: document.getElementById('dsReportPdfBtn'),
    reportSearchInput: document.getElementById('dsReportSearchInput'),
    reportSortBy: document.getElementById('dsReportSortBy'),
    reportTableBody: document.getElementById('dsReportTableBody'),
    reportActiveFilters: document.getElementById('dsReportActiveFilters'),
    reportKpiCost: document.getElementById('dsReportKpiCost'),
    reportKpiQty: document.getElementById('dsReportKpiQty'),
    reportKpiAvgCost: document.getElementById('dsReportKpiAvgCost'),
    reportKpiClients: document.getElementById('dsReportKpiClients'),
    reportTrendCost: document.getElementById('dsReportTrendCost'),
    reportTrendQty: document.getElementById('dsReportTrendQty'),
    reportTrendAvg: document.getElementById('dsReportTrendAvg'),
    reportTrendClients: document.getElementById('dsReportTrendClients'),
    reportTransportChart: document.getElementById('dsReportTransportChart'),
    reportProductChart: document.getElementById('dsReportProductChart'),
    reportClientChart: document.getElementById('dsReportClientChart'),
    reportMonthlyChart: document.getElementById('dsReportMonthlyChart')
  };

  function showMessage(msg, type) {
    if (typeof window.showERPToast === 'function') {
      window.showERPToast(String(msg || ''), type || 'info');
      return;
    }
    alert(String(msg || ''));
  }

  function showConfirm(msg, onOk) {
    var text = String(msg || '');
    if (typeof window.showERPConfirm === 'function') {
      window.showERPConfirm(text, onOk, { title: 'Please Confirm', okLabel: 'Confirm', cancelLabel: 'Cancel' });
      return;
    }
    if (typeof window.erpCenterMessage === 'function') {
      window.erpCenterMessage(text, { title: 'Please Confirm', actionLabel: 'Confirm', action: onOk });
      return;
    }
    if (confirm(text) && typeof onOk === 'function') {
      onOk();
    }
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function num(v) {
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function fmt(v, d) {
    var dec = typeof d === 'number' ? d : 3;
    return Number(num(v).toFixed(dec)).toLocaleString('en-IN');
  }

  function formatDisplayDate(value) {
    var raw = String(value || '').trim();
    if (!raw) {
      return '';
    }
    var parts = raw.split('-');
    if (parts.length === 3 && parts[0].length === 4) {
      return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    return raw;
  }

  function getActiveFilters() {
    return {
      from: nodes.filterFrom.value,
      to: nodes.filterTo.value,
      client: nodes.filterClient.value,
      item: nodes.filterItem.value,
      status: nodes.filterStatus.value,
      search: nodes.searchInput.value,
      strict: '0'
    };
  }

  function statusMeaning(status) {
    var normalized = String(status || '').toLowerCase();
    if (normalized === 'delivered') {
      return 'Delivered: material received by client and dispatch cycle closed.';
    }
    if (normalized === 'in transit' || normalized === 'dispatched') {
      return 'Dispatched: material left the plant and is in transit.';
    }
    if (normalized === 'packing done' || normalized === 'packed' || normalized === 'packing') {
      return 'Packing: packing is complete and ready for dispatch handoff.';
    }
    return 'Ready to Dispatch: dispatch entry is created and waiting to move in transit.';
  }

  function statusRank(status) {
    var normalized = String(status || '').toLowerCase();
    if (normalized === 'delivered') {
      return 4;
    }
    if (normalized === 'in transit' || normalized === 'dispatched' || normalized === 'finished production') {
      return 3;
    }
    if (normalized === 'packing done' || normalized === 'packed' || normalized === 'packing') {
      return 2;
    }
    return 1;
  }

  function getRequestedDispatchEntryId() {
    var params = new URLSearchParams(window.location.search);
    var id = parseInt(params.get('dispatch_entry') || '0', 10);
    if (id > 0) {
      state.highlightedDispatchId = id;
      state.pendingOpenMode = String(params.get('open') || '').toLowerCase();
      return id;
    }
    var hashMatch = String(window.location.hash || '').match(/dispatch-entry-(\d+)/i);
    if (hashMatch && hashMatch[1]) {
      id = parseInt(hashMatch[1], 10);
      state.highlightedDispatchId = id;
      return id;
    }
    return 0;
  }

  function focusHighlightedDispatchRow() {
    if (!state.highlightedDispatchId || !nodes.tableBody) {
      return;
    }
    var row = nodes.tableBody.querySelector('tr[data-dispatch-entry-id="' + String(state.highlightedDispatchId) + '"]');
    if (!row) {
      return;
    }
    row.classList.add('ds-row-highlight');
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function maybeOpenHighlightedDispatch() {
    if (!state.highlightedDispatchId || state.pendingOpenMode !== 'view') {
      focusHighlightedDispatchRow();
      return;
    }
    state.pendingOpenMode = '';
    onRowAction('view', state.highlightedDispatchId);
    focusHighlightedDispatchRow();
  }

  function setLoading(on) {
    if (!nodes.loading) {
      return;
    }
    nodes.loading.style.display = on ? 'flex' : 'none';
    nodes.loading.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function today() {
    var d = new Date();
    return d.toISOString().slice(0, 10);
  }

  function api(action, params, method) {
    var m = method || 'GET';
    if (m === 'GET') {
      var q = new URLSearchParams();
      q.set('action', action);
      var keys = Object.keys(params || {});
      for (var i = 0; i < keys.length; i += 1) {
        q.set(keys[i], String(params[keys[i]] == null ? '' : params[keys[i]]));
      }
      return fetch(state.apiUrl + '?' + q.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    var body = new FormData();
    body.set('action', action);
    body.set('csrf_token', state.csrf);
    var pKeys = Object.keys(params || {});
    for (var j = 0; j < pKeys.length; j += 1) {
      var k = pKeys[j];
      if (Array.isArray(params[k]) || (params[k] && typeof params[k] === 'object')) {
        body.set(k, JSON.stringify(params[k]));
      } else {
        body.set(k, String(params[k] == null ? '' : params[k]));
      }
    }

    return fetch(state.apiUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function parsePrefill() {
    var p = new URLSearchParams(window.location.search);
    var itemId = parseInt(p.get('item_id') || '0', 10);

    nodes.packingId.value = p.get('packing_id') || '';
    nodes.batchNo.value = p.get('batch') || '';
    nodes.size.value = p.get('size') || '';
    nodes.itemName.value = p.get('item_name') || '';
    nodes.unit.value = p.get('unit') || 'PCS';
    nodes.availableQty.value = p.get('qty') || '';
    if (p.get('qty')) {
      nodes.dispatchQty.value = p.get('qty');
    }

    if (itemId > 0) {
      nodes.form.setAttribute('data-stock-id', String(itemId));
      api('prefill_stock', { item_id: itemId }, 'GET').then(function (res) {
        if (!res || !res.ok || !res.row) {
          return;
        }
        nodes.itemName.value = res.row.item_name || nodes.itemName.value;
        nodes.packingId.value = res.row.item_code || nodes.packingId.value;
        nodes.batchNo.value = res.row.batch_no || nodes.batchNo.value;
        nodes.size.value = res.row.size || nodes.size.value;
        nodes.unit.value = res.row.unit || nodes.unit.value;
        nodes.availableQty.value = res.row.quantity || nodes.availableQty.value;
        state.cartonRatio = parseFloat(res.row.carton_ratio || 0);
        state.availableCartons = num(res.row.available_cartons || 0);
        loadBatches();
        syncUnitInputMode();
      });
    } else if (String(nodes.packingId.value || '').trim() !== '') {
      prefillByPackingId(true);
    }

    syncUnitInputMode();
    updateCartonFields();
  }

  function renderPackingIdSuggestions(rows) {
    if (!nodes.packingIdSuggestions) {
      return;
    }
    var list = Array.isArray(rows) ? rows : [];
    var html = '';
    for (var i = 0; i < list.length; i += 1) {
      var r = list[i] || {};
      var packingId = String(r.packing_id || '').trim();
      if (!packingId) {
        continue;
      }
      var itemName = String(r.item_name || '').trim();
      var qty = fmt(r.available_qty || 0);
      var label = packingId + (itemName ? ' | ' + itemName : '') + ' | Avl: ' + qty;
      html += '<option value="' + esc(packingId) + '" label="' + esc(label) + '"></option>';
    }
    nodes.packingIdSuggestions.innerHTML = html;
  }

  function updateCartonFields() {
    var ratio = state.cartonRatio || 0;
    if (ratio <= 0) {
      if (nodes.availableCarton) { nodes.availableCarton.value = ''; }
      if (nodes.dispatchCarton) { nodes.dispatchCarton.value = ''; }
      return;
    }
    var avail = parseFloat(nodes.availableQty.value || 0);
    var disp = parseFloat(nodes.dispatchQty.value || 0);
    var availableCartonsExact = num(state.availableCartons || 0);
    var availCarton = avail > 0 ? (avail / ratio) : 0;
    var dispCarton = disp > 0 ? (disp / ratio) : 0;

    // Keep barcode carton display aligned with finished stock full-carton value.
    if (availableCartonsExact > 0) {
      availCarton = availableCartonsExact;
      if (disp > 0 && avail > 0 && Math.abs(disp - avail) < 0.001) {
        dispCarton = availableCartonsExact;
      }
    }

    if (nodes.availableCarton) {
      nodes.availableCarton.value = availCarton > 0 ? Number(availCarton.toFixed(2)).toString() : '';
    }
    if (nodes.dispatchCarton) {
      nodes.dispatchCarton.value = dispCarton > 0 ? Number(dispCarton.toFixed(2)).toString() : '';
    }
  }

  function syncDispatchQtyFromCarton() {
    var ratio = state.cartonRatio || 0;
    var carton = num(nodes.dispatchCarton ? nodes.dispatchCarton.value : 0);
    if (ratio <= 0 || carton <= 0) {
      nodes.dispatchQty.value = '';
      return;
    }
    nodes.dispatchQty.value = String(Number((carton * ratio).toFixed(3)));
  }

  function syncUnitInputMode() {
    var isCarton = String(nodes.unit.value || 'PCS').toUpperCase() === 'CARTON';
    nodes.dispatchQty.readOnly = isCarton;
    if (nodes.dispatchCarton) {
      nodes.dispatchCarton.readOnly = !isCarton;
    }

    if (isCarton) {
      if (nodes.dispatchQty.value && (!nodes.dispatchCarton || !nodes.dispatchCarton.value)) {
        updateCartonFields();
      }
      syncDispatchQtyFromCarton();
    } else {
      updateCartonFields();
    }
  }

  function loadPackingIdSuggestions(query) {
    return api('search_packing_ids', {
      q: String(query || '').trim(),
      limit: 25
    }, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load packing ID suggestions.');
      }
      renderPackingIdSuggestions(res.rows || []);
    }).catch(function () {
      renderPackingIdSuggestions([]);
    });
  }

  function prefillByPackingId(silent) {
    var packingId = String(nodes.packingId.value || '').trim();
    if (packingId === '') {
      return Promise.resolve();
    }

    if (!silent) {
      setLoading(true);
    }

    return api('prefill_by_packing_id', { packing_id: packingId }, 'GET').then(function (res) {
      if (!res || !res.ok || !res.row) {
        throw new Error((res && res.error) || 'Packing ID not found in finished goods.');
      }
      var row = res.row;
      nodes.form.setAttribute('data-stock-id', String(row.id || 0));
      nodes.itemName.value = row.item_name || nodes.itemName.value;
      nodes.batchNo.value = row.batch_no || nodes.batchNo.value;
      nodes.size.value = row.size || nodes.size.value;
      nodes.unit.value = row.unit || nodes.unit.value;
      nodes.availableQty.value = String(row.available_qty || row.quantity || nodes.availableQty.value || '');
      state.cartonRatio = parseFloat(row.carton_ratio || 0);
      state.availableCartons = num(row.available_cartons || 0);
      updateCartonFields();
      syncUnitInputMode();
      loadBatches();
      return loadPackingIdSuggestions(packingId);
    }).catch(function (err) {
      if (!silent) {
        showMessage(err.message || 'Packing ID search failed.', 'error');
      }
    }).finally(function () {
      if (!silent) {
        setLoading(false);
      }
    });
  }

  function renderBatchRows() {
    if (!nodes.batchTableBody) {
      return;
    }
    if (!state.batchRows.length) {
      nodes.batchTableBody.innerHTML = '<tr><td colspan="4" class="ds-empty">No batches available</td></tr>';
      if (nodes.batchTotal) {
        nodes.batchTotal.textContent = 'Total Dispatch: 0';
      }
      return;
    }

    var html = '';
    for (var i = 0; i < state.batchRows.length; i += 1) {
      var row = state.batchRows[i];
      var invalid = num(row.dispatch_qty) > num(row.available_qty);
      html += '<tr class="' + (invalid ? 'ds-batch-row-invalid' : '') + '">' +
        '<td>' + esc(row.batch_no || '-') + '</td>' +
        '<td>' + esc(row.packing_id || '-') + '</td>' +
        '<td>' + fmt(row.available_qty || 0) + '</td>' +
        '<td><input type="number" min="0" step="0.001" class="ds-batch-qty-input" data-batch-index="' + i + '" value="' + esc(row.dispatch_qty || 0) + '"></td>' +
      '</tr>';
    }
    nodes.batchTableBody.innerHTML = html;
    syncBatchTotals();
  }

  function syncBatchTotals() {
    var totalDispatch = 0;
    var totalAvailable = 0;
    for (var i = 0; i < state.batchRows.length; i += 1) {
      totalDispatch += num(state.batchRows[i].dispatch_qty || 0);
      totalAvailable += num(state.batchRows[i].available_qty || 0);
    }
    nodes.dispatchQty.value = totalDispatch > 0 ? String(totalDispatch) : '';
    nodes.availableQty.value = totalAvailable > 0 ? String(totalAvailable) : '';
    if (nodes.batchTotal) {
      nodes.batchTotal.textContent = 'Total Dispatch: ' + fmt(totalDispatch);
    }
  }

  function loadBatches() {
    var stockId = parseInt(nodes.form.getAttribute('data-stock-id') || '0', 10);
    var itemName = String(nodes.itemName.value || '').trim();
    var packingId = String(nodes.packingId.value || '').trim();

    if (!stockId && !itemName) {
      state.batchRows = [];
      renderBatchRows();
      return Promise.resolve();
    }

    setLoading(true);
    return api('get_item_batches', {
      stock_id: stockId,
      item_name: itemName,
      packing_id: packingId
    }, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load available batches.');
      }
      var rows = Array.isArray(res.rows) ? res.rows : [];
      state.batchRows = rows.map(function (r) {
        return {
          item_id: parseInt(r.id || '0', 10),
          batch_no: String(r.batch_no || ''),
          packing_id: String(r.item_code || ''),
          available_qty: num(r.quantity || 0),
          dispatch_qty: 0
        };
      });

      // Keep compatibility for single batch prefill.
      var prefillBatch = String(nodes.batchNo.value || '').trim();
      var prefillQty = num(nodes.dispatchQty.value || 0);
      if (prefillBatch && prefillQty > 0) {
        for (var i = 0; i < state.batchRows.length; i += 1) {
          if (String(state.batchRows[i].batch_no || '').trim() === prefillBatch) {
            state.batchRows[i].dispatch_qty = Math.min(prefillQty, num(state.batchRows[i].available_qty));
            break;
          }
        }
      }

      renderBatchRows();
    }).catch(function (err) {
      showMessage(err.message || 'Unable to load available batches.', 'error');
      state.batchRows = [];
      renderBatchRows();
    }).finally(function () {
      setLoading(false);
    });
  }

  function collectBatchItems() {
    var out = [];
    for (var i = 0; i < state.batchRows.length; i += 1) {
      var row = state.batchRows[i];
      var qty = num(row.dispatch_qty || 0);
      if (qty <= 0) {
        continue;
      }
      out.push({
        item_id: row.item_id,
        batch_no: row.batch_no,
        packing_id: row.packing_id,
        available_qty: row.available_qty,
        dispatch_qty: qty
      });
    }
    return out;
  }

  function resetForm() {
    nodes.entryPk.value = '0';
    nodes.formModeLabel.textContent = 'New Entry';
    nodes.form.reset();
    nodes.entryDate.value = today();
    nodes.dispatchDate.value = today();
    nodes.transportCost.value = '0';
    nodes.availableQty.value = '';
    if (nodes.availableCarton) {
      nodes.availableCarton.value = '';
    }
    if (nodes.dispatchCarton) {
      nodes.dispatchCarton.value = '';
    }
    state.cartonRatio = 0;
    state.availableCartons = 0;
    state.batchRows = [];
    renderBatchRows();
    nodes.form.removeAttribute('data-stock-id');
    fetchNextDispatchId();
    parsePrefill();
  }

  function fetchNextDispatchId() {
    api('get_next_dispatch_id', {}, 'GET').then(function (res) {
      if (res && res.ok) {
        nodes.dispatchId.value = res.dispatch_id || '';
      }
    });
  }

  function collectPayload() {
    var batchItems = collectBatchItems();
    var fallbackStockId = parseInt(nodes.form.getAttribute('data-stock-id') || '0', 10);
    var primaryStockId = fallbackStockId;
    if (batchItems.length) {
      primaryStockId = parseInt(batchItems[0].item_id || '0', 10) || fallbackStockId;
    }

    return {
      id: parseInt(nodes.entryPk.value || '0', 10),
      dispatch_id: nodes.dispatchId.value,
      entry_date: nodes.entryDate.value,
      client_name: nodes.clientName.value,
      finished_stock_id: primaryStockId,
      item_name: nodes.itemName.value,
      packing_id: nodes.packingId.value,
      batch_no: nodes.batchNo.value,
      size: nodes.size.value,
      available_qty_snapshot: nodes.availableQty.value || '0',
      dispatch_qty: nodes.dispatchQty.value,
      batch_items: batchItems,
      unit: nodes.unit.value,
      invoice_no: nodes.invoiceNo.value,
      invoice_date: nodes.invoiceDate.value,
      transport_type: nodes.transportType.value,
      vehicle_no: nodes.vehicleNo.value,
      transport_name: nodes.transportName.value,
      driver_name: nodes.driverName.value,
      driver_phone: nodes.driverPhone.value,
      transport_cost: nodes.transportCost.value,
      paid_by: nodes.paidBy.value,
      dispatch_date: nodes.dispatchDate.value,
      expected_delivery_date: nodes.expectedDeliveryDate.value,
      delivery_status: nodes.deliveryStatus.value,
      remarks: nodes.remarks.value
    };
  }

  function validatePayload(payload) {
    if (!payload.entry_date) {
      return 'Date is required.';
    }
    if (!payload.client_name.trim()) {
      return 'Client name is required.';
    }
    if (!payload.item_name.trim()) {
      return 'Item name is required.';
    }

    var dq = num(payload.dispatch_qty);
    if (!(dq > 0)) {
      return 'Dispatch quantity must be greater than zero.';
    }

    var batchItems = Array.isArray(payload.batch_items) ? payload.batch_items : [];
    if (batchItems.length) {
      var total = 0;
      for (var i = 0; i < batchItems.length; i += 1) {
        var bq = num(batchItems[i].dispatch_qty || 0);
        var ba = num(batchItems[i].available_qty || 0);
        if (bq <= 0) {
          continue;
        }
        if (bq > ba) {
          return 'Batch quantity cannot exceed available quantity.';
        }
        total += bq;
      }
      if (!(total > 0)) {
        return 'Enter at least one batch dispatch quantity.';
      }
      payload.dispatch_qty = String(total);
      dq = total;
    }

    var av = num(payload.available_qty_snapshot);
    if (payload.finished_stock_id > 0 && dq > av && parseInt(payload.id || '0', 10) <= 0) {
      return 'Cannot dispatch more than available qty.';
    }

    if (num(payload.transport_cost) < 0) {
      return 'Transport cost cannot be negative.';
    }

    return '';
  }

  function statusPill(status) {
    var normalized = String(status || '').toLowerCase();
    var label = 'Ready to Dispatch';
    var cls = 'ds-status-pending';
    if (statusRank(normalized) >= 3 && normalized !== 'delivered') {
      label = 'Dispatched';
      cls = 'ds-status-transit';
    } else if (statusRank(normalized) >= 4) {
      label = 'Delivered';
      cls = 'ds-status-delivered';
    } else if (statusRank(normalized) >= 2) {
      label = 'Packing';
      cls = 'ds-status-transit';
    }
    return '<span class="ds-status-pill ' + cls + '" title="' + esc(statusMeaning(normalized)) + '">' + esc(label) + '</span>';
  }

  function renderTable() {
    if (!state.filteredRows.length) {
      nodes.tableBody.innerHTML = '<tr><td colspan="10" class="ds-empty">No dispatch data available</td></tr>';
      return;
    }

    var html = '';
    for (var i = 0; i < state.filteredRows.length; i += 1) {
      var row = state.filteredRows[i];
      var rowCls = parseInt(row.id, 10) === state.highlightedDispatchId ? ' class="ds-row-highlight"' : '';
      html += '<tr data-dispatch-entry-id="' + esc(row.id || '') + '"' + rowCls + '>' +
        '<td>' + esc(row.dispatch_id || '') + '</td>' +
        '<td>' + esc(row.dispatch_date || row.entry_date || '') + '</td>' +
        '<td>' + esc(row.client_name || '') + '</td>' +
        '<td>' + esc(row.item_name || '') + '</td>' +
        '<td>' + fmt(row.dispatch_qty || 0) + ' ' + esc(row.unit || '') + '</td>' +
        '<td>' + esc(row.invoice_no || '') + '</td>' +
        '<td>' + esc(row.transport_type || '') + '</td>' +
        '<td>' + statusPill(String(row.delivery_status || 'Pending')) + '</td>' +
        '<td>' + fmt(row.transport_cost || 0, 2) + '</td>' +
        '<td><div class="ds-row-actions">' +
          '<button type="button" class="ds-row-btn ds-status-btn" data-ds-action="quick-status" data-id="' + row.id + '" title="Change Status"><i class="bi bi-check2-circle"></i></button>' +
          '<button type="button" class="ds-row-btn" data-ds-action="view" data-id="' + row.id + '">View</button>' +
          '<button type="button" class="ds-row-btn" data-ds-action="print" data-id="' + row.id + '">Print</button>' +
          '<button type="button" class="ds-row-btn" data-ds-action="edit" data-id="' + row.id + '">Edit</button>' +
          '<button type="button" class="ds-row-btn ds-delete-btn" data-ds-action="delete" data-id="' + row.id + '">Delete</button>' +
        '</div></td>' +
      '</tr>';
    }

    nodes.tableBody.innerHTML = html;
    focusHighlightedDispatchRow();
  }

  function renderTableSkeleton() {
    if (!nodes.tableBody) {
      return;
    }
    var html = '';
    for (var i = 0; i < 6; i += 1) {
      html += '<tr class="ds-skeleton-row">' +
        '<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>' +
        '<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>' +
      '</tr>';
    }
    nodes.tableBody.innerHTML = html;
  }

  function setStatusKpisFromRows() {
    if (!nodes.kpiPendingTransit || !nodes.kpiDelivered) {
      return;
    }
    var pendingTransit = 0;
    var delivered = 0;
    for (var i = 0; i < state.rows.length; i += 1) {
      var status = String(state.rows[i].delivery_status || '').toLowerCase();
      if (status === 'delivered') {
        delivered += 1;
      } else if (status === 'pending' || status === 'in transit') {
        pendingTransit += 1;
      }
    }
    nodes.kpiPendingTransit.textContent = String(pendingTransit);
    nodes.kpiDelivered.textContent = String(delivered);
  }

  function applyLocalSearch() {
    state.filteredRows = state.rows.slice();
    renderTable();
  }

  function loadTable() {
    setLoading(true);
    renderTableSkeleton();
    api('list_dispatches', getActiveFilters(), 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load dispatch entries.');
      }
      state.rows = Array.isArray(res.rows) ? res.rows : [];
      state.filteredRows = state.rows.slice();
      setStatusKpisFromRows();
      applyLocalSearch();
      maybeOpenHighlightedDispatch();
    }).catch(function (err) {
      showMessage(err.message || 'Unable to load dispatch entries.', 'error');
      state.rows = [];
      state.filteredRows = [];
      setStatusKpisFromRows();
      renderTable();
    }).finally(function () {
      setLoading(false);
    });
  }

  function setKpi(kpi) {
    nodes.kpiTotalDispatch.textContent = String(kpi.total_dispatches || 0);
    nodes.kpiTotalQty.textContent = fmt(kpi.total_qty || 0);
    nodes.kpiTotalCost.textContent = fmt(kpi.total_cost || 0, 2);
    nodes.kpiPendingTransit.textContent = String(kpi.pending_transit || 0);
    if (nodes.kpiDelivered) {
      nodes.kpiDelivered.textContent = nodes.kpiDelivered.textContent || '0';
    }
  }

  function renderCategorySummary(summary) {
    if (!nodes.categorySummaryGrid) {
      return;
    }
    var config = [
      { key: 'pos_paper_roll', label: 'POS & Paper Roll' },
      { key: 'one_ply', label: '1 Ply' },
      { key: 'two_ply', label: '2 Ply' },
      { key: 'barcode', label: 'Barcode' },
      { key: 'printing_roll', label: 'Printing Roll' },
      { key: 'ribbon', label: 'Ribbon' },
      { key: 'core', label: 'Core' },
      { key: 'carton', label: 'Carton' }
    ];
    var html = '';
    var payload = summary && typeof summary === 'object' ? summary : {};
    for (var i = 0; i < config.length; i += 1) {
      var row = payload[config[i].key] || {};
      html += '<div class="ds-category-tile">' +
        '<span>' + esc(config[i].label) + '</span>' +
        '<strong>' + fmt(row.qty || 0) + '</strong>' +
        '<small>' + esc(String(row.dispatch_count || 0)) + ' dispatches</small>' +
      '</div>';
    }
    nodes.categorySummaryGrid.innerHTML = html;
  }

  function drawChart(refName, canvas, config) {
    if (!canvas || typeof Chart === 'undefined') {
      return;
    }
    if (state.charts[refName]) {
      state.charts[refName].destroy();
    }
    state.charts[refName] = new Chart(canvas.getContext('2d'), config);
  }

  function getInsightTabConfig(tabKey) {
    for (var i = 0; i < state.insightTabOrder.length; i += 1) {
      if (state.insightTabOrder[i].key === tabKey) {
        return state.insightTabOrder[i];
      }
    }
    return { key: tabKey, label: tabKey, color: '#64748b' };
  }

  function renderInsightTabs() {
    if (!nodes.insightTabs) {
      return;
    }
    var html = '';
    for (var i = 0; i < state.insightTabOrder.length; i += 1) {
      var t = state.insightTabOrder[i];
      var active = t.key === state.activeInsightTab ? ' active' : '';
      html += '<button type="button" class="ds-item-tab-btn' + active + '" data-ds-insight-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
    }
    nodes.insightTabs.innerHTML = html;
  }

  function renderItemWiseInsight() {
    var activeTab = state.activeInsightTab;
    var payload = state.itemWiseByCategory[activeTab] || null;
    var tabConfig = getInsightTabConfig(activeTab);
    var limit = parseInt(nodes.itemTopLimit && nodes.itemTopLimit.value ? nodes.itemTopLimit.value : '10', 10);
    if (!limit || limit < 1) {
      limit = 10;
    }
    var sourceItems = payload && Array.isArray(payload.items) ? payload.items.slice() : [];
    var chartItems = sourceItems.slice(0, limit);
    var topSum = 0;
    for (var oi = 0; oi < chartItems.length; oi += 1) {
      topSum += num(chartItems[oi].qty || 0);
    }
    var totalQty = payload ? num(payload.total_qty || 0) : 0;
    var othersQty = Math.max(0, totalQty - topSum);
    if (othersQty > 0) {
      chartItems.push({ item_name: 'Others', qty: othersQty });
    }
    var labels = chartItems.map(function (r) { return String(r.item_name || 'Unknown Item'); });
    var data = chartItems.map(function (r) { return num(r.qty || 0); });

    if (nodes.itemInsightMeta) {
      var total = payload ? fmt(totalQty || 0) : '0';
      var count = sourceItems.length;
      nodes.itemInsightMeta.textContent = tabConfig.label + ' | Total Dispatch: ' + total + ' | Item Count: ' + count;
    }

    if (nodes.itemInsightList) {
      if (!sourceItems.length) {
        nodes.itemInsightList.innerHTML = '<div class="ds-item-empty">No dispatch data available</div>';
      } else {
        var listHtml = '';
        var listItems = sourceItems.slice(0, limit);
        for (var i = 0; i < listItems.length; i += 1) {
          listHtml += '<div class="ds-item-row"><span>' + esc(listItems[i].item_name || 'Unknown Item') + '</span><strong>' + fmt(listItems[i].qty || 0) + '</strong></div>';
        }
        if (othersQty > 0) {
          listHtml += '<div class="ds-item-row"><span>Others</span><strong>' + fmt(othersQty) + '</strong></div>';
        }
        nodes.itemInsightList.innerHTML = listHtml;
      }
    }

    var hash = activeTab + '|' + limit + '|' + JSON.stringify(labels) + '|' + JSON.stringify(data);
    if (hash === state.lastItemInsightHash && state.charts.itemWise) {
      return;
    }
    state.lastItemInsightHash = hash;

    if (!data.length) {
      if (state.charts.itemWise) {
        state.charts.itemWise.destroy();
        state.charts.itemWise = null;
      }
      return;
    }

    drawChart('itemWise', nodes.chartItemWise, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: tabConfig.label + ' Qty',
          data: data,
          backgroundColor: tabConfig.color
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }

  function loadDashboardStats() {
    api('dashboard_stats', getActiveFilters(), 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load dashboard stats.');
      }

      setKpi(res.kpi || {});
      renderCategorySummary(res.category_summary || {});

      var monthly = Array.isArray(res.monthly_qty) ? res.monthly_qty : [];
      var mLabels = monthly.map(function (r) { return r.ym || ''; });
      var mData = monthly.map(function (r) { return num(r.qty || 0); });
      drawChart('monthly', nodes.chartMonthly, {
        type: 'line',
        data: {
          labels: mLabels,
          datasets: [{
            label: 'Qty',
            data: mData,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.15)',
            fill: true,
            tension: 0.25
          }]
        },
        options: { responsive: true, maintainAspectRatio: false }
      });

      var clients = Array.isArray(res.client_qty) ? res.client_qty : [];
      drawChart('client', nodes.chartClient, {
        type: 'doughnut',
        data: {
          labels: clients.map(function (r) { return r.client_name || 'Unknown'; }),
          datasets: [{
            label: 'Qty',
            data: clients.map(function (r) { return num(r.qty || 0); }),
            backgroundColor: ['#4f46e5', '#06b6d4', '#16a34a', '#f59e0b', '#ef4444', '#7c3aed', '#0891b2', '#84cc16']
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                boxWidth: 10,
                usePointStyle: true
              }
            }
          }
        }
      });

      var costSummary = Array.isArray(res.transport_cost_summary) ? res.transport_cost_summary : [];
      var cost = Array.isArray(res.monthly_cost) ? res.monthly_cost : [];
      var costLabels = costSummary.length
        ? costSummary.map(function (r) { return r.transport_type || 'Unknown'; })
        : cost.map(function (r) { return r.ym || ''; });
      var costData = costSummary.length
        ? costSummary.map(function (r) { return num(r.cost || 0); })
        : cost.map(function (r) { return num(r.cost || 0); });
      drawChart('cost', nodes.chartCost, {
        type: 'bar',
        data: {
          labels: costLabels,
          datasets: [{
            label: 'Cost',
            data: costData,
            backgroundColor: '#16a34a'
          }]
        },
        options: { responsive: true, maintainAspectRatio: false }
      });

      state.itemWiseByCategory = res.item_wise_by_category && typeof res.item_wise_by_category === 'object'
        ? res.item_wise_by_category
        : {};
      if (!state.itemWiseByCategory[state.activeInsightTab]) {
        state.activeInsightTab = state.insightTabOrder[0].key;
      }
      renderInsightTabs();
      renderItemWiseInsight();
      setStatusKpisFromRows();
    }).catch(function () {
      setKpi({ total_dispatches: 0, total_qty: 0, total_cost: 0, pending_transit: 0 });
      renderCategorySummary({});
      if (nodes.kpiDelivered) {
        nodes.kpiDelivered.textContent = '0';
      }
      state.itemWiseByCategory = {};
      state.lastItemInsightHash = '';
      renderInsightTabs();
      renderItemWiseInsight();
    });
  }

  function clearChart(refName) {
    if (state.charts[refName]) {
      state.charts[refName].destroy();
      state.charts[refName] = null;
    }
  }

  function fmtCurrency(v) {
    return 'Rs ' + fmt(v || 0, 2);
  }

  function hexToRgba(hex, alpha) {
    var safe = String(hex || '').replace('#', '');
    if (safe.length !== 6) {
      return 'rgba(59,130,246,' + String(alpha) + ')';
    }
    var r = parseInt(safe.slice(0, 2), 16);
    var g = parseInt(safe.slice(2, 4), 16);
    var b = parseInt(safe.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + String(alpha) + ')';
  }

  function reportMonthKey(value) {
    var raw = String(value || '').trim();
    if (!raw) {
      return '';
    }
    if (/^\d{4}-\d{2}/.test(raw)) {
      return raw.slice(0, 7);
    }
    var dt = new Date(raw);
    if (isNaN(dt.getTime())) {
      return '';
    }
    return String(dt.getFullYear()) + '-' + String(dt.getMonth() + 1).padStart(2, '0');
  }

  function reportMonthLabel(key) {
    if (!/^\d{4}-\d{2}$/.test(String(key || ''))) {
      return String(key || '');
    }
    var parts = String(key).split('-');
    var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, 1);
    return dt.toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
  }

  function getRelativeMonthKey(offset) {
    var dt = new Date();
    dt.setDate(1);
    dt.setMonth(dt.getMonth() + offset);
    return String(dt.getFullYear()) + '-' + String(dt.getMonth() + 1).padStart(2, '0');
  }

  function getReportFilters() {
    return {
      from: nodes.reportFrom ? nodes.reportFrom.value : '',
      to: nodes.reportTo ? nodes.reportTo.value : '',
      client: nodes.reportClient ? nodes.reportClient.value : '',
      transport_type: nodes.reportTransportType ? nodes.reportTransportType.value : '',
      item: nodes.reportItem ? nodes.reportItem.value : ''
    };
  }

  function getReportTransportColor(label) {
    var normalized = String(label || '').toLowerCase();
    if (normalized === 'own vehicle') {
      return reportPalette.blue;
    }
    if (normalized === 'toto') {
      return reportPalette.yellow;
    }
    if (normalized === 'courier') {
      return reportPalette.purple;
    }
    if (normalized === 'transport') {
      return reportPalette.green;
    }
    return reportPalette.orange;
  }

  function setReportTrend(node, currentValue, previousValue) {
    if (!node) {
      return;
    }
    node.className = 'ds-report-trend';
    var current = num(currentValue || 0);
    var previous = num(previousValue || 0);
    var delta = current - previous;

    if (!current && !previous) {
      node.textContent = '-';
      return;
    }

    if (previous === 0) {
      node.textContent = current > 0 ? '↑ New' : '0%';
      if (current > 0) {
        node.classList.add('is-up');
      }
      return;
    }

    var pct = Math.abs((delta / previous) * 100);
    if (pct < 0.05) {
      node.textContent = '→ 0.0%';
      return;
    }

    node.textContent = (delta >= 0 ? '↑ ' : '↓ ') + pct.toFixed(1) + '%';
    node.classList.add(delta >= 0 ? 'is-up' : 'is-down');
  }

  function renderReportSelectOptions(selectNode, items, emptyLabel) {
    if (!selectNode) {
      return;
    }
    var selected = selectNode.value;
    var list = Array.isArray(items) ? items : [];
    if (selected && list.indexOf(selected) === -1) {
      list = list.slice();
      list.push(selected);
      list.sort(function (a, b) {
        return String(a).localeCompare(String(b));
      });
    }
    var html = '<option value="">' + esc(emptyLabel || 'All') + '</option>';
    for (var i = 0; i < list.length; i += 1) {
      html += '<option value="' + esc(list[i]) + '">' + esc(list[i]) + '</option>';
    }
    selectNode.innerHTML = html;
    if (selected) {
      selectNode.value = selected;
    }
  }

  function renderReportEmptyState(message) {
    clearChart('reportTransport');
    clearChart('reportProduct');
    clearChart('reportClient');
    clearChart('reportMonthly');

    if (nodes.reportKpiCost) {
      nodes.reportKpiCost.textContent = 'Rs 0.00';
    }
    if (nodes.reportKpiQty) {
      nodes.reportKpiQty.textContent = '0';
    }
    if (nodes.reportKpiAvgCost) {
      nodes.reportKpiAvgCost.textContent = 'Rs 0.00';
    }
    if (nodes.reportKpiClients) {
      nodes.reportKpiClients.textContent = '0';
    }
    setReportTrend(nodes.reportTrendCost, 0, 0);
    setReportTrend(nodes.reportTrendQty, 0, 0);
    setReportTrend(nodes.reportTrendAvg, 0, 0);
    setReportTrend(nodes.reportTrendClients, 0, 0);

    if (nodes.reportTableBody) {
      nodes.reportTableBody.innerHTML = '<tr><td colspan="6" class="ds-empty">' + esc(message || 'No dispatch report rows available') + '</td></tr>';
    }
    if (nodes.reportActiveFilters) {
      nodes.reportActiveFilters.textContent = String(message || 'No dispatch report rows available');
    }
    if (nodes.reportClearDrillBtn) {
      nodes.reportClearDrillBtn.disabled = true;
    }
  }

  function clearReportDrillFilters() {
    state.report.drill.transportType = '';
    state.report.drill.itemName = '';
    state.report.drill.clientName = '';
  }

  function toggleReportDrill(key, value) {
    if (!value) {
      return;
    }
    state.report.drill[key] = state.report.drill[key] === value ? '' : value;
    renderDispatchReports();
  }

  function switchModuleTab(tabKey) {
    var target = tabKey === 'reports' ? 'reports' : 'operations';
    state.activeModuleTab = target;

    if (nodes.tabButtons && typeof nodes.tabButtons.forEach === 'function') {
      nodes.tabButtons.forEach(function (button) {
        var isActive = String(button.getAttribute('data-ds-tab') || '') === target;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
    }

    if (nodes.tabPanels && typeof nodes.tabPanels.forEach === 'function') {
      nodes.tabPanels.forEach(function (panel) {
        var isActive = String(panel.getAttribute('data-ds-panel') || '') === target;
        panel.classList.toggle('is-active', isActive);
        panel.hidden = !isActive;
      });
    }

    if (target === 'reports' && !state.report.loaded) {
      loadDispatchReports();
    }
  }

  function getReportAnalysisRows() {
    var rows = state.report.rows.slice();
    var drill = state.report.drill || {};

    if (drill.transportType) {
      rows = rows.filter(function (row) {
        return String(row.transport_type || '') === drill.transportType;
      });
    }
    if (drill.itemName) {
      rows = rows.filter(function (row) {
        return String(row.item_name || '') === drill.itemName;
      });
    }
    if (drill.clientName) {
      rows = rows.filter(function (row) {
        return String(row.client_name || '') === drill.clientName;
      });
    }

    return rows;
  }

  function getReportTableRows() {
    var rows = state.report.analysisRows.slice();
    var search = String(nodes.reportSearchInput && nodes.reportSearchInput.value || '').trim().toLowerCase();
    var sortBy = String(nodes.reportSortBy && nodes.reportSortBy.value || 'cost_desc');

    if (search) {
      rows = rows.filter(function (row) {
        var haystack = [
          row.client_name,
          row.item_name,
          row.batch_no,
          row.transport_type,
          row.dispatch_id
        ].join(' ').toLowerCase();
        return haystack.indexOf(search) !== -1;
      });
    }

    rows.sort(function (a, b) {
      var aCost = num(a.transport_cost || 0);
      var bCost = num(b.transport_cost || 0);
      var aQty = num(a.dispatch_qty || 0);
      var bQty = num(b.dispatch_qty || 0);
      var aClient = String(a.client_name || '');
      var bClient = String(b.client_name || '');
      var aItem = String(a.item_name || '');
      var bItem = String(b.item_name || '');
      if (sortBy === 'cost_asc') {
        return aCost - bCost;
      }
      if (sortBy === 'qty_desc') {
        return bQty - aQty;
      }
      if (sortBy === 'qty_asc') {
        return aQty - bQty;
      }
      if (sortBy === 'client_asc') {
        return aClient.localeCompare(bClient);
      }
      if (sortBy === 'item_asc') {
        return aItem.localeCompare(bItem);
      }
      return bCost - aCost;
    });

    return rows;
  }

  function buildReportMonthBuckets(rows) {
    var buckets = {};
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var key = reportMonthKey(row.month_key || row.dispatch_date || row.entry_date || '');
      if (!key) {
        continue;
      }
      if (!buckets[key]) {
        buckets[key] = {
          cost: 0,
          qty: 0,
          dispatchMap: {},
          clientMap: {}
        };
      }
      buckets[key].cost += num(row.transport_cost || 0);
      buckets[key].qty += num(row.dispatch_qty || 0);
      buckets[key].dispatchMap[String(row.dispatch_entry_id || row.dispatch_id || i)] = true;
      buckets[key].clientMap[String(row.client_name || 'Unknown Client')] = true;
    }
    return buckets;
  }

  function setReportKpis(rows) {
    var totalCost = 0;
    var totalQty = 0;
    var dispatchMap = {};
    var clientMap = {};
    for (var i = 0; i < rows.length; i += 1) {
      totalCost += num(rows[i].transport_cost || 0);
      totalQty += num(rows[i].dispatch_qty || 0);
      dispatchMap[String(rows[i].dispatch_entry_id || rows[i].dispatch_id || i)] = true;
      clientMap[String(rows[i].client_name || 'Unknown Client')] = true;
    }

    var dispatchCount = Object.keys(dispatchMap).length;
    var totalClients = Object.keys(clientMap).length;
    var avgCost = dispatchCount > 0 ? (totalCost / dispatchCount) : 0;
    var monthBuckets = buildReportMonthBuckets(rows);
    var currentMonth = monthBuckets[getRelativeMonthKey(0)] || { cost: 0, qty: 0, dispatchMap: {}, clientMap: {} };
    var previousMonth = monthBuckets[getRelativeMonthKey(-1)] || { cost: 0, qty: 0, dispatchMap: {}, clientMap: {} };
    var currentDispatchCount = Object.keys(currentMonth.dispatchMap).length;
    var previousDispatchCount = Object.keys(previousMonth.dispatchMap).length;
    var currentAvg = currentDispatchCount > 0 ? (currentMonth.cost / currentDispatchCount) : 0;
    var previousAvg = previousDispatchCount > 0 ? (previousMonth.cost / previousDispatchCount) : 0;

    if (nodes.reportKpiCost) {
      nodes.reportKpiCost.textContent = fmtCurrency(totalCost);
    }
    if (nodes.reportKpiQty) {
      nodes.reportKpiQty.textContent = fmt(totalQty, 3);
    }
    if (nodes.reportKpiAvgCost) {
      nodes.reportKpiAvgCost.textContent = fmtCurrency(avgCost);
    }
    if (nodes.reportKpiClients) {
      nodes.reportKpiClients.textContent = String(totalClients);
    }

    setReportTrend(nodes.reportTrendCost, currentMonth.cost, previousMonth.cost);
    setReportTrend(nodes.reportTrendQty, currentMonth.qty, previousMonth.qty);
    setReportTrend(nodes.reportTrendAvg, currentAvg, previousAvg);
    setReportTrend(nodes.reportTrendClients, Object.keys(currentMonth.clientMap).length, Object.keys(previousMonth.clientMap).length);
  }

  function buildTransportSummary(rows) {
    var order = ['Own Vehicle', 'Toto', 'Courier', 'Transport'];
    var map = {};
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var key = String(row.transport_type || 'Transport');
      if (!map[key]) {
        map[key] = {
          transport_type: key,
          cost: 0,
          qty: 0,
          dispatchMap: {}
        };
      }
      map[key].cost += num(row.transport_cost || 0);
      map[key].qty += num(row.dispatch_qty || 0);
      map[key].dispatchMap[String(row.dispatch_entry_id || row.dispatch_id || i)] = true;
    }
    var out = Object.keys(map).map(function (key) {
      return {
        transport_type: map[key].transport_type,
        cost: map[key].cost,
        qty: map[key].qty,
        dispatch_count: Object.keys(map[key].dispatchMap).length
      };
    });
    out.sort(function (a, b) {
      var aIndex = order.indexOf(a.transport_type);
      var bIndex = order.indexOf(b.transport_type);
      if (aIndex === -1) {
        aIndex = 99;
      }
      if (bIndex === -1) {
        bIndex = 99;
      }
      if (aIndex === bIndex) {
        return b.cost - a.cost;
      }
      return aIndex - bIndex;
    });
    return out;
  }

  function buildProductSummary(rows) {
    var map = {};
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var key = String(row.item_name || 'Unknown Item');
      if (!map[key]) {
        map[key] = {
          item_name: key,
          cost: 0,
          qty: 0,
          dispatchMap: {},
          clientMap: {}
        };
      }
      map[key].cost += num(row.transport_cost || 0);
      map[key].qty += num(row.dispatch_qty || 0);
      map[key].dispatchMap[String(row.dispatch_entry_id || row.dispatch_id || i)] = true;
      map[key].clientMap[String(row.client_name || 'Unknown Client')] = true;
    }
    var out = Object.keys(map).map(function (key) {
      return {
        item_name: map[key].item_name,
        cost: map[key].cost,
        qty: map[key].qty,
        dispatch_count: Object.keys(map[key].dispatchMap).length,
        client_count: Object.keys(map[key].clientMap).length
      };
    });
    out.sort(function (a, b) {
      return b.cost - a.cost;
    });
    return out.slice(0, 10);
  }

  function buildClientSummary(rows) {
    var map = {};
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      var key = String(row.client_name || 'Unknown Client');
      if (!map[key]) {
        map[key] = {
          client_name: key,
          cost: 0,
          qty: 0,
          dispatchMap: {}
        };
      }
      map[key].cost += num(row.transport_cost || 0);
      map[key].qty += num(row.dispatch_qty || 0);
      map[key].dispatchMap[String(row.dispatch_entry_id || row.dispatch_id || i)] = true;
    }
    var out = Object.keys(map).map(function (key) {
      return {
        client_name: map[key].client_name,
        cost: map[key].cost,
        qty: map[key].qty,
        dispatch_count: Object.keys(map[key].dispatchMap).length
      };
    });
    out.sort(function (a, b) {
      return b.cost - a.cost;
    });
    return out.slice(0, 10);
  }

  function buildMonthlySummary(rows) {
    var map = buildReportMonthBuckets(rows);
    var keys = Object.keys(map).sort();
    var out = [];
    var peakCost = -1;
    var peakKey = '';
    for (var i = 0; i < keys.length; i += 1) {
      var key = keys[i];
      var cost = num(map[key].cost || 0);
      if (cost > peakCost) {
        peakCost = cost;
        peakKey = key;
      }
      out.push({
        month_key: key,
        month_label: reportMonthLabel(key),
        cost: cost,
        qty: num(map[key].qty || 0),
        dispatch_count: Object.keys(map[key].dispatchMap || {}).length
      });
    }
    for (var j = 0; j < out.length; j += 1) {
      out[j].is_peak = out[j].month_key === peakKey;
    }
    return out;
  }

  function renderReportActiveFilters(tableRows) {
    if (!nodes.reportActiveFilters) {
      return;
    }
    var parts = [];
    var filters = getReportFilters();
    if (filters.from || filters.to) {
      parts.push('Range: ' + (filters.from || 'Start') + ' to ' + (filters.to || 'End'));
    }
    if (filters.client) {
      parts.push('Client: ' + filters.client);
    }
    if (filters.transport_type) {
      parts.push('Transport: ' + filters.transport_type);
    }
    if (filters.item) {
      parts.push('Item: ' + filters.item);
    }
    if (state.report.drill.transportType) {
      parts.push('Transport Drill: ' + state.report.drill.transportType);
    }
    if (state.report.drill.itemName) {
      parts.push('Item Drill: ' + state.report.drill.itemName);
    }
    if (state.report.drill.clientName) {
      parts.push('Client Drill: ' + state.report.drill.clientName);
    }
    if (nodes.reportSearchInput && String(nodes.reportSearchInput.value || '').trim()) {
      parts.push('Search: ' + String(nodes.reportSearchInput.value || '').trim());
    }
    parts.push('Rows: ' + String((tableRows || []).length));
    nodes.reportActiveFilters.textContent = parts.length ? parts.join(' | ') : 'All dispatch report rows are visible.';
  }

  function renderReportTable() {
    if (!nodes.reportTableBody) {
      return;
    }
    var rows = getReportTableRows();
    renderReportActiveFilters(rows);
    if (!rows.length) {
      nodes.reportTableBody.innerHTML = '<tr><td colspan="6" class="ds-empty">No report rows match the selected filters.</td></tr>';
      return;
    }

    var html = '';
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      html += '<tr>' +
        '<td>' + esc(row.client_name || '-') + '</td>' +
        '<td>' + esc(row.item_name || '-') + '</td>' +
        '<td>' + esc(row.batch_no || '-') + '</td>' +
        '<td>' + esc(row.transport_type || '-') + '</td>' +
        '<td>' + fmt(row.dispatch_qty || 0, 3) + '</td>' +
        '<td>' + fmtCurrency(row.transport_cost || 0) + '</td>' +
      '</tr>';
    }
    nodes.reportTableBody.innerHTML = html;
  }

  function buildDispatchReportsHtml() {
    var rows = getReportTableRows();
    var generatedAt = new Date();
    var generatedText = generatedAt.toLocaleDateString('en-GB') + ' ' + generatedAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    var filters = getReportFilters();
    var filterBits = [];
    var searchValue = String(nodes.reportSearchInput && nodes.reportSearchInput.value || '').trim();
    var sortValue = String(nodes.reportSortBy && nodes.reportSortBy.options[nodes.reportSortBy.selectedIndex] ? nodes.reportSortBy.options[nodes.reportSortBy.selectedIndex].text : 'Highest Cost');

    if (filters.from || filters.to) {
      filterBits.push('<div><strong>Date Range:</strong> ' + esc((formatDisplayDate(filters.from) || 'Start') + ' to ' + (formatDisplayDate(filters.to) || 'End')) + '</div>');
    } else {
      filterBits.push('<div><strong>Date Range:</strong> All Dates</div>');
    }
    filterBits.push('<div><strong>Client:</strong> ' + esc(filters.client || 'All Clients') + '</div>');
    filterBits.push('<div><strong>Transport:</strong> ' + esc(filters.transport_type || 'All Transport Types') + '</div>');
    filterBits.push('<div><strong>Item:</strong> ' + esc(filters.item || 'All Items') + '</div>');
    filterBits.push('<div><strong>Drill-down:</strong> ' + esc([
      state.report.drill.transportType ? ('Transport: ' + state.report.drill.transportType) : '',
      state.report.drill.itemName ? ('Item: ' + state.report.drill.itemName) : '',
      state.report.drill.clientName ? ('Client: ' + state.report.drill.clientName) : ''
    ].filter(Boolean).join(' | ') || 'None') + '</div>');
    filterBits.push('<div><strong>Search:</strong> ' + esc(searchValue || 'None') + '</div>');
    filterBits.push('<div><strong>Sort:</strong> ' + esc(sortValue) + '</div>');
    filterBits.push('<div><strong>Total Rows:</strong> ' + String(rows.length) + '</div>');

    var html = '<div class="ds-report"><div class="ds-report-card">' +
      '<div class="ds-report-head">' +
        '<h2>Dispatch Reports Dashboard</h2>' +
        '<p>' + esc(company.name || 'ERP') + ' | Interactive transport and dispatch analysis</p>' +
      '</div>' +
      '<div class="ds-report-meta">' +
        '<div><strong>Total Transport Cost:</strong> ' + esc(nodes.reportKpiCost ? nodes.reportKpiCost.textContent : 'Rs 0.00') + '</div>' +
        '<div><strong>Total Dispatch Qty:</strong> ' + esc(nodes.reportKpiQty ? nodes.reportKpiQty.textContent : '0') + '</div>' +
        '<div><strong>Avg Cost per Dispatch:</strong> ' + esc(nodes.reportKpiAvgCost ? nodes.reportKpiAvgCost.textContent : 'Rs 0.00') + '</div>' +
        '<div><strong>Total Clients:</strong> ' + esc(nodes.reportKpiClients ? nodes.reportKpiClients.textContent : '0') + '</div>' +
        filterBits.join('') +
      '</div>' +
      '<div class="ds-report-table-wrap">';

    if (!rows.length) {
      html += '<div class="ds-no-data">No report rows match the selected filters.</div>';
    } else {
      html += '<table><thead><tr><th>Client</th><th>Item</th><th>Batch</th><th>Transport Type</th><th>Qty</th><th>Cost</th></tr></thead><tbody>';
      for (var i = 0; i < rows.length; i += 1) {
        var row = rows[i];
        html += '<tr>' +
          '<td>' + esc(row.client_name || '-') + '</td>' +
          '<td>' + esc(row.item_name || '-') + '</td>' +
          '<td>' + esc(row.batch_no || '-') + '</td>' +
          '<td>' + esc(row.transport_type || '-') + '</td>' +
          '<td>' + fmt(row.dispatch_qty || 0, 3) + '</td>' +
          '<td>' + fmtCurrency(row.transport_cost || 0) + '</td>' +
        '</tr>';
      }
      html += '</tbody></table>';
    }

    html += '</div><div class="ds-report-foot">Generated on: ' + esc(generatedText) + ' | Powered by Dispatch Reports</div></div></div>';
    return html;
  }

  function printDispatchReport() {
    var rows = getReportTableRows();
    if (!rows.length) {
      showMessage('No report rows available for printing.', 'warning');
      return;
    }
    printHtml('Dispatch Reports Dashboard', buildDispatchReportsHtml());
  }

  function exportDispatchReportPdf() {
    var rows = getReportTableRows();
    if (!rows.length) {
      showMessage('No report rows available for PDF export.', 'warning');
      return;
    }
    printHtml('Dispatch Reports Dashboard PDF', buildDispatchReportsHtml(), { autoPrint: true });
  }

  function drawReportTransportChart(rows) {
    var summary = buildTransportSummary(rows);
    if (!summary.length) {
      clearChart('reportTransport');
      return;
    }
    var totalCost = 0;
    for (var i = 0; i < summary.length; i += 1) {
      totalCost += num(summary[i].cost || 0);
    }
    var activeValue = state.report.drill.transportType;
    drawChart('reportTransport', nodes.reportTransportChart, {
      type: 'doughnut',
      data: {
        labels: summary.map(function (row) { return row.transport_type; }),
        datasets: [{
          data: summary.map(function (row) { return num(row.cost || 0); }),
          backgroundColor: summary.map(function (row) {
            var base = getReportTransportColor(row.transport_type);
            return activeValue && activeValue !== row.transport_type ? hexToRgba(base, 0.3) : base;
          }),
          borderColor: '#ffffff',
          borderWidth: 2,
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 520, easing: 'easeOutQuart' },
        plugins: {
          legend: {
            position: 'bottom',
            labels: { usePointStyle: true, boxWidth: 10 }
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                var cost = num(context.parsed || 0);
                var pct = totalCost > 0 ? ((cost / totalCost) * 100) : 0;
                return context.label + ': ' + fmtCurrency(cost) + ' (' + pct.toFixed(1) + '%)';
              }
            }
          }
        },
        onClick: function (evt, elements) {
          if (!elements || !elements.length) {
            return;
          }
          var selected = summary[elements[0].index];
          if (selected) {
            toggleReportDrill('transportType', selected.transport_type);
          }
        }
      }
    });
  }

  function drawReportProductChart(rows) {
    var summary = buildProductSummary(rows);
    if (!summary.length) {
      clearChart('reportProduct');
      return;
    }
    var activeValue = state.report.drill.itemName;
    drawChart('reportProduct', nodes.reportProductChart, {
      type: 'bar',
      data: {
        labels: summary.map(function (row) { return row.item_name; }),
        datasets: [{
          label: 'Cost',
          data: summary.map(function (row) { return num(row.cost || 0); }),
          backgroundColor: summary.map(function (row) {
            return activeValue && activeValue !== row.item_name
              ? hexToRgba(reportPalette.purple, 0.26)
              : hexToRgba(reportPalette.purple, 0.84);
          }),
          borderRadius: 8,
          borderSkipped: false
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 520, easing: 'easeOutQuart' },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (context) {
                return context[0] ? context[0].label : '';
              },
              label: function (context) {
                return 'Cost: ' + fmtCurrency(context.parsed.x || 0);
              },
              afterLabel: function (context) {
                var row = summary[context.dataIndex] || {};
                return ['Qty: ' + fmt(row.qty || 0, 3), 'Dispatches: ' + String(row.dispatch_count || 0)];
              }
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return 'Rs ' + fmt(value || 0, 0);
              }
            }
          }
        },
        onClick: function (evt, elements) {
          if (!elements || !elements.length) {
            return;
          }
          var selected = summary[elements[0].index];
          if (selected) {
            toggleReportDrill('itemName', selected.item_name);
          }
        }
      }
    });
  }

  function drawReportClientChart(rows) {
    var summary = buildClientSummary(rows);
    if (!summary.length) {
      clearChart('reportClient');
      return;
    }
    var colorSet = [reportPalette.orange, reportPalette.yellow, reportPalette.blue, reportPalette.green, reportPalette.purple];
    var activeValue = state.report.drill.clientName;
    drawChart('reportClient', nodes.reportClientChart, {
      type: 'bar',
      data: {
        labels: summary.map(function (row) { return row.client_name; }),
        datasets: [{
          label: 'Client Cost',
          data: summary.map(function (row) { return num(row.cost || 0); }),
          backgroundColor: summary.map(function (row, index) {
            var base = colorSet[index % colorSet.length];
            return activeValue && activeValue !== row.client_name ? hexToRgba(base, 0.28) : hexToRgba(base, 0.85);
          }),
          borderRadius: 10,
          borderSkipped: false,
          maxBarThickness: 48
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 520, easing: 'easeOutQuart' },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                return 'Cost: ' + fmtCurrency(context.parsed.y || 0);
              },
              afterLabel: function (context) {
                var row = summary[context.dataIndex] || {};
                return ['Dispatch Count: ' + String(row.dispatch_count || 0), 'Qty: ' + fmt(row.qty || 0, 3)];
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return 'Rs ' + fmt(value || 0, 0);
              }
            }
          }
        },
        onClick: function (evt, elements) {
          if (!elements || !elements.length) {
            return;
          }
          var selected = summary[elements[0].index];
          if (selected) {
            toggleReportDrill('clientName', selected.client_name);
          }
        }
      }
    });
  }

  function drawReportMonthlyChart(rows) {
    var summary = buildMonthlySummary(rows);
    if (!summary.length) {
      clearChart('reportMonthly');
      return;
    }
    drawChart('reportMonthly', nodes.reportMonthlyChart, {
      type: 'line',
      data: {
        labels: summary.map(function (row) { return row.month_label; }),
        datasets: [{
          label: 'Transport Cost',
          data: summary.map(function (row) { return num(row.cost || 0); }),
          borderColor: reportPalette.blue,
          backgroundColor: hexToRgba(reportPalette.blue, 0.14),
          fill: true,
          tension: 0.34,
          pointRadius: summary.map(function (row) { return row.is_peak ? 5 : 3; }),
          pointHoverRadius: 6,
          pointBackgroundColor: summary.map(function (row) { return row.is_peak ? reportPalette.orange : reportPalette.blue; }),
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 560, easing: 'easeOutQuart' },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (context) {
                return 'Cost: ' + fmtCurrency(context.parsed.y || 0);
              },
              afterLabel: function (context) {
                var row = summary[context.dataIndex] || {};
                return row.is_peak ? 'Highest month in current selection' : '';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return 'Rs ' + fmt(value || 0, 0);
              }
            }
          }
        }
      }
    });
  }

  function renderDispatchReports() {
    state.report.analysisRows = getReportAnalysisRows();

    if (!state.report.analysisRows.length) {
      renderReportEmptyState('No dispatch report rows match the selected filters.');
      return;
    }

    setReportKpis(state.report.analysisRows);
    drawReportTransportChart(state.report.analysisRows);
    drawReportProductChart(state.report.analysisRows);
    drawReportClientChart(state.report.analysisRows);
    drawReportMonthlyChart(state.report.analysisRows);
    renderReportTable();

    if (nodes.reportClearDrillBtn) {
      nodes.reportClearDrillBtn.disabled = !state.report.drill.transportType && !state.report.drill.itemName && !state.report.drill.clientName;
    }
  }

  function loadDispatchReports() {
    if (!nodes.reportTableBody) {
      return Promise.resolve();
    }

    nodes.reportTableBody.innerHTML = '<tr><td colspan="6" class="ds-empty">Loading dispatch report analytics...</td></tr>';
    setLoading(true);

    return api('dispatch_reports', getReportFilters(), 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load dispatch report analytics.');
      }

      state.report.rows = Array.isArray(res.rows) ? res.rows : [];
      state.report.filterOptions = res.filter_options && typeof res.filter_options === 'object' ? res.filter_options : {
        clients: [],
        items: [],
        transportTypes: []
      };
      state.report.loaded = true;

      renderReportSelectOptions(nodes.reportClient, state.report.filterOptions.clients || [], 'All Clients');
      renderReportSelectOptions(nodes.reportTransportType, state.report.filterOptions.transportTypes || [], 'All Transport Types');
      renderReportSelectOptions(nodes.reportItem, state.report.filterOptions.items || [], 'All Items');

      if (!state.report.rows.length) {
        renderReportEmptyState('No dispatch report rows available for the selected advanced filters.');
        return;
      }

      renderDispatchReports();
    }).catch(function (err) {
      state.report.rows = [];
      state.report.analysisRows = [];
      renderReportEmptyState(err.message || 'Unable to load dispatch report analytics.');
    }).finally(function () {
      setLoading(false);
    });
  }

  function renderChallanHtml(row, batchItems) {
    if (!row) {
      return '<div class="ds-challan-empty">No dispatch data available</div>';
    }

    var items = Array.isArray(batchItems) ? batchItems : [];
    var logoHtml = company.logo ? '<img src="' + esc(company.logo) + '" alt="Logo" class="ds-challan-logo">' : '';
    var addressLine = [company.address, company.phone ? ('Ph: ' + company.phone) : '', company.email].filter(Boolean).join(' | ');
    var challanStatus = String(row.delivery_status || 'Pending');
    var challanStatusKey = challanStatus.toLowerCase();
    var challanStatusClass = challanStatusKey === 'delivered' ? 'delivered' : (challanStatusKey === 'in transit' ? 'transit' : 'pending');
    var itemRows = '';
    if (!items.length) {
      itemRows = '<tr><td colspan="6" class="ds-challan-empty-row">No batches available</td></tr>';
    } else {
      for (var i = 0; i < items.length; i += 1) {
        var item = items[i];
        itemRows += '<tr class="ds-challan-row">' +
          '<td class="ds-challan-sl">' + String(i + 1) + '</td>' +
          '<td class="ds-challan-item">' + esc(item.item_name || row.item_name || '') + '</td>' +
          '<td>' + esc(item.batch_no || '') + '</td>' +
          '<td>' + esc(item.packing_id || '') + '</td>' +
          '<td>' + esc(item.size || row.size || '') + '</td>' +
          '<td class="ds-challan-num"><span class="ds-qty-badge">' + fmt(item.dispatch_qty || 0) + ' ' + esc(item.unit || row.unit || '') + '</span></td>' +
        '</tr>';
      }
    }

    return '<div class="ds-challan">' +
      '<div class="ds-challan-accent-bar"></div>' +
      '<div class="ds-challan-header">' +
        '<div class="ds-challan-brand">' + logoHtml + '<div><div class="ds-challan-company">' + esc(company.name || 'Shree Label Creation ERP') + '</div>' +
        (company.tagline ? '<div class="ds-challan-tagline">' + esc(company.tagline) + '</div>' : '') +
        '<div class="ds-challan-address">' + esc(addressLine) + '</div></div></div>' +
        '<div class="ds-challan-head-meta"><div class="ds-challan-title">Dispatch Challan</div><div class="ds-challan-id">' + esc(row.dispatch_id || '') + '</div><div class="ds-challan-status-chip ' + challanStatusClass + '">' + esc(challanStatus) + '</div></div>' +
      '</div>' +
      '<div class="ds-challan-section-grid">' +
        '<div class="ds-challan-section"><div class="ds-challan-section-title">Client Section</div><div><strong>Client Name:</strong> ' + esc(row.client_name || '') + '</div><div><strong>Invoice No:</strong> ' + esc(row.invoice_no || '-') + '</div></div>' +
        '<div class="ds-challan-section"><div class="ds-challan-section-title">Dispatch Info</div><div><strong>Date:</strong> ' + esc(row.dispatch_date || row.entry_date || '') + '</div><div><strong>Status:</strong> ' + esc(row.delivery_status || 'Pending') + '</div></div>' +
      '</div>' +
      '<div class="ds-challan-table-wrap"><table class="ds-challan-table"><thead><tr><th>SL</th><th>Item</th><th>Batch</th><th>Packing ID</th><th>Size</th><th>Qty</th></tr></thead><tbody>' + itemRows + '</tbody></table></div>' +
      '<div class="ds-challan-section"><div class="ds-challan-section-title">Transport Section</div><div class="ds-challan-transport-grid"><div><strong>Transport Type:</strong> ' + esc(row.transport_type || '-') + '</div><div><strong>Vehicle Number:</strong> ' + esc(row.vehicle_no || '-') + '</div><div><strong>Driver Name:</strong> ' + esc(row.driver_name || '-') + '</div><div><strong>Driver Phone:</strong> ' + esc(row.driver_phone || '-') + '</div><div><strong>Cost:</strong> ' + fmt(row.transport_cost || 0, 2) + '</div></div></div>' +
      '<div class="ds-challan-remarks"><strong>Remarks:</strong> ' + esc(row.remarks || '-') + '</div>' +
      '<div class="ds-sign-row"><div class="ds-sign-box">Prepared By</div><div class="ds-sign-box">Dispatch In-charge</div><div class="ds-sign-box">Receiver Signature</div></div>' +
      '<div class="ds-challan-foot"><span>Version : 1.0</span><span>&copy; 2026 Shree Label Creation ERP &bull; ERP Master System v1.0 | @ Developed by Mriganka Bhusan Debnath</span></div>' +
    '</div>';
  }

  function getExportStyles() {
    return '@page{size:A4;margin:18px}' +
      'body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;background:#f4f7fb;margin:0}' +
      '.no-print{padding:10px 14px;background:#ffffff;border-bottom:1px solid #dbe5ef;position:sticky;top:0;z-index:2}' +
      '.no-print button{padding:7px 10px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;font-weight:600;cursor:pointer}' +
      '.no-print .primary{background:#0f766e;color:#fff;border-color:#0f766e}' +
      '.ds-report{padding:14px}' +
      '.ds-report-card{background:#fff;border:1px solid #dbe5ef;border-radius:14px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.08)}' +
      '.ds-report-head{background:linear-gradient(120deg,#0f766e 0%,#2563eb 52%,#7c3aed 100%);color:#fff;padding:14px 16px}' +
      '.ds-report-brandline{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.9}' +
      '.ds-report-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:4px}' +
      '.ds-report-chip{display:inline-block;padding:4px 10px;border:1px solid rgba(255,255,255,.5);border-radius:999px;font-size:11px;font-weight:700;background:rgba(255,255,255,.18);white-space:nowrap}' +
      '.ds-report-head h2{margin:0;font-size:20px;letter-spacing:.01em}' +
      '.ds-report-head p{margin:4px 0 0;font-size:12px;opacity:.95}' +
      '.ds-report-subtitle{margin-top:6px}' +
      '.ds-report-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:12px 14px;background:#f8fbff;border-bottom:1px solid #dbe5ef}' +
      '.ds-kpi-box{border:1px solid #dbe5ef;border-radius:10px;padding:10px;background:#fff}' +
      '.ds-kpi-box span{display:block;font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.04em}' +
      '.ds-kpi-box strong{display:block;margin-top:4px;font-size:16px;color:#0f172a}' +
      '.ds-kpi-blue{background:linear-gradient(180deg,#eaf2ff,#f8fbff);border-color:#bfdbfe}' +
      '.ds-kpi-violet{background:linear-gradient(180deg,#f2edff,#fbf9ff);border-color:#ddd6fe}' +
      '.ds-kpi-amber{background:linear-gradient(180deg,#fff7e6,#fffcf4);border-color:#fcd34d}' +
      '.ds-kpi-emerald{background:linear-gradient(180deg,#ecfdf5,#f7fffb);border-color:#86efac}' +
      '.ds-report-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid #dbe5ef}' +
      '.ds-report-meta div{font-size:12px;color:#334155;border:1px dashed #dbe5ef;border-radius:8px;padding:6px 8px;background:#fff}' +
      '.ds-report-meta strong{color:#0f172a}' +
      '.ds-report-table-wrap{padding:12px 14px}' +
      '.ds-report table{width:100%;border-collapse:collapse}' +
      '.ds-report-table{border-radius:12px;overflow:hidden}' +
      '.ds-report th,.ds-report td{border:1px solid #d5deea;padding:9px 10px;font-size:12px;vertical-align:top}' +
      '.ds-report th{background:linear-gradient(90deg,#eff6ff 0%,#f5f3ff 100%);color:#1e293b;text-align:left;font-weight:800;letter-spacing:.02em}' +
      '.ds-report tbody tr:nth-child(even) td{background:#fbfdff}' +
      '.ds-report tbody tr:nth-child(odd) td{background:#ffffff}' +
      '.ds-row-pending td{background:#fff7ed !important}' +
      '.ds-row-transit td{background:#eff6ff !important}' +
      '.ds-row-delivered td{background:#ecfdf3 !important}' +
      '.ds-status-pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.02em}' +
      '.ds-status-pill.is-pending{color:#9a3412;background:#ffedd5;border:1px solid #fdba74}' +
      '.ds-status-pill.is-transit{color:#1d4ed8;background:#dbeafe;border:1px solid #93c5fd}' +
      '.ds-status-pill.is-delivered{color:#166534;background:#dcfce7;border:1px solid #86efac}' +
      '.ds-report-cell-right{text-align:right;white-space:nowrap}' +
      '.ds-no-data,.ds-challan-empty{padding:20px;text-align:center;color:#64748b;font-weight:600}' +
      '.ds-report-foot{padding:10px 14px;border-top:1px solid #dbe5ef;background:#f8fafc;color:#334155;font-size:11px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}' +
        '.ds-challan{width:100%;background:#fff;padding:20px;box-sizing:border-box;border:1px solid #dbe5ef;border-radius:14px;overflow:hidden;box-shadow:0 10px 28px rgba(15,23,42,.08)}' +
        '.ds-challan-accent-bar{height:8px;background:linear-gradient(90deg,#0f766e 0%,#2563eb 52%,#7c3aed 100%);margin:-20px -20px 14px}' +
        '.ds-challan-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:2px solid #cbd5e1;padding-bottom:12px;margin-bottom:14px}' +
      '.ds-challan-brand{display:flex;gap:12px;align-items:flex-start}' +
      '.ds-challan-logo{height:52px;max-width:140px;object-fit:contain}' +
        '.ds-challan-company{font-size:20px;font-weight:900;letter-spacing:.01em}' +
      '.ds-challan-tagline,.ds-challan-address{font-size:12px;color:#475569;margin-top:3px}' +
      '.ds-challan-head-meta{text-align:right}' +
      '.ds-challan-title{font-size:18px;font-weight:800}' +
      '.ds-challan-id{font-size:12px;color:#475569;margin-top:4px}' +
        '.ds-challan-status-chip{display:inline-block;margin-top:8px;padding:4px 10px;border-radius:999px;background:#e0f2fe;border:1px solid #7dd3fc;color:#075985;font-size:11px;font-weight:800}' +
      '.ds-challan-status-chip.delivered{background:#dcfce7;border-color:#86efac;color:#166534}' +
      '.ds-challan-status-chip.transit{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8}' +
      '.ds-challan-status-chip.pending{background:#ffedd5;border-color:#fdba74;color:#9a3412}' +
      '.ds-challan-section-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px}' +
        '.ds-challan-section{border:1px solid #dbe5ef;border-radius:10px;padding:10px 12px;font-size:12px;background:linear-gradient(180deg,#f8fafc,#ffffff)}' +
      '.ds-challan-section-title{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#334155;margin-bottom:6px}' +
      '.ds-challan-table-wrap{margin-bottom:14px}' +
      '.ds-challan-table{width:100%;border-collapse:collapse}' +
        '.ds-challan-table th,.ds-challan-table td{border:1px solid #cbd5e1;padding:8px;font-size:12px;vertical-align:top}' +
        '.ds-challan-table th{background:#e7efff;text-align:left;font-weight:800;border-bottom:2px solid #94a3b8}' +
        '.ds-challan-table tbody tr:nth-child(even) td{background:#fbfdff}' +
      '.ds-challan-row td:first-child{background:#f1f5ff;font-weight:800;color:#1e40af}' +
      '.ds-challan-item{font-weight:700;color:#1f2937}' +
      '.ds-challan-sl{text-align:center;min-width:42px}' +
      '.ds-qty-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eefcf3;border:1px solid #86efac;color:#166534;font-weight:800}' +
      '.ds-challan-empty-row{text-align:center;color:#64748b;font-weight:600}' +
      '.ds-challan-num{text-align:right;white-space:nowrap}' +
      '.ds-challan-transport-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}' +
      '.ds-challan-remarks{font-size:12px;margin:12px 0 18px}' +
      '.ds-sign-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:18px}' +
      '.ds-sign-box{padding-top:28px;border-top:1px solid #94a3b8;font-size:12px;font-weight:700;text-align:center}' +
        '.ds-challan-foot{margin-top:14px;padding-top:8px;border-top:1px dashed #cbd5e1;font-size:11px;color:#475569;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}' +
        '@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact}.no-print{display:none}body{background:#fff;margin:0;font-size:12px}.ds-report,.ds-challan{padding:0}.ds-challan{width:100%}.ds-challan-table{border-collapse:collapse}}';
  }

  function printHtml(title, html) {
    var options = arguments[2] || {};
    if (!String(html || '').trim()) {
      showMessage('No dispatch data available', 'warning');
      return;
    }
    var w = window.open('', '_blank');
    if (!w) {
      showMessage('Popup blocked. Please allow popups.', 'warning');
      return;
    }

    var doc = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' +
      '<style>' + getExportStyles() + '</style>' +
      '</head><body><div class="no-print"><button class="primary" onclick="window.print()">Print / Save PDF</button> <button onclick="window.close()">Close</button></div>' + html + '</body></html>';

    w.document.open();
    w.document.write(doc);
    w.document.close();
    w.focus();
    if (options.autoPrint) {
      setTimeout(function () {
        w.print();
      }, 250);
    }
  }

  function buildEntriesReportHtml() {
    var fromDate = formatDisplayDate(nodes.filterFrom.value);
    var toDate = formatDisplayDate(nodes.filterTo.value);
    var summaryRange = (fromDate || toDate) ? ((fromDate || 'Start') + ' to ' + (toDate || 'End')) : 'All Dates';
    var generatedAt = new Date();
    var generatedText = generatedAt.toLocaleDateString('en-GB') + ' ' + generatedAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

    var totalQty = 0;
    var totalCost = 0;
    var pendingCount = 0;
    var deliveredCount = 0;
    for (var t = 0; t < state.filteredRows.length; t += 1) {
      var tr = state.filteredRows[t] || {};
      var st = String(tr.delivery_status || '').toLowerCase();
      totalQty += num(tr.dispatch_qty || 0);
      totalCost += num(tr.transport_cost || 0);
      if (st === 'delivered') {
        deliveredCount += 1;
      } else {
        pendingCount += 1;
      }
    }

    var html = '<div class="ds-report"><div class="ds-report-card">' +
      '<div class="ds-report-head">' +
        '<div class="ds-report-brandline">' + esc(company.name || 'Shree Label Creation ERP') + '</div>' +
        '<div class="ds-report-title-row"><h2>Dispatch Entries Report</h2><span class="ds-report-chip">Dispatch Module</span></div>' +
        '<p class="ds-report-subtitle">Professional Export Format | Clean, Color-coded and Print Ready</p>' +
      '</div>' +
      '<div class="ds-report-kpis">' +
        '<div class="ds-kpi-box ds-kpi-blue"><span>Total Entries</span><strong>' + esc(String(state.filteredRows.length)) + '</strong></div>' +
        '<div class="ds-kpi-box ds-kpi-violet"><span>Total Dispatch Qty</span><strong>' + esc(fmt(totalQty, 0)) + '</strong></div>' +
        '<div class="ds-kpi-box ds-kpi-amber"><span>Pending / Transit</span><strong>' + esc(String(pendingCount)) + '</strong></div>' +
        '<div class="ds-kpi-box ds-kpi-emerald"><span>Total Cost</span><strong>' + esc(fmtCurrency(totalCost)) + '</strong></div>' +
      '</div>' +
      '<div class="ds-report-meta">' +
        '<div><strong>From:</strong> ' + esc(fromDate || 'N/A') + '</div>' +
        '<div><strong>To:</strong> ' + esc(toDate || 'N/A') + '</div>' +
        '<div><strong>Client Filter:</strong> ' + esc(nodes.filterClient.value || 'All') + '</div>' +
        '<div><strong>Item Filter:</strong> ' + esc(nodes.filterItem.value || 'All') + '</div>' +
        '<div><strong>Status:</strong> ' + esc(nodes.filterStatus.value || 'All') + '</div>' +
        '<div><strong>Search:</strong> ' + esc(nodes.searchInput.value || 'None') + '</div>' +
        '<div><strong>Date Range:</strong> ' + esc(summaryRange) + '</div>' +
        '<div><strong>Delivered Rows:</strong> ' + String(deliveredCount) + '</div>' +
        '<div><strong>Generated By:</strong> ' + esc(company.name || 'Dispatch Module') + '</div>' +
      '</div>' +
      '<div class="ds-report-table-wrap">';

    if (!state.filteredRows.length) {
      html += '<div class="ds-no-data">No dispatch entries found for selected filters.</div>';
    } else {
      html += '<table class="ds-report-table"><thead><tr><th>SL</th><th>Dispatch ID</th><th>Date</th><th>Client</th><th>Item</th><th>Qty</th><th>Invoice No</th><th>Transport Type</th><th>Delivery Status</th><th>Cost</th></tr></thead><tbody>';
      for (var i = 0; i < state.filteredRows.length; i += 1) {
        var r = state.filteredRows[i];
        var statusText = String(r.delivery_status || '').toLowerCase();
        var rowClass = statusText === 'delivered' ? 'ds-row-delivered' : (statusText === 'in transit' ? 'ds-row-transit' : 'ds-row-pending');
        var statusClass = statusText === 'delivered' ? 'is-delivered' : (statusText === 'in transit' ? 'is-transit' : 'is-pending');
        html += '<tr class="' + rowClass + '">' +
          '<td>' + String(i + 1) + '</td>' +
          '<td>' + esc(r.dispatch_id || '') + '</td>' +
          '<td>' + esc(formatDisplayDate(r.dispatch_date || r.entry_date || '')) + '</td>' +
          '<td>' + esc(r.client_name || '') + '</td>' +
          '<td>' + esc(r.item_name || '') + '</td>' +
          '<td class="ds-report-cell-right">' + fmt(r.dispatch_qty || 0) + ' ' + esc(r.unit || '') + '</td>' +
          '<td>' + esc(r.invoice_no || '') + '</td>' +
          '<td>' + esc(r.transport_type || '') + '</td>' +
          '<td><span class="ds-status-pill ' + statusClass + '">' + esc(r.delivery_status || '') + '</span></td>' +
          '<td class="ds-report-cell-right">' + fmtCurrency(r.transport_cost || 0) + '</td>' +
        '</tr>';
      }
      html += '</tbody></table>';
    }

    html += '</div><div class="ds-report-foot"><span>Version : 1.0</span><span>&copy; 2026 Shree Label Creation ERP &bull; ERP Master System v1.0 | @ Developed by Mriganka Bhusan Debnath</span></div></div></div>';
    return html;
  }

  function exportExcelTemplate() {
    var htmlDoc = '<html><head><meta charset="utf-8"><style>' + getExportStyles() + '</style></head><body>' + buildEntriesReportHtml() + '</body></html>';
    var blob = new Blob(['\ufeff' + htmlDoc], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var link = document.createElement('a');
    var dt = new Date();
    var y = String(dt.getFullYear());
    var m = String(dt.getMonth() + 1).padStart(2, '0');
    var d = String(dt.getDate()).padStart(2, '0');
    link.href = URL.createObjectURL(blob);
    link.download = 'dispatch_entries_report_' + y + m + d + '.xls';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href);
  }

  function exportPdfReport() {
    printHtml('Dispatch Entries Report', buildEntriesReportHtml(), { autoPrint: true });
  }

  function loadChallanData(id, fallbackRow) {
    return api('get_dispatch', { id: id }, 'GET').then(function (res) {
      if (!res || !res.ok || !res.row) {
        throw new Error((res && res.error) || 'Unable to load dispatch challan details.');
      }
      state.challanRow = res.row;
      state.challanBatchItems = Array.isArray(res.batch_items) ? res.batch_items : [];
      return { row: res.row, batch_items: state.challanBatchItems };
    }).catch(function () {
      state.challanRow = fallbackRow || null;
      state.challanBatchItems = [];
      if (fallbackRow) {
        return { row: fallbackRow, batch_items: [] };
      }
      throw new Error('Unable to load dispatch challan details.');
    });
  }

  function openChallanView(row, batchItems) {
    state.challanRow = row;
    state.challanBatchItems = Array.isArray(batchItems) ? batchItems : [];
    nodes.challanPreview.innerHTML = renderChallanHtml(row, state.challanBatchItems);
    nodes.viewModal.style.display = 'flex';
    nodes.viewModal.setAttribute('aria-hidden', 'false');
  }

  function closeChallanView() {
    nodes.viewModal.style.display = 'none';
    nodes.viewModal.setAttribute('aria-hidden', 'true');
  }

  function onRowAction(action, id) {
    var row = null;
    for (var i = 0; i < state.rows.length; i += 1) {
      if (parseInt(state.rows[i].id, 10) === id) {
        row = state.rows[i];
        break;
      }
    }

    if (!row) {
      showMessage('Dispatch row not found.', 'error');
      return;
    }

    if (action === 'view') {
      setLoading(true);
      loadChallanData(id, row).then(function (payload) {
        openChallanView(payload.row, payload.batch_items);
      }).catch(function (err) {
        showMessage(err.message || 'Unable to load dispatch challan.', 'error');
      }).finally(function () {
        setLoading(false);
      });
      return;
    }

    if (action === 'print') {
      setLoading(true);
      loadChallanData(id, row).then(function (payload) {
        printHtml('Dispatch Challan', renderChallanHtml(payload.row, payload.batch_items));
      }).catch(function (err) {
        showMessage(err.message || 'Unable to print dispatch challan.', 'error');
      }).finally(function () {
        setLoading(false);
      });
      return;
    }

    if (action === 'quick-status') {
      var currentStatus = String(row.delivery_status || 'Pending');
      var nextStatus = currentStatus === 'Pending' ? 'In Transit' : currentStatus === 'In Transit' ? 'Delivered' : 'Pending';
      var confirmMsg = 'Change dispatch status from "' + currentStatus + '" to "' + nextStatus + '"?';
      showConfirm(confirmMsg, function () {
        setLoading(true);
        api('update_dispatch_status', { id: row.id, status: nextStatus }, 'POST').then(function (res) {
          if (!res || !res.ok) {
            throw new Error((res && res.error) || 'Unable to update dispatch status.');
          }
          showMessage('Status updated to: ' + nextStatus, 'success');
          loadTable();
          loadDashboardStats();
        }).catch(function (err) {
          showMessage(err.message || 'Unable to update dispatch status.', 'error');
        }).finally(function () {
          setLoading(false);
        });
      });
      return;
    }

    if (action === 'edit') {
      setLoading(true);
      api('get_dispatch', { id: row.id }, 'GET').then(function (res) {
        if (!res || !res.ok || !res.row) {
          throw new Error((res && res.error) || 'Unable to load dispatch details.');
        }
        var d = res.row;
        nodes.entryPk.value = String(d.id || 0);
        nodes.formModeLabel.textContent = 'Edit Entry';
        nodes.dispatchId.value = d.dispatch_id || '';
        nodes.entryDate.value = d.entry_date || today();
        nodes.clientName.value = d.client_name || '';
        nodes.form.setAttribute('data-stock-id', String(d.finished_stock_id || 0));
        nodes.itemName.value = d.item_name || '';
        nodes.packingId.value = d.packing_id || '';
        nodes.batchNo.value = d.batch_no || '';
        nodes.size.value = d.size || '';
        nodes.availableQty.value = d.available_qty_snapshot || '';
        nodes.dispatchQty.value = d.dispatch_qty || '';
        nodes.unit.value = d.unit || 'PCS';
        nodes.invoiceNo.value = d.invoice_no || '';
        nodes.invoiceDate.value = d.invoice_date || '';
        nodes.transportType.value = d.transport_type || 'Transport';
        nodes.vehicleNo.value = d.vehicle_no || '';
        nodes.transportName.value = d.transport_name || '';
        nodes.driverName.value = d.driver_name || '';
        nodes.driverPhone.value = d.driver_phone || '';
        nodes.transportCost.value = d.transport_cost || 0;
        nodes.paidBy.value = d.paid_by || 'Company';
        nodes.dispatchDate.value = d.dispatch_date || '';
        nodes.expectedDeliveryDate.value = d.expected_delivery_date || '';
        nodes.deliveryStatus.value = d.delivery_status || 'Pending';
        nodes.remarks.value = d.remarks || '';

        var batchItems = Array.isArray(res.batch_items) ? res.batch_items : [];
        loadBatches().then(function () {
          if (batchItems.length) {
            var map = {};
            for (var bi = 0; bi < batchItems.length; bi += 1) {
              map[String(batchItems[bi].item_id)] = num(batchItems[bi].dispatch_qty || 0);
            }
            for (var br = 0; br < state.batchRows.length; br += 1) {
              var key = String(state.batchRows[br].item_id || '');
              if (Object.prototype.hasOwnProperty.call(map, key)) {
                state.batchRows[br].dispatch_qty = map[key];
              }
            }
            renderBatchRows();
          }
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
      }).catch(function (err) {
        showMessage(err.message || 'Unable to load dispatch details.', 'error');
      }).finally(function () {
        setLoading(false);
      });
      return;
    }

    if (action === 'delete') {
      showConfirm('Delete dispatch entry ' + (row.dispatch_id || '') + '? This will rollback stock and remove this record.', function () {
        setLoading(true);
        api('delete_dispatch', { id: row.id }, 'POST').then(function (res) {
          if (!res || !res.ok) {
            throw new Error((res && res.error) || 'Unable to delete dispatch entry.');
          }
          showMessage(res.message || 'Dispatch entry deleted successfully.', 'success');
          loadTable();
          loadDashboardStats();
        }).catch(function (err) {
          showMessage(err.message || 'Unable to delete dispatch entry.', 'error');
        }).finally(function () {
          setLoading(false);
        });
      });
      return;
    }
  }

  function exportExcel() {
    if (!state.filteredRows.length) {
      showMessage('No rows available for export.', 'warning');
      return;
    }
    exportExcelTemplate();
  }

  function printTable() {
    printHtml('Dispatch Entries Report', buildEntriesReportHtml(), { autoPrint: true });
  }

  function submitForm(ev) {
    ev.preventDefault();
    var payload = collectPayload();
    var err = validatePayload(payload);
    if (err) {
      showMessage(err, 'error');
      return;
    }

    setLoading(true);
    nodes.saveBtn.disabled = true;

    api('save_dispatch', payload, 'POST').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Dispatch save failed.');
      }
      showMessage(res.message || 'Dispatched Successfully', 'success');
      resetForm();
      loadTable();
      loadDashboardStats();
    }).catch(function (error) {
      showMessage(error.message || 'Unable to save dispatch.', 'error');
    }).finally(function () {
      setLoading(false);
      nodes.saveBtn.disabled = false;
    });
  }

  function bindEvents() {
    nodes.form.addEventListener('submit', submitForm);

    if (nodes.tabButtons && typeof nodes.tabButtons.forEach === 'function') {
      nodes.tabButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          switchModuleTab(String(button.getAttribute('data-ds-tab') || 'operations'));
        });
      });
    }

    nodes.resetBtn.addEventListener('click', function () {
      resetForm();
    });

    if (nodes.loadBatchesBtn) {
      nodes.loadBatchesBtn.addEventListener('click', function () {
        loadBatches();
      });
    }

    if (nodes.packingSearchBtn) {
      nodes.packingSearchBtn.addEventListener('click', function () {
        prefillByPackingId(false);
      });
    }

    var batchReloadTimer = null;
    nodes.itemName.addEventListener('change', function () {
      loadBatches();
    });
    nodes.packingId.addEventListener('input', function () {
      clearTimeout(batchReloadTimer);
      batchReloadTimer = setTimeout(loadBatches, 250);
    });
    nodes.packingId.addEventListener('focus', function () {
      loadPackingIdSuggestions(nodes.packingId.value || '');
    });
    nodes.packingId.addEventListener('input', function () {
      loadPackingIdSuggestions(nodes.packingId.value || '');
    });
    nodes.packingId.addEventListener('change', function () {
      prefillByPackingId(true);
    });
    nodes.packingId.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        prefillByPackingId(false);
      }
    });

    nodes.dispatchQty.addEventListener('input', function () {
      if (String(nodes.unit.value || '').toUpperCase() !== 'CARTON') {
        updateCartonFields();
      }
    });

    if (nodes.dispatchCarton) {
      nodes.dispatchCarton.addEventListener('input', function () {
        if (String(nodes.unit.value || '').toUpperCase() === 'CARTON') {
          syncDispatchQtyFromCarton();
        }
      });
    }

    nodes.unit.addEventListener('change', function () {
      syncUnitInputMode();
    });

    if (nodes.batchTableBody) {
      nodes.batchTableBody.addEventListener('input', function (ev) {
        var el = ev.target.closest('[data-batch-index]');
        if (!el) {
          return;
        }
        var idx = parseInt(el.getAttribute('data-batch-index') || '-1', 10);
        if (idx < 0 || idx >= state.batchRows.length) {
          return;
        }
        var q = num(el.value || 0);
        if (q < 0) {
          q = 0;
        }
        state.batchRows[idx].dispatch_qty = q;
        renderBatchRows();
      });
    }

    nodes.filterApplyBtn.addEventListener('click', function () {
      loadTable();
      loadDashboardStats();
    });

    nodes.filterResetBtn.addEventListener('click', function () {
      nodes.filterFrom.value = '';
      nodes.filterTo.value = '';
      nodes.filterClient.value = '';
      nodes.filterItem.value = '';
      nodes.filterStatus.value = '';
      nodes.searchInput.value = '';
      loadTable();
      loadDashboardStats();
    });

    var searchTimer = null;
    nodes.searchInput.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        loadTable();
        loadDashboardStats();
      }, 220);
    });

    nodes.tableBody.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-ds-action]');
      if (!btn) {
        return;
      }
      var action = String(btn.getAttribute('data-ds-action') || '');
      var id = parseInt(btn.getAttribute('data-id') || '0', 10);
      if (id > 0) {
        onRowAction(action, id);
      }
    });

    if (nodes.insightTabs) {
      nodes.insightTabs.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-ds-insight-tab]');
        if (!btn) {
          return;
        }
        var tab = String(btn.getAttribute('data-ds-insight-tab') || '').trim();
        if (!tab) {
          return;
        }
        state.activeInsightTab = tab;
        renderInsightTabs();
        renderItemWiseInsight();
      });
    }

    if (nodes.itemTopLimit) {
      nodes.itemTopLimit.addEventListener('change', function () {
        state.lastItemInsightHash = '';
        renderItemWiseInsight();
      });
    }

    nodes.exportExcelBtn.addEventListener('click', exportExcel);
    nodes.exportPdfBtn.addEventListener('click', exportPdfReport);
    nodes.printTableBtn.addEventListener('click', printTable);

    if (nodes.reportApplyBtn) {
      nodes.reportApplyBtn.addEventListener('click', function () {
        clearReportDrillFilters();
        loadDispatchReports();
      });
    }

    if (nodes.reportResetBtn) {
      nodes.reportResetBtn.addEventListener('click', function () {
        if (nodes.reportFrom) {
          nodes.reportFrom.value = '';
        }
        if (nodes.reportTo) {
          nodes.reportTo.value = '';
        }
        if (nodes.reportClient) {
          nodes.reportClient.value = '';
        }
        if (nodes.reportTransportType) {
          nodes.reportTransportType.value = '';
        }
        if (nodes.reportItem) {
          nodes.reportItem.value = '';
        }
        if (nodes.reportSearchInput) {
          nodes.reportSearchInput.value = '';
        }
        if (nodes.reportSortBy) {
          nodes.reportSortBy.value = 'cost_desc';
        }
        clearReportDrillFilters();
        loadDispatchReports();
      });
    }

    if (nodes.reportClearDrillBtn) {
      nodes.reportClearDrillBtn.addEventListener('click', function () {
        clearReportDrillFilters();
        renderDispatchReports();
      });
    }

    if (nodes.reportSortBy) {
      nodes.reportSortBy.addEventListener('change', function () {
        renderReportTable();
      });
    }

    if (nodes.reportSearchInput) {
      var reportSearchTimer = null;
      nodes.reportSearchInput.addEventListener('input', function () {
        clearTimeout(reportSearchTimer);
        reportSearchTimer = setTimeout(function () {
          renderReportTable();
        }, 160);
      });
    }

    if (nodes.reportPrintBtn) {
      nodes.reportPrintBtn.addEventListener('click', printDispatchReport);
    }

    if (nodes.reportPdfBtn) {
      nodes.reportPdfBtn.addEventListener('click', exportDispatchReportPdf);
    }

    nodes.closeViewModal.addEventListener('click', closeChallanView);
    nodes.viewModal.addEventListener('click', function (ev) {
      if (ev.target === nodes.viewModal) {
        closeChallanView();
      }
    });

    nodes.printChallanBtn.addEventListener('click', function () {
      if (!state.challanRow) {
        showMessage('No dispatch data available', 'warning');
        return;
      }
      printHtml('Dispatch Challan', renderChallanHtml(state.challanRow, state.challanBatchItems));
    });

    nodes.pdfChallanBtn.addEventListener('click', function () {
      if (!state.challanRow) {
        showMessage('No dispatch data available', 'warning');
        return;
      }
      printHtml('Dispatch Challan PDF', renderChallanHtml(state.challanRow, state.challanBatchItems));
    });
  }

  function boot() {
    nodes.entryDate.value = today();
    nodes.dispatchDate.value = today();
    switchModuleTab('operations');
    getRequestedDispatchEntryId();
    bindEvents();
    fetchNextDispatchId();
    parsePrefill();
    renderBatchRows();
    loadTable();
    loadDashboardStats();
  }

  boot();
})();
