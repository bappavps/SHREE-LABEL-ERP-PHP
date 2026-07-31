<?php
// Accurate brace counting using token_get_all (ignores strings/comments properly)
function braceNet($code) {
    $tokens = token_get_all($code);
    $net = 0;
    foreach ($tokens as $t) {
        if (is_array($t)) continue; // skip T_* tokens (strings, comments, etc.)
        if ($t === '{') $net++;
        elseif ($t === '}') $net--;
    }
    return $net;
}

$backup = file('api_backup_plate.php');
$body = file('plate_handler_body.php');
array_shift($body); // remove <?php
$bodyCode = implode('', $body);

echo "backup lines: " . count($backup) . "\n";
echo "body lines: " . count($body) . " | body brace net = " . braceNet($bodyCode) . "\n";

// Priority splice: index 2327 len 48
$priBefore = implode('', array_slice($backup, 0, 2327));
$priLeft   = implode('', array_slice($backup, 2327 + 48));
echo "PRIORITY before net = " . braceNet($priBefore) . " | leftover net = " . braceNet($priLeft) . " | combined need body net = " . (-braceNet($priBefore) - braceNet($priLeft)) . "\n";

// Main splice: index 3290 len 469
$mainBefore = implode('', array_slice($backup, 0, 3290));
$mainLeft   = implode('', array_slice($backup, 3290 + 469));
echo "MAIN before net = " . braceNet($mainBefore) . " | leftover net = " . braceNet($mainLeft) . " | combined need body net = " . (-braceNet($mainBefore) - braceNet($mainLeft)) . "\n";

// Sanity: full backup net (should be 0)
echo "FULL backup net = " . braceNet(implode('', $backup)) . "\n";
