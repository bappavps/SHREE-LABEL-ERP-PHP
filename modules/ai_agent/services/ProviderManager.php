<?php

class ProviderManager
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getPrimaryProvider(): string
    {
        return strtolower($this->config['default_provider'] ?? 'openrouter');
    }

    public function getFallbackProviders(string $exclude): array
    {
        $providers = [];
        
        $standardProviders = [
            'openai' => !empty($this->config['openai_api_key']),
            'openrouter' => !empty($this->config['openrouter_api_key']),
            'gemini' => !empty($this->config['gemini_api_key']),
            'local' => !empty($this->config['local_ai_url'])
        ];
        
        foreach ($standardProviders as $prov => $isAvailable) {
            if ($prov !== $exclude && $isAvailable) {
                $providers[] = ['type' => 'standard', 'id' => $prov];
            }
        }
        
        $endpointsJson = $this->config['ai_custom_endpoints'] ?? '[]';
        $endpoints = is_array($endpointsJson) ? $endpointsJson : (json_decode($endpointsJson, true) ?: []);
        
        foreach ($endpoints as $ep) {
            if (empty($ep['active'])) continue;
            
            $label = $ep['label'] ?? '';
            $url = $ep['url'] ?? '';
            $epModel = $ep['model'] ?? 'gpt-4o-mini';
            
            if (empty($url)) continue;
            
            $customModelStr = 'custom|||' . $label . '|||' . $url . '|||' . $epModel;
            $providers[] = [
                'type' => 'custom',
                'id' => 'custom',
                'model_str' => $customModelStr
            ];
        }
        
        return $providers;
    }
}
