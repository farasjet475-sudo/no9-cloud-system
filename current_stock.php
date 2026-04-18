<?php
$pageTitle='Current Stock';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');
$cid = current_company_id();
$rows = db()->query("SELECT id,name,COALESCE(barcode,code,'') barcode, category, brand, COALESCE(cost_price,0) cost_price, price selling_price, stock_qty, COALESCE(reorder_level,0) reorder_level FROM products WHERE company_id=$cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between mb-3"><h5 class="mb-0">Current Stock</h5><a href="inventory_reports.php" class="btn btn-outline-secondary">Back</a></div>
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead><tr><th>Product</th><th>Barcode/Code</th><th>Category</th><th>Brand</th><th>Buying Price</th><th>Selling Price</th><th>Stock</th><th>Reorder Level</th></tr></thead>
      <tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['name']); ?></td><td><?php echo e($r['barcode']); ?></td><td><?php echo e($r['category']); ?></td><td><?php echo e($r['brand']); ?></td><td><?php echo money($r['cost_price']); ?></td><td><?php echo money($r['selling_price']); ?></td><td><?php echo number_format($r['stock_qty'],2); ?></td><td><?php echo number_format($r['reorder_level'],2); ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="8" class="text-center text-secondary">No products found.</td></tr><?php endif; ?></tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
