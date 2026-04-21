<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$jobNo = trim((string)($_GET['job_no'] ?? ''));

$routeByDepartment = static function(string $department): string {
    $department = strtolower(trim($department));
    if ($department === 'one ply' || $department === 'one_ply') {
        $department = 'oneply';
    }
    if ($department === 'two ply' || $department === 'two_ply') {
        $department = 'twoply';
    }
    if ($department === 'rotary') {
        $department = 'rotery';
    }

    $map = [
        'pos' => '/modules/jobs/pos/index.php',
        'barcode' => '/modules/jobs/barcode/index.php',
        'oneply' => '/modules/jobs/oneply/index.php',
        'twoply' => '/modules/jobs/twoply/index.php',
        'rotery' => '/modules/jobs/rotery/index.php',
    ];

    return $map[$department] ?? '/modules/jobs/pos/index.php';
};

$routeByPrefix = static function(string $jobNumber): string {
    $jobNumber = strtoupper(trim($jobNumber));
    if ($jobNumber === '') {
        return '/modules/jobs/pos/index.php';
    }
    if (str_starts_with($jobNumber, 'BRC-BAR/')) {
        return '/modules/jobs/barcode/index.php';
    }
    if (str_starts_with($jobNumber, 'OPL/')) {
        return '/modules/jobs/oneply/index.php';
    }
    if (str_starts_with($jobNumber, 'TPL/')) {
        return '/modules/jobs/twoply/index.php';
    }
    if (str_starts_with($jobNumber, 'POS-PRL/') || str_starts_with($jobNumber, 'POS/')) {
        return '/modules/jobs/pos/index.php';
    }
    return '/modules/jobs/pos/index.php';
};

if ($jobNo === '') {
    redirect(appUrl('/modules/jobs/pos/index.php'));
}

$targetPath = $routeByPrefix($jobNo);
$targetJobId = 0;

$stmt = $db->prepare("SELECT id, department, job_type FROM jobs WHERE job_no = ? ORDER BY id DESC LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $jobNo);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ($job) {
        $targetJobId = (int)($job['id'] ?? 0);
        $department = trim((string)($job['department'] ?? ''));
        $jobType = trim((string)($job['job_type'] ?? ''));
        if ($department === '' && $jobType !== '') {
            $department = $jobType;
        }
        $targetPath = $routeByDepartment($department);
    }
}

$targetUrl = appUrl($targetPath);
if ($targetJobId > 0) {
    $targetUrl .= '?auto_job=' . rawurlencode((string)$targetJobId);
}

redirect($targetUrl);