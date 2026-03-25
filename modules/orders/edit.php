<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { setFlash('error', 'Order not found.'); header('Location: index.php'); exit; }

$customers = $conn->query("SELECT id, name, company FROM customers WHERE status=1 ORDER BY name");
$products  = $conn->query("SELECT id, name, code, price, unit FROM products WHERE status=1 ORDER BY name");

$stmt2 = $conn->prepare("SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
$stmt2->bind_param('i', $id);
$stmt2->execute();
$existingItems = $stmt2->get_result();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $order_date    = trim($_POST['order_date'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $status        = trim($_POST['status'] ?? 'pending');
    $discount      = (float)($_POST['discount'] ?? 0);
    $tax_percent   = (float)($_POST['tax_percent'] ?? DEFAULT_TAX);
    $items         = $_POST['items'] ?? [];

    if (!$customer_id) $errors[] = 'Please select a customer.';
    if (!$order_date)  $errors[] = 'Order date is required.';

    $validItems = [];
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $validItems[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price, 'total' => $qty * $price];
        }
    }
    if (empty($validItems)) $errors[] = 'No valid items added.';

    if (empty($errors)) {
        $subtotal   = array_sum(array_column($validItems, 'total'));
        $tax_amount = ($subtotal - $discount) * ($tax_percent / 100);
        $total      = $subtotal - $discount + $tax_amount;

        $stmt = $conn->prepare("UPDATE orders SET customer_id=?,order_date=?,delivery_date=?,status=?,subtotal=?,discount=?,tax_percent=?,tax_amount=?,total=?,notes=? WHERE id=?");
        $delDate = $delivery_date ?: null;
        $stmt->bind_param('isssdddddsi',
            $customer_id, $order_date, $delDate, $status,
            $subtotal, $discount, $tax_percent, $tax_amount, $total, $notes, $id
        );
        if ($stmt->execute()) {
            $delItems = $conn->prepare("DELETE FROM order_items WHERE order_id = ?");
            $delItems->bind_param('i', $id);
            $delItems->execute();
            foreach ($validItems as $item) {
                $istmt = $conn->prepare("INSERT INTO order_items (order_id,product_id,quantity,unit_price,total) VALUES (?,?,?,?,?)");
                $istmt->bind_param('iiidd', $id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total']);
                $istmt->execute();
            }
            setFlash('success', 'Order updated successfully.');
            header("Location: view.php?id=$id");
            exit;
        }
        $errors[] = 'Failed to update order.';
    }
}

$pageTitle  = 'Edit Order: ' . $order['order_number'];
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => getBaseUrl() . '/dashboard.php'],
    ['label' => 'Orders', 'url' => 'index.php'],
    ['label' => 'Edit', 'url' => '#'],
];
include __DIR__ . '/../../includes/header.php';

