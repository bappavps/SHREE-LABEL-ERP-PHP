<?php

function dieToolingModuleSlug() {
  return 'plate-data';
}

function dieToolingModuleLabel() {
  return 'Plate Data';
}

function dieToolingTableName() {
  return 'master_plate_data';
}

function dieToolingImportColumns() {
  return ['sl_no' => 'SL No.'] + dieToolingColumns();
}

function dieToolingColumns() {
  return [
    'date_received' => 'Date of Recv.',
    'name' => 'Name',
    'image_path' => 'Image',
    'ups' => 'UPS',
    'plate' => 'Plate',
    'size' => 'Size',
    'gap_h' => 'Gap (H)',
    'gap_v' => 'Gap (V)',
    'paper_size' => 'Paper Size',
    'paper_type' => 'Paper Type',
    'cylinder' => 'Cylinder',
    'make_by' => 'Make By',
    'die' => 'Die',
    'repeat_value' => 'Repeat',
    'core' => 'Core',
    'qty_roll' => 'Qty. Roll',
    'rewinding' => 'Rewinding',
    'c' => 'C',
    'm' => 'M',
    'y' => 'Y',
    'k' => 'K',
    'special_1' => 'Color 5',
    'special_2' => 'Color 6',
    'special_3' => 'Color 7',
    'special_4' => 'Color 8',
    'special_5' => 'Color 9',
  ];
}

function dieToolingColumnSqlTypes() {
  return [
    'sl_no' => 'VARCHAR(50) DEFAULT NULL',
    'date_received' => 'VARCHAR(40) DEFAULT NULL',
    'name' => 'VARCHAR(160) DEFAULT NULL',
    'image_path' => 'VARCHAR(255) DEFAULT NULL',
    'ups' => 'VARCHAR(80) DEFAULT NULL',
    'plate' => 'VARCHAR(120) DEFAULT NULL',
    'size' => 'VARCHAR(120) DEFAULT NULL',
    'gap_h' => 'VARCHAR(80) DEFAULT NULL',
    'gap_v' => 'VARCHAR(80) DEFAULT NULL',
    'paper_size' => 'VARCHAR(120) DEFAULT NULL',
    'paper_type' => 'VARCHAR(120) DEFAULT NULL',
    'cylinder' => 'VARCHAR(120) DEFAULT NULL',
    'make_by' => 'VARCHAR(120) DEFAULT NULL',
    'die' => 'VARCHAR(120) DEFAULT NULL',
    'repeat_value' => 'VARCHAR(120) DEFAULT NULL',
    'core' => 'VARCHAR(80) DEFAULT NULL',
    'qty_roll' => 'VARCHAR(80) DEFAULT NULL',
    'rewinding' => 'VARCHAR(120) DEFAULT NULL',
    'c' => 'VARCHAR(40) DEFAULT NULL',
    'm' => 'VARCHAR(40) DEFAULT NULL',
    'y' => 'VARCHAR(40) DEFAULT NULL',
    'k' => 'VARCHAR(40) DEFAULT NULL',
    'special_1' => 'VARCHAR(120) DEFAULT NULL',
    'special_2' => 'VARCHAR(120) DEFAULT NULL',
    'special_3' => 'VARCHAR(120) DEFAULT NULL',
    'special_4' => 'VARCHAR(120) DEFAULT NULL',
    'special_5' => 'VARCHAR(120) DEFAULT NULL',
  ];
}

function dieToolingColumnSynonyms() {
  return [
    'sl_no' => ['sl no', 'sl. no', 'serial no', 'serial number'],
    'date_received' => ['date of recv', 'date recv', 'date of received', 'date'],
    'name' => ['name'],
    'image_path' => ['image', 'thumbnail', 'thumb', 'photo', 'image path'],
    'ups' => ['ups', 'up'],
    'plate' => ['plate'],
    'size' => ['size'],
    'gap_h' => ['gap h', 'gap horizontal', 'horizontal gap'],
    'gap_v' => ['gap v', 'gap vertical', 'vertical gap'],
    'paper_size' => ['paper size'],
    'paper_type' => ['paper type'],
    'cylinder' => ['cylinder', 'cylender'],
    'make_by' => ['make by', 'made by', 'maker'],
    'die' => ['die'],
    'repeat_value' => ['repeat', 'repet'],
    'core' => ['core'],
    'qty_roll' => ['qty roll', 'qty. roll', 'quantity roll'],
    'rewinding' => ['rewinding'],
    'c' => ['c'],
    'm' => ['m'],
    'y' => ['y'],
    'k' => ['k'],
    'special_1' => ['special 1', 'color 5', 'colour 5'],
    'special_2' => ['special 2', 'color 6', 'colour 6'],
    'special_3' => ['special 3', 'color 7', 'colour 7'],
    'special_4' => ['special 4', 'color 8', 'colour 8'],
    'special_5' => ['special 5', 'color 9', 'colour 9'],
  ];
}

function dieToolingQuickFilters() {
  return [
    ['type' => 'text', 'key' => 'plate', 'label' => 'Plate Number', 'placeholder' => 'Enter plate number...'],
    ['type' => 'text', 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Search name...'],
    ['type' => 'pick', 'key' => 'paper_type', 'label' => 'Paper Type', 'allLabel' => 'All Paper Type'],
    ['type' => 'pick', 'key' => 'cylinder', 'label' => 'Cylinder', 'allLabel' => 'All Cylinder'],
    ['type' => 'pick', 'key' => 'make_by', 'label' => 'Make By', 'allLabel' => 'All Make By'],
    ['type' => 'pick', 'key' => 'core', 'label' => 'Core', 'allLabel' => 'All Core'],
    ['type' => 'number_range', 'key' => 'repeat_value', 'label' => 'Repeat', 'minPlaceholder' => 'Repeat min', 'maxPlaceholder' => 'Repeat max'],
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

function dieToolingRedirectUrl($mode = 'master') {
  global $dieToolingRedirectUrlOverride;
  if (isset($dieToolingRedirectUrlOverride) && trim((string)$dieToolingRedirectUrlOverride) !== '') {
    return (string)$dieToolingRedirectUrlOverride;
  }

  $mode = ($mode === 'design') ? 'design' : 'master';
  if ($mode === 'design') {
    return BASE_URL . '/modules/design/plate-data.php';
  }
  return BASE_URL . '/modules/master/plate-data.php';
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
