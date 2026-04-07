<?php
// ============================================================
// ERP System — Planning: Board (Concept port from Firebase)
// Keeps existing UI theme/colors. Planning module only.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../plate-data/_common.php';

$db = getDB();

// Ensure department support for planning rows.
try { $db->query("ALTER TABLE planning ADD COLUMN department VARCHAR(80) NOT NULL DEFAULT 'general' AFTER notes"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE planning ADD COLUMN extra_data LONGTEXT NULL AFTER department"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE planning ADD COLUMN job_no VARCHAR(60) NULL AFTER id"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE planning ADD COLUMN sequence_order INT NOT NULL DEFAULT 0 AFTER extra_data"); } catch (Exception $e) {}

// Config table for planning board columns (rename/type/reorder).
@$db->query("CREATE TABLE IF NOT EXISTS planning_board_columns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department VARCHAR(80) NOT NULL,
  col_key VARCHAR(80) NOT NULL,
  col_label VARCHAR(120) NOT NULL,
  col_type VARCHAR(20) NOT NULL DEFAULT 'Text',
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_dept_col (department, col_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$csrfToken = generateCSRF();

$department = trim((string)($_GET['department'] ?? 'label-printing'));
if ($department === '') $department = 'label-printing';

$defaultColumns = [
    ['key' => 'sn', 'label' => 'S.N', 'type' => 'Number', 'sort' => 1],
  ['key' => 'printing_planning', 'label' => 'Status', 'type' => 'Status', 'sort' => 2],
  ['key' => 'name', 'label' => 'Job Name', 'type' => 'Text', 'sort' => 3],
  ['key' => 'priority', 'label' => 'Priority', 'type' => 'Priority', 'sort' => 4],
  ['key' => 'order_date', 'label' => 'Order Date', 'type' => 'Date', 'sort' => 5],
  ['key' => 'dispatch_date', 'label' => 'Dispatch Date', 'type' => 'Date', 'sort' => 6],
  ['key' => 'plate_no', 'label' => 'Plate No', 'type' => 'Text', 'sort' => 7],
  ['key' => 'size', 'label' => 'Size', 'type' => 'Text', 'sort' => 8],
  ['key' => 'repeat', 'label' => 'Repeat', 'type' => 'Text', 'sort' => 9],
  ['key' => 'material', 'label' => 'Material', 'type' => 'Text', 'sort' => 10],
  ['key' => 'paper_size', 'label' => 'Paper Size', 'type' => 'Text', 'sort' => 11],
  ['key' => 'department_route', 'label' => 'Department', 'type' => 'Department', 'sort' => 12],
  ['key' => 'die', 'label' => 'Die', 'type' => 'Text', 'sort' => 13],
  ['key' => 'allocate_mtrs', 'label' => 'MTRS', 'type' => 'Number', 'sort' => 14],
  ['key' => 'qty_pcs', 'label' => 'QTY', 'type' => 'Number', 'sort' => 15],
  ['key' => 'core_size', 'label' => 'Core', 'type' => 'Text', 'sort' => 16],
  ['key' => 'qty_per_roll', 'label' => 'Qty/Roll', 'type' => 'Text', 'sort' => 17],
  ['key' => 'roll_direction', 'label' => 'Direction', 'type' => 'Text', 'sort' => 18],
  ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'Text', 'sort' => 19],
];

$allowedTypes = ['Text', 'Number', 'Date', 'Status', 'Priority', 'Department'];
$statusList = erp_status_page_options('planning.label-printing');
$customDisplayStatuses = ['Hold For Plate', 'Hold For Payment'];
foreach ($customDisplayStatuses as $customStatus) {
  if (!in_array($customStatus, $statusList, true)) {
    $statusList[] = $customStatus;
  }
}
$defaultStatus = erp_status_page_default('planning.label-printing');
$priorityList = ['Low', 'Normal', 'High', 'Urgent'];

function planning_route_department_choices() {
  return erp_get_machine_departments(getDB());
}

function planning_die_options() {
  return ['FlatBed', 'Rotary', 'Sheeting', 'FlatBed With Sheeting', 'Rotary with Sheeting'];
}

function planning_normalize_die_value($value) {
  $text = trim((string)$value);
  if ($text === '') return '';

  $norm = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $text));
  $norm = trim(preg_replace('/\s+/', ' ', $norm));

  if ($norm === 'flatbed' || $norm === 'flat bed') return 'FlatBed';
  if ($norm === 'rotary' || $norm === 'rotery') return 'Rotary';
  if ($norm === 'sheeting') return 'Sheeting';
  if ($norm === 'flatbed with sheeting' || $norm === 'flat bed with sheeting') return 'FlatBed With Sheeting';
  if ($norm === 'rotary with sheeting' || $norm === 'rotery with sheeting') return 'Rotary with Sheeting';

  foreach (planning_die_options() as $option) {
    if (strcasecmp($text, $option) === 0) return $option;
  }

  return $text;
}

function planning_route_default_department_list($dieValue = '') {
  $dieNorm = strtolower(trim((string)planning_normalize_die_value($dieValue)));
  $defaults = ['Jumbo Slitting', 'Printing', 'Die-Cutting', 'Label Slitting', 'Packaging', 'Dispatch'];
  if ($dieNorm !== '' && (strpos($dieNorm, 'rotary') !== false || strpos($dieNorm, 'rotery') !== false)) {
    $defaults = array_values(array_filter($defaults, function($item) {
      return strcasecmp((string)$item, 'Die-Cutting') !== 0;
    }));
  }
  return $defaults;
}

function planning_apply_die_route_rules($value, $dieValue = '') {
  $normalized = erp_normalize_department_selection(
    $value,
    planning_route_department_choices(),
    planning_route_default_department_list($dieValue)
  );

  $dieNorm = strtolower(trim((string)planning_normalize_die_value($dieValue)));
  if ($dieNorm !== '' && (strpos($dieNorm, 'rotary') !== false || strpos($dieNorm, 'rotery') !== false)) {
    $selected = erp_department_selection_list($normalized, planning_route_department_choices());
    $selected = array_values(array_filter($selected, function($item) {
      return strcasecmp((string)$item, 'Die-Cutting') !== 0;
    }));
    return implode(', ', $selected);
  }

  return $normalized;
}

function planning_default_route_departments($dieValue = '') {
  return erp_normalize_department_selection('', planning_route_department_choices(), planning_route_default_department_list($dieValue));
}

function planning_department_options() {
  return [
    'label-printing',
    'jumbo-slitting',
    'printing',
    'die-cutting',
    'barcode',
    'label-slitting',
    'batch-printing',
    'packaging',
    'dispatch',
  ];
}

function planning_department_label($department) {
  $department = trim((string)$department);
  $labels = [
    'label-printing' => 'Label Printing',
    'jumbo-slitting' => 'Jumbo Slitting',
    'printing' => 'Printing',
    'die-cutting' => 'Die-Cutting',
    'barcode' => 'Barcode',
    'label-slitting' => 'Label Slitting',
    'batch-printing' => 'Batch Printing',
    'packaging' => 'Packaging',
    'dispatch' => 'Dispatch',
  ];
  if ($department === '') return 'Label Printing';
  if (isset($labels[$department])) return $labels[$department];
  return ucwords(str_replace('-', ' ', $department));
}

function planning_normalize_route_departments($value) {
  return planning_normalize_route_departments_by_die($value, '');
}

function planning_normalize_route_departments_by_die($value, $dieValue = '') {
  return planning_apply_die_route_rules($value, $dieValue);
}

function planning_normalize_lookup_key($value) {
  return strtolower(preg_replace('/[^a-z0-9]+/', '', trim((string)$value)));
}

function planning_value_is_blankish($value) {
  $text = trim((string)$value);
  return $text === '' || $text === '-' || $text === '—' || strtoupper($text) === 'NA';
}

function planning_material_suggestions(mysqli $db) {
  static $cache = null;
  if ($cache !== null) return $cache;

  $cache = [];
  $res = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE paper_type IS NOT NULL AND TRIM(paper_type) <> '' ORDER BY paper_type");
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      $text = trim((string)($row['paper_type'] ?? ''));
      if ($text !== '') $cache[] = $text;
    }
    $res->close();
  }

  return $cache;
}

function planning_match_material_suggestion($value, array $options) {
  $text = trim((string)$value);
  if ($text === '') return '';
  foreach ($options as $option) {
    if (strcasecmp((string)$option, $text) === 0) return (string)$option;
  }
  return $text;
}

function planning_plate_records(mysqli $db) {
  static $cache = null;
  if ($cache !== null) return $cache;

  $cache = [];
  try {
    ensureDieToolingSchema($db);
    $table = dieToolingTableName();
    $res = $db->query("SELECT plate, name, image_path, size, paper_size, paper_type, die, repeat_value, core, qty_roll, ups, rewinding, date_received FROM `{$table}` WHERE TRIM(COALESCE(plate, '')) <> '' ORDER BY id DESC");
    if ($res instanceof mysqli_result) {
      $cache = $res->fetch_all(MYSQLI_ASSOC);
      $res->close();
    }
  } catch (Exception $e) {
    $cache = [];
  }

  return $cache;
}

function planning_plate_autofill_payload(array $plateRow, array $materialOptions = []) {
  $imagePath = trim((string)($plateRow['image_path'] ?? ''));
  $imageName = $imagePath !== '' ? basename(str_replace('\\', '/', $imagePath)) : '';
  $material = planning_match_material_suggestion($plateRow['paper_type'] ?? '', $materialOptions);

  return [
    'plate_no' => trim((string)($plateRow['plate'] ?? '')),
    'name' => trim((string)($plateRow['name'] ?? '')),
    'size' => trim((string)($plateRow['size'] ?? '')),
    'repeat' => trim((string)($plateRow['repeat_value'] ?? '')),
    'paper_size' => trim((string)($plateRow['paper_size'] ?? '')),
    'material' => $material,
    'die' => planning_normalize_die_value($plateRow['die'] ?? ''),
    'core_size' => trim((string)($plateRow['core'] ?? '')),
    'qty_per_roll' => trim((string)($plateRow['qty_roll'] ?? '')),
    'ups' => trim((string)($plateRow['ups'] ?? '')),
    'roll_direction' => trim((string)($plateRow['rewinding'] ?? '')),
    'image_path' => $imagePath,
    'image_name' => $imageName,
    'image_uploaded_at' => trim((string)($plateRow['date_received'] ?? '')),
    'image_source' => $imagePath !== '' ? 'plate' : '',
    'image_url' => $imagePath !== '' ? appUrl($imagePath) : '',
  ];
}

function planning_find_plate_autofill_data(mysqli $db, $plateNo) {
  $lookup = planning_normalize_lookup_key($plateNo);
  if ($lookup === '') return [];

  $materialOptions = planning_material_suggestions($db);
  foreach (planning_plate_records($db) as $row) {
    if (planning_normalize_lookup_key($row['plate'] ?? '') === $lookup) {
      return planning_plate_autofill_payload($row, $materialOptions);
    }
  }

  return [];
}

function planning_apply_plate_defaults(array $rowValues, array $plateData) {
  if (empty($plateData)) return $rowValues;

  $fieldMap = ['name', 'size', 'repeat', 'paper_size', 'material', 'die', 'core_size', 'qty_per_roll', 'ups', 'roll_direction'];
  foreach ($fieldMap as $field) {
    if (planning_value_is_blankish($rowValues[$field] ?? '') && !planning_value_is_blankish($plateData[$field] ?? '')) {
      $rowValues[$field] = (string)$plateData[$field];
    }
  }

  foreach (['image_path', 'image_name', 'image_uploaded_at', 'image_source'] as $imageField) {
    if (planning_value_is_blankish($rowValues[$imageField] ?? '') && !planning_value_is_blankish($plateData[$imageField] ?? '')) {
      $rowValues[$imageField] = (string)$plateData[$imageField];
    }
  }

  return $rowValues;
}

function planning_json_response($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function planning_seed_columns(mysqli $db, $department, array $defaultColumns) {
    $chk = $db->prepare("SELECT COUNT(*) AS c FROM planning_board_columns WHERE department = ?");
    $chk->bind_param('s', $department);
    $chk->execute();
    $count = (int)$chk->get_result()->fetch_assoc()['c'];
    if ($count > 0) return;

    $ins = $db->prepare("INSERT INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES (?,?,?,?,?)");
    foreach ($defaultColumns as $c) {
        $ins->bind_param('ssssi', $department, $c['key'], $c['label'], $c['type'], $c['sort']);
        $ins->execute();
    }
}

function planning_get_columns(mysqli $db, $department, array $defaultColumns) {
    planning_seed_columns($db, $department, $defaultColumns);
    $stmt = $db->prepare("SELECT col_key, col_label, col_type, sort_order FROM planning_board_columns WHERE department = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param('s', $department);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($rows)) {
        return $defaultColumns;
    }

  // One-time migration for legacy board config to Excel-style schema.
  $keys = array_map(function($r){ return (string)$r['col_key']; }, $rows);
  if ($department === 'label-printing' && !in_array('printing_planning', $keys, true)) {
    $del = $db->prepare("DELETE FROM planning_board_columns WHERE department = ?");
    $del->bind_param('s', $department);
    $del->execute();
    planning_seed_columns($db, $department, $defaultColumns);
    return $defaultColumns;
  }

  // Keep main planning board default opening order predictable.
  // Fixed columns render S.N and Job No before configurable board columns.
  if ($department === 'label-printing') {
    $targetFirst = ['sn', 'printing_planning', 'name', 'priority'];
    $currentFirst = array_slice($keys, 0, count($targetFirst));
    $needsReset = false;
    if (in_array('job_no', $keys, true)) {
      $needsReset = true;
    } elseif ($currentFirst !== $targetFirst) {
      $needsReset = true;
    }
    if ($needsReset) {
      $del = $db->prepare("DELETE FROM planning_board_columns WHERE department = ?");
      $del->bind_param('s', $department);
      $del->execute();
      planning_seed_columns($db, $department, $defaultColumns);
      return $defaultColumns;
    }
  }

  // Ensure Priority column exists for every department
  if (!in_array('priority', $keys, true)) {
    $maxSort = 0;
    foreach ($rows as $_r) { $maxSort = max($maxSort, (int)$_r['sort_order']); }
    $priKey = 'priority'; $priLabel = 'Priority'; $priType = 'Priority'; $priSort = $maxSort + 1;
    $pIns = $db->prepare("INSERT IGNORE INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES (?,?,?,?,?)");
    $pIns->bind_param('ssssi', $department, $priKey, $priLabel, $priType, $priSort);
    $pIns->execute();
    $stmt2 = $db->prepare("SELECT col_key, col_label, col_type, sort_order FROM planning_board_columns WHERE department = ? ORDER BY sort_order ASC, id ASC");
    $stmt2->bind_param('s', $department);
    $stmt2->execute();
    $rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $keys = array_map(function($r){ return (string)$r['col_key']; }, $rows);
  }

  if ($department === 'label-printing' && !in_array('department_route', $keys, true)) {
    $dieSort = null;
    $maxSort = 0;
    foreach ($rows as $_r) {
      $maxSort = max($maxSort, (int)$_r['sort_order']);
      if ((string)$_r['col_key'] === 'die') {
        $dieSort = (int)$_r['sort_order'];
      }
    }
    $insertSort = $dieSort ?: ($maxSort + 1);
    $shift = $db->prepare("UPDATE planning_board_columns SET sort_order = sort_order + 1 WHERE department = ? AND sort_order >= ?");
    $shift->bind_param('si', $department, $insertSort);
    $shift->execute();

    $insDept = $db->prepare("INSERT IGNORE INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES (?,?,?,?,?)");
    $deptKey = 'department_route';
    $deptLabel = 'Department';
    $deptType = 'Department';
    $insDept->bind_param('ssssi', $department, $deptKey, $deptLabel, $deptType, $insertSort);
    $insDept->execute();

    $stmt2 = $db->prepare("SELECT col_key, col_label, col_type, sort_order FROM planning_board_columns WHERE department = ? ORDER BY sort_order ASC, id ASC");
    $stmt2->bind_param('s', $department);
    $stmt2->execute();
    $rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  // Auto-heal board layout so all default columns remain visible even if
  // older/saved configs accidentally removed some keys.
  $keys = array_map(function($r){ return (string)$r['col_key']; }, $rows);
  $maxSort = 0;
  foreach ($rows as $_r) {
    $maxSort = max($maxSort, (int)$_r['sort_order']);
  }

  $missingDefaults = [];
  foreach ($defaultColumns as $dc) {
    $dk = (string)($dc['key'] ?? '');
    if ($dk !== '' && !in_array($dk, $keys, true)) {
      $missingDefaults[] = $dc;
    }
  }

  if (!empty($missingDefaults)) {
    $insMissing = $db->prepare("INSERT IGNORE INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES (?,?,?,?,?)");
    foreach ($missingDefaults as $dc) {
      $colKey = trim((string)($dc['key'] ?? ''));
      $colLabel = trim((string)($dc['label'] ?? $colKey));
      $colType = trim((string)($dc['type'] ?? 'Text'));
      if ($colKey === '' || $colLabel === '') {
        continue;
      }
      $sort = (int)($dc['sort'] ?? 0);
      if ($sort <= 0 || $sort <= $maxSort) {
        $sort = $maxSort + 1;
      }
      $insMissing->bind_param('ssssi', $department, $colKey, $colLabel, $colType, $sort);
      $insMissing->execute();
      $maxSort = max($maxSort, $sort);
    }

    $stmt3 = $db->prepare("SELECT col_key, col_label, col_type, sort_order FROM planning_board_columns WHERE department = ? ORDER BY sort_order ASC, id ASC");
    $stmt3->bind_param('s', $department);
    $stmt3->execute();
    $rows = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
  }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'key' => $r['col_key'],
            'label' => $r['col_label'],
            'type' => $r['col_type'],
            'sort' => (int)$r['sort_order']
        ];
    }
    return $out;
}

