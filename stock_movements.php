<?php
$pageTitle='Stock Movements';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$productId = (int)($_GET['product_id'] ?? 0);
$type = trim($_GET['type'] ?? '');

$where = "WHERE sm.company_id=$cid AND sm.branch_id=$bid";
if ($from) $where .= " AND DATE(sm.created_at) >= '".db()->real_escape_string($from)."'";
if ($to) $where .= " AND DATE(sm.created_at) <= '".db()->real_escape_string($to)."'";
if ($productId > 0) $where .= " AND sm.product_id = $productId";
if ($type !== '') $where .= " AND sm.transaction_type = '".db()->real_escape_string($type)."'";

$rows = table_exists(db(), 'stock_movements')
    ? db()->query("SELECT sm.*, p.name product_name FROM stock_movements sm LEFT JOIN products p ON p.id=sm.product_id $where ORDER BY sm.id DESC")->fetch_all(MYSQLI_ASSOC)
    : [];

$products = db()->query("SELECT id,name FROM products WHERE company_id=$cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$types = ['RECEIPT_SALE','INVOICE_SALE','SALE_DELETE_RETURN','PURCHASE','OPENING','SALES_RETURN','DAMAGE','PURCHASE_RETURN','ADJUSTMENT_IN','ADJUSTMENT_OUT'];
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <h5 class="mb-0">Stock Movement Report</h5>
    <div class="d-flex gap-2">
      <a href="inventory_reports.php" class="btn btn-outline-primary">Inventory Reports</a>
      <a href="sales.php" class="btn btn-outline-secondary">Sales</a>
    </div>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-md-2"><input type="date" name="from" value="<?php echo e($from); ?>" class="form-control"></div>
    <div class="col-md-2"><input type="date" name="to" value="<?php echo e($to); ?>" class="form-control"></div>
    <div class="col-md-3">
      <select name="product_id" class="form-select">
        <option value="0">All Products</option>
        <?php foreach($products as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo $productId===$p['id']?'selected':''; ?>><?php echo e($p['name']); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="type" class="form-select">
        <option value="">All Types</option>
        <?php foreach($types as $t): ?><option value="<?php echo e($t); ?>" <?php echo $type===$t?'selected':''; ?>><?php echo e($t); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Reference</th><th>Qty In</th><th>Qty Out</th><th>Balance After</th><th>Unit Cost</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo e($r['created_at']); ?></td>
            <td><?php echo e($r['product_name']); ?></td>
            <td><?php echo e($r['transaction_type']); ?></td>
            <td><?php echo e($r['reference_no']); ?></td>
            <td><?php echo number_format((float)$r['qty_in'],2); ?></td>
            <td><?php echo number_format((float)$r['qty_out'],2); ?></td>
            <td><?php echo number_format((float)$r['balance_after'],2); ?></td>
            <td><?php echo money($r['unit_cost']); ?></td>
            <td><?php echo e($r['notes']); ?></td>
          </tr>
        <?php endforeach; if(!$rows): ?>
          <tr><td colspan="9" class="text-center text-secondary">No stock movements found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
