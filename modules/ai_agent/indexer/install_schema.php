<?php
// Script to safely install AI Indexer database tables

require_once __DIR__ . '/../../../config/db.php';

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    die("Error: schema.sql not found\n");
}

$sqlCommands = file_get_contents($sqlFile);

$db = getDB();

if ($db->multi_query($sqlCommands)) {
    do {
        if ($res = $db->store_result()) {
            $res->free();
        }
    } while ($db->more_results() && $db->next_result());
    echo "AI Indexer Schema Installed Successfully.\n";
} else {
    echo "Error installing schema: " . $db->error . "\n";
}