// Build existing items JSON for JS
$itemsJson = [];
$existingItems->data_seek(0);
while ($ei = $existingItems->fetch_assoc()) {
    $itemsJson[] = ['product_id' => $ei['product_id'], 'quantity' => $ei['quantity'], 'unit_price' => $ei['unit_price'], 'total' => $ei['total']];
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-edit me-2 text-primary"></i>Edit Order</h4>
    <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . sanitize($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST" id="orderForm">
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Order Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Customer <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required>
                            <?php while ($c = $customers->fetch_assoc()): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $order['customer_id'] == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize($c['name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label>
                        <input type="date" name="order_date" class="form-control" value="<?php echo $order['order_date']; ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Delivery Date</label>
                        <input type="date" name="delivery_date" class="form-control" value="<?php echo $order['delivery_date'] ?? ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['pending','processing','completed','cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $order['status'] === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Order Items</span>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()"><i class="fas fa-plus me-1"></i>Add</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr><th>Product</th><th width="100">Qty</th><th width="120">Price</th><th width="120">Total</th><th width="50"></th></tr>
                        </thead>
                        <tbody id="itemsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?php echo sanitize($order['notes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm sticky-top" style="top:80px">
            <div class="card-header bg-white fw-semibold">Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span>Subtotal</span><span id="summSubtotal">₹0.00</span></div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Discount (₹)</label>
                    <input type="number" name="discount" id="discount" class="form-control form-control-sm" value="<?php echo $order['discount']; ?>" min="0" step="0.01" oninput="recalc()">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">GST (%)</label>
                    <input type="number" name="tax_percent" id="taxPercent" class="form-control form-control-sm" value="<?php echo $order['tax_percent']; ?>" min="0" step="0.01" oninput="recalc()">
                </div>
                <div class="d-flex justify-content-between mb-2"><span>Tax</span><span id="summTax">₹0.00</span></div>
                <hr>
                <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span id="summTotal">₹0.00</span></div>
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Update Order</button>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
var products = <?php
$products->data_seek(0);
$pArr = [];
while ($p = $products->fetch_assoc()) {
    $pArr[] = ['id'=>$p['id'],'name'=>$p['name'],'code'=>$p['code'],'price'=>(float)$p['price'],'unit'=>$p['unit']];
}
echo json_encode($pArr);
?>;
var existingItems = <?php echo json_encode($itemsJson); ?>;
var itemCount = 0;

function addItem(pid, qty, price) {
    var opts = products.map(function(p) {
        var sel = (pid && p.id == pid) ? ' selected' : '';
        return '<option value="' + p.id + '" data-price="' + p.price + '"' + sel + '>' + p.name + (p.code ? ' [' + p.code + ']' : '') + '</option>';
    }).join('');
    var idx = itemCount++;
    qty   = qty   || 1;
    price = price || 0;
    var tot = qty * price;
    var row = '<tr id="item-' + idx + '">' +
        '<td><select name="items[' + idx + '][product_id]" class="form-select form-select-sm" onchange="productSelected(this,' + idx + ')" required><option value="">-- Select --</option>' + opts + '</select></td>' +
        '<td><input type="number" name="items[' + idx + '][quantity]" id="qty-' + idx + '" class="form-control form-control-sm" value="' + qty + '" min="1" oninput="lineTotal(' + idx + ')"></td>' +
        '<td><input type="number" name="items[' + idx + '][unit_price]" id="price-' + idx + '" class="form-control form-control-sm" value="' + price.toFixed(2) + '" min="0" step="0.01" oninput="lineTotal(' + idx + ')"></td>' +
        '<td><input type="number" name="items[' + idx + '][total]" id="total-' + idx + '" class="form-control form-control-sm" value="' + tot.toFixed(2) + '" readonly></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(' + idx + ')"><i class="fas fa-times"></i></button></td>' +
        '</tr>';
    document.getElementById('itemsBody').insertAdjacentHTML('beforeend', row);
    recalc();
}
function productSelected(sel, idx) {
    var price = parseFloat(sel.options[sel.selectedIndex].getAttribute('data-price') || 0);
    document.getElementById('price-' + idx).value = price.toFixed(2);
    lineTotal(idx);
}
function lineTotal(idx) {
    var qty   = parseFloat(document.getElementById('qty-' + idx).value || 0);
    var price = parseFloat(document.getElementById('price-' + idx).value || 0);
    document.getElementById('total-' + idx).value = (qty * price).toFixed(2);
    recalc();
}
function removeItem(idx) {
    var r = document.getElementById('item-' + idx);
    if (r) r.remove();
    recalc();
}
function recalc() {
    var subtotal = 0;
    document.querySelectorAll('[id^="total-"]').forEach(function(el) { subtotal += parseFloat(el.value || 0); });
    var discount   = parseFloat(document.getElementById('discount').value || 0);
    var taxPercent = parseFloat(document.getElementById('taxPercent').value || 0);
    var taxable    = Math.max(0, subtotal - discount);
    var tax        = taxable * (taxPercent / 100);
    document.getElementById('summSubtotal').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('summTax').textContent      = '₹' + tax.toFixed(2);
    document.getElementById('summTotal').textContent    = '₹' + (taxable + tax).toFixed(2);
}
// Load existing items
existingItems.forEach(function(item) { addItem(item.product_id, item.quantity, item.unit_price); });
if (existingItems.length === 0) addItem();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
