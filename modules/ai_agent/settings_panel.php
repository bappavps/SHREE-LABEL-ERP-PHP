<?php
// ============================================================
// AI Agent Plugin — Settings Panel (included from settings/index.php)
// Admin-Only Configuration Panel for AI Agent Module
// ============================================================

if (!function_exists('isAdmin') || !isAdmin()) {
  echo '<div style="padding:40px;text-align:center;color:#b91c1c;font-size:1.1rem"><i class="bi bi-shield-lock" style="font-size:2rem"></i><br>Admin access required to configure AI Agent settings.</div>';
  return;
}

$aiSettings = function_exists('getAppSettings') ? getAppSettings() : [];
$aiProvider = $aiSettings['ai_agent_provider'] ?? 'openrouter';
$aiModel = $aiSettings['ai_agent_model'] ?? 'openrouter/free';
$geminiKey = $aiSettings['gemini_api_key'] ?? '';
$openaiKey = $aiSettings['openai_api_key'] ?? '';
$openaiUrl = $aiSettings['openai_api_url'] ?? '';
$localUrl = $aiSettings['local_ai_url'] ?? 'http://localhost:11434/v1/chat/completions';
$rawEndpoints = $aiSettings['ai_custom_endpoints'] ?? '[]';
$customEndpoints = is_array($rawEndpoints) ? $rawEndpoints : (json_decode($rawEndpoints, true) ?: []);
$aiTemp = $aiSettings['ai_agent_temperature'] ?? 0.2;
$aiMaxTokens = $aiSettings['ai_agent_max_tokens'] ?? 1500;
$aiEnabled = (int) ($aiSettings['ai_agent_enabled'] ?? 1);
$baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
$kbApiUrl = $baseUrl . '/modules/ai_agent/knowledge_api.php';

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$standaloneAppUrl = $protocol . $host . $baseUrl . '/modules/ai_agent/app.php';

if (empty($_SESSION['ai_agent_csrf_token'])) {
  $_SESSION['ai_agent_csrf_token'] = bin2hex(random_bytes(32));
}
$aiCsrfToken = $_SESSION['ai_agent_csrf_token'];
?>


<style>
  .ai-settings-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 22px;
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
    border-radius: 12px;
    color: #fff;
    margin-bottom: 24px;
  }

  .ai-settings-banner i {
    font-size: 1.5rem;
    opacity: .9;
  }

  .ai-settings-banner strong {
    font-size: 1.05rem;
  }

  .ai-settings-banner .sub {
    font-size: .84rem;
    opacity: .8;
    margin-top: 2px;
  }

  .ai-settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
  }

  @media (max-width:900px) {
    .ai-settings-grid {
      grid-template-columns: 1fr;
    }
  }

  .ai-card {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 22px;
    transition: box-shadow .2s;
  }

  .ai-card:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, .06);
  }

  .ai-card.full {
    grid-column: 1/-1;
  }

  .ai-card h4 {
    font-size: 1rem;
    font-weight: 700;
    color: #1e1b4b;
    margin: 0 0 14px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .ai-card h4 i {
    font-size: 1.15rem;
    color: #4338ca;
  }

  .ai-field {
    margin-bottom: 14px;
  }

  .ai-field label {
    display: block;
    font-size: .82rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 5px;
  }

  .ai-field input,
  .ai-field select,
  .ai-field textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: .9rem;
    background: #f8fafc;
    transition: border-color .2s;
  }

  .ai-field input:focus,
  .ai-field select:focus,
  .ai-field textarea:focus {
    border-color: #4338ca;
    outline: none;
    background: #fff;
  }

  .ai-field textarea {
    resize: vertical;
    min-height: 80px;
    font-family: inherit;
  }

  .ai-field .hint {
    font-size: .78rem;
    color: #94a3b8;
    margin-top: 3px;
  }

  .ai-field-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
  }

  .ai-field-row .ai-field {
    flex: 1;
  }

  .ai-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .2s;
  }

  .ai-btn-primary {
    background: #4338ca;
    color: #fff;
  }

  .ai-btn-primary:hover {
    background: #3730a3;
  }

  .ai-btn-success {
    background: #16a34a;
    color: #fff;
  }

  .ai-btn-success:hover {
    background: #15803d;
  }

  .ai-btn-danger {
    background: #dc2626;
    color: #fff;
  }

  .ai-btn-danger:hover {
    background: #b91c1c;
  }

  .ai-btn-outline {
    background: #fff;
    color: #4338ca;
    border: 1.5px solid #4338ca;
  }

  .ai-btn-outline:hover {
    background: #eef2ff;
  }

  .ai-btn-sm {
    padding: 6px 12px;
    font-size: .82rem;
  }

  .ai-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .ai-toggle-switch {
    position: relative;
    width: 44px;
    height: 24px;
    cursor: pointer;
  }

  .ai-toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
  }

  .ai-toggle-track {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #cbd5e1;
    border-radius: 12px;
    transition: background .2s;
  }

  .ai-toggle-switch input:checked+.ai-toggle-track {
    background: #4338ca;
  }

  .ai-toggle-track::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 20px;
    height: 20px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
  }

  .ai-toggle-switch input:checked+.ai-toggle-track::after {
    transform: translateX(20px);
  }

  .ai-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: .78rem;
    font-weight: 600;
  }

  .ai-status-active {
    background: #dcfce7;
    color: #166534;
  }

  .ai-status-inactive {
    background: #fee2e2;
    color: #991b1b;
  }

  /* Knowledge Table */
  .kb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .88rem;
  }

  .kb-table thead th {
    padding: 10px 12px;
    background: #f1f5f9;
    font-weight: 700;
    color: #334155;
    text-align: left;
    border-bottom: 2px solid #e2e8f0;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .3px;
  }

  .kb-table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
  }

  .kb-table tbody tr:hover {
    background: #fafafe;
  }

  .kb-cat-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: .76rem;
    font-weight: 600;
  }

  .kb-cat-faq {
    background: #dbeafe;
    color: #1d4ed8;
  }

  .kb-cat-business {
    background: #fef3c7;
    color: #92400e;
  }

  .kb-cat-terminology {
    background: #e0e7ff;
    color: #3730a3;
  }

  .kb-cat-chip {
    background: #d1fae5;
    color: #065f46;
  }

  .kb-keywords {
    font-size: .8rem;
    color: #64748b;
    max-width: 220px;
    word-break: break-word;
    line-height: 1.45;
  }

  .kb-answer-wrap {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    max-width: 480px;
  }

  .kb-answer-title {
    display: block !important;
    font-weight: 700 !important;
    color: #1e1b4b !important;
    font-size: .86rem !important;
    line-height: 1.35 !important;
    margin: 0 0 2px 0 !important;
    padding: 0 !important;
  }

  .kb-answer-body {
    display: block !important;
    color: #475569 !important;
    font-size: .82rem !important;
    line-height: 1.4 !important;
    margin: 0 !important;
    padding: 0 !important;
    word-break: break-word;
    max-height: 4.2em;
    overflow: hidden;
  }



  /* Modal */
  .ai-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, .5);
    z-index: 9999;
    display: none;
    justify-content: center;
    align-items: center;
  }

  .ai-modal-overlay.show {
    display: flex;
  }

  .ai-modal {
    background: #fff;
    border-radius: 16px;
    width: 560px;
    max-width: 95vw;
    max-height: 90vh;
    overflow-y: auto;
    padding: 28px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
  }

  .ai-modal h3 {
    margin: 0 0 18px;
    font-size: 1.1rem;
    color: #1e1b4b;
  }

  .ai-modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
  }

  /* Plugin Guide */
  .ai-guide-step {
    display: flex;
    gap: 14px;
    margin-bottom: 16px;
    padding: 14px;
    background: #f8fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
  }

  .ai-guide-num {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #4338ca;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .9rem;
  }

  .ai-guide-text {
    flex: 1;
  }

  .ai-guide-text strong {
    display: block;
    color: #1e1b4b;
    margin-bottom: 3px;
  }

  .ai-guide-text code {
    background: #e0e7ff;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: .82rem;
    color: #3730a3;
  }
