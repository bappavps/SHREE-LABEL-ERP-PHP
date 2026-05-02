<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Db.php';
require_once __DIR__ . '/includes/functions.php';

$erpLogoUrl = ERP_BASE_URL . '/pwa_icon.php?size=192';

$token = isset($_GET['token']) ? sanitize($_GET['token']) : '';

if (!$token) die("Invalid access link.");

$db = Db::getInstance();

// Get Project
$stmt = $db->prepare("SELECT * FROM artwork_projects WHERE token = ?");
$stmt->execute([$token]);
$project = $stmt->fetch();

if (!$project) die("Project not found or link expired.");

// Get Latest File
$stmt = $db->prepare("SELECT * FROM artwork_files WHERE project_id = ? ORDER BY version DESC LIMIT 1");
$stmt->execute([$project['id']]);
$file = $stmt->fetch();

if (!$file) die("No artwork uploaded yet.");

$currentFileType = strtolower((string) ($file['file_type'] ?? ''));
$currentFileExt = strtolower((string) pathinfo((string) ($file['filename'] ?? ''), PATHINFO_EXTENSION));
$isCurrentPdf = ($currentFileType === 'pdf' || $currentFileExt === 'pdf');
$isCurrentImage = in_array($currentFileType, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
    || in_array($currentFileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

$openHub = isset($_GET['open']) && $_GET['open'] === '1';
if (!$openHub) {
    $openHubUrl = ARTWORK_BASE_URL . '/review.php?token=' . rawurlencode($token) . '&open=1';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome: <?php echo sanitize($project['title']); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', sans-serif;
            color: #0f172a;
            background:
                radial-gradient(circle at 18% 18%, rgba(16, 185, 129, 0.28), transparent 42%),
                radial-gradient(circle at 86% 86%, rgba(14, 165, 233, 0.24), transparent 40%),
                linear-gradient(160deg, #f8fafc 0%, #e2e8f0 100%);
            display: grid;
            place-items: center;
            padding: 1.2rem;
        }
        .intro-shell {
            width: min(980px, 100%);
            max-width: 940px;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.34);
            box-shadow: 0 40px 80px -36px rgba(15, 23, 42, 0.45);
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            display: grid;
            grid-template-columns: 1.05fr 1fr;
        }
        .intro-shell-wrap {
            width: min(980px, 100%);
            max-width: 940px;
        }
        .intro-visual {
            min-height: 400px;
            background: linear-gradient(160deg, #0d1f2d 0%, #0f3d38 60%, #0f766e 100%);
            color: #ffffff;
            padding: 1.6rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }
        .intro-visual::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 10% 10%, rgba(20,184,166,0.22), transparent 55%),
                radial-gradient(circle at 90% 90%, rgba(14,165,233,0.18), transparent 50%);
            pointer-events: none;
        }
        .intro-brand {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            position: relative;
            z-index: 1;
            opacity: 0.92;
        }
        .intro-brand img {
            width: 26px;
            height: 26px;
            object-fit: contain;
            background: #fff;
            border-radius: 6px;
            padding: 2px;
        }
        .intro-footer-note {
            margin-top: 0.7rem;
            font-size: 0.82rem;
            line-height: 1.25;
            color: #000000;
            font-family: 'Segoe UI', Arial, sans-serif;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-shadow: none;
            font-weight: 700;
            letter-spacing: 0.01em;
            -webkit-font-smoothing: antialiased;
            text-rendering: geometricPrecision;
        }
        .artwork-preview-wrap {
            flex: 0 0 auto;
            border-radius: 14px;
            overflow: hidden;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.14);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            min-height: 170px;
            height: clamp(170px, 24vh, 210px);
            max-width: 100%;
        }
        .artwork-preview-wrap img {
            width: auto;
            max-width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        #intro-pdf-canvas {
            width: auto !important;
            max-width: 100% !important;
            height: 100% !important;
            object-fit: contain;
            display: block;
        }
        .artwork-preview-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
            color: rgba(255,255,255,0.5);
            font-size: 0.85rem;
        }
        .artwork-preview-placeholder i { font-size: 2.5rem; }
        .intro-caption {
            position: relative;
            z-index: 1;
        }
        .intro-visual h1 {
            margin: 0;
            font-size: clamp(1.2rem, 2.5vw, 1.7rem);
            line-height: 1.2;
            letter-spacing: -0.02em;
        }
        .intro-visual p {
            margin: 0.4rem 0 0;
            color: rgba(255,255,255,0.78);
            font-size: 0.82rem;
            line-height: 1.5;
        }
        .intro-right {
            padding: 2rem;
        }
        .section-title {
            margin: 0 0 0.3rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            font-size: 1.45rem;
        }
        .section-sub {
            margin: 0 0 1.2rem;
            color: #64748b;
            font-size: 0.9rem;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.7rem;
            margin-bottom: 0.9rem;
        }
        .detail-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.7rem 0.75rem;
            background: #ffffff;
        }
        .detail-label {
            margin: 0;
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 700;
        }
        .detail-value {
            margin: 0.3rem 0 0;
            font-size: 0.86rem;
            color: #0f172a;
            font-weight: 700;
            word-break: break-word;
        }
        .remark {
            margin: 0;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 0.8rem;
            background: #f8fafc;
            font-size: 0.84rem;
            line-height: 1.5;
            color: #334155;
            min-height: 66px;
            white-space: pre-wrap;
        }
        .open-btn {
            margin-top: 1.25rem;
            width: 100%;
            border: 0;
            border-radius: 14px;
            padding: 0.88rem 1rem;
            background: linear-gradient(120deg, #0f766e, #14b8a6);
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 0.45rem;
            box-shadow: 0 12px 30px -16px rgba(20, 184, 166, 0.75);
        }
        @media (max-width: 900px) {
            .intro-shell { grid-template-columns: 1fr; }
            .intro-visual { min-height: 260px; }
            .detail-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="intro-shell-wrap">
    <div class="intro-shell">
        <div class="intro-visual">
            <div class="intro-brand"><img src="<?php echo sanitize($erpLogoUrl); ?>" alt="ERP Logo"> <?php echo sanitize(APP_NAME); ?></div>
            <div class="artwork-preview-wrap" id="artworkPreviewWrap">
                <?php if ($isCurrentImage): ?>
                    <img src="<?php echo ARTWORK_BASE_URL; ?>/uploads/projects/<?php echo sanitize($file['filename']); ?>" alt="Artwork Preview">
                <?php elseif ($isCurrentPdf): ?>
                    <canvas id="intro-pdf-canvas"></canvas>
                <?php else: ?>
                    <div class="artwork-preview-placeholder">
                        <i class="fas fa-file-alt"></i>
                        <span>Artwork Preview</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="intro-caption">
                <h1><?php echo sanitize($project['title']); ?></h1>
                <p>Review the job details and open the artwork approval workspace when ready.</p>
            </div>
        </div>
        <div class="intro-right">
            <h2 class="section-title">Job Details</h2>
            <p class="section-sub">Client briefing information before opening the artwork approval canvas.</p>

            <div class="detail-grid">
                <div class="detail-card">
                    <p class="detail-label">Client</p>
                    <p class="detail-value"><?php echo sanitize($project['client_name'] ?: 'N/A'); ?></p>
                </div>
                <div class="detail-card">
                    <p class="detail-label">Job Name</p>
                    <p class="detail-value"><?php echo sanitize($project['job_name'] ?: 'N/A'); ?></p>
                </div>
                <div class="detail-card">
                    <p class="detail-label">Job Size</p>
                    <p class="detail-value"><?php echo sanitize($project['job_size'] ?? 'N/A'); ?></p>
                </div>
                <div class="detail-card">
                    <p class="detail-label">Job Color</p>
                    <p class="detail-value"><?php echo sanitize($project['job_color'] ?? 'N/A'); ?></p>
                </div>
            </div>

            <p class="detail-label" style="margin:0 0 0.3rem;">Remark</p>
            <p class="remark"><?php echo sanitize($project['job_remark'] ?? 'No remarks added for this project.'); ?></p>

            <a href="<?php echo $openHubUrl; ?>" class="open-btn"><i class="fas fa-eye"></i> Open Artwork Approval Hub</a>
        </div>
    </div>
    <div class="intro-footer-note">Version : <?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?> &bull; &copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &bull; ERP Master System v<?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?> | @ Developed by Mriganka Bhusan Debnath</div>
    </div>
<?php if ($isCurrentPdf): ?>
<script>
(function() {
    var PDFJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js';
    var script = document.createElement('script');
    script.src = PDFJS_CDN;
    script.onload = function() {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
        fetch('<?php echo ARTWORK_BASE_URL; ?>/preview.php?id=<?php echo (int)$file['id']; ?>')
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (!data.data || !data.data.bytes) return;
                var bytes = atob(data.data.bytes);
                var arr = new Uint8Array(bytes.length);
                for (var i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
                return pdfjsLib.getDocument({ data: arr }).promise;
            })
            .then(function(pdf) { return pdf.getPage(1); })
            .then(function(page) {
                var wrap = document.getElementById('artworkPreviewWrap');
                var canvas = document.getElementById('intro-pdf-canvas');
                var vp = page.getViewport({ scale: 1 });
                var scale = Math.min((wrap.clientWidth || 320) / vp.width, (wrap.clientHeight || 280) / vp.height, 2.5);
                var sv = page.getViewport({ scale: scale });
                canvas.width = sv.width;
                canvas.height = sv.height;
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: sv }).promise;
            })
            .catch(function(e){ console.warn('PDF intro thumb:', e); });
    };
    document.head.appendChild(script);
})();
</script>
<?php endif; ?>
</body>
</html>
<?php
    exit;
}

