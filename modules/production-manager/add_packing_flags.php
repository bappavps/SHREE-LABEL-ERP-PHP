<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Update PCK job extra_data with packing flags
$extraData = json_encode([
    'packing_packed_flag' => 1,
    'packing_done_flag' => 1,
    'packing_done_at' => date('Y-m-d H:i:s')
]);

$sql = "UPDATE jobs SET extra_data = ?, updated_at = NOW() WHERE id = 4 AND job_no = 'PCK/2026/0001'";
$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $extraData);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'PCK job extra_data updated with packing flags']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No rows affected']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'error' => $db->error]);
}
