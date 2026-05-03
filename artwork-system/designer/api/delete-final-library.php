<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Invalid request method.');
}

$user = getAuthUser();
if (!$user) {
    jsonResponse('error', 'Authentication required.');
}
if (($user['role'] ?? '') !== 'admin') {
    jsonResponse('error', 'Only admin can delete final files.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    jsonResponse('error', 'Invalid file record.');
}

$db = Db::getInstance();

try {
    $db->beginTransaction();

    $find = $db->prepare('SELECT id, project_id, legacy_artwork_file_id, stored_name FROM artwork_final_files WHERE id = ? AND is_active = 1 LIMIT 1');
    $find->execute([$id]);
    $row = $find->fetch();

    if (!$row) {
        throw new RuntimeException('Final file not found.');
    }

    $filePath = UPLOAD_FINAL_DIR . DIRECTORY_SEPARATOR . basename((string)$row['stored_name']);
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    $db->prepare('UPDATE artwork_final_files SET is_active = 0 WHERE id = ?')->execute([$id]);

    $legacyId = (int)($row['legacy_artwork_file_id'] ?? 0);
    if ($legacyId > 0) {
        $db->prepare('UPDATE artwork_files SET is_final = 0 WHERE id = ?')->execute([$legacyId]);
    }

    $projectId = (int)($row['project_id'] ?? 0);
    if ($projectId > 0) {
        $db->prepare('INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)')->execute([$projectId, 'Admin deleted final file from Final Artwork server.']);
    }

    $db->commit();
    jsonResponse('success', 'Final file deleted.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse('error', $e->getMessage());
}
