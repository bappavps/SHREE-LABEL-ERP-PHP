<?php

/**
 * Resolve current status rendering context.
 * Audience determines whether admin/production-only display transforms apply.
 */
function erp_status_context(array $context = []): array {
    if (!empty($context['audience'])) {
        return $context;
    }

    $path = '';
    if (isset($context['path'])) {
        $path = (string)$context['path'];
    } else {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = strtolower((string)parse_url($uri, PHP_URL_PATH));
    }

    $role = '';
    if (isset($context['role'])) {
        $role = strtolower(trim((string)$context['role']));
    } else {
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    }

    $view = strtolower(trim((string)($_GET['view'] ?? '')));
    $isOperatorPath = ($path !== '' && strpos($path, '/modules/operators/') === 0);
    $isOperatorView = ($view === 'operator') || $isOperatorPath || ($role === 'operator');
    $isPrivileged = in_array($role, ['admin', 'manager', 'system_admin', 'super_admin'], true);
    $isLiveFloor = ($path !== '' && strpos($path, '/modules/live/') === 0);

    $audience = 'default';
    if ($isOperatorView) {
        $audience = 'operator';
    } elseif ($isPrivileged || $isLiveFloor) {
        $audience = 'admin-production';
    }

    return [
        'path' => $path,
        'role' => $role,
        'audience' => $audience,
        'is_operator_view' => $isOperatorView,
        'is_live_floor' => $isLiveFloor,
    ];
}

/**
 * Convert raw status to audience-specific display label.
 */
function erp_status_display_label($status, array $context = []): string {
    $raw = trim((string)$status);
    if ($raw === '') {
        return '';
    }

    $ctx = erp_status_context($context);
    if ($ctx['audience'] !== 'operator' && strcasecmp($raw, 'Slitting') === 0) {
        return 'Preparing Slitting';
    }

    return $raw;
}

/**
 * Resolve badge class key for a status label.
 */
function erp_status_badge_class($status, array $context = []): string {
    $raw = trim((string)$status);
    $display = erp_status_display_label($raw, $context);

    $map = [
        'Main' => 'main',
        'Stock' => 'stock',
        'Slitting' => 'slitting-active',
        'Preparing Slitting' => 'preparing-slitting',
        'Slitted' => 'slitted',
        'Slitting Pause' => 'slitting-pause',
        'Jumbo Slitting' => 'jumbo-slitting-active',
        'Preparing Jumbo Slitting' => 'jumbo-slitting-preparing',
        'Jumbo Slitted' => 'jumbo-slitted',
        'Job Assign' => 'job-assign',
        'In Production' => 'in-production',
        'Available' => 'available',
        'Assigned' => 'assigned',
        'Consumed' => 'consumed',
        'Consume' => 'consumed',

        'Draft' => 'draft',
        'Sent' => 'sent',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Converted' => 'converted',

        'Pending' => 'pending',
        'Queued' => 'queued',
        'Pending - Jumbo Slitting' => 'pending-jumbo-slitting',
        'Pending - Printing' => 'pending-printing',
        'Pending - Die Cutting' => 'pending-die-cutting',
        'Pending - Barcode' => 'pending-barcode',
        'Pending - Label Slitting' => 'pending-label-slitting',
        'Preparing Printing' => 'printing-preparing',
        'Printing' => 'printing-active',
        'Printing Pause' => 'printing-pause',
        'Printed' => 'printed',
        'Preparing Die Cutting' => 'die-cutting-preparing',
        'Die Cutting' => 'die-cutting-active',
        'Die Cutting Pause' => 'die-cutting-pause',
        'Die Cut' => 'die-cutting-done',
        'Preparing Barcode' => 'barcode-preparing',
        'Barcode' => 'barcode-active',
        'Barcode Pause' => 'barcode-pause',
        'Barcoded' => 'barcode-done',
        'Preparing Label Slitting' => 'label-slitting-preparing',
        'Label Slitting' => 'label-slitting-active',
        'Label Slitting Pause' => 'label-slitting-pause',
        'Label Slitted' => 'label-slitting-done',
        'Preparing Packing' => 'packing-preparing',
        'Packing' => 'packing-active',
        'Packing Pause' => 'packing-pause',
        'Packed' => 'packing-done',
        'In Progress' => 'in-progress',
        'On Hold' => 'on-hold',
        'Running' => 'in-progress',
        'QC Passed' => 'completed',
        'QC Failed' => 'rejected',
        'Completed' => 'completed',
        'Dispatched' => 'dispatched',
        'Cancelled' => 'cancelled',
        'Complete' => 'completed',
    ];

    if (isset($map[$raw])) {
        return $map[$raw];
    }
    if (isset($map[$display])) {
        return $map[$display];
    }

    return 'draft';
}

