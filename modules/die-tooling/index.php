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
$entityLabel = isset($dieToolingEntityLabelOverride) && trim((string)$dieToolingEntityLabelOverride) !== ''
  ? trim((string)$dieToolingEntityLabelOverride)
  : 'Barcode Die';
$pageTitle = isset($dieToolingPageTitleOverride) && trim((string)$dieToolingPageTitleOverride) !== ''
  ? trim((string)$dieToolingPageTitleOverride)
  : ($isDesignMode ? 'Design ' . $entityLabel : 'Master ' . $entityLabel);
$isEmbedded = !empty($dieToolingEmbedded);
$dieTypeScope = isset($dieToolingDieTypeScope) ? mb_strtolower(trim((string)$dieToolingDieTypeScope), 'UTF-8') : '';
$dieTypeScopeLabel = isset($dieToolingDieTypeScopeLabel) && trim((string)$dieToolingDieTypeScopeLabel) !== ''
  ? trim((string)$dieToolingDieTypeScopeLabel)
  : '';
$columns = dieToolingColumns();
$importColumns = dieToolingImportColumns();
$csrf = generateCSRF();

function dieToolingNormalizeHeaderKey($value) {
  $value = mb_strtolower(trim((string)$value), 'UTF-8');
  $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
  $value = preg_replace('/\s+/', ' ', $value);
  return trim($value);
}

function dieToolingAutoMapHeaders(array $headers) {
  $synonyms = [
    'sl_no' => ['sl no', 'sl. no', 'serial no', 'serial number', 'sr no', 'sr. no'],
    'barcode_size' => ['barcode size', 'bar code size', 'size', 'barcode'],
    'ups_in_roll' => ['ups in roll', 'up in roll', 'ups roll'],
    'up_in_die' => ['up in die', 'ups in die', 'up in production', 'ups in production', 'ups is production', 'up production', 'ups production', 'use in production', 'used in production', 'use production'],
    'label_gap' => ['label gap', 'gap'],
    'paper_size' => ['paper size', 'paper'],
    'cylender' => ['cylender', 'cylinder'],
    'repeat_size' => ['repeat', 'repeat size'],
    'used_for' => ['used', 'used in', 'used for', 'use for', 'use'],
    'die_type' => ['die type', 'type'],
    'core' => ['core'],
    'pices_per_roll' => ['pices per roll', 'pieces per roll', 'pcs per roll'],
  ];

  $normalizedHeaders = [];
  foreach ($headers as $header) {
    $normalizedHeaders[(string)$header] = dieToolingNormalizeHeaderKey($header);
  }

  $mapping = [];
  foreach ($synonyms as $key => $keys) {
    $mapping[$key] = '';
    foreach ($normalizedHeaders as $rawHeader => $normHeader) {
      foreach ($keys as $needle) {
        $needle = dieToolingNormalizeHeaderKey($needle);
        if ($needle !== '' && strpos($normHeader, $needle) !== false) {
          $mapping[$key] = (string)$rawHeader;
          break 2;
        }
      }
    }
  }

  foreach ($normalizedHeaders as $rawHeader => $normHeader) {
    if ($mapping['up_in_die'] === '' && strpos($normHeader, 'production') !== false) {
      if (
        strpos($normHeader, 'up') !== false ||
        strpos($normHeader, 'ups') !== false ||
        strpos($normHeader, 'use') !== false
      ) {
        $mapping['up_in_die'] = (string)$rawHeader;
      }
    }
  }

  return $mapping;
}

function dieToolingRowMatchesScope($dieTypeValue, $scope) {
  $scope = mb_strtolower(trim((string)$scope), 'UTF-8');
  if ($scope === '') {
    return true;
  }

  $value = mb_strtolower(trim((string)$dieTypeValue), 'UTF-8');
  if ($scope === 'flatbed') {
    return strpos($value, 'flat') !== false;
  }
  if ($scope === 'rotary') {
    return strpos($value, 'rotary') !== false;
  }
  return strpos($value, $scope) !== false;
}

