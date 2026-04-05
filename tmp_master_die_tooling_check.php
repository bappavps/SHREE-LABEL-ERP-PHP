<?php
require __DIR__ . '/config/db.php';

$conn = getDB();
$columns = [
    'barcode_size',
    'ups_in_roll',
    'up_in_die',
    'label_gap',
    'paper_size',
    'cylender',
    'repeat_size',
    'used_for',
    'die_type',
    'core',
    'pices_per_roll'
];

$counts = [];
foreach ($columns as $col) {
    $sql = "SELECT COUNT(DISTINCT NULLIF(TRIM(`$col`), '')) AS cnt FROM `master_die_tooling`";
    $res = $conn->query($sql);
    if (!$res) {
        fwrite(STDERR, "Count query failed for $col: " . $conn->error . PHP_EOL);
        exit(1);
    }
    $row = $res->fetch_assoc();
    $counts[$col] = (int)$row['cnt'];
}

echo "DISTINCT_NON_EMPTY_COUNTS\n";
foreach ($counts as $col => $cnt) {
    echo $col . ": " . $cnt . PHP_EOL;
}

echo PHP_EOL . "SAMPLE_ROWS_WHERE_BARCODE_SIZE_NON_EMPTY\n";
$colList = implode(', ', array_map(fn($c) => "`$c`", $columns));
$sampleSql = "SELECT $colList FROM `master_die_tooling` WHERE NULLIF(TRIM(`barcode_size`), '') IS NOT NULL LIMIT 5";
$sample = $conn->query($sampleSql);
if (!$sample) {
    fwrite(STDERR, "Sample query failed: " . $conn->error . PHP_EOL);
    exit(1);
}

$i = 0;
while ($r = $sample->fetch_assoc()) {
    $i++;
    echo "Row $i: " . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
if ($i === 0) {
    echo "No rows found." . PHP_EOL;
}
?>
