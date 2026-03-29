<?php
// ================================================================
// Scan → Job Card Viewer
// URL: /modules/scan/job.php?jn=FLX/2026/0001
// Works on desktop & mobile. Opens when QR code is scanned.
// ================================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db          = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';

$jn = trim((string)($_GET['jn'] ?? ''));
$job = null;
$error = '';

if ($jn === '') {
    $error = 'No job number provided. Please scan a valid job card QR code.';
} else {
    $stmt = $db->prepare("
        SELECT j.*,
               ps.paper_type, ps.company AS supplier, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
               p.job_name AS planning_job_name, p.status AS planning_status,
               p.priority AS planning_priority, p.machine, p.operator_name, p.scheduled_date, p.notes AS planning_notes,
               prev.job_no AS prev_job_no, prev.status AS prev_job_status
        FROM jobs j
        LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
        LEFT JOIN planning p ON j.planning_id = p.id
        LEFT JOIN jobs prev ON j.previous_job_id = prev.id
        WHERE j.job_no = ?
          AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
        LIMIT 1
    ");
    $stmt->bind_param('s', $jn);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    if (!$job) {
        $error = 'Job card not found for: ' . htmlspecialchars($jn, ENT_QUOTES);
    }
}

// Parse extra_data fields if job found
$extra = [];
if ($job) {
    $extra = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
}

// Determine the management page link based on job_type & department
function getDeptPageUrl(array $job): string {
    $type = strtolower(trim((string)($job['job_type'] ?? '')));
    $dept = strtolower(trim((string)($job['department'] ?? '')));
    if ($type === 'slitting' || $dept === 'jumbo_slitting') {
        return BASE_URL . '/modules/jobs/jumbo/index.php?auto_job=' . $job['id'];
    }
    if ($type === 'printing' || str_contains($dept, 'print')) {
        return BASE_URL . '/modules/jobs/printing/index.php?auto_job=' . $job['id'];
    }
    return BASE_URL . '/modules/jobs/jumbo/index.php?auto_job=' . $job['id'];
}

function getDeptLabel(array $job): string {
    $type = strtolower(trim((string)($job['job_type'] ?? '')));
    $dept = strtolower(trim((string)($job['department'] ?? '')));
    if ($type === 'slitting') return 'Jumbo Slitting';
    if ($type === 'printing') return 'Flexo Printing';
    return ucwords(str_replace('_', ' ', $dept ?: $type));
}

function statusColor(string $sts): array {
    return match(strtolower(trim($sts))) {
        'pending'                => ['#fef3c7', '#92400e', '#f59e0b'],
        'running'                => ['#dbeafe', '#1e40af', '#3b82f6'],
        'completed', 'qc passed' => ['#dcfce7', '#166534', '#22c55e'],
        'queued'                 => ['#f1f5f9', '#475569', '#94a3b8'],
        default                  => (str_contains(strtolower($sts), 'hold')
            ? ['#fee2e2', '#991b1b', '#ef4444']
            : ['#f1f5f9', '#475569', '#64748b'])
    };
}

$pageTitle = 'Job Card — ' . ($job ? htmlspecialchars($job['job_no']) : 'Not Found');

// For the scan page we render a standalone page — no standard header/sidebar
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; min-height: 100vh; }

    /* ── Top bar ── */
    .sc-topbar {
      background: #0f172a;
      padding: 12px 20px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      position: sticky; top: 0; z-index: 100;
    }
    .sc-topbar-brand { display: flex; align-items: center; gap: 10px; }
    .sc-topbar-brand img { height: 32px; border-radius: 4px; }
    .sc-topbar-brand .sc-co { font-weight: 800; color: #fff; font-size: .88rem; }
    .sc-topbar-back { color: #94a3b8; font-size: .75rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 4px; }
    .sc-topbar-back:hover { color: #fff; }

    /* ── Layout ── */
    .sc-wrap { max-width: 680px; margin: 0 auto; padding: 20px 16px 40px; }

    /* ── Job badge hero ── */
    .sc-hero {
      background: #fff; border-radius: 16px; padding: 20px 24px 16px;
      box-shadow: 0 2px 12px rgba(0,0,0,.06);
      margin-bottom: 16px;
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    }
    .sc-hero-main { flex: 1; min-width: 0; }
    .sc-department { font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #94a3b8; margin-bottom: 4px; }
    .sc-jobno { font-size: 1.6rem; font-weight: 900; color: #0f172a; line-height: 1.1; }
    .sc-jobname { font-size: .88rem; color: #475569; font-weight: 600; margin-top: 4px; }
    .sc-status-row { display: flex; align-items: center; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    .sc-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 5px 14px; border-radius: 999px; font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
    }
    .sc-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
    .sc-hero-qr { flex-shrink: 0; text-align: center; }
    .sc-hero-qr canvas, .sc-hero-qr img { width: 90px; height: 90px; }
    .sc-hero-qr .sc-qr-label { font-size: .55rem; color: #94a3b8; font-weight: 600; margin-top: 3px; }

    /* ── Cards / Sections ── */
    .sc-card {
      background: #fff; border-radius: 14px; padding: 18px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,.05);
      margin-bottom: 12px;
    }
    .sc-card-title {
      font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #94a3b8;
      display: flex; align-items: center; gap: 6px; margin-bottom: 14px;
      border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;
    }
    .sc-card-title i { font-size: .85rem; }
    .sc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
    @media(max-width:400px){ .sc-grid { grid-template-columns: 1fr; } }
    .sc-field { display: flex; flex-direction: column; gap: 2px; }
    .sc-field .sf-label { font-size: .58rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: .04em; }
    .sc-field .sf-val { font-size: .82rem; font-weight: 700; color: #1e293b; }
    .sc-field .sf-val.brand { color: #8b5cf6; }
    .sc-field .sf-val.green { color: #16a34a; }
    .sc-field .sf-val.teal { color: #0ea5a4; }

    /* Colour lane grid */
    .sc-lane-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 4px; }
    .sc-lane-cell { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 6px; font-size: .7rem; font-weight: 700; text-align: center; min-height: 28px; display: flex; align-items: center; justify-content: center; }
    .sc-lane-num { font-size: .5rem; color: #94a3b8; display: block; }

    /* ── Action buttons ── */
    .sc-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
    .sc-btn {
      flex: 1; min-width: 140px; padding: 13px 16px; border: none; border-radius: 12px;
      font-size: .78rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
      text-decoration: none; transition: all .15s;
    }
    .sc-btn-primary { background: #8b5cf6; color: #fff; }
    .sc-btn-primary:hover { background: #7c3aed; }
    .sc-btn-secondary { background: #0ea5a4; color: #fff; }
    .sc-btn-secondary:hover { background: #0d9090; }
    .sc-btn-ghost { background: #f1f5f9; color: #475569; }
    .sc-btn-ghost:hover { background: #e2e8f0; }

    /* ── Timeline ── */
    .sc-timeline { display: flex; gap: 16px; flex-wrap: wrap; }
    .sc-tl-item { display: flex; flex-direction: column; gap: 2px; }
    .sc-tl-item .tl-label { font-size: .55rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; }
    .sc-tl-item .tl-val { font-size: .78rem; font-weight: 700; color: #1e293b; }

    /* ── Gate / lock info ── */
    .sc-gate { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 14px; font-size: .78rem; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }

    /* ── Error ── */
    .sc-error { text-align: center; padding: 60px 20px; }
    .sc-error i { font-size: 3rem; color: #ef4444; opacity: .5; display: block; margin-bottom: 12px; }
    .sc-error h2 { font-size: 1.1rem; font-weight: 800; color: #1e293b; margin-bottom: 6px; }
    .sc-error p { font-size: .85rem; color: #64748b; }

    /* ── Print ── */
    @media print {
      .sc-topbar, .sc-actions, .sc-hero-qr { display: none !important; }
      .sc-wrap { padding: 0; }
      *{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="sc-topbar">
  <div class="sc-topbar-brand">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
    <?php else: ?>
      <i class="bi bi-upc-scan" style="color:#8b5cf6;font-size:1.3rem"></i>
    <?php endif; ?>
    <span class="sc-co"><?= htmlspecialchars($companyName) ?></span>
  </div>
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="sc-topbar-back">
    <i class="bi bi-house"></i> Dashboard
  </a>
</div>

<div class="sc-wrap">

<?php if ($error): ?>
  <div class="sc-error">
    <i class="bi bi-qr-code-scan"></i>
    <h2>Job Card Not Found</h2>
    <p><?= $error ?></p>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="sc-btn sc-btn-ghost" style="display:inline-flex;margin-top:20px;max-width:200px;text-decoration:none">
      <i class="bi bi-house"></i> Go to Dashboard
    </a>
  </div>

<?php else:
  $sts = (string)($job['status'] ?? 'Pending');
  [$bgClr, $txtClr, $dotClr] = statusColor($sts);
  $jobName = trim((string)($job['planning_job_name'] ?? ''));
  if ($jobName === '') {
    $jobName = trim((string)($job['display_job_name'] ?? ''));
    if ($jobName === '') {
      $dept = ucwords(str_replace('_', ' ', $job['department'] ?? $job['job_type'] ?? ''));
      $jobName = $job['job_no'] . ' (' . $dept . ')';
    }
  }
  $deptLabel = getDeptLabel($job);
  $deptUrl   = getDeptPageUrl($job);
  $prevDone  = !$job['previous_job_id'] || !$job['prev_job_status'] || in_array($job['prev_job_status'], ['Completed','QC Passed']);
  $qrSelf    = BASE_URL . '/modules/scan/job.php?jn=' . urlencode($job['job_no']);
  $dur = $job['duration_minutes'] ?? null;
  $durStr = ($dur !== null) ? (floor($dur/60) . 'h ' . ($dur % 60) . 'm') : '—';
  $createdAt   = $job['created_at']   ? date('d M Y, H:i', strtotime($job['created_at']))   : '—';
  $startedAt   = $job['started_at']   ? date('d M Y, H:i', strtotime($job['started_at']))   : '—';
  $completedAt = $job['completed_at'] ? date('d M Y, H:i', strtotime($job['completed_at'])) : '—';
?>

  <!-- Quick actions -->
  <div class="sc-actions">
    <a href="<?= htmlspecialchars($deptUrl) ?>" class="sc-btn sc-btn-primary">
      <i class="bi bi-lightning-charge-fill"></i> Open in <?= htmlspecialchars($deptLabel) ?>
    </a>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="sc-btn sc-btn-ghost">
      <i class="bi bi-house"></i> Dashboard
    </a>
  </div>

  <?php if (!$prevDone): ?>
  <div class="sc-gate">
    <i class="bi bi-lock-fill"></i>
    Waiting for previous job: <?= htmlspecialchars($job['prev_job_no'] ?? '—') ?>
    (<?= htmlspecialchars($job['prev_job_status'] ?? '—') ?>)
  </div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="sc-hero">
    <div class="sc-hero-main">
      <div class="sc-department"><i class="bi bi-building"></i> <?= htmlspecialchars($deptLabel) ?></div>
      <div class="sc-jobno"><?= htmlspecialchars($job['job_no']) ?></div>
      <div class="sc-jobname"><?= htmlspecialchars($jobName) ?></div>
      <div class="sc-status-row">
        <span class="sc-badge" style="background:<?= $bgClr ?>;color:<?= $txtClr ?>">
          <span class="sc-dot" style="background:<?= $dotClr ?>"></span>
          <?= htmlspecialchars($sts) ?>
        </span>
        <?php if (trim((string)($job['planning_priority'] ?? 'Normal')) !== 'Normal'): ?>
          <span class="sc-badge" style="background:#fee2e2;color:#991b1b">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= htmlspecialchars($job['planning_priority']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="sc-hero-qr">
      <div id="sc-qr-box"></div>
      <div class="sc-qr-label">Scan to share</div>
    </div>
  </div>

  <!-- Material / Roll -->
  <div class="sc-card">
    <div class="sc-card-title"><i class="bi bi-box-seam"></i> Material & Roll</div>
    <div class="sc-grid">
      <div class="sc-field"><span class="sf-label">Roll No</span><span class="sf-val teal"><?= htmlspecialchars($job['roll_no'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Paper Type</span><span class="sf-val"><?= htmlspecialchars($job['paper_type'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Supplier</span><span class="sf-val"><?= htmlspecialchars($job['supplier'] ?? $job['company'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">GSM</span><span class="sf-val"><?= htmlspecialchars($job['gsm'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Width × Length</span><span class="sf-val"><?= htmlspecialchars(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <div class="sc-field"><span class="sf-label">Weight</span><span class="sf-val"><?= $job['weight_kg'] ? htmlspecialchars($job['weight_kg']) . ' kg' : '—' ?></span></div>
    </div>
  </div>

  <?php if ($job['job_type'] === 'Printing' || strtolower($job['department'] ?? '') === 'flexo_printing'): ?>
  <!-- Flexo Job Card Fields -->
  <div class="sc-card">
    <div class="sc-card-title"><i class="bi bi-printer-fill"></i> Flexo Job Card Fields</div>
    <div class="sc-grid">
      <div class="sc-field"><span class="sf-label">MKD Job SL NO</span><span class="sf-val brand"><?= htmlspecialchars($extra['mkd_job_sl_no'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Date</span><span class="sf-val"><?= htmlspecialchars($extra['job_date'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Die</span><span class="sf-val"><?= htmlspecialchars($extra['die'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Plate No</span><span class="sf-val"><?= htmlspecialchars($extra['plate_no'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Label Size</span><span class="sf-val"><?= htmlspecialchars($extra['label_size'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Repeat / Direction</span><span class="sf-val"><?= htmlspecialchars(($extra['repeat_mm'] ?? '—') . ' / ' . ($extra['direction'] ?? '—')) ?></span></div>
      <div class="sc-field"><span class="sf-label">Reel C1 / C2</span><span class="sf-val"><?= htmlspecialchars(($extra['reel_no_c1'] ?? '—') . ' / ' . ($extra['reel_no_c2'] ?? '—')) ?></span></div>
      <div class="sc-field"><span class="sf-label">Order QTY / MTR</span><span class="sf-val"><?= htmlspecialchars(($extra['order_qty'] ?? '—') . ' / ' . ($extra['order_mtr'] ?? '—')) ?></span></div>
      <div class="sc-field"><span class="sf-label">Actual QTY</span><span class="sf-val green"><?= htmlspecialchars($extra['actual_qty'] ?? '—') ?></span></div>
      <div class="sc-field"><span class="sf-label">Prepared By / Filled By</span><span class="sf-val"><?= htmlspecialchars(($extra['prepared_by'] ?? '—') . ' / ' . ($extra['filled_by'] ?? '—')) ?></span></div>
    </div>
    <?php
      $colourLanes = $extra['colour_lanes'] ?? [];
      $aniloxLanes = $extra['anilox_lanes'] ?? [];
      $hasColours  = is_array($colourLanes) && count(array_filter($colourLanes)) > 0;
      $hasAnilox   = is_array($aniloxLanes) && count(array_filter($aniloxLanes)) > 0;
    ?>
    <?php if ($hasColours): ?>
    <div style="margin-top:12px">
      <div class="sf-label" style="font-size:.58rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:6px">Colour Lanes (1–8)</div>
      <div class="sc-lane-grid">
        <?php for ($li = 0; $li < 8; $li++): ?>
          <div class="sc-lane-cell"><span class="sc-lane-num"><?= $li+1 ?></span><?= htmlspecialchars($colourLanes[$li] ?? '') ?></div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($hasAnilox): ?>
    <div style="margin-top:8px">
      <div class="sf-label" style="font-size:.58rem;font-weight:800;text-transform:uppercase;color:#94a3b8;margin-bottom:6px">Anilox Lanes (1–8)</div>
      <div class="sc-lane-grid">
        <?php for ($li = 0; $li < 8; $li++): ?>
          <div class="sc-lane-cell"><span class="sc-lane-num"><?= $li+1 ?></span><?= htmlspecialchars($aniloxLanes[$li] ?? '') ?></div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Planning -->
  <?php if ($job['planning_job_name'] || $job['machine'] || $job['operator_name'] || $job['scheduled_date']): ?>
  <div class="sc-card">
    <div class="sc-card-title"><i class="bi bi-clipboard2-check"></i> Planning</div>
    <div class="sc-grid">
      <?php if ($job['planning_job_name']): ?>
        <div class="sc-field" style="grid-column:1/-1"><span class="sf-label">Job Name</span><span class="sf-val"><?= htmlspecialchars($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
      <?php if ($job['machine']): ?>
        <div class="sc-field"><span class="sf-label">Machine</span><span class="sf-val"><?= htmlspecialchars($job['machine']) ?></span></div>
      <?php endif; ?>
      <?php if ($job['operator_name']): ?>
        <div class="sc-field"><span class="sf-label">Operator</span><span class="sf-val"><?= htmlspecialchars($job['operator_name']) ?></span></div>
      <?php endif; ?>
      <?php if ($job['scheduled_date']): ?>
        <div class="sc-field"><span class="sf-label">Scheduled</span><span class="sf-val"><?= date('d M Y', strtotime($job['scheduled_date'])) ?></span></div>
      <?php endif; ?>
      <div class="sc-field"><span class="sf-label">Priority</span><span class="sf-val"><?= htmlspecialchars($job['planning_priority'] ?? 'Normal') ?></span></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Timeline -->
  <div class="sc-card">
    <div class="sc-card-title"><i class="bi bi-clock-history"></i> Timeline</div>
    <div class="sc-timeline">
      <div class="sc-tl-item"><span class="tl-label">Created</span><span class="tl-val"><?= $createdAt ?></span></div>
      <div class="sc-tl-item"><span class="tl-label">Started</span><span class="tl-val" style="color:#8b5cf6"><?= $startedAt ?></span></div>
      <div class="sc-tl-item"><span class="tl-label">Completed</span><span class="tl-val" style="color:#16a34a"><?= $completedAt ?></span></div>
      <?php if ($dur !== null): ?>
        <div class="sc-tl-item"><span class="tl-label">Duration</span><span class="tl-val" style="color:#16a34a"><?= $durStr ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Notes -->
  <?php if ($job['notes']): ?>
  <div class="sc-card">
    <div class="sc-card-title"><i class="bi bi-sticky"></i> Notes</div>
    <div style="font-size:.82rem;color:#475569;line-height:1.6"><?= nl2br(htmlspecialchars($job['notes'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Bottom action row -->
  <div class="sc-actions" style="margin-top:4px">
    <a href="<?= htmlspecialchars($deptUrl) ?>" class="sc-btn sc-btn-secondary">
      <i class="bi bi-box-arrow-up-right"></i> Open Full View
    </a>
    <button onclick="window.print()" class="sc-btn sc-btn-ghost">
      <i class="bi bi-printer"></i> Print
    </button>
  </div>

  <!-- Scan timestamp -->
  <div style="text-align:center;font-size:.65rem;color:#94a3b8;margin-top:8px">
    Scanned: <?= date('d M Y, H:i:s') ?> &nbsp;|&nbsp; <?= htmlspecialchars($companyName) ?>
  </div>

<?php endif; ?>
</div><!-- /sc-wrap -->

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
(function() {
  var box = document.getElementById('sc-qr-box');
  if (!box) return;
  var qrUrl = <?= json_encode($qrSelf ?? BASE_URL . '/modules/scan/job.php', JSON_HEX_TAG|JSON_HEX_APOS) ?>;
  try {
    new QRCode(box, {
      text: qrUrl,
      width: 90,
      height: 90,
      colorDark: '#0f172a',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  } catch(e) { /* graceful — QR is decorative here */ }
})();
</script>
</body>
</html>
