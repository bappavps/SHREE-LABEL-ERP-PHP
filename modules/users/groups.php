<?php
// ============================================================
// ERP System — User Groups & Page Permissions (Admin only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error', 'Access denied. Admin only.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$db = getDB();
ensureRbacSchema();

$catalog = rbacPageCatalog();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_group') {
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $errors[] = 'Group name is required.';
            }

            if (empty($errors)) {
                $chk = $db->prepare("SELECT id FROM user_groups WHERE name = ? LIMIT 1");
                $chk->bind_param('s', $name);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $errors[] = 'Group name already exists.';
                }
            }

            if (empty($errors)) {
                $ins = $db->prepare("INSERT INTO user_groups (name, description, is_active) VALUES (?,?,?)");
                $ins->bind_param('ssi', $name, $desc, $active);
                if ($ins->execute()) {
                    $gid = (int)$ins->insert_id;
                    setFlash('success', 'Group created successfully.');
                    redirect(BASE_URL . '/modules/users/groups.php?gid=' . $gid);
                } else {
                    $errors[] = 'Database error: ' . $db->error;
                }
            }
        }

        if ($action === 'update_group') {
            $gid = (int)($_POST['gid'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;

            if ($gid <= 0) $errors[] = 'Invalid group.';
            if ($name === '') $errors[] = 'Group name is required.';

            if (empty($errors)) {
                $chk = $db->prepare("SELECT id FROM user_groups WHERE name = ? AND id != ? LIMIT 1");
                $chk->bind_param('si', $name, $gid);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $errors[] = 'Another group already uses this name.';
                }
            }

            if (empty($errors)) {
                $upd = $db->prepare("UPDATE user_groups SET name=?, description=?, is_active=? WHERE id=?");
                $upd->bind_param('ssii', $name, $desc, $active, $gid);
                if ($upd->execute()) {
                    setFlash('success', 'Group updated.');
                    redirect(BASE_URL . '/modules/users/groups.php?gid=' . $gid);
                } else {
                    $errors[] = 'Update failed: ' . $db->error;
                }
            }
        }

        if ($action === 'delete_group') {
            $gid = (int)($_POST['gid'] ?? 0);
            if ($gid <= 0) $errors[] = 'Invalid group.';

            if (empty($errors)) {
                $cntQ = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE group_id = ?");
                $cntQ->bind_param('i', $gid);
                $cntQ->execute();
                $cnt = (int)($cntQ->get_result()->fetch_assoc()['c'] ?? 0);
                if ($cnt > 0) {
                    $errors[] = 'Cannot delete group. Users are still assigned to this group.';
                }
            }

            if (empty($errors)) {
                $del = $db->prepare("DELETE FROM user_groups WHERE id = ?");
                $del->bind_param('i', $gid);
                if ($del->execute()) {
                    setFlash('success', 'Group deleted.');
                    redirect(BASE_URL . '/modules/users/groups.php');
                } else {
                    $errors[] = 'Delete failed: ' . $db->error;
                }
            }
        }

        if ($action === 'save_permissions') {
            $gid = (int)($_POST['gid'] ?? 0);
            $pages = $_POST['pages'] ?? [];
            if (!is_array($pages)) $pages = [];

            if ($gid <= 0) $errors[] = 'Invalid group.';

            $valid = [];
            foreach ($pages as $p) {
                $p = rbacNormalizePath((string)$p);
                if (isset($catalog[$p])) {
                    $valid[$p] = true;
                }
            }
            $validPages = array_keys($valid);

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    $del = $db->prepare("DELETE FROM group_page_permissions WHERE group_id = ?");
                    $del->bind_param('i', $gid);
                    $del->execute();

                    if (!empty($validPages)) {
                        $ins = $db->prepare("INSERT INTO group_page_permissions (group_id, page_path, can_view) VALUES (?,?,1)");
                        foreach ($validPages as $pagePath) {
                            $ins->bind_param('is', $gid, $pagePath);
                            $ins->execute();
                        }
                    }

                    $db->commit();
                    setFlash('success', 'Permissions saved.');
                    redirect(BASE_URL . '/modules/users/groups.php?gid=' . $gid);
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Failed to save permissions.';
                }
            }
        }
    }
}

$groups = $db->query("SELECT g.*, (SELECT COUNT(*) FROM users u WHERE u.group_id = g.id) AS users_count FROM user_groups g ORDER BY g.name ASC")->fetch_all(MYSQLI_ASSOC);

$selectedGroupId = (int)($_GET['gid'] ?? 0);
if ($selectedGroupId <= 0 && !empty($groups)) {
    $selectedGroupId = (int)$groups[0]['id'];
}

$selectedGroup = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === $selectedGroupId) {
        $selectedGroup = $g;
        break;
    }
}

$selectedPerms = [];
if ($selectedGroupId > 0) {
    $pq = $db->prepare("SELECT page_path FROM group_page_permissions WHERE group_id = ? AND can_view = 1");
    $pq->bind_param('i', $selectedGroupId);
    $pq->execute();
    $rows = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $selectedPerms[rbacNormalizePath((string)$r['page_path'])] = true;
    }
}

$catalogBySection = [];
foreach ($catalog as $path => $label) {
    $parts = explode('/', trim($path, '/'));
    $section = isset($parts[1]) ? ucfirst(str_replace('-', ' ', $parts[1])) : 'Other';
    if (!isset($catalogBySection[$section])) $catalogBySection[$section] = [];
    $catalogBySection[$section][$path] = $label;
}
ksort($catalogBySection);

