<?php
$pageTitle = "Owner's Equity Statement";
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/financial_statement_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';

$data = fs_owners_equity_statement($cid, $bid, $from ?: null, $to ?: null);
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
.kpi-2{background:linear-gradient(135deg,#10b981,#059669);}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
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
</style>

<div class="statement-wrap">

    <div class="statement-card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="mb-1">Owner's Equity Statement</h4>
                <div class="text-muted">Track owner capital, investments, profit, withdrawals, and ending equity</div>
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
                <small>Opening Capital</small>
                <h3><?php echo money($data['opening_capital']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Additional Investment</small>
                <h3><?php echo money($data['additional_investment']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Net Profit</small>
                <h3><?php echo money($data['net_profit']); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Ending Equity</small>
                <h3><?php echo money($data['ending_equity']); ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="statement-card p-4 h-100">
                <h5 class="section-title">Equity Summary</h5>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle summary-table mb-0">
                        <tbody>
                            <tr>
                                <th>Opening Capital</th>
                                <td><?php echo money($data['opening_capital']); ?></td>
                            </tr>
                            <tr>
                                <th>Additional Investment</th>
                                <td><?php echo money($data['additional_investment']); ?></td>
                            </tr>
                            <tr>
                                <th>Net Profit</th>
                                <td class="<?php echo $data['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo money($data['net_profit']); ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Withdrawals</th>
                                <td><?php echo money($data['withdrawals']); ?></td>
                            </tr>
                            <tr>
                                <th>Ending Equity</th>
                                <td class="fw-bold"><?php echo money($data['ending_equity']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="statement-card p-4 mb-4">
                <h5 class="section-title">Formula</h5>
                <div class="formula-box">
                    <div class="formula">
                        Opening Capital + Additional Investment + Net Profit - Withdrawals = Ending Equity
                    </div>
                </div>
            </div>

            <div class="statement-card p-4">
                <h5 class="section-title">Notes</h5>
                <div class="note-box">
                    Statement-kan wuxuu muujinayaa isbeddelka saamiga milkiilaha ee shirkadda muddada la doortay.
                    Waxa uu si toos ah uga akhrisanayaa:
                    <br><br>
                    - owner capital / investment<br>
                    - net profit from operations<br>
                    - withdrawals<br>
                    - ending equity calculation
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>