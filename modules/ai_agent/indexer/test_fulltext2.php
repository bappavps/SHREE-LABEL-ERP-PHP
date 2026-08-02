<?php
require_once __DIR__ . '/../../../config/db.php';
$db = getDB();

$q = "+plate* +costing*";

$sql = "
    SELECT e.id, e.name, 
           (MATCH(e.name, e.signature, e.summary) AGAINST(? IN BOOLEAN MODE) * 2) AS score_entity,
           (SELECT IFNULL(MAX(MATCH(k.keyword) AGAINST(? IN BOOLEAN MODE)), 0) FROM ai_entity_keywords k WHERE k.entity_id = e.id) AS score_keyword
    FROM ai_knowledge_entities e
    HAVING (score_entity + score_keyword) > 0
    ORDER BY (score_entity + score_keyword) DESC
    LIMIT 3
";
$stmt = $db->prepare($sql);
$stmt->bind_param('ss', $q, $q);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    print_r($row);
}
if($db->error) echo 'DB ERROR: ' . $db->error . "\n";
