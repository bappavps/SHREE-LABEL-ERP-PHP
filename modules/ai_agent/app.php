<?php
// ============================================================
// Standalone Mobile PWA AI Agent Chat App — Shree Label ERP
// 100% Independent Mobile Web App (No ERP Login/Menu Needed)
// Advanced Mobile UI v2.0
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$config = getAiAgentConfig();
$quickChips = getAiAgentQuickChips();
$baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

// Group chips by category
$chipCategories = [
    'production' => ['icon' => 'bi-gear-wide-connected', 'color' => '#f59e0b', 'label' => 'Production', 'items' => []],
    'inventory'  => ['icon' => 'bi-box-seam', 'color' => '#10b981', 'label' => 'Inventory', 'items' => []],
    'dispatch'   => ['icon' => 'bi-truck', 'color' => '#3b82f6', 'label' => 'Dispatch & Orders', 'items' => []],
    'finance'    => ['icon' => 'bi-currency-rupee', 'color' => '#8b5cf6', 'label' => 'Finance', 'items' => []],
    'tools'      => ['icon' => 'bi-tools', 'color' => '#ec4899', 'label' => 'Tools & Help', 'items' => []],
];
$chipMap = [
    'label_calculator'      => 'tools',
    'roll_status'           => 'production',
    'order_status'          => 'dispatch',
    'customer_search'       => 'dispatch',
    'production_summary'    => 'production',
    'today_dispatch'        => 'dispatch',
    'pending_jobs'          => 'production',
    'machine_running'       => 'production',
    'operator_performance'  => 'production',
    'inventory'             => 'inventory',
    'raw_material'          => 'inventory',
    'paper_stock'           => 'inventory',
    'costing'               => 'finance',
    'barcode_search'        => 'tools',
    'invoice'               => 'finance',
    'purchase_order'        => 'inventory',
    'client_balance'        => 'finance',
    'job_planning'          => 'production',
    'reports'               => 'tools',
    'ai_help'               => 'tools',
    'erp_training'          => 'tools',
];

