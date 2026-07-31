<?php
// Test the specific query
$base = 'http://localhost/calipot-erp/shree-label-php';
$cookieJar = __DIR__ . '/_tmp_cookies.txt';

// Auth
$ch = curl_init($base . '/modules/ai_agent/_tmp_auth.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_exec($ch);
curl_close($ch);

function askAgent($prompt) {
    global $base, $cookieJar;
    $ch = curl_init($base . '/modules/ai_agent/api.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'query', 'prompt' => $prompt]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($result, true);
    return ['http' => $httpCode, 'answer' => $decoded['answer'] ?? '', 'tool' => $decoded['tool_used'] ?? ''];
}

$tests = [
    '/plate how many paper required to print "blue500" for 2000 qnty?',
    '/plate blue500 job for 2000 labels',
    '/plate blue500 2000 qnty',
];

foreach ($tests as $t) {
    echo "════════════════════════════════════════════════\n";
    echo "Q: {$t}\n";
    $r = askAgent($t);
    echo "HTTP: {$r['http']} | Tool: {$r['tool']}\n";
    echo "A: " . substr($r['answer'], 0, 1200) . "\n\n";
}