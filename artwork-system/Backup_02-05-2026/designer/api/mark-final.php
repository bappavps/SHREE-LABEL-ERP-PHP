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
    jsonResponse('error', 'Only admin can mark final files.');
}

$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;

if ($projectId <= 0) {
    jsonResponse('error', 'Invalid project');
}

$db = Db::getInstance();

try {
    $db->beginTransaction();

    if ($fileId <= 0) {
        $stmt = $db->prepare('SELECT id FROM files WHERE project_id = ? ORDER BY version DESC LIMIT 1');
        $stmt->execute([$projectId]);
        $fileId = (int) $stmt->fetchColumn();
    }

    if ($fileId <= 0) {
        throw new RuntimeException('No file found to mark final.');
    }

    $stmt = $db->prepare('SELECT filename, original_name, version FROM files WHERE id = ? AND project_id = ?');
    $stmt->execute([$fileId, $projectId]);
    $file = $stmt->fetch();
    if (!$file) {
        throw new RuntimeException('File not found.');
    }

    $source = UPLOAD_PROJECT_DIR . '/' . $file['filename'];
    if (!file_exists($source)) {
        throw new RuntimeException('Source artwork is missing on server.');
    }

    $finalFilename = 'project_' . $projectId . '_final_' . basename($file['filename']);
    $finalPath = UPLOAD_FINAL_DIR . '/' . $finalFilename;
    if (!copy($source, $finalPath)) {
        throw new RuntimeException('Unable to copy final file.');
    }

    $db->prepare('UPDATE files SET is_final = 0 WHERE project_id = ?')->execute([$projectId]);
    $db->prepare('UPDATE files SET is_final = 1 WHERE id = ?')->execute([$fileId]);
    $db->prepare("UPDATE projects SET status = 'approved' WHERE id = ?")->execute([$projectId]);

    $log = $db->prepare('INSERT INTO activity_log (project_id, action) VALUES (?, ?)');
    $log->execute([$projectId, 'Admin marked final approval file: v' . (int) $file['version'] . ' (' . $file['original_name'] . ').']);

    $db->commit();

    jsonResponse('success', 'Final file marked successfully.', [
        'final_file' => 'uploads/final/' . $finalFilename,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse('error', $e->getMessage());
}
