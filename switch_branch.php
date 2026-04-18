<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_company_user();
verify_csrf();

$branchId = (int)($_POST['branch_id'] ?? 0);

if ($branchId <= 0) {
    flash('error', 'Invalid branch selected.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

require_branch_access($branchId);

if (set_current_branch($branchId)) {
    flash('success', 'Branch switched successfully.');
} else {
    flash('error', 'Unable to switch branch.');
}

redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');