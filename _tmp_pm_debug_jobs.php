<?php
require_once __DIR__ . '/config/db.php';

$db = getDB();
$planningId = 1;

echo "Planning ID: {$planningId}\n";

$res = $db->query("SELECT id, job_no, department, job_type, status, updated_at FROM jobs WHERE planning_id = {$planningId} ORDER BY id ASC");
if ($res instanceof mysqli_result) {
    while ($row = $res->fetch_assoc()) {
        echo 'ID=' . ($row['id'] ?? '')
            . ' | JOB=' . ($row['job_no'] ?? '')
            . ' | DEPT=' . ($row['department'] ?? '')
            . ' | TYPE=' . ($row['job_type'] ?? '')
            . ' | STATUS=' . ($row['status'] ?? '')
            . ' | UPDATED=' . ($row['updated_at'] ?? '')
            . "\n";
    }
    $res->close();
}
