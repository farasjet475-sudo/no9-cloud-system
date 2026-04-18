<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Quotation not found.');
    redirect('quotations.php');
}

$stmt = db()->prepare("SELECT * FROM quotations WHERE id=? AND company_id=? AND branch_id=? LIMIT 1");
$stmt->bind_param('iii', $id, $cid, $bid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    flash('error', 'Quotation not found.');
    redirect('quotations.php');
}

function quotation_logo_url(string $logo): string {
    $logo = trim($logo);
    if ($logo === '') return '';
    $candidates = ['uploads/products/' . $logo, 'uploads/' . $logo, $logo];
    foreach ($candidates as $path) {
        $full = __DIR__ . '/' . ltrim($path, '/');
        if (file_exists($full)) return $path;
    }
    return $logo;
}

$branding = function_exists('company_branding') ? company_branding() : [];
$currency = $branding['currency_symbol'] ?? '$';
$logo = quotation_logo_url($branding['logo'] ?? '');
$branchName = '';
$stmt = db()->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
$stmt->bind_param('i', $bid);
$stmt->execute();
$branchRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$branchName = $branchRow['name'] ?? '';

$rawDetails = json_decode((string)($row['details'] ?? ''), true);
$tax = 0;
$notes = '';
$items = [];

if (isset($rawDetails['items']) && is_array($rawDetails['items'])) {
    $items = $rawDetails['items'];
    $tax = (float)($rawDetails['tax'] ?? 0);
    $notes = (string)($rawDetails['notes'] ?? '');
} elseif (is_array($rawDetails)) {
    $items = $rawDetails;
}

