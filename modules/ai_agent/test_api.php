<?php
session_start();
$_SESSION['user_id'] = 1;
$_REQUEST['action'] = 'query';
$_REQUEST['prompt'] = 'How to calculate plate cost for নীল ৫00এমএল?';
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
try {
    require_once __DIR__ . '/api.php';
} catch (Exception $e) {
    echo "\nEXCEPTION CAUGHT: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "\nFATAL ERROR CAUGHT: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

echo "OUTPUT LENGTH: " . strlen($output) . "\n";
echo "--- RAW OUTPUT START ---\n";
echo $output;
echo "\n--- RAW OUTPUT END ---\n";
