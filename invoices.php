<?php
$pageTitle='Credit Invoices';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/invoice_helpers.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customerId = (int)($_POST['customer_id'] ?? 0);
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (float)($_POST['qty'] ?? 1));
    $sellingPrice = (float)($_POST['selling_price'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $tax = (float)($_POST['tax'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($customerId <= 0) {
        flash('error', 'Select customer for invoice.');
        redirect('invoices.php');
    }

    $product = query_one("SELECT * FROM products WHERE id=$productId AND company_id=$cid");
    if (!$product) {
        flash('error', 'Select a valid product.');
        redirect('invoices.php');
    }

    if ((float)$product['stock_qty'] < $qty) {
        flash('error', 'Not enough stock for invoice.');
        redirect('invoices.php');
    }

    $buyingPrice = (float)($product['cost_price'] ?? 0);
    if ($sellingPrice <= 0) $sellingPrice = (float)$product['price'];

    $result = invoice_create_credit_sale(db(), [
        'company_id'   => $cid,
        'branch_id'    => $bid,
        'customer_id'  => $customerId,
        'invoice_no'   => $invoiceNo,
        'invoice_date' => $invoiceDate,
        'tax'          => $tax,
        'discount'     => 0,
        'notes'        => $notes,
        'created_by'   => $_SESSION['user']['id'] ?? null,
    ], [[
        'product_id'    => $productId,
        'product_name'  => $product['name'],
        'qty'           => $qty,
        'buying_price'  => $buyingPrice,
        'selling_price' => $sellingPrice,
        'discount'      => $discount,
    ]]);

    if ($result['ok']) {
        flash('success', 'Invoice created successfully.');
    } else {
        flash('error', 'Invoice failed: ' . $result['message']);
    }
    redirect('invoices.php');
}

$customers = db()->query("SELECT id,name FROM customers WHERE company_id=$cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products  = db()->query("SELECT id,name,price,stock_qty,COALESCE(cost_price,0) cost_price, COALESCE(barcode, code, '') barcode FROM products WHERE company_id=$cid AND stock_qty>0 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$where = "WHERE i.company_id=$cid AND i.branch_id=$bid";
if ($from) $where .= " AND i.invoice_date>='".db()->real_escape_string($from)."'";
if ($to)   $where .= " AND i.invoice_date<='".db()->real_escape_string($to)."'";

$rows = table_exists(db(), 'invoices')
    ? db()->query("SELECT i.*, c.name customer_name, (SELECT GROUP_CONCAT(CONCAT(product_name,' x',qty) SEPARATOR ', ') FROM invoice_items ii WHERE ii.invoice_id=i.id) item_summary FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id $where ORDER BY i.id DESC")->fetch_all(MYSQLI_ASSOC)
    : [];
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card-soft p-4">
      <h5>Create Credit Invoice</h5>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="mb-2"><label class="form-label">Invoice no</label><input name="invoice_no" class="form-control" value="INV-<?php echo date('Ymd-His'); ?>"></div>
        <div class="mb-2"><label class="form-label">Invoice date</label><input type="date" name="invoice_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="mb-2"><label class="form-label">Customer</label><select name="customer_id" class="form-select" required><option value="">Select customer</option><?php foreach($customers as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Product</label><select name="product_id" class="form-select" required><?php foreach($products as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?> | <?php echo e($p['barcode']); ?> | Cost <?php echo money($p['cost_price']); ?> | Sell <?php echo money($p['price']); ?> | Stock <?php echo number_format($p['stock_qty'],2); ?></option><?php endforeach; ?></select></div>
        <div class="row g-2">
          <div class="col"><label class="form-label">Qty</label><input type="number" step="0.01" min="1" name="qty" class="form-control" value="1"></div>
          <div class="col"><label class="form-label">Selling Price</label><input type="number" step="0.01" min="0" name="selling_price" class="form-control" value="0"></div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col"><label class="form-label">Discount</label><input type="number" step="0.01" min="0" name="discount" class="form-control" value="0"></div>
          <div class="col"><label class="form-label">Tax</label><input type="number" step="0.01" min="0" name="tax" class="form-control" value="0"></div>
        </div>
        <div class="mt-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control"></textarea></div>
        <button class="btn btn-primary mt-3 w-100">Save Invoice</button>
      </form>
      <div class="small text-secondary mt-3">Buying price stays read only in invoice logic. Stock changes only after save.</div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card-soft p-4">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
        <form class="d-flex gap-2">
          <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control">
          <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control">
          <button class="btn btn-outline-secondary">Filter</button>
        </form>
        <div class="d-flex gap-2">
          <a href="sales.php" class="btn btn-outline-primary">Receipt Sales</a>
          <a href="financial_statements.php" class="btn btn-outline-success">Financial Statements</a>
        </div>
      </div>
      <table class="table align-middle">
        <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th>Due</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo e($r['invoice_no']); ?></td>
              <td><?php echo e($r['invoice_date']); ?></td>
              <td><?php echo e($r['customer_name']); ?></td>
              <td><?php echo e($r['item_summary']); ?></td>
              <td><?php echo money($r['total_amount']); ?></td>
              <td><?php echo money($r['due_amount']); ?></td>
            </tr>
          <?php endforeach; if(!$rows): ?>
            <tr><td colspan="6" class="text-center text-secondary">No invoices found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
