<?php
// ============================================================
// ERP System — Stock Import & Export Hub
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

function importTrimBom($value) {
    $value = is_string($value) ? trim($value) : trim((string)$value);
    return preg_replace('/^\xEF\xBB\xBF/', '', $value);
}

function importNormalizeHeader($value) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(importTrimBom($value)));
}

function importFieldAliases() {
    return [
        'roll_no' => ['roll_no', 'rollno', 'roll', 'rollnumber', 'rollid', 'roll no'],
        'paper_type' => ['paper_type', 'papertype', 'type', 'substrate', 'paper type'],
        'company' => ['company', 'papercompany', 'paper_company', 'paper company', 'supplier', 'vendor'],
        'width_mm' => ['width_mm', 'widthmm', 'width', 'width mm'],
        'length_mtr' => ['length_mtr', 'lengthmtr', 'length', 'length mtr', 'lengthmeters', 'length_meters'],
        'sqm' => ['sqm'],
        'gsm' => ['gsm'],
        'weight_kg' => ['weight_kg', 'weightkg', 'weight', 'weight kg'],
        'purchase_rate' => ['purchase_rate', 'purchaserate', 'rate', 'purchase rate'],
        'lot_batch_no' => ['lot_batch_no', 'lotbatchno', 'lotno', 'lot', 'batchno', 'lot no batch no'],
        'company_roll_no' => ['company_roll_no', 'companyrollno', 'parentrollno', 'company roll no'],
        'status' => ['status', 'inventorystatus', 'rollstatus'],
        'job_no' => ['job_no', 'jobno', 'job no'],
        'job_size' => ['job_size', 'jobsize', 'job size'],
        'job_name' => ['job_name', 'jobname', 'job name'],
        'date_received' => ['date_received', 'receiveddate', 'dateofreceived', 'date received'],
        'date_used' => ['date_used', 'dateused', 'dateofused', 'date used'],
        'remarks' => ['remarks', 'remark', 'notes'],
    ];
}

function importBuildHeaderMap(array $headerRow) {
    $normalizedHeaders = [];
    foreach ($headerRow as $index => $header) {
        $normalized = importNormalizeHeader($header);
        if ($normalized !== '') {
            $normalizedHeaders[$index] = $normalized;
        }
    }

    $map = [];
    foreach (importFieldAliases() as $field => $aliases) {
        foreach ($normalizedHeaders as $index => $header) {
            if (in_array($header, array_map('importNormalizeHeader', $aliases), true)) {
                $map[$field] = $index;
                break;
            }
        }
    }

    return $map;
}

  function importSystemFieldOptions() {
    return [
      'roll_no' => 'roll_no (required)',
      'paper_type' => 'paper_type (required)',
      'company' => 'company (required)',
      'width_mm' => 'width_mm (required)',
      'length_mtr' => 'length_mtr (required)',
      'gsm' => 'gsm (optional)',
      'status' => 'status (optional)',
      'sqm' => 'sqm (optional)',
      'weight_kg' => 'weight_kg (optional)',
      'purchase_rate' => 'purchase_rate (optional)',
      'lot_batch_no' => 'lot_batch_no (optional)',
      'company_roll_no' => 'company_roll_no (optional)',
      'job_no' => 'job_no (optional)',
      'job_size' => 'job_size (optional)',
      'job_name' => 'job_name (optional)',
      'date_received' => 'date_received (optional)',
      'date_used' => 'date_used (optional)',
      'remarks' => 'remarks (optional)',
    ];
  }

  function importRequiredFields() {
    return ['roll_no', 'paper_type', 'company', 'width_mm', 'length_mtr'];
  }

  function importSuggestColumnMapping(array $headerRow, array $rememberedMapping = []) {
    $suggested = [];
    $usedFields = [];
    $fieldToIndex = importBuildHeaderMap($headerRow);

    foreach ($headerRow as $index => $header) {
      $normalized = importNormalizeHeader($header);
      if ($normalized === '') {
        continue;
      }

      if (isset($rememberedMapping[$normalized]) && !isset($usedFields[$rememberedMapping[$normalized]])) {
        $suggested[$index] = $rememberedMapping[$normalized];
        $usedFields[$rememberedMapping[$normalized]] = true;
      }
    }

    foreach ($fieldToIndex as $field => $index) {
      if (!isset($suggested[$index]) && !isset($usedFields[$field])) {
        $suggested[$index] = $field;
        $usedFields[$field] = true;
      }
    }

    return $suggested;
  }

  function importBuildFieldIndexMap(array $selectedMapping) {
    $fieldIndexMap = [];
    foreach ($selectedMapping as $index => $field) {
      $field = trim((string)$field);
      if ($field === '') {
        continue;
      }
      $fieldIndexMap[$field] = (int)$index;
    }
    return $fieldIndexMap;
  }

  function importExcelColumnLabel($index) {
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

function importColumnIndexFromCellRef($cellReference) {
    if (!preg_match('/[A-Z]+/i', (string)$cellReference, $matches)) {
        return 0;
    }

    $letters = strtoupper($matches[0]);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function importReadXlsxSharedStrings(ZipArchive $zip) {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }

    $document = simplexml_load_string($xml);
    if (!$document) {
        return [];
    }

    $sharedStrings = [];
    foreach ($document->xpath('//*[local-name()="si"]') as $item) {
      $parts = $item->xpath('.//*[local-name()="t"]');
        $value = '';
        if ($parts) {
            foreach ($parts as $part) {
                $value .= (string)$part;
            }
        }
        $sharedStrings[] = importTrimBom($value);
    }

    return $sharedStrings;
}

function importFindFirstWorksheetPath(ZipArchive $zip) {
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $workbookXml = $zip->getFromName('xl/workbook.xml');

    if ($relsXml !== false && $workbookXml !== false) {
        $rels = simplexml_load_string($relsXml);
        $workbook = simplexml_load_string($workbookXml);
        if ($rels && $workbook) {
            $relationships = [];
            foreach ($rels->Relationship as $relationship) {
                $relationships[(string)$relationship['Id']] = (string)$relationship['Target'];
            }

            $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if ($sheets && isset($sheets[0])) {
                $relationshipId = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                if ($relationshipId && isset($relationships[$relationshipId])) {
                    return 'xl/' . ltrim($relationships[$relationshipId], '/');
                }
            }
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            return $name;
        }
    }

    return null;
}

function importExtractXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings) {
    $type = (string)$cell['t'];

    if ($type === 'inlineStr') {
    $textParts = $cell->xpath('.//*[local-name()="t"]');
        $value = '';
        if ($textParts) {
            foreach ($textParts as $part) {
                $value .= (string)$part;
            }
        }
        return importTrimBom($value);
    }

    $value = isset($cell->v) ? (string)$cell->v : '';
    if ($type === 's') {
        $index = (int)$value;
        return $sharedStrings[$index] ?? '';
    }

    if ($type === 'b') {
        return $value === '1' ? 'TRUE' : 'FALSE';
    }

    return importTrimBom($value);
}

function importParseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is not enabled on this PHP installation, so XLSX files cannot be read.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Unable to open the uploaded XLSX file.');
    }

    $worksheetPath = importFindFirstWorksheetPath($zip);
    if (!$worksheetPath) {
        $zip->close();
        throw new RuntimeException('Could not find a worksheet inside the XLSX file.');
    }

    $worksheetXml = $zip->getFromName($worksheetPath);
    if ($worksheetXml === false) {
        $zip->close();
        throw new RuntimeException('Could not read worksheet data from the XLSX file.');
    }

    $sharedStrings = importReadXlsxSharedStrings($zip);
    $zip->close();

    $worksheet = simplexml_load_string($worksheetXml);
    if (!$worksheet) {
        throw new RuntimeException('Worksheet XML is invalid or unreadable.');
    }

    $rows = [];
    foreach ($worksheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $values = [];
        $maxIndex = -1;

        foreach ($row->c as $cell) {
            $index = importColumnIndexFromCellRef((string)$cell['r']);
            $values[$index] = importExtractXlsxCellValue($cell, $sharedStrings);
            if ($index > $maxIndex) {
                $maxIndex = $index;
            }
        }

        if ($maxIndex < 0) {
            continue;
        }

        $normalized = [];
        for ($i = 0; $i <= $maxIndex; $i++) {
            $normalized[$i] = isset($values[$i]) ? importTrimBom($values[$i]) : '';
        }
        $rows[] = $normalized;
    }

    return $rows;
}

function importParseCsvFile($filePath) {
    $rows = [];
    $file = new SplFileObject($filePath);
    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

    foreach ($file as $row) {
        if ($row === false || $row === [null]) {
            continue;
        }

        $rows[] = array_map(function($value) {
            return importTrimBom($value ?? '');
        }, $row);
    }

    return $rows;
}

function importParseSpreadsheet($filePath, $fileName) {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        return importParseCsvFile($filePath);
    }

    if ($extension === 'xlsx') {
        return importParseXlsxFile($filePath);
    }

    throw new RuntimeException('Only CSV and XLSX files are supported.');
}

function importNormalizeNumber($value) {
    $value = str_replace(',', '', importTrimBom($value));
    if ($value === '') {
        return null;
    }

    return is_numeric($value) ? (float)$value : null;
}

function importNormalizeDate($value) {
    $value = importTrimBom($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial > 20000 && $serial < 80000) {
            return gmdate('Y-m-d', (int)(($serial - 25569) * 86400));
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : $value;
}

function importNormalizeStatus($value) {
    $value = importTrimBom($value);
    $allowed = ['Available', 'Assigned', 'Consumed'];
    return in_array($value, $allowed, true) ? $value : 'Available';
}

function importIsEmptyRow(array $row) {
    foreach ($row as $value) {
        if (importTrimBom($value) !== '') {
            return false;
        }
    }
    return true;
}

function importFetchExistingRolls(mysqli $db, array $rollNumbers) {
    $rollNumbers = array_values(array_unique(array_filter($rollNumbers)));
    if (empty($rollNumbers)) {
        return [];
    }

    $existing = [];
    foreach (array_chunk($rollNumbers, 250) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $stmt = $db->prepare("SELECT roll_no FROM paper_stock WHERE roll_no IN ($placeholders)");
        $stmt->bind_param($types, ...$chunk);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing[] = $row['roll_no'];
        }
        $stmt->close();
    }

    return array_values(array_unique($existing));
}

