<?php
require_once 'config/db.php';
$db = getDB();

// Restore LSL - clear deleted_at
$sql = "UPDATE jobs SET deleted_at = NULL WHERE id = 3";
if ($db->query($sql)) {
    echo "✓ LSL restored. deleted_at set to NULL.\n";
} else {
    echo "ERROR: " . $db->error;
}
