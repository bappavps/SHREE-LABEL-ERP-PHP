<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$row = $conn->query("SELECT * FROM products WHERE id = $id")->fetch_assoc();
if (!$row) { setFlash('error', 'Product not found.'); header('Location: index.php'); exit; }

$conn->query("UPDATE products SET status = 0 WHERE id = $id");
setFlash('success', 'Product deleted successfully.');
header('Location: index.php');
exit;
