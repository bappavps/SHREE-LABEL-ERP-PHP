<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$conn = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("
    SELECT i.*, c.name as customer_name, c.company, c.email as customer_email,
           c.phone as customer_phone, c.address, c.city, c.state, c.pincode, c.gstin,
           o.order_number, o.order_date
    FROM invoices i
    JOIN customers c ON i.customer_id = c.id
    JOIN orders o ON i.order_id = o.id
    WHERE i.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
if (!$invoice) { header('Location: index.php'); exit; }

$orderId = (int)$invoice['order_id'];
$stmt2 = $conn->prepare("
    SELECT oi.*, p.name as product_name, p.code, p.unit
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$stmt2->bind_param('i', $orderId);
$stmt2->execute();
$items = $stmt2->get_result();

$balance = $invoice['total'] - $invoice['paid_amount'];
$psLabel = ['unpaid' => 'UNPAID', 'partial' => 'PARTIALLY PAID', 'paid' => 'PAID'][$invoice['payment_status']] ?? 'UNPAID';
$psColor = ['unpaid' => '#dc3545', 'partial' => '#fd7e14', 'paid' => '#198754'][$invoice['payment_status']] ?? '#dc3545';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?php echo sanitize($invoice['invoice_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 13px; color: #333; background: #fff; }
        .invoice-wrapper { max-width: 800px; margin: 20px auto; padding: 30px; border: 1px solid #ddd; }
        .header { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 2px solid #1a3c6e; padding-bottom: 20px; }
        .company-name { font-size: 24px; font-weight: bold; color: #1a3c6e; }
        .company-sub { color: #666; font-size: 12px; margin-top: 4px; }
        .invoice-title { text-align: right; }
        .invoice-title h2 { font-size: 28px; color: #1a3c6e; text-transform: uppercase; }
        .invoice-title .inv-num { font-size: 14px; color: #666; }
        .status-stamp {
            position: absolute;
            top: 120px; right: 40px;
            border: 3px solid;
            padding: 4px 12px;
            font-size: 16px;
            font-weight: bold;
            transform: rotate(-20deg);
            opacity: 0.6;
            border-radius: 4px;
        }
        .bill-section { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .bill-box h4 { font-size: 11px; text-transform: uppercase; color: #999; margin-bottom: 6px; }
        .bill-box p { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead th { background: #1a3c6e; color: #fff; padding: 8px 10px; text-align: left; font-size: 12px; }
        tbody td { padding: 8px 10px; border-bottom: 1px solid #eee; }
        tbody tr:hover { background: #f9f9f9; }
        .text-right { text-align: right; }
        .totals { width: 280px; margin-left: auto; }
        .totals td { padding: 5px 10px; }
        .totals .grand-total { font-size: 16px; font-weight: bold; background: #f4f6f9; }
        .footer { margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; font-size: 11px; color: #888; text-align: center; }
        .bank-details { margin-top: 20px; padding: 12px; background: #f8f9fa; border-radius: 6px; font-size: 12px; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
            .invoice-wrapper { border: none; margin: 0; }
        }
    </style>
</head>
<body>
<div class="no-print" style="text-align:center; padding:10px; background:#f0f0f0;">
    <button onclick="window.print()" style="padding:8px 20px; background:#1a3c6e; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:14px;">
        🖨 Print Invoice
    </button>
    <a href="view.php?id=<?php echo $id; ?>" style="margin-left:10px; padding:8px 20px; background:#6c757d; color:#fff; border-radius:4px; text-decoration:none; font-size:14px;">
        ← Back
    </a>
</div>

<div class="invoice-wrapper" style="position:relative;">
    <div class="status-stamp" style="color:<?php echo $psColor; ?>; border-color:<?php echo $psColor; ?>;">
        <?php echo $psLabel; ?>
    </div>

    <div class="header">
        <div>
            <div class="company-name"><i>🏷 </i><?php echo sanitize(COMPANY_NAME); ?></div>
            <div class="company-sub">
                <?php echo sanitize(COMPANY_ADDRESS); ?><br>
                Phone: <?php echo sanitize(COMPANY_PHONE); ?> | Email: <?php echo sanitize(COMPANY_EMAIL); ?><br>
                GSTIN: <?php echo sanitize(COMPANY_GSTIN); ?>
            </div>
        </div>
        <div class="invoice-title">
            <h2>Tax Invoice</h2>
            <div class="inv-num"><?php echo sanitize($invoice['invoice_number']); ?></div>
            <div style="font-size:12px; color:#666; margin-top:4px;">
                Date: <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?><br>
                <?php if ($invoice['due_date']): ?>
                Due: <?php echo date('d M Y', strtotime($invoice['due_date'])); ?><br>
                <?php endif; ?>
                Order: <?php echo sanitize($invoice['order_number']); ?>
            </div>
        </div>
    </div>

    <div class="bill-section">
        <div class="bill-box">
            <h4>Bill To</h4>
            <p><strong><?php echo sanitize($invoice['customer_name']); ?></strong></p>
            <?php if ($invoice['company']): ?><p><?php echo sanitize($invoice['company']); ?></p><?php endif; ?>
            <?php if ($invoice['address']): ?><p><?php echo sanitize($invoice['address']); ?></p><?php endif; ?>
            <?php if ($invoice['city']): ?><p><?php echo sanitize($invoice['city'] . ', ' . ($invoice['state'] ?? '') . ' - ' . ($invoice['pincode'] ?? '')); ?></p><?php endif; ?>
            <?php if ($invoice['customer_phone']): ?><p>Ph: <?php echo sanitize($invoice['customer_phone']); ?></p><?php endif; ?>
            <?php if ($invoice['gstin']): ?><p>GSTIN: <?php echo sanitize($invoice['gstin']); ?></p><?php endif; ?>
        </div>
        <div class="bill-box" style="text-align:right;">
            <h4>Invoice From</h4>
            <p><strong><?php echo sanitize(COMPANY_NAME); ?></strong></p>
            <p><?php echo sanitize(COMPANY_ADDRESS); ?></p>
            <p>GSTIN: <?php echo sanitize(COMPANY_GSTIN); ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Product / Description</th>
                <th>Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; while ($item = $items->fetch_assoc()): ?>
            <tr>
                <td><?php echo $i++; ?></td>
                <td>
                    <?php echo sanitize($item['product_name']); ?>
                    <?php if ($item['code']): ?> <small>(<?php echo sanitize($item['code']); ?>)</small><?php endif; ?>
                </td>
                <td><?php echo number_format($item['quantity']); ?> <?php echo sanitize($item['unit']); ?></td>
                <td class="text-right"><?php echo formatCurrency($item['unit_price']); ?></td>
                <td class="text-right"><?php echo formatCurrency($item['total']); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="text-right"><?php echo formatCurrency($invoice['subtotal']); ?></td></tr>
        <?php if ($invoice['discount'] > 0): ?>
        <tr><td>Discount</td><td class="text-right" style="color:#dc3545;">- <?php echo formatCurrency($invoice['discount']); ?></td></tr>
        <?php endif; ?>
        <tr><td>GST</td><td class="text-right"><?php echo formatCurrency($invoice['tax_amount']); ?></td></tr>
        <tr class="grand-total"><td><strong>Total</strong></td><td class="text-right"><strong><?php echo formatCurrency($invoice['total']); ?></strong></td></tr>
        <tr style="color:#198754;"><td>Paid</td><td class="text-right"><?php echo formatCurrency($invoice['paid_amount']); ?></td></tr>
        <tr style="color:#dc3545; font-weight:bold;"><td>Balance Due</td><td class="text-right"><?php echo formatCurrency($balance); ?></td></tr>
    </table>

    <?php if ($invoice['notes']): ?>
    <div style="margin-top:10px; padding:10px; background:#fffdf0; border-left:3px solid #ffc107; font-size:12px;">
        <strong>Notes:</strong> <?php echo sanitize($invoice['notes']); ?>
    </div>
    <?php endif; ?>

    <div class="bank-details">
        <strong>Payment Details:</strong><br>
        Bank: State Bank of India | Account: XXXX XXXX 1234 | IFSC: SBIN0001234<br>
        UPI: <?php echo sanitize(COMPANY_EMAIL); ?>
    </div>

    <div class="footer">
        <p>Thank you for your business! | <?php echo sanitize(COMPANY_NAME); ?> | <?php echo sanitize(COMPANY_EMAIL); ?> | <?php echo sanitize(COMPANY_PHONE); ?></p>
        <p style="margin-top:4px;">This is a computer generated invoice.</p>
    </div>
</div>
</body>
</html>