/**
 * Shared status palette aligned with the global badge styles.
 */
function erp_status_palette_map(): array {
    return [
        'main' => ['background' => '#f3e8ff', 'color' => '#7e22ce'],
        'stock' => ['background' => '#d1fae5', 'color' => '#065f46'],
        'slitting' => ['background' => '#ffedd5', 'color' => '#c2410c'],
        'slitting-active' => ['background' => '#fff7ed', 'color' => '#9a3412'],
        'preparing-slitting' => ['background' => '#ffedd5', 'color' => '#c2410c'],
        'slitted' => ['background' => '#dcfce7', 'color' => '#166534'],
        'slitting-pause' => ['background' => '#fff1f2', 'color' => '#be123c'],
        'jumbo-slitting-active' => ['background' => '#312e81', 'color' => '#ffffff'],
        'jumbo-slitting-preparing' => ['background' => '#f5f3ff', 'color' => '#6d28d9'],
        'jumbo-slitted' => ['background' => '#ecfdf5', 'color' => '#047857'],
        'job-assign' => ['background' => '#ffe4e6', 'color' => '#be123c'],
        'draft' => ['background' => '#f3f4f6', 'color' => '#6b7280'],
        'sent' => ['background' => '#eff6ff', 'color' => '#1d4ed8'],
        'approved' => ['background' => '#dcfce7', 'color' => '#166534'],
        'rejected' => ['background' => '#fef2f2', 'color' => '#991b1b'],
        'converted' => ['background' => '#faf5ff', 'color' => '#6d28d9'],
        'pending' => ['background' => '#fff7ed', 'color' => '#c2410c'],
        'pending-jumbo-slitting' => ['background' => '#fdf4ff', 'color' => '#a21caf'],
        'pending-printing' => ['background' => '#eff6ff', 'color' => '#1d4ed8'],
        'pending-die-cutting' => ['background' => '#fef3c7', 'color' => '#92400e'],
        'pending-barcode' => ['background' => '#f0fdfa', 'color' => '#0f766e'],
        'pending-label-slitting' => ['background' => '#f5f3ff', 'color' => '#6d28d9'],
        'in-production' => ['background' => '#eff6ff', 'color' => '#1d4ed8'],
        'printing-preparing' => ['background' => '#dbeafe', 'color' => '#1e40af'],
        'printing-active' => ['background' => '#bfdbfe', 'color' => '#1d4ed8'],
        'printing-pause' => ['background' => '#fee2e2', 'color' => '#991b1b'],
        'printed' => ['background' => '#dcfce7', 'color' => '#166534'],
        'die-cutting-preparing' => ['background' => '#fef3c7', 'color' => '#92400e'],
        'die-cutting-active' => ['background' => '#fcd34d', 'color' => '#78350f'],
        'die-cutting-pause' => ['background' => '#fee2e2', 'color' => '#b91c1c'],
        'die-cutting-done' => ['background' => '#ecfccb', 'color' => '#3f6212'],
        'barcode-preparing' => ['background' => '#ccfbf1', 'color' => '#0f766e'],
        'barcode-active' => ['background' => '#99f6e4', 'color' => '#115e59'],
        'barcode-pause' => ['background' => '#ffe4e6', 'color' => '#be123c'],
        'barcode-done' => ['background' => '#a7f3d0', 'color' => '#047857'],
        'label-slitting-preparing' => ['background' => '#ede9fe', 'color' => '#5b21b6'],
        'label-slitting-active' => ['background' => '#ddd6fe', 'color' => '#6d28d9'],
        'label-slitting-pause' => ['background' => '#fce7f3', 'color' => '#9d174d'],
        'label-slitting-done' => ['background' => '#ddd6fe', 'color' => '#6d28d9'],
        'packing-preparing' => ['background' => '#e0f2fe', 'color' => '#0369a1'],
        'packing-active' => ['background' => '#7dd3fc', 'color' => '#0c4a6e'],
        'packing-pause' => ['background' => '#fde68a', 'color' => '#92400e'],
        'packing-done' => ['background' => '#bae6fd', 'color' => '#075985'],
        'completed' => ['background' => '#dcfce7', 'color' => '#166534'],
        'dispatched' => ['background' => '#f0fdf4', 'color' => '#15803d'],
        'cancelled' => ['background' => '#fef2f2', 'color' => '#991b1b'],
        'queued' => ['background' => '#f3f4f6', 'color' => '#4b5563'],
        'in-progress' => ['background' => '#eff6ff', 'color' => '#2563eb'],
        'on-hold' => ['background' => '#fff7ed', 'color' => '#b45309'],
        'urgent' => ['background' => '#fef2f2', 'color' => '#dc2626'],
        'high' => ['background' => '#fff7ed', 'color' => '#c2410c'],
        'normal' => ['background' => '#eff6ff', 'color' => '#1d4ed8'],
        'low' => ['background' => '#f3f4f6', 'color' => '#6b7280'],
    ];
}

