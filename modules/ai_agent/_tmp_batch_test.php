<?php
// TEMP batch test — checks which AI commands return real data.
// Usage: php _tmp_batch_test.php "prompt1" "prompt2" ...
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['user_name'] = 'admin';
$_SESSION['user_email'] = 'admin@example.com';
unset($_SESSION['ai_priority_mode']);

$prompts = array_slice($argv, 1);
if (!$prompts) $prompts = ['Show total paper rolls'];

function callApi($prompt) {
    $_REQUEST['action'] = 'query';
    $_REQUEST['prompt'] = $prompt;
    ob_start();
    try {
        include __DIR__ . '/api.php';
    } catch (Throwable $e) {
        ob_end_clean();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $json = ob_get_clean();
    $data = json_decode($json, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'no-json', 'raw' => substr($json, 0, 200)];
}

foreach ($prompts as $pr) {
    $r = callApi($pr);
    $tool = $r['tool_used'] ?? ($r['error'] ?? '?');
    $ans = $r['answer'] ?? '';
    $first = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/[|\n]+/', ' | ', $ans))));
    $first = mb_substr($first, 0, 180);
    $count = $r['total_count'] ?? '?';
    echo ">>> " . $pr . "\n    tool=" . $tool . " count=" . $count . "\n    " . $first . "\n\n";
}
