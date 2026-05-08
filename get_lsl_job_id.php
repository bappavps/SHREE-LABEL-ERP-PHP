<?php
$connection = new mysqli('localhost', 'root', '', 'shree_label_erp');
if ($connection->connect_error) {
    die('Connection failed: ' . $connection->connect_error);
}
$sql = "SELECT id FROM jobs WHERE job_no = 'LSL/2026/0001' LIMIT 1";
$result = $connection->query($sql);
if ($row = $result->fetch_assoc()) {
    echo $row['id'];
}
$connection->close();
