<?php
declare(strict_types=1);

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function checkPathWritable(string $path): array {
    $exists = file_exists($path);
    if (!$exists) {
        if (@mkdir($path, 0755, true)) {
            $exists = true;
        }
    }

    if (!$exists) {
        return [false, 'Missing and could not be created'];
    }

    if (!is_writable($path)) {
        return [false, 'Exists but not writable'];
    }

    return [true, 'OK'];
}

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$scheme = $https ? 'https' : 'http';
$selfDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
$selfDir = rtrim($selfDir, '/');
$selfDir = ($selfDir === '/' || $selfDir === '.') ? '' : $selfDir;

$checks = [];
$checks[] = ['PHP version >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION];
$checks[] = ['mysqli extension loaded', extension_loaded('mysqli'), extension_loaded('mysqli') ? 'Loaded' : 'Missing'];
$checks[] = ['json extension loaded', extension_loaded('json'), extension_loaded('json') ? 'Loaded' : 'Missing'];
$checks[] = ['file uploads enabled', (bool)ini_get('file_uploads'), 'file_uploads=' . ini_get('file_uploads')];
$checks[] = ['schema file present', file_exists(__DIR__ . '/database/schema.sql'), 'database/schema.sql'];
$checks[] = ['setup page present', file_exists(__DIR__ . '/setup.php'), 'setup.php'];
$checks[] = ['exec() available (not required on InfinityFree)', function_exists('exec'), function_exists('exec') ? 'Available' : 'Disabled'];

$pathChecks = [];
$pathChecks['config'] = checkPathWritable(__DIR__ . '/config');
$pathChecks['data'] = checkPathWritable(__DIR__ . '/data');
$pathChecks['uploads'] = checkPathWritable(__DIR__ . '/uploads');
$pathChecks['uploads/company'] = checkPathWritable(__DIR__ . '/uploads/company');
$pathChecks['uploads/library'] = checkPathWritable(__DIR__ . '/uploads/library');

$lockExists = file_exists(__DIR__ . '/data/install.lock');

$allPass = true;
foreach ($checks as $c) {
    if (!$c[1] && $c[0] !== 'exec() available (not required on InfinityFree)') {
        $allPass = false;
    }
}
foreach ($pathChecks as $pc) {
    if (!$pc[0]) {
        $allPass = false;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>InfinityFree Preflight</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f8fafc;margin:0;padding:24px}
    .wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}
    h1{margin:0 0 4px}
    .muted{color:#64748b;font-size:.9rem;margin:0 0 14px}
    table{width:100%;border-collapse:collapse;margin:14px 0}
    th,td{border:1px solid #e2e8f0;padding:10px;text-align:left;font-size:.92rem}
    th{background:#f8fafc}
    .ok{color:#166534;font-weight:700}
    .bad{color:#b91c1c;font-weight:700}
    .warn{color:#92400e;font-weight:700}
    .box{border:1px solid #e2e8f0;border-radius:10px;padding:12px;background:#f8fafc;margin:10px 0}
    .next a{display:inline-block;margin-right:10px;padding:10px 14px;border-radius:8px;text-decoration:none;background:#0f172a;color:#fff}
  </style>
</head>
<body>
<div class="wrap">
  <h1>InfinityFree Preflight Check</h1>
  <p class="muted">Run this before setup to validate hosting readiness.</p>

  <div class="box">
    <strong>Detected Host:</strong> <?= h($host !== '' ? $host : 'unknown') ?><br>
    <strong>Detected App Base URL:</strong> <?= h($selfDir) ?><br>
    <strong>Recommended Setup Value:</strong> Base URL = <?= h($selfDir) ?>
  </div>

  <?php if ($lockExists): ?>
    <div class="box" style="border-color:#fecaca;background:#fef2f2;color:#991b1b">
      <strong>Installer lock detected:</strong> data/install.lock exists. Delete it only for a full reinstall.
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr><th>Check</th><th>Status</th><th>Details</th></tr>
    </thead>
    <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td><?= h($c[0]) ?></td>
          <td class="<?= $c[1] ? 'ok' : (($c[0] === 'exec() available (not required on InfinityFree)') ? 'warn' : 'bad') ?>">
            <?= $c[1] ? 'PASS' : (($c[0] === 'exec() available (not required on InfinityFree)') ? 'INFO' : 'FAIL') ?>
          </td>
          <td><?= h((string)$c[2]) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table>
    <thead>
      <tr><th>Writable Path</th><th>Status</th><th>Details</th></tr>
    </thead>
    <tbody>
      <?php foreach ($pathChecks as $name => $pc): ?>
        <tr>
          <td><?= h($name) ?></td>
          <td class="<?= $pc[0] ? 'ok' : 'bad' ?>"><?= $pc[0] ? 'PASS' : 'FAIL' ?></td>
          <td><?= h($pc[1]) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="box">
    <strong>InfinityFree Notes</strong><br>
    1. Keep Git auto-push disabled (shell git/exec is not available).<br>
    2. Use setup.php for DB import and admin creation.<br>
    3. After install, remove/rename setup.php and this preflight page for safety.
  </div>

  <div class="next">
    <a href="setup.php">Open setup.php</a>
    <a href="auth/login.php">Open login.php</a>
  </div>

  <p class="muted" style="margin-top:14px">Overall status: <strong class="<?= $allPass ? 'ok' : 'bad' ?>"><?= $allPass ? 'READY' : 'NEEDS ATTENTION' ?></strong></p>
</div>
</body>
</html>
