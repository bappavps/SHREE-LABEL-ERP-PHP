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

$moduleBaseUrl = defined('BASE_URL') ? BASE_URL . '/modules/ai_agent' : (function() {
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $appDir = str_replace('\\', '/', __DIR__);
    if ($docRoot && strpos($appDir, $docRoot) === 0) {
        return substr($appDir, strlen($docRoot)) ?: '/modules/ai_agent';
    }
    return '/shree-label-php/modules/ai_agent';
})();
require_once __DIR__ . '/config.php';
$floatChips = getAiAgentQuickChips();
?>

<link rel="stylesheet" href="<?= $moduleBaseUrl ?>/css/floating_widget.css?v=<?= time() ?>">
<link rel="stylesheet" href="<?= $moduleBaseUrl ?>/css/ai_agent.css?v=<?= time() ?>">
<style>
/* ContentEditable placeholder */
#aiChatInput:empty:before {
  content: attr(data-placeholder);
  color: #94a3b8;
  pointer-events: none;
  display: block;
}
/* Quote highlighting in floating widget input */
#aiChatInput .cmd-quote-hl {
  color: #f59e0b;
  font-weight: 600;
}
#aiChatInput .cmd-quote-hl .qp {
  opacity: 0.7;
  font-size: 0.85em;
}
#aiChatInput .cmd-quote-hl.done {
  color: #d97706;
  font-weight: 700;
}
/* Command highlight */
#aiChatInput .cmd-highlight {
  color: #ef4444;
  font-weight: 700;
}
/* Message footer with meta + copy button */
.ai-msg-footer {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  margin-top: 4px;
}
.ai-msg-footer .msg-meta {
  font-size: 11px;
  color: #475569;
  font-weight: 600;
}
/* Copy button in footer */
.ai-btn-copy-msg {
  background: none; border: none;
  color: #94a3b8;
  font-size: 11px;
  cursor: pointer;
  padding: 2px;
  border-radius: 4px;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
  opacity: 0;
}
.ai-msg:hover .ai-btn-copy-msg,
.ai-msg:active .ai-btn-copy-msg { opacity: 1; }
.ai-btn-copy-msg:hover { color: #3b82f6; }
.ai-btn-copy-msg:active { transform: scale(1.2); }
.ai-btn-copy-msg.copied { color: #22c55e; opacity: 1; }
/* Thinking indicator (fallback style if external CSS fails) */
.ai-thinking-indicator {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;
}
.ai-thinking-text {
  font-size: 0.88rem;
  color: #64748b;
  font-style: italic;
  font-weight: 500;
}
.ai-pulse {
  animation: aiPulse 1.2s infinite ease-in-out;
}
@keyframes aiPulse {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.9); }
  40% { opacity: 1; transform: scale(1.1); }
}
</style>

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
        <div class="ai-msg-bubble">
          👋 <strong>Hello! I am your AI Agent.</strong><br>
          Ask me anything about <strong>Plates, Paper Stock, Dispatches, Orders, or Costing</strong>!
        </div>
        <div class="ai-msg-footer"><span class="msg-meta">AI Copilot</span></div>
      </div>
    </div>
  </div>

  <div class="ai-chat-input-wrap" style="padding:10px 12px;gap:6px;display:flex;align-items:center;position:relative">
    <!-- Command Suggestions Dropup -->
    <div class="ai-cmd-suggestions" id="aiCmdSuggestions" style="position:absolute;bottom:calc(100% + 4px);left:8px;right:8px;background:rgba(255,255,255,0.95);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(0,0,0,0.08);border-radius:12px;padding:4px;display:none;z-index:100;box-shadow:0 -4px 24px rgba(0,0,0,0.12);max-height:180px;overflow-y:auto">
      <div class="ai-cmd-item" data-cmd="/cal" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#334155" onmouseover="this.style.background='rgba(59,130,246,0.08)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/cal</span>
        <span style="font-size:12px;color:#64748b">External Calculations</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/erp" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#334155" onmouseover="this.style.background='rgba(59,130,246,0.08)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/erp</span>
        <span style="font-size:12px;color:#64748b">ERP-Only Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/paperstock" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#334155" onmouseover="this.style.background='rgba(59,130,246,0.08)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/paperstock</span>
        <span style="font-size:12px;color:#64748b">Paper Stock Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/plate" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#334155" onmouseover="this.style.background='rgba(59,130,246,0.08)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/plate</span>
        <span style="font-size:12px;color:#64748b">Plate Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/clear" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#334155" onmouseover="this.style.background='rgba(59,130,246,0.08)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/clear</span>
        <span style="font-size:12px;color:#64748b">Clear Priority</span>
      </div>
    </div>
    <div class="ai-chat-input" id="aiChatInput" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Type query or click mic to speak..." style="font-size:.82rem;padding:8px 12px;flex:1;white-space:pre-wrap;outline:none;word-break:break-word;min-height:36px;max-height:80px;overflow-y:auto;line-height:1.4;background:#fff;border:1px solid #cbd5e1;border-radius:10px;color:#1e293b"></div>
    <button type="button" class="ai-mic-btn" id="aiMicBtn" title="Speak to AI Agent" style="padding:8px 10px;font-size:.9rem;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);color:#94a3b8;border-radius:8px;cursor:pointer;transition:all 0.2s">
      <i class="bi bi-mic-fill" id="aiMicIcon"></i>
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
  var chatInput = document.getElementById('aiChatInput');

  // ─── Real-time quote highlighting in contenteditable input ───
  function getChatText() { return chatInput ? (chatInput.innerText || chatInput.textContent || '') : ''; }

  function processChatInput() {
    if (!chatInput) return;
    var text = getChatText();
    // Escape HTML entities first so quotes become &quot; for regex matching
    var html = text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    // Step 1: highlight slash commands
    html = html.replace(/^(\/[a-zA-Z]+)/, '<span class="cmd-highlight">$1</span>');

    // Step 2: highlight quoted terms (product names) — single pass with function callback
    html = html.replace(/&quot;(.+?)&quot;/g, function(match, inner, offset, fullStr) {
      var nextChar = fullStr.charAt(offset + match.length);
      var doneClass = (nextChar === ' ' || nextChar === '' || nextChar === '\n') ? ' done' : '';
      return '<span class="cmd-quote-hl' + doneClass + '"><span class="qp">&quot;</span>' + inner + '<span class="qp">&quot;</span></span>';
    });
    html = html.replace(/[\u201C\u201D](.+?)[\u201C\u201D]/g, function(match, inner, offset, fullStr) {
      var nextChar = fullStr.charAt(offset + match.length);
      var doneClass = (nextChar === ' ' || nextChar === '' || nextChar === '\n') ? ' done' : '';
      return '<span class="cmd-quote-hl' + doneClass + '"><span class="qp">\u201C</span>' + inner + '<span class="qp">\u201D</span></span>';
    });

    if (html !== chatInput.innerHTML) {
      chatInput.innerHTML = html;
      // Move caret to end
      var range = document.createRange();
      var sel = window.getSelection();
      range.selectNodeContents(chatInput);
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

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
      // Clear saved chat history so it starts fresh next time
      sessionStorage.removeItem('ai_chat_history');
      sessionStorage.removeItem('ai_auto_open_chat');
      chatBody.innerHTML = '<div class="ai-msg assistant"><div class="ai-msg-avatar"><i class="bi bi-robot"></i></div><div class="ai-msg-content" style="font-size:.82rem"><div class="ai-msg-bubble">👋 <strong>Hello! I am your AI Agent.</strong><br>Ask me anything about <strong>Plates, Paper Stock, Dispatches, Orders, or Costing</strong>!</div><div class="ai-msg-footer"><span class="msg-meta">AI Copilot</span></div></div></div>';
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
      chatBody.innerHTML = '<div class="ai-msg assistant"><div class="ai-msg-avatar"><i class="bi bi-robot"></i></div><div class="ai-msg-content" style="font-size:.82rem"><div class="ai-msg-bubble">🧹 <em>Chat history cleared.</em><br>How can I assist you now?</div><div class="ai-msg-footer"><span class="msg-meta">AI Copilot</span></div></div></div>';
    });
  }

  // ─── ContentEditable Input Events ───
  if (chatInput) {
    chatInput.addEventListener('input', processChatInput);
  }
})();
</script>

<script src="<?= $moduleBaseUrl ?>/js/ai_agent.js?v=<?= time() ?>"></script>
