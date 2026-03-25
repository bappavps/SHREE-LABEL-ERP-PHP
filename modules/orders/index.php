<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$search    = trim($_GET['search'] ?? '');
$status    = $_GET['status'] ?? '';
$params    = [];
$types     = '';
$conditions = [];

if ($search) {
    $like = '%' . $search . '%';
    $conditions[] = "(o.order_number LIKE ? OR c.name LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($status) {
    $conditions[] = "o.status = ?";
    $params[] = $status;
    $types   .= 's';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

if ($params) {
    $stmt = $conn->prepare("
        SELECT o.*, c.name as customer_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        $where
        ORDER BY o.created_at DESC
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $orders = $stmt->get_result();
} else {
    $orders = $conn->query("
        SELECT o.*, c.name as customer_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        ORDER BY o.created_at DESC
    ");
}

$pageTitle  = 'Orders';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Orders', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-shopping-cart me-2 text-primary"></i>Orders</h4>
    <a href="create.php" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> New Order
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form class="d-flex gap-2 flex-wrap" method="GET">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:250px;"
                   placeholder="Search order #, customer..." value="<?php echo sanitize($search); ?>">
            <select name="status" class="form-select form-select-sm" style="max-width:150px;">
                <option value="">All Status</option>
                <?php foreach (['pending','processing','completed','cancelled'] as $s): ?>
                <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
            <?php if ($search || $status): ?>
            <a href="index.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Order Date</th>
                        <th>Delivery Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders && $orders->num_rows > 0): ?>
                        <?php while ($o = $orders->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo sanitize($o['order_number']); ?></strong></td>
                            <td><?php echo sanitize($o['customer_name']); ?></td>
                            <td><?php echo date('d M Y', strtotime($o['order_date'])); ?></td>
                            <td><?php echo $o['delivery_date'] ? date('d M Y', strtotime($o['delivery_date'])) : '-'; ?></td>
                            <td class="fw-semibold"><?php echo formatCurrency($o['total']); ?></td>
                            <td>
                                <span class="badge badge-status-<?php echo $o['status']; ?>">
                                    <?php echo ucfirst($o['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="view.php?id=<?php echo $o['id']; ?>" class="btn btn-action btn-outline-info me-1">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $o['id']; ?>" class="btn btn-action btn-outline-primary me-1">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete.php?id=<?php echo $o['id']; ?>" class="btn btn-action btn-outline-danger"
                                   onclick="return confirm('Delete this order?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No orders found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
