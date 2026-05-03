<?php
$pageTitle = 'Project Details';
$activePage = 'projects';
require_once __DIR__ . '/../includes/header.php';

// Read company name dynamically from ERP settings (non-hardcoded)
$_erpSettingsFile = __DIR__ . '/../../data/app_settings.json';
$_erpCompanyName = '';
if (file_exists($_erpSettingsFile)) {
    $_erpSettings = json_decode(file_get_contents($_erpSettingsFile), true);
    $_erpCompanyName = trim((string)($_erpSettings['company_name'] ?? ''));
}
if (!$_erpCompanyName) $_erpCompanyName = defined('APP_NAME') ? APP_NAME : 'Enterprise ERP';

$db = Db::getInstance();
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$projectId) redirect('index.php');

// Get Project
$stmt = $db->prepare("SELECT * FROM artwork_projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) redirect('index.php');

// Get Files
$stmt = $db->prepare("SELECT * FROM artwork_files WHERE project_id = ? ORDER BY version DESC");
$stmt->execute([$projectId]);
$files = $stmt->fetchAll();
$latestVersion = !empty($files) ? (int) $files[0]['version'] : 0;
$firstVersion = !empty($files) ? (int) $files[count($files) - 1]['version'] : 0;

// Get Activity
$stmt = $db->prepare("SELECT * FROM artwork_activity_log WHERE project_id = ? ORDER BY created_at DESC LIMIT 80");
$stmt->execute([$projectId]);
$activities = $stmt->fetchAll();

$stats = [
    'created_at' => $project['created_at'],
    'last_activity_at' => null,
    'last_correction_at' => null,
    'approval_at' => null,
    'total_corrections' => 0,
    'total_revisions' => 0,
    'approval_lead' => 'Not approved yet',
];

if (!empty($activities)) {
    $stats['last_activity_at'] = $activities[0]['created_at'];
}

$stmt = $db->prepare("SELECT COUNT(*) FROM artwork_comments c JOIN artwork_files f ON f.id = c.file_id WHERE f.project_id = ?");
$stmt->execute([$projectId]);
$stats['total_corrections'] = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM artwork_files WHERE project_id = ?");
$stmt->execute([$projectId]);
$totalFiles = (int) $stmt->fetchColumn();
$stats['total_revisions'] = max(0, $totalFiles - 1);

$correctionHistory = [];
$approvalAt = null;
foreach ($activities as $activity) {
    $actionText = (string) ($activity['action'] ?? '');
    $createdAt = (string) ($activity['created_at'] ?? '');

    if (
        stripos($actionText, 'Correction') !== false ||
        stripos($actionText, 'revision') !== false ||
        stripos($actionText, 'Changes requested') !== false ||
        stripos($actionText, 'New version') !== false ||
        stripos($actionText, 'Reply by') !== false
    ) {
        $correctionHistory[] = $activity;
        if ($stats['last_correction_at'] === null) {
            $stats['last_correction_at'] = $createdAt;
        }
    }

    if ($approvalAt === null && stripos($actionText, 'approved by client') !== false) {
        $approvalAt = $createdAt;
    }
}

if ($approvalAt !== null) {
    $stats['approval_at'] = $approvalAt;
    $created = new DateTime($project['created_at']);
    $approved = new DateTime($approvalAt);
    $diff = $created->diff($approved);
    $leadParts = [];
    if ($diff->days > 0) {
        $leadParts[] = $diff->days . ' day' . ($diff->days > 1 ? 's' : '');
    }
    if ($diff->h > 0) {
        $leadParts[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    }
    if (empty($leadParts)) {
        $leadParts[] = max(1, (int) $diff->i) . ' minute(s)';
    }
    $stats['approval_lead'] = implode(' ', $leadParts);
}

$latestFileId = !empty($files) ? (int) $files[0]['id'] : 0;
$comments = [];
if ($latestFileId > 0) {
    $commentStmt = $db->prepare("SELECT * FROM artwork_comments WHERE file_id = ? AND parent_id IS NULL ORDER BY created_at DESC LIMIT 20");
    $commentStmt->execute([$latestFileId]);
    $comments = $commentStmt->fetchAll();
}

function dashboardAnnotationLabel(array $comment): string {
    $type = $comment['type'] ?? 'point';
    if ($type === 'area') {
        return 'Area';
    }
    if ($type === 'arrow') {
        return 'Arrow Callout';
    }
    if ($type === 'pen') {
        return 'Pen Markup';
    }
    if ($type === 'highlighter') {
        return 'Highlight';
    }
    return 'Pin';
}

$reviewLink = ARTWORK_BASE_URL . "/review.php?token=" . $project['token'] . "&view=client";
$portalReviewLink = ARTWORK_BASE_URL . "/review.php?token=" . $project['token'] . "&open=1&view=designer";
$finalFileStmt = $db->prepare("SELECT * FROM artwork_files WHERE project_id = ? AND is_final = 1 ORDER BY version DESC LIMIT 1");
$finalFileStmt->execute([$projectId]);
$finalFile = $finalFileStmt->fetch();
$isAdmin = (($designer['role'] ?? '') === 'admin');
?>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
    <div>
        <h2 style="font-weight: 700; margin-bottom: 0.25rem;"><?php echo sanitize($project['title']); ?></h2>
        <p style="color: var(--text-muted); font-size: 0.9rem;">
            Client: <strong><?php echo sanitize($project['client_name']); ?></strong> | 
            Status: <span class="status-badge status-<?php echo $project['status']; ?>" style="position: static; font-size: 0.7rem;"><?php echo $project['status']; ?></span>
        </p>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-top:0.45rem;">
            <?php if (!empty($project['job_name'])): ?>
                <span style="font-size:0.68rem; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.2rem 0.48rem; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">Job: <?php echo sanitize($project['job_name']); ?></span>
            <?php endif; ?>
            <?php if (!empty($project['job_size'])): ?>
                <span style="font-size:0.68rem; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.2rem 0.48rem; background:#ecfeff; color:#0f766e; border:1px solid #99f6e4;">Size: <?php echo sanitize($project['job_size']); ?></span>
            <?php endif; ?>
            <?php if (!empty($project['job_color'])): ?>
                <span style="font-size:0.68rem; font-weight:700; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.2rem 0.48rem; background:#fef3c7; color:#92400e; border:1px solid #fcd34d;">Color: <?php echo sanitize($project['job_color']); ?></span>
            <?php endif; ?>
        </div>
        <?php if (!empty($project['job_remark'])): ?>
            <p style="margin:0.55rem 0 0; font-size:0.8rem; color:#475569; max-width:760px; line-height:1.45;">
                <strong style="color:#0f172a;">Remark:</strong> <?php echo nl2br(sanitize($project['job_remark'])); ?>
            </p>
        <?php endif; ?>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; max-width: 500px;">
        <button class="btn-primary" style="background: #25d366; color: white; border: none; padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="shareWhatsApp()">
            <i class="fab fa-whatsapp"></i> WhatsApp
        </button>
        <button class="btn-primary" style="background: #ea4335; color: white; border: none; padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="shareEmail()">
            <i class="far fa-envelope"></i> Email
        </button>
        <button class="btn-primary" style="background: white; color: var(--text-main); border: 1px solid #e2e8f0; padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="copyToClipboard('<?php echo $reviewLink; ?>')">
            <i class="fas fa-link"></i> Copy Link
        </button>
        <button class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="openPortal()">
            <i class="fas fa-eye"></i> View Portal
        </button>
        <button class="btn-primary" style="background: #0f766e; padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="regenerateSecureLink()">
            <i class="fas fa-rotate"></i> Regenerate Link
        </button>
        <a href="api/download-zip.php?id=<?php echo $projectId; ?>" class="btn-primary" style="background: #64748b; padding: 0.5rem 1rem; font-size: 0.8rem; text-decoration: none;">
            <i class="fas fa-file-archive"></i> ZIP
        </a>
    </div>
</div>

<?php if ($finalFile): ?>
<div class="glass-card" style="background: #ecfeff; border: 1px solid #99f6e4; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
    <strong style="display:block; margin-bottom:0.3rem;">Final Approved File Ready</strong>
    <span style="font-size:0.85rem; color:var(--text-muted); display:block; margin-bottom:0.7rem;">
        <?php echo sanitize($finalFile['original_name']); ?> (Version <?php echo (int) $finalFile['version']; ?>)
    </span>
    <a href="../uploads/final/<?php echo 'project_' . (int) $projectId . '_final_' . $finalFile['filename']; ?>" target="_blank" class="btn-primary" style="text-decoration:none; padding:0.45rem 0.8rem; font-size:0.8rem;">
        <i class="fas fa-folder-open"></i> View Final Folder
    </a>
</div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
    <div class="left-col">
        <div class="glass-card" style="background: white; padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-soft); margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem; font-weight: 700;">Files & Versions</h3>
            <div class="file-list">
                <?php foreach ($files as $file): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="width: 48px; height: 48px; background: #f8fafc; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #cbd5e1;">
                                <i class="fas <?php echo getFileIcon($file['file_type']); ?>"></i>
                            </div>
                            <div>
                                <p style="font-weight: 600; margin-bottom: 0.1rem;"><?php echo sanitize($file['original_name']); ?></p>
                                <p style="font-size: 0.75rem; color: var(--text-muted);">Version <?php echo $file['version']; ?> • <?php echo date('M d, H:i', strtotime($file['uploaded_at'])); ?></p>
                                <div style="display:flex; gap:0.35rem; margin-top:0.3rem; flex-wrap:wrap;">
                                    <?php if ((int) $file['version'] === $firstVersion): ?>
                                        <span style="font-size:0.62rem; font-weight:800; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.15rem 0.42rem; background:#e0f2fe; color:#075985;">First Upload</span>
                                    <?php endif; ?>
                                    <?php if ((int) $file['version'] === $latestVersion): ?>
                                        <span style="font-size:0.62rem; font-weight:800; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.15rem 0.42rem; background:#ede9fe; color:#5b21b6;">Latest</span>
                                    <?php endif; ?>
                                    <?php if ((int) ($file['is_final'] ?? 0) === 1): ?>
                                        <span style="font-size:0.62rem; font-weight:800; letter-spacing:0.03em; text-transform:uppercase; border-radius:999px; padding:0.15rem 0.42rem; background:#dcfce7; color:#166534;">Final</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="../uploads/projects/<?php echo $file['filename']; ?>" download class="btn-primary" style="background: #f1f5f9; color: var(--text-main); padding: 0.5rem;" title="Download"><i class="fas fa-download"></i></a>
                            <?php if ($isAdmin): ?>
                                <button class="btn-primary" style="background: #14532d; color: #fff; padding: 0.5rem 0.9rem; font-size: 0.75rem;" onclick="markAsFinal(<?php echo (int) $file['id']; ?>)">
                                    <i class="fas fa-seal-check"></i> Final
                                </button>
                            <?php endif; ?>
                            <?php if ($file['version'] == $files[0]['version']): ?>
                                <button class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.8rem;" onclick="openRevisionModal()">Upload Revision</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="right-col">
        <div class="glass-card" style="background: white; padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-soft); margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 700;">Project Tracking</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:0.7rem;">
                    <p style="margin:0; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#64748b;">Job Created</p>
                    <p style="margin:0.3rem 0 0; font-size:0.82rem; font-weight:700;"><?php echo date('M d, Y H:i', strtotime($stats['created_at'])); ?></p>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:0.7rem;">
                    <p style="margin:0; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#64748b;">Last Activity</p>
                    <p style="margin:0.3rem 0 0; font-size:0.82rem; font-weight:700;"><?php echo $stats['last_activity_at'] ? date('M d, Y H:i', strtotime($stats['last_activity_at'])) : 'N/A'; ?></p>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:0.7rem;">
                    <p style="margin:0; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#64748b;">Total Corrections</p>
                    <p style="margin:0.3rem 0 0; font-size:1rem; font-weight:800; color:#0f766e;"><?php echo (int) $stats['total_corrections']; ?></p>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:0.7rem;">
                    <p style="margin:0; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#64748b;">Total Revisions</p>
                    <p style="margin:0.3rem 0 0; font-size:1rem; font-weight:800; color:#0f172a;"><?php echo (int) $stats['total_revisions']; ?></p>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:0.7rem; grid-column:1 / -1;">
                    <p style="margin:0; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; color:#64748b;">Approval Lead Time</p>
                    <p style="margin:0.3rem 0 0; font-size:0.92rem; font-weight:800; color:#14532d;"><?php echo sanitize($stats['approval_lead']); ?></p>
                    <p style="margin:0.35rem 0 0; font-size:0.75rem; color:#64748b;">
                        Approval date: <?php echo $stats['approval_at'] ? date('M d, Y H:i', strtotime($stats['approval_at'])) : 'Not approved yet'; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="glass-card" style="background: white; padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-soft); margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem; font-weight: 700;">Activity Timeline</h3>
            <div class="timeline" style="border-left: 2px solid #f1f5f9; padding-left: 1.5rem; margin-left: 0.5rem; max-height: 280px; overflow-y: auto; padding-right: 0.2rem;">
                <?php if (empty($activities)): ?>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">No timeline activity found yet.</p>
                <?php else: ?>
                    <?php $clientNameLower = strtolower(trim((string)($project['client_name'] ?? ''))); ?>
                    <?php foreach ($activities as $activity): ?>
                        <?php
                        $actText = strtolower((string)($activity['action'] ?? ''));
                        // Client entry if the action text contains the client's name
                        $isClientAct = $clientNameLower !== '' && str_contains($actText, $clientNameLower);
                        $dotColor = $isClientAct ? '#f97316' : '#0d9488';
                        $textColor = $isClientAct ? '#c2410c' : '#0f766e';
                        $bgColor = $isClientAct ? '#fff7ed' : '#f0fdfa';
                        $borderColor = $isClientAct ? '#fed7aa' : '#99f6e4';
                        ?>
                        <div style="position: relative; margin-bottom: 1.5rem; background:<?php echo $bgColor; ?>; border:1px solid <?php echo $borderColor; ?>; border-radius:8px; padding:0.55rem 0.65rem 0.45rem 0.65rem;">
                            <div style="position: absolute; left: -1.9rem; top: 0.55rem; width: 12px; height: 12px; background: <?php echo $dotColor; ?>; border-radius: 50%; border: 3px solid white;"></div>
                            <p style="font-size: 0.85rem; margin-bottom: 0.2rem; color:<?php echo $textColor; ?>; font-weight:600;"><?php echo sanitize($activity['action']); ?></p>
                            <p style="font-size: 0.7rem; color: var(--text-muted);"><?php echo date('M d, H:i', strtotime($activity['created_at'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass-card" style="background: white; padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-soft); margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 700;">Correction History</h3>
            <?php if (empty($correctionHistory)): ?>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">No correction-cycle events found yet.</p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:0.55rem; max-height:280px; overflow-y:auto; padding-right:0.2rem;">
                    <?php foreach ($correctionHistory as $entry): ?>
                        <div style="border:1px solid #e2e8f0; border-radius:10px; padding:0.6rem 0.65rem; background:#f8fafc;">
                            <p style="margin:0 0 0.2rem 0; font-size:0.8rem; color:#0f172a;"><?php echo sanitize($entry['action']); ?></p>
                            <p style="margin:0; font-size:0.7rem; color:#64748b;"><?php echo date('M d, Y H:i', strtotime($entry['created_at'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="glass-card" style="background: white; padding: 1.5rem; border-radius: 20px; box-shadow: var(--shadow-soft); margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1rem; font-weight: 700;">Client Comments &amp; Callouts</h3>
            <?php if (empty($comments)): ?>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">No comments yet on the latest file.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 480px; overflow-y: auto; padding-right: 0.25rem;">
                    <?php foreach ($comments as $comment): ?>
                        <div style="border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.8rem; background: #fcfdff;">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.45rem;">
                                <span style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #0f766e; background: #ecfeff; border: 1px solid #99f6e4; border-radius: 999px; padding: 0.16rem 0.5rem;">
                                    <?php echo sanitize(dashboardAnnotationLabel($comment)); ?>
                                </span>
                                <span style="font-size: 0.72rem; color: var(--text-muted); white-space: nowrap;">
                                    <?php echo date('M d, H:i', strtotime($comment['created_at'])); ?>
                                </span>
                            </div>
                            <p style="font-size: 0.85rem; margin: 0 0 0.5rem 0; color: #334155; line-height: 1.45;">
                                <?php echo nl2br(sanitize($comment['comment'])); ?>
                            </p>
                            <p style="font-size: 0.72rem; margin: 0; color: #64748b;">
                                by <?php echo sanitize($comment['user_name'] ?: 'Client'); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.project-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.48);
    z-index: 2200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

/* Portal iframe overlay */
#portal-overlay {
    position: fixed;
    inset: 0;
    z-index: 3000;
    display: none;
    flex-direction: column;
    background: #0f172a;
}
#portal-overlay.open { display: flex; }
#portal-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 1rem;
    background: #1e293b;
    border-bottom: 1px solid #334155;
    flex-shrink: 0;
}
#portal-topbar span {
    color: #94a3b8;
    font-size: 0.82rem;
    font-family: inherit;
}
#portal-topbar a {
    color: #38bdf8;
    font-size: 0.82rem;
    text-decoration: none;
    margin-right: 1rem;
}
#portal-topbar a:hover { text-decoration: underline; }
#portal-close-btn {
    background: #ef4444;
    color: #fff;
    border: none;
    padding: 0.35rem 0.9rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.82rem;
    font-family: inherit;
}
#portal-iframe {
    flex: 1;
    width: 100%;
    border: none;
}

