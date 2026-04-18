<?php
$pageTitle='Financial Statements';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/report_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

[$from, $to] = finance_date_range();
$cid = current_company_id();
$bid = current_branch_id();
$summary = sales_summary_fallback(db(), $from, $to, $cid, $bid);
$inventory = inventory_totals(db(), $cid);
?>
<div class="row g-4">
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Sales</div><h3><?php echo money($summary['sales']); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">COGS</div><h3><?php echo money($summary['cogs']); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Expenses</div><h3><?php echo money($summary['expenses']); ?></h3></div></div>
  <div class="col-md-3"><div class="card-soft p-4"><div class="small text-secondary">Net Profit</div><h3><?php echo money($summary['net_profit']); ?></h3></div></div>

  <div class="col-12">
    <div class="card-soft p-4">
      <form class="row g-2">
        <div class="col-md-3"><input type="date" name="from" value="<?php echo e($from); ?>" class="form-control"></div>
        <div class="col-md-3"><input type="date" name="to" value="<?php echo e($to); ?>" class="form-control"></div>
        <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
      </form>
    </div>
  </div>

  <div class="col-md-6"><a href="income_statement.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="text-decoration-none"><div class="card-soft p-4"><h5>Income Statement</h5><div class="text-secondary">Sales, cost of goods sold, expenses, and net profit.</div></div></a></div>
  <div class="col-md-6"><a href="balance_sheet.php?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>" class="text-decoration-none"><div class="card-soft p-4"><h5>Balance Sheet</h5><div class="text-secondary">Cash, receivables, inventory, liabilities, and equity overview.</div></div></a></div>

  <div class="col-md-4"><a href="inventory_value.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Inventory Value</h5><div class="text-secondary"><?php echo money($inventory['inventory_value']); ?></div></div></a></div>
  <div class="col-md-4"><a href="sales.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Receipt Sales</h5><div class="text-secondary">Cash sales linked to stock and finance.</div></div></a></div>
  <div class="col-md-4"><a href="invoices.php" class="text-decoration-none"><div class="card-soft p-4"><h5>Credit Invoices</h5><div class="text-secondary">Receivable sales linked to stock and finance.</div></div></a></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
