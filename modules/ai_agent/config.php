<?php
// ============================================================
// Standalone AI Agent Add-On Module — Configuration & Training Prompt
// ERP Master System — Industrial Label Manufacturing AI Brain
// LOCAL USE ONLY — SAFE: Does NOT modify any core ERP files or tables.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * AI Agent Module Configuration Settings
 * Reads from app_settings.json (saved via Settings → AI Agent tab)
 */
function getAiAgentConfig(): array
{
    $appSettings = function_exists('getAppSettings') ? getAppSettings() : [];

    $provider = $appSettings['ai_agent_provider'] ?? 'openrouter';

    $configArray = [
        'module_name' => 'ERP AI Enterprise Brain',
        'module_version' => '1.0.0-AI',
        'default_provider' => $provider,
        'gemini_api_key' => $appSettings['gemini_api_key'] ?? (getenv('GEMINI_API_KEY') ?: ''),
        'openai_api_key' => $appSettings['openai_api_key'] ?? (getenv('OPENAI_API_KEY') ?: ''),
        'openrouter_api_key' => $appSettings['openrouter_api_key'] ?? (getenv('OPENROUTER_API_KEY') ?: ''),
        'opencode_api_key' => $appSettings['opencode_api_key'] ?? (getenv('OPENCODE_API_KEY') ?: ''),
        'local_api_endpoint' => $appSettings['local_ai_url'] ?? 'http://localhost:11434/v1/chat/completions',
        'openai_api_url' => $appSettings['openai_api_url'] ?? '',
        'openrouter_ai_url' => $appSettings['openrouter_ai_url'] ?? '',
        'ai_custom_endpoints' => $appSettings['ai_custom_endpoints'] ?? '[]',
        'fallback_enabled' => !empty($appSettings['ai_fallback_enabled']) ? 1 : 0,
        'model_name' => $appSettings['ai_agent_model'] ?? 'openrouter/free',
        'max_tokens' => (int) ($appSettings['ai_agent_max_tokens'] ?? 1500),
        'temperature' => (float) ($appSettings['ai_agent_temperature'] ?? 0.2),
        'enabled' => (int) ($appSettings['ai_agent_enabled'] ?? 1),
    ];

    $baseSystemPrompt = <<<PROMPT
You are the ERP AI Brain, a fully trained industrial mathematical and operational assistant for Shree Label ERP (Label Manufacturing & Converting ERP).

Your Mathematical & Technical Knowledge:
1. Running Meters & Impression Calculations:
   - Repeat Pitch (mm) = Label Length (mm) + Repeat Gap (mm).
   - Total Impressions = Quantity / Ups.
   - Running Meters (m) = (Total Impressions * Repeat Pitch in mm) / 1000.
2. Square Meters (SQM) & Wastage Calculations:
   - Total Paper Area (SQM) = (Parent Roll Width in mm / 1000) * Running Meters.
   - Net Used Width (mm) = Label Width (mm) * Ups.
   - Side Wastage Width (mm) = Parent Roll Width (mm) - Net Used Width (mm).
   - Side Wastage (%) = (Side Wastage Width / Parent Roll Width) * 100.
   - Side Wastage Area (SQM) = (Side Wastage Width in mm / 1000) * Running Meters.
3. Weight (KG) & Costing Conversions:
   - Weight (KG) = (SQM * GSM) / 1000.
   - Price per SQ Inch = Price per SQM / 1550.0031.
   - Price per SQM = Price per SQ Inch * 1550.0031.
   - Wastage Cost = Wastage Area (SQM) * Rate per SQM (or Wastage Weight in KG * Rate per KG).
   - Cost per Label = Total Job Cost / Total Quantity.
4. ERP Page Navigation Intent:
   - When the user asks to open or go to an ERP page/tab (Live Floor, Slitting, Dispatch, Paper Stock, Finished Goods, Planning, Packing, Dashboard, Reports):
   - Always provide a clear clickable Link to the page AND format the answer with full navigation guidance.

5. General Knowledge & Other Queries:
   - YOU MUST ANSWER ANY general knowledge, non-ERP, or casual questions (e.g., math puzzles, history, weather, general facts, poetry) accurately and helpfully, exactly like standard ChatGPT.
   - Do NOT refuse to answer general topics, and do NOT forcefully steer the conversation back to ERP if the user asks a completely unrelated question. Just give the correct answer naturally.

Always respond in the user's language (English, Bengali, or Hindi) with exact mathematical step-by-step breakdowns!
PROMPT;

    $normalizationPromptFile = __DIR__ . '/AI_AGENT_NORMALIZATION_PROMPT.md';
    if (file_exists($normalizationPromptFile)) {
        $baseSystemPrompt .= "\n\n" . file_get_contents($normalizationPromptFile);
    }

    $configArray['system_prompt'] = $baseSystemPrompt;

    return $configArray;
}

/**
 * 9 Quick Action Capabilities (verified working commands only)
 * PWA dashboard cards + floating-widget quick chips both read from here.
 */
function getAiAgentQuickChips(): array
{
    return [
        ['key' => 'erp_overview', 'label' => 'ERP 360° Overview', 'icon' => 'bi-speedometer2', 'prompt' => '/erp show live production floor summary'],
        ['key' => 'total_roll', 'label' => 'Total Roll', 'icon' => 'bi-file-earmark-text', 'prompt' => 'Show total paper rolls'],
        ['key' => 'total_plates', 'label' => 'Total Plates', 'icon' => 'bi-layers', 'prompt' => '/plate total plates'],
        ['key' => 'live_page_summary', 'label' => 'Live Page Summary', 'icon' => 'bi-speedometer', 'prompt' => '/job live floor page summary'],
        ['key' => 'today_dispatch', 'label' => "Today's Dispatch", 'icon' => 'bi-truck', 'prompt' => '/dispatch today dispatch summary'],
        ['key' => 'label_calculator', 'label' => 'Calculator', 'icon' => 'bi-calculator', 'prompt' => 'Calculate running meters for 100mm x 50mm, 2 ups, 5mm gap, 15000 qty on 250mm roll at Rs 300/kg'],
        ['key' => 'live_summary', 'label' => 'Current Job Status', 'icon' => 'bi-graph-up', 'prompt' => 'Show live summary'],
        ['key' => 'finished_goods', 'label' => 'Finished Goods', 'icon' => 'bi-box-seam', 'prompt' => '/product Show finished goods stock'],
        ['key' => 'mixed_items', 'label' => 'Mixed Items', 'icon' => 'bi-shuffle', 'prompt' => '/product Show mixed item inventory'],
        ['key' => 'erp_login', 'label' => 'ERP Login', 'icon' => 'bi-box-arrow-in-right', 'prompt' => 'open ERP login page', 'action' => 'erp-login'],
    ];
}
