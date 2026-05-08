<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check ALL jobs for planning 1
$sql = "SELECT id, job_no, department, status FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$result = $db->query($sql);
$allJobs = [];
while ($row = $result->fetch_assoc()) {
    $allJobs[] = $row;
}

// Check specifically for packing jobs
$sql2 = "SELECT id, job_no, department FROM jobs WHERE planning_id = 1 AND (department = 'packing' OR department = 'packaging' OR job_no LIKE 'PCK/%') ORDER BY id DESC";
$result2 = $db->query($sql2);
$packingJobs = [];
while ($row = $result2->fetch_assoc()) {
    $packingJobs[] = $row;
}

echo json_encode(['all_jobs' => $allJobs, 'packing_jobs' => $packingJobs]);
