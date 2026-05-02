<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = sanitize($_POST['token']);
    $status = sanitize($_POST['status']);

    if (!in_array($status, ['approved', 'changes'])) {
        jsonResponse('error', 'Invalid status');
    }

    $db = Db::getInstance();
    
    try {
        $db->beginTransaction();
        
        // Update Project Status
        $stmt = $db->prepare("UPDATE projects SET status = ? WHERE token = ?");
        $stmt->execute([$status, $token]);
        
        // Get Project Details
        $stmt = $db->prepare("SELECT id, designer_id, title FROM projects WHERE token = ?");
        $stmt->execute([$token]);
        $project = $stmt->fetch();
        
        // Log Activity
        $action = $status === 'approved'
            ? "Artwork approved by client. Review cycle completed."
            : "Changes requested by client. Correction cycle reopened.";
        $stmt = $db->prepare("INSERT INTO activity_log (project_id, action) VALUES (?, ?)");
        $stmt->execute([$project['id'], $action]);
        
        // Notification for Designer
        $msg = "Project '" . $project['title'] . "' has been " . $status;
        $stmt = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->execute([$project['designer_id'], $msg]);
        
        $db->commit();
        jsonResponse('success', 'Status updated');
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse('error', $e->getMessage());
    }
}
?>
