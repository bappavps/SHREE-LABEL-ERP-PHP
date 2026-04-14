<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

if (!isAdmin()) {
    setFlash('error', 'Access denied. Admin only.');
    redirect(BASE_URL . '/modules/dashboard/index.php');
}

$db = getDB();
ensureRbacSchema();

function rbac_ui_section_for_path($path) {
    $path = rbacNormalizePath($path);
    if (strpos($path, '/modules/estimate/') === 0 || strpos($path, '/modules/estimates/') === 0 || strpos($path, '/modules/quotations/') === 0 || strpos($path, '/modules/sales_order/') === 0) {
        return 'Sales & Estimating';
    }
    if (strpos($path, '/modules/artwork/') === 0 || strpos($path, '/modules/planning/') === 0) {
        return 'Design & Prepress';
    }
    if (strpos($path, '/modules/operators/') === 0) {
        return 'Machine Operator';
    }
    if (strpos($path, '/modules/paper_stock/') === 0 || strpos($path, '/modules/audit/') === 0 || strpos($path, '/modules/scan/') === 0 || strpos($path, '/modules/inventory/') === 0) {
        return 'Inventory Hub';
    }
    if (strpos($path, '/modules/jobs/') === 0 || strpos($path, '/modules/bom/') === 0 || strpos($path, '/modules/live/') === 0 || strpos($path, '/modules/production-manager/') === 0 || strpos($path, '/modules/approval/') === 0) {
        return 'Production';
    }
    if (strpos($path, '/modules/purchase/') === 0 || strpos($path, '/modules/requisition-management/') === 0) {
        return 'Purchase';
    }
    if (strpos($path, '/modules/leave-management/') === 0) {
      return 'HR & Workforce';
    }
    if (strpos($path, '/modules/qc/') === 0 || strpos($path, '/modules/packing/') === 0 || strpos($path, '/modules/dispatch/') === 0 || strpos($path, '/modules/billing/') === 0) {
        return 'Quality & Logistics';
    }
    if (strpos($path, '/modules/performance/') === 0 || strpos($path, '/modules/reports/') === 0) {
        return 'Analytics';
    }
    if (strpos($path, '/modules/master/') === 0 || strpos($path, '/modules/stock-import/') === 0 || strpos($path, '/modules/users/') === 0 || strpos($path, '/modules/print/') === 0 || strpos($path, '/modules/pricing/') === 0 || strpos($path, '/modules/settings/') === 0) {
        return 'Administration';
    }
    return 'General';
}

function rbac_ui_module_blurb($dir, $title) {
    $map = [
        '/modules/dashboard' => 'Main overview and business snapshot.',
        '/modules/paper_stock' => 'Stock roll visibility, add, edit, label, and export controls.',
        '/modules/scan' => 'Barcode or QR based stock scanning workflow.',
        '/modules/audit' => 'Physical stock check and reconciliation tools.',
        '/modules/live' => 'Live production visibility and floor monitoring.',
        '/modules/production-manager' => 'Unified Production Summary view across planning and job cards.',
        '/modules/users' => 'Users, access groups, and system-level access control.',
        '/modules/master' => 'Reference masters such as materials, clients, and machines.',
        '/modules/planning' => 'Planning board and department-wise job planning.',
        '/modules/operators' => 'Operator workspaces for production teams.',
        '/modules/jobs' => 'Production job card execution screens.',
        '/modules/requisition-management' => 'Requisition request, approval, and PO preparation workflow.',
        '/modules/leave-management' => 'Employee leave application, voice capture, approval, and print workflow.',
    ];
    if (isset($map[$dir])) return $map[$dir];
    return $title . ' access and allowed functions.';
}

function rbac_ui_action_from_item($path, $label, $baseLabel) {
    $file = basename($path);
    $actionMap = [
        'index.php' => 'Open',
        'add.php' => 'Add',
        'edit.php' => 'Edit',
        'delete.php' => 'Delete',
        'view.php' => 'View',
        'export.php' => 'Export',
        'import.php' => 'Import',
        'batch_delete.php' => 'Batch Delete',
        'label.php' => 'Print Label',
        'groups.php' => 'Manage Groups & Permissions',
    ];
    if (isset($actionMap[$file])) return $actionMap[$file];

    $name = pathinfo($file, PATHINFO_FILENAME);
    $name = ucwords(str_replace(['-', '_'], ' ', $name));
    if ($label === $baseLabel) return 'Open';
    if (strpos($label, $baseLabel . ' - ') === 0) {
        return substr($label, strlen($baseLabel) + 3);
    }
    return $name;
}