$pageTitle = 'User Groups & Permissions';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/users/index.php">Users</a><span class="breadcrumb-sep">›</span>
  <span>Groups</span>
</div>

<div class="page-header">
  <div>
    <h1>User Groups & Permissions</h1>
    <p>Create groups and assign allowed pages for each group.</p>
  </div>
  <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Users</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Errors:</strong>
  <ul style="margin:6px 0 0 18px"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card mb-16">
  <div class="card-header"><span class="card-title">Create New Group</span></div>
  <div class="card-body">
    <form method="POST" class="form-grid">
      <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
      <input type="hidden" name="action" value="create_group">
      <div class="form-group">
        <label class="form-label">Group Name <span class="req">*</span></label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" placeholder="Optional notes">
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="is_active" value="1" checked style="width:16px;height:16px">
          <span>Active group</span>
        </label>
      </div>
      <div class="form-group" style="display:flex;align-items:flex-end">
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Create Group</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <div class="rbac-layout">
      <aside class="rbac-sidebar">
        <div class="rbac-side-head">Groups</div>
        <?php if (empty($groups)): ?>
          <div class="table-empty" style="padding:18px"><i class="bi bi-inbox"></i>No groups yet.</div>
        <?php else: ?>
          <?php foreach ($groups as $g): ?>
            <a class="rbac-group-item <?= (int)$g['id'] === $selectedGroupId ? 'active' : '' ?>" href="groups.php?gid=<?= (int)$g['id'] ?>">
              <div>
                <strong><?= e($g['name']) ?></strong>
                <small><?= (int)$g['users_count'] ?> user(s)</small>
              </div>
              <?php if ((int)$g['is_active'] === 1): ?>
                <span class="badge badge-available">Active</span>
              <?php else: ?>
                <span class="badge badge-cancelled">Inactive</span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </aside>

      <section class="rbac-main">
        <?php if (!$selectedGroup): ?>
          <div class="table-empty" style="padding:24px"><i class="bi bi-inbox"></i>Select a group to manage permissions.</div>
        <?php else: ?>
          <form method="POST" class="card mb-16">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action" value="update_group">
            <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">
            <div class="card-header"><span class="card-title">Group Details</span></div>
            <div class="card-body">
              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label">Group Name <span class="req">*</span></label>
                  <input type="text" name="name" class="form-control" required value="<?= e($selectedGroup['name']) ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">Description</label>
                  <input type="text" name="description" class="form-control" value="<?= e((string)$selectedGroup['description']) ?>">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" name="is_active" value="1" <?= (int)$selectedGroup['is_active'] === 1 ? 'checked' : '' ?> style="width:16px;height:16px">
                    <span>Active group</span>
                  </label>
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                  <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle"></i> Save Group</button>
                </div>
              </div>
            </div>
          </form>

          <form method="POST" onsubmit="return confirm('Delete this group? This cannot be undone.');" style="margin-bottom:16px">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Delete Group</button>
          </form>

          <form method="POST" class="card">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">
            <div class="card-header">
              <span class="card-title">Page Permissions</span>
              <div class="d-flex gap-8">
                <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllPerm(true)">Select All</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllPerm(false)">Clear</button>
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-shield-check"></i> Save Permissions</button>
              </div>
            </div>
            <div class="card-body">
              <?php foreach ($catalogBySection as $section => $items): ?>
              <div class="perm-section">
                <h3><?= e($section) ?></h3>
                <div class="perm-grid">
                  <?php foreach ($items as $path => $label): ?>
                  <label class="perm-item">
                    <input type="checkbox" class="perm-cb" name="pages[]" value="<?= e($path) ?>" <?= isset($selectedPerms[$path]) ? 'checked' : '' ?>>
                    <span>
                      <strong><?= e($label) ?></strong>
                      <small><?= e($path) ?></small>
                    </span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </form>
        <?php endif; ?>
      </section>
    </div>
  </div>
</div>

<style>
.req { color: var(--danger); }
.rbac-layout { display:flex; min-height: 560px; }
.rbac-sidebar { width: 280px; border-right: 1px solid var(--line); background: #fafbff; }
.rbac-side-head { padding: 14px 16px; border-bottom: 1px solid var(--line); font-weight: 700; }
.rbac-group-item { display:flex; align-items:center; justify-content:space-between; gap:8px; padding: 10px 12px; border-bottom:1px solid var(--line); text-decoration:none; color:inherit; }
.rbac-group-item:hover { background:#f3f5ff; }
.rbac-group-item.active { background:#eaf0ff; }
.rbac-group-item strong { display:block; }
.rbac-group-item small { color:#6b7280; }
.rbac-main { flex:1; padding: 16px; }
.perm-section { margin-bottom: 18px; }
.perm-section h3 { margin:0 0 8px; font-size: 14px; color:#1f2937; }
.perm-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 8px; }
.perm-item { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-radius:10px; padding:9px; background:#fff; }
.perm-item small { display:block; color:#64748b; margin-top:2px; font-size:11px; }
</style>

<script>
function toggleAllPerm(state) {
  document.querySelectorAll('.perm-cb').forEach(function(cb){ cb.checked = state; });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
