<?php
require 'config/db.php';
$db = getDB();
$res = $db->query('SELECT id, job_no, job_name, status, extra_data FROM planning ORDER BY id DESC LIMIT 5');
if (!$res) {
    echo "Query error: " . $db->error;
    exit;
}
while($r = $res->fetch_assoc()) {
    echo "ID: " . $r['id'] . "\n";
    echo "Job No: " . $r['job_no'] . "\n";
    echo "Job Name: " . $r['job_name'] . "\n";
    echo "Status: " . $r['status'] . "\n";
    if ($r['extra_data']) {
        $extra = json_decode($r['extra_data'], true);
        echo "Extra Data: " . json_encode($extra, JSON_PRETTY_PRINT) . "\n";
    }
    echo "---\n";
}