/**
 * Resolve palette by shared status class.
 */
function erp_status_palette_by_class($class): array {
    $map = erp_status_palette_map();
    $key = strtolower(trim((string)$class));
    return $map[$key] ?? $map['draft'];
}

/**
 * Resolve palette for a status value.
 */
function erp_status_palette($status, array $context = []): array {
    return erp_status_palette_by_class(erp_status_badge_class($status, $context));
}

/**
 * Inline style string for shared status presentation.
 */
function erp_status_inline_style($status, array $context = []): string {
    $palette = erp_status_palette($status, $context);
    return 'background:' . $palette['background'] . ';color:' . $palette['color'] . ';';
}

/**
 * Canonical status options for paper stock module filters.
 */
function erp_paper_stock_status_options(): array {
    $coreStart = ['Main'];
    $coreEnd = ['Job Assign', 'Stock', 'Consume'];

    $departments = [];
    foreach (erp_get_machine_departments(getDB()) as $dept) {
        $label = trim((string)$dept);
        if ($label !== '') {
            $departments[] = $label;
        }
    }

    $merged = array_merge($coreStart, $departments, $coreEnd);
    $out = [];
    foreach ($merged as $item) {
        $k = strtolower(trim((string)$item));
        if ($k === '') continue;
        if (!isset($out[$k])) {
            $out[$k] = (string)$item;
        }
    }
    return array_values($out);
}

/**
 * Normalize legacy paper stock statuses into canonical paper-stock statuses.
 */
function erp_paper_stock_normalize_status($status): string {
    $s = trim((string)$status);
    if ($s === '') return 'Main';

    $canon = erp_paper_stock_status_options();
    foreach ($canon as $item) {
        if (strcasecmp($s, $item) === 0) return $item;
    }

    $departmentChoices = [];
    foreach ($canon as $item) {
        if (in_array($item, ['Main', 'Job Assign', 'Stock', 'Consume'], true)) continue;
        $departmentChoices[] = $item;
    }

    $canonDeptLabel = erp_canonical_department_label($s, $departmentChoices);
    if ($canonDeptLabel !== '' && in_array($canonDeptLabel, $departmentChoices, true)) {
        return $canonDeptLabel;
    }

    $n = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $s));
    $n = trim(preg_replace('/\s+/', ' ', $n));

    if (strpos($n, 'main') !== false || strpos($n, 'available') !== false) return 'Main';
    if (strpos($n, 'job assign') !== false || strpos($n, 'assigned') !== false || strpos($n, 'in production') !== false) return 'Job Assign';
    if (strpos($n, 'stock') !== false) return 'Stock';
    if (strpos($n, 'consum') !== false) return 'Consume';

    // Fallback heuristic: map old free-text statuses to nearest department label.
    foreach ($departmentChoices as $deptLabel) {
        $dk = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $deptLabel));
        $dk = trim(preg_replace('/\s+/', ' ', $dk));
        if ($dk !== '' && (strpos($n, $dk) !== false || strpos($dk, $n) !== false)) {
            return $deptLabel;
        }
    }

    $keywordGroups = [
        'slit' => ['slit', 'jumbo'],
        'print' => ['print'],
        'die' => ['die'],
        'barcode' => ['barcode', 'bar code'],
        'label' => ['label', 'lsl'],
        'pack' => ['pack'],
        'dispatch' => ['dispatch'],
    ];
    foreach ($keywordGroups as $group => $needles) {
        foreach ($departmentChoices as $deptLabel) {
            $dk = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $deptLabel));
            $dk = trim(preg_replace('/\s+/', ' ', $dk));
            foreach ($needles as $needle) {
                if ((strpos($n, $needle) !== false && strpos($dk, $needle) !== false) || ($group === 'label' && strpos($dk, 'slitting') !== false && strpos($n, 'label') !== false)) {
                    return $deptLabel;
                }
            }
        }
    }

    return 'Main';
}

/**
 * Canonical status options for label-printing planning board.
 */
