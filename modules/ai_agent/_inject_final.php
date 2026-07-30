<?php
$lines = file('api_backup_plate.php');
$newBodyLines = file('plate_handler_body.php');
// Remove <?php from body
array_shift($newBodyLines);

// 1. Replace MAIN plate handler body FIRST (from index 3290, length 469)
array_splice($lines, 3290, 469, $newBodyLines);

// 2. Replace PRIORITY plate handler body (from index 2327, length 48)
array_splice($lines, 2327, 48, $newBodyLines);

file_put_contents('api.php', implode('', $lines));
echo "Injected completely!\n";
