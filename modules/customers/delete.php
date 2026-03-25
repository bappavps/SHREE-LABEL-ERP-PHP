<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$row = $conn->query("SELECT * FROM customers WHERE id = $id AND status = 1")->fetch_assoc();
if (!$row) { setFlash('error', 'Customer not found.'); header('Location: index.php'); exit; }

// Check if customer has orders
$orderCount = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE customer_id = $id")->fetch_assoc()['cnt'];
if ($orderCount > 0) {
    setFlash('error', 'Cannot delete customer with existing orders.');
    header('Location: index.php');
    exit;
}

$conn->query("UPDATE customers SET status = 0 WHERE id = $id");
setFlash('success', 'Customer deleted successfully.');
header('Location: index.php');
exit;
