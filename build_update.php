<?php
/**
 * ERP Package Builder
 * 
 * CLI tool to create:
 * 1) update packages from git changes
 * 2) full fresh-install setup packages
 * Usage: php build_update.php
 */

if (php_sapi_name() !== 'cli') {
    echo 'This script must be run from the command line.';
    exit(1);
}

$projectRoot = __DIR__;
$configFile  = $projectRoot . '/config/db.php';

function normalize_path($path) {
    return str_replace('\\', '/', (string)$path);
}

function collect_setup_files($projectRoot) {
    $excludePatterns = [
        '#^\.git/#',
        '#^node_modules/#',
        '#^\.gitignore$#',
        '#^local_backup\.zip$#',
        '#^update_v.*\.zip$#',
        '#^setup_v.*\.zip$#',
        '#^config/db\.runtime\.php$#',
        '#^data/install\.lock$#',
        '#^data/update_log\.json$#',
        '#^uploads/.+#',
    ];

    $requiredPaths = [
        'setup.php',
        'infinityfree_preflight.php',
        'database/schema.sql',
    ];

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $fullPath = normalize_path($fileInfo->getPathname());
        $relPath = ltrim(substr($fullPath, strlen(normalize_path($projectRoot))), '/');
        if ($relPath === '') {
            continue;
        }

        $skip = false;
        foreach ($excludePatterns as $pat) {
            if (preg_match($pat, $relPath)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $files[] = $relPath;
        }
    }

    sort($files);

    foreach ($requiredPaths as $required) {
        if (!in_array($required, $files, true)) {
            fwrite(STDERR, "ERROR: Required setup file missing from package list: {$required}\n");
            exit(1);
        }
    }

    return $files;
}

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
echo "  ERP Package Builder\n";
echo "==============================================\n";
echo "  Current version: {$currentVersion}\n";
echo "  Project root:    {$projectRoot}\n";
echo "----------------------------------------------\n\n";

echo "Package type:\n";
echo "  1) Update package (for Settings > System Update)\n";
echo "  2) Fresh setup package (for new installations)\n";
echo "Choice [1]: ";
$packageChoice = trim((string)fgets(STDIN));
if ($packageChoice === '') $packageChoice = '1';

$isSetupPackage = ($packageChoice === '2');
if (!$isSetupPackage && $packageChoice !== '1') {
    fwrite(STDERR, "ERROR: Invalid package type choice.\n");
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

echo "Package description: ";
$description = trim((string)fgets(STDIN));
if ($description === '') $description = $isSetupPackage
    ? "Fresh install package v{$newVersion}"
    : "Update to v{$newVersion}";

$files = [];
$migrations = [];

if ($isSetupPackage) {
    echo "\nCollecting files for fresh setup package...\n";
    $files = collect_setup_files($projectRoot);
    echo count($files) . " file(s) to include in setup package.\n";
} else {
    // Check git is available for update-package mode.
    $gitCheck = trim((string)shell_exec('git --version 2>&1'));
    if (strpos($gitCheck, 'git version') === false) {
        fwrite(STDERR, "ERROR: git is not available in PATH.\n");
        exit(1);
    }

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
        '#^setup_v#',
        '#^INFINITYFREE_DEPLOY\.md$#',
        '#^_check_import\.php$#',
        '#^_test_mapping\.php$#',
    ];

    foreach ($allFiles as $f) {
        $f = normalize_path($f);
        $skip = false;
        foreach ($excludePatterns as $pat) {
            if (preg_match($pat, $f)) { $skip = true; break; }
        }
        if (!$skip && !is_file($projectRoot . '/' . $f)) {
            $skip = true;
        }
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

    $migrationsDir = $projectRoot . '/pending_migrations';
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
        echo "Include these SQL migration(s) in the update package? [y/N]: ";
        $includeMigrations = trim((string)fgets(STDIN));
        if ($includeMigrations === '' || strtolower($includeMigrations[0]) !== 'y') {
            $migrations = [];
            echo "Skipping SQL migrations for this package.\n";
        }
    } else {
        echo "\nNo SQL migrations in pending_migrations/\n";
    }
}

// ── Confirm ──
echo "\nCreate " . ($isSetupPackage ? 'setup' : 'update') . " package v{$newVersion}? [Y/n]: ";
$confirm = trim((string)fgets(STDIN));
if ($confirm !== '' && strtolower($confirm[0]) !== 'y') {
    echo "Cancelled.\n";
    exit(0);
}

// ── Build manifest ──
$manifest = [
    'package_type' => $isSetupPackage ? 'setup' : 'update',
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

$zipName = ($isSetupPackage ? 'setup_v' : 'update_v') . "{$newVersion}.zip";
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
        if ($isSetupPackage) {
            $zip->addFile($fullPath, $f);
        } else {
            $zip->addFile($fullPath, 'files/' . $f);
        }
    }
}

// Add migrations
if (!$isSetupPackage) {
    $migrationsDir = $projectRoot . '/pending_migrations';
    foreach ($migrations as $mf) {
        $fullPath = $migrationsDir . '/' . $mf;
        if (is_file($fullPath)) {
            $zip->addFile($fullPath, 'migrations/' . $mf);
        }
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
if (!$isSetupPackage) {
    $migrationsDir = $projectRoot . '/pending_migrations';
    foreach ($migrations as $mf) {
        $fullPath = $migrationsDir . '/' . $mf;
        if (is_file($fullPath)) {
            $checksumData .= hash_file('sha256', $fullPath);
        }
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
echo "  Type: " . ($isSetupPackage ? 'fresh-setup' : 'update') . "\n";
echo "  Files: " . count($files) . " | Migrations: " . count($migrations) . "\n";
echo "==============================================\n";
if ($isSetupPackage) {
    echo "\nUse this ZIP for fresh installation (extract full package, then run setup.php).\n";
} else {
    echo "\nUpload this ZIP via Settings > System Update.\n";
}
