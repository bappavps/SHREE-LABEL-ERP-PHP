<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/../../packing/_data.php';

$db = getDB();
packing_ensure_operator_entries_table($db);

$pageTitle = 'Packing Operator Station';
$canAdminOverrideEdit = (function_exists('isAdmin') && isAdmin())
  || (function_exists('hasRole') && hasRole('system_admin', 'super_admin'));

$allowedTabs   = packing_tab_keys();
$currentTab    = trim((string)($_GET['tab'] ?? 'printing_label'));
if (!in_array($currentTab, $allowedTabs, true)) $currentTab = 'printing_label';

$search = trim((string)($_GET['q']      ?? ''));
$from   = trim((string)($_GET['from']   ?? ''));
$to     = trim((string)($_GET['to']     ?? ''));
$period = trim((string)($_GET['period'] ?? 'custom'));

$allowedPeriods = ['custom', 'day', 'week', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) $period = 'custom';

if ($period !== 'custom') {
    $today = new DateTimeImmutable('today');
    if ($period === 'day') {
        $from = $today->format('Y-m-d'); $to = $today->format('Y-m-d');
    } elseif ($period === 'week') {
        $from = $today->modify('monday this week')->format('Y-m-d');
        $to   = $today->modify('sunday this week')->format('Y-m-d');
    } elseif ($period === 'month') {
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $to   = $today->modify('last day of this month')->format('Y-m-d');
    } elseif ($period === 'year') {
        $from = $today->setDate((int)$today->format('Y'), 1, 1)->format('Y-m-d');
        $to   = $today->setDate((int)$today->format('Y'), 12, 31)->format('Y-m-d');
    }
}

$data      = packing_fetch_ready_rows($db, ['search' => $search, 'from' => $from, 'to' => $to]);
$rowsByTab = $data['rows_by_tab'];
$counts    = $data['counts'];

