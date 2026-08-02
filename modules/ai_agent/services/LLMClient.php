<?php

class LLMClient
{
    private array $config;
    private Logger $logger;
    private ProviderManager $providerManager;

    public function __construct(array $config, Logger $logger, ProviderManager $providerManager)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->providerManager = $providerManager;
    }

    public function callWithFallback(string $prompt, array $messages, array $tools = []): ?string
    {
        $primaryProvider = $this->providerManager->getPrimaryProvider();
        $model = $this->config['model_name'] ?? 'openrouter/free';
        
        $this->logger->info("Calling primary provider: $primaryProvider");
        $result = $this->callProvider($primaryProvider, $model, $prompt, $messages, $tools);

        if ($result !== null && strpos($result, '[API_ERROR]') !== 0) {
            return $result . "\n\n*Note: Response via Agent 1*";
        }

        $fallbackEnabled = !empty($this->config['fallback_enabled']) && $this->config['fallback_enabled'] === 1;
        if (!$fallbackEnabled) {
            return $result;
        }

        $this->logger->info("Primary provider failed, initiating fallback", ['error' => $result]);
        
        $agentCounter = 2;
        $fallbacks = $this->providerManager->getFallbackProviders($primaryProvider);

        foreach ($fallbacks as $fb) {
            $provId = $fb['id'];
            $epModel = $fb['model_str'] ?? ($this->config['model_name'] ?? '');
            
            $this->logger->info("Calling fallback provider: $provId (Agent $agentCounter)");
            
            $epResult = $this->callProvider($provId, $epModel, $prompt, $messages, $tools);
            
            if ($epResult !== null && strpos($epResult, '[API_ERROR]') !== 0) {
                return $epResult . "\n\n*Note: Response via Agent " . $agentCounter . '*';
            }
            
            $agentCounter++;
        }

        $this->logger->error("All fallback providers failed");
        return $result; // Return the primary error
    }

    private function callProvider(string $provider, string $model, string $prompt, array $messages, array $tools = []): ?string
    {
        $temperature = (float) ($this->config['temperature'] ?? 0.2);
        $maxTokens = (int) ($this->config['max_tokens'] ?? 1500);

        // 1. Google Gemini Provider
        if ($provider === 'gemini_pro' || $provider === 'gemini') {
            $apiKey = $this->config['gemini_api_key'] ?? '';
            if (empty($apiKey)) return null;

            $targetModel = ($model && strpos($model, 'gemini') !== false) ? $model : 'gemini-2.0-flash';
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($targetModel) . ":generateContent?key=" . urlencode($apiKey);

            $systemPrompt = $messages[0]['content'] ?? '';
            $userPrompt = end($messages)['content'] ?? $prompt;

            $payload = [
                "systemInstruction" => [
                    "parts" => [["text" => $systemPrompt]]
                ],
                "contents" => [
                    [
                        "role" => "user",
                        "parts" => [["text" => $userPrompt]]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => $temperature,
                    "maxOutputTokens" => $maxTokens
                ]
            ];

            return $this->executeCurl($provider, $url, $payload, ['Content-Type: application/json']);
        }

        // 2. OpenAI-compatible Providers
        $url = '';
        $apiKey = '';
        $headers = ['Content-Type: application/json'];

        if ($provider === 'openai') {
            $apiKey = $this->config['openai_api_key'] ?? '';
            $url = !empty($this->config['openai_api_url']) ? $this->config['openai_api_url'] : 'https://api.openai.com/v1/chat/completions';
            if (empty($model) || strpos($model, 'gemini') !== false) {
                $model = (strpos($url, 'ninerouter') !== false) ? '9ROUTER-COMBO' : 'gpt-4o-mini';
            }
        } elseif ($provider === 'openrouter') {
            $apiKey = !empty($this->config['openrouter_api_key']) ? $this->config['openrouter_api_key'] : ($this->config['openai_api_key'] ?? '');
            $url = !empty($this->config['openrouter_ai_url']) ? $this->config['openrouter_ai_url'] : 'https://openrouter.ai/api/v1/chat/completions';
            if (empty($model)) $model = 'openrouter/free';
            $headers[] = 'HTTP-Referer: http://localhost';
            $headers[] = 'X-Title: Shree Label ERP AI';
        } elseif ($provider === 'opencode') {
            $apiKey = !empty($this->config['opencode_api_key']) ? $this->config['opencode_api_key'] : ($this->config['openai_api_key'] ?? '');
            $url = !empty($this->config['local_api_endpoint']) ? $this->config['local_api_endpoint'] : 'https://api.opencode.ai/v1/chat/completions';
            if (empty($model) || strpos($model, 'gemini') !== false) $model = 'opencode-default';
        } elseif ($provider === 'local') {
            $url = !empty($this->config['local_api_endpoint']) ? $this->config['local_api_endpoint'] : 'http://localhost:11434/v1/chat/completions';
            $apiKey = $this->config['openai_api_key'] ?? '';
            if (empty($model) || strpos($model, 'gemini') !== false) $model = 'llama3';
        } elseif ($provider === 'custom') {
            if (preg_match('/^custom\|\|\|(.+?)\|\|\|(.+?)\|\|\|(.+)$/', $model, $m)) {
                $customLabel = $m[1];
                $url = $m[2];
                $model = $m[3] ?: 'gpt-4o-mini';
                
                $endpointsJson = $this->config['ai_custom_endpoints'] ?? '[]';
                $endpoints = is_array($endpointsJson) ? $endpointsJson : (json_decode($endpointsJson, true) ?: []);
                foreach ($endpoints as $ep) {
                    if (($ep['label'] ?? '') === $customLabel) {
                        $apiKey = $ep['api_key'] ?? '';
                        break;
                    }
                }
            }
        }

        if (empty($url)) return null;
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $this->executeCurl($provider, $url, $payload, $headers);
    }

    private function executeCurl(string $provider, string $url, array $payload, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            $this->logger->error("Connection failed for $provider", ['url' => $url, 'error' => $error]);
            return "[API_ERROR] Connection failed ($provider): " . ($error ?: 'empty response');
        }

        if ($provider === 'gemini_pro' || $provider === 'gemini') {
            $response = preg_replace('/\R?data:\s*\[DONE\]\s*$/', '', $response);
            $response = preg_replace('/\R?data:\s*.+$/', '', $response);

            if (strpos(trim($response), 'data: ') === 0) {
                $fullContent = '';
                $lines = explode("\n", $response);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $jsonStr = trim(substr($line, 6));
                        if ($jsonStr === '[DONE]') continue;
                        $data = json_decode($jsonStr, true);
                        if (isset($data['choices'][0]['delta']['content'])) {
                            $fullContent .= $data['choices'][0]['delta']['content'];
                        }
                    }
                }
                if ($fullContent !== '') return $fullContent;
            }

            $result = json_decode($response, true);
            if (isset($result['error'])) {
                $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
                $this->logger->error("Gemini API Error", $result);
                return "[API_ERROR] Gemini Error: " . $msg;
            }
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return trim($result['candidates'][0]['content']['parts'][0]['text']);
            }
            return "[API_ERROR] Unexpected API response format.";
        }

        if (strpos(trim($response), 'data: ') === 0) {
            $fullContent = '';
            $lines = explode("\n", $response);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = trim(substr($line, 6));
                    if ($jsonStr === '[DONE]') continue;
                    $data = json_decode($jsonStr, true);
                    if (isset($data['choices'][0]['delta']['content'])) {
                        $fullContent .= $data['choices'][0]['delta']['content'];
                    }
                }
            }
            if ($fullContent !== '') return $fullContent;
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("JSON Parse Error for $provider", ['response' => $response]);
            return "[API_ERROR] Invalid JSON from API.";
        }

        if (isset($result['error'])) {
            $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
            $this->logger->error("API Error for $provider", $result);
            return "[API_ERROR] API Error: " . $msg;
        }

        if (isset($result['choices'][0]['message']['tool_calls'])) {
            $this->logger->info("Tool Call received from $provider");
            return "[TOOL_CALL] " . json_encode($result['choices'][0]['message']['tool_calls']);
        }

        if (isset($result['choices'][0]['message']['content'])) {
            $content = trim($result['choices'][0]['message']['content']);
            if ($content !== '') return $content;
        }

        return "[API_ERROR] No valid response generated.";
    }
}