.project-modal-overlay.open {
    display: flex;
}

.project-modal {
    width: min(430px, calc(100vw - 2rem));
    background: #ffffff;
    border: 1px solid #dbeafe;
    border-radius: 14px;
    box-shadow: 0 34px 70px -28px rgba(15, 23, 42, 0.5);
    overflow: hidden;
}

.project-modal-head {
    padding: 0.9rem 1rem;
    font-size: 0.95rem;
    font-weight: 800;
    color: #0f172a;
    background: #f8fbff;
    border-bottom: 1px solid #e2e8f0;
}

.project-modal-body {
    padding: 1rem;
    color: #334155;
    font-size: 0.9rem;
    line-height: 1.5;
    white-space: pre-wrap;
}

.project-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.55rem;
    padding: 0 1rem 1rem;
}

.project-modal-btn {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    border-radius: 10px;
    padding: 0.5rem 0.9rem;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
}

.project-modal-btn.primary {
    border-color: transparent;
    background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
    color: #ffffff;
}

.upload-progress-wrap {
    padding: 1rem;
}

.upload-progress-file {
    margin: 0 0 0.55rem;
    font-size: 0.82rem;
    color: #334155;
    font-weight: 600;
    word-break: break-all;
}

.upload-progress-bar-track {
    width: 100%;
    height: 10px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
    border: 1px solid #cbd5e1;
}