$subtotal = 0;
foreach ($items as $it) {
    $subtotal += (float)($it['total'] ?? 0);
}
$total = $subtotal + $tax;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quotation - <?php echo e($row['quote_no']); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif}
body{padding:24px}.page{max-width:980px;margin:0 auto}.sheet{background:#fff;border:1px solid #dbe3ee;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08)}
.topline{height:8px;background:#7c3aed}.inner{padding:30px}.header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:2px solid #eef2f7;padding-bottom:20px}.brand{display:flex;gap:16px;align-items:flex-start}
.brand-logo{width:84px;height:84px;border:1px solid #e5e7eb;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#fff;overflow:hidden}.brand-logo img{max-width:100%;max-height:100%;object-fit:contain}
.brand-text h1{margin:0;font-size:28px;line-height:1.15;color:#0f172a;font-weight:800}.brand-text .title{margin-top:4px;font-size:15px;color:#334155;font-weight:700}.brand-text .tagline{margin-top:6px;color:#64748b;font-size:13px}
.brand-meta{margin-top:10px;color:#475569;font-size:13px;line-height:1.6}.doc-badge{text-align:right}.doc-badge .type{display:inline-block;padding:10px 18px;border-radius:999px;color:#fff;font-weight:800;letter-spacing:.7px;background:#7c3aed;font-size:13px;text-transform:uppercase}.doc-badge .number{margin-top:12px;font-size:24px;font-weight:800;color:#0f172a}
.section-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px}.info-card{border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}.info-card h3{margin:0 0 10px;font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#64748b}.info-card .line{margin:5px 0;font-size:14px;color:#0f172a}
table{width:100%;border-collapse:collapse;margin-top:24px}thead th{background:#0f172a;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;text-align:left}tbody td{padding:12px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#0f172a}tbody tr:nth-child(even){background:#fcfdff}.right{text-align:right}
.totals-wrap{display:flex;justify-content:flex-end;margin-top:22px}.totals{width:360px;border:1px solid #dbe3ee;background:#f8fafc;border-radius:14px;padding:16px 18px}.totals .row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #cbd5e1;font-size:14px}.totals .row:last-child{border-bottom:0}.totals .grand{font-size:18px;font-weight:800;color:#0f172a}
.notes{margin-top:22px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}.notes h3{margin:0 0 8px;font-size:13px;color:#334155}.footer{margin-top:26px;padding-top:18px;border-top:2px solid #eef2f7;display:flex;justify-content:space-between;gap:20px;align-items:flex-start;color:#475569;font-size:13px;line-height:1.6}
.signature{min-width:220px;text-align:center}.signature-line{margin-top:34px;border-top:1px solid #94a3b8;padding-top:6px;color:#334155}.actions{display:flex;gap:12px;margin-top:18px}.btn{appearance:none;border:0;border-radius:12px;padding:12px 18px;font-weight:700;text-decoration:none;cursor:pointer;color:#fff;background:#334155}.btn-primary{background:#7c3aed}
@media (max-width:760px){body{padding:10px}.header,.footer{flex-direction:column}.section-grid{grid-template-columns:1fr}.totals{width:100%}}@media print{body{background:#fff;padding:0}.page{max-width:none}.sheet{border:0;border-radius:0;box-shadow:none}.actions{display:none}}
</style>
</head>
<body>
<div class="page"><div class="sheet"><div class="topline"></div><div class="inner">
<div class="header"><div class="brand"><?php if ($logo): ?><div class="brand-logo"><img src="<?php echo e($logo); ?>" alt="Logo"></div><?php endif; ?><div class="brand-text"><h1><?php echo e($branding['name'] ?? 'Company'); ?></h1><div class="title"><?php echo e($branding['title'] ?? ($branding['name'] ?? 'Company')); ?></div><?php if (!empty($branding['tagline'])): ?><div class="tagline"><?php echo e($branding['tagline']); ?></div><?php endif; ?><div class="brand-meta"><?php if (!empty($branding['address'])): ?><div><?php echo e($branding['address']); ?></div><?php endif; ?><?php if (!empty($branding['phone'])): ?><div><?php echo e($branding['phone']); ?></div><?php endif; ?><?php if (!empty($branding['email'])): ?><div><?php echo e($branding['email']); ?></div><?php endif; ?></div></div></div><div class="doc-badge"><div class="type">Quotation</div><div class="number"><?php echo e($row['quote_no']); ?></div></div></div>
<div class="section-grid"><div class="info-card"><h3>Document Information</h3><div class="line"><strong>Date:</strong> <?php echo e($row['quote_date']); ?></div><div class="line"><strong>Branch:</strong> <?php echo e($branchName); ?></div><div class="line"><strong>Status:</strong> <?php echo e(ucfirst($row['status'] ?? 'draft')); ?></div></div><div class="info-card"><h3>Customer Information</h3><div class="line"><strong>Customer:</strong> <?php echo e($row['customer_name'] ?: 'Walk-in'); ?></div></div></div>
<table><thead><tr><th style="width:60px">#</th><th>Description</th><th style="width:120px">Barcode</th><th class="right" style="width:90px">Qty</th><th class="right" style="width:130px">Unit Price</th><th class="right" style="width:130px">Discount</th><th class="right" style="width:140px">Line Total</th></tr></thead><tbody><?php foreach ($items as $i => $it): ?><tr><td><?php echo $i + 1; ?></td><td><?php echo e($it['description'] ?? ''); ?></td><td><?php echo e($it['barcode'] ?? ''); ?></td><td class="right"><?php echo number_format((float)($it['qty'] ?? 0), 2); ?></td><td class="right"><?php echo e($currency) . number_format((float)($it['price'] ?? 0), 2); ?></td><td class="right"><?php echo e($currency) . number_format((float)($it['discount'] ?? 0), 2); ?></td><td class="right"><?php echo e($currency) . number_format((float)($it['total'] ?? 0), 2); ?></td></tr><?php endforeach; ?></tbody></table>
<div class="totals-wrap"><div class="totals"><div class="row"><span>Subtotal</span><strong><?php echo e($currency) . number_format($subtotal, 2); ?></strong></div><div class="row"><span>Tax</span><strong><?php echo e($currency) . number_format($tax, 2); ?></strong></div><div class="row grand"><span>Grand Total</span><span><?php echo e($currency) . number_format($total, 2); ?></span></div></div></div>
<?php if ($notes !== ''): ?><div class="notes"><h3>Notes</h3><div><?php echo nl2br(e($notes)); ?></div></div><?php endif; ?>
<div class="footer"><div><div><strong><?php echo e($branding['name'] ?? 'Company'); ?></strong></div><div><?php echo e($branding['invoice_footer'] ?? 'Thank you for your business.'); ?></div><div>This quotation is valid for 7 days from the issue date.</div></div><div class="signature"><div class="signature-line">Authorized Signature</div></div></div>
</div></div></div>
<div class="actions"><button class="btn btn-primary" onclick="window.print()">Print Quotation</button><a href="quotations.php" class="btn">Back to Quotations</a></div>
</body>
</html>
