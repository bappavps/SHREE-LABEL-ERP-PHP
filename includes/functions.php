<?php
require_once __DIR__ . '/../config/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . getBaseUrl() . '/login.php');
        exit;
    }
}

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // Find the application root by removing module subdirectory paths
    $path = rtrim($scriptDir, '/');
    // Walk up from modules/xxx to root
    $path = preg_replace('#/modules/[^/]*$#', '', $path);
    $path = preg_replace('#/modules$#', '', $path);
    return $protocol . '://' . $host . $path;
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    $conn = db();
    $id = (int)$_SESSION['user_id'];
    $result = $conn->query("SELECT id, name, email, role FROM users WHERE id = $id AND status = 1");
    return $result ? $result->fetch_assoc() : null;
}

function generateOrderNumber() {
    $conn = db();
    $year = date('Y');
    $month = date('m');
    $result = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE YEAR(order_date) = $year AND MONTH(order_date) = $month");
    $row = $result->fetch_assoc();
    $seq = ($row['cnt'] ?? 0) + 1;
    return 'ORD-' . $year . $month . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function generateInvoiceNumber() {
    $conn = db();
    $year = date('Y');
    $month = date('m');
    $result = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE YEAR(invoice_date) = $year AND MONTH(invoice_date) = $month");
    $row = $result->fetch_assoc();
    $seq = ($row['cnt'] ?? 0) + 1;
    return 'INV-' . $year . $month . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function formatCurrency($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