function rbac_ui_action_hint($action) {
    $map = [
        'Open' => 'Open and use this module page.',
        'Add' => 'Create new entries from this module.',
        'Edit' => 'Modify existing records.',
        'Delete' => 'Remove records from the system.',
        'View' => 'Open details without edit action.',
        'Export' => 'Download or export data.',
        'Import' => 'Upload or import external data.',
        'Batch Delete' => 'Bulk remove multiple records.',
        'Print Label' => 'Print labels or generated output.',
        'Manage Groups & Permissions' => 'Configure groups and access rules.',
    ];
    return $map[$action] ?? ('Allow ' . strtolower($action) . ' action.');
}

function rbac_default_group_blueprints() {
    return [
        [
            'name' => 'Store & Inventory',
            'description' => 'Paper stock, scanning, audit, and inventory handling.',
            'pages' => [
                '/modules/dashboard/index.php',
                '/modules/paper_stock/index.php',
                '/modules/paper_stock/add.php',
                '/modules/paper_stock/edit.php',
                '/modules/paper_stock/view.php',
                '/modules/paper_stock/export.php',
                '/modules/paper_stock/label.php',
                '/modules/audit/index.php',
                '/modules/scan/index.php',
                '/modules/inventory/slitting/index.php',
                '/modules/inventory/finished/index.php',
            ],
        ],
        [
            'name' => 'Planning Team',
            'description' => 'Artwork and all planning department pages.',
            'pages' => [
                '/modules/dashboard/index.php',
                '/modules/artwork/index.php',
                '/modules/planning/label/index.php',
                '/modules/planning/slitting/index.php',
                '/modules/planning/printing/index.php',
                '/modules/planning/flatbed/index.php',
                '/modules/planning/barcode/index.php',
                '/modules/planning/paperroll/index.php',
                '/modules/planning/label-slitting/index.php',
                '/modules/planning/batch/index.php',
                '/modules/planning/packing/index.php',
                '/modules/planning/dispatch/index.php',
                '/modules/live/index.php',
            ],
        ],
        [
            'name' => 'Production Operator',
            'description' => 'Operator workspaces and live production pages.',
            'pages' => [
                '/modules/dashboard/index.php',
                '/modules/operators/jumbo/index.php',
                '/modules/operators/printing/index.php',
                '/modules/operators/packing/index.php',
                '/modules/live/index.php',
                '/modules/jobs/jumbo/index.php',
                '/modules/jobs/printing/index.php',
                '/modules/jobs/packing/index.php',
            ],
        ],
        [
            'name' => 'Management Viewer',
            'description' => 'High-level dashboard, live floor, reports, and performance.',
            'pages' => [
                '/modules/dashboard/index.php',
                '/modules/live/index.php',
                '/modules/production-manager/index.php',
                '/modules/performance/index.php',
                '/modules/reports/index.php',
                '/modules/reports/jobs.php',
                '/modules/estimates/index.php',
                '/modules/quotations/index.php',
                '/modules/sales_order/index.php',
            ],
        ],
            [
              'name' => 'Leave Employee',
              'description' => 'Employee self-service access for leave application and leave status tracking.',
              'pages' => [
                '/modules/dashboard/index.php',
                '/modules/leave-management/index.php',
              ],
            ],
            [
              'name' => 'Leave Approver',
              'description' => 'Manager or admin access for leave approval and printable leave documents.',
              'pages' => [
                '/modules/dashboard/index.php',
                '/modules/leave-management/index.php',
              ],
            ],
    ];
}

function rbac_seed_default_groups(mysqli $db, array $catalog) {
    $created = 0;
    $templates = rbac_default_group_blueprints();
    foreach ($templates as $tpl) {
        $name = trim((string)$tpl['name']);
        if ($name === '') continue;

        $chk = $db->prepare('SELECT id FROM user_groups WHERE name = ? LIMIT 1');
        $chk->bind_param('s', $name);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if ($row) continue;

        $db->begin_transaction();
        try {
            $desc = trim((string)$tpl['description']);
            $active = 1;
            $ins = $db->prepare('INSERT INTO user_groups (name, description, is_active) VALUES (?,?,?)');
            $ins->bind_param('ssi', $name, $desc, $active);
            $ins->execute();
            $gid = (int)$ins->insert_id;

            $permIns = $db->prepare('INSERT INTO group_page_permissions (group_id, page_path, can_view) VALUES (?,?,1)');
            foreach ((array)$tpl['pages'] as $path) {
                $path = rbacNormalizePath((string)$path);
                if (!isset($catalog[$path])) continue;
                $permIns->bind_param('is', $gid, $path);
                $permIns->execute();
            }

            $db->commit();
            $created++;
        } catch (Throwable $e) {
            $db->rollback();
        }
    }
    return $created;
}

