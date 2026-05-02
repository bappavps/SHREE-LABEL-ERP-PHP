<?php
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();
getCurrentDesigner($db);

// Get stats
$totalProjects = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$pendingProjects = $db->query("SELECT COUNT(*) FROM projects WHERE status = 'pending'")->fetchColumn();
$approvedProjects = $db->query("SELECT COUNT(*) FROM projects WHERE status = 'approved'")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Get recent projects
$stmt = $db->prepare("
    SELECT p.*, f.filename 
    FROM projects p 
    LEFT JOIN files f ON p.id = f.project_id AND f.version = (SELECT MAX(version) FROM files WHERE project_id = p.id)
    ORDER BY p.created_at DESC 
    LIMIT 6
");
$stmt->execute();
$projects = $stmt->fetchAll();

$commentCountByProject = [];
if (!empty($projects)) {
    $commentCountStmt = $db->query("SELECT f.project_id, COUNT(c.id) AS total_comments FROM comments c JOIN files f ON c.file_id = f.id GROUP BY f.project_id");
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
            <div class="project-card" onclick="window.location.href='project-details.php?id=<?php echo $project['id']; ?>'" style="cursor: pointer;">
                <div class="project-thumb">
                    <?php if ($project['filename'] && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $project['filename'])): ?>
                        <img src="../uploads/projects/<?php echo $project['filename']; ?>" alt="Preview">
                    <?php else: ?>
                        <i class="fas <?php echo getFileIcon(pathinfo($project['filename'] ?? '', PATHINFO_EXTENSION)); ?>"></i>
                    <?php endif; ?>
                    <span class="status-badge status-<?php echo $project['status']; ?>"><?php echo $project['status']; ?></span>
                </div>
                <div class="project-info">
                    <h4 class="project-title"><?php echo sanitize($project['title']); ?></h4>
                    <p class="project-client"><?php echo sanitize($project['client_name']); ?></p>
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
