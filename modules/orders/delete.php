<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: index.php'); exit; }

// Delete related invoices first, then order items and order
$s1 = $conn->prepare("DELETE FROM invoices WHERE order_id = ?");
$s1->bind_param('i', $id);
$s1->execute();

$s2 = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
$s2->bind_param('i', $id);
$s2->execute();

$s3 = $conn->prepare("DELETE FROM orders WHERE id = ?");
$s3->bind_param('i', $id);
$s3->execute();
setFlash('success', 'Order deleted successfully.');
header('Location: index.php');
exit;
