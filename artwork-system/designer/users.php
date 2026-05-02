<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Db.php';
require_once __DIR__ . '/../includes/functions.php';

$authDb = Db::getInstance();
requireAuthUser(['admin']);

$pageTitle = 'User Management';
$activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();
$stmt = $db->query("SELECT * FROM artwork_users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
?>

<div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h2 style="font-weight: 800; letter-spacing: -0.02em;">User Directory</h2>
    <button class="btn-primary" onclick="alert('This feature is coming soon in the full version!')">
        <i class="fas fa-user-plus"></i> Add New User
    </button>
</div>

<div class="glass-card" style="padding: 1.5rem; overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; text-align: left;">
        <thead>
            <tr style="border-bottom: 2px solid #f1f5f9;">
                <th style="padding: 1rem; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">User</th>
                <th style="padding: 1rem; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">Role</th>
                <th style="padding: 1rem; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">Joined</th>
                <th style="padding: 1rem; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding: 1rem;">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div class="user-avatar" style="width: 36px; height: 36px; font-size: 0.9rem;">
                                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                            </div>
                            <div>
                                <p style="font-weight: 700; margin-bottom: 0.1rem;"><?php echo sanitize($user['name']); ?></p>
                                <p style="font-size: 0.8rem; color: var(--text-muted);"><?php echo sanitize($user['email']); ?></p>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 1rem;">
                        <span style="padding: 0.35rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; background: <?php echo $user['role'] === 'admin' ? '#fee2e2; color: #ef4444;' : '#e0e7ff; color: #6366f1;'; ?>">
                            <?php echo strtoupper($user['role']); ?>
                        </span>
                    </td>
                    <td style="padding: 1rem; font-size: 0.9rem; color: var(--text-muted);">
                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                    </td>
                    <td style="padding: 1rem;">
                        <button style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; padding: 0.5rem;" title="Edit User">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if (($user['role'] ?? '') !== 'admin'): ?>
                            <button style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; padding: 0.5rem;" title="Delete User">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        <?php else: ?>
                            <span style="display:inline-flex; align-items:center; gap:0.35rem; font-size:0.72rem; font-weight:700; color:#166534; background:#dcfce7; border:1px solid #86efac; border-radius:999px; padding:0.2rem 0.55rem;">
                                <i class="fas fa-lock"></i> Protected
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
