<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$pageTitle = 'Production Summary';

function pm_text($value): string {
    return trim((string)($value ?? ''));
}

function pm_job_activity_score($status): int {
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));
  if ($norm === 'running' || $norm === 'in progress' || $norm === 'inprogress') {
    return 3;
  }
  if ($norm === 'finished production' || $norm === 'dispatched' || $norm === 'dispatch') {
    return 4;
  }
  if ($norm === 'pending') {
    return 2;
  }
  if ($norm === 'queued') {
    return 1;
  }
  return 0;
}

function pm_department_label($dept, $fallbackType = ''): string {
    $dept = strtolower(trim((string)$dept));
    if ($dept === '') {
        $dept = strtolower(trim((string)$fallbackType));
    }

    $map = [
        'jumbo slitting' => 'Jumbo Slitting',
        'jumbo-slitting' => 'Jumbo Slitting',
        'jumbo_slitting' => 'Jumbo Slitting',
      'jumbo' => 'Jumbo Slitting',
        'printing' => 'Printing',
        'flexo printing' => 'Printing',
        'flexo_printing' => 'Printing',
        'die-cutting' => 'Die-Cutting',
        'die cutting' => 'Die-Cutting',
        'flatbed' => 'Die-Cutting',
        'barcode' => 'Barcode',
        'label-slitting' => 'Label Slitting',
        'label slitting' => 'Label Slitting',
        'packaging' => 'Packaging',
        'packing' => 'Packaging',
        'dispatch' => 'Dispatch',
        'slitting' => 'Slitting',
        'printing_planning' => 'Printing',
        'pos' => 'POS Roll',
        'pos roll' => 'POS Roll',
        'pos_roll' => 'POS Roll',
        'paperroll' => 'Paper Roll',
        'paper roll' => 'Paper Roll',
        'paper_roll' => 'Paper Roll',
        'oneply' => 'One Ply',
        'one ply' => 'One Ply',
        'one_ply' => 'One Ply',
        'paper_roll_1ply' => 'One Ply',
        'twoply' => 'Two Ply',
        'two ply' => 'Two Ply',
        'two_ply' => 'Two Ply',
        'paper_roll_2ply' => 'Two Ply',
    ];

    if (isset($map[$dept])) return $map[$dept];
    if ($dept === '') return 'Not Started';
    return ucwords(str_replace(['_', '-'], ' ', $dept));
}

  function pm_planning_type_key($value): string {
    return strtolower(trim(str_replace([' ', '_'], '-', (string)$value)));
  }

  function pm_route_from_plan_context($planningNo, $planningType, $planningDept, $currentRoute): string {
    $planningNo = strtoupper(pm_text($planningNo));
    $typeKey = pm_planning_type_key($planningType);
    $deptKey = pm_planning_type_key($planningDept);
    $route = pm_text($currentRoute);

    if ($typeKey === '' || $typeKey === 'planning') {
      if (strpos($planningNo, 'PLN-BAR/') === 0) {
        $typeKey = 'barcode';
      } elseif (strpos($planningNo, 'PLN-POS/') === 0) {
        $typeKey = 'pos-roll';
      } elseif (strpos($planningNo, 'PLN-PRL/') === 0) {
        $typeKey = 'paperroll';
      } elseif (strpos($planningNo, 'PLN-1PL/') === 0) {
        $typeKey = 'one-ply';
      } elseif (strpos($planningNo, 'PLN-2PL/') === 0) {
        $typeKey = 'two-ply';
      } elseif (strpos($planningNo, 'PLN/') === 0) {
        $typeKey = 'label-printing';
      }
    }

    if ($typeKey === '' || $typeKey === 'planning') {
      if (strpos($deptKey, 'label') !== false) {
        $typeKey = 'label-printing';
      } elseif (strpos($deptKey, 'barcode') !== false) {
        $typeKey = 'barcode';
      } elseif (strpos($deptKey, 'pos') !== false) {
        $typeKey = 'pos-roll';
      } elseif (strpos($deptKey, 'paper') !== false) {
        $typeKey = 'paperroll';
      }
    }

    $routeMap = [
      'label-printing' => 'Jumbo Slitting, Printing, Label Slitting, Packaging, Finished Production',
      'label' => 'Jumbo Slitting, Printing, Label Slitting, Packaging, Finished Production',
      'barcode' => 'Jumbo Slitting, Printing, Barcode, Packaging, Finished Production',
      'barcoding' => 'Jumbo Slitting, Printing, Barcode, Packaging, Finished Production',
      'pos-roll' => 'POS Roll, Packaging, Finished Production',
      'posroll' => 'POS Roll, Packaging, Finished Production',
      'paperroll' => 'Paper Roll, Packaging, Finished Production',
      'paper-roll' => 'Paper Roll, Packaging, Finished Production',
      'one-ply' => 'One Ply, Packaging, Finished Production',
      'oneply' => 'One Ply, Packaging, Finished Production',
      'two-ply' => 'Two Ply, Packaging, Finished Production',
      'twoply' => 'Two Ply, Packaging, Finished Production',
    ];

    if (isset($routeMap[$typeKey])) {
      return $routeMap[$typeKey];
    }

    if ($route !== '') {
      return $route;
    }

    return pm_text($planningDept);
  }

  function pm_pln_first_actionable_stage_from_chain($chainSummary, mysqli $db = null, int $planningId = 0): string {
    // First check if any job in this planning already reached a terminal downstream state.
    if ($db !== null && $planningId > 0) {
      $stmt = $db->prepare("SELECT status, extra_data FROM jobs WHERE planning_id = ? ORDER BY updated_at DESC, id DESC");
      if ($stmt) {
        $stmt->bind_param('i', $planningId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($jobRow = $result->fetch_assoc()) {
          $extra = json_decode($jobRow['extra_data'] ?? '{}', true);
          $statusNorm = strtolower(trim(str_replace(['-', '_'], ' ', (string)($jobRow['status'] ?? ''))));
          if (is_array($extra)) {
            $hasFinishedFlag = (int)($extra['finished_production_flag'] ?? 0) === 1
              || trim((string)($extra['finished_production_at'] ?? '')) !== '';
            if ($hasFinishedFlag || $statusNorm === 'finished production') {
              $stmt->close();
              return 'Finished Production';
            }
            $hasPackedFlag = (int)($extra['packing_done_flag'] ?? 0) === 1
              || (int)($extra['packing_packed_flag'] ?? 0) === 1
              || trim((string)($extra['packing_done_at'] ?? '')) !== '';
            if ($hasPackedFlag) {
              $stmt->close();
              return 'Packaging';
            }
          }
          if (in_array($statusNorm, ['dispatched', 'dispatch'], true)) {
            $stmt->close();
            return 'Dispatch';
          }
        }
        $stmt->close();
      }
    }
    
    $chain = strtolower(pm_text($chainSummary));
    if ($chain === '') {
      return '';
    }

    $entries = array_filter(array_map('trim', explode('|', $chain)));
    if (empty($entries)) {
      return '';
    }

    $statusByDept = [];
    foreach ($entries as $entry) {
      $parts = explode(':', $entry, 2);
      if (count($parts) !== 2) {
        continue;
      }
      $dept = strtolower(trim($parts[0]));
      $status = strtolower(trim($parts[1]));
      if ($dept === '' || $status === '') {
        continue;
      }
      $statusByDept[$dept] = $status;
    }

    $isActionable = static function ($status): bool {
      return in_array($status, ['pending', 'queued', 'running', 'in progress', 'inprogress'], true);
    };

    $orderedDeptMap = [
      'jumbo_slitting' => 'Jumbo Slitting',
      'jumbo-slitting' => 'Jumbo Slitting',
      'jumbo slitting' => 'Jumbo Slitting',
      'jumbo' => 'Jumbo Slitting',
      'flexo_printing' => 'Printing',
      'flexo-printing' => 'Printing',
      'printing' => 'Printing',
      'label_slitting' => 'Label Slitting',
      'label-slitting' => 'Label Slitting',
      'label slitting' => 'Label Slitting',
      'packing' => 'Packaging',
      'packaging' => 'Packaging',
      'dispatch' => 'Dispatch',
    ];

    foreach ($orderedDeptMap as $deptKey => $label) {
      if (isset($statusByDept[$deptKey]) && $isActionable($statusByDept[$deptKey])) {
        return $label;
      }
    }

    return '';
  }

function pm_pick_stage_job_card(array $cards, $stageLabel): array {
  $target = strtolower(pm_text($stageLabel));
  if ($target === '' || empty($cards)) {
    return [];
  }

  $best = null;
  foreach ($cards as $card) {
    $cardStage = strtolower(pm_text($card['stage'] ?? ''));
    $score = (int)($card['activity_score'] ?? 0);
    if ($cardStage !== $target || $score <= 0) {
      continue;
    }

    if ($best === null) {
      $best = $card;
      continue;
    }

    $bestScore = (int)($best['activity_score'] ?? 0);
    $cardId = (int)($card['job_id'] ?? 0);
    $bestId = (int)($best['job_id'] ?? 0);

    if ($score > $bestScore) {
      $best = $card;
      continue;
    }

    if ($score === $bestScore) {
      if ($score === 3) {
        // Running: prefer latest movement in same stage.
        if ($cardId > $bestId) {
          $best = $card;
        }
      } else {
        // Pending/queued: prefer earliest actionable card in same stage.
        if ($bestId === 0 || ($cardId > 0 && $cardId < $bestId)) {
          $best = $card;
        }
      }
    }
  }

  return is_array($best) ? $best : [];
}

function pm_is_pending_like_status($status): bool {
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));
  return in_array($norm, ['pending', 'queued', 'preparing'], true);
}

