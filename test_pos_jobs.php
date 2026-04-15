<?php
require_once 'config/db.php';
$db = getDB();

echo "=== All Departments and Statuses ===\n";
$result = $db->query('SELECT DISTINCT department, status, COUNT(*) as cnt FROM jobs WHERE (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00") GROUP BY department, status ORDER BY department, status');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo trim($row['department'] ?? 'NULL') . ' | ' . trim($row['status'] ?? 'NULL') . ' | Count: ' . $row['cnt'] . "\n";
    }
} else {
    echo "Query error\n";
}

echo "\n=== POS Roll Specifically (Complete status) ===\n";
$result2 = $db->query('SELECT COUNT(*) as cnt FROM jobs WHERE LOWER(TRIM(COALESCE(department, ""))) IN ("pos", "pos roll", "pos_roll") AND status = "Complete" AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00")');
if ($result2) {
    $row = $result2->fetch_assoc();
    echo "POS Complete: " . $row['cnt'] . "\n";
}

echo "\n=== POS Roll All Statuses ===\n";
$result3 = $db->query('SELECT status, COUNT(*) as cnt FROM jobs WHERE LOWER(TRIM(COALESCE(department, ""))) IN ("pos", "pos roll", "pos_roll") AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00") GROUP BY status');
if ($result3) {
    while ($row = $result3->fetch_assoc()) {
        echo "Status: " . trim($row['status'] ?? 'NULL') . ' | Count: ' . $row['cnt'] . "\n";
    }
}
?>
