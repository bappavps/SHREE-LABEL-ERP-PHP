<?php
// Database
$defaultDbHost = 'localhost';
$defaultDbName = 'artwork_system';
$defaultDbUser = 'root';
$defaultDbPass = '';

$erpRuntimeConfigFile = __DIR__ . '/../config/db.runtime.php';
if (file_exists($erpRuntimeConfigFile)) {
    $runtimeConfig = require $erpRuntimeConfigFile;
    if (is_array($runtimeConfig)) {
        $isLocal = false;
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if (
            $host === ''
            || strpos($host, 'localhost') !== false
            || strpos($host, '127.0.0.1') !== false
            || substr($host, -6) === '.local'
            || $serverAddr === '127.0.0.1'
            || $serverAddr === '::1'
            || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)
            || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $serverAddr)
        ) {
            $isLocal = true;
        }

        $envKey = $isLocal ? 'local' : 'live';
        if (isset($runtimeConfig[$envKey]) && is_array($runtimeConfig[$envKey])) {
            $profile = $runtimeConfig[$envKey];
            $defaultDbHost = (string)($profile['DB_HOST'] ?? $defaultDbHost);
            $defaultDbName = (string)($profile['DB_NAME'] ?? $defaultDbName);
            $defaultDbUser = (string)($profile['DB_USER'] ?? $defaultDbUser);
            $defaultDbPass = (string)($profile['DB_PASS'] ?? $defaultDbPass);
        }
    }
}

define('ARTWORK_DB_HOST', $defaultDbHost);
define('ARTWORK_DB_NAME', $defaultDbName);
define('ARTWORK_DB_USER', $defaultDbUser);
define('ARTWORK_DB_PASS', $defaultDbPass);

// App
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Artwork Approval Hub');
}
define('ARTWORK_APP_NAME', 'Artwork Approval Hub');
define('SESSION_NAME', 'artwork_approval_session');
define('ARTWORK_ALLOW_STANDALONE_LOGIN', false);

// Upload paths
define('UPLOAD_BASE_DIR', __DIR__ . '/uploads');
define('UPLOAD_PROJECT_DIR', UPLOAD_BASE_DIR . '/projects');
define('UPLOAD_REFERENCE_DIR', UPLOAD_BASE_DIR . '/references');
define('UPLOAD_FINAL_DIR', UPLOAD_BASE_DIR . '/final');
define('UPLOAD_DIR', UPLOAD_PROJECT_DIR . '/');

// Limits and allowed file types
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024);
define('MAX_FINAL_UPLOAD_BYTES', 1000 * 1024 * 1024);
define('ALLOWED_ARTWORK_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'ai', 'cdr']);
define('ALLOWED_REFERENCE_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);
define('ALLOWED_FINAL_EXTENSIONS', ['pdf']);

// Debug mode (set to false on production)
define('APP_DEBUG', true);
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

date_default_timezone_set('UTC');

// Dynamic base URL detection (always resolves to project root)
$_artScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_artHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';

if (!defined('BASE_URL')) {
    $_artDocRoot  = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $_artAppRoot  = str_replace('\\', '/', (string) realpath(__DIR__));
    $_artProjBase = '';

    if ($_artDocRoot !== '' && $_artAppRoot !== '' && stripos($_artAppRoot, $_artDocRoot) === 0) {
        $_artProjBase = substr($_artAppRoot, strlen($_artDocRoot));
    }
    if ($_artProjBase === '' || $_artProjBase === false) {
        $_artProjBase = '/' . basename(__DIR__);
    }
    $_artProjBase = '/' . trim((string) $_artProjBase, '/');
    define('BASE_URL', $_artScheme . '://' . $_artHost . $_artProjBase);

    // Standalone: ERP root is the parent of artwork-system
    if (!defined('ERP_BASE_URL')) {
        $_artErpBase = preg_replace('#/artwork-system$#', '', $_artProjBase);
        if (!is_string($_artErpBase) || $_artErpBase === '') {
            $_artErpBase = '';
        }
        define('ERP_BASE_URL', $_artScheme . '://' . $_artHost . $_artErpBase);
    }
} else {
    // Loaded via ERP bridge: BASE_URL is already the ERP project root
    if (!defined('ERP_BASE_URL')) {
        define('ERP_BASE_URL', BASE_URL);
    }
}

if (!defined('ERP_ARTWORK_BRIDGE_URL')) {
    define('ERP_ARTWORK_BRIDGE_URL', ERP_BASE_URL . '/modules/artwork/index.php');
}

if (!defined('ARTWORK_BASE_URL')) {
    $_artDocRoot2 = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $_artAppRoot2 = str_replace('\\', '/', (string) realpath(__DIR__));
    $_artPluginBase = '';

    if ($_artDocRoot2 !== '' && $_artAppRoot2 !== '' && stripos($_artAppRoot2, $_artDocRoot2) === 0) {
        $_artPluginBase = substr($_artAppRoot2, strlen($_artDocRoot2));
    }
    if ($_artPluginBase === '' || $_artPluginBase === false) {
        $_artPluginBase = '/artwork-system';
    }
    $_artPluginBase = '/' . trim((string) $_artPluginBase, '/');

    define('ARTWORK_BASE_URL', $_artScheme . '://' . $_artHost . $_artPluginBase);
}

// Ensure required directories exist
$requiredDirs = [UPLOAD_BASE_DIR, UPLOAD_PROJECT_DIR, UPLOAD_REFERENCE_DIR, UPLOAD_FINAL_DIR];
foreach ($requiredDirs as $requiredDir) {
    if (!is_dir($requiredDir)) {
        @mkdir($requiredDir, 0755, true);
    }
}

