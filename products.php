<?php
$pageTitle = 'Products / Inventory';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    flash('error', 'This page is for company users only.');
    redirect('dashboard.php');
}

$cid = current_company_id();
$bid = current_branch_id();
$db = db();
$isAdmin = is_company_admin();

$message = '';
$error = '';

if (!defined('UPLOAD_PRODUCTS')) {
    define('UPLOAD_PRODUCTS', __DIR__ . '/uploads/products/');
}

if (!is_dir(UPLOAD_PRODUCTS)) {
    @mkdir(UPLOAD_PRODUCTS, 0777, true);
}

/* ========================
   OPTIONAL XLSX SUPPORT
======================== */
$spreadsheetAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($spreadsheetAutoload)) {
    require_once $spreadsheetAutoload;
}

function product_import_ext(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function product_import_rows(string $tmpFile, string $originalName): array {
    $ext = product_import_ext($originalName);

    if ($ext === 'csv') {
        $rows = [];
        if (($handle = fopen($tmpFile, 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
        }
        return $rows;
    }

    if (($ext === 'xlsx' || $ext === 'xls')) {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new RuntimeException('XLSX/XLS import requires PhpSpreadsheet. Run: composer require phpoffice/phpspreadsheet');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
        return $spreadsheet->getActiveSheet()->toArray();
    }

    throw new RuntimeException('Unsupported file type. Please upload CSV, XLSX, or XLS.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $id            = (int)($_POST['id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $sku           = trim($_POST['sku'] ?? '');
        $barcode       = trim($_POST['barcode'] ?? '');
        $code          = trim($_POST['code'] ?? '');
        $brand         = trim($_POST['brand'] ?? '');
        $category      = trim($_POST['category'] ?? '');
        $branch_id     = (int)($_POST['branch_id'] ?? 0);
        $price         = (float)($_POST['price'] ?? 0);
        $cost_price    = (float)($_POST['cost_price'] ?? 0);
        $stock_qty     = (float)($_POST['stock_qty'] ?? 0);
        $unit          = trim($_POST['unit'] ?? 'pcs');
        $description   = trim($_POST['description'] ?? '');
        $min_stock     = (float)($_POST['min_stock'] ?? 5);
        $reorder_level = (float)($_POST['reorder_level'] ?? 0);

        if ($branch_id <= 0) $branch_id = $bid;

        if ($name === '') {
            $error = 'Product name is required.';
        } elseif ($price < 0 || $cost_price < 0 || $stock_qty < 0 || $min_stock < 0 || $reorder_level < 0) {
            $error = 'Selling price, buying price, stock, min stock, and reorder level must be valid numbers.';
        } else {
            $imageName = null;

            if (!empty($_FILES['image']['name'])) {
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (!in_array($ext, $allowed, true)) {
                    $error = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
                } else {
                    $imageName = uniqid('prod_', true) . '.' . $ext;
                    $target = UPLOAD_PRODUCTS . $imageName;

                    if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $error = 'Image upload failed.';
                    }
                }
            }

            if ($error === '') {
                if ($id > 0) {
                    $stmt = $db->prepare("SELECT image_path FROM products WHERE id = ? AND company_id = ? LIMIT 1");
                    $stmt->bind_param("ii", $id, $cid);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$existing) {
                        $error = 'Product not found.';
                    } else {
                        $finalImage = $imageName ?: ($existing['image_path'] ?? null);

                        $stmt = $db->prepare("
                            UPDATE products
                            SET branch_id=?, name=?, sku=?, barcode=?, code=?, brand=?, category=?, price=?, cost_price=?, stock_qty=?, unit=?, description=?, image_path=?, min_stock=?, reorder_level=?
                            WHERE id=? AND company_id=?
                        ");
                        $stmt->bind_param(
                            "issssssdddsssddii",
                            $branch_id,
                            $name,
                            $sku,
                            $barcode,
                            $code,
                            $brand,
                            $category,
                            $price,
                            $cost_price,
                            $stock_qty,
                            $unit,
                            $description,
                            $finalImage,
                            $min_stock,
                            $reorder_level,
                            $id,
                            $cid
                        );
                        $stmt->execute();
                        $stmt->close();

                        $message = 'Product updated successfully.';
                    }
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO products (
                            company_id, branch_id, name, sku, category, price, cost_price, stock_qty, min_stock, unit, description, image_path, barcode, code, brand, reorder_level, created_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param(
                        "iisssddddssssssd",
                        $cid,
                        $branch_id,
                        $name,
                        $sku,
                        $category,
                        $price,
                        $cost_price,
                        $stock_qty,
                        $min_stock,
                        $unit,
                        $description,
                        $imageName,
                        $barcode,
                        $code,
                        $brand,
                        $reorder_level
                    );
                    $stmt->execute();
                    $stmt->close();

                    $message = 'Product added successfully.';
                }
            }
        }
    }

    if ($action === 'import_products') {
        try {
            if (empty($_FILES['import_file']['name'])) {
                throw new RuntimeException('Please choose an import file.');
            }

            $rows = product_import_rows($_FILES['import_file']['tmp_name'], $_FILES['import_file']['name']);
            if (!$rows) {
                throw new RuntimeException('Import file is empty.');
            }

            $inserted = 0;
            $updated  = 0;
            $skipped  = 0;

            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    $first = strtolower(trim((string)($row[0] ?? '')));
                    if (in_array($first, ['name', 'product name', 'product'], true)) {
                        continue;
                    }
                }

                $name          = trim((string)($row[0] ?? ''));
                $sku           = trim((string)($row[1] ?? ''));
                $barcode       = trim((string)($row[2] ?? ''));
                $code          = trim((string)($row[3] ?? ''));
                $brand         = trim((string)($row[4] ?? ''));
                $category      = trim((string)($row[5] ?? ''));
                $price         = (float)($row[6] ?? 0);
                $cost_price    = (float)($row[7] ?? 0);
                $stock_qty     = (float)($row[8] ?? 0);
                $min_stock     = (float)($row[9] ?? 0);
                $reorder_level = (float)($row[10] ?? 0);
                $unit          = trim((string)($row[11] ?? 'pcs'));
                $description   = trim((string)($row[12] ?? ''));
                $branch_id     = (int)($row[13] ?? $bid);

                if ($name === '') {
                    $skipped++;
                    continue;
                }

                if ($branch_id <= 0) {
                    $branch_id = $bid;
                }

                $stmt = $db->prepare("
                    SELECT id
                    FROM products
                    WHERE company_id=? AND (
                        (sku <> '' AND sku=?) OR
                        (barcode <> '' AND barcode=?) OR
                        (code <> '' AND code=?) OR
                        (name=? AND branch_id=?)
                    )
                    LIMIT 1
                ");
                $stmt->bind_param("issssi", $cid, $sku, $barcode, $code, $name, $branch_id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    $productId = (int)$existing['id'];

                    $stmt = $db->prepare("
                        UPDATE products
                        SET branch_id=?, name=?, sku=?, barcode=?, code=?, brand=?, category=?, price=?, cost_price=?, stock_qty=stock_qty + ?, unit=?, description=?, min_stock=?, reorder_level=?
                        WHERE id=? AND company_id=?
                    ");
                    $stmt->bind_param(
                        "issssssdddssddii",
                        $branch_id,
                        $name,
                        $sku,
                        $barcode,
                        $code,
                        $brand,
                        $category,
                        $price,
                        $cost_price,
                        $stock_qty,
                        $unit,
                        $description,
                        $min_stock,
                        $reorder_level,
                        $productId,
                        $cid
                    );
                    $stmt->execute();
                    $stmt->close();
                    $updated++;
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO products (
                            company_id, branch_id, name, sku, category, price, cost_price, stock_qty, min_stock, unit, description, image_path, barcode, code, brand, reorder_level, created_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param(
                        "iisssddddssssd",
                        $cid,
                        $branch_id,
                        $name,
                        $sku,
                        $category,
                        $price,
                        $cost_price,
                        $stock_qty,
                        $min_stock,
                        $unit,
                        $description,
                        $barcode,
                        $code,
                        $brand,
                        $reorder_level
                    );
                    $stmt->execute();
                    $stmt->close();
                    $inserted++;
                }
            }

            $message = "Import completed. Inserted: {$inserted}, Updated: {$updated}, Skipped: {$skipped}.";
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$branchStmt = $db->prepare("SELECT id, name FROM branches WHERE company_id = ? ORDER BY name ASC");
$branchStmt->bind_param("i", $cid);
$branchStmt->execute();
$branches = $branchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$branchStmt->close();

$editProduct = null;
if ($isAdmin && isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->bind_param("ii", $editId, $cid);
    $stmt->execute();
    $editProduct = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<style>
.soft-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 10px 28px rgba(15,23,42,.06);
}
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
.form-label{font-weight:700}
.bottom-link{
    display:flex;
    justify-content:flex-end;
    margin-top:16px;
}
</style>

<div class="container-fluid py-4">
    <div class="hero-card mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Products / Inventory Setup</h3>
                <p>Add products manually or import from CSV / Excel, then open the full database list page.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="soft-card p-4">
                <h5 class="mb-3"><?= $editProduct ? 'Edit Product' : 'Add Product' ?></h5>

                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="id" value="<?= (int)($editProduct['id'] ?? 0) ?>">

                    <div class="col-md-4">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" class="form-control" required value="<?= e($editProduct['name'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">SKU</label>
                        <input type="text" name="sku" class="form-control" value="<?= e($editProduct['sku'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Barcode</label>
                        <input type="text" name="barcode" class="form-control" value="<?= e($editProduct['barcode'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" class="form-control" value="<?= e($editProduct['code'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand" class="form-control" value="<?= e($editProduct['brand'] ?? '') ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" class="form-control" value="<?= e($editProduct['category'] ?? '') ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Selling Price</label>
                        <input type="number" step="0.01" min="0" name="price" class="form-control" required value="<?= e((string)($editProduct['price'] ?? 0)) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Buying Price</label>
                        <input type="number" step="0.01" min="0" name="cost_price" class="form-control" required value="<?= e((string)($editProduct['cost_price'] ?? 0)) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Stock Qty</label>
                        <input type="number" step="0.01" min="0" name="stock_qty" class="form-control" required value="<?= e((string)($editProduct['stock_qty'] ?? 0)) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Min Stock</label>
                        <input type="number" step="0.01" min="0" name="min_stock" class="form-control" value="<?= e((string)($editProduct['min_stock'] ?? 5)) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" step="0.01" min="0" name="reorder_level" class="form-control" value="<?= e((string)($editProduct['reorder_level'] ?? 0)) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" class="form-control" value="<?= e($editProduct['unit'] ?? 'pcs') ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="0">Select branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int)$branch['id'] ?>" <?= ((int)($editProduct['branch_id'] ?? $bid) === (int)$branch['id']) ? 'selected' : '' ?>>
                                    <?= e($branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Image</label>
                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= e($editProduct['description'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-primary"><?= $editProduct ? 'Update Product' : 'Add Product' ?></button>
                        <?php if ($editProduct): ?>
                            <a href="products.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="soft-card p-4">
                <h5 class="mb-3">Import Products</h5>
                <p class="text-muted small">
                    Supports CSV by default, and XLSX/XLS when PhpSpreadsheet is installed.
                </p>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="import_products">

                    <div class="mb-3">
                        <label class="form-label">Import File</label>
                        <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                    </div>

                    <button class="btn btn-success w-100">Import Products</button>
                </form>

                <hr>

                <div class="small text-muted">
                    <strong>Column order:</strong><br>
                    Name, SKU, Barcode, Code, Brand, Category, Selling Price, Buying Price, Stock Qty, Min Stock, Reorder Level, Unit, Description, Branch ID
                </div>
            </div>

            <div class="bottom-link">
                <a href="products_list.php" class="btn btn-dark mt-3">Open Database Product List</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>