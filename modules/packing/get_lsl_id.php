<?php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');

// Get job ID for LSL/2026/0001
$sql = "SELECT id, planning_id FROM jobs WHERE job_no = 'LSL/2026/0001' LIMIT 1";
$result = getDB()->query($sql);
if ($row = $result->fetch_assoc()) {
    echo json_encode(['success' => true, 'job_id' => $row['id'], 'planning_id' => $row['planning_id']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Job not found']);
}