foreach ($quickChips as $c) {
    $key = $c['key'];
    $cat = isset($chipMap[$key]) ? $chipMap[$key] : 'tools';
    $chipCategories[$cat]['items'][] = $c;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>ERP-BOT — Mobile PWA AI Copilot</title>

  <link rel="manifest" href="<?= $baseUrl ?>/modules/ai_agent/manifest.json">
  <meta name="theme-color" content="#0a0f1e" id="themeColorMeta">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="ERP-BOT">
  <link rel="apple-touch-icon" href="https://cdn-icons-png.flaticon.com/512/4712/4712035.png">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

  <style>
    :root {
      --bg-deep: #060a14;
      --bg-main: #0a0f1e;
      --bg-card: rgba(15, 23, 42, 0.92);
      --bg-glass: rgba(255,255,255,0.04);
      --accent: #3b82f6;
      --accent-glow: rgba(59, 130, 246, 0.35);
      --accent-soft: rgba(59, 130, 246, 0.12);
      --user-grad: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
      --ai-bg: rgba(255, 255, 255, 0.05);
      --text-primary: #f1f5f9;
      --text-secondary: #94a3b8;
      --text-dim: #64748b;
      --border: rgba(255,255,255,0.08);
      --border-light: rgba(255,255,255,0.12);
      --safe-top: env(safe-area-inset-top, 0px);
      --safe-bottom: env(safe-area-inset-bottom, 0px);
      --safe-left: env(safe-area-inset-left, 0px);
      --safe-right: env(safe-area-inset-right, 0px);
      --header-h: 60px;
      --input-h: 72px;
    }

    [data-theme="light"] {
      --bg-deep: #f0f2f5;
      --bg-main: #ffffff;
      --bg-card: rgba(255, 255, 255, 0.95);
      --bg-glass: rgba(0,0,0,0.04);
      --accent: #2563eb;
      --accent-glow: rgba(37, 99, 235, 0.2);
      --accent-soft: rgba(37, 99, 235, 0.08);
      --user-grad: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
      --ai-bg: rgba(0, 0, 0, 0.04);
      --text-primary: #0f172a;
      --text-secondary: #475569;
      --text-dim: #94a3b8;
      --border: rgba(0,0,0,0.08);
      --border-light: rgba(0,0,0,0.12);
    }

    [data-theme="light"] body::before {
      background: radial-gradient(ellipse at 30% 20%, rgba(37,99,235,0.04) 0%, transparent 50%),
                  radial-gradient(ellipse at 70% 80%, rgba(139,92,246,0.03) 0%, transparent 50%);
    }

    [data-theme="light"] .app-header {
      background: rgba(255, 255, 255, 0.92);
    }
    [data-theme="light"] .app-title h2 { color: #0f172a; }
    [data-theme="light"] .lang-toolbar { background: rgba(255, 255, 255, 0.85); }
    [data-theme="light"] .chips-section { background: rgba(255, 255, 255, 0.7); }
    [data-theme="light"] .chips-toggle { background: rgba(0,0,0,0.02); }
    [data-theme="light"] .app-footer { background: rgba(255, 255, 255, 0.95); }
    [data-theme="light"] .scroll-fab { background: rgba(255,255,255,0.95); box-shadow: 0 4px 20px rgba(0,0,0,0.12); }

    [data-theme="light"] .btn-header:active { background: rgba(0,0,0,0.06); }
    [data-theme="light"] .chip-item::before { background: linear-gradient(135deg, rgba(0,0,0,0.03), transparent); }
    [data-theme="light"] .welcome-hero h1 {
      background: linear-gradient(135deg, #0f172a 0%, #2563eb 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    [data-theme="light"] .msg-group.user .msg-bubble {
      box-shadow: 0 4px 16px rgba(37,99,235,0.18);
    }
    [data-theme="light"] .msg-group.assistant .msg-bubble {
      background: rgba(0,0,0,0.04);
      color: #1e293b;
      border-color: rgba(0,0,0,0.08);
    }
    [data-theme="light"] .msg-bubble strong { color: #2563eb; }
    [data-theme="light"] .msg-group.user .msg-bubble strong { color: #dbeafe; }
    [data-theme="light"] .msg-bubble code { background: rgba(0,0,0,0.08); color: #db2777; }
    [data-theme="light"] .msg-bubble pre { background: rgba(0,0,0,0.06); }
    [data-theme="light"] .msg-bubble pre code { color: #1e293b; }
    [data-theme="light"] .msg-bubble a { color: #2563eb; border-color: rgba(37,99,235,0.3); }
    [data-theme="light"] .msg-bubble th { background: rgba(37,99,235,0.08); color: #1d4ed8; }
    [data-theme="light"] .msg-bubble td, [data-theme="light"] .msg-bubble th { border-color: rgba(0,0,0,0.08); }

    [data-theme="light"] .typing-box { background: rgba(0,0,0,0.04); border-color: rgba(0,0,0,0.08); }
    [data-theme="light"] .input-container { background: rgba(0,0,0,0.04); }
    [data-theme="light"] .chat-input { color: #0f172a; }
    [data-theme="light"] .chat-input::placeholder { color: #94a3b8; }
    [data-theme="light"] .input-hint { color: #94a3b8; }

    [data-theme="light"] .chat-container::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); }
    [data-theme="light"] .quick-action-card { background: rgba(0,0,0,0.03); }
    [data-theme="light"] .lang-hint .hint-pill { background: rgba(0,0,0,0.04); border-color: rgba(0,0,0,0.08); color: #475569; }
    [data-theme="light"] .msg-meta { color: #94a3b8; }
    [data-theme="light"] .btn-copy-msg { color: #94a3b8; }
    [data-theme="light"] .status-dot { box-shadow: 0 0 8px #22c55e; }

    /* Theme Toggle Button */
    .btn-theme {
      position: relative;
      overflow: hidden;
    }
    .btn-theme .icon-sun,
    .btn-theme .icon-moon {
      transition: all 0.3s ease;
    }
    [data-theme="light"] .btn-theme .icon-sun { display: none; }
    [data-theme="dark"] .btn-theme .icon-moon { display: none; }
    :root:not([data-theme="light"]):not([data-theme="dark"]) .btn-theme .icon-moon { display: none; }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', 'Hind Siliguri', sans-serif; -webkit-tap-highlight-color: transparent; -webkit-touch-callout: none; }

    html { height: 100%; -webkit-text-size-adjust: 100%; }

    body {
      background: var(--bg-deep);
      color: var(--text-primary);
      height: 100vh;
      height: -webkit-fill-available;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      position: relative;
    }

    body::before {
      content: '';
      position: fixed;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(ellipse at 30% 20%, rgba(59,130,246,0.06) 0%, transparent 50%),
                  radial-gradient(ellipse at 70% 80%, rgba(139,92,246,0.04) 0%, transparent 50%);
      animation: bgFloat 20s ease-in-out infinite alternate;
      pointer-events: none;
      z-index: 0;
    }
    @keyframes bgFloat {
      0% { transform: translate(0, 0) rotate(0deg); }
      100% { transform: translate(-2%, -1%) rotate(1deg); }
    }

    /* Connection Banner */
    .connection-banner {
      display: none;
      position: fixed;
      top: calc(var(--safe-top) + var(--header-h));
      left: 0; right: 0;
      background: #dc2626;
      color: #fff;
      text-align: center;
      padding: 6px 12px;
      font-size: 11px;
      font-weight: 700;
      z-index: 200;
      letter-spacing: 0.5px;
      animation: slideDown 0.3s ease;
    }
    .connection-banner.offline { display: block; }
    @keyframes slideDown { from { transform: translateY(-100%); } to { transform: translateY(0); } }

    /* ─── HEADER ─── */
    .app-header {
      position: relative;
      z-index: 100;
      background: rgba(10, 15, 30, 0.97);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      padding: 10px 16px;
      padding-top: calc(10px + var(--safe-top));
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid var(--border);
      min-height: calc(var(--header-h) + var(--safe-top));
    }

    .app-header::after {
      content: '';
      position: absolute;
      bottom: 0; left: 10%; right: 10%;
      height: 1px;
      background: linear-gradient(90deg, transparent, var(--accent), transparent);
      opacity: 0.5;
    }

    .app-brand { display: flex; align-items: center; gap: 12px; }

    .app-logo {
      width: 42px; height: 42px;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 22px;
      box-shadow: 0 4px 20px rgba(59,130,246,0.4);
      position: relative;
      overflow: hidden;
    }
    .app-logo::after {
      content: '';
      position: absolute;
      top: -50%; left: -50%;
      width: 200%; height: 200%;
      background: linear-gradient(45deg, transparent 40%, rgba(255,255,255,0.15) 50%, transparent 60%);
      animation: logoShine 3s ease-in-out infinite;
    }
    @keyframes logoShine { 0%,100% { transform: translateX(-100%); } 50% { transform: translateX(100%); } }

    .app-title h2 { font-size: 15px; font-weight: 800; color: #fff; letter-spacing: -0.3px; }
    .app-title p {
      font-size: 11px; color: var(--accent); font-weight: 600;
      display: flex; align-items: center; gap: 5px; margin-top: 1px;
    }
    .status-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 8px #22c55e;
      animation: statusPulse 2s ease-in-out infinite;
    }
    .status-dot.offline { background: #ef4444; box-shadow: 0 0 8px #ef4444; }
    @keyframes statusPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

    .header-actions { display: flex; align-items: center; gap: 6px; }

    .btn-header {
      background: var(--bg-glass);
      border: 1px solid var(--border);
      color: var(--text-secondary);
      width: 38px; height: 38px;
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px;
      cursor: pointer;
      transition: all 0.2s ease;
      position: relative;
    }
    .btn-header:active { transform: scale(0.92); background: rgba(255,255,255,0.1); }
    .btn-header .badge {
      position: absolute; top: -3px; right: -3px;
      width: 16px; height: 16px;
      background: #ef4444;
      border-radius: 50%;
      font-size: 9px; font-weight: 800; color: #fff;
      display: none; align-items: center; justify-content: center;
      border: 2px solid var(--bg-main);
    }

    /* ─── LANGUAGE TOOLBAR ─── */
    .lang-toolbar {
      position: relative; z-index: 10;
      background: rgba(10, 15, 30, 0.9);
      padding: 8px 14px;
      display: flex; gap: 6px;
      overflow-x: auto;
      border-bottom: 1px solid var(--border);
      -webkit-overflow-scrolling: touch;
    }
    .lang-toolbar::-webkit-scrollbar { display: none; }

    .lang-pill {
      background: var(--bg-glass);
      border: 1px solid var(--border);
      color: var(--text-dim);
      font-size: 12px; font-weight: 700;
      padding: 6px 14px;
      border-radius: 20px;
      white-space: nowrap;
      cursor: pointer;
      transition: all 0.25s ease;
      display: flex; align-items: center; gap: 5px;
    }
    .lang-pill:active { transform: scale(0.95); }
    .lang-pill.active {
      background: linear-gradient(135deg, #2563eb, #7c3aed);
      color: #fff;
      border-color: transparent;
      box-shadow: 0 3px 12px rgba(37,99,235,0.5);
    }
    .lang-pill .lang-flag { font-size: 14px; }

    /* ─── CHIPS CATEGORY GRID ─── */
    .chips-section {
      position: relative; z-index: 10;
      background: rgba(10, 15, 30, 0.7);
      border-bottom: 1px solid var(--border);
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .chips-section.open { max-height: 500px; }

    .chips-toggle {
      display: flex; align-items: center; justify-content: center;
      gap: 6px;
      padding: 8px 14px;
      background: rgba(255,255,255,0.03);
      border-bottom: 1px solid var(--border);
      color: var(--text-secondary);
      font-size: 12px; font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
      position: relative; z-index: 10;
    }
    .chips-toggle:active { background: rgba(255,255,255,0.06); }
    .chips-toggle i { transition: transform 0.3s ease; font-size: 10px; }
    .chips-section.open + .chips-toggle i,
    .chips-toggle.active i { transform: rotate(180deg); }

    .chips-grid { padding: 10px 12px; }

    .chip-category {
      margin-bottom: 10px;
    }
    .chip-category:last-child { margin-bottom: 0; }

    .cat-header {
      display: flex; align-items: center; gap: 8px;
      margin-bottom: 8px;
      padding: 0 2px;
    }
    .cat-icon {
      width: 26px; height: 26px;
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; color: #fff;
    }
    .cat-label {
      font-size: 11px; font-weight: 800;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.8px;
    }

    .cat-items {
      display: flex; flex-wrap: wrap; gap: 6px;
    }

    .chip-item {
      background: var(--bg-glass);
      border: 1px solid var(--border);
      color: var(--text-secondary);
      font-size: 11.5px; font-weight: 600;
      padding: 7px 12px;
      border-radius: 10px;
      white-space: nowrap;
      cursor: pointer;
      display: flex; align-items: center; gap: 5px;
      transition: all 0.2s ease;
      position: relative;
      overflow: hidden;
    }
    .chip-item::before {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.05), transparent);
      opacity: 0;
      transition: opacity 0.2s;
    }
    .chip-item:active { transform: scale(0.96); }
    .chip-item:active::before { opacity: 1; }
    .chip-item .chip-icon { font-size: 12px; }

    /* ─── CHAT CONTAINER ─── */
    .chat-container {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 16px 14px;
      padding-bottom: 8px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      position: relative;
      z-index: 1;
      -webkit-overflow-scrolling: touch;
      scroll-behavior: smooth;
    }

    /* ─── WELCOME SCREEN ─── */
    .welcome-hero {
      text-align: center;
      padding: 20px 10px 8px;
      animation: fadeInUp 0.5s ease;
    }
    .welcome-hero .hero-icon {
      width: 72px; height: 72px;
      margin: 0 auto 14px;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899);
      border-radius: 24px;
      display: flex; align-items: center; justify-content: center;
      font-size: 36px; color: #fff;
      box-shadow: 0 8px 32px rgba(59,130,246,0.35);
      animation: heroFloat 3s ease-in-out infinite;
      position: relative;
    }
    .welcome-hero .hero-icon::after {
      content: '';
      position: absolute;
      inset: -3px;
      border-radius: 27px;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899);
      z-index: -1;
      opacity: 0.3;
      filter: blur(12px);
      animation: heroGlow 2s ease-in-out infinite alternate;
    }
    @keyframes heroFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
    @keyframes heroGlow { 0% { opacity: 0.2; transform: scale(1); } 100% { opacity: 0.4; transform: scale(1.05); } }

    .welcome-hero h1 {
      font-size: 20px; font-weight: 800; color: #fff;
      margin-bottom: 6px;
      background: linear-gradient(135deg, #fff 0%, #93c5fd 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .welcome-hero p {
      font-size: 13px; color: var(--text-secondary);
      line-height: 1.5;
      max-width: 300px; margin: 0 auto;
    }

    .quick-actions {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 6px;
      margin-top: 12px;
      padding: 0 2px;
      max-height: 220px;
      overflow-y: auto;
    }
    .quick-actions::-webkit-scrollbar { width: 3px; }
    .quick-actions::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
    .quick-action-card {
      background: var(--bg-glass);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-align: center;
    }
    .quick-action-card:active { transform: scale(0.95); border-color: var(--accent); }
    .quick-action-card .qa-icon {
      width: 28px; height: 28px;
      border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; color: #fff;
      margin: 0 auto 4px;
    }
    .quick-action-card .qa-title { font-size: 10px; font-weight: 600; color: #fff; line-height: 1.2; }

    .lang-hint {
      display: flex; align-items: center; justify-content: center;
      gap: 6px; margin-top: 14px;
      font-size: 11px; color: var(--text-dim); font-weight: 600;
    }
    .lang-hint .hint-pill {
      background: var(--bg-glass);
      border: 1px solid var(--border);
      padding: 3px 8px; border-radius: 6px;
      font-size: 11px; color: var(--text-secondary);
    }

    /* ─── MESSAGE GROUPS ─── */
    .msg-group {
      display: flex;
      flex-direction: column;
      max-width: 88%;
      animation: msgSlide 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    }
    @keyframes msgSlide {
      from { opacity: 0; transform: translateY(12px) scale(0.97); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .msg-group.user { align-self: flex-end; }
    .msg-group.assistant { align-self: flex-start; }

    .msg-row {
      display: flex;
      gap: 8px;
      align-items: flex-end;
    }
    .msg-group.user .msg-row { flex-direction: row-reverse; }

    .msg-avatar {
      width: 30px; height: 30px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: #fff;
      flex-shrink: 0;
      margin-bottom: 18px;
    }
    .msg-group.assistant .msg-avatar { background: linear-gradient(135deg, #3b82f6, #8b5cf6); }
    .msg-group.user .msg-avatar { background: linear-gradient(135deg, #10b981, #059669); }

    .msg-content { display: flex; flex-direction: column; }

    .msg-bubble {
      padding: 12px 16px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 1.6;
      word-break: break-word;
      position: relative;
    }

    .msg-group.user .msg-bubble {
      background: var(--user-grad);
      color: #fff;
      border-bottom-right-radius: 6px;
      box-shadow: 0 4px 16px rgba(37,99,235,0.25);
    }

    .msg-group.assistant .msg-bubble {
      background: var(--ai-bg);
      color: #e2e8f0;
      border: 1px solid var(--border);
      border-bottom-left-radius: 6px;
      backdrop-filter: blur(8px);
    }

    .msg-bubble strong { color: #60a5fa; font-weight: 700; }
    .msg-group.user .msg-bubble strong { color: #bfdbfe; }
    .msg-bubble code { background: rgba(0,0,0,0.35); padding: 2px 6px; border-radius: 5px; color: #f472b6; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; }
    .msg-bubble pre { background: rgba(0,0,0,0.35); padding: 10px 12px; border-radius: 10px; overflow-x: auto; margin: 8px 0; }
    .msg-bubble pre code { background: none; padding: 0; color: #e2e8f0; }
    .msg-bubble a { color: #38bdf8; text-decoration: none; font-weight: 600; border-bottom: 1px dashed rgba(56,189,248,0.4); }
    .msg-bubble table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 12px; }
    .msg-bubble th, .msg-bubble td { padding: 6px 8px; border: 1px solid rgba(255,255,255,0.1); text-align: left; }
    .msg-bubble th { background: rgba(59,130,246,0.15); color: #93c5fd; font-weight: 700; }
    .msg-bubble ul, .msg-bubble ol { padding-left: 18px; margin: 6px 0; }
    .msg-bubble li { margin-bottom: 3px; }
    .msg-bubble blockquote { border-left: 3px solid var(--accent); padding-left: 10px; margin: 8px 0; color: var(--text-secondary); font-style: italic; }

    .msg-footer {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 4px;
      padding: 0 2px;
    }
    .msg-group.user .msg-footer { justify-content: flex-end; }

    .msg-meta {
      font-size: 10px;
      color: var(--text-dim);
      font-weight: 500;
    }

    .btn-copy-msg {
      background: none; border: none;
      color: var(--text-dim);
      font-size: 12px;
      cursor: pointer;
      padding: 2px;
      border-radius: 4px;
      transition: all 0.2s;
      display: none;
    }
    .msg-group.assistant:hover .btn-copy-msg,
    .msg-group.assistant:active .btn-copy-msg { display: flex; }
    .btn-copy-msg:active { color: var(--accent); transform: scale(1.2); }
    .btn-copy-msg.copied { color: #22c55e; }

    /* ─── SUGGESTION CHIPS ─── */
    .ai-suggestion-box {
      margin-top: 10px;
      padding-top: 8px;
      border-top: 1px dashed rgba(255,255,255,0.12);
    }
    .ai-suggestion-title {
      font-size: 11px;
      font-weight: 700;
      color: #94a3b8;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 4px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .ai-suggestion-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .ai-suggestion-chip {
      background: rgba(59, 130, 246, 0.12);
      border: 1px solid rgba(59, 130, 246, 0.35);
      color: #60a5fa;
      padding: 5px 12px;
      border-radius: 14px;
      font-size: 11.5px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .ai-suggestion-chip:hover, .ai-suggestion-chip:active {
      background: #3b82f6;
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    /* ─── TYPING INDICATOR ─── */
    .typing-box {
      display: none;
      align-self: flex-start;
      padding: 14px 18px;
      border-radius: 18px;
      border-bottom-left-radius: 6px;
      background: var(--ai-bg);
      border: 1px solid var(--border);
      margin-left: 38px;
    }
    .typing-box.visible { display: flex; align-items: center; gap: 10px; }
    .typing-label { font-size: 12px; color: var(--text-dim); font-weight: 600; }
    .typing-dots { display: flex; gap: 4px; }
    .typing-dot {
      width: 7px; height: 7px;
      background: var(--accent);
      border-radius: 50%;
      animation: typingBounce 1.4s infinite ease-in-out both;
    }
    .typing-dot:nth-child(2) { animation-delay: -0.16s; }
    .typing-dot:nth-child(3) { animation-delay: -0.32s; }
    @keyframes typingBounce { 0%,80%,100% { transform: scale(0.4); opacity: 0.4; } 40% { transform: scale(1); opacity: 1; } }

    /* ─── SCROLL TO BOTTOM FAB ─── */
    .scroll-fab {
      position: fixed;
      bottom: calc(var(--input-h) + var(--safe-bottom) + 16px);
      right: 16px;
      width: 42px; height: 42px;
      background: rgba(30, 41, 59, 0.95);
      backdrop-filter: blur(12px);
      border: 1px solid var(--border-light);
      border-radius: 50%;
      display: none;
      align-items: center; justify-content: center;
      color: var(--accent);
      font-size: 18px;
      cursor: pointer;
      z-index: 50;
      box-shadow: 0 4px 20px rgba(0,0,0,0.3);
      transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
      animation: fabIn 0.3s ease;
    }
    .scroll-fab.visible { display: flex; }
    .scroll-fab:active { transform: scale(0.9); }
    .scroll-fab .fab-badge {
      position: absolute; top: -4px; right: -4px;
      min-width: 18px; height: 18px;
      background: #ef4444;
      border-radius: 10px;
      font-size: 10px; font-weight: 800; color: #fff;
      display: none; align-items: center; justify-content: center;
      padding: 0 4px;
      border: 2px solid var(--bg-main);
    }
    .scroll-fab .fab-badge.visible { display: flex; }
    @keyframes fabIn { from { opacity: 0; transform: scale(0.5) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

    /* ─── INPUT FOOTER ─── */
    .app-footer {
      position: relative;
      z-index: 100;
      background: rgba(10, 15, 30, 0.97);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      padding: 8px 12px;
      padding-bottom: calc(8px + var(--safe-bottom));
      border-top: 1px solid var(--border);
    }

    .input-container {
      background: rgba(255,255,255,0.06);
      border: 1px solid var(--border-light);
      border-radius: 24px;
      display: flex;
      align-items: flex-end;
      gap: 0;
      padding: 4px 4px 4px 16px;
      transition: border-color 0.25s, box-shadow 0.25s;
    }
    .input-container:focus-within {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px var(--accent-soft);
    }

    .chat-input {
      flex: 1;
      background: transparent;
      border: none;
      color: #fff;
      font-size: 14px;
      padding: 10px 0;
      outline: none;
      resize: none;
      max-height: 100px;
      line-height: 1.4;
      font-family: inherit;
    }
    .chat-input::placeholder { color: var(--text-dim); }

    .input-actions {
      display: flex;
      align-items: center;
      gap: 4px;
      padding-bottom: 2px;
    }

    .btn-input {
      background: none;
      border: none;
      width: 38px; height: 38px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px;
      cursor: pointer;
      transition: all 0.2s ease;
      color: var(--text-dim);
    }
    .btn-input:active { transform: scale(0.88); }

    .btn-mic { color: #38bdf8; }
    .btn-mic.listening {
      background: rgba(59, 130, 246, 0.15);
      color: #3b82f6;
      animation: micPulse 1s infinite;
    }
    @keyframes micPulse {
      0% { box-shadow: 0 0 0 0 rgba(59,130,246,0.5); }
      70% { box-shadow: 0 0 0 8px rgba(59,130,246,0); }
      100% { box-shadow: 0 0 0 0 rgba(59,130,246,0); }
    }

    .btn-send {
      background: var(--user-grad);
      color: #fff;
      width: 40px; height: 40px;
      box-shadow: 0 3px 12px rgba(37,99,235,0.4);
      transition: all 0.2s ease;
    }
    .btn-send:active { transform: scale(0.88); box-shadow: 0 2px 8px rgba(37,99,235,0.3); }
    .btn-send:disabled { opacity: 0.4; box-shadow: none; }

    .input-hint {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 4px 4px 0;
      font-size: 10px;
      color: var(--text-dim);
    }
    .char-count { font-variant-numeric: tabular-nums; }

    /* ─── ANIMATIONS ─── */
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(16px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    /* ─── SCROLLBAR ─── */
    .chat-container::-webkit-scrollbar { width: 3px; }
    .chat-container::-webkit-scrollbar-track { background: transparent; }
    .chat-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }

    /* ─── RESPONSIVE ─── */
    @media (min-width: 480px) {
      .chat-container { padding: 20px 24px; }
      .msg-group { max-width: 75%; }
      .quick-actions { grid-template-columns: 1fr 1fr 1fr; }
    }
    @media (min-width: 768px) {
      .chat-container { padding: 24px 32px; max-width: 800px; margin: 0 auto; width: 100%; }
      .msg-group { max-width: 65%; }
    }
  </style>
</head>
<body>

  <!-- Connection Offline Banner -->
  <div class="connection-banner" id="connectionBanner">
    <i class="bi bi-wifi-off"></i> You are offline — messages will send when reconnected
  </div>

  <!-- Header -->
  <header class="app-header">
    <div class="app-brand">
      <div class="app-logo">
        <i class="bi bi-robot"></i>
      </div>
      <div class="app-title">
        <h2>ERP AI Copilot</h2>
        <p><span class="status-dot" id="statusDot"></span> <span id="statusText">Online — Smart RAG</span></p>
      </div>
    </div>
    <div class="header-actions">
      <button class="btn-header btn-theme" id="themeBtn" title="Toggle Theme">
        <i class="bi bi-moon-fill icon-moon"></i>
        <i class="bi bi-sun-fill icon-sun"></i>
      </button>
      <button class="btn-header" id="historyBtn" title="History">
        <i class="bi bi-clock-history"></i>
      </button>
      <button class="btn-header" id="clearBtn" title="Clear Chat">
        <i class="bi bi-trash3"></i>
      </button>
    </div>
  </header>

  <!-- Language Pills -->
  <div class="lang-toolbar">
    <button class="lang-pill" data-lang="en-US"><span class="lang-flag">🇬🇧</span> English</button>
    <button class="lang-pill" data-lang="bn-IN"><span class="lang-flag">🇧🇩</span> বাংলা</button>
    <button class="lang-pill" data-lang="hi-IN"><span class="lang-flag">🇮🇳</span> हिंदी</button>
    <button class="lang-pill active" data-lang="auto"><span class="lang-flag">🤖</span> Auto</button>
  </div>

  <!-- Chips Toggle Button -->
  <div class="chips-toggle" id="chipsToggle">
    <i class="bi bi-grid-3x3-gap-fill"></i> Quick Actions
    <i class="bi bi-chevron-down"></i>
  </div>

  <!-- Chips Category Grid -->
  <div class="chips-section" id="chipsSection">
    <div class="chips-grid">
      <?php foreach ($chipCategories as $catKey => $cat): ?>
        <?php if (!empty($cat['items'])): ?>
        <div class="chip-category">
          <div class="cat-header">
            <div class="cat-icon" style="background: <?= $cat['color'] ?>20; color: <?= $cat['color'] ?>;">
              <i class="bi <?= $cat['icon'] ?>"></i>
            </div>
            <span class="cat-label"><?= $cat['label'] ?></span>
          </div>
          <div class="cat-items">
            <?php foreach ($cat['items'] as $c): ?>
              <button class="chip-item" data-prompt="<?= e($c['prompt']) ?>">
                <i class="bi <?= $cat['icon'] ?> chip-icon" style="color: <?= $cat['color'] ?>"></i>
                <?= e($c['label']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Chat Container -->
  <main class="chat-container" id="chatStream">
    <!-- Welcome Hero -->
    <div class="welcome-hero" id="welcomeHero">
      <div class="hero-icon"><i class="bi bi-robot"></i></div>
      <h1>Shree Label AI Copilot</h1>
      <p>Your smart manufacturing assistant. Ask about production, inventory, orders, dispatches, and more.</p>

      <div class="quick-actions">
        <?php foreach ($quickChips as $c): ?>
        <div class="quick-action-card" data-prompt="<?= e($c['prompt']) ?>">
          <div class="qa-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);"><i class="bi <?= e($c['icon']) ?>"></i></div>
          <div class="qa-title"><?= e($c['label']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="lang-hint">
        <i class="bi bi-translate"></i>
        Speak in
        <span class="hint-pill">English</span>
        <span class="hint-pill">বাংলা</span>
        <span class="hint-pill">हिंदी</span>
      </div>
    </div>

    <!-- Typing Indicator -->
    <div class="typing-box" id="typingBox">
      <div class="typing-dots">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
      <span class="typing-label">Thinking...</span>
    </div>
  </main>

  <!-- Scroll to Bottom FAB -->
  <button class="scroll-fab" id="scrollFab">
    <i class="bi bi-chevron-down"></i>
    <span class="fab-badge" id="fabBadge">0</span>
  </button>

  <!-- Input Footer -->
  <footer class="app-footer">
    <div class="input-container">
      <textarea class="chat-input" id="chatInput" placeholder="Ask about stock, orders, dispatch..." rows="1" autocomplete="off"></textarea>
      <div class="input-actions">
        <button class="btn-input btn-mic" id="micBtn" title="Voice Input">
          <i class="bi bi-mic-fill"></i>
        </button>
        <button class="btn-input btn-send" id="sendBtn" title="Send" disabled>
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
    </div>
    <div class="input-hint">
      <span id="langHint">Auto-detect language</span>
      <span class="char-count" id="charCount"></span>
    </div>
  </footer>

  <script>
    const API_URL = '<?= $baseUrl ?>/modules/ai_agent/api.php';
    let selectedLang = 'auto';
    let newMsgCount = 0;
    let isUserScrolled = false;
    let currentTheme = localStorage.getItem('erp-ai-theme') || 'dark';

    // Theme Management
    function applyTheme(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      const meta = document.getElementById('themeColorMeta');
      if (meta) meta.content = theme === 'light' ? '#ffffff' : '#0a0f1e';
      localStorage.setItem('erp-ai-theme', theme);
      currentTheme = theme;
    }
    applyTheme(currentTheme);

    const themeBtn = document.getElementById('themeBtn');
    themeBtn.addEventListener('click', () => {
      const next = currentTheme === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      if (navigator.vibrate) navigator.vibrate(10);
    });

    // Service Worker
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= $baseUrl ?>/modules/ai_agent/sw.js').catch(() => {});
      });
    }

    const chatStream = document.getElementById('chatStream');
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const micBtn = document.getElementById('micBtn');
    const clearBtn = document.getElementById('clearBtn');
    const typingBox = document.getElementById('typingBox');
    const scrollFab = document.getElementById('scrollFab');
    const fabBadge = document.getElementById('fabBadge');
    const chipsToggle = document.getElementById('chipsToggle');
    const chipsSection = document.getElementById('chipsSection');
    const statusDot = document.getElementById('statusDot');
    const statusText = document.getElementById('statusText');
    const connectionBanner = document.getElementById('connectionBanner');
    const charCount = document.getElementById('charCount');
    const langHint = document.getElementById('langHint');
    const welcomeHero = document.getElementById('welcomeHero');

    // Auto-resize textarea
    chatInput.addEventListener('input', () => {
      chatInput.style.height = 'auto';
      chatInput.style.height = Math.min(chatInput.scrollHeight, 100) + 'px';
      const len = chatInput.value.length;
      sendBtn.disabled = len === 0;
      charCount.textContent = len > 0 ? len + '/500' : '';
    });

    // Chips toggle
    chipsToggle.addEventListener('click', () => {
      chipsSection.classList.toggle('open');
      chipsToggle.classList.toggle('active');
    });

    // Language pills
    document.querySelectorAll('.lang-pill').forEach(pill => {
      pill.addEventListener('click', () => {
        document.querySelectorAll('.lang-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        selectedLang = pill.getAttribute('data-lang');
        const names = { 'auto': 'Auto-detect language', 'en-US': 'English', 'bn-IN': 'বাংলা', 'hi-IN': 'हिंदी' };
        langHint.textContent = names[selectedLang] || 'Auto-detect language';
        if (navigator.vibrate) navigator.vibrate(10);
      });
    });

    // Quick chips click
    document.querySelectorAll('.chip-item').forEach(chip => {
      chip.addEventListener('click', () => {
        chatInput.value = chip.getAttribute('data-prompt');
        chatInput.dispatchEvent(new Event('input'));
        sendQuery();
        chipsSection.classList.remove('open');
        chipsToggle.classList.remove('active');
      });
    });

    // Quick action cards
    document.querySelectorAll('.quick-action-card').forEach(card => {
      card.addEventListener('click', () => {
        chatInput.value = card.getAttribute('data-prompt');
        chatInput.dispatchEvent(new Event('input'));
        sendQuery();
      });
    });

    // Clear Chat
    clearBtn.addEventListener('click', () => {
      if (navigator.vibrate) navigator.vibrate(20);
      const welcomeHTML = welcomeHero ? welcomeHero.outerHTML : '';
      chatStream.innerHTML = '';
      if (welcomeHTML) chatStream.innerHTML = welcomeHTML;
      chatStream.appendChild(typingBox);
      newMsgCount = 0;
      fabBadge.classList.remove('visible');
    });

    // Scroll to bottom
    sendBtn.addEventListener('click', sendQuery);
    chatInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuery(); }
    });

    scrollFab.addEventListener('click', () => {
      chatStream.scrollTo({ top: chatStream.scrollHeight, behavior: 'smooth' });
      newMsgCount = 0;
      fabBadge.classList.remove('visible');
    });

    // Track scroll position for FAB
    chatStream.addEventListener('scroll', () => {
      const threshold = 120;
      const atBottom = chatStream.scrollHeight - chatStream.scrollTop - chatStream.clientHeight < threshold;
      isUserScrolled = !atBottom;
      scrollFab.classList.toggle('visible', isUserScrolled);
    });

    // Network status
    function updateOnlineStatus() {
      const online = navigator.onLine;
      statusDot.classList.toggle('offline', !online);
      statusText.textContent = online ? 'Online — Smart RAG' : 'Offline';
      connectionBanner.classList.toggle('offline', !online);
    }
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    updateOnlineStatus();

    async function sendQuery() {
      const prompt = chatInput.value.trim();
      if (!prompt) return;

      chatInput.value = '';
      chatInput.style.height = 'auto';
      chatInput.dispatchEvent(new Event('input'));
      sendBtn.disabled = true;

      if (welcomeHero) welcomeHero.remove();
      appendUserMsg(prompt);
      showTyping(true);
      if (navigator.vibrate) navigator.vibrate(15);

      try {
        const res = await fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'query', prompt: prompt, user_lang: selectedLang })
        });
        const data = await res.json();
        showTyping(false);

        if (data.ok) {
          appendAiMsg(data.answer, data.suggestions);
          if (data.nav_url) {
            appendAiMsg(`🚀 **Redirect Link:** [Click here to open ${data.tool_used || 'Module Page'}](${data.nav_url})`);
          }
        } else {
          appendAiMsg(`⚠️ **Error:** ${data.error || 'Unable to process query.'}`);
        }
      } catch (err) {
        showTyping(false);
        appendAiMsg('❌ **Network Error:** Failed to connect to ERP AI engine. Please check your connection.');
      }
    }

    // Delegation for suggestion chips inside chatStream
    chatStream.addEventListener('click', (e) => {
      const chip = e.target.closest('.ai-suggestion-chip');
      if (chip) {
        const prompt = chip.getAttribute('data-prompt');
        if (prompt) {
          chatInput.value = prompt;
          sendQuery();
        }
      }
    });

    function appendUserMsg(text) {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const html = `
        <div class="msg-group user">
          <div class="msg-row">
            <div class="msg-avatar"><i class="bi bi-person-fill"></i></div>
            <div class="msg-content">
              <div class="msg-bubble">${escapeHtml(text)}</div>
              <div class="msg-footer">
                <span class="msg-meta">You · ${time}</span>
              </div>
            </div>
          </div>
        </div>`;
      chatStream.insertAdjacentHTML('beforeend', html);
      scrollToBottom();
    }

    function appendAiMsg(markdownText, suggestions) {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      let parsedHtml = marked.parse(markdownText);

      if (suggestions && suggestions.length > 0) {
        parsedHtml += `<div class="ai-suggestion-box">
          <div class="ai-suggestion-title"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Suggested Questions:</div>
          <div class="ai-suggestion-chips">`;
        for (const sug of suggestions) {
          parsedHtml += `<button type="button" class="ai-suggestion-chip" data-prompt="${escapeHtml(sug)}"><i class="bi bi-chat-left-text"></i> ${escapeHtml(sug)}</button>`;
        }
        parsedHtml += `</div></div>`;
      }

      const html = `
        <div class="msg-group assistant">
          <div class="msg-row">
            <div class="msg-avatar"><i class="bi bi-robot"></i></div>
            <div class="msg-content">
              <div class="msg-bubble">${parsedHtml}</div>
              <div class="msg-footer">
                <span class="msg-meta">AI Copilot · ${time}</span>
                <button class="btn-copy-msg" onclick="copyMsg(this)" title="Copy"><i class="bi bi-clipboard"></i></button>
              </div>
            </div>
          </div>
        </div>`;
      chatStream.insertAdjacentHTML('beforeend', html);
      if (!isUserScrolled) {
        scrollToBottom();
      } else {
        newMsgCount++;
        fabBadge.textContent = newMsgCount;
        fabBadge.classList.add('visible');
      }
    }

    function copyMsg(btn) {
      const bubble = btn.closest('.msg-content').querySelector('.msg-bubble');
      const text = bubble.innerText || bubble.textContent;
      navigator.clipboard.writeText(text).then(() => {
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.add('copied');
        if (navigator.vibrate) navigator.vibrate(10);
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; btn.classList.remove('copied'); }, 1500);
      });
    }

    function showTyping(show) {
      typingBox.classList.toggle('visible', show);
      if (show) scrollToBottom();
    }

    function scrollToBottom() {
      chatStream.scrollTo({ top: chatStream.scrollHeight, behavior: 'smooth' });
    }

    function escapeHtml(text) {
      return text.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    // Voice Speech Recognition — Press & Hold (Push-to-Talk)
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let isListening = false;
    let currentUtterance = '';

    if (SpeechRecognition) {
      const recognition = new SpeechRecognition();
      recognition.interimResults = true;
      recognition.continuous = true;
      recognition.maxAlternatives = 1;

      let touchFired = false;

      function startListening() {
        if (isListening) return;
        isListening = true;
        currentUtterance = '';
        chatInput.value = '';

        if (selectedLang === 'hi-IN' || selectedLang === 'Hindi') recognition.lang = 'hi-IN';
        else if (selectedLang === 'bn-IN' || selectedLang === 'Bengali') recognition.lang = 'bn-IN';
        else if (selectedLang === 'en-US' || selectedLang === 'English') recognition.lang = 'en-US';
        else recognition.lang = 'hi-IN';

        try { recognition.start(); } catch(e) { isListening = false; return; }
        micBtn.classList.add('listening');
        chatInput.placeholder = '🎙️ Listening... Release mic to send';
      }

      function stopListening() {
        if (!isListening) return;
        try { recognition.stop(); } catch(e) {}
      }

      micBtn.addEventListener('touchstart', (e) => {
        touchFired = true;
        startListening();
      });

      micBtn.addEventListener('touchend', (e) => {
        touchFired = false;
        stopListening();
      });

      micBtn.addEventListener('touchcancel', () => {
        touchFired = false;
        isListening = false;
        try { recognition.stop(); } catch(e) {}
        micBtn.classList.remove('listening');
        chatInput.placeholder = 'Ask about stock, orders, dispatch...';
      });

      micBtn.addEventListener('mousedown', (e) => {
        if (touchFired) return;
        startListening();
      });

      micBtn.addEventListener('mouseup', (e) => {
        if (touchFired) return;
        stopListening();
      });

      micBtn.addEventListener('mouseleave', () => {
        if (touchFired) return;
        stopListening();
      });

      recognition.onresult = (e) => {
        const latest = e.results[e.resultIndex][0].transcript;
        if (e.results[e.resultIndex].isFinal) {
          currentUtterance = latest;
          chatInput.value = latest;
        } else {
          chatInput.value = latest;
        }
        chatInput.dispatchEvent(new Event('input'));
      };

      recognition.onerror = (e) => {
        if (e.error === 'no-speech' || e.error === 'aborted') return;
        isListening = false;
        micBtn.classList.remove('listening');
        chatInput.placeholder = 'Ask about stock, orders, dispatch...';
      };

      recognition.onend = () => {
        isListening = false;
        micBtn.classList.remove('listening');
        chatInput.placeholder = 'Ask about stock, orders, dispatch...';
        const text = currentUtterance.trim() || chatInput.value.trim();
        if (text) {
          chatInput.value = text;
          sendQuery();
        }
      };
    } else {
      micBtn.style.display = 'none';
    }

    // Focus input on load
    setTimeout(() => chatInput.focus(), 300);
  </script>
</body>
</html>