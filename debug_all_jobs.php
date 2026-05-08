<?php
require_once 'config/db.php';

echo "=== ALL JOBS FOR PLANNING_ID=1 ===\n";
$sql = "SELECT id, job_no, department, status, extra_data FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$res = mysqli_query($connection, $sql);
$jobCount = 0;
while($r = mysqli_fetch_assoc($res)) {
    $jobCount++;
    $extra = json_decode($r['extra_data'], true);
    echo "Job $jobCount: {$r['job_no']} | Dept: {$r['department']} | Status: {$r['status']}\n";
    if ($extra && isset($extra['packing_packed_flag'])) {
        echo "  -> packing_packed_flag: " . $extra['packing_packed_flag'] . "\n";
    }
    if ($extra && isset($extra['packing_done_flag'])) {
        echo "  -> packing_done_flag: " . $extra['packing_done_flag'] . "\n";
    }
}
echo "Total jobs: $jobCount\n";