.upload-progress-bar {
    width: 0%;
    height: 100%;
    background: linear-gradient(90deg, #0f766e 0%, #14b8a6 100%);
    transition: width 0.18s ease;
}

.upload-progress-meta {
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    font-size: 0.78rem;
    color: #475569;
}

.upload-progress-percent {
    font-weight: 800;
    color: #0f766e;
}

.upload-progress-status {
    font-weight: 600;
}
</style>

<!-- Client Portal iframe overlay -->
<div id="portal-overlay" aria-hidden="true">
    <div id="portal-topbar">
        <span><i class="fas fa-eye" style="margin-right:0.4rem;"></i>Client View Portal &mdash; <?php echo htmlspecialchars($project['title']); ?></span>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <a href="<?php echo $portalReviewLink; ?>" target="_blank" title="Open in new tab"><i class="fas fa-external-link-alt"></i> Open in new tab</a>
            <button id="portal-close-btn" onclick="closePortal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
    <iframe id="portal-iframe" src="" title="Client Review Portal"></iframe>
</div>

<div class="project-modal-overlay" id="project-modal-overlay" aria-hidden="true">
    <div class="project-modal" role="dialog" aria-modal="true" aria-labelledby="project-modal-title">
        <div class="project-modal-head" id="project-modal-title">Notice</div>
        <div class="project-modal-body" id="project-modal-message"></div>
        <div class="project-modal-actions">
            <button type="button" class="project-modal-btn" id="project-modal-cancel" style="display:none;">Cancel</button>
            <button type="button" class="project-modal-btn primary" id="project-modal-ok">OK</button>
        </div>
    </div>
</div>

<div class="project-modal-overlay" id="upload-progress-overlay" aria-hidden="true">
    <div class="project-modal" role="dialog" aria-modal="true" aria-labelledby="upload-progress-title">
        <div class="project-modal-head" id="upload-progress-title">Uploading Revision</div>
        <div class="upload-progress-wrap">
            <p class="upload-progress-file" id="upload-progress-file">Preparing file...</p>
            <div class="upload-progress-bar-track">
                <div class="upload-progress-bar" id="upload-progress-bar"></div>
            </div>
            <div class="upload-progress-meta">
                <span class="upload-progress-status" id="upload-progress-status">Starting upload...</span>
                <span class="upload-progress-percent" id="upload-progress-percent">0%</span>
            </div>
        </div>
    </div>
</div>

<script>
// Client portal iframe
const portalOverlay = document.getElementById('portal-overlay');
const portalIframe  = document.getElementById('portal-iframe');

function openPortal() {
    portalIframe.src = '<?php echo $portalReviewLink; ?>';
    portalOverlay.classList.add('open');
    portalOverlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closePortal() {
    portalOverlay.classList.remove('open');
    portalOverlay.setAttribute('aria-hidden', 'true');
    portalIframe.src = '';
    document.body.style.overflow = '';
}

// Close on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && portalOverlay.classList.contains('open')) closePortal();
});

