<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Invalid request method');
}

$user = getAuthUser();
if (!$user) {
    jsonResponse('error', 'Authentication required.');
}
if (($user['role'] ?? '') !== 'admin') {
    jsonResponse('error', 'Only admin can delete final projects.');
}

$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
if ($projectId <= 0 || $fileId <= 0) {
    jsonResponse('error', 'Invalid project or file id');
}

$db = Db::getInstance();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT id, filename, is_final FROM artwork_files WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$fileId, $projectId]);
    $file = $stmt->fetch();

    if (!$file) {
        throw new RuntimeException('Final file not found.');
    }

    if ((int) $file['is_final'] !== 1) {
        throw new RuntimeException('Selected file is not marked as final.');
    }

    $finalPath = UPLOAD_FINAL_DIR . '/project_' . $projectId . '_final_' . $file['filename'];
    if (is_file($finalPath)) {
        @unlink($finalPath);
    }

    $db->prepare('UPDATE artwork_files SET is_final = 0 WHERE id = ?')->execute([$fileId]);
    $db->prepare("UPDATE artwork_projects SET status = 'changes' WHERE id = ?")->execute([$projectId]);
    $db->prepare('INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)')->execute([$projectId, 'Admin deleted final approval file.']);

    $db->commit();
    jsonResponse('success', 'Final file deleted successfully.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse('error', $e->getMessage());
}
