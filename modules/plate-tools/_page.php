<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitleText = $pageTitleText ?? 'Plate Data & Tools';
$pageSubtitleText = $pageSubtitleText ?? 'Scalable module shell for future workflow and database integration.';
$pageCodeText = $pageCodeText ?? 'PT-000';

$pageTitle = $pageTitleText;
include __DIR__ . '/../../includes/header.php';
?>

<style>
.pt-shell{display:grid;grid-template-columns:2fr 1fr;gap:14px}
.pt-card{border:1px solid var(--border);border-radius:12px;background:#fff;padding:16px}
.pt-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}
.pt-title{font-size:1rem;font-weight:800;color:#0f172a}
.pt-chip{display:inline-flex;align-items:center;height:24px;padding:0 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.7rem;font-weight:700;letter-spacing:.04em}
.pt-sub{font-size:.86rem;color:#64748b;line-height:1.6}
.pt-list{margin:10px 0 0;padding:0;list-style:none;display:grid;gap:8px}
.pt-list li{font-size:.84rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:9px 10px}
.pt-note{border-left:4px solid #0f172a;background:#f8fafc;padding:10px 12px;border-radius:8px;font-size:.82rem;color:#475569}
@media (max-width:900px){.pt-shell{grid-template-columns:1fr}}
</style>

<div class="card">
  <div class="card-header"><span class="card-title"><?= e($pageTitleText) ?></span></div>
  <div class="pt-shell">
    <section class="pt-card">
      <div class="pt-head">
        <div class="pt-title">Module Workspace</div>
        <span class="pt-chip"><?= e($pageCodeText) ?></span>
      </div>
      <div class="pt-sub"><?= e($pageSubtitleText) ?></div>
      <ul class="pt-list">
        <li>Independent page endpoint ready for feature implementation.</li>
        <li>ERP-safe naming and navigation path finalized.</li>
        <li>UI shell is intentionally clean and lightweight.</li>
      </ul>
    </section>

    <aside class="pt-card">
      <div class="pt-title" style="margin-bottom:8px;">Status</div>
      <div class="pt-note">Blank module page created. Connect forms, tables, and APIs in next phase without changing sidebar structure.</div>
    </aside>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
