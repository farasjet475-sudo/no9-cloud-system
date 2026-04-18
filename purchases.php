<?php
$pageTitle = 'Purchases';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/stock_helpers.php';
require_once __DIR__ . '/includes/finance_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();
$db  = db();

if (!isset($_SESSION['purchase_cart']) || !is_array($_SESSION['purchase_cart'])) {
    $_SESSION['purchase_cart'] = [];
}

function purchase_cart_totals(array $cart, float $tax = 0): array {
    $subtotal = 0;
    foreach ($cart as $item) {
        $subtotal += (float)($item['line_total'] ?? 0);
    }

    return [
        'subtotal' => $subtotal,
        'tax'      => $tax,
        'total'    => $subtotal + $tax,
    ];
}

function purchase_status_text(float $paid, float $due): string {
    if ($due <= 0) return 'paid';
    if ($paid > 0) return 'partial';
    return 'unpaid';
}

function purchase_upload_dir(): string {
    $dir = __DIR__ . '/uploads/purchases/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_new_product') {
        $name       = trim($_POST['name'] ?? '');
        $sku        = trim($_POST['sku'] ?? '');
        $category   = trim($_POST['category'] ?? '');
        $costPrice  = max(0, (float)($_POST['cost_price'] ?? 0));
        $sellPrice  = max(0, (float)($_POST['price'] ?? 0));
        $unit       = trim($_POST['unit'] ?? 'pcs');
        $minStock   = max(0, (float)($_POST['min_stock'] ?? 0));
        $openingQty = max(0, (float)($_POST['opening_qty'] ?? 0));

        if ($name === '') {
            flash('error', 'Product name is required.');
            redirect('purchases.php');
        }

        $stmt = $db->prepare("
            INSERT INTO products
            (company_id, branch_id, name, sku, category, price, cost_price, stock_qty, min_stock, unit, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'iisssdddss',
            $cid,
            $bid,
            $name,
            $sku,
            $category,
            $sellPrice,
            $costPrice,
            $openingQty,
            $minStock,
            $unit
        );

        if ($stmt->execute()) {
            $newProductId = $stmt->insert_id;

            if ($openingQty > 0 && function_exists('stock_record_movement')) {
                stock_record_movement($db, [
                    'company_id'       => $cid,
                    'branch_id'        => $bid,
                    'product_id'       => $newProductId,
                    'transaction_type' => 'OPENING_STOCK',
                    'reference_no'     => 'OPEN-' . $newProductId,
                    'qty_in'           => $openingQty,
                    'qty_out'          => 0,
                    'unit_cost'        => $costPrice,
                    'notes'            => 'Opening stock from new product creation',
                    'created_by'       => current_user_id(),
                ]);
            }

            flash('success', 'Product created with opening stock successfully.');
        } else {
            flash('error', 'Failed to create product.');
        }
        $stmt->close();
        redirect('purchases.php');
    }

    if ($action === 'add_item') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $qty       = max(1, (float)($_POST['qty'] ?? 1));
        $unitCost  = max(0, (float)($_POST['unit_cost'] ?? 0));

        $product = query_one("SELECT * FROM products WHERE id=$productId AND company_id=$cid");
        if (!$product) {
            flash('error', 'Select a valid product.');
            redirect('purchases.php');
        }

        if ($unitCost <= 0) {
            $unitCost = (float)($product['cost_price'] ?? 0);
        }

        $lineTotal = $qty * $unitCost;

        $found = false;
        foreach ($_SESSION['purchase_cart'] as $k => $item) {
            if ((int)$item['product_id'] === $productId) {
                $newQty = (float)$item['qty'] + $qty;
                $_SESSION['purchase_cart'][$k]['qty'] = $newQty;
                $_SESSION['purchase_cart'][$k]['unit_cost'] = $unitCost;
                $_SESSION['purchase_cart'][$k]['line_total'] = $newQty * $unitCost;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $_SESSION['purchase_cart'][] = [
                'product_id'   => (int)$product['id'],
                'product_name' => $product['name'],
                'sku'          => $product['sku'] ?? '',
                'qty'          => $qty,
                'unit_cost'    => $unitCost,
                'line_total'   => $lineTotal,
            ];
        }

        flash('success', 'Product added to purchase table.');
        redirect('purchases.php');
    }

    if ($action === 'update_item') {
        $index    = (int)($_POST['cart_index'] ?? -1);
        $qty      = max(1, (float)($_POST['qty'] ?? 1));
        $unitCost = max(0.01, (float)($_POST['unit_cost'] ?? 0));

        if (!isset($_SESSION['purchase_cart'][$index])) {
            flash('error', 'Item not found.');
            redirect('purchases.php');
        }

        $_SESSION['purchase_cart'][$index]['qty'] = $qty;
        $_SESSION['purchase_cart'][$index]['unit_cost'] = $unitCost;
        $_SESSION['purchase_cart'][$index]['line_total'] = $qty * $unitCost;

        flash('success', 'Purchase item updated.');
        redirect('purchases.php');
    }

    if ($action === 'remove_item') {
        $index = (int)($_POST['cart_index'] ?? -1);
        if (isset($_SESSION['purchase_cart'][$index])) {
            unset($_SESSION['purchase_cart'][$index]);
            $_SESSION['purchase_cart'] = array_values($_SESSION['purchase_cart']);
            flash('success', 'Item removed.');
        }
        redirect('purchases.php');
    }

    if ($action === 'clear_cart') {
        $_SESSION['purchase_cart'] = [];
        flash('success', 'Purchase table cleared.');
        redirect('purchases.php');
    }

    if ($action === 'import_csv') {
        if (empty($_FILES['csv_file']['name'])) {
            flash('error', 'Please choose a CSV file.');
            redirect('purchases.php');
        }

        $tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if (!$handle) {
            flash('error', 'Unable to read CSV file.');
            redirect('purchases.php');
        }

        $imported = 0;
        $skipped  = 0;
        $headerRead = false;

        while (($row = fgetcsv($handle)) !== false) {
            if (!$headerRead) {
                $headerRead = true;
                if (isset($row[0]) && stripos($row[0], 'product') !== false) {
                    continue;
                }
            }

            $name = trim($row[0] ?? '');
            $qty  = max(0, (float)($row[1] ?? 0));
            $cost = max(0, (float)($row[2] ?? 0));

            if ($name === '' || $qty <= 0) {
                $skipped++;
                continue;
            }

            $safeName = $db->real_escape_string($name);
            $product = query_one("SELECT * FROM products WHERE company_id=$cid AND name='$safeName' LIMIT 1");

            if (!$product) {
                $stmt = $db->prepare("
                    INSERT INTO products (company_id, branch_id, name, sku, category, price, cost_price, stock_qty, min_stock, unit, created_at)
                    VALUES (?, ?, ?, '', '', 0, ?, 0, 0, 'pcs', NOW())
                ");
                $stmt->bind_param('iisd', $cid, $bid, $name, $cost);
                $stmt->execute();
                $productId = $stmt->insert_id;
                $stmt->close();

                $_SESSION['purchase_cart'][] = [
                    'product_id'   => $productId,
                    'product_name' => $name,
                    'sku'          => '',
                    'qty'          => $qty,
                    'unit_cost'    => $cost,
                    'line_total'   => $qty * $cost,
                ];
            } else {
                $resolvedCost = $cost > 0 ? $cost : (float)($product['cost_price'] ?? 0);

                $found = false;
                foreach ($_SESSION['purchase_cart'] as $k => $item) {
                    if ((int)$item['product_id'] === (int)$product['id']) {
                        $_SESSION['purchase_cart'][$k]['qty'] += $qty;
                        $_SESSION['purchase_cart'][$k]['unit_cost'] = $resolvedCost;
                        $_SESSION['purchase_cart'][$k]['line_total'] = $_SESSION['purchase_cart'][$k]['qty'] * $_SESSION['purchase_cart'][$k]['unit_cost'];
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $_SESSION['purchase_cart'][] = [
                        'product_id'   => (int)$product['id'],
                        'product_name' => $product['name'],
                        'sku'          => $product['sku'] ?? '',
                        'qty'          => $qty,
                        'unit_cost'    => $resolvedCost,
                        'line_total'   => $qty * $resolvedCost,
                    ];
                }
            }

            $imported++;
        }

        fclose($handle);
        flash('success', "CSV import completed. Imported: $imported, Skipped: $skipped.");
        redirect('purchases.php');
    }

    if ($action === 'save_purchase') {
        $supplierId   = (int)($_POST['supplier_id'] ?? 0);
        $purchaseNo   = trim($_POST['purchase_no'] ?? '');
        $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
        $tax          = max(0, (float)($_POST['tax'] ?? 0));
        $paidNow      = max(0, (float)($_POST['paid_now'] ?? 0));
        $notes        = trim($_POST['notes'] ?? '');

        if ($supplierId <= 0) {
            flash('error', 'Select supplier.');
            redirect('purchases.php');
        }

        if ($purchaseNo === '') {
            flash('error', 'Purchase number is required.');
            redirect('purchases.php');
        }

        if (empty($_SESSION['purchase_cart'])) {
            flash('error', 'Purchase table is empty.');
            redirect('purchases.php');
        }

        $totals = purchase_cart_totals($_SESSION['purchase_cart'], $tax);

        if ($paidNow > $totals['total']) {
            flash('error', 'Paid amount cannot be greater than total purchase.');
            redirect('purchases.php');
        }

        $dueAmount = $totals['total'] - $paidNow;
        $status = purchase_status_text($paidNow, $dueAmount);

        $db->begin_transaction();
        try {
            $createdBy = current_user_id();

            $stmt = $db->prepare("
                INSERT INTO purchases
                (company_id, branch_id, supplier_id, purchase_no, purchase_date, subtotal, tax, total_amount, paid_amount, due_amount, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->bind_param(
                'iiissddddsssi',
                $cid,
                $bid,
                $supplierId,
                $purchaseNo,
                $purchaseDate,
                $totals['subtotal'],
                $tax,
                $totals['total'],
                $paidNow,
                $dueAmount,
                $status,
                $notes,
                $createdBy
            );
            $stmt->execute();
            $purchaseId = $stmt->insert_id;
            $stmt->close();

            foreach ($_SESSION['purchase_cart'] as $item) {
                $productId   = (int)$item['product_id'];
                $productName = $item['product_name'];
                $qty         = (float)$item['qty'];
                $unitCost    = (float)$item['unit_cost'];
                $lineTotal   = (float)$item['line_total'];

                $stmt2 = $db->prepare("
                    INSERT INTO purchase_items
                    (purchase_id, product_id, product_name, qty, unit_cost, line_total)
                    VALUES (?,?,?,?,?,?)
                ");
                $stmt2->bind_param(
                    'iisddd',
                    $purchaseId,
                    $productId,
                    $productName,
                    $qty,
                    $unitCost,
                    $lineTotal
                );
                $stmt2->execute();
                $stmt2->close();

                $db->query("
                    UPDATE products
                    SET stock_qty = stock_qty + ".(float)$qty.",
                        cost_price = ".(float)$unitCost."
                    WHERE id=".(int)$productId." AND company_id=".(int)$cid."
                ");

                if (function_exists('stock_record_movement')) {
                    stock_record_movement($db, [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => $productId,
                        'transaction_type' => 'PURCHASE',
                        'reference_no'     => $purchaseNo,
                        'qty_in'           => $qty,
                        'qty_out'          => 0,
                        'unit_cost'        => $unitCost,
                        'notes'            => 'Automatic stock increase from purchase',
                        'created_by'       => current_user_id(),
                    ]);
                }
            }

            if ($paidNow > 0) {
                $stmt3 = $db->prepare("
                    INSERT INTO purchase_payments
                    (company_id, branch_id, purchase_id, supplier_id, payment_date, amount, method, reference_no, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $method = trim($_POST['payment_method'] ?? 'cash');
                $referenceNo = trim($_POST['reference_no'] ?? '');
                $stmt3->bind_param(
                    'iiiisdsssi',
                    $cid,
                    $bid,
                    $purchaseId,
                    $supplierId,
                    $purchaseDate,
                    $paidNow,
                    $method,
                    $referenceNo,
                    $notes,
                    $createdBy
                );
                $stmt3->execute();
                $stmt3->close();
            }

            if (function_exists('finance_post_expense')) {
                finance_post_expense($db, [
                    'entry_date'   => $purchaseDate,
                    'reference_no' => $purchaseNo,
                    'memo'         => 'Automatic posting from purchase',
                    'source_id'    => $purchaseId,
                    'branch_id'    => $bid,
                    'company_id'   => $cid,
                    'created_by'   => current_user_id(),
                    'amount'       => $totals['total'],
                ]);
            }

            $db->commit();
            $_SESSION['purchase_cart'] = [];
            flash('success', 'Purchase saved successfully.');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Purchase failed: ' . $e->getMessage());
        }

        redirect('purchases.php');
    }

    if ($action === 'save_payment') {
        $purchaseId   = (int)($_POST['purchase_id'] ?? 0);
        $paymentDate  = $_POST['payment_date'] ?? date('Y-m-d');
        $amount       = max(0, (float)($_POST['amount'] ?? 0));
        $method       = trim($_POST['method'] ?? 'cash');
        $referenceNo  = trim($_POST['reference_no'] ?? '');
        $notes        = trim($_POST['payment_notes'] ?? '');

        $purchase = query_one("SELECT * FROM purchases WHERE id=$purchaseId AND company_id=$cid AND branch_id=$bid");
        if (!$purchase) {
            flash('error', 'Purchase not found.');
            redirect('purchases.php');
        }

        if ($amount <= 0) {
            flash('error', 'Payment amount must be greater than 0.');
            redirect('purchases.php');
        }

        $currentDue = (float)($purchase['due_amount'] ?? 0);
        if ($amount > $currentDue) {
            flash('error', 'Payment cannot exceed due amount.');
            redirect('purchases.php');
        }

        $db->begin_transaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO purchase_payments
                (company_id, branch_id, purchase_id, supplier_id, payment_date, amount, method, reference_no, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $createdBy = current_user_id();
            $supplierId = (int)$purchase['supplier_id'];
            $stmt->bind_param(
                'iiiisdsssi',
                $cid,
                $bid,
                $purchaseId,
                $supplierId,
                $paymentDate,
                $amount,
                $method,
                $referenceNo,
                $notes,
                $createdBy
            );
            $stmt->execute();
            $stmt->close();

            $newPaid = (float)$purchase['paid_amount'] + $amount;
            $newDue  = max(0, (float)$purchase['total_amount'] - $newPaid);
            $newStatus = purchase_status_text($newPaid, $newDue);

            $stmt2 = $db->prepare("UPDATE purchases SET paid_amount=?, due_amount=?, status=? WHERE id=? AND company_id=? AND branch_id=?");
            $stmt2->bind_param('ddsiii', $newPaid, $newDue, $newStatus, $purchaseId, $cid, $bid);
            $stmt2->execute();
            $stmt2->close();

            $db->commit();
            flash('success', 'Purchase payment saved successfully.');
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Payment failed: '.$e->getMessage());
        }

        redirect('purchases.php');
    }
}

if (isset($_GET['print'])) {
    $purchaseId = (int)$_GET['print'];
    $purchase = query_one("
        SELECT p.*, s.name supplier_name, s.phone supplier_phone
        FROM purchases p
        LEFT JOIN suppliers s ON s.id=p.supplier_id
        WHERE p.id=$purchaseId AND p.company_id=$cid AND p.branch_id=$bid
    ");

    if (!$purchase) {
        exit('Purchase not found.');
    }

    $brand = function_exists('company_branding') ? company_branding() : [];
    $items = $db->query("SELECT * FROM purchase_items WHERE purchase_id=$purchaseId ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Purchase <?php echo e($purchase['purchase_no']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body{font-family:Arial,sans-serif;padding:28px;background:#eef2f7;color:#0f172a}
            .sheet{max-width:960px;margin:auto;background:#fff;padding:34px;border-radius:22px;box-shadow:0 16px 40px rgba(15,23,42,.10)}
            .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:24px;padding-bottom:18px;border-bottom:2px solid #e2e8f0}
            .brand-name{font-size:28px;font-weight:800;margin:0;color:#0f172a}
            .muted{color:#64748b}
            .doc-badge{display:inline-block;padding:10px 16px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:800}
            table{width:100%;border-collapse:collapse;margin-top:16px}
            th,td{border:1px solid #dbe3ef;padding:11px}
            th{background:#f8fafc;color:#334155}
            .right{text-align:right}
            .totals{max-width:360px;margin-left:auto;margin-top:18px}
            .totals .rowx{display:flex;justify-content:space-between;padding:12px 14px;border:1px solid #dbe3ef;border-top:0}
            .totals .rowx:first-child{border-top:1px solid #dbe3ef}
            .totals .grand{background:#f8fafc;font-weight:800}
            .print-btn{margin-top:22px}
            @media print {.print-btn{display:none} body{background:#fff;padding:0}.sheet{box-shadow:none;border-radius:0}}
        </style>
    </head>
    <body>
        <div class="sheet">
            <div class="head">
                <div>
                    <h1 class="brand-name"><?php echo e($brand['name'] ?? 'Company'); ?></h1>
                    <div class="muted"><?php echo e($brand['address'] ?? ''); ?></div>
                    <div class="muted"><?php echo e(trim(($brand['phone'] ?? '').'  '.($brand['email'] ?? ''))); ?></div>
                </div>
                <div class="text-end">
                    <div class="doc-badge">PURCHASE</div>
                    <div class="mt-2 fw-bold"><?php echo e($purchase['purchase_no']); ?></div>
                </div>
            </div>

            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <strong>Supplier:</strong> <?php echo e($purchase['supplier_name']); ?><br>
                    <span class="muted"><?php echo e($purchase['supplier_phone']); ?></span>
                </div>
                <div class="col-md-6 text-md-end">
                    <strong>Date:</strong> <?php echo e($purchase['purchase_date']); ?><br>
                    <strong>Status:</strong> <?php echo e(ucfirst($purchase['status'])); ?>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th class="right">Qty</th>
                        <th class="right">Unit Cost</th>
                        <th class="right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $i => $it): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo e($it['product_name']); ?></td>
                        <td class="right"><?php echo number_format((float)$it['qty'], 2); ?></td>
                        <td class="right"><?php echo money($it['unit_cost']); ?></td>
                        <td class="right"><?php echo money($it['line_total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <div class="rowx"><span>Subtotal</span><strong><?php echo money($purchase['subtotal']); ?></strong></div>
                <div class="rowx"><span>Tax</span><strong><?php echo money($purchase['tax']); ?></strong></div>
                <div class="rowx grand"><span>Total</span><strong><?php echo money($purchase['total_amount']); ?></strong></div>
                <div class="rowx"><span>Paid</span><strong><?php echo money($purchase['paid_amount']); ?></strong></div>
                <div class="rowx"><span>Due</span><strong><?php echo money($purchase['due_amount']); ?></strong></div>
            </div>

            <?php if (!empty($purchase['notes'])): ?>
                <div class="mt-4"><strong>Notes:</strong> <?php echo e($purchase['notes']); ?></div>
            <?php endif; ?>

            <div class="mt-4 muted text-center"><?php echo e($brand['invoice_footer'] ?? 'Thank you.'); ?></div>

            <button class="btn btn-primary print-btn" onclick="window.print()">Print Purchase</button>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$products = $db->query("
    SELECT id, name, sku, COALESCE(cost_price,0) cost_price
    FROM products
    WHERE company_id=$cid
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

$suppliers = $db->query("
    SELECT id, name, phone
    FROM suppliers
    WHERE company_id=$cid
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$supplierFilter = (int)($_GET['supplier_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$where = "WHERE p.company_id=$cid AND p.branch_id=$bid";

if ($search !== '') {
    $safe = $db->real_escape_string($search);
    $where .= " AND (p.purchase_no LIKE '%$safe%' OR s.name LIKE '%$safe%')";
}
if ($statusFilter !== '') {
    $safeStatus = $db->real_escape_string($statusFilter);
    $where .= " AND p.status='$safeStatus'";
}
if ($supplierFilter > 0) {
    $where .= " AND p.supplier_id=".(int)$supplierFilter;
}
if ($from) {
    $where .= " AND p.purchase_date>='".$db->real_escape_string($from)."'";
}
if ($to) {
    $where .= " AND p.purchase_date<='".$db->real_escape_string($to)."'";
}

$rows = $db->query("
    SELECT p.*, s.name supplier_name,
           (SELECT GROUP_CONCAT(CONCAT(product_name,' x',qty) SEPARATOR ', ')
            FROM purchase_items pi WHERE pi.purchase_id=p.id) item_summary
    FROM purchases p
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    $where
    ORDER BY p.id DESC
")->fetch_all(MYSQLI_ASSOC);

$totals = $db->query("
    SELECT
        COUNT(*) AS total_purchases,
        COALESCE(SUM(total_amount),0) AS total_amount,
        COALESCE(SUM(paid_amount),0) AS total_paid,
        COALESCE(SUM(due_amount),0) AS total_due,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END) AS partial_count,
        SUM(CASE WHEN status='unpaid' THEN 1 ELSE 0 END) AS unpaid_count
    FROM purchases
    WHERE company_id=$cid AND branch_id=$bid
")->fetch_assoc();

$cart = $_SESSION['purchase_cart'];
$cartTotals = purchase_cart_totals($cart, 0);
$productJson = json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>

<style>
.purchase-page{
    --primary:#2563eb;
    --primary-dark:#1d4ed8;
    --success:#10b981;
    --warning:#f59e0b;
    --danger:#ef4444;
    --dark:#0f172a;
    --soft:#f8fafc;
    --border:#e2e8f0;
}
.purchase-page .hero-card,
.purchase-page .soft-card{
    border:0;
    border-radius:24px;
    background:#fff;
    box-shadow:0 14px 34px rgba(15,23,42,.07);
}
.purchase-page .hero-card{
    padding:22px;
    margin-bottom:20px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
}
.purchase-page .hero-card h3{
    margin:0;
    font-weight:800;
}
.purchase-page .hero-card p{
    margin:8px 0 0;
    color:rgba(255,255,255,.78);
}
.purchase-page .kpi-card{
    border:0;
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.purchase-page .kpi-card small{opacity:.92;font-size:.84rem}
.purchase-page .kpi-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.purchase-page .kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.purchase-page .kpi-2{background:linear-gradient(135deg,#10b981,#059669)}
.purchase-page .kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.purchase-page .kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.purchase-page .soft-card{padding:24px}
.purchase-page .section-title{font-weight:800;margin-bottom:16px;color:#0f172a}
.purchase-page .section-subtitle{color:#64748b;font-size:14px}
.purchase-page .summary-box{
    border-radius:18px;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:14px 16px;
}
.purchase-page .summary-item{
    display:flex;justify-content:space-between;align-items:center;
    padding:10px 0;border-bottom:1px dashed #dbe3ef
}
.purchase-page .summary-item:last-child{border-bottom:0}
.purchase-page .search-results{
    z-index:1000;
    max-height:280px;
    overflow:auto;
    display:none;
    border-radius:16px;
    border:1px solid #e2e8f0;
}
.purchase-page .small-note{font-size:12px;color:#64748b}
.purchase-page .status-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}
.purchase-page .status-paid{background:#dcfce7;color:#15803d}
.purchase-page .status-partial{background:#e0f2fe;color:#0369a1}
.purchase-page .status-unpaid{background:#fee2e2;color:#b91c1c}
.purchase-page .table thead th{
    background:#f8fafc;
    color:#334155;
    border-color:#e2e8f0;
    font-size:13px;
    white-space:nowrap;
}
.purchase-page .table td{
    vertical-align:middle;
    border-color:#e2e8f0;
}
.purchase-page .glass-box{
    background:linear-gradient(180deg,#ffffff,#f8fafc);
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:16px;
}
.purchase-page .top-tools{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}
.purchase-page .empty-box{
    padding:26px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    border-radius:18px;
}
.purchase-page .totals-panel{
    border-radius:18px;
    background:linear-gradient(180deg,#f8fafc,#ffffff);
    border:1px solid #e5e7eb;
    padding:18px;
}
.purchase-page .totals-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:10px 0;
    border-bottom:1px dashed #dbe3ef;
}
.purchase-page .totals-row:last-child{border-bottom:0}
.purchase-page .totals-row.total{
    font-size:18px;
    font-weight:800;
    color:#0f172a;
}
.purchase-page .btn{
    border-radius:14px;
    font-weight:700;
}
.purchase-page .form-control,
.purchase-page .form-select{
    border-radius:14px;
    min-height:46px;
}
.purchase-page .modal-content{
    border:0;
    border-radius:24px;
    overflow:hidden;
    box-shadow:0 22px 60px rgba(15,23,42,.18);
}
.purchase-page .modal-header{
    background:#0f172a;
    color:#fff;
    border-bottom:0;
}
.purchase-page .modal-header .btn-close{
    filter:invert(1);
}
</style>

<div class="purchase-page">
    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Purchase Management</h3>
                <p>Create purchases, add supplier payments, increase stock automatically, and keep purchase history clean and professional.</p>
            </div>
            <button class="btn btn-light fw-bold" data-bs-toggle="modal" data-bs-target="#addProductModal">+ Add New Product</button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="kpi-card kpi-1"><small>Total Purchases</small><h3><?php echo (int)($totals['total_purchases'] ?? 0); ?></h3></div></div>
        <div class="col-md-3"><div class="kpi-card kpi-2"><small>Total Amount</small><h3><?php echo money($totals['total_amount'] ?? 0); ?></h3></div></div>
        <div class="col-md-3"><div class="kpi-card kpi-3"><small>Total Paid</small><h3><?php echo money($totals['total_paid'] ?? 0); ?></h3></div></div>
        <div class="col-md-3"><div class="kpi-card kpi-4"><small>Total Due</small><h3><?php echo money($totals['total_due'] ?? 0); ?></h3></div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="soft-card mb-4">
                <div class="top-tools mb-3">
                    <div>
                        <h5 class="section-title mb-1">Add Product To Purchase</h5>
                        <div class="section-subtitle">Search product and add it to temporary purchase table</div>
                    </div>
                </div>

                <div class="mb-3 position-relative">
                    <label class="form-label fw-semibold">Search Product</label>
                    <input type="text" id="productSearch" class="form-control" placeholder="Type product name or SKU...">
                    <div id="searchResults" class="list-group position-absolute w-100 shadow-sm search-results"></div>
                </div>

                <div id="selectedProductBox" class="glass-box mb-3" style="display:none;">
                    <div class="mb-1"><strong>Product:</strong> <span id="sp_name"></span></div>
                    <div class="mb-1"><strong>SKU:</strong> <span id="sp_sku"></span></div>
                    <div><strong>Last Cost:</strong> <span id="sp_cost"></span></div>
                </div>

                <form method="post" id="addItemForm" class="mb-4">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="product_id" id="selected_product_id">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Qty</label>
                        <input type="number" step="0.01" min="1" name="qty" class="form-control" value="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Unit Cost</label>
                        <input type="number" step="0.01" min="0" name="unit_cost" id="selected_unit_cost" class="form-control" value="0" required>
                    </div>

                    <button class="btn btn-primary w-100">Add To Purchase Table</button>
                </form>

                <hr>

                <h6 class="fw-bold mb-2">Import CSV</h6>
                <div class="small-note mb-2">Format: Product Name, Qty, Cost</div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="import_csv">
                    <input type="file" name="csv_file" class="form-control mb-3" accept=".csv" required>
                    <button class="btn btn-success w-100">Import CSV To Temp Table</button>
                </form>
            </div>

            <div class="soft-card">
                <h5 class="section-title">Status Summary</h5>
                <div class="summary-box">
                    <div class="summary-item"><span>Paid Purchases</span><strong><?php echo (int)($totals['paid_count'] ?? 0); ?></strong></div>
                    <div class="summary-item"><span>Partial Purchases</span><strong><?php echo (int)($totals['partial_count'] ?? 0); ?></strong></div>
                    <div class="summary-item"><span>Unpaid Purchases</span><strong><?php echo (int)($totals['unpaid_count'] ?? 0); ?></strong></div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="soft-card mb-4">
                <div class="top-tools mb-3">
                    <div>
                        <h5 class="section-title mb-1">Temporary Purchase Table</h5>
                        <div class="section-subtitle">Add items first, then save full purchase</div>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Purchase No</label>
                            <input name="purchase_no" class="form-control" value="PUR-<?php echo date('Ymd-His'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Supplier</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Tax</label>
                            <input type="number" step="0.01" min="0" name="tax" id="taxInput" class="form-control" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Paid Now</label>
                            <input type="number" step="0.01" min="0" name="paid_now" class="form-control" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="zaad">Zaad</option>
                                <option value="edahab">E-Dahab</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Reference No</label>
                            <input type="text" name="reference_no" class="form-control">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional purchase notes">
                    </div>

                    <div class="table-responsive mt-4">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Total</th>
                                    <th width="185">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cart as $i => $item): ?>
                                    <tr>
                                        <td><?php echo $i + 1; ?></td>
                                        <td class="fw-semibold"><?php echo e($item['product_name']); ?></td>
                                        <td><?php echo e($item['sku']); ?></td>
                                        <td>
                                            <form method="post" class="d-flex gap-2 align-items-center">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="update_item">
                                                <input type="hidden" name="cart_index" value="<?php echo $i; ?>">
                                                <input type="number" step="0.01" min="1" name="qty" class="form-control form-control-sm" value="<?php echo e((string)$item['qty']); ?>">
                                        </td>
                                        <td>
                                                <input type="number" step="0.01" min="0.01" name="unit_cost" class="form-control form-control-sm" value="<?php echo e((string)$item['unit_cost']); ?>">
                                        </td>
                                        <td class="fw-bold"><?php echo money($item['line_total']); ?></td>
                                        <td class="d-flex gap-2">
                                                <button class="btn btn-sm btn-outline-primary">Update</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="remove_item">
                                                <input type="hidden" name="cart_index" value="<?php echo $i; ?>">
                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (!$cart): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-box">No products added yet.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <div class="totals-panel">
                                <div class="totals-row"><span>Subtotal</span><strong id="cartSubtotal"><?php echo number_format($cartTotals['subtotal'], 2); ?></strong></div>
                                <div class="totals-row"><span>Tax</span><strong id="cartTax"><?php echo number_format($cartTotals['tax'], 2); ?></strong></div>
                                <div class="totals-row total"><span>Total</span><strong id="cartTotal"><?php echo number_format($cartTotals['total'], 2); ?></strong></div>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex flex-column gap-2 justify-content-end">
                            <button type="submit" name="action" value="save_purchase" class="btn btn-primary btn-lg">Save Purchase</button>
                            <button type="submit" name="action" value="clear_cart" class="btn btn-outline-danger">Clear Table</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="soft-card">
                <div class="top-tools mb-3">
                    <div>
                        <h5 class="section-title mb-1">Purchase History</h5>
                        <div class="section-subtitle">Search, filter, print, and add supplier payments</div>
                    </div>
                </div>

                <form class="row g-2 mb-4">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control" placeholder="Search purchase no / supplier" value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="paid" <?php echo $statusFilter==='paid'?'selected':''; ?>>Paid</option>
                            <option value="partial" <?php echo $statusFilter==='partial'?'selected':''; ?>>Partial</option>
                            <option value="unpaid" <?php echo $statusFilter==='unpaid'?'selected':''; ?>>Unpaid</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="supplier_id" class="form-select">
                            <option value="0">All Suppliers</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>" <?php echo $supplierFilter===(int)$s['id']?'selected':''; ?>>
                                    <?php echo e($s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><input type="date" name="from" class="form-control" value="<?php echo e($from); ?>"></div>
                    <div class="col-md-2"><input type="date" name="to" class="form-control" value="<?php echo e($to); ?>"></div>
                    <div class="col-12"><button class="btn btn-outline-primary">Filter</button></div>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Purchase No</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Due</th>
                                <th>Status</th>
                                <th width="240">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo e($r['purchase_no']); ?></td>
                                    <td><?php echo e($r['purchase_date']); ?></td>
                                    <td><?php echo e($r['supplier_name']); ?></td>
                                    <td><small><?php echo e($r['item_summary']); ?></small></td>
                                    <td><?php echo money($r['total_amount']); ?></td>
                                    <td><?php echo money($r['paid_amount']); ?></td>
                                    <td><?php echo money($r['due_amount']); ?></td>
                                    <td>
                                        <?php if ($r['status'] === 'paid'): ?>
                                            <span class="status-pill status-paid">Paid</span>
                                        <?php elseif ($r['status'] === 'partial'): ?>
                                            <span class="status-pill status-partial">Partial</span>
                                        <?php else: ?>
                                            <span class="status-pill status-unpaid">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-flex gap-2 flex-wrap">
                                        <a href="purchases.php?print=<?php echo (int)$r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">Print</a>

                                        <?php if ((float)$r['due_amount'] > 0): ?>
                                            <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#payModal<?php echo (int)$r['id']; ?>">
                                                Add Payment
                                            </button>
                                        <?php else: ?>
                                            <span class="text-success small fw-bold">Complete</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-box">No purchases found.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($rows as $r): ?>
        <?php
        if ((float)$r['due_amount'] <= 0) continue;
        $paymentRows = $db->query("
            SELECT *
            FROM purchase_payments
            WHERE purchase_id=".(int)$r['id']."
            ORDER BY id DESC
        ")->fetch_all(MYSQLI_ASSOC);
        ?>
        <div class="modal fade" id="payModal<?php echo (int)$r['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <div class="modal-header">
                            <h5 class="modal-title">Add Payment - <?php echo e($r['purchase_no']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="action" value="save_payment">
                            <input type="hidden" name="purchase_id" value="<?php echo (int)$r['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Amount</label>
                                <input type="number" step="0.01" min="0.01" max="<?php echo e((string)$r['due_amount']); ?>" name="amount" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Method</label>
                                <select name="method" class="form-select">
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                    <option value="zaad">Zaad</option>
                                    <option value="edahab">E-Dahab</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Reference No</label>
                                <input type="text" name="reference_no" class="form-control">
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Notes</label>
                                <textarea name="payment_notes" class="form-control" rows="3"></textarea>
                            </div>

                            <?php if ($paymentRows): ?>
                                <hr>
                                <div class="fw-bold mb-2">Payment History</div>
                                <div class="small-note">
                                    <?php foreach ($paymentRows as $pr): ?>
                                        <div class="mb-1">
                                            <?php echo e($pr['payment_date']); ?> —
                                            <?php echo money($pr['amount']); ?> —
                                            <?php echo e(ucfirst($pr['method'])); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-primary">Save Payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title">Add New Product</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="add_new_product">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Product Name</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">SKU</label>
                            <input type="text" name="sku" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Category</label>
                            <input type="text" name="category" class="form-control">
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Cost Price</label>
                                <input type="number" step="0.01" min="0" name="cost_price" class="form-control" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Selling Price</label>
                                <input type="number" step="0.01" min="0" name="price" class="form-control" value="0">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Unit</label>
                                <input type="text" name="unit" class="form-control" value="pcs">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Min Stock</label>
                                <input type="number" step="0.01" min="0" name="min_stock" class="form-control" value="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Opening Qty</label>
                                <input type="number" step="0.01" min="0" name="opening_qty" class="form-control" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-success">Save Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const productsData = <?php echo $productJson; ?>;
const searchInput = document.getElementById('productSearch');
const resultsBox = document.getElementById('searchResults');
const selectedBox = document.getElementById('selectedProductBox');
const selectedProductId = document.getElementById('selected_product_id');
const selectedUnitCost = document.getElementById('selected_unit_cost');
const taxInput = document.getElementById('taxInput');

const spName = document.getElementById('sp_name');
const spSku = document.getElementById('sp_sku');
const spCost = document.getElementById('sp_cost');

const cartSubtotalEl = document.getElementById('cartSubtotal');
const cartTaxEl = document.getElementById('cartTax');
const cartTotalEl = document.getElementById('cartTotal');
const initialSubtotal = <?php echo json_encode((float)$cartTotals['subtotal']); ?>;

function moneyFormat(val) {
    return Number(val).toFixed(2);
}

function updateCartTotals() {
    const tax = Number(taxInput ? taxInput.value : 0) || 0;
    const total = initialSubtotal + tax;

    if (cartSubtotalEl) cartSubtotalEl.textContent = moneyFormat(initialSubtotal);
    if (cartTaxEl) cartTaxEl.textContent = moneyFormat(tax);
    if (cartTotalEl) cartTotalEl.textContent = moneyFormat(total);
}

function renderResults(list) {
    resultsBox.innerHTML = '';

    if (!list.length) {
        resultsBox.style.display = 'none';
        return;
    }

    list.forEach(product => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action';
        item.innerHTML = `
            <div class="fw-semibold">${product.name}</div>
            <small class="text-muted">SKU: ${product.sku || '-'} | Cost: ${moneyFormat(product.cost_price || 0)}</small>
        `;
        item.addEventListener('click', function () {
            selectedProductId.value = product.id;
            selectedUnitCost.value = moneyFormat(product.cost_price || 0);

            spName.textContent = product.name;
            spSku.textContent = product.sku || '-';
            spCost.textContent = moneyFormat(product.cost_price || 0);

            selectedBox.style.display = 'block';
            searchInput.value = product.name;
            resultsBox.style.display = 'none';
        });
        resultsBox.appendChild(item);
    });

    resultsBox.style.display = 'block';
}

if (searchInput) {
    searchInput.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();

        if (q.length < 1) {
            resultsBox.style.display = 'none';
            return;
        }

        const filtered = productsData.filter(product =>
            (product.name && product.name.toLowerCase().includes(q)) ||
            (product.sku && product.sku.toLowerCase().includes(q))
        ).slice(0, 12);

        renderResults(filtered);
    });
}

document.addEventListener('click', function (e) {
    if (resultsBox && !resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.style.display = 'none';
    }
});

const addItemForm = document.getElementById('addItemForm');
if (addItemForm) {
    addItemForm.addEventListener('submit', function (e) {
        if (!selectedProductId.value) {
            e.preventDefault();
            alert('Marka hore product dooro.');
        }
    });
}

if (taxInput) {
    taxInput.addEventListener('input', updateCartTotals);
    updateCartTotals();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>