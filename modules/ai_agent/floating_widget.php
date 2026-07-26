<?php
// ============================================================
// Standalone AI Agent Add-On Module — Floating Assistant Widget
// Name: AI Agent
// Usage: Include this file in footer.php or header.php to enable
//        floating AI Assistant across all ERP pages.
// LOCAL USE ONLY — SAFE: Read-Only, 100% Isolated Add-On
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    return;
}

$moduleBaseUrl = defined('BASE_URL') ? BASE_URL . '/modules/ai_agent' : '/shree-label-php/modules/ai_agent';
require_once __DIR__ . '/config.php';
$floatChips = getAiAgentQuickChips();
?>

<link rel="stylesheet" href="<?= $moduleBaseUrl ?>/css/floating_widget.css?v=<?= time() ?>">
<link rel="stylesheet" href="<?= $moduleBaseUrl ?>/css/ai_agent.css?v=<?= time() ?>">

<!-- Floating Trigger Button -->
<div id="aiFloatingTriggerBtn" title="AI Agent — Click to Chat">
  <div class="ai-pulse-ring"></div>
  <i class="bi bi-robot"></i>
</div>

<!-- Floating Chat Window -->
<div id="aiFloatingPopupCard">
  <div class="ai-float-header">
    <div class="ai-float-header-title">
      <i class="bi bi-robot" style="color:#d946ef"></i>
      <span>AI Agent Assistant</span>
    </div>
    <div class="ai-float-actions">
      <button type="button" class="ai-float-action-btn" id="aiFloatClearBtn" title="Clear Chat History">
        <i class="bi bi-trash"></i>
      </button>
      <button type="button" class="ai-float-action-btn" id="aiFloatMaximizeBtn" title="Maximize / Restore Window">
        <i class="bi bi-arrows-angle-expand" id="aiFloatMaxIcon"></i>
      </button>
      <button type="button" class="ai-float-action-btn" id="aiFloatCloseBtn" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
  </div>

  <!-- Quick Capabilities Chips (Shown on Maximize) -->
  <div class="ai-float-chips-wrap">
    <div class="ai-chips-title" style="margin-bottom:8px">
      <i class="bi bi-lightning-charge-fill" style="color:#eab308"></i> 21 Quick Capabilities & Instant Query Chips
    </div>
    <div class="ai-chips-grid" style="max-height:100px">
      <?php foreach ($floatChips as $chip): ?>
        <button type="button" class="ai-chip-btn" data-key="<?= e($chip['key']) ?>" data-prompt="<?= e($chip['prompt']) ?>">
          <i class="bi <?= e($chip['icon']) ?>"></i>
          <span><?= e($chip['label']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ai-chat-body" id="aiChatBody" style="flex:1;padding:14px;background:#f8fafc;overflow-y:auto">
    <div class="ai-msg assistant">
      <div class="ai-msg-avatar">
        <i class="bi bi-robot"></i>
      </div>
      <div class="ai-msg-content" style="font-size:.82rem">
        👋 <strong>Hello! I am your AI Agent.</strong><br>
        Ask me anything about <strong>Plates, Paper Stock, Dispatches, Orders, or Costing</strong>!
      </div>
    </div>
  </div>

  <div class="ai-chat-input-wrap" style="padding:10px 12px;gap:6px">
    <input type="text" class="ai-chat-input" id="aiChatInput" placeholder="Type query or click mic to speak..." style="font-size:.82rem;padding:8px 12px">
    <button type="button" class="ai-mic-btn" id="aiMicBtnFloat" title="Voice Input — Speak to Type" style="padding:8px 10px;font-size:.82rem">
      <i class="bi bi-mic-fill"></i>
    </button>
    <button type="button" class="ai-send-btn" id="aiSendBtn" style="padding:8px 14px;font-size:.82rem">
      <i class="bi bi-send-fill"></i>
    </button>
  </div>
</div>

<script>
(function() {
  window.AI_AGENT_API_URL = '<?= $moduleBaseUrl ?>/api.php';
  var triggerBtn = document.getElementById('aiFloatingTriggerBtn');
  var popupCard = document.getElementById('aiFloatingPopupCard');
  var closeBtn = document.getElementById('aiFloatCloseBtn');
  var maxBtn = document.getElementById('aiFloatMaximizeBtn');
  var maxIcon = document.getElementById('aiFloatMaxIcon');
  var clearBtn = document.getElementById('aiFloatClearBtn');
  var chatBody = document.getElementById('aiChatBody');

  // Auto-restore chat popup window state & history across page navigation
  if (sessionStorage.getItem('ai_auto_open_chat') === 'true' && popupCard) {
    popupCard.classList.add('active');
    var savedHistory = sessionStorage.getItem('ai_chat_history');
    if (savedHistory && chatBody) {
      chatBody.innerHTML = savedHistory;
      chatBody.scrollTop = chatBody.scrollHeight;
    }
    sessionStorage.removeItem('ai_auto_open_chat');
  } else if (sessionStorage.getItem('ai_chat_history') && chatBody) {
    chatBody.innerHTML = sessionStorage.getItem('ai_chat_history');
  }

  if (triggerBtn && popupCard) {
    triggerBtn.addEventListener('click', function() {
      popupCard.classList.toggle('active');
    });
  }
  if (closeBtn && popupCard) {
    closeBtn.addEventListener('click', function() {
      popupCard.classList.remove('active');
    });
  }
  if (maxBtn && popupCard) {
    maxBtn.addEventListener('click', function() {
      popupCard.classList.toggle('maximized');
      if (maxIcon) {
        if (popupCard.classList.contains('maximized')) {
          maxIcon.className = 'bi bi-arrows-angle-contract';
        } else {
          maxIcon.className = 'bi bi-arrows-angle-expand';
        }
      }
    });
  }
  if (clearBtn && chatBody) {
    clearBtn.addEventListener('click', function() {
      sessionStorage.removeItem('ai_chat_history');
      sessionStorage.removeItem('ai_auto_open_chat');
      chatBody.innerHTML = '<div class="ai-msg assistant"><div class="ai-msg-avatar"><i class="bi bi-robot"></i></div><div class="ai-msg-content" style="font-size:.82rem">🧹 <em>Chat history cleared.</em><br>How can I assist you now?</div></div>';
    });
  }
})();
</script>

<script src="<?= $moduleBaseUrl ?>/js/ai_agent.js?v=<?= time() ?>"></script>
