<?php
$pageTitle='Sales';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/stock_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
    $invoiceNo  = trim($_POST['invoice_no'] ?? '');
    $saleDate   = $_POST['sale_date'] ?? date('Y-m-d');
    $productId  = (int)($_POST['product_id'] ?? 0);
    $qty        = max(1, (float)($_POST['qty'] ?? 1));
    $tax        = (float)($_POST['tax'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    $product = query_one("SELECT * FROM products WHERE id=$productId AND company_id=$cid");

    if (!$product) {
        flash('error', 'Select an existing product from inventory.');
        redirect('sales.php');
    }

    if ((float)$product['stock_qty'] < $qty) {
        flash('error', 'Not enough stock for this sale.');
        redirect('sales.php');
    }

    $unitPrice = (float)$product['price'];
    $subtotal  = $unitPrice * $qty;
    $total     = $subtotal + $tax;
    $costPrice = isset($product['cost_price']) ? (float)$product['cost_price'] : 0;

    db()->begin_transaction();
    try {
        $stmt = db()->prepare("INSERT INTO sales(company_id,branch_id,customer_id,invoice_no,sale_date,subtotal,tax,total_amount,notes) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iiissddds', $cid, $bid, $customerId, $invoiceNo, $saleDate, $subtotal, $tax, $total, $notes);
        $stmt->execute();
        $saleId = $stmt->insert_id;
        $stmt->close();

        $stmt2 = db()->prepare("INSERT INTO sale_items(sale_id,product_id,product_name,qty,unit_price,line_total) VALUES(?,?,?,?,?,?)");
        $pname = $product['name'];
        $stmt2->bind_param('iisddd', $saleId, $productId, $pname, $qty, $unitPrice, $subtotal);
        $stmt2->execute();
        $stmt2->close();

        if (!stock_deduct(db(), $productId, $qty)) {
            throw new Exception('Failed to deduct stock.');
        }

        stock_record_movement(db(), [
            'company_id'       => $cid,
            'branch_id'        => $bid,
            'product_id'       => $productId,
            'transaction_type' => 'RECEIPT_SALE',
            'reference_no'     => $invoiceNo,
            'qty_in'           => 0,
            'qty_out'          => $qty,
            'unit_cost'        => $costPrice,
            'notes'            => 'Automatic stock deduction from receipt sale',
            'created_by'       => $_SESSION['user']['id'] ?? null,
        ]);

        finance_post_sale(db(), [
            'entry_date'   => $saleDate,
            'reference_no' => $invoiceNo,
            'memo'         => 'Automatic posting from POS sale',
            'source_id'    => $saleId,
            'branch_id'    => $_SESSION['branch_id'] ?? $bid ?? null,
            'company_id'   => $_SESSION['company_id'] ?? $cid ?? null,
            'created_by'   => $_SESSION['user']['id'] ?? null,
            'gross_amount' => $total,
            'cost_amount'  => $costPrice * $qty,
        ]);

        if (((float)$product['stock_qty'] - $qty) <= (float)($product['reorder_level'] ?? $product['min_stock'] ?? 0)) {
            add_notification($cid, 'inventory', 'Low stock alert', $product['name'].' is at or below minimum stock level.');
        }

        db()->commit();
        flash('success', 'Sale added.');
    } catch (Throwable $e) {
        db()->rollback();
        flash('error', 'Sale failed: '.$e->getMessage());
    }

    redirect('sales.php');
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $sale = query_one("SELECT * FROM sales WHERE id=$id AND company_id=$cid");
    if ($sale) {
        db()->begin_transaction();
        try {
            $items = db()->query("SELECT * FROM sale_items WHERE sale_id=$id")->fetch_all(MYSQLI_ASSOC);
            foreach ($items as $it) {
                if ($it['product_id']) {
                    stock_add(db(), (int)$it['product_id'], (float)$it['qty']);
                    stock_record_movement(db(), [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => (int)$it['product_id'],
                        'transaction_type' => 'SALE_DELETE_RETURN',
                        'reference_no'     => $sale['invoice_no'],
                        'qty_in'           => (float)$it['qty'],
                        'qty_out'          => 0,
                        'unit_cost'        => 0,
                        'notes'            => 'Stock restored after sale deletion',
                        'created_by'       => $_SESSION['user']['id'] ?? null,
                    ]);
                }
            }
            db()->query("DELETE FROM sale_items WHERE sale_id=$id");
            db()->query("DELETE FROM sales WHERE id=$id");
            db()->commit();
            flash('success', 'Sale deleted and stock restored.');
        } catch (Throwable $e) {
            db()->rollback();
            flash('error', 'Delete failed: '.$e->getMessage());
        }
    }
    redirect('sales.php');
}

$customers = db()->query("SELECT id,name FROM customers WHERE company_id=$cid ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products  = db()->query("SELECT id,name,price,stock_qty,COALESCE(cost_price,0) cost_price, COALESCE(barcode, code, '') barcode FROM products WHERE company_id=$cid AND stock_qty>0 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$from  = $_GET['from'] ?? '';
$to    = $_GET['to'] ?? '';
$where = "WHERE s.company_id=$cid";
if ($from) $where .= " AND s.sale_date>='".db()->real_escape_string($from)."'";
if ($to)   $where .= " AND s.sale_date<='".db()->real_escape_string($to)."'";

$rows = db()->query("
    SELECT 
        s.*, 
        c.name customer_name, 
        b.name branch_name,
        (SELECT GROUP_CONCAT(CONCAT(product_name,' x',qty) SEPARATOR ', ') FROM sale_items si WHERE si.sale_id = s.id) item_summary
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN branches b ON b.id = s.branch_id
    $where
    ORDER BY s.id DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card-soft p-4">
      <h5>Record Receipt Sale</h5>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="mb-2"><label class="form-label">Receipt no</label><input name="invoice_no" class="form-control" value="REC-<?php echo date('Ymd-His'); ?>"></div>
        <div class="mb-2"><label class="form-label">Sale date</label><input type="date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="mb-2"><label class="form-label">Customer</label><select name="customer_id" class="form-select"><option value="">Walk-in</option><?php foreach($customers as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
        <div class="mb-2"><label class="form-label">Inventory product</label><select name="product_id" class="form-select" required><?php foreach($products as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?> | <?php echo e($p['barcode']); ?> | Cost <?php echo money($p['cost_price']); ?> | Sell <?php echo money($p['price']); ?> | Stock <?php echo number_format($p['stock_qty'],2); ?></option><?php endforeach; ?></select></div>
        <div class="row g-2"><div class="col"><label class="form-label">Qty</label><input type="number" step="0.01" min="1" name="qty" class="form-control" value="1"></div><div class="col"><label class="form-label">Tax</label><input type="number" step="0.01" min="0" name="tax" class="form-control" value="0"></div></div>
        <div class="mt-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control"></textarea></div>
        <button class="btn btn-primary mt-3 w-100">Save Receipt Sale</button>
      </form>
      <div class="small text-secondary mt-3">
        Buying price is read only on receipt sale. Stock only changes after save.
      </div>
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
          <a href="invoices.php" class="btn btn-outline-primary">Credit Invoices</a>
          <a href="stock_movements.php" class="btn btn-outline-success">Stock Movements</a>
        </div>
      </div>
      <table class="table align-middle">
        <thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th></th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo e($r['invoice_no']); ?><div class="small-muted"><?php echo e($r['branch_name']); ?></div></td>
              <td><?php echo e($r['sale_date']); ?></td>
              <td><?php echo e($r['customer_name'] ?: 'Walk-in'); ?></td>
              <td><?php echo e($r['item_summary']); ?></td>
              <td><?php echo money($r['total_amount']); ?></td>
              <td class="text-end">
                <a target="_blank" href="invoice.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Invoice</a>
                <a target="_blank" href="invoice.php?id=<?php echo $r['id']; ?>&type=receipt" class="btn btn-sm btn-outline-success">Receipt</a>
                <a onclick="return confirm('Delete sale?')" href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger">Delete</a>
              </td>
            </tr>
          <?php endforeach; if(!$rows): ?>
            <tr><td colspan="6" class="text-center text-secondary">No sales found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