function importPrepareAnalysis(array $parsedRows, mysqli $db, $fileName, array $selectedMapping) {
    if (count($parsedRows) < 2) {
        throw new RuntimeException('The file must contain a header row and at least one data row.');
    }

    $headerRow = array_map('importTrimBom', $parsedRows[0]);
  $fieldIndexMap = importBuildFieldIndexMap($selectedMapping);
  $requiredFields = importRequiredFields();
    $allFields = array_keys(importFieldAliases());

    $rows = [];
    $preview = [];
    $errorItems = [];
    $rowCount = 0;
    $fileDuplicates = 0;
    $seenRolls = [];

    foreach (array_slice($parsedRows, 1) as $offset => $row) {
        if (importIsEmptyRow($row)) {
            continue;
        }

        $rowCount++;
        $rowNumber = $offset + 2;
        $record = [];
        foreach ($allFields as $field) {
          $columnIndex = $fieldIndexMap[$field] ?? null;
            $record[$field] = $columnIndex !== null ? importTrimBom($row[$columnIndex] ?? '') : '';
        }

        $normalized = [
            'roll_no' => $record['roll_no'],
            'paper_type' => $record['paper_type'],
            'company' => $record['company'],
            'width_mm' => importNormalizeNumber($record['width_mm']),
            'length_mtr' => importNormalizeNumber($record['length_mtr']),
            'sqm' => importNormalizeNumber($record['sqm']),
            'gsm' => importNormalizeNumber($record['gsm']),
            'weight_kg' => importNormalizeNumber($record['weight_kg']),
            'purchase_rate' => importNormalizeNumber($record['purchase_rate']),
            'lot_batch_no' => $record['lot_batch_no'] !== '' ? $record['lot_batch_no'] : null,
            'company_roll_no' => $record['company_roll_no'] !== '' ? $record['company_roll_no'] : null,
            'status' => importNormalizeStatus($record['status']),
            'job_no' => $record['job_no'] !== '' ? $record['job_no'] : null,
            'job_size' => $record['job_size'] !== '' ? $record['job_size'] : null,
            'job_name' => $record['job_name'] !== '' ? $record['job_name'] : null,
            'date_received' => importNormalizeDate($record['date_received']),
            'date_used' => importNormalizeDate($record['date_used']),
            'remarks' => $record['remarks'] !== '' ? $record['remarks'] : null,
            '_row_number' => $rowNumber,
            '_errors' => [],
        ];

        if ($normalized['sqm'] === null && $normalized['width_mm'] !== null && $normalized['length_mtr'] !== null) {
            $normalized['sqm'] = round(calcSQM($normalized['width_mm'], $normalized['length_mtr']), 2);
        }

        foreach ($requiredFields as $field) {
          if (!isset($fieldIndexMap[$field])) {
                continue;
            }

            if ($field === 'width_mm' || $field === 'length_mtr') {
                if ($normalized[$field] === null) {
                    $normalized['_errors'][] = strtoupper($field) . ' is missing or invalid';
                }
                continue;
            }

            if (importTrimBom($normalized[$field]) === '') {
                $normalized['_errors'][] = strtoupper($field) . ' is required';
            }
        }

        $rollNo = $normalized['roll_no'];
        if ($rollNo !== '') {
            if (isset($seenRolls[$rollNo])) {
                $normalized['_errors'][] = 'Duplicate roll_no found in uploaded file';
                $fileDuplicates++;
            } else {
                $seenRolls[$rollNo] = true;
            }
        }

        $rows[] = $normalized;
    }

    foreach ($requiredFields as $field) {
    if (!isset($fieldIndexMap[$field])) {
            $errorItems[] = [
                'row' => 'Header',
                'message' => strtoupper($field) . ' column is missing from the uploaded file',
            ];
        }
    }

    $existingRolls = importFetchExistingRolls($db, array_column($rows, 'roll_no'));
    $existingLookup = array_fill_keys($existingRolls, true);
    foreach ($rows as $index => $row) {
        if ($row['roll_no'] !== '' && isset($existingLookup[$row['roll_no']])) {
            $rows[$index]['_errors'][] = 'roll_no already exists in paper stock';
        }
    }

    $validRows = 0;
    foreach ($rows as $row) {
        if (empty($row['_errors']) && empty($errorItems)) {
            $validRows++;
        }

        foreach ($row['_errors'] as $message) {
            $errorItems[] = [
                'row' => $row['_row_number'],
                'message' => $message,
            ];
        }

        if (count($preview) < 10) {
            $preview[] = $row;
        }
    }

    return [
        'rows' => $rows,
        'all_display_rows' => $rows,
        'preview' => $preview,
        'errors' => $errorItems,
        'summary' => [
            'file_name' => $fileName,
            'total_rows' => $rowCount,
            'valid_rows' => $validRows,
            'error_rows' => count($errorItems),
            'duplicate_rows' => $fileDuplicates + count($existingRolls),
        ],
    ];
}

function importResetSessionState() {
    unset($_SESSION['import_state']);
  unset($_SESSION['import_parsed_rows']);
  unset($_SESSION['import_header']);
  unset($_SESSION['import_mapping']);
    unset($_SESSION['import_rows']);
    unset($_SESSION['import_all_display']);
    unset($_SESSION['import_preview']);
    unset($_SESSION['import_errors']);
    unset($_SESSION['import_summary']);
    unset($_SESSION['import_result']);
    unset($_SESSION['import_skipped']);
}

if (($_GET['action'] ?? null) === 'download_template') {
    $template = [
        'roll_no', 'paper_type', 'company', 'width_mm', 'length_mtr',
        'sqm', 'gsm', 'weight_kg', 'purchase_rate', 'lot_batch_no', 'company_roll_no',
        'status', 'job_no', 'job_size', 'job_name', 'date_received',
        'date_used', 'remarks'
    ];

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ERP_Stock_Template_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, $template);
    fputcsv($output, [
        'T-2026-1001', 'Maplitho', 'JK Paper', '330', '4200',
        '1386', '70', '97.0', '88.00', 'LOT-A12', 'JK-8721',
        'Main', 'JC-2026-001', '100x75 mm', 'Haldirams Label', date('Y-m-d'),
        '', 'Sample data - replace with your records'
    ]);
    fclose($output);
    exit;
}

