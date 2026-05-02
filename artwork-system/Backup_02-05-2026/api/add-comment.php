<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

function hasColumn(PDO $db, string $table, string $column): bool {
    static $cache = [];

    if (!isset($cache[$table])) {
        $cache[$table] = [];
        $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['Field'])) {
                $cache[$table][$row['Field']] = true;
            }
        }
    }

    return isset($cache[$table][$column]);
}

function ensureCommentSchema(PDO $db): void {
    $requiredColumns = [
        'type' => "ALTER TABLE comments ADD COLUMN type VARCHAR(32) DEFAULT 'point' AFTER comment",
        'width' => 'ALTER TABLE comments ADD COLUMN width DECIMAL(6,2) DEFAULT NULL AFTER y_pos',
        'height' => 'ALTER TABLE comments ADD COLUMN height DECIMAL(6,2) DEFAULT NULL AFTER width',
        'drawing_data' => 'ALTER TABLE comments ADD COLUMN drawing_data LONGTEXT DEFAULT NULL AFTER height',
        'attachment' => 'ALTER TABLE comments ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER drawing_data'
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!hasColumn($db, 'comments', $column)) {
            $db->exec($sql);
        }
    }
}

function shortActivityText(string $text, int $max = 90): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 3) . '...';
}

function prettyCommentType(string $type): string {
    $map = [
        'point' => 'Pin',
        'area' => 'Area',
        'arrow' => 'Arrow',
        'pen' => 'Pen',
        'highlighter' => 'Highlight',
    ];
    return $map[$type] ?? ucfirst($type);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    appSessionStart();
    $authUser = getAuthUser();
    $actorRole = $authUser ? 'Designer' : 'Client';

    $fileId = (int)$_POST['file_id'];
    $comment = sanitize($_POST['comment']);
    $type = isset($_POST['type']) ? sanitize($_POST['type']) : 'point';
    $xPos = isset($_POST['x_pos']) && $_POST['x_pos'] !== '' ? $_POST['x_pos'] : null;
    $yPos = isset($_POST['y_pos']) && $_POST['y_pos'] !== '' ? $_POST['y_pos'] : null;
    $width = isset($_POST['width']) && $_POST['width'] !== '' ? $_POST['width'] : null;
    $height = isset($_POST['height']) && $_POST['height'] !== '' ? $_POST['height'] : null;
    $drawingData = isset($_POST['drawing_data']) ? $_POST['drawing_data'] : null;
    $parentId = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $userName = sanitize($_POST['user_name']);
    $attachment = null;

    if (empty($comment)) jsonResponse('error', 'Comment cannot be empty');

    // Handle Attachment
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        
        if (in_array($ext, $allowed, true)) {
            $filename = 'ref_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], UPLOAD_REFERENCE_DIR . '/' . $filename)) {
                $attachment = $filename;
            }
        }
    }

    $db = Db::getInstance();
    ensureCommentSchema($db);
    
    try {
        $db->beginTransaction();
        
        // Insert Comment (works with both old and new comment schemas)
        $columns = ['file_id', 'user_name', 'comment'];
        $values = [$fileId, $userName, $comment];

        if (hasColumn($db, 'comments', 'type')) {
            $columns[] = 'type';
            $values[] = $type;
        }
        if (hasColumn($db, 'comments', 'x_pos')) {
            $columns[] = 'x_pos';
            $values[] = $xPos;
        }
        if (hasColumn($db, 'comments', 'y_pos')) {
            $columns[] = 'y_pos';
            $values[] = $yPos;
        }
        if (hasColumn($db, 'comments', 'width')) {
            $columns[] = 'width';
            $values[] = $width;
        }
        if (hasColumn($db, 'comments', 'height')) {
            $columns[] = 'height';
            $values[] = $height;
        }
        if (hasColumn($db, 'comments', 'drawing_data')) {
            $columns[] = 'drawing_data';
            $values[] = $drawingData;
        }
        if (hasColumn($db, 'comments', 'attachment')) {
            $columns[] = 'attachment';
            $values[] = $attachment;
        }
        if (hasColumn($db, 'comments', 'parent_id')) {
            $columns[] = 'parent_id';
            $values[] = $parentId;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO comments (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
        
        // Get Project ID for notification and activity
        $stmt = $db->prepare("SELECT project_id FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $projectId = $stmt->fetchColumn();
        
        // Log Activity
        if ($parentId) {
            $actionMsg = "$actorRole replied by $userName: " . shortActivityText($comment);
        } else {
            $actionMsg = "$actorRole correction added (" . prettyCommentType($type) . ") by $userName: " . shortActivityText($comment);
        }
        $stmt = $db->prepare("INSERT INTO activity_log (project_id, action) VALUES (?, ?)");
        $stmt->execute([$projectId, $actionMsg]);
        
        // Create Notification for Designer
        $stmt = $db->prepare("SELECT designer_id FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $designerId = $stmt->fetchColumn();
        
        $stmt = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->execute([$designerId, "New activity on project from $userName"]);
        
        $db->commit();
        jsonResponse('success', 'Comment added');
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse('error', $e->getMessage());
    }
}
?>
