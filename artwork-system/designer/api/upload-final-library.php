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

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$clientName = sanitize((string)($_POST['client_name'] ?? ''));
$plateNumber = sanitize((string)($_POST['plate_number'] ?? ''));
$dieNumber = sanitize((string)($_POST['die_number'] ?? ''));
$colorJob = sanitize((string)($_POST['color_job'] ?? ''));
$jobDate = sanitize((string)($_POST['job_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if (!isset($_FILES['final_pdf'])) {
    jsonResponse('error', 'PDF file is required.');
}

$validation = validateFinalPdfUpload($_FILES['final_pdf']);
if (empty($validation['valid'])) {
    jsonResponse('error', (string)($validation['message'] ?? 'Invalid file upload.'));
}

$db = Db::getInstance();

try {
    $resolvedClient = $clientName;
    if ($projectId > 0) {
        $stmt = $db->prepare('SELECT id, client_name FROM artwork_projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();
        if (!$project) {
            throw new RuntimeException('Project not found.');
        }
        if ($resolvedClient === '') {
            $resolvedClient = (string)($project['client_name'] ?? '');
        }
    }

    if ($resolvedClient === '') {
        throw new RuntimeException('Client name is required.');
    }

    $tmpName = (string)$_FILES['final_pdf']['tmp_name'];
    $originalName = (string)($_FILES['final_pdf']['name'] ?? 'final.pdf');
    $ext = (string)$validation['ext'];
    $storedName = 'finalsrv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destination = UPLOAD_FINAL_DIR . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Failed to store uploaded PDF on server.');
    }

    $jobDateSql = null;
    if ($jobDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $jobDate);
        if ($dt) {
            $jobDateSql = $dt->format('Y-m-d');
        }
    }

    $insert = $db->prepare('INSERT INTO artwork_final_files
        (project_id, client_name, plate_number, die_number, color_job, job_date, original_name, stored_name, file_size, mime_type, uploaded_by, uploaded_by_name, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    $insert->execute([
        $projectId > 0 ? $projectId : null,
        $resolvedClient,
        $plateNumber !== '' ? $plateNumber : null,
        $dieNumber !== '' ? $dieNumber : null,
        $colorJob !== '' ? $colorJob : null,
        $jobDateSql,
        $originalName,
        $storedName,
        (int)$validation['size'],
        (string)$validation['mime'],
        (int)$user['id'],
        (string)$user['name'],
        $notes !== '' ? $notes : null,
    ]);

    if ($projectId > 0) {
        $log = $db->prepare('INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)');
        $log->execute([$projectId, 'Final file server upload: ' . $originalName]);
    }

    jsonResponse('success', 'Final PDF uploaded successfully.', [
        'id' => (int)$db->lastInsertId(),
        'stored_name' => $storedName,
    ]);
} catch (Throwable $e) {
    jsonResponse('error', $e->getMessage());
}
