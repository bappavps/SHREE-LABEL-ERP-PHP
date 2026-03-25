<?php
// ============================================================
// ERP System — Authentication Guard
// Include at the top of every protected page.
// Requires db.php to be loaded first (for BASE_URL).
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}
