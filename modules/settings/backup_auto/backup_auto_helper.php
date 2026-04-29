<?php

function auto_backup_app_root() {
  return realpath(__DIR__ . '/../../..');
}

function auto_backup_dir() {
  $root = auto_backup_app_root();
  return $root ? ($root . '/data/auto_backups') : '';
}

function auto_backup_config_file() {
  $backupDir = auto_backup_dir();
  return $backupDir === '' ? '' : ($backupDir . '/config.json');
}

function auto_backup_default_settings() {
  return [
    'enabled' => true,
    'frequency' => 'daily',
    'backup_time' => '02:00',
    'storage' => 'local',
    'remote' => '',
    'folder' => '',
    'saved_at' => date('Y-m-d H:i:s'),
  ];
}

function auto_backup_clean_enabled($enabled) {
  if (is_bool($enabled)) {
    return $enabled;
  }
  return in_array((string)$enabled, ['1', 'true', 'on', 'yes'], true);
}

function auto_backup_clean_frequency($frequency) {
  $frequency = strtolower(trim((string)$frequency));
  if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
    return 'daily';
  }
  return $frequency;
}

function auto_backup_clean_backup_time($time) {
  $time = trim((string)$time);
  return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:00';
}

function auto_backup_clean_storage_type($storage) {
  return ((string)$storage === 'gdrive') ? 'gdrive' : 'local';
}

function auto_backup_clean_remote_name($remote) {
  $remote = trim((string)$remote);
  return preg_match('/^[a-zA-Z0-9._-]{2,32}$/', $remote) ? $remote : '';
}

function auto_backup_clean_folder_path($folder) {
  $folder = trim((string)$folder);
  if ($folder === '') {
    return '';
  }
  if (!preg_match('/^[a-zA-Z0-9._\/\s-]{1,128}$/', $folder)) {
    return '';
  }
  return trim($folder, '/');
}

function auto_backup_get_settings() {
  $settings = auto_backup_default_settings();
  $cfgFile = auto_backup_config_file();

  if ($cfgFile !== '' && is_file($cfgFile)) {
    $raw = @file_get_contents($cfgFile);
    $arr = json_decode((string)$raw, true);
    if (is_array($arr)) {
      $settings['enabled'] = auto_backup_clean_enabled($arr['enabled'] ?? true);
      $settings['frequency'] = auto_backup_clean_frequency($arr['frequency'] ?? 'daily');
      $settings['backup_time'] = auto_backup_clean_backup_time($arr['backup_time'] ?? '02:00');
      $settings['storage'] = auto_backup_clean_storage_type($arr['storage'] ?? 'local');
      $settings['remote'] = auto_backup_clean_remote_name($arr['remote'] ?? '');
      $settings['folder'] = auto_backup_clean_folder_path($arr['folder'] ?? '');
      $settings['saved_at'] = (string)($arr['saved_at'] ?? $settings['saved_at']);
    }
  }

  return $settings;
}

function auto_backup_save_settings($data) {
  $backupDir = auto_backup_dir();
  $cfgFile = auto_backup_config_file();
  if ($backupDir === '' || $cfgFile === '') {
    return false;
  }

  if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
    return false;
  }

  $current = auto_backup_get_settings();
  if (!is_array($data)) {
    $data = [];
  }

  $current['enabled'] = array_key_exists('enabled', $data) ? auto_backup_clean_enabled($data['enabled']) : $current['enabled'];
  $current['frequency'] = array_key_exists('frequency', $data) ? auto_backup_clean_frequency($data['frequency']) : $current['frequency'];
  $current['backup_time'] = array_key_exists('backup_time', $data) ? auto_backup_clean_backup_time($data['backup_time']) : $current['backup_time'];
  $current['storage'] = array_key_exists('storage', $data) ? auto_backup_clean_storage_type($data['storage']) : $current['storage'];
  $current['remote'] = array_key_exists('remote', $data) ? auto_backup_clean_remote_name($data['remote']) : $current['remote'];
  $current['folder'] = array_key_exists('folder', $data) ? auto_backup_clean_folder_path($data['folder']) : $current['folder'];
  $current['saved_at'] = date('Y-m-d H:i:s');

  if (@file_put_contents($cfgFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    return false;
  }

  return $current;
}

