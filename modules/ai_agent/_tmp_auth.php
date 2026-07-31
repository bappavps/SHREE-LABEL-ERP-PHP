<?php
// Test-only session bootstrap: create a valid session for admin (id=1)
// so curl requests to api.php authenticate. Does NOT modify ERP data.
session_name('PHPSESSID');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Admin';
$_SESSION['user_email'] = 'admin@example.com';
$_SESSION['role'] = 'admin';
$_SESSION['group_id'] = 0;
$_SESSION['tenant_slug'] = 'default';
$_SESSION['tenant_name'] = 'Shree Label';
session_write_close();
echo "session-ready\n";
