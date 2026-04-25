<?php
// ============================================================
// ERP Diagnostic — Temporary file for white-page debugging
// UPLOAD TO LIVE SERVER, OPEN IN BROWSER, THEN DELETE.
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ob_start();

echo '<pre style="font-family:monospace;font-size:13px;padding:16px">';
echo '<strong>ERP Diagnostic Report</strong>' . "\n";
echo str_repeat('-', 60) . "\n";

// ── PHP Version ─────────────────────────────────────────────
echo 'PHP Version   : ' . PHP_VERSION . "\n";
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
echo 'PHP >= 8.0    : ' . ($phpOk ? 'OK' : 'FAIL — upgrade PHP to 8.0+ in cPanel') . "\n";

// ── Required Extensions ─────────────────────────────────────
$exts = ['mysqli', 'json', 'mbstring', 'session', 'pcre', 'openssl'];
foreach ($exts as $ext) {
    echo 'ext/' . str_pad($ext, 12) . ': ' . (extension_loaded($ext) ? 'OK' : 'MISSING') . "\n";
}

echo "\n";

// ── File Existence ──────────────────────────────────────────
$files = [
    __DIR__ . '/config/db.php',
    __DIR__ . '/config/db.runtime.php',
    __DIR__ . '/config/tenants.php',
    __DIR__ . '/includes/functions.php',
    __DIR__ . '/includes/auth_check.php',
    __DIR__ . '/includes/header.php',
    __DIR__ . '/includes/footer.php',
    __DIR__ . '/data/app_settings.json',
];
foreach ($files as $f) {
    $rel = str_replace(__DIR__, '', $f);
    echo str_pad($rel, 36) . ': ' . (file_exists($f) ? 'EXISTS' : 'MISSING') . "\n";
}

// ── db.runtime.php contents (safe) ──────────────────────────
echo "\n";
$runtimePath = __DIR__ . '/config/db.runtime.php';
if (file_exists($runtimePath)) {
    echo "db.runtime.php   : EXISTS\n";
    $runtime = require $runtimePath;
    if (is_array($runtime)) {
        foreach (['local', 'live'] as $env) {
            if (!empty($runtime[$env])) {
                foreach ($runtime[$env] as $k => $v) {
                    $display = (stripos($k, 'PASS') !== false || stripos($k, 'SECRET') !== false)
                        ? '*** (hidden)'
                        : (string)$v;
                    echo "  $env.$k = $display\n";
                }
            }
        }
    }
} else {
    echo "db.runtime.php   : MISSING — run setup.php first!\n";
}

echo "\n";

// ── DB Connection Test ──────────────────────────────────────
echo str_repeat('-', 60) . "\n";
echo "DB Connection Test\n";
echo str_repeat('-', 60) . "\n";
try {
    require_once __DIR__ . '/config/db.php';
    echo 'ERP Env       : ' . (getenv('ERP_ENV') ?: 'auto') . "\n";
    echo 'DB_HOST       : ' . DB_HOST . "\n";
    echo 'DB_PORT       : ' . DB_PORT . "\n";
    echo 'DB_USER       : ' . DB_USER . "\n";
    echo 'DB_NAME       : ' . DB_NAME . "\n";
    echo 'BASE_URL      : ' . (BASE_URL === '' ? '(empty — root)' : BASE_URL) . "\n";
    echo 'TENANT_SLUG   : ' . TENANT_SLUG . "\n";
    echo 'TENANT_ACTIVE : ' . (TENANT_ACTIVE ? 'true' : 'false') . "\n";
    $db = getDB();
    echo 'DB Connect    : OK' . "\n";
    $r = $db->query('SELECT COUNT(*) AS c FROM users LIMIT 1');
    $cnt = $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
    echo 'users table   : ' . ($r ? "EXISTS ($cnt rows)" : 'MISSING or error') . "\n";
} catch (Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage() . "\n";
}

echo "\n";

// ── Session Test ─────────────────────────────────────────────
echo str_repeat('-', 60) . "\n";
echo "Session Test\n";
echo str_repeat('-', 60) . "\n";
if (session_status() === PHP_SESSION_NONE) session_start();
echo 'Session status: ' . session_status() . " (2=active)\n";
echo 'Session ID    : ' . session_id() . "\n";
echo 'user_id in session: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '(not set — not logged in)') . "\n";

echo "\n";

