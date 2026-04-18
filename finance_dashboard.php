<?php
$pageTitle = 'Finance Dashboard';
require_once __DIR__ . '/includes/finance_page_bootstrap.php';

$trial = finance_trial_balance($conn, $dateFrom, $dateTo);
$totals = [
    'assets' => 0,
    'liabilities' => 0,
    'equity' => 0,
    'income' => 0,
    'expenses' => 0,
    'cogs' => 0,
];
foreach ($trial as $row) {
    $totals[$row['type']] += finance_net_balance($row);
}
$grossProfit = $totals['income'] - $totals['cogs'];
$netProfit = $grossProfit - $totals['expenses'];
$cash = 0;
foreach ($trial as $row) {
    if (($row['code'] ?? '') === '1000') $cash = finance_net_balance($row);
}
?>
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Net Profit', $netProfit],
        ['Sales Revenue', $totals['income']],
        ['COGS', $totals['cogs']],
        ['Operating Expenses', $totals['expenses']],
        ['Cash Balance', $cash],
        ['Total Assets', $totals['assets']],
    ];
    foreach ($cards as $card):
    ?>
    <div class="col-md-4 col-xl-2">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="text-muted small"><?php echo finance_escape($card[0]); ?></div>
                <div class="h4 mb-0"><?php echo number_format((float)$card[1], 2); ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white"><strong>Quick Finance Links</strong></div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="income_statement.php?date_from=<?php echo finance_escape($dateFrom); ?>&date_to=<?php echo finance_escape($dateTo); ?>">Income Statement</a>
        <a class="btn btn-outline-primary" href="balance_sheet.php?date_from=<?php echo finance_escape($dateFrom); ?>&date_to=<?php echo finance_escape($dateTo); ?>">Balance Sheet</a>
        <a class="btn btn-outline-primary" href="cash_flow.php?date_from=<?php echo finance_escape($dateFrom); ?>&date_to=<?php echo finance_escape($dateTo); ?>">Cash Flow</a>
        <a class="btn btn-outline-primary" href="owner_equity.php?date_from=<?php echo finance_escape($dateFrom); ?>&date_to=<?php echo finance_escape($dateTo); ?>">Owner Equity</a>
        <a class="btn btn-outline-primary" href="journal_entries.php?date_from=<?php echo finance_escape($dateFrom); ?>&date_to=<?php echo finance_escape($dateTo); ?>">Journal Entries</a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
