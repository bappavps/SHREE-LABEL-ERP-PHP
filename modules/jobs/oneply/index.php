<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$dcPageTitleOperator = 'One Ply Operator';
$dcPageTitleProduction = 'One Ply Job Cards';
$dcOperatorBreadcrumb = 'One Ply Operator';
$dcProductionBreadcrumb = 'One Ply';
$dcHeaderIcon = 'bi-layers';
$dcHeaderSubtitle = 'Auto-generated One Ply cards from paper roll slitting · Department-gated sequential queue';
$dcDocumentTitle = 'One Ply Job Card';
$dcBulkPrintTitle = 'One Ply Job Cards';
$dcDetailsSectionLabel = 'One Ply Details';
$dcCompareSectionTitle = 'One Ply Production vs Plan';
$dcProducedQtyLabel = 'One Ply Production';
$dcProducedQtySource = 'current';
$dcShowWeightHeightFields = true;
$dcWeightLabel = 'Width';
$dcHeightLabel = 'Height';
$dcPaperWidthLabel = 'Paper Width (mm)';
$dcBrand = '#166534';
$dcBrandLight = '#dcfce7';
$dcBrandDark = '#14532d';
$dcShowPaperCompanyInDetails = false;
$dcDefaultFilter = 'Pending';
$dcAutoFallbackToAllOnEmptyDefault = false;
$dcEnableBulkSelection = false;
$dcShowParentChildRollTables = true;
$sessionRoleRaw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isOnePlyPrivilegedRole = in_array($sessionRoleRaw, ['admin', 'system_admin', 'system admin', 'system-admin', 'super_admin', 'super admin', 'super-admin', 'manager'], true);
$dcCanManualRollEntry = hasPageAction('/modules/jobs/oneply/index.php', 'edit')
    || hasPageAction('/modules/operators/oneply/index.php', 'edit')
    || hasRole('manager', 'system_admin', 'super_admin')
    || $isOnePlyPrivilegedRole
    || isAdmin();
$dcRequireRollScan = true;
$dcWhereClauseOverride = "(
    LOWER(COALESCE(j.department, '')) IN ('oneply', 'one_ply', 'one-ply', '1-ply', '1ply')
    OR LOWER(COALESCE(j.job_type, '')) IN ('oneply', 'one_ply', 'one-ply', '1-ply', '1ply')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) IN ('oneply', 'one_ply', 'one-ply', '1-ply', '1ply'))
)";

require __DIR__ . '/../flatbed/index.php';
