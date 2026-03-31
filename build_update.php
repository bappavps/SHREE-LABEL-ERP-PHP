<?php
/**
 * ERP Update Package Builder
 * 
 * CLI tool to create update packages from git changes.
 * Usage: php build_update.php
 * 
 * Creates a ZIP with changed files + optional SQL migrations.
 */

if (php_sapi_name() !== 'cli') {
    echo 'This script must be run from the command line.';
    exit(1);
}

$projectRoot = __DIR__;
$configFile  = $projectRoot . '/config/db.php';

// ── Read current APP_VERSION from config ──
$configContent = file_get_contents($configFile);
if ($configContent === false) {
    fwrite(STDERR, "ERROR: Cannot read config/db.php\n");
    exit(1);
}
if (preg_match("/'APP_VERSION'\s*=>\s*'([^']+)'/", $configContent, $m)) {
    $currentVersion = $m[1];
} else {
    $currentVersion = 'unknown';
}

echo "==============================================\n";
echo "  ERP Update Package Builder\n";
echo "==============================================\n";
echo "  Current version: {$currentVersion}\n";
echo "  Project root:    {$projectRoot}\n";
echo "----------------------------------------------\n\n";

// ── Check git is available ──
$gitCheck = trim((string)shell_exec('git --version 2>&1'));
if (strpos($gitCheck, 'git version') === false) {
    fwrite(STDERR, "ERROR: git is not available in PATH.\n");
    exit(1);
}

// ── Prompt for new version ──
echo "New version number (e.g. 1.1): ";
$newVersion = trim((string)fgets(STDIN));
if ($newVersion === '') {
    fwrite(STDERR, "ERROR: Version number is required.\n");
    exit(1);
}
if (!preg_match('/^\d+(\.\d+){0,3}$/', $newVersion)) {
    fwrite(STDERR, "ERROR: Invalid version format. Use numbers like 1.1 or 1.2.3\n");
    exit(1);
}

echo "Update description: ";
$description = trim((string)fgets(STDIN));
if ($description === '') $description = "Update to v{$newVersion}";

// ── Choose diff source ──
echo "\nHow to detect changed files?\n";
echo "  1) Compare with last N commits (default: 1)\n";
echo "  2) Compare with a git tag\n";
echo "  3) Compare staged files only (git diff --cached)\n";
echo "  4) All uncommitted changes (git diff HEAD)\n";
echo "Choice [1]: ";
$choice = trim((string)fgets(STDIN));
if ($choice === '') $choice = '1';

$diffCmd = '';
switch ($choice) {
    case '1':
        echo "Number of commits back [1]: ";
        $n = trim((string)fgets(STDIN));
        $n = (int)($n ?: 1);
        if ($n < 1) $n = 1;
        $diffCmd = "git diff --name-only HEAD~{$n}";
        break;
    case '2':
        echo "Tag name (e.g. v1.0): ";
        $tag = trim((string)fgets(STDIN));
        if ($tag === '') {
            fwrite(STDERR, "ERROR: Tag name is required.\n");
            exit(1);
        }
        $diffCmd = "git diff --name-only " . escapeshellarg($tag) . " HEAD";
        break;
    case '3':
        $diffCmd = "git diff --cached --name-only";
        break;
    case '4':
        $diffCmd = "git diff HEAD --name-only";
        break;
    default:
        fwrite(STDERR, "ERROR: Invalid choice.\n");
        exit(1);
}

echo "\nRunning: {$diffCmd}\n";
$diffOutput = trim((string)shell_exec($diffCmd . ' 2>&1'));
if ($diffOutput === '') {
    fwrite(STDERR, "No changed files detected. Nothing to package.\n");
    exit(1);
}

$allFiles = array_filter(array_map('trim', explode("\n", $diffOutput)));

// ── Filter excluded paths ──
$excludePatterns = [
    '#^\.git/#',
    '#^\.gitignore$#',
    '#^node_modules/#',
    '#^build_update\.php$#',
    '#^uploads/#',
    '#^data/#',
    '#^local_backup\.zip$#',
    '#^pending_migrations/#',
    '#^update_v#',
    '#^INFINITYFREE_DEPLOY\.md$#',
    '#^_check_import\.php$#',
    '#^_test_mapping\.php$#',
];

