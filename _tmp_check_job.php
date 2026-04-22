<?php
require 'config/db.php';
$db = getDB();
$r = $db->query("SHOW COLUMNS FROM jobs");
$cols = [];
while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
echo "jobs columns: " . implode(", ", $cols) . "\n";
$r2 = $db->query("SELECT * FROM jobs WHERE job_no='POS/2026/0001' LIMIT 1");
$row = $r2->fetch_assoc();
echo "job fields: ";
echo json_encode($row, JSON_PRETTY_PRINT);
echo "\n";
$pid = $row['planning_id'] ?? null;
if ($pid) {
    $r3 = $db->query("SELECT * FROM planning WHERE id=$pid LIMIT 1");
    $row3 = $r3->fetch_assoc();
    echo "planning: ";
    echo json_encode($row3, JSON_PRETTY_PRINT);
} else { echo "No planning_id.\n"; }
