<?php
$dcPageTitleOperator = 'Barcode Operator';
$dcPageTitleProduction = 'Barcode Job Cards';
$dcOperatorBreadcrumb = 'Barcode Operator';
$dcProductionBreadcrumb = 'Barcode';
$dcHeaderIcon = 'bi-upc-scan';
$dcHeaderSubtitle = 'Auto-generated for barcode from slitting planning flow &middot; Department-gated sequential queue';
$dcDocumentTitle = 'Barcode Job Card';
$dcBulkPrintTitle = 'Barcode Job Cards';
$dcDetailsSectionLabel = 'Barcode Details';
$dcCompareSectionTitle = 'Barcode Production vs Plan';
$dcProducedQtyLabel = 'Barcode Production';
$dcProducedQtySource = 'current';
$dcShowWeightHeightFields = true;
$dcWeightLabel = 'Width';
$dcHeightLabel = 'Height';
$dcPaperWidthLabel = 'Paper Width (mm)';
$dcDefaultFilter = 'Pending';
$dcWhereClauseOverride = "(
    LOWER(COALESCE(j.department, '')) IN ('barcode')
    OR LOWER(COALESCE(j.job_type, '')) IN ('barcode')
    OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) = 'barcode')
)";

require __DIR__ . '/../flatbed/index.php';
