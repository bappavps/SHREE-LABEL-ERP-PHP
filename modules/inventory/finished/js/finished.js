(function () {
  'use strict';

  var fg_root = document.getElementById('fgStockModule');
  if (!fg_root) {
    return;
  }

  var fg_co = {
    name:    String(fg_root.getAttribute('data-co-name')    || 'ERP'),
    tagline: String(fg_root.getAttribute('data-co-tagline') || ''),
    address: String(fg_root.getAttribute('data-co-address') || ''),
    phone:   String(fg_root.getAttribute('data-co-phone')   || ''),
    email:   String(fg_root.getAttribute('data-co-email')   || ''),
    gst:     String(fg_root.getAttribute('data-co-gst')     || ''),
    logo:    String(fg_root.getAttribute('data-co-logo')    || '')
  };

  var fg_state = {
    apiUrl: String(fg_root.getAttribute('data-api-url') || ''),
    dispatchUrl: String(fg_root.getAttribute('data-dispatch-url') || ''),
    csrf: String(fg_root.getAttribute('data-csrf-token') || ''),
    isAdmin: String(fg_root.getAttribute('data-is-admin') || '') === '1',
    canManageRows: String(fg_root.getAttribute('data-can-manage-rows') || fg_root.getAttribute('data-is-admin') || '') === '1',
    tabs: [
      { key: 'printing_label', label: 'Printing Label', color: '#DC2626' },
      { key: 'pos_paper_roll', label: 'POS & Paper Roll', color: '#2563EB' },
      { key: 'one_ply', label: '1 Ply', color: '#166534' },
      { key: 'two_ply', label: '2 Ply', color: '#0f766e' },
      { key: 'barcode', label: 'Barcode', color: '#16A34A' },
      { key: 'ribbon', label: 'Ribbon', color: '#EA580C' },
      { key: 'core', label: 'Core', color: '#0F766E' },
      { key: 'carton', label: 'Carton', color: '#92400E' }
    ],
    activeTab: 'printing_label',
    rows: [],
    filteredRows: [],
    summary: { total_items: 0, total_quantity: 0 },
    reportPeriod: 'month',
    report: { opening_stock: 0, inward_stock: 0, dispatch_qty: 0, closing_stock: 0, months: [] },
    search: '',
    sortKey: '',
    sortDir: 'asc',
    filters: {},
    activeFilterCol: '',
    activeFilterParent: null,
    page: 1,
    perPage: 15,
    visibleColumns: {},
    editId: 0,
    importData: null,
    importMapping: {},
    itemSummary: [],
    prcData: null,
    prcLoading: false,
    tabCounts: {}
  };

  var fg_nodes = {
    tabs: document.getElementById('fgTabs'),
    headerStrip: document.getElementById('fgHeaderStrip'),
    summaryItems: document.getElementById('fgSummaryItems'),
    summaryQty: document.getElementById('fgSummaryQty'),
    summaryOpening: document.getElementById('fgSummaryOpening'),
    summaryInward: document.getElementById('fgSummaryInward'),
    summaryDispatch: document.getElementById('fgSummaryDispatch'),
    summaryClosing: document.getElementById('fgSummaryClosing'),
    reportMonths: document.getElementById('fgReportMonths'),
    itemSummaryMeta: document.getElementById('fgItemSummaryMeta'),
    itemSummarySelect: document.getElementById('fgItemSummarySelect'),
    itemSummaryTotal: document.getElementById('fgItemSummaryTotal'),
    viewItemDetailsBtn: document.getElementById('fgViewItemDetailsBtn'),
    reportPeriod: document.getElementById('fgReportPeriod'),
    searchInput: document.getElementById('fgSearchInput'),
    tableHead: document.getElementById('fgTableHead'),
    tableBody: document.getElementById('fgTableBody'),
    pagination: document.getElementById('fgPagination'),
    columnMenu: document.getElementById('fgColumnMenu'),
    entryModal: document.getElementById('fgEntryModal'),
    entryModalTitle: document.getElementById('fgEntryModalTitle'),
    entryForm: document.getElementById('fgEntryForm'),
    entryFormGrid: document.getElementById('fgEntryFormGrid'),
    entrySubmitBtn: document.getElementById('fgEntrySubmitBtn'),
    viewModal: document.getElementById('fgViewModal'),
    viewModalTitle: document.getElementById('fgViewModalTitle'),
    viewModalBody: document.getElementById('fgViewModalBody'),
    importModal: document.getElementById('fgImportModal'),
    importFile: document.getElementById('fgImportFile'),
    importMapping: document.getElementById('fgImportMapping'),
    importPreview: document.getElementById('fgImportPreview')
  };

  function fg_showMessage(msg, type) {
    if (typeof window.showERPToast === 'function') {
      window.showERPToast(String(msg || ''), type || 'info');
      return;
    }
    if (typeof window.erpCenterMessage === 'function') {
      window.erpCenterMessage(String(msg || ''), { title: 'Notification' });
    }
  }

  function fg_confirm(msg, onOk) {
    if (typeof window.showERPConfirm === 'function') {
      window.showERPConfirm(String(msg || ''), onOk, { title: 'Please Confirm', okLabel: 'Confirm', cancelLabel: 'Cancel' });
      return;
    }
    if (typeof window.erpCenterMessage === 'function') {
      window.erpCenterMessage(String(msg || ''), { title: 'Please Confirm', actionLabel: 'Confirm', action: onOk });
    }
  }

  function fg_escapeHtml(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function fg_num(v) {
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function fg_fmt(v) {
    return Number(fg_num(v).toFixed(3)).toLocaleString('en-IN');
  }

  function fg_filterToken(v) {
    var s = String(v == null ? '' : v).trim().toLowerCase();
    return s ? s : '__blank__';
  }

  function fg_packRemarks(note, extra) {
    var payload = {
      note: String(note || '').trim(),
      extra: extra && typeof extra === 'object' ? extra : {}
    };
    return JSON.stringify(payload);
  }

  function fg_parseRemarks(raw) {
    var txt = String(raw || '').trim();
    if (!txt) {
      return { note: '', extra: {} };
    }
    if (txt.charAt(0) === '{' && txt.charAt(txt.length - 1) === '}') {
      try {
        var parsed = JSON.parse(txt);
        if (parsed && typeof parsed === 'object') {
          return {
            note: String(parsed.note || ''),
            extra: parsed.extra && typeof parsed.extra === 'object' ? parsed.extra : {}
          };
        }
      } catch (e) {}
    }
    return { note: txt, extra: {} };
  }

  function fg_tabByKey(key) {
    for (var i = 0; i < fg_state.tabs.length; i += 1) {
      if (fg_state.tabs[i].key === key) {
        return fg_state.tabs[i];
      }
    }
    return fg_state.tabs[0];
  }

  function fg_currentTab() {
    return fg_tabByKey(fg_state.activeTab);
  }

  function fg_hexToRgba(hex, alpha) {
    var raw = String(hex || '').trim().replace('#', '');
    if (raw.length === 3) {
      raw = raw.charAt(0) + raw.charAt(0) + raw.charAt(1) + raw.charAt(1) + raw.charAt(2) + raw.charAt(2);
    }
    if (!/^[0-9a-fA-F]{6}$/.test(raw)) {
      return 'rgba(37,99,235,' + String(alpha) + ')';
    }
    var r = parseInt(raw.slice(0, 2), 16);
    var g = parseInt(raw.slice(2, 4), 16);
    var b = parseInt(raw.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + String(alpha) + ')';
  }

  // ── PRC suggestion helpers ────────────────────────────────────────────────
  function fg_loadPrcData(callback) {
    if (fg_state.prcData !== null) {
      if (callback) callback(fg_state.prcData);
      return;
    }
    if (fg_state.prcLoading) {
      var waitInterval = setInterval(function () {
        if (!fg_state.prcLoading) {
          clearInterval(waitInterval);
          if (callback) callback(fg_state.prcData || []);
        }
      }, 80);
      return;
    }
    fg_state.prcLoading = true;
    var url = fg_state.apiUrl + '?action=get_prc_suggestions&_=' + Date.now();
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url);
    xhr.onload = function () {
      fg_state.prcLoading = false;
      if (xhr.status === 200) {
        try {
          var res = JSON.parse(xhr.responseText);
          fg_state.prcData = Array.isArray(res.rows) ? res.rows : [];
        } catch (e) {
          fg_state.prcData = [];
        }
      } else {
        fg_state.prcData = [];
      }
      if (callback) callback(fg_state.prcData);
    };
    xhr.onerror = function () {
      fg_state.prcLoading = false;
      fg_state.prcData = [];
      if (callback) callback([]);
    };
    xhr.send();
  }

  // Mapping: finished form field → prc table field
  var fg_prcFieldMap = {
    item_name:     'item_name',
    width:         'width_mm',
    length:        'length_mtr',
    gsm:           'gsm',
    size:          'size',
    core_size:     'core',
    core_type:     'core_type',
    material_type: 'paper_type'
  };

  function fg_prcUnique(prcRows, prcField) {
    var seen = {};
    var out = [];
    for (var i = 0; i < prcRows.length; i += 1) {
      var v = String(prcRows[i][prcField] || '').trim();
      if (v && !seen[v]) {
        seen[v] = true;
        out.push(v);
      }
    }
    return out;
  }

  function fg_markSuggestPending(input) {
    if (!input) return;
    var wrap = input.closest('.fg-form-field');
    if (!wrap) return;
    wrap.classList.add('fg-suggest-track');
    wrap.classList.remove('fg-suggest-ok');
    wrap.classList.add('fg-suggest-pending');
  }

  function fg_markSuggestOk(input) {
    if (!input) return;
    var wrap = input.closest('.fg-form-field');
    if (!wrap) return;
    wrap.classList.add('fg-suggest-track');
    wrap.classList.remove('fg-suggest-pending');
    wrap.classList.add('fg-suggest-ok');
  }

  function fg_hasPrcMatch(prcRows, prcField, rawVal) {
    var val = String(rawVal || '').trim().toLowerCase();
    if (!val) return false;
    for (var i = 0; i < prcRows.length; i += 1) {
      var got = String(prcRows[i][prcField] || '').trim().toLowerCase();
      if (got && got === val) {
        return true;
      }
    }
    return false;
  }

  function fg_attachPrcSuggestions(prcRows) {
    if (!fg_nodes.entryFormGrid) return;
    var formFields = fg_prcFieldMap;

    // Default state: all form fields yellow in Add Stock (POS & Paper Roll)
    var allWraps = fg_nodes.entryFormGrid.querySelectorAll('.fg-form-field');
    for (var aw = 0; aw < allWraps.length; aw += 1) {
      allWraps[aw].classList.add('fg-suggest-track');
      allWraps[aw].classList.remove('fg-suggest-ok');
      allWraps[aw].classList.add('fg-suggest-pending');
    }

    // Build / refresh datalists for each mapped field
    for (var fgField in formFields) {
      if (!Object.prototype.hasOwnProperty.call(formFields, fgField)) continue;
      var prcField = formFields[fgField];
      var input = fg_nodes.entryFormGrid.querySelector('[data-fg-field="' + fgField + '"]');
      if (!input || input.tagName.toLowerCase() !== 'input') continue;

      var dlId = 'fg-prc-dl-' + fgField;
      var dl = document.getElementById(dlId);
      if (!dl) {
        dl = document.createElement('datalist');
        dl.id = dlId;
        document.body.appendChild(dl);
      }
      dl.innerHTML = '';
      var vals = fg_prcUnique(prcRows, prcField);
      for (var vi = 0; vi < vals.length; vi += 1) {
        var opt = document.createElement('option');
        opt.value = vals[vi];
        dl.appendChild(opt);
      }

      input.setAttribute('list', dlId);
      input.setAttribute('autocomplete', 'off');

      (function (boundInput, boundPrcField) {
        function refreshState() {
          if (fg_hasPrcMatch(prcRows, boundPrcField, boundInput.value)) {
            fg_markSuggestOk(boundInput);
          } else {
            fg_markSuggestPending(boundInput);
          }
        }
        boundInput.addEventListener('input', refreshState);
        boundInput.addEventListener('change', refreshState);
        refreshState();
      })(input, prcField);
    }

    // Auto-fill other fields when item_name is chosen
    var itemNameInput = fg_nodes.entryFormGrid.querySelector('[data-fg-field="item_name"]');
    if (!itemNameInput) return;

    function fg_prcAutoFill() {
      var typed = itemNameInput.value.trim().toLowerCase();
      if (!typed) return;
      // Find first matching prc row
      var match = null;
      for (var ri = 0; ri < prcRows.length; ri += 1) {
        if (String(prcRows[ri].item_name || '').trim().toLowerCase() === typed) {
          match = prcRows[ri];
          break;
        }
      }
      if (!match) return;
      // Fill each mapped field (skip item_name itself)
      var skipFields = { item_name: true };
      for (var ff in formFields) {
        if (!Object.prototype.hasOwnProperty.call(formFields, ff)) continue;
        if (skipFields[ff]) continue;
        var inp = fg_nodes.entryFormGrid.querySelector('[data-fg-field="' + ff + '"]');
        if (!inp) continue;
        // Only fill if currently empty
        if (String(inp.value || '').trim() !== '') continue;
        var prcVal = String(match[formFields[ff]] || '').trim();
        if (prcVal) {
          inp.value = prcVal;
          // Trigger input event so calc functions catch it
          inp.dispatchEvent(new Event('input', { bubbles: true }));
          inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }

      // Item itself also should become green when valid match selected
      itemNameInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Fire on change (when user picks from datalist or leaves field)
    itemNameInput.addEventListener('change', fg_prcAutoFill);
    // Also fire on a small delay after input (covers manual type + select)
    var prcTimer = null;
    itemNameInput.addEventListener('input', function () {
      clearTimeout(prcTimer);
      prcTimer = setTimeout(fg_prcAutoFill, 400);
    });
  }
  // ─────────────────────────────────────────────────────────────────────────

  function fg_setAccent() {
    var tab = fg_currentTab();
    fg_root.style.setProperty('--fg-accent', tab.color);
    fg_root.style.setProperty('--fg-accent-soft', tab.color + '22');
    fg_root.style.setProperty('--fg-accent-text', tab.color);
    fg_root.style.setProperty('--fg-modal-surface', fg_hexToRgba(tab.color, 0.10));
    fg_root.style.setProperty('--fg-modal-head', fg_hexToRgba(tab.color, 0.16));
    fg_root.style.setProperty('--fg-modal-border', fg_hexToRgba(tab.color, 0.35));
    fg_root.style.setProperty('--fg-modal-shadow', fg_hexToRgba(tab.color, 0.20));
    fg_root.style.setProperty('--fg-input-surface', fg_hexToRgba(tab.color, 0.11));
    fg_root.style.setProperty('--fg-input-border', fg_hexToRgba(tab.color, 0.38));
    fg_root.style.setProperty('--fg-input-focus-ring', fg_hexToRgba(tab.color, 0.28));
    // Row tint variables — 8 shades of the active tab color
    var rowAlphas = [0.05, 0.08, 0.05, 0.08, 0.05, 0.08, 0.05, 0.08];
    for (var ci = 0; ci < 8; ci++) {
      fg_root.style.setProperty('--fg-row-c' + ci, fg_hexToRgba(tab.color, rowAlphas[ci]));
    }
    fg_root.style.setProperty('--fg-row-hover', fg_hexToRgba(tab.color, 0.18));
    if (fg_nodes.headerStrip) {
      fg_nodes.headerStrip.style.background = tab.color;
    }
  }

  function fg_getColumns(tabKey) {
    if (tabKey === 'pos_paper_roll' || tabKey === 'one_ply' || tabKey === 'two_ply') {
      return [
        { key: 'sl_no', label: 'SL.NO', numeric: true },
        { key: 'packing_id', label: 'Packing ID' },
        { key: 'production_date', label: 'Production Date' },
        { key: 'item_name', label: 'ITEM' },
        { key: 'size', label: 'SIZE' },
        { key: 'width', label: 'Width' },
        { key: 'length', label: 'Length' },
        { key: 'gsm', label: 'GSM' },
        { key: 'core_size', label: 'Core size' },
        { key: 'core_type', label: 'core type' },
        { key: 'paper_company', label: 'Paper COMPANY' },
        { key: 'material_type', label: 'Material Type' },
        { key: 'batch_no', label: 'Batch No.' },
        { key: 'barcode', label: 'Barcode' },
        { key: 'pcs', label: 'PCS', numeric: true },
        { key: 'per_carton', label: 'Per carton', numeric: true },
        { key: 'carton', label: 'CARTON', numeric: true },
        { key: 'after_packing_qty', label: 'After Packing Qty', numeric: true },
        { key: 'current_total', label: 'Current Total', numeric: true },
        { key: 'available_for_dispatch', label: 'Available for Dispatch', numeric: true },
        { key: 'stock_status', label: 'Status' }
      ];
    }

    if (tabKey === 'barcode') {
      return [
        { key: 'sl_no', label: 'SL.NO', numeric: true },
        { key: 'planning_id', label: 'Planning ID' },
        { key: 'production_date', label: 'Production Date' },
        { key: 'status', label: 'Status' },
        { key: 'item_name', label: 'Item Name' },
        { key: 'pcs_per_roll', label: 'PCS PER ROLL', numeric: true },
        { key: 'total_roll', label: 'Total Roll', numeric: true },
        { key: 'size', label: 'SIZE' },
        { key: 'width', label: 'Width' },
        { key: 'length', label: 'Length' },
        { key: 'ups', label: 'UPS' },
        { key: 'paper_company', label: 'Paper COMPANY' },
        { key: 'label_gap', label: 'Label Gap' },
        { key: 'die_type', label: 'Die Type' },
        { key: 'carton', label: 'CARTON', numeric: true },
        { key: 'batch_no', label: 'BATCH NO.' },
        { key: 'roll_per_cartoon', label: 'ROLL PER CARTOON', numeric: true },
        { key: 'after_packing_qty', label: 'After Packing Qty', numeric: true },
        { key: 'current_total', label: 'Current Total', numeric: true },
        { key: 'available_for_dispatch', label: 'Available for Dispatch', numeric: true }
      ];
    }

    if (tabKey === 'printing_label') {
      return [
        { key: 'sl_no', label: 'SL.NO', numeric: true },
        { key: 'packing_id', label: 'Packing ID' },
        { key: 'production_date', label: 'Production Date' },
        { key: 'job_name', label: 'Job Name' },
        { key: 'order_date', label: 'Order Date' },
        { key: 'dispatch_date', label: 'Dispatch Date' },
        { key: 'size', label: 'Size' },
        { key: 'width', label: 'Width' },
        { key: 'length', label: 'Length' },
        { key: 'gsm', label: 'GSM' },
        { key: 'mtrs', label: 'MTRS' },
        { key: 'qty', label: 'QTY', numeric: true },
        { key: 'qty_per_roll', label: 'Qty/Roll', numeric: true },
        { key: 'direction', label: 'Direction' },
        { key: 'core_size', label: 'Core size' },
        { key: 'core_type', label: 'core type' },
        { key: 'paper_company', label: 'Paper COMPANY' },
        { key: 'material_type', label: 'Material Type' },
        { key: 'per_carton', label: 'Per carton', numeric: true },
        { key: 'carton', label: 'CARTON', numeric: true },
        { key: 'after_packing_qty', label: 'After Packing Qty', numeric: true },
        { key: 'current_total', label: 'Current Total', numeric: true },
        { key: 'available_for_dispatch', label: 'Available for Dispatch', numeric: true },
        { key: 'status', label: 'Status' }
      ];
    }

    if (tabKey === 'printing_roll') {
      return [
        { key: 'item_name', label: 'Item Name' },
        { key: 'size', label: 'Size' },
        { key: 'material', label: 'Material' },
        { key: 'print_type', label: 'Print Type' },
        { key: 'quantity', label: 'Quantity', numeric: true },
        { key: 'unit', label: 'Unit' },
        { key: 'batch_no', label: 'Batch' },
        { key: 'date', label: 'Date' },
        { key: 'remarks', label: 'Remarks' }
      ];
    }

    if (tabKey === 'ribbon') {
      return [
        { key: 'ribbon_type', label: 'Ribbon Type' },
        { key: 'width', label: 'Width' },
        { key: 'length', label: 'Length' },
        { key: 'quantity', label: 'Quantity', numeric: true },
        { key: 'unit', label: 'Unit' },
        { key: 'location', label: 'Location' },
        { key: 'batch_no', label: 'Batch' },
        { key: 'date', label: 'Date' }
      ];
    }

    if (tabKey === 'core') {
      return [
        { key: 'core_type', label: 'Core Type' },
        { key: 'size', label: 'Size' },
        { key: 'quantity', label: 'Quantity', numeric: true },
        { key: 'unit', label: 'Unit' },
        { key: 'location', label: 'Location' },
        { key: 'batch_no', label: 'Batch' },
        { key: 'date', label: 'Date' }
      ];
    }

    return [
      { key: 'carton_type', label: 'Carton Type' },
      { key: 'size', label: 'Size' },
      { key: 'strength', label: 'Strength' },
      { key: 'quantity', label: 'Quantity', numeric: true },
      { key: 'unit', label: 'Unit' },
      { key: 'location', label: 'Location' },
      { key: 'batch_no', label: 'Batch' },
      { key: 'date', label: 'Date' }
    ];
  }

  function fg_value(row, key) {
    var extra = row._fgExtra || {};
    function extraPick(keys) {
      for (var i = 0; i < keys.length; i += 1) {
        var got = extra[keys[i]];
        if (String(got == null ? '' : got).trim() !== '') {
          return String(got);
        }
      }
      return '';
    }
    function fmtQty(v) {
      return fg_num(v).toFixed(3).replace(/\.000$/, '');
    }
    function mixedAdjustedTotal() {
      var category = String((row && row.category) || fg_state.activeTab || '');
      var supportsMixed = ['pos_paper_roll', 'one_ply', 'two_ply', 'barcode', 'printing_label', 'printing_roll'].indexOf(category) !== -1;

      var currentRaw = fg_num(row.quantity);
      var dispatchQtyTotal = fg_num(row.dispatch_qty_total || 0);
      var snapshotRawStr = extraPick(['total', 'total_quantity']);
      var snapshotRaw = snapshotRawStr !== '' ? fg_num(snapshotRawStr) : 0;

      var productionRaw = currentRaw + Math.max(0, dispatchQtyTotal);
      if (snapshotRaw > productionRaw) {
        productionRaw = snapshotRaw;
      }
      if (productionRaw <= 0) {
        productionRaw = currentRaw;
      }

      function calcMixedExtra(baseRaw) {
        if (!supportsMixed || baseRaw <= 0) {
          return 0;
        }

        var mixedExtra = 0;

        if (category === 'barcode' || category === 'printing_label') {
          var mixedEnabled = String(extra.mixed_enabled || '0') === '1';
          var rpc = Math.floor(fg_num(extraPick(['roll_per_cartoon', 'roll_per_carton', 'per_carton'])));

          if (mixedEnabled) {
            mixedExtra = Math.max(0, fg_num(extra.mixed_extra_rolls || 0));
          } else {
            var totalRoll = fg_num(extraPick(['total_roll', 'total_rolls', 'total_roll_value']));
            if (totalRoll <= 0) {
              var pcsPerRoll = fg_num(extraPick(['pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll', 'qty_per_roll']));
              if (pcsPerRoll > 0 && baseRaw > 0) {
                totalRoll = Math.ceil(baseRaw / pcsPerRoll);
              }
            }

            if (rpc > 0 && totalRoll > 0) {
              mixedExtra = totalRoll % rpc;
            } else {
              mixedExtra = totalRoll;
            }
          }
        } else {
          var mixedEnabledOther = String(extra.mixed_enabled || '0') === '1';
          if (mixedEnabledOther) {
            mixedExtra = Math.max(0, fg_num(extra.mixed_extra_rolls || 0));
          } else {
            var perCarton = fg_num(extraPick(['per_carton']));
            if (perCarton > 0 && baseRaw > 0) {
              mixedExtra = baseRaw % perCarton;
            } else {
              mixedExtra = baseRaw;
            }
          }
        }

        return Math.max(0, fg_num(mixedExtra));
      }

      var mixedExtra = calcMixedExtra(productionRaw);
      var packedNet = Math.max(0, fg_num(productionRaw - mixedExtra));
      var availableNet = Math.max(0, fg_num(currentRaw - mixedExtra));
      return {
        raw: currentRaw,
        production_raw: productionRaw,
        extra: mixedExtra,
        packed_net: packedNet,
        available_net: availableNet
      };
    }
    if (key === 'sl_no') {
      return row._fgSerial || '';
    }
    if (key === 'planning_id') {
      return String(extraPick(['planning_id', 'packing_id']) || row.item_code || '');
    }
    if (key === 'packing_id') {
      return String(extra.packing_id || row.item_code || '');
    }
    if (key === 'production_date') {
      return row.date || '';
    }
    if (key === 'status') {
      var statusFromExtra = extraPick(['status', 'job_status', 'finished_status']);
      if (statusFromExtra !== '') {
        return statusFromExtra;
      }
      if (fg_state.activeTab === 'barcode') {
        return fg_num(row.quantity) <= 0 ? 'Dispatched' : 'Ready to Dispatch';
      }
      return fg_num(row.quantity) <= 0 ? 'Dispatched' : 'In Stock';
    }
    if (key === 'job_name') {
      return extraPick(['job_name']) || row.item_name || '';
    }
    if (key === 'order_date') {
      return extraPick(['order_date']);
    }
    if (key === 'dispatch_date') {
      return extraPick(['dispatch_date']);
    }
    if (key === 'mtrs') {
      return extraPick(['mtrs', 'meter']);
    }
    if (key === 'qty') {
      return fmtQty(mixedAdjustedTotal().packed_net);
    }
    if (key === 'qty_per_roll') {
      return extraPick(['qty_per_roll', 'pcs_per_roll', 'pieces_per_roll', 'barcode_in_1_roll']);
    }
    if (key === 'direction') {
      return extraPick(['direction']);
    }
    if (key === 'pcs_per_roll') {
      return extraPick(['pcs_per_roll', 'pieces_per_roll', 'pices_per_roll', 'barcode_in_1_roll', 'qty_per_roll']);
    }
    if (key === 'total_roll') {
      if (fg_state.activeTab === 'barcode') {
        var currentQty = fg_num(mixedAdjustedTotal().available_net);
        var currentPcsPerRoll = fg_num(extraPick(['pcs_per_roll', 'pieces_per_roll', 'pices_per_roll', 'barcode_in_1_roll', 'qty_per_roll']));
        if (currentQty > 0 && currentPcsPerRoll > 0) {
          return String(Math.floor(currentQty / currentPcsPerRoll));
        }
      }

      var totalRollDirect = extraPick(['total_roll', 'total_rolls', 'total_roll_value']);
      if (totalRollDirect !== '') {
        return totalRollDirect;
      }

      var totalQtyRaw = extraPick(['total_quantity', 'total']);
      if (totalQtyRaw === '') {
        totalQtyRaw = String(row.quantity == null ? '' : row.quantity);
      }
      var pcsPerRollRaw = extraPick(['pcs_per_roll', 'pieces_per_roll', 'pices_per_roll', 'barcode_in_1_roll', 'qty_per_roll']);

      var totalQtyNum = fg_num(totalQtyRaw);
      var pcsPerRollNum = fg_num(pcsPerRollRaw);
      if (totalQtyNum > 0 && pcsPerRollNum > 0) {
        return String(Math.max(1, Math.ceil(totalQtyNum / pcsPerRollNum)));
      }

      return '';
    }
    if (key === 'width' || key === 'length' || key === 'core_size' || key === 'core_type' || key === 'paper_company' || key === 'barcode' || key === 'per_carton') {
      return String(extra[key] || '');
    }
    if (key === 'carton') {
      // For carton category, show actual carton quantity from inventory
      if (row.category === 'carton') {
        return fmtQty(row.quantity);
      }

      // For barcode tab, calculate carton by current full rolls and roll-per-carton.
      if (fg_state.activeTab === 'barcode') {
        var barcodeQty = fg_num(mixedAdjustedTotal().available_net);
        var barcodePcsPerRoll = fg_num(extraPick(['pcs_per_roll', 'pieces_per_roll', 'pices_per_roll', 'barcode_in_1_roll', 'qty_per_roll']));
        var barcodeRollPerCarton = fg_num(extraPick(['roll_per_cartoon', 'roll_per_carton']));
        if (barcodeQty > 0 && barcodePcsPerRoll > 0 && barcodeRollPerCarton > 0) {
          var barcodeFullRolls = Math.floor(barcodeQty / barcodePcsPerRoll);
          return String(Math.max(0, Math.floor(barcodeFullRolls / barcodeRollPerCarton)));
        }
      }

      // For other categories, calculate cartons needed based on quantity and ratio
      var ratioRaw = extraPick(['per_carton', 'roll_per_cartoon', 'roll_per_carton']);
      var ratio = fg_num(ratioRaw);
      if (ratio > 0) {
        var netQty = mixedAdjustedTotal().available_net;
        return String(Math.max(0, Math.floor(netQty / ratio)));
      }
      return '';
    }
    if (key === 'ups') {
      var fromRow = String((row && (row.up_in_roll || row.up_in_production)) || '').trim();
      if (fromRow !== '') {
        return fromRow;
      }
      return extraPick(['up_in_roll', 'ups_in_roll', 'ups', 'up_in_production', 'ups_in_die']);
    }
    if (key === 'label_gap') {
      return extraPick(['label_gap']);
    }
    if (key === 'die_type' || key === 'die_type_dup') {
      return extraPick(['die_type']);
    }
    if (key === 'roll_per_cartoon') {
      return extraPick(['roll_per_cartoon', 'roll_per_carton', 'per_carton']);
    }
    if (key === 'total_quantity') {
      return fmtQty(mixedAdjustedTotal().packed_net);
    }
    if (key === 'material_type') {
      return String(extra.material_type || row.sub_type || '');
    }
    if (key === 'pcs') {
      return fmtQty(mixedAdjustedTotal().available_net);
    }
    if (key === 'total') {
      return fmtQty(mixedAdjustedTotal().packed_net);
    }
    if (key === 'after_packing_qty') {
      return fmtQty(mixedAdjustedTotal().packed_net);
    }
    if (key === 'current_total') {
      return fmtQty(mixedAdjustedTotal().available_net);
    }
    if (key === 'available_for_dispatch') {
      return fmtQty(mixedAdjustedTotal().available_net);
    }
    if (key === 'stock_status') {
      var q = fg_num(row.quantity);
      if (q <= 0) {
        return 'Dispatched';
      }
      if (q <= 10) {
        return 'Low';
      }
      return 'In Stock';
    }
    if (key === 'type' || key === 'barcode_type' || key === 'print_type' || key === 'ribbon_type' || key === 'core_type' || key === 'carton_type') {
      return row.sub_type || '';
    }
    if (key === 'ply' || key === 'material' || key === 'width' || key === 'length' || key === 'strength') {
      return String(extra[key] || '');
    }
    if (key === 'remarks') {
      return row._fgNote || '';
    }
    if (key === 'quantity') {
      return fmtQty(row.quantity);
    }
    if (key === 'date') {
      return row.date || '';
    }
    return String(row[key] == null ? '' : row[key]);
  }

  function fg_resolveBarcodeValue(row) {
    var extra = row ? (row._fgExtra || {}) : {};
    var raw = String(extra.barcode || '').trim();
    if (/^J[0-9A-Z]{1,10}$/i.test(raw) || /^JOB\s*:/i.test(raw) || /\/modules\/scan\//i.test(raw)) {
      return raw;
    }
    var batchNo = String((row && row.batch_no) || '').trim();
    if (batchNo) {
      return 'JOB:' + batchNo;
    }
    return raw;
  }

  function fg_barcodeHref(row) {
    var txt = fg_resolveBarcodeValue(row);
    if (!txt) {
      return '';
    }
    var apiUrl = String(fg_state.apiUrl || '');
    var baseUrl = apiUrl.replace(/\/modules\/inventory\/finished\/api\/finished_api\.php.*$/i, '');
    if (!baseUrl) {
      return '';
    }
    return baseUrl + '/modules/scan/index.php?qr=' + encodeURIComponent(txt);
  }

  function fg_renderCell(row, key) {
    var value = fg_value(row, key);
    if (key === 'after_packing_qty') {
      return '<span class="fg-qty-pill fg-qty-pill-packed">' + fg_escapeHtml(value) + '</span>';
    }
    if (key === 'current_total' || key === 'total_quantity') {
      return '<span class="fg-qty-pill fg-qty-pill-total">' + fg_escapeHtml(value) + '</span>';
    }
    if (key === 'total' && (fg_state.activeTab === 'pos_paper_roll' || fg_state.activeTab === 'one_ply' || fg_state.activeTab === 'two_ply')) {
      return '<span class="fg-qty-pill fg-qty-pill-total">' + fg_escapeHtml(value) + '</span>';
    }
    if (key === 'available_for_dispatch') {
      return '<span class="fg-qty-pill fg-qty-pill-available">' + fg_escapeHtml(value) + '</span>';
    }
    if (key === 'stock_status') {
      var cls = 'fg-status-instock';
      if (String(value) === 'Low') {
        cls = 'fg-status-low';
      } else if (String(value) === 'Dispatched') {
        cls = 'fg-status-dispatched';
      }
      return '<span class="fg-stock-badge ' + cls + '">' + fg_escapeHtml(value) + '</span>';
    }
    if (key === 'status') {
      return '<span class="fg-status-pill fg-status-pill-green">' + fg_escapeHtml(value || 'Ready to Dispatch') + '</span>';
    }
    if (key === 'barcode') {
      var barcodeValue = fg_resolveBarcodeValue(row) || String(value || '').trim();
      var href = fg_barcodeHref(row);
      if (href && barcodeValue !== '') {
        return '<a href="' + fg_escapeHtml(href) + '" target="_blank" rel="noopener noreferrer" style="color:#2563eb;font-weight:700;text-decoration:underline">' + fg_escapeHtml(barcodeValue) + '</a>';
      }
    }
    return fg_escapeHtml(value);
  }

  function fg_isDispatchTab() {
    return fg_state.activeTab === 'pos_paper_roll' || fg_state.activeTab === 'one_ply' || fg_state.activeTab === 'two_ply' || fg_state.activeTab === 'barcode' || fg_state.activeTab === 'printing_label';
  }

  function fg_dispatchRow(row) {
    if (!row) {
      return;
    }
    var base = String(fg_state.dispatchUrl || '').trim();
    if (!base) {
      fg_showMessage('Dispatch page URL not configured.', 'error');
      return;
    }
    var extra = row._fgExtra || {};
    var params = new URLSearchParams();
    var dispatchQty = fg_num(fg_value(row, 'available_for_dispatch'));
    params.set('item_id', String(row.id || ''));
    params.set('packing_id', String(extra.packing_id || row.item_code || ''));
    params.set('qty', String(dispatchQty));
    params.set('size', String(row.size || ''));
    params.set('batch', String(row.batch_no || ''));
    params.set('item_name', String(row.item_name || ''));
    params.set('unit', String(row.unit || 'PCS'));
    params.set('tab', String(fg_state.activeTab || ''));
    window.location.href = base + '?' + params.toString();
  }

  function fg_getFormSchema(tabKey) {
    if (tabKey === 'pos_paper_roll' || tabKey === 'one_ply' || tabKey === 'two_ply') {
      return [
        { name: 'sl_no', label: 'SL.NO', source: 'meta', readonly: true, save: false, importable: false },
        { name: 'packing_id', label: 'Packing ID', source: 'extra' },
        { name: 'date', label: 'Production Date', source: 'direct', type: 'date' },
        { name: 'item_name', label: 'ITEM', source: 'direct', required: true },
        { name: 'size', label: 'SIZE', source: 'direct' },
        { name: 'width', label: 'Width', source: 'extra' },
        { name: 'length', label: 'Length', source: 'extra' },
        { name: 'gsm', label: 'GSM', source: 'direct' },
        { name: 'core_size', label: 'Core size', source: 'extra' },
        { name: 'core_type', label: 'core type', source: 'extra' },
        { name: 'paper_company', label: 'Paper COMPANY', source: 'extra' },
        { name: 'material_type', label: 'Material Type', source: 'extra' },
        { name: 'batch_no', label: 'Batch No.', source: 'direct' },
        { name: 'barcode', label: 'Barcode', source: 'extra' },
        { name: 'quantity', label: 'PCS', source: 'direct', type: 'number', required: true },
        { name: 'per_carton', label: 'Per carton', source: 'extra', type: 'number' },
        { name: 'carton', label: 'CARTON', source: 'extra', type: 'number' },
        { name: 'total', label: 'TOTAL', source: 'extra', type: 'number' },
        { name: 'available_for_dispatch', label: 'Available for Dispatch', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'stock_status', label: 'Status', source: 'extra', readonly: true, save: false, importable: false },
        { name: 'unit', label: 'Unit', source: 'direct' },
        { name: 'location', label: 'Location', source: 'direct' },
        { name: 'note', label: 'Remarks', source: 'note', type: 'textarea', full: true }
      ];
    }

    if (tabKey === 'barcode') {
      return [
        { name: 'sl_no', label: 'SL.NO', source: 'meta', readonly: true, save: false, importable: false },
        { name: 'planning_id', label: 'Planning ID', source: 'extra', readonly: true },
        { name: 'date', label: 'Production Date', source: 'direct', type: 'date' },
        { name: 'status', label: 'Status', source: 'extra', readonly: true, save: false, importable: false },
        { name: 'item_name', label: 'Item Name', source: 'direct', required: true },
        { name: 'pcs_per_roll', label: 'PCS PER ROLL', source: 'extra', type: 'number' },
        { name: 'total_roll', label: 'Total Roll', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'size', label: 'SIZE', source: 'direct' },
        { name: 'width', label: 'Width', source: 'extra' },
        { name: 'length', label: 'Length', source: 'extra' },
        { name: 'ups', label: 'UPS', source: 'extra' },
        { name: 'paper_company', label: 'Paper COMPANY', source: 'extra' },
        { name: 'label_gap', label: 'Label Gap', source: 'extra' },
        { name: 'die_type', label: 'Die Type', source: 'extra' },
        { name: 'carton', label: 'CARTON', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'batch_no', label: 'BATCH NO.', source: 'direct' },
        { name: 'roll_per_cartoon', label: 'ROLL PER CARTOON', source: 'extra', type: 'number' },
        { name: 'quantity', label: 'TOTAL Quantity', source: 'direct', type: 'number', required: true },
        { name: 'available_for_dispatch', label: 'Available for Dispatch', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'note', label: 'Remarks', source: 'note', type: 'textarea', full: true }
      ];
    }

    if (tabKey === 'printing_label') {
      return [
        { name: 'sl_no', label: 'SL.NO', source: 'meta', readonly: true, save: false, importable: false },
        { name: 'packing_id', label: 'Packing ID', source: 'extra', readonly: true },
        { name: 'date', label: 'Production Date', source: 'direct', type: 'date' },
        { name: 'job_name', label: 'Job Name', source: 'extra' },
        { name: 'order_date', label: 'Order Date', source: 'extra' },
        { name: 'dispatch_date', label: 'Dispatch Date', source: 'extra' },
        { name: 'size', label: 'Size', source: 'direct' },
        { name: 'width', label: 'Width', source: 'extra' },
        { name: 'length', label: 'Length', source: 'extra' },
        { name: 'gsm', label: 'GSM', source: 'direct' },
        { name: 'mtrs', label: 'MTRS', source: 'extra' },
        { name: 'quantity', label: 'QTY', source: 'direct', type: 'number', required: true },
        { name: 'qty_per_roll', label: 'Qty/Roll', source: 'extra', type: 'number' },
        { name: 'direction', label: 'Direction', source: 'extra' },
        { name: 'core_size', label: 'Core size', source: 'extra' },
        { name: 'core_type', label: 'core type', source: 'extra' },
        { name: 'paper_company', label: 'Paper COMPANY', source: 'extra' },
        { name: 'material_type', label: 'Material Type', source: 'extra' },
        { name: 'per_carton', label: 'Per carton', source: 'extra', type: 'number' },
        { name: 'carton', label: 'CARTON', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'available_for_dispatch', label: 'Available for Dispatch', source: 'extra', type: 'number', readonly: true, save: false, importable: false },
        { name: 'status', label: 'Status', source: 'extra', readonly: true, save: false, importable: false },
        { name: 'note', label: 'Remarks', source: 'note', type: 'textarea', full: true }
      ];
    }

    if (tabKey === 'printing_roll') {
      return [
        { name: 'item_name', label: 'Item Name', source: 'direct', required: true },
        { name: 'size', label: 'Size', source: 'direct' },
        { name: 'material', label: 'Material', source: 'extra' },
        { name: 'sub_type', label: 'Print Type', source: 'direct' },
        { name: 'quantity', label: 'Quantity', source: 'direct', type: 'number', required: true },
        { name: 'unit', label: 'Unit', source: 'direct', required: true },
        { name: 'batch_no', label: 'Batch', source: 'direct' },
        { name: 'date', label: 'Date', source: 'direct', type: 'date' },
        { name: 'note', label: 'Remarks', source: 'note', type: 'textarea', full: true }
      ];
    }

    if (tabKey === 'ribbon') {
      return [
        { name: 'sub_type', label: 'Ribbon Type', source: 'direct', required: true },
        { name: 'width', label: 'Width', source: 'extra' },
        { name: 'length', label: 'Length', source: 'extra' },
        { name: 'quantity', label: 'Quantity', source: 'direct', type: 'number', required: true },
        { name: 'unit', label: 'Unit', source: 'direct', required: true },
        { name: 'location', label: 'Location', source: 'direct' },
        { name: 'batch_no', label: 'Batch', source: 'direct' },
        { name: 'date', label: 'Date', source: 'direct', type: 'date' }
      ];
    }

    if (tabKey === 'core') {
      return [
        { name: 'sub_type', label: 'Core Type (Paper / Plastic / Sink)', source: 'direct', required: true, type: 'select', options: ['Paper', 'Plastic', 'Sink'] },
        { name: 'size', label: 'Size', source: 'direct' },
        { name: 'quantity', label: 'Quantity', source: 'direct', type: 'number', required: true },
        { name: 'unit', label: 'Unit', source: 'direct', required: true },
        { name: 'location', label: 'Location', source: 'direct' },
        { name: 'batch_no', label: 'Batch', source: 'direct' },
        { name: 'date', label: 'Date', source: 'direct', type: 'date' }
      ];
    }

    var cartonSizeOptions = (function () {
      var seen = {};
      var list = [];
      var fallback = ['57x15', '57x25', '78x25', '75mm', 'Barcode', 'Medicine'];
      var rows = fg_state.rows || [];
      for (var ri = 0; ri < rows.length; ri += 1) {
        var sz = String(rows[ri].size || rows[ri].item_name || '').trim();
        if (sz && !seen[sz.toLowerCase()]) {
          seen[sz.toLowerCase()] = true;
          list.push(sz);
        }
      }
      if (!list.length) {
        list = fallback;
      }
      list.sort();
      if (!seen['custom']) { list.push('Custom'); }
      return list;
    }());
    return [
      { name: 'size', label: 'Size', source: 'direct', required: true, type: 'select', options: cartonSizeOptions },
      { name: 'custom_size', label: 'Custom Size', source: 'extra', placeholder: 'Type new size (for custom)' },
      { name: 'quantity', label: 'Value', source: 'direct', type: 'number', required: true }
    ];
  }

  var fg_tabIcons = {
    pos_paper_roll: 'bi bi-receipt-cutoff',
    one_ply: 'bi bi-layers',
    two_ply: 'bi bi-layers-fill',
    barcode: 'bi bi-upc-scan',
    printing_roll: 'bi bi-printer-fill',
    ribbon: 'bi bi-gift',
    core: 'bi bi-circle-half',
    carton: 'bi bi-box-seam-fill'
  };

  function fg_renderTabs() {
    var html = '';
    for (var i = 0; i < fg_state.tabs.length; i += 1) {
      var t = fg_state.tabs[i];
      var isActive = t.key === fg_state.activeTab;
      var count = fg_state.tabCounts[t.key] != null ? fg_state.tabCounts[t.key] : '';
      var icon = fg_tabIcons[t.key] || 'bi bi-folder';
      var btnStyle, badgeStyle;
      if (isActive) {
        btnStyle = 'background:' + t.color + ';color:#fff;border-color:' + t.color + ';box-shadow:0 6px 20px ' + t.color + '55;';
        badgeStyle = 'background:rgba(255,255,255,0.28);color:#fff;';
      } else {
        btnStyle = 'background:#fff;color:' + t.color + ';border-color:' + t.color + '55;';
        badgeStyle = 'background:' + t.color + '1a;color:' + t.color + ';';
      }
      html += '<button class="fg-tab-btn' + (isActive ? ' active' : '') + '" type="button" data-fg-action="switch-tab" data-tab="' + fg_escapeHtml(t.key) + '" style="' + btnStyle + '">' +
        '<i class="' + icon + '"></i>' +
        ' ' + fg_escapeHtml(t.label) +
        '<span class="fg-tab-count" style="' + badgeStyle + '">' + (count !== '' ? count : '…') + '</span>' +
        '</button>';
    }
    fg_nodes.tabs.innerHTML = html;
  }

  function fg_initVisibleColumns() {
    var cols = fg_getColumns(fg_state.activeTab);
    var vis = {};
    for (var i = 0; i < cols.length; i += 1) {
      vis[cols[i].key] = true;
    }
    fg_state.visibleColumns = vis;
  }

  function fg_api(action, params, method) {
    var m = method || 'GET';
    if (m === 'GET') {
      var q = new URLSearchParams();
      q.set('action', action);
      var keys = Object.keys(params || {});
      for (var i = 0; i < keys.length; i += 1) {
        q.set(keys[i], String(params[keys[i]] == null ? '' : params[keys[i]]));
      }
      return fetch(fg_state.apiUrl + '?' + q.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    var body = new FormData();
    body.set('action', action);
    body.set('csrf_token', fg_state.csrf);
    var postKeys = Object.keys(params || {});
    for (var j = 0; j < postKeys.length; j += 1) {
      var k = postKeys[j];
      var v = params[k];
      if (Array.isArray(v) || (v && typeof v === 'object')) {
        body.set(k, JSON.stringify(v));
      } else {
        body.set(k, String(v == null ? '' : v));
      }
    }

    return fetch(fg_state.apiUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function fg_loadSummary() {
    return fg_api('get_summary', { category: fg_state.activeTab }, 'GET')
      .then(function (res) {
        if (!res || !res.ok) {
          throw new Error((res && res.error) || 'Summary load failed.');
        }
        fg_state.summary = res.summary || { total_items: 0, total_quantity: 0 };
        fg_nodes.summaryItems.textContent = String(fg_state.summary.total_items || 0);
        fg_nodes.summaryQty.textContent = fg_fmt(fg_state.summary.total_quantity || 0);
        fg_loadPeriodReport();
      })
      .catch(function (err) {
        fg_nodes.summaryItems.textContent = '0';
        fg_nodes.summaryQty.textContent = '0';
        if (fg_nodes.summaryOpening) fg_nodes.summaryOpening.textContent = '0';
        if (fg_nodes.summaryInward) fg_nodes.summaryInward.textContent = '0';
        if (fg_nodes.summaryDispatch) fg_nodes.summaryDispatch.textContent = '0';
        if (fg_nodes.summaryClosing) fg_nodes.summaryClosing.textContent = '0';
        fg_showMessage(err.message || 'Unable to load summary.', 'error');
      });
  }

  function fg_loadPeriodReport() {
    var params = { category: fg_state.activeTab, period: fg_state.reportPeriod || 'month' };
    return fg_api('get_period_report', params, 'GET')
      .then(function (res) {
        if (!res || !res.ok) {
          throw new Error((res && res.error) || 'Report load failed.');
        }

        fg_state.report = res.report || { opening_stock: 0, inward_stock: 0, dispatch_qty: 0, closing_stock: 0, months: [] };

        if (fg_nodes.summaryOpening) fg_nodes.summaryOpening.textContent = fg_fmt(fg_state.report.opening_stock || 0);
        if (fg_nodes.summaryInward) fg_nodes.summaryInward.textContent = fg_fmt(fg_state.report.inward_stock || 0);
        if (fg_nodes.summaryDispatch) fg_nodes.summaryDispatch.textContent = fg_fmt(fg_state.report.dispatch_qty || 0);
        if (fg_nodes.summaryClosing) fg_nodes.summaryClosing.textContent = fg_fmt(fg_state.report.closing_stock || 0);

        if (fg_nodes.reportMonths) {
          var months = Array.isArray(fg_state.report.months) ? fg_state.report.months : [];
          if (fg_state.reportPeriod === 'last_3_months' && months.length) {
            var html = '';
            for (var i = 0; i < months.length; i += 1) {
              var m = months[i];
              html += '<div class="fg-report-month-pill">' +
                '<strong>' + fg_escapeHtml(String(m.month || '')) + '</strong>' +
                '<span>Inward: ' + fg_fmt(m.inward || 0) + '</span>' +
                '<span>Dispatch: ' + fg_fmt(m.dispatch || 0) + '</span>' +
                '</div>';
            }
            fg_nodes.reportMonths.innerHTML = html;
            fg_nodes.reportMonths.style.display = '';
          } else {
            fg_nodes.reportMonths.innerHTML = '';
            fg_nodes.reportMonths.style.display = 'none';
          }
        }
      })
      .catch(function (err) {
        if (fg_nodes.summaryOpening) fg_nodes.summaryOpening.textContent = '0';
        if (fg_nodes.summaryInward) fg_nodes.summaryInward.textContent = '0';
        if (fg_nodes.summaryDispatch) fg_nodes.summaryDispatch.textContent = '0';
        if (fg_nodes.summaryClosing) fg_nodes.summaryClosing.textContent = '0';
        if (fg_nodes.reportMonths) {
          fg_nodes.reportMonths.innerHTML = '';
          fg_nodes.reportMonths.style.display = 'none';
        }
        fg_showMessage(err.message || 'Unable to load period report.', 'error');
      });
  }

  function fg_loadTable() {
    fg_nodes.tableBody.innerHTML = '<tr><td class="fg-empty" colspan="99">Loading...</td></tr>';
    var loadTab = fg_state.activeTab;
    return fg_api('get_stock', { category: loadTab }, 'GET')
      .then(function (res) {
        if (!res || !res.ok) {
          throw new Error((res && res.error) || 'Table load failed.');
        }
        fg_state.rows = (res.rows || []).map(function (r) {
          var parsed = fg_parseRemarks(r.remarks);
          r._fgNote = parsed.note;
          r._fgExtra = parsed.extra;
          return r;
        });
        fg_state.tabCounts[loadTab] = fg_state.rows.length;
        fg_applyFilterSort();
      })
      .catch(function (err) {
        fg_nodes.tableBody.innerHTML = '<tr><td class="fg-empty" colspan="99">Unable to load rows.</td></tr>';
        fg_showMessage(err.message || 'Unable to load rows.', 'error');
      });
  }

  function fg_loadTabCounts() {
    fg_api('get_tab_counts', {}, 'GET').then(function (res) {
      if (res && res.ok && res.counts && typeof res.counts === 'object') {
        fg_state.tabCounts = res.counts;
        fg_renderTabs();
      }
    }).catch(function () {});
  }

  function fg_buildItemSummary(rows) {
    var bucket = {};
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i] || {};
      var name = String(row.item_name || '').trim() || 'N/A';
      var key = name.toLowerCase();
      if (!bucket[key]) {
        bucket[key] = { item_name: name, total_qty: 0 };
      }
      // Keep dropdown totals aligned with current net dispatchable stock.
      bucket[key].total_qty += fg_num(fg_value(row, 'available_for_dispatch'));
    }
    var out = Object.keys(bucket).map(function (k) { return bucket[k]; });
    out.sort(function (a, b) { return b.total_qty - a.total_qty; });
    return out;
  }

  function fg_updateItemSummaryTotal() {
    if (!fg_nodes.itemSummarySelect || !fg_nodes.itemSummaryTotal) return;
    var selected = String(fg_nodes.itemSummarySelect.value || '');
    var total = 0;
    for (var i = 0; i < fg_state.itemSummary.length; i += 1) {
      if (fg_state.itemSummary[i].item_name === selected) {
        total = fg_state.itemSummary[i].total_qty;
        break;
      }
    }
    fg_nodes.itemSummaryTotal.textContent = 'Selected Item Total: ' + fg_fmt(total) + ' PCS';
  }

  function fg_renderItemSummary() {
    if (!fg_nodes.itemSummarySelect) return;

    var items = fg_buildItemSummary(fg_state.filteredRows || []);
    fg_state.itemSummary = items;

    if (!items.length) {
      fg_nodes.itemSummarySelect.innerHTML = '<option value="">No item found</option>';
      if (fg_nodes.itemSummaryMeta) fg_nodes.itemSummaryMeta.textContent = '0 item(s)';
      if (fg_nodes.viewItemDetailsBtn) fg_nodes.viewItemDetailsBtn.disabled = true;
      fg_updateItemSummaryTotal();
      return;
    }

    var options = '';
    for (var i = 0; i < items.length; i += 1) {
      options += '<option value="' + fg_escapeHtml(items[i].item_name) + '">' +
        fg_escapeHtml(items[i].item_name) + ' - ' + fg_fmt(items[i].total_qty) + ' PCS</option>';
    }
    fg_nodes.itemSummarySelect.innerHTML = options;
    if (fg_nodes.itemSummaryMeta) fg_nodes.itemSummaryMeta.textContent = items.length + ' item(s)';
    if (fg_nodes.viewItemDetailsBtn) fg_nodes.viewItemDetailsBtn.disabled = false;
    fg_updateItemSummaryTotal();
  }

  function fg_openItemDetailsWindow() {
    if (!fg_nodes.itemSummarySelect) return;
    var selected = String(fg_nodes.itemSummarySelect.value || '').trim();
    if (!selected) {
      fg_showMessage('Please select an item first.', 'warning');
      return;
    }

    var rows = (fg_state.filteredRows || []).filter(function (r) {
      return String(r.item_name || '').trim().toLowerCase() === selected.toLowerCase();
    });
    if (!rows.length) {
      fg_showMessage('No rows found for selected item.', 'warning');
      return;
    }

    var batchAgg = {};
    var gsmSet = {};
    var paperSet = {};
    var totalQty = 0;
    for (var i = 0; i < rows.length; i += 1) {
      var r = rows[i] || {};
      var ex = r._fgExtra || {};
      var qty = fg_num(fg_value(r, 'available_for_dispatch'));
      totalQty += qty;

      var bn = String(r.batch_no || '').trim() || 'N/A';
      batchAgg[bn] = (batchAgg[bn] || 0) + qty;
      gsmSet[String(r.gsm || '').trim() || 'N/A'] = true;
      paperSet[String(ex.material_type || r.sub_type || '').trim() || 'N/A'] = true;
    }

    var batchLines = Object.keys(batchAgg).sort().map(function (bn) {
      return '<span style="display:inline-flex;margin:0 6px 6px 0;padding:4px 10px;border:1px solid #cbd5e1;border-radius:999px;background:#f8fafc;font-size:12px">' +
        fg_escapeHtml(bn) + ': <strong style="margin-left:4px">' + fg_fmt(batchAgg[bn]) + '</strong></span>';
    }).join('');

    var detailRows = '';
    for (var j = 0; j < rows.length; j += 1) {
      var row = rows[j] || {};
      var extra = row._fgExtra || {};
      var paperType = String(extra.material_type || row.sub_type || '').trim() || 'N/A';
      detailRows += '<tr>' +
        '<td>' + (j + 1) + '</td>' +
        '<td>' + fg_escapeHtml(String(row.batch_no || 'N/A')) + '</td>' +
        '<td>' + fg_fmt(fg_value(row, 'available_for_dispatch') || 0) + '</td>' +
        '<td>' + fg_escapeHtml(String(row.gsm || 'N/A')) + '</td>' +
        '<td>' + fg_escapeHtml(paperType) + '</td>' +
        '<td>' + fg_escapeHtml(String(row.size || '')) + '</td>' +
        '<td>' + fg_escapeHtml(String(extra.width || '')) + '</td>' +
        '<td>' + fg_escapeHtml(String(extra.length || '')) + '</td>' +
        '<td>' + fg_escapeHtml(String(row.date || '')) + '</td>' +
      '</tr>';
    }

    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Item Details - ' + fg_escapeHtml(selected) + '</title>' +
      '<style>body{font-family:Segoe UI,Arial,sans-serif;padding:16px;color:#0f172a}h2{margin:0 0 8px} .meta{margin-bottom:10px;color:#334155} .chips{margin-bottom:12px} table{width:100%;border-collapse:collapse;font-size:12px} th,td{border:1px solid #e2e8f0;padding:6px 8px;text-align:left} th{background:#0f172a;color:#fff} tr:nth-child(even){background:#f8fafc}</style>' +
      '</head><body>' +
      '<h2>Item: ' + fg_escapeHtml(selected) + '</h2>' +
      '<div class="meta"><strong>Total PCS:</strong> ' + fg_fmt(totalQty) + ' | <strong>Total Batch:</strong> ' + Object.keys(batchAgg).length + '</div>' +
      '<div class="meta"><strong>GSM:</strong> ' + fg_escapeHtml(Object.keys(gsmSet).sort().join(', ')) + '</div>' +
      '<div class="meta"><strong>Paper Type:</strong> ' + fg_escapeHtml(Object.keys(paperSet).sort().join(', ')) + '</div>' +
      '<div class="chips"><strong>Batch-wise Qty:</strong><div>' + batchLines + '</div></div>' +
      '<table><thead><tr><th>SL</th><th>Batch</th><th>Qty</th><th>GSM</th><th>Paper Type</th><th>Size</th><th>Width</th><th>Length</th><th>Date</th></tr></thead><tbody>' + detailRows + '</tbody></table>' +
      '</body></html>';

    var w = window.open('', '_blank');
    if (!w) {
      fg_showMessage('Popup blocked. Please allow popups.', 'warning');
      return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
  }

  function fg_applyFilterSort() {
    var cols = fg_getColumns(fg_state.activeTab);
    var search = String(fg_state.search || '').toLowerCase().trim();

    fg_state.filteredRows = fg_state.rows.filter(function (row) {
      if (search) {
        var found = false;
        for (var i = 0; i < cols.length; i += 1) {
          var cell = String(fg_value(row, cols[i].key) || '').toLowerCase();
          if (cell.indexOf(search) !== -1) {
            found = true;
            break;
          }
        }
        if (!found) {
          return false;
        }
      }

      for (var key in fg_state.filters) {
        if (!Object.prototype.hasOwnProperty.call(fg_state.filters, key)) {
          continue;
        }
        var selected = fg_state.filters[key];
        if (!Array.isArray(selected) || !selected.length) {
          continue;
        }

        var gotToken = fg_filterToken(fg_value(row, key));
        var pass = false;
        for (var z = 0; z < selected.length; z += 1) {
          if (fg_filterToken(selected[z]) === gotToken) {
            pass = true;
            break;
          }
        }

        if (!pass) {
          return false;
        }
      }

      return true;
    });

    if (fg_state.sortKey) {
      var sortKey = fg_state.sortKey;
      var sortDir = fg_state.sortDir === 'desc' ? -1 : 1;
      fg_state.filteredRows.sort(function (a, b) {
        var av = fg_value(a, sortKey);
        var bv = fg_value(b, sortKey);
        var an = parseFloat(av);
        var bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) {
          if (an < bn) return -1 * sortDir;
          if (an > bn) return 1 * sortDir;
          return 0;
        }
        var as = String(av || '').toLowerCase();
        var bs = String(bv || '').toLowerCase();
        if (as < bs) return -1 * sortDir;
        if (as > bs) return 1 * sortDir;
        return 0;
      });
    }

    fg_renderTable();
    fg_renderPagination();
    fg_renderItemSummary();
    fg_renderTabs();

    // Enable/disable reset-filter button based on active filters
    var rfBtn = fg_root ? fg_root.querySelector('#fgResetFilterBtn') : null;
    if (rfBtn) {
      var hasFilter = false;
      for (var fk in fg_state.filters) {
        if (Object.prototype.hasOwnProperty.call(fg_state.filters, fk) &&
            Array.isArray(fg_state.filters[fk]) && fg_state.filters[fk].length) {
          hasFilter = true;
          break;
        }
      }
      rfBtn.disabled = !hasFilter;
      rfBtn.style.opacity = hasFilter ? '1' : '0.45';
    }
  }

  function fg_renderTable() {
    if (fg_state.activeTab === 'carton') {
      fg_renderCartonMatrixTable();
      return;
    }

    var cols = fg_getColumns(fg_state.activeTab).filter(function (c) {
      return fg_state.visibleColumns[c.key] !== false;
    });

    var headHtml = '<tr>';
    for (var i = 0; i < cols.length; i += 1) {
      var c = cols[i];
      var icon = 'bi bi-arrow-down-up';
      if (fg_state.sortKey === c.key) {
        icon = fg_state.sortDir === 'asc' ? 'bi bi-sort-down' : 'bi bi-sort-up';
      }
      var activeCount = Array.isArray(fg_state.filters[c.key]) ? fg_state.filters[c.key].length : 0;
      headHtml += '<th><div class="fg-th-wrap">' +
        '<span class="fg-th-sort" data-fg-sort="' + fg_escapeHtml(c.key) + '">' + fg_escapeHtml(c.label) + ' <i class="' + icon + '"></i></span>' +
        '<button type="button" class="fg-col-filter-btn ' + (activeCount ? 'active' : '') + '" data-fg-action="open-col-filter" data-col="' + fg_escapeHtml(c.key) + '" title="Filter ' + fg_escapeHtml(c.label) + '">' +
        '<i class="bi bi-funnel-fill"></i><span class="fg-filter-count">' + (activeCount ? activeCount : '') + '</span></button>' +
        '<div class="fg-col-filter-pop" id="fg-col-filter-' + fg_escapeHtml(c.key) + '"></div>' +
      '</div></th>';
    }
    if (fg_isDispatchTab()) {
      headHtml += '<th>Dispatch</th>';
    }
    {
      headHtml += '<th>Action</th>';
    }
    headHtml += '</tr>';

    fg_nodes.tableHead.innerHTML = headHtml;

    var start = (fg_state.page - 1) * fg_state.perPage;
    var end = start + fg_state.perPage;
    var rows = fg_state.filteredRows.slice(start, end);

    if (!rows.length) {
      fg_nodes.tableBody.innerHTML = '<tr><td class="fg-empty" colspan="99">No rows found.</td></tr>';
      return;
    }

    var body = '';
    for (var r = 0; r < rows.length; r += 1) {
      var row = rows[r];
      row._fgSerial = start + r + 1;
      body += '<tr class="fg-row-c' + (r % 8) + '">';
      for (var x = 0; x < cols.length; x += 1) {
        body += '<td>' + fg_renderCell(row, cols[x].key) + '</td>';
      }
      if (fg_isDispatchTab()) {
        var disabled = fg_num(row.quantity) <= 0 ? 'disabled' : '';
        body += '<td><button type="button" class="fg-act-btn blue fg-dispatch-btn" data-fg-action="dispatch-row" data-id="' + row.id + '" ' + disabled + '><i class="bi bi-truck"></i> Dispatch</button></td>';
      }
      {
        body += '<td><div class="fg-row-actions">' +
          '<button type="button" class="btn btn-sm fg-view-btn" data-fg-action="view-row" data-id="' + row.id + '" title="View"><i class="bi bi-eye"></i></button>' +
          (fg_state.canManageRows ?
          '<button type="button" class="btn btn-sm" data-fg-action="edit-row" data-id="' + row.id + '"><i class="bi bi-pencil"></i></button>' +
          '<button type="button" class="btn btn-sm btn-danger" data-fg-action="delete-row" data-id="' + row.id + '"><i class="bi bi-trash"></i></button>' : '') +
          '</div></td>';
      }
      body += '</tr>';
    }

    fg_nodes.tableBody.innerHTML = body;
  }

  function fg_renderCartonMatrixTable() {
    var rows = fg_state.filteredRows.slice();
    var fixedOrder = ['57x15', '57x25', '78x25', '75mm', 'Barcode', 'Medicine'];
    var order = fixedOrder.slice();
    var qtyBySize = {};
    var minBySize = {};
    var stockRowIdBySize = {};
    var cartonItemIdBySize = {};
    var actionIdBySize = {};

    for (var f = 0; f < fixedOrder.length; f += 1) {
      qtyBySize[fixedOrder[f]] = 0;
      minBySize[fixedOrder[f]] = 0;
    }

    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i] || {};
      var sizeText = String(fg_value(row, 'size') || row.size || row.item_name || '').trim();
      if (!sizeText) {
        sizeText = 'Other';
      }
      if (!Object.prototype.hasOwnProperty.call(qtyBySize, sizeText)) {
        qtyBySize[sizeText] = 0;
        minBySize[sizeText] = 0;
        order.push(sizeText);
      }
      var cartonItemId = parseInt(String(row.carton_item_id || '0'), 10) || 0;
      if (!Object.prototype.hasOwnProperty.call(cartonItemIdBySize, sizeText) && cartonItemId > 0) {
        cartonItemIdBySize[sizeText] = String(cartonItemId);
      }

      // Actions (edit/delete) should target real finished_goods_stock rows, not carton_items synthetic rows.
      var isRealStockRow = String(row.created_at || '').trim() !== '';
      if (!Object.prototype.hasOwnProperty.call(stockRowIdBySize, sizeText) && isRealStockRow && fg_num(row.id) > 0) {
        stockRowIdBySize[sizeText] = String(row.id);
        actionIdBySize[sizeText] = String(row.id);
      }
      qtyBySize[sizeText] += fg_num(row.quantity);
      if (fg_num(row.min_qty) > 0) {
        minBySize[sizeText] = Math.max(minBySize[sizeText], fg_num(row.min_qty));
      }
    }

    for (var ck in cartonItemIdBySize) {
      if (!Object.prototype.hasOwnProperty.call(cartonItemIdBySize, ck)) continue;
      if (!Object.prototype.hasOwnProperty.call(actionIdBySize, ck) && cartonItemIdBySize[ck]) {
        actionIdBySize[ck] = cartonItemIdBySize[ck];
      }
    }

    var headHtml = '<tr><th>SIZE</th>';
    var bodyHtml = '<tr><td><strong>QTY</strong></td>';
    var minHtml = '<tr><td><strong>MIN REQ</strong></td>';
    var statusHtml = '<tr><td><strong>STATUS</strong></td>';
    for (var j = 0; j < order.length; j += 1) {
      var sizeKey = order[j];
      headHtml += '<th>' + fg_escapeHtml(sizeKey) + '</th>';
      var qtyNum = Math.floor(fg_num(qtyBySize[sizeKey]));
      var minNum = Math.max(0, Math.floor(fg_num(minBySize[sizeKey])));
      var isLow = minNum > 0 && qtyNum < minNum;
      var qtyCell = fg_escapeHtml(String(qtyNum));
      if (fg_state.canManageRows) {
        var actionHtml = '';
        if (actionIdBySize[sizeKey]) {
          actionHtml = '<div class="fg-row-actions" style="justify-content:center;margin-top:6px">' +
            '<button type="button" class="btn btn-sm" data-fg-action="edit-row" data-id="' + fg_escapeHtml(actionIdBySize[sizeKey]) + '" data-agg-qty="' + qtyNum + '"><i class="bi bi-pencil"></i></button>' +
            '<button type="button" class="btn btn-sm btn-danger" data-fg-action="delete-row" data-id="' + fg_escapeHtml(actionIdBySize[sizeKey]) + '" data-category="carton" data-size="' + fg_escapeHtml(sizeKey) + '"><i class="bi bi-trash"></i></button>' +
          '</div>';
        }
        qtyCell = '<div>' + qtyCell + actionHtml + '</div>';
      }
      var minCell = fg_state.canManageRows
        ? '<input type="number" min="0" step="1" class="fg-carton-min-input" data-size="' + fg_escapeHtml(sizeKey) + '" data-id="' + fg_escapeHtml(String(cartonItemIdBySize[sizeKey] || '')) + '" value="' + fg_escapeHtml(String(minNum)) + '" style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;text-align:center">'
        : '<span>' + fg_escapeHtml(String(minNum)) + '</span>';
      var statusCell = isLow
        ? '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#fee2e2;color:#b91c1c;font-weight:800;font-size:.72rem">Low Quantity</span>'
        : '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:800;font-size:.72rem">In Stock</span>';
      bodyHtml += '<td>' + qtyCell + '</td>';
      minHtml += '<td style="text-align:center">' + minCell + '</td>';
      statusHtml += '<td style="text-align:center">' + statusCell + '</td>';
    }
    headHtml += '</tr>';
    bodyHtml += '</tr>';
    minHtml += '</tr>';
    statusHtml += '</tr>';

    fg_nodes.tableHead.innerHTML = headHtml;
    fg_nodes.tableBody.innerHTML = bodyHtml + minHtml + statusHtml;

    if (fg_state.canManageRows) {
      Array.prototype.slice.call(fg_nodes.tableBody.querySelectorAll('.fg-carton-min-input')).forEach(function(inp) {
        inp.addEventListener('change', function() {
          var idVal = parseInt(String(inp.getAttribute('data-id') || '0'), 10) || 0;
          var sizeVal = String(inp.getAttribute('data-size') || '').trim();
          var minVal = Math.max(0, Math.floor(fg_num(inp.value)));
          inp.value = String(minVal);
          fg_api('set_carton_min_qty', { id: idVal, size: sizeVal, min_qty: minVal }, 'POST')
            .then(function(res) {
              if (!res || !res.ok) {
                throw new Error((res && res.error) || 'Failed to save minimum quantity.');
              }
              fg_loadTable();
            })
            .catch(function(err) {
              fg_showMessage(err.message || 'Failed to save minimum quantity.', 'error');
            });
        });
      });
    }
  }

  function fg_getFilterOptions(colKey) {
    var map = {};
    for (var i = 0; i < fg_state.rows.length; i += 1) {
      var raw = String(fg_value(fg_state.rows[i], colKey) || '').trim();
      var token = fg_filterToken(raw);
      if (!Object.prototype.hasOwnProperty.call(map, token)) {
        map[token] = raw || 'Blanks';
      }
    }

    var items = [];
    for (var key in map) {
      if (Object.prototype.hasOwnProperty.call(map, key)) {
        items.push({ token: key, label: map[key] });
      }
    }

    items.sort(function (a, b) {
      if (a.token === '__blank__') return -1;
      if (b.token === '__blank__') return 1;
      var an = parseFloat(a.label);
      var bn = parseFloat(b.label);
      if (!isNaN(an) && !isNaN(bn)) {
        return an - bn;
      }
      return String(a.label).localeCompare(String(b.label));
    });

    return items;
  }

  function fg_syncFilterSelectAll(colKey) {
    var pop = document.getElementById('fg-col-filter-' + colKey);
    if (!pop) return;
    var all = pop.querySelectorAll('.fg-cfp-list input[type="checkbox"][data-fg-cfp="item"]');
    var checked = pop.querySelectorAll('.fg-cfp-list input[type="checkbox"][data-fg-cfp="item"]:checked');
    var selectAll = pop.querySelector('[data-fg-cfp="select-all"]');
    if (selectAll) {
      selectAll.checked = all.length > 0 && all.length === checked.length;
    }
  }

  function fg_renderFilterPopup(colKey) {
    var pop = document.getElementById('fg-col-filter-' + colKey);
    if (!pop) return;

    var options = fg_getFilterOptions(colKey);
    var active = Array.isArray(fg_state.filters[colKey]) ? fg_state.filters[colKey].map(fg_filterToken) : [];
    var useActive = active.length > 0;

    var html = '<div class="fg-cfp-head">' +
      '<input type="text" class="fg-cfp-search" data-fg-cfp="search" data-col="' + fg_escapeHtml(colKey) + '" placeholder="Search values...">' +
      '<label class="fg-cfp-select-all"><input type="checkbox" data-fg-cfp="select-all" data-col="' + fg_escapeHtml(colKey) + '"><span>Select All</span></label>' +
      '</div>';

    html += '<div class="fg-cfp-list">';
    for (var i = 0; i < options.length; i += 1) {
      var opt = options[i];
      var checked = useActive ? active.indexOf(opt.token) !== -1 : true;
      html += '<label class="fg-cfp-item" data-token="' + fg_escapeHtml(opt.token) + '">' +
        '<input type="checkbox" data-fg-cfp="item" data-col="' + fg_escapeHtml(colKey) + '" value="' + fg_escapeHtml(opt.token) + '" ' + (checked ? 'checked' : '') + '>' +
        '<span>' + fg_escapeHtml(opt.label) + '</span></label>';
    }
    html += '</div>';
    html += '<div class="fg-cfp-foot"><button type="button" class="btn btn-sm" data-fg-cfp="apply" data-col="' + fg_escapeHtml(colKey) + '">Apply</button></div>';

    pop.innerHTML = html;
    fg_syncFilterSelectAll(colKey);
  }

  function fg_commitFilter(colKey) {
    var pop = document.getElementById('fg-col-filter-' + colKey);
    if (!pop) return;

    var optionsCount = pop.querySelectorAll('.fg-cfp-list input[type="checkbox"][data-fg-cfp="item"]').length;
    var checkedEls = pop.querySelectorAll('.fg-cfp-list input[type="checkbox"][data-fg-cfp="item"]:checked');
    var selected = [];
    for (var i = 0; i < checkedEls.length; i += 1) {
      selected.push(fg_filterToken(checkedEls[i].value));
    }

    if (!selected.length || selected.length === optionsCount) {
      delete fg_state.filters[colKey];
    } else {
      fg_state.filters[colKey] = selected;
    }

    fg_state.page = 1;
    fg_closeFilterPopup();
    fg_applyFilterSort();
  }

  function fg_closeFilterPopup() {
    if (!fg_state.activeFilterCol) {
      return;
    }

    var pop = document.getElementById('fg-col-filter-' + fg_state.activeFilterCol);
    if (pop) {
      pop.style.display = 'none';
      if (fg_state.activeFilterParent && pop.parentNode === document.body) {
        fg_state.activeFilterParent.appendChild(pop);
      }
    }

    fg_state.activeFilterCol = '';
    fg_state.activeFilterParent = null;
  }

  function fg_openFilterPopup(colKey, btnEl) {
    if (!colKey || !btnEl) return;

    if (fg_state.activeFilterCol === colKey) {
      fg_closeFilterPopup();
      return;
    }

    fg_closeFilterPopup();
    fg_renderFilterPopup(colKey);

    var pop = document.getElementById('fg-col-filter-' + colKey);
    if (!pop) return;

    fg_state.activeFilterCol = colKey;
    fg_state.activeFilterParent = pop.parentNode;
    document.body.appendChild(pop);

    var rect = btnEl.getBoundingClientRect();
    var popupW = 280;
    var popupH = 320;
    var left = Math.max(8, Math.min(rect.left, window.innerWidth - popupW - 8));
    var top = rect.bottom + 6;
    if (top + popupH > window.innerHeight) {
      top = Math.max(8, rect.top - popupH - 6);
    }

    pop.style.left = left + 'px';
    pop.style.top = top + 'px';
    pop.style.display = 'block';

    var searchEl = pop.querySelector('.fg-cfp-search');
    if (searchEl) {
      searchEl.focus();
    }
  }

  function fg_renderPagination() {
    if (fg_state.activeTab === 'carton') {
      fg_nodes.pagination.innerHTML = '';
      return;
    }

    var total = fg_state.filteredRows.length;
    var pages = Math.max(1, Math.ceil(total / fg_state.perPage));
    if (fg_state.page > pages) {
      fg_state.page = pages;
    }

    var html = '';
    html += '<button class="fg-page-btn" type="button" data-fg-action="go-page" data-page="' + (fg_state.page - 1) + '" ' + (fg_state.page <= 1 ? 'disabled' : '') + '>Prev</button>';
    for (var i = 1; i <= pages; i += 1) {
      var cls = i === fg_state.page ? 'fg-page-btn active' : 'fg-page-btn';
      html += '<button class="' + cls + '" type="button" data-fg-action="go-page" data-page="' + i + '">' + i + '</button>';
    }
    html += '<button class="fg-page-btn" type="button" data-fg-action="go-page" data-page="' + (fg_state.page + 1) + '" ' + (fg_state.page >= pages ? 'disabled' : '') + '>Next</button>';
    fg_nodes.pagination.innerHTML = html;
  }

  function fg_renderColumnMenu() {
    var cols = fg_getColumns(fg_state.activeTab);
    var html = '<div class="fg-column-grid">';
    for (var i = 0; i < cols.length; i += 1) {
      var c = cols[i];
      var checked = fg_state.visibleColumns[c.key] !== false ? 'checked' : '';
      html += '<label class="fg-col-item"><input type="checkbox" data-fg-action="toggle-col" data-col="' + fg_escapeHtml(c.key) + '" ' + checked + '> ' + fg_escapeHtml(c.label) + '</label>';
    }
    html += '</div>';
    fg_nodes.columnMenu.innerHTML = html;
  }

  function fg_openEntryModal(row) {
    fg_state.editId = row && row.id ? parseInt(row.id, 10) : 0;
    var isEdit = fg_state.editId > 0;
    fg_nodes.entryModalTitle.textContent = isEdit ? 'Edit Stock Entry' : 'Add Stock Entry';
    fg_nodes.entrySubmitBtn.textContent = isEdit ? 'Update' : 'Save';

    var schema = fg_getFormSchema(fg_state.activeTab);
    var extra = row ? (row._fgExtra || {}) : {};
    var note = row ? (row._fgNote || '') : '';

    var html = '';
    for (var i = 0; i < schema.length; i += 1) {
      var f = schema[i];
      var val = '';
      if (row) {
        if (f.source === 'direct') {
          val = row[f.name] == null ? '' : row[f.name];
        } else if (f.source === 'extra') {
          val = extra[f.name] == null ? '' : extra[f.name];
        } else if (f.source === 'meta') {
          val = row._fgSerial || '';
        } else {
          val = note;
        }
      } else if (f.source === 'meta') {
        val = 'Auto';
      }

      var cls = f.full ? 'fg-form-field full' : 'fg-form-field';
      if (fg_state.activeTab === 'barcode') {
        cls += ' fg-barcode-field';
        if (f.source === 'meta' || f.readonly) {
          cls += ' fg-barcode-field-readonly';
        } else if (f.name === 'quantity') {
          cls += ' fg-barcode-field-total';
        } else if (f.name === 'pcs_per_roll' || f.name === 'roll_per_cartoon') {
          cls += ' fg-barcode-field-calc';
        } else if (f.name === 'batch_no' || f.name === 'item_name' || f.name === 'size') {
          cls += ' fg-barcode-field-key';
        }
      }
      var readonlyAttr = f.readonly ? ' readonly' : '';
      var disabledAttr = f.readonly ? ' disabled' : '';
      var saveAttr = f.save === false ? ' data-fg-save="0"' : '';
      html += '<div class="' + cls + '">';
      html += '<label>' + fg_escapeHtml(f.label) + (f.required ? ' *' : '') + '</label>';

      if (f.type === 'textarea') {
        html += '<textarea data-fg-field="' + fg_escapeHtml(f.name) + '" data-fg-source="' + fg_escapeHtml(f.source) + '"' + saveAttr + readonlyAttr + '>' + fg_escapeHtml(val) + '</textarea>';
      } else if (f.type === 'select') {
        html += '<select data-fg-field="' + fg_escapeHtml(f.name) + '" data-fg-source="' + fg_escapeHtml(f.source) + '"' + saveAttr + disabledAttr + '>';
        html += '<option value="">Select</option>';
        var options = f.options || [];
        var hasSelectedValue = false;
        for (var oi = 0; oi < options.length; oi += 1) {
          if (String(options[oi]) === String(val)) {
            hasSelectedValue = true;
            break;
          }
        }
        if (val !== '' && !hasSelectedValue) {
          html += '<option value="' + fg_escapeHtml(String(val)) + '" selected>' + fg_escapeHtml(String(val)) + '</option>';
        }
        for (var o = 0; o < options.length; o += 1) {
          var opt = String(options[o]);
          var selected = String(val) === opt ? 'selected' : '';
          html += '<option value="' + fg_escapeHtml(opt) + '" ' + selected + '>' + fg_escapeHtml(opt) + '</option>';
        }
        html += '</select>';
      } else {
        var type = f.type || 'text';
        var placeholderAttr = f.placeholder ? ' placeholder="' + fg_escapeHtml(String(f.placeholder)) + '"' : '';
        html += '<input type="' + type + '" data-fg-field="' + fg_escapeHtml(f.name) + '" data-fg-source="' + fg_escapeHtml(f.source) + '"' + saveAttr + ' value="' + fg_escapeHtml(val) + '"' + placeholderAttr + readonlyAttr + '>';
      }

      html += '</div>';
    }

    fg_nodes.entryFormGrid.innerHTML = html;

    if (fg_state.activeTab === 'carton') {
      var cartonSizeEl = fg_nodes.entryForm.querySelector('[data-fg-field="size"]');
      var cartonCustomSizeWrap = fg_nodes.entryForm.querySelector('[data-fg-field="custom_size"]');
      var cartonCustomSizeInput = cartonCustomSizeWrap;
      if (cartonCustomSizeWrap) {
        cartonCustomSizeWrap = cartonCustomSizeWrap.closest('.fg-form-field');
      }

      var syncCartonCustomSize = function () {
        if (!cartonSizeEl || !cartonCustomSizeWrap) {
          return;
        }
        var selected = String(cartonSizeEl.value || '').trim();
        var showCustom = selected === 'Custom';
        cartonCustomSizeWrap.style.display = showCustom ? '' : 'none';
        if (!showCustom && cartonCustomSizeInput) {
          cartonCustomSizeInput.value = '';
        }
      };

      if (cartonSizeEl) {
        cartonSizeEl.addEventListener('change', syncCartonCustomSize);
      }
      syncCartonCustomSize();
    }

    fg_attachPosAutoCalc();
    fg_attachBarcodeAutoCalc();

    // Attach PRC suggestions for pos_paper_roll, one_ply and two_ply tabs
    if (fg_state.activeTab === 'pos_paper_roll' || fg_state.activeTab === 'one_ply' || fg_state.activeTab === 'two_ply') {
      fg_loadPrcData(function (prcRows) {
        fg_attachPrcSuggestions(prcRows);
      });
    }

    fg_nodes.entryModal.style.display = 'flex';
    fg_nodes.entryModal.setAttribute('aria-hidden', 'false');
  }

  function fg_attachPosAutoCalc() {
    if ((fg_state.activeTab !== 'pos_paper_roll' && fg_state.activeTab !== 'one_ply' && fg_state.activeTab !== 'two_ply') || !fg_nodes.entryForm) {
      return;
    }

    var pcsEl = fg_nodes.entryForm.querySelector('[data-fg-field="quantity"]');
    var perCartonEl = fg_nodes.entryForm.querySelector('[data-fg-field="per_carton"]');
    var cartonEl = fg_nodes.entryForm.querySelector('[data-fg-field="carton"]');
    var totalEl = fg_nodes.entryForm.querySelector('[data-fg-field="total"]');
    var availableEl = fg_nodes.entryForm.querySelector('[data-fg-field="available_for_dispatch"]');
    var statusEl = fg_nodes.entryForm.querySelector('[data-fg-field="stock_status"]');

    if (!pcsEl || !totalEl) {
      return;
    }

    function fmt(n) {
      if (!isFinite(n)) {
        return '';
      }
      return String(Math.round(n * 1000) / 1000).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
    }

    function calc() {
      var pcs = fg_num(pcsEl.value);
      var perCarton = perCartonEl ? fg_num(perCartonEl.value) : 0;
      var carton = cartonEl ? fg_num(cartonEl.value) : 0;

      var total = 0;
      if (perCarton > 0 && carton > 0) {
        total = perCarton * carton;
      } else if (pcs > 0) {
        total = pcs;
      }

      totalEl.value = total > 0 ? fmt(total) : '';

      if (cartonEl && perCartonEl && carton > 0 && pcs > 0 && perCarton <= 0) {
        perCartonEl.value = fmt(pcs / carton);
      }

      if (availableEl) {
        availableEl.value = pcs > 0 ? fmt(pcs) : '';
      }
      if (statusEl) {
        if (pcs <= 0) {
          statusEl.value = 'Dispatched';
        } else if (pcs <= 10) {
          statusEl.value = 'Low';
        } else {
          statusEl.value = 'In Stock';
        }
      }
    }

    pcsEl.addEventListener('input', calc);
    if (perCartonEl) {
      perCartonEl.addEventListener('input', calc);
    }
    if (cartonEl) {
      cartonEl.addEventListener('input', calc);
    }

    calc();
  }

  function fg_attachBarcodeAutoCalc() {
    if (fg_state.activeTab !== 'barcode' || !fg_nodes.entryForm) {
      return;
    }

    var qtyEl = fg_nodes.entryForm.querySelector('[data-fg-field="quantity"]');
    var pcsPerRollEl = fg_nodes.entryForm.querySelector('[data-fg-field="pcs_per_roll"]');
    var totalRollEl = fg_nodes.entryForm.querySelector('[data-fg-field="total_roll"]');
    var rollsPerCartonEl = fg_nodes.entryForm.querySelector('[data-fg-field="roll_per_cartoon"]');
    var cartonEl = fg_nodes.entryForm.querySelector('[data-fg-field="carton"]');
    var totalQtyEl = fg_nodes.entryForm.querySelector('[data-fg-field="total_quantity"]');
    var availableEl = fg_nodes.entryForm.querySelector('[data-fg-field="available_for_dispatch"]');
    var statusEl = fg_nodes.entryForm.querySelector('[data-fg-field="status"]');

    if (!qtyEl) {
      return;
    }

    function toInt(v) {
      var n = Number(String(v == null ? '' : v).replace(/,/g, '').trim());
      if (!isFinite(n) || isNaN(n)) {
        return 0;
      }
      return Math.max(0, Math.floor(n));
    }

    function setVal(el, value) {
      if (!el) return;
      el.value = String(value);
    }

    function calcBarcode() {
      var qty = toInt(qtyEl.value);
      var pcsPerRoll = Math.max(1, toInt(pcsPerRollEl ? pcsPerRollEl.value : 0));
      var totalRoll = qty > 0 ? Math.ceil(qty / pcsPerRoll) : 0;
      var rollsPerCarton = toInt(rollsPerCartonEl ? rollsPerCartonEl.value : 0);
      var cartons = rollsPerCarton > 0 ? Math.floor(totalRoll / rollsPerCarton) : 0;

      setVal(totalRollEl, totalRoll > 0 ? totalRoll : '');
      setVal(cartonEl, cartons > 0 ? cartons : '');
      setVal(totalQtyEl, qty > 0 ? qty : '');
      setVal(availableEl, qty > 0 ? qty : '');
      if (statusEl) {
        statusEl.value = qty > 0 ? 'Ready to Dispatch' : 'Dispatched';
      }
    }

    qtyEl.addEventListener('input', calcBarcode);
    if (pcsPerRollEl) pcsPerRollEl.addEventListener('input', calcBarcode);
    if (rollsPerCartonEl) rollsPerCartonEl.addEventListener('input', calcBarcode);

    calcBarcode();
  }

  function fg_closeEntryModal() {
    fg_nodes.entryModal.style.display = 'none';
    fg_nodes.entryModal.setAttribute('aria-hidden', 'true');
    fg_state.editId = 0;
  }

  function fg_openViewModal(row) {
    if (!row || !fg_nodes.viewModal || !fg_nodes.viewModalBody) {
      return;
    }

    var cols = fg_getColumns(fg_state.activeTab);
    var tableDetails = '';
    for (var i = 0; i < cols.length; i += 1) {
      var c = cols[i];
      tableDetails += '<div class="fg-view-item">' +
        '<b>' + fg_escapeHtml(c.label) + '</b>' +
        '<span>' + fg_renderCell(row, c.key) + '</span>' +
      '</div>';
    }

    var extra = row._fgExtra || {};
    var sysDetails = '';
    var sysPairs = [
      ['ID', row.id],
      ['Category', row.category],
      ['Sub Type', row.sub_type],
      ['Item Code', row.item_code],
      ['Unit', row.unit],
      ['Location', row.location],
      ['Created At', row.created_at],
      ['Remarks', row._fgNote || '-']
    ];
    for (var j = 0; j < sysPairs.length; j += 1) {
      sysDetails += '<div class="fg-view-item">' +
        '<b>' + fg_escapeHtml(sysPairs[j][0]) + '</b>' +
        '<span>' + fg_escapeHtml(String(sysPairs[j][1] == null || sysPairs[j][1] === '' ? '-' : sysPairs[j][1])) + '</span>' +
      '</div>';
    }

    var extraKeys = Object.keys(extra);
    if (extraKeys.length) {
      for (var k = 0; k < extraKeys.length; k += 1) {
        var ek = extraKeys[k];
        var eLabel = String(ek || '').replace(/[_-]+/g, ' ').replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
        sysDetails += '<div class="fg-view-item">' +
          '<b>' + fg_escapeHtml(eLabel) + '</b>' +
          '<span>' + fg_escapeHtml(String(extra[ek] == null || extra[ek] === '' ? '-' : extra[ek])) + '</span>' +
        '</div>';
      }
    }

    if (fg_nodes.viewModalTitle) {
      fg_nodes.viewModalTitle.textContent = 'View Details - ' + String(row.item_name || 'Stock Row');
    }
    fg_nodes.viewModalBody.innerHTML =
      '<div class="fg-view-sec">' +
      '  <h4>Table Details</h4>' +
      '  <div class="fg-view-grid">' + tableDetails + '</div>' +
      '</div>' +
      '<div class="fg-view-sec">' +
      '  <h4>System / Extra Details</h4>' +
      '  <div class="fg-view-grid">' + sysDetails + '</div>' +
      '</div>';

    fg_nodes.viewModal.style.display = 'flex';
    fg_nodes.viewModal.setAttribute('aria-hidden', 'false');
  }

  function fg_closeViewModal() {
    if (!fg_nodes.viewModal) {
      return;
    }
    fg_nodes.viewModal.style.display = 'none';
    fg_nodes.viewModal.setAttribute('aria-hidden', 'true');
    if (fg_nodes.viewModalBody) {
      fg_nodes.viewModalBody.innerHTML = '';
    }
  }

  function fg_readFormPayload() {
    var schema = fg_getFormSchema(fg_state.activeTab);
    var fields = fg_nodes.entryForm.querySelectorAll('[data-fg-field]');
    var direct = {
      category: fg_state.activeTab,
      sub_type: '',
      item_name: '',
      item_code: '',
      size: '',
      gsm: '',
      quantity: 0,
      unit: '',
      location: '',
      batch_no: '',
      date: '',
      remarks: ''
    };
    var extra = {};
    var note = '';

    for (var i = 0; i < fields.length; i += 1) {
      var el = fields[i];
      if (String(el.getAttribute('data-fg-save') || '1') === '0') {
        continue;
      }
      var name = String(el.getAttribute('data-fg-field') || '');
      var source = String(el.getAttribute('data-fg-source') || 'direct');
      var value = String(el.value || '').trim();

      if (source === 'direct') {
        direct[name] = value;
      } else if (source === 'extra') {
        if (value !== '') {
          extra[name] = value;
        }
      } else {
        note = value;
      }
    }

    direct.quantity = fg_num(direct.quantity);

    if (fg_state.activeTab === 'carton') {
      var selectedSize = String(direct.size || '').trim();
      var customSize = String(extra.custom_size || '').trim();
      if (selectedSize === 'Custom') {
        if (!customSize) {
          throw new Error('Please enter Custom Size.');
        }
        direct.size = customSize;
      }
      delete extra.custom_size;
    }

    direct.remarks = fg_packRemarks(note, extra);

    for (var s = 0; s < schema.length; s += 1) {
      var f = schema[s];
      if (f.required) {
        var check = '';
        if (f.source === 'direct') check = String(direct[f.name] || '');
        if (f.source === 'extra') check = String(extra[f.name] || '');
        if (f.source === 'note') check = String(note || '');
        if (!check.trim()) {
          throw new Error('Please fill required field: ' + f.label);
        }
      }
    }

    return direct;
  }

  function fg_submitEntry(ev) {
    ev.preventDefault();

    var payload;
    try {
      payload = fg_readFormPayload();
    } catch (err) {
      fg_showMessage(err.message || 'Validation failed.', 'warning');
      return;
    }

    var action = fg_state.editId > 0 ? 'update_stock' : 'add_stock';
    if (fg_state.editId > 0) {
      payload.id = fg_state.editId;
    }

    fg_api(action, payload, 'POST').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Save failed.');
      }
      fg_closeEntryModal();
      fg_showMessage(res.message || 'Saved successfully.', 'success');
      fg_loadSummary();
      fg_loadTable();
    }).catch(function (err) {
      fg_showMessage(err.message || 'Unable to save entry.', 'error');
    });
  }

  function fg_deleteRow(id, meta) {
    fg_confirm('Delete this stock row?', function () {
      var payload = { id: id };
      if (meta && typeof meta === 'object') {
        if (meta.category) payload.category = String(meta.category);
        if (meta.size) payload.size = String(meta.size);
      }

      fg_api('delete_stock', payload, 'POST').then(function (res) {
        if (!res || !res.ok) {
          throw new Error((res && res.error) || 'Delete failed.');
        }
        fg_showMessage(res.message || 'Deleted successfully.', 'success');
        fg_loadSummary();
        fg_loadTable();
      }).catch(function (err) {
        fg_showMessage(err.message || 'Unable to delete row.', 'error');
      });
    });
  }

  function fg_toggleImportModal(show) {
    fg_nodes.importModal.style.display = show ? 'flex' : 'none';
    fg_nodes.importModal.setAttribute('aria-hidden', show ? 'false' : 'true');
    if (!show) {
      fg_state.importData = null;
      fg_state.importMapping = {};
      fg_nodes.importMapping.innerHTML = '';
      fg_nodes.importPreview.innerHTML = '';
      if (fg_nodes.importFile) fg_nodes.importFile.value = '';
    }
  }

  function fg_normKey(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  function fg_importTargets() {
    var schema = fg_getFormSchema(fg_state.activeTab);
    var targets = [];
    for (var i = 0; i < schema.length; i += 1) {
      var f = schema[i];
      if (f.importable === false || f.save === false) {
        continue;
      }
      if (f.name === 'date' || f.name === 'quantity' || f.name === 'item_name' || f.name === 'item_code' || f.name === 'size' || f.name === 'gsm' || f.name === 'unit' || f.name === 'location' || f.name === 'batch_no' || f.name === 'sub_type' || f.source === 'extra' || f.source === 'note') {
        var key = f.source + ':' + f.name;
        targets.push({ key: key, label: f.label, field: f });
      }
    }
    return targets;
  }

  function fg_csvParseLine(line) {
    var out = [];
    var cur = '';
    var inQuote = false;
    for (var i = 0; i < line.length; i += 1) {
      var ch = line.charAt(i);
      if (ch === '"') {
        if (inQuote && line.charAt(i + 1) === '"') {
          cur += '"';
          i += 1;
        } else {
          inQuote = !inQuote;
        }
      } else if (ch === ',' && !inQuote) {
        out.push(cur);
        cur = '';
      } else {
        cur += ch;
      }
    }
    out.push(cur);
    return out;
  }

  function fg_parseImportFile() {
    var file = fg_nodes.importFile && fg_nodes.importFile.files && fg_nodes.importFile.files[0] ? fg_nodes.importFile.files[0] : null;
    if (!file) {
      fg_showMessage('Please choose a file first.', 'warning');
      return;
    }

    var name = String(file.name || '').toLowerCase();
    var reader = new FileReader();

    reader.onerror = function () {
      fg_showMessage('Unable to read file.', 'error');
    };

    if ((name.endsWith('.xlsx') || name.endsWith('.xls')) && window.XLSX) {
      reader.onload = function (e) {
        try {
          var data = new Uint8Array(e.target.result);
          var wb = window.XLSX.read(data, { type: 'array' });
          var sheet = wb.Sheets[wb.SheetNames[0]];
          var rows = window.XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false });
          fg_prepareImport(rows);
        } catch (err) {
          fg_showMessage('Invalid Excel file.', 'error');
        }
      };
      reader.readAsArrayBuffer(file);
      return;
    }

    reader.onload = function (e) {
      var text = String(e.target.result || '');
      var lines = text.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
      var rows = lines.map(fg_csvParseLine);
      fg_prepareImport(rows);
    };
    reader.readAsText(file);
  }

  function fg_prepareImport(rows2d) {
    if (!Array.isArray(rows2d) || rows2d.length < 2) {
      fg_showMessage('File has no data rows.', 'warning');
      return;
    }

    var headers = rows2d[0].map(function (h) { return String(h || '').trim(); });
    var body = [];
    for (var i = 1; i < rows2d.length; i += 1) {
      var row = rows2d[i];
      if (!Array.isArray(row)) continue;
      var obj = {};
      var allBlank = true;
      for (var c = 0; c < headers.length; c += 1) {
        var hv = headers[c] || ('Column ' + (c + 1));
        var val = row[c] == null ? '' : String(row[c]).trim();
        obj[hv] = val;
        if (val !== '') allBlank = false;
      }
      if (!allBlank) body.push(obj);
    }

    fg_state.importData = { headers: headers, rows: body };

    var targets = fg_importTargets();
    fg_state.importMapping = {};
    for (var t = 0; t < targets.length; t += 1) {
      var trg = targets[t];
      var def = '';
      var trgNorm = fg_normKey(trg.label + ' ' + trg.field.name);
      for (var h = 0; h < headers.length; h += 1) {
        var hn = fg_normKey(headers[h]);
        if (hn && (trgNorm.indexOf(hn) >= 0 || hn.indexOf(fg_normKey(trg.field.name)) >= 0)) {
          def = headers[h];
          break;
        }
      }
      fg_state.importMapping[trg.key] = def;
    }

    fg_renderImportMapping();
    fg_renderImportPreview();
    fg_showMessage('File parsed successfully. Map columns and import.', 'success');
  }

  function fg_renderImportMapping() {
    if (!fg_state.importData) {
      fg_nodes.importMapping.innerHTML = '';
      return;
    }

    var targets = fg_importTargets();
    var headers = fg_state.importData.headers;
    var html = '<div class="fg-map-grid">';
    for (var i = 0; i < targets.length; i += 1) {
      var t = targets[i];
      var mapped = String(fg_state.importMapping[t.key] || '');
      html += '<div class="fg-map-item">';
      html += '<strong>' + fg_escapeHtml(t.label) + '</strong>';
      html += '<select data-fg-action="change-map" data-map-key="' + fg_escapeHtml(t.key) + '">';
      html += '<option value="">-- Ignore --</option>';
      for (var h = 0; h < headers.length; h += 1) {
        var hd = String(headers[h] || '');
        var sel = hd === mapped ? 'selected' : '';
        html += '<option value="' + fg_escapeHtml(hd) + '" ' + sel + '>' + fg_escapeHtml(hd) + '</option>';
      }
      html += '</select>';
      html += '</div>';
    }
    html += '</div>';

    fg_nodes.importMapping.innerHTML = html;
  }

  function fg_renderImportPreview() {
    if (!fg_state.importData) {
      fg_nodes.importPreview.innerHTML = '';
      return;
    }

    var targets = fg_importTargets();
    var rows = fg_state.importData.rows.slice(0, 8);
    var html = '<table><thead><tr>';
    for (var i = 0; i < targets.length; i += 1) {
      html += '<th>' + fg_escapeHtml(targets[i].label) + '</th>';
    }
    html += '</tr></thead><tbody>';

    for (var r = 0; r < rows.length; r += 1) {
      var src = rows[r];
      html += '<tr>';
      for (var t = 0; t < targets.length; t += 1) {
        var mk = targets[t].key;
        var sourceHeader = fg_state.importMapping[mk] || '';
        var val = sourceHeader ? src[sourceHeader] : '';
        html += '<td>' + fg_escapeHtml(val) + '</td>';
      }
      html += '</tr>';
    }

    html += '</tbody></table>';
    html += '<div class="fg-import-note" style="margin-top:8px">Preview shows first 8 rows only.</div>';
    fg_nodes.importPreview.innerHTML = html;
  }

  function fg_submitImport() {
    if (!fg_state.isAdmin) {
      fg_showMessage('Only admin can import.', 'warning');
      return;
    }
    if (!fg_state.importData || !fg_state.importData.rows.length) {
      fg_showMessage('Please parse file first.', 'warning');
      return;
    }

    var targets = fg_importTargets();

    function fg_importValueOrNA(field, srcVal) {
      var v = srcVal == null ? '' : String(srcVal).trim();
      if (v !== '') return v;
      if (field && field.type === 'number') return '0';
      if (field && field.name === 'date') return '';
      return 'N/A';
    }

    var payloadRows = [];
    for (var i = 0; i < fg_state.importData.rows.length; i += 1) {
      var src = fg_state.importData.rows[i];
      var row = {
        category: fg_state.activeTab,
        sub_type: '',
        item_name: '',
        item_code: '',
        size: '',
        gsm: '',
        quantity: 0,
        unit: '',
        location: '',
        batch_no: '',
        date: '',
        remarks: ''
      };
      var extra = {};
      var note = '';

      for (var t = 0; t < targets.length; t += 1) {
        var trg = targets[t];
        var head = fg_state.importMapping[trg.key] || '';
        if (!head) continue;
        var val = fg_importValueOrNA(trg.field, src[head]);

        if (trg.field.source === 'direct') {
          row[trg.field.name] = val;
        } else if (trg.field.source === 'extra') {
          extra[trg.field.name] = val || 'N/A';
        } else {
          note = val || 'N/A';
        }
      }

      row.quantity = fg_num(row.quantity);
      row.remarks = fg_packRemarks(note, extra);

      if (row.item_name || row.sub_type || row.item_code || row.size || row.quantity > 0) {
        payloadRows.push(row);
      }
    }

    if (!payloadRows.length) {
      fg_showMessage('No valid rows found after mapping.', 'warning');
      return;
    }

    fg_api('import_excel', { rows: payloadRows }, 'POST').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Import failed.');
      }
      var msg = 'Import completed. Inserted: ' + String(res.inserted || 0) + ', Failed: ' + String(res.failed || 0);
      fg_toggleImportModal(false);
      fg_showMessage(msg, 'success');
      fg_loadSummary();
      fg_loadTable();
    }).catch(function (err) {
      fg_showMessage(err.message || 'Unable to import rows.', 'error');
    });
  }

  function fg_exportCsv() {
    var tab  = fg_currentTab();
    var cols = fg_getColumns(fg_state.activeTab).filter(function (c) { return fg_state.visibleColumns[c.key] !== false; });
    var rows = fg_state.filteredRows;
    var now  = new Date();

    // ── Build Excel XML (same as Paper Stock XLS export) ──────────────────
    function xmlEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var colCount  = cols.length + 1; // +1 for SL
    var mergeCount = colCount - 1;
    var numericKeys = {};
    for (var ci = 0; ci < cols.length; ci += 1) { if (cols[ci].numeric) numericKeys[cols[ci].key] = true; }

    // Style IDs matching Paper Stock palette
    var styles = [
      '<Style ss:ID="s_company"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="14" ss:Color="#0F172A"/></Style>',
      '<Style ss:ID="s_tagline"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:Size="10" ss:Color="#64748B"/></Style>',
      '<Style ss:ID="s_meta"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:Size="9" ss:Color="#64748B"/></Style>',
      '<Style ss:ID="s_title"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="12" ss:Color="#FFFFFF"/><Interior ss:Color="#0F172A" ss:Pattern="Solid"/></Style>',
      '<Style ss:ID="s_thead"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="9"/><Interior ss:Color="#0F172A" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1E293B"/></Borders></Style>',
      '<Style ss:ID="s_body"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Font ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>',
      '<Style ss:ID="s_body_alt"><Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1"/><Font ss:Size="9"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>',
      '<Style ss:ID="s_num"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:Size="9"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>',
      '<Style ss:ID="s_num_alt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Font ss:Size="9"/><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>',
      '<Style ss:ID="s_sl"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Size="9" ss:Color="#94A3B8"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/></Borders></Style>'
    ];

    // ColWidths
    var colWidths = ['<Column ss:Width="38"/>'];
    for (var ci2 = 0; ci2 < cols.length; ci2 += 1) {
      var w = numericKeys[cols[ci2].key] ? 80 : 120;
      colWidths.push('<Column ss:Width="' + w + '"/>');
    }

    // Company header rows
    var dateStr = now.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    var timeStr = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });

    var contactParts = [];
    if (fg_co.phone) contactParts.push('Ph: ' + fg_co.phone);
    if (fg_co.email) contactParts.push(fg_co.email);
    if (fg_co.gst)   contactParts.push('GST: ' + fg_co.gst);

    var headerRows = [
      '<Row ss:Height="28"><Cell ss:StyleID="s_company" ss:MergeAcross="' + (mergeCount - 1) + '"><Data ss:Type="String">' + xmlEsc(fg_co.name) + '</Data></Cell><Cell ss:StyleID="s_meta"><Data ss:Type="String">Generated: ' + xmlEsc(dateStr + ' ' + timeStr) + '</Data></Cell></Row>',
      fg_co.tagline ? '<Row ss:Height="16"><Cell ss:StyleID="s_tagline" ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String">' + xmlEsc(fg_co.tagline) + '</Data></Cell></Row>' : '',
      fg_co.address ? '<Row ss:Height="14"><Cell ss:StyleID="s_tagline" ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String">' + xmlEsc(fg_co.address) + '</Data></Cell></Row>' : '',
      contactParts.length ? '<Row ss:Height="14"><Cell ss:StyleID="s_tagline" ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String">' + xmlEsc(contactParts.join('  |  ')) + '</Data></Cell></Row>' : '',
      '<Row ss:Height="6"><Cell ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String"></Data></Cell></Row>',
      '<Row ss:Height="26"><Cell ss:StyleID="s_title" ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String">FINISHED GOODS STOCK — ' + xmlEsc(tab.label.toUpperCase()) + ' | ' + rows.length + ' Records</Data></Cell></Row>',
      '<Row ss:Height="6"><Cell ss:MergeAcross="' + mergeCount + '"><Data ss:Type="String"></Data></Cell></Row>'
    ].filter(Boolean).join('\n');

    // Header row
    var theadRow = '<Row ss:Height="30"><Cell ss:StyleID="s_thead"><Data ss:Type="String">SL</Data></Cell>';
    for (var ci3 = 0; ci3 < cols.length; ci3 += 1) {
      theadRow += '<Cell ss:StyleID="s_thead"><Data ss:Type="String">' + xmlEsc(cols[ci3].label) + '</Data></Cell>';
    }
    theadRow += '</Row>';

    // Data rows
    var dataRows = '';
    for (var ri = 0; ri < rows.length; ri += 1) {
      var row = rows[ri];
      row._fgSerial = ri + 1;
      var alt = ri % 2 === 1;
      dataRows += '<Row ss:Height="18"><Cell ss:StyleID="s_sl"><Data ss:Type="Number">' + (ri + 1) + '</Data></Cell>';
      for (var ci4 = 0; ci4 < cols.length; ci4 += 1) {
        var cv = fg_value(row, cols[ci4].key);
        if (numericKeys[cols[ci4].key]) {
          var n = parseFloat(cv);
          var styleN = alt ? 's_num_alt' : 's_num';
          dataRows += '<Cell ss:StyleID="' + styleN + '"><Data ss:Type="Number">' + (isNaN(n) ? 0 : n) + '</Data></Cell>';
        } else {
          var styleB = alt ? 's_body_alt' : 's_body';
          dataRows += '<Cell ss:StyleID="' + styleB + '"><Data ss:Type="String">' + xmlEsc(cv) + '</Data></Cell>';
        }
      }
      dataRows += '</Row>';
    }

    var xml = '<?xml version="1.0" encoding="UTF-8"?>\n' +
      '<?mso-application progid="Excel.Sheet"?>\n' +
      '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' +
      '<Styles>' + styles.join('') + '</Styles>' +
      '<Worksheet ss:Name="' + xmlEsc(tab.label) + '">' +
      '<Table>' + colWidths.join('') + headerRows + theadRow + dataRows + '</Table>' +
      '</Worksheet></Workbook>';

    var blob = new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href      = url;
    a.download  = 'Finished_Stock_' + tab.label.replace(/\s+/g, '_') + '_' + now.toISOString().slice(0, 10) + '.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function fg_openPrintWindow(modeLabel) {
    var tab  = fg_currentTab();
    var cols = fg_getColumns(fg_state.activeTab).filter(function (c) { return fg_state.visibleColumns[c.key] !== false; });
    var rows = fg_state.filteredRows.slice(0, 600);
    var now  = new Date();

    // Company header details
    var logoHtml = fg_co.logo ? '<img src="' + fg_escapeHtml(fg_co.logo) + '" style="width:56px;height:56px;border-radius:10px;object-fit:contain;border:1px solid #e2e8f0">' : '';
    var contactParts = [];
    if (fg_co.phone) contactParts.push('Ph: ' + fg_co.phone);
    if (fg_co.email) contactParts.push(fg_co.email);
    if (fg_co.gst)   contactParts.push('GST: ' + fg_co.gst);
    var addressHtml = fg_co.address ? '<div style="font-size:10px;color:#475569;margin-top:3px">' + fg_escapeHtml(fg_co.address) + '</div>' : '';
    var contactHtml = contactParts.length ? '<div style="font-size:10px;color:#64748b;margin-top:3px">' + contactParts.map(fg_escapeHtml).join('  |  ') + '</div>' : '';

    // Summary totals (numeric cols)
    var totalQty = 0;
    var totalCarton = 0;
    for (var ri = 0; ri < rows.length; ri += 1) {
      totalQty    += fg_num(fg_value(rows[ri], 'quantity') || fg_value(rows[ri], 'pcs'));
      totalCarton += fg_num(fg_value(rows[ri], 'carton') || fg_value(rows[ri], 'total'));
    }

    // Table HTML
    var tableHtml = '<table><thead><tr><th class="sl-col">SL</th>';
    for (var ci = 0; ci < cols.length; ci += 1) {
      tableHtml += '<th>' + fg_escapeHtml(cols[ci].label) + '</th>';
    }
    tableHtml += '</tr></thead><tbody>';
    for (var r = 0; r < rows.length; r += 1) {
      rows[r]._fgSerial = r + 1;
      tableHtml += '<tr>';
      tableHtml += '<td class="sl-col">' + (r + 1) + '</td>';
      for (var x = 0; x < cols.length; x += 1) {
        tableHtml += '<td>' + fg_escapeHtml(fg_value(rows[r], cols[x].key)) + '</td>';
      }
      tableHtml += '</tr>';
    }
    tableHtml += '</tbody></table>';

    var colCount = cols.length + 1;
    var dateStr  = now.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    var timeStr  = now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });
    var useLandscape = colCount > 8;
    var fontSize = colCount > 10 ? '9px' : '10px';

    var html = '<!doctype html><html><head><meta charset="utf-8">' +
      '<title>Finished Goods — ' + fg_escapeHtml(tab.label) + '</title>' +
      '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">' +
      '<style>' +
      '@page{size:' + (useLandscape ? 'A4 landscape' : 'A4') + ';margin:10mm 8mm 14mm 8mm}' +
      '*{box-sizing:border-box;margin:0;padding:0}' +
      'body{font-family:"Segoe UI",Arial,sans-serif;font-size:' + fontSize + ';color:#1e293b}' +
      '.company-header{display:flex;align-items:center;gap:14px;border-bottom:3px solid #0f172a;padding-bottom:10px;margin-bottom:10px}' +
      '.company-name{font-size:22px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:.03em}' +
      '.company-sub{font-size:11px;color:#64748b;margin-top:2px}' +
      '.report-meta{text-align:right;min-width:160px;font-size:10px;color:#64748b;line-height:1.7}' +
      '.report-meta strong{color:#0f172a}' +
      '.title-bar{display:flex;justify-content:space-between;align-items:center;background:#0f172a;color:#fff;border-radius:8px;padding:10px 18px;margin-bottom:10px;font-size:13px;font-weight:700}' +
      '.summary-cards{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap}' +
      '.sc{flex:1;min-width:120px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 14px;text-align:center}' +
      '.sc .label{font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.08em}' +
      '.sc .val{font-size:18px;font-weight:900;color:#0f172a;margin-top:2px}' +
      '.sc .unit{font-size:10px;color:#64748b;font-weight:600}' +
      '.sc.accent{border-color:#bbf7d0;background:#f0fdf4}.sc.accent .val{color:#16a34a}' +
      'table{width:100%;border-collapse:collapse;font-size:' + fontSize + ';margin-bottom:8px}' +
      'thead{display:table-header-group}' +
      'th{background:#0f172a;color:#fff;padding:6px 8px;text-align:left;font-size:' + fontSize + ';text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;border:1px solid #1e293b}' +
      'td{padding:5px 8px;border:1px solid #e2e8f0}tr:nth-child(even){background:#f8fafc}' +
      '.sl-col{text-align:center;width:34px;color:#94a3b8;font-size:9px}' +
      '.report-footer{display:flex;justify-content:space-between;align-items:center;border-top:2px solid #0f172a;padding-top:8px;margin-top:10px;font-size:10px;color:#94a3b8}' +
      '.report-footer strong{color:#0f172a}' +
      '.print-toolbar{padding:14px 20px;background:linear-gradient(135deg,#fefce8,#fff7ed);border-bottom:2px solid #fde68a;text-align:center;font-size:13px;font-weight:700;color:#92400e;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}' +
      '.print-toolbar button{padding:8px 20px;border-radius:10px;font-weight:700;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:6px}' +
      '.btn-p{border:none;background:#0f172a;color:#fff}.btn-c{border:1px solid #cbd5e1;background:#fff;color:#64748b}' +
      '@media print{body{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.no-print{display:none!important}}' +
      '</style></head><body>' +
      '<div class="print-toolbar no-print">' +
        '<span><i class="bi bi-file-earmark-richtext" style="font-size:16px"></i> Finished Goods Report Ready</span>' +
        '<button class="btn-p" onclick="window.print()"><i class="bi bi-printer"></i> Print / Save PDF</button>' +
        '<button class="btn-c" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>' +
      '</div>' +
      '<div style="padding:12px 16px">' +
      '<div class="company-header">' +
        logoHtml +
        '<div style="flex:1"><div class="company-name">' + fg_escapeHtml(fg_co.name) + '</div>' +
        (fg_co.tagline ? '<div class="company-sub">' + fg_escapeHtml(fg_co.tagline) + '</div>' : '') +
        addressHtml + contactHtml +
        '</div>' +
        '<div class="report-meta">' +
          '<div>Generated: <strong>' + dateStr + ', ' + timeStr + '</strong></div>' +
          '<div>Records: <strong>' + rows.length + '</strong></div>' +
          '<div>Columns: <strong>' + colCount + '</strong></div>' +
        '</div>' +
      '</div>' +
      '<div class="title-bar">' +
        '<span><i class="bi bi-grid"></i> FINISHED GOODS STOCK — ' + fg_escapeHtml(tab.label.toUpperCase()) + '</span>' +
        '<span style="background:rgba(255,255,255,.15);border-radius:6px;padding:4px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em">' + fg_escapeHtml(modeLabel) + '</span>' +
      '</div>' +
      '<div class="summary-cards">' +
        '<div class="sc"><div class="label">Total Rows</div><div class="val">' + rows.length + '</div></div>' +
        (totalQty ? '<div class="sc accent"><div class="label">Total Qty / PCS</div><div class="val">' + Number(totalQty.toFixed(0)).toLocaleString() + '</div></div>' : '') +
        (totalCarton ? '<div class="sc accent"><div class="label">Total / Carton</div><div class="val">' + Number(totalCarton.toFixed(0)).toLocaleString() + '</div></div>' : '') +
        '<div class="sc"><div class="label">Tab</div><div class="val" style="font-size:12px">' + fg_escapeHtml(tab.label) + '</div></div>' +
      '</div>' +
      tableHtml +
      '<div class="report-footer">' +
        '<div><strong>' + fg_escapeHtml(fg_co.name) + '</strong> — Finished Goods Report</div>' +
        '<div>' + dateStr + ' | ' + rows.length + ' records | ' + colCount + ' columns</div>' +
      '</div>' +
      '</div></body></html>';

    var w = window.open('', '_blank');
    if (!w) {
      fg_showMessage('Popup blocked. Please allow popups for print/PDF.', 'warning');
      return;
    }
    w.document.open();
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(function () {
      if (modeLabel === 'PDF Export') w.print();
    }, 400);
  }

  function fg_findRowById(id) {
    var intId = parseInt(id, 10);
    for (var i = 0; i < fg_state.rows.length; i += 1) {
      if (parseInt(fg_state.rows[i].id, 10) === intId) {
        return fg_state.rows[i];
      }
    }
    return null;
  }

  function fg_switchTab(tabKey) {
    if (!tabKey || tabKey === fg_state.activeTab) return;
    fg_state.activeTab = tabKey;
    fg_state.search = '';
    fg_state.sortKey = '';
    fg_state.sortDir = 'asc';
    fg_state.filters = {};
    fg_state.page = 1;
    fg_nodes.searchInput.value = '';

    fg_initVisibleColumns();
    fg_setAccent();
    fg_renderTabs();
    fg_renderColumnMenu();
    fg_loadSummary();
    fg_loadTable();
  }

  function fg_onAction(action, target) {
    if (action === 'switch-tab') {
      fg_switchTab(String(target.getAttribute('data-tab') || ''));
      return;
    }

    if (action === 'open-add') {
      fg_openEntryModal(null);
      return;
    }

    if (action === 'close-entry-modal') {
      fg_closeEntryModal();
      return;
    }

    if (action === 'close-view-modal') {
      fg_closeViewModal();
      return;
    }

    if (action === 'go-page') {
      var p = parseInt(target.getAttribute('data-page') || '1', 10);
      if (p >= 1) {
        fg_state.page = p;
        fg_renderTable();
        fg_renderPagination();
      }
      return;
    }

    if (action === 'toggle-columns') {
      fg_nodes.columnMenu.style.display = fg_nodes.columnMenu.style.display === 'none' ? '' : 'none';
      return;
    }

    if (action === 'toggle-col') {
      var col = String(target.getAttribute('data-col') || '');
      if (col) {
        fg_state.visibleColumns[col] = !!target.checked;
        fg_closeFilterPopup();
        fg_renderTable();
      }
      return;
    }

    if (action === 'open-col-filter') {
      fg_openFilterPopup(String(target.getAttribute('data-col') || ''), target);
      return;
    }

    if (action === 'edit-row') {
      if (!fg_state.canManageRows) return;
      var id = target.getAttribute('data-id');
      var aggQtyAttr = target.getAttribute('data-agg-qty');
      var row = fg_findRowById(id);
      if (row) {
        if (aggQtyAttr !== null && fg_state.activeTab === 'carton') {
          row = Object.assign({}, row, { quantity: fg_num(aggQtyAttr) });
        }
        fg_openEntryModal(row);
      }
      return;
    }

    if (action === 'view-row') {
      var viewId = target.getAttribute('data-id');
      var viewRow = fg_findRowById(viewId);
      if (viewRow) {
        fg_openViewModal(viewRow);
      }
      return;
    }

    if (action === 'delete-row') {
      if (!fg_state.canManageRows) return;
      var did = parseInt(target.getAttribute('data-id') || '0', 10);
      if (did > 0) {
        fg_deleteRow(did, {
          category: String(target.getAttribute('data-category') || ''),
          size: String(target.getAttribute('data-size') || '')
        });
      }
      return;
    }

    if (action === 'dispatch-row') {
      var rid = parseInt(target.getAttribute('data-id') || '0', 10);
      if (rid <= 0) {
        fg_showMessage('Invalid row selected for dispatch.', 'error');
        return;
      }
      var dispatchRow = fg_findRowById(rid);
      if (!dispatchRow) {
        fg_showMessage('Stock row not found.', 'error');
        return;
      }
      if (fg_num(dispatchRow.quantity) <= 0) {
        fg_showMessage('No stock available for dispatch.', 'warning');
        return;
      }
      fg_dispatchRow(dispatchRow);
      return;
    }

    if (action === 'open-import') {
      if (!fg_state.isAdmin) {
        fg_showMessage('Only admin can import.', 'warning');
        return;
      }
      fg_toggleImportModal(true);
      return;
    }

    if (action === 'close-import-modal') {
      fg_toggleImportModal(false);
      return;
    }

    if (action === 'parse-import') {
      fg_parseImportFile();
      return;
    }

    if (action === 'submit-import') {
      fg_submitImport();
      return;
    }

    if (action === 'change-map') {
      var mapKey = String(target.getAttribute('data-map-key') || '');
      if (mapKey) {
        fg_state.importMapping[mapKey] = target.value || '';
        fg_renderImportPreview();
      }
      return;
    }

    if (action === 'reset-filter') {
      fg_state.filters = {};
      fg_state.page = 1;
      fg_applyFilterSort();
      return;
    }

    if (action === 'view-item-details') {
      fg_openItemDetailsWindow();
      return;
    }

    if (action === 'export-excel') {
      fg_exportCsv();
      return;
    }

    if (action === 'export-pdf') {
      fg_openPrintWindow('PDF Export');
      return;
    }

    if (action === 'print-view') {
      fg_openPrintWindow('Print View');
      return;
    }
  }

  function fg_bindEvents() {
    fg_root.addEventListener('click', function (ev) {
      var actionEl = ev.target.closest('[data-fg-action]');
      if (!actionEl) return;
      var action = String(actionEl.getAttribute('data-fg-action') || '');
      fg_onAction(action, actionEl);
    });

    fg_root.addEventListener('click', function (ev) {
      var sortEl = ev.target.closest('[data-fg-sort]');
      if (!sortEl) return;
      var key = String(sortEl.getAttribute('data-fg-sort') || '');
      if (!key) return;
      if (fg_state.sortKey === key) {
        fg_state.sortDir = fg_state.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        fg_state.sortKey = key;
        fg_state.sortDir = 'asc';
      }
      fg_applyFilterSort();
    });

    if (fg_nodes.searchInput) {
      fg_nodes.searchInput.addEventListener('input', function () {
        fg_state.search = fg_nodes.searchInput.value || '';
        fg_state.page = 1;
        fg_applyFilterSort();
      });
    }

    if (fg_nodes.reportPeriod) {
      fg_nodes.reportPeriod.addEventListener('change', function () {
        fg_state.reportPeriod = String(fg_nodes.reportPeriod.value || 'month');
        fg_loadPeriodReport();
      });
    }

    if (fg_nodes.itemSummarySelect) {
      fg_nodes.itemSummarySelect.addEventListener('change', fg_updateItemSummaryTotal);
    }

    if (fg_nodes.entryForm) {
      fg_nodes.entryForm.addEventListener('submit', fg_submitEntry);
    }

    if (fg_nodes.entryModal) {
      fg_nodes.entryModal.addEventListener('click', function (ev) {
        if (ev.target === fg_nodes.entryModal) {
          fg_closeEntryModal();
        }
      });
    }

    if (fg_nodes.importModal) {
      fg_nodes.importModal.addEventListener('click', function (ev) {
        if (ev.target === fg_nodes.importModal) {
          fg_toggleImportModal(false);
        }
      });
    }

    if (fg_nodes.viewModal) {
      fg_nodes.viewModal.addEventListener('click', function (ev) {
        if (ev.target === fg_nodes.viewModal) {
          fg_closeViewModal();
        }
      });
    }

    document.addEventListener('click', function (ev) {
      if (!fg_state.activeFilterCol) return;

      var openBtn = ev.target.closest('[data-fg-action="open-col-filter"]');
      if (openBtn) return;

      var popup = document.getElementById('fg-col-filter-' + fg_state.activeFilterCol);
      if (popup && popup.contains(ev.target)) return;

      fg_closeFilterPopup();
    });

    document.addEventListener('input', function (ev) {
      var searchEl = ev.target.closest('[data-fg-cfp="search"]');
      if (!searchEl) return;

      var col = String(searchEl.getAttribute('data-col') || '');
      var popup = document.getElementById('fg-col-filter-' + col);
      if (!popup) return;

      var q = String(searchEl.value || '').toLowerCase();
      var items = popup.querySelectorAll('.fg-cfp-item');
      for (var i = 0; i < items.length; i += 1) {
        var text = String(items[i].textContent || '').toLowerCase();
        items[i].style.display = text.indexOf(q) >= 0 ? '' : 'none';
      }
    });

    document.addEventListener('change', function (ev) {
      var selectAll = ev.target.closest('[data-fg-cfp="select-all"]');
      if (selectAll) {
        var col = String(selectAll.getAttribute('data-col') || '');
        var popup = document.getElementById('fg-col-filter-' + col);
        if (!popup) return;

        var items = popup.querySelectorAll('.fg-cfp-list input[type="checkbox"][data-fg-cfp="item"]');
        for (var i = 0; i < items.length; i += 1) {
          items[i].checked = !!selectAll.checked;
        }
        fg_syncFilterSelectAll(col);
        return;
      }

      var itemCb = ev.target.closest('[data-fg-cfp="item"]');
      if (itemCb) {
        var itemCol = String(itemCb.getAttribute('data-col') || '');
        fg_syncFilterSelectAll(itemCol);
      }
    });

    document.addEventListener('click', function (ev) {
      var applyBtn = ev.target.closest('[data-fg-cfp="apply"]');
      if (!applyBtn) return;
      fg_commitFilter(String(applyBtn.getAttribute('data-col') || ''));
    });

    document.addEventListener('scroll', function () {
      if (fg_state.activeFilterCol) {
        fg_closeFilterPopup();
      }
    }, true);
  }

  function fg_boot() {
    fg_initVisibleColumns();
    fg_setAccent();
    fg_renderTabs();
    fg_renderColumnMenu();
    fg_bindEvents();
    fg_loadTabCounts();
    fg_loadSummary();
    fg_loadTable();
  }

  fg_boot();
})();
