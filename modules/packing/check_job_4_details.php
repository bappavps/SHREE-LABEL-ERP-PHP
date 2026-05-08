<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check job 4 (PCK job)
$sql = "SELECT id, job_no, department, job_type, status, planning_id FROM jobs WHERE id = 4 LIMIT 1";
$result = $db->query($sql);
$job = $result->fetch_assoc();

// Also check packing_operator_entries for this job
$sql2 = "SELECT * FROM packing_operator_entries WHERE job_id = 4 LIMIT 1";
$result2 = $db->query($sql2);
$opEntry = $result2->fetch_assoc();

echo json_encode(['job' => $job, 'operator_entry' => $opEntry]);
