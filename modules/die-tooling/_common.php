<?php

function dieToolingImportColumns() {
  return ['sl_no' => 'SL No.'] + dieToolingColumns();
}

function dieToolingColumns() {
  return [
    'barcode_size' => 'BarCode Size',
    'ups_in_roll' => 'Ups in Roll',
    'up_in_die' => 'UPS in Die',
    'label_gap' => 'Label Gap',
    'paper_size' => 'Paper Size',
    'cylender' => 'Cylender',
    'repeat_size' => 'Repeat',
    'used_for' => 'Used IN',
    'die_type' => 'Die Type',
    'core' => 'Core',
    'pices_per_roll' => 'Pices per Roll',
  ];
}

function ensureDieToolingSchema(mysqli $db) {
  $sql = "
    CREATE TABLE IF NOT EXISTS master_die_tooling (
      id INT AUTO_INCREMENT PRIMARY KEY,
      sl_no VARCHAR(50) DEFAULT NULL,
      barcode_size VARCHAR(120) DEFAULT NULL,
      ups_in_roll VARCHAR(80) DEFAULT NULL,
      up_in_die VARCHAR(80) DEFAULT NULL,
      label_gap VARCHAR(80) DEFAULT NULL,
      paper_size VARCHAR(120) DEFAULT NULL,
      cylender VARCHAR(120) DEFAULT NULL,
      repeat_size VARCHAR(120) DEFAULT NULL,
      used_for VARCHAR(140) DEFAULT NULL,
      die_type VARCHAR(120) DEFAULT NULL,
      core VARCHAR(80) DEFAULT NULL,
      pices_per_roll VARCHAR(80) DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";
  $db->query($sql);

  $hasSlNo = $db->query("SHOW COLUMNS FROM master_die_tooling LIKE 'sl_no'");
  if ($hasSlNo instanceof mysqli_result && $hasSlNo->num_rows === 0) {
    $db->query("ALTER TABLE master_die_tooling ADD COLUMN sl_no VARCHAR(50) DEFAULT NULL AFTER id");
  }
  if ($hasSlNo instanceof mysqli_result) {
    $hasSlNo->close();
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
  $mode = ($mode === 'design') ? 'design' : 'master';
  if ($mode === 'design') {
    return BASE_URL . '/modules/design/barcode-die.php';
  }
  return BASE_URL . '/modules/master/barcode-die.php';
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
