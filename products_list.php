<?php
$pageTitle = 'Products List';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    redirect('dashboard.php');
}

if (function_exists('require_module_read')) {
    require_module_read('products');
}

$db = db();
$cid = current_company_id();
$isAdmin = is_company_admin();
$canEditProducts = $isAdmin || (function_exists('can_update') && can_update('products'));
$canDeleteProducts = $isAdmin || (function_exists('can_delete') && can_delete('products'));

/* ========================
   BRANCH ACCESS
======================== */
$userBranchId = function_exists('enforce_user_branch_scope')
    ? enforce_user_branch_scope(current_branch_id())
    : current_branch_id();

/* ========================
   DELETE PRODUCT
======================== */
if (isset($_GET['delete']) && $canDeleteProducts) {
    $deleteId = (int)($_GET['delete'] ?? 0);

    $sql = "
        SELECT *
        FROM products
        WHERE id = ?
          AND company_id = ?
    ";

    if (!$isAdmin) {
        $sql .= " AND branch_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);

    if ($isAdmin) {
        $stmt->bind_param('ii', $deleteId, $cid);
    } else {
        $stmt->bind_param('iii', $deleteId, $cid, $userBranchId);
    }

    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        flash('error', 'Product not found or access denied.');
        redirect('products_list.php');
    }

    $stmt = $db->prepare("DELETE FROM products WHERE id = ? AND company_id = ?");
    $stmt->bind_param('ii', $deleteId, $cid);
    $stmt->execute();
    $stmt->close();

    if (function_exists('log_activity')) {
        log_activity('delete', 'products', $deleteId);
    }

    flash('success', 'Product deleted successfully.');
    redirect('products_list.php');
}

/* ========================
   UPDATE PRODUCT
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action = trim($_POST['action'] ?? '');

    if ($action === 'update_product' && $canEditProducts) {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $barcode    = trim($_POST['barcode'] ?? '');
        $sku        = trim($_POST['sku'] ?? '');
        $brand      = trim($_POST['brand'] ?? '');
        $category   = trim($_POST['category'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $price      = (float)($_POST['price'] ?? 0);
        $costPrice  = (float)($_POST['cost_price'] ?? 0);
        $stockQty   = (float)($_POST['stock_qty'] ?? 0);
        $branchId   = (int)($_POST['branch_id'] ?? 0);

        if ($name === '') {
            flash('error', 'Product name is required.');
            redirect('products_list.php');
        }

        if ($price < 0 || $costPrice < 0 || $stockQty < 0) {
            flash('error', 'Price, cost price, and stock must be valid values.');
            redirect('products_list.php');
        }

        if ($isAdmin) {
            if ($branchId <= 0) {
                flash('error', 'Please select a branch.');
                redirect('products_list.php');
            }
        } else {
            $branchId = $userBranchId;
        }

        if (!$isAdmin && function_exists('enforce_user_branch_scope')) {
            $branchId = enforce_user_branch_scope($branchId);
        }

        $sql = "
            SELECT *
            FROM products
            WHERE id = ?
              AND company_id = ?
        ";

        if (!$isAdmin) {
            $sql .= " AND branch_id = ?";
        }

        $sql .= " LIMIT 1";

        $stmt = $db->prepare($sql);

        if ($isAdmin) {
            $stmt->bind_param('ii', $id, $cid);
        } else {
            $stmt->bind_param('iii', $id, $cid, $userBranchId);
        }

        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            flash('error', 'Product not found or access denied.');
            redirect('products_list.php');
        }

        $stmt = $db->prepare("
            UPDATE products
            SET
                name = ?,
                barcode = ?,
                sku = ?,
                brand = ?,
                category = ?,
                unit = ?,
                price = ?,
                cost_price = ?,
                stock_qty = ?,
                branch_id = ?
            WHERE id = ?
              AND company_id = ?
        ");
        $stmt->bind_param(
            'ssssssdddiii',
            $name,
            $barcode,
            $sku,
            $brand,
            $category,
            $unit,
            $price,
            $costPrice,
            $stockQty,
            $branchId,
            $id,
            $cid
        );
        $stmt->execute();
        $stmt->close();

        if (function_exists('log_activity')) {
            log_activity('update', 'products', $id);
        }

        flash('success', 'Product updated successfully.');
        redirect('products_list.php');
    }
}

/* ========================
   EDIT PRODUCT
======================== */
$edit = null;
if (isset($_GET['edit']) && $canEditProducts) {
    $editId = (int)($_GET['edit'] ?? 0);

    $sql = "
        SELECT *
        FROM products
        WHERE id = ?
          AND company_id = ?
    ";

    if (!$isAdmin) {
        $sql .= " AND branch_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);

    if ($isAdmin) {
        $stmt->bind_param('ii', $editId, $cid);
    } else {
        $stmt->bind_param('iii', $editId, $cid, $userBranchId);
    }

    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ========================
   BRANCHES
======================== */
$branches = [];
$stmt = $db->prepare("
    SELECT id, name
    FROM branches
    WHERE company_id = ?
    ORDER BY name ASC
");
$stmt->bind_param('i', $cid);
$stmt->execute();
$branches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   FILTERS
======================== */
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$categoryFilter = trim($_GET['category'] ?? '');

$where = ["p.company_id = ?"];
$params = [$cid];
$types = 'i';

if (!$isAdmin) {
    $where[] = "p.branch_id = ?";
    $params[] = $userBranchId;
    $types .= 'i';
} elseif ($branchFilter > 0) {
    $where[] = "p.branch_id = ?";
    $params[] = $branchFilter;
    $types .= 'i';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = "(p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($categoryFilter !== '') {
    $where[] = "p.category = ?";
    $params[] = $categoryFilter;
    $types .= 's';
}

/* ========================
   CATEGORY LIST
======================== */
$catSql = "
    SELECT DISTINCT category
    FROM products
    WHERE company_id = ?
      AND category IS NOT NULL
      AND category <> ''
";
$catParams = [$cid];
$catTypes = 'i';

if (!$isAdmin) {
    $catSql .= " AND branch_id = ?";
    $catParams[] = $userBranchId;
    $catTypes .= 'i';
}

$catSql .= " ORDER BY category ASC";

$stmt = $db->prepare($catSql);
$stmt->bind_param($catTypes, ...$catParams);
$stmt->execute();
$categoryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   PRODUCTS LIST
======================== */
$sql = "
    SELECT
        p.*,
        b.name AS branch_name
    FROM products p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.id DESC
";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   KPI
======================== */
$totalProducts = count($rows);
$lowStockCount = 0;
$totalStockQty = 0;
$totalStockValue = 0;

foreach ($rows as $r) {
    $qty = (float)($r['stock_qty'] ?? 0);
    $cost = (float)($r['cost_price'] ?? 0);

    $totalStockQty += $qty;
    $totalStockValue += ($qty * $cost);

    if ($qty <= 5) {
        $lowStockCount++;
    }
}
?>

<style>
.products-page{display:grid;gap:20px}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    padding:24px;
    box-shadow:0 16px 36px rgba(15,23,42,.14);
}
.hero-card h2{margin:0;font-size:28px;font-weight:800}
.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.82)}
.hero-actions .btn{border-radius:12px;font-weight:700}