const projectShareMeta = {
    title: <?php echo json_encode((string) ($project['title'] ?? '')); ?>,
    clientName: <?php echo json_encode((string) ($project['client_name'] ?? '')); ?>,
    jobName: <?php echo json_encode((string) ($project['job_name'] ?? '')); ?>,
    jobSize: <?php echo json_encode((string) ($project['job_size'] ?? '')); ?>,
    jobColor: <?php echo json_encode((string) ($project['job_color'] ?? '')); ?>,
    remark: <?php echo json_encode((string) ($project['job_remark'] ?? '')); ?>,
    createdAt: <?php echo json_encode((string) ($project['created_at'] ?? '')); ?>,
    reviewLink: <?php echo json_encode((string) $reviewLink); ?>,
    portalLink: <?php echo json_encode((string) $portalReviewLink); ?>,
    appName: <?php echo json_encode($_erpCompanyName); ?>
};

function formatDateTime(value) {
    if (!value) return 'N/A';
    const dt = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(dt.getTime())) return value;
    return dt.toLocaleString();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function buildEmailDraft(linkValue) {
    const jobName = projectShareMeta.jobName || projectShareMeta.title || 'N/A';
    const details = [
        `Client Name: ${projectShareMeta.clientName || 'N/A'}`,
        `Job Name/ID: ${jobName}`,
        `Job Size: ${projectShareMeta.jobSize || 'N/A'}`,
        `Job Color: ${projectShareMeta.jobColor || 'N/A'}`,
        `Remarks: ${projectShareMeta.remark || 'N/A'}`,
        `Project Created On: ${formatDateTime(projectShareMeta.createdAt)}`,
        `Mail Drafted On: ${new Date().toLocaleString()}`
    ].join('\n');

    return [
        `To: ${projectShareMeta.clientName || 'Client'} <client-email@example.com>`,
        `Subject: Artwork Review Required - ${jobName}`,
        '',
        'Dear Valued Client,',
        '',
        `Greetings from ${projectShareMeta.appName || 'Artwork Approval Hub'}.`,
        '',
        `We hope you are doing well. This is to formally notify you that the artwork package for "${jobName}" is now ready for your review and approval.`,
        'For your convenience, we have shared the complete project brief and approval access below.',
        '',
        'Project Summary:',
        details,
        '',
        'Action Required:',
        'Kindly review the artwork and share your approval or correction comments at your earliest convenience so we can proceed without delay.',
        '',
                `Review Button: Open Artwork Approval Portal`,
                `Secure Review URL: ${linkValue}`,
        '',
        'If you need any clarification, please reply to this email and our team will assist you immediately.',
        '',
        'Regards,',
        'Design & Prepress Team',
        'Artwork Approval Hub',
        projectShareMeta.appName || ''
    ].join('\n');
}

