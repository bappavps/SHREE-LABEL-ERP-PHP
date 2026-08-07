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
$promptSuggestionsPath = __DIR__ . '/../../data/prompt_suggestions.json';
$promptSuggestionsJson = file_exists($promptSuggestionsPath) ? file_get_contents($promptSuggestionsPath) : '{}';
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
  display: flex; flex-direction: column; max-width: 96%; min-width: 0;
  animation: floatMsgSlide 0.3s cubic-bezier(0.22, 1, 0.36, 1);
  margin-bottom: 14px;
}
#aiFloatingChatBody .msg-group.user { align-self: flex-end; }
#aiFloatingChatBody .msg-group.assistant { align-self: flex-start; }
#aiFloatingChatBody .msg-row { display: flex; gap: 8px; align-items: flex-end; min-width: 0; width: 100%; }
#aiFloatingChatBody .msg-group.user .msg-row { flex-direction: row-reverse; }
#aiFloatingChatBody .msg-avatar {
  width: 30px; height: 30px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: #fff; flex-shrink: 0; margin-bottom: 18px;
}
#aiFloatingChatBody .msg-group.assistant .msg-avatar { background: linear-gradient(135deg, #3b82f6, #8b5cf6); }
#aiFloatingChatBody .msg-group.user .msg-avatar { background: linear-gradient(135deg, #10b981, #059669); }
#aiFloatingChatBody .msg-content { display: flex; flex-direction: column; min-width: 0; max-width: 100%; }
#aiFloatingChatBody .msg-bubble {
  padding: 12px 16px; border-radius: 18px; font-size: 14px;
  line-height: 1.6; word-break: break-word; position: relative;
  min-width: 0; max-width: 100%; overflow-x: hidden;
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
#aiFloatingChatBody .table-responsive-wrapper {
  display: block;
  width: 100%;
  max-width: 100%;
  overflow-x: auto !important;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
  margin: 10px 0;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,0.12);
  background: rgba(15, 23, 42, 0.7);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
  box-sizing: border-box;
  touch-action: pan-x pan-y;
}
#aiFloatingChatBody .table-responsive-wrapper::-webkit-scrollbar { height: 5px; }
#aiFloatingChatBody .table-responsive-wrapper::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.04); }
#aiFloatingChatBody .table-responsive-wrapper::-webkit-scrollbar-thumb { background: rgba(59, 130, 246, 0.5); border-radius: 4px; }
#aiFloatingChatBody .msg-bubble table { width: max-content !important; min-width: 100%; table-layout: auto !important; border-collapse: collapse !important; margin: 0 !important; font-size: 12px !important; background: transparent !important; color: #e2e8f0 !important; }
#aiFloatingChatBody .msg-bubble table tr { background: transparent !important; color: inherit !important; }
#aiFloatingChatBody .msg-bubble th, #aiFloatingChatBody .msg-bubble td { padding: 8px 12px !important; border: 1px solid rgba(255,255,255,0.15) !important; text-align: left !important; color: inherit !important; background: transparent !important; white-space: nowrap !important; word-break: normal !important; }
#aiFloatingChatBody .msg-bubble th:first-child, #aiFloatingChatBody .msg-bubble td:first-child { width: 1% !important; min-width: 36px !important; text-align: center !important; padding: 8px 6px !important; }
#aiFloatingChatBody .msg-bubble th { background: linear-gradient(135deg, rgba(59,130,246,0.25), rgba(37,99,235,0.15)) !important; color: #93c5fd !important; font-weight: 700 !important; font-size: 11px !important; text-transform: uppercase !important; }
#aiFloatingChatBody .msg-bubble tr:nth-child(even), #aiFloatingChatBody .msg-bubble tr:nth-child(even) td { background: rgba(255,255,255,0.04) !important; }
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
  display: inline-flex; background: none; border: none;
  color: #94a3b8; font-size: 13px; cursor: pointer;
  padding: 3px 6px; border-radius: 6px;
  transition: all 0.2s; align-items: center; gap: 3px;
  opacity: 0.85; margin-left: 4px;
}
#aiFloatingChatBody .msg-group .btn-copy-msg { display: inline-flex; }
#aiFloatingChatBody .btn-copy-msg:hover { background: rgba(59,130,246,0.12); color: #3b82f6; opacity: 1; transform: scale(1.1); }
#aiFloatingChatBody .btn-copy-msg:active { transform: scale(1.15); }
#aiFloatingChatBody .btn-copy-msg.copied { color: #22c55e; }
/* Thinking indicator matching PWA typing-box */
#aiFloatingChatBody .ai-thinking-indicator {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 18px;
  border-bottom-left-radius: 6px;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  margin-left: 0; margin-top: 4px;
}
#aiFloatingChatBody .typing-dots { display: flex; gap: 4px; align-items: center; }
#aiFloatingChatBody .typing-dot {
  width: 7px; height: 7px;
  background: #3b82f6;
  border-radius: 50%;
  animation: typingBounce 1.4s infinite ease-in-out both;
}
#aiFloatingChatBody .typing-dot:nth-child(1) { animation-delay: -0.32s; }
#aiFloatingChatBody .typing-dot:nth-child(2) { animation-delay: -0.16s; }
#aiFloatingChatBody .typing-dot:nth-child(3) { animation-delay: 0s; }
@keyframes typingBounce {
  0%, 80%, 100% { transform: scale(0.4); opacity: 0.4; }
  40% { transform: scale(1); opacity: 1; }
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
/* Light Theme Table Overrides */
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble table { color: #334155 !important; }
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble th, 
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble td { border-color: rgba(0,0,0,0.1) !important; color: inherit !important; }
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble th { background: rgba(37,99,235,0.06) !important; color: #1e40af !important; }
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble tr:nth-child(even),
#aiFloatingPopupCard[data-theme="light"] #aiFloatingChatBody .msg-bubble tr:nth-child(even) td { background: rgba(0,0,0,0.03) !important; }
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
/* Floating Popup Menu */
.ai-popup-menu {
  position: absolute;
  bottom: calc(100% - 10px);
  left: 14px;
  right: 14px;
  background: rgba(15, 23, 42, 0.95);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.3);
  display: none;
  flex-direction: column;
  max-height: 250px;
  overflow-y: auto;
  z-index: 1000;
  padding: 6px 0;
  transform-origin: bottom center;
  animation: popupSlideUp 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes popupSlideUp {
  from { opacity: 0; transform: translateY(10px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.ai-popup-menu::-webkit-scrollbar { width: 4px; }
.ai-popup-menu::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }

.ai-popup-menu .popup-item {
  padding: 10px 16px;
  color: #e2e8f0;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: background 0.15s;
}
.ai-popup-menu .popup-item:hover {
  background: rgba(59, 130, 246, 0.2);
  color: #93c5fd;
}
.ai-popup-menu .popup-item i { color: #3b82f6; font-size: 14px; }

/* Light Theme */
#aiFloatingPopupCard[data-theme="light"] .ai-popup-menu {
  background: rgba(255, 255, 255, 0.95);
  border-color: rgba(0, 0, 0, 0.1);
  box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.1);
}
#aiFloatingPopupCard[data-theme="light"] .ai-popup-menu .popup-item { color: #334155; }
#aiFloatingPopupCard[data-theme="light"] .ai-popup-menu .popup-item:hover { background: rgba(37,99,235,0.1); color: #2563eb; }

/* AutoComplete Styles Floating */
.ai-float-autocomplete-dropdown {
    position: absolute;
    bottom: 100%;
    left: 0;
    width: 250px;
    max-height: 180px;
    overflow-y: auto;
    background: #2a2a35;
    border: 1px solid rgba(245,158,11,0.7);
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(245,158,11,0.18);
    z-index: 10000;
    display: none;
    margin-bottom: 5px;
    font-size: 13px;
    margin-left: 10px;
}
.ai-float-autocomplete-dropdown li.ai-autocomplete-item {
    padding: 6px 10px;
    cursor: pointer;
    border-bottom: 1px solid #3a3a4a;
    color: #e0e0e0;
    display: flex;
    justify-content: space-between;
}
.ai-float-autocomplete-dropdown li.ai-autocomplete-item:hover {
    background: rgba(245, 158, 11, 0.16);
}
.ai-float-autocomplete-dropdown li.ai-autocomplete-item:last-child {
    border-bottom: none;
}
.ai-float-autocomplete-dropdown .ai-autocomplete-name {
    font-weight: bold;
    color: #fbbf24;
}
.ai-float-autocomplete-dropdown .ai-autocomplete-size {
    font-size: 10px;
    color: #888;
}
/* 3-Level Color Coding (floating widget) */
/* Level 2 — Query suggestions (GREEN) */
#aiFloatingCmdSuggestionsPopup .popup-item:hover { background: rgba(16,185,129,0.18); color: #6ee7b7; }
#aiFloatingCmdSuggestionsPopup .popup-item i { color: #10b981; }
#aiFloatingPopupCard[data-theme="light"] #aiFloatingCmdSuggestionsPopup .popup-item:hover { background: rgba(16,185,129,0.12); color: #059669; }
</style>

<!-- Floating Trigger Button -->
<!-- Module base URL for the Level 3 entity autocomplete fetch (resolves to the real api.php) -->
<input type="hidden" id="aiBaseUrl" value="<?= $moduleBaseUrl ?>">
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
    <i class="bi bi-star-fill" style="color:#f59e0b;"></i> Quick Actions
    <span class="qa-badge" id="aiFloatQaBadge" style="display:none;background:#f59e0b;color:#000;font-size:11px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;">0</span>
    <i class="bi bi-chevron-down" style="margin-left:auto;"></i>
  </div>
  <div class="ai-float-chips-section" id="aiFloatChipsSection">
    <div id="aiFloatQuickActionsList" style="display:flex;flex-direction:column;gap:6px;padding:4px 0;"></div>
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
    <!-- Contextual Prompt Popup -->
    <div id="aiFloatingCmdSuggestionsPopup" class="ai-popup-menu" style="position:absolute;bottom:calc(100% + 4px);left:8px;right:8px;background:rgba(30,41,59,0.98);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(16,185,129,0.75);border-radius:12px;padding:4px;display:none;z-index:101;box-shadow:0 -4px 24px rgba(16,185,129,0.25);max-height:180px;overflow-y:auto;flex-direction:column;"></div>
    <!-- Command Suggestions Dropup -->
    <div class="ai-cmd-suggestions" id="aiFloatingCmdSuggestions" style="position:absolute;bottom:calc(100% + 4px);left:8px;right:8px;background:rgba(30,41,59,0.98);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(59,130,246,0.75);border-radius:12px;padding:4px;display:none;z-index:100;box-shadow:0 -4px 24px rgba(59,130,246,0.25);max-height:180px;overflow-y:auto">
      <div class="ai-cmd-item" data-cmd="/erp" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/erp</span>
        <span style="font-size:12px;color:#94a3b8">Executive 360° ERP Master Overview</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/job" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/job</span>
        <span style="font-size:12px;color:#94a3b8">Job Priority Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/plate" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/plate</span>
        <span style="font-size:12px;color:#94a3b8">Plate Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/planning" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/planning</span>
        <span style="font-size:12px;color:#94a3b8">Job Planning Board</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/paper" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/paper</span>
        <span style="font-size:12px;color:#94a3b8">Paper Stock Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/product" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/product</span>
        <span style="font-size:12px;color:#94a3b8">Product / Item lookup</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/client" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/client</span>
        <span style="font-size:12px;color:#94a3b8">Client / Party lookup</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/dispatch" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/dispatch</span>
        <span style="font-size:12px;color:#94a3b8">Dispatch Mode</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/order" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/order</span>
        <span style="font-size:12px;color:#94a3b8">Order lookup</span>
      </div>
      <div class="ai-cmd-item" data-cmd="/stock" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background 0.15s;color:#e2e8f0" onmouseover="this.style.background='rgba(59,130,246,0.15)'" onmouseout="this.style.background='transparent'">
        <span style="font-weight:700;color:#ef4444;font-size:13px;min-width:65px">/stock</span>
        <span style="font-size:12px;color:#94a3b8">Stock lookup</span>
      </div>
    </div>
    <button type="button" class="btn-input btn-mic" id="aiFloatingMicBtn" title="Speak to AI Agent" style="background:none;border:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;cursor:pointer;transition:all 0.2s ease;color:#64748b;margin-right:4px">
      <i class="bi bi-mic-fill" id="aiFloatingMicIcon"></i>
    </button>
    <ul id="aiFloatAutocompleteDropdown" class="ai-float-autocomplete-dropdown"></ul>
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
  var promptSuggestionsData = <?= $promptSuggestionsJson ?>;
  var cmdSuggestionsPopup = document.getElementById('aiFloatingCmdSuggestionsPopup');

  function renderFloatCmdSuggestions(cmd) {
    if (!promptSuggestionsData || !promptSuggestionsData[cmd]) {
      cmdSuggestionsPopup.style.display = 'none';
      return;
    }
    var suggestions = (promptSuggestionsData[cmd] || []).slice(0, 3);
    var html = '';
    for (var i = 0; i < suggestions.length; i++) {
      var text = suggestions[i];
      var display = text.replace(cmd, '<strong style="color:#3b82f6">' + cmd + '</strong>');
      html += '<div class="popup-item" onclick="_applyFloatSuggestion(\'' + text.replace(/'/g, "\\'") + '\')"><i class="bi bi-magic"></i> <span>' + display + '</span></div>';
    }
    cmdSuggestionsPopup.innerHTML = html;
    cmdSuggestionsPopup.style.display = 'flex';
  }

  window._applyFloatSuggestion = function(text) {
    if (chatInput) {
      chatInput.innerHTML = '';
      chatInput.appendChild(document.createTextNode(text + ' '));
      cmdSuggestionsPopup.style.display = 'none';
      processChatInput();
            chatInput.focus();
      if (typeof window.getSelection !== 'undefined' && typeof document.createRange !== 'undefined') {
        var range = document.createRange();
        range.selectNodeContents(chatInput);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
    }
  };
  
  document.addEventListener('click', function(e) {
      if (!e.target.closest('#aiFloatingCmdSuggestionsPopup') && !e.target.closest('#aiFloatingChatInput')) {
          if (cmdSuggestionsPopup) cmdSuggestionsPopup.style.display = 'none';
      }
  });
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

  // ─── Enhance code blocks & wrap tables for mobile responsiveness ───
  function enhanceCodeBlocks(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    d.querySelectorAll('table').forEach(function(table) {
      if (!table.parentNode.classList.contains('table-responsive-wrapper')) {
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive-wrapper';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
      }
    });
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

  window._floatRegenMsg = function(btn) {
    var msgGroup = btn.closest('.msg-group');
    var promptText = '';
    if (msgGroup.classList.contains('user')) {
      var bubble = msgGroup.querySelector('.msg-bubble');
      promptText = ((bubble && (bubble.innerText || bubble.textContent)) || '').trim();
    } else {
      var prev = msgGroup.previousElementSibling;
      while (prev) {
        if (prev.classList.contains('msg-group') && prev.classList.contains('user')) {
          var bubble = prev.querySelector('.msg-bubble');
          promptText = ((bubble && (bubble.innerText || bubble.textContent)) || '').trim();
          break;
        }
        prev = prev.previousElementSibling;
      }
    }
    if (promptText && typeof doSend === 'function') {
      doSend(promptText);
    }
  };

  window._floatEditMsg = function(btn) {
    var msgContent = btn.closest('.msg-content');
    var bubble = msgContent.querySelector('.msg-bubble');
    if (!bubble || msgContent.querySelector('.msg-edit-box')) return;

    var originalText = ((bubble.innerText || bubble.textContent) || '').trim();
    var originalHtml = bubble.innerHTML;

    bubble.innerHTML = '<div class="msg-edit-box" style="display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px;">'
      + '<textarea class="msg-edit-input" style="width:100%;min-height:55px;background:rgba(15,23,42,0.85);border:1px solid #3b82f6;color:#fff;border-radius:8px;padding:6px 8px;font-size:13px;resize:vertical;outline:none;font-family:inherit;">' + esc(originalText) + '</textarea>'
      + '<div style="display:flex;gap:6px;justify-content:flex-end;">'
      + '<button type="button" class="btn-cancel-edit" style="background:rgba(148,163,184,0.2);border:none;color:#cbd5e1;padding:3px 8px;border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>'
      + '<button type="button" class="btn-save-edit" style="background:#3b82f6;border:none;color:#fff;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Save & Regenerate</button>'
      + '</div></div>';

    var textarea = bubble.querySelector('.msg-edit-input');
    if (textarea) {
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    }

    var cancelBtn = bubble.querySelector('.btn-cancel-edit');
    if (cancelBtn) {
      cancelBtn.onclick = function() { bubble.innerHTML = originalHtml; };
    }

    var saveBtn = bubble.querySelector('.btn-save-edit');
    if (saveBtn) {
      saveBtn.onclick = function() {
        var newText = textarea.value.trim();
        if (newText && typeof doSend === 'function') {
          bubble.innerHTML = esc(newText);
          doSend(newText);
        } else {
          bubble.innerHTML = originalHtml;
        }
      };
    }
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

    var isFav = (typeof isQuickActionSaved === 'function') ? isQuickActionSaved(text) : false;
    var favBtnHtml = sender === 'user' ? '<button class="btn-copy-msg btn-fav-msg ' + (isFav ? 'active' : '') + '" onclick="_floatToggleFavMsg(this)" title="' + (isFav ? 'Remove from Quick Actions' : 'Pin to Quick Actions') + '"><i class="bi ' + (isFav ? 'bi-star-fill' : 'bi-star') + '" style="' + (isFav ? 'color:#f59e0b;' : '') + '"></i></button>' : '';
    var editBtnHtml = sender === 'user' ? '<button class="btn-copy-msg btn-edit-msg" onclick="_floatEditMsg(this)" title="Edit Prompt"><i class="bi bi-pencil-square"></i></button>' : '';

    var cmdClass = commandType ? ' ai-cmd-' + commandType : '';
    var html = '<div class="msg-group ' + sender + cmdClass + '">'
      + '<div class="msg-row">'
      + '<div class="msg-avatar">' + (sender === 'user' ? '<i class="bi bi-person-fill"></i>' : '<i class="bi bi-robot"></i>') + '</div>'
      + '<div class="msg-content">'
      + '<div class="msg-bubble">' + bubbleHtml + '</div>'
      + '<div class="msg-footer">'
      + '<span class="msg-meta">' + label + ' \u00B7 ' + timeStr + '</span>'
      + favBtnHtml
      + '<button class="btn-copy-msg" onclick="_floatCopyMsg(this)" title="Copy"><i class="bi bi-clipboard"></i></button>'
      + editBtnHtml
      + '<button class="btn-copy-msg btn-regen-msg" onclick="_floatRegenMsg(this)" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>'
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

    // ─── In-page full-screen report viewer (PDF print view) ───
    // PDF export links return an HTML print view. Opening it in a new tab is
    // popup-blocked on mobile (async fetch loses user gesture), so we render
    // it in a full-screen modal right here — "view report on screen".
    function openWidgetReportViewer(url) {
      var ov = document.getElementById('widgetReportViewer');
      if (ov) ov.parentNode && ov.parentNode.removeChild(ov);
      ov = document.createElement('div');
      ov.id = 'widgetReportViewer';
      ov.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(2,6,23,.9);display:flex;flex-direction:column;';
      var bar = document.createElement('div');
      bar.style.cssText = 'display:flex;align-items:center;gap:10px;justify-content:space-between;padding:10px 14px;background:#0f172a;color:#fff;flex:0 0 auto;';
      var title = document.createElement('span');
      title.textContent = '📄 Report — Print / Save as PDF';
      title.style.cssText = 'font-size:14px;font-weight:600;';
      var btns = document.createElement('div');
      btns.style.cssText = 'display:flex;gap:8px;';
      var printBtn = document.createElement('button');
      printBtn.textContent = '🖨 Print';
      printBtn.style.cssText = 'background:#2563eb;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:14px;cursor:pointer;';
      var closeBtn = document.createElement('button');
      closeBtn.textContent = '✕ Close';
      closeBtn.style.cssText = 'background:#ef4444;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:14px;cursor:pointer;';
      btns.appendChild(printBtn);
      btns.appendChild(closeBtn);
      bar.appendChild(title);
      bar.appendChild(btns);
      var wrap = document.createElement('div');
      wrap.style.cssText = 'flex:1 1 auto;min-height:0;';
      var frame = document.createElement('iframe');
      frame.style.cssText = 'width:100%;height:100%;border:none;background:#fff;';
      frame.src = url;
      wrap.appendChild(frame);
      ov.appendChild(bar);
      ov.appendChild(wrap);
      document.body.appendChild(ov);
      printBtn.onclick = function() { try { frame.contentWindow.print(); } catch (e) {} };
      closeBtn.onclick = function() { ov.parentNode && ov.parentNode.removeChild(ov); };
    }

    // ─── File/Export links must NEVER navigate the ERP chat away ───
    // Download PDF / Excel / CSV directly via fetch → blob. PDF (print view)
    // responses are shown in-app. Fallback opens a separate browser tab, so the
    // current page (PWA / dashboard) is untouched.
    chatBody.addEventListener('click', function(e) {
      var a = e.target.closest('a');
      if (!a || !a.href) return;
      var h = a.href;
      var isFileLink = /export\.php|\.pdf($|[?#])|\.csv($|[?#])|\.xlsx?($|[?#])|format=(pdf|csv|excel)/i.test(h);
      if (!isFileLink) return;

      e.preventDefault();
      e.stopPropagation();

      var isPdfLink = /format=pdf/i.test(h);

      fetch(h, { credentials: 'include' })
        .then(function(r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          var ct = (r.headers.get('Content-Type') || '').toLowerCase();
          if (ct.indexOf('text/html') !== -1) {
            if (isPdfLink) {
              openWidgetReportViewer(h);
              return null;
            }
            throw new Error('html-redirect');
          }
          return r.blob();
        })
        .then(function(blob) {
          if (!blob) return;
          var ext = /format=pdf/i.test(h) ? 'pdf'
                  : (/format=csv|\.csv/i.test(h) ? 'csv'
                  : (/\.xlsx/i.test(h) ? 'xlsx' : 'bin'));
          var url = URL.createObjectURL(blob);
          var dl = document.createElement('a');
          dl.href = url;
          dl.download = 'report-' + new Date().toISOString().slice(0, 10) + '.' + ext;
          document.body.appendChild(dl);
          dl.click();
          dl.remove();
          setTimeout(function() { URL.revokeObjectURL(url); }, 4000);
        })
        .catch(function() {
          window.open(h, '_blank', 'noopener,noreferrer');
        });
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
    addMsg('<div class="ai-thinking-indicator" id="aiFloatThinkingIndicator"><div class="typing-dots"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div> <em class="ai-thinking-text" style="font-size:12px;color:#94a3b8;font-weight:600;font-style:normal;">Thinking...</em></div>', 'assistant');
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

    function applyChipPrompt(promptText, autoSend) {
    var currentText = chatInput.innerText || chatInput.textContent || '';
    var finalPrompt = promptText;
    
    // Command prefixes
    var floatCmdNames = ['/erp', '/dispatch', '/job', '/paperstock', '/plate', '/cal', '/clear', '/packing', '/jobcard', '/planning'];
    
    // If prompt doesn't start with /, but existing text does, preserve it
    if (!promptText.startsWith('/') && currentText.startsWith('/')) {
      var parts = currentText.split(' ');
      var activeCmd = parts[0];
      if (floatCmdNames.includes(activeCmd)) {
        finalPrompt = activeCmd + ' ' + promptText;
      }
    }
    
    if (chatInput) {
        chatInput.innerHTML = '';
        chatInput.appendChild(document.createTextNode(finalPrompt + ' '));
        processChatInput();
        
        if (autoSend && finalPrompt.trim().length > 0) {
            setTimeout(function() { if(typeof doSend === 'function') doSend(); }, 10);
        } else {
            chatInput.focus();
            if (typeof window.getSelection !== 'undefined' && typeof document.createRange !== 'undefined') {
              var range = document.createRange();
              range.selectNodeContents(chatInput);
              range.collapse(false);
              var sel = window.getSelection();
              sel.removeAllRanges();
              sel.addRange(range);
            }
        }
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
      if (e.key === 'Enter' && !e.shiftKey) {
        var ddl = document.getElementById('aiFloatAutocompleteDropdown');
        if (ddl && ddl.style.display === 'block') return; // let the autocomplete handler pick the item
        e.preventDefault(); doSend();
      }
    });
    chatInput.addEventListener('input', function() {
      var val = (chatInput.innerText || chatInput.textContent || '').replace(/\n/g, '');
      var sugBox = document.getElementById('aiFloatingCmdSuggestions');
      if (!sugBox) return;
      // Contextual prompt popup check (Level 2 — after command + SPACE, not inside quotes)
    var inQuote = /^\/(job|plate|paper|product)\s+"/i.test(val);
    var cmdMatch = false;
    if (val.startsWith('/') && !inQuote) {
       var parts = val.split(' ');
       var activeCmd = parts[0];
       if (promptSuggestionsData[activeCmd] && val.indexOf(' ') !== -1) {
           renderFloatCmdSuggestions(activeCmd);
           cmdMatch = true;
       }
    }
    if (!cmdMatch) {
       if (cmdSuggestionsPopup) cmdSuggestionsPopup.style.display = 'none';
    }
    
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
    if (chatInput)       chatInput.focus();
      if (typeof window.getSelection !== 'undefined' && typeof document.createRange !== 'undefined') {
        var range = document.createRange();
        range.selectNodeContents(chatInput);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      }
  });

  // ─── Voice Input (PWA-style) ───
  var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (SpeechRecognition && micBtn) {
    var recognition = new SpeechRecognition();
    recognition.continuous = false;
    recognition.interimResults = true;
    var isListening = false;
    var initialVoiceText = '';
    
    recognition.onstart = function() {
      isListening = true;
      if (chatInput) {
          initialVoiceText = chatInput.innerText.trim();
          if (initialVoiceText && !initialVoiceText.endsWith(' ')) initialVoiceText += ' ';
      }
      if (micBtn) micBtn.classList.add('listening');
      if (micIcon) micIcon.className = 'bi bi-mic-fill ai-pulse';
      if (chatInput) chatInput.dataset.placeholder = '🎙️ Listening... Speak now';
    };
    recognition.onresult = function(event) {
      var transcript = '';
      for (var i = event.resultIndex; i < event.results.length; i++) { transcript += event.results[i][0].transcript; }
      if (chatInput) { chatInput.innerHTML = ''; chatInput.appendChild(document.createTextNode(initialVoiceText + transcript)); }
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
    btn.addEventListener('click', function() { var p = btn.getAttribute('data-prompt'); if (p) { applyChipPrompt(p, false); } });
  });

})();

// ==================== AUTOCOMPLETE LOGIC FLOATING ====================
const acFloatDropdown = document.getElementById('aiFloatAutocompleteDropdown');
let acFloatTimeout = null;
const floatInput = document.getElementById('aiFloatingChatInput');

// Caret offset within the full input text (ignores the highlight <span>s that
// processChatInput injects, since a cloned range up to the caret is used).
function floatCaretOffset() {
    const sel = window.getSelection();
    if (!sel.rangeCount) return (floatInput.innerText || floatInput.textContent || '').length;
    const range = sel.getRangeAt(0);
    const clone = range.cloneRange();
    clone.selectNodeContents(floatInput);
    clone.setEnd(range.startContainer, range.startOffset);
    return clone.toString().replace(/\n/g, '').length;
}

function applyFloatAutocomplete(name) {
    // Insert the chosen entity name right after the first opening quote, auto-close
    // the quote + add a trailing space, and place the caret right after the closing
    // quote so the user can keep typing. Operates on the FULL innerText so it works
    // even though the input is re-rendered with highlight <span>s.
    const current = (floatInput.innerText || floatInput.textContent || '').replace(/\n/g, '');
    const caret = floatCaretOffset();
    const lastQuote = current.lastIndexOf('"');

    if (lastQuote !== -1) {
        const before = current.substring(0, lastQuote + 1); // e.g. '/plate "'
        const after = current.substring(caret);             // text typed after the caret (kept)
        const newText = before + name + '" ' + after;       // '/plate "Name" <after>'
        floatInput.innerHTML = '';
        floatInput.appendChild(document.createTextNode(newText));

        const pos = before.length + name.length + 2;        // caret after the closing quote + space
        if (floatInput.firstChild) {
            const newRange = document.createRange();
            newRange.setStart(floatInput.firstChild, Math.min(pos, floatInput.firstChild.textContent.length));
            newRange.collapse(true);
            const s = window.getSelection();
            s.removeAllRanges();
            s.addRange(newRange);
        }
        if (floatInput) floatInput.focus();
    }

    acFloatDropdown.style.display = 'none';
    acFloatDropdown.innerHTML = '';
}

floatInput.addEventListener('input', function(e) {
    // Reconstruct the FULL text before the caret. processChatInput re-renders the
    // input with <span class="cmd-highlight">/plate</span> highlight spans, so the
    // selection's startContainer may only be a fragment (e.g. ' "b'). A cloned range
    // up to the caret gives the true text regardless of span splitting.
    let fullBefore;
    const sel = window.getSelection();
    if (sel && sel.rangeCount) {
        const range = sel.getRangeAt(0);
        const clone = range.cloneRange();
        clone.selectNodeContents(floatInput);
        clone.setEnd(range.startContainer, range.startOffset);
        fullBefore = clone.toString();
    } else {
        fullBefore = (floatInput.innerText || floatInput.textContent || '');
    }
    const normalized = fullBefore.replace(/\n/g, '');

    // Unclosed-quote detection: an ODD number of " means the last quote is an opening
    // quote. Entity autocomplete triggers for /job|/plate|/paper|/product even when the
    // user types extra words between the command and the quote
    // (e.g. `/job how many label if "blue 500`).
    const quoteCount = (normalized.match(/"/g) || []).length;
    const lastQuote = normalized.lastIndexOf('"');
    if (lastQuote !== -1 && (quoteCount % 2) === 1) {
        const prefix = normalized.substring(0, lastQuote).trim();
        const isEntityCmd = /^\/(job|plate|paper|product)\b/i.test(prefix);
        const textSinceQuote = normalized.substring(lastQuote + 1);
        if (isEntityCmd) {
            // Hide Level 1 (commands) and Level 2 (query suggestions) popups
            const fCmdBox = document.getElementById('aiFloatingCmdSuggestions');
            if (fCmdBox) fCmdBox.style.display = 'none';
            const fLevel2 = document.getElementById('aiFloatingCmdSuggestionsPopup');
            if (fLevel2) fLevel2.style.display = 'none';
            clearTimeout(acFloatTimeout);
            acFloatTimeout = setTimeout(() => {
                const baseUrl = document.getElementById('aiBaseUrl') ? document.getElementById('aiBaseUrl').value : '';
                fetch((baseUrl ? baseUrl + '/' : '') + 'api.php?action=autocomplete&prompt=' + encodeURIComponent(textSinceQuote))
                    .then(res => res.json())
                    .then(data => {
                        if (data.ok && data.suggestions.length > 0) {
                            acFloatDropdown.innerHTML = '';
                            // Show all matching jobs (empty or typed) so the list scrolls
                            data.suggestions.forEach(item => {
                                const li = document.createElement('li');
                                li.className = 'ai-autocomplete-item';
                                li.setAttribute('data-name', item.name);
                                li.innerHTML = '<span class="ai-autocomplete-name">' + item.name + '</span><span class="ai-autocomplete-size">' + (item.size || '') + '</span>';
                                li.onmousedown = (e) => {
                                    e.preventDefault();
                                    applyFloatAutocomplete(item.name);
                                };
                                acFloatDropdown.appendChild(li);
                            });
                            acFloatDropdown.style.display = 'block';
                        } else {
                            acFloatDropdown.style.display = 'none';
                        }
                    })
                    .catch(err => {
                        acFloatDropdown.style.display = 'none';
                    });
            }, 200);
            return;
        }
    }
    
    acFloatDropdown.style.display = 'none';
});

// Keyboard navigation (↑/↓/Enter) + ESC for the floating autocomplete dropdown
floatInput.addEventListener('keydown', function(e) {
    if (acFloatDropdown.style.display !== 'block') return;
    const items = acFloatDropdown.querySelectorAll('li.ai-autocomplete-item');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (window.acFloatFocus === undefined || window.acFloatFocus < 0) window.acFloatFocus = -1;
        window.acFloatFocus++;
        if (window.acFloatFocus >= items.length) window.acFloatFocus = 0;
        floatSetActive(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (window.acFloatFocus === undefined || window.acFloatFocus >= items.length) window.acFloatFocus = 0;
        window.acFloatFocus--;
        if (window.acFloatFocus < 0) window.acFloatFocus = items.length - 1;
        floatSetActive(items);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (window.acFloatFocus !== undefined && window.acFloatFocus > -1 && items[window.acFloatFocus]) {
            applyFloatAutocomplete(items[window.acFloatFocus].getAttribute('data-name'));
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        acFloatDropdown.style.display = 'none';
        acFloatDropdown.innerHTML = '';
        window.acFloatFocus = -1;
    }
});

function floatSetActive(items) {
    for (let i = 0; i < items.length; i++) {
        items[i].classList.remove('active');
        items[i].style.background = '';
    }
    if (window.acFloatFocus > -1 && items[window.acFloatFocus]) {
        items[window.acFloatFocus].classList.add('active');
        items[window.acFloatFocus].style.background = 'rgba(59,130,246,0.15)';
    }
}

// ─── FLOATING WIDGET FAVORITE QUICK ACTIONS ENGINE ───
if (typeof STORAGE_KEY_QA === 'undefined') {
  var STORAGE_KEY_QA = 'erp_ai_quick_actions';
}

function getQuickActions() {
  try {
    var data = localStorage.getItem(STORAGE_KEY_QA);
    return data ? JSON.parse(data) : [];
  } catch (e) {
    return [];
  }
}

function isQuickActionSaved(prompt) {
  var list = getQuickActions();
  var clean = prompt.trim().toLowerCase();
  return list.some(function(item) { return (typeof item === 'string' ? item : item.prompt).trim().toLowerCase() === clean; });
}

function saveQuickAction(prompt) {
  var list = getQuickActions();
  var clean = prompt.trim();
  if (!clean) return;
  if (!isQuickActionSaved(clean)) {
    list.push({ id: Date.now(), prompt: clean });
    localStorage.setItem(STORAGE_KEY_QA, JSON.stringify(list));
  }
  renderFloatingQuickActions();
  updateFloatingUserMsgStars();
}

function removeQuickAction(prompt) {
  var list = getQuickActions();
  var clean = prompt.trim().toLowerCase();
  list = list.filter(function(item) { return (typeof item === 'string' ? item : item.prompt).trim().toLowerCase() !== clean; });
  localStorage.setItem(STORAGE_KEY_QA, JSON.stringify(list));
  renderFloatingQuickActions();
  updateFloatingUserMsgStars();
}

window._floatToggleFavMsg = function(btn) {
  var msgContent = btn.closest('.msg-content');
  var bubble = msgContent.querySelector('.msg-bubble');
  var prompt = ((bubble && (bubble.innerText || bubble.textContent)) || '').trim();
  if (!prompt) return;

  if (isQuickActionSaved(prompt)) {
    removeQuickAction(prompt);
    btn.classList.remove('active');
    btn.innerHTML = '<i class="bi bi-star"></i>';
    btn.title = 'Pin to Quick Actions';
    showFloatingToast('Removed from Quick Actions');
  } else {
    saveQuickAction(prompt);
    btn.classList.add('active');
    btn.innerHTML = '<i class="bi bi-star-fill" style="color:#f59e0b;"></i>';
    btn.title = 'Remove from Quick Actions';
    showFloatingToast('Added to Quick Actions ⭐');
  }
  if (navigator.vibrate) navigator.vibrate(15);
};

window.deleteFloatingQuickAction = function(e, prompt) {
  e.stopPropagation();
  removeQuickAction(prompt);
  showFloatingToast('Deleted from Quick Actions');
  if (navigator.vibrate) navigator.vibrate(15);
};

window.runFloatingQuickAction = function(prompt) {
  if (typeof doSend === 'function') {
    doSend(prompt);
  }
  var sec = document.getElementById('aiFloatChipsSection');
  if (sec) sec.classList.remove('visible');
  var tog = document.getElementById('aiFloatChipsToggle');
  if (tog) tog.classList.remove('active');
};

function renderFloatingQuickActions() {
  var container = document.getElementById('aiFloatQuickActionsList');
  if (!container) return;

  var list = getQuickActions();
  var badge = document.getElementById('aiFloatQaBadge');
  if (badge) {
    badge.textContent = list.length;
    badge.style.display = list.length > 0 ? 'inline-block' : 'none';
  }

  if (list.length === 0) {
    container.innerHTML = '<div class="empty-qa-box" style="padding:14px;text-align:center;background:rgba(15,23,42,0.5);border:1px dashed rgba(255,255,255,0.12);border-radius:10px;color:#94a3b8;font-size:12px;">'
      + '<i class="bi bi-star-fill" style="font-size:18px;color:#f59e0b;display:block;margin-bottom:4px;"></i>'
      + '<strong>No Favorite Quick Actions Pinned</strong>'
      + '<p style="margin:4px 0 0 0;font-size:11px;color:#64748b;">Click the <i class="bi bi-star"></i> star icon on any user prompt in chat to pin it here for 1-click execution!</p>'
      + '</div>';
    return;
  }

  var html = '';
  for (var i = 0; i < list.length; i++) {
    var pText = typeof list[i] === 'string' ? list[i] : list[i].prompt;
    var escP = esc(pText);
    html += '<div class="qa-fav-item" style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#e2e8f0;font-size:12px;cursor:pointer;margin-bottom:4px;" onclick="runFloatingQuickAction(\'' + escP.replace(/'/g, "\\'") + '\')">'
      + '<div style="display:flex;align-items:center;gap:8px;flex:1;overflow:hidden;">'
      + '<i class="bi bi-star-fill" style="color:#f59e0b;font-size:13px;flex-shrink:0;"></i>'
      + '<span style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escP + '</span>'
      + '</div>'
      + '<button type="button" style="background:none;border:none;color:#ef4444;opacity:0.8;padding:2px 6px;cursor:pointer;" onclick="deleteFloatingQuickAction(event, \'' + escP.replace(/'/g, "\\'") + '\')" title="Delete">'
      + '<i class="bi bi-trash3-fill"></i>'
      + '</button>'
      + '</div>';
  }
  container.innerHTML = html;
}

function updateFloatingUserMsgStars() {
  var chatBodyEl = document.getElementById('aiFloatingChatBody');
  if (!chatBodyEl) return;
  var userGroups = chatBodyEl.querySelectorAll('.msg-group.user');
  for (var i = 0; i < userGroups.length; i++) {
    var bubble = userGroups[i].querySelector('.msg-bubble');
    var btn = userGroups[i].querySelector('.btn-fav-msg');
    if (bubble && btn) {
      var prompt = ((bubble.innerText || bubble.textContent) || '').trim();
      var isFav = isQuickActionSaved(prompt);
      btn.classList.toggle('active', isFav);
      btn.title = isFav ? 'Remove from Quick Actions' : 'Pin to Quick Actions';
      btn.innerHTML = '<i class="bi ' + (isFav ? 'bi-star-fill' : 'bi-star') + '" style="' + (isFav ? 'color:#f59e0b;' : '') + '"></i>';
    }
  }
}

function showFloatingToast(msg) {
  var toast = document.getElementById('aiFloatingToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'aiFloatingToast';
    toast.style.cssText = 'position:absolute;bottom:70px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,0.95);color:#fff;border:1px solid #3b82f6;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,0.4);backdrop-filter:blur(8px);transition:all 0.3s ease;opacity:0;pointer-events:none;';
    var wrap = document.getElementById('aiFloatingChatWidget');
    if (wrap) wrap.appendChild(toast);
    else document.body.appendChild(toast);
  }
  toast.innerHTML = msg;
  toast.style.opacity = '1';
  toast.style.transform = 'translateX(-50%) translateY(0)';
  if (window._floatToastTimeout) clearTimeout(window._floatToastTimeout);
  window._floatToastTimeout = setTimeout(function() {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(-50%) translateY(10px)';
  }, 2200);
}

// Initial Render for Floating Widget
renderFloatingQuickActions();
// ============================================================
</script>
