<?php
require __DIR__ . '/config/db.php';
$db = getDB();
$target = 'PLN-PRL/2026/0001';
$esc = $db->real_escape_string($target);

$parse = function($raw){
    if ($raw === null || $raw === '') return [null, null];
    $j = json_decode($raw, true);
    if (!is_array($j)) return [null, null];
    return [$j['printing_planning'] ?? null, $j['timer_state'] ?? null];
};

$pRes = $db->query("SELECT id, job_no, status, department, extra_data FROM planning WHERE job_no='" . $esc . "' LIMIT 1");
$plan = $pRes ? $pRes->fetch_assoc() : null;

echo "=== planning ===\n";
if (!$plan) {
    echo "not found\n";
} else {
    [$pp, $ts] = $parse($plan['extra_data']);
    echo json_encode([
        'id' => $plan['id'],
        'job_no' => $plan['job_no'],
        'status' => $plan['status'],
        'department' => $plan['department'],
        'extra_data' => $plan['extra_data'],
        'printing_planning' => $pp,
        'timer_state' => $ts,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

$pid = $plan['id'] ?? 0;
echo "=== jobs_by_planning_id ===\n";
if ($pid) {
    $jRes = $db->query("SELECT id, job_no, department, job_type, status, previous_job_id, completed_at, extra_data FROM jobs WHERE planning_id=" . (int)$pid . " ORDER BY id");
    if ($jRes && $jRes->num_rows) {
        while ($r = $jRes->fetch_assoc()) {
            [$pp, $ts] = $parse($r['extra_data']);
            $r['printing_planning'] = $pp;
            $r['timer_state'] = $ts;
            echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
        }
    } else {
        echo "none\n";
    }
} else {
    echo "planning_missing\n";
}

echo "=== jobs_notes_match ===\n";
$like = $db->real_escape_string('Plan: ' . $target);
$nRes = $db->query("SELECT id, job_no, planning_id, department, job_type, status, previous_job_id, completed_at, notes, extra_data FROM jobs WHERE notes LIKE '%" . $like . "%' ORDER BY id");
if ($nRes && $nRes->num_rows) {
    while ($r = $nRes->fetch_assoc()) {
        [$pp, $ts] = $parse($r['extra_data']);
        $r['printing_planning'] = $pp;
        $r['timer_state'] = $ts;
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
    }
} else {
    echo "none\n";
}