function auto_backup_get_storage_config() {
  $settings = auto_backup_get_settings();
  $config = [
    'storage' => $settings['storage'],
    'remote' => $settings['remote'],
    'folder' => $settings['folder'],
  ];

  // Allow request values to override saved config for current run.
  $reqStorage = $_POST['storage'] ?? $_POST['auto_backup_storage'] ?? null;
  $reqRemote = $_POST['remote'] ?? $_POST['auto_backup_rclone_remote'] ?? null;
  $reqFolder = $_POST['folder'] ?? $_POST['auto_backup_rclone_folder'] ?? null;

  if ($reqStorage !== null) {
    $config['storage'] = auto_backup_clean_storage_type($reqStorage);
  }
  if ($reqRemote !== null) {
    $clean = auto_backup_clean_remote_name($reqRemote);
    if ($clean !== '') {
      $config['remote'] = $clean;
    }
  }
  if ($reqFolder !== null) {
    $clean = auto_backup_clean_folder_path($reqFolder);
    if ($clean !== '' || trim((string)$reqFolder) === '') {
      $config['folder'] = $clean;
    }
  }

  return $config;
}

function auto_backup_save_storage_config($storage, $remote, $folder) {
  $data = auto_backup_save_settings([
    'storage' => auto_backup_clean_storage_type($storage),
    'remote' => auto_backup_clean_remote_name($remote),
    'folder' => auto_backup_clean_folder_path($folder),
  ]);

  return is_array($data);
}

function auto_backup_rclone_remote_exists($remote) {
  $remote = auto_backup_clean_remote_name($remote);
  if ($remote === '') {
    return false;
  }

  $output = [];
  $code = 1;
  @exec('rclone listremotes 2>&1', $output, $code);
  if ($code !== 0 || !is_array($output)) {
    return false;
  }

  $needle = $remote . ':';
  foreach ($output as $line) {
    if (trim((string)$line) === $needle) {
      return true;
    }
  }
  return false;
}

function auto_backup_upload_to_gdrive($localFile, $remote, $folder) {
  $result = [
    'success' => false,
    'error' => 'Upload failed, saved locally',
  ];

  $localFile = (string)$localFile;
  if (!is_file($localFile)) {
    $result['error'] = 'Upload failed, saved locally';
    return $result;
  }

  $remote = auto_backup_clean_remote_name($remote);
  $folder = auto_backup_clean_folder_path($folder);
  if ($remote === '') {
    $result['error'] = 'Upload failed, saved locally';
    return $result;
  }

  if (!auto_backup_rclone_remote_exists($remote)) {
    $result['error'] = 'Upload failed, saved locally';
    return $result;
  }

  $target = $remote . ':' . $folder;
  $cmd = 'rclone copy ' . escapeshellarg($localFile) . ' ' . escapeshellarg($target) . ' 2>&1';
  $output = [];
  $code = 1;
  @exec($cmd, $output, $code);

  if ($code === 0) {
    $result['success'] = true;
    $result['error'] = '';
    $result['message'] = 'Backup uploaded to Google Drive';
    return $result;
  }

  $result['error'] = 'Upload failed, saved locally';
  return $result;
}

