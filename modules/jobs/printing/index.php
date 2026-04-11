<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$statusCtx = erp_status_context();
$isOperatorView = (bool)($statusCtx['is_operator_view'] ?? false);
$canManualRollEntry = hasPageAction('/modules/jobs/printing/index.php', 'edit')
  || hasPageAction('/modules/operators/printing/index.php', 'edit')
  || hasRole('manager', 'system_admin', 'super_admin')
  || isAdmin();
$canDeleteJobs = isAdmin() && !$isOperatorView;
$canReviewFlexoRequests = hasRole('admin', 'manager', 'system_admin', 'super_admin');
$pageTitle = $isOperatorView ? 'Flexo Operator' : 'Flexo Printing Jobs';
$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? 'Shree Label Creation';
$companyAddr = $appSettings['company_address'] ?? '';
$companyGst  = $appSettings['company_gst'] ?? '';
$logoPath    = $appSettings['logo_path'] ?? '';
$logoUrl     = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$sessionUser = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));

if (!function_exists('jobs_printing_table_exists')) {
  function jobs_printing_table_exists(mysqli $db, string $tableName): bool {
    $tableName = trim($tableName);
    if ($tableName === '') return false;
    $safe = $db->real_escape_string($tableName);
    $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
    if (!($res instanceof mysqli_result)) return false;
    $exists = $res->num_rows > 0;
    $res->close();
    return $exists;
  }
}

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
    $planningImagePath = trim((string)($ped['image_path'] ?? ''));
    if ($planningImagePath !== '' && !preg_match('/^https?:\/\//i', $planningImagePath)) {
      $planningImagePath = BASE_URL . '/' . ltrim(str_replace('\\', '/', $planningImagePath), '/');
    }
    $j['planning_image_url'] = $planningImagePath;
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

// Attach latest/pending Flexo operator request metadata per job (if table exists)
$jobIds = array_values(array_unique(array_map(static fn($j) => (int)($j['id'] ?? 0), $jobs)));
if (!empty($jobIds) && jobs_printing_table_exists($db, 'job_change_requests')) {
  $pendingMap = [];
  $ph = implode(',', array_fill(0, count($jobIds), '?'));
  $types = str_repeat('i', count($jobIds));

  $pStmt = $db->prepare("SELECT job_id, COUNT(*) AS pending_count FROM job_change_requests WHERE request_type = 'flexo_operator_request' AND status = 'Pending' AND job_id IN ($ph) GROUP BY job_id");
  if ($pStmt) {
    $pStmt->bind_param($types, ...$jobIds);
    $pStmt->execute();
    $pRows = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($pRows as $pr) {
      $pendingMap[(int)($pr['job_id'] ?? 0)] = (int)($pr['pending_count'] ?? 0);
    }
  }

  $latestMap = [];
  $lSql = "SELECT r.*
           FROM job_change_requests r
           INNER JOIN (
              SELECT job_id, MAX(id) AS max_id
              FROM job_change_requests
              WHERE request_type = 'flexo_operator_request' AND job_id IN ($ph)
              GROUP BY job_id
           ) lr ON lr.max_id = r.id";
  $lStmt = $db->prepare($lSql);
  if ($lStmt) {
    $lStmt->bind_param($types, ...$jobIds);
    $lStmt->execute();
    $lRows = $lStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($lRows as $lr) {
      $jid = (int)($lr['job_id'] ?? 0);
      if ($jid <= 0) continue;
      $lr['payload'] = json_decode((string)($lr['payload_json'] ?? '{}'), true) ?: [];
      $latestMap[$jid] = $lr;
    }
  }

  foreach ($jobs as &$j) {
    $jid = (int)($j['id'] ?? 0);
    $j['flexo_pending_request_count'] = (int)($pendingMap[$jid] ?? 0);
    $latest = $latestMap[$jid] ?? null;
    $j['flexo_latest_request_id'] = (int)($latest['id'] ?? 0);
    $j['flexo_latest_request_status'] = (string)($latest['status'] ?? '');
    $j['flexo_latest_request_note'] = (string)($latest['review_note'] ?? '');
    $j['flexo_latest_request_requested_by'] = (string)($latest['requested_by_name'] ?? '');
    $j['flexo_latest_request_requested_at'] = (string)($latest['requested_at'] ?? '');
    $j['flexo_latest_request_reviewed_by'] = (string)($latest['reviewed_by_name'] ?? '');
    $j['flexo_latest_request_reviewed_at'] = (string)($latest['reviewed_at'] ?? '');
    $j['flexo_latest_request_payload'] = is_array($latest['payload'] ?? null) ? $latest['payload'] : [];
  }
  unset($j);
}

// Map plate-data image by plate number and attach preview URLs for all printing job cards.
$plateImageByPlateNo = [];
$plateRes = @$db->query("SELECT plate, image_path FROM master_plate_data");
if ($plateRes instanceof mysqli_result) {
  while ($row = $plateRes->fetch_assoc()) {
    $plateNo = trim((string)($row['plate'] ?? ''));
    $imgPath = trim((string)($row['image_path'] ?? ''));
    if ($plateNo === '' || $imgPath === '') continue;
    if (!preg_match('/^https?:\/\//i', $imgPath)) {
      $imgPath = BASE_URL . '/' . ltrim(str_replace('\\', '/', $imgPath), '/');
    }
    if (!isset($plateImageByPlateNo[$plateNo])) {
      $plateImageByPlateNo[$plateNo] = $imgPath;
    }
  }
  $plateRes->close();
}

foreach ($jobs as &$j) {
  $plateNo = trim((string)($j['planning_plate_no'] ?? ''));
  $plateImageUrl = ($plateNo !== '' && isset($plateImageByPlateNo[$plateNo])) ? (string)$plateImageByPlateNo[$plateNo] : '';
  $j['plate_image_url'] = $plateImageUrl;
  $j['job_preview_image_url'] = trim((string)($j['planning_image_url'] ?? '')) !== ''
    ? (string)$j['planning_image_url']
    : $plateImageUrl;
}
unset($j);

// Fetch Anilox LPI stock (for Color + Anilox dropdown and quantity checks)
$aniloxStockMap = [];
foreach (['master_anilox_data', 'anilox_data'] as $aniloxTable) {
  if (!jobs_printing_table_exists($db, $aniloxTable)) continue;
  $aniloxRes = @$db->query("SELECT anilox_lpi, stock_qty FROM {$aniloxTable}");
  if (!($aniloxRes instanceof mysqli_result)) continue;
  while ($row = $aniloxRes->fetch_assoc()) {
    $lpi = trim((string)($row['anilox_lpi'] ?? ''));
    if ($lpi === '') continue;
    $qtyRaw = trim((string)($row['stock_qty'] ?? '0'));
    $qty = 0;
    if (preg_match('/-?\d+(?:\.\d+)?/', $qtyRaw, $m)) {
      $qty = max(0, (int)floor((float)$m[0]));
    }
    if (!isset($aniloxStockMap[$lpi])) $aniloxStockMap[$lpi] = 0;
    $aniloxStockMap[$lpi] += $qty;
  }
  $aniloxRes->close();
}

$aniloxLpiOptions = array_keys($aniloxStockMap);
usort($aniloxLpiOptions, static function(string $a, string $b): int {
  $na = is_numeric($a) ? (float)$a : null;
  $nb = is_numeric($b) ? (float)$b : null;
  if ($na !== null && $nb !== null) return $na <=> $nb;
  if ($na !== null) return -1;
  if ($nb !== null) return 1;
  return strcasecmp($a, $b);
});

// ─── Fetch child rolls from previous slitting jobs for each printing job ───
$prevJobIds = array_filter(array_unique(array_map(fn($j) => (int)($j['previous_job_id'] ?? 0), $jobs)));
$prevJobExtraMap = []; // prev_job_id => extra_data parsed
if (!empty($prevJobIds)) {
    $ph = implode(',', array_fill(0, count($prevJobIds), '?'));
    $types = str_repeat('i', count($prevJobIds));
    $pStmt = $db->prepare("SELECT id, extra_data FROM jobs WHERE id IN ($ph)");
    $pStmt->bind_param($types, ...$prevJobIds);
    $pStmt->execute();
    $pRows = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($pRows as $pr) {
        $prevJobExtraMap[(int)$pr['id']] = json_decode($pr['extra_data'] ?? '{}', true) ?: [];
    }
}

// Collect all assigned child roll_nos from slitting jobs or direct-printing payloads
$allChildRollNos = [];
foreach ($jobs as &$j) {
    $prevId = (int)($j['previous_job_id'] ?? 0);
    $prevExtra = $prevJobExtraMap[$prevId] ?? [];
  $childRolls = is_array($prevExtra['child_rolls'] ?? null) ? $prevExtra['child_rolls'] : [];
  $directAssignedRolls = is_array($j['extra_data_parsed']['assigned_child_rolls'] ?? null) ? $j['extra_data_parsed']['assigned_child_rolls'] : [];
  $childRolls = array_merge($childRolls, $directAssignedRolls);
    foreach ($childRolls as $cr) {
        $rn = trim((string)($cr['roll_no'] ?? ''));
        if ($rn !== '') $allChildRollNos[$rn] = true;
    }
}
unset($j);

// Fetch paper_stock data for all child rolls
$childRollStockMap = []; // roll_no => paper_stock data
if (!empty($allChildRollNos)) {
    $rollList = array_keys($allChildRollNos);
    $ph = implode(',', array_fill(0, count($rollList), '?'));
    $types = str_repeat('s', count($rollList));
    $psStmt = $db->prepare("SELECT roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, status FROM paper_stock WHERE roll_no IN ($ph)");
    $psStmt->bind_param($types, ...$rollList);
    $psStmt->execute();
    $psRows = $psStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($psRows as $ps) {
        $childRollStockMap[$ps['roll_no']] = $ps;
    }
}

