<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$dcPageTitleOperator = 'POS Operator';
$dcPageTitleProduction = 'POS Job Cards';
$dcOperatorBreadcrumb = 'POS Operator';
$dcProductionBreadcrumb = 'POS';
$dcHeaderIcon = 'bi-grid-1x2';
$dcHeaderSubtitle = 'Auto-generated POS cards from paper roll slitting &middot; Department-gated sequential queue';
$dcDocumentTitle = 'POS Job Card';
$dcBulkPrintTitle = 'POS Job Cards';
$dcDetailsSectionLabel = 'POS Details';
$dcCompareSectionTitle = 'POS Production vs Plan';
$dcProducedQtyLabel = 'POS Production';
$dcProducedQtySource = 'current';
$dcShowWeightHeightFields = true;
$dcWeightLabel = 'Width';
$dcHeightLabel = 'Height';
$dcPaperWidthLabel = 'Paper Width (mm)';
$dcBrand = '#0f4c81';
$dcBrandLight = '#dbeafe';
$dcBrandDark = '#1e3a8a';
$dcShowPaperCompanyInDetails = false;
$dcDefaultFilter = 'Pending';
$dcAutoFallbackToAllOnEmptyDefault = false;
$dcEnableBulkSelection = false;
$dcShowParentChildRollTables = true;
$sessionRoleRaw = strtolower(trim((string)($_SESSION['role'] ?? '')));
$isPosPrivilegedRole = in_array($sessionRoleRaw, ['admin', 'system_admin', 'system admin', 'system-admin', 'super_admin', 'super admin', 'super-admin', 'manager'], true);
$dcCanManualRollEntry = hasPageAction('/modules/jobs/pos/index.php', 'edit')
    || hasPageAction('/modules/operators/pos/index.php', 'edit')
    || hasRole('manager', 'system_admin', 'super_admin')
    || $isPosPrivilegedRole
    || isAdmin();
$dcRequireRollScan = true;
$dcWhereClauseOverride = "(
    LOWER(COALESCE(j.department, '')) IN ('pos')
    OR LOWER(COALESCE(j.job_type, '')) IN ('pos')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) = 'pos')
)";

require __DIR__ . '/../flatbed/index.php';
