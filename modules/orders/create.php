<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$customers = $conn->query("SELECT id, name, company FROM customers WHERE status=1 ORDER BY name");
$products  = $conn->query("SELECT id, name, code, price, unit FROM products WHERE status=1 ORDER BY name");

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $order_date    = trim($_POST['order_date'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $discount      = (float)($_POST['discount'] ?? 0);
    $tax_percent   = (float)($_POST['tax_percent'] ?? DEFAULT_TAX);
    $items         = $_POST['items'] ?? [];

    if (!$customer_id)  $errors[] = 'Please select a customer.';
    if (!$order_date)   $errors[] = 'Order date is required.';
    if (empty($items))  $errors[] = 'Please add at least one product.';

    $validItems = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        if ($pid > 0 && $qty > 0 && $price >= 0) {
            $validItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'total' => $qty * $price];
        }
    }
    if (empty($validItems)) $errors[] = 'No valid items added.';

    if (empty($errors)) {
        $subtotal   = array_sum(array_column($validItems, 'total'));
        $tax_amount = ($subtotal - $discount) * ($tax_percent / 100);
        $total      = $subtotal - $discount + $tax_amount;
        $orderNum   = generateOrderNumber();
        $delDate    = $delivery_date ?: null;
        $status     = 'pending';

        $stmt = $conn->prepare("INSERT INTO orders (order_number,customer_id,order_date,delivery_date,status,subtotal,discount,tax_percent,tax_amount,total,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sisssddddds',
            $orderNum, $customer_id, $order_date, $delDate, $status,
            $subtotal, $discount, $tax_percent, $tax_amount, $total, $notes
        );
        if ($stmt->execute()) {
            $orderId = $conn->insert_id;
            foreach ($validItems as $item) {
                $istmt = $conn->prepare("INSERT INTO order_items (order_id,product_id,quantity,unit_price,total) VALUES (?,?,?,?,?)");
                $istmt->bind_param('iiidd', $orderId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total']);
                $istmt->execute();
            }
            setFlash('success', "Order $orderNum created successfully.");
            header("Location: view.php?id=$orderId");
            exit;
        }
        $errors[] = 'Failed to create order: ' . $conn->error;
    }
}

$pageTitle  = 'New Order';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Orders', 'url' => 'index.php'],
    ['label' => 'New Order', 'url' => '#'],
];
$preCustomer = (int)($_GET['customer_id'] ?? 0);
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-plus me-2 text-primary"></i>New Order</h4>
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . sanitize($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST" id="orderForm">
<div class="row g-3">
    <!-- Order details -->
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Order Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Select Customer --</option>
                            <?php while ($c = $customers->fetch_assoc()): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($preCustomer == $c['id'] || ($_POST['customer_id'] ?? 0) == $c['id']) ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?><?php echo $c['company'] ? ' (' . sanitize($c['company']) . ')' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label>
                        <input type="date" name="order_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['order_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Delivery Date</label>
                        <input type="date" name="delivery_date" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Order Items -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Order Items</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()">
                    <i class="fas fa-plus me-1"></i> Add Item
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th width="100">Qty</th>
                                <th width="120">Unit Price</th>
                                <th width="120">Total</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?php echo sanitize($_POST['notes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Order Summary -->
    <div class="col-lg-4">
        <div class="card shadow-sm sticky-top" style="top:80px">
            <div class="card-header bg-white fw-semibold">Order Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal</span>
                    <span id="summSubtotal">₹0.00</span>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Discount (₹)</label>
                    <input type="number" name="discount" id="discount" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($_POST['discount'] ?? '0'); ?>" min="0" step="0.01" oninput="recalc()">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">GST (%)</label>
                    <input type="number" name="tax_percent" id="taxPercent" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($_POST['tax_percent'] ?? DEFAULT_TAX); ?>" min="0" step="0.01" oninput="recalc()">
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Tax Amount</span>
                    <span id="summTax">₹0.00</span>
                </div>
                <hr>
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Total</span>
                    <span id="summTotal">₹0.00</span>
                </div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save me-1"></i> Create Order
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<!-- Product data for JS -->
<script>
var products = <?php
$products->data_seek(0);
$pArr = [];
while ($p = $products->fetch_assoc()) {
    $pArr[] = ['id'=>$p['id'],'name'=>$p['name'],'code'=>$p['code'],'price'=>(float)$p['price'],'unit'=>$p['unit']];
}
echo json_encode($pArr);
?>;

var itemCount = 0;

function addItem() {
    var opts = products.map(function(p) {
        return '<option value="' + p.id + '" data-price="' + p.price + '">' + p.name + (p.code ? ' [' + p.code + ']' : '') + '</option>';
    }).join('');

    var idx = itemCount++;
    var row = '<tr id="item-' + idx + '">' +
        '<td><select name="items[' + idx + '][product_id]" class="form-select form-select-sm" onchange="productSelected(this,' + idx + ')" required>' +
        '<option value="">-- Select --</option>' + opts + '</select></td>' +
        '<td><input type="number" name="items[' + idx + '][quantity]" id="qty-' + idx + '" class="form-control form-control-sm" value="1" min="1" oninput="lineTotal(' + idx + ')"></td>' +
        '<td><input type="number" name="items[' + idx + '][unit_price]" id="price-' + idx + '" class="form-control form-control-sm" value="0" min="0" step="0.01" oninput="lineTotal(' + idx + ')"></td>' +
        '<td><input type="number" name="items[' + idx + '][total]" id="total-' + idx + '" class="form-control form-control-sm" value="0" readonly></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(' + idx + ')"><i class="fas fa-times"></i></button></td>' +
        '</tr>';
    document.getElementById('itemsBody').insertAdjacentHTML('beforeend', row);
}

function productSelected(sel, idx) {
    var opt = sel.options[sel.selectedIndex];
    var price = parseFloat(opt.getAttribute('data-price') || 0);
    document.getElementById('price-' + idx).value = price.toFixed(2);
    lineTotal(idx);
}

function lineTotal(idx) {
    var qty   = parseFloat(document.getElementById('qty-' + idx).value || 0);
    var price = parseFloat(document.getElementById('price-' + idx).value || 0);
    var tot   = qty * price;
    document.getElementById('total-' + idx).value = tot.toFixed(2);
    recalc();
}

function removeItem(idx) {
    var row = document.getElementById('item-' + idx);
    if (row) row.remove();
    recalc();
}

function recalc() {
    var subtotal = 0;
    document.querySelectorAll('[id^="total-"]').forEach(function(el) {
        subtotal += parseFloat(el.value || 0);
    });
    var discount   = parseFloat(document.getElementById('discount').value || 0);
    var taxPercent = parseFloat(document.getElementById('taxPercent').value || 0);
    var taxable    = Math.max(0, subtotal - discount);
    var tax        = taxable * (taxPercent / 100);
    var total      = taxable + tax;
    document.getElementById('summSubtotal').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('summTax').textContent      = '₹' + tax.toFixed(2);
    document.getElementById('summTotal').textContent    = '₹' + total.toFixed(2);
}

// Add one empty row by default
addItem();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
