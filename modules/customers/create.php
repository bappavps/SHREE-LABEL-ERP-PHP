<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$errors = [];
$data = ['name'=>'','company'=>'','email'=>'','phone'=>'','address'=>'','city'=>'','state'=>'','pincode'=>'','gstin'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['name']    = trim($_POST['name'] ?? '');
    $data['company'] = trim($_POST['company'] ?? '');
    $data['email']   = trim($_POST['email'] ?? '');
    $data['phone']   = trim($_POST['phone'] ?? '');
    $data['address'] = trim($_POST['address'] ?? '');
    $data['city']    = trim($_POST['city'] ?? '');
    $data['state']   = trim($_POST['state'] ?? '');
    $data['pincode'] = trim($_POST['pincode'] ?? '');
    $data['gstin']   = trim($_POST['gstin'] ?? '');

    if (empty($data['name']))  $errors[] = 'Customer name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        $conn = db();
        $stmt = $conn->prepare("INSERT INTO customers (name,company,email,phone,address,city,state,pincode,gstin) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssss',
            $data['name'], $data['company'], $data['email'], $data['phone'],
            $data['address'], $data['city'], $data['state'], $data['pincode'], $data['gstin']
        );
        if ($stmt->execute()) {
            setFlash('success', 'Customer added successfully.');
            header('Location: index.php');
            exit;
        }
        $errors[] = 'Failed to add customer. Please try again.';
    }
}

$pageTitle  = 'Add Customer';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Customers', 'url' => 'index.php'],
    ['label' => 'Add Customer', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-user-plus me-2 text-primary"></i>Add Customer</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . sanitize($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?php echo sanitize($data['name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Company</label>
                    <input type="text" name="company" class="form-control" value="<?php echo sanitize($data['company']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo sanitize($data['email']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo sanitize($data['phone']); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?php echo sanitize($data['address']); ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">City</label>
                    <input type="text" name="city" class="form-control" value="<?php echo sanitize($data['city']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">State</label>
                    <input type="text" name="state" class="form-control" value="<?php echo sanitize($data['state']); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Pincode</label>
                    <input type="text" name="pincode" class="form-control" value="<?php echo sanitize($data['pincode']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">GSTIN</label>
                    <input type="text" name="gstin" class="form-control" value="<?php echo sanitize($data['gstin']); ?>" maxlength="15">
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i> Save Customer
                </button>
                <a href="index.php" class="btn btn-outline-secondary px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
