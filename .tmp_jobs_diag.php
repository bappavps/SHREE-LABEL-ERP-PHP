<?php
require 'config/db.php';
$db = getDB();
$ids = array();
$res = $db->query("SELECT DISTINCT planning_id FROM jobs WHERE planning_id IS NOT NULL AND planning_id>0 ORDER BY planning_id DESC LIMIT 30");
while ($row = $res->fetch_assoc()) { $ids[] = (int)$row['planning_id']; }
if (!$ids) { exit; }
$in = implode(',', $ids);
$q = "SELECT planning_id, id, job_no, department, job_type, status FROM jobs WHERE planning_id IN ($in) ORDER BY planning_id DESC, id DESC";
$rr = $db->query($q);
$seen = array();
while ($r = $rr->fetch_assoc()) {
  $pid = (int)$r['planning_id'];
  if (isset($seen[$pid]) && $seen[$pid] >= 4) { continue; }
  $seen[$pid] = isset($seen[$pid]) ? ($seen[$pid] + 1) : 1;
  echo $pid . ' | ' . ($r['job_no'] ?? '') . ' | ' . ($r['department'] ?? '') . ' | ' . ($r['job_type'] ?? '') . ' | ' . ($r['status'] ?? '') . PHP_EOL;
}
