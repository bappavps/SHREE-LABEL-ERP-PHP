<?php
require_once 'config/db.php';

echo "=== JOBS FOR PLN/2026/0001 (planning_id=1) ===\n";
$sql = "SELECT id, job_no, department, status, extra_data FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$res = mysqli_query($connection, $sql);
while($r = mysqli_fetch_assoc($res)) {
    $extra = json_decode($r['extra_data'], true);
    echo "Job: {$r['job_no']} | Status: {$r['status']} | Dept: {$r['department']}";
    if ($extra) {
        echo " | finished_production_flag: " . ($extra['finished_production_flag'] ?? 'NULL');
        echo " | packing_done_flag: " . ($extra['packing_done_flag'] ?? 'NULL');
    }
    echo "\n";
}

echo "\n=== PLANNING ROW FOR ID=1 ===\n";
$sql2 = "SELECT id, job_no, department, status FROM planning WHERE id = 1";
$res2 = mysqli_query($connection, $sql2);
$p = mysqli_fetch_assoc($res2);
echo "Planning ID: {$p['id']} | Job No: {$p['job_no']} | Dept: {$p['department']} | Status: {$p['status']}\n";

echo "\n=== PACKING OPERATOR ENTRIES FOR PLN/2026/0001 ===\n";
$sql3 = "SELECT id, job_id, department, packing_done FROM packing_operator_entries WHERE job_id IN (SELECT id FROM jobs WHERE planning_id = 1)";
$res3 = mysqli_query($connection, $sql3);
while($r3 = mysqli_fetch_assoc($res3)) {
    echo "Entry: {$r3['id']} | Job: {$r3['job_id']} | Dept: {$r3['department']} | Packing Done: {$r3['packing_done']}\n";
}
