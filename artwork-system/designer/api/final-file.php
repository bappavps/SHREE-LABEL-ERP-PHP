<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

$user = getAuthUser();
if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode = strtolower((string)($_GET['mode'] ?? 'preview'));
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid file id.');
}
if ($mode !== 'preview' && $mode !== 'download') {
    $mode = 'preview';
}

$db = Db::getInstance();
$stmt = $db->prepare('SELECT id, original_name, stored_name, mime_type FROM artwork_final_files WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$storedName = basename((string)$file['stored_name']);
$path = UPLOAD_FINAL_DIR . DIRECTORY_SEPARATOR . $storedName;
if (!is_file($path)) {
    http_response_code(404);
    exit('Stored file missing.');
}

$mime = (string)($file['mime_type'] ?? 'application/pdf');
$downloadName = basename((string)$file['original_name']);
if ($downloadName === '') {
    $downloadName = $storedName;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');

if ($mode === 'download') {
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
} else {
    header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
}

readfile($path);
exit;
