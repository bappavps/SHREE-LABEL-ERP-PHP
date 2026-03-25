<?php
// ============================================================
// Shree Label ERP — Paper Stock: Delete Roll (CSRF protected)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$id   = (int)($_GET['id'] ?? 0);
$csrf = $_GET['csrf'] ?? '';

if (!$id || !verifyCSRF($csrf)) {
    setFlash('error', 'Invalid or expired request.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

$db = getDB();
$stmt = $db->prepare("SELECT roll_no FROM paper_stock WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    setFlash('error', 'Roll not found.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

$del = $db->prepare("DELETE FROM paper_stock WHERE id = ?");
$del->bind_param('i', $id);
if ($del->execute()) {
    setFlash('success', 'Roll ' . $row['roll_no'] . ' deleted.');
} else {
    setFlash('error', 'Could not delete roll: ' . $db->error);
}

redirect(BASE_URL . '/modules/paper_stock/index.php');
