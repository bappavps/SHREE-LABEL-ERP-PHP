<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) { setFlash('error', 'Product not found.'); header('Location: index.php'); exit; }

$stmtDel = $conn->prepare("UPDATE products SET status = 0 WHERE id = ?");
$stmtDel->bind_param('i', $id);
$stmtDel->execute();
setFlash('success', 'Product deleted successfully.');
header('Location: index.php');
exit;
