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

// Auto-login as the System Admin (user id 1) for the standalone mobile PWA.
// Mirror a real ERP login session (role, group, tenant) so RBAC permission
// checks (isAdmin / canAccessPath, e.g. export.php) behave identically to a
// normal ERP login — otherwise filtered PDF/CSV export links get redirected
// to the dashboard as "access denied".
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}
if (empty($_SESSION['role']) || empty($_SESSION['user_name'])) {
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, name, email, role, group_id FROM users WHERE id = 1 AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if ($u) {
            $_SESSION['user_id']     = (int)$u['id'];
            $_SESSION['user_name']   = $u['name'];
            $_SESSION['user_email']  = $u['email'];
            $_SESSION['role']        = $u['role'];
            $_SESSION['group_id']    = isset($u['group_id']) ? (int)$u['group_id'] : 0;
            $_SESSION['tenant_slug'] = defined('TENANT_SLUG') ? TENANT_SLUG : 'default';
            $_SESSION['tenant_name'] = defined('TENANT_NAME') ? TENANT_NAME : APP_NAME;
        }
    } catch (Throwable $e) {
        // Fallback: minimum session (user_id only) already set above.
    }
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
    'erp_overview'          => 'production',
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
$promptSuggestionsPath = __DIR__ . '/../../data/prompt_suggestions.json';
$promptSuggestionsJson = file_exists($promptSuggestionsPath) ? file_get_contents($promptSuggestionsPath) : '{}';
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
    [data-theme="light"] .msg-bubble tr:nth-child(even) td { background: rgba(0,0,0,0.02); }

    [data-theme="light"] .msg-group.assistant.ai-cmd-paperstock .msg-bubble { border-color: #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.04) 0%, rgba(16,185,129,0.01) 100%); }
    [data-theme="light"] .msg-group.assistant.ai-cmd-plate .msg-bubble { border-color: #8b5cf6; background: linear-gradient(135deg, rgba(139,92,246,0.04) 0%, rgba(139,92,246,0.01) 100%); }
    [data-theme="light"] .msg-group.assistant.ai-cmd-quoted .msg-bubble { border-color: #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.04) 0%, rgba(245,158,11,0.01) 100%); }

    [data-theme="light"] .typing-box { background: rgba(0,0,0,0.04); border-color: rgba(0,0,0,0.08); }
    [data-theme="light"] .input-container { background: rgba(0,0,0,0.04); }
    [data-theme="light"] .chat-input { color: #0f172a; }
    [data-theme="light"] .chat-input::placeholder { color: #94a3b8; }
    [data-theme="light"] .input-hint { color: #94a3b8; }

    [data-theme="light"] .chat-container::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); }
    [data-theme="light"] .quick-action-card { background: rgba(0,0,0,0.03); }
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
      padding: 16px 8px;
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

    /* ─── MESSAGE GROUPS ─── */
    .msg-group {
      display: flex;
      flex-direction: column;
      max-width: 100%;
      min-width: 0;
      animation: msgSlide 0.3s cubic-bezier(0.22, 1, 0.36, 1);
    }
    @keyframes msgSlide {
      from { opacity: 0; transform: translateY(12px) scale(0.97); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .msg-group.user { align-self: flex-end; max-width: 90%; }
    .msg-group.assistant { align-self: flex-start; max-width: 100%; width: 100%; }

    .msg-row {
      display: flex;
      gap: 8px;
      align-items: flex-end;
      min-width: 0;
      width: 100%;
      max-width: 100%;
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

    .msg-content {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-width: 0;
      max-width: calc(100% - 38px);
    }

    .msg-bubble {
      padding: 12px 16px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 1.6;
      word-break: break-word;
      position: relative;
      min-width: 0;
      width: 100%;
      box-sizing: border-box;
      overflow-x: hidden;
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

    .msg-bubble p { margin: 0 0 12px 0; }
    .msg-bubble p:last-child { margin-bottom: 0; }
    
    .msg-bubble strong { color: #60a5fa; font-weight: 700; }
    .msg-group.user .msg-bubble strong { color: #bfdbfe; }
    .msg-bubble code { background: rgba(0,0,0,0.35); padding: 2px 6px; border-radius: 5px; color: #f472b6; font-family: 'JetBrains Mono', monospace; font-size: 12.5px; }
    .msg-bubble pre { background: rgba(0,0,0,0.35); padding: 10px 12px; border-radius: 10px; overflow-x: auto; margin: 8px 0; }
    .msg-bubble pre code { background: none; padding: 0; color: #e2e8f0; }
    .msg-bubble a { color: #38bdf8; text-decoration: none; font-weight: 600; border-bottom: 1px dashed rgba(56,189,248,0.4); }
    
    /* Mobile Responsive Markdown Tables */
    .table-responsive-wrapper {
      display: block;
      width: 100%;
      max-width: 100%;
      overflow-x: auto !important;
      -webkit-overflow-scrolling: touch;
      margin: 12px 0;
      border-radius: 12px;
      border: 1px solid var(--border-light);
      background: rgba(15, 23, 42, 0.7);
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.3);
      box-sizing: border-box;
      touch-action: pan-x;
    }
    .table-responsive-wrapper::-webkit-scrollbar {
      height: 6px;
    }
    .table-responsive-wrapper::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.04);
      border-radius: 4px;
    }
    .table-responsive-wrapper::-webkit-scrollbar-thumb {
      background: rgba(59, 130, 246, 0.5);
      border-radius: 4px;
    }
    .table-responsive-wrapper::-webkit-scrollbar-thumb:hover {
      background: rgba(59, 130, 246, 0.8);
    }

    .msg-bubble table {
      width: max-content !important;
      min-width: 100%;
      table-layout: auto !important;
      border-collapse: collapse;
      margin: 0;
      font-size: 12.5px;
      line-height: 1.5;
    }
    .msg-bubble th, .msg-bubble td {
      padding: 9px 14px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      text-align: left;
      white-space: nowrap !important;
      word-break: normal !important;
    }
    .msg-bubble th {
      background: linear-gradient(135deg, rgba(59,130,246,0.25) 0%, rgba(37,99,235,0.15) 100%);
      color: #93c5fd;
      font-weight: 700;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .msg-bubble tr:nth-child(even) td { background: rgba(255, 255, 255, 0.03); }
    .msg-bubble tr:hover td { background: rgba(59, 130, 246, 0.08); }

    /* Special Command Visual Styling — Paper Stock (green) */
    .msg-group.assistant.ai-cmd-paperstock .msg-bubble { border: 1.5px solid #10b981; background: linear-gradient(135deg, rgba(16,185,129,0.06) 0%, rgba(16,185,129,0.02) 100%); }
    .msg-group.assistant.ai-cmd-paperstock .msg-avatar { background: linear-gradient(135deg, #10b981, #059669); }

    /* Special Command Visual Styling — Plate (purple) */
    .msg-group.assistant.ai-cmd-plate .msg-bubble { border: 1.5px solid #8b5cf6; background: linear-gradient(135deg, rgba(139,92,246,0.06) 0%, rgba(139,92,246,0.02) 100%); }
    .msg-group.assistant.ai-cmd-plate .msg-avatar { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

    /* Special Command Visual Styling — Quoted Query (amber) */
    .msg-group.assistant.ai-cmd-quoted .msg-bubble { border: 1.5px solid #f59e0b; background: linear-gradient(135deg, rgba(245,158,11,0.06) 0%, rgba(245,158,11,0.02) 100%); }
    .msg-group.assistant.ai-cmd-quoted .msg-avatar { background: linear-gradient(135deg, #f59e0b, #d97706); }

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
      color: #94a3b8;
      font-size: 13px;
      cursor: pointer;
      padding: 3px 6px;
      border-radius: 4px;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      opacity: 0.85;
      margin-left: 4px;
    }
    .btn-copy-msg:hover {
      opacity: 1;
      color: #3b82f6;
      background: rgba(59, 130, 246, 0.12);
      transform: scale(1.1);
    }
    .msg-group.assistant .btn-copy-msg,
    .msg-group.user .btn-copy-msg { display: inline-flex; }
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
      white-space: pre-wrap;
      overflow-y: auto;
      word-break: break-word;
    }
    .chat-input:empty::before {
      content: attr(data-placeholder);
      color: var(--text-dim);
      pointer-events: none;
    }
    .chat-input .cmd-highlight {
      color: #ef4444;
      font-weight: 600;
    }
    .chat-input .cmd-highlight-done {
      color: #ef4444;
      font-weight: 600;
    }
    .chat-input .cmd-highlight + .cmd-highlight-done {
      margin-left: 0;
    }
    /* Quote highlight in input — product/item names (bold + accent color) */
    .chat-input .cmd-quote-hl {
      color: #f59e0b;
      font-weight: 800;
      text-shadow: 0 0 8px rgba(245, 158, 11, 0.25);
    }
    .chat-input .cmd-quote-hl .qp {
      color: #f59e0b;
      opacity: 0.5;
      font-weight: 400;
    }
    .chat-input .cmd-quote-hl.done {
      color: #10b981;
      font-weight: 800;
    }
    .chat-input .cmd-quote-hl.done .qp {
      color: #10b981;
      opacity: 0.4;
      font-weight: 400;
    }

    /* Quote highlight for product/item names in user messages */
    .msg-bubble .quote-highlight {
      display: inline-block;
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      font-weight: 800;
      padding: 1px 8px;
      border-radius: 4px;
      font-size: 0.85em;
    }
    [data-theme="light"] .msg-bubble .quote-highlight {
      background: rgba(245, 158, 11, 0.1);
      color: #d97706;
    }

    /* Command Suggestions Dropup */
    .cmd-suggestions {
      position: absolute;
      bottom: calc(100% + 8px);
      left: 0;
      right: 0;
      background: var(--bg-card);
      border: 1px solid var(--border-light);
      border-radius: 16px;
      padding: 6px;
      display: none;
      z-index: 100;
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      box-shadow: 0 -8px 32px rgba(0,0,0,0.4);
      max-height: 200px;
      overflow-y: auto;
    }
    .cmd-suggestions.visible { display: block; }
    .cmd-suggestion-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 10px;
      cursor: pointer;
      transition: background 0.15s;
      color: var(--text-primary);
    }
    .cmd-suggestion-item:hover, .cmd-suggestion-item.active {
      background: var(--accent-soft);
    }
    .cmd-suggestion-item .cmd-key {
      font-weight: 700;
      color: #3b82f6;
      font-size: 15px;
      min-width: 70px;
    }
    .cmd-suggestion-item .cmd-desc {
      font-size: 13px;
      color: var(--text-secondary);
    }
    .cmd-suggestion-item .cmd-key-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(59, 130, 246, 0.15);
      color: #3b82f6;
      font-weight: 700;
      font-size: 12px;
      padding: 2px 10px;
      border-radius: 6px;
      min-width: 70px;
    }
    /* ─── 3-Level Color Coding (PWA) ─── */
    /* Level 1: Commands `/` — BLUE */
    #cmdSuggestions { border-color: rgba(59,130,246,0.55); box-shadow: 0 -8px 32px rgba(59,130,246,0.16); }
    /* Level 2: Query suggestions `/cmd ` — GREEN */
    #cmdSuggestionsPopup { border-color: rgba(16,185,129,0.55); box-shadow: 0 -8px 32px rgba(16,185,129,0.16); }
    #cmdSuggestionsPopup .popup-item:hover { background: rgba(16,185,129,0.18); color: #6ee7b7; }
    #cmdSuggestionsPopup .popup-item i { color: #10b981; }
    [data-theme="light"] #cmdSuggestionsPopup .popup-item:hover { background: rgba(16,185,129,0.12); color: #059669; }
    /* Level 3: Entities `/cmd "term` — AMBER */
    #autocompleteSuggestions { border-color: rgba(245,158,11,0.55); box-shadow: 0 -8px 32px rgba(245,158,11,0.16); }
    #autocompleteSuggestions .cmd-suggestion-item .cmd-key-badge { background: rgba(245,158,11,0.15); color: #f59e0b; }
    #autocompleteSuggestions .cmd-suggestion-item:hover, #autocompleteSuggestions .cmd-suggestion-item.active { background: rgba(245,158,11,0.12); }

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

    .btn-mic {
      color: var(--text-secondary);
      margin-right: 4px;
      transition: all 0.25s ease;
    }
    .btn-mic.listening {
      color: #ef4444 !important;
      background: rgba(239, 68, 68, 0.2) !important;
      box-shadow: 0 0 12px rgba(239, 68, 68, 0.4);
      animation: pulseMic 1.5s infinite;
    }
    @keyframes pulseMic {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.1); }
    }

    .code-block-wrapper { position: relative; margin: 8px 0; }
    .btn-copy-code {
      position: absolute;
      top: 6px; right: 6px;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.2);
      color: #94a3b8;
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 6px;
      cursor: pointer;
      display: flex; align-items: center; gap: 4px;
      transition: all 0.2s;
      z-index: 5;
    }
    .btn-copy-code:hover { background: rgba(255,255,255,0.25); color: #fff; }

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
      .chat-container { padding: 20px 16px; }
      .msg-group { max-width: 88%; }
      .quick-actions { grid-template-columns: 1fr 1fr 1fr; }
    }
    @media (min-width: 768px) {
      .chat-container { padding: 24px 20px; max-width: 800px; margin: 0 auto; width: 100%; }
      .msg-group { max-width: 85%; }
    }
      /* Floating Popup Menu */
    .input-container { position: relative; } /* Anchor for popup */
    .ai-popup-menu {
      position: absolute;
      bottom: calc(100% + 12px);
      left: 10px;
      right: 10px;
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
    
    .popup-item {
      padding: 10px 16px;
      color: #e2e8f0;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
      transition: background 0.15s;
    }
    .popup-item:hover {
      background: rgba(59, 130, 246, 0.2);
      color: #93c5fd;
    }
    .popup-item i { color: #3b82f6; font-size: 15px; }
    
    /* Light Theme */
    [data-theme="light"] .ai-popup-menu {
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(0, 0, 0, 0.1);
      box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.1);
    }
    [data-theme="light"] .popup-item { color: #334155; }
    [data-theme="light"] .popup-item:hover { background: rgba(37,99,235,0.1); color: #2563eb; }
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
      <button class="btn-header" id="shareBtn" title="Share AI App">
        <i class="bi bi-share-fill"></i>
      </button>
      <button class="btn-header" id="historyBtn" title="History">
        <i class="bi bi-clock-history"></i>
      </button>
      <button class="btn-header" id="clearBtn" title="Clear Chat">
        <i class="bi bi-trash3"></i>
      </button>
    </div>
  </header>

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

    </div>

    <!-- Typing Indicator -->
    <div class="typing-box" id="typingBox">
      <div class="typing-dots">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
      <span class="typing-label" id="typingLabel">Thinking...</span>
    </div>
  </main>

  <!-- Scroll to Bottom FAB -->
  <button class="scroll-fab" id="scrollFab">
    <i class="bi bi-chevron-down"></i>
    <span class="fab-badge" id="fabBadge">0</span>
  </button>

  <!-- Input Footer -->
  <footer class="app-footer">
    <div class="input-container" style="position:relative;">
      <!-- Command Suggestions Dropup -->
      <div class="cmd-suggestions" id="autocompleteSuggestions" style="display:none; max-height: 250px; overflow-y: auto;"></div>
      <div class="cmd-suggestions" id="cmdSuggestions">
        <div class="cmd-suggestion-item" data-cmd="/erp">
          <span class="cmd-key-badge">/erp</span>
          <span class="cmd-desc">Executive 360° ERP Master Overview</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/job">
          <span class="cmd-key-badge">/job</span>
          <span class="cmd-desc">Job / Planning Priority Mode</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/plate">
          <span class="cmd-key-badge">/plate</span>
          <span class="cmd-desc">Plate Priority Mode</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/planning">
          <span class="cmd-key-badge">/planning</span>
          <span class="cmd-desc">Job Planning Board</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/paper">
          <span class="cmd-key-badge">/paper</span>
          <span class="cmd-desc">Paper Stock Priority Mode</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/product">
          <span class="cmd-key-badge">/product</span>
          <span class="cmd-desc">Product / Item lookup</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/client">
          <span class="cmd-key-badge">/client</span>
          <span class="cmd-desc">Client / Party lookup</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/dispatch">
          <span class="cmd-key-badge">/dispatch</span>
          <span class="cmd-desc">Dispatch / Packing Priority Mode</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/order">
          <span class="cmd-key-badge">/order</span>
          <span class="cmd-desc">Order lookup</span>
        </div>
        <div class="cmd-suggestion-item" data-cmd="/stock">
          <span class="cmd-key-badge">/stock</span>
          <span class="cmd-desc">Stock lookup</span>
        </div>
      </div>
      <button class="btn-input btn-mic" id="micBtn" title="Speak to AI Agent">
        <i class="bi bi-mic-fill" id="micIcon"></i>
      </button>
            <!-- Floating Popup Menu -->
      <div id="cmdSuggestionsPopup" class="ai-popup-menu"></div>
      
      <div class="chat-input" id="chatInput" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Ask about stock, orders or speak..."></div>
      <div class="input-actions">
        <button class="btn-input btn-send" id="sendBtn" title="Send" disabled>
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
    </div>
    <div class="input-hint">
      <span id="voiceStatusText" style="color:#3b82f6;font-weight:700"></span>
      <span class="char-count" id="charCount"></span>
    </div>
  </footer>

  <script>
    const API_URL = '<?= $baseUrl ?>/modules/ai_agent/api.php';
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

    // Native Web Share API + Mobile Clipboard Fallback
    const shareBtn = document.getElementById('shareBtn');
    if (shareBtn) {
      shareBtn.addEventListener('click', async () => {
        if (navigator.vibrate) navigator.vibrate(15);
        const shareData = {
          title: 'ERP-BOT — Mobile PWA AI Copilot',
          text: '⚡ Shree Label ERP Mobile PWA AI Agent — Smart Manufacturing Assistant!',
          url: window.location.href
        };
        if (navigator.share && (navigator.canShare ? navigator.canShare(shareData) : true)) {
          try {
            await navigator.share(shareData);
          } catch (err) {
            if (err.name !== 'AbortError') copyAppUrlFallback();
          }
        } else {
          copyAppUrlFallback();
        }
      });
    }

    function copyAppUrlFallback() {
      const url = window.location.href;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => {
          showShareToast('📋 App URL copied to clipboard!');
        }).catch(() => {
          prompt('Copy App URL:', url);
        });
      } else {
        prompt('Copy App URL:', url);
      }
    }

    function showShareToast(msg) {
      let toast = document.getElementById('shareToast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'shareToast';
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#10b981;color:#fff;font-size:13px;font-weight:600;padding:8px 18px;border-radius:20px;z-index:999999;box-shadow:0 4px 16px rgba(16,185,129,0.4);transition:all 0.3s ease;opacity:0;pointer-events:none;';
        document.body.appendChild(toast);
      }
      toast.textContent = msg;
      toast.style.opacity = '1';
      setTimeout(() => { toast.style.opacity = '0'; }, 2500);
    }

    // Service Worker
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('<?= $baseUrl ?>/modules/ai_agent/sw.js').catch(() => {});
      });
    }

    // Auto-refresh when PWA app is reopened from BFCache (e.g. after being backgrounded)
    window.addEventListener('pageshow', (e) => {
      if (e.persisted) {
        location.reload();
      }
    });

    const chatStream = document.getElementById('chatStream');
    const chatInput = document.getElementById('chatInput');
    const promptSuggestionsData = <?= $promptSuggestionsJson ?>;
    const cmdSuggestionsPopup = document.getElementById('cmdSuggestionsPopup');

    function renderCmdSuggestions(cmd) {
      if (!promptSuggestionsData || !promptSuggestionsData[cmd]) {
        cmdSuggestionsPopup.style.display = 'none';
        return;
      }
      
      const suggestions = (promptSuggestionsData[cmd] || []).slice(0, 3);
      let html = '';
      suggestions.forEach(text => {
        // Highlighting the command part
        const display = text.replace(cmd, `<strong style="color:#3b82f6">${cmd}</strong>`);
        html += `<div class="popup-item" onclick="applySuggestion('${text}')"><i class="bi bi-magic"></i> <span>${display}</span></div>`;
      });
      cmdSuggestionsPopup.innerHTML = html;
      cmdSuggestionsPopup.style.display = 'flex';
    }

    function applySuggestion(text) {
      setChatText(text + ' ');
      cmdSuggestionsPopup.style.display = 'none';
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
    
    // Hide popup when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#cmdSuggestionsPopup') && !e.target.closest('#chatInput')) {
            if (cmdSuggestionsPopup) cmdSuggestionsPopup.style.display = 'none';
        }
    });
    const sendBtn = document.getElementById('sendBtn');
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
    const welcomeHero = document.getElementById('welcomeHero');

    // Command definitions for suggestions & highlighting
    const CMD_LIST = [
      { cmd: '/erp', desc: 'Executive 360° ERP Master Overview' },
      { cmd: '/paper', desc: 'Paper Stock Priority Mode' },
      { cmd: '/plate', desc: 'Plate Priority Mode' },
      { cmd: '/stock', desc: 'Mixed Item Extra Stock Pool' },
      { cmd: '/job', desc: 'Job / Planning Priority Mode' },
      { cmd: '/planning', desc: 'Job Planning Board' },
      { cmd: '/product', desc: 'Product / Item lookup' },
      { cmd: '/client', desc: 'Client / Party lookup' },
      { cmd: '/dispatch', desc: 'Dispatch / Packing Priority Mode' },
      { cmd: '/order', desc: 'Order lookup' },
    ];
    const CMD_NAMES = CMD_LIST.map(c => c.cmd);

        function applyChipPrompt(promptText, autoSend) {
      var currentText = getChatText();
      var finalPrompt = promptText;
      
      // If prompt doesn't start with /, but existing text does, preserve it
      if (!promptText.startsWith('/') && currentText.startsWith('/')) {
        var parts = currentText.split(' ');
        var activeCmd = parts[0];
        if (CMD_NAMES.includes(activeCmd)) {
          finalPrompt = activeCmd + ' ' + promptText;
        }
      }
      
      setChatText(finalPrompt + ' ');
      processChatInput();
      
      if (autoSend && finalPrompt.trim().length > 0) {
        if (typeof sendQuery === 'function') {
            // Need small timeout to ensure DOM update
            setTimeout(() => sendQuery(), 10);
        }
      } else {
        chatInput.focus();
        if (typeof setChatCursorPos === 'function') setChatCursorPos(finalPrompt.length + 1);
      }
    }

    function getChatText() { return chatInput.innerText || chatInput.textContent || ''; }
    function setChatText(t) { chatInput.innerHTML = ''; chatInput.appendChild(document.createTextNode(t)); }
    function getChatCursorPos() {
      const sel = window.getSelection();
      if (sel.rangeCount === 0) return 0;
      const r = sel.getRangeAt(0);
      const pre = document.createRange();
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

    // Highlight slash commands & show suggestions dropup
    function processChatInput() {
      const text = getChatText();
      // Update send button
      sendBtn.disabled = text.trim().length === 0;
      charCount.textContent = text.length > 0 ? text.length + '/500' : '';

      // Show suggestions when user types "/" at start of input or after space
      const showSuggestions = text.startsWith('/') && !text.includes(' ');
      
      // Level 2 — Query Suggestions: show only after the command is completed + SPACE
      let cmdMatch = false;
      if (text.startsWith('/')) {
         const parts = text.split(' ');
         const activeCmd = parts[0];
         const inQuote = /^\/(job|plate|paper|product)\s+"/i.test(text);
         if (promptSuggestionsData[activeCmd] && text.startsWith(activeCmd + ' ') && !inQuote) {
             renderCmdSuggestions(activeCmd);
             cmdMatch = true;
         }
      }
      if (!cmdMatch) {
         cmdSuggestionsPopup.style.display = 'none';
      }
      const sug = document.getElementById('cmdSuggestions');
      // Quoted Entity Autocomplete Logic — /job, /plate, /paper, /product
      // Triggers on an UNCLOSED opening quote (odd " count) even when extra words are
      // typed between the command and the quote (e.g. `/job how many label if "blue 500`).
      const quoteCount = (text.match(/"/g) || []).length;
      const lastQuote = text.lastIndexOf('"');
      const isEntityCmd = /^\/(job|plate|paper|product)\b/i.test(text);
      const autoSug = document.getElementById('autocompleteSuggestions');
      if (isEntityCmd && lastQuote !== -1 && (quoteCount % 2) === 1) {
         const searchTerm = text.substring(lastQuote + 1); // text after the opening quote (may be empty = browse all)
         sug.classList.remove('visible'); // hide basic commands (Level 1)
         cmdSuggestionsPopup.style.display = 'none'; // hide query suggestions (Level 2)
         clearTimeout(window.plateFetchTimer);
         window.plateFetchTimer = setTimeout(() => {
           fetch('api.php?action=autocomplete&prompt=' + encodeURIComponent(searchTerm))
           .then(res => res.json())
           .then(data => {
             autoSug.innerHTML = '';
             window.autocompleteCurrentFocus = -1;
             if (data.ok && data.suggestions && data.suggestions.length > 0) {
               // Show all matching jobs (empty or typed) so the list scrolls
               data.suggestions.forEach(s => {
                 const div = document.createElement('div');
                 div.className = 'cmd-suggestion-item autocomplete-item';
                 div.setAttribute('data-autocomplete', s.name);
                 div.innerHTML = '<span class="cmd-key-badge">' + escHtml(s.name) + '</span><span class="cmd-desc">' + escHtml(s.size||'') + '</span>';
                 autoSug.appendChild(div);
               });
               autoSug.classList.add('visible');
               autoSug.style.display = 'block';
             } else {
               autoSug.classList.remove('visible');
               autoSug.style.display = 'none';
             }
           }).catch(e => console.error(e));
         }, 200);
      } else {
         autoSug.classList.remove('visible');
         autoSug.style.display = 'none';
         if (showSuggestions) {
           const partial = text.toLowerCase();
           sug.querySelectorAll('.cmd-suggestion-item').forEach(el => {
             const cmd = el.dataset.cmd;
             el.style.display = cmd.startsWith(partial) ? 'flex' : 'none';
           });
           sug.classList.add('visible');
         } else {
           sug.classList.remove('visible');
         }
      }

      // Build highlighted HTML
      let html = '';
      let cmdHighlighted = false;

      // Step 1: Check for command prefix
      for (const cmd of CMD_NAMES) {
        if (text.startsWith(cmd + ' ') || text === cmd) {
          const rest = text.substring(cmd.length);
          html = '<span class="cmd-highlight">' + cmd + '</span>' + (rest ? '<span>' + escHtml(rest) + '</span>' : '');
          cmdHighlighted = true;
          break;
        }
      }
      if (!cmdHighlighted) {
        html = escHtml(text);
      }

      // Step 2: Highlight quoted text (product/item names) in the input
      // Use single-pass regex (no do-while loop to avoid infinite re-matching)
      // Replace straight quotes &quot;...&quot; first
      html = html.replace(/&quot;(.+?)&quot;/g, function(m, inner) {
        // Check if space follows the closing quote (completed phrase)
        var idx = arguments[arguments.length - 2] + m.length;
        var nextChar = html[idx] || '';
        var doneClass = (nextChar === ' ' || nextChar === '\t' || nextChar === '\n' || nextChar === '') ? ' done' : '';
        return '<span class="cmd-quote-hl' + doneClass + '"><span class="qp">&quot;</span>' + inner + '<span class="qp">&quot;</span></span>';
      });
      // Replace curly quotes \u201C...\u201D
      html = html.replace(/[\u201C\u201D](.+?)[\u201C\u201D]/g, function(m, inner) {
        var idx = arguments[arguments.length - 2] + m.length;
        var nextChar = html[idx] || '';
        var doneClass = (nextChar === ' ' || nextChar === '\t' || nextChar === '\n' || nextChar === '') ? ' done' : '';
        return '<span class="cmd-quote-hl' + doneClass + '"><span class="qp">\u201C</span>' + inner + '<span class="qp">\u201D</span></span>';
      });

      if (html !== chatInput.innerHTML) {
        let savedPos = getChatCursorPos();
        chatInput.innerHTML = html;
        setChatCursorPos(savedPos);
      }
    }

    function escHtml(t) {
      return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function placeCursorAtEnd() {
      const sel = window.getSelection();
      const r = document.createRange();
      r.selectNodeContents(chatInput);
      r.collapse(false);
      sel.removeAllRanges();
      sel.addRange(r);
    }

    // Input event: auto-resize + command processing
    chatInput.addEventListener('input', processChatInput);

    // Focus event: show suggestions if starts with /
    chatInput.addEventListener('focus', () => {
      if (getChatText().startsWith('/')) processChatInput();
    });

    // Click on suggestion items
    document.getElementById('cmdSuggestions').addEventListener('click', (e) => {
      const item = e.target.closest('.cmd-suggestion-item');
      if (!item) return;
      const cmd = item.dataset.cmd;
      setChatText(cmd + ' ');
      document.getElementById('cmdSuggestions').classList.remove('visible');
      processChatInput();
      chatInput.focus();
      placeCursorAtEnd();
    });

    document.getElementById('autocompleteSuggestions').addEventListener('click', (e) => {
      const item = e.target.closest('.autocomplete-item');
      if (!item) return;
      const plateName = item.getAttribute('data-autocomplete');
      const val = getChatText();
      // Preserve everything before the opening quote; replace the partial typed term
      // with the chosen name + automatic closing quote + space.
      const qCount = (val.match(/"/g) || []).length;
      const qPos = val.lastIndexOf('"');
      if (qPos !== -1 && (qCount % 2) === 1) {
        setChatText(val.substring(0, qPos + 1) + plateName + '" ');
      }
      document.getElementById('autocompleteSuggestions').classList.remove('visible');
      document.getElementById('autocompleteSuggestions').style.display = 'none';
      processChatInput();
      chatInput.focus();
      placeCursorAtEnd();
    });

    // Click outside any suggestion dropdown closes it
    document.addEventListener('click', (e) => {
      if (chatInput && chatInput.contains(e.target)) return; // keep open while typing in the input
      const autoSug = document.getElementById('autocompleteSuggestions');
      const sug = document.getElementById('cmdSuggestions');
      if (autoSug && autoSug.style.display === 'block' && !autoSug.contains(e.target)) {
        autoSug.classList.remove('visible');
        autoSug.style.display = 'none';
      }
      if (sug && sug.classList.contains('visible') && !sug.contains(e.target)) {
        sug.classList.remove('visible');
      }
      if (cmdSuggestionsPopup && cmdSuggestionsPopup.style.display !== 'none' && !cmdSuggestionsPopup.contains(e.target)) {
        cmdSuggestionsPopup.style.display = 'none';
      }
    });

    // Chips toggle
    chipsToggle.addEventListener('click', () => {
      chipsSection.classList.toggle('open');
      chipsToggle.classList.toggle('active');
    });

    // Quick chips click
    document.querySelectorAll('.chip-item').forEach(chip => {
      chip.addEventListener('click', () => { applyChipPrompt(chip.getAttribute('data-prompt'), true); chipsSection.classList.remove('open'); chipsToggle.classList.remove('active'); });
    });

    // Quick action cards
    document.querySelectorAll('.quick-action-card').forEach(card => {
      card.addEventListener('click', () => { applyChipPrompt(card.getAttribute('data-prompt'), true); });
    });

    // AI Suggestion chips click (event delegation for dynamically added chips)
    chatStream.addEventListener('click', (e) => {
      const chip = e.target.closest('.ai-suggestion-chip');
      if (chip) {
        const p = chip.getAttribute('data-prompt');
        if (p) { applyChipPrompt(p, true); }
      }
    });

    // ── In-PWA full-screen report viewer (PDF print view) ──
    // PDF export links return an HTML print view. Opening it in a new tab is
    // popup-blocked on mobile (async fetch loses user gesture), so we render
    // it inside the PWA in a full-screen modal — "view report on screen".
    function openPwaReportViewer(url) {
      let ov = document.getElementById('pwaReportViewer');
      if (ov) ov.remove();
      ov = document.createElement('div');
      ov.id = 'pwaReportViewer';
      ov.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(2,6,23,.9);display:flex;flex-direction:column;';
      const bar = document.createElement('div');
      bar.style.cssText = 'display:flex;align-items:center;gap:10px;justify-content:space-between;padding:10px 14px;background:#0f172a;color:#fff;flex:0 0 auto;';
      const title = document.createElement('span');
      title.textContent = '📄 Report — Print / Save as PDF';
      title.style.cssText = 'font-size:14px;font-weight:600;';
      const btns = document.createElement('div');
      btns.style.cssText = 'display:flex;gap:8px;';
      const printBtn = document.createElement('button');
      printBtn.textContent = '🖨 Print';
      printBtn.style.cssText = 'background:#2563eb;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:14px;cursor:pointer;';
      const closeBtn = document.createElement('button');
      closeBtn.textContent = '✕ Close';
      closeBtn.style.cssText = 'background:#ef4444;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:14px;cursor:pointer;';
      btns.appendChild(printBtn);
      btns.appendChild(closeBtn);
      bar.appendChild(title);
      bar.appendChild(btns);
      const wrap = document.createElement('div');
      wrap.style.cssText = 'flex:1 1 auto;min-height:0;';
      const frame = document.createElement('iframe');
      frame.style.cssText = 'width:100%;height:100%;border:none;background:#fff;';
      frame.src = url;
      wrap.appendChild(frame);
      ov.appendChild(bar);
      ov.appendChild(wrap);
      document.body.appendChild(ov);
      printBtn.addEventListener('click', () => { try { frame.contentWindow.print(); } catch (e) {} });
      closeBtn.addEventListener('click', () => ov.remove());
    }

    // ── PWA: File/Export links must NEVER navigate the PWA into the ERP ──
    // Clicking a PDF / Excel / CSV / export link downloads the file directly
    // inside the PWA via fetch → blob (or shows the PDF print view in-app).
    // The PWA window itself never leaves the chat (no dashboard/login redirects).
    chatStream.addEventListener('click', (e) => {
      const a = e.target.closest('a');
      if (!a || !a.href) return;
      const h = a.href;
      const isFileLink = /export\.php|\.pdf($|[?#])|\.csv($|[?#])|\.xlsx?($|[?#])|format=(pdf|csv|excel)/i.test(h);
      if (!isFileLink) return;

      e.preventDefault();
      e.stopPropagation();

      const isPdfLink = /format=pdf/i.test(h);

      fetch(h, { credentials: 'include' })
        .then((r) => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          const ct = (r.headers.get('Content-Type') || '').toLowerCase();
          // If the server answered with an HTML page: for PDF export links this
          // is the print view → render it in-app. For everything else it is an
          // auth/redirect page → fall through to the catch handler (never save
          // an HTML page as a file).
          if (ct.indexOf('text/html') !== -1) {
            if (isPdfLink) {
              openPwaReportViewer(h);
              return null;
            }
            throw new Error('html-redirect');
          }
          return r.blob();
        })
        .then((blob) => {
          if (!blob) return;
          let ext = /format=pdf/i.test(h) ? 'pdf'
                 : (/format=csv|\.csv/i.test(h) ? 'csv'
                 : (/\.xlsx/i.test(h) ? 'xlsx' : 'bin'));
          const url = URL.createObjectURL(blob);
          const dl = document.createElement('a');
          dl.href = url;
          dl.download = 'report-' + new Date().toISOString().slice(0, 10) + '.' + ext;
          document.body.appendChild(dl);
          dl.click();
          dl.remove();
          setTimeout(() => URL.revokeObjectURL(url), 4000);
        })
        .catch(() => {
          // Never navigate the PWA itself — open in a separate browser tab instead.
          window.open(h, '_blank', 'noopener,noreferrer');
        });
    });

    // Scroll to bottom
    sendBtn.addEventListener('click', sendQuery);
    chatInput.addEventListener('keydown', (e) => {
      const autoSug = document.getElementById('autocompleteSuggestions');
      if (autoSug && autoSug.style.display === 'block') {
        const items = autoSug.querySelectorAll('.autocomplete-item');
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          window.autocompleteCurrentFocus++;
          addActivePwa(items);
          return;
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          window.autocompleteCurrentFocus--;
          addActivePwa(items);
          return;
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (window.autocompleteCurrentFocus > -1 && items[window.autocompleteCurrentFocus]) {
            items[window.autocompleteCurrentFocus].click();
          } else {
            sendQuery();
          }
          return;
        } else if (e.key === 'Escape') {
          e.preventDefault();
          autoSug.classList.remove('visible');
          autoSug.style.display = 'none';
          window.autocompleteCurrentFocus = -1;
          if (cmdSuggestionsPopup) cmdSuggestionsPopup.style.display = 'none';
          const sugEl = document.getElementById('cmdSuggestions');
          if (sugEl) sugEl.classList.remove('visible');
          return;
        }
      }
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuery(); }
    });

    function addActivePwa(items) {
      if (!items || !items.length) return false;
      removeActivePwa(items);
      if (window.autocompleteCurrentFocus >= items.length) window.autocompleteCurrentFocus = 0;
      if (window.autocompleteCurrentFocus < 0) window.autocompleteCurrentFocus = items.length - 1;
      items[window.autocompleteCurrentFocus].classList.add('active');
      items[window.autocompleteCurrentFocus].style.background = 'rgba(59,130,246,0.15)';
    }

    function removeActivePwa(items) {
      for (let i = 0; i < items.length; i++) {
        items[i].classList.remove('active');
        items[i].style.background = 'transparent';
      }
    }

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
      const prompt = getChatText().trim();
      if (!prompt) return;

      setChatText('');
      sendBtn.disabled = true;
      document.getElementById('cmdSuggestions').classList.remove('visible');

      if (welcomeHero) welcomeHero.remove();
      appendUserMsg(prompt);
      // Move typing indicator right after the user's message
      const userGroups = chatStream.querySelectorAll('.msg-group.user');
      const lastUser = userGroups[userGroups.length - 1];
      if (lastUser) lastUser.insertAdjacentElement('afterend', typingBox);
      showTyping(true);
      if (navigator.vibrate) navigator.vibrate(15);

      try {
        const res = await fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'query', prompt: prompt })
        });
        const data = await res.json();
        showTyping(false);

        if (data.ok) {
          appendAiMsg(data.answer, data.suggestions, data.command_type);
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

    // Web Speech API Voice Recognition Controller
    const micBtn = document.getElementById('micBtn');
    const micIcon = document.getElementById('micIcon');
    const voiceStatusText = document.getElementById('voiceStatusText');

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    let recognition = null;
    let isListening = false;
    let initialVoiceText = '';

    if (SpeechRecognition && micBtn) {
      recognition = new SpeechRecognition();
      recognition.continuous = false;
      recognition.interimResults = true;

      recognition.onstart = () => {
        isListening = true;
        initialVoiceText = getChatText().trim();
        if (initialVoiceText && !initialVoiceText.endsWith(' ')) initialVoiceText += ' ';
        micBtn.classList.add('listening');
        if (micIcon) micIcon.className = 'bi bi-mic-fill ai-pulse';
        if (voiceStatusText) voiceStatusText.textContent = '🎙️ Listening... Speak now';
        chatInput.dataset.placeholder = 'Listening...';
        if (navigator.vibrate) navigator.vibrate(20);
      };

      recognition.onresult = (e) => {
        let transcript = '';
        for (let i = e.resultIndex; i < e.results.length; i++) {
          transcript += e.results[i][0].transcript;
        }
        setChatText(initialVoiceText + transcript);
        processChatInput();
      };

      recognition.onerror = (e) => {
        console.warn('Speech error:', e.error);
        stopListening();
        if (voiceStatusText) voiceStatusText.textContent = 'Voice error: ' + e.error;
        setTimeout(() => { if (voiceStatusText) voiceStatusText.textContent = ''; }, 3000);
      };

      recognition.onend = () => {
        stopListening();
      };

      function stopListening() {
        isListening = false;
        micBtn.classList.remove('listening');
        if (micIcon) micIcon.className = 'bi bi-mic-fill';
        if (voiceStatusText) voiceStatusText.textContent = '';
        chatInput.dataset.placeholder = 'Ask about stock, orders or speak...';
      }

      micBtn.addEventListener('click', () => {
        if (isListening) {
          recognition.stop();
        } else {
          try {
            recognition.start();
          } catch (err) {
            console.error(err);
          }
        }
      });
    } else if (micBtn) {
      micBtn.addEventListener('click', () => {
        alert('Voice input is not supported in this browser. Please use Google Chrome, Edge, or Safari.');
      });
    }

    // Save & Restore Chat History in sessionStorage
    function saveHistory() {
      try {
        sessionStorage.setItem('erp_pwa_chat_history', chatStream.innerHTML);
      } catch (e) {}
    }

    function loadHistory() {
      try {
        const saved = sessionStorage.getItem('erp_pwa_chat_history');
        if (saved && saved.trim() !== '') {
          chatStream.innerHTML = saved;
          // Ensure typing label has id (old saved data might lack it)
          const label = chatStream.querySelector('.typing-label');
          if (label && !label.id) label.id = 'typingLabel';
          if (!document.getElementById('typingBox')) {
            chatStream.appendChild(typingBox);
          }
          scrollToBottom();
        }
      } catch (e) {}
    }
    loadHistory();

    // Clear Chat — clears history and auto-refresh
    clearBtn.addEventListener('click', () => {
      if (navigator.vibrate) navigator.vibrate(20);
      try { sessionStorage.removeItem('erp_pwa_chat_history'); } catch (e) {}
      try { sessionStorage.setItem('erp_chat_cleared', '1'); } catch (e) {}
      location.reload();
    });

    function appendUserMsg(text) {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      // Just escape HTML — no quote highlighting in sent messages (input box only)
      const highlighted = escapeHtml(text);
      const html = `
        <div class="msg-group user">
          <div class="msg-row">
            <div class="msg-avatar"><i class="bi bi-person-fill"></i></div>
            <div class="msg-content">
              <div class="msg-bubble">${highlighted}</div>
              <div class="msg-footer">
                <span class="msg-meta">You · ${time}</span>
                <button class="btn-copy-msg" onclick="copyMsg(this)" title="Copy"><i class="bi bi-clipboard"></i></button>
                <button class="btn-copy-msg btn-edit-msg" onclick="editUserMsg(this)" title="Edit Prompt"><i class="bi bi-pencil-square"></i></button>
                <button class="btn-copy-msg btn-regen-msg" onclick="regenerateMsg(this)" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>
              </div>
            </div>
          </div>
        </div>`;
      chatStream.insertAdjacentHTML('beforeend', html);
      saveHistory();
      scrollToBottom();
    }

    function appendAiMsg(markdownText, suggestions, commandType) {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      marked.setOptions({ breaks: true, gfm: true });
      let parsedHtml = marked.parse(markdownText);

      // Wrap tables in responsive container for smooth mobile scrolling
      const tempDiv = document.createElement('div');
      tempDiv.innerHTML = parsedHtml;

      tempDiv.querySelectorAll('table').forEach(table => {
        if (!table.parentNode.classList.contains('table-responsive-wrapper')) {
          const wrapper = document.createElement('div');
          wrapper.className = 'table-responsive-wrapper';
          table.parentNode.insertBefore(wrapper, table);
          wrapper.appendChild(table);
        }
      });

      // Enhance code blocks with Copy buttons
      tempDiv.querySelectorAll('pre').forEach(pre => {
        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrapper';
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(pre);

        const copyBtn = document.createElement('button');
        copyBtn.className = 'btn-copy-code';
        copyBtn.type = 'button';
        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy code';
        copyBtn.onclick = function() {
          const code = pre.querySelector('code') ? pre.querySelector('code').innerText : pre.innerText;
          navigator.clipboard.writeText(code).then(() => {
            copyBtn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
            setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy code'; }, 2000);
          });
        };
        wrapper.appendChild(copyBtn);
      });
      parsedHtml = tempDiv.innerHTML;

      if (suggestions && suggestions.length > 0) {
        parsedHtml += `<div class="ai-suggestion-box">
          <div class="ai-suggestion-title"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Suggested Questions:</div>
          <div class="ai-suggestion-chips">`;
        for (const sug of suggestions) {
          parsedHtml += `<button type="button" class="ai-suggestion-chip" data-prompt="${escapeHtml(sug)}"><i class="bi bi-chat-left-text"></i> ${escapeHtml(sug)}</button>`;
        }
        parsedHtml += `</div></div>`;
      }

      const cmdClass = commandType ? ` ai-cmd-${commandType}` : '';
      const html = `
        <div class="msg-group assistant${cmdClass}">
          <div class="msg-row">
            <div class="msg-avatar"><i class="bi bi-robot"></i></div>
            <div class="msg-content">
              <div class="msg-bubble">${parsedHtml}</div>
              <div class="msg-footer">
                <span class="msg-meta">AI Copilot · ${time}</span>
                <button class="btn-copy-msg" onclick="copyMsg(this)" title="Copy"><i class="bi bi-clipboard"></i></button>
                <button class="btn-copy-msg btn-regen-msg" onclick="regenerateMsg(this)" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>
              </div>
            </div>
          </div>
        </div>`;
      chatStream.insertAdjacentHTML('beforeend', html);
      saveHistory();

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

    function editUserMsg(btn) {
      const msgContent = btn.closest('.msg-content');
      const bubble = msgContent.querySelector('.msg-bubble');
      if (msgContent.querySelector('.msg-edit-box')) return;

      const originalText = (bubble.innerText || bubble.textContent).trim();
      const originalHtml = bubble.innerHTML;

      bubble.innerHTML = `
        <div class="msg-edit-box" style="display:flex;flex-direction:column;gap:8px;width:100%;margin-top:4px;">
          <textarea class="msg-edit-input" style="width:100%;min-height:60px;background:rgba(15,23,42,0.85);border:1px solid #3b82f6;color:#fff;border-radius:8px;padding:8px 10px;font-size:14px;resize:vertical;outline:none;font-family:inherit;">${escapeHtml(originalText)}</textarea>
          <div style="display:flex;gap:6px;justify-content:flex-end;">
            <button type="button" class="btn-cancel-edit" style="background:rgba(148,163,184,0.2);border:none;color:#cbd5e1;padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer;">Cancel</button>
            <button type="button" class="btn-save-edit" style="background:#3b82f6;border:none;color:#fff;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Save & Regenerate</button>
          </div>
        </div>`;

      const textarea = bubble.querySelector('.msg-edit-input');
      textarea.focus();
      textarea.setSelectionRange(textarea.value.length, textarea.value.length);

      bubble.querySelector('.btn-cancel-edit').onclick = function() {
        bubble.innerHTML = originalHtml;
      };

      bubble.querySelector('.btn-save-edit').onclick = function() {
        const newText = textarea.value.trim();
        if (newText) {
          bubble.innerHTML = escapeHtml(newText);
          setChatText(newText);
          processChatInput();
          sendQuery();
        } else {
          bubble.innerHTML = originalHtml;
        }
      };
    }

    function regenerateMsg(btn) {
      const msgGroup = btn.closest('.msg-group');
      let promptText = '';
      if (msgGroup.classList.contains('user')) {
        const bubble = msgGroup.querySelector('.msg-bubble');
        promptText = (bubble.innerText || bubble.textContent).trim();
      } else {
        let prev = msgGroup.previousElementSibling;
        while (prev) {
          if (prev.classList.contains('msg-group') && prev.classList.contains('user')) {
            const bubble = prev.querySelector('.msg-bubble');
            promptText = (bubble.innerText || bubble.textContent).trim();
            break;
          }
          prev = prev.previousElementSibling;
        }
      }
      if (promptText) {
        setChatText(promptText);
        processChatInput();
        sendQuery();
      }
    }

    function showTyping(show) {
      typingBox.classList.toggle('visible', show);
      if (show) {
        // Cycle through animated status messages
        const statuses = ['Thinking', 'Processing', 'Searching', 'Analyzing', 'Fetching data', 'Targeting', 'Computing', 'Loading'];
        let idx = 0;
        let label = document.getElementById('typingLabel');
        // Ensure label exists (old localStorage data might lack id)
        if (!label) {
          const span = typingBox.querySelector('.typing-label');
          if (span) { span.id = 'typingLabel'; label = span; }
        }
        let dots = 0;
        if (window._typingInterval) clearInterval(window._typingInterval);
        window._typingInterval = setInterval(() => {
          if (!label) { clearInterval(window._typingInterval); return; }
          dots = (dots + 1) % 4;
          label.textContent = statuses[idx] + '.'.repeat(dots > 0 ? dots : 3);
          if (dots === 0) {
            idx = (idx + 1) % statuses.length;
          }
        }, 400);
        scrollToBottom();
      } else {
        if (window._typingInterval) {
          clearInterval(window._typingInterval);
          window._typingInterval = null;
        }
        const label = document.getElementById('typingLabel') || typingBox.querySelector('.typing-label');
        if (label) label.textContent = 'Thinking...';
      }
    }

    function scrollToBottom() {
      chatStream.scrollTo({ top: chatStream.scrollHeight, behavior: 'smooth' });
    }

    function escapeHtml(text) {
      return text.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    // Focus input on load
    setTimeout(() => chatInput.focus(), 300);
  </script>
</body>
</html>