<?php
$c = file_get_contents('plate_handler_body.php');
$c = str_replace(
    '$jobSearchExplicit = trim($m[1] ?: $m[2]);',
    '$jobSearchExplicit = trim($m[1] ?: $m[2]); if (preg_match(\'/[a-zA-Z]\d|\d[a-zA-Z]/\', $jobSearchExplicit)) { $jobSearchExplicit = preg_replace(\'/([a-zA-Z])(\d)/\', \'$1 $2\', $jobSearchExplicit); $jobSearchExplicit = preg_replace(\'/(\d)([a-zA-Z])/\', \'$1 $2\', $jobSearchExplicit); }',
    $c
);
file_put_contents('plate_handler_body.php', $c);
echo "Updated plate_handler_body.php\n";
