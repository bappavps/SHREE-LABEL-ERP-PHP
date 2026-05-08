<?php
require_once 'config/db.php';
$db = getDB();

// Check LSL job extra_data
$sql = "SELECT id, job_no, department, job_type, status, deleted_at, extra_data FROM jobs WHERE id = 3";
$result = $db->query($sql);
$job = $result->fetch_assoc();

if ($job) {
    $extra = json_decode($job['extra_data'] ?? '{}', true);
    echo json_encode([
        'job' => [
            'id' => $job['id'],
            'job_no' => $job['job_no'],
            'department' => $job['department'],
            'job_type' => $job['job_type'],
            'status' => $job['status'],
            'deleted_at' => $job['deleted_at'],
        ],
        'extra' => $extra
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode(['error' => 'Job not found']);
}
