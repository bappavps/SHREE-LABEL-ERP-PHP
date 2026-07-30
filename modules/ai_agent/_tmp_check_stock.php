<?php
require_once __DIR__ . '/../../config/db.php';
$db = $GLOBALS['db'] ?? new mysqli('localhost','root','','shree_label_erp');
echo "=== Search for 0351 ===\n";
$res = $db->query("SELECT roll_no, company, paper_type, status, width_mm, length_mtr FROM paper_stock WHERE roll_no LIKE '%0351%' LIMIT 10");
if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "No matches for 0351\n";
}
echo "\n=== Sample rows ===\n";
$res2 = $db->query("SELECT roll_no, company, paper_type, status, width_mm FROM paper_stock LIMIT 5");
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "Query failed: " . $db->error . "\n";
}
echo "\n=== Total rows ===\n";
$cnt = $db->query("SELECT COUNT(*) as c FROM paper_stock")->fetch_assoc();
echo "Total paper_stock rows: " . $cnt['c'] . "\n";