$catalog = rbacPageCatalog();
$errors = [];

$groupCountRow = $db->query('SELECT COUNT(*) AS c FROM user_groups');
$groupCount = (int)($groupCountRow ? ($groupCountRow->fetch_assoc()['c'] ?? 0) : 0);
if ($groupCount === 0) {
    $createdDefaults = rbac_seed_default_groups($db, $catalog);
    if ($createdDefaults > 0) {
        setFlash('success', 'Default groups created automatically.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'seed_default_groups') {
            $created = rbac_seed_default_groups($db, $catalog);
            if ($created > 0) {
                setFlash('success', $created . ' default group(s) created.');
            } else {
                setFlash('info', 'Default groups already exist.');
            }
            redirect(BASE_URL . '/modules/users/groups.php');
        }

        if ($action === 'create_group') {
            $name = trim((string)($_POST['name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $active = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $errors[] = 'Group name is required.';
            }

            if (empty($errors)) {
                $chk = $db->prepare('SELECT id FROM user_groups WHERE name = ? LIMIT 1');
                $chk->bind_param('s', $name);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $errors[] = 'Group name already exists.';
                }
            }

            if (empty($errors)) {
                $ins = $db->prepare('INSERT INTO user_groups (name, description, is_active) VALUES (?,?,?)');
                $ins->bind_param('ssi', $name, $desc, $active);
                if ($ins->execute()) {
                    setFlash('success', 'Group created successfully.');
                    redirect(BASE_URL . '/modules/users/groups.php?gid=' . (int)$ins->insert_id);
                }
                $errors[] = 'Database error: ' . $db->error;
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
                $chk = $db->prepare('SELECT id FROM user_groups WHERE name = ? AND id != ? LIMIT 1');
                $chk->bind_param('si', $name, $gid);
                $chk->execute();
                if ($chk->get_result()->fetch_assoc()) {
                    $errors[] = 'Another group already uses this name.';
                }
            }

            if (empty($errors)) {
                $upd = $db->prepare('UPDATE user_groups SET name=?, description=?, is_active=? WHERE id=?');
                $upd->bind_param('ssii', $name, $desc, $active, $gid);
                if ($upd->execute()) {
                    setFlash('success', 'Group updated.');
                    redirect(BASE_URL . '/modules/users/groups.php?gid=' . $gid);
                }
                $errors[] = 'Update failed: ' . $db->error;
            }
        }

        if ($action === 'delete_group') {
            $gid = (int)($_POST['gid'] ?? 0);
            if ($gid <= 0) $errors[] = 'Invalid group.';

            if (empty($errors)) {
                $cntQ = $db->prepare('SELECT COUNT(*) AS c FROM users WHERE group_id = ?');
                $cntQ->bind_param('i', $gid);
                $cntQ->execute();
                $cnt = (int)($cntQ->get_result()->fetch_assoc()['c'] ?? 0);
                if ($cnt > 0) {
                    $errors[] = 'Cannot delete group. Users are still assigned to this group.';
                }
            }

            if (empty($errors)) {
                $del = $db->prepare('DELETE FROM user_groups WHERE id = ?');
                $del->bind_param('i', $gid);
                if ($del->execute()) {
                    setFlash('success', 'Group deleted.');
                    redirect(BASE_URL . '/modules/users/groups.php');
                }
                $errors[] = 'Delete failed: ' . $db->error;
            }
        }

        if ($action === 'save_permissions') {
            $gid = (int)($_POST['gid'] ?? 0);
            $pages = $_POST['pages'] ?? [];
            if (!is_array($pages)) $pages = [];
            $addPages = $_POST['add_pages'] ?? [];
            $editPages = $_POST['edit_pages'] ?? [];
            $deletePages = $_POST['delete_pages'] ?? [];
            if (!is_array($addPages)) $addPages = [];
            if (!is_array($editPages)) $editPages = [];
            if (!is_array($deletePages)) $deletePages = [];

            if ($gid <= 0) {
                $errors[] = 'Invalid group.';
            }

            $valid = [];
            foreach ($pages as $p) {
                $p = rbacNormalizePath((string)$p);
                if (isset($catalog[$p])) {
                    $valid[$p] = true;
                }
            }
            $validPages = array_keys($valid);

            $addSet = [];
            foreach ($addPages as $p) { $addSet[rbacNormalizePath((string)$p)] = true; }
            $editSet = [];
            foreach ($editPages as $p) { $editSet[rbacNormalizePath((string)$p)] = true; }
            $deleteSet = [];
            foreach ($deletePages as $p) { $deleteSet[rbacNormalizePath((string)$p)] = true; }

            if (empty($errors)) {
                $db->begin_transaction();
                try {
                    $del = $db->prepare('DELETE FROM group_page_permissions WHERE group_id = ?');
                    $del->bind_param('i', $gid);
                    $del->execute();

                    if (!empty($validPages)) {
                        $ins = $db->prepare('INSERT INTO group_page_permissions (group_id, page_path, can_view, can_add, can_edit, can_delete) VALUES (?,?,1,?,?,?)');
                        foreach ($validPages as $pagePath) {
                            $cAdd = isset($addSet[$pagePath]) ? 1 : 0;
                            $cEdit = isset($editSet[$pagePath]) ? 1 : 0;
                            $cDel = isset($deleteSet[$pagePath]) ? 1 : 0;
                            $ins->bind_param('isiii', $gid, $pagePath, $cAdd, $cEdit, $cDel);
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

$groups = $db->query('SELECT g.*, (SELECT COUNT(*) FROM users u WHERE u.group_id = g.id) AS users_count FROM user_groups g ORDER BY g.name ASC')->fetch_all(MYSQLI_ASSOC);

$selectedGroupId = (int)($_GET['gid'] ?? 0);
if ($selectedGroupId <= 0 && !empty($groups)) {
    $selectedGroupId = (int)$groups[0]['id'];
}

$selectedGroup = null;
foreach ($groups as $groupRow) {
    if ((int)$groupRow['id'] === $selectedGroupId) {
        $selectedGroup = $groupRow;
        break;
    }
}

$selectedPerms = [];
if ($selectedGroupId > 0) {
    $pq = $db->prepare('SELECT page_path, can_add, can_edit, can_delete FROM group_page_permissions WHERE group_id = ? AND can_view = 1');
    $pq->bind_param('i', $selectedGroupId);
    $pq->execute();
    $rows = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $selectedPerms[rbacNormalizePath((string)$row['page_path'])] = [
            'add' => (int)($row['can_add'] ?? 0) === 1,
            'edit' => (int)($row['can_edit'] ?? 0) === 1,
            'delete' => (int)($row['can_delete'] ?? 0) === 1,
        ];
    }
}

$moduleCardsBySection = [];
foreach ($catalog as $path => $label) {
    $path = rbacNormalizePath($path);
    $dir = str_replace('\\', '/', dirname($path));
    $basePath = ($dir === '.' ? '' : $dir) . '/index.php';
    $basePath = rbacNormalizePath($basePath);
    $baseLabel = $catalog[$basePath] ?? $label;
    $section = rbac_ui_section_for_path($path);
    $moduleKey = $dir;
    if (!isset($moduleCardsBySection[$section])) $moduleCardsBySection[$section] = [];
    if (!isset($moduleCardsBySection[$section][$moduleKey])) {
        $moduleCardsBySection[$section][$moduleKey] = [
            'title' => $baseLabel,
            'blurb' => rbac_ui_module_blurb($dir, $baseLabel),
            'items' => [],
        ];
    }

    $action = rbac_ui_action_from_item($path, $label, $baseLabel);
    $permData = $selectedPerms[$path] ?? null;
    $isGranular = (strpos($path, '/modules/planning/') === 0);
    $moduleCardsBySection[$section][$moduleKey]['items'][] = [
        'path' => $path,
        'action' => $action,
        'hint' => rbac_ui_action_hint($action),
        'checked' => $permData !== null,
        'granular' => $isGranular,
        'can_add' => $permData ? $permData['add'] : false,
        'can_edit' => $permData ? $permData['edit'] : false,
        'can_delete' => $permData ? $permData['delete'] : false,
    ];
}

$actionRank = ['Open' => 1, 'View' => 2, 'Add' => 3, 'Edit' => 4, 'Delete' => 5, 'Export' => 6, 'Import' => 7, 'Print Label' => 8, 'Batch Delete' => 9, 'Manage Groups & Permissions' => 10];
foreach ($moduleCardsBySection as $section => $modules) {
    uasort($moduleCardsBySection[$section], function($left, $right) {
        return strcmp($left['title'], $right['title']);
    });
    foreach ($moduleCardsBySection[$section] as $moduleKey => $module) {
        usort($moduleCardsBySection[$section][$moduleKey]['items'], function($left, $right) use ($actionRank) {
            $rankLeft = $actionRank[$left['action']] ?? 99;
            $rankRight = $actionRank[$right['action']] ?? 99;
            if ($rankLeft !== $rankRight) return $rankLeft - $rankRight;
            return strcmp($left['action'], $right['action']);
        });
    }
}
ksort($moduleCardsBySection);

$selectedCount = count($selectedPerms);
$selectedGroupUsers = (int)($selectedGroup['users_count'] ?? 0);

$pageTitle = 'Groups & Permissions';
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a><span class="breadcrumb-sep">›</span>
  <a href="<?= BASE_URL ?>/modules/users/index.php">Users</a><span class="breadcrumb-sep">›</span>
  <span>Groups & Permissions</span>
</div>

<div class="page-header gp-page-header">
  <div>
    <h1>Groups & Permissions</h1>
    <p>Grant module access with clear page actions like Open, Add, Edit, Delete, Export, and Print.</p>
  </div>
  <div class="d-flex gap-8 gp-header-actions">
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
      <input type="hidden" name="action" value="seed_default_groups">
      <button type="submit" class="btn btn-ghost"><i class="bi bi-stars"></i> Create Default Groups</button>
    </form>
    <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Users</a>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Errors:</strong>
  <ul style="margin:6px 0 0 18px"><?php foreach($errors as $errorItem): ?><li><?= e($errorItem) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="gp-shell">
  <aside class="gp-sidebar">
    <section class="gp-panel gp-panel-soft">
      <div class="gp-panel-head">
        <div>
          <span class="gp-eyebrow">Create Group</span>
          <h3>New Access Group</h3>
        </div>
      </div>
      <form method="POST" class="gp-form-stack">
        <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
        <input type="hidden" name="action" value="create_group">
        <label>
          <span>Group Name</span>
          <input type="text" name="name" class="form-control" placeholder="e.g. Store Operator" required>
        </label>
        <label>
          <span>Description</span>
          <input type="text" name="description" class="form-control" placeholder="Who should use this group?">
        </label>
        <label class="gp-check-row">
          <input type="checkbox" name="is_active" value="1" checked>
          <span>Active group</span>
        </label>
        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Create Group</button>
      </form>
    </section>

    <section class="gp-panel">
      <div class="gp-panel-head">
        <div>
          <span class="gp-eyebrow">Available Groups</span>
          <h3>Access Teams</h3>
        </div>
        <span class="badge badge-draft"><?= count($groups) ?></span>
      </div>
      <div class="gp-group-list">
        <?php if (empty($groups)): ?>
          <div class="table-empty" style="padding:18px"><i class="bi bi-inbox"></i>No groups found.</div>
        <?php else: ?>
          <?php foreach ($groups as $groupRow): ?>
            <a class="gp-group-card <?= (int)$groupRow['id'] === $selectedGroupId ? 'active' : '' ?>" href="groups.php?gid=<?= (int)$groupRow['id'] ?>">
              <div class="gp-group-main">
                <strong><?= e($groupRow['name']) ?></strong>
                <small><?= e((string)($groupRow['description'] ?: 'No description added yet.')) ?></small>
              </div>
              <div class="gp-group-meta">
                <span class="badge <?= (int)$groupRow['is_active'] === 1 ? 'badge-available' : 'badge-cancelled' ?>"><?= (int)$groupRow['is_active'] === 1 ? 'Active' : 'Inactive' ?></span>
                <span class="gp-user-count"><?= (int)$groupRow['users_count'] ?> user(s)</span>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </aside>

  <section class="gp-main">
    <?php if (!$selectedGroup): ?>
      <div class="gp-panel gp-empty-state">
        <i class="bi bi-people"></i>
        <h3>Select a group</h3>
        <p>Create a new group or choose an existing one to manage page access.</p>
      </div>
    <?php else: ?>
      <section class="gp-hero">
        <div>
          <span class="gp-eyebrow">Selected Group</span>
          <h2><?= e($selectedGroup['name']) ?></h2>
          <p><?= e((string)($selectedGroup['description'] ?: 'No description added. Use this group to assign exact page actions.')) ?></p>
        </div>
        <div class="gp-hero-stats">
          <div class="gp-stat-card">
            <span>Users</span>
            <strong><?= $selectedGroupUsers ?></strong>
          </div>
          <div class="gp-stat-card">
            <span>Permissions</span>
            <strong id="perm-selected-count"><?= $selectedCount ?></strong>
          </div>
          <div class="gp-stat-card">
            <span>Status</span>
            <strong><?= (int)$selectedGroup['is_active'] === 1 ? 'Active' : 'Inactive' ?></strong>
          </div>
        </div>
      </section>

      <div class="gp-two-col">
        <form method="POST" class="gp-panel">
          <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
          <input type="hidden" name="action" value="update_group">
          <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">
          <div class="gp-panel-head">
            <div>
              <span class="gp-eyebrow">Group Setup</span>
              <h3>Basic Details</h3>
            </div>
          </div>
          <div class="gp-form-grid">
            <label>
              <span>Group Name</span>
              <input type="text" name="name" class="form-control" required value="<?= e($selectedGroup['name']) ?>">
            </label>
            <label>
              <span>Description</span>
              <input type="text" name="description" class="form-control" value="<?= e((string)$selectedGroup['description']) ?>">
            </label>
            <label class="gp-check-row">
              <input type="checkbox" name="is_active" value="1" <?= (int)$selectedGroup['is_active'] === 1 ? 'checked' : '' ?>>
              <span>Active group</span>
            </label>
          </div>
          <div class="gp-panel-actions">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-circle"></i> Save Group</button>
          </div>
        </form>

        <form method="POST" class="gp-panel" data-confirm="Delete this group? This cannot be undone.">
          <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
          <input type="hidden" name="action" value="delete_group">
          <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">
          <div class="gp-panel-head">
            <div>
              <span class="gp-eyebrow">Danger Zone</span>
              <h3>Delete Group</h3>
            </div>
          </div>
          <p class="gp-danger-copy">Delete only if no users are assigned to this group.</p>
          <div class="gp-panel-actions">
            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Delete Group</button>
          </div>
        </form>
      </div>

      <form method="POST" class="gp-panel gp-panel-permissions">
        <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
        <input type="hidden" name="action" value="save_permissions">
        <input type="hidden" name="gid" value="<?= (int)$selectedGroup['id'] ?>">

        <div class="gp-panel-head gp-panel-head-wrap">
          <div>
            <span class="gp-eyebrow">Permission Designer</span>
            <h3>Module Access</h3>
            <p class="gp-head-help">Example: if you want someone to add paper rolls, enable <strong>Paper Stock</strong> and then enable <strong>Add</strong>.</p>
          </div>
          <div class="gp-perm-tools">
            <input type="text" id="perm-search" class="form-control" placeholder="Search module or function, e.g. Paper Stock, Add, Flexo">
            <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check"></i> Save Permissions</button>
          </div>
        </div>

        <?php foreach ($moduleCardsBySection as $section => $modules): ?>
          <section class="gp-module-section" data-section-block>
            <div class="gp-module-section-head">
              <div>
                <span class="gp-eyebrow"><?= e($section) ?></span>
                <h4><?= e($section) ?></h4>
              </div>
              <span class="badge badge-draft"><?= count($modules) ?> module(s)</span>
            </div>

            <div class="gp-module-grid">
              <?php foreach ($modules as $module): ?>
                <?php
                  $searchBag = strtolower($section . ' ' . $module['title'] . ' ' . $module['blurb']);
                  foreach ($module['items'] as $moduleItem) {
                      $searchBag .= ' ' . strtolower($moduleItem['action'] . ' ' . $moduleItem['hint']);
                  }
                ?>
                <article class="gp-module-card" data-module-card data-search="<?= e($searchBag) ?>">
                  <div class="gp-module-card-head">
                    <div>
                      <span class="gp-module-kicker"><?= e($section) ?></span>
                      <h5><?= e($module['title']) ?></h5>
                    </div>
                    <span class="badge badge-consumed"><?= count($module['items']) ?> action(s)</span>
                  </div>
                  <p class="gp-module-copy"><?= e($module['blurb']) ?></p>

                  <div class="gp-action-list">
                    <?php foreach ($module['items'] as $moduleItem): ?>
                      <label class="gp-action-card">
                        <input type="checkbox" class="perm-cb" name="pages[]" value="<?= e($moduleItem['path']) ?>" <?= $moduleItem['checked'] ? 'checked' : '' ?>>
                        <span class="gp-action-body">
                          <strong><?= e($moduleItem['action']) ?></strong>
                          <small><?= e($moduleItem['hint']) ?></small>
                          <?php if ($moduleItem['granular']): ?>
                          <div class="gp-granular-perms">
                            <label class="gp-granular-cb" title="Allow creating new entries"><input type="checkbox" name="add_pages[]" value="<?= e($moduleItem['path']) ?>" <?= $moduleItem['can_add'] ? 'checked' : '' ?>> <span>Add</span></label>
                            <label class="gp-granular-cb" title="Allow editing existing entries"><input type="checkbox" name="edit_pages[]" value="<?= e($moduleItem['path']) ?>" <?= $moduleItem['can_edit'] ? 'checked' : '' ?>> <span>Edit</span></label>
                            <label class="gp-granular-cb" title="Allow deleting entries"><input type="checkbox" name="delete_pages[]" value="<?= e($moduleItem['path']) ?>" <?= $moduleItem['can_delete'] ? 'checked' : '' ?>> <span>Delete</span></label>
                          </div>
                          <?php endif; ?>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </form>
    <?php endif; ?>
  </section>
</div>

<style>
.gp-page-header { align-items: flex-start; gap: 16px; }
.gp-header-actions { flex-wrap: wrap; }
.gp-shell { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 18px; align-items: start; }
.gp-sidebar, .gp-main { min-width: 0; }
.gp-panel {
  background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
  border: 1px solid #e6edf5;
  border-radius: 18px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, .05);
  padding: 18px;
}
.gp-panel-soft {
  background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
}
.gp-panel + .gp-panel { margin-top: 16px; }
.gp-panel-head, .gp-module-section-head {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.gp-panel-head h3, .gp-module-section-head h4, .gp-hero h2 { margin: 0; }
.gp-panel-head-wrap { flex-wrap: wrap; }
.gp-eyebrow, .gp-module-kicker {
  display: inline-block;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: #64748b;
  margin-bottom: 6px;
}
.gp-form-stack, .gp-form-grid { display: grid; gap: 12px; }
.gp-form-stack label span, .gp-form-grid label span { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #475569; }
.gp-check-row { display: flex; align-items: center; gap: 8px; font-weight: 600; color: #334155; }
.gp-check-row input { width: 16px; height: 16px; }
.gp-group-list { display: grid; gap: 10px; }
.gp-group-card {
  display: grid;
  gap: 8px;
  padding: 14px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  text-decoration: none;
  color: inherit;
  background: #fff;
  transition: .18s ease;
}
.gp-group-card:hover { border-color: #bfdbfe; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(37, 99, 235, .08); }
.gp-group-card.active { border-color: #60a5fa; background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%); }
.gp-group-main strong { display: block; font-size: 15px; color: #0f172a; }
.gp-group-main small { display: block; margin-top: 4px; color: #64748b; line-height: 1.45; }
.gp-group-meta { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.gp-user-count { font-size: 12px; color: #64748b; }
.gp-hero {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 16px;
  padding: 22px;
  border-radius: 20px;
  margin-bottom: 18px;
  color: #fff;
  background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 55%, #0ea5e9 100%);
  box-shadow: 0 18px 38px rgba(29, 78, 216, .24);
}
.gp-hero p { margin: 8px 0 0; max-width: 720px; color: rgba(255,255,255,.82); }
.gp-hero-stats { display: grid; grid-template-columns: repeat(3, minmax(100px, 1fr)); gap: 10px; min-width: 330px; }
.gp-stat-card {
  background: rgba(255,255,255,.12);
  border: 1px solid rgba(255,255,255,.16);
  border-radius: 16px;
  padding: 14px;
  backdrop-filter: blur(8px);
}
.gp-stat-card span { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .78; }
.gp-stat-card strong { display: block; margin-top: 6px; font-size: 20px; }
.gp-two-col { display: grid; grid-template-columns: 1.3fr .8fr; gap: 16px; margin-bottom: 18px; }
.gp-panel-actions { display: flex; align-items: center; justify-content: flex-end; margin-top: 14px; }
.gp-danger-copy { margin: 0; color: #64748b; line-height: 1.55; }
.gp-head-help { margin: 8px 0 0; color: #64748b; }
.gp-perm-tools { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.gp-perm-tools .form-control { min-width: 320px; }
.gp-module-section + .gp-module-section { margin-top: 22px; }
.gp-module-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 14px; }
.gp-module-card {
  border: 1px solid #e2e8f0;
  border-radius: 18px;
  padding: 16px;
  background: linear-gradient(180deg, #ffffff 0%, #fcfdff 100%);
  box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
}
.gp-module-card-head {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 12px;
}
.gp-module-card h5 { margin: 0; font-size: 18px; color: #0f172a; }
.gp-module-copy { margin: 10px 0 14px; color: #64748b; min-height: 42px; }
.gp-action-list { display: grid; gap: 10px; }
.gp-action-card {
  display: flex;
  gap: 12px;
  align-items: start;
  padding: 12px;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #fff;
  cursor: pointer;
  transition: .16s ease;
}
.gp-action-card:hover { border-color: #93c5fd; background: #f8fbff; }
.gp-action-card input { width: 16px; height: 16px; margin-top: 2px; }
.gp-action-body strong { display: block; color: #0f172a; font-size: 14px; }
.gp-action-body small { display: block; margin-top: 4px; color: #64748b; line-height: 1.45; }
.gp-granular-perms {
  display: flex;
  gap: 12px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
}
.gp-granular-cb {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  padding: 3px 8px;
  border-radius: 6px;
  background: #f1f5f9;
  transition: .14s ease;
}
.gp-granular-cb:hover { background: #e2e8f0; }
.gp-granular-cb input { width: 14px; height: 14px; }
.gp-granular-cb input:checked + span { color: #1d4ed8; }
.gp-empty-state {
  min-height: 320px;
  display: grid;
  place-items: center;
  text-align: center;
}
.gp-empty-state i { font-size: 42px; color: #94a3b8; }
.gp-empty-state h3 { margin: 12px 0 6px; }
.gp-empty-state p { margin: 0; color: #64748b; }

@media (max-width: 1180px) {
  .gp-shell { grid-template-columns: 1fr; }
  .gp-hero { flex-direction: column; }
  .gp-hero-stats { min-width: 0; width: 100%; }
  .gp-two-col { grid-template-columns: 1fr; }
}

@media (max-width: 720px) {
  .gp-page-header { flex-direction: column; }
  .gp-hero-stats { grid-template-columns: 1fr; }
  .gp-module-grid { grid-template-columns: 1fr; }
  .gp-perm-tools .form-control { min-width: 0; width: 100%; }
}
</style>

<script>
(function(){
  function updatePermSelectedCount() {
    var checked = document.querySelectorAll('.perm-cb:checked').length;
    var el = document.getElementById('perm-selected-count');
    if (el) el.textContent = String(checked);

    document.querySelectorAll('.gp-module-card').forEach(function(card){
      var hasChecked = !!card.querySelector('.perm-cb:checked');
      card.style.borderColor = hasChecked ? '#60a5fa' : '#e2e8f0';
      card.style.boxShadow = hasChecked ? '0 12px 24px rgba(37, 99, 235, .08)' : 'none';
    });
  }

  function filterPermItems() {
    var search = document.getElementById('perm-search');
    var q = search ? String(search.value || '').toLowerCase().trim() : '';

    document.querySelectorAll('[data-module-card]').forEach(function(card){
      var hay = String(card.getAttribute('data-search') || '');
      card.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });

    document.querySelectorAll('[data-section-block]').forEach(function(section){
      var hasVisible = false;
      section.querySelectorAll('[data-module-card]').forEach(function(card){
        if (card.style.display !== 'none') hasVisible = true;
      });
      section.style.display = hasVisible ? '' : 'none';
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    var search = document.getElementById('perm-search');
    if (search) search.addEventListener('input', filterPermItems);
    document.querySelectorAll('.perm-cb').forEach(function(cb){
      cb.addEventListener('change', function(){
        updatePermSelectedCount();
        // Uncheck granular sub-checkboxes when parent is unchecked
        if (!cb.checked) {
          var card = cb.closest('.gp-action-card');
          if (card) {
            card.querySelectorAll('.gp-granular-perms input[type="checkbox"]').forEach(function(sub){
              sub.checked = false;
            });
          }
        }
      });
    });
    // If granular checkbox is checked, auto-check the parent page checkbox
    document.querySelectorAll('.gp-granular-perms input[type="checkbox"]').forEach(function(sub){
      sub.addEventListener('change', function(){
        if (sub.checked) {
          var card = sub.closest('.gp-action-card');
          if (card) {
            var parent = card.querySelector('.perm-cb');
            if (parent && !parent.checked) {
              parent.checked = true;
              updatePermSelectedCount();
            }
          }
        }
      });
    });
    updatePermSelectedCount();
  });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>