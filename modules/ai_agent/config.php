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

    $provider = $appSettings['ai_agent_provider'] ?? 'gemini_pro';

    return [
        'module_name' => 'ERP AI Enterprise Brain',
        'module_version' => '1.0.0-AI',
        'default_provider' => $provider,
        'gemini_api_key' => $appSettings['gemini_api_key'] ?? (getenv('GEMINI_API_KEY') ?: ''),
        'openai_api_key' => $appSettings['openai_api_key'] ?? (getenv('OPENAI_API_KEY') ?: ''),
        'openrouter_api_key' => $appSettings['openrouter_api_key'] ?? (getenv('OPENROUTER_API_KEY') ?: ''),
        'opencode_api_key' => $appSettings['opencode_api_key'] ?? (getenv('OPENCODE_API_KEY') ?: ''),
        'local_api_endpoint' => $appSettings['local_ai_url'] ?? 'http://localhost:11434/v1/chat/completions',
        'model_name' => $appSettings['ai_agent_model'] ?? 'gemini-2.0-flash',
        'max_tokens' => (int) ($appSettings['ai_agent_max_tokens'] ?? 1500),
        'temperature' => (float) ($appSettings['ai_agent_temperature'] ?? 0.2),
        'enabled' => (int) ($appSettings['ai_agent_enabled'] ?? 1),
        'system_prompt' => <<<PROMPT
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

Always respond in the user's language (English, Bengali, or Hindi) with exact mathematical step-by-step breakdowns!
PROMPT
    ];
}

/**
 * 21 Quick Action Capabilities List
 */
function getAiAgentQuickChips(): array
{
    return [
        ['key' => 'label_calculator', 'label' => 'Label Math Calculator', 'icon' => 'bi-calculator', 'prompt' => 'Calculate running meters for 100mm x 50mm, 2 ups, 5mm gap, 15000 qty on 250mm roll at Rs 300/kg'],
        ['key' => 'roll_status', 'label' => 'Roll Status', 'icon' => 'bi-journal-code', 'prompt' => 'Check roll status for recent paper stock rolls'],
        ['key' => 'order_status', 'label' => 'Order Status', 'icon' => 'bi-card-checklist', 'prompt' => 'Show active sales orders and job card progress'],
        ['key' => 'customer_search', 'label' => 'Customer Search', 'icon' => 'bi-people', 'prompt' => 'Show client summary and recent customer dispatches'],
        ['key' => 'production_summary', 'label' => 'Production Summary', 'icon' => 'bi-speedometer2', 'prompt' => 'Show daily production summary and output metrics'],
        ['key' => 'today_dispatch', 'label' => "Today's Dispatch", 'icon' => 'bi-truck', 'prompt' => "Show today's total dispatches and pending deliveries"],
        ['key' => 'pending_jobs', 'label' => 'Pending Jobs', 'icon' => 'bi-hourglass-split', 'prompt' => 'List pending jobs across Slitting, Printing, Die-Cutting, Packing'],
        ['key' => 'machine_running', 'label' => 'Machine Running', 'icon' => 'bi-gear-wide-connected', 'prompt' => 'Show active machine runs and department assignments'],
        ['key' => 'operator_performance', 'label' => 'Operator Performance', 'icon' => 'bi-person-badge', 'prompt' => 'Show operator job completion and productivity summary'],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'bi-box-seam', 'prompt' => 'Show finished goods stock by category'],
        ['key' => 'raw_material', 'label' => 'Raw Material', 'icon' => 'bi-layers', 'prompt' => 'Show paper stock, ink, core, and carton raw material stock'],
        ['key' => 'paper_stock', 'label' => 'Paper Stock', 'icon' => 'bi-file-earmark-text', 'prompt' => 'Show available parent paper rolls and remnant stocks'],
        ['key' => 'costing', 'label' => 'Costing', 'icon' => 'bi-currency-rupee', 'prompt' => 'Show transport cost per carton and freight analysis'],
        ['key' => 'barcode_search', 'label' => 'Barcode Search', 'icon' => 'bi-qr-code-scan', 'prompt' => 'How to search packing items by Barcode ID?'],
        ['key' => 'invoice', 'label' => 'Invoice', 'icon' => 'bi-receipt', 'prompt' => 'Show recent invoices and dispatch billing details'],
        ['key' => 'purchase_order', 'label' => 'Purchase Order', 'icon' => 'bi-bag-check', 'prompt' => 'Show paper receiving and purchase orders'],
        ['key' => 'client_balance', 'label' => 'Client Balance', 'icon' => 'bi-wallet2', 'prompt' => 'Show client dispatch totals and paid-by status'],
        ['key' => 'job_planning', 'label' => 'Job Planning', 'icon' => 'bi-diagram-3', 'prompt' => 'Show planning queue and best paper roll matches'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'prompt' => 'Generate category-wise dispatch and inventory report'],
        ['key' => 'ai_help', 'label' => 'AI Help', 'icon' => 'bi-robot', 'prompt' => 'What features can you help me with in ERP?'],
        ['key' => 'erp_training', 'label' => 'ERP Training', 'icon' => 'bi-mortarboard', 'prompt' => 'Provide a step-by-step training guide for ERP operators'],
    ];
}
