<?php
// ============================================================
// ERP System — Planning: Board (Concept port from Firebase)
// Keeps existing UI theme/colors. Planning module only.
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

// Ensure department support for planning rows.
try { $db->query("ALTER TABLE planning ADD COLUMN department VARCHAR(80) NOT NULL DEFAULT 'general' AFTER notes"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE planning ADD COLUMN extra_data LONGTEXT NULL AFTER department"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE planning ADD COLUMN job_no VARCHAR(60) NULL AFTER id"); } catch (Exception $e) {}

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
  ['key' => 'order_date', 'label' => 'Order Date', 'type' => 'Date', 'sort' => 2],
  ['key' => 'dispatch_date', 'label' => 'Dispatch Date', 'type' => 'Date', 'sort' => 3],
  ['key' => 'printing_planning', 'label' => 'Status', 'type' => 'Status', 'sort' => 4],
  ['key' => 'plate_no', 'label' => 'Plate No', 'type' => 'Text', 'sort' => 5],
  ['key' => 'name', 'label' => 'Job Name', 'type' => 'Text', 'sort' => 6],
  ['key' => 'size', 'label' => 'Size', 'type' => 'Text', 'sort' => 7],
  ['key' => 'repeat', 'label' => 'Repeat', 'type' => 'Text', 'sort' => 8],
  ['key' => 'material', 'label' => 'Material', 'type' => 'Text', 'sort' => 9],
  ['key' => 'paper_size', 'label' => 'Paper Size', 'type' => 'Text', 'sort' => 10],
  ['key' => 'die', 'label' => 'Die', 'type' => 'Text', 'sort' => 11],
  ['key' => 'allocate_mtrs', 'label' => 'MTRS', 'type' => 'Number', 'sort' => 12],
  ['key' => 'qty_pcs', 'label' => 'QTY', 'type' => 'Number', 'sort' => 13],
  ['key' => 'core_size', 'label' => 'Core', 'type' => 'Text', 'sort' => 14],
  ['key' => 'qty_per_roll', 'label' => 'Qty/Roll', 'type' => 'Text', 'sort' => 15],
  ['key' => 'roll_direction', 'label' => 'Direction', 'type' => 'Text', 'sort' => 16],
  ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'Text', 'sort' => 17],
];

$allowedTypes = ['Text', 'Number', 'Date', 'Status'];
$statusList = ['Pending', 'Preparing Slitting', 'Slitting Completed', 'Running', 'Completed', 'Hold', 'Hold for Payment', 'Hold for Approval'];
$priorityList = ['Low', 'Normal', 'High', 'Urgent'];

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
        ORDER BY
          CASE WHEN p.job_no IS NULL OR p.job_no = '' THEN 1 ELSE 0 END ASC,
          CAST(SUBSTRING_INDEX(p.job_no, '/', -1) AS UNSIGNED) ASC,
          p.id ASC");
    $stmt->bind_param('s', $department);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function planning_normalize_status($status) {
  $s = trim((string)$status);
  if ($s === '') return 'Pending';

  $sLower = strtolower($s);
  // Any slitting-stage text should stay in the slitting lane.
  if (strpos($sLower, 'slitting') !== false || strpos($sLower, 'sliting') !== false) {
    if (strpos($sLower, 'completed') !== false || strpos($sLower, 'done') !== false) return 'Slitting Completed';
    return 'Preparing Slitting';
  }

  // Normalize legacy and typo values to the canonical status.
  if (strcasecmp($s, 'Slitting') === 0
      || strcasecmp($s, 'Prepared Slitting') === 0
      || strcasecmp($s, 'Prepared Sliting') === 0
      || strcasecmp($s, 'Preparing Sliting') === 0) {
    return 'Preparing Slitting';
  }

  if (strcasecmp($s, 'Slitting Done') === 0 || strcasecmp($s, 'Sliting Done') === 0 || strcasecmp($s, 'Slitting Completed') === 0) {
    return 'Slitting Completed';
  }

  $allowed = ['Pending', 'Running', 'Completed', 'Hold', 'Hold for Payment', 'Hold for Approval', 'Preparing Slitting', 'Slitting', 'Slitting Completed'];
  foreach ($allowed as $v) {
    if (strcasecmp($s, $v) === 0) return $v;
  }
  return 'Pending';
}

