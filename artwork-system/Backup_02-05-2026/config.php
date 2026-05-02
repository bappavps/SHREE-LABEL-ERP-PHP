<?php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'artwork_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// App
define('APP_NAME', 'Artwork Approval Hub');
define('SESSION_NAME', 'artwork_approval_session');

// Upload paths
define('UPLOAD_BASE_DIR', __DIR__ . '/uploads');
define('UPLOAD_PROJECT_DIR', UPLOAD_BASE_DIR . '/projects');
define('UPLOAD_REFERENCE_DIR', UPLOAD_BASE_DIR . '/references');
define('UPLOAD_FINAL_DIR', UPLOAD_BASE_DIR . '/final');
define('UPLOAD_DIR', UPLOAD_PROJECT_DIR . '/');

// Limits and allowed file types
define('MAX_UPLOAD_BYTES', 25 * 1024 * 1024);
define('ALLOWED_ARTWORK_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'ai', 'cdr']);
define('ALLOWED_REFERENCE_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'webp']);

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
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'])) : '';
$appRoot = str_replace('\\', '/', (string) realpath(__DIR__));
$projectBase = '';

if ($docRoot !== '' && $appRoot !== '' && stripos($appRoot, $docRoot) === 0) {
    $projectBase = substr($appRoot, strlen($docRoot));
}

if ($projectBase === '' || $projectBase === false) {
    $projectBase = '/' . basename(__DIR__);
}

$projectBase = '/' . trim((string) $projectBase, '/');
define('BASE_URL', $scheme . '://' . $host . $projectBase);

// Ensure required directories exist
$requiredDirs = [UPLOAD_BASE_DIR, UPLOAD_PROJECT_DIR, UPLOAD_REFERENCE_DIR, UPLOAD_FINAL_DIR];
foreach ($requiredDirs as $requiredDir) {
    if (!is_dir($requiredDir)) {
        @mkdir($requiredDir, 0755, true);
    }
}

