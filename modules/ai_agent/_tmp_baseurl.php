<?php
require_once __DIR__ . '/../../config/db.php';
echo "BASE_URL = " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "\n";
// Simulate the app's resolution
$baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
echo "app.php baseUrl = " . $baseUrl . "\n";
echo "API_URL would be = " . $baseUrl . "/modules/ai_agent/api.php\n";