function planning_status_badge($status) {
    $s = (string)$status;
    $class = 'pending';
    if (strcasecmp($s, 'Completed') === 0) $class = 'completed';
    elseif (strcasecmp($s, 'Running') === 0) $class = 'in-progress';
    elseif (stripos($s, 'Hold') === 0 || strcasecmp($s, 'On Hold') === 0) $class = 'on-hold';
    elseif (strcasecmp($s, 'Slitting') === 0 || strcasecmp($s, 'Preparing Slitting') === 0) $class = 'slitting';
    elseif (strcasecmp($s, 'Slitting Completed') === 0 || strcasecmp($s, 'Sliting Done') === 0 || strcasecmp($s, 'Slitting Done') === 0) $class = 'completed';
    elseif ($s === 'Queued') $class = 'consumed';
    return '<span class="badge badge-' . $class . '">' . e($s) . '</span>';
}

  function planning_status_pill_class($status) {
    $v = strtolower(preg_replace('/[^a-z]/', '', trim((string)$status)));
    if (strpos($v, 'slitting') !== false || strpos($v, 'sliting') !== false) {
      if (strpos($v, 'completed') !== false || strpos($v, 'done') !== false) return 'completed';
      return 'slitting';
    }
    if ($v === 'running' || $v === 'inprogress') return 'running';
    if ($v === 'completed' || $v === 'slittingcompleted') return 'completed';
    if (strpos($v, 'hold') === 0) return 'hold';
    if ($v === 'preparingslitting' || $v === 'preparingsliting' || $v === 'preparedslitting' || $v === 'preparedsliting' || $v === 'slitting') return 'slitting';
    return 'pending';
  }

  function planning_board_status_badge($status) {
    $s = trim((string)$status);
    if ($s === '') $s = 'Pending';
    $class = 'pending';
    if (strcasecmp($s, 'Running') === 0) $class = 'in-progress';
    elseif (strcasecmp($s, 'Completed') === 0) $class = 'completed';
    elseif (strcasecmp($s, 'Preparing Slitting') === 0 || strcasecmp($s, 'Slitting') === 0) $class = 'slitting';
    elseif (strcasecmp($s, 'Slitting Completed') === 0 || strcasecmp($s, 'Sliting Done') === 0 || strcasecmp($s, 'Slitting Done') === 0) $class = 'completed';
    elseif (stripos($s, 'Hold') === 0) $class = 'on-hold';
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
        $liveStatus = planning_normalize_status((string)($row['status'] ?? ''));
        $extraStatus = planning_normalize_status((string)($extra['printing_planning'] ?? ''));
        $vals[$k] = $liveStatus !== 'Pending' ? $liveStatus : ($extraStatus !== 'Pending' ? $extraStatus : 'Pending');
        continue;
      }
      if (array_key_exists($k, $extra)) {
        $val = (string)$extra[$k];
        $vals[$k] = $val;
        continue;
      }
      if ($k === 'name') $vals[$k] = (string)($row['job_name'] ?? '');
      elseif ($k === 'remarks') $vals[$k] = (string)($row['notes'] ?? '');
      elseif ($k === 'dispatch_date') $vals[$k] = (string)($extra['dispatch_date'] ?? ($row['scheduled_date'] ?? ''));
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

      $statusRaw = trim((string)($rowValues['printing_planning'] ?? $rowValues['status'] ?? 'Pending'));
      if ($statusRaw === '') $statusRaw = 'Pending';
      $status = planning_normalize_status($statusRaw);
      // Persist canonical status token in extra_data so reload keeps correct color class.
      $rowValues['printing_planning'] = $status;
      $priority = (string)($rowValues['priority'] ?? 'Normal');
        if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

      $machine = trim((string)($rowValues['machine'] ?? ''));
      $operator = trim((string)($rowValues['operator_name'] ?? ''));
      $notes = trim((string)($rowValues['remarks'] ?? $rowValues['notes'] ?? ''));
      $scheduled = planning_try_parse_date($rowValues['dispatch_date'] ?? $rowValues['scheduled_date'] ?? '');

      $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $up = $db->prepare("UPDATE planning SET job_name=?, machine=?, operator_name=?, scheduled_date=NULLIF(?, ''), status=?, priority=?, notes=?, extra_data=? WHERE id=? AND department=?");
      $up->bind_param('ssssssssis', $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $extraJson, $id, $department);
        $ok = $up->execute();
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Row updated.' : 'Update failed.']);
    }

    if ($action === 'delete_row') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) planning_json_response(['ok' => false, 'message' => 'Invalid row id.'], 400);

      $sel = $db->prepare("SELECT extra_data FROM planning WHERE id = ? AND department = ? LIMIT 1");
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

      $statusRaw = 'Pending';
      $rowValues['printing_planning'] = 'Pending';

      $status = 'Pending';
      $priority = (string)($rowValues['priority'] ?? $_POST['priority'] ?? 'Normal');
        if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

      $machine = trim((string)($rowValues['machine'] ?? $_POST['machine'] ?? ''));
      $operator = trim((string)($rowValues['operator_name'] ?? $_POST['operator_name'] ?? ''));
      $notes = trim((string)($rowValues['remarks'] ?? $_POST['notes'] ?? ''));
      $scheduled = planning_try_parse_date($rowValues['dispatch_date'] ?? $_POST['scheduled_date'] ?? '');

      $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Auto-generate planning job number
      $planJobNo = getNextId('planning');
      if (!$planJobNo) $planJobNo = 'PLN-' . date('Ymd') . '-' . rand(1000,9999);

      $ins = $db->prepare("INSERT INTO planning (job_no, sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by) VALUES (?,NULL,?,?,?,NULLIF(?, ''),?,?,?,?,?,?)");
        $uid = (int)($_SESSION['user_id'] ?? 0);
      $ins->bind_param('ssssssssssi', $planJobNo, $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
        $ok = $ins->execute();
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
                 $rowValues[$k] = 'NA';
               }
            }

            $jobName = trim((string)($rowValues['name'] ?? ''));
            if ($jobName === '') continue;

            $machine = (string)(planning_get_any_key($normRow, ['machine']) ?? '');
            $operator = (string)(planning_get_any_key($normRow, ['operator_name', 'operator']) ?? '');
            $scheduled = planning_try_parse_date($rowValues['dispatch_date'] ?? (planning_get_any_key($normRow, ['scheduled_date', 'scheduled date']) ?? ''));
            $rowValues['dispatch_date'] = $scheduled;
            $rowValues['order_date'] = planning_try_parse_date($rowValues['order_date'] ?? '');

            $status = 'Pending';
            $rowValues['printing_planning'] = 'Pending';

            $priority = (string)(planning_get_any_key($normRow, ['priority']) ?? 'Normal');
            if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

            $notes = trim((string)($rowValues['remarks'] ?? ''));
            $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Auto-generate planning job number for each imported row
            $planJobNo = getNextId('planning');
            if (!$planJobNo) $planJobNo = 'PLN-' . date('Ymd') . '-' . rand(1000,9999);

            $ins->bind_param('ssssssssssi', $planJobNo, $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
            if ($ins->execute()) $count++;
        }

        if ($count === 0) {
            planning_json_response(['ok' => false, 'message' => 'Import failed: No valid rows matched required Job Name column.'], 400);
        }
        planning_json_response(['ok' => true, 'message' => 'Import completed.', 'count' => $count]);
    }

    planning_json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
}

