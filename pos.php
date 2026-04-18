<?php
$pageTitle = 'POS';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';
require_once __DIR__ . '/includes/stock_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = function_exists('enforce_sales_branch')
    ? enforce_sales_branch(current_branch_id())
    : current_branch_id();

$db = db();

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

/* =========================
   POS CURRENCY HELPERS
========================= */
if (!function_exists('posx_currency_settings')) {
    function posx_currency_settings(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $primary = 'USD';
        $secondary = 'SLSH';
        $rate = 11000;

        if (function_exists('company_settings')) {
            $s = company_settings();
            $primary = strtoupper(trim((string)($s['currency_primary'] ?? 'USD')));
            $secondary = strtoupper(trim((string)($s['currency_secondary'] ?? 'SLSH')));
            $rate = (float)($s['exchange_rate'] ?? 11000);
        }

        if ($rate <= 0) $rate = 11000;
        if (!in_array($primary, ['SLSH', 'USD'], true)) $primary = 'USD';
        if (!in_array($secondary, ['SLSH', 'USD'], true)) $secondary = ($primary === 'USD' ? 'SLSH' : 'USD');

        $selected = strtoupper(trim((string)($_GET['currency'] ?? $primary)));
        if (!in_array($selected, ['SLSH', 'USD'], true)) $selected = $primary;

        $cache = [
            'primary' => $primary,
            'secondary' => $secondary,
            'rate' => $rate,
            'selected' => $selected,
        ];
        return $cache;
    }
}

if (!function_exists('posx_normalize_to_primary')) {
    function posx_normalize_to_primary(float $value, string $inputCurrency): float {
        $c = posx_currency_settings();
        $primary = strtoupper($c['primary']);
        $rate = (float)$c['rate'];
        $inputCurrency = strtoupper(trim($inputCurrency));

        if ($rate <= 0) $rate = 11000;

        if ($primary === 'USD') {
            if ($inputCurrency === 'SLSH') {
                return $value / $rate;
            }
            return $value;
        }

        if ($primary === 'SLSH') {
            if ($inputCurrency === 'USD') {
                return $value * $rate;
            }
            return $value;
        }

        return $value;
    }
}

if (!function_exists('posx_convert_amount')) {
    function posx_convert_amount(float $value, ?string $currency = null): float {
        $c = posx_currency_settings();
        $target = strtoupper(trim((string)($currency ?? $c['selected'])));
        $primary = strtoupper($c['primary']);
        $rate = (float)$c['rate'];

        if ($rate <= 0) $rate = 11000;

        if ($primary === $target) {
            return $value;
        }

        if ($primary === 'USD' && $target === 'SLSH') {
            return $value * $rate;
        }

        if ($primary === 'SLSH' && $target === 'USD') {
            return $value / $rate;
        }

        return $value;
    }
}

if (!function_exists('posx_format_money')) {
    function posx_format_money(float $value, ?string $currency = null): string {
        $c = posx_currency_settings();
        $target = strtoupper(trim((string)($currency ?? $c['selected'])));
        $converted = posx_convert_amount($value, $target);

        if ($target === 'USD') {
            return '$' . number_format($converted, 2);
        }
        return 'SLSH ' . number_format($converted, 0);
    }
}

if (!function_exists('posx_dual_money')) {
    function posx_dual_money(float $value): string {
        $c = posx_currency_settings();
        $primary = posx_format_money($value, $c['primary']);
        $secondary = posx_format_money($value, $c['secondary']);
        return $primary . '<small class="text-muted d-block">' . $secondary . '</small>';
    }
}

if (!function_exists('posx_currency_rate_text')) {
    function posx_currency_rate_text(): string {
        $c = posx_currency_settings();
        return '1 USD = ' . number_format((float)$c['rate'], 0) . ' SLSH';
    }
}

if (!function_exists('posx_currency_label')) {
    function posx_currency_label(): string {
        return posx_currency_settings()['selected'];
    }
}

/* =========================
   POS CORE HELPERS
========================= */
if (!function_exists('posx_cart_totals')) {
    function posx_cart_totals(array $cart, float $tax = 0): array {
        $subtotal = 0;
        $discount = 0;

        foreach ($cart as $item) {
            $subtotal += (float)($item['line_total'] ?? 0);
            $discount += (float)($item['discount'] ?? 0);
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax'      => $tax,
            'total'    => $subtotal + $tax,
        ];
    }
}

if (!function_exists('posx_table_exists')) {
    function posx_table_exists(mysqli $db, string $table): bool {
        $table = $db->real_escape_string($table);
        $q = $db->query("SHOW TABLES LIKE '$table'");
        return $q && $q->num_rows > 0;
    }
}

if (!function_exists('posx_column_exists')) {
    function posx_column_exists(mysqli $db, string $table, string $column): bool {
        $table = $db->real_escape_string($table);
        $column = $db->real_escape_string($column);
        $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $q && $q->num_rows > 0;
    }
}

if (!function_exists('posx_customer_name_from_list')) {
    function posx_customer_name_from_list(array $customers, ?int $customerId): string {
        if (!$customerId) return 'Walk-in';
        foreach ($customers as $c) {
            if ((int)$c['id'] === (int)$customerId) return $c['name'];
        }
        return 'Walk-in';
    }
}

if (!function_exists('posx_logo_url')) {
    function posx_logo_url(string $logo): string {
        $logo = trim($logo);
        if ($logo === '') return '';

        $candidates = [
            'uploads/products/' . $logo,
            'uploads/' . $logo,
            $logo,
        ];

        foreach ($candidates as $path) {
            $full = __DIR__ . '/' . ltrim($path, '/');
            if (file_exists($full)) return $path;
        }

        return $logo;
    }
}

if (!function_exists('posx_branch_name')) {
    function posx_branch_name(mysqli $db, int $branchId): string {
        if ($branchId <= 0) return '';
        $stmt = $db->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['name'] ?? '';
    }
}

