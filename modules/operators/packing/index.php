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
$canDeleteJobs = function_exists('isAdmin') && isAdmin();

$allowedTabs   = array_merge(packing_tab_keys(), ['history']);
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

// show_all_active=true: operator page must show fresh "Packing" jobs even before submission
// so operators can open them and submit their production entries.
$data      = packing_fetch_ready_rows($db, [
  'search' => $search,
  'from' => $from,
  'to' => $to,
  'show_all_active' => true,
  // Operator queue should exclude manager-marked packed rows.
  'hide_packed_in_active' => true,
]);
$rowsByTab = $data['rows_by_tab'];
$counts    = $data['counts'];

$historyRows = packing_fetch_history_rows($db, ['search' => $search, 'from' => $from, 'to' => $to]);
$rowsByTab['history'] = $historyRows;
$counts['history'] = count($historyRows);

$formatDate = static function(string $value): string {
    $value = trim($value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y', $ts) : '-';
};

$displayPackingStatus = static function(string $status): string {
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', $status)));
  if ($norm === 'finished production') return 'Finished Production';
  if ($norm === 'dispatched') return 'Dispatched';
  if (in_array($norm, ['packing done', 'packed'], true)) return 'Packed';
  return 'Packing';
};

$displayPackingStatusClass = static function(string $status): string {
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', $status)));
  return in_array($norm, ['packing done', 'packed', 'finished production', 'dispatched'], true) ? 'ok' : 'muted';
};

$tabThemes = [
    'printing_label' => ['accent' => '#c2410c', 'soft' => '#fff7ed', 'border' => '#fed7aa'],
    'pos_roll'        => ['accent' => '#b45309', 'soft' => '#fef3c7', 'border' => '#fde68a'],
    'one_ply'         => ['accent' => '#92400e', 'soft' => '#ffedd5', 'border' => '#fdba74'],
    'two_ply'         => ['accent' => '#d97706', 'soft' => '#fffbeb', 'border' => '#fcd34d'],
    'barcode'         => ['accent' => '#dc2626', 'soft' => '#fef2f2', 'border' => '#fca5a5'],
  'history'         => ['accent' => '#6d28d9', 'soft' => '#f3e8ff', 'border' => '#ddd6fe'],
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
.op-tab[data-theme="history"],.op-pane[data-theme="history"]{--op-accent:#6d28d9;--op-soft:#f3e8ff;--op-border:#ddd6fe}
.op-btn-admin-edit{background:#7c3aed;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:800;font-size:.95em;cursor:pointer;transition:all .2s ease;display:inline-flex;align-items:center;gap:6px}
.op-btn-admin-edit:hover{background:#6d28d9;transform:translateY(-1px)}
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
    $isBarcodeTab = ($tabKey === 'barcode');
    $barcodeTotalRollValue = 0.0;
    if ($isBarcodeTab) {
      foreach ($rows as $rowValue) {
        $rawRollValue = trim((string)($rowValue['total_roll_value'] ?? ''));
        if ($rawRollValue === '') continue;
        $numericRollValue = (float)str_replace(',', '', $rawRollValue);
        if (is_numeric(str_replace(',', '', $rawRollValue))) {
          $barcodeTotalRollValue += $numericRollValue;
        }
      }
    }
  ?>
  <div class="op-pane<?= $tabKey === $currentTab ? ' active' : '' ?>"
       data-pane="<?= e($tabKey) ?>" data-theme="<?= e($tabKey) ?>"
       style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
    <div class="op-bar">
      <span class="count"><?= count($rows) ?> job(s) — <?= e($label) ?></span>
      <?php if ($isBarcodeTab): ?>
      <span class="count">Total Roll Value: <?= e(number_format($barcodeTotalRollValue, 2, '.', '')) ?></span>
      <?php endif; ?>
    </div>
    <div class="op-table-wrap">
      <table class="op-table">
        <thead>
          <tr>
            <th>Packing ID</th>
            <th>Plan No</th>
            <th>Job Name</th>
            <th>Client</th>
            <?php if ($isBarcodeTab): ?><th>Total Order Quantity</th><?php endif; ?>
            <?php if ($isBarcodeTab): ?><th>Production Qty</th><?php endif; ?>
            <?php if ($isBarcodeTab): ?><th>Total Roll Value</th><?php endif; ?>
            <th>Dispatch</th>
            <th>Status</th>
            <th>Operator Entry</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="<?= $isBarcodeTab ? '11' : '8' ?>" class="op-empty">No jobs found for this category.</td></tr>
          <?php else: ?>
          <?php foreach ($rows as $r):
            $isSubmitted = !empty($r['operator_submitted']);
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
            <?php if ($isBarcodeTab): ?><td><?= e(($r['order_quantity'] ?? '') !== '' ? $r['order_quantity'] : '-') ?></td><?php endif; ?>
            <?php if ($isBarcodeTab): ?><td><?= e(($r['production_quantity'] ?? '') !== '' ? $r['production_quantity'] : '-') ?></td><?php endif; ?>
            <?php if ($isBarcodeTab): ?><td><?= e(($r['total_roll_value'] ?? '') !== '' ? $r['total_roll_value'] : '-') ?></td><?php endif; ?>
            <td><?= e($formatDate((string)($r['dispatch_date'] ?? ''))) ?></td>
            <td><span class="op-badge <?= e($displayPackingStatusClass((string)($r['status'] ?? ''))) ?>"><?= e($displayPackingStatus((string)($r['status'] ?? ''))) ?></span></td>
            <td><?php if ($isSubmitted): ?>
              <span class="op-badge submitted">Submitted</span>
            <?php else: ?>
              <span class="op-badge muted">Pending</span>
            <?php endif; ?></td>
            <td>
              <button class="op-id-btn op-open-btn"
                      data-job-id="<?= (int)($r['id'] ?? 0) ?>"
                      style="--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
                Open
              </button>
              <?php if ($tabKey === 'history'): ?>
              <button class="op-id-btn op-history-print-btn"
                      data-job-id="<?= (int)($r['id'] ?? 0) ?>"
                      title="Print packing slip"
                      style="margin-left:6px;--op-accent:<?= e($theme['accent']) ?>;--op-soft:<?= e($theme['soft']) ?>;--op-border:<?= e($theme['border']) ?>">
                Print
              </button>
              <?php if ($canDeleteJobs): ?>
              <button class="op-id-btn op-history-delete-btn"
                      data-job-id="<?= (int)($r['id'] ?? 0) ?>"
                      title="Delete job"
                      style="margin-left:6px;background:#fee2e2;border-color:#fecaca;color:#b91c1c">
                Delete
              </button>
              <?php endif; ?>
              <?php endif; ?>
            </td>
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
  var opCanDeleteJobs = <?= $canDeleteJobs ? 'true' : 'false' ?>;
  var activeJobId = 0;
  var activeJobNo = '';
  var activeJobData = null;
  var opCartonStatusMap = {};
  var opCartonStatusLoaded = false;
  var opCartonStatusPromise = null;

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
  function closeModal() {
    if (document.getElementById('opSubmitSuccessModal')) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    activeJobId = 0;
    activeJobNo = '';
    activeJobData = null;
  }
  function loadingHtml() { return '<div style="padding:30px;text-align:center;color:#92400e;font-weight:700">Loading job details...</div>'; }

  document.getElementById('opCloseModalBtn').addEventListener('click', closeModal);
  document.getElementById('opModalBackdrop').addEventListener('click', closeModal);
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

  function escHtml(v) {
    return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function opNormCartonKey(v) {
    return String(v || '').toLowerCase().replace(/\s+/g, '');
  }

  function opGetCartonStatus(sizeText) {
    var key = opNormCartonKey(sizeText);
    if (!key || !Object.prototype.hasOwnProperty.call(opCartonStatusMap, key)) {
      return { qty: null, min_qty: 0, is_low: false };
    }
    return opCartonStatusMap[key];
  }

  function opCartonBadgeHtml(sizeText) {
    var st = opGetCartonStatus(sizeText);
    if (st.qty === null || typeof st.qty === 'undefined') {
      return '<span style="display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#e2e8f0;color:#475569;font-size:.68rem;font-weight:800">Qty: -</span>';
    }
    if (st.is_low) {
      return '<span style="display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#fee2e2;color:#b91c1c;font-size:.68rem;font-weight:900">Qty: ' + escHtml(String(st.qty)) + ' (Low Quantity)</span>';
    }
    return '<span style="display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#dcfce7;color:#166534;font-size:.68rem;font-weight:900">Qty: ' + escHtml(String(st.qty)) + '</span>';
  }

  function opLoadCartonStatus(forceReload) {
    if (!forceReload && opCartonStatusLoaded) {
      return Promise.resolve(opCartonStatusMap);
    }
    if (opCartonStatusPromise) {
      return opCartonStatusPromise;
    }
    opCartonStatusPromise = fetch(baseUrl + '/modules/packing/api.php?action=get_carton_stock_status', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        var nextMap = {};
        var rows = (res && Array.isArray(res.rows)) ? res.rows : [];
        rows.forEach(function(row) {
          var rawSize = String((row && row.size) || '').trim();
          var key = opNormCartonKey(rawSize);
          if (!key) return;
          nextMap[key] = {
            qty: Math.max(0, Math.floor(Number(row.qty || 0))),
            min_qty: Math.max(0, Math.floor(Number(row.min_qty || 0))),
            is_low: !!row.is_low
          };
        });
        opCartonStatusMap = nextMap;
        opCartonStatusLoaded = true;
        if (modal && modal.classList.contains('show')) {
          updateLiveMetrics();
        }
        return opCartonStatusMap;
      })
      .catch(function() {
        return opCartonStatusMap;
      })
      .finally(function() {
        opCartonStatusPromise = null;
      });
    return opCartonStatusPromise;
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
    function parseExtraData(raw) {
      if (raw && typeof raw === 'object') return raw;
      if (typeof raw === 'string') {
        try {
          var parsed = JSON.parse(raw);
          if (parsed && typeof parsed === 'object') return parsed;
        } catch (e) {}
      }
      return {};
    }

    var planExtra = parseExtraData(job.plan_extra_data);
    var jobExtra  = parseExtraData(job.job_extra_data);
    var prevExtra = parseExtraData(job.prev_job_extra_data);
    var grandPrevExtra = parseExtraData(job.grandprev_job_extra_data);
    var sources   = [job, planExtra, jobExtra, prevExtra, grandPrevExtra];
    var isBarcodeMode = String(job.packing_tab || '').toLowerCase() === 'barcode'
      || /^PLN-BAR\//i.test(String(pickFirst(sources, ['plan_no', 'plan_number']) || ''));
    var tabKeyNorm = String(job.packing_tab || '').toLowerCase();
    var isPaperRollTypeTab = tabKeyNorm === 'pos_roll' || tabKeyNorm === 'one_ply' || tabKeyNorm === 'two_ply';

    var orderQtyRaw = pickFirstLoose(sources, ['order_quantity','order_qty','quantity','qty_pcs']);
    var prodQtyRaw  = pickFirstLoose(sources, ['production_quantity','production_qty','produced_quantity','actual_qty','output_qty','completed_qty']);
    var totalRollValueRaw = pickFirstLoose(sources, [
      'total_roll_value','total_roll','total_rolls','barcode_total_roll','barcode_total_rolls','label_slitting_total_roll'
    ]);
    var barcodePerRollRaw = pickFirstLoose(sources, [
      'barcode_in_1_roll', 'barcode_per_roll', 'barcode_qty_per_roll',
      'pcs_per_roll', 'pieces_per_roll', 'pices_per_roll',
      'qty_per_roll', 'quantity_per_roll', 'qty_in_roll', 'quantity_in_roll',
      'planning_pcs_per_roll'
    ]);
    var orderQty = parseFloat(String(orderQtyRaw).replace(/,/g,'')) || 0;
    var prodQty  = parseFloat(String(prodQtyRaw).replace(/,/g,''))  || 0;
    var barcodePerRollDefault = Math.max(1, Math.floor(numFromAny(barcodePerRollRaw)));
    if (!(barcodePerRollDefault > 0)) {
      var totalRollNumFallback = Math.max(0, Math.floor(numFromAny(totalRollValueRaw)));
      if (orderQty > 0 && totalRollNumFallback > 0) {
        barcodePerRollDefault = Math.max(1, Math.round(orderQty / totalRollNumFallback));
      }
    }
    if (!(barcodePerRollDefault > 0)) barcodePerRollDefault = 100;

    var opEntry = (job.operator_entry && typeof job.operator_entry==='object') ? job.operator_entry : null;
    var opEntrySubmitted = !!opEntry && Number(opEntry.is_submitted || 0) === 1;
    var statusNorm = String(job.status || '').toLowerCase().replace(/[-_]/g, ' ').trim();
    var isHistoryLockedByStatus = ['packed', 'packing done', 'finished production', 'dispatched'].indexOf(statusNorm) !== -1;
    var lockSubmittedForOperator = (opEntrySubmitted || isHistoryLockedByStatus) && !opCanAdminOverrideEdit;
    var operatorLockMessage = isHistoryLockedByStatus
      ? 'This job has moved to History by manager status update. Operator entry is locked.'
      : 'Only admin can edit/delete after first submit.';
    var currentOpBatches = [];
    var opEntryRollPayload = parseExtraData(opEntry ? opEntry.roll_payload_json : null);

    function numFromAny(v) {
      var n = parseFloat(String(v == null ? '' : v).replace(/,/g, ''));
      return Number.isFinite(n) ? n : 0;
    }

    function pickRollLots() {
      var out = [];
      var seen = {};
      var fallbackQty = prodQty > 0 ? prodQty : 0;

      function firstPresent(src, keys) {
        if (!src || typeof src !== 'object') return '';
        for (var i = 0; i < keys.length; i++) {
          var v = src[keys[i]];
          if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') return v;
        }
        return '';
      }

      function push(src) {
        if (src == null) return;
        if (typeof src === 'string' || typeof src === 'number') {
          src = { roll_no: String(src) };
        }
        if (!src || typeof src !== 'object') return;
        var rollNo = String(firstPresent(src, ['roll_no', 'roll', 'parent_roll', 'roll_number', 'rollNumber']) || '').trim();
        if (!rollNo || seen[rollNo]) return;
        var p = numFromAny(firstPresent(src, ['production_qty', 'produced_qty', 'qty', 'quantity', 'total_qty', 'roll_qty']));
        var a = numFromAny(firstPresent(src, ['available_qty', 'available', 'avail_qty', 'qty_available', 'balance_qty']));
        if (p <= 0) p = fallbackQty;
        if (a <= 0) a = p;
        if (p <= 0 && a <= 0) return;
        seen[rollNo] = true;
        out.push({ rollNo: rollNo, productionQty: Math.max(0, p), availableQty: Math.max(0, a) });
      }

      function pushFromArrayMaybe(container, key) {
        if (!container || typeof container !== 'object') return;
        var arr = container[key];
        if (!Array.isArray(arr)) return;
        arr.forEach(push);
      }

      if (Array.isArray(jobExtra.selected_rolls)) jobExtra.selected_rolls.forEach(push);
      if (Array.isArray(planExtra.selected_rolls)) planExtra.selected_rolls.forEach(push);
      if (Array.isArray(prevExtra.selected_rolls)) prevExtra.selected_rolls.forEach(push);
      if (Array.isArray(grandPrevExtra.selected_rolls)) grandPrevExtra.selected_rolls.forEach(push);
      pushFromArrayMaybe(jobExtra, 'parent_details');
      pushFromArrayMaybe(planExtra, 'parent_details');
      pushFromArrayMaybe(prevExtra, 'parent_details');
      pushFromArrayMaybe(grandPrevExtra, 'parent_details');
      pushFromArrayMaybe(jobExtra, 'roll_details');
      pushFromArrayMaybe(planExtra, 'roll_details');
      pushFromArrayMaybe(prevExtra, 'roll_details');
      pushFromArrayMaybe(grandPrevExtra, 'roll_details');
      pushFromArrayMaybe(jobExtra, 'assigned_child_rolls');
      pushFromArrayMaybe(planExtra, 'assigned_child_rolls');
      pushFromArrayMaybe(prevExtra, 'assigned_child_rolls');
      pushFromArrayMaybe(grandPrevExtra, 'assigned_child_rolls');
      pushFromArrayMaybe(jobExtra, 'child_rolls');
      pushFromArrayMaybe(planExtra, 'child_rolls');
      pushFromArrayMaybe(prevExtra, 'child_rolls');
      pushFromArrayMaybe(grandPrevExtra, 'child_rolls');
      pushFromArrayMaybe(jobExtra, 'split_rolls');
      pushFromArrayMaybe(planExtra, 'split_rolls');
      pushFromArrayMaybe(prevExtra, 'split_rolls');
      pushFromArrayMaybe(grandPrevExtra, 'split_rolls');
      pushFromArrayMaybe(jobExtra, 'rolls');
      pushFromArrayMaybe(planExtra, 'rolls');
      pushFromArrayMaybe(prevExtra, 'rolls');
      pushFromArrayMaybe(grandPrevExtra, 'rolls');
      if (!out.length) {
        push({ roll_no: pickFirst(sources, ['roll_no']), production_qty: fallbackQty, available_qty: fallbackQty });
      }
      return out;
    }

    var rollLots = pickRollLots();
    var rollLooseOverrides = {};
    var submittedSelectedRollKeys = [];
    if (opEntryRollPayload && typeof opEntryRollPayload === 'object') {
      var payloadSelected = Array.isArray(opEntryRollPayload.selected_roll_keys) ? opEntryRollPayload.selected_roll_keys : [];
      submittedSelectedRollKeys = payloadSelected.map(function(v) { return String(v || '').trim(); }).filter(function(v) { return v !== ''; });
      var payloadOverrides = (opEntryRollPayload.roll_overrides && typeof opEntryRollPayload.roll_overrides === 'object')
        ? opEntryRollPayload.roll_overrides
        : {};
      Object.keys(payloadOverrides).forEach(function(key) {
        var src = payloadOverrides[key];
        if (!src || typeof src !== 'object') return;
        var dst = {};
        ['rps','rpc','csize','cartons','extra','bpr','total_rolls','qty','extra_pcs','rolls_per_carton'].forEach(function(field) {
          var raw = Math.floor(toNum(src[field]));
          if (!Number.isFinite(raw)) return;
          if (field === 'rps' || field === 'rpc' || field === 'csize') {
            dst[field] = Math.max(1, raw);
          } else {
            dst[field] = Math.max(0, raw);
          }
        });
        if (typeof src.csize_text === 'string' && src.csize_text.trim() !== '') {
          dst.csize_text = src.csize_text.trim();
        }
        if (Object.keys(dst).length) {
          rollLooseOverrides[String(key)] = dst;
        }
      });
    }
    if (opEntry && rollLots.length) {
      var firstKey = String(rollLots[0].rollNo || 'roll-0');
      if (!rollLooseOverrides[firstKey] || typeof rollLooseOverrides[firstKey] !== 'object') {
        var submittedPacked = Math.max(0, numFromAny(opEntry.packed_qty));
        var submittedBundles = Math.max(0, Math.floor(numFromAny(opEntry.bundles_count)));
        var submittedCartons = Math.max(0, Math.floor(numFromAny(opEntry.cartons_count)));
        var submittedLoose = Math.max(0, Math.floor(numFromAny(opEntry.loose_qty)));
        var derivedRps = (submittedBundles > 0 && submittedPacked > 0)
          ? Math.max(1, Math.round(submittedPacked / submittedBundles))
          : 5;
        var derivedRpc = (submittedCartons > 0)
          ? Math.max(1, Math.floor(Math.max(0, submittedPacked - submittedLoose) / submittedCartons))
          : 50;
        rollLooseOverrides[firstKey] = {
          rps: derivedRps,
          rpc: derivedRpc,
          csize: 75,
          cartons: submittedCartons,
          extra: submittedLoose
        };
      }
    }
    var rollSelectionHtml = rollLots.length ? rollLots.map(function(roll, idx) {
      var rollKey = String(roll.rollNo || ('roll-' + String(idx)));
      var isChecked = !submittedSelectedRollKeys.length || submittedSelectedRollKeys.indexOf(rollKey) !== -1;
      return ''
        + '<label style="display:flex;align-items:flex-start;gap:8px;border:1px solid #fdba74;border-radius:10px;padding:8px;background:#fff;cursor:pointer">'
        + '  <input type="checkbox" class="op-roll-select" data-roll-index="' + String(idx) + '"' + (isChecked ? ' checked' : '') + ' style="margin-top:2px">'
        + '  <span style="display:flex;flex-direction:column;gap:2px">'
        + '    <b style="font-size:.78rem;color:#7c2d12">' + escHtml(roll.rollNo) + '</b>'
        + '    <span style="font-size:.7rem;color:#475569">Production: ' + escHtml(String(roll.productionQty)) + '</span>'
        + '    <span style="font-size:.7rem;color:#9a3412">Available: ' + escHtml(String(roll.availableQty)) + '</span>'
        + '  </span>'
        + '</label>';
    }).join('') : '<div style="font-size:.78rem;color:#64748b">No roll data found.</div>';
    var rollNoSummary = rollLots.length
      ? rollLots.map(function(r) { return String(r.rollNo || '').trim(); }).filter(function(v) { return v !== ''; }).join(', ')
      : pickFirst(sources, ['roll_no']);

    function cleanMaybePlaceholder(v) {
      var s = String(v == null ? '' : v).trim();
      if (!s) return '';
      var n = s.toLowerCase();
      if (n === '-' || n === '--' || n === '—' || n === 'na' || n === 'n/a' || n === 'null' || n === 'undefined') {
        return '';
      }
      return s;
    }

    var paperSizeRaw = cleanMaybePlaceholder(pickFirstLoose(sources, ['paper_size', 'paper_width_mm', 'paper_width', 'width_mm']));
    var barcodeSizeRaw = cleanMaybePlaceholder(pickFirstLoose(sources, ['barcode_size', 'planning_die_size', 'die_size', 'size']));
    var itemWidthRaw = cleanMaybePlaceholder(pickFirstLoose(sources, ['planning_size_width_mm', 'item_width_mm', 'width_mm', 'barcode_width', 'width']));
    var itemLengthRaw = cleanMaybePlaceholder(pickFirstLoose(sources, ['planning_size_height_mm', 'item_length_mm', 'height_mm', 'barcode_height', 'height', 'length_mm', 'length']));
    if ((!itemWidthRaw || !itemLengthRaw) && barcodeSizeRaw) {
      var mSize = String(barcodeSizeRaw).match(/([0-9]+(?:\.[0-9]+)?)\s*mm?\s*[xX×]\s*([0-9]+(?:\.[0-9]+)?)/i);
      if (mSize) {
        if (!itemWidthRaw) itemWidthRaw = mSize[1];
        if (!itemLengthRaw) itemLengthRaw = mSize[2];
      }
    }
    var itemWidthNum = parseFloat(String(itemWidthRaw || '').replace(/,/g, ''));
    var itemLengthNum = parseFloat(String(itemLengthRaw || '').replace(/,/g, ''));
    if (Number.isFinite(itemWidthNum) && Number.isFinite(itemLengthNum) && itemWidthNum > 0 && itemLengthNum > 0 && itemWidthNum < itemLengthNum) {
      var tSwap = itemWidthRaw;
      itemWidthRaw = itemLengthRaw;
      itemLengthRaw = tSwap;
    }

    // Build section A detail items
    var detailKeys = [
      ['Packing ID',     [job.packing_display_id||'-']],
      ['Plan No',        pickFirst(sources,['plan_no','plan_number'])],
      ['Job No',         pickFirst(sources,['job_no'])],
      ['Job Name',       pickFirst(sources,['plan_name','job_name'])],
      ['Client Name',    pickFirst(sources,['client_name','customer_name'])],
      ['Order Date',     pickFirst(sources,['order_date'])],
      ['Dispatch Date',  pickFirst(sources,['dispatch_date','due_date'])],
      ['Roll No',        rollNoSummary],
      ['Department',     pickFirst(sources,['department'])],
      ['Status',         pickFirst(sources,['status'])],
      ['Order Quantity', orderQtyRaw],
      ['Production Qty', prodQtyRaw],
      ['Total Roll Value', totalRollValueRaw],
      ['Barcode in 1 roll', String(barcodePerRollDefault)],
      ['Item Width', itemWidthRaw ? String(itemWidthRaw) + ' mm' : '-'],
      ['Item Length', itemLengthRaw ? String(itemLengthRaw) + ' mm' : '-'],
      ['Paper Size', paperSizeRaw || '-'],
    ];

    var detailHtml = '<div class="op-detail-grid">' +
      detailKeys.map(function(d){
        var label = Array.isArray(d[0]) ? d[0][0] : d[0];
        var val   = Array.isArray(d[1]) ? d[1][0]  : d[1];
        return '<div class="op-detail-item"><b>'+escHtml(label)+'</b><span>'+escHtml(String(val||'-'))+'</span></div>';
      }).join('') + '</div>';

    // Build submitted box (if entry exists)
    var submittedBoxHtml = '';
    if (opEntrySubmitted) {
      submittedBoxHtml =
        '<div class="op-submitted-box">' +
        '<div style="font-size:.78rem;font-weight:900;color:#92400e;display:flex;align-items:center;gap:6px">' +
        '<span style="background:#fed7aa;color:#92400e;padding:2px 8px;border-radius:999px;font-size:.72rem">&#10003; Already Submitted</span>' +
        ' <span style="color:#64748b;font-weight:700">' + escHtml(opEntry.submitted_at||'') + '</span>' +
        '</div>' +
        '<div class="op-sb-grid">' +
        '<div class="op-sb-item"><b>Physical Production</b><span>'+escHtml(String(opEntry.packed_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Bundles</b><span>'+escHtml(String(opEntry.bundles_count||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Cartons</b><span>'+escHtml(String(opEntry.cartons_count||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Wastage</b><span>'+escHtml(String(opEntry.wastage_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Loose Qty</b><span>'+escHtml(String(opEntry.loose_qty||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Notes</b><span>'+escHtml(opEntry.notes||'-')+'</span></div>' +
        '<div class="op-sb-item"><b>Submitted By</b><span>'+escHtml(String(opEntry.operator_name||'-'))+'</span></div>' +
        '<div class="op-sb-item"><b>Submitted At</b><span>'+escHtml(String(opEntry.submitted_at||'-'))+'</span></div>' +
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
        '<div class="op-top-item"><b>Total Roll Value</b><span>' + escHtml(totalRollValueRaw) + '</span></div>' +
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
          // STEP 1: Live display metrics (always at top)
          '<div style="margin-bottom:14px;padding:12px;border:1px solid #fef3c7;border-radius:10px;background:linear-gradient(120deg,#fffbeb 0%,#fff 100%)"><div style="font-size:.74rem;font-weight:900;color:#92400e;margin-bottom:8px">Live Production Metrics</div><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Order Qty</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(orderQtyRaw) + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Production Qty</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(prodQtyRaw) + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Total Roll Value</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(totalRollValueRaw || '-') + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Barcode in 1 roll</b><span style="font-size:.95rem;font-weight:900;color:#0f172a">' + escHtml(isBarcodeMode ? '250' : '-') + '</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Shrink Bundles</b><span id="opLiveShrinkBundles" style="font-size:.95rem;font-weight:900;color:#7c3aed">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Cartons Required</b><span id="opLiveCartonsRequired" style="font-size:.95rem;font-weight:900;color:#9a3412">-</span></div>' +
            '<div style="border:1px solid #bbf7d0;border-radius:8px;padding:8px;background:#f0fdf4"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#166534;margin-bottom:2px">Physical Qty</b><span id="opLivePhysicalQty" style="font-size:.95rem;font-weight:900;color:#166534">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Physical %</b><span id="opLivePhysicalPct" style="font-size:.95rem;font-weight:900;color:#0f766e">-</span></div>' +
            '<div style="border:1px solid #fde68a;border-radius:8px;padding:8px;background:#fff"><b style="display:block;font-size:.62rem;text-transform:uppercase;color:#92400e;margin-bottom:2px">Loose</b><span id="opLiveLooseQty" style="font-size:.95rem;font-weight:900;color:#7c2d12">-</span></div>' +
          '</div></div>' +

          // STEP 2: Roll selection
          '<div style="margin-bottom:14px;padding:12px;border:1px solid #fdba74;border-radius:10px;background:linear-gradient(120deg,#fff7ed 0%,#fff 100%)">'
          + '<div style="font-size:.74rem;font-weight:900;color:#c2410c;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">Roll Selection</div>'
          + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px">' + rollSelectionHtml + '</div>'
          + '</div>' +

          // STEP 3: Per-roll helpers (conditional)
          '<div id="opHelpersSection" style="margin-bottom:14px;padding:12px;border:1px solid #fed7aa;border-radius:10px;background:linear-gradient(120deg,#fff7ed 0%,#fff 100%)">'
          + '<div style="font-size:.74rem;font-weight:900;color:#c2410c;margin-bottom:8px">Packing Calculation Helpers</div>'
          + '<div id="opPerRollOutput" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px"></div>'
          + '<div id="opMixedPanel" style="display:none;margin-top:10px;padding:10px;border:1px solid #fca5a5;border-radius:8px;background:#fff1f2">'
          + '  <div style="font-size:.72rem;font-weight:900;color:#be123c;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Mixed Carton Pool</div>'
          + '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Enable Mixed</label><input type="checkbox" id="opMixedEnable" style="margin-top:6px"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Mixed Roll/Carton</label><input type="number" id="opMixedRpc" min="0" step="1" value="0" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Pool Extra Rolls</label><input type="text" id="opMixedPool" readonly value="0" style="width:100%;padding:5px 6px;border:1px solid #fecaca;border-radius:6px;background:#fff"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Mixed Cartons</label><input type="text" id="opMixedCartons" readonly value="0" style="width:100%;padding:5px 6px;border:1px solid #fecaca;border-radius:6px;background:#fff"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Mixed Extra</label><input type="text" id="opMixedExtra" readonly value="0" style="width:100%;padding:5px 6px;border:1px solid #fecaca;border-radius:6px;background:#fff"></div>'
          + '  </div>'
          + '</div>'
          + '<div class="op-entry-form" style="margin-top:10px">'
          +   '<div class="op-field"><label>Physical Production (calculated)</label><input type="text" id="opCalcPhysicalProduction" readonly value="-" style="background:#fff7ed;font-weight:900;color:#166534;cursor:not-allowed"></div>'
          + '</div>'
          + '</div>'
          + '<div id="opHelpersHiddenMsg" style="display:none;margin:-2px 0 14px;padding:10px;border:1px dashed #fca5a5;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:.8rem;font-weight:700">Select at least one roll to view Packing Calculation Helpers.</div>' +
          '<div id="opCleanSummarySection" style="margin-bottom:14px;padding:12px;border:1px solid #bbf7d0;border-radius:10px;background:linear-gradient(120deg,#f0fdf4 0%,#fff 100%)">'
          + '<div style="font-size:.74rem;font-weight:900;color:#166534;margin-bottom:8px">Clean Summary</div>'
          + '<div id="opCleanSummaryHead" style="font-size:.8rem;color:#14532d;font-weight:700;margin-bottom:8px"></div>'
          + '<div style="overflow:auto">'
          + '  <table style="width:100%;border-collapse:collapse;min-width:520px;border:1px solid #bbf7d0">'
          + '    <thead><tr style="background:#dcfce7;color:#14532d"><th style="padding:7px;border:1px solid #bbf7d0;text-align:left;font-size:.72rem">Roll</th><th style="padding:7px;border:1px solid #bbf7d0;text-align:left;font-size:.72rem">Production Received</th><th style="padding:7px;border:1px solid #bbf7d0;text-align:left;font-size:.72rem">Carton Build Formula</th><th style="padding:7px;border:1px solid #bbf7d0;text-align:left;font-size:.72rem">Physical Output</th></tr></thead>'
          + '    <tbody id="opCleanSummaryBody"></tbody>'
          + '  </table>'
          + '</div>'
          + '</div>' +

          // STEP 3: Operator entry fields
          '<div class="op-entry-form" id="opEntryForm">' +
            '<input type="hidden" id="opPackedQty" value="' + escHtml(opEntry?String(opEntry.packed_qty||''):'') + '">' +
            '<input type="hidden" id="opBundlesCount" value="' + escHtml(opEntry?String(opEntry.bundles_count||''):'') + '">' +
            '<input type="hidden" id="opCartonsCount" value="' + escHtml(opEntry?String(opEntry.cartons_count||''):'') + '">' +
            '<input type="hidden" id="opWastageQty" value="' + escHtml(opEntry?String(opEntry.wastage_qty||''):'') + '">' +
            '<input type="hidden" id="opLooseQty" value="' + escHtml(opEntry?String(opEntry.loose_qty||''):'') + '">' +
            '<div class="op-field" style="grid-column:1/-1"><label>Notes / Remarks</label>' +
              '<textarea id="opNotes">' + escHtml(opEntry?(opEntry.notes||''):'') + '</textarea>' +
            '</div>' +
            '<div class="op-field" style="grid-column:1/-1"><label>Photo (optional, max 5 MB) — tap to take photo with camera</label>' +
              '<input type="file" id="opPhoto" accept=".jpg,.jpeg,.png,.webp,image/*" capture="environment">' +
            '</div>' +
          '</div>' +
          '<div class="op-submit-row">' +
            '<button class="op-btn-submit" id="opSubmitBtn" type="button"' + ((lockSubmittedForOperator || opEntrySubmitted) ? ' disabled style="opacity:.65;cursor:not-allowed"' : '') + '>' + (lockSubmittedForOperator ? (isHistoryLockedByStatus && !opEntrySubmitted ? 'Moved to History' : 'Submitted (Locked)') : (opEntrySubmitted ? 'Submitted' : 'Submit Entry')) + '</button>' +
            (opCanAdminOverrideEdit ? '<button class="op-btn-admin-edit" id="opAdminEditBtn" type="button" style="display:' + (opEntrySubmitted ? 'inline-flex' : 'none') + '">✎ Edit Entry (Admin)</button>' : '') +
            '<button class="op-btn-print" id="opPrintSlipBtn" type="button">Packing Slip Print</button>' +
            '<button class="op-btn-cancel-modal" id="opCancelBtn" type="button">Cancel</button>' +
          '</div>' +
          '<div class="op-msg" id="opMsg">' + (lockSubmittedForOperator ? operatorLockMessage : '') + '</div>' +
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
    var looseQtyInput = document.getElementById('opLooseQty');
    var cartonsCountInput = document.getElementById('opCartonsCount');
    var wastageQtyInput = document.getElementById('opWastageQty');
    var liveShrinkBundles = document.getElementById('opLiveShrinkBundles');
    var liveCartonsRequired = document.getElementById('opLiveCartonsRequired');
    var livePhysicalQty = document.getElementById('opLivePhysicalQty');
    var livePhysicalPct = document.getElementById('opLivePhysicalPct');
    var liveLooseQty = document.getElementById('opLiveLooseQty');
    var mixedPanel = document.getElementById('opMixedPanel');
    var mixedEnableNode = document.getElementById('opMixedEnable');
    var mixedRpcNode = document.getElementById('opMixedRpc');
    var mixedPoolNode = document.getElementById('opMixedPool');
    var mixedCartonsNode = document.getElementById('opMixedCartons');
    var mixedExtraNode = document.getElementById('opMixedExtra');
    var calcCartonsNeeded = document.getElementById('opCalcCartonsNeeded');
    var calcPhysicalProduction = document.getElementById('opCalcPhysicalProduction');
    var helpersSection = document.getElementById('opHelpersSection');
    var helpersHiddenMsg = document.getElementById('opHelpersHiddenMsg');
    var perRollOutput = document.getElementById('opPerRollOutput');
    var cleanSummarySection = document.getElementById('opCleanSummarySection');
    var cleanSummaryHead = document.getElementById('opCleanSummaryHead');
    var cleanSummaryBody = document.getElementById('opCleanSummaryBody');
    var rollSelectNodes = Array.prototype.slice.call(document.querySelectorAll('.op-roll-select'));
    var orderQtyNum = parseFloat(String(orderQtyRaw || '').replace(/,/g, '')) || 0;
    var prodQtyNum = parseFloat(String(prodQtyRaw || '').replace(/,/g, '')) || 0;

    if ((isBarcodeMode || isPaperRollTypeTab) && opEntryRollPayload && typeof opEntryRollPayload === 'object' && opEntryRollPayload.mixed && typeof opEntryRollPayload.mixed === 'object') {
      var mixedSaved = opEntryRollPayload.mixed;
      if (mixedEnableNode) mixedEnableNode.checked = (Number(mixedSaved.enabled || 0) === 1 || mixedSaved.enabled === true);
      if (mixedRpcNode) mixedRpcNode.value = String(Math.max(0, Math.floor(toNum(mixedSaved.rolls_per_carton || 0))));
      if (mixedPoolNode) mixedPoolNode.value = String(Math.max(0, Math.floor(toNum(mixedSaved.pool_extra_rolls || 0))));
      if (mixedCartonsNode) mixedCartonsNode.value = String(Math.max(0, Math.floor(toNum(mixedSaved.mixed_cartons || 0))));
      if (mixedExtraNode) mixedExtraNode.value = String(Math.max(0, Math.floor(toNum(mixedSaved.mixed_extra_rolls || 0))));
    }

    function toNum(v) {
      var n = parseFloat(String(v || '').replace(/,/g, ''));
      return Number.isFinite(n) ? n : 0;
    }

    function normCsizeText(v) {
      var t = String(v || '').toLowerCase().replace(/\s+/g, '');
      if (t === '75' || t === '75mm') return '75mm';
      if (t === '57x15' || t === '57x25' || t === '78x25' || t === 'barcode' || t === 'medicine') return t;
      return '75mm';
    }

    function getDefaultDistributedQty(totalQty, totalRollCount, rollIndex) {
      var safeTotal = Math.max(0, Math.floor(toNum(totalQty)));
      var safeCount = Math.max(1, Math.floor(toNum(totalRollCount)));
      var baseQty = Math.floor(safeTotal / safeCount);
      var remainder = safeTotal % safeCount;
      return baseQty + (rollIndex < remainder ? 1 : 0);
    }

    function resolveBarcodePackState(st, qtyInput, bpr) {
      // total_rolls = ceil(production_qty / bpr), or manual override
      var totalRollsBarcode = (Object.prototype.hasOwnProperty.call(st, 'total_rolls') && Math.floor(toNum(st.total_rolls)) > 0)
        ? Math.max(0, Math.floor(toNum(st.total_rolls)))
        : Math.max(0, Math.ceil(qtyInput / Math.max(1, bpr)));

      // rolls_per_carton: user input
      var rollsPerCarton = Object.prototype.hasOwnProperty.call(st, 'rolls_per_carton')
        ? Math.max(0, Math.floor(toNum(st.rolls_per_carton)))
        : 0;

      // cartons = floor(total_rolls / rolls_per_carton)  — always auto
      var cartonsBarcode = (rollsPerCarton > 0)
        ? Math.floor(totalRollsBarcode / rollsPerCarton)
        : 0;

      // extra_rolls = total_rolls - cartons * rolls_per_carton  — display only
      var extraRolls = (rollsPerCarton > 0)
        ? (totalRollsBarcode - cartonsBarcode * rollsPerCarton)
        : 0;

      return {
        totalRollsBarcode: totalRollsBarcode,
        rollsPerCarton: rollsPerCarton,
        cartonsBarcode: cartonsBarcode,
        extraRolls: extraRolls,
      };
    }

    function computeMixedBarcodePool(rows, sharedRpc) {
      var pool = 0;
      (rows || []).forEach(function(row) {
        pool += Math.max(0, Math.floor(toNum(row && row.extraRolls != null ? row.extraRolls : 0)));
      });
      var rpc = Math.max(0, Math.floor(toNum(sharedRpc)));
      if (rpc <= 0) {
        return {
          poolExtraRolls: pool,
          mixedCartons: 0,
          mixedExtraRolls: pool
        };
      }
      return {
        poolExtraRolls: pool,
        mixedCartons: Math.floor(pool / rpc),
        mixedExtraRolls: pool % rpc
      };
    }

    function renderPerRollCards(selectedRolls) {
      if (!perRollOutput) return;
      var htmlCards = selectedRolls.map(function(roll, idx) {
        var key = String(roll.rollNo || ('roll-' + String(idx)));
        var st = rollLooseOverrides[key] || {};
        if (isBarcodeMode) {
          var qtyIn = Math.max(0, Math.floor(Math.min(toNum(roll.productionQty), toNum(roll.availableQty))));
          var bpr = Object.prototype.hasOwnProperty.call(st, 'bpr') ? Math.max(1, Math.floor(toNum(st.bpr))) : barcodePerRollDefault;
          var packCalc = resolveBarcodePackState(st, qtyIn, bpr);
          var totalRollsBarcode = packCalc.totalRollsBarcode;
          var rollsPerCarton = packCalc.rollsPerCarton;
          var cartonsBarcode = packCalc.cartonsBarcode;
          var extraRolls = packCalc.extraRolls;
          var extraPieces = Object.prototype.hasOwnProperty.call(st, 'extra_pcs') ? Math.max(0, Math.floor(toNum(st.extra_pcs))) : 0;
          var csizeText = String(st.csize_text || '75 mm').trim() || '75 mm';
          var csizeBadge = opCartonBadgeHtml(csizeText);
          // Physical = total_rolls × bpr + extra_pcs
          var physical = Math.max(0, totalRollsBarcode * bpr + extraPieces);
          return ''
            + '<div style="border:1px solid #fdba74;border-radius:10px;padding:10px;background:#fff">'
            + '  <div style="font-size:.74rem;font-weight:900;color:#9a3412;margin-bottom:8px">Roll: ' + escHtml(String(roll.rollNo || '-')) + '</div>'
            + '  <div style="display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:8px">'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Barcode in 1 roll (bpr)</label><input type="number" class="op-bc-per-roll" data-roll-key="' + escHtml(key) + '" min="1" step="1" value="' + String(bpr) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Total rolls <span style="font-weight:500;color:#94a3b8">(auto=ceil(qty÷bpr))</span></label><input type="number" class="op-bc-total-rolls-input" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(totalRollsBarcode) + '" style="width:100%;padding:5px 6px;border:1px solid #86efac;border-radius:6px"></div>'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Roll in 1 carton</label><input type="number" class="op-bc-rolls-per-carton-input" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(rollsPerCarton) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#0f766e">Total cartons <span style="font-weight:500;color:#94a3b8">(auto)</span></label><div style="width:100%;padding:5px 8px;border:1px solid #99f6e4;border-radius:6px;background:#f0fdfa;font-size:.85rem;font-weight:900;color:#0f766e;min-height:28px" class="op-bc-cartons-display" data-roll-key="' + escHtml(key) + '">' + String(cartonsBarcode) + '</div></div>'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#7c3aed">Extra rolls <span style="font-weight:500;color:#94a3b8">(auto)</span></label><div style="width:100%;padding:5px 8px;border:1px solid #ddd6fe;border-radius:6px;background:#f5f3ff;font-size:.85rem;font-weight:900;color:#7c3aed;min-height:28px" class="op-bc-extra-rolls-display" data-roll-key="' + escHtml(key) + '">' + String(extraRolls) + '</div></div>'
            + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#92400e">Extra pieces (loose)</label><input type="number" class="op-bc-extra-pcs-input" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(extraPieces) + '" style="width:100%;padding:5px 6px;border:1px solid #fdba74;border-radius:6px"></div>'
            + '    <div style="grid-column:span 2"><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Carton size ' + csizeBadge + '</label><input type="text" class="op-bc-csize-input" data-roll-key="' + escHtml(key) + '" value="' + escHtml(csizeText) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
            + '  </div>'
            + '  <div style="margin-top:8px;padding:7px 9px;border:1px solid #86efac;border-radius:7px;background:#dcfce7;color:#166534;font-size:.8rem;font-weight:900">'
            + '    Physical: <span class="op-roll-total-rolls" data-roll-key="' + escHtml(key) + '">' + String(physical) + '</span>'
            + '    <span style="font-weight:500;font-size:.72rem;color:#16a34a;margin-left:8px">(' + String(totalRollsBarcode) + ' rolls × ' + String(bpr) + ' bpr' + (extraPieces > 0 ? ' + ' + String(extraPieces) + ' pcs' : '') + ')'
            + '    </span>'
            + '  </div>'
            + '</div>';
        }
        var rps = Math.max(1, Math.floor(toNum(st.rps || 5)));
        var rpc = Math.max(1, Math.floor(toNum(st.rpc || 50)));
        var csize = 75;
        var qty = Math.max(0, Math.min(toNum(roll.productionQty), toNum(roll.availableQty)));
        var autoExtra = qty % rpc;
        var cartons = Object.prototype.hasOwnProperty.call(st, 'cartons') ? Math.max(0, Math.floor(toNum(st.cartons))) : Math.floor(qty / rpc);
        var extra = Object.prototype.hasOwnProperty.call(st, 'extra') ? Math.max(0, Math.floor(toNum(st.extra))) : autoExtra;
        var totalRolls = Math.max(0, cartons * rpc + extra);
        var csizeNow = normCsizeText(st.csize_text || '75mm');
        var csizeBadgeNonBarcode = opCartonBadgeHtml(csizeNow);
        return ''
          + '<div style="border:1px solid #fdba74;border-radius:10px;padding:10px;background:#fff">'
          + '  <div style="font-size:.74rem;font-weight:900;color:#9a3412;margin-bottom:8px">Roll: ' + escHtml(String(roll.rollNo || '-')) + '</div>'
          + '  <div style="display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:8px">'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Rolls per shrink wrap</label><input type="number" class="op-roll-rps" data-roll-key="' + escHtml(key) + '" min="1" step="1" value="' + String(rps) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Rolls per carton</label><input type="number" class="op-roll-rpc-input" data-roll-key="' + escHtml(key) + '" min="1" step="1" value="' + String(rpc) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Carton size (mm) ' + csizeBadgeNonBarcode + '</label><select class="op-roll-csize" data-roll-key="' + escHtml(key) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc">' + ['57x15','57x25','78x25','75mm','Barcode','Medicine'].map(function(s){var sel=(s.toLowerCase()===csizeNow.toLowerCase()?' selected':'');return '<option value="'+s+'"'+sel+'>'+s+'</option>';}).join('') + '</select></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Cartons</label><input type="number" class="op-roll-cartons-input" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(cartons) + '" style="width:100%;padding:5px 6px;border:1px solid #86efac;border-radius:6px"></div>'
          + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#92400e">Extra rolls</label><input type="number" class="op-roll-extra-input" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(extra) + '" style="width:100%;padding:5px 6px;border:1px solid #fdba74;border-radius:6px"></div>'
          + '  </div>'
          + '  <div style="margin-top:8px;padding:7px 9px;border:1px solid #86efac;border-radius:7px;background:#dcfce7;color:#166534;font-size:.8rem;font-weight:900">Total Rolls: <span class="op-roll-total-rolls" data-roll-key="' + escHtml(key) + '">' + String(totalRolls) + '</span></div>'
          + '</div>';
      }).join('');
      perRollOutput.innerHTML = htmlCards;
    }

    function bindPerRollCardEvents() {
      if (!perRollOutput) return;
      function saveAndRecalc(input, field, asText) {
        var key = String(input.getAttribute('data-roll-key') || '').trim();
        if (!key) return;
        if (!rollLooseOverrides[key] || typeof rollLooseOverrides[key] !== 'object') rollLooseOverrides[key] = {};
        if (asText) {
          rollLooseOverrides[key][field] = String(input.value || '').trim();
          updateLiveMetrics();
          return;
        }
        var raw = Math.floor(toNum(input.value));
        if (field === 'rps' || field === 'rpc' || field === 'csize') {
          rollLooseOverrides[key][field] = Math.max(1, raw);
        } else {
          rollLooseOverrides[key][field] = Math.max(0, raw);
        }

        // For barcode: bpr change resets total_rolls auto-calc (remove override)
        if (isBarcodeMode && field === 'bpr') {
          delete rollLooseOverrides[key].total_rolls;
        }
        updateLiveMetrics();
      }

      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-roll-rps')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'rps'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-roll-rpc-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'rpc'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-roll-csize')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'csize_text', true); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-roll-cartons-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'cartons'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-roll-extra-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'extra'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-per-roll')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'bpr'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-total-rolls-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'total_rolls'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-cartons-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'cartons'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-rolls-per-carton-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'rolls_per_carton'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-extra-pcs-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'extra_pcs'); });
      });
      Array.prototype.slice.call(perRollOutput.querySelectorAll('.op-bc-csize-input')).forEach(function(input) {
        input.addEventListener('change', function() { saveAndRecalc(input, 'csize_text', true); });
      });
    }

    function updateLiveMetrics() {
      opLoadCartonStatus(false);

      var selectedRolls = [];
      if (rollSelectNodes.length) {
        rollSelectNodes.forEach(function(node) {
          if (!node.checked) return;
          var idx = Number(node.getAttribute('data-roll-index'));
          if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return;
          selectedRolls.push(rollLots[idx]);
        });
      }

      if (!selectedRolls.length) {
        if (helpersSection) helpersSection.style.display = 'none';
        if (helpersHiddenMsg) helpersHiddenMsg.style.display = 'block';
        if (cleanSummarySection) cleanSummarySection.style.display = 'none';
        if (calcCartonsNeeded) calcCartonsNeeded.value = '-';
        if (calcPhysicalProduction) calcPhysicalProduction.value = '-';
        if (liveShrinkBundles) liveShrinkBundles.textContent = '-';
        if (liveCartonsRequired) liveCartonsRequired.textContent = '-';
        if (livePhysicalQty) livePhysicalQty.textContent = '-';
        if (livePhysicalPct) livePhysicalPct.textContent = '-';
        if (liveLooseQty) liveLooseQty.textContent = '-';
        if (packedQtyInput) packedQtyInput.value = '';
        if (cartonsCountInput) cartonsCountInput.value = '';
        if (bundlesCountInput) bundlesCountInput.value = '';
        if (looseQtyInput) looseQtyInput.value = '';
        if (perRollOutput) perRollOutput.innerHTML = '';
        if (mixedPoolNode) mixedPoolNode.value = '0';
        if (mixedCartonsNode) mixedCartonsNode.value = '0';
        if (mixedExtraNode) mixedExtraNode.value = '0';
        currentOpBatches = [];
        return;
      }

      if (helpersSection) helpersSection.style.display = '';
      if (helpersHiddenMsg) helpersHiddenMsg.style.display = 'none';
      if (cleanSummarySection) cleanSummarySection.style.display = '';
      if (mixedPanel) mixedPanel.style.display = (isBarcodeMode || isPaperRollTypeTab) ? '' : 'none';
      var splitMeta = selectedRolls.map(function(roll) {
        var rollNo = String(roll && roll.rollNo ? roll.rollNo : '').trim();
        var qtyVal = Math.max(0, Math.min(toNum(roll.productionQty), toNum(roll.availableQty)));
        var parentKey = rollNo.replace(/-[A-Za-z0-9]+$/, '');
        return { rollNo: rollNo, qty: qtyVal, parentKey: parentKey };
      });
      var parentKeySet = {};
      var qtySet = {};
      splitMeta.forEach(function(m) {
        if (m.parentKey) parentKeySet[m.parentKey] = true;
        qtySet[String(m.qty)] = true;
      });
      var parentKeyCount = Object.keys(parentKeySet).length;
      var qtyCount = Object.keys(qtySet).length;
      var sharedSplitMode = selectedRolls.length > 1 && parentKeyCount === 1 && qtyCount === 1;
      var sharedReceivedQty = sharedSplitMode && splitMeta.length ? splitMeta[0].qty : 0;

      renderPerRollCards(selectedRolls.map(function(roll, idx) {
        if (!sharedSplitMode) return roll;
        return Object.assign({}, roll, {
          productionQty: getDefaultDistributedQty(sharedReceivedQty, selectedRolls.length, idx),
          availableQty: getDefaultDistributedQty(sharedReceivedQty, selectedRolls.length, idx)
        });
      }));
      bindPerRollCardEvents();

      var totalCartons = 0;
      var totalLoose = 0;
      var totalShrinkBundles = 0;
      var physicalProduction = 0;
      var totalProductionReceived = 0;
      var batches = [];
      var summaryRows = [];
      var barcodeRowsForMix = [];
      var paperRowsForMix = [];
      var barcodeExtraPiecesTotal = 0;
      var barcodeSharedRpc = 0;
      var paperSharedRpc = 0;

      selectedRolls.forEach(function(roll, rollIdx) {
        var rollKey = String(roll.rollNo || ('roll-' + String(rollIdx)));
        var st = rollLooseOverrides[rollKey] || {};
        if (isBarcodeMode) {
          // Apply sharedSplitMode: distribute total qty evenly across rolls (same as non-barcode)
          var qtyInput = sharedSplitMode
            ? getDefaultDistributedQty(sharedReceivedQty, selectedRolls.length, rollIdx)
            : Math.max(0, Math.floor(Math.min(toNum(roll.productionQty), toNum(roll.availableQty))));
          var bpr = Object.prototype.hasOwnProperty.call(st, 'bpr') ? Math.max(1, Math.floor(toNum(st.bpr))) : barcodePerRollDefault;
          var packCalc = resolveBarcodePackState(st, qtyInput, bpr);
          var totalRollsBarcode = packCalc.totalRollsBarcode;
          var rollsPerCarton = packCalc.rollsPerCarton;
          var cartonsBarcode = packCalc.cartonsBarcode;
          var extraRolls = packCalc.extraRolls;
          var extraPieces = Object.prototype.hasOwnProperty.call(st, 'extra_pcs') ? Math.max(0, Math.floor(toNum(st.extra_pcs))) : 0;
          var csizeText = String(st.csize_text || '75 mm').trim() || '75 mm';
          // Physical = total_rolls × bpr + extra_pcs
          var rollPhysical = Math.max(0, totalRollsBarcode * bpr + extraPieces);

          totalShrinkBundles += totalRollsBarcode;
          totalCartons += cartonsBarcode;
          barcodeExtraPiecesTotal += extraPieces;
          barcodeRowsForMix.push({
            rollNo: String(roll.rollNo || '-'),
            extraRolls: extraRolls
          });
          if (rollsPerCarton > 0 && barcodeSharedRpc <= 0) {
            barcodeSharedRpc = rollsPerCarton;
          }
          physicalProduction += rollPhysical;
          if (!sharedSplitMode) totalProductionReceived += qtyInput;

          // Update per-roll physical display
          var totalRollsSpanBarcode = perRollOutput ? perRollOutput.querySelector('.op-roll-total-rolls[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
          if (totalRollsSpanBarcode) totalRollsSpanBarcode.textContent = String(rollPhysical);
          // Update auto-calc displays
          var cartonsDisplay = perRollOutput ? perRollOutput.querySelector('.op-bc-cartons-display[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
          if (cartonsDisplay) cartonsDisplay.textContent = String(cartonsBarcode);
          var extraRollsDisplay = perRollOutput ? perRollOutput.querySelector('.op-bc-extra-rolls-display[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
          if (extraRollsDisplay) extraRollsDisplay.textContent = String(extraRolls);
          // Update total_rolls input if it was auto-calculated (no manual override set)
          if (!Object.prototype.hasOwnProperty.call(st, 'total_rolls') || st.total_rolls === 0) {
            var totalRollsInput = perRollOutput ? perRollOutput.querySelector('.op-bc-total-rolls-input[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
            if (totalRollsInput) totalRollsInput.value = String(totalRollsBarcode);
          }

          summaryRows.push(
            '<tr>'
            + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#14532d;font-weight:700">' + escHtml(String(roll.rollNo || '-')) + '</td>'
            + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#0f172a">' + escHtml(String(qtyInput)) + '</td>'
            + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#334155">'
            + escHtml(String(totalRollsBarcode)) + ' rolls × ' + escHtml(String(bpr)) + ' bpr'
            + ' | Cartons: ' + escHtml(String(cartonsBarcode))
            + ' | Extra rolls: ' + escHtml(String(extraRolls))
            + (extraPieces > 0 ? ' | Extra pcs: ' + escHtml(String(extraPieces)) : '')
            + '</td>'
            + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#166534;font-weight:900">' + escHtml(String(rollPhysical)) + '</td>'
            + '</tr>'
          );

          batches.push({
            batchNo: rollIdx + 1,
            label: String(roll.rollNo || '-'),
            type: 'ROLL',
            cartons: cartonsBarcode,
            shrinkBundles: totalRollsBarcode,
            looseUsed: extraPieces,
            shortage: 0
          });
          return;
        }
        var rps = Math.max(1, Math.floor(toNum(st.rps || 5)));
        var rpc = Math.max(1, Math.floor(toNum(st.rpc || 50)));
        var qty = Math.max(0, Math.min(toNum(roll.productionQty), toNum(roll.availableQty)));
        var normalizedQty = sharedSplitMode
          ? getDefaultDistributedQty(sharedReceivedQty, selectedRolls.length, rollIdx)
          : qty;
        if (!sharedSplitMode) totalProductionReceived += qty;
        var autoExtra = normalizedQty % rpc;
        var rollCartons = Object.prototype.hasOwnProperty.call(st, 'cartons') ? Math.max(0, Math.floor(toNum(st.cartons))) : Math.floor(normalizedQty / rpc);
        var rollExtra = Object.prototype.hasOwnProperty.call(st, 'extra') ? Math.max(0, Math.floor(toNum(st.extra))) : autoExtra;
        var rollTotalRolls = Math.max(0, rollCartons * rpc + rollExtra);
        var rollShrink = Math.floor(rollTotalRolls / rps);
        totalShrinkBundles += rollShrink;
        totalCartons += rollCartons;
        totalLoose += rollExtra;
        paperRowsForMix.push({
          rollNo: String(roll.rollNo || '-'),
          extraRolls: rollExtra
        });
        if (rpc > 0 && paperSharedRpc <= 0) {
          paperSharedRpc = rpc;
        }
        physicalProduction += rollTotalRolls;

        var totalRollsSpan = perRollOutput ? perRollOutput.querySelector('.op-roll-total-rolls[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
        if (totalRollsSpan) totalRollsSpan.textContent = String(rollTotalRolls);

        summaryRows.push(
          '<tr>'
          + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#14532d;font-weight:700">' + escHtml(String(roll.rollNo || '-')) + '</td>'
          + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#0f172a">' + escHtml(String(sharedSplitMode ? normalizedQty : qty)) + '</td>'
          + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#334155">' + escHtml(String(rollCartons)) + ' x ' + escHtml(String(rpc)) + ' + ' + escHtml(String(rollExtra)) + '</td>'
          + '<td style="padding:7px;border:1px solid #dcfce7;font-size:.78rem;color:#166534;font-weight:900">' + escHtml(String(rollTotalRolls)) + '</td>'
          + '</tr>'
        );

        batches.push({
          batchNo: rollIdx + 1,
          label: String(roll.rollNo || '-'),
          type: 'ROLL',
          cartons: rollCartons,
          shrinkBundles: rollShrink,
          looseUsed: rollExtra,
          shortage: 0
        });
      });

      if (sharedSplitMode) totalProductionReceived = sharedReceivedQty;

      if (isBarcodeMode || isPaperRollTypeTab) {
        var mixedEnabled = !!(mixedEnableNode && mixedEnableNode.checked);
        var rpcInputVal = mixedRpcNode ? Math.max(0, Math.floor(toNum(mixedRpcNode.value))) : 0;
        var effectiveRpc = rpcInputVal > 0
          ? rpcInputVal
          : (isBarcodeMode ? barcodeSharedRpc : paperSharedRpc);
        var mixRows = isBarcodeMode ? barcodeRowsForMix : paperRowsForMix;
        var mix = computeMixedBarcodePool(mixRows, effectiveRpc);

        if (mixedPoolNode) mixedPoolNode.value = String(mix.poolExtraRolls);
        if (mixedCartonsNode) mixedCartonsNode.value = String(mix.mixedCartons);
        if (mixedExtraNode) mixedExtraNode.value = String(mix.mixedExtraRolls);

        if (mixedEnabled && effectiveRpc > 0) {
          totalCartons += mix.mixedCartons;
          totalLoose = isBarcodeMode ? (mix.mixedExtraRolls + barcodeExtraPiecesTotal) : mix.mixedExtraRolls;
          if (mix.mixedCartons > 0 || mix.mixedExtraRolls > 0) {
            var mixLabel = 'MIXED';
            var mixSources = mixRows.map(function(row) { return String(row.rollNo || '').trim(); }).filter(function(v) { return v !== ''; });
            if (mixSources.length) {
              mixLabel += ' (' + mixSources.join(', ') + ')';
            }
            batches.push({
              batchNo: batches.length + 1,
              label: mixLabel,
              type: 'MIXED',
              cartons: mix.mixedCartons,
              shrinkBundles: 0,
              looseUsed: mix.mixedExtraRolls,
              shortage: 0
            });
          }
        } else {
          if (isBarcodeMode) {
            totalLoose = barcodeExtraPiecesTotal;
          }
        }
      }

      var physicalPct = orderQtyNum > 0 ? ((physicalProduction / orderQtyNum) * 100) : 0;

      if (cleanSummaryBody) {
        summaryRows.push(
          '<tr style="background:#f0fdf4">'
          + '<td style="padding:7px;border:1px solid #bbf7d0;font-size:.8rem;color:#14532d;font-weight:900">TOTAL</td>'
          + '<td style="padding:7px;border:1px solid #bbf7d0;font-size:.8rem;color:#14532d;font-weight:900">' + escHtml(String(totalProductionReceived)) + '</td>'
          + '<td style="padding:7px;border:1px solid #bbf7d0;font-size:.8rem;color:#14532d;font-weight:900">-</td>'
          + '<td style="padding:7px;border:1px solid #bbf7d0;font-size:.8rem;color:#166534;font-weight:900">' + escHtml(String(physicalProduction)) + '</td>'
          + '</tr>'
        );
        cleanSummaryBody.innerHTML = summaryRows.join('');
      }
      if (cleanSummaryHead) {
        cleanSummaryHead.textContent = 'Production Received: ' + String(totalProductionReceived) + ' | Physical Output: ' + String(physicalProduction) + ' | Difference: ' + String(totalProductionReceived - physicalProduction);
      }

      if (packedQtyInput) packedQtyInput.value = String(physicalProduction);
      if (cartonsCountInput) cartonsCountInput.value = String(totalCartons);
      if (bundlesCountInput) bundlesCountInput.value = String(totalShrinkBundles);
      if (wastageQtyInput) wastageQtyInput.value = '';
      currentOpBatches = batches;

      if (calcCartonsNeeded) calcCartonsNeeded.value = totalCartons.toLocaleString('en-IN');
      if (calcPhysicalProduction) calcPhysicalProduction.value = physicalProduction.toLocaleString('en-IN');
      if (liveShrinkBundles) liveShrinkBundles.textContent = totalShrinkBundles.toLocaleString('en-IN');
      if (liveCartonsRequired) liveCartonsRequired.textContent = totalCartons.toLocaleString('en-IN');
      if (livePhysicalQty) livePhysicalQty.textContent = physicalProduction.toLocaleString('en-IN');
      if (livePhysicalPct) livePhysicalPct.textContent = physicalPct.toFixed(1) + '%';
      if (liveLooseQty) liveLooseQty.textContent = totalLoose.toLocaleString('en-IN');
      if (looseQtyInput) looseQtyInput.value = String(totalLoose);
    }

    if (rollSelectNodes.length) {
      rollSelectNodes.forEach(function(node) {
        node.addEventListener('change', updateLiveMetrics);
      });
    }
    if (mixedEnableNode) mixedEnableNode.addEventListener('change', updateLiveMetrics);
    if (mixedRpcNode) mixedRpcNode.addEventListener('change', updateLiveMetrics);
    updateLiveMetrics();

    // Submit handler
    var submitBtn = document.getElementById('opSubmitBtn');
    var printBtn  = document.getElementById('opPrintSlipBtn');
    var cancelBtn = document.getElementById('opCancelBtn');
    var msgDiv    = document.getElementById('opMsg');
    if (opEntrySubmitted || lockSubmittedForOperator) {
      var lockIds = ['opNotes','opPhoto'];
      lockIds.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.disabled = true;
      });
      Array.prototype.slice.call(document.querySelectorAll('.op-roll-rps,.op-roll-rpc-input,.op-roll-csize,.op-roll-cartons-input,.op-roll-extra-input,.op-roll-select')).forEach(function(el) {
        el.disabled = true;
      });
    }

    // ── Admin Edit Entry button ──
    var adminEditBtn = document.getElementById('opAdminEditBtn');
    var adminEditMode = false;
    var helperInputSel = '.op-roll-rps,.op-roll-rpc-input,.op-roll-csize,.op-roll-cartons-input,.op-roll-extra-input,.op-roll-select';
    function syncSubmitButtonState() {
      if (!submitBtn) return;
      if (lockSubmittedForOperator) {
        submitBtn.disabled = true;
        submitBtn.textContent = (isHistoryLockedByStatus && !opEntrySubmitted) ? 'Moved to History' : 'Submitted (Locked)';
        submitBtn.style.opacity = '0.65';
        submitBtn.style.cursor = 'not-allowed';
        return;
      }
      if (adminEditMode) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Update Entry';
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
        return;
      }
      if (opEntrySubmitted && opCanAdminOverrideEdit) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitted';
        submitBtn.style.opacity = '0.65';
        submitBtn.style.cursor = 'not-allowed';
        return;
      }
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Entry';
      submitBtn.style.opacity = '1';
      submitBtn.style.cursor = 'pointer';
    }

    syncSubmitButtonState();

    function applyAdminEditLockState(isEditMode) {
      var editLockIds = ['opNotes', 'opPhoto'];
      if (isEditMode) {
        editLockIds.forEach(function(id) {
          var el = document.getElementById(id);
          if (el) { el.disabled = false; el.style.backgroundColor = ''; el.style.cursor = ''; }
        });
        Array.prototype.slice.call(document.querySelectorAll(helperInputSel)).forEach(function(el) {
          el.disabled = false; el.style.cursor = ''; el.style.opacity = '';
        });
        syncSubmitButtonState();
        if (adminEditBtn) {
          adminEditBtn.textContent = '✕ Cancel Edit';
          adminEditBtn.style.backgroundColor = '#dc2626';
        }
        if (msgDiv) { msgDiv.textContent = 'Admin edit mode enabled. Update values and press Update Entry.'; msgDiv.style.color = '#7c3aed'; }
      } else {
        editLockIds.forEach(function(id) {
          var el = document.getElementById(id);
          if (el) { el.disabled = true; }
        });
        Array.prototype.slice.call(document.querySelectorAll(helperInputSel)).forEach(function(el) {
          el.disabled = true;
        });
        syncSubmitButtonState();
        if (adminEditBtn) {
          adminEditBtn.textContent = '✎ Edit Entry (Admin)';
          adminEditBtn.style.backgroundColor = '#7c3aed';
        }
        if (msgDiv) { msgDiv.textContent = 'Edit cancelled.'; msgDiv.style.color = '#78350f'; }
      }
    }
    if (adminEditBtn) {
      adminEditBtn.addEventListener('click', function() {
        adminEditMode = !adminEditMode;
        applyAdminEditLockState(adminEditMode);
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
      var firstSelectedRoll = null;
      if (rollSelectNodes.length) {
        rollSelectNodes.some(function(node) {
          if (!node.checked) return false;
          var idx = Number(node.getAttribute('data-roll-index'));
          if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return false;
          firstSelectedRoll = rollLots[idx];
          return true;
        });
      }
      var firstKey = firstSelectedRoll ? String(firstSelectedRoll.rollNo || 'roll-0') : '';
      var firstState = (firstKey && rollLooseOverrides[firstKey] && typeof rollLooseOverrides[firstKey] === 'object') ? rollLooseOverrides[firstKey] : {};
      var rollsPerShrinkText = String(Math.max(1, Math.floor(toNum(firstState.rps || 5))));
      var rollsPerCartonText = String(Math.max(1, Math.floor(toNum(firstState.rpc || 50))));
      var cartonSizeText = '75';
      var cartonsInputText = String(Math.max(0, Math.floor(toNum(firstState.cartons != null ? firstState.cartons : cartonsRequiredText || 0))));
      var looseQtyEditableText = String((document.getElementById('opLooseQty')?.value || '').trim() || '-');
      var extraRollsText = String(Math.max(0, Math.floor(toNum(firstState.extra != null ? firstState.extra : looseQtyEditableText || 0))));
      var physicalProductionCalcText = String((document.getElementById('opCalcPhysicalProduction')?.value || '').trim() || '-');
      var selectedRollRows = [];
      if (rollSelectNodes.length) {
        rollSelectNodes.forEach(function(node) {
          if (!node.checked) return;
          var idx = Number(node.getAttribute('data-roll-index'));
          if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return;
          var lot = rollLots[idx] || {};
          var lotKey = String(lot.rollNo || ('roll-' + String(idx)));
          var lotState = (rollLooseOverrides[lotKey] && typeof rollLooseOverrides[lotKey] === 'object') ? rollLooseOverrides[lotKey] : {};
          var lotProdQty = Math.max(0, Math.floor(toNum(lot.productionQty || lot.qty || 0)));
          var lotRpc = Math.max(1, Math.floor(toNum(lotState.rpc || 50)));
          var lotCartons = Math.max(0, Math.floor(toNum(lotState.cartons || 0)));
          var lotExtra = Math.max(0, Math.floor(toNum(lotState.extra || 0)));
          var lotPhysical = (lotCartons * lotRpc) + lotExtra;
          selectedRollRows.push({
            rollNo: String(lot.rollNo || ('Roll ' + String(idx + 1))),
            prodQty: String(lotProdQty),
            formula: String(lotCartons) + ' x ' + String(lotRpc) + ' + ' + String(lotExtra),
            physical: String(lotPhysical)
          });
        });
      }
      var rollWiseSeparationHtml = selectedRollRows.length
        ? (
          '  <div class="block"><p class="title">Roll-wise Separation</p>' +
          '    <table><thead><tr><th>Roll No</th><th>Production Received</th><th>Packing Formula (Cartons x Rolls/Carton + Extra)</th><th>Physical Output</th></tr></thead><tbody>' +
          selectedRollRows.map(function(row) {
            return '<tr><td>' + escHtml(row.rollNo) + '</td><td>' + escHtml(row.prodQty) + '</td><td>' + escHtml(row.formula) + '</td><td>' + escHtml(row.physical) + '</td></tr>';
          }).join('') +
          '    </tbody></table>' +
          '  </div>'
        )
        : '';
      var batchRows = (Array.isArray(currentOpBatches) && currentOpBatches.length) ? currentOpBatches : [{ batchNo: 1, label: jobNo, cartons: cartonsRequiredText, shrinkBundles: shrinkBundlesText, shortage: 0 }];

      var submittedBy = opEntry && opEntry.operator_name ? String(opEntry.operator_name) : currentOperatorName;
      var submittedAt = opEntry && opEntry.submitted_at ? String(opEntry.submitted_at) : new Date().toLocaleString();
      var qrDataUrl = buildSlipQrDataUrl(jobNo);

      var multiBatchSheets = batchRows.map(function(batch, idx) {
        var batchLabel = String(batch.label || ('Batch ' + String(idx + 1)));
        var batchCartons = String(batch.cartons == null ? '-' : batch.cartons);
        var batchBundles = String(batch.shrinkBundles == null ? '-' : batch.shrinkBundles);
        var batchExtraRolls = String(batch.looseUsed == null ? '-' : batch.looseUsed);
        var batchShortage = Number(batch.shortage || 0);
        return ''
          + '<div class="sheet" style="' + (idx < batchRows.length - 1 ? 'margin-bottom:12px;page-break-after:always;' : '') + '">'
          + '  <div class="hdr"><div><h1>Packing Job Card</h1><div class="sub">Batch Slip</div><div style="margin-top:8px"><span class="pill">' + escHtml(batchLabel) + '</span></div></div><div class="qr-wrap">' + (qrDataUrl ? ('<img src="' + escHtml(qrDataUrl) + '" alt="Job QR">') : '<div class="qr-na">QR unavailable</div>') + '</div></div>'
          + '  <div class="meta">'
          + '    <div class="m"><b>Job No</b><span>' + escHtml(jobNo) + '</span></div>'
          + '    <div class="m"><b>Batch</b><span>' + escHtml(batchLabel) + '</span></div>'
          + '    <div class="m"><b>Client</b><span>' + escHtml(clientName) + '</span></div>'
          + '  </div>'
          + '  <div class="block"><p class="title">Batch Summary</p>'
          + '    <table><thead><tr><th>Metric</th><th>Value</th><th>Metric</th><th>Value</th></tr></thead><tbody>'
          + '      <tr><td>Batch Cartons</td><td>' + escHtml(batchCartons) + '</td><td>Batch Bundles</td><td>' + escHtml(batchBundles) + '</td></tr>'
          + '      <tr><td>Extra Rolls</td><td>' + escHtml(batchExtraRolls) + '</td><td>Submitted By</td><td>' + escHtml(submittedBy) + '</td></tr>'
          + '      <tr><td>Shortage Qty</td><td style="color:' + (batchShortage > 0 ? '#991b1b' : '#166534') + ';font-weight:900">' + escHtml(String(batchShortage)) + '</td><td>Submitted At</td><td>' + escHtml(submittedAt) + '</td></tr>'
          + '    </tbody></table>'
          + '  </div>'
          + '  <div class="ftr"><span>Shree Label ERP - Packing Slip</span><span>Printed: ' + escHtml(new Date().toLocaleString()) + '</span></div>'
          + '</div>';
      }).join('');

      var slipHtml = (batchRows.length > 1 ? '' : '') +
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
        (batchRows.length > 1 ? multiBatchSheets : '<div class="sheet">' +
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
        '      <tr><td>Rolls per shrink wrap</td><td>' + escHtml(rollsPerShrinkText) + '</td><td>Rolls per carton</td><td>' + escHtml(rollsPerCartonText) + '</td></tr>' +
        '      <tr><td>Carton size (mm)</td><td>' + escHtml(cartonSizeText) + '</td><td>Cartons</td><td>' + escHtml(cartonsInputText) + '</td></tr>' +
        '      <tr><td>Extra rolls</td><td>' + escHtml(extraRollsText) + '</td><td>Physical Production (calculated)</td><td>' + escHtml(physicalProductionCalcText) + '</td></tr>' +
        '    </tbody></table>' +
        '  </div>' +
        rollWiseSeparationHtml +
        '  <div class="block"><p class="title">Operator Notes</p>' +
        '    <div class="notes">' + escHtml(notesText) + '</div>' +
        '  </div>' +
        '  <div class="sig"><div class="sig-box">Operator Signature</div><div class="sig-box">Manager Signature</div></div>' +
        '  <div class="ftr"><span>Shree Label ERP - Packing Slip</span><span>Printed: ' + escHtml(new Date().toLocaleString()) + '</span></div>' +
        '</div>') +
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

    function showSubmitSuccessModalAndClose() {
      var existing = document.getElementById('opSubmitSuccessModal');
      if (existing) existing.remove();

      var wrap = document.createElement('div');
      wrap.id = 'opSubmitSuccessModal';
      wrap.style.position = 'fixed';
      wrap.style.inset = '0';
      wrap.style.background = 'rgba(15,23,42,.45)';
      wrap.style.display = 'flex';
      wrap.style.alignItems = 'center';
      wrap.style.justifyContent = 'center';
      wrap.style.zIndex = '99999';

      var card = document.createElement('div');
      card.style.width = 'min(92vw,420px)';
      card.style.border = '1px solid #86efac';
      card.style.borderRadius = '14px';
      card.style.background = '#ffffff';
      card.style.boxShadow = '0 18px 50px rgba(2,132,199,.22)';
      card.style.padding = '18px 16px';
      card.innerHTML = ''
        + '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">'
        + '  <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:900">&#10003;</span>'
        + '  <h4 style="margin:0;font-size:1rem;color:#166534;font-weight:900">Entry Submitted</h4>'
        + '</div>'
        + '<p style="margin:0 0 12px;color:#334155;font-size:.9rem;line-height:1.45">Submission successful. Press OK to close this window.</p>'
        + '<div style="display:flex;justify-content:flex-end;gap:8px">'
        + '  <button type="button" id="opSubmitSuccessOk" style="border:1px solid #16a34a;background:#16a34a;color:#fff;border-radius:9px;padding:7px 12px;font-size:.82rem;font-weight:800;cursor:pointer">OK</button>'
        + '</div>';

      wrap.appendChild(card);
      document.body.appendChild(wrap);

      var closed = false;
      function finalizeClose() {
        if (closed) return;
        closed = true;
        wrap.remove();
        closeModal();
      }

      var okBtn = card.querySelector('#opSubmitSuccessOk');
      if (okBtn) okBtn.addEventListener('click', finalizeClose);
    }

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
      var selectedRollPayloadKeys = [];
      if (rollSelectNodes.length) {
        rollSelectNodes.forEach(function(node) {
          if (!node.checked) return;
          var idx = Number(node.getAttribute('data-roll-index'));
          if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return;
          selectedRollPayloadKeys.push(String(rollLots[idx].rollNo || ('roll-' + String(idx))));
        });
      }
      var payloadOverrides = {};
      selectedRollPayloadKeys.forEach(function(key) {
        var lot = null;
        for (var i = 0; i < rollLots.length; i++) {
          if (String(rollLots[i].rollNo || ('roll-' + String(i))) === key) {
            lot = rollLots[i];
            break;
          }
        }
        var lotQty = lot ? Math.max(0, Math.min(toNum(lot.productionQty), toNum(lot.availableQty))) : 0;
        var st = (rollLooseOverrides[key] && typeof rollLooseOverrides[key] === 'object') ? rollLooseOverrides[key] : {};
        if (isBarcodeMode) {
          payloadOverrides[key] = {
            bpr: Object.prototype.hasOwnProperty.call(st, 'bpr') ? Math.max(1, Math.floor(toNum(st.bpr))) : barcodePerRollDefault,
            total_rolls: Math.max(0, Math.floor(toNum(st.total_rolls || 0))),
            cartons: Math.max(0, Math.floor(toNum(st.cartons || 0))),
            rolls_per_carton: Math.max(0, Math.floor(toNum(st.rolls_per_carton || 0))),
            qty: Math.max(0, Math.floor(lotQty)),
            extra_pcs: Math.max(0, Math.floor(toNum(st.extra_pcs || 0))),
            csize_text: String(st.csize_text || '75 mm').trim() || '75 mm'
          };
          return;
        }
        var rps = Math.max(1, Math.floor(toNum(st.rps || 5)));
        var rpc = Math.max(1, Math.floor(toNum(st.rpc || 50)));
        var cartons = Object.prototype.hasOwnProperty.call(st, 'cartons') ? Math.max(0, Math.floor(toNum(st.cartons))) : Math.floor(lotQty / rpc);
        var autoExtra = lotQty % rpc;
        var extra = Object.prototype.hasOwnProperty.call(st, 'extra') ? Math.max(0, Math.floor(toNum(st.extra))) : autoExtra;
        payloadOverrides[key] = {
          rps: rps,
          rpc: rpc,
            csize_text: String(st.csize_text || '75mm').trim() || '75mm',
          cartons: cartons,
          extra: extra
        };
      });
      fd.append('roll_payload_json', JSON.stringify({
        v: 2,
        selected_roll_keys: selectedRollPayloadKeys,
        roll_overrides: payloadOverrides,
        mixed: {
          enabled: !!(mixedEnableNode && mixedEnableNode.checked),
          rolls_per_carton: Math.max(0, Math.floor(toNum(mixedRpcNode ? mixedRpcNode.value : 0))),
          pool_extra_rolls: Math.max(0, Math.floor(toNum(mixedPoolNode ? mixedPoolNode.value : 0))),
          mixed_cartons: Math.max(0, Math.floor(toNum(mixedCartonsNode ? mixedCartonsNode.value : 0))),
          mixed_extra_rolls: Math.max(0, Math.floor(toNum(mixedExtraNode ? mixedExtraNode.value : 0))),
          batch_labels: selectedRollPayloadKeys.join(', ')
        }
      }));
      if (photoInput && photoInput.files && photoInput.files[0]) {
        fd.append('photo', photoInput.files[0]);
      }

      fetch(baseUrl + '/modules/packing/api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(resp) {
          if (!resp || !resp.ok) {
            msgDiv.textContent = (resp && resp.message) ? resp.message : 'Submit failed.';
            msgDiv.style.color = '#b91c1c';
            // Restore button state based on role/submission/edit mode rules
            syncSubmitButtonState();
            return;
          }
          msgDiv.textContent = '✓ Entry submitted successfully! Manager can now finalize this job.';
          msgDiv.style.color = '#166534';
          opEntrySubmitted = true;
          lockSubmittedForOperator = !opCanAdminOverrideEdit;
          if (opCanAdminOverrideEdit) {
            // Admin: re-lock form and reset edit mode after successful update
            adminEditMode = false;
            applyAdminEditLockState(false);
            if (adminEditBtn) adminEditBtn.style.display = 'inline-flex';
            msgDiv.textContent = 'Entry updated. Press Edit Entry (Admin) to make further changes.';
            msgDiv.style.color = '#166534';
          } else {
            syncSubmitButtonState();
            ['opNotes','opPhoto'].forEach(function(id) {
              var el = document.getElementById(id);
              if (el) el.disabled = true;
            });
            Array.prototype.slice.call(document.querySelectorAll('.op-roll-rps,.op-roll-rpc-input,.op-roll-csize,.op-roll-cartons-input,.op-roll-extra-input,.op-roll-select')).forEach(function(el) {
              el.disabled = true;
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
          showSubmitSuccessModalAndClose();
        })
        .catch(function() {
          msgDiv.textContent = 'Network error. Please try again.';
          msgDiv.style.color = '#b91c1c';
          // Restore button state based on role/submission/edit mode rules
          syncSubmitButtonState();
        });
    });
  }

  // ── Open job modal ──
  function openJobModal(jobId, autoPrintAfterOpen) {
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
        if (autoPrintAfterOpen) {
          setTimeout(function() {
            var printBtn = document.getElementById('opPrintSlipBtn');
            if (printBtn) printBtn.click();
          }, 120);
        }
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

  document.querySelectorAll('.op-history-print-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openJobModal(btn.getAttribute('data-job-id'), true);
    });
  });

  document.querySelectorAll('.op-history-delete-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (!opCanDeleteJobs) return;
      var jobId = Number(btn.getAttribute('data-job-id') || 0);
      if (!jobId) return;
      if (!window.confirm('Delete this job from packing history?')) return;

      var fd = new FormData();
      fd.append('action', 'delete_job');
      fd.append('job_id', String(jobId));
      fetch(baseUrl + '/modules/packing/api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
          if (!resp || !resp.ok) {
            window.alert((resp && resp.message) ? resp.message : 'Delete failed.');
            return;
          }
          window.location.reload();
        })
        .catch(function() {
          window.alert('Network error while deleting job.');
        });
    });
  });

})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
