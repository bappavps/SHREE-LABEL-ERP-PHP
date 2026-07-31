<?php
// Test-only: inspect master_plate_data to understand real data for fixing /plate
require_once __DIR__ . '/../../config/db.php';
$db = getDB();
echo "=== COUNT ===\n";
echo json_encode($db->query("SELECT COUNT(*) c FROM master_plate_data")->fetch_assoc()) . "\n";
echo "\n=== SAMPLE 5 ===\n";
$r = $db->query("SELECT * FROM master_plate_data ORDER BY id DESC LIMIT 5");
while ($row = $r->fetch_assoc()) { echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n"; }
echo "\n=== DISTINCT cylinder (sample) ===\n";
$r = $db->query("SELECT cylinder, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(cylinder,'')) != '' GROUP BY cylinder ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['cylinder'] . " => " . $row['c'] . "\n"; }
echo "\n=== DISTINCT repeat_value (sample) ===\n";
$r = $db->query("SELECT repeat_value, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(repeat_value,'')) != '' GROUP BY repeat_value ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['repeat_value'] . " => " . $row['c'] . "\n"; }
echo "\n=== DISTINCT paper_type (sample) ===\n";
$r = $db->query("SELECT paper_type, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(paper_type,'')) != '' GROUP BY paper_type ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['paper_type'] . " => " . $row['c'] . "\n"; }
echo "\n=== DISTINCT make_by (sample) ===\n";
$r = $db->query("SELECT make_by, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(make_by,'')) != '' GROUP BY make_by ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['make_by'] . " => " . $row['c'] . "\n"; }
echo "\n=== DISTINCT die (sample) ===\n";
$r = $db->query("SELECT die, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(die,'')) != '' GROUP BY die ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['die'] . " => " . $row['c'] . "\n"; }
echo "\n=== DISTINCT paper_size (sample) ===\n";
$r = $db->query("SELECT paper_size, COUNT(*) c FROM master_plate_data WHERE TRIM(COALESCE(paper_size,'')) != '' GROUP BY paper_size ORDER BY c DESC LIMIT 25");
while ($row = $r->fetch_assoc()) { echo $row['paper_size'] . " => " . $row['c'] . "\n"; }
echo "\n=== plate numbers like P- ===\n";
$r = $db->query("SELECT COUNT(*) c FROM master_plate_data WHERE plate LIKE 'P-%' OR sl_no LIKE 'P-%'");
echo json_encode($r->fetch_assoc()) . "\n";
echo "\n=== a plate with many colors ===\n";
$r = $db->query("SELECT id, name, plate, c, m, y, k, special_1, special_2, special_3, special_4, special_5 FROM master_plate_data WHERE (special_1 IS NOT NULL AND special_1 != '' AND special_1 != 'NA') OR (special_2 IS NOT NULL AND special_2 != '' AND special_2 != 'NA') LIMIT 5");
if ($r->num_rows) { while ($row = $r->fetch_assoc()) { echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n"; } } else { echo "none with special colors\n"; }
