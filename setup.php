<?php
// ============================================================
// Shree Label ERP — One-click Database Setup
// ✅ Run this ONCE to create the database and seed admin user.
// ✅ Delete or rename this file after successful setup.
// ============================================================

// Load only the core config (DB connection with no DB selected yet)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'shree_label_erp');
define('BASE_URL', '/calipot-erp/shree-label-php');
define('APP_NAME', 'Shree Label ERP');
define('APP_VERSION', '1.0');

$log    = [];
$errors = [];

// Avoid fatal mysqli_sql_exception during setup; collect errors in setup log instead.
mysqli_report(MYSQLI_REPORT_OFF);

function logOK($msg)  { global $log; $log[] = ['ok',  $msg]; }
function logErr($msg) { global $log, $errors; $log[] = ['err', $msg]; $errors[] = $msg; }

// Step 1: Connect without selecting a database
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    logErr('Cannot connect to MySQL: ' . $conn->connect_error);
    goto render;
}
$conn->set_charset('utf8mb4');
logOK('Connected to MySQL server.');

// Step 2: Create database
if ($conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    logOK('Database `' . DB_NAME . '` created (or already exists).');
} else {
    logErr('Failed to create database: ' . $conn->error);
    goto render;
}

// Step 3: Select database
$conn->select_db(DB_NAME);

// Step 4: Execute schema
$schemaFile = __DIR__ . '/database/schema.sql';
if (!file_exists($schemaFile)) {
    logErr('schema.sql not found at: ' . $schemaFile);
    goto render;
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    logErr('Unable to read schema.sql');
    goto render;
}

// Remove SQL comments before splitting by ';' so CREATE TABLE statements are not skipped.
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);           // block comments
$sql = preg_replace('/^\s*--.*$/m', '', $sql);               // -- comments
$sql = preg_replace('/^\s*#.*$/m', '', $sql);                // # comments

$stmts = array_filter(array_map('trim', explode(';', $sql)));
$schemaErrors = 0;
foreach ($stmts as $stmt) {
    if ($stmt === '') continue;
    if (!$conn->query($stmt)) {
        $schemaErrors++;
        logErr('SQL Error: ' . $conn->error . ' — ' . substr($stmt, 0, 140));
    }
}

if ($schemaErrors === 0) {
    logOK('Schema tables created successfully.');
} else {
    logErr('Schema completed with ' . $schemaErrors . ' SQL error(s).');
}

// Step 5: Seed admin user
$adminEmail = 'admin@shreelabel.com';
$adminPass  = password_hash('admin123', PASSWORD_BCRYPT);
$adminName  = 'System Admin';
$adminRole  = 'admin';

$check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->bind_param('s', $adminEmail);
$check->execute();
$exists = $check->get_result()->fetch_assoc();

if (!$exists) {
    $ins = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $ins->bind_param('ssss', $adminName, $adminEmail, $adminPass, $adminRole);
    if ($ins->execute()) {
        logOK('Admin user created: ' . $adminEmail . ' / admin123');
    } else {
        logErr('Failed to create admin user: ' . $ins->error);
    }
} else {
    logOK('Admin user already exists — skipped.');
}

logOK('Setup complete! You can now <a href="' . BASE_URL . '/auth/login.php">login</a>.');

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body style="background:#f5f6f8;padding:20px">
<div class="setup-wrap">
  <div class="setup-card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
      <i class="bi bi-database-check" style="font-size:1.8rem;color:#16a34a"></i>
      <h1><?= APP_NAME ?> — Setup</h1>
    </div>
    <p>Initialising database and seeding default data…</p>

    <div class="setup-log">
      <?php foreach ($log as [$type, $msg]): ?>
      <div class="<?= $type ?>">
        <?= $type === 'ok' ? '✔' : '✘' ?> <?= $msg ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($errors)): ?>
    <div class="alert alert-success" style="margin-top:0">
      <span><i class="bi bi-check-circle"></i> &nbsp;Setup completed successfully.</span>
    </div>
    <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary btn-full">
      <i class="bi bi-box-arrow-in-right"></i> Go to Login
    </a>
    <p style="margin-top:14px;font-size:.78rem;color:#6b7280;text-align:center">
      ⚠ For security, please delete <strong>setup.php</strong> after setup.
    </p>
    <?php else: ?>
    <div class="alert alert-danger" style="margin-top:0">
      <span><i class="bi bi-exclamation-triangle"></i> &nbsp;Setup encountered errors. Check the log above.</span>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
