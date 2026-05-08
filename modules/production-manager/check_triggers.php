<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Check what triggers exist on jobs table
$sql = "SELECT TRIGGER_NAME, TRIGGER_SCHEMA, EVENT_MANIPULATION, ACTION_STATEMENT FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = 'shree_label_erp' AND EVENT_OBJECT_TABLE = 'jobs'";
$result = $db->query($sql);
$triggers = [];
while ($row = $result->fetch_assoc()) {
    $triggers[] = $row;
}

echo json_encode(['triggers' => $triggers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
