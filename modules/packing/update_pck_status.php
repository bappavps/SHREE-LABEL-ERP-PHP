<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Update PCK job to Packed status
$sql = "UPDATE jobs SET status = 'Packed' WHERE job_no = 'PCK/2026/0001' LIMIT 1";
$db->query($sql);

// Also set the packing flags
$extraData = json_encode(['packing_packed_flag' => 1, 'packing_done_flag' => 1, 'packing_done_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
$stmt = $db->prepare("UPDATE jobs SET extra_data = ? WHERE job_no = 'PCK/2026/0001' LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $extraData);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true, 'message' => 'PCK job updated to Packed status']);
