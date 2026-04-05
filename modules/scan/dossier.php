<?php
// ================================================================
// Scan → Job Journey Dossier
// URL: /modules/scan/dossier.php?jn=FLX/2026/0001
// Shows the FULL production chain:
//   Planning → Slitting → Printing → (future depts auto-included)
// Works on desktop & mobile. Printable as single multi-page PDF
// via browser Print → Save as PDF (no library required).
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

// ── Helpers ──────────────────────────────────────────────────

function dos_statusColor(string $sts): array {
    return match(strtolower(trim($sts))) {
        'pending'                         => ['#fef3c7','#92400e','#f59e0b'],
        'queued'                          => ['#f1f5f9','#475569','#94a3b8'],
        'running'                         => ['#dbeafe','#1e40af','#3b82f6'],
        'completed','qc passed','finalized','closed'
                                          => ['#dcfce7','#166534','#22c55e'],
        'qc failed'                       => ['#fee2e2','#991b1b','#ef4444'],
        default => (str_contains(strtolower($sts), 'hold')
            ? ['#fee2e2','#991b1b','#ef4444']
            : ['#f1f5f9','#475569','#64748b'])
    };
}

function dos_deptLabel(array $job): string {
    $type = strtolower(trim((string)($job['job_type'] ?? '')));
    $dept = strtolower(trim((string)($job['department'] ?? '')));
    $fixedMap = [
        'jumbo_slitting' => 'Jumbo Slitting',
        'flexo_printing' => 'Flexo Printing',
        'qc'             => 'QC',
        'packing'        => 'Packing',
        'dispatch'       => 'Dispatch',
    ];
    if (isset($fixedMap[$dept])) return $fixedMap[$dept];
    if ($type === 'slitting') return 'Jumbo Slitting';
    if ($type === 'printing') return 'Flexo Printing';
    $label = $dept ?: $type;
    return ucwords(str_replace('_', ' ', $label));
}

function dos_deptIcon(array $job): string {
    $type = strtolower(trim((string)($job['job_type'] ?? '')));
    $dept = strtolower(trim((string)($job['department'] ?? '')));
    if ($type === 'slitting' || $dept === 'jumbo_slitting') return 'bi-scissors';
    if ($type === 'printing' || str_contains($dept, 'print'))  return 'bi-printer-fill';
    if (str_contains($dept, 'qc'))      return 'bi-patch-check';
    if (str_contains($dept, 'pack'))    return 'bi-box-seam';
    if (str_contains($dept, 'dispatch') || str_contains($dept, 'delivery')) return 'bi-truck';
    return 'bi-gear-fill';
}

function dos_fmtDate(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    return date('d M Y', strtotime($d));
}
function dos_fmtDt(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '—';
    return date('d M Y, H:i', strtotime($d));
}

function dos_planning_values(array $planning, array $columns): array {
  $extra = json_decode((string)($planning['extra_data'] ?? '{}'), true);
  if (!is_array($extra)) $extra = [];

  $vals = [];
  foreach ($columns as $c) {
    $k = (string)($c['col_key'] ?? '');
    if ($k === '') continue;

    if ($k === 'sn') {
      $vals[$k] = (string)($planning['sequence_order'] ?? $planning['id'] ?? '');
      continue;
    }
    if ($k === 'printing_planning') {
      $vals[$k] = (string)($planning['status'] ?? ($extra[$k] ?? 'Pending'));
      continue;
    }
    if ($k === 'priority') {
      $vals[$k] = (string)($planning['priority'] ?? ($extra[$k] ?? 'Normal'));
      continue;
    }
    if ($k === 'name') {
      $vals[$k] = (string)($planning['job_name'] ?? ($extra[$k] ?? ''));
      continue;
    }
    if ($k === 'remarks') {
      $vals[$k] = (string)($planning['notes'] ?? ($extra[$k] ?? ''));
      continue;
    }
    if ($k === 'dispatch_date') {
      $vals[$k] = (string)($extra['dispatch_date'] ?? ($planning['scheduled_date'] ?? ''));
      continue;
    }

    if (array_key_exists($k, $extra)) {
      $vals[$k] = (string)$extra[$k];
      continue;
    }
    $vals[$k] = (string)($planning[$k] ?? '');
  }
  return $vals;
}

// ── Find root of chain by walking UP via previous_job_id ─────
function dos_find_root(mysqli $db, array $startJob): array {
    $visited = [];
    $current = $startJob;
    while (!empty($current['previous_job_id'])) {
        $pid = (int)$current['previous_job_id'];
        if (isset($visited[$pid])) break;
        $visited[$pid] = true;
        $st = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $st->bind_param('i', $pid);
        $st->execute();
        $parent = $st->get_result()->fetch_assoc();
        if (!$parent) break;
        $current = $parent;
    }
    return $current;
}