function planning_get_rows(mysqli $db, $department) {
    $stmt = $db->prepare("SELECT p.*, so.order_no, so.client_name
        FROM planning p
        LEFT JOIN sales_orders so ON so.id = p.sales_order_id
        WHERE p.department = ?
          AND LOWER(TRIM(COALESCE(p.status, ''))) <> 'finished'
        ORDER BY
          CASE WHEN p.sequence_order > 0 THEN 0 ELSE 1 END ASC,
          p.sequence_order ASC,
          CASE WHEN p.job_no IS NULL OR p.job_no = '' THEN 1 ELSE 0 END ASC,
          CAST(SUBSTRING_INDEX(p.job_no, '/', -1) AS UNSIGNED) ASC,
          p.id ASC");
    $stmt->bind_param('s', $department);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function planning_normalize_status($status) {
  return erp_status_page_normalize($status, 'planning.label-printing');
}

function planning_status_badge($status) {
    $s = trim((string)$status);
  if ($s === '') $s = erp_status_page_default('planning.label-printing');
    $class = erp_status_badge_class(planning_normalize_status($s));
    return '<span class="badge badge-' . $class . '">' . e($s) . '</span>';
}

  function planning_status_pill_class($status) {
    $badgeClass = erp_status_badge_class(planning_normalize_status($status));
    if (in_array($badgeClass, ['completed', 'dispatched', 'approved'], true)) return 'completed';
    if (in_array($badgeClass, ['on-hold', 'rejected', 'cancelled'], true)) return 'hold';
    if ($badgeClass === 'slitting') return 'slitting';
    if (in_array($badgeClass, ['in-progress', 'in-production'], true)) return 'running';
    return 'pending';
  }

  function planning_status_inline_style($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'hold for plate' || $s === 'hold for payment') {
      return 'background:#fee2e2;color:#991b1b;border-color:#fecaca;';
    }
    return erp_status_inline_style(planning_normalize_status($status));
  }

  function planning_status_style_map_data() {
    $labels = erp_status_page_options('planning.label-printing');
    $map = [];
    foreach ($labels as $label) {
      $map[strtolower(trim((string)$label))] = planning_status_inline_style($label);
    }
    return $map;
  }

  function planning_board_status_badge($status) {
    $s = trim((string)$status);
    if ($s === '') $s = erp_status_page_default('planning.label-printing');
    $class = erp_status_badge_class(planning_normalize_status($s));
    return '<span class="badge badge-' . $class . '">' . e($s) . '</span>';
  }

  function planning_try_parse_date($val) {
    $s = trim((string)$val);
    if ($s === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^\d{2}[\.\/-]\d{2}[\.\/-]\d{2,4}$/', $s)) {
      $parts = preg_split('/[\.\/-]/', $s);
      if (count($parts) === 3) {
        $y = strlen($parts[2]) === 2 ? ('20' . $parts[2]) : $parts[2];
        return sprintf('%04d-%02d-%02d', (int)$y, (int)$parts[1], (int)$parts[0]);
      }
    }
    return '';
  }

  function planning_default_dispatch_date($dispatchVal, $orderVal = '', $fallbackScheduled = '') {
    $dispatch = planning_try_parse_date($dispatchVal);
    if ($dispatch !== '') return $dispatch;

    $scheduled = planning_try_parse_date($fallbackScheduled);
    if ($scheduled !== '') return $scheduled;

    $orderDate = planning_try_parse_date($orderVal);
    if ($orderDate === '') return '';

    try {
      $dt = new DateTimeImmutable($orderDate);
      return $dt->modify('+12 days')->format('Y-m-d');
    } catch (Exception $e) {
      return '';
    }
  }

  function planning_extract_row_values(array $row, array $columns, $rowIndex = 0) {
    $extra = json_decode($row['extra_data'] ?? '{}', true);
    if (!is_array($extra)) $extra = [];
    $vals = [];
    foreach ($columns as $c) {
      $k = $c['key'];
      if ($k === 'sn') {
        $vals[$k] = (string)($rowIndex + 1);
        continue;
      }
      if ($k === 'printing_planning') {
        $default = erp_status_page_default('planning.label-printing');
        $liveStatus = planning_normalize_status((string)($row['status'] ?? ''));
        $extraStatus = planning_normalize_status((string)($extra['printing_planning'] ?? ''));
        $stageLifecycle = [
          'Preparing Slitting', 'Slitting', 'Slitting Pause', 'Slitted',
          'Printing', 'Printing Pause', 'Printed',
          'Die Cutting', 'Die Cutting Pause', 'Die Cut',
          'Barcode', 'Barcode Pause', 'Barcoded',
          'Label Slitting', 'Label Slitting Pause', 'Label Slitted',
          'Packing', 'Packing Pause', 'Packed'
        ];
        if (in_array($extraStatus, $stageLifecycle, true)) {
          $vals[$k] = $extraStatus;
          continue;
        }
        $vals[$k] = $liveStatus !== $default ? $liveStatus : ($extraStatus !== $default ? $extraStatus : $default);
        continue;
      }
      if ($k === 'priority') {
        $vals[$k] = (string)($row['priority'] ?? $extra['priority'] ?? 'Normal');
        continue;
      }
      if ($k === 'department_route') {
        $vals[$k] = planning_normalize_route_departments_by_die($extra['department_route'] ?? '', $extra['die'] ?? '');
        continue;
      }
      if ($k === 'die') {
        $vals[$k] = planning_normalize_die_value($extra['die'] ?? '');
        continue;
      }
      if ($k === 'dispatch_date') {
        $vals[$k] = planning_default_dispatch_date(
          $extra['dispatch_date'] ?? '',
          $extra['order_date'] ?? ($row['order_date'] ?? ''),
          $row['scheduled_date'] ?? ''
        );
        continue;
      }
      if (array_key_exists($k, $extra)) {
        $val = (string)$extra[$k];
        $vals[$k] = $val;
        continue;
      }
      if ($k === 'name') $vals[$k] = (string)($row['job_name'] ?? '');
      elseif ($k === 'remarks') $vals[$k] = (string)($row['notes'] ?? '');
      else $vals[$k] = (string)($row[$k] ?? '');
    }
    return $vals;
  }

function planning_norm_header_key($key) {
    return strtolower(trim(preg_replace('/\s+/', ' ', (string)$key)));
}

function planning_get_any_key(array $normRow, array $keys) {
    foreach ($keys as $k) {
        $nk = planning_norm_header_key($k);
        if (array_key_exists($nk, $normRow)) return $normRow[$nk];
    }
    return null;
}

function planning_is_safe_image_path($path) {
  $p = str_replace('\\', '/', trim((string)$path));
  if ($p === '') return false;
  return strpos($p, 'uploads/planning/') === 0;
}

function planning_remove_image_file($path) {
  $p = trim((string)$path);
  if ($p === '' || !planning_is_safe_image_path($p)) return;
  $abs = realpath(__DIR__ . '/../../' . ltrim($p, '/'));
  if ($abs && is_file($abs)) {
    @unlink($abs);
    $dir = dirname($abs);
    if (is_dir($dir)) {
      $left = @scandir($dir);
      if (is_array($left) && count($left) <= 2) {
        @rmdir($dir);
      }
    }
  }
}

function planning_save_uploaded_image($file, $rowId, $oldPath = '') {
  if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return [false, 'No image selected.', null];
  }
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return [false, 'Image upload failed.', null];
  }
  if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
    return [false, 'Image size must be below 8MB.', null];
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  $mime = @mime_content_type($tmp);
  $allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  ];
  if (!isset($allowed[$mime])) {
    return [false, 'Only PNG, JPG, WEBP, GIF files are allowed.', null];
  }

  $ext = $allowed[$mime];
  $dirRel = 'uploads/planning/' . (int)$rowId;
  $dirAbs = __DIR__ . '/../../' . $dirRel;
  if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0777, true)) {
    return [false, 'Unable to prepare upload directory.', null];
  }

  $safeName = 'plan_' . (int)$rowId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $absPath = $dirAbs . '/' . $safeName;
  if (!@move_uploaded_file($tmp, $absPath)) {
    return [false, 'Unable to save uploaded image.', null];
  }

  if ($oldPath !== '' && $oldPath !== ($dirRel . '/' . $safeName)) {
    planning_remove_image_file($oldPath);
  }

  return [true, '', [
    'image_path' => $dirRel . '/' . $safeName,
    'image_name' => (string)($file['name'] ?? $safeName),
    'image_uploaded_at' => date('Y-m-d H:i:s'),
  ]];
}

// AJAX actions for board.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        planning_json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 400);
    }

    $action = (string)$_POST['action'];

    // Permission guards for AJAX actions
    $ajaxCanAdd    = currentPageAction('add');
    $ajaxCanEdit   = currentPageAction('edit');
    $ajaxCanDelete = currentPageAction('delete');

    if (in_array($action, ['create_row', 'import_rows'], true) && !$ajaxCanAdd) {
        planning_json_response(['ok' => false, 'message' => 'Permission denied. You do not have Add access.'], 403);
    }
    if (in_array($action, ['save_row', 'reorder_rows', 'update_priority', 'upload_job_image', 'save_columns'], true) && !$ajaxCanEdit) {
        planning_json_response(['ok' => false, 'message' => 'Permission denied. You do not have Edit access.'], 403);
    }
    if (in_array($action, ['delete_row', 'delete_job_image'], true) && !$ajaxCanDelete) {
        planning_json_response(['ok' => false, 'message' => 'Permission denied. You do not have Delete access.'], 403);
    }

    if ($action === 'save_row') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);

      $rowValues = json_decode((string)($_POST['row_values_json'] ?? '{}'), true);
      if (!is_array($rowValues)) $rowValues = [];
      if (empty($rowValues)) {
        foreach ($_POST as $k => $v) {
          if (in_array($k, ['action', 'id', 'csrf_token'], true)) continue;
          $rowValues[$k] = is_scalar($v) ? (string)$v : '';
        }
      }

      $jobName = trim((string)($rowValues['name'] ?? $rowValues['job_name'] ?? ''));
        if ($jobName === '') planning_json_response(['ok' => false, 'message' => 'Job name is required.'], 400);

      $statusRaw = trim((string)($rowValues['printing_planning'] ?? $rowValues['status'] ?? $defaultStatus));
      if ($statusRaw === '') $statusRaw = $defaultStatus;
      $status = planning_normalize_status($statusRaw);
      // Persist canonical status token in extra_data so reload keeps correct color class.
      $rowValues['printing_planning'] = $status;
      $rowValues['die'] = planning_normalize_die_value($rowValues['die'] ?? '');
      $rowValues['department_route'] = planning_normalize_route_departments_by_die($rowValues['department_route'] ?? '', $rowValues['die'] ?? '');
      $priority = (string)($rowValues['priority'] ?? 'Normal');
        if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

      $rowValues['order_date'] = planning_try_parse_date($rowValues['order_date'] ?? '');
      $rowValues['dispatch_date'] = planning_default_dispatch_date(
        $rowValues['dispatch_date'] ?? '',
        $rowValues['order_date'] ?? '',
        $rowValues['scheduled_date'] ?? ''
      );

      $machine = trim((string)($rowValues['machine'] ?? ''));
      $operator = trim((string)($rowValues['operator_name'] ?? ''));
      $notes = trim((string)($rowValues['remarks'] ?? $rowValues['notes'] ?? ''));
      $scheduled = $rowValues['dispatch_date'];

      // Preserve uploaded image metadata stored in extra_data when saving row fields.
      $selExtra = $db->prepare("SELECT job_no, job_name, extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
      $selExtra->bind_param('is', $id, $department);
      $selExtra->execute();
      $existingRow = $selExtra->get_result()->fetch_assoc();
      $existingExtra = json_decode((string)($existingRow['extra_data'] ?? '{}'), true);
      if (!is_array($existingExtra)) $existingExtra = [];
      foreach (['image_path', 'image_name', 'image_uploaded_at'] as $imgKey) {
        if (!array_key_exists($imgKey, $rowValues) && array_key_exists($imgKey, $existingExtra)) {
          $rowValues[$imgKey] = $existingExtra[$imgKey];
        }
      }

      $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $up = $db->prepare("UPDATE planning SET job_name=?, machine=?, operator_name=?, scheduled_date=NULLIF(?, ''), status=?, priority=?, notes=?, extra_data=? WHERE id=? AND department=?");
      $up->bind_param('ssssssssis', $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $extraJson, $id, $department);
        $ok = $up->execute();
        if ($ok) {
          planningCreateNotifications(
            $db,
            (string)($existingRow['job_no'] ?? ''),
            $jobName !== '' ? $jobName : (string)($existingRow['job_name'] ?? ''),
            $department,
            'updated'
          );
        }
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Row updated.' : 'Update failed.']);
    }

    if ($action === 'delete_row') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);

      $sel = $db->prepare("SELECT job_no, job_name, extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
      $sel->bind_param('is', $id, $department);
      $sel->execute();
      $row = $sel->get_result()->fetch_assoc();
      if ($row) {
        $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
        if (is_array($extra) && !empty($extra['image_path'])) {
          planning_remove_image_file((string)$extra['image_path']);
        }
      }

        $del = $db->prepare("DELETE FROM planning WHERE id = ? AND department = ?");
        $del->bind_param('is', $id, $department);
        $ok = $del->execute();
        if ($ok && $row) {
          planningCreateNotifications($db, (string)($row['job_no'] ?? ''), (string)($row['job_name'] ?? ''), $department, 'deleted');
        }
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Row deleted.' : 'Delete failed.']);
    }

    if ($action === 'upload_job_image') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);
      if (empty($_FILES['job_image'])) {
        planning_json_response(['ok' => false, 'message' => 'No image selected.'], 400);
      }

      $sel = $db->prepare("SELECT extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
      $sel->bind_param('is', $id, $department);
      $sel->execute();
      $row = $sel->get_result()->fetch_assoc();
      if (!$row) {
        planning_json_response(['ok' => false, 'message' => 'Planning row not found.'], 404);
      }

      $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
      if (!is_array($extra)) $extra = [];
      $oldPath = trim((string)($extra['image_path'] ?? ''));

      list($saved, $err, $meta) = planning_save_uploaded_image($_FILES['job_image'], $id, $oldPath);
      if (!$saved || !is_array($meta)) {
        planning_json_response(['ok' => false, 'message' => $err !== '' ? $err : 'Image upload failed.'], 400);
      }

      $extra['image_path'] = $meta['image_path'];
      $extra['image_name'] = $meta['image_name'];
      $extra['image_uploaded_at'] = $meta['image_uploaded_at'];

      $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $up = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ? AND department = ?");
      $up->bind_param('sis', $extraJson, $id, $department);
      $ok = $up->execute();
      if (!$ok) {
        planning_remove_image_file((string)$meta['image_path']);
        planning_json_response(['ok' => false, 'message' => 'Image saved but database update failed.'], 500);
      }

      planning_json_response([
        'ok' => true,
        'message' => 'Image uploaded.',
        'image_path' => $meta['image_path'],
        'image_name' => $meta['image_name'],
        'image_uploaded_at' => $meta['image_uploaded_at'],
        'image_url' => appUrl($meta['image_path'])
      ]);
    }

    if ($action === 'delete_job_image') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);

      $sel = $db->prepare("SELECT extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
      $sel->bind_param('is', $id, $department);
      $sel->execute();
      $row = $sel->get_result()->fetch_assoc();
      if (!$row) {
        planning_json_response(['ok' => false, 'message' => 'Planning row not found.'], 404);
      }

      $extra = json_decode((string)($row['extra_data'] ?? '{}'), true);
      if (!is_array($extra)) $extra = [];
      $oldPath = trim((string)($extra['image_path'] ?? ''));
      if ($oldPath !== '') {
        planning_remove_image_file($oldPath);
      }

      unset($extra['image_path'], $extra['image_name'], $extra['image_uploaded_at']);
      $extraJson = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $up = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ? AND department = ?");
      $up->bind_param('sis', $extraJson, $id, $department);
      $ok = $up->execute();
      planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Image removed.' : 'Unable to remove image.']);
    }

    if ($action === 'create_row') {
      $rowValues = json_decode((string)($_POST['row_values_json'] ?? '{}'), true);
      if (!is_array($rowValues)) $rowValues = [];

      $jobName = trim((string)($rowValues['name'] ?? $_POST['job_name'] ?? ''));
        if ($jobName === '') planning_json_response(['ok' => false, 'message' => 'Job name is required.'], 400);

      $statusRaw = $defaultStatus;
      $rowValues['printing_planning'] = $defaultStatus;
      $rowValues = planning_apply_plate_defaults($rowValues, planning_find_plate_autofill_data($db, $rowValues['plate_no'] ?? $_POST['plate_no'] ?? ''));
      $rowValues['die'] = planning_normalize_die_value($rowValues['die'] ?? $_POST['die'] ?? '');
      $rowValues['department_route'] = planning_normalize_route_departments_by_die($rowValues['department_route'] ?? '', $rowValues['die'] ?? '');

      $status = $defaultStatus;
      $priority = (string)($rowValues['priority'] ?? $_POST['priority'] ?? 'Normal');
        if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

      $rowValues['order_date'] = planning_try_parse_date($rowValues['order_date'] ?? $_POST['order_date'] ?? '');
      $rowValues['dispatch_date'] = planning_default_dispatch_date(
        $rowValues['dispatch_date'] ?? '',
        $rowValues['order_date'] ?? '',
        $_POST['scheduled_date'] ?? ''
      );

      $machine = trim((string)($rowValues['machine'] ?? $_POST['machine'] ?? ''));
      $operator = trim((string)($rowValues['operator_name'] ?? $_POST['operator_name'] ?? ''));
      $notes = trim((string)($rowValues['remarks'] ?? $_POST['notes'] ?? ''));
      $scheduled = $rowValues['dispatch_date'];

      $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Auto-generate planning job number
      $planJobNo = getNextId('planning');
      if (!$planJobNo) $planJobNo = 'PLN-' . date('Ymd') . '-' . rand(1000,9999);

      $ins = $db->prepare("INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by) VALUES (?,NULL,?,?,?,NULLIF(?, ''),?,?,?,?,?,?)");
        $uid = (int)($_SESSION['user_id'] ?? 0);
      $ins->bind_param('ssssssssssi', $planJobNo, $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
        $ok = $ins->execute();
        if ($ok) planningCreateNotifications($db, $planJobNo, $jobName, $department, 'added');
        planning_json_response(['ok' => (bool)$ok, 'id' => $ok ? $db->insert_id : 0, 'job_no' => $planJobNo, 'message' => $ok ? 'Row created.' : 'Create failed.']);
    }

    if ($action === 'save_columns') {
        $payload = $_POST['columns_json'] ?? '[]';
        $cols = json_decode((string)$payload, true);
        if (!is_array($cols)) planning_json_response(['ok' => false, 'message' => 'Invalid payload.'], 400);

        $db->begin_transaction();
        try {
            $del = $db->prepare("DELETE FROM planning_board_columns WHERE department = ?");
            $del->bind_param('s', $department);
            $del->execute();

            $ins = $db->prepare("INSERT INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES (?,?,?,?,?)");
            $sort = 1;
            foreach ($cols as $c) {
                $key = trim((string)($c['key'] ?? ''));
                $label = trim((string)($c['label'] ?? ''));
                $type = trim((string)($c['type'] ?? 'Text'));
                if ($key === '' || $label === '') continue;
                if (!in_array($type, $allowedTypes, true)) $type = 'Text';
                $ins->bind_param('ssssi', $department, $key, $label, $type, $sort);
                $ins->execute();
                $sort++;
            }
            $db->commit();
            planning_json_response(['ok' => true, 'message' => 'Board headers updated.']);
        } catch (Exception $e) {
            $db->rollback();
            planning_json_response(['ok' => false, 'message' => 'Unable to save headers.'], 500);
        }
    }

    if ($action === 'import_rows') {
        $payload = $_POST['rows_json'] ?? '[]';
        $rows = json_decode((string)$payload, true);
        if (!is_array($rows)) planning_json_response(['ok' => false, 'message' => 'Invalid rows payload.'], 400);

      $activeColumns = planning_get_columns($db, $department, $defaultColumns);

        $ins = $db->prepare("INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by) VALUES (?,NULL,?,?,?,NULLIF(?, ''),?,?,?,?,?,?)");
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $count = 0;

        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            $normRow = [];
            foreach ($r as $rk => $rv) {
                $normRow[planning_norm_header_key($rk)] = is_scalar($rv) ? trim((string)$rv) : '';
            }

            $rowValues = [];
            foreach ($activeColumns as $col) {
                $k = $col['key'];
                if ($k === 'sn') continue;
                $rowValues[$k] = '';
            }

            $aliases = [
                'order_date' => ['order_date', 'order date'],
                'dispatch_date' => ['dispatch_date', 'dispatch date'],
                'printing_planning' => ['printing_planning', 'printing planing', 'status', 'planing', 'planning'],
                'plate_no' => ['plate_no', 'plate no', 'plate no.'],
                'name' => ['name', 'job name', 'job_name'],
                'size' => ['size'],
                'repeat' => ['repeat'],
                'material' => ['material'],
                'paper_size' => ['paper_size', 'paper size'],
              'department_route' => ['department_route', 'department', 'departments'],
                'die' => ['die'],
                'allocate_mtrs' => ['allocate_mtrs', 'mtrs', 'allocate mtrs', 'allocate'],
                'qty_pcs' => ['qty_pcs', 'qty', 'qty. (pcs)', 'qty (pcs)'],
                'core_size' => ['core_size', 'core', 'core size'],
                'qty_per_roll' => ['qty_per_roll', 'qty/roll', 'qty per roll', 'qty. per roll'],
                'roll_direction' => ['roll_direction', 'direction', 'roll direction'],
                'remarks' => ['remarks', 'notes']
            ];

            foreach ($aliases as $k => $tryKeys) {
                $v = planning_get_any_key($normRow, $tryKeys);
               if ($v !== null && trim((string)$v) !== '') {
                 $rowValues[$k] = (string)$v;
               } else {
                 // Show NA for missing/blank values - user can edit them later
                 $rowValues[$k] = $k === 'department_route' ? '' : 'NA';
               }
            }

            $rowValues = planning_apply_plate_defaults($rowValues, planning_find_plate_autofill_data($db, $rowValues['plate_no'] ?? ''));
            $rowValues['die'] = planning_normalize_die_value($rowValues['die'] ?? '');
            $rowValues['department_route'] = planning_normalize_route_departments_by_die($rowValues['department_route'] ?? '', $rowValues['die'] ?? '');

            $jobName = trim((string)($rowValues['name'] ?? ''));
            if ($jobName === '') continue;

            $machine = (string)(planning_get_any_key($normRow, ['machine']) ?? '');
            $operator = (string)(planning_get_any_key($normRow, ['operator_name', 'operator']) ?? '');
            $rowValues['order_date'] = planning_try_parse_date($rowValues['order_date'] ?? '');
            $scheduled = planning_default_dispatch_date(
              $rowValues['dispatch_date'] ?? '',
              $rowValues['order_date'] ?? '',
              planning_get_any_key($normRow, ['scheduled_date', 'scheduled date']) ?? ''
            );
            $rowValues['dispatch_date'] = $scheduled;

            $status = $defaultStatus;
            $rowValues['printing_planning'] = $defaultStatus;

            $priority = (string)(planning_get_any_key($normRow, ['priority']) ?? 'Normal');
            if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

            $notes = trim((string)($rowValues['remarks'] ?? ''));
            $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Auto-generate planning job number for each imported row
            $planJobNo = getNextId('planning');
            if (!$planJobNo) $planJobNo = 'PLN-' . date('Ymd') . '-' . rand(1000,9999);

            $ins->bind_param('ssssssssssi', $planJobNo, $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
            if ($ins->execute()) {
              planningCreateNotifications($db, $planJobNo, $jobName, $department, 'imported');
              $count++;
            }
        }

        if ($count === 0) {
            planning_json_response(['ok' => false, 'message' => 'Import failed: No valid rows matched required Job Name column.'], 400);
        }
        planning_json_response(['ok' => true, 'message' => 'Import completed.', 'count' => $count]);
    }

    if ($action === 'reorder_rows') {
        $order = json_decode((string)($_POST['order_json'] ?? '[]'), true);
        if (!is_array($order) || empty($order)) planning_json_response(['ok' => false, 'message' => 'Invalid order data.'], 400);
        $up = $db->prepare("UPDATE planning SET sequence_order = ? WHERE id = ? AND department = ?");
        foreach ($order as $idx => $rowId) {
            $seq = (int)($idx + 1);
            $rid = (int)$rowId;
            if ($rid <= 0) continue;
            $up->bind_param('iis', $seq, $rid, $department);
            $up->execute();
        }
        planningCreateNotifications($db, '', 'Planning board', $department, 'sequence updated');
        planning_json_response(['ok' => true, 'message' => 'Sequence updated.']);
    }

    if ($action === 'update_priority') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);
        $newPriority = (string)($_POST['priority'] ?? 'Normal');
        if (!in_array($newPriority, $priorityList, true)) $newPriority = 'Normal';
        $sel = $db->prepare("SELECT job_no, job_name, extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
        $sel->bind_param('is', $id, $department);
        $sel->execute();
        $existRow = $sel->get_result()->fetch_assoc();
        if ($existRow) {
            $ex = json_decode((string)($existRow['extra_data'] ?? '{}'), true);
            if (!is_array($ex)) $ex = [];
            $ex['priority'] = $newPriority;
            $exJson = json_encode($ex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $up = $db->prepare("UPDATE planning SET priority = ?, extra_data = ? WHERE id = ? AND department = ?");
            $up->bind_param('ssis', $newPriority, $exJson, $id, $department);
            $ok = $up->execute();
            if ($ok) {
              planningCreateNotifications($db, (string)($existRow['job_no'] ?? ''), (string)($existRow['job_name'] ?? ''), $department, 'priority updated');
            }
        } else {
            $ok = false;
        }
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Priority updated.' : 'Update failed.']);
    }

    planning_json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
}