function auto_backup_test_rclone_conn($remote, $folder) {
  $out = [ 'success' => false ];

  if (!preg_match('/^[a-zA-Z0-9._-]{2,32}$/', (string)$remote)) {
    $out['error'] = 'Invalid remote name.';
    return $out;
  }

  if ($folder !== '' && !preg_match('/^[a-zA-Z0-9._\/\s-]{0,128}$/', (string)$folder)) {
    $out['error'] = 'Invalid folder path.';
    return $out;
  }

  $target = $remote . ':' . $folder;
  $cmd = 'rclone lsd ' . escapeshellarg($target) . ' 2>&1';
  $output = [];
  $code = 1;
  @exec($cmd, $output, $code);

  if ($code === 0) {
    auto_backup_save_storage_config('gdrive', $remote, $folder);
    $out['success'] = true;
    return $out;
  }

  $out['error'] = 'Could not connect to Google Drive. Check remote name and folder.';
  return $out;
}

function auto_backup_rclone_status() {
  $output = [];
  $code = 1;
  @exec('rclone --version 2>&1', $output, $code);

  if (is_array($output) && count($output) > 0 && stripos((string)$output[0], 'rclone') !== false) {
    return [
      'installed' => true,
      'message' => 'Rclone is installed.',
      'version' => (string)$output[0],
    ];
  }

  return [
    'installed' => false,
    'message' => 'Rclone is not installed or not in PATH.',
    'version' => null,
  ];
}

function auto_backup_resolve_file($file) {
  $backupDir = auto_backup_dir();
  if ($backupDir === '') {
    return false;
  }

  $file = basename((string)$file);
  if (strpos($file, 'backup_') !== 0 || substr($file, -4) !== '.zip') {
    return false;
  }

  $path = $backupDir . '/' . $file;
  if (!is_file($path)) {
    return false;
  }

  return $path;
}

function auto_backup_user_can_delete() {
  return isset($_SESSION['user_id']) || isset($_SESSION['username']);
}

function auto_backup_environment_error() {
  if (!class_exists('mysqli')) {
    return 'PHP mysqli extension is not enabled on this server.';
  }
  if (!class_exists('ZipArchive')) {
    return 'PHP ZipArchive extension is not enabled on this server.';
  }
  return '';
}

function auto_backup_run_local() {
  $response = [ 'success' => false ];
  $now = date('Y-m-d_H-i-s');

  $envError = auto_backup_environment_error();
  if ($envError !== '') {
    $response['error'] = $envError;
    return $response;
  }

  $root = auto_backup_app_root();
  if (!$root) {
    $response['error'] = 'Project root not found.';
    return $response;
  }

  $backupDir = auto_backup_dir();
  if ($backupDir === '') {
    $response['error'] = 'Backup directory path failed.';
    return $response;
  }

  if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
    $response['error'] = 'Could not create backup directory.';
    return $response;
  }

  require_once $root . '/config/db.php';
  $dbPort = defined('DB_PORT') ? DB_PORT : 3306;
  $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)$dbPort);
  if ($mysqli->connect_error) {
    $response['error'] = 'Database connection failed.';
    return $response;
  }

  $sql = auto_backup_generate_sql($mysqli);
  if ($sql === '') {
    $response['error'] = 'Failed to generate database dump.';
    return $response;
  }

  $sqlFile = $backupDir . '/backup_' . $now . '.sql';
  if (@file_put_contents($sqlFile, $sql) === false) {
    $response['error'] = 'Could not write SQL file.';
    return $response;
  }

  $zipFile = $backupDir . '/backup_' . $now . '.zip';
  $zip = new ZipArchive();
  if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $response['error'] = 'Could not create ZIP file.';
    return $response;
  }
  $zip->addFile($sqlFile, 'database.sql');
  $zip->close();

  @unlink($sqlFile);

  $storage = auto_backup_get_storage_config();
  $storageType = $storage['storage'] ?? 'local';
  $uploadInfo = [
    'success' => false,
    'error' => '',
    'message' => '',
  ];

  if ($storageType === 'gdrive') {
    $uploadInfo = auto_backup_upload_to_gdrive($zipFile, $storage['remote'] ?? '', $storage['folder'] ?? '');
  }

  $historyStatus = 'Success';
  $historyLocation = 'Local';
  $uploadStatus = 'Not Requested';
  if ($storageType === 'gdrive' && $uploadInfo['success']) {
    $historyLocation = 'Google Drive';
    $uploadStatus = 'Success';
  } elseif ($storageType === 'gdrive') {
    $historyStatus = 'Saved Locally (Drive Failed)';
    $historyLocation = 'Local';
    $uploadStatus = 'Failed';
  }

  $entry = [
    'date' => date('Y-m-d H:i:s'),
    'file' => basename($zipFile),
    'location' => $historyLocation,
    'status' => $historyStatus,
    'storage_type' => ($storageType === 'gdrive') ? 'Google Drive' : 'Local',
    'upload_status' => $uploadStatus,
    'size' => is_file($zipFile) ? filesize($zipFile) : 0,
  ];

  $history = auto_backup_get_history();
  $history[] = $entry;
  auto_backup_save_history($history);

  $response['success'] = $storageType !== 'gdrive' || !empty($uploadInfo['success']);
  $response['file'] = $entry['file'];
  $response['size'] = $entry['size'];
  $response['date'] = $entry['date'];
  $response['location'] = $entry['location'];
  $response['storage_type'] = $entry['storage_type'];
  $response['upload_status'] = $entry['upload_status'];

  if ($storageType === 'gdrive' && !empty($uploadInfo['success'])) {
    $response['message'] = 'Backup uploaded to Google Drive';
    $response['file'] = 'Backup uploaded to Google Drive';
  } elseif ($storageType === 'gdrive') {
    $response['error'] = 'Upload failed, saved locally';
  }

  return $response;
}

