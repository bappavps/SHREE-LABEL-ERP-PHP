<?php
declare(strict_types=1);

$cfgPath = __DIR__ . '/data/github_auto_push.json';
$log = [];
$errors = [];

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function out_line(array &$bucket, string $line): void {
    $bucket[] = trim($line) === '' ? '(empty output)' : $line;
}

function run_git(string $cmd, array &$bucket, array &$errors): int {
    if (!function_exists('exec')) {
        $errors[] = 'exec() is disabled on this server. Git auto-push cannot run.';
        return 1;
    }
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    foreach ($output as $line) {
        out_line($bucket, (string) $line);
    }
    return $code;
}

$cfg = [
    'enabled' => false,
    'remote' => 'origin',
    'branch' => 'main',
    'access_key' => '',
];

if (file_exists($cfgPath)) {
    $raw = json_decode((string) file_get_contents($cfgPath), true);
    if (is_array($raw)) {
        $cfg = array_merge($cfg, $raw);
    }
}

$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$providedKey = trim((string) ($_POST['access_key'] ?? $_GET['key'] ?? ''));
$hasKeyMatch = $cfg['access_key'] !== '' && hash_equals((string) $cfg['access_key'], $providedKey);
$authorized = $isLocal || $hasKeyMatch;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_push'])) {
    if (!$cfg['enabled']) {
        $errors[] = 'Git auto-push is disabled. Enable it from setup.php first.';
    } elseif (!$authorized) {
        $errors[] = 'Access denied. Provide a valid access key.';
    } else {
        $repo = __DIR__;
        $remote = trim((string) ($_POST['git_remote'] ?? $cfg['remote']));
        $branch = trim((string) ($_POST['git_branch'] ?? $cfg['branch']));
        $message = trim((string) ($_POST['commit_message'] ?? 'Auto commit from ERP UI'));

        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $remote)) {
            $errors[] = 'Invalid remote name.';
        }
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $branch)) {
            $errors[] = 'Invalid branch name.';
        }

        if (empty($errors)) {
            $log[] = 'Repository: ' . $repo;

            $statusOut = [];
            $statusCode = run_git('git -C ' . escapeshellarg($repo) . ' status --porcelain', $statusOut, $errors);
            foreach ($statusOut as $line) {
                $log[] = $line;
            }

            if ($statusCode !== 0) {
                $errors[] = 'Unable to read repository status.';
            }

            if (empty($errors)) {
                $addCode = run_git('git -C ' . escapeshellarg($repo) . ' add -A', $log, $errors);
                if ($addCode !== 0) {
                    $errors[] = 'git add failed.';
                }
            }

            if (empty($errors)) {
                $commitCode = run_git('git -C ' . escapeshellarg($repo) . ' commit -m ' . escapeshellarg($message), $log, $errors);
                if ($commitCode !== 0) {
                    $log[] = 'Commit may have been skipped (no staged changes) or failed.';
                }
            }

            if (empty($errors)) {
                $pushCmd = 'git -C ' . escapeshellarg($repo) . ' push ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch);
                $pushCode = run_git($pushCmd, $log, $errors);
                if ($pushCode !== 0) {
                    $errors[] = 'git push failed. Check auth, remote, and branch.';
                } else {
                    $log[] = 'Push completed successfully.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Git Auto Push</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8fafc;padding:20px;margin:0}
        .card{max-width:880px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}
        h1{margin:0 0 8px}
        .muted{color:#64748b;font-size:.9rem}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
        .full{grid-column:1 / -1}
        label{font-size:.78rem;font-weight:700;color:#334155;display:block;margin-bottom:4px}
        input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}
        button{margin-top:14px;padding:12px 16px;border:0;border-radius:8px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer}
        .warn{margin-top:12px;padding:10px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412}
        .err{margin-top:12px;padding:10px;border-radius:8px;background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
        .ok{margin-top:12px;padding:10px;border-radius:8px;background:#ecfeff;border:1px solid #67e8f9;color:#155e75}
        pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:.82rem;line-height:1.4}
    </style>
</head>
<body>
<div class="card">
    <h1>Git Auto Push</h1>
    <p class="muted">This utility runs git add, commit, and push from the project root.</p>

    <?php if (!$cfg['enabled']): ?>
        <div class="warn">Auto-push is disabled. Re-run setup.php and enable Git auto-push.</div>
    <?php endif; ?>

    <?php if (!$authorized): ?>
        <div class="warn">Access key required. Localhost can access without key. On remote hosting, enter installer-generated key.</div>
    <?php endif; ?>

    <form method="post">
        <div class="grid">
            <div><label>Git Remote</label><input name="git_remote" value="<?= e((string) $cfg['remote']) ?>"></div>
            <div><label>Git Branch</label><input name="git_branch" value="<?= e((string) $cfg['branch']) ?>"></div>
            <div class="full"><label>Commit Message</label><input name="commit_message" value="Auto commit from ERP UI"></div>
            <div class="full"><label>Access Key (required for non-localhost)</label><input name="access_key" value="<?= e($providedKey) ?>"></div>
        </div>
        <button name="do_push" value="1" type="submit">Run Git Push</button>
    </form>

    <?php if (!empty($errors)): ?>
        <div class="err"><pre><?= e(implode("\n", $errors)) ?></pre></div>
    <?php endif; ?>

    <?php if (!empty($log)): ?>
        <div class="ok"><pre><?= e(implode("\n", $log)) ?></pre></div>
    <?php endif; ?>
</div>
</body>
</html>