if (($_GET['reset'] ?? null) === '1') {
    importResetSessionState();
    redirect(BASE_URL . '/modules/stock-import/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'upload_file') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid form session. Please try again.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please choose a CSV or XLSX file before uploading.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $originalName = $_FILES['csv_file']['name'] ?? 'upload';
    $tmpName = $_FILES['csv_file']['tmp_name'] ?? '';
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        setFlash('error', 'Only CSV and XLSX files are supported.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    if ((int)($_FILES['csv_file']['size'] ?? 0) > 20 * 1024 * 1024) {
        setFlash('error', 'The selected file exceeds the 20MB upload limit.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    try {
        $parsedRows = importParseSpreadsheet($tmpName, $originalName);
      $headerRow = array_map('importTrimBom', $parsedRows[0] ?? []);
      $rememberedMapping = $_SESSION['import_mapping_memory'] ?? [];

      $_SESSION['import_parsed_rows'] = $parsedRows;
      $_SESSION['import_header'] = $headerRow;
      $_SESSION['import_mapping'] = importSuggestColumnMapping($headerRow, $rememberedMapping);
      $_SESSION['import_rows'] = null;
      $_SESSION['import_preview'] = null;
      $_SESSION['import_errors'] = [];
      $_SESSION['import_summary'] = [
        'file_name' => $originalName,
        'total_rows' => max(count($parsedRows) - 1, 0),
        'valid_rows' => 0,
        'error_rows' => 0,
        'duplicate_rows' => 0,
      ];
        $_SESSION['import_result'] = null;
      $_SESSION['import_state'] = 'mapping';

        redirect(BASE_URL . '/modules/stock-import/index.php');
    } catch (Throwable $exception) {
        setFlash('error', 'Upload failed: ' . $exception->getMessage());
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }
}

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'confirm_mapping') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
      setFlash('error', 'Invalid form session. Please try again.');
      redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $parsedRows = $_SESSION['import_parsed_rows'] ?? [];
    $headerRow = $_SESSION['import_header'] ?? [];
    $fileName = $_SESSION['import_summary']['file_name'] ?? 'upload';
    if (empty($parsedRows) || empty($headerRow)) {
      setFlash('error', 'There is no uploaded file ready for mapping.');
      redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $submittedMapping = $_POST['mapping_by_field'] ?? [];
    $allowedFields = array_keys(importSystemFieldOptions());
    $selectedMapping = [];
    $seenFields = [];
    $seenColumns = [];

    foreach ($allowedFields as $field) {
      $columnIndexRaw = $submittedMapping[$field] ?? '';
      if ($columnIndexRaw === '' || $columnIndexRaw === null) {
        continue;
      }

      $columnIndex = (int)$columnIndexRaw;
      if (!array_key_exists($columnIndex, $headerRow)) {
        continue;
      }

      if (isset($seenFields[$field])) {
        $_SESSION['import_mapping'] = $selectedMapping;
        setFlash('error', strtoupper($field) . ' was mapped more than once. Each system field can only be used once.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
      }

      if (isset($seenColumns[$columnIndex])) {
        $_SESSION['import_mapping'] = $selectedMapping;
        $label = importExcelColumnLabel($columnIndex);
        setFlash('error', 'Excel column ' . $label . ' was assigned to multiple ERP fields.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
      }

      $selectedMapping[$columnIndex] = $field;
      $seenFields[$field] = true;
      $seenColumns[$columnIndex] = true;
    }

    $missingRequired = array_values(array_filter(importRequiredFields(), function($field) use ($seenFields) {
      return !isset($seenFields[$field]);
    }));

    if (!empty($missingRequired)) {
      $_SESSION['import_mapping'] = $selectedMapping;
      setFlash('error', 'Map all required fields before continuing: ' . implode(', ', $missingRequired));
      redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    try {
      $analysis = importPrepareAnalysis($parsedRows, $db, $fileName, $selectedMapping);
      $mappingMemory = $_SESSION['import_mapping_memory'] ?? [];
      foreach ($selectedMapping as $index => $field) {
        $normalizedHeader = importNormalizeHeader($headerRow[$index] ?? '');
        if ($normalizedHeader !== '') {
          $mappingMemory[$normalizedHeader] = $field;
        }
      }

      $_SESSION['import_mapping_memory'] = $mappingMemory;
      $_SESSION['import_mapping'] = $selectedMapping;
      $_SESSION['import_rows'] = $analysis['rows'];
      $_SESSION['import_all_display'] = $analysis['all_display_rows'];
      $_SESSION['import_preview'] = $analysis['preview'];
      $_SESSION['import_errors'] = $analysis['errors'];
      $_SESSION['import_summary'] = $analysis['summary'];
      $_SESSION['import_skipped'] = [];
      $_SESSION['import_state'] = 'analysis';

      redirect(BASE_URL . '/modules/stock-import/index.php');
    } catch (Throwable $exception) {
      setFlash('error', 'Mapping failed: ' . $exception->getMessage());
      redirect(BASE_URL . '/modules/stock-import/index.php');
    }
  }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'toggle_skip') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $rowNum = (int)($_POST['row_number'] ?? 0);
    $skipped = $_SESSION['import_skipped'] ?? [];
    if (in_array($rowNum, $skipped, true)) {
        $skipped = array_values(array_diff($skipped, [$rowNum]));
    } else {
        $skipped[] = $rowNum;
    }
    $_SESSION['import_skipped'] = $skipped;
    echo json_encode(['ok' => true, 'skipped' => $skipped]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'save_row_edit') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Invalid session']);
        exit;
    }
    $editRowNum = (int)($_POST['row_number'] ?? 0);
    $rows = $_SESSION['import_rows'] ?? [];
    $allDisplay = $_SESSION['import_all_display'] ?? [];
    $editableFields = ['roll_no','paper_type','company','width_mm','length_mtr','gsm','weight_kg','purchase_rate','lot_batch_no','company_roll_no','status','job_no','job_size','job_name','date_received','date_used','remarks'];
    $requiredFieldsList = importRequiredFields();
    $found = false;

    foreach ($rows as $idx => $row) {
        if ((int)$row['_row_number'] !== $editRowNum) continue;
        $found = true;
        foreach ($editableFields as $f) {
            $val = trim((string)($_POST['edit_' . $f] ?? ''));
            if (in_array($f, ['width_mm','length_mtr','sqm','gsm','weight_kg','purchase_rate'], true)) {
                $rows[$idx][$f] = $val !== '' ? (float)$val : null;
            } elseif (in_array($f, ['date_received','date_used'], true)) {
                $rows[$idx][$f] = $val !== '' ? $val : null;
            } elseif ($f === 'status') {
                $rows[$idx][$f] = importNormalizeStatus($val);
            } else {
                $rows[$idx][$f] = $val !== '' ? $val : ($f === 'roll_no' || $f === 'paper_type' || $f === 'company' ? '' : null);
            }
        }
        if ($rows[$idx]['width_mm'] !== null && $rows[$idx]['length_mtr'] !== null) {
            $rows[$idx]['sqm'] = round(calcSQM($rows[$idx]['width_mm'], $rows[$idx]['length_mtr']), 2);
        }
        $errors = [];
        foreach ($requiredFieldsList as $rf) {
            if ($rf === 'width_mm' || $rf === 'length_mtr') {
                if ($rows[$idx][$rf] === null) $errors[] = strtoupper($rf) . ' is missing or invalid';
            } else {
                if (trim((string)($rows[$idx][$rf] ?? '')) === '') $errors[] = strtoupper($rf) . ' is required';
            }
        }
        $rollNo = $rows[$idx]['roll_no'];
        foreach ($rows as $ci => $cr) {
            if ($ci !== $idx && $cr['roll_no'] === $rollNo && $rollNo !== '') {
                $errors[] = 'Duplicate roll_no found in uploaded file';
                break;
            }
        }
        if ($rollNo !== '') {
            $existing = importFetchExistingRolls($db, [$rollNo]);
            if (!empty($existing)) $errors[] = 'roll_no already exists in paper stock';
        }
        $rows[$idx]['_errors'] = $errors;
        break;
    }

    if ($found) {
        $_SESSION['import_rows'] = $rows;
        $_SESSION['import_all_display'] = $rows;
        $validCount = 0;
        $errorItems = [];
        foreach ($rows as $r) {
            if (empty($r['_errors'])) $validCount++;
            foreach ($r['_errors'] as $m) {
                $errorItems[] = ['row' => $r['_row_number'], 'message' => $m];
            }
        }
        $_SESSION['import_errors'] = $errorItems;
        $_SESSION['import_summary']['valid_rows'] = $validCount;
        $_SESSION['import_summary']['error_rows'] = count($errorItems);
    }
    echo json_encode(['ok' => $found, 'redirect' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'import_confirmed') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid form session. Please try again.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $rows = $_SESSION['import_rows'] ?? [];
    $skippedRows = $_SESSION['import_skipped'] ?? [];
    $summary = $_SESSION['import_summary'] ?? ['total_rows' => 0, 'valid_rows' => 0];
    if (empty($rows)) {
        setFlash('error', 'There is no analyzed file ready to import.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $rowsToImport = array_values(array_filter($rows, function($row) use ($skippedRows) {
      return !in_array((int)$row['_row_number'], $skippedRows, true);
    }));

    if (empty($rowsToImport)) {
      setFlash('error', 'No rows are available to import.');
        redirect(BASE_URL . '/modules/stock-import/index.php');
    }

    $successCount = 0;
    $resultErrors = [];
    $autoAdjustedCount = 0;
    $usedRollNos = [];

    $checkStmt = $db->prepare('SELECT id FROM paper_stock WHERE roll_no = ? LIMIT 1');
    $insertStmt = $db->prepare("INSERT INTO paper_stock
        (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate,
         lot_batch_no, company_roll_no, status, job_no,
         date_received, date_used, remarks, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($rowsToImport as $row) {
      $rowNumber = (int)($row['_row_number'] ?? 0);

      $rollNo = importTrimBom((string)($row['roll_no'] ?? ''));
      if ($rollNo === '') {
        $rollNo = 'AUTO-' . date('Ymd') . '-' . $rowNumber;
        $autoAdjustedCount++;
        }

      $baseRollNo = $rollNo;
      $suffix = 1;
      while (true) {
        $conflictsInBatch = isset($usedRollNos[$rollNo]);

        $checkStmt->bind_param('s', $rollNo);
        $checkStmt->execute();
        $existsInDb = (bool)$checkStmt->get_result()->fetch_assoc();

        if (!$conflictsInBatch && !$existsInDb) {
          break;
        }

        $rollNo = $baseRollNo . '-' . $suffix;
        $suffix++;
        $autoAdjustedCount++;
      }
      $usedRollNos[$rollNo] = true;

      $paperType = importTrimBom((string)($row['paper_type'] ?? ''));
      if ($paperType === '') {
        $paperType = 'Unknown';
        $autoAdjustedCount++;
      }

      $company = importTrimBom((string)($row['company'] ?? ''));
      if ($company === '') {
        $company = 'Unknown';
        $autoAdjustedCount++;
      }

      $widthMm = isset($row['width_mm']) && is_numeric($row['width_mm']) ? (float)$row['width_mm'] : 0.0;
      $lengthMtr = isset($row['length_mtr']) && is_numeric($row['length_mtr']) ? (float)$row['length_mtr'] : 0.0;
      if ($widthMm === 0.0 || $lengthMtr === 0.0) {
        $autoAdjustedCount++;
      }

      $gsm = isset($row['gsm']) && is_numeric($row['gsm']) ? (float)$row['gsm'] : 0.0;
      $weightKg = isset($row['weight_kg']) && is_numeric($row['weight_kg']) ? (float)$row['weight_kg'] : 0.0;
      $purchaseRate = isset($row['purchase_rate']) && is_numeric($row['purchase_rate']) ? (float)$row['purchase_rate'] : 0.0;
      $lotBatchNo = !empty($row['lot_batch_no']) ? (string)$row['lot_batch_no'] : null;
      $companyRollNo = !empty($row['company_roll_no']) ? (string)$row['company_roll_no'] : null;
      // Full stock import should always enter as Main stock by default.
      $status = 'Main';
      $jobNo = !empty($row['job_no']) ? (string)$row['job_no'] : null;
      $dateReceived = !empty($row['date_received']) ? (string)$row['date_received'] : null;
      $dateUsed = !empty($row['date_used']) ? (string)$row['date_used'] : null;
      $remarks = !empty($row['remarks']) ? (string)$row['remarks'] : null;
        $createdBy = (int)($_SESSION['user_id'] ?? 0);

        $insertStmt->bind_param(
            'sssdddddsssssssi',
            $rollNo,
        $paperType,
        $company,
            $widthMm,
            $lengthMtr,
            $gsm,
            $weightKg,
            $purchaseRate,
            $lotBatchNo,
            $companyRollNo,
            $status,
            $jobNo,
            $dateReceived,
            $dateUsed,
            $remarks,
            $createdBy
        );

        if ($insertStmt->execute()) {
            $successCount++;
            continue;
        }

        $resultErrors[] = [
            'row' => $row['_row_number'],
            'message' => $insertStmt->error ?: 'Database insert failed',
        ];
    }

    $checkStmt->close();
    $insertStmt->close();

    $_SESSION['import_errors'] = $resultErrors;
    $_SESSION['import_result'] = [
        'total_rows' => $summary['total_rows'] ?? 0,
      'valid_rows' => count($rowsToImport),
        'imported_rows' => $successCount,
        'error_rows' => count($resultErrors),
        'file_name' => $summary['file_name'] ?? '',
    ];
    if ($autoAdjustedCount > 0) {
      setFlash('success', $autoAdjustedCount . ' value(s) were auto-adjusted to complete import without review.');
    }
    $_SESSION['import_state'] = 'result';

    unset($_SESSION['import_rows']);
    unset($_SESSION['import_all_display']);
    unset($_SESSION['import_preview']);
    unset($_SESSION['import_summary']);
    unset($_SESSION['import_skipped']);

    redirect(BASE_URL . '/modules/stock-import/index.php');
}

$importState = $_SESSION['import_state'] ?? 'template';
$uploadedHeader = $_SESSION['import_header'] ?? [];
$selectedMapping = $_SESSION['import_mapping'] ?? [];
$uploadedRowsRaw = $_SESSION['import_parsed_rows'] ?? [];
$firstUploadedDataRow = [];
if (isset($uploadedRowsRaw[1]) && is_array($uploadedRowsRaw[1])) {
  $firstUploadedDataRow = $uploadedRowsRaw[1];
}
$selectedFieldToColumn = [];
foreach ($selectedMapping as $colIdx => $fieldName) {
  $selectedFieldToColumn[(string)$fieldName] = (int)$colIdx;
}
$importErrors = $_SESSION['import_errors'] ?? [];
$analysisRows = $_SESSION['import_preview'] ?? [];
$allDisplayRows = $_SESSION['import_all_display'] ?? [];
$skippedRows = $_SESSION['import_skipped'] ?? [];
$analysisSummary = $_SESSION['import_summary'] ?? ['total_rows' => 0, 'valid_rows' => 0, 'error_rows' => 0, 'duplicate_rows' => 0, 'file_name' => ''];
$resultSummary = $_SESSION['import_result'] ?? ['total_rows' => 0, 'valid_rows' => 0, 'imported_rows' => 0, 'error_rows' => 0, 'file_name' => ''];

// Avoid showing stale "last import" results after all stock has been deleted.
$currentPaperStockCount = 0;
$stockCountRes = $db->query("SELECT COUNT(*) AS c FROM paper_stock");
if ($stockCountRes) {
  $stockCountRow = $stockCountRes->fetch_assoc();
  $currentPaperStockCount = (int)($stockCountRow['c'] ?? 0);
}
if ($importState === 'result' && $currentPaperStockCount === 0 && (int)($resultSummary['imported_rows'] ?? 0) > 0) {
  unset($_SESSION['import_result']);
  unset($_SESSION['import_errors']);
  $_SESSION['import_state'] = 'template';
  $importState = 'template';
  $resultSummary = ['total_rows' => 0, 'valid_rows' => 0, 'imported_rows' => 0, 'error_rows' => 0, 'file_name' => ''];
  $importErrors = [];
}

$mappingFields = importSystemFieldOptions();
$requiredFields = importRequiredFields();

$visualStep = 0;
if ($importState === 'mapping') {
  $visualStep = 2;
}
if ($importState === 'analysis') {
  $visualStep = 3;
}
if ($importState === 'result') {
    $visualStep = 4;
}

$pageTitle = 'Stock Import & Export';
$csrf = generateCSRF();
include __DIR__ . '/../../includes/header.php';
?>

<style>
.stock-import-card {
  border-radius: 20px;
  overflow: hidden;
  transition: transform .2s ease, box-shadow .2s ease;
}

.stock-import-card:hover {
  transform: translateY(-1px);
}

.stock-import-steps {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 24px;
}

.stock-import-step {
  text-align: center;
  padding: 14px 10px;
  border-radius: 14px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
  transition: all .2s ease;
}

.stock-import-step.is-complete {
  background: #dcfce7;
  color: #166534;
  border-color: #86efac;
}

.stock-import-step.is-current {
  background: #022c22;
  color: #fff;
  border-color: #16a34a;
  box-shadow: 0 14px 30px rgba(2, 44, 34, .18);
}

.stock-import-step-icon {
  display: block;
  margin-bottom: 6px;
  font-size: 16px;
}

.stock-import-actions {
  display: flex;
  justify-content: center;
  gap: 16px;
  flex-wrap: wrap;
  max-width: 620px;
  margin: 0 auto;
}

.stock-import-action {
  width: 240px;
  max-width: 100%;
  height: 52px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 700;
  color: #fff;
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
  box-shadow: 0 16px 28px rgba(15, 23, 42, .12);
}

.stock-import-action:hover {
  transform: translateY(-1px);
}

.stock-import-action.download {
  background: #22c55e;
}

.stock-import-action.download:hover {
  background: #16a34a;
}

.stock-import-action.upload {
  background: #f59e0b;
}

.stock-import-action.upload:hover {
  background: #d97706;
}

.stock-import-upload-box {
  max-width: 760px;
  margin: 0 auto;
  padding: 20px;
  border: 2px dashed #cbd5e1;
  background: linear-gradient(180deg, #fff7ed 0%, #ffffff 100%);
  border-radius: 18px;
}

.stock-import-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-bottom: 20px;
}

.stock-import-metric {
  border-radius: 18px;
  padding: 18px;
  text-align: center;
  border: 1px solid #e2e8f0;
  background: #fff;
}

.stock-import-metric strong {
  display: block;
  margin-top: 6px;
  font-size: 28px;
  line-height: 1;
}

.stock-import-table-wrap {
  overflow-x: auto;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
}

.stock-import-table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
}

.stock-import-table th,
.stock-import-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #e2e8f0;
  font-size: 12px;
  text-align: left;
}

.stock-import-table th {
  background: #f8fafc;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  white-space: nowrap;
}

.stock-import-table tr.has-error {
  background: #fef2f2;
}

.stock-import-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
}

