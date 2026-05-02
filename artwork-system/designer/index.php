<?php
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();
getCurrentDesigner($db);

// Get stats
$totalProjects = $db->query("SELECT COUNT(*) FROM artwork_projects")->fetchColumn();
$pendingProjects = $db->query("SELECT COUNT(*) FROM artwork_projects WHERE status = 'pending'")->fetchColumn();
$approvedProjects = $db->query("SELECT COUNT(*) FROM artwork_projects WHERE status = 'approved'")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM artwork_users")->fetchColumn();

// Get recent projects
$stmt = $db->prepare("
    SELECT p.*, f.id AS latest_file_id, f.filename, f.original_name, f.uploaded_at 
    FROM artwork_projects p 
    LEFT JOIN artwork_files f ON p.id = f.project_id AND f.version = (SELECT MAX(version) FROM artwork_files WHERE project_id = p.id)
    ORDER BY p.created_at DESC 
    LIMIT 6
");
$stmt->execute();
$projects = $stmt->fetchAll();

$commentCountByProject = [];
if (!empty($projects)) {
    $commentCountStmt = $db->query("SELECT f.project_id, COUNT(c.id) AS total_comments FROM artwork_comments c JOIN artwork_files f ON c.file_id = f.id GROUP BY f.project_id");
    foreach ($commentCountStmt->fetchAll() as $row) {
        $commentCountByProject[(int) $row['project_id']] = (int) $row['total_comments'];
    }
}
?>

<div class="stats-grid">
    <div class="stat-card">
        <p class="user-role">Total Projects</p>
        <h2 class="project-title"><?php echo $totalProjects; ?></h2>
    </div>
    <div class="stat-card">
        <p class="user-role">Pending Approval</p>
        <h2 class="project-title" style="color: var(--status-pending);"><?php echo $pendingProjects; ?></h2>
    </div>
    <div class="stat-card">
        <p class="user-role">Approved</p>
        <h2 class="project-title" style="color: var(--status-approved);"><?php echo $approvedProjects; ?></h2>
    </div>
    <div class="stat-card">
        <p class="user-role">Total Users</p>
        <h2 class="project-title" style="color: #0f766e;"><?php echo $totalUsers; ?></h2>
    </div>
</div>

<div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <h3 style="font-weight: 700;">Recent Projects</h3>
    <a href="projects.php" style="color: var(--primary-color); text-decoration: none; font-size: 0.9rem; font-weight: 600;">View All</a>
</div>

<div class="project-grid">
    <?php if (empty($projects)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 3rem; background: white; border-radius: 20px;">
            <i class="fas fa-folder-open" style="font-size: 3rem; color: #e2e8f0; margin-bottom: 1rem; display: block;"></i>
            <p style="color: var(--text-muted);">No projects found. Create your first project to get started!</p>
        </div>
    <?php else: ?>
        <?php foreach ($projects as $project): ?>
            <div class="project-card" onclick="window.location.href='<?php echo BASE_URL; ?>/designer/project-details.php?id=<?php echo $project['id']; ?>'" style="cursor: pointer;">
                <div class="project-thumb">
                    <?php
                        $latestFileId = (int) ($project['latest_file_id'] ?? 0);
                        $filename = (string) ($project['filename'] ?? '');
                        $originalName = (string) ($project['original_name'] ?? '');
                        $uploadedAt = (string) ($project['uploaded_at'] ?? '');
                        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
                        $encodedFilename = rawurlencode($filename);
                    ?>
                    <?php if ($filename !== '' && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?>
                        <img src="../uploads/projects/<?php echo $encodedFilename; ?>" alt="Preview">
                    <?php elseif ($filename !== '' && $ext === 'pdf' && $latestFileId > 0): ?>
                        <canvas class="pdf-thumb-canvas" data-pdf-src="../preview.php?id=<?php echo $latestFileId; ?>" aria-label="PDF Preview"></canvas>
                        <span class="pdf-thumb-badge">PDF</span>
                    <?php else: ?>
                        <i class="fas <?php echo getFileIcon($ext); ?>"></i>
                    <?php endif; ?>
                    <div class="thumb-meta-strip">
                        <p class="thumb-file-name" title="<?php echo sanitize($originalName !== '' ? $originalName : $filename); ?>"><?php echo sanitize($originalName !== '' ? $originalName : ($filename !== '' ? $filename : 'No file uploaded')); ?></p>
                        <p class="thumb-file-sub"><?php echo sanitize($project['client_name']); ?> • <?php echo $uploadedAt !== '' ? date('M d, Y H:i', strtotime($uploadedAt)) : date('M d, Y H:i', strtotime($project['created_at'])); ?></p>
                    </div>
                    <span class="status-badge status-<?php echo $project['status']; ?>"><?php echo $project['status']; ?></span>
                </div>
                <div class="project-info">
                    <h4 class="project-title"><?php echo sanitize($project['title']); ?></h4>
                    <p class="project-client"><?php echo sanitize($project['client_name']); ?></p>
                    <p class="project-file-line" title="<?php echo sanitize($originalName !== '' ? $originalName : $filename); ?>">
                        <i class="far fa-file-lines"></i>
                        <?php echo sanitize($originalName !== '' ? $originalName : ($filename !== '' ? $filename : 'No file uploaded')); ?>
                    </p>
                    <p class="project-file-line">
                        <i class="far fa-clock"></i>
                        <?php echo $uploadedAt !== '' ? date('M d, Y H:i', strtotime($uploadedAt)) : date('M d, Y H:i', strtotime($project['created_at'])); ?>
                    </p>
                    <div class="project-meta">
                        <span><i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($project['created_at'])); ?></span>
                        <span><i class="far fa-comments"></i> <?php echo $commentCountByProject[(int) $project['id']] ?? 0; ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
