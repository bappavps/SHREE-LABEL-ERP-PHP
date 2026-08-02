<?php
require_once __DIR__ . '/../../../config/db.php';
$db = getDB();
$res = $db->query("SELECT id, name FROM ai_knowledge_entities WHERE MATCH(name, signature, summary) AGAINST('+plate* +costing*' IN BOOLEAN MODE)");
if($db->error) {
    echo 'DB ERROR: ' . $db->error . "\n";
} else {
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
}
