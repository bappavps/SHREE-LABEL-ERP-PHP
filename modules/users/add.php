<?php
// ============================================================
// ERP System — Users: Add (Admin only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error','Access denied.'); redirect(BASE_URL.'/modules/dashboard/index.php');
}

$db = getDB();
ensureRbacSchema();
$errors = [];
$roles  = ['admin','manager','operator','viewer'];
$groupRows = $db->query("SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name ASC");
$groups = $groupRows ? $groupRows->fetch_all(MYSQLI_ASSOC) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $name     = trim($_POST['name']     ?? '');
        $email    = trim(strtolower($_POST['email'] ?? ''));
        if (preg_match('/@gmail\.com$/i', $email)) {
          $email = preg_replace('/@gmail\.com$/i', '@shreelabel.com', $email);
        }
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $role     = $_POST['role']          ?? 'operator';
        $groupId  = (int)($_POST['group_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '')                   $errors[] = 'Name is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < 8)          $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)         $errors[] = 'Passwords do not match.';
        if (!in_array($role, $roles))       $errors[] = 'Invalid role.';
        if ($role !== 'admin' && $groupId <= 0) $errors[] = 'Group is required for non-admin users.';

        if ($groupId > 0) {
          $gq = $db->prepare("SELECT id FROM user_groups WHERE id = ? AND is_active = 1 LIMIT 1");
          $gq->bind_param('i', $groupId);
          $gq->execute();
          if (!$gq->get_result()->fetch_assoc()) {
            $errors[] = 'Selected group is invalid or inactive.';
          }
        }

        if (empty($errors)) {
            // Check email uniqueness
            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->bind_param('s',$email); $chk->execute();
            if ($chk->get_result()->fetch_assoc()) {
                $errors[] = 'Email is already registered.';
            }
        }
        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins  = $db->prepare("INSERT INTO users (name, email, password, role, group_id, is_active) VALUES (?,?,?, ?, NULLIF(?,0), ?)");
            $ins->bind_param('ssssii', $name, $email, $hash, $role, $groupId, $isActive);
            if ($ins->execute()) {
                setFlash('success',"User {$name} created successfully.");
                redirect(BASE_URL.'/modules/users/index.php');
            } else {
                $errors[] = 'Database error: '.$db->error;
            }
        }
    }
}

$pageTitle = 'Add User';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="index.php">Users</a><span class="breadcrumb-sep">›</span><span>Add</span>
</div>
<div class="page-header">
  <div><h1>Add User</h1><p>Create a new system account.</p></div>
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
          <input type="text" name="name" class="form-control" required autocomplete="off" value="<?= e($_POST['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Email <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" required autocomplete="off" value="<?= e($_POST['email'] ?? '') ?>">
          <small class="text-muted">If you enter @gmail.com, it will be saved as @shreelabel.com automatically.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Password <span class="req">*</span> <small class="text-muted">(min 8 chars)</small></label>
          <input type="password" name="password" class="form-control" required autocomplete="new-password" minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password <span class="req">*</span></label>
          <input type="password" name="password_confirm" class="form-control" required autocomplete="new-password" minlength="8">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select name="role" class="form-control">
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r ?>" <?= ($_POST['role']??'operator')===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Group <span class="req">*</span></label>
          <select name="group_id" class="form-control">
            <option value="0">Select Group</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= ((int)($_POST['group_id'] ?? 0) === (int)$g['id']) ? 'selected' : '' ?>><?= e($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Admin role can be kept unassigned; others require a group.</small>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" value="1" <?= (!isset($_POST['is_active']) || $_POST['is_active']) ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>Active account</span>
          </label>
        </div>
      </div>
    </div>
  </div>
  <div class="form-actions mt-16">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Create User</button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
  </div>
</form>
<style>.req{color:var(--danger)}</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
