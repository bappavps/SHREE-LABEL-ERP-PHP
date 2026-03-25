<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Stock Prediction';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Stock Prediction</span>
</div>
<div class="page-header">
  <div>
    <h1>Stock Prediction</h1>
    <p>AI support for stock usage and material planning insight.</p>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Stock Prediction</span></div>
  <div style="padding:40px;text-align:center;color:#6b7280">
    <i class="bi bi-graph-up-arrow" style="font-size:2.5rem;opacity:.3"></i>
    <p style="margin-top:12px;font-size:.9rem">This stock prediction workspace is ready for the next implementation phase.</p>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
