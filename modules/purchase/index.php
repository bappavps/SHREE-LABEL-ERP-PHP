<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$appSettings  = getAppSettings();
$companyName  = (string)($appSettings['company_name'] ?? APP_NAME);

$pageTitle = 'Purchase Orders';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.po-banner {
  display:flex;
  align-items:center;
  gap:12px;
  padding:14px 16px;
  border-radius:12px;
  border:1px solid #bfdbfe;
  background:linear-gradient(135deg,#eff6ff 0%,#ecfeff 100%);
  color:#0c4a6e;
  margin-bottom:14px;
}
.po-toolbar {
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
  padding:10px 16px 8px;
}
.po-search {
  flex:1;
  min-width:180px;
  max-width:260px;
  padding:6px 10px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  font-size:.84rem;
}
.po-badge {
  padding:3px 10px;
  border-radius:999px;
  font-size:.75rem;
  font-weight:700;
  line-height:1;
}
.po-badge.connected    { background:#dcfce7; color:#166534; }
.po-badge.disconnected { background:#fee2e2; color:#b91c1c; }

/* Mobile card layout for PO table */
@media (max-width: 640px) {
  .po-toolbar { flex-direction:column; align-items:stretch; }
  .po-toolbar select, .po-search { max-width:100%; width:100%; }
  #poCount { margin-left:0 !important; }
  .mobile-card-table thead { display:none; }
  .mobile-card-table tbody tr {
    display:block;
    margin-bottom:10px;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:10px 12px;
    background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.07);
  }
  .mobile-card-table tbody td {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:4px 0;
    border:none;
    font-size:.83rem;
    border-bottom:1px solid #f1f5f9;
  }
  .mobile-card-table tbody td:last-child { border-bottom:none; padding-top:8px; }
  .mobile-card-table tbody td::before {
    content: attr(data-label);
    font-weight:600;
    color:#64748b;
    font-size:.75rem;
    text-transform:uppercase;
    letter-spacing:.03em;
    min-width:90px;
    flex-shrink:0;
  }
  .mobile-card-table tbody td[data-label=""]::before,
  .mobile-card-table tbody td[data-label="Action"]::before { display:none; }
  .mobile-card-table tbody td[data-label="Action"] { justify-content:flex-end; gap:6px; flex-wrap:wrap; }
}

/* Print styles */
@media print {
  body * { visibility: hidden !important; }
  #posPrintArea, #posPrintArea * { visibility: visible !important; }
  #posPrintArea { position:absolute; left:0; top:0; width:100%; }
  .no-print { display:none !important; }
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Purchase</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Purchase Orders</span>
</div>

<div class="page-header">
  <div>
    <h1>Purchase Orders</h1>
    <p>Tally PO list — read-only view. Manual purchase entry is available separately.</p>
  </div>
</div>

<!-- Tally PO Section -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:10px">
      <span class="card-title">Tally Purchase Orders</span>
      <span id="poBadge" class="po-badge disconnected" style="display:none"></span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button id="poSyncBtn" class="btn btn-sm" style="background:#0ea5e9;color:#fff" onclick="poSync(true)">
        <i class="bi bi-arrow-repeat"></i> Sync PO from Tally
      </button>
      <button id="poDeleteSelBtn" class="btn btn-sm btn-danger" style="display:none" onclick="poDeleteSelected()">
        <i class="bi bi-trash"></i> Delete Selected
      </button>
      <button class="btn btn-sm" style="background:#f1f5f9;color:#dc2626;border:1px solid #fca5a5" onclick="poClearAll()">
        <i class="bi bi-x-circle"></i> Clear All PO Cache
      </button>
    </div>
  </div>

  <div id="poMsg" style="display:none;margin:0 16px 4px;padding:8px 12px;border-radius:8px;font-size:.83rem"></div>

  <!-- Tally date-range notice -->
  <div id="poTallyHint" style="display:none;margin:0 16px 8px;padding:10px 14px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;font-size:.82rem;color:#92400e">
    <strong>⚠ কোনো Purchase Order পাওয়া যায়নি।</strong>
    Tally-র Daybook শুধু current date-এর data দেয়।<br>
    <strong>Historical PO দেখতে:</strong> Tally খুলুন → <em>Day Book</em> → <kbd>F2</kbd> চাপুন → Period
    <strong>1-Apr-2024</strong> থেকে আজকের তারিখ পর্যন্ত সেট করুন → তারপর এখানে <strong>Sync</strong> করুন।
  </div>

  <div class="po-toolbar">
    <input id="poSearch" class="po-search" type="search" placeholder="Search PO, Supplier, Item..." oninput="poFilterRows()">
    <select id="poYear" onchange="poApplyFilters()" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.84rem">
      <option value="">All Years</option>
    </select>
    <select id="poMonth" onchange="poApplyFilters()" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.84rem">
      <option value="">All Months</option>
      <option value="01">January</option><option value="02">February</option>
      <option value="03">March</option><option value="04">April</option>
      <option value="05">May</option><option value="06">June</option>
      <option value="07">July</option><option value="08">August</option>
      <option value="09">September</option><option value="10">October</option>
      <option value="11">November</option><option value="12">December</option>
    </select>
    <span id="poCount" style="font-size:.8rem;color:#64748b;margin-left:auto"></span>
  </div>

  <!-- Summary Section -->
  <div id="poSummary" style="display:none;padding:0 12px 12px"></div>

  <div id="poDeleteBar" style="display:none;padding:6px 16px;background:#fef2f2;border-bottom:1px solid #fecaca;font-size:.82rem;color:#991b1b">
    <span id="poSelCount">0</span> row(s) selected &nbsp;
    <button class="btn btn-xs btn-danger" onclick="poDeleteSelected()"><i class="bi bi-trash"></i> Delete Selected</button>
    <button class="btn btn-xs" style="margin-left:4px" onclick="poUnselectAll()">Unselect All</button>
  </div>
  <div class="table-responsive">
    <table class="table mobile-card-table" id="poTable">
      <thead>
        <tr>
          <th style="width:36px"><input type="checkbox" id="poSelectAll" onchange="poToggleAll(this)" title="Select all"></th>
          <th>PO No</th>
          <th>Date</th>
          <th>Supplier / Party</th>
          <th>Item</th>
          <th>Qty</th>
          <th>Rate</th>
          <th>Amount</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="poTbody">
        <tr id="poPlaceholder"><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">
          <i class="bi bi-arrow-repeat" style="font-size:1.2rem;opacity:.4"></i>
          <div style="margin-top:6px;font-size:.84rem">Checking Tally connection…</div>
        </td></tr>
      </tbody>
    </table>
    <div id="poPager" style="display:none;padding:8px 12px;border-top:1px solid #e2e8f0;background:#f8fafc"></div>
</div>

<!-- Print Modal -->
<div id="poPrintModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:2000;overflow-y:auto">
  <div style="max-width:760px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.2);overflow:hidden">
    <div class="no-print" style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between">
      <strong style="font-size:.95rem">Print Preview</strong>
      <div style="display:flex;gap:8px">
        <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <button class="btn btn-sm btn-secondary" onclick="document.getElementById('poPrintModal').style.display='none'">Close</button>
      </div>
    </div>
    <div id="posPrintArea" style="padding:32px 40px;font-family:'Segoe UI',Arial,sans-serif;font-size:13px;color:#0f172a">
      <!-- filled by JS -->
    </div>
  </div>
</div>

<script>
const PO_FETCH_URL    = '<?= e(BASE_URL) ?>/modules/tally/fetch_po.php';
const PO_CHECK_URL    = '<?= e(BASE_URL) ?>/modules/tally/check_connection.php';
const PO_COMPANY_NAME = '<?= e($companyName) ?>';
const PO_COMPANY_ADDR = '<?= e($appSettings["company_address"] ?? "") ?>';
var _poSyncedOnce = false; // true only after a real Tally sync attempt
const PO_COMPANY_GST  = '<?= e($appSettings["company_gst"] ?? "") ?>';
const PO_COMPANY_EMAIL= '<?= e($appSettings["company_email"] ?? "") ?>';
const PO_COMPANY_PHONE= '<?= e($appSettings["company_mobile"] ?? $appSettings["company_phone"] ?? "") ?>';
const PO_PAGE_SIZE    = 100;

var _poRows     = [];  // all rows from Tally
var _poFiltered = [];  // after search + year/month filter
var _poPage     = 1;

function poNormalizeRow(r) {
  var row = r || {};
  row.party_name = row.party_name || row.supplier_name || row.client_name || '';
  // Extract YYYY and MM from Tally date YYYYMMDD
  var d = String(row.date || '');
  row._year  = /^\d{8}$/.test(d) ? d.slice(0, 4) : (d.slice(0, 4) || '');
  row._month = /^\d{8}$/.test(d) ? d.slice(4, 6) : '';
  return row;
}

// ── helpers ──────────────────────────────────────────────────────────────────
function poEsc(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function poFmtDate(d) {
  if (!d || d.length < 8) return d || '-';
  // Tally date: YYYYMMDD
  if (/^\d{8}$/.test(d)) {
    return d.slice(6,8) + '/' + d.slice(4,6) + '/' + d.slice(0,4);
  }
  return d;
}

function poSetBadge(connected) {
  var b = document.getElementById('poBadge');
  if (!b) return;
  b.style.display = 'inline-block';
  if (connected) {
    b.textContent = 'Connected';
    b.className = 'po-badge connected';
  } else {
    b.textContent = 'Disconnected';
    b.className = 'po-badge disconnected';
  }
}

function poShowMsg(text, color, bg) {
  var el = document.getElementById('poMsg');
  if (!el) return;
  el.style.display  = 'block';
  el.style.color    = color  || '#334155';
  el.style.background = bg   || '#f8fafc';
  el.style.border   = '1px solid #e2e8f0';
  el.textContent    = text;
}

function poHideMsg() {
  var el = document.getElementById('poMsg');
  if (el) el.style.display = 'none';
}

// ── rendering ────────────────────────────────────────────────────────────────
function poRender(rows) {
  var tbody = document.getElementById('poTbody');
  var pager = document.getElementById('poPager');
  if (!tbody) return;

  if (!rows || rows.length === 0) {
    var hasSourceRows = Array.isArray(_poRows) && _poRows.length > 0;
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">'
      + (hasSourceRows ? 'No purchase orders match selected month/year/search.' : 'No purchase orders found.')
      + '</td></tr>';
    document.getElementById('poCount').textContent = '';
    if (pager) pager.style.display = 'none';
    var hint = document.getElementById('poTallyHint');
    if (hint) hint.style.display = (_poSyncedOnce && !hasSourceRows) ? '' : 'none';
    return;
  }
  var hint2 = document.getElementById('poTallyHint');
  if (hint2) hint2.style.display = 'none';

  var total      = rows.length;
  var totalPages = Math.max(1, Math.ceil(total / PO_PAGE_SIZE));
  if (_poPage > totalPages) _poPage = totalPages;
  if (_poPage < 1) _poPage = 1;
  var start = (_poPage - 1) * PO_PAGE_SIZE;
  var end   = Math.min(start + PO_PAGE_SIZE, total);
  var page  = rows.slice(start, end);

  if (pager) {
    pager.style.display = '';
    pager.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">'
      + '<div style="font-size:.82rem;color:#475569">Showing ' + (start+1) + '–' + end + ' of ' + total + ' records</div>'
      + '<div style="display:flex;align-items:center;gap:8px">'
      + '<button type="button" class="btn btn-xs" onclick="poGoPage(-1)" ' + (_poPage<=1 ? 'disabled style="opacity:.6;cursor:not-allowed"' : '') + '>Previous</button>'
      + '<span style="font-size:.82rem;color:#334155">Page ' + _poPage + ' / ' + totalPages + '</span>'
      + '<button type="button" class="btn btn-xs" onclick="poGoPage(1)" ' + (_poPage>=totalPages ? 'disabled style="opacity:.6;cursor:not-allowed"' : '') + '>Next</button>'
      + '</div></div>';
  }

  var html = '';
  for (var i = 0; i < page.length; i++) {
    var r   = page[i];
    var abs = start + i;
    var poKey = poEsc(r.po_number || '');
    html += '<tr>';
    html += '<td data-label=""><input type="checkbox" class="po-row-chk" data-key="' + poKey + '" onchange="poOnCheckChange()"></td>';
    html += '<td data-label="PO No">' + poEsc(r.po_number || '-') + '</td>';
    html += '<td data-label="Date">' + poEsc(poFmtDate(r.date)) + '</td>';
    html += '<td data-label="Supplier">' + poEsc(r.party_name || '-') + '</td>';
    html += '<td data-label="Item">' + poEsc(r.item_name   || '-') + '</td>';
    html += '<td data-label="Qty">' + poEsc(r.quantity    || '-') + '</td>';
    html += '<td data-label="Rate">' + poEsc(r.rate        || '-') + '</td>';
    html += '<td data-label="Amount">' + poEsc(r.amount      || '-') + '</td>';
    html += '<td data-label="Action" style="white-space:nowrap">'
          + '<button class="btn btn-xs btn-info" onclick="poView(' + abs + ')"><i class="bi bi-eye"></i> View</button> '
          + '<button class="btn btn-xs" style="background:#0ea5e9;color:#fff" onclick="poPrint(' + abs + ')"><i class="bi bi-printer"></i> Print</button>'
          + '</td>';
    html += '</tr>';
  }
  tbody.innerHTML = html;
  document.getElementById('poCount').textContent = total + ' record' + (total !== 1 ? 's' : '');
}

function poGoPage(delta) {
  _poPage += delta;
  poRender(_poFiltered);
}

// ── summary by month/party ────────────────────────────────────────────────────
function poRenderSummary(rows) {
  var el = document.getElementById('poSummary');
  if (!el) return;
  if (!rows || rows.length === 0) { el.style.display = 'none'; return; }

  // Group by YYYY-MM
  var monthMap = {};
  var partySet = {};
  for (var i = 0; i < rows.length; i++) {
    var r   = rows[i];
    var key = (r._year || '????') + '-' + (r._month || '??');
    var lbl = (r._month && r._year)
      ? (new Date(parseInt(r._year, 10), parseInt(r._month, 10)-1, 1)
           .toLocaleString('default', {month:'long', year:'numeric'}))
      : key;
    if (!monthMap[key]) monthMap[key] = { label: lbl, count: 0, parties: {}, amount: 0 };
    monthMap[key].count++;
    var party = r.party_name || '-';
    monthMap[key].parties[party] = (monthMap[key].parties[party] || 0) + 1;
    var amt = parseFloat(String(r.amount || '0').replace(/[^0-9.]/g,'')) || 0;
    monthMap[key].amount += amt;
    partySet[party] = (partySet[party] || 0) + 1;
  }

  var keys = Object.keys(monthMap).sort().reverse();

  var html = '<div style="margin-bottom:10px">'
    + '<strong style="font-size:.9rem">Summary</strong>'
    + ' <span style="font-size:.8rem;color:#64748b">— ' + rows.length + ' POs, '
    + Object.keys(partySet).length + ' parties, '
    + keys.length + ' month(s)</span></div>';

  // Party summary table (top parties)
  var partyArr = Object.keys(partySet).map(function(p){return{name:p,count:partySet[p]};});
  partyArr.sort(function(a,b){return b.count-a.count;});
  var topParties = partyArr.slice(0, 5);

  html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:14px">';
  for (var j = 0; j < topParties.length; j++) {
    var p = topParties[j];
    html += '<div style="padding:10px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;font-size:.83rem">'
      + '<div style="font-weight:700;color:#0369a1;margin-bottom:3px">' + poEsc(p.name) + '</div>'
      + '<div style="color:#475569">' + p.count + ' PO' + (p.count>1?'s':'') + '</div>'
      + '</div>';
  }
  if (partyArr.length > 5) {
    html += '<div style="padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:.83rem;color:#64748b;display:flex;align-items:center">'
      + '+ ' + (partyArr.length - 5) + ' more parties</div>';
  }
  html += '</div>';

  // Month-wise table
  html += '<table style="width:100%;border-collapse:collapse;font-size:.83rem;margin-bottom:4px">'
    + '<thead><tr style="background:#f1f5f9">'
    + '<th style="padding:7px 10px;border:1px solid #e2e8f0;text-align:left">Month</th>'
    + '<th style="padding:7px 10px;border:1px solid #e2e8f0;text-align:center">PO Count</th>'
    + '<th style="padding:7px 10px;border:1px solid #e2e8f0;text-align:left">Top Parties</th>'
    + '</tr></thead><tbody>';

  for (var k = 0; k < keys.length; k++) {
    var m  = monthMap[keys[k]];
    var mp = Object.keys(m.parties).sort(function(a,b){return m.parties[b]-m.parties[a];}).slice(0,3);
    var partyTags = mp.map(function(pp){
      return '<span style="padding:1px 8px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:.75rem;margin-right:4px">'
        + poEsc(pp) + ' (' + m.parties[pp] + ')</span>';
    }).join('');
    if (Object.keys(m.parties).length > 3) partyTags += '<span style="font-size:.75rem;color:#94a3b8">+' + (Object.keys(m.parties).length-3) + ' more</span>';

    html += '<tr style="' + (k%2===0?'background:#fff':'background:#f8fafc') + '">'
      + '<td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:600">' + poEsc(m.label) + '</td>'
      + '<td style="padding:6px 10px;border:1px solid #e2e8f0;text-align:center">'
      + '<span style="padding:2px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:700">' + m.count + '</span>'
      + '</td>'
      + '<td style="padding:6px 10px;border:1px solid #e2e8f0">' + partyTags + '</td>'
      + '</tr>';
  }

  html += '</tbody></table>';
  el.innerHTML = html;
  el.style.display = '';
}

// ── populate year filter ──────────────────────────────────────────────────────
function poPopulateYears(rows) {
  var sel   = document.getElementById('poYear');
  if (!sel) return;
  var years = {};
  for (var i = 0; i < rows.length; i++) {
    var y = rows[i]._year;
    if (y) years[y] = true;
  }
  var sorted = Object.keys(years).sort().reverse();
  // keep existing "All Years" option
  sel.options.length = 1;
  for (var j = 0; j < sorted.length; j++) {
    var opt = document.createElement('option');
    opt.value = sorted[j]; opt.textContent = sorted[j];
    sel.appendChild(opt);
  }
}

// ── filter rows ───────────────────────────────────────────────────────────────
function poApplyFilters() {
  var q  = (document.getElementById('poSearch').value || '').toLowerCase().trim();
  var yr = document.getElementById('poYear').value  || '';
  var mn = document.getElementById('poMonth').value || '';
  _poFiltered = _poRows.filter(function(r) {
    if (yr && r._year  !== yr) return false;
    if (mn && r._month !== mn) return false;
    if (q) {
      return (r.po_number   || '').toLowerCase().indexOf(q) >= 0
          || (r.party_name  || '').toLowerCase().indexOf(q) >= 0
          || (r.item_name   || '').toLowerCase().indexOf(q) >= 0;
    }
    return true;
  });
  _poPage = 1;
  poRenderSummary(_poFiltered);
  poRender(_poFiltered);
}

function poFilterRows() { poApplyFilters(); }

// ── sync ─────────────────────────────────────────────────────────────────────
function poSync(force) {
  var btn = document.getElementById('poSyncBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Syncing...'; }
  var url = PO_FETCH_URL + (force ? '?refresh=1' : '');
  fetch(url, { credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sync PO from Tally'; }
      if (!d || !d.ok) {
        poSetBadge(false);
        poShowMsg('Tally not connected — unable to fetch purchase orders.', '#b91c1c', '#fff5f5');
        document.getElementById('poTbody').innerHTML =
          '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected. Manual data only.</td></tr>';
        return;
      }
      poSetBadge(true);
      poHideMsg();
      _poSyncedOnce = true;
      _poRows     = (d.rows || d.data || []).map(poNormalizeRow);
      _poFiltered = _poRows.slice();
      _poPage     = 1;
      poPopulateYears(_poRows);
      poRenderSummary(_poFiltered);
      poRender(_poFiltered);
    })
    .catch(function() {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sync PO from Tally'; }
      poSetBadge(false);
      poShowMsg('Tally not connected or unreachable.', '#b91c1c', '#fff5f5');
      document.getElementById('poTbody').innerHTML =
        '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected.</td></tr>';
    });
}

// ── view (inline expand in future; currently same as print preview) ───────────
function poView(idx) {
  poPrint(idx);
}

// ── Tally short date format: 25-Apr-26 ──────────────────────────────────────
function poFmtTallyDate(d) {
  if (!d || String(d).length < 8) return d || '';
  var s = String(d);
  if (!/^\d{8}$/.test(s)) return s;
  var mons = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return parseInt(s.slice(6,8),10) + '-' + mons[parseInt(s.slice(4,6),10)-1] + '-' + s.slice(2,4);
}

// ── build Invoice To / Consignee block (our own company) ──────────────────────
function poBuildCompanyBlock() {
  var lines = [];
  if (PO_COMPANY_ADDR) {
    var addrLines = PO_COMPANY_ADDR.split(/\n|,\s*/);
    for (var i = 0; i < addrLines.length; i++) {
      var al = addrLines[i].trim();
      if (al) lines.push(poEsc(al));
    }
  }
  if (PO_COMPANY_GST)  lines.push('GSTIN/UIN&nbsp;&nbsp;&nbsp;: <strong>' + poEsc(PO_COMPANY_GST) + '</strong>');
  if (PO_COMPANY_EMAIL)lines.push('E-Mail&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ' + poEsc(PO_COMPANY_EMAIL));
  if (PO_COMPANY_PHONE)lines.push('Phone&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ' + poEsc(PO_COMPANY_PHONE));
  return '<strong>' + poEsc(PO_COMPANY_NAME) + '</strong>'
       + (lines.length ? '<br><span style="font-size:11px">' + lines.join('<br>') + '</span>' : '');
}

// ── build Supplier block from Tally party data ────────────────────────────────
function poBuildSupplierBlock(r0) {
  var name    = r0.party_name || r0.client_name || '-';
  var addr    = r0.party_address || '';
  var gstin   = r0.party_gstin   || '';
  var state   = r0.party_state   || '';
  var pincode = r0.party_pincode || '';
  var email   = r0.party_email   || '';
  var phone   = r0.party_phone   || '';
  var msme    = r0.party_msme    || '';

  var lines = [];
  if (addr) {
    var parts = addr.split('\n');
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].trim();
      if (p) lines.push(poEsc(p));
    }
  }
  if (msme)   lines.push('MSME No.&nbsp;&nbsp;&nbsp;&nbsp;: ' + poEsc(msme));
  if (gstin)  lines.push('GSTIN/UIN&nbsp;&nbsp;&nbsp;: <strong>' + poEsc(gstin) + '</strong>');
  if (state)  {
    var stateCode = '';
    if (gstin && gstin.length >= 2) stateCode = gstin.slice(0, 2);
    lines.push('State Name&nbsp;&nbsp;: ' + poEsc(state) + (stateCode ? ', Code : ' + stateCode : ''));
  }
  if (email)  lines.push('E-Mail&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ' + poEsc(email));
  if (phone)  lines.push('Phone&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ' + poEsc(phone));

  return '<strong>' + poEsc(name) + '</strong>'
       + (lines.length ? '<br><span style="font-size:11px">' + lines.join('<br>') + '</span>' : '');
}

// ── print ─────────────────────────────────────────────────────────────────────
function poPrint(idx) {
  var base = _poFiltered[idx];
  if (!base) return;
  var poNo = base.po_number || '';

  // Collect ALL items for this PO from _poRows (so grouped items all appear)
  var allItems = _poRows.filter(function(r){ return r.po_number === poNo; });
  if (!allItems.length) allItems = [base];
  var r0 = poNormalizeRow(allItems[0]);

  var poNoEsc   = poEsc(poNo || 'N/A');
  var dated     = poFmtTallyDate(r0.date);
  var supplier  = poEsc(r0.party_name || '-');
  var supplierBlock = poBuildSupplierBlock(r0);

  // PAN = chars 2-12 of GSTIN
  var pan = (PO_COMPANY_GST && PO_COMPANY_GST.length >= 12) ? PO_COMPANY_GST.slice(2,12) : '';

  var C = 'border:1px solid #000'; // shortcut
  var BT0 = 'border-top:0;';

  // ── cell style helpers ────────────────────────────────────────────────────
  function td(style, content) { return '<td style="' + style + '">' + content + '</td>'; }
  function th(style, content) { return '<th style="' + style + '">' + content + '</th>'; }
  function hdr(txt) { return '<span style="font-size:10px;color:#000">' + txt + '</span>'; }
  var BS = 'border:1px solid #000;padding:3px 5px;'; // base cell
  var BR = 'border-right:1px solid #000;';
  var BB = 'border-bottom:1px solid #000;';

  // ── Title ─────────────────────────────────────────────────────────────────
  var html = '<div id="tallyPO" style="font-family:Arial,sans-serif;font-size:12px;color:#000;max-width:760px;margin:0 auto">'
    + '<div style="text-align:center;font-weight:700;font-size:15px;letter-spacing:1px;padding:4px 0 6px">PURCHASE ORDER</div>'

    // ── Header: Invoice To (left) + Voucher/Date grid (right) ─────────────
    + '<table style="width:100%;border-collapse:collapse;' + C + '">'
    + '<tr>'

    // Left: Invoice To
    + td('vertical-align:top;width:50%;padding:5px 7px;' + BR,
        hdr('Invoice To') + '<br>' + poBuildCompanyBlock())

    // Right: nested table Voucher No / Dated / Mode / Reference / Dispatched / Terms
    + '<td style="vertical-align:top;width:50%;padding:0">'
    + '<table style="width:100%;border-collapse:collapse">'
    + '<tr>'
    + td('width:60%;' + BB + BR + 'padding:3px 5px', hdr('Voucher No.'))
    + td(BB + 'padding:3px 5px', hdr('Dated'))
    + '</tr>'
    + '<tr>'
    + td(BB + BR + 'padding:4px 5px;font-weight:700', poNoEsc)
    + td(BB + 'padding:4px 5px', dated)
    + '</tr>'
    + '<tr>' + td('colspan="2" ' + BB + 'padding:3px 5px', hdr('Mode/Terms of Payment')) + '</tr>'
    + '<tr>' + td('colspan="2" ' + BB + 'padding:14px 5px 3px', '') + '</tr>'
    + '<tr>'
    + td(BB + BR + 'padding:3px 5px', hdr('Reference No. &amp; Date.'))
    + td(BB + 'padding:3px 5px', hdr('Other References'))
    + '</tr>'
    + '<tr>'
    + td(BB + BR + 'padding:4px 5px;font-weight:700', poNoEsc)
    + td(BB + 'padding:4px 5px', '')
    + '</tr>'
    + '<tr>'
    + td(BB + BR + 'padding:3px 5px', hdr('Dispatched through'))
    + td(BB + 'padding:3px 5px', hdr('Destination'))
    + '</tr>'
    + '<tr>' + td('colspan="2" ' + BB + 'padding:14px 5px 3px', '') + '</tr>'
    + '<tr>' + td('colspan="2" ' + BB + 'padding:3px 5px', hdr('Terms of Delivery')) + '</tr>'
    + '<tr>' + td('colspan="2" padding:14px 5px 3px', '') + '</tr>'
    + '</table>'
    + '</td>'
    + '</tr>'
    + '</table>'

    // ── Consignee (Ship to) ───────────────────────────────────────────────
    + '<table style="width:100%;border-collapse:collapse;' + C + ';border-top:0">'
    + '<tr>' + td('padding:5px 7px', hdr('Consignee (Ship to)') + '<br>' + poBuildCompanyBlock()) + '</tr>'
    + '</table>'

    // ── Supplier (Bill from) ──────────────────────────────────────────────
    + '<table style="width:100%;border-collapse:collapse;' + C + ';border-top:0">'
    + '<tr>' + td('padding:5px 7px', hdr('Supplier (Bill from)') + '<br>' + supplierBlock) + '</tr>'
    + '</table>';

  // ── Items table ───────────────────────────────────────────────────────────
  html += '<table style="width:100%;border-collapse:collapse;' + C + ';border-top:0">'
    + '<thead><tr style="background:#f0f0f0">'
    + th(BS + 'text-align:center;width:4%', 'Sl<br>No.')
    + th(BS + 'text-align:left', 'Description of Goods')
    + th(BS + 'text-align:center;white-space:nowrap', 'Due on')
    + th(BS + 'text-align:right', 'Quantity')
    + th(BS + 'text-align:right', 'Rate')
    + th(BS + 'text-align:center', 'per')
    + th(BS + 'text-align:right', 'Disc %')
    + th(BS + 'text-align:right', 'Amount')
    + '</tr></thead><tbody>';

  var totalAmt = 0;
  for (var i = 0; i < allItems.length; i++) {
    var ri = poNormalizeRow(allItems[i]);
    var itemName = ri.item_name || '';
    var qty  = ri.quantity || '';
    var rate = ri.rate || '';
    var amt  = ri.amount || '';
    var amtNum = parseFloat(String(amt).replace(/[^0-9.]/g,'')) || 0;
    totalAmt += amtNum;
    html += '<tr>'
      + td(BS + 'text-align:center', (i+1))
      + td(BS + 'font-weight:700', poEsc(itemName))
      + td(BS + 'text-align:center', dated)
      + td(BS + 'text-align:right', poEsc(qty))
      + td(BS + 'text-align:right', poEsc(rate))
      + td(BS + 'text-align:center', '')
      + td(BS + 'text-align:right', '')
      + td(BS + 'text-align:right', amtNum > 0 ? amtNum.toFixed(2) : '')
      + '</tr>';
  }

  // blank spacer rows
  var blankRow = '<tr style="height:22px">'
    + td(BS,'') + td(BS,'') + td(BS,'') + td(BS,'') + td(BS,'') + td(BS,'') + td(BS,'') + td(BS,'')
    + '</tr>';
  html += blankRow + blankRow;

  // Total row
  html += '<tr style="background:#f5f5f5">'
    + td(BS + 'text-align:right;font-weight:700;' , '')
    + td('colspan="6" ' + BS + 'text-align:right;font-weight:700', 'Total')
    + td(BS + 'text-align:right;font-weight:700', totalAmt > 0 ? totalAmt.toFixed(2) : '')
    + '</tr>';

  html += '</tbody></table>';

  // E. & O.E
  html += '<div style="text-align:right;font-size:11px;padding:2px 4px;border-left:1px solid #000;border-right:1px solid #000">E. &amp; O.E</div>';

  // Blank area for notes + PAN + Signature
  html += '<table style="width:100%;border-collapse:collapse;' + C + ';border-top:0">'
    + '<tr style="height:90px"><td style="padding:5px 7px;vertical-align:bottom;font-size:11px">'
    + (pan ? "Company's PAN &nbsp;&nbsp;&nbsp; : &nbsp;<strong>" + poEsc(pan) + '</strong>' : '')
    + '</td>'
    + '<td style="padding:5px 7px;vertical-align:bottom;text-align:right;font-size:11px;width:40%;border-left:1px solid #000">'
    + 'for ' + poEsc(PO_COMPANY_NAME) + '<br><br><br>Authorised Signatory'
    + '</td></tr>'
    + '</table>';

  // Footer
  html += '<div style="text-align:center;font-size:11px;padding:6px 0 2px">This is a Computer Generated Document</div>';

  html += '</div>';

  document.getElementById('posPrintArea').innerHTML = html;
  document.getElementById('poPrintModal').style.display = 'block';
}

// ── auto-load on page ready ───────────────────────────────────────────────────
(function() {
  // 1. Quick badge check via dedicated endpoint
  fetch(PO_CHECK_URL, { credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      var conn = !!(d && d.status === 'connected');
      poSetBadge(conn);
      if (conn) {
        // 2. Auto-fetch PO if connected
        poSync(false);
      } else {
        poShowMsg('Tally not connected — showing empty list. Click "Sync" to retry.', '#92400e', '#fffbeb');
        document.getElementById('poTbody').innerHTML =
          '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected. Click Sync PO from Tally to retry.</td></tr>';
      }
    })
    .catch(function() {
      poSetBadge(false);
      poShowMsg('Tally not connected — showing empty list.', '#92400e', '#fffbeb');
      document.getElementById('poTbody').innerHTML =
        '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected.</td></tr>';
    });
})();

// ── PO bulk select / delete / clear ──────────────────────────────────────────
var PO_CLEAR_URL = '<?= e(BASE_URL) ?>/modules/tally/clear_cache.php';

function poToggleAll(chk) {
  var boxes = document.querySelectorAll('#poTbody .po-row-chk');
  for (var i = 0; i < boxes.length; i++) boxes[i].checked = chk.checked;
  poOnCheckChange();
}

function poOnCheckChange() {
  var checked = document.querySelectorAll('#poTbody .po-row-chk:checked');
  var bar = document.getElementById('poDeleteBar');
  var cnt = document.getElementById('poSelCount');
  var all = document.getElementById('poSelectAll');
  if (bar) bar.style.display = checked.length > 0 ? '' : 'none';
  if (cnt) cnt.textContent = checked.length;
  // Update header checkbox state
  var total = document.querySelectorAll('#poTbody .po-row-chk');
  if (all) {
    all.indeterminate = checked.length > 0 && checked.length < total.length;
    all.checked = total.length > 0 && checked.length === total.length;
  }
}

function poUnselectAll() {
  var boxes = document.querySelectorAll('#poTbody .po-row-chk');
  for (var i = 0; i < boxes.length; i++) boxes[i].checked = false;
  var all = document.getElementById('poSelectAll');
  if (all) { all.checked = false; all.indeterminate = false; }
  poOnCheckChange();
}

function poDeleteSelected() {
  var checked = document.querySelectorAll('#poTbody .po-row-chk:checked');
  if (checked.length === 0) { alert('Please select at least one row.'); return; }
  if (!confirm('Delete ' + checked.length + ' selected PO row(s) from cache? They will re-appear on next Tally sync.')) return;
  var keys = [];
  for (var i = 0; i < checked.length; i++) keys.push(checked[i].getAttribute('data-key'));
  fetch(PO_CLEAR_URL, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type: 'po', keys: keys })
  }).then(function(r) { return r.json(); }).then(function(d) {
    if (d && d.ok) {
      // Remove matching rows from in-memory array
      var keySet = {}; keys.forEach(function(k) { keySet[k] = 1; });
      _poRows = _poRows.filter(function(r) { return !keySet[r.po_number || '']; });
      _poFiltered = _poFiltered.filter(function(r) { return !keySet[r.po_number || '']; });
      poUnselectAll();
      poRenderSummary(_poFiltered);
      poRender(_poFiltered);
      poShowMsg(keys.length + ' row(s) removed from cache.', '#166534', '#f0fdf4');
    }
  }).catch(function() { alert('Error removing rows.'); });
}

function poClearAll() {
  if (!confirm('Clear ALL PO cache? This will remove all PO data until next Tally sync.')) return;
  fetch(PO_CLEAR_URL, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type: 'po' })
  }).then(function(r) { return r.json(); }).then(function(d) {
    if (d && d.ok) {
      _poRows = []; _poFiltered = [];
      var hint = document.getElementById('poTallyHint');
      if (hint) hint.style.display = 'none';
      poRender([]);
      poRenderSummary([]);
      poUnselectAll();
      var tbody = document.getElementById('poTbody');
      if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:28px">Cache cleared. Click <strong>Sync PO from Tally</strong> to reload.</td></tr>';
      poShowMsg('PO cache cleared. Click Sync to reload from Tally.', '#92400e', '#fffbeb');
    }
  }).catch(function() { alert('Error clearing cache.'); });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

