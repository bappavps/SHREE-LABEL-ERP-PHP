<?php
require_once __DIR__ . '/../../../config/db.php';
$db = getDB();
$sql = "SELECT id FROM ai_knowledge_entities WHERE MATCH(name, signature, summary) AGAINST('*' IN BOOLEAN MODE)";
$res = $db->query($sql);
if (!$res) {
    echo "SQL ERROR: " . $db->error . "\n";
} else {
    echo "Rows: " . $res->num_rows . "\n";
}
