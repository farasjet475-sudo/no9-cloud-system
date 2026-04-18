<?php
$pageTitle = 'Stock Transfer';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) redirect('dashboard.php');
if (!is_company_admin()) {
    flash('error', 'Only company admin can manage stock transfers.');
    redirect('dashboard.php');
}

$cid = current_company_id();
$db  = db();
$error = '';
$message = '';

/* ========================
   ENSURE TABLE EXISTS
======================== */
$db->query("
CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    from_branch_id INT NOT NULL,
    to_branch_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_by INT NULL,
    approved_by INT NULL,
    approved_at DATETIME NULL,
    cancelled_by INT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

/* ========================
   SAFE HELPERS
======================== */
function st_badge_class($status){
    if ($status === 'approved') return 'bg-success-subtle text-success';
    if ($status === 'cancelled') return 'bg-danger-subtle text-danger';
    return 'bg-warning-subtle text-warning';
}

/* ========================
   CREATE REQUEST
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_transfer') {
        $from  = (int)($_POST['from_branch_id'] ?? 0);
        $to    = (int)($_POST['to_branch_id'] ?? 0);
        $pid   = (int)($_POST['product_id'] ?? 0);
        $qty   = (float)($_POST['qty'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($from <= 0 || $to <= 0 || $pid <= 0) {
            $error = 'Please select source branch, destination branch, and product.';
        } elseif ($from === $to) {
            $error = 'Source and destination branch cannot be the same.';
        } elseif ($qty <= 0) {
            $error = 'Quantity must be greater than zero.';
        } else {
            $stmt = $db->prepare("
                SELECT *
                FROM products
                WHERE id=? AND company_id=? AND branch_id=?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $pid, $cid, $from);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                $error = 'Selected product not found in source branch.';
            } elseif ((float)$product['stock_qty'] < $qty) {
                $error = 'Not enough stock in source branch.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO stock_transfers
                    (company_id, from_branch_id, to_branch_id, product_id, qty, notes, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
                ");
                $createdBy = current_user_id();
                $stmt->bind_param('iiiidsi', $cid, $from, $to, $pid, $qty, $notes, $createdBy);
                $stmt->execute();
                $stmt->close();

                $message = 'Transfer request created successfully.';
            }
        }
    }

    /* ========================
       APPROVE TRANSFER
    ========================= */
    if ($action === 'approve_transfer') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $db->prepare("
            SELECT *
            FROM stock_transfers
            WHERE id=? AND company_id=? AND status='pending'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $id, $cid);
        $stmt->execute();
        $transfer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$transfer) {
            $error = 'Pending transfer not found.';
        } else {
            $from = (int)$transfer['from_branch_id'];
            $to   = (int)$transfer['to_branch_id'];
            $pid  = (int)$transfer['product_id'];
            $qty  = (float)$transfer['qty'];

            $stmt = $db->prepare("
                SELECT *
                FROM products
                WHERE id=? AND company_id=? AND branch_id=?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $pid, $cid, $from);
            $stmt->execute();
            $sourceProduct = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sourceProduct) {
                $error = 'Source product not found.';
            } elseif ((float)$sourceProduct['stock_qty'] < $qty) {
                $error = 'Not enough stock to approve this transfer.';
            } else {
                $db->begin_transaction();

                try {
                    $referenceNo = 'TRF-' . $id;
                    $name        = (string)$sourceProduct['name'];
                    $sku         = (string)($sourceProduct['sku'] ?? '');
                    $barcode     = (string)($sourceProduct['barcode'] ?? '');
                    $code        = (string)($sourceProduct['code'] ?? '');
                    $brand       = (string)($sourceProduct['brand'] ?? '');
                    $category    = (string)($sourceProduct['category'] ?? '');
                    $price       = (float)($sourceProduct['price'] ?? 0);
                    $costPrice   = (float)($sourceProduct['cost_price'] ?? 0);
                    $minStock    = (float)($sourceProduct['min_stock'] ?? 0);
                    $unit        = (string)($sourceProduct['unit'] ?? 'pcs');
                    $description = (string)($sourceProduct['description'] ?? '');
                    $imagePath   = (string)($sourceProduct['image_path'] ?? '');
                    $reorder     = (float)($sourceProduct['reorder_level'] ?? 0);

                    // deduct from source
                    $stmt = $db->prepare("
                        UPDATE products
                        SET stock_qty = stock_qty - ?
                        WHERE id=? AND company_id=? AND branch_id=? AND stock_qty >= ?
                    ");
                    $stmt->bind_param('diiid', $qty, $pid, $cid, $from, $qty);
                    $stmt->execute();
                    if ($stmt->affected_rows <= 0) {
                        $stmt->close();
                        throw new Exception('Failed to deduct source stock.');
                    }
                    $stmt->close();

                    // destination check
                    $stmt = $db->prepare("
                        SELECT id
                        FROM products
                        WHERE company_id=? AND branch_id=? AND (
                            (sku <> '' AND sku=?) OR
                            (barcode <> '' AND barcode=?) OR
                            (code <> '' AND code=?) OR
                            name=?
                        )
                        LIMIT 1
                    ");
                    $stmt->bind_param('iissss', $cid, $to, $sku, $barcode, $code, $name);
                    $stmt->execute();
                    $dest = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($dest) {
                        $destProductId = (int)$dest['id'];

                        $stmt = $db->prepare("
                            UPDATE products
                            SET stock_qty = stock_qty + ?
                            WHERE id=? AND company_id=?
                        ");
                        $stmt->bind_param('dii', $qty, $destProductId, $cid);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO products
                            (company_id, branch_id, name, sku, category, price, cost_price, stock_qty, min_stock, unit, description, image_path, barcode, code, brand, reorder_level, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->bind_param(
                            'iisssddddssssssd',
                            $cid,
                            $to,
                            $name,
                            $sku,
                            $category,
                            $price,
                            $costPrice,
                            $qty,
                            $minStock,
                            $unit,
                            $description,
                            $imagePath,
                            $barcode,
                            $code,
                            $brand,
                            $reorder
                        );
                        $stmt->execute();
                        $destProductId = $stmt->insert_id;
                        $stmt->close();
                    }

                    // optional stock movements
                    $hasStockMovements = $db->query("SHOW TABLES LIKE 'stock_movements'");
                    if ($hasStockMovements && $hasStockMovements->num_rows > 0) {
                        $approvedBy = current_user_id();

                        $stmt = $db->prepare("
                            INSERT INTO stock_movements
                            (company_id, branch_id, product_id, transaction_type, reference_no, qty_in, qty_out, unit_cost, notes, created_by)
                            VALUES (?, ?, ?, 'TRANSFER_OUT', ?, 0, ?, ?, ?, ?)
                        ");
                        $noteOut = 'Approved transfer OUT';
                        $stmt->bind_param('iiisddsi', $cid, $from, $pid, $referenceNo, $qty, $costPrice, $noteOut, $approvedBy);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $db->prepare("
                            INSERT INTO stock_movements
                            (company_id, branch_id, product_id, transaction_type, reference_no, qty_in, qty_out, unit_cost, notes, created_by)
                            VALUES (?, ?, ?, 'TRANSFER_IN', ?, ?, 0, ?, ?, ?)
                        ");
                        $noteIn = 'Approved transfer IN';
                        $stmt->bind_param('iiisddsi', $cid, $to, $destProductId, $referenceNo, $qty, $costPrice, $noteIn, $approvedBy);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // mark approved
                    $stmt = $db->prepare("
                        UPDATE stock_transfers
                        SET status='approved', approved_by=?, approved_at=NOW()
                        WHERE id=? AND company_id=?
                    ");
                    $approvedBy = current_user_id();
                    $stmt->bind_param('iii', $approvedBy, $id, $cid);
                    $stmt->execute();
                    $stmt->close();

                    $db->commit();
                    $message = 'Transfer approved successfully.';
                } catch (Throwable $e) {
                    $db->rollback();
                    $error = 'Approve failed: ' . $e->getMessage();
                }
            }
        }
    }

    /* ========================
       CANCEL TRANSFER
    ========================= */
    if ($action === 'cancel_transfer') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['cancel_reason'] ?? '');

        $stmt = $db->prepare("
            UPDATE stock_transfers
            SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancel_reason=?
            WHERE id=? AND company_id=? AND status='pending'
        ");
        $cancelledBy = current_user_id();
        $stmt->bind_param('isii', $cancelledBy, $reason, $id, $cid);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        if ($ok) {
            $message = 'Transfer cancelled successfully.';
        } else {
            $error = 'Pending transfer not found or already processed.';
        }
    }
}

/* ========================
   LOOKUPS
======================== */
$branches = company_branches();

$products = $db->query("
    SELECT p.id, p.name, p.stock_qty, p.branch_id, b.name AS branch_name
    FROM products p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.company_id = $cid
    ORDER BY p.name ASC
")->fetch_all(MYSQLI_ASSOC);

$rows = $db->query("
    SELECT
        t.*,
        p.name AS product_name,
        fb.name AS from_branch_name,
        tb.name AS to_branch_name,
        cu.full_name AS created_by_name,
        au.full_name AS approved_by_name,
        xu.full_name AS cancelled_by_name
    FROM stock_transfers t
    LEFT JOIN products p ON p.id = t.product_id
    LEFT JOIN branches fb ON fb.id = t.from_branch_id
    LEFT JOIN branches tb ON tb.id = t.to_branch_id
    LEFT JOIN users cu ON cu.id = t.created_by
    LEFT JOIN users au ON au.id = t.approved_by
    LEFT JOIN users xu ON xu.id = t.cancelled_by
    WHERE t.company_id = $cid
    ORDER BY t.id DESC
")->fetch_all(MYSQLI_ASSOC);

$totalTransfers = count($rows);
$pendingCount = 0;
$approvedCount = 0;
$cancelledCount = 0;

foreach ($rows as $r) {
    if ($r['status'] === 'pending') $pendingCount++;
    elseif ($r['status'] === 'approved') $approvedCount++;
    elseif ($r['status'] === 'cancelled') $cancelledCount++;
}
?>

<style>
.transfer-shell{display:grid;gap:18px}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    box-shadow:0 16px 36px rgba(15,23,42,.18);
    padding:26px;
}
.hero-card h3{margin:0;font-weight:800}
.hero-card p{margin:6px 0 0;color:rgba(255,255,255,.82)}
.soft-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.kpi-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{opacity:.92;font-size:.84rem}
.kpi-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-3{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-4{background:linear-gradient(135deg,#ef4444,#dc2626)}
.section-title{font-weight:800;margin-bottom:6px}
.section-sub{color:#64748b;font-size:14px}
.form-label{font-weight:700}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
}
.status-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.meta-text{font-size:12px;color:#64748b}
</style>

<div class="container-fluid py-4 transfer-shell">
    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Stock Transfer Pro</h3>
                <p>Create pending requests, approve them safely, or cancel them before stock is moved.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Transfers</small>
                <h3><?php echo $totalTransfers; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Pending</small>
                <h3><?php echo $pendingCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Approved</small>
                <h3><?php echo $approvedCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Cancelled</small>
                <h3><?php echo $cancelledCount; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="soft-card p-4">
                <div class="section-title">Create Transfer Request</div>
                <div class="section-sub mb-3">Stock will move only after approval.</div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_transfer">

                    <div class="mb-3">
                        <label class="form-label">From Branch</label>
                        <select name="from_branch_id" class="form-select" required>
                            <option value="">Select source branch</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">To Branch</label>
                        <select name="to_branch_id" class="form-select" required>
                            <option value="">Select destination branch</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Select product row</option>
                            <?php foreach($products as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>">
                                    <?php echo e($p['name']); ?> — <?php echo e($p['branch_name']); ?> (Stock: <?php echo number_format((float)$p['stock_qty'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="0.01" min="0.01" name="qty" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>

                    <button class="btn btn-primary w-100">Create Request</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4">
                <div class="section-title">Transfer History</div>
                <div class="section-sub mb-3">Pending requests can be approved or cancelled.</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>By</th>
                                    <th>Notes</th>
                                    <th width="220">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows as $r): ?>
                                    <tr>
                                        <td><?php echo e($r['created_at']); ?></td>
                                        <td><?php echo e($r['product_name']); ?></td>
                                        <td><?php echo e($r['from_branch_name']); ?></td>
                                        <td><?php echo e($r['to_branch_name']); ?></td>
                                        <td><?php echo number_format((float)$r['qty'], 2); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo st_badge_class($r['status']); ?>">
                                                <?php echo e(ucfirst($r['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo e($r['created_by_name'] ?? '-'); ?></div>
                                            <?php if (!empty($r['approved_by_name'])): ?>
                                                <div class="meta-text">Approved: <?php echo e($r['approved_by_name']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['cancelled_by_name'])): ?>
                                                <div class="meta-text">Cancelled: <?php echo e($r['cancelled_by_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e($r['notes'] ?? ''); ?>
                                            <?php if (!empty($r['cancel_reason'])): ?>
                                                <div class="meta-text">Reason: <?php echo e($r['cancel_reason']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($r['status'] === 'pending'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="approve_transfer">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <button class="btn btn-sm btn-success">Approve</button>
                                                </form>

                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="cancel_transfer">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <input type="hidden" name="cancel_reason" value="Cancelled by admin">
                                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">Completed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-5">No transfer records found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>