// Attach slitting_rolls array to each printing job
foreach ($jobs as &$j) {
    $prevId = (int)($j['previous_job_id'] ?? 0);
    $prevExtra = $prevJobExtraMap[$prevId] ?? [];
  $childRolls = is_array($prevExtra['child_rolls'] ?? null) ? $prevExtra['child_rolls'] : [];
  $directAssignedRolls = is_array($j['extra_data_parsed']['assigned_child_rolls'] ?? null) ? $j['extra_data_parsed']['assigned_child_rolls'] : [];
  if (!empty($directAssignedRolls)) {
    $childRolls = array_merge($childRolls, $directAssignedRolls);
  }
  $childRollMap = [];
  foreach ($childRolls as $cr) {
    if (!is_array($cr)) continue;
    $rn = trim((string)($cr['roll_no'] ?? ''));
    if ($rn === '') continue;
    $childRollMap[$rn] = $cr;
  }
  $childRolls = array_values($childRollMap);
    $slittingRolls = [];
    foreach ($childRolls as $cr) {
        $rn = trim((string)($cr['roll_no'] ?? ''));
        if ($rn === '') continue;
        $stock = $childRollStockMap[$rn] ?? [];
        $slittingRolls[] = [
            'roll_no' => $rn,
            'parent_roll_no' => trim((string)($cr['parent_roll_no'] ?? '')),
            'company' => $stock['company'] ?? '',
            'paper_type' => $stock['paper_type'] ?? '',
      'width_mm' => (float)($stock['width_mm'] ?? $cr['width_mm'] ?? $cr['width'] ?? 0),
      'length_mtr' => (float)($stock['length_mtr'] ?? $cr['length_mtr'] ?? $cr['length'] ?? 0),
      'gsm' => (float)($stock['gsm'] ?? $cr['gsm'] ?? 0),
      'status' => $stock['status'] ?? ($cr['status'] ?? ''),
        ];
    }
    $j['slitting_rolls'] = $slittingRolls;
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
$requestTabCount = count(array_filter($jobs, fn($j) => $j['status'] === 'Running' && (int)($j['flexo_pending_request_count'] ?? 0) > 0));

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
.fp-card.fp-card-waiting-slitting{border-left-color:#f59e0b}
.fp-card.fp-card-waiting-slitting .fp-card-head{background:linear-gradient(135deg,#fffbeb,#fff)}
.fp-card-head{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border,#e2e8f0);background:linear-gradient(135deg,#faf5ff,#fff)}
.fp-card-head .fp-jobno{font-weight:900;font-size:.85rem;color:#0f172a;display:flex;align-items:center;gap:8px}
.fp-card-head .fp-jobno i{color:var(--fp-brand);font-size:1rem}
.fp-card-body{padding:14px 18px}
.fp-card-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:.78rem}
.fp-card-row .fp-label{color:#94a3b8;font-weight:700;font-size:.65rem;text-transform:uppercase;letter-spacing:.03em}
.fp-card-row .fp-value{font-weight:700;color:#1e293b}
.fp-job-name{font-size:1.1rem;line-height:1.25;font-weight:900;color:#0f172a}
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
.fp-badge-request-alert{background:#fee2e2;color:#991b1b;border:1px solid #ef4444;animation:fp-request-blink 1.1s ease-in-out infinite}
.fp-badge-waiting{background:#ffedd5;color:#9a3412;border:1px solid #fdba74}
@keyframes pulse-fp{0%,100%{opacity:1}50%{opacity:.6}}
@keyframes fp-request-blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(1.06)}}
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
.fp-wait-info{font-size:.62rem;color:#9a3412;font-weight:700;display:flex;align-items:flex-start;gap:6px;background:#fffbeb;border:1px solid #fde68a;padding:6px 8px;border-radius:8px;line-height:1.35}

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
.fp-req-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.fp-req-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.fp-req-meta{font-size:.72rem;color:#475569;font-weight:700}
.fp-req-note{font-size:.74rem;color:#64748b;line-height:1.45}
.fp-req-diff{font-size:.78rem;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;padding:6px 8px;font-weight:800}
.fp-req-chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:.62rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em}
.fp-req-chip.pending{background:#fef3c7;color:#92400e}
.fp-req-chip.approved{background:#dcfce7;color:#166534}
.fp-req-chip.rejected{background:#fee2e2;color:#991b1b}
.fp-req-attn{border:1px solid #fecaca;background:#fff1f2 !important}
.fp-req-attn .fp-op-h{background:#ef4444;color:#fff}
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
.fp-op-roll-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;width:100%}
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
.fp-btn-reject{background:#ef4444;color:#fff}
.fp-btn-reject:hover{background:#dc2626}

.fp-tabs{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.fp-tab-btn{padding:7px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:999px;cursor:pointer;color:#64748b;transition:all .15s}
.fp-tab-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.fp-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:.62rem;margin-left:6px}
.fp-tab-btn.active .fp-tab-count{background:rgba(255,255,255,.2);color:#fff}
.fp-filter-request-badge{display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 8px;border-radius:999px;background:#ef4444;color:#fff;font-size:.72rem;font-weight:900;margin-left:8px;box-shadow:0 0 0 3px rgba(239,68,68,.2);animation:fp-request-blink 1.1s ease-in-out infinite}
.fp-card-request-alert{border-left-color:#ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.22), 0 8px 20px rgba(239,68,68,.16);animation:fp-request-card-pulse 1.25s ease-in-out infinite}
.fp-card-request-alert .fp-card-head{background:linear-gradient(135deg,#fff1f2,#fff)}
@keyframes fp-request-card-pulse{0%,100%{box-shadow:0 0 0 2px rgba(239,68,68,.22), 0 8px 20px rgba(239,68,68,.16)}50%{box-shadow:0 0 0 4px rgba(239,68,68,.34), 0 12px 28px rgba(239,68,68,.24)}}
.fp-card-check{width:16px;height:16px;cursor:pointer;accent-color:var(--fp-brand);flex-shrink:0;margin-right:2px}
@media print{.no-print,.breadcrumb,.page-header,.fp-modal-overlay{display:none!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
@media(max-width:900px){.fp-op-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.fp-op-lanes{grid-template-columns:repeat(4,minmax(72px,1fr))}.fp-op-topstrip{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.fp-grid{grid-template-columns:1fr}.fp-stats{grid-template-columns:repeat(2,1fr)}.fp-detail-grid{grid-template-columns:1fr}.fp-form-row{grid-template-columns:1fr}.fp-op-grid-2,.fp-op-grid-3,.fp-op-grid-4{grid-template-columns:1fr}.fp-op-lanes{grid-template-columns:repeat(2,minmax(90px,1fr))}}

/* Timer overlay */
.fp-timer-overlay{position:fixed;inset:0;z-index:20000;background:rgba(15,23,42,.85);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:24px;backdrop-filter:blur(4px)}
.fp-timer-display{font-size:4rem;font-weight:900;font-variant-numeric:tabular-nums;color:#fff;letter-spacing:.04em;text-shadow:0 2px 12px rgba(0,0,0,.3)}
.fp-timer-jobinfo{color:rgba(255,255,255,.7);font-size:1rem;text-align:center;font-weight:600}
.fp-timer-actions{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;max-width:min(92vw,760px)}
.fp-timer-actions button{padding:12px 32px;font-size:.95rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;border:none;border-radius:999px;cursor:pointer;transition:all .15s;justify-content:center;flex:1 1 180px}
.fp-timer-btn-cancel{background:#64748b;color:#fff}
.fp-timer-btn-cancel:hover{background:#475569}
.fp-timer-btn-pause{background:#f59e0b;color:#fff}
.fp-timer-btn-pause:hover{background:#d97706}
.fp-timer-btn-end{background:#16a34a;color:#fff}
.fp-timer-btn-end:hover{background:#15803d}
.fp-timer-history{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
.fp-timer-history-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px}
.fp-timer-history-card h4{margin:0 0 8px;font-size:.74rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.fp-timer-history-list{display:grid;gap:8px}
.fp-timer-history-row{display:grid;grid-template-columns:96px 1fr;gap:10px;padding:8px 10px;border-radius:10px;background:#f8fafc;align-items:start}
.fp-timer-history-row.work{background:#f0fdf4}
.fp-timer-history-row.pause{background:#fff7ed}
.fp-timer-history-row .k{font-size:.68rem;font-weight:900;letter-spacing:.03em;text-transform:uppercase;color:#64748b}
.fp-timer-history-row .v{font-size:.82rem;font-weight:700;color:#0f172a;line-height:1.45}
.fp-timer-history-empty{font-size:.78rem;color:#94a3b8;font-weight:700}
@media(max-width:600px){.fp-timer-overlay{padding:18px 14px;gap:18px}.fp-timer-jobinfo{font-size:.92rem;padding:0 8px}.fp-timer-display{font-size:2.4rem;line-height:1.1;text-align:center}.fp-timer-actions{width:100%;gap:10px}.fp-timer-actions button{width:100%;flex:1 1 100%;padding:13px 16px}.fp-timer-history{grid-template-columns:1fr}.fp-timer-history-row{grid-template-columns:1fr}}
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
$runningRequestJobs = count(array_filter($jobs, fn($j) => $j['status'] === 'Running' && (int)($j['flexo_pending_request_count'] ?? 0) > 0));
$waitingSlittingJobs = count(array_filter($jobs, static function($j) {
  $extra = is_array($j['extra_data_parsed'] ?? null) ? $j['extra_data_parsed'] : [];
  $flag = !empty($extra['flexo_waiting_additional_slitting']);
  $pendingTask = false;
  $tasks = is_array($extra['flexo_extension_tasks'] ?? null) ? $extra['flexo_extension_tasks'] : [];
  foreach ($tasks as $t) {
    if (!is_array($t)) continue;
    $type = strtolower(trim((string)($t['type'] ?? '')));
    $status = strtolower(trim((string)($t['status'] ?? '')));
    if ($type === 'additional_slitting' && $status === 'pending') {
      $pendingTask = true;
      break;
    }
  }
  return (string)($j['status'] ?? '') === 'Pending' && ($flag || $pendingTask);
}));
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
  <div class="fp-stat" style="cursor:pointer" onclick="clickStatFilter('Request')">
    <div class="fp-stat-icon" style="background:#fee2e2;color:#dc2626"><i class="bi bi-bell-fill"></i></div>
    <div><div class="fp-stat-val"><?= $requestTabCount ?></div><div class="fp-stat-label">Request</div></div>
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
  <button type="button" class="fp-filter-btn" data-filter="all" onclick="filterFP('all',this)">All</button>
  <button type="button" class="fp-filter-btn" data-filter="Queued" onclick="filterFP('Queued',this)">Queued</button>
  <button type="button" class="fp-filter-btn active" data-filter="Pending" onclick="filterFP('Pending',this)">Pending</button>
  <button type="button" class="fp-filter-btn" data-filter="WaitingSlitting" onclick="filterFP('WaitingSlitting',this)">Waiting Slitting<?php if ($waitingSlittingJobs > 0): ?> <span class="fp-filter-request-badge" style="background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.22);animation:none" title="Jobs waiting for additional slitting"><?= (int)$waitingSlittingJobs ?></span><?php endif; ?></button>
  <button type="button" class="fp-filter-btn" data-filter="Running" onclick="filterFP('Running',this)">Running<?php if (!$isOperatorView && $runningRequestJobs > 0): ?> <span class="fp-filter-request-badge" title="Running jobs with pending request"><?= (int)$runningRequestJobs ?></span><?php endif; ?></button>
  <button type="button" class="fp-filter-btn" data-filter="Request" onclick="filterFP('Request',this)">Request<?php if (!$isOperatorView && $requestTabCount > 0): ?> <span class="fp-filter-request-badge" title="Pending request jobs"><?= (int)$requestTabCount ?></span><?php endif; ?></button>
  <button type="button" class="fp-filter-btn" data-filter="Completed" onclick="filterFP('Completed',this)">Completed</button>
  <button type="button" id="fpPrintSelBtn" onclick="printSelectedJobs()" style="display:none;padding:6px 14px;font-size:.7rem;font-weight:800;text-transform:uppercase;border:none;background:var(--fp-brand);color:#fff;border-radius:20px;cursor:pointer;align-items:center;gap:6px;letter-spacing:.04em"><i class="bi bi-printer-fill"></i> Print Selected (<span id="fpSelCount">0</span>)</button>
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
    $pendingReqCount = (int)($job['flexo_pending_request_count'] ?? 0);
    $hasPendingFlexoReq = $sts === 'Running' && $pendingReqCount > 0;
    $timerActive = !empty($job['extra_data_parsed']['timer_active']);
    $timerState = strtolower(trim((string)($job['extra_data_parsed']['timer_state'] ?? '')));
    $pri = $job['planning_priority'] ?? 'Normal';
    $priClass = match(strtolower($pri)) { 'urgent'=>'urgent', 'high'=>'high', default=>'normal' };
    $createdAt = $job['created_at'] ? date('d M Y, H:i', strtotime($job['created_at'])) : '—';
    $startedTs = $job['started_at'] ? strtotime($job['started_at']) * 1000 : 0;
    $resumedTs = !empty($job['extra_data_parsed']['timer_last_resumed_at']) ? (strtotime($job['extra_data_parsed']['timer_last_resumed_at']) * 1000) : $startedTs;
    $baseSeconds = (int)round((float)($job['extra_data_parsed']['timer_accumulated_seconds'] ?? 0));
    $dur = $job['duration_minutes'] ?? null;
    $searchText = strtolower($job['job_no'] . ' ' . ($job['roll_no'] ?? '') . ' ' . ($job['company'] ?? '') . ' ' . ($job['planning_job_name'] ?? ''));
    // Sequencing gate: can only start if previous slitting job is finished
    $prevDone = true;
    if ($job['previous_job_id'] && $job['prev_job_status'] && !in_array($job['prev_job_status'], ['Completed','QC Passed','Closed','Finalized'])) {
        $prevDone = false;
    }
    $isWaitingAdditionalSlitting = !empty($job['extra_data_parsed']['flexo_waiting_additional_slitting']);
    $waitingSlittingReason = trim((string)($job['extra_data_parsed']['flexo_waiting_additional_slitting_reason'] ?? ''));
    $isQueued = ($sts === 'Queued');
  ?>
  <div class="fp-card <?= $isQueued ? 'fp-queued' : '' ?> <?= $hasPendingFlexoReq ? 'fp-card-request-alert' : '' ?> <?= $isWaitingAdditionalSlitting ? 'fp-card-waiting-slitting' : '' ?>" data-status="<?= e($sts) ?>" data-has-request="<?= $hasPendingFlexoReq ? '1' : '0' ?>" data-waiting-slitting="<?= $isWaitingAdditionalSlitting ? '1' : '0' ?>" data-lockstate="<?= $prevDone ? 'unlocked' : 'locked' ?>" data-search="<?= e($searchText) ?>" data-id="<?= $job['id'] ?>" onclick="openPrintDetail(<?= $job['id'] ?>)">
    <div class="fp-card-head">
      <div class="fp-jobno"><i class="bi bi-printer-fill"></i> <?= e($job['job_no']) ?></div>
      <div style="display:flex;gap:6px;align-items:center">
        <span class="fp-badge fp-badge-<?= $stsClass ?>"><?= e($sts) ?></span>
        <?php if ($hasPendingFlexoReq): ?>
          <span class="fp-badge fp-badge-request-alert" title="Pending flexo operator request">REQUEST <?= $pendingReqCount ?></span>
        <?php endif; ?>
        <?php if ($isWaitingAdditionalSlitting): ?>
          <span class="fp-badge fp-badge-waiting" title="Awaiting additional slitting completion">Waiting Slitting</span>
        <?php endif; ?>
        <?php if ($pri !== 'Normal'): ?>
          <span class="fp-badge fp-badge-<?= $priClass ?>"><?= e($pri) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="fp-card-body">
      <?php if ($job['planning_job_name']): ?>
      <div class="fp-card-row"><span class="fp-label">Job Name</span><span class="fp-value fp-job-name"><?= e($job['planning_job_name']) ?></span></div>
      <?php endif; ?>
      <div class="fp-card-row"><span class="fp-label">Roll No</span><span class="fp-value" style="color:var(--fp-brand)"><?php
        $slRolls = $job['slitting_rolls'] ?? [];
        if (!empty($slRolls)) {
          echo e(implode(', ', array_column($slRolls, 'roll_no')));
        } else {
          echo e($job['roll_no'] ?? '—');
        }
      ?></span></div>
      <div class="fp-card-row"><span class="fp-label">Material</span><span class="fp-value"><?= e(($job['company'] ?? '—') . ' / ' . ($job['paper_type'] ?? '—')) ?></span></div>
      <?php if (!empty($slRolls)): ?>
      <?php foreach ($slRolls as $sr): ?>
      <div class="fp-card-row"><span class="fp-label"><?= e($sr['roll_no']) ?></span><span class="fp-value" style="font-size:.72rem"><?= e($sr['width_mm'] . 'mm × ' . $sr['length_mtr'] . 'm') ?></span></div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="fp-card-row"><span class="fp-label">Dimension</span><span class="fp-value"><?= e(($job['width_mm'] ?? '—') . 'mm × ' . ($job['length_mtr'] ?? '—') . 'm') ?></span></div>
      <?php endif; ?>
      <?php if ($isQueued || !$prevDone): ?>
      <div class="fp-card-row">
        <span class="fp-gate-info"><i class="bi bi-lock-fill"></i> Waiting for slitting: <?= e($job['prev_job_no'] ?? '—') ?> (<?= e($job['prev_job_status'] ?? '—') ?>)</span>
      </div>
      <?php endif; ?>
      <?php if ($isWaitingAdditionalSlitting): ?>
      <div class="fp-card-row">
        <span class="fp-wait-info"><i class="bi bi-hourglass-split"></i> Waiting for additional slitting<?= $waitingSlittingReason !== '' ? ': ' . e($waitingSlittingReason) : '' ?></span>
      </div>
      <?php endif; ?>
      <?php if ($sts === 'Running' && $resumedTs && $timerState !== 'paused'): ?>
      <div class="fp-card-row"><span class="fp-label">Elapsed</span><span class="fp-timer" data-base-seconds="<?= $baseSeconds ?>" data-resumed-at="<?= $resumedTs ?>">00:00:00</span></div>
      <?php elseif ($dur !== null): ?>
      <div class="fp-card-row"><span class="fp-label">Duration</span><span class="fp-value"><?= floor($dur/60) ?>h <?= $dur%60 ?>m</span></div>
      <?php endif; ?>
    </div>
    <div class="fp-card-foot">
      <div class="fp-time"><i class="bi bi-clock"></i> <?= $createdAt ?></div>
      <div style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
        <?php if ($sts === 'Pending' && $prevDone): ?>
          <?php if ($isOperatorView): ?>
            <?php if ($isWaitingAdditionalSlitting): ?>
              <button class="fp-action-btn fp-btn-start" disabled title="Complete additional slitting task first"><i class="bi bi-hourglass-split"></i> Waiting Slitting</button>
            <?php else: ?>
              <button class="fp-action-btn fp-btn-start" onclick="startJobWithTimer(<?= $job['id'] ?>)"><i class="bi bi-play-fill"></i> Start</button>
            <?php endif; ?>
          <?php else: ?>
            <button class="fp-action-btn fp-btn-view" onclick="openPrintDetail(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-eye"></i> Open</button>
          <?php endif; ?>
        <?php elseif ($sts === 'Pending' && !$prevDone): ?>
          <button class="fp-action-btn fp-btn-start" disabled title="Slitting job must complete first"><i class="bi bi-lock-fill"></i> Locked</button>
        <?php elseif ($sts === 'Running'): ?>
          <?php if ($isOperatorView): ?>
            <?php if ($timerState === 'paused'): ?>
              <button class="fp-action-btn fp-btn-start" <?= $hasPendingFlexoReq ? 'disabled title="Request is active. Wait for review."' : '' ?> onclick="startJobWithTimer(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-play-circle"></i> Again Start</button>
            <?php elseif ($timerActive): ?>
              <button class="fp-action-btn fp-btn-start" onclick="resumeRunningPrintTimer(<?= $job['id'] ?>);event.stopPropagation()"><i class="bi bi-play-circle"></i> Open Timer</button>
            <?php else: ?>
              <button class="fp-action-btn fp-btn-complete" onclick="openPrintDetail(<?= $job['id'] ?>,'complete');event.stopPropagation()"><i class="bi bi-check-lg"></i> Complete</button>
            <?php endif; ?>
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

<style>
/* ── History Table Styles ── */
.ht-filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.ht-search{padding:8px 14px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.82rem;min-width:200px;outline:none;transition:border .15s}
.ht-search:focus{border-color:var(--fp-brand)}
.ht-date-input{padding:7px 12px;border:1px solid var(--border,#e2e8f0);border-radius:10px;font-size:.76rem;outline:none}
.ht-date-input:focus{border-color:var(--fp-brand)}
.ht-period-btn{padding:5px 13px;font-size:.66rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:20px;cursor:pointer;transition:all .15s;color:#64748b}
.ht-period-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}
.ht-label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.03em}
.ht-bulk-bar{display:none;background:linear-gradient(135deg,#1e40af,#1e3a8a);color:#fff;border-radius:12px;padding:12px 20px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 4px 16px rgba(30,64,175,.25)}
.ht-bulk-bar.visible{display:flex}
.ht-bulk-btn{padding:5px 13px;border-radius:8px;font-weight:700;font-size:.7rem;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.12);color:#fff}
.ht-bulk-btn:hover{background:rgba(255,255,255,.22)}
.ht-bulk-print{padding:7px 16px;background:var(--fp-brand);color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(var(--fp-brand),.3)}
.ht-bulk-print:hover{opacity:.9}
.ht-bulk-delete{padding:7px 16px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:800;font-size:.74rem;cursor:pointer;display:flex;align-items:center;gap:5px;box-shadow:0 2px 8px rgba(220,38,38,.3)}
.ht-bulk-delete:hover{background:#b91c1c}
.ht-table-wrap{background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:14px;overflow:hidden}
.ht-table{width:100%;border-collapse:collapse;font-size:.78rem}
.ht-table thead{background:linear-gradient(135deg,#f8fafc,#f1f5f9);position:sticky;top:0;z-index:2}
.ht-table th{padding:11px 13px;font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#64748b;text-align:left;border-bottom:2px solid #e2e8f0;white-space:nowrap;cursor:pointer;user-select:none}
.ht-table th:hover{color:#0f172a}
.ht-table th .ht-sort{margin-left:3px;font-size:.52rem;opacity:.4}
.ht-table th.sorted .ht-sort{opacity:1;color:var(--fp-brand)}
.ht-table td{padding:9px 13px;border-bottom:1px solid #f1f5f9;color:#1e293b;font-weight:600;vertical-align:middle}
.ht-table tbody tr{transition:background .1s;cursor:pointer}
.ht-table tbody tr:hover{background:#fdf4ff}
.ht-table tbody tr.ht-selected{background:#fdf4ff;outline:2px solid var(--fp-brand);outline-offset:-2px}
.ht-table .ht-cb-cell{width:34px;text-align:center}
.ht-table .ht-cb-cell input{width:16px;height:16px;accent-color:var(--fp-brand);cursor:pointer}
.ht-jobno{font-weight:900;color:#0f172a;font-size:.8rem}
.ht-jobname{font-size:.88rem;font-weight:900;color:#0f172a}
.ht-dim{color:#94a3b8;font-size:.72rem}
.ht-badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.56rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.ht-badge-completed{background:#dcfce7;color:#166534}
.ht-badge-closed{background:#dcfce7;color:#166534}
.ht-badge-finalized{background:#dbeafe;color:#1e40af}
.ht-badge-qcpassed{background:#d1fae5;color:#065f46}
.ht-badge-default{background:#f1f5f9;color:#64748b}
.ht-act-btn{padding:4px 9px;font-size:.58rem;font-weight:800;text-transform:uppercase;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:6px;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:3px;color:#475569}
.ht-act-btn:hover{background:#f1f5f9}
.ht-act-btn.ht-print{color:#8b5cf6;border-color:#c4b5fd}
.ht-act-btn.ht-print:hover{background:#f5f3ff}
.ht-act-btn.ht-delete{color:#dc2626;border-color:#fecaca}
.ht-act-btn.ht-delete:hover{background:#fee2e2}
.ht-pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;flex-wrap:wrap;gap:10px}
.ht-page-info{font-size:.7rem;color:#64748b;font-weight:600}
.ht-page-btns{display:flex;gap:4px}
.ht-page-btn{padding:5px 11px;border:1px solid var(--border,#e2e8f0);background:#fff;border-radius:8px;font-size:.7rem;font-weight:700;cursor:pointer;color:#475569;transition:all .12s}
.ht-page-btn:hover{background:#f1f5f9}
.ht-page-btn.active{background:var(--fp-brand);color:#fff;border-color:var(--fp-brand)}
.ht-page-btn:disabled{opacity:.4;cursor:not-allowed}
.ht-per-page{padding:5px 10px;border:1px solid var(--border,#e2e8f0);border-radius:8px;font-size:.7rem;outline:none}
@media(max-width:768px){.ht-table-wrap{overflow-x:auto}}
</style>

<!-- History Filter Bar -->
<div class="ht-filter-bar no-print" style="margin-top:14px">
  <input type="text" class="ht-search" id="htSearch" placeholder="Search job no, roll, company, material&hellip;">
  <span class="ht-label">Period:</span>
  <button type="button" class="ht-period-btn active" onclick="htSetPeriod('all',this)">All</button>
  <button type="button" class="ht-period-btn" onclick="htSetPeriod('today',this)">Today</button>
  <button type="button" class="ht-period-btn" onclick="htSetPeriod('week',this)">Week</button>
  <button type="button" class="ht-period-btn" onclick="htSetPeriod('month',this)">Month</button>
  <button type="button" class="ht-period-btn" onclick="htSetPeriod('year',this)">Year</button>
  <span class="ht-label" style="margin-left:4px">Custom:</span>
  <input type="date" class="ht-date-input" id="htDateFrom" title="From date">
  <span style="color:#94a3b8;font-size:.7rem">to</span>
  <input type="date" class="ht-date-input" id="htDateTo" title="To date">
  <button type="button" class="ht-period-btn" onclick="htApplyCustomDate()" style="background:var(--fp-brand);color:#fff;border-color:var(--fp-brand)"><i class="bi bi-funnel"></i> Apply</button>
</div>

<!-- History Bulk Bar -->
<div class="ht-bulk-bar no-print" id="htBulkBar">
  <div style="display:flex;align-items:center;gap:10px">
    <i class="bi bi-check2-square" style="font-size:1.1rem"></i>
    <span style="font-weight:800;font-size:.82rem"><span id="htSelectedCount">0</span> Selected</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <button type="button" class="ht-bulk-btn" onclick="htSelectAllVisible()">Select All</button>
    <button type="button" class="ht-bulk-btn" onclick="htDeselectAll()">Deselect All</button>
    <button type="button" class="ht-bulk-print" onclick="htBulkPrint()"><i class="bi bi-printer-fill"></i> Print Selected</button>
    <?php if ($canDeleteJobs): ?>
    <button type="button" class="ht-bulk-delete" onclick="htBulkDelete()"><i class="bi bi-trash-fill"></i> Delete Selected</button>
    <?php endif; ?>
  </div>
</div>

<!-- History Table -->
<div class="ht-table-wrap">
  <table class="ht-table" id="htTable">
    <thead>
      <tr>
        <th class="ht-cb-cell no-print"><input type="checkbox" id="htCheckAll" onchange="htToggleAll(this.checked)" title="Select all"></th>
        <th onclick="htSortCol(0)">#<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(1)">Job No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(2)">Job Name<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(3)">Roll No<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(4)">Material<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(5)">Status<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(6)">Started<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(7)">Completed<span class="ht-sort">▲▼</span></th>
        <th onclick="htSortCol(8)">Duration<span class="ht-sort">▲▼</span></th>
        <th class="no-print">Actions</th>
      </tr>
    </thead>
    <tbody id="htBody">
    <?php if (empty($historyJobs)): ?>
      <tr><td colspan="11" style="padding:40px;text-align:center;color:#94a3b8"><i class="bi bi-inbox" style="font-size:2rem;opacity:.3"></i><br>No completed jobs yet</td></tr>
    <?php else: ?>
      <?php foreach ($historyJobs as $idx => $h):
        $hSts = $h['status'];
        $hStsLower = strtolower(str_replace(' ', '', $hSts));
        $hStsClass = match($hStsLower) { 'closed'=>'closed','finalized'=>'finalized','completed'=>'completed','qcpassed'=>'qcpassed', default=>'default' };
        $hDur = $h['duration_minutes'] ?? null;
        $hDurStr = ($hDur !== null) ? (floor($hDur/60).'h '.($hDur%60).'m') : '—';
        $hStarted = $h['started_at'] ? date('d M Y, H:i', strtotime($h['started_at'])) : '—';
        $hCompleted = $h['completed_at'] ? date('d M Y, H:i', strtotime($h['completed_at'])) : ($h['updated_at'] ? date('d M Y, H:i', strtotime($h['updated_at'])) : '—');
        $hSearch = strtolower(($h['job_no']??'').' '.($h['planning_job_name']??'').' '.($h['display_job_name']??'').' '.($h['roll_no']??'').' '.($h['paper_type']??'').' '.($h['company']??''));
      ?>
      <tr data-id="<?= (int)$h['id'] ?>"
          data-completed="<?= e($h['completed_at'] ?? $h['updated_at'] ?? $h['created_at'] ?? '') ?>"
          data-search="<?= e($hSearch) ?>"
          onclick="openPrintDetail(<?= (int)$h['id'] ?>)">
        <td class="ht-cb-cell no-print">
          <input type="checkbox" class="ht-row-cb" data-job-id="<?= (int)$h['id'] ?>" onclick="event.stopPropagation();htUpdateBulk()">
        </td>
        <td class="ht-dim"><?= $idx + 1 ?></td>
        <td><span class="ht-jobno"><?= e($h['job_no']) ?></span></td>
        <td><span class="ht-jobname"><?= e($h['planning_job_name'] ?? $h['display_job_name'] ?? '—') ?></span></td>
        <td style="color:var(--fp-brand);font-weight:800"><?= e($h['roll_no'] ?? '—') ?></td>
        <td><?= e($h['paper_type'] ?? '—') ?></td>
        <td><span class="ht-badge ht-badge-<?= $hStsClass ?>"><?= e($hSts) ?></span></td>
        <td class="ht-dim"><?= $hStarted ?></td>
        <td class="ht-dim"><?= $hCompleted ?></td>
        <td class="ht-dim"><?= $hDurStr ?></td>
        <td class="no-print" onclick="event.stopPropagation()">
          <button class="ht-act-btn" onclick="openPrintDetail(<?= (int)$h['id'] ?>)" title="View"><i class="bi bi-eye"></i></button>
          <button class="ht-act-btn ht-print" onclick="printJobCard(<?= (int)$h['id'] ?>)" title="Print"><i class="bi bi-printer"></i></button>
          <?php if ($canDeleteJobs): ?>
          <button class="ht-act-btn ht-delete" onclick="deleteJob(<?= (int)$h['id'] ?>)" title="Delete"><i class="bi bi-trash"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <div class="ht-pagination no-print" id="htPagination">
    <div class="ht-page-info" id="htPageInfo">Showing 0–0 of 0</div>
    <div style="display:flex;align-items:center;gap:10px">
      <select class="ht-per-page" id="htPerPage" onchange="htGoPage(1)">
        <option value="25">25 / page</option>
        <option value="50">50 / page</option>
        <option value="100">100 / page</option>
        <option value="all">Show All</option>
      </select>
      <div class="ht-page-btns" id="htPageBtns"></div>
    </div>
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
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const CSRF = '<?= e($csrf) ?>';
const API_BASE = '<?= BASE_URL ?>/modules/jobs/api.php';
const BASE_URL = '<?= BASE_URL ?>';
const IS_OPERATOR_VIEW = <?= $isOperatorView ? 'true' : 'false' ?>;
const CAN_MANUAL_ROLL_ENTRY = <?= $canManualRollEntry ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $canDeleteJobs ? 'true' : 'false' ?>;
const CAN_REVIEW_FLEXO_REQUESTS = <?= $canReviewFlexoRequests ? 'true' : 'false' ?>;
const CURRENT_USER = <?= json_encode($sessionUser, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const COMPANY = <?= json_encode(['name'=>$companyName,'address'=>$companyAddr,'gst'=>$companyGst,'logo'=>$logoUrl], JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ALL_JOBS = <?= json_encode($jobs, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ANILOX_LPI_STOCK = <?= json_encode($aniloxStockMap, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const ANILOX_LPI_OPTIONS = <?= json_encode($aniloxLpiOptions, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
let activeStatusFilter = 'Pending';

function getFieldVal(form, name) {
  const local = form ? form.querySelector('[name="' + name + '"]') : null;
  if (local) return String(local.value || '').trim();
  const formId = String(form?.id || '').trim();
  if (formId !== '') {
    const linked = document.querySelector('[name="' + name + '"][form="' + formId + '"]');
    if (linked) return String(linked.value || '').trim();
  }
  return '';
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
  const DEFAULT_COLORS = ['Cyan', 'Magenta', 'Yellow', 'Black', 'None', 'None', 'None', 'None'];
  const DEFAULT_ANILOX = ['None', 'None', 'None', 'None', 'None', 'None', 'None', 'None'];
  if (!Array.isArray(out.colour_lanes)) out.colour_lanes = DEFAULT_COLORS.slice();
  if (!Array.isArray(out.anilox_lanes)) out.anilox_lanes = DEFAULT_ANILOX.slice();
  out.colour_lanes = out.colour_lanes
    .slice(0, 8)
    .concat(Array(Math.max(0, 8 - out.colour_lanes.length)).fill(''))
    .map((v, i) => {
      const t = String(v || '').trim();
      return t !== '' ? t : DEFAULT_COLORS[i];
    });
  out.anilox_lanes = out.anilox_lanes
    .slice(0, 8)
    .concat(Array(Math.max(0, 8 - out.anilox_lanes.length)).fill(''))
    .map((v, i) => {
      const t = String(v || '').trim();
      return t !== '' ? t : DEFAULT_ANILOX[i];
    });

  const materialRows = Array.isArray(out.material_rows) ? out.material_rows : [];
  // Use slitting_rolls (child rolls from previous slitting job) when available
  const slittingRolls = Array.isArray(job.slitting_rolls) ? job.slitting_rolls : [];
  if (slittingRolls.length) {
    // Build from slitting child rolls, merging any saved per-roll data
    const savedByRoll = {};
    materialRows.forEach(mr => { if (mr.roll_no) savedByRoll[mr.roll_no] = mr; });
    const savedWastByRoll = {};
    (Array.isArray(out.roll_wastage_rows) ? out.roll_wastage_rows : []).forEach(wr => { if (wr.roll_no) savedWastByRoll[wr.roll_no] = wr; });
    out.material_rows = slittingRolls.map(sr => {
      const rn = String(sr.roll_no || '').trim();
      const saved = savedByRoll[rn] || {};
      return {
        roll_no: rn,
        parent_roll_no: String(sr.parent_roll_no || '').trim(),
        material_company: String(sr.company || out.material_company || '').trim(),
        material_name: String(sr.paper_type || out.material_name || '').trim(),
        slitted_width: sr.width_mm || '',
        slitted_length: sr.length_mtr || '',
        order_mtr: saved.order_mtr ?? out.order_mtr,
        order_qty: saved.order_qty ?? out.order_qty,
        color_match_status: String(saved.color_match_status || out.color_match_status || 'Matched').trim(),
        wastage_meters: saved.wastage_meters ?? out.wastage_meters ?? '',
      };
    });
    // Also rebuild roll_wastage_rows from slitting child rolls
    out.roll_wastage_rows = slittingRolls.map(sr => {
      const rn = String(sr.roll_no || '').trim();
      const sw = savedWastByRoll[rn] || {};
      return {
        roll_no: rn,
        parent_roll_no: String(sr.parent_roll_no || '').trim(),
        material_company: String(sr.company || out.material_company || '').trim(),
        material_name: String(sr.paper_type || out.material_name || '').trim(),
        slitted_width: sr.width_mm || '',
        slitted_length: sr.length_mtr || '',
        color_match_status: String(sw.color_match_status || out.color_match_status || 'Matched').trim(),
        wastage_meters: sw.wastage_meters ?? '',
      };
    });
  } else if (materialRows.length) {
    out.material_rows = materialRows;
  } else {
    out.material_rows = [{
      roll_no: String(job.roll_no || '').trim(),
      parent_roll_no: '',
      material_company: out.material_company,
      material_name: out.material_name,
      slitted_width: job.width_mm || '',
      slitted_length: job.length_mtr || '',
      order_mtr: out.order_mtr,
      order_qty: out.order_qty,
      color_match_status: String(out.color_match_status || 'Matched').trim(),
      wastage_meters: out.wastage_meters ?? '',
    }];
  }

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
      const colorVal = String(out.colour_lanes[i] || '').trim() || (i < 4 ? CMYK_DEFAULTS[i] : 'None');
      const anVal = String(out.anilox_lanes[i] || '').trim() || 'None';
      const colorName = ['Cyan','Magenta','Yellow','Black'].includes(colorVal) ? colorVal : (colorVal === 'None' ? 'None' : '');
      return {
        lane: i + 1,
        color_code: colorVal,
        color_name: colorName,
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
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : 0;
  }
  const raw = String(value == null ? '' : value).trim();
  if (!raw) return 0;
  const normalized = raw.replace(/,/g, '');
  const direct = Number(normalized);
  if (Number.isFinite(direct)) return direct;
  const match = normalized.match(/-?\d+(?:\.\d+)?/);
  if (!match) return 0;
  const parsed = Number(match[0]);
  return Number.isFinite(parsed) ? parsed : 0;
}

function normalizeAniloxLaneValue(value) {
  const raw = String(value || '').trim();
  if (!raw) return 'None';
  if (raw.toLowerCase() === 'none') return 'None';
  return raw;
}

function collectAniloxLaneSelections(form) {
  if (!form) return [];
  const rows = Array.from(form.querySelectorAll('[data-lane-row]'));
  return rows.map((row, idx) => {
    const sel = row.querySelector('[data-role="anilox-select"]');
    return {
      lane: idx + 1,
      value: normalizeAniloxLaneValue(sel?.value || 'None'),
      selectEl: sel || null,
    };
  });
}

function buildAniloxUsageCount(selections) {
  const used = {};
  selections.forEach(item => {
    const v = normalizeAniloxLaneValue(item?.value || 'None');
    if (v === 'None') return;
    used[v] = (used[v] || 0) + 1;
  });
  return used;
}

function getAniloxStockForValue(value) {
  const key = normalizeAniloxLaneValue(value);
  if (key === 'None') return Number.POSITIVE_INFINITY;
  return Math.max(0, Number(ANILOX_LPI_STOCK?.[key] || 0));
}

function renderAniloxOptions(selected) {
  const normalized = normalizeAniloxLaneValue(selected || 'None');
  let html = '<option value="None"' + (normalized === 'None' ? ' selected' : '') + '>None</option>';

  const optionSource = (Array.isArray(ANILOX_LPI_OPTIONS) && ANILOX_LPI_OPTIONS.length)
    ? ANILOX_LPI_OPTIONS
    : Object.keys(ANILOX_LPI_STOCK || {});

  optionSource.forEach(lpi => {
    const key = String(lpi || '').trim();
    if (!key) return;
    const qty = Math.max(0, Number(ANILOX_LPI_STOCK?.[key] || 0));
    const isSel = normalized === key;
    const outTag = qty <= 0 ? ' - Out of stock' : '';
    html += `<option value="${esc(key)}"${isSel ? ' selected' : ''}>${esc(key)} (Available: ${qty})${outTag}</option>`;
  });

  if (normalized !== 'None' && !Object.prototype.hasOwnProperty.call(ANILOX_LPI_STOCK || {}, normalized)) {
    html += `<option value="${esc(normalized)}" selected>${esc(normalized)} (Legacy)</option>`;
  }
  return html;
}

function updateAniloxLaneAvailability(form) {
  if (!form) return;
  // Keep all DB suggestions selectable in the UI.
  // Quantity/availability is still enforced by validateAniloxLaneQuantities() before submit.
  const selections = collectAniloxLaneSelections(form);
  selections.forEach(item => {
    const sel = item.selectEl;
    if (!sel) return;
    Array.from(sel.options).forEach(opt => {
      opt.disabled = false;
    });
  });
}

function validateAniloxLaneQuantities(form) {
  const selections = collectAniloxLaneSelections(form);
  const usage = buildAniloxUsageCount(selections);
  const missingInStock = [];
  const exceeded = [];

  Object.keys(usage).forEach(lpi => {
    const used = usage[lpi] || 0;
    const stock = getAniloxStockForValue(lpi);
    if (!Number.isFinite(stock)) return;
    if (stock <= 0) {
      missingInStock.push(lpi);
      return;
    }
    if (used > stock) {
      exceeded.push({ lpi, used, stock });
    }
  });

  if (!missingInStock.length && !exceeded.length) return true;

  let message = 'Anilox selection exceeds available stock. Please adjust before submit.\n\n';
  if (missingInStock.length) {
    message += 'Not available in Anilox Management:\n- ' + missingInStock.join('\n- ') + '\n\n';
  }
  if (exceeded.length) {
    message += 'Quantity mismatch:\n- ' + exceeded.map(e => `${e.lpi}: selected ${e.used}, available ${e.stock}`).join('\n- ');
  }
  erpToast(message.trim(), 'warning');
  return false;
}

function secondsToHms(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = String(Math.floor(sec / 3600)).padStart(2, '0');
  const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
  const s = String(sec % 60).padStart(2, '0');
  return `${h}:${m}:${s}`;
}

function formatDurationText(seconds) {
  const sec = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return s > 0 ? `${m}m ${s}s` : `${m}m`;
  return `${s}s`;
}

function formatDateTimeText(value) {
  const raw = String(value || '').trim();
  if (!raw) return '—';
  const dt = new Date(raw.replace(' ', 'T'));
  return Number.isFinite(dt.getTime()) ? dt.toLocaleString() : '—';
}

function erpToast(message, type) {
  const msg = String(message || '').trim();
  if (!msg) return;
  if (typeof window.showERPToast === 'function') {
    window.showERPToast(msg, type || 'info');
    return;
  }
  if (typeof window.erpCenterMessage === 'function') {
    window.erpCenterMessage(msg, { title: 'Notification' });
    return;
  }
  try { console.warn('[ERP toast fallback]', msg); } catch (_) {}
}

function erpConfirmAsync(message, options) {
  const opts = options || {};
  return new Promise(function(resolve) {
    if (typeof window.showERPConfirm === 'function') {
      window.showERPConfirm(String(message || ''), function() {
        resolve(true);
      }, {
        title: String(opts.title || 'Please Confirm'),
        okLabel: String(opts.okLabel || 'Confirm'),
        cancelLabel: String(opts.cancelLabel || 'Cancel'),
        onCancel: function() { resolve(false); }
      });
      return;
    }
    resolve(false);
  });
}

function erpPromptAsync(message, defaultValue, options) {
  const opts = options || {};
  return new Promise(function(resolve) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:25000;background:rgba(15,23,42,.52);display:flex;align-items:center;justify-content:center;padding:16px';
    overlay.innerHTML = `<div style="width:min(560px,96vw);background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 26px 60px rgba(2,6,23,.28);overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(90deg,#0f172a,#1e293b);color:#fff">
        <div style="font-size:15px;font-weight:700">${esc(opts.title || 'Input Required')}</div>
        <button type="button" id="erpPromptClose" style="border:0;background:rgba(255,255,255,.16);color:#fff;border-radius:8px;padding:5px 9px;cursor:pointer">X</button>
      </div>
      <div style="padding:16px">
        <div style="font-size:.86rem;color:#334155;line-height:1.5;white-space:pre-wrap;margin-bottom:10px">${esc(message || '')}</div>
        <input type="text" id="erpPromptInput" value="${esc(defaultValue || '')}" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:.85rem">
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#f8fafc;border-top:1px solid #e2e8f0">
        <button type="button" id="erpPromptCancel" class="fp-action-btn fp-btn-view">${esc(opts.cancelLabel || 'Cancel')}</button>
        <button type="button" id="erpPromptOk" class="fp-action-btn fp-btn-start">${esc(opts.okLabel || 'OK')}</button>
      </div>
    </div>`;
    document.body.appendChild(overlay);
    const input = overlay.querySelector('#erpPromptInput');
    if (input) {
      input.focus();
      input.select();
    }

    let done = false;
    function finish(val) {
      if (done) return;
      done = true;
      if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      resolve(val);
    }

    overlay.querySelector('#erpPromptOk')?.addEventListener('click', function() {
      finish(String(input?.value || ''));
    });
    overlay.querySelector('#erpPromptCancel')?.addEventListener('click', function() { finish(null); });
    overlay.querySelector('#erpPromptClose')?.addEventListener('click', function() { finish(null); });
    overlay.addEventListener('click', function(e) { if (e.target === overlay) finish(null); });
    overlay.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') { e.preventDefault(); finish(null); }
      if (e.key === 'Enter') { e.preventDefault(); finish(String(input?.value || '')); }
    });
  });
}

function printDurationSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const acc = Math.max(0, Math.floor(Number(extra.timer_accumulated_seconds || 0)));
  if (acc > 0) return acc;
  const mins = Number(job.duration_minutes || 0);
  return Number.isFinite(mins) && mins > 0 ? Math.floor(mins * 60) : 0;
}

function printPauseTotalSeconds(extra) {
  const segments = Array.isArray(extra?.timer_pause_segments) ? extra.timer_pause_segments : [];
  let total = segments.reduce((sum, row) => sum + Math.max(0, Number(row?.seconds || 0)), 0);
  const pausedAt = String(extra?.timer_pause_started_at || extra?.timer_paused_at || '').trim();
  const isPaused = String(extra?.timer_state || '').toLowerCase() === 'paused';
  if (isPaused && pausedAt) {
    const fromTs = Date.parse(pausedAt.replace(' ', 'T'));
    if (Number.isFinite(fromTs) && fromTs > 0) total += Math.max(0, Math.floor((Date.now() - fromTs) / 1000));
  }
  return total;
}

function pushPrintTimerEventLocal(extra, type, at) {
  extra.timer_events = Array.isArray(extra.timer_events) ? extra.timer_events : [];
  const last = extra.timer_events.length ? extra.timer_events[extra.timer_events.length - 1] : null;
  if (!last || String(last.type || '') !== type || String(last.at || '') !== at) {
    extra.timer_events.push({ type, at });
  }
}

function pushPrintTimerSegmentLocal(extra, key, from, to) {
  const fromTs = Date.parse(String(from || '').replace(' ', 'T'));
  const toTs = Date.parse(String(to || '').replace(' ', 'T'));
  if (!Number.isFinite(fromTs) || !Number.isFinite(toTs) || toTs <= fromTs) return;
  extra[key] = Array.isArray(extra[key]) ? extra[key] : [];
  extra[key].push({ from, to, seconds: Math.floor((toTs - fromTs) / 1000) });
}

function printLiveTimerAttrs(job) {
  const extra = job?.extra_data_parsed || {};
  const resumedRaw = extra.timer_last_resumed_at || extra.timer_started_at || job?.started_at || '';
  const resumedAt = resumedRaw ? Date.parse(String(resumedRaw).replace(' ', 'T')) : NaN;
  const baseSeconds = Math.max(0, Number(extra.timer_accumulated_seconds || 0));
  const resumedAttr = Number.isFinite(resumedAt) && resumedAt > 0 ? resumedAt : 0;
  return `data-base-seconds="${Math.floor(baseSeconds)}" data-resumed-at="${resumedAttr}"`;
}

function buildPrintTimerHistoryHtml(job) {
  const extra = job?.extra_data_parsed || {};
  const events = Array.isArray(extra.timer_events) ? extra.timer_events.filter(row => row && row.at) : [];
  const fallbackEvents = [];
  if (!events.length) {
    if (extra.timer_started_at || job?.started_at) fallbackEvents.push({ type: 'start', at: extra.timer_started_at || job.started_at });
    if (extra.timer_paused_at) fallbackEvents.push({ type: 'pause', at: extra.timer_paused_at });
    if (extra.timer_ended_at || job?.completed_at) fallbackEvents.push({ type: 'end', at: extra.timer_ended_at || job.completed_at });
  }
  const eventRows = (events.length ? events : fallbackEvents).sort((a, b) => Date.parse(String(a.at).replace(' ', 'T')) - Date.parse(String(b.at).replace(' ', 'T')));

  const segments = [];
  (Array.isArray(extra.timer_work_segments) ? extra.timer_work_segments : []).forEach(row => {
    if (row && row.from && row.to) segments.push({ kind: 'work', from: row.from, to: row.to, seconds: Number(row.seconds || 0) });
  });
  (Array.isArray(extra.timer_pause_segments) ? extra.timer_pause_segments : []).forEach(row => {
    if (row && row.from && row.to) segments.push({ kind: 'pause', from: row.from, to: row.to, seconds: Number(row.seconds || 0) });
  });
  if (!segments.length && (extra.timer_started_at || job?.started_at) && (extra.timer_ended_at || job?.completed_at)) {
    const from = extra.timer_started_at || job.started_at;
    const to = extra.timer_ended_at || job.completed_at;
    const fromTs = Date.parse(String(from).replace(' ', 'T'));
    const toTs = Date.parse(String(to).replace(' ', 'T'));
    if (Number.isFinite(fromTs) && Number.isFinite(toTs) && toTs > fromTs) {
      segments.push({ kind: 'work', from, to, seconds: Math.floor((toTs - fromTs) / 1000) });
    }
  }
  segments.sort((a, b) => Date.parse(String(a.from).replace(' ', 'T')) - Date.parse(String(b.from).replace(' ', 'T')));

  const timeOnly = (val) => {
    const d = new Date(String(val || '').replace(' ', 'T'));
    if (!Number.isFinite(d.getTime())) return '—';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  };

  const summaryBits = segments.map(row => `${timeOnly(row.from)}-${timeOnly(row.to)} ${row.kind === 'pause' ? 'paused' : 'worked'}`);
  const pauseTotalSeconds = segments
    .filter(row => row.kind === 'pause')
    .reduce((sum, row) => sum + Math.max(0, Number(row.seconds || 0)), 0);
  const summaryText = summaryBits.length ? summaryBits.join(', ') : 'No time ranges yet';

  const eventMap = { start: 'Start', resume: 'Again Start', pause: 'Pause', end: 'End' };
  const eventsHtml = eventRows.length
    ? eventRows.map(row => `<div class="fp-timer-history-row"><div class="k">${esc(eventMap[String(row.type || '').toLowerCase()] || 'Event')}</div><div class="v">${esc(formatDateTimeText(row.at))}</div></div>`).join('')
    : '<div class="fp-timer-history-empty">No timer event history yet.</div>';
  const segmentsHtml = segments.length
    ? segments.map(row => `<div class="fp-timer-history-row ${row.kind}"><div class="k">${row.kind === 'pause' ? 'Paused' : 'Worked'}</div><div class="v">${esc(formatDateTimeText(row.from))} - ${esc(formatDateTimeText(row.to))}<br><span style="color:#64748b;font-weight:800">${esc(formatDurationText(row.seconds))}</span></div></div>`).join('')
    : '<div class="fp-timer-history-empty">No work/pause ranges recorded yet.</div>';
  return `<div class="fp-timer-history">
    <div class="fp-timer-history-card"><h4>Event Log</h4><div class="fp-timer-history-list">${eventsHtml}</div></div>
    <div class="fp-timer-history-card"><h4>Work / Pause Range</h4><div style="font-size:.82rem;font-weight:800;color:#0f172a;line-height:1.55;margin-bottom:8px">${esc(summaryText)}</div><div style="font-size:.78rem;font-weight:900;color:#b45309;margin-bottom:10px">Total Pause: ${esc(formatDurationText(pauseTotalSeconds))}</div><div class="fp-timer-history-list">${segmentsHtml}</div></div>
  </div>`;
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
        const curVal = normalizeAniloxLaneValue(anSel.value || 'None');
        anSel.innerHTML = renderAniloxOptions(curVal);
        anSel.value = curVal;
      }
      if (anCustom) {
        anCustom.value = '';
        anCustom.style.display = 'none';
      }
      updateAniloxLaneAvailability(container);
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

function flexoRequestStatusClass(status) {
  const s = String(status || '').toLowerCase();
  if (s === 'approved') return 'approved';
  if (s === 'rejected') return 'rejected';
  return 'pending';
}

function flexoRequestDraftKey(jobId) {
  return 'fp_flexo_req_draft_' + String(jobId || '0');
}

function loadFlexoRequestDraft(jobId, payload) {
  let fromStorage = null;
  try {
    const raw = localStorage.getItem(flexoRequestDraftKey(jobId));
    if (raw) {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') fromStorage = parsed;
    }
  } catch (_) {}

  const add = (payload && typeof payload.additional_roll_request === 'object') ? payload.additional_roll_request : {};
  const ex = (payload && typeof payload.excess_roll_adjustment === 'object') ? payload.excess_roll_adjustment : {};

  return {
    request_mode: String(fromStorage?.request_mode || 'remaining').toLowerCase() === 'add' ? 'add' : 'remaining',
    source_roll_no: String(fromStorage?.source_roll_no || ex.source_roll_no || '').trim(),
    used_length_mtr: toSafeNumber(fromStorage?.used_length_mtr ?? ex.used_length_mtr ?? 0),
    remaining_length_mtr: toSafeNumber(fromStorage?.remaining_length_mtr ?? ex.remaining_length_mtr ?? 0),
    additional_width_mm: toSafeNumber(fromStorage?.additional_width_mm ?? add.width_mm ?? 0),
    additional_length_mtr: toSafeNumber(fromStorage?.additional_length_mtr ?? add.length_mtr ?? 0),
    additional_reason: String(fromStorage?.additional_reason || add.reason || '').trim(),
    operator_request_note: String(fromStorage?.operator_request_note || add.operator_note || ex.operator_note || '').trim(),
  };
}

function saveFlexoRequestDraft(jobId, form) {
  if (!jobId || !form) return;
  const draft = {
    request_mode: getFieldVal(form, 'fr_request_mode') || 'remaining',
    source_roll_no: getFieldVal(form, 'fr_source_roll_no'),
    used_length_mtr: toSafeNumber(getFieldVal(form, 'fr_used_length_mtr')),
    remaining_length_mtr: toSafeNumber(getFieldVal(form, 'fr_remaining_length_mtr')),
    additional_width_mm: toSafeNumber(getFieldVal(form, 'fr_additional_width_mm')),
    additional_length_mtr: toSafeNumber(getFieldVal(form, 'fr_additional_length_mtr')),
    additional_reason: getFieldVal(form, 'fr_additional_reason'),
    operator_request_note: getFieldVal(form, 'fr_operator_request_note')
  };
  try { localStorage.setItem(flexoRequestDraftKey(jobId), JSON.stringify(draft)); } catch (_) {}
}

function clearFlexoRequestDraft(jobId) {
  if (!jobId) return;
  try { localStorage.removeItem(flexoRequestDraftKey(jobId)); } catch (_) {}
}

function hasFlexoRequestValues(form) {
  if (!form) return false;
  const mode = String(getFieldVal(form, 'fr_request_mode') || 'remaining').toLowerCase();
  const sourceRollNo = String(getFieldVal(form, 'fr_source_roll_no') || '').trim();
  const usedLength = toSafeNumber(getFieldVal(form, 'fr_used_length_mtr'));
  const remainingLength = toSafeNumber(getFieldVal(form, 'fr_remaining_length_mtr'));
  const additionalWidth = toSafeNumber(getFieldVal(form, 'fr_additional_width_mm'));
  const additionalLength = toSafeNumber(getFieldVal(form, 'fr_additional_length_mtr'));
  const additionalReason = String(getFieldVal(form, 'fr_additional_reason') || '').trim();
  const operatorNote = String(getFieldVal(form, 'fr_operator_request_note') || '').trim();
  if (mode === 'add') {
    return additionalWidth > 0 || additionalLength > 0 || additionalReason || operatorNote;
  }
  return sourceRollNo || usedLength > 0 || remainingLength > 0;
}

function updateFlexoRequestModeUI(form) {
  if (!form) return;
  const mode = String(getFieldVal(form, 'fr_request_mode') || 'remaining').toLowerCase();
  const addWrap = document.querySelector('[data-req-section="add"]');
  const remWrap = document.querySelector('[data-req-section="remaining"]');
  if (addWrap) addWrap.style.display = mode === 'add' ? '' : 'none';
  if (remWrap) remWrap.style.display = mode === 'add' ? 'none' : '';
}

function updateFlexoRequestButtonStates(jobId) {
  if (!jobId) return;
  const form = document.getElementById('dm-operator-form');
  if (!form) return;
  const hasValues = hasFlexoRequestValues(form);
  const submitBtn = document.querySelector('[data-role="fr-submit"]');
  const againStartBtn = document.querySelector('[data-role="again-start"]');
  const submitForceLocked = !!(submitBtn && submitBtn.hasAttribute('data-force-disabled'));
  if (submitBtn && !submitForceLocked) submitBtn.disabled = !hasValues;
  if (againStartBtn) {
    if (submitForceLocked) {
      if (!againStartBtn.hasAttribute('data-force-disabled')) againStartBtn.disabled = false;
    } else if (hasValues) {
      againStartBtn.disabled = true;
    } else {
      if (!againStartBtn.hasAttribute('data-force-disabled')) {
        againStartBtn.disabled = false;
      }
    }
  }
}

function bindFlexoRequestAutoCalc(jobId, form, options) {
  if (!form) return;
  const opts = options || {};
  const formId = String(form.id || '');
  const resolveLinked = function(name) {
    if (!name) return null;
    return document.querySelector('[name="' + name + '"][form="' + formId + '"]') || form.querySelector('[name="' + name + '"]');
  };

  const sourceEl = resolveLinked('fr_source_roll_no');
  const modeEl = resolveLinked('fr_request_mode');
  const usedEl = resolveLinked('fr_used_length_mtr');
  const remEl = resolveLinked('fr_remaining_length_mtr');
  const reqInputs = Array.from(document.querySelectorAll('[name^="fr_"][form="' + formId + '"]'));
  if (!sourceEl || !usedEl || !remEl) return;

  remEl.readOnly = true;

  function syncRemaining() {
    const selected = sourceEl.options[sourceEl.selectedIndex];
    const rollLen = toSafeNumber(selected ? selected.getAttribute('data-roll-length') : 0);
    const used = toSafeNumber(usedEl.value);
    if (rollLen > 0 && used >= 0) {
      const rem = Math.max(0, rollLen - used);
      remEl.value = rem.toFixed(2);
    } else if (toSafeNumber(remEl.value) <= 0) {
      remEl.value = '';
    }
    saveFlexoRequestDraft(jobId, form);
    updateFlexoRequestButtonStates(jobId);
  }

  sourceEl.addEventListener('change', syncRemaining);
  usedEl.addEventListener('input', syncRemaining);
  if (modeEl) {
    modeEl.addEventListener('change', function() {
      saveFlexoRequestDraft(jobId, form);
      updateFlexoRequestModeUI(form);
      updateFlexoRequestButtonStates(jobId);
    });
  }
  reqInputs.forEach(function(el) {
    if (el === sourceEl || el === usedEl || el === remEl) return;
    el.addEventListener('input', function() { 
      saveFlexoRequestDraft(jobId, form);
      updateFlexoRequestButtonStates(jobId);
    });
    el.addEventListener('change', function() { 
      saveFlexoRequestDraft(jobId, form);
      updateFlexoRequestButtonStates(jobId);
    });
  });

  if (opts.autoComputeOnInit) syncRemaining();
  updateFlexoRequestModeUI(form);
}

function setControlsEditable(root, editable) {
  if (!root) return;
  root.querySelectorAll('input,select,textarea,button').forEach(function(el) {
    if (el.type === 'hidden') return;
    if (el.hasAttribute('data-keep-readonly')) return;
    if (!editable) {
      el.disabled = true;
    } else if (!el.hasAttribute('data-force-disabled')) {
      el.disabled = false;
    }
  });
}

function applyOperatorModalEditState(ctx) {
  if (!ctx || !ctx.form) return;
  const reqWrap = ctx.reqWrap || null;
  const reqEditable = !!ctx.reqEditable;
  const mainEditable = !!ctx.mainEditable;

  setControlsEditable(ctx.form, mainEditable);

  if (reqWrap) {
    reqWrap.style.display = ctx.showRequest ? '' : 'none';
    reqWrap.querySelectorAll('[name^="fr_"][form="dm-operator-form"], [data-role="fr-submit"]').forEach(function(el) {
      if (!reqEditable) {
        el.disabled = true;
      } else if (!el.hasAttribute('data-force-disabled')) {
        el.disabled = false;
      }
    });
    const remField = reqWrap.querySelector('[name="fr_remaining_length_mtr"][form="dm-operator-form"]');
    if (remField) remField.readOnly = true;
  }
}

function buildFlexoRequestPayloadFromForm(job, form) {
  if (!form) return null;
  const requestMode = String(getFieldVal(form, 'fr_request_mode') || 'remaining').toLowerCase();
  const sourceRollNo = getFieldVal(form, 'fr_source_roll_no');
  const usedLength = toSafeNumber(getFieldVal(form, 'fr_used_length_mtr'));
  const remainingLength = toSafeNumber(getFieldVal(form, 'fr_remaining_length_mtr'));
  const additionalWidth = toSafeNumber(getFieldVal(form, 'fr_additional_width_mm'));
  const additionalLength = toSafeNumber(getFieldVal(form, 'fr_additional_length_mtr'));
  const additionalReason = getFieldVal(form, 'fr_additional_reason');
  const operatorNote = getFieldVal(form, 'fr_operator_request_note');

  const excessEnabled = requestMode !== 'add' && !!sourceRollNo && (usedLength > 0 || remainingLength > 0);
  const additionalEnabled = requestMode === 'add' && additionalWidth > 0 && additionalLength > 0;
  if (!excessEnabled && !additionalEnabled) return null;

  return {
    additional_roll_request: {
      enabled: additionalEnabled,
      width_mm: additionalWidth,
      length_mtr: additionalLength,
      reason: additionalReason,
      operator_note: operatorNote,
    },
    excess_roll_adjustment: {
      enabled: excessEnabled,
      source_roll_no: sourceRollNo,
      used_length_mtr: usedLength,
      remaining_length_mtr: remainingLength,
      operator_note: operatorNote,
    },
    same_job_card: true,
    request_scope: 'flexo_operator_request',
    created_from_modal: true,
    created_from_job_id: Number(job?.id || 0),
  };
}

async function submitFlexoOperatorRequest(jobId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (!job) { erpToast('Job not found', 'error'); return; }
  const form = document.getElementById('dm-operator-form');
  if (!form) { erpToast('Request form not found', 'error'); return; }

  const payload = buildFlexoRequestPayloadFromForm(job, form);
  if (!payload) {
    erpToast('Fill required request fields before submitting.', 'warning');
    return;
  }

  const submitBtn = document.querySelector('[data-role="fr-submit"]');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting...';
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'submit_flexo_operator_request');
  fd.append('job_id', String(jobId));
  fd.append('request_payload', JSON.stringify(payload));
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      erpToast('Request failed: ' + (data.error || 'Unknown error'), 'error');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit Request to Production';
      }
      return;
    }
    clearFlexoRequestDraft(jobId);
    erpToast('Request submitted to Production Manager', 'success');
    location.reload();
  } catch (err) {
    erpToast('Network error: ' + err.message, 'error');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="bi bi-send"></i> Submit Request to Production';
    }
  }
}

async function reviewFlexoOperatorRequest(requestId, decision, jobId) {
  if (!requestId) return;
  const decisionLabel = decision === 'Approved' ? 'Accept' : 'Reject';
  const ok = await erpConfirmAsync('Are you sure you want to ' + decisionLabel + ' this request?', {
    title: 'Confirm Review',
    okLabel: decisionLabel,
    cancelLabel: 'Cancel'
  });
  if (!ok) return;
  const noteRaw = await erpPromptAsync((decision === 'Approved' ? 'Approval' : 'Rejection') + ' note (optional):', '', {
    title: 'Review Note',
    okLabel: 'Continue'
  });
  if (noteRaw === null) return;
  const note = String(noteRaw || '').trim();

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'review_flexo_operator_request');
  fd.append('request_id', String(requestId));
  fd.append('decision', String(decision));
  fd.append('review_note', note);

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      erpToast('Review failed: ' + (data.error || 'Unknown error'), 'error');
      return;
    }
    erpToast('Request ' + decisionLabel.toLowerCase() + 'ed successfully.', 'success');
    location.reload();
  } catch (err) {
    erpToast('Network error: ' + err.message, 'error');
  }
}

function buildFlexoSlittingUrl(job, requestId, task) {
  const target = new URL(BASE_URL + '/modules/inventory/slitting/index.php', window.location.origin);
  target.searchParams.set('from', 'flexo_request_accept');
  target.searchParams.set('job_id', String(job?.id || ''));
  target.searchParams.set('request_id', String(requestId || ''));
  if (Number(job?.planning_id || 0) > 0) {
    target.searchParams.set('planning_id', String(job.planning_id));
  }
  if (String(job?.plan_no || '').trim()) {
    target.searchParams.set('plan_no', String(job.plan_no).trim());
  }
  if (task && Number(task.width_mm || 0) > 0) {
    target.searchParams.set('target_width', String(task.width_mm));
  }
  if (task && Number(task.length_mtr || 0) > 0) {
    target.searchParams.set('target_length', String(task.length_mtr));
  }
  if (task && Number(task._idx) >= 0) {
    target.searchParams.set('task_index', String(task._idx));
  }
  return target.toString();
}

function getFlexoRequestType(payload) {
  const p = (payload && typeof payload === 'object') ? payload : {};
  const addReq = (p.additional_roll_request && typeof p.additional_roll_request === 'object') ? p.additional_roll_request : {};
  const exReq = (p.excess_roll_adjustment && typeof p.excess_roll_adjustment === 'object') ? p.excess_roll_adjustment : {};
  const addEnabled = !!addReq.enabled;
  const remainingEnabled = !!exReq.enabled;
  if (addEnabled && !remainingEnabled) return 'add_roll';
  if (remainingEnabled && !addEnabled) return 'remaining_roll';
  return 'unknown';
}

function openFlexoSlittingWorkspace(jobId, taskIndex, requestId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (!job) {
    erpToast('Job not found', 'error');
    return;
  }
  const extra = job.extra_data_parsed || {};
  const tasks = Array.isArray(extra.flexo_extension_tasks) ? extra.flexo_extension_tasks : [];
  const task = (Number.isInteger(taskIndex) && taskIndex >= 0) ? (tasks[taskIndex] || null) : null;
  window.location = buildFlexoSlittingUrl(job, requestId || job.flexo_latest_request_id || 0, task);
}

function showFlexoProductionUpdateModal(job, requestId, requestSpec, candidates) {
  return new Promise(resolve => {
    const reqW = Number(requestSpec?.width_mm || 0);
    const reqL = Number(requestSpec?.length_mtr || 0);
    const list = Array.isArray(candidates) ? candidates : [];
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(2,6,23,.55);z-index:20020;display:flex;align-items:center;justify-content:center;padding:16px';
    overlay.innerHTML = `<div style="width:min(980px,96vw);max-height:88vh;overflow:auto;background:#fff;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 30px 70px rgba(2,6,23,.35)">
      <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;background:linear-gradient(135deg,#fff7ed,#ffffff)">
        <div style="font-weight:900;font-size:1rem;color:#0f172a">Production Update</div>
        <div style="font-size:.78rem;color:#64748b">Job: <strong>${esc(job?.job_no || '—')}</strong> • Request #${esc(String(requestId || ''))} • Required: <strong>${esc(String(reqW || 0))}mm × ${esc(String(reqL || 0))}m</strong></div>
      </div>
      <div style="padding:14px 16px;display:grid;gap:10px">
        <div style="font-size:.76rem;color:#475569">Select compatible stock roll if available. If unavailable, choose slitting workflow.</div>
        <div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">
          <table style="width:100%;border-collapse:collapse;font-size:.75rem">
            <thead style="background:#f8fafc"><tr><th style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:left">Pick</th><th style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:left">Roll</th><th style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:left">Material</th><th style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:left">Size</th><th style="padding:8px;border-bottom:1px solid #e2e8f0;text-align:left">After Issue</th></tr></thead>
            <tbody>
              ${list.length ? list.map((r, idx) => `<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px"><input type="radio" name="fp_prod_pick" value="${esc(String(r.roll_no || ''))}" ${idx===0 ? 'checked' : ''}></td><td style="padding:8px;font-weight:800;color:#7c3aed">${esc(String(r.roll_no || ''))}</td><td style="padding:8px">${esc(String(r.company || ''))} / ${esc(String(r.paper_type || ''))}</td><td style="padding:8px">${esc(String(r.width_mm || 0))}mm × ${esc(String(r.length_mtr || 0))}m</td><td style="padding:8px">${r.will_split ? ('Split, remain ' + esc(String(r.remaining_after_issue || 0)) + 'm') : 'Full issue'}</td></tr>`).join('') : `<tr><td colspan="5" style="padding:12px;color:#b45309;background:#fffbeb">No compatible stock available.</td></tr>`}
            </tbody>
          </table>
        </div>
        <textarea id="fpProdUpdateNote" placeholder="Manager note (optional)" style="min-height:70px;border:1px solid #e2e8f0;border-radius:10px;padding:10px;font-size:.8rem"></textarea>
      </div>
      <div style="padding:12px 16px;border-top:1px solid #e2e8f0;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
        <button id="fpProdCancel" class="fp-action-btn fp-btn-view" style="padding:8px 14px">Cancel</button>
        <button id="fpProdSlit" class="fp-action-btn fp-btn-start" style="padding:8px 14px;background:#f59e0b">Open Slitting Interface</button>
        <button id="fpProdApply" class="fp-action-btn fp-btn-start" style="padding:8px 14px">Apply Selected Roll</button>
      </div>
    </div>`;
    document.body.appendChild(overlay);

    const close = (payload) => {
      try { document.body.removeChild(overlay); } catch (_) {}
      resolve(payload);
    };

    overlay.querySelector('#fpProdCancel')?.addEventListener('click', () => close({ action: 'cancel' }));
    overlay.querySelector('#fpProdSlit')?.addEventListener('click', () => {
      const note = String(overlay.querySelector('#fpProdUpdateNote')?.value || '').trim();
      close({ action: 'slitting', note: note });
    });
    overlay.querySelector('#fpProdApply')?.addEventListener('click', () => {
      const selected = overlay.querySelector('input[name="fp_prod_pick"]:checked');
      const note = String(overlay.querySelector('#fpProdUpdateNote')?.value || '').trim();
      if (!selected) {
        erpToast('Please select a stock roll, or use Open Slitting Interface.', 'warning');
        return;
      }
      close({ action: 'stock', selected_roll_no: String(selected.value || '').trim(), note: note });
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) close({ action: 'cancel' });
    });
  });
}

async function openFlexoProductionUpdate(requestId, jobId) {
  if (!requestId) return;
  const job = ALL_JOBS.find(j => j.id == jobId) || null;
  if (!job) {
    erpToast('Job not found', 'error');
    return;
  }
  const payload = (job && typeof job.flexo_latest_request_payload === 'object') ? job.flexo_latest_request_payload : {};
  const reqType = getFlexoRequestType(payload);

  if (reqType === 'add_roll') {
    const url = buildFlexoSlittingUrl(job, requestId, null);
    window.location = url;
    return;
  }

  if (reqType !== 'remaining_roll') {
    erpToast('Unsupported request type for production update.', 'error');
    return;
  }

  const noteRaw = await erpPromptAsync('Update note (optional):', '', {
    title: 'Update Roll Request',
    okLabel: 'Update'
  });
  if (noteRaw === null) return;
  const reviewNote = String(noteRaw || '').trim();

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'approve_flexo_request_production_update');
  fd.append('request_id', String(requestId));
  fd.append('mode', 'stock');
  fd.append('selected_roll_no', '');
  fd.append('review_note', reviewNote);

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      erpToast('Update failed: ' + (data.error || 'Unknown error'), 'error');
      return;
    }
    if (data.auto_completed) {
      erpToast('Remaining roll updated and job completed successfully', 'success');
    } else {
      erpToast('Roll request updated successfully.', 'success');
    }
    const labelUrl = String(data.label_print_url || '').trim();
    if (labelUrl) {
      window.location = labelUrl;
      return;
    }
    location.reload();
  } catch (err) {
    erpToast('Network error: ' + err.message, 'error');
  }
}

async function completeFlexoAdditionalSlitting(jobId, taskIndex) {
  const rollNo = await erpPromptAsync('Enter issued roll no from completed slitting (stock roll):', '', {
    title: 'Complete Additional Slitting',
    okLabel: 'Continue'
  });
  if (rollNo === null) return;
  const cleanRoll = String(rollNo || '').trim();
  if (!cleanRoll) {
    erpToast('Roll number is required.', 'warning');
    return;
  }
  const noteRaw = await erpPromptAsync('Completion note (optional):', '', { title: 'Completion Note', okLabel: 'Next' });
  if (noteRaw === null) return;
  const note = String(noteRaw || '').trim();
  const ok = await erpConfirmAsync('Complete additional slitting task using roll ' + cleanRoll + '?', {
    title: 'Confirm Completion',
    okLabel: 'Complete',
    cancelLabel: 'Cancel'
  });
  if (!ok) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'complete_flexo_additional_slitting_task');
  fd.append('job_id', String(jobId));
  fd.append('task_index', String(taskIndex));
  fd.append('source_roll_no', cleanRoll);
  fd.append('completion_note', note);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      erpToast('Task completion failed: ' + (data.error || 'Unknown error'), 'error');
      return;
    }
    erpToast('Additional slitting task completed. Issued roll: ' + (data.issued_roll_no || cleanRoll), 'success');
    location.reload();
  } catch (err) {
    erpToast('Network error: ' + err.message, 'error');
  }
}

function operatorTabMatch(card, status) {
  const lockState = String(card.dataset.lockstate || '').toLowerCase();
  const cardStatus = String(card.dataset.status || '').trim();
  const hasRequest = String(card.dataset.hasRequest || '0') === '1';
  const isWaitingSlitting = String(card.dataset.waitingSlitting || '0') === '1';

  if (status === 'Queued') return lockState === 'locked';
  if (status === 'Pending') return lockState !== 'locked' && (cardStatus === 'Pending' || cardStatus === 'Queued');
  if (status === 'WaitingSlitting') return cardStatus === 'Pending' && isWaitingSlitting;
  if (status === 'Request') return cardStatus === 'Running' && hasRequest;
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

  const btnActive = document.getElementById('fpTabBtnActive');
  const btnHistory = document.getElementById('fpTabBtnHistory');
  if (btnHistory) btnHistory.classList.remove('active');
  if (btnActive) btnActive.classList.add('active');

  applyFPFilters();
}

function clickStatFilter(status) {
  activeStatusFilter = status;
  document.querySelectorAll('.fp-filter-btn').forEach(b => {
    const filterKey = String(b.dataset.filter || b.textContent || '').toLowerCase().replace(/\s+/g, '');
    const target = (status === 'all' ? 'all' : String(status || '').toLowerCase().replace(/\s+/g, ''));
    b.classList.toggle('active', filterKey === target);
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
    htGoPage(1);
  } else {
    panelActive.style.display  = '';
    panelHistory.style.display = 'none';
    btnActive.classList.add('active');
    btnHistory.classList.remove('active');
  }
}

// ─── History table: search/filter/sort/pagination/bulk ──────
let HT_PERIOD = 'all';
let HT_PAGE = 1;
let HT_SORT = { col: -1, asc: true };

function htVisibleRows() {
  const rows = Array.from(document.querySelectorAll('#htBody tr[data-id]'));
  const q = (document.getElementById('htSearch')?.value || '').trim().toLowerCase();
  const now = new Date();
  const from = document.getElementById('htDateFrom')?.value || '';
  const to = document.getElementById('htDateTo')?.value || '';

  return rows.filter(function(tr) {
    if (q && !(tr.dataset.search || '').includes(q)) return false;
    if (HT_PERIOD === 'all') return true;

    const dRaw = tr.dataset.completed || '';
    if (!dRaw) return false;
    const d = new Date(dRaw);
    if (isNaN(d.getTime())) return false;

    if (HT_PERIOD === 'today') {
      return d.toISOString().slice(0, 10) === now.toISOString().slice(0, 10);
    }
    if (HT_PERIOD === 'week') {
      const dow = now.getDay() || 7;
      const start = new Date(now);
      start.setDate(now.getDate() - dow + 1);
      start.setHours(0, 0, 0, 0);
      return d >= start;
    }
    if (HT_PERIOD === 'month') {
      return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    }
    if (HT_PERIOD === 'year') {
      return d.getFullYear() === now.getFullYear();
    }
    if (HT_PERIOD === 'custom') {
      const day = d.toISOString().slice(0, 10);
      if (from && day < from) return false;
      if (to && day > to) return false;
      return true;
    }
    return true;
  });
}

function htSetPeriod(period, btn) {
  HT_PERIOD = period;
  document.querySelectorAll('.ht-period-btn').forEach(function(b) { b.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  const df = document.getElementById('htDateFrom');
  const dt = document.getElementById('htDateTo');
  if (df) df.value = '';
  if (dt) dt.value = '';
  htGoPage(1);
}

function htApplyCustomDate() {
  const from = document.getElementById('htDateFrom')?.value || '';
  const to = document.getElementById('htDateTo')?.value || '';
  if (!from && !to) return;
  HT_PERIOD = 'custom';
  document.querySelectorAll('.ht-period-btn').forEach(function(b) { b.classList.remove('active'); });
  htGoPage(1);
}

function htGoPage(page) {
  const visible = htVisibleRows();
  const perSel = document.getElementById('htPerPage')?.value || '25';
  const per = perSel === 'all' ? visible.length : parseInt(perSel, 10);
  const perSafe = per > 0 ? per : Math.max(visible.length, 1);
  const totalPages = Math.max(1, Math.ceil(visible.length / perSafe));
  HT_PAGE = Math.max(1, Math.min(page, totalPages));

  const start = (HT_PAGE - 1) * perSafe;
  const end = start + perSafe;

  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) { tr.style.display = 'none'; });
  visible.forEach(function(tr, i) {
    tr.style.display = (i >= start && i < end) ? '' : 'none';
  });

  const shownEnd = Math.min(end, visible.length);
  const info = document.getElementById('htPageInfo');
  if (info) info.textContent = 'Showing ' + (visible.length ? (start + 1) : 0) + '–' + shownEnd + ' of ' + visible.length;

  const btns = document.getElementById('htPageBtns');
  if (btns) {
    let html = '<button class="ht-page-btn" onclick="htGoPage(' + (HT_PAGE - 1) + ')" ' + (HT_PAGE <= 1 ? 'disabled' : '') + '>‹</button>';
    for (let p = 1; p <= totalPages; p++) {
      if (totalPages > 7 && p > 2 && p < totalPages - 1 && Math.abs(p - HT_PAGE) > 1) {
        if (p === 3 || p === totalPages - 2) html += '<span style="padding:0 6px;color:#94a3b8">…</span>';
        continue;
      }
      html += '<button class="ht-page-btn ' + (p === HT_PAGE ? 'active' : '') + '" onclick="htGoPage(' + p + ')">' + p + '</button>';
    }
    html += '<button class="ht-page-btn" onclick="htGoPage(' + (HT_PAGE + 1) + ')" ' + (HT_PAGE >= totalPages ? 'disabled' : '') + '>›</button>';
    btns.innerHTML = html;
  }

  htUpdateBulk();
}

function htSortCol(colIdx) {
  const tbody = document.getElementById('htBody');
  if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
  if (!rows.length) return;

  const asc = HT_SORT.col === colIdx ? !HT_SORT.asc : true;
  HT_SORT = { col: colIdx, asc: asc };

  rows.sort(function(a, b) {
    const aText = (a.children[colIdx + 1]?.textContent || '').trim();
    const bText = (b.children[colIdx + 1]?.textContent || '').trim();
    const aNum = parseFloat(aText);
    const bNum = parseFloat(bText);
    if (!isNaN(aNum) && !isNaN(bNum)) return asc ? (aNum - bNum) : (bNum - aNum);
    const aDate = Date.parse(aText);
    const bDate = Date.parse(bText);
    if (!isNaN(aDate) && !isNaN(bDate)) return asc ? (aDate - bDate) : (bDate - aDate);
    return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
  });

  rows.forEach(function(r) { tbody.appendChild(r); });
  document.querySelectorAll('#htTable th').forEach(function(th, i) {
    th.classList.toggle('sorted', i === colIdx + 1);
    const icon = th.querySelector('.ht-sort');
    if (icon) icon.textContent = (i === colIdx + 1) ? (asc ? '▲' : '▼') : '▲▼';
  });
  htGoPage(1);
}

function htUpdateBulk() {
  const selected = document.querySelectorAll('.ht-row-cb:checked').length;
  const bar = document.getElementById('htBulkBar');
  const count = document.getElementById('htSelectedCount');
  if (count) count.textContent = selected;
  if (bar) bar.classList.toggle('visible', selected > 0);
  document.querySelectorAll('.ht-row-cb').forEach(function(cb) {
    const tr = cb.closest('tr');
    if (tr) tr.classList.toggle('ht-selected', cb.checked);
  });
}

function htToggleAll(checked) {
  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb');
    if (cb) cb.checked = checked;
  });
  htUpdateBulk();
}

function htSelectAllVisible() {
  document.querySelectorAll('#htBody tr[data-id]').forEach(function(tr) {
    if (tr.style.display === 'none') return;
    const cb = tr.querySelector('.ht-row-cb');
    if (cb) cb.checked = true;
  });
  const master = document.getElementById('htCheckAll');
  if (master) master.checked = true;
  htUpdateBulk();
}

function htDeselectAll() {
  document.querySelectorAll('.ht-row-cb').forEach(function(cb) { cb.checked = false; });
  const master = document.getElementById('htCheckAll');
  if (master) master.checked = false;
  htUpdateBulk();
}

function htBulkPrint() {
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(function(cb) { return cb.dataset.jobId; });
  if (!ids.length) { erpToast('No history job selected', 'warning'); return; }
  const jobs = ids.map(function(id) { return ALL_JOBS.find(function(j) { return j.id == id; }); }).filter(Boolean);
  if (!jobs.length) return;

  (async function() {
    const mode = await choosePrintMode();
    if (!mode) return;
    let pages = '';
    for (let idx = 0; idx < jobs.length; idx++) {
      const job = jobs[idx];
      const extra = job.extra_data_parsed || {};
      const card = normalizeCardData(job, extra);
      const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
      const qrDataUrl = await generateQR(qrUrl);
      const pb = idx < jobs.length - 1 ? 'page-break-after:always;' : '';
      let cardHtml = renderPrintCardHtml(job, card, extra, qrDataUrl);
      if (mode === 'bw') cardHtml = printBwTransform(cardHtml);
      pages += `<div style="${pb}">${cardHtml}</div>`;
    }
    const w = window.open('', '_blank', 'width=820,height=920');
    w.document.write('<!DOCTYPE html><html><head><title>Bulk Print - ' + jobs.length + ' Job Cards</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>' + pages + '</body></html>');
    w.document.close();
    w.focus();
    setTimeout(function() { w.print(); }, 400);
  })();
}

async function htBulkDelete() {
  if (!IS_ADMIN) { erpToast('Access denied. Only system admin can delete job cards.', 'error'); return; }
  const ids = Array.from(document.querySelectorAll('.ht-row-cb:checked')).map(cb => cb.dataset.jobId);
  if (!ids.length) { erpToast('No history jobs selected', 'warning'); return; }
  const delOk = await erpConfirmAsync(`Delete ${ids.length} selected job card(s)?\n\nLinked reset logic will apply for each job (paper stock, planning, downstream jobs).`, {
    title: 'Confirm Bulk Delete',
    okLabel: 'Delete',
    cancelLabel: 'Cancel'
  });
  if (!delOk) return;

  let ok = 0, failed = 0, errors = [];
  for (const id of ids) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'delete_job');
    fd.append('job_id', id);
    try {
      const res = await fetch(API_BASE, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) { ok++; }
      else {
        failed++;
        const job = ALL_JOBS.find(j => j.id == id);
        const label = job ? job.job_no : ('ID ' + id);
        errors.push(`${label}: ${data.error || 'Unknown error'}`);
      }
    } catch (err) { failed++; errors.push('ID ' + id + ': ' + err.message); }
  }
  if (errors.length) erpToast(`Deleted: ${ok}, Failed: ${failed}`, failed > 0 ? 'warning' : 'success');
  if (ok > 0) location.reload();
}

document.getElementById('htSearch')?.addEventListener('input', function() {
  htGoPage(1);
});

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
  if (!checkedIds.length) { erpToast('No job cards selected.', 'warning'); return; }
  const mode = await choosePrintMode();
  if (!mode) return;
  const selectedJobs = ALL_JOBS.filter(j => checkedIds.includes(j.id));
  let pages = '';
  for (const [idx, job] of selectedJobs.entries()) {
    const extra = job.extra_data_parsed || {};
    const card = normalizeCardData(job, extra);
    const pb  = idx < selectedJobs.length - 1 ? 'page-break-after:always;' : '';
    const jqrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
    const jqrDataUrl = await generateQR(jqrUrl);
    let cardHtml = renderPrintCardHtml(job, card, extra, jqrDataUrl);
    if (mode === 'bw') cardHtml = printBwTransform(cardHtml);
    pages += `<div style="${pb}">${cardHtml}</div>`;
  }
  const w = window.open('', '_blank', 'width=820,height=920');
  w.document.write(`<!DOCTYPE html><html><head><title>Flexo Job Cards (${selectedJobs.length})</title><style>@page{margin:12mm}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}</style></head><body>${pages}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}

// ─── Live timers ────────────────────────────────────────────
function updateTimers() {
  document.querySelectorAll('.fp-timer').forEach(el => {
    const base = Math.max(0, parseInt(el.dataset.baseSeconds || '0', 10) || 0);
    const resumedAt = parseInt(el.dataset.resumedAt || '0', 10) || 0;
    const diff = resumedAt > 0 ? Math.floor(base + ((Date.now() - resumedAt) / 1000)) : base;
    el.textContent = secondsToHms(diff);
  });
}
setInterval(updateTimers, 1000);
updateTimers();

// ─── Status update ──────────────────────────────────────────
async function updateFPStatus(id, newStatus) {
  const ok = await erpConfirmAsync('Set this job to ' + newStatus + '?', {
    title: 'Confirm Status Change',
    okLabel: 'Confirm',
    cancelLabel: 'Cancel'
  });
  if (!ok) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', newStatus);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else erpToast('Error: ' + (data.error || 'Unknown'), 'error');
  } catch (err) { erpToast('Network error: ' + err.message, 'error'); }
}

// ─── Start Job with Timer Overlay ───────────────────────────
let _timerInterval = null;
let _timerStart = 0;
let _timerJobId = null;

function isPrintTimerActive(job) {
  return !!(job && job.extra_data_parsed && job.extra_data_parsed.timer_active);
}

async function markPrintTimerEnded(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'end_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to end timer');

  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    const nowIso = data.timer_ended_at || new Date().toISOString();
    if (job.extra_data_parsed.timer_last_resumed_at) {
      pushPrintTimerSegmentLocal(job.extra_data_parsed, 'timer_work_segments', job.extra_data_parsed.timer_last_resumed_at, nowIso);
    }
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'ended';
    job.extra_data_parsed.timer_ended_at = nowIso;
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_paused_at = '';
    job.extra_data_parsed.timer_pause_started_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = Number(data.timer_accumulated_seconds || job.extra_data_parsed.timer_accumulated_seconds || 0);
    pushPrintTimerEventLocal(job.extra_data_parsed, 'end', nowIso);
  }
}

async function pausePrintTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'pause_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to pause timer');

  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.extra_data_parsed = job.extra_data_parsed || {};
    const nowIso = new Date().toISOString();
    if (job.extra_data_parsed.timer_last_resumed_at) {
      pushPrintTimerSegmentLocal(job.extra_data_parsed, 'timer_work_segments', job.extra_data_parsed.timer_last_resumed_at, nowIso);
    }
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = data.timer_state || 'paused';
    job.extra_data_parsed.timer_paused_at = data.timer_paused_at || '';
    job.extra_data_parsed.timer_pause_started_at = data.timer_paused_at || nowIso;
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = Number(data.timer_accumulated_seconds || 0);
    pushPrintTimerEventLocal(job.extra_data_parsed, 'pause', job.extra_data_parsed.timer_paused_at || nowIso);
  }
}

async function resetPrintTimer(jobId) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'reset_timer_session');
  fd.append('job_id', jobId);

  const res = await fetch(API_BASE, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Unable to reset timer');

  const job = ALL_JOBS.find(j => j.id == jobId);
  if (job) {
    job.status = 'Pending';
    job.started_at = null;
    job.completed_at = null;
    job.duration_minutes = null;
    job.extra_data_parsed = job.extra_data_parsed || {};
    job.extra_data_parsed.timer_active = false;
    job.extra_data_parsed.timer_state = 'pending';
    job.extra_data_parsed.timer_started_at = '';
    job.extra_data_parsed.timer_last_resumed_at = '';
    job.extra_data_parsed.timer_paused_at = '';
    job.extra_data_parsed.timer_pause_started_at = '';
    job.extra_data_parsed.timer_ended_at = '';
    job.extra_data_parsed.timer_accumulated_seconds = 0;
    job.extra_data_parsed.timer_events = [];
    job.extra_data_parsed.timer_work_segments = [];
    job.extra_data_parsed.timer_pause_segments = [];
  }
}

function printTimerTotalSeconds(job) {
  if (!job) return 0;
  const extra = job.extra_data_parsed || {};
  const base = Math.max(0, Number(extra.timer_accumulated_seconds || 0));
  if (!extra.timer_active) return Math.floor(base);
  const resumedRaw = extra.timer_last_resumed_at || extra.timer_started_at || job.started_at || '';
  const resumedAt = resumedRaw ? Date.parse(String(resumedRaw).replace(' ', 'T')) : NaN;
  if (!Number.isFinite(resumedAt) || resumedAt <= 0) return Math.floor(base);
  return Math.floor(base + ((Date.now() - resumedAt) / 1000));
}

async function finalizePrintTimer(jobId) {
  if (!jobId) return;
  try {
    await markPrintTimerEnded(jobId);
  } catch (err) {
    erpToast('Timer end failed: ' + err.message, 'error');
    return;
  }

  const ov = document.getElementById('fpTimerOverlay');
  if (ov) ov.remove();

  _uploadedPhotoUrl = '';
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
        if (data.ok) {
          _uploadedPhotoUrl = data.photo_url || '';
          const j = ALL_JOBS.find(x => x.id == jobId);
          if (j) {
            if (!j.extra_data_parsed) j.extra_data_parsed = {};
            j.extra_data_parsed.physical_print_photo_url = _uploadedPhotoUrl;
            j.extra_data_parsed.physical_print_photo_path = data.photo_path || '';
          }
        } else {
          erpToast('Photo upload failed: ' + (data.error || 'Unknown'), 'error');
        }
      } catch (err) {
        erpToast('Photo upload error: ' + err.message, 'error');
      }
    }
    camInput.remove();
    openPrintDetail(jobId, 'complete');
  });
  camInput.addEventListener('cancel', function() {
    camInput.remove();
    openPrintDetail(jobId, 'complete');
  });
  camInput.click();
}

async function endRunningPrintTimer(jobId) {
  await finalizePrintTimer(jobId);
}

function printTimerStartMs(job) {
  if (!job) return Date.now();
  const startedRaw = (job.extra_data_parsed && job.extra_data_parsed.timer_started_at) || job.started_at || '';
  const parsed = startedRaw ? Date.parse(String(startedRaw).replace(' ', 'T')) : NaN;
  return Number.isFinite(parsed) && parsed > 0 ? parsed : Date.now();
}

function showPrintTimerOverlay(job) {
  if (!job) return;
  const existing = document.getElementById('fpTimerOverlay');
  if (existing) existing.remove();

  _timerJobId = Number(job.id || 0);
  _timerStart = printTimerStartMs(job);

  const jobLabel = resolvePrintDisplayName(job) || ('Job #' + _timerJobId);
  const jobNo = job.job_no || '';
  const paperCo = job.company || '';
  const paperType = job.paper_type || '';

  const overlay = document.createElement('div');
  overlay.className = 'fp-timer-overlay';
  overlay.id = 'fpTimerOverlay';
  overlay.innerHTML = `
    <div class="fp-timer-jobinfo">
      <div style="font-size:1.3rem;font-weight:900;letter-spacing:.03em">${esc(jobNo)}</div>
      <div style="margin-top:4px">${esc(jobLabel)}</div>
      ${paperCo || paperType ? `<div style="margin-top:4px;font-size:.85rem;opacity:.8">${esc(paperCo)}${paperCo && paperType ? ' — ' : ''}${esc(paperType)}</div>` : ''}
    </div>
    <div class="fp-timer-display" id="fpTimerCounter">00:00:00</div>
    <div class="fp-timer-actions">
      <button class="fp-timer-btn-cancel" onclick="cancelTimer()"><i class="bi bi-x-lg"></i> Cancel</button>
      <button class="fp-timer-btn-pause" onclick="pauseTimer()"><i class="bi bi-pause-fill"></i> Pause</button>
      <button class="fp-timer-btn-end" onclick="endTimer()"><i class="bi bi-stop-fill"></i> End</button>
    </div>
  `;
  document.body.appendChild(overlay);

  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = setInterval(() => {
    const diff = Math.max(0, printTimerTotalSeconds(job));
    const h = String(Math.floor(diff / 3600)).padStart(2, '0');
    const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
    const s = String(diff % 60).padStart(2, '0');
    const el = document.getElementById('fpTimerCounter');
    if (el) el.textContent = h + ':' + m + ':' + s;
  }, 1000);
}

function resumeRunningPrintTimer(jobId) {
  const job = ALL_JOBS.find(j => j.id == jobId);
  if (!job || String(job.status || '') !== 'Running') {
    erpToast('Timer is not active for this job.', 'warning');
    return;
  }
  if (!isPrintTimerActive(job)) {
    erpToast('Timer is paused. Click Again Start.', 'warning');
    return;
  }
  showPrintTimerOverlay(job);
}

let FP_VERIFIER_SCANNER = null;

function printNormalizeRollNo(val) {
  return String(val || '').trim().toUpperCase();
}

function printExtractRollNoFromQr(rawValue) {
  const raw = String(rawValue || '').trim();
  if (!raw) return '';
  try {
    const u = new URL(raw);
    const qRoll = u.searchParams.get('roll_no') || u.searchParams.get('roll') || u.searchParams.get('rn') || '';
    if (String(qRoll).trim()) return String(qRoll).trim();
  } catch (e) {}

  const named = raw.match(/(?:roll\s*no|roll_no|roll)\s*[:=\-]\s*([A-Za-z0-9._\/-]+)/i);
  if (named && named[1]) return named[1].trim();

  const tokens = raw.split(/[^A-Za-z0-9._\/-]+/).filter(Boolean);
  if (tokens.length) return tokens[tokens.length - 1].trim();
  return raw;
}

function printBuildRequiredRolls(job) {
  const rows = Array.isArray(job?.slitting_rolls) ? job.slitting_rolls : [];
  const uniq = {};
  const out = [];

  rows.forEach(function(r) {
    const candidate = String(r?.roll_no || '').trim();
    const norm = printNormalizeRollNo(candidate);
    if (!norm || uniq[norm]) return;
    uniq[norm] = true;
    out.push({
      roll_no: candidate,
      norm: norm,
      paper_type: String(r?.paper_type || job?.paper_type || '').trim(),
      company: String(r?.company || job?.company || '').trim(),
      width: r?.width_mm ?? job?.width_mm ?? '',
      length: r?.length_mtr ?? job?.length_mtr ?? ''
    });
  });

  return out;
}

function printShowVerificationMessage(el, kind, text) {
  if (!el) return;
  const styles = {
    info: 'background:#e0f2fe;color:#075985;border:1px solid #bae6fd',
    ok: 'background:#dcfce7;color:#166534;border:1px solid #bbf7d0',
    warn: 'background:#fef3c7;color:#92400e;border:1px solid #fde68a',
    bad: 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca'
  };
  el.style.display = 'block';
  el.style.cssText += ';' + (styles[kind] || styles.info);
  el.innerHTML = esc(text || '');
}

async function printCloseVerifierScanner() {
  if (!FP_VERIFIER_SCANNER) return;
  try {
    await FP_VERIFIER_SCANNER.clear();
  } catch (e) {}
  FP_VERIFIER_SCANNER = null;
}

async function printOpenRollVerification(job) {
  const required = printBuildRequiredRolls(job);
  if (!required.length) {
    return { ok: false, error: 'No assigned slitted rolls found for this printing job.' };
  }

  const isMobileView = window.matchMedia('(max-width: 640px)').matches;
  const actionWidth = isMobileView ? 'width:100%;justify-content:center;min-height:44px' : '';
  const rowMap = {};
  required.forEach(function(r) { rowMap[r.norm] = r; });
  const matched = {};
  const methods = {};

  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:10050;display:flex;align-items:center;justify-content:center;padding:' + (isMobileView ? '0' : '16px');
  overlay.innerHTML = `
    <div style="width:min(760px,100%);height:${isMobileView ? '100dvh' : 'auto'};max-height:${isMobileView ? '100dvh' : '90vh'};overflow:auto;background:#fff;border-radius:${isMobileView ? '0' : '18px'};box-shadow:0 30px 80px rgba(15,23,42,.35)">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:${isMobileView ? '12px' : '18px 20px'};border-bottom:1px solid #e2e8f0">
        <div>
          <div style="font-size:1rem;font-weight:900;color:#0f172a">Assigned Roll Verification</div>
          <div style="font-size:.82rem;color:#475569;margin-top:4px">Job ${esc(job?.job_no || ('#' + (job?.id || '')))} - verify all assigned slitted rolls before start.</div>
        </div>
        <button type="button" id="fpVClose" class="fp-action-btn fp-btn-view"><i class="bi bi-x-lg"></i></button>
      </div>

      <div style="padding:${isMobileView ? '12px' : '18px 20px'};display:grid;gap:${isMobileView ? '12px' : '14px'}">
        <div style="border:1px solid #e2e8f0;border-radius:14px;padding:${isMobileView ? '12px' : '14px'};background:#f8fafc">
          <div style="font-size:.8rem;font-weight:900;color:#334155;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Assigned Slitted Rolls</div>
          <div id="fpVList"></div>
        </div>

        <div id="fpVMsg" style="display:none;padding:10px 12px;border-radius:10px;font-size:.82rem;font-weight:800"></div>

        <div>
          <label style="display:block;font-size:.66rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Last Scan Value</label>
          <input type="text" id="fpVLast" readonly style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc">
        </div>

        <div>
          <label style="display:block;font-size:.66rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">QR Scanner</label>
          <div id="fpVScanner" style="min-height:${isMobileView ? '250px' : '280px'};background:#0f172a;border-radius:14px;padding:${isMobileView ? '6px' : '10px'};overflow:hidden"></div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;${isMobileView ? 'flex-direction:column;' : ''}">
          <button type="button" id="fpVStart" class="fp-action-btn fp-btn-start" style="${actionWidth}"><i class="bi bi-camera"></i> Start QR Scanner</button>
          <button type="button" id="fpVStop" class="fp-action-btn fp-btn-view" style="display:none;${actionWidth}"><i class="bi bi-stop-fill"></i> Stop Scan</button>
          <button type="button" id="fpVPhoto" class="fp-action-btn fp-btn-view" style="${actionWidth}"><i class="bi bi-image"></i> Scan Photo</button>
          <input type="file" id="fpVFile" accept="image/*" capture="environment" style="display:none">
        </div>

        <div>
          <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
            <label style="display:block;font-size:.66rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.06em">Manual Roll Input</label>
            <span id="fpVManualLock" style="display:none;font-size:.6rem;font-weight:900;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:999px;padding:2px 8px">Scan Only</span>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-direction:${isMobileView ? 'column' : 'row'}">
            <input type="text" id="fpVManual" style="flex:1;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px" placeholder="Type roll no and add">
            <button type="button" id="fpVAdd" class="fp-action-btn fp-btn-view" style="${actionWidth}"><i class="bi bi-keyboard"></i> Add</button>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-direction:${isMobileView ? 'column' : 'row'};justify-content:space-between;gap:12px;align-items:${isMobileView ? 'stretch' : 'center'};padding:${isMobileView ? '12px' : '16px 20px'};border-top:1px solid #e2e8f0;background:#f8fafc">
        <div id="fpVProgress" style="font-size:.84rem;font-weight:900;color:#0f172a;${isMobileView ? 'width:100%;' : ''}">Matched 0 / ${required.length}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;${isMobileView ? 'width:100%;' : ''}">
          <button type="button" id="fpVCancel" class="fp-action-btn fp-btn-view" style="${actionWidth}">Cancel</button>
          <button type="button" id="fpVProceed" class="fp-action-btn fp-btn-start" style="${actionWidth}" disabled>Start Job</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(overlay);

  const listEl = overlay.querySelector('#fpVList');
  const msgEl = overlay.querySelector('#fpVMsg');
  const progressEl = overlay.querySelector('#fpVProgress');
  const proceedBtn = overlay.querySelector('#fpVProceed');
  const lastEl = overlay.querySelector('#fpVLast');
  const manualEl = overlay.querySelector('#fpVManual');
  const manualBtn = overlay.querySelector('#fpVAdd');
  const manualLock = overlay.querySelector('#fpVManualLock');
  const startBtn = overlay.querySelector('#fpVStart');
  const stopBtn = overlay.querySelector('#fpVStop');
  const fileInput = overlay.querySelector('#fpVFile');

  if (!CAN_MANUAL_ROLL_ENTRY) {
    if (manualLock) manualLock.style.display = '';
    if (manualEl) {
      manualEl.disabled = true;
      manualEl.placeholder = 'Manual disabled for this account';
      manualEl.style.background = '#f8fafc';
      manualEl.style.opacity = '0.65';
      manualEl.style.cursor = 'not-allowed';
    }
    if (manualBtn) {
      manualBtn.disabled = true;
      manualBtn.style.opacity = '0.65';
      manualBtn.style.cursor = 'not-allowed';
      manualBtn.title = 'Manual roll entry is disabled for this account.';
    }
    printShowVerificationMessage(msgEl, 'info', 'Scan Only Mode: manual roll entry is disabled for this account.');
  }

  function renderList() {
    listEl.innerHTML = required.map(function(row) {
      const ok = !!matched[row.norm];
      const method = methods[row.norm] ? (' (' + methods[row.norm] + ')') : '';
      return `<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border-radius:10px;background:${ok ? '#dcfce7' : '#fff'};border:1px solid ${ok ? '#86efac' : '#e2e8f0'};margin-bottom:8px">
        <div>
          <div style="font-size:.88rem;font-weight:900;color:#0f172a">${esc(row.roll_no)}</div>
          <div style="font-size:.75rem;color:#64748b">${esc(row.paper_type || '--')} | ${esc(String(row.width || '--'))} x ${esc(String(row.length || '--'))}</div>
        </div>
        <div style="font-size:.72rem;font-weight:900;text-transform:uppercase;color:${ok ? '#166534' : '#92400e'}">${ok ? ('Matched' + method) : 'Pending'}</div>
      </div>`;
    }).join('');
    const matchedCount = Object.keys(matched).length;
    progressEl.textContent = 'Matched ' + matchedCount + ' / ' + required.length;
    proceedBtn.disabled = matchedCount !== required.length;
  }

  function processRawValue(rawValue, method) {
    const shown = String(rawValue || '').trim();
    if (lastEl) lastEl.value = shown;
    const extracted = printExtractRollNoFromQr(rawValue);
    const norm = printNormalizeRollNo(extracted);
    if (!norm) {
      printShowVerificationMessage(msgEl, 'bad', 'Could not detect a valid roll number.');
      return;
    }
    if (!rowMap[norm]) {
      printShowVerificationMessage(msgEl, 'bad', 'Scanned roll ' + extracted + ' is not assigned to this job.');
      return;
    }
    if (matched[norm]) {
      printShowVerificationMessage(msgEl, 'warn', 'Roll ' + rowMap[norm].roll_no + ' already matched.');
      return;
    }
    matched[norm] = true;
    methods[norm] = String(method || 'qr').toUpperCase();
    renderList();
    const done = Object.keys(matched).length === required.length;
    printShowVerificationMessage(msgEl, done ? 'ok' : 'info', done ? 'All assigned slitted rolls matched. You can start the job now.' : ('Matched ' + rowMap[norm].roll_no + '. Continue remaining rolls.'));
  }

  async function startScanner() {
    if (typeof Html5QrcodeScanner !== 'function') {
      printShowVerificationMessage(msgEl, 'bad', CAN_MANUAL_ROLL_ENTRY ? 'Scanner unavailable. Use manual input or photo scan.' : 'Scanner unavailable. Use photo scan.');
      return;
    }
    await printCloseVerifierScanner();
    const reader = overlay.querySelector('#fpVScanner');
    if (reader) reader.innerHTML = '';
    startBtn.style.display = 'none';
    stopBtn.style.display = '';
    printShowVerificationMessage(msgEl, 'info', 'Scanner opening...');
    try {
      FP_VERIFIER_SCANNER = new Html5QrcodeScanner('fpVScanner', {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        rememberLastUsedCamera: false,
        formatsToSupport: [
          Html5QrcodeSupportedFormats.QR_CODE,
          Html5QrcodeSupportedFormats.CODE_128,
          Html5QrcodeSupportedFormats.CODE_39,
          Html5QrcodeSupportedFormats.EAN_13,
          Html5QrcodeSupportedFormats.EAN_8
        ]
      }, false);
      FP_VERIFIER_SCANNER.render(function(decodedText) {
        processRawValue(decodedText, 'qr');
      }, function() {});
      printShowVerificationMessage(msgEl, 'info', 'Scanner started. Point camera at assigned slitted roll QR code.');
    } catch (err) {
      startBtn.style.display = '';
      stopBtn.style.display = 'none';
      printShowVerificationMessage(msgEl, 'bad', 'Camera could not start. Allow camera permission and retry.');
    }
  }

  async function stopScanner() {
    await printCloseVerifierScanner();
    startBtn.style.display = '';
    stopBtn.style.display = 'none';
    printShowVerificationMessage(msgEl, 'info', CAN_MANUAL_ROLL_ENTRY ? 'Scanner stopped. You can use manual input or restart scanner.' : 'Scanner stopped. Restart scanner to continue verification.');
  }

  async function scanFromImageFile(file) {
    if (!file) return;
    if (typeof Html5Qrcode !== 'function') {
      printShowVerificationMessage(msgEl, 'bad', 'Image scan library is unavailable.');
      return;
    }
    const tempId = 'fpVTmp_' + Date.now();
    const temp = document.createElement('div');
    temp.id = tempId;
    temp.style.display = 'none';
    document.body.appendChild(temp);
    const qr = new Html5Qrcode(tempId);
    try {
      const decoded = await qr.scanFile(file, true);
      processRawValue(decoded, 'qr');
      printShowVerificationMessage(msgEl, 'ok', 'QR decoded from image successfully.');
    } catch (err) {
      printShowVerificationMessage(msgEl, 'bad', 'Could not decode QR from image. Try a clearer photo or live scan.');
    } finally {
      try { await qr.clear(); } catch (e) {}
      if (temp.parentNode) temp.parentNode.removeChild(temp);
    }
  }

  renderList();
  setTimeout(function() { startScanner(); }, 150);

  return new Promise(function(resolve) {
    let done = false;
    function finish(result) {
      if (done) return;
      done = true;
      printCloseVerifierScanner().finally(function() {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        resolve(result);
      });
    }

    overlay.querySelector('#fpVClose').addEventListener('click', function() { finish({ ok: false, error: 'Verification cancelled.' }); });
    overlay.querySelector('#fpVCancel').addEventListener('click', function() { finish({ ok: false, error: 'Verification cancelled.' }); });
    overlay.querySelector('#fpVProceed').addEventListener('click', function() {
      if (Object.keys(matched).length !== required.length) {
        printShowVerificationMessage(msgEl, 'bad', 'All assigned slitted rolls must be matched before start.');
        return;
      }
      const verified = required.filter(function(r) { return !!matched[r.norm]; }).map(function(r) { return r.roll_no; });
      finish({ ok: true, verified_rolls: verified });
    });
    startBtn.addEventListener('click', startScanner);
    stopBtn.addEventListener('click', stopScanner);
    overlay.querySelector('#fpVPhoto').addEventListener('click', function() { fileInput.click(); });
    fileInput.addEventListener('change', function() {
      const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      scanFromImageFile(file);
      fileInput.value = '';
    });
    overlay.querySelector('#fpVAdd').addEventListener('click', function() {
      if (!CAN_MANUAL_ROLL_ENTRY) {
        printShowVerificationMessage(msgEl, 'bad', 'Manual roll entry is disabled for this account. Please scan QR.');
        return;
      }
      const raw = String(manualEl.value || '').trim();
      if (!raw) {
        printShowVerificationMessage(msgEl, 'warn', 'Enter a roll number first.');
        return;
      }
      processRawValue(raw, 'manual');
      manualEl.value = '';
      manualEl.focus();
    });
    manualEl.addEventListener('keydown', function(ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (!CAN_MANUAL_ROLL_ENTRY) {
          printShowVerificationMessage(msgEl, 'bad', 'Manual roll entry is disabled for this account. Please scan QR.');
          return;
        }
        overlay.querySelector('#fpVAdd').click();
      }
    });
  });
}

async function startJobWithTimer(id) {
  const allowStart = await erpConfirmAsync('Start this job?', { title: 'Confirm Job Start', okLabel: 'Start', cancelLabel: 'Cancel' });
  if (!allowStart) return;
  const job = ALL_JOBS.find(j => j.id == id) || null;
  const waitingAdditionalSlitting = !!(job && job.extra_data_parsed && job.extra_data_parsed.flexo_waiting_additional_slitting);
  if (waitingAdditionalSlitting) {
    erpToast('This job is waiting for additional slitting completion. Please complete the pending task first.', 'warning');
    return;
  }
  const timerState = String((job && job.extra_data_parsed && job.extra_data_parsed.timer_state) || '').toLowerCase();
  const isResumeFromPause = !!job && String(job.status || '') === 'Running' && timerState === 'paused';
  let verifiedRolls = [];
  if (!isResumeFromPause) {
    const verification = await printOpenRollVerification(job || { id: id, job_no: id, slitting_rolls: [] });
    if (!verification.ok) {
      erpToast(verification.error || 'Assigned roll verification is required before start.', 'warning');
      return;
    }

    verifiedRolls = Array.isArray(verification.verified_rolls)
      ? verification.verified_rolls.map(function(v) { return String(v || '').trim(); }).filter(Boolean)
      : [];
    if (!verifiedRolls.length) {
      erpToast('Roll verification did not capture any required roll. Please verify again.', 'warning');
      return;
    }
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'update_status');
  fd.append('job_id', id);
  fd.append('status', 'Running');
  if (!isResumeFromPause) {
    fd.append('verified_rolls_json', JSON.stringify(verifiedRolls));
    fd.append('verified_rolls_csv', verifiedRolls.join(','));
    fd.append('verified_rolls_count', String(verifiedRolls.length));
    verifiedRolls.forEach(function(rollNo) {
      fd.append('verified_rolls[]', rollNo);
    });
    fd.append('verified_rolls_mode', CAN_MANUAL_ROLL_ENTRY ? 'qr_manual' : 'qr_only');
  }
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { erpToast('Error: ' + (data.error || 'Unknown'), 'error'); return; }
  } catch (err) { erpToast('Network error: ' + err.message, 'error'); return; }

  _timerJobId = id;
  _timerStart = Date.now();
  const activeJob = ALL_JOBS.find(j => j.id == id) || {};
  const nowIso = new Date().toISOString();
  // Update ALL_JOBS so openPrintDetail sees Running status
  activeJob.status = 'Running';
  if (!activeJob.started_at) activeJob.started_at = nowIso;
  activeJob.extra_data_parsed = activeJob.extra_data_parsed || {};
  if (!activeJob.extra_data_parsed.timer_started_at) {
    activeJob.extra_data_parsed.timer_started_at = nowIso;
    pushPrintTimerEventLocal(activeJob.extra_data_parsed, 'start', nowIso);
  } else if (String(activeJob.extra_data_parsed.timer_state || '').toLowerCase() === 'paused') {
    const pausedAt = activeJob.extra_data_parsed.timer_pause_started_at || activeJob.extra_data_parsed.timer_paused_at || '';
    if (pausedAt) {
      pushPrintTimerSegmentLocal(activeJob.extra_data_parsed, 'timer_pause_segments', pausedAt, nowIso);
    }
    pushPrintTimerEventLocal(activeJob.extra_data_parsed, 'resume', nowIso);
  }
  activeJob.extra_data_parsed.timer_active = true;
  activeJob.extra_data_parsed.timer_state = 'running';
  activeJob.extra_data_parsed.timer_last_resumed_at = nowIso;
  activeJob.extra_data_parsed.timer_paused_at = '';
  activeJob.extra_data_parsed.timer_pause_started_at = '';
  activeJob.extra_data_parsed.timer_ended_at = '';
  showPrintTimerOverlay(activeJob);
}

async function cancelTimer() {
  if (!_timerJobId) return;
  const ok = await erpConfirmAsync('Cancel will reset this job timer and return it to Pending. Continue?', {
    title: 'Cancel Timer',
    okLabel: 'Confirm',
    cancelLabel: 'Cancel'
  });
  if (!ok) return;
  try {
    await resetPrintTimer(_timerJobId);
  } catch (err) {
    erpToast('Timer reset failed: ' + err.message, 'error');
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('fpTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

async function pauseTimer() {
  const jobId = _timerJobId;
  if (!jobId) return;
  try {
    await pausePrintTimer(jobId);
  } catch (err) {
    erpToast('Timer pause failed: ' + err.message, 'error');
    return;
  }
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const ov = document.getElementById('fpTimerOverlay');
  if (ov) ov.remove();
  _timerJobId = null;
  location.reload();
}

let _uploadedPhotoUrl = '';

function endTimer() {
  if (_timerInterval) clearInterval(_timerInterval);
  _timerInterval = null;
  const jobId = _timerJobId;
  _timerJobId = null;
  finalizePrintTimer(jobId);
}

function buildPrintingExtraDataFromForm(job, form) {
  if (!form) return null;
  const rollRows = Array.from(form.querySelectorAll('[data-roll-row]')).map((row, idx) => ({
    idx: idx + 1,
    roll_no: String(row.dataset.rollNo || '').trim(),
    parent_roll_no: String(row.dataset.parentRollNo || '').trim(),
    slitted_width: String(row.dataset.slittedWidth || '').trim(),
    slitted_length: String(row.dataset.slittedLength || '').trim(),
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
    anilox_value: normalizeAniloxLaneValue(getFieldVal(row, 'anilox_lane_value_' + idx)),
    anilox_custom: '',
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
    anilox_lanes: laneRows.map(r => String(normalizeAniloxLaneValue(r.anilox_value || 'None')).trim()),
    color_anilox_rows: laneRows,
    physical_print_photo_url: getFieldVal(form, 'physical_print_photo_url'),
    physical_print_photo_path: getFieldVal(form, 'physical_print_photo_path')
  });

  return extraData;
}

// ─── Submit operator extra data + complete ──────────────────
async function submitAndComplete(id) {
  const job = ALL_JOBS.find(j => j.id == id) || {};
  if (isPrintTimerActive(job)) {
    erpToast('Timer is still running. Please End the timer before finishing this job.', 'warning');
    return;
  }
  const form = document.getElementById('dm-operator-form');
  if (!form) return updateFPStatus(id, 'Completed');
  if (!validateAniloxLaneQuantities(form)) return;
  const extraData = buildPrintingExtraDataFromForm(job, form);
  if (!extraData) return updateFPStatus(id, 'Completed');

  const fd1 = new FormData();
  fd1.append('csrf_token', CSRF);
  fd1.append('action', 'submit_extra_data');
  fd1.append('job_id', id);
  fd1.append('extra_data', JSON.stringify(extraData));
  try {
    const r1 = await fetch(API_BASE, { method: 'POST', body: fd1 });
    const d1 = await r1.json();
    if (!d1.ok) { erpToast('Save error: ' + (d1.error||'Unknown'), 'error'); return; }
  } catch(e) { erpToast('Network error', 'error'); return; }

  await updateFPStatus(id, 'Completed');
}

// ─── Regenerate job card with reason (admin) ───────────────
async function regenerateJobCard(id) {
  if (!IS_ADMIN) { erpToast('Access denied. Only system admin can regenerate job cards.', 'error'); return; }
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) { erpToast('Job not found.', 'error'); return; }

  const reason = await erpPromptAsync('Reason for regeneration (required):', 'Planning update / roll correction', {
    title: 'Regenerate Job Card',
    okLabel: 'Next'
  });
  if (reason === null) return;
  const reasonText = String(reason || '').trim();
  if (!reasonText) { erpToast('Reason is required.', 'warning'); return; }

  const notesAppend = await erpPromptAsync('Describe what changed (optional):', '', {
    title: 'Regeneration Notes',
    okLabel: 'Next'
  });
  if (notesAppend === null) return;

  const currentRoll = String(job.roll_no || '').trim();
  const newRollPrompt = await erpPromptAsync('Roll No change (optional). Keep blank to retain current roll:', currentRoll, {
    title: 'Roll Update',
    okLabel: 'Continue'
  });
  if (newRollPrompt === null) return;
  const newRoll = String(newRollPrompt || '').trim();

  let changes = {};
  const form = document.getElementById('dm-operator-form');
  if (form) {
    const payload = buildPrintingExtraDataFromForm(job, form);
    if (payload) changes = payload;
  }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'regenerate_job_card');
  fd.append('job_id', String(id));
  fd.append('reason', reasonText);
  fd.append('notes_append', String(notesAppend || '').trim());
  if (newRoll !== '' && newRoll !== currentRoll) {
    fd.append('roll_no', newRoll);
  }
  fd.append('changes_json', JSON.stringify(changes));

  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) {
      erpToast('Regenerate failed: ' + (data.error || 'Unknown error'), 'error');
      return;
    }
    erpToast('Job card regenerated successfully. Same job number kept and status reset to Pending.', 'success');
    location.reload();
  } catch (err) {
    erpToast('Network error: ' + err.message, 'error');
  }
}

function renderLaneSelectOptions(selected) {
  const opts = ['None', 'Cyan', 'Magenta', 'Yellow', 'Black', 'P1', 'P2', 'P3', 'P4', 'UV', 'Other'];
  return opts.map(o => `<option value="${o}"${String(selected||'')===o?' selected':''}>${o}</option>`).join('');
}

function renderAniloxOptionsLegacyCompat(selected) {
  return renderAniloxOptions(selected);
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
      erpToast('Image upload failed: ' + (data.error || 'Unknown'), 'error');
      return;
    }
    const img = document.getElementById('physical-photo-preview');
    if (img) img.src = data.photo_url || '';
    const f1 = document.querySelector('[name=physical_print_photo_url]');
    const f2 = document.querySelector('[name=physical_print_photo_path]');
    if (f1) f1.value = data.photo_url || '';
    if (f2) f2.value = data.photo_path || '';
  } catch (err) {
    erpToast('Image upload network error: ' + err.message, 'error');
  } finally {
    input.disabled = false;
  }
}

let voiceRec = null;
function startVoiceToField(fieldName, btn) {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    erpToast('Voice input is not supported in this browser.', 'warning');
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
  const timerActive = isPrintTimerActive(job);
  const timerState = String((job.extra_data_parsed && job.extra_data_parsed.timer_state) || '').toLowerCase();
  const createdAt = formatDateTimeText(job.created_at);
  const startedAt = formatDateTimeText(extra.timer_started_at || job.started_at);
  const completedAt = formatDateTimeText(extra.timer_ended_at || job.completed_at);
  const activeSeconds = timerActive ? printTimerTotalSeconds(job) : printDurationSeconds(job);
  const pauseSeconds = printPauseTotalSeconds(extra);
  const startedTs = (() => {
    const resumedRaw = extra.timer_last_resumed_at || extra.timer_started_at || job.started_at || '';
    const parsed = resumedRaw ? Date.parse(String(resumedRaw).replace(' ', 'T')) : NaN;
    return Number.isFinite(parsed) ? parsed : 0;
  })();
  const prevDone = !job.previous_job_id || !job.prev_job_status || ['Completed','QC Passed','Closed','Finalized'].includes(job.prev_job_status);
  const elapsedSec = timerActive ? printTimerTotalSeconds(job) : activeSeconds;
  const flexoReqId = Number(job.flexo_latest_request_id || 0);
  const flexoReqStatus = String(job.flexo_latest_request_status || '').trim();
  const flexoReqPendingCount = Number(job.flexo_pending_request_count || 0);
  const flexoReqPayload = (job.flexo_latest_request_payload && typeof job.flexo_latest_request_payload === 'object') ? job.flexo_latest_request_payload : {};
  const hasPendingFlexoReq = flexoReqStatus === 'Pending' || flexoReqPendingCount > 0;
  const hasApprovedFlexoReq = flexoReqStatus === 'Approved' && flexoReqId > 0;
  const hasLockedFlexoReq = hasPendingFlexoReq || hasApprovedFlexoReq;
  const requestDraft = loadFlexoRequestDraft(job.id, flexoReqPayload);
  const extensionTasks = Array.isArray(extra.flexo_extension_tasks) ? extra.flexo_extension_tasks : [];
  const pendingAdditionalTasks = extensionTasks
    .map((t, idx) => Object.assign({ _idx: idx }, t || {}))
    .filter(t => String(t.type || '').toLowerCase() === 'additional_slitting' && String(t.status || '').toLowerCase() === 'pending');
  const isWaitingAdditionalSlitting = !!extra.flexo_waiting_additional_slitting || pendingAdditionalTasks.length > 0;
  const waitingAdditionalReason = String(extra.flexo_waiting_additional_slitting_reason || '').trim();

  const rollRows = (Array.isArray(card.roll_wastage_rows) && card.roll_wastage_rows.length ? card.roll_wastage_rows : card.material_rows).map((row, idx) => ({
    idx,
    roll_no: String(row.roll_no || (idx === 0 ? (job.roll_no || '') : '')).trim(),
    parent_roll_no: String(row.parent_roll_no || '').trim(),
    material_company: String(row.material_company || card.material_company || job.company || '').trim(),
    material_name: String(row.material_name || card.material_name || job.paper_type || '').trim(),
    slitted_width: row.slitted_width ?? '',
    slitted_length: row.slitted_length ?? '',
    order_mtr: row.order_mtr ?? card.order_mtr ?? '',
    order_qty: row.order_qty ?? card.order_qty ?? '',
    color_match_status: String(row.color_match_status || card.color_match_status || 'Matched').trim(),
    wastage_meters: row.wastage_meters ?? '',
  }));

  const totalAllocatedMtr = parseFloat(card.order_mtr) || 0;
  const totalAssignedMtr = rollRows.reduce((sum, r) => sum + (parseFloat(r.slitted_length) || 0), 0);
  const mtrMatch = totalAllocatedMtr > 0 && totalAssignedMtr >= totalAllocatedMtr;
  const mtrShort = totalAllocatedMtr > 0 ? totalAllocatedMtr - totalAssignedMtr : 0;

  const laneRows = Array.from({ length: 8 }, (_, i) => {
    const lr = Array.isArray(card.color_anilox_rows) ? (card.color_anilox_rows[i] || {}) : {};
    const colorCode = String(lr.color_code || card.colour_lanes[i] || 'None').trim() || 'None';
    const anValRaw = normalizeAniloxLaneValue(String(lr.anilox_value || card.anilox_lanes[i] || 'None').trim() || 'None');
    const fallbackColorName = ['Cyan','Magenta','Yellow','Black'].includes(colorCode) ? colorCode : (colorCode === 'None' ? 'None' : '');
    return {
      lane: i + 1,
      color_code: colorCode,
      color_name: String(lr.color_name || fallbackColorName).trim(),
      anilox_value: anValRaw,
      anilox_custom: '',
    };
  });

  const planningImage = String(job.planning_image_url || '').trim();
  const plateImage = String(job.plate_image_url || '').trim();
  const referenceImage = String(job.job_preview_image_url || planningImage || plateImage || '').trim();
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

  if (isWaitingAdditionalSlitting) {
    html += `<div class="fp-detail-section" style="padding:12px;background:#fffbeb;border-radius:10px;border-left:4px solid #f59e0b">
      <div style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem;font-weight:700;color:#9a3412;line-height:1.45">
        <i class="bi bi-hourglass-split" style="color:#d97706"></i>
        <div>
          <div>Job is waiting for additional slitting completion before printing can restart.</div>
          ${waitingAdditionalReason ? `<div style="font-size:.72rem;color:#b45309;margin-top:2px">Reason: ${esc(waitingAdditionalReason)}</div>` : ''}
        </div>
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

  html += `<div class="fp-op-topstrip" style="grid-template-columns:repeat(6,minmax(0,1fr))">
    <div class="fp-op-topitem"><div class="k">Created</div><div class="v">${esc(createdAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Started</div><div class="v">${esc(startedAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Completed</div><div class="v">${esc(completedAt)}</div></div>
    <div class="fp-op-topitem"><div class="k">Active Time</div><div class="v">${activeSeconds > 0 ? esc(formatDurationText(activeSeconds)) : '—'}</div></div>
    <div class="fp-op-topitem"><div class="k">Pause Time</div><div class="v">${pauseSeconds > 0 ? esc(formatDurationText(pauseSeconds)) : '—'}</div></div>
    <div class="fp-op-topitem"><div class="k">Total Time</div><div class="v">${(activeSeconds + pauseSeconds) > 0 ? esc(formatDurationText(activeSeconds + pauseSeconds)) : '—'}</div></div>
  </div>`;

  html += `<div class="fp-detail-section">${buildPrintTimerHistoryHtml(job)}</div>`;

  html += `<div class="fp-detail-section fp-op-shell"><div class="fp-op-form">
    <div class="fp-op-section"><div class="fp-op-h">Job Information</div><div class="fp-op-b fp-op-grid-4">
      <div class="fp-op-field"><label>Job No</label><input type="text" value="${esc(job.job_no||'—')}" readonly></div>
      <div class="fp-op-field"><label>Job Name</label><input type="text" value="${esc(resolvePrintDisplayName(job))}" style="font-size:1.02rem;font-weight:900;color:#0f172a" readonly></div>
      <div class="fp-op-field"><label>Department</label><input type="text" value="Flexo Printing" readonly></div>
      <div class="fp-op-field"><label>Priority / Sequence</label><input type="text" value="${esc((job.planning_priority||'Normal') + ' / #' + (job.sequence_order||2))}" readonly></div>
    </div></div>
  </div></div>`;

  if (IS_OPERATOR_VIEW && (sts === 'Running' || mode === 'complete' || sts === 'Pending')) {
    html += `<div class="fp-detail-section fp-op-shell"><h3><i class="bi bi-pencil-square"></i> Operator Data — Fill Before Completing</h3>
    <form id="dm-operator-form" class="fp-op-form">
      <div class="fp-op-section"><div class="fp-op-h">Locked Planning Fields</div><div class="fp-op-b fp-op-grid-3">
        <div class="fp-op-field"><label>Job Name</label><input type="text" name="job_name_locked" value="${esc(card.job_name||resolvePrintDisplayName(job))}" style="font-size:1.02rem;font-weight:900;color:#0f172a" readonly></div>
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
        <div class="fp-op-roll-wrap"><table class="fp-op-roll-table"><thead><tr><th>#</th><th>Roll No</th><th>Material</th><th>Slitted Size</th><th>Length (m)</th><th>Color Match</th><th>Wastage (m)</th></tr></thead><tbody>
          ${rollRows.map((r, idx) => `<tr data-roll-row data-roll-no="${esc(r.roll_no)}" data-parent-roll-no="${esc(r.parent_roll_no||'')}" data-slitted-width="${esc(r.slitted_width||'')}" data-slitted-length="${esc(r.slitted_length||'')}" data-material-company="${esc(r.material_company)}" data-material-name="${esc(r.material_name)}" data-order-mtr="${esc(r.order_mtr)}" data-order-qty="${esc(r.order_qty)}"><td>${idx+1}</td><td style="font-weight:700;color:var(--fp-brand)">${esc(r.roll_no||'—')}</td><td>${esc(r.material_name||'—')}</td><td>${r.slitted_width ? esc(r.slitted_width+'mm') : '—'}</td><td style="font-weight:700">${r.slitted_length ? esc(r.slitted_length) : '—'}</td><td><select name="color_match_status_${idx}"><option value="Matched"${r.color_match_status==='Matched'?' selected':''}>Matched</option><option value="Slight Variation"${r.color_match_status==='Slight Variation'?' selected':''}>Slight Variation</option><option value="Mismatch"${r.color_match_status==='Mismatch'?' selected':''}>Mismatch</option></select></td><td><input type="number" step="0.01" name="roll_wastage_${idx}" value="${esc(r.wastage_meters||'')}" placeholder="0.00"></td></tr>`).join('')}
        </tbody>
        <tfoot>
          <tr><td colspan="4" style="text-align:right;font-weight:800;padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0">Total Assigned Roll MTR</td><td style="font-weight:900;padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0">${totalAssignedMtr}</td><td colspan="2" style="padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0"></td></tr>
          <tr><td colspan="4" style="text-align:right;font-weight:800;padding:8px 10px;background:#f0fdf4">Job Allocated MTR</td><td style="font-weight:900;padding:8px 10px;background:#f0fdf4">${totalAllocatedMtr || '—'}</td><td colspan="2" style="padding:8px 10px;background:${mtrMatch?'#f0fdf4':'#fef3c7'};font-weight:800;color:${mtrMatch?'#16a34a':'#d97706'}"><i class="bi bi-${mtrMatch?'check-circle-fill':'exclamation-triangle-fill'}"></i> ${mtrMatch ? 'Sufficient' : (totalAllocatedMtr > 0 ? 'Short by '+mtrShort+'m' : '—')}</td></tr>
          <tr><td colspan="5"></td><td style="text-align:right;font-weight:800;padding:8px 10px;background:#f8fafc">Total Wastage</td><td style="font-weight:800;padding:8px 10px;background:#f8fafc"><input type="text" name="total_wastage_meters" value="${esc(card.total_wastage_meters||'')}" readonly style="width:80px;font-weight:800"></td></tr>
        </tfoot></table></div>
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Color + Anilox (Color 1-8)</div><div class="fp-op-b" style="gap:10px">
        ${laneRows.map((r, idx) => `<div class="fp-op-lane-row" data-lane-row><div class="fp-op-mini"><strong>Color ${idx+1}</strong></div><div style="display:grid;gap:6px"><select data-role="color-select" name="color_lane_code_${idx}">${renderLaneSelectOptions(r.color_code)}</select><input data-role="color-name" type="text" name="color_lane_name_${idx}" value="${esc(r.color_name||'')}" placeholder="Color name" style="display:none"></div><div style="display:grid;gap:6px"><select data-role="anilox-select" name="anilox_lane_value_${idx}">${renderAniloxOptions(r.anilox_value)}</select><input data-role="anilox-custom" type="text" name="anilox_lane_custom_${idx}" value="" placeholder="Custom anilox" style="display:none"></div></div>`).join('')}
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Planning / Plate vs Physical Print Image</div><div class="fp-op-b"><div class="fp-photo-grid"><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Reference Image</strong></div><img class="fp-photo-preview" src="${esc(referenceImage || '')}" alt="Reference image" onerror="this.style.opacity='0.4';this.alt='Reference image not available'"></div><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Physical Image</strong></div><img id="physical-photo-preview" class="fp-photo-preview" src="${esc(physicalImage || '')}" alt="Physical image" onerror="this.style.opacity='0.4';this.alt='Physical image not available'"><input type="hidden" name="physical_print_photo_url" value="${esc(physicalImage || '')}"><input type="hidden" name="physical_print_photo_path" value="${esc(card.physical_print_photo_path || '')}"><input type="file" id="fp-camera-input" accept="image/*" capture="environment" style="display:none" onchange="handlePrintingPhotoUpload(this, ${job.id})"></div></div></div></div>

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
        <div class="fp-op-field"><label>Job Name</label><input type="text" value="${esc(card.job_name||resolvePrintDisplayName(job))}" style="font-size:1.02rem;font-weight:900;color:#0f172a" readonly></div>
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
        <div class="fp-op-roll-wrap"><table class="fp-op-roll-table"><thead><tr><th>#</th><th>Roll No</th><th>Material</th><th>Slitted Size</th><th>Length (m)</th><th>Color Match</th><th>Wastage (m)</th></tr></thead><tbody>
          ${rollRows.map((r, idx) => `<tr><td>${idx+1}</td><td style="font-weight:700;color:var(--fp-brand)">${esc(r.roll_no||'—')}</td><td>${esc(r.material_name||'—')}</td><td>${r.slitted_width ? esc(r.slitted_width+'mm') : '—'}</td><td style="font-weight:700">${r.slitted_length ? esc(r.slitted_length) : '—'}</td><td>${esc(r.color_match_status||'—')}</td><td>${esc(String(r.wastage_meters??'—'))}</td></tr>`).join('')}
        </tbody>
        <tfoot>
          <tr><td colspan="4" style="text-align:right;font-weight:800;padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0">Total Assigned Roll MTR</td><td style="font-weight:900;padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0">${totalAssignedMtr}</td><td colspan="2" style="padding:8px 10px;background:#f8fafc;border-top:2px solid #e2e8f0"></td></tr>
          <tr><td colspan="4" style="text-align:right;font-weight:800;padding:8px 10px;background:#f0fdf4">Job Allocated MTR</td><td style="font-weight:900;padding:8px 10px;background:#f0fdf4">${totalAllocatedMtr || '—'}</td><td colspan="2" style="padding:8px 10px;background:${mtrMatch?'#f0fdf4':'#fef3c7'};font-weight:800;color:${mtrMatch?'#16a34a':'#d97706'}"><i class="bi bi-${mtrMatch?'check-circle-fill':'exclamation-triangle-fill'}"></i> ${mtrMatch ? 'Sufficient' : (totalAllocatedMtr > 0 ? 'Short by '+mtrShort+'m' : '—')}</td></tr>
          <tr><td colspan="5"></td><td style="text-align:right;font-weight:800;padding:8px 10px;background:#f8fafc">Total Wastage</td><td style="font-weight:800;padding:8px 10px;background:#f8fafc">${esc(card.total_wastage_meters || card.wastage_meters || '—')}</td></tr>
        </tfoot></table></div>
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Color + Anilox (Color 1-8)</div><div class="fp-op-b" style="gap:10px">
        ${laneRows.map((r, idx) => `<div class="fp-op-lane-row"><div class="fp-op-mini"><strong>Color ${idx+1}</strong></div><div><input type="text" value="${esc(r.color_code||'—')}" readonly style="width:100%"></div><div><input type="text" value="${esc(r.anilox_value||'—')}" readonly style="width:100%"></div></div>`).join('')}
      </div></div>

      <div class="fp-op-section"><div class="fp-op-h">Planning / Plate vs Physical Print Image</div><div class="fp-op-b"><div class="fp-photo-grid"><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Reference Image</strong></div><img class="fp-photo-preview" src="${esc(referenceImage || '')}" alt="Reference image" onerror="this.style.opacity='0.4';this.alt='Not available'"></div><div class="fp-photo-card"><div class="fp-op-mini" style="margin-bottom:6px"><strong>Physical Image</strong></div><img class="fp-photo-preview" src="${esc(physicalImage || '')}" alt="Physical image" onerror="this.style.opacity='0.4';this.alt='Not available'"></div></div></div></div>

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

  const requestStatusChip = flexoReqId > 0
    ? `<span class="fp-req-chip ${flexoRequestStatusClass(flexoReqStatus)}">${esc(flexoReqStatus || 'Pending')}</span>`
    : '<span class="fp-req-chip pending">No Request</span>';

  if (IS_OPERATOR_VIEW) {
    html += `<div id="fpFlexoRequestWrap" class="fp-detail-section fp-op-shell fp-req-attn"><div class="fp-op-form">
      <div class="fp-op-section"><div class="fp-op-h">Flexo Operator Request Function</div><div class="fp-op-b">
        <div class="fp-req-meta">Latest Request Status: ${requestStatusChip}</div>
        <div class="fp-req-note">Choose one request mode. Only one section stays active at a time.</div>
      </div>
      <div class="fp-op-b">
        <div class="fp-op-field"><label>Request Mode</label>
          <select name="fr_request_mode" form="dm-operator-form" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}>
            <option value="remaining" ${requestDraft.request_mode === 'add' ? '' : 'selected'}>Remaining Roll</option>
            <option value="add" ${requestDraft.request_mode === 'add' ? 'selected' : ''}>Add Roll</option>
          </select>
        </div>
      </div>

      <div class="fp-op-b" data-req-section="remaining" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;margin:8px">
        <div class="fp-req-meta" style="color:#1d4ed8;font-weight:900">REMAINING ROLL (Blue)</div>
        <div class="fp-req-grid-3">
          <div class="fp-op-field"><label>Source Assigned Roll</label>
          <select name="fr_source_roll_no" form="dm-operator-form" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}>
            <option value="">Select roll</option>
            ${rollRows.map(r => `<option value="${esc(r.roll_no || '')}" data-roll-length="${esc(String(toSafeNumber(r.slitted_length || 0)))}" ${(String(requestDraft.source_roll_no || '').trim() === String(r.roll_no || '').trim()) ? 'selected' : ''}>${esc(r.roll_no || '')} (${esc(String(r.slitted_length || '0'))}m)</option>`).join('')}
          </select>
          </div>
          <div class="fp-op-field"><label>Used Length (MTR)</label><input type="number" step="0.01" min="0" name="fr_used_length_mtr" form="dm-operator-form" value="${esc(requestDraft.used_length_mtr > 0 ? String(requestDraft.used_length_mtr) : '')}" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}></div>
          <div class="fp-op-field"><label>Remaining Length (MTR)</label><input type="number" step="0.01" min="0" name="fr_remaining_length_mtr" form="dm-operator-form" value="${esc(requestDraft.remaining_length_mtr > 0 ? String(requestDraft.remaining_length_mtr) : '')}" readonly ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}></div>
        </div>
      </div>

      <div class="fp-op-b" data-req-section="add" style="display:none;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;margin:8px">
        <div class="fp-req-meta" style="color:#c2410c;font-weight:900">ADD ROLL (Orange)</div>
        <div class="fp-req-grid-3">
          <div class="fp-op-field"><label>Additional Width (MM)</label><input type="number" step="0.01" min="0" name="fr_additional_width_mm" form="dm-operator-form" value="${esc(requestDraft.additional_width_mm > 0 ? String(requestDraft.additional_width_mm) : '')}" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}></div>
          <div class="fp-op-field"><label>Additional Length (MTR)</label><input type="number" step="0.01" min="0" name="fr_additional_length_mtr" form="dm-operator-form" value="${esc(requestDraft.additional_length_mtr > 0 ? String(requestDraft.additional_length_mtr) : '')}" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}></div>
          <div class="fp-op-field"><label>Reason</label>
            <select name="fr_additional_reason" form="dm-operator-form" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}>
              <option value="">Select reason</option>
              <option value="Wastage" ${(String(requestDraft.additional_reason || '') === 'Wastage') ? 'selected' : ''}>Wastage</option>
              <option value="Extra Requirement" ${(String(requestDraft.additional_reason || '') === 'Extra Requirement') ? 'selected' : ''}>Extra Requirement</option>
            </select>
          </div>
          <div class="fp-op-field" style="grid-column:1/-1"><label>Note</label><textarea name="fr_operator_request_note" form="dm-operator-form" placeholder="Why this additional roll is needed" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : ''}>${esc(requestDraft.operator_request_note || '')}</textarea></div>
        </div>
      </div>

      <div class="fp-op-b">
        <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap">
          <button type="button" class="fp-action-btn fp-btn-start" data-role="fr-submit" onclick="submitFlexoOperatorRequest(${job.id})" ${hasLockedFlexoReq ? 'disabled data-force-disabled="1"' : 'disabled'}><i class="bi bi-send"></i> Submit Request to Production</button>
        </div>
        ${hasPendingFlexoReq ? '<div class="fp-req-note">One pending request already exists for this job. Please wait for manager review.</div>' : ''}
        ${hasApprovedFlexoReq ? '<div class="fp-req-note">Latest request already approved. This section is now view-only.</div>' : ''}
      </div>
      </div></div></div>`;
  } else if (flexoReqId > 0) {
    const addReq = (flexoReqPayload && typeof flexoReqPayload.additional_roll_request === 'object') ? flexoReqPayload.additional_roll_request : {};
    const exReq = (flexoReqPayload && typeof flexoReqPayload.excess_roll_adjustment === 'object') ? flexoReqPayload.excess_roll_adjustment : {};
    const srcRoll = String(exReq.source_roll_no || '').trim();
    const srcRow = rollRows.find(r => String(r.roll_no || '').trim() === srcRoll);
    const srcLength = toSafeNumber(srcRow ? srcRow.slitted_length : 0);
    const usedLen = toSafeNumber(exReq.used_length_mtr || 0);
    const remLen = toSafeNumber(exReq.remaining_length_mtr || 0);
    const reqBy = String(job.flexo_latest_request_requested_by || '').trim();
    const reqAt = String(job.flexo_latest_request_requested_at || '').trim();
    const reviewNote = String(job.flexo_latest_request_note || '').trim();

    html += `<div class="fp-detail-section fp-op-shell fp-req-attn"><div class="fp-op-form">
      <div class="fp-op-section"><div class="fp-op-h">Flexo Operator Request Review</div><div class="fp-op-b">
        <div class="fp-req-meta">Request #${esc(String(flexoReqId))} • Status: ${requestStatusChip}</div>
        <div class="fp-req-note">Requested by: <strong>${esc(reqBy || '—')}</strong> at <strong>${esc(formatDateTimeText(reqAt))}</strong></div>
        ${reviewNote ? `<div class="fp-req-note">Review Note: ${esc(reviewNote)}</div>` : ''}
      </div>
      ${addReq && addReq.enabled ? `<div class="fp-op-b" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px">
        <div class="fp-req-meta" style="color:#c2410c;font-weight:900">Type: ADD ROLL</div>
        <div class="fp-req-grid-3" style="margin-top:8px">
          <div class="fp-op-field"><label>Width (MM)</label><div class="fp-req-diff" style="border-color:#fed7aa;background:#fff7ed;color:#9a3412">${esc(String(addReq.width_mm || 0))}</div></div>
          <div class="fp-op-field"><label>Length (MTR)</label><div class="fp-req-diff" style="border-color:#fed7aa;background:#fff7ed;color:#9a3412">${esc(String(addReq.length_mtr || 0))}</div></div>
          <div class="fp-op-field"><label>Reason</label><div class="fp-req-diff" style="border-color:#fed7aa;background:#fff7ed;color:#9a3412">${esc(String(addReq.reason || ''))}</div></div>
        </div>
      </div>` : ''}
      ${exReq && exReq.enabled ? `<div class="fp-op-b" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px">
        <div class="fp-req-meta" style="color:#1d4ed8;font-weight:900">Type: REMAINING ROLL</div>
        <div class="fp-req-grid-3" style="margin-top:8px">
        <div class="fp-op-field"><label>Source Assigned Roll</label><div class="fp-req-diff" style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8">${esc(srcRoll || '—')}</div></div>
        <div class="fp-op-field"><label>Original Length (mtr)</label><div class="fp-op-field"><input value="${esc(String(srcLength || 0))}" readonly></div></div>
        <div class="fp-op-field"><label>Used Length (mtr)</label><div class="fp-req-diff" style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8">${esc(String(usedLen || 0))}</div></div>
      </div></div>
      <div class="fp-op-b fp-req-grid-2" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px">
        <div class="fp-op-field"><label>Remaining Length (mtr)</label><div class="fp-req-diff" style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8">${esc(String(remLen || 0))}</div></div>
        <div class="fp-op-field"><label>Expected Remaining Stock Roll</label><div class="fp-req-diff">${esc(srcRoll ? (srcRoll + '-1 / -2 ...') : 'Numeric suffix')}</div></div>
      </div>` : ''}
      ${pendingAdditionalTasks.length ? `<div class="fp-op-b">
        <div class="fp-req-meta">Pending Additional Slitting Tasks (Same Job Card)</div>
        ${pendingAdditionalTasks.map(t => `<div style="border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:10px;margin-top:8px">
          <div class="fp-req-note"><strong>Task #${esc(String(t._idx + 1))}</strong> • Width: ${esc(String(t.width_mm || 0))} mm • Length: ${esc(String(t.length_mtr || 0))} mtr • Status: ${esc(String(t.status || 'Pending'))}</div>
          <div class="fp-req-note">Reason: ${esc(String(t.reason || ''))}</div>
          <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:8px"><button type="button" class="fp-action-btn fp-btn-start" onclick="openFlexoSlittingWorkspace(${job.id}, ${t._idx}, ${flexoReqId})"><i class="bi bi-scissors"></i> Open Slitting Interface</button><button type="button" class="fp-action-btn fp-btn-view" onclick="completeFlexoAdditionalSlitting(${job.id}, ${t._idx})"><i class="bi bi-check2-square"></i> Mark Completed</button></div>
        </div>`).join('')}
      </div>` : ''}
      </div></div></div>`;
  }

  document.getElementById('dm-body').innerHTML = html;
  updateTimers();
  const operatorFormEl = document.getElementById('dm-operator-form');
  bindFlexoFormBehavior(operatorFormEl);

  if (IS_OPERATOR_VIEW && operatorFormEl) {
    const isPausedState = sts === 'Running' && timerState === 'paused';
    const isCompleteState = mode === 'complete';
    const isBeforeStart = sts === 'Pending' && !isCompleteState && !isWaitingAdditionalSlitting;
    const showRequestSection = !isBeforeStart;
    const mainEditable = isCompleteState;
    const reqEditable = !hasLockedFlexoReq && (isPausedState || isCompleteState);
    const reqWrap = document.getElementById('fpFlexoRequestWrap');

    applyOperatorModalEditState({
      form: operatorFormEl,
      reqWrap: reqWrap,
      mainEditable: mainEditable,
      reqEditable: reqEditable,
      showRequest: showRequestSection,
    });

    bindFlexoRequestAutoCalc(job.id, operatorFormEl, { autoComputeOnInit: true });
    updateFlexoRequestButtonStates(job.id);
  }

  // Footer actions
  let fHtml = '<div style="display:flex;gap:8px">';
  fHtml += `<button class="fp-action-btn fp-btn-print" onclick="printJobCard(${job.id})"><i class="bi bi-printer"></i> Print</button>`;
  fHtml += '</div><div style="display:flex;gap:8px">';
  if (mode === 'complete' && IS_OPERATOR_VIEW && !timerActive && timerState !== 'paused') {
    fHtml += `<button class="fp-action-btn fp-btn-complete" onclick="submitAndComplete(${job.id})"><i class="bi bi-check-lg"></i> Complete & Submit</button>`;
  } else if (sts === 'Pending' && prevDone && IS_OPERATOR_VIEW) {
    if (isWaitingAdditionalSlitting) {
      fHtml += `<button class="fp-action-btn fp-btn-start" disabled><i class="bi bi-hourglass-split"></i> Waiting Additional Slitting</button>`;
    } else {
      fHtml += `<button class="fp-action-btn fp-btn-start" onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-fill"></i> Start Job</button>`;
    }
  } else if (sts === 'Pending' && !prevDone) {
    fHtml += `<button class="fp-action-btn fp-btn-start" disabled><i class="bi bi-lock-fill"></i> Waiting for Slitting</button>`;
  } else if (sts === 'Running' && IS_OPERATOR_VIEW) {
    if (timerState === 'paused') {
      fHtml += `<button class="fp-action-btn fp-btn-start" data-role="again-start" ${hasPendingFlexoReq ? 'disabled data-force-disabled="1" title="Request is active. Wait for review."' : ''} onclick="startJobWithTimer(${job.id})"><i class="bi bi-play-circle"></i> Again Start</button>`;
    } else if (timerActive) {
      fHtml += `<button class="fp-action-btn fp-btn-start" onclick="resumeRunningPrintTimer(${job.id})"><i class="bi bi-play-circle"></i> Open Timer</button>`;
    } else {
      fHtml += `<button class="fp-action-btn fp-btn-complete" onclick="openPrintDetail(${job.id},'complete')"><i class="bi bi-check-lg"></i> Complete</button>`;
    }
  }
  if (!IS_OPERATOR_VIEW && CAN_REVIEW_FLEXO_REQUESTS && flexoReqId > 0 && flexoReqStatus === 'Pending') {
    const reqType = getFlexoRequestType(flexoReqPayload);
    if (reqType === 'remaining_roll') {
      fHtml += `<button class="fp-action-btn fp-btn-start" onclick="openFlexoProductionUpdate(${flexoReqId}, ${job.id})"><i class="bi bi-arrow-repeat"></i> Update Roll Request</button>`;
    } else {
      fHtml += `<button class="fp-action-btn fp-btn-start" onclick="openFlexoProductionUpdate(${flexoReqId}, ${job.id})"><i class="bi bi-scissors"></i> Slitting</button>`;
    }
    fHtml += `<button class="fp-action-btn fp-btn-reject" onclick="reviewFlexoOperatorRequest(${flexoReqId}, 'Rejected', ${job.id})"><i class="bi bi-x-circle"></i> Reject Request</button>`;
  }
  if (IS_ADMIN) {
    fHtml += `<button class="fp-action-btn fp-btn-start" onclick="regenerateJobCard(${job.id})" title="Regenerate with reason"><i class="bi bi-arrow-repeat"></i> Regenerate</button>`;
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
  if (!IS_ADMIN) { erpToast('Access denied. Only system admin can delete job cards.', 'error'); return; }
  const ok = await erpConfirmAsync('Delete this job card? If linked reset logic applies, related queued jobs may also be rolled back.', {
    title: 'Confirm Delete',
    okLabel: 'Delete',
    cancelLabel: 'Cancel'
  });
  if (!ok) return;
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'delete_job');
  fd.append('job_id', id);
  try {
    const res = await fetch(API_BASE, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) location.reload();
    else erpToast('Error: ' + (data.error || 'Unknown'), 'error');
  } catch (err) { erpToast('Network error: ' + err.message, 'error'); }
}

// ─── Print Mode Chooser & B&W Transform ────────────────────
function choosePrintMode() {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `<div style="background:#fff;border-radius:16px;padding:28px 32px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center;font-family:'Segoe UI',Arial,sans-serif">
      <div style="font-size:1.1rem;font-weight:900;color:#0f172a;margin-bottom:4px">Print Mode</div>
      <div style="font-size:.78rem;color:#64748b;margin-bottom:20px">Select print mode for best output quality</div>
      <div style="display:flex;gap:12px;justify-content:center">
        <button id="_pm_color" style="flex:1;padding:16px 12px;border:2px solid #22c55e;border-radius:12px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);cursor:pointer;transition:all .15s">
          <div style="font-size:1.5rem;margin-bottom:6px">🎨</div>
          <div style="font-weight:800;font-size:.88rem;color:#166534">Color</div>
          <div style="font-size:.65rem;color:#64748b;margin-top:2px">Full color print</div>
        </button>
        <button id="_pm_bw" style="flex:1;padding:16px 12px;border:2px solid #64748b;border-radius:12px;background:linear-gradient(135deg,#f8fafc,#e2e8f0);cursor:pointer;transition:all .15s">
          <div style="font-size:1.5rem;margin-bottom:6px">⬛</div>
          <div style="font-weight:800;font-size:.88rem;color:#0f172a">Black & White</div>
          <div style="font-size:.65rem;color:#64748b;margin-top:2px">High contrast B&W</div>
        </button>
      </div>
      <button id="_pm_cancel" style="margin-top:16px;padding:8px 24px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b;font-size:.76rem;font-weight:700;cursor:pointer">Cancel</button>
    </div>`;
    document.body.appendChild(overlay);
    document.getElementById('_pm_color').onclick = () => { document.body.removeChild(overlay); resolve('color'); };
    document.getElementById('_pm_bw').onclick = () => { document.body.removeChild(overlay); resolve('bw'); };
    document.getElementById('_pm_cancel').onclick = () => { document.body.removeChild(overlay); resolve(null); };
    overlay.onclick = e => { if (e.target === overlay) { document.body.removeChild(overlay); resolve(null); } };
  });
}

function printBwTransform(html) {
  const map = {
    '166534':'000000','15803d':'000000','16a34a':'000000',
    '1e40af':'000000','5b21b6':'000000','6d28d9':'000000',
    'a16207':'000000','92400e':'000000','d97706':'333333',
    '0f766e':'000000','9d174d':'000000','0891b2':'333333',
    'dcfce7':'e0e0e0','f0fdf4':'f0f0f0','d1fae5':'e0e0e0',
    'dbeafe':'d8d8d8','ede9fe':'e0e0e0',
    'fef3c7':'e8e8e8','fffde7':'f0f0f0',
    'e0f7fa':'e8e8e8','fce4ec':'e8e8e8','eceff1':'e0e0e0','fee2e2':'e8e8e8',
    'bbf7d0':'999999','bfdbfe':'999999','c4b5fd':'999999',
    'd1e7dd':'999999','bae6fd':'999999','fcd34d':'999999',
    'cbd5e1':'aaaaaa','e2e8f0':'bbbbbb',
    'f8fafc':'f2f2f2','f1f5f9':'efefef',
  };
  let result = html;
  for (const [from, to] of Object.entries(map)) {
    result = result.replace(new RegExp('#' + from, 'gi'), '#' + to);
  }
  return result;
}

// ─── Print Job Card ─────────────────────────────────────────
async function printJobCard(id) {
  const mode = await choosePrintMode();
  if (!mode) return;
  const job = ALL_JOBS.find(j => j.id == id);
  if (!job) return;
  const extra = job.extra_data_parsed || {};
  const card = normalizeCardData(job, extra);
  const qrUrl = `${BASE_URL}/modules/scan/job.php?jn=${encodeURIComponent(job.job_no)}`;
  const qrDataUrl = await generateQR(qrUrl);
  let html = renderPrintCardHtml(job, card, extra, qrDataUrl);
  if (mode === 'bw') html = printBwTransform(html);
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
  const totalAllocatedMtr = parseFloat(card.order_mtr) || 0;
  const totalAssignedMtr = rollRows.reduce((sum, r) => sum + (parseFloat(r.slitted_length) || 0), 0);
  const mtrMatch = totalAllocatedMtr > 0 && totalAssignedMtr >= totalAllocatedMtr;
  const mtrShort = totalAllocatedMtr > 0 ? totalAllocatedMtr - totalAssignedMtr : 0;
  const laneRows = Array.isArray(card.color_anilox_rows) && card.color_anilox_rows.length ? card.color_anilox_rows : Array.from({ length: 8 }, (_, i) => ({
    lane: i + 1,
    color_code: card.colour_lanes[i] || ['Cyan', 'Magenta', 'Yellow', 'Black', 'None', 'None', 'None', 'None'][i],
    color_name: card.colour_lanes[i] || ['Cyan', 'Magenta', 'Yellow', 'Black', 'None', 'None', 'None', 'None'][i],
    anilox_value: normalizeAniloxLaneValue(card.anilox_lanes[i] || 'None'),
    anilox_custom: '',
  }));
  const laneColorStyle = (v) => {
    const s = String(v || 'None');
    if (s === 'Cyan') return 'background:#e0f7fa;color:#0f766e;font-weight:800';
    if (s === 'Magenta') return 'background:#fce4ec;color:#9d174d;font-weight:800';
    if (s === 'Yellow') return 'background:#fffde7;color:#a16207;font-weight:800';
    if (s === 'Black') return 'background:#eceff1;color:#111827;font-weight:800';
    return 'background:#f8fafc;color:#64748b;font-weight:700';
  };
  const laneColorName = (r) => {
    const cc = String(r.color_code || 'None');
    const cn = String(r.color_name || '').trim();
    if (cn !== '') return cn;
    if (['Cyan','Magenta','Yellow','Black','None'].includes(cc)) return cc;
    return '—';
  };
  const planningImage = String(job.planning_image_url || '').trim();
  const plateImage = String(job.plate_image_url || '').trim();
  const referenceImage = String(job.job_preview_image_url || planningImage || plateImage || '').trim();
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
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Job Name</td><td style="padding:5px 7px;border:1px solid #cbd5e1;font-size:.84rem;font-weight:900;color:#0f172a">${esc(resolvePrintDisplayName(job))}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;width:24%">Die / Plate</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.die||'—') + ' / ' + (card.plate_no||'—'))}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Material</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.material_company||'—') + ' / ' + (card.material_name||'—'))}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Order MTR / QTY</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.order_mtr||'—') + ' / ' + (card.order_qty||'—'))}</td></tr>
          <tr><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Label Size</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc(card.label_size||'—')}</td><td style="padding:5px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800">Repeat / Direction</td><td style="padding:5px 7px;border:1px solid #cbd5e1">${esc((card.repeat_mm||'—') + ' / ' + (card.direction||'—'))}</td></tr>
        </table>

        <!-- ROLL-WISE MATERIAL AND WASTAGE -->
        <div style="font-size:.66rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;color:#c2410c;background:#fff7ed;padding:5px 8px;border-radius:4px">Roll-wise Material and Wastage</div>
        <table style="width:100%;border-collapse:collapse;font-size:.7rem;margin-bottom:10px">
          <thead><tr>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">#</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Roll No</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Material</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Slitted Size</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Length (m)</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Color Match</th>
            <th style="padding:5px 6px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c">Wastage (m)</th>
          </tr></thead>
          <tbody>
            ${rollRows.map((r, i) => `<tr>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${i + 1}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1;font-weight:700;color:#c2410c">${esc(r.roll_no||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.material_name||card.material_name||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${r.slitted_width ? esc(r.slitted_width+'mm') : '—'}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1;font-weight:700">${r.slitted_length ? esc(String(r.slitted_length)) : '—'}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.color_match_status||'—')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(String(r.wastage_meters ?? '—'))}</td>
            </tr>`).join('')}
          </tbody>
          <tfoot>
            <tr><td colspan="4" style="padding:6px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;text-align:right">Total Assigned Roll MTR</td><td style="padding:6px 7px;border:1px solid #cbd5e1;font-weight:900;background:#f8fafc">${totalAssignedMtr}</td><td colspan="2" style="border:1px solid #cbd5e1;background:#f8fafc"></td></tr>
            <tr><td colspan="4" style="padding:6px 7px;border:1px solid #cbd5e1;background:#f0fdf4;font-weight:800;text-align:right">Job Allocated MTR</td><td style="padding:6px 7px;border:1px solid #cbd5e1;font-weight:900;background:#f0fdf4">${totalAllocatedMtr || '—'}</td><td colspan="2" style="padding:6px 7px;border:1px solid #cbd5e1;background:${mtrMatch?'#f0fdf4':'#fef3c7'};font-weight:800;color:${mtrMatch?'#16a34a':'#d97706'}">${mtrMatch ? '✓ Sufficient' : (totalAllocatedMtr > 0 ? '⚠ Short by '+mtrShort+'m' : '—')}</td></tr>
            <tr><td colspan="5" style="border:1px solid #cbd5e1"></td><td style="padding:6px 7px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;text-align:right">Total Wastage</td><td style="padding:6px 7px;border:1px solid #cbd5e1;font-weight:800">${esc(card.total_wastage_meters || card.wastage_meters || '—')}</td></tr>
          </tfoot>
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
              <td style="padding:5px 6px;border:1px solid #cbd5e1;${laneColorStyle(r.color_code)}">${esc(r.color_code || 'None')}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1;${laneColorStyle(laneColorName(r))}">${esc(laneColorName(r))}</td>
              <td style="padding:5px 6px;border:1px solid #cbd5e1">${esc(r.anilox_value || 'None')}</td>
            </tr>`).join('')}
          </tbody>
        </table>

        <!-- IMAGES -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
          <div style="border:1px solid #cbd5e1;border-radius:8px;padding:8px">
            <div style="font-size:.62rem;font-weight:800;text-transform:uppercase;color:#5b21b6;margin-bottom:6px">Reference Image (Planning/Plate)</div>
            ${referenceImage ? `<img src="${esc(referenceImage)}" style="width:100%;height:130px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0">` : `<div style="height:130px;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.68rem;color:#94a3b8">Not available</div>`}
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
