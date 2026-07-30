<?php
// Test the API by simulating JS fetch request
$url = 'http://localhost/calipot-erp/shree-label-php/modules/ai_agent/api.php';

// First, get a session cookie by logging in
$ch = curl_init('http://localhost/calipot-erp/shree-label-php/auth/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'admin@example.com',
    'password' => 'admin123'
]));
$cookieJar = __DIR__ . '/_tmp_cookies.txt';
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
$loginRes = curl_exec($ch);
curl_close($ch);

// Now make the API call
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'action' => 'query',
    'prompt' => 'SLC/2026/0351 রোলটি দেখাও'
]);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "cURL Error: " . ($error ?: 'none') . "\n";
echo "Response:\n";
if ($result) {
    $decoded = json_decode($result, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo "NOT JSON. Raw first 2000 chars:\n" . substr($result, 0, 2000);
    }
} else {
    echo "(empty response)";
}
