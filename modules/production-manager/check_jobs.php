<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check all jobs for planning_id = 1 - including deleted_at status
$sql = "SELECT id, job_no, department, job_type, status, deleted_at, updated_at FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$result = $db->query($sql);
$jobs = [];
while ($row = $result->fetch_assoc()) {
    $jobs[] = $row;
}

echo json_encode(['jobs' => $jobs, 'count' => count($jobs)], JSON_PRETTY_PRINT);
