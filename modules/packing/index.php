<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_data.php';

$db = getDB();
$pageTitle = 'Packing';

$allowedTabs = array_merge(packing_tab_keys(), ['history']);
$currentTab = trim((string)($_GET['tab'] ?? 'printing_label'));
if (!in_array($currentTab, $allowedTabs, true)) {
  $currentTab = 'printing_label';
}

$search = trim((string)($_GET['q']      ?? ''));
$from   = trim((string)($_GET['from']   ?? ''));
$to     = trim((string)($_GET['to']     ?? ''));
$period = trim((string)($_GET['period'] ?? 'custom'));
$status = trim((string)($_GET['status'] ?? '')); // Default: all statuses (empty string)
$historyDept = trim((string)($_GET['hist_dept'] ?? '')); // History department filter

$allowedPeriods = ['custom', 'day', 'week', 'month', 'year'];
if (!in_array($period, $allowedPeriods, true)) {
  $period = 'custom';
}

if ($period !== 'custom') {
  $today = new DateTimeImmutable('today');

  if ($period === 'day') {
    $from = $today->format('Y-m-d');
    $to = $today->format('Y-m-d');
  } elseif ($period === 'week') {
    $weekStart = $today->modify('monday this week');
    $weekEnd = $today->modify('sunday this week');
    $from = $weekStart->format('Y-m-d');
    $to = $weekEnd->format('Y-m-d');
  } elseif ($period === 'month') {
    $monthStart = $today->modify('first day of this month');
    $monthEnd = $today->modify('last day of this month');
    $from = $monthStart->format('Y-m-d');
    $to = $monthEnd->format('Y-m-d');
  } elseif ($period === 'year') {
    $yearStart = $today->setDate((int)$today->format('Y'), 1, 1);
    $yearEnd = $today->setDate((int)$today->format('Y'), 12, 31);
    $from = $yearStart->format('Y-m-d');
    $to = $yearEnd->format('Y-m-d');
  }
}

$data = packing_fetch_ready_rows($db, [
  'search' => $search,
  'from' => $from,
  'to' => $to,
  'status' => $status,
]);
$rowsByTab = $data['rows_by_tab'];
$counts = $data['counts'];

$historyData = [];
if ($currentTab === 'history') {
  $historyData = packing_fetch_history_rows($db, [
    'search' => $search,
    'from' => $from,
    'to' => $to,
    'dept' => $historyDept,
  ]);
}
$canDeleteJobs = isAdmin();
$appSettings = getAppSettings();
$printCompanyName = trim((string)($appSettings['company_name'] ?? APP_NAME));
$printLogoPath = trim((string)($appSettings['logo_path'] ?? ''));
$printLogoUrl = $printLogoPath !== ''
  ? (rtrim((string)BASE_URL, '/') . '/' . ltrim($printLogoPath, '/'))
  : '';

$statusClass = static function(string $status): string {
  return strtolower(trim($status)) === 'packing done' ? 'ok' : 'muted';
};

$displayPackingStatus = static function(string $status): string {
  return strtolower(trim($status)) === 'packing done' ? 'Packing Done' : 'Packing';
};

$formatDate = static function(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '-';
  }
  $ts = strtotime($value);
  if ($ts === false) {
    return '-';
  }
  return date('d M Y', $ts);
};

