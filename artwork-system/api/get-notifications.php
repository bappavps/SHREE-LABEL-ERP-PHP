<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = Db::getInstance();
$designer = getCurrentDesigner($db);
$userId = (int) $designer['id'];

$stmt = $db->prepare("SELECT COUNT(*) FROM artwork_notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$userId]);
$unreadCount = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT id, message, is_read, created_at FROM artwork_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 12");
$stmt->execute([$userId]);
$items = $stmt->fetchAll();

jsonResponse('success', 'Notifications fetched', [
	'unread_count' => (int) $unreadCount,
	'items' => $items,
]);
?>