function erp_label_planning_status_options(): array {
    return [
        'Pending',
        'Preparing Slitting',
        'Slitting',
        'Slitting Pause',
        'Slitted',
        'Pending - Jumbo Slitting',
        'Preparing Jumbo Slitting',
        'Jumbo Slitted',
        'Pending - Printing',
        'Printing',
        'Printing Pause',
        'Printed',
        'Pending - Die Cutting',
        'Preparing Die Cutting',
        'Die Cutting',
        'Die Cutting Pause',
        'Die Cut',
        'Pending - Barcode',
        'Preparing Barcode',
        'Barcode',
        'Barcode Pause',
        'Barcoded',
        'Pending - Label Slitting',
        'Preparing Label Slitting',
        'Label Slitting',
        'Label Slitting Pause',
        'Label Slitted',
        'Pending - Packing',
        'Preparing Packing',
        'Packing',
        'Packing Pause',
        'Packed',
        'Dispatched',
        'Complete',
    ];
}

/**
 * Normalize legacy planning statuses to canonical label-planning statuses.
 */
function erp_label_planning_normalize_status($status): string {
    $s = trim((string)$status);
    if ($s === '') return 'Pending';

    $allowed = erp_label_planning_status_options();
    foreach ($allowed as $item) {
        if (strcasecmp($s, $item) === 0) return $item;
    }

    $n = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $s));
    $n = trim(preg_replace('/\s+/', ' ', $n));

    if (strpos($n, 'dispat') !== false) return 'Dispatched';
    if (strpos($n, 'complete') !== false || strpos($n, 'completed') !== false) return 'Complete';

    if (strpos($n, 'slit') !== false || strpos($n, 'jumbo') !== false) {
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Slitting Pause';
        if (strpos($n, 'done') !== false || strpos($n, 'complete') !== false || strpos($n, 'slitted') !== false) return 'Slitted';
        if (strpos($n, 'running') !== false || strpos($n, 'progress') !== false) return 'Slitting';
        if (strpos($n, 'pending') !== false || strpos($n, 'queue') !== false || strpos($n, 'prepar') !== false) return 'Preparing Slitting';
        return 'Preparing Slitting';
    }

    if (strpos($n, 'print') !== false) {
        if (strpos($n, 'pending') !== false) return 'Pending - Printing';
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Printing Pause';
        if (strpos($n, 'prepar') !== false || strpos($n, 'running') !== false || strpos($n, 'progress') !== false) return 'Printing';
        if (strpos($n, 'done') !== false || strpos($n, 'printed') !== false || strpos($n, 'complete') !== false) return 'Printed';
        return 'Pending - Printing';
    }

    if (strpos($n, 'die') !== false) {
        if (strpos($n, 'pending') !== false) return 'Pending - Die Cutting';
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Die Cutting Pause';
        if (strpos($n, 'done') !== false || strpos($n, 'die cut') !== false) return 'Die Cut';
        if (strpos($n, 'prepar') !== false || strpos($n, 'running') !== false || strpos($n, 'progress') !== false || strpos($n, 'die cutting') !== false) return 'Die Cutting';
        return 'Pending - Die Cutting';
    }

    if (strpos($n, 'barcode') !== false || strpos($n, 'bar code') !== false) {
        if (strpos($n, 'pending') !== false) return 'Pending - Barcode';
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Barcode Pause';
        if (strpos($n, 'prepar') !== false || strpos($n, 'running') !== false || strpos($n, 'progress') !== false || $n === 'barcode' || $n === 'bar code') return 'Barcode';
        if (strpos($n, 'done') !== false || strpos($n, 'barcoded') !== false) return 'Barcoded';
        return 'Pending - Barcode';
    }

    if (strpos($n, 'label slitting') !== false || strpos($n, 'label') !== false) {
        if (strpos($n, 'pending') !== false) return 'Pending - Label Slitting';
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Label Slitting Pause';
        if (strpos($n, 'prepar') !== false || strpos($n, 'running') !== false || strpos($n, 'progress') !== false || strpos($n, 'label slitting') !== false) return 'Label Slitting';
        if (strpos($n, 'done') !== false || strpos($n, 'slitted') !== false) return 'Label Slitted';
        return 'Pending - Label Slitting';
    }

    if (strpos($n, 'pack') !== false) {
        if (strpos($n, 'pending') !== false) return 'Pending - Packing';
        if (strpos($n, 'hold') !== false || strpos($n, 'pause') !== false) return 'Packing Pause';
        if (strpos($n, 'prepar') !== false || strpos($n, 'running') !== false || strpos($n, 'progress') !== false || $n === 'packing') return 'Packing';
        if (strpos($n, 'done') !== false || strpos($n, 'packed') !== false) return 'Packed';
        return 'Pending - Packing';
    }

    if (strpos($n, 'hold') !== false || strpos($n, 'queue') !== false || strpos($n, 'pending') !== false) return 'Pending';

    return 'Pending';
}

