<?php
$pageTitle = 'Settings';
$activePage = 'settings';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();
$userId = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
?>

<div class="section-header" style="margin-bottom: 2.5rem;">
    <h2 style="font-weight: 800; letter-spacing: -0.02em;">Account Settings</h2>
    <p style="color: var(--text-muted);">Manage your profile and application preferences.</p>
</div>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
    <aside>
        <div class="glass-card" style="padding: 1.5rem;">
            <nav style="display: flex; flex-direction: column; gap: 0.5rem;">
                <a href="#" style="padding: 0.85rem 1rem; border-radius: 12px; background: var(--primary-gradient); color: white; text-decoration: none; font-weight: 600;">
                    <i class="fas fa-user-circle" style="margin-right: 0.75rem;"></i> Profile Information
                </a>
                <a href="#" style="padding: 0.85rem 1rem; border-radius: 12px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.color='var(--text-main)'" onmouseout="this.style.background='transparent'; this.style.color='var(--text-muted)'">
                    <i class="fas fa-lock" style="margin-right: 0.75rem;"></i> Password & Security
                </a>
                <a href="#" style="padding: 0.85rem 1rem; border-radius: 12px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.color='var(--text-main)'" onmouseout="this.style.background='transparent'; this.style.color='var(--text-muted)'">
                    <i class="fas fa-bell" style="margin-right: 0.75rem;"></i> Email Notifications
                </a>
                <a href="#" style="padding: 0.85rem 1rem; border-radius: 12px; color: var(--text-muted); text-decoration: none; font-weight: 500; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.color='var(--text-main)'" onmouseout="this.style.background='transparent'; this.style.color='var(--text-muted)'">
                    <i class="fas fa-palette" style="margin-right: 0.75rem;"></i> Appearance
                </a>
            </nav>
        </div>
    </aside>

    <main>
        <div class="glass-card" style="padding: 2.5rem;">
            <div style="display: flex; align-items: center; gap: 2rem; margin-bottom: 2.5rem; padding-bottom: 2rem; border-bottom: 1px solid #f1f5f9;">
                <div class="user-avatar" style="width: 100px; height: 100px; font-size: 2.5rem; border-radius: 24px;">
                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                </div>
                <div>
                    <h3 style="margin-bottom: 0.5rem; font-weight: 700;">Profile Photo</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">Update your avatar and personal details.</p>
                    <div style="display: flex; gap: 0.75rem;">
                        <button class="btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">Upload New</button>
                        <button style="padding: 0.5rem 1rem; font-size: 0.85rem; border: 1px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer;">Remove</button>
                    </div>
                </div>
            </div>

            <form style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label style="font-weight: 600; font-size: 0.9rem; color: var(--text-main);">Full Name</label>
                    <input type="text" value="<?php echo sanitize($user['name']); ?>" style="padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #e2e8f0; outline: none; font-size: 0.9rem;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label style="font-weight: 600; font-size: 0.9rem; color: var(--text-main);">Email Address</label>
                    <input type="email" value="<?php echo sanitize($user['email']); ?>" style="padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #e2e8f0; outline: none; font-size: 0.9rem;">
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; grid-column: 1 / -1;">
                    <label style="font-weight: 600; font-size: 0.9rem; color: var(--text-main);">Role</label>
                    <input type="text" value="<?php echo ucfirst($user['role']); ?>" readonly style="padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #e2e8f0; background: #f8fafc; color: var(--text-muted); outline: none; font-size: 0.9rem; cursor: not-allowed;">
                </div>
                
                <div style="grid-column: 1 / -1; display: flex; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn-primary" onclick="alert('Profile updated successfully!')">Save Changes</button>
                </div>
            </form>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
