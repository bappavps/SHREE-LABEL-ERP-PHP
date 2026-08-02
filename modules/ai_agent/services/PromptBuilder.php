<?php

class PromptBuilder
{
    private MemoryManager $memory;
    private ?RetrievalEngine $retrievalEngine;

    public function __construct(MemoryManager $memory, ?RetrievalEngine $retrievalEngine = null)
    {
        $this->memory = $memory;
        $this->retrievalEngine = $retrievalEngine;
    }

    public function buildMessages(string $prompt, string $systemPrompt): array
    {
        // 1. Fetch semantic context from Knowledge Catalog
        if ($this->retrievalEngine) {
            $rawResults = $this->retrievalEngine->search($prompt, 5);
            if (!empty($rawResults) && class_exists('ContextBuilder')) {
                $contextBlock = ContextBuilder::build($rawResults, 1500, 1.0);
                if ($contextBlock !== '') {
                    $systemPrompt .= "\n\n" . $contextBlock;
                }
            }
        }
        
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        
        $history = $this->memory->getHistory();
        foreach ($history as $msg) {
            $messages[] = $msg;
        }
        
        $messages[] = ['role' => 'user', 'content' => $prompt];
        
        return $messages;
    }
}
