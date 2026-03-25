<?php
// ============================================================
// Shree Label ERP — Users: List (Admin only)
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error','Access denied. Admin only.');
    redirect(BASE_URL.'/modules/dashboard/index.php');
}

$db = getDB();
$fSearch = trim($_GET['search'] ?? '');

$where = ['1=1']; $params = []; $types = '';
if ($fSearch !== '') {
    $like    = '%'.$fSearch.'%';
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $params  = [$like,$like]; $types = 'ss';
}
$whereSQL = implode(' AND ', $where);

$perPage = 20; $page = max(1,(int)($_GET['page'] ?? 1)); $offset = ($page-1)*$perPage;

$totalQ = $db->prepare("SELECT COUNT(*) AS c FROM users WHERE {$whereSQL}");
if ($types) $totalQ->bind_param($types,...$params);
$totalQ->execute();
$total = (int)$totalQ->get_result()->fetch_assoc()['c'];

$listQ = $db->prepare("SELECT * FROM users WHERE {$whereSQL} ORDER BY id ASC LIMIT ? OFFSET ?");
$listQ->bind_param($types.'ii',...[...$params,$perPage,$offset]);
$listQ->execute();
$users = $listQ->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Users';
include __DIR__ . '/../../includes/header.php';
?>
<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <span>Users</span>
</div>
<div class="page-header">
  <div><h1>User Management</h1><p>Manage system accounts and roles.</p></div>
  <a href="add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Add User</a>
</div>

<form method="GET" class="filter-bar mb-0">
  <div class="filter-group">
    <label>Search</label>
    <input type="text" name="search" placeholder="Name or Email" value="<?= e($fSearch) ?>">
  </div>
  <div class="filter-group" style="justify-content:flex-end"><label>&nbsp;</label>
    <div class="d-flex gap-8">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Search</button>
      <a href="index.php" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> Reset</a>
    </div>
  </div>
</form>

<div class="card mt-16">
  <div class="card-header">
    <span class="card-title">All Users <span class="badge badge-consumed" style="margin-left:6px"><?= $total ?></span></span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="7" class="table-empty"><i class="bi bi-inbox"></i>No users found.</td></tr>
        <?php else: ?>
        <?php foreach ($users as $i => $u): ?>
        <tr>
          <td class="text-muted"><?= $offset+$i+1 ?></td>
          <td class="fw-600"><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td>
            <span class="badge <?= $u['role']==='admin'?'badge-warning':'badge-consumed' ?>">
              <?= e(ucfirst($u['role'])) ?>
            </span>
          </td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge badge-available">Active</span>
            <?php else: ?>
            <span class="badge badge-cancelled">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= formatDate($u['created_at']) ?></td>
          <td>
            <div class="row-actions">
              <a href="edit.php?id=<?= $u['id'] ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="bi bi-pencil"></i></a>
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <a href="delete.php?id=<?= $u['id'] ?>&csrf=<?= generateCSRF() ?>"
                 class="btn btn-danger btn-sm" title="Delete"
                 data-confirm="Delete user <?= e($u['name']) ?>?"><i class="bi bi-trash"></i></a>
              <?php else: ?>
              <span class="btn btn-ghost btn-sm" style="opacity:.35;cursor:default" title="Cannot delete yourself"><i class="bi bi-shield"></i></span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
  $qStr = http_build_query(['search'=>$fSearch,'page'=>'{page}']);
  echo paginationBar($total, $perPage, $page, BASE_URL.'/modules/users/index.php?'.$qStr);
  ?>
  <div style="padding:8px 14px"></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
