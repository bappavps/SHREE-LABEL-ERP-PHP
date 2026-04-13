<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$dcPageTitleOperator = 'Two Ply Operator';
$dcPageTitleProduction = 'Two Ply Job Cards';
$dcOperatorBreadcrumb = 'Two Ply Operator';
$dcProductionBreadcrumb = 'Two Ply';
$dcHeaderIcon = 'bi-layers-half';
$dcHeaderSubtitle = 'Auto-generated Two Ply cards from paper roll slitting · Department-gated sequential queue';
$dcDocumentTitle = 'Two Ply Job Card';
$dcBulkPrintTitle = 'Two Ply Job Cards';
$dcDetailsSectionLabel = 'Two Ply Details';
$dcCompareSectionTitle = 'Two Ply Production vs Plan';
$dcProducedQtyLabel = 'Two Ply Production';
$dcProducedQtySource = 'current';
$dcShowWeightHeightFields = true;
$dcWeightLabel = 'Width';
$dcHeightLabel = 'Height';
$dcPaperWidthLabel = 'Paper Width (mm)';
$dcBrand = '#0f766e';
$dcBrandLight = '#ccfbf1';
$dcBrandDark = '#115e59';
$dcShowPaperCompanyInDetails = false;
$dcDefaultFilter = 'Pending';
$dcAutoFallbackToAllOnEmptyDefault = false;
$dcEnableBulkSelection = false;
$dcShowParentChildRollTables = true;
$sessionRoleRaw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isTwoPlyPrivilegedRole = in_array($sessionRoleRaw, ['admin', 'system_admin', 'system admin', 'system-admin', 'super_admin', 'super admin', 'super-admin', 'manager'], true);
$dcCanManualRollEntry = hasPageAction('/modules/jobs/twoply/index.php', 'edit')
    || hasPageAction('/modules/operators/twoply/index.php', 'edit')
    || hasRole('manager', 'system_admin', 'super_admin')
    || $isTwoPlyPrivilegedRole
    || isAdmin();
$dcRequireRollScan = true;
$dcWhereClauseOverride = "(
    LOWER(COALESCE(j.department, '')) IN ('twoply', 'two_ply', 'two-ply', '2-ply', '2ply')
    OR LOWER(COALESCE(j.job_type, '')) IN ('twoply', 'two_ply', 'two-ply', '2-ply', '2ply')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) IN ('twoply', 'two_ply', 'two-ply', '2-ply', '2ply'))
)";

require __DIR__ . '/../flatbed/index.php';
