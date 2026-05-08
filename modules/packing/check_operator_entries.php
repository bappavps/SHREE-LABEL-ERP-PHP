<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check operator entries
$sql = "SELECT id, job_id, job_no, planning_id, submitted_at FROM packing_operator_entries LIMIT 10";
$result = $db->query($sql);
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode(['operator_entries' => $rows]);