function buildEmailHtmlDraft(linkValue) {
        const jobName = projectShareMeta.jobName || projectShareMeta.title || 'N/A';
        const createdOn = formatDateTime(projectShareMeta.createdAt);
        const draftedOn = new Date().toLocaleString();

        return `
<div style="font-family:Segoe UI,Arial,sans-serif;background:#f3f7ff;padding:24px;color:#0f172a;line-height:1.6;">
    <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #dbeafe;border-radius:16px;overflow:hidden;box-shadow:0 24px 48px -28px rgba(15,23,42,0.4);">
        <div style="background:linear-gradient(135deg,#0f766e 0%,#0ea5e9 100%);padding:18px 22px;color:#ffffff;">
            <div style="font-size:18px;font-weight:700;">Artwork Approval Notification</div>
            <div style="font-size:13px;opacity:0.92;">Formal review request from Design &amp; Prepress Team</div>
        </div>
        <div style="padding:22px;">
            <p style="margin:0 0 12px;">Dear Valued Client,</p>
            <p style="margin:0 0 12px;">Greetings from <strong>${escapeHtml(projectShareMeta.appName || 'Artwork Approval Hub')}</strong>.</p>
            <p style="margin:0 0 16px;">We hope you are doing well. This is to formally notify you that the artwork package for <strong>${escapeHtml(jobName)}</strong> is now ready for your review and approval.</p>

            <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin:0 0 16px;">
                <div style="background:#f8fafc;padding:10px 12px;font-size:12px;font-weight:700;letter-spacing:.04em;color:#334155;text-transform:uppercase;">Project Summary</div>
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;padding:12px;">
                    <tr>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Client Name:</strong><br>${escapeHtml(projectShareMeta.clientName || 'N/A')}</td>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Job Name/ID:</strong><br>${escapeHtml(jobName)}</td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Job Size:</strong><br>${escapeHtml(projectShareMeta.jobSize || 'N/A')}</td>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Job Color:</strong><br>${escapeHtml(projectShareMeta.jobColor || 'N/A')}</td>
                    </tr>
                    <tr>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Project Created:</strong><br>${escapeHtml(createdOn)}</td>
                        <td width="50%" style="padding:5px 7px;vertical-align:top;"><strong>Mail Drafted:</strong><br>${escapeHtml(draftedOn)}</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding:5px 7px;vertical-align:top;"><strong>Remarks:</strong> ${escapeHtml(projectShareMeta.remark || 'N/A')}</td>
                    </tr>
                </table>
            </div>

            <p style="margin:0 0 16px;">Kindly review the artwork and share your approval or correction comments at your earliest convenience so we can proceed without delay.</p>

            <div style="text-align:center;margin:20px 0;">
                <a href="${escapeHtml(linkValue)}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 22px;border-radius:999px;background:linear-gradient(135deg,#0f766e 0%,#14b8a6 100%);color:#ffffff !important;text-decoration:none;font-weight:700;font-size:14px;">Open Artwork Approval Portal</a>
            </div>

            <p style="margin:0 0 10px;font-size:13px;color:#334155;">If the button does not open in compose mode, use this secure direct link:</p>
            <p style="margin:0 0 10px;font-size:13px;">
                <a href="${escapeHtml(linkValue)}" target="_blank" rel="noopener noreferrer" style="color:#0369a1;text-decoration:underline;word-break:break-all;">${escapeHtml(linkValue)}</a>
            </p>
            <p style="margin:0;font-size:13px;color:#334155;">If you need any clarification, please reply to this email and our team will assist you immediately.</p>

            <p style="margin:18px 0 0;">Regards,<br><strong>Design &amp; Prepress Team</strong><br>Artwork Approval Hub<br>${escapeHtml(projectShareMeta.appName || '')}</p>
        </div>
    </div>
</div>`.trim();
}

