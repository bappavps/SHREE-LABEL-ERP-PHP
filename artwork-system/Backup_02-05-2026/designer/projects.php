<?php
$pageTitle = 'All Projects';
$activePage = 'projects';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$status = isset($_GET['status']) ? sanitize($_GET['status']) : '';

$query = "SELECT p.*, f.filename 
          FROM projects p 
          LEFT JOIN files f ON p.id = f.project_id AND f.version = (SELECT MAX(version) FROM files WHERE project_id = p.id)
          WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (p.title LIKE ? OR p.client_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status) {
    $query .= " AND p.status = ?";
    $params[] = $status;
}

$query .= " ORDER BY p.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$projects = $stmt->fetchAll();
$isAdmin = (($designer['role'] ?? '') === 'admin');
?>

<div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2 style="font-weight: 800; letter-spacing: -0.02em;">Projects Library</h2>
    <div style="display: flex; gap: 1rem;">
        <form action="" method="GET" style="display: flex; gap: 0.5rem;">
            <select name="status" onchange="this.form.submit()" style="padding: 0.75rem 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; font-size: 0.9rem;">
                <option value="">All Statuses</option>
                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="changes" <?php echo $status === 'changes' ? 'selected' : ''; ?>>Changes Requested</option>
                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
            </select>
            <div class="search-bar" style="width: 250px;">
                <i class="fas fa-search"></i>
                <input type="text" name="search" placeholder="Search..." value="<?php echo $search; ?>">
            </div>
        </form>
    </div>
</div>

<div class="project-grid">
    <?php if (empty($projects)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 5rem; background: white; border-radius: 24px; box-shadow: var(--shadow-md);">
            <div style="width: 80px; height: 80px; background: #f1f5f9; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2rem; color: #cbd5e1;">
                <i class="fas fa-search"></i>
            </div>
            <h3 style="margin-bottom: 0.5rem;">No projects found</h3>
            <p style="color: var(--text-muted);">Try adjusting your search or filters.</p>
            <button class="btn-primary" style="margin: 1.5rem auto 0;" onclick="window.location.href='new-project.php'">
                <i class="fas fa-plus"></i> Create New Project
            </button>
        </div>
    <?php else: ?>
        <?php foreach ($projects as $project): ?>
            <div class="project-card" onclick="window.location.href='project-details.php?id=<?php echo $project['id']; ?>'" style="cursor: pointer;">
                <div class="project-thumb">
                    <?php if ($project['filename'] && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $project['filename'])): ?>
                        <img src="../uploads/projects/<?php echo $project['filename']; ?>" alt="Preview">
                    <?php else: ?>
                        <i class="fas <?php echo getFileIcon(pathinfo($project['filename'] ?? '', PATHINFO_EXTENSION)); ?>" style="font-size: 3.5rem; color: #cbd5e1;"></i>
                    <?php endif; ?>
                    <span class="status-badge status-<?php echo $project['status']; ?>"><?php echo $project['status']; ?></span>
                </div>
                <div class="project-info">
                    <h4 class="project-title"><?php echo sanitize($project['title']); ?></h4>
                    <p class="project-client"><i class="far fa-user"></i> <?php echo sanitize($project['client_name']); ?></p>
                    <div class="project-meta">
                        <span><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($project['created_at'])); ?></span>
                        <?php
                            $commentCount = $db->prepare("SELECT COUNT(*) FROM comments c JOIN files f ON c.file_id = f.id WHERE f.project_id = ?");
                            $commentCount->execute([$project['id']]);
                            $count = $commentCount->fetchColumn();
                        ?>
                        <span><i class="far fa-comment-dots"></i> <?php echo $count; ?></span>
                        <?php if ($isAdmin): ?>
                            <button
                                type="button"
                                title="Delete Project"
                                onclick="event.stopPropagation(); deleteProject(<?php echo (int) $project['id']; ?>, '<?php echo sanitize(addslashes($project['title'])); ?>');"
                                style="margin-left:auto; border:none; border-radius:999px; background:#fee2e2; color:#b91c1c; cursor:pointer; width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center;"
                            >
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<style>
.dashboard-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.48);
    z-index: 2200;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.dashboard-modal-overlay.open {
    display: flex;
}

