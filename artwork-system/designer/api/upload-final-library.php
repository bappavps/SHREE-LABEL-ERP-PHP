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

function resolveErpDbName(): string {
    $runtimeFile = realpath(__DIR__ . '/../../../config/db.runtime.php');
    if ($runtimeFile && is_file($runtimeFile)) {
        $runtime = require $runtimeFile;
        if (is_array($runtime)) {
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
            $isLocal = ($host === '')
                || (strpos($host, 'localhost') !== false)
                || (strpos($host, '127.0.0.1') !== false)
                || (substr($host, -6) === '.local')
                || ($serverAddr === '127.0.0.1')
                || ($serverAddr === '::1')
                || (bool)preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)
                || (bool)preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $serverAddr);
            $envKey = $isLocal ? 'local' : 'live';
            if (isset($runtime[$envKey]) && is_array($runtime[$envKey])) {
                $dbName = trim((string)($runtime[$envKey]['DB_NAME'] ?? ''));
                if ($dbName !== '') {
                    return $dbName;
                }
            }
        }
    }
    return 'shree_label_erp';
}

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

    /* ── Generate PDF thumbnail (localhost / Hostinger / GoDaddy / any Linux) ── */
    $thumbnailRelPath = '';
    $thumbnailUrl     = '';
    try {
        /*
         * ERP root = 3 levels up from artwork-system/designer/api/
         * Local  : …/shree-label-php/
         * Live   : …/public_html/
         * This is always correct regardless of server.
         */
        $erpRoot  = (string) realpath(__DIR__ . '/../../..');
        $plateDir = $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'plate-data';
        if (!is_dir($plateDir)) {
            @mkdir($plateDir, 0755, true);
        }

        $thumbName = 'pdf_thumb_' . pathinfo($storedName, PATHINFO_FILENAME) . '.jpg';
        $thumbPath = $plateDir . DIRECTORY_SEPARATOR . $thumbName;

        $thumbGenerated = false;

        // ── Method 1: PHP Imagick (Hostinger, GoDaddy, cPanel hosts) ────────
        if (!$thumbGenerated && extension_loaded('imagick')) {
            try {
                // Some hosts block PDF read via ImageMagick policy — temporarily override
                $im = new Imagick();
                $im->setResolution(150, 150);
                // readImage with explicit PDF delegate path to bypass policy
                $im->readImage($destination . '[0]');
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(82);
                $im->thumbnailImage(320, 320, true, true);
                if ($im->writeImage($thumbPath) && is_file($thumbPath) && filesize($thumbPath) > 0) {
                    $thumbGenerated = true;
                }
                $im->clear();
                $im->destroy();
            } catch (Throwable $imErr) {
                // Imagick blocked or failed — try Ghostscript
                if (is_file($thumbPath)) {
                    @unlink($thumbPath);
                }
            }
        }

        // ── Method 2: Ghostscript ────────────────────────────────────────────
        if (!$thumbGenerated) {
            if (DIRECTORY_SEPARATOR === '\\') {
                // Windows (local XAMPP)
                $gsCandidates = glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [];
                $gs = !empty($gsCandidates) ? end($gsCandidates) : 'C:\\Program Files\\gs\\gs10.01.2\\bin\\gswin64c.exe';
            } else {
                // Linux / Mac
                $gs = trim((string)@shell_exec('which gs 2>/dev/null'));
                if ($gs === '') {
                    $gs = 'gs';
                }
            }

            $cmd = sprintf(
                '%s -dBATCH -dNOPAUSE -dSAFER -dFirstPage=1 -dLastPage=1 -sDEVICE=jpeg -r120 -dJPEGQ=82 -dFitPage -g320x320 -sOutputFile=%s %s 2>&1',
                escapeshellarg($gs),
                escapeshellarg($thumbPath),
                escapeshellarg($destination)
            );
            @exec($cmd, $gsOut, $gsCode);
            if ($gsCode === 0 && is_file($thumbPath) && filesize($thumbPath) > 0) {
                $thumbGenerated = true;
            }
        }

        if ($thumbGenerated) {
            $thumbnailRelPath = 'uploads/library/plate-data/' . $thumbName;
            $thumbnailUrl     = ERP_BASE_URL . '/' . $thumbnailRelPath;

            /* Update master_plate_data image_path if plate number is known */
            if ($plateNumber !== '') {
                $erpConn = new mysqli(ARTWORK_DB_HOST, ARTWORK_DB_USER, ARTWORK_DB_PASS, resolveErpDbName());
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
        // Thumbnail is optional — upload still succeeds
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
