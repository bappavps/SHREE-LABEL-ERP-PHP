<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Log the previous active card determination for planning_id = 1
$sql = "SELECT id, job_no, department, status, updated_at FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$result = $db->query($sql);
$cards = [];
$log = [];

while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
    $log[] = "Job {$row['job_no']}: dept={$row['department']}, status={$row['status']}";
}

// Simulate previous card selection with HIGH RANK comparison
$rankMap = ['jumbo_slitting' => 1, 'flexo_printing' => 2, 'label_slitting' => 4, 'packing' => 5];

$currentRank = 5; // packing
$previousCard = null;
$selection_log = [];

foreach ($cards as $card) {
    $cardRank = $rankMap[$card['department']] ?? 0;
    $isStarted = !in_array(strtolower($card['status']), ['pending', 'queued', 'preparing', '']);
    
    $selection_log[] = "Checking {$card['job_no']}: rank=$cardRank, isStarted=" . ($isStarted ? 'yes' : 'no') . ", cardRank < currentRank=" . ($cardRank < $currentRank ? 'yes' : 'no');
    
    if (!$isStarted) continue;
    if ($cardRank >= $currentRank) continue;
    
    if ($previousCard === null) {
        $previousCard = $card;
        $selection_log[] = "  -> Selected {$card['job_no']} (first)";
    } else {
        $prevRank = $rankMap[$previousCard['department']] ?? 0;
        if ($cardRank > $prevRank) {
            $selection_log[] = "  -> Replacing {$previousCard['job_no']} with {$card['job_no']} (rank $cardRank > $prevRank)";
            $previousCard = $card;
        } else {
            $selection_log[] = "  -> Keeping {$previousCard['job_no']} (rank $cardRank <= $prevRank)";
        }
    }
}

$result_job = $previousCard ? $previousCard['job_no'] : '-';

echo json_encode([
    'cards' => $log,
    'selection_process' => $selection_log,
    'result' => $result_job
], JSON_PRETTY_PRINT);
