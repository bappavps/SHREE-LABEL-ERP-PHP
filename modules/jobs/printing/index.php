<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isOperatorView = (string)($_GET['view'] ?? '') === 'operator';
$pageTitle = $isOperatorView ? 'Flexo Operator' : 'Flexo Printing Jobs';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$sessionUser = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));

// Fetch Printing job cards with roll, planning and previous slitting job details
$jobs = $db->query("
    SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           ps.status AS roll_status, ps.lot_batch_no,
           p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
           p.extra_data AS planning_extra_data,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE j.job_type = 'Printing'
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
    ORDER BY j.created_at DESC
    LIMIT 200
")->fetch_all(MYSQLI_ASSOC);

// Parse extra_data for each job
foreach ($jobs as &$j) {
    $j['extra_data_parsed'] = json_decode($j['extra_data'] ?? '{}', true) ?: [];
    // Parse planning extra_data into planning_* fields
    $ped = json_decode($j['planning_extra_data'] ?? '{}', true) ?: [];
    $j['planning_die'] = $ped['die'] ?? '';
    $j['planning_plate_no'] = $ped['plate_no'] ?? '';
    $j['planning_label_size'] = $ped['size'] ?? '';
    $j['planning_repeat_mm'] = $ped['repeat'] ?? '';
    $j['planning_direction'] = $ped['roll_direction'] ?? '';
    $j['planning_order_mtr'] = $ped['allocate_mtrs'] ?? '';
    $j['planning_order_qty'] = $ped['qty_pcs'] ?? '';
    $j['planning_job_date'] = $ped['order_date'] ?? '';
    $j['planning_mkd_job_sl_no'] = $ped['mkd_job_sl_no'] ?? '';
    $j['planning_core_size'] = $ped['core_size'] ?? '';
    $j['planning_qty_per_roll'] = $ped['qty_per_roll'] ?? '';
    $j['planning_material'] = $ped['material'] ?? '';
    $j['planning_paper_size'] = $ped['paper_size'] ?? '';
    $j['planning_remarks'] = $ped['remarks'] ?? '';
    $j['planning_image_url'] = !empty($ped['image_path']) ? (BASE_URL . '/' . $ped['image_path']) : '';
    unset($j['planning_extra_data']); // Don't send raw blob to JS
    $planningName = trim((string)($j['planning_job_name'] ?? ''));
    if ($planningName !== '') {
      $j['display_job_name'] = $planningName;
    } else {
      $deptRaw = trim((string)($j['department'] ?? 'flexo_printing'));
      $dept = ucwords(str_replace('_', ' ', $deptRaw));
      $jobNo = trim((string)($j['job_no'] ?? ''));
      $j['display_job_name'] = $jobNo !== '' ? ($jobNo . ' (' . $dept . ')') : ($dept !== '' ? $dept : '—');
    }

    $rawNotes = trim((string)($j['notes'] ?? ''));
    $j['notes_display'] = $rawNotes;
    if ($rawNotes !== '' && stripos($rawNotes, 'Flexo printing queued from Jumbo | Plan:') === 0) {
      $planRef = 'N/A';
      if (preg_match('/\|\s*Plan:\s*([^|\n]+)/i', $rawNotes, $m)) {
        $planRef = trim((string)($m[1] ?? '')) ?: 'N/A';
      }
      $jumboRef = trim((string)($j['prev_job_no'] ?? ''));
      $flexoRef = trim((string)($j['job_no'] ?? ''));
      $displayName = trim((string)($j['display_job_name'] ?? ''));
      $normalized = 'Flexo printing queued from Jumbo | Plan: ' . $planRef . ' | Jumbo: ' . ($jumboRef !== '' ? $jumboRef : 'N/A') . ' I Flexo: ' . ($flexoRef !== '' ? $flexoRef : 'N/A');
      if ($displayName !== '') {
        $normalized .= ' | Job name : ' . $displayName;
      }
      $j['notes_display'] = $normalized;
    }
}
unset($j);

// Notification count
$notifCount = 0;
$nRes = $db->query("SELECT COUNT(*) as cnt FROM job_notifications WHERE (department = 'flexo_printing' OR department IS NULL) AND is_read = 0");
if ($nRes) $notifCount = (int)$nRes->fetch_assoc()['cnt'];

// Split active vs history
$activeJobs  = array_values(array_filter($jobs, fn($j) => !in_array($j['status'], ['Completed','QC Passed','Closed','Finalized'])));
$historyJobs = array_values(array_filter($jobs, fn($j) => in_array($j['status'], ['Completed','QC Passed','Closed','Finalized'])));
$activeCount  = count($activeJobs);
$historyCount = count($historyJobs);

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <?php if ($isOperatorView): ?>
    <span>Operator</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Machine Operators</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Flexo Operator</span>
  <?php else: ?>
    <span>Production</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Job Cards</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Flexo Printing</span>
  <?php endif; ?>
</div>

<style>
:root{--fp-brand:#8b5cf6;--fp-brand-dim:rgba(139,92,246,.08);--fp-orange:#f97316;--fp-blue:#3b82f6;--fp-green:#22c55e;--fp-red:#ef4444}
.fp-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.fp-header h1{font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px}
.fp-header h1 i{font-size:1.6rem;color:var(--fp-brand)}
.fp-header-meta{font-size:.75rem;color:#64748b;font-weight:600}
.fp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.fp-stat{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:box-shadow .15s}
.fp-stat:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
.fp-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.fp-stat-val{font-size:1.5rem;font-weight:900;line-height:1}
.fp-stat-label{font-size:.65rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em;margin-top:2px}
.fp-filters{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.fp-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:240px;outline:none;transition:border .15s}
.fp-search:focus{border-color:var(--fp-brand)}
.fp-filter-btn{padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.fp-filter-btn.active{background:var(--fp-brand);border-color:var(--fp-brand);color:#fff}
.fp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px}
.fp-card{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden;transition:all .2s;border-left:4px solid var(--fp-brand);cursor:pointer}
.fp-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.fp-card.fp-queued{opacity:.7;border-left-color:#94a3b8}
.fp-card-head{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border,#e2e8f0);background:linear-gradient(135deg,#faf5ff,#fff)}
.fp-card-head .fp-jobno{font-weight:900;font-size:.85rem;color:#0f172a;display:flex;align-items:center;gap:8px}
.fp-card-head .fp-jobno i{color:var(--fp-brand);font-size:1rem}
.fp-card-body{padding:14px 18px}
.fp-card-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.78rem}
.fp-card-row .fp-label{color:#94a3b8;font-weight:700;font-size:.65rem;text-transform:uppercase;letter-spacing:.03em}
.fp-card-row .fp-value{font-weight:700;color:#1e293b}
.fp-card-foot{padding:12px 18px;border-top:1px solid var(--border,#e2e8f0);display:flex;align-items:center;justify-content:space-between;background:#fafbfc}
.fp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.fp-badge-queued{background:#f1f5f9;color:#64748b}
.fp-badge-pending{background:#fef3c7;color:#92400e}
.fp-badge-running{background:#dbeafe;color:#1e40af;animation:pulse-fp 2s infinite}
.fp-badge-completed{background:#dcfce7;color:#166534}
.fp-badge-slitting{background:#ede9fe;color:#6d28d9}
.fp-badge-urgent{background:#fee2e2;color:#991b1b}
.fp-badge-high{background:#ffedd5;color:#9a3412}
.fp-badge-normal{background:#e0f2fe;color:#075985}
@keyframes pulse-fp{0%,100%{opacity:1}50%{opacity:.6}}
.fp-action-btn{padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;border-radius:8px;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.fp-btn-start{background:var(--fp-brand);color:#fff}
.fp-btn-start:hover{background:#7c3aed}
.fp-btn-start:disabled{opacity:.4;cursor:not-allowed}
.fp-btn-complete{background:var(--fp-green);color:#fff}
.fp-btn-complete:hover{background:#16a34a}
.fp-btn-view{background:#f1f5f9;color:#475569;border:1px solid var(--border,#e2e8f0)}
.fp-btn-view:hover{background:#e2e8f0}
.fp-btn-print{background:var(--fp-brand);color:#fff}
.fp-btn-print:hover{background:#7c3aed}
.fp-btn-delete{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
.fp-btn-delete:hover{background:#fecaca}
.fp-time{font-size:.6rem;color:#94a3b8;font-weight:600}
.fp-empty{text-align:center;padding:60px 20px;color:#94a3b8}
.fp-empty i{font-size:3rem;opacity:.3}
.fp-empty p{margin-top:12px;font-size:.9rem;font-weight:600}
.fp-timer{font-size:.75rem;font-weight:800;color:var(--fp-brand);font-family:'Courier New',monospace}
.fp-notif-badge{background:#ef4444;color:#fff;font-size:9px;font-weight:800;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-left:6px}
.fp-gate-info{font-size:.6rem;color:#f59e0b;font-weight:700;display:flex;align-items:center;gap:4px;background:#fef3c7;padding:4px 8px;border-radius:6px}

/* ── Detail Modal ── */
.fp-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px}
.fp-modal-overlay.active{display:flex}
.fp-modal{background:#fff;border-radius:16px;max-width:720px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.2)}
.fp-modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#faf5ff,#fff);border-radius:16px 16px 0 0}
.fp-modal-header h2{font-size:1.1rem;font-weight:900;display:flex;align-items:center;gap:10px}
.fp-modal-header h2 i{color:var(--fp-brand)}
.fp-modal-body{padding:24px}
.fp-detail-section{margin-bottom:20px}
.fp-detail-section h3{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.fp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
.fp-detail-item{display:flex;flex-direction:column;gap:2px}
.fp-detail-item .dl{font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.fp-detail-item .dv{font-size:.82rem;font-weight:700;color:#1e293b}
.fp-timeline{display:flex;gap:20px;flex-wrap:wrap}
.fp-timeline-item{display:flex;flex-direction:column;gap:2px}
.fp-timeline-item .tl-label{font-size:.55rem;font-weight:800;text-transform:uppercase;color:#94a3b8}
.fp-timeline-item .tl-val{font-size:.75rem;font-weight:700;color:#1e293b}
.fp-timeline-item .tl-val.green{color:#16a34a}
.fp-timeline-item .tl-val.purple{color:#8b5cf6}
.fp-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.fp-form-group{display:flex;flex-direction:column;gap:4px}
.fp-form-group label{font-size:.6rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
.fp-form-group input,.fp-form-group select,.fp-form-group textarea{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;font-weight:600;font-family:inherit}
.fp-form-group textarea{min-height:60px;resize:vertical}
.fp-form-group input:focus,.fp-form-group select:focus,.fp-form-group textarea:focus{outline:none;border-color:var(--fp-brand);box-shadow:0 0 0 2px rgba(139,92,246,.1)}
.fp-modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}

.fp-op-shell{border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;overflow:hidden}
.fp-op-form{padding:12px;display:grid;gap:12px}
.fp-op-section{border:1px solid #dbe5f0;border-radius:10px;background:#fff;overflow:hidden;display:flex;flex-direction:column}
.fp-op-h{padding:9px 11px;border-bottom:1px solid #c4b5fd;background:#ede9fe;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#5b21b6}
.fp-op-b{padding:10px;display:grid;gap:8px}
.fp-op-b + .fp-op-b{border-top:1px solid #ede9fe;margin-top:0}
.fp-op-grid-2,.fp-op-grid-3,.fp-op-grid-4{display:grid;gap:8px}
.fp-op-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}
.fp-op-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.fp-op-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
.fp-op-field{display:grid;gap:5px}
.fp-op-field label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.03em}
.fp-op-field input,.fp-op-field select,.fp-op-field textarea{padding:8px 10px;border:1px solid #dbe5f0;border-radius:8px;font-size:.8rem;font-weight:600;font-family:inherit;background:#fcfdff}
.fp-op-field textarea{min-height:60px;resize:vertical}
.fp-op-field input:focus,.fp-op-field select:focus,.fp-op-field textarea:focus{outline:none;border-color:var(--fp-brand);box-shadow:0 0 0 2px rgba(139,92,246,.1);background:#fff}
.fp-op-lanes{display:grid;grid-template-columns:repeat(8,minmax(64px,1fr));gap:8px}
.fp-op-lane{display:grid;gap:5px}
.fp-op-lane label{font-size:.58rem;font-weight:800;color:#64748b;text-align:center}
.fp-op-lane input{text-align:center;padding:7px 6px;border:1px solid #dbe5f0;border-radius:8px;font-size:.76rem;font-weight:700}
.fp-op-note{font-size:.66rem;color:#64748b;margin:0}
.fp-op-topstrip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}
.fp-op-topitem{display:grid;gap:3px}
.fp-op-topitem .k{font-size:.58rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.fp-op-topitem .v{font-size:.76rem;font-weight:800;color:#0f172a}
.fp-op-roll-table{width:100%;border-collapse:collapse;font-size:.75rem}
.fp-op-roll-table th,.fp-op-roll-table td{border:1px solid #dbe5f0;padding:7px 8px;text-align:left;vertical-align:middle}
.fp-op-roll-table th{background:#f8fafc;font-size:.62rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.fp-op-mini{font-size:.68rem;color:#64748b}
.fp-op-lane-row{display:grid;grid-template-columns:70px 1.2fr 1fr;gap:8px;align-items:center}
.fp-photo-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fp-photo-card{border:1px solid #dbe5f0;border-radius:10px;padding:10px;background:#fff}
.fp-photo-preview{width:100%;height:160px;object-fit:cover;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc}
.fp-voice-btn{padding:6px 10px;font-size:.62rem;font-weight:800;text-transform:uppercase;border:1px solid #dbe5f0;border-radius:8px;background:#fff;cursor:pointer;color:#334155}
.fp-voice-btn.active{background:#fee2e2;border-color:#fca5a5;color:#b91c1c}

.fp-tabs{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.fp-tab-btn{padding:7px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:999px;cursor:pointer;color:#64748b;transition:all .15s}
.fp-tab-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.fp-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.62rem;margin-left:6px}
.fp-tab-btn.active .fp-tab-count{background:rgba(255,255,255,.2);color:#fff}
.fp-card-check{width:16px;height:16px;cursor:pointer;accent-color:var(--fp-brand);flex-shrink:0;margin-right:2px}
@media print{.no-print,.breadcrumb,.page-header,.fp-modal-overlay{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:900px){.fp-op-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.fp-op-lanes{grid-template-columns:repeat(4,minmax(72px,1fr))}.fp-op-topstrip{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.fp-grid{grid-template-columns:1fr}.fp-stats{grid-template-columns:repeat(2,1fr)}.fp-detail-grid{grid-template-columns:1fr}.fp-form-row{grid-template-columns:1fr}.fp-op-grid-2,.fp-op-grid-3,.fp-op-grid-4{grid-template-columns:1fr}.fp-op-lanes{grid-template-columns:repeat(2,minmax(90px,1fr))}}

/* Timer overlay */
.fp-timer-overlay{position:fixed;inset:0;z-index:20000;background:rgba(15,23,42,.85);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;backdrop-filter:blur(4px)}
.fp-timer-display{font-size:4rem;font-weight:900;font-variant-numeric:tabular-nums;color:#fff;letter-spacing:.04em;text-shadow:0 2px 12px rgba(0,0,0,.3)}
.fp-timer-jobinfo{color:rgba(255,255,255,.7);font-size:1rem;text-align:center;font-weight:600}
.fp-timer-actions{display:flex;gap:16px}
.fp-timer-actions button{padding:12px 32px;font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;border:none;border-radius:999px;cursor:pointer;transition:all .15s}
.fp-timer-btn-cancel{background:#64748b;color:#fff}
.fp-timer-btn-cancel:hover{background:#475569}
.fp-timer-btn-end{background:#16a34a;color:#fff}
.fp-timer-btn-end:hover{background:#15803d}
</style>

<div class="fp-header no-print">
  <div>
    <h1><i class="bi bi-printer"></i> <?= $isOperatorView ? 'Flexo Operator' : 'Flexo Printing Jobs' ?>
      <?php if ($notifCount > 0): ?><span class="fp-notif-badge"><?= $notifCount ?></span><?php endif; ?>
    </h1>
    <div class="fp-header-meta">
      <?= $isOperatorView
        ? 'Operator execution board for Flexo printing job cards.'
        : 'Auto-generated for printing after slitting &middot; Sequential gating from Jumbo Slitting' ?>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="fp-action-btn fp-btn-view" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<?php
$totalJobs = count($jobs);
$pendingJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Pending'));
$runningJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Running'));
$completedJobs = count(array_filter($jobs, fn($j) => in_array($j['status'], ['Completed','QC Passed'])));
$queuedJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Queued'));
?>
<div class="fp-stats no-print">
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('all')">
    <div class="fp-stat-icon" style="background:#faf5ff;color:var(--fp-brand)"><i class="bi bi-printer"></i></div>
    <div><div class="fp-stat-val"><?= $totalJobs ?></div><div class="fp-stat-label">Total Print Jobs</div></div>
  </div>
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('Queued')">
    <div class="fp-stat-icon" style="background:#f1f5f9;color:#64748b"><i class="bi bi-lock"></i></div>
    <div><div class="fp-stat-val"><?= $queuedJobs ?></div><div class="fp-stat-label">Queued</div></div>
  </div>
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('Pending')">
    <div class="fp-stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="bi bi-hourglass-split"></i></div>
    <div><div class="fp-stat-val"><?= $pendingJobs ?></div><div class="fp-stat-label">Pending</div></div>
  </div>
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('Running')">
    <div class="fp-stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="bi bi-play-circle"></i></div>
    <div><div class="fp-stat-val"><?= $runningJobs ?></div><div class="fp-stat-label">Running</div></div>
  </div>
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('Completed')">
    <div class="fp-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="bi bi-check-circle"></i></div>
    <div><div class="fp-stat-val"><?= $completedJobs ?></div><div class="fp-stat-label">Completed</div></div>
  </div>
</div>

<div class="fp-tabs no-print">
  <button id="fpTabBtnActive" class="fp-tab-btn active" type="button" onclick="switchFPTab('active')">Job Cards <span class="fp-tab-count"><?= $activeCount ?></span></button>
  <button id="fpTabBtnHistory" class="fp-tab-btn" type="button" onclick="switchFPTab('history')">History <span class="fp-tab-count"><?= $historyCount ?></span></button>
</div>

<div id="fpPanelActive">
<div class="fp-filters no-print">
  <input type="text" class="fp-search" id="fpSearch" placeholder="Search by job no, roll, company&hellip;">
  <button class="fp-filter-btn" onclick="filterFP('all',this)">All</button>
  <button class="fp-filter-btn" onclick="filterFP('Queued',this)">Queued</button>
  <button class="fp-filter-btn active" onclick="filterFP('Pending',this)">Pending</button>
  <button class="fp-filter-btn" onclick="filterFP('Running',this)">Running</button>
  <button class="fp-filter-btn" onclick="filterFP('Completed',this)">Completed</button>
  <button id="fpPrintSelBtn" onclick="printSelectedJobs()" style="display:none;padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--fp-brand);color:#fff;border-radius:20px;cursor:pointer;align-items:center;gap:6px;letter-spacing:.04em"><i class="bi bi-printer-fill"></i> Print Selected (<span id="fpSelCount">0</span>)</button>
</div>

<div class="fp-grid no-print" id="fpGrid">
<?php if (empty($jobs)): ?>
  <div class="fp-empty" style="grid-column:1/-1">
    <i class="bi bi-inbox"></i>
    <p>No printing job cards yet. They are auto-created when slitting operations execute.</p>
  </div>
<?php else: ?>
  <?php foreach ($jobs as $idx => $job):
    $sts = $job['status'];
    $stsClass = match($sts) { 'Queued'=>'queued', 'Pending'=>'pending', 'Running'=>'running', 'Completed','QC Passed'=>'completed', default=>'pending' };
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0;
    $dur = $job['duration_minutes'] ?? null;
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
    // Sequencing gate: can only start if previous slitting job is finished
    $prevDone = true;
    if ($job['previous_job_id'] && $job['prev_job_status'] && !in_array($job['prev_job_status'], ['Completed','QC Passed','Closed','Finalized'])) {
        $prevDone = false;
    }
    $isQueued = ($sts === 'Queued');
  ?>
  <div class="fp-card <?= $isQueued ? 'fp-queued' : '' ?>" data-status="<?= e($sts) ?>" data-lockstate="<?= $prevDone ? 'unlocked' : 'locked' ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openPrintDetail(<?= $job['id'] ?>)">
    <div class="fp-card-head">
      <div class="fp-jobno"><i class="bi bi-printer-fill"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="fp-badge fp-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($pri !== 'Normal'): ?>
          <span class="fp-badge fp-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="fp-card-body">
      <?php if ($job['planning_job_name']): ?>
      <div class="fp-card-row"><span class="fp-label">Job Name</span><span class="fp-value"><?= e($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
      <div class="fp-card-row"><span class="fp-label">Roll No</span><span class="fp-value" style="color:var(--fp-brand)"><?= e($job['roll_no'] ?? '—') ?></span></div>
      <div class="fp-card-row"><span class="fp-label">Material</span><span class="fp-value"><?= e($job['paper_type'] ?? '—') ?></span></div>
      <div class="fp-card-row"><span class="fp-label">Dimension</span><span class="fp-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <?php if ($isQueued || !$prevDone): ?>
      <div class="fp-card-row">
        <span class="fp-gate-info"><i class="bi bi-lock-fill"></i> Waiting for slitting: <?= e($job['prev_job_no'] ?? '—') ?> (<?= e($job['prev_job_status'] ?? '—') ?>)</span>
      </div>
      <?php endif; ?>
      <?php if ($sts === 'Running' && $startedTs): ?>
      <div class="fp-card-row"><span class="fp-label">Elapsed</span><span class="fp-timer" data-started="<?= $startedTs ?>">00:00:00</span></div>
      <?php elseif ($dur !== null): ?>
      <div class="fp-card-row"><span class="fp-label">Duration</span><span class="fp-value"><?= floor($dur/60) ?>h <?= $dur%60 ?>m</span></div>
      <?php endif; ?>
    </div>
    <div class="fp-card-foot">
      <div class="fp-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <input type="checkbox" class="fp-card-check" data-id="<?= $job['id'] ?>" onclick="event.stopPropagation();updatePrintCount()" title="Select for bulk print">
        <?php if ($sts === 'Pending' && $prevDone): ?>
          <?php if ($isOperatorView): ?>
            <button class="fp-action-btn fp-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
          <?php else: ?>
            <button class="fp-action-btn fp-btn-view" onclick="openPrintDetail(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-eye"></i> Open</button>
          <?php endif; ?>
        <?php elseif ($sts === 'Pending' && !$prevDone): ?>
          <button class="fp-action-btn fp-btn-start" disabled title="Slitting job must complete first"><i class="bi bi-lock-fill"></i> Locked</button>
        <?php elseif ($sts === 'Running'): ?>
          <?php if ($isOperatorView): ?>
            <button class="fp-action-btn fp-btn-complete" onclick="openPrintDetail(<?= $job['id'] ?>,'complete')"><i class="bi bi-check-lg"></i> Complete</button>
          <?php else: ?>
            <button class="fp-action-btn fp-btn-view" onclick="openPrintDetail(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-eye"></i> Open</button>
          <?php endif; ?>
        <?php endif; ?>
        <button class="fp-action-btn fp-btn-view" onclick="printJobCard(<?= $job['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div><!-- end fpPanelActive -->

<div id="fpPanelHistory" style="display:none">
<div class="card no-print" style="margin-top:8px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <span class="card-title"><i class="bi bi-clock-history"></i> Flexo Printing History (Completed / QC Passed)</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="text" id="fpHistorySearch" placeholder="Search history..." oninput="filterHistory(this.value)" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;outline:none">
      <span style="font-size:.72rem;color:#64748b;font-weight:700"><?= $historyCount ?> records</span>
      <button onclick="window.print()" style="padding:5px 12px;font-size:.65rem;font-weight:800;text-transform:uppercase;border:none;background:var(--fp-brand);color:#fff;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:5px"><i class="bi bi-printer"></i> Print</button>
    </div>
  </div>
  <div style="overflow:auto">
    <table id="fpHistoryTable" style="width:100%;border-collapse:collapse;font-size:.78rem">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Job No</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Job Name</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Roll No</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Material</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Status</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Started</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Completed</th>
          <th style="padding:10px 12px;text-align:left;border-bottom:2px solid #e2e8f0">Duration</th>
        </tr>
      </thead>
      <tbody id="fpHistoryBody">
        <?php if (empty($historyJobs)): ?>
          <tr><td colspan="8" style="padding:20px;text-align:center;color:#94a3b8">No completed jobs yet.</td></tr>
        <?php else: ?>
          <?php foreach ($historyJobs as $h):
            $hDur = $h['duration_minutes'] ?? null;
            $hDurStr = ($hDur !== null) ? (floor($hDur/60).'h '.($hDur%60).'m') : '—';
            $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
            $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : '—';
            $hSearch = e(strtolower(($h['job_no']??'').' '.($h['planning_job_name']??'').' '.($h['display_job_name']??'').' '.($h['roll_no']??'')));
          ?>
          <tr data-search="<?= $hSearch ?>" style="cursor:pointer" onclick="openPrintDetail(<?= $h['id'] ?>)" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;font-weight:700;color:var(--fp-brand)"><?= e($h['job_no']) ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['planning_job_name'] ?? $h['display_job_name'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['roll_no'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= e($h['paper_type'] ?? '—') ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><span class="fp-badge fp-badge-completed"><?= e($h['status']) ?></span></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hStarted ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hCompleted ?></td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9"><?= $hDurStr ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- end fpPanelHistory -->

<!-- ═══ DETAIL MODAL ═══ -->
<div class="fp-modal-overlay" id="fpDetailModal">
  <div class="fp-modal">
    <div class="fp-modal-header">
      <h2><i class="bi bi-printer-fill"></i> <span id="dm-jobno"></span></h2>
      <div style="display:flex;gap:8px;align-items:center">
        <span id="dm-status-badge" class="fp-badge"></span>
        <button class="fp-action-btn fp-btn-view" onclick="closeDetail()"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
    <div class="fp-modal-body" id="dm-body"></div>
    <div class="fp-modal-footer" id="dm-footer"></div>
  </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const BASE_URL = '<?= BASE_URL ?>';
const IS_OPERATOR_VIEW = <?= $isOperatorView ? 'true' : 'false' ?>;
const CURRENT_USER = <?= json_encode($sessionUser, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode($jobs, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
let activeStatusFilter = 'Pending';

function getFieldVal(form, name) {
  return String(form.querySelector('[name="' + name + '"]')?.value || '').trim();
}

function getNumberFieldVal(form, name) {
  const raw = getFieldVal(form, name);
  if (raw === '') return '';
  const n = Number(raw);
  return Number.isFinite(n) ? n : raw;
}

function normalizeCardData(job, extra) {
  const out = Object.assign({}, extra || {});
  out.mkd_job_sl_no = String(out.mkd_job_sl_no || job.planning_mkd_job_sl_no || '').trim();
  out.job_date = String(out.job_date || job.planning_job_date || '').trim();
  out.job_name = String(out.job_name || resolvePrintDisplayName(job)).trim();
  out.die = String(out.die || job.planning_die || '').trim();
  out.plate_no = String(out.plate_no || job.planning_plate_no || '').trim();
  out.material_company = String(out.material_company || job.company || '').trim();
  out.material_name = String(out.material_name || job.paper_type || '').trim();
  out.order_mtr = out.order_mtr ?? (job.planning_order_mtr ?? '');
  out.order_qty = out.order_qty ?? (job.planning_order_qty ?? '');
  out.reel_no_c1 = String(out.reel_no_c1 || job.planning_reel_no_c1 || '').trim();
  out.reel_no_c2 = String(out.reel_no_c2 || job.planning_reel_no_c2 || '').trim();
  out.width_c1 = out.width_c1 ?? (job.planning_width_c1 ?? (job.width_mm || ''));
  out.width_c2 = out.width_c2 ?? (job.planning_width_c2 ?? (job.width_mm || ''));
  out.length_c1 = out.length_c1 ?? (job.planning_length_c1 ?? (job.length_mtr || ''));
  out.length_c2 = out.length_c2 ?? (job.planning_length_c2 ?? (job.length_mtr || ''));
  out.label_size = String(out.label_size || job.planning_label_size || '').trim();
  out.repeat_mm = String(out.repeat_mm || job.planning_repeat_mm || '').trim();
  out.direction = String(out.direction || job.planning_direction || '').trim();
  out.actual_qty = out.actual_qty ?? '';
  out.electricity = String(out.electricity || '').trim();
  out.time_spent = String(out.time_spent || '').trim();
  out.prepared_by = String(out.prepared_by || CURRENT_USER || '').trim();
  out.filled_by = String(out.filled_by || '').trim();
  out.defects_text = String(out.defects_text || (Array.isArray(out.defects) ? out.defects.join(', ') : '') || '').trim();
  out.physical_print_photo_url = String(out.physical_print_photo_url || '').trim();
  out.physical_print_photo_path = String(out.physical_print_photo_path || '').trim();
  out.total_wastage_meters = out.total_wastage_meters ?? out.wastage_meters ?? '';
  if (!Array.isArray(out.colour_lanes)) out.colour_lanes = ['Cyan', 'Magenta', 'Yellow', 'Black', '', '', '', ''];
  if (!Array.isArray(out.anilox_lanes)) out.anilox_lanes = ['', '', '', '', '', '', '', ''];
  out.colour_lanes = out.colour_lanes.slice(0, 8).concat(Array(Math.max(0, 8 - out.colour_lanes.length)).fill('')).map(v => String(v || '').trim());
  out.anilox_lanes = out.anilox_lanes.slice(0, 8).concat(Array(Math.max(0, 8 - out.anilox_lanes.length)).fill('')).map(v => String(v || '').trim());

  const materialRows = Array.isArray(out.material_rows) ? out.material_rows : [];
  out.material_rows = materialRows.length ? materialRows : [{
    roll_no: String(job.roll_no || '').trim(),
    material_company: out.material_company,
    material_name: out.material_name,
    order_mtr: out.order_mtr,
    order_qty: out.order_qty,
    color_match_status: String(out.color_match_status || 'Matched').trim(),
    wastage_meters: out.wastage_meters ?? '',
  }];

  if (!Array.isArray(out.roll_wastage_rows) || !out.roll_wastage_rows.length) {
    out.roll_wastage_rows = out.material_rows.map(r => ({
      roll_no: String(r.roll_no || '').trim(),
      color_match_status: String(r.color_match_status || out.color_match_status || 'Matched').trim(),
      wastage_meters: r.wastage_meters ?? '',
    }));
  }

  if (!Array.isArray(out.color_anilox_rows) || !out.color_anilox_rows.length) {
    const CMYK_DEFAULTS = ['Cyan','Magenta','Yellow','Black'];
    out.color_anilox_rows = Array.from({ length: 8 }, (_, i) => {
      const colorVal = String(out.colour_lanes[i] || '').trim() || (i < 4 ? CMYK_DEFAULTS[i] : '');
      const anVal = String(out.anilox_lanes[i] || '').trim();
      return {
        lane: i + 1,
        color_code: colorVal,
        color_name: '',
        anilox_value: anVal,
        anilox_custom: '',
      };
    });
  }
  return out;
}

function toLocalDateInputValue(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
  const dt = new Date(raw);
  if (Number.isNaN(dt.getTime())) return '';
  const m = String(dt.getMonth() + 1).padStart(2, '0');
  const d = String(dt.getDate()).padStart(2, '0');
  return `${dt.getFullYear()}-${m}-${d}`;
}

function toSafeNumber(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function secondsToHms(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = String(Math.floor(sec / 3600)).padStart(2, '0');
  const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
  const s = String(sec % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

function bindFlexoFormBehavior(container) {
  if (!container) return;
  const totalField = container.querySelector('[name=total_wastage_meters]');
  const refreshWastage = () => {
    const sum = Array.from(container.querySelectorAll('[name^="roll_wastage_"]')).reduce((acc, el) => acc + toSafeNumber(el.value), 0);
    if (totalField) totalField.value = sum ? String(sum.toFixed(2)) : '';
  };

  container.querySelectorAll('[name^="roll_wastage_"]').forEach(el => {
    el.addEventListener('input', refreshWastage);
  });
  refreshWastage();

  const CMYK_COLORS = ['Cyan','Magenta','Yellow','Black'];
  const cmykAniloxOpts = ['None','250','300','400','500','550','600','700','750','800','850','900','1000','1100','1200','1300','1400','Custom'];
  const defaultAniloxOpts = ['None','60','80','100','120','140','160','Custom'];
  container.querySelectorAll('[data-lane-row]').forEach(row => {
    const colorSel = row.querySelector('[data-role="color-select"]');
    const colorName = row.querySelector('[data-role="color-name"]');
    const anSel = row.querySelector('[data-role="anilox-select"]');
    const anCustom = row.querySelector('[data-role="anilox-custom"]');
    const COLOR_BG = {
      'Cyan':'#e0f7fa','Magenta':'#fce4ec','Yellow':'#fffde7','Black':'#eceff1',
      'P1':'#e8eaf6','P2':'#e0f2f1','P3':'#fff3e0','P4':'#f3e5f5','UV':'#ede7f6','Other':'#f5f5f5'
    };
    const sync = () => {
      const c = String(colorSel?.value || '');
      const isCMYK = CMYK_COLORS.includes(c);
      if (colorName) colorName.style.display = (c && c !== 'None' && !isCMYK) ? '' : 'none';
      if (colorSel) colorSel.style.background = COLOR_BG[c] || '#fcfdff';
      if (anSel) {
        const curVal = anSel.value;
        const opts = isCMYK ? cmykAniloxOpts : defaultAniloxOpts;
        anSel.innerHTML = opts.map(o => `<option value="${o}"${o===curVal?' selected':''}>${o}</option>`).join('');
        if (!opts.includes(curVal)) anSel.value = 'None';
      }
      if (anCustom) anCustom.style.display = String(anSel?.value || '') === 'Custom' ? '' : 'none';
    };
    if (colorSel) colorSel.addEventListener('change', sync);
    if (anSel) anSel.addEventListener('change', sync);
    sync();
  });
}

function resolvePrintDisplayName(job) {
  if (job && String(job.display_job_name || '').trim() !== '') return String(job.display_job_name).trim();
  if (job && String(job.planning_job_name || '').trim() !== '') return String(job.planning_job_name).trim();
  const jobNo = String(job?.job_no || '').trim();
  const dept = String(job?.department || 'flexo_printing').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  if (jobNo !== '') return `${jobNo} (${dept})`;
  return dept || '—';
}

function operatorTabMatch(card, status) {
  const lockState = String(card.dataset.lockstate || '').toLowerCase();
  const cardStatus = String(card.dataset.status || '').trim();

  if (status === 'Queued') return lockState === 'locked';
  if (status === 'Pending') return lockState !== 'locked' && (cardStatus === 'Pending' || cardStatus === 'Queued');
  if (status === 'all') return true;
  return cardStatus === status;
}

function applyFPFilters() {
  const q = String(document.getElementById('fpSearch')?.value || '').toLowerCase();
  document.querySelectorAll('.fp-card').forEach(card => {
    const searchOk = (card.dataset.search || '').includes(q);
    const statusOk = operatorTabMatch(card, activeStatusFilter);
    card.style.display = (searchOk && statusOk) ? '' : 'none';
  });
}

// ─── Filters ────────────────────────────────────────────────
function filterFP(status, btn) {
  activeStatusFilter = status;
  document.querySelectorAll('.fp-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  applyFPFilters();
}

function clickStatFilter(status) {
  activeStatusFilter = status;
  document.querySelectorAll('.fp-filter-btn').forEach(b => {
    b.classList.toggle('active', b.textContent.trim() === (status === 'all' ? 'All' : status));
  });
  // Ensure active tab is on Job Cards
  switchFPTab('active');
  applyFPFilters();
}

document.getElementById('fpSearch').addEventListener('input', function() {
  applyFPFilters();
});

applyFPFilters();

// ─── Tab switching ──────────────────────────────────────────
function switchFPTab(tab) {
  const panelActive  = document.getElementById('fpPanelActive');
  const panelHistory = document.getElementById('fpPanelHistory');
  const btnActive    = document.getElementById('fpTabBtnActive');
  const btnHistory   = document.getElementById('fpTabBtnHistory');
  if (tab === 'history') {
    panelActive.style.display  = 'none';
    panelHistory.style.display = '';
    btnActive.classList.remove('active');
    btnHistory.classList.add('active');
  } else {
    panelActive.style.display  = '';
    panelHistory.style.display = 'none';
    btnActive.classList.add('active');
    btnHistory.classList.remove('active');
  }
}

// ─── History search ──────────────────────────────────────────
function filterHistory(q) {
  q = (q || '').toLowerCase();
  document.querySelectorAll('#fpHistoryBody tr[data-search]').forEach(row => {
    row.style.display = (row.dataset.search || '').includes(q) ? '' : 'none';
  });
}

// ─── Multi-select print ──────────────────────────────────────
function updatePrintCount() {
  const checked = document.querySelectorAll('.fp-card-check:checked').length;
  const btn = document.getElementById('fpPrintSelBtn');
  if (btn) {
    btn.style.display = checked > 0 ? 'inline-flex' : 'none';
    const cnt = btn.querySelector('#fpSelCount');
    if (cnt) cnt.textContent = checked;
  }
}

async function printSelectedJobs() {
  const checkedIds = Array.from(document.querySelectorAll('.fp-card-check:checked')).map(c => parseInt(c.dataset.id));
  if (!checkedIds.length) { alert('No job cards selected.'); return; }
  const selectedJobs = ALL_JOBS.filter(j => checkedIds.includes(j.id));
  let pages = '';
  for (const [idx, job] of selectedJobs.entries()) {
    const extra = job.extra_data_parsed || {};
    const card = normalizeCardData(job, extra);
    const pb  = idx < selectedJobs.length - 1 ? 'page-break-after:always;' : '';
    const jqrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
    const jqrDataUrl = await generateQR(jqrUrl);
    pages += `<div style="${pb}">${renderPrintCardHtml(job, card, extra, jqrDataUrl)}</div>`;
  }
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Flexo Job Cards (${selectedJobs.length})</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${pages}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

// ─── Live timers ────────────────────────────────────────────
function updateTimers() {
  document.querySelectorAll('.fp-timer[data-started]').forEach(el => {
    const started = parseInt(el.dataset.started);
    if (!started) return;
    const diff = Math.floor((Date.now() - started) / 1000);
    const h = String(Math.floor(diff/3600)).padStart(2,'0');
    const m = String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    el.textContent = h + ':' + m + ':' + s;
  });
}
setInterval(updateTimers, 1000);
updateTimers();

// ─── Status update ──────────────────────────────────────────
async function updateFPStatus(id, newStatus) {
  if (!confirm('Set this job to ' + newStatus + '?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ─── Start Job with Timer Overlay ───────────────────────────
let _timerInterval = null;
let _timerStart = 0;
let _timerJobId = null;

async function startJobWithTimer(id) {
  if (!confirm('Start this job?')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', 'Running');
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { alert('Error: ' + (data.error || 'Unknown')); return; }
  } catch (err) { alert('Network error: ' + err.message); return; }

  _timerJobId = id;
  _timerStart = Date.now();
  const job = ALL_JOBS.find(j => j.id == id) || {};
  const jobLabel = resolvePrintDisplayName(job) || ('Job #' + id);

  const overlay = document.createElement('div');
  overlay.className = 'fp-timer-overlay';
  overlay.id = 'fpTimerOverlay';
  overlay.innerHTML = `
    <div class="fp-timer-jobinfo"><i class="bi bi-printer"></i> ${jobLabel}</div>
    <div class="fp-timer-display" id="fpTimerCounter">00:00:00</div>
    <div class="fp-timer-actions">
      <button class="fp-timer-btn-cancel" onclick="cancelTimer()"><i class="bi bi-x-lg"></i> Cancel</button>
      <button class="fp-timer-btn-end" onclick="endTimer()"><i class="bi bi-stop-fill"></i> End</button>
    </div>
  `;
  document.body.appendChild(overlay);

  _timerInterval = setInterval(() => {
    const diff = Math.floor((Date.now() - _timerStart) / 1000);
    const h = String(Math.floor(diff/3600)).padStart(2,'0');
    const m = String(Math.floor((diff%3600)/60)).padStart(2,'0');
    const s = String(diff%60).padStart(2,'0');
    const el = document.getElementById('fpTimerCounter');
    if (el) el.textContent = h + ':' + m + ':' + s;
  }, 1000);
}

function cancelTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('fpTimerOverlay');
  if (ov) ov.remove();
  // Revert status back to Pending
  (async () => {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'update_status');
    fd.append('job_id', _timerJobId);
    fd.append('status', 'Pending');
    try { await fetch(API_BASE, { method: 'POST', body: fd }); } catch(e) {}
    _timerJobId = null;
    location.reload();
  })();
}

function endTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('fpTimerOverlay');
  if (ov) ov.remove();
  const jobId = _timerJobId;
  _timerJobId = null;
  // Auto-open camera for physical photo, then open detail form
  const camInput = document.createElement('input');
  camInput.type = 'file';
  camInput.accept = 'image/*';
  camInput.capture = 'environment';
  camInput.style.display = 'none';
  document.body.appendChild(camInput);
  camInput.addEventListener('change', async function() {
    const file = camInput.files?.[0];
    if (file) {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('action', 'upload_printing_photo');
      fd.append('job_id', jobId);
      fd.append('photo', file);
      try {
        const res = await fetch(API_BASE, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) alert('Photo upload failed: ' + (data.error || 'Unknown'));
      } catch (err) { alert('Photo upload error: ' + err.message); }
    }
    camInput.remove();
    openPrintDetail(jobId, 'complete');
  });
  // If user cancels camera, still open the form
  camInput.addEventListener('cancel', function() {
    camInput.remove();
    openPrintDetail(jobId, 'complete');
  });
  camInput.click();
}

// ─── Submit operator extra data + complete ──────────────────
async function submitAndComplete(id) {
  const job = ALL_JOBS.find(j => j.id == id) || {};
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateFPStatus(id, 'Completed');

  const rollRows = Array.from(form.querySelectorAll('[data-roll-row]')).map((row, idx) => ({
    idx: idx + 1,
    roll_no: String(row.dataset.rollNo || '').trim(),
    material_company: String(row.dataset.materialCompany || '').trim(),
    material_name: String(row.dataset.materialName || '').trim(),
    order_mtr: String(row.dataset.orderMtr || '').trim(),
    order_qty: String(row.dataset.orderQty || '').trim(),
    color_match_status: getFieldVal(form, 'color_match_status_' + idx) || 'Matched',
    wastage_meters: getNumberFieldVal(form, 'roll_wastage_' + idx),
  }));

  const laneRows = Array.from(form.querySelectorAll('[data-lane-row]')).map((row, idx) => ({
    lane: idx + 1,
    color_code: getFieldVal(row, 'color_lane_code_' + idx),
    color_name: getFieldVal(row, 'color_lane_name_' + idx),
    anilox_value: getFieldVal(row, 'anilox_lane_value_' + idx),
    anilox_custom: getFieldVal(row, 'anilox_lane_custom_' + idx),
  }));

  const startedTs = job.started_at ? new Date(job.started_at).getTime() : 0;
  const elapsed = startedTs ? Math.floor((Date.now() - startedTs) / 1000) : 0;
  const autoTimeSpent = secondsToHms(elapsed);

  const baseExtra = normalizeCardData(job, job.extra_data_parsed || {});
  const extraData = Object.assign({}, baseExtra, {
    ink_colors: form.querySelector('[name=ink_colors]')?.value || '',
    cylinder_ref: form.querySelector('[name=cylinder_ref]')?.value || '',
    impression_count: form.querySelector('[name=impression_count]')?.value || '',
    print_speed: form.querySelector('[name=print_speed]')?.value || '',
    color_match_status: getFieldVal(form, 'color_match_status_0') || 'Matched',
    wastage_meters: getFieldVal(form, 'total_wastage_meters') || '',
    total_wastage_meters: getFieldVal(form, 'total_wastage_meters') || '',
    roll_wastage_rows: rollRows,
    material_rows: rollRows,
    operator_notes: form.querySelector('[name=operator_notes]')?.value || '',
    defects_text: getFieldVal(form, 'defects_text'),
    defects: getFieldVal(form, 'defects_text') ? [getFieldVal(form, 'defects_text')] : [],
    mkd_job_sl_no: getFieldVal(form, 'mkd_job_sl_no_locked'),
    job_date: getFieldVal(form, 'job_date_locked'),
    job_name: getFieldVal(form, 'job_name_locked') || resolvePrintDisplayName(job),
    die: getFieldVal(form, 'die_locked'),
    plate_no: getFieldVal(form, 'plate_no_locked'),
    material_company: getFieldVal(form, 'material_company_locked'),
    material_name: getFieldVal(form, 'material_name_locked'),
    order_mtr: getNumberFieldVal(form, 'order_mtr_locked'),
    order_qty: getNumberFieldVal(form, 'order_qty_locked'),
    reel_no_c1: getFieldVal(form, 'reel_no_c1_locked'),
    reel_no_c2: getFieldVal(form, 'reel_no_c2_locked'),
    width_c1: getNumberFieldVal(form, 'width_c1_locked'),
    width_c2: getNumberFieldVal(form, 'width_c2_locked'),
    length_c1: getNumberFieldVal(form, 'length_c1_locked'),
    length_c2: getNumberFieldVal(form, 'length_c2_locked'),
    label_size: getFieldVal(form, 'label_size_locked'),
    repeat_mm: getFieldVal(form, 'repeat_mm_locked'),
    direction: getFieldVal(form, 'direction_locked'),
    actual_qty: getNumberFieldVal(form, 'actual_qty'),
    electricity: getFieldVal(form, 'electricity'),
    time_spent: getFieldVal(form, 'time_spent') || autoTimeSpent,
    prepared_by: getFieldVal(form, 'prepared_by') || CURRENT_USER,
    filled_by: getFieldVal(form, 'filled_by') || CURRENT_USER,
    colour_lanes: laneRows.map(r => String(r.color_code || '').trim()),
    anilox_lanes: laneRows.map(r => String(r.anilox_value === 'Custom' ? r.anilox_custom : r.anilox_value || '').trim()),
    color_anilox_rows: laneRows,
    physical_print_photo_url: getFieldVal(form, 'physical_print_photo_url'),
    physical_print_photo_path: getFieldVal(form, 'physical_print_photo_path')
  });

  const fd1 = new FormData();
  fd1.append('csrf_token', CSRF);
  fd1.append('action', 'submit_extra_data');
  fd1.append('job_id', id);
  fd1.append('extra_data', JSON.stringify(extraData));
  try {
    const r1 = await fetch(API_BASE, { method: 'POST', body: fd1 });
    const d1 = await r1.json();
    if (!d1.ok) { alert('Save error: ' + (d1.error||'Unknown')); return; }
  } catch(e) { alert('Network error'); return; }

  await updateFPStatus(id, 'Completed');
}

function renderLaneSelectOptions(selected) {
  const opts = ['None', 'Cyan', 'Magenta', 'Yellow', 'Black', 'P1', 'P2', 'P3', 'P4', 'UV', 'Other'];
  return opts.map(o => `<option value="${o}"${String(selected||'')===o?' selected':''}>${o}</option>`).join('');
}

function renderAniloxOptions(selected, forCMYK) {
  const cmykOpts = ['None','250','300','400','500','550','600','700','750','800','850','900','1000','1100','1200','1300','1400','Custom'];
  const defaultOpts = ['None','60','80','100','120','140','160','Custom'];
  const opts = forCMYK ? cmykOpts : defaultOpts;
  return opts.map(o => `<option value="${o}"${String(selected||'')===o?' selected':''}>${o}</option>`).join('');
}

async function handlePrintingPhotoUpload(input, jobId) {
  const file = input?.files?.[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'upload_printing_photo');
  fd.append('job_id', jobId);
  fd.append('photo', file);
  input.disabled = true;
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      alert('Image upload failed: ' + (data.error || 'Unknown'));
      return;
    }
    const img = document.getElementById('physical-photo-preview');
    if (img) img.src = data.photo_url || '';
    const f1 = document.querySelector('[name=physical_print_photo_url]');
    const f2 = document.querySelector('[name=physical_print_photo_path]');
    if (f1) f1.value = data.photo_url || '';
    if (f2) f2.value = data.photo_path || '';
  } catch (err) {
    alert('Image upload network error: ' + err.message);
  } finally {
    input.disabled = false;
  }
}

let voiceRec = null;
function startVoiceToField(fieldName, btn) {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    alert('Voice input is not supported in this browser.');
    return;
  }
  if (voiceRec) {
    try { voiceRec.stop(); } catch (_) {}
    voiceRec = null;
  }
  const input = document.querySelector('[name="' + fieldName + '"]');
  if (!input) return;
  const rec = new SpeechRecognition();
  rec.lang = 'en-IN';
  rec.interimResults = true;
  rec.continuous = false;
  let finalText = '';
  if (btn) btn.classList.add('active');
  rec.onresult = (event) => {
    let interim = '';
    for (let i = event.resultIndex; i < event.results.length; i += 1) {
      const t = String(event.results[i][0]?.transcript || '');
      if (event.results[i].isFinal) finalText += t + ' ';
      else interim += t;
    }
    input.value = (finalText + interim).trim();
  };
  rec.onerror = () => {
    if (btn) btn.classList.remove('active');
  };
  rec.onend = () => {
    if (btn) btn.classList.remove('active');
    voiceRec = null;
  };
  voiceRec = rec;
  rec.start();
}

// ─── Detail modal ───────────────────────────────────────────
async function openPrintDetail(id, mode) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;

  const sts = job.status;
  const stsClass = {Queued:'queued',Pending:'pending',Running:'running',Completed:'completed','QC Passed':'completed'}[sts]||'pending';
  const extra = job.extra_data_parsed || {};
  const card = normalizeCardData(job, extra);
  const createdAt = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const startedAt = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completedAt = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = job.duration_minutes;
  const startedTs = job.started_at ? new Date(job.started_at).getTime() : 0;
  const prevDone = !job.previous_job_id || !job.prev_job_status || ['Completed','QC Passed','Closed','Finalized'].includes(job.prev_job_status);
  const elapsedSec = startedTs ? Math.floor((Date.now() - startedTs) / 1000) : 0;

  const rollRows = (Array.isArray(card.roll_wastage_rows) && card.roll_wastage_rows.length ? card.roll_wastage_rows : card.material_rows).map((row, idx) => ({
    idx,
    roll_no: String(row.roll_no || (idx === 0 ? (job.roll_no || '') : '')).trim(),
    material_company: String(row.material_company || card.material_company || job.company || '').trim(),
    material_name: String(row.material_name || card.material_name || job.paper_type || '').trim(),
    order_mtr: row.order_mtr ?? card.order_mtr ?? '',
    order_qty: row.order_qty ?? card.order_qty ?? '',
    color_match_status: String(row.color_match_status || card.color_match_status || 'Matched').trim(),
    wastage_meters: row.wastage_meters ?? '',
  }));

  const laneRows = Array.from({ length: 8 }, (_, i) => {
    const lr = Array.isArray(card.color_anilox_rows) ? (card.color_anilox_rows[i] || {}) : {};
    const colorCode = String(lr.color_code || card.colour_lanes[i] || 'None').trim() || 'None';
    const anValRaw = String(lr.anilox_value || card.anilox_lanes[i] || 'None').trim() || 'None';
    const ALL_ANILOX = ['None','60','80','100','120','140','160','250','300','400','500','550','600','700','750','800','850','900','1000','1100','1200','1300','1400','Custom'];
    return {
      lane: i + 1,
      color_code: colorCode,
      color_name: String(lr.color_name || '').trim(),
      anilox_value: ALL_ANILOX.includes(anValRaw) ? anValRaw : 'Custom',
      anilox_custom: ALL_ANILOX.includes(anValRaw) ? String(lr.anilox_custom || '').trim() : anValRaw,
    };
  });

  const planningImage = String(job.planning_image_url || '').trim();
  const physicalImage = String(card.physical_print_photo_url || '').trim();

  document.getElementById('dm-jobno').textContent = job.job_no;
  const badge = document.getElementById('dm-status-badge');
  badge.textContent = sts;
  badge.className = 'fp-badge fp-badge-' + stsClass;

  let html = '';
  const viewQrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no || '')}`;
  const viewQrDataUrl = await generateQR(viewQrUrl);

  if (job.prev_job_no) {
    const pvColor = prevDone ? '#16a34a' : '#f59e0b';
    html += `<div class="fp-detail-section" style="padding:12px;background:${prevDone?'#f0fdf4':'#fef3c7'};border-radius:10px;border-left:4px solid ${pvColor}">
      <div style="display:flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700">
        <i class="bi bi-${prevDone?'check-circle-fill':'lock-fill'}" style="color:${pvColor}"></i>
        Previous Job: <span style="color:var(--fp-brand)">${esc(job.prev_job_no)}</span>
        — <span style="color:${pvColor}">${esc(job.prev_job_status||'—')}</span>
      </div>
    </div>`;
  }

  if (viewQrDataUrl) {
    html += `<div class="fp-detail-section" style="display:flex;align-items:center;justify-content:space-between;gap:14px;background:#f8fafc">
      <div>
        <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.05em">Job Card QR</div>
        <div style="font-size:.74rem;color:#475569">Scan to open this flexo job card on mobile/desktop</div>
      </div>
      <div style="text-align:center"><img src="${viewQrDataUrl}" alt="Job QR" style="width:96px;height:96px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px"></div>
    </div>`;
  }

  // Date + Department heading at top of job card
  html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#ede9fe;border-radius:10px;margin-bottom:10px;border:1px solid #c4b5fd">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="font-size:.9rem;font-weight:900;color:#5b21b6"><i class="bi bi-calendar3"></i> Date: ${esc(card.job_date || '—')}</div>
      <div style="font-size:.82rem;font-weight:900;color:#7c3aed;padding:4px 12px;background:#f5f3ff;border-radius:6px">Department: Flexo Printing</div>
    </div>
    <div style="font-size:.72rem;font-weight:700;color:#6d28d9">Status: ${esc(job.status || '—')}</div>
  </div>`;

  // Note field (PLN, JMB, FLX IDs and Job name)
  if (job.notes_display || job.notes) {
    html += `<div style="padding:10px 14px;background:#fef3c7;border-radius:10px;margin-bottom:10px;border:1px solid #fcd34d;font-size:.78rem;color:#92400e">
      <div style="font-weight:800;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;color:#78350f"><i class="bi bi-sticky"></i> Note</div>
      <div style="font-weight:700">${esc(job.notes_display || job.notes || '')}</div>
    </div>`;
  }

  html += `<div class="fp-op-topstrip" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="fp-op-topitem"><div class="k">Created</div><div class="v">${esc(createdAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Started</div><div class="v">${esc(startedAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Completed</div><div class="v">${esc(completedAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Elapsed</div><div class="v">${startedTs ? secondsToHms(elapsedSec) : '—'}</div></div>
  </div>`;

  html += `<div class="fp-detail-section fp-op-shell"><div class="fp-op-form">
    <div class="fp-op-section"><div class="fp-op-h">Job Information</div><div class="fp-op-b fp-op-grid-4">
      <div class="fp-op-field"><label>Job No</label><input type="text" value="${esc(job.job_no||'—')}" readonly></div>
      <div class="fp-op-field"><label>Job Name</label><input type="text" value="${esc(resolvePrintDisplayName(job))}" readonly></div>
      <div class="fp-op-field"><label>Department</label><input type="text" value="Flexo Printing" readonly></div>
      <div class="fp-op-field"><label>Priority / Sequence</label><input type="text" value="${esc((job.planning_priority||'Normal') + ' / #' + (job.sequence_order||2))}" readonly></div>
    </div></div>
  </div></div>`;

  if (IS_OPERATOR_VIEW && (sts === 'Running' || mode === 'complete' || sts === 'Pending')) {
    html += `<div class="fp-detail-section fp-op-shell"><h3><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</h3>
    <form id="dm-operator-form" class="fp-op-form">
      <div class="fp-op-section"><div class="fp-op-h">Locked Planning Fields</div><div class="fp-op-b fp-op-grid-3">
        <div class="fp-op-field"><label>Job Name</label><input type="text" name="job_name_locked" value="${esc(card.job_name||resolvePrintDisplayName(job))}" readonly></div>
        <div class="fp-op-field"><label>Die</label><input type="text" name="die_locked" value="${esc(card.die||'')}" readonly></div>
        <div class="fp-op-field"><label>Plate No</label><input type="text" name="plate_no_locked" value="${esc(card.plate_no||'')}" readonly></div>
        <div class="fp-op-field"><label>Material Company</label><input type="text" name="material_company_locked" value="${esc(card.material_company||'')}" readonly></div>
        <div class="fp-op-field"><label>Material Name</label><input type="text" name="material_name_locked" value="${esc(card.material_name||'')}" readonly></div>
        <div class="fp-op-field"><label>Order MTR</label><input type="text" name="order_mtr_locked" value="${esc(card.order_mtr||'')}" readonly></div>
        <div class="fp-op-field"><label>Order QTY</label><input type="text" name="order_qty_locked" value="${esc(card.order_qty||'')}" readonly></div>
        <div class="fp-op-field"><label>Label Size</label><input type="text" name="label_size_locked" value="${esc(card.label_size||'')}" readonly></div>
        <div class="fp-op-field"><label>Repeat</label><input type="text" name="repeat_mm_locked" value="${esc(card.repeat_mm||'')}" readonly></div>
        <div class="fp-op-field"><label>Direction</label><input type="text" name="direction_locked" value="${esc(card.direction||'')}" readonly></div>
      </div>
      <input type="hidden" name="mkd_job_sl_no_locked" value="${esc(card.mkd_job_sl_no||'')}">
      <input type="hidden" name="job_date_locked" value="${esc(toLocalDateInputValue(card.job_date||''))}">
      <input type="hidden" name="reel_no_c1_locked" value="${esc(card.reel_no_c1||'')}">
      <input type="hidden" name="reel_no_c2_locked" value="${esc(card.reel_no_c2||'')}">
      <input type="hidden" name="width_c1_locked" value="${esc(card.width_c1||'')}">
      <input type="hidden" name="width_c2_locked" value="${esc(card.width_c2||'')}">
      <input type="hidden" name="length_c1_locked" value="${esc(card.length_c1||'')}">
      <input type="hidden" name="length_c2_locked" value="${esc(card.length_c2||'')}">
      </div>

      <div class="fp-op-section"><div class="fp-op-h">Roll-wise Material and Wastage</div><div class="fp-op-b">
        <table class="fp-op-roll-table"><thead><tr><th>#</th><th>Roll No</th><th>Material</th><th>Order MTR</th><th>Order QTY</th><th>Color Match</th><th>Wastage (m)</th></tr></thead><tbody>
          ${rollRows.map((r, idx) => `<tr data-roll-row data-roll-no="${esc(r.roll_no)}" data-material-company="${esc(r.material_company)}" data-material-name="${esc(r.material_name)}" data-order-mtr="${esc(r.order_mtr)}" data-order-qty="${esc(r.order_qty)}"><td>${idx+1}</td><td>${esc(r.roll_no||'—')}</td><td>${esc((r.material_company||'—')+' / '+(r.material_name||'—'))}</td><td>${esc(r.order_mtr||'—')}</td><td>${esc(r.order_qty||'—')}</td><td><select name="color_match_status_${idx}"><option value="Matched"${r.color_match_status==='Matched'?' selected':''}>Matched</option><option value="Slight Variation"${r.color_match_status==='Slight Variation'?' selected':''}>Slight Variation</option><option value="Mismatch"${r.color_match_status==='Mismatch'?' selected':''}>Mismatch</option></select></td><td><input type="number" step="0.01" name="roll_wastage_${idx}" value="${esc(r.wastage_meters||'')}" placeholder="0.00"></td></tr>`).join('')}
        </tbody></table>
        <div style="margin-top:8px;max-width:220px"><div class="fp-op-field"><label>Total Wastage (m)</label><input type="text" name="total_wastage_meters" value="${esc(card.total_wastage_meters||'')}" readonly></div></div>
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Color + Anilox (Color 1-8)</div><div class="fp-op-b" style="gap:10px">
        ${laneRows.map((r, idx) => `<div class="fp-op-lane-row" data-lane-row><div class="fp-op-mini"><strong>Color ${idx+1}</strong></div><div style="display:grid;gap:6px"><select data-role="color-select" name="color_lane_code_${idx}">${renderLaneSelectOptions(r.color_code)}</select><input data-role="color-name" type="text" name="color_lane_name_${idx}" value="${esc(r.color_name||'')}" placeholder="Color name" style="display:none"></div><div style="display:grid;gap:6px"><select data-role="anilox-select" name="anilox_lane_value_${idx}">${renderAniloxOptions(r.anilox_value, ['Cyan','Magenta','Yellow','Black'].includes(r.color_code))}</select><input data-role="anilox-custom" type="text" name="anilox_lane_custom_${idx}" value="${esc(r.anilox_custom||'')}" placeholder="Custom anilox" style="display:none"></div></div>`).join('')}
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Planning vs Physical Print Image</div><div class="fp-op-b"><div class="fp-photo-grid"><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Planning Image</strong></div><img class="fp-photo-preview" src="${esc(planningImage || '')}" alt="Planning image" onerror="this.style.opacity='0.4';this.alt='Planning image not available'"></div><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Physical Image</strong></div><img id="physical-photo-preview" class="fp-photo-preview" src="${esc(physicalImage || '')}" alt="Physical image" onerror="this.style.opacity='0.4';this.alt='Physical image not available'"><input type="hidden" name="physical_print_photo_url" value="${esc(physicalImage || '')}"><input type="hidden" name="physical_print_photo_path" value="${esc(card.physical_print_photo_path || '')}"><input type="file" id="fp-camera-input" accept="image/*" capture="environment" style="display:none" onchange="handlePrintingPhotoUpload(this, ${job.id})"></div></div></div></div>

      <div class="fp-op-section"><div class="fp-op-h">Production and Signoff</div><div class="fp-op-b fp-op-grid-2">
        <div class="fp-op-field"><label>Production Total Quantity</label><input type="number" step="1" name="actual_qty" value="${esc(card.actual_qty||'')}"></div>
        <div class="fp-op-field"><label>Electricity</label><input type="text" name="electricity" value="${esc(card.electricity||'')}"></div>
      </div><div class="fp-op-b fp-op-grid-2">
        <div class="fp-op-field"><label>Time</label><input type="text" name="time_spent" value="${esc(card.time_spent||secondsToHms(elapsedSec))}" readonly></div>
        <div class="fp-op-field"><label>Prepared By</label><input type="text" name="prepared_by" value="${esc(card.prepared_by||CURRENT_USER||'')}" readonly></div>
      </div><div class="fp-op-b fp-op-grid-2"><div class="fp-op-field"><label>Filled By</label><input type="text" name="filled_by" value="${esc(card.filled_by||CURRENT_USER||'')}" readonly></div><div class="fp-op-field"><label>Defects Found</label><select name="defects_text">${['','Light Color','Dark Color','Plate Damage','Registration Error','Ink Smudge','Ink Spreading','Dot Missing','Streaks','Hazing','Scratch Marks','Material Defect','Other'].map(o=>o?'<option value="'+o+'"'+(card.defects_text===o?' selected':'')+'>'+o+'</option>':'<option value="">-- Select --</option>').join('')}</select></div></div><div class="fp-op-b fp-op-grid-2"><div class="fp-op-field"><label>Operator Notes</label><textarea name="operator_notes" placeholder="Observations, adjustments, ink changes&hellip;">${esc(extra.operator_notes||'')}</textarea></div><div class="fp-op-field"><label>Voice Notes</label><div style="display:flex;align-items:center;gap:8px"><button type="button" class="fp-voice-btn" onclick="startVoiceToField('operator_notes', this)"><i class="bi bi-mic"></i> Speak Notes</button></div></div></div></div>
    </form></div>`;
  } else {
    // Production (non-operator) read-only view — same format
    html += `<div class="fp-detail-section fp-op-shell"><div class="fp-op-form">
      <div class="fp-op-section"><div class="fp-op-h">Locked Planning Fields</div><div class="fp-op-b fp-op-grid-3">
        <div class="fp-op-field"><label>Job Name</label><input type="text" value="${esc(card.job_name||resolvePrintDisplayName(job))}" readonly></div>
        <div class="fp-op-field"><label>Die</label><input type="text" value="${esc(card.die||'')}" readonly></div>
        <div class="fp-op-field"><label>Plate No</label><input type="text" value="${esc(card.plate_no||'')}" readonly></div>
        <div class="fp-op-field"><label>Material Company</label><input type="text" value="${esc(card.material_company||'')}" readonly></div>
        <div class="fp-op-field"><label>Material Name</label><input type="text" value="${esc(card.material_name||'')}" readonly></div>
        <div class="fp-op-field"><label>Order MTR</label><input type="text" value="${esc(card.order_mtr||'')}" readonly></div>
        <div class="fp-op-field"><label>Order QTY</label><input type="text" value="${esc(card.order_qty||'')}" readonly></div>
        <div class="fp-op-field"><label>Label Size</label><input type="text" value="${esc(card.label_size||'')}" readonly></div>
        <div class="fp-op-field"><label>Repeat</label><input type="text" value="${esc(card.repeat_mm||'')}" readonly></div>
        <div class="fp-op-field"><label>Direction</label><input type="text" value="${esc(card.direction||'')}" readonly></div>
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Roll-wise Material and Wastage</div><div class="fp-op-b">
        <table class="fp-op-roll-table"><thead><tr><th>#</th><th>Roll No</th><th>Material</th><th>Order MTR</th><th>Order QTY</th><th>Color Match</th><th>Wastage (m)</th></tr></thead><tbody>
          ${rollRows.map((r, idx) => `<tr><td>${idx+1}</td><td>${esc(r.roll_no||'—')}</td><td>${esc((r.material_company||'—')+' / '+(r.material_name||'—'))}</td><td>${esc(r.order_mtr||'—')}</td><td>${esc(r.order_qty||'—')}</td><td>${esc(r.color_match_status||'—')}</td><td>${esc(String(r.wastage_meters??'—'))}</td></tr>`).join('')}
        </tbody></table>
        <div style="margin-top:8px;max-width:220px"><div class="fp-op-field"><label>Total Wastage (m)</label><input type="text" value="${esc(card.total_wastage_meters||'')}" readonly></div></div>
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Color + Anilox (Color 1-8)</div><div class="fp-op-b" style="gap:10px">
        ${laneRows.map((r, idx) => `<div class="fp-op-lane-row"><div class="fp-op-mini"><strong>Color ${idx+1}</strong></div><div><input type="text" value="${esc(r.color_code||'—')}" readonly style="width:100%"></div><div><input type="text" value="${esc((r.anilox_value==='Custom'?r.anilox_custom:r.anilox_value)||'—')}" readonly style="width:100%"></div></div>`).join('')}
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Planning vs Physical Print Image</div><div class="fp-op-b"><div class="fp-photo-grid"><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Planning Image</strong></div><img class="fp-photo-preview" src="${esc(planningImage || '')}" alt="Planning image" onerror="this.style.opacity='0.4';this.alt='Not available'"></div><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Physical Image</strong></div><img class="fp-photo-preview" src="${esc(physicalImage || '')}" alt="Physical image" onerror="this.style.opacity='0.4';this.alt='Not available'"></div></div></div></div>

      <div class="fp-op-section"><div class="fp-op-h">Production and Signoff</div><div class="fp-op-b fp-op-grid-2">
        <div class="fp-op-field"><label>Production Total Quantity</label><input type="text" value="${esc(card.actual_qty||'—')}" readonly></div>
        <div class="fp-op-field"><label>Electricity</label><input type="text" value="${esc(card.electricity||'—')}" readonly></div>
      </div><div class="fp-op-b fp-op-grid-2">
        <div class="fp-op-field"><label>Time</label><input type="text" value="${esc(card.time_spent||'—')}" readonly></div>
        <div class="fp-op-field"><label>Prepared By</label><input type="text" value="${esc(card.prepared_by||'—')}" readonly></div>
      </div><div class="fp-op-b fp-op-grid-2"><div class="fp-op-field"><label>Filled By</label><input type="text" value="${esc(card.filled_by||'—')}" readonly></div><div class="fp-op-field"><label>Defects Found</label><input type="text" value="${esc(card.defects_text||'—')}" readonly></div></div>
      ${extra.operator_notes ? `<div class="fp-op-b"><div class="fp-op-field"><label>Operator Notes</label><div style="padding:8px 10px;border:1px solid #dbe5f0;border-radius:8px;font-size:.8rem;font-weight:600;background:#fcfdff;min-height:40px">${esc(extra.operator_notes)}</div></div></div>` : ''}
      </div>
    </div></div>`;
  }

  document.getElementById('dm-body').innerHTML = html;
  updateTimers();
  bindFlexoFormBehavior(document.getElementById('dm-operator-form'));

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += `<button class="fp-action-btn fp-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (sts === 'Pending' && prevDone && IS_OPERATOR_VIEW) fHtml += `<button class="fp-action-btn fp-btn-start" onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-fill"></i> Start Job</button>`;
  if (sts === 'Pending' && !prevDone) fHtml += `<button class="fp-action-btn fp-btn-start" disabled><i class="bi bi-lock-fill"></i> Waiting for Slitting</button>`;
  if (sts === 'Running' && IS_OPERATOR_VIEW) fHtml += `<button class="fp-action-btn fp-btn-complete" onclick="submitAndComplete(${job.id})"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  if (!IS_OPERATOR_VIEW) {
    fHtml += `<button class="fp-action-btn fp-btn-delete" onclick="deleteJob(${job.id})" title="Admin: Delete"><i class="bi bi-trash"></i></button>`;
  }
  fHtml += '</div>';
  document.getElementById('dm-footer').innerHTML = fHtml;

  document.getElementById('fpDetailModal').classList.add('active');
}

function closeDetail() {
  document.getElementById('fpDetailModal').classList.remove('active');
}
document.getElementById('fpDetailModal').addEventListener('click', function(e) {
  if (e.target === this) closeDetail();
});

// ─── Delete job (admin) ─────────────────────────────────────
async function deleteJob(id) {
  if (!confirm('Delete this job card? If linked reset logic applies, related queued jobs may also be rolled back.')) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'delete_job');
  fd.append('job_id', id);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else alert('Error: ' + (data.error || 'Unknown'));
  } catch (err) { alert('Network error: ' + err.message); }
}

// ─── Print Job Card ─────────────────────────────────────────
async function printJobCard(id) {
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const extra = job.extra_data_parsed || {};
  const card = normalizeCardData(job, extra);
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  const html = renderPrintCardHtml(job, card, extra, qrDataUrl);
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Job Card - ${esc(job.job_no)}</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${html}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

function renderPrintCardHtml(job, card, extra, qrDataUrl) {
  const created = job.created_at ? new Date(job.created_at).toLocaleString() : '—';
  const started = job.started_at ? new Date(job.started_at).toLocaleString() : '—';
  const completed = job.completed_at ? new Date(job.completed_at).toLocaleString() : '—';
  const dur = Number(job.duration_minutes);
  const durText = Number.isFinite(dur) ? `${Math.floor(dur/60)}h ${dur%60}m` : '—';
  const rollRows = Array.isArray(card.roll_wastage_rows) && card.roll_wastage_rows.length ? card.roll_wastage_rows : card.material_rows;
  const laneRows = Array.isArray(card.color_anilox_rows) && card.color_anilox_rows.length ? card.color_anilox_rows : Array.from({ length: 8 }, (_, i) => ({
    lane: i + 1,
    color_code: card.colour_lanes[i] || '',
    color_name: '',
    anilox_value: card.anilox_lanes[i] || '',
    anilox_custom: '',
  }));
  const planningImage = String(job.planning_image_url || '').trim();
  const physicalImage = String(card.physical_print_photo_url || '').trim();

  const qrHtml = qrDataUrl
    ? `<div style="text-align:center;margin-left:12px"><img src="${qrDataUrl}" style="width:90px;height:90px;display:block"><div style="font-size:.56rem;color:#64748b;margin-top:2px">Scan job card</div></div>`
    : '';

  return `<div style="font-family:'Segoe UI',Arial,sans-serif;padding:20px;max-width:760px;margin:0 auto;color:#0f172a">
    <div style="border:2px solid #111827;border-radius:12px;overflow:hidden">
      <!-- HEADER -->
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:12px 14px;background:#f8fafc;border-bottom:2px solid #111827">
        <div>
          ${COMPANY.logo ? `<img src="${COMPANY.logo}" style="height:36px;margin-bottom:4px;display:block">` : ''}
          <div style="font-weight:900;font-size:1.02rem;letter-spacing:.02em">${esc(COMPANY.name || 'Company')}</div>
          <div style="font-size:.66rem;color:#475569">${esc(COMPANY.address || '')}</div>
          ${COMPANY.gst ? `<div style="font-size:.62rem;color:#64748b">GST: ${esc(COMPANY.gst)}</div>` : ''}
        </div>
        <div style="display:flex;align-items:flex-start">
          <div style="text-align:right">
            <div style="font-size:.66rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#475569">Department</div>
            <div style="font-size:.92rem;font-weight:900;color:#5b21b6">Flexo Printing</div>
            <div style="font-size:1.2rem;font-weight:900;color:#0f172a;line-height:1.1">${esc(job.job_no || '—')}</div>
            <div style="font-size:.6rem;color:#64748b">Generated: ${esc(created)}</div>
          </div>
          ${qrHtml}
        </div>
      </div>

      <!-- DATE BAR -->
      <div style="padding:8px 14px;background:#ede9fe;border-bottom:1px solid #c4b5fd;display:flex;justify-content:space-between;align-items:center">
        <div style="font-size:.76rem;font-weight:900;color:#5b21b6">Date: ${esc(card.job_date || '—')}</div>
        <div style="font-size:.68rem;font-weight:700;color:#6d28d9">Status: ${esc(job.status || '—')}</div>
      </div>

      <!-- NOTE (PLN, JMB, FLX IDs) -->
      ${(job.notes_display || job.notes) ? `<div style="padding:8px 14px;background:#fef3c7;border-bottom:1px solid #fcd34d;font-size:.7rem;color:#92400e"><strong>Note:</strong> ${esc(job.notes_display || job.notes || '')}</div>` : ''}

      <!-- STATUS BAR -->
      <div style="padding:10px 12px;border-bottom:1px solid #cbd5e1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;font-size:.66rem">
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800">Status</div><div style="font-weight:800">${esc(job.status || '—')}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800">Started</div><div style="font-weight:700">${esc(started)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800">Completed</div><div style="font-weight:700">${esc(completed)}</div></div>
        <div><div style="color:#64748b;text-transform:uppercase;font-weight:800">Duration</div><div style="font-weight:700">${esc(durText)}</div></div>
      </div>

      <div style="padding:10px 12px">
        <!-- LOCKED PLANNING FIELDS (no MKD, no Date, no Reel/Width/Length) -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#5b21b6;background:#ede9fe;padding:5px 8px;border-radius:4px">Locked Planning Fields</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;margin-bottom:10px">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(resolvePrintDisplayName(job))}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Die / Plate</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.die||'—') + ' / ' + (card.plate_no||'—'))}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Material</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.material_company||'—') + ' / ' + (card.material_name||'—'))}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Order MTR / QTY</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.order_mtr||'—') + ' / ' + (card.order_qty||'—'))}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Label Size</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.label_size||'—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Repeat / Direction</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.repeat_mm||'—') + ' / ' + (card.direction||'—'))}</td></tr>
        </table>

        <!-- ROLL-WISE MATERIAL AND WASTAGE -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#5b21b6;background:#ede9fe;padding:5px 8px;border-radius:4px">Roll-wise Material and Wastage</div>
        <table style="width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:10px">
          <thead><tr>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">#</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Roll</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Material</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Order MTR</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Order QTY</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Color Match</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Wastage (m)</th>
          </tr></thead>
          <tbody>
            ${rollRows.map((r, i) => `<tr>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${i + 1}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.roll_no||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc((r.material_company||card.material_company||'—') + ' / ' + (r.material_name||card.material_name||'—'))}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.order_mtr||card.order_mtr||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.order_qty||card.order_qty||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.color_match_status||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(String(r.wastage_meters ?? '—'))}</td>
            </tr>`).join('')}
            <tr><td colspan="6" style="padding:6px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;text-align:right">Total Wastage</td><td style="padding:6px 7px;border:1px solid #cbd5e1;font-weight:800">${esc(card.total_wastage_meters || card.wastage_meters || '—')}</td></tr>
          </tbody>
        </table>

        <!-- COLOR + ANILOX (Color 1-8) -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#5b21b6;background:#ede9fe;padding:5px 8px;border-radius:4px">Color + Anilox (Color 1-8)</div>
        <table style="width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:10px">
          <thead><tr>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Color</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Color Code</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Color Name</th>
            <th style="padding:5px 6px;border:1px solid #cbd5e1;background:#ede9fe;color:#5b21b6">Anilox</th>
          </tr></thead>
          <tbody>
            ${laneRows.map((r, i) => `<tr>
              <td style="padding:5px 6px;border:1px solid #cbd5e1;font-weight:700">Color ${i + 1}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.color_code || '—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.color_name || '—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc((r.anilox_value === 'Custom' ? r.anilox_custom : r.anilox_value) || '—')}</td>
            </tr>`).join('')}
          </tbody>
        </table>

        <!-- IMAGES -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px">
            <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;color:#5b21b6;margin-bottom:6px">Planning Image</div>
            ${planningImage ? `<img src="${esc(planningImage)}" style="width:100%;height:130px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0">` : `<div style="height:130px;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.68rem;color:#94a3b8">Not available</div>`}
          </div>
          <div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px">
            <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;color:#5b21b6;margin-bottom:6px">Physical Image</div>
            ${physicalImage ? `<img src="${esc(physicalImage)}" style="width:100%;height:130px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0">` : `<div style="height:130px;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.68rem;color:#94a3b8">Not available</div>`}
          </div>
        </div>

        <!-- EXECUTION SUMMARY -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#5b21b6;background:#ede9fe;padding:5px 8px;border-radius:4px">Execution Summary</div>
        <table style="width:100%;border-collapse:collapse;font-size:.72rem">
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Production Total Qty</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.actual_qty||'—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Electricity</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.electricity||'—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Time</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.time_spent||'—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Prepared / Filled</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.prepared_by||'—') + ' / ' + (card.filled_by||'—'))}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Defects</td><td colspan="3" style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.defects_text || (Array.isArray(extra.defects) ? extra.defects.join(', ') : '—') || '—')}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Operator Notes</td><td colspan="3" style="padding:5px 7px;border:1px solid #cbd5e1">${esc(extra.operator_notes || '—')}</td></tr>
        </table>
      </div>

      <!-- FOOTER -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;border-top:2px solid #111827;background:#f8fafc">
        <div style="font-size:.68rem;color:#475569">Operator Signature: _____________________</div>
        <div style="font-size:.68rem;color:#475569">Supervisor Signature: _____________________</div>
      </div>
      <div style="padding:6px 12px;font-size:.58rem;color:#64748b;display:flex;justify-content:space-between;border-top:1px solid #cbd5e1">
        <span>Document: Flexo Printing Job Card | ${esc(COMPANY.name || '')}</span>
        <span>Printed at ${esc(new Date().toLocaleString())}</span>
      </div>
    </div>
  </div>`;
}

function generateQR(text) {
  return new Promise(function(resolve) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden';
    document.body.appendChild(el);
    const inner = document.createElement('div');
    el.appendChild(inner);
    try {
      new QRCode(inner, { text: text, width: 160, height: 160, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    } catch(e) { document.body.removeChild(el); resolve(''); return; }
    setTimeout(function() {
      const canvas = inner.querySelector('canvas');
      const img = inner.querySelector('img');
      let url = '';
      if (canvas) url = canvas.toDataURL('image/png');
      else if (img && img.src && img.src.startsWith('data:')) url = img.src;
      document.body.removeChild(el);
      resolve(url);
    }, 150);
  });
}
(function(){
  const autoId = new URLSearchParams(window.location.search).get('auto_job');
  if (autoId) setTimeout(function(){ try { openPrintDetail(parseInt(autoId)); } catch(e){} }, 600);
})();
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
