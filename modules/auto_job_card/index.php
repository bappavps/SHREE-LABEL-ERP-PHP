<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Auto Job Card';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Auto Job Card</span>
</div>
<div class="page-header">
  <div>
    <h1>Auto Job Card</h1>
    <p>AI-assisted job card creation for planning workflow.</p>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Auto Job Card</span></div>
  <div style="padding:40px;text-align:center;color:#6b7280">
    <i class="bi bi-clipboard2-data" style="font-size:2.5rem;opacity:.3"></i>
    <p style="margin-top:12px;font-size:.9rem">This auto job card workspace is ready for the next implementation phase.</p>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