.stock-import-pill.success {
  background: #dcfce7;
  color: #166534;
}

.stock-import-pill.warning {
  background: #fff7ed;
  color: #b45309;
}

.stock-import-errors {
  max-height: 220px;
  overflow: auto;
  border: 1px solid #fecaca;
  background: #fef2f2;
  border-radius: 16px;
  padding: 14px;
}

.stock-import-error-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid #fee2e2;
  color: #7f1d1d;
  font-size: 12px;
}

.stock-import-error-item:last-child {
  border-bottom: none;
}

.stock-import-error-row {
  min-width: 74px;
  padding: 4px 8px;
  border-radius: 999px;
  background: #fff;
  color: #b91c1c;
  font-weight: 700;
  text-align: center;
}

.stock-import-footer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

.stock-import-result-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
  max-width: 520px;
  margin: 0 auto 24px;
}

.stock-import-mapping-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
  gap: 16px;
}

.stock-import-select {
  width: 100%;
  height: 44px;
  border-radius: 12px;
  border: 1px solid #cbd5e1;
  padding: 0 12px;
  background: #fff;
  color: #0f172a;
  font-size: 13px;
}

.stock-import-header-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 7px 10px;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 11px;
  font-weight: 700;
}

.stock-import-required-note {
  color: #b91c1c;
  font-weight: 700;
}

.stock-import-mapping-help {
  border: 1px solid #fde68a;
  background: #fffbeb;
  color: #92400e;
  border-radius: 14px;
  padding: 14px;
  font-size: 12px;
}

.stock-import-mapping-row {
  transition: background .15s ease, border-color .15s ease;
}

.stock-import-mapping-row.row-valid {
  background: #f0fdf4;
}

.stock-import-mapping-row.row-error {
  background: #fef2f2;
  border-left: 3px solid #ef4444;
}

.stock-import-mapping-row.row-warning {
  background: #fffbeb;
  border-left: 3px solid #f59e0b;
}

.stock-import-status-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 999px;
  font-size: 16px;
  cursor: default;
  position: relative;
}

.stock-import-status-icon.icon-valid {
  background: #dcfce7;
  color: #16a34a;
}

.stock-import-status-icon.icon-error {
  background: #fee2e2;
  color: #dc2626;
}

.stock-import-status-icon.icon-warning {
  background: #fef3c7;
  color: #d97706;
}

.stock-import-status-icon .si-tooltip {
  display: none;
  position: absolute;
  right: calc(100% + 8px);
  top: 50%;
  transform: translateY(-50%);
  white-space: nowrap;
  background: #0f172a;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  padding: 5px 10px;
  border-radius: 8px;
  pointer-events: none;
  z-index: 10;
}

.stock-import-status-icon:hover .si-tooltip {
  display: block;
}

.stock-import-validation-bar {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  padding: 12px 16px;
  border-radius: 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  margin-bottom: 16px;
  font-size: 13px;
  font-weight: 700;
}

.stock-import-validation-bar .vb-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.stock-import-validation-bar .vb-valid { color: #16a34a; }
.stock-import-validation-bar .vb-error { color: #dc2626; }
.stock-import-validation-bar .vb-warning { color: #d97706; }

.btn-disabled-state {
  opacity: .45;
  pointer-events: none;
  cursor: not-allowed;
}

.stock-import-table tr.row-skipped {
  opacity: .4;
  background: #f8fafc;
}

.stock-import-table tr.row-skipped td {
  text-decoration: line-through;
  color: #94a3b8;
}

.stock-import-table tr.row-skipped td:last-child {
  text-decoration: none;
}

.si-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
  transition: all .15s ease;
}

.si-action-btn:hover {
  transform: scale(1.1);
}

.si-action-btn.btn-edit {
  color: #2563eb;
  border-color: #bfdbfe;
}

.si-action-btn.btn-edit:hover {
  background: #eff6ff;
}

.si-action-btn.btn-skip {
  color: #f59e0b;
  border-color: #fde68a;
}

.si-action-btn.btn-skip:hover {
  background: #fffbeb;
}

.si-action-btn.btn-skip.is-skipped {
  color: #dc2626;
  border-color: #fecaca;
  background: #fef2f2;
}

.si-error-link {
  cursor: pointer;
  transition: background .15s ease;
  border-radius: 6px;
}

.si-error-link:hover {
  background: #fecdd3;
}

.si-review-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 12px 16px;
  background: #fefce8;
  border: 1px solid #fde68a;
  border-radius: 14px;
  margin-bottom: 16px;
}

.si-review-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 700;
  border: none;
  cursor: pointer;
  transition: all .15s ease;
}

.si-review-btn:hover {
  transform: translateY(-1px);
}

.si-review-btn.review-issues {
  background: #f59e0b;
  color: #fff;
}

