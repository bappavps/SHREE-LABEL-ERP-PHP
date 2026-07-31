<?php
$db = new mysqli('localhost', 'root', '', 'shree_label_erp');
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

$res = $db->query("SELECT id, name, plate, sl_no FROM master_plate_data WHERE name LIKE '%blue%' OR name LIKE '%500%'");
$rows = $res->fetch_all(MYSQLI_ASSOC);
print_r($rows);