function pm_is_started_status($status): bool {
  $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));
  if ($norm === '') {
    return false;
  }
  if (in_array($norm, ['pending', 'queued', 'preparing'], true)) {
    return false;
  }
  return true;
}

function pm_stage_rank_for_label($stage): int {
  $stage = strtolower(pm_text($stage));
  if ($stage === 'jumbo slitting') return 1;
  if ($stage === 'printing') return 2;
  if ($stage === 'die-cutting' || $stage === 'barcode') return 3;
  if ($stage === 'label slitting') return 4;
  if ($stage === 'packaging') return 5;
  if ($stage === 'finished production') return 6;
  if ($stage === 'dispatch') return 6;
  return 99;
}

function pm_bucket_status(array $row): string {
  $jobStatus = strtolower(pm_text(($row['effective_status'] ?? '') ?: ($row['latest_job_status'] ?: $row['active_job_status'])));
    $boardStatus = strtolower(pm_text($row['board_status']));
    $planningStatus = strtolower(pm_text($row['planning_status']));

    if ($jobStatus === 'finished production') return 'Finished Production';
    if (in_array($jobStatus, ['dispatched', 'dispatch'], true)) return 'Dispatched';
    if (in_array($jobStatus, ['ready to dispatch', 'ready to dispatched', 'ready to dispathce', 'packed', 'packing done', 'finished barcode'], true)) return 'Packed';

    $haystack = $jobStatus . ' ' . $boardStatus . ' ' . $planningStatus;
    if (strpos($haystack, 'running') !== false || strpos($haystack, 'in progress') !== false) return 'Running';
    if (strpos($haystack, 'pause') !== false || strpos($haystack, 'hold') !== false) return 'On Hold';
    if (
        strpos($haystack, 'completed') !== false ||
        strpos($haystack, 'finalized') !== false ||
        strpos($haystack, 'closed') !== false ||
        strpos($haystack, 'qc passed') !== false ||
        strpos($haystack, 'dispatch') !== false ||
        strpos($haystack, 'finished') !== false ||
        strpos($haystack, 'barcoded') !== false ||
        strpos($haystack, 'packing done') !== false ||
        strpos($haystack, 'packed') !== false
    ) return 'Completed';
    if (strpos($haystack, 'pending') !== false || strpos($haystack, 'queued') !== false || strpos($haystack, 'preparing') !== false) return 'Pending';
    return 'Pending';
}

