<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';

function ensure_currency_settings_exist(?int $companyId = null): void {
    $settings = $companyId ? company_settings($companyId) : company_settings();

    if (!isset($settings['currency_primary']) || trim((string)$settings['currency_primary']) === '') {
        if ($companyId) {
            save_setting('currency_primary', 'USD', $companyId);
        } else {
            save_setting('currency_primary', 'USD');
        }
    }

    if (!isset($settings['currency_secondary']) || trim((string)$settings['currency_secondary']) === '') {
        if ($companyId) {
            save_setting('currency_secondary', 'SLSH', $companyId);
        } else {
            save_setting('currency_secondary', 'SLSH');
        }
    }

    if (!isset($settings['exchange_rate']) || trim((string)$settings['exchange_rate']) === '') {
        if ($companyId) {
            save_setting('exchange_rate', '11000', $companyId);
        } else {
            save_setting('exchange_rate', '11000');
        }
    }
}

if (is_super_admin()) {
    $companyId = (int)($_GET['company_id'] ?? 2);
    $companies = db()->query("SELECT id,name FROM companies WHERE id<>1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

    ensure_currency_settings_exist($companyId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();

        $companyId = (int)($_POST['company_id'] ?? 0);

        foreach ([
            'company_name',
            'company_title',
            'company_phone',
            'company_email',
            'company_address',
            'invoice_footer',
            'currency_symbol',
            'company_tagline',
            'currency_primary',
            'currency_secondary'
        ] as $k) {
            save_setting($k, trim($_POST[$k] ?? ''), $companyId);
        }

        $exchangeRate = (float)($_POST['exchange_rate'] ?? 11000);
        if ($exchangeRate <= 0) {
            $exchangeRate = 11000;
        }
        save_setting('exchange_rate', (string)$exchangeRate, $companyId);

        if (!empty($_FILES['company_logo']['name'])) {
            $logo = upload_file('company_logo', UPLOAD_PRODUCTS, ['jpg', 'jpeg', 'png', 'webp']);
            save_setting('company_logo', $logo, $companyId);
        }

        flash('success', 'Company settings updated successfully.');
        redirect('settings.php?company_id=' . $companyId);
    }

    $settings = company_settings($companyId);
    ?>
    <div class="card-soft p-4">
        <h5 class="mb-3">Company Branding, Currency & Settings</h5>

        <form method="get" class="row g-2 mb-4">
            <div class="col-md-4">
                <label class="form-label">Select Company</label>
                <select name="company_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $companyId === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="company_id" value="<?php echo (int)$companyId; ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name</label>
                    <input class="form-control" name="company_name" value="<?php echo e($settings['company_name'] ?? ''); ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Page Title</label>
                    <input class="form-control" name="company_title" value="<?php echo e($settings['company_title'] ?? ''); ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input class="form-control" name="company_phone" value="<?php echo e($settings['company_phone'] ?? ''); ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input class="form-control" name="company_email" value="<?php echo e($settings['company_email'] ?? ''); ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tagline</label>
                    <input class="form-control" name="company_tagline" value="<?php echo e($settings['company_tagline'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Currency Symbol</label>
                    <input class="form-control" name="currency_symbol" value="<?php echo e($settings['currency_symbol'] ?? '$'); ?>" placeholder="$ / SLSH">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Logo</label>
                    <input type="file" name="company_logo" class="form-control">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Primary Currency</label>
                    <?php $primary = strtoupper((string)($settings['currency_primary'] ?? 'USD')); ?>
                    <select class="form-select" name="currency_primary">
                        <option value="USD" <?php echo $primary === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="SLSH" <?php echo $primary === 'SLSH' ? 'selected' : ''; ?>>SLSH</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Secondary Currency</label>
                    <?php $secondary = strtoupper((string)($settings['currency_secondary'] ?? 'SLSH')); ?>
                    <select class="form-select" name="currency_secondary">
                        <option value="SLSH" <?php echo $secondary === 'SLSH' ? 'selected' : ''; ?>>SLSH</option>
                        <option value="USD" <?php echo $secondary === 'USD' ? 'selected' : ''; ?>>USD</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Exchange Rate</label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control" name="exchange_rate" value="<?php echo e($settings['exchange_rate'] ?? '11000'); ?>">
                    <small class="text-muted">Example: 1 USD = 11000 SLSH</small>
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="company_address" rows="3"><?php echo e($settings['company_address'] ?? ''); ?></textarea>
                </div>

                <div class="col-12">
                    <label class="form-label">Invoice Footer</label>
                    <input class="form-control" name="invoice_footer" value="<?php echo e($settings['invoice_footer'] ?? 'Thank you for your business.'); ?>">
                </div>
            </div>

            <button class="btn btn-primary mt-3">Save Settings</button>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require_company_admin();

ensure_currency_settings_exist();

$settings = company_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ([
        'company_name',
        'company_title',
        'company_phone',
        'company_email',
        'company_address',
        'invoice_footer',
        'currency_symbol',
        'company_tagline',
        'currency_primary',
        'currency_secondary'
    ] as $k) {
        save_setting($k, trim($_POST[$k] ?? ''));
    }

    $exchangeRate = (float)($_POST['exchange_rate'] ?? 11000);
    if ($exchangeRate <= 0) {
        $exchangeRate = 11000;
    }
    save_setting('exchange_rate', (string)$exchangeRate);

    if (!empty($_FILES['company_logo']['name'])) {
        $logo = upload_file('company_logo', UPLOAD_PRODUCTS, ['jpg', 'jpeg', 'png', 'webp']);
        save_setting('company_logo', $logo);
    }

    flash('success', 'Settings saved successfully.');
    redirect('settings.php');
}
?>

<div class="card-soft p-4">
    <h5 class="mb-3">Company Settings</h5>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Company Name</label>
                <input class="form-control" name="company_name" value="<?php echo e($settings['company_name'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Page Title</label>
                <input class="form-control" name="company_title" value="<?php echo e($settings['company_title'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Company Phone</label>
                <input class="form-control" name="company_phone" value="<?php echo e($settings['company_phone'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Company Email</label>
                <input class="form-control" name="company_email" value="<?php echo e($settings['company_email'] ?? ''); ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Tagline</label>
                <input class="form-control" name="company_tagline" value="<?php echo e($settings['company_tagline'] ?? ''); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Currency Symbol</label>
                <input class="form-control" name="currency_symbol" value="<?php echo e($settings['currency_symbol'] ?? '$'); ?>" placeholder="$ / SLSH">
            </div>

            <div class="col-md-3">
                <label class="form-label">Logo</label>
                <input type="file" class="form-control" name="company_logo">
            </div>

            <div class="col-md-4">
                <label class="form-label">Primary Currency</label>
                <?php $primary = strtoupper((string)($settings['currency_primary'] ?? 'USD')); ?>
                <select class="form-select" name="currency_primary">
                    <option value="USD" <?php echo $primary === 'USD' ? 'selected' : ''; ?>>USD</option>
                    <option value="SLSH" <?php echo $primary === 'SLSH' ? 'selected' : ''; ?>>SLSH</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Secondary Currency</label>
                <?php $secondary = strtoupper((string)($settings['currency_secondary'] ?? 'SLSH')); ?>
                <select class="form-select" name="currency_secondary">
                    <option value="SLSH" <?php echo $secondary === 'SLSH' ? 'selected' : ''; ?>>SLSH</option>
                    <option value="USD" <?php echo $secondary === 'USD' ? 'selected' : ''; ?>>USD</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Exchange Rate</label>
                <input type="number" step="0.0001" min="0.0001" class="form-control" name="exchange_rate" value="<?php echo e($settings['exchange_rate'] ?? '11000'); ?>">
                <small class="text-muted">Example: 1 USD = 11000 SLSH</small>
            </div>

            <div class="col-12">
                <label class="form-label">Company Address</label>
                <textarea class="form-control" name="company_address" rows="3"><?php echo e($settings['company_address'] ?? ''); ?></textarea>
            </div>

            <div class="col-12">
                <label class="form-label">Invoice Footer</label>
                <input class="form-control" name="invoice_footer" value="<?php echo e($settings['invoice_footer'] ?? 'Thank you for your business.'); ?>">
            </div>
        </div>

        <button class="btn btn-primary mt-3">Save Settings</button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>