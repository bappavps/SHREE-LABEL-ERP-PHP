<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

session_start([
    'name' => SESSION_NAME,
    'cookie_httponly' => true,
]);

// Auth bypassed for testing
// if (!isDesigner()) die("Unauthorized");

$projectId = (int)$_GET['id'];
$db = Db::getInstance();

$stmt = $db->prepare("SELECT * FROM files WHERE project_id = ?");
$stmt->execute([$projectId]);
$files = $stmt->fetchAll();

if (empty($files)) die("No files to download");

$zip = new ZipArchive();
$zipName = "project_" . $projectId . "_files.zip";
$zipPath = sys_get_temp_dir() . "/" . $zipName;

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
    foreach ($files as $file) {
        $filePath = UPLOAD_DIR . $file['filename'];
        if (file_exists($filePath)) {
            $zip->addFile($filePath, "v" . $file['version'] . "_" . $file['original_name']);
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-disposition: attachment; filename=' . $zipName);
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    unlink($zipPath);
} else {
    die("Could not create ZIP file");
}
?>
