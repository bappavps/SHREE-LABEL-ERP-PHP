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
$jobName = sanitize((string)($_POST['job_name'] ?? ''));
$plateNumber = sanitize((string)($_POST['plate_number'] ?? ''));
$dieNumber = sanitize((string)($_POST['die_number'] ?? ''));
$colorJob = sanitize((string)($_POST['color_job'] ?? ''));
$jobDate = sanitize((string)($_POST['job_date'] ?? ''));
$dateReceived = sanitize((string)($_POST['date_received'] ?? ''));
$jobSize = sanitize((string)($_POST['job_size'] ?? ''));
$paperSize = sanitize((string)($_POST['paper_size'] ?? ''));
$paperType = sanitize((string)($_POST['paper_type'] ?? ''));
$makeBy = sanitize((string)($_POST['make_by'] ?? ''));
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
        if ($dt) { $jobDateSql = $dt->format('Y-m-d'); }
    }
    $dateReceivedSql = null;
    if ($dateReceived !== '') {
        $dt2 = DateTime::createFromFormat('Y-m-d', $dateReceived);
        if ($dt2) { $dateReceivedSql = $dt2->format('Y-m-d'); }
        if (!$dt2) {
            // Try common text format from plate master e.g. "01-Jan-2024"
            $dt2b = DateTime::createFromFormat('d-M-Y', $dateReceived);
            if ($dt2b) { $dateReceivedSql = $dt2b->format('Y-m-d'); }
        }
    }

    $insert = $db->prepare('INSERT INTO artwork_final_files
        (project_id, client_name, job_name, plate_number, die_number, color_job, job_date, date_received, job_size, paper_size, paper_type, make_by, original_name, stored_name, file_size, mime_type, uploaded_by, uploaded_by_name, notes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    $insert->execute([
        $projectId > 0 ? $projectId : null,
        $resolvedClient,
        $jobName !== '' ? $jobName : null,
        $plateNumber !== '' ? $plateNumber : null,
        $dieNumber !== '' ? $dieNumber : null,
        $colorJob !== '' ? $colorJob : null,
        $jobDateSql,
        $dateReceivedSql,
        $jobSize !== '' ? $jobSize : null,
        $paperSize !== '' ? $paperSize : null,
        $paperType !== '' ? $paperType : null,
        $makeBy !== '' ? $makeBy : null,
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

    /* ── Generate PDF thumbnail via Ghostscript ─────────────────────── */
    $thumbnailRelPath = '';
    $thumbnailUrl     = '';
    try {
        $gs = 'C:\\Program Files\\gs\\gs10.01.2\\bin\\gswin64c.exe';
        // artwork-system/designer/api/ → up 3 levels = shree-label-php root
        $erpRoot  = realpath(__DIR__ . '/../../../..');
        $plateDir = $erpRoot . DIRECTORY_SEPARATOR . 'shree-label-php' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'plate-data';
        if (!is_dir($plateDir)) {
            @mkdir($plateDir, 0755, true);
        }

        $thumbName = 'pdf_thumb_' . pathinfo($storedName, PATHINFO_FILENAME) . '.jpg';
        $thumbPath = $plateDir . DIRECTORY_SEPARATOR . $thumbName;

        $cmd = sprintf(
            '"%s" -dBATCH -dNOPAUSE -dSAFER -dFirstPage=1 -dLastPage=1 -sDEVICE=jpeg -r120 -dJPEGQ=82 -dFitPage -g320x320 -sOutputFile="%s" "%s" 2>&1',
            $gs,
            $thumbPath,
            $destination
        );
        exec($cmd, $gsOut, $gsCode);

        if ($gsCode === 0 && is_file($thumbPath) && filesize($thumbPath) > 0) {
            $thumbnailRelPath = 'uploads/library/plate-data/' . $thumbName;
            $thumbnailUrl     = ERP_BASE_URL . '/' . $thumbnailRelPath;

            /* Update master_plate_data image_path if plate number is known */
            if ($plateNumber !== '') {
                $erpConn = new mysqli(ARTWORK_DB_HOST, ARTWORK_DB_USER, ARTWORK_DB_PASS, 'shree_label_erp');
                if (!$erpConn->connect_error) {
                    $upd = $erpConn->prepare("UPDATE master_plate_data SET image_path = ? WHERE plate = ? LIMIT 1");
                    if ($upd) {
                        $upd->bind_param('ss', $thumbnailRelPath, $plateNumber);
                        $upd->execute();
                        $upd->close();
                    }
                    $erpConn->close();
                }
            }
        }
    } catch (Throwable $thumbErr) {
        // Thumbnail is optional — don't fail the upload
        $thumbnailUrl = '';
    }

    jsonResponse('success', 'Final PDF uploaded successfully.', [
        'id'            => (int)$db->lastInsertId(),
        'stored_name'   => $storedName,
        'original_name' => $originalName,
        'thumbnail_url' => $thumbnailUrl,
        'plate_number'  => $plateNumber,
        'client_name'   => $resolvedClient,
        'job_name'      => $jobName,
    ]);
} catch (Throwable $e) {
    jsonResponse('error', $e->getMessage());
}
