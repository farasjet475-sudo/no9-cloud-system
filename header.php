<?php
require_once __DIR__ . '/auth.php';
require_login();
write_guard();

$flashSuccess = flash('success');
$flashError   = flash('error');
$pageTitle    = $pageTitle ?? 'Dashboard';
$branches     = is_super_admin() ? [] : company_branches();
$subscription = is_super_admin()
    ? ['state' => 'active', 'days_left' => 9999, 'label' => 'Active']
    : current_subscription_status();
$notifCount = unread_notifications_count();

if (is_super_admin()) {
    $branding = [
        'title' => APP_NAME,
        'name' => APP_NAME,
        'logo' => '',
        'tagline' => 'Cloud inventory, finance, and multi-branch SaaS',
    ];
} else {
    if (function_exists('company_branding')) {
        $branding = company_branding();
    } else {
        $branding = [
            'title' => APP_NAME,
            'name' => APP_NAME,
            'logo' => '',
            'tagline' => 'Inventory, finance, and branch management',
            'email' => '',
            'phone' => '',
            'address' => '',
            'invoice_footer' => 'Thank you for your business.',
        ];
    }
}

$lowStock = (!is_super_admin() && function_exists('low_stock_items')) ? low_stock_items() : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($pageTitle); ?> - <?php echo e($branding['title'] ?? APP_NAME); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="main">
        <div class="topbar d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="mb-0"><?php echo e($pageTitle); ?></h5>
                <div class="small-muted">
                    <?php echo is_super_admin() ? 'Super Admin / SaaS Control' : e($branding['name'] ?? APP_NAME); ?>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (!is_super_admin() && is_company_admin()): ?>
                <form action="switch_branch.php" method="post" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>" <?php echo current_branch_id() == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo e($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>

                <a href="profile.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-person-circle"></i>
                </a>

                <a href="notifications.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-bell"></i>
                    <?php if($notifCount): ?>
                        <span class="badge text-bg-danger"><?php echo (int)$notifCount; ?></span>
                    <?php endif; ?>
                </a>

                <a href="healthcheck.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-heart-pulse"></i>
                </a>

                <a href="logout.php" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>

        <div class="content">
            <?php if (!is_super_admin() && in_array($subscription['state'], ['expired','suspended'], true)): ?>
                <div class="readonly-banner">
                    Your account is in <strong><?php echo e($subscription['label'] ?? $subscription['state']); ?></strong> state.
                    Data can be viewed, but changes are blocked until payment is approved.
                </div>
            <?php elseif (!is_super_admin() && isset($subscription['days_left']) && $subscription['days_left'] <= 5): ?>
                <div class="alert alert-warning">
                    Subscription expires in <?php echo (int)$subscription['days_left']; ?> day(s).
                    Please upload payment proof to avoid read-only mode.
                </div>
            <?php endif; ?>

            <?php if (!is_super_admin() && !empty($lowStock)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    Low stock alert: <?php echo count($lowStock); ?> product(s) need restocking.
                </div>
            <?php endif; ?>

            <?php if ($flashSuccess): ?>
                <div class="alert alert-success"><?php echo e($flashSuccess); ?></div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="alert alert-danger"><?php echo e($flashError); ?></div>
            <?php endif; ?>