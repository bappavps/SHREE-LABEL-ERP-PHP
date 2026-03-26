<?php
require_once __DIR__ . '/config/db.php';

$db = getDB();
$schemaFile = __DIR__ . '/database/schema.sql';
$sql = file_get_contents($schemaFile);

// Remove comments
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$sql = preg_replace('/^\s*#.*$/m', '', $sql);

$stmts = array_filter(array_map('trim', explode(';', $sql)));
$errors = 0;
$created = 0;

foreach ($stmts as $stmt) {
    if ($stmt === '') continue;
    if (!$db->query($stmt)) {
        echo '<p style="color:red">❌ Error: ' . htmlspecialchars($db->error) . '</p>';
        echo '<p style="color:#666;font-size:0.9em">SQL: ' . htmlspecialchars(substr($stmt, 0, 100)) . '...</p>';
        $errors++;
    } else {
        $created++;
    }
}

echo '<h2 style="color:#333">Database Schema Setup</h2>';
echo '<p style="color:green;font-size:1.1em"><strong>✅ Tables Created:</strong> ' . $created . '</p>';
if ($errors > 0) {
    echo '<p style="color:red;font-size:1.1em"><strong>❌ Errors:</strong> ' . $errors . '</p>';
} else {
    echo '<p style="color:green;font-size:1.1em"><strong>All tables created successfully!</strong></p>';
}

echo '<p style="margin-top:20px"><a href="modules/master/index.php">Go to Master Control Panel</a></p>';
?>