.si-review-btn.review-issues:hover {
  background: #d97706;
}

.si-review-btn.import-valid {
  background: #22c55e;
  color: #fff;
}

.si-review-btn.import-valid:hover {
  background: #16a34a;
}

.si-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15,23,42,.55);
  z-index: 1000;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.si-modal-overlay.is-open {
  display: flex;
}

.si-modal {
  background: #fff;
  border-radius: 20px;
  width: 100%;
  max-width: 640px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 25px 60px rgba(15,23,42,.25);
}

.si-modal-header {
  padding: 20px 24px;
  background: #0f172a;
  color: #fff;
  border-radius: 20px 20px 0 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.si-modal-header h3 {
  font-size: 14px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  margin: 0;
}

.si-modal-close {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 20px;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}

.si-modal-close:hover {
  color: #fff;
}

.si-modal-body {
  padding: 24px;
}

.si-modal-field {
  margin-bottom: 14px;
}

.si-modal-field label {
  display: block;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #64748b;
  margin-bottom: 5px;
}

.si-modal-field label .req-star {
  color: #dc2626;
}

.si-modal-field input,
.si-modal-field select {
  width: 100%;
  height: 40px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  padding: 0 12px;
  font-size: 13px;
  background: #fff;
  color: #0f172a;
}

.si-modal-field input:focus,
.si-modal-field select:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,.15);
}

