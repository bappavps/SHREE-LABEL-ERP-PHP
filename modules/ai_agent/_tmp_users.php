<?php
// Test-only: list active users so tests can authenticate without touching ERP data
require_once __DIR__ . '/../../config/db.php';
$db = getDB();
$res = $db->query("SELECT id, email, role FROM users WHERE is_active = 1 LIMIT 10");
if (!$res) { echo "ERR: " . $db->error . "\n"; exit; }
while ($r = $res->fetch_assoc()) {
    echo $r['id'] . "\t" . $r['email'] . "\t" . $r['role'] . "\n";
}
