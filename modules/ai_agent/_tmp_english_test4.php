<?php
$base = 'http://localhost/calipot-erp/shree-label-php';
$cookieJar = __DIR__ . '/_tmp_cookies.txt';
@unlink($cookieJar);

// Auth
$ch = curl_init($base . '/modules/ai_agent/_tmp_auth.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_exec($ch);
curl_close($ch);

// Check if cookie jar exists
echo "Cookie jar exists: " . (file_exists($cookieJar) ? 'YES' : 'NO') . "\n";
if (file_exists($cookieJar)) {
    echo "Cookie jar contents: " . file_get_contents($cookieJar) . "\n";
}

// Test query
$ch = curl_init($base . '/modules/ai_agent/api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'query', 'prompt' => '/plate how many total plates we have']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $httpCode\n";
echo "Response: " . substr($result, 0, 500) . "\n";
