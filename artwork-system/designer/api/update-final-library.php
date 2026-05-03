<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Db.php';
require_once __DIR__ . '/../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse('error', 'Invalid request method.');
}

$user = getAuthUser();
if (!$user) {
    jsonResponse('error', 'Authentication required.');
}
if (($user['role'] ?? '') !== 'admin') {
    jsonResponse('error', 'Only admin can edit final files.');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    jsonResponse('error', 'Invalid file record.');
}

$clientName = sanitize((string)($_POST['client_name'] ?? ''));
$plateNumber = sanitize((string)($_POST['plate_number'] ?? ''));
$dieNumber = sanitize((string)($_POST['die_number'] ?? ''));
$colorJob = sanitize((string)($_POST['color_job'] ?? ''));
$jobDate = sanitize((string)($_POST['job_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($clientName === '') {
    jsonResponse('error', 'Client name is required.');
}

$jobDateSql = null;
if ($jobDate !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $jobDate);
    if ($dt) {
        $jobDateSql = $dt->format('Y-m-d');
    }
}

$db = Db::getInstance();

$stmt = $db->prepare('UPDATE artwork_final_files
    SET client_name = ?, plate_number = ?, die_number = ?, color_job = ?, job_date = ?, notes = ?
    WHERE id = ? AND is_active = 1');
$stmt->execute([
    $clientName,
    $plateNumber !== '' ? $plateNumber : null,
    $dieNumber !== '' ? $dieNumber : null,
    $colorJob !== '' ? $colorJob : null,
    $jobDateSql,
    $notes !== '' ? $notes : null,
    $id,
]);

if ($stmt->rowCount() <= 0) {
    jsonResponse('error', 'No active final file found to update.');
}

jsonResponse('success', 'Final file metadata updated.');