if (!function_exists('posx_company_branding_safe')) {
    function posx_company_branding_safe(): array {
        $currency = posx_currency_settings();

        if (function_exists('company_branding')) {
            $b = company_branding();
            return [
                'name'              => $b['name'] ?? 'Company',
                'title'             => $b['title'] ?? ($b['name'] ?? 'Company'),
                'logo'              => $b['logo'] ?? '',
                'tagline'           => $b['tagline'] ?? '',
                'email'             => $b['email'] ?? '',
                'phone'             => $b['phone'] ?? '',
                'address'           => $b['address'] ?? '',
                'invoice_footer'    => $b['invoice_footer'] ?? 'Thank you for your business.',
                'currency_symbol'   => $b['currency_symbol'] ?? '$',
                'currency_primary'  => $currency['primary'],
                'currency_secondary'=> $currency['secondary'],
                'exchange_rate'     => $currency['rate'],
            ];
        }

        return [
            'name'              => 'Company',
            'title'             => 'Company',
            'logo'              => '',
            'tagline'           => '',
            'email'             => '',
            'phone'             => '',
            'address'           => '',
            'invoice_footer'    => 'Thank you for your business.',
            'currency_symbol'   => '$',
            'currency_primary'  => $currency['primary'],
            'currency_secondary'=> $currency['secondary'],
            'exchange_rate'     => $currency['rate'],
        ];
    }
}