function pm_should_show_board_status(array $row): bool {
  $board = pm_text($row['board_status'] ?? '');
  if ($board === '') {
    return false;
  }

  $latestDept = strtolower(pm_text($row['latest_job_department'] ?? ''));
  $activeDept = strtolower(pm_text($row['active_job_department'] ?? ''));
  $latestType = strtolower(pm_text($row['latest_job_type'] ?? ''));
  $activeType = strtolower(pm_text($row['active_job_type'] ?? ''));
  $current = trim($latestDept . ' ' . $activeDept . ' ' . $latestType . ' ' . $activeType);

  if ($current === '') {
    return true;
  }

  // Board status is relevant for barcode/printing chain, not for downstream paperroll/POS/ply stages.
  $downstreamNeedless = [
    'pos', 'pos_roll', 'pos roll',
    'paperroll', 'paper_roll', 'paper roll',
    'oneply', 'one_ply', 'one ply',
    'twoply', 'two_ply', 'two ply',
    'packing', 'packaging', 'dispatch'
  ];
  foreach ($downstreamNeedless as $needle) {
    if (strpos($current, $needle) !== false) {
      return false;
    }
  }

  return true;
}

  function pm_display_status($status): string {
    return erp_status_visual_label($status);
  }

  function pm_decode_json_assoc($json): array {
    if (is_array($json)) {
      return $json;
    }
    if (!is_string($json) || trim($json) === '') {
      return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
  }

  function pm_status_priority_from_job($status, array $extra): array {
    $norm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($status))));

    $finishedBarcodeFlag = (int)($extra['finished_barcode_flag'] ?? 0) === 1
      || pm_text($extra['finished_barcode_at'] ?? '') !== '';
    $finishedFlag = (int)($extra['finished_production_flag'] ?? 0) === 1
      || pm_text($extra['finished_production_at'] ?? '') !== '';
    $finishedLabelFlag = (int)($extra['finished_label_flag'] ?? 0) === 1
      || pm_text($extra['finished_label_at'] ?? '') !== '';
    $packedFlag = (int)($extra['packing_done_flag'] ?? 0) === 1
      || (int)($extra['packing_packed_flag'] ?? 0) === 1
      || pm_text($extra['packing_done_at'] ?? '') !== '';

    // Only truly final statuses → Finished Production
    if ($finishedFlag || $norm === 'finished production') {
      return [
        'priority' => 5,
        'status' => 'Finished Production',
      ];
    }
    if (in_array($norm, ['dispatched', 'dispatch', 'shipped'], true)) {
      return [
        'priority' => 3,
        'status' => 'Dispatched',
      ];
    }
    if ($packedFlag || in_array($norm, ['packed', 'packing done'], true)) {
      return ['priority' => 2, 'status' => 'Packed'];
    }
    // Barcode done — intermediate above Completed
    if ($finishedBarcodeFlag || $norm === 'finished barcode') {
      return ['priority' => 2, 'status' => 'Finished Barcode'];
    }
    if (in_array($norm, ['completed', 'complete', 'closed', 'finalized', 'qc passed', 'qc failed'], true)) {
      return ['priority' => 1, 'status' => 'Completed'];
    }
    // Label slitted — intermediate step, packing still pending
    if ($finishedLabelFlag || $norm === 'finished label') {
      return ['priority' => 1, 'status' => 'Label Slitted'];
    }
    if (in_array($norm, ['running', 'in progress', 'inprogress'], true)) {
      return ['priority' => 0, 'status' => 'Running'];
    }
    return ['priority' => -1, 'status' => pm_display_status($status !== '' ? $status : 'Pending')];
  }

  function pm_is_upstream_finished_for_packing(array $row): bool {
    $haystack = strtolower(pm_text(
      ($row['effective_status'] ?? '') . ' ' .
      ($row['latest_job_status'] ?? '') . ' ' .
      ($row['active_job_status'] ?? '') . ' ' .
      ($row['board_status'] ?? '') . ' ' .
      ($row['planning_status'] ?? '')
    ));

    $doneSignals = [
      'finished barcode',
      'barcoded',
      'label slitted',
      'label slitting finished',
      'completed',
      'closed',
      'finalized',
      'qc passed',
      'finished',
    ];
    foreach ($doneSignals as $signal) {
      if (strpos($haystack, $signal) !== false) {
        return true;
      }
    }
    return false;
  }

  function pm_has_non_packing_actionable_stage(array $row): bool {
    $chain = strtolower(pm_text($row['chain_summary'] ?? ''));
    if ($chain !== '') {
      $items = array_filter(array_map('trim', explode('|', $chain)));
      foreach ($items as $item) {
        $parts = explode(':', $item, 2);
        if (count($parts) !== 2) {
          continue;
        }
        $dept = strtolower(trim((string)$parts[0]));
        $status = strtolower(trim((string)$parts[1]));
        if (!in_array($status, ['pending', 'queued', 'running', 'in progress', 'inprogress'], true)) {
          continue;
        }
        if (
          strpos($dept, 'pack') === false &&
          strpos($dept, 'dispatch') === false
        ) {
          return true;
        }
      }
    }

    $statusBag = strtolower(pm_text(
      ($row['active_job_status'] ?? '') . ' ' .
      ($row['latest_job_status'] ?? '')
    ));
    $deptBag = strtolower(pm_text(
      ($row['active_job_department'] ?? '') . ' ' .
      ($row['active_job_type'] ?? '') . ' ' .
      ($row['latest_job_department'] ?? '') . ' ' .
      ($row['latest_job_type'] ?? '')
    ));

    $hasActionable =
      strpos($statusBag, 'pending') !== false ||
      strpos($statusBag, 'queued') !== false ||
      strpos($statusBag, 'running') !== false ||
      strpos($statusBag, 'in progress') !== false;

    $isNonPackingDept =
      strpos($deptBag, 'pack') === false &&
      strpos($deptBag, 'dispatch') === false;

    return $hasActionable && $isNonPackingDept;
  }

  function pm_label_slitting_completed(array $row): bool {
    $chain = strtolower(pm_text($row['chain_summary'] ?? ''));
    if ($chain !== '') {
      $items = array_filter(array_map('trim', explode('|', $chain)));
      foreach ($items as $item) {
        $parts = explode(':', $item, 2);
        if (count($parts) !== 2) {
          continue;
        }
        $dept = strtolower(trim((string)$parts[0]));
        $status = strtolower(trim((string)$parts[1]));
        if (strpos($dept, 'label_slitting') !== false || strpos($dept, 'label-slitting') !== false || strpos($dept, 'label slitting') !== false) {
          if (in_array($status, ['completed', 'complete', 'closed', 'finalized', 'qc passed', 'finished label'], true)) {
            return true;
          }
        }
      }
    }

    $statusBag = strtolower(pm_text(
      ($row['latest_job_status'] ?? '') . ' ' .
      ($row['active_job_status'] ?? '') . ' ' .
      ($row['effective_status'] ?? '')
    ));
    $deptBag = strtolower(pm_text(
      ($row['latest_job_department'] ?? '') . ' ' .
      ($row['active_job_department'] ?? '') . ' ' .
      ($row['latest_job_type'] ?? '') . ' ' .
      ($row['active_job_type'] ?? '')
    ));

    return (
      (strpos($deptBag, 'label') !== false && strpos($deptBag, 'slitting') !== false) &&
      (
        strpos($statusBag, 'completed') !== false ||
        strpos($statusBag, 'closed') !== false ||
        strpos($statusBag, 'finalized') !== false ||
        strpos($statusBag, 'qc passed') !== false ||
        strpos($statusBag, 'finished label') !== false
      )
    );
  }

  function pm_should_apply_packing_fallback(array $row): bool {
    $current = strtolower(pm_text(($row['effective_status'] ?? '') ?: ($row['latest_job_status'] ?? '') ?: ($row['active_job_status'] ?? '')));
    if (
      strpos($current, 'pack') !== false ||
      strpos($current, 'dispatch') !== false ||
      strpos($current, 'finished production') !== false
    ) {
      return false;
    }

    // Do not jump to packing while non-packing stages still have actionable cards.
    if (pm_has_non_packing_actionable_stage($row)) {
      return false;
    }

    return pm_is_upstream_finished_for_packing($row);
  }

  function pm_route_has_packing(array $row): bool {
    $route = strtolower(pm_text($row['department_route'] ?? ''));
    $planningDept = strtolower(pm_text($row['planning_department'] ?? ''));
    if ($route !== '' && (strpos($route, 'pack') !== false || strpos($route, 'packag') !== false)) {
      return true;
    }
    if ($planningDept !== '' && (strpos($planningDept, 'pack') !== false || strpos($planningDept, 'packag') !== false)) {
      return true;
    }
    return false;
  }

  function pm_barcode_completed_should_move_to_packing(array $row): bool {
    $latestDept = strtolower(pm_text($row['latest_job_department'] ?? ''));
    $activeDept = strtolower(pm_text($row['active_job_department'] ?? ''));
    $latestStatus = strtolower(pm_text($row['latest_job_status'] ?? ''));
    $activeStatus = strtolower(pm_text($row['active_job_status'] ?? ''));
    $planningStatus = strtolower(pm_text($row['planning_status'] ?? ''));

    $deptLooksBarcode =
      strpos($latestDept, 'barcode') !== false ||
      strpos($activeDept, 'barcode') !== false;

    if (!$deptLooksBarcode) {
      return false;
    }

    $statusBag = $latestStatus . ' ' . $activeStatus . ' ' . $planningStatus;
    $barcodeDone =
      strpos($statusBag, 'barcoded') !== false ||
      strpos($statusBag, 'finished barcode') !== false ||
      strpos($statusBag, 'barcode:completed') !== false ||
      strpos($statusBag, 'completed') !== false;

    if (!$barcodeDone) {
      return false;
    }

    // If already terminal, do not force Packing fallback.
    if (
      strpos($statusBag, 'finished production') !== false ||
      strpos($statusBag, 'dispatched') !== false ||
      strpos($statusBag, 'delivered') !== false
    ) {
      return false;
    }

    return true;
  }
