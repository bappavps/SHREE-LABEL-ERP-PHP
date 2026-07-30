<?php
$conn = new mysqli('localhost', 'root', '', 'shree_label_erp');
$result = $conn->query('SELECT id, category, keywords, is_active FROM ai_agent_knowledge WHERE id=8');
if($result) {
    while($row = $result->fetch_assoc()) {
        echo 'ID: ' . $row['id'] . PHP_EOL;
        echo 'Category: ' . $row['category'] . PHP_EOL;
        echo 'Keywords: ' . $row['keywords'] . PHP_EOL;
        echo 'Active: ' . ($row['is_active'] ? 'Yes' : 'No') . PHP_EOL;
    }
} else {
    echo 'Error: ' . $conn->error . PHP_EOL;
}
?>