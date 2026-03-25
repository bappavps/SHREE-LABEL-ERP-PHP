<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$conn = db();

// Stats
$totalCustomers = $conn->query("SELECT COUNT(*) as cnt FROM customers WHERE status=1")->fetch_assoc()['cnt'];
$totalProducts  = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE status=1")->fetch_assoc()['cnt'];
$totalOrders    = $conn->query("SELECT COUNT(*) as cnt FROM orders")->fetch_assoc()['cnt'];
$pendingOrders  = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='pending'")->fetch_assoc()['cnt'];
$totalRevenue   = $conn->query("SELECT COALESCE(SUM(total),0) as rev FROM invoices WHERE payment_status='paid'")->fetch_assoc()['rev'];
$unpaidAmount   = $conn->query("SELECT COALESCE(SUM(total - paid_amount),0) as amt FROM invoices WHERE payment_status IN ('unpaid','partial')")->fetch_assoc()['amt'];

// Recent orders
$recentOrders = $conn->query("
    SELECT o.id, o.order_number, o.order_date, o.status, o.total,
           c.name as customer_name
    FROM orders o
    JOIN customers c ON o.customer_id = c.id
    ORDER BY o.created_at DESC LIMIT 8
");

// Monthly orders chart data
$monthlyData = $conn->query("
    SELECT DATE_FORMAT(order_date,'%b') as month,
           COUNT(*) as cnt,
           SUM(total) as rev
    FROM orders
    WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(order_date,'%Y-%m')
    ORDER BY order_date ASC
");
$chartLabels = $chartOrders = $chartRevenue = [];
while ($row = $monthlyData->fetch_assoc()) {
    $chartLabels[]  = $row['month'];
    $chartOrders[]  = (int)$row['cnt'];
    $chartRevenue[] = (float)$row['rev'];
}

$pageTitle = 'Dashboard';
$breadcrumbs = [['label' => 'Dashboard', 'url' => '#']];
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Dashboard</h4>
        <p class="text-muted small mb-0">Welcome back, <?php echo sanitize($_SESSION['user_name'] ?? 'Admin'); ?>!</p>
    </div>
    <div class="text-muted small"><?php echo date('l, d F Y'); ?></div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo number_format($totalOrders); ?></div>
                    <div class="text-muted small">Total Orders</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo number_format($pendingOrders); ?></div>
                    <div class="text-muted small">Pending Orders</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-success bg-opacity-10 text-success">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo formatCurrency($totalRevenue); ?></div>
                    <div class="text-muted small">Total Revenue</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-danger bg-opacity-10 text-danger">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo formatCurrency($unpaidAmount); ?></div>
                    <div class="text-muted small">Outstanding</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-info bg-opacity-10 text-info">
                    <i class="fas fa-users"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo number_format($totalCustomers); ?></div>
                    <div class="text-muted small">Customers</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box" style="background:rgba(232,80,26,0.1);color:#e8501a;">
                    <i class="fas fa-tag"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?php echo number_format($totalProducts); ?></div>
                    <div class="text-muted small">Products / Labels</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div>
                    <div class="text-muted small mb-1">Quick Actions</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?php echo getBaseUrl(); ?>/modules/orders/create.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i>New Order
                        </a>
                        <a href="<?php echo getBaseUrl(); ?>/modules/customers/create.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-user-plus me-1"></i>Add Customer
                        </a>
                        <a href="<?php echo getBaseUrl(); ?>/modules/products/create.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-plus me-1"></i>Add Product
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Chart -->
    <div class="col-xl-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Orders & Revenue
            </div>
            <div class="card-body">
                <canvas id="ordersChart" height="100"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="col-xl-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="fas fa-list me-2 text-primary"></i>Recent Orders</span>
                <a href="<?php echo getBaseUrl(); ?>/modules/orders/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
                        <?php while ($o = $recentOrders->fetch_assoc()): ?>
                        <a href="<?php echo getBaseUrl(); ?>/modules/orders/view.php?id=<?php echo $o['id']; ?>"
                           class="list-group-item list-group-item-action px-3 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold small"><?php echo sanitize($o['order_number']); ?></div>
                                    <div class="text-muted" style="font-size:0.78rem;"><?php echo sanitize($o['customer_name']); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="small fw-semibold"><?php echo formatCurrency($o['total']); ?></div>
                                    <span class="badge badge-status-<?php echo $o['status']; ?>" style="font-size:0.7rem;">
                                        <?php echo ucfirst($o['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted small">No orders yet</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
<script>
var ctx = document.getElementById("ordersChart").getContext("2d");
new Chart(ctx, {
    type: "bar",
    data: {
        labels: ' . json_encode($chartLabels) . ',
        datasets: [{
            label: "Orders",
            data: ' . json_encode($chartOrders) . ',
            backgroundColor: "rgba(26,60,110,0.8)",
            borderRadius: 6,
            yAxisID: "y"
        }, {
            label: "Revenue (₹)",
            data: ' . json_encode($chartRevenue) . ',
            type: "line",
            borderColor: "#e8501a",
            backgroundColor: "rgba(232,80,26,0.1)",
            fill: true,
            tension: 0.4,
            yAxisID: "y1"
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: "top" } },
        scales: {
            y: { type: "linear", position: "left", title: { display: true, text: "Orders" } },
            y1: { type: "linear", position: "right", title: { display: true, text: "Revenue (₹)" }, grid: { drawOnChartArea: false } }
        }
    }
});
</script>';
include __DIR__ . '/includes/footer.php';
?>
