<?php
// ============================================================
// ERP System — Users: Edit (Admin only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error','Access denied.'); redirect(BASE_URL.'/modules/dashboard/index.php');
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid user.'); redirect(BASE_URL.'/modules/users/index.php'); }

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i',$id); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { setFlash('error','User not found.'); redirect(BASE_URL.'/modules/users/index.php'); }

$roles  = ['admin','manager','operator','viewer'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $name     = trim($_POST['name']  ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role']       ?? 'operator';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password']   ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if ($name === '')                   $errors[] = 'Name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!in_array($role, $roles))       $errors[] = 'Invalid role.';

        // Password change is optional: only validate if provided
        $changePassword = ($password !== '');
        if ($changePassword) {
            if (strlen($password) < 8)      $errors[] = 'New password must be at least 8 characters.';
            if ($password !== $confirm)     $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            // Email uniqueness (excluding self)
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->bind_param('si',$email,$id); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $errors[] = 'Email is already used by another account.';
            }
        }

        // Prevent last admin from losing admin role
        if (empty($errors) && $role !== 'admin') {
            $admQ = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1 AND id != ?");
            $admQ->bind_param('i',$id); $admQ->execute();
            if ((int)$admQ->get_result()->fetch_assoc()['c'] === 0) {
                $errors[] = 'Cannot change role: this is the last active admin account.';
            }
        }

        if (empty($errors)) {
            if ($changePassword) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, is_active=?, password=? WHERE id=?");
                $upd->bind_param('sssisi', $name, $email, $role, $isActive, $hash, $id);
            } else {
                $upd = $db->prepare("UPDATE users SET name=?, email=?, role=?, is_active=? WHERE id=?");
                $upd->bind_param('sssii', $name, $email, $role, $isActive, $id);
            }
            if ($upd->execute()) {
                setFlash('success','User updated.');
                redirect(BASE_URL.'/modules/users/index.php');
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
    // Re-populate from POST on error
    $user = array_merge($user, $_POST);
}

$pageTitle = 'Edit User';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Users</a><span class="breadcrumb-sep">›</span>
  <a href="index.php"><?= e($user['name']) ?></a><span class="breadcrumb-sep">›</span>
  <span>Edit</span>
</div>
<div class="page-header">
  <div><h1>Edit User</h1><p><?= e($user['email']) ?></p></div>
  <a href="index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Errors:</strong>
  <ul style="margin:6px 0 0 18px"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach;?></ul>
</div>
<?php endif; ?>

<form method="POST" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
  <div class="card">
    <div class="card-header"><span class="card-title">Account Details</span></div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Full Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required value="<?= e($user['name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Email <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" required value="<?= e($user['email']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control" <?= $user['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r ?>" <?= $user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($user['id'] == $_SESSION['user_id']): ?>
          <input type="hidden" name="role" value="<?= e($user['role']) ?>">
          <small class="text-muted">You cannot change your own role.</small>
          <?php endif; ?>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" value="1" <?= $user['is_active']?'checked':'' ?> style="width:16px;height:16px">
            <span>Active account</span>
          </label>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-16">
    <div class="card-header">
      <span class="card-title">Change Password</span>
      <span class="text-muted text-sm">Leave blank to keep current password.</span>
    </div>
    <div class="card-body">
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">New Password <small class="text-muted">(min 8 chars)</small></label>
          <input type="password" name="password" class="form-control" autocomplete="new-password" minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password</label>
          <input type="password" name="password_confirm" class="form-control" autocomplete="new-password" minlength="8">
        </div>
      </div>
    </div>
  </div>

  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<style>.req{color:var(--danger)}</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