function buildWhatsAppTemplate(linkValue) {
    const jobName = projectShareMeta.jobName || projectShareMeta.title || 'N/A';
    return [
        'Hello,',
        `Please review artwork: ${jobName}`,
        `Client: ${projectShareMeta.clientName || 'N/A'}`,
        `Date/Time: ${new Date().toLocaleString()}`,
        `Link: ${linkValue}`,
        '',
        'Thanks.'
    ].join('\n');
}

async function getShortLink(url) {
    try {
        const response = await fetch('https://tinyurl.com/api-create.php?url=' + encodeURIComponent(url));
        if (!response.ok) return url;
        const tiny = (await response.text()).trim();
        if (/^https?:\/\/tinyurl\.com\//i.test(tiny)) {
            return tiny;
        }
    } catch (err) {
        console.warn('Short-link fallback to original URL:', err);
    }
    return url;
}

function copyDraftText(text, successMessage, htmlText = '') {
    if (htmlText && navigator.clipboard && window.ClipboardItem && navigator.clipboard.write) {
        const blobHtml = new Blob([htmlText], { type: 'text/html' });
        const blobText = new Blob([text], { type: 'text/plain' });
        return navigator.clipboard.write([
            new ClipboardItem({
                'text/html': blobHtml,
                'text/plain': blobText
            })
        ]).then(() => notify(successMessage))
          .catch(() => copyDraftText(text, successMessage));
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text)
            .then(() => notify(successMessage))
            .catch(() => notify('Auto-copy failed. Please copy manually.'));
    }

    const temp = document.createElement('textarea');
    temp.value = text;
    temp.style.position = 'fixed';
    temp.style.opacity = '0';
    document.body.appendChild(temp);
    temp.focus();
    temp.select();
    try {
        document.execCommand('copy');
        notify(successMessage);
    } catch (e) {
        notify('Auto-copy failed. Please copy manually.');
    }
    document.body.removeChild(temp);
    return Promise.resolve();
}

