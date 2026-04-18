<?php
$pageTitle = 'Cash Flow Statement';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/financial_statement_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$data = fs_cash_flow_statement($cid, $bid, $from ?: null, $to ?: null);

$totalInflows  = (float)($data['cash_inflows']['total'] ?? 0);
$totalOutflows = (float)($data['cash_outflows']['total'] ?? 0);
$netCashFlow   = (float)($data['net_cash_flow'] ?? 0);
$closingBal    = (float)($data['closing_balance'] ?? 0);
?>

<style>
.statement-wrap{
    display:grid;
    gap:18px;
}
.statement-card{
    border:0;
    border-radius:20px;
    background:#fff;
    box-shadow:0 10px 28px rgba(15,23,42,.06);
}
.kpi-card{
    border-radius:18px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{
    opacity:.92;
    font-size:.84rem;
}
.kpi-card h3{
    margin:8px 0 0;
    font-size:1.7rem;
    font-weight:700;
}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8);}
.kpi-2{background:linear-gradient(135deg,#ef4444,#dc2626);}
.kpi-3{background:linear-gradient(135deg,#10b981,#059669);}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.section-title{
    font-weight:700;
    margin-bottom:14px;
}
.summary-table th{
    width:55%;
    background:#f8fafc;
}
.formula-box{
    border-radius:16px;
    background:#eff6ff;
    border:1px solid #bfdbfe;
    padding:18px;
}
.formula-box .formula{
    font-size:1.05rem;
    font-weight:700;
    color:#1d4ed8;
}
.note-box{
    border-radius:16px;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:16px;
    color:#475569;
}
.flow-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
}
.flow-positive{
    background:#dcfce7;
    color:#166534;
}
.flow-negative{
    background:#fee2e2;
    color:#b91c1c;
}
.progress-soft{
    height:12px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
}
.progress-soft .bar{
    height:100%;
    border-radius:999px;
}
.bar-in{
    background:linear-gradient(90deg,#2563eb,#1d4ed8);
}
.bar-out{
    background:linear-gradient(90deg,#ef4444,#dc2626);
}
</style>

<div class="statement-wrap">

    <div class="statement-card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="mb-1">Cash Flow Statement</h4>
                <div class="text-muted">Track cash inflows, outflows, net cash flow, and closing balance</div>
            </div>

            <form class="row g-2">
                <div class="col-auto">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control">
                </div>
                <div class="col-auto d-flex align-items-end">
                    <button class="btn btn-primary">Filter</button>
                </div>
                <div class="col-auto d-flex align-items-end">
                    <a href="financial_statements.php" class="btn btn-outline-secondary">Back</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Cash Inflows</small>
                <h3><?php echo money($totalInflows); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Total Cash Outflows</small>
                <h3><?php echo money($totalOutflows); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Net Cash Flow</small>
                <h3><?php echo money($netCashFlow); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Closing Balance</small>
                <h3><?php echo money($closingBal); ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="statement-card p-4 h-100">
                <h5 class="section-title">Cash Flow Summary</h5>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle summary-table mb-0">
                        <tbody>
                            <tr>
                                <th>Opening Balance</th>
                                <td><?php echo money($data['opening_balance']); ?></td>
                            </tr>
                            <tr>
                                <th>Cash Inflows</th>
                                <td><?php echo money($totalInflows); ?></td>
                            </tr>
                            <tr>
                                <th>Cash Outflows</th>
                                <td><?php echo money($totalOutflows); ?></td>
                            </tr>
                            <tr>
                                <th>Net Cash Flow</th>
                                <td class="<?php echo $netCashFlow >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold'; ?>">
                                    <?php echo money($netCashFlow); ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Closing Balance</th>
                                <td class="fw-bold"><?php echo money($closingBal); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-semibold">Inflows vs Outflows</span>
                        <span class="<?php echo $netCashFlow >= 0 ? 'flow-badge flow-positive' : 'flow-badge flow-negative'; ?>">
                            <?php echo $netCashFlow >= 0 ? 'Positive Flow' : 'Negative Flow'; ?>
                        </span>
                    </div>

                    <?php
                    $baseTotal = max(1, $totalInflows + $totalOutflows);
                    $inPercent  = ($totalInflows / $baseTotal) * 100;
                    $outPercent = ($totalOutflows / $baseTotal) * 100;
                    ?>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Inflows</span>
                            <span><?php echo number_format($inPercent, 1); ?>%</span>
                        </div>
                        <div class="progress-soft">
                            <div class="bar bar-in" style="width: <?php echo $inPercent; ?>%;"></div>
                        </div>
                    </div>

                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Outflows</span>
                            <span><?php echo number_format($outPercent, 1); ?>%</span>
                        </div>
                        <div class="progress-soft">
                            <div class="bar bar-out" style="width: <?php echo $outPercent; ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="statement-card p-4 mb-4">
                <h5 class="section-title">Formula</h5>
                <div class="formula-box">
                    <div class="formula">
                        Opening Balance + Inflows - Outflows = Closing Balance
                    </div>
                </div>
            </div>

            <div class="statement-card p-4">
                <h5 class="section-title">Notes</h5>
                <div class="note-box">
                    Statement-kan wuxuu muujinayaa lacagta soo gasha iyo tan baxda muddada la doortay.
                    Waxa uu ka akhrisanayaa:
                    <br><br>
                    - Sales cash inflows<br>
                    - Invoice collections<br>
                    - Other income / investments<br>
                    - Expenses<br>
                    - Withdrawals<br>
                    - Net cash position
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="statement-card p-4 h-100">
                <h5 class="section-title">Cash Inflows Breakdown</h5>
                <table class="table table-bordered align-middle summary-table mb-0">
                    <tbody>
                        <tr>
                            <th>Sales</th>
                            <td><?php echo money($data['cash_inflows']['sales']); ?></td>
                        </tr>
                        <tr>
                            <th>Invoice Collections</th>
                            <td><?php echo money($data['cash_inflows']['invoice_collections']); ?></td>
                        </tr>
                        <tr>
                            <th>Other Income / Investments</th>
                            <td><?php echo money($data['cash_inflows']['other_income_investments']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Cash Inflows</th>
                            <td class="fw-bold"><?php echo money($data['cash_inflows']['total']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="statement-card p-4 h-100">
                <h5 class="section-title">Cash Outflows Breakdown</h5>
                <table class="table table-bordered align-middle summary-table mb-0">
                    <tbody>
                        <tr>
                            <th>Expenses</th>
                            <td><?php echo money($data['cash_outflows']['expenses']); ?></td>
                        </tr>
                        <tr>
                            <th>Withdrawals</th>
                            <td><?php echo money($data['cash_outflows']['withdrawals']); ?></td>
                        </tr>
                        <tr>
                            <th>Total Cash Outflows</th>
                            <td class="fw-bold"><?php echo money($data['cash_outflows']['total']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>