</style>

<!-- Banner -->
<div class="ai-settings-banner">
  <i class="bi bi-robot"></i>
  <div>
    <strong>AI Agent — Configuration & Training</strong>
    <div class="sub">Configure AI provider, API keys, train custom knowledge, and manage the AI chatbot plugin.</div>
  </div>
  <div style="margin-left:auto">
    <span class="ai-status-badge <?= $aiEnabled ? 'ai-status-active' : 'ai-status-inactive' ?>">
      <i class="bi bi-circle-fill" style="font-size:.5rem"></i>
      <?= $aiEnabled ? 'Active' : 'Inactive' ?>
    </span>
  </div>
</div>

<!-- Section 1: Provider & Model -->
<form method="POST">
  <input type="hidden" name="action" value="save_ai_settings">
  <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">
  <div class="ai-settings-grid">
    <div class="ai-card">
      <h4><i class="bi bi-cpu"></i> AI Provider & Model</h4>
      <div class="ai-field">
        <label for="ai_provider">Provider</label>
        <select name="ai_agent_provider" id="ai_provider" onchange="aiSettingsToggleProvider()">
          <option value="gemini_pro" <?= $aiProvider === 'gemini_pro' ? 'selected' : '' ?>>Google Gemini Pro</option>
          <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI GPT</option>
          <option value="local" <?= $aiProvider === 'local' ? 'selected' : '' ?>>Local LLM (Ollama / LM Studio)</option>
          <option value="openrouter" <?= $aiProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
          <option value="custom" <?= $aiProvider === 'custom' ? 'selected' : '' ?>>Custom API (Multiple Endpoints)</option>
        </select>
      </div>
      <div class="ai-field" id="ai_model_group">
        <label for="ai_agent_model">AI Model Name</label>
        <select id="ai_agent_model_select" onchange="handleModelSelect()"></select>
        <input name="ai_agent_model" id="ai_agent_model" type="text" value="<?= e($aiModel) ?>"
          placeholder="e.g. gemini-2.0-flash, gpt-4o-mini" style="margin-top:8px; display:none;">
      </div>
      <div class="ai-field" id="gemini_key_group">
        <label for="gemini_api_key">Gemini API Key</label>
        <div style="position:relative">
          <input name="gemini_api_key" id="gemini_api_key" type="password" value="<?= e($geminiKey) ?>"
            placeholder="Enter Gemini API key" autocomplete="off">
          <button type="button" onclick="toggleKeyVisibility('gemini_api_key')"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;font-size:1rem"><i
              class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="ai-field" id="openai_key_group" style="display:none">
        <label for="openai_api_key">OpenAI API Key</label>
        <div style="position:relative">
          <input name="openai_api_key" id="openai_api_key" type="password" value="<?= e($openaiKey) ?>"
            placeholder="Enter OpenAI API key (sk-...)" autocomplete="off">
          <button type="button" onclick="toggleKeyVisibility('openai_api_key')"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;font-size:1rem"><i
              class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="ai-field" id="openai_url_group" style="display:none">
        <label for="openai_api_url">OpenAI API URL</label>
        <div style="position:relative">
          <input name="openai_api_url" id="openai_api_url" type="url" value="<?= e($openaiUrl) ?>"
            placeholder="https://api.openai.com/v1/chat/completions">
          <button type="button" onclick="toggleKeyVisibility('openai_api_url')"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;font-size:1rem"><i
              class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="ai-field" id="local_url_group" style="display:none">
        <label for="local_ai_url">Local LLM Endpoint URL</label>
        <input name="local_ai_url" id="local_ai_url" type="url" value="<?= e($localUrl) ?>"
          placeholder="http://localhost:11434/v1/chat/completions">
      </div>
      <div class="ai-field" id="openrouter_key_group" style="display:none">
        <label for="openrouter_api_key">OpenRouter API Key</label>
        <div style="position:relative">
          <input name="openrouter_api_key" id="openrouter_api_key" type="password"
            value="<?= e($aiSettings['openrouter_api_key'] ?? '') ?>" placeholder="Enter OpenRouter API key"
            autocomplete="off">
          <button type="button" onclick="toggleKeyVisibility('openrouter_api_key')"
            style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#64748b;font-size:1rem"><i
              class="bi bi-eye"></i></button>
        </div>
      </div>
      <div class="ai-field" id="openrouter_url_group" style="display:none">
        <label for="openrouter_ai_url">OpenRouter Endpoint URL</label>
        <input name="openrouter_ai_url" id="openrouter_ai_url" type="url"
          value="<?= e($aiSettings['openrouter_ai_url'] ?? 'https://openrouter.ai/api/v1/chat/completions') ?>"
          placeholder="https://openrouter.ai/api/v1/chat/completions">
      </div>

      <div id="ai_test_result" style="margin-top:10px;font-size:.84rem;display:none"></div>
      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center">
        <button type="button" class="ai-btn ai-btn-outline ai-btn-sm" onclick="aiTestProvider()"><i class="bi bi-lightning"></i> Test Connection</button>
        <button type="submit" class="ai-btn ai-btn-success ai-btn-sm"><i class="bi bi-save"></i> Save Settings</button>
        <div class="ai-toggle" style="margin-left:auto">
          <label class="ai-toggle-switch">
            <input name="ai_fallback_enabled" type="checkbox" id="ai_fallback_enabled" value="1" <?= ($aiSettings['ai_fallback_enabled'] ?? 0) ? 'checked' : '' ?>>
            <span class="ai-toggle-track"></span>
          </label>
          <label for="ai_fallback_enabled" style="font-weight:600;color:#334155;cursor:pointer;font-size:.85rem">Enable Fallback</label>
        </div>
      </div>
      <div class="hint" style="margin-top:6px;font-size:.78rem;color:#94a3b8">
        Fallback: If the primary API fails, the system will try each <strong>active Custom API Endpoint</strong> in order until one succeeds.
      </div>
      <div id="ai_set_save_result" style="margin-top:px;font-size:.rem"></div>
    </div>

    <!-- Custom API Endpoints Card (always visible) -->
    <div class="ai-card">
      <h4><i class="bi bi-plugin"></i> Custom API Endpoints <span style="font-weight:400;font-size:.78rem;color:#94a3b8">(fallback targets)</span></h4>
      <p style="font-size:.82rem;color:#64748b;margin:0 0 14px">
        Add multiple OpenAI-compatible API endpoints. Each endpoint can be saved, tested, and activated independently.
        When <strong>Fallback</strong> is enabled, active endpoints are tried in order if the primary API fails.
      </p>
      <div id="custom_endpoints_list">
        <?php if (!empty($customEndpoints)): ?>
          <?php foreach ($customEndpoints as $i => $ep): ?>
            <div class="custom-ep-row" data-index="<?= $i ?>" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px">
              <div class="ai-field-row">
                <div class="ai-field" style="flex:2">
                  <label>Label</label>
                  <input type="text" class="ep-label" value="<?= e($ep['label'] ?? '') ?>" placeholder="e.g. 9Router">
                </div>
                <div class="ai-field" style="flex:3">
                  <label>API URL</label>
                  <input type="url" class="ep-url" value="<?= e($ep['url'] ?? '') ?>" placeholder="https://example.com/v1/chat/completions">
                </div>
              </div>
              <div class="ai-field-row">
                <div class="ai-field" style="flex:3">
                  <label>API Key</label>
                  <input type="password" class="ep-key" value="<?= e($ep['api_key'] ?? '') ?>" placeholder="sk-..." autocomplete="off">
                </div>
                <div class="ai-field" style="flex:2">
                  <label>Default Model</label>
                  <input type="text" class="ep-model" value="<?= e($ep['model'] ?? 'gpt-4o-mini') ?>" placeholder="gpt-4o-mini">
                </div>
              </div>
              <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
                <div class="ai-toggle">
                  <label class="ai-toggle-switch" style="width:36px;height:20px">
                    <input type="checkbox" class="ep-active" value="1" <?= !empty($ep['active']) ? 'checked' : '' ?>>
                    <span class="ai-toggle-track" style="width:36px;height:20px"></span>
                  </label>
                  <span style="font-size:.78rem;color:#64748b;margin-left:4px">Active</span>
                </div>
                <button type="button" class="ai-btn ai-btn-outline ai-btn-sm ep-save-btn" onclick="saveEndpoint(this)"><i class="bi bi-save"></i> Save</button>
                <button type="button" class="ai-btn ai-btn-outline ai-btn-sm ep-test-btn" onclick="testEndpoint(this)"><i class="bi bi-lightning"></i> Test</button>
                <button type="button" class="ai-btn ai-btn-danger ai-btn-sm" onclick="removeEndpoint(this)" title="Remove"><i class="bi bi-trash"></i></button>
                <span class="ep-status" style="font-size:.78rem;display:none"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <button type="button" class="ai-btn ai-btn-primary ai-btn-sm" onclick="addEndpoint()"><i class="bi bi-plus-lg"></i> Add Endpoint</button>
      <button type="button" class="ai-btn ai-btn-outline ai-btn-sm" onclick="testFallbackChain()" style="margin-left:8px"><i class="bi bi-diagram-3"></i> Test Fallback Chain</button>
      <span id="ai_fallback_test_result" style="font-size:.82rem;display:none;margin-left:12px"></span>
      <input type="hidden" name="ai_custom_endpoints" id="ai_custom_endpoints" value="<?= e($customEndpointsJson) ?>">
    </div>

    <!-- Advanced Options Card -->
    <div class="ai-card">
      <h4><i class="bi bi-sliders"></i> Advanced Options</h4>
      <div class="ai-field">
        <label for="ai_temperature">Temperature: <span
            id="ai_temp_val"><?= number_format((float) $aiTemp, 1) ?></span></label>
        <input name="ai_agent_temperature" id="ai_temperature" type="range" min="0" max="1" step="0.1"
          value="<?= (float) $aiTemp ?>" oninput="document.getElementById('ai_temp_val').textContent=this.value"
          style="accent-color:#4338ca">
        <div class="hint">Lower = more precise. Higher = more creative.</div>
      </div>
      <div class="ai-field">
        <label for="ai_max_tokens">Max Tokens: <span id="ai_tok_val"><?= (int) $aiMaxTokens ?></span></label>
        <input name="ai_agent_max_tokens" id="ai_max_tokens" type="range" min="100" max="4000" step="100"
          value="<?= (int) $aiMaxTokens ?>" oninput="document.getElementById('ai_tok_val').textContent=this.value"
          style="accent-color:#4338ca">
        <div class="hint">Maximum response length (100–4000 tokens).</div>
      </div>
      <div class="ai-field" style="margin-top:20px">
        <div class="ai-toggle">
          <label class="ai-toggle-switch">
            <input name="ai_agent_enabled" type="checkbox" id="ai_enabled" value="1" <?= $aiEnabled ? 'checked' : '' ?>>
            <span class="ai-toggle-track"></span>
          </label>
          <label for="ai_enabled" style="font-weight:600;color:#334155;cursor:pointer">Enable AI Agent Chatbot</label>
        </div>
        <div class="hint" style="margin-top:6px">When disabled, the floating chat widget will not appear on any page.
        </div>
      </div>

      <div style="margin-top:22px;padding:14px;background:#f0fdf4;border-radius:10px;border:1px solid #bbf7d0">
        <div style="font-weight:700;color:#166534;font-size:.88rem;margin-bottom:6px"><i class="bi bi-info-circle"></i>
          Module Info</div>
        <div style="font-size:.82rem;color:#475569">
          <div><strong>Module:</strong> AI Agent Plugin v1.0.0</div>
          <div><strong>Provider:</strong> <span
              id="ai_info_provider"><?= e(ucwords(str_replace('_', ' ', $aiProvider))) ?></span></div>
          <div><strong>Files:</strong> modules/ai_agent/ (6 files)</div>
          <div><strong>Tables:</strong> ai_agent_knowledge</div>
          <div><strong>Core ERP Impact:</strong> Zero (100% isolated)</div>
          <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0;">
            <div><strong>Standalone Mobile AI App Link:</strong></div>
            <div
              style="background:#f8fafc;padding:10px;border-radius:6px;font-family:monospace;font-size:.8rem;word-break:break-all;">
              <a href="<?= e($standaloneAppUrl) ?>" target="_blank"
                style="color:#2563eb;text-decoration:none;"><?= e($standaloneAppUrl) ?></a>
            </div>
            <div style="font-size:.75rem;color:#64748b;margin-top:4px;">
              Open this link on mobile and use "Add to Home Screen" to install the PWA app
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Section 2: Knowledge Base Training -->
<div class="ai-card full" style="margin-bottom:24px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <h4 style="margin:0"><i class="bi bi-book"></i> Knowledge Base / Custom Training</h4>
    <button class="ai-btn ai-btn-primary ai-btn-sm" onclick="kbOpenModal()"><i class="bi bi-plus-lg"></i> Add New
      Entry</button>
  </div>
  <!-- Clear How-To & Training Guide Banner -->
  <div
    style="margin-bottom:16px;padding:16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;font-size:.85rem;color:#0369a1;line-height:1.5">
    <div style="font-weight:700;font-size:.92rem;margin-bottom:6px;display:flex;align-items:center;gap:6px">
      <i class="bi bi-lightbulb-fill" style="color:#0284c7"></i> <strong>How to Train the AI Agent with Custom
        Knowledge & Business Rules</strong>
    </div>
    <div style="color:#334155;margin-bottom:8px">
      As an administrator, you can train the AI Agent with company-specific <strong>Business Rules, Custom FAQs, or
        Terminology</strong>. When a user asks a question matching your defined keywords, the AI automatically
      provides your exact trained response.
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:10px">
      <div style="background:#fff;padding:10px;border-radius:8px;border:1px solid #e0f2fe">
        <strong style="color:#1d4ed8">1. FAQ (Customer Q&A)</strong>
        <div style="font-size:.78rem;color:#64748b;margin-top:2px"><strong>Keywords:</strong>
          <code>delivery, shipping cost</code><br><strong>Answer:</strong> "Free delivery on orders above ₹10,000..."
        </div>
      </div>
      <div style="background:#fff;padding:10px;border-radius:8px;border:1px solid #fef3c7">
        <strong style="color:#92400e">2. Business Rules (Policies)</strong>
        <div style="font-size:.78rem;color:#64748b;margin-top:2px"><strong>Keywords:</strong>
          <code>moq, minimum order</code><br><strong>Answer:</strong> "Our minimum order quantity is 5,000 pcs..."
        </div>
      </div>
      <div style="background:#fff;padding:10px;border-radius:8px;border:1px solid #e0e7ff">
        <strong style="color:#3730a3">3. Terminology (Contacts)</strong>
        <div style="font-size:.78rem;color:#64748b;margin-top:2px"><strong>Keywords:</strong>
          <code>plate manager, incharge</code><br><strong>Answer:</strong> "For plate issues, contact Mr. Roy..."
        </div>
      </div>
    </div>
  </div>



  <div style="display:flex;gap:8px;margin-bottom:14px">
    <button class="ai-btn ai-btn-sm ai-btn-outline kb-filter active" data-cat="all"
      onclick="kbFilterCategory('all',this)">All</button>
    <button class="ai-btn ai-btn-sm ai-btn-outline kb-filter" data-cat="FAQ"
      onclick="kbFilterCategory('FAQ',this)">FAQ</button>
    <button class="ai-btn ai-btn-sm ai-btn-outline kb-filter" data-cat="Business Rule"
      onclick="kbFilterCategory('Business Rule',this)">Business Rules</button>
    <button class="ai-btn ai-btn-sm ai-btn-outline kb-filter" data-cat="Terminology"
      onclick="kbFilterCategory('Terminology',this)">Terminology</button>
    <button class="ai-btn ai-btn-sm ai-btn-outline kb-filter" data-cat="Quick Chip"
      onclick="kbFilterCategory('Quick Chip',this)">Quick Chips</button>
  </div>

  <div id="kb_table_wrap">
    <table class="kb-table">
      <thead>
        <tr>
          <th style="width:30px">#</th>
          <th style="width:100px">Category</th>
          <th style="width:200px">Keywords</th>
          <th>Question / Answer Preview</th>
          <th style="width:80px">Status</th>
          <th style="width:120px">Actions</th>
        </tr>
      </thead>
      <tbody id="kb_tbody">
        <tr>
          <td colspan="6" style="text-align:center;color:#94a3b8;padding:30px">Loading knowledge entries...</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Section 3: Plugin Installation Guide -->
