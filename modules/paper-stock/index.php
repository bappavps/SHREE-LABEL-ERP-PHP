<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Paper Stock';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Paper Stock</span>
</div>

<div class="page-header">
  <div>
    <h1>Paper Stock</h1>
    <p>This module is under development.</p>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Paper Stock</span>
  </div>
  <div style="padding:40px;text-align:center;color:#6b7280">
    <i class="bi bi-tools" style="font-size:2.5rem;opacity:.3"></i>
    <p style="margin-top:12px;font-size:.9rem">This page will be implemented in the next phase.</p>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
