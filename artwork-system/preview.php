<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Db.php';

$fileId = (int) ($_GET['id'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    exit('Invalid preview request');
}

$db = Db::getInstance();
$stmt = $db->prepare('SELECT filename FROM artwork_files WHERE id = ? LIMIT 1');
$stmt->execute([$fileId]);
$row = $stmt->fetch();

if (!$row || empty($row['filename'])) {
    http_response_code(404);
    exit('File not found');
}

$filename = basename((string) $row['filename']);
$ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    http_response_code(415);
    exit('Only PDF preview is supported');
}

$path = UPLOAD_PROJECT_DIR . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

$bytes = file_get_contents($path);
if ($bytes === false) {
    http_response_code(500);
    exit(json_encode(['status' => 'error', 'message' => 'Unable to read file']));
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'bytes' => base64_encode($bytes),
        'name' => $filename,
    ],
]);
exit;
