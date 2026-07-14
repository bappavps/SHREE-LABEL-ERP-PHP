<?php
require_once __DIR__ . '/config/db.php';
$db = getDB();

function d($v) { 
    if ($v === null) return [];
    $d = json_decode(is_string($v) ? $v : '', true);
    return is_array($d) ? $d : [];
}

function p($sources, $keys) {
    foreach ($sources as $src) {
        if (!is_array($src)) continue;
        foreach ($keys as $k) {
            if (isset($src[$k]) && $src[$k] !== '' && $src[$k] !== null) return $src[$k];
        }
    }
    return '';
}

function f($v) {
    if ($v === null || $v === '') return null;
    $f = (float)str_replace(',', '', (string)$v);
    return $f;
}

function calc_total_roll_value($jobExtra, $planExtra, $prevExtra, $grandPrevExtra, $operatorEntry) {
    if (is_array($operatorEntry)) {
        $raw = $operatorEntry['roll_payload_json'] ?? null;
        $payload = is_array($raw) ? $raw : (is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : []);
        if (!is_array($payload)) $payload = [];
        if (!empty($payload)) {
            $mixedPayload = isset($payload['mixed']) && is_array($payload['mixed']) ? $payload['mixed'] : [];
            $mixedEnabled = !empty($mixedPayload['enabled']) && ((int)($mixedPayload['enabled'] ?? 0) === 1 || ($mixedPayload['enabled'] ?? false) === true);
            if ($mixedEnabled) {
                $mixedPool = f($mixedPayload['pool_extra_rolls'] ?? null);
                if ($mixedPool !== null && $mixedPool >= 0) return (string)((int)floor($mixedPool));
            }

            $topLevelTotal = p([$payload], ['total_roll_value', 'total_roll', 'total_rolls']);
            if ($topLevelTotal !== '') return $topLevelTotal;

            $rollOverrides = isset($payload['roll_overrides']) && is_array($payload['roll_overrides']) ? $payload['roll_overrides'] : [];
            if (!empty($rollOverrides)) {
                $selectedKeys = isset($payload['selected_roll_keys']) && is_array($payload['selected_roll_keys']) ? $payload['selected_roll_keys'] : [];
                $keysToTry = [];
                foreach ($selectedKeys as $rk) {
                    $k = trim((string)$rk);
                    if ($k !== '') $keysToTry[] = $k;
                }
                foreach (array_keys($rollOverrides) as $rk) {
                    $k = trim((string)$rk);
                    if ($k !== '') $keysToTry[] = $k;
                }
                $sumTotalRolls = 0;
                $hasTotalRolls = false;
                foreach ($keysToTry as $key) {
                    $state = $rollOverrides[$key] ?? null;
                    if (!is_array($state)) continue;
                    $rolls = f($state['total_rolls'] ?? ($state['total_roll_value'] ?? null));
                    if ($rolls !== null && $rolls > 0) {
                        $sumTotalRolls += (int)floor($rolls);
                        $hasTotalRolls = true;
                    }
                }
                if ($hasTotalRolls && $sumTotalRolls > 0) return (string)$sumTotalRolls;
            }
        }
    }

    $sources = [$jobExtra, $planExtra, $prevExtra, $grandPrevExtra];
    $explicitTotal = p($sources, [
        'total_roll_value', 'total_roll', 'total_rolls',
        'barcode_total_roll', 'barcode_total_rolls', 'label_slitting_total_roll',
    ]);
    if ($explicitTotal !== '') return $explicitTotal;

    $orderQtyRaw = p($sources, [
        'order_quantity_user', 'order_qty_user', 'order_quantity', 'order_qty',
        'quantity', 'qty_pcs', 'total_order_qty',
    ]);
    $pcsPerRollRaw = p($sources, [
        'pcs_per_roll', 'pices_per_roll', 'qty_in_roll', 'quantity_in_roll',
    ]);
    $orderQtyNum = f($orderQtyRaw);
    $pcsPerRollNum = f($pcsPerRollRaw);
    if ($orderQtyNum !== null && $orderQtyNum > 0 && $pcsPerRollNum !== null && $pcsPerRollNum > 0) {
        return (string)max(1, (int)ceil($orderQtyNum / $pcsPerRollNum));
    }
    return '';
}

// Get the data
$r2 = $db->query("SELECT extra_data FROM jobs WHERE id=3");
$jobRow = $r2->fetch_assoc();
$jobExtra = d($jobRow['extra_data']);

$r3 = $db->query("SELECT extra_data FROM planning WHERE id=1");
$planRow = $r3->fetch_assoc();
$planExtra = d($planRow['extra_data']);

// Get operator entry
$opRow = $db->query("SELECT * FROM packing_operator_entries WHERE job_no='LSL/2026/0001'")->fetch_assoc();

echo "Without operator entry: " . calc_total_roll_value($jobExtra, $planExtra, [], [], null) . "\n";
echo "With operator entry: " . calc_total_roll_value($jobExtra, $planExtra, [], [], $opRow) . "\n";

// Also check if the issue is the roll_payload_json escaping
echo "\n=== Raw roll_payload_json ===\n";
echo $opRow['roll_payload_json'] . "\n\n";

// Decode and check selected keys
$payload = json_decode($opRow['roll_payload_json'], true);
echo "selected_roll_keys: " . json_encode($payload['selected_roll_keys']) . "\n";
echo "roll_overrides keys: " . json_encode(array_keys($payload['roll_overrides'])) . "\n";

foreach ($payload['selected_roll_keys'] as $rk) {
    $st = $payload['roll_overrides'][$rk] ?? null;
    echo "Key '$rk': " . json_encode($st) . "\n";
    if ($st && isset($st['total_rolls'])) {
        echo "  total_rolls=" . $st['total_rolls'] . " (type=" . gettype($st['total_rolls']) . ")\n";
    }
}
