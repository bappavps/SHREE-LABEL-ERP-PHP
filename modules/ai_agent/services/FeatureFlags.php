<?php

class FeatureFlags
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isMemoryEnabled(): bool
    {
        return !isset($this->config['ai_feature_memory']) || $this->config['ai_feature_memory'] == 1;
    }

    public function isToolCallingEnabled(): bool
    {
        return !isset($this->config['ai_feature_tool_calling']) || $this->config['ai_feature_tool_calling'] == 1;
    }

    public function isLoggingEnabled(): bool
    {
        return !isset($this->config['ai_feature_logging']) || $this->config['ai_feature_logging'] == 1;
    }

    public function isFutureRagEnabled(): bool
    {
        return isset($this->config['ai_feature_future_rag']) && $this->config['ai_feature_future_rag'] == 1;
    }
}
