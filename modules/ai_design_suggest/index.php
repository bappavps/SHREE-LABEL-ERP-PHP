<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'AI Design Suggest';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>AI Design Suggest</span>
</div>
<div class="page-header">
  <div>
    <h1>AI Design Suggest Module Update</h1>
    <p>This module has been integrated into the central AI Agent.</p>
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Module Deprecated</span></div>
  <div style="padding:40px;text-align:center;color:#6b7280">
    <i class="bi bi-info-circle-fill" style="font-size:2.5rem;color:#007bff;"></i>
    <h3 style="margin-top:1rem;">This Module Has Been Merged!</h3>
    <p style="margin-top:12px;font-size:1rem;max-width:600px;margin-left:auto;margin-right:auto;">
      The <strong>AI Design Suggest</strong> functionality has been upgraded and merged into the new, more powerful
      <strong>AI Agent</strong> module.
      All AI capabilities are now centralized for a better experience.
    </p>
    <a href="<?= BASE_URL ?>/modules/ai_agent/index.php" class="btn btn-primary" style="margin-top:20px;">
      <i class="bi bi-robot"></i> Go to AI Agent Module
    </a>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>