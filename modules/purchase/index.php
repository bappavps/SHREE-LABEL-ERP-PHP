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
    </div>
  </div>

  <div id="poMsg" style="display:none;margin:0 16px 4px;padding:8px 12px;border-radius:8px;font-size:.83rem"></div>

  <div class="po-toolbar">
    <input id="poSearch" class="po-search" type="search" placeholder="Search PO, Supplier, Item..." oninput="poFilterRows()">
    <span id="poCount" style="font-size:.8rem;color:#64748b"></span>
  </div>

  <div class="table-responsive">
    <table class="table" id="poTable">
      <thead>
        <tr>
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
        <tr id="poPlaceholder"><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">
          <i class="bi bi-arrow-repeat" style="font-size:1.2rem;opacity:.4"></i>
          <div style="margin-top:6px;font-size:.84rem">Checking Tally connection…</div>
        </td></tr>
      </tbody>
    </table>
  </div>
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
const PO_MAX          = 100;

var _poRows = [];
var _poFiltered = [];

function poNormalizeRow(r) {
  var row = r || {};
  row.party_name = row.party_name || row.supplier_name || row.client_name || '';
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
  if (!tbody) return;

  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">No purchase orders found in Tally.</td></tr>';
    document.getElementById('poCount').textContent = '';
    return;
  }

  var capped = false;
  var total  = rows.length;
  if (total > PO_MAX) { rows = rows.slice(0, PO_MAX); capped = true; }

  var html = '';
  for (var i = 0; i < rows.length; i++) {
    var r = poNormalizeRow(rows[i]);
    html += '<tr>';
    html += '<td>' + poEsc(r.po_number || '-') + '</td>';
    html += '<td>' + poEsc(poFmtDate(r.date)) + '</td>';
    html += '<td>' + poEsc(r.party_name || '-') + '</td>';
    html += '<td>' + poEsc(r.item_name   || '-') + '</td>';
    html += '<td>' + poEsc(r.quantity    || '-') + '</td>';
    html += '<td>' + poEsc(r.rate        || '-') + '</td>';
    html += '<td>' + poEsc(r.amount      || '-') + '</td>';
    html += '<td style="white-space:nowrap">'
          + '<button class="btn btn-xs btn-info" onclick="poView(' + i + ')"><i class="bi bi-eye"></i> View</button> '
          + '<button class="btn btn-xs" style="background:#0ea5e9;color:#fff" onclick="poPrint(' + i + ')"><i class="bi bi-printer"></i> Print</button>'
          + '</td>';
    html += '</tr>';
  }

  if (capped) {
    html += '<tr><td colspan="8" style="text-align:center;background:#fffbeb;color:#92400e;font-size:.8rem;padding:8px">'
          + 'Showing first ' + PO_MAX + ' of ' + total + ' records.'
          + '</td></tr>';
  }

  tbody.innerHTML = html;
  document.getElementById('poCount').textContent = rows.length + (capped ? ' of ' + total : '') + ' records';
}

// ── search filter ─────────────────────────────────────────────────────────────
function poFilterRows() {
  var q = (document.getElementById('poSearch').value || '').toLowerCase().trim();
  if (!q) { _poFiltered = _poRows.slice(); }
  else {
    _poFiltered = _poRows.filter(function(r) {
      var row = poNormalizeRow(r);
      return (r.po_number     || '').toLowerCase().indexOf(q) >= 0
          || (row.party_name  || '').toLowerCase().indexOf(q) >= 0
          || (r.item_name     || '').toLowerCase().indexOf(q) >= 0;
    });
  }
  poRender(_poFiltered);
}

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
          '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected. Manual data only.</td></tr>';
        return;
      }
      poSetBadge(true);
      poHideMsg();
      _poRows     = (d.data || []).map(poNormalizeRow);
      _poFiltered = _poRows.slice();
      poRender(_poFiltered);
    })
    .catch(function() {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sync PO from Tally'; }
      poSetBadge(false);
      poShowMsg('Tally not connected or unreachable.', '#b91c1c', '#fff5f5');
      document.getElementById('poTbody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected.</td></tr>';
    });
}

