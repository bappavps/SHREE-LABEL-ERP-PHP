<?php
$pageTitle = 'Final Projects';
$activePage = 'final-projects';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();

$stmt = $db->query("SELECT p.id AS project_id, p.title, p.client_name, p.status, f.id AS file_id, f.filename, f.original_name, f.version, f.uploaded_at
                    FROM files f
                    INNER JOIN projects p ON p.id = f.project_id
                    WHERE f.is_final = 1
                    ORDER BY f.uploaded_at DESC");
$finalProjects = $stmt->fetchAll();
$isAdmin = (($designer['role'] ?? '') === 'admin');
?>

<div class="section-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.75rem;">
    <div>
        <h2 style="margin:0 0 0.25rem; font-weight:800; letter-spacing:-0.02em;">Final Projects</h2>
        <p style="margin:0; color:var(--text-muted); font-size:0.9rem;">Approved final artwork files are stored here.</p>
    </div>
    <?php if (!$isAdmin): ?>
        <span style="display:inline-flex; align-items:center; gap:0.45rem; font-size:0.8rem; font-weight:700; color:#7c2d12; background:#fff7ed; border:1px solid #fed7aa; padding:0.45rem 0.7rem; border-radius:999px;">
            <i class="fas fa-lock"></i> View only (Admin can edit/delete)
        </span>
    <?php endif; ?>
</div>

<div class="project-grid">
    <?php if (empty($finalProjects)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 3rem; background: white; border-radius: 20px;">
            <i class="fas fa-folder-open" style="font-size: 3rem; color: #e2e8f0; margin-bottom: 1rem; display: block;"></i>
            <p style="color: var(--text-muted); margin:0;">No final projects found yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($finalProjects as $final): ?>
            <div class="project-card">
                <div class="project-info">
                    <h4 class="project-title" style="margin-bottom:0.35rem;"><?php echo sanitize($final['title']); ?></h4>
                    <p class="project-client" style="margin-bottom:0.8rem;"><?php echo sanitize($final['client_name']); ?></p>
                    <div style="font-size:0.82rem; color:var(--text-muted); margin-bottom:0.85rem; line-height:1.45;">
                        <div><strong>File:</strong> <?php echo sanitize($final['original_name']); ?></div>
                        <div><strong>Version:</strong> v<?php echo (int) $final['version']; ?></div>
                        <div><strong>Finalized:</strong> <?php echo date('M d, Y H:i', strtotime($final['uploaded_at'])); ?></div>
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <a class="btn-primary" style="padding:0.5rem 0.85rem; font-size:0.78rem; text-decoration:none;" target="_blank" href="../uploads/final/<?php echo 'project_' . (int) $final['project_id'] . '_final_' . $final['filename']; ?>">
                            <i class="fas fa-eye"></i> View Final
                        </a>
                        <a class="btn-primary" style="padding:0.5rem 0.85rem; font-size:0.78rem; text-decoration:none; background:#0f172a;" href="project-details.php?id=<?php echo (int) $final['project_id']; ?>">
                            <i class="fas fa-pen"></i> Open Project
                        </a>
                        <?php if ($isAdmin): ?>
                            <button class="btn-primary" style="padding:0.5rem 0.85rem; font-size:0.78rem; background:#dc2626;" onclick="deleteFinalProject(<?php echo (int) $final['project_id']; ?>, <?php echo (int) $final['file_id']; ?>)">
                                <i class="fas fa-trash"></i> Delete Final
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
<?php endif; ?>

<script>
<?php if ($isAdmin): ?>
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

async function deleteFinalProject(projectId, fileId) {
    const allow = await confirmAction('Delete final file from this project?');
    if (!allow) {
        return;
    }

    const fd = new FormData();
    fd.append('project_id', String(projectId));
    fd.append('file_id', String(fileId));

    fetch('api/delete-final.php', {
        method: 'POST',
        body: fd
    }).then(res => res.json()).then(data => {
        if (data.status !== 'success') {
            notify(data.message || 'Unable to delete final file.');
            return;
        }
        location.reload();
    }).catch(() => {
        notify('Failed to delete final file.');
    });
}
<?php else: ?>
function deleteFinalProject() {}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
