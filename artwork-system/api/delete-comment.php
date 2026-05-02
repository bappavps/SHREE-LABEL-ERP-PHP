<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Invalid request method');
}

$commentId = isset($_POST['comment_id']) ? (int) $_POST['comment_id'] : 0;
$token = isset($_POST['token']) ? sanitize($_POST['token']) : '';

if ($commentId <= 0 || $token === '') {
    jsonResponse('error', 'Missing comment or token.');
}

$db = Db::getInstance();

try {
    $db->beginTransaction();

    $stmt = $db->prepare(
        'SELECT c.id, c.comment, f.project_id
         FROM artwork_comments c
         INNER JOIN artwork_files f ON f.id = c.file_id
         INNER JOIN artwork_projects p ON p.id = f.project_id
         WHERE c.id = ? AND p.token = ?'
    );
    $stmt->execute([$commentId, $token]);
    $comment = $stmt->fetch();

    if (!$comment) {
        throw new RuntimeException('Comment not found.');
    }

    $deleteReply = $db->prepare('DELETE FROM artwork_comments WHERE parent_id = ?');
    $deleteReply->execute([$commentId]);

    $deleteComment = $db->prepare('DELETE FROM artwork_comments WHERE id = ?');
    $deleteComment->execute([$commentId]);

    $deletedText = trim(preg_replace('/\s+/', ' ', (string) $comment['comment']));
    if (strlen($deletedText) > 90) {
        $deletedText = substr($deletedText, 0, 87) . '...';
    }
    $log = $db->prepare('INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)');
    $log->execute([(int) $comment['project_id'], 'Correction deleted: ' . $deletedText]);

    $db->commit();
    jsonResponse('success', 'Comment deleted successfully.');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse('error', $e->getMessage());
}
