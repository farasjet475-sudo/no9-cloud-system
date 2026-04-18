<?php
$pageTitle='Inventory Reports';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$totals = inventory_totals(db(), $cid);
?>
<div class="row g-4">
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Products</div><h3><?php echo number_format($totals['product_count']); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Total Stock Qty</div><h3><?php echo number_format($totals['stock_qty'],2); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Inventory Value</div><h3><?php echo money($totals['inventory_value']); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Low / Out of Stock</div><h3><?php echo number_format($totals['low_stock_count']); ?> / <?php echo number_format($totals['out_of_stock_count']); ?></h3></div></div>

  <div class="col-md-3"><a href="current_stock.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Current Stock</h5><div class="text-secondary">See all live stock balances.</div></div></a></div>
  <div class="col-md-3"><a href="low_stock.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Low Stock</h5><div class="text-secondary">Products near reorder level.</div></div></a></div>
  <div class="col-md-3"><a href="out_of_stock.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Out of Stock</h5><div class="text-secondary">Products with zero stock.</div></div></a></div>
  <div class="col-md-3"><a href="inventory_value.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Inventory Value</h5><div class="text-secondary">Stock value by buying price.</div></div></a></div>

  <div class="col-md-6"><a href="stock_movements.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Stock Movements</h5><div class="text-secondary">Track stock in and stock out history.</div></div></a></div>
  <div class="col-md-6"><a href="financial_statements.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Financial Statements</h5><div class="text-secondary">Income statement and balance sheet linked to stock and sales.</div></div></a></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
