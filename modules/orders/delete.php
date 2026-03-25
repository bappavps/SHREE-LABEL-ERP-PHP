<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$order = $conn->query("SELECT * FROM orders WHERE id = $id")->fetch_assoc();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: index.php'); exit; }

// Delete related invoices first, then order
$conn->query("DELETE FROM invoices WHERE order_id = $id");
$conn->query("DELETE FROM order_items WHERE order_id = $id");
$conn->query("DELETE FROM orders WHERE id = $id");
setFlash('success', 'Order deleted successfully.');
header('Location: index.php');
exit;
