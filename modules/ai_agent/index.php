<?php
// ============================================================
// Standalone AI Agent Add-On Module — Main Dashboard UI
// ERP Master System — 100% Isolated Add-On Module
// SAFE: Read-Only Integration (Zero Disruption to ERP Core)
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . '/index.php');
  exit;
}

$pageTitle = 'AI Agent Assistant — ERP Master';
$config = getAiAgentConfig();
$quickChips = getAiAgentQuickChips();

// Use file modification time for cache busting - more efficient than time()
$css_version = file_exists(__DIR__ . '/css/ai_agent.css') ? filemtime(__DIR__ . '/css/ai_agent.css') : '1.0.2';
$js_version = file_exists(__DIR__ . '/js/ai_agent.js') ? filemtime(__DIR__ . '/js/ai_agent.js') : '1.0.2';

include __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/modules/ai_agent/css/ai_agent.css?v=<?= $css_version ?>">

<div class="ai-agent-container">

  <!-- Header Banner -->
  <div class="ai-header-banner">
    <div class="ai-banner-left">
      <div class="ai-banner-icon">
        <i class="bi bi-robot"></i>
      </div>
      <div>
        <h1 class="ai-banner-title"><?= e($config['module_name']) ?></h1>
        <p class="ai-banner-sub">Smart RAG Copilot for Orders, Dispatches, Paper Stock, Finished Goods, Machine
          Operations & Label Math</p>
      </div>
    </div>
    <div class="ai-banner-right">
      <span class="ai-badge-standalone">Standalone Add-On Module</span>
    </div>
  </div>

  <!-- Main Layout Grid -->
  <div class="ai-main-grid">

    <!-- Left: Interactive Chat Drawer -->
    <div class="ai-card ai-chat-card">
      <div class="ai-card-header">
        <div class="ai-card-header-title">
          <i class="bi bi-chat-left-dots-fill text-primary"></i>
          <span>ERP AI Assistant Chat</span>
        </div>
        <button type="button" class="ai-clear-btn" id="aiClearChatBtn" title="Clear Chat History">
          <i class="bi bi-trash"></i> Clear Stream
        </button>
      </div>

      <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-msg assistant">
          <div class="ai-msg-avatar">
            <i class="bi bi-robot"></i>
          </div>
          <div class="ai-msg-content">
            👋 <strong>Welcome to Shree Label ERP AI Assistant!</strong><br>
            I am your dedicated <strong>Industrial ERP & Label Manufacturing Copilot</strong>.<br><br>
            You can ask me questions in <strong>English, Bengali, or Hindi</strong>. Click any quick chip below or type
            your query!
          </div>
        </div>
      </div>

      <!-- Chat Input Area -->
      <div class="ai-chat-input-wrap" style="position:relative;">
        <input type="text" class="ai-chat-input" id="aiChatInput"
          placeholder="Type query or click mic to speak (English, Bengali, Hindi)..." autocomplete="off">
        <button type="button" class="ai-mic-btn" id="aiMicBtn"
          title="Voice Input — Speak to Type (Auto Language Detect)">
          <i class="bi bi-mic-fill"></i>
        </button>
        <button type="button" class="ai-send-btn" id="aiSendBtn">
          <i class="bi bi-send-fill"></i> Send
        </button>
        <!-- 3-Level AI Suggestion Dropdown (commands → query examples → entities) -->
        <div id="aiCmdSuggestions" class="ai-cmd-suggestions"
          style="display:none;position:absolute;left:0;right:0;bottom:100%;margin-bottom:6px;z-index:1000;background:#1e293b;border:1px solid #334155;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,0.45);max-height:260px;overflow-y:auto;"></div>
      </div>
    </div>

    <!-- Right: Quick Chips & Telemetry -->
    <div class="ai-sidebar">

      <!-- 21 Quick Action Chips -->
      <div class="ai-card">
        <div class="ai-card-header">
          <div class="ai-card-header-title">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            <span>21 Quick Capabilities</span>
          </div>
        </div>
        <div class="ai-chips-grid">
          <?php foreach ($quickChips as $chip): ?>
            <button type="button" class="ai-chip-btn" data-key="<?= e($chip['key']) ?>"
              data-prompt="<?= e($chip['prompt']) ?>">
              <i class="bi <?= e($chip['icon']) ?>"></i>
              <span><?= e($chip['label']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Telemetry Drawer -->
      <div class="ai-card ai-telemetry-card">
        <div class="ai-card-header">
          <div class="ai-card-header-title">
            <i class="bi bi-cpu-fill text-info"></i>
            <span>Connection Telemetry</span>
          </div>
        </div>
        <div class="ai-telemetry-body">
          <div class="ai-tel-item">
            <span class="ai-tel-label">Engine Provider</span>
            <span class="ai-tel-val" id="aiTelemetryApiStatus">Gemini Pro API</span>
          </div>
          <div class="ai-tel-item">
            <span class="ai-tel-label">Last Tool Used</span>
            <span class="ai-tel-val" id="aiTelemetryLastTool">General ERP RAG</span>
          </div>
          <div class="ai-tel-item">
            <span class="ai-tel-label">Total Queries</span>
            <span class="ai-tel-val" id="aiTelemetryTotalQueries">0</span>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
  // Base URL for the standalone AI Agent chat (Level 3 entity autocomplete fetch)
  window.aiAgentParams = { baseUrl: <?= json_encode(BASE_URL) ?> };
</script>
<script src="<?= BASE_URL ?>/modules/ai_agent/js/ai_agent.js?v=<?= $js_version ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>