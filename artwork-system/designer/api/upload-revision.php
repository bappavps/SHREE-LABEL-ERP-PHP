<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

session_start([
    'name' => SESSION_NAME,
    'cookie_httponly' => true,
]);

// Auth bypassed for testing
// if (!isDesigner()) {
//    jsonResponse('error', 'Unauthorized');
// }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = (int)$_POST['project_id'];
    $version = (int)$_POST['version'];
    
    if (empty($_FILES['artwork']['name'])) {
        jsonResponse('error', 'No file uploaded');
    }

    $db = Db::getInstance();
    
    // Check project token for filename
    $stmt = $db->prepare("SELECT token FROM artwork_projects WHERE id = ?");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    
    if (!$project) jsonResponse('error', 'Project not found');
    
    $file = $_FILES['artwork'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'ai', 'cdr'];
    
    if (!in_array($ext, $allowed)) {
        jsonResponse('error', 'Invalid file type');
    }
    
    $filename = $project['token'] . '_v' . $version . '_' . time() . '.' . $ext;
    $uploadPath = UPLOAD_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        try {
            $db->beginTransaction();
            
            // Insert File
            $stmt = $db->prepare("INSERT INTO artwork_files (project_id, filename, original_name, file_type, version) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$projectId, $filename, $file['name'], $ext, $version]);
            
            // Update Project Status
            $stmt = $db->prepare("UPDATE artwork_projects SET status = 'pending' WHERE id = ?");
            $stmt->execute([$projectId]);
            
            // Log Activity
            $stmt = $db->prepare("INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)");
            $stmt->execute([$projectId, "Designer uploaded revision v$version (" . $file['name'] . ")."]);
            
            $db->commit();
            jsonResponse('success', 'File uploaded successfully');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse('error', $e->getMessage());
        }
    } else {
        jsonResponse('error', 'Upload failed');
    }
}
?>
