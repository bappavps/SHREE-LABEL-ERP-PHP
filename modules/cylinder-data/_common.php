<?php

function dieToolingModuleSlug() {
  return 'cylinder-data';
}

function dieToolingModuleLabel() {
  return 'Cylinder Data';
}

function dieToolingTableName() {
  return 'master_cylinder_data';
}

function dieToolingImportColumns() {
  return ['sl_no' => 'SL No.'] + dieToolingColumns();
}

function dieToolingColumns() {
  return [
    'cylinder_type' => 'Cylinder Type',
    'cylinder_size_inch' => 'Sylinder Size (inc)',
    'cylinder_teeth' => 'Cylinder Teeth',
    'stock_qty' => 'Stock (QNTY)',
  ];
}

function dieToolingColumnSqlTypes() {
  return [
    'sl_no' => 'VARCHAR(50) DEFAULT NULL',
    'cylinder_type' => 'VARCHAR(80) DEFAULT NULL',
    'cylinder_size_inch' => 'VARCHAR(80) DEFAULT NULL',
    'cylinder_teeth' => 'VARCHAR(80) DEFAULT NULL',
    'stock_qty' => 'VARCHAR(80) DEFAULT NULL',
  ];
}

function dieToolingColumnSynonyms() {
  return [
    'sl_no' => ['sl no', 'sl. no', 'serial no', 'serial number'],
    'cylinder_type' => ['cylinder type', 'type', 'cylinder category'],
    'cylinder_size_inch' => ['sylinder size inc', 'cylinder size inc', 'cylinder size inch', 'size inc', 'size'],
    'cylinder_teeth' => ['sylinder tit', 'cylinder tit', 'sylinder teeth', 'cylinder teeth', 'tit', 'teeth'],
    'stock_qty' => ['stock qnty', 'stock qty', 'stock quantity', 'stock'],
  ];
}

function dieToolingQuickFilters() {
  return [
    ['type' => 'pick', 'key' => 'cylinder_type', 'label' => 'Cylinder Type', 'allLabel' => 'All types'],
    ['type' => 'text', 'key' => 'cylinder_size_inch', 'label' => 'Size', 'placeholder' => 'Search size...'],
    ['type' => 'text', 'key' => 'cylinder_teeth', 'label' => 'Cylinder Teeth', 'placeholder' => 'Search teeth...'],
    ['type' => 'number_range', 'key' => 'stock_qty', 'label' => 'Stock', 'minPlaceholder' => 'Stock min', 'maxPlaceholder' => 'Stock max'],
  ];
}

