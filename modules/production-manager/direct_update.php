<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Direct update
$result = $db->query("UPDATE jobs SET status = 'Packed', updated_at = NOW() WHERE id = 4");
echo "Update result: ";
var_dump($result);
echo "Affected rows: " . $db->affected_rows . "\n";

// Check afterward
$check = $db->query("SELECT id, job_no, status, updated_at FROM jobs WHERE id = 4");
$row = $check->fetch_assoc();
echo json_encode($row, JSON_PRETTY_PRINT);
