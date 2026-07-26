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

include __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/modules/ai_agent/css/ai_agent.css?v=<?= time() ?>">

<div class="ai-agent-container">
  
  <!-- Header Banner -->
  <div class="ai-header-banner">
    <div class="ai-banner-left">
      <div class="ai-banner-icon">
        <i class="bi bi-robot"></i>
      </div>
      <div>
        <h1 class="ai-banner-title"><?= e($config['module_name']) ?></h1>
        <p class="ai-banner-sub">Smart RAG Copilot for Orders, Dispatches, Paper Stock, Finished Goods, Machine Operations & Label Math</p>
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
            You can ask me questions in <strong>English, Bengali, or Hindi</strong>. Click any quick chip below or type your query!
          </div>
        </div>
      </div>

      <!-- Chat Input Area -->
      <div class="ai-chat-input-wrap">
        <input type="text" class="ai-chat-input" id="aiChatInput" placeholder="Type query or click mic to speak (English, Bengali, Hindi)..." autocomplete="off">
        <button type="button" class="ai-mic-btn" id="aiMicBtn" title="Voice Input — Speak to Type (Auto Language Detect)">
          <i class="bi bi-mic-fill"></i>
        </button>
        <button type="button" class="ai-send-btn" id="aiSendBtn">
          <i class="bi bi-send-fill"></i> Send
        </button>
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
            <button type="button" class="ai-chip-btn" data-key="<?= e($chip['key']) ?>" data-prompt="<?= e($chip['prompt']) ?>">
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

<script src="<?= BASE_URL ?>/modules/ai_agent/js/ai_agent.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
