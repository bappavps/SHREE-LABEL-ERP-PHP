<?php
// ============================================================
// ERP System — My Profile (logged-in user only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    redirect(BASE_URL . '/auth/login.php');
}

$stmt = $db->prepare("SELECT u.*, ug.name AS group_name FROM users u LEFT JOIN user_groups ug ON ug.id = u.group_id WHERE u.id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    setFlash('error', 'User not found.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$errors = [];
$success = false;

// ── Handle profile update ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        // Password change (optional)
        $changePassword = ($newPassword !== '');
        if ($changePassword) {
            if ($currentPassword === '') {
                $errors[] = 'Current password is required to set a new password.';
            } elseif (!password_verify($currentPassword, $user['password'])) {
                $errors[] = 'Current password is incorrect.';
            }
            if (strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New passwords do not match.';
            }
        }

        if (empty($errors)) {
            if ($changePassword) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $upd = $db->prepare("UPDATE users SET name = ?, password = ? WHERE id = ?");
                $upd->bind_param('ssi', $name, $hash, $userId);
            } else {
                $upd = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
                $upd->bind_param('si', $name, $userId);
            }
            if ($upd->execute()) {
                $_SESSION['user_name'] = $name;
                $user['name'] = $name;
                setFlash('success', 'Profile updated successfully.');
                redirect(BASE_URL . '/modules/users/profile.php');
            } else {
                $errors[] = 'Database error: ' . $db->error;
            }
        }
        // Re-populate name from POST on error
        $user['name'] = $name;
    }
}

$pageTitle = 'My Profile';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>My Profile</span>
</div>
<div class="page-header">
  <div><h1><i class="bi bi-person-circle" style="margin-right:8px"></i>My Profile</h1><p>View and update your account details.</p></div>
</div>

<?php if (!empty($errors)): ?>
<div style="padding:0 0 12px">
  <div class="alert alert-error" role="alert">
    <span><?= e(implode(' ', $errors)) ?></span>
    <button class="alert-close" type="button">&times;</button>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:900px">

  <!-- Profile Info Card -->
  <div class="card">
    <div class="card-header"><span class="card-title">Account Information</span></div>
    <div class="card-body" style="padding:20px">
      <div style="display:flex;flex-direction:column;align-items:center;gap:14px;margin-bottom:20px">
        <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#0f172a,#334155);display:flex;align-items:center;justify-content:center;color:#f8fafc;font-size:2rem;font-weight:700">
          <?= strtoupper(mb_substr($user['name'], 0, 1)) ?>
        </div>
        <div style="text-align:center">
          <div style="font-size:1.1rem;font-weight:700;color:#0f172a"><?= e($user['name']) ?></div>
          <div style="font-size:.82rem;color:#64748b;margin-top:2px"><?= e($user['email']) ?></div>
        </div>
      </div>

      <table style="width:100%;border-collapse:collapse">
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 8px;font-weight:600;color:#475569;font-size:.82rem;width:110px">User ID</td>
          <td style="padding:10px 8px;font-size:.82rem;color:#0f172a">#<?= (int)$user['id'] ?></td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 8px;font-weight:600;color:#475569;font-size:.82rem">Role</td>
          <td style="padding:10px 8px;font-size:.82rem"><span class="badge" style="background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:999px;font-weight:700;text-transform:capitalize"><?= e($user['role']) ?></span></td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 8px;font-weight:600;color:#475569;font-size:.82rem">Group</td>
          <td style="padding:10px 8px;font-size:.82rem;color:#0f172a"><?= e($user['group_name'] ?? '—') ?></td>
        </tr>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 8px;font-weight:600;color:#475569;font-size:.82rem">Status</td>
          <td style="padding:10px 8px;font-size:.82rem">
            <?php if ((int)($user['is_active'] ?? 1)): ?>
              <span class="badge" style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-weight:700">Active</span>
            <?php else: ?>
              <span class="badge" style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:999px;font-weight:700">Inactive</span>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td style="padding:10px 8px;font-weight:600;color:#475569;font-size:.82rem">Joined</td>
          <td style="padding:10px 8px;font-size:.82rem;color:#0f172a"><?= isset($user['created_at']) ? date('d M Y, h:i A', strtotime($user['created_at'])) : '—' ?></td>
        </tr>
      </table>
    </div>
  </div>

  <!-- Edit Profile Card -->
  <div class="card">
    <div class="card-header"><span class="card-title">Update Profile</span></div>
    <div class="card-body" style="padding:20px">
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(generateCSRF()) ?>">

        <div class="form-group" style="margin-bottom:16px">
          <label style="display:block;font-size:.78rem;font-weight:600;color:#334155;margin-bottom:5px">Full Name</label>
          <input type="text" name="name" value="<?= e($user['name']) ?>" class="form-control" required style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem">
        </div>

        <div class="form-group" style="margin-bottom:16px">
          <label style="display:block;font-size:.78rem;font-weight:600;color:#334155;margin-bottom:5px">Email</label>
          <input type="email" value="<?= e($user['email']) ?>" class="form-control" disabled style="width:100%;padding:9px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:.85rem;background:#f9fafb;color:#6b7280;cursor:not-allowed">
          <small style="font-size:.7rem;color:#94a3b8;margin-top:3px;display:block">Email cannot be changed. Contact admin if needed.</small>
        </div>

        <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0">
        <p style="font-size:.78rem;font-weight:600;color:#475569;margin-bottom:12px"><i class="bi bi-shield-lock" style="margin-right:4px"></i> Change Password <span style="font-weight:400;color:#94a3b8">(optional)</span></p>

        <div class="form-group" style="margin-bottom:16px">
          <label style="display:block;font-size:.78rem;font-weight:600;color:#334155;margin-bottom:5px">Current Password</label>
          <input type="password" name="current_password" class="form-control" autocomplete="current-password" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem">
        </div>

        <div class="form-group" style="margin-bottom:16px">
          <label style="display:block;font-size:.78rem;font-weight:600;color:#334155;margin-bottom:5px">New Password</label>
          <input type="password" name="new_password" class="form-control" autocomplete="new-password" minlength="8" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem">
        </div>

        <div class="form-group" style="margin-bottom:20px">
          <label style="display:block;font-size:.78rem;font-weight:600;color:#334155;margin-bottom:5px">Confirm New Password</label>
          <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%"><i class="bi bi-check-lg" style="margin-right:6px"></i> Save Changes</button>
      </form>
    </div>
  </div>

</div>

<style>
@media (max-width: 768px) {
  div[style*="grid-template-columns:1fr 1fr"] {
    grid-template-columns: 1fr !important;
  }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
