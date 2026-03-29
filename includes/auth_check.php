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

if (function_exists('ensureRbacSchema')) {
    ensureRbacSchema();
}

if (function_exists('canAccessCurrentPage') && !canAccessCurrentPage()) {
    if (function_exists('setFlash')) {
        setFlash('error', 'Access denied for this page. Please contact admin.');
    }
    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    exit;
}
