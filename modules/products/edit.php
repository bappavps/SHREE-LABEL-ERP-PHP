<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$row = $conn->query("SELECT * FROM products WHERE id = $id AND status = 1")->fetch_assoc();
if (!$row) { setFlash('error', 'Product not found.'); header('Location: index.php'); exit; }

$categories = $conn->query("SELECT id, name FROM categories WHERE status=1 ORDER BY name");
$errors = [];
$data = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['name']        = trim($_POST['name'] ?? '');
    $data['code']        = trim($_POST['code'] ?? '');
    $data['category_id'] = (int)($_POST['category_id'] ?? 0);
    $data['description'] = trim($_POST['description'] ?? '');
    $data['unit']        = trim($_POST['unit'] ?? 'PCS');
    $data['size']        = trim($_POST['size'] ?? '');
    $data['material']    = trim($_POST['material'] ?? '');
    $data['price']       = (float)($_POST['price'] ?? 0);
    $data['stock']       = (int)($_POST['stock'] ?? 0);
    $data['min_stock']   = (int)($_POST['min_stock'] ?? 0);

    if (empty($data['name'])) $errors[] = 'Product name is required.';

    if (empty($errors)) {
        $catId = $data['category_id'] ?: null;
        $stmt = $conn->prepare("UPDATE products SET name=?,code=?,category_id=?,description=?,unit=?,size=?,material=?,price=?,stock=?,min_stock=? WHERE id=?");
        $stmt->bind_param('ssissssdiii',
            $data['name'], $data['code'], $catId, $data['description'],
            $data['unit'], $data['size'], $data['material'], $data['price'],
            $data['stock'], $data['min_stock'], $id
        );
        if ($stmt->execute()) {
            setFlash('success', 'Product updated successfully.');
            header('Location: index.php');
            exit;
        }
        $errors[] = 'Failed to update product.';
    }
}

$pageTitle  = 'Edit Product';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Products', 'url' => 'index.php'],
    ['label' => 'Edit', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-edit me-2 text-primary"></i>Edit Product</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . sanitize($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo sanitize($data['name']); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Product Code</label>
                    <input type="text" name="code" class="form-control" value="<?php echo sanitize($data['code']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">-- Select --</option>
                        <?php while ($cat = $categories->fetch_assoc()): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $data['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($cat['name']); ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?php echo sanitize($data['description']); ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Unit</label>
                    <select name="unit" class="form-select">
                        <?php foreach (['PCS','ROLL','MTR','KG','BOX','SHEET'] as $u): ?>
                        <option value="<?php echo $u; ?>" <?php echo $data['unit'] === $u ? 'selected' : ''; ?>><?php echo $u; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Size</label>
                    <input type="text" name="size" class="form-control" value="<?php echo sanitize($data['size']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Material</label>
                    <input type="text" name="material" class="form-control" value="<?php echo sanitize($data['material']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Unit Price (₹)</label>
                    <input type="number" name="price" class="form-control" value="<?php echo $data['price']; ?>" step="0.01" min="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Current Stock</label>
                    <input type="number" name="stock" class="form-control" value="<?php echo $data['stock']; ?>" min="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Min Stock Alert</label>
                    <input type="number" name="min_stock" class="form-control" value="<?php echo $data['min_stock']; ?>" min="0">
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i> Update Product
                </button>
                <a href="index.php" class="btn btn-outline-secondary px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
