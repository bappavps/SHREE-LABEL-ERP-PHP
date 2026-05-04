<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$lockFile = __DIR__ . '/data/install.lock';
$schemaFile = __DIR__ . '/database/schema.sql';
$runtimeConfigFile = __DIR__ . '/config/db.runtime.php';
$migrationDir = __DIR__ . '/pending_migrations';
$updateLogFile = __DIR__ . '/data/update_log.json';
$configFile = __DIR__ . '/config/db.php';

$log = [];
$errors = [];

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function log_ok(string $msg): void {
    global $log;
    $log[] = ['ok', $msg];
}

function log_err(string $msg): void {
    global $log, $errors;
    $log[] = ['err', $msg];
    $errors[] = $msg;
}

function is_local_request(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    return $host === ''
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || substr($host, -6) === '.local'
        || $serverAddr === '127.0.0.1'
        || $serverAddr === '::1'
        || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)
        || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $serverAddr);
}

function detect_base_url(): string {
    $documentRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $appRoot = str_replace('\\', '/', (string)(realpath(__DIR__) ?: __DIR__));
    if ($documentRoot !== '' && $appRoot !== '') {
        $documentRoot = rtrim($documentRoot, '/');
        if (stripos($appRoot, $documentRoot) === 0) {
            $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
            return $relative === '' ? '' : '/' . $relative;
        }
    }
    return '';
}

function check_writable(string $path): array {
    if (!file_exists($path) && !@mkdir($path, 0755, true)) {
        return [false, 'Missing and could not be created'];
    }
    if (!is_writable($path)) {
        return [false, 'Exists but not writable'];
    }
    return [true, 'OK'];
}

function split_sql_statements(string $sql): array {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*#.*$/m', '', $sql);
    return array_filter(array_map('trim', explode(';', $sql)));
}

function run_sql_file(mysqli $conn, string $filePath, string $label): int {
    if (!file_exists($filePath)) {
        log_err($label . ' missing: ' . basename($filePath));
        return 1;
    }
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        log_err($label . ' cannot be read: ' . basename($filePath));
        return 1;
    }

    $errs = 0;
    foreach (split_sql_statements($sql) as $stmt) {
        if (!$conn->query($stmt)) {
            $errs++;
            log_err($label . ' SQL error: ' . $conn->error . ' -- ' . substr($stmt, 0, 120));
        }
    }

    if ($errs === 0) {
        log_ok($label . ' completed successfully.');
    }
    return $errs;
}

function detect_default_app_version(string $configFile): string {
    if (!file_exists($configFile)) {
        return '1.0';
    }
    $content = file_get_contents($configFile);
    if ($content !== false && preg_match("/'APP_VERSION'\\s*=>\\s*'([^']+)'/", $content, $m)) {
        return trim((string)$m[1]) !== '' ? trim((string)$m[1]) : '1.0';
    }
    return '1.0';
}