.si-modal-footer {
  padding: 16px 24px;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.si-row-highlight {
  animation: siHighlight .8s ease;
}

@keyframes siHighlight {
  0% { background: #fef08a; }
  100% { background: transparent; }
}

@media (max-width: 860px) {
  .stock-import-steps,
  .stock-import-summary-grid,
  .stock-import-result-grid,
  .stock-import-mapping-grid {
    grid-template-columns: 1fr;
  }

  .stock-import-footer-actions {
    justify-content: center;
  }
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Stock Import & Export</span>
</div>

<div class="page-header">
  <div>
    <h1>Stock Import & Export Hub</h1>
    <p>Clean Excel intake, validation-first analysis, and controlled stock import for paper inventory.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Back to Stock
    </a>
  </div>
</div>

<div class="stock-import-steps">
  <?php $steps = ['Template', 'Upload', 'Mapping', 'Analysis', 'Result']; ?>
  <?php $icons = ['file-earmark-arrow-down', 'cloud-upload', 'sliders', 'bar-chart', 'check-circle']; ?>
  <?php foreach ($steps as $index => $label): ?>
    <?php
      $classes = 'stock-import-step';
      if ($index < $visualStep) {
          $classes .= ' is-complete';
      } elseif ($index === $visualStep) {
          $classes .= ' is-current';
      }
    ?>
    <div class="<?= $classes ?>">
      <span class="stock-import-step-icon"><i class="bi bi-<?= $icons[$index] ?>"></i></span>
      <?= e($label) ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($importState === 'template'): ?>
<div class="card stock-import-card">
  <div style="background:#0f172a;color:#fff;padding:22px 24px">
    <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
      <i class="bi bi-file-earmark-spreadsheet" style="color:#22c55e;margin-right:8px"></i>Technical Data Preparation
    </h2>
    <p style="margin:0;font-size:12px;color:#cbd5e1">Download the ERP template or upload your own CSV/XLSX file. After file selection, you can map each Excel column before analysis starts.</p>
  </div>
  <div style="padding:32px;text-align:center">
    <div class="stock-import-upload-box">
      <div style="display:flex;justify-content:center;margin-bottom:18px">
        <div style="height:74px;width:74px;border-radius:999px;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 14px 24px rgba(15,23,42,.1)">
          <i class="bi bi-cloud-arrow-up" style="font-size:28px;color:#f59e0b"></i>
        </div>
      </div>
      <h3 style="margin:0 0 10px 0;font-size:22px;font-weight:700">Stock File Intake</h3>
      <p style="max-width:560px;margin:0 auto 24px;color:#64748b;font-size:13px;line-height:1.6">Use the same sheet structure as the Firebase reference flow, but import directly into your PHP ERP. Required fields are checked before anything is written to the database.</p>

      <div class="stock-import-actions">
        <a href="<?= BASE_URL ?>/modules/stock-import/index.php?action=download_template" class="stock-import-action download">
          <i class="bi bi-download"></i>
          <span>Download ERP Template</span>
        </a>

        <label for="stock-import-file" class="stock-import-action upload" style="margin:0">
          <i class="bi bi-upload"></i>
          <span>Upload File</span>
        </label>
      </div>

      <form id="stock-import-upload-form" method="POST" enctype="multipart/form-data" style="display:none">
        <input type="hidden" name="action" value="upload_file">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="file" name="csv_file" id="stock-import-file" accept=".csv,.xlsx" onchange="if(this.files.length){document.getElementById('stock-import-upload-form').submit();}">
      </form>

      <div style="margin-top:18px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap">
        <span class="stock-import-pill success"><i class="bi bi-filetype-xlsx"></i> XLSX Supported</span>
        <span class="stock-import-pill warning"><i class="bi bi-filetype-csv"></i> CSV Supported</span>
      </div>
    </div>
  </div>
</div>

<!-- ── Export & Manage All Rolls ── -->
<?php
  $totalRolls = (int)$db->query("SELECT COUNT(*) AS c FROM paper_stock")->fetch_assoc()['c'];
  $totalMtr   = (float)$db->query("SELECT IFNULL(SUM(length_mtr),0) AS m FROM paper_stock")->fetch_assoc()['m'];
?>
<div class="card stock-import-card" style="margin-top:20px">
  <div style="background:#0f172a;color:#fff;padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
        <i class="bi bi-database-gear" style="color:#22c55e;margin-right:8px"></i>Manage All Paper Rolls
      </h2>
      <p style="margin:0;color:#cbd5e1;font-size:12px">Export full inventory as PDF or Excel, or clear all rolls from the database.</p>
    </div>
    <span class="stock-import-pill success"><i class="bi bi-box-seam"></i> <?= number_format($totalRolls) ?> rolls &middot; <?= number_format($totalMtr, 0) ?> MTR</span>
  </div>
  <div style="padding:32px">
    <div style="display:flex;justify-content:center;gap:16px;flex-wrap:wrap">

      <!-- Export PDF -->
      <a href="<?= BASE_URL ?>/modules/paper_stock/export.php?format=pdf&mode=all"
         class="stock-import-action download" style="background:#2563eb;border-color:#1d4ed8;min-width:180px;text-decoration:none"
         target="_blank">
        <i class="bi bi-file-earmark-pdf"></i>
        <span>Export PDF</span>
      </a>

      <!-- Export Excel -->
      <a href="<?= BASE_URL ?>/modules/paper_stock/export.php?format=csv&mode=all"
         class="stock-import-action download" style="background:#16a34a;border-color:#15803d;min-width:180px;text-decoration:none">
        <i class="bi bi-file-earmark-excel"></i>
        <span>Export Excel</span>
      </a>

      <!-- Delete All Rolls -->
      <button type="button" onclick="confirmDeleteAllRolls()" class="stock-import-action upload"
              style="background:#dc2626;border-color:#b91c1c;min-width:180px;cursor:pointer"
              <?= $totalRolls === 0 ? 'disabled style="opacity:.5;pointer-events:none"' : '' ?>>
        <i class="bi bi-trash3"></i>
        <span>Delete All Rolls (<?= number_format($totalRolls) ?>)</span>
      </button>
    </div>

    <?php if ($totalRolls > 0): ?>
    <p style="text-align:center;margin:18px 0 0;color:#94a3b8;font-size:12px">
      <i class="bi bi-info-circle"></i>
      Delete All will permanently remove <strong><?= number_format($totalRolls) ?></strong> roll(s) from the database. This cannot be undone.
    </p>
    <?php else: ?>
    <p style="text-align:center;margin:18px 0 0;color:#94a3b8;font-size:12px">
      <i class="bi bi-inbox"></i> No rolls in the database. Import a file above to get started.
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- Delete All — hidden form + confirmation -->
<form id="delete-all-rolls-form" method="POST" action="<?= BASE_URL ?>/modules/paper_stock/batch_delete.php" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="ids" id="delete-all-roll-ids" value="">
</form>
<script>
function confirmDeleteAllRolls() {
  var total = <?= (int)$totalRolls ?>;
  if (total === 0) return;
  var msg = 'Are you sure you want to DELETE ALL ' + total.toLocaleString() + ' paper roll(s)?\n\nThis action CANNOT be undone.';
  if (!confirm(msg)) return;
  var msg2 = 'FINAL CONFIRMATION: Type OK to proceed with deleting ALL rolls.';
  var answer = prompt(msg2);
  if (!answer || answer.trim().toUpperCase() !== 'OK') { alert('Deletion cancelled.'); return; }

  // Fetch all roll IDs via AJAX, then submit
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '<?= BASE_URL ?>/modules/paper_stock/export.php?format=ids&mode=all', true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      document.getElementById('delete-all-roll-ids').value = xhr.responseText.trim();
      document.getElementById('delete-all-rolls-form').submit();
    } else {
      alert('Failed to fetch roll IDs. Please try again.');
    }
  };
  xhr.onerror = function() { alert('Network error. Please try again.'); };
  xhr.send();
}
</script>

<?php elseif ($importState === 'mapping'): ?>
<div class="card stock-import-card">
  <div style="background:#0f172a;color:#fff;padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
        <i class="bi bi-sliders" style="color:#f59e0b;margin-right:8px"></i>Column Mapping
      </h2>
      <p style="margin:0;color:#cbd5e1;font-size:12px">Map uploaded Excel columns to ERP fields before validation and import.</p>
    </div>
    <span class="stock-import-pill success"><i class="bi bi-file-earmark-spreadsheet"></i><?= count($uploadedHeader) ?> columns detected</span>
  </div>

  <div style="padding:24px">
    <div class="stock-import-summary-grid" style="margin-bottom:18px">
      <div class="stock-import-metric" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">
        <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Detected Columns</span>
        <strong><?= count($uploadedHeader) ?></strong>
      </div>
      <div class="stock-import-metric" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">
        <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Rows Uploaded</span>
        <strong><?= (int)$analysisSummary['total_rows'] ?></strong>
      </div>
      <div class="stock-import-metric" style="background:#fefce8;border-color:#fde68a;color:#a16207">
        <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Required Fields</span>
        <strong><?= count($requiredFields) ?></strong>
      </div>
    </div>

    <div class="stock-import-mapping-help" style="margin-bottom:18px">
      Required fields are highlighted. The system makes a best-guess mapping first, but you can change every column before continuing to analysis.
    </div>

    <form method="POST" id="mapping-form">
      <input type="hidden" name="action" value="confirm_mapping">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

      <div class="stock-import-validation-bar" id="mapping-validation-bar">
        <span class="vb-item vb-valid"><i class="bi bi-check-circle-fill"></i> Valid: <span id="vb-valid-count">0</span></span>
        <span class="vb-item vb-error"><i class="bi bi-x-circle-fill"></i> Errors: <span id="vb-error-count">0</span></span>
        <span class="vb-item vb-warning"><i class="bi bi-exclamation-triangle-fill"></i> Warnings: <span id="vb-warning-count">0</span></span>
      </div>

      <div class="stock-import-table-wrap">
        <table class="stock-import-table">
          <thead>
            <tr>
              <th>ERP Table Column</th>
              <th>Map Excel Column</th>
              <th>Excel Preview</th>
              <th style="text-align:center;width:70px">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mappingFields as $field => $label): ?>
              <?php
                $isRequired = in_array($field, $requiredFields, true);
                $selectedColumn = $selectedFieldToColumn[$field] ?? '';
              ?>
              <tr class="stock-import-mapping-row" id="mapping-row-<?= e($field) ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <span class="stock-import-header-chip" style="background:#ecfeff;color:#0f766e">
                      <i class="bi bi-table"></i>
                      <?= e($label) ?>
                    </span>
                    <?php if ($isRequired): ?>
                      <span class="stock-import-pill warning" style="padding:4px 8px">
                        <i class="bi bi-asterisk"></i> Required
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <select name="mapping_by_field[<?= e($field) ?>]" class="stock-import-select mapping-select" data-field="<?= e($field) ?>">
                    <option value="">Do not import</option>
                    <?php foreach ($uploadedHeader as $index => $header): ?>
                      <?php
                        $excelLabel = importExcelColumnLabel($index);
                        $headerLabel = importTrimBom((string)$header);
                        if ($headerLabel === '') {
                          $headerLabel = 'Excel Column ' . $excelLabel;
                        }
                      ?>
                      <option value="<?= (int)$index ?>" <?= ((string)$selectedColumn !== '' && (int)$selectedColumn === (int)$index) ? 'selected' : '' ?>>
                        <?= e($excelLabel . ' - ' . $headerLabel) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <div class="text-muted" id="mapping-preview-<?= e($field) ?>" style="font-size:12px">
                    <?php if ((string)$selectedColumn !== '' && isset($uploadedHeader[(int)$selectedColumn])): ?>
                      <?php
                        $previewHeader = importTrimBom((string)$uploadedHeader[(int)$selectedColumn]);
                        if ($previewHeader === '') {
                          $previewHeader = 'Excel Column ' . importExcelColumnLabel((int)$selectedColumn);
                        }
                        $previewSample = importTrimBom((string)($firstUploadedDataRow[(int)$selectedColumn] ?? ''));
                        if ($previewSample === '') {
                          $previewSample = '-';
                        }
                      ?>
                      <strong><?= e($previewHeader) ?></strong><br>
                      <span style="font-family:monospace"><?= e(strlen($previewSample) > 42 ? substr($previewSample, 0, 39) . '...' : $previewSample) ?></span>
                    <?php else: ?>
                      <span class="text-muted">No Excel column selected</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="text-align:center">
                  <span class="stock-import-status-icon" id="mapping-icon-<?= e($field) ?>">
                    <i class="bi bi-dash"></i>
                    <span class="si-tooltip"></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:center" id="required-field-pills">
        <?php foreach ($requiredFields as $field): ?>
          <span class="stock-import-pill warning" id="req-pill-<?= e($field) ?>" data-field="<?= e($field) ?>">
            <i class="bi bi-exclamation-circle"></i>
            <?= e($field) ?>
          </span>
        <?php endforeach; ?>
      </div>

      <div style="margin-top:22px" class="stock-import-footer-actions">
        <a href="<?= BASE_URL ?>/modules/stock-import/index.php?reset=1" class="btn btn-ghost">
          <i class="bi bi-x-circle"></i> Cancel
        </a>
        <button type="submit" class="btn btn-primary btn-disabled-state" id="mapping-submit-btn" disabled>
          <i class="bi bi-arrow-right-circle"></i> Continue to Analysis
        </button>
      </div>
    </form>

    <script>
    (function() {
      var requiredFields = <?= json_encode(array_values($requiredFields)) ?>;
      var headerMap = <?= json_encode(array_values(array_map('importTrimBom', $uploadedHeader))) ?>;
      var sampleMap = <?= json_encode(array_values(array_map(function($v) { return importTrimBom((string)$v); }, $firstUploadedDataRow))) ?>;
      var selects = document.querySelectorAll('.mapping-select');

      function excelColumnLabel(index) {
        var label = '';
        var n = parseInt(index, 10);
        if (isNaN(n) || n < 0) {
          return '';
        }
        while (n >= 0) {
          label = String.fromCharCode((n % 26) + 65) + label;
          n = Math.floor(n / 26) - 1;
        }
        return label;
      }

      function updatePreview(field, indexValue) {
        var preview = document.getElementById('mapping-preview-' + field);
        if (!preview) {
          return;
        }

        if (indexValue === '') {
          preview.innerHTML = '<span class="text-muted">No Excel column selected</span>';
          return;
        }

        var idx = parseInt(indexValue, 10);
        var header = (headerMap[idx] || '').trim();
        if (!header) {
          header = 'Excel Column ' + excelColumnLabel(idx);
        }
        var sample = (sampleMap[idx] || '').toString().trim();
        if (!sample) {
          sample = '-';
        }
        if (sample.length > 42) {
          sample = sample.slice(0, 39) + '...';
        }

        preview.innerHTML = '<strong>' + header.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</strong><br><span style="font-family:monospace">' + sample.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
      }

      function runValidation() {
        var selectedColumns = {};
        var duplicateColumns = {};
        var fieldMap = {};
        var validCount = 0;
        var errorCount = 0;
        var warningCount = 0;

        selects.forEach(function(sel) {
          var val = sel.value;
          var field = sel.getAttribute('data-field');
          fieldMap[field] = val;
          if (val !== '') {
            if (selectedColumns[val] !== undefined) {
              duplicateColumns[val] = true;
            }
            selectedColumns[val] = field;
          }
          updatePreview(field, val);
        });

        selects.forEach(function(sel) {
          var field = sel.getAttribute('data-field');
          var val = sel.value;
          var row = document.getElementById('mapping-row-' + field);
          var icon = document.getElementById('mapping-icon-' + field);
          var iconEl = icon.querySelector('i');
          var tooltip = icon.querySelector('.si-tooltip');
          var isRequired = requiredFields.indexOf(field) !== -1;

          row.className = 'stock-import-mapping-row';
          icon.className = 'stock-import-status-icon';

          if (val === '' && isRequired) {
            icon.classList.add('icon-error');
            row.classList.add('row-error');
            iconEl.className = 'bi bi-x-circle-fill';
            tooltip.textContent = 'Required ERP field is not mapped';
            errorCount++;
          } else if (val === '') {
            icon.classList.add('icon-warning');
            row.classList.add('row-warning');
            iconEl.className = 'bi bi-exclamation-triangle-fill';
            tooltip.textContent = 'Optional ERP field not mapped';
            warningCount++;
          } else if (duplicateColumns[val]) {
            icon.classList.add('icon-error');
            row.classList.add('row-error');
            iconEl.className = 'bi bi-x-circle-fill';
            tooltip.textContent = 'This Excel column is mapped multiple times';
            errorCount++;
          } else {
            icon.classList.add('icon-valid');
            row.classList.add('row-valid');
            iconEl.className = 'bi bi-check-circle-fill';
            tooltip.textContent = 'Mapped correctly';
            validCount++;
          }
        });

        requiredFields.forEach(function(rf) {
          var pill = document.getElementById('req-pill-' + rf);
          if (!pill) {
            return;
          }
          if (fieldMap[rf] && fieldMap[rf] !== '') {
            pill.className = 'stock-import-pill success';
            pill.querySelector('i').className = 'bi bi-check2-circle';
          } else {
            pill.className = 'stock-import-pill warning';
            pill.querySelector('i').className = 'bi bi-exclamation-circle';
          }
        });

        document.getElementById('vb-valid-count').textContent = validCount;
        document.getElementById('vb-error-count').textContent = errorCount;
        document.getElementById('vb-warning-count').textContent = warningCount;

        var btn = document.getElementById('mapping-submit-btn');
        if (errorCount > 0) {
          btn.disabled = true;
          btn.classList.add('btn-disabled-state');
        } else {
          btn.disabled = false;
          btn.classList.remove('btn-disabled-state');
        }
      }

      selects.forEach(function(sel) {
        sel.addEventListener('change', runValidation);
      });

      runValidation();
    })();
    </script>
  </div>
</div>

<?php elseif ($importState === 'analysis'):
  $needsReviewRows = [];
  $readyCount = 0;
  $reviewCount = 0;
  $skippedCount = count($skippedRows);
  foreach ($allDisplayRows as $dr) {
    $isSkipped = in_array((int)$dr['_row_number'], $skippedRows, true);
    if ($isSkipped) continue;
    if (empty($dr['_errors'])) { $readyCount++; } else { $reviewCount++; $needsReviewRows[] = $dr; }
  }
?>

<div class="stock-import-summary-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <div class="stock-import-metric" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">
    <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Total Rows</span>
    <strong><?= (int)$analysisSummary['total_rows'] ?></strong>
  </div>
  <div class="stock-import-metric" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">
    <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Ready</span>
    <strong><?= $readyCount ?></strong>
  </div>
  <div class="stock-import-metric" style="background:#fff7ed;border-color:#fed7aa;color:#c2410c">
    <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Needs Review</span>
    <strong><?= $reviewCount ?></strong>
  </div>
  <div class="stock-import-metric" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">
    <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Skipped</span>
    <strong id="skipped-count"><?= $skippedCount ?></strong>
  </div>
