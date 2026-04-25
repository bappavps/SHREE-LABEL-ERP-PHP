<?php
require_once 'config/database.php';
$r = $db->query("SHOW TRIGGERS WHERE `Table` = 'finished_goods_stock'");
while ($row = $r->fetch_assoc()) { print_r($row); }
