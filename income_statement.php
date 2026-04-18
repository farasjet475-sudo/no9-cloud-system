<?php
$pageTitle='Income Statement';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

[$from, $to] = finance_date_range();
$cid = current_company_id();
$bid = current_branch_id();
$summary = sales_summary_fallback(db(), $from, $to, $cid, $bid);
?>
<div class="card-soft p-4">
  <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <h5 class="mb-0">Income Statement</h5>
    <div class="d-flex gap-2">
      <a href="financial_statements.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="btn btn-outline-secondary">Back</a>
      <form class="d-flex gap-2">
        <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control">
        <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control">
        <button class="btn btn-outline-primary">Filter</button>
      </form>
    </div>
  </div>

  <table class="table table-bordered align-middle">
    <tbody>
      <tr><th>Sales Revenue</th><td class="text-end"><?php echo money($summary['sales']); ?></td></tr>
      <tr><th>Cost of Goods Sold</th><td class="text-end"><?php echo money($summary['cogs']); ?></td></tr>
      <tr class="table-light"><th>Gross Profit</th><td class="text-end"><?php echo money($summary['gross_profit']); ?></td></tr>
      <tr><th>Operating Expenses</th><td class="text-end"><?php echo money($summary['expenses']); ?></td></tr>
      <tr class="table-primary"><th>Net Profit</th><td class="text-end"><?php echo money($summary['net_profit']); ?></td></tr>
    </tbody>
  </table>

  <div class="small text-secondary">
    This report uses accounting tables when available and falls back to sales, invoices, products, and expenses if needed.
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
