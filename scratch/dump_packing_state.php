<?php
require_once __DIR__ . '/../config/db.php';
$db = getDB();

$output = "# Complete Database Value Dump: Planning, Jobs, Packing, Inventory & Dispatch\n";
$output .= "**Dump Time**: " . date('Y-m-d H:i:s') . "\n\n";

// 1. PLANNING TABLE
$output .= "## 1. Full Planning Table Records\n";
$resP = $db->query("SELECT * FROM planning WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
if ($resP && $resP->num_rows > 0) {
    while ($r = $resP->fetch_assoc()) {
        $output .= "### Planning ID #" . $r['id'] . " (" . $r['job_no'] . ")\n";
        $output .= "```json\n";
        if (!empty($r['extra_data'])) {
            $parsed = json_decode($r['extra_data'], true);
            $r['extra_data_parsed'] = $parsed ?: $r['extra_data'];
        }
        $output .= json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $output .= "```\n\n";
    }
} else {
    $output .= "_No active planning records found._\n\n";
}

// 2. JOBS TABLE
$output .= "## 2. Full Jobs Table Records\n";
$resJ = $db->query("SELECT * FROM jobs WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id ASC");
if ($resJ && $resJ->num_rows > 0) {
    while ($r = $resJ->fetch_assoc()) {
        $output .= "### Job ID #" . $r['id'] . " (" . $r['job_no'] . " - " . $r['department'] . ")\n";
        $output .= "```json\n";
        if (!empty($r['extra_data'])) {
            $parsed = json_decode($r['extra_data'], true);
            $r['extra_data_parsed'] = $parsed ?: $r['extra_data'];
        }
        $output .= json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $output .= "```\n\n";
    }
} else {
    $output .= "_No active jobs found._\n\n";
}

// 3. PACKING OPERATOR ENTRIES
$output .= "## 3. Full Packing Operator Entries Table\n";
$tRes = $db->query("SHOW TABLES LIKE 'packing_operator_entries'");
if ($tRes && $tRes->num_rows > 0) {
    $resPack = $db->query("SELECT * FROM packing_operator_entries ORDER BY id ASC");
    if ($resPack && $resPack->num_rows > 0) {
        while ($r = $resPack->fetch_assoc()) {
            $output .= "### Packing Entry ID #" . $r['id'] . "\n";
            $output .= "```json\n";
            $output .= json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $output .= "```\n\n";
        }
    } else {
        $output .= "_No entries found in `packing_operator_entries` table._\n\n";
    }
} else {
    $output .= "_`packing_operator_entries` table does not exist._\n\n";
}

// 4. FINISHED GOODS STOCK
$output .= "## 4. Full Finished Goods Stock Table\n";
$tRes2 = $db->query("SHOW TABLES LIKE 'finished_goods_stock'");
if ($tRes2 && $tRes2->num_rows > 0) {
    $resFG = $db->query("SELECT * FROM finished_goods_stock ORDER BY id ASC");
    if ($resFG && $resFG->num_rows > 0) {
        while ($r = $resFG->fetch_assoc()) {
            $output .= "### Finished Stock ID #" . $r['id'] . " (" . $r['item_name'] . ")\n";
            $output .= "```json\n";
            $output .= json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $output .= "```\n\n";
        }
    } else {
        $output .= "_No records found in `finished_goods_stock` table._\n\n";
    }
} else {
    $output .= "_`finished_goods_stock` table does not exist._\n\n";
}

// 5. FINISHED GOODS DISPATCH LOG
$output .= "## 5. Full Finished Goods Dispatch Log Table\n";
$tRes3 = $db->query("SHOW TABLES LIKE 'finished_goods_dispatch_log'");
if ($tRes3 && $tRes3->num_rows > 0) {
    $resDisp = $db->query("SELECT * FROM finished_goods_dispatch_log ORDER BY id ASC");
    if ($resDisp && $resDisp->num_rows > 0) {
        while ($r = $resDisp->fetch_assoc()) {
            $output .= "### Dispatch Log ID #" . $r['id'] . "\n";
            $output .= "```json\n";
            $output .= json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $output .= "```\n\n";
        }
    } else {
        $output .= "_No dispatch log records found._\n\n";
    }
} else {
    $output .= "_`finished_goods_dispatch_log` table does not exist._\n\n";
}

$targetPath = __DIR__ . '/../scratch/full_db_values_dump.md';
file_put_contents($targetPath, $output);
echo "SUCCESS: Saved full dump to " . $targetPath . "\n";