// ── view (inline expand in future; currently same as print preview) ───────────
function poView(idx) {
  poPrint(idx);
}

// ── print ─────────────────────────────────────────────────────────────────────
function poPrint(idx) {
  var r = poNormalizeRow(_poFiltered[idx]);
  if (!r) return;

  var supplierName = poEsc(r.party_name || '-');
  var poNo   = poEsc(r.po_number  || 'N/A');
  var date   = poEsc(poFmtDate(r.date));
  var item   = poEsc(r.item_name  || '-');
  var qty    = poEsc(String(r.quantity || '-'));
  var rate   = poEsc(String(r.rate     || '-'));
  var amount = poEsc(String(r.amount   || '-'));

  var html = '<div style="max-width:680px;margin:0 auto">'
    + '<div style="text-align:center;margin-bottom:18px;border-bottom:2px solid #0f172a;padding-bottom:12px">'
    + '<h2 style="margin:0;font-size:1.3rem;color:#0f172a">' + poEsc(PO_COMPANY_NAME) + '</h2>'
    + '<p style="margin:2px 0;font-size:.82rem;color:#475569">Purchase Order</p>'
    + '</div>'

    + '<table style="width:100%;margin-bottom:14px;font-size:.85rem">'
    + '<tr>'
    + '<td style="width:50%;vertical-align:top"><strong>Supplier Name:</strong><br>' + supplierName + '</td>'
    + '<td style="width:50%;text-align:right;vertical-align:top"><strong>PO Number:</strong> ' + poNo + '<br><strong>Date:</strong> ' + date + '</td>'
    + '</tr>'
    + '</table>'

    + '<table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:18px">'
    + '<thead>'
    + '<tr style="background:#0f172a;color:#fff">'
    + '<th style="padding:7px 10px;text-align:left;border:1px solid #334155">Item</th>'
    + '<th style="padding:7px 10px;text-align:right;border:1px solid #334155">Qty</th>'
    + '<th style="padding:7px 10px;text-align:right;border:1px solid #334155">Rate</th>'
    + '<th style="padding:7px 10px;text-align:right;border:1px solid #334155">Amount</th>'
    + '</tr>'
    + '</thead>'
    + '<tbody>'
    + '<tr>'
    + '<td style="padding:7px 10px;border:1px solid #cbd5e1">' + item + '</td>'
    + '<td style="padding:7px 10px;border:1px solid #cbd5e1;text-align:right">' + qty + '</td>'
    + '<td style="padding:7px 10px;border:1px solid #cbd5e1;text-align:right">' + rate + '</td>'
    + '<td style="padding:7px 10px;border:1px solid #cbd5e1;text-align:right">' + amount + '</td>'
    + '</tr>'
    + '</tbody>'
    + '<tfoot>'
    + '<tr style="background:#f8fafc">'
    + '<td colspan="3" style="padding:7px 10px;border:1px solid #cbd5e1;text-align:right;font-weight:700">Total</td>'
    + '<td style="padding:7px 10px;border:1px solid #cbd5e1;text-align:right;font-weight:700">' + amount + '</td>'
    + '</tr>'
    + '</tfoot>'
    + '</table>'

    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:30px;font-size:.82rem">'
    + '<div style="border-top:1px solid #0f172a;padding-top:6px;text-align:center;color:#475569">Authorised Signature</div>'
    + '<div style="border-top:1px solid #0f172a;padding-top:6px;text-align:center;color:#475569">Received By</div>'
    + '</div>'

    + '<div style="margin-top:18px;text-align:center;font-size:.75rem;color:#94a3b8">'
    + 'Fetched from Tally &mdash; Read Only &mdash; Generated by ' + poEsc(PO_COMPANY_NAME)
    + '</div>'
    + '</div>';

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
          '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected. Click Sync PO from Tally to retry.</td></tr>';
      }
    })
    .catch(function() {
      poSetBadge(false);
      poShowMsg('Tally not connected — showing empty list.', '#92400e', '#fffbeb');
      document.getElementById('poTbody').innerHTML =
        '<tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:28px">Tally not connected.</td></tr>';
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

