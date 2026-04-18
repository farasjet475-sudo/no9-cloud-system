<?php
$pageTitle = 'Owner Equity Statement';
require_once __DIR__ . '/includes/finance_page_bootstrap.php';

$capitalId = finance_account_id($conn, '3000');
$retainedId = finance_account_id($conn, '3100');
$withdrawId = finance_account_id($conn, '3200');

$openingCapital = finance_fetch_value($conn,
    "SELECT COALESCE(SUM(credit - debit),0) FROM journal_entry_lines jel
     INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
     WHERE jel.account_id = ? AND je.entry_date < ?",
    'is', [$capitalId, $dateFrom], 0);
$additionalInvestment = finance_fetch_value($conn,
    "SELECT COALESCE(SUM(amount),0) FROM owner_transactions WHERE txn_type IN ('capital','investment') AND txn_date BETWEEN ? AND ?",
    'ss', [$dateFrom, $dateTo], 0);
$withdrawals = finance_fetch_value($conn,
    "SELECT COALESCE(SUM(amount),0) FROM owner_transactions WHERE txn_type='withdrawal' AND txn_date BETWEEN ? AND ?",
    'ss', [$dateFrom, $dateTo], 0);

$trial = finance_trial_balance($conn, $dateFrom, $dateTo);
$revenue = 0; $cogs = 0; $expenses = 0;
foreach ($trial as $row) {
    $bal = finance_net_balance($row);
    if ($row['type'] === 'income') $revenue += $bal;
    if ($row['type'] === 'cogs') $cogs += $bal;
    if ($row['type'] === 'expense') $expenses += $bal;
}
$netProfit = ($revenue - $cogs) - $expenses;
$endingEquity = $openingCapital + $additionalInvestment + $netProfit - $withdrawals;
?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Owner Equity Statement</strong></div>
    <div class="card-body">
        <table class="table table-bordered align-middle">
            <tr><th>Opening Owner Capital</th><td class="text-end"><?php echo number_format($openingCapital, 2); ?></td></tr>
            <tr><th>Additional Investment</th><td class="text-end"><?php echo number_format($additionalInvestment, 2); ?></td></tr>
            <tr><th>Net Profit</th><td class="text-end"><?php echo number_format($netProfit, 2); ?></td></tr>
            <tr><th>Withdrawals</th><td class="text-end"><?php echo number_format($withdrawals, 2); ?></td></tr>
            <tr><th>Ending Equity</th><td class="text-end fw-bold"><?php echo number_format($endingEquity, 2); ?></td></tr>
        </table>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
