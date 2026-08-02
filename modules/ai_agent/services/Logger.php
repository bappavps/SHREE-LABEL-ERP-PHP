<?php

class Logger
{
    private string $logDir;

    public function __construct(string $logDir = __DIR__ . '/../logs')
    {
        $this->logDir = rtrim($logDir, '/');
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ai_error.log', 'ERROR', $message, $context);
    }

    public function tool(string $message, array $context = []): void
    {
        $this->write('ai_tool.log', 'TOOL', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('ai_info.log', 'INFO', $message, $context);
    }

    private function write(string $file, string $level, string $message, array $context = []): void
    {
        $path = $this->logDir . '/' . $file;
        $timestamp = date('Y-m-d H:i:s');
        
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logLine = "[$timestamp] [$level] $message$contextStr" . PHP_EOL;
        
        @file_put_contents($path, $logLine, FILE_APPEND);
    }
}
