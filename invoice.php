<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_company_user();

$id   = (int)($_GET['id'] ?? 0);
$type = strtolower(trim($_GET['type'] ?? 'invoice'));

$cid = current_company_id();
$db  = db();

$brand = company_branding();
$currency = $brand['currency_symbol'] ?? currency_symbol();

function money_print($amount, $currency){
    return $currency . number_format((float)$amount, 2);
}

function logo_path($logo){
    if (!$logo) return '';
    if (preg_match('~^https?://~i', $logo)) return $logo;

    $try = [
        'uploads/' . ltrim($logo, '/'),
        'uploads/products/' . ltrim($logo, '/'),
        ltrim($logo, '/'),
    ];

    foreach ($try as $p) {
        if (file_exists(__DIR__ . '/' . $p)) return $p;
    }

    return '';
}

$isReceipt = ($type === 'receipt');

if ($isReceipt) {
    $doc = query_one("
        SELECT 
            s.*,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.email AS customer_email,
            b.name AS branch_name
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN branches b ON b.id = s.branch_id
        WHERE s.id = $id AND s.company_id = $cid
    ");

    if (!$doc) exit('Receipt not found.');

    $items = $db->query("
        SELECT product_name, qty, unit_price, line_total
        FROM sale_items
        WHERE sale_id = $id
        ORDER BY id ASC
    ")->fetch_all(MYSQLI_ASSOC);

    $docNo        = $doc['invoice_no'];
    $docDate      = $doc['sale_date'];
    $docTitle     = 'RECEIPT';
    $customerName = $doc['customer_name'] ?: 'Walk-in Customer';
    $customerPhone= $doc['customer_phone'] ?? '';
    $customerEmail= $doc['customer_email'] ?? '';
    $branchName   = $doc['branch_name'] ?? '';
    $subtotal     = (float)$doc['subtotal'];
    $tax          = (float)$doc['tax'];
    $total        = (float)$doc['total_amount'];
    $paidAmount   = (float)$doc['total_amount'];
    $dueAmount    = 0;
    $dueDate      = '';
    $notes        = $doc['notes'] ?? '';
} else {
    $hasDueDate = false;
    $hasPaidAmount = false;
    $hasDueAmount = false;

    $q1 = $db->query("SHOW COLUMNS FROM invoices LIKE 'due_date'");
    if ($q1 && $q1->num_rows > 0) $hasDueDate = true;

    $q2 = $db->query("SHOW COLUMNS FROM invoices LIKE 'paid_amount'");
    if ($q2 && $q2->num_rows > 0) $hasPaidAmount = true;

    $q3 = $db->query("SHOW COLUMNS FROM invoices LIKE 'due_amount'");
    if ($q3 && $q3->num_rows > 0) $hasDueAmount = true;

    $extra = $hasDueDate ? ", i.due_date" : ", '' AS due_date";
    $extra .= $hasPaidAmount ? ", i.paid_amount" : ", 0 AS paid_amount";
    $extra .= $hasDueAmount ? ", i.due_amount" : ", i.total_amount AS due_amount";

    $doc = $db->query("
        SELECT 
            i.*,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.email AS customer_email,
            b.name AS branch_name
            $extra
        FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id
        LEFT JOIN branches b ON b.id = i.branch_id
        WHERE i.id = ".(int)$id." AND i.company_id = ".(int)$cid."
        LIMIT 1
    ")->fetch_assoc();

    if (!$doc) exit('Invoice not found.');

    $items = $db->query("
        SELECT product_name, qty, selling_price AS unit_price, line_total
        FROM invoice_items
        WHERE invoice_id = ".(int)$id."
        ORDER BY id ASC
    ")->fetch_all(MYSQLI_ASSOC);

    $docNo        = $doc['invoice_no'];
    $docDate      = $doc['invoice_date'];
    $docTitle     = 'INVOICE';
    $customerName = $doc['customer_name'] ?: 'Unknown Customer';
    $customerPhone= $doc['customer_phone'] ?? '';
    $customerEmail= $doc['customer_email'] ?? '';
    $branchName   = $doc['branch_name'] ?? '';
    $subtotal     = (float)$doc['subtotal'];
    $tax          = (float)$doc['tax'];
    $total        = (float)$doc['total_amount'];
    $paidAmount   = (float)($doc['paid_amount'] ?? 0);
    $dueAmount    = (float)($doc['due_amount'] ?? $doc['total_amount']);
    $dueDate      = $doc['due_date'] ?? '';
    $notes        = $doc['notes'] ?? '';
}

$logo = logo_path($brand['logo'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($docTitle . ' ' . $docNo); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body{
        background:#f5f7fb;
        font-family:Arial, Helvetica, sans-serif;
        color:#222;
        padding:24px;
    }
    .toolbar{
        max-width:900px;
        margin:0 auto 16px auto;
        display:flex;
        justify-content:space-between;
        align-items:center;
    }
    .paper{
        max-width:900px;
        margin:auto;
        background:#fff;
        border:1px solid #dfe3ea;
        padding:32px;
    }
    .top{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:20px;
        margin-bottom:24px;
    }
    .brand{
        display:flex;
        gap:14px;
        align-items:flex-start;
    }
    .logo{
        width:70px;
        height:70px;
        object-fit:contain;
        border:1px solid #ddd;
        padding:4px;
        background:#fff;
    }
    .brand h2{
        margin:0 0 6px;
        font-size:26px;
        font-weight:700;
    }
    .muted{
        color:#666;
        font-size:14px;
    }
    .doc-title{
        text-align:right;
    }
    .doc-title h1{
        margin:0 0 6px;
        font-size:28px;
        font-weight:700;
    }
    .meta{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:18px;
        margin-bottom:24px;
    }
    .meta-box{
        border:1px solid #e5e7eb;
        padding:14px;
    }
    .meta-box h6{
        margin:0 0 10px;
        font-size:13px;
        color:#555;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    table{
        width:100%;
        border-collapse:collapse;
        margin-top:10px;
    }
    th, td{
        border:1px solid #dfe3ea;
        padding:10px;
        font-size:14px;
    }
    th{
        background:#f8fafc;
        text-align:left;
    }
    .right{
        text-align:right;
    }
    .totals{
        width:340px;
        margin-left:auto;
        margin-top:20px;
        border:1px solid #dfe3ea;
    }
    .totals-row{
        display:flex;
        justify-content:space-between;
        padding:10px 14px;
        border-bottom:1px solid #e5e7eb;
    }
    .totals-row:last-child{
        border-bottom:0;
    }
    .grand{
        font-weight:700;
        background:#f8fafc;
    }
    .notes{
        margin-top:20px;
        border:1px dashed #cbd5e1;
        padding:14px;
        background:#fafafa;
    }
    .footer{
        margin-top:28px;
        text-align:center;
        color:#666;
        font-size:13px;
    }
    .badge-paid,
    .badge-due{
        display:inline-block;
        padding:5px 10px;
        font-size:12px;
        font-weight:700;
        border:1px solid #ddd;
    }
    .badge-paid{
        background:#ecfdf5;
        color:#166534;
    }
    .badge-due{
        background:#fef2f2;
        color:#b91c1c;
    }
    @media print{
        body{
            background:#fff;
            padding:0;
        }
        .toolbar{
            display:none;
        }
        .paper{
            border:0;
            padding:0;
            max-width:none;
        }
    }
    @media (max-width: 700px){
        .top,
        .meta{
            grid-template-columns:1fr;
            display:block;
        }
        .doc-title{
            text-align:left;
            margin-top:14px;
        }
        .meta-box{
            margin-bottom:14px;
        }
        .totals{
            width:100%;
        }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="<?php echo $isReceipt ? 'sales.php' : 'invoices.php'; ?>" class="btn btn-outline-secondary">Back</a>
    <button onclick="window.print()" class="btn btn-primary">Print</button>
</div>

<div class="paper">
    <div class="top">
        <div class="brand">
            <?php if ($logo): ?>
                <img src="<?php echo e($logo); ?>" alt="Logo" class="logo">
            <?php endif; ?>

            <div>
                <h2><?php echo e($brand['name']); ?></h2>
                <?php if (!empty($brand['tagline'])): ?>
                    <div class="muted"><?php echo e($brand['tagline']); ?></div>
                <?php endif; ?>
                <?php if (!empty($brand['address'])): ?>
                    <div class="muted"><?php echo e($brand['address']); ?></div>
                <?php endif; ?>
                <?php if (!empty($brand['phone']) || !empty($brand['email'])): ?>
                    <div class="muted"><?php echo e(trim(($brand['phone'] ?? '') . '  ' . ($brand['email'] ?? ''))); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="doc-title">
            <h1><?php echo e($docTitle); ?></h1>
            <div class="muted"><?php echo e($docNo); ?></div>
        </div>
    </div>

    <div class="meta">
        <div class="meta-box">
            <h6>Customer</h6>
            <div><strong><?php echo e($customerName); ?></strong></div>
            <?php if ($customerPhone): ?><div><?php echo e($customerPhone); ?></div><?php endif; ?>
            <?php if ($customerEmail): ?><div><?php echo e($customerEmail); ?></div><?php endif; ?>
        </div>

        <div class="meta-box">
            <h6>Details</h6>
            <div><strong>Date:</strong> <?php echo e($docDate); ?></div>
            <div><strong>Branch:</strong> <?php echo e($branchName ?: 'Main'); ?></div>
            <div><strong>No:</strong> <?php echo e($docNo); ?></div>
            <?php if (!$isReceipt && $dueDate): ?>
                <div><strong>Due Date:</strong> <?php echo e($dueDate); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th width="60">#</th>
                <th>Description</th>
                <th width="100">Qty</th>
                <th width="140">Unit Price</th>
                <th width="150">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $i => $it): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo e($it['product_name']); ?></td>
                    <td class="right"><?php echo number_format((float)$it['qty'], 2); ?></td>
                    <td class="right"><?php echo money_print($it['unit_price'], $currency); ?></td>
                    <td class="right"><strong><?php echo money_print($it['line_total'], $currency); ?></strong></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$items): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted">No items found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="totals-row">
            <span>Subtotal</span>
            <strong><?php echo money_print($subtotal, $currency); ?></strong>
        </div>
        <div class="totals-row">
            <span>Tax</span>
            <strong><?php echo money_print($tax, $currency); ?></strong>
        </div>

        <?php if (!$isReceipt): ?>
            <div class="totals-row">
                <span>Paid Amount</span>
                <strong><?php echo money_print($paidAmount, $currency); ?></strong>
            </div>
            <div class="totals-row">
                <span>Due Amount</span>
                <strong><?php echo money_print($dueAmount, $currency); ?></strong>
            </div>
        <?php endif; ?>

        <div class="totals-row grand">
            <span><?php echo $isReceipt ? 'Paid Total' : 'Grand Total'; ?></span>
            <span><?php echo money_print($total, $currency); ?></span>
        </div>
    </div>

    <?php if (!$isReceipt): ?>
        <div style="margin-top:12px;">
            <?php if ($dueAmount <= 0): ?>
                <span class="badge-paid">Paid</span>
            <?php else: ?>
                <span class="badge-due">Due Pending</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($notes)): ?>
        <div class="notes">
            <strong>Notes:</strong><br>
            <?php echo nl2br(e($notes)); ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        <?php echo e($brand['invoice_footer']); ?>
    </div>
</div>

</body>
</html>