$columns = planning_get_columns($db, $department, $defaultColumns);
$rows = planning_get_rows($db, $department);
$planningJobPreview = previewNextId('planning') ?: 'Auto-generated on save';

$pageTitle = 'Planning Board';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>Planning</span>
</div>

<div class="page-header">
  <div>
    <h1>Planning Board</h1>
    <p>Live planning board with inline editing, Excel import, and configurable headers.</p>
  </div>
  <div class="d-flex gap-8">
    <button type="button" class="btn btn-ghost" id="btn-print-pdf"><i class="bi bi-printer"></i> Print / PDF</button>
    <button type="button" class="btn btn-ghost" id="btn-export-excel"><i class="bi bi-file-earmark-excel"></i> Export Excel</button>
    <button type="button" class="btn btn-ghost" id="btn-board-config"><i class="bi bi-layout-text-window-reverse"></i> Board Layout</button>
    <button type="button" class="btn btn-secondary" id="btn-import"><i class="bi bi-file-earmark-arrow-up"></i> Import Excel</button>
    <button type="button" class="btn btn-primary" id="btn-add-row"><i class="bi bi-plus-circle"></i> Add Job Entry</button>
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
        <?php $deptOptions = ['label-printing', 'printing', 'slitting', 'packing', 'dispatch', 'general']; ?>
        <?php foreach ($deptOptions as $d): ?>
          <option value="<?= e($d) ?>" <?= $d === $department ? 'selected' : '' ?>><?= e(ucwords(str_replace('-', ' ', $d))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="table-wrap planning-board-wrap">
    <table id="planning-board-table" class="planning-board-table">
      <thead>
        <tr>
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
        <tr><td colspan="<?= $visibleColCount + 4 ?>" class="table-empty"><i class="bi bi-inbox"></i>No planning jobs found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $r): ?>
          <?php
            $rowVals = planning_extract_row_values($r, $columns, $idx);
            $rowStatusVal = 'Pending';
            foreach ($columns as $_sc) { if ($_sc['type'] === 'Status') { $rowStatusVal = (string)($rowVals[$_sc['key']] ?? 'Pending'); break; } }
            $rowSCls = planning_status_pill_class($rowStatusVal);
            $rowExtra = json_decode((string)($r['extra_data'] ?? '{}'), true);
            if (!is_array($rowExtra)) $rowExtra = [];
            $jobImagePath = trim((string)($rowExtra['image_path'] ?? ''));
            $jobImageName = trim((string)($rowExtra['image_name'] ?? ''));
            $jobImageUploadedAt = trim((string)($rowExtra['image_uploaded_at'] ?? ''));
            $jobImageUrl = $jobImagePath !== '' ? appUrl($jobImagePath) : '';
          ?>
          <tr data-id="<?= (int)$r['id'] ?>" class="row-s-<?= $rowSCls ?>">
            <td class="sticky-col">
              <div class="row-actions">
                <button class="btn btn-secondary btn-sm btn-edit" type="button" title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-primary btn-sm btn-save" type="button" title="Save" style="display:none"><i class="bi bi-check2-circle"></i></button>
                <button class="btn btn-ghost btn-sm btn-cancel" type="button" title="Cancel" style="display:none"><i class="bi bi-x-circle"></i></button>
                <button class="btn btn-danger btn-sm btn-delete" type="button" title="Delete"><i class="bi bi-trash"></i></button>
              </div>
            </td>
            <td><?= (int)($idx + 1) ?></td>
            <td class="job-no-cell"><strong><?= e($r['job_no'] ?? '—') ?></strong></td>
            <?php foreach ($columns as $c): ?>
              <?php if ($c['key'] === 'sn') continue; ?>
              <?php
                $k = $c['key'];
                $v = (string)($rowVals[$k] ?? '');
              ?>
              <td data-key="<?= e($k) ?>" data-type="<?= e($c['type']) ?>">
                <?php if ($c['type'] === 'Status'): ?>
                  <span class="cell-display status-pill status-pill-<?= e(planning_status_pill_class($v ?: 'Pending')) ?>"><?= e($v ?: 'Pending') ?></span>
                  <?php
                    $statusOptions = array_values(array_unique(array_merge(
                      ['Pending', 'Preparing Slitting', 'Slitting Completed', 'Running', 'Completed', 'Hold', 'Hold for Payment', 'Hold for Approval'],
                      $statusList,
                      [$v ?: 'Pending']
                    )));
                  ?>
                  <select class="cell-input cell-select-status form-control" style="display:none">
                    <?php foreach ($statusOptions as $s): ?><option value="<?= e($s) ?>"<?= $v === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                  </select>
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
          <div<?= $c['key'] === 'remarks' ? ' style="grid-column:1 / -1"' : '' ?>>
            <label><?= e($c['label']) ?><?= $c['key'] === 'name' ? ' *' : '' ?></label>
            <?php if ($c['type'] === 'Status'): ?>
              <input class="form-control" type="text" value="Pending" readonly>
              <input type="hidden" name="<?= e($c['key']) ?>" value="Pending">
            <?php else: ?>
              <input class="form-control" name="<?= e($c['key']) ?>" type="<?= $c['type'] === 'Number' ? 'number' : ($c['type'] === 'Date' ? 'date' : 'text') ?>" <?= $c['key'] === 'name' ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div style="grid-column:1 / -1">
          <label>Job Image (Optional)</label>
          <input class="form-control" name="job_image" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
        </div>
      </div>
      <div class="planning-modal-foot">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i> Save Entry</button>
      </div>
    </form>
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

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function(){
  'use strict';

  var boardTable = document.getElementById('planning-board-table');
  var deptSwitch = document.getElementById('dept-switch');
  var addModal = document.getElementById('modal-add');
  var cfgModal = document.getElementById('modal-config');
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
  var columns = <?= json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var csrfToken = document.querySelector('#csrf-form [name="csrf_token"]').value;
  var currentDepartment = <?= json_encode($department, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var imageViewerScale = 1;
  var imageViewerRowId = '';
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
      var snCell = tr.children[1];
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

    var data = [boardHeaders()].concat(rows);
    var ws = XLSX.utils.aoa_to_sheet(data);
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Planning');

    var fileDept = String(currentDepartment || 'planning').replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'planning';
    var stamp = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, 'planning-' + fileDept + '-' + stamp + '.xlsx');
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
        label: (headers[idx + 3] || 'Field').trim(),
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
    if (norm.indexOf('slitting') !== -1 || norm.indexOf('sliting') !== -1) {
      if (norm.indexOf('completed') !== -1) {
        tr.classList.remove('row-s-running','row-s-completed','row-s-hold','row-s-slitting','row-s-pending');
        tr.classList.add('row-s-completed');
      } else {
        tr.classList.remove('row-s-running','row-s-completed','row-s-hold','row-s-slitting','row-s-pending');
        tr.classList.add('row-s-slitting');
      }
      return;
    }
    var cls = (norm === 'running' || norm === 'inprogress')
      ? 'row-s-running'
      : (norm === 'completed' || norm === 'slittingcompleted')
        ? 'row-s-completed'
        : (norm.indexOf('hold') === 0)
          ? 'row-s-hold'
          : (norm === 'preparingslitting' || norm === 'preparingsliting' || norm === 'preparedslitting' || norm === 'preparedsliting' || norm === 'slitting')
            ? 'row-s-slitting'
            : 'row-s-pending';
    tr.classList.remove('row-s-running','row-s-completed','row-s-hold','row-s-slitting','row-s-pending');
    tr.classList.add(cls);
  }

  function applyStatusStyle(sel) {
    var v = String(sel.value || '').trim().toLowerCase();
    var norm = v.replace(/[^a-z]/g, '');
    if (norm.indexOf('slitting') !== -1 || norm.indexOf('sliting') !== -1) {
      sel.className = 'cell-input cell-select-status form-control ' + ((norm.indexOf('completed') !== -1 || norm.indexOf('done') !== -1) ? 'ssel-completed' : 'ssel-slitting');
      var tr2 = sel.closest('tr[data-id]');
      if (tr2) applyRowStatus(tr2, sel.value);
      return;
    }
    var cls = (norm === 'running' || norm === 'inprogress')
      ? 'ssel-running'
      : (norm === 'completed' || norm === 'slittingcompleted')
        ? 'ssel-completed'
        : (norm.indexOf('hold') === 0)
          ? 'ssel-hold'
          : (norm === 'preparingslitting' || norm === 'preparingsliting' || norm === 'preparedslitting' || norm === 'preparedsliting' || norm === 'slitting')
            ? 'ssel-slitting'
            : 'ssel-pending';
    sel.className = 'cell-input cell-select-status form-control ' + cls;
    var tr = sel.closest('tr[data-id]');
    if (tr) applyRowStatus(tr, sel.value);
  }

  function statusClassFromText(txt) {
    var s = String(txt || '').trim().toLowerCase();
    var n = s.replace(/[^a-z]/g, '');
    if (n.indexOf('slitting') !== -1 || n.indexOf('sliting') !== -1) {
      return (n.indexOf('completed') !== -1 || n.indexOf('done') !== -1) ? 'completed' : 'slitting';
    }
    if (n === 'running' || n === 'inprogress') return 'running';
    if (n === 'completed') return 'completed';
    if (n.indexOf('hold') === 0) return 'hold';
    return 'pending';
  }

  function normalizeRenderedStatuses() {
    boardTable.querySelectorAll('tr[data-id]').forEach(function(tr){
      var statusCell = tr.querySelector('td[data-type="Status"] .cell-display.status-pill');
      if (!statusCell) return;
      var cls = statusClassFromText(statusCell.textContent || 'Pending');
      statusCell.className = 'cell-display status-pill status-pill-' + cls;
      var currentText = String(statusCell.textContent || '').trim();
      var logicalStatus = (cls === 'slitting') ? 'Preparing Slitting' : currentText;
      if (cls === 'completed' && /slit+ing?\s*done/i.test(currentText)) {
        logicalStatus = 'Slitting Completed';
      }
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

  deptSwitch.addEventListener('change', function(){
    var url = new URL(window.location.href);
    url.searchParams.set('department', deptSwitch.value);
    window.location.href = url.toString();
  });

  function setRowEditMode(tr, on) {
    tr.classList.toggle('is-editing', on);
    tr.querySelector('.btn-edit').style.display = on ? 'none' : '';
    tr.querySelector('.btn-delete').style.display = on ? 'none' : '';
    tr.querySelector('.btn-save').style.display = on ? '' : 'none';
    tr.querySelector('.btn-cancel').style.display = on ? '' : 'none';

    tr.querySelectorAll('td[data-key]').forEach(function(td){
      var key = td.getAttribute('data-key');
      var disp = td.querySelector('.cell-display');
      var input = td.querySelector('.cell-input');
      if (key === 'sn') return;
      disp.style.display = on ? 'none' : '';
      input.style.display = on ? '' : 'none';

      var type = td.getAttribute('data-type');
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
      }
    });
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
        rowValues[key] = td.querySelector('.cell-input').value || '';
        payload[key] = rowValues[key];
      });
      payload.row_values_json = JSON.stringify(rowValues);
      postAction(payload).then(function(){
        tr.querySelectorAll('td[data-key]').forEach(function(td){
          var key = td.getAttribute('data-key');
          var type = td.getAttribute('data-type');
          var disp = td.querySelector('.cell-display');
          var input = td.querySelector('.cell-input');
          if (key === 'sn') return;

          var v = input.value || '';
          td.setAttribute('data-raw', v);
          if (type === 'Status') {
            var vl = String(v || 'Pending').trim().toLowerCase().replace(/[^a-z]/g,'');
            var pilClass = (vl.indexOf('slitting') !== -1 || vl.indexOf('sliting') !== -1)
              ? (vl.indexOf('completed') !== -1 ? 'completed' : 'slitting')
              : (vl === 'running' || vl === 'inprogress')
              ? 'running'
              : (vl === 'completed' || vl === 'slittingcompleted')
                ? 'completed'
                : (vl.indexOf('hold') === 0)
                  ? 'hold'
                  : (vl === 'preparingslitting' || vl === 'preparingsliting' || vl === 'preparedslitting' || vl === 'preparedsliting' || vl === 'slitting')
                    ? 'slitting'
                    : 'pending';
            disp.className = 'cell-display status-pill status-pill-' + pilClass;
            disp.textContent = v || 'Pending';
            applyRowStatus(tr, v);
          } else {
            disp.textContent = v || '—';
          }
        });

        setRowEditMode(tr, false);
        toast('Plan saved');
      }).catch(function(err){ toast(err.message || 'Save failed', true); });
      return;
    }
  });

  boardTable.addEventListener('dblclick', function(e){
    if (e.target.closest('.btn-edit,.btn-save,.btn-cancel,.btn-delete,.job-image-thumb-btn,.job-image-empty-btn')) return;
    var tr = e.target.closest('tr[data-id]');
    if (!tr || tr.classList.contains('is-editing')) return;
    setRowEditMode(tr, true);
  });

  // Final UI guard: ensure persisted/legacy statuses render with the correct color after refresh.
  normalizeRenderedStatuses();

  function openModal(el) { el.style.display = 'flex'; }
  function closeModal(el) {
    el.style.display = 'none';
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

  document.getElementById('btn-add-row').addEventListener('click', function(){ openModal(addModal); });
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

  [addModal, cfgModal, detailModal, imageViewerModal].forEach(function(m){
    m.addEventListener('click', function(e){ if (e.target === m) closeModal(m); });
  });

  document.getElementById('form-add-row').addEventListener('submit', function(e){
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
        var rows = XLSX.utils.sheet_to_json(ws, { defval: '' });
        if (!rows.length) { toast('No rows found in file', true); return; }

        var normalizedRows = rows.map(function(row){
          var out = {};
          Object.keys(row).forEach(function(k){
            var nk = String(k || '').replace(/\s+/g, ' ').trim().toLowerCase();
            out[nk] = row[k];
          });
          return out;
        });

        postAction({ action: 'import_rows', rows_json: JSON.stringify(normalizedRows) }).then(function(res){
          toast('Import successful: ' + (res.count || 0) + ' rows');
          window.location.reload();
        }).catch(function(err){ toast(err.message || 'Import failed', true); });
      } catch (err) {
        toast('Unable to read Excel file: ' + (err && err.message ? err.message : 'unknown error'), true);
      } finally {
        fileInput.value = '';
      }
    };
    reader.readAsArrayBuffer(file);
  });

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
})();
</script>

