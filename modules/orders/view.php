<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$order = $conn->query("
    SELECT o.*, c.name as customer_name, c.company, c.email as customer_email,
           c.phone as customer_phone, c.address as customer_address, c.city, c.state, c.gstin
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = $id
")->fetch_assoc();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: index.php'); exit; }

$items = $conn->query("
    SELECT oi.*, p.name as product_name, p.code, p.unit
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = $id
");

$invoice = $conn->query("SELECT id, invoice_number FROM invoices WHERE order_id = $id")->fetch_assoc();

$pageTitle  = 'Order: ' . $order['order_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Orders', 'url' => 'index.php'],
    ['label' => $order['order_number'], 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0">
        <i class="fas fa-shopping-cart me-2 text-primary"></i>
        <?php echo sanitize($order['order_number']); ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if (!$invoice): ?>
        <a href="<?php echo getBaseUrl(); ?>/modules/invoices/create.php?order_id=<?php echo $id; ?>" class="btn btn-sm btn-success">
            <i class="fas fa-file-invoice me-1"></i> Generate Invoice
        </a>
        <?php else: ?>
        <a href="<?php echo getBaseUrl(); ?>/modules/invoices/view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info">
            <i class="fas fa-file-invoice me-1"></i> View Invoice
        </a>
        <?php endif; ?>
        <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <!-- Items -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Order Items</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; while ($item = $items->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <strong><?php echo sanitize($item['product_name']); ?></strong>
                                    <?php if ($item['code']): ?>
                                    <span class="badge bg-secondary ms-1"><?php echo sanitize($item['code']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($item['quantity']); ?> <?php echo sanitize($item['unit']); ?></td>
                                <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                <td class="text-end fw-semibold"><?php echo formatCurrency($item['total']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="4" class="text-end">Subtotal</td>
                                <td class="text-end"><?php echo formatCurrency($order['subtotal']); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end">Discount</td>
                                <td class="text-end text-danger">- <?php echo formatCurrency($order['discount']); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end">GST (<?php echo $order['tax_percent']; ?>%)</td>
                                <td class="text-end"><?php echo formatCurrency($order['tax_amount']); ?></td>
                            </tr>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">Total</td>
                                <td class="text-end fs-5"><?php echo formatCurrency($order['total']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php if ($order['notes']): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Notes</div>
            <div class="card-body"><?php echo nl2br(sanitize($order['notes'])); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Order Info</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th>Status</th><td><span class="badge badge-status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></td></tr>
                    <tr><th>Order Date</th><td><?php echo date('d M Y', strtotime($order['order_date'])); ?></td></tr>
                    <tr><th>Delivery Date</th><td><?php echo $order['delivery_date'] ? date('d M Y', strtotime($order['delivery_date'])) : '-'; ?></td></tr>
                    <tr><th>Created</th><td><?php echo date('d M Y H:i', strtotime($order['created_at'])); ?></td></tr>
                </table>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Customer</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th>Name</th><td><?php echo sanitize($order['customer_name']); ?></td></tr>
                    <?php if ($order['company']): ?><tr><th>Company</th><td><?php echo sanitize($order['company']); ?></td></tr><?php endif; ?>
                    <?php if ($order['customer_phone']): ?><tr><th>Phone</th><td><?php echo sanitize($order['customer_phone']); ?></td></tr><?php endif; ?>
                    <?php if ($order['customer_email']): ?><tr><th>Email</th><td><?php echo sanitize($order['customer_email']); ?></td></tr><?php endif; ?>
                    <?php if ($order['gstin']): ?><tr><th>GSTIN</th><td><?php echo sanitize($order['gstin']); ?></td></tr><?php endif; ?>
                </table>
                <a href="<?php echo getBaseUrl(); ?>/modules/customers/view.php?id=<?php echo $order['customer_id']; ?>" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="fas fa-user me-1"></i> View Customer
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
