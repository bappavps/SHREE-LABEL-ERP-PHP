<?php
require 'config/db.php';
$db = getDB();
$sql = "
SELECT j.id, j.job_no, j.status, j.department,
       p.department AS plan_department, p.job_type,
       poe.submitted_lock, poe.submitted_at
FROM jobs j
LEFT JOIN planning p ON p.id = j.planning_id
LEFT JOIN packing_operator_entries poe ON poe.job_no = j.job_no
WHERE (j.deleted_at IS NULL OR j.deleted_at='0000-00-00 00:00:00')
  AND (
    LOWER(COALESCE(j.department,'')) IN ('pos','pos roll','pos_roll')
    OR LOWER(COALESCE(p.job_type,'')) IN ('pos_roll','pos')
    OR LOWER(COALESCE(p.department,'')) IN ('paperroll')
  )
ORDER BY j.id DESC
LIMIT 120
";
$res = $db->query($sql);
if (!$res) {
    echo 'query failed: ' . $db->error . PHP_EOL;
    exit(1);
}
while ($r = $res->fetch_assoc()) {
    echo implode(' | ', [
        str_pad((string)$r['id'], 5, ' ', STR_PAD_LEFT),
        (string)($r['job_no'] ?? ''),
        (string)($r['status'] ?? ''),
        (string)($r['department'] ?? ''),
        (string)($r['plan_department'] ?? ''),
        (string)($r['job_type'] ?? ''),
        (string)($r['submitted_lock'] ?? 'NULL'),
        (string)($r['submitted_at'] ?? 'NULL'),
    ]) . PHP_EOL;
}
