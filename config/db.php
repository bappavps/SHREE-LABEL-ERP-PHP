<?php
// ============================================================
// ERP System — Portable Database Configuration
// Works across local XAMPP and shared hosting when DB credentials
// are provided via runtime config or environment variables.
// ============================================================

function erp_env_value(array $names, $default = '') {
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }
        if (isset($_SERVER[$name]) && trim((string)$_SERVER[$name]) !== '') {
            return trim((string)$_SERVER[$name]);
        }
    }
    return $default;
}

function erp_is_local_request() {
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

function erp_normalize_base_url($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = str_replace('\\', '/', $value);
    if (preg_match('#^[A-Za-z]:/#', $value) || stripos($value, '/public_html/') !== false || stripos($value, '/htdocs/') !== false) {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $value)) {
        $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
        $value = $path;
    }

    $normalized = '/' . trim($value, '/');
    return $normalized === '/' ? '' : $normalized;
}

function erp_detect_base_url($configured = '') {
    $normalized = erp_normalize_base_url($configured);
    if ($normalized !== '' || trim((string)$configured) === '/') {
        return $normalized;
    }

    $documentRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $appRoot = str_replace('\\', '/', (string)(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')));

    if ($documentRoot !== '' && $appRoot !== '') {
        $documentRoot = rtrim($documentRoot, '/');
        if (stripos($appRoot, $documentRoot) === 0) {
            $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
            return $relative === '' ? '' : '/' . $relative;
        }
    }

    return '';
}

$profiles = [
    'local' => [
        'DB_HOST' => erp_env_value(['ERP_DB_HOST_LOCAL', 'ERP_DB_HOST', 'DB_HOST'], 'localhost'),
        'DB_USER' => erp_env_value(['ERP_DB_USER_LOCAL', 'ERP_DB_USER', 'DB_USER'], 'root'),
        'DB_PASS' => erp_env_value(['ERP_DB_PASS_LOCAL', 'ERP_DB_PASS', 'DB_PASS'], ''),
        'DB_NAME' => erp_env_value(['ERP_DB_NAME_LOCAL', 'ERP_DB_NAME', 'DB_NAME'], 'shree_label_erp'),
        'BASE_URL' => erp_env_value(['ERP_BASE_URL_LOCAL', 'ERP_BASE_URL'], ''),
        'APP_NAME' => 'Enterprise ERP',
        'APP_VERSION' => '1.0',
    ],
    'live' => [
        'DB_HOST' => erp_env_value(['ERP_DB_HOST_LIVE', 'ERP_DB_HOST', 'DB_HOST'], 'localhost'),
        'DB_USER' => erp_env_value(['ERP_DB_USER_LIVE', 'ERP_DB_USER', 'DB_USER'], ''),
        'DB_PASS' => erp_env_value(['ERP_DB_PASS_LIVE', 'ERP_DB_PASS', 'DB_PASS'], ''),
        'DB_NAME' => erp_env_value(['ERP_DB_NAME_LIVE', 'ERP_DB_NAME', 'DB_NAME'], ''),
        'BASE_URL' => erp_env_value(['ERP_BASE_URL_LIVE', 'ERP_BASE_URL'], ''),
        'APP_NAME' => 'Enterprise ERP',
        'APP_VERSION' => '1.0',
    ],
];

$runtimeConfigFile = __DIR__ . '/db.runtime.php';
if (file_exists($runtimeConfigFile)) {
    $runtime = require $runtimeConfigFile;
    if (is_array($runtime)) {
        if (isset($runtime['local']) && is_array($runtime['local'])) {
            $profiles['local'] = array_merge($profiles['local'], $runtime['local']);
        }
        if (isset($runtime['live']) && is_array($runtime['live'])) {
            $profiles['live'] = array_merge($profiles['live'], $runtime['live']);
        }
    }
}

$forcedEnv = getenv('ERP_ENV');
$env = '';
if ($forcedEnv === 'local' || $forcedEnv === 'live') {
    $env = $forcedEnv;
} else {
    $env = erp_is_local_request() ? 'local' : 'live';
}

$active = $profiles[$env];
$resolvedBaseUrl = erp_detect_base_url($active['BASE_URL'] ?? '');

define('DB_HOST', $active['DB_HOST']);
define('DB_USER', $active['DB_USER']);
define('DB_PASS', $active['DB_PASS']);
define('DB_NAME', $active['DB_NAME']);
define('BASE_URL', $resolvedBaseUrl);
define('APP_NAME', $active['APP_NAME']);
define('APP_VERSION', $active['APP_VERSION']);

function getDB() {
    static $conn = null;
    if ($conn === null) {
        if (trim((string)DB_HOST) === '' || trim((string)DB_USER) === '' || trim((string)DB_NAME) === '') {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database settings are incomplete.'
                . '<br>Environment: ' . htmlspecialchars((string) (getenv('ERP_ENV') ?: 'auto'))
                . '<br>Please update config/db.runtime.php or provide ERP_DB_HOST, ERP_DB_USER, ERP_DB_PASS, ERP_DB_NAME environment variables.</p>');
        }
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database connection failed: '
                . htmlspecialchars($conn->connect_error)
                . '<br>Environment: ' . htmlspecialchars((string) (getenv('ERP_ENV') ?: 'auto'))
                . '<br>Please check config/db.runtime.php or your ERP_DB_* environment variables.</p>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
