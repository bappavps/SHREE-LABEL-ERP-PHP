<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$token  = isset($_GET['token'])   ? sanitize($_GET['token']) : '';

if (!$fileId || !$token) {
    jsonResponse('error', 'Missing parameters');
}

$db = Db::getInstance();

// Verify the token belongs to the project that owns this file
$stmt = $db->prepare(
    "SELECT f.id FROM files f
     JOIN projects p ON p.id = f.project_id
     WHERE f.id = ? AND p.token = ?"
);
$stmt->execute([$fileId, $token]);
if (!$stmt->fetch()) {
    jsonResponse('error', 'Access denied');
}

// Fetch top-level comments
$stmt = $db->prepare(
    "SELECT id, user_name, comment, type, x_pos, y_pos, width, height,
            drawing_data, attachment, created_at
     FROM comments
     WHERE file_id = ? AND parent_id IS NULL
     ORDER BY created_at ASC"
);
$stmt->execute([$fileId]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch replies for each comment
foreach ($comments as &$c) {
    if (empty($c['type']) && !empty($c['drawing_data'])) {
        $decoded = json_decode((string) $c['drawing_data'], true);
        if (is_array($decoded) && !empty($decoded['tool'])) {
            $tool = strtolower((string) $decoded['tool']);
            if (in_array($tool, ['point', 'area', 'arrow', 'pen', 'highlighter'], true)) {
                $c['type'] = $tool;
            }
        }
    }

    $stmt2 = $db->prepare(
        "SELECT id, user_name, comment, created_at
         FROM comments WHERE parent_id = ? ORDER BY created_at ASC"
    );
    $stmt2->execute([$c['id']]);
    $c['replies'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}
unset($c);

jsonResponse('success', 'OK', ['comments' => $comments]);
?>