$tabThemes = [
  'printing_label' => [
    'accent' => '#2563eb',
    'soft' => '#dbeafe',
    'border' => '#93c5fd',
  ],
  'pos_roll' => [
    'accent' => '#0f766e',
    'soft' => '#ccfbf1',
    'border' => '#5eead4',
  ],
  'one_ply' => [
    'accent' => '#c2410c',
    'soft' => '#ffedd5',
    'border' => '#fdba74',
  ],
  'two_ply' => [
    'accent' => '#7c3aed',
    'soft' => '#ede9fe',
    'border' => '#c4b5fd',
  ],
  'barcode' => [
    'accent' => '#be123c',
    'soft' => '#ffe4e6',
    'border' => '#fda4af',
  ],
  'history' => [
    'accent' => '#7c3aed',
    'soft' => '#f3e8ff',
    'border' => '#ddd6fe',
  ],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Packaging and Dispatch</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Packing</span>
</div>

<style>
.pk-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.pk-head h1{margin:0;font-size:1.45rem;font-weight:900;color:#f8fafc}
.pk-head p{margin:5px 0 0;color:#dbeafe}
.pk-actions{display:flex;gap:8px;flex-wrap:wrap}
.pk-btn{border:1px solid #cbd5e1;background:#fff;color:#1f2937;border-radius:10px;padding:8px 12px;font-size:.78rem;font-weight:800;cursor:pointer;transition:all .18s ease}
.pk-btn:hover{transform:translateY(-1px)}
.pk-btn.primary{background:#0f172a;color:#fff;border-color:#0f172a;box-shadow:0 8px 16px rgba(15,23,42,.2)}
.pk-btn[disabled]{opacity:.45;cursor:not-allowed}
.pk-card{background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);border:1px solid #dbe3f0;border-radius:14px;padding:14px;margin-top:14px;box-shadow:0 12px 30px rgba(15,23,42,.06)}
.pk-filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:10px;align-items:end}
.pk-filters label{display:block;font-size:.63rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:800;margin-bottom:4px}
.pk-filters input{height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:0 10px;font-size:.82rem}
.pk-filters .pk-btn{height:38px}
.pk-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.pk-tab{padding:8px 12px;border-radius:10px;border:1px solid var(--pk-border,#cbd5e1);background:var(--pk-soft,#fff);color:var(--pk-accent,#334155);font-size:.74rem;font-weight:800;cursor:pointer;transition:all .2s ease;box-shadow:0 2px 8px rgba(15,23,42,.06)}
.pk-tab:hover{transform:translateY(-1px)}
.pk-tab.active{background:var(--pk-accent,#0f172a);color:#fff;border-color:var(--pk-accent,#0f172a);box-shadow:0 10px 20px rgba(15,23,42,.16)}
.pk-pane{display:none;margin-top:12px;--pk-accent:#1d4ed8;--pk-soft:#eff6ff;--pk-border:#bfdbfe}
.pk-pane.active{display:block}
.pk-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 8px;padding:10px;border:1px solid var(--pk-border);background:linear-gradient(135deg,var(--pk-soft) 0%,#ffffff 100%);border-radius:10px}
.pk-bar .count{font-size:.78rem;font-weight:800;color:var(--pk-accent)}
.pk-table-wrap{overflow:auto;border:1px solid var(--pk-border);border-radius:12px}
.pk-table{width:100%;border-collapse:collapse;min-width:980px}
.pk-table th{background:var(--pk-soft);color:#334155;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;padding:10px;border-bottom:1px solid var(--pk-border);text-align:left}
.pk-table td{padding:9px;border-bottom:1px solid #f1f5f9;font-size:.77rem;color:#0f172a;font-weight:600}
.pk-table tr:nth-child(even) td{background:#fcfdff}
.pk-check-col{width:38px;text-align:center}
.pk-badge{display:inline-flex;border-radius:999px;padding:2px 8px;font-size:.62rem;font-weight:800}
.pk-badge.ok{background:#dcfce7;color:#166534}
.pk-badge.muted{background:#e2e8f0;color:#475569}
.pk-empty{padding:30px;text-align:center;color:#94a3b8}
.pk-id-btn{border:1px solid var(--pk-border);background:var(--pk-soft);color:var(--pk-accent);padding:5px 9px;border-radius:999px;font-size:.7rem;font-weight:800;cursor:pointer}
.pk-id-btn:hover{filter:brightness(.96)}
.pk-modal{position:fixed;inset:0;z-index:1200;display:none}
.pk-modal.show{display:block}
.pk-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.58);backdrop-filter:blur(2px)}
.pk-modal-card{position:relative;margin:4vh auto 0;width:min(880px,94vw);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;border:1px solid var(--pk-border,#cbd5e1);box-shadow:0 24px 48px rgba(15,23,42,.24)}
.pk-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:14px 16px;background:linear-gradient(120deg,var(--pk-soft,#eff6ff) 0%,#fff 90%);border-bottom:1px solid var(--pk-border,#cbd5e1)}
.pk-modal-title{margin:0;font-size:1.02rem;font-weight:900;color:var(--pk-accent,#1d4ed8)}
.pk-modal-sub{margin:3px 0 0;font-size:.75rem;color:#475569;font-weight:700}
.pk-modal-body{padding:14px 16px 16px}
.pk-modal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px;margin-bottom:12px}
.pk-modal-item{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px}
.pk-modal-item b{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:2px}
.pk-modal-item span{font-size:.8rem;color:#0f172a;font-weight:700;word-break:break-word}
.pk-modal-photo{margin-top:6px;border:1px dashed var(--pk-border,#cbd5e1);border-radius:12px;padding:10px;background:#f8fafc;text-align:center}
.pk-modal-photo img{max-width:100%;max-height:280px;border-radius:10px;border:1px solid #cbd5e1}
.pk-modal-actions{display:flex;gap:8px;flex-wrap:wrap}
.pk-jc-card{border:1px solid var(--pk-border,#cbd5e1);border-radius:12px;overflow:hidden;background:#fff}
.pk-jc-head{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:12px;border-bottom:1px solid var(--pk-border,#cbd5e1);background:linear-gradient(120deg,var(--pk-soft,#eff6ff) 0%,#fff 86%)}
.pk-jc-brand h4{margin:0;font-size:1rem;font-weight:900;color:var(--pk-accent,#1d4ed8)}
.pk-jc-brand p{margin:4px 0 0;font-size:.74rem;color:#475569;font-weight:700}
.pk-jc-qr{width:122px;min-height:122px;border:1px dashed var(--pk-border,#cbd5e1);background:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:6px}
.pk-jc-meta{padding:10px 12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.pk-jc-meta-item{border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:7px 8px}
.pk-jc-meta-item b{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:2px}
.pk-jc-meta-item span{font-size:.78rem;color:#0f172a;font-weight:800}
.pk-jc-image{padding:10px 12px;border-top:1px solid #eef2f7;text-align:center}
.pk-jc-image img{max-width:100%;max-height:230px;border:1px solid #cbd5e1;border-radius:10px}
.pk-jc-foot{padding:9px 12px;border-top:1px solid var(--pk-border,#cbd5e1);background:#f8fafc;color:#475569;font-size:.72rem;font-weight:700;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
.pk-jc-section{padding:10px 12px;border-top:1px solid #eef2f7}
.pk-jc-section h5{margin:0 0 8px;font-size:.74rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#0f172a}
.pk-jc-top-summary{padding:10px 12px;border-top:1px solid #eef2f7;background:linear-gradient(120deg,#fff 0%,#f8fafc 100%)}
.pk-jc-top-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.pk-jc-top-item{border:1px solid var(--pk-border,#cbd5e1);border-radius:10px;padding:8px;background:#fff}
.pk-jc-top-item b{display:block;font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:2px}
.pk-jc-top-item span{display:block;font-size:1rem;font-weight:900;color:#0f172a;line-height:1.2}
.pk-jc-top-item.dispatch span{color:#b91c1c}
.pk-jc-top-item.metric-good span{color:#166534}
.pk-jc-top-item.metric-bad span{color:#b91c1c}
.pk-jc-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.pk-jc-detail-item{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);box-shadow:0 2px 8px rgba(15,23,42,.04)}
.pk-jc-detail-item b{display:block;font-size:.61rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:3px}
.pk-jc-detail-item span{display:block;font-size:.8rem;color:#0f172a;font-weight:800;word-break:break-word}
.pk-jc-detail-item.emphasis span{font-size:.95rem;font-weight:900;line-height:1.25}
.pk-jc-detail-item.priority-normal span{color:#166534;background:#dcfce7;border:1px solid #86efac;display:inline-block;padding:3px 8px;border-radius:999px}
.pk-jc-detail-item.priority-urgent span{color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;display:inline-block;padding:3px 8px;border-radius:999px}
.pk-jc-detail-item.dispatch-date span{color:#b91c1c;font-weight:900;font-size:1rem;line-height:1.2}
.pk-dispatch-date{font-size:.92rem}
.pk-dispatch-pill{display:inline-flex;align-items:center;border:1px solid #fda4af;background:#ffe4e6;color:#b91c1c;padding:4px 9px;border-radius:999px;font-weight:900;line-height:1}
.pk-jc-rolls{width:100%;border-collapse:collapse;margin-top:6px}
.pk-jc-rolls th,.pk-jc-rolls td{border:1px solid #e2e8f0;padding:6px 7px;font-size:.73rem;text-align:left}
.pk-jc-rolls th{background:#f8fafc;color:#334155;font-weight:900}
.pk-roll-even td{background:#f0fdfa;}
.pk-roll-odd td{background:#f8fafc;}
.pk-jc-rolls tr:hover td{background:#fef08a !important;}
.pk-roll-highlight td{background:#fde047 !important; font-weight:900; color:#78350f;}
.pk-jc-section-content.collapsed { display: none; }
.pk-jc-section-content-b.collapsed { display: none; }
.pk-jc-user-entry{border:1px dashed var(--pk-border,#cbd5e1);border-radius:10px;background:#f8fafc;padding:10px}
.pk-jc-user-entry p{margin:0;color:#64748b;font-size:.77rem;font-weight:700}
.pk-tab[data-theme="printing_label"],.pk-pane[data-theme="printing_label"]{--pk-accent:#2563eb;--pk-soft:#dbeafe;--pk-border:#93c5fd}
.pk-tab[data-theme="pos_roll"],.pk-pane[data-theme="pos_roll"]{--pk-accent:#0f766e;--pk-soft:#ccfbf1;--pk-border:#5eead4}
.pk-tab[data-theme="one_ply"],.pk-pane[data-theme="one_ply"]{--pk-accent:#c2410c;--pk-soft:#ffedd5;--pk-border:#fdba74}
.pk-tab[data-theme="two_ply"],.pk-pane[data-theme="two_ply"]{--pk-accent:#7c3aed;--pk-soft:#ede9fe;--pk-border:#c4b5fd}
.pk-tab[data-theme="barcode"],.pk-pane[data-theme="barcode"]{--pk-accent:#be123c;--pk-soft:#ffe4e6;--pk-border:#fda4af}
.pk-tab[data-theme="history"],.pk-pane[data-theme="history"]{--pk-accent:#7c3aed;--pk-soft:#f3e8ff;--pk-border:#ddd6fe}
.page-header{margin-top:12px;background:linear-gradient(120deg,#0f172a 0%,#1d4ed8 48%,#0ea5e9 100%);border-radius:14px;padding:14px;border:1px solid #1e3a8a;box-shadow:0 14px 30px rgba(30,58,138,.24)}
@media (max-width:980px){.pk-filters{grid-template-columns:1fr 1fr}.pk-head{align-items:stretch}}
@media (max-width:680px){.pk-filters{grid-template-columns:1fr}.pk-table{min-width:860px}.page-header{padding:12px}.pk-modal-grid{grid-template-columns:1fr}.pk-jc-head{grid-template-columns:1fr}.pk-jc-qr{width:100%;max-width:180px;margin:0 auto}.pk-jc-top-grid{grid-template-columns:1fr}.pk-jc-detail-grid{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <div class="pk-head">
    <div>
      <h1>Packing Dashboard</h1>
      <p>Final-stage completed jobs ready for packing.</p>
    </div>
    <div class="pk-actions">
      <button class="pk-btn" id="pkPrintSelected" disabled>Print All Selected</button>
      <button class="pk-btn primary" id="pkExportSelected" disabled>Export PDF</button>
    </div>
  </div>
</div>

<div class="pk-card">
  <form class="pk-filters" method="get">
    <div>
      <label>Search</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Plan no, job no, roll no, job name">
    </div>
    <div>
      <label>Filter Type</label>
      <select name="period" style="height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:0 10px;font-size:.82rem;width:100%;background:#fff;">
        <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Date</option>
        <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Day Wise (Today)</option>
        <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>Week Wise (This Week)</option>
        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Month Wise (This Month)</option>
        <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Year Wise (This Year)</option>
      </select>
    </div>
    <div>
      <label>From Date</label>
      <input type="date" name="from" value="<?= e($from) ?>">
    </div>
    <div>
      <label>To Date</label>
      <input type="date" name="to" value="<?= e($to) ?>">
    </div>
    <div>
      <label>Status</label>
      <select name="status" style="height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:0 10px;font-size:.82rem;width:100%;background:#fff;">
        <option value="" <?= $status === '' ? 'selected' : '' ?>>All Statuses</option>
        <option value="Completed" <?= $status === 'Completed' ? 'selected' : '' ?>>Completed</option>
        <option value="Packing Done" <?= $status === 'Packing Done' ? 'selected' : '' ?>>Packing Done</option>
      </select>
    </div>
    <div style="display:flex;gap:8px;">
      <input type="hidden" name="tab" id="pkTabInput" value="<?= e($currentTab) ?>">
      <button class="pk-btn primary" type="submit">Apply</button>
      <a class="pk-btn" href="<?= e(BASE_URL . '/modules/packing/index.php?tab=' . urlencode($currentTab)) ?>">Reset</a>
    </div>
  </form>

  <div class="pk-tabs" id="pkTabs">
    <?php foreach ($allowedTabs as $tabKey): ?>
      <?php $theme = $tabThemes[$tabKey] ?? ['accent' => '#1d4ed8', 'soft' => '#eff6ff', 'border' => '#bfdbfe']; ?>
      <button
        class="pk-tab<?= $currentTab === $tabKey ? ' active' : '' ?>"
        type="button"
        data-tab="<?= e($tabKey) ?>"
        data-theme="<?= e($tabKey) ?>"
        style="--pk-accent:<?= e($theme['accent']) ?>;--pk-soft:<?= e($theme['soft']) ?>;--pk-border:<?= e($theme['border']) ?>;"
      >
        <?php if ($tabKey === 'history'): ?>
          <i class="bi bi-clock-history" style="margin-right:3px"></i> History
        <?php else: ?>
          <?= e(packing_tab_label($tabKey)) ?> (<?= (int)($counts[$tabKey] ?? 0) ?>)
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($allowedTabs as $tabKey): ?>
    <?php 
      $tabRows = $tabKey === 'history' ? $historyData : ($rowsByTab[$tabKey] ?? []);
      $theme = $tabThemes[$tabKey] ?? ['accent' => '#1d4ed8', 'soft' => '#eff6ff', 'border' => '#bfdbfe']; 
    ?>
    <div
      class="pk-pane<?= $currentTab === $tabKey ? ' active' : '' ?>"
      data-pane="<?= e($tabKey) ?>"
      data-theme="<?= e($tabKey) ?>"
      style="--pk-accent:<?= e($theme['accent']) ?>;--pk-soft:<?= e($theme['soft']) ?>;--pk-border:<?= e($theme['border']) ?>;"
    >
      <?php if ($tabKey === 'history'): ?>
        <!-- History Filters -->
        <div class="pk-card">
          <form method="get" style="margin:0">
            <input type="hidden" name="tab" value="history">
            <div class="pk-filters">
              <div>
                <label>Search (Plan No / Job Name / Client)</label>
                <input type="text" name="q" placeholder="Search..." value="<?= e($search) ?>">
              </div>
              <div>
                <label>From Date</label>
                <input type="date" name="from" value="<?= e($from) ?>">
              </div>
              <div>
                <label>To Date</label>
                <input type="date" name="to" value="<?= e($to) ?>">
              </div>
              <div>
                <label>Department</label>
                <select name="hist_dept">
                  <option value="">All Departments</option>
                  <?php foreach (packing_department_type_map() as $deptKey => $deptCfg): ?>
                    <option value="<?= strtolower(trim($deptCfg['departments'][0] ?? '')) ?>"<?= $historyDept === strtolower(trim($deptCfg['departments'][0] ?? '')) ? ' selected' : '' ?>>
                      <?= e($deptCfg['department_label'] ?? $deptKey) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="pk-btn primary" style="align-self:flex-end"><i class="bi bi-search"></i> Search</button>
            </div>
          </form>
        </div>
        <div class="pk-bar">
          <div class="count">Total: <?= count($tabRows) ?> completed jobs</div>
        </div>
      <?php else: ?>
        <div class="pk-bar">
          <div class="count"><span class="pk-selected">0</span> selected in <?= e(packing_tab_label($tabKey)) ?></div>
          <div style="font-size:.74rem;color:#475569;">Ready rows: <?= count($tabRows) ?></div>
        </div>
      <?php endif; ?>

      <div class="pk-table-wrap">
        <table class="pk-table">
          <thead>
            <?php if ($tabKey === 'history'): ?>
              <tr>
                <th>Sl</th>
                <th>Packing ID</th>
                <th>Plan No</th>
                <th>Plan Name</th>
                <th>Job No</th>
                <th>Client</th>
                <th>Department</th>
                <th>Status</th>
                <th>Completed Date</th>
                <th>Action</th>
              </tr>
            <?php elseif ($tabKey === 'pos_roll'): ?>
              <tr>
                <th class="pk-check-col"><input type="checkbox" class="pk-select-all"></th>
                <th>Sl No</th>
                <th>Packing ID</th>
                <th>Priority</th>
                <th>Plan No</th>
                <th>Last Job No</th>
                <th>Job Name</th>
                <th>Client Name</th>
                <th>Order Date</th>
                <th>Dispatch Date</th>
                <th>Status</th>
              </tr>
            <?php else: ?>
              <tr>
                <th class="pk-check-col"><input type="checkbox" class="pk-select-all"></th>
                <th>Plan No</th>
                <th>Plan Name</th>
                <th>Last Job No</th>
                <th>Roll No</th>
                <th>Type</th>
                <th>Last Department</th>
                <th>Status</th>
                <th>Completed At</th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php if (empty($tabRows)): ?>
              <tr><td colspan="<?= $tabKey === 'history' ? '10' : ($tabKey === 'pos_roll' ? '11' : '9') ?>" class="pk-empty">
                <?= $tabKey === 'history' ? 'No completed jobs found.' : 'No packing-ready job found in this tab.' ?>
              </td></tr>
            <?php else: ?>
              <?php foreach ($tabRows as $idx => $row): ?>
                <?php if ($tabKey === 'history'): ?>
                  <tr data-row-id="<?= (int)$row['id'] ?>">
                    <td><?= (int)$idx + 1 ?></td>
                    <td>
                      <button class="pk-id-btn pk-open-modal" type="button" data-job-id="<?= (int)$row['id'] ?>">
                        <?= e($row['packing_display_id'] ?? ('PKG/' . (int)$row['id'])) ?>
                      </button>
                    </td>
                    <td>
                      <?php if (!empty($row['plan_no'])): ?>
                        <a href="<?= e(BASE_URL . '/modules/planning/board.php?plan_no=' . urlencode($row['plan_no'])) ?>" target="_blank" style="text-decoration:underline;color:#2563eb;">
                          <?= e($row['plan_no']) ?>
                        </a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td><?= e(($row['plan_name'] ?? '') !== '' ? $row['plan_name'] : '-') ?></td>
                    <td>
                      <?php if (!empty($row['job_no'])): ?>
                        <a href="<?= e(BASE_URL . '/modules/pos_roll/job_card.php?job_no=' . urlencode($row['job_no'])) ?>" target="_blank" style="text-decoration:underline;color:#2563eb;">
                          <?= e($row['job_no']) ?>
                        </a>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td><?= e(($row['client_name'] ?? '') !== '' ? $row['client_name'] : '-') ?></td>
                    <td><?= e($row['last_department'] ?? '-') ?></td>
                    <td><span class="pk-badge <?= e($statusClass((string)($row['status'] ?? ''))) ?>"><?= e($row['status'] ?? '-') ?></span></td>
                    <td><?= e(($row['event_time'] ?? '') !== '' ? date('d M Y H:i', strtotime((string)$row['event_time'])) : '-') ?></td>
                    <td style="text-align:center">
                      <button class="pk-id-btn pk-open-modal" type="button" data-job-id="<?= (int)$row['id'] ?>" title="View Details" style="padding:3px 6px;font-size:.68rem">
                        <i class="bi bi-eye"></i> View
                      </button>
                    </td>
                  </tr>
                <?php elseif ($tabKey === 'pos_roll'): ?>
                  <tr data-row-id="<?= (int)$row['id'] ?>">
                    <td class="pk-check-col"><input type="checkbox" class="pk-row-check" value="<?= (int)$row['id'] ?>"></td>
                    <td><?= (int)$idx + 1 ?></td>
                    <td>
                      <button class="pk-id-btn pk-open-modal" type="button" data-job-id="<?= (int)$row['id'] ?>">
                        <?= e($row['packing_display_id'] ?? ('PKG/' . (int)$row['id'])) ?>
                      </button>
                    </td>
                    <td><?= e(($row['plan_priority'] ?? '') !== '' ? $row['plan_priority'] : '-') ?></td>
                    <td>
                      <?php if (!empty($row['plan_no'])): ?>
                        <button class="pk-id-btn pk-open-modal" type="button" data-job-id="<?= (int)$row['id'] ?>" title="Open Job Modal" style="text-decoration:underline;color:#2563eb;background:none;border:none;padding:0;font-size:inherit;cursor:pointer;">
                          <?= e($row['plan_no']) ?>
                        </button>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($row['job_no'])): ?>
                        <button class="pk-id-btn pk-open-modal" type="button" data-job-id="<?= (int)$row['id'] ?>" title="Open Job Modal" style="text-decoration:underline;color:#2563eb;background:none;border:none;padding:0;font-size:inherit;cursor:pointer;">
                          <?= e($row['job_no']) ?>
                        </button>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td><?= e(($row['plan_name'] ?? '') !== '' ? $row['plan_name'] : '-') ?></td>
                    <td><?= e(($row['client_name'] ?? '') !== '' ? $row['client_name'] : '-') ?></td>
                    <td><?= e($formatDate((string)($row['order_date'] ?? ''))) ?></td>
                    <td class="pk-dispatch-date"><span class="pk-dispatch-pill"><?= e($formatDate((string)($row['dispatch_date'] ?? $row['event_time'] ?? ''))) ?></span></td>
                    <td><span class="pk-badge <?= e($statusClass((string)($row['status'] ?? ''))) ?>"><?= e($displayPackingStatus((string)($row['status'] ?? ''))) ?></span></td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <td class="pk-check-col"><input type="checkbox" class="pk-row-check" value="<?= (int)$row['id'] ?>"></td>
                    <td><?= e(($row['plan_no'] ?? '') !== '' ? $row['plan_no'] : '-') ?></td>
                    <td><?= e(($row['plan_name'] ?? '') !== '' ? $row['plan_name'] : '-') ?></td>
                    <td><?= e($row['job_no'] ?? '-') ?></td>
                    <td><?= e(($row['roll_no'] ?? '') !== '' ? $row['roll_no'] : '-') ?></td>
                    <td><?= e($row['tab_label'] ?? '-') ?></td>
                    <td><?= e($row['last_department'] ?? '-') ?></td>
                    <td><span class="pk-badge <?= e($statusClass((string)($row['status'] ?? ''))) ?>"><?= e($displayPackingStatus((string)($row['status'] ?? ''))) ?></span></td>
                    <td><?= e(($row['event_time'] ?? '') !== '' ? date('d M Y H:i', strtotime((string)$row['event_time'])) : '-') ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <div style="margin-top:10px;font-size:.74rem;color:#64748b;">
    Rule: A row appears only when the latest final job-card stage for that section is in Completed/Closed/Finalized/QC Passed.
  </div>
</div>

  <div class="pk-modal" id="pkJobModal" aria-hidden="true">
    <div class="pk-modal-backdrop" data-close-modal="1"></div>
    <div class="pk-modal-card">
      <div class="pk-modal-head" id="pkModalHead">
        <div>
          <h3 class="pk-modal-title" id="pkModalTitle">Job Details</h3>
          <p class="pk-modal-sub" id="pkModalSub">Packing details preview</p>
        </div>
        <div class="pk-modal-actions">
          <?php if ($canDeleteJobs): ?>
            <button class="pk-btn" id="pkDeleteJobBtn" type="button">Delete Job</button>
          <?php endif; ?>
          <button class="pk-btn" id="pkPrintDetailsBtn" type="button"><i class="bi bi-printer"></i> Print All Details</button>
          <button class="pk-btn primary" id="pkCloseModalBtn" type="button">Close</button>
        </div>
      </div>
      <div class="pk-modal-body">
        <div id="pkModalCardCanvas"></div>
      </div>
    </div>
  </div>

<script>
(function() {
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.pk-tab'));
  var panes = Array.prototype.slice.call(document.querySelectorAll('.pk-pane'));
  var tabInput = document.getElementById('pkTabInput');
  var exportBtn = document.getElementById('pkExportSelected');
  var printBtn = document.getElementById('pkPrintSelected');
  var activeTab = '<?= e($currentTab) ?>';
  var baseUrl = '<?= e(BASE_URL) ?>';
  var q = '<?= e($search) ?>';
  var from = '<?= e($from) ?>';
  var to = '<?= e($to) ?>';
  var canDeleteJobs = <?= $canDeleteJobs ? 'true' : 'false' ?>;
  var printCompanyName = '<?= e($printCompanyName) ?>';
  var printCompanyLogo = '<?= e($printLogoUrl) ?>';
  var activeJobIdForModal = 0;
  var activeModalJob = null;

  var modal = document.getElementById('pkJobModal');
  var modalHead = document.getElementById('pkModalHead');
  var modalTitle = document.getElementById('pkModalTitle');
  var modalSub = document.getElementById('pkModalSub');
  var modalCardCanvas = document.getElementById('pkModalCardCanvas');
  var closeModalBtn = document.getElementById('pkCloseModalBtn');
  var deleteBtn = document.getElementById('pkDeleteJobBtn');

  function activePane() {
    return document.querySelector('.pk-pane.active');
  }

  function activeIds() {
    var pane = activePane();
    if (!pane) return [];
    return Array.prototype.slice.call(pane.querySelectorAll('.pk-row-check:checked')).map(function(el) {
      return el.value;
    });
  }

  function syncPaneState(pane) {
    if (!pane) return;
    var checks = Array.prototype.slice.call(pane.querySelectorAll('.pk-row-check'));
    var selected = checks.filter(function(el) { return el.checked; }).length;
    var allBox = pane.querySelector('.pk-select-all');
    var countEl = pane.querySelector('.pk-selected');
    if (countEl) countEl.textContent = String(selected);
    if (allBox) {
      allBox.checked = checks.length > 0 && selected === checks.length;
      allBox.indeterminate = selected > 0 && selected < checks.length;
    }

    var hasSelection = selected > 0;
    exportBtn.disabled = !hasSelection;
    printBtn.disabled = !hasSelection;
  }

  function bindPane(pane) {
    var allBox = pane.querySelector('.pk-select-all');
    var checks = Array.prototype.slice.call(pane.querySelectorAll('.pk-row-check'));
    if (allBox) {
      allBox.addEventListener('change', function() {
        checks.forEach(function(el) { el.checked = allBox.checked; });
        syncPaneState(pane);
      });
    }
    checks.forEach(function(el) {
      el.addEventListener('change', function() { syncPaneState(pane); });
    });
    syncPaneState(pane);
  }

  function activateTab(tabKey) {
    activeTab = tabKey;
    tabs.forEach(function(btn) {
      btn.classList.toggle('active', btn.getAttribute('data-tab') === tabKey);
    });
    panes.forEach(function(p) {
      p.classList.toggle('active', p.getAttribute('data-pane') === tabKey);
    });
    if (tabInput) tabInput.value = tabKey;
    syncPaneState(activePane());
  }

  function readThemeVars() {
    var pane = activePane();
    if (!pane) {
      return { accent: '#1d4ed8', soft: '#eff6ff', border: '#bfdbfe' };
    }
    var styles = window.getComputedStyle(pane);
    return {
      accent: (styles.getPropertyValue('--pk-accent') || '#1d4ed8').trim(),
      soft: (styles.getPropertyValue('--pk-soft') || '#eff6ff').trim(),
      border: (styles.getPropertyValue('--pk-border') || '#bfdbfe').trim()
    };
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    activeJobIdForModal = 0;
    activeModalJob = null;
  }

  function openModal() {
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function printJobDetails() {
    if (!activeModalJob || !modalTitle) return;

    var printTitle = modalTitle.textContent || 'Packing Job Details';
    var planningRows = buildPlanningRows(activeModalJob);
    var rollInfoRows = buildRollItems(activeModalJob);
    var rollProdRows = buildPrintRollProductionRows(activeModalJob);

    var operatorEntry = (activeModalJob && activeModalJob.operator_entry && typeof activeModalJob.operator_entry === 'object') ? activeModalJob.operator_entry : null;
    var packedQtySubmitted = Math.max(0, safeNum(operatorEntry && operatorEntry.packed_qty ? operatorEntry.packed_qty : 0));
    var orderQty = safeNum(activeModalJob.order_quantity || 0);
    var prodQty = packedQtySubmitted > 0 ? packedQtySubmitted : safeNum(activeModalJob.production_quantity || 0);
    var wastage = safeNum(activeModalJob.wastage || 0);
    var deltaQty = prodQty - orderQty;
    var deltaText = (deltaQty > 0 ? '+' : '') + String(deltaQty);

    var totalPhysical = 0;
    var totalCartons = 0;
    var totalBundles = 0;
    rollProdRows.forEach(function(row) {
      totalPhysical += safeNum(row.physical);
      totalCartons += safeNum(row.cartons);
      totalBundles += safeNum(row.bundles);
    });

    var metricCards = [
      ['Order Qty', orderQty > 0 ? String(orderQty) : '-'],
      ['Production Qty', prodQty > 0 ? String(prodQty) : '-'],
      ['Wastage', wastage > 0 ? String(wastage) : '0'],
      ['Extra / Short', deltaText],
      ['Total Physical', totalPhysical > 0 ? String(totalPhysical) : '-'],
      ['Total Cartons', totalCartons > 0 ? String(totalCartons) : '-'],
      ['Total Bundles', totalBundles > 0 ? String(totalBundles) : '-'],
      ['Roll Count', String(rollProdRows.length || 0)]
    ];
    var statusRaw = String(activeModalJob.status || '').trim();
    var statusLower = statusRaw.toLowerCase().replace(/[-_]/g, ' ').trim();
    var statusText = (statusLower === 'packing done') ? 'Packing Done' : 'Packing';
    var completedAtRaw = String(activeModalJob.completed_at || activeModalJob.updated_at || activeModalJob.created_at || '').trim();
    var completedText = '-';
    if (completedAtRaw !== '') {
      var completedTs = new Date(completedAtRaw);
      if (!isNaN(completedTs.getTime())) {
        completedText = completedTs.toLocaleString();
      }
    }

    var summaryHtml = metricCards.map(function(item) {
      return ''
        + '<div class="a4-card">'
        + '  <div class="a4-label">' + escHtml(item[0] || '-') + '</div>'
        + '  <div class="a4-value">' + escHtml(item[1] || '-') + '</div>'
        + '</div>';
    }).join('');

    var planningHtml = planningRows.slice(0, 14).map(function(item) {
      return ''
        + '<tr>'
        + '  <td>' + escHtml(item[0] || '-') + '</td>'
        + '  <td>' + escHtml(item[1] || '-') + '</td>'
        + '</tr>';
    }).join('');

    var rollInfoLimit = 8;
    var rollInfoMore = Math.max(0, rollInfoRows.length - rollInfoLimit);
    var rollsInfoHtml = rollInfoRows.length ? rollInfoRows.slice(0, rollInfoLimit).map(function(roll, idx) {
      return ''
        + '<tr>'
        + '  <td>' + String(idx + 1) + '</td>'
        + '  <td>' + escHtml(roll.rollNo || '-') + '</td>'
        + '  <td>' + escHtml(roll.paperType || '-') + '</td>'
        + '  <td>' + escHtml(roll.company || '-') + '</td>'
        + '  <td>' + escHtml(roll.width || '-') + '</td>'
        + '  <td>' + escHtml(roll.length || '-') + '</td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="6" style="text-align:center;color:#64748b">No roll info found</td></tr>';

    var rollProdLimit = 8;
    var rollProdMore = Math.max(0, rollProdRows.length - rollProdLimit);
    var rollsProdHtml = rollProdRows.length ? rollProdRows.slice(0, rollProdLimit).map(function(roll, idx) {
      return ''
        + '<tr>'
        + '  <td>' + String(idx + 1) + '</td>'
        + '  <td>' + escHtml(roll.rollNo || '-') + '</td>'
        + '  <td>' + escHtml(roll.width || '-') + '</td>'
        + '  <td>' + escHtml(String(roll.sizeMm || '-')) + '</td>'
        + '  <td>' + escHtml(String(roll.productionQty || 0)) + '</td>'
        + '  <td>' + escHtml(String(roll.cartons || 0)) + '</td>'
        + '  <td>' + escHtml(String(roll.extra || 0)) + '</td>'
        + '  <td>' + escHtml(String(roll.physical || 0)) + '</td>'
        + '</tr>';
    }).join('') : '<tr><td colspan="8" style="text-align:center;color:#64748b">No roll-wise production data found</td></tr>';

    var printWindow = window.open('', '_blank');
    if (!printWindow) {
      alert('Popup blocked. Please allow popups for printing.');
      return;
    }

    var logoFallback = (printCompanyName || 'ERP').replace(/\s+/g, ' ').trim().slice(0, 2).toUpperCase() || 'ER';
    var logoHtml = printCompanyLogo
      ? '<img src="' + escHtml(printCompanyLogo) + '" alt="Company Logo" class="a4-logo">'
      : '<div class="a4-logo-fallback">' + escHtml(logoFallback) + '</div>';

    var printHtml = '<!DOCTYPE html>' +
      '<html>' +
      '<head>' +
      '<meta charset="UTF-8">' +
      '<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
      '<title>' + escHtml(printTitle) + '</title>' +
      '<style>' +
      '@page { size: A4; margin: 7mm; }' +
      'body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; color: #0f172a; background: #ffffff; }' +
      '.a4-sheet { width: 100%; max-width: 196mm; margin: 0 auto; }' +
      '.a4-head { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; background: linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%); display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }' +
      '.a4-brand { display:flex; align-items:center; gap:8px; }' +
      '.a4-logo { width: 34px; height: 34px; object-fit: contain; border: 1px solid #dbeafe; border-radius: 6px; background:#fff; padding:2px; }' +
      '.a4-logo-fallback { width:34px; height:34px; border-radius:6px; border:1px solid #cbd5e1; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:900; background:#fff; color:#334155; }' +
      '.a4-company { font-size: 11px; color:#1e293b; font-weight: 800; margin: 0 0 2px; text-transform: uppercase; letter-spacing:.04em; }' +
      '.a4-title { font-size: 15px; font-weight: 800; margin: 0; color: #111827; }' +
      '.a4-sub { margin: 2px 0 0; font-size: 10px; color: #475569; font-weight: 600; }' +
      '.a4-badge { font-size: 10px; padding: 5px 8px; border-radius: 999px; font-weight: 800; border: 1px solid #cbd5e1; background: #eef2ff; color: #3730a3; text-transform: uppercase; }' +
      '.a4-badge.ok { background: #dcfce7; color: #166534; border-color: #86efac; }' +
      '.a4-grid { margin-top: 6px; display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 5px; }' +
      '.a4-card { border: 1px solid #e2e8f0; border-radius: 7px; background: #ffffff; padding: 5px 7px; min-height: 40px; }' +
      '.a4-label { font-size: 9px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; }' +
      '.a4-value { font-size: 11px; color: #0f172a; font-weight: 700; line-height: 1.2; }' +
      '.a4-row { margin-top: 6px; display:grid; grid-template-columns: 1fr 1fr; gap: 6px; }' +
      '.a4-sec { border: 1px solid #e2e8f0; border-radius: 7px; overflow: hidden; }' +
      '.a4-sec-title { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 5px 7px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #334155; }' +
      'table { width: 100%; border-collapse: collapse; }' +
      'th, td { border-bottom: 1px solid #f1f5f9; padding: 5px 7px; text-align: left; font-size: 9.5px; }' +
      'th { background: #f8fafc; color: #475569; font-weight: 800; text-transform: uppercase; font-size: 9px; }' +
      'tr:last-child td { border-bottom: none; }' +
      '.a4-note { margin-top: 3px; font-size: 8.5px; color: #64748b; text-align: right; }' +
      '.a4-sign { margin-top: 8px; border: 1px solid #e2e8f0; border-radius: 7px; padding: 8px 10px 12px; }' +
      '.a4-sign-title { font-size: 9px; font-weight: 800; color:#64748b; text-transform: uppercase; letter-spacing:.05em; margin-bottom: 10px; }' +
      '.a4-sign-grid { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; }' +
      '.a4-sign-col { text-align:center; }' +
      '.a4-sign-line { border-top: 1px solid #94a3b8; margin-top: 22px; }' +
      '.a4-sign-label { margin-top: 4px; font-size: 9px; color:#334155; font-weight: 700; text-transform: uppercase; }' +
      '.a4-foot { margin-top: 5px; font-size: 8.5px; color: #64748b; text-align: right; }' +
      '@media print { .a4-sheet { max-width: 100%; } .a4-sign { margin-top: 6px; } }' +
      '</style>' +
      '</head>' +
      '<body>' +
      '<div class="a4-sheet">' +
      '  <div class="a4-head">' +
      '    <div class="a4-brand">' +
      '      ' + logoHtml +
      '      <div>' +
      '        <p class="a4-company">' + escHtml(printCompanyName || 'Company') + '</p>' +
      '        <h1 class="a4-title">' + escHtml(printTitle) + '</h1>' +
      '      </div>' +
      '    </div>' +
      '    <div>' +
      '      <p class="a4-sub">' + escHtml(modalSub ? modalSub.textContent : '-') + '</p>' +
      '      <p class="a4-sub">Completed: ' + escHtml(completedText) + '</p>' +
      '      <div style="margin-top:4px;text-align:right"><span class="a4-badge ' + ((statusLower === 'packing done' || statusLower === 'completed' || statusLower === 'closed' || statusLower === 'finalized' || statusLower === 'qc passed') ? 'ok' : '') + '">' + escHtml(statusText) + '</span></div>' +
      '    </div>' +
      '  </div>' +
      '  <div class="a4-grid">' + summaryHtml + '</div>' +
      '  <div class="a4-row">' +
      '    <div class="a4-sec">' +
      '      <div class="a4-sec-title">Planning & Job Details</div>' +
      '      <table>' +
      '        <tbody>' + planningHtml + '</tbody>' +
      '      </table>' +
      '    </div>' +
      '    <div class="a4-sec">' +
      '      <div class="a4-sec-title">Roll Source Details</div>' +
      '      <table>' +
      '        <thead><tr><th>#</th><th>Roll No</th><th>Paper Type</th><th>Company</th><th>Width</th><th>Length</th></tr></thead>' +
      '        <tbody>' + rollsInfoHtml + '</tbody>' +
      '      </table>' +
      (rollInfoMore > 0 ? '<div class="a4-note">+' + String(rollInfoMore) + ' more roll rows</div>' : '') +
      '    </div>' +
      '  </div>' +
      '  <div class="a4-sec" style="margin-top:6px">' +
      '    <div class="a4-sec-title">Roll-wise Physical Production</div>' +
      '    <table>' +
      '      <thead><tr><th>#</th><th>Roll No</th><th>Width</th><th>Size(mm)</th><th>Prod Qty</th><th>Cartons</th><th>Extra</th><th>Physical</th></tr></thead>' +
      '      <tbody>' + rollsProdHtml + '</tbody>' +
      '    </table>' +
      (rollProdMore > 0 ? '<div class="a4-note">+' + String(rollProdMore) + ' more production rows</div>' : '') +
      '  </div>' +
      '  <div class="a4-sign">' +
      '    <div class="a4-sign-title">Approval Signatures</div>' +
      '    <div class="a4-sign-grid">' +
      '      <div class="a4-sign-col"><div class="a4-sign-line"></div><div class="a4-sign-label">Prepared By</div></div>' +
      '      <div class="a4-sign-col"><div class="a4-sign-line"></div><div class="a4-sign-label">Checked By</div></div>' +
      '      <div class="a4-sign-col"><div class="a4-sign-line"></div><div class="a4-sign-label">Authorized Signature</div></div>' +
      '    </div>' +
      '  </div>' +
      '  <div class="a4-foot">Generated from Packing Dashboard on ' + escHtml(new Date().toLocaleString()) + '</div>' +
      '</div>' +
      '</body>' +
      '</html>';
    
    printWindow.document.write(printHtml);
    printWindow.document.close();
    
    setTimeout(function() {
      printWindow.focus();
      printWindow.print();
    }, 250);
  }

  function escHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function toDisplayLabel(key) {
    return String(key || '')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
  }

  function stringifyValue(val) {
    if (val === null || typeof val === 'undefined' || val === '') return '-';
    if (typeof val === 'object') {
      try { return JSON.stringify(val); } catch (e) { return '-'; }
    }
    return String(val);
  }

  function pickFirstValue(sources, keys) {
    var keyList = Array.isArray(keys) ? keys : [keys];
    for (var s = 0; s < sources.length; s++) {
      var src = sources[s] || {};
      for (var i = 0; i < keyList.length; i++) {
        var k = keyList[i];
        if (Object.prototype.hasOwnProperty.call(src, k)) {
          var v = src[k];
          if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') {
            return String(v);
          }
        }
      }
    }
    return '-';
  }

  function pickFirstValueLoose(sources, keys) {
    var keyList = Array.isArray(keys) ? keys : [keys];
    var lowerKeys = keyList.map(function(k) { return String(k || '').toLowerCase(); });

    for (var s = 0; s < sources.length; s++) {
      var src = sources[s] || {};
      if (!src || typeof src !== 'object') continue;

      var direct = pickFirstValue([src], keyList);
      if (direct !== '-') return direct;

      var lowerMap = {};
      Object.keys(src).forEach(function(k) {
        lowerMap[String(k).toLowerCase()] = src[k];
      });

      for (var i = 0; i < lowerKeys.length; i++) {
        var lk = lowerKeys[i];
        if (!Object.prototype.hasOwnProperty.call(lowerMap, lk)) continue;
        var v = lowerMap[lk];
        if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') {
          return String(v);
        }
      }
    }

    return '-';
  }

  function expandMetricSources(baseSources) {
    var out = [];
    (baseSources || []).forEach(function(src) {
      if (!src || typeof src !== 'object') return;
      out.push(src);
      Object.keys(src).forEach(function(k) {
        var v = src[k];
        if (v && typeof v === 'object' && !Array.isArray(v)) {
          out.push(v);
        }
      });
    });
    return out;
  }

  function toNumberOrNull(value) {
    if (value === null || typeof value === 'undefined') return null;
    var n = Number(String(value).replace(/,/g, '').trim());
    return Number.isFinite(n) ? n : null;
  }

  function buildTopSummary(job) {
    var planExtra = (job && job.plan_extra_data && typeof job.plan_extra_data === 'object') ? job.plan_extra_data : {};
    var jobExtra = (job && job.job_extra_data && typeof job.job_extra_data === 'object') ? job.job_extra_data : {};
    var sources = expandMetricSources([job || {}, planExtra, jobExtra]);

    var orderQtyRaw = pickFirstValueLoose(sources, [
      'order_quantity',
      'order_qty',
      'quantity',
      'qty_pcs',
      'order_pcs',
      'order_quantity_user',
      'quantity_user',
      'order_qty_user',
      'total_order_qty'
    ]);
    var prodQtyRaw = pickFirstValueLoose(sources, [
      'production_quantity',
      'production_qty',
      'produced_quantity',
      'produced_qty',
      'actual_qty',
      'actual_quantity',
      'output_qty',
      'completed_qty',
      'final_production_qty',
      'up_in_production'
    ]);
    var wastageRaw = pickFirstValueLoose(sources, [
      'wastage',
      'wastage_qty',
      'waste_qty',
      'wastage_percent',
      'wastage_percentage',
      'label_slitting_wastage_percentage',
      'die_cutting_wastage_percentage',
      'total_wastage'
    ]);
    var storedPercentRaw = pickFirstValueLoose(sources, [
      'production_percent',
      'production_percentage',
      'prod_percent',
      'completion_percent'
    ]);

    var orderQtyNum = toNumberOrNull(orderQtyRaw);
    var prodQtyNum = toNumberOrNull(prodQtyRaw);
    var prodPercent = '-';
    var percentClass = '';
    var extraPcs = null;
    if (orderQtyNum !== null && orderQtyNum > 0 && prodQtyNum !== null) {
      var deltaPct = ((prodQtyNum - orderQtyNum) / orderQtyNum) * 100;
      extraPcs = prodQtyNum - orderQtyNum;
      var extraPcsStr = '';
      if (extraPcs !== 0 && !isNaN(extraPcs)) {
        extraPcsStr = ' (' + (extraPcs > 0 ? '+' : '') + extraPcs + ' pcs)';
      }
      prodPercent = (deltaPct > 0 ? '+' : '') + deltaPct.toFixed(1) + '%' + extraPcsStr;
      percentClass = Math.abs(deltaPct) <= 10 ? 'metric-good' : 'metric-bad';
    } else if (storedPercentRaw !== '-') {
      var stored = String(storedPercentRaw).trim();
      prodPercent = /%$/.test(stored) ? stored : (stored + '%');
      var storedNum = toNumberOrNull(prodPercent);
      if (storedNum !== null) {
        percentClass = Math.abs(storedNum) <= 10 ? 'metric-good' : 'metric-bad';
      }
    }

    return [
      ['Job Name', pickFirstValueLoose(sources, ['plan_name', 'job_name', 'job_label']), ''],
      ['Client Name', pickFirstValueLoose(sources, ['client_name', 'customer_name']), ''],
      ['Dispatch Date', pickFirstValueLoose(sources, ['dispatch_date', 'due_date']), 'dispatch'],
      ['Order Quantity', orderQtyRaw, ''],
      ['Production Quantity', prodQtyRaw, ''],
      ['Wastage', wastageRaw, ''],
      ['Production Percent', prodPercent, percentClass]
    ];
  }

  function buildPlanningRows(job) {
    var planExtra = (job && job.plan_extra_data && typeof job.plan_extra_data === 'object') ? job.plan_extra_data : {};
    var jobExtra = (job && job.job_extra_data && typeof job.job_extra_data === 'object') ? job.job_extra_data : {};
    var prevExtra = (job && job.prev_job_extra_data && typeof job.prev_job_extra_data === 'object') ? job.prev_job_extra_data : {};
    var grandPrevExtra = (job && job.grandprev_job_extra_data && typeof job.grandprev_job_extra_data === 'object') ? job.grandprev_job_extra_data : {};
    var sourceObjects = [job || {}, planExtra, jobExtra, prevExtra, grandPrevExtra];

    var rollSeen = {};
    var rollList = [];
    function addRoll(raw) {
      if (raw === null || typeof raw === 'undefined') return;
      var rollNo = String(raw).trim();
      if (!rollNo || rollSeen[rollNo]) return;
      rollSeen[rollNo] = true;
      rollList.push(rollNo);
    }
    function addFromSource(src) {
      if (!src || typeof src !== 'object') return;
      addRoll(src.roll_no || src.parent_roll || src.roll || src.rollNumber || src.roll_number);
      ['selected_rolls', 'split_rolls', 'child_rolls', 'rolls'].forEach(function(key) {
        var arr = src[key];
        if (!Array.isArray(arr)) return;
        arr.forEach(function(it) {
          if (it && typeof it === 'object') addRoll(it.roll_no || it.parent_roll || it.roll || it.rollNumber || it.roll_number);
          else addRoll(it);
        });
      });
    }
    sourceObjects.forEach(addFromSource);

    function collectAssignedFromSource(src) {
      if (!src || typeof src !== 'object') return [];
      var out = [];
      ['selected_rolls', 'split_rolls', 'child_rolls', 'rolls'].forEach(function(key) {
        var arr = src[key];
        if (!Array.isArray(arr)) return;
        arr.forEach(function(it) {
          var raw = (it && typeof it === 'object') ? (it.roll_no || it.parent_roll || it.roll || it.rollNumber || it.roll_number) : it;
          var rollNo = String(raw == null ? '' : raw).trim();
          if (rollNo) out.push(rollNo);
        });
      });
      return out;
    }

    function stripParentRolls(list) {
      var arr = Array.isArray(list) ? list.slice() : [];
      return arr.filter(function(rollNo) {
        var base = String(rollNo || '').trim();
        if (!base) return false;
        return !arr.some(function(other) {
          var o = String(other || '').trim();
          return o !== base && o.indexOf(base + '-') === 0;
        });
      });
    }

    var assignedSeen = {};
    var assignedRolls = [];
    [jobExtra, planExtra, prevExtra, grandPrevExtra].forEach(function(src) {
      collectAssignedFromSource(src).forEach(function(rn) {
        if (assignedSeen[rn]) return;
        assignedSeen[rn] = true;
        assignedRolls.push(rn);
      });
    });
    assignedRolls = stripParentRolls(assignedRolls);
    rollList = stripParentRolls(rollList);
    var rollNoSummary = assignedRolls.length ? assignedRolls.join(', ') : (rollList.length ? rollList.join(', ') : '-');
    var sources = [{ __roll_no_summary__: rollNoSummary }].concat(sourceObjects);
    var defs = [
      ['Packing ID', ['packing_display_id']],
      ['Plan No', ['plan_no', 'plan_number']],
      ['Last Job No', ['job_no', 'last_job_no']],
      ['Job Name', ['plan_name', 'job_name', 'job_label']],
      ['Client Name', ['client_name', 'customer_name']],
      ['Order Date', ['order_date']],
      ['Dispatch Date', ['dispatch_date', 'due_date']],
      ['Roll No', ['__roll_no_summary__']],
      ['Department', ['department']],
      ['Status', ['status']],
      ['Planning Date', ['scheduled_date', 'planning_date', 'job_date']],
      ['Priority', ['plan_priority', 'priority']],
      ['Material Type', ['material_type', 'material_name', 'paper_type']],
      ['Order Quantity', ['order_quantity', 'quantity']],
      ['Order Meter', ['order_meter', 'meter']],
      ['Order Quantity User', ['order_quantity_user', 'quantity_user']],
      ['Order Meter User', ['order_meter_user', 'meter_user']],
      ['Job Label', ['job_label']],
      ['Item Width', ['item_width', 'width_mm', 'width']],
      ['Item Length', ['item_length', 'length_mm', 'length']],
      ['Gsm', ['gsm']],
      ['Core Size', ['core_size']],
      ['Core Type', ['core_type']],
      ['Printing Planning', ['printing_planning', 'planning_status']]
    ];

    return defs.map(function(def) {
      var val = pickFirstValue(sources, def[1]);
      // If value is null/undefined/empty, show '-'
      if (val === null || typeof val === 'undefined' || val === '') val = '-';
      return [def[0], val];
    });
  }

  function detailItemClass(label, value) {
    var l = String(label || '').toLowerCase();
    var v = String(value || '').toLowerCase();
    var classes = [];

    if (l === 'job name' || l === 'client name' || l === 'material type' || l === 'order quantity') {
      classes.push('emphasis');
    }

    if (l === 'priority') {
      if (v === 'urgent') {
        classes.push('priority-urgent');
      } else if (v === 'normal') {
        classes.push('priority-normal');
      }
    }

    if (l === 'dispatch date') {
      classes.push('dispatch-date');
    }

    return classes.join(' ');
  }

  function buildRollItems(job) {
    var items = [];
    var jobExtra = (job && job.job_extra_data && typeof job.job_extra_data === 'object') ? job.job_extra_data : {};
    var planExtra = (job && job.plan_extra_data && typeof job.plan_extra_data === 'object') ? job.plan_extra_data : {};
    var prevExtra = (job && job.prev_job_extra_data && typeof job.prev_job_extra_data === 'object') ? job.prev_job_extra_data : {};
    var grandPrevExtra = (job && job.grandprev_job_extra_data && typeof job.grandprev_job_extra_data === 'object') ? job.grandprev_job_extra_data : {};

    function extractRollNo(src) {
      if (!src || typeof src !== 'object') return '';
      return String(src.roll_no || src.parent_roll || src.roll || src.rollNumber || src.roll_number || '').trim();
    }

    function collectAssignedRolls(src) {
      var out = [];
      if (!src || typeof src !== 'object') return out;
      ['selected_rolls', 'split_rolls', 'child_rolls', 'rolls'].forEach(function(key) {
        var arr = src[key];
        if (!Array.isArray(arr)) return;
        arr.forEach(function(it) {
          var rn = (it && typeof it === 'object') ? extractRollNo(it) : String(it == null ? '' : it).trim();
          if (rn) out.push(rn);
        });
      });
      return out;
    }

    function stripParentRolls(list) {
      var arr = Array.isArray(list) ? list.slice() : [];
      return arr.filter(function(rollNo) {
        var base = String(rollNo || '').trim();
        if (!base) return false;
        return !arr.some(function(other) {
          var o = String(other || '').trim();
          return o !== base && o.indexOf(base + '-') === 0;
        });
      });
    }

    var assignedRollSeen = {};
    var assignedRollList = [];
    [jobExtra, planExtra, prevExtra, grandPrevExtra].forEach(function(src) {
      collectAssignedRolls(src).forEach(function(rn) {
        if (assignedRollSeen[rn]) return;
        assignedRollSeen[rn] = true;
        assignedRollList.push(rn);
      });
    });
    assignedRollList = stripParentRolls(assignedRollList);
    assignedRollSeen = {};
    assignedRollList.forEach(function(rn) { assignedRollSeen[rn] = true; });
    var hasAssignedRolls = assignedRollList.length > 0;

    function addItem(src) {
      if (!src || typeof src !== 'object') return;
      var rollNo = src.roll_no || src.parent_roll || src.roll || '-';
      var rollNoNorm = String(rollNo).trim();
      if (hasAssignedRolls && (!rollNoNorm || !assignedRollSeen[rollNoNorm])) return;
      var company = src.company || src.paper_company || src.material_company || src.client_name || '-';
      var paperType = src.paper_type || src.material_name || src.paper || src.material_type || '-';
      var width = src.width_mm || src.width || '-';
      var length = src.length_mtr || src.length || src.length_meter || '-';
      items.push({ rollNo: stringifyValue(rollNo), company: stringifyValue(company), paperType: stringifyValue(paperType), width: stringifyValue(width), length: stringifyValue(length) });
    }

    addItem(jobExtra.parent_details || {});
    addItem(prevExtra.parent_details || {});
    addItem(grandPrevExtra.parent_details || {});
    if (jobExtra.parent_roll && (!jobExtra.parent_details || typeof jobExtra.parent_details !== 'object')) {
      addItem({ parent_roll: jobExtra.parent_roll });
    }
    if (prevExtra.parent_roll && (!prevExtra.parent_details || typeof prevExtra.parent_details !== 'object')) {
      addItem({ parent_roll: prevExtra.parent_roll });
    }
    if (grandPrevExtra.parent_roll && (!grandPrevExtra.parent_details || typeof grandPrevExtra.parent_details !== 'object')) {
      addItem({ parent_roll: grandPrevExtra.parent_roll });
    }

    if (Array.isArray(jobExtra.selected_rolls)) {
      jobExtra.selected_rolls.forEach(addItem);
    }
    if (Array.isArray(planExtra.selected_rolls)) {
      planExtra.selected_rolls.forEach(addItem);
    }
    if (Array.isArray(prevExtra.selected_rolls)) {
      prevExtra.selected_rolls.forEach(addItem);
    }
    if (Array.isArray(grandPrevExtra.selected_rolls)) {
      grandPrevExtra.selected_rolls.forEach(addItem);
    }
    if (Array.isArray(jobExtra.split_rolls)) jobExtra.split_rolls.forEach(addItem);
    if (Array.isArray(planExtra.split_rolls)) planExtra.split_rolls.forEach(addItem);
    if (Array.isArray(prevExtra.split_rolls)) prevExtra.split_rolls.forEach(addItem);
    if (Array.isArray(grandPrevExtra.split_rolls)) grandPrevExtra.split_rolls.forEach(addItem);
    if (Array.isArray(jobExtra.child_rolls)) jobExtra.child_rolls.forEach(addItem);
    if (Array.isArray(planExtra.child_rolls)) planExtra.child_rolls.forEach(addItem);
    if (Array.isArray(prevExtra.child_rolls)) prevExtra.child_rolls.forEach(addItem);
    if (Array.isArray(grandPrevExtra.child_rolls)) grandPrevExtra.child_rolls.forEach(addItem);

    var fallback = {
      roll_no: hasAssignedRolls ? '' : (job.roll_no || planExtra.roll_no || prevExtra.roll_no || grandPrevExtra.roll_no || ''),
      paper_company: job.paper_company || planExtra.paper_company_name || planExtra.paper_company || planExtra.company_name || planExtra.material_company || '',
      paper_type: job.paper_type || planExtra.paper_type || planExtra.paper_name || planExtra.material_name || planExtra.material_type || '',
      width_mm: job.paper_width_mm || planExtra.paper_width_mm || planExtra.width_mm || planExtra.paper_width || planExtra.item_width || planExtra.width || '',
      length_mtr: job.paper_length_mtr || planExtra.paper_length_mtr || planExtra.length_mtr || planExtra.paper_length || planExtra.order_meter_user || planExtra.order_meter || ''
    };
    addItem(fallback);

    function normNumText(v) {
      var s = String(v == null ? '' : v).trim();
      var n = Number(s.replace(/,/g, ''));
      if (!isNaN(n)) return String(n);
      return s;
    }

    function suppressParentRows(rows) {
      var allRolls = rows.map(function(r) { return String(r.rollNo || '').trim(); }).filter(function(v) { return v !== ''; });
      return rows.filter(function(r) {
        var base = String(r.rollNo || '').trim();
        if (!base) return false;
        return !allRolls.some(function(other) {
          return other !== base && other.indexOf(base + '-') === 0;
        });
      });
    }

    items = suppressParentRows(items);

    var seen = {};
    return items.filter(function(it) {
      var key = [it.rollNo, it.company, it.paperType, normNumText(it.width), normNumText(it.length)].join('|');
      if (seen[key]) return false;
      seen[key] = true;
      return !(it.rollNo === '-' && it.company === '-' && it.paperType === '-');
    });
  }

  function safeNum(val) {
    var n = Number(String(val == null ? '' : val).replace(/,/g, '').trim());
    return isNaN(n) ? 0 : n;
  }

  function normalizeRollLots(job, fallbackQty) {
    var out = [];
    var seen = {};
    var jobExtra = (job && job.job_extra_data && typeof job.job_extra_data === 'object') ? job.job_extra_data : {};
    var planExtra = (job && job.plan_extra_data && typeof job.plan_extra_data === 'object') ? job.plan_extra_data : {};
    var prevExtra = (job && job.prev_job_extra_data && typeof job.prev_job_extra_data === 'object') ? job.prev_job_extra_data : {};
    var grandPrevExtra = (job && job.grandprev_job_extra_data && typeof job.grandprev_job_extra_data === 'object') ? job.grandprev_job_extra_data : {};

    function firstPresent(src, keys) {
      if (!src || typeof src !== 'object') return '';
      for (var i = 0; i < keys.length; i++) {
        var v = src[keys[i]];
        if (v !== null && typeof v !== 'undefined' && String(v).trim() !== '') return v;
      }
      return '';
    }

    function pushLot(src) {
      if (!src || typeof src !== 'object') return;
      var rollNoRaw = firstPresent(src, ['roll_no', 'parent_roll', 'roll', 'rollNumber', 'roll_number']);
      var rollNo = String(rollNoRaw || '').trim();
      if (!rollNo) return;
      if (seen[rollNo]) return;

      var prod = safeNum(firstPresent(src, ['production_qty', 'produced_qty', 'qty', 'quantity', 'total_qty', 'roll_qty']));
      var avail = safeNum(firstPresent(src, ['available_qty', 'available', 'avail_qty', 'qty_available', 'balance_qty']));
      if (prod <= 0) prod = safeNum(fallbackQty);
      if (avail <= 0) avail = prod;
      if (prod <= 0 && avail <= 0) return;

      seen[rollNo] = true;
      out.push({
        rollNo: rollNo,
        productionQty: Math.max(0, prod),
        availableQty: Math.max(0, avail)
      });
    }

    if (Array.isArray(jobExtra.selected_rolls)) jobExtra.selected_rolls.forEach(pushLot);
    if (Array.isArray(planExtra.selected_rolls)) planExtra.selected_rolls.forEach(pushLot);
    if (Array.isArray(prevExtra.selected_rolls)) prevExtra.selected_rolls.forEach(pushLot);
    if (Array.isArray(grandPrevExtra.selected_rolls)) grandPrevExtra.selected_rolls.forEach(pushLot);
    if (Array.isArray(jobExtra.split_rolls)) jobExtra.split_rolls.forEach(pushLot);
    if (Array.isArray(planExtra.split_rolls)) planExtra.split_rolls.forEach(pushLot);
    if (Array.isArray(prevExtra.split_rolls)) prevExtra.split_rolls.forEach(pushLot);
    if (Array.isArray(grandPrevExtra.split_rolls)) grandPrevExtra.split_rolls.forEach(pushLot);
    if (Array.isArray(jobExtra.child_rolls)) jobExtra.child_rolls.forEach(pushLot);
    if (Array.isArray(planExtra.child_rolls)) planExtra.child_rolls.forEach(pushLot);
    if (Array.isArray(prevExtra.child_rolls)) prevExtra.child_rolls.forEach(pushLot);
    if (Array.isArray(grandPrevExtra.child_rolls)) grandPrevExtra.child_rolls.forEach(pushLot);

    if (!out.length) {
      pushLot({
        roll_no: (job && job.roll_no ? job.roll_no : '') || prevExtra.roll_no || grandPrevExtra.roll_no || '',
        production_qty: fallbackQty,
        available_qty: fallbackQty
      });
    }

    return out;
  }

  function parseOperatorRollPayload(job) {
    var operatorEntry = (job && job.operator_entry && typeof job.operator_entry === 'object') ? job.operator_entry : null;
    var payload = {};
    if (operatorEntry && operatorEntry.roll_payload_json) {
      if (typeof operatorEntry.roll_payload_json === 'object') {
        payload = operatorEntry.roll_payload_json;
      } else if (typeof operatorEntry.roll_payload_json === 'string') {
        try {
          var parsed = JSON.parse(operatorEntry.roll_payload_json);
          if (parsed && typeof parsed === 'object') {
            payload = parsed;
          }
        } catch (e) {}
      }
    }

    var selectedRollKeys = Array.isArray(payload.selected_roll_keys)
      ? payload.selected_roll_keys.map(function(v) { return String(v == null ? '' : v).trim(); }).filter(function(v) { return v !== ''; })
      : [];

    var overrides = (payload.roll_overrides && typeof payload.roll_overrides === 'object') ? payload.roll_overrides : {};
    return {
      selectedRollKeys: selectedRollKeys,
      rollOverrides: overrides
    };
  }

  function buildPrintRollProductionRows(job) {
    var prodBaseline = safeNum(job && job.production_quantity ? job.production_quantity : 0);
    var rollLots = normalizeRollLots(job, prodBaseline);
    var rollItems = buildRollItems(job);
    var payload = parseOperatorRollPayload(job || {});
    var operatorEntry = (job && job.operator_entry && typeof job.operator_entry === 'object') ? job.operator_entry : null;
    var packedQtySubmitted = Math.max(0, safeNum(operatorEntry && operatorEntry.packed_qty ? operatorEntry.packed_qty : 0));
    var selectedSet = {};
    payload.selectedRollKeys.forEach(function(k) { selectedSet[k] = true; });
    var hasSelection = payload.selectedRollKeys.length > 0;

    var rollMeta = {};
    rollItems.forEach(function(item) {
      var key = String(item.rollNo || '').trim();
      if (!key) return;
      rollMeta[key] = {
        paperType: String(item.paperType || '-'),
        company: String(item.company || '-'),
        width: String(item.width || '-'),
        length: String(item.length || '-')
      };
    });

    var rows = [];
    rollLots.forEach(function(roll, idx) {
      var rollKey = String(roll.rollNo || ('roll-' + String(idx))).trim();
      if (!rollKey) return;
      if (hasSelection && !selectedSet[rollKey]) return;

      var st = (payload.rollOverrides[rollKey] && typeof payload.rollOverrides[rollKey] === 'object')
        ? payload.rollOverrides[rollKey]
        : {};

      var qty = Math.max(0, Math.min(safeNum(roll.productionQty), safeNum(roll.availableQty)));
      var rpc = Math.max(1, Math.floor(safeNum(Object.prototype.hasOwnProperty.call(st, 'rpc') ? st.rpc : 50)));
      var rps = Math.max(1, Math.floor(safeNum(Object.prototype.hasOwnProperty.call(st, 'rps') ? st.rps : 5)));
      var cartons = Object.prototype.hasOwnProperty.call(st, 'cartons')
        ? Math.max(0, Math.floor(safeNum(st.cartons)))
        : Math.floor(qty / rpc);
      var extra = Object.prototype.hasOwnProperty.call(st, 'extra')
        ? Math.max(0, Math.floor(safeNum(st.extra)))
        : (qty % rpc);
      var sizeMm = Object.prototype.hasOwnProperty.call(st, 'csize')
        ? Math.max(1, Math.floor(safeNum(st.csize)))
        : 75;
      var physical = Math.max(0, (cartons * rpc) + extra);
      var bundles = Math.floor(physical / rps);
      var meta = rollMeta[rollKey] || { paperType: '-', company: '-', width: '-', length: '-' };

      rows.push({
        rollNo: rollKey,
        paperType: meta.paperType,
        company: meta.company,
        width: meta.width,
        length: meta.length,
        sizeMm: sizeMm,
        availableQty: Math.max(0, safeNum(roll.availableQty)),
        productionQty: Math.max(0, safeNum(roll.productionQty)),
        rps: rps,
        rpc: rpc,
        cartons: cartons,
        extra: extra,
        bundles: bundles,
        physical: physical
      });
    });

    if (packedQtySubmitted > 0 && rows.length === 1) {
      var one = rows[0];
      one.productionQty = packedQtySubmitted;
      one.availableQty = Math.max(one.availableQty, packedQtySubmitted);
      one.cartons = Math.floor(packedQtySubmitted / Math.max(1, one.rpc));
      one.extra = packedQtySubmitted % Math.max(1, one.rpc);
      one.physical = packedQtySubmitted;
      one.bundles = Math.floor(packedQtySubmitted / Math.max(1, one.rps));
    }

    if (!rows.length && rollItems.length) {
      rollItems.forEach(function(item, idx) {
        var fallbackPhysical = idx === 0 ? prodBaseline : 0;
        rows.push({
          rollNo: String(item.rollNo || ('ROLL-' + String(idx + 1))),
          paperType: String(item.paperType || '-'),
          company: String(item.company || '-'),
          width: String(item.width || '-'),
          length: String(item.length || '-'),
          sizeMm: 75,
          availableQty: fallbackPhysical,
          productionQty: fallbackPhysical,
          rps: 5,
          rpc: 50,
          cartons: fallbackPhysical > 0 ? Math.floor(fallbackPhysical / 50) : 0,
          extra: fallbackPhysical > 0 ? (fallbackPhysical % 50) : 0,
          bundles: fallbackPhysical > 0 ? Math.floor(fallbackPhysical / 5) : 0,
          physical: fallbackPhysical
        });
      });
    }

    return rows;
  }

  function renderModalQr(job) {
    var target = document.getElementById('pkModalQrBox');
    if (!target) return;
    target.innerHTML = '';
    var jobNo = String(job && job.job_no ? job.job_no : '').trim();
    var text = jobNo ? (baseUrl + '/modules/scan/job.php?jn=' + encodeURIComponent(jobNo)) : '';
    if (typeof QRCode === 'function') {
      try {
        if (!text) {
          throw new Error('Missing job number for QR');
        }
        new QRCode(target, { text: text, width: 104, height: 104, colorDark: '#0f172a', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
      } catch (e) {
        target.innerHTML = '<div style="font-size:.72rem;color:#64748b;font-weight:700">QR unavailable</div>';
      }
      return;
    }
    target.innerHTML = '<div style="font-size:.72rem;color:#64748b;font-weight:700">QR unavailable</div>';
  }

  function fillModal(job) {
    if (!modalCardCanvas || !modalTitle || !modalSub || !modalHead) return;
    activeModalJob = job || null;
    var theme = readThemeVars();
    modalHead.style.setProperty('--pk-accent', theme.accent);
    modalHead.style.setProperty('--pk-soft', theme.soft);
    modalHead.style.setProperty('--pk-border', theme.border);

    modalTitle.textContent = 'Packing Job: ' + (job.packing_display_id || '-');
    modalSub.textContent = (job.plan_name || '-') + ' | Job: ' + (job.job_no || '-');

    var topSummary = buildTopSummary(job);
    var planningRows = buildPlanningRows(job);
    var rollItems = buildRollItems(job);
    var jobStatusRaw = String(job.status || '').trim();
    var jobStatusNorm = jobStatusRaw.toLowerCase().replace(/[-_]/g, ' ').trim();
    var isPackingDone = jobStatusNorm === 'packing done';
    var jobStatusText = isPackingDone ? 'Packing Done' : 'Packing';
    var sectionBLocked = isPackingDone && !canDeleteJobs;
    var targetPaperStockId = Number(job.paper_stock_id || 0);
    var operatorEntry = (job.operator_entry && typeof job.operator_entry === 'object') ? job.operator_entry : null;
    var operatorHasSubmitted = !!operatorEntry;
    var operatorRollPayload = {};
    if (operatorEntry && operatorEntry.roll_payload_json) {
      if (typeof operatorEntry.roll_payload_json === 'object') {
        operatorRollPayload = operatorEntry.roll_payload_json;
      } else if (typeof operatorEntry.roll_payload_json === 'string') {
        try {
          var parsedRollPayload = JSON.parse(operatorEntry.roll_payload_json);
          if (parsedRollPayload && typeof parsedRollPayload === 'object') {
            operatorRollPayload = parsedRollPayload;
          }
        } catch (e) {}
      }
    }
    var operatorSelectedRollKeys = Array.isArray(operatorRollPayload.selected_roll_keys)
      ? operatorRollPayload.selected_roll_keys.map(function(v) { return String(v == null ? '' : v).trim(); }).filter(function(v) { return v !== ''; })
      : [];
    var operatorRollOverrides = (operatorRollPayload.roll_overrides && typeof operatorRollPayload.roll_overrides === 'object')
      ? operatorRollPayload.roll_overrides
      : {};
    var rollHelperOverrides = {};

    function toNum(v) {
      var n = Number(String(v || '').replace(/,/g, '').trim());
      return isNaN(n) ? 0 : n;
    }

    Object.keys(operatorRollOverrides).forEach(function(k) {
      var src = operatorRollOverrides[k];
      if (!src || typeof src !== 'object') return;
      var state = {};
      ['rps', 'rpc', 'cartons', 'extra', 'csize'].forEach(function(field) {
        if (!Object.prototype.hasOwnProperty.call(src, field)) return;
        var raw = Math.floor(toNum(src[field]));
        if (!isNaN(raw)) {
          state[field] = (field === 'rps' || field === 'rpc' || field === 'csize') ? Math.max(1, raw) : Math.max(0, raw);
        }
      });
      if (Object.keys(state).length) {
        rollHelperOverrides[String(k)] = state;
      }
    });
    var prodQtyBaseline = safeNum(job && job.production_quantity ? job.production_quantity : 0);
    var rollLots = normalizeRollLots(job, prodQtyBaseline);
    var rollSelectionHtml = rollLots.length ? rollLots.map(function(roll, idx) {
      var rollKey = String(roll.rollNo || ('roll-' + String(idx)));
      var isChecked = !operatorSelectedRollKeys.length || operatorSelectedRollKeys.indexOf(rollKey) !== -1;
      return ''
        + '<label style="display:flex;align-items:flex-start;gap:8px;border:1px solid #bae6fd;border-radius:10px;padding:8px;background:#fff;cursor:pointer">'
        + '  <input type="checkbox" class="pk-roll-select" data-roll-index="' + String(idx) + '"' + (isChecked ? ' checked' : '') + ' style="margin-top:2px">'
        + '  <span style="display:flex;flex-direction:column;gap:2px">'
        + '    <b style="font-size:.78rem;color:#0f172a">' + escHtml(roll.rollNo) + '</b>'
        + '  </span>'
        + '</label>';
    }).join('') : '<div style="font-size:.78rem;color:#64748b">No roll sources found.</div>';
    var currentPackingBatches = [];
    // Finish active only if operator submitted AND job not already finished
    var finishShouldBeActive = !isPackingDone && operatorHasSubmitted;

    var imageHtml = job.image_url
      ? '<div class="pk-jc-image"><div style="font-size:.72rem;font-weight:800;color:#475569;margin-bottom:6px">Assigned Image Preview</div><img src="' + escHtml(job.image_url) + '" alt="Assigned Job Image"></div>'
      : '<div class="pk-jc-image"><div style="font-size:.74rem;color:#64748b">No image assigned for this job.</div></div>';

    modalCardCanvas.innerHTML = '' +
      '<div class="pk-jc-card" style="--pk-accent:' + escHtml(theme.accent) + ';--pk-soft:' + escHtml(theme.soft) + ';--pk-border:' + escHtml(theme.border) + '">' +
      '  <div class="pk-jc-head">' +
      '    <div class="pk-jc-brand">' +
      '      <h4>Packing Job Card</h4>' +
      '      <p>Generated from Packing Dashboard</p>' +
      '    </div>' +
      '    <div class="pk-jc-qr" id="pkModalQrBox"></div>' +
      '  </div>' +
      '  <div class="pk-jc-top-summary">' +
      '    <div class="pk-jc-top-grid">' + topSummary.map(function(item){
        var cls = item[2] ? (' ' + String(item[2])) : '';
        return '<div class="pk-jc-top-item' + cls + '"><b>' + escHtml(item[0]) + '</b><span>' + escHtml(item[1]) + '</span></div>';
          }).join('') + '</div>' +
      '  </div>' +
      '  <div class="pk-jc-section pk-jc-section-collapsible">' +
      '    <h5 class="pk-jc-section-toggle" tabindex="0" style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:7px;">' +
      '      <span class="pk-jc-section-arrow" style="display:inline-block;transition:transform .18s;">&#9660;</span> Section A: Planning and Paper Roll Details' +
      '    </h5>' +
      '    <div class="pk-jc-section-content">' +
      '      <div class="pk-jc-detail-grid pk-jc-detail-bg">' + planningRows.map(function(item){
        var cls = detailItemClass(item[0], item[1]);
        return '<div class="pk-jc-detail-item ' + escHtml(cls) + '"><b>' + escHtml(item[0]) + '</b><span>' + escHtml(item[1]) + '</span></div>';
          }).join('') + '</div>' +
        (job.image_url ? '<div style="margin-top:12px;text-align:center;border-top:1px solid #e2e8f0;padding-top:12px"><div style="font-size:.74rem;font-weight:900;color:#0f172a;margin-bottom:8px">Job Image</div><img src="' + escHtml(job.image_url) + '" alt="Job Image" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #cbd5e1"></div>' : '') +
      '      <table class="pk-jc-rolls">' +
      '        <thead><tr><th>Roll No</th><th>Paper Company</th><th>Paper Type</th><th>Width</th><th>Length</th></tr></thead>' +
      '        <tbody>' + (rollItems.length ? rollItems.map(function(it, idx){
          var rowClass = (idx % 2 === 0 ? 'pk-roll-even' : 'pk-roll-odd');
          var highlight = (it.rollNo === 'SLC/2026/0364-C') ? ' pk-roll-highlight' : '';
          return '<tr class="' + rowClass + highlight + '"><td>' + escHtml(it.rollNo) + '</td><td>' + escHtml(it.company) + '</td><td>' + escHtml(it.paperType) + '</td><td>' + escHtml(it.width) + '</td><td>' + escHtml(it.length) + '</td></tr>';
            }).join('') : '<tr><td colspan="5">No paper roll details found.</td></tr>') + '</tbody>' +
      '      </table>' +
      '    </div>' +
      '  </div>' +
          '  <div class="pk-jc-section pk-jc-section-collapsible-b">'
          + '    <h5 class="pk-jc-section-toggle-b" tabindex="0" style="cursor:pointer;user-select:none;display:flex;align-items:center;gap:7px;">'
          + '      <span class="pk-jc-section-arrow-b" style="display:inline-block;transition:transform .25s ease;font-size:1.1em;">▼</span> Section B: Wrapping and Packing Details'
          + (isPackingDone ? '<span id="pkPackingDoneBadge" style="margin-left:8px;background:#22c55e;color:#fff;font-size:.72rem;font-weight:800;padding:3px 8px;border-radius:999px;">Packing Done</span>' : '')
          + '    </h5>'
          + '    <div class="pk-jc-section-content-b">'
          + '      <div style="padding:14px 12px;margin-bottom:14px;border:1px solid #bae6fd;border-radius:12px;background:linear-gradient(120deg,#ecfeff 0%,#f0f9ff 100%);">'
          + '        <div style="font-size:.74rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#0284c7;margin-bottom:10px">From Production Department</div>'
          + '        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px 12px;align-items:end;">'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Production</label>'
            + '            <div style="font-size:1.05em;font-weight:900;color:#0f172a;"><span id="pkDashProduction">-</span></div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Available</label>'
            + '            <div style="font-size:1.05em;font-weight:900;color:#0f172a;"><span id="pkDashAvailable">-</span></div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Order Qty</label>'
            + '            <div style="font-size:1.15em;font-weight:900;color:#0f172a;">'
            + '              <span id="pkDashOrderQty">-</span>'
            + '            </div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Production Qty</label>'
            + '            <div style="font-size:1.15em;font-weight:900;color:#0f172a;">'
            + '              <span id="pkDashProdQty">-</span>'
            + '            </div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Actual %</label>'
            + '            <div style="font-size:1.15em;font-weight:900;color:#0f766e;">'
            + '              <span id="pkDashActualPercent">-</span>'
            + '            </div>'
            + '          </div>'
          + '        </div>'
          + '      </div>'
          + '      <div style="margin-bottom:14px;padding:10px;border:1px solid #bfdbfe;border-radius:10px;background:#f8fbff">'
          + '        <div style="font-size:.72rem;font-weight:900;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Roll Selection</div>'
          + '        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px">' + rollSelectionHtml + '</div>'
          + '      </div>'
          + '      <div style="padding:14px 12px;margin-bottom:14px;border:1px solid #d9f99d;border-radius:12px;background:linear-gradient(120deg,#f7fee7 0%,#fff 100%);">'
          + '        <div style="font-size:.72rem;font-weight:900;color:#3f6212;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Roll Data (Selected Roll Only)</div>'
          + '        <div id="pkHelpersRollCards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px"></div>'
          + '        <div id="pkHelpersHiddenMsg" style="display:none;margin-top:8px;padding:8px;border:1px dashed #fca5a5;border-radius:8px;background:#fff7ed;color:#9a3412;font-size:.76rem;font-weight:700">Select at least one roll to view helpers.</div>'
          + '      </div>'
          + '      <div style="padding:14px 0;">'
          + '        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px 18px;align-items:center;">'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Shrink Bundles</label>'
            + '            <div id="pkCalcBundlesContainer" style="font-size:1.15em;font-weight:900;color:#0c4169;background-color:#dbeafe;padding:8px 10px;border-radius:6px;border:1px solid #7dd3fc;">'
            + '              <span id="pkCalcBundles">-</span>'
            + '            </div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Cartons Required</label>'
            + '            <div id="pkCalcCartonsContainer" style="font-size:1.15em;font-weight:900;color:#065f46;background-color:#dcfce7;padding:8px 10px;border-radius:6px;border:1px solid #86efac;">'
            + '              <span id="pkCalcCartons">-</span>'
            + '            </div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Loose Qty</label>'
            + '            <div id="pkCalcLooseContainer" style="font-size:1.15em;font-weight:900;color:#78350f;background-color:#fed7aa;padding:8px 10px;border-radius:6px;border:1px solid #fdba74;">'
            + '              <span id="pkCalcLoose">-</span>'
            + '            </div>'
            + '          </div>'
            + '          <div style="display:flex;flex-direction:column;gap:4px;">'
            + '            <label style="font-size:.85em;font-weight:700;color:#64748b;">Physical Production</label>'
            + '            <div style="font-size:1.15em;font-weight:900;color:#166534;background-color:#dcfce7;padding:8px 10px;border-radius:6px;border:1px solid #86efac;">'
            + '              <span id="pkCalcPhysicalProduction">-</span>'
            + '            </div>'
            + '          </div>'
          + '        </div>'
          + '        <div style="margin-top:12px;border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#fff">'
          + '          <div style="font-size:.72rem;font-weight:900;color:#334155;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Per-Roll Batch Output</div>'
          + '          <div id="pkPerRollOutput" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px"></div>'
          + '        </div>'
          + '      </div>'
          + '      <div style="padding:12px 0;border-top:1px solid #eef2f7;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-start;">'
          + '        <button id="pkSectionBFinishedBtn" type="button" class="pk-action-btn pk-btn-finished" ' + (!finishShouldBeActive ? 'disabled ' : '') + 'title="' + (!finishShouldBeActive && !isPackingDone ? 'Waiting for operator to submit packing entry' : '') + '" style="background-color:' + (isPackingDone ? '#9ca3af' : (operatorHasSubmitted ? '#22c55e' : '#f97316')) + ';color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:700;cursor:' + (!finishShouldBeActive ? 'not-allowed' : 'pointer') + ';font-size:.95em;transition:all .2s ease;opacity:' + (!finishShouldBeActive ? '0.65' : '1') + ';">Packing Done</button>'
          + '        <button id="pkSectionBCancelBtn" type="button" class="pk-action-btn pk-btn-cancel" style="background-color:#6b7280;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">Cancel</button>'
          + '        <button class="pk-action-btn pk-btn-sticker" style="background-color:#3b82f6;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">Sticker Print</button>'
          + '        <button class="pk-action-btn pk-btn-label" style="background-color:#f97316;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">Label Print</button>'
          + '      </div>'
          + '      <div id="pkSectionBMessage" style="min-height:20px;font-size:.82rem;color:#475569;font-weight:700;">' + (sectionBLocked ? 'Locked: Packing Done (non-admin view)' : '') + '</div>'
          + '    </div>'
          + '  </div>'
              // Section B: Bidirectional sync logic
              setTimeout(function() {
                var orderQty = 0;
                var prodQty = 0;
                var operatorPhysicalQty = 0;
                if (job && job.order_quantity) {
                  var n = Number(String(job.order_quantity).replace(/,/g, ''));
                  if (!isNaN(n)) orderQty = n;
                }
                if (job && job.production_quantity) {
                  var n = Number(String(job.production_quantity).replace(/,/g, ''));
                  if (!isNaN(n)) prodQty = n;
                }
                if (operatorEntry && operatorEntry.packed_qty) {
                  var pn = Number(String(operatorEntry.packed_qty).replace(/,/g, ''));
                  if (!isNaN(pn) && pn > 0) operatorPhysicalQty = pn;
                }
                var dashOrderQtySpan = document.getElementById('pkDashOrderQty');
                var dashProdQtySpan = document.getElementById('pkDashProdQty');
                var dashActualPercentSpan = document.getElementById('pkDashActualPercent');
                var dashProductionSpan = document.getElementById('pkDashProduction');
                var dashAvailableSpan = document.getElementById('pkDashAvailable');
                var helperCardsWrap = document.getElementById('pkHelpersRollCards');
                var helperHiddenMsg = document.getElementById('pkHelpersHiddenMsg');
                var calcBundlesSpan = document.getElementById('pkCalcBundles');
                var calcCartonsSpan = document.getElementById('pkCalcCartons');
                var calcLooseSpan = document.getElementById('pkCalcLoose');
                var calcPhysicalSpan = document.getElementById('pkCalcPhysicalProduction');
                var perRollOutput = document.getElementById('pkPerRollOutput');
                var rollSelectNodes = Array.prototype.slice.call(document.querySelectorAll('.pk-roll-select'));
                
                // Display order and production quantities
                dashOrderQtySpan.textContent = orderQty > 0 ? orderQty : '-';
                dashProdQtySpan.textContent = prodQty > 0 ? prodQty : '-';
                if (dashProductionSpan) dashProductionSpan.textContent = prodQty > 0 ? prodQty : '-';
                if (dashAvailableSpan) dashAvailableSpan.textContent = prodQty > 0 ? prodQty : '-';
                // Display actual percent
                if (orderQty > 0 && prodQty > 0) {
                  var deltaPct = ((prodQty - orderQty) / orderQty) * 100;
                  dashActualPercentSpan.textContent = (deltaPct > 0 ? '+' : '') + deltaPct.toFixed(1) + '%';
                } else {
                  dashActualPercentSpan.textContent = '-';
                }
                
                function seedSubmittedDefaultsForSelection(selectedRolls) {
                  if (!operatorHasSubmitted || !operatorEntry || !Array.isArray(selectedRolls) || selectedRolls.length !== 1) return;
                  var roll = selectedRolls[0] || {};
                  var key = String(roll.rollNo || 'roll-0');
                  if (!rollHelperOverrides[key] || typeof rollHelperOverrides[key] !== 'object') rollHelperOverrides[key] = {};
                  var state = rollHelperOverrides[key];

                  var submittedPacked = Math.max(0, Math.floor(toNum(operatorEntry.packed_qty)));
                  var submittedCartons = Math.max(0, Math.floor(toNum(operatorEntry.cartons_count)));
                  var submittedLoose = Math.max(0, Math.floor(toNum(operatorEntry.loose_qty)));
                  var submittedBundles = Math.max(0, Math.floor(toNum(operatorEntry.bundles_count)));

                  if (!Object.prototype.hasOwnProperty.call(state, 'cartons')) state.cartons = submittedCartons;
                  if (!Object.prototype.hasOwnProperty.call(state, 'extra')) state.extra = submittedLoose;

                  if (!Object.prototype.hasOwnProperty.call(state, 'rpc')) {
                    var derivedRpc = 0;
                    if (submittedCartons > 0 && submittedPacked >= submittedLoose) {
                      derivedRpc = Math.floor((submittedPacked - submittedLoose) / submittedCartons);
                    }
                    state.rpc = Math.max(1, derivedRpc || 50);
                  }

                  if (!Object.prototype.hasOwnProperty.call(state, 'rps')) {
                    var derivedRps = 0;
                    if (submittedBundles > 0 && submittedPacked > 0) {
                      derivedRps = Math.floor(submittedPacked / submittedBundles);
                    }
                    state.rps = Math.max(1, derivedRps || 5);
                  }
                }

                function renderHelperCards(selectedRolls) {
                  if (!helperCardsWrap) return;
                  helperCardsWrap.innerHTML = selectedRolls.map(function(roll, idx) {
                    var key = String(roll.rollNo || ('roll-' + String(idx)));
                    var st = rollHelperOverrides[key] || {};
                    var qty = Math.max(0, Math.min(toNum(roll.productionQty), toNum(roll.availableQty)));
                    var rps = Math.max(1, Math.floor(toNum(st.rps || 5)));
                    var rpc = Math.max(1, Math.floor(toNum(st.rpc || 50)));
                    var cartons = Object.prototype.hasOwnProperty.call(st, 'cartons') ? Math.max(0, Math.floor(toNum(st.cartons))) : Math.floor(qty / rpc);
                    var extra = Object.prototype.hasOwnProperty.call(st, 'extra') ? Math.max(0, Math.floor(toNum(st.extra))) : (qty % rpc);
                    var totalRolls = Math.max(0, cartons * rpc + extra);
                    return ''
                      + '<div style="border:1px solid #bbf7d0;border-radius:10px;padding:10px;background:#fff">'
                      + '  <div style="font-size:.74rem;font-weight:900;color:#166534;margin-bottom:8px">Roll: ' + escHtml(String(roll.rollNo || '-')) + '</div>'
                      + '  <div style="display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:8px">'
                      + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Rolls per shrink wrap</label><input type="number" class="pk-roll-rps" data-roll-key="' + escHtml(key) + '" min="1" step="1" value="' + String(rps) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
                      + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Rolls per carton</label><input type="number" class="pk-roll-rpc" data-roll-key="' + escHtml(key) + '" min="1" step="1" value="' + String(rpc) + '" style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px"></div>'
                      + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Carton size (mm)</label><select disabled style="width:100%;padding:5px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc"><option value="75" selected>75 mm</option></select></div>'
                      + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#64748b">Cartons</label><input type="number" class="pk-roll-cartons" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(cartons) + '" style="width:100%;padding:5px 6px;border:1px solid #86efac;border-radius:6px"></div>'
                      + '    <div><label style="display:block;font-size:.68rem;font-weight:700;color:#92400e">Extra rolls</label><input type="number" class="pk-roll-extra" data-roll-key="' + escHtml(key) + '" min="0" step="1" value="' + String(extra) + '" style="width:100%;padding:5px 6px;border:1px solid #fdba74;border-radius:6px"></div>'
                      + '  </div>'
                      + '  <div style="margin-top:8px;padding:7px 9px;border:1px solid #86efac;border-radius:7px;background:#dcfce7;color:#166534;font-size:.8rem;font-weight:900">Total Rolls: <span class="pk-roll-total" data-roll-key="' + escHtml(key) + '">' + String(totalRolls) + '</span></div>'
                      + '</div>';
                  }).join('');
                }

                function bindHelperCardEvents() {
                  if (!helperCardsWrap) return;
                  function saveAndRecalc(node, field) {
                    var key = String(node.getAttribute('data-roll-key') || '').trim();
                    if (!key) return;
                    if (!rollHelperOverrides[key] || typeof rollHelperOverrides[key] !== 'object') rollHelperOverrides[key] = {};
                    var raw = Math.floor(toNum(node.value));
                    rollHelperOverrides[key][field] = field === 'rps' || field === 'rpc' ? Math.max(1, raw) : Math.max(0, raw);
                    recalc();
                  }
                  Array.prototype.slice.call(helperCardsWrap.querySelectorAll('.pk-roll-rps')).forEach(function(node) {
                    node.addEventListener('change', function() { saveAndRecalc(node, 'rps'); });
                  });
                  Array.prototype.slice.call(helperCardsWrap.querySelectorAll('.pk-roll-rpc')).forEach(function(node) {
                    node.addEventListener('change', function() { saveAndRecalc(node, 'rpc'); });
                  });
                  Array.prototype.slice.call(helperCardsWrap.querySelectorAll('.pk-roll-cartons')).forEach(function(node) {
                    node.addEventListener('change', function() { saveAndRecalc(node, 'cartons'); });
                  });
                  Array.prototype.slice.call(helperCardsWrap.querySelectorAll('.pk-roll-extra')).forEach(function(node) {
                    node.addEventListener('change', function() { saveAndRecalc(node, 'extra'); });
                  });
                }

                function recalc() {

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
                    if (helperCardsWrap) helperCardsWrap.innerHTML = '';
                    if (helperHiddenMsg) helperHiddenMsg.style.display = 'block';
                    if (calcBundlesSpan) calcBundlesSpan.textContent = '-';
                    if (calcCartonsSpan) calcCartonsSpan.textContent = '-';
                    if (calcLooseSpan) calcLooseSpan.textContent = '-';
                    if (calcPhysicalSpan) calcPhysicalSpan.textContent = '-';
                    if (perRollOutput) perRollOutput.innerHTML = '<div style="font-size:.75rem;color:#64748b">No roll selected.</div>';
                    currentPackingBatches = [];
                    return;
                  }
                  seedSubmittedDefaultsForSelection(selectedRolls);
                  if (helperHiddenMsg) helperHiddenMsg.style.display = 'none';
                  renderHelperCards(selectedRolls);
                  bindHelperCardEvents();

                  var totalBundlesNum = 0;
                  var totalCartonsNum = 0;
                  var totalLooseNum = 0;
                  var totalPhysicalNum = 0;
                  var resultCards = [];
                  var rollBatches = [];

                  selectedRolls.forEach(function(roll, rollIdx) {
                    var rollKey = String(roll.rollNo || ('roll-' + String(rollIdx)));
                    var st = rollHelperOverrides[rollKey] || {};
                    var qty = Math.max(0, Math.min(toNum(roll.productionQty), toNum(roll.availableQty)));
                    var rollRps = Math.max(1, Math.floor(toNum(st.rps || 5)));
                    var rollRpc = Math.max(1, Math.floor(toNum(st.rpc || 50)));
                    var rollCartons = Object.prototype.hasOwnProperty.call(st, 'cartons') ? Math.max(0, Math.floor(toNum(st.cartons))) : Math.floor(qty / rollRpc);
                    var rollLoose = Object.prototype.hasOwnProperty.call(st, 'extra') ? Math.max(0, Math.floor(toNum(st.extra))) : (qty % rollRpc);
                    var rollPhysical = Math.max(0, (rollCartons * rollRpc) + rollLoose);
                    var rollBundles = Math.floor(rollPhysical / rollRps);
                    totalBundlesNum += rollBundles;
                    totalCartonsNum += rollCartons;
                    totalLooseNum += rollLoose;
                    totalPhysicalNum += rollPhysical;
                    var totalRollsNode = helperCardsWrap ? helperCardsWrap.querySelector('.pk-roll-total[data-roll-key="' + rollKey.replace(/"/g, '\\"') + '"]') : null;
                    if (totalRollsNode) totalRollsNode.textContent = String(rollPhysical);
                    rollBatches.push({
                      batchNo: rollIdx + 1,
                      type: 'ROLL',
                      label: String(roll.rollNo || '-'),
                      cartons: rollCartons,
                      shrinkBundles: rollBundles,
                      looseUsed: rollLoose,
                      shortage: 0
                    });
                    resultCards.push(
                      '<div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px;background:#f8fafc">'
                      + '<b style="display:block;font-size:.73rem;color:#0f172a;margin-bottom:4px">' + escHtml(String(roll.rollNo || '-')) + '</b>'
                      + '<div style="font-size:.72rem;color:#334155">Cartons: <b>' + String(rollCartons) + '</b> | Extra: <b>' + String(rollLoose) + '</b></div>'
                      + '<div style="font-size:.72rem;color:#166534">Physical Output: <b>' + String(rollPhysical) + '</b></div>'
                      + '</div>'
                    );
                  });
                  calcBundlesSpan.textContent = totalBundlesNum;
                  calcCartonsSpan.textContent = totalCartonsNum;
                  calcLooseSpan.textContent = totalLooseNum;
                  if (calcPhysicalSpan) calcPhysicalSpan.textContent = String(totalPhysicalNum);
                  if (perRollOutput) perRollOutput.innerHTML = resultCards.join('') || '<div style="font-size:.75rem;color:#64748b">No roll selected.</div>';
                  currentPackingBatches = rollBatches;
                  
                  // Update Loose Qty container background color based on value
                  var looseContainer = document.getElementById('pkCalcLooseContainer');
                  if (looseContainer) {
                    if (totalLooseNum === 0) {
                      // Neutral light orange when zero
                      looseContainer.style.backgroundColor = '#fed7aa';
                      looseContainer.style.borderColor = '#fdba74';
                      looseContainer.style.color = '#78350f';
                    } else if (totalLooseNum > 0) {
                      // Warning red/orange when > 0
                      looseContainer.style.backgroundColor = '#fee2e2';
                      looseContainer.style.borderColor = '#fca5a5';
                      looseContainer.style.color = '#7f1d1d';
                    } else {
                      // Dash means undefined
                      looseContainer.style.backgroundColor = '#fed7aa';
                      looseContainer.style.borderColor = '#fdba74';
                      looseContainer.style.color = '#78350f';
                    }
                  }
                }

                // Event listeners
                if (rollSelectNodes.length) {
                  rollSelectNodes.forEach(function(node) {
                    node.addEventListener('change', function() {
                      recalc();
                    });
                  });
                }

                // Initial calculation.
                recalc();
              }, 10);
          + imageHtml
          + '  <div class="pk-jc-foot">'
          + '    <span>Job Card Footer: Packing Verification Copy</span>'
          + '    <span>Printed At: ' + escHtml(new Date().toLocaleString()) + '</span>'
          + '  </div>'
          + '  <div id="pk-cancel-confirmation-modal" class="pk-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:10000;justify-content:center;align-items:center;opacity:0;transition:opacity .3s ease;">'
          + '    <div class="pk-modal-content" style="background-color:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-width:450px;width:90%;padding:32px;text-align:center;transform:scale(0.95);transition:transform .3s ease;">'
          + '      <h3 style="font-size:1.3em;font-weight:700;color:#1e293b;margin:0 0 12px 0;">Cancel Job</h3>'
          + '      <p style="font-size:.95em;color:#64748b;margin:0 0 24px 0;line-height:1.5;">Are you sure you want to cancel this job?</p>'
          + '      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">'
          + '        <button id="pk-modal-btn-yes" type="button" class="pk-modal-btn pk-modal-btn-danger" style="background-color:#dc2626;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;flex:1;min-width:120px;">Yes, Cancel</button>'
          + '        <button id="pk-modal-btn-no" type="button" class="pk-modal-btn pk-modal-btn-gray" style="background-color:#6b7280;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;flex:1;min-width:120px;">No / Close</button>'
          + '      </div>'
          + '    </div>'
          + '  </div>'
          + '  <div id="pk-delete-confirmation-modal" class="pk-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:10000;justify-content:center;align-items:center;opacity:0;transition:opacity .3s ease;">'
          + '    <div class="pk-modal-content" style="background-color:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-width:460px;width:90%;padding:32px;text-align:center;transform:scale(0.95);transition:transform .3s ease;">'
          + '      <h3 style="font-size:1.3em;font-weight:700;color:#1e293b;margin:0 0 12px 0;">Delete Job</h3>'
          + '      <p style="font-size:.95em;color:#64748b;margin:0 0 24px 0;line-height:1.5;">This will permanently remove this job from packing list. Continue?</p>'
          + '      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">'
          + '        <button id="pk-delete-modal-btn-yes" type="button" class="pk-modal-btn pk-modal-btn-danger" style="background-color:#b91c1c;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;flex:1;min-width:130px;">Yes, Delete</button>'
          + '        <button id="pk-delete-modal-btn-no" type="button" class="pk-modal-btn pk-modal-btn-gray" style="background-color:#6b7280;color:#fff;border:none;padding:10px 20px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;flex:1;min-width:130px;">No / Close</button>'
          + '      </div>'
          + '    </div>'
          + '  </div>'
          + '  <div id="pk-print-confirmation-modal" class="pk-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:10000;justify-content:center;align-items:center;opacity:0;transition:opacity .3s ease;">'
          + '    <div class="pk-modal-content" style="background-color:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-width:480px;width:90%;padding:28px;text-align:left;transform:scale(0.95);transition:transform .3s ease;">'
          + '      <h3 id="pk-print-confirm-title" style="font-size:1.2em;font-weight:800;color:#1e293b;margin:0 0 8px 0;">Print Confirmation</h3>'
          + '      <p id="pk-print-confirm-message" style="font-size:.95em;color:#475569;margin:0 0 12px 0;line-height:1.5;">Do you want to print?</p>'
          + '      <div style="display:grid;grid-template-columns:1fr;gap:10px;margin:0 0 16px 0;">'
          + '        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;">'
          + '          <div style="font-size:.72em;font-weight:700;color:#64748b;">Required Qty</div>'
          + '          <div id="pk-print-confirm-qty" style="font-size:1.05em;font-weight:900;color:#0f172a;">0</div>'
          + '        </div>'
          + '      </div>'
          + '      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">'
          + '        <button id="pk-print-modal-btn-cancel" type="button" class="pk-modal-btn pk-modal-btn-gray" style="background-color:#6b7280;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">Cancel</button>'
          + '        <button id="pk-print-modal-btn-ok" type="button" class="pk-modal-btn pk-modal-btn-primary" style="background-color:#2563eb;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">OK</button>'
          + '      </div>'
          + '    </div>'
          + '  </div>'
          + '</div>';

    // Safety: some older render paths may miss modal blocks; inject if missing.
    if (!document.getElementById('pk-print-confirmation-modal')) {
      modalCardCanvas.insertAdjacentHTML('beforeend',
        '<div id="pk-print-confirmation-modal" class="pk-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:10000;justify-content:center;align-items:center;opacity:0;transition:opacity .3s ease;">'
        + '<div class="pk-modal-content" style="background-color:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.2);max-width:480px;width:90%;padding:28px;text-align:left;transform:scale(0.95);transition:transform .3s ease;">'
        + '<h3 id="pk-print-confirm-title" style="font-size:1.2em;font-weight:800;color:#1e293b;margin:0 0 8px 0;">Print Confirmation</h3>'
        + '<p id="pk-print-confirm-message" style="font-size:.95em;color:#475569;margin:0 0 12px 0;line-height:1.5;">Do you want to print?</p>'
        + '<div style="display:grid;grid-template-columns:1fr;gap:10px;margin:0 0 16px 0;">'
        + '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;"><div style="font-size:.72em;font-weight:700;color:#64748b;">Required Qty</div><div id="pk-print-confirm-qty" style="font-size:1.05em;font-weight:900;color:#0f172a;">0</div></div>'
        + '</div>'
        + '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">'
        + '<button id="pk-print-modal-btn-cancel" type="button" class="pk-modal-btn pk-modal-btn-gray" style="background-color:#6b7280;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">Cancel</button>'
        + '<button id="pk-print-modal-btn-ok" type="button" class="pk-modal-btn pk-modal-btn-primary" style="background-color:#2563eb;color:#fff;border:none;padding:10px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:.95em;transition:all .2s ease;">OK</button>'
        + '</div>'
        + '</div>'
        + '</div>'
      );
    }

    // Collapsible Section A logic
    setTimeout(function() {
      var sectionA = modalCardCanvas.querySelector('.pk-jc-section-collapsible');
      if (!sectionA) return;
      var toggleA = sectionA.querySelector('.pk-jc-section-toggle');
      var contentA = sectionA.querySelector('.pk-jc-section-content');
      var arrowA = sectionA.querySelector('.pk-jc-section-arrow');
      if (!toggleA || !contentA || !arrowA) return;
      toggleA.addEventListener('click', function() {
        var isOpenA = !contentA.classList.contains('collapsed');
        if (isOpenA) {
          contentA.classList.add('collapsed');
          arrowA.style.transform = 'rotate(-90deg)';
        } else {
          contentA.classList.remove('collapsed');
          arrowA.style.transform = '';
        }
      });
      toggleA.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') toggleA.click();
      });
      // Default expanded
      contentA.classList.remove('collapsed');
      arrowA.style.transform = '';
    }, 0);

    // Collapsible Section B logic
    setTimeout(function() {
      var section = modalCardCanvas.querySelector('.pk-jc-section-collapsible-b');
      if (!section) return;
      var toggle = section.querySelector('.pk-jc-section-toggle-b');
      var content = section.querySelector('.pk-jc-section-content-b');
      var arrow = section.querySelector('.pk-jc-section-arrow-b');
      if (!toggle || !content || !arrow) return;
      toggle.addEventListener('click', function() {
        var isOpen = !content.classList.contains('collapsed');
        if (isOpen) {
          content.classList.add('collapsed');
          arrow.style.transform = 'rotate(-90deg)';
        } else {
          content.classList.remove('collapsed');
          arrow.style.transform = 'rotate(0deg)';
        }
      });
      toggle.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') toggle.click();
      });
      // Default expanded
      content.classList.remove('collapsed');
      arrow.style.transform = 'rotate(0deg)';

      var sectionMessage = document.getElementById('pkSectionBMessage');
      var finishBtn = section.querySelector('.pk-btn-finished');
      var sectionCancelBtn = section.querySelector('#pkSectionBCancelBtn');
      var isAdminUser = !!canDeleteJobs;
      var isPackingDoneNow = String(job.status || '').trim().toLowerCase() === 'packing done';
      var operatorSubmittedNow = operatorHasSubmitted;
      var pendingPrintType = '';
      var pendingPrintUrls = [];

      function setSectionMessage(text, isError) {
        if (!sectionMessage) return;
        sectionMessage.textContent = text || '';
        sectionMessage.style.color = isError ? '#b91c1c' : '#475569';
      }

      function ensurePackingDoneBadge() {
        if (!isPackingDoneNow) return;
        var heading = section.querySelector('.pk-jc-section-toggle-b');
        if (!heading || heading.querySelector('#pkPackingDoneBadge')) return;
        var badge = document.createElement('span');
        badge.id = 'pkPackingDoneBadge';
        badge.textContent = 'Packing Done';
        badge.style.marginLeft = '8px';
        badge.style.background = '#22c55e';
        badge.style.color = '#fff';
        badge.style.fontSize = '.72rem';
        badge.style.fontWeight = '800';
        badge.style.padding = '3px 8px';
        badge.style.borderRadius = '999px';
        heading.appendChild(badge);
      }

      function getNumericValue(id) {
        var node = document.getElementById(id);
        if (!node) return 0;
        var n = Number(String(node.textContent || '').replace(/,/g, '').trim());
        return isNaN(n) ? 0 : n;
      }

      function getSelectedRollNodes() {
        return Array.prototype.slice.call(section.querySelectorAll('.pk-roll-select:checked'));
      }

      function getSelectedRollInfo() {
        var selected = getSelectedRollNodes();
        if (selected.length !== 1) return null;
        var idx = Number(selected[0].getAttribute('data-roll-index'));
        if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return null;
        var lot = rollLots[idx] || {};
        return {
          index: idx,
          rollNo: String(lot.rollNo || ('ROLL-' + String(idx + 1))),
          batchNo: String(lot.rollNo || ('ROLL-' + String(idx + 1)))
        };
      }

      function getSelectedRollLabels() {
        var selected = getSelectedRollNodes();
        if (!selected.length) return [];
        return selected.map(function(node) {
          var idx = Number(node.getAttribute('data-roll-index'));
          if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return '';
          var lot = rollLots[idx] || {};
          return String(lot.rollNo || ('ROLL-' + String(idx + 1))).trim();
        }).filter(function(v) { return v !== ''; });
      }

      function getFirstSelectedRollRpc() {
        var selected = getSelectedRollNodes();
        if (!selected.length) return 50;
        var idx = Number(selected[0].getAttribute('data-roll-index'));
        if (isNaN(idx) || idx < 0 || idx >= rollLots.length) return 50;
        var rollKey = String((rollLots[idx] || {}).rollNo || ('roll-' + String(idx)));
        var st = (rollHelperOverrides[rollKey] && typeof rollHelperOverrides[rollKey] === 'object') ? rollHelperOverrides[rollKey] : {};
        var rpc = Number(String(st.rpc == null ? '' : st.rpc));
        if (!isNaN(rpc) && rpc > 0) return Math.floor(rpc);
        var rpcInput = section.querySelector('.pk-roll-rpc');
        var rpcVal = rpcInput ? Number(String(rpcInput.value || '').trim()) : 0;
        return (!isNaN(rpcVal) && rpcVal > 0) ? Math.floor(rpcVal) : 50;
      }

      function updateStickerButtonState() {
        var stickerBtn = section.querySelector('.pk-btn-sticker');
        if (!stickerBtn) return;
        var selectedCount = getSelectedRollNodes().length;
        var shouldDisable = selectedCount !== 1;
        if (shouldDisable) {
          stickerBtn.setAttribute('disabled', 'disabled');
          stickerBtn.style.opacity = '0.55';
          stickerBtn.style.cursor = 'not-allowed';
          stickerBtn.title = selectedCount > 1 ? 'Only one roll can be selected for sticker print.' : 'Select one roll for sticker print.';
        } else {
          stickerBtn.removeAttribute('disabled');
          stickerBtn.style.opacity = '1';
          stickerBtn.style.cursor = 'pointer';
          stickerBtn.title = '';
        }
      }

      function updateLabelButtonState() {
        var labelBtn = section.querySelector('.pk-btn-label');
        if (!labelBtn) return;
        var selectedCount = getSelectedRollNodes().length;
        var shouldDisable = selectedCount < 1;
        if (shouldDisable) {
          labelBtn.setAttribute('disabled', 'disabled');
          labelBtn.style.opacity = '0.55';
          labelBtn.style.cursor = 'not-allowed';
          labelBtn.title = 'Select at least one roll for label print.';
        } else {
          labelBtn.removeAttribute('disabled');
          labelBtn.style.opacity = '1';
          labelBtn.style.cursor = 'pointer';
          labelBtn.title = '';
        }
      }

      function getStickerItemWidth() {
        function normalizeWidth(v) {
          if (v === null || typeof v === 'undefined') return '';
          var raw = String(v).replace(/\s*mm$/i, '').trim();
          if (!raw) return '';
          var n = Number(raw.replace(/,/g, ''));
          // Guardrail: sticker item width should be realistic, not long meter values.
          if (isNaN(n) || n <= 0 || n > 2000) return '';
          return Math.abs(n - Math.round(n)) < 0.001 ? String(Math.round(n)) : String(n.toFixed(2)).replace(/\.00$/, '');
        }

        var candidate = '';
        var jobExtra = (job && job.job_extra_data && typeof job.job_extra_data === 'object') ? job.job_extra_data : {};
        var planExtra = (job && job.plan_extra_data && typeof job.plan_extra_data === 'object') ? job.plan_extra_data : {};

        // First priority: "Item Width" value from Section A detail grid.
        var detailItems = section.querySelectorAll('.pk-jc-detail-item');
        for (var d = 0; d < detailItems.length; d++) {
          var labelNode = detailItems[d].querySelector('b');
          var valueNode = detailItems[d].querySelector('span');
          var label = labelNode ? String(labelNode.textContent || '').trim().toLowerCase() : '';
          if (label === 'item width') {
            candidate = normalizeWidth(valueNode ? valueNode.textContent : '');
            if (candidate) return candidate;
          }
        }

        // Second priority: width shown in roll table.
        var widthCell = section.querySelector('.pk-jc-rolls tbody tr td:nth-child(4)');
        if (widthCell) {
          candidate = normalizeWidth(widthCell.textContent || '');
          if (candidate) return candidate;
        }

        var picks = [
          job && job.item_width,
          job && job.width_mm,
          job && job.paper_width_mm,
          job && job.paper_width,
          planExtra.item_width,
          planExtra.width_mm,
          planExtra.paper_width_mm,
          planExtra.paper_width,
          planExtra.width,
          jobExtra.item_width,
          jobExtra.width_mm,
          jobExtra.width
        ];

        for (var i = 0; i < picks.length; i++) {
          candidate = normalizeWidth(picks[i]);
          if (candidate) return candidate;
        }

        return '';
      }

      function resolvePaperStockId() {
        if (targetPaperStockId > 0) {
          return Promise.resolve(targetPaperStockId);
        }
        if (!activeJobIdForModal) {
          return Promise.resolve(0);
        }
        var url = baseUrl + '/modules/packing/api.php?action=resolve_paper_stock_id&job_id=' + encodeURIComponent(String(activeJobIdForModal));
        return fetch(url, { credentials: 'same-origin' })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data && data.ok) {
              targetPaperStockId = Number(data.paper_stock_id || 0);
              return targetPaperStockId;
            }
            return 0;
          })
          .catch(function() {
            return 0;
          });
      }

      function executePendingPrint(printModal) {
        if (!pendingPrintType || !Array.isArray(pendingPrintUrls) || !pendingPrintUrls.length) {
          setSectionMessage('Print type missing.', true);
          if (printModal) {
            printModal.style.opacity = '0';
            var missingTypeContent = printModal.querySelector('.pk-modal-content');
            if (missingTypeContent) missingTypeContent.style.transform = 'scale(0.95)';
            setTimeout(function() {
              printModal.style.display = 'none';
            }, 300);
          }
          return;
        }

        pendingPrintUrls.forEach(function(url) {
          window.open(url, '_blank');
        });
        setSectionMessage((pendingPrintType === 'sticker' ? 'Sticker' : 'Label') + ' Print opened.', false);
        if (printModal) {
          printModal.style.opacity = '0';
          var doneContent = printModal.querySelector('.pk-modal-content');
          if (doneContent) doneContent.style.transform = 'scale(0.95)';
          setTimeout(function() {
            printModal.style.display = 'none';
          }, 300);
        }
      }

      function openPrintConfirmModal(printType) {
        if (printType === 'sticker' && !getSelectedRollInfo()) {
          updateStickerButtonState();
          updateLabelButtonState();
          setSectionMessage('Sticker print requires exactly one selected roll.', true);
          return;
        }

        var printModal = modalCardCanvas.querySelector('#pk-print-confirmation-modal') || document.getElementById('pk-print-confirmation-modal');
        var titleEl = printModal ? printModal.querySelector('#pk-print-confirm-title') : null;
        var msgEl = printModal ? printModal.querySelector('#pk-print-confirm-message') : null;
        var qtyEl = printModal ? printModal.querySelector('#pk-print-confirm-qty') : null;
        if (!printModal || !titleEl || !msgEl || !qtyEl) {
          setSectionMessage('Print modal load failed. Please reopen this job modal.', true);
          return;
        }

        var requiredQty = 0;
        if (printType === 'sticker') {
          requiredQty = getNumericValue('pkCalcBundles');
          titleEl.textContent = 'Sticker Print';
          msgEl.textContent = 'Do you want to print sticker?';
        } else {
          requiredQty = getNumericValue('pkCalcCartons');
          titleEl.textContent = 'Label Print';
          msgEl.textContent = 'Do you want to print label for each box?';
        }

        qtyEl.textContent = String(requiredQty);
        pendingPrintType = printType;
        pendingPrintUrls = [];
        var btnPrintOk = printModal.querySelector('#pk-print-modal-btn-ok');
        var btnPrintCancel = printModal.querySelector('#pk-print-modal-btn-cancel');
        if (btnPrintOk) btnPrintOk.onclick = function(e) {
          if (e) {
            e.preventDefault();
            e.stopPropagation();
          }
          executePendingPrint(printModal);
        };
        if (btnPrintOk) btnPrintOk.setAttribute('data-print-type', printType);
        if (btnPrintOk) btnPrintOk.disabled = true;
        if (btnPrintCancel) btnPrintCancel.onclick = function() {
          printModal.style.opacity = '0';
          var cancelContent = printModal.querySelector('.pk-modal-content');
          if (cancelContent) cancelContent.style.transform = 'scale(0.95)';
          setTimeout(function() {
            printModal.style.display = 'none';
          }, 300);
        };
        printModal.style.display = 'flex';
        printModal.style.opacity = '0';
        var printContent = printModal.querySelector('.pk-modal-content');
        if (printContent) printContent.style.transform = 'scale(0.95)';
        msgEl.textContent = printType === 'sticker' ? 'Preparing sticker print preview...' : 'Preparing label print preview...';

        var safeQty = requiredQty > 0 ? requiredQty : 1;
        var sizeParam = printType === 'sticker' ? '40x25' : '150x100';
        var selectedRollInfo = printType === 'sticker' ? getSelectedRollInfo() : null;
        var selectedRollLabels = printType === 'label' ? getSelectedRollLabels() : [];
        var rollsPerCarton = printType === 'label' ? getFirstSelectedRollRpc() : 0;
        var labelJobName = String(job && job.plan_name ? job.plan_name : (job && job.job_name ? job.job_name : ''));
        var rollsPerShrinkWrap = Number((section.querySelector('.pk-roll-rps') || {}).value || 0);
        if (!rollsPerShrinkWrap || rollsPerShrinkWrap < 1) rollsPerShrinkWrap = 5;
        var stickerItemWidth = getStickerItemWidth();
        var batches = (Array.isArray(currentPackingBatches) && currentPackingBatches.length) ? currentPackingBatches : null;
        var backUrl = window.location.href;

        resolvePaperStockId().then(function(resolvedId) {
          if (!resolvedId) {
            msgEl.textContent = 'Paper stock id not found for this job.';
            setSectionMessage('Paper stock id not found for this job.', true);
            return;
          }

          if (printType === 'label') {
            var labelBatchNo = selectedRollLabels.length ? selectedRollLabels.join(', ') : '';
            pendingPrintUrls = [
              baseUrl + '/modules/paper_stock/label.php?ids=' + encodeURIComponent(String(resolvedId))
                + '&print_type=' + encodeURIComponent(printType)
                + '&required_qty=' + encodeURIComponent(String(safeQty))
                + '&label_size=' + encodeURIComponent(sizeParam)
                + '&bundle_pcs=' + encodeURIComponent(String(rollsPerShrinkWrap))
                + '&rolls_per_carton=' + encodeURIComponent(String(rollsPerCarton || 0))
                + '&item_width=' + encodeURIComponent(String(stickerItemWidth || ''))
                + '&job_name=' + encodeURIComponent(labelJobName)
                + '&batch_labels=' + encodeURIComponent(labelBatchNo)
                + '&batch_no=' + encodeURIComponent(labelBatchNo)
                + '&back_url=' + encodeURIComponent(backUrl)
            ];
          } else if (!batches || batches.length <= 1) {
            pendingPrintUrls = [
              baseUrl + '/modules/paper_stock/label.php?ids=' + encodeURIComponent(String(resolvedId))
                + '&print_type=' + encodeURIComponent(printType)
                + '&required_qty=' + encodeURIComponent(String(safeQty))
                + '&label_size=' + encodeURIComponent(sizeParam)
                + '&bundle_pcs=' + encodeURIComponent(String(rollsPerShrinkWrap))
                + '&item_width=' + encodeURIComponent(String(stickerItemWidth || ''))
                + (selectedRollInfo ? ('&batch_no=' + encodeURIComponent(selectedRollInfo.batchNo) + '&batch_label=' + encodeURIComponent(selectedRollInfo.rollNo)) : '')
            ];
          } else {
            pendingPrintUrls = batches.map(function(batch) {
              var batchQty = printType === 'sticker'
                ? Math.max(0, Number(batch.shrinkBundles || 0))
                : Math.max(0, Number(batch.cartons || 0));
              if (batchQty <= 0) return '';
              var batchLabel = String(batch.label || ('Batch ' + String(batch.batchNo || '')));
              return baseUrl + '/modules/paper_stock/label.php?ids=' + encodeURIComponent(String(resolvedId))
                + '&print_type=' + encodeURIComponent(printType)
                + '&required_qty=' + encodeURIComponent(String(batchQty))
                + '&label_size=' + encodeURIComponent(sizeParam)
                + '&bundle_pcs=' + encodeURIComponent(String(rollsPerShrinkWrap))
                + '&item_width=' + encodeURIComponent(String(stickerItemWidth || ''))
                + '&batch_label=' + encodeURIComponent(batchLabel);
            }).filter(function(v) { return v !== ''; });
          }

          if (!pendingPrintUrls.length) {
            msgEl.textContent = 'Nothing available to print.';
            setSectionMessage('Nothing available to print.', true);
            return;
          }

          if (btnPrintOk) btnPrintOk.disabled = false;
          msgEl.textContent = printType === 'sticker'
            ? 'Sticker print preview is ready. Press OK to open.'
            : 'Label print preview is ready. Press OK to open.';
        }).catch(function() {
          msgEl.textContent = 'Print preview prepare failed. Please try again.';
          setSectionMessage('Print preview prepare failed. Please try again.', true);
        });

        setTimeout(function() {
          printModal.style.opacity = '1';
          if (printContent) printContent.style.transform = 'scale(1)';
        }, 10);
      }

      function applySectionBLockState() {
        var shouldLock = isPackingDoneNow && !isAdminUser;
        var editableFields = section.querySelectorAll('.pk-jc-section-content-b input, .pk-jc-section-content-b select, .pk-jc-section-content-b textarea');
        editableFields.forEach(function(field) {
          if (shouldLock) {
            field.setAttribute('disabled', 'disabled');
            field.style.backgroundColor = '#e5e7eb';
            field.style.color = '#6b7280';
            field.style.cursor = 'not-allowed';
            field.style.pointerEvents = 'none';
          } else {
            field.removeAttribute('disabled');
            field.style.backgroundColor = '';
            field.style.color = '';
            field.style.cursor = '';
            field.style.pointerEvents = '';
          }
        });

        if (finishBtn) {
          var canFinishNow = !isPackingDoneNow && operatorSubmittedNow;
          if (isPackingDoneNow) {
            finishBtn.setAttribute('disabled', 'disabled');
            finishBtn.style.backgroundColor = '#9ca3af';
            finishBtn.style.cursor = 'not-allowed';
            finishBtn.style.opacity = '0.75';
            finishBtn.title = '';
          } else if (!operatorSubmittedNow) {
            finishBtn.setAttribute('disabled', 'disabled');
            finishBtn.style.backgroundColor = '#f97316';
            finishBtn.style.cursor = 'not-allowed';
            finishBtn.style.opacity = '0.65';
            finishBtn.title = 'Waiting for operator to submit packing entry';
          } else {
            finishBtn.removeAttribute('disabled');
            finishBtn.style.backgroundColor = '#22c55e';
            finishBtn.style.cursor = 'pointer';
            finishBtn.style.opacity = '1';
            finishBtn.title = '';
          }
        }

        ensurePackingDoneBadge();
        if (isPackingDoneNow && !isAdminUser) {
          setSectionMessage('Locked: Packing Done (non-admin view)', false);
        } else if (isPackingDoneNow && isAdminUser) {
          setSectionMessage('Packing Done: admin override enabled for edits.', false);
        } else if (!operatorSubmittedNow) {
          setSectionMessage('&#9679; Waiting: Operator has not yet submitted packing quantities. Finish button will activate once submitted.', false);
        }
      }

      applySectionBLockState();

      // Footer Cancel uses same behavior as header Close button
      if (sectionCancelBtn) {
        sectionCancelBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          closeModal();
        });
      }

      function openDirectLabelPreview() {
        var requiredQty = getNumericValue('pkCalcCartons');
        var safeQty = requiredQty > 0 ? requiredQty : 1;
        var selectedRollLabels = getSelectedRollLabels();
        var rollsPerCarton = getFirstSelectedRollRpc();
        var labelJobName = String(job && job.plan_name ? job.plan_name : (job && job.job_name ? job.job_name : ''));
        var rollsPerShrinkWrap = Number((section.querySelector('.pk-roll-rps') || {}).value || 0);
        if (!rollsPerShrinkWrap || rollsPerShrinkWrap < 1) rollsPerShrinkWrap = 5;
        var stickerItemWidth = getStickerItemWidth();
        var backUrl = window.location.href;

        // Use the same barcode value as sticker print: for each selected roll, batch_no and batch_labels should be the roll number
        var batchNo = selectedRollLabels.length ? selectedRollLabels.join(',') : '';
        var batchLabels = batchNo;

        var labelWin = window.open('about:blank', '_blank');
        if (!labelWin) {
          setSectionMessage('Popup blocked. Please allow popups for label print.', true);
          return;
        }

        setSectionMessage('Preparing label print preview...', false);
        resolvePaperStockId().then(function(resolvedId) {
          if (!resolvedId) {
            if (!labelWin.closed) labelWin.close();
            setSectionMessage('Paper stock id not found for this job.', true);
            return;
          }
          var labelPrintUrl = baseUrl + '/modules/paper_stock/label.php?ids=' + encodeURIComponent(String(resolvedId))
            + '&print_type=label'
            + '&required_qty=' + encodeURIComponent(String(safeQty))
            + '&label_size=150x100'
            + '&bundle_pcs=' + encodeURIComponent(String(rollsPerShrinkWrap))
            + '&rolls_per_carton=' + encodeURIComponent(String(rollsPerCarton || 0))
            + '&item_width=' + encodeURIComponent(String(stickerItemWidth || ''))
            + '&job_name=' + encodeURIComponent(labelJobName)
            + '&batch_labels=' + encodeURIComponent(batchLabels)
            + '&batch_no=' + encodeURIComponent(batchNo)
            + '&back_url=' + encodeURIComponent(backUrl);
          labelWin.location.href = labelPrintUrl;
          setSectionMessage('Label print preview opened.', false);
        }).catch(function(err) {
          if (!labelWin.closed) labelWin.close();
          setSectionMessage('Label print preview failed to open.', true);
        });
      }

      // Hard fallback bindings to ensure print buttons always respond.
      var stickerBtn = section.querySelector('.pk-btn-sticker');
      var labelBtn = section.querySelector('.pk-btn-label');
      if (stickerBtn) {
        stickerBtn.onclick = function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (stickerBtn.hasAttribute('disabled')) return;
          openPrintConfirmModal('sticker');
        };
      }
      if (labelBtn) {
        labelBtn.onclick = function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (labelBtn.hasAttribute('disabled')) return;
          openDirectLabelPreview();
        };
      }
      
      // Add button hover and click effects
      var buttons = section.querySelectorAll('.pk-action-btn');
      buttons.forEach(function(btn) {
        btn.addEventListener('mouseenter', function() {
          this.style.opacity = '0.9';
          this.style.transform = 'translateY(-1px)';
        });
        btn.addEventListener('mouseleave', function() {
          this.style.opacity = '1';
          this.style.transform = 'translateY(0)';
        });
        btn.addEventListener('mousedown', function() {
          this.style.transform = 'translateY(1px)';
        });
        btn.addEventListener('mouseup', function() {
          this.style.transform = 'translateY(-1px)';
        });
        
        // Add click handler for each button
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (this.hasAttribute('disabled')) return;
          var buttonAction = '';
          if (this.classList.contains('pk-btn-finished')) buttonAction = 'finished';
          else if (this.classList.contains('pk-btn-cancel')) buttonAction = 'cancel';
          else if (this.classList.contains('pk-btn-sticker')) buttonAction = 'sticker';
          else if (this.classList.contains('pk-btn-label')) buttonAction = 'label';
          if (!buttonAction) return;
          var jobData = {
            jobId: activeJobIdForModal,
            action: buttonAction,
            timestamp: new Date().toISOString()
          };
          
          if (buttonAction === 'finished') {
            if (isPackingDoneNow || !operatorSubmittedNow || (finishBtn && finishBtn.getAttribute('data-saving') === '1')) return;
            if (finishBtn) {
              finishBtn.setAttribute('data-saving', '1');
              finishBtn.setAttribute('disabled', 'disabled');
              finishBtn.textContent = 'Saving...';
              finishBtn.style.backgroundColor = '#64748b';
            }
            setSectionMessage('Updating status to Packing Done...', false);

            var fdFinish = new FormData();
            fdFinish.append('action', 'mark_packing_done');
            fdFinish.append('job_id', String(activeJobIdForModal));

            fetch(baseUrl + '/modules/packing/api.php', {
              method: 'POST',
              body: fdFinish,
              credentials: 'same-origin'
            })
              .then(function(r) { return r.json(); })
              .then(function(resp) {
                if (!resp || !resp.ok) {
                  setSectionMessage((resp && resp.message) ? resp.message : 'Status update failed.', true);
                  isPackingDoneNow = false;
                  return;
                }
                isPackingDoneNow = true;
                job.status = 'Packing Done';
                applySectionBLockState();
                setSectionMessage('Status updated: Packing Done ✓ Moving to History...', false);

                // Remove row from active tab and reload page so History is updated.
                var row = document.querySelector('tr[data-row-id="' + String(activeJobIdForModal) + '"]');
                if (row && row.parentNode) {
                  row.parentNode.removeChild(row);
                }
                closeModal();
                setTimeout(function() { window.location.href = window.location.pathname + '?tab=history'; }, 800);
              })
              .catch(function() {
                setSectionMessage('Status update failed.', true);
                isPackingDoneNow = false;
              })
              .finally(function() {
                if (finishBtn) {
                  finishBtn.removeAttribute('data-saving');
                  finishBtn.textContent = 'Finished';
                  applySectionBLockState();
                }
              });
          } else if (buttonAction === 'cancel') {
            closeModal();
          } else if (buttonAction === 'sticker') {
            openPrintConfirmModal('sticker');
          } else if (buttonAction === 'label') {
            openDirectLabelPreview();
          }
        });
      });

      var rollSelectForPrintState = Array.prototype.slice.call(section.querySelectorAll('.pk-roll-select'));
      if (rollSelectForPrintState.length) {
        rollSelectForPrintState.forEach(function(node) {
          node.addEventListener('change', function() {
            updateStickerButtonState();
            updateLabelButtonState();
          });
        });
      }
      updateStickerButtonState();
      updateLabelButtonState();
      
      // Set up cancel confirmation modal
      setTimeout(function() {
        var modal = document.getElementById('pk-cancel-confirmation-modal');
        var btnYes = document.getElementById('pk-modal-btn-yes');
        var btnNo = document.getElementById('pk-modal-btn-no');
        if (!modal || !btnYes || !btnNo) return;
        var modalContent = modal.querySelector('.pk-modal-content');
        
        function closeCancelModal() {
          modal.classList.remove('pk-modal-show');
          modal.style.opacity = '0';
          if (modalContent) {
            modalContent.style.transform = 'scale(0.95)';
          }
          setTimeout(function() {
            modal.style.display = 'none';
          }, 300);
        }
        
        function openCancelModal() {
          modal.style.display = 'flex';
          modal.style.opacity = '0';
          if (modalContent) {
            modalContent.style.transform = 'scale(0.95)';
          }
          setTimeout(function() {
            modal.style.opacity = '1';
            if (modalContent) {
              modalContent.style.transform = 'scale(1)';
            }
          }, 10);
        }
        
        // Store the original openModal function for the parent scope
        window.pkOpenCancelModal = openCancelModal;
        
        // Yes button - proceed with cancel
        btnYes.addEventListener('click', function() {
          console.log('Job cancel confirmed');
          closeCancelModal();
          closeModal();
        });
        
        // No button - close modal
        btnNo.addEventListener('click', function() {
          console.log('Job cancel cancelled');
          closeCancelModal();
        });
        
        // Click outside modal (on overlay) to close
        modal.addEventListener('click', function(e) {
          if (e.target === modal) {
            closeCancelModal();
          }
        });
        
        // Add hover effects to modal buttons
        var modalButtons = modal.querySelectorAll('.pk-modal-btn');
        modalButtons.forEach(function(btn) {
          btn.addEventListener('mouseenter', function() {
            this.style.opacity = '0.9';
            this.style.transform = 'translateY(-1px)';
          });
          btn.addEventListener('mouseleave', function() {
            this.style.opacity = '1';
            this.style.transform = 'translateY(0)';
          });
          btn.addEventListener('mousedown', function() {
            this.style.transform = 'translateY(1px)';
          });
          btn.addEventListener('mouseup', function() {
            this.style.transform = 'translateY(-1px)';
          });
        });
        
        // Keyboard support - Escape to close
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && modal.style.display === 'flex') {
            closeCancelModal();
          }
        });
      }, 10);

      // Set up sticker/label print confirmation modal
      setTimeout(function() {
        var printModal = modalCardCanvas.querySelector('#pk-print-confirmation-modal') || document.getElementById('pk-print-confirmation-modal');
        var btnPrintOk = printModal ? printModal.querySelector('#pk-print-modal-btn-ok') : null;
        var btnPrintCancel = printModal ? printModal.querySelector('#pk-print-modal-btn-cancel') : null;
        if (!printModal || !btnPrintOk || !btnPrintCancel) return;
        var printContent = printModal.querySelector('.pk-modal-content');

        function closePrintModal() {
          printModal.style.opacity = '0';
          if (printContent) printContent.style.transform = 'scale(0.95)';
          setTimeout(function() {
            printModal.style.display = 'none';
          }, 300);
        }

        btnPrintCancel.onclick = function() {
          closePrintModal();
        };

        btnPrintOk.onclick = function(e) {
          if (e) {
            e.preventDefault();
            e.stopPropagation();
          }
          executePendingPrint(printModal);
        };

        printModal.onclick = function(e) {
          if (e.target === printModal) {
            closePrintModal();
          }
        };

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && printModal.style.display === 'flex') {
            closePrintModal();
          }
        });
      }, 10);

      // Set up delete confirmation modal (admin only)
      setTimeout(function() {
        if (!isAdminUser) return;
        var deleteModal = document.getElementById('pk-delete-confirmation-modal');
        var btnDeleteYes = document.getElementById('pk-delete-modal-btn-yes');
        var btnDeleteNo = document.getElementById('pk-delete-modal-btn-no');
        if (!deleteModal || !btnDeleteYes || !btnDeleteNo) return;
        var deleteContent = deleteModal.querySelector('.pk-modal-content');

        function closeDeleteModal() {
          deleteModal.style.opacity = '0';
          if (deleteContent) deleteContent.style.transform = 'scale(0.95)';
          setTimeout(function() {
            deleteModal.style.display = 'none';
          }, 300);
        }

        btnDeleteYes.addEventListener('click', function() {
          closeDeleteModal();
          deleteActiveJob(true);
        });

        btnDeleteNo.addEventListener('click', function() {
          closeDeleteModal();
        });

        deleteModal.addEventListener('click', function(e) {
          if (e.target === deleteModal) {
            closeDeleteModal();
          }
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && deleteModal.style.display === 'flex') {
            closeDeleteModal();
          }
        });
      }, 10);
    }, 0);

    renderModalQr(job);
  }

  function openJobModal(jobId) {
    if (!jobId) return;
    activeJobIdForModal = Number(jobId) || 0;
    var url = baseUrl + '/modules/packing/api.php?action=job_details&job_id=' + encodeURIComponent(String(jobId));
    fetch(url, { credentials: 'same-origin' })
      .then(function(r) {
        return r.text().then(function(text) {
          var data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (err) {
            throw new Error('Server returned an invalid response for job details.');
          }
          if (!r.ok && data && data.message) {
            throw new Error(data.message);
          }
          if (!r.ok) {
            throw new Error('Could not load job details.');
          }
          return data;
        });
      })
      .then(function(data) {
        if (!data || !data.ok || !data.job) {
          alert((data && data.message) ? data.message : 'Could not load job details.');
          return;
        }
        fillModal(data.job);
        openModal();
      })
      .catch(function(err) {
        alert((err && err.message) ? err.message : 'Could not load job details.');
      });
  }

  function deleteActiveJob(confirmedByModal) {
    if (!canDeleteJobs || !activeJobIdForModal) return;
    if (!confirmedByModal) return;

    var messageEl = document.getElementById('pkSectionBMessage');
    if (messageEl) {
      messageEl.textContent = 'Deleting job...';
      messageEl.style.color = '#475569';
    }

    var fd = new FormData();
    fd.append('action', 'delete_job');
    fd.append('job_id', String(activeJobIdForModal));

    fetch(baseUrl + '/modules/packing/api.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok) {
          if (messageEl) {
            messageEl.textContent = (data && data.message) ? data.message : 'Delete failed.';
            messageEl.style.color = '#b91c1c';
          }
          return;
        }
        var row = document.querySelector('tr[data-row-id="' + String(activeJobIdForModal) + '"]');
        if (row && row.parentNode) {
          row.parentNode.removeChild(row);
        }
        closeModal();
      })
      .catch(function() {
        if (messageEl) {
          messageEl.textContent = 'Delete failed.';
          messageEl.style.color = '#b91c1c';
        }
      });
  }

  tabs.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tab = btn.getAttribute('data-tab');
      // Full page reload on tab click so PHP fetches correct data for each tab.
      var url = window.location.pathname + '?tab=' + encodeURIComponent(tab);
      if (q) url += '&q=' + encodeURIComponent(q);
      if (from) url += '&from=' + encodeURIComponent(from);
      if (to) url += '&to=' + encodeURIComponent(to);
      window.location.href = url;
    });
  });

  panes.forEach(bindPane);
  activateTab(activeTab);

  function exportUrl(autoprint) {
    var ids = activeIds();
    if (!ids.length) {
      return '';
    }
    var url = baseUrl + '/modules/packing/export.php?mode=selected';
    url += '&tab=' + encodeURIComponent(activeTab);
    url += '&ids=' + encodeURIComponent(ids.join(','));
    if (q) url += '&search=' + encodeURIComponent(q);
    if (from) url += '&from=' + encodeURIComponent(from);
    if (to) url += '&to=' + encodeURIComponent(to);
    if (autoprint) url += '&autoprint=1';
    return url;
  }

  exportBtn.addEventListener('click', function() {
    var url = exportUrl(false);
    if (!url) return;
    window.open(url, '_blank');
  });

  printBtn.addEventListener('click', function() {
    var url = exportUrl(true);
    if (!url) return;
    window.open(url, '_blank');
  });

  document.querySelectorAll('.pk-open-modal').forEach(function(btn) {
    btn.addEventListener('click', function() {
      openJobModal(btn.getAttribute('data-job-id'));
    });
  });

  if (modal) {
    modal.addEventListener('click', function(ev) {
      if (ev.target && ev.target.getAttribute('data-close-modal') === '1') {
        closeModal();
      }
    });
  }

  if (closeModalBtn) {
    closeModalBtn.addEventListener('click', closeModal);
  }

  if (deleteBtn) {
    deleteBtn.addEventListener('click', function() {
      if (!canDeleteJobs) return;
      var deleteModal = document.getElementById('pk-delete-confirmation-modal');
      if (!deleteModal) return;
      var deleteContent = deleteModal.querySelector('.pk-modal-content');
      deleteModal.style.display = 'flex';
      deleteModal.style.opacity = '0';
      if (deleteContent) deleteContent.style.transform = 'scale(0.95)';
      setTimeout(function() {
        deleteModal.style.opacity = '1';
        if (deleteContent) deleteContent.style.transform = 'scale(1)';
      }, 10);
    });
  }

  var printBtn = document.getElementById('pkPrintDetailsBtn');
  if (printBtn) {
    printBtn.addEventListener('click', function() {
      printJobDetails();
    });
  }

  document.addEventListener('keydown', function(ev) {
    if (ev.key === 'Escape') {
      closeModal();
    }
  });
})();
</script>
<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
