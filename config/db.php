<?php
// ============================================================
// ERP System — Database Configuration
// Edit DB_USER / DB_PASS / BASE_URL to match your server
// ============================================================

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'shree_label_erp');

// Base URL — no trailing slash
// XAMPP:          '/calipot-erp/shree-label-php'
// Shared hosting: '' (if installed at domain root)
define('BASE_URL',   '/calipot-erp/shree-label-php');

define('APP_NAME',   'Enterprise ERP');
define('APP_VERSION','1.0');

/**
 * Returns a singleton MySQLi connection.
 */
function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database connection failed: '
                . htmlspecialchars($conn->connect_error)
                . '<br>Please run <a href="' . BASE_URL . '/setup.php">setup.php</a> first.</p>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
