<?php
$db = new mysqli('localhost', 'root', '', 'shree_label_erp');
$r = $db->query("SELECT id, roll_payload_json FROM packing_operator_entries WHERE id=1");
$row = $r->fetch_assoc();
$payload = json_decode($row['roll_payload_json'], true);

// Update roll_overrides with correct values
$payload['roll_overrides']['SLC/2026/0247-A']['total_rolls'] = 80;
$payload['roll_overrides']['SLC/2026/0247-A']['cartons'] = 2;
$payload['roll_overrides']['SLC/2026/0247-A']['qty'] = 24000;
$payload['roll_overrides']['SLC/2026/0247-B']['total_rolls'] = 80;
$payload['roll_overrides']['SLC/2026/0247-B']['cartons'] = 2;
$payload['roll_overrides']['SLC/2026/0247-B']['qty'] = 24000;

$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
$stmt = $db->prepare("UPDATE packing_operator_entries SET roll_payload_json=? WHERE id=1");
$stmt->bind_param('s', $json);
$stmt->execute();
echo "Updated: " . $stmt->affected_rows . " rows\n";
echo "New JSON:\n" . $json . "\n";
