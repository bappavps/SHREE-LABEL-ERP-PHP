<?php
// ============================================================
// ERP System — Logout
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$logoutName = trim((string)($_SESSION['user_name'] ?? ''));
$logoutEmail = trim((string)($_SESSION['user_email'] ?? ''));
$logoutLabel = $logoutName !== '' ? $logoutName : ($logoutEmail !== '' ? $logoutEmail : 'Unknown user');

try {
	$db = getDB();
	createDepartmentNotifications(
		$db,
		['global'],
		0,
		$logoutLabel . ' logged out.',
		'info',
		'/modules/dashboard/index.php'
	);
} catch (Throwable $e) {
	// Keep logout flow uninterrupted if notification insert fails.
}

session_unset();
session_destroy();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;