if (!function_exists('posx_render_print_layout')) {
    function posx_render_print_layout(array $doc, array $branding, string $docType = 'Quotation'): void {
        $companyName    = $branding['name'] ?? 'Company';
        $companyTitle   = $branding['title'] ?? $companyName;
        $companyPhone   = $branding['phone'] ?? '';
        $companyAddress = $branding['address'] ?? '';
        $companyEmail   = $branding['email'] ?? '';
        $companyLogo    = posx_logo_url($branding['logo'] ?? '');
        $companyTagline = $branding['tagline'] ?? '';
        $footerText     = $branding['invoice_footer'] ?? 'Thank you for your business.';
        $totals = posx_cart_totals($doc['items'] ?? [], (float)($doc['tax'] ?? 0));
        $sec = posx_currency_settings()['secondary'];
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title><?php echo e($docType); ?> - <?php echo e($doc['doc_no'] ?? ''); ?></title>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                *{box-sizing:border-box}
                html,body{margin:0;padding:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif}
                body{padding:24px}
                .page{max-width:980px;margin:0 auto}
                .sheet{background:#fff;border:1px solid #dbe3ee;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08)}
                .topline{height:8px;background:#7c3aed}
                .inner{padding:30px}
                .header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:2px solid #eef2f7;padding-bottom:20px}
                .brand{display:flex;gap:16px;align-items:flex-start}
                .brand-logo{width:84px;height:84px;border:1px solid #e5e7eb;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#fff;overflow:hidden}
                .brand-logo img{max-width:100%;max-height:100%;object-fit:contain}
                .brand-text h1{margin:0;font-size:28px;line-height:1.15;color:#0f172a;font-weight:800}
                .brand-text .title{margin-top:4px;font-size:15px;color:#334155;font-weight:700}
                .brand-text .tagline{margin-top:6px;color:#64748b;font-size:13px}
                .brand-meta{margin-top:10px;color:#475569;font-size:13px;line-height:1.6}
                .doc-badge{text-align:right}
                .doc-badge .type{display:inline-block;padding:10px 18px;border-radius:999px;color:#fff;font-weight:800;letter-spacing:.7px;background:#7c3aed;font-size:13px;text-transform:uppercase}
                .doc-badge .number{margin-top:12px;font-size:24px;font-weight:800;color:#0f172a}
                .section-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:22px}
                .info-card{border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}
                .info-card h3{margin:0 0 10px;font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#64748b}
                .info-card .line{margin:5px 0;font-size:14px;color:#0f172a}
                table{width:100%;border-collapse:collapse;margin-top:24px}
                thead th{background:#0f172a;color:#fff;font-size:12px;text-transform:uppercase;letter-spacing:.4px;padding:12px 10px;text-align:left}
                tbody td{padding:12px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#0f172a;vertical-align:top}
                tbody tr:nth-child(even){background:#fcfdff}
                .right{text-align:right}
                .sub-money{display:block;color:#64748b;font-size:12px;margin-top:2px}
                .totals-wrap{display:flex;justify-content:flex-end;margin-top:22px}
                .totals{width:380px;border:1px solid #dbe3ee;background:#f8fafc;border-radius:14px;padding:16px 18px}
                .totals .row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #cbd5e1;font-size:14px}
                .totals .row:last-child{border-bottom:0}
                .totals .grand{font-size:18px;font-weight:800;color:#0f172a}
                .notes{margin-top:22px;border:1px solid #e5e7eb;background:#f8fafc;border-radius:14px;padding:16px}
                .notes h3{margin:0 0 8px;font-size:13px;color:#334155}
                .footer{margin-top:26px;padding-top:18px;border-top:2px solid #eef2f7;display:flex;justify-content:space-between;gap:20px;align-items:flex-start;color:#475569;font-size:13px;line-height:1.6}
                .signature{min-width:220px;text-align:center}
                .signature-line{margin-top:34px;border-top:1px solid #94a3b8;padding-top:6px;color:#334155}
                .actions{display:flex;gap:12px;margin-top:18px}
                .btn{appearance:none;border:0;border-radius:12px;padding:12px 18px;font-weight:700;text-decoration:none;cursor:pointer;color:#fff;background:#334155}
                .btn-primary{background:#7c3aed}
                @media (max-width:760px){
                    body{padding:10px}
                    .header,.footer{flex-direction:column}
                    .section-grid{grid-template-columns:1fr}
                    .totals{width:100%}
                }
                @media print{
                    body{background:#fff;padding:0}
                    .page{max-width:none}
                    .sheet{border:0;border-radius:0;box-shadow:none}
                    .actions{display:none}
                }
            </style>
        </head>
        <body>
        <div class="page">
            <div class="sheet">
                <div class="topline"></div>
                <div class="inner">
                    <div class="header">
                        <div class="brand">
                            <?php if ($companyLogo): ?>
                                <div class="brand-logo"><img src="<?php echo e($companyLogo); ?>" alt="Logo"></div>
                            <?php endif; ?>
                            <div class="brand-text">
                                <h1><?php echo e($companyName); ?></h1>
                                <div class="title"><?php echo e($companyTitle); ?></div>
                                <?php if ($companyTagline): ?><div class="tagline"><?php echo e($companyTagline); ?></div><?php endif; ?>
                                <div class="brand-meta">
                                    <?php if ($companyAddress): ?><div><?php echo e($companyAddress); ?></div><?php endif; ?>
                                    <?php if ($companyPhone): ?><div><?php echo e($companyPhone); ?></div><?php endif; ?>
                                    <?php if ($companyEmail): ?><div><?php echo e($companyEmail); ?></div><?php endif; ?>
                                    <div><?php echo e(posx_currency_rate_text()); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="doc-badge">
                            <div class="type"><?php echo e($docType); ?></div>
                            <div class="number"><?php echo e($doc['doc_no'] ?? ''); ?></div>
                        </div>
                    </div>

                    <div class="section-grid">
                        <div class="info-card">
                            <h3>Document Information</h3>
                            <div class="line"><strong>Date:</strong> <?php echo e($doc['doc_date'] ?? ''); ?></div>
                            <?php if (!empty($doc['branch_name'])): ?><div class="line"><strong>Branch:</strong> <?php echo e($doc['branch_name']); ?></div><?php endif; ?>
                        </div>
                        <div class="info-card">
                            <h3>Customer Information</h3>
                            <div class="line"><strong>Customer:</strong> <?php echo e($doc['customer_name'] ?? 'Walk-in'); ?></div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:60px">#</th>
                                <th>Product</th>
                                <th style="width:120px">Barcode</th>
                                <th class="right" style="width:90px">Qty</th>
                                <th class="right" style="width:150px">Unit Price</th>
                                <th class="right" style="width:150px">Discount</th>
                                <th class="right" style="width:170px">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($doc['items'] ?? []) as $i => $it): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo e($it['product_name'] ?? ''); ?></td>
                                <td><?php echo e($it['barcode'] ?? ''); ?></td>
                                <td class="right"><?php echo number_format((float)($it['qty'] ?? 0), 2); ?></td>
                                <td class="right">
                                    <?php echo posx_format_money((float)($it['selling_price'] ?? 0)); ?>
                                    <span class="sub-money"><?php echo posx_format_money((float)($it['selling_price'] ?? 0), $sec); ?></span>
                                </td>
                                <td class="right">
                                    <?php echo posx_format_money((float)($it['discount'] ?? 0)); ?>
                                    <span class="sub-money"><?php echo posx_format_money((float)($it['discount'] ?? 0), $sec); ?></span>
                                </td>
                                <td class="right">
                                    <?php echo posx_format_money((float)($it['line_total'] ?? 0)); ?>
                                    <span class="sub-money"><?php echo posx_format_money((float)($it['line_total'] ?? 0), $sec); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="totals-wrap">
                        <div class="totals">
                            <div class="row">
                                <span>Subtotal</span>
                                <strong><?php echo posx_format_money((float)$totals['subtotal']); ?><span class="sub-money"><?php echo posx_format_money((float)$totals['subtotal'], $sec); ?></span></strong>
                            </div>
                            <div class="row">
                                <span>Tax</span>
                                <strong><?php echo posx_format_money((float)$totals['tax']); ?><span class="sub-money"><?php echo posx_format_money((float)$totals['tax'], $sec); ?></span></strong>
                            </div>
                            <div class="row grand">
                                <span>Grand Total</span>
                                <span><?php echo posx_format_money((float)$totals['total']); ?><span class="sub-money"><?php echo posx_format_money((float)$totals['total'], $sec); ?></span></span>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($doc['notes'])): ?>
                        <div class="notes">
                            <h3>Notes</h3>
                            <div><?php echo nl2br(e($doc['notes'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="footer">
                        <div>
                            <div><strong><?php echo e($companyName); ?></strong></div>
                            <div><?php echo e($footerText); ?></div>
                            <div>This quotation is valid for 7 days from the issue date.</div>
                        </div>
                        <div class="signature">
                            <div class="signature-line">Authorized Signature</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" onclick="window.print()">Print Quotation</button>
            <a href="pos.php" class="btn">Back to POS</a>
        </div>
        </body>
        </html>
        <?php
    }
}

$hasInvoices     = posx_table_exists($db, 'invoices');
$hasInvoiceItems = posx_table_exists($db, 'invoice_items');
$hasDueDate      = $hasInvoices && posx_column_exists($db, 'invoices', 'due_date');
$hasStatus       = $hasInvoices && posx_column_exists($db, 'invoices', 'status');
$hasPaidAmount   = $hasInvoices && posx_column_exists($db, 'invoices', 'paid_amount');
$hasDueAmount    = $hasInvoices && posx_column_exists($db, 'invoices', 'due_amount');
$hasQuotations   = posx_table_exists($db, 'quotations');

$stmt = $db->prepare("SELECT id, name FROM customers WHERE company_id = ? AND branch_id = ? ORDER BY name");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$branding = posx_company_branding_safe();
$currentBranchName = posx_branch_name($db, (int)$bid);

foreach ($_SESSION['pos_cart'] as $k => $item) {
    $productId = (int)($item['product_id'] ?? 0);
    $stmt = $db->prepare("SELECT id FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
    $stmt->bind_param('iii', $productId, $cid, $bid);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ok) unset($_SESSION['pos_cart'][$k]);
}
$_SESSION['pos_cart'] = array_values($_SESSION['pos_cart']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $stmt = $db->prepare("SELECT p.*, COALESCE(p.cost_price,0) AS cost_price, COALESCE(p.barcode, p.code, '') AS barcode_text FROM products p WHERE p.id = ? AND p.company_id = ? AND p.branch_id = ? LIMIT 1");
        $stmt->bind_param('iii', $productId, $cid, $bid);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$product) { flash('error', 'Product not found in your branch.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }
        if ((float)$product['stock_qty'] <= 0) { flash('error', 'Selected product is out of stock.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }

        $found = false;
        foreach ($_SESSION['pos_cart'] as $k => $item) {
            if ((int)$item['product_id'] === $productId) {
                $newQty = (float)$item['qty'] + 1;
                if ($newQty > (float)$product['stock_qty']) { flash('error', 'Combined quantity exceeds available stock.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }
                $_SESSION['pos_cart'][$k]['qty'] = $newQty;
                $_SESSION['pos_cart'][$k]['stock_qty'] = (float)$product['stock_qty'];
                $_SESSION['pos_cart'][$k]['line_total'] = ($newQty * (float)$_SESSION['pos_cart'][$k]['selling_price']) - (float)$_SESSION['pos_cart'][$k]['discount'];
                if ($_SESSION['pos_cart'][$k]['line_total'] < 0) $_SESSION['pos_cart'][$k]['line_total'] = 0;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $_SESSION['pos_cart'][] = [
                'product_id'     => (int)$product['id'],
                'product_name'   => $product['name'],
                'barcode'        => $product['barcode_text'],
                'stock_qty'      => (float)$product['stock_qty'],
                'buying_price'   => (float)$product['cost_price'],   // stored in primary
                'selling_price'  => (float)$product['price'],        // stored in primary
                'qty'            => 1,
                'discount'       => 0,
                'line_total'     => (float)$product['price'],
                'input_currency' => posx_currency_settings()['primary'],
            ];
        }

        flash('success', 'Product added to POS table.');
        redirect('pos.php?currency=' . urlencode(posx_currency_label()));
    }

    if ($action === 'update_item') {
        $index            = (int)($_POST['cart_index'] ?? -1);
        $qty              = max(1, (float)($_POST['qty'] ?? 1));
        $sellingPriceRaw  = max(0, (float)($_POST['selling_price'] ?? 0));
        $discountRaw      = max(0, (float)($_POST['discount'] ?? 0));
        $inputCurrency    = strtoupper(trim((string)($_POST['input_currency'] ?? posx_currency_settings()['primary'])));

        if (!isset($_SESSION['pos_cart'][$index])) {
            flash('error', 'Cart item not found.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }

        $item = $_SESSION['pos_cart'][$index];
        $productId = (int)$item['product_id'];

        $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
        $stmt->bind_param('iii', $productId, $cid, $bid);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fresh) {
            flash('error', 'Product no longer exists in your branch.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }
        if ($qty > (float)$fresh['stock_qty']) {
            flash('error', 'Quantity exceeds available stock.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }

        $sellingPrice = posx_normalize_to_primary($sellingPriceRaw, $inputCurrency);
        $discount     = posx_normalize_to_primary($discountRaw, $inputCurrency);

        if ($sellingPrice <= 0) $sellingPrice = (float)$item['selling_price'];

        $lineTotal = ($qty * $sellingPrice) - $discount;
        if ($lineTotal < 0) $lineTotal = 0;

        $_SESSION['pos_cart'][$index]['qty'] = $qty;
        $_SESSION['pos_cart'][$index]['selling_price'] = $sellingPrice;
        $_SESSION['pos_cart'][$index]['discount'] = $discount;
        $_SESSION['pos_cart'][$index]['stock_qty'] = (float)$fresh['stock_qty'];
        $_SESSION['pos_cart'][$index]['line_total'] = $lineTotal;
        $_SESSION['pos_cart'][$index]['input_currency'] = $inputCurrency;

        flash('success', 'Item updated.');
        redirect('pos.php?currency=' . urlencode(posx_currency_label()));
    }

    if ($action === 'remove_item') {
        $index = (int)($_POST['cart_index'] ?? -1);
        if (isset($_SESSION['pos_cart'][$index])) {
            unset($_SESSION['pos_cart'][$index]);
            $_SESSION['pos_cart'] = array_values($_SESSION['pos_cart']);
            flash('success', 'Item removed.');
        }
        redirect('pos.php?currency=' . urlencode(posx_currency_label()));
    }

    if ($action === 'clear_cart') {
        $_SESSION['pos_cart'] = [];
        flash('success', 'POS table cleared.');
        redirect('pos.php?currency=' . urlencode(posx_currency_label()));
    }

    if ($action === 'save_receipt') {
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $saleDate   = $_POST['sale_date'] ?? date('Y-m-d');
        $invoiceNo  = trim($_POST['invoice_no'] ?? ('REC-' . date('Ymd-His')));
        $taxRaw     = (float)($_POST['tax'] ?? 0);
        $taxCurrency= strtoupper(trim((string)($_POST['tax_currency'] ?? posx_currency_settings()['primary'])));
        $tax        = posx_normalize_to_primary($taxRaw, $taxCurrency);
        $notes      = trim($_POST['notes'] ?? '');

        if (empty($_SESSION['pos_cart'])) { flash('error', 'POS table is empty.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }

        $totals = posx_cart_totals($_SESSION['pos_cart'], $tax);
        $db->begin_transaction();
        try {
            $stmt = $db->prepare("INSERT INTO sales(company_id,branch_id,customer_id,invoice_no,sale_date,subtotal,tax,total_amount,notes) VALUES(?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iiissddds', $cid, $bid, $customerId, $invoiceNo, $saleDate, $totals['subtotal'], $tax, $totals['total'], $notes);
            $stmt->execute();
            $saleId = $stmt->insert_id;
            $stmt->close();

            $totalCost = 0;
            foreach ($_SESSION['pos_cart'] as $item) {
                $productId   = (int)$item['product_id'];
                $productName = $item['product_name'];
                $qty         = (float)$item['qty'];
                $unitPrice   = (float)$item['selling_price'];
                $lineTotal   = (float)$item['line_total'];
                $costPrice   = (float)$item['buying_price'];

                $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
                $stmt->bind_param('iii', $productId, $cid, $bid);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$fresh || (float)$fresh['stock_qty'] < $qty) throw new Exception('Not enough stock for ' . $productName);

                $stmt2 = $db->prepare("INSERT INTO sale_items(sale_id,product_id,product_name,qty,unit_price,line_total) VALUES(?,?,?,?,?,?)");
                $stmt2->bind_param('iisddd', $saleId, $productId, $productName, $qty, $unitPrice, $lineTotal);
                $stmt2->execute();
                $stmt2->close();

                if (!stock_deduct($db, $productId, $qty)) throw new Exception('Failed to deduct stock for ' . $productName);

                if (function_exists('stock_record_movement')) {
                    stock_record_movement($db, [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => $productId,
                        'transaction_type' => 'RECEIPT_SALE',
                        'reference_no'     => $invoiceNo,
                        'qty_in'           => 0,
                        'qty_out'          => $qty,
                        'unit_cost'        => $costPrice,
                        'notes'            => 'Automatic stock deduction from POS receipt sale',
                        'created_by'       => $_SESSION['user']['id'] ?? null,
                    ]);
                }

                $totalCost += ($costPrice * $qty);
            }

            if (function_exists('finance_post_sale')) {
                finance_post_sale($db, [
                    'entry_date'   => $saleDate,
                    'reference_no' => $invoiceNo,
                    'memo'         => 'Automatic posting from POS receipt sale',
                    'source_id'    => $saleId,
                    'branch_id'    => $bid,
                    'company_id'   => $cid,
                    'created_by'   => $_SESSION['user']['id'] ?? null,
                    'gross_amount' => $totals['total'],
                    'cost_amount'  => $totalCost,
                ]);
            }

            $db->commit();
            $_SESSION['pos_cart'] = [];
            flash('success', 'Receipt saved successfully.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Receipt failed: ' . $e->getMessage());
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }
    }

    if ($action === 'save_invoice') {
        if (!$hasInvoices || !$hasInvoiceItems) { flash('error', 'Invoice tables are missing.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }

        $customerId  = (int)($_POST['customer_id'] ?? 0);
        $invoiceDate = $_POST['sale_date'] ?? date('Y-m-d');
        $invoiceNo   = trim($_POST['invoice_no'] ?? ('INV-' . date('Ymd-His')));
        $dueDate     = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
        $taxRaw      = (float)($_POST['tax'] ?? 0);
        $taxCurrency = strtoupper(trim((string)($_POST['tax_currency'] ?? posx_currency_settings()['primary'])));
        $tax         = posx_normalize_to_primary($taxRaw, $taxCurrency);
        $notes       = trim($_POST['notes'] ?? '');

        if ($customerId <= 0) { flash('error', 'Select customer for invoice.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }
        if (empty($_SESSION['pos_cart'])) { flash('error', 'POS table is empty.'); redirect('pos.php?currency=' . urlencode(posx_currency_label())); }

        $totals = posx_cart_totals($_SESSION['pos_cart'], $tax);
        $db->begin_transaction();
        try {
            $createdBy  = $_SESSION['user']['id'] ?? null;
            $dueAmount  = $totals['total'];
            $paidAmount = 0;
            $status     = 'unpaid';

            if ($hasDueDate && $hasStatus && $hasPaidAmount && $hasDueAmount) {
                $stmt = $db->prepare("INSERT INTO invoices (company_id,branch_id,customer_id,invoice_no,invoice_date,due_date,subtotal,tax,total_amount,paid_amount,due_amount,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iiisssddddddsi', $cid, $bid, $customerId, $invoiceNo, $invoiceDate, $dueDate, $totals['subtotal'], $tax, $totals['total'], $paidAmount, $dueAmount, $status, $notes, $createdBy);
            } else {
                $stmt = $db->prepare("INSERT INTO invoices (company_id,branch_id,customer_id,invoice_no,invoice_date,subtotal,tax,total_amount,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iiissdddsi', $cid, $bid, $customerId, $invoiceNo, $invoiceDate, $totals['subtotal'], $tax, $totals['total'], $notes, $createdBy);
            }
            $stmt->execute();
            $invoiceId = $stmt->insert_id;
            $stmt->close();

            $totalCost = 0;
            foreach ($_SESSION['pos_cart'] as $item) {
                $productId    = (int)$item['product_id'];
                $productName  = $item['product_name'];
                $qty          = (float)$item['qty'];
                $buyingPrice  = (float)$item['buying_price'];
                $sellingPrice = (float)$item['selling_price'];
                $discount     = (float)$item['discount'];
                $lineTotal    = (float)$item['line_total'];

                $stmt = $db->prepare("SELECT id, stock_qty FROM products WHERE id = ? AND company_id = ? AND branch_id = ? LIMIT 1");
                $stmt->bind_param('iii', $productId, $cid, $bid);
                $stmt->execute();
                $fresh = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$fresh || (float)$fresh['stock_qty'] < $qty) throw new Exception('Not enough stock for ' . $productName);

                $stmt2 = $db->prepare("INSERT INTO invoice_items (invoice_id,product_id,product_name,qty,buying_price,selling_price,discount,line_total) VALUES (?,?,?,?,?,?,?,?)");
                $stmt2->bind_param('iisddddd', $invoiceId, $productId, $productName, $qty, $buyingPrice, $sellingPrice, $discount, $lineTotal);
                $stmt2->execute();
                $stmt2->close();

                if (!stock_deduct($db, $productId, $qty)) throw new Exception('Failed to deduct stock for ' . $productName);

                if (function_exists('stock_record_movement')) {
                    stock_record_movement($db, [
                        'company_id'       => $cid,
                        'branch_id'        => $bid,
                        'product_id'       => $productId,
                        'transaction_type' => 'INVOICE_SALE',
                        'reference_no'     => $invoiceNo,
                        'qty_in'           => 0,
                        'qty_out'          => $qty,
                        'unit_cost'        => $buyingPrice,
                        'notes'            => 'Automatic stock deduction from POS credit invoice',
                        'created_by'       => $_SESSION['user']['id'] ?? null,
                    ]);
                }

                $totalCost += ($buyingPrice * $qty);
            }

            if (function_exists('finance_post_sale')) {
                finance_post_sale($db, [
                    'entry_date'   => $invoiceDate,
                    'reference_no' => $invoiceNo,
                    'memo'         => 'Automatic posting from POS credit invoice',
                    'source_id'    => $invoiceId,
                    'branch_id'    => $bid,
                    'company_id'   => $cid,
                    'created_by'   => $_SESSION['user']['id'] ?? null,
                    'gross_amount' => $totals['total'],
                    'cost_amount'  => $totalCost,
                ]);
            }

            $db->commit();
            $_SESSION['pos_cart'] = [];
            flash('success', 'Invoice saved successfully.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        } catch (Throwable $e) {
            $db->rollback();
            flash('error', 'Invoice failed: ' . $e->getMessage());
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }
    }

    if ($action === 'print_quotation') {
        $customerId  = (int)($_POST['customer_id'] ?? 0) ?: null;
        $quoteDate   = $_POST['sale_date'] ?? date('Y-m-d');
        $quoteNo     = trim($_POST['invoice_no'] ?? ('QT-' . date('Ymd-His')));
        $taxRaw      = (float)($_POST['tax'] ?? 0);
        $taxCurrency = strtoupper(trim((string)($_POST['tax_currency'] ?? posx_currency_settings()['primary'])));
        $tax         = posx_normalize_to_primary($taxRaw, $taxCurrency);
        $notes       = trim($_POST['notes'] ?? '');
        $customerName = posx_customer_name_from_list($customers, $customerId);

        if (empty($_SESSION['pos_cart'])) {
            flash('error', 'POS table is empty.');
            redirect('pos.php?currency=' . urlencode(posx_currency_label()));
        }

        $quotationItems = [];
        $amount = 0;
        foreach ($_SESSION['pos_cart'] as $it) {
            $line = (float)($it['line_total'] ?? 0);
            $quotationItems[] = [
                'description' => $it['product_name'] ?? '',
                'barcode'     => $it['barcode'] ?? '',
                'qty'         => (float)($it['qty'] ?? 0),
                'price'       => (float)($it['selling_price'] ?? 0),
                'discount'    => (float)($it['discount'] ?? 0),
                'total'       => $line,
            ];
            $amount += $line;
        }

        if ($hasQuotations) {
            $status = 'draft';
            $details = json_encode([
                'notes' => $notes,
                'tax'   => $tax,
                'items' => $quotationItems,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $db->prepare("INSERT INTO quotations(company_id,branch_id,customer_name,quote_no,quote_date,amount,status,details) VALUES(?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iisssdss', $cid, $bid, $customerName, $quoteNo, $quoteDate, $amount, $status, $details);
            $stmt->execute();
            $quotationId = (int)$stmt->insert_id;
            $stmt->close();

            flash('success', 'Quotation prepared successfully. Stock was not changed.');
            redirect('quotation_print.php?id=' . $quotationId);
        }

        $_SESSION['pos_quotation'] = [
            'doc_no'        => $quoteNo,
            'doc_date'      => $quoteDate,
            'customer_id'   => $customerId,
            'customer_name' => $customerName,
            'tax'           => $tax,
            'notes'         => $notes,
            'items'         => $_SESSION['pos_cart'],
            'branch_id'     => $bid,
            'branch_name'   => $currentBranchName,
        ];
        flash('success', 'Quotation prepared successfully. Stock was not changed.');
        redirect('pos.php?print_quote=1&currency=' . urlencode(posx_currency_label()));
    }
}

$stmt = $db->prepare("SELECT id,name,price,stock_qty,COALESCE(cost_price,0) AS cost_price,COALESCE(barcode, code, '') AS barcode,sku,brand,category,unit,branch_id FROM products WHERE company_id = ? AND branch_id = ? AND stock_qty > 0 ORDER BY name");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$productJson = json_encode(array_values($products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cart = $_SESSION['pos_cart'];
$cartTotals = posx_cart_totals($cart, 0);

if (isset($_GET['print_quote']) && !empty($_SESSION['pos_quotation'])) {
    $q = $_SESSION['pos_quotation'];
    if ((int)($q['branch_id'] ?? 0) !== (int)$bid) {
        flash('error', 'Quotation does not belong to your branch.');
        redirect('pos.php?currency=' . urlencode(posx_currency_label()));
    }
    posx_render_print_layout([
        'doc_no'        => $q['doc_no'] ?? '',
        'doc_date'      => $q['doc_date'] ?? '',
        'customer_name' => $q['customer_name'] ?? 'Walk-in',
        'notes'         => $q['notes'] ?? '',
        'tax'           => (float)($q['tax'] ?? 0),
        'branch_name'   => $q['branch_name'] ?? $currentBranchName,
        'items'         => $q['items'] ?? [],
    ], $branding, 'Quotation');
    exit;
}
?>
<style>
:root{
    --pos-bg:#f4f7fb;
    --pos-card:#ffffff;
    --pos-text:#0f172a;
    --pos-muted:#64748b;
    --pos-line:#e2e8f0;
    --pos-primary:#2563eb;
    --pos-primary-dark:#1d4ed8;
    --pos-success:#16a34a;
    --pos-danger:#dc2626;
    --pos-warning:#f59e0b;
    --pos-purple:#7c3aed;
    --pos-soft:#eff6ff;
}
.pos-shell{display:grid;gap:20px}
.pos-top{
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.10), transparent 20%),
        linear-gradient(135deg,#0f172a,#1e3a8a 55%, #0f766e);
    color:#fff;
    border-radius:26px;
    padding:28px;
    box-shadow:0 18px 40px rgba(15,23,42,.18)
}
.pos-top h4{margin:0 0 8px;font-size:30px;font-weight:800}
.pos-top .sub{color:rgba(255,255,255,.84);max-width:760px}
.pos-card{
    border:0;
    border-radius:24px;
    background:var(--pos-card);
    box-shadow:0 12px 30px rgba(15,23,42,.06)
}
.pos-card .card-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:16px
}
.pos-card .card-head h5{margin:0;font-weight:800;color:var(--pos-text)}
.pos-card .card-head p{margin:4px 0 0;color:var(--pos-muted);font-size:14px}
.search-box{position:relative}
.search-box .form-control{
    height:56px;
    border-radius:18px;
    padding-left:16px;
    border:1px solid var(--pos-line);
    box-shadow:none
}
.result-list{
    z-index:1000;
    max-height:320px;
    overflow:auto;
    display:none;
    border-radius:18px;
    border:1px solid var(--pos-line);
    margin-top:8px
}
.result-list .list-group-item{
    border:0;
    border-bottom:1px solid #eef2f7;
    padding:14px 16px
}
.result-list .list-group-item:last-child{border-bottom:0}
.pos-hint{
    background:#f8fafc;
    border:1px dashed #cbd5e1;
    border-radius:18px;
    padding:14px 16px;
    color:var(--pos-muted)
}
.pos-form .form-control,.pos-form .form-select{
    border-radius:14px;
    min-height:48px;
    border:1px solid var(--pos-line);
    box-shadow:none
}
.pos-form .form-label{
    font-weight:700;
    color:#334155;
    font-size:13px;
    margin-bottom:6px
}
.pos-grid-stat{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px
}
.pos-stat{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10)
}
.pos-stat small{opacity:.9;font-size:12px}
.pos-stat strong{display:block;margin-top:8px;font-size:22px}
.pos-s1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.pos-s2{background:linear-gradient(135deg,#10b981,#059669)}
.pos-s3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.pos-s4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.pos-table-wrap{
    border:1px solid var(--pos-line);
    border-radius:18px;
    overflow:hidden
}
.pos-table{margin:0}
.pos-table thead th{
    background:#f8fafc;
    border-bottom:1px solid var(--pos-line);
    font-size:13px;
    color:#334155;
    white-space:nowrap
}
.pos-table td{vertical-align:middle}
.pos-table input.form-control{
    min-width:88px;
    border-radius:12px;
    min-height:40px
}
.product-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
    font-weight:700
}
.money-box{
    border-radius:22px;
    background:linear-gradient(135deg,#eff6ff,#f8fafc);
    border:1px solid #dbeafe;
    padding:18px
}
.money-row{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    padding:8px 0;
    border-bottom:1px dashed #cbd5e1
}
.money-row:last-child{border-bottom:0}
.money-row.total{
    font-size:18px;
    font-weight:800;
    color:#0f172a
}
.action-stack{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px
}
.action-btn{
    border:none;
    outline:none;
    border-radius:16px;
    padding:16px 18px;
    font-size:15px;
    font-weight:800;
    color:#fff;
    cursor:pointer;
    transition:all .22s ease;
    box-shadow:0 8px 20px rgba(0,0,0,.12);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    letter-spacing:.2px
}
.action-save{background:linear-gradient(135deg,#16a34a,#22c55e)}
.action-invoice{background:linear-gradient(135deg,#2563eb,#3b82f6)}
.action-quote{background:linear-gradient(135deg,#7c3aed,#a855f7)}
.action-clear{background:linear-gradient(135deg,#dc2626,#ef4444)}
.mini-note{font-size:12px;color:var(--pos-muted)}
.pro-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.18);
    font-weight:700;
    font-size:.82rem
}
.currency-panel{
    min-width:240px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.16);
    border-radius:18px;
    padding:12px 14px;
}
.currency-panel .form-select{
    border-radius:14px;
    border:0;
    min-height:42px;
    box-shadow:none;
}
.currency-note{
    margin-top:6px;
    color:rgba(255,255,255,.82);
    font-size:.78rem;
}
.money-dual small{
    display:block;
    color:#64748b;
    font-weight:500;
}
.inline-currency{
    min-width:95px;
}
@media (max-width:992px){
    .pos-grid-stat{grid-template-columns:repeat(2,minmax(0,1fr))}
    .action-stack{grid-template-columns:1fr}
}
@media (max-width:576px){
    .pos-grid-stat{grid-template-columns:1fr}
}
</style>

<div class="pos-shell">
    <div class="pos-top">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="pro-badge mb-2">Professional POS Screen</div>
                <h4>POS Terminal</h4>
                <div class="sub">
                    Primary currency is stored in DB. If you type SLSH while primary is USD, system converts automatically before saving.
                </div>
            </div>

            <div class="currency-panel">
                <form method="get">
                    <label class="form-label text-white mb-1 fw-semibold">Currency View</label>
                    <select name="currency" class="form-select" onchange="this.form.submit()">
                        <option value="USD" <?php echo posx_currency_label() === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="SLSH" <?php echo posx_currency_label() === 'SLSH' ? 'selected' : ''; ?>>SLSH</option>
                    </select>
                    <div class="currency-note"><?php echo e(posx_currency_rate_text()); ?></div>
                </form>
            </div>
        </div>
    </div>

    <div class="pos-grid-stat">
        <div class="pos-stat pos-s1"><small>Items in Table</small><strong><?php echo count($cart); ?></strong></div>
        <div class="pos-stat pos-s2"><small>Subtotal</small><strong><?php echo strip_tags(posx_dual_money((float)$cartTotals['subtotal']), '<small>'); ?></strong></div>
        <div class="pos-stat pos-s3"><small>Discount</small><strong><?php echo strip_tags(posx_dual_money((float)$cartTotals['discount']), '<small>'); ?></strong></div>
        <div class="pos-stat pos-s4"><small>Total</small><strong><?php echo strip_tags(posx_dual_money((float)$cartTotals['total']), '<small>'); ?></strong></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="pos-card p-4 h-100">
                <div class="card-head">
                    <div>
                        <h5>Quick Product Search</h5>
                        <p>Only products from your assigned branch appear here.</p>
                    </div>
                </div>

                <div class="search-box mb-3">
                    <input type="text" id="productSearch" class="form-control" placeholder="Search name / barcode / sku ...">
                    <div id="searchResults" class="list-group position-absolute w-100 shadow-sm result-list"></div>
                </div>

                <div class="pos-hint">
                    <div class="fw-bold mb-2">How it works</div>
                    <div class="small">
                        Search product → click result → item auto-adds → choose input currency → edit price → if SLSH is entered while primary is USD, system converts automatically.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="pos-card p-4">
                <div class="card-head">
                    <div>
                        <h5>POS Sale Table</h5>
                        <p>Editable price supports USD or SLSH input per row.</p>
                    </div>
                </div>

                <form method="post" class="pos-form">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Document No</label>
                            <input name="invoice_no" class="form-control" value="POS-<?php echo date('Ymd-His'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="sale_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Customer</label>
                            <select name="customer_id" class="form-select">
                                <option value="">Walk-in</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Due Date (Invoice)</label>
                            <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-2">
                            <label class="form-label">Tax</label>
                            <input type="number" step="0.01" min="0" name="tax" class="form-control" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tax Currency</label>
                            <select name="tax_currency" class="form-select">
                                <option value="USD">USD</option>
                                <option value="SLSH">SLSH</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional note...">
                        </div>
                    </div>

                    <div class="pos-table-wrap">
                        <div class="table-responsive">
                            <table class="table pos-table align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Product</th>
                                        <th>Barcode</th>
                                        <th>Stock</th>
                                        <th>Buying</th>
                                        <th>Selling Input</th>
                                        <th>Input Cur</th>
                                        <th>Qty</th>
                                        <th>Discount</th>
                                        <th>Total</th>
                                        <th width="190">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart as $i => $item): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo e($item['product_name']); ?></div>
                                                <div class="mini-note">Stored in <?php echo e(posx_currency_settings()['primary']); ?></div>
                                            </td>
                                            <td><span class="product-chip"><?php echo e($item['barcode']); ?></span></td>
                                            <td><?php echo number_format((float)$item['stock_qty'], 2); ?></td>
                                            <td class="money-dual"><?php echo posx_dual_money((float)$item['buying_price']); ?></td>
                                            <td>
                                                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="update_item">
                                                    <input type="hidden" name="cart_index" value="<?php echo $i; ?>">
                                                    <input type="number" step="0.01" min="0" name="selling_price" value="<?php echo e((string)$item['selling_price']); ?>" class="form-control form-control-sm">
                                                    <div class="mini-note w-100"><?php echo posx_format_money((float)$item['selling_price']); ?> / <?php echo posx_format_money((float)$item['selling_price'], posx_currency_settings()['secondary']); ?></div>
                                            </td>
                                            <td>
                                                <select name="input_currency" class="form-select form-select-sm inline-currency">
                                                    <option value="USD" <?php echo (($item['input_currency'] ?? 'USD') === 'USD') ? 'selected' : ''; ?>>USD</option>
                                                    <option value="SLSH" <?php echo (($item['input_currency'] ?? '') === 'SLSH') ? 'selected' : ''; ?>>SLSH</option>
                                                </select>
                                            </td>
                                            <td><input type="number" step="0.01" min="1" name="qty" value="<?php echo e((string)$item['qty']); ?>" class="form-control form-control-sm"></td>
                                            <td>
                                                <input type="number" step="0.01" min="0" name="discount" value="<?php echo e((string)$item['discount']); ?>" class="form-control form-control-sm">
                                                <div class="mini-note"><?php echo posx_format_money((float)$item['discount']); ?> / <?php echo posx_format_money((float)$item['discount'], posx_currency_settings()['secondary']); ?></div>
                                            </td>
                                            <td class="fw-bold money-dual"><?php echo posx_dual_money((float)$item['line_total']); ?></td>
                                            <td class="d-flex gap-2 flex-wrap">
                                                <button class="btn btn-sm btn-outline-primary">Update</button>
                                                </form>
                                                <form method="post" onsubmit="return confirm('Remove item?')">
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
                                            <td colspan="11" class="text-center text-muted py-5">
                                                No products added yet. Search and click a product to start.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4 g-4">
                        <div class="col-md-6">
                            <div class="money-box">
                                <div class="money-row"><span>Subtotal</span><strong><?php echo posx_dual_money((float)$cartTotals['subtotal']); ?></strong></div>
                                <div class="money-row"><span>Discount</span><strong><?php echo posx_dual_money((float)$cartTotals['discount']); ?></strong></div>
                                <div class="money-row"><span>Tax</span><strong><?php echo posx_dual_money((float)$cartTotals['tax']); ?></strong></div>
                                <div class="money-row total"><span>Grand Total</span><span><?php echo posx_dual_money((float)$cartTotals['total']); ?></span></div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="action-stack">
                                <button type="submit" name="action" value="save_receipt" class="action-btn action-save confirm-action" data-message="Ma hubtaa inaad rabto inaad kaydiso Receipt-kan?">
                                    <span>🧾</span><span>Save Receipt</span>
                                </button>

                                <button type="submit" name="action" value="save_invoice" class="action-btn action-invoice confirm-action" data-message="Ma hubtaa inaad rabto inaad kaydiso Invoice-kan? Stock-ga wuu is beddeli karaa.">
                                    <span>📄</span><span>Save Invoice</span>
                                </button>

                                <button type="submit" name="action" value="print_quotation" class="action-btn action-quote confirm-action" data-message="Ma hubtaa inaad rabto inaad sameyso Quotation? Stock waxba kama badalayo.">
                                    <span>🖨️</span><span>Print Quotation</span>
                                </button>

                                <button type="submit" name="action" value="clear_cart" class="action-btn action-clear confirm-action" data-message="Dhammaan items-ka miiska ma tirtirtaa?">
                                    <span>🗑️</span><span>Clear Table</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<form id="quickAddForm" method="post" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="action" value="add_item">
    <input type="hidden" name="product_id" id="quick_product_id">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.confirm-action').forEach(function (button) {
        button.addEventListener('click', function (e) {
            const message = this.getAttribute('data-message') || 'Ma hubtaa?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
});

const productsData = <?php echo $productJson; ?>;
const searchInput = document.getElementById('productSearch');
const resultsBox = document.getElementById('searchResults');
const quickProductId = document.getElementById('quick_product_id');
const quickAddForm = document.getElementById('quickAddForm');
const exchangeRate = <?php echo json_encode((float)posx_currency_settings()['rate']); ?>;
const primaryCurrency = <?php echo json_encode(posx_currency_settings()['primary']); ?>;
const secondaryCurrency = <?php echo json_encode(posx_currency_settings()['secondary']); ?>;

function formatCurrencyByCodePrimaryStored(val, currency) {
    val = Number(val || 0);

    if (primaryCurrency === currency) {
        if (currency === 'USD') return '$' + val.toFixed(2);
        return 'SLSH ' + Math.round(val).toLocaleString();
    }

    if (primaryCurrency === 'USD' && currency === 'SLSH') {
        return 'SLSH ' + Math.round(val * exchangeRate).toLocaleString();
    }

    if (primaryCurrency === 'SLSH' && currency === 'USD') {
        return '$' + (val / exchangeRate).toFixed(2);
    }

    return String(val);
}

function dualMoney(val) {
    return `${formatCurrencyByCodePrimaryStored(val, primaryCurrency)} / ${formatCurrencyByCodePrimaryStored(val, secondaryCurrency)}`;
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
            <small class="text-muted">
                Barcode: ${product.barcode || '-'} |
                SKU: ${product.sku || '-'} |
                Stock: ${Number(product.stock_qty).toFixed(2)} |
                Buy: ${dualMoney(product.cost_price || 0)} |
                Sell: ${dualMoney(product.price || 0)}
            </small>
        `;
        item.addEventListener('click', function () {
            quickProductId.value = product.id;
            quickAddForm.submit();
        });
        resultsBox.appendChild(item);
    });

    resultsBox.style.display = 'block';
}

searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (q.length < 1) {
        resultsBox.style.display = 'none';
        return;
    }

    const filtered = productsData.filter(product =>
        (product.name && product.name.toLowerCase().includes(q)) ||
        (product.barcode && String(product.barcode).toLowerCase().includes(q)) ||
        (product.sku && String(product.sku).toLowerCase().includes(q))
    ).slice(0, 12);

    renderResults(filtered);
});

document.addEventListener('click', function (e) {
    if (!resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.style.display = 'none';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>