<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Invalid request method');
}

$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
if ($projectId <= 0) {
    jsonResponse('error', 'Invalid project');
}

$db = Db::getInstance();
$newToken = generateToken(48);

$stmt = $db->prepare('UPDATE artwork_projects SET token = ? WHERE id = ?');
$stmt->execute([$newToken, $projectId]);

if ($stmt->rowCount() < 1) {
    jsonResponse('error', 'Project not found');
}

$db->prepare('INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)')->execute([$projectId, 'Secure review link regenerated.']);

jsonResponse('success', 'Review link regenerated.', [
    'token' => $newToken,
    'review_link' => ARTWORK_BASE_URL . '/review.php?token=' . $newToken . '&view=client',
]);
