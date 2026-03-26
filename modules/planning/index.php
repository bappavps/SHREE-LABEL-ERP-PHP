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
$statusList = ['Running', 'Completed', 'Hold', 'Hold for Payment', 'Hold for Approval', 'Pending'];
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
          FIELD(p.priority,'Urgent','High','Normal','Low'),
          p.scheduled_date ASC,
          p.id DESC");
    $stmt->bind_param('s', $department);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function planning_normalize_status($status) {
    $s = trim((string)$status);
    if ($s === '' || strcasecmp($s, 'Pending') === 0 || strcasecmp($s, 'Running') === 0) return 'Queued';
    if (strcasecmp($s, 'Hold') === 0 || strcasecmp($s, 'Hold for Payment') === 0 || strcasecmp($s, 'Hold for Approval') === 0) return 'On Hold';
    if (strcasecmp($s, 'Completed') === 0) return 'Completed';
    if (strcasecmp($s, 'In Progress') === 0) return 'In Progress';
    return 'Queued';
}

function planning_status_badge($status) {
    $s = (string)$status;
    $class = 'info';
    if ($s === 'Completed') $class = 'success';
    elseif ($s === 'On Hold') $class = 'warning';
    elseif ($s === 'Queued') $class = 'consumed';
    return '<span class="badge badge-' . $class . '">' . e($s) . '</span>';
}

  function planning_board_status_badge($status) {
    $s = trim((string)$status);
    if ($s === '') $s = 'Pending';
    $class = 'consumed';
    if (strcasecmp($s, 'Running') === 0) $class = 'info';
    elseif (strcasecmp($s, 'Completed') === 0) $class = 'success';
    elseif (stripos($s, 'Hold') === 0) $class = 'warning';
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
      if (array_key_exists($k, $extra)) {
        $vals[$k] = (string)$extra[$k];
        continue;
      }
      if ($k === 'name') $vals[$k] = (string)($row['job_name'] ?? '');
      elseif ($k === 'printing_planning') $vals[$k] = (string)($extra['printing_planning'] ?? ($row['status'] ?? 'Pending'));
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
      $rowValues['printing_planning'] = $statusRaw;

      $status = planning_normalize_status($statusRaw);
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
        $del = $db->prepare("DELETE FROM planning WHERE id = ? AND department = ?");
        $del->bind_param('is', $id, $department);
        $ok = $del->execute();
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Row deleted.' : 'Delete failed.']);
    }

    if ($action === 'create_row') {
      $rowValues = json_decode((string)($_POST['row_values_json'] ?? '{}'), true);
      if (!is_array($rowValues)) $rowValues = [];

      $jobName = trim((string)($rowValues['name'] ?? $_POST['job_name'] ?? ''));
        if ($jobName === '') planning_json_response(['ok' => false, 'message' => 'Job name is required.'], 400);

      $statusRaw = trim((string)($rowValues['printing_planning'] ?? $_POST['status'] ?? 'Pending'));
      if ($statusRaw === '') $statusRaw = 'Pending';
      $rowValues['printing_planning'] = $statusRaw;

      $status = planning_normalize_status($statusRaw);
      $priority = (string)($rowValues['priority'] ?? $_POST['priority'] ?? 'Normal');
        if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

      $machine = trim((string)($rowValues['machine'] ?? $_POST['machine'] ?? ''));
      $operator = trim((string)($rowValues['operator_name'] ?? $_POST['operator_name'] ?? ''));
      $notes = trim((string)($rowValues['remarks'] ?? $_POST['notes'] ?? ''));
      $scheduled = planning_try_parse_date($rowValues['dispatch_date'] ?? $_POST['scheduled_date'] ?? '');

      $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $ins = $db->prepare("INSERT INTO planning (sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by) VALUES (NULL,?,?,?,NULLIF(?, ''),?,?,?,?,?,?)");
        $uid = (int)($_SESSION['user_id'] ?? 0);
      $ins->bind_param('sssssssssi', $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
        $ok = $ins->execute();
        planning_json_response(['ok' => (bool)$ok, 'message' => $ok ? 'Row created.' : 'Create failed.']);
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

        $ins = $db->prepare("INSERT INTO planning (sales_order_id, job_name, machine, operator_name, scheduled_date, status, priority, notes, department, extra_data, created_by) VALUES (NULL,?,?,?,NULLIF(?, ''),?,?,?,?,?,?)");
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
                'printing_planning' => ['printing_planning', 'status'],
                'plate_no' => ['plate_no', 'plate no'],
                'name' => ['name', 'job name', 'job_name'],
                'size' => ['size'],
                'repeat' => ['repeat'],
                'material' => ['material'],
                'paper_size' => ['paper_size', 'paper size'],
                'die' => ['die'],
                'allocate_mtrs' => ['allocate_mtrs', 'mtrs', 'allocate mtrs'],
                'qty_pcs' => ['qty_pcs', 'qty'],
                'core_size' => ['core_size', 'core', 'core size'],
                'qty_per_roll' => ['qty_per_roll', 'qty/roll', 'qty per roll'],
                'roll_direction' => ['roll_direction', 'direction', 'roll direction'],
                'remarks' => ['remarks', 'notes']
            ];

            foreach ($aliases as $k => $tryKeys) {
                $v = planning_get_any_key($normRow, $tryKeys);
                if ($v !== null) $rowValues[$k] = (string)$v;
            }

            $jobName = trim((string)($rowValues['name'] ?? ''));
            if ($jobName === '') continue;

            $machine = (string)(planning_get_any_key($normRow, ['machine']) ?? '');
            $operator = (string)(planning_get_any_key($normRow, ['operator_name', 'operator']) ?? '');
            $scheduled = planning_try_parse_date($rowValues['dispatch_date'] ?? (planning_get_any_key($normRow, ['scheduled_date', 'scheduled date']) ?? ''));
            $rowValues['dispatch_date'] = $scheduled;
            $rowValues['order_date'] = planning_try_parse_date($rowValues['order_date'] ?? '');

            $statusIn = (string)($rowValues['printing_planning'] ?? 'Pending');
            $status = planning_normalize_status($statusIn);
            $rowValues['printing_planning'] = trim($statusIn) !== '' ? (string)$statusIn : 'Pending';

            $priority = (string)(planning_get_any_key($normRow, ['priority']) ?? 'Normal');
            if (!in_array($priority, $priorityList, true)) $priority = 'Normal';

            $notes = trim((string)($rowValues['remarks'] ?? ''));
            $extraJson = json_encode($rowValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ins->bind_param('sssssssssi', $jobName, $machine, $operator, $scheduled, $status, $priority, $notes, $department, $extraJson, $uid);
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
          <?php foreach ($columns as $c): ?>
            <th draggable="true" data-col-key="<?= e($c['key']) ?>"><?= e($c['label']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($columns) + 1 ?>" class="table-empty"><i class="bi bi-inbox"></i>No planning jobs found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $r): ?>
          <?php
            $rowVals = planning_extract_row_values($r, $columns, $idx);
            $rowStatusVal = 'Pending';
            foreach ($columns as $_sc) { if ($_sc['type'] === 'Status') { $rowStatusVal = (string)($rowVals[$_sc['key']] ?? 'Pending'); break; } }
            $vl_ = strtolower(trim($rowStatusVal));
            $rowSCls = (substr($vl_, 0, 4) === 'hold') ? 'hold' : (($vl_ === 'running') ? 'running' : (($vl_ === 'completed') ? 'completed' : 'pending'));
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
            <?php foreach ($columns as $c): ?>
              <?php
                $k = $c['key'];
                $v = (string)($rowVals[$k] ?? '');
              ?>
              <td data-key="<?= e($k) ?>" data-type="<?= e($c['type']) ?>">
                <?php if ($c['type'] === 'Status'): ?>
                  <span class="cell-display status-pill status-pill-<?= strtolower(preg_replace('/[^a-z]/i','',str_replace(' ','_',strtolower($v ?: 'Pending')))) ?>"><?= e($v ?: 'Pending') ?></span>
                  <select class="cell-input cell-select-status form-control" style="display:none">
                    <?php foreach ($statusList as $s): ?><option value="<?= e($s) ?>"<?= $v === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <span class="cell-display"><?= e($v !== '' ? $v : '—') ?></span>
                  <input class="cell-input form-control" type="text" value="<?= e($v) ?>" style="display:none">
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
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
      <div class="planning-grid">
        <?php foreach ($columns as $c): ?>
          <?php if ($c['key'] === 'sn') continue; ?>
          <div<?= $c['key'] === 'remarks' ? ' style="grid-column:1 / -1"' : '' ?>>
            <label><?= e($c['label']) ?><?= $c['key'] === 'name' ? ' *' : '' ?></label>
            <?php if ($c['type'] === 'Status'): ?>
              <select class="form-control" name="<?= e($c['key']) ?>" <?= $c['key'] === 'name' ? 'required' : '' ?>>
                <?php foreach ($statusList as $s): ?><option value="<?= e($s) ?>" <?= $s === 'Pending' ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input class="form-control" name="<?= e($c['key']) ?>" type="<?= $c['type'] === 'Number' ? 'number' : ($c['type'] === 'Date' ? 'date' : 'text') ?>" <?= $c['key'] === 'name' ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
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
  var fileInput = document.getElementById('excel-file');
  var columns = <?= json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var csrfToken = document.querySelector('#csrf-form [name="csrf_token"]').value;

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
    var cls = vl === 'running' ? 'row-s-running' : vl === 'completed' ? 'row-s-completed' : vl.indexOf('hold') === 0 ? 'row-s-hold' : 'row-s-pending';
    tr.classList.remove('row-s-running','row-s-completed','row-s-hold','row-s-pending');
    tr.classList.add(cls);
  }

  function applyStatusStyle(sel) {
    var v = String(sel.value || '').trim().toLowerCase();
    var cls = v === 'running' ? 'ssel-running' : v === 'completed' ? 'ssel-completed' : v.indexOf('hold') === 0 ? 'ssel-hold' : 'ssel-pending';
    sel.className = 'cell-input cell-select-status form-control ' + cls;
    var tr = sel.closest('tr[data-id]');
    if (tr) applyRowStatus(tr, sel.value);
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

  boardTable.addEventListener('click', function(e){
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;

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
            var pilClass = vl === 'running' ? 'running' : vl === 'completed' ? 'completed' : vl.indexOf('hold') === 0 ? 'hold' : 'pending';
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
    if (e.target.closest('.btn-edit,.btn-save,.btn-cancel,.btn-delete')) return;
    var tr = e.target.closest('tr[data-id]');
    if (!tr || tr.classList.contains('is-editing')) return;
    setRowEditMode(tr, true);
  });

  function openModal(el) { el.style.display = 'flex'; }
  function closeModal(el) { el.style.display = 'none'; }

  document.getElementById('btn-add-row').addEventListener('click', function(){ openModal(addModal); });
  document.getElementById('btn-board-config').addEventListener('click', function(){
    renderConfigList();
    openModal(cfgModal);
  });

  document.querySelectorAll('.modal-close').forEach(function(btn){
    btn.addEventListener('click', function(){
      closeModal(btn.closest('.planning-modal'));
    });
  });

  [addModal, cfgModal].forEach(function(m){
    m.addEventListener('click', function(e){ if (e.target === m) closeModal(m); });
  });

  document.getElementById('form-add-row').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(e.target);
    var payload = { action: 'create_row' };
    var rowValues = {};
    fd.forEach(function(v, k){ payload[k] = v; });
    fd.forEach(function(v, k){ rowValues[k] = v; });
    payload.row_values_json = JSON.stringify(rowValues);

    postAction(payload).then(function(){
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
.sticky-col {
  position: sticky;
  left: 0;
  z-index: 4;
  background: #fff;
  min-width: 145px;
}
.planning-board-table thead .sticky-col { z-index: 6; }

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
.ssel-pending  { background: #94a3b8 !important; color: #fff !important; }
/* ── Column header drag ── */
.planning-board-table th[data-col-key] { cursor: grab; user-select: none; }
.planning-board-table th[data-col-key].col-drag-over { background: #dbeafe; outline: 2px dashed #3b82f6; outline-offset: -2px; }
.planning-board-table th[data-col-key].col-dragging { opacity: .4; }
/* ── Row background colour by status ── */
.row-s-running td, .row-s-running .sticky-col   { background: #eff6ff !important; }
.row-s-completed td, .row-s-completed .sticky-col{ background: #f0fdf4 !important; }
.row-s-hold td, .row-s-hold .sticky-col          { background: #fff5f5 !important; }
.row-s-pending td, .row-s-pending .sticky-col    { background: #fff    !important; }
/* ── Status pill badge ── */
.status-pill { display: inline-block; font-size: .75rem; font-weight: 700; padding: 3px 14px; border-radius: 4px; letter-spacing: .03em; text-transform: uppercase; white-space: nowrap; color: #fff; }
.status-pill-running          { background: #3b82f6; }
.status-pill-completed        { background: #22c55e; }
.status-pill-hold,
.status-pill-holdforpayment,
.status-pill-holdforapproval  { background: #ef4444; }
.status-pill-pending          { background: #94a3b8; }
/* Double-click hint cursor on data cells */
.planning-board-table tbody tr[data-id]:not(.is-editing) td:not(.sticky-col) { cursor: pointer; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
