<?php
$prompt = 'P-1234 প্লেটের সাইজ কত?';
$p = mb_strtolower($prompt, 'UTF-8');
$commandType = null;
$isOtherModuleQueryPlate = false;
$isPlateQuery = !$isOtherModuleQueryPlate && ($commandType === 'plate' || strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'die') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'সিলিন্ডার') !== false || strpos($p, 'রিপিট') !== false || strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false || strpos($p, 'ups') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কত') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false || strpos($p, 'কয়টি') !== false || strpos($p, 'পাবো') !== false || strpos($p, 'কী') !== false || strpos($p, 'কি') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'মিটার') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || strpos($p, 'label') !== false || strpos($p, 'লেবেল') !== false || preg_match('/\b(run|paper)\b/', $p))));

var_dump($isPlateQuery);