const projectModal = {
    overlay: document.getElementById('project-modal-overlay'),
    title: document.getElementById('project-modal-title'),
    message: document.getElementById('project-modal-message'),
    ok: document.getElementById('project-modal-ok'),
    cancel: document.getElementById('project-modal-cancel')
};

const uploadProgressUi = {
    overlay: document.getElementById('upload-progress-overlay'),
    file: document.getElementById('upload-progress-file'),
    bar: document.getElementById('upload-progress-bar'),
    percent: document.getElementById('upload-progress-percent'),
    status: document.getElementById('upload-progress-status')
};

function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (!Number.isFinite(size) || size <= 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = size;
    let idx = 0;
    while (value >= 1024 && idx < units.length - 1) {
        value /= 1024;
        idx += 1;
    }
    return value.toFixed(value >= 100 || idx === 0 ? 0 : 1) + ' ' + units[idx];
}

function showUploadProgress(fileName, fileSize) {
    if (!uploadProgressUi.overlay) return;
    uploadProgressUi.file.textContent = fileName + ' (' + formatFileSize(fileSize) + ')';
    uploadProgressUi.bar.style.width = '0%';
    uploadProgressUi.percent.textContent = '0%';
    uploadProgressUi.status.textContent = 'Starting upload...';
    uploadProgressUi.overlay.classList.add('open');
    uploadProgressUi.overlay.setAttribute('aria-hidden', 'false');
}

function updateUploadProgress(percent, statusText) {
    if (!uploadProgressUi.overlay) return;
    const safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
    uploadProgressUi.bar.style.width = safePercent + '%';
    uploadProgressUi.percent.textContent = safePercent + '%';
    if (statusText) {
        uploadProgressUi.status.textContent = statusText;
    }
}

function hideUploadProgress() {
    if (!uploadProgressUi.overlay) return;
    uploadProgressUi.overlay.classList.remove('open');
    uploadProgressUi.overlay.setAttribute('aria-hidden', 'true');
}

function closeProjectModal() {
    projectModal.overlay.classList.remove('open');
    projectModal.overlay.setAttribute('aria-hidden', 'true');
}

