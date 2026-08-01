<?php
$prompt = 'hum ko "aleaxa" printing korata hai 5000 pcs, kita paper lagela?';
$p = mb_strtolower($prompt, 'UTF-8');

$isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || (strpos($p, 'print') !== false && strpos($p, 'koto') === false && strpos($p, 'কত') === false && strpos($p, 'required') === false && strpos($p, 'need') === false && strpos($p, 'how many') === false && strpos($p, 'korechi') === false && strpos($p, 'hoyeche') === false && strpos($p, 'kora') === false && strpos($p, 'amra') === false && strpos($p, 'have we') === false && strpos($p, 'kita') === false && strpos($p, 'kitna') === false && strpos($p, 'lagela') === false && strpos($p, 'chahiye') === false));

var_dump($isExportQuery);

$c1 = strpos($p, 'print');
$c2 = strpos($p, 'kora');
$c3 = strpos($p, 'kita');
$c4 = strpos($p, 'lagela');

var_dump($c1, $c2, $c3, $c4);
