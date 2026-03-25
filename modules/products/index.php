<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$search = trim($_GET['search'] ?? '');
if ($search) {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 1 AND (p.name LIKE ? OR p.code LIKE ? OR p.material LIKE ?) ORDER BY p.created_at DESC");
    $stmt->bind_param('sss', $like, $like, $like);
    $stmt->execute();
    $products = $stmt->get_result();
} else {
    $products = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.status = 1 ORDER BY p.created_at DESC");
}

$pageTitle  = 'Products / Labels';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Products', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-tag me-2 text-primary"></i>Products / Labels</h4>
    <a href="create.php" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> Add Product
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form class="d-flex gap-2" method="GET">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:300px;"
                   placeholder="Search by name, code, material..." value="<?php echo sanitize($search); ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-search"></i></button>
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
                        <th>Code</th>
                        <th>Category</th>
                        <th>Size</th>
                        <th>Unit Price</th>
                        <th>Stock</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products && $products->num_rows > 0): ?>
                        <?php $i = 1; while ($p = $products->fetch_assoc()): ?>
                        <tr>
                            <td class="text-muted small"><?php echo $i++; ?></td>
                            <td><strong><?php echo sanitize($p['name']); ?></strong></td>
                            <td><span class="badge bg-secondary"><?php echo sanitize($p['code'] ?? '-'); ?></span></td>
                            <td><?php echo sanitize($p['category_name'] ?? '-'); ?></td>
                            <td><?php echo sanitize($p['size'] ?? '-'); ?></td>
                            <td><?php echo formatCurrency($p['price']); ?></td>
                            <td>
                                <?php
                                $stockClass = (int)$p['stock'] <= (int)$p['min_stock'] ? 'text-danger fw-bold' : 'text-success';
                                ?>
                                <span class="<?php echo $stockClass; ?>"><?php echo number_format($p['stock']); ?> <?php echo sanitize($p['unit']); ?></span>
                            </td>
                            <td>
                                <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-action btn-outline-primary me-1">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete.php?id=<?php echo $p['id']; ?>" class="btn btn-action btn-outline-danger"
                                   onclick="return confirm('Delete this product?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
