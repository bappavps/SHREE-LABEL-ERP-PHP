<?php
require_once 'config/db.php';

echo "=== JOBS FOR PLANNING_ID=1 ===\n";
$sql = "SELECT id, job_no, department, status FROM jobs WHERE planning_id = 1 ORDER BY id";
$res = mysqli_query($connection, $sql);
while($r = mysqli_fetch_assoc($res)) {
    echo "Job: {$r['job_no']} | Dept: {$r['department']} | Status: {$r['status']}\n";
}