</div>

<div class="card stock-import-card">
  <div style="background:#0f172a;color:#fff;padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
        <i class="bi bi-table" style="color:#22c55e;margin-right:8px"></i>Analysis & Review
      </h2>
      <p style="margin:0;color:#cbd5e1;font-size:12px">All <?= (int)$analysisSummary['total_rows'] ?> rows from <?= e($analysisSummary['file_name']) ?>. Edit, skip, or import valid data.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <span class="stock-import-pill success"><i class="bi bi-check2-circle"></i> <?= $readyCount ?> ready</span>
      <?php if ($reviewCount > 0): ?>
        <span class="stock-import-pill warning"><i class="bi bi-exclamation-triangle"></i> <?= $reviewCount ?> needs review</span>
      <?php endif; ?>
    </div>
  </div>

  <div style="padding:24px">

    <div class="si-review-bar">
      <span style="font-size:12px;font-weight:700;color:#92400e"><i class="bi bi-lightbulb" style="margin-right:4px"></i>Actions:</span>
      <?php if ($reviewCount > 0): ?>
        <button type="button" class="si-review-btn review-issues" onclick="siScrollToFirstIssue()">
          <i class="bi bi-search"></i> Review All Issues (<?= $reviewCount ?>)
        </button>
      <?php endif; ?>
      <form method="POST" style="margin:0;display:inline">
        <input type="hidden" name="action" value="import_confirmed">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button type="submit" class="si-review-btn import-valid" <?= $readyCount < 1 ? 'disabled style="opacity:.45;cursor:not-allowed"' : '' ?>>
          <i class="bi bi-cloud-check"></i> Import Now Without Review (<?= $readyCount ?>)
        </button>
      </form>
    </div>

    <div style="margin-bottom:16px;padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;border-radius:12px;color:#92400e;font-size:12px">
      <i class="bi bi-info-circle" style="margin-right:6px"></i>
      <strong>Recommended:</strong> click <strong>Review All Issues</strong> first to verify mapped data.
      If needed, you can still continue with <strong>Import Now Without Review</strong>.
    </div>

    <div class="stock-import-table-wrap" style="max-height:520px;overflow-y:auto" id="analysis-table-wrap">
      <table class="stock-import-table" id="analysis-table">
        <thead style="position:sticky;top:0;z-index:2">
          <tr>
            <th style="width:54px">Row</th>
            <th>Roll No</th>
            <th>Paper Type</th>
            <th>Company</th>
            <th>Width</th>
            <th>Length</th>
            <th>Status</th>
            <th style="width:100px">State</th>
            <th style="width:90px;text-align:center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($allDisplayRows)): ?>
            <tr>
              <td colspan="9" style="text-align:center;color:#64748b">No rows available.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($allDisplayRows as $row):
              $rowNum = (int)$row['_row_number'];
              $isSkipped = in_array($rowNum, $skippedRows, true);
              $hasErrors = !empty($row['_errors']);
              $rowClass = $isSkipped ? 'row-skipped' : ($hasErrors ? 'has-error' : '');
            ?>
              <tr class="<?= $rowClass ?>" id="analysis-row-<?= $rowNum ?>" data-row="<?= $rowNum ?>">
                <td>#<?= $rowNum ?></td>
                <td><?= e($row['roll_no']) ?></td>
                <td><?= e($row['paper_type']) ?></td>
                <td><?= e($row['company']) ?></td>
                <td><?= $row['width_mm'] !== null ? e($row['width_mm']) . ' mm' : '-' ?></td>
                <td><?= $row['length_mtr'] !== null ? e($row['length_mtr']) . ' mtr' : '-' ?></td>
                <td><?= statusBadge($row['status']) ?></td>
                <td>
                  <?php if ($isSkipped): ?>
                    <span class="stock-import-pill" style="background:#f1f5f9;color:#64748b"><i class="bi bi-slash-circle"></i> Skipped</span>
                  <?php elseif ($hasErrors): ?>
                    <span class="stock-import-pill warning">Needs Review</span>
                  <?php else: ?>
                    <span class="stock-import-pill success">Ready</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center">
                  <div style="display:flex;gap:4px;justify-content:center">
                    <?php if (!$isSkipped): ?>
                      <button type="button" class="si-action-btn btn-edit" title="Edit row" onclick="siOpenEditModal(<?= $rowNum ?>)">
                        <i class="bi bi-pencil-square"></i>
                      </button>
                    <?php endif; ?>
                    <button type="button" class="si-action-btn btn-skip <?= $isSkipped ? 'is-skipped' : '' ?>" title="<?= $isSkipped ? 'Unskip row' : 'Skip row' ?>" onclick="siToggleSkip(<?= $rowNum ?>, this)">
                      <i class="bi bi-<?= $isSkipped ? 'arrow-counterclockwise' : 'x-lg' ?>"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($importErrors)): ?>
      <div style="margin-top:18px">
        <h3 style="font-size:13px;font-weight:700;margin:0 0 10px 0;color:#991b1b"><i class="bi bi-exclamation-triangle" style="margin-right:4px"></i>Validation Issues <span style="font-weight:400;color:#b91c1c">(click to jump to row)</span></h3>
        <div class="stock-import-errors">
          <?php foreach ($importErrors as $error): ?>
            <?php $errRowNum = $error['row']; ?>
            <div class="stock-import-error-item si-error-link" onclick="siScrollToRow('<?= e((string)$errRowNum) ?>')" title="Click to scroll to row <?= e((string)$errRowNum) ?>">
              <span class="stock-import-error-row"><i class="bi bi-geo-alt" style="margin-right:3px;font-size:10px"></i><?= e((string)$errRowNum) ?></span>
              <span><?= e($error['message']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="margin-top:22px" class="stock-import-footer-actions">
      <a href="<?= BASE_URL ?>/modules/stock-import/index.php?reset=1" class="btn btn-ghost">
        <i class="bi bi-x-circle"></i> Cancel
      </a>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="import_confirmed">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-primary" <?= $readyCount < 1 ? 'disabled' : '' ?>>
          <i class="bi bi-arrow-right-circle"></i> Import <?= $readyCount ?> Valid Rows
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="si-modal-overlay" id="si-edit-modal">
  <div class="si-modal">
    <div class="si-modal-header">
      <h3><i class="bi bi-pencil-square" style="margin-right:8px;color:#f59e0b"></i>Edit Row <span id="si-edit-row-label"></span></h3>
      <button type="button" class="si-modal-close" onclick="siCloseModal()">&times;</button>
    </div>
    <div class="si-modal-body">
      <form id="si-edit-form">
        <input type="hidden" name="action" value="save_row_edit">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="row_number" id="si-edit-row-number">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
          <?php
            $editFields = [
              ['roll_no', 'Roll No', 'text', true],
              ['paper_type', 'Paper Type', 'text', true],
              ['company', 'Company', 'text', true],
              ['width_mm', 'Width (mm)', 'number', true],
              ['length_mtr', 'Length (mtr)', 'number', true],
              ['gsm', 'GSM', 'number', false],
              ['weight_kg', 'Weight (kg)', 'number', false],
              ['purchase_rate', 'Purchase Rate', 'number', false],
              ['lot_batch_no', 'Lot / Batch No', 'text', false],
              ['company_roll_no', 'Company Roll No', 'text', false],
              ['status', 'Status', 'select', false],
              ['job_no', 'Job No', 'text', false],
              ['job_size', 'Job Size', 'text', false],
              ['job_name', 'Job Name', 'text', false],
              ['date_received', 'Date Received', 'date', false],
              ['date_used', 'Date Used', 'date', false],
              ['remarks', 'Remarks', 'text', false],
            ];
            foreach ($editFields as $ef):
              $fname = $ef[0]; $flabel = $ef[1]; $ftype = $ef[2]; $freq = $ef[3];
          ?>
            <div class="si-modal-field">
              <label><?= e($flabel) ?><?= $freq ? ' <span class="req-star">*</span>' : '' ?></label>
              <?php if ($ftype === 'select'): ?>
                <select name="edit_<?= $fname ?>" id="si-field-<?= $fname ?>">
                  <option value="Available">Available</option>
                  <option value="Assigned">Assigned</option>
                  <option value="Consumed">Consumed</option>
                </select>
              <?php else: ?>
                <input type="<?= $ftype ?>" name="edit_<?= $fname ?>" id="si-field-<?= $fname ?>" <?= $ftype === 'number' ? 'step="any"' : '' ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
    <div class="si-modal-footer">
      <button type="button" class="btn btn-ghost" onclick="siCloseModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="siSaveEdit()" id="si-save-btn">
        <i class="bi bi-check-lg"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<script>
var SI_CSRF = <?= json_encode($csrf) ?>;
var SI_BASE = <?= json_encode(BASE_URL . '/modules/stock-import/index.php') ?>;
var SI_ALL_ROWS = <?= json_encode(array_map(function($r) {
  $out = [];
  foreach (['roll_no','paper_type','company','width_mm','length_mtr','gsm','weight_kg','purchase_rate','lot_batch_no','company_roll_no','status','job_no','job_size','job_name','date_received','date_used','remarks','_row_number','_errors'] as $k) {
    $out[$k] = $r[$k] ?? null;
  }
  return $out;
}, $allDisplayRows)) ?>;

function siScrollToRow(rowId) {
  var el = document.getElementById('analysis-row-' + rowId);
  if (!el) return;
  var wrap = document.getElementById('analysis-table-wrap');
  el.classList.remove('si-row-highlight');
  void el.offsetWidth;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.classList.add('si-row-highlight');
}

function siScrollToFirstIssue() {
  var rows = document.querySelectorAll('#analysis-table tbody tr.has-error');
  if (rows.length > 0) {
    rows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    rows[0].classList.remove('si-row-highlight');
    void rows[0].offsetWidth;
    rows[0].classList.add('si-row-highlight');
  }
}

function siToggleSkip(rowNum, btn) {
  var fd = new FormData();
  fd.append('action', 'toggle_skip');
  fd.append('csrf_token', SI_CSRF);
  fd.append('row_number', rowNum);
  fetch(SI_BASE, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok) return;
      var tr = document.getElementById('analysis-row-' + rowNum);
      var isNowSkipped = d.skipped.indexOf(rowNum) !== -1;
      tr.className = isNowSkipped ? 'row-skipped' : (siRowHasErrors(rowNum) ? 'has-error' : '');
      var stateCell = tr.children[7];
      if (isNowSkipped) {
        stateCell.innerHTML = '<span class="stock-import-pill" style="background:#f1f5f9;color:#64748b"><i class="bi bi-slash-circle"></i> Skipped</span>';
      } else if (siRowHasErrors(rowNum)) {
        stateCell.innerHTML = '<span class="stock-import-pill warning">Needs Review</span>';
      } else {
        stateCell.innerHTML = '<span class="stock-import-pill success">Ready</span>';
      }
      var actionCell = tr.children[8];
      if (isNowSkipped) {
        actionCell.innerHTML = '<div style="display:flex;gap:4px;justify-content:center"><button type="button" class="si-action-btn btn-skip is-skipped" title="Unskip row" onclick="siToggleSkip(' + rowNum + ', this)"><i class="bi bi-arrow-counterclockwise"></i></button></div>';
      } else {
        actionCell.innerHTML = '<div style="display:flex;gap:4px;justify-content:center"><button type="button" class="si-action-btn btn-edit" title="Edit row" onclick="siOpenEditModal(' + rowNum + ')"><i class="bi bi-pencil-square"></i></button><button type="button" class="si-action-btn btn-skip" title="Skip row" onclick="siToggleSkip(' + rowNum + ', this)"><i class="bi bi-x-lg"></i></button></div>';
      }
      var sc = document.getElementById('skipped-count');
      if (sc) sc.textContent = d.skipped.length;
    });
}

