<?php

require_once __DIR__ . '/Parsers/PhpParser.php';
require_once __DIR__ . '/Parsers/SqlParser.php';
require_once __DIR__ . '/Parsers/MarkdownParser.php';

$phpFile = __DIR__ . '/../services/CalculationEngine.php';
$sqlFile = __DIR__ . '/schema.sql';
$mdFile = __DIR__ . '/../JOB_PLANNING_KNOWLEDGE.md';

$phpEntities = PhpParser::parse($phpFile);
$sqlEntities = SqlParser::parse($sqlFile);
$mdEntities = MarkdownParser::parse($mdFile);

echo "========================================\n";
echo "       PHP PARSER RESULTS\n";
echo "========================================\n";
echo "Extracted " . count($phpEntities) . " entities from CalculationEngine.php\n";
foreach ($phpEntities as $e) {
    echo "- [" . $e['type'] . "] " . $e['name'] . " (Lines: " . $e['line_start'] . "-" . $e['line_end'] . ")\n";
    echo "  Signature: " . $e['signature'] . "\n";
    if (!empty($e['summary'])) {
        echo "  Summary: " . substr($e['summary'], 0, 100) . "...\n";
    }
}

echo "\n========================================\n";
echo "       SQL PARSER RESULTS\n";
echo "========================================\n";
echo "Extracted " . count($sqlEntities) . " entities from schema.sql\n";
foreach ($sqlEntities as $e) {
    echo "- [" . $e['type'] . "] " . $e['name'] . " (Lines: " . $e['line_start'] . "-" . $e['line_end'] . ")\n";
}

echo "\n========================================\n";
echo "       MARKDOWN PARSER RESULTS\n";
echo "========================================\n";
echo "Extracted " . count($mdEntities) . " entities from JOB_PLANNING_KNOWLEDGE.md\n";
foreach ($mdEntities as $e) {
    echo "- [" . $e['type'] . "] " . $e['name'] . " (Lines: " . $e['line_start'] . "-" . $e['line_end'] . ")\n";
}

echo "\n----------------------------------------\n";
echo "Total Entities Parsed: " . (count($phpEntities) + count($sqlEntities) + count($mdEntities)) . "\n";
