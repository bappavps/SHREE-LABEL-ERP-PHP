<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check table structure
$sql = "DESCRIBE jobs";
$result = $db->query($sql);
$fields = [];
while ($row = $result->fetch_assoc()) {
    if ($row['Field'] === 'status' || $row['Field'] === 'id' || $row['Field'] === 'job_no') {
        $fields[] = $row;
    }
}

echo json_encode(['fields' => $fields], JSON_PRETTY_PRINT);