.stat-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.stat-card small{display:block;opacity:.9}
.stat-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.bg1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.bg2{background:linear-gradient(135deg,#10b981,#059669)}
.bg3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.bg4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}

.panel-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:22px}
.panel-title{font-size:20px;font-weight:800;color:#0f172a;margin-bottom:4px}
.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}

.form-label{font-weight:700;color:#334155}
.form-control,.form-select{
    min-height:46px;
    border-radius:12px;
    border:1px solid #dbe2ea;
}
.form-control:focus,.form-select:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}
.btn{border-radius:12px;font-weight:700}
.action-btn{border-radius:10px}

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
.small-muted{color:#64748b;font-size:12px}
.branch-badge,
.category-badge,
.stock-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.branch-badge{background:#eff6ff;color:#1d4ed8}
.category-badge{background:#ede9fe;color:#6d28d9}
.stock-ok{background:#dcfce7;color:#166534}
.stock-low{background:#fee2e2;color:#b91c1c}
.edit-box{
    border-top:1px solid #e5e7eb;
    margin-top:16px;
    padding-top:16px;
}
</style>

<div class="container-fluid py-4 products-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Products List</h2>
                <p>
                    <?php if ($isAdmin): ?>
                        View, edit, and delete company products across branches.
                    <?php else: ?>
                        View products from your assigned branch only.
                    <?php endif; ?>
                </p>
            </div>
            <div class="hero-actions d-flex gap-2 flex-wrap">
                <a href="products.php" class="btn btn-light">Add Product</a>
                <a href="dashboard.php" class="btn btn-light">Back</a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="stat-card bg1">
                <small>Total Products</small>
                <h3><?php echo (int)$totalProducts; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg2">
                <small>Total Stock Qty</small>
                <h3><?php echo number_format($totalStockQty, 2); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg3">
                <small>Low Stock Items</small>
                <h3><?php echo (int)$lowStockCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg4">
                <small>Stock Value</small>
                <h3><?php echo money($totalStockValue); ?></h3>
            </div>
        </div>
    </div>

    <?php if ($edit && $canEditProducts): ?>
    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Edit Product</div>
            <div class="panel-sub">Update product information below.</div>

            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">

                <div class="col-md-6">
                    <label class="form-label">Product Name</label>
                    <input name="name" class="form-control" required value="<?php echo e($edit['name'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Barcode</label>
                    <input name="barcode" class="form-control" value="<?php echo e($edit['barcode'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">SKU</label>
                    <input name="sku" class="form-control" value="<?php echo e($edit['sku'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Brand</label>
                    <input name="brand" class="form-control" value="<?php echo e($edit['brand'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <input name="category" class="form-control" value="<?php echo e($edit['category'] ?? ''); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Unit</label>
                    <input name="unit" class="form-control" value="<?php echo e($edit['unit'] ?? ''); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Selling Price</label>
                    <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?php echo e((string)($edit['price'] ?? 0)); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Cost Price</label>
                    <input type="number" step="0.01" min="0" name="cost_price" class="form-control" value="<?php echo e((string)($edit['cost_price'] ?? 0)); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Stock Qty</label>
                    <input type="number" step="0.01" min="0" name="stock_qty" class="form-control" value="<?php echo e((string)($edit['stock_qty'] ?? 0)); ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Branch</label>
                    <?php if ($isAdmin): ?>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select branch</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)($edit['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?php echo (int)$userBranchId; ?>">
                        <input class="form-control" disabled value="<?php
                            $branchName = '';
                            foreach ($branches as $b) {
                                if ((int)$b['id'] === (int)$userBranchId) {
                                    $branchName = $b['name'];
                                    break;
                                }
                            }
                            echo e($branchName);
                        ?>">
                    <?php endif; ?>
                </div>

                <div class="col-12 d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary">Update Product</button>
                    <a href="products_list.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Search Products</div>
            <div class="panel-sub">Search by name, barcode, sku, or brand.</div>

            <form class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?php echo e($search); ?>">
                </div>

                <?php if ($isAdmin): ?>
                <div class="col-md-3">
                    <select name="branch_id" class="form-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                                <?php echo e($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categoryRows as $cat): ?>
                            <option value="<?php echo e($cat['category']); ?>" <?php echo $categoryFilter === $cat['category'] ? 'selected' : ''; ?>>
                                <?php echo e($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-1">
                    <button class="btn btn-dark w-100">Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Products Table</div>
            <div class="panel-sub">
                <?php if ($isAdmin): ?>
                    Admin can edit and delete products from here.
                <?php else: ?>
                    You are viewing products from your assigned branch only.
                <?php endif; ?>
            </div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Barcode / SKU</th>
                                <th>Category</th>
                                <th>Branch</th>
                                <th>Prices</th>
                                <th>Stock</th>
                                <th width="180"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php $qty = (float)($r['stock_qty'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo e($r['name']); ?></div>
                                        <div class="small-muted">
                                            <?php echo e($r['brand'] ?? ''); ?>
                                            <?php if (!empty($r['unit'])): ?>
                                                | Unit: <?php echo e($r['unit']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div><?php echo e($r['barcode'] ?? '-'); ?></div>
                                        <div class="small-muted">SKU: <?php echo e($r['sku'] ?? '-'); ?></div>
                                    </td>

                                    <td>
                                        <span class="category-badge"><?php echo e($r['category'] ?: 'Uncategorized'); ?></span>
                                    </td>

                                    <td>
                                        <span class="branch-badge"><?php echo e($r['branch_name'] ?: 'No Branch'); ?></span>
                                    </td>

                                    <td>
                                        <div>Selling: <strong><?php echo money($r['price'] ?? 0); ?></strong></div>
                                        <div class="small-muted">Cost: <?php echo money($r['cost_price'] ?? 0); ?></div>
                                    </td>

                                    <td>
                                        <span class="stock-badge <?php echo $qty <= 5 ? 'stock-low' : 'stock-ok'; ?>">
                                            <?php echo number_format($qty, 2); ?>
                                        </span>
                                    </td>

                                    <td class="text-end">
                                        <?php if ($canEditProducts): ?>
                                            <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>
                                        <?php endif; ?>

                                        <?php if ($canDeleteProducts): ?>
                                            <a href="?delete=<?php echo (int)$r['id']; ?>"
                                               onclick="return confirm('Delete this product?')"
                                               class="btn btn-sm btn-outline-danger action-btn">
                                                Delete
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">No products found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>