<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../services/RetrievalEngine.php';
require_once __DIR__ . '/../services/ContextBuilder.php';

$db = getDB();
$engine = new RetrievalEngine($db);

$query = "How is plate costing calculated?";
echo "Executing search for: \"$query\"\n\n";

$rawResults = $engine->search($query, 5);

echo "--- RAW RETRIEVAL RESULTS (Count: " . count($rawResults) . ") ---\n";
// (We will omit printing raw array to save space)

echo "\n--- CONTEXT BUILDER OUTPUT (Max 1500 chars) ---\n";
$contextString = ContextBuilder::build($rawResults, 1500, 1.0);

echo $contextString . "\n";
echo "\nTotal String Length: " . strlen($contextString) . " chars\n";
