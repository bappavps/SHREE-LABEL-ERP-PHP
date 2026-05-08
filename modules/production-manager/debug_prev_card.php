<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
$db = getDB();

// Simulate the previous active card logic
$cards = [];
$sql = "SELECT id, job_no, department, job_type, status FROM jobs WHERE planning_id = 1 ORDER BY id ASC";
$result = $db->query($sql);
while ($row = $result->fetch_assoc()) {
    $cards[] = [
        'job_id' => (int)$row['id'],
        'job_no' => $row['job_no'],
        'stage' => $row['department'], // Simplified
        'status' => $row['status'],
    ];
}

// Simulate pm_stage_rank_for_label
$rankMap = [
    'jumbo_slitting' => 1,
    'flexo_printing' => 2,
    'label_slitting' => 4,
    'packing' => 5,
];

function getCardRank($stage) {
    global $rankMap;
    $stageKey = strtolower(str_replace(['_', ' '], '_', $stage));
    return $rankMap[$stageKey] ?? 0;
}

function isStartedStatus($status) {
    $norm = strtolower(trim(str_replace(['-', '_'], ' ', $status)));
    if ($norm === '') return false;
    if (in_array($norm, ['pending', 'queued', 'preparing'], true)) return false;
    return true;
}

$currentDept = 'packing'; // Current stage
$currentRank = getCardRank($currentDept);

echo "Current stage: $currentDept (rank $currentRank)\n";
echo "Cards:\n";
foreach ($cards as $card) {
    $rank = getCardRank($card['stage']);
    $isStarted = isStartedStatus($card['status']);
    echo "  - {$card['job_no']} ({$card['stage']}, rank=$rank, status={$card['status']}, started=$isStarted)\n";
}

// Find previous card
$previousCard = null;
foreach ($cards as $card) {
    $cardRank = getCardRank($card['stage']);
    $cardStatus = $card['status'];
    
    if (!isStartedStatus($cardStatus)) {
        echo "Skipping {$card['job_no']}: status not started\n";
        continue;
    }
    if ($cardRank >= $currentRank) {
        echo "Skipping {$card['job_no']}: rank $cardRank >= currentRank $currentRank\n";
        continue;
    }
    
    if ($previousCard === null) {
        echo "Setting previousCard to {$card['job_no']} (first candidate)\n";
        $previousCard = $card;
    } else {
        $prevRank = getCardRank($previousCard['stage']);
        if ($cardRank > $prevRank) {
            echo "Replacing previousCard with {$card['job_no']} (rank $cardRank > prevRank $prevRank)\n";
            $previousCard = $card;
        } elseif ($cardRank === $prevRank && $card['job_id'] > $previousCard['job_id']) {
            echo "Replacing previousCard with {$card['job_no']} (same rank, higher job_id)\n";
            $previousCard = $card;
        } else {
            echo "Keeping previousCard as {$previousCard['job_no']} (candidate {$card['job_no']} is worse)\n";
        }
    }
}

echo "\nResult: ";
if ($previousCard) {
    echo "Previous Active Card = {$previousCard['job_no']}\n";
} else {
    echo "No previous card\n";
}