function siRowHasErrors(rowNum) {
  for (var i = 0; i < SI_ALL_ROWS.length; i++) {
    if (SI_ALL_ROWS[i]._row_number === rowNum) {
      return SI_ALL_ROWS[i]._errors && SI_ALL_ROWS[i]._errors.length > 0;
    }
  }
  return false;
}

function siOpenEditModal(rowNum) {
  var row = null;
  for (var i = 0; i < SI_ALL_ROWS.length; i++) {
    if (SI_ALL_ROWS[i]._row_number === rowNum) { row = SI_ALL_ROWS[i]; break; }
  }
  if (!row) return;
  document.getElementById('si-edit-row-number').value = rowNum;
  document.getElementById('si-edit-row-label').textContent = '#' + rowNum;
  var fields = ['roll_no','paper_type','company','width_mm','length_mtr','gsm','weight_kg','purchase_rate','lot_batch_no','company_roll_no','status','job_no','job_size','job_name','date_received','date_used','remarks'];
  for (var f = 0; f < fields.length; f++) {
    var el = document.getElementById('si-field-' + fields[f]);
    if (el) el.value = row[fields[f]] !== null && row[fields[f]] !== undefined ? row[fields[f]] : '';
  }
  document.getElementById('si-edit-modal').classList.add('is-open');
}

function siCloseModal() {
  document.getElementById('si-edit-modal').classList.remove('is-open');
}

function siSaveEdit() {
  var form = document.getElementById('si-edit-form');
  var fd = new FormData(form);
  var btn = document.getElementById('si-save-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
  fetch(SI_BASE, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok) {
        siCloseModal();
        window.location.reload();
      } else {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Save Changes';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Save Changes';
    });
}

document.getElementById('si-edit-modal').addEventListener('click', function(e) {
  if (e.target === this) siCloseModal();
});
</script>

<?php elseif ($importState === 'result'): ?>
<div class="card stock-import-card">
  <div style="background:#0f172a;color:#fff;padding:22px 24px">
    <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
      <i class="bi bi-check-circle" style="color:#22c55e;margin-right:8px"></i>Import Results
    </h2>
    <p style="margin:0;color:#cbd5e1;font-size:12px">The import has finished. Review the imported count and any rows that were skipped.</p>
  </div>

  <div style="padding:30px 24px;text-align:center">
    <div class="stock-import-result-grid">
      <div class="stock-import-metric" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534">
        <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Total Imported Rows</span>
        <strong><?= (int)$resultSummary['imported_rows'] ?></strong>
      </div>
      <div class="stock-import-metric" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">
        <span style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase">Error Rows</span>
        <strong><?= (int)$resultSummary['error_rows'] ?></strong>
      </div>
    </div>

    <p style="max-width:580px;margin:0 auto 24px;color:#64748b;font-size:13px;line-height:1.6">
      Imported <?= (int)$resultSummary['imported_rows'] ?> out of <?= (int)$resultSummary['valid_rows'] ?> valid rows from <?= e($resultSummary['file_name']) ?>.
    </p>

    <?php if (!empty($importErrors)): ?>
      <div style="max-width:720px;margin:0 auto 24px;text-align:left">
        <h3 style="font-size:13px;font-weight:700;margin:0 0 10px 0;color:#991b1b">Skipped / Error Rows</h3>
        <div class="stock-import-errors">
          <?php foreach ($importErrors as $error): ?>
            <div class="stock-import-error-item">
              <span class="stock-import-error-row">Row <?= e((string)$error['row']) ?></span>
              <span><?= e($error['message']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/modules/paper_stock/index.php" class="btn btn-primary">
        <i class="bi bi-eye"></i> View Imported Data
      </a>
      <a href="<?= BASE_URL ?>/modules/stock-import/index.php?reset=1" class="btn btn-ghost">
        <i class="bi bi-arrow-clockwise"></i> Import Another File
      </a>
    </div>
  </div>
</div>

<?php endif; ?>

<?php if ($importState !== 'template'): ?>
<?php
  $totalRollsAlways = (int)$db->query("SELECT COUNT(*) AS c FROM paper_stock")->fetch_assoc()['c'];
  $totalMtrAlways   = (float)$db->query("SELECT IFNULL(SUM(length_mtr),0) AS m FROM paper_stock")->fetch_assoc()['m'];
?>
<div class="card stock-import-card" style="margin-top:20px">
  <div style="background:#0f172a;color:#fff;padding:22px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 0">
        <i class="bi bi-database-gear" style="color:#22c55e;margin-right:8px"></i>Manage All Paper Rolls
      </h2>
      <p style="margin:0;color:#cbd5e1;font-size:12px">Bulk export inventory or delete all rolls from here.</p>
    </div>
    <span class="stock-import-pill success"><i class="bi bi-box-seam"></i> <?= number_format($totalRollsAlways) ?> rolls &middot; <?= number_format($totalMtrAlways, 0) ?> MTR</span>
  </div>
  <div style="padding:22px 24px">
    <div style="display:flex;justify-content:center;gap:12px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/modules/paper_stock/export.php?format=pdf&mode=all"
         class="stock-import-action download" style="background:#2563eb;border-color:#1d4ed8;min-width:170px;text-decoration:none"
         target="_blank">
        <i class="bi bi-file-earmark-pdf"></i>
        <span>Export PDF</span>
      </a>

      <a href="<?= BASE_URL ?>/modules/paper_stock/export.php?format=csv&mode=all"
         class="stock-import-action download" style="background:#16a34a;border-color:#15803d;min-width:170px;text-decoration:none">
        <i class="bi bi-file-earmark-excel"></i>
        <span>Export Excel</span>
      </a>

      <button type="button" onclick="confirmDeleteAllRollsGlobal()" class="stock-import-action upload"
              style="background:#dc2626;border-color:#b91c1c;min-width:170px;cursor:pointer"
              <?= $totalRollsAlways === 0 ? 'disabled style="opacity:.5;pointer-events:none"' : '' ?>>
        <i class="bi bi-trash3"></i>
        <span>Delete All Rolls (<?= number_format($totalRollsAlways) ?>)</span>
      </button>
    </div>
  </div>
</div>

<form id="delete-all-rolls-form-global" method="POST" action="<?= BASE_URL ?>/modules/paper_stock/batch_delete.php" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="ids" id="delete-all-roll-ids-global" value="">
</form>
<script>
function confirmDeleteAllRollsGlobal() {
  var total = <?= (int)$totalRollsAlways ?>;
  if (total === 0) return;
  var msg = 'Are you sure you want to DELETE ALL ' + total.toLocaleString() + ' paper roll(s)?\n\nThis action CANNOT be undone.';
  if (!confirm(msg)) return;
  var msg2 = 'FINAL CONFIRMATION: Type OK to proceed with deleting ALL rolls.';
  var answer = prompt(msg2);
  if (!answer || answer.trim().toUpperCase() !== 'OK') { alert('Deletion cancelled.'); return; }

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '<?= BASE_URL ?>/modules/paper_stock/export.php?format=ids&mode=all', true);
  xhr.onload = function() {
    if (xhr.status === 200) {
      document.getElementById('delete-all-roll-ids-global').value = xhr.responseText.trim();
      document.getElementById('delete-all-rolls-form-global').submit();
    } else {
      alert('Failed to fetch roll IDs. Please try again.');
    }
  };
  xhr.onerror = function() { alert('Network error. Please try again.'); };
  xhr.send();
}
</script>
<?php endif; ?>

<div style="margin-top:24px;padding:16px;background:#eff6ff;border:2px solid #bfdbfe;border-radius:12px;font-size:12px;color:#1e40af">
  <strong>Import Guidelines</strong>
  <ul style="margin:8px 0 0 0;padding-left:20px;line-height:1.8">
    <li>Use CSV or XLSX only. XLSX files are parsed from workbook data, not read as plain text.</li>
    <li>Required fields: roll_no, paper_type, company, width_mm, length_mtr.</li>
    <li>Missing required values and duplicate roll_no entries are shown during analysis before import.</li>
    <li>Duplicate roll_no values already present in paper stock are skipped and reported cleanly on the result screen.</li>
    <li>Maximum upload size: 20MB.</li>
  </ul>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
