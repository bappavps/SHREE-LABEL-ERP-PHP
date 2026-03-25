<?php
// ============================================================
// Shree Label ERP — HTML <head> + topbar opener
// Usage: include __DIR__ . '/../../includes/header.php';
//        Pass $pageTitle before including.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$pageTitle = $pageTitle ?? 'Dashboard';
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
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-wrapper">
  <!-- Topbar -->
  <header class="topbar">
    <div class="d-flex align-center gap-8">
      <button id="sidebarToggle" class="btn btn-ghost btn-sm" style="display:none;padding:4px 8px" aria-label="Menu">
        <i class="bi bi-list" style="font-size:1.2rem"></i>
      </button>
      <h2><?= e($pageTitle) ?></h2>
    </div>
    <div class="topbar-user">
      <i class="bi bi-person-circle"></i>
      <span><?= e($_SESSION['user_name'] ?? 'User') ?></span>
      <small><?= e($_SESSION['role'] ?? '') ?></small>
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
