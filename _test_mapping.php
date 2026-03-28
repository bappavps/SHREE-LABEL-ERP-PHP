<?php
// Test script to check Excel import parsing

// Simulate the row aliases mapping from import_rows
$aliases = [
    'order_date' => ['order_date', 'order date'],
    'dispatch_date' => ['dispatch_date', 'dispatch date'],
    'printing_planning' => ['printing_planning', 'status'],
    'plate_no' => ['plate_no', 'plate no'],
    'name' => ['name', 'job name', 'job_name'],
    'size' => ['size'],
    'repeat' => ['repeat'],
    'material' => ['material'],
    'paper_size' => ['paper_size', 'paper size'],
    'die' => ['die'],
    'allocate_mtrs' => ['allocate_mtrs', 'mtrs', 'allocate mtrs'],
    'qty_pcs' => ['qty_pcs', 'qty'],
    'core_size' => ['core_size', 'core', 'core size'],
    'qty_per_roll' => ['qty_per_roll', 'qty/roll', 'qty per roll'],
    'roll_direction' => ['roll_direction', 'direction', 'roll direction'],
    'remarks' => ['remarks', 'notes']
];

// Simulate Excel headers (as they would be normalized by JavaScript)
$headers = [
    's.n',
    'order date',
    'dispatch date',
    'printing planning',  // or "printing planing" - check exact text
    'plate no.',
    'name',
    'size',
    'repeat',
    'material',
    'paper size',  // Note: Excel has "PAPER\nSIZE" (with newline)
    'die',
    'allocate mtrs',
    'qty. (pcs)',  // Note: Excel has "QTY. (PCS)"
    'core size',
    'qty. per roll',  // Note: Excel has "QTY. PER ROLL"
    'roll direction',
    'remarks'
];

// Check which headers match the aliases
echo "Header Mapping Check:\n";
echo "=====================\n\n";

foreach ($aliases as $key => $tryKeys) {
    $found = null;
    foreach ($headers as $header) {
        $norm_header = strtolower(trim(preg_replace('/\\s+/', ' ', $header)));
        foreach ($tryKeys as $check_key) {
            $norm_check = strtolower(trim(preg_replace('/\\s+/', ' ', $check_key)));
            if ($norm_header === $norm_check) {
                $found = $header;
                break 2;
            }
        }
    }
    $status = $found ? "✓ FOUND ($found)" : "✗ NOT FOUND";
    echo "$key => $status\n";
}

echo "\n\nActual Excel Column Headers (from user):\n";
$actual_headers = [
    'S.N',
    'Order Date',
    'Dispatch Date',
    'Printing Planing',  // Note: "Planing" not "Planning"
    'Plate No.',
    'NAME',
    'SIZE',
    'Repeat',
    'MATERIAL',
    'PAPER SIZE',
    'Die',
    'Allocate MTRS',
    'QTY. (PCS)',
    'CORE SIZE',
    'QTY. PER ROLL',
    'Roll Direction',
    'Remarks'
];

echo "Checking typo: 'Printing Planing' vs 'Printing Planning'\n";
$norm1 = strtolower(trim(preg_replace('/\\s+/', ' ', 'Printing Planing')));
$norm2 = strtolower(trim(preg_replace('/\\s+/', ' ', 'Printing Planning')));
echo "Normalized 'Printing Planing': $norm1\n";
echo "Normalized 'Printing Planning': $norm2\n";
echo "Are they same? " . ($norm1 === $norm2 ? "NO - They're different!" : "YES") . "\n";
?>
