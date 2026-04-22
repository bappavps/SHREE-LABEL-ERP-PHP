<?php
require 'config/db.php';
require 'includes/functions.php';
require 'modules/packing/_data.php';
$db = getDB();
$r = packing_fetch_ready_rows($db, array('show_all_active'=>true, 'hide_packed_in_active'=>true));
$rows = (isset($r['rows']) && is_array($r['rows'])) ? $r['rows'] : array();
echo 'rows=' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
  $j = isset($row['job_no']) ? $row['job_no'] : '';
  $tab = isset($row['tab_key']) ? $row['tab_key'] : '';
  $pid = isset($row['planning_id']) ? (int)$row['planning_id'] : 0;
  $status = isset($row['status']) ? $row['status'] : '';
  echo $pid . ' | ' . $j . ' | ' . $tab . ' | ' . $status . PHP_EOL;
}
