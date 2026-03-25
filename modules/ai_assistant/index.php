<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'AI Assistant';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>AI Assistant</span>
</div>
<div class="page-header">
  <div>
    <h1>AI Assistant</h1>
    <p>General AI helper for ERP workflow guidance and task support.</p>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">AI Assistant</span></div>
  <div style="padding:40px;text-align:center;color:#6b7280">
    <i class="bi bi-robot" style="font-size:2.5rem;opacity:.3"></i>
    <p style="margin-top:12px;font-size:.9rem">This AI workspace is ready for the next implementation phase.</p>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
