<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
// Allow wrapper pages to override the redirect URL — must be defined BEFORE _common.php
if (isset($dieToolingRedirectUrlOverride) && trim((string)$dieToolingRedirectUrlOverride) !== '') {
  function dieToolingRedirectUrl($mode = 'master') {
    global $dieToolingRedirectUrlOverride;
    $url = trim((string)$dieToolingRedirectUrlOverride);
    if (BASE_URL !== '' && strpos($url, BASE_URL) === 0) return $url;
    return BASE_URL . $url;
  }
}
require_once __DIR__ . '/_common.php';

$db = getDB();
ensureDieToolingSchema($db);

$mode = (($_GET['mode'] ?? 'master') === 'design') ? 'design' : 'master';
$isDesignMode = $mode === 'design';
$moduleLabel = isset($aniloxDataLabelOverride) && trim((string)$aniloxDataLabelOverride) !== ''
  ? trim((string)$aniloxDataLabelOverride)
  : dieToolingModuleLabel();
$pageTitle = isset($aniloxDataPageTitleOverride) && trim((string)$aniloxDataPageTitleOverride) !== ''
  ? trim((string)$aniloxDataPageTitleOverride)
  : ($isDesignMode ? 'Design ' . $moduleLabel : 'Master ' . $moduleLabel);
$isEmbedded = !empty($aniloxDataEmbedded);
$moduleSlug = dieToolingModuleSlug();
$tableName = dieToolingTableName();
$sessionKey = 'import_preview_' . str_replace('-', '_', $moduleSlug);
$columns = dieToolingColumns();
$importColumns = dieToolingImportColumns();
$quickFilters = dieToolingQuickFilters();
$csrf = generateCSRF();

function dieToolingNormalizeHeaderKey($value) {
  $value = mb_strtolower(trim((string)$value), 'UTF-8');
  $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
  $value = preg_replace('/\s+/', ' ', $value);
  return trim($value);
}

function dieToolingAutoMapHeaders(array $headers) {
  $synonyms = dieToolingColumnSynonyms();
  $normalizedHeaders = [];
  foreach ($headers as $header) {
    $normalizedHeaders[(string)$header] = dieToolingNormalizeHeaderKey($header);
  }

  $mapping = [];
  foreach ($synonyms as $key => $needles) {
    $mapping[$key] = '';
    foreach ($normalizedHeaders as $rawHeader => $normalizedHeader) {
      foreach ((array)$needles as $needle) {
        $needle = dieToolingNormalizeHeaderKey($needle);
        if ($needle !== '' && strpos($normalizedHeader, $needle) !== false) {
          $mapping[$key] = (string)$rawHeader;
          break 2;
        }
      }
    }
  }

  return $mapping;
}

function dieToolingPrepareInsert(mysqli $db, $tableName, array $keys) {
  $columnsSql = implode(', ', $keys);
  $placeholders = implode(', ', array_fill(0, count($keys), '?'));
  return $db->prepare("INSERT INTO {$tableName} ({$columnsSql}) VALUES ({$placeholders})");
}

function dieToolingPrepareUpdate(mysqli $db, $tableName, array $keys) {
  $setSql = implode(', ', array_map(function ($key) {
    return $key . '=?';
  }, $keys));
  return $db->prepare("UPDATE {$tableName} SET {$setSql} WHERE id=?");
}

function dieToolingDisplayValue($value) {
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  return dieToolingFormatDisplayNumber($value);
}

