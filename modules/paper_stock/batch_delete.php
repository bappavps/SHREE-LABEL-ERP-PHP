<?php
// ============================================================
// Shree Label ERP — Paper Stock: Batch Delete (POST + CSRF)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'Invalid request method.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid or expired session. Please try again.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

$rawIds = $_POST['ids'] ?? '';
if (is_array($rawIds)) {
    $ids = array_map('intval', $rawIds);
} else {
    $ids = array_map('intval', explode(',', (string)$rawIds));
}
$ids = array_values(array_filter($ids, function($id) { return $id > 0; }));

if (empty($ids)) {
    setFlash('error', 'No rolls were selected for deletion.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

$db = getDB();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$typesStr = str_repeat('i', count($ids));

// Count how many exist
$countStmt = $db->prepare("SELECT COUNT(*) AS c FROM paper_stock WHERE id IN ($placeholders)");
$countStmt->bind_param($typesStr, ...$ids);
$countStmt->execute();
$existCount = (int)$countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

if ($existCount === 0) {
    setFlash('error', 'No matching rolls found in the database.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

// Perform delete
$delStmt = $db->prepare("DELETE FROM paper_stock WHERE id IN ($placeholders)");
$delStmt->bind_param($typesStr, ...$ids);

if ($delStmt->execute()) {
    $deleted = $delStmt->affected_rows;
    setFlash('success', $deleted . ' roll(s) deleted successfully.');
} else {
    setFlash('error', 'Could not delete rolls: ' . $db->error);
}

$delStmt->close();
redirect(BASE_URL . '/modules/paper_stock/index.php');