function collect_migration_sql_files(string $migrationDir): array {
    if (!is_dir($migrationDir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($migrationDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $fullPath = str_replace('\\', '/', (string)$fileInfo->getPathname());
        if (substr($fullPath, -4) !== '.sql') {
            continue;
        }
        // Keep backup SQL snapshots out of automatic fresh-install execution.
        if (stripos($fullPath, '/pending_migrations/backup/') !== false) {
            continue;
        }
        $files[] = $fullPath;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return $files;
}

function append_setup_log(string $logFile, array $entry): void {
    $entries = [];
    if (file_exists($logFile)) {
        $raw = file_get_contents($logFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }
    }

    $entries[] = $entry;
    file_put_contents($logFile, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$baseUrlDetected = detect_base_url();
$defaultAppVersion = detect_default_app_version($configFile);
$input = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'base_url' => $baseUrlDetected,
    'app_version' => $defaultAppVersion,
    'create_database' => '0',
];

$checks = [
    ['PHP version >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION],
    ['mysqli extension loaded', extension_loaded('mysqli'), extension_loaded('mysqli') ? 'Loaded' : 'Missing'],
    ['json extension loaded', extension_loaded('json'), extension_loaded('json') ? 'Loaded' : 'Missing'],
    ['schema.sql present', file_exists($schemaFile), 'database/schema.sql'],
    ['pending_migrations folder present', is_dir($migrationDir), 'pending_migrations/'],
];

$pendingMigrationFiles = collect_migration_sql_files($migrationDir);
$pendingMigrationCount = count($pendingMigrationFiles);
$pendingMigrationPreview = array_slice(array_map('basename', $pendingMigrationFiles), 0, 5);

$pathChecks = [
    'config'     => check_writable(__DIR__ . '/config'),
    'data'       => check_writable(__DIR__ . '/data'),
    'uploads'    => check_writable(__DIR__ . '/uploads'),
    'plate_data' => check_writable(__DIR__ . '/uploads/library/plate-data'),
];

$blockingCheckFail = false;
foreach ($checks as $c) {
    if (!$c[1]) {
        $blockingCheckFail = true;
    }
}
foreach ($pathChecks as $pc) {
    if (!$pc[0]) {
        $blockingCheckFail = true;
    }
}

if (file_exists($lockFile)) {
    $installedAt = trim((string)@file_get_contents($lockFile));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Installer Locked</title>
      <style>body{font-family:Arial,sans-serif;background:#f8fafc;padding:24px}.card{max-width:780px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}.warn{color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px}</style>
    </head>
    <body>
      <div class="card">
        <h2>Installer is locked</h2>
        <p>This ERP is already installed.</p>
        <p class="warn">Installed at: <?= h($installedAt !== '' ? $installedAt : 'unknown') ?></p>
        <p>Delete <strong>data/install.lock</strong> only for a full reinstall.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['setup_csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['setup_csrf'], $submittedToken)) {
        log_err('Invalid setup request token. Please refresh and try again.');
    }

    foreach ($input as $key => $value) {
        if (isset($_POST[$key])) {
            $input[$key] = trim((string)$_POST[$key]);
        }
    }

    $baseUrl = rtrim($input['base_url'], '/');
    $targetEnv = is_local_request() ? 'local' : 'live';

    if ($blockingCheckFail) {
        log_err('Environment pre-check failed. Fix FAIL items and retry setup.');
    }
    if ($input['db_host'] === '' || $input['db_name'] === '' || $input['db_user'] === '') {
        log_err('DB Host, DB Name, and DB Username are required.');
    }
    $dbPort = (int)$input['db_port'];
    if ($dbPort < 1 || $dbPort > 65535) {
        log_err('DB Port must be between 1 and 65535.');
    }
    if (!preg_match('/^\d+(?:\.\d+){0,3}$/', (string)$input['app_version'])) {
        log_err('Invalid App Version format. Use examples like 1.0 or 1.7.2');
    }

    if (empty($errors) && $input['create_database'] === '1') {
        $serverConn = new mysqli($input['db_host'], $input['db_user'], $input['db_pass'], '', $dbPort);
        if ($serverConn->connect_error) {
            log_err('Cannot connect to DB server for create-database: ' . $serverConn->connect_error);
        } else {
            $q = "CREATE DATABASE IF NOT EXISTS `" . $serverConn->real_escape_string($input['db_name']) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            if ($serverConn->query($q)) {
                log_ok('Database create/exists check completed.');
            } else {
                log_err('Database create failed: ' . $serverConn->error . ' (disable on shared hosting if needed).');
            }
            $serverConn->close();
        }
    }

    if (empty($errors)) {
        $conn = new mysqli($input['db_host'], $input['db_user'], $input['db_pass'], $input['db_name'], $dbPort);
        if ($conn->connect_error) {
            log_err('Cannot connect to MySQL: ' . $conn->connect_error);
        } else {
            $conn->set_charset('utf8mb4');
            log_ok('Connected to target database.');

            $schemaErr = run_sql_file($conn, $schemaFile, 'Schema import');
            $appliedMigrations = [];

            if ($schemaErr === 0) {
                $migrationFiles = collect_migration_sql_files($migrationDir);
                if (empty($migrationFiles)) {
                    log_ok('No pending migration files found.');
                } else {
                    foreach ($migrationFiles as $mf) {
                        run_sql_file($conn, $mf, 'Migration ' . basename($mf));
                        $appliedMigrations[] = basename($mf);
                    }
                }
            }

            if (empty($errors)) {
                $adminName = 'System Admin';
                $adminEmail = 'admin@example.com';
                $adminPass = 'admin123';
                $adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
                $adminRole = 'admin';

                $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                if ($check) {
                    $check->bind_param('s', $adminEmail);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();

                    if ($exists) {
                        $upd = $conn->prepare('UPDATE users SET name = ?, password = ?, role = ?, is_active = 1 WHERE email = ?');
                        if ($upd) {
                            $upd->bind_param('ssss', $adminName, $adminHash, $adminRole, $adminEmail);
                            if ($upd->execute()) {
                                log_ok('Default admin account updated: admin@example.com / admin123');
                            } else {
                                log_err('Default admin update failed: ' . $upd->error);
                            }
                            $upd->close();
                        } else {
                            log_err('Default admin update prepare failed.');
                        }
                    } else {
                        $ins = $conn->prepare('INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
                        if ($ins) {
                            $ins->bind_param('ssss', $adminName, $adminEmail, $adminHash, $adminRole);
                            if ($ins->execute()) {
                                log_ok('Default admin account created: admin@example.com / admin123');
                            } else {
                                log_err('Default admin create failed: ' . $ins->error);
                            }
                            $ins->close();
                        } else {
                            log_err('Default admin insert prepare failed.');
                        }
                    }
                } else {
                    log_err('Default admin check prepare failed.');
                }
            }

            if (empty($errors)) {
                $runtime = [];
                if (file_exists($runtimeConfigFile)) {
                    $loaded = require $runtimeConfigFile;
                    if (is_array($loaded)) {
                        $runtime = $loaded;
                    }
                }

                $runtime[$targetEnv] = [
                    'DB_HOST' => $input['db_host'],
                    'DB_PORT' => $dbPort,
                    'DB_USER' => $input['db_user'],
                    'DB_PASS' => $input['db_pass'],
                    'DB_NAME' => $input['db_name'],
                    'BASE_URL' => $baseUrl,
                    'APP_NAME' => 'Enterprise ERP',
                    'APP_VERSION' => $input['app_version'],
                ];

                $runtimePhp = "<?php\nreturn " . var_export($runtime, true) . ";\n";
                if (file_put_contents($runtimeConfigFile, $runtimePhp, LOCK_EX) === false) {
                    log_err('Failed to write config/db.runtime.php (check permissions).');
                } else {
                    log_ok('Runtime config saved for environment: ' . $targetEnv . '.');
                }
            }

            if (empty($errors)) {
                if (file_put_contents($lockFile, date('c'), LOCK_EX) === false) {
                    log_err('Failed to create install lock file.');
                } else {
                    log_ok('Install lock file created.');
                    append_setup_log($updateLogFile, [
                        'event' => 'fresh_install',
                        'version' => $input['app_version'],
                        'environment' => $targetEnv,
                        'installed_at' => date('c'),
                        'migrations' => $appliedMigrations,
                    ]);
                    log_ok('Installation logged in data/update_log.json.');
                }
            }

            $conn->close();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ERP One-Click Setup</title>
<style>
body{font-family:Arial,sans-serif;background:#f5f6f8;padding:20px;margin:0}.wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}
h1{margin:0 0 6px}.muted{color:#64748b;font-size:.9rem}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}.full{grid-column:1 / -1}
label{font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:4px}input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}
.check{display:flex;gap:8px;align-items:center;padding-top:8px}.check input{width:auto}.btn{margin-top:16px;padding:12px 16px;border:none;border-radius:8px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer}
.log{margin-top:16px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:12px}.ok{color:#166534;margin:6px 0}.err{color:#b91c1c;margin:6px 0}
.next{margin-top:12px;padding:10px;background:#ecfeff;border:1px solid #a5f3fc;border-radius:8px;color:#155e75}
.summary{margin:12px 0 14px;border:1px solid #c7d2fe;background:#eef2ff;border-radius:10px;padding:12px 14px}
.summary-title{font-size:.82rem;font-weight:800;color:#312e81;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.summary-item{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px}
.summary-item .k{font-size:.68rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.summary-item .v{font-size:.95rem;color:#0f172a;font-weight:800;margin-top:3px;word-break:break-all}
.summary-note{font-size:.74rem;color:#475569;margin-top:8px}
table{width:100%;border-collapse:collapse;margin:10px 0 16px}th,td{border:1px solid #e2e8f0;padding:8px;text-align:left;font-size:.86rem}th{background:#f8fafc}
.status-ok{color:#166534;font-weight:700}.status-bad{color:#b91c1c;font-weight:700}
@media (max-width:760px){.summary-grid{grid-template-columns:1fr}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <h1>Enterprise ERP One-Click Setup</h1>
    <p class="muted">Provide database details and version. Setup will auto-check environment, import schema, run all pending migrations, create default admin, save runtime config, and lock installer.</p>

    <div class="summary">
        <div class="summary-title">Setup Snapshot</div>
        <div class="summary-grid">
            <div class="summary-item">
                <div class="k">Default Version</div>
                <div class="v"><?= h($defaultAppVersion) ?></div>
            </div>
            <div class="summary-item">
                <div class="k">Pending Migrations</div>
                <div class="v"><?= (int)$pendingMigrationCount ?></div>
            </div>
            <div class="summary-item">
                <div class="k">Installer Environment</div>
                <div class="v"><?= h(is_local_request() ? 'Local' : 'Live') ?></div>
            </div>
        </div>
        <?php if (!empty($pendingMigrationPreview)): ?>
            <div class="summary-note">Next migrations: <?= h(implode(', ', $pendingMigrationPreview)) ?><?= $pendingMigrationCount > count($pendingMigrationPreview) ? ' ...' : '' ?></div>
        <?php else: ?>
            <div class="summary-note">No pending migration files detected.</div>
        <?php endif; ?>
    </div>

  <table>
    <thead><tr><th>Environment Check</th><th>Status</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr><td><?= h($c[0]) ?></td><td class="<?= $c[1] ? 'status-ok' : 'status-bad' ?>"><?= $c[1] ? 'PASS' : 'FAIL' ?></td><td><?= h((string)$c[2]) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($pathChecks as $name => $pc): ?>
      <tr><td>Writable: <?= h($name) ?></td><td class="<?= $pc[0] ? 'status-ok' : 'status-bad' ?>"><?= $pc[0] ? 'PASS' : 'FAIL' ?></td><td><?= h($pc[1]) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <form method="post">
        <input type="hidden" name="setup_csrf" value="<?= h((string)$_SESSION['setup_csrf']) ?>">
    <div class="grid">
      <div><label>DB Host</label><input name="db_host" value="<?= h($input['db_host']) ?>" required></div>
            <div><label>DB Port</label><input name="db_port" type="number" min="1" max="65535" value="<?= h($input['db_port']) ?>" required></div>
      <div><label>DB Name</label><input name="db_name" value="<?= h($input['db_name']) ?>" required></div>
      <div><label>DB Username</label><input name="db_user" value="<?= h($input['db_user']) ?>" required></div>
      <div><label>DB Password</label><input type="password" name="db_pass" value="<?= h($input['db_pass']) ?>"></div>
      <div class="full"><label>Base URL (empty for domain root)</label><input name="base_url" value="<?= h($input['base_url']) ?>" placeholder="/erp"></div>
            <div><label>App Version</label><input name="app_version" value="<?= h($input['app_version']) ?>" placeholder="1.0" required></div>
      <div class="full check"><input type="checkbox" name="create_database" value="1" <?= $input['create_database'] === '1' ? 'checked' : '' ?>><label>Create database automatically (disable for shared hosting if permission denied)</label></div>
    </div>
    <button class="btn" type="submit">Run Automatic Installation</button>
  </form>

  <?php if (!empty($log)): ?>
    <div class="log">
      <?php foreach ($log as [$type, $msg]): ?>
        <div class="<?= $type ?>"><?= $type === 'ok' ? 'OK: ' : 'ERROR: ' ?><?= h((string)$msg) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if (empty($errors)): ?>
      <div class="next">
        Installation completed.<br>
        Default admin: <strong>admin@example.com</strong> / <strong>admin123</strong><br>
        Login URL: <strong><?= h(rtrim($input['base_url'], '/')) ?>/auth/login.php</strong><br>
        For security, delete <strong>setup.php</strong> after first successful login.
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>