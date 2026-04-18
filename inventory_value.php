<?php
$pageTitle='Inventory Value';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');
$cid = current_company_id();
$rows = db()->query("SELECT name, COALESCE(barcode,code,'') barcode, stock_qty, COALESCE(cost_price,0) cost_price, (stock_qty * COALESCE(cost_price,0)) stock_value FROM products WHERE company_id=$cid ORDER BY stock_value DESC, name")->fetch_all(MYSQLI_ASSOC);
$totalValue = 0;
foreach ($rows as $r) { $totalValue += (float)$r['stock_value']; }
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Inventory Value Report</h5><a href="inventory_reports.php" class="btn btn-outline-secondary">Back</a></div>
  <div class="alert alert-light border">Total Inventory Value: <strong><?php echo money($totalValue); ?></strong></div>
  <table class="table table-bordered align-middle">
    <thead><tr><th>Product</th><th>Barcode/Code</th><th>Stock Qty</th><th>Buying Price</th><th>Stock Value</th></tr></thead>
    <tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['name']); ?></td><td><?php echo e($r['barcode']); ?></td><td><?php echo number_format($r['stock_qty'],2); ?></td><td><?php echo money($r['cost_price']); ?></td><td><?php echo money($r['stock_value']); ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" class="text-center text-secondary">No products found.</td></tr><?php endif; ?></tbody>
  </table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