$files = [];
foreach ($allFiles as $f) {
    $f = str_replace('\\', '/', $f);
    $skip = false;
    foreach ($excludePatterns as $pat) {
        if (preg_match($pat, $f)) { $skip = true; break; }
    }
    // Skip deleted files (not on disk)
    if (!$skip && !is_file($projectRoot . '/' . $f)) {
        $skip = true;
    }
    // Protect config/db.php from being included (credentials)
    if ($f === 'config/db.php') {
        $skip = true;
    }
    if (!$skip) {
        $files[] = $f;
    }
}

if (empty($files)) {
    fwrite(STDERR, "No eligible files to package after filtering.\n");
    exit(1);
}

echo "\n" . count($files) . " file(s) to include:\n";
foreach ($files as $f) {
    echo "  + {$f}\n";
}

// ── Check for pending migrations ──
$migrationsDir = $projectRoot . '/pending_migrations';
$migrations = [];
if (is_dir($migrationsDir)) {
    $sqlFiles = glob($migrationsDir . '/*.sql');
    if (!empty($sqlFiles)) {
        sort($sqlFiles);
        foreach ($sqlFiles as $sf) {
            $migrations[] = basename($sf);
        }
    }
}

if (!empty($migrations)) {
    echo "\n" . count($migrations) . " SQL migration(s) found:\n";
    foreach ($migrations as $mf) {
        echo "  ~ {$mf}\n";
    }
} else {
    echo "\nNo SQL migrations in pending_migrations/\n";
}

// ── Confirm ──
echo "\nCreate update package v{$newVersion}? [Y/n]: ";
$confirm = trim((string)fgets(STDIN));
if ($confirm !== '' && strtolower($confirm[0]) !== 'y') {
    echo "Cancelled.\n";
    exit(0);
}

// ── Build manifest ──
$manifest = [
    'version'      => $newVersion,
    'from_version' => $currentVersion,
    'description'  => $description,
    'timestamp'    => date('c'),
    'files'        => $files,
    'migrations'   => $migrations,
];

// ── Create ZIP ──
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: ZipArchive PHP extension is required.\n");
    exit(1);
}

$zipName = "update_v{$newVersion}.zip";
$zipPath = $projectRoot . '/' . $zipName;

if (file_exists($zipPath)) {
    echo "WARNING: {$zipName} already exists. Overwrite? [Y/n]: ";
    $ow = trim((string)fgets(STDIN));
    if ($ow !== '' && strtolower($ow[0]) !== 'y') {
        echo "Cancelled.\n";
        exit(0);
    }
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERROR: Cannot create ZIP file.\n");
    exit(1);
}

// Add changed files
foreach ($files as $f) {
    $fullPath = $projectRoot . '/' . $f;
    if (is_file($fullPath)) {
        $zip->addFile($fullPath, 'files/' . $f);
    }
}

// Add migrations
foreach ($migrations as $mf) {
    $fullPath = $migrationsDir . '/' . $mf;
    if (is_file($fullPath)) {
        $zip->addFile($fullPath, 'migrations/' . $mf);
    }
}

// Generate checksum of all file contents
$checksumData = '';
foreach ($files as $f) {
    $fullPath = $projectRoot . '/' . $f;
    if (is_file($fullPath)) {
        $checksumData .= hash_file('sha256', $fullPath);
    }
}
foreach ($migrations as $mf) {
    $fullPath = $migrationsDir . '/' . $mf;
    if (is_file($fullPath)) {
        $checksumData .= hash_file('sha256', $fullPath);
    }
}
$manifest['checksum'] = hash('sha256', $checksumData);

// Add manifest
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$zip->close();

$sizeMB = round(filesize($zipPath) / 1024 / 1024, 2);
echo "\n==============================================\n";
echo "  Package created: {$zipName} ({$sizeMB} MB)\n";
echo "  Version: {$currentVersion} → {$newVersion}\n";
echo "  Files: " . count($files) . " | Migrations: " . count($migrations) . "\n";
echo "==============================================\n";
echo "\nUpload this ZIP via Settings > System Update.\n";
