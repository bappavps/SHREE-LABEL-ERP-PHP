<?php
// ============================================================
// ERP System — Database Configuration
// Edit DB_USER / DB_PASS / BASE_URL to match your server
// ============================================================

// ── InfinityFree / RF.GD settings ─────────────────────────
// Find these in: InfinityFree Panel → MySQL Databases
define('DB_HOST',    'sql305.infinityfree.com');   // your MySQL host from panel
define('DB_USER',    'if0_41486428');               // your MySQL username
define('DB_PASS',    'YOUR_DB_PASSWORD_HERE');      // your MySQL password
define('DB_NAME',    'if0_41486428_shreeerp');      // your database name

// Installed at domain root (rf.gd), so BASE_URL is empty
define('BASE_URL',   '');

// ── XAMPP local dev (uncomment to switch back) ─────────────
// define('DB_HOST',  'localhost');
// define('DB_USER',  'root');
// define('DB_PASS',  '');
// define('DB_NAME',  'shree_label_erp');
// define('BASE_URL', '/calipot-erp/shree-label-php');

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
