<?php
$base = 'http://localhost/calipot-erp/shree-label-php';
$cookieJar = __DIR__ . '/_tmp_cookies.txt';

// Fresh auth
@unlink($cookieJar);
$ch = curl_init($base . '/modules/ai_agent/_tmp_auth.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$authResp = curl_exec($ch);
$authHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Auth HTTP: $authHttp | Resp: $authResp\n";
echo "Cookie jar contents:\n";
echo file_get_contents($cookieJar) . "\n";

// Now test the English query
$ch = curl_init($base . '/modules/ai_agent/api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'query', 'prompt' => '/plate how many total plates we have']);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "\nQuery HTTP: $httpCode | Error: $err\n";
echo "Raw response: " . substr($result, 0, 1000) . "\n";
$decoded = json_decode($result, true);
if ($decoded) {
    echo "Decoded answer: " . substr($decoded['answer'] ?? '(no answer)', 0, 800) . "\n";
}
