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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<style>
/* ContentEditable placeholder */
#aiFloatingChatInput:empty:before {
  content: attr(data-placeholder);
  color: #94a3b8;
  pointer-events: none;
  display: block;
}
/* Quote highlighting in floating widget input */
#aiFloatingChatInput .cmd-quote-hl {
  color: #f59e0b;
  font-weight: 600;
}
#aiFloatingChatInput .cmd-quote-hl .qp {
  opacity: 0.7;
  font-size: 0.85em;
}
#aiFloatingChatInput .cmd-quote-hl.done {
  color: #d97706;
  font-weight: 700;
}
/* Command highlight */
#aiFloatingChatInput .cmd-highlight {
  color: #ef4444;
  font-weight: 700;
}
/* ─── Floating Chat Body Styles (PWA-exact) ─── */
@keyframes floatMsgSlide {
  from { opacity: 0; transform: translateY(12px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
#aiFloatingChatBody .msg-group {
  display: flex; flex-direction: column; max-width: 96%;
  animation: floatMsgSlide 0.3s cubic-bezier(0.22, 1, 0.36, 1);
  margin-bottom: 14px;
}
#aiFloatingChatBody .msg-group.user { align-self: flex-end; }
#aiFloatingChatBody .msg-group.assistant { align-self: flex-start; }
#aiFloatingChatBody .msg-row { display: flex; gap: 8px; align-items: flex-end; }
#aiFloatingChatBody .msg-group.user .msg-row { flex-direction: row-reverse; }
#aiFloatingChatBody .msg-avatar {
  width: 30px; height: 30px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: #fff; flex-shrink: 0; margin-bottom: 18px;
}
#aiFloatingChatBody .msg-group.assistant .msg-avatar { background: linear-gradient(135deg, #3b82f6, #8b5cf6); }
#aiFloatingChatBody .msg-group.user .msg-avatar { background: linear-gradient(135deg, #10b981, #059669); }
#aiFloatingChatBody .msg-content { display: flex; flex-direction: column; }
#aiFloatingChatBody .msg-bubble {
  padding: 12px 16px; border-radius: 18px; font-size: 14px;
  line-height: 1.6; word-break: break-word; position: relative;
}
#aiFloatingChatBody .msg-group.user .msg-bubble {
  background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
  color: #fff; border-bottom-right-radius: 6px;
  box-shadow: 0 4px 16px rgba(37,99,235,0.25);
}
#aiFloatingChatBody .msg-group.assistant .msg-bubble {
  background: rgba(255,255,255,0.05); color: #e2e8f0;
  border: 1px solid rgba(255,255,255,0.08);
  border-bottom-left-radius: 6px; backdrop-filter: blur(8px);
}
#aiFloatingChatBody .msg-bubble p { margin: 0 0 12px 0; }
#aiFloatingChatBody .msg-bubble p:last-child { margin-bottom: 0; }
#aiFloatingChatBody .msg-bubble strong { color: #60a5fa; font-weight: 700; }
#aiFloatingChatBody .msg-group.user .msg-bubble strong { color: #bfdbfe; }
#aiFloatingChatBody .msg-bubble code { background: rgba(0,0,0,0.35); padding: 2px 6px; border-radius: 5px; color: #f472b6; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; }
#aiFloatingChatBody .msg-bubble pre { background: rgba(0,0,0,0.35); padding: 10px 12px; border-radius: 10px; overflow-x: auto; margin: 8px 0; }
#aiFloatingChatBody .msg-bubble pre code { background: none; padding: 0; color: #e2e8f0; font-size: 12.5px; }
#aiFloatingChatBody .msg-bubble a { color: #38bdf8; text-decoration: none; font-weight: 600; border-bottom: 1px dashed rgba(56,189,248,0.4); }
#aiFloatingChatBody .msg-bubble table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 12px; }
#aiFloatingChatBody .msg-bubble th, #aiFloatingChatBody .msg-bubble td { padding: 6px 8px; border: 1px solid rgba(255,255,255,0.1); text-align: left; }
#aiFloatingChatBody .msg-bubble th { background: rgba(59,130,246,0.15); color: #93c5fd; font-weight: 700; }
#aiFloatingChatBody .msg-bubble tr:nth-child(even) td { background: rgba(255,255,255,0.03); }
#aiFloatingChatBody .msg-bubble ul, #aiFloatingChatBody .msg-bubble ol { padding-left: 18px; margin: 6px 0; }
#aiFloatingChatBody .msg-bubble li { margin-bottom: 3px; }
#aiFloatingChatBody .msg-bubble blockquote { border-left: 3px solid #3b82f6; padding-left: 10px; margin: 8px 0; color: #94a3b8; font-style: italic; }
/* Footer with meta + copy button */
#aiFloatingChatBody .msg-footer {
  display: flex; align-items: center; gap: 8px;
  margin-top: 4px; padding: 0 2px;
}
#aiFloatingChatBody .msg-group.user .msg-footer { justify-content: flex-end; }
#aiFloatingChatBody .msg-group.assistant .msg-footer { justify-content: flex-start; }
#aiFloatingChatBody .msg-meta { font-size: 10px; color: #64748b; font-weight: 500; letter-spacing: 0.02em; }
/* Copy button in footer — PWA style */
#aiFloatingChatBody .btn-copy-msg {
  display: none; background: none; border: none;
  color: #94a3b8; font-size: 12px; cursor: pointer;
  padding: 2px 6px; border-radius: 6px;
  transition: all 0.2s; align-items: center; gap: 3px;
}
#aiFloatingChatBody .msg-group:hover .btn-copy-msg { display: inline-flex; }
#aiFloatingChatBody .btn-copy-msg:hover { background: rgba(255,255,255,0.08); color: #e2e8f0; }
#aiFloatingChatBody .btn-copy-msg:active { transform: scale(1.15); }
#aiFloatingChatBody .btn-copy-msg.copied { color: #22c55e; }
/* Thinking indicator matching PWA typing-box */
#aiFloatingChatBody .ai-thinking-indicator {
  display: none; align-self: flex-start;
  padding: 14px 18px; border-radius: 18px;
  border-bottom-left-radius: 6px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  margin-left: 0; margin-top: 4px;
}
#aiFloatingChatBody .ai-thinking-indicator.visible { display: flex; align-items: center; gap: 10px; }
#aiFloatingChatBody .ai-thinking-text { font-size: 12px; color: #64748b; font-weight: 600; }
#aiFloatingChatBody .ai-pulse { animation: aiPulse 1.2s infinite ease-in-out; }
@keyframes aiPulse {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.9); }
  40% { opacity: 1; transform: scale(1.1); }
}
/* Tool call tag — PWA style */
#aiFloatingChatBody .ai-tool-call-tag {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(59,130,246,0.12); color: #60a5fa;
  font-size: 11px; font-weight: 600; padding: 4px 10px;
  border-radius: 6px; margin-bottom: 8px;
}
/* Code block wrapper + copy button — PWA style */
#aiFloatingChatBody .code-block-wrapper { position: relative; margin: 8px 0; }
#aiFloatingChatBody .code-block-wrapper .btn-copy-code {
  position: absolute; top: 6px; right: 6px;
  background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2);
  color: #94a3b8; font-size: 11px; padding: 3px 8px; border-radius: 6px;
  cursor: pointer; display: flex; align-items: center; gap: 4px;
  transition: all 0.2s; z-index: 5;
}
#aiFloatingChatBody .code-block-wrapper .btn-copy-code:hover { background: rgba(255,255,255,0.25); color: #fff; }
/* Command-specific bubbles */
#aiFloatingChatBody .msg-group.ai-cmd-paperstock .msg-bubble { border-color: #10b981; border-width: 1.5px 1.5px 1.5px 3px; }
#aiFloatingChatBody .msg-group.ai-cmd-plate .msg-bubble { border-color: #8b5cf6; border-width: 1.5px 1.5px 1.5px 3px; }
#aiFloatingChatBody .msg-group.ai-cmd-quoted .msg-bubble { border-color: #f59e0b; border-width: 1.5px 1.5px 1.5px 3px; }
/* Suggestion box — PWA exact */
#aiFloatingChatBody .ai-suggestion-box {
  margin-top: 10px; padding-top: 8px;
  border-top: 1px dashed rgba(255,255,255,0.12);
}
#aiFloatingChatBody .ai-suggestion-title {
  font-size: 11px; font-weight: 700; color: #94a3b8;
  margin-bottom: 6px; display: flex; align-items: center; gap: 4px;
  text-transform: uppercase; letter-spacing: 0.04em;
}
#aiFloatingChatBody .ai-suggestion-chips { display: flex; flex-wrap: wrap; gap: 6px; }
#aiFloatingChatBody .ai-suggestion-chip {
  background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.35);
  color: #60a5fa; padding: 5px 12px; border-radius: 14px;
  font-size: 11.5px; font-weight: 600; cursor: pointer;
  transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 5px;
}
#aiFloatingChatBody .ai-suggestion-chip:hover,
#aiFloatingChatBody .ai-suggestion-chip:active {
  background: #3b82f6; color: #fff;
  transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.4);
}
/* Welcome animation */
#aiFloatingChatBody .msg-group.welcome .msg-bubble {
  background: linear-gradient(135deg, rgba(59,130,246,0.08), rgba(168,85,247,0.08));
  border: 1px solid rgba(168,85,247,0.15);
}
/* Scrollbar matching PWA */
#aiFloatingChatBody::-webkit-scrollbar { width: 4px; }
#aiFloatingChatBody::-webkit-scrollbar-track { background: transparent; }
#aiFloatingChatBody::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
#aiFloatingChatBody::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
/* Quick chips section — PWA-style toggle */
.ai-float-chips-toggle {
  display: flex; align-items: center; justify-content: center;
  gap: 6px; padding: 8px 14px;
  background: rgba(255,255,255,0.03);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  color: #94a3b8; font-size: 12px; font-weight: 700;
  cursor: pointer; transition: all 0.2s;
  user-select: none;
}
.ai-float-chips-toggle:active { background: rgba(255,255,255,0.06); }
.ai-float-chips-toggle i { transition: transform 0.3s ease; font-size: 10px; }
.ai-float-chips-toggle.open i:last-child { transform: rotate(180deg); }
.ai-float-chips-section {
  max-height: 0; overflow: hidden;
  transition: max-height 0.35s cubic-bezier(0.4,0,0.2,1);
  background: rgba(10,15,30,0.7);
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.ai-float-chips-section.open { max-height: 400px; }
.ai-float-chips-grid { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 12px; }
.ai-float-chip-item {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  color: #cbd5e1; font-size: 11.5px; font-weight: 600;
  padding: 7px 12px; border-radius: 10px;
  cursor: pointer; display: flex; align-items: center; gap: 5px;
  transition: all 0.2s ease; white-space: nowrap;
}
.ai-float-chip-item:active { transform: scale(0.96); background: rgba(255,255,255,0.08); }
.ai-float-chip-icon { font-size: 12px; color: #94a3b8; }

/* Mic button listening state (PWA-style) */
#aiFloatingChatBody ~ .ai-chat-input-wrap .btn-mic.listening {
  color: #ef4444 !important;
  background: rgba(239, 68, 68, 0.2) !important;
  box-shadow: 0 0 12px rgba(239, 68, 68, 0.4);
  animation: pulseMic 1.5s infinite;
}
@keyframes pulseMic {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.1); }
}
/* Theme toggle icons — PWA style */
#aiFloatingPopupCard[data-theme="light"] .ai-float-theme-btn .icon-moon { display: none; }
#aiFloatingPopupCard[data-theme="dark"] .ai-float-theme-btn .icon-sun { display: none; }
#aiFloatingPopupCard:not([data-theme="light"]) .ai-float-theme-btn .icon-sun { display: none; }
/* Light theme overrides for floating widget */
#aiFloatingPopupCard[data-theme="light"] {
  background: #ffffff !important;
  box-shadow: 0 8px 40px rgba(0,0,0,0.12) !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-float-header {
  background: rgba(0,0,0,0.03) !important;
  border-bottom: 1px solid rgba(0,0,0,0.06) !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-float-header-title span { color: #0f172a !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-action-btn { color: #64748b !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-action-btn:hover { background: rgba(0,0,0,0.06) !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-chat-body { background: #f8fafc !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.assistant .msg-bubble {
  background: #ffffff !important; color: #1e293b !important;
  border: 1px solid rgba(0,0,0,0.08) !important; backdrop-filter: none !important;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04) !important;
}
#aiFloatingPopupCard[data-theme="light"] .msg-group.user .msg-bubble {
  background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%) !important;
  color: #fff !important;
  box-shadow: 0 4px 16px rgba(37,99,235,0.25) !important;
}
#aiFloatingPopupCard[data-theme="light"] .msg-meta { color: #94a3b8 !important; }
#aiFloatingPopupCard[data-theme="light"] .btn-copy-msg { color: #94a3b8 !important; }
#aiFloatingPopupCard[data-theme="light"] .btn-copy-msg:hover { background: rgba(0,0,0,0.06) !important; color: #0f172a !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-chat-input-wrap {
  background: rgba(0,0,0,0.03) !important;
  border-top: 1px solid rgba(0,0,0,0.06) !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-chat-input { color: #0f172a !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-chat-input:empty:before { color: #94a3b8 !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-suggestion-chip {
  background: rgba(37,99,235,0.08) !important;
  border-color: rgba(37,99,235,0.25) !important;
  color: #2563eb !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-suggestion-chip:hover,
#aiFloatingPopupCard[data-theme="light"] .ai-suggestion-chip:active {
  background: #2563eb !important; color: #fff !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-suggestion-title { color: #64748b !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-suggestion-box { border-top-color: rgba(0,0,0,0.08) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.welcome .msg-bubble {
  background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.04)) !important;
  border-color: rgba(124,58,237,0.12) !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-tool-call-tag { background: rgba(37,99,235,0.08) !important; color: #2563eb !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-thinking-indicator { background: rgba(0,0,0,0.04) !important; border-color: rgba(0,0,0,0.08) !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-thinking-text { color: #94a3b8 !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-chat-input-wrap .btn-mic { color: #94a3b8 !important; }
#aiFloatingPopupCard[data-theme="light"]::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble code { background: rgba(0,0,0,0.08) !important; color: #db2777 !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble pre { background: rgba(0,0,0,0.06) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble pre code { color: #1e293b !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble a { color: #2563eb !important; border-color: rgba(37,99,235,0.3) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble th { background: rgba(37,99,235,0.08) !important; color: #1d4ed8 !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble td, #aiFloatingPopupCard[data-theme="light"] .msg-bubble th { border-color: rgba(0,0,0,0.08) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble tr:nth-child(even) td { background: rgba(0,0,0,0.02) !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-bubble strong { color: #2563eb !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.user .msg-bubble strong { color: #dbeafe !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.ai-cmd-paperstock .msg-bubble { border-color: #10b981 !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.ai-cmd-plate .msg-bubble { border-color: #8b5cf6 !important; }
#aiFloatingPopupCard[data-theme="light"] .msg-group.ai-cmd-quoted .msg-bubble { border-color: #f59e0b !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-chips-toggle { color: #475569 !important; background: rgba(0,0,0,0.02) !important; border-bottom-color: rgba(0,0,0,0.06) !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-chips-section { background: rgba(255,255,255,0.7) !important; border-bottom-color: rgba(0,0,0,0.06) !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-chip-item {
  background: rgba(0,0,0,0.03) !important;
  border-color: rgba(0,0,0,0.08) !important;
  color: #334155 !important;
}
#aiFloatingPopupCard[data-theme="light"] .ai-float-chip-item:active { background: rgba(0,0,0,0.06) !important; }
#aiFloatingPopupCard[data-theme="light"] .ai-float-chip-icon { color: #64748b !important; }
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
      <button type="button" class="ai-float-action-btn ai-float-theme-btn" id="aiFloatThemeBtn" title="Toggle Theme">
        <i class="bi bi-moon-fill icon-moon"></i>
        <i class="bi bi-sun-fill icon-sun"></i>
      </button>
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

  <!-- Quick Capabilities Chips (PWA-style toggle) -->
  <div class="ai-float-chips-toggle" id="aiFloatChipsToggle">
    <i class="bi bi-grid-3x3-gap-fill"></i> Quick Actions
    <i class="bi bi-chevron-down"></i>
  </div>
  <div class="ai-float-chips-section" id="aiFloatChipsSection">
    <div class="ai-float-chips-grid">
      <?php foreach ($floatChips as $chip): ?>
        <button type="button" class="ai-float-chip-item" data-key="<?= e($chip['key']) ?>" data-prompt="<?= e($chip['prompt']) ?>">
          <i class="bi <?= e($chip['icon']) ?> ai-float-chip-icon"></i>
          <span><?= e($chip['label']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ai-chat-body" id="aiFloatingChatBody" style="flex:1;padding:14px;background:#0a0f1e;overflow-y:auto;scroll-behavior:smooth">
    <div class="msg-group assistant welcome">
      <div class="msg-row">
        <div class="msg-avatar"><i class="bi bi-robot"></i></div>
        <div class="msg-content">
          <div class="msg-bubble">
            👋 <strong>Hello! I am your AI Agent.</strong><br>
            Ask me anything about <strong>Plates, Paper Stock, Dispatches, Orders, or Costing</strong>!
          </div>
          <div class="msg-footer">
            <span class="msg-meta">AI Copilot</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="ai-chat-input-wrap" style="padding:8px 12px;gap:6px;display:flex;align-items:center;position:relative;background:rgba(15,23,42,0.95);border-top:1px solid rgba(255,255,255,0.06)">
    <!-- Command Suggestions Dropup -->
    <div class="ai-cmd-suggestions" id="aiFloatingCmdSuggestions" style="position:absolute;bottom:calc(100% + 4px);left:8px;right:8px;background:rgba(30,41,59,0.98);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:4px;display:none;z-index:100;box-shadow:0 -4px 24px rgba(0,0,0,0.3);max-height:180px;overflow-y:auto">
      <div class="ai-cmd-item" data-cmd="/cal" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/cal</span>
        <span style="font-size:12px;color:#94a3b8">External Calculations</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/erp" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/erp</span>
        <span style="font-size:12px;color:#94a3b8">ERP-Only Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/paperstock" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/paperstock</span>
        <span style="font-size:12px;color:#94a3b8">Paper Stock Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/plate" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/plate</span>
        <span style="font-size:12px;color:#94a3b8">Plate Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/clear" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/clear</span>
        <span style="font-size:12px;color:#94a3b8">Clear Priority</span>
      </div>
    </div>
    <button type="button" class="btn-input btn-mic" id="aiFloatingMicBtn" title="Speak to AI Agent" style="background:none;border:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;transition:all 0.2s ease;color:#64748b;margin-right:4px">
      <i class="bi bi-mic-fill" id="aiFloatingMicIcon"></i>
    </button>
    <div class="ai-chat-input" id="aiFloatingChatInput" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Ask about stock, orders or speak..." style="flex:1;background:transparent;border:none;color:#fff;font-size:14px;padding:10px 0;outline:none;resize:none;max-height:100px;line-height:1.4;font-family:inherit;white-space:pre-wrap;overflow-y:auto;word-break:break-word"></div>
    <div class="input-actions" style="display:flex;align-items:center;gap:4px;padding-bottom:2px">
      <button type="button" class="btn-input btn-send" id="aiFloatingSendBtn" title="Send" style="background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;width:40px;height:40px;border:none;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;box-shadow:0 3px 12px rgba(37,99,235,0.4);transition:all 0.2s ease" onmousedown="this.style.transform='scale(0.88)'" onmouseup="this.style.transform='scale(1)'">
        <i class="bi bi-send-fill"></i>
      </button>
    </div>
  </div>

</div>

<script>
(function() {
  'use strict';

  var API_URL = '<?= $moduleBaseUrl ?>/api.php';
  var triggerBtn = document.getElementById('aiFloatingTriggerBtn');
  var popupCard = document.getElementById('aiFloatingPopupCard');
  var closeBtn = document.getElementById('aiFloatCloseBtn');
  var maxBtn = document.getElementById('aiFloatMaximizeBtn');
  var maxIcon = document.getElementById('aiFloatMaxIcon');
  var clearBtn = document.getElementById('aiFloatClearBtn');
  var chatBody = document.getElementById('aiFloatingChatBody');
  var chatInput = document.getElementById('aiFloatingChatInput');
  var sendBtn = document.getElementById('aiFloatingSendBtn');
  var micBtn = document.getElementById('aiFloatingMicBtn');
  var micIcon = document.getElementById('aiFloatingMicIcon');

  var isProcessing = false;

  // ─── Utility: HTML escape ───
  function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  // ─── Format Markdown using marked.js (like PWA) ───
  function fmtMd(str) {
    if (!str) return '';
    if (typeof marked !== 'undefined') {
      marked.setOptions({ breaks: true, gfm: true });
      return marked.parse(str);
    }
    // fallback: basic text only
    return esc(str).replace(/\n/g, '<br>');
  }

  // ─── Enhance code blocks with copy button (PWA-identical) ───
  function enhanceCodeBlocks(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    d.querySelectorAll('pre').forEach(function(pre) {
      var wrapper = document.createElement('div');
      wrapper.className = 'code-block-wrapper';
      pre.parentNode.insertBefore(wrapper, pre);
      wrapper.appendChild(pre);
      var copyBtn = document.createElement('button');
      copyBtn.className = 'btn-copy-code';
      copyBtn.type = 'button';
      copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy code';
      copyBtn.onclick = function() {
        var code = pre.querySelector('code') ? pre.querySelector('code').innerText : pre.innerText;
        navigator.clipboard.writeText(code).then(function() {
          copyBtn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
          setTimeout(function() { copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy code'; }, 2000);
        });
      };
      wrapper.appendChild(copyBtn);
    });
    return d.innerHTML;
  }

  // ─── Global copy function (PWA-style, used by onclick) ───
  window._floatCopyMsg = function(btn) {
    var bubble = btn.closest('.msg-content').querySelector('.msg-bubble');
    var txt = (bubble && (bubble.innerText || bubble.textContent)) || '';
    navigator.clipboard.writeText(txt).then(function() {
      btn.innerHTML = '<i class="bi bi-check-lg"></i>';
      btn.classList.add('copied');
      setTimeout(function() { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; btn.classList.remove('copied'); }, 1500);
    });
  };

  // ─── Append a message to floating chat body (PWA-identical HTML) ───
  function addMsg(text, sender, toolUsed, suggestions, commandType) {
    if (!chatBody) return;

    var now = new Date();
    var timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    var label = sender === 'user' ? 'You' : 'AI Copilot';

    var bubbleHtml = '';
    if (toolUsed && sender === 'assistant') {
      bubbleHtml += '<div class="ai-tool-call-tag"><i class="bi bi-lightning-charge-fill"></i> Executed ERP Tool: ' + esc(toolUsed) + '</div>';
    }
    if (text.indexOf('ai-thinking-indicator') !== -1) {
      bubbleHtml += text;
    } else {
      var parsed = fmtMd(text);
      if (sender === 'assistant') parsed = enhanceCodeBlocks(parsed);
      bubbleHtml += parsed;
    }

    if (sender === 'assistant' && suggestions && suggestions.length > 0) {
      bubbleHtml += '<div class="ai-suggestion-box"><div class="ai-suggestion-title"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Suggested Questions:</div><div class="ai-suggestion-chips">';
      for (var s = 0; s < suggestions.length; s++) {
        bubbleHtml += '<button type="button" class="ai-suggestion-chip" data-prompt="' + esc(suggestions[s]) + '"><i class="bi bi-chat-left-text"></i> ' + esc(suggestions[s]) + '</button>';
      }
      bubbleHtml += '</div></div>';
    }

    var cmdClass = commandType ? ' ai-cmd-' + commandType : '';
    var html = '<div class="msg-group ' + sender + cmdClass + '">'
      + '<div class="msg-row">'
      + '<div class="msg-avatar">' + (sender === 'user' ? '<i class="bi bi-person-fill"></i>' : '<i class="bi bi-robot"></i>') + '</div>'
      + '<div class="msg-content">'
      + '<div class="msg-bubble">' + bubbleHtml + '</div>'
      + '<div class="msg-footer">'
      + '<span class="msg-meta">' + label + ' \u00B7 ' + timeStr + '</span>'
      + '<button class="btn-copy-msg" onclick="_floatCopyMsg(this)" title="Copy"><i class="bi bi-clipboard"></i></button>'
      + '</div></div></div></div>';

    chatBody.insertAdjacentHTML('beforeend', html);
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  // ─── Suggestion chip delegation ───
  if (chatBody) {
    chatBody.addEventListener('click', function(e) {
      var chip = e.target.closest('.ai-suggestion-chip');
      if (chip) {
        var p = chip.getAttribute('data-prompt');
        if (p && !isProcessing) { doSend(p); }
      }
    });
  }

  // ─── Send query ───
  function doSend(promptText) {
    var query = promptText || (chatInput ? (chatInput.innerText || chatInput.textContent || '').trim() : '');
    if (!query || isProcessing) return;

    if (chatInput) chatInput.innerHTML = '';
    isProcessing = true;

    addMsg(query, 'user');

    // Thinking indicator
    addMsg('<div class="ai-thinking-indicator" id="aiFloatThinkingIndicator"><i class="bi bi-three-dots ai-pulse"></i> <em class="ai-thinking-text">Thinking</em></div>', 'assistant');
    var statuses = ['Thinking','Processing','Searching','Analyzing','Fetching','Targeting','Computing'];
    var sIdx = 0, dCount = 0;
    if (window._floatTypingInterval) clearInterval(window._floatTypingInterval);
    window._floatTypingInterval = setInterval(function() {
      var el = document.querySelector('#aiFloatThinkingIndicator .ai-thinking-text');
      if (el) {
        dCount = (dCount + 1) % 4;
        el.textContent = statuses[sIdx] + '.'.repeat(dCount > 0 ? dCount : 3);
        if (dCount === 0) sIdx = (sIdx + 1) % statuses.length;
      }
    }, 400);

    var body = new FormData();
    body.set('action', 'query');
    body.set('prompt', query);

    fetch(API_URL, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function(res) { return res.json(); })
      .then(function(res) {
        if (window._floatTypingInterval) { clearInterval(window._floatTypingInterval); window._floatTypingInterval = null; }
        var msgs = chatBody ? chatBody.querySelectorAll('.msg-group.assistant') : [];
        if (msgs.length > 0) {
          var last = msgs[msgs.length - 1];
          if (last.innerHTML.indexOf('ai-thinking-indicator') !== -1) last.remove();
        }
        isProcessing = false;
        if (!res || !res.ok) {
          addMsg(res ? (res.error || 'Unable to process query.') : 'Network error.', 'assistant');
          return;
        }
        addMsg(res.answer || 'Query completed.', 'assistant', res.tool_used, res.suggestions, res.command_type);
        try { sessionStorage.setItem('ai_float_chat_history', chatBody.innerHTML); } catch(e){}
        if (res.nav_url) {
          try {
            sessionStorage.setItem('ai_float_chat_history', chatBody.innerHTML);
            sessionStorage.setItem('ai_float_auto_open', 'true');
          } catch(e){}
          setTimeout(function() { window.location.href = res.nav_url; }, 1500);
        }
      })
      .catch(function(err) {
        if (window._floatTypingInterval) { clearInterval(window._floatTypingInterval); window._floatTypingInterval = null; }
        var msgs = chatBody ? chatBody.querySelectorAll('.msg-group.assistant') : [];
        if (msgs.length > 0) {
          var last = msgs[msgs.length - 1];
          if (last.innerHTML.indexOf('ai-thinking-indicator') !== -1) last.remove();
        }
        isProcessing = false;
        addMsg('Error: ' + (err.message || 'Server connection failed.'), 'assistant');
      });
  }

  // ─── Real-time quote highlighting in contenteditable input ───
  function getChatText() { return chatInput ? (chatInput.innerText || chatInput.textContent || '') : ''; }

  function getChatCursorPos() {
    var sel = window.getSelection();
    if (sel.rangeCount === 0) return 0;
    var r = sel.getRangeAt(0);
    var pre = document.createRange();
    pre.selectNodeContents(chatInput);
    pre.setEnd(r.startContainer, r.startOffset);
    return pre.toString().length;
  }

  function setChatCursorPos(chars) {
    if (chars >= 0) {
      var sel = window.getSelection();
      var range = document.createRange();
      var charIndex = 0, nodeStack = [chatInput], node, foundStart = false, stop = false;
      range.setStart(chatInput, 0);
      range.collapse(true);
      while (!stop && (node = nodeStack.pop())) {
        if (node.nodeType === 3) {
          var nextCharIndex = charIndex + node.length;
          if (!foundStart && chars >= charIndex && chars <= nextCharIndex) {
            range.setStart(node, chars - charIndex);
            range.setEnd(node, chars - charIndex);
            stop = true;
          }
          charIndex = nextCharIndex;
        } else {
          var i = node.childNodes.length;
          while (i--) nodeStack.push(node.childNodes[i]);
        }
      }
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  function processChatInput() {
    if (!chatInput) return;
    var text = getChatText();
    var html = text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
    html = html.replace(/^(\/[a-zA-Z]+)/, '<span class="cmd-highlight">$1</span>');
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
      var savedPos = getChatCursorPos();
      chatInput.innerHTML = html;
      setChatCursorPos(savedPos);
    }
  }

  // ─── Restore history & auto-open ───
  if (sessionStorage.getItem('ai_float_auto_open') === 'true' && popupCard) {
    popupCard.classList.add('active');
    var saved = sessionStorage.getItem('ai_float_chat_history');
    if (saved && chatBody) { chatBody.innerHTML = saved; chatBody.scrollTop = chatBody.scrollHeight; }
    sessionStorage.removeItem('ai_float_auto_open');
  } else if (sessionStorage.getItem('ai_float_chat_history') && chatBody) {
    chatBody.innerHTML = sessionStorage.getItem('ai_float_chat_history');
  }

  // ─── Theme Management (PWA-identical) ───
  var floatTheme = localStorage.getItem('erp-ai-theme') || 'dark';
  function applyFloatTheme(theme) {
    popupCard.setAttribute('data-theme', theme);
    localStorage.setItem('erp-ai-theme', theme);
    floatTheme = theme;
  }
  applyFloatTheme(floatTheme);
  var themeBtn = document.getElementById('aiFloatThemeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function() {
      var next = floatTheme === 'dark' ? 'light' : 'dark';
      applyFloatTheme(next);
      if (navigator.vibrate) navigator.vibrate(10);
    });
  }

  // ─── Chips toggle (PWA-identical) ───
  var chipsToggle = document.getElementById('aiFloatChipsToggle');
  var chipsSection = document.getElementById('aiFloatChipsSection');
  if (chipsToggle && chipsSection) {
    chipsToggle.addEventListener('click', function() {
      chipsSection.classList.toggle('open');
      chipsToggle.classList.toggle('open');
    });
  }

  // ─── Open/close toggle ───
  if (triggerBtn && popupCard) {
    triggerBtn.addEventListener('click', function() { popupCard.classList.toggle('active'); });
  }
  if (closeBtn && popupCard) {
    closeBtn.addEventListener('click', function() {
      popupCard.classList.remove('active');
      sessionStorage.removeItem('ai_float_chat_history');
      sessionStorage.removeItem('ai_float_auto_open');
      chatBody.innerHTML = '<div class="msg-group assistant welcome"><div class="msg-row"><div class="msg-avatar"><i class="bi bi-robot"></i></div><div class="msg-content"><div class="msg-bubble">👋 <strong>Hello! I am your AI Agent.</strong><br>Ask me anything about <strong>Plates, Paper Stock, Dispatches, Orders, or Costing</strong>!</div><div class="msg-footer"><span class="msg-meta">AI Copilot</span></div></div></div></div>';
    });
  }
  if (maxBtn && popupCard) {
    maxBtn.addEventListener('click', function() {
      popupCard.classList.toggle('maximized');
      if (maxIcon) maxIcon.className = popupCard.classList.contains('maximized') ? 'bi bi-arrows-angle-contract' : 'bi bi-arrows-angle-expand';
    });
  }
  if (clearBtn && chatBody) {
    clearBtn.addEventListener('click', function() {
      sessionStorage.removeItem('ai_float_chat_history');
      sessionStorage.removeItem('ai_float_auto_open');
      chatBody.innerHTML = '<div class="msg-group assistant welcome"><div class="msg-row"><div class="msg-avatar"><i class="bi bi-robot"></i></div><div class="msg-content"><div class="msg-bubble">🧹 <em>Chat history cleared.</em><br>How can I assist you now?</div><div class="msg-footer"><span class="msg-meta">AI Copilot</span></div></div></div></div>';
    });
  }

  // ─── Send button click ───
  if (sendBtn) sendBtn.addEventListener('click', function() { doSend(); });

  // ─── ContentEditable Input Events ───
  if (chatInput) {
    chatInput.addEventListener('input', processChatInput);
    chatInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
    });
    chatInput.addEventListener('input', function() {
      var val = chatInput.innerText || chatInput.textContent || '';
      var sugBox = document.getElementById('aiFloatingCmdSuggestions');
      if (!sugBox) return;
      if (val.startsWith('/') && val.indexOf(' ') === -1) {
        sugBox.style.display = 'block';
        sugBox.querySelectorAll('.ai-cmd-item').forEach(function(el) {
          el.style.display = el.getAttribute('data-cmd').indexOf(val.toLowerCase()) === 0 ? 'flex' : 'none';
        });
      } else {
        sugBox.style.display = 'none';
      }
    });
  }

  // ─── Command suggestions click ───
  document.addEventListener('click', function(e) {
    var item = e.target.closest('.ai-cmd-item');
    if (!item) return;
    var cmd = item.getAttribute('data-cmd');
    if (chatInput) { 
      chatInput.innerHTML = ''; 
      chatInput.appendChild(document.createTextNode(cmd + ' ')); 
      processChatInput();
      var range = document.createRange();
      var sel = window.getSelection();
      range.selectNodeContents(chatInput);
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
    }
    var sugBox = document.getElementById('aiFloatingCmdSuggestions');
    if (sugBox) sugBox.style.display = 'none';
    if (chatInput) chatInput.focus();
  });

  // ─── Voice Input (PWA-style) ───
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SpeechRecognition && micBtn) {
    var recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    var isListening = false;
    recognition.onstart = function() {
      isListening = true;
      if (micBtn) micBtn.classList.add('listening');
      if (micIcon) micIcon.className = 'bi bi-mic-fill ai-pulse';
      if (chatInput) chatInput.dataset.placeholder = '🎙️ Listening... Speak now';
    };
    recognition.onresult = function(event) {
      var transcript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) { transcript += event.results[i][0].transcript; }
      if (chatInput) { chatInput.innerHTML = ''; chatInput.appendChild(document.createTextNode(transcript)); }
    };
    recognition.onerror = function(event) {
      isListening = false;
      if (micBtn) micBtn.classList.remove('listening');
      if (micIcon) micIcon.className = 'bi bi-mic-fill';
      if (chatInput) chatInput.dataset.placeholder = 'Voice error: ' + event.error + '. Try typing.';
    };
    recognition.onend = function() {
      isListening = false;
      if (micBtn) micBtn.classList.remove('listening');
      if (micIcon) micIcon.className = 'bi bi-mic-fill';
      if (chatInput) chatInput.dataset.placeholder = 'Ask about stock, orders or speak...';
    };
    micBtn.addEventListener('click', function() {
      if (isListening) { recognition.stop(); } else { try { recognition.start(); } catch(e) { console.error(e); } }
    });
  } else if (micBtn) {
    micBtn.addEventListener('click', function() { alert('Speech recognition is not supported in this browser. Please use Chrome, Edge, or Safari.'); });
  }

  // ─── Quick Chips (from any page) ───
  document.querySelectorAll('.ai-float-chip-item').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var prompt = btn.getAttribute('data-prompt');
      if (prompt) doSend(prompt);
    });
  });

})();
</script>
