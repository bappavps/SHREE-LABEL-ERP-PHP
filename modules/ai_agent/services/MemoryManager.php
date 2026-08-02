<?php

class MemoryManager
{
    private FeatureFlags $features;
    private int $maxMessages;

    public function __construct(FeatureFlags $features, int $maxMessages = 6)
    {
        $this->features = $features;
        $this->maxMessages = $maxMessages;
    }

    public function getHistory(): array
    {
        if (!$this->features->isMemoryEnabled()) {
            return [];
        }

        return $_SESSION['ai_chat_history'] ?? [];
    }

    public function saveInteraction(string $userPrompt, string $aiResponse): void
    {
        if (!$this->features->isMemoryEnabled()) {
            return;
        }

        if (empty($userPrompt) || empty($aiResponse)) {
            return;
        }

        if (!isset($_SESSION['ai_chat_history'])) {
            $_SESSION['ai_chat_history'] = [];
        }

        $_SESSION['ai_chat_history'][] = ['role' => 'user', 'content' => $userPrompt];
        $_SESSION['ai_chat_history'][] = ['role' => 'assistant', 'content' => $aiResponse];

        // Trim to max messages
        if (count($_SESSION['ai_chat_history']) > $this->maxMessages) {
            $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -$this->maxMessages);
        }
    }
}