$columns = planning_get_columns($db, $department, $defaultColumns);
$rows = planning_get_rows($db, $department);
$planningJobPreview = previewNextId('planning') ?: 'Auto-generated on save';
$deptOptions = planning_department_options();
$routeDepartmentChoices = planning_route_department_choices();
$planningMaterialSuggestions = planning_material_suggestions($db);
$planningPlateSuggestionList = [];
$planningJobSuggestionList = [];
$planningPlateAutofillMap = [];
$planningPickerItems = [];
foreach (planning_plate_records($db) as $plateRow) {
  $payload = planning_plate_autofill_payload($plateRow, $planningMaterialSuggestions);
  $plateNo = trim((string)($payload['plate_no'] ?? ''));
  if ($plateNo === '') continue;
  $planningPlateSuggestionList[] = $plateNo;
  $jobName = trim((string)($payload['name'] ?? ''));
  if ($jobName !== '') $planningJobSuggestionList[] = $jobName;
  $lookupKey = planning_normalize_lookup_key($plateNo);
  if ($lookupKey !== '' && !isset($planningPlateAutofillMap[$lookupKey])) {
    $planningPlateAutofillMap[$lookupKey] = $payload;
  }
  $planningPickerItems[] = [
    'lookup_key' => $lookupKey,
    'plate_no' => $plateNo,
    'name' => $jobName,
    'label' => trim($plateNo . ($jobName !== '' ? ' - ' . $jobName : '')),
    'meta' => trim(($payload['size'] ?? '') . (($payload['material'] ?? '') !== '' ? ' | ' . $payload['material'] : '')),
  ] + $payload;
}
$planningPlateSuggestionList = array_values(array_unique($planningPlateSuggestionList));
$planningJobSuggestionList = array_values(array_unique($planningJobSuggestionList));
$boardUrl = appUrl('modules/planning/index.php?department=' . rawurlencode($department));
$historyUrl = appUrl('modules/planning/history.php?department=' . rawurlencode($department));

// Granular permissions: admin always gets full access
$canAdd    = currentPageAction('add');
$canEdit   = currentPageAction('edit');
$canDelete = currentPageAction('delete');

$pageTitle = 'Planning Board';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>Planning</span>
</div>

<div class="planning-view-switch">
  <a href="<?= e($boardUrl) ?>" class="planning-view-link is-active"><i class="bi bi-grid-1x2"></i> Board</a>
  <a href="<?= e($historyUrl) ?>" class="planning-view-link"><i class="bi bi-clock-history"></i> History</a>
</div>

<div class="page-header">
  <div>
    <h1>Planning Board</h1>
    <p>Live planning board with inline editing, Excel import, and configurable headers.</p>
  </div>
  <div class="d-flex gap-8">
    <button type="button" class="btn btn-ghost" id="btn-print-pdf"><i class="bi bi-printer"></i> Print / PDF</button>
    <button type="button" class="btn btn-ghost" id="btn-export-excel"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
    <?php if ($canEdit): ?>
    <button type="button" class="btn btn-ghost" id="btn-board-config"><i class="bi bi-layout-text-window-reverse"></i> Board Layout</button>
    <?php endif; ?>
    <?php if ($canAdd): ?>
    <button type="button" class="btn btn-secondary" id="btn-import"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</button>
    <button type="button" class="btn btn-primary" id="btn-add-row"><i class="bi bi-plus-circle"></i> Add Job Entry</button>
    <?php endif; ?>
    <input type="file" id="excel-file" accept=".xlsx,.xls" style="display:none">
  </div>