function auto_backup_generate_sql($db) {
  $out = [];
  $out[] = '-- Auto Backup SQL Dump';
  $out[] = '-- Generated: ' . date('Y-m-d H:i:s');
  $out[] = '';

  $tablesRes = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
  if (!$tablesRes) {
    return '';
  }

  while ($row = $tablesRes->fetch_row()) {
    $table = (string)$row[0];
    $safeTable = '`' . str_replace('`', '``', $table) . '`';

    $createRes = $db->query('SHOW CREATE TABLE ' . $safeTable);
    if (!$createRes) {
      continue;
    }

    $createRow = $createRes->fetch_assoc();
    $createStmt = (string)($createRow['Create Table'] ?? '');
    if ($createStmt === '') {
      continue;
    }

    $out[] = '';
    $out[] = '-- Structure for table ' . $table;
    $out[] = 'DROP TABLE IF EXISTS ' . $safeTable . ';';
    $out[] = $createStmt . ';';

    $dataRes = $db->query('SELECT * FROM ' . $safeTable);
    if ($dataRes && $dataRes->num_rows > 0) {
      $out[] = '-- Data for table ' . $table;
      while ($dataRow = $dataRes->fetch_assoc()) {
        $columns = [];
        $values = [];
        foreach ($dataRow as $col => $val) {
          $columns[] = '`' . str_replace('`', '``', (string)$col) . '`';
          $values[] = ($val === null) ? 'NULL' : "'" . $db->real_escape_string((string)$val) . "'";
        }
        $out[] = 'INSERT INTO ' . $safeTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
      }
    }
  }

  return implode("\n", $out);
}

function auto_backup_get_history() {
  $backupDir = auto_backup_dir();
  if ($backupDir === '') {
    return [];
  }

  $file = $backupDir . '/history.json';
  if (!is_file($file)) {
    return [];
  }

  $raw = @file_get_contents($file);
  $arr = json_decode((string)$raw, true);
  return is_array($arr) ? $arr : [];
}

function auto_backup_save_history($arr) {
  $backupDir = auto_backup_dir();
  if ($backupDir === '') {
    return;
  }

  if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
  }

  $file = $backupDir . '/history.json';
  @file_put_contents($file, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function auto_backup_cron_command() {
  return 'php /path/to/backup_auto_helper.php --run-scheduled';
}

