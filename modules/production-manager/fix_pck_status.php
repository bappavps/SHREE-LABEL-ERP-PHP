<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Set PCK job status to Packed
$sql = "UPDATE jobs SET status = 'Packed' WHERE id = 4 AND job_no = 'PCK/2026/0001'";
if ($db->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'PCK job updated to Packed status']);
} else {
    echo json_encode(['success' => false, 'error' => $db->error]);
}
