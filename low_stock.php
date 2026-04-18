<?php
$pageTitle='Low Stock';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');
$cid = current_company_id();
$rows = db()->query("SELECT name, COALESCE(barcode,code,'') barcode, stock_qty, COALESCE(reorder_level,0) reorder_level, COALESCE(cost_price,0) cost_price FROM products WHERE company_id=$cid AND stock_qty > 0 AND stock_qty <= COALESCE(reorder_level,0) ORDER BY stock_qty ASC, name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Low Stock Report</h5><a href="inventory_reports.php" class="btn btn-outline-secondary">Back</a></div>
  <table class="table table-bordered align-middle">
    <thead><tr><th>Product</th><th>Barcode/Code</th><th>Stock</th><th>Reorder Level</th><th>Buying Price</th></tr></thead>
    <tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['name']); ?></td><td><?php echo e($r['barcode']); ?></td><td><?php echo number_format($r['stock_qty'],2); ?></td><td><?php echo number_format($r['reorder_level'],2); ?></td><td><?php echo money($r['cost_price']); ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="5" class="text-center text-secondary">No low stock items found.</td></tr><?php endif; ?></tbody>
  </table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
