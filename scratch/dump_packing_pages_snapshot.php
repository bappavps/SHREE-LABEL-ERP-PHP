<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/packing/_data.php';

$db = getDB();

$out = "# Detailed Data & Values Dump for Packing Pages\n";
$out .= "**Dump Time**: " . date('Y-m-d H:i:s') . "\n";
$out .= "**Target Pages**:\n";
$out .= "- `http://localhost/calipot-erp/shree-label-php/modules/operators/packing/index.php`\n";
$out .= "- `http://localhost/calipot-erp/shree-label-php/modules/packing/index.php`\n\n";

// ── 1. PACKING OPERATOR STATION PAGE DATA ──
$out .= "## 1. Packing Operator Station Page Data (`modules/operators/packing/index.php`)\n";
$opData = packing_fetch_ready_rows($db, [
    'search' => '',
    'from' => '',
    'to' => '',
    'show_all_active' => true,
    'hide_packed_in_active' => true,
]);
$opHistory = packing_fetch_history_rows($db, ['search' => '', 'from' => '', 'to' => '', 'history_type' => 'operator']);

$out .= "### Tab Counts (Operator View)\n";
$out .= "```json\n" . json_encode($opData['counts'], JSON_PRETTY_PRINT) . "\n```\n\n";

$out .= "### Active Rows by Tab (Operator View)\n";
foreach ($opData['rows_by_tab'] as $tab => $rows) {
    $out .= "#### Tab: `$tab` (" . count($rows) . " rows)\n";
    if (!empty($rows)) {
        $out .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    } else {
        $out .= "_No active cards in `$tab` tab for operator station._\n\n";
    }
}

$out .= "#### Tab: `history` (Operator Station History: " . count($opHistory) . " rows)\n";
if (!empty($opHistory)) {
    $out .= "```json\n" . json_encode($opHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
} else {
    $out .= "_No operator station history entries found._\n\n";
}

// ── 2. MANAGER PACKING BOARD PAGE DATA ──
$out .= "## 2. Manager Packing Board Page Data (`modules/packing/index.php`)\n";
$mgrData = packing_fetch_ready_rows($db, [
    'search' => '',
    'from' => '',
    'to' => '',
    'status' => '',
]);
$mgrHistory = packing_fetch_history_rows($db, ['search' => '', 'from' => '', 'to' => '']);

$out .= "### Tab Counts (Manager View)\n";
$out .= "```json\n" . json_encode($mgrData['counts'], JSON_PRETTY_PRINT) . "\n```\n\n";

$out .= "### Active Rows by Tab (Manager View)\n";
foreach ($mgrData['rows_by_tab'] as $tab => $rows) {
    $out .= "#### Tab: `$tab` (" . count($rows) . " rows)\n";
    if (!empty($rows)) {
        $out .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    } else {
        $out .= "_No active cards in `$tab` tab for manager board._\n\n";
    }
}

$out .= "#### Tab: `history` (Manager Board History: " . count($mgrHistory) . " rows)\n";
if (!empty($mgrHistory)) {
    $out .= "```json\n" . json_encode($mgrHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
} else {
    $out .= "_No manager board history entries found._\n\n";
}

$target = __DIR__ . '/../scratch/packing_pages_exact_data.md';
file_put_contents($target, $out);
echo "SUCCESS: Dumped packing pages data to " . $target . "\n";
