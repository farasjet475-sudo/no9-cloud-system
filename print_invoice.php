<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (is_super_admin()) {
    redirect('dashboard.php');
}

if (empty($_SESSION['pos_print_invoice']) || !is_array($_SESSION['pos_print_invoice'])) {
    flash('error', 'No invoice data found.');
    redirect('pos.php');
}

$doc = $_SESSION['pos_print_invoice'];
$branding = company_branding();
$currency = $branding['currency_symbol'] ?? '$';

function doc_totals(array $items, float $tax = 0): array {
    $subtotal = 0;
    $discount = 0;
    foreach ($items as $item) {
        $subtotal += (float)($item['line_total'] ?? 0);
        $discount += (float)($item['discount'] ?? 0);
    }
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax'      => $tax,
        'total'    => $subtotal + $tax,
    ];
}

function branding_logo_url($logo) {
    $logo = trim((string)$logo);
    if ($logo === '') return '';

    $candidates = [
        'uploads/products/' . $logo,
        'uploads/' . $logo,
        $logo,
    ];

    foreach ($candidates as $path) {
        $full = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($full)) return $path;
    }

    return $logo;
}

$logoUrl = branding_logo_url($branding['logo'] ?? '');
$totals = doc_totals($doc['items'] ?? [], (float)($doc['tax'] ?? 0));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice - <?php echo e($doc['doc_no'] ?? ''); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a}
.sheet{max-width:980px;margin:0 auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 14px 35px rgba(15,23,42,.10)}
.topbar{height:8px;background:linear-gradient(90deg,#2563eb,#3b82f6,#7c3aed)}
.inner{padding:30px}
.header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:18px;border-bottom:1px solid #e2e8f0}
.brand{display:flex;gap:14px;align-items:flex-start}
.brand img{width:72px;height:72px;object-fit:contain;border-radius:12px;border:1px solid #e2e8f0;background:#fff;padding:6px}
.brand h1{margin:0 0 6px;font-size:28px}
.muted{color:#64748b;font-size:14px;line-height:1.55}
.badge{display:inline-block;padding:10px 16px;border-radius:999px;font-weight:800;font-size:13px;color:#fff;background:linear-gradient(135deg,#2563eb,#1d4ed8);text-transform:uppercase;letter-spacing:.5px}
.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:22px 0}
.meta-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px}
.meta-card h4{margin:0 0 10px;font-size:13px;color:#475569;text-transform:uppercase;letter-spacing:.4px}
.meta-card div{margin:5px 0;font-size:14px}
table{width:100%;border-collapse:collapse;margin-top:14px}
thead th{background:#0f172a;color:#fff;font-size:13px;padding:12px 10px;text-align:left}
tbody td{border-bottom:1px solid #e2e8f0;padding:12px 10px;font-size:14px;vertical-align:top}
.right{text-align:right}
.totals{width:340px;margin-left:auto;margin-top:22px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px 18px}
.totals .row{display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px dashed #cbd5e1}
.totals .row:last-child{border-bottom:0}
.totals .grand{font-size:18px;font-weight:800}
.notes{margin-top:26px;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px}
.footer{margin-top:26px;padding-top:18px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:20px;color:#64748b;font-size:13px}
.actions{max-width:980px;margin:18px auto 0;display:flex;gap:12px}
.btn{border:0;border-radius:14px;padding:12px 18px;font-weight:700;cursor:pointer;color:#fff;text-decoration:none}
.btn-print{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.btn-back{background:linear-gradient(135deg,#64748b,#475569)}
@media (max-width:700px){
    body{padding:10px}
    .header{flex-direction:column}
    .meta{grid-template-columns:1fr}
    .totals{width:100%}
    .footer{flex-direction:column}
}
@media print{
    body{background:#fff;padding:0}
    .sheet{box-shadow:none;border-radius:0;max-width:none}
    .actions{display:none}
}
</style>
</head>
<body>
<div class="sheet">
    <div class="topbar"></div>
    <div class="inner">
        <div class="header">
            <div class="brand">
                <?php if ($logoUrl): ?>
                    <img src="<?php echo e($logoUrl); ?>" alt="Logo">
                <?php endif; ?>
                <div>
                    <h1><?php echo e($branding['name'] ?? 'Company'); ?></h1>
                    <div class="muted">
                        <?php if (!empty($branding['address'])): ?><div><?php echo e($branding['address']); ?></div><?php endif; ?>
                        <?php if (!empty($branding['phone'])): ?><div><?php echo e($branding['phone']); ?></div><?php endif; ?>
                        <?php if (!empty($branding['email'])): ?><div><?php echo e($branding['email']); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div><div class="badge">Invoice</div></div>
        </div>

        <div class="meta">
            <div class="meta-card">
                <h4>Invoice Info</h4>
                <div><strong>No:</strong> <?php echo e($doc['doc_no'] ?? ''); ?></div>
                <div><strong>Date:</strong> <?php echo e($doc['doc_date'] ?? ''); ?></div>
                <?php if (!empty($doc['due_date'])): ?><div><strong>Due Date:</strong> <?php echo e($doc['due_date']); ?></div><?php endif; ?>
                <?php if (!empty($doc['branch_name'])): ?><div><strong>Branch:</strong> <?php echo e($doc['branch_name']); ?></div><?php endif; ?>
            </div>
            <div class="meta-card">
                <h4>Customer</h4>
                <div><strong>Name:</strong> <?php echo e($doc['customer_name'] ?? 'Walk-in'); ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th>Product</th>
                    <th style="width:120px">Barcode</th>
                    <th class="right" style="width:90px">Qty</th>
                    <th class="right" style="width:120px">Price</th>
                    <th class="right" style="width:120px">Discount</th>
                    <th class="right" style="width:140px">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($doc['items'] ?? []) as $i => $it): ?>
                <tr>
                    <td><?php echo $i + 1; ?></td>
                    <td><?php echo e($it['product_name'] ?? ''); ?></td>
                    <td><?php echo e($it['barcode'] ?? ''); ?></td>
                    <td class="right"><?php echo number_format((float)($it['qty'] ?? 0), 2); ?></td>
                    <td class="right"><?php echo e($currency) . number_format((float)($it['selling_price'] ?? 0), 2); ?></td>
                    <td class="right"><?php echo e($currency) . number_format((float)($it['discount'] ?? 0), 2); ?></td>
                    <td class="right"><?php echo e($currency) . number_format((float)($it['line_total'] ?? 0), 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="row">
                <span>Subtotal</span>
                <strong><?php echo e($currency) . number_format($totals['subtotal'], 2); ?></strong>
            </div>
            <div class="row">
                <span>Tax</span>
                <strong><?php echo e($currency) . number_format($totals['tax'], 2); ?></strong>
            </div>
            <div class="row grand">
                <span>Grand Total</span>
                <span><?php echo e($currency) . number_format($totals['total'], 2); ?></span>
            </div>
        </div>

        <?php if (!empty($doc['notes'])): ?>
        <div class="notes">
            <strong>Notes:</strong><br>
            <?php echo nl2br(e($doc['notes'])); ?>
        </div>
        <?php endif; ?>

        <div class="footer">
            <div><?php echo e($branding['invoice_footer'] ?? 'Thank you for your business.'); ?></div>
            <div>Please keep this invoice for your records</div>
        </div>
    </div>
</div>

<div class="actions">
    <button class="btn btn-print" onclick="window.print()">Print Invoice</button>
    <a href="pos.php" class="btn btn-back">Back to POS</a>
</div>
</body>
</html>