// Get Comments for this file
$stmt = $db->prepare("SELECT * FROM artwork_comments WHERE file_id = ? AND parent_id IS NULL ORDER BY created_at ASC");
$stmt->execute([$file['id']]);
$comments = $stmt->fetchAll();

// Full File Journey (for client/designer/admin visibility)
$stmt = $db->prepare("SELECT id, filename, original_name, file_type, version, uploaded_at, is_final FROM artwork_files WHERE project_id = ? ORDER BY version ASC");
$stmt->execute([$project['id']]);
$projectFiles = $stmt->fetchAll();

$stmt = $db->prepare("SELECT action, created_at FROM artwork_activity_log WHERE project_id = ? ORDER BY created_at DESC LIMIT 40");
$stmt->execute([$project['id']]);
$projectActivities = $stmt->fetchAll();

$stmt = $db->prepare("SELECT COUNT(*) FROM artwork_comments c JOIN artwork_files f ON f.id = c.file_id WHERE f.project_id = ?");
$stmt->execute([$project['id']]);
$totalCorrections = (int) $stmt->fetchColumn();

$firstUploadedAt = !empty($projectFiles) ? $projectFiles[0]['uploaded_at'] : null;
$latestUploadedAt = !empty($projectFiles) ? $projectFiles[count($projectFiles) - 1]['uploaded_at'] : null;

// Detect if viewer is a logged-in designer/admin (not a client)
$authUser = getAuthUser();
$isDesigner = $authUser !== null && in_array($authUser['role'], ['designer', 'admin'], true);
$projectStatus = $project['status'] ?? 'pending';
$isApproved = ($projectStatus === 'approved');