/**
 * Canonical status options for barcode planning.
 */
function erp_barcode_planning_status_options(): array {
    return [
        'Pending',
        'Pending - Barcode',
        'Preparing Barcode',
        'Barcode',
        'Barcode Pause',
        'Barcoded',
        'Pending - Label Slitting',
        'Label Slitting',
        'Label Slitting Pause',
        'Label Slitted',
        'Complete',
    ];
}

/**
 * Normalize barcode planning statuses to canonical values.
 */
function erp_barcode_planning_normalize_status($status): string {
    $s = trim((string)$status);
    if ($s === '') return 'Pending';

    foreach (erp_barcode_planning_status_options() as $item) {
        if (strcasecmp($s, $item) === 0) return $item;
    }

    $n = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $s));
    $n = trim(preg_replace('/\s+/', ' ', $n));

    if ($n === 'complete') return 'Complete';
    if (strpos($n, 'label') !== false && strpos($n, 'slit') !== false) {
        if (strpos($n, 'pending') !== false || strpos($n, 'queue') !== false) return 'Pending - Label Slitting';
        if (strpos($n, 'pause') !== false || strpos($n, 'hold') !== false) return 'Label Slitting Pause';
        if (strpos($n, 'done') !== false || strpos($n, 'slitted') !== false || strpos($n, 'completed') !== false) return 'Label Slitted';
        if (strpos($n, 'running') !== false || strpos($n, 'progress') !== false || strpos($n, 'prepar') !== false) return 'Label Slitting';
        return 'Label Slitting';
    }
    if (strpos($n, 'barcode ready') !== false || strpos($n, 'barcoded') !== false) return 'Barcoded';
    if (strpos($n, 'pause') !== false || strpos($n, 'hold') !== false) return 'Barcode Pause';
    if (strpos($n, 'queue') !== false) return 'Pending - Barcode';
    if (strpos($n, 'progress') !== false || strpos($n, 'running') !== false || strpos($n, 'prepar') !== false) return 'Preparing Barcode';
    if ($n === 'barcode' || $n === 'bar code') return 'Barcode';
    if (strpos($n, 'completed') !== false || strpos($n, 'done') !== false) return 'Barcoded';
    if (strpos($n, 'pending') !== false) return 'Pending';
    if (strpos($n, 'barcode') !== false) return 'Pending - Barcode';

    return 'Pending';
}

/**
 * Page-wise status options (single source for status-enabled screens).
 */
function erp_status_page_options($pageKey): array {
    $key = strtolower(trim((string)$pageKey));
    if (in_array($key, ['planning.label-printing', 'planning.label', 'label-printing-planning'], true)) {
        return erp_label_planning_status_options();
    }
    if (in_array($key, ['planning.paperroll', 'planning.paper-roll', 'paperroll-planning', 'paper-roll-planning'], true)) {
        // PaperRoll planning follows the same global planning lifecycle statuses.
        return erp_label_planning_status_options();
    }
    if (in_array($key, ['planning.barcode', 'barcode-planning'], true)) {
        return erp_barcode_planning_status_options();
    }
    return ['Pending'];
}

/**
 * Page-wise default status.
 */
function erp_status_page_default($pageKey): string {
    $opts = erp_status_page_options($pageKey);
    return (string)($opts[0] ?? 'Pending');
}

/**
 * Page-wise status normalization.
 */
function erp_status_page_normalize($status, $pageKey): string {
    $key = strtolower(trim((string)$pageKey));
    if (in_array($key, ['planning.label-printing', 'planning.label', 'label-printing-planning'], true)) {
        return erp_label_planning_normalize_status($status);
    }
    if (in_array($key, ['planning.paperroll', 'planning.paper-roll', 'paperroll-planning', 'paper-roll-planning'], true)) {
        return erp_label_planning_normalize_status($status);
    }
    if (in_array($key, ['planning.barcode', 'barcode-planning'], true)) {
        return erp_barcode_planning_normalize_status($status);
    }

    $s = trim((string)$status);
    if ($s === '') return erp_status_page_default($pageKey);
    foreach (erp_status_page_options($pageKey) as $item) {
        if (strcasecmp($s, $item) === 0) return $item;
    }
    return erp_status_page_default($pageKey);
}
