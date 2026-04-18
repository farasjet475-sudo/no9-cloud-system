<?php
require_once __DIR__ . '/auth.php';

require_login();
require_once __DIR__ . '/finance_helpers.php';

$pageTitle = $pageTitle ?? 'Finance';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');

/* DB CONNECTION FOR FINANCE PAGES */
$conn = db();

include __DIR__ . '/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h2 class="mb-1"><?php echo finance_escape($pageTitle); ?></h2>
            <p class="text-muted mb-0">Integrated financial reports for POS + inventory + expenses.</p>
        </div>
        <form class="d-flex gap-2 flex-wrap" method="get">
            <input type="date" name="date_from" value="<?php echo finance_escape($dateFrom); ?>" class="form-control">
            <input type="date" name="date_to" value="<?php echo finance_escape($dateTo); ?>" class="form-control">
            <button class="btn btn-primary" type="submit">Filter</button>
        </form>
    </div>