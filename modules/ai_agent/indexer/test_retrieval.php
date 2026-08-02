<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../services/RetrievalEngine.php';

$db = getDB();
$engine = new RetrievalEngine($db);

$queries = [
    "How is plate costing calculated?",
    "What are the job planning keywords?",
    "Show me the database schema for the knowledge index"
];

echo "========================================\n";
echo "   SEMANTIC RETRIEVAL ENGINE TESTS\n";
echo "========================================\n\n";

foreach ($queries as $q) {
    echo "Query: \"$q\"\n";
    $results = $engine->search($q, 3);
    
    if (empty($results)) {
        echo "  -> No results found.\n\n";
        continue;
    }
    
    foreach ($results as $i => $r) {
        echo "  " . ($i + 1) . ". [" . $r['type'] . "] " . $r['name'] . " (Score: " . round($r['total_score'], 2) . ")\n";
        echo "     File: " . $r['file_path'] . " (Lines: " . $r['line_start'] . "-" . $r['line_end'] . ")\n";
    }
    echo "\n";
}