<style>
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
.planning-board-table td[data-key] { min-width: 80px; }
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
.config-list { padding: 14px 16px; display: grid; gap: 8px; }
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
  .plan-detail-grid { grid-template-columns: 1fr; }
  .job-image-cell { min-width: 64px; }
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
.ssel-running  { background: #3b82f6 !important; color: #fff !important; }
.ssel-completed{ background: #22c55e !important; color: #fff !important; }
.ssel-hold     { background: #ef4444 !important; color: #fff !important; }
.ssel-slitting { background: #f97316 !important; color: #fff !important; }
.ssel-pending  { background: #94a3b8 !important; color: #fff !important; }
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
/* ── Status pill badge ── */
.status-pill { display: inline-block; font-size: .75rem; font-weight: 700; padding: 3px 14px; border-radius: 4px; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; color: #fff; }
.status-pill-running          { background: #3b82f6; }
.status-pill-completed        { background: #22c55e; }
.status-pill-hold,
.status-pill-holdforpayment,
.status-pill-holdforapproval  { background: #ef4444; }
.status-pill-slitting,
.status-pill-preparingslitting { background: #f97316; }
.status-pill-pending          { background: #94a3b8; }
/* Double-click hint cursor on data cells */
.planning-board-table tbody tr[data-id]:not(.is-editing) td:not(.sticky-col) { cursor: pointer; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