if (isset($_GET['clear_import']) && !$isDesignMode) {
  unset($_SESSION['die_tooling_import_preview']);
  redirect(dieToolingRedirectUrl($mode));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please retry.');
    redirect(dieToolingRedirectUrl($mode));
  }

  $action = trim((string)($_POST['action'] ?? ''));

  if ($action === 'add_die_tool') {
    if (!$isDesignMode) {
      setFlash('error', 'Manual add is disabled in Master Barcode Die. Use import mapping instead.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $data = dieToolingNormalizePayload($_POST);
    if ($dieTypeScope !== '') {
      $data['die_type'] = $dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope);
    }
    $stmt = $db->prepare('INSERT INTO master_die_tooling (barcode_size, ups_in_roll, up_in_die, label_gap, paper_size, cylender, repeat_size, used_for, die_type, core, pices_per_roll) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if ($stmt) {
      $stmt->bind_param(
        'sssssssssss',
        $data['barcode_size'],
        $data['ups_in_roll'],
        $data['up_in_die'],
        $data['label_gap'],
        $data['paper_size'],
        $data['cylender'],
        $data['repeat_size'],
        $data['used_for'],
        $data['die_type'],
        $data['core'],
        $data['pices_per_roll']
      );
      if ($stmt->execute()) {
        setFlash('success', 'Barcode die entry added successfully.');
      } else {
        setFlash('error', 'Unable to add entry.');
      }
      $stmt->close();
    } else {
      setFlash('error', 'Unable to prepare insert query.');
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'update_die_tool') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      setFlash('error', 'Invalid row id for update.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $data = dieToolingNormalizePayload($_POST);
    if ($dieTypeScope !== '') {
      $data['die_type'] = $dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope);
    }
    $stmt = $db->prepare('UPDATE master_die_tooling SET barcode_size=?, ups_in_roll=?, up_in_die=?, label_gap=?, paper_size=?, cylender=?, repeat_size=?, used_for=?, die_type=?, core=?, pices_per_roll=? WHERE id=?');
    if ($stmt) {
      $stmt->bind_param(
        'sssssssssssi',
        $data['barcode_size'],
        $data['ups_in_roll'],
        $data['up_in_die'],
        $data['label_gap'],
        $data['paper_size'],
        $data['cylender'],
        $data['repeat_size'],
        $data['used_for'],
        $data['die_type'],
        $data['core'],
        $data['pices_per_roll'],
        $id
      );
      if ($stmt->execute()) {
        setFlash('success', 'Barcode die entry updated successfully.');
      } else {
        setFlash('error', 'Unable to update entry.');
      }
      $stmt->close();
    } else {
      setFlash('error', 'Unable to prepare update query.');
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'delete_die_tool') {
    if ($isDesignMode) {
      setFlash('error', 'Delete is disabled in Design Barcode Die.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare('DELETE FROM master_die_tooling WHERE id=?');
      if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
      }
      setFlash('success', 'Barcode die entry deleted.');
    } else {
      setFlash('error', 'Invalid row id for delete.');
    }
    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'bulk_delete_die_tools') {
    if ($isDesignMode) {
      setFlash('error', 'Bulk delete is disabled in Design Barcode Die.');
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
    $types = str_repeat('i', count($selectedIds));
    $stmt = $db->prepare('DELETE FROM master_die_tooling WHERE id IN (' . $placeholders . ')');
    if ($stmt) {
      $stmt->bind_param($types, ...$selectedIds);
      if ($stmt->execute()) {
        setFlash('success', 'Selected barcode die rows deleted: ' . $stmt->affected_rows);
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
      setFlash('error', 'Import is disabled in Design Barcode Die.');
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

      $headers = array_map(function ($v) {
        return trim((string)$v);
      }, (array)$sheetRows[0]);

      $rows = [];
      for ($i = 1; $i < count($sheetRows); $i++) {
        $line = (array)$sheetRows[$i];
        $allBlank = true;
        foreach ($line as $c) {
          if (trim((string)$c) !== '') {
            $allBlank = false;
            break;
          }
        }
        if ($allBlank) continue;
        $rows[] = array_values($line);
      }

      if (!$rows) {
        throw new RuntimeException('No data rows found in file.');
      }

      $_SESSION['die_tooling_import_preview'] = [
        'source_name' => $fileName,
        'headers' => $headers,
        'rows' => $rows,
      ];

      setFlash('success', 'File uploaded successfully. Please map columns and apply import.');
    } catch (Throwable $e) {
      setFlash('error', $e->getMessage());
    }

    redirect(dieToolingRedirectUrl($mode));
  }

  if ($action === 'import_apply') {
    if ($isDesignMode) {
      setFlash('error', 'Import is disabled in Design Barcode Die.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $preview = $_SESSION['die_tooling_import_preview'] ?? null;
    if (!$preview || !is_array($preview)) {
      setFlash('error', 'Import preview not found. Upload file again.');
      redirect(dieToolingRedirectUrl($mode));
    }

    $headers = array_map(function ($h) {
      return trim((string)$h);
    }, (array)($preview['headers'] ?? []));

    $headerIndex = [];
    foreach ($headers as $idx => $name) {
      $headerIndex[$name] = $idx;
    }

    $autoMapping = dieToolingAutoMapHeaders($headers);
    $mapping = (array)($_POST['mapping'] ?? []);
    $rows = (array)($preview['rows'] ?? []);
    $clearExisting = !empty($_POST['clear_existing']);

    if ($clearExisting) {
      if ($dieTypeScopeLabel !== '') {
        $safeLabel = $db->real_escape_string(mb_strtolower(trim($dieTypeScopeLabel), 'UTF-8'));
        $db->query("DELETE FROM master_die_tooling WHERE LOWER(COALESCE(die_type, '')) = '{$safeLabel}'");
      } elseif ($dieTypeScope !== '') {
        $safeScope = $db->real_escape_string($dieTypeScope);
        $db->query("DELETE FROM master_die_tooling WHERE LOWER(COALESCE(die_type, '')) LIKE '%{$safeScope}%'");
      } else {
        $db->query('DELETE FROM master_die_tooling');
      }
    }

    $inserted = 0;
    $stmt = $db->prepare('INSERT INTO master_die_tooling (sl_no, barcode_size, ups_in_roll, up_in_die, label_gap, paper_size, cylender, repeat_size, used_for, die_type, core, pices_per_roll) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
      setFlash('error', 'Unable to prepare import insert statement.');
      redirect(dieToolingRedirectUrl($mode));
    }

    foreach ($rows as $row) {
      $payload = [];
      foreach ($importColumns as $key => $label) {
        $mappedHeader = trim((string)($mapping[$key] ?? ''));
        if ($mappedHeader === '') {
          $mappedHeader = trim((string)($autoMapping[$key] ?? ''));
        }
        $value = '';
        if ($mappedHeader !== '' && isset($headerIndex[$mappedHeader])) {
          $sourceIdx = (int)$headerIndex[$mappedHeader];
          $value = trim((string)($row[$sourceIdx] ?? ''));
        }
        if ($key === 'up_in_die' && $value === '') {
          foreach ($headers as $headerName) {
            $normalizedHeader = dieToolingNormalizeHeaderKey($headerName);
            if (strpos($normalizedHeader, 'production') !== false && (strpos($normalizedHeader, 'up') !== false || strpos($normalizedHeader, 'ups') !== false || strpos($normalizedHeader, 'use') !== false)) {
              $sourceIdx = (int)($headerIndex[$headerName] ?? -1);
              if ($sourceIdx >= 0) {
                $value = trim((string)($row[$sourceIdx] ?? ''));
              }
              break;
            }
          }
        }
        if ($key !== 'sl_no' && $value === '') {
          $value = 'NA';
        }
        $payload[$key] = dieToolingCleanText($value, 190);
      }

      if ($dieTypeScope !== '') {
        $payload['die_type'] = $dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope);
      }

      $stmt->bind_param(
        'ssssssssssss',
        $payload['sl_no'],
        $payload['barcode_size'],
        $payload['ups_in_roll'],
        $payload['up_in_die'],
        $payload['label_gap'],
        $payload['paper_size'],
        $payload['cylender'],
        $payload['repeat_size'],
        $payload['used_for'],
        $payload['die_type'],
        $payload['core'],
        $payload['pices_per_roll']
      );
      if ($stmt->execute()) {
        $inserted++;
      }
    }

    $stmt->close();
    unset($_SESSION['die_tooling_import_preview']);

    setFlash('success', 'Import complete. Total inserted: ' . $inserted);
    redirect(dieToolingRedirectUrl($mode));
  }
}

$importPreview = !$isDesignMode ? ($_SESSION['die_tooling_import_preview'] ?? null) : null;
$importAutoMapping = [];
if (is_array($importPreview) && !empty($importPreview['headers'])) {
  $importAutoMapping = dieToolingAutoMapHeaders((array)$importPreview['headers']);
}

$editingId = (int)($_GET['edit_id'] ?? 0);
$editingRow = null;
if ($editingId > 0) {
  $stmt = $db->prepare('SELECT * FROM master_die_tooling WHERE id=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('i', $editingId);
    $stmt->execute();
    $editingRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($editingRow && !dieToolingRowMatchesScope($editingRow['die_type'] ?? '', $dieTypeScope)) {
      $editingRow = null;
    }
  }
}

$scopeWhere = '';
if ($dieTypeScopeLabel !== '') {
  $safeLabel = $db->real_escape_string(mb_strtolower(trim($dieTypeScopeLabel), 'UTF-8'));
  $scopeWhere = " WHERE LOWER(COALESCE(die_type, '')) = '{$safeLabel}'";
} elseif ($dieTypeScope !== '') {
  $safeScope = $db->real_escape_string($dieTypeScope);
  $scopeWhere = " WHERE LOWER(COALESCE(die_type, '')) LIKE '%{$safeScope}%'";
}

$rows = [];
$res = $db->query("SELECT * FROM master_die_tooling{$scopeWhere} ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$calculatorDies = [];
foreach ($rows as $calcRow) {
  $calcSlNo = trim((string)($calcRow['sl_no'] ?? ''));
  $calcBarcode = trim((string)($calcRow['barcode_size'] ?? ''));
  $calcUsedFor = trim((string)($calcRow['used_for'] ?? ''));
  $calcLabelParts = [];
  if ($calcSlNo !== '') $calcLabelParts[] = $calcSlNo;
  if ($calcBarcode !== '') $calcLabelParts[] = $calcBarcode;
  if ($calcUsedFor !== '') $calcLabelParts[] = $calcUsedFor;
  $calcLabel = trim(implode(' | ', $calcLabelParts));
  if ($calcLabel === '') $calcLabel = 'Die #' . (int)($calcRow['id'] ?? 0);
  $calculatorDies[] = [
    'id' => (int)($calcRow['id'] ?? 0),
    'label' => $calcLabel,
    'pices_per_roll' => trim((string)($calcRow['pices_per_roll'] ?? '')),
    'up_in_die' => trim((string)($calcRow['up_in_die'] ?? '')),
    'repeat_size' => trim((string)($calcRow['repeat_size'] ?? '')),
  ];
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

    $normalizedValue = strtolower($value);
    if (!isset($fieldSuggestions[$columnKey][$normalizedValue])) {
      $fieldSuggestions[$columnKey][$normalizedValue] = $value;
    }
  }
}
foreach (array_keys($columns) as $columnKey) {
  $fieldSuggestions[$columnKey] = array_values($fieldSuggestions[$columnKey]);
  usort($fieldSuggestions[$columnKey], function ($left, $right) {
    return strnatcasecmp((string)$left, (string)$right);
  });
}

$flash = getFlash();

if (!$isEmbedded) {
  include __DIR__ . '/../../includes/header.php';
}
?>

<style>
.barcode-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.barcode-btn { display:inline-flex; align-items:center; gap:6px; border:1px solid var(--border); border-radius:10px; padding:8px 12px; font-size:.78rem; font-weight:700; text-decoration:none; background:#fff; color:var(--text-main); }
.barcode-btn.green { color:#15803d; border-color:#86efac; }
.barcode-btn.blue { color:#1d4ed8; border-color:#93c5fd; }
.barcode-btn.orange { color:#c2410c; border-color:#fdba74; }
.barcode-btn.red { color:#b91c1c; border-color:#fca5a5; }
.barcode-btn[disabled] { opacity:.45; cursor:not-allowed; pointer-events:none; }
.barcode-bulk-bar { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 10px; padding:10px 12px; border:1px solid #fecaca; border-radius:10px; background:#fef2f2; }
.barcode-bulk-meta { font-size:.82rem; color:#991b1b; font-weight:700; }
.bulk-check-col { width:42px; text-align:center; }

.ps-quick-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:10px;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;margin:10px 0 12px}
.ps-qf-item{display:flex;flex-direction:column;gap:3px;min-width:120px;flex:1;justify-content:center}
.ps-qf-item label{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}
.ps-qf-item input,.ps-qf-item select{height:36px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.8rem;background:#fff}
.ps-qf-item input:focus,.ps-qf-item select:focus{outline:none;border-color:#86efac;box-shadow:0 0 0 3px rgba(34,197,94,.1)}
.ps-qf-actions{display:flex;align-items:center;gap:8px;min-width:max-content;padding-top:18px}
.ps-qf-reset{height:36px;display:inline-flex;align-items:center;justify-content:center;padding:0 14px;border-radius:8px;background:#f97316;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;white-space:nowrap}
.ps-qf-picker{position:relative}
.ps-qf-picker-btn{height:36px;width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid var(--border);border-radius:8px;padding:0 10px;background:#fff;font-size:.8rem;color:var(--text-main);cursor:pointer;text-align:left}
.ps-qf-picker-btn .muted{color:#94a3b8}
.ps-qf-picker-btn.active{border-color:#fdba74;background:#fff7ed}
.ps-qf-popup{display:none;position:fixed;z-index:240;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,.18);width:260px;overflow:hidden}
.ps-qf-popup.open{display:block}
.ps-qf-popup-head{padding:10px;border-bottom:1px solid #f1f5f9}
.ps-qf-popup-search{width:100%;height:34px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem;background:#f8fafc}
.ps-qf-popup-list{max-height:220px;overflow-y:auto;padding:6px 0}
.ps-qf-popup-item{display:flex;align-items:center;padding:7px 10px;font-size:.78rem;cursor:pointer}
.ps-qf-popup-item:hover{background:#f8fafc}
.ps-qf-popup-item.active{background:#fff7ed;color:#ea580c;font-weight:700}

.col-filter-wrap{position:relative;display:inline-flex;align-items:center}
.col-filter-btn{background:none;border:none;cursor:pointer;padding:2px 4px;color:#94a3b8;font-size:10px;margin-left:4px;border-radius:4px;transition:all .12s}
.col-filter-btn:hover,.col-filter-btn.active{color:#16a34a;background:rgba(34,197,94,.12)}
.col-filter-btn .filter-count{display:none;position:absolute;top:-5px;right:-6px;background:#ef4444;color:#fff;border-radius:8px;font-size:7px;padding:1px 3px;line-height:1;min-width:11px;text-align:center}
.col-filter-btn.active .filter-count{display:block}
.cfp{display:none;position:fixed;z-index:260;width:290px;max-width:90vw;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.18);overflow:hidden}
.cfp.open{display:block}
.cfp-head{padding:10px;border-bottom:1px solid #f1f5f9;background:#f8fafc}
.cfp-search{width:100%;height:32px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem}
.cfp-select-all{display:flex;align-items:center;gap:6px;font-size:.75rem;margin-top:8px;color:#64748b}
.cfp-list{max-height:220px;overflow:auto;padding:8px 10px;display:flex;flex-direction:column;gap:6px}
.cfp-item{display:flex;align-items:center;gap:8px;font-size:.78rem;color:#334155}
.cfp-foot{display:flex;justify-content:flex-end;gap:8px;padding:10px;border-top:1px solid #f1f5f9;background:#fff}
.cfp-foot button{height:30px;border-radius:8px;border:none;padding:0 12px;font-size:.76rem;font-weight:700;cursor:pointer}
.cfp-foot .ok{background:#16a34a;color:#fff}
.cfp-foot .apply{background:#0f172a;color:#fff}

.barcode-die-table thead th { background:#dbeafe; color:#1e3a8a; border-color:#bfdbfe; font-weight:700; white-space:nowrap; }
.barcode-die-table tbody td { border-color:#e2e8f0; }
.die-calc-box{margin-top:10px;border:1px solid #fdba74;border-radius:12px;background:#fff7ed;padding:12px}
.die-calc-title{font-size:.8rem;font-weight:800;color:#9a3412;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
.die-calc-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
.die-calc-grid select,.die-calc-grid input{height:38px;border:1px solid #fdba74;border-radius:8px;padding:0 10px;background:#fff;font-size:.85rem}
@media (max-width:1200px){.die-calc-grid{grid-template-columns:1fr 1fr}}
@media (max-width:980px){.ps-quick-filter{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.die-calc-grid{grid-template-columns:1fr}}
@media (max-width:768px){.ps-quick-filter{display:grid;grid-template-columns:1fr;margin:8px 0 10px}.ps-qf-item{min-width:auto}.ps-qf-actions{padding-top:10px;min-width:auto;width:100%}.ps-qf-reset{width:100%;justify-content:center}.die-calc-grid{grid-template-columns:1fr}.barcode-actions{flex-direction:column;align-items:stretch}.barcode-btn{width:100%;justify-content:center}.modal-card{width:90%;max-height:95vh}}
@media (max-width:640px){.barcode-die-table{font-size:.7rem}.barcode-die-table thead th{padding:4px 2px}.barcode-die-table tbody td{padding:4px 2px}.ps-quick-filter{padding:8px;gap:6px;margin:6px 0 8px}.ps-qf-item{gap:2px}.ps-qf-item label{font-size:.55rem}.ps-qf-item input,.ps-qf-item select{height:32px;padding:0 8px;font-size:.7rem}.die-calc-box{padding:8px;margin-top:6px}.die-calc-title{font-size:.7rem;margin-bottom:6px}.die-calc-grid{grid-template-columns:1fr;gap:8px}.die-calc-grid select,.die-calc-grid input{height:34px;padding:0 8px;font-size:.75rem}.modal-form-section{grid-template-columns:1fr}.modal-body-pad{padding:10px 14px}.modal-head{padding:10px 14px}.modal-form-label{font-size:.6rem;margin-bottom:3px}.modal-form-input{height:36px;font-size:.7rem}.barcode-btn{padding:6px 10px;font-size:.7rem}.col-filter-btn{font-size:8px}.ps-qf-popup,.cfp{width:90vw;max-width:280px}.card-header{padding:8px 10px}}

.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; align-items:center; justify-content:center; }
.modal-card { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:95%; max-width:960px; max-height:92vh; overflow:auto; background:#fff; border-radius:14px; box-shadow:0 24px 60px rgba(0,0,0,.25); }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:15px 20px; background:#0f172a; color:#fff; border-radius:14px 14px 0 0; }
.modal-head strong { font-size:.92rem; font-weight:700; letter-spacing:.02em; display:flex;align-items:center;gap:8px; }
.modal-head .btn-ghost { color:#94a3b8 !important; border:none; padding:4px 8px; }
.modal-head .btn-ghost:hover { color:#fff !important; background:rgba(255,255,255,.1) !important; }
.modal-body-pad { padding:20px 22px; }
.modal-form-section { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px 16px; }
@media (max-width:900px) { .modal-form-section { grid-template-columns:repeat(2,minmax(0,1fr)); } }
.modal-form-label { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:#64748b; display:block; margin-bottom:4px; }
.modal-form-input { width:100%; height:40px; border:1px solid #cbd5e1; border-radius:10px; padding:0 12px; font-size:.85rem; background:#fff; color:#0f172a; transition:border-color .15s,box-shadow .15s; }
.modal-form-input:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.modal-form-input[readonly] { background:#f8fafc; color:#94a3b8; cursor:not-allowed; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <span class="card-title"><?= e($pageTitle) ?></span>
    <div class="barcode-actions">
      <?php if ($isDesignMode): ?>
        <button type="button" class="barcode-btn green" onclick="openAddModal()"><i class="bi bi-plus-circle"></i> Add New</button>
      <?php endif; ?>
      <?php if (!$isDesignMode): ?>
        <button type="button" class="barcode-btn red" id="bulkDeleteTrigger" disabled><i class="bi bi-trash3"></i> Bulk Delete</button>
        <a class="barcode-btn blue" href="<?= BASE_URL ?>/modules/die-tooling/export.php?mode=<?= e($mode) ?>&format=excel<?= $dieTypeScope !== '' ? '&scope=' . urlencode($dieTypeScope) . '&scope_label=' . urlencode($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : '' ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <a class="barcode-btn orange" target="_blank" href="<?= BASE_URL ?>/modules/die-tooling/export.php?mode=<?= e($mode) ?>&format=pdf<?= $dieTypeScope !== '' ? '&scope=' . urlencode($dieTypeScope) . '&scope_label=' . urlencode($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : '' ?>"><i class="bi bi-printer"></i> Print / PDF</a>
      <?php else: ?>
        <a class="barcode-btn blue" href="<?= BASE_URL ?>/modules/die-tooling/export.php?mode=<?= e($mode) ?>&format=excel<?= $dieTypeScope !== '' ? '&scope=' . urlencode($dieTypeScope) . '&scope_label=' . urlencode($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : '' ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <a class="barcode-btn orange" target="_blank" href="<?= BASE_URL ?>/modules/die-tooling/export.php?mode=<?= e($mode) ?>&format=pdf<?= $dieTypeScope !== '' ? '&scope=' . urlencode($dieTypeScope) . '&scope_label=' . urlencode($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : '' ?>"><i class="bi bi-printer"></i> Print / PDF</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:10px;"><?= e($flash['message']) ?></div>
  <?php endif; ?>

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

<?php
$barcodeColorPalette = ['#dbeafe', '#dcfce7', '#fce7f3', '#ede9fe', '#e0f2fe', '#fae8ff', '#f5f3ff', '#ecfeff'];
$barcodeColorMap = [];
foreach ($rows as $rowItem) {
  $barcodeKey = strtolower(trim((string)($rowItem['barcode_size'] ?? '')));
  if ($barcodeKey === '' || isset($barcodeColorMap[$barcodeKey])) continue;
  $hash = abs((int)crc32($barcodeKey));
  $barcodeColorMap[$barcodeKey] = $barcodeColorPalette[$hash % count($barcodeColorPalette)];
}
?>

<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span class="card-title"><?= e($entityLabel) ?> Table</span>
    <span style="font-size:.82rem;color:var(--text-muted);">Total Rows: <?= count($rows) ?></span>
  </div>

  <?php if (!$isDesignMode): ?>
    <div class="barcode-bulk-bar no-print">
      <div class="barcode-bulk-meta"><span id="bulkSelectedCount">0</span> row selected</div>
      <div style="font-size:.78rem;color:#7f1d1d;">Select rows from table, then use Bulk Delete.</div>
    </div>
    <form method="POST" id="bulkDeleteForm" style="display:none;">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="bulk_delete_die_tools">
      <div id="bulkDeleteIds"></div>
    </form>
  <?php endif; ?>

  <?php if ($isDesignMode): ?>
    <div class="die-calc-box no-print" id="dieCalcBox">
      <div class="die-calc-title"><?= e($entityLabel) ?> Meter Calculation</div>
      <div class="die-calc-grid">
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Select <?= e($entityLabel) ?></label>
          <select id="dieCalcSelect">
            <option value="">-- Select <?= e($entityLabel) ?> --</option>
            <?php foreach ($calculatorDies as $calcDie): ?>
              <option value="<?= (int)$calcDie['id'] ?>" data-pices-per-roll="<?= e((string)$calcDie['pices_per_roll']) ?>" data-ups="<?= e((string)$calcDie['up_in_die']) ?>" data-repeat="<?= e((string)$calcDie['repeat_size']) ?>"><?= e((string)$calcDie['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Quantity</label>
          <input type="number" id="dieCalcQty" min="0" step="1" placeholder="Enter qty">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Meter</label>
          <input type="number" id="dieCalcMeter" min="0" step="0.01" placeholder="Enter meter">
        </div>
      </div>
      <div style="font-size:.74rem;color:#9a3412;margin-top:8px;">Enter Quantity to get Meter, or enter Meter to get Quantity. Formula: If Pcs/Roll available → Meter = Qty / Pcs/Roll; else → Meter = (Qty / UPS) × (Repeat / 1000)</div>
    </div>
  <?php endif; ?>

  <div class="ps-quick-filter no-print" id="ps-quick-filter">
    <div class="ps-qf-item"><label>Global Search</label><input type="text" id="qf-search" placeholder="Search everything..."></div>
    <div class="ps-qf-item"><label>Barcode Size</label><input type="text" id="qf-barcode" placeholder="e.g. 33mm X 15mm"></div>
    <div class="ps-qf-item">
      <label>Used IN</label>
      <div class="ps-qf-picker">
        <input type="hidden" id="qf-used" value="">
        <button type="button" class="ps-qf-picker-btn" data-qf-picker="used"><span class="muted">All Used IN</span><i class="bi bi-chevron-down"></i></button>
        <div class="ps-qf-popup" id="qf-popup-used"></div>
      </div>
    </div>
    <div class="ps-qf-item">
      <label>Die Type</label>
      <div class="ps-qf-picker">
        <input type="hidden" id="qf-dietype" value="">
        <button type="button" class="ps-qf-picker-btn" data-qf-picker="dietype"><span class="muted">All Die Type</span><i class="bi bi-chevron-down"></i></button>
        <div class="ps-qf-popup" id="qf-popup-dietype"></div>
      </div>
    </div>
    <div class="ps-qf-item">
      <label>Core</label>
      <div class="ps-qf-picker">
        <input type="hidden" id="qf-core" value="">
        <button type="button" class="ps-qf-picker-btn" data-qf-picker="core"><span class="muted">All Core</span><i class="bi bi-chevron-down"></i></button>
        <div class="ps-qf-popup" id="qf-popup-core"></div>
      </div>
    </div>
    <div class="ps-qf-item"><label>Repeat Min</label><input type="number" id="qf-repeat-min" step="0.01" placeholder="e.g. 20"></div>
    <div class="ps-qf-item"><label>Repeat Max</label><input type="number" id="qf-repeat-max" step="0.01" placeholder="e.g. 80"></div>
    <div class="ps-qf-actions"><button type="button" class="ps-qf-reset" onclick="resetQuickFilters()">Reset</button></div>
  </div>

  <div class="table-responsive">
    <table class="table barcode-die-table" id="barcodeDieTable">
      <thead>
        <tr>
          <?php if (!$isDesignMode): ?>
            <th class="bulk-check-col no-print"><input type="checkbox" id="selectAllBarcodeDie"></th>
          <?php endif; ?>
          <th><span class="col-filter-wrap">SL No.<button type="button" class="col-filter-btn no-print" data-filter-col="0"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-0"></div></span></th>
          <th><span class="col-filter-wrap">BarCode Size<button type="button" class="col-filter-btn no-print" data-filter-col="1"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-1"></div></span></th>
          <th><span class="col-filter-wrap">Ups in Roll<button type="button" class="col-filter-btn no-print" data-filter-col="2"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-2"></div></span></th>
          <th><span class="col-filter-wrap">UPS in Die<button type="button" class="col-filter-btn no-print" data-filter-col="3"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-3"></div></span></th>
          <th><span class="col-filter-wrap">Label Gap<button type="button" class="col-filter-btn no-print" data-filter-col="4"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-4"></div></span></th>
          <th><span class="col-filter-wrap">Paper Size<button type="button" class="col-filter-btn no-print" data-filter-col="5"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-5"></div></span></th>
          <th><span class="col-filter-wrap">Cylender<button type="button" class="col-filter-btn no-print" data-filter-col="6"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-6"></div></span></th>
          <th><span class="col-filter-wrap">Repeat<button type="button" class="col-filter-btn no-print" data-filter-col="7"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-7"></div></span></th>
          <th><span class="col-filter-wrap">Used IN<button type="button" class="col-filter-btn no-print" data-filter-col="8"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-8"></div></span></th>
          <th><span class="col-filter-wrap">Die Type<button type="button" class="col-filter-btn no-print" data-filter-col="9"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-9"></div></span></th>
          <th><span class="col-filter-wrap">Core<button type="button" class="col-filter-btn no-print" data-filter-col="10"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-10"></div></span></th>
          <th><span class="col-filter-wrap">Pices per Roll<button type="button" class="col-filter-btn no-print" data-filter-col="11"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-11"></div></span></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $isDesignMode ? 13 : 14 ?>" style="text-align:center;color:var(--text-muted);padding:24px;">No <?= e(strtolower($entityLabel)) ?> records found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $idx => $row): ?>
          <?php
            $barcodeKey = strtolower(trim((string)($row['barcode_size'] ?? '')));
            $repeatRaw = trim((string)($row['repeat_size'] ?? ''));
            $hasRepeat = ($repeatRaw !== '' && strtoupper($repeatRaw) !== 'NA');
            $barcodeBg = ($barcodeKey !== '' && $hasRepeat) ? ($barcodeColorMap[$barcodeKey] ?? '#ffffff') : '#ffffff';
            $displaySlNo = $dieTypeScope !== '' ? (string)($idx + 1) : trim((string)($row['sl_no'] ?? ''));
            if ($displaySlNo === '') $displaySlNo = (string)($idx + 1);
          ?>
          <tr>
            <?php if (!$isDesignMode): ?>
              <td class="bulk-check-col no-print"><input type="checkbox" class="barcode-row-check" value="<?= (int)$row['id'] ?>"></td>
            <?php endif; ?>
            <td><?= e($displaySlNo) ?></td>
            <td style="background:<?= e($barcodeBg) ?>;font-weight:600;"><?= e((string)$row['barcode_size']) ?></td>
            <td><?= e((string)$row['ups_in_roll']) ?></td>
            <td><?= e((string)$row['up_in_die']) ?></td>
            <td><?= e((string)$row['label_gap']) ?></td>
            <td><?= e((string)$row['paper_size']) ?></td>
            <td><?= e((string)$row['cylender']) ?></td>
            <td><?= e(dieToolingFormatDisplayNumber($row['repeat_size'])) ?></td>
            <td><?= e((string)$row['used_for']) ?></td>
            <td><?= e((string)$row['die_type']) ?></td>
            <td><?= e((string)$row['core']) ?></td>
            <td><?= e((string)$row['pices_per_roll']) ?></td>
            <td>
              <a class="btn btn-sm btn-primary" href="<?= dieToolingRedirectUrl($mode) ?>?edit_id=<?= (int)$row['id'] ?>"><i class="bi bi-pencil"></i></a>
              <?php if (!$isDesignMode): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this row?');">
                  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete_die_tool">
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
<div class="modal-overlay" id="addDieModal">
  <div class="modal-card">
    <div class="modal-head">
      <strong><i class="bi bi-plus-circle"></i> Add <?= e($entityLabel) ?></strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeAddModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body-pad">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add_die_tool">
        <div class="modal-form-section">
        <?php foreach ($columns as $key => $label): ?>
          <?php $isScopedDieType = ($key === 'die_type' && $dieTypeScope !== ''); ?>
          <div>
            <label class="modal-form-label"><?= e($label) ?></label>
            <input type="text" name="<?= e($key) ?>" class="modal-form-input" value="<?= e($isScopedDieType ? ($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : '') ?>" list="barcode-die-suggestions-<?= e($key) ?>" autocomplete="off" <?= $isScopedDieType ? 'readonly' : '' ?>>
          </div>
        <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
          <button type="button" class="btn btn-secondary" onclick="closeAddModal()"><i class="bi bi-x"></i> Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($editingRow): ?>
<div class="modal-overlay" id="editDieModal" style="display:block;">
  <div class="modal-card">
    <div class="modal-head">
      <strong><i class="bi bi-pencil-square"></i> Edit <?= e($entityLabel) ?></strong>
      <a class="btn btn-sm btn-ghost" href="<?= dieToolingRedirectUrl($mode) ?>"><i class="bi bi-x-lg"></i></a>
    </div>
    <div class="modal-body-pad">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_die_tool">
        <input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>">
        <div class="modal-form-section">
        <?php foreach ($columns as $key => $label): ?>
          <?php $isScopedDieType = ($key === 'die_type' && $dieTypeScope !== ''); ?>
          <div>
            <label class="modal-form-label"><?= e($label) ?></label>
            <input type="text" name="<?= e($key) ?>" class="modal-form-input" value="<?= e($isScopedDieType ? ($dieTypeScopeLabel !== '' ? $dieTypeScopeLabel : ucfirst($dieTypeScope)) : (string)($editingRow[$key] ?? '')) ?>" list="barcode-die-suggestions-<?= e($key) ?>" autocomplete="off" <?= $isScopedDieType ? 'readonly' : '' ?>>
          </div>
        <?php endforeach; ?>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
          <a class="btn btn-secondary" href="<?= dieToolingRedirectUrl($mode) ?>"><i class="bi bi-x"></i> Cancel</a>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Update</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php foreach ($columns as $key => $label): ?>
  <datalist id="barcode-die-suggestions-<?= e($key) ?>">
    <?php foreach ((array)($fieldSuggestions[$key] ?? []) as $suggestedValue): ?>
      <option value="<?= e((string)$suggestedValue) ?>"></option>
    <?php endforeach; ?>
  </datalist>
<?php endforeach; ?>

<?php if (!$isDesignMode && $importPreview): ?>
<div id="importMapModal" class="modal-overlay">
  <div class="modal-card">
    <div class="modal-head">
      <strong>Import Mapping - <?= e($entityLabel) ?></strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeImportMapModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:14px;">
      <div style="margin-bottom:10px;padding:8px 10px;border:1px solid #dbeafe;background:#eff6ff;border-radius:8px;font-size:.86rem;color:#1e3a8a;">
        Auto-matched columns are pre-selected. Green tick means mapped.
      </div>
      <details style="margin-bottom:10px;">
        <summary style="cursor:pointer;font-size:.82rem;font-weight:700;color:#64748b;padding:4px 0;">&#128269; Debug: Excel headers detected (<?= count((array)$importPreview['headers']) ?> columns)</summary>
        <div style="margin-top:6px;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:.78rem;font-family:monospace;overflow-x:auto;">
          <table style="border-collapse:collapse;width:100%;">
            <tr style="background:#e2e8f0;">
              <th style="padding:3px 8px;text-align:left;">#</th>
              <th style="padding:3px 8px;text-align:left;">Excel Header</th>
              <th style="padding:3px 8px;text-align:left;">Normalized</th>
              <th style="padding:3px 8px;text-align:left;">Mapped To</th>
            </tr>
            <?php
            $reverseMapping = array_flip(array_filter($importAutoMapping));
            foreach ((array)$importPreview['headers'] as $hIdx => $hVal):
              $hVal = (string)$hVal;
              $hNorm = dieToolingNormalizeHeaderKey($hVal);
              $mappedTo = $reverseMapping[$hVal] ?? '';
            ?>
            <tr style="<?= $mappedTo ? 'background:#f0fdf4;' : '' ?>">
              <td style="padding:2px 8px;color:#94a3b8;"><?= (int)$hIdx ?></td>
              <td style="padding:2px 8px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($hVal, ENT_QUOTES, 'UTF-8') ?></td>
              <td style="padding:2px 8px;color:#64748b;"><?= htmlspecialchars($hNorm, ENT_QUOTES, 'UTF-8') ?></td>
              <td style="padding:2px 8px;color:<?= $mappedTo ? '#166534' : '#dc2626' ?>;"><?= $mappedTo ? htmlspecialchars($mappedTo, ENT_QUOTES, 'UTF-8') : '— not mapped —' ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </details>

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

        <div class="col-span-2" style="padding:10px 0 2px;">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:.88rem;padding:8px 12px;border-radius:8px;border:1px solid #fca5a5;background:#fff1f2;color:#b91c1c;font-weight:600;">
            <input type="checkbox" name="clear_existing" value="1" style="width:16px;height:16px;accent-color:#dc2626;">
            Clear all existing data before importing
          </label>
        </div>
        <div class="form-actions col-span-2" style="display:flex;justify-content:flex-end;gap:8px;">
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
  var dieCalcSelect = document.getElementById('dieCalcSelect');
  var dieCalcQty = document.getElementById('dieCalcQty');
  var dieCalcMeter = document.getElementById('dieCalcMeter');
  if (dieCalcSelect && dieCalcQty && dieCalcMeter) {
    function parseNum(raw) {
      var cleaned = String(raw || '').trim().replace(/,/g, '').replace(/[^0-9.\-]/g, '');
      var n = parseFloat(cleaned);
      return isNaN(n) ? 0 : n;
    }
    function getDieParams() {
      var option = dieCalcSelect.options[dieCalcSelect.selectedIndex] || null;
      return {
        picesPerRoll: parseNum(option ? option.getAttribute('data-pices-per-roll') : 0),
        ups: parseNum(option ? option.getAttribute('data-ups') : 0),
        repeatValue: parseNum(option ? option.getAttribute('data-repeat') : 0)
      };
    }
    function recalcFromQty() {
      var p = getDieParams();
      var qty = parseNum(dieCalcQty.value || 0);
      if (qty <= 0) { dieCalcMeter.value = ''; return; }
      var meter = 0;
      if (p.picesPerRoll > 0) {
        meter = qty / p.picesPerRoll;
      } else if (p.ups > 0 && p.repeatValue > 0) {
        meter = (qty / p.ups) * (p.repeatValue / 1000);
      }
      dieCalcMeter.value = meter > 0 ? meter.toFixed(2) : '';
    }
    function recalcFromMeter() {
      var p = getDieParams();
      var meter = parseNum(dieCalcMeter.value || 0);
      if (meter <= 0) { dieCalcQty.value = ''; return; }
      var qty = 0;
      if (p.picesPerRoll > 0) {
        qty = meter * p.picesPerRoll;
      } else if (p.ups > 0 && p.repeatValue > 0) {
        qty = (meter / (p.repeatValue / 1000)) * p.ups;
      }
      dieCalcQty.value = qty > 0 ? Math.round(qty) : '';
    }
    dieCalcQty.addEventListener('input', function () { recalcFromQty(); });
    dieCalcMeter.addEventListener('input', function () { recalcFromMeter(); });
    dieCalcSelect.addEventListener('change', function () {
      if (parseNum(dieCalcQty.value) > 0) recalcFromQty();
      else if (parseNum(dieCalcMeter.value) > 0) recalcFromMeter();
    });
  }
})();
</script>

<script>
(function () {
  var bulkDeleteTrigger = document.getElementById('bulkDeleteTrigger');
  var bulkDeleteForm = document.getElementById('bulkDeleteForm');
  var bulkDeleteIds = document.getElementById('bulkDeleteIds');
  var bulkSelectedCount = document.getElementById('bulkSelectedCount');
  var selectAllBarcodeDie = document.getElementById('selectAllBarcodeDie');
  var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.barcode-row-check'));

  function updateBulkDeleteState() {
    if (!bulkDeleteTrigger || !bulkSelectedCount) return;
    var checkedRows = rowChecks.filter(function (checkbox) { return checkbox.checked; });
    bulkSelectedCount.textContent = checkedRows.length;
    bulkDeleteTrigger.disabled = checkedRows.length === 0;
    if (selectAllBarcodeDie) {
      selectAllBarcodeDie.checked = rowChecks.length > 0 && checkedRows.length === rowChecks.length;
      selectAllBarcodeDie.indeterminate = checkedRows.length > 0 && checkedRows.length < rowChecks.length;
    }
  }

  if (selectAllBarcodeDie) {
    selectAllBarcodeDie.addEventListener('change', function () {
      rowChecks.forEach(function (checkbox) {
        checkbox.checked = selectAllBarcodeDie.checked;
      });
      updateBulkDeleteState();
    });
  }

  rowChecks.forEach(function (checkbox) {
    checkbox.addEventListener('change', updateBulkDeleteState);
  });

  if (bulkDeleteTrigger && bulkDeleteForm && bulkDeleteIds) {
    bulkDeleteTrigger.addEventListener('click', function () {
      var checkedRows = rowChecks.filter(function (checkbox) { return checkbox.checked; });
      if (!checkedRows.length) {
        return;
      }
      if (!window.confirm('Delete selected rows?')) {
        return;
      }

      bulkDeleteIds.innerHTML = '';
      checkedRows.forEach(function (checkbox) {
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
    var modal = document.getElementById('addDieModal');
    if (modal) modal.style.display = 'block';
  };
  window.closeAddModal = function () {
    var modal = document.getElementById('addDieModal');
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

  document.addEventListener('click', function (e) {
    var addModal = document.getElementById('addDieModal');
    if (addModal && e.target === addModal) closeAddModal();
    var importModal = document.getElementById('importMapModal');
    if (importModal && e.target === importModal) closeImportMapModal();
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
  var table = document.getElementById('barcodeDieTable');
  if (!table) return;

  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (tr) {
    return tr.children.length > 1;
  });
  var activeColFilters = {};
  var activeCfpCol = null;
  var activeQuickPopup = null;

  var qf = {
    search: document.getElementById('qf-search'),
    barcode: document.getElementById('qf-barcode'),
    used: document.getElementById('qf-used'),
    dietype: document.getElementById('qf-dietype'),
    core: document.getElementById('qf-core'),
    repeatMin: document.getElementById('qf-repeat-min'),
    repeatMax: document.getElementById('qf-repeat-max')
  };

  function norm(v) { return String(v || '').trim().toLowerCase(); }
  function cell(row, idx) {
    var c = row.children[idx];
    return c ? String(c.textContent || '').trim() : '';
  }
  function repeatNum(row) {
    var raw = norm(cell(row, 7)).replace(/[^0-9.\-]/g, '');
    var n = parseFloat(raw);
    return isNaN(n) ? null : n;
  }
  function unique(colIdx) {
    var set = new Set();
    rows.forEach(function (r) {
      var v = cell(r, colIdx);
      if (v !== '') set.add(v);
    });
    return Array.from(set).sort(function (a, b) { return a.localeCompare(b); });
  }

  var quickLabel = { used: 'All Used IN', dietype: 'All Die Type', core: 'All Core' };
  var quickSource = { used: unique(8), dietype: unique(9), core: unique(10) };

  function updatePickerLabel(key) {
    var hidden = qf[key];
    var btn = document.querySelector('.ps-qf-picker-btn[data-qf-picker="' + key + '"]');
    if (!hidden || !btn) return;
    var span = btn.querySelector('span');
    var val = String(hidden.value || '').trim();
    if (val === '') {
      span.textContent = quickLabel[key] || 'All';
      span.classList.add('muted');
      btn.classList.remove('active');
    } else {
      span.textContent = val;
      span.classList.remove('muted');
      btn.classList.add('active');
    }
  }

  function renderQuickPopup(key) {
    var popup = document.getElementById('qf-popup-' + key);
    if (!popup) return;
    var current = String((qf[key] && qf[key].value) || '').trim();
    var html = '<div class="ps-qf-popup-head"><input type="text" class="ps-qf-popup-search" data-qf-popup-search="' + key + '" placeholder="Search..."></div>';
    html += '<div class="ps-qf-popup-list">';
    html += '<div class="ps-qf-popup-item' + (current === '' ? ' active' : '') + '" data-qf-key="' + key + '" data-qf-value="">All</div>';
    (quickSource[key] || []).forEach(function (v) {
      var safe = v.replace(/"/g, '&quot;');
      html += '<div class="ps-qf-popup-item' + (current === v ? ' active' : '') + '" data-qf-key="' + key + '" data-qf-value="' + safe + '">' + v + '</div>';
    });
    html += '</div>';
    popup.innerHTML = html;
  }

  function closeQuickPopup() {
    if (!activeQuickPopup) return;
    var pop = document.getElementById('qf-popup-' + activeQuickPopup);
    if (pop) pop.classList.remove('open');
    activeQuickPopup = null;
  }

  function quickPass(row) {
    var s = norm(qf.search && qf.search.value);
    var b = norm(qf.barcode && qf.barcode.value);
    var u = norm(qf.used && qf.used.value);
    var d = norm(qf.dietype && qf.dietype.value);
    var c = norm(qf.core && qf.core.value);
    var min = parseFloat(String((qf.repeatMin && qf.repeatMin.value) || '').trim());
    var max = parseFloat(String((qf.repeatMax && qf.repeatMax.value) || '').trim());

    if (s) {
      var hit = false;
      for (var i = 0; i < 12; i++) {
        if (norm(cell(row, i)).indexOf(s) !== -1) { hit = true; break; }
      }
      if (!hit) return false;
    }
    if (b && norm(cell(row, 1)).indexOf(b) === -1) return false;
    if (u && norm(cell(row, 8)) !== u) return false;
    if (d && norm(cell(row, 9)) !== d) return false;
    if (c && norm(cell(row, 10)) !== c) return false;

    var r = repeatNum(row);
    if (!isNaN(min) && (r === null || r < min)) return false;
    if (!isNaN(max) && (r === null || r > max)) return false;
    return true;
  }

  function colPass(row) {
    var cols = Object.keys(activeColFilters);
    for (var i = 0; i < cols.length; i++) {
      var col = cols[i];
      var set = activeColFilters[col];
      if (!set || !set.size) continue;
      var value = norm(cell(row, parseInt(col, 10)));
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

  function updateFilterButton(col) {
    var btn = document.querySelector('.col-filter-btn[data-filter-col="' + col + '"]');
    if (!btn) return;
    var count = btn.querySelector('.filter-count');
    if (activeColFilters[col]) {
      btn.classList.add('active');
      count.textContent = activeColFilters[col].size;
    } else {
      btn.classList.remove('active');
      count.textContent = '';
    }
  }

  function buildUnique(col) {
    var vals = [];
    var seen = new Set();
    var hasBlank = false;
    rows.forEach(function (row) {
      if (!quickPass(row)) return;
      var t = cell(row, parseInt(col, 10)).trim();
      if (t === '') { hasBlank = true; return; }
      var k = norm(t);
      if (!seen.has(k)) { seen.add(k); vals.push(t); }
    });
    vals.sort(function (a, b) { return a.localeCompare(b); });
    return { vals: vals, hasBlank: hasBlank };
  }

  function syncSelectAll(col) {
    var pop = document.getElementById('cfp-' + col);
    if (!pop) return;
    var all = pop.querySelectorAll('.cfp-list input[type=checkbox]');
    var checked = pop.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    var sa = pop.querySelector('.cfp-select-all input');
    if (sa) sa.checked = all.length > 0 && checked.length === all.length;
  }

  function renderCfp(col) {
    var pop = document.getElementById('cfp-' + col);
    if (!pop) return;
    var data = buildUnique(col);
    var active = activeColFilters[col];
    var draft = new Set(active ? Array.from(active) : []);
    if (!active) {
      data.vals.forEach(function (v) { draft.add(norm(v)); });
      if (data.hasBlank) draft.add('__blank__');
    }

    var html = '<div class="cfp-head">';
    html += '<input type="text" class="cfp-search" data-col="' + col + '" placeholder="Search values...">';
    html += '<label class="cfp-select-all"><input type="checkbox" data-col="' + col + '"> <span>Select All</span></label>';
    html += '</div><div class="cfp-list">';
    html += '<label class="cfp-item" data-val="__blank__"><input type="checkbox" value="__blank__"> <span>Blanks</span></label>';
    data.vals.forEach(function (v) {
      var safe = v.replace(/"/g, '&quot;');
      html += '<label class="cfp-item" data-val="' + norm(v).replace(/"/g, '&quot;') + '"><input type="checkbox" value="' + safe + '"> <span>' + v + '</span></label>';
    });
    html += '</div><div class="cfp-foot"><button type="button" class="ok" data-col="' + col + '">OK</button><button type="button" class="apply" data-col="' + col + '">Apply</button></div>';
    pop.innerHTML = html;

    requestAnimationFrame(function () {
      pop.querySelectorAll('.cfp-list input[type=checkbox]').forEach(function (cb) {
        var token = cb.value === '__blank__' ? '__blank__' : norm(cb.value);
        cb.checked = draft.has(token);
      });
      syncSelectAll(col);
    });
  }

  function closeCfp() {
    if (!activeCfpCol) return;
    var pop = document.getElementById('cfp-' + activeCfpCol);
    if (pop) pop.classList.remove('open');
    activeCfpCol = null;
  }

  function commitDraft(col) {
    var pop = document.getElementById('cfp-' + col);
    if (!pop) return;
    var selected = pop.querySelectorAll('.cfp-list input[type=checkbox]:checked');
    var total = pop.querySelectorAll('.cfp-list input[type=checkbox]').length;
    if (selected.length === 0 || selected.length === total) {
      delete activeColFilters[col];
    } else {
      var set = new Set();
      selected.forEach(function (cb) {
        set.add(cb.value === '__blank__' ? '__blank__' : norm(cb.value));
      });
      activeColFilters[col] = set;
    }
    updateFilterButton(col);
    closeCfp();
    applyAllFilters();
  }

  window.resetQuickFilters = function () {
    Object.keys(qf).forEach(function (k) { if (qf[k]) qf[k].value = ''; });
    ['used', 'dietype', 'core'].forEach(updatePickerLabel);
    Object.keys(activeColFilters).forEach(function (col) { delete activeColFilters[col]; updateFilterButton(col); });
    applyAllFilters();
  };

  [qf.search, qf.barcode, qf.repeatMin, qf.repeatMax].forEach(function (el) {
    if (!el) return;
    el.addEventListener('input', applyAllFilters);
  });

  ['used', 'dietype', 'core'].forEach(updatePickerLabel);

  document.addEventListener('click', function (e) {
    var qfBtn = e.target.closest('.ps-qf-picker-btn');
    if (qfBtn) {
      var key = qfBtn.dataset.qfPicker;
      if (activeQuickPopup === key) { closeQuickPopup(); return; }
      closeQuickPopup();
      renderQuickPopup(key);
      var popup = document.getElementById('qf-popup-' + key);
      var rect = qfBtn.getBoundingClientRect();
      var left = Math.max(8, Math.min(rect.left, window.innerWidth - 280));
      popup.style.left = left + 'px';
      popup.style.top = (rect.bottom + 6) + 'px';
      popup.classList.add('open');
      activeQuickPopup = key;
      var search = popup.querySelector('.ps-qf-popup-search');
      if (search) search.focus();
      return;
    }

    var qfItem = e.target.closest('.ps-qf-popup-item');
    if (qfItem) {
      var key2 = qfItem.dataset.qfKey;
      if (qf[key2]) qf[key2].value = qfItem.dataset.qfValue || '';
      updatePickerLabel(key2);
      closeQuickPopup();
      applyAllFilters();
      return;
    }

    var btn = e.target.closest('.col-filter-btn');
    if (btn) {
      var col = btn.dataset.filterCol;
      if (activeCfpCol === col) { closeCfp(); return; }
      closeCfp();
      renderCfp(col);
      var pop = document.getElementById('cfp-' + col);
      var rect2 = btn.getBoundingClientRect();
      var popupW = 290;
      var popupH = 340;
      var left2 = Math.max(8, Math.min(rect2.left, window.innerWidth - popupW - 8));
      var top2 = rect2.bottom + 6;
      if (top2 + popupH > window.innerHeight) top2 = Math.max(8, rect2.top - popupH - 6);
      pop.style.left = left2 + 'px';
      pop.style.top = top2 + 'px';
      pop.classList.add('open');
      activeCfpCol = col;
      var cfpSearch = pop.querySelector('.cfp-search');
      if (cfpSearch) cfpSearch.focus();
      return;
    }

    if (activeCfpCol) {
      var cfp = document.getElementById('cfp-' + activeCfpCol);
      if (cfp && !cfp.contains(e.target)) closeCfp();
    }
    if (activeQuickPopup) {
      var qPopup = document.getElementById('qf-popup-' + activeQuickPopup);
      if (qPopup && !qPopup.contains(e.target)) closeQuickPopup();
    }
  });

  document.addEventListener('input', function (e) {
    if (e.target.classList.contains('ps-qf-popup-search')) {
      var key = e.target.dataset.qfPopupSearch;
      var q = norm(e.target.value);
      document.querySelectorAll('#qf-popup-' + key + ' .ps-qf-popup-item').forEach(function (item) {
        item.style.display = norm(item.textContent).indexOf(q) >= 0 ? '' : 'none';
      });
      return;
    }
    if (e.target.classList.contains('cfp-search')) {
      var col = e.target.dataset.col;
      var q2 = norm(e.target.value);
      document.querySelectorAll('#cfp-' + col + ' .cfp-item').forEach(function (item) {
        item.style.display = norm(item.dataset.val || '').indexOf(q2) >= 0 ? '' : 'none';
      });
    }
  });

  document.addEventListener('change', function (e) {
    if (!activeCfpCol) return;
    var popup = document.getElementById('cfp-' + activeCfpCol);
    if (!popup || !popup.contains(e.target)) return;
    if (e.target.closest('.cfp-select-all')) {
      popup.querySelectorAll('.cfp-list input[type=checkbox]').forEach(function (cb) { cb.checked = e.target.checked; });
    }
    syncSelectAll(activeCfpCol);
  });

  document.addEventListener('click', function (e) {
    if (e.target.classList.contains('ok') || e.target.classList.contains('apply')) {
      commitDraft(e.target.dataset.col);
    }
  });

  applyAllFilters();
})();
</script>

<?php if (!$isEmbedded) { include __DIR__ . '/../../includes/footer.php'; } ?>