$formatDate = static function(string $value): string {
    $value = trim($value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y', $ts) : '-';
};

$displayPackingStatus = static function(string $status): string {
  return strtolower(trim($status)) === 'packing done' ? 'Packing Done' : 'Packing';
};

$displayPackingStatusClass = static function(string $status): string {
  return strtolower(trim($status)) === 'packing done' ? 'ok' : 'muted';
};

$tabThemes = [
    'printing_label' => ['accent' => '#c2410c', 'soft' => '#fff7ed', 'border' => '#fed7aa'],
    'pos_roll'        => ['accent' => '#b45309', 'soft' => '#fef3c7', 'border' => '#fde68a'],
    'one_ply'         => ['accent' => '#92400e', 'soft' => '#ffedd5', 'border' => '#fdba74'],
    'two_ply'         => ['accent' => '#d97706', 'soft' => '#fffbeb', 'border' => '#fcd34d'],
    'barcode'         => ['accent' => '#dc2626', 'soft' => '#fef2f2', 'border' => '#fca5a5'],
];

include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Operators</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Packing Operator Station</span>
</div>

<style>
/* ── Operator Packing Page — Orange/Amber Theme ── */
.op-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.op-head h1{margin:0;font-size:1.45rem;font-weight:900;color:#fff}
.op-head p{margin:5px 0 0;color:#fed7aa}
.op-btn{border:1px solid #cbd5e1;background:#fff;color:#1f2937;border-radius:10px;padding:8px 12px;font-size:.78rem;font-weight:800;cursor:pointer;transition:all .18s ease}
.op-btn:hover{transform:translateY(-1px)}
.op-card{background:linear-gradient(180deg,#ffffff 0%,#fffbf5 100%);border:1px solid #fed7aa;border-radius:14px;padding:14px;margin-top:14px;box-shadow:0 12px 30px rgba(194,65,12,.07)}
.op-filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:end}
.op-filters label{display:block;font-size:.63rem;text-transform:uppercase;letter-spacing:.06em;color:#92400e;font-weight:800;margin-bottom:4px}
.op-filters input{height:38px;border:1px solid #fed7aa;border-radius:9px;padding:0 10px;font-size:.82rem}
.op-filters .op-btn{height:38px;background:#c2410c;color:#fff;border-color:#c2410c}
.op-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.op-tab{padding:8px 12px;border-radius:10px;border:1px solid var(--op-border,#fed7aa);background:var(--op-soft,#fff7ed);color:var(--op-accent,#c2410c);font-size:.74rem;font-weight:800;cursor:pointer;transition:all .2s ease;box-shadow:0 2px 8px rgba(194,65,12,.06)}
.op-tab:hover{transform:translateY(-1px)}
.op-tab.active{background:var(--op-accent,#c2410c);color:#fff;border-color:var(--op-accent,#c2410c);box-shadow:0 10px 20px rgba(194,65,12,.2)}
.op-pane{display:none;margin-top:12px;--op-accent:#c2410c;--op-soft:#fff7ed;--op-border:#fed7aa}
.op-pane.active{display:block}
.op-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 8px;padding:10px;border:1px solid var(--op-border);background:linear-gradient(135deg,var(--op-soft) 0%,#ffffff 100%);border-radius:10px}
.op-bar .count{font-size:.78rem;font-weight:800;color:var(--op-accent)}
.op-table-wrap{overflow:auto;border:1px solid var(--op-border);border-radius:12px}
.op-table{width:100%;border-collapse:collapse;min-width:780px}
.op-table th{background:var(--op-soft);color:#78350f;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;padding:10px;border-bottom:1px solid var(--op-border);text-align:left}
.op-table td{padding:9px;border-bottom:1px solid #fef3c7;font-size:.77rem;color:#0f172a;font-weight:600}
.op-table tr:nth-child(even) td{background:#fffbf5}
.op-badge{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:.62rem;font-weight:800}
.op-badge.ok{background:#dcfce7;color:#166534}
.op-badge.muted{background:#e2e8f0;color:#475569}
.op-badge.submitted{background:#fed7aa;color:#92400e}
.op-empty{padding:30px;text-align:center;color:#94a3b8}
.op-id-btn{border:1px solid var(--op-border);background:var(--op-soft);color:var(--op-accent);padding:5px 9px;border-radius:999px;font-size:.7rem;font-weight:800;cursor:pointer}
.op-id-btn:hover{filter:brightness(.96)}
/* Modal */
.op-modal{position:fixed;inset:0;z-index:1200;display:none}
.op-modal.show{display:block}
.op-modal-backdrop{position:absolute;inset:0;background:rgba(120,53,15,.45);backdrop-filter:blur(2px)}
.op-modal-card{position:relative;margin:3vh auto 0;width:min(860px,95vw);max-height:93vh;overflow:auto;background:#fff;border-radius:16px;border:1px solid #fed7aa;box-shadow:0 24px 48px rgba(120,53,15,.18)}
.op-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:14px 16px;background:linear-gradient(120deg,#fff7ed 0%,#fff 90%);border-bottom:1px solid #fed7aa}
.op-modal-title{margin:0;font-size:1.02rem;font-weight:900;color:#c2410c}
.op-modal-sub{margin:3px 0 0;font-size:.75rem;color:#78350f;font-weight:700}
.op-modal-body{padding:14px 16px 16px}
/* Section header */
.op-section{padding:10px 12px;border-top:1px solid #fef3c7}
.op-section h5{margin:0 0 8px;font-size:.74rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#c2410c;display:flex;align-items:center;gap:7px;cursor:pointer;user-select:none}
.op-section-content{padding-top:4px}
.op-section-content.collapsed{display:none}
/* Top summary cards */
.op-top-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;padding:12px 12px 4px;background:linear-gradient(120deg,#fff7ed 0%,#fff 100%)}
.op-top-item{border:1px solid #fed7aa;border-radius:10px;padding:8px;background:#fff}
.op-top-item b{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;color:#92400e;margin-bottom:2px}
.op-top-item span{display:block;font-size:1rem;font-weight:900;color:#0f172a;line-height:1.2}
.op-top-item.dispatch span{color:#b91c1c}
/* Detail grid A */
.op-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.op-detail-item{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:#fffbf5}
.op-detail-item b{display:block;font-size:.61rem;text-transform:uppercase;letter-spacing:.06em;color:#78350f;margin-bottom:3px}
.op-detail-item span{display:block;font-size:.8rem;color:#0f172a;font-weight:800;word-break:break-word}
/* Roll table */
.op-rolls{width:100%;border-collapse:collapse;margin-top:6px}
.op-rolls th,.op-rolls td{border:1px solid #fef3c7;padding:6px 7px;font-size:.73rem;text-align:left}
.op-rolls th{background:#fff7ed;color:#78350f;font-weight:900}
/* Section B operator entry */
.op-entry-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px 16px;align-items:end;padding:12px 0}
.op-entry-form .op-field label{display:block;font-size:.8em;font-weight:700;color:#92400e;margin-bottom:4px}
.op-entry-form .op-field input,.op-entry-form .op-field textarea{width:100%;padding:7px 9px;border-radius:7px;border:1px solid #fed7aa;font-size:.9em;background:#fffbf5;box-sizing:border-box}
.op-entry-form .op-field input:focus,.op-entry-form .op-field textarea:focus{outline:none;border-color:#c2410c;box-shadow:0 0 0 2px rgba(194,65,12,.12)}
.op-entry-form .op-field textarea{resize:vertical;min-height:64px}
.op-entry-form .op-field .op-ro-val{font-size:1.05em;font-weight:900;color:#0f172a;padding:7px 0}
.op-submit-row{display:flex;gap:8px;flex-wrap:wrap;padding:10px 0 0;border-top:1px solid #fef3c7}
.op-btn-submit{background:#c2410c;color:#fff;border:none;padding:9px 22px;border-radius:7px;font-weight:800;font-size:.95em;cursor:pointer;transition:all .2s ease}
.op-btn-submit:hover{background:#9a3412;transform:translateY(-1px)}
.op-btn-submit:disabled{background:#9ca3af;cursor:not-allowed;opacity:.7}
.op-btn-print{background:#2563eb;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:800;font-size:.95em;cursor:pointer;transition:all .2s ease}
.op-btn-print:hover{background:#1d4ed8;transform:translateY(-1px)}
.op-btn-cancel-modal{background:#6b7280;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:700;font-size:.95em;cursor:pointer}
.op-submitted-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;margin-bottom:12px}
.op-submitted-box .op-sb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-top:8px}
.op-submitted-box .op-sb-item b{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#92400e;margin-bottom:2px}
.op-submitted-box .op-sb-item span{font-size:.85rem;color:#0f172a;font-weight:800}
.op-msg{min-height:18px;font-size:.82rem;font-weight:700;color:#78350f;padding-top:4px}
/* Tab themes */
.op-tab[data-theme="printing_label"],.op-pane[data-theme="printing_label"]{--op-accent:#c2410c;--op-soft:#fff7ed;--op-border:#fed7aa}
.op-tab[data-theme="pos_roll"],.op-pane[data-theme="pos_roll"]{--op-accent:#b45309;--op-soft:#fef3c7;--op-border:#fde68a}
.op-tab[data-theme="one_ply"],.op-pane[data-theme="one_ply"]{--op-accent:#92400e;--op-soft:#ffedd5;--op-border:#fdba74}
.op-tab[data-theme="two_ply"],.op-pane[data-theme="two_ply"]{--op-accent:#d97706;--op-soft:#fffbeb;--op-border:#fcd34d}
.op-tab[data-theme="barcode"],.op-pane[data-theme="barcode"]{--op-accent:#dc2626;--op-soft:#fef2f2;--op-border:#fca5a5}
.page-header{margin-top:12px;background:linear-gradient(120deg,#7c2d12 0%,#c2410c 48%,#ea580c 100%);border-radius:14px;padding:14px;border:1px solid #9a3412;box-shadow:0 14px 30px rgba(124,45,18,.24)}
@media(max-width:980px){.op-filters{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.op-filters{grid-template-columns:1fr}.op-table{min-width:660px}.op-modal-card{margin:0;border-radius:0;max-height:100vh}.op-detail-grid{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <div class="op-head">
    <div>
      <h1><i class="bi bi-box-seam-fill" style="margin-right:8px"></i>Packing Operator Station</h1>
      <p>Select a job, fill packed quantities and submit to the manager.</p>
    </div>
  </div>
</div>

<div class="op-card">
  <form class="op-filters" method="get">
    <div>
      <label>Search</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Plan no, job no, roll no, job name">
    </div>
    <div>
      <label>Filter Type</label>
      <select name="period" style="height:38px;border:1px solid #fed7aa;border-radius:9px;padding:0 10px;font-size:.82rem;width:100%;background:#fff;">
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Date</option>
        <option value="day"    <?= $period === 'day'    ? 'selected' : '' ?>>Today</option>
        <option value="week"   <?= $period === 'week'   ? 'selected' : '' ?>>This Week</option>
        <option value="month"  <?= $period === 'month'  ? 'selected' : '' ?>>This Month</option>
        <option value="year"   <?= $period === 'year'   ? 'selected' : '' ?>>This Year</option>
      </select>
    </div>
    <div>
      <label>From</label>
      <input type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div>
      <label>To</label>
      <input type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div>
      <button class="op-btn" type="submit">Filter</button>
    </div>
    <?php foreach (['tab' => $currentTab] as $k => $v): ?>
    <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
    <?php endforeach; ?>
  </form>

  <!-- Tabs -->
  <div class="op-tabs">
    <?php foreach ($allowedTabs as $tabKey):
      $theme = $tabThemes[$tabKey] ?? $tabThemes['printing_label'];
      $label = packing_tab_label($tabKey);
      $cnt   = $counts[$tabKey] ?? 0;
    ?>
    <button class="op-tab<?= $tabKey === $currentTab ? ' active' : '' ?>"
            data-tab="<?= e($tabKey) ?>" data-theme="<?= e($tabKey) ?>"
            style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
      <?= e($label) ?> <span style="opacity:.7;font-weight:700">(<?= (int)$cnt ?>)</span>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- Panes -->
  <?php foreach ($allowedTabs as $tabKey):
    $theme = $tabThemes[$tabKey] ?? $tabThemes['printing_label'];
    $rows  = $rowsByTab[$tabKey] ?? [];
    $label = packing_tab_label($tabKey);
  ?>
  <div class="op-pane<?= $tabKey === $currentTab ? ' active' : '' ?>"
       data-pane="<?= e($tabKey) ?>" data-theme="<?= e($tabKey) ?>"
       style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
    <div class="op-bar">
      <span class="count"><?= count($rows) ?> job(s) — <?= e($label) ?></span>
    </div>
    <div class="op-table-wrap">
      <table class="op-table">
        <thead>
          <tr>
            <th>Packing ID</th>
            <th>Plan No</th>
            <th>Job Name</th>
            <th>Client</th>
            <th>Dispatch</th>
            <th>Status</th>
            <th>Operator Entry</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="8" class="op-empty">No jobs found for this category.</td></tr>
          <?php else: ?>
          <?php foreach ($rows as $r):
            $opEntry = packing_fetch_operator_entry($db, (string)($r['job_no'] ?? ''));
            $hasEntry = !empty($opEntry);
          ?>
          <tr data-row-id="<?= (int)($r['id'] ?? 0) ?>">
            <td><button class="op-id-btn op-open-btn"
                        data-job-id="<?= (int)($r['id'] ?? 0) ?>"
                        style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
              <?= e($r['packing_display_id'] ?? ('-')) ?>
            </button></td>
            <td><?= e($r['plan_no'] ?? '-') ?></td>
            <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['plan_name'] ?? '-') ?></td>
            <td><?= e($r['client_name'] ?? '-') ?></td>
            <td><?= e($formatDate((string)($r['dispatch_date'] ?? ''))) ?></td>
            <td><span class="op-badge <?= e($displayPackingStatusClass((string)($r['status'] ?? ''))) ?>"><?= e($displayPackingStatus((string)($r['status'] ?? ''))) ?></span></td>
            <td><?php if ($hasEntry): ?>
              <span class="op-badge submitted">Submitted</span>
            <?php else: ?>
              <span class="op-badge muted">Pending</span>
            <?php endif; ?></td>
            <td><button class="op-id-btn op-open-btn"
                        data-job-id="<?= (int)($r['id'] ?? 0) ?>"
                        style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
              Open
            </button></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ═══ Operator Job Modal ═══ -->
<div class="op-modal" id="opJobModal" aria-hidden="true" role="dialog">
  <div class="op-modal-backdrop" id="opModalBackdrop"></div>
  <div class="op-modal-card">
    <div class="op-modal-head" id="opModalHead">
      <div>
        <h3 class="op-modal-title" id="opModalTitle">Packing Job</h3>
        <p class="op-modal-sub" id="opModalSub"></p>
      </div>
      <button id="opCloseModalBtn" style="background:none;border:none;font-size:1.4rem;line-height:1;cursor:pointer;color:#78350f;padding:4px 8px">&#10005;</button>
    </div>
    <div class="op-modal-body" id="opModalBody">
      <div style="padding:30px;text-align:center;color:#92400e;font-weight:700">Loading...</div>
    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
(function() {
  var baseUrl = '<?= e(BASE_URL) ?>';
  var currentOperatorName = '<?= e(trim((string)($_SESSION['user_name'] ?? 'Operator')) ?: 'Operator') ?>';
  var opCanAdminOverrideEdit = <?= $canAdminOverrideEdit ? 'true' : 'false' ?>;
  var activeJobId = 0;
  var activeJobNo = '';
  var activeJobData = null;

  // ── Tab switching ──
  var tabs  = document.querySelectorAll('.op-tab');
  var panes = document.querySelectorAll('.op-pane');
  var tabInput; // no hidden input needed — form submission handles it

  tabs.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var t = btn.getAttribute('data-tab');
      tabs.forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-tab') === t); });
      panes.forEach(function(p) { p.classList.toggle('active', p.getAttribute('data-pane') === t); });
      // Update URL without reload for UX
      var url = new URL(window.location.href);
      url.searchParams.set('tab', t);
      history.replaceState(null, '', url.toString());
    });
  });

  // ── Modal helpers ──
  var modal     = document.getElementById('opJobModal');
  var modalHead = document.getElementById('opModalHead');
  var modalTitle= document.getElementById('opModalTitle');
  var modalSub  = document.getElementById('opModalSub');
  var modalBody = document.getElementById('opModalBody');

  function openModal()  { modal.classList.add('show');    modal.setAttribute('aria-hidden','false'); }
  function closeModal() { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true');  activeJobId = 0; activeJobNo = ''; activeJobData = null; }
  function loadingHtml() { return '<div style="padding:30px;text-align:center;color:#92400e;font-weight:700">Loading job details...</div>'; }

  document.getElementById('opCloseModalBtn').addEventListener('click', closeModal);
  document.getElementById('opModalBackdrop').addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

  function escHtml(v) {
    return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function pickFirst(sources, keys) {
    for (var s=0;s<sources.length;s++) {
      var src=sources[s]||{};
      for (var i=0;i<keys.length;i++) {
        var v=src[keys[i]];
        if (v!==null&&typeof v!=='undefined'&&String(v).trim()!=='') return String(v);
      }
    }
    return '-';
  }

  function pickFirstLoose(sources, keys) {
    var lk=keys.map(function(k){return String(k).toLowerCase();});
    for (var s=0;s<sources.length;s++) {
      var src=sources[s]||{};
      for (var i=0;i<keys.length;i++) {
        var v=src[keys[i]];
        if (v!==null&&typeof v!=='undefined'&&String(v).trim()!=='') return String(v);
      }
      var map={};
      Object.keys(src).forEach(function(k){map[k.toLowerCase()]=src[k];});
      for (var i=0;i<lk.length;i++) {
        if (!Object.prototype.hasOwnProperty.call(map,lk[i])) continue;
        var v=map[lk[i]];
        if (v!==null&&typeof v!=='undefined'&&String(v).trim()!=='') return String(v);
      }
    }
    return '-';
  }

  function buildSlipQrDataUrl(jobNo) {
    var cleanJobNo = String(jobNo || '').trim();
    if (!cleanJobNo || typeof QRCode !== 'function') return '';
    var text = baseUrl + '/modules/scan/job.php?jn=' + encodeURIComponent(cleanJobNo);
    var holder = document.createElement('div');
    holder.style.position = 'fixed';
    holder.style.left = '-9999px';
    holder.style.top = '-9999px';
    document.body.appendChild(holder);
    try {
      new QRCode(holder, { text: text, width: 112, height: 112, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
      var img = holder.querySelector('img');
      if (img && img.src) return img.src;
      var canvas = holder.querySelector('canvas');
      if (canvas && typeof canvas.toDataURL === 'function') return canvas.toDataURL('image/png');
      return '';
    } catch (e) {
      return '';
    } finally {
      holder.remove();
    }
  }

  // ── Render modal from job data ──
  function renderModal(job) {
    var planExtra = (job.plan_extra_data&&typeof job.plan_extra_data==='object')?job.plan_extra_data:{};
    var jobExtra  = (job.job_extra_data &&typeof job.job_extra_data ==='object')?job.job_extra_data :{};
    var sources   = [job, planExtra, jobExtra];

    var orderQtyRaw = pickFirstLoose(sources, ['order_quantity','order_qty','quantity','qty_pcs']);
    var prodQtyRaw  = pickFirstLoose(sources, ['production_quantity','production_qty','produced_quantity','actual_qty','output_qty','completed_qty']);
    var orderQty = parseFloat(String(orderQtyRaw).replace(/,/g,'')) || 0;
    var prodQty  = parseFloat(String(prodQtyRaw).replace(/,/g,''))  || 0;

    var opEntry = (job.operator_entry && typeof job.operator_entry==='object') ? job.operator_entry : null;
    var lockSubmittedForOperator = !!opEntry && !opCanAdminOverrideEdit;

    // Build section A detail items
    var detailKeys = [
      ['Packing ID',     [job.packing_display_id||'-']],
      ['Plan No',        pickFirst(sources,['plan_no','plan_number'])],
      ['Job No',         pickFirst(sources,['job_no'])],
      ['Job Name',       pickFirst(sources,['plan_name','job_name'])],
      ['Client Name',    pickFirst(sources,['client_name','customer_name'])],
      ['Order Date',     pickFirst(sources,['order_date'])],
      ['Dispatch Date',  pickFirst(sources,['dispatch_date','due_date'])],
      ['Roll No',        pickFirst(sources,['roll_no'])],
      ['Department',     pickFirst(sources,['department'])],
      ['Status',         pickFirst(sources,['status'])],
      ['Order Quantity', orderQtyRaw],
      ['Production Qty', prodQtyRaw],
    ];

    var detailHtml = '<div class="op-detail-grid">' +
      detailKeys.map(function(d){
        var label = Array.isArray(d[0]) ? d[0][0] : d[0];
        var val   = Array.isArray(d[1]) ? d[1][0]  : d[1];
        return '<div class="op-detail-item"><b>'+escHtml(label)+'</b><span>'+escHtml(String(val||'-'))+'</span></div>';
      }).join('') + '</div>';

    // Build submitted box (if entry exists)
    var submittedBoxHtml = '';
    if (opEntry) {
      submittedBoxHtml =
        '<div class="op-submitted-box">' +
        '<div style="font-size:.78rem;font-weight:900;color:#92400e;display:flex;align-items:center;gap:6px">' +
        '<span style="background:#fed7aa;color:#92400e;padding:2px 8px;border-radius:999px;font-size:.72rem">&#10003; Already Submitted</span>' +
        ' <span style="color:#64748b;font-weight:700">' + escHtml(opEntry.submitted_at||'') + '</span>' +
        '</div>' +
        '<div class="op-sb-grid">' +
        '<div class="op-sb-item"><b>Packed Qty</b><span>'+escHtml(String(opEntry.packed_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Bundles</b><span>'+escHtml(String(opEntry.bundles_count||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Cartons</b><span>'+escHtml(String(opEntry.cartons_count||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Wastage</b><span>'+escHtml(String(opEntry.wastage_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Loose Qty</b><span>'+escHtml(String(opEntry.loose_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Notes</b><span>'+escHtml(opEntry.notes||'-')+'</span></div>' +
        '</div>' +
        (lockSubmittedForOperator ? '<div style="margin-top:8px;font-size:.76rem;font-weight:800;color:#b91c1c">Locked: already submitted. Only admin can edit or delete this entry.</div>' : '') +
        '</div>';
    }

    var html =
      // Top summary
      '<div class="op-top-grid">' +
        '<div class="op-top-item"><b>Packing ID</b><span>' + escHtml(job.packing_display_id||'-') + '</span></div>' +
        '<div class="op-top-item"><b>Job Name</b><span>' + escHtml(pickFirst(sources,['plan_name','job_name'])) + '</span></div>' +
        '<div class="op-top-item"><b>Client</b><span>' + escHtml(pickFirst(sources,['client_name','customer_name'])) + '</span></div>' +
        '<div class="op-top-item dispatch"><b>Dispatch Date</b><span>' + escHtml(pickFirst(sources,['dispatch_date','due_date'])) + '</span></div>' +
        '<div class="op-top-item"><b>Order Qty</b><span>' + escHtml(orderQtyRaw) + '</span></div>' +
        '<div class="op-top-item"><b>Production Qty</b><span>' + escHtml(prodQtyRaw) + '</span></div>' +
      '</div>' +

      // Section A
      '<div class="op-section">' +
        '<h5 class="op-sec-a-toggle">&#9660; Section A: Planning &amp; Paper Roll Details</h5>' +
        '<div class="op-section-content op-sec-a-content">' +
          detailHtml +
          (job.image_url ? '<div style="margin-top:12px;text-align:center;border-top:1px solid #fef3c7;padding-top:12px"><div style="font-size:.74rem;font-weight:900;color:#c2410c;margin-bottom:8px">Job Image</div><img src="' + escHtml(job.image_url) + '" alt="Job Image" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #fed7aa"></div>' : '') +
        '</div>' +

      // Section B — operator entry
      '<div class="op-section">' +
        '<h5>&#9660; Section B: Operator Packing Entry</h5>' +
        '<div class="op-section-content">' +
          submittedBoxHtml +
          // STEP 1: Helper calculation fields (top)
          '<div style="margin-bottom:14px;padding:12px;border:1px solid #fed7aa;border-radius:10px;background:linear-gradient(120deg,#fff7ed 0%,#fff 100%)"><div style="font-size:.74rem;font-weight:900;color:#c2410c;margin-bottom:8px">Packing Calculation Helpers</div><div class="op-entry-form">' +
            '<div class="op-field"><label>Rolls per shrink wrap</label>' +
              '<input type="number" id="opRollsPerShrink" class="op-calc-field" min="1" step="1" value="5">' +
            '</div>' +
            '<div class="op-field"><label>Bundles per carton</label>' +
              '<input type="number" id="opBundlesPerCarton" class="op-calc-field" min="1" step="1" value="10">' +
            '</div>' +
            '<div class="op-field"><label>Rolls per carton</label>' +
              '<input type="number" id="opRollsPerCarton" class="op-calc-field" min="1" step="1" value="50">' +
            '</div>' +
            '<div class="op-field"><label>Carton size (mm)</label>' +
              '<input type="number" id="opCartonSize" class="op-calc-field" min="1" step="1" value="75">' +
            '</div>' +
            '<div class="op-field"><label>Carton Lagbe (calculated)</label>' +
              '<input type="text" id="opCalcCartonsNeeded" readonly value="-" style="background:#fff7ed;font-weight:900;color:#9a3412;cursor:not-allowed">' +
            '</div>' +
            '<div class="op-field"><label>Physical Production (calculated)</label>' +
              '<input type="text" id="opCalcPhysicalProduction" readonly value="-" style="background:#fff7ed;font-weight:900;color:#9a3412;cursor:not-allowed">' +
            '</div>' +
          '</div></div>' +
          // STEP 2: Live display metrics
          '<div style="margin-bottom:14px;padding:12px;border:1px solid #fef3c7;border-radius:10px;background:linear-gradient(120deg,#fffbeb 0%,#fff 100%)"><div style="font-size:.74rem;font-weight:900;color:#92400e;margin-bottom:8px">Live Production Metrics</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Order Qty</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(orderQtyRaw) + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Production Qty</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(prodQtyRaw) + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Shrink Bundles</b><span id="opLiveShrinkBundles" style="font-size:.95rem;font-weight:900;color:#7c3aed">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Cartons Required</b><span id="opLiveCartonsRequired" style="font-size:.95rem;font-weight:900;color:#9a3412">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Physical Qty</b><span id="opLivePhysicalQty" style="font-size:.95rem;font-weight:900;color:#0c4169">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Physical %</b><span id="opLivePhysicalPct" style="font-size:.95rem;font-weight:900;color:#0f766e">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Loose Baki</b><span id="opLiveLooseQty" style="font-size:.95rem;font-weight:900;color:#7c2d12">-</span></div>' +
          '</div></div>' +
          // STEP 3: Operator entry fields
          '<div class="op-entry-form" id="opEntryForm">' +
            '<input type="hidden" id="opPackedQty" value="' + escHtml(opEntry?String(opEntry.packed_qty||''):'') + '">' +
            '<input type="hidden" id="opBundlesCount" value="' + escHtml(opEntry?String(opEntry.bundles_count||''):'') + '">' +
            '<input type="hidden" id="opCartonsCount" value="' + escHtml(opEntry?String(opEntry.cartons_count||''):'') + '">' +
            '<input type="hidden" id="opWastageQty" value="' + escHtml(opEntry?String(opEntry.wastage_qty||''):'') + '">' +
            '<div class="op-field"><label>Loose Qty (editable)</label>' +
              '<input type="number" id="opLooseQty" min="0" step="1" value="' + escHtml(opEntry?String(opEntry.loose_qty||''):'') + '" placeholder="Auto calculated, editable">' +
            '</div>' +
            '<div class="op-field" style="grid-column:1/-1"><label>Notes / Remarks</label>' +
              '<textarea id="opNotes">' + escHtml(opEntry?(opEntry.notes||''):'') + '</textarea>' +
            '</div>' +
            '<div class="op-field" style="grid-column:1/-1"><label>Photo (optional, max 5 MB) — tap to take photo with camera</label>' +
              '<input type="file" id="opPhoto" accept=".jpg,.jpeg,.png,.webp,image/*" capture="environment">' +
            '</div>' +
          '</div>' +
          '<div class="op-submit-row">' +
            '<button class="op-btn-submit" id="opSubmitBtn" type="button"' + (lockSubmittedForOperator ? ' disabled style="opacity:.65;cursor:not-allowed"' : '') + '>' + (lockSubmittedForOperator ? 'Submitted (Locked)' : (opEntry ? 'Update Entry' : 'Submit Entry')) + '</button>' +
            '<button class="op-btn-print" id="opPrintSlipBtn" type="button">Packing Slip Print</button>' +
            '<button class="op-btn-cancel-modal" id="opCancelBtn" type="button">Cancel</button>' +
          '</div>' +
          '<div class="op-msg" id="opMsg">' + (lockSubmittedForOperator ? 'Only admin can edit/delete after first submit.' : '') + '</div>' +
        '</div>' +
      '</div>';

    modalTitle.textContent = 'Job: ' + (job.job_no||'-') + ' — Packing Entry';
    modalSub.textContent   = (job.plan_name||'-') + ' | ' + (job.packing_display_id||'-');
    modalBody.innerHTML    = html;

    // Section A collapse toggle
    var togA = modalBody.querySelector('.op-sec-a-toggle');
    var contA= modalBody.querySelector('.op-sec-a-content');
    if (togA && contA) {
      togA.addEventListener('click', function() {
        contA.classList.toggle('collapsed');
        var arrow = togA.querySelector
        togA.innerHTML = (contA.classList.contains('collapsed') ? '&#9654;' : '&#9660;') + ' Section A: Planning & Paper Roll Details';
      });
    }

    // Live metrics update
    var packedQtyInput = document.getElementById('opPackedQty');
    var bundlesCountInput = document.getElementById('opBundlesCount');
    var rollsPerShrinkInput = document.getElementById('opRollsPerShrink');
    var bundlesPerCartonInput = document.getElementById('opBundlesPerCarton');
    var rollsPerCartonInput = document.getElementById('opRollsPerCarton');
    var looseQtyInput = document.getElementById('opLooseQty');
    var cartonsCountInput = document.getElementById('opCartonsCount');
    var wastageQtyInput = document.getElementById('opWastageQty');
    var liveShrinkBundles = document.getElementById('opLiveShrinkBundles');
    var liveCartonsRequired = document.getElementById('opLiveCartonsRequired');
    var livePhysicalQty = document.getElementById('opLivePhysicalQty');
    var livePhysicalPct = document.getElementById('opLivePhysicalPct');
    var liveLooseQty = document.getElementById('opLiveLooseQty');
    var calcCartonsNeeded = document.getElementById('opCalcCartonsNeeded');
    var calcPhysicalProduction = document.getElementById('opCalcPhysicalProduction');
    var orderQtyNum = parseFloat(String(orderQtyRaw || '').replace(/,/g, '')) || 0;
    var prodQtyNum = parseFloat(String(prodQtyRaw || '').replace(/,/g, '')) || 0;
    var looseEditedManually = false;

    function toNum(v) {
      var n = parseFloat(String(v || '').replace(/,/g, ''));
      return Number.isFinite(n) ? n : 0;
    }

    function updateLiveMetrics() {
      if (!rollsPerCartonInput) return;

      var packed = prodQtyNum;
      var rollsPerShrink = Math.max(1, toNum(rollsPerShrinkInput ? rollsPerShrinkInput.value : 0) || 1);
      var bundlesPerCarton = Math.max(1, toNum(bundlesPerCartonInput ? bundlesPerCartonInput.value : 0) || 1);
      var rollsPerCarton = Math.max(1, toNum(rollsPerCartonInput.value) || 1);
      var looseAuto = Math.max(0, packed % rollsPerCarton);

      if (looseQtyInput && (!looseEditedManually || String(looseQtyInput.value || '').trim() === '')) {
        looseQtyInput.value = String(looseAuto);
      }

      var looseNow = looseQtyInput ? toNum(looseQtyInput.value) : looseAuto;
      var physicalProduction = packed + looseNow;
      var shrinkBundles = Math.floor(physicalProduction / rollsPerShrink);
      var cartonsNeeded = Math.floor(shrinkBundles / bundlesPerCarton);
      var physicalPct = orderQtyNum > 0 ? ((physicalProduction / orderQtyNum) * 100) : 0;

      if (packedQtyInput) packedQtyInput.value = String(physicalProduction);
      if (cartonsCountInput) cartonsCountInput.value = String(cartonsNeeded);
      if (bundlesCountInput) bundlesCountInput.value = String(shrinkBundles);
      if (wastageQtyInput) wastageQtyInput.value = '';

      if (calcCartonsNeeded) calcCartonsNeeded.value = cartonsNeeded.toLocaleString('en-IN');
      if (calcPhysicalProduction) calcPhysicalProduction.value = physicalProduction.toLocaleString('en-IN');
      if (liveShrinkBundles) liveShrinkBundles.textContent = shrinkBundles.toLocaleString('en-IN');
      if (liveCartonsRequired) liveCartonsRequired.textContent = cartonsNeeded.toLocaleString('en-IN');
      if (livePhysicalQty) livePhysicalQty.textContent = physicalProduction.toLocaleString('en-IN');
      if (livePhysicalPct) livePhysicalPct.textContent = physicalPct.toFixed(1) + '%';
      if (liveLooseQty) liveLooseQty.textContent = looseNow.toLocaleString('en-IN');
    }

    if (rollsPerShrinkInput) {
      rollsPerShrinkInput.addEventListener('input', updateLiveMetrics);
      rollsPerShrinkInput.addEventListener('change', updateLiveMetrics);
    }
    if (bundlesPerCartonInput) {
      bundlesPerCartonInput.addEventListener('input', updateLiveMetrics);
      bundlesPerCartonInput.addEventListener('change', updateLiveMetrics);
    }

    if (rollsPerCartonInput) {
      rollsPerCartonInput.addEventListener('input', updateLiveMetrics);
      rollsPerCartonInput.addEventListener('change', updateLiveMetrics);
    }
    if (looseQtyInput) {
      looseQtyInput.addEventListener('input', function() {
        looseEditedManually = true;
        updateLiveMetrics();
      });
      looseQtyInput.addEventListener('change', function() {
        looseEditedManually = true;
        updateLiveMetrics();
      });
    }
    updateLiveMetrics();

    // Submit handler
    var submitBtn = document.getElementById('opSubmitBtn');
    var printBtn  = document.getElementById('opPrintSlipBtn');
    var cancelBtn = document.getElementById('opCancelBtn');
    var msgDiv    = document.getElementById('opMsg');
    if (lockSubmittedForOperator) {
      var lockIds = ['opRollsPerShrink','opBundlesPerCarton','opRollsPerCarton','opCartonSize','opLooseQty','opNotes','opPhoto'];
      lockIds.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.disabled = true;
      });
    }

    cancelBtn.addEventListener('click', closeModal);

    printBtn.addEventListener('click', function() {
      var jobNo = String(job.job_no || '-');
      var planNo = String(pickFirst(sources,['plan_no','plan_number']) || '-');
      var packingId = String(job.packing_display_id || '-');
      var jobName = String(pickFirst(sources,['plan_name','job_name']) || '-');
      var clientName = String(pickFirst(sources,['client_name','customer_name']) || '-');
      var dispatchDate = String(pickFirst(sources,['dispatch_date','due_date']) || '-');
      var orderQtyText = String(orderQtyRaw || '-');
      var prodQtyText = String(prodQtyRaw || '-');

      var shrinkBundlesText = liveShrinkBundles ? String(liveShrinkBundles.textContent || '-') : '-';
      var cartonsRequiredText = liveCartonsRequired ? String(liveCartonsRequired.textContent || '-') : '-';
      var physicalQtyText = livePhysicalQty ? String(livePhysicalQty.textContent || '-') : '-';
      var physicalPctText = livePhysicalPct ? String(livePhysicalPct.textContent || '-') : '-';
      var looseQtyText = liveLooseQty ? String(liveLooseQty.textContent || '-') : '-';
      var notesText = String((document.getElementById('opNotes')?.value || '').trim() || '-');
      var rollsPerShrinkText = String((document.getElementById('opRollsPerShrink')?.value || '').trim() || '-');
      var bundlesPerCartonText = String((document.getElementById('opBundlesPerCarton')?.value || '').trim() || '-');
      var rollsPerCartonText = String((document.getElementById('opRollsPerCarton')?.value || '').trim() || '-');
      var cartonSizeText = String((document.getElementById('opCartonSize')?.value || '').trim() || '-');
      var cartonLagbeText = String((document.getElementById('opCalcCartonsNeeded')?.value || '').trim() || '-');
      var physicalProductionCalcText = String((document.getElementById('opCalcPhysicalProduction')?.value || '').trim() || '-');
      var looseQtyEditableText = String((document.getElementById('opLooseQty')?.value || '').trim() || '-');

      var submittedBy = opEntry && opEntry.operator_name ? String(opEntry.operator_name) : currentOperatorName;
      var submittedAt = opEntry && opEntry.submitted_at ? String(opEntry.submitted_at) : new Date().toLocaleString();
      var qrDataUrl = buildSlipQrDataUrl(jobNo);

      var slipHtml = '' +
        '<!doctype html><html><head><meta charset="utf-8"><title>Packing Slip - ' + escHtml(jobNo) + '</title>' +
        '<style>' +
        ':root{--brand:#c2410c;--brand-soft:#fff7ed;--line:#e2e8f0;--text:#0f172a;--muted:#64748b;--accent:#0ea5e9;--ok:#16a34a}*{box-sizing:border-box}' +
        'body{font-family:"Segoe UI",Tahoma,Arial,sans-serif;margin:0;padding:16px;color:var(--text);background:#fff;font-size:15px}' +
        '.sheet{border:1px solid var(--line);border-radius:12px;overflow:hidden}' +
        '.hdr{padding:14px 16px;background:linear-gradient(120deg,var(--brand-soft) 0%,#fff 70%);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px;align-items:flex-start}' +
        '.hdr h1{margin:0;font-size:22px;letter-spacing:.03em;color:var(--brand)}' +
        '.sub{margin-top:4px;font-size:13px;color:var(--muted)}' +
        '.pill{display:inline-block;border:1px solid #fdba74;background:#ffedd5;color:#9a3412;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700}' +
        '.qr-wrap{width:124px;min-height:124px;border:1px dashed #cbd5e1;border-radius:10px;background:#fff;display:flex;align-items:center;justify-content:center;padding:6px}' +
        '.qr-wrap img{width:112px;height:112px;display:block}' +
        '.qr-na{font-size:11px;color:#64748b;font-weight:700}' +
        '.meta{padding:12px 16px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;border-bottom:1px solid var(--line)}' +
        '.meta .m{border:1px solid var(--line);border-radius:8px;padding:8px;background:#fff}' +
        '.meta b{display:block;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}' +
        '.meta span{font-size:15px;font-weight:700}' +
        '.block{padding:12px 16px;border-bottom:1px solid var(--line)}' +
        '.title{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#334155;font-weight:800}' +
        'table{width:100%;border-collapse:collapse;border:1px solid var(--line);border-radius:8px;overflow:hidden}' +
        'thead th{background:#eff6ff;color:#1e3a8a;font-size:12px;text-transform:uppercase;letter-spacing:.05em;padding:9px;border-bottom:1px solid #bfdbfe;text-align:left}' +
        'tbody td{padding:9px;border-bottom:1px solid #e2e8f0;font-size:14px}' +
        'tbody tr:nth-child(even) td{background:#f8fafc}' +
        '.notes{border:1px dashed #cbd5e1;border-radius:8px;min-height:48px;padding:9px;background:#f8fafc;font-size:14px}' +
        '.sig{padding:14px 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px}' +
        '.sig-box{border-top:1px solid #94a3b8;padding-top:6px;font-size:13px;color:#334155}' +
        '.ftr{padding:10px 16px;border-top:1px solid var(--line);background:#fff7ed;display:flex;justify-content:space-between;gap:8px;font-size:12px;color:#7c2d12;font-weight:700}' +
        '@media print{@page{size:A4;margin:10mm}body{padding:0}.sheet{border:none;border-radius:0}.ftr{position:fixed;left:0;right:0;bottom:0}}' +
        '</style></head><body>' +
        '<div class="sheet">' +
        '  <div class="hdr"><div><h1>Packing Job Card</h1><div class="sub">Generated from Packing Dashboard</div><div style="margin-top:8px"><span class="pill">POS Roll Packing</span></div></div><div class="qr-wrap">' + (qrDataUrl ? ('<img src="' + escHtml(qrDataUrl) + '" alt="Job QR">') : '<div class="qr-na">QR unavailable</div>') + '</div></div>' +
        '  <div class="meta">' +
        '    <div class="m"><b>Packing ID</b><span>' + escHtml(packingId) + '</span></div>' +
        '    <div class="m"><b>Plan No</b><span>' + escHtml(planNo) + '</span></div>' +
        '    <div class="m"><b>Job No</b><span>' + escHtml(jobNo) + '</span></div>' +
        '    <div class="m"><b>Job Name</b><span>' + escHtml(jobName) + '</span></div>' +
        '    <div class="m"><b>Client</b><span>' + escHtml(clientName) + '</span></div>' +
        '    <div class="m"><b>Dispatch Date</b><span>' + escHtml(dispatchDate) + '</span></div>' +
        '  </div>' +
        '  <div class="block"><p class="title">Quantity Summary</p>' +
        '    <table><thead><tr><th>Metric</th><th>Value</th><th>Metric</th><th>Value</th></tr></thead><tbody>' +
        '      <tr><td>Order Qty</td><td>' + escHtml(orderQtyText) + '</td><td>Production Qty</td><td>' + escHtml(prodQtyText) + '</td></tr>' +
        '      <tr><td>Physical Qty</td><td>' + escHtml(physicalQtyText) + '</td><td>Physical %</td><td>' + escHtml(physicalPctText) + '</td></tr>' +
        '      <tr><td>Shrink Bundles</td><td>' + escHtml(shrinkBundlesText) + '</td><td>Cartons Required</td><td>' + escHtml(cartonsRequiredText) + '</td></tr>' +
        '      <tr><td>Loose Qty</td><td>' + escHtml(looseQtyText) + '</td><td>Submitted By</td><td>' + escHtml(submittedBy) + '</td></tr>' +
        '      <tr><td>Submitted At</td><td colspan="3">' + escHtml(submittedAt) + '</td></tr>' +
        '    </tbody></table>' +
        '  </div>' +
        '  <div class="block"><p class="title">Packing Calculation Helpers</p>' +
        '    <table><thead><tr><th>Helper Field</th><th>Value</th><th>Helper Field</th><th>Value</th></tr></thead><tbody>' +
        '      <tr><td>Rolls per shrink wrap</td><td>' + escHtml(rollsPerShrinkText) + '</td><td>Bundles per carton</td><td>' + escHtml(bundlesPerCartonText) + '</td></tr>' +
        '      <tr><td>Rolls per carton</td><td>' + escHtml(rollsPerCartonText) + '</td><td>Carton size (mm)</td><td>' + escHtml(cartonSizeText) + '</td></tr>' +
        '      <tr><td>Carton Lagbe (calculated)</td><td>' + escHtml(cartonLagbeText) + '</td><td>Physical Production (calculated)</td><td>' + escHtml(physicalProductionCalcText) + '</td></tr>' +
        '    </tbody></table>' +
        '  </div>' +
        '  <div class="block"><p class="title">Live Production Inputs</p>' +
        '    <table><thead><tr><th>Metric</th><th>Value</th><th>Metric</th><th>Value</th></tr></thead><tbody>' +
        '      <tr><td>Loose Baki</td><td>' + escHtml(looseQtyText) + '</td><td>Loose Qty (editable)</td><td>' + escHtml(looseQtyEditableText) + '</td></tr>' +
        '      <tr><td>Notes / Remarks</td><td colspan="3">' + escHtml(notesText) + '</td></tr>' +
        '    </tbody></table>' +
        '  </div>' +
        '  <div class="sig"><div class="sig-box">Operator Signature</div><div class="sig-box">Manager Signature</div></div>' +
        '  <div class="ftr"><span>Shree Label ERP - Packing Slip</span><span>Printed: ' + escHtml(new Date().toLocaleString()) + '</span></div>' +
        '</div>' +
        '</body></html>';

      // Print from hidden iframe only to avoid opening blank popup pages in Chrome.
      var frame = document.getElementById('opSlipPrintFrame');
      if (!frame) {
        frame = document.createElement('iframe');
        frame.id = 'opSlipPrintFrame';
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '0';
        frame.style.height = '0';
        frame.style.border = '0';
        frame.setAttribute('aria-hidden', 'true');
        document.body.appendChild(frame);
      }

      var fdoc = frame.contentWindow ? frame.contentWindow.document : frame.contentDocument;
      if (!fdoc) {
        msgDiv.textContent = 'Could not open print preview. Please try again.';
        msgDiv.style.color = '#b91c1c';
        return;
      }

      fdoc.open();
      fdoc.write(slipHtml);
      fdoc.close();
      setTimeout(function() {
        try {
          if (frame.contentWindow) {
            frame.contentWindow.focus();
            frame.contentWindow.print();
          }
        } catch (e) {}
      }, 180);
      msgDiv.textContent = '';
    });

    submitBtn.addEventListener('click', function() {
      if (lockSubmittedForOperator) {
        msgDiv.textContent = 'Entry already submitted. Only admin can edit or delete.';
        msgDiv.style.color = '#b91c1c';
        return;
      }

      var packedQty    = (document.getElementById('opPackedQty')?.value    || '').trim();
      var bundlesCount = (document.getElementById('opBundlesCount')?.value || '').trim();
      var cartonsCount = (document.getElementById('opCartonsCount')?.value || '').trim();
      var wastageQty   = (document.getElementById('opWastageQty')?.value  || '').trim();
      var looseQty     = (document.getElementById('opLooseQty')?.value    || '').trim();
      var notes        = (document.getElementById('opNotes')?.value        || '').trim();
      var photoInput   = document.getElementById('opPhoto');

      if (packedQty === '' || parseFloat(packedQty) < 0) {
        msgDiv.textContent = 'Please enter a valid Packed Quantity.';
        msgDiv.style.color = '#b91c1c';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
      msgDiv.textContent = '';

      var fd = new FormData();
      fd.append('action',      'operator_submit');
      fd.append('job_id',      String(activeJobId));
      fd.append('job_no',      String(activeJobNo));
      fd.append('planning_id', String(activeJobData && activeJobData.planning_id ? activeJobData.planning_id : '0'));
      fd.append('packed_qty',    packedQty);
      fd.append('bundles_count', bundlesCount);
      fd.append('cartons_count', cartonsCount);
      fd.append('wastage_qty',   wastageQty);
      fd.append('loose_qty',     looseQty);
      fd.append('notes',         notes);
      if (photoInput && photoInput.files && photoInput.files[0]) {
        fd.append('photo', photoInput.files[0]);
      }

      fetch(baseUrl + '/modules/packing/api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(resp) {
          if (!resp || !resp.ok) {
            msgDiv.textContent = (resp && resp.message) ? resp.message : 'Submit failed.';
            msgDiv.style.color = '#b91c1c';
            return;
          }
          msgDiv.textContent = '✓ Entry submitted successfully! Manager can now finalize this job.';
          msgDiv.style.color = '#166534';
          if (opCanAdminOverrideEdit) {
            submitBtn.textContent = 'Update Entry';
          } else {
            submitBtn.textContent = 'Submitted (Locked)';
            submitBtn.disabled = true;
            ['opRollsPerShrink','opBundlesPerCarton','opRollsPerCarton','opCartonSize','opLooseQty','opNotes','opPhoto'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.disabled = true;
            });
            msgDiv.textContent = 'Entry submitted and locked. Only admin can edit or delete.';
          }
          // Update badge in table row
          var row = document.querySelector('tr[data-row-id="' + String(activeJobId) + '"]');
          if (row) {
            var badges = row.querySelectorAll('.op-badge');
            badges.forEach(function(b) {
              if (b.classList.contains('muted') && b.textContent.trim().toLowerCase() === 'pending') {
                b.className = 'op-badge submitted';
                b.textContent = 'Submitted';
              }
            });
          }
        })
        .catch(function() {
          msgDiv.textContent = 'Network error. Please try again.';
          msgDiv.style.color = '#b91c1c';
        })
        .finally(function() {
          submitBtn.disabled = false;
        });
    });
  }

  // ── Open job modal ──
  function openJobModal(jobId) {
    if (!jobId) return;
    activeJobId = Number(jobId) || 0;
    modalBody.innerHTML = loadingHtml();
    openModal();

    var url = baseUrl + '/modules/packing/api.php?action=job_details&job_id=' + encodeURIComponent(String(jobId));
    fetch(url, { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok || !data.job) {
          modalBody.innerHTML = '<div style="padding:24px;text-align:center;color:#b91c1c;font-weight:700">' +
            escHtml((data && data.message) ? data.message : 'Could not load job details.') + '</div>';
          return;
        }
        activeJobData = data.job;
        activeJobNo   = String(data.job.job_no || '');
        renderModal(data.job);
      })
      .catch(function(err) {
        modalBody.innerHTML = '<div style="padding:24px;text-align:center;color:#b91c1c;font-weight:700">' +
          escHtml((err && err.message) ? err.message : 'Could not load job details.') + '</div>';
      });
  }

  // ── Bind open buttons ──
  document.querySelectorAll('.op-open-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openJobModal(btn.getAttribute('data-job-id'));
    });
  });

})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
