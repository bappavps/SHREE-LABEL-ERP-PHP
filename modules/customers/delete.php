<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND status = 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) { setFlash('error', 'Customer not found.'); header('Location: index.php'); exit; }

// Check if customer has orders
$stmt2 = $conn->prepare("SELECT COUNT(*) as cnt FROM orders WHERE customer_id = ?");
$stmt2->bind_param('i', $id);
$stmt2->execute();
$orderCount = $stmt2->get_result()->fetch_assoc()['cnt'];
if ($orderCount > 0) {
    setFlash('error', 'Cannot delete customer with existing orders.');
    header('Location: index.php');
    exit;
}

$stmt3 = $conn->prepare("UPDATE customers SET status = 0 WHERE id = ?");
$stmt3->bind_param('i', $id);
$stmt3->execute();
setFlash('success', 'Customer deleted successfully.');
header('Location: index.php');
exit;
