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
$erpLogoPath = (string)($appSettings['erp_logo_path'] ?? '');
$animatedFlagPath = (string)($appSettings['animated_flag_path'] ?? '');
$animatedFlagUrl = trim((string)($appSettings['animated_flag_url'] ?? ''));
$flagEmoji = trim((string)($appSettings['flag_emoji'] ?? '🇮🇳')) ?: '🇮🇳';
$themeMode = ($appSettings['theme_mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
$sidebarButtonColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');
$sidebarHoverColor = (string)($appSettings['sidebar_hover_color'] ?? 'rgba(255,255,255,.09)');
$sidebarActiveBg = (string)($appSettings['sidebar_active_bg'] ?? 'rgba(34,197,94,.12)');
$sidebarActiveTxt = (string)($appSettings['sidebar_active_text'] ?? '#bbf7d0');
$sidebarCollapseDelayMs = (int)($appSettings['sidebar_collapse_delay_ms'] ?? 1000);
$animatedFlagSrc = '';
if ($animatedFlagUrl !== '' && filter_var($animatedFlagUrl, FILTER_VALIDATE_URL)) {
  $animatedFlagSrc = $animatedFlagUrl;
} elseif ($animatedFlagPath !== '') {
  $animatedFlagSrc = appUrl($animatedFlagPath);
}
$uiLogoPath = $erpLogoPath !== '' ? $erpLogoPath : $logoPath;
$companyLogoUrl = $uiLogoPath !== '' ? appUrl($uiLogoPath) : appUrl('assets/img/logo.svg');
$themeColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');
$csrfToken = function_exists('generateCSRF') ? generateCSRF() : '';
$currentPath = function_exists('rbacCurrentPath') ? rbacCurrentPath() : (string)($_SERVER['PHP_SELF'] ?? '');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$notificationDepartments = [];
if (strpos($currentPath, '/modules/operators/jumbo/') === 0 || strpos($currentPath, '/modules/jobs/jumbo/') === 0) {
  $notificationDepartments[] = 'jumbo_slitting';
}
if (strpos($currentPath, '/modules/operators/printing/') === 0 || strpos($currentPath, '/modules/jobs/printing/') === 0) {
  $notificationDepartments[] = 'flexo_printing';
}
if (strpos($currentPath, '/modules/operators/flatbed/') === 0 || strpos($currentPath, '/modules/jobs/flatbed/') === 0) {
  $notificationDepartments[] = 'flatbed';
}
if (strpos($currentPath, '/modules/operators/rotery/') === 0 || strpos($currentPath, '/modules/jobs/rotery/') === 0) {
  $notificationDepartments[] = 'rotery';
}
if (strpos($currentPath, '/modules/operators/barcode/') === 0 || strpos($currentPath, '/modules/jobs/barcode/') === 0) {
  $notificationDepartments[] = 'barcode';
}
if (strpos($currentPath, '/modules/operators/label-slitting/') === 0 || strpos($currentPath, '/modules/jobs/label-slitting/') === 0) {
  $notificationDepartments[] = 'label_slitting';
}
if (strpos($currentPath, '/modules/operators/packing/') === 0 || strpos($currentPath, '/modules/jobs/packing/') === 0) {
  $notificationDepartments[] = 'packing';
}
if (strpos($currentPath, '/modules/planning/') === 0) {
  $notificationDepartments[] = 'planning';
}
if ($currentUserId > 0) {
  $canRequisitionUser = !function_exists('canAccessPath') || canAccessPath('/modules/requisition-management/index.php');
  if ($canRequisitionUser) {
    $notificationDepartments[] = erpNotificationUserChannel('requisition', $currentUserId);
  }

  $canRequisitionAdmin = !function_exists('canAccessPath') || canAccessPath('/modules/requisition-management/admin.php');
  if ($canRequisitionAdmin) {
    $notificationDepartments[] = erpNotificationAdminChannel('requisition');
  }

  $canLeaveUser = !function_exists('canAccessPath') || canAccessPath('/modules/leave-management/index.php');
  if ($canLeaveUser) {
    $notificationDepartments[] = erpNotificationUserChannel('leave', $currentUserId);
  }

  $canLeaveAdmin = !function_exists('canAccessPath') || canAccessPath('/modules/leave-management/admin.php');
  if ($canLeaveAdmin) {
    $notificationDepartments[] = erpNotificationAdminChannel('leave');
  }
}
$notificationDepartments = array_values(array_unique($notificationDepartments));
$notificationDeptCsv = implode(',', $notificationDepartments);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e($erpDisplayName) ?></title>
<meta name="csrf-token" content="<?= e($csrfToken) ?>">
<link rel="icon" href="<?= e($companyLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($companyLogoUrl) ?>">
<link rel="manifest" href="<?= e(appUrl('manifest.php')) ?>">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(appUrl('assets/css/style.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
<style>
:root {
  --brand: <?= e($sidebarButtonColor) ?>;
  --nav-hover: <?= e($sidebarHoverColor) ?>;
  --nav-active-bg: <?= e($sidebarActiveBg) ?>;
  --nav-active-txt: <?= e($sidebarActiveTxt) ?>;
}

/* Fallback: keep bell dropdown usable even when cached/older style.css is served */
.topbar-right { position: relative; }
#topbarNotificationPanel {
  position: absolute;
  top: 34px;
  right: 54px;
  width: 340px;
  max-height: 420px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
  z-index: 1200;
  overflow: hidden;
}
#topbarNotificationPanel .np-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-bottom: 1px solid #eef2f7;
  background: #f8fafc;
}
#topbarNotificationPanel .np-head strong {
  font-size: .78rem;
  letter-spacing: .03em;
  text-transform: uppercase;
  color: #334155;
}
#topbarNotificationPanel .np-markall {
  border: none;
  background: transparent;
  color: #2563eb;
  font-size: .72rem;
  font-weight: 700;
  cursor: pointer;
}
#topbarNotificationPanel .np-list { max-height: 360px; overflow-y: auto; }
#topbarNotificationPanel .np-item {
  border-bottom: 1px solid #f1f5f9;
  padding: 10px 12px;
  cursor: pointer;
}
#topbarNotificationPanel .np-item:hover { background: #f8fafc; }
#topbarNotificationPanel .np-item-title {
  font-size: .76rem;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 4px;
}
#topbarNotificationPanel .np-item-msg {
  font-size: .74rem;
  color: #475569;
  line-height: 1.35;
}
#topbarNotificationPanel .np-item-time {
  margin-top: 6px;
  font-size: .66rem;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: .04em;
}
#topbarNotificationPanel .np-empty {
  padding: 18px 12px;
  text-align: center;
  color: #94a3b8;
  font-size: .74rem;
  font-weight: 600;
}
@media (max-width: 900px) {
  #topbarNotificationPanel {
    right: 8px;
    left: 8px;
    width: auto;
    top: 40px;
  }
}
</style>
</head>
<body class="theme-<?= e($themeMode) ?>">
<div class="app-shell sidebar-collapsed" data-sidebar-collapse-delay-ms="<?= $sidebarCollapseDelayMs ?>">
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
          <img src="<?= e(appUrl($logoPath)) ?>" alt="Logo" class="topbar-brand-logo">
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
      <button type="button" id="topbarNotificationBtn" class="topbar-icon-btn topbar-notification-btn" data-href="<?= BASE_URL ?>/modules/approval/index.php" data-notif-api="<?= BASE_URL ?>/modules/jobs/api.php" data-notif-departments="<?= e($notificationDeptCsv) ?>" aria-label="Notifications">
        <i class="bi bi-bell"></i><span id="topbarNotificationDot" class="topbar-notification-dot" style="display:none"></span>
      </button>
      <div id="topbarNotificationPanel" class="topbar-notification-panel" style="display:none">
        <div class="np-head">
          <strong>Notifications</strong>
          <button type="button" id="topbarNotifMarkAll" class="np-markall">Mark all read</button>
        </div>
        <div id="topbarNotificationList" class="np-list">
          <div class="np-empty">No notifications</div>
        </div>
      </div>
      <button type="button" id="topbarMinimizeBtn" class="topbar-icon-btn" aria-label="Minimize ERP" title="Minimize ERP"><i class="bi bi-dash-circle"></i></button>
      <button type="button" id="topbarFullscreenBtn" class="topbar-icon-btn" aria-label="Expand"><i class="bi bi-arrows-angle-expand"></i></button>
      <button type="button" id="topbarProfileBtn" class="topbar-icon-btn" data-href="<?= BASE_URL ?>/modules/users/index.php" aria-label="Profile"><i class="bi bi-person"></i></button>
      <button type="button" id="topbarPowerBtn" class="topbar-icon-btn" data-href="<?= BASE_URL ?>/auth/logout.php" aria-label="Power"><i class="bi bi-power"></i></button>
    </div>
  </header>

  <!-- Flash Message -->
  <?php
  $headerFlash = getFlash();
  if ($headerFlash):
    $type = in_array($headerFlash['type'], ['success','error','warning','info']) ? $headerFlash['type'] : 'info';
  ?>
  <div style="padding:0 24px;padding-top:16px">
    <div class="alert alert-<?= $type ?>" role="alert">
      <span><?= e($headerFlash['message']) ?></span>
      <button class="alert-close" type="button">&times;</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Page Content -->
  <main class="page-content">
