(function () {
  'use strict';

  var root = document.getElementById('mixPage');
  if (!root) {
    return;
  }

  var state = {
    apiUrl: String(root.getAttribute('data-api-url') || ''),
    csrf: String(root.getAttribute('data-csrf-token') || ''),
    packingUrl: String(root.getAttribute('data-packing-url') || ''),
    planningUrl: String(root.getAttribute('data-planning-url') || ''),
    tabs: [],
    activeTab: '',
    rows: [],
    filteredRows: [],
    selectedIds: {},
    search: ''
  };

  var nodes = {
    tabs: document.getElementById('mixTabs'),
    totalItems: document.getElementById('mixTotalItems'),
    totalExtra: document.getElementById('mixTotalExtra'),
    selectedCount: document.getElementById('mixSelectedCount'),
    search: document.getElementById('mixSearch'),
    thead: document.getElementById('mixThead'),
    tbody: document.getElementById('mixTbody'),
    assignBtn: document.getElementById('mixAssignBtn'),
    assignTarget: document.getElementById('mixAssignTarget'),
    openBoardBtn: document.getElementById('mixOpenBoardBtn')
  };

  function showMessage(msg, type) {
    if (typeof window.showERPToast === 'function') {
      window.showERPToast(String(msg || ''), type || 'info');
      return;
    }
    alert(String(msg || ''));
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function num(v) {
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }

  function fmt(v) {
    return Number(num(v).toFixed(3)).toLocaleString('en-IN');
  }

  function api(action, params, method) {
    var m = method || 'GET';
    if (m === 'GET') {
      var q = new URLSearchParams();
      q.set('action', action);
      Object.keys(params || {}).forEach(function (k) {
        q.set(k, String(params[k] == null ? '' : params[k]));
      });
      return fetch(state.apiUrl + '?' + q.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    var body = new FormData();
    body.set('action', action);
    body.set('csrf_token', state.csrf);
    Object.keys(params || {}).forEach(function (k) {
      var val = params[k];
      if (Array.isArray(val) || (val && typeof val === 'object')) {
        body.set(k, JSON.stringify(val));
      } else {
        body.set(k, String(val == null ? '' : val));
      }
    });

    return fetch(state.apiUrl, {
      method: 'POST',
      body: body,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function renderTabs() {
    var html = '';
    state.tabs.forEach(function (t) {
      var active = t.key === state.activeTab ? ' active' : '';
      html += '<button type="button" class="mix-tab' + active + '" data-mix-tab="' + esc(t.key) + '">' + esc(t.label) + '</button>';
    });
    nodes.tabs.innerHTML = html;
  }

  function renderTable() {
    nodes.thead.innerHTML = '<tr>' +
      '<th><input type="checkbox" id="mixSelectAll"></th>' +
      '<th>Item</th>' +
      '<th>Batch</th>' +
      '<th>Size</th>' +
      '<th>GSM</th>' +
      '<th>Width</th>' +
      '<th>Length</th>' +
      '<th>Total Qty</th>' +
      '<th>Per Carton</th>' +
      '<th>Extra</th>' +
      '<th>Unit</th>' +
      '<th>Possible Cartons</th>' +
      '<th>Date</th>' +
      '</tr>';

    if (!state.filteredRows.length) {
      nodes.tbody.innerHTML = '<tr><td class="mix-empty" colspan="99">No extra production found in this tab.</td></tr>';
      syncSelectedCount();
      return;
    }

    var html = '';
    state.filteredRows.forEach(function (r) {
      var checked = state.selectedIds[r.id] ? 'checked' : '';
      html += '<tr>' +
        '<td><input type="checkbox" data-mix-select="' + esc(r.id) + '" ' + checked + '></td>' +
        '<td>' + esc(r.item_name || '-') + '</td>' +
        '<td>' + esc(r.batch_no || '-') + '</td>' +
        '<td>' + esc(r.size || '-') + '</td>' +
        '<td>' + esc(r.gsm || '-') + '</td>' +
        '<td>' + esc(r.width || '-') + '</td>' +
        '<td>' + esc(r.length || '-') + '</td>' +
        '<td>' + esc(fmt(r.total_qty || 0)) + '</td>' +
        '<td>' + esc(fmt(r.per_carton || 0)) + '</td>' +
        '<td><span class="mix-extra-pill">' + esc(fmt(r.extra_qty || 0)) + '</span></td>' +
        '<td>' + esc(r.unit_type || '-') + '</td>' +
        '<td>' + esc(String(r.possible_cartons || 0)) + '</td>' +
        '<td>' + esc(r.date || '-') + '</td>' +
      '</tr>';
    });

    nodes.tbody.innerHTML = html;
    syncSelectAll();
    syncSelectedCount();
  }

  function syncSelectAll() {
    var all = document.getElementById('mixSelectAll');
    if (!all) return;
    var boxes = nodes.tbody.querySelectorAll('input[type="checkbox"][data-mix-select]');
    if (!boxes.length) {
      all.checked = false;
      all.indeterminate = false;
      return;
    }
    var checked = 0;
    boxes.forEach(function (b) { if (b.checked) checked += 1; });
    all.checked = checked === boxes.length;
    all.indeterminate = checked > 0 && checked < boxes.length;
  }

  function syncSelectedCount() {
    var count = 0;
    Object.keys(state.selectedIds).forEach(function (id) {
      if (state.selectedIds[id]) count += 1;
    });
    nodes.selectedCount.textContent = String(count);
  }

  function applySearch() {
    var q = String(state.search || '').toLowerCase().trim();
    if (!q) {
      state.filteredRows = state.rows.slice();
      renderTable();
      return;
    }

    state.filteredRows = state.rows.filter(function (r) {
      var blob = [
        r.item_name, r.batch_no, r.size, r.gsm, r.width, r.length, r.unit_type, r.date,
        r.total_qty, r.per_carton, r.extra_qty
      ].join(' ').toLowerCase();
      return blob.indexOf(q) >= 0;
    });
    renderTable();
  }

  function loadRows() {
    nodes.tbody.innerHTML = '<tr><td class="mix-empty" colspan="99">Loading...</td></tr>';
    return api('get_extra_stock', { category: state.activeTab }, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load mixed rows.');
      }
      state.rows = Array.isArray(res.rows) ? res.rows : [];
      state.filteredRows = state.rows.slice();

      var summary = res.summary || {};
      nodes.totalItems.textContent = String(summary.total_items || 0);
      nodes.totalExtra.textContent = fmt(summary.total_extra || 0);

      applySearch();
    }).catch(function (err) {
      nodes.tbody.innerHTML = '<tr><td class="mix-empty" colspan="99">Unable to load data.</td></tr>';
      showMessage(err.message || 'Unable to load data.', 'error');
    });
  }

  function loadTabs() {
    return api('get_tabs', {}, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load tabs.');
      }
      state.tabs = Array.isArray(res.tabs) ? res.tabs : [];
      if (!state.tabs.length) {
        throw new Error('No tabs configured.');
      }
      state.activeTab = state.tabs[0].key;
      renderTabs();
      return loadRows();
    }).catch(function (err) {
      showMessage(err.message || 'Unable to load tabs.', 'error');
    });
  }

  function selectedRows() {
    var out = [];
    state.rows.forEach(function (r) {
      if (state.selectedIds[r.id]) {
        out.push(r);
      }
    });
    return out;
  }

  function assignSelected() {
    var rows = selectedRows();
    if (!rows.length) {
      showMessage('Please select at least one row.', 'warning');
      return;
    }

    var target = String(nodes.assignTarget.value || 'packing');
    api('assign_mixed_items', {
      target: target,
      source_category: state.activeTab,
      items: rows,
      note: ''
    }, 'POST').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to assign selected items.');
      }

      var info = res.assignment || {};
      showMessage((res.message || 'Assigned.') + ' Code: ' + String(info.code || '-'), 'success');
      state.selectedIds = {};
      syncSelectedCount();
      renderTable();
    }).catch(function (err) {
      showMessage(err.message || 'Unable to assign selected items.', 'error');
    });
  }

  function openBoard() {
    var target = String(nodes.assignTarget.value || 'packing');
    if (target === 'planning') {
      window.location.href = state.planningUrl;
      return;
    }
    var status = state.activeTab === 'barcode' ? 'Finished Barcode' : 'Finished Production';
    window.location.href = state.packingUrl + '?status=' + encodeURIComponent(status);
  }

  function bindEvents() {
    nodes.tabs.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-mix-tab]');
      if (!btn) return;
      var key = String(btn.getAttribute('data-mix-tab') || '');
      if (!key || key === state.activeTab) return;
      state.activeTab = key;
      state.selectedIds = {};
      renderTabs();
      loadRows();
    });

    if (nodes.search) {
      nodes.search.addEventListener('input', function () {
        state.search = nodes.search.value || '';
        applySearch();
      });
    }

    nodes.tbody.addEventListener('change', function (ev) {
      var cb = ev.target.closest('input[type="checkbox"][data-mix-select]');
      if (!cb) return;
      var id = String(cb.getAttribute('data-mix-select') || '');
      if (!id) return;
      state.selectedIds[id] = !!cb.checked;
      syncSelectAll();
      syncSelectedCount();
    });

    nodes.thead.addEventListener('change', function (ev) {
      var all = ev.target.closest('#mixSelectAll');
      if (!all) return;
      var boxes = nodes.tbody.querySelectorAll('input[type="checkbox"][data-mix-select]');
      boxes.forEach(function (b) {
        b.checked = !!all.checked;
        var id = String(b.getAttribute('data-mix-select') || '');
        if (id) {
          state.selectedIds[id] = !!all.checked;
        }
      });
      syncSelectAll();
      syncSelectedCount();
    });

    if (nodes.assignBtn) {
      nodes.assignBtn.addEventListener('click', assignSelected);
    }

    if (nodes.openBoardBtn) {
      nodes.openBoardBtn.addEventListener('click', openBoard);
    }
  }

  function boot() {
    bindEvents();
    loadTabs();
  }

  boot();
})();
