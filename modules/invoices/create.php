<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) { header('Location: ../orders/index.php'); exit; }

// Check if invoice already exists
$stmt0 = $conn->prepare("SELECT id FROM invoices WHERE order_id = ?");
$stmt0->bind_param('i', $orderId);
$stmt0->execute();
$existing = $stmt0->get_result()->fetch_assoc();
if ($existing) {
    setFlash('info', 'Invoice already exists for this order.');
    header('Location: view.php?id=' . $existing['id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT o.*, c.name as customer_name, c.company, c.email as customer_email,
           c.phone as customer_phone, c.address, c.city, c.state, c.gstin
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    WHERE o.id = ?
");
$stmt->bind_param('i', $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: ../orders/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_date = trim($_POST['invoice_date'] ?? '');
    $due_date     = trim($_POST['due_date'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $paid_amount  = (float)($_POST['paid_amount'] ?? 0);

    if (!$invoice_date) $errors[] = 'Invoice date is required.';

    if (empty($errors)) {
        $invoiceNum   = generateInvoiceNumber();
        $payment_status = 'unpaid';
        if ($paid_amount >= $order['total']) {
            $payment_status = 'paid';
            $paid_amount = $order['total'];
        } elseif ($paid_amount > 0) {
            $payment_status = 'partial';
        }

        $dueDate = $due_date ?: null;
        $stmt = $conn->prepare("INSERT INTO invoices (invoice_number,order_id,customer_id,invoice_date,due_date,subtotal,discount,tax_amount,total,payment_status,paid_amount,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('siissddddsds',
            $invoiceNum, $orderId, $order['customer_id'], $invoice_date, $dueDate,
            $order['subtotal'], $order['discount'], $order['tax_amount'], $order['total'],
            $payment_status, $paid_amount, $notes
        );
        if ($stmt->execute()) {
            $invId = $conn->insert_id;
            setFlash('success', "Invoice $invoiceNum created successfully.");
            header("Location: view.php?id=$invId");
            exit;
        }
        $errors[] = 'Failed to create invoice: ' . $conn->error;
    }
}

$pageTitle  = 'Generate Invoice';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Invoices', 'url' => 'index.php'],
    ['label' => 'Generate Invoice', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-file-invoice me-2 text-primary"></i>Generate Invoice</h4>
    <a href="../orders/view.php?id=<?php echo $orderId; ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Order
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . sanitize($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Invoice Details</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Invoice Date <span class="text-danger">*</span></label>
                            <input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount Received (₹)</label>
                            <input type="number" name="paid_amount" class="form-control" value="0" min="0" step="0.01">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="fas fa-file-invoice me-1"></i> Generate Invoice
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Order Summary</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th>Order #</th><td><?php echo sanitize($order['order_number']); ?></td></tr>
                    <tr><th>Customer</th><td><?php echo sanitize($order['customer_name']); ?></td></tr>
                    <tr><th>Subtotal</th><td><?php echo formatCurrency($order['subtotal']); ?></td></tr>
                    <tr><th>Discount</th><td>- <?php echo formatCurrency($order['discount']); ?></td></tr>
                    <tr><th>GST (<?php echo $order['tax_percent']; ?>%)</th><td><?php echo formatCurrency($order['tax_amount']); ?></td></tr>
                    <tr class="fw-bold"><th>Total</th><td class="fs-5"><?php echo formatCurrency($order['total']); ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
