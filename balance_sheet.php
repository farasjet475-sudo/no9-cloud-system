<?php
$pageTitle='Balance Sheet';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

[$from, $to] = finance_date_range();
$cid = current_company_id();
$bid = current_branch_id();

$inventory = inventory_totals(db(), $cid);
$cash = 0.0;
if (table_exists(db(), 'sales')) {
    $row = db()->query("SELECT COALESCE(SUM(total_amount),0) total FROM sales WHERE company_id=$cid AND branch_id=$bid AND sale_date>='".db()->real_escape_string($from)."' AND sale_date<='".db()->real_escape_string($to)."'")->fetch_assoc();
    $cash = (float)($row['total'] ?? 0);
}
$receivables = 0.0;
if (table_exists(db(), 'invoices')) {
    $row = db()->query("SELECT COALESCE(SUM(due_amount),0) total FROM invoices WHERE company_id=$cid AND branch_id=$bid AND invoice_date<='".db()->real_escape_string($to)."'")->fetch_assoc();
    $receivables = (float)($row['total'] ?? 0);
}
$payables = 0.0;
if (table_exists(db(), 'expenses')) {
    $payables = 0.0;
}

$summary = sales_summary_fallback(db(), $from, $to, $cid, $bid);
$retained = $summary['net_profit'];

$totalAssets = $cash + $receivables + $inventory['inventory_value'];
$totalLiabEquity = $payables + $retained;
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <h5 class="mb-0">Balance Sheet</h5>
    <div class="d-flex gap-2">
      <a href="financial_statements.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="btn btn-outline-secondary">Back</a>
      <form class="d-flex gap-2">
        <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control">
        <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control">
        <button class="btn btn-outline-primary">Filter</button>
      </form>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-md-6">
      <table class="table table-bordered">
        <thead><tr><th colspan="2">Assets</th></tr></thead>
        <tbody>
          <tr><td>Cash / Receipt Sales</td><td class="text-end"><?php echo money($cash); ?></td></tr>
          <tr><td>Accounts Receivable</td><td class="text-end"><?php echo money($receivables); ?></td></tr>
          <tr><td>Inventory</td><td class="text-end"><?php echo money($inventory['inventory_value']); ?></td></tr>
          <tr class="table-light"><th>Total Assets</th><th class="text-end"><?php echo money($totalAssets); ?></th></tr>
        </tbody>
      </table>
    </div>
    <div class="col-md-6">
      <table class="table table-bordered">
        <thead><tr><th colspan="2">Liabilities & Equity</th></tr></thead>
        <tbody>
          <tr><td>Accounts Payable</td><td class="text-end"><?php echo money($payables); ?></td></tr>
          <tr><td>Current Period Profit</td><td class="text-end"><?php echo money($retained); ?></td></tr>
          <tr class="table-light"><th>Total Liabilities & Equity</th><th class="text-end"><?php echo money($totalLiabEquity); ?></th></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="small text-secondary">
    Balance sheet is simplified for merge-ready use. If your accounting package already has exact accounts, you can wire this page to accounts and journal tables later without changing the UI.
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
