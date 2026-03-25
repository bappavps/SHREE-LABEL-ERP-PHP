<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$search = trim($_GET['search'] ?? '');
if ($search) {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT * FROM customers WHERE status = 1 AND (name LIKE ? OR company LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY created_at DESC");
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $customers = $stmt->get_result();
} else {
    $customers = $conn->query("SELECT * FROM customers WHERE status = 1 ORDER BY created_at DESC");
}

$pageTitle  = 'Customers';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Customers', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-users me-2 text-primary"></i>Customers</h4>
    <a href="create.php" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> Add Customer
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form class="d-flex gap-2" method="GET">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:300px;"
                   placeholder="Search by name, company, phone..." value="<?php echo sanitize($search); ?>">
            <button class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-search"></i>
            </button>
            <?php if ($search): ?>
            <a href="index.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>City</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers && $customers->num_rows > 0): ?>
                        <?php $i = 1; while ($c = $customers->fetch_assoc()): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $i++; ?></td>
                            <td><strong><?php echo sanitize($c['name']); ?></strong></td>
                            <td><?php echo sanitize($c['company'] ?? '-'); ?></td>
                            <td><?php echo sanitize($c['phone'] ?? '-'); ?></td>
                            <td><?php echo sanitize($c['email'] ?? '-'); ?></td>
                            <td><?php echo sanitize($c['city'] ?? '-'); ?></td>
                            <td>
                                <a href="view.php?id=<?php echo $c['id']; ?>" class="btn btn-action btn-outline-info me-1">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $c['id']; ?>" class="btn btn-action btn-outline-primary me-1">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete.php?id=<?php echo $c['id']; ?>" class="btn btn-action btn-outline-danger"
                                   onclick="return confirm('Delete this customer?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No customers found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