function showProjectModal({ title = 'Notice', message = '', confirm = false }) {
    return new Promise((resolve) => {
        projectModal.title.textContent = title;
        projectModal.message.textContent = message;
        projectModal.cancel.style.display = confirm ? 'inline-flex' : 'none';
        projectModal.ok.textContent = confirm ? 'Confirm' : 'OK';
        projectModal.overlay.classList.add('open');
        projectModal.overlay.setAttribute('aria-hidden', 'false');

        const onOk = () => {
            cleanup();
            closeProjectModal();
            resolve(true);
        };
        const onCancel = () => {
            cleanup();
            closeProjectModal();
            resolve(false);
        };
        const onOverlay = (event) => {
            if (event.target === projectModal.overlay && confirm) {
                onCancel();
            }
        };
        const onEsc = (event) => {
            if (event.key === 'Escape') {
                if (confirm) {
                    onCancel();
                } else {
                    onOk();
                }
            }
        };
        const cleanup = () => {
            projectModal.ok.removeEventListener('click', onOk);
            projectModal.cancel.removeEventListener('click', onCancel);
            projectModal.overlay.removeEventListener('click', onOverlay);
            document.removeEventListener('keydown', onEsc);
        };

        projectModal.ok.addEventListener('click', onOk);
        projectModal.cancel.addEventListener('click', onCancel);
        projectModal.overlay.addEventListener('click', onOverlay);
        document.addEventListener('keydown', onEsc);
    });
}

function notify(message, title = 'Notice') {
    return showProjectModal({ title, message, confirm: false });
}

function confirmAction(message, title = 'Please Confirm') {
    return showProjectModal({ title, message, confirm: true });
}

function shareWhatsApp() {
    getShortLink(projectShareMeta.reviewLink)
        .then((shortLink) => {
            const waText = buildWhatsAppTemplate(shortLink);
            copyDraftText(waText, 'WhatsApp template copied with share link.');
            window.open('https://wa.me/?text=' + encodeURIComponent(waText), '_blank');
        });
}

function shareEmail() {
    const emailTextDraft = buildEmailDraft(projectShareMeta.reviewLink);
    const emailHtmlDraft = buildEmailHtmlDraft(projectShareMeta.reviewLink);
    copyDraftText(
        emailTextDraft,
        'Modern email draft copied. Paste into mail body (compose view may not open links until sent).',
        emailHtmlDraft
    );
}

function copyToClipboard(text) {
    return copyDraftText(text, 'Review link copied to clipboard!');
}

async function regenerateSecureLink() {
    const allow = await confirmAction('Generate a new secure review token? Old link will stop working.');
    if (!allow) return;

    const fd = new FormData();
    fd.append('project_id', <?php echo (int) $projectId; ?>);

    fetch('api/regenerate-link.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            notify(data.message || 'Unable to regenerate link');
            return;
        }
        copyToClipboard(data.data.review_link);
        notify('Secure link regenerated and copied.');
        location.reload();
    })
    .catch(() => notify('Failed to regenerate link'));
}

async function markAsFinal(fileId) {
    const allow = await confirmAction('Mark this version as final approved file?');
    if (!allow) return;

    const fd = new FormData();
    fd.append('project_id', <?php echo (int) $projectId; ?>);
    fd.append('file_id', fileId);

    fetch('api/mark-final.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            notify(data.message || 'Unable to mark final file');
            return;
        }
        notify('Final file saved to uploads/final and project set to approved.');
        location.reload();
    })
    .catch(() => notify('Failed to mark final file'));
}

function openRevisionModal() {
    let fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = '.pdf,.png,.jpg,.jpeg,.gif,.webp,.ai,.eps,.svg';
    fileInput.onchange = e => {
        let file = e.target.files[0];
        if (!file) {
            return;
        }
        let formData = new FormData();
        formData.append('artwork', file);
        formData.append('project_id', <?php echo $projectId; ?>);
        formData.append('version', <?php echo $files[0]['version'] + 1; ?>);

        showUploadProgress(file.name, file.size);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/upload-revision.php', true);

        xhr.upload.onprogress = function (event) {
            if (!event.lengthComputable) {
                updateUploadProgress(0, 'Uploading...');
                return;
            }
            const percent = (event.loaded / event.total) * 100;
            updateUploadProgress(percent, 'Uploading...');
        };

        xhr.onload = function () {
            updateUploadProgress(100, 'Processing file...');
            let data = null;
            try {
                data = JSON.parse(xhr.responseText || '{}');
            } catch (err) {
                hideUploadProgress();
                notify('Upload failed (invalid server response)');
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data.status === 'success') {
                updateUploadProgress(100, 'Upload complete. Refreshing...');
                setTimeout(() => location.reload(), 250);
            } else {
                hideUploadProgress();
                notify((data && data.message) ? data.message : 'Upload failed');
            }
        };

        xhr.onerror = function () {
            hideUploadProgress();
            notify('Upload failed due to network/server error');
        };

        xhr.onabort = function () {
            hideUploadProgress();
            notify('Upload canceled');
        };

        xhr.send(formData);
    };
    fileInput.click();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
