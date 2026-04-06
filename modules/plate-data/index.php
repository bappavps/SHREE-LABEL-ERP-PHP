<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/_common.php';

$db = getDB();
ensureDieToolingSchema($db);

$mode = (($_GET['mode'] ?? 'master') === 'design') ? 'design' : 'master';
$isDesignMode = $mode === 'design';
$moduleLabel = isset($dieToolingModuleLabelOverride) && trim((string)$dieToolingModuleLabelOverride) !== ''
  ? trim((string)$dieToolingModuleLabelOverride)
  : dieToolingModuleLabel();
$pageTitle = isset($dieToolingPageTitleOverride) && trim((string)$dieToolingPageTitleOverride) !== ''
  ? trim((string)$dieToolingPageTitleOverride)
  : (($isDesignMode ? 'Design ' : 'Master ') . $moduleLabel);
$isEmbedded = !empty($dieToolingEmbedded);
$moduleSlug = dieToolingModuleSlug();
$tableName = dieToolingTableName();
$sessionKey = 'import_preview_' . str_replace('-', '_', $moduleSlug);
$columns = dieToolingColumns();
$importColumns = dieToolingImportColumns();
$quickFilters = dieToolingQuickFilters();
$csrf = generateCSRF();

$dieToolingImageUploadError = '';

function dieToolingSetImageUploadError($message) {
  global $dieToolingImageUploadError;
  $dieToolingImageUploadError = trim((string)$message);
}

function dieToolingGetImageUploadError() {
  global $dieToolingImageUploadError;
  return trim((string)$dieToolingImageUploadError);
}

function dieToolingGetPaperTypeOptions(mysqli $db) {
  $options = [];
  $res = $db->query("SELECT DISTINCT TRIM(COALESCE(paper_type, '')) AS paper_type FROM paper_stock WHERE TRIM(COALESCE(paper_type, '')) <> '' ORDER BY paper_type ASC");
  if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
      $value = trim((string)($row['paper_type'] ?? ''));
      if ($value !== '') {
        $options[] = $value;
      }
    }
    $res->close();
  }
  return array_values(array_unique($options));
}

$paperTypeOptions = dieToolingGetPaperTypeOptions($db);
$paperTypeLookup = [];
foreach ($paperTypeOptions as $paperTypeOption) {
  $paperTypeLookup[mb_strtolower(trim((string)$paperTypeOption), 'UTF-8')] = $paperTypeOption;
}

function dieToolingNormalizeHeaderKey($value) {
  $value = mb_strtolower(trim((string)$value), 'UTF-8');
  $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
  $value = preg_replace('/\s+/', ' ', $value);
  return trim($value);
}

function dieToolingExcelColumnLabel($index) {
  $index = (int)$index;
  if ($index < 0) {
    return '';
  }

  $label = '';
  do {
    $remainder = $index % 26;
    $label = chr(65 + $remainder) . $label;
    $index = (int)floor($index / 26) - 1;
  } while ($index >= 0);

  return $label;
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

function dieToolingImageSrc($path) {
  $path = trim((string)$path);
  if ($path === '') {
    return '';
  }
  if (preg_match('/^https?:\/\//i', $path)) {
    return $path;
  }

  // Normalize Windows separators and recover relative upload path from legacy absolute values.
  $normalized = str_replace('\\', '/', $path);
  if (preg_match('/^[A-Za-z]:\//', $normalized) || strpos($normalized, '/') === 0) {
    $uploadsPos = stripos($normalized, '/uploads/');
    if ($uploadsPos !== false) {
      $normalized = ltrim(substr($normalized, $uploadsPos + 1), '/');
    }
  }

  // If only file name is stored, resolve it from the plate-data upload folder.
  if (strpos($normalized, '/') === false) {
    $probe = __DIR__ . '/../../uploads/library/plate-data/' . $normalized;
    if (is_file($probe)) {
      $normalized = 'uploads/library/plate-data/' . $normalized;
    }
  }

  if (function_exists('appUrl')) {
    return appUrl(ltrim($normalized, '/'));
  }
  return rtrim((string)BASE_URL, '/') . '/' . ltrim($normalized, '/');
}

function dieToolingNormalizeImportedDate($value) {
  $value = trim((string)$value);
  if ($value === '' || strtoupper($value) === 'NA') {
    return $value;
  }

  // Excel date serial (1900 system) -> readable date
  if (is_numeric($value)) {
    $serial = (float)$value;
    if ($serial >= 61 && $serial <= 60000) {
      $unixTime = (int)floor(($serial - 25569) * 86400);
      return gmdate('d-m-Y', $unixTime);
    }
  }

  // Try common human-readable date formats
  $normalized = str_replace('/', '-', $value);
  $timestamp = strtotime($normalized);
  if ($timestamp !== false) {
    return date('d-m-Y', $timestamp);
  }

  return $value;
}

function dieToolingDateForInput($value) {
  $value = trim((string)$value);
  if ($value === '' || strtoupper($value) === 'NA') {
    return '';
  }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    return $value;
  }
  $normalized = str_replace('/', '-', $value);
  $timestamp = strtotime($normalized);
  if ($timestamp !== false) {
    return date('Y-m-d', $timestamp);
  }
  return '';
}

