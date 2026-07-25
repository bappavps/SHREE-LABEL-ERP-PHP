<?php
require_once __DIR__ . '/../config/db.php';
$db = getDB();

// Upstream jobs must be Completed so Packing receives them!
$db->query("UPDATE jobs SET status = 'Completed', extra_data = '{}' WHERE planning_id = 1 AND department IN ('jumbo_slitting', 'flexo_printing', 'flatbed', 'label_slitting')");

// Ensure planning record status is active
$db->query("UPDATE planning SET status = '' WHERE id = 1");

// Delete any finished goods stock entry for LSL/2026/0001
$db->query("DELETE FROM finished_goods_stock WHERE batch_no = 'LSL/2026/0001' OR item_name = 'Mewa laya'");
$db->query("DELETE FROM packing_operator_entries WHERE planning_id = 1 OR job_no = 'LSL/2026/0001'");

echo "SUCCESS: Upstream jobs set to Completed. Label Job LSL/2026/0001 is now READY in Packing Operator Dashboard!\n";
