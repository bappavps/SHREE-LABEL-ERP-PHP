<?php
// TEMP single-prompt harness — simulates authenticated API request.
// Usage: php _tmp_api_test.php "<prompt>"
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['user_name'] = 'admin';
$_SESSION['user_email'] = 'admin@example.com';
unset($_SESSION['ai_priority_mode']);
$_REQUEST['action'] = 'query';
$_REQUEST['prompt'] = $argv[1] ?? '/paper show me thermal paper below size of 500 mm width';
include __DIR__ . '/api.php';
