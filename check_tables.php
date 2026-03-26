<?php
require_once __DIR__ . '/config/db.php';

$db = getDB();

$tables = ['master_raw_materials', 'master_suppliers', 'master_machines', 'master_cylinders', 'master_clients', 'master_boms', 'master_bom_items'];

echo '<h2>Database Tables Status</h2>';
echo '<ul style="font-size:1.1em;line-height:1.8">';

foreach ($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo '<li style="color:green">✅ ' . $table . ' — EXISTS</li>';
    } else {
        echo '<li style="color:red">❌ ' . $table . ' — MISSING</li>';
    }
}

echo '</ul>';

echo '<p style="margin-top:30px"><a href="modules/master/index.php" class="btn" style="padding:10px 20px;background:#0066cc;color:white;text-decoration:none;border-radius:4px">Go to Master Control Panel</a></p>';
echo '<p><a href="create_tables.php" style="margin-left:10px">Run Setup Again</a></p>';
?>