function dieToolingSavePlateImage($inputName, $existingPath = '') {
  dieToolingSetImageUploadError('');

  if (!isset($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
    return $existingPath;
  }

  $file = $_FILES[$inputName];
  $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($error === UPLOAD_ERR_NO_FILE) {
    return $existingPath;
  }
  if ($error !== UPLOAD_ERR_OK) {
    dieToolingSetImageUploadError('Image upload failed. Please retry with a smaller valid image file.');
    return $existingPath;
  }

  $tmpName = (string)($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    dieToolingSetImageUploadError('Invalid upload source. Please select the image again.');
    return $existingPath;
  }

  $original = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
  $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'jfif', 'avif'];
  if (!in_array($ext, $allowed, true)) {
    dieToolingSetImageUploadError('Unsupported image format. Allowed: JPG, PNG, WEBP, GIF, BMP, JFIF, AVIF.');
    return $existingPath;
  }

  $uploadDir = __DIR__ . '/../../uploads/library/plate-data';
  if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
    @chmod($uploadDir, 0775);
  }
  if (!is_dir($uploadDir)) {
    dieToolingSetImageUploadError('Upload folder is not available. Please contact admin.');
    return $existingPath;
  }

  $name = 'plate_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $target = $uploadDir . '/' . $name;
  $moved = move_uploaded_file($tmpName, $target);
  if (!$moved) {
    // Fallback for environments where move_uploaded_file intermittently fails.
    $moved = @copy($tmpName, $target);
  }
  if (!$moved) {
    dieToolingSetImageUploadError('Failed to save uploaded image. Please check folder permission.');
    return $existingPath;
  }

  return 'uploads/library/plate-data/' . $name;
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
    if (trim((string)($data['date_received'] ?? '')) === '' || strtoupper(trim((string)($data['date_received'] ?? ''))) === 'NA') {
      $data['date_received'] = date('Y-m-d');
    }
    $paperTypeInput = mb_strtolower(trim((string)($data['paper_type'] ?? '')), 'UTF-8');
    if ($paperTypeInput !== '' && $paperTypeInput !== 'na' && !isset($paperTypeLookup[$paperTypeInput])) {
      setFlash('error', 'Please select Paper Type from Paper Stock list only.');
      redirect(dieToolingRedirectUrl($mode));
    }
    if (isset($paperTypeLookup[$paperTypeInput])) {
      $data['paper_type'] = $paperTypeLookup[$paperTypeInput];
    }
    $data['image_path'] = dieToolingSavePlateImage('image_path_file', $data['image_path'] ?? '');
    $imageUploadError = dieToolingGetImageUploadError();
    if ($imageUploadError !== '') {
      setFlash('error', $imageUploadError);
      redirect(dieToolingRedirectUrl($mode));
    }
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

    $existingImage = '';
    $currentStmt = $db->prepare("SELECT image_path FROM {$tableName} WHERE id=? LIMIT 1");
    if ($currentStmt) {
      $currentStmt->bind_param('i', $id);
      $currentStmt->execute();
      $currentRow = $currentStmt->get_result()->fetch_assoc();
      $existingImage = trim((string)($currentRow['image_path'] ?? ''));
      $currentStmt->close();
    }

    $data = dieToolingNormalizePayload($_POST);
    $paperTypeInput = mb_strtolower(trim((string)($data['paper_type'] ?? '')), 'UTF-8');
    if ($paperTypeInput !== '' && $paperTypeInput !== 'na' && !isset($paperTypeLookup[$paperTypeInput])) {
      setFlash('error', 'Please select Paper Type from Paper Stock list only.');
      redirect(dieToolingRedirectUrl($mode));
    }
    if (isset($paperTypeLookup[$paperTypeInput])) {
      $data['paper_type'] = $paperTypeLookup[$paperTypeInput];
    }
    $data['image_path'] = dieToolingSavePlateImage('image_path_file', $existingImage);
    $imageUploadError = dieToolingGetImageUploadError();
    if ($imageUploadError !== '') {
      setFlash('error', $imageUploadError);
      redirect(dieToolingRedirectUrl($mode));
    }
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

    $nextSerial = 1;
    $serialRes = $db->query("SELECT COALESCE(MAX(CAST(TRIM(sl_no) AS UNSIGNED)), 0) AS max_sl FROM {$tableName} WHERE TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$'");
    if ($serialRes instanceof mysqli_result) {
      $serialRow = $serialRes->fetch_assoc();
      $nextSerial = ((int)($serialRow['max_sl'] ?? 0)) + 1;
      $serialRes->close();
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
        if ($key !== 'sl_no' && $key !== 'image_path' && $value === '') {
          $value = 'NA';
        }

        if ($key === 'date_received' || $key === 'date_used') {
          $value = dieToolingNormalizeImportedDate($value);
        }

        $payload[$key] = dieToolingCleanText($value, 190);
      }

      // Import file serial is ignored; serial number is always auto-generated.
      $payload['sl_no'] = (string)$nextSerial;

      $values = [];
      foreach ($importColumnKeys as $key) {
        $values[] = $payload[$key] ?? '';
      }
      $stmt->bind_param(str_repeat('s', count($values)), ...$values);
      if ($stmt->execute()) {
        $inserted++;
        $nextSerial++;
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

$searchQuery = trim((string)($_GET['q'] ?? ''));
$activeQuickFilters = [];
$whereClauses = [];
$quickWhereClauses = [];

$rowOrderSql = "CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC";

foreach ($quickFilters as $filter) {
  $filterType = (string)($filter['type'] ?? '');
  $filterKey = (string)($filter['key'] ?? '');
  if ($filterKey === '' || !array_key_exists($filterKey, $columns)) {
    continue;
  }

  if ($filterType === 'text' || $filterType === 'pick') {
    $paramKey = 'qf_' . $filterKey;
    $value = trim((string)($_GET[$paramKey] ?? ''));
    $activeQuickFilters[$paramKey] = $value;
    if ($value !== '') {
      $valueEsc = $db->real_escape_string($value);
      if ($filterType === 'text') {
        if ($filterKey === 'plate') {
          $condition = "TRIM(COALESCE(`{$filterKey}`, '')) = '{$valueEsc}'";
        } else {
          $condition = "COALESCE(`{$filterKey}`, '') LIKE '%{$valueEsc}%'";
        }
      } else {
        $condition = "TRIM(COALESCE(`{$filterKey}`, '')) = '{$valueEsc}'";
      }
      $whereClauses[] = $condition;
      $quickWhereClauses[] = $condition;
    }
    continue;
  }

  if ($filterType === 'number_range') {
    $minParam = 'qf_' . $filterKey . '_min';
    $maxParam = 'qf_' . $filterKey . '_max';
    $minRaw = trim((string)($_GET[$minParam] ?? ''));
    $maxRaw = trim((string)($_GET[$maxParam] ?? ''));
    $activeQuickFilters[$minParam] = $minRaw;
    $activeQuickFilters[$maxParam] = $maxRaw;

    $numericExpr = "CASE WHEN TRIM(COALESCE(`{$filterKey}`, '')) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(TRIM(`{$filterKey}`) AS DECIMAL(18,4)) ELSE NULL END";

    if ($minRaw !== '' && is_numeric($minRaw)) {
      $minValue = (float)$minRaw;
      $condition = "{$numericExpr} >= {$minValue}";
      $whereClauses[] = $condition;
      $quickWhereClauses[] = $condition;
    }
    if ($maxRaw !== '' && is_numeric($maxRaw)) {
      $maxValue = (float)$maxRaw;
      $condition = "{$numericExpr} <= {$maxValue}";
      $whereClauses[] = $condition;
      $quickWhereClauses[] = $condition;
    }
  }
}

if ($searchQuery !== '') {
  $searchEsc = $db->real_escape_string($searchQuery);
  $searchLike = "'%" . $searchEsc . "%'";
  $searchFields = array_merge(['sl_no'], array_keys($columns));
  $searchParts = ["CAST(id AS CHAR) LIKE {$searchLike}"];
  foreach ($searchFields as $fieldKey) {
    $searchParts[] = "COALESCE(`{$fieldKey}`, '') LIKE {$searchLike}";
  }

  // Support searching by visual Sl.NO fallback (ordered row position) across full filtered dataset.
  if (ctype_digit($searchQuery)) {
    $serialNo = (int)$searchQuery;
    if ($serialNo > 0) {
      $serialOffset = $serialNo - 1;
      $quickWhereSql = $quickWhereClauses ? (' WHERE ' . implode(' AND ', $quickWhereClauses)) : '';
      $serialSql = "SELECT id FROM {$tableName}{$quickWhereSql} ORDER BY {$rowOrderSql} LIMIT {$serialOffset}, 1";
      $serialRes = $db->query($serialSql);
      if ($serialRes instanceof mysqli_result) {
        $serialRow = $serialRes->fetch_assoc();
        $serialRes->close();
        if ($serialRow && isset($serialRow['id'])) {
          $searchParts[] = 'id = ' . (int)$serialRow['id'];
        }
      }
    }
  }

  if ($searchParts) {
    $whereClauses[] = '(' . implode(' OR ', $searchParts) . ')';
  }
}

$whereSql = $whereClauses ? (' WHERE ' . implode(' AND ', $whereClauses)) : '';

$allowedRowsPerPage = [10, 20, 40, 50, 100];
$rowsPerPageRaw = strtoupper(trim((string)($_GET['per_page'] ?? '100')));
$showAllRows = $rowsPerPageRaw === 'ALL';
$rowsPerPage = 100;
if (!$showAllRows) {
  $rowsPerPageCandidate = (int)$rowsPerPageRaw;
  if (in_array($rowsPerPageCandidate, $allowedRowsPerPage, true)) {
    $rowsPerPage = $rowsPerPageCandidate;
  }
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalRows = 0;
$countRes = $db->query("SELECT COUNT(*) AS total_count FROM {$tableName}{$whereSql}");
if ($countRes instanceof mysqli_result) {
  $countRow = $countRes->fetch_assoc();
  $totalRows = (int)($countRow['total_count'] ?? 0);
  $countRes->close();
}

if ($showAllRows) {
  $rowsPerPage = max(1, $totalRows);
  $totalPages = 1;
  $currentPage = 1;
  $offset = 0;
} else {
  $totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
  if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
  }
  $offset = ($currentPage - 1) * $rowsPerPage;
}

$rows = [];
$rowSql = "SELECT * FROM {$tableName}{$whereSql} ORDER BY {$rowOrderSql}";
if (!$showAllRows) {
  $rowSql .= " LIMIT {$offset}, {$rowsPerPage}";
}
$res = $db->query($rowSql);
if ($res) {
  $rows = $res->fetch_all(MYSQLI_ASSOC);
}

$calculatorPlates = [];
$calcRes = $db->query("SELECT id, sl_no, name, plate, qty_roll, ups, repeat_value FROM {$tableName} ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
if ($calcRes instanceof mysqli_result) {
  while ($calcRow = $calcRes->fetch_assoc()) {
    $calcName = trim((string)($calcRow['name'] ?? ''));
    $calcPlate = trim((string)($calcRow['plate'] ?? ''));
    $calcSlNo = trim((string)($calcRow['sl_no'] ?? ''));
    $calcLabelParts = [];
    if ($calcSlNo !== '') {
      $calcLabelParts[] = $calcSlNo;
    }
    if ($calcName !== '') {
      $calcLabelParts[] = $calcName;
    }
    if ($calcPlate !== '') {
      $calcLabelParts[] = $calcPlate;
    }
    $calcLabel = trim(implode(' | ', $calcLabelParts));
    if ($calcLabel === '') {
      $calcLabel = 'Plate #' . (int)($calcRow['id'] ?? 0);
    }
    $calculatorPlates[] = [
      'id' => (int)($calcRow['id'] ?? 0),
      'label' => $calcLabel,
      'sl_no' => $calcSlNo,
      'job_name' => $calcName,
      'plate_no' => $calcPlate,
      'qty_roll' => trim((string)($calcRow['qty_roll'] ?? '')),
      'ups' => trim((string)($calcRow['ups'] ?? '')),
      'repeat_value' => trim((string)($calcRow['repeat_value'] ?? '')),
    ];
  }
  $calcRes->close();
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

$flash = getFlash();
$exportBase = BASE_URL . '/modules/' . $moduleSlug . '/export.php';
$columnCount = count($columns) + ($isDesignMode ? 2 : 3);
$quickFiltersJson = json_encode(array_values($quickFilters), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$nameColumnKey = '';
foreach ($columns as $key => $label) {
  if ($key === 'image_path') {
    continue;
  }
  if ($nameColumnKey === '' && (strcasecmp((string)$key, 'name') === 0 || stripos((string)$label, 'name') !== false)) {
    $nameColumnKey = (string)$key;
  }
}

$hasActiveServerFilter = ($searchQuery !== '');
if (!$hasActiveServerFilter) {
  foreach ($activeQuickFilters as $filterVal) {
    if (trim((string)$filterVal) !== '') {
      $hasActiveServerFilter = true;
      break;
    }
  }
}
$showNoResultModal = $hasActiveServerFilter && $totalRows === 0;
$noResultContext = $searchQuery !== '' ? ('Search: ' . $searchQuery) : 'Applied filter(s)';

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
.table-responsive{overflow-x:auto}
.table-scroll-top{overflow-x:auto;overflow-y:hidden;height:14px;margin:0 0 8px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}
.table-scroll-top-inner{height:1px}
.module-table{min-width:2600px}
.module-table thead th{background:#dbeafe;color:#1e3a8a;border-color:#bfdbfe;font-weight:700;white-space:nowrap}
.module-table tbody td{border-color:#e2e8f0;white-space:nowrap}
.plate-thumb{width:42px;height:42px;border-radius:8px;object-fit:cover;border:1px solid #cbd5e1;background:#f8fafc}
.plate-preview-lg{width:86px;height:86px;border-radius:10px;object-fit:cover;border:1px solid #cbd5e1;background:#f8fafc}
.name-link-btn{background:none;border:none;color:#1d4ed8;font-weight:700;cursor:pointer;text-decoration:underline;padding:0}
.detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.detail-item{border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#f8fafc}
.detail-key{font-size:.68rem;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.05em}
.detail-val{font-size:.85rem;color:#0f172a;margin-top:3px;word-break:break-word}
.camera-modal-card{max-width:620px}
.camera-video{width:100%;max-height:340px;background:#0f172a;border-radius:10px;object-fit:cover}
.camera-help{font-size:.78rem;color:#64748b;margin-top:8px}
.module-pagination{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 4px 2px}.module-page-list{display:flex;gap:6px;flex-wrap:wrap}.module-page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:32px;padding:0 10px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text-main);font-size:.78rem;font-weight:700;text-decoration:none}.module-page-btn.active{background:#0f172a;color:#fff;border-color:#0f172a}.module-page-btn.disabled{opacity:.5;pointer-events:none}
.plate-calc-box{margin-top:10px;border:1px solid #fdba74;border-radius:12px;background:#fff7ed;padding:12px}
.plate-calc-title{font-size:.8rem;font-weight:800;color:#9a3412;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
.plate-calc-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
.plate-calc-grid select,.plate-calc-grid input{height:38px;border:1px solid #fdba74;border-radius:8px;padding:0 10px;background:#fff;font-size:.85rem}
.plate-calc-grid input[readonly]{background:#f8fafc;font-weight:800;color:#0f172a}
.plate-calc-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}
.plate-calc-meta .meta-item{border:1px solid #fdba74;border-radius:8px;background:#fff;padding:8px 10px}
.plate-calc-meta .meta-key{font-size:.65rem;font-weight:800;color:#9a3412;text-transform:uppercase;letter-spacing:.06em}
.plate-calc-meta .meta-val{margin-top:3px;font-size:.84rem;color:#0f172a;font-weight:700;word-break:break-word}
@media (max-width:1200px){.plate-calc-grid{grid-template-columns:1fr 1fr}.detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:980px){.qf{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000}.modal-card{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:95%;max-width:1100px;max-height:90vh;overflow:auto;background:#fff;border-radius:10px;box-shadow:0 20px 40px rgba(0,0,0,.2)}.modal-head{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-bottom:1px solid var(--border)}.form-grid-2{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px}.form-group{display:flex;flex-direction:column;gap:6px}.form-group label{font-size:.76rem;font-weight:700;color:#475569}.form-group input,.form-group select{height:38px;border:1px solid var(--border);border-radius:8px;padding:0 10px}.form-actions{display:flex;justify-content:flex-end;gap:8px}.col-span-all{grid-column:1/-1}
@media (max-width:900px){.form-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.module-table{font-size:.75rem}.detail-grid{grid-template-columns:1fr}}
@media (max-width:768px){.qf{display:grid;grid-template-columns:1fr;margin:8px 0 10px}.qf-item{min-width:auto}.qf-actions{padding-top:10px;min-width:auto;width:100%}.qf-reset{width:100%;justify-content:center}.detail-grid{grid-template-columns:1fr}.plate-calc-grid{grid-template-columns:1fr}.plate-calc-meta{grid-template-columns:1fr}.module-actions{flex-direction:column;align-items:stretch}.module-btn{width:100%;justify-content:center}.modal-card{width:90%;max-height:95vh;top:50%;max-width:500px}.form-grid-2{grid-template-columns:1fr;padding:10px}}
@media (max-width:640px){.module-table{min-width:900px;font-size:.7rem}.module-table thead th{padding:4px 2px}.module-table tbody td{padding:4px 2px}.qf{padding:8px;gap:6px;margin:6px 0 8px}.qf-item{gap:2px}.qf-item label{font-size:.55rem}.qf-item input,.qf-item select{height:32px;padding:0 8px;font-size:.7rem}.text-responsive{font-size:.7rem !important}.detail-grid{grid-template-columns:1fr;gap:8px}.detail-item{padding:6px}.detail-key{font-size:.6rem}.detail-val{font-size:.75rem}.plate-calc-box{padding:8px;margin-top:6px}.plate-calc-title{font-size:.7rem;margin-bottom:6px}.plate-calc-grid{grid-template-columns:1fr;gap:8px}.plate-calc-grid select,.plate-calc-grid input{height:34px;padding:0 8px;font-size:.75rem}.card-header{padding:8px 10px}.form-grid-2{grid-template-columns:1fr;padding:8px;gap:8px}.module-bulk-bar{padding:8px 10px;gap:8px;flex-direction:column}.module-bulk-meta{font-size:.75rem}.module-pagination{padding:8px 4px}.module-btn{padding:6px 10px;font-size:.7rem}.qf-popup,.cfp{width:90vw;max-width:280px}.col-filter-btn{font-size:8px}}
@media (max-width:360px){.card{border-radius:10px}.card-header{padding:7px 8px}.card-title{font-size:.78rem}.module-actions{gap:6px}.module-btn{padding:6px 8px;font-size:.66rem;gap:4px}.qf{padding:6px;gap:5px;margin:5px 0 7px}.qf-item label{font-size:.5rem;letter-spacing:.04em}.qf-item input,.qf-item select{height:30px;padding:0 6px;font-size:.66rem}.qf-reset{height:32px;font-size:.66rem;padding:0 10px}.module-table{min-width:820px}.module-table thead th{padding:3px 2px;font-size:.62rem}.module-table tbody td{padding:3px 2px;font-size:.64rem}.detail-item{padding:5px}.detail-key{font-size:.55rem}.detail-val{font-size:.68rem}.plate-calc-box{padding:7px}.plate-calc-title{font-size:.66rem}.plate-calc-grid select,.plate-calc-grid input{height:32px;font-size:.68rem}.modal-head{padding:8px 10px}.modal-body-pad{padding:8px 10px}.modal-form-input{height:34px;font-size:.66rem}.module-page-btn{min-width:30px;height:28px;padding:0 7px;font-size:.66rem}.qf-popup,.cfp{max-width:260px}}
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
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <span class="card-title"><?= e($moduleLabel) ?> Table</span>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <label for="rowsPerPageSelect" style="font-size:.78rem;color:#475569;font-weight:700;">Rows:</label>
      <select id="rowsPerPageSelect" style="height:34px;border:1px solid var(--border);border-radius:8px;padding:0 10px;font-size:.78rem;background:#fff;">
        <?php foreach ($allowedRowsPerPage as $perPageOption): ?>
          <option value="<?= (int)$perPageOption ?>" <?= (!$showAllRows && (int)$rowsPerPage === (int)$perPageOption) ? 'selected' : '' ?>><?= (int)$perPageOption ?></option>
        <?php endforeach; ?>
        <option value="ALL" <?= $showAllRows ? 'selected' : '' ?>>ALL</option>
      </select>
      <span id="tableVisibleCount" data-total-rows="<?= (int)$totalRows ?>" style="font-size:.82rem;color:var(--text-muted);">Visible Rows: <?= (int)count($rows) ?> / <?= (int)count($rows) ?> | Total: <?= (int)$totalRows ?></span>
    </div>
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

  <?php if ($isDesignMode): ?>
    <div class="plate-calc-box no-print" id="plateCalcBox">
      <div class="plate-calc-title">Plate Meter Calculation</div>
      <div class="plate-calc-grid">
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Select Plate</label>
          <select id="plateCalcSelect">
            <option value="">-- Select Plate --</option>
            <?php foreach ($calculatorPlates as $calcPlate): ?>
              <option value="<?= (int)$calcPlate['id'] ?>" data-sl-no="<?= e((string)$calcPlate['sl_no']) ?>" data-plate-no="<?= e((string)$calcPlate['plate_no']) ?>" data-job-name="<?= e((string)$calcPlate['job_name']) ?>" data-qty-roll="<?= e((string)$calcPlate['qty_roll']) ?>" data-ups="<?= e((string)$calcPlate['ups']) ?>" data-repeat="<?= e((string)$calcPlate['repeat_value']) ?>"><?= e((string)$calcPlate['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Quantity</label>
          <input type="number" id="plateCalcQty" min="0" step="1" placeholder="Enter qty">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#1e3a8a;display:block;margin-bottom:4px;">Meter</label>
          <input type="number" id="plateCalcMeter" min="0" step="0.01" placeholder="Enter meter">
        </div>
      </div>
      <div class="plate-calc-meta">
        <div class="meta-item"><div class="meta-key">SL No</div><div class="meta-val" id="plateCalcSlNo">-</div></div>
        <div class="meta-item"><div class="meta-key">Plate No</div><div class="meta-val" id="plateCalcPlateNo">-</div></div>
        <div class="meta-item"><div class="meta-key">Job Name</div><div class="meta-val" id="plateCalcJobName">-</div></div>
      </div>
      <div style="font-size:.74rem;color:#9a3412;margin-top:8px;">Enter Quantity to get Meter, or enter Meter to get Quantity. Formula: If Qty.Roll available → Meter = Qty / Qty.Roll; else → Meter = (Qty / UPS) × (Repeat / 1000)</div>
    </div>
  <?php endif; ?>

  <div class="qf no-print" id="moduleQuickFilter">
    <div class="qf-item"><label>Global Search</label><input type="text" id="qf-search" value="<?= e($searchQuery) ?>" placeholder="Search everything..."></div>
    <?php foreach ($quickFilters as $filter): ?>
      <?php if (($filter['type'] ?? '') === 'text'): ?>
        <div class="qf-item">
          <label><?= e((string)$filter['label']) ?></label>
          <input type="text" id="qf-<?= e((string)$filter['key']) ?>" value="<?= e((string)($activeQuickFilters['qf_' . (string)$filter['key']] ?? '')) ?>" placeholder="<?= e((string)($filter['placeholder'] ?? 'Search...')) ?>">
        </div>
      <?php elseif (($filter['type'] ?? '') === 'pick'): ?>
        <div class="qf-item">
          <label><?= e((string)$filter['label']) ?></label>
          <div class="qf-picker">
            <input type="hidden" id="qf-<?= e((string)$filter['key']) ?>" value="<?= e((string)($activeQuickFilters['qf_' . (string)$filter['key']] ?? '')) ?>">
            <button type="button" class="qf-picker-btn" data-qf-picker="<?= e((string)$filter['key']) ?>"><span class="muted"><?= e((string)($filter['allLabel'] ?? 'All')) ?></span><i class="bi bi-chevron-down"></i></button>
            <div class="qf-popup" id="qf-popup-<?= e((string)$filter['key']) ?>"></div>
          </div>
        </div>
      <?php elseif (($filter['type'] ?? '') === 'number_range'): ?>
        <div class="qf-item"><label><?= e((string)$filter['label']) ?> Min</label><input type="number" step="0.01" id="qf-<?= e((string)$filter['key']) ?>-min" value="<?= e((string)($activeQuickFilters['qf_' . (string)$filter['key'] . '_min'] ?? '')) ?>" placeholder="<?= e((string)($filter['minPlaceholder'] ?? 'Min')) ?>"></div>
        <div class="qf-item"><label><?= e((string)$filter['label']) ?> Max</label><input type="number" step="0.01" id="qf-<?= e((string)$filter['key']) ?>-max" value="<?= e((string)($activeQuickFilters['qf_' . (string)$filter['key'] . '_max'] ?? '')) ?>" placeholder="<?= e((string)($filter['maxPlaceholder'] ?? 'Max')) ?>"></div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="qf-actions"><button type="button" class="qf-reset" onclick="resetQuickFilters()">Reset</button></div>
  </div>

  <div class="table-scroll-top" id="tableScrollTop"><div class="table-scroll-top-inner" id="tableScrollTopInner"></div></div>
  <div class="table-responsive" style="overflow-x:auto;" id="tableScrollBody">
    <table class="table module-table" id="moduleDataTable">
      <thead>
        <tr>
          <?php if (!$isDesignMode): ?>
            <th class="bulk-check-col no-print"><input type="checkbox" id="selectAllRows"></th>
          <?php endif; ?>
          <th><span class="col-filter-wrap">Sl.NO<button type="button" class="col-filter-btn no-print" data-filter-field="__sl_no__"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-__sl_no__"></div></span></th>
          <th><span class="col-filter-wrap">Plate Number<button type="button" class="col-filter-btn no-print" data-filter-field="plate"><i class="bi bi-funnel-fill"></i><span class="filter-count"></span></button><div class="cfp no-print" id="cfp-plate"></div></span></th>
          <?php foreach ($columns as $key => $label): ?>
            <?php if ($key === 'plate') { continue; } ?>
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
          <?php $displaySlNo = trim((string)($row['sl_no'] ?? '')); ?>
          <tr>
            <?php if (!$isDesignMode): ?>
              <td class="bulk-check-col no-print"><input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>"></td>
            <?php endif; ?>
            <td data-field="__sl_no__"><?= e($displaySlNo !== '' ? $displaySlNo : (string)($index + 1)) ?></td>
            <td data-field="plate"><?= e(trim((string)($row['plate'] ?? ''))) ?></td>
            <?php foreach ($columns as $key => $label): ?>
              <?php if ($key === 'plate') { continue; } ?>
              <?php
                $rawValue = (string)($row[$key] ?? '');
                $displayValue = dieToolingDisplayValue($rawValue);
              ?>
              <?php if ($key === 'image_path'): ?>
                <?php $imgSrc = dieToolingImageSrc($rawValue); ?>
                <td data-field="<?= e($key) ?>">
                  <?php if ($imgSrc !== ''): ?>
                    <img src="<?= e($imgSrc) ?>" alt="Plate" class="plate-thumb">
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              <?php elseif ($nameColumnKey !== '' && $key === $nameColumnKey): ?>
                <td data-field="<?= e($key) ?>">
                  <a class="name-link-btn" href="<?= dieToolingRedirectUrl($mode) ?>?edit_id=<?= (int)$row['id'] ?>"><?= e($displayValue) ?></a>
                </td>
              <?php else: ?>
                <td data-field="<?= e($key) ?>"><?= e($displayValue) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
            <td>
              <a class="btn btn-sm btn-primary" href="<?= dieToolingRedirectUrl($mode) ?>?edit_id=<?= (int)$row['id'] ?>"><i class="bi bi-pencil"></i></a>
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

  <?php if ($totalPages > 1): ?>
    <?php
      $basePath = dieToolingRedirectUrl($mode);
      $pageBaseParams = ['per_page' => $showAllRows ? 'ALL' : (string)$rowsPerPage];
      if ($searchQuery !== '') {
        $pageBaseParams['q'] = $searchQuery;
      }
      foreach ($activeQuickFilters as $filterParamKey => $filterParamValue) {
        if (trim((string)$filterParamValue) === '') {
          continue;
        }
        $pageBaseParams[$filterParamKey] = (string)$filterParamValue;
      }
      $pageBaseQuery = http_build_query($pageBaseParams);
      $queryJoiner = (strpos($basePath, '?') !== false) ? '&' : '?';
      $prevPage = max(1, $currentPage - 1);
      $nextPage = min($totalPages, $currentPage + 1);
      $windowStart = max(1, $currentPage - 3);
      $windowEnd = min($totalPages, $currentPage + 3);
    ?>
    <div class="module-pagination no-print">
      <div style="font-size:.78rem;color:#64748b;">Showing page <?= (int)$currentPage ?> of <?= (int)$totalPages ?> (<?= (int)$rowsPerPage ?> rows/page)</div>
      <div class="module-page-list">
        <a class="module-page-btn <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= e($basePath) ?><?= e($queryJoiner) ?><?= e($pageBaseQuery) ?>&page=<?= (int)$prevPage ?>">Prev</a>
        <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
          <a class="module-page-btn <?= $p === $currentPage ? 'active' : '' ?>" href="<?= e($basePath) ?><?= e($queryJoiner) ?><?= e($pageBaseQuery) ?>&page=<?= (int)$p ?>"><?= (int)$p ?></a>
        <?php endfor; ?>
        <a class="module-page-btn <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= e($basePath) ?><?= e($queryJoiner) ?><?= e($pageBaseQuery) ?>&page=<?= (int)$nextPage ?>">Next</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($isDesignMode): ?>
<div class="modal-overlay" id="addRecordModal">
  <div class="modal-card">
    <div class="modal-head">
      <strong>Add <?= e($moduleLabel) ?></strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeAddModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" class="form-grid-2" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="add_record">
      <?php foreach ($columns as $key => $label): ?>
        <div class="form-group">
          <label><?= e($label) ?></label>
          <?php if ($key === 'image_path'): ?>
            <input type="hidden" name="image_path" value="">
            <input type="file" id="addImageFileInput" class="js-plate-image-input" data-preview-id="addImagePreview" name="image_path_file" accept="image/*" capture="environment">
            <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap;">
              <button type="button" class="btn btn-sm btn-secondary js-open-camera" data-input-id="addImageFileInput" data-preview-id="addImagePreview"><i class="bi bi-camera"></i> Camera</button>
              <span style="font-size:.74rem;color:#64748b;">On mobile, tap Camera to capture directly.</span>
            </div>
            <div id="addImagePreviewWrap" style="margin-top:8px;display:none;"><img id="addImagePreview" src="" alt="Selected image" class="plate-preview-lg"></div>
          <?php elseif ($key === 'paper_type'): ?>
            <select name="paper_type">
              <option value="">-- Select Paper Type --</option>
              <?php foreach ($paperTypeOptions as $paperTypeOption): ?>
                <option value="<?= e($paperTypeOption) ?>"><?= e($paperTypeOption) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($key === 'date_received'): ?>
            <input type="date" name="date_received" value="<?= e(date('Y-m-d')) ?>">
          <?php else: ?>
            <input type="text" name="<?= e($key) ?>" value="" list="field-suggestions-<?= e($key) ?>" autocomplete="off">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="form-actions col-span-all">
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
      <strong>Edit <?= e($moduleLabel) ?></strong>
      <a class="btn btn-sm btn-ghost" href="<?= dieToolingRedirectUrl($mode) ?>"><i class="bi bi-x-lg"></i></a>
    </div>
    <?php
      $editImageSrc = dieToolingImageSrc((string)($editingRow['image_path'] ?? ''));
      $editSlNo = trim((string)($editingRow['sl_no'] ?? ''));
      $editPlateNo = trim((string)($editingRow['plate'] ?? ''));
    ?>
    <div style="padding:12px 14px 4px;display:flex;flex-direction:column;gap:10px;">
      <div style="font-size:.78rem;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.06em;">View Summary</div>
      <?php if ($editImageSrc !== ''): ?>
        <div>
          <img src="<?= e($editImageSrc) ?>" alt="Record image" style="max-width:220px;max-height:220px;border:1px solid #cbd5e1;border-radius:10px;object-fit:cover;">
        </div>
      <?php endif; ?>
      <div class="detail-grid">
        <div class="detail-item"><div class="detail-key">SL No.</div><div class="detail-val"><?= e($editSlNo !== '' ? $editSlNo : '-') ?></div></div>
        <div class="detail-item"><div class="detail-key">Plate Number</div><div class="detail-val"><?= e($editPlateNo !== '' ? $editPlateNo : '-') ?></div></div>
        <?php foreach ($columns as $key => $label): ?>
          <?php if ($key === 'image_path' || $key === 'plate'): continue; endif; ?>
          <?php
            $summaryRaw = (string)($editingRow[$key] ?? '');
            $summaryVal = dieToolingDisplayValue($summaryRaw);
          ?>
          <div class="detail-item"><div class="detail-key"><?= e($label) ?></div><div class="detail-val"><?= e($summaryVal !== '' ? $summaryVal : '-') ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <form method="POST" class="form-grid-2" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update_record">
      <input type="hidden" name="id" value="<?= (int)$editingRow['id'] ?>">
      <?php foreach ($columns as $key => $label): ?>
        <div class="form-group">
          <label><?= e($label) ?></label>
          <?php if ($key === 'image_path'): ?>
            <?php $existingImgSrc = dieToolingImageSrc((string)($editingRow[$key] ?? '')); ?>
            <input type="hidden" name="image_path" value="<?= e((string)($editingRow[$key] ?? '')) ?>">
            <input type="file" id="editImageFileInput" class="js-plate-image-input" data-preview-id="editImagePreview" name="image_path_file" accept="image/*" capture="environment">
            <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap;">
              <button type="button" class="btn btn-sm btn-secondary js-open-camera" data-input-id="editImageFileInput" data-preview-id="editImagePreview"><i class="bi bi-camera"></i> Camera</button>
              <span style="font-size:.74rem;color:#64748b;">Preview updates immediately after a new photo is selected.</span>
            </div>
            <div id="editImagePreviewWrap" style="margin-top:8px;<?= $existingImgSrc !== '' ? 'display:block;' : 'display:none;' ?>"><img id="editImagePreview" src="<?= e($existingImgSrc) ?>" alt="Current image" class="plate-preview-lg"></div>
          <?php elseif ($key === 'paper_type'): ?>
            <?php $currentPaperType = trim((string)($editingRow['paper_type'] ?? '')); ?>
            <select name="paper_type">
              <option value="">-- Select Paper Type --</option>
              <?php foreach ($paperTypeOptions as $paperTypeOption): ?>
                <option value="<?= e($paperTypeOption) ?>" <?= $currentPaperType === (string)$paperTypeOption ? 'selected' : '' ?>><?= e($paperTypeOption) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($key === 'date_received'): ?>
            <input type="date" name="date_received" value="<?= e(dieToolingDateForInput((string)($editingRow[$key] ?? ''))) ?>">
          <?php else: ?>
            <input type="text" name="<?= e($key) ?>" value="<?= e((string)($editingRow[$key] ?? '')) ?>" list="field-suggestions-<?= e($key) ?>" autocomplete="off">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="form-actions col-span-all">
        <a class="btn btn-secondary" href="<?= dieToolingRedirectUrl($mode) ?>">Cancel</a>
        <button type="submit" class="btn btn-success">Update</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="detailRecordModal">
  <div class="modal-card" style="max-width:1200px;">
    <div class="modal-head">
      <strong><?= e($moduleLabel) ?> Details</strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeDetailModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:14px;display:flex;flex-direction:column;gap:12px;">
      <div id="detailImageWrap" style="display:none;">
        <img id="detailImage" src="" alt="Record image" style="max-width:220px;max-height:220px;border:1px solid #cbd5e1;border-radius:10px;object-fit:cover;">
      </div>
      <div id="detailGrid" class="detail-grid"></div>
    </div>
  </div>
</div>

<?php if ($showNoResultModal): ?>
<div class="modal-overlay" id="noResultModal" style="display:block;">
  <div class="modal-card" style="max-width:460px;">
    <div class="modal-head">
      <strong>No Data Found</strong>
      <button class="btn btn-sm btn-ghost" type="button" onclick="closeNoResultModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:16px;display:flex;flex-direction:column;gap:10px;">
      <div style="font-size:.9rem;color:#334155;">No records matched your input.</div>
      <div style="font-size:.78rem;color:#64748b;">Context: <?= e($noResultContext) ?></div>
      <div style="display:flex;justify-content:flex-end;">
        <button class="btn btn-primary" type="button" onclick="closeNoResultModal()">OK</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="cameraCaptureModal">
  <div class="modal-card camera-modal-card">
    <div class="modal-head">
      <strong>Capture Image</strong>
      <button class="btn btn-sm btn-ghost" type="button" id="cameraCloseBtn"><i class="bi bi-x-lg"></i></button>
    </div>
    <div style="padding:14px;display:flex;flex-direction:column;gap:10px;">
      <video id="cameraCaptureVideo" class="camera-video" autoplay playsinline muted></video>
      <canvas id="cameraCaptureCanvas" style="display:none;"></canvas>
      <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
        <button class="btn btn-secondary" type="button" id="cameraTakeBtn"><i class="bi bi-camera-fill"></i> Capture</button>
        <button class="btn btn-success" type="button" id="cameraUseBtn" disabled><i class="bi bi-check2-circle"></i> Use This Photo</button>
      </div>
      <div class="camera-help">Tip: After first permission, the camera stream is reused in this page session. Some phones may still prompt again due to browser policy.</div>
    </div>
  </div>
</div>

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
              <?php foreach ((array)$importPreview['headers'] as $headerIndex => $header): ?>
                <?php
                  $header = (string)$header;
                  $excelCol = dieToolingExcelColumnLabel((int)$headerIndex);
                  $displayLabel = trim($header) !== '' ? ($excelCol . ' | ' . $header) : $excelCol;
                ?>
                <option value="<?= e($header) ?>" <?= ($suggested !== '' && $suggested === $header) ? 'selected' : '' ?>><?= e($displayLabel) ?></option>
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
  var rowsPerPageSelect = document.getElementById('rowsPerPageSelect');
  if (rowsPerPageSelect) {
    rowsPerPageSelect.addEventListener('change', function () {
      var value = String(rowsPerPageSelect.value || '100').trim().toUpperCase();
      var url = new URL(window.location.href);
      url.searchParams.set('per_page', value);
      url.searchParams.set('page', '1');
      window.location.href = url.toString();
    });
  }

  var calcSelect = document.getElementById('plateCalcSelect');
  var calcQty = document.getElementById('plateCalcQty');
  var calcMeter = document.getElementById('plateCalcMeter');
  var calcSlNo = document.getElementById('plateCalcSlNo');
  var calcPlateNo = document.getElementById('plateCalcPlateNo');
  var calcJobName = document.getElementById('plateCalcJobName');
  if (calcSelect && calcQty && calcMeter) {
    var calcMissingModal = null;
    var lastMissingSignature = '';

    function ensureCalcMissingModal() {
      if (calcMissingModal) return calcMissingModal;

      var overlay = document.createElement('div');
      overlay.id = 'plateCalcMissingModal';
      overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:3500;align-items:center;justify-content:center;padding:14px;';

      var card = document.createElement('div');
      card.style.cssText = 'width:100%;max-width:420px;background:#fff;border-radius:12px;box-shadow:0 20px 45px rgba(2,6,23,.35);overflow:hidden;border:1px solid #e2e8f0;';
      card.innerHTML = '' +
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#fff7ed;border-bottom:1px solid #fed7aa;">' +
          '<strong style="font-size:.92rem;color:#9a3412;">Calculation blocked</strong>' +
          '<button type="button" data-close="1" style="border:none;background:transparent;color:#9a3412;font-size:1rem;cursor:pointer;line-height:1;">&times;</button>' +
        '</div>' +
        '<div style="padding:12px;">' +
          '<div style="font-size:.84rem;color:#334155;">Required data missing for calculator:</div>' +
          '<ul data-missing-list style="margin:8px 0 0;padding-left:18px;font-size:.84rem;color:#b91c1c;"></ul>' +
        '</div>' +
        '<div style="padding:0 12px 12px;display:flex;justify-content:flex-end;">' +
          '<button type="button" data-close="1" class="btn btn-secondary">OK</button>' +
        '</div>';

      overlay.appendChild(card);
      document.body.appendChild(overlay);

      overlay.addEventListener('click', function (event) {
        var target = event.target;
        if (target === overlay || (target && target.getAttribute && target.getAttribute('data-close') === '1')) {
          hideCalcMissingModal();
        }
      });

      calcMissingModal = overlay;
      return calcMissingModal;
    }

    function hideCalcMissingModal(resetSignature) {
      if (calcMissingModal) calcMissingModal.style.display = 'none';
      if (resetSignature) lastMissingSignature = '';
    }

    function showCalcMissingModal(missingFields) {
      var cleanMissing = (missingFields || []).filter(function (item) { return String(item || '').trim() !== ''; });
      if (!cleanMissing.length) {
        hideCalcMissingModal(false);
        return;
      }

      var signature = cleanMissing.join('|');
      if (signature === lastMissingSignature) {
        return;
      }
      lastMissingSignature = signature;

      var modal = ensureCalcMissingModal();
      var list = modal.querySelector('[data-missing-list]');
      if (list) {
        list.innerHTML = '';
        cleanMissing.forEach(function (fieldName) {
          var li = document.createElement('li');
          li.textContent = fieldName;
          list.appendChild(li);
        });
      }
      modal.style.display = 'flex';
    }

    function parseNum(raw) {
      var cleaned = String(raw || '').trim().replace(/,/g, '').replace(/[^0-9.\-]/g, '');
      var n = parseFloat(cleaned);
      return isNaN(n) ? 0 : n;
    }
    function getPlateParams() {
      var option = calcSelect.options[calcSelect.selectedIndex] || null;
      return {
        selected: !!(option && String(option.value || '').trim() !== ''),
        qtyRoll: parseNum(option ? option.getAttribute('data-qty-roll') : 0),
        ups: parseNum(option ? option.getAttribute('data-ups') : 0),
        repeatValue: parseNum(option ? option.getAttribute('data-repeat') : 0)
      };
    }
    function getMissingCalcFields(params) {
      if (!params || !params.selected) {
        return ['Plate Selection'];
      }
      if (params.qtyRoll > 0) {
        return [];
      }
      var missing = [];
      if (params.ups <= 0) missing.push('UPS');
      if (params.repeatValue <= 0) missing.push('Repeat Value');
      return missing;
    }
    function updatePlateMeta() {
      var option = calcSelect.options[calcSelect.selectedIndex] || null;
      var slNo = option ? String(option.getAttribute('data-sl-no') || '').trim() : '';
      var plateNo = option ? String(option.getAttribute('data-plate-no') || '').trim() : '';
      var jobName = option ? String(option.getAttribute('data-job-name') || '').trim() : '';
      if (calcSlNo) calcSlNo.textContent = slNo !== '' ? slNo : '-';
      if (calcPlateNo) calcPlateNo.textContent = plateNo !== '' ? plateNo : '-';
      if (calcJobName) calcJobName.textContent = jobName !== '' ? jobName : '-';
    }
    function recalcFromQty() {
      var p = getPlateParams();
      var qty = parseNum(calcQty.value || 0);
      if (qty <= 0) { calcMeter.value = ''; hideCalcMissingModal(true); return; }

      var missing = getMissingCalcFields(p);
      if (missing.length) {
        calcMeter.value = '';
        showCalcMissingModal(missing);
        return;
      }

      var meter = 0;
      if (p.qtyRoll > 0) {
        meter = qty / p.qtyRoll;
      } else if (p.ups > 0 && p.repeatValue > 0) {
        meter = (qty / p.ups) * (p.repeatValue / 1000);
      }
      hideCalcMissingModal(true);
      calcMeter.value = meter > 0 ? meter.toFixed(2) : '';
    }
    function recalcFromMeter() {
      var p = getPlateParams();
      var meter = parseNum(calcMeter.value || 0);
      if (meter <= 0) { calcQty.value = ''; hideCalcMissingModal(true); return; }

      var missing = getMissingCalcFields(p);
      if (missing.length) {
        calcQty.value = '';
        showCalcMissingModal(missing);
        return;
      }

      var qty = 0;
      if (p.qtyRoll > 0) {
        qty = meter * p.qtyRoll;
      } else if (p.ups > 0 && p.repeatValue > 0) {
        qty = (meter / (p.repeatValue / 1000)) * p.ups;
      }
      hideCalcMissingModal(true);
      calcQty.value = qty > 0 ? Math.round(qty) : '';
    }
    calcQty.addEventListener('input', function () { recalcFromQty(); });
    calcMeter.addEventListener('input', function () { recalcFromMeter(); });
    calcSelect.addEventListener('change', function () {
      hideCalcMissingModal(true);
      updatePlateMeta();
      if (parseNum(calcQty.value) > 0) recalcFromQty();
      else if (parseNum(calcMeter.value) > 0) recalcFromMeter();
    });
    updatePlateMeta();
  }

  var detailLabels = <?= json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
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
  window.closeDetailModal = function () {
    var modal = document.getElementById('detailRecordModal');
    if (modal) modal.style.display = 'none';
  };
  window.closeNoResultModal = function () {
    var modal = document.getElementById('noResultModal');
    if (modal) modal.style.display = 'none';
  };

  var cameraState = {
    modal: document.getElementById('cameraCaptureModal'),
    video: document.getElementById('cameraCaptureVideo'),
    canvas: document.getElementById('cameraCaptureCanvas'),
    closeBtn: document.getElementById('cameraCloseBtn'),
    takeBtn: document.getElementById('cameraTakeBtn'),
    useBtn: document.getElementById('cameraUseBtn'),
    stream: null,
    blob: null,
    activeInputId: '',
    activePreviewId: ''
  };

  function setInlinePreview(input, previewId, blobUrl) {
    var preview = document.getElementById(previewId || '');
    if (!preview) return;
    var wrap = document.getElementById((previewId || '') + 'Wrap') || preview.parentElement;
    if (!wrap) return;

    if (blobUrl && blobUrl !== '') {
      preview.setAttribute('src', blobUrl);
      wrap.style.display = 'block';
      return;
    }

    var file = (input && input.files && input.files[0]) ? input.files[0] : null;
    if (!file) return;
    var objectUrl = URL.createObjectURL(file);
    preview.setAttribute('src', objectUrl);
    wrap.style.display = 'block';
  }

  function bindImageInputPreview() {
    document.querySelectorAll('.js-plate-image-input').forEach(function (input) {
      var previewId = input.getAttribute('data-preview-id') || '';
      input.addEventListener('change', function () {
        setInlinePreview(input, previewId, '');
      });
    });
  }

  async function ensureCameraStream() {
    if (!cameraState.video) return false;
    if (cameraState.stream) {
      cameraState.video.srcObject = cameraState.stream;
      return true;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      return false;
    }
    try {
      cameraState.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false
      });
      cameraState.video.srcObject = cameraState.stream;
      return true;
    } catch (err) {
      try {
        cameraState.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        cameraState.video.srcObject = cameraState.stream;
        return true;
      } catch (fallbackErr) {
        return false;
      }
    }
  }

  function hideCameraModal() {
    if (cameraState.modal) cameraState.modal.style.display = 'none';
    cameraState.blob = null;
    if (cameraState.useBtn) cameraState.useBtn.disabled = true;
  }

  async function openCameraModal(inputId, previewId) {
    var targetInput = document.getElementById(inputId || '');
    if (!targetInput || !cameraState.modal) return;

    cameraState.activeInputId = inputId;
    cameraState.activePreviewId = previewId || '';
    cameraState.blob = null;
    if (cameraState.useBtn) cameraState.useBtn.disabled = true;

    var ok = await ensureCameraStream();
    if (!ok) {
      window.alert('Camera access is unavailable. Please use file picker instead.');
      targetInput.click();
      return;
    }
    cameraState.modal.style.display = 'block';
  }

  function captureFromVideo() {
    if (!cameraState.video || !cameraState.canvas) return;
    var w = cameraState.video.videoWidth || 0;
    var h = cameraState.video.videoHeight || 0;
    if (w <= 0 || h <= 0) return;

    cameraState.canvas.width = w;
    cameraState.canvas.height = h;
    var ctx = cameraState.canvas.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(cameraState.video, 0, 0, w, h);
    cameraState.canvas.toBlob(function (blob) {
      cameraState.blob = blob || null;
      if (cameraState.useBtn) cameraState.useBtn.disabled = !cameraState.blob;
    }, 'image/jpeg', 0.92);
  }

  function useCapturedPhoto() {
    if (!cameraState.blob) return;
    var input = document.getElementById(cameraState.activeInputId || '');
    if (!input) return;

    var file = new File([cameraState.blob], 'camera_' + Date.now() + '.jpg', { type: 'image/jpeg' });
    if (typeof DataTransfer === 'undefined') {
      window.alert('This browser does not support camera file injection. Please use file picker upload.');
      return;
    }
    var dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));

    var blobUrl = URL.createObjectURL(cameraState.blob);
    setInlinePreview(input, cameraState.activePreviewId, blobUrl);
    hideCameraModal();
  }

  function bindCameraActions() {
    document.querySelectorAll('.js-open-camera').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var inputId = btn.getAttribute('data-input-id') || '';
        var previewId = btn.getAttribute('data-preview-id') || '';
        openCameraModal(inputId, previewId);
      });
    });

    if (cameraState.closeBtn) {
      cameraState.closeBtn.addEventListener('click', hideCameraModal);
    }
    if (cameraState.modal) {
      cameraState.modal.addEventListener('click', function (event) {
        if (event.target === cameraState.modal) hideCameraModal();
      });
    }
    if (cameraState.takeBtn) {
      cameraState.takeBtn.addEventListener('click', captureFromVideo);
    }
    if (cameraState.useBtn) {
      cameraState.useBtn.addEventListener('click', useCapturedPhoto);
    }

    window.addEventListener('beforeunload', function () {
      if (cameraState.stream) {
        cameraState.stream.getTracks().forEach(function (track) { track.stop(); });
        cameraState.stream = null;
      }
    });
  }

  bindImageInputPreview();
  bindCameraActions();

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function openDetailFromButton(button) {
    var row = button ? button.closest('tr') : null;
    var modal = document.getElementById('detailRecordModal');
    var grid = document.getElementById('detailGrid');
    var imageWrap = document.getElementById('detailImageWrap');
    var image = document.getElementById('detailImage');
    if (!row || !modal || !grid || !imageWrap || !image) return;

    var cells = Array.prototype.slice.call(row.querySelectorAll('td[data-field]'));
    var html = '';
    var imageSrc = '';

    cells.forEach(function (cell) {
      var field = String(cell.getAttribute('data-field') || '');
      var label = field === '__sl_no__' ? 'SL No.' : (detailLabels[field] || field);

      if (field === 'image_path') {
        var img = cell.querySelector('img');
        imageSrc = img ? String(img.getAttribute('src') || '') : '';
        return;
      }

      var value = String(cell.textContent || '').trim();
      html += '<div class="detail-item"><div class="detail-key">' + escapeHtml(label) + '</div><div class="detail-val">' + escapeHtml(value || '-') + '</div></div>';
    });

    grid.innerHTML = html;
    if (imageSrc !== '') {
      image.setAttribute('src', imageSrc);
      imageWrap.style.display = 'block';
    } else {
      image.setAttribute('src', '');
      imageWrap.style.display = 'none';
    }
    modal.style.display = 'block';
  }

  document.addEventListener('click', function (event) {
    var detailBtn = event.target.closest('.name-link-btn');
    if (detailBtn && detailBtn.hasAttribute('data-detail-id')) {
      openDetailFromButton(detailBtn);
    }
  });

  document.addEventListener('click', function (event) {
    var addModal = document.getElementById('addRecordModal');
    if (addModal && event.target === addModal) closeAddModal();
    var importModal = document.getElementById('importMapModal');
    if (importModal && event.target === importModal) closeImportMapModal();
    var detailModal = document.getElementById('detailRecordModal');
    if (detailModal && event.target === detailModal) closeDetailModal();
    var noResultModal = document.getElementById('noResultModal');
    if (noResultModal && event.target === noResultModal) closeNoResultModal();
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
  var topScroller = document.getElementById('tableScrollTop');
  var topInner = document.getElementById('tableScrollTopInner');
  var bodyScroller = document.getElementById('tableScrollBody');
  var table = document.getElementById('moduleDataTable');
  if (!topScroller || !topInner || !bodyScroller || !table) return;

  var syncingTop = false;
  var syncingBody = false;

  function syncWidth() {
    topInner.style.width = table.scrollWidth + 'px';
  }

  topScroller.addEventListener('scroll', function () {
    if (syncingBody) return;
    syncingTop = true;
    bodyScroller.scrollLeft = topScroller.scrollLeft;
    syncingTop = false;
  });

  bodyScroller.addEventListener('scroll', function () {
    if (syncingTop) return;
    syncingBody = true;
    topScroller.scrollLeft = bodyScroller.scrollLeft;
    syncingBody = false;
  });

  window.addEventListener('resize', syncWidth);
  syncWidth();
})();

(function () {
  var table = document.getElementById('moduleDataTable');
  if (!table) return;

  var qfConfig = <?= $quickFiltersJson ?: '[]' ?>;
  var paperTypeOptions = <?= json_encode(array_values($paperTypeOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function (row) { return row.querySelector('td[data-field]'); });
  var tableVisibleCount = document.getElementById('tableVisibleCount');
  var loadedRowsCount = rows.length;
  var totalRowsCount = tableVisibleCount ? parseInt(String(tableVisibleCount.getAttribute('data-total-rows') || '0'), 10) || 0 : 0;
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

  var serverFilterTimer = null;
  function isCompactFilterViewport() {
    return window.innerWidth <= 900 || (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
  }

  function applyServerFiltersNow() {
    var url = new URL(window.location.href);

    var globalSearchValue = String((qf.search && qf.search.value) || '').trim();
    if (globalSearchValue !== '') {
      url.searchParams.set('q', globalSearchValue);
    } else {
      url.searchParams.delete('q');
    }

    qfConfig.forEach(function (filter) {
      if (filter.type === 'text' || filter.type === 'pick') {
        var key = 'qf_' + filter.key;
        var value = String((qf[filter.key] && qf[filter.key].value) || '').trim();
        if (value !== '') {
          url.searchParams.set(key, value);
        } else {
          url.searchParams.delete(key);
        }
      }
      if (filter.type === 'number_range') {
        var minKey = 'qf_' + filter.key + '_min';
        var maxKey = 'qf_' + filter.key + '_max';
        var minVal = String((qf[filter.key + '_min'] && qf[filter.key + '_min'].value) || '').trim();
        var maxVal = String((qf[filter.key + '_max'] && qf[filter.key + '_max'].value) || '').trim();
        if (minVal !== '') {
          url.searchParams.set(minKey, minVal);
        } else {
          url.searchParams.delete(minKey);
        }
        if (maxVal !== '') {
          url.searchParams.set(maxKey, maxVal);
        } else {
          url.searchParams.delete(maxKey);
        }
      }
    });

    url.searchParams.set('page', '1');
    var nextUrl = url.toString();
    if (nextUrl === window.location.href) return;
    window.location.href = nextUrl;
  }

  function applyServerFiltersDebounced() {
    if (isCompactFilterViewport()) {
      if (serverFilterTimer) {
        window.clearTimeout(serverFilterTimer);
        serverFilterTimer = null;
      }
      return;
    }
    if (serverFilterTimer) {
      window.clearTimeout(serverFilterTimer);
    }
    serverFilterTimer = window.setTimeout(function () {
      applyServerFiltersNow();
    }, 350);
  }

  function uniqueFieldValues(field) {
    if (field === 'paper_type' && Array.isArray(paperTypeOptions) && paperTypeOptions.length) {
      return paperTypeOptions.slice().sort(function (left, right) { return String(left).localeCompare(String(right)); });
    }
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

  function updateVisibleCount() {
    if (!tableVisibleCount) return;
    var visible = 0;
    rows.forEach(function (row) {
      if (row.style.display !== 'none') {
        visible += 1;
      }
    });
    tableVisibleCount.textContent = 'Visible Rows: ' + visible + ' / ' + loadedRowsCount + ' | Total: ' + totalRowsCount;
  }

  function applyAllFilters() {
    rows.forEach(function (row) {
      row.style.display = (quickPass(row) && colPass(row)) ? '' : 'none';
    });
    updateVisibleCount();
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
    var url = new URL(window.location.href);
    url.searchParams.delete('q');
    qfConfig.forEach(function (filter) {
      if (filter.type === 'text' || filter.type === 'pick') {
        url.searchParams.delete('qf_' + filter.key);
      } else if (filter.type === 'number_range') {
        url.searchParams.delete('qf_' + filter.key + '_min');
        url.searchParams.delete('qf_' + filter.key + '_max');
      }
    });
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
  };

  function bindServerFilterInput(el) {
    if (!el) return;
    el.addEventListener('input', function () {
      if (serverFilterTimer) {
        window.clearTimeout(serverFilterTimer);
        serverFilterTimer = null;
      }
    });
    el.addEventListener('change', function () {
      if (serverFilterTimer) {
        window.clearTimeout(serverFilterTimer);
        serverFilterTimer = null;
      }
      applyServerFiltersNow();
    });
    el.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      if (serverFilterTimer) {
        window.clearTimeout(serverFilterTimer);
        serverFilterTimer = null;
      }
      applyServerFiltersNow();
    });
  }

  bindServerFilterInput(qf.search);
  qfConfig.forEach(function (filter) {
    if (filter.type === 'text' && qf[filter.key]) {
      bindServerFilterInput(qf[filter.key]);
    }
    if (filter.type === 'number_range') {
      bindServerFilterInput(qf[filter.key + '_min']);
      bindServerFilterInput(qf[filter.key + '_max']);
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
      applyServerFiltersNow();
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
  updateVisibleCount();
})();
</script>

<?php if (!$isEmbedded) { include __DIR__ . '/../../includes/footer.php'; } ?>
