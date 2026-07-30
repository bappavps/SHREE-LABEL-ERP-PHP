<?php
$lines = file('api.php');

$tmpLines = file('C:\Users\Dell\.gemini\antigravity-ide\brain\7cd0da98-0b03-45c4-bb41-cf23e289c94b\_tmp_plate_handler.php');
// Body is from line 3 to 255.
$newBody = array_slice($tmpLines, 2, count($tmpLines) - 3);

// In api.php, priority plate handler body is from 2322 to 2369.
// Index is 2321, length is 2369 - 2322 + 1 = 48.
array_splice($lines, 2321, 48, $newBody);

file_put_contents('api.php', implode('', $lines));
echo "Injected Priority Plate Handler body successfully!\n";
