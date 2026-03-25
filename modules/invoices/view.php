<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$invoice = $conn->query("
    SELECT i.*, c.name as customer_name, c.company, c.email as customer_email,
           c.phone as customer_phone, c.address, c.city, c.state, c.gstin,
           o.order_number, o.order_date
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN orders o ON i.order_id = o.id
    WHERE i.id = $id
")->fetch_assoc();
if (!$invoice) { setFlash('error', 'Invoice not found.'); header('Location: index.php'); exit; }

$items = $conn->query("
    SELECT oi.*, p.name as product_name, p.code, p.unit
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = {$invoice['order_id']}
");

// Update payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paid_amount'])) {
    $paid   = min((float)$_POST['paid_amount'], $invoice['total']);
    $status = 'unpaid';
    if ($paid >= $invoice['total']) { $status = 'paid'; $paid = $invoice['total']; }
    elseif ($paid > 0) { $status = 'partial'; }

    $stmt = $conn->prepare("UPDATE invoices SET paid_amount=?, payment_status=? WHERE id=?");
    $stmt->bind_param('dsi', $paid, $status, $id);
    $stmt->execute();
    setFlash('success', 'Payment updated.');
    header("Location: view.php?id=$id");
    exit;
}

$pageTitle  = 'Invoice: ' . $invoice['invoice_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Invoices', 'url' => 'index.php'],
    ['label' => $invoice['invoice_number'], 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';

$balance    = $invoice['total'] - $invoice['paid_amount'];
$psClass    = ['unpaid'=>'danger','partial'=>'warning','paid'=>'success'][$invoice['payment_status']] ?? 'secondary';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i><?php echo sanitize($invoice['invoice_number']); ?></h4>
    <div class="d-flex gap-2">
        <a href="print.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
            <i class="fas fa-print me-1"></i> Print
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <span class="fw-semibold"><?php echo sanitize($invoice['invoice_number']); ?></span>
                    <span class="ms-2 badge bg-<?php echo $psClass; ?>"><?php echo ucfirst($invoice['payment_status']); ?></span>
                </div>
                <div class="text-muted small"><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Product</th><th>Qty</th><th>Unit Price</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; while ($item = $items->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td>
                                    <?php echo sanitize($item['product_name']); ?>
                                    <?php if ($item['code']): ?><span class="badge bg-secondary ms-1"><?php echo sanitize($item['code']); ?></span><?php endif; ?>
                                </td>
                                <td><?php echo number_format($item['quantity']); ?> <?php echo sanitize($item['unit']); ?></td>
                                <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['total']); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?php echo formatCurrency($invoice['subtotal']); ?></td></tr>
                            <tr><td colspan="4" class="text-end">Discount</td><td class="text-end text-danger">- <?php echo formatCurrency($invoice['discount']); ?></td></tr>
                            <tr><td colspan="4" class="text-end">GST</td><td class="text-end"><?php echo formatCurrency($invoice['tax_amount']); ?></td></tr>
                            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end fs-5"><?php echo formatCurrency($invoice['total']); ?></td></tr>
                            <tr class="text-success"><td colspan="4" class="text-end">Paid</td><td class="text-end"><?php echo formatCurrency($invoice['paid_amount']); ?></td></tr>
                            <tr class="text-danger fw-bold"><td colspan="4" class="text-end">Balance Due</td><td class="text-end"><?php echo formatCurrency($balance); ?></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Bill To</div>
            <div class="card-body">
                <strong><?php echo sanitize($invoice['customer_name']); ?></strong><br>
                <?php if ($invoice['company']): ?><?php echo sanitize($invoice['company']); ?><br><?php endif; ?>
                <?php if ($invoice['address']): ?><?php echo sanitize($invoice['address']); ?><br><?php endif; ?>
                <?php if ($invoice['city']): ?><?php echo sanitize($invoice['city'] . ', ' . ($invoice['state'] ?? '')); ?><br><?php endif; ?>
                <?php if ($invoice['customer_phone']): ?><i class="fas fa-phone me-1 text-muted"></i><?php echo sanitize($invoice['customer_phone']); ?><br><?php endif; ?>
                <?php if ($invoice['gstin']): ?><small class="text-muted">GSTIN: <?php echo sanitize($invoice['gstin']); ?></small><?php endif; ?>
            </div>
        </div>

        <?php if ($invoice['payment_status'] !== 'paid'): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Record Payment</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Amount Paid (₹)</label>
                        <input type="number" name="paid_amount" class="form-control" value="<?php echo $invoice['paid_amount']; ?>" min="0" max="<?php echo $invoice['total']; ?>" step="0.01">
                    </div>
                    <button type="submit" class="btn btn-success btn-sm w-100">
                        <i class="fas fa-check me-1"></i> Update Payment
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Invoice Summary</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th>Invoice #</th><td><?php echo sanitize($invoice['invoice_number']); ?></td></tr>
                    <tr><th>Order #</th><td><a href="../orders/view.php?id=<?php echo $invoice['order_id']; ?>"><?php echo sanitize($invoice['order_number']); ?></a></td></tr>
                    <tr><th>Date</th><td><?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?></td></tr>
                    <tr><th>Due Date</th><td><?php echo $invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '-'; ?></td></tr>
                    <tr><th>Total</th><td class="fw-bold"><?php echo formatCurrency($invoice['total']); ?></td></tr>
                    <tr><th>Balance</th><td class="text-danger fw-bold"><?php echo formatCurrency($balance); ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