function ensureDieToolingSchema(mysqli $db) {
  $table = dieToolingTableName();
  $columnSql = [];
  foreach (dieToolingColumnSqlTypes() as $key => $sqlType) {
    $columnSql[] = "`{$key}` {$sqlType}";
  }

  $sql = "
    CREATE TABLE IF NOT EXISTS `{$table}` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      " . implode(",\n      ", $columnSql) . ",
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  $db->query($sql);

  $existingColumns = [];
  $result = $db->query("SHOW COLUMNS FROM `{$table}`");
  if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
      $existingColumns[] = (string)($row['Field'] ?? '');
    }
    $result->close();
  }

  foreach (dieToolingColumnSqlTypes() as $key => $sqlType) {
    if (!in_array($key, $existingColumns, true)) {
      $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$key}` {$sqlType} AFTER `id`");
    }
  }
}

function dieToolingCleanText($value, $maxLen = 190) {
  $value = trim((string)$value);
  if ($maxLen > 0) {
    $value = mb_substr($value, 0, $maxLen);
  }
  return $value;
}

function dieToolingNormalizePayload(array $input) {
  $data = [];
  foreach (dieToolingColumns() as $key => $label) {
    $data[$key] = dieToolingCleanText($input[$key] ?? '', 190);
  }
  return $data;
}

function dieToolingFormatDisplayNumber($value) {
  $value = trim((string)$value);
  if ($value === '') return '';
  if (!is_numeric($value)) return $value;

  $normalized = number_format((float)$value, 6, '.', '');
  $normalized = rtrim(rtrim($normalized, '0'), '.');
  return $normalized === '' ? '0' : $normalized;
}

if (!function_exists('dieToolingRedirectUrl')) {
  function dieToolingRedirectUrl($mode = 'master') {
    $mode = ($mode === 'design') ? 'design' : 'master';
    if ($mode === 'design') {
      return BASE_URL . '/modules/design/cylinder-data.php';
    }
    return BASE_URL . '/modules/master/cylinder-data.php';
  }
}

function dieToolingParseCsv($filePath) {
  $rows = [];
  $file = new SplFileObject($filePath);
  $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

  foreach ($file as $row) {
    if ($row === false || $row === [null]) continue;
    $rows[] = array_map(function ($v) {
      return trim((string)($v ?? ''));
    }, $row);
  }

  return $rows;
}

function dieToolingColumnIndexFromRef($cellRef) {
  if (!preg_match('/^([A-Z]+)/i', (string)$cellRef, $m)) {
    return 0;
  }
  $letters = strtoupper($m[1]);
  $idx = 0;
  for ($i = 0; $i < strlen($letters); $i++) {
    $idx = ($idx * 26) + (ord($letters[$i]) - 64);
  }
  return max(0, $idx - 1);
}

function dieToolingReadXlsxSharedStrings(ZipArchive $zip) {
  $xml = $zip->getFromName('xl/sharedStrings.xml');
  if ($xml === false) return [];
  $doc = simplexml_load_string($xml);
  if (!$doc) return [];

  $shared = [];
  foreach ($doc->xpath('//*[local-name()="si"]') as $item) {
    $parts = $item->xpath('.//*[local-name()="t"]');
    $text = '';
    if ($parts) {
      foreach ($parts as $part) {
        $text .= (string)$part;
      }
    }
    $shared[] = trim($text);
  }

  return $shared;
}

function dieToolingFindSheetPath(ZipArchive $zip) {
  $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
  $workbookXml = $zip->getFromName('xl/workbook.xml');

  if ($relsXml !== false && $workbookXml !== false) {
    $rels = simplexml_load_string($relsXml);
    $workbook = simplexml_load_string($workbookXml);
    if ($rels && $workbook) {
      $relationships = [];
      foreach ($rels->Relationship as $rel) {
        $relationships[(string)$rel['Id']] = (string)$rel['Target'];
      }
      $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
      if ($sheets && isset($sheets[0])) {
        $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        if ($rid && isset($relationships[$rid])) {
          return 'xl/' . ltrim($relationships[$rid], '/');
        }
      }
    }
  }

  for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string)$name)) {
      return $name;
    }
  }
  return null;
}

function dieToolingXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings) {
  $type = (string)$cell['t'];
  if ($type === 'inlineStr') {
    $parts = $cell->xpath('.//*[local-name()="t"]');
    $text = '';
    if ($parts) {
      foreach ($parts as $part) {
        $text .= (string)$part;
      }
    }
    return trim($text);
  }

  $value = isset($cell->v) ? (string)$cell->v : '';
  if ($type === 's') {
    $idx = (int)$value;
    return trim((string)($sharedStrings[$idx] ?? ''));
  }
  if ($type === 'b') {
    return $value === '1' ? 'TRUE' : 'FALSE';
  }
  return trim($value);
}

function dieToolingParseXlsx($filePath) {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZipArchive extension is required for XLSX import.');
  }

  $zip = new ZipArchive();
  if ($zip->open($filePath) !== true) {
    throw new RuntimeException('Unable to open uploaded XLSX file.');
  }

  $sheetPath = dieToolingFindSheetPath($zip);
  if (!$sheetPath) {
    $zip->close();
    throw new RuntimeException('Worksheet not found in uploaded XLSX file.');
  }

  $sheetXml = $zip->getFromName($sheetPath);
  $sharedStrings = dieToolingReadXlsxSharedStrings($zip);
  $zip->close();

  if ($sheetXml === false) {
    throw new RuntimeException('Unable to read worksheet data from XLSX file.');
  }

  $sheet = simplexml_load_string($sheetXml);
  if (!$sheet) {
    throw new RuntimeException('Invalid worksheet XML in XLSX file.');
  }

  $rows = [];
  foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
    $vals = [];
    $maxIndex = -1;
    foreach ($row->c as $cell) {
      $idx = dieToolingColumnIndexFromRef((string)$cell['r']);
      $vals[$idx] = dieToolingXlsxCellValue($cell, $sharedStrings);
      if ($idx > $maxIndex) $maxIndex = $idx;
    }
    if ($maxIndex < 0) continue;

    $normalized = [];
    for ($i = 0; $i <= $maxIndex; $i++) {
      $normalized[$i] = isset($vals[$i]) ? trim((string)$vals[$i]) : '';
    }
    $rows[] = $normalized;
  }

  return $rows;
}

function dieToolingParseSpreadsheet($filePath, $fileName) {
  $ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
  if ($ext === 'csv') return dieToolingParseCsv($filePath);
  if ($ext === 'xlsx') return dieToolingParseXlsx($filePath);
  throw new RuntimeException('Only CSV and XLSX files are supported.');
}