function annotationLabel(array $comment, int $index): string {
    $type = $comment['type'] ?? 'point';
    if ($type === 'area') {
        return 'Area #' . $index;
    }
    if ($type === 'arrow') {
        return 'Arrow #' . $index;
    }
    if ($type === 'pen') {
        return 'Markup #' . $index;
    }
    if ($type === 'highlighter') {
        return 'Highlight #' . $index;
    }
    return 'Pin #' . $index;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review: <?php echo sanitize($project['title']); ?> - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0f766e;
            --primary-gradient: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
            --tool-point: #0ea5e9;
            --tool-area: #8b5cf6;
            --tool-arrow: #f97316;
            --tool-pen: #0f766e;
            --tool-highlighter: #eab308;
            --bg-color: #e8eff2;
            --sidebar-bg: #ffffff;
            --text-main: #132228;
            --text-muted: #5a737b;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.3);
            --status-approved: #16a34a;
            --status-changes: #dc2626;
            --radius-lg: 20px;
        }

        body { 
            background: #f1f5f9; 
            margin: 0; 
            display: flex; 
            flex-direction: column; 
            height: 100vh; 
            overflow: hidden; 
            font-family: 'Manrope', sans-serif;
            color: var(--text-main);
        }

        .review-header { 
            background: white; 
            padding: 1.25rem 2.5rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
            z-index: 100; 
        }
        .erp-brand-wrap {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
        }
        .erp-brand-wrap img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            background: #fff;
            border-radius: 6px;
            padding: 2px;
            border: 1px solid #e2e8f0;
        }
        .erp-brand-text {
            font-size: 0.92rem;
            font-weight: 800;
            color: #0f172a;
        }
        .app-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            background: #1f2937;
            padding: 10px 18px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .app-footer-left,
        .app-footer-right {
            font-size: 0.74rem;
            color: #d1d5db;
            line-height: 1.35;
            font-weight: 500;
            white-space: nowrap;
        }

        .review-header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn-toggle-comments {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 1rem;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .btn-toggle-comments:hover {
            background: #dbeafe;
            box-shadow: 0 2px 8px rgba(59,130,246,0.15);
        }
        .btn-toggle-comments.active {
            background: #1d4ed8;
            color: #fff;
            border-color: #1d4ed8;
        }
        .btn-toggle-comments .comment-count {
            background: #ef4444;
            color: #fff;
            border-radius: 10px;
            padding: 0 5px;
            font-size: 0.7rem;
            font-weight: 700;
            min-width: 18px;
            text-align: center;
            display: inline-block;
        }

        .btn-print-design {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            border-radius: 999px;
            padding: 0.55rem 1rem;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
        }

        .btn-print-design:hover {
            border-color: #0f766e;
            color: #0f766e;
            background: #f0fdfa;
        }

        .review-container { 
            flex: 1; 
            display: flex; 
            overflow: visible; 
            position: relative;
            min-height: 0;
        }

        .toolbar {
            position: absolute;
            left: 2rem;
            top: 50%;
            transform: translateY(-50%);
            background: linear-gradient(180deg, rgba(236, 253, 245, 0.96) 0%, rgba(207, 250, 254, 0.96) 100%);
            border: 1px solid rgba(20, 184, 166, 0.28);
            padding: 0.75rem;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            box-shadow: 0 24px 48px -22px rgba(15, 118, 110, 0.45), 0 12px 24px -16px rgba(14, 116, 144, 0.35);
            z-index: 1000;
            user-select: none;
            backdrop-filter: blur(10px);
        }

        .toolbar.dragging {
            cursor: grabbing;
            box-shadow: 0 26px 50px -18px rgba(15, 23, 42, 0.45);
        }

        .toolbar-handle {
            width: 44px;
            height: 22px;
            border-radius: 10px;
            border: 1px solid rgba(13, 148, 136, 0.35);
            background: rgba(204, 251, 241, 0.95);
            color: #0f766e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            cursor: grab;
            margin: 0 auto 0.15rem;
        }

        .comment-box-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }

        .comment-box-close {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            transition: all 0.2s;
        }

        .comment-box-close:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .toolbar-divider {
            width: 100%;
            height: 1px;
            background: rgba(45, 212, 191, 0.38);
            margin: 0.25rem 0;
        }

        .color-picker {
            display: flex;
            flex-direction: row;
            gap: 0.5rem;
            align-items: center;
            justify-content: center;
            padding: 0.2rem 0;
        }

        .tool-status-pill {
            position: absolute;
            top: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            background: rgba(19, 34, 40, 0.82);
            color: #fff;
            padding: 0.55rem 1rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.18);
        }

        .tool-guide {
            position: absolute;
            top: 4.1rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            background: rgba(255, 255, 255, 0.82);
            color: var(--text-muted);
            border: 1px solid rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            padding: 0.55rem 0.9rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }

        .tool-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid rgba(20, 184, 166, 0.25);
            background: rgba(255, 255, 255, 0.9);
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1.1rem;
            position: relative;
        }

        .tool-btn:hover, .tool-btn.active {
            background: linear-gradient(135deg, #06b6d4 0%, #14b8a6 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 20px -8px rgba(6, 182, 212, 0.75);
        }

        .tool-btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            left: 100%;
            margin-left: 10px;
            background: #1e293b;
            color: white;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.7rem;
            white-space: nowrap;
            z-index: 1001;
        }

        .artwork-viewer { 
            flex: 1; 
            overflow: hidden; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            position: relative; 
            background: #e2eff2;
            background-image: radial-gradient(#b8cad2 1px, transparent 1px);
            background-size: 20px 20px;
            cursor: pointer;
        }

        .ruler-top,
        .ruler-left {
            position: absolute;
            background: rgba(255, 255, 255, 0.92);
            border-color: #cbd5e1;
            z-index: 14;
            pointer-events: auto;
            backdrop-filter: blur(4px);
            user-select: none;
        }

        .ruler-top {
            top: 0;
            left: 24px;
            right: 0;
            height: 24px;
            border-bottom: 1px solid #cbd5e1;
            background-image:
                repeating-linear-gradient(to right, transparent 0, transparent 49px, rgba(15, 118, 110, 0.35) 49px, rgba(15, 118, 110, 0.35) 50px);
            cursor: col-resize;
        }

        .ruler-left {
            top: 24px;
            left: 0;
            bottom: 0;
            width: 24px;
            border-right: 1px solid #cbd5e1;
            background-image:
                repeating-linear-gradient(to bottom, transparent 0, transparent 49px, rgba(15, 118, 110, 0.35) 49px, rgba(15, 118, 110, 0.35) 50px);
            cursor: row-resize;
        }

        .ruler-corner {
            position: absolute;
            top: 0;
            left: 0;
            width: 24px;
            height: 24px;
            background: rgba(241, 245, 249, 0.95);
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            z-index: 15;
            pointer-events: none;
        }

        .ruler-guides-layer {
            position: absolute;
            inset: 0;
            z-index: 13;
            pointer-events: none;
        }

        .ruler-guide {
            position: absolute;
            pointer-events: auto;
        }

        .ruler-guide.vertical {
            left: 24px;
            top: 24px;
            bottom: 0;
            width: 0;
            border-left: 1px dashed rgba(15, 118, 110, 0.8);
            cursor: col-resize;
        }

        .ruler-guide.horizontal {
            left: 24px;
            right: 0;
            height: 0;
            border-top: 1px dashed rgba(15, 118, 110, 0.8);
            cursor: row-resize;
        }

        .ruler-guide::after {
            content: attr(data-value);
            position: absolute;
            background: rgba(15, 118, 110, 0.92);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 5px;
            border-radius: 6px;
            pointer-events: none;
            line-height: 1.1;
        }

        .ruler-guide.vertical::after {
            top: 3px;
            left: 6px;
        }

        .ruler-guide.horizontal::after {
            top: 6px;
            left: 3px;
        }

        .artwork-wrapper { 
            position: relative; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); 
            background: white; 
            line-height: 0; 
            border-radius: 4px;
            transform-origin: 0 0;
            cursor: inherit !important;
            transition: opacity 120ms ease;
        }

        .artwork-wrapper.artwork-loading {
            opacity: 0;
        }

        .artwork-wrapper img { 
            max-width: none; /* Required for zoom */
            height: auto; 
            display: block;
        }

        .history-sidebar {
            width: 340px;
            min-width: 260px;
            background: linear-gradient(180deg, #f8fbff 0%, #f1f5ff 55%, #eef2ff 100%);
            border-right: 1px solid #bfdbfe;
            overflow-y: auto;
            position: relative;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        
        .comment-sidebar { 
            position: absolute;
            right: 1rem;
            top: 4rem;
            bottom: 1rem;
            width: 380px;
            min-width: 220px;
            max-width: 560px;
            background: linear-gradient(180deg, #eff6ff 0%, #f8fbff 45%, #f5faff 100%);
            border: 1px solid rgba(59, 130, 246, 0.22);
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.16);
            display: flex;
            flex-direction: column;
            z-index: 50;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s;
            overflow: hidden;
        }

        .comment-sidebar.panel-hidden {
            transform: translateX(calc(100% + 1.5rem));
            opacity: 0;
            pointer-events: none;
        }

        .comment-sidebar.is-resizing {
            transition: none !important;
            user-select: none;
        }

        .history-sidebar.is-resizing {
            transition: none !important;
            user-select: none;
        }

        .comment-resizer {
            position: absolute;
            top: 0;
            left: 0;
            width: 10px;
            height: 100%;
            cursor: col-resize;
            z-index: 15;
            border-radius: 16px 0 0 16px;
        }

        .history-resizer {
            position: absolute;
            top: 0;
            right: -5px;
            width: 10px;
            height: 100%;
            cursor: col-resize;
            z-index: 15;
        }

        .comment-header { 
            padding: 1.5rem 2rem; 
            border-bottom: 1px solid #dbeafe; 
            font-weight: 800; 
            font-family: 'Sora', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            justify-content: space-between; 
            align-items: center;
            background: rgba(59, 130, 246, 0.08);
        }

        .project-history-panel {
            margin: 1rem 1rem 0;
            border: 1px solid #bfdbfe;
            background: #ffffff;
            border-radius: 14px;
            padding: 0.85rem;
            box-shadow: 0 8px 20px -20px rgba(15, 23, 42, 0.45);
            flex-shrink: 0;
        }

        .history-title {
            margin: 0;
            font-size: 0.85rem;
            font-weight: 800;
            color: #0f172a;
        }

        .history-stats {
            margin-top: 0.6rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.45rem;
        }

        .history-stat {
            border: 1px solid #dbeafe;
            border-radius: 10px;
            padding: 0.45rem 0.5rem;
            background: #f8fbff;
        }

        .history-stat-label {
            margin: 0;
            font-size: 0.64rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
        }

        .history-stat-value {
            margin: 0.2rem 0 0;
            font-size: 0.78rem;
            font-weight: 700;
            color: #0f172a;
        }

        .history-file-list {
            margin-top: 0.75rem;
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            max-height: 240px;
            overflow-y: auto;
            padding-right: 0.2rem;
        }

        .history-file-item {
            border: 1px solid #dbeafe;
            border-radius: 10px;
            padding: 0.45rem 0.5rem;
            background: #ffffff;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .history-file-item:hover { background: #f0f9ff; border-color: #7dd3fc; }
        .history-file-item.active-version { background: #ede9fe; border-color: #a78bfa; }

        #readonly-banner {
            display: none;
            position: absolute;
            top: 0.6rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 50;
            background: #fef3c7;
            border: 1.5px solid #f59e0b;
            color: #92400e;
            border-radius: 8px;
            padding: 0.35rem 1rem;
            font-size: 0.78rem;
            font-weight: 700;
            pointer-events: none;
            white-space: nowrap;
        }
        #readonly-banner.show { display: block; }

        .history-file-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.45rem;
        }

        .history-file-name {
            margin: 0;
            font-size: 0.75rem;
            font-weight: 700;
            color: #0f172a;
        }

        .history-file-meta {
            margin: 0.2rem 0 0;
            font-size: 0.69rem;
            color: #64748b;
        }

        .history-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.12rem 0.4rem;
            font-size: 0.62rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .history-badge.first { background: #e0f2fe; color: #075985; }
        .history-badge.latest { background: #ede9fe; color: #5b21b6; }
        .history-badge.final { background: #dcfce7; color: #166534; }
        .history-badge.version { background: #f1f5f9; color: #475569; }

        .history-activity-panel {
            margin: 0.75rem 1rem 1rem;
            border: 1px solid #bfdbfe;
            background: #ffffff;
            border-radius: 14px;
            padding: 0.85rem;
            box-shadow: 0 8px 20px -20px rgba(15, 23, 42, 0.45);
            flex-shrink: 0;
        }

        .history-activity-panel-title {
            margin: 0 0 0.55rem;
            font-size: 0.78rem;
            font-weight: 800;
            color: #0f172a;
            flex-shrink: 0;
        }

        .history-activity-list {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            max-height: 240px;
            overflow-y: auto;
            padding-right: 0.35rem;
            scrollbar-width: thin;
            scrollbar-color: #bfdbfe #f0f9ff;
        }
        .history-activity-list::-webkit-scrollbar { width: 5px; }
        .history-activity-list::-webkit-scrollbar-track { background: #f0f9ff; border-radius: 999px; }
        .history-activity-list::-webkit-scrollbar-thumb { background: #bfdbfe; border-radius: 999px; }

        .history-activity-item {
            border-left: 2px solid #cbd5e1;
            padding-left: 0.65rem;
            border-radius: 0 4px 4px 0;
            background: #f8fafc;
        }

        .history-activity-item.activity-client {
            border-left-color: #3b82f6;
            background: #eff6ff;
        }

        .history-activity-item.activity-designer {
            border-left-color: #8b5cf6;
            background: #f5f3ff;
        }

        .history-activity-item.activity-delete {
            border-left-color: #f87171;
            background: #fff7f7;
        }

        .history-activity-text {
            margin: 0;
            font-size: 0.7rem;
            color: #334155;
            line-height: 1.35;
        }

        .history-activity-item.activity-client .history-activity-text {
            color: #1d4ed8;
            font-weight: 600;
        }

        .history-activity-item.activity-designer .history-activity-text {
            color: #6d28d9;
            font-weight: 600;
        }

        .history-activity-item.activity-delete .history-activity-text {
            color: #b91c1c;
            font-style: italic;
        }

        .history-activity-time {
            margin: 0.15rem 0 0;
            font-size: 0.64rem;
            color: #64748b;
        }

        .comment-list { 
            flex: 1; 
            overflow-y: auto; 
            padding: 1.5rem; 
        }
        
        .pin { 
            position: absolute; 
            width: 34px; 
            height: 34px; 
            background: var(--primary-gradient); 
            border: 2px solid white; 
            border-radius: 50%; 
            color: white; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 13px; 
            font-weight: 800; 
            transform: translate(-50%, -50%); 
            cursor: pointer; 
            z-index: 10; 
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4); 
            transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .pin::after {
            content: '';
            position: absolute;
            inset: -6px;
            border-radius: 999px;
            border: 2px solid rgba(20, 184, 166, 0.45);
            animation: none;
            pointer-events: none;
        }

        .pin.temp-pin {
            animation: pin-drop 0.22s ease-out;
        }

        .pin:hover { 
            transform: translate(-50%, -50%) scale(1.3); 
            box-shadow: 0 20px 25px -5px rgba(99, 102, 241, 0.4);
        }

        .pin.active-link,
        .comment-area.active-link {
            transform: translate(-50%, -50%) scale(1.18);
            box-shadow: 0 0 0 6px rgba(20, 184, 166, 0.18);
            border-color: #14b8a6;
        }

        .pin.active-link[data-type="point"] {
            box-shadow: 0 0 0 6px rgba(14, 165, 233, 0.22);
            border-color: var(--tool-point);
        }

        .pin.active-link[data-type="area"] {
            box-shadow: 0 0 0 6px rgba(139, 92, 246, 0.22);
            border-color: var(--tool-area);
        }

        .pin.active-link[data-type="arrow"] {
            box-shadow: 0 0 0 6px rgba(249, 115, 22, 0.22);
            border-color: var(--tool-arrow);
        }

        .pin.active-link[data-type="pen"] {
            box-shadow: 0 0 0 6px rgba(15, 118, 110, 0.22);
            border-color: var(--tool-pen);
        }

        .pin.active-link[data-type="highlighter"] {
            box-shadow: 0 0 0 6px rgba(234, 179, 8, 0.24);
            border-color: var(--tool-highlighter);
        }

        .comment-area.active-link {
            transform: none;
            box-shadow: 0 0 0 6px rgba(139, 92, 246, 0.18), 0 0 0 2px rgba(139, 92, 246, 0.65) inset;
            background: rgba(139, 92, 246, 0.14);
        }
        
        .selection-rect {
            position: absolute;
            border: 2px dotted var(--tool-area);
            background: rgba(139, 92, 246, 0.13);
            pointer-events: none;
            z-index: 5;
            animation: none;
        }

        .comment-area {
            position: absolute;
            border: 2px dotted var(--tool-area);
            background: rgba(139, 92, 246, 0.08);
            z-index: 4;
            cursor: pointer;
            transition: all 0.2s;
            animation: none;
        }

        .comment-area[data-type="area"] {
            border-color: var(--tool-area);
            background: rgba(139, 92, 246, 0.08);
        }

        .pin[data-type="point"] {
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%);
            box-shadow: 0 10px 15px -3px rgba(14, 165, 233, 0.38);
        }

        .pin[data-type="area"] {
            background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
            box-shadow: 0 10px 15px -3px rgba(139, 92, 246, 0.38);
        }

        .pin[data-type="arrow"] {
            background: linear-gradient(135deg, #f97316 0%, #fb923c 100%);
            box-shadow: 0 10px 15px -3px rgba(249, 115, 22, 0.38);
        }

        .pin[data-type="pen"] {
            background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
            box-shadow: 0 10px 15px -3px rgba(15, 118, 110, 0.38);
        }

        .pin[data-type="highlighter"] {
            background: linear-gradient(135deg, #ca8a04 0%, #facc15 100%);
            box-shadow: 0 10px 15px -3px rgba(202, 138, 4, 0.38);
        }

        .comment-area:hover, .comment-area.active {
            background: rgba(99, 102, 241, 0.15);
            box-shadow: 0 0 15px rgba(99, 102, 241, 0.3);
        }

        .comment-item { 
            padding: 1.25rem; 
            border-radius: 16px; 
            background: #ffffff; 
            margin-bottom: 1rem; 
            border: 1px solid #dbeafe; 
            transition: all 0.3s; 
            cursor: pointer; 
            --comment-accent: var(--tool-point);
            border-left: 4px solid var(--comment-accent);
        }

        .comment-item[data-type="point"] { --comment-accent: var(--tool-point); }
        .comment-item[data-type="area"] { --comment-accent: var(--tool-area); }
        .comment-item[data-type="arrow"] { --comment-accent: var(--tool-arrow); }
        .comment-item[data-type="pen"] { --comment-accent: var(--tool-pen); }
        .comment-item[data-type="highlighter"] { --comment-accent: var(--tool-highlighter); }

        .comment-item:hover, .comment-item.active { 
            border-color: color-mix(in srgb, var(--comment-accent) 48%, #ffffff 52%);
            background: white; 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }

        .comment-item.active-link {
            border-color: color-mix(in srgb, var(--comment-accent) 56%, #ffffff 44%);
            background: color-mix(in srgb, var(--comment-accent) 10%, #ffffff 90%);
            box-shadow: 0 0 0 1px color-mix(in srgb, var(--comment-accent) 28%, #ffffff 72%), 0 12px 25px -18px color-mix(in srgb, var(--comment-accent) 48%, rgba(15, 118, 110, 0.5) 52%);
        }

        .comment-author { 
            font-weight: 700; 
            font-size: 0.9rem; 
            margin-bottom: 0.5rem; 
            display: flex;
            align-items: center;
        }

        .comment-badge {
            background: color-mix(in srgb, var(--comment-accent) 78%, #ffffff 22%);
            color: white;
            padding: 2px 8px; 
            border-radius: 6px; 
            margin-right: 8px;
            font-size: 0.75rem;
        }

        .comment-location {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            margin-top: 0.35rem;
            padding: 0.28rem 0.5rem;
            border-radius: 999px;
            background: color-mix(in srgb, var(--comment-accent) 14%, #ffffff 86%);
            color: color-mix(in srgb, var(--comment-accent) 82%, #1e293b 18%);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .comment-text { 
            font-size: 0.95rem; 
            color: #475569; 
            line-height: 1.5;
        }

        .reply-list {
            margin-top: 1rem;
            padding-left: 1rem;
            border-left: 2px solid #f1f5f9;
        }

        .reply-item {
            font-size: 0.85rem;
            margin-bottom: 0.75rem;
        }

        .reply-input-box {
            margin-top: 0.75rem;
            display: none;
        }

        .btn-reply-toggle {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--primary-color);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            height: 22px;
            margin: 0;
        }

        .comment-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.75rem;
            min-height: 22px;
        }

        .comment-delete-btn {
            border: none;
            background: transparent;
            color: #dc2626;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            padding: 0;
            height: 22px;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }

        .comment-delete-btn:hover {
            color: #991b1b;
        }
        
        .custom-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .custom-modal-overlay.open {
            display: flex;
        }

        .custom-modal {
            width: min(420px, calc(100vw - 2rem));
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid #dbeafe;
            box-shadow: 0 30px 70px -28px rgba(15, 23, 42, 0.55);
            overflow: hidden;
            animation: modal-pop-in 0.16s ease-out;
        }

        .custom-modal-head {
            padding: 0.9rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
            background: #f8fbff;
        }

        .custom-modal-body {
            padding: 1rem;
            color: #334155;
            line-height: 1.5;
            font-size: 0.9rem;
            white-space: pre-wrap;
        }

        .custom-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
            padding: 0 1rem 1rem;
        }

        .custom-modal-btn {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #1e293b;
            border-radius: 10px;
            padding: 0.5rem 0.95rem;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .custom-modal-btn.primary {
            border-color: transparent;
            background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
            color: #ffffff;
        }

        @keyframes modal-pop-in {
            from {
                transform: translateY(10px) scale(0.98);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .btn-approve, .btn-changes { 
            min-height: 34px;
            padding: 0.45rem 1rem;
            border-radius: 20px; 
            font-weight: 700; 
            cursor: pointer; 
            border: none;
            color: white;
            font-size: 0.82rem;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s;
        }

        .btn-approve i,
        .btn-changes i {
            font-size: 0.82rem;
        }

        .btn-approve { background: var(--status-approved); box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3); }
        .btn-changes { background: var(--status-changes); box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3); }
        .btn-approve:disabled, .btn-changes:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
            pointer-events: none;
        }

        #new-comment-box,
        #comment-view-popup {
            position: absolute;
            z-index: 200;
            width: 300px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1rem 1.1rem 1.1rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08);
        }

        #comment-textarea {
            width: 100%; 
            padding: 0.75rem; 
            border-radius: 10px; 
            border: 1px solid #e2e8f0; 
            resize: none; 
            height: 80px; 
            margin-bottom: 0.5rem;
            font-family: inherit;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .btn-save {
            background: var(--primary-gradient);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            flex: 1;
        }

        /* Drawing Tools Styles */
        .color-input {
            width: 36px;
            height: 36px;
            border: 1px solid rgba(13, 148, 136, 0.4);
            border-radius: 10px;
            background: #ffffff;
            cursor: pointer;
            padding: 2px;
        }

        .color-preview {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 1px solid rgba(15, 23, 42, 0.2);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3);
        }

        .reference-upload-container {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }

        .ref-file-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--primary-color);
            cursor: pointer;
            font-weight: 600;
        }

        .ref-file-preview {
            margin-top: 0.5rem;
            max-width: 100%;
            height: 80px;
            object-fit: contain;
            border-radius: 8px;
            display: none;
            border: 1px solid #e2e8f0;
        }

        .drawing-canvas {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 6;
            pointer-events: none;
        }

        .markup-layer {
            position: absolute;
            inset: 0;
            z-index: 8;
            overflow: visible;
        }

        .markup-stroke {
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }

        .markup-stroke.markup-pen {
            stroke-dasharray: 10 8;
            animation: none;
        }

        .markup-stroke.markup-arrow {
            stroke-dasharray: 18 8;
            animation: none;
        }

        .markup-hit {
            fill: none;
            stroke: transparent;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: stroke;
            cursor: pointer;
        }

        .markup-hit.active-link {
            stroke: rgba(20, 184, 166, 0.16);
        }

        .arrow-note {
            pointer-events: none;
        }

        .arrow-note-bg {
            fill: rgba(15, 118, 110, 0.9);
            stroke: rgba(255, 255, 255, 0.65);
            stroke-width: 1;
        }

        .arrow-note-text {
            fill: #ffffff;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Manrope', sans-serif;
            dominant-baseline: middle;
        }

        .interaction-layer {
            position: absolute;
            inset: 0;
            z-index: 9;
            cursor: inherit;
            background: transparent;
            touch-action: none;
            user-select: none;
        }

        #pins-container {
            position: absolute;
            inset: 0;
            z-index: 10;
            pointer-events: none;
        }

        #pins-container .pin,
        #pins-container .comment-area {
            pointer-events: auto;
        }

        #pins-container.pins-pass-through .pin,
        #pins-container.pins-pass-through .comment-area {
            pointer-events: none !important;
        }

        @keyframes pin-ring {
            0% {
                transform: scale(0.85);
                opacity: 0.6;
            }
            70% {
                transform: scale(1.22);
                opacity: 0;
            }
            100% {
                transform: scale(1.22);
                opacity: 0;
            }
        }

        @keyframes pin-drop {
            0% {
                transform: translate(-50%, -50%) scale(0.3);
                opacity: 0;
            }
            100% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 1;
            }
        }

        @keyframes pin-bob {
            0%, 100% {
                transform: translate(-50%, -50%) scale(1);
            }
            50% {
                transform: translate(-50%, -52%) scale(1.05);
            }
        }

        @keyframes area-breathe {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(20, 184, 166, 0.1);
                background: rgba(99, 102, 241, 0.05);
            }
            50% {
                box-shadow: 0 0 0 5px rgba(20, 184, 166, 0.14);
                background: rgba(20, 184, 166, 0.09);
            }
        }

        @keyframes dotted-flash {
            0%, 100% {
                border-color: rgba(15, 118, 110, 1);
            }
            50% {
                border-color: rgba(15, 118, 110, 0.55);
            }
        }

        @keyframes pen-flow {
            from {
                stroke-dashoffset: 0;
            }
            to {
                stroke-dashoffset: -54;
            }
        }

        @keyframes arrow-pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.72;
            }
        }

        @media (max-width: 1100px) {
            .history-sidebar {
                width: 300px;
            }

            .comment-sidebar {
                width: 360px;
            }
        }

        @media (max-width: 860px) {
            .review-container {
                flex-direction: column;
            }

            .toolbar {
                left: 1rem;
                top: 1rem;
                transform: none;
                flex-direction: row;
                flex-wrap: wrap;
                max-width: calc(100% - 2rem);
            }

            .toolbar-handle {
                display: none;
            }

            .toolbar-divider,
            .color-picker {
                display: none;
            }

            .comment-sidebar {
                top: auto;
                bottom: 0;
                right: 0;
                left: 0;
                width: 100% !important;
                max-width: 100%;
                border-radius: 16px 16px 0 0;
                max-height: 60vh;
            }
            .comment-sidebar.panel-hidden {
                transform: translateY(110%);
            }

            .history-sidebar {
                width: 100%;
                min-width: 0;
                max-height: 34vh;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
            }

            .comment-resizer {
                display: none;
            }

            .ruler-top,
            .ruler-left,
            .ruler-corner,
            .ruler-guides-layer,
            .ruler-guide {
                display: none !important;
            }

            .btn-approve, .btn-changes {
                font-size: 0.75rem;
                padding: 0.4rem 0.75rem;
            }
        }
        @media print {
            body {
                background: #ffffff !important;
                overflow: visible !important;
                height: auto !important;
            }

            .review-header,
            .app-footer,
            .toolbar,
            .history-sidebar,
            .tool-status-pill,
            .tool-guide,
            .comment-sidebar,
            .ruler-top,
            .ruler-left,
            .ruler-corner,
            .ruler-guides-layer,
            #new-comment-box,
            #selection-rect {
                display: none !important;
            }

            .review-container,
            .artwork-viewer,
            .artwork-wrapper {
                display: block !important;
                width: auto !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
            }

            .artwork-viewer {
                background: #ffffff !important;
                align-items: flex-start !important;
                justify-content: flex-start !important;
            }

            body.print-mode .artwork-wrapper {
                transform: scale(var(--print-scale, 1)) !important;
                transform-origin: 0 0 !important;
            }

            #pins-container .pin,
            #pins-container .comment-area,
            .markup-layer {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <header class="review-header">
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <div class="erp-brand-wrap">
                <img src="<?php echo sanitize($erpLogoUrl); ?>" alt="ERP Logo">
                <span class="erp-brand-text"><?php echo sanitize(APP_NAME); ?></span>
            </div>
            <div style="width: 1px; height: 32px; background: #f1f5f9;"></div>
            <div>
                <h4 style="margin: 0; font-weight: 800; letter-spacing: -0.01em;"><?php echo sanitize($project['title']); ?></h4>
                <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">
                    Version <?php echo $file['version']; ?> • <?php echo date('M d, Y', strtotime($file['uploaded_at'])); ?>
                </p>
            </div>
        </div>
        <div class="review-header-actions">
            <button type="button" id="toggle-comments-btn" class="btn-toggle-comments active" title="Toggle Comments Panel">
                <i class="fas fa-comments"></i> Comments
                <span class="comment-count" id="comments-count-badge"><?php echo count($comments); ?></span>
            </button>
            <?php if (!$isDesigner): ?>
            <button class="btn-changes" onclick="requestChanges()" title="Request Changes"
                <?php echo $isApproved ? 'disabled' : ''; ?>>
                <i class="fas fa-undo-alt"></i> Request Changes
            </button>
            <button class="btn-approve" onclick="approveArtwork()" title="Approve Artwork"
                <?php echo $isApproved ? 'disabled' : ''; ?>>
                <i class="fas fa-check-circle"></i> <?php echo $isApproved ? 'Approved' : 'Approve Artwork'; ?>
            </button>
            <?php endif; ?>
            <button type="button" class="btn-print-design" onclick="triggerPrintDesign()" title="Print Artwork">
                <i class="fas fa-print"></i> Print
            </button>
            <div style="background: #f8fafc; padding: 0.5rem 1.25rem; border-radius: 30px; border: 1px solid #f1f5f9; font-size: 0.85rem; font-weight: 600; color: var(--text-muted);">
                Reviewing as <span style="color: var(--text-main);"><?php echo sanitize($project['client_name']); ?></span>
            </div>
        </div>
    </header>

    <div class="review-container">
        <div class="tool-status-pill">Active Tool: <span id="tool-status-label">Select</span></div>
        <div class="tool-guide" id="tool-guide-label">Select tool keeps the canvas neutral for browsing and closing floating boxes.</div>
        <div class="toolbar" id="main-toolbar">
            <div class="toolbar-handle" id="toolbar-handle" title="Drag Toolbar">
                <i class="fas fa-grip-lines"></i>
            </div>
            <button class="tool-btn active" data-tool="select" title="Select / Inspect (V)">
                <i class="fas fa-arrow-pointer"></i>
            </button>
            <button class="tool-btn" data-tool="point" title="Add Point (P)">
                <i class="fas fa-map-marker-alt"></i>
            </button>
            <button class="tool-btn" data-tool="area" title="Select Area (A)">
                <i class="fas fa-vector-square"></i>
            </button>
            <button class="tool-btn" data-tool="arrow" title="Arrow Comment (W)">
                <i class="fas fa-arrow-right"></i>
            </button>
            <button class="tool-btn" data-tool="pen" title="Pen Markup (D)">
                <i class="fas fa-pen"></i>
            </button>
            <button class="tool-btn" data-tool="highlighter" title="Highlight (I)">
                <i class="fas fa-highlighter"></i>
            </button>
            <button class="tool-btn" data-tool="pan" title="Pan Tool (H)">
                <i class="fas fa-hand-paper"></i>
            </button>
            <div class="toolbar-divider"></div>
            <div class="color-picker">
                <input type="color" id="tool-color-input" class="color-input" value="#0f766e" title="Select Pen/Highlight Color">
                <span class="color-preview" id="tool-color-preview" style="background: #0f766e;"></span>
            </div>
            <div class="toolbar-divider"></div>
            <button class="tool-btn" id="zoom-in" title="Zoom In (+)">
                <i class="fas fa-search-plus"></i>
            </button>
            <button class="tool-btn" id="zoom-out" title="Zoom Out (-)">
                <i class="fas fa-search-minus"></i>
            </button>
            <button class="tool-btn" id="zoom-reset" title="Reset View (R)">
                <i class="fas fa-expand"></i>
            </button>
        </div>

        <aside class="history-sidebar" id="history-sidebar">
            <div class="history-resizer" id="history-resizer"></div>

            <!-- Version History Panel -->
            <div class="project-history-panel">
                <p class="history-title">Project History</p>
                <div class="history-stats">
                    <div class="history-stat">
                        <p class="history-stat-label">First File</p>
                        <p class="history-stat-value"><?php echo $firstUploadedAt ? date('M d, H:i', strtotime($firstUploadedAt)) : 'N/A'; ?></p>
                    </div>
                    <div class="history-stat">
                        <p class="history-stat-label">Latest Upload</p>
                        <p class="history-stat-value"><?php echo $latestUploadedAt ? date('M d, H:i', strtotime($latestUploadedAt)) : 'N/A'; ?></p>
                    </div>
                    <div class="history-stat">
                        <p class="history-stat-label">Total Files</p>
                        <p class="history-stat-value"><?php echo count($projectFiles); ?></p>
                    </div>
                    <div class="history-stat">
                        <p class="history-stat-label">Corrections</p>
                        <p class="history-stat-value"><?php echo (int) $totalCorrections; ?></p>
                    </div>
                </div>

                <div class="history-file-list">
                    <?php if (empty($projectFiles)): ?>
                        <p style="margin:0; font-size:0.73rem; color:#64748b;">No file history found.</p>
                    <?php else: ?>
                        <?php foreach ($projectFiles as $fileIndex => $journeyFile): ?>
                            <?php
                                $isFirst = ($fileIndex === 0);
                                $isLatest = ($fileIndex === count($projectFiles) - 1);
                                $isFinal = ((int) ($journeyFile['is_final'] ?? 0) === 1);
                            ?>
                            <div class="history-file-item<?php echo $isLatest ? ' active-version' : ''; ?>"
                                   onclick="switchVersion(<?php echo htmlspecialchars(json_encode(['id'=>(int)$journeyFile['id'],'filename'=>$journeyFile['filename'],'file_type'=>strtolower((string)($journeyFile['file_type'] ?: pathinfo((string)$journeyFile['filename'], PATHINFO_EXTENSION))),'version'=>(int)$journeyFile['version'],'is_latest'=>$isLatest]), ENT_QUOTES); ?>)"
                                 data-file-id="<?php echo (int)$journeyFile['id']; ?>">
                                <div class="history-file-top">
                                    <p class="history-file-name">v<?php echo (int) $journeyFile['version']; ?> • <?php echo sanitize($journeyFile['original_name']); ?></p>
                                    <div style="display:flex; gap:0.2rem; flex-wrap:wrap; justify-content:flex-end;">
                                        <?php if ($isFirst): ?><span class="history-badge first">First</span><?php endif; ?>
                                        <?php if ($isLatest): ?><span class="history-badge latest">Latest</span><?php endif; ?>
                                        <?php if ($isFinal): ?><span class="history-badge final">Final</span><?php endif; ?>
                                        <?php if (!$isFirst && !$isLatest && !$isFinal): ?><span class="history-badge version">v<?php echo (int)$journeyFile['version']; ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <p class="history-file-meta"><?php echo date('M d, Y H:i', strtotime($journeyFile['uploaded_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Log Panel -->
            <div class="history-activity-panel">
                <p class="history-activity-panel-title"><i class="fas fa-history" style="margin-right:0.35rem; color:#6366f1;"></i>Activity Log</p>
                <div class="history-activity-list">
                    <?php if (empty($projectActivities)): ?>
                        <p style="margin:0; font-size:0.73rem; color:#64748b;">No activity history yet.</p>
                    <?php else: ?>
                        <?php foreach ($projectActivities as $event): ?>
                            <?php
                                $act = $event['action'];
                                $actClass = '';
                                if (stripos($act, 'Client ') === 0 || stripos($act, 'Artwork approved by client') !== false) {
                                    $actClass = 'activity-client';
                                } elseif (stripos($act, 'Designer ') === 0 || stripos($act, 'uploaded revision') !== false) {
                                    $actClass = 'activity-designer';
                                } elseif (stripos($act, 'Correction deleted') === 0 || stripos($act, 'Deleted') === 0) {
                                    $actClass = 'activity-delete';
                                }
                            ?>
                            <div class="history-activity-item <?php echo $actClass; ?>">
                                <p class="history-activity-text"><?php echo sanitize($event['action']); ?></p>
                                <p class="history-activity-time"><?php echo date('M d, Y H:i', strtotime($event['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <div class="artwork-viewer" id="artwork-viewer">
            <div class="ruler-corner"></div>
            <div class="ruler-top"></div>
            <div class="ruler-left"></div>
            <div class="ruler-guides-layer" id="ruler-guides-layer"></div>
            <div id="readonly-banner"><i class="fas fa-eye" style="margin-right:0.35rem;"></i>Viewing older version — comments disabled</div>
            <div class="artwork-wrapper" id="artwork-wrapper">
                <canvas id="drawing-canvas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 6; pointer-events: none;"></canvas>
                <?php if ($isCurrentPdf): ?>
                    <canvas id="pdf-canvas"></canvas>
                <?php elseif ($isCurrentImage): ?>
                    <img src="uploads/projects/<?php echo rawurlencode($file['filename']); ?>" id="artwork-img" alt="Artwork">
                <?php else: ?>
                    <div style="padding: 6rem; text-align: center; background: white; border-radius: 16px;">
                        <div style="width: 100px; height: 100px; background: #f1f5f9; border-radius: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 2rem; color: #cbd5e1; font-size: 3rem;">
                            <i class="fas <?php echo getFileIcon($file['file_type']); ?>"></i>
                        </div>
                        <h3 style="font-weight: 800; margin-bottom: 0.5rem;">Preview Unavailable</h3>
                        <p style="color: var(--text-muted); margin-bottom: 0;">This file type cannot be previewed in-browser from client view.</p>
                    </div>
                <?php endif; ?>
                <svg class="markup-layer" id="markup-layer"></svg>
                <div class="interaction-layer" id="interaction-layer"></div>
                
                <div id="pins-container">
                    <?php foreach ($comments as $index => $comment): ?>
                        <?php if (in_array(($comment['type'] ?? 'point'), ['point', 'pen', 'highlighter'], true) && ($comment['x_pos'] ?? null) !== null): ?>
                            <div class="pin" style="left: <?php echo (float) ($comment['x_pos'] ?? 0); ?>%; top: <?php echo (float) ($comment['y_pos'] ?? 0); ?>%;" data-id="<?php echo $comment['id']; ?>" data-type="<?php echo sanitize((string) ($comment['type'] ?? 'point')); ?>" data-comment-kind="<?php echo sanitize(annotationLabel($comment, $index + 1)); ?>">
                                <?php echo $index + 1; ?>
                            </div>
                        <?php elseif (($comment['type'] ?? '') === 'area' && ($comment['width'] ?? null) !== null): ?>
                            <div class="comment-area" style="left: <?php echo (float) ($comment['x_pos'] ?? 0); ?>%; top: <?php echo (float) ($comment['y_pos'] ?? 0); ?>%; width: <?php echo (float) ($comment['width'] ?? 0); ?>%; height: <?php echo (float) ($comment['height'] ?? 0); ?>%;" data-id="<?php echo $comment['id']; ?>" data-type="area" data-comment-kind="<?php echo sanitize(annotationLabel($comment, $index + 1)); ?>">
                                <span class="pin" data-type="area" style="left: 0; top: 0; transform: translate(-50%, -50%);">
                                    <?php echo $index + 1; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div id="selection-rect" class="selection-rect" style="display: none;"></div>
            </div>
        </div>

        <aside class="comment-sidebar panel-hidden">
            <div class="comment-resizer" id="comment-resizer"></div>
            <div class="comment-header">
                Comments 
                <span style="background: #f1f5f9; padding: 2px 10px; border-radius: 20px; font-size: 0.8rem; color: var(--text-muted);"><?php echo count($comments); ?></span>
            </div>

            <div class="comment-list" id="comment-list">
                <?php if (empty($comments)): ?>
                    <div style="text-align: center; color: var(--text-muted); padding: 4rem 2rem;">
                        <div style="width: 64px; height: 64px; background: #f8fafc; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 1.5rem; color: #e2e8f0;">
                            <i class="far fa-comment-dots"></i>
                        </div>
                        <h4 style="color: var(--text-main); margin-bottom: 0.5rem; font-weight: 700;">No comments yet</h4>
                        <p style="font-size: 0.9rem;">Click or select an area on the artwork to share your feedback.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $index => $comment): ?>
                        <div class="comment-item" data-id="<?php echo $comment['id']; ?>" data-type="<?php echo sanitize((string) ($comment['type'] ?? 'point')); ?>" data-comment-kind="<?php echo sanitize(annotationLabel($comment, $index + 1)); ?>">
                            <div class="comment-author">
                                <span class="comment-badge"><?php echo $index + 1; ?></span>
                                <?php echo sanitize($comment['user_name']); ?>
                            </div>
                            <div class="comment-location">
                                <i class="fas fa-location-crosshairs"></i>
                                <?php echo sanitize(annotationLabel($comment, $index + 1)); ?>
                            </div>
                            <div class="comment-text"><?php echo nl2br(sanitize($comment['comment'])); ?></div>
                            
                            <?php if (!empty($comment['attachment'])): ?>
                                <div style="margin-top: 0.75rem;">
                                    <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $comment['attachment'])): ?>
                                        <img src="uploads/references/<?php echo $comment['attachment']; ?>" style="max-width: 100%; border-radius: 8px; cursor: pointer; border: 1px solid #e2e8f0;" onclick="window.open(this.src)">
                                    <?php else: ?>
                                        <a href="uploads/references/<?php echo $comment['attachment']; ?>" target="_blank" style="font-size: 0.8rem; color: var(--primary-color); text-decoration: none;">
                                            <i class="fas fa-paperclip"></i> View Attachment
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="far fa-clock"></i> <?php echo date('M d, H:i', strtotime($comment['created_at'])); ?></span>
                                <div class="comment-actions">
                                    <span class="btn-reply-toggle" onclick="event.stopPropagation(); toggleReplyForm(<?php echo $comment['id']; ?>)">Reply</span>
                                    <button class="comment-delete-btn" type="button" data-delete-comment="<?php echo $comment['id']; ?>">Delete</button>
                                </div>
                            </div>

                            <!-- Replies -->
                            <div class="reply-list" id="replies-<?php echo $comment['id']; ?>">
                                <?php
                                $stmt = $db->prepare("SELECT * FROM artwork_comments WHERE parent_id = ? ORDER BY created_at ASC");
                                $stmt->execute([$comment['id']]);
                                $replies = $stmt->fetchAll();
                                foreach ($replies as $reply): ?>
                                    <div class="reply-item">
                                        <div style="font-weight: 700; font-size: 0.8rem;"><?php echo sanitize($reply['user_name']); ?></div>
                                        <div style="color: #475569;"><?php echo nl2br(sanitize($reply['comment'])); ?></div>
                                        <button class="comment-delete-btn" type="button" data-delete-comment="<?php echo $reply['id']; ?>" style="margin-top:0.3rem;">Delete</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Reply Form -->
                            <div class="reply-input-box" id="reply-box-<?php echo $comment['id']; ?>" onclick="event.stopPropagation()">
                                <textarea class="reply-textarea" placeholder="Write a reply..." style="width: 100%; padding: 0.5rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.85rem; height: 60px; resize: none; outline: none; margin-top: 0.5rem;"></textarea>
                                <button class="btn-save" style="width: 100%; padding: 0.4rem; font-size: 0.8rem; margin-top: 0.5rem;" onclick="event.stopPropagation(); saveReply(<?php echo $comment['id']; ?>)">Post Reply</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Floating comment composer, positioned near annotation -->
        <div id="new-comment-box" style="display:none;">
            <div class="comment-box-head">
                <span style="font-weight:700; font-size:0.92rem;">Add Feedback</span>
                <button type="button" id="close-comment-box" class="comment-box-close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <textarea id="comment-textarea" placeholder="Describe the changes or feedback for this spot..."></textarea>
            <div class="reference-upload-container">
                <label class="ref-file-label" for="ref-image-input">
                    <i class="fas fa-image"></i> Reference Image
                </label>
                <input type="file" id="ref-image-input" accept="image/*" style="display:none;">
                <img id="ref-image-preview" class="ref-file-preview">
            </div>
            <div style="display:flex; gap:0.6rem; margin-top:1rem;">
                <button class="btn-save" id="save-comment">Post</button>
                <button class="btn-cancel" id="cancel-comment">Cancel</button>
            </div>
        </div>

        <!-- Floating comment view popup (click on existing pin) -->
        <div id="comment-view-popup" style="display:none;">
            <div class="comment-box-head">
                <span id="cvp-label" style="font-weight:700; font-size:0.85rem; color:#64748b;"></span>
                <button type="button" id="cvp-close" class="comment-box-close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="cvp-author" style="font-weight:700; font-size:0.88rem; margin-bottom:0.35rem;"></div>
            <div id="cvp-text" style="font-size:0.88rem; color:#334155; line-height:1.5; margin-bottom:0.5rem; white-space:pre-wrap;"></div>
            <div id="cvp-attachment" style="margin-bottom:0.5rem;"></div>
            <div id="cvp-time" style="font-size:0.75rem; color:#94a3b8; margin-bottom:0.6rem;"></div>
            <div style="display:flex; gap:0.5rem; padding-top:0.6rem; border-top:1px solid #f1f5f9; align-items:center;">
                <button type="button" id="cvp-reply-toggle" class="btn-reply-toggle" style="font-size:0.8rem; padding:0.25rem 0.7rem; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; cursor:pointer; font-family:inherit;">Reply</button>
                <button type="button" id="cvp-delete" class="comment-delete-btn" style="font-size:0.8rem; margin-left:auto;">Delete</button>
            </div>
            <div id="cvp-reply-box" style="display:none; margin-top:0.6rem;">
                <textarea id="cvp-reply-textarea" placeholder="Write a reply..." style="width:100%; padding:0.6rem; border-radius:8px; border:1px solid #e2e8f0; resize:none; height:60px; font-family:inherit; font-size:0.85rem; outline:none; box-sizing:border-box;"></textarea>
                <button type="button" id="cvp-reply-post" class="btn-save" style="width:100%; padding:0.4rem; font-size:0.8rem; margin-top:0.4rem;">Post Reply</button>
            </div>
        </div>
    </div>

    <div class="custom-modal-overlay" id="custom-modal-overlay" aria-hidden="true">
        <div class="custom-modal" role="dialog" aria-modal="true" aria-labelledby="custom-modal-title">
            <div class="custom-modal-head" id="custom-modal-title">Notice</div>
            <div class="custom-modal-body" id="custom-modal-message"></div>
            <div class="custom-modal-actions">
                <button type="button" class="custom-modal-btn" id="custom-modal-cancel" style="display:none;">Cancel</button>
                <button type="button" class="custom-modal-btn primary" id="custom-modal-ok">OK</button>
            </div>
        </div>
    </div>

    <footer class="app-footer" role="contentinfo">
        <div class="app-footer-left">Version : <?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?></div>
        <div class="app-footer-right">&copy; <?php echo date('Y'); ?> <?php echo sanitize(APP_NAME); ?> &bull; ERP Master System v<?php echo sanitize(defined('APP_VERSION') ? APP_VERSION : '1.0.0'); ?> | @ Developed by Mriganka Bhusan Debnath</div>
    </footer>

    <script src="https://unpkg.com/@panzoom/panzoom@4.5.1/dist/panzoom.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
    <script>
        window.projectToken = '<?php echo $token; ?>';
        window.fileId = '<?php echo $file['id']; ?>';
        window.latestFileId = '<?php echo $file['id']; ?>';
        window.clientName = '<?php echo sanitize($project['client_name']); ?>';
        window.filePath = <?php echo $isCurrentPdf
            ? json_encode('preview.php?id=' . (int) $file['id'])
            : json_encode('uploads/projects/' . rawurlencode((string) $file['filename'])); ?>;
        window.isPdf = <?php echo $isCurrentPdf ? 'true' : 'false'; ?>;
        window.initialComments = <?php echo json_encode($comments); ?>;

        const PDFJS_WORKER_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
        const PDFJS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js';

        function base64ToBytes(base64) {
            const raw = atob(base64 || '');
            const out = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i += 1) {
                out[i] = raw.charCodeAt(i);
            }
            return out;
        }

        function ensurePdfJsLoaded() {
            if (window.pdfjsLib) {
                return Promise.resolve(window.pdfjsLib);
            }

            return new Promise(function(resolve, reject) {
                const existing = document.querySelector('script[data-pdfjs="review-preview"]');
                if (existing) {
                    existing.addEventListener('load', function() {
                        resolve(window.pdfjsLib);
                    });
                    existing.addEventListener('error', reject);
                    return;
                }

                const script = document.createElement('script');
                script.src = PDFJS_URL;
                script.async = true;
                script.dataset.pdfjs = 'review-preview';
                script.onload = function() {
                    resolve(window.pdfjsLib);
                };
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        function buildProjectFilePath(filename, asPdf, fileId) {
            const safeName = encodeURIComponent(String(filename || ''));
            if (asPdf) {
                return 'preview.php?id=' + String(parseInt(fileId, 10) || 0);
            }
            return 'uploads/projects/' + safeName;
        }

        async function renderPdfCanvas(pdfPath) {
            const canvas = document.getElementById('pdf-canvas');
            if (!canvas || !pdfPath) {
                return;
            }
            const wrapper = document.getElementById('artwork-wrapper');

            window.artworkCanvasReady = false;
            canvas.style.visibility = 'hidden';
            canvas.style.opacity = '0';

            const pdfjsLib = await ensurePdfJsLoaded();
            if (!pdfjsLib) {
                return;
            }

            pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;

            try {
                const response = await fetch(pdfPath, { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('Failed to load PDF bytes');
                }
                const payload = await response.json();
                if (!payload || payload.status !== 'success' || !payload.data || !payload.data.bytes) {
                    throw new Error('Invalid preview payload');
                }
                const bytes = base64ToBytes(payload.data.bytes);

                const loadingTask = pdfjsLib.getDocument({ data: bytes });
                const pdf = await loadingTask.promise;
                const page = await pdf.getPage(1);
                const viewportBase = page.getViewport({ scale: 1 });
                const wrapper = document.getElementById('artwork-wrapper');
                const wrapperWidth = Math.max((wrapper ? wrapper.clientWidth : 0), 900);
                const targetWidth = Math.min(Math.max(wrapperWidth * 2, 1920), 3840);
                const scale = targetWidth / viewportBase.width;
                const viewport = page.getViewport({ scale: scale });
                const context = canvas.getContext('2d', { alpha: false });
                const dpr = Math.max(window.devicePixelRatio || 1, 1);
                const qualityBoost = 1.25;
                let renderScale = dpr * qualityBoost;

                // Avoid over-allocating huge canvases while still keeping zoom text sharp.
                const maxPixels = 42000000;
                const estimatedPixels = viewport.width * viewport.height * renderScale * renderScale;
                if (estimatedPixels > maxPixels) {
                    const ratio = Math.sqrt(maxPixels / estimatedPixels);
                    renderScale = renderScale * ratio;
                }

                canvas.width = Math.floor(viewport.width * renderScale);
                canvas.height = Math.floor(viewport.height * renderScale);
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';
                context.setTransform(renderScale, 0, 0, renderScale, 0, 0);
                context.imageSmoothingEnabled = true;
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);

                await page.render({ canvasContext: context, viewport: viewport }).promise;
                canvas.style.visibility = 'visible';
                canvas.style.opacity = '1';

                window.requestAnimationFrame(function() {
                    if (wrapper) {
                        wrapper.classList.remove('artwork-loading');
                    }
                    window.artworkCanvasReady = true;
                    window.dispatchEvent(new CustomEvent('artwork:ready'));
                });
            } catch (error) {
                console.error('Failed to render PDF preview', error);
                canvas.style.visibility = 'visible';
                canvas.style.opacity = '1';
                if (wrapper) {
                    wrapper.classList.remove('artwork-loading');
                }
            }
        }

        function setArtworkMedia(fileData) {
            const wrapper = document.getElementById('artwork-wrapper');
            const markupLayer = document.getElementById('markup-layer');
            if (!wrapper || !markupLayer || !fileData) {
                return;
            }

            const current = wrapper.querySelector('#pdf-canvas, #artwork-img, #artwork-unavailable');
            if (current) {
                current.remove();
            }

            const ext = String(fileData.file_type || String(fileData.filename || '').split('.').pop() || '').toLowerCase();
            const filePath = buildProjectFilePath(fileData.filename || '', ext === 'pdf', fileData.id);
            const imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (ext === 'pdf') {
                wrapper.classList.add('artwork-loading');
                const canvas = document.createElement('canvas');
                canvas.id = 'pdf-canvas';
                canvas.style.background = '#ffffff';
                canvas.style.transition = 'opacity 120ms ease';
                wrapper.insertBefore(canvas, markupLayer);
                window.filePath = filePath;
                window.isPdf = true;
                renderPdfCanvas(filePath);
                return;
            }

            if (imageTypes.includes(ext)) {
                wrapper.classList.add('artwork-loading');
                const img = document.createElement('img');
                img.id = 'artwork-img';
                img.alt = 'Artwork';
                img.src = filePath;
                img.onload = function() {
                    wrapper.classList.remove('artwork-loading');
                };
                img.onerror = function() {
                    wrapper.classList.remove('artwork-loading');
                };
                wrapper.insertBefore(img, markupLayer);
                window.filePath = filePath;
                window.isPdf = false;
                return;
            }

            const unsupported = document.createElement('div');
            unsupported.id = 'artwork-unavailable';
            unsupported.style.padding = '6rem';
            unsupported.style.textAlign = 'center';
            unsupported.style.background = 'white';
            unsupported.style.borderRadius = '16px';
            unsupported.innerHTML = '<div style="width:100px;height:100px;background:#f1f5f9;border-radius:30px;display:flex;align-items:center;justify-content:center;margin:0 auto 2rem;color:#cbd5e1;font-size:3rem;">'
                + '<i class="fas fa-file"></i></div>'
                + '<h3 style="font-weight:800;margin-bottom:0.5rem;">Preview Unavailable</h3>'
                + '<p style="color:var(--text-muted);margin-bottom:0;">This file type cannot be previewed in-browser from client view.</p>';
            wrapper.insertBefore(unsupported, markupLayer);
            wrapper.classList.remove('artwork-loading');
            window.isPdf = false;
        }

        if (window.isPdf) {
            renderPdfCanvas(window.filePath);
        }

        function switchVersion(fileData) {
            const isLatest = fileData.is_latest;
            setArtworkMedia(fileData);

            // Show/hide read-only banner
            const banner = document.getElementById('readonly-banner');
            if (banner) banner.classList.toggle('show', !isLatest);

            // Enable/disable annotation tools
            if (typeof window.setReviewReadOnly === 'function') {
                window.setReviewReadOnly(!isLatest);
            }

            // Highlight active item in history list
            document.querySelectorAll('.history-file-item').forEach(function(el) {
                el.classList.toggle('active-version', parseInt(el.dataset.fileId) === fileData.id);
            });

            // Update fileId
            window.fileId = isLatest ? window.latestFileId : String(fileData.id);

            // Fetch and re-render comments for this version
            fetch('api/get-comments.php?file_id=' + fileData.id + '&token=' + encodeURIComponent(window.projectToken))
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if (resp.status !== 'success') return;
                    renderVersionComments(resp.data.comments, isLatest);
                })
                .catch(function(){ /* silently ignore */ });
        }

        // Annotation type label (mirrors PHP annotationLabel())
        function annotationLabel(comment, index) {
            const type = comment.type || 'point';
            const map = { area:'Area', arrow:'Arrow', pen:'Markup', highlighter:'Highlight' };
            return (map[type] || 'Pin') + ' #' + index;
        }

        // Format date: "May 01, 18:48"
        function fmtDate(str) {
            const d = new Date(str.replace(' ', 'T'));
            return d.toLocaleDateString('en-US',{month:'short',day:'2-digit'})
                 + ', ' + String(d.getHours()).padStart(2,'0')
                 + ':' + String(d.getMinutes()).padStart(2,'0');
        }

        function renderVersionComments(comments, isLatest) {
            // 1. Update markup/pins canvas via pins.js hook
            if (typeof window.renderCommentsForVersion === 'function') {
                window.renderCommentsForVersion(comments);
            }

            // 2. Rebuild sidebar comment list
            const list = document.getElementById('comment-list');
            if (!list) return;

            if (!comments || comments.length === 0) {
                list.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:4rem 2rem;">'
                    + '<div style="width:64px;height:64px;background:#f8fafc;border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:1.5rem;color:#e2e8f0;">'
                    + '<i class="far fa-comment-dots"></i></div>'
                    + '<h4 style="color:var(--text-main);margin-bottom:0.5rem;font-weight:700;">No comments yet</h4>'
                    + '<p style="font-size:0.9rem;">Click or select an area on the artwork to share your feedback.</p></div>';
                return;
            }

            let html = '';
            comments.forEach(function(comment, index) {
                const label = annotationLabel(comment, index + 1);
                const escComment = comment.comment.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                const escName   = comment.user_name.replace(/</g,'&lt;').replace(/>/g,'&gt;');

                let attachHtml = '';
                if (comment.attachment) {
                    if (/\.(jpg|jpeg|png|gif|webp)$/i.test(comment.attachment)) {
                        attachHtml = '<div style="margin-top:0.75rem;"><img src="uploads/references/'
                            + comment.attachment
                            + '" style="max-width:100%;border-radius:8px;cursor:pointer;border:1px solid #e2e8f0;" onclick="window.open(this.src)"></div>';
                    } else {
                        attachHtml = '<div style="margin-top:0.75rem;"><a href="uploads/references/'
                            + comment.attachment + '" target="_blank" style="font-size:0.8rem;color:var(--primary-color);text-decoration:none;">'
                            + '<i class="fas fa-paperclip"></i> View Attachment</a></div>';
                    }
                }

                // Replies
                let repliesHtml = '';
                if (comment.replies && comment.replies.length) {
                    comment.replies.forEach(function(r){
                        const rName = r.user_name.replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        const rText = r.comment.replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                        repliesHtml += '<div class="reply-item">'
                            + '<div style="font-weight:700;font-size:0.8rem;">' + rName + '</div>'
                            + '<div style="color:#475569;">' + rText + '</div>'
                            + (isLatest ? '<button class="comment-delete-btn" type="button" data-delete-comment="' + r.id + '" style="margin-top:0.3rem;">Delete</button>' : '')
                            + '</div>';
                    });
                }

                // Actions only on latest version
                const actionsHtml = isLatest
                    ? '<span class="btn-reply-toggle" onclick="toggleReplyForm(' + comment.id + ')">Reply</span>'
                      + '<button class="comment-delete-btn" type="button" data-delete-comment="' + comment.id + '">Delete</button>'
                    : '<span style="font-size:0.72rem;color:#94a3b8;">View only</span>';

                html += '<div class="comment-item" data-id="' + comment.id
                    + '" data-type="' + (comment.type||'point')
                    + '" data-comment-kind="' + label + '">'
                    + '<div class="comment-author"><span class="comment-badge">' + (index+1) + '</span>' + escName + '</div>'
                    + '<div class="comment-location"><i class="fas fa-location-crosshairs"></i> ' + label + '</div>'
                    + '<div class="comment-text">' + escComment + '</div>'
                    + attachHtml
                    + '<div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.75rem;display:flex;justify-content:space-between;align-items:center;">'
                    + '<span><i class="far fa-clock"></i> ' + fmtDate(comment.created_at) + '</span>'
                    + '<div class="comment-actions">' + actionsHtml + '</div>'
                    + '</div>'
                    + '<div class="reply-list" id="replies-' + comment.id + '">' + repliesHtml + '</div>'
                    + (isLatest ? '<div class="reply-input-box" id="reply-box-' + comment.id + '">'
                        + '<textarea class="reply-textarea" placeholder="Write a reply..." style="width:100%;padding:0.5rem;border-radius:8px;border:1px solid #e2e8f0;font-size:0.85rem;height:60px;resize:none;outline:none;margin-top:0.5rem;"></textarea>'
                        + '<button class="btn-save" style="width:100%;padding:0.4rem;font-size:0.8rem;margin-top:0.5rem;" onclick="saveReply(' + comment.id + ')">Post Reply</button>'
                        + '</div>' : '')
                    + '</div>';
            });
            list.innerHTML = html;

            // Re-attach delete handlers
            if (typeof window.attachDeleteHandlers === 'function') {
                window.attachDeleteHandlers();
            }
        }

        window.renderVersionComments = renderVersionComments;

        function triggerPrintDesign() {
            var scale = 1;
            if (typeof window.getReviewZoomScale === 'function') {
                var nextScale = Number(window.getReviewZoomScale());
                if (Number.isFinite(nextScale) && nextScale > 0) {
                    scale = nextScale;
                }
            }
            document.documentElement.style.setProperty('--print-scale', String(scale));
            document.body.classList.add('print-mode');
            window.print();
        }

        window.addEventListener('afterprint', function () {
            document.body.classList.remove('print-mode');
            document.documentElement.style.removeProperty('--print-scale');
        });

        // Client portal deterrents: allow print but discourage direct downloads/saving.
        document.addEventListener('contextmenu', function (event) {
            if (event.target.closest('#artwork-wrapper')) {
                event.preventDefault();
            }
        });

        document.addEventListener('dragstart', function (event) {
            if (event.target.closest('#artwork-wrapper img') || event.target.closest('#artwork-wrapper canvas')) {
                event.preventDefault();
            }
        });

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S' || event.key === 'u' || event.key === 'U')) {
                event.preventDefault();
            }
        });
    </script>
    <script src="assets/js/pins.js?v=<?php echo filemtime(__DIR__ . '/assets/js/pins.js'); ?>"></script>
</body>
</html>
