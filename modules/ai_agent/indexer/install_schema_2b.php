<?php
require_once __DIR__ . '/../../../config/db.php';
$db = getDB();

$sqlFile = __DIR__ . '/schema_2b.sql';
$sqlCommands = explode(";", file_get_contents($sqlFile));

foreach ($sqlCommands as $cmd) {
    $cmd = trim($cmd);
    if ($cmd !== '') {
        // Ignore errors if the index already exists
        $db->query($cmd);
    }
}
echo "Phase 2B FULLTEXT schema update completed.\n";