<div class="ai-card full">
  <h4><i class="bi bi-plug"></i> Plugin Installation Guide (Hostinger / Remote Server)</h4>
  <div style="font-size:.84rem;color:#64748b;margin-bottom:18px">
    Follow these steps to install the AI Agent plugin on your live Hostinger ERP or any remote server:
  </div>

  <div class="ai-guide-step">
    <div class="ai-guide-num">1</div>
    <div class="ai-guide-text">
      <strong>Upload AI Agent Module</strong>
      Upload the entire <code>modules/ai_agent/</code> folder to your ERP's <code>modules/</code> directory via FTP,
      File Manager, or Git pull.
    </div>
  </div>
  <div class="ai-guide-step">
    <div class="ai-guide-num">2</div>
    <div class="ai-guide-text">
      <strong>Run Database Migration</strong>
      Open phpMyAdmin on Hostinger → Select your ERP database → Go to SQL tab → Copy and paste the contents of
      <code>modules/ai_agent/ai_agent_migration.sql</code> → Execute. The table <code>ai_agent_knowledge</code> will
      be created automatically.
    </div>
  </div>
  <div class="ai-guide-step">
    <div class="ai-guide-num">3</div>
    <div class="ai-guide-text">
      <strong>Enable Chat Widget</strong>
      Add this one line to <code>includes/footer.php</code> (just before <code>&lt;/body&gt;</code>):<br>
      <code>&lt;?php if (file_exists(__DIR__ . '/../modules/ai_agent/floating_widget.php')) { include_once __DIR__ . '/../modules/ai_agent/floating_widget.php'; } ?&gt;</code>
      <div style="font-size:.78rem;color:#94a3b8;margin-top:4px">The <code>file_exists()</code> guard ensures ERP
        works even if the module is removed.</div>
    </div>
  </div>
  <div class="ai-guide-step">
    <div class="ai-guide-num">4</div>
    <div class="ai-guide-text">
      <strong>Add Settings Tab (Optional)</strong>
      In <code>modules/settings/index.php</code>: add <code>'ai_agent'</code> to the <code>$allowedTabs</code> array,
      add the tab link, and add the include line for the AI Agent panel. Or simply manage config via
      <code>modules/ai_agent/config.php</code>.
    </div>
  </div>
  <div class="ai-guide-step">
    <div class="ai-guide-num">5</div>
    <div class="ai-guide-text">
      <strong>Configure API Key</strong>
      Go to Settings → AI Agent tab → Enter your Gemini or OpenAI API key → Click <strong>Test Connection</strong> →
      Click <strong>Save Settings</strong>. Done!
    </div>
  </div>
  <div class="ai-guide-step" style="background:#f0fdf4;border-color:#bbf7d0">
    <div class="ai-guide-num" style="background:#16a34a">✓</div>
    <div class="ai-guide-text">
      <strong style="color:#166534">Installation Complete!</strong>
      The AI chatbot will now appear on every ERP page. Train it from the Knowledge Base section above.
      <div style="font-size:.82rem;color:#64748b;margin-top:4px">To <strong>deactivate</strong> without deleting:
        simply toggle the "Enable AI Agent Chatbot" switch above to OFF.</div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="ai-modal-overlay" id="kb_modal">
  <div class="ai-modal">
    <h3 id="kb_modal_title"><i class="bi bi-plus-circle"></i> Add Knowledge Entry</h3>
    <input type="hidden" id="kb_edit_id" value="">
    <div class="ai-field">
      <label for="kb_category">Category</label>
      <select id="kb_category">
        <option value="FAQ">FAQ</option>
        <option value="Business Rule">Business Rule</option>
        <option value="Terminology">Terminology</option>
        <option value="Quick Chip">Quick Chip</option>
      </select>
    </div>
    <div class="ai-field">
      <label for="kb_keywords">Keywords <span style="color:#dc2626">*</span></label>
      <input id="kb_keywords" type="text" placeholder="delivery charge, ডেলিভারি খরচ, shipping cost">
      <div class="hint">Comma-separated keywords. AI will match these against user queries.</div>
    </div>
    <div class="ai-field">
      <label for="kb_question">Display Question (Optional)</label>
      <input id="kb_question" type="text" placeholder="e.g. What is the delivery charge?">
    </div>
    <div class="ai-field">
      <label for="kb_answer">Answer <span style="color:#dc2626">*</span></label>
      <textarea id="kb_answer" rows="4" placeholder="Enter the exact answer the AI should give..."></textarea>
    </div>
    <div class="ai-field">
      <div class="ai-toggle">
        <label class="ai-toggle-switch">
          <input type="checkbox" id="kb_active" checked>
          <span class="ai-toggle-track"></span>
        </label>
        <label for="kb_active" style="font-weight:600;color:#334155;cursor:pointer">Active</label>
      </div>
    </div>
    <div class="ai-modal-actions">
      <button class="ai-btn ai-btn-outline" onclick="kbCloseModal()">Cancel</button>
      <button class="ai-btn ai-btn-primary" onclick="kbSaveEntry()"><i class="bi bi-save"></i> Save Entry</button>
    </div>
  </div>