if (isset($_GET['clear_import']) && !$isDesignMode) {
  unset($_SESSION[$sessionKey]);
  redirect(dieToolingRedirectUrl($mode));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please retry.');
    redirect(dieToolingRedirectUrl($mode));
  }

  $action = trim((string)($_POST['action'] ?? ''));
  $columnKeys = array_keys($columns);
  $importColumnKeys = array_keys($importColumns);

  if ($action === 'add_record') {
    if (!$isDesignMode) {
      setFlash('error', 'Manual add is disabled in Master ' . $moduleLabel . '. Use import mapping instead.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $data = dieToolingNormalizePayload($_POST);
    $stmt = dieToolingPrepareInsert($db, $tableName, $columnKeys);
    if ($stmt) {
      $values = [];
      foreach ($columnKeys as $key) {
        $values[] = $data[$key] ?? '';
      }
      $stmt->bind_param(str_repeat('s', count($values)), ...$values);
      if ($stmt->execute()) {
        setFlash('success', $moduleLabel . ' entry added successfully.');
      } else {
        setFlash('error', 'Unable to add entry.');
      }
      $stmt->close();
    } else {
      setFlash('error', 'Unable to prepare insert query.');
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'update_record') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      setFlash('error', 'Invalid row id for update.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $data = dieToolingNormalizePayload($_POST);
    $stmt = dieToolingPrepareUpdate($db, $tableName, $columnKeys);
    if ($stmt) {
      $values = [];
      foreach ($columnKeys as $key) {
        $values[] = $data[$key] ?? '';
      }
      $values[] = $id;
      $stmt->bind_param(str_repeat('s', count($columnKeys)) . 'i', ...$values);
      if ($stmt->execute()) {
        setFlash('success', $moduleLabel . ' entry updated successfully.');
      } else {
        setFlash('error', 'Unable to update entry.');
      }
      $stmt->close();
    } else {
      setFlash('error', 'Unable to prepare update query.');
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'delete_record') {
    if ($isDesignMode) {
      setFlash('error', 'Delete is disabled in Design ' . $moduleLabel . '.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM {$tableName} WHERE id=?");
      if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
      }
      setFlash('success', $moduleLabel . ' entry deleted.');
    } else {
      setFlash('error', 'Invalid row id for delete.');
    }
    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'bulk_delete_records') {
    if ($isDesignMode) {
      setFlash('error', 'Bulk delete is disabled in Design ' . $moduleLabel . '.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $selectedIds = array_values(array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [])), function ($id) {
      return $id > 0;
    }));
    if (!$selectedIds) {
      setFlash('error', 'Please select at least one row to delete.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $db->prepare("DELETE FROM {$tableName} WHERE id IN ({$placeholders})");
    if ($stmt) {
      $stmt->bind_param(str_repeat('i', count($selectedIds)), ...$selectedIds);
      if ($stmt->execute()) {
        setFlash('success', 'Selected ' . strtolower($moduleLabel) . ' rows deleted: ' . $stmt->affected_rows);
      } else {
        setFlash('error', 'Unable to delete selected rows.');
      }
      $stmt->close();
    } else {
      setFlash('error', 'Unable to prepare bulk delete query.');
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'import_preview') {
    if ($isDesignMode) {
      setFlash('error', 'Import is disabled in Design ' . $moduleLabel . '.');
      redirect(dieToolingRedirectUrl($mode));
    }

    if (!isset($_FILES['import_file']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
      setFlash('error', 'Please choose a file to upload.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $fileName = (string)($_FILES['import_file']['name'] ?? '');
    $tmpPath = (string)($_FILES['import_file']['tmp_name'] ?? '');

    try {
      $sheetRows = dieToolingParseSpreadsheet($tmpPath, $fileName);
      if (!$sheetRows || count($sheetRows) < 2) {
        throw new RuntimeException('File must have header row and at least one data row.');
      }

      $headers = array_map(function ($value) {
        return trim((string)$value);
      }, (array)$sheetRows[0]);

      $rows = [];
      for ($index = 1; $index < count($sheetRows); $index++) {
        $line = (array)$sheetRows[$index];
        $allBlank = true;
        foreach ($line as $cell) {
          if (trim((string)$cell) !== '') {
            $allBlank = false;
            break;
          }
        }
        if ($allBlank) {
          continue;
        }
        $rows[] = array_values($line);
      }

      if (!$rows) {
        throw new RuntimeException('No data rows found in file.');
      }

      $_SESSION[$sessionKey] = [
        'source_name' => $fileName,
        'headers' => $headers,
        'rows' => $rows,
      ];

      setFlash('success', 'File uploaded successfully. Please map columns and apply import.');
    } catch (Throwable $error) {
      setFlash('error', $error->getMessage());
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'import_apply') {
    if ($isDesignMode) {
      setFlash('error', 'Import is disabled in Design ' . $moduleLabel . '.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $preview = $_SESSION[$sessionKey] ?? null;
    if (!$preview || !is_array($preview)) {
      setFlash('error', 'Import preview not found. Upload file again.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $headers = array_map(function ($header) {
      return trim((string)$header);
    }, (array)($preview['headers'] ?? []));
    $headerIndex = [];
    foreach ($headers as $index => $name) {
      $headerIndex[$name] = $index;
    }

    $autoMapping = dieToolingAutoMapHeaders($headers);
    $mapping = (array)($_POST['mapping'] ?? []);
    $rows = (array)($preview['rows'] ?? []);
    $clearExisting = !empty($_POST['clear_existing']);

    if ($clearExisting) {
      $db->query("DELETE FROM {$tableName}");
    }

    $stmt = dieToolingPrepareInsert($db, $tableName, $importColumnKeys);
    if (!$stmt) {
      setFlash('error', 'Unable to prepare import insert statement.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $inserted = 0;
    foreach ($rows as $row) {
      $payload = [];
      foreach ($importColumns as $key => $label) {
        $mappedHeader = trim((string)($mapping[$key] ?? ''));
        if ($mappedHeader === '') {
          $mappedHeader = trim((string)($autoMapping[$key] ?? ''));
        }

        $value = '';
        if ($mappedHeader !== '' && isset($headerIndex[$mappedHeader])) {
          $value = trim((string)($row[(int)$headerIndex[$mappedHeader]] ?? ''));
        }
        if ($key !== 'sl_no' && $value === '') {
          $value = 'NA';
        }
        $payload[$key] = dieToolingCleanText($value, 190);
      }

      $values = [];
      foreach ($importColumnKeys as $key) {
        $values[] = $payload[$key] ?? '';
      }
      $stmt->bind_param(str_repeat('s', count($values)), ...$values);
      if ($stmt->execute()) {
        $inserted++;
      }
    }
    $stmt->close();
    unset($_SESSION[$sessionKey]);

    setFlash('success', 'Import completed. Rows inserted: ' . $inserted);
    redirect(dieToolingRedirectUrl($mode));
  }
}

$importPreview = !$isDesignMode ? ($_SESSION[$sessionKey] ?? null) : null;
$importAutoMapping = [];
if (is_array($importPreview) && !empty($importPreview['headers'])) {
  $importAutoMapping = dieToolingAutoMapHeaders((array)$importPreview['headers']);
}

$editingId = (int)($_GET['edit_id'] ?? 0);
$editingRow = null;
if ($editingId > 0) {
  $stmt = $db->prepare("SELECT * FROM {$tableName} WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $editingRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
  }
}

$rows = [];
$res = $db->query("SELECT * FROM {$tableName} ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$fieldSuggestions = [];
foreach (array_keys($columns) as $columnKey) {
  $fieldSuggestions[$columnKey] = [];
}
foreach ($rows as $suggestionRow) {
  foreach (array_keys($columns) as $columnKey) {
    $value = trim((string)($suggestionRow[$columnKey] ?? ''));
    if ($value === '' || strtoupper($value) === 'NA') {
      continue;
    }
    $normalized = mb_strtolower($value, 'UTF-8');
    if (!isset($fieldSuggestions[$columnKey][$normalized])) {
      $fieldSuggestions[$columnKey][$normalized] = $value;
    }
  }
}
foreach (array_keys($columns) as $columnKey) {
  $fieldSuggestions[$columnKey] = array_values($fieldSuggestions[$columnKey]);
  usort($fieldSuggestions[$columnKey], function ($left, $right) {
    return strnatcasecmp((string)$left, (string)$right);
  });
}

// Compute total stock
$totalStock = 0;
foreach ($rows as $r) {
  $totalStock += (int)($r['stock_qty'] ?? 0);
}

$flash = getFlash();
$exportBase = BASE_URL . '/modules/' . $moduleSlug . '/export.php';
$columnCount = count($columns) + ($isDesignMode ? 2 : 3);
$quickFiltersJson = json_encode(array_values($quickFilters), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (!$isEmbedded) {
  include __DIR__ . '/../../includes/header.php';
}
?>

<style>
.module-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.module-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:10px;padding:8px 12px;font-size:.78rem;font-weight:700;text-decoration:none;background:#fff;color:var(--text-main)}
.module-btn.green{color:#15803d;border-color:#86efac}.module-btn.blue{color:#1d4ed8;border-color:#93c5fd}.module-btn.orange{color:#c2410c;border-color:#fdba74}.module-btn.red{color:#b91c1c;border-color:#fca5a5}.module-btn[disabled]{opacity:.45;cursor:not-allowed;pointer-events:none}
.module-bulk-bar{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 10px;padding:10px 12px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2}.module-bulk-meta{font-size:.82rem;color:#991b1b;font-weight:700}.bulk-check-col{width:42px;text-align:center}
.qf{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:10px;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;margin:10px 0 12px}.qf-item{display:flex;flex-direction:column;gap:3px;min-width:120px;flex:1;justify-content:center}.qf-item label{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}.qf-item input,.qf-item select{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.8rem;background:#fff}.qf-item input:focus,.qf-item select:focus{outline:none;border-color:#86efac;box-shadow:0 0 0 3px rgba(34,197,94,.1)}.qf-actions{display:flex;align-items:center;gap:8px;min-width:max-content;padding-top:18px}.qf-reset{height:36px;display:inline-flex;align-items:center;justify-content:center;padding:0 14px;border-radius:8px;background:#f97316;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap}
.qf-picker{position:relative}.qf-picker-btn{height:36px;width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff;font-size:.8rem;color:var(--text-main);cursor:pointer;text-align:left}.qf-picker-btn .muted{color:#94a3b8}.qf-picker-btn.active{border-color:#fdba74;background:#fff7ed}.qf-popup{display:none;position:fixed;z-index:240;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.18);width:260px;overflow:hidden}.qf-popup.open{display:block}.qf-popup-head{padding:10px;border-bottom:1px solid #f1f5f9}.qf-popup-search{width:100%;height:34px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem;background:#f8fafc}.qf-popup-list{max-height:220px;overflow-y:auto;padding:6px 0}.qf-popup-item{display:flex;align-items:center;padding:7px 10px;font-size:.78rem;cursor:pointer}.qf-popup-item:hover{background:#f8fafc}.qf-popup-item.active{background:#fff7ed;color:#ea580c;font-weight:700}
.col-filter-wrap{position:relative;display:inline-flex;align-items:center}.col-filter-btn{background:none;border:none;cursor:pointer;padding:2px 4px;color:#94a3b8;font-size:10px;margin-left:4px;border-radius:4px;transition:all .12s}.col-filter-btn:hover,.col-filter-btn.active{color:#16a34a;background:rgba(34,197,94,.12)}.col-filter-btn .filter-count{display:none;position:absolute;top:-5px;right:-6px;background:#ef4444;color:#fff;border-radius:8px;font-size:7px;padding:1px 3px;line-height:1;min-width:11px;text-align:center}.col-filter-btn.active .filter-count{display:block}.cfp{display:none;position:fixed;z-index:260;width:290px;max-width:90vw;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.18);overflow:hidden}.cfp.open{display:block}.cfp-head{padding:10px;border-bottom:1px solid #f1f5f9;background:#f8fafc}.cfp-search{width:100%;height:32px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem}.cfp-select-all{display:flex;align-items:center;gap:6px;font-size:.75rem;margin-top:8px;color:#64748b}.cfp-list{max-height:220px;overflow:auto;padding:8px 10px;display:flex;flex-direction:column;gap:6px}.cfp-item{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#334155}.cfp-foot{display:flex;justify-content:flex-end;gap:8px;padding:10px;border-top:1px solid #f1f5f9;background:#fff}.cfp-foot button{height:30px;border-radius:8px;border:none;padding:0 12px;font-size:.76rem;font-weight:700;cursor:pointer}.cfp-foot .ok{background:#16a34a;color:#fff}.cfp-foot .apply{background:#0f172a;color:#fff}
.module-table{border:2px solid #1e3a8a;border-radius:10px;overflow:hidden;border-collapse:separate;border-spacing:0}
.module-table thead th{background:#1e3a8a;color:#fff;border:1px solid #1e40af;font-weight:700;white-space:nowrap;padding:10px 14px;font-size:.82rem;text-transform:uppercase;letter-spacing:.04em}
.module-table tbody td{border:1px solid #cbd5e1;padding:9px 14px;font-size:.84rem}
.module-table tbody tr:nth-child(even) td{background:#f1f5f9}
.module-table tbody tr:hover td{background:#dbeafe}
.table-responsive{max-width:800px;margin:0 auto}
@media (max-width:980px){.qf{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000}.modal-card{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:95%;max-width:1100px;max-height:90vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 20px 40px rgba(0,0,0,.2)}.modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:0;background:#0f172a;color:#fff;border-radius:14px 14px 0 0}.modal-body-pad{padding:14px}.modal-form-section{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.modal-form-label{font-size:.76rem;font-weight:700;color:#475569}.modal-form-input{height:38px;border:1px solid var(--border);border-radius:8px;padding:0 10px}.form-grid-2{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px}.form-group{display:flex;flex-direction:column;gap:6px}.form-group label{font-size:.76rem;font-weight:700;color:#475569}.form-group input,.form-group select{height:38px;border:1px solid var(--border);border-radius:8px;padding:0 10px}.form-actions{display:flex;justify-content:flex-end;gap:8px}.col-span-all{grid-column:1/-1}
@media (max-width:900px){.form-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.modal-form-section{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:768px){.qf{display:grid;grid-template-columns:1fr;margin:8px 0 10px}.qf-item{min-width:auto}.qf-actions{padding-top:10px;width:100%}.qf-reset{width:100%;justify-content:center}.modal-card{width:90%;max-width:500px;max-height:95vh}.form-grid-2{grid-template-columns:1fr;padding:10px}.anilox-summary{gap:8px}.anilox-summary .sum-card{min-width:auto;padding:8px 12px;font-size:.75rem}}@media (max-width:640px){.form-grid-2{grid-template-columns:1fr;padding:8px;gap:8px}.modal-form-section{grid-template-columns:1fr;gap:8px}.modal-body-pad{padding:10px 14px}.modal-head{padding:10px 14px}.modal-form-label{font-size:.6rem;margin-bottom:3px}.modal-form-input{height:36px;font-size:.7rem}.qf{padding:8px;gap:6px;margin:6px 0 8px}.qf-item{gap:2px}.qf-item label{font-size:.55rem}.qf-item input,.qf-item select{height:32px;padding:0 8px;font-size:.7rem}.col-filter-btn{font-size:8px}.qf-popup,.cfp{width:90vw;max-width:280px}.anilox-summary .sum-card{font-size:.7rem;padding:6px 10px}}
.anilox-summary{display:flex;gap:12px;margin:10px auto;flex-wrap:wrap;max-width:800px}
.anilox-summary .sum-card{flex:1;min-width:120px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 16px;text-align:center}
.anilox-summary .sum-card .sum-label{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}
.anilox-summary .sum-card .sum-value{font-size:1.6rem;font-weight:900;color:#15803d;margin-top:2px}
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <span class="card-title"><?= e($pageTitle) ?></span>
    <div class="module-actions">
      <?php if ($isDesignMode): ?>
        <button type="button" class="module-btn green" onclick="openAddModal()"><i class="bi bi-plus-circle"></i> Add New</button>
      <?php endif; ?>
      <?php if (!$isDesignMode): ?>
        <button type="button" class="module-btn red" id="bulkDeleteTrigger" disabled><i class="bi bi-trash3"></i> Bulk Delete</button>
      <?php endif; ?>
      <a class="module-btn blue" href="<?= e($exportBase) ?>?mode=<?= e($mode) ?>&format=excel"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
      <a class="module-btn orange" target="_blank" href="<?= e($exportBase) ?>?mode=<?= e($mode) ?>&format=pdf"><i class="bi bi-printer"></i> Print / PDF</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:10px;"><?= e($flash['message']) ?></div>
  <?php endif; ?>

  <!-- Summary -->
  <div class="anilox-summary">
    <div class="sum-card">
      <div class="sum-label">Total Records</div>
      <div class="sum-value"><?= count($rows) ?></div>
    </div>
    <div class="sum-card">
      <div class="sum-label">Total Stock (QNTY)</div>
      <div class="sum-value"><?= $totalStock ?></div>
    </div>
  </div>

  <?php if (!$isDesignMode): ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:12px;background:#f8fafc;margin-bottom:10px;">
      <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="import_preview">
        <input type="file" name="import_file" accept=".csv,.xlsx" required>
        <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload &amp; Preview Mapping</button>
        <?php if ($importPreview): ?>
          <a class="btn btn-ghost" href="<?= dieToolingRedirectUrl($mode) ?>?clear_import=1"><i class="bi bi-x-lg"></i> Clear Preview</a>
        <?php endif; ?>
      </form>
      <?php if ($importPreview): ?>
        <div style="margin-top:10px;padding:10px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
          <div style="font-weight:600;">File: <?= e((string)$importPreview['source_name']) ?> | Rows: <?= count((array)$importPreview['rows']) ?></div>
          <button class="btn btn-primary btn-sm" type="button" onclick="openImportMapModal()"><i class="bi bi-diagram-3"></i> Open Mapping Window</button>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span class="card-title"><?= e($moduleLabel) ?> Table</span>
    <span style="font-size:.82rem;color:var(--text-muted);">Total Rows: <?= count($rows) ?></span>
  </div>

  <?php if (!$isDesignMode): ?>
    <div class="module-bulk-bar no-print">
      <div class="module-bulk-meta"><span id="bulkSelectedCount">0</span> row selected</div>
      <div style="font-size:.78rem;color:#7f1d1d;">Select rows from table, then use Bulk Delete.</div>
    </div>
    <form method="POST" id="bulkDeleteForm" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="bulk_delete_records">
      <div id="bulkDeleteIds"></div>
    </form>
  <?php endif; ?>

  <div class="qf no-print" id="moduleQuickFilter">
    <div class="qf-item"><label>Global Search</label><input type="text" id="qf-search" placeholder="Search everything..."></div>
    <?php foreach ($quickFilters as $filter): ?>
      <?php if (($filter['type'] ?? '') === 'text'): ?>
        <div class="qf-item">
          <label><?= e((string)$filter['label']) ?></label>
          <input type="text" id="qf-<?= e((string)$filter['key']) ?>" placeholder="<?= e((string)($filter['placeholder'] ?? 'Search...')) ?>">
        </div>
      <?php elseif (($filter['type'] ?? '') === 'pick'): ?>
        <div class="qf-item">
          <label><?= e((string)$filter['label']) ?></label>
          <div class="qf-picker">
            <input type="hidden" id="qf-<?= e((string)$filter['key']) ?>" value="">
            <button type="button" class="qf-picker-btn" data-qf-picker="<?= e((string)$filter['key']) ?>"><span class="muted"><?= e((string)($filter['allLabel'] ?? 'All')) ?></span><i class="bi bi-chevron-down"></i></button>
            <div class="qf-popup" id="qf-popup-<?= e((string)$filter['key']) ?>"></div>
          </div>
        </div>
      <?php elseif (($filter['type'] ?? '') === 'number_range'): ?>
        <div class="qf-item"><label><?= e((string)$filter['label']) ?> Min</label><input type="number" step="0.01" id="qf-<?= e((string)$filter['key']) ?>-min" placeholder="<?= e((string)($filter['minPlaceholder'] ?? 'Min')) ?>"></div>
        <div class="qf-item"><label><?= e((string)$filter['label']) ?> Max</label><input type="number" step="0.01" id="qf-<?= e((string)$filter['key']) ?>-max" placeholder="<?= e((string)($filter['maxPlaceholder'] ?? 'Max')) ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="qf-actions"><button type="button" class="qf-reset" onclick="resetQuickFilters()">Reset</button></div>
  </div>

  <div class="table-responsive">
    <table class="table module-table" id="moduleDataTable">
      <thead>
        <tr>
          <?php if (!$isDesignMode): ?>
            <th class="bulk-check-col no-print"><input type="checkbox" id="selectAllRows"></th>
          <?php endif; ?>
          <th><span class="col-filter-wrap">SL No.<button type="button" class="col-filter-btn no-print" data-filter-field="__sl_no__"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-__sl_no__"></div></span></th>
          <?php foreach ($columns as $key => $label): ?>
            <th><span class="col-filter-wrap"><?= e($label) ?><button type="button" class="col-filter-btn no-print" data-filter-field="<?= e($key) ?>"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-<?= e($key) ?>"></div></span></th>
          <?php endforeach; ?>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= (int)$columnCount ?>" style="text-align:center;color:var(--text-muted);padding:24px;">No <?= e(strtolower($moduleLabel)) ?> records found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $index => $row): ?>
          <tr>
            <?php if (!$isDesignMode): ?>
              <td class="bulk-check-col no-print"><input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>"></td>
            <?php endif; ?>
            <td data-field="__sl_no__"><?= (string)($index + 1) ?></td>
            <?php foreach ($columns as $key => $label): ?>
              <td data-field="<?= e($key) ?>"><?= e(dieToolingDisplayValue($row[$key] ?? '')) ?></td>
            <?php endforeach; ?>
            <td>
              <a class="btn btn-sm btn-primary" href="<?= dieToolingRedirectUrl($mode) ?><?= strpos(dieToolingRedirectUrl($mode), '?') !== false ? '&' : '?' ?>edit_id=<?= (int)$row['id'] ?>"><i class="bi bi-pencil"></i></a>
              <?php if (!$isDesignMode): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this row?');">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete_record">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($isDesignMode): ?>
<div class="modal-overlay" id="addRecordModal">
  <div class="modal-card">
    <div class="modal-head">
      <strong><i class="bi bi-plus-circle" style="margin-right:8px;"></i> Add <?= e($moduleLabel) ?></strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeAddModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" class="modal-body-pad">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="add_record">
      <div class="modal-form-section">
        <?php foreach ($columns as $key => $label): ?>
          <div class="form-group">
            <label class="modal-form-label"><?= e($label) ?></label>
            <input class="modal-form-input" type="text" name="<?= e($key) ?>" value="" list="field-suggestions-<?= e($key) ?>" autocomplete="off">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="form-actions" style="margin-top:12px;">
        <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
        <button type="submit" class="btn btn-success">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($editingRow): ?>
<div class="modal-overlay" id="editRecordModal" style="display:block;">
  <div class="modal-card">
    <div class="modal-head">
      <strong><i class="bi bi-pencil-square" style="margin-right:8px;"></i> Edit <?= e($moduleLabel) ?></strong>
      <a class="btn btn-sm btn-ghost" href="<?= dieToolingRedirectUrl($mode) ?>"><i class="bi bi-x-lg"></i></a>
    </div>
    <form method="POST" class="modal-body-pad">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update_record">
      <input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>">
      <div class="modal-form-section">
        <?php foreach ($columns as $key => $label): ?>
          <div class="form-group">
            <label class="modal-form-label"><?= e($label) ?></label>
            <input class="modal-form-input" type="text" name="<?= e($key) ?>" value="<?= e((string)($editingRow[$key] ?? '')) ?>" list="field-suggestions-<?= e($key) ?>" autocomplete="off">
          </div>
        <?php endforeach; ?>
      </div>
      <div class="form-actions" style="margin-top:12px;">
        <a class="btn btn-secondary" href="<?= dieToolingRedirectUrl($mode) ?>">Cancel</a>
        <button type="submit" class="btn btn-success">Update</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php foreach ($columns as $key => $label): ?>
  <datalist id="field-suggestions-<?= e($key) ?>">
    <?php foreach ((array)($fieldSuggestions[$key] ?? []) as $value): ?>
      <option value="<?= e((string)$value) ?>"></option>
    <?php endforeach; ?>
  </datalist>
<?php endforeach; ?>

<?php if (!$isDesignMode && $importPreview): ?>
<div id="importMapModal" class="modal-overlay">
  <div class="modal-card">
    <div class="modal-head">
      <strong>Import Mapping - <?= e($moduleLabel) ?></strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeImportMapModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:14px;">
      <div style="margin-bottom:10px;padding:8px 10px;border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;font-size:.86rem;color:#1e3a8a;">Auto-matched columns are pre-selected. Green tick means mapped.</div>
      <form method="POST" class="form-grid-2" id="importMapForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="import_apply">
        <?php foreach ($importColumns as $key => $label): ?>
          <?php $suggested = (string)($importAutoMapping[$key] ?? ''); ?>
          <div class="form-group">
            <label style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <span><?= e($label) ?> Mapping</span>
              <span data-map-tick="<?= e($key) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;font-size:.82rem;font-weight:700;<?= $suggested !== '' ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;' ?>"><?= $suggested !== '' ? '✓' : '○' ?></span>
            </label>
            <select name="mapping[<?= e($key) ?>]" data-map-select="<?= e($key) ?>">
              <option value="">-- Skip --</option>
              <?php foreach ((array)$importPreview['headers'] as $header): ?>
                <?php $header = (string)$header; ?>
                <option value="<?= e($header) ?>" <?= ($suggested !== '' && $suggested === $header) ? 'selected' : '' ?>><?= e($header) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endforeach; ?>
        <div class="col-span-all" style="padding:10px 0 2px;">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:.88rem;padding:8px 12px;border-radius:8px;border:1px solid #fca5a5;background:#fff1f2;color:#b91c1c;font-weight:600;">
            <input type="checkbox" name="clear_existing" value="1" style="width:16px;height:16px;accent-color:#dc2626;"> Clear all existing data before importing
          </label>
        </div>
        <div class="form-actions col-span-all">
          <button type="button" class="btn btn-secondary" onclick="closeImportMapModal()">Cancel</button>
          <button type="submit" class="btn btn-success">Apply Import</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var bulkDeleteTrigger = document.getElementById('bulkDeleteTrigger');
  var bulkDeleteForm = document.getElementById('bulkDeleteForm');
  var bulkDeleteIds = document.getElementById('bulkDeleteIds');
  var bulkSelectedCount = document.getElementById('bulkSelectedCount');
  var selectAllRows = document.getElementById('selectAllRows');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));

  function updateBulkDeleteState() {
    if (!bulkDeleteTrigger || !bulkSelectedCount) return;
    var checked = rowChecks.filter(function (checkbox) { return checkbox.checked; });
    bulkSelectedCount.textContent = checked.length;
    bulkDeleteTrigger.disabled = checked.length === 0;
    if (selectAllRows) {
      selectAllRows.checked = rowChecks.length > 0 && checked.length === rowChecks.length;
      selectAllRows.indeterminate = checked.length > 0 && checked.length < rowChecks.length;
    }
  }

  if (selectAllRows) {
    selectAllRows.addEventListener('change', function () {
      rowChecks.forEach(function (checkbox) { checkbox.checked = selectAllRows.checked; });
      updateBulkDeleteState();
    });
  }

  rowChecks.forEach(function (checkbox) { checkbox.addEventListener('change', updateBulkDeleteState); });
  if (bulkDeleteTrigger && bulkDeleteForm && bulkDeleteIds) {
    bulkDeleteTrigger.addEventListener('click', function () {
      var checked = rowChecks.filter(function (checkbox) { return checkbox.checked; });
      if (!checked.length || !window.confirm('Delete selected rows?')) return;
      bulkDeleteIds.innerHTML = '';
      checked.forEach(function (checkbox) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = checkbox.value;
        bulkDeleteIds.appendChild(input);
      });
      bulkDeleteForm.submit();
    });
    updateBulkDeleteState();
  }

  window.openAddModal = function () {
    var modal = document.getElementById('addRecordModal');
    if (modal) modal.style.display = 'block';
  };
  window.closeAddModal = function () {
    var modal = document.getElementById('addRecordModal');
    if (modal) modal.style.display = 'none';
  };
  window.openImportMapModal = function () {
    var modal = document.getElementById('importMapModal');
    if (modal) modal.style.display = 'block';
  };
  window.closeImportMapModal = function () {
    var modal = document.getElementById('importMapModal');
    if (modal) modal.style.display = 'none';
  };

  document.addEventListener('click', function (event) {
    var addModal = document.getElementById('addRecordModal');
    if (addModal && event.target === addModal) closeAddModal();
    var importModal = document.getElementById('importMapModal');
    if (importModal && event.target === importModal) closeImportMapModal();
  });

  document.querySelectorAll('[data-map-select]').forEach(function (select) {
    var key = select.getAttribute('data-map-select') || '';
    function updateTick() {
      var tick = document.querySelector('[data-map-tick="' + key + '"]');
      if (!tick) return;
      if ((select.value || '').trim() !== '') {
        tick.textContent = '✓';
        tick.style.background = '#dcfce7';
        tick.style.color = '#166534';
        tick.style.border = '1px solid #86efac';
      } else {
        tick.textContent = '○';
        tick.style.background = '#f1f5f9';
        tick.style.color = '#64748b';
        tick.style.border = '1px solid #cbd5e1';
      }
    }
    updateTick();
    select.addEventListener('change', updateTick);
  });

  if (document.getElementById('importMapModal')) {
    openImportMapModal();
  }
})();

(function () {
  var table = document.getElementById('moduleDataTable');
  if (!table) return;

  var qfConfig = <?= $quickFiltersJson ?: '[]' ?>;
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (row) { return row.querySelector('td[data-field]'); });
  var activeColFilters = {};
  var activeColPopup = null;
  var activeQuickPopup = null;

  function norm(value) { return String(value || '').trim().toLowerCase(); }
  function getCell(row, field) {
    return row.querySelector('td[data-field="' + field + '"]');
  }
  function getCellValue(row, field) {
    var cell = getCell(row, field);
    return cell ? String(cell.textContent || '').trim() : '';
  }
  function parseNumeric(value) {
    var cleaned = norm(value).replace(/[^0-9.\-]/g, '');
    var numeric = parseFloat(cleaned);
    return isNaN(numeric) ? null : numeric;
  }

  var qf = { search: document.getElementById('qf-search') };
  qfConfig.forEach(function (filter) {
    if (filter.type === 'text' || filter.type === 'pick') {
      qf[filter.key] = document.getElementById('qf-' + filter.key);
    } else if (filter.type === 'number_range') {
      qf[filter.key + '_min'] = document.getElementById('qf-' + filter.key + '-min');
      qf[filter.key + '_max'] = document.getElementById('qf-' + filter.key + '-max');
    }
  });

  function uniqueFieldValues(field) {
    var set = new Set();
    rows.forEach(function (row) {
      var value = getCellValue(row, field);
      if (value !== '') set.add(value);
    });
    return Array.from(set).sort(function (left, right) { return left.localeCompare(right); });
  }

  function updatePickerLabel(filter) {
    var input = qf[filter.key];
    var button = document.querySelector('.qf-picker-btn[data-qf-picker="' + filter.key + '"]');
    if (!input || !button) return;
    var span = button.querySelector('span');
    var value = String(input.value || '').trim();
    if (value === '') {
      span.textContent = filter.allLabel || 'All';
      span.classList.add('muted');
      button.classList.remove('active');
    } else {
      span.textContent = value;
      span.classList.remove('muted');
      button.classList.add('active');
    }
  }

  function renderQuickPopup(filter) {
    var popup = document.getElementById('qf-popup-' + filter.key);
    if (!popup) return;
    var current = String((qf[filter.key] && qf[filter.key].value) || '').trim();
    var values = uniqueFieldValues(filter.key);
    var html = '<div class="qf-popup-head"><input type="text" class="qf-popup-search" data-qf-popup-search="' + filter.key + '" placeholder="Search..."></div>';
    html += '<div class="qf-popup-list">';
    html += '<div class="qf-popup-item' + (current === '' ? ' active' : '') + '" data-qf-key="' + filter.key + '" data-qf-value="">All</div>';
    values.forEach(function (value) {
      var safe = value.replace(/"/g, '&quot;');
      html += '<div class="qf-popup-item' + (current === value ? ' active' : '') + '" data-qf-key="' + filter.key + '" data-qf-value="' + safe + '">' + value + '</div>';
    });
    html += '</div>';
    popup.innerHTML = html;
  }

  function closeQuickPopup() {
    if (!activeQuickPopup) return;
    var popup = document.getElementById('qf-popup-' + activeQuickPopup);
    if (popup) popup.classList.remove('open');
    activeQuickPopup = null;
  }

  function quickPass(row) {
    var search = norm(qf.search && qf.search.value);
    if (search) {
      var hit = false;
      row.querySelectorAll('td[data-field]').forEach(function (cell) {
        if (!hit && norm(cell.textContent).indexOf(search) !== -1) hit = true;
      });
      if (!hit) return false;
    }

    for (var i = 0; i < qfConfig.length; i++) {
      var filter = qfConfig[i];
      if (filter.type === 'text') {
        var textVal = norm(qf[filter.key] && qf[filter.key].value);
        if (textVal && norm(getCellValue(row, filter.key)).indexOf(textVal) === -1) return false;
      }
      if (filter.type === 'pick') {
        var pickVal = norm(qf[filter.key] && qf[filter.key].value);
        if (pickVal && norm(getCellValue(row, filter.key)) !== pickVal) return false;
      }
      if (filter.type === 'number_range') {
        var min = parseFloat(String((qf[filter.key + '_min'] && qf[filter.key + '_min'].value) || '').trim());
        var max = parseFloat(String((qf[filter.key + '_max'] && qf[filter.key + '_max'].value) || '').trim());
        var current = parseNumeric(getCellValue(row, filter.key));
        if (!isNaN(min) && (current === null || current < min)) return false;
        if (!isNaN(max) && (current === null || current > max)) return false;
      }
    }
    return true;
  }

  function colPass(row) {
    var fields = Object.keys(activeColFilters);
    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      var set = activeColFilters[field];
      if (!set || !set.size) continue;
      var value = norm(getCellValue(row, field));
      var token = value === '' ? '__blank__' : value;
      if (!set.has(token)) return false;
    }
    return true;
  }

  function applyAllFilters() {
    rows.forEach(function (row) {
      row.style.display = (quickPass(row) && colPass(row)) ? '' : 'none';
    });
  }

  function updateFilterButton(field) {
    var button = document.querySelector('.col-filter-btn[data-filter-field="' + field + '"]');
    if (!button) return;
    var count = button.querySelector('.filter-count');
    if (activeColFilters[field]) {
      button.classList.add('active');
      count.textContent = activeColFilters[field].size;
    } else {
      button.classList.remove('active');
      count.textContent = '';
    }
  }

  function buildUnique(field) {
    var values = [];
    var seen = new Set();
    var hasBlank = false;
    rows.forEach(function (row) {
      if (!quickPass(row)) return;
      var value = getCellValue(row, field);
      if (value === '') { hasBlank = true; return; }
      var key = norm(value);
      if (!seen.has(key)) {
        seen.add(key);
        values.push(value);
      }
    });
    values.sort(function (left, right) { return left.localeCompare(right); });
    return { values: values, hasBlank: hasBlank };
  }

  function syncSelectAll(field) {
    var popup = document.getElementById('cfp-' + field);
    if (!popup) return;
    var all = popup.querySelectorAll('.cfp-list input[type=checkbox]');
    var checked = popup.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    var selectAll = popup.querySelector('.cfp-select-all input');
    if (selectAll) selectAll.checked = all.length > 0 && checked.length === all.length;
  }

  function renderColPopup(field) {
    var popup = document.getElementById('cfp-' + field);
    if (!popup) return;
    var built = buildUnique(field);
    var active = activeColFilters[field] || new Set();
    var html = '<div class="cfp-head"><input class="cfp-search" data-cfp-search="' + field + '" placeholder="Search..."><label class="cfp-select-all"><input type="checkbox" data-cfp-select-all="' + field + '"> Select all</label></div><div class="cfp-list">';
    if (built.hasBlank) {
      html += '<label class="cfp-item"><input type="checkbox" value="__blank__" ' + (active.has('__blank__') ? 'checked' : '') + '> <span>(Blank)</span></label>';
    }
    built.values.forEach(function (value) {
      var token = norm(value).replace(/"/g, '&quot;');
      html += '<label class="cfp-item"><input type="checkbox" value="' + token + '" ' + (active.has(norm(value)) ? 'checked' : '') + '> <span>' + value + '</span></label>';
    });
    html += '</div><div class="cfp-foot"><button type="button" class="apply" data-cfp-apply="' + field + '">Apply</button><button type="button" class="ok" data-cfp-ok="' + field + '">OK</button></div>';
    popup.innerHTML = html;
    syncSelectAll(field);
  }

  function closeColPopup() {
    if (!activeColPopup) return;
    var popup = document.getElementById('cfp-' + activeColPopup);
    if (popup) popup.classList.remove('open');
    activeColPopup = null;
  }

  function applyColFilter(field) {
    var popup = document.getElementById('cfp-' + field);
    if (!popup) return;
    var checked = popup.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    if (!checked.length) {
      delete activeColFilters[field];
    } else {
      activeColFilters[field] = new Set(Array.prototype.map.call(checked, function (input) { return input.value; }));
    }
    updateFilterButton(field);
    applyAllFilters();
  }

  window.resetQuickFilters = function () {
    if (qf.search) qf.search.value = '';
    qfConfig.forEach(function (filter) {
      if (filter.type === 'text' || filter.type === 'pick') {
        if (qf[filter.key]) qf[filter.key].value = '';
      } else if (filter.type === 'number_range') {
        if (qf[filter.key + '_min']) qf[filter.key + '_min'].value = '';
        if (qf[filter.key + '_max']) qf[filter.key + '_max'].value = '';
      }
      if (filter.type === 'pick') updatePickerLabel(filter);
    });
    activeColFilters = {};
    document.querySelectorAll('.col-filter-btn').forEach(function (button) {
      button.classList.remove('active');
      var count = button.querySelector('.filter-count');
      if (count) count.textContent = '';
    });
    applyAllFilters();
  };

  if (qf.search) qf.search.addEventListener('input', applyAllFilters);
  qfConfig.forEach(function (filter) {
    if (filter.type === 'text' && qf[filter.key]) {
      qf[filter.key].addEventListener('input', applyAllFilters);
    }
    if (filter.type === 'number_range') {
      if (qf[filter.key + '_min']) qf[filter.key + '_min'].addEventListener('input', applyAllFilters);
      if (qf[filter.key + '_max']) qf[filter.key + '_max'].addEventListener('input', applyAllFilters);
    }
    if (filter.type === 'pick') {
      updatePickerLabel(filter);
      var button = document.querySelector('.qf-picker-btn[data-qf-picker="' + filter.key + '"]');
      if (button) {
        button.addEventListener('click', function () {
          var popup = document.getElementById('qf-popup-' + filter.key);
          if (!popup) return;
          if (activeQuickPopup === filter.key) {
            closeQuickPopup();
            return;
          }
          qfConfig.filter(function (item) { return item.type === 'pick'; }).forEach(function (item) { renderQuickPopup(item); });
          closeQuickPopup();
          var rect = button.getBoundingClientRect();
          popup.style.top = (rect.bottom + 6) + 'px';
          popup.style.left = Math.min(rect.left, window.innerWidth - 280) + 'px';
          popup.classList.add('open');
          activeQuickPopup = filter.key;
        });
      }
    }
  });

  document.querySelectorAll('.col-filter-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var field = button.getAttribute('data-filter-field') || '';
      var popup = document.getElementById('cfp-' + field);
      if (!popup) return;
      if (activeColPopup === field) {
        closeColPopup();
        return;
      }
      renderColPopup(field);
      closeColPopup();
      var rect = button.getBoundingClientRect();
      popup.style.top = (rect.bottom + 6) + 'px';
      popup.style.left = Math.min(rect.left, window.innerWidth - 310) + 'px';
      popup.classList.add('open');
      activeColPopup = field;
    });
  });

  document.addEventListener('click', function (event) {
    if (event.target.closest('.qf-picker-btn') === null && event.target.closest('.qf-popup') === null) closeQuickPopup();
    if (event.target.closest('.col-filter-btn') === null && event.target.closest('.cfp') === null) closeColPopup();

    var qfItem = event.target.closest('.qf-popup-item');
    if (qfItem) {
      var key = qfItem.getAttribute('data-qf-key') || '';
      if (qf[key]) qf[key].value = qfItem.getAttribute('data-qf-value') || '';
      qfConfig.forEach(function (filter) { if (filter.key === key) updatePickerLabel(filter); });
      applyAllFilters();
      closeQuickPopup();
    }

    var selectAll = event.target.closest('[data-cfp-select-all]');
    if (selectAll) {
      var field = selectAll.getAttribute('data-cfp-select-all') || '';
      var popup = document.getElementById('cfp-' + field);
      if (!popup) return;
      popup.querySelectorAll('.cfp-list input[type=checkbox]').forEach(function (input) { input.checked = selectAll.checked; });
    }

    var applyBtn = event.target.closest('[data-cfp-apply], [data-cfp-ok]');
    if (applyBtn) {
      var field = applyBtn.getAttribute('data-cfp-apply') || applyBtn.getAttribute('data-cfp-ok') || '';
      applyColFilter(field);
      if (applyBtn.hasAttribute('data-cfp-ok')) closeColPopup();
    }
  });

  document.addEventListener('input', function (event) {
    var quickSearch = event.target.closest('[data-qf-popup-search]');
    if (quickSearch) {
      var key = quickSearch.getAttribute('data-qf-popup-search') || '';
      var popup = document.getElementById('qf-popup-' + key);
      if (!popup) return;
      var needle = norm(quickSearch.value);
      popup.querySelectorAll('.qf-popup-item').forEach(function (item) {
        item.style.display = norm(item.textContent).indexOf(needle) !== -1 ? '' : 'none';
      });
    }
    var cfpSearch = event.target.closest('[data-cfp-search]');
    if (cfpSearch) {
      var field = cfpSearch.getAttribute('data-cfp-search') || '';
      var popup = document.getElementById('cfp-' + field);
      if (!popup) return;
      var filter = norm(cfpSearch.value);
      popup.querySelectorAll('.cfp-item').forEach(function (item) {
        item.style.display = norm(item.textContent).indexOf(filter) !== -1 ? '' : 'none';
      });
    }
  });

  applyAllFilters();
})();
</script>

<?php if (!$isEmbedded) { include __DIR__ . '/../../includes/footer.php'; } ?>
