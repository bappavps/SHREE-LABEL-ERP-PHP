<?php
// ============================================================
// ERP System — Dual Environment Database Configuration
// Default: XAMPP local profile
// Live host: auto-switch to live profile by host detection
// Optional installer runtime overrides: config/db.runtime.php
// ============================================================

$profiles = [
    'local' => [
        'DB_HOST' => 'localhost',
        'DB_USER' => 'root',
        'DB_PASS' => '',
        'DB_NAME' => 'shree_label_erp',
        'BASE_URL' => '/calipot-erp/shree-label-php',
        'APP_NAME' => 'Enterprise ERP',
        'APP_VERSION' => '1.0',
    ],
    'live' => [
        // Replace with your real hosting values.
        'DB_HOST' => 'sql208.infinityfree.com',
        'DB_USER' => 'if0_41486428',
        'DB_PASS' => 'YOUR_DB_PASSWORD_HERE',
        'DB_NAME' => 'if0_41486428_shreeerp',
        'BASE_URL' => '',
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
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $serverAddr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    $isLocalHost = ($host === ''
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || substr($host, -6) === '.local'
        || $serverAddr === '127.0.0.1'
        || $serverAddr === '::1');

    // Default must be XAMPP/local unless detected otherwise.
    $env = $isLocalHost ? 'local' : 'live';
}

$active = $profiles[$env];

define('DB_HOST', $active['DB_HOST']);
define('DB_USER', $active['DB_USER']);
define('DB_PASS', $active['DB_PASS']);
define('DB_NAME', $active['DB_NAME']);
define('BASE_URL', $active['BASE_URL']);
define('APP_NAME', $active['APP_NAME']);
define('APP_VERSION', $active['APP_VERSION']);

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database connection failed: '
                . htmlspecialchars($conn->connect_error)
                . '<br>Environment: ' . htmlspecialchars((string) (getenv('ERP_ENV') ?: 'auto'))
                . '<br>Please run <a href="' . BASE_URL . '/setup.php">setup.php</a> first.</p>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
