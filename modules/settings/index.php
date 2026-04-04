<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$settings = getAppSettings();

$libraryCategories = [
  'company-logo' => 'Company Logo',
  'label-asset' => 'Label Asset',
  'background' => 'Background',
  'product-type' => 'Product Type',
  'misc' => 'Misc',
];

function normalizeHexColor($value, $fallback) {
  $value = trim((string)$value);
  if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) return strtoupper($value);
  return $fallback;
}

function isDirectImageUrl($url) {
  $url = trim((string)$url);
  if ($url === '') return false;
  return (bool)preg_match('/\.(gif|png|jpe?g|webp|avif|svg)(\?|#|$)/i', $url);
}

function resolveImageUrlFromPage($url) {
  $url = trim((string)$url);
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';

  $context = stream_context_create([
    'http' => [
      'timeout' => 6,
      'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ],
    'https' => [
      'timeout' => 6,
      'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    ],
  ]);

  $html = @file_get_contents($url, false, $context);
  if ($html === false || trim($html) === '') return '';

  $patterns = [
    '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i',
    '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
    '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/i',
  ];

  foreach ($patterns as $pattern) {
    if (preg_match($pattern, $html, $m)) {
      $candidate = html_entity_decode(trim((string)$m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if (filter_var($candidate, FILTER_VALIDATE_URL) && isDirectImageUrl($candidate)) {
        return $candidate;
      }
    }
  }

  return '';
}

function buildDatabaseBackupSql(?mysqli $db = null) {
  if (!$db instanceof mysqli) {
    $db = getDB();
  }
  $sql = [];
  $sql[] = '-- ERP System Database Backup';
  $sql[] = '-- Generated: ' . date('Y-m-d H:i:s');
  $sql[] = '-- Database: ' . DB_NAME;
  $sql[] = '';
  $sql[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
  $sql[] = 'SET FOREIGN_KEY_CHECKS = 0;';
  $sql[] = '';

  $tablesRes = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
  if (!$tablesRes) {
    return '';
  }

  while ($row = $tablesRes->fetch_row()) {
    $table = (string)$row[0];
    $safeTable = '`' . str_replace('`', '``', $table) . '`';

    $createRes = $db->query("SHOW CREATE TABLE " . $safeTable);
    if (!$createRes) continue;
    $createRow = $createRes->fetch_assoc();
    $createStmt = (string)($createRow['Create Table'] ?? '');
    if ($createStmt === '') continue;

    $sql[] = '--';
    $sql[] = '-- Structure for table ' . $table;
    $sql[] = '--';
    $sql[] = 'DROP TABLE IF EXISTS ' . $safeTable . ';';
    $sql[] = $createStmt . ';';
    $sql[] = '';

    $dataRes = $db->query("SELECT * FROM " . $safeTable);
    if (!$dataRes) continue;
    if ($dataRes->num_rows > 0) {
      $sql[] = '--';
      $sql[] = '-- Data for table ' . $table;
      $sql[] = '--';
    }

    while ($dataRow = $dataRes->fetch_assoc()) {
      $columns = [];
      $values = [];
      foreach ($dataRow as $col => $val) {
        $columns[] = '`' . str_replace('`', '``', (string)$col) . '`';
        if ($val === null) {
          $values[] = 'NULL';
        } else {
          $values[] = "'" . $db->real_escape_string((string)$val) . "'";
        }
      }
      $sql[] = 'INSERT INTO ' . $safeTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
    }
    if ($dataRes->num_rows > 0) $sql[] = '';
  }

  $sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
  $sql[] = '';
  return implode("\n", $sql);
}

function restoreDatabaseFromSql(mysqli $db, string $sqlDump): array {
  $restoreErr = '';
  $db->query('SET FOREIGN_KEY_CHECKS=0');
  $restoreOk = true;

  if (!$db->multi_query($sqlDump)) {
    $restoreOk = false;
    $restoreErr = $db->error;
  } else {
    do {
      if ($res = $db->store_result()) {
        $res->free();
      }
      if (!$db->more_results()) break;
      if (!$db->next_result()) {
        $restoreOk = false;
        $restoreErr = $db->error;
        break;
      }
    } while (true);
  }

  $db->query('SET FOREIGN_KEY_CHECKS=1');
  return ['ok' => $restoreOk, 'error' => $restoreErr];
}

function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void {
  if (!is_dir($sourceDir)) return;
  $base = rtrim(str_replace('\\', '/', realpath($sourceDir) ?: $sourceDir), '/');
  if (!is_dir($base)) return;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($it as $entry) {
    $path = str_replace('\\', '/', $entry->getPathname());
    $rel = ltrim(substr($path, strlen($base)), '/');
    if ($rel === '') continue;
    $zipPath = trim($zipPrefix, '/') . '/' . $rel;
    if ($entry->isDir()) {
      $zip->addEmptyDir($zipPath);
    } elseif ($entry->isFile()) {
      $zip->addFile($path, $zipPath);
    }
  }
}

function restoreZipAsset(ZipArchive $zip, string $entryName, string $projectRoot): void {
  $name = str_replace('\\', '/', $entryName);
  $allowedPrefixes = ['uploads/company/', 'uploads/library/'];
  $allowedSingles = ['data/app_settings.json'];

  $isAllowed = in_array($name, $allowedSingles, true);
  if (!$isAllowed) {
    foreach ($allowedPrefixes as $prefix) {
      if (strpos($name, $prefix) === 0) {
        $isAllowed = true;
        break;
      }
    }
  }
  if (!$isAllowed) return;
  if (strpos($name, '..') !== false) return;

  $target = $projectRoot . '/' . $name;
  $targetDir = dirname($target);
  if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0777, true);
  }

  $in = $zip->getStream($entryName);
  if (!$in) return;
  $out = @fopen($target, 'wb');
  if (!$out) {
    fclose($in);
    return;
  }
  while (!feof($in)) {
    $chunk = fread($in, 8192);
    if ($chunk === false) break;
    fwrite($out, $chunk);
  }
  fclose($out);
  fclose($in);
}

function saveUploadedImage($fileKey, $targetDir, $prefix) {
  if (empty($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) return ['', ''];
  $file = $_FILES[$fileKey];
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', ''];
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return ['', 'Upload failed for ' . $fileKey . '.'];
  if (($file['size'] ?? 0) > 5 * 1024 * 1024) return ['', 'File size must be below 5MB.'];

  $tmp = $file['tmp_name'] ?? '';
  $mime = @mime_content_type($tmp);
  $allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  ];
  if (!isset($allowed[$mime])) return ['', 'Only PNG, JPG, WEBP, GIF files are allowed.'];

  $ext = $allowed[$mime];
  $safeName = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $absDir = __DIR__ . '/../../uploads/' . trim($targetDir, '/');
  if (!is_dir($absDir)) @mkdir($absDir, 0777, true);
  $absPath = $absDir . '/' . $safeName;
  if (!@move_uploaded_file($tmp, $absPath)) return ['', 'Unable to save uploaded file.'];

  return ['uploads/' . trim($targetDir, '/') . '/' . $safeName, ''];
}

$activeTab = $_GET['tab'] ?? 'company';
$allowedTabs = ['company', 'library', 'theme', 'backup', 'update'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'company';

// Support paper_type parameter from paper stock view
$targetPaperType = trim((string)($_GET['paper_type'] ?? ''));
if ($targetPaperType !== '') {
  $activeTab = 'library';
  $libraryFilter = 'all';
} else {
  $libraryFilter = $_GET['category'] ?? 'all';
}

if ($libraryFilter !== 'all' && !isset($libraryCategories[$libraryFilter])) {
  $libraryFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please retry.');
    redirect(BASE_URL . '/modules/settings/index.php?tab=' . urlencode($activeTab));
  }

  $action = $_POST['action'] ?? '';

  if ($action === 'save_company') {
    $settings['company_name'] = trim((string)($_POST['company_name'] ?? ''));
    $settings['company_email'] = trim((string)($_POST['company_email'] ?? ''));
    $settings['company_mobile'] = trim((string)($_POST['company_mobile'] ?? ''));
    $settings['company_phone'] = $settings['company_mobile'];
    $settings['company_currency'] = strtoupper(trim((string)($_POST['company_currency'] ?? 'INR')));
    $settings['company_address'] = trim((string)($_POST['company_address'] ?? ''));
    $settings['company_gst'] = trim((string)($_POST['company_gst'] ?? ''));
    $settings['flag_emoji'] = trim((string)($_POST['flag_emoji'] ?? '🇮🇳')) ?: '🇮🇳';
    $animatedFlagUrl = trim((string)($_POST['animated_flag_url'] ?? ''));
    if ($animatedFlagUrl !== '' && !filter_var($animatedFlagUrl, FILTER_VALIDATE_URL)) {
      setFlash('error', 'Please enter a valid animated flag image URL.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=company');
    }
    if ($animatedFlagUrl !== '' && !isDirectImageUrl($animatedFlagUrl)) {
      $resolvedFlagUrl = resolveImageUrlFromPage($animatedFlagUrl);
      if ($resolvedFlagUrl !== '') {
        $animatedFlagUrl = $resolvedFlagUrl;
      } else {
        setFlash('error', 'This link is a webpage, not a direct image. Use a direct GIF/WEBP/JPG/PNG link, or a Tenor media link.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=company');
      }
    }
    $settings['animated_flag_url'] = $animatedFlagUrl;

    list($logoPath, $logoErr) = saveUploadedImage('company_logo', 'company', 'logo');
    if ($logoErr !== '') {
      setFlash('error', $logoErr);
      redirect(BASE_URL . '/modules/settings/index.php?tab=company');
    }
    if ($logoPath !== '') $settings['logo_path'] = $logoPath;

    list($flagPath, $flagErr) = saveUploadedImage('animated_flag', 'company', 'flag');
    if ($flagErr !== '') {
      setFlash('error', $flagErr);
      redirect(BASE_URL . '/modules/settings/index.php?tab=company');
    }
    if ($flagPath !== '') $settings['animated_flag_path'] = $flagPath;

    if (saveAppSettings($settings)) {
      setFlash('success', 'Company profile saved successfully.');
    } else {
      setFlash('error', 'Unable to save company profile settings.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=company');
  }

  if ($action === 'upload_library_image') {
    $category = $_POST['image_category'] ?? 'misc';
    if (!isset($libraryCategories[$category])) $category = 'misc';

    $paperType = trim((string)($_POST['paper_type_tag'] ?? ''));
    if ($category === 'product-type' && $paperType === '') {
      setFlash('error', 'Paper type name is required for Product Type images.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=library');
    }

    list($imgPath, $imgErr) = saveUploadedImage('library_image', 'library', 'lib');
    if ($imgErr !== '') {
      setFlash('error', $imgErr);
      redirect(BASE_URL . '/modules/settings/index.php?tab=library');
    }
    if ($imgPath !== '') {
      if (!isset($settings['image_library']) || !is_array($settings['image_library'])) $settings['image_library'] = [];
      $entry = [
        'path' => $imgPath,
        'name' => basename($imgPath),
        'uploaded_at' => date('Y-m-d H:i:s'),
        'category' => $category,
      ];
      if ($category === 'product-type' && $paperType !== '') {
        $entry['paper_type'] = $paperType;
      }
      $settings['image_library'][] = $entry;
      if ($category === 'background' && trim((string)($settings['login_background_image'] ?? '')) === '') {
        $settings['login_background_image'] = $imgPath;
      }
      if (saveAppSettings($settings)) {
        if ($category === 'background' && ($settings['login_background_image'] ?? '') === $imgPath) {
          setFlash('success', 'Background uploaded and set as login background.');
        } else {
          setFlash('success', 'Image uploaded to library.');
        }
      } else {
        setFlash('error', 'Image uploaded but settings file could not be updated.');
      }
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library');
  }

  if ($action === 'remove_library_image') {
    $idx = (int)($_POST['image_index'] ?? -1);
    if (isset($settings['image_library'][$idx])) {
      $path = (string)$settings['image_library'][$idx]['path'];
      $abs = __DIR__ . '/../../' . ltrim($path, '/');
      if (is_file($abs)) @unlink($abs);
      array_splice($settings['image_library'], $idx, 1);
      saveAppSettings($settings);
      setFlash('success', 'Image removed from library.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library&category=' . urlencode($libraryFilter));
  }

  if ($action === 'assign_image_to_papertype') {
    $imageIdx = (int)($_POST['image_index'] ?? -1);
    $paperType = trim((string)($_POST['paper_type'] ?? ''));
    
    if ($imageIdx >= 0 && isset($settings['image_library'][$imageIdx]) && $paperType !== '') {
      $imagePath = (string)($settings['image_library'][$imageIdx]['path'] ?? '');
      
      // Find existing entry for this paper type and update it
      $found = false;
      foreach (($settings['image_library'] ?? []) as &$img) {
        if (($img['category'] ?? '') === 'product-type' && 
            strtolower(trim((string)($img['paper_type'] ?? ''))) === strtolower($paperType)) {
          $img['path'] = $imagePath;
          $img['paper_type'] = $paperType;
          $found = true;
          break;
        }
      }
      
      // If not found, add new entry
      if (!$found) {
        $settings['image_library'][] = [
          'category' => 'product-type',
          'paper_type' => $paperType,
          'path' => $imagePath,
          'name' => basename($imagePath),
          'title' => $paperType . ' Thumbnail',
          'uploaded_at' => date('Y-m-d H:i:s')
        ];
      }
      
      if (saveAppSettings($settings)) {
        setFlash('success', 'Image assigned to paper type "' . htmlspecialchars($paperType) . '" successfully.');
      } else {
        setFlash('error', 'Failed to assign image to paper type.');
      }
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=library&category=product-type');
  }

  if ($action === 'save_theme') {
    $settings['theme_mode'] = (($_POST['theme_mode'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $settings['sidebar_button_color'] = normalizeHexColor($_POST['sidebar_button_color'] ?? '', '#22C55E');
    $settings['sidebar_hover_color'] = normalizeHexColor($_POST['sidebar_hover_color'] ?? '', '#263445');
    $settings['sidebar_active_bg'] = normalizeHexColor($_POST['sidebar_active_bg'] ?? '', '#214036');
    $settings['sidebar_active_text'] = normalizeHexColor($_POST['sidebar_active_text'] ?? '', '#BBF7D0');
    $settings['sidebar_collapse_delay_ms'] = min(600000, max(300, (int)($_POST['sidebar_collapse_delay_ms'] ?? 1000)));
    $settings['login_background_image'] = trim((string)($_POST['login_background_image'] ?? ''));

    $validLibraryPaths = [];
    foreach (($settings['image_library'] ?? []) as $img) {
      if (!empty($img['path'])) $validLibraryPaths[] = (string)$img['path'];
    }
    if ($settings['login_background_image'] !== '' && !in_array($settings['login_background_image'], $validLibraryPaths, true)) {
      $settings['login_background_image'] = '';
    }

    if (saveAppSettings($settings)) {
      setFlash('success', 'Theme settings saved successfully.');
    } else {
      setFlash('error', 'Unable to save theme settings.');
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=theme');
  }



  if ($action === 'download_backup') {
    if (!isset($db) || !($db instanceof mysqli)) {
      $db = getDB();
    }
    $backupSql = buildDatabaseBackupSql($db ?? null);
    if ($backupSql === '') {
      setFlash('error', 'Unable to generate backup. Please check database access.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $stamp = date('Ymd_His');
    $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');

    if (!class_exists('ZipArchive')) {
      $fileName = DB_NAME . '_backup_' . $stamp . '.sql';
      header('Content-Type: application/sql; charset=UTF-8');
      header('Content-Disposition: attachment; filename="' . $fileName . '"');
      header('Content-Length: ' . strlen($backupSql));
      echo $backupSql;
      exit;
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'erpbk_');
    if ($tmpZip === false) {
      setFlash('error', 'Unable to create backup file. Please try again.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
      @unlink($tmpZip);
      setFlash('error', 'Unable to open backup archive for writing.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip->addFromString('backup/database.sql', $backupSql);
    $settingsFile = $projectRoot . '/data/app_settings.json';
    if (is_file($settingsFile)) {
      $zip->addFile($settingsFile, 'data/app_settings.json');
    }
    addDirectoryToZip($zip, $projectRoot . '/uploads/company', 'uploads/company');
    addDirectoryToZip($zip, $projectRoot . '/uploads/library', 'uploads/library');
    $zip->close();

    $fileName = DB_NAME . '_full_backup_' . $stamp . '.zip';
    $size = @filesize($tmpZip);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    if ($size !== false) {
      header('Content-Length: ' . $size);
    }
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
  }

  if ($action === 'restore_backup') {
    if (!isset($db) || !($db instanceof mysqli)) {
      $db = getDB();
    }
    $fileInput = $_FILES['backup_file'] ?? $_FILES['backup_sql'] ?? null;
    if (empty($fileInput) || !is_array($fileInput)) {
      setFlash('error', 'Please choose a backup file (.zip or .sql) to restore.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $file = $fileInput;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      setFlash('error', 'Backup upload failed. Please try again.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }
    if (($file['size'] ?? 0) > 30 * 1024 * 1024) {
      setFlash('error', 'Backup file is too large. Maximum allowed size is 30MB.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $name = strtolower((string)($file['name'] ?? ''));
    $isSql = (substr($name, -4) === '.sql');
    $isZip = (substr($name, -4) === '.zip');
    if (!$isSql && !$isZip) {
      setFlash('error', 'Invalid file format. Please upload a .zip (full backup) or .sql file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    if ($isSql) {
      $sqlDump = @file_get_contents((string)$file['tmp_name']);
      if ($sqlDump === false || trim($sqlDump) === '') {
        setFlash('error', 'Could not read uploaded SQL backup file.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
      }
      $res = restoreDatabaseFromSql($db, $sqlDump);
      if ($res['ok']) {
        setFlash('success', 'Database restore completed successfully.');
      } else {
        setFlash('error', 'Restore failed: ' . ($res['error'] ?: 'Unknown SQL error.'));
      }
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    if (!class_exists('ZipArchive')) {
      setFlash('error', 'ZIP restore requires ZipArchive extension in PHP.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$file['tmp_name']) !== true) {
      setFlash('error', 'Could not open ZIP backup file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $sqlDump = '';
    $sqlIndex = $zip->locateName('backup/database.sql', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
    if ($sqlIndex === false) {
      $sqlIndex = $zip->locateName('database.sql', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
    }
    if ($sqlIndex !== false) {
      $sqlDump = (string)$zip->getFromIndex($sqlIndex);
    }
    if (trim($sqlDump) === '') {
      $zip->close();
      setFlash('error', 'Invalid backup ZIP: database.sql not found.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $dbRes = restoreDatabaseFromSql($db, $sqlDump);
    if (!$dbRes['ok']) {
      $zip->close();
      setFlash('error', 'Database restore failed: ' . ($dbRes['error'] ?: 'Unknown SQL error.'));
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entryName = (string)$zip->getNameIndex($i);
      if ($entryName === '' || substr($entryName, -1) === '/') continue;
      restoreZipAsset($zip, $entryName, str_replace('\\', '/', $projectRoot));
    }
    $zip->close();

    setFlash('success', 'Full backup restored successfully (database + images/settings).');
    redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
  }

  // ── Apply System Update ──
  if ($action === 'apply_update') {
    if (!isAdmin()) {
      setFlash('error', 'Only administrators can apply system updates.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $fileInput = $_FILES['update_file'] ?? null;
    if (empty($fileInput) || !is_array($fileInput) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      setFlash('error', 'Please select a valid update ZIP file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    if (($fileInput['size'] ?? 0) > 50 * 1024 * 1024) {
      setFlash('error', 'Update file too large. Maximum 50 MB.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    $fname = strtolower((string)($fileInput['name'] ?? ''));
    if (substr($fname, -4) !== '.zip') {
      setFlash('error', 'Only .zip update packages are accepted.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    if (!class_exists('ZipArchive')) {
      setFlash('error', 'ZipArchive PHP extension is required.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $zip = new ZipArchive();
    if ($zip->open((string)$fileInput['tmp_name']) !== true) {
      setFlash('error', 'Cannot open update ZIP file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Read manifest
    $manifestJson = $zip->getFromName('manifest.json');
    if ($manifestJson === false) {
      $zip->close();
      setFlash('error', 'Invalid update package: manifest.json not found.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }
    // Be tolerant of UTF-8 BOM in manifests produced by some zip/build tools.
    $manifestJson = preg_replace('/^\xEF\xBB\xBF/', '', (string)$manifestJson);
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || empty($manifest['version']) || empty($manifest['files'])) {
      $zip->close();
      setFlash('error', 'Invalid manifest.json: missing version or files list.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Validate checksum
    $checksumData = '';
    foreach ((array)$manifest['files'] as $relPath) {
      $content = $zip->getFromName('files/' . $relPath);
      if ($content !== false) {
        $checksumData .= hash('sha256', $content);
      }
    }
    foreach ((array)($manifest['migrations'] ?? []) as $mf) {
      $content = $zip->getFromName('migrations/' . $mf);
      if ($content !== false) {
        $checksumData .= hash('sha256', $content);
      }
    }
    if (!empty($manifest['checksum']) && hash('sha256', $checksumData) !== $manifest['checksum']) {
      $zip->close();
      setFlash('error', 'Checksum verification failed. The update package may be corrupted.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Security: validate all file paths
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../'));
    foreach ((array)$manifest['files'] as $relPath) {
      $relPath = str_replace('\\', '/', (string)$relPath);
      if (strpos($relPath, '..') !== false || $relPath === 'config/db.php') {
        $zip->close();
        setFlash('error', 'Security violation: invalid file path in update package.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=update');
      }
    }

    // ZIP bomb check: total uncompressed size limit (100 MB)
    $totalSize = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $stat = $zip->statIndex($i);
      if ($stat) $totalSize += (int)($stat['size'] ?? 0);
    }
    if ($totalSize > 100 * 1024 * 1024) {
      $zip->close();
      setFlash('error', 'Update package too large when extracted (>100 MB).');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // ── Auto-backup before applying ──
    $backupsDir = $projectRoot . '/uploads/updates/backups';
    if (!is_dir($backupsDir)) {
      @mkdir($backupsDir, 0755, true);
    }
    $stamp = date('Ymd_His');
    $preBackupPath = $backupsDir . '/pre_update_v' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $manifest['version']) . '_' . $stamp . '.zip';

    $bkZip = new ZipArchive();
    if ($bkZip->open($preBackupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
      // Backup files that will be overwritten
      foreach ((array)$manifest['files'] as $relPath) {
        $absPath = $projectRoot . '/' . str_replace('\\', '/', $relPath);
        if (is_file($absPath)) {
          $bkZip->addFile($absPath, 'files/' . $relPath);
        }
      }
      // Backup database
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      $bkSql = buildDatabaseBackupSql($db);
      if ($bkSql !== '') {
        $bkZip->addFromString('backup/database.sql', $bkSql);
      }
      // Save old version in backup manifest
      $bkManifest = [
        'version' => APP_VERSION,
        'timestamp' => date('c'),
        'description' => 'Pre-update backup before applying v' . $manifest['version'],
        'files' => (array)$manifest['files'],
      ];
      $bkZip->addFromString('manifest.json', json_encode($bkManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $bkZip->close();
    }

    // ── Extract files ──
    $extractedCount = 0;
    foreach ((array)$manifest['files'] as $relPath) {
      $relPath = str_replace('\\', '/', (string)$relPath);
      $content = $zip->getFromName('files/' . $relPath);
      if ($content === false) continue;
      $absTarget = $projectRoot . '/' . $relPath;
      $targetDir = dirname($absTarget);
      if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0755, true);
      }
      if (file_put_contents($absTarget, $content) !== false) {
        $extractedCount++;
      }
    }

    // ── Run SQL migrations ──
    $migrationResults = [];
    $migrations = (array)($manifest['migrations'] ?? []);
    sort($migrations);
    if (!empty($migrations)) {
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      foreach ($migrations as $mf) {
        $sqlContent = $zip->getFromName('migrations/' . $mf);
        if ($sqlContent === false || trim($sqlContent) === '') continue;
        $res = restoreDatabaseFromSql($db, $sqlContent);
        $migrationResults[] = [
          'file' => $mf,
          'ok' => $res['ok'],
          'error' => $res['error'] ?? '',
        ];
      }
    }

    $zip->close();

    // ── Bump APP_VERSION in config/db.php ──
    $configPath = $projectRoot . '/config/db.php';
    if (is_file($configPath) && is_writable($configPath)) {
      $configContent = file_get_contents($configPath);
      if ($configContent !== false) {
        $newVersion = preg_replace('/[^a-zA-Z0-9._-]/', '', $manifest['version']);
        $configContent = preg_replace(
          "/('APP_VERSION'\s*=>\s*')[^']*(')/",
          '${1}' . $newVersion . '${2}',
          $configContent
        );
        file_put_contents($configPath, $configContent);
      }
    }

    // ── Log update ──
    $logFile = $projectRoot . '/data/update_log.json';
    $log = [];
    if (is_file($logFile)) {
      $logRaw = file_get_contents($logFile);
      $log = json_decode($logRaw, true);
      if (!is_array($log)) $log = [];
    }
    $failedMigrations = array_filter($migrationResults, function($r) { return !$r['ok']; });
    $log[] = [
      'version'         => $manifest['version'],
      'from_version'    => $manifest['from_version'] ?? APP_VERSION,
      'description'     => $manifest['description'] ?? '',
      'timestamp'       => date('c'),
      'files_count'     => $extractedCount,
      'files'           => (array)$manifest['files'],
      'migrations_run'  => count($migrationResults),
      'migrations_failed' => count($failedMigrations),
      'backup_path'     => str_replace($projectRoot . '/', '', $preBackupPath),
      'applied_by'      => $_SESSION['username'] ?? 'admin',
    ];
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Build result message
    $msg = "Update v{$manifest['version']} applied: {$extractedCount} file(s) updated";
    if (!empty($migrationResults)) {
      $msg .= ', ' . count($migrationResults) . ' migration(s) executed';
    }
    if (!empty($failedMigrations)) {
      $failNames = array_map(function($r) { return $r['file']; }, $failedMigrations);
      $msg .= '. WARNING: Failed migrations: ' . implode(', ', $failNames);
      setFlash('error', $msg);
    } else {
      $msg .= '.';
      setFlash('success', $msg);
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=update');
  }

  // ── Rollback Update ──
  if ($action === 'rollback_update') {
    if (!isAdmin()) {
      setFlash('error', 'Only administrators can rollback updates.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $rollbackIdx = (int)($_POST['rollback_index'] ?? -1);
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../'));
    $logFile = $projectRoot . '/data/update_log.json';
    $log = [];
    if (is_file($logFile)) {
      $log = json_decode(file_get_contents($logFile), true);
      if (!is_array($log)) $log = [];
    }

    if ($rollbackIdx < 0 || !isset($log[$rollbackIdx])) {
      setFlash('error', 'Invalid rollback target.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $entry = $log[$rollbackIdx];
    $backupZipPath = $projectRoot . '/' . ($entry['backup_path'] ?? '');
    if (!is_file($backupZipPath)) {
      setFlash('error', 'Pre-update backup file not found. Cannot rollback.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    $zip = new ZipArchive();
    if ($zip->open($backupZipPath) !== true) {
      setFlash('error', 'Cannot open backup ZIP for rollback.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=update');
    }

    // Restore files
    $restoredCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $eName = str_replace('\\', '/', (string)$zip->getNameIndex($i));
      if (strpos($eName, 'files/') === 0 && substr($eName, -1) !== '/') {
        $relPath = substr($eName, 6); // strip 'files/'
        if (strpos($relPath, '..') !== false) continue;
        $content = $zip->getFromIndex($i);
        if ($content === false) continue;
        $absTarget = $projectRoot . '/' . $relPath;
        $targetDir = dirname($absTarget);
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        if (file_put_contents($absTarget, $content) !== false) {
          $restoredCount++;
        }
      }
    }

    // Restore database if backup contains it
    $sqlDump = $zip->getFromName('backup/database.sql');
    $dbRestored = false;
    if ($sqlDump !== false && trim($sqlDump) !== '') {
      if (!isset($db) || !($db instanceof mysqli)) $db = getDB();
      $dbRes = restoreDatabaseFromSql($db, $sqlDump);
      $dbRestored = $dbRes['ok'];
    }

    // Read old version from backup manifest
    $bkManifestJson = $zip->getFromName('manifest.json');
    $zip->close();

    $oldVersion = $entry['from_version'] ?? '';
    if ($bkManifestJson !== false) {
      $bkManifest = json_decode($bkManifestJson, true);
      if (is_array($bkManifest) && !empty($bkManifest['version'])) {
        $oldVersion = $bkManifest['version'];
      }
    }

    // Revert APP_VERSION
    if ($oldVersion !== '') {
      $configPath = $projectRoot . '/config/db.php';
      if (is_file($configPath) && is_writable($configPath)) {
        $configContent = file_get_contents($configPath);
        if ($configContent !== false) {
          $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '', $oldVersion);
          $configContent = preg_replace(
            "/('APP_VERSION'\s*=>\s*')[^']*(')/",
            '${1}' . $safeVer . '${2}',
            $configContent
          );
          file_put_contents($configPath, $configContent);
        }
      }
    }

    // Mark rollback in log
    $log[$rollbackIdx]['rolled_back']    = true;
    $log[$rollbackIdx]['rolled_back_at'] = date('c');
    $log[$rollbackIdx]['rolled_back_by'] = $_SESSION['username'] ?? 'admin';
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $msg = "Rollback complete: {$restoredCount} file(s) restored";
    if ($dbRestored) $msg .= ', database restored';
    $msg .= ", version reverted to {$oldVersion}.";
    setFlash('success', $msg);
    redirect(BASE_URL . '/modules/settings/index.php?tab=update');
  }
}

$filteredLibrary = [];
foreach (($settings['image_library'] ?? []) as $idx => $img) {
  $cat = $img['category'] ?? 'misc';
  if ($libraryFilter === 'all' || $libraryFilter === $cat) {
    $filteredLibrary[$idx] = $img;
  }
}

$backgroundOptions = [];
foreach (($settings['image_library'] ?? []) as $img) {
  if (($img['category'] ?? '') === 'background' && !empty($img['path'])) {
    $backgroundOptions[] = $img;
  }
}

$pageTitle = 'Settings';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Master</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Settings</span>
</div>

<div class="page-header">
  <div>
    <h1>Settings</h1>
    <p>Modern configuration panel for company profile, image assets, and visual theme.</p>
  </div>
</div>

<div class="card settings-card settings-modern">
  <div class="settings-tabs" role="tablist" aria-label="Settings Tabs">
    <a class="settings-tab <?= $activeTab==='company'?'active':'' ?>" href="?tab=company">Company Profile</a>
    <a class="settings-tab <?= $activeTab==='library'?'active':'' ?>" href="?tab=library">Image Library</a>
    <a class="settings-tab <?= $activeTab==='theme'?'active':'' ?>" href="?tab=theme">Color Theme</a>
    <a class="settings-tab <?= $activeTab==='backup'?'active':'' ?>" href="?tab=backup">Backup &amp; Restore</a>
    <a class="settings-tab <?= $activeTab==='update'?'active':'' ?>" href="?tab=update">System Update</a>
  </div>

  <div class="settings-body">
    <?php if ($activeTab === 'company'): ?>
      <form method="POST" enctype="multipart/form-data" class="form-grid-2">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="save_company">

        <div class="form-group col-span-2">
          <label>Company Legal Name</label>
          <input type="text" name="company_name" value="<?= e($settings['company_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Email</label>
          <input type="email" name="company_email" value="<?= e($settings['company_email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Mobile Number</label>
          <input type="text" name="company_mobile" value="<?= e($settings['company_mobile'] ?? ($settings['company_phone'] ?? '')) ?>" placeholder="e.g. +91 9876543210">
        </div>

        <div class="form-group">
          <label>Currency</label>
          <input type="text" name="company_currency" value="<?= e($settings['company_currency'] ?? 'INR') ?>" placeholder="e.g. INR, USD">
        </div>
        <div class="form-group">
          <label>GST / Tax ID</label>
          <input type="text" name="company_gst" value="<?= e($settings['company_gst'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Flag Emoji Fallback</label>
          <input type="text" name="flag_emoji" value="<?= e($settings['flag_emoji'] ?? '🇮🇳') ?>" maxlength="4">
        </div>

        <div class="form-group col-span-2">
          <label>Address</label>
          <textarea name="company_address" rows="3"><?= e($settings['company_address'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label>Company Logo</label>
          <input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp,image/gif">
          <?php if (!empty($settings['logo_path'])): ?>
            <div class="settings-preview mt-8"><img src="<?= e(appUrl($settings['logo_path'])) ?>" alt="Current logo"></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label>Animated Flag Image</label>
          <input type="file" name="animated_flag" accept="image/png,image/jpeg,image/webp,image/gif">
          <?php if (!empty($settings['animated_flag_path'])): ?>
            <div class="settings-preview mt-8"><img src="<?= e(appUrl($settings['animated_flag_path'])) ?>" alt="Current flag"></div>
          <?php endif; ?>
        </div>

        <div class="form-group col-span-2">
          <label>Animated Flag Image URL (Optional)</label>
          <input type="url" name="animated_flag_url" value="<?= e($settings['animated_flag_url'] ?? '') ?>" placeholder="https://example.com/flag.gif">
          <small class="help-text">If provided, this URL is used first. Page links are auto-resolved to image URLs when possible.</small>
        </div>

        <div class="form-actions col-span-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Company Profile</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($activeTab === 'library'): ?>
      <?php if ($targetPaperType !== ''): ?>
        <div style="background:#e0f2fe;border:1px solid #0284c7;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#0c4a6e;font-size:.95rem">
          <i class="bi bi-info-circle"></i> <strong>Assigning image to paper type:</strong> <strong><?= e($targetPaperType) ?></strong> - Click "<strong>Assign to Type</strong>" on any image below, then enter the paper type name.
        </div>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" class="library-upload-bar mb-16">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="upload_library_image">
        <select name="image_category" id="lib-cat-select" required>
          <?php foreach ($libraryCategories as $k => $label): ?>
            <option value="<?= e($k) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="paper_type_tag" id="paper-type-tag-input" class="form-control" placeholder="Paper type name (e.g. Thermal Paper)" style="display:none;max-width:220px">
        <input type="file" name="library_image" accept="image/png,image/jpeg,image/webp,image/gif" required>
        <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Upload</button>
      </form>
      <script>
      (function(){
        var sel = document.getElementById('lib-cat-select');
        var inp = document.getElementById('paper-type-tag-input');
        function toggle() {
          var isProductType = sel.value === 'product-type';
          inp.style.display = isProductType ? '' : 'none';
          inp.required = isProductType;
          if (!isProductType) inp.value = '';
        }
        sel.addEventListener('change', toggle);
        toggle();
      })();
      </script>

      <div class="library-category-pills mb-16">
        <a href="?tab=library&category=all" class="library-pill <?= $libraryFilter==='all'?'active':'' ?>">All</a>
        <?php foreach ($libraryCategories as $k => $label): ?>
          <a href="?tab=library&category=<?= e($k) ?>" class="library-pill <?= $libraryFilter===$k?'active':'' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="library-grid library-grid-modern">
        <?php foreach ($filteredLibrary as $idx => $img): ?>
          <div class="library-card library-card-modern">
            <div class="library-chip"><?= e($libraryCategories[$img['category'] ?? 'misc'] ?? 'Misc') ?></div>
            <div class="library-thumb"><img src="<?= e(appUrl((string)$img['path'])) ?>" alt="Library image"></div>
            <div class="library-meta">
              <div class="library-name"><?= e((string)($img['name'] ?? 'image')) ?></div>
              <?php if (($img['category'] ?? '') === 'product-type' && !empty($img['paper_type'])): ?>
                <div style="font-size:.72rem;color:#f97316;font-weight:700;margin-top:2px"><i class="bi bi-tag-fill"></i> <?= e($img['paper_type']) ?></div>
              <?php endif; ?>
              <div class="library-time">Uploaded: <?= e((string)($img['uploaded_at'] ?? '')) ?></div>
            <?php if (($img['category'] ?? '') === 'product-type' || !isset($img['category']) || $img['category'] === 'misc'): ?>
              <button type="button" class="btn btn-primary btn-sm" onclick="openPaperTypeModal(<?= (int)$idx ?>)" style="width:100%;margin-top:8px;margin-bottom:8px"><i class="bi bi-tag"></i> Assign to Type</button>
            <?php endif; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this image?')">
              <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
              <input type="hidden" name="action" value="remove_library_image">
              <input type="hidden" name="image_index" value="<?= (int)$idx ?>">
              <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (empty($filteredLibrary)): ?>
          <div class="empty-state" style="padding:28px 14px">
            <i class="bi bi-images"></i>
            <p>No images found for this category.</p>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($activeTab === 'theme'): ?>
      <form method="POST" class="form-grid-2">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
        <input type="hidden" name="action" value="save_theme">

        <div class="form-group">
          <label>Theme Mode</label>
          <select name="theme_mode">
            <option value="light" <?= ($settings['theme_mode'] ?? 'light')==='light'?'selected':'' ?>>Light Mode</option>
            <option value="dark" <?= ($settings['theme_mode'] ?? 'light')==='dark'?'selected':'' ?>>Dark Mode</option>
          </select>
        </div>

        <div class="form-group">
          <label>Login Background Image</label>
          <select name="login_background_image">
            <option value="">Default Gradient</option>
            <?php foreach ($backgroundOptions as $bg): ?>
              <option value="<?= e((string)$bg['path']) ?>" <?= (($settings['login_background_image'] ?? '') === (string)$bg['path']) ? 'selected' : '' ?>>
                <?= e((string)($bg['name'] ?? 'background')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Sidebar Button Color</label>
          <input type="color" name="sidebar_button_color" value="<?= e(normalizeHexColor($settings['sidebar_button_color'] ?? '#22C55E', '#22C55E')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Hover Color</label>
          <input type="color" name="sidebar_hover_color" value="<?= e(normalizeHexColor($settings['sidebar_hover_color'] ?? '#263445', '#263445')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Active Background</label>
          <input type="color" name="sidebar_active_bg" value="<?= e(normalizeHexColor($settings['sidebar_active_bg'] ?? '#214036', '#214036')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Active Text</label>
          <input type="color" name="sidebar_active_text" value="<?= e(normalizeHexColor($settings['sidebar_active_text'] ?? '#BBF7D0', '#BBF7D0')) ?>">
        </div>

        <div class="form-group">
          <label>Sidebar Collapse Delay (ms)</label>
          <input type="number" name="sidebar_collapse_delay_ms" min="300" max="600000" step="100" value="<?= (int)($settings['sidebar_collapse_delay_ms'] ?? 1000) ?>">
          <small>Time in milliseconds after mouse leaves sidebar before it collapses (300-600000, max 10 minutes)</small>
        </div>

        <div class="form-actions col-span-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-palette"></i> Save Theme</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($activeTab === 'backup'): ?>
      <div class="backup-banner mb-16">
        <i class="bi bi-shield-lock"></i>
        <div>
          <strong>Database Safety Zone</strong>
          <p>Create full SQL backups before restore operations. Restore replaces existing data.</p>
        </div>
      </div>

      <div class="backup-grid">
        <div class="backup-panel">
          <h3><i class="bi bi-download"></i> Backup Database</h3>
          <p>Download full backup package: database SQL + image library + company logo/settings.</p>
          <form method="POST" class="mt-12">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="download_backup">
            <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down"></i> Download Full Backup (.zip)</button>
          </form>
        </div>

        <div class="backup-panel backup-danger">
          <h3><i class="bi bi-upload"></i> Restore Database</h3>
          <p>Restore from full backup (.zip) or SQL (.sql). ZIP restore also restores company/library images.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-12" onsubmit="return confirm('This will restore database data. Continue?');">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="restore_backup">
            <input type="file" name="backup_file" accept=".zip,.sql" required>
            <div class="mt-12">
              <button class="btn btn-danger" type="submit"><i class="bi bi-cloud-arrow-up"></i> Restore from Backup</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card mt-16">
        <div class="card-header"><span class="card-title">System Information</span></div>
        <div class="card-body">
          <table style="font-size:.82rem">
            <tbody>
              <tr><td class="text-muted" style="padding:5px 0">PHP Version</td><td class="fw-600"><?= phpversion() ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">MySQL</td><td class="fw-600"><?= $db->server_info ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">Database Name</td><td class="fw-600"><?= e(DB_NAME) ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">App Version</td><td class="fw-600"><?= e(APP_VERSION) ?></td></tr>
              <tr><td class="text-muted" style="padding:5px 0">Server Time</td><td class="fw-600"><?= date('d M Y H:i') ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($activeTab === 'update'): ?>
      <?php
        // Load update log
        $updateLogFile = realpath(__DIR__ . '/../../') . '/data/update_log.json';
        $updateLog = [];
        if (is_file($updateLogFile)) {
          $raw = file_get_contents($updateLogFile);
          $updateLog = json_decode($raw, true);
          if (!is_array($updateLog)) $updateLog = [];
        }
        $lastUpdate = !empty($updateLog) ? end($updateLog) : null;
      ?>

      <!-- Version Banner -->
      <div class="upd-version-banner">
        <div class="upd-version-main">
          <div class="upd-version-icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <span class="upd-version-label">Current Version</span>
            <span class="upd-version-number"><?= e(APP_VERSION) ?></span>
          </div>
        </div>
        <div class="upd-version-meta">
          <?php if ($lastUpdate): ?>
            <span><i class="bi bi-clock-history"></i> Last updated: <?= e(date('d M Y, H:i', strtotime($lastUpdate['timestamp'] ?? ''))) ?></span>
            <span><i class="bi bi-person"></i> By: <?= e($lastUpdate['applied_by'] ?? 'admin') ?></span>
          <?php else: ?>
            <span><i class="bi bi-info-circle"></i> No updates applied yet</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="backup-grid mt-16">
        <!-- Upload Update Panel -->
        <div class="backup-panel">
          <h3><i class="bi bi-cloud-arrow-up"></i> Apply Update</h3>
          <p>Upload an update package (.zip) created with the build tool. A backup is created automatically before applying.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-12" onsubmit="return confirm('Apply this update? A backup will be created automatically before changes are applied.');">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="apply_update">
            <div class="upd-upload-zone" id="upd-drop-zone">
              <input type="file" name="update_file" id="upd-file-input" accept=".zip" required>
              <div class="upd-upload-placeholder" id="upd-placeholder">
                <i class="bi bi-file-earmark-zip" style="font-size:28px;color:#3b82f6"></i>
                <span>Choose update ZIP or drag &amp; drop</span>
                <small>Max 50 MB &middot; .zip only</small>
              </div>
              <div class="upd-upload-selected" id="upd-selected" style="display:none">
                <i class="bi bi-file-earmark-check" style="font-size:24px;color:#16a34a"></i>
                <span id="upd-file-name"></span>
                <small id="upd-file-size"></small>
              </div>
            </div>
            <div class="mt-12">
              <button class="btn btn-primary" type="submit"><i class="bi bi-rocket-takeoff"></i> Apply Update</button>
            </div>
          </form>
        </div>

        <!-- Package Builder Info -->
        <div class="backup-panel" style="background:linear-gradient(135deg,#f8fafc 0%,#eef6ff 100%)">
          <h3><i class="bi bi-tools"></i> Build Update Package</h3>
          <p>Use the CLI tool to create update packages from your git changes.</p>
          <div class="upd-code-block mt-12">
            <code>cd <?= e(realpath(__DIR__ . '/../../') ?: __DIR__ . '/../../') ?></code>
            <code>php build_update.php</code>
          </div>
          <div class="upd-steps mt-12">
            <div class="upd-step"><span class="upd-step-num">1</span><span>Make changes in VS Code &amp; commit to Git</span></div>
            <div class="upd-step"><span class="upd-step-num">2</span><span>Run <strong>php build_update.php</strong> in terminal</span></div>
            <div class="upd-step"><span class="upd-step-num">3</span><span>Choose version &amp; select changed files</span></div>
            <div class="upd-step"><span class="upd-step-num">4</span><span>Upload the generated ZIP here</span></div>
          </div>
          <div class="mt-12" style="font-size:.82rem;color:#64748b">
            <strong>Package format:</strong> manifest.json + files/ + migrations/<br>
            <strong>SQL migrations:</strong> Place .sql files in <code>pending_migrations/</code> before building
          </div>
        </div>
      </div>

      <!-- Update History -->
      <div class="card mt-16">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
          <span class="card-title"><i class="bi bi-clock-history"></i> Update History</span>
          <span class="badge badge-draft"><?= count($updateLog) ?> update(s)</span>
        </div>
        <div class="card-body" style="overflow-x:auto">
          <?php if (empty($updateLog)): ?>
            <div class="table-empty"><i class="bi bi-inbox"></i>No updates have been applied yet.</div>
          <?php else: ?>
            <table style="font-size:.82rem;width:100%">
              <thead>
                <tr>
                  <th style="padding:8px 6px">#</th>
                  <th style="padding:8px 6px">Version</th>
                  <th style="padding:8px 6px">Description</th>
                  <th style="padding:8px 6px">Date</th>
                  <th style="padding:8px 6px">Files</th>
                  <th style="padding:8px 6px">Migrations</th>
                  <th style="padding:8px 6px">Applied By</th>
                  <th style="padding:8px 6px">Status</th>
                  <th style="padding:8px 6px">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($updateLog, true) as $idx => $entry): ?>
                  <tr>
                    <td style="padding:6px"><?= (int)$idx + 1 ?></td>
                    <td style="padding:6px">
                      <strong>v<?= e($entry['version'] ?? '') ?></strong>
                      <br><small class="text-muted">from v<?= e($entry['from_version'] ?? '') ?></small>
                    </td>
                    <td style="padding:6px"><?= e($entry['description'] ?? '—') ?></td>
                    <td style="padding:6px;white-space:nowrap"><?= e(date('d M Y H:i', strtotime($entry['timestamp'] ?? ''))) ?></td>
                    <td style="padding:6px;text-align:center"><?= (int)($entry['files_count'] ?? 0) ?></td>
                    <td style="padding:6px;text-align:center">
                      <?= (int)($entry['migrations_run'] ?? 0) ?>
                      <?php if (($entry['migrations_failed'] ?? 0) > 0): ?>
                        <span class="badge badge-cancelled"><?= (int)$entry['migrations_failed'] ?> failed</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px"><?= e($entry['applied_by'] ?? 'admin') ?></td>
                    <td style="padding:6px">
                      <?php if (!empty($entry['rolled_back'])): ?>
                        <span class="badge badge-cancelled">Rolled Back</span>
                      <?php else: ?>
                        <span class="badge badge-available">Applied</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px">
                      <?php if (empty($entry['rolled_back']) && !empty($entry['backup_path'])): ?>
                        <form method="POST" style="margin:0" onsubmit="return confirm('Rollback this update? This will restore files and database to the pre-update state.');">
                          <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
                          <input type="hidden" name="action" value="rollback_update">
                          <input type="hidden" name="rollback_index" value="<?= (int)$idx ?>">
                          <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Rollback</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <style>
      .upd-version-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 22px;
        border-radius: 16px;
        color: #fff;
        background: linear-gradient(135deg, #0f172a 0%, #1e40af 55%, #0ea5e9 100%);
        box-shadow: 0 12px 28px rgba(29, 78, 216, .2);
      }
      .upd-version-main { display: flex; align-items: center; gap: 14px; }
      .upd-version-icon {
        width: 48px; height: 48px;
        display: grid; place-items: center;
        background: rgba(255,255,255,.15);
        border-radius: 12px;
        font-size: 22px;
        backdrop-filter: blur(6px);
      }
      .upd-version-label {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .1em;
        opacity: .8;
      }
      .upd-version-number {
        display: block;
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -.02em;
        margin-top: 2px;
      }
      .upd-version-meta {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: .82rem;
        opacity: .85;
        text-align: right;
      }
      .upd-upload-zone {
        position: relative;
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        transition: .18s ease;
        background: #fafbfc;
        cursor: pointer;
      }
      .upd-upload-zone.drag-over {
        border-color: #3b82f6;
        background: #eff6ff;
      }
      .upd-upload-zone input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
      }
      .upd-upload-placeholder,
      .upd-upload-selected {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        pointer-events: none;
      }
      .upd-upload-placeholder span,
      .upd-upload-selected span { font-weight: 600; color: #334155; font-size: .92rem; }
      .upd-upload-placeholder small,
      .upd-upload-selected small { color: #94a3b8; font-size: .78rem; }
      .upd-code-block {
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: .82rem;
        line-height: 1.7;
      }
      .upd-code-block code {
        display: block;
        color: #67e8f9;
      }
      .upd-code-block code::before {
        content: '> ';
        color: #64748b;
      }
      .upd-steps { display: grid; gap: 10px; }
      .upd-step {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: .85rem;
        color: #334155;
      }
      .upd-step-num {
        width: 24px; height: 24px;
        display: grid; place-items: center;
        background: #dbeafe;
        color: #1d4ed8;
        border-radius: 50%;
        font-size: .72rem;
        font-weight: 800;
        flex-shrink: 0;
      }
      @media (max-width: 720px) {
        .upd-version-banner { flex-direction: column; align-items: flex-start; }
        .upd-version-meta { text-align: left; }
      }
      </style>

      <script>
      (function(){
        var fileInput = document.getElementById('upd-file-input');
        var placeholder = document.getElementById('upd-placeholder');
        var selected = document.getElementById('upd-selected');
        var fileNameEl = document.getElementById('upd-file-name');
        var fileSizeEl = document.getElementById('upd-file-size');
        var dropZone = document.getElementById('upd-drop-zone');

        if (fileInput) {
          fileInput.addEventListener('change', function(){
            if (fileInput.files && fileInput.files[0]) {
              var f = fileInput.files[0];
              placeholder.style.display = 'none';
              selected.style.display = '';
              fileNameEl.textContent = f.name;
              fileSizeEl.textContent = (f.size / 1024 / 1024).toFixed(2) + ' MB';
            } else {
              placeholder.style.display = '';
              selected.style.display = 'none';
            }
          });
        }

        if (dropZone) {
          ['dragenter','dragover'].forEach(function(ev){
            dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.add('drag-over'); });
          });
          ['dragleave','drop'].forEach(function(ev){
            dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.remove('drag-over'); });
          });
          dropZone.addEventListener('drop', function(e){
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
              fileInput.files = e.dataTransfer.files;
              fileInput.dispatchEvent(new Event('change'));
            }
          });
        }
      })();
      </script>
    <?php endif; ?>
  </div>
</div>

<!-- Paper Type Assignment Modal -->
<div id="paperTypeModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;padding:24px;width:90%;max-width:400px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1)">
    <h3 style="margin:0 0 16px;font-size:1.1rem">Assign to Paper Type</h3>
    <form method="POST" id="assignPaperTypeForm">
      <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
      <input type="hidden" name="action" value="assign_image_to_papertype">
      <input type="hidden" name="image_index" id="modalImageIndex" value="">
      
      <div style="margin-bottom:16px">
        <label style="display:block;font-weight:600;margin-bottom:8px;color:#334155">Paper Type Name</label>
        <input type="text" name="paper_type" id="modalPaperType" placeholder="e.g., Thermal Paper, Chromo Matt" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:.95rem" required>
      </div>
      
      <div style="display:flex;gap:12px;justify-content:space-between">
        <button type="button" onclick="closePaperTypeModal()" style="flex:1;padding:10px;border:1px solid #e2e8f0;background:#fff;border-radius:6px;cursor:pointer;font-weight:600">Cancel</button>
        <button type="submit" style="flex:1;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:600">Assign</button>
      </div>
    </form>
  </div>
</div>

<script>
let currentImageIndex = null;

function openPaperTypeModal(imageIndex) {
  currentImageIndex = imageIndex;
  document.getElementById('modalImageIndex').value = imageIndex;
  document.getElementById('modalPaperType').value = '<?= e($targetPaperType) ?>';
  document.getElementById('paperTypeModal').style.display = 'flex';
  document.getElementById('modalPaperType').focus();
}

function closePaperTypeModal() {
  document.getElementById('paperTypeModal').style.display = 'none';
  currentImageIndex = null;
}

// Close modal when clicking outside
document.getElementById('paperTypeModal')?.addEventListener('click', function(e) {
  if (e.target === this) closePaperTypeModal();
});

// Handle form submission
document.getElementById('assignPaperTypeForm')?.addEventListener('submit', function(e) {
  const paperType = document.getElementById('modalPaperType').value.trim();
  if (!paperType) {
    e.preventDefault();
    alert('Please enter a paper type name');
    return;
  }
  // Form will submit naturally
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>