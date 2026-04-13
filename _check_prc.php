<?php
require_once __DIR__ . '/config/db.php';
$db = getDB();
$r = $db->query("SHOW TABLES LIKE 'paper_roll_concept'");
echo $r->num_rows > 0 ? 'EXISTS' : 'MISSING';
$r2 = $db->query("SHOW COLUMNS FROM paper_roll_concept");
if ($r2) {
    while ($col = $r2->fetch_assoc()) {
        echo "\n" . $col['Field'] . ' - ' . $col['Type'];
    }
}