// ── Walk DOWN from root collecting all linked jobs (BFS) ─────
function dos_get_chain(mysqli $db, int $rootId): array {
    $chain = [];
    $seen  = [];
    $queue = [$rootId];
    while (!empty($queue)) {
        $cid = (int)array_shift($queue);
        if ($cid <= 0 || isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $st = $db->prepare("SELECT * FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        $st->bind_param('i', $cid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) continue;
        $chain[$cid] = $row;
        $cs = $db->prepare("SELECT id FROM jobs WHERE previous_job_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
        $cs->bind_param('i', $cid);
        $cs->execute();
        foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $cr) {
            if (!isset($seen[(int)$cr['id']])) $queue[] = (int)$cr['id'];
        }
    }
    return array_values($chain);
}

// ── Fetch slitting entries for a list of roll nos ────────────
function dos_slitting_entries(mysqli $db, array $rollNos): array {
    if (empty($rollNos)) return [];
    $ph = implode(',', array_fill(0, count($rollNos), '?'));
    $ts = str_repeat('s', count($rollNos));
    $st = $db->prepare("SELECT se.*, sb.batch_no, sb.status AS batch_status, sb.machine, sb.operator_name
        FROM slitting_entries se
        JOIN slitting_batches sb ON se.batch_id = sb.id
        WHERE se.parent_roll_no IN ($ph)
        ORDER BY se.id ASC");
    $st->bind_param($ts, ...$rollNos);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Load live roll details for a set of roll numbers ─────────
function dos_roll_map(mysqli $db, array $rollNos): array {
    if (empty($rollNos)) return [];
    $ph = implode(',', array_fill(0, count($rollNos), '?'));
    $ts = str_repeat('s', count($rollNos));
    $st = $db->prepare("SELECT roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, status, remarks FROM paper_stock WHERE roll_no IN ($ph)");
    $st->bind_param($ts, ...$rollNos);
    $st->execute();
    $map = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $map[$r['roll_no']] = $r;
    }
    return $map;
}

// ═══════════════════════════════════════════════════
// DATA GATHERING
// ═══════════════════════════════════════════════════

$jn    = trim((string)($_GET['jn'] ?? ''));
$error = '';

$chain    = [];
$planning = null;
$salesOrder = null;
$slitEntries = [];
$planningBoardColumns = [];
$planningBoardValues  = [];
$planningBoardImageUrl = '';
$planningBoardImageName = '';

if ($jn === '') {
    $error = 'No job number provided. Please scan a valid job card QR code.';
} else {
    // 1. Find the scanned job
    $st = $db->prepare("SELECT * FROM jobs WHERE job_no = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
    $st->bind_param('s', $jn);
    $st->execute();
    $scannedJob = $st->get_result()->fetch_assoc();

    if (!$scannedJob) {
        $error = 'Job card not found: ' . htmlspecialchars($jn, ENT_QUOTES);
    } else {
        // 2. Walk UP to chain root
        $root  = dos_find_root($db, $scannedJob);
        $chain = dos_get_chain($db, (int)$root['id']);

        // 3. Also include sibling jobs sharing same planning_id (not already captured)
        $planningId = (int)($root['planning_id'] ?? 0);
        if ($planningId > 0) {
            $inIds = array_map(fn($j) => (int)$j['id'], $chain);
            $ph = implode(',', array_fill(0, count($inIds), '?'));
            $ts = str_repeat('i', count($inIds));
            $st2 = $db->prepare("SELECT * FROM jobs WHERE planning_id = ? AND id NOT IN ($ph) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY sequence_order ASC, created_at ASC");
            $params = array_merge([$planningId], $inIds);
            $types  = 'i' . $ts;
            $st2->bind_param($types, ...$params);
            $st2->execute();
            foreach ($st2->get_result()->fetch_all(MYSQLI_ASSOC) as $sibling) {
                $chain[] = $sibling;
            }
        }

        // 4. Sort chain: by sequence_order then created_at
        usort($chain, function($a, $b) {
            $sa = (int)($a['sequence_order'] ?? 0);
            $sb = (int)($b['sequence_order'] ?? 0);
            if ($sa !== $sb) return $sa - $sb;
            return strtotime((string)($a['created_at'] ?? '')) - strtotime((string)($b['created_at'] ?? ''));
        });

        // 5. Parse extra_data for all chain jobs
        foreach ($chain as &$cj) {
            $cj['_extra'] = json_decode((string)($cj['extra_data'] ?? '{}'), true) ?: [];
        }
        unset($cj);

        // 6. Collect ALL roll numbers from chain for batch lookup
        $allRollNos = [];
        foreach ($chain as $cj) {
            $ex = $cj['_extra'];
            if (!empty($cj['roll_no']))           $allRollNos[] = $cj['roll_no'];
            if (!empty($ex['parent_roll']))        $allRollNos[] = $ex['parent_roll'];
            $parentRolls = $ex['parent_rolls'] ?? [];
            if (is_string($parentRolls)) $parentRolls = preg_split('/\s*,\s*/', $parentRolls, -1, PREG_SPLIT_NO_EMPTY);
            foreach ((array)$parentRolls as $pr)  $allRollNos[] = $pr;
            foreach ((array)($ex['child_rolls'] ?? []) as $cr)  $allRollNos[] = $cr['roll_no'] ?? '';
            foreach ((array)($ex['stock_rolls'] ?? []) as $sr)  $allRollNos[] = $sr['roll_no'] ?? '';
        }
        $allRollNos = array_values(array_unique(array_filter($allRollNos)));
        $rollMap = dos_roll_map($db, $allRollNos);

        // 7. Load slitting entries
        $parentRollNosForEntries = [];
        foreach ($chain as $cj) {
            if (strtolower($cj['job_type'] ?? '') === 'slitting') {
                if (!empty($cj['roll_no'])) $parentRollNosForEntries[] = $cj['roll_no'];
                $ex = $cj['_extra'];
                if (!empty($ex['parent_roll'])) $parentRollNosForEntries[] = $ex['parent_roll'];
                $prs = $ex['parent_rolls'] ?? [];
                if (is_string($prs)) $prs = preg_split('/\s*,\s*/', $prs, -1, PREG_SPLIT_NO_EMPTY);
                foreach ((array)$prs as $pr) $parentRollNosForEntries[] = $pr;
            }
        }
        $parentRollNosForEntries = array_values(array_unique(array_filter($parentRollNosForEntries)));
        $slitEntries = dos_slitting_entries($db, $parentRollNosForEntries);

        // 8. Load planning record
        if ($planningId > 0) {
            $st3 = $db->prepare("SELECT p.*, so.order_no, so.client_name AS so_client, so.quantity AS so_qty, so.selling_price AS so_price, so.due_date AS so_due, so.status AS so_status, so.material_type AS so_material
                FROM planning p
                LEFT JOIN sales_orders so ON p.sales_order_id = so.id
                WHERE p.id = ? LIMIT 1");
            $st3->bind_param('i', $planningId);
            $st3->execute();
            $planning = $st3->get_result()->fetch_assoc();

          if ($planning) {
            $planDept = trim((string)($planning['department'] ?? ''));
            if ($planDept === '') $planDept = 'label-printing';

            $stCols = $db->prepare("SELECT col_key, col_label, col_type, sort_order
              FROM planning_board_columns
              WHERE department = ?
              ORDER BY sort_order ASC, id ASC");
            $stCols->bind_param('s', $planDept);
            $stCols->execute();
            $planningBoardColumns = $stCols->get_result()->fetch_all(MYSQLI_ASSOC);

            if (!empty($planningBoardColumns)) {
              $planningBoardValues = dos_planning_values($planning, $planningBoardColumns);
            }

            $planExtra = json_decode((string)($planning['extra_data'] ?? '{}'), true);
            if (!is_array($planExtra)) $planExtra = [];

            $planningBoardImageName = trim((string)($planExtra['image_name'] ?? ($planExtra['planning_image_name'] ?? '')));
            $imgPath = '';
            foreach (['image_path','planning_image_path','print_image_path','physical_image_path','upload_image_path'] as $ik) {
              $cand = trim((string)($planExtra[$ik] ?? ''));
              if ($cand !== '') { $imgPath = $cand; break; }
            }
            if ($imgPath !== '') {
              $planningBoardImageUrl = appUrl($imgPath);
            }
          }
        }

        // 9. Also try to load sales order from any job in chain
        if (!$planning || empty($planning['order_no'])) {
            foreach ($chain as $cj) {
                $soId = (int)($cj['sales_order_id'] ?? 0);
                if ($soId > 0) {
                    $st4 = $db->prepare("SELECT * FROM sales_orders WHERE id = ? LIMIT 1");
                    $st4->bind_param('i', $soId);
                    $st4->execute();
                    $salesOrder = $st4->get_result()->fetch_assoc();
                    if ($salesOrder) break;
                }
            }
        }

        // 10. Find QC status from chain jobs
        $qcStatus = '';
        $qcJob    = null;
        foreach ($chain as $cj) {
            $sts = strtolower($cj['status'] ?? '');
            if ($sts === 'qc passed' || $sts === 'qc failed') {
                $qcStatus = $cj['status'];
                $qcJob    = $cj;
                break;
            }
            if (str_contains(strtolower($cj['department'] ?? ''), 'qc')) {
                $qcStatus = $cj['status'];
                $qcJob    = $cj;
                break;
            }
        }
    }
}

$jobName = '';
if (!empty($chain)) {
    $jobName = trim((string)($planning['job_name'] ?? ($chain[0]['_extra']['job_name'] ?? '')));
    if ($jobName === '') $jobName = $jn;
}

$pageTitle = $jobName ? "Job Journey — $jobName" : "Job Journey Dossier";

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; min-height: 100vh; }

    /* ── Top bar ── */
    .ds-topbar {
      background: #0f172a;
      padding: 12px 20px;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      position: sticky; top: 0; z-index: 100;
    }
    .ds-topbar-brand { display: flex; align-items: center; gap: 10px; }
    .ds-topbar-brand img { height: 32px; border-radius: 4px; }
    .ds-topbar-brand .ds-co { font-weight: 800; color: #fff; font-size: .88rem; }
    .ds-topbar-right { display: flex; align-items: center; gap: 8px; }
    .ds-topbar-btn {
      padding: 7px 14px; border-radius: 8px; font-size: .72rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: .05em; border: none; cursor: pointer;
      display: flex; align-items: center; gap: 6px; text-decoration: none; transition: all .15s;
    }
    .ds-btn-print  { background: #7c3aed; color: #fff; }
    .ds-btn-print:hover { background: #6d28d9; }
    .ds-btn-back   { background: transparent; color: #94a3b8; }
    .ds-btn-back:hover { color: #fff; }

    /* ── Layout ── */
    .ds-wrap { max-width: 760px; margin: 0 auto; padding: 20px 16px 48px; }

    /* ── Cover block ── */
    .ds-cover {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
      border-radius: 18px; padding: 28px 28px 24px;
      margin-bottom: 20px; color: #fff;
      display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap;
    }
    .ds-cover-main { flex: 1; min-width: 0; }
    .ds-cover-tag { font-size: .58rem; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: #7dd3fc; margin-bottom: 6px; }
    .ds-cover-title { font-size: 1.55rem; font-weight: 900; line-height: 1.15; margin-bottom: 6px; }
    .ds-cover-sub { font-size: .82rem; color: #94a3b8; font-weight: 600; }
    .ds-cover-meta { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px; }
    .ds-cover-stat { display: flex; flex-direction: column; gap: 2px; }
    .ds-cover-stat .cs-lbl { font-size: .55rem; font-weight: 800; text-transform: uppercase; color: #7dd3fc; }
    .ds-cover-stat .cs-val { font-size: .88rem; font-weight: 700; color: #f0f9ff; }
    .ds-cover-right { text-align: right; flex-shrink: 0; }
    .ds-cover-date { font-size: .7rem; color: #94a3b8; margin-top: 8px; }

    /* ── Journey progress bar ── */
    .ds-journey {
      background: #fff; border-radius: 12px; padding: 16px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,.06); margin-bottom: 20px;
      overflow-x: auto;
    }
    .ds-journey-label { font-size: .58rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; margin-bottom: 12px; }
    .ds-steps { display: flex; align-items: center; gap: 0; min-width: max-content; }
    .ds-step { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 80px; }
    .ds-step-dot {
      width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-size: .75rem; font-weight: 800; border: 2px solid transparent;
    }
    .ds-step-dot.done   { background: #dcfce7; border-color: #22c55e; color: #16a34a; }
    .ds-step-dot.active { background: #dbeafe; border-color: #3b82f6; color: #1e40af; }
    .ds-step-dot.pending{ background: #f1f5f9; border-color: #cbd5e1; color: #94a3b8; }
    .ds-step-dot.failed { background: #fee2e2; border-color: #ef4444; color: #dc2626; }
    .ds-step-name { font-size: .58rem; font-weight: 700; text-align: center; color: #64748b; }
    .ds-step-status { font-size: .5rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; }
    .ds-step-conn { height: 2px; flex: 1; min-width: 20px; background: #e2e8f0; margin-bottom: 16px; }
    .ds-step-conn.done { background: #22c55e; }

    /* ── Planning summary card ── */
    .ds-card {
      background: #fff; border-radius: 14px; padding: 18px 20px;
      box-shadow: 0 1px 6px rgba(0,0,0,.05); margin-bottom: 14px;
    }
    .ds-card-title {
      font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; color: #94a3b8;
      display: flex; align-items: center; gap: 7px; margin-bottom: 14px;
      border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;
    }
    .ds-card-title i { font-size: .85rem; }
    .ds-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
    @media(max-width:420px){ .ds-grid { grid-template-columns: 1fr; } }
    .ds-field { display: flex; flex-direction: column; gap: 2px; }
    .ds-field .df-lbl { font-size: .57rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: .04em; }
    .ds-field .df-val { font-size: .82rem; font-weight: 700; color: #1e293b; }
    .df-val.purple { color: #7c3aed; }
    .df-val.green  { color: #16a34a; }
    .df-val.teal   { color: #0ea5a4; }
    .df-val.red    { color: #dc2626; }

    /* ── Stage block ── */
    .ds-stage {
      background: #fff; border-radius: 16px;
      box-shadow: 0 2px 10px rgba(0,0,0,.06);
      margin-bottom: 20px; overflow: hidden;
    }
    .ds-stage-header {
      padding: 16px 20px 12px;
      border-bottom: 2px solid #f1f5f9;
      display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    }
    .ds-stage-left { flex: 1; min-width: 0; }
    .ds-stage-num { font-size: .55rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: .1em; margin-bottom: 3px; }
    .ds-stage-dept { display: flex; align-items: center; gap: 8px; }
    .ds-stage-dept i { font-size: 1.1rem; color: #7c3aed; }
    .ds-stage-dept-name { font-size: 1rem; font-weight: 900; color: #0f172a; }
    .ds-stage-jobno { font-size: .78rem; color: #475569; font-weight: 600; margin-top: 2px; }
    .ds-stage-right { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }

    /* Badge / pill */
    .ds-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 12px; border-radius: 999px; font-size: .67rem; font-weight: 800; text-transform: uppercase;
    }
    .ds-dot { width: 6px; height: 6px; border-radius: 50%; }

    .ds-stage-body { padding: 16px 20px; }

    /* Sub-card inside stage body */
    .ds-sub {
      background: #f8fafc; border-radius: 10px; padding: 12px 14px;
      margin-bottom: 12px; border: 1px solid #f1f5f9;
    }
    .ds-sub-title {
      font-size: .58rem; font-weight: 800; text-transform: uppercase; color: #94a3b8;
      display: flex; align-items: center; gap: 5px; margin-bottom: 10px;
    }
    .ds-sub-title i { font-size: .75rem; }
    .ds-plan-img-wrap { margin-top: 6px; }
    .ds-plan-img {
      width: 100%; max-height: 340px; object-fit: contain;
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
      display: block;
    }
    .ds-plan-img-cap {
      margin-top: 6px; font-size: .68rem; font-weight: 700; color: #64748b;
    }

    /* Roll rows */
    .ds-roll-row {
      display: flex; align-items: baseline; justify-content: space-between; gap: 8px;
      padding: 5px 0; border-bottom: 1px solid #e2e8f0; font-size: .78rem;
    }
    .ds-roll-row:last-child { border-bottom: none; }
    .ds-roll-no { font-weight: 800; color: #0ea5a4; }
    .ds-roll-spec { color: #64748b; font-size: .7rem; }
    .ds-roll-sts { font-size: .65rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; }

    /* Colour lane grid */
    .ds-lane-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 3px; margin-top: 8px; }
    .ds-lane-cell {
      background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 3px 4px;
      font-size: .68rem; font-weight: 700; text-align: center; min-height: 26px;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .ds-lane-num { font-size: .47rem; color: #94a3b8; }

    /* Timeline strip */
    .ds-tl { display: flex; gap: 18px; flex-wrap: wrap; margin-top: 10px; }
    .ds-tl-item { display: flex; flex-direction: column; gap: 1px; }
    .ds-tl-item .tl-lbl { font-size: .53rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; }
    .ds-tl-item .tl-val { font-size: .75rem; font-weight: 700; color: #1e293b; }

    /* Placeholder block (QC, Dispatch not yet implemented) */
    .ds-placeholder {
      background: #fffbeb; border: 1px dashed #fbbf24; border-radius: 10px;
      padding: 14px 18px; display: flex; align-items: flex-start; gap: 12px;
      font-size: .78rem; color: #92400e; margin-bottom: 14px;
    }
    .ds-placeholder i { font-size: 1.2rem; flex-shrink: 0; margin-top: 1px; }
    .ds-placeholder-content { flex: 1; }
    .ds-placeholder-title { font-weight: 800; margin-bottom: 3px; }
    .ds-placeholder-sub { font-size: .72rem; color: #b45309; }

    /* Signoff */
    .ds-signoff {
      background: #fff; border: 2px dashed #e2e8f0; border-radius: 14px;
      padding: 24px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px;
      margin-top: 20px;
    }
    @media(max-width:500px){ .ds-signoff { grid-template-columns: 1fr; } }
    .ds-signoff-block { display: flex; flex-direction: column; gap: 40px; }
    .ds-signoff-line { border-top: 1.5px solid #0f172a; font-size: .62rem; font-weight: 800; text-transform: uppercase; color: #64748b; padding-top: 4px; }
    .ds-signoff-title { font-size: .65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px; }

    /* Error */
    .ds-error { text-align: center; padding: 70px 20px; }
    .ds-error i { font-size: 3.5rem; color: #ef4444; opacity: .4; display: block; margin-bottom: 14px; }
    .ds-error h2 { font-size: 1.2rem; font-weight: 800; color: #1e293b; }
    .ds-error p  { font-size: .85rem; color: #64748b; margin-top: 6px; }

    /* Footer stamp */
    .ds-stamp { text-align: center; font-size: .62rem; color: #94a3b8; margin-top: 16px; }

    /* ══ PRINT STYLES ══════════════════════════════════ */
    @media print {
      .ds-topbar,
      .ds-topbar-btn,
      .ds-topbar-right { display: none !important; }

      body { background: #fff !important; }
      .ds-wrap { padding: 0; max-width: 100%; }
      .ds-card, .ds-stage, .ds-sub { box-shadow: none !important; border: 1px solid #e2e8f0; }

      /* Print header on every page */
      .ds-print-header { display: block !important; }

      /* Cover gets its own page */
      .ds-cover { page-break-after: always; border-radius: 0; background: #0f172a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

      /* Journey bar — keep together */
      .ds-journey { page-break-inside: avoid; }

      /* Each stage on its own page */
      .ds-stage { page-break-before: always; page-break-inside: avoid; }
      .ds-stage:first-of-type { page-break-before: avoid; }

      /* Signoff gets own page */
      .ds-signoff { page-break-before: always; }

      * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

      @page { size: A4 portrait; margin: 12mm 10mm; }
    }

    /* Print header (hidden on screen, shown on print) */
    .ds-print-header {
      display: none;
      text-align: center;
      padding-bottom: 10px;
      border-bottom: 2px solid #0f172a;
      margin-bottom: 14px;
    }
    .ds-print-header .ph-co   { font-size: 1rem; font-weight: 900; color: #0f172a; }
    .ds-print-header .ph-sub  { font-size: .68rem; color: #475569; }
    .ds-print-header .ph-date { font-size: .62rem; color: #94a3b8; margin-top: 2px; }

    /* ══ PHYSICAL JOB CARD (embedded after each stage) ═════ */
    .jc-wrap {
      background: #fff;
      border: 2px solid #0f172a;
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 20px;
      font-family: 'Segoe UI', Arial, sans-serif;
    }
    /* On screen: collapsed; expand button shown */
    .jc-toggle-btn {
      width: 100%; padding: 10px 16px;
      background: #f8fafc; border: none; border-top: 1px solid #e2e8f0;
      font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em;
      color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
      text-align: center;
    }
    .jc-toggle-btn:hover { background: #f1f5f9; }
    .jc-body { display: none; } /* collapsed on screen by default */
    .jc-body.open { display: block; }

    .jc-header {
      background: #0f172a; color: #fff;
      padding: 14px 18px;
      display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap;
    }
    .jc-header-left { flex: 1; min-width: 0; }
    .jc-co-name { font-size: .72rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 2px; }
    .jc-title { font-size: .62rem; font-weight: 800; text-transform: uppercase; letter-spacing: .12em; color: #7dd3fc; margin-bottom: 4px; }
    .jc-jobno { font-size: 1.35rem; font-weight: 900; color: #fff; line-height: 1.1; }
    .jc-jobname { font-size: .78rem; color: #94a3b8; font-weight: 600; margin-top: 3px; }
    .jc-header-right { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .jc-dept-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 4px 12px; border-radius: 999px;
      font-size: .65rem; font-weight: 800; text-transform: uppercase;
      background: #1e293b; color: #7dd3fc; border: 1px solid #334155;
    }
    .jc-qr-box { background: #fff; border-radius: 6px; padding: 3px; }

    .jc-content { padding: 14px 18px; }
    .jc-section { margin-bottom: 12px; }
    .jc-section-title {
      font-size: .57rem; font-weight: 800; text-transform: uppercase; color: #94a3b8;
      letter-spacing: .08em; display: flex; align-items: center; gap: 5px;
      border-bottom: 1px solid #f1f5f9; padding-bottom: 5px; margin-bottom: 8px;
    }
    .jc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; }
    .jc-field { display: flex; flex-direction: column; gap: 1px; }
    .jc-field .jf-lbl { font-size: .54rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; letter-spacing: .04em; }
    .jc-field .jf-val { font-size: .8rem; font-weight: 700; color: #1e293b; }
    .jf-val.jc-purple { color: #7c3aed; }
    .jf-val.jc-teal   { color: #0ea5a4; }
    .jf-val.jc-green  { color: #16a34a; }

    /* Colour/anilox lane grid */
    .jc-lane-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 3px; margin-top: 6px; }
    .jc-lane-cell {
      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px;
      padding: 3px 2px; font-size: .67rem; font-weight: 700;
      text-align: center; min-height: 25px;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .jc-lane-num { font-size: .44rem; color: #94a3b8; }

    /* Slit table */
    .jc-slit-table { width: 100%; border-collapse: collapse; font-size: .72rem; }
    .jc-slit-table th { background: #f1f5f9; font-size: .54rem; font-weight: 800; text-transform: uppercase; color: #64748b; padding: 5px 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .jc-slit-table td { padding: 5px 8px; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-weight: 600; }
    .jc-slit-table tr:last-child td { border-bottom: none; }

    /* Status badge inside card */
    .jc-sts-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 10px; border-radius: 999px; font-size: .64rem; font-weight: 800; text-transform: uppercase;
    }
    .jc-dot { width: 6px; height: 6px; border-radius: 50%; }

    /* Timeline inside card */
    .jc-tl { display: flex; gap: 16px; flex-wrap: wrap; }
    .jc-tl-item { display: flex; flex-direction: column; gap: 1px; }
    .jc-tl-item .jtl-lbl { font-size: .52rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; }
    .jc-tl-item .jtl-val { font-size: .74rem; font-weight: 700; color: #1e293b; }

    /* Job card sign-off strip */
    .jc-footer {
      border-top: 2px dashed #e2e8f0;
      padding: 12px 18px;
      display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;
      font-size: .6rem;
    }
    .jc-footer-block { display: flex; flex-direction: column; gap: 26px; }
    .jc-footer-line { border-top: 1px solid #0f172a; padding-top: 3px; font-size: .57rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
    .jc-footer-title { font-size: .57rem; font-weight: 800; text-transform: uppercase; color: #94a3b8; }

    /* Print behaviour for job cards */
    @media print {
      .jc-wrap { page-break-before: always; border-radius: 0; border: 2px solid #0f172a; }
      .jc-body { display: block !important; } /* always expanded in print */
      .jc-toggle-btn { display: none !important; }
      .jc-wrap .no-print { display: none !important; }
    }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="ds-topbar no-print">
  <div class="ds-topbar-brand">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo">
    <?php else: ?>
      <i class="bi bi-journal-bookmark-fill" style="color:#7c3aed;font-size:1.3rem"></i>
    <?php endif; ?>
    <span class="ds-co"><?= htmlspecialchars($companyName) ?></span>
  </div>
  <div class="ds-topbar-right">
    <button onclick="window.print()" class="ds-topbar-btn ds-btn-print">
      <i class="bi bi-printer-fill"></i> Print / PDF
    </button>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="ds-topbar-btn ds-btn-back">
      <i class="bi bi-house"></i> Dashboard
    </a>
  </div>
</div>

<div class="ds-wrap">

<!-- Print-only page header (appears on every printed page via CSS running header alternative) -->
<div class="ds-print-header">
  <div class="ph-co"><?= htmlspecialchars($companyName) ?><?= $companyGst ? ' &nbsp;|&nbsp; GST: ' . htmlspecialchars($companyGst) : '' ?></div>
  <?php if ($companyAddr): ?>
    <div class="ph-sub"><?= htmlspecialchars($companyAddr) ?></div>
  <?php endif; ?>
  <div class="ph-date">Job Journey Dossier &nbsp;|&nbsp; Printed: <?= date('d M Y, H:i') ?></div>
</div>

<?php if ($error): ?>
  <div class="ds-error">
    <i class="bi bi-qr-code-scan"></i>
    <h2>Job Not Found</h2>
    <p><?= $error ?></p>
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php" style="display:inline-flex;align-items:center;gap:6px;margin-top:20px;padding:10px 20px;background:#f1f5f9;border-radius:10px;font-size:.78rem;font-weight:800;text-decoration:none;color:#475569">
      <i class="bi bi-house"></i> Dashboard
    </a>
  </div>

<?php else: ?>

<?php
/* ── Build planning summary data ── */
$planJobName  = trim((string)($planning['job_name'] ?? ''));
$planMachine  = trim((string)($planning['machine'] ?? ''));
$planOperator = trim((string)($planning['operator_name'] ?? ''));
$planSched    = $planning['scheduled_date'] ?? '';
$planPriority = trim((string)($planning['priority'] ?? 'Normal'));
$planStatus   = trim((string)($planning['status'] ?? ''));
$planNotes    = trim((string)($planning['notes'] ?? ''));
$orderNo      = trim((string)($planning['order_no'] ?? ($salesOrder['order_no'] ?? '')));
$clientName   = trim((string)($planning['so_client'] ?? ($salesOrder['client_name'] ?? '')));
$soQty        = $planning['so_qty'] ?? ($salesOrder['quantity'] ?? '');
$soDue        = $planning['so_due'] ?? ($salesOrder['due_date'] ?? '');
$soStatus     = trim((string)($planning['so_status'] ?? ($salesOrder['status'] ?? '')));
$soMaterial   = trim((string)($planning['so_material'] ?? ($salesOrder['material_type'] ?? '')));
$planExtraArr = json_decode((string)($planning['extra_data'] ?? '{}'), true);
if (!is_array($planExtraArr)) $planExtraArr = [];

$planningFallbackDetails = [];
foreach ($planExtraArr as $k => $v) {
  if (!is_scalar($v)) continue;
  if ($v === '' || $v === null) continue;
  if (in_array((string)$k, ['image_path','image_name','image_uploaded_at'], true)) continue;
  $planningFallbackDetails[(string)$k] = (string)$v;
}

// Last job status for dispatch inference
$lastJob = !empty($chain) ? end($chain) : null;
$chainDone = $lastJob && in_array(strtolower($lastJob['status'] ?? ''), ['completed','qc passed','finalized','closed'], true);

// Stage progress state
function dos_stepState(string $sts): string {
    $s = strtolower(trim($sts));
    if (in_array($s, ['completed','finalized','closed','qc passed'], true)) return 'done';
    if ($s === 'qc failed') return 'failed';
    if (in_array($s, ['running','in progress'], true)) return 'active';
    return 'pending';
}
?>

<!-- ═══ COVER PAGE ═══════════════════════════════════════════ -->
<div class="ds-cover">
  <div class="ds-cover-main">
    <div class="ds-cover-tag"><i class="bi bi-journal-bookmark-fill"></i> &nbsp;Job Journey Dossier</div>
    <div class="ds-cover-title"><?= htmlspecialchars($planJobName ?: $jn) ?></div>
    <?php if ($clientName): ?>
      <div class="ds-cover-sub"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($clientName) ?>
        <?= $orderNo ? ' &nbsp;|&nbsp; Order: ' . htmlspecialchars($orderNo) : '' ?>
      </div>
    <?php endif; ?>
    <div class="ds-cover-meta">
      <div class="ds-cover-stat">
        <span class="cs-lbl">Total Stages</span>
        <span class="cs-val"><?= count($chain) ?></span>
      </div>
      <?php if ($planMachine): ?>
      <div class="ds-cover-stat">
        <span class="cs-lbl">Machine</span>
        <span class="cs-val"><?= htmlspecialchars($planMachine) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($planPriority && $planPriority !== 'Normal'): ?>
      <div class="ds-cover-stat">
        <span class="cs-lbl">Priority</span>
        <span class="cs-val" style="color:#fbbf24"><?= htmlspecialchars($planPriority) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($soDue): ?>
      <div class="ds-cover-stat">
        <span class="cs-lbl">Due Date</span>
        <span class="cs-val"><?= dos_fmtDate($soDue) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="ds-cover-right">
    <?php if ($logoUrl): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" style="height:50px;border-radius:6px;opacity:.9">
    <?php endif; ?>
    <div class="ds-cover-date">Generated: <?= date('d M Y, H:i') ?></div>
    <div class="ds-cover-date" style="color:#7dd3fc"><?= htmlspecialchars($companyName) ?></div>
  </div>
</div>

<!-- ═══ JOURNEY PROGRESS BAR ════════════════════════════════ -->
<div class="ds-journey">
  <div class="ds-journey-label"><i class="bi bi-signpost-split"></i> &nbsp;Production Journey</div>
  <div class="ds-steps">
    <?php
    // Build step items: Planning + each chain job + QC (if separate) + Dispatch
    $steps = [];
    if ($planJobName || $planStatus) {
        $steps[] = ['label' => 'Planning', 'icon' => 'bi-clipboard2-check', 'status' => $planStatus ?: 'Completed', 'state' => $planStatus ? dos_stepState($planStatus) : 'done'];
    }
    foreach ($chain as $idx => $cj) {
        $steps[] = ['label' => dos_deptLabel($cj), 'icon' => dos_deptIcon($cj), 'status' => $cj['status'], 'state' => dos_stepState($cj['status'])];
    }
    $steps[] = ['label' => 'QC', 'icon' => 'bi-patch-check', 'status' => $qcStatus ?: 'Pending', 'state' => $qcStatus ? dos_stepState($qcStatus) : 'pending'];
    $steps[] = ['label' => 'Dispatch', 'icon' => 'bi-truck', 'status' => $chainDone ? 'Ready' : 'Pending', 'state' => $chainDone ? 'done' : 'pending'];

    foreach ($steps as $si => $step):
        $prevDone = ($si > 0 && $steps[$si-1]['state'] === 'done');
    ?>
      <?php if ($si > 0): ?><div class="ds-step-conn <?= $prevDone ? 'done' : '' ?>"></div><?php endif; ?>
      <div class="ds-step">
        <div class="ds-step-dot <?= $step['state'] ?>">
          <i class="bi <?= $step['icon'] ?>"></i>
        </div>
        <div class="ds-step-name"><?= htmlspecialchars($step['label']) ?></div>
        <div class="ds-step-status"><?= htmlspecialchars($step['status']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ═══ PLANNING + ORDER SUMMARY ════════════════════════════ -->
<?php if ($planning): ?>
<div class="ds-card">
  <div class="ds-card-title"><i class="bi bi-clipboard2-check"></i> Planning &amp; Order Summary</div>
  <div class="ds-grid">
    <?php if ($planJobName): ?>
      <div class="ds-field" style="grid-column:1/-1">
        <span class="df-lbl">Job Name</span>
        <span class="df-val"><?= htmlspecialchars($planJobName) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($clientName): ?>
      <div class="ds-field"><span class="df-lbl">Client</span><span class="df-val purple"><?= htmlspecialchars($clientName) ?></span></div>
    <?php endif; ?>
    <?php if ($orderNo): ?>
      <div class="ds-field"><span class="df-lbl">Order No</span><span class="df-val"><?= htmlspecialchars($orderNo) ?></span></div>
    <?php endif; ?>
    <?php if ($soQty): ?>
      <div class="ds-field"><span class="df-lbl">Order Qty</span><span class="df-val"><?= number_format((int)$soQty) ?></span></div>
    <?php endif; ?>
    <?php if ($soMaterial): ?>
      <div class="ds-field"><span class="df-lbl">Material Type</span><span class="df-val"><?= htmlspecialchars($soMaterial) ?></span></div>
    <?php endif; ?>
    <?php if ($planMachine): ?>
      <div class="ds-field"><span class="df-lbl">Machine</span><span class="df-val"><?= htmlspecialchars($planMachine) ?></span></div>
    <?php endif; ?>
    <?php if ($planOperator): ?>
      <div class="ds-field"><span class="df-lbl">Operator</span><span class="df-val"><?= htmlspecialchars($planOperator) ?></span></div>
    <?php endif; ?>
    <?php if ($planSched): ?>
      <div class="ds-field"><span class="df-lbl">Scheduled</span><span class="df-val"><?= dos_fmtDate($planSched) ?></span></div>
    <?php endif; ?>
    <?php if ($soDue): ?>
      <div class="ds-field"><span class="df-lbl">Due Date</span><span class="df-val"><?= dos_fmtDate($soDue) ?></span></div>
    <?php endif; ?>
    <?php if ($planPriority): ?>
      <div class="ds-field"><span class="df-lbl">Priority</span>
        <span class="df-val <?= $planPriority === 'Urgent' ? 'red' : ($planPriority === 'High' ? 'red' : '') ?>"><?= htmlspecialchars($planPriority) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($soStatus): ?>
      <div class="ds-field"><span class="df-lbl">Order Status</span><span class="df-val"><?= htmlspecialchars($soStatus) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($planningBoardColumns) || !empty($planningFallbackDetails)): ?>
    <div class="ds-sub" style="margin-top:12px">
      <div class="ds-sub-title"><i class="bi bi-table"></i> Planning Board Details (All Columns)</div>
      <div class="ds-grid">
        <?php if (!empty($planningBoardColumns)): ?>
          <?php foreach ($planningBoardColumns as $pc):
            $pKey = (string)($pc['col_key'] ?? '');
            if ($pKey === '') continue;
            $pLabel = (string)($pc['col_label'] ?? $pKey);
            $pVal = trim((string)($planningBoardValues[$pKey] ?? ''));
          ?>
            <div class="ds-field">
              <span class="df-lbl"><?= htmlspecialchars($pLabel) ?></span>
              <span class="df-val"><?= htmlspecialchars($pVal !== '' ? $pVal : '—') ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($planningFallbackDetails as $fk => $fv): ?>
            <div class="ds-field">
              <span class="df-lbl"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $fk))) ?></span>
              <span class="df-val"><?= htmlspecialchars($fv) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($planningBoardImageUrl): ?>
    <div class="ds-sub" style="margin-top:12px">
      <div class="ds-sub-title"><i class="bi bi-image"></i> Planning Board Job Image</div>
      <div class="ds-plan-img-wrap">
        <img class="ds-plan-img" src="<?= htmlspecialchars($planningBoardImageUrl) ?>" alt="Planning board image">
        <?php if ($planningBoardImageName): ?>
          <div class="ds-plan-img-cap">File: <?= htmlspecialchars($planningBoardImageName) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($planNotes): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9">
      <div class="df-lbl" style="font-size:.57rem">Planning Notes</div>
      <div style="font-size:.82rem;color:#475569;margin-top:4px;line-height:1.6"><?= nl2br(htmlspecialchars($planNotes)) ?></div>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ PRODUCTION STAGES (one per job in chain) ════════════ -->
<?php foreach ($chain as $stageIdx => $job):
  $extra = $job['_extra'];
  $sts   = (string)($job['status'] ?? 'Pending');
  [$bgC, $txC, $dtC] = dos_statusColor($sts);
  $deptLabel = dos_deptLabel($job);
  $deptIcon  = dos_deptIcon($job);
  $stageNum  = $stageIdx + 1;
  $jobName2  = trim((string)($job['planning_job_name'] ?? ($extra['job_name'] ?? '')));
  if ($jobName2 === '') $jobName2 = $job['job_no'];

  $dur = $job['duration_minutes'] ?? null;
  $durStr = ($dur !== null && $dur > 0) ? (floor($dur/60) . 'h ' . ($dur % 60) . 'm') : '—';

  // Parent rolls
  $parentRolls = $extra['parent_rolls'] ?? [];
  if (is_string($parentRolls)) $parentRolls = preg_split('/\s*,\s*/', $parentRolls, -1, PREG_SPLIT_NO_EMPTY);
  if (empty($parentRolls) && !empty($extra['parent_roll'])) $parentRolls = [$extra['parent_roll']];
  if (empty($parentRolls) && !empty($job['roll_no']))        $parentRolls = [$job['roll_no']];
  $parentRolls = array_values(array_unique(array_filter($parentRolls)));

  // Child rolls from extra_data
  $childRolls = is_array($extra['child_rolls'] ?? null) ? $extra['child_rolls'] : [];
  $stockRolls = is_array($extra['stock_rolls'] ?? null) ? $extra['stock_rolls'] : [];

  // Slitting entries for this stage
  $stageSlitEntries = [];
  if (strtolower($job['job_type'] ?? '') === 'slitting') {
      foreach ($slitEntries as $se) {
          if (in_array($se['parent_roll_no'], $parentRolls, true)) {
              $stageSlitEntries[] = $se;
          }
      }
  }

  $isSlitting  = strtolower($job['job_type'] ?? '') === 'slitting';
  $isPrinting  = strtolower($job['job_type'] ?? '') === 'printing' || str_contains(strtolower($job['department'] ?? ''), 'print');
?>
<div class="ds-stage">
  <!-- Stage header -->
  <div class="ds-stage-header">
    <div class="ds-stage-left">
      <div class="ds-stage-num">Stage <?= $stageNum ?> of <?= count($chain) ?></div>
      <div class="ds-stage-dept">
        <i class="bi <?= $deptIcon ?>" style="color:#7c3aed;font-size:1.2rem"></i>
        <span class="ds-stage-dept-name"><?= htmlspecialchars($deptLabel) ?></span>
      </div>
      <div class="ds-stage-jobno"><?= htmlspecialchars($job['job_no']) ?>
        <?php if ($jobName2 !== $job['job_no']): ?>
          &nbsp;—&nbsp; <?= htmlspecialchars($jobName2) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="ds-stage-right">
      <span class="ds-badge" style="background:<?= $bgC ?>;color:<?= $txC ?>">
        <span class="ds-dot" style="background:<?= $dtC ?>"></span>
        <?= htmlspecialchars($sts) ?>
      </span>
      <?php
        $completedAt = $job['completed_at'] ?? '';
        if ($completedAt && $completedAt !== '0000-00-00 00:00:00'):
      ?>
        <span style="font-size:.62rem;color:#16a34a;font-weight:700">
          <i class="bi bi-check-circle-fill"></i> <?= dos_fmtDate($completedAt) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>

  <div class="ds-stage-body">

    <!-- Material / Roll -->
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-box-seam"></i> Material &amp; Roll</div>
      <?php foreach ($parentRolls as $prn):
        $ri = $rollMap[$prn] ?? [];
        $rsb = $ri['status'] ?? '—';
        [$rbg, $rtx] = dos_statusColor($rsb);
      ?>
        <div class="ds-roll-row">
          <div>
            <span class="ds-roll-no"><?= htmlspecialchars($prn) ?></span>
            <?php if (!empty($ri['paper_type'])): ?>
              <span class="ds-roll-spec"> &nbsp;<?= htmlspecialchars($ri['paper_type']) ?>
                <?= !empty($ri['company']) ? ' · ' . htmlspecialchars($ri['company']) : '' ?>
                <?= !empty($ri['width_mm']) ? ' · ' . htmlspecialchars($ri['width_mm']) . 'mm' : '' ?>
                <?= !empty($ri['length_mtr']) ? ' × ' . htmlspecialchars($ri['length_mtr']) . 'm' : '' ?>
                <?= !empty($ri['gsm']) ? ' · ' . htmlspecialchars($ri['gsm']) . 'gsm' : '' ?>
                <?= !empty($ri['weight_kg']) ? ' · ' . htmlspecialchars($ri['weight_kg']) . 'kg' : '' ?>
              </span>
            <?php endif; ?>
          </div>
          <span class="ds-roll-sts" style="background:<?= $rbg ?>;color:<?= $rtx ?>"><?= htmlspecialchars($rsb) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (empty($parentRolls)): ?>
        <div style="font-size:.75rem;color:#94a3b8">—</div>
      <?php endif; ?>
    </div>

    <!-- SLITTING: output rolls -->
    <?php if ($isSlitting && !empty($childRolls)): ?>
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-scissors"></i> Slit Output Rolls (<?= count($childRolls) ?>)</div>
      <?php foreach ($childRolls as $cr):
        $crn = $cr['roll_no'] ?? '';
        $cri = $rollMap[$crn] ?? [];
        $crsts = $cri['status'] ?? ($cr['status_live'] ?? '—');
        [$crbg, $crtx] = dos_statusColor($crsts);
      ?>
        <div class="ds-roll-row">
          <div>
            <span class="ds-roll-no"><?= htmlspecialchars($crn) ?></span>
            <span class="ds-roll-spec">
              <?= !empty($cr['slit_width_mm']) ? htmlspecialchars($cr['slit_width_mm']) . 'mm' : '' ?>
              <?= !empty($cr['slit_length_mtr']) ? ' × ' . htmlspecialchars($cr['slit_length_mtr']) . 'm' : '' ?>
              <?= !empty($cr['destination']) ? ' · ' . htmlspecialchars($cr['destination']) : '' ?>
              <?= !empty($cr['job_name']) ? ' · ' . htmlspecialchars($cr['job_name']) : '' ?>
            </span>
          </div>
          <span class="ds-roll-sts" style="background:<?= $crbg ?>;color:<?= $crtx ?>"><?= htmlspecialchars($crsts) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- SLITTING: stock rolls -->
    <?php if ($isSlitting && !empty($stockRolls)): ?>
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-archive"></i> Stock Rolls (<?= count($stockRolls) ?>)</div>
      <?php foreach ($stockRolls as $sr):
        $srn = $sr['roll_no'] ?? '';
        $sri = $rollMap[$srn] ?? [];
        $srsts = $sri['status'] ?? ($sr['status_live'] ?? '—');
        [$srbg, $srtx] = dos_statusColor($srsts);
      ?>
        <div class="ds-roll-row">
          <div>
            <span class="ds-roll-no"><?= htmlspecialchars($srn) ?></span>
            <span class="ds-roll-spec">
              <?= !empty($sr['slit_width_mm']) ? htmlspecialchars($sr['slit_width_mm']) . 'mm' : '' ?>
              <?= !empty($sr['slit_length_mtr']) ? ' × ' . htmlspecialchars($sr['slit_length_mtr']) . 'm' : '' ?>
            </span>
          </div>
          <span class="ds-roll-sts" style="background:<?= $srbg ?>;color:<?= $srtx ?>"><?= htmlspecialchars($srsts) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- SLITTING: batch entries from DB -->
    <?php if ($isSlitting && !empty($stageSlitEntries)): ?>
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-table"></i> Slitting Batch Details</div>
      <div class="ds-grid" style="margin-bottom:8px">
        <?php $sb = $stageSlitEntries[0]; ?>
        <div class="ds-field"><span class="df-lbl">Batch No</span><span class="df-val purple"><?= htmlspecialchars($sb['batch_no'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Batch Status</span><span class="df-val"><?= htmlspecialchars($sb['batch_status'] ?? '—') ?></span></div>
        <?php if (!empty($sb['machine'])): ?>
          <div class="ds-field"><span class="df-lbl">Machine</span><span class="df-val"><?= htmlspecialchars($sb['machine']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($sb['operator_name'])): ?>
          <div class="ds-field"><span class="df-lbl">Operator</span><span class="df-val"><?= htmlspecialchars($sb['operator_name']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php foreach ($stageSlitEntries as $se): ?>
        <div class="ds-roll-row">
          <div>
            <span class="ds-roll-no"><?= htmlspecialchars($se['child_roll_no'] ?? '—') ?></span>
            <span class="ds-roll-spec">
              <?= htmlspecialchars($se['slit_width_mm'] ?? '') ?>mm × <?= htmlspecialchars($se['slit_length_mtr'] ?? '') ?>m
              &nbsp;·&nbsp;Qty: <?= htmlspecialchars($se['qty'] ?? 1) ?>
              &nbsp;·&nbsp;<?= htmlspecialchars($se['destination'] ?? '') ?>
              <?= !empty($se['job_name']) ? ' · ' . htmlspecialchars($se['job_name']) : '' ?>
              <?= ($se['is_remainder'] ?? 0) ? ' <span style="color:#f59e0b;font-weight:800">[REMAINDER]</span>' : '' ?>
            </span>
          </div>
          <span class="ds-roll-sts" style="background:#f0fdf4;color:#16a34a"><?= htmlspecialchars($se['mode'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- PRINTING / FLEXO job card fields -->
    <?php if ($isPrinting): ?>
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-printer-fill"></i> Flexo Job Card</div>
      <div class="ds-grid">
        <div class="ds-field"><span class="df-lbl">MKD Job Sl No</span><span class="df-val purple"><?= htmlspecialchars($extra['mkd_job_sl_no'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Date</span><span class="df-val"><?= htmlspecialchars($extra['job_date'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Die</span><span class="df-val"><?= htmlspecialchars($extra['die'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Plate No</span><span class="df-val"><?= htmlspecialchars($extra['plate_no'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Label Size</span><span class="df-val"><?= htmlspecialchars($extra['label_size'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Repeat / Direction</span><span class="df-val"><?= htmlspecialchars(($extra['repeat_mm'] ?? '—') . ' / ' . ($extra['direction'] ?? '—')) ?></span></div>
        <div class="ds-field"><span class="df-lbl">Reel C1 / C2</span><span class="df-val"><?= htmlspecialchars(($extra['reel_no_c1'] ?? '—') . ' / ' . ($extra['reel_no_c2'] ?? '—')) ?></span></div>
        <div class="ds-field"><span class="df-lbl">Order QTY / MTR</span><span class="df-val"><?= htmlspecialchars(($extra['order_qty'] ?? '—') . ' / ' . ($extra['order_mtr'] ?? '—')) ?></span></div>
        <div class="ds-field"><span class="df-lbl">Actual QTY</span><span class="df-val green"><?= htmlspecialchars($extra['actual_qty'] ?? '—') ?></span></div>
        <div class="ds-field"><span class="df-lbl">Prepared by / Filled by</span><span class="df-val"><?= htmlspecialchars(($extra['prepared_by'] ?? '—') . ' / ' . ($extra['filled_by'] ?? '—')) ?></span></div>
      </div>
      <?php
        $colourLanes = $extra['colour_lanes'] ?? [];
        $aniloxLanes = $extra['anilox_lanes'] ?? [];
        $hasColours  = is_array($colourLanes) && count(array_filter($colourLanes)) > 0;
        $hasAnilox   = is_array($aniloxLanes) && count(array_filter($aniloxLanes)) > 0;
      ?>
      <?php if ($hasColours): ?>
        <div style="margin-top:10px">
          <div class="df-lbl" style="margin-bottom:4px">Colour Lanes (1–8)</div>
          <div class="ds-lane-grid">
            <?php for ($li = 0; $li < 8; $li++): ?>
              <div class="ds-lane-cell"><span class="ds-lane-num"><?= $li+1 ?></span><?= htmlspecialchars($colourLanes[$li] ?? '') ?></div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($hasAnilox): ?>
        <div style="margin-top:7px">
          <div class="df-lbl" style="margin-bottom:4px">Anilox Lanes (1–8)</div>
          <div class="ds-lane-grid">
            <?php for ($li = 0; $li < 8; $li++): ?>
              <div class="ds-lane-cell"><span class="ds-lane-num"><?= $li+1 ?></span><?= htmlspecialchars($aniloxLanes[$li] ?? '') ?></div>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- GENERIC EXTRA DATA for unknown/future departments ── -->
    <?php
    $knownExtraKeys = ['job_name','parent_roll','parent_rolls','parent_details','child_rolls','stock_rolls',
      'mkd_job_sl_no','job_date','die','plate_no','label_size','repeat_mm','direction',
      'reel_no_c1','reel_no_c2','order_qty','order_mtr','actual_qty','prepared_by','filled_by',
      'colour_lanes','anilox_lanes','planning_id','plan_job_no','jumbo_job','direct_flexo_bypass',
      'print_image_path','planning_image_path','physical_image_path','upload_image_path','image_path'];
    $genericFields = array_diff_key($extra, array_flip($knownExtraKeys));
    // Only show non-empty scalar fields
    $genericFields = array_filter($genericFields, fn($v) => is_scalar($v) && $v !== '' && $v !== null);
    if (!$isSlitting && !$isPrinting && !empty($genericFields)):
    ?>
    <div class="ds-sub">
      <div class="ds-sub-title"><i class="bi bi-info-circle"></i> <?= htmlspecialchars($deptLabel) ?> Details</div>
      <div class="ds-grid">
        <?php foreach ($genericFields as $gk => $gv): ?>
          <div class="ds-field">
            <span class="df-lbl"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $gk))) ?></span>
            <span class="df-val"><?= htmlspecialchars((string)$gv) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Timeline strip -->
    <div class="ds-tl">
      <div class="ds-tl-item"><span class="tl-lbl">Created</span><span class="tl-val"><?= dos_fmtDt($job['created_at'] ?? '') ?></span></div>
      <div class="ds-tl-item"><span class="tl-lbl">Started</span><span class="tl-val" style="color:#7c3aed"><?= dos_fmtDt($job['started_at'] ?? '') ?></span></div>
      <div class="ds-tl-item"><span class="tl-lbl">Completed</span><span class="tl-val" style="color:#16a34a"><?= dos_fmtDt($job['completed_at'] ?? '') ?></span></div>
      <?php if ($dur !== null && $dur > 0): ?>
        <div class="ds-tl-item"><span class="tl-lbl">Duration</span><span class="tl-val" style="color:#16a34a"><?= $durStr ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Notes -->
    <?php if (!empty($job['notes'])): ?>
      <div style="margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9;font-size:.78rem;color:#475569;line-height:1.6">
        <span class="df-lbl"><i class="bi bi-sticky"></i> Notes &nbsp;</span>
        <?= nl2br(htmlspecialchars($job['notes'])) ?>
      </div>
    <?php endif; ?>

    <!-- Action button (screen only) -->
    <div style="margin-top:14px" class="no-print">
      <?php
        $type = strtolower($job['job_type'] ?? '');
        $dept = strtolower($job['department'] ?? '');
        if ($type === 'slitting' || $dept === 'jumbo_slitting') {
            $deptUrl = BASE_URL . '/modules/jobs/jumbo/index.php?auto_job=' . $job['id'];
        } elseif ($type === 'printing' || str_contains($dept, 'print')) {
            $deptUrl = BASE_URL . '/modules/jobs/printing/index.php?auto_job=' . $job['id'];
        } else {
            $deptUrl = BASE_URL . '/modules/jobs/jumbo/index.php?auto_job=' . $job['id'];
        }
      ?>
      <a href="<?= htmlspecialchars($deptUrl) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#f1f5f9;border-radius:8px;font-size:.72rem;font-weight:800;text-decoration:none;color:#475569;letter-spacing:.04em;text-transform:uppercase">
        <i class="bi bi-box-arrow-up-right"></i> Open in <?= htmlspecialchars($deptLabel) ?>
      </a>
    </div>

  </div><!-- /stage-body -->
</div><!-- /stage -->

<!-- ─── PHYSICAL JOB CARD for this stage ─────────────────── -->
<?php
  $jcExtra  = $job['_extra'];
  $jcSts    = (string)($job['status'] ?? 'Pending');
  [$jcBg, $jcTx, $jcDt] = dos_statusColor($jcSts);
  $jcDeptLabel = dos_deptLabel($job);
  $jcDeptIcon  = dos_deptIcon($job);
  $jcJobName   = trim((string)($job['planning_job_name'] ?? ($jcExtra['job_name'] ?? '')));
  if ($jcJobName === '') $jcJobName = $job['job_no'];
  $jcRollNo  = trim((string)($job['roll_no'] ?? ''));
  $jcRollDat = $jcRollNo && isset($rollMap[$jcRollNo]) ? $rollMap[$jcRollNo] : [];
  $jcDur     = $job['duration_minutes'] ?? null;
  $jcDurStr  = ($jcDur !== null && $jcDur > 0) ? (floor($jcDur/60).'h '.($jcDur % 60).'m') : '—';
  $jcIsSlitting = strtolower($job['job_type'] ?? '') === 'slitting';
  $jcIsPrinting = strtolower($job['job_type'] ?? '') === 'printing' || str_contains(strtolower($job['department'] ?? ''), 'print');
  // Slit entries for this stage
  $jcSlitRows = [];
  if ($jcIsSlitting) {
      $jcParentRolls2 = $jcExtra['parent_rolls'] ?? [];
      if (is_string($jcParentRolls2)) $jcParentRolls2 = preg_split('/\s*,\s*/', $jcParentRolls2, -1, PREG_SPLIT_NO_EMPTY);
      if (empty($jcParentRolls2) && !empty($jcExtra['parent_roll'])) $jcParentRolls2 = [$jcExtra['parent_roll']];
      if (empty($jcParentRolls2) && !empty($job['roll_no'])) $jcParentRolls2 = [$job['roll_no']];
      foreach ($slitEntries as $se) {
          if (in_array($se['parent_roll_no'], $jcParentRolls2, true)) $jcSlitRows[] = $se;
      }
  }
  $jcColourLanes = $jcExtra['colour_lanes'] ?? [];
  $jcAniloxLanes = $jcExtra['anilox_lanes'] ?? [];
  $jcHasColours  = is_array($jcColourLanes) && count(array_filter($jcColourLanes)) > 0;
  $jcHasAnilox   = is_array($jcAniloxLanes) && count(array_filter($jcAniloxLanes)) > 0;
  $jcChildRolls  = is_array($jcExtra['child_rolls'] ?? null) ? $jcExtra['child_rolls'] : [];
  $jcStockRolls  = is_array($jcExtra['stock_rolls'] ?? null) ? $jcExtra['stock_rolls'] : [];
  $jcQrSelf = BASE_URL . '/modules/scan/dossier.php?jn=' . urlencode($job['job_no']);
?>
<div class="jc-wrap" id="jc-<?= $job['id'] ?>">

  <!-- Toggle button (screen only) -->
  <button class="jc-toggle-btn no-print" onclick="dosToggleCard(<?= (int)$job['id'] ?>)">
    <i class="bi bi-credit-card-2-front"></i>
    Job Card: <?= htmlspecialchars($job['job_no']) ?> — <?= htmlspecialchars($jcDeptLabel) ?>
    &nbsp;<span id="jc-arr-<?= $job['id'] ?>">▼ Show</span>
  </button>

  <div class="jc-body" id="jcb-<?= $job['id'] ?>">

    <!-- Card Header -->
    <div class="jc-header">
      <div class="jc-header-left">
        <div class="jc-co-name"><?= htmlspecialchars($companyName) ?></div>
        <div class="jc-title">Job Card</div>
        <div class="jc-jobno"><?= htmlspecialchars($job['job_no']) ?></div>
        <div class="jc-jobname"><?= htmlspecialchars($jcJobName !== $job['job_no'] ? $jcJobName : '') ?></div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <span class="jc-dept-badge"><i class="bi <?= $jcDeptIcon ?>"></i> <?= htmlspecialchars($jcDeptLabel) ?></span>
          <span class="jc-sts-badge" style="background:<?= $jcBg ?>;color:<?= $jcTx ?>">
            <span class="jc-dot" style="background:<?= $jcDt ?>"></span>
            <?= htmlspecialchars($jcSts) ?>
          </span>
        </div>
      </div>
      <div class="jc-header-right">
        <div class="jc-qr-box" id="jcqr-<?= $job['id'] ?>"></div>
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="" style="height:32px;border-radius:4px;margin-top:6px">
        <?php endif; ?>
      </div>
    </div>

    <div class="jc-content">

      <!-- Material & Roll -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-box-seam"></i> Material &amp; Roll</div>
        <div class="jc-grid">
          <div class="jc-field"><span class="jf-lbl">Roll No</span><span class="jf-val jc-teal"><?= htmlspecialchars($jcRollNo ?: '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Paper Type</span><span class="jf-val"><?= htmlspecialchars($jcRollDat['paper_type'] ?? ($job['paper_type'] ?? '—')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Supplier</span><span class="jf-val"><?= htmlspecialchars($jcRollDat['company'] ?? ($job['supplier'] ?? ($job['company'] ?? '—'))) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">GSM</span><span class="jf-val"><?= htmlspecialchars($jcRollDat['gsm'] ?? ($job['gsm'] ?? '—')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Width × Length</span><span class="jf-val"><?= htmlspecialchars((($jcRollDat['width_mm'] ?? $job['width_mm'] ?? '—') . 'mm × ' . ($jcRollDat['length_mtr'] ?? $job['length_mtr'] ?? '—') . 'm')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Weight</span><span class="jf-val"><?= ($jcRollDat['weight_kg'] ?? $job['weight_kg'] ?? '') ? htmlspecialchars($jcRollDat['weight_kg'] ?? $job['weight_kg']) . ' kg' : '—' ?></span></div>
        </div>
      </div>

      <?php if ($jcIsSlitting && !empty($jcChildRolls)): ?>
      <!-- Slitting Output Rolls -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-scissors"></i> Slit Output Rolls (<?= count($jcChildRolls) ?>)</div>
        <table class="jc-slit-table">
          <thead><tr><th>Roll No</th><th>Width</th><th>Length</th><th>Destination</th><th>Job Name</th></tr></thead>
          <tbody>
          <?php foreach ($jcChildRolls as $jcCr): ?>
            <tr>
              <td style="color:#0ea5a4;font-weight:800"><?= htmlspecialchars($jcCr['roll_no'] ?? '—') ?></td>
              <td><?= htmlspecialchars($jcCr['slit_width_mm'] ?? '—') ?> mm</td>
              <td><?= htmlspecialchars($jcCr['slit_length_mtr'] ?? '—') ?> m</td>
              <td><?= htmlspecialchars($jcCr['destination'] ?? '—') ?></td>
              <td><?= htmlspecialchars($jcCr['job_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($jcIsSlitting && !empty($jcStockRolls)): ?>
      <!-- Stock Rolls -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-archive"></i> Stock Rolls (<?= count($jcStockRolls) ?>)</div>
        <table class="jc-slit-table">
          <thead><tr><th>Roll No</th><th>Width</th><th>Length</th></tr></thead>
          <tbody>
          <?php foreach ($jcStockRolls as $jcSr): ?>
            <tr>
              <td style="color:#0ea5a4;font-weight:800"><?= htmlspecialchars($jcSr['roll_no'] ?? '—') ?></td>
              <td><?= htmlspecialchars($jcSr['slit_width_mm'] ?? '—') ?> mm</td>
              <td><?= htmlspecialchars($jcSr['slit_length_mtr'] ?? '—') ?> m</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($jcIsSlitting && !empty($jcSlitRows)): ?>
      <!-- Batch Detail -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-table"></i> Batch: <?= htmlspecialchars($jcSlitRows[0]['batch_no'] ?? '') ?> &nbsp;·&nbsp; <?= htmlspecialchars($jcSlitRows[0]['batch_status'] ?? '') ?></div>
        <table class="jc-slit-table">
          <thead><tr><th>Child Roll</th><th>Width</th><th>Length</th><th>Qty</th><th>Mode</th><th>Dest</th></tr></thead>
          <tbody>
          <?php foreach ($jcSlitRows as $jcSe): ?>
            <tr>
              <td style="color:#0ea5a4;font-weight:800"><?= htmlspecialchars($jcSe['child_roll_no'] ?? '—') ?>
                <?= ($jcSe['is_remainder'] ?? 0) ? ' <span style="color:#f59e0b">[R]</span>' : '' ?>
              </td>
              <td><?= htmlspecialchars($jcSe['slit_width_mm'] ?? '—') ?> mm</td>
              <td><?= htmlspecialchars($jcSe['slit_length_mtr'] ?? '—') ?> m</td>
              <td><?= htmlspecialchars($jcSe['qty'] ?? 1) ?></td>
              <td><?= htmlspecialchars($jcSe['mode'] ?? '—') ?></td>
              <td><?= htmlspecialchars($jcSe['destination'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php if ($jcIsPrinting): ?>
      <!-- Flexo Job Card Fields -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-printer-fill"></i> Flexo Job Card</div>
        <div class="jc-grid">
          <div class="jc-field"><span class="jf-lbl">MKD Job Sl No</span><span class="jf-val jc-purple"><?= htmlspecialchars($jcExtra['mkd_job_sl_no'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Date</span><span class="jf-val"><?= htmlspecialchars($jcExtra['job_date'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Die</span><span class="jf-val"><?= htmlspecialchars($jcExtra['die'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Plate No</span><span class="jf-val"><?= htmlspecialchars($jcExtra['plate_no'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Label Size</span><span class="jf-val"><?= htmlspecialchars($jcExtra['label_size'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Repeat / Direction</span><span class="jf-val"><?= htmlspecialchars(($jcExtra['repeat_mm'] ?? '—').' / '.($jcExtra['direction'] ?? '—')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Reel C1 / C2</span><span class="jf-val"><?= htmlspecialchars(($jcExtra['reel_no_c1'] ?? '—').' / '.($jcExtra['reel_no_c2'] ?? '—')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Order QTY / MTR</span><span class="jf-val"><?= htmlspecialchars(($jcExtra['order_qty'] ?? '—').' / '.($jcExtra['order_mtr'] ?? '—')) ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Actual QTY</span><span class="jf-val jc-green"><?= htmlspecialchars($jcExtra['actual_qty'] ?? '—') ?></span></div>
          <div class="jc-field"><span class="jf-lbl">Prepared by / Filled by</span><span class="jf-val"><?= htmlspecialchars(($jcExtra['prepared_by'] ?? '—').' / '.($jcExtra['filled_by'] ?? '—')) ?></span></div>
        </div>
        <?php if ($jcHasColours): ?>
          <div style="margin-top:8px">
            <div class="jf-lbl" style="margin-bottom:4px">Colour Lanes (1–8)</div>
            <div class="jc-lane-grid">
              <?php for ($ci = 0; $ci < 8; $ci++): ?>
                <div class="jc-lane-cell"><span class="jc-lane-num"><?= $ci+1 ?></span><?= htmlspecialchars($jcColourLanes[$ci] ?? '') ?></div>
              <?php endfor; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($jcHasAnilox): ?>
          <div style="margin-top:6px">
            <div class="jf-lbl" style="margin-bottom:4px">Anilox Lanes (1–8)</div>
            <div class="jc-lane-grid">
              <?php for ($ci = 0; $ci < 8; $ci++): ?>
                <div class="jc-lane-cell"><span class="jc-lane-num"><?= $ci+1 ?></span><?= htmlspecialchars($jcAniloxLanes[$ci] ?? '') ?></div>
              <?php endfor; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Planning info inside card -->
      <?php if (($job['planning_job_name'] ?? '') || ($job['machine'] ?? '') || ($job['operator_name'] ?? '') || ($job['scheduled_date'] ?? '')): ?>
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-clipboard2-check"></i> Planning</div>
        <div class="jc-grid">
          <?php if ($job['planning_job_name'] ?? ''): ?><div class="jc-field" style="grid-column:1/-1"><span class="jf-lbl">Job Name</span><span class="jf-val"><?= htmlspecialchars($job['planning_job_name'] ?? '') ?></span></div><?php endif; ?>
          <?php if ($job['machine'] ?? ''): ?><div class="jc-field"><span class="jf-lbl">Machine</span><span class="jf-val"><?= htmlspecialchars($job['machine'] ?? '') ?></span></div><?php endif; ?>
          <?php if ($job['operator_name'] ?? ''): ?><div class="jc-field"><span class="jf-lbl">Operator</span><span class="jf-val"><?= htmlspecialchars($job['operator_name'] ?? '') ?></span></div><?php endif; ?>
          <?php if ($job['scheduled_date'] ?? ''): ?><div class="jc-field"><span class="jf-lbl">Scheduled</span><span class="jf-val"><?= dos_fmtDate($job['scheduled_date'] ?? '') ?></span></div><?php endif; ?>
          <div class="jc-field"><span class="jf-lbl">Priority</span><span class="jf-val"><?= htmlspecialchars($job['planning_priority'] ?? 'Normal') ?></span></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Timeline -->
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-clock-history"></i> Timeline</div>
        <div class="jc-tl">
          <div class="jc-tl-item"><span class="jtl-lbl">Created</span><span class="jtl-val"><?= dos_fmtDt($job['created_at'] ?? '') ?></span></div>
          <div class="jc-tl-item"><span class="jtl-lbl">Started</span><span class="jtl-val" style="color:#7c3aed"><?= dos_fmtDt($job['started_at'] ?? '') ?></span></div>
          <div class="jc-tl-item"><span class="jtl-lbl">Completed</span><span class="jtl-val" style="color:#16a34a"><?= dos_fmtDt($job['completed_at'] ?? '') ?></span></div>
          <?php if ($jcDur !== null && $jcDur > 0): ?>
            <div class="jc-tl-item"><span class="jtl-lbl">Duration</span><span class="jtl-val" style="color:#16a34a"><?= $jcDurStr ?></span></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Notes -->
      <?php if (!empty($job['notes'])): ?>
      <div class="jc-section">
        <div class="jc-section-title"><i class="bi bi-sticky"></i> Notes</div>
        <div style="font-size:.78rem;color:#475569;line-height:1.6"><?= nl2br(htmlspecialchars($job['notes'])) ?></div>
      </div>
      <?php endif; ?>

    </div><!-- /jc-content -->

    <!-- Card footer / sign-off -->
    <div class="jc-footer">
      <div class="jc-footer-block">
        <div class="jc-footer-title">Operator</div>
        <div class="jc-footer-line"><?= htmlspecialchars($job['operator_name'] ?? '') ?> &nbsp; Signature</div>
      </div>
      <div class="jc-footer-block">
        <div class="jc-footer-title">Supervisor</div>
        <div class="jc-footer-line">Name &amp; Signature</div>
      </div>
      <div class="jc-footer-block">
        <div class="jc-footer-title">Date &amp; Time</div>
        <div class="jc-footer-line"><?= dos_fmtDt($job['completed_at'] ?? '') ?></div>
      </div>
    </div>

  </div><!-- /jc-body -->
</div><!-- /jc-wrap -->
<?php endforeach; ?>

<!-- ═══ QC SECTION ══════════════════════════════════════════ -->
<?php if ($qcJob): ?>
<div class="ds-card">
  <?php [$qbg, $qtx, $qdt] = dos_statusColor($qcStatus); ?>
  <div class="ds-card-title"><i class="bi bi-patch-check"></i> Quality Control</div>
  <div class="ds-grid">
    <div class="ds-field">
      <span class="df-lbl">QC Result</span>
      <span class="ds-badge" style="background:<?= $qbg ?>;color:<?= $qtx ?>;width:fit-content">
        <span class="ds-dot" style="background:<?= $qdt ?>"></span>
        <?= htmlspecialchars($qcStatus) ?>
      </span>
    </div>
    <div class="ds-field"><span class="df-lbl">Job No</span><span class="df-val"><?= htmlspecialchars($qcJob['job_no'] ?? '—') ?></span></div>
    <?php if (!empty($qcJob['completed_at'])): ?>
      <div class="ds-field"><span class="df-lbl">QC Date</span><span class="df-val"><?= dos_fmtDt($qcJob['completed_at']) ?></span></div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="ds-placeholder">
  <i class="bi bi-patch-exclamation"></i>
  <div class="ds-placeholder-content">
    <div class="ds-placeholder-title">QC — Not Yet Completed</div>
    <div class="ds-placeholder-sub">
      <?php if ($chainDone): ?>
        All production stages completed. Awaiting QC inspection and sign-off.
      <?php else: ?>
        QC inspection will be recorded once all production stages are completed.
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ DISPATCH SECTION ════════════════════════════════════ -->
<?php
$dispatchDate   = trim((string)(json_decode((string)($planning['extra_data'] ?? '{}'), true)['dispatch_date'] ?? ''));
$dispatchNotes  = trim((string)(json_decode((string)($planning['extra_data'] ?? '{}'), true)['dispatch_notes'] ?? ''));
?>
<?php if ($chainDone && $dispatchDate): ?>
<div class="ds-card">
  <div class="ds-card-title"><i class="bi bi-truck"></i> Dispatch &amp; Delivery</div>
  <div class="ds-grid">
    <div class="ds-field"><span class="df-lbl">Dispatch Date</span><span class="df-val green"><?= dos_fmtDate($dispatchDate) ?></span></div>
    <?php if ($clientName): ?><div class="ds-field"><span class="df-lbl">Deliver To</span><span class="df-val"><?= htmlspecialchars($clientName) ?></span></div><?php endif; ?>
    <?php if ($orderNo): ?><div class="ds-field"><span class="df-lbl">Against Order</span><span class="df-val"><?= htmlspecialchars($orderNo) ?></span></div><?php endif; ?>
  </div>
  <?php if ($dispatchNotes): ?>
    <div style="margin-top:10px;font-size:.78rem;color:#475569"><?= nl2br(htmlspecialchars($dispatchNotes)) ?></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="ds-placeholder">
  <i class="bi bi-truck"></i>
  <div class="ds-placeholder-content">
    <div class="ds-placeholder-title">Dispatch — <?= $chainDone ? 'Ready for Dispatch' : 'Awaiting Production' ?></div>
    <div class="ds-placeholder-sub">
      <?= $chainDone
          ? 'All stages completed. Delivery details will be recorded once dispatched.'
          : 'Dispatch details will be available once all production and QC stages are complete.' ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ SUMMARY STATS ═══════════════════════════════════════ -->
<?php
$totalDuration = array_sum(array_column($chain, 'duration_minutes'));
$completedStages = count(array_filter($chain, fn($j) => in_array(strtolower($j['status']), ['completed','finalized','closed','qc passed'], true)));
?>
<div class="ds-card" style="margin-top:8px">
  <div class="ds-card-title"><i class="bi bi-bar-chart-fill"></i> Production Summary</div>
  <div class="ds-grid">
    <div class="ds-field"><span class="df-lbl">Total Stages</span><span class="df-val"><?= count($chain) ?></span></div>
    <div class="ds-field"><span class="df-lbl">Completed Stages</span><span class="df-val green"><?= $completedStages ?> / <?= count($chain) ?></span></div>
    <?php if ($totalDuration > 0): ?>
      <div class="ds-field"><span class="df-lbl">Total Production Time</span><span class="df-val"><?= floor($totalDuration/60) ?>h <?= $totalDuration % 60 ?>m</span></div>
    <?php endif; ?>
    <?php if ($clientName): ?>
      <div class="ds-field"><span class="df-lbl">Client</span><span class="df-val purple"><?= htmlspecialchars($clientName) ?></span></div>
    <?php endif; ?>
    <?php if ($soDue): ?>
      <div class="ds-field"><span class="df-lbl">Due Date</span><span class="df-val"><?= dos_fmtDate($soDue) ?></span></div>
    <?php endif; ?>
    <div class="ds-field"><span class="df-lbl">Overall Status</span>
      <span class="df-val <?= $chainDone ? 'green' : 'purple' ?>">
        <?= $chainDone ? 'Production Complete' : 'In Progress (' . $completedStages . '/' . count($chain) . ')' ?>
      </span>
    </div>
  </div>
</div>

<!-- ═══ SIGN-OFF BLOCK ══════════════════════════════════════ -->
<div class="ds-signoff">
  <div class="ds-signoff-block">
    <div class="ds-signoff-title">Production Manager</div>
    <div class="ds-signoff-line">Name &amp; Signature</div>
  </div>
  <div class="ds-signoff-block">
    <div class="ds-signoff-title">Quality Control</div>
    <div class="ds-signoff-line">Name &amp; Signature</div>
  </div>
  <div class="ds-signoff-block">
    <div class="ds-signoff-title">Dispatch / Delivery</div>
    <div class="ds-signoff-line">Name &amp; Signature</div>
  </div>
</div>

<div class="ds-stamp">
  <?= htmlspecialchars($companyName) ?> &nbsp;|&nbsp;
  Generated: <?= date('d M Y, H:i:s') ?> &nbsp;|&nbsp;
  <strong><?= htmlspecialchars($jn) ?></strong>
</div>

<?php endif; /* end !$error */ ?>

</div><!-- /ds-wrap -->

<script src="<?= BASE_URL ?>/assets/js/qrcode.min.js"></script>
<script>
// Toggle individual job card visibility on screen
function dosToggleCard(id) {
  var body = document.getElementById('jcb-' + id);
  var arr  = document.getElementById('jc-arr-' + id);
  if (!body) return;
  var open = body.classList.toggle('open');
  if (arr) arr.textContent = open ? '▲ Hide' : '▼ Show';
}

// Generate QR codes for each job card holder
(function() {
  var cards = <?= json_encode(array_map(function($j) {
    return [
      'id'  => (int)$j['id'],
      'url' => BASE_URL . '/modules/scan/dossier.php?jn=' . rawurlencode($j['job_no'])
    ];
  }, $chain), JSON_HEX_TAG|JSON_HEX_APOS) ?>;

  cards.forEach(function(c) {
    var box = document.getElementById('jcqr-' + c.id);
    if (!box) return;
    try {
      new QRCode(box, {
        text: c.url,
        width: 72, height: 72,
        colorDark: '#0f172a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
      });
    } catch(e) {}
  });
})();
</script>

</body>
</html>
