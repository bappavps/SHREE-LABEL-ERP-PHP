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
    jsonResponse('error', 'Only admin can delete projects.');
}

$projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
if ($projectId <= 0) {
    jsonResponse('error', 'Invalid project id.');
}

$db = Db::getInstance();

try {
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT id, title FROM artwork_projects WHERE id = ? LIMIT 1');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        throw new RuntimeException('Project not found.');
    }

    $fileStmt = $db->prepare('SELECT filename FROM artwork_files WHERE project_id = ?');
    $fileStmt->execute([$projectId]);
    $fileRows = $fileStmt->fetchAll();

    $deleteStmt = $db->prepare('DELETE FROM artwork_projects WHERE id = ?');
    $deleteStmt->execute([$projectId]);

    $db->commit();

    foreach ($fileRows as $row) {
        $filename = (string) ($row['filename'] ?? '');
        if ($filename === '') {
            continue;
        }

        $projectPath = UPLOAD_PROJECT_DIR . '/' . $filename;
        if (is_file($projectPath)) {
            @unlink($projectPath);
        }

        $finalPath = UPLOAD_FINAL_DIR . '/project_' . $projectId . '_final_' . $filename;
        if (is_file($finalPath)) {
            @unlink($finalPath);
        }
    }

    jsonResponse('success', 'Project deleted successfully.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse('error', $e->getMessage());
}
