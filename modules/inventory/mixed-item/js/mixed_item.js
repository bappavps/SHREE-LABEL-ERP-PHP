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
    tabCounts: {},
    selectedIds: {},
    search: '',
    filters: {},
    activeFilterCol: ''
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
    return Math.round(num(v)).toLocaleString('en-IN');
  }

  function filterToken(v) {
    var s = String(v == null ? '' : v).trim().toLowerCase();
    return s ? s : '__blank__';
  }

  function columns() {
    return [
      { key: 'item_name', label: 'Item' },
      { key: 'batch_no', label: 'Batch' },
      { key: 'size', label: 'Size' },
      { key: 'gsm', label: 'GSM' },
      { key: 'width', label: 'Width' },
      { key: 'length', label: 'Length' },
      { key: 'total_qty', label: 'Total Qty' },
      { key: 'per_carton', label: 'Per Carton' },
      { key: 'extra_qty', label: 'Extra' },
      { key: 'unit_type', label: 'Unit' },
      { key: 'possible_cartons', label: 'Possible Cartons' },
      { key: 'date', label: 'Date' }
    ];
  }

  function columnValue(row, key) {
    if (key === 'total_qty' || key === 'per_carton' || key === 'extra_qty') {
      return fmt(row[key] || 0);
    }
    if (key === 'possible_cartons') {
      return String(Math.round(num(row[key] || 0)));
    }
    return String(row[key] == null || row[key] === '' ? '-' : row[key]);
  }

  function filterOptions(colKey) {
    var map = {};
    state.rows.forEach(function (row) {
      var raw = columnValue(row, colKey);
      var token = filterToken(raw);
      if (!Object.prototype.hasOwnProperty.call(map, token)) {
        map[token] = raw || 'Blanks';
      }
    });

    var out = Object.keys(map).map(function (token) {
      return { token: token, label: map[token] };
    });

    out.sort(function (a, b) {
      if (a.token === '__blank__') return -1;
      if (b.token === '__blank__') return 1;
      return String(a.label).localeCompare(String(b.label), undefined, { numeric: true, sensitivity: 'base' });
    });
    return out;
  }

  function closeFilterPopup() {
    var pop = document.getElementById('mixFilterPopup');
    if (pop) {
      pop.remove();
    }
    state.activeFilterCol = '';
  }

  function syncFilterSelectAll(pop) {
    if (!pop) return;
    var all = pop.querySelectorAll('input[data-mix-filter-item]');
    var checked = pop.querySelectorAll('input[data-mix-filter-item]:checked');
    var selectAll = pop.querySelector('input[data-mix-filter-all]');
    if (selectAll) {
      selectAll.checked = all.length > 0 && all.length === checked.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
    }
  }

  function applyFiltersAndSearch() {
    var q = String(state.search || '').toLowerCase().trim();
    var cols = columns();

    state.filteredRows = state.rows.filter(function (r) {
      if (q) {
        var found = cols.some(function (c) {
          return String(columnValue(r, c.key) || '').toLowerCase().indexOf(q) >= 0;
        });
        if (!found) {
          return false;
        }
      }

      for (var key in state.filters) {
        if (!Object.prototype.hasOwnProperty.call(state.filters, key)) continue;
        var selected = state.filters[key];
        if (!Array.isArray(selected) || !selected.length) continue;
        var got = filterToken(columnValue(r, key));
        if (selected.indexOf(got) === -1) {
          return false;
        }
      }
      return true;
    });

    renderTable();
  }

  function openFilterPopup(colKey, triggerEl) {
    if (!colKey || !triggerEl) return;
    if (state.activeFilterCol === colKey) {
      closeFilterPopup();
      return;
    }

    closeFilterPopup();
    state.activeFilterCol = colKey;

    var pop = document.createElement('div');
    pop.id = 'mixFilterPopup';
    pop.className = 'mix-filter-popup';

    var options = filterOptions(colKey);
    var active = Array.isArray(state.filters[colKey]) ? state.filters[colKey] : [];
    var useActive = active.length > 0;

    var html = '<div class="mix-filter-head">' +
      '<input type="text" class="mix-filter-search" placeholder="Search values...">' +
      '<label class="mix-filter-select-all"><input type="checkbox" data-mix-filter-all> <span>Select All</span></label>' +
      '</div>' +
      '<div class="mix-filter-list">';

    options.forEach(function (opt) {
      var checked = useActive ? active.indexOf(opt.token) !== -1 : true;
      html += '<label class="mix-filter-item" data-token="' + esc(opt.token) + '">' +
        '<input type="checkbox" data-mix-filter-item value="' + esc(opt.token) + '" ' + (checked ? 'checked' : '') + '> ' +
        '<span>' + esc(opt.label) + '</span>' +
        '</label>';
    });

    html += '</div><div class="mix-filter-actions">' +
      '<button type="button" class="mix-btn" data-mix-filter-reset>Reset</button>' +
      '<button type="button" class="mix-btn mix-btn-green" data-mix-filter-apply>Apply</button>' +
      '</div>';

    pop.innerHTML = html;
    document.body.appendChild(pop);

    var rect = triggerEl.getBoundingClientRect();
    pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 300)) + 'px';
    pop.style.top = (rect.bottom + window.scrollY + 6) + 'px';

    var searchEl = pop.querySelector('.mix-filter-search');
    if (searchEl) {
      searchEl.focus();
      searchEl.addEventListener('input', function () {
        var query = String(searchEl.value || '').toLowerCase();
        pop.querySelectorAll('.mix-filter-item').forEach(function (item) {
          var text = String(item.textContent || '').toLowerCase();
          item.style.display = text.indexOf(query) >= 0 ? '' : 'none';
        });
      });
    }

    var selectAll = pop.querySelector('input[data-mix-filter-all]');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        pop.querySelectorAll('input[data-mix-filter-item]').forEach(function (cb) {
          if (cb.closest('.mix-filter-item').style.display !== 'none') {
            cb.checked = !!selectAll.checked;
          }
        });
        syncFilterSelectAll(pop);
      });
    }

    pop.querySelectorAll('input[data-mix-filter-item]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        syncFilterSelectAll(pop);
      });
    });

    var resetBtn = pop.querySelector('[data-mix-filter-reset]');
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        delete state.filters[colKey];
        closeFilterPopup();
        applyFiltersAndSearch();
      });
    }

    var applyBtn = pop.querySelector('[data-mix-filter-apply]');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        var selected = [];
        pop.querySelectorAll('input[data-mix-filter-item]:checked').forEach(function (cb) {
          selected.push(String(cb.value || ''));
        });
        if (!selected.length || selected.length === options.length) {
          delete state.filters[colKey];
        } else {
          state.filters[colKey] = selected;
        }
        closeFilterPopup();
        applyFiltersAndSearch();
      });
    }

    syncFilterSelectAll(pop);
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
      var count = state.tabCounts[t.key] != null ? state.tabCounts[t.key] : 0;
      html += '<button type="button" class="mix-tab' + active + '" data-mix-tab="' + esc(t.key) + '">' +
        '<span>' + esc(t.label) + '</span>' +
        '<span class="mix-tab-count">' + esc(String(count)) + '</span>' +
        '</button>';
    });
    nodes.tabs.innerHTML = html;
  }

  function renderTable() {
    var cols = columns();
    var headHtml = '<tr><th><input type="checkbox" id="mixSelectAll"></th>';
    cols.forEach(function (col) {
      var activeCount = Array.isArray(state.filters[col.key]) ? state.filters[col.key].length : 0;
      headHtml += '<th><div class="mix-th-wrap">' +
        '<span>' + esc(col.label) + '</span>' +
        '<button type="button" class="mix-filter-btn ' + (activeCount ? 'active' : '') + '" data-mix-open-filter="' + esc(col.key) + '" title="Filter ' + esc(col.label) + '">&#x1F5D0;</button>' +
        '</div></th>';
    });
    headHtml += '</tr>';
    nodes.thead.innerHTML = headHtml;

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
        columns().map(function (col) {
          var value = columnValue(r, col.key);
          if (col.key === 'extra_qty') {
            return '<td><span class="mix-extra-pill">' + esc(value) + '</span></td>';
          }
          return '<td>' + esc(value) + '</td>';
        }).join('') +
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
    applyFiltersAndSearch();
  }

  function loadRows() {
    nodes.tbody.innerHTML = '<tr><td class="mix-empty" colspan="99">Loading...</td></tr>';
    return api('get_extra_stock', { category: state.activeTab }, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load mixed rows.');
      }
      state.rows = Array.isArray(res.rows) ? res.rows : [];
      state.filteredRows = state.rows.slice();
      state.tabCounts[state.activeTab] = state.rows.length;
      renderTabs();

      var summary = res.summary || {};
      nodes.totalItems.textContent = String(summary.total_items || 0);
      nodes.totalExtra.textContent = fmt(summary.total_extra || 0);

      applySearch();
    }).catch(function (err) {
      nodes.tbody.innerHTML = '<tr><td class="mix-empty" colspan="99">Unable to load data.</td></tr>';
      showMessage(err.message || 'Unable to load data.', 'error');
    });
  }

  function loadTabCounts() {
    return api('get_tab_counts', {}, 'GET').then(function (res) {
      if (!res || !res.ok) {
        throw new Error((res && res.error) || 'Unable to load tab counts.');
      }
      state.tabCounts = res.counts && typeof res.counts === 'object' ? res.counts : {};
      renderTabs();
    }).catch(function () {
      state.tabCounts = {};
      renderTabs();
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
      loadTabCounts();
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
      state.filters = {};
      closeFilterPopup();
      renderTabs();
      loadRows();
    });

    nodes.thead.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-mix-open-filter]');
      if (!btn) return;
      openFilterPopup(String(btn.getAttribute('data-mix-open-filter') || ''), btn);
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

    document.addEventListener('click', function (ev) {
      if (!state.activeFilterCol) return;
      var popup = document.getElementById('mixFilterPopup');
      if (popup && popup.contains(ev.target)) return;
      if (ev.target.closest('[data-mix-open-filter]')) return;
      closeFilterPopup();
    });

    document.addEventListener('scroll', function () {
      if (state.activeFilterCol) {
        closeFilterPopup();
      }
    }, true);
  }

  function boot() {
    bindEvents();
    loadTabs();
  }

  boot();
})();
