<?php
$prompt = 'hum ko "aleaxa" printing korata hai 5000 pcs, kita paper lagela?';
$p = mb_strtolower($prompt, 'UTF-8');

$isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || (strpos($p, 'print') !== false && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many') && !strpos($p, 'korechi') && !strpos($p, 'hoyeche') && !strpos($p, 'kora') && !strpos($p, 'amra') && !strpos($p, 'have we')));

echo "isExportQuery: " . ($isExportQuery ? 'true' : 'false') . "\n";

echo "strpos print: " . (strpos($p, 'print') !== false ? 'true' : 'false') . "\n";
echo "strpos kora: " . (strpos($p, 'kora') !== false ? 'true' : 'false') . "\n";