</div>

<script>
  const KB_API = '<?= $kbApiUrl ?>';
  const AI_CSRF_TOKEN = '<?= $aiCsrfToken ?>';

  const AI_MODELS = {
    'gemini_pro': ['gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
    'openai': ['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'],
    'openrouter': ['openrouter/free', 'google/gemini-2.5-flash', 'openai/gpt-4o-mini', 'anthropic/claude-3-haiku', 'meta-llama/llama-3.3-70b-instruct'],
    'local': ['llama3', 'mistral', 'qwen2']
  };

  function handleModelSelect() {
    const sel = document.getElementById('ai_agent_model_select');
    const inp = document.getElementById('ai_agent_model');
    if (sel.value === 'custom') {
      inp.style.display = 'block';
    } else {
      inp.style.display = 'none';
      inp.value = sel.value;
    }
  }

  function aiSettingsToggleProvider(isInit = false) {
    const p = document.getElementById('ai_provider').value;
    document.getElementById('gemini_key_group').style.display = p === 'gemini_pro' ? '' : 'none';
    document.getElementById('openai_key_group').style.display = p === 'openai' ? '' : 'none';
    document.getElementById('openai_url_group').style.display = p === 'openai' ? '' : 'none';
    document.getElementById('local_url_group').style.display = p === 'local' ? '' : 'none';
    const org = document.getElementById('openrouter_key_group');
    if (org) org.style.display = p === 'openrouter' ? '' : 'none';
    const ourl = document.getElementById('openrouter_url_group');
    if (ourl) ourl.style.display = p === 'openrouter' ? '' : 'none';
    const sel = document.getElementById('ai_agent_model_select');
    const inp = document.getElementById('ai_agent_model');
    const currentVal = inp.value;

    if (sel) {
      sel.innerHTML = '';
      let found = false;
      if (p === 'custom') {
        // Populate with saved custom endpoints as model options
        try {
          const eps = JSON.parse(document.getElementById('ai_custom_endpoints').value || '[]');
          eps.forEach((ep, idx) => {
            const opt = document.createElement('option');
            opt.value = 'custom:' + ep.label + ':' + ep.url + ':' + (ep.model || 'gpt-4o-mini');
            opt.textContent = (ep.label || 'Endpoint ' + (idx + 1)) + ' — ' + (ep.model || 'gpt-4o-mini');
            opt.dataset.key = ep.api_key || '';
            if (ep.label === currentVal || 'custom:' + ep.label === currentVal) found = true;
            sel.appendChild(opt);
          });
        } catch (e) {}
        const customOpt = document.createElement('option');
        customOpt.value = 'custom';
        customOpt.textContent = 'Other (Custom...)';
        sel.appendChild(customOpt);
        if (isInit && currentVal && !found) {
          sel.value = 'custom';
        } else if (isInit && found) {
          sel.value = currentVal;
        }
      } else if (AI_MODELS[p]) {
        AI_MODELS[p].forEach(m => {
          const opt = document.createElement('option');
          opt.value = m;
          opt.textContent = m;
          if (m === currentVal) found = true;
          sel.appendChild(opt);
        });
        const customOpt = document.createElement('option');
        customOpt.value = 'custom';
        customOpt.textContent = 'Other (Custom...)';
        sel.appendChild(customOpt);
        if (isInit && currentVal && !found) {
          sel.value = 'custom';
        } else if (isInit && found) {
          sel.value = currentVal;
        }
      }

      if (sel) {
        handleModelSelect();
      }
    }
  }
  aiSettingsToggleProvider(true);

  function toggleKeyVisibility(id) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
  }

  function aiTestProvider() {
    const provider = document.getElementById('ai_provider').value;
    let apiKey = '';
    let localUrl = '';
    let openrouterUrl = '';
    let openaiUrl = '';
    let customUrl = '';
    let customModel = '';
    if (provider === 'gemini_pro') {
      apiKey = document.getElementById('gemini_api_key').value;
    } else if (provider === 'openai') {
      apiKey = document.getElementById('openai_api_key').value;
      const urlInput = document.getElementById('openai_api_url');
      if (urlInput) openaiUrl = urlInput.value;
    } else if (provider === 'openrouter') {
      apiKey = document.getElementById('openrouter_api_key').value;
      const oUrlInput = document.getElementById('openrouter_ai_url');
      if (oUrlInput) openrouterUrl = oUrlInput.value;
    } else if (provider === 'custom') {
      const sel = document.getElementById('ai_agent_model_select');
      if (sel && sel.value && sel.value.startsWith('custom:')) {
        const parts = sel.value.split(':');
        customUrl = parts[2] || '';
        customModel = parts[3] || 'gpt-4o-mini';
        // Look up API key from the endpoints data
        try {
          const eps = JSON.parse(document.getElementById('ai_custom_endpoints').value || '[]');
          const label = parts[1] || '';
          const found = eps.find(e => e.label === label);
          if (found) apiKey = found.api_key || '';
        } catch (e) {}
      }
    } else {
      localUrl = document.getElementById('local_ai_url').value; apiKey = 'local';
    }

    const res = document.getElementById('ai_test_result');
    res.style.display = 'block';
    res.innerHTML = '<span style="color:#4338ca"><i class="bi bi-arrow-repeat spin"></i> Testing connection...</span>';

    const fd = new FormData();
    fd.append('action', 'test_provider');
    fd.append('provider', provider);
    fd.append('api_key', apiKey);

    const modelInput = document.getElementById('ai_agent_model');
    if (modelInput && modelInput.value) {
      fd.append('model', customModel || modelInput.value);
    }

    if (localUrl) fd.append('local_url', localUrl);
    if (openrouterUrl) fd.append('openrouter_url', openrouterUrl);
    if (openaiUrl) fd.append('openai_url', openaiUrl);
    if (customUrl) fd.append('custom_url', customUrl);

    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          res.innerHTML = '<span style="color:#166534"><i class="bi bi-check-circle-fill"></i> ' + (d.message || 'Connected!') + '</span>';
        } else {
          res.innerHTML = '<span style="color:#b91c1c"><i class="bi bi-exclamation-triangle-fill"></i> ' + (d.error || 'Connection failed.') + '</span>';
        }
      })
      .catch(e => {
        res.innerHTML = '<span style="color:#b91c1c"><i class="bi bi-exclamation-triangle-fill"></i> Network error.</span>';
      });
  }

  // ─── Custom API Endpoints Management (per-endpoint AJAX) ───
  function saveEndpoint(btn) {
    const row = btn.closest('.custom-ep-row');
    const label = row.querySelector('.ep-label').value.trim();
    const url = row.querySelector('.ep-url').value.trim();
    const apiKey = row.querySelector('.ep-key').value.trim();
    const model = row.querySelector('.ep-model').value.trim();
    const active = row.querySelector('.ep-active').checked ? 1 : 0;
    const status = row.querySelector('.ep-status');

    if (!label || !url) {
      status.style.display = 'inline';
      status.innerHTML = '<span style="color:#b91c1c">Label & URL required</span>';
      return;
    }

    const fd = new FormData();
    fd.append('action', 'save_endpoint');
    fd.append('label', label);
    fd.append('url', url);
    fd.append('api_key', apiKey);
    fd.append('model', model || 'gpt-4o-mini');
    fd.append('active', active);
    fd.append('csrf_token', AI_CSRF_TOKEN);

    status.style.display = 'inline';
    status.innerHTML = '<span style="color:#4338ca"><i class="bi bi-arrow-repeat spin"></i> Saving...</span>';

    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          status.innerHTML = '<span style="color:#166534"><i class="bi bi-check-circle-fill"></i> Saved</span>';
          setTimeout(() => { status.style.display = 'none'; }, 2000);
        } else {
          status.innerHTML = '<span style="color:#b91c1c">' + (d.error || 'Save failed.') + '</span>';
        }
      })
      .catch(e => {
        status.innerHTML = '<span style="color:#b91c1c">Network error.</span>';
      });
  }

  function testEndpoint(btn) {
    const row = btn.closest('.custom-ep-row');
    const label = row.querySelector('.ep-label').value.trim();
    const url = row.querySelector('.ep-url').value.trim();
    const apiKey = row.querySelector('.ep-key').value.trim();
    const model = row.querySelector('.ep-model').value.trim();
    const status = row.querySelector('.ep-status');

    if (!label || !url) {
      status.style.display = 'inline';
      status.innerHTML = '<span style="color:#b91c1c">Label & URL required</span>';
      return;
    }

    const fd = new FormData();
    fd.append('action', 'test_single_endpoint');
    fd.append('label', label);
    fd.append('url', url);
    fd.append('api_key', apiKey);
    fd.append('model', model || 'gpt-4o-mini');
    fd.append('csrf_token', AI_CSRF_TOKEN);

    status.style.display = 'inline';
    status.innerHTML = '<span style="color:#4338ca"><i class="bi bi-arrow-repeat spin"></i> Testing...</span>';

    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          status.innerHTML = '<span style="color:#166534"><i class="bi bi-check-circle-fill"></i> ' + (d.message || 'Connected!') + '</span>';
          setTimeout(() => { status.style.display = 'none'; }, 3000);
        } else {
          status.innerHTML = '<span style="color:#b91c1c">' + (d.error || 'Connection failed.') + '</span>';
        }
      })
      .catch(e => {
        status.innerHTML = '<span style="color:#b91c1c">Network error.</span>';
      });
  }

  function testFallbackChain() {
    const result = document.getElementById('ai_fallback_test_result');
    result.style.display = 'inline';
    result.innerHTML = '<span style="color:#4338ca"><i class="bi bi-arrow-repeat spin"></i> Testing fallback chain...</span>';

    const fd = new FormData();
    fd.append('action', 'test_fallback_chain');
    fd.append('csrf_token', AI_CSRF_TOKEN);

    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          let html = '<span style="color:#166534"><i class="bi bi-check-circle-fill"></i> Fallback OK — ' + d.message + '</span>';
          if (d.details) {
            html += '<div style="margin-top:6px;font-size:.78rem;line-height:1.6">';
            d.details.forEach(function(item) {
              const icon = item.status === 'ok' ? '✅' : item.status === 'skip' ? '⏭️' : '❌';
              html += '<div>' + icon + ' ' + item.label + ': ' + item.info + '</div>';
            });
            html += '</div>';
          }
          result.innerHTML = html;
        } else {
          result.innerHTML = '<span style="color:#b91c1c"><i class="bi bi-exclamation-triangle-fill"></i> ' + (d.error || 'Fallback test failed.') + '</span>';
        }
      })
      .catch(e => {
        result.innerHTML = '<span style="color:#b91c1c">Network error.</span>';
      });
  }

  function addEndpoint() {
    const list = document.getElementById('custom_endpoints_list');
    const div = document.createElement('div');
    div.className = 'custom-ep-row';
    div.style.cssText = 'background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:10px';
    div.innerHTML = `
      <div class="ai-field-row">
        <div class="ai-field" style="flex:2">
          <label>Label</label>
          <input type="text" class="ep-label" placeholder="e.g. 9Router">
        </div>
        <div class="ai-field" style="flex:3">
          <label>API URL</label>
          <input type="url" class="ep-url" placeholder="https://example.com/v1/chat/completions">
        </div>
      </div>
      <div class="ai-field-row">
        <div class="ai-field" style="flex:3">
          <label>API Key</label>
          <input type="password" class="ep-key" placeholder="sk-..." autocomplete="off">
        </div>
        <div class="ai-field" style="flex:2">
          <label>Default Model</label>
          <input type="text" class="ep-model" value="gpt-4o-mini" placeholder="gpt-4o-mini">
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
        <div class="ai-toggle">
          <label class="ai-toggle-switch" style="width:36px;height:20px">
            <input type="checkbox" class="ep-active" value="1" checked>
            <span class="ai-toggle-track" style="width:36px;height:20px"></span>
          </label>
          <span style="font-size:.78rem;color:#64748b;margin-left:4px">Active</span>
        </div>
        <button type="button" class="ai-btn ai-btn-outline ai-btn-sm ep-save-btn" onclick="saveEndpoint(this)"><i class="bi bi-save"></i> Save</button>
        <button type="button" class="ai-btn ai-btn-outline ai-btn-sm ep-test-btn" onclick="testEndpoint(this)"><i class="bi bi-lightning"></i> Test</button>
        <button type="button" class="ai-btn ai-btn-danger ai-btn-sm" onclick="removeEndpoint(this)" title="Remove"><i class="bi bi-trash"></i></button>
        <span class="ep-status" style="font-size:.78rem;display:none"></span>
      </div>`;
    list.appendChild(div);
  }

  function removeEndpoint(btn) {
    if (!confirm('Remove this endpoint?')) return;
    const row = btn.closest('.custom-ep-row');
    if (row) {
      row.remove();
    }
  }


  // ─── Knowledge Base CRUD ───
  let kbCurrentFilter = 'all';

  function kbLoad() {
    const url = KB_API + '?action=list' + (kbCurrentFilter !== 'all' ? '&category=' + encodeURIComponent(kbCurrentFilter) : '');
    fetch(url)
      .then(r => r.json())
      .then(d => {
        const tbody = document.getElementById('kb_tbody');
        if (!d.ok || !d.data || d.data.length === 0) {
          tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:40px"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px"></i>No knowledge entries yet.<br><small>Click "Add New Entry" to train the AI.</small></td></tr>';
          return;
        }
        let html = '';
        d.data.forEach((row, i) => {
          const catClass = row.category === 'FAQ' ? 'kb-cat-faq' : row.category === 'Business Rule' ? 'kb-cat-business' : row.category === 'Terminology' ? 'kb-cat-terminology' : 'kb-cat-chip';
          const statusBadge = row.is_active == 1
            ? '<span class="ai-status-badge ai-status-active"><i class="bi bi-circle-fill" style="font-size:.4rem"></i>Active</span>'
            : '<span class="ai-status-badge ai-status-inactive"><i class="bi bi-circle-fill" style="font-size:.4rem"></i>Off</span>';
          const answerPreview = (row.answer || '').substring(0, 80) + ((row.answer || '').length > 80 ? '…' : '');
          html += '<tr>'
            + '<td style="color:#94a3b8">' + row.id + '</td>'
            + '<td><span class="kb-cat-badge ' + catClass + '">' + row.category + '</span></td>'
            + '<td class="kb-keywords">' + escHtml(row.keywords) + '</td>'
            + '<td><div class="kb-answer-wrap">'
            + (row.question ? '<div class="kb-answer-title">' + escHtml(row.question) + '</div>' : '')
            + '<div class="kb-answer-body" title="' + escHtml(row.answer) + '">' + escHtml(row.answer) + '</div>'
            + '</div></td>'

            + '<td>' + statusBadge + '</td>'
            + '<td style="white-space:nowrap">'
            + '<button class="ai-btn ai-btn-outline ai-btn-sm" onclick="kbEdit(' + row.id + ')" title="Edit"><i class="bi bi-pencil"></i></button> '
            + '<button class="ai-btn ai-btn-sm" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca" onclick="kbDelete(' + row.id + ')" title="Delete"><i class="bi bi-trash"></i></button>'
            + '</td>'
            + '</tr>';
        });
        tbody.innerHTML = html;
      })
      .catch(e => {
        document.getElementById('kb_tbody').innerHTML = '<tr><td colspan="6" style="color:#b91c1c;text-align:center">Error loading knowledge entries.</td></tr>';
      });
  }

  function kbFilterCategory(cat, btn) {
    kbCurrentFilter = cat;
    document.querySelectorAll('.kb-filter').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    kbLoad();
  }

  function kbOpenModal(data) {
    document.getElementById('kb_edit_id').value = data ? data.id : '';
    document.getElementById('kb_category').value = data ? data.category : 'FAQ';
    document.getElementById('kb_keywords').value = data ? data.keywords : '';
    document.getElementById('kb_question').value = data ? (data.question || '') : '';
    document.getElementById('kb_answer').value = data ? data.answer : '';
    document.getElementById('kb_active').checked = data ? data.is_active == 1 : true;
    document.getElementById('kb_modal_title').innerHTML = data
      ? '<i class="bi bi-pencil-square"></i> Edit Knowledge Entry #' + data.id
      : '<i class="bi bi-plus-circle"></i> Add Knowledge Entry';
    document.getElementById('kb_modal').classList.add('show');
  }

  function kbCloseModal() {
    document.getElementById('kb_modal').classList.remove('show');
  }

  function kbSaveEntry() {
    const id = document.getElementById('kb_edit_id').value;
    const fd = new FormData();
    fd.append('action', id ? 'edit' : 'add');
    if (id) fd.append('id', id);
    fd.append('category', document.getElementById('kb_category').value);
    fd.append('keywords', document.getElementById('kb_keywords').value);
    fd.append('question', document.getElementById('kb_question').value);
    fd.append('answer', document.getElementById('kb_answer').value);
    fd.append('is_active', document.getElementById('kb_active').checked ? '1' : '0');
    fd.append('csrf_token', AI_CSRF_TOKEN);

    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (d.ok) {
          kbCloseModal();
          kbLoad();
        } else {
          alert(d.error || 'Error saving entry.');
        }
      })
      .catch(e => alert('Network error.'));
  }

  function kbEdit(id) {
    fetch(KB_API + '?action=list')
      .then(r => r.json())
      .then(d => {
        if (d.ok && d.data) {
          const entry = d.data.find(r => r.id == id);
          if (entry) kbOpenModal(entry);
        }
      });
  }

  function kbDelete(id) {
    if (!confirm('Are you sure you want to delete this knowledge entry?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    fd.append('csrf_token', AI_CSRF_TOKEN);
    fetch(KB_API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => { if (d.ok) kbLoad(); else alert(d.error || 'Delete failed.'); })
      .catch(e => alert('Network error.'));
  }

  function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  // Close modal on overlay click
  document.getElementById('kb_modal').addEventListener('click', function (e) {
    if (e.target === this) kbCloseModal();
  });

  // Initial load
  kbLoad();
</script>