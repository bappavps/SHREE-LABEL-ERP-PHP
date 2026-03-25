<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$search = trim($_GET['search'] ?? '');
$payStatus = $_GET['pay_status'] ?? '';
$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (i.invoice_number LIKE '%$s%' OR c.name LIKE '%$s%')";
}
if ($payStatus) {
    $ps = $conn->real_escape_string($payStatus);
    $where .= " AND i.payment_status = '$ps'";
}

$invoices = $conn->query("
    SELECT i.*, c.name as customer_name, o.order_number
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN orders o ON i.order_id = o.id
    $where
    ORDER BY i.created_at DESC
");

$pageTitle  = 'Invoices';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Invoices', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Invoices</h4>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form class="d-flex gap-2 flex-wrap" method="GET">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:250px;"
                   placeholder="Search invoice #, customer..." value="<?php echo sanitize($search); ?>">
            <select name="pay_status" class="form-select form-select-sm" style="max-width:150px;">
                <option value="">All Status</option>
                <?php foreach (['unpaid','partial','paid'] as $ps): ?>
                <option value="<?php echo $ps; ?>" <?php echo $payStatus === $ps ? 'selected' : ''; ?>><?php echo ucfirst($ps); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
            <?php if ($search || $payStatus): ?>
            <a href="index.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Invoice #</th>
                        <th>Customer</th>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($invoices && $invoices->num_rows > 0): ?>
                        <?php while ($inv = $invoices->fetch_assoc()):
                            $balance = $inv['total'] - $inv['paid_amount'];
                            $psClass = ['unpaid'=>'danger','partial'=>'warning','paid'=>'success'][$inv['payment_status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td><strong><?php echo sanitize($inv['invoice_number']); ?></strong></td>
                            <td><?php echo sanitize($inv['customer_name']); ?></td>
                            <td><?php echo sanitize($inv['order_number']); ?></td>
                            <td><?php echo date('d M Y', strtotime($inv['invoice_date'])); ?></td>
                            <td class="fw-semibold"><?php echo formatCurrency($inv['total']); ?></td>
                            <td class="text-success"><?php echo formatCurrency($inv['paid_amount']); ?></td>
                            <td class="text-danger"><?php echo formatCurrency($balance); ?></td>
                            <td><span class="badge bg-<?php echo $psClass; ?>"><?php echo ucfirst($inv['payment_status']); ?></span></td>
                            <td>
                                <a href="view.php?id=<?php echo $inv['id']; ?>" class="btn btn-action btn-outline-info me-1">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="print.php?id=<?php echo $inv['id']; ?>" class="btn btn-action btn-outline-secondary me-1" target="_blank">
                                    <i class="fas fa-print"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No invoices found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
