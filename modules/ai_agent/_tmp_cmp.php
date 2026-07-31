<?php
$dir = __DIR__;
$api = file($dir . '/api.php');
$backup = file($dir . '/api_backup_plate.php');
$body = file($dir . '/plate_handler_body.php');
array_shift($body); // remove <?php
echo "api.php lines: " . count($api) . "\n";
echo "api_backup_plate.php lines: " . count($backup) . "\n";
echo "body lines (after removing <?php): " . count($body) . "\n\n";

$max = min(2326, count($api), count($backup));
$same = true;
for ($i = 0; $i < $max; $i++) {
    if (trim($api[$i]) !== trim($backup[$i])) { $same = false; echo "first diff before 2327 at line " . ($i+1) . "\n"; break; }
}
echo "Lines 0-2326 identical to backup: " . ($same ? "YES" : "NO") . "\n";

// Compare priority body region in api.php (2327..) to body
$prioStart = 2327;
$prioMatch = true;
for ($i = 0; $i < count($body); $i++) {
    $j = $prioStart + $i;
    if (!isset($api[$j]) || trim($api[$j]) !== trim($body[$i])) { $prioMatch = false; echo "  prio diff at body line " . ($i+1) . " (api line " . ($j+1) . ")\n"; break; }
}
echo "Priority region (2327+) matches body: " . ($prioMatch ? "YES" : "NO") . "\n";

// Find where MAIN body region starts in api.php (search for the body's 3rd line)
$bodyLine2 = trim($body[2] ?? '');
$mainStart = null;
for ($j = 2327 + count($body); $j < count($api); $j++) {
    if (trim($api[$j]) === $bodyLine2) { $mainStart = $j; break; }
}
echo "Main body region candidate start (0-based): " . ($mainStart ?? 'NOT FOUND') . "\n";
if ($mainStart !== null) {
    $mainMatch = true;
    for ($i = 0; $i < count($body); $i++) {
        $j = $mainStart + $i;
        if (!isset($api[$j]) || trim($api[$j]) !== trim($body[$i])) { $mainMatch = false; echo "  main diff at body line " . ($i+1) . " (api line " . ($j+1) . ")\n"; break; }
    }
    echo "Main region matches body: " . ($mainMatch ? "YES" : "NO") . "\n";
}
