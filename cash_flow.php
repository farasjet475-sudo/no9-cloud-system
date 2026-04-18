<?php
$pageTitle = 'Cash Flow Statement';
require_once __DIR__ . '/includes/finance_page_bootstrap.php';

$cashId = finance_account_id($conn, '1000');
$openingSql = "SELECT COALESCE(SUM(jel.debit - jel.credit),0)
               FROM journal_entry_lines jel
               INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
               WHERE jel.account_id = ? AND je.entry_date < ?";
$periodSql  = "SELECT COALESCE(SUM(jel.debit),0) AS inflow, COALESCE(SUM(jel.credit),0) AS outflow
               FROM journal_entry_lines jel
               INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
               WHERE jel.account_id = ? AND je.entry_date BETWEEN ? AND ?";

$opening = finance_fetch_value($conn, $openingSql, 'is', [$cashId, $dateFrom], 0);
$stmt = $conn->prepare($periodSql);
$stmt->bind_param('iss', $cashId, $dateFrom, $dateTo);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc() ?: ['inflow' => 0, 'outflow' => 0];
$stmt->close();
$inflow = (float)$row['inflow'];
$outflow = (float)$row['outflow'];
$closing = $opening + $inflow - $outflow;
?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white"><strong>Cash Flow Statement</strong></div>
    <div class="card-body">
        <table class="table table-bordered align-middle">
            <tr><th>Opening Balance</th><td class="text-end"><?php echo number_format($opening, 2); ?></td></tr>
            <tr><th>Cash Inflows</th><td class="text-end"><?php echo number_format($inflow, 2); ?></td></tr>
            <tr><th>Cash Outflows</th><td class="text-end"><?php echo number_format($outflow, 2); ?></td></tr>
            <tr><th>Closing Balance</th><td class="text-end fw-bold"><?php echo number_format($closing, 2); ?></td></tr>
        </table>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
