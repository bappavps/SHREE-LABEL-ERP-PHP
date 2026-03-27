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
$allowedTabs = ['company', 'library', 'theme', 'backup'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'company';

$libraryFilter = $_GET['category'] ?? 'all';
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

  if ($action === 'save_theme') {
    $settings['theme_mode'] = (($_POST['theme_mode'] ?? 'light') === 'dark') ? 'dark' : 'light';
    $settings['sidebar_button_color'] = normalizeHexColor($_POST['sidebar_button_color'] ?? '', '#22C55E');
    $settings['sidebar_hover_color'] = normalizeHexColor($_POST['sidebar_hover_color'] ?? '', '#263445');
    $settings['sidebar_active_bg'] = normalizeHexColor($_POST['sidebar_active_bg'] ?? '', '#214036');
    $settings['sidebar_active_text'] = normalizeHexColor($_POST['sidebar_active_text'] ?? '', '#BBF7D0');
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

    $fileName = DB_NAME . '_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($backupSql));
    echo $backupSql;
    exit;
  }

  if ($action === 'restore_backup') {
    if (!isset($db) || !($db instanceof mysqli)) {
      $db = getDB();
    }
    if (empty($_FILES['backup_sql']) || !is_array($_FILES['backup_sql'])) {
      setFlash('error', 'Please choose a SQL backup file to restore.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $file = $_FILES['backup_sql'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      setFlash('error', 'Backup upload failed. Please try again.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }
    if (($file['size'] ?? 0) > 30 * 1024 * 1024) {
      setFlash('error', 'Backup file is too large. Maximum allowed size is 30MB.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $name = strtolower((string)($file['name'] ?? ''));
    if (substr($name, -4) !== '.sql') {
      setFlash('error', 'Invalid file format. Please upload a .sql backup file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

    $sqlDump = @file_get_contents((string)$file['tmp_name']);
    if ($sqlDump === false || trim($sqlDump) === '') {
      setFlash('error', 'Could not read uploaded backup file.');
      redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
    }

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

    if ($restoreOk) {
      setFlash('success', 'Database restore completed successfully.');
    } else {
      setFlash('error', 'Restore failed: ' . ($restoreErr ?? 'Unknown SQL error.'));
    }
    redirect(BASE_URL . '/modules/settings/index.php?tab=backup');
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
            <div class="settings-preview mt-8"><img src="<?= e(BASE_URL . '/' . ltrim($settings['logo_path'], '/')) ?>" alt="Current logo"></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label>Animated Flag Image</label>
          <input type="file" name="animated_flag" accept="image/png,image/jpeg,image/webp,image/gif">
          <?php if (!empty($settings['animated_flag_path'])): ?>
            <div class="settings-preview mt-8"><img src="<?= e(BASE_URL . '/' . ltrim($settings['animated_flag_path'], '/')) ?>" alt="Current flag"></div>
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
            <div class="library-thumb"><img src="<?= e(BASE_URL . '/' . ltrim((string)$img['path'], '/')) ?>" alt="Library image"></div>
            <div class="library-meta">
              <div class="library-name"><?= e((string)($img['name'] ?? 'image')) ?></div>
              <?php if (($img['category'] ?? '') === 'product-type' && !empty($img['paper_type'])): ?>
                <div style="font-size:.72rem;color:#f97316;font-weight:700;margin-top:2px"><i class="bi bi-tag-fill"></i> <?= e($img['paper_type']) ?></div>
              <?php endif; ?>
              <div class="library-time">Uploaded: <?= e((string)($img['uploaded_at'] ?? '')) ?></div>
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
          <p>Download a complete SQL snapshot of your current ERP database.</p>
          <form method="POST" class="mt-12">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="download_backup">
            <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down"></i> Download Backup (.sql)</button>
          </form>
        </div>

        <div class="backup-panel backup-danger">
          <h3><i class="bi bi-upload"></i> Restore Database</h3>
          <p>Restore from a SQL file. This can overwrite current records and structure.</p>
          <form method="POST" enctype="multipart/form-data" class="mt-12" onsubmit="return confirm('This will restore database data. Continue?');">
            <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
            <input type="hidden" name="action" value="restore_backup">
            <input type="file" name="backup_sql" accept=".sql" required>
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
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>