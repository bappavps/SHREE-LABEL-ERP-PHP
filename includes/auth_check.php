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

$currentTenantSlug = defined('TENANT_SLUG') ? (string)TENANT_SLUG : 'default';
$currentTenantName = defined('TENANT_NAME') ? (string)TENANT_NAME : APP_NAME;

if (defined('TENANT_ACTIVE') && !TENANT_ACTIVE) {
    if (function_exists('setFlash')) {
        setFlash('error', 'This company workspace is currently inactive. Please contact support.');
    }
    $_SESSION = [];
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

if (empty($_SESSION['tenant_slug'])) {
    $_SESSION['tenant_slug'] = $currentTenantSlug;
    $_SESSION['tenant_name'] = $currentTenantName;
} elseif ((string)$_SESSION['tenant_slug'] !== $currentTenantSlug) {
    if (function_exists('setFlash')) {
        setFlash('error', 'Your session belongs to a different company workspace. Please sign in again.');
    }
    $_SESSION = [];
    session_destroy();
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