/* ── Delete planning row ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && pm_text($_POST['action'] ?? '') === 'delete_planning') {
    $delId   = (int)($_POST['planning_id'] ?? 0);
    $delCsrf = pm_text($_POST['csrf_token'] ?? '');
    // auth_check.php already verified the user; also accept token match OR regenerated-session fallback
    $csrfOk  = verifyCSRF($delCsrf) || (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0);
    if ($delId > 0 && $csrfOk) {
        $db->query("UPDATE planning SET deleted_at=NOW() WHERE id=" . $delId);
    }
    $qs = http_build_query(array_filter([
        'q'      => pm_text($_GET['q'] ?? ''),
        'status' => pm_text($_GET['status'] ?? ''),
        'stage'  => pm_text($_GET['stage'] ?? ''),
    ]));
    header('Location: ' . BASE_URL . '/modules/production-manager/index.php' . ($qs ? '?' . $qs : ''));
    exit;
}
$q = pm_text($_GET['q'] ?? '');
$statusFilter = pm_text($_GET['status'] ?? '');
$stageFilter = pm_text($_GET['stage'] ?? '');
if (in_array(strtolower($statusFilter), ['ready to dispatch', 'ready to dispatched', 'ready to dispathce'], true)) {
  $statusFilter = 'Packed';
}

$where = [];
$types = '';
$params = [];

if ($q !== '') {
    $where[] = "(p.job_no LIKE ? OR p.job_name LIKE ? OR p.notes LIKE ? OR a.job_no LIKE ? OR l.job_no LIKE ?)";
    $like = '%' . $q . '%';
    $types .= 'sssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where[] = "(p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";

$sql = "SELECT
    p.id,
    p.job_no,
    p.job_name,
    p.priority,
    p.status AS planning_status,
    p.department AS planning_department,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.planning_type')) ELSE '' END AS planning_type,
    p.notes,
    p.updated_at AS planning_updated_at,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.printing_planning')) ELSE '' END AS board_status,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.department_route')) ELSE '' END AS department_route,
    CASE WHEN JSON_VALID(p.extra_data) THEN JSON_UNQUOTE(JSON_EXTRACT(p.extra_data, '$.dispatch_date')) ELSE '' END AS dispatch_date,
    a.job_no AS active_job_no,
    a.department AS active_job_department,
    a.job_type AS active_job_type,
    a.status AS active_job_status,
    a.updated_at AS active_job_updated_at,
    l.job_no AS latest_job_no,
    l.department AS latest_job_department,
    l.job_type AS latest_job_type,
    l.status AS latest_job_status,
    l.updated_at AS latest_job_updated_at,
    l.completed_at AS latest_job_completed_at,
    js.total_jobs,
    js.completed_jobs,
    js.running_jobs,
    js.pending_jobs,
    js.chain_summary,
    js.last_job_at
FROM planning p
LEFT JOIN (
    SELECT j1.*
    FROM jobs j1
    INNER JOIN (
        SELECT planning_id, MAX(id) AS max_id
        FROM jobs
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
          AND status IN ('Running','Pending','Queued')
        GROUP BY planning_id
    ) x ON x.max_id = j1.id
) a ON a.planning_id = p.id
LEFT JOIN (
    SELECT j2.*
    FROM jobs j2
    INNER JOIN (
        SELECT planning_id, MAX(id) AS max_id
        FROM jobs
        WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
        GROUP BY planning_id
    ) y ON y.max_id = j2.id
) l ON l.planning_id = p.id
LEFT JOIN (
    SELECT
        planning_id,
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN status IN ('Completed','Closed','Finalized','QC Passed') THEN 1 ELSE 0 END) AS completed_jobs,
        SUM(CASE WHEN status = 'Running' THEN 1 ELSE 0 END) AS running_jobs,
        SUM(CASE WHEN status IN ('Pending','Queued') THEN 1 ELSE 0 END) AS pending_jobs,
        GROUP_CONCAT(CONCAT(COALESCE(NULLIF(department, ''), job_type), ':', status) ORDER BY id SEPARATOR ' | ') AS chain_summary,
        MAX(updated_at) AS last_job_at
    FROM jobs
    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
    GROUP BY planning_id
) js ON js.planning_id = p.id";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT 500';

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$rows = [];
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
}

$effectiveStatusByPlanning = [];
$packingStatusByPlanning = [];
$currentJobByPlanning = [];
$jobCardsByPlanning = [];
if (!empty($rows)) {
  $planningIds = array_values(array_unique(array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
  }, $rows)));
  $planningIds = array_values(array_filter($planningIds, static function ($id) {
    return $id > 0;
  }));

  if (!empty($planningIds)) {
    $in = implode(',', array_fill(0, count($planningIds), '?'));
    $types2 = str_repeat('i', count($planningIds));
    $jobSql = "SELECT planning_id, job_no, department, job_type, status, extra_data, updated_at, id
           FROM jobs
           WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
           AND planning_id IN ($in)
           ORDER BY planning_id ASC, id ASC";
    $jobStmt = $db->prepare($jobSql);
    if ($jobStmt) {
      $jobStmt->bind_param($types2, ...$planningIds);
      $jobStmt->execute();
      $jobRes = $jobStmt->get_result();
      if ($jobRes instanceof mysqli_result) {
        while ($jobRow = $jobRes->fetch_assoc()) {
          $pid = (int)($jobRow['planning_id'] ?? 0);
          if ($pid <= 0) {
            continue;
          }
          $extra = pm_decode_json_assoc($jobRow['extra_data'] ?? null);
          $evaluated = pm_status_priority_from_job((string)($jobRow['status'] ?? ''), $extra);
          $priority = (int)($evaluated['priority'] ?? 0);
          $statusText = pm_text($evaluated['status'] ?? '');
          $updatedAt = pm_text($jobRow['updated_at'] ?? '');
          $jobId = (int)($jobRow['id'] ?? 0);
          $activityScore = pm_job_activity_score($jobRow['status'] ?? '');

          $jobCardsByPlanning[$pid][] = [
            'job_id' => $jobId,
            'job_no' => pm_text($jobRow['job_no'] ?? ''),
            'department' => pm_text($jobRow['department'] ?? ''),
            'job_type' => pm_text($jobRow['job_type'] ?? ''),
            'status' => pm_text($jobRow['status'] ?? ''),
            'activity_score' => $activityScore,
            'stage' => pm_department_label($jobRow['department'] ?? '', $jobRow['job_type'] ?? ''),
          ];

          if (!isset($effectiveStatusByPlanning[$pid])) {
            $effectiveStatusByPlanning[$pid] = [
              'priority' => $priority,
              'status' => $statusText,
              'updated_at' => $updatedAt,
              'job_id' => $jobId,
            ];
            continue;
          }

          $current = $effectiveStatusByPlanning[$pid];
          $shouldReplace = false;
          if ($priority > (int)$current['priority']) {
            $shouldReplace = true;
          } elseif ($priority === (int)$current['priority']) {
            if ($updatedAt !== '' && ($current['updated_at'] === '' || strcmp($updatedAt, (string)$current['updated_at']) >= 0)) {
              $shouldReplace = true;
            } elseif ($updatedAt === (string)$current['updated_at'] && $jobId >= (int)$current['job_id']) {
              $shouldReplace = true;
            }
          }

          if ($shouldReplace) {
            $effectiveStatusByPlanning[$pid] = [
              'priority' => $priority,
              'status' => $statusText,
              'updated_at' => $updatedAt,
              'job_id' => $jobId,
            ];
          }

          if ($activityScore > 0) {
            if (!isset($currentJobByPlanning[$pid])) {
              $currentJobByPlanning[$pid] = [
                'score' => $activityScore,
                'updated_at' => $updatedAt,
                'job_id' => $jobId,
                'job_no' => pm_text($jobRow['job_no'] ?? ''),
                'department' => pm_text($jobRow['department'] ?? ''),
                'job_type' => pm_text($jobRow['job_type'] ?? ''),
                'status' => pm_text($jobRow['status'] ?? ''),
              ];
            } else {
              $currentActive = $currentJobByPlanning[$pid];
              $replaceActive = false;
              if ($activityScore > (int)$currentActive['score']) {
                $replaceActive = true;
              } elseif ($activityScore === (int)$currentActive['score']) {
                if ($activityScore === 3) {
                  // For running jobs, show the latest active movement.
                  if ($updatedAt !== '' && ($currentActive['updated_at'] === '' || strcmp($updatedAt, (string)$currentActive['updated_at']) >= 0)) {
                    $replaceActive = true;
                  } elseif ($updatedAt === (string)$currentActive['updated_at'] && $jobId >= (int)$currentActive['job_id']) {
                    $replaceActive = true;
                  }
                } else {
                  // For pending/queued chains, show the first actionable stage (earliest card).
                  if ($jobId > 0 && ((int)$currentActive['job_id'] === 0 || $jobId < (int)$currentActive['job_id'])) {
                    $replaceActive = true;
                  }
                }
              }

              if ($replaceActive) {
                $currentJobByPlanning[$pid] = [
                  'score' => $activityScore,
                  'updated_at' => $updatedAt,
                  'job_id' => $jobId,
                  'job_no' => pm_text($jobRow['job_no'] ?? ''),
                  'department' => pm_text($jobRow['department'] ?? ''),
                  'job_type' => pm_text($jobRow['job_type'] ?? ''),
                  'status' => pm_text($jobRow['status'] ?? ''),
                ];
              }
            }
          }
        }
      }
      $jobStmt->close();
    }
  }

  if (!empty($planningIds)) {
    $hasPackingTable = false;
    $packingTableRes = $db->query("SHOW TABLES LIKE 'packing_operator_entries'");
    if ($packingTableRes instanceof mysqli_result) {
      $hasPackingTable = ($packingTableRes->num_rows > 0);
      $packingTableRes->close();
    }

    if ($hasPackingTable) {
      $in = implode(',', array_fill(0, count($planningIds), '?'));
      $types2 = str_repeat('i', count($planningIds));
      $packingSql = "SELECT
            COALESCE(NULLIF(pe.planning_id, 0), j.planning_id) AS planning_id,
            MAX(CASE WHEN pe.submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS has_submitted,
            COUNT(*) AS entry_count
          FROM packing_operator_entries pe
          LEFT JOIN jobs j ON j.id = pe.job_id
          WHERE COALESCE(NULLIF(pe.planning_id, 0), j.planning_id) IN ($in)
          GROUP BY COALESCE(NULLIF(pe.planning_id, 0), j.planning_id)";
      $packingStmt = $db->prepare($packingSql);
      if ($packingStmt) {
        $packingStmt->bind_param($types2, ...$planningIds);
        $packingStmt->execute();
        $packingRes = $packingStmt->get_result();
        if ($packingRes instanceof mysqli_result) {
          while ($packingRow = $packingRes->fetch_assoc()) {
            $pid = (int)($packingRow['planning_id'] ?? 0);
            if ($pid <= 0) {
              continue;
            }
            $hasSubmitted = (int)($packingRow['has_submitted'] ?? 0) === 1;
            $entryCount = (int)($packingRow['entry_count'] ?? 0);
            if ($entryCount <= 0) {
              continue;
            }
            $packingStatusByPlanning[$pid] = $hasSubmitted ? 'Packed' : 'Packing';
          }
        }
        $packingStmt->close();
      }
    }
  }
}

$filtered = [];
foreach ($rows as $row) {
  $planningId = (int)($row['id'] ?? 0);

  if ($planningId > 0 && isset($currentJobByPlanning[$planningId])) {
    $currentJob = $currentJobByPlanning[$planningId];
    $row['active_job_no'] = $currentJob['job_no'];
    $row['active_job_department'] = $currentJob['department'];
    $row['active_job_type'] = $currentJob['job_type'];
    $row['active_job_status'] = $currentJob['status'];
    $row['active_job_updated_at'] = $currentJob['updated_at'];
  }

  if ($planningId > 0 && isset($effectiveStatusByPlanning[$planningId]['status'])) {
    $row['effective_status'] = pm_text($effectiveStatusByPlanning[$planningId]['status']);
  }

  if (
    $planningId > 0 &&
    isset($packingStatusByPlanning[$planningId]) &&
    (pm_label_slitting_completed($row) || pm_should_apply_packing_fallback($row))
  ) {
    $row['effective_status'] = $packingStatusByPlanning[$planningId];
    $row['latest_job_department'] = 'packing';
  } elseif (
    pm_should_apply_packing_fallback($row) &&
    (pm_route_has_packing($row) || pm_barcode_completed_should_move_to_packing($row))
  ) {
    // Route-level fallback: upstream done + packaging in route => show as Packing stage.
    $row['effective_status'] = 'Packing';
    $row['latest_job_department'] = 'packing';
  }

  $effectiveNorm = strtolower(pm_text($row['effective_status'] ?? ''));
  if (
    ($effectiveNorm === 'packing' || $effectiveNorm === 'packed' || $effectiveNorm === 'packing done' || $effectiveNorm === 'finished production') &&
    pm_text($row['latest_job_department'] ?? '') === ''
  ) {
    $row['latest_job_department'] = 'packing';
  }

  $totalJobs = (int)($row['total_jobs'] ?? 0);
  $hasAnyJobCard =
    $totalJobs > 0 ||
    pm_text($row['active_job_no'] ?? '') !== '' ||
    pm_text($row['latest_job_no'] ?? '') !== '';

  $currentDept = pm_department_label(
    $row['active_job_department'] ?: $row['latest_job_department'] ?: $row['planning_department'],
    $row['active_job_type'] ?: $row['latest_job_type']
  );

  $planningNoUpper = strtoupper(pm_text($row['job_no'] ?? ''));
  if (strpos($planningNoUpper, 'PLN/') === 0) {
    $plnActionStage = pm_pln_first_actionable_stage_from_chain($row['chain_summary'] ?? '', $db, $planningId);
    if ($plnActionStage !== '') {
      $currentDept = $plnActionStage;
    }
  }

  $effectiveStatusNorm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($row['effective_status'] ?? ''))));
  if ($effectiveStatusNorm === 'finished production') {
    $currentDept = 'Finished Production';
  } elseif (in_array($effectiveStatusNorm, ['dispatched', 'dispatch'], true)) {
    $currentDept = 'Dispatch';
  }

  if (!$hasAnyJobCard) {
    $currentDept = 'Planning';
    if (pm_text($row['effective_status'] ?? '') === '') {
      $row['effective_status'] = pm_text($row['planning_status'] ?? '') !== '' ? pm_text($row['planning_status'] ?? '') : 'Planning';
    }
  }

  if ($planningId > 0 && isset($jobCardsByPlanning[$planningId])) {
    $stageCard = pm_pick_stage_job_card($jobCardsByPlanning[$planningId], $currentDept);
    if (!empty($stageCard)) {
      $row['active_job_no'] = pm_text($stageCard['job_no'] ?? '');
      $row['active_job_department'] = pm_text($stageCard['department'] ?? '');
      $row['active_job_type'] = pm_text($stageCard['job_type'] ?? '');
      $row['active_job_status'] = pm_text($stageCard['status'] ?? '');

      // Current actionable stage status should drive Production Status.
      $stageStatusNorm = strtolower(trim(str_replace(['-', '_'], ' ', pm_text($stageCard['status'] ?? ''))));
      if ($stageStatusNorm === 'running' || $stageStatusNorm === 'in progress' || $stageStatusNorm === 'inprogress') {
        $row['effective_status'] = 'Running';
      } elseif ($stageStatusNorm === 'finished production') {
        $row['effective_status'] = 'Finished Production';
      } elseif (in_array($stageStatusNorm, ['dispatched', 'dispatch'], true)) {
        $row['effective_status'] = 'Dispatched';
      } elseif (in_array($stageStatusNorm, ['packed', 'packing done', 'finished barcode'], true)) {
        $row['effective_status'] = 'Packed';
      } elseif (in_array($stageStatusNorm, ['pending', 'queued', 'preparing'], true)) {
        $row['effective_status'] = 'Pending';
      }
    }

    $cards = $jobCardsByPlanning[$planningId];
    $currentJobNo = pm_text($row['active_job_no'] ?? '');
    $latestJobNoRaw = pm_text($row['latest_job_no'] ?? '');

    $hasStarted = false;
    foreach ($cards as $card) {
      if (pm_is_started_status($card['status'] ?? '')) {
        $hasStarted = true;
        break;
      }
    }

    $displayJobNo = $currentJobNo !== '' ? $currentJobNo : ($latestJobNoRaw !== '' ? $latestJobNoRaw : pm_text($row['job_no'] ?? ''));
    $displayLatestJobNo = $latestJobNoRaw;
    $displayPrevActiveJobNo = '-';

    if (!$hasStarted) {
      // Fresh pending-only chain: no previous active card yet.
      $displayPrevActiveJobNo = '-';
    } else {
      $currentRank = pm_stage_rank_for_label($currentDept);
      $previousCard = null;
      foreach ($cards as $card) {
        $cardStatus = pm_text($card['status'] ?? '');
        $cardRank = pm_stage_rank_for_label($card['stage'] ?? '');
        if (!pm_is_started_status($cardStatus)) {
          continue;
        }
        if ($cardRank >= $currentRank) {
          continue;
        }
        // Pick the card with the HIGHEST stage rank (closest to current stage)
        if ($previousCard === null) {
          $previousCard = $card;
        } else {
          $prevRank = pm_stage_rank_for_label($previousCard['stage'] ?? '');
          if ($cardRank > $prevRank) {
            $previousCard = $card;
          } elseif ($cardRank === $prevRank && (int)($card['job_id'] ?? 0) > (int)($previousCard['job_id'] ?? 0)) {
            // If same rank, pick the higher job_id
            $previousCard = $card;
          }
        }
      }
      if (is_array($previousCard) && pm_text($previousCard['job_no'] ?? '') !== '') {
        $displayPrevActiveJobNo = pm_text($previousCard['job_no'] ?? '');
        // DEBUG: Log this to help troubleshoot
        error_log("DEBUG PM: displayPrevActiveJobNo set to {$displayPrevActiveJobNo} for planning {$planningId}");
      }

      // For PLN flow, show immediate downstream card by route order.
      // Pass 1: actionable downstream (Pending/Queued/Running).
      // Pass 2 fallback: any downstream card if actionable one is unavailable.
      if (strpos($planningNoUpper, 'PLN/') === 0) {
        $nextCard = null;
        foreach ($cards as $card) {
          $cardStatus = strtolower(pm_text($card['status'] ?? ''));
          $cardRank = pm_stage_rank_for_label($card['stage'] ?? '');
          if ($cardRank <= $currentRank) {
            continue;
          }
          if (!pm_is_pending_like_status($cardStatus) && $cardStatus !== 'running' && $cardStatus !== 'in progress' && $cardStatus !== 'inprogress') {
            continue;
          }
          if ($nextCard === null) {
            $nextCard = $card;
            continue;
          }

          $nextRank = pm_stage_rank_for_label($nextCard['stage'] ?? '');
          $cardId = (int)($card['job_id'] ?? 0);
          $nextId = (int)($nextCard['job_id'] ?? 0);
          if ($cardRank < $nextRank || ($cardRank === $nextRank && $cardId > 0 && ($nextId === 0 || $cardId < $nextId))) {
            $nextCard = $card;
          }
        }

        if (!is_array($nextCard)) {
          foreach ($cards as $card) {
            $cardRank = pm_stage_rank_for_label($card['stage'] ?? '');
            if ($cardRank <= $currentRank) {
              continue;
            }
            if ($nextCard === null) {
              $nextCard = $card;
              continue;
            }

            $nextRank = pm_stage_rank_for_label($nextCard['stage'] ?? '');
            $cardId = (int)($card['job_id'] ?? 0);
            $nextId = (int)($nextCard['job_id'] ?? 0);
            if ($cardRank < $nextRank || ($cardRank === $nextRank && $cardId > 0 && ($nextId === 0 || $cardId < $nextId))) {
              $nextCard = $card;
            }
          }
        }

        if (is_array($nextCard) && pm_text($nextCard['job_no'] ?? '') !== '') {
          $displayLatestJobNo = pm_text($nextCard['job_no'] ?? '');
        }
        // If no next card found, leave it empty (don't default to current job)
      }
    }

    // Only set displayLatestJobNo if it wasn't already set by next card logic
    if ($displayLatestJobNo === '') {
      $displayLatestJobNo = '-';  // Default to empty display, not the current job
    }

    $row['display_job_no'] = $displayJobNo;
    $row['display_previous_active_job_no'] = $displayPrevActiveJobNo;
    
      $row['display_latest_job_no'] = $displayLatestJobNo !== '' ? $displayLatestJobNo : '-';
  }

  $row['display_department_route'] = pm_route_from_plan_context(
    $row['job_no'] ?? '',
    $row['planning_type'] ?? '',
    $row['planning_department'] ?? '',
    $row['department_route'] ?? ''
  );

    $bucket = pm_bucket_status($row);

    if ($statusFilter !== '') {
      if (strcasecmp($statusFilter, 'Completed') === 0) {
        if (!in_array($bucket, ['Completed', 'Packed'], true)) {
          continue;
        }
      } elseif (strcasecmp($bucket, $statusFilter) !== 0) {
        continue;
      }
    }
    if ($stageFilter !== '' && stripos($currentDept, $stageFilter) === false) {
        continue;
    }

    $row['current_department_label'] = $currentDept;
    $row['bucket_status'] = $bucket;
    $filtered[] = $row;
}

$total = count($filtered);
$running = 0;
$pending = 0;
$completed = 0;
$hold = 0;

foreach ($filtered as $row) {
    $bucket = $row['bucket_status'];
    if ($bucket === 'Running') $running++;
    elseif ($bucket === 'Pending') $pending++;
  elseif ($bucket === 'Completed' || $bucket === 'Packed') $completed++;
    elseif ($bucket === 'On Hold') $hold++;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Production Summary</span>
</div>

<style>
:root{
  --pm-ink:#1e293b;
  --pm-slate:#64748b;
  --pm-border:#e2e8f0;
  --pm-surface:#f8fafc;
  --pm-brand:#6366f1;
  --pm-accent:#f59e0b;
}
body{background:#f1f5f9}
.pm-wrap{
  display:flex;
  flex-direction:column;
  gap:16px;
  position:relative;
}
.pm-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
  background:linear-gradient(120deg,#4f46e5 0%,#7c3aed 50%,#a21caf 100%);
  padding:22px 26px;
  border-radius:18px;
  color:#fff;
  box-shadow:0 12px 32px rgba(99,102,241,.28);
}
.pm-head h1{margin:0;font-size:1.45rem;font-weight:900;letter-spacing:.01em}
.pm-head p{margin:6px 0 0;opacity:.88;font-size:.83rem;max-width:680px}
/* ── Stat Cards ── */
.pm-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px}
.pm-stat{
  border-radius:14px;
  padding:14px 16px;
  border:1.5px solid transparent;
  box-shadow:0 4px 16px rgba(0,0,0,.07);
}
.pm-stat-total{background:linear-gradient(135deg,#ede9fe,#ddd6fe);border-color:#c4b5fd}
.pm-stat-total .k{color:#5b21b6}
.pm-stat-total .v{color:#4c1d95}
.pm-stat-running{background:linear-gradient(135deg,#dbeafe,#bfdbfe);border-color:#93c5fd}
.pm-stat-running .k{color:#1d4ed8}
.pm-stat-running .v{color:#1e3a8a}
.pm-stat-pending{background:linear-gradient(135deg,#fef9c3,#fde68a);border-color:#fcd34d}
.pm-stat-pending .k{color:#92400e}
.pm-stat-pending .v{color:#78350f}
.pm-stat-hold{background:linear-gradient(135deg,#fee2e2,#fecaca);border-color:#f87171}
.pm-stat-hold .k{color:#991b1b}
.pm-stat-hold .v{color:#7f1d1d}
.pm-stat-done{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-color:#6ee7b7}
.pm-stat-done .k{color:#065f46}
.pm-stat-done .v{color:#064e3b}
.pm-stat .k{display:block;font-size:.67rem;text-transform:uppercase;letter-spacing:.08em;font-weight:800}
.pm-stat .v{display:block;font-size:1.5rem;font-weight:900;line-height:1.15}
/* ── Filters ── */
.pm-filters{
  display:grid;
  grid-template-columns:2fr 1fr 1fr auto;
  gap:10px;
  background:#fff;
  border:1.5px solid var(--pm-border);
  padding:12px;
  border-radius:14px;
  box-shadow:0 2px 8px rgba(0,0,0,.05);
}
.pm-filters input,.pm-filters select{
  width:100%;
  border:1.5px solid #e2e8f0;
  background:#f8fafc;
  border-radius:10px;
  padding:9px 11px;
  font-size:.86rem;
  color:var(--pm-ink);
}
.pm-filters input:focus,.pm-filters select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.18)}
.pm-filters button{
  border:0;
  border-radius:10px;
  background:linear-gradient(120deg,#6366f1,#7c3aed);
  color:#fff;
  font-weight:800;
  padding:9px 16px;
  cursor:pointer;
  font-size:.86rem;
}
.pm-filters button:hover{background:linear-gradient(120deg,#4f46e5,#6d28d9)}
/* ── Table ── */
.pm-table{
  background:#fff;
  border:1.5px solid var(--pm-border);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 6px 24px rgba(0,0,0,.07);
}
.pm-table-wrap{overflow:auto;max-height:68vh}
.pm-table table{width:100%;border-collapse:separate;border-spacing:0;min-width:1320px}
.pm-table th,.pm-table td{padding:9px 11px;border-bottom:1px solid #f1f5f9;font-size:.8rem;vertical-align:top}
.pm-table th{
  background:linear-gradient(180deg,#f8faff 0%,#f1f5f9 100%);
  text-transform:uppercase;
  font-size:.63rem;
  letter-spacing:.09em;
  color:#475569;
  font-weight:800;
  text-align:left;
  position:sticky;
  top:0;
  z-index:1;
  border-bottom:2px solid #e2e8f0;
}
/* Per-row pastel bands */
.pm-table tbody tr.rc0 td{background:#f0f4ff}
.pm-table tbody tr.rc1 td{background:#f0fdf4}
.pm-table tbody tr.rc2 td{background:#fdf4ff}
.pm-table tbody tr.rc3 td{background:#fffbeb}
.pm-table tbody tr.rc4 td{background:#fff1f2}
.pm-table tbody tr.rc5 td{background:#f0fdfa}
.pm-table tbody tr.rc0:hover td{background:#dde8ff}
.pm-table tbody tr.rc1:hover td{background:#dcfce7}
.pm-table tbody tr.rc2:hover td{background:#f3e8ff}
.pm-table tbody tr.rc3:hover td{background:#fef3c7}
.pm-table tbody tr.rc4:hover td{background:#ffe4e6}
.pm-table tbody tr.rc5:hover td{background:#ccfbf1}
/* Badges */
.pm-badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:.65rem;font-weight:800;letter-spacing:.03em;border:1.5px solid transparent}
.pm-badge.warning{background:#fef3c7;color:#92400e;border-color:#fcd34d}
.pm-badge.info{background:#e0f2fe;color:#0c4a6e;border-color:#7dd3fc}
.pm-badge.success{background:#dcfce7;color:#166534;border-color:#86efac}
.pm-badge.danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.pm-badge.neutral{background:#e2e8f0;color:#334155;border-color:#cbd5e1}
/* Planning status badge */
.pm-ps-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:.62rem;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.pm-ps-badge.ps-open{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.pm-ps-badge.ps-closed{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
.pm-ps-badge.ps-dispatch{background:#fdf4ff;color:#7e22ce;border-color:#e9d5ff}
.pm-muted{color:#94a3b8;font-size:.73rem;margin-top:2px}
.pm-chain{max-width:340px;white-space:normal;line-height:1.45;color:#334155;font-size:.75rem}
.pm-link{
  display:inline-block;
  padding:5px 10px;
  border-radius:8px;
  background:linear-gradient(120deg,#e0e7ff,#ede9fe);
  color:#3730a3;
  text-decoration:none;
  font-size:.71rem;
  font-weight:800;
  border:1px solid #c7d2fe;
}
.pm-link:hover{background:linear-gradient(120deg,#c7d2fe,#ddd6fe);color:#312e81}
@media (max-width:980px){
  .pm-head{flex-direction:column;align-items:flex-start}
  .pm-stats{grid-template-columns:repeat(2,minmax(120px,1fr))}
  .pm-filters{grid-template-columns:1fr}
}
</style>

<div class="pm-wrap">
  <section class="pm-head">
    <div>
      <h1><i class="bi bi-kanban"></i> Production Summary Console</h1>
      <p>All production visibility in one view: planning, job cards, current stage, progress, and full journey snapshot.</p>
    </div>
    <a class="pm-link" href="<?= BASE_URL ?>/modules/live/index.php">Live Floor View</a>
  </section>

  <section class="pm-stats">
    <div class="pm-stat pm-stat-total"><span class="k">📊 Total Plans</span><span class="v"><?= (int)$total ?></span></div>
    <div class="pm-stat pm-stat-running"><span class="k">🔵 Running</span><span class="v"><?= (int)$running ?></span></div>
    <div class="pm-stat pm-stat-pending"><span class="k">🟡 Pending</span><span class="v"><?= (int)$pending ?></span></div>
    <div class="pm-stat pm-stat-hold"><span class="k">🔴 On Hold</span><span class="v"><?= (int)$hold ?></span></div>
    <div class="pm-stat pm-stat-done"><span class="k">✅ Completed</span><span class="v"><?= (int)$completed ?></span></div>
  </section>

  <form class="pm-filters" method="get">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by planning/job no, job name, notes">
    <select name="status">
      <option value="">All Status</option>
      <?php foreach (['Pending','Running','On Hold','Packed','Completed'] as $opt): ?>
        <option value="<?= e($opt) ?>" <?= strcasecmp($statusFilter, $opt) === 0 ? 'selected' : '' ?>><?= e($opt === 'Packed' ? 'Ready to Dispatch' : $opt) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="stage" value="<?= e($stageFilter) ?>" placeholder="Filter stage (e.g. Barcode)">
    <button type="submit"><i class="bi bi-funnel"></i> Filter</button>
  </form>

  <section class="pm-table">
    <div class="pm-table-wrap">
      <table>
        <thead>
          <tr>
            <th>Serial No</th>
            <th>Planning No</th>
            <th>Job No</th>
            <th>Job Name</th>
            <th>Priority</th>
            <th>Production Status</th>
            <th>Current Stage</th>
            <th>Previous Active Card</th>
            <th>Next Job Card</th>
            <th>Department Route</th>
            <th>Chain Snapshot</th>
            <th>Last Update</th>
            <th>Details</th>
            <th></th>
          </tr>
        <tbody>
        <?php if (empty($filtered)): ?>
          <tr><td colspan="15" class="pm-muted">No data found for selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($filtered as $idx => $row): ?>
            <?php
              $rowColorClass = 'rc' . ($idx % 6);
              $bucket = (string)$row['bucket_status'];
              $productionStatusRaw = pm_text($row['effective_status'] ?? '');
              if ($productionStatusRaw === '') {
                $productionStatusRaw = $bucket;
              }
              $productionStatusText = pm_display_status($productionStatusRaw);
              $bucketClass = erp_status_visual_tone($productionStatusText);

              $activeJobNo = pm_text($row['active_job_no']);
              $latestJobNo = pm_text($row['latest_job_no']);
              $planNo = pm_text($row['job_no']);
              $viewJobNo = pm_text($row['display_job_no'] ?? '');
              if ($viewJobNo === '') {
                $viewJobNo = $activeJobNo !== '' ? $activeJobNo : ($latestJobNo !== '' ? $latestJobNo : $planNo);
              }
              $prevActiveJobNo = pm_text($row['display_previous_active_job_no'] ?? '-');
              if ($prevActiveJobNo === '') $prevActiveJobNo = '-';
              $latestJobNoDisplay = pm_text($row['display_latest_job_no'] ?? '');
              if ($latestJobNoDisplay === '') {
                $latestJobNoDisplay = $latestJobNo !== '' ? $latestJobNo : '-';
              }
              $planningNo = $planNo !== '' ? $planNo : ('PLAN-' . (int)$row['id']);
              $serialNo = (int)$idx + 1;

              // Current position should emphasize stage (department) over generic lifecycle statuses.
              $curPos = pm_text($row['current_department_label'] ?? '');
              if ($curPos === '' || strtolower($curPos) === 'not started') $curPos = pm_text($row['effective_status'] ?? '');
              if ($curPos === '' || strtolower($curPos) === 'not started') $curPos = pm_text($row['planning_status']);
              if ($curPos === '') $curPos = pm_text($row['latest_job_status']);
              if ($curPos === '') $curPos = pm_text($row['active_job_status']);
              if ($curPos === '') $curPos = pm_text($row['board_status']);
              $curPos = pm_display_status($curPos);

              $route = pm_text($row['display_department_route'] ?? '');
              if ($route === '') $route = pm_text($row['planning_department']);

              $lastAt = pm_text($row['latest_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['active_job_updated_at']);
              if ($lastAt === '') $lastAt = pm_text($row['last_job_at']);
              if ($lastAt === '') $lastAt = pm_text($row['planning_updated_at']);
            ?>
            <tr class="<?= $rowColorClass ?>">
              <td><strong><?= (int)$serialNo ?></strong></td>
              <td><strong><?= e($planningNo) ?></strong><div class="pm-muted">ID: <?= (int)$row['id'] ?></div></td>
              <td><strong><?= e($viewJobNo !== '' ? $viewJobNo : '-') ?></strong></td>
              <td><?= e(pm_text($row['job_name']) !== '' ? $row['job_name'] : '-') ?></td>
              <td><?= e(pm_text($row['priority']) !== '' ? $row['priority'] : 'Normal') ?></td>
              <td>
                <span class="pm-badge <?= e($bucketClass) ?>"><?= e($productionStatusText) ?></span>
                <div class="pm-muted">Stage: <?= e(pm_text($row['current_department_label']) !== '' ? $row['current_department_label'] : '-') ?></div>
              </td>
              <td><strong><?= e($row['current_department_label']) ?></strong></td>
              <td><?= e(isset($row['_debug_prev_calc']) ? ($row['_debug_prev_calc'] . ' [cards:' . $row['_debug_cards'] . ']') : $prevActiveJobNo) ?></td>
              <td><?= e($latestJobNoDisplay) ?></td>
              <td><?= e($route !== '' ? $route : '-') ?></td>
              <td class="pm-chain"><?= e(pm_text($row['chain_summary']) !== '' ? $row['chain_summary'] : '-') ?></td>
              <td><?= e($lastAt !== '' ? $lastAt : '-') ?></td>
              <td>
                <?php if ($viewJobNo !== ''): ?>
                  <a class="pm-link" href="<?= BASE_URL ?>/modules/scan/dossier.php?jn=<?= urlencode($viewJobNo) ?>" target="_blank" rel="noopener">Full Details</a>
                <?php else: ?>
                  <span class="pm-muted">No link</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center">
                <button type="button" class="pm-del-btn" data-id="<?= (int)$row['id'] ?>" data-label="<?= e($planningNo) ?>" title="Delete planning row"><i class="bi bi-trash"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      
      <!-- DEBUG: Show full debug info for planning_id=1 -->
      <?php foreach ($filtered as $row): ?>
        <?php if ((int)$row['id'] === 1 && isset($row['_debug_prev_calc'])): ?>
          <div style="margin:20px; padding:15px; background:#fee2e2; border: 1px solid #fecaca; border-radius:8px; font-family:monospace; font-size:12px; white-space:pre-wrap;">
            <strong>DEBUG INFO:</strong>
            <?= e($row['_debug_prev_calc']) ?>
            Cards: <?= e($row['_debug_cards']) ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<!-- Delete confirm modal -->
<div id="pm-del-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 28px 20px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 8px;font-size:1rem;color:#0f172a"><i class="bi bi-exclamation-triangle-fill" style="color:#ef4444"></i> Delete Planning Row?</h3>
    <p style="margin:0 0 18px;font-size:.86rem;color:#475569">This will soft-delete <strong id="pm-del-label"></strong>. Job cards linked to it will remain intact.</p>
    <form method="POST" id="pm-del-form">
      <input type="hidden" name="action" value="delete_planning">
      <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
      <input type="hidden" name="planning_id" id="pm-del-id" value="">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
      <input type="hidden" name="stage" value="<?= e($stageFilter) ?>">
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" id="pm-del-cancel" class="btn btn-light">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>

<style>
.pm-del-btn{
  border:none;background:none;cursor:pointer;
  color:#94a3b8;padding:4px 6px;border-radius:7px;
  font-size:.9rem;transition:color .15s,background .15s;
}
.pm-del-btn:hover{color:#ef4444;background:#fee2e2}
</style>

<script>
(function(){
  var modal  = document.getElementById('pm-del-modal');
  var delId  = document.getElementById('pm-del-id');
  var delLbl = document.getElementById('pm-del-label');
  document.querySelectorAll('.pm-del-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      delId.value  = btn.dataset.id;
      delLbl.textContent = btn.dataset.label;
      modal.style.display = 'flex';
    });
  });
  document.getElementById('pm-del-cancel').addEventListener('click', function(){
    modal.style.display = 'none';
  });
  modal.addEventListener('click', function(e){ if(e.target===modal) modal.style.display='none'; });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
