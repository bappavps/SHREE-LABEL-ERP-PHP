<?php
// ============================================================
// ERP System — Slitting Module: AJAX API
// Single endpoint for all slitting operations.
// SAFE: Does NOT modify existing tables or modules.
// ============================================================
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';
require_once __DIR__ . '/setup_tables.php';

ensureSlittingTables();

header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$action = trim($_REQUEST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// CSRF check for all POST operations
if ($method === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCSRF($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// ─── Helper: Generate batch number using master prefix system ─
function generateBatchNo($db) {
    $batchId = getNextId('batch');
    if ($batchId) return $batchId;
    // Fallback if prefix system fails
    $prefix = 'SB-' . date('Ymd') . '-';
    $like   = $prefix . '%';
    $stmt   = $db->prepare("SELECT COUNT(*) AS cnt FROM slitting_batches WHERE batch_no LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $next = (int)($row['cnt'] ?? 0) + 1;
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ─── Helper: Ensure generated identifier is unique in table ─
function generateUniqueIdForTable(mysqli $db, string $moduleType, string $table, string $column, int $maxAttempts = 30) {
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = getNextId($moduleType);
        if (!$candidate) {
            break;
        }

        $sql = "SELECT COUNT(*) AS c FROM {$table} WHERE {$column} = ?";
        $chk = $db->prepare($sql);
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $exists = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        if (!$exists) {
            return $candidate;
        }
    }

    return null;
}

// ─── Helper: Derive stage number from planning.job_no ───────
// Example: PLN/2026/0042 -> JUM/2026/0042
function deriveStageJobNo($planNo, $targetPrefix) {
    $planNo = trim((string)$planNo);
    $targetPrefix = strtoupper(trim((string)$targetPrefix));
    if ($planNo === '' || $targetPrefix === '') return '';

    if (preg_match('/^[A-Za-z]+([\/-].+)$/', $planNo, $m)) {
        return $targetPrefix . $m[1];
    }

    return $targetPrefix . '/' . $planNo;
}

// ─── Helper: Detect slitting mode ────────────────────────────
// IF slit_length < parent_length → LENGTH
// IF slit_length == parent_length → WIDTH
// IF slit_length > parent_length → INVALID
function detectMode($slitLength, $parentLength) {
    $sl = (float)$slitLength;
    $pl = (float)$parentLength;
    if ($sl > $pl) return 'INVALID';
    if ($sl < $pl) return 'LENGTH';
    return 'WIDTH';
}

// ─── Helper: Generate child suffix ──────────────────────────
// WIDTH → A, B, C … Z, A1, B1 …
// LENGTH → 1, 2, 3 …
function getNextSuffix($mode, $index) {
    if ($mode === 'LENGTH') {
        return (string)($index + 1);
    }
    // WIDTH — alpha: A..Z, then A1..Z1, A2..Z2 …
    $letter = chr(65 + ($index % 26)); // A-Z
    $cycle  = intdiv($index, 26);
    return $cycle === 0 ? $letter : $letter . $cycle;
}

// ─── Helper: Get existing children of a roll in DB ──────────
function getExistingChildren($db, $parentRoll) {
    $like = $db->real_escape_string($parentRoll) . '-%';
    $res  = $db->query("SELECT roll_no FROM paper_stock WHERE roll_no LIKE '{$like}'");
    $children = [];
    while ($r = $res->fetch_assoc()) {
        $children[] = $r['roll_no'];
    }
    return $children;
}

// ─── Helper: Count direct children (one level deep) ─────────
function countDirectChildren($db, $parentRoll, $mode) {
    $existing = getExistingChildren($db, $parentRoll);
    $count = 0;
    $parentEsc = preg_quote($parentRoll, '/');

    if ($mode === 'WIDTH') {
        // Match T-1205-A, T-1205-B etc. (alpha suffix, single level)
        $pattern = '/^' . $parentEsc . '-[A-Z][0-9]*$/i';
    } else {
        // Match T-1205-1, T-1205-2 etc. (numeric suffix, single level)
        $pattern = '/^' . $parentEsc . '-\d+$/';
    }

    foreach ($existing as $ch) {
        if (preg_match($pattern, $ch)) $count++;
    }
    return $count;
}

// ─── Helper: Normalize material for comparison ──────────────
function normalizeMaterial($str) {
    return strtolower(preg_replace('/[\s\-]+/', '', trim((string)$str)));
}

// ─── Route actions ──────────────────────────────────────────
try {
    switch ($action) {

    // ═════════════════════════════════════════════════════════
    // SEARCH ROLL — find by roll_no
    // ═════════════════════════════════════════════════════════
    case 'search_roll':
        $q = trim($_GET['q'] ?? '');
        if ($q === '') { echo json_encode(['ok' => false, 'error' => 'Empty search']); break; }

        $stmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? AND status NOT IN ('Consumed','Dispatched') LIMIT 1");
        $stmt->bind_param('s', $q);
        $stmt->execute();
        $roll = $stmt->get_result()->fetch_assoc();

        if (!$roll) {
            // Also try LIKE search
            $like = '%' . $db->real_escape_string($q) . '%';
            $stmt2 = $db->prepare("SELECT * FROM paper_stock WHERE roll_no LIKE ? AND status NOT IN ('Consumed','Dispatched') ORDER BY roll_no LIMIT 10");
            $stmt2->bind_param('s', $like);
            $stmt2->execute();
            $rolls = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['ok' => true, 'roll' => null, 'suggestions' => $rolls]);
        } else {
            echo json_encode(['ok' => true, 'roll' => $roll, 'suggestions' => []]);
        }
        break;

    // ═════════════════════════════════════════════════════════
    // SEARCH ROLLS BY MATERIAL — for Auto Planner
    // ═════════════════════════════════════════════════════════
    case 'search_rolls_by_material':
        $paperType = trim($_GET['paper_type'] ?? '');
        $targetWidth = (float)($_GET['target_width'] ?? 0);
        $targetLength = (float)($_GET['target_length'] ?? 0);

        if ($paperType === '' || $targetWidth <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Missing paper_type or target_width']);
            break;
        }

        $normalizedPaperType = normalizeMaterial($paperType);

        // Find all available rolls, then do tolerant material matching in PHP.
        $stmt = $db->prepare("SELECT * FROM paper_stock WHERE status IN ('Stock','Main','Available') AND width_mm >= ? ORDER BY width_mm ASC, length_mtr DESC");
        $stmt->bind_param('d', $targetWidth);
        $stmt->execute();
        $candidateRolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $rolls = array_values(array_filter($candidateRolls, function($roll) use ($normalizedPaperType) {
            $rollType = normalizeMaterial($roll['paper_type'] ?? '');
            if ($normalizedPaperType === '' || $rollType === '') return false;
            return $rollType === $normalizedPaperType || strpos($rollType, $normalizedPaperType) !== false || strpos($normalizedPaperType, $rollType) !== false;
        }));

        $options = [];
        foreach ($rolls as $roll) {
            $rw = (float)$roll['width_mm'];
            $rl = (float)$roll['length_mtr'];
            $splits = floor($rw / $targetWidth);
            if ($splits < 1) continue;
            $waste  = fmod($rw, $targetWidth);
            $efficiency = $rw > 0 ? round(($splits * $targetWidth) / $rw * 100, 1) : 0;

            // Determine if this is width or length slitting
            $mode = 'WIDTH';
            if ($targetLength > 0 && $targetLength < $rl) {
                $mode = 'LENGTH';
                $lengthSplits = floor($rl / $targetLength);
            }

            $possibleWays = [];
            for ($count = (int)$splits; $count >= 1; $count--) {
                $stockWaste = round(max(0, $rw - ($targetWidth * $count)), 2);
                $possibleWays[] = [
                    'count' => $count,
                    'stock_width' => round($targetWidth, 2),
                    'stock_waste_mm' => $stockWaste,
                    'adjust_width' => round($rw / $count, 2),
                ];
            }

            $options[] = [
                'roll'       => $roll,
                'splits'     => (int)$splits,
                'waste_mm'   => round($waste, 2),
                'efficiency' => $efficiency,
                'mode'       => $mode,
                'length_splits' => $mode === 'LENGTH' ? (int)($lengthSplits ?? 1) : 1,
                'possible_ways' => $possibleWays,
            ];
        }

        // Sort by width ascending (smallest roll first), then efficiency descending
        usort($options, function($a, $b) {
            $wCmp = (float)$a['roll']['width_mm'] <=> (float)$b['roll']['width_mm'];
            if ($wCmp !== 0) return $wCmp;
            return $b['efficiency'] <=> $a['efficiency'];
        });

        echo json_encode(['ok' => true, 'options' => $options]);
        break;

    // ═════════════════════════════════════════════════════════
    // GET PLANNING JOBS — pending jobs that need slitting
    // ═════════════════════════════════════════════════════════
    case 'get_planning_jobs':
        // Planning is the single source of truth for auto slitting queue.
        // Only pending jobs are eligible for execution.
        $rows = $db->query("SELECT p.*, so.material_type, so.label_width_mm, so.label_length_mm, so.quantity
            FROM planning p
            LEFT JOIN sales_orders so ON p.sales_order_id = so.id
            WHERE p.status = 'Pending'
            ORDER BY FIELD(p.priority,'Urgent','High','Normal','Low'), p.scheduled_date ASC
            LIMIT 100")->fetch_all(MYSQLI_ASSOC);

        // Merge extra_data JSON fields into each row so JS can read them directly
        foreach ($rows as &$row) {
            $extra = json_decode($row['extra_data'] ?? '{}', true);
            if (is_array($extra)) {
                // Provide fallback values from extra_data when sales_order fields are empty
                if (empty($row['material_type']) && !empty($extra['material'])) {
                    $row['material_type'] = $extra['material'];
                }
                if (empty($row['label_width_mm']) && !empty($extra['paper_size'])) {
                    $row['label_width_mm'] = $extra['paper_size'];
                }
                if (empty($row['label_length_mm']) && !empty($extra['size'])) {
                    $row['label_length_mm'] = $extra['size'];
                }
                if (empty($row['quantity']) && !empty($extra['qty_pcs'])) {
                    $row['quantity'] = $extra['qty_pcs'];
                }
                // Expose extra planning fields
                $row['allocate_mtrs'] = $extra['allocate_mtrs'] ?? '';
                $row['die_type'] = $extra['die'] ?? '';
                $row['core_size'] = $extra['core_size'] ?? '';
                $row['dispatch_date'] = $extra['dispatch_date'] ?? ($row['scheduled_date'] ?? '');
                $row['printing_planning'] = $extra['printing_planning'] ?? '';
                $row['roll_direction'] = $extra['roll_direction'] ?? '';
                $row['repeat_val'] = $extra['repeat'] ?? '';
            }
        }
        unset($row);

        echo json_encode(['ok' => true, 'jobs' => $rows]);
        break;

    // ═════════════════════════════════════════════════════════
    // VALIDATE CONFIG — check slit runs against parent roll
    // ═════════════════════════════════════════════════════════
    case 'validate_config':
        $parentRollNo = trim($_GET['parent_roll_no'] ?? '');
        $runsJson     = trim($_GET['runs'] ?? '[]');
        $runs         = json_decode($runsJson, true);

        if (!$parentRollNo || !is_array($runs) || empty($runs)) {
            echo json_encode(['ok' => false, 'error' => 'Missing parent_roll_no or runs']);
            break;
        }

        $stmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1");
        $stmt->bind_param('s', $parentRollNo);
        $stmt->execute();
        $parent = $stmt->get_result()->fetch_assoc();

        if (!$parent) {
            echo json_encode(['ok' => false, 'error' => 'Parent roll not found']);
            break;
        }

        $pw = (float)$parent['width_mm'];
        $pl = (float)$parent['length_mtr'];
        $totalUsed = 0;
        $validatedRuns = [];
        $hasError = false;

        foreach ($runs as $run) {
            $w = (float)($run['width'] ?? 0);
            $l = (float)($run['length'] ?? 0);
            $q = max(1, (int)($run['qty'] ?? 1));

            if ($w <= 0) { $hasError = true; continue; }
            if ($l <= 0) $l = $pl; // default to full length

            $mode = detectMode($l, $pl);
            if ($mode === 'INVALID') {
                echo json_encode(['ok' => false, 'error' => "Slit length {$l}m exceeds parent length {$pl}m"]);
                break 2;
            }

            if ($mode === 'WIDTH') {
                $totalUsed += $w * $q;
            } else {
                // LENGTH mode — width stays same, length splits
                $totalUsed += $w * $q;
            }

            $validatedRuns[] = [
                'width'  => $w,
                'length' => $l,
                'qty'    => $q,
                'mode'   => $mode,
            ];
        }

        $remainder = $pw - $totalUsed;
        $isValid   = $remainder >= -0.01; // small tolerance for floating point
        $utilization = $pw > 0 ? round(($totalUsed / $pw) * 100, 1) : 0;

        echo json_encode([
            'ok'          => true,
            'valid'       => $isValid,
            'parent'      => $parent,
            'runs'        => $validatedRuns,
            'total_used'  => round($totalUsed, 2),
            'remainder'   => round(max(0, $remainder), 2),
            'utilization' => min(100, $utilization),
        ]);
        break;

    // ═════════════════════════════════════════════════════════
    // EXECUTE BATCH — Atomic transaction
    // ═════════════════════════════════════════════════════════
    case 'execute_batch':
        if ($method !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST required']);
            break;
        }

        $parentRollNo   = trim($_POST['parent_roll_no'] ?? '');
        $runsJson       = trim($_POST['runs'] ?? '[]');
        $remainderAction= trim($_POST['remainder_action'] ?? 'STOCK'); // STOCK or ADJUST
        $operatorName   = trim($_POST['operator_name'] ?? '');
        $machine        = trim($_POST['machine'] ?? '');
        $jobNo          = trim($_POST['job_no'] ?? '');
        $jobName        = trim($_POST['job_name'] ?? '');
        $jobSize        = trim($_POST['job_size'] ?? '');
        $planNoInput    = trim($_POST['plan_no'] ?? $jobNo);
        $destination    = trim($_POST['destination'] ?? 'STOCK');
        $planningId     = (int)($_POST['planning_id'] ?? 0);
        $runs           = json_decode($runsJson, true);

        if (!$parentRollNo || !is_array($runs) || empty($runs)) {
            echo json_encode(['ok' => false, 'error' => 'Missing parent_roll_no or runs']);
            break;
        }

        if ($machine === '') {
            echo json_encode(['ok' => false, 'error' => 'Error: Select machine first']);
            break;
        }

        if ($operatorName === '') {
            $operatorName = trim((string)($_SESSION['user_name'] ?? 'Operator'));
        }

        $db->begin_transaction();
        try {
            // 1. Lock and fetch parent roll
            $stmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? FOR UPDATE");
            $stmt->bind_param('s', $parentRollNo);
            $stmt->execute();
            $parent = $stmt->get_result()->fetch_assoc();

            if (!$parent) {
                throw new Exception('Parent roll not found: ' . $parentRollNo);
            }
            if ($parent['status'] === 'Consumed') {
                throw new Exception('Parent roll already consumed: ' . $parentRollNo);
            }

            $pw = (float)$parent['width_mm'];
            $pl = (float)$parent['length_mtr'];

            // Resolve planning context using planning_id first, then plan number.
            $planNo = $planNoInput;
            $planningExtra = [];
            $planningJobName = '';
            if ($planningId > 0) {
                $planStmt = $db->prepare("SELECT id, job_no, job_name, extra_data FROM planning WHERE id = ? LIMIT 1");
                $planStmt->bind_param('i', $planningId);
                $planStmt->execute();
                $planRow = $planStmt->get_result()->fetch_assoc();
                if ($planRow) {
                    $planNo           = trim((string)($planRow['job_no'] ?? $planNo));
                    $planningJobName  = trim((string)($planRow['job_name'] ?? ''));
                    $planningExtra    = json_decode((string)($planRow['extra_data'] ?? '{}'), true) ?: [];
                }
            } elseif ($planNo !== '') {
                $planStmt = $db->prepare("SELECT id, job_no, job_name, extra_data FROM planning WHERE job_no = ? LIMIT 1");
                $planStmt->bind_param('s', $planNo);
                $planStmt->execute();
                $planRow = $planStmt->get_result()->fetch_assoc();
                if ($planRow) {
                    $planningId       = (int)$planRow['id'];
                    $planNo           = trim((string)($planRow['job_no'] ?? $planNo));
                    $planningJobName  = trim((string)($planRow['job_name'] ?? ''));
                    $planningExtra    = json_decode((string)($planRow['extra_data'] ?? '{}'), true) ?: [];
                }
            }

            // 2. Validate all runs & detect mode
            $totalUsedWidth = 0;
            $validRuns = [];
            foreach ($runs as $run) {
                $w = (float)($run['width'] ?? 0);
                $l = (float)($run['length'] ?? 0);
                $q = max(1, (int)($run['qty'] ?? 1));
                if ($w <= 0) continue;
                if ($l <= 0) $l = $pl;

                $mode = detectMode($l, $pl);
                if ($mode === 'INVALID') {
                    throw new Exception("Slit length {$l}m exceeds parent length {$pl}m");
                }

                $totalUsedWidth += $w * $q;
                $validRuns[] = ['width' => $w, 'length' => $l, 'qty' => $q, 'mode' => $mode,
                                'dest' => $run['destination'] ?? $destination,
                                'job_no' => $run['job_no'] ?? $planNo,
                                'job_name' => $run['job_name'] ?? $jobName,
                                'job_size' => $run['job_size'] ?? $jobSize];
            }

            $remainderWidth = $pw - $totalUsedWidth;
            if ($remainderWidth < -0.5) {
                throw new Exception('Over-cut! Total width ' . round($totalUsedWidth,2) . 'mm exceeds parent ' . round($pw,2) . 'mm');
            }

            // 3. Create batch (collision-safe for unique batch_no)
            $userId  = $_SESSION['user_id'] ?? null;
            $batchId = 0;
            $batchNo = '';
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $batchNo = (string)(generateUniqueIdForTable($db, 'batch', 'slitting_batches', 'batch_no') ?? '');
                if ($batchNo === '') {
                    $batchNo = generateBatchNo($db);
                }

                $stmtB = $db->prepare("INSERT INTO slitting_batches (batch_no, status, operator_name, machine, created_by) VALUES (?, 'Completed', ?, ?, ?)");
                $stmtB->bind_param('sssi', $batchNo, $operatorName, $machine, $userId);
                $okBatch = $stmtB->execute();
                if ($okBatch) {
                    $batchId = (int)$db->insert_id;
                    break;
                }

                // Duplicate key race/collision: retry with next generated number.
                if ((int)$stmtB->errno === 1062) {
                    continue;
                }

                throw new Exception('Failed to create slitting batch: ' . $stmtB->error);
            }
            if ($batchId <= 0) {
                throw new Exception('Unable to generate unique batch number. Please try again.');
            }

            // 4. Generate child rolls
            $childRolls = [];
            $entryStmt = $db->prepare("INSERT INTO slitting_entries (batch_id, parent_roll_no, child_roll_no, slit_width_mm, slit_length_mtr, qty, mode, destination, job_no, job_name, job_size, is_remainder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            // Get existing children to determine starting index
            $existingChildren = getExistingChildren($db, $parentRollNo);

            foreach ($validRuns as $run) {

            // Use only current batch index for suffixes
            $batchChildIdx = 0;
            for ($i = 0; $i < $run['qty']; $i++, $batchChildIdx++) {
                $suffix  = getNextSuffix($run['mode'], $batchChildIdx);
                $childNo = $parentRollNo . '-' . $suffix;

                // Check for duplicate roll_no in DB (should not happen, but just in case)
                $chkStmt = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ?");
                $chkStmt->bind_param('s', $childNo);
                $chkStmt->execute();
                $extra = 0;
                while ($chkStmt->get_result()->num_rows > 0 && $extra < 100) {
                    $extra++;
                    $suffix  = getNextSuffix($run['mode'], $batchChildIdx + $extra);
                    $childNo = $parentRollNo . '-' . $suffix;
                    $chkStmt->bind_param('s', $childNo);
                    $chkStmt->execute();
                }

                // Determine child status
                $childStatus = ($run['dest'] === 'JOB') ? 'Job Assign' : 'Stock';

                // Extract values to local vars for bind_param by-reference compatibility (PHP 8+)
                $cPaperType    = (string)($parent['paper_type'] ?? '');
                $cCompany      = (string)($parent['company'] ?? '');
                $cWidth        = (float)$run['width'];
                $cLength       = (float)$run['length'];
                $cGsm          = (float)($parent['gsm'] ?? 0);
                $cWeightKg     = (float)($parent['weight_kg'] ?? 0);
                $cPurchaseRate = (float)($parent['purchase_rate'] ?? 0);
                $childSqm      = ($cWidth / 1000) * $cLength;
                $cLotBatch     = (string)($parent['lot_batch_no'] ?? '');
                $cJobNo        = (string)($run['job_no'] ?? '');
                $cJobName      = (string)($run['job_name'] ?? '');
                $cJobSize      = (string)($run['job_size'] ?? '');

                // Insert child into paper_stock
                $insChild = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate, sqm, lot_batch_no, status, job_no, job_name, job_size, date_received, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)");
                $insChild->bind_param('sssddddddsssssi',
                    $childNo, $cPaperType, $cCompany,
                    $cWidth, $cLength, $cGsm, $cWeightKg, $cPurchaseRate,
                    $childSqm, $cLotBatch, $childStatus,
                    $cJobNo, $cJobName, $cJobSize, $userId
                );
                $insChild->execute();

                // Insert slitting entry
                $isRem    = 0;
                $entryQty = 1;
                $eMode    = (string)$run['mode'];
                $eDest    = (string)$run['dest'];
                $entryStmt->bind_param('issddisssssi',
                    $batchId, $parentRollNo, $childNo,
                    $cWidth, $cLength, $entryQty,
                    $eMode, $eDest,
                    $cJobNo, $cJobName, $cJobSize,
                    $isRem
                );
                $entryStmt->execute();

                $childRolls[] = [
                    'roll_no' => $childNo,
                    'parent_roll_no' => $parentRollNo,
                    'width'   => $cWidth,
                    'length'  => $cLength,
                    'mode'    => $run['mode'],
                    'dest'    => $run['dest'],
                    'status'  => (($run['dest'] ?? '') === 'JOB') ? 'Slitting' : 'Stock',
                    'job_no'  => $cJobNo,
                    'job_name'=> $cJobName,
                    'company' => $cCompany,
                    'paper_type' => $cPaperType,
                    'gsm'     => $cGsm,
                    'weight_kg' => $cWeightKg,
                    'sqm' => round($childSqm, 2),
                    'wastage' => 0,
                    'remarks' => '',
                ];
            }
            }

            // 5. Handle remainder — gets next sequential letter (same as slit children)
            if ($remainderWidth > 0.5 && $remainderAction === 'STOCK') {
                // Use next letter after last batch child for remainder
                $remIdx = $batchChildIdx;
                $remSuffix = getNextSuffix('WIDTH', $remIdx);
                $remRollNo = $parentRollNo . '-' . $remSuffix;
                // Check duplicate — increment until unique
                $chkStmt = $db->prepare("SELECT id FROM paper_stock WHERE roll_no = ?");
                $chkStmt->bind_param('s', $remRollNo);
                $chkStmt->execute();
                while ($chkStmt->get_result()->num_rows > 0 && $remIdx < 200) {
                    $remIdx++;
                    $remSuffix = getNextSuffix('WIDTH', $remIdx);
                    $remRollNo = $parentRollNo . '-' . $remSuffix;
                    $chkStmt->bind_param('s', $remRollNo);
                    $chkStmt->execute();
                }

                // Extract to local vars for bind_param compatibility (PHP 8+)
                $remPaperType = (string)($parent['paper_type'] ?? '');
                $remCompany   = (string)($parent['company'] ?? '');
                $remGsm       = (float)($parent['gsm'] ?? 0);
                $remWeightKg  = (float)($parent['weight_kg'] ?? 0);
                $remPRate     = (float)($parent['purchase_rate'] ?? 0);
                $remSqm       = ($remainderWidth / 1000) * $pl;
                $remLotBatch  = (string)($parent['lot_batch_no'] ?? '');

                $insRem = $db->prepare("INSERT INTO paper_stock (roll_no, paper_type, company, width_mm, length_mtr, gsm, weight_kg, purchase_rate, sqm, lot_batch_no, status, date_received, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Stock', CURDATE(), ?)");
                $insRem->bind_param('sssddddddsi',
                    $remRollNo, $remPaperType, $remCompany,
                    $remainderWidth, $pl, $remGsm, $remWeightKg, $remPRate,
                    $remSqm, $remLotBatch, $userId
                );
                $insRem->execute();

                // Entry for remainder
                $isRem    = 1;
                $remQty   = 1;
                $remDest  = 'STOCK';
                $remMode  = 'WIDTH';
                $emptyStr = '';
                $entryStmt->bind_param('issddisssssi',
                    $batchId, $parentRollNo, $remRollNo,
                    $remainderWidth, $pl, $remQty,
                    $remMode, $remDest,
                    $emptyStr, $emptyStr, $emptyStr,
                    $isRem
                );
                $entryStmt->execute();

                $childRolls[] = [
                    'roll_no'      => $remRollNo,
                    'parent_roll_no' => $parentRollNo,
                    'width'        => $remainderWidth,
                    'length'       => $pl,
                    'mode'         => 'WIDTH',
                    'dest'         => 'STOCK',
                    'status'       => 'Stock',
                    'job_no'       => '',
                    'job_name'     => '',
                    'company'      => $remCompany,
                    'paper_type'   => $remPaperType,
                    'gsm'          => $remGsm,
                    'weight_kg'    => $remWeightKg,
                    'sqm'          => round($remSqm, 2),
                    'wastage'      => round($remainderWidth, 2),
                    'remarks'      => 'Remainder roll',
                    'is_remainder' => true,
                ];
            } elseif ($remainderWidth > 0.5 && $remainderAction === 'ADJUST') {
                // Distribute remainder evenly across child rolls — update their widths
                $childCount = count($childRolls);
                if ($childCount > 0) {
                    $addEach = $remainderWidth / $childCount;
                    foreach ($childRolls as &$ch) {
                        $newW = $ch['width'] + $addEach;
                        $newSqm = ($newW / 1000) * $ch['length'];
                        $updStmt = $db->prepare("UPDATE paper_stock SET width_mm = ?, sqm = ? WHERE roll_no = ?");
                        $updStmt->bind_param('dds', $newW, $newSqm, $ch['roll_no']);
                        $updStmt->execute();
                        $ch['width'] = round($newW, 2);
                    }
                    unset($ch);
                }
            }

            // 6. Mark parent as Consumed
            $stmtU = $db->prepare("UPDATE paper_stock SET status = 'Consumed', date_used = CURDATE() WHERE roll_no = ?");
            $stmtU->bind_param('s', $parentRollNo);
            $stmtU->execute();

            // 7. Insert inventory log
            $logDesc = "Slitting batch {$batchNo}: {$parentRollNo} → " . count($childRolls) . " child rolls";
            $logStmt = $db->prepare("INSERT INTO inventory_logs (action_type, roll_no, paper_stock_id, description, performed_by) VALUES ('SLITTING', ?, ?, ?, ?)");
            $logStmt->bind_param('sisi', $parentRollNo, $parent['id'], $logDesc, $userId);
            $logStmt->execute();

            // 8. Create ONE jumbo job per plan (outside child-roll loop).
            $createdJobCards = [];
            $jobChildRolls = [];
            $stockRolls = [];
            foreach ($childRolls as $ch) {
                if (($ch['dest'] ?? '') === 'JOB') $jobChildRolls[] = $ch;
                if (($ch['dest'] ?? '') === 'STOCK') $stockRolls[] = $ch;
            }

            $totalQtyMtr = 0.0;
            foreach ($jobChildRolls as $ch) $totalQtyMtr += (float)($ch['length'] ?? 0);
            foreach ($stockRolls as $ch) $totalQtyMtr += (float)($ch['length'] ?? 0);

            $materialRaw = (string)($planningExtra['material'] ?? ($parent['paper_type'] ?? ''));
            $isPaperMaterial = normalizeMaterial($materialRaw) === 'paper';

            $extraPayload = [
                'plan_no' => $planNo,
                'parent_roll' => $parentRollNo,
                'parent_details' => [
                    'roll_no' => $parentRollNo,
                    'company' => (string)($parent['company'] ?? ''),
                    'paper_type' => (string)($parent['paper_type'] ?? ''),
                    'width_mm' => (float)($parent['width_mm'] ?? 0),
                    'length_mtr' => (float)($parent['length_mtr'] ?? 0),
                    'gsm' => (float)($parent['gsm'] ?? 0),
                    'weight_kg' => (float)($parent['weight_kg'] ?? 0),
                    'sqm'             => round(((float)($parent['width_mm'] ?? 0) / 1000) * (float)($parent['length_mtr'] ?? 0), 2),
                    'original_status' => (string)($parent['status'] ?? 'Main'),
                    'remarks'         => (string)($parent['remarks'] ?? ''),
                ],
                'child_rolls' => array_values($jobChildRolls),
                'stock_rolls' => array_values($stockRolls),
                'total_roll_count' => count($jobChildRolls) + count($stockRolls) + 1,
                'total_qty_mtr' => round($totalQtyMtr, 2),
                'material' => $materialRaw,
                'paper_combined' => $isPaperMaterial,
                'batch_no' => $batchNo,
                'machine' => $machine,
                'operator_name' => $operatorName,
            ];

            $displayJobName = ($planningJobName !== '' ? $planningJobName : $jobName);
            $jumboJobId = 0;
            if (!empty($jobChildRolls)) {
                $existingJumbo = null;
                if ($planningId > 0) {
                    $findJ = $db->prepare("SELECT * FROM jobs WHERE job_type = 'Slitting' AND planning_id = ? AND status IN ('Pending','Running') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
                    $findJ->bind_param('i', $planningId);
                    $findJ->execute();
                    $existingJumbo = $findJ->get_result()->fetch_assoc();
                } elseif ($planNo !== '') {
                    $findJ = $db->prepare("SELECT * FROM jobs WHERE job_type = 'Slitting' AND JSON_UNQUOTE(JSON_EXTRACT(extra_data, '$.plan_no')) = ? AND status IN ('Pending','Running') AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
                    $findJ->bind_param('s', $planNo);
                    $findJ->execute();
                    $existingJumbo = $findJ->get_result()->fetch_assoc();
                }

                if ($existingJumbo) {
                    $oldExtra = json_decode((string)($existingJumbo['extra_data'] ?? '{}'), true) ?: [];
                    $oldChild = is_array($oldExtra['child_rolls'] ?? null) ? $oldExtra['child_rolls'] : [];
                    $oldStock = is_array($oldExtra['stock_rolls'] ?? null) ? $oldExtra['stock_rolls'] : [];

                    $mergeByRoll = function(array $rows) {
                        $m = [];
                        foreach ($rows as $r) {
                            $k = (string)($r['roll_no'] ?? '');
                            if ($k === '') continue;
                            $m[$k] = $r;
                        }
                        return array_values($m);
                    };

                    $extraPayload['child_rolls'] = $mergeByRoll(array_merge($oldChild, $jobChildRolls));
                    $extraPayload['stock_rolls'] = $mergeByRoll(array_merge($oldStock, $stockRolls));
                    $extraPayload['total_roll_count'] = count($extraPayload['child_rolls']) + count($extraPayload['stock_rolls']) + 1;

                    $newExtra = json_encode($extraPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $updJ = $db->prepare("UPDATE jobs SET extra_data = ?, notes = ? WHERE id = ?");
                    $notesJ = 'Jumbo grouped slitting job | Plan: ' . ($planNo ?: 'N/A') . ' | JMB: ' . (string)($existingJumbo['job_no'] ?? 'N/A') . ($displayJobName !== '' ? ' | Job Name : ' . $displayJobName : '');
                    $jid = (int)$existingJumbo['id'];
                    $updJ->bind_param('ssi', $newExtra, $notesJ, $jid);
                    $updJ->execute();
                    $jumboJobId = $jid;
                    $createdJobCards[] = ['job_no' => $existingJumbo['job_no'], 'type' => 'Slitting', 'roll' => $parentRollNo, 'id' => $jumboJobId];
                } else {
                    // Derive JMB suffix from planning number (e.g., PLN/2026/0002 → JMB/2026/0002)
                    $jcNoJumbo = '';
                    if ($planNo !== '') {
                        $parts = explode('/', $planNo);
                        if (count($parts) >= 3) {
                            $suffix = (string)$parts[2];
                            $year = (string)($parts[1] ?? date('Y'));
                            $jcNoJumbo = 'JMB/' . $year . '/' . $suffix;
                        }
                    }
                    
                    // Fallback to deriveStageJobNo if suffix extraction fails
                    if ($jcNoJumbo === '') {
                        $jcNoJumbo = deriveStageJobNo($planNo, 'JMB');
                    }
                    if ($jcNoJumbo === '') {
                        $jcNoJumbo = 'JMB/' . date('Y') . '/' . str_pad((string)$batchId, 4, '0', STR_PAD_LEFT);
                    }

                    $extraJson = json_encode($extraPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $pidJ = $planningId > 0 ? $planningId : null;
                    $insertedJumbo = false;
                    for ($jumboAttempt = 0; $jumboAttempt < 30; $jumboAttempt++) {
                        if ($jumboAttempt > 0) {
                            $jcNoJumbo = (string)(generateUniqueIdForTable($db, 'jumbo_job', 'jobs', 'job_no') ?? '');
                            if ($jcNoJumbo === '') {
                                $jcNoJumbo = 'JMB/' . date('Y') . '/' . str_pad((string)($batchId + $jumboAttempt), 4, '0', STR_PAD_LEFT);
                            }
                        }

                        $notesJ = 'Jumbo grouped slitting job | Plan: ' . ($planNo ?: 'N/A') . ' | JMB: ' . $jcNoJumbo . ($displayJobName !== '' ? ' | Job Name : ' . $displayJobName : '');
                        $jcStmtJ = $db->prepare("INSERT INTO jobs (job_no, planning_id, sales_order_id, roll_no, job_type, department, status, sequence_order, notes, extra_data) VALUES (?, ?, NULL, ?, 'Slitting', 'jumbo_slitting', 'Pending', 1, ?, ?)");
                        $jcStmtJ->bind_param('sisss', $jcNoJumbo, $pidJ, $parentRollNo, $notesJ, $extraJson);
                        $okJumbo = $jcStmtJ->execute();
                        if ($okJumbo) {
                            $jumboJobId = $db->insert_id;
                            $createdJobCards[] = ['job_no' => $jcNoJumbo, 'type' => 'Slitting', 'roll' => $parentRollNo, 'id' => $jumboJobId];
                            $insertedJumbo = true;
                            break;
                        }
                        if ((int)$jcStmtJ->errno === 1062) {
                            continue;
                        }
                        throw new Exception('Failed to create jumbo job: ' . $jcStmtJ->error);
                    }
                    if (!$insertedJumbo) {
                        throw new Exception('Failed to create jumbo job: duplicate job number, please retry.');
                    }
                }

                // Keep one downstream printing job per plan as queued.
                if ($jumboJobId > 0 && $planningId > 0) {
                    $chkP = $db->prepare("SELECT id, job_no FROM jobs WHERE job_type = 'Printing' AND planning_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY id DESC LIMIT 1");
                    $chkP->bind_param('i', $planningId);
                    $chkP->execute();
                    $existingPrint = $chkP->get_result()->fetch_assoc();
                    if (!$existingPrint) {
                        $jcNoFlex = '';
                        for ($flexAttempt = 0; $flexAttempt < 30; $flexAttempt++) {
                            $jcNoFlex = (string)(generateUniqueIdForTable($db, 'printing_job', 'jobs', 'job_no') ?? '');
                            if ($jcNoFlex === '') {
                                $jcNoFlex = deriveStageJobNo($planNo, 'FLX');
                            }
                            if ($jcNoFlex === '') {
                                $jcNoFlex = 'FLX/' . date('Y') . '/' . str_pad((string)($batchId + $flexAttempt), 4, '0', STR_PAD_LEFT);
                            }
                            if ($jcNoFlex === '') continue;

                            $notesF = 'Flexo printing queued from Jumbo | Plan: ' . ($planNo ?: 'N/A') . ' | Batch: ' . $batchNo;
                            $jcStmtF = $db->prepare("INSERT INTO jobs (job_no, planning_id, sales_order_id, roll_no, job_type, department, status, sequence_order, previous_job_id, notes) VALUES (?, ?, NULL, ?, 'Printing', 'flexo_printing', 'Queued', 2, ?, ?)");
                            $jcStmtF->bind_param('sisss', $jcNoFlex, $planningId, $parentRollNo, $jumboJobId, $notesF);
                            $okFlex = $jcStmtF->execute();
                            if ($okFlex) {
                                $createdJobCards[] = ['job_no' => $jcNoFlex, 'type' => 'Printing', 'roll' => $parentRollNo, 'id' => $db->insert_id];
                                break;
                            }
                            if ((int)$jcStmtF->errno === 1062) {
                                continue;
                            }
                            if ($flexAttempt >= 29) {
                                throw new Exception('Unable to generate unique Flexo job number. Please try again.');
                            }
                        }
                    }
                }
            }

            // 9. Update planning status to a valid planning enum state.
            if ($planNo !== '') {
                $updPlanNo = $db->prepare("UPDATE planning SET status = 'Preparing Slitting' WHERE job_no = ?");
                $updPlanNo->bind_param('s', $planNo);
                $updPlanNo->execute();

                $extraStmt = $db->prepare("SELECT id, extra_data FROM planning WHERE job_no = ? LIMIT 1");
                $extraStmt->bind_param('s', $planNo);
                $extraStmt->execute();
                $extraRow = $extraStmt->get_result()->fetch_assoc();
                if ($extraRow) {
                    $extraData = json_decode((string)($extraRow['extra_data'] ?? '{}'), true) ?: [];
                    $extraData['printing_planning'] = 'Preparing Slitting';
                    $newExtra = json_encode($extraData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $pid = (int)$extraRow['id'];
                    $updExtra = $db->prepare("UPDATE planning SET extra_data = ? WHERE id = ?");
                    $updExtra->bind_param('si', $newExtra, $pid);
                    $updExtra->execute();
                }
            } elseif ($planningId > 0) {
                $updPlan = $db->prepare("UPDATE planning SET status = 'Preparing Slitting' WHERE id = ?");
                $updPlan->bind_param('i', $planningId);
                $updPlan->execute();
            }

            // 10. Mark JOB destination children as Slitting.
            foreach ($childRolls as $ch) {
                if (($ch['dest'] ?? '') === 'JOB') {
                    $lockStmt = $db->prepare("UPDATE paper_stock SET status = 'Slitting' WHERE roll_no = ?");
                    $lockStmt->bind_param('s', $ch['roll_no']);
                    $lockStmt->execute();
                }
            }

            $db->commit();

            echo json_encode([
                'ok'       => true,
                'batch_no' => $batchNo,
                'batch_id' => $batchId,
                'parent'   => $parentRollNo,
                'children' => $childRolls,
                'remainder_action' => $remainderAction,
                'job_cards' => $createdJobCards,
                'planning_status' => ($planningId > 0 || $planNo !== '') ? 'Preparing Slitting' : null,
            ]);

        } catch (Exception $ex) {
            $db->rollback();
            echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
        }
        break;

    // ═════════════════════════════════════════════════════════
    // GET BATCH — single batch with entries
    // ═════════════════════════════════════════════════════════
    case 'get_batch':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); break; }

        $stmt = $db->prepare("SELECT * FROM slitting_batches WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();

        if (!$batch) { echo json_encode(['ok' => false, 'error' => 'Batch not found']); break; }

        $stmt2 = $db->prepare("SELECT * FROM slitting_entries WHERE batch_id = ? ORDER BY id ASC");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $entries = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $childRollNos = [];
        foreach ($entries as $entry) {
            $rollNo = trim((string)($entry['child_roll_no'] ?? ''));
            if ($rollNo !== '') $childRollNos[] = $rollNo;
        }
        $childRollNos = array_values(array_unique($childRollNos));

        // Get parent roll details for each unique parent
        $parentRolls = [];
        foreach ($entries as $e) {
            $prn = $e['parent_roll_no'];
            if (!isset($parentRolls[$prn])) {
                $pStmt = $db->prepare("SELECT * FROM paper_stock WHERE roll_no = ? LIMIT 1");
                $pStmt->bind_param('s', $prn);
                $pStmt->execute();
                $parentRolls[$prn] = $pStmt->get_result()->fetch_assoc();
            }
        }

        $jobCards = [];
        if (!empty($childRollNos)) {
            $placeholders = implode(',', array_fill(0, count($childRollNos), '?'));
            $types = str_repeat('s', count($childRollNos));
            $jobSql = "SELECT id, job_no, roll_no, job_type, department, status, planning_id, created_at
                       FROM jobs
                       WHERE roll_no IN ($placeholders)
                         AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                       ORDER BY created_at ASC, id ASC";
            $jobStmt = $db->prepare($jobSql);
            $jobStmt->bind_param($types, ...$childRollNos);
            $jobStmt->execute();
            $jobCards = $jobStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $planning = null;
        $planningId = 0;
        foreach ($jobCards as $jobCard) {
            if ((int)($jobCard['planning_id'] ?? 0) > 0) {
                $planningId = (int)$jobCard['planning_id'];
                break;
            }
        }

        if ($planningId > 0) {
            $planStmt = $db->prepare("SELECT * FROM planning WHERE id = ? LIMIT 1");
            $planStmt->bind_param('i', $planningId);
            $planStmt->execute();
            $planning = $planStmt->get_result()->fetch_assoc();
        }

        $settings = getAppSettings();
        $companyName = trim((string)($settings['company_name'] ?? 'Shree Label Creation'));
        $erpName = getErpDisplayName($companyName);
        $company = [
            'company_name' => $companyName,
            'erp_name' => $erpName,
            'company_address' => trim((string)($settings['company_address'] ?? '')),
            'company_phone' => trim((string)($settings['company_phone'] ?? '')),
            'company_mobile' => trim((string)($settings['company_mobile'] ?? '')),
            'company_email' => trim((string)($settings['company_email'] ?? '')),
            'company_gst' => trim((string)($settings['company_gst'] ?? '')),
            'logo_url' => !empty($settings['logo_path']) ? (BASE_URL . '/' . ltrim((string)$settings['logo_path'], '/')) : '',
            'footer_text' => 'Version : ' . APP_VERSION . ' | © ' . date('Y') . ' ' . $erpName . ' • ERP Master System v' . APP_VERSION . ' | @ Developed by Mriganka Bhusan Debnath',
        ];

        echo json_encode(['ok' => true, 'batch' => $batch, 'entries' => $entries, 'parents' => $parentRolls, 'job_cards' => $jobCards, 'planning' => $planning, 'company' => $company]);
        break;

    // ═════════════════════════════════════════════════════════
    // LIST BATCHES — recent history
    // ═════════════════════════════════════════════════════════
    case 'list_batches':
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $total = $db->query("SELECT COUNT(*) AS cnt FROM slitting_batches")->fetch_assoc()['cnt'];

        $stmt = $db->prepare("SELECT sb.*,
            (SELECT COUNT(*) FROM slitting_entries WHERE batch_id = sb.id AND is_remainder = 0) AS child_count,
            (SELECT GROUP_CONCAT(DISTINCT parent_roll_no) FROM slitting_entries WHERE batch_id = sb.id) AS parent_rolls
            FROM slitting_batches sb
            ORDER BY sb.created_at DESC
            LIMIT ? OFFSET ?");
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        echo json_encode(['ok' => true, 'batches' => $batches, 'total' => (int)$total, 'page' => $page, 'pages' => ceil($total / $limit)]);
        break;

    // ═════════════════════════════════════════════════════════
    // GET MACHINES — for machine selector
    // ═════════════════════════════════════════════════════════
    case 'get_machines':
        $rows = $db->query("SELECT * FROM master_machines WHERE status = 'Active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['ok' => true, 'machines' => $rows]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (Throwable $th) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $th->getMessage()]);
}
