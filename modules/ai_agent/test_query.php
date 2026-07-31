<?php
session_start();
$_SESSION['user_id'] = 1;
$_REQUEST['action'] = 'query';
$_REQUEST['prompt'] = $argv[1];
$_REQUEST['user_lang'] = $argv[2] ?? '';

ob_start();
include __DIR__ . '/api.php';
$output = ob_get_clean();

$res = json_decode($output, true);
if ($res && isset($res['answer'])) {
    echo "QUERY: " . $argv[1] . "\n";
    echo "ANSWER: \n" . strip_tags(str_replace('<br>', "\n", $res['answer'])) . "\n";
    echo str_repeat('-', 50) . "\n";
} else {
    echo "QUERY: " . $argv[1] . "\n";
    echo "RAW OUTPUT: \n" . $output . "\n";
    echo str_repeat('-', 50) . "\n";
}