.dashboard-modal {
    width: min(420px, calc(100vw - 2rem));
    background: #ffffff;
    border: 1px solid #dbeafe;
    border-radius: 14px;
    box-shadow: 0 34px 70px -28px rgba(15, 23, 42, 0.5);
    overflow: hidden;
}

.dashboard-modal-head {
    padding: 0.9rem 1rem;
    font-size: 0.95rem;
    font-weight: 800;
    color: #0f172a;
    background: #f8fbff;
    border-bottom: 1px solid #e2e8f0;
}

.dashboard-modal-body {
    padding: 1rem;
    color: #334155;
    font-size: 0.9rem;
    line-height: 1.5;
    white-space: pre-wrap;
}

.dashboard-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.55rem;
    padding: 0 1rem 1rem;
}

.dashboard-modal-btn {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    border-radius: 10px;
    padding: 0.5rem 0.9rem;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
}

.dashboard-modal-btn.primary {
    border-color: transparent;
    background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
    color: #ffffff;
}
</style>

<div class="dashboard-modal-overlay" id="dashboard-modal-overlay" aria-hidden="true">
    <div class="dashboard-modal" role="dialog" aria-modal="true" aria-labelledby="dashboard-modal-title">
        <div class="dashboard-modal-head" id="dashboard-modal-title">Notice</div>
        <div class="dashboard-modal-body" id="dashboard-modal-message"></div>
        <div class="dashboard-modal-actions">
            <button type="button" class="dashboard-modal-btn" id="dashboard-modal-cancel" style="display:none;">Cancel</button>
            <button type="button" class="dashboard-modal-btn primary" id="dashboard-modal-ok">OK</button>
        </div>
    </div>
</div>

<script>
const dashboardModal = {
    overlay: document.getElementById('dashboard-modal-overlay'),
    title: document.getElementById('dashboard-modal-title'),
    message: document.getElementById('dashboard-modal-message'),
    ok: document.getElementById('dashboard-modal-ok'),
    cancel: document.getElementById('dashboard-modal-cancel')
};

function closeDashboardModal() {
    dashboardModal.overlay.classList.remove('open');
    dashboardModal.overlay.setAttribute('aria-hidden', 'true');
}

function showDashboardModal({ title = 'Notice', message = '', confirm = false }) {
    return new Promise((resolve) => {
        dashboardModal.title.textContent = title;
        dashboardModal.message.textContent = message;
        dashboardModal.cancel.style.display = confirm ? 'inline-flex' : 'none';
        dashboardModal.ok.textContent = confirm ? 'Confirm' : 'OK';
        dashboardModal.overlay.classList.add('open');
        dashboardModal.overlay.setAttribute('aria-hidden', 'false');

        const onOk = () => {
            cleanup();
            closeDashboardModal();
            resolve(true);
        };
        const onCancel = () => {
            cleanup();
            closeDashboardModal();
            resolve(false);
        };
        const onOverlay = (event) => {
            if (event.target === dashboardModal.overlay && confirm) {
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
            dashboardModal.ok.removeEventListener('click', onOk);
            dashboardModal.cancel.removeEventListener('click', onCancel);
            dashboardModal.overlay.removeEventListener('click', onOverlay);
            document.removeEventListener('keydown', onEsc);
        };

        dashboardModal.ok.addEventListener('click', onOk);
        dashboardModal.cancel.addEventListener('click', onCancel);
        dashboardModal.overlay.addEventListener('click', onOverlay);
        document.addEventListener('keydown', onEsc);
    });
}

function notify(message, title = 'Notice') {
    return showDashboardModal({ title, message, confirm: false });
}

function confirmAction(message, title = 'Please Confirm') {
    return showDashboardModal({ title, message, confirm: true });
}

async function deleteProject(projectId, projectTitle) {
    const allow = await confirmAction('Delete project "' + projectTitle + '"? This will remove all files, comments and history.');
    if (!allow) {
        return;
    }

    const fd = new FormData();
    fd.append('project_id', String(projectId));

    fetch('api/delete-project.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.status !== 'success') {
            notify(data.message || 'Unable to delete project.');
            return;
        }
        location.reload();
    })
    .catch(() => {
        notify('Failed to delete project.');
    });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
