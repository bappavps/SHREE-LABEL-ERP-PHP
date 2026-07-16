<?php
$db = new mysqli('localhost', 'root', '', 'shree_label_erp');
$r = $db->query("SELECT id, job_no, packed_qty, cartons_count, loose_qty, roll_payload_json FROM packing_operator_entries WHERE job_no='PKG-BAR/2026/0001' ORDER BY id DESC LIMIT 1");
while ($row = $r->fetch_assoc()) {
    echo json_encode($row, JSON_PRETTY_PRINT) . PHP_EOL;
}
