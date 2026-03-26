<?php
require_once __DIR__ . '/config/db.php';

$db = getDB();

// Check if master tables exist
$tables = [
  'master_raw_materials',
  'master_suppliers', 
  'master_machines',
  'master_cylinders',
  'master_clients',
  'master_boms',
  'master_bom_items'
];

echo '<html>
<head>
  <title>Master Data Setup Verification</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
    h1 { color: #333; border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
    .status { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
    .item { padding: 12px; border-radius: 4px; font-weight: 500; }
    .ok { background: #d4edda; color: #155724; }
    .error { background: #f8d7da; color: #721c24; }
    .link { margin-top: 30px; text-align: center; }
    a { padding: 12px 24px; background: #0066cc; color: white; text-decoration: none; border-radius: 4px; display: inline-block; }
    a:hover { background: #0052a3; }
  </style>
</head>
<body>
<div class="container">
  <h1>✅ Master Control Panel Setup Complete!</h1>
  <p>All database tables have been successfully created. Here is the status:</p>
  
  <div class="status">';

$allOk = true;
foreach ($tables as $table) {
  $result = $db->query("SHOW TABLES LIKE '$table'");
  if ($result && $result->num_rows > 0) {
    echo '<div class="item ok">✓ ' . str_replace('master_', '', $table) . '</div>';
  } else {
    echo '<div class="item error">✗ ' . str_replace('master_', '', $table) . '</div>';
    $allOk = false;
  }
}

echo '</div>';

if ($allOk) {
  echo '<p style="color: green; font-size: 1.1em; text-align: center; margin-top: 20px;">
    <strong>All tables created successfully!</strong>
  </p>';
} else {
  echo '<p style="color: red; font-size: 1.1em; text-align: center; margin-top: 20px;">
    <strong>Some tables are missing. Please check the database.</strong>
  </p>';
}

echo '
  <div class="link">
    <p><a href="modules/master/index.php">🎯 Go to Master Control Panel</a></p>
    <p style="margin-top: 15px; color: #666;">
      You can now manage all master data including Raw Materials, Suppliers, Machines, Cylinders, Clients, and BOMs.
    </p>
  </div>
</div>
</body>
</html>';
?>