// ── functions.php load test ──────────────────────────────────
echo str_repeat('-', 60) . "\n";
echo "functions.php Load Test\n";
echo str_repeat('-', 60) . "\n";
try {
    if (!function_exists('e')) {
        require_once __DIR__ . '/includes/functions.php';
    }
    echo "functions.php : OK\n";
    echo "createDepartmentNotifications: " . (function_exists('createDepartmentNotifications') ? 'OK' : 'MISSING') . "\n";
    echo "getAppSettings: " . (function_exists('getAppSettings') ? 'OK' : 'MISSING') . "\n";
    echo "generateCSRF  : " . (function_exists('generateCSRF') ? 'OK' : 'MISSING') . "\n";
    echo "canAccessPath : " . (function_exists('canAccessPath') ? 'OK' : 'MISSING') . "\n";
} catch (Throwable $e) {
    echo "functions.php ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n";

// ── dashboard PHP logic test (no output) ────────────────────
echo str_repeat('-', 60) . "\n";
echo "Dashboard Logic Test\n";
echo str_repeat('-', 60) . "\n";
try {
    if (defined('DB_NAME') && function_exists('getDB')) {
        $db2 = getDB();
        // Test match expression (PHP 8.0+)
        $testPeriod = 'month';
        $matchResult = match ($testPeriod) {
            'today' => 'today_expr',
            'week'  => 'week_expr',
            'month' => 'month_expr',
            'year'  => 'year_expr',
            default => 'default_expr',
        };
        echo "match() syntax: OK ($matchResult)\n";
        // Test critical tables
        $tables = ['users', 'paper_stock', 'estimates', 'sales_orders', 'planning', 'jobs', 'job_notifications'];
        foreach ($tables as $tbl) {
            $r2 = @$db2->query("SELECT 1 FROM `$tbl` LIMIT 1");
            echo "table $tbl: " . ($r2 !== false ? 'OK' : 'MISSING or ERROR') . "\n";
        }

        $packingTable = @$db2->query("SHOW TABLES LIKE 'packing_operator_entries'");
        $hasPackingTable = $packingTable instanceof mysqli_result && $packingTable->num_rows > 0;
        echo "packing_operator_entries table: " . ($hasPackingTable ? 'OK' : 'MISSING') . "\n";
        if ($packingTable instanceof mysqli_result) {
            $packingTable->close();
        }

        foreach (['loose_qty', 'roll_payload_json', 'submitted_lock'] as $col) {
            $colRes = @$db2->query("SHOW COLUMNS FROM `packing_operator_entries` LIKE '" . $db2->real_escape_string($col) . "'");
            $hasCol = $colRes instanceof mysqli_result && $colRes->num_rows > 0;
            echo "packing_operator_entries.$col: " . ($hasCol ? 'OK' : 'MISSING') . "\n";
            if ($colRes instanceof mysqli_result) {
                $colRes->close();
            }
        }
    }
} catch (Throwable $e) {
    echo "Dashboard logic ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n";

// ── dashboard full runtime probe ─────────────────────────────
echo str_repeat('-', 60) . "\n";
echo "Dashboard Full Runtime Probe\n";
echo str_repeat('-', 60) . "\n";
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $existingSession = $_SESSION;
    $existingGet = $_GET;
    $existingRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $existingPhpSelf = $_SERVER['PHP_SELF'] ?? null;

    $_SESSION['user_id'] = 1;
    $_SESSION['user_name'] = $_SESSION['user_name'] ?? 'Diagnostic User';
    $_SESSION['user_email'] = $_SESSION['user_email'] ?? 'diagnostic@example.com';
    $_SESSION['role'] = $_SESSION['role'] ?? 'admin';
    $_SESSION['tenant_slug'] = defined('TENANT_SLUG') ? TENANT_SLUG : 'default';
    $_SESSION['tenant_name'] = defined('TENANT_NAME') ? TENANT_NAME : APP_NAME;
    $_GET['period'] = 'month';
    $_SERVER['REQUEST_URI'] = '/modules/dashboard/index.php';
    $_SERVER['PHP_SELF'] = '/modules/dashboard/index.php';

    ob_start();
    include __DIR__ . '/modules/dashboard/index.php';
    $dashboardOutput = ob_get_clean();

    echo 'dashboard include: OK' . "\n";
    echo 'dashboard output bytes: ' . strlen((string)$dashboardOutput) . "\n";
    echo 'dashboard contains html: ' . (stripos((string)$dashboardOutput, '<html') !== false ? 'YES' : 'NO') . "\n";

    $_SESSION = $existingSession;
    $_GET = $existingGet;
    if ($existingRequestUri !== null) {
        $_SERVER['REQUEST_URI'] = $existingRequestUri;
    }
    if ($existingPhpSelf !== null) {
        $_SERVER['PHP_SELF'] = $existingPhpSelf;
    }
} catch (Throwable $e) {
    if (ob_get_level() > 1) {
        ob_end_clean();
    }
    echo 'dashboard include ERROR: ' . $e->getMessage() . "\n";
    echo 'File: ' . $e->getFile() . ' Line: ' . $e->getLine() . "\n";
}

echo "\n";

// ── header.php load test ─────────────────────────────────────
echo str_repeat('-', 60) . "\n";
echo "header.php Load Test\n";
echo str_repeat('-', 60) . "\n";
try {
    $bufBefore = ob_get_length();
    // Don't actually include header (it outputs HTML), just check file
    $headerPath = __DIR__ . '/includes/header.php';
    if (file_exists($headerPath)) {
        echo "header.php    : EXISTS (" . filesize($headerPath) . " bytes)\n";
        // Check for any syntax errors by tokenizing
        $tokens = token_get_all(file_get_contents($headerPath));
        echo "header.php tokens: " . count($tokens) . " (parsed ok)\n";
    } else {
        echo "header.php    : MISSING\n";
    }
} catch (Throwable $e) {
    echo "header.php ERROR: " . $e->getMessage() . "\n";
}

echo "\n";
echo str_repeat('-', 60) . "\n";
echo "Diagnosis complete. Delete this file after use!\n";
echo str_repeat('-', 60) . "\n";
echo '</pre>';

ob_end_flush();
