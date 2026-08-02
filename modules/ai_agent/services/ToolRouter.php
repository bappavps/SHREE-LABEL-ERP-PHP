<?php

class ToolRouter
{
    private FeatureFlags $features;
    private Logger $logger;

    public function __construct(FeatureFlags $features, Logger $logger)
    {
        $this->features = $features;
        $this->logger = $logger;
    }

    public function getToolDefinitions(): array
    {
        if (!$this->features->isToolCallingEnabled()) {
            return [];
        }

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'calculate_erp_job',
                    'description' => 'Calculate math, cost, and quantities for an ERP label job. Use this when the user asks a mathematical question or requests a calculation for a job.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'plate_name' => [
                                'type' => 'string',
                                'description' => 'The name of the item or plate (e.g. Blue 500ml)'
                            ],
                            'budget' => [
                                'type' => 'number',
                                'description' => 'The budget constraint or order total'
                            ],
                            'rate' => [
                                'type' => 'number',
                                'description' => 'The per-label or per-sqinch rate'
                            ]
                        ],
                        'required' => ['plate_name']
                    ]
                ]
            ]
        ];
    }

    public function interceptToolCall(string $llmAnswer): ?array
    {
        if (!$this->features->isToolCallingEnabled()) {
            return null;
        }

        if (strpos($llmAnswer, '[TOOL_CALL]') === false) {
            return null;
        }

        if (preg_match('/\[TOOL_CALL\]\s*(\[.*?\])/s', $llmAnswer, $matches)) {
            $toolJson = $matches[1];
            $toolCalls = json_decode($toolJson, true);
            
            if (is_array($toolCalls) && !empty($toolCalls)) {
                $tc = $toolCalls[0];
                if (isset($tc['function']['name'])) {
                    $this->logger->tool("Intercepted tool call: " . $tc['function']['name'], $tc);
                    return $tc;
                }
            }
        }
        
        return null;
    }

    public function executeMathTool(array $tc, mysqli $db, string $prompt): string
    {
        try {
            $args = json_decode($tc['function']['arguments'], true);
            
            $plateName = $args['plate_name'] ?? '';
            $budget = (float)($args['budget'] ?? 0);
            $rate = (float)($args['rate'] ?? 0);
            
            $this->logger->tool("Invoking legacy calculation engine directly", ['plate' => $plateName, 'budget' => $budget, 'rate' => $rate]);

            $calcEngine = new CalculationEngine();
            $result = $calcEngine->calculatePlateCosting($db, $plateName, $rate, $budget, $prompt);

            if ($result && isset($result['answer'])) {
                return "🤖 **AI Tool Result:** `$plateName`\n\n*(Calculation Engine Delegated)*\n\n" . $result['answer'];
            }

            throw new Exception("Calculation Engine returned empty or invalid result");
            
        } catch (Exception $e) {
            $this->logger->error("ToolRouter execution failed", ['exception' => $e->getMessage()]);
            return "⚠️ **AI Tool Encountered an Error.**\n\nMy calculation tool failed. Please try typing the exact command (e.g. `/cal \"Item\"`).";
        }
    }
}
