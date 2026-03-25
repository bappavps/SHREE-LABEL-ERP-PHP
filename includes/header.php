<?php
// ============================================================
// Shree Label ERP — HTML <head> + topbar opener
// Usage: include __DIR__ . '/../../includes/header.php';
//        Pass $pageTitle before including.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$pageTitle = $pageTitle ?? 'Dashboard';
$userName = $_SESSION['user_name'] ?? 'User';
$companyName = $_SESSION['company_name'] ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: time() ?>">
</head>
<body>
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
        <i class="bi bi-layers"></i>
        <span><?= e(APP_NAME) ?></span>
      </div>
      <div class="topbar-sep" aria-hidden="true"></div>
      <div class="topbar-company">
        <small>Welcome <?= e($userName) ?></small>
        <strong><?= e($companyName) ?></strong>
      </div>
    </div>

    <div class="topbar-right">
      <span id="topbarDateTime" class="topbar-datetime" aria-live="polite"></span>
      <span class="topbar-flag-wrap" aria-hidden="true"><span class="topbar-flag">🇮🇳</span></span>
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
