<?php
// ============================================================
// Shree Label ERP — Logout
// ============================================================
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