</div>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">Department: <?= e($department) ?>
      <span class="badge badge-consumed" style="margin-left:6px"><?= count($rows) ?></span>
    </span>
    <div class="d-flex gap-8">
      <select id="dept-switch" class="form-control" style="min-width:210px">
        <?php foreach ($deptOptions as $d): ?>
          <option value="<?= e($d) ?>" <?= $d === $department ? 'selected' : '' ?>><?= e(planning_department_label($d)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="table-wrap planning-board-wrap">
    <table id="planning-board-table" class="planning-board-table">
      <thead>
        <tr>
          <th class="seq-drag-col">&nbsp;</th>
          <th class="sticky-col">Actions</th>
          <th>S.N</th>
          <th>Job No</th>
          <?php foreach ($columns as $c): ?>
            <?php if ($c['key'] === 'sn') continue; ?>
            <th draggable="true" data-col-key="<?= e($c['key']) ?>"><?= e($c['label']) ?></th>
          <?php endforeach; ?>
          <th>Job Image</th>
        </tr>
      </thead>
      <tbody>
      <?php $visibleColCount = 0; foreach ($columns as $_c) { if ($_c['key'] !== 'sn') $visibleColCount++; } ?>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= $visibleColCount + 5 ?>" class="table-empty"><i class="bi bi-inbox"></i>No planning jobs found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $r): ?>
          <?php
            $rowVals = planning_extract_row_values($r, $columns, $idx);
            $rowStatusVal = $defaultStatus;
            foreach ($columns as $_sc) { if ($_sc['type'] === 'Status') { $rowStatusVal = (string)($rowVals[$_sc['key']] ?? $defaultStatus); break; } }
            $rowSCls = planning_status_pill_class($rowStatusVal);
            $rowExtra = json_decode((string)($r['extra_data'] ?? '{}'), true);
            if (!is_array($rowExtra)) $rowExtra = [];
            $jobImagePath = trim((string)($rowExtra['image_path'] ?? ''));
            $jobImageName = trim((string)($rowExtra['image_name'] ?? ''));
            $jobImageUploadedAt = trim((string)($rowExtra['image_uploaded_at'] ?? ''));
            $jobImageUrl = $jobImagePath !== '' ? appUrl($jobImagePath) : '';
            $rowPlateUps = trim((string)($rowExtra['ups'] ?? ''));
          ?>
          <tr data-id="<?= (int)$r['id'] ?>" data-plate-ups="<?= e($rowPlateUps) ?>" class="row-s-<?= $rowSCls ?>" <?= $canEdit ? 'draggable="true"' : '' ?>>
            <td class="seq-drag-col <?= $canEdit ? 'seq-drag-handle' : '' ?>" <?= $canEdit ? 'title="Drag to reorder"' : '' ?>><?php if ($canEdit): ?><i class="bi bi-grip-vertical"></i><?php endif; ?></td>
            <td class="sticky-col">
              <div class="row-actions">
                <?php if ($canEdit): ?>
                <button class="btn btn-secondary btn-sm btn-edit" type="button" title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-primary btn-sm btn-save" type="button" title="Save" style="display:none"><i class="bi bi-check2-circle"></i></button>
                <button class="btn btn-ghost btn-sm btn-cancel" type="button" title="Cancel" style="display:none"><i class="bi bi-x-circle"></i></button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                <button class="btn btn-danger btn-sm btn-delete" type="button" title="Delete"><i class="bi bi-trash"></i></button>
                <?php endif; ?>
              </div>
            </td>
            <td><?= (int)($idx + 1) ?></td>
            <td class="job-no-cell">
              <strong><?= e($r['job_no'] ?? '—') ?></strong>
              <?php
                $linkedJobs = $planLinkedJobs[(int)$r['id']] ?? [];
                foreach ($linkedJobs as $lj):
                  $ljNo = e($lj['job_no'] ?? '');
                  $ljDept = strtolower(trim((string)($lj['department'] ?? '')));
                  $ljType = strtolower(trim((string)($lj['job_type'] ?? '')));
                  $ljUrl = '';
                  if ($ljDept === 'jumbo_slitting' || $ljType === 'slitting') {
                    $ljUrl = BASE_URL . '/modules/jobs/jumbo/index.php';
                  } elseif ($ljDept === 'flexo_printing' || $ljType === 'printing') {
                    $ljUrl = BASE_URL . '/modules/jobs/printing/index.php';
                  }
                  if ($ljUrl !== '' && $ljNo !== ''):
              ?>
                <a href="<?= e($ljUrl) ?>" class="linked-job-link" title="Open <?= $ljNo ?> job card"><?= $ljNo ?></a>
              <?php endif; endforeach; ?>
            </td>
            <?php foreach ($columns as $c): ?>
              <?php if ($c['key'] === 'sn') continue; ?>
              <?php
                $k = $c['key'];
                $v = (string)($rowVals[$k] ?? '');
                if ($c['type'] === 'Status') {
                  $v = planning_normalize_status($v);
                }
              ?>
              <td data-key="<?= e($k) ?>" data-type="<?= e($c['type']) ?>" data-raw="<?= e($v) ?>">
                <?php if ($c['type'] === 'Status'): ?>
                  <span class="cell-display status-pill" style="<?= e(planning_status_inline_style($v ?: $defaultStatus)) ?>"><?= e($v ?: $defaultStatus) ?></span>
                  <?php $statusOptions = $statusList; ?>
                  <select class="cell-input cell-select-status form-control" style="display:none">
                    <?php foreach ($statusOptions as $s): ?><option value="<?= e($s) ?>"<?= $v === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                  </select>
                <?php elseif ($c['type'] === 'Priority'): ?>
                  <?php $pVal = $v ?: 'Normal'; $pCls = strtolower($pVal); ?>
                  <span class="cell-display priority-pill priority-pill-<?= e($pCls) ?>"><?= e($pVal) ?></span>
                  <select class="cell-input cell-select-priority form-control" style="display:none">
                    <?php foreach ($priorityList as $p): ?><option value="<?= e($p) ?>"<?= $pVal === $p ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
                  </select>
                <?php elseif ($c['type'] === 'Department'): ?>
                  <?php $selectedRoutes = array_filter(array_map('trim', explode(',', $v))); ?>
                  <span class="cell-display department-cell-display"><?= e($v !== '' ? $v : '—') ?></span>
                  <div class="cell-input department-check-group" data-input-type="department" style="display:none">
                    <?php foreach ($routeDepartmentChoices as $routeLabel): ?>
                      <label class="department-check-option">
                        <input type="checkbox" class="department-check-input" value="<?= e($routeLabel) ?>"<?= in_array($routeLabel, $selectedRoutes, true) ? ' checked' : '' ?>>
                        <span><?= e($routeLabel) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php elseif ($k === 'die'): ?>
                  <?php $dieOptions = planning_die_options(); ?>
                  <span class="cell-display"><?= e($v !== '' ? $v : '—') ?></span>
                  <select class="cell-input form-control" style="display:none">
                    <option value="">Select Die</option>
                    <?php foreach ($dieOptions as $dieOption): ?>
                      <option value="<?= e($dieOption) ?>"<?= $v === $dieOption ? ' selected' : '' ?>><?= e($dieOption) ?></option>
                    <?php endforeach; ?>
                    <?php if ($v !== '' && !in_array($v, $dieOptions, true)): ?>
                      <option value="<?= e($v) ?>" selected><?= e($v) ?></option>
                    <?php endif; ?>
                  </select>
                <?php elseif ($k === 'plate_no'): ?>
                  <span class="cell-display planning-field-highlight"><?= e($v !== '' ? $v : '—') ?></span>
                  <div class="planning-picker-inline">
                    <input class="cell-input form-control planning-picker-source planning-picker-highlight" type="text" value="<?= e($v) ?>" list="planning-plate-options" autocomplete="off" data-picker-field="plate_no" style="display:none">
                    <button type="button" class="cell-input-action planning-picker-btn" data-picker-trigger="plate_no" style="display:none"><i class="bi bi-search"></i></button>
                  </div>
                <?php elseif ($k === 'name'): ?>
                  <span class="cell-display planning-field-highlight"><?= e($v !== '' ? $v : '—') ?></span>
                  <div class="planning-picker-inline">
                    <input class="cell-input form-control planning-picker-source planning-picker-highlight" type="text" value="<?= e($v) ?>" list="planning-job-options" autocomplete="off" data-picker-field="name" style="display:none">
                    <button type="button" class="cell-input-action planning-picker-btn" data-picker-trigger="name" style="display:none"><i class="bi bi-search"></i></button>
                  </div>
                <?php elseif ($k === 'material'): ?>
                  <span class="cell-display"><?= e($v !== '' ? $v : '—') ?></span>
                  <input class="cell-input form-control" type="text" value="<?= e($v) ?>" list="planning-material-options" autocomplete="off" style="display:none">
                <?php else: ?>
                  <span class="cell-display"><?= e($v !== '' ? $v : '—') ?></span>
                  <input class="cell-input form-control" type="text" value="<?= e($v) ?>" style="display:none">
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="job-image-cell"
                data-image-path="<?= e($jobImagePath) ?>"
                data-image-url="<?= e($jobImageUrl) ?>"
                data-image-name="<?= e($jobImageName) ?>"
                data-image-uploaded-at="<?= e($jobImageUploadedAt) ?>">
              <div class="job-image-box <?= $jobImageUrl !== '' ? 'has-image' : 'no-image' ?>">
                <?php if ($jobImageUrl !== ''): ?>
                  <button type="button" class="job-image-thumb-btn" title="Open image">
                    <img src="<?= e($jobImageUrl) ?>" alt="Job image" class="job-image-thumb">
                  </button>
                <?php else: ?>
                  <button type="button" class="job-image-empty-btn" title="Add image"><i class="bi bi-image"></i></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="planning-modal" id="modal-add" style="display:none">
  <div class="planning-modal-card">
    <div class="planning-modal-head">
      <h3>Add Job Entry</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <form id="form-add-row">
      <div class="planning-job-preview-box">
        <span class="planning-job-preview-label">Job ID</span>
        <strong id="planning-job-preview"><?= e($planningJobPreview) ?></strong>
        <small>Final number is assigned when you save.</small>
      </div>
      <div class="planning-grid">
        <?php foreach ($columns as $c): ?>
          <?php if ($c['key'] === 'sn') continue; ?>
          <div<?= in_array($c['key'], ['department_route', 'remarks'], true) ? ' style="grid-column:1 / -1"' : '' ?>>
            <label><?= e($c['label']) ?><?= $c['key'] === 'name' ? ' *' : '' ?></label>
            <?php if ($c['type'] === 'Status'): ?>
              <input class="form-control" type="text" value="<?= e($defaultStatus) ?>" readonly>
              <input type="hidden" name="<?= e($c['key']) ?>" value="<?= e($defaultStatus) ?>">
            <?php elseif ($c['type'] === 'Priority'): ?>
              <select class="form-control" name="<?= e($c['key']) ?>">
                <?php foreach ($priorityList as $p): ?><option value="<?= e($p) ?>"<?= $p === 'Normal' ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
              </select>
            <?php elseif ($c['type'] === 'Department'): ?>
              <div class="department-check-group department-check-group-modal" data-input-type="department" data-name="<?= e($c['key']) ?>">
                <?php foreach ($routeDepartmentChoices as $routeLabel): ?>
                  <label class="department-check-option">
                        <input type="checkbox" class="department-check-input" value="<?= e($routeLabel) ?>"<?= in_array($routeLabel, array_filter(array_map('trim', explode(',', planning_default_route_departments('FlatBed')))), true) ? ' checked' : '' ?>>
                    <span><?= e($routeLabel) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php elseif ($c['key'] === 'die'): ?>
              <select class="form-control" name="<?= e($c['key']) ?>">
                <?php foreach (planning_die_options() as $dieOption): ?>
                  <option value="<?= e($dieOption) ?>"<?= $dieOption === 'FlatBed' ? ' selected' : '' ?>><?= e($dieOption) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($c['key'] === 'plate_no'): ?>
              <div class="planning-picker-inline planning-picker-inline-modal">
                <input class="form-control planning-picker-source planning-picker-highlight" name="<?= e($c['key']) ?>" type="text" list="planning-plate-options" autocomplete="off" data-picker-field="plate_no">
                <button type="button" class="planning-picker-btn" data-picker-trigger="plate_no"><i class="bi bi-search"></i></button>
              </div>
            <?php elseif ($c['key'] === 'name'): ?>
              <div class="planning-picker-inline planning-picker-inline-modal">
                <input class="form-control planning-picker-source planning-picker-highlight" name="<?= e($c['key']) ?>" type="text" list="planning-job-options" autocomplete="off" data-picker-field="name" required>
                <button type="button" class="planning-picker-btn" data-picker-trigger="name"><i class="bi bi-search"></i></button>
              </div>
            <?php elseif ($c['key'] === 'material'): ?>
              <input class="form-control" name="<?= e($c['key']) ?>" type="text" list="planning-material-options" autocomplete="off">
            <?php else: ?>
              <input class="form-control" name="<?= e($c['key']) ?>" type="<?= $c['type'] === 'Number' ? 'number' : ($c['type'] === 'Date' ? 'date' : 'text') ?>" <?= $c['key'] === 'name' ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div style="grid-column:1 / -1">
          <label>Job Image (Optional)</label>
          <input class="form-control" name="job_image" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
          <div id="planning-plate-link-note" style="margin-top:6px;font-size:.76rem;color:#64748b">Select Plate No to auto-fill linked plate data. Manual upload overrides plate image.</div>
          <input type="hidden" name="image_path" value="">
          <input type="hidden" name="image_name" value="">
          <input type="hidden" name="image_uploaded_at" value="">
          <input type="hidden" name="image_source" value="">
          <input type="hidden" name="ups" value="">
        </div>
      </div>
      <div class="planning-modal-foot">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Save Entry</button>
      </div>
    </form>
  </div>
</div>

<datalist id="planning-plate-options">
  <?php foreach ($planningPlateSuggestionList as $plateOption): ?>
    <option value="<?= e($plateOption) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="planning-material-options">
  <?php foreach ($planningMaterialSuggestions as $materialOption): ?>
    <option value="<?= e($materialOption) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="planning-job-options">
  <?php foreach ($planningJobSuggestionList as $jobOption): ?>
    <option value="<?= e($jobOption) ?>"></option>
  <?php endforeach; ?>
</datalist>

<div class="planning-modal" id="modal-planning-picker" style="display:none">
  <div class="planning-modal-card planning-picker-modal-card">
    <div class="planning-modal-head">
      <h3 id="planning-picker-title">Search</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="planning-picker-modal-body">
      <div class="planning-picker-searchbar">
        <i class="bi bi-search"></i>
        <input type="text" id="planning-picker-search" class="form-control" placeholder="Search...">
      </div>
      <div id="planning-picker-list" class="planning-picker-list"></div>
    </div>
  </div>
</div>

<div class="planning-modal" id="modal-config" style="display:none">
  <div class="planning-modal-card">
    <div class="planning-modal-head">
      <h3>Board Header Configuration</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="config-list" class="config-list"></div>
    <div class="planning-modal-foot">
      <button type="button" class="btn btn-primary" id="btn-save-config"><i class="bi bi-save"></i> Save Header Changes</button>
    </div>
  </div>
</div>

<div class="planning-modal" id="modal-import-map" style="display:none">
  <div class="planning-modal-card">
    <div class="planning-modal-head">
      <h3>Excel Column Mapping</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:10px 16px 0;color:#475569;font-size:.82rem">
      Map uploaded Excel columns to Planning fields before import.
    </div>
    <div id="import-map-list" class="import-map-list"></div>
    <div class="planning-modal-foot">
      <button type="button" class="btn btn-ghost" id="btn-import-map-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="btn-import-map-apply"><i class="bi bi-check2-circle"></i> Import Mapped Rows</button>
    </div>
  </div>
</div>

<div class="planning-modal" id="modal-plan-detail" style="display:none">
  <div class="planning-modal-card">
    <div class="planning-modal-head">
      <h3>Plan Details</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="plan-detail-content" class="plan-detail-grid"></div>
  </div>
</div>

<div class="planning-modal" id="modal-image-viewer" style="display:none">
  <div class="planning-modal-card image-viewer-modal-card">
    <div class="planning-modal-head">
      <h3>Planning Job Image</h3>
      <button type="button" class="btn btn-ghost btn-sm modal-close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="image-viewer-toolbar">
      <div class="image-viewer-meta" id="image-viewer-meta"></div>
      <div class="image-viewer-controls">
        <button type="button" class="btn btn-ghost btn-sm" id="image-zoom-out"><i class="bi bi-dash-lg"></i></button>
        <button type="button" class="btn btn-ghost btn-sm" id="image-zoom-reset">100%</button>
        <button type="button" class="btn btn-ghost btn-sm" id="image-zoom-in"><i class="bi bi-plus-lg"></i></button>
        <button type="button" class="btn btn-ghost btn-sm" id="image-upload-btn"><i class="bi bi-upload"></i> Upload</button>
        <button type="button" class="btn btn-secondary btn-sm" id="image-edit-btn"><i class="bi bi-pencil-square"></i> Edit</button>
        <button type="button" class="btn btn-danger btn-sm" id="image-delete-btn"><i class="bi bi-trash"></i> Delete</button>
      </div>
    </div>
    <div class="image-viewer-stage" id="image-viewer-stage">
      <img id="image-viewer-img" alt="Planning job image">
    </div>
  </div>
</div>

<form id="csrf-form" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="department" value="<?= e($department) ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
<script>
(function(){
  'use strict';

  var boardTable = document.getElementById('planning-board-table');
  var deptSwitch = document.getElementById('dept-switch');
  var addModal = document.getElementById('modal-add');
  var addForm = document.getElementById('form-add-row');
  var cfgModal = document.getElementById('modal-config');
  var importMapModal = document.getElementById('modal-import-map');
  var detailModal = document.getElementById('modal-plan-detail');
  var imageViewerModal = document.getElementById('modal-image-viewer');
  var imageViewerImg = document.getElementById('image-viewer-img');
  var imageViewerMeta = document.getElementById('image-viewer-meta');
  var imageZoomInBtn = document.getElementById('image-zoom-in');
  var imageZoomOutBtn = document.getElementById('image-zoom-out');
  var imageZoomResetBtn = document.getElementById('image-zoom-reset');
  var imageUploadBtn = document.getElementById('image-upload-btn');
  var imageEditBtn = document.getElementById('image-edit-btn');
  var imageDeleteBtn = document.getElementById('image-delete-btn');
  var imageViewerStage = document.getElementById('image-viewer-stage');
  var fileInput = document.getElementById('excel-file');
  var btnExportExcel = document.getElementById('btn-export-excel');
  var btnPrintPdf = document.getElementById('btn-print-pdf');
  var importMapList = document.getElementById('import-map-list');
  var btnImportMapApply = document.getElementById('btn-import-map-apply');
  var btnImportMapCancel = document.getElementById('btn-import-map-cancel');
  var columns = <?= json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var routeDepartmentChoices = <?= json_encode($routeDepartmentChoices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var defaultRouteDepartments = <?= json_encode(planning_default_route_departments('FlatBed'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var plateAutofillMap = <?= json_encode($planningPlateAutofillMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var planningPickerItems = <?= json_encode($planningPickerItems, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var materialSuggestions = <?= json_encode($planningMaterialSuggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var planningStatusStyleMap = <?= json_encode(planning_status_style_map_data(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var defaultPlanningStatus = <?= json_encode($defaultStatus, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var defaultPlanningStatusKey = String(defaultPlanningStatus || '').trim().toLowerCase();
  var csrfToken = document.querySelector('#csrf-form [name="csrf_token"]').value;
  var currentDepartment = <?= json_encode($department, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var CAN_ADD = <?= $canAdd ? 'true' : 'false' ?>;
  var CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
  var CAN_DELETE = <?= $canDelete ? 'true' : 'false' ?>;
  var imageViewerScale = 1;
  var imageViewerRowId = '';
  var pickerModal = document.getElementById('modal-planning-picker');
  var pickerTitle = document.getElementById('planning-picker-title');
  var pickerSearch = document.getElementById('planning-picker-search');
  var pickerList = document.getElementById('planning-picker-list');
  var activePickerContext = null;
  var pendingImportRows = [];
  var pendingImportHeaders = [];
  var imageUploadInput = document.createElement('input');
  imageUploadInput.type = 'file';
  imageUploadInput.accept = 'image/png,image/jpeg,image/webp,image/gif';
  imageUploadInput.style.display = 'none';
  document.body.appendChild(imageUploadInput);

  function safeText(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function normalizeLookupKey(value) {
    return String(value == null ? '' : value).trim().toLowerCase().replace(/[^a-z0-9]+/g, '');
  }

  function isBlankishValue(value) {
    var text = String(value == null ? '' : value).trim();
    return text === '' || text === '-' || text === '—' || text.toUpperCase() === 'NA';
  }

  function addDaysToDate(dateValue, dayCount) {
    var text = String(dateValue == null ? '' : dateValue).trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(text)) return '';
    var dt = new Date(text + 'T00:00:00');
    if (Number.isNaN(dt.getTime())) return '';
    dt.setDate(dt.getDate() + dayCount);
    var y = dt.getFullYear();
    var m = String(dt.getMonth() + 1).padStart(2, '0');
    var d = String(dt.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
  }

  function lookupPlateAutofillData(plateValue) {
    var key = normalizeLookupKey(plateValue);
    return key && Object.prototype.hasOwnProperty.call(plateAutofillMap, key) ? plateAutofillMap[key] : null;
  }

  function lookupByJobName(jobName) {
    var key = normalizeLookupKey(jobName);
    if (!key) return null;
    for (var i = 0; i < planningPickerItems.length; i += 1) {
      if (normalizeLookupKey(planningPickerItems[i].name) === key) return planningPickerItems[i];
    }
    return null;
  }

  function setFormValue(field, value) {
    if (!addForm) return;
    var input = addForm.querySelector('[name="' + field + '"]');
    if (!input) return;
    input.value = String(value == null ? '' : value);
  }

  function parseCalcNumber(value) {
    var text = String(value == null ? '' : value).replace(/,/g, '').trim();
    var num = parseFloat(text);
    return Number.isFinite(num) ? num : 0;
  }

  function getCalcParams(source) {
    if (!source) return { qtyRoll: 0, ups: 0, repeatValue: 0 };
    return {
      qtyRoll: parseCalcNumber(source.qtyPerRoll || source.qty_roll || source.qty_per_roll || ''),
      ups: parseCalcNumber(source.ups || ''),
      repeatValue: parseCalcNumber(source.repeat || source.repeat_value || '')
    };
  }

  function calculateMeterFromQty(params, qty) {
    if (qty <= 0) return '';
    if (params.qtyRoll > 0) return (qty / params.qtyRoll).toFixed(2);
    if (params.ups > 0 && params.repeatValue > 0) return ((qty / params.ups) * (params.repeatValue / 1000)).toFixed(2);
    return '';
  }

  function calculateQtyFromMeter(params, meter) {
    if (meter <= 0) return '';
    if (params.qtyRoll > 0) return String(Math.round(meter * params.qtyRoll));
    if (params.ups > 0 && params.repeatValue > 0) return String(Math.round((meter / (params.repeatValue / 1000)) * params.ups));
    return '';
  }

  function updateAddPlateLinkNote(data) {
    var note = document.getElementById('planning-plate-link-note');
    if (!note) return;
    if (data && data.image_path) {
      note.textContent = 'Plate data matched. Linked plate image will be used unless you upload a new image.';
      return;
    }
    note.textContent = 'Select Plate No to auto-fill linked plate data. Manual upload overrides plate image.';
  }

  function setAddFormPlateImageData(data) {
    setFormValue('image_path', data && data.image_path ? data.image_path : '');
    setFormValue('image_name', data && data.image_name ? data.image_name : '');
    setFormValue('image_uploaded_at', data && data.image_uploaded_at ? data.image_uploaded_at : '');
    setFormValue('image_source', data && data.image_source ? data.image_source : '');
    updateAddPlateLinkNote(data);
  }

  function applyPlateAutofillToAddForm(data) {
    if (!addForm || !data) return;
    ['plate_no', 'name', 'size', 'repeat', 'paper_size', 'material', 'die', 'core_size', 'qty_per_roll', 'roll_direction', 'ups'].forEach(function(field){
      if (Object.prototype.hasOwnProperty.call(data, field) && data[field] != null && data[field] !== '') {
        setFormValue(field, data[field]);
      }
    });
    setAddFormPlateImageData(data);
    var dieInput = addForm.querySelector('[name="die"]');
    var departmentGroup = addForm.querySelector('[data-input-type="department"][data-name="department_route"]');
    if (dieInput && departmentGroup) {
      setDepartmentInputValue(departmentGroup, getRouteDefaultDepartmentsByDie(dieInput.value));
    }
    syncCalcFieldsForContainer(addForm, data);
  }

  function getContainerFieldValue(container, field) {
    if (!container) return '';
    var input = container.querySelector('[name="' + field + '"]') || container.querySelector('td[data-key="' + field + '"] .cell-input');
    return input ? String(input.value || '').trim() : '';
  }

  function setContainerFieldValue(container, field, value) {
    if (!container) return;
    var input = container.querySelector('[name="' + field + '"]') || container.querySelector('td[data-key="' + field + '"] .cell-input');
    if (input) input.value = String(value == null ? '' : value);
  }

  function syncCalcFieldsForContainer(container, source) {
    if (!container) return;
    var qtyVal = parseCalcNumber(getContainerFieldValue(container, 'qty_pcs'));
    var meterVal = parseCalcNumber(getContainerFieldValue(container, 'allocate_mtrs'));
    var params = getCalcParams(source || {
      qtyPerRoll: getContainerFieldValue(container, 'qty_per_roll'),
      ups: getContainerFieldValue(container, 'ups'),
      repeat: getContainerFieldValue(container, 'repeat')
    });
    if (qtyVal > 0) {
      setContainerFieldValue(container, 'allocate_mtrs', calculateMeterFromQty(params, qtyVal));
    } else if (meterVal > 0) {
      setContainerFieldValue(container, 'qty_pcs', calculateQtyFromMeter(params, meterVal));
    }
  }

  function bindQtyMeterAutoCalc(container, paramsSource) {
    if (!container) return;
    var qtyInput = container.querySelector('[name="qty_pcs"]') || container.querySelector('td[data-key="qty_pcs"] .cell-input');
    var meterInput = container.querySelector('[name="allocate_mtrs"]') || container.querySelector('td[data-key="allocate_mtrs"] .cell-input');
    if (!qtyInput || !meterInput) return;

    qtyInput.oninput = function() {
      var params = getCalcParams(typeof paramsSource === 'function' ? paramsSource() : paramsSource);
      meterInput.value = calculateMeterFromQty(params, parseCalcNumber(qtyInput.value));
    };
    meterInput.oninput = function() {
      var params = getCalcParams(typeof paramsSource === 'function' ? paramsSource() : paramsSource);
      qtyInput.value = calculateQtyFromMeter(params, parseCalcNumber(meterInput.value));
    };
  }

  function filterPickerItems(mode, searchText) {
    var needle = normalizeLookupKey(searchText);
    return planningPickerItems.filter(function(item){
      var pool = mode === 'name'
        ? [item.name, item.plate_no, item.label, item.meta]
        : [item.plate_no, item.name, item.label, item.meta];
      if (!needle) return true;
      return pool.some(function(part){ return normalizeLookupKey(part).indexOf(needle) !== -1; });
    });
  }

  function renderPickerList() {
    if (!pickerList || !activePickerContext) return;
    var items = filterPickerItems(activePickerContext.mode, pickerSearch ? pickerSearch.value : '');
    if (!items.length) {
      pickerList.innerHTML = '<div class="planning-picker-empty">No match found</div>';
      return;
    }
    pickerList.innerHTML = items.map(function(item){
      return '' +
        '<button type="button" class="planning-picker-item" data-lookup-key="' + safeText(item.lookup_key || '') + '">' +
          '<strong>' + safeText(activePickerContext.mode === 'name' ? (item.name || '-') : (item.plate_no || '-')) + '</strong>' +
          '<span>' + safeText(activePickerContext.mode === 'name' ? (item.plate_no || '-') : (item.name || '-')) + '</span>' +
          '<small>' + safeText(item.meta || '') + '</small>' +
        '</button>';
    }).join('');
  }

  function applyPickerSelection(item) {
    if (!item || !activePickerContext) return;
    if (activePickerContext.kind === 'add') {
      applyPlateAutofillToAddForm(item);
      closeModal(pickerModal);
      return;
    }
    if (activePickerContext.kind === 'row' && activePickerContext.row) {
      applyPlateAutofillToRow(activePickerContext.row, item);
      closeModal(pickerModal);
    }
  }

  function openPlanningPicker(kind, mode, row) {
    activePickerContext = { kind: kind, mode: mode, row: row || null };
    if (pickerTitle) pickerTitle.textContent = mode === 'name' ? 'Search Job Name' : 'Search Plate No';
    if (pickerSearch) pickerSearch.value = '';
    renderPickerList();
    openModal(pickerModal);
    if (pickerSearch) pickerSearch.focus();
  }

  function bindDispatchDateAutoFill(orderInput, dispatchInput) {
    if (!orderInput || !dispatchInput) return;
    function syncDispatch() {
      var nextDate = addDaysToDate(orderInput.value, 12);
      if (nextDate) dispatchInput.value = nextDate;
    }
    orderInput.oninput = syncDispatch;
    orderInput.onchange = syncDispatch;
  }

  function normalizeRouteDepartmentValue(value) {
    var rawItems = Array.isArray(value)
      ? value.slice()
      : String(value == null ? '' : value).split(/\s*,\s*|\r\n|\r|\n/);
    var wanted = {};

    rawItems.forEach(function(item){
      var text = String(item == null ? '' : item).trim();
      if (!text) return;
      var norm = text.toLowerCase().replace(/[^a-z0-9]+/g, '');
      routeDepartmentChoices.forEach(function(choice){
        var choiceNorm = String(choice).toLowerCase().replace(/[^a-z0-9]+/g, '');
        if (choiceNorm === norm || choiceNorm.indexOf(norm) !== -1 || norm.indexOf(choiceNorm) !== -1) {
          wanted[choice] = true;
        }
      });
    });

    var normalized = routeDepartmentChoices.filter(function(choice){
      return wanted[choice];
    }).join(', ');
    return normalized || defaultRouteDepartments;
  }

  function getRouteDefaultDepartmentsByDie(dieValue) {
    var text = String(dieValue == null ? '' : dieValue).trim().toLowerCase();
    var defaults = ['Jumbo Slitting', 'Printing', 'Die-Cutting', 'Label Slitting', 'Packaging', 'Dispatch'];
    if (text && (text.indexOf('rotary') !== -1 || text.indexOf('rotery') !== -1)) {
      defaults = defaults.filter(function(item){ return item !== 'Die-Cutting'; });
    }
    return normalizeRouteDepartmentValue(defaults);
  }

  function applyDieRulesToDepartmentValue(value, dieValue) {
    var normalized = normalizeRouteDepartmentValue(value);
    var text = String(dieValue == null ? '' : dieValue).trim().toLowerCase();
    if (!text || (text.indexOf('rotary') === -1 && text.indexOf('rotery') === -1)) {
      var selectedKeep = normalized ? normalized.split(/\s*,\s*/) : [];
      var dieDefaultDepartments = getRouteDefaultDepartmentsByDie(dieValue).split(/\s*,\s*/).filter(Boolean);
      if (dieDefaultDepartments.indexOf('Die-Cutting') !== -1 && selectedKeep.indexOf('Die-Cutting') === -1) {
        selectedKeep.push('Die-Cutting');
      }
      return normalizeRouteDepartmentValue(selectedKeep);
    }
    var selected = normalized ? normalized.split(/\s*,\s*/) : [];
    selected = selected.filter(function(item){ return item !== 'Die-Cutting'; });
    return selected.join(', ');
  }

  function setDepartmentInputValue(input, value) {
    if (!input) return;
    var normalized = normalizeRouteDepartmentValue(value);
    var selected = normalized ? normalized.split(/\s*,\s*/) : [];
    input.querySelectorAll('.department-check-input').forEach(function(box){
      box.checked = selected.indexOf(box.value) !== -1;
    });
    input.setAttribute('data-value', normalized);
  }

  function syncDepartmentGroupByDie(group, dieValue) {
    if (!group) return;
    setDepartmentInputValue(group, applyDieRulesToDepartmentValue(getDepartmentInputValue(group), dieValue));
  }

  function getDepartmentInputValue(input) {
    if (!input) return '';
    var selected = Array.prototype.slice.call(input.querySelectorAll('.department-check-input:checked')).map(function(box){
      return box.value;
    });
    var normalized = normalizeRouteDepartmentValue(selected);
    input.setAttribute('data-value', normalized);
    return normalized;
  }

  function getCellInputValue(td) {
    var input = td.querySelector('.cell-input');
    var type = td.getAttribute('data-type');
    if (!input) return '';
    if (type === 'Department') return getDepartmentInputValue(input);
    return input.value || '';
  }

  function resetAddFormDefaults() {
    if (!addForm) return;
    addForm.reset();
    var dieInput = addForm.querySelector('[name="die"]');
    addForm.querySelectorAll('[data-input-type="department"][data-name]').forEach(function(group){
      setDepartmentInputValue(group, getRouteDefaultDepartmentsByDie(dieInput ? dieInput.value : ''));
    });
    setAddFormPlateImageData(null);
  }

  function bindAddFormDepartmentDefaults() {
    if (!addForm) return;
    var dieInput = addForm.querySelector('[name="die"]');
    var departmentGroup = addForm.querySelector('[data-input-type="department"][data-name="department_route"]');
    if (!dieInput || !departmentGroup) return;
    function syncDefaults() {
      setDepartmentInputValue(departmentGroup, getRouteDefaultDepartmentsByDie(dieInput.value));
    }
    dieInput.addEventListener('input', syncDefaults);
    dieInput.addEventListener('change', syncDefaults);
  }

  function bindAddFormAutoFill() {
    if (!addForm) return;
    var plateInput = addForm.querySelector('[name="plate_no"]');
    var jobInput = addForm.querySelector('[name="name"]');
    var materialInput = addForm.querySelector('[name="material"]');
    var orderInput = addForm.querySelector('[name="order_date"]');
    var dispatchInput = addForm.querySelector('[name="dispatch_date"]');

    if (materialInput) {
      materialInput.setAttribute('list', 'planning-material-options');
      materialInput.setAttribute('autocomplete', 'off');
    }
    if (plateInput) {
      plateInput.setAttribute('list', 'planning-plate-options');
      plateInput.setAttribute('autocomplete', 'off');
      function syncPlateData() {
        var data = lookupPlateAutofillData(plateInput.value);
        if (data) {
          applyPlateAutofillToAddForm(data);
        } else {
          setAddFormPlateImageData(null);
        }
      }
      plateInput.addEventListener('input', syncPlateData);
      plateInput.addEventListener('change', syncPlateData);
    }

    if (jobInput) {
      jobInput.setAttribute('list', 'planning-job-options');
      jobInput.setAttribute('autocomplete', 'off');
      function syncJobData() {
        var data = lookupByJobName(jobInput.value);
        if (data) applyPlateAutofillToAddForm(data);
      }
      jobInput.addEventListener('input', syncJobData);
      jobInput.addEventListener('change', syncJobData);
    }

    addForm.querySelectorAll('[data-picker-trigger]').forEach(function(btn){
      btn.addEventListener('click', function(){
        openPlanningPicker('add', btn.getAttribute('data-picker-trigger') || 'plate_no');
      });
    });

    bindDispatchDateAutoFill(orderInput, dispatchInput);
    bindQtyMeterAutoCalc(addForm, function(){
      return {
        qtyPerRoll: getContainerFieldValue(addForm, 'qty_per_roll'),
        ups: getContainerFieldValue(addForm, 'ups'),
        repeat: getContainerFieldValue(addForm, 'repeat')
      };
    });
  }

  function boardHeaders() {
    var headers = ['S.N', 'Job No'];
    columns.forEach(function(col){
      if (col.key === 'sn') return;
      headers.push(col.label || col.key || '');
    });
    return headers;
  }

  function boardRows() {
    return Array.prototype.slice.call(boardTable.querySelectorAll('tbody tr[data-id]')).map(function(tr){
      var row = [];
      var snCell = tr.children[2];
      row.push(snCell ? snCell.textContent.trim() : '');
      var jobNoCell = tr.querySelector('.job-no-cell');
      row.push(jobNoCell ? jobNoCell.textContent.trim() : '');
      Array.prototype.slice.call(tr.querySelectorAll('td[data-key]')).forEach(function(td){
        var raw = td.getAttribute('data-raw');
        var text = raw !== null ? raw : (td.querySelector('.cell-display') ? td.querySelector('.cell-display').textContent.trim() : td.textContent.trim());
        row.push(text === '—' ? 'NA' : text);
      });
      return row;
    });
  }

  function exportPlanningExcel() {
    if (typeof XLSX === 'undefined') {
      toast('Excel export library failed to load. Please refresh and try again.', true);
      return;
    }

    var rows = boardRows();
    if (!rows.length) {
      toast('No planning rows available to export.', true);
      return;
    }

    function departmentLabel(value) {
      return String(value || 'general').replace(/-/g, ' ').replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
    }

    function buildCounts(colType) {
      var index = -1;
      for (var i = 0; i < columns.length; i += 1) {
        if (columns[i].type === colType) {
          index = i + 2;
          break;
        }
      }
      var counts = {};
      if (index === -1) return counts;
      rows.forEach(function (row) {
        var key = String(row[index] || '').trim() || 'Unspecified';
        counts[key] = (counts[key] || 0) + 1;
      });
      return counts;
    }

    function countsToMatrix(title, counts) {
      var keys = Object.keys(counts);
      var matrix = [[title], ['Value', 'Count']];
      if (!keys.length) {
        matrix.push(['No data', 0]);
        return matrix;
      }
      keys.sort(function (a, b) { return a.localeCompare(b); }).forEach(function (key) {
        matrix.push([key, counts[key]]);
      });
      return matrix;
    }

    function computeWidths(matrix) {
      var widths = [];
      matrix.forEach(function (row) {
        row.forEach(function (cell, idx) {
          var len = String(cell == null ? '' : cell).length;
          widths[idx] = Math.max(widths[idx] || 0, Math.min(Math.max(len + 2, 10), 42));
        });
      });
      return widths.map(function (wch) { return { wch: wch }; });
    }

    function encode(row, col) {
      return XLSX.utils.encode_cell({ r: row, c: col });
    }

    function ensureCell(wsRef, row, col) {
      var ref = encode(row, col);
      if (!wsRef[ref]) wsRef[ref] = { t: 's', v: '' };
      return wsRef[ref];
    }

    function mergeStyle(base, extra) {
      var out = {};
      Object.keys(base || {}).forEach(function (key) { out[key] = base[key]; });
      Object.keys(extra || {}).forEach(function (key) { out[key] = extra[key]; });
      return out;
    }

    var palette = {
      navy: '173A63',
      blue: '2563EB',
      sky: 'DBEAFE',
      teal: '0F766E',
      mint: 'DCFCE7',
      green: '16A34A',
      amber: 'F59E0B',
      amberSoft: 'FEF3C7',
      red: 'DC2626',
      redSoft: 'FEE2E2',
      slate: '475569',
      slateSoft: 'E2E8F0',
      white: 'FFFFFF',
      panel: 'F8FAFC',
      lavender: 'EEF2FF',
      border: 'CBD5E1'
    };

    var borderAll = {
      top: { style: 'thin', color: { rgb: palette.border } },
      bottom: { style: 'thin', color: { rgb: palette.border } },
      left: { style: 'thin', color: { rgb: palette.border } },
      right: { style: 'thin', color: { rgb: palette.border } }
    };

    var styles = {
      title: {
        font: { bold: true, sz: 18, color: { rgb: palette.white } },
        fill: { fgColor: { rgb: palette.navy } },
        alignment: { horizontal: 'center', vertical: 'center' },
        border: borderAll
      },
      metaLabel: {
        font: { bold: true, color: { rgb: palette.navy } },
        fill: { fgColor: { rgb: palette.sky } },
        alignment: { horizontal: 'left', vertical: 'center' },
        border: borderAll
      },
      metaValue: {
        font: { bold: true, color: { rgb: '0F172A' } },
        fill: { fgColor: { rgb: palette.white } },
        alignment: { horizontal: 'left', vertical: 'center' },
        border: borderAll
      },
      section: {
        font: { bold: true, sz: 13, color: { rgb: palette.white } },
        fill: { fgColor: { rgb: palette.teal } },
        alignment: { horizontal: 'left', vertical: 'center' },
        border: borderAll
      },
      tableHead: {
        font: { bold: true, color: { rgb: palette.white } },
        fill: { fgColor: { rgb: palette.blue } },
        alignment: { horizontal: 'center', vertical: 'center', wrapText: true },
        border: borderAll
      },
      cell: {
        font: { color: { rgb: '0F172A' } },
        fill: { fgColor: { rgb: palette.white } },
        alignment: { horizontal: 'left', vertical: 'center', wrapText: true },
        border: borderAll
      },
      altCell: {
        font: { color: { rgb: '0F172A' } },
        fill: { fgColor: { rgb: palette.panel } },
        alignment: { horizontal: 'left', vertical: 'center', wrapText: true },
        border: borderAll
      },
      countHead: {
        font: { bold: true, color: { rgb: palette.navy } },
        fill: { fgColor: { rgb: palette.slateSoft } },
        alignment: { horizontal: 'center', vertical: 'center' },
        border: borderAll
      },
      countValue: {
        font: { bold: true, color: { rgb: '0F172A' } },
        fill: { fgColor: { rgb: palette.white } },
        alignment: { horizontal: 'left', vertical: 'center' },
        border: borderAll
      },
      countNumber: {
        font: { bold: true, color: { rgb: palette.navy } },
        fill: { fgColor: { rgb: palette.lavender } },
        alignment: { horizontal: 'center', vertical: 'center' },
        border: borderAll
      }
    };

    function statusFill(value) {
      var v = String(value || '').trim().toLowerCase();
      if (v === 'running') return { fg: 'DBEAFE', font: '1D4ED8' };
      if (v === 'completed' || v === 'printing done' || v === 'slitting completed' || v === 'finished') return { fg: 'DCFCE7', font: '166534' };
      if (v.indexOf('hold') === 0) return { fg: 'FEE2E2', font: 'B91C1C' };
      if (v.indexOf('slitting') !== -1) return { fg: 'FFEDD5', font: 'C2410C' };
      return { fg: 'E2E8F0', font: '334155' };
    }

    function priorityFill(value) {
      var v = String(value || '').trim().toLowerCase();
      if (v === 'urgent') return { fg: 'FEE2E2', font: 'B91C1C' };
      if (v === 'high') return { fg: 'FEF3C7', font: 'B45309' };
      if (v === 'normal') return { fg: 'DBEAFE', font: '1D4ED8' };
      return { fg: 'E2E8F0', font: '334155' };
    }

    function applyRowStyle(wsRef, row, colCount, style, startCol) {
      var begin = typeof startCol === 'number' ? startCol : 0;
      for (var col = begin; col < colCount; col += 1) {
        ensureCell(wsRef, row, col).s = style;
      }
    }

    function styleCountTable(wsRef, startRow, counts, colorResolver) {
      var keys = Object.keys(counts);
      ensureCell(wsRef, startRow, 0).s = styles.section;
      ensureCell(wsRef, startRow + 1, 0).s = styles.countHead;
      ensureCell(wsRef, startRow + 1, 1).s = styles.countHead;
      var rowsLocal = keys.length ? keys : ['No data'];
      rowsLocal.forEach(function (key, idx) {
        var rowIdx = startRow + 2 + idx;
        var isEmpty = !keys.length;
        var tone = colorResolver(isEmpty ? '' : key);
        ensureCell(wsRef, rowIdx, 0).s = mergeStyle(styles.countValue, {
          fill: { fgColor: { rgb: tone.fg || palette.white } },
          font: { bold: true, color: { rgb: tone.font || '0F172A' } }
        });
        ensureCell(wsRef, rowIdx, 1).s = styles.countNumber;
      });
      return startRow + 2 + rowsLocal.length;
    }

    var headers = boardHeaders();
    var reportTitle = 'Planning Board Export';
    var deptLabel = departmentLabel(currentDepartment);
    var printedAt = new Date();
    var statusCounts = buildCounts('Status');
    var priorityCounts = buildCounts('Priority');
    var detailData = [
      [reportTitle],
      ['Department', deptLabel, 'Exported At', printedAt.toLocaleString()],
      ['Total Rows', rows.length, 'View', 'Active Planning Board'],
      [],
      headers
    ].concat(rows);
    var ws = XLSX.utils.aoa_to_sheet(detailData);
    ws['!merges'] = [{ s: { r: 0, c: 0 }, e: { r: 0, c: Math.max(headers.length - 1, 0) } }];
    ws['!cols'] = computeWidths(detailData);
    ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 4, c: 0 }, e: { r: 4 + rows.length, c: headers.length - 1 } }) };
    ws['!rows'] = [{ hpt: 26 }, { hpt: 22 }, { hpt: 22 }, { hpt: 8 }, { hpt: 24 }];

    applyRowStyle(ws, 0, headers.length, styles.title);
    ensureCell(ws, 1, 0).s = styles.metaLabel;
    ensureCell(ws, 1, 1).s = styles.metaValue;
    ensureCell(ws, 1, 2).s = styles.metaLabel;
    ensureCell(ws, 1, 3).s = styles.metaValue;
    ensureCell(ws, 2, 0).s = styles.metaLabel;
    ensureCell(ws, 2, 1).s = styles.metaValue;
    ensureCell(ws, 2, 2).s = styles.metaLabel;
    ensureCell(ws, 2, 3).s = styles.metaValue;
    applyRowStyle(ws, 4, headers.length, styles.tableHead);

    var statusIndex = -1;
    var priorityIndex = -1;
    columns.forEach(function (col, idx) {
      var absoluteIndex = idx + 2;
      if (col.type === 'Status' && statusIndex === -1) statusIndex = absoluteIndex;
      if (col.type === 'Priority' && priorityIndex === -1) priorityIndex = absoluteIndex;
    });

    rows.forEach(function (row, idx) {
      var rowNumber = 5 + idx;
      var baseStyle = idx % 2 === 0 ? styles.cell : styles.altCell;
      applyRowStyle(ws, rowNumber, headers.length, baseStyle);
      ensureCell(ws, rowNumber, 0).s = mergeStyle(baseStyle, { alignment: { horizontal: 'center', vertical: 'center' } });
      if (statusIndex >= 0) {
        var statusTone = statusFill(row[statusIndex]);
        ensureCell(ws, rowNumber, statusIndex).s = mergeStyle(baseStyle, {
          fill: { fgColor: { rgb: statusTone.fg } },
          font: { bold: true, color: { rgb: statusTone.font } },
          alignment: { horizontal: 'center', vertical: 'center' }
        });
      }
      if (priorityIndex >= 0) {
        var priorityTone = priorityFill(row[priorityIndex]);
        ensureCell(ws, rowNumber, priorityIndex).s = mergeStyle(baseStyle, {
          fill: { fgColor: { rgb: priorityTone.fg } },
          font: { bold: true, color: { rgb: priorityTone.font } },
          alignment: { horizontal: 'center', vertical: 'center' }
        });
      }
    });

    var overviewData = [
      ['Planning Export Overview'],
      ['Department', deptLabel],
      ['Exported At', printedAt.toLocaleString()],
      ['Total Rows', rows.length],
      []
    ].concat(countsToMatrix('Status Summary', statusCounts))
      .concat([[]])
      .concat(countsToMatrix('Priority Summary', priorityCounts));
    var overviewWs = XLSX.utils.aoa_to_sheet(overviewData);
    var statusBlock = countsToMatrix('Status Summary', statusCounts);
    var priorityBlock = countsToMatrix('Priority Summary', priorityCounts);
    var priorityTitleRow = 5 + statusBlock.length + 1;
    overviewWs['!merges'] = [
      { s: { r: 0, c: 0 }, e: { r: 0, c: 1 } },
      { s: { r: 5, c: 0 }, e: { r: 5, c: 1 } },
      { s: { r: priorityTitleRow, c: 0 }, e: { r: priorityTitleRow, c: 1 } }
    ];
    overviewWs['!cols'] = computeWidths(overviewData);
    overviewWs['!rows'] = [{ hpt: 26 }];
    ensureCell(overviewWs, 0, 0).s = styles.title;
    ensureCell(overviewWs, 1, 0).s = styles.metaLabel;
    ensureCell(overviewWs, 1, 1).s = styles.metaValue;
    ensureCell(overviewWs, 2, 0).s = styles.metaLabel;
    ensureCell(overviewWs, 2, 1).s = styles.metaValue;
    ensureCell(overviewWs, 3, 0).s = styles.metaLabel;
    ensureCell(overviewWs, 3, 1).s = styles.metaValue;
    styleCountTable(overviewWs, 5, statusCounts, statusFill);
    styleCountTable(overviewWs, priorityTitleRow, priorityCounts, priorityFill);

    var wb = XLSX.utils.book_new();
    wb.Props = {
      Title: reportTitle,
      Subject: 'Planning board export',
      Author: 'Calipot ERP',
      CreatedDate: printedAt
    };
    XLSX.utils.book_append_sheet(wb, overviewWs, 'Overview');
    XLSX.utils.book_append_sheet(wb, ws, 'Planning Board');

    var fileDept = String(currentDepartment || 'planning').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'planning';
    var stamp = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'planning-' + fileDept + '-' + stamp + '.xlsx', { cellStyles: true });
  }

  function printPlanningBoard() {
    var rows = boardRows();
    if (!rows.length) {
      toast('No planning rows available to print.', true);
      return;
    }

    var headers = boardHeaders();
    var title = 'Planning Board - ' + String(currentDepartment || '').replace(/-/g, ' ');
    var printedAt = new Date().toLocaleString();
    var html = '<!doctype html><html><head><meta charset="utf-8">' +
      '<title>' + safeText(title) + '</title>' +
      '<style>' +
      'body{font-family:Arial,sans-serif;margin:24px;color:#0f172a;}' +
      'h1{margin:0 0 6px;font-size:22px;text-transform:capitalize;}' +
      '.meta{margin-bottom:18px;color:#475569;font-size:12px;}' +
      'table{width:100%;border-collapse:collapse;font-size:11px;}' +
      'th,td{border:1px solid #cbd5e1;padding:7px 8px;text-align:left;vertical-align:top;word-break:break-word;}' +
      'th{background:#e2e8f0;font-weight:700;}' +
      'tbody tr:nth-child(even){background:#f8fafc;}' +
      '@media print{body{margin:10mm;} .no-print{display:none;}}' +
      '</style></head><body>' +
      '<h1>' + safeText(title) + '</h1>' +
      '<div class="meta">Printed: ' + safeText(printedAt) + ' | Total Rows: ' + rows.length + '</div>' +
      '<table><thead><tr>' + headers.map(function(h){ return '<th>' + safeText(h) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function(row){
        return '<tr>' + row.map(function(cell){ return '<td>' + safeText(cell || 'NA') + '</td>'; }).join('') + '</tr>';
      }).join('') +
      '</tbody></table>' +
      '<script>window.onload=function(){window.print();};</' + 'script>' +
      '</body></html>';

    var printWin = window.open('', '_blank', 'width=1280,height=900');
    if (!printWin) {
      toast('Popup blocked. Please allow popups and try again.', true);
      return;
    }
    printWin.document.open();
    printWin.document.write(html);
    printWin.document.close();
  }

  function openPlanDetail(tr) {
    var headers = Array.prototype.slice.call(boardTable.querySelectorAll('thead th')).map(function(th){
      return th.textContent.trim();
    });
    var values = [];
    var jobNo = tr.querySelector('.job-no-cell') ? tr.querySelector('.job-no-cell').textContent.trim() : '—';
    values.push({ label: 'Plan No', value: jobNo || '—' });

    Array.prototype.slice.call(tr.querySelectorAll('td[data-key]')).forEach(function(td, idx){
      var value = td.getAttribute('data-raw');
      if (value === null) {
        var disp = td.querySelector('.cell-display');
        value = disp ? disp.textContent.trim() : td.textContent.trim();
      }
      values.push({
        label: (headers[idx + 4] || 'Field').trim(),
        value: value && value !== '—' ? value : 'NA'
      });
    });

    var imageCell = tr.querySelector('.job-image-cell');
    var imageUrl = '';
    if (imageCell) {
      imageUrl = String(imageCell.getAttribute('data-image-url') || '').trim();
      var imageName = imageCell.getAttribute('data-image-name') || '';
      var imageUploadedAt = imageCell.getAttribute('data-image-uploaded-at') || '';
      values.push({ label: 'Job Image', value: imageName !== '' ? imageName : 'Not uploaded' });
      values.push({ label: 'Image Uploaded At', value: imageUploadedAt !== '' ? imageUploadedAt : 'NA' });
    }

    var container = document.getElementById('plan-detail-content');
    var detailHtml = values.map(function(item){
      return '<div class="plan-detail-item">' +
        '<div class="plan-detail-label">' + safeText(item.label) + '</div>' +
        '<div class="plan-detail-value">' + safeText(item.value) + '</div>' +
      '</div>';
    }).join('');

    var imagePreviewHtml = '';
    if (imageUrl !== '') {
      imagePreviewHtml = '' +
        '<div class="plan-detail-item" style="grid-column:1 / -1">' +
          '<div class="plan-detail-label">Job Image Preview</div>' +
          '<div class="plan-detail-value" style="padding-top:8px">' +
            '<img src="' + safeText(imageUrl) + '" alt="Job image preview" style="max-width:100%;max-height:320px;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain;background:#f8fafc">' +
          '</div>' +
        '</div>';
    }

    container.innerHTML = imagePreviewHtml + detailHtml;
    openModal(detailModal);
  }

  var _toastCtr = null;
  function toast(msg, type) {
    var t = (type === true || type === 'error') ? 'error' : (type === 'info' ? 'info' : 'success');
    if (!_toastCtr) { _toastCtr = document.createElement('div'); _toastCtr.id = 'erp-toast-ctr'; document.body.appendChild(_toastCtr); }
    var el = document.createElement('div');
    el.className = 'erp-toast erp-toast-' + t;
    var icon = t === 'error' ? '&#10007;' : '&#10003;';
    el.innerHTML = '<span class="erp-toast-icon">' + icon + '</span><span class="erp-toast-msg">' + String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span><button class="erp-toast-x" type="button">&#215;</button>';
    el.querySelector('.erp-toast-x').onclick = function(){ _dToast(el); };
    _toastCtr.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('show'); });
    setTimeout(function(){ _dToast(el); }, 5000);
  }
  function _dToast(el) {
    el.classList.remove('show');
    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 300);
  }

  function confirmDialog(msg, onConfirm) {
    if (!_toastCtr) { _toastCtr = document.createElement('div'); _toastCtr.id = 'erp-toast-ctr'; document.body.appendChild(_toastCtr); }
    var el = document.createElement('div');
    el.className = 'erp-toast erp-toast-confirm show';
    el.innerHTML =
      '<span class="erp-toast-icon">&#9888;</span>' +
      '<span class="erp-toast-msg">' + String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</span>' +
      '<button class="erp-confirm-yes btn btn-danger btn-sm" type="button">Delete</button>' +
      '<button class="erp-confirm-no btn btn-ghost btn-sm" type="button">Cancel</button>';
    el.querySelector('.erp-confirm-yes').onclick = function(){ _dToast(el); onConfirm(); };
    el.querySelector('.erp-confirm-no').onclick = function(){ _dToast(el); };
    _toastCtr.appendChild(el);
  }

  function applyRowStatus(tr, val) {
    var vl = String(val || '').trim().toLowerCase();
    var norm = vl.replace(/[^a-z]/g, '');
    var allRowCls = ['row-s-running','row-s-completed','row-s-hold','row-s-slitting','row-s-pending','row-s-printingdone'];
    var cls = 'row-s-pending';
    if (norm.indexOf('hold') === 0 || norm.indexOf('pause') !== -1) {
      cls = 'row-s-hold';
    } else if (norm.indexOf('pending') === 0) {
      cls = 'row-s-pending';
    } else if (
      norm === 'running' ||
      norm === 'inprogress' ||
      norm === 'printing' ||
      norm === 'diecutting' ||
      norm === 'barcode' ||
      norm === 'labelslitting' ||
      norm === 'packing' ||
      norm === 'slitting'
    ) {
      cls = (norm === 'slitting') ? 'row-s-slitting' : 'row-s-running';
    } else if (norm.indexOf('preparing') === 0) {
      cls = (norm.indexOf('slitting') !== -1 || norm.indexOf('jumbo') !== -1) ? 'row-s-slitting' : 'row-s-running';
    } else if (
      norm.indexOf('slitted') !== -1 ||
      norm.indexOf('printed') !== -1 ||
      norm.indexOf('diecut') !== -1 ||
      norm.indexOf('barcoded') !== -1 ||
      norm.indexOf('labelslitted') !== -1 ||
      norm.indexOf('packed') !== -1 ||
      norm.indexOf('dispatched') !== -1 ||
      norm === 'complete' ||
      norm === 'completed'
    ) {
      cls = 'row-s-completed';
    }
    tr.classList.remove.apply(tr.classList, allRowCls);
    tr.classList.add(cls);
  }

  function applyStatusStyle(sel) {
    var displayState = sel.style.display;
    sel.className = 'cell-input cell-select-status form-control';
    sel.style.cssText = statusStyleFromText(sel.value || defaultPlanningStatus);
    if (displayState !== '') sel.style.display = displayState;
    var tr = sel.closest('tr[data-id]');
    if (tr) applyRowStatus(tr, sel.value);
  }

  function statusClassFromText(txt) {
    var s = String(txt || '').trim().toLowerCase();
    var n = s.replace(/[^a-z]/g, '');
    if (n.indexOf('hold') === 0 || n.indexOf('pause') !== -1) return 'hold';
    if (n.indexOf('pending') === 0) return 'pending';
    if (n.indexOf('preparing') === 0) {
      return (n.indexOf('slitting') !== -1 || n.indexOf('jumbo') !== -1) ? 'slitting' : 'running';
    }
    if (
      n.indexOf('slitted') !== -1 ||
      n.indexOf('printed') !== -1 ||
      n.indexOf('diecut') !== -1 ||
      n.indexOf('barcoded') !== -1 ||
      n.indexOf('labelslitted') !== -1 ||
      n.indexOf('packed') !== -1 ||
      n.indexOf('dispatched') !== -1 ||
      n === 'complete' ||
      n === 'completed'
    ) return 'completed';
    if (
      n === 'running' ||
      n === 'inprogress' ||
      n === 'printing' ||
      n === 'diecutting' ||
      n === 'barcode' ||
      n === 'labelslitting' ||
      n === 'packing'
    ) return 'running';
    if (n === 'slitting') return 'slitting';
    return 'pending';
  }

  function statusStyleFromText(txt) {
    var key = String(txt || defaultPlanningStatus).trim().toLowerCase();
    return planningStatusStyleMap[key] || planningStatusStyleMap[defaultPlanningStatusKey] || '';
  }

  function applyPriorityStyle(sel) {
    var v = String(sel.value || 'Normal').trim().toLowerCase();
    sel.className = 'cell-input cell-select-priority form-control psel-' + v;
  }

  function normalizeRenderedStatuses() {
    boardTable.querySelectorAll('tr[data-id]').forEach(function(tr){
      var statusCell = tr.querySelector('td[data-type="Status"] .cell-display.status-pill');
      if (!statusCell) return;
      statusCell.className = 'cell-display status-pill';
      statusCell.style.cssText = statusStyleFromText(statusCell.textContent || defaultPlanningStatus);
      var logicalStatus = String(statusCell.textContent || '').trim();
      applyRowStatus(tr, logicalStatus);
    });
  }

  function postAction(payload) {
    var form = new FormData();
    Object.keys(payload).forEach(function(k){ form.append(k, payload[k]); });
    form.append('csrf_token', csrfToken);
    return fetch(window.location.href, { method: 'POST', body: form }).then(function(r){
      return r.text().then(function(t){
        var data = null;
        try { data = JSON.parse(t); } catch (e) {
          throw new Error('Server returned non-JSON response. ' + String(t || '').slice(0, 180));
        }
        if (!r.ok || !data || data.ok === false) {
          throw new Error((data && data.message) ? data.message : 'Request failed');
        }
        return data;
      });
    });
  }

  function postActionForm(form) {
    form.append('csrf_token', csrfToken);
    return fetch(window.location.href, { method: 'POST', body: form }).then(function(r){
      return r.text().then(function(t){
        var data = null;
        try { data = JSON.parse(t); } catch (e) {
          throw new Error('Server returned non-JSON response. ' + String(t || '').slice(0, 180));
        }
        if (!r.ok || !data || data.ok === false) {
          throw new Error((data && data.message) ? data.message : 'Request failed');
        }
        return data;
      });
    });
  }

  var planningRouteMap = {
    'label-printing': <?= json_encode(appUrl('/modules/planning/label/index.php')) ?>,
    'jumbo-slitting': <?= json_encode(appUrl('/modules/planning/slitting/index.php')) ?>,
    'printing': <?= json_encode(appUrl('/modules/planning/printing/index.php')) ?>,
    'die-cutting': <?= json_encode(appUrl('/modules/planning/flatbed/index.php')) ?>,
    'barcode': <?= json_encode(appUrl('/modules/planning/barcode/index.php')) ?>,
    'label-slitting': <?= json_encode(appUrl('/modules/planning/label-slitting/index.php')) ?>,
    'batch-printing': <?= json_encode(appUrl('/modules/planning/batch/index.php')) ?>,
    'packaging': <?= json_encode(appUrl('/modules/planning/packing/index.php')) ?>,
    'dispatch': <?= json_encode(appUrl('/modules/planning/dispatch/index.php')) ?>
  };

  deptSwitch.addEventListener('change', function(){
    var selected = String(deptSwitch.value || '').trim();
    if (planningRouteMap[selected]) {
      window.location.href = planningRouteMap[selected];
      return;
    }
    var url = new URL(window.location.href);
    url.searchParams.set('department', selected);
    window.location.href = url.toString();
  });

  function setRowFieldInputValue(tr, key, value) {
    var td = tr.querySelector('td[data-key="' + key + '"]');
    if (!td) return;
    var input = td.querySelector('.cell-input');
    if (!input) return;
    if (td.getAttribute('data-type') === 'Department') {
      setDepartmentInputValue(input, value);
      return;
    }
    input.value = String(value == null ? '' : value);
  }

  function applyPlateAutofillToRow(tr, data) {
    if (!tr || !data) return;
    ['plate_no', 'name', 'size', 'repeat', 'paper_size', 'material', 'die', 'core_size', 'qty_per_roll', 'roll_direction'].forEach(function(field){
      if (Object.prototype.hasOwnProperty.call(data, field) && data[field] != null && data[field] !== '') {
        setRowFieldInputValue(tr, field, data[field]);
      }
    });
    tr.setAttribute('data-plate-ups', String(data.ups || '').trim());
    if (data.image_path) {
      setRowImageData(tr, {
        image_path: data.image_path || '',
        image_url: data.image_url || '',
        image_name: data.image_name || '',
        image_uploaded_at: data.image_uploaded_at || ''
      });
    }
    var rowDieInput = tr.querySelector('td[data-key="die"] .cell-input');
    var rowDepartmentGroup = tr.querySelector('td[data-key="department_route"] [data-input-type="department"]');
    if (rowDieInput && rowDepartmentGroup) {
      setDepartmentInputValue(rowDepartmentGroup, getRouteDefaultDepartmentsByDie(rowDieInput.value));
    }
    syncCalcFieldsForContainer(tr, {
      qtyPerRoll: getContainerFieldValue(tr, 'qty_per_roll'),
      ups: tr.getAttribute('data-plate-ups') || '',
      repeat: getContainerFieldValue(tr, 'repeat')
    });
  }

  function setRowEditMode(tr, on) {
    tr.classList.toggle('is-editing', on);
    tr.querySelector('.btn-edit').style.display = on ? 'none' : '';
    tr.querySelector('.btn-delete').style.display = on ? 'none' : '';
    tr.querySelector('.btn-save').style.display = on ? '' : 'none';
    tr.querySelector('.btn-cancel').style.display = on ? '' : 'none';

    var rowDieInput = tr.querySelector('td[data-key="die"] .cell-input');
    var rowDepartmentGroup = tr.querySelector('td[data-key="department_route"] [data-input-type="department"]');

    tr.querySelectorAll('td[data-key]').forEach(function(td){
      var key = td.getAttribute('data-key');
      var disp = td.querySelector('.cell-display');
      var input = td.querySelector('.cell-input');
      if (key === 'sn') return;
      disp.style.display = on ? 'none' : '';
      input.style.display = on ? '' : 'none';
      td.querySelectorAll('.cell-input-action').forEach(function(actionBtn){
        actionBtn.style.display = on ? '' : 'none';
      });

      var type = td.getAttribute('data-type');
      if (type === 'Department') {
        if (on) {
          var deptCurrent = td.getAttribute('data-raw') || disp.textContent.trim();
          if (deptCurrent === '—') deptCurrent = '';
          setDepartmentInputValue(input, deptCurrent);
        }
        return;
      }

      if (input.tagName !== 'SELECT') {
        if (type === 'Date') input.type = 'date';
        else if (type === 'Number') input.type = 'number';
        else input.type = 'text';
      }

      if (on) {
        var current = td.getAttribute('data-raw') || disp.textContent.trim();
        if (current === '—') current = '';
        input.value = current;
        if (type === 'Status' && input.tagName === 'SELECT') {
          applyStatusStyle(input);
          input.onchange = function(){ applyStatusStyle(this); };
        }
        if (type === 'Priority' && input.tagName === 'SELECT') {
          applyPriorityStyle(input);
          input.onchange = function(){ applyPriorityStyle(this); };
        }
        if (key === 'die' && rowDepartmentGroup) {
          input.onchange = function(){ syncDepartmentGroupByDie(rowDepartmentGroup, this.value); };
          input.oninput = function(){ syncDepartmentGroupByDie(rowDepartmentGroup, this.value); };
        }
        if (key === 'plate_no') {
          input.setAttribute('list', 'planning-plate-options');
          input.setAttribute('autocomplete', 'off');
          var plateBtn = td.querySelector('[data-picker-trigger="plate_no"]');
          if (plateBtn) {
            plateBtn.onclick = function(){ openPlanningPicker('row', 'plate_no', tr); };
          }
          input.onchange = function(){
            var data = lookupPlateAutofillData(this.value);
            if (data) applyPlateAutofillToRow(tr, data);
          };
          input.oninput = function(){
            var data = lookupPlateAutofillData(this.value);
            if (data) applyPlateAutofillToRow(tr, data);
          };
        }
        if (key === 'name') {
          input.setAttribute('list', 'planning-job-options');
          input.setAttribute('autocomplete', 'off');
          var jobBtn = td.querySelector('[data-picker-trigger="name"]');
          if (jobBtn) {
            jobBtn.onclick = function(){ openPlanningPicker('row', 'name', tr); };
          }
          input.onchange = function(){
            var data = lookupByJobName(this.value);
            if (data) applyPlateAutofillToRow(tr, data);
          };
          input.oninput = function(){
            var data = lookupByJobName(this.value);
            if (data) applyPlateAutofillToRow(tr, data);
          };
        }
        if (key === 'material') {
          input.setAttribute('list', 'planning-material-options');
          input.setAttribute('autocomplete', 'off');
        }
        if (key === 'order_date') {
          bindDispatchDateAutoFill(input, tr.querySelector('td[data-key="dispatch_date"] .cell-input'));
        }
      }
    });

    if (on && rowDieInput && rowDepartmentGroup) {
      syncDepartmentGroupByDie(rowDepartmentGroup, rowDieInput.value);
    }
    if (on) {
      bindQtyMeterAutoCalc(tr, function(){
        return {
          qtyPerRoll: getContainerFieldValue(tr, 'qty_per_roll'),
          ups: tr.getAttribute('data-plate-ups') || '',
          repeat: getContainerFieldValue(tr, 'repeat')
        };
      });
    }
  }

  function updateImageZoom(scale) {
    imageViewerScale = Math.max(0.25, Math.min(5, scale));
    if (imageViewerImg) {
      imageViewerImg.style.transform = 'scale(' + imageViewerScale + ')';
      imageViewerImg.style.transformOrigin = 'center center';
    }
    if (imageZoomResetBtn) {
      imageZoomResetBtn.textContent = Math.round(imageViewerScale * 100) + '%';
    }
  }

  function setRowImageData(tr, meta) {
    var cell = tr.querySelector('.job-image-cell');
    if (!cell) return;

    var imagePath = String(meta.image_path || '').trim();
    var imageUrl = String(meta.image_url || '').trim();
    var imageName = String(meta.image_name || '').trim();
    var imageUploadedAt = String(meta.image_uploaded_at || '').trim();

    cell.setAttribute('data-image-path', imagePath);
    cell.setAttribute('data-image-url', imageUrl);
    cell.setAttribute('data-image-name', imageName);
    cell.setAttribute('data-image-uploaded-at', imageUploadedAt);

    var box = cell.querySelector('.job-image-box');
    if (!box) return;

    if (imageUrl !== '') {
      box.classList.remove('no-image');
      box.classList.add('has-image');
      box.innerHTML = '' +
        '<button type="button" class="job-image-thumb-btn" title="Open image">' +
          '<img src="' + safeText(imageUrl) + '" alt="Job image" class="job-image-thumb">' +
        '</button>';
      return;
    }

    box.classList.remove('has-image');
    box.classList.add('no-image');
    box.innerHTML = '' +
      '<button type="button" class="job-image-empty-btn" title="Add image"><i class="bi bi-image"></i></button>';
  }

  function openImageViewerForRow(tr) {
    var cell = tr.querySelector('.job-image-cell');
    if (!cell) return;
    var imageUrl = String(cell.getAttribute('data-image-url') || '').trim();
    var imageName = String(cell.getAttribute('data-image-name') || '').trim();
    var imageUploadedAt = String(cell.getAttribute('data-image-uploaded-at') || '').trim();
    var jobNo = tr.querySelector('.job-no-cell strong') ? tr.querySelector('.job-no-cell strong').textContent.trim() : '';

    imageViewerRowId = tr.getAttribute('data-id') || '';
    if (imageUrl !== '') {
      imageViewerImg.src = imageUrl;
      imageViewerImg.style.display = '';
      imageViewerStage.classList.remove('is-empty');
      imageViewerStage.setAttribute('data-empty', '');
      imageDeleteBtn.disabled = false;
    } else {
      imageViewerImg.removeAttribute('src');
      imageViewerImg.style.display = 'none';
      imageViewerStage.classList.add('is-empty');
      imageViewerStage.setAttribute('data-empty', 'No image uploaded. Click Upload or Edit to add one.');
      imageDeleteBtn.disabled = true;
    }

    imageViewerMeta.innerHTML = '<strong>' + safeText(jobNo || 'Planning Job') + '</strong>' +
      (imageName !== '' ? '<span>' + safeText(imageName) + '</span>' : '') +
      (imageUploadedAt !== '' ? '<small>Uploaded: ' + safeText(imageUploadedAt) + '</small>' : '');
    updateImageZoom(1);
    openModal(imageViewerModal);
  }

  function uploadImageForRow(tr, file) {
    var rowId = tr.getAttribute('data-id');
    if (!rowId || !file) return;
    var form = new FormData();
    form.append('action', 'upload_job_image');
    form.append('id', rowId);
    form.append('job_image', file);
    postActionForm(form).then(function(res){
      setRowImageData(tr, res || {});
      toast('Job image uploaded');
      if (imageViewerModal.style.display === 'flex') {
        openImageViewerForRow(tr);
      }
    }).catch(function(err){
      toast(err.message || 'Image upload failed', true);
    });
  }

  function removeImageForRow(tr) {
    var rowId = tr.getAttribute('data-id');
    if (!rowId) return;
    confirmDialog('Delete this planning job image?', function(){
      postAction({ action: 'delete_job_image', id: rowId }).then(function(){
        setRowImageData(tr, {});
        if (imageViewerModal && imageViewerModal.style.display === 'flex') {
          closeModal(imageViewerModal);
        }
        toast('Job image removed');
      }).catch(function(err){
        toast(err.message || 'Unable to delete image', true);
      });
    });
  }

  boardTable.addEventListener('click', function(e){
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;

    if (e.target.closest('.job-image-thumb-btn,.job-image-empty-btn')) {
      openImageViewerForRow(tr);
      return;
    }

    if (e.target.closest('.job-no-cell')) {
      openPlanDetail(tr);
      return;
    }

    if (e.target.closest('.btn-edit')) {
      setRowEditMode(tr, true);
      return;
    }

    if (e.target.closest('.btn-cancel')) {
      setRowEditMode(tr, false);
      return;
    }

    if (e.target.closest('.btn-delete')) {
      var rowId = tr.getAttribute('data-id');
      var rowTr = tr;
      confirmDialog('Delete this planning row?', function(){
        postAction({ action: 'delete_row', id: rowId }).then(function(){
          rowTr.remove();
          toast('Row deleted');
        }).catch(function(err){ toast(err.message || 'Delete failed', true); });
      });
      return;
    }

    if (e.target.closest('.btn-save')) {
      var payload = { action: 'save_row', id: tr.getAttribute('data-id') };
      var rowValues = {};
      tr.querySelectorAll('td[data-key]').forEach(function(td){
        var key = td.getAttribute('data-key');
        if (key === 'sn') return;
        rowValues[key] = getCellInputValue(td);
        payload[key] = rowValues[key];
      });
      rowValues.ups = String(tr.getAttribute('data-plate-ups') || '').trim();
      payload.ups = rowValues.ups;
      var imageCell = tr.querySelector('.job-image-cell');
      if (imageCell) {
        rowValues.image_path = String(imageCell.getAttribute('data-image-path') || '').trim();
        rowValues.image_name = String(imageCell.getAttribute('data-image-name') || '').trim();
        rowValues.image_uploaded_at = String(imageCell.getAttribute('data-image-uploaded-at') || '').trim();
        payload.image_path = rowValues.image_path;
        payload.image_name = rowValues.image_name;
        payload.image_uploaded_at = rowValues.image_uploaded_at;
      }
      payload.row_values_json = JSON.stringify(rowValues);
      postAction(payload).then(function(){
        tr.querySelectorAll('td[data-key]').forEach(function(td){
          var key = td.getAttribute('data-key');
          var type = td.getAttribute('data-type');
          var disp = td.querySelector('.cell-display');
          if (key === 'sn') return;

          var v = getCellInputValue(td);
          if (type === 'Status') {
            disp.className = 'cell-display status-pill';
            disp.style.cssText = statusStyleFromText(v || defaultPlanningStatus);
            disp.textContent = v || defaultPlanningStatus;
            applyRowStatus(tr, v);
          } else if (type === 'Priority') {
            var pCls = String(v || 'Normal').trim().toLowerCase();
            disp.className = 'cell-display priority-pill priority-pill-' + pCls;
            disp.textContent = v || 'Normal';
          } else if (type === 'Department') {
            v = normalizeRouteDepartmentValue(v);
            disp.textContent = v || '—';
          } else {
            disp.textContent = v || '—';
          }
          td.setAttribute('data-raw', v);
        });

        tr.setAttribute('data-plate-ups', String(rowValues.ups || '').trim());

        setRowEditMode(tr, false);
        toast('Plan saved');
      }).catch(function(err){ toast(err.message || 'Save failed', true); });
      return;
    }
  });

  boardTable.addEventListener('dblclick', function(e){
    if (!CAN_EDIT) return;
    if (e.target.closest('.btn-edit,.btn-save,.btn-cancel,.btn-delete,.job-image-thumb-btn,.job-image-empty-btn,.seq-drag-handle')) return;
    var tr = e.target.closest('tr[data-id]');
    if (!tr || tr.classList.contains('is-editing')) return;
    setRowEditMode(tr, true);
  });

  // Final UI guard: ensure persisted/legacy statuses render with the correct color after refresh.
  normalizeRenderedStatuses();

  function openModal(el) { el.style.display = 'flex'; }
  function closeModal(el) {
    el.style.display = 'none';
    if (el === addModal) {
      resetAddFormDefaults();
    }
    if (el === pickerModal) {
      activePickerContext = null;
      if (pickerSearch) pickerSearch.value = '';
      if (pickerList) pickerList.innerHTML = '';
    }
    if (el === importMapModal) {
      pendingImportRows = [];
      pendingImportHeaders = [];
      importMapList.innerHTML = '';
      fileInput.value = '';
    }
    if (el === imageViewerModal) {
      imageViewerRowId = '';
      imageViewerImg.removeAttribute('src');
      imageViewerImg.style.display = '';
      imageViewerStage.classList.remove('is-empty');
      imageViewerStage.setAttribute('data-empty', '');
      imageDeleteBtn.disabled = false;
      imageViewerMeta.textContent = '';
      updateImageZoom(1);
    }
  }

  imageZoomInBtn.addEventListener('click', function(){ updateImageZoom(imageViewerScale + 0.25); });
  imageZoomOutBtn.addEventListener('click', function(){ updateImageZoom(imageViewerScale - 0.25); });
  imageZoomResetBtn.addEventListener('click', function(){ updateImageZoom(1); });
  imageUploadBtn.addEventListener('click', function(){
    if (!imageViewerRowId) return;
    var row = boardTable.querySelector('tr[data-id="' + imageViewerRowId + '"]');
    if (!row) return;
    imageUploadInput.value = '';
    imageUploadInput.onchange = function(){
      var file = imageUploadInput.files && imageUploadInput.files[0];
      if (!file) return;
      uploadImageForRow(row, file);
    };
    imageUploadInput.click();
  });
  imageEditBtn.addEventListener('click', function(){
    if (!imageViewerRowId) return;
    var row = boardTable.querySelector('tr[data-id="' + imageViewerRowId + '"]');
    if (!row) return;
    imageUploadInput.value = '';
    imageUploadInput.onchange = function(){
      var file = imageUploadInput.files && imageUploadInput.files[0];
      if (!file) return;
      uploadImageForRow(row, file);
    };
    imageUploadInput.click();
  });
  imageDeleteBtn.addEventListener('click', function(){
    if (!imageViewerRowId) return;
    var row = boardTable.querySelector('tr[data-id="' + imageViewerRowId + '"]');
    if (!row) return;
    removeImageForRow(row);
  });

  document.getElementById('btn-add-row').addEventListener('click', function(){
    resetAddFormDefaults();
    openModal(addModal);
  });
  if (pickerSearch) {
    pickerSearch.addEventListener('input', function(){
      renderPickerList();
    });
    pickerSearch.addEventListener('keydown', function(e){
      if (e.key === 'Escape') {
        closeModal(pickerModal);
      }
    });
  }
  if (pickerList) {
    pickerList.addEventListener('click', function(e){
      var btn = e.target.closest('.planning-picker-item');
      if (!btn) return;
      var lookupKey = normalizeLookupKey(btn.getAttribute('data-lookup-key') || '');
      if (!lookupKey) return;
      for (var i = 0; i < planningPickerItems.length; i += 1) {
        if (normalizeLookupKey(planningPickerItems[i].lookup_key || '') === lookupKey) {
          applyPickerSelection(planningPickerItems[i]);
          break;
        }
      }
    });
  }
  btnExportExcel.addEventListener('click', exportPlanningExcel);
  btnPrintPdf.addEventListener('click', printPlanningBoard);
  document.getElementById('btn-board-config').addEventListener('click', function(){
    renderConfigList();
    openModal(cfgModal);
  });

  document.querySelectorAll('.modal-close').forEach(function(btn){
    btn.addEventListener('click', function(){
      closeModal(btn.closest('.planning-modal'));
    });
  });

  [addModal, cfgModal, importMapModal, detailModal, imageViewerModal, pickerModal].forEach(function(m){
    m.addEventListener('click', function(e){
      if (e.target === m && m !== addModal) closeModal(m);
    });
  });

  bindAddFormDepartmentDefaults();
  bindAddFormAutoFill();

  addForm.addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(e.target);
    var payload = { action: 'create_row' };
    var rowValues = {};
    var jobImage = fd.get('job_image');
    fd.forEach(function(v, k){
      if (k === 'job_image') return;
      payload[k] = v;
      rowValues[k] = v;
    });
    e.target.querySelectorAll('[data-input-type="department"][data-name]').forEach(function(group){
      var fieldName = group.getAttribute('data-name');
      var fieldValue = getDepartmentInputValue(group);
      payload[fieldName] = fieldValue;
      rowValues[fieldName] = fieldValue;
    });
    payload.row_values_json = JSON.stringify(rowValues);

    postAction(payload).then(function(res){
      var createdId = parseInt(res && res.id ? res.id : '0', 10);
      if (jobImage && typeof jobImage === 'object' && jobImage.size > 0 && createdId > 0) {
        var upForm = new FormData();
        upForm.append('action', 'upload_job_image');
        upForm.append('id', String(createdId));
        upForm.append('job_image', jobImage);
        return postActionForm(upForm).then(function(){
          toast('New job added with image');
          window.location.reload();
        });
      }
      toast('New job added');
      window.location.reload();
    }).catch(function(err){ toast(err.message || 'Create failed', true); });
  });

  document.getElementById('btn-import').addEventListener('click', function(){ fileInput.click(); });

  function normKey(v) {
    return String(v == null ? '' : v).replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function importTargetOptions() {
    var built = {};
    var out = [];

    columns.forEach(function(col){
      if (!col || !col.key || col.key === 'sn') return;
      var key = String(col.key);
      if (built[key]) return;
      built[key] = true;
      out.push({ key: key, label: String(col.label || key) });
    });

    [
      { key: 'name', label: 'Job Name' },
      { key: 'dispatch_date', label: 'Dispatch Date' },
      { key: 'order_date', label: 'Order Date' },
      { key: 'remarks', label: 'Remarks' },
      { key: 'department_route', label: 'Department' },
      { key: 'priority', label: 'Priority' },
      { key: 'machine', label: 'Machine' },
      { key: 'operator_name', label: 'Operator Name' }
    ].forEach(function(t){
      if (!built[t.key]) {
        built[t.key] = true;
        out.push(t);
      }
    });

    return out;
  }

  function suggestImportTarget(header, targets) {
    var n = normKey(header).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    var nSimple = n.replace(/_/g, '');
    var map = {
      name: ['name', 'jobname', 'job_name'],
      dispatch_date: ['dispatchdate', 'dispatch_date', 'dateofdispatch'],
      order_date: ['orderdate', 'order_date'],
      printing_planning: ['printingplanning', 'printing_planing', 'planning', 'status', 'planing'],
      plate_no: ['plateno', 'plate_no', 'plate'],
      size: ['size', 'labelsize', 'label_size'],
      repeat: ['repeat', 'repeatmm', 'repeat_mm'],
      material: ['material'],
      paper_size: ['papersize', 'paper_size'],
      department_route: ['department', 'departments', 'departmentroute', 'department_route'],
      die: ['die'],
      allocate_mtrs: ['allocatemtrs', 'allocate_mtrs', 'mtrs', 'allocatemeters'],
      qty_pcs: ['qtypcs', 'qty_pcs', 'qty', 'quantity'],
      core_size: ['coresize', 'core_size', 'core'],
      qty_per_roll: ['qtyperroll', 'qty_per_roll', 'qtyroll'],
      roll_direction: ['rolldirection', 'roll_direction', 'direction'],
      remarks: ['remarks', 'notes'],
      machine: ['machine'],
      operator_name: ['operatorname', 'operator_name', 'operator'],
      priority: ['priority']
    };

    for (var key in map) {
      if (!Object.prototype.hasOwnProperty.call(map, key)) continue;
      if (map[key].indexOf(nSimple) !== -1 || map[key].indexOf(n) !== -1) return key;
    }

    var byKey = targets.find(function(t){ return normKey(t.key).replace(/[^a-z0-9]+/g, '') === nSimple; });
    if (byKey) return byKey.key;
    var byLabel = targets.find(function(t){ return normKey(t.label).replace(/[^a-z0-9]+/g, '') === nSimple; });
    if (byLabel) return byLabel.key;
    return '';
  }

  function openImportMapping(headers, rows) {
    var targets = importTargetOptions();
    pendingImportHeaders = headers.slice();
    pendingImportRows = rows.slice();
    importMapList.innerHTML = '';

    headers.forEach(function(h){
      var row = document.createElement('div');
      row.className = 'import-map-row';

      var source = document.createElement('div');
      source.className = 'import-map-src';
      source.textContent = String(h || '(blank header)');

      var select = document.createElement('select');
      select.className = 'form-control import-map-select';
      select.setAttribute('data-source-header', String(h || ''));
      select.innerHTML = '<option value="">Ignore this column</option>';
      targets.forEach(function(t){
        select.innerHTML += '<option value="' + safeText(t.key) + '">' + safeText(t.label) + ' (' + safeText(t.key) + ')</option>';
      });

      var guess = suggestImportTarget(h, targets);
      if (guess) select.value = guess;

      row.appendChild(source);
      row.appendChild(select);
      importMapList.appendChild(row);
    });

    openModal(importMapModal);
  }

  function runMappedImport() {
    var map = {};
    importMapList.querySelectorAll('select[data-source-header]').forEach(function(sel){
      var src = String(sel.getAttribute('data-source-header') || '');
      var dst = String(sel.value || '');
      if (src !== '' && dst !== '') map[src] = dst;
    });

    var normalizedRows = pendingImportRows.map(function(raw){
      var out = {};
      Object.keys(raw || {}).forEach(function(srcKey){
        var dstKey = map[srcKey] || '';
        if (!dstKey) return;
        out[dstKey] = raw[srcKey];
      });
      return out;
    }).filter(function(r){
      return Object.keys(r).length > 0;
    });

    if (!normalizedRows.length) {
      toast('No mapped columns selected for import.', true);
      return;
    }

    var hasJobName = normalizedRows.some(function(r){
      return String(r.name == null ? '' : r.name).trim() !== '';
    });
    if (!hasJobName) {
      toast('Map at least one Excel column to Job Name (name).', true);
      return;
    }

    postAction({ action: 'import_rows', rows_json: JSON.stringify(normalizedRows) }).then(function(res){
      closeModal(importMapModal);
      toast('Import successful: ' + (res.count || 0) + ' rows');
      window.location.reload();
    }).catch(function(err){
      toast(err.message || 'Import failed', true);
    });
  }

  fileInput.addEventListener('change', function(e){
    var file = e.target.files && e.target.files[0];
    if (!file) return;
    if (typeof XLSX === 'undefined') {
      toast('Excel reader failed to load. Please refresh and try again.', true);
      return;
    }

    var reader = new FileReader();
    reader.onload = function(evt){
      try {
        var wb = XLSX.read(evt.target.result, { type: 'array' });
        var ws = wb.Sheets[wb.SheetNames[0]];
        var matrix = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
        if (!Array.isArray(matrix) || matrix.length < 2) {
          toast('No data rows found in file', true);
          return;
        }

        var headers = (matrix[0] || []).map(function(h, idx){
          var v = String(h == null ? '' : h).trim();
          return v !== '' ? v : ('Column ' + (idx + 1));
        });

        var rows = matrix.slice(1).map(function(cells){
          var rowObj = {};
          headers.forEach(function(h, idx){
            rowObj[h] = (cells && typeof cells[idx] !== 'undefined') ? cells[idx] : '';
          });
          return rowObj;
        }).filter(function(rowObj){
          return Object.keys(rowObj).some(function(k){
            return String(rowObj[k] == null ? '' : rowObj[k]).trim() !== '';
          });
        });

        if (!rows.length) {
          toast('No data rows found in file', true);
          return;
        }

        openImportMapping(headers, rows);
      } catch (err) {
        toast('Unable to read Excel file: ' + (err && err.message ? err.message : 'unknown error'), true);
      } finally {
        if (importMapModal.style.display !== 'flex') {
          fileInput.value = '';
        }
      }
    };
    reader.readAsArrayBuffer(file);
  });

  btnImportMapApply.addEventListener('click', runMappedImport);
  btnImportMapCancel.addEventListener('click', function(){ closeModal(importMapModal); });

  function renderConfigList() {
    var list = document.getElementById('config-list');
    list.innerHTML = '';
    columns.forEach(function(col, idx){
      var row = document.createElement('div');
      row.className = 'config-row';
      row.setAttribute('draggable', 'true');
      row.dataset.index = String(idx);

      row.innerHTML = '' +
        '<div class="config-drag"><i class="bi bi-grip-horizontal"></i></div>' +
        '<input class="form-control config-label" value="' + String(col.label || '').replace(/"/g, '&quot;') + '">' +
        '<select class="form-control config-type">' +
          '<option value="Text">Text</option>' +
          '<option value="Number">Number</option>' +
          '<option value="Date">Date</option>' +
          '<option value="Status">Status</option>' +
          '<option value="Priority">Priority</option>' +
          '<option value="Department">Department</option>' +
        '</select>';

      row.querySelector('.config-type').value = col.type || 'Text';
      list.appendChild(row);
    });

    var dragIdx = -1;
    list.querySelectorAll('.config-row').forEach(function(row){
      row.addEventListener('dragstart', function(){ dragIdx = parseInt(row.dataset.index || '-1', 10); });
      row.addEventListener('dragover', function(e){ e.preventDefault(); });
      row.addEventListener('drop', function(){
        var overIdx = parseInt(row.dataset.index || '-1', 10);
        if (dragIdx < 0 || overIdx < 0 || dragIdx === overIdx) return;
        var moved = columns.splice(dragIdx, 1)[0];
        columns.splice(overIdx, 0, moved);
        renderConfigList();
      });
    });
  }

  document.getElementById('btn-save-config').addEventListener('click', function(){
    var rows = Array.prototype.slice.call(document.querySelectorAll('#config-list .config-row'));
    rows.forEach(function(r, idx){
      var label = r.querySelector('.config-label').value.trim();
      var type = r.querySelector('.config-type').value;
      if (columns[idx]) {
        columns[idx].label = label || columns[idx].label;
        columns[idx].type = type || columns[idx].type;
      }
    });

    postAction({ action: 'save_columns', columns_json: JSON.stringify(columns) }).then(function(){
      toast('Board headers updated');
      window.location.reload();
    }).catch(function(err){ toast(err.message || 'Save failed', true); });
  });

  // ── Column-header drag-to-reorder ──────────────────────────
  var colDragFromIdx = -1;
  function initColDrag() {
    var colThs = Array.prototype.slice.call(boardTable.querySelectorAll('thead tr th[data-col-key]'));
    colThs.forEach(function(th) {
      th.ondragstart = function(e) {
        var cur = Array.prototype.slice.call(boardTable.querySelectorAll('thead tr th[data-col-key]'));
        colDragFromIdx = cur.indexOf(th);
        e.dataTransfer.effectAllowed = 'move';
        th.classList.add('col-dragging');
      };
      th.ondragend = function() { th.classList.remove('col-dragging'); colThs.forEach(function(x){ x.classList.remove('col-drag-over'); }); };
      th.ondragover = function(e) { e.preventDefault(); th.classList.add('col-drag-over'); };
      th.ondragleave = function() { th.classList.remove('col-drag-over'); };
      th.ondrop = function(e) {
        e.preventDefault();
        th.classList.remove('col-drag-over');
        var cur = Array.prototype.slice.call(boardTable.querySelectorAll('thead tr th[data-col-key]'));
        var dropIdx = cur.indexOf(th);
        if (colDragFromIdx < 0 || colDragFromIdx === dropIdx) return;
        // Reorder columns array
        var moved = columns.splice(colDragFromIdx, 1)[0];
        columns.splice(dropIdx, 0, moved);
        // Move th in DOM
        var theadTr = boardTable.querySelector('thead tr');
        var allThs = Array.prototype.slice.call(theadTr.querySelectorAll('th[data-col-key]'));
        var fromTh = allThs[colDragFromIdx];
        if (colDragFromIdx < dropIdx) theadTr.insertBefore(fromTh, th.nextSibling);
        else theadTr.insertBefore(fromTh, th);
        // Move tds in every data row
        boardTable.querySelectorAll('tbody tr[data-id]').forEach(function(tr) {
          var tds = Array.prototype.slice.call(tr.querySelectorAll('td[data-key]'));
          var fromTd = tds[colDragFromIdx];
          var toTd = tds[dropIdx];
          if (colDragFromIdx < dropIdx) tr.insertBefore(fromTd, toTd.nextSibling);
          else tr.insertBefore(fromTd, toTd);
        });
        var from = colDragFromIdx;
        colDragFromIdx = -1;
        initColDrag();
        // Persist new order
        postAction({ action: 'save_columns', columns_json: JSON.stringify(columns) }).then(function(){
          toast('Column order saved');
        }).catch(function(err){ toast(err.message, 'error'); });
      };
    });
  }
  initColDrag();

  // ── Row drag-to-reorder ──────────────────────────
  var rowDragFrom = null;
  var rowDragGrip = false;
  function initRowDrag() {
    boardTable.querySelectorAll('tbody tr[data-id]').forEach(function(tr) {
      tr.onmousedown = function(e) {
        rowDragGrip = !!e.target.closest('.seq-drag-handle');
      };
      tr.ondragstart = function(e) {
        if (!rowDragGrip) { e.preventDefault(); return; }
        rowDragFrom = tr;
        tr.classList.add('row-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', tr.getAttribute('data-id'));
      };
      tr.ondragend = function() {
        tr.classList.remove('row-dragging');
        boardTable.querySelectorAll('tbody tr.row-drag-over').forEach(function(x){ x.classList.remove('row-drag-over'); });
        rowDragFrom = null;
        rowDragGrip = false;
      };
      tr.ondragover = function(e) {
        if (!rowDragFrom) return;
        e.preventDefault();
        boardTable.querySelectorAll('tbody tr.row-drag-over').forEach(function(x){ x.classList.remove('row-drag-over'); });
        tr.classList.add('row-drag-over');
      };
      tr.ondragleave = function() { tr.classList.remove('row-drag-over'); };
      tr.ondrop = function(e) {
        e.preventDefault();
        tr.classList.remove('row-drag-over');
        if (!rowDragFrom || rowDragFrom === tr) return;
        var tbody = boardTable.querySelector('tbody');
        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
        var fromIdx = allRows.indexOf(rowDragFrom);
        var toIdx = allRows.indexOf(tr);
        if (fromIdx < 0 || toIdx < 0) return;
        if (fromIdx < toIdx) {
          tbody.insertBefore(rowDragFrom, tr.nextSibling);
        } else {
          tbody.insertBefore(rowDragFrom, tr);
        }
        rowDragFrom = null;
        // Renumber S.N column (index 2: drag-col, actions, S.N)
        var newRows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
        var order = [];
        newRows.forEach(function(r, i) {
          r.children[2].textContent = String(i + 1);
          order.push(r.getAttribute('data-id'));
        });
        // Save new sequence order
        postAction({ action: 'reorder_rows', order_json: JSON.stringify(order) }).then(function(){
          toast('Sequence updated');
        }).catch(function(err){ toast(err.message || 'Reorder failed', true); });
      };
    });
  }
  initRowDrag();
})();
</script>

<style>
.planning-view-switch {
  display: inline-flex;
  gap: 8px;
  margin: 0 0 14px;
  flex-wrap: wrap;
}
.planning-view-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  border-radius: 999px;
  border: 1px solid #dbe5f0;
  background: #fff;
  color: #334155;
  font-size: .82rem;
  font-weight: 700;
  text-decoration: none;
}
.planning-view-link:hover {
  border-color: #93c5fd;
  color: #1d4ed8;
}
.planning-view-link.is-active {
  background: #eff6ff;
  border-color: #bfdbfe;
  color: #1d4ed8;
}
.planning-board-wrap { max-height: 74vh; overflow: auto; }
.planning-board-table { min-width: 1700px; }
.planning-board-table th,
.planning-board-table td { white-space: nowrap; }
.planning-board-table th[draggable="true"] { cursor: move; }
.planning-board-table tr.is-editing { background: #f8fafc; }
.planning-board-table tr.is-editing td { white-space: normal; }
.planning-board-table tr.is-editing .cell-input {
  display: block !important;
  width: 100%;
  min-width: 120px;
  box-sizing: border-box;
}
.planning-board-table tr.is-editing td[data-type="Date"] .cell-input { min-width: 140px; }
.planning-board-table tr.is-editing td[data-type="Number"] .cell-input { min-width: 80px; }
.planning-board-table tr.is-editing td[data-type="Department"] .cell-input { min-width: 240px; }
.planning-board-table td[data-key] { min-width: 80px; }
.department-cell-display {
  display: block;
  min-width: 220px;
  white-space: normal;
  line-height: 1.35;
}
.department-check-group {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px 12px;
  min-width: 240px;
  padding: 10px 12px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  background: #fff;
}
.department-check-group-modal {
  background: #f8fafc;
}
.department-check-option {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: .8rem;
  color: #334155;
  line-height: 1.25;
}
.department-check-option input {
  margin: 0;
}
.sticky-col {
  position: sticky;
  left: 0;
  z-index: 4;
  background: #fff;
  min-width: 145px;
}
.planning-board-table thead .sticky-col { z-index: 6; }
.job-no-cell { white-space: nowrap; color: #1e40af; font-size: .82rem; letter-spacing: .02em; }
.job-no-cell strong { cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
.job-image-cell { min-width: 74px; text-align: center; }
.job-image-box {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 44px;
}
.job-image-thumb-btn {
  border: none;
  padding: 0;
  background: transparent;
  cursor: zoom-in;
}
.job-image-thumb {
  width: 56px;
  height: 40px;
  object-fit: cover;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  box-shadow: 0 2px 6px rgba(2, 6, 23, .12);
}
.job-image-empty-btn {
  width: 56px;
  height: 40px;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  color: #64748b;
  cursor: pointer;
}

.job-image-empty-btn:hover,
.job-image-thumb-btn:hover .job-image-thumb { border-color: #60a5fa; }

.image-viewer-modal-card {
  width: min(760px, 100%);
}
.image-viewer-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 16px;
  border-bottom: 1px solid #e2e8f0;
}
.image-viewer-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  color: #334155;
}
.image-viewer-meta strong {
  font-size: .9rem;
  color: #0f172a;
}
.image-viewer-meta span,
.image-viewer-meta small {
  font-size: .76rem;
}
.image-viewer-controls {
  display: flex;
  gap: 6px;
}
.image-viewer-stage {
  background: #0f172a;
  min-height: 42vh;
  max-height: 52vh;
  overflow: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 18px;
}
.image-viewer-stage.is-empty::before {
  content: attr(data-empty);
  color: #cbd5e1;
  font-size: .92rem;
}
.image-viewer-stage img {
  max-width: 100%;
  max-height: 100%;
  border-radius: 8px;
  box-shadow: 0 18px 38px rgba(15, 23, 42, .5);
  transition: transform .16s ease;
}

.planning-modal {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, .42);
  z-index: 1200;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.planning-modal-card {
  width: min(920px, 100%);
  max-height: 88vh;
  overflow: auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 22px 54px rgba(0,0,0,.22);
  border: 1px solid #e5e7eb;
}
.planning-modal-head,
.planning-modal-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid #eef2f7;
}
.planning-modal-foot {
  border-bottom: none;
  border-top: 1px solid #eef2f7;
}
.planning-modal-head h3 { font-size: 1.05rem; }
.planning-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 14px 16px;
}
.planning-job-preview-box {
  margin: 14px 16px 0;
  padding: 12px 14px;
  border: 1px solid #dbeafe;
  background: #eff6ff;
  border-radius: 10px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.planning-job-preview-label {
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #1d4ed8;
}
.planning-job-preview-box strong {
  font-size: 1rem;
  color: #0f172a;
}
.planning-job-preview-box small {
  color: #475569;
}
.planning-grid label {
  display: block;
  margin-bottom: 5px;
  font-size: .75rem;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: .04em;
}
.planning-picker-inline {
  display: flex;
  align-items: center;
  gap: 8px;
}
.planning-picker-inline .planning-picker-source {
  flex: 1 1 auto;
}
.planning-picker-inline-modal {
  width: 100%;
}
.planning-picker-highlight {
  border-color: #f59e0b !important;
  background: linear-gradient(180deg, #fffdf5 0%, #fff7db 100%);
  box-shadow: inset 0 0 0 1px rgba(245, 158, 11, .18);
}
.planning-picker-highlight:focus {
  border-color: #d97706 !important;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, .18);
}
.planning-picker-btn {
  width: 40px;
  min-width: 40px;
  height: 38px;
  border: 1px solid #f4b860;
  border-radius: 10px;
  background: linear-gradient(180deg, #fff1c2 0%, #fed27a 100%);
  color: #8a4b08;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
}
.planning-picker-btn:hover {
  border-color: #d97706;
  box-shadow: 0 8px 18px rgba(217, 119, 6, .18);
  transform: translateY(-1px);
}
.planning-picker-btn i {
  font-size: .92rem;
}
.planning-picker-modal-card {
  width: min(680px, 100%);
}
.planning-picker-modal-body {
  padding: 14px 16px 16px;
}
.planning-picker-searchbar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.planning-picker-list {
  display: grid;
  gap: 10px;
  max-height: 52vh;
  overflow: auto;
}
.planning-picker-item {
  width: 100%;
  text-align: left;
  border: 1px solid #fde68a;
  border-radius: 12px;
  background: linear-gradient(180deg, #fffdf5 0%, #fff8df 100%);
  padding: 12px 14px;
  display: grid;
  gap: 4px;
  cursor: pointer;
}
.planning-picker-item strong {
  color: #8a4b08;
  font-size: .92rem;
}
.planning-picker-item span {
  color: #1f2937;
  font-size: .84rem;
  font-weight: 600;
}
.planning-picker-item small {
  color: #6b7280;
  font-size: .74rem;
}
.planning-picker-item:hover {
  border-color: #f59e0b;
  box-shadow: 0 10px 22px rgba(245, 158, 11, .16);
}
.planning-picker-empty {
  border: 1px dashed #cbd5e1;
  border-radius: 12px;
  background: #f8fafc;
  color: #64748b;
  padding: 18px;
  text-align: center;
  font-weight: 600;
}
.config-list { padding: 14px 16px; display: grid; gap: 8px; }
.import-map-list {
  padding: 14px 16px;
  display: grid;
  gap: 8px;
  max-height: 52vh;
  overflow: auto;
}
.import-map-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  align-items: center;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #f8fafc;
  padding: 8px 10px;
}
.import-map-src {
  font-size: .8rem;
  color: #0f172a;
  font-weight: 700;
  word-break: break-word;
}
.plan-detail-grid {
  padding: 16px;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.plan-detail-item {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px;
  background: #f8fafc;
}
.plan-detail-label {
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #64748b;
  font-weight: 700;
  margin-bottom: 6px;
}
.plan-detail-value {
  color: #0f172a;
  font-weight: 600;
  word-break: break-word;
}
.config-row {
  display: grid;
  grid-template-columns: 34px 1fr 150px;
  gap: 8px;
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 8px;
}
.config-drag {
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
}
@media (max-width: 980px) {
  .planning-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 680px) {
  .planning-grid { grid-template-columns: 1fr; }
  .import-map-row { grid-template-columns: 1fr; }
  .plan-detail-grid { grid-template-columns: 1fr; }
  .job-image-cell { min-width: 64px; }
  .planning-picker-inline {
    gap: 6px;
  }
  .planning-picker-btn {
    width: 36px;
    min-width: 36px;
    height: 36px;
  }
  .job-image-thumb,
  .job-image-empty-btn { width: 48px; height: 34px; }
  .image-viewer-stage { min-height: 36vh; }
}
/* ── In-page toast notifications ── */
#erp-toast-ctr { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; max-width: 420px; }
.erp-toast { display: flex; align-items: center; gap: 10px; padding: 12px 18px; border-radius: 8px; font-size: .875rem; font-weight: 500; box-shadow: 0 4px 20px rgba(0,0,0,.18); pointer-events: auto; opacity: 0; transform: translateX(48px); transition: opacity .28s ease, transform .28s ease; color: #fff; }
.erp-toast.show { opacity: 1; transform: translateX(0); }
.erp-toast-success { background: #16a34a; }
.erp-toast-error { background: #dc2626; }
.erp-toast-info { background: #0284c7; }
.erp-toast-icon { font-size: .95rem; font-weight: 900; flex-shrink: 0; }
.erp-toast-msg { flex: 1; line-height: 1.45; word-break: break-word; }
.erp-toast-x { background: none; border: none; color: inherit; font-size: 1.2rem; cursor: pointer; opacity: .8; padding: 0 0 0 6px; line-height: 1; }
.erp-toast-x:hover { opacity: 1; }
.erp-toast-confirm { gap: 10px; flex-wrap: wrap; background: #1e293b; }
.erp-toast-confirm .erp-toast-msg { color: #f1f5f9; }
.erp-toast-confirm .erp-toast-icon { color: #fbbf24; font-size: 1rem; }
.erp-confirm-yes, .erp-confirm-no { flex-shrink: 0; font-size: .78rem !important; padding: 4px 12px !important; }
/* ── Status select inline edit ── */
.cell-select-status { -webkit-appearance: none; appearance: none; font-size: .78rem; font-weight: 700; text-align: center; border-radius: 4px !important; padding: 3px 14px !important; cursor: pointer; min-width: 130px; border-width: 0 !important; }
/* ── Column header drag ── */
.planning-board-table th[data-col-key] { cursor: grab; user-select: none; }
.planning-board-table th[data-col-key].col-drag-over { background: #dbeafe; outline: 2px dashed #3b82f6; outline-offset: -2px; }
.planning-board-table th[data-col-key].col-dragging { opacity: .4; }
/* ── Row background colour by status ── */
.row-s-running td, .row-s-running .sticky-col   { background: #eff6ff !important; }
.row-s-completed td, .row-s-completed .sticky-col{ background: #f0fdf4 !important; }
.row-s-hold td, .row-s-hold .sticky-col          { background: #fff5f5 !important; }
.row-s-slitting td, .row-s-slitting .sticky-col  { background: #fff7ed !important; }
.row-s-pending td, .row-s-pending .sticky-col    { background: #fff    !important; }
.row-s-printingdone td, .row-s-printingdone .sticky-col { background: #f0fdfa !important; }
/* ── Status pill badge ── */
.status-pill { display: inline-block; font-size: .75rem; font-weight: 700; padding: 3px 14px; border-radius: 4px; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; color: #fff; }
/* Double-click hint cursor on data cells */
.planning-board-table tbody tr[data-id]:not(.is-editing) td:not(.sticky-col):not(.seq-drag-col) { cursor: pointer; }
/* ── Priority pill badge ── */
.priority-pill { display: inline-block; font-size: .75rem; font-weight: 700; padding: 3px 14px; border-radius: 4px; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; color: #fff; }
.priority-pill-low { background: #94a3b8; }
.priority-pill-normal { background: #3b82f6; }
.priority-pill-high { background: #f97316; }
.priority-pill-urgent { background: #ef4444; animation: pulse-urgent 1.5s ease-in-out infinite; }
@keyframes pulse-urgent { 0%,100%{ opacity:1; } 50%{ opacity:.78; } }
/* ── Priority select inline edit ── */
.cell-select-priority { -webkit-appearance: none; appearance: none; font-size: .78rem; font-weight: 700; text-align: center; border-radius: 4px !important; padding: 3px 14px !important; cursor: pointer; min-width: 100px; border-width: 0 !important; }
.psel-low     { background: #94a3b8 !important; color: #fff !important; }
.psel-normal  { background: #3b82f6 !important; color: #fff !important; }
.psel-high    { background: #f97316 !important; color: #fff !important; }
.psel-urgent  { background: #ef4444 !important; color: #fff !important; }
/* ── Row drag-to-reorder ── */
.seq-drag-col { width: 28px; min-width: 28px; max-width: 28px; text-align: center; padding: 4px 2px !important; }
.seq-drag-handle { cursor: grab; color: #94a3b8; font-size: 1.1rem; user-select: none; }
.seq-drag-handle:hover { color: #3b82f6; }
.seq-drag-handle:active { cursor: grabbing; }
.row-dragging { opacity: .35; }
.row-dragging td { background: #dbeafe !important; }
.row-drag-over { outline: 2px dashed #3b82f6; outline-offset: -2px; }
.row-drag-over td { background: #eff6ff !important; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
