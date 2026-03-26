<?php
// ============================================================
// ERP System — HTML <head> + topbar opener
// Usage: include __DIR__ . '/../../includes/header.php';
//        Pass $pageTitle before including.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$pageTitle = $pageTitle ?? 'Dashboard';
$appSettings = function_exists('getAppSettings') ? getAppSettings() : [];
$userName = $_SESSION['user_name'] ?? 'User';
$companyName = trim((string)($appSettings['company_name'] ?? '')) ?: APP_NAME;
$erpDisplayName = function_exists('getErpDisplayName') ? getErpDisplayName($companyName) : APP_NAME;
$companyTagline = trim((string)($appSettings['company_tagline'] ?? '')) ?: 'ERP Master System';
$logoPath = (string)($appSettings['logo_path'] ?? '');
$animatedFlagPath = (string)($appSettings['animated_flag_path'] ?? '');
$animatedFlagUrl = trim((string)($appSettings['animated_flag_url'] ?? ''));
$flagEmoji = trim((string)($appSettings['flag_emoji'] ?? '🇮🇳')) ?: '🇮🇳';
$themeMode = ($appSettings['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
$sidebarButtonColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');
$sidebarHoverColor = (string)($appSettings['sidebar_hover_color'] ?? 'rgba(255,255,255,.09)');
$sidebarActiveBg = (string)($appSettings['sidebar_active_bg'] ?? 'rgba(34,197,94,.12)');
$sidebarActiveTxt = (string)($appSettings['sidebar_active_text'] ?? '#bbf7d0');
$animatedFlagSrc = '';
if ($animatedFlagUrl !== '' && filter_var($animatedFlagUrl, FILTER_VALIDATE_URL)) {
  $animatedFlagSrc = $animatedFlagUrl;
} elseif ($animatedFlagPath !== '') {
  $animatedFlagSrc = BASE_URL . '/' . ltrim($animatedFlagPath, '/');
}
$companyLogoUrl = $logoPath !== '' ? (BASE_URL . '/' . ltrim($logoPath, '/')) : (BASE_URL . '/assets/img/logo.svg');
$themeColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="icon" href="<?= e($companyLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($companyLogoUrl) ?>">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.php">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<style>
:root {
  --brand: <?= e($sidebarButtonColor) ?>;
  --nav-hover: <?= e($sidebarHoverColor) ?>;
  --nav-active-bg: <?= e($sidebarActiveBg) ?>;
  --nav-active-txt: <?= e($sidebarActiveTxt) ?>;
}
</style>
</head>
<body class="theme-<?= e($themeMode) ?>">
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-wrapper">
  <div class="app-top-strip" aria-hidden="true"><span class="app-top-dot"></span></div>

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <button id="sidebarToggle" class="btn btn-ghost btn-sm" aria-label="Menu">
        <i class="bi bi-list" style="font-size:1.2rem"></i>
      </button>
      <div class="topbar-brand">
        <?php if ($logoPath !== ''): ?>
          <img src="<?= e(BASE_URL . '/' . ltrim($logoPath, '/')) ?>" alt="Logo" class="topbar-brand-logo">
        <?php else: ?>
          <i class="bi bi-layers"></i>
        <?php endif; ?>
        <span><?= e($erpDisplayName) ?></span>
      </div>
      <div class="topbar-sep" aria-hidden="true"></div>
      <div class="topbar-company">
        <small>Welcome <?= e($userName) ?></small>
        <strong><?= e($companyTagline) ?></strong>
      </div>
    </div>

    <div class="topbar-right">
      <span id="topbarDateTime" class="topbar-datetime" aria-live="polite"></span>
      <span class="topbar-flag-wrap" aria-hidden="true">
        <?php if ($animatedFlagSrc !== ''): ?>
          <img src="<?= e($animatedFlagSrc) ?>" alt="Flag" class="topbar-flag-image">
        <?php else: ?>
          <span class="topbar-flag"><?= e($flagEmoji) ?></span>
        <?php endif; ?>
      </span>
      <button type="button" id="topbarNotificationBtn" class="topbar-icon-btn topbar-notification-btn" data-href="<?= BASE_URL ?>/modules/approval/index.php" aria-label="Notifications">
        <i class="bi bi-bell"></i><span class="topbar-notification-dot"></span>
      </button>
      <button type="button" id="topbarFullscreenBtn" class="topbar-icon-btn" aria-label="Expand"><i class="bi bi-arrows-angle-expand"></i></button>
      <button type="button" id="topbarProfileBtn" class="topbar-icon-btn" data-href="<?= BASE_URL ?>/modules/users/index.php" aria-label="Profile"><i class="bi bi-person"></i></button>
      <button type="button" id="topbarPowerBtn" class="topbar-icon-btn" data-href="<?= BASE_URL ?>/auth/logout.php" aria-label="Power"><i class="bi bi-power"></i></button>
    </div>
  </header>

  <!-- Flash Message -->
  <?php
  $flash = getFlash();
  if ($flash):
    $type = in_array($flash['type'], ['success','error','warning','info']) ? $flash['type'] : 'info';
  ?>
  <div style="padding:0 24px;padding-top:16px">
    <div class="alert alert-<?= $type ?>" role="alert">
      <span><?= e($flash['message']) ?></span>
      <button class="alert-close" type="button">&times;</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Page Content -->
  <main class="page-content">
