<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

$lockFile   = __DIR__ . '/data/install.lock';
$schemaFile = __DIR__ . '/database/schema.sql';
$configFile = __DIR__ . '/config/db.php';
$gitCfgFile = __DIR__ . '/data/github_auto_push.json';

$log = [];
$errors = [];

function log_ok(string $msg): void {
  global $log;
  $log[] = ['ok', $msg];
}

function log_err(string $msg): void {
  global $log, $errors;
  $log[] = ['err', $msg];
  $errors[] = $msg;
}

function e(string $val): string {
  return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

$input = [
  'db_host' => 'localhost',
  'db_name' => '',
  'db_user' => '',
  'db_pass' => '',
  'base_url' => '',
  'app_name' => 'Enterprise ERP',
  'app_version' => '1.0',
  'create_database' => '0',
  'admin_name' => 'System Admin',
  'admin_email' => 'admin@example.com',
  'admin_pass' => 'admin123',
  'enable_git_push' => '0',
  'git_remote' => 'origin',
  'git_branch' => 'main',
];

if (file_exists($lockFile)) {
  $installedAt = trim((string) @file_get_contents($lockFile));
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installer Locked</title>
    <style>
      body{font-family:Arial,sans-serif;background:#f8fafc;padding:24px}
      .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}
      .warn{color:#92400e;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px}
    </style>
  </head>
  <body>
    <div class="card">
      <h2>Installer is locked</h2>
      <p>This ERP appears to be already installed.</p>
      <p class="warn">Installed at: <?= e($installedAt !== '' ? $installedAt : 'unknown') ?></p>
      <p>Delete <strong>data/install.lock</strong> only if you need a full reinstall.</p>
    </div>
  </body>
  </html>
  <?php
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($input as $key => $value) {
    if (isset($_POST[$key])) {
      $input[$key] = trim((string) $_POST[$key]);
    }
  }

  $baseUrl = rtrim($input['base_url'], '/');

  if ($input['db_host'] === '' || $input['db_name'] === '' || $input['db_user'] === '') {
    log_err('DB Host, DB Name, and DB Username are required.');
  }
  if ($input['app_name'] === '') {
    log_err('App Name is required.');
  }
  if (!filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
    log_err('Admin Email is not valid.');
  }
  if (strlen($input['admin_pass']) < 6) {
    log_err('Admin Password must be at least 6 characters.');
  }
  if (!file_exists($schemaFile)) {
    log_err('database/schema.sql not found.');
  }

  if (empty($errors) && $input['create_database'] === '1') {
    $serverConn = new mysqli($input['db_host'], $input['db_user'], $input['db_pass']);
    if ($serverConn->connect_error) {
      log_err('Cannot connect to server for DB creation: ' . $serverConn->connect_error);
    } else {
      $q = "CREATE DATABASE IF NOT EXISTS `" . $serverConn->real_escape_string($input['db_name']) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
      if ($serverConn->query($q)) {
        log_ok('Database create/exists check completed.');
      } else {
        log_err('Database create failed: ' . $serverConn->error . ' (Disable "Create DB" on shared hosting)');
      }
      $serverConn->close();
    }
  }

  if (empty($errors)) {
    $conn = new mysqli($input['db_host'], $input['db_user'], $input['db_pass'], $input['db_name']);
    if ($conn->connect_error) {
      log_err('Cannot connect to MySQL: ' . $conn->connect_error);
    } else {
      $conn->set_charset('utf8mb4');
      log_ok('Connected to target database.');

      $sql = file_get_contents($schemaFile);
      if ($sql === false) {
        log_err('Unable to read schema.sql');
      } else {
        // Strip comments before splitting statements.
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('/^\s*#.*$/m', '', $sql);
        $stmts = array_filter(array_map('trim', explode(';', $sql)));

        $schemaErrors = 0;
        foreach ($stmts as $stmt) {
          if ($stmt === '') {
            continue;
          }
          if (!$conn->query($stmt)) {
            $schemaErrors++;
            log_err('SQL Error: ' . $conn->error . ' -- ' . substr($stmt, 0, 120));
          }
        }
        if ($schemaErrors === 0) {
          log_ok('Schema imported successfully.');
        } else {
          log_err('Schema import finished with ' . $schemaErrors . ' error(s).');
        }
      }

      if (empty($errors)) {
        $adminHash = password_hash($input['admin_pass'], PASSWORD_BCRYPT);
        $adminRole = 'admin';

        $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        if ($check) {
          $check->bind_param('s', $input['admin_email']);
          $check->execute();
          $exists = $check->get_result()->fetch_assoc();
          $check->close();

          if ($exists) {
            $upd = $conn->prepare('UPDATE users SET name = ?, password = ?, role = ? WHERE email = ?');
            if ($upd) {
              $upd->bind_param('ssss', $input['admin_name'], $adminHash, $adminRole, $input['admin_email']);
              if ($upd->execute()) {
                log_ok('Existing admin user updated.');
              } else {
                log_err('Admin update failed: ' . $upd->error);
              }
              $upd->close();
            } else {
              log_err('Admin update prepare failed.');
            }
          } else {
            $ins = $conn->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
            if ($ins) {
              $ins->bind_param('ssss', $input['admin_name'], $input['admin_email'], $adminHash, $adminRole);
              if ($ins->execute()) {
                log_ok('Admin user created successfully.');
              } else {
                log_err('Admin creation failed: ' . $ins->error);
              }
              $ins->close();
            } else {
              log_err('Admin insert prepare failed.');
            }
          }
        } else {
          log_err('Admin check prepare failed.');
        }
      }

      if (empty($errors)) {
        $dbPhp = "<?php\n"
          . "define('DB_HOST',    " . var_export($input['db_host'], true) . ");\n"
          . "define('DB_USER',    " . var_export($input['db_user'], true) . ");\n"
          . "define('DB_PASS',    " . var_export($input['db_pass'], true) . ");\n"
          . "define('DB_NAME',    " . var_export($input['db_name'], true) . ");\n"
          . "define('BASE_URL',   " . var_export($baseUrl, true) . ");\n\n"
          . "define('APP_NAME',   " . var_export($input['app_name'], true) . ");\n"
          . "define('APP_VERSION'," . var_export($input['app_version'], true) . ");\n\n"
          . "function getDB() {\n"
          . "    static \$conn = null;\n"
          . "    if (\$conn === null) {\n"
          . "        \$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);\n"
          . "        if (\$conn->connect_error) {\n"
          . "            die('<p style=\"font-family:sans-serif;color:red;padding:20px\">Database connection failed: ' . htmlspecialchars(\$conn->connect_error) . '</p>');\n"
          . "        }\n"
          . "        \$conn->set_charset('utf8mb4');\n"
          . "    }\n"
          . "    return \$conn;\n"
          . "}\n";

        if (file_put_contents($configFile, $dbPhp, LOCK_EX) === false) {
          log_err('Failed to write config/db.php (check permissions).');
        } else {
          log_ok('config/db.php generated.');
        }
      }

      if (empty($errors)) {
        if (!is_dir(__DIR__ . '/data')) {
          @mkdir(__DIR__ . '/data', 0755, true);
        }

        if ($input['enable_git_push'] === '1') {
          $accessKey = bin2hex(random_bytes(16));
          $gitCfg = [
            'enabled' => true,
            'remote' => $input['git_remote'] !== '' ? $input['git_remote'] : 'origin',
            'branch' => $input['git_branch'] !== '' ? $input['git_branch'] : 'main',
            'access_key' => $accessKey,
          ];
          if (file_put_contents($gitCfgFile, json_encode($gitCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            log_err('Could not write Git auto-push config.');
          } else {
            log_ok('Git auto-push enabled. Access key: ' . $accessKey);
          }
        }

        if (file_put_contents($lockFile, date('c'), LOCK_EX) === false) {
          log_err('Failed to create install lock file.');
        } else {
          log_ok('Install lock file created.');
        }
      }

      $conn->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ERP Installer</title>
<style>
  body{font-family:Arial,sans-serif;background:#f5f6f8;padding:20px;margin:0}
  .wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}
  h1{margin:0 0 6px}
  .muted{color:#64748b;font-size:.9rem}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
  .full{grid-column:1 / -1}
  label{font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:4px}
  input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}
  .check{display:flex;gap:8px;align-items:center;padding-top:8px}
  .check input{width:auto}
  .btn{margin-top:16px;padding:12px 16px;border:none;border-radius:8px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer}
  .log{margin-top:16px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:12px}
  .ok{color:#166534;margin:6px 0}
  .err{color:#b91c1c;margin:6px 0}
  .next{margin-top:12px;padding:10px;background:#ecfeff;border:1px solid #a5f3fc;border-radius:8px;color:#155e75}
</style>
</head>
<body>
<div class="wrap">
  <h1>Enterprise ERP Installer</h1>
  <p class="muted">Fill database credentials, import schema, create admin user, and optionally enable browser-driven Git push.</p>

  <form method="post">
    <div class="grid">
      <div><label>DB Host</label><input name="db_host" value="<?= e($input['db_host']) ?>" required></div>
      <div><label>DB Name</label><input name="db_name" value="<?= e($input['db_name']) ?>" required></div>
      <div><label>DB Username</label><input name="db_user" value="<?= e($input['db_user']) ?>" required></div>
      <div><label>DB Password</label><input type="password" name="db_pass" value="<?= e($input['db_pass']) ?>"></div>
      <div><label>Base URL (empty for domain root)</label><input name="base_url" value="<?= e($input['base_url']) ?>"></div>
      <div><label>App Name</label><input name="app_name" value="<?= e($input['app_name']) ?>" required></div>
      <div><label>App Version</label><input name="app_version" value="<?= e($input['app_version']) ?>"></div>
      <div class="full check"><input type="checkbox" name="create_database" value="1" <?= $input['create_database'] === '1' ? 'checked' : '' ?>><label>Create database automatically (disable for shared hosting)</label></div>

      <div><label>Admin Name</label><input name="admin_name" value="<?= e($input['admin_name']) ?>" required></div>
      <div><label>Admin Email</label><input type="email" name="admin_email" value="<?= e($input['admin_email']) ?>" required></div>
      <div><label>Admin Password</label><input type="password" name="admin_pass" value="<?= e($input['admin_pass']) ?>" required></div>

      <div class="full" style="border-top:1px solid #e2e8f0;padding-top:12px;margin-top:4px">
        <label style="font-size:.9rem">Optional: GitHub Auto Push</label>
      </div>
      <div class="full check"><input type="checkbox" name="enable_git_push" value="1" <?= $input['enable_git_push'] === '1' ? 'checked' : '' ?>><label>Enable browser page for git add/commit/push</label></div>
      <div><label>Git Remote</label><input name="git_remote" value="<?= e($input['git_remote']) ?>"></div>
      <div><label>Git Branch</label><input name="git_branch" value="<?= e($input['git_branch']) ?>"></div>
    </div>
    <button class="btn" type="submit">Run Installation</button>
  </form>

  <?php if (!empty($log)): ?>
    <div class="log">
      <?php foreach ($log as [$type, $msg]): ?>
        <div class="<?= $type ?>"><?= $type === 'ok' ? 'OK: ' : 'ERROR: ' ?><?= e((string) $msg) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if (empty($errors)): ?>
      <div class="next">
        Installation completed. You can now open <strong><?= e($input['base_url']) ?>/auth/login.php</strong>.
        If Git auto-push is enabled, use <strong><?= e($input['base_url']) ?>/github_auto_push.php</strong>.
        Delete setup.php after finishing.
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
