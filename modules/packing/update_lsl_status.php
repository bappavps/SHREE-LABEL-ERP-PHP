<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Set LSL job status to indicate it's completed
$sql = "UPDATE jobs SET status = 'Completed' WHERE job_no = 'LSL/2026/0001' LIMIT 1";
$db->query($sql);

echo json_encode(['success' => true, 'message' => 'LSL job status updated to Completed']);
