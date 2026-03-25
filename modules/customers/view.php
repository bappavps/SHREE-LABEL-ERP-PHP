<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$customer = $conn->query("SELECT * FROM customers WHERE id = $id")->fetch_assoc();
if (!$customer) { setFlash('error', 'Customer not found.'); header('Location: index.php'); exit; }

$orders = $conn->query("SELECT * FROM orders WHERE customer_id = $id ORDER BY order_date DESC LIMIT 10");

$pageTitle  = 'Customer: ' . $customer['name'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Customers', 'url' => 'index.php'],
    ['label' => $customer['name'], 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i><?php echo sanitize($customer['name']); ?></h4>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Customer Details</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th width="40%">Name</th><td><?php echo sanitize($customer['name']); ?></td></tr>
                    <tr><th>Company</th><td><?php echo sanitize($customer['company'] ?? '-'); ?></td></tr>
                    <tr><th>Email</th><td><?php echo sanitize($customer['email'] ?? '-'); ?></td></tr>
                    <tr><th>Phone</th><td><?php echo sanitize($customer['phone'] ?? '-'); ?></td></tr>
                    <tr><th>Address</th><td><?php echo sanitize($customer['address'] ?? '-'); ?></td></tr>
                    <tr><th>City</th><td><?php echo sanitize($customer['city'] ?? '-'); ?></td></tr>
                    <tr><th>State</th><td><?php echo sanitize($customer['state'] ?? '-'); ?></td></tr>
                    <tr><th>Pincode</th><td><?php echo sanitize($customer['pincode'] ?? '-'); ?></td></tr>
                    <tr><th>GSTIN</th><td><?php echo sanitize($customer['gstin'] ?? '-'); ?></td></tr>
                    <tr><th>Since</th><td><?php echo date('d M Y', strtotime($customer['created_at'])); ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Recent Orders</span>
                <a href="<?php echo getBaseUrl(); ?>/modules/orders/create.php?customer_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus me-1"></i> New Order
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Order #</th><th>Date</th><th>Total</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($orders && $orders->num_rows > 0): ?>
                                <?php while ($o = $orders->fetch_assoc()): ?>
                                <tr>
                                    <td><a href="<?php echo getBaseUrl(); ?>/modules/orders/view.php?id=<?php echo $o['id']; ?>"><?php echo sanitize($o['order_number']); ?></a></td>
                                    <td><?php echo date('d M Y', strtotime($o['order_date'])); ?></td>
                                    <td><?php echo formatCurrency($o['total']); ?></td>
                                    <td><span class="badge badge-status-<?php echo $o['status']; ?>"><?php echo ucfirst($o['status']); ?></span></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-3 text-muted">No orders yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
