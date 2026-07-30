<?php
// Debug script to check AI proxy response
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Check raw app settings
$rawSettings = function_exists('getAppSettings') ? getAppSettings() : [];
echo "=== RAW app_settings.json (ai_agent keys) ===\n";
foreach ($rawSettings as $k => $v) {
    if (strpos($k, 'ai_agent') === 0 || strpos($k, 'openai') === 0 || strpos($k, 'openrouter') === 0 || strpos($k, 'gemini') === 0 || strpos($k, 'local_ai') === 0) {
        echo "$k = " . (is_string($v) ? (strlen($v) > 40 ? substr($v, 0, 40) . '...' : $v) : var_export($v, true)) . "\n";
    }
}
echo "\n";

require_once __DIR__ . '/modules/ai_agent/config.php';

$config = getAiAgentConfig();
$provider = strtolower($config['default_provider'] ?? 'openrouter');
$model = $config['model_name'] ?? 'openrouter/free';
$systemPrompt = "You are a helpful assistant. Answer concisely.";
$prompt = "Who is the prime minister of India?";

$url = '';
$apiKey = '';
$headers = ['Content-Type: application/json'];

if ($provider === 'openai') {
    $apiKey = $config['openai_api_key'] ?? '';
    $url = !empty($config['openai_api_url']) ? $config['openai_api_url'] : 'https://api.openai.com/v1/chat/completions';
    if (empty($model) || strpos($model, 'gemini') !== false) $model = 'gpt-4o-mini';
}

echo "=== CONFIG DEBUG ===\n";
echo "Provider: $provider\n";
echo "Model: $model\n";
echo "URL: $url\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 200
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array_merge($headers, ['Authorization: Bearer ' . $apiKey]),
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "=== HTTP RESPONSE ===\n";
echo "HTTP Code: $httpCode\n";
if ($error) echo "cURL Error: $error\n";
echo "Raw Response:\n";
echo $response . "\n\n";

echo "=== DECODED ===\n";
$result = json_decode($response, true);
if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON parse error: " . json_last_error_msg() . "\n";
} else {
    echo "choices[0][message][content] = " . var_export($result['choices'][0]['message']['content'] ?? 'NOT SET', true) . "\n";
    echo "choices[0][message][reasoning_content] = " . var_export($result['choices'][0]['message']['reasoning_content'] ?? 'NOT SET', true) . "\n";
    echo "isset(content) = " . (isset($result['choices'][0]['message']['content']) ? 'true' : 'false') . "\n";
    echo "empty(content) = " . (empty($result['choices'][0]['message']['content']) ? 'true' : 'false') . "\n";
}
