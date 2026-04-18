<?php
$pageTitle='Reports';
require_once __DIR__ . '/includes/header.php';

$db = db();

function reports_is_company_admin(): bool {
    if (function_exists('is_company_admin') && is_company_admin()) return true;
    if (function_exists('is_admin') && is_admin()) return true;

    $u = function_exists('current_user') ? current_user() : [];
    $role = strtolower((string)($u['role'] ?? ''));
    return in_array($role, ['admin', 'company_admin', 'manager'], true);
}

function reports_branch_only(): bool {
    if (is_super_admin()) return false;
    return !reports_is_company_admin();
}

function reports_money($value): string {
    return money((float)$value);
}
?>

<style>
:root{
    --rp-shadow:0 14px 32px rgba(15,23,42,.07);
    --rp-shadow-lg:0 22px 50px rgba(15,23,42,.12);
    --rp-line:#e2e8f0;
}
.reports-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:30px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 20%),
        radial-gradient(circle at bottom left, rgba(124,58,237,.15), transparent 25%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 48%, #0f766e 100%);
    box-shadow:var(--rp-shadow-lg);
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    font-size:.83rem;
    font-weight:700;
}
.hero-card h2{
    margin:14px 0 8px;
    font-weight:800;
    letter-spacing:-.02em;
}
.hero-card p{
    margin:0;
    color:rgba(255,255,255,.85);
    max-width:820px;
}
.hero-mini{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:18px;
}
.hero-mini .box{
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.16);
    border-radius:18px;
    padding:14px 16px;
    min-width:160px;
}
.hero-mini .box small{
    display:block;
    color:rgba(255,255,255,.78);
}
.hero-mini .box strong{
    font-size:1.08rem;
    font-weight:800;
}

.soft-card{
    border:1px solid var(--rp-line);
    border-radius:24px;
    background:#fff;
    box-shadow:var(--rp-shadow);
}
.kpi-card{
    border:0;
    border-radius:22px;
    padding:20px;
    color:#fff;
    min-height:145px;
    position:relative;
    overflow:hidden;
    box-shadow:0 10px 28px rgba(0,0,0,.10);
}
.kpi-card::after{
    content:"";
    position:absolute;
    width:120px;
    height:120px;
    right:-18px;
    bottom:-18px;
    border-radius:50%;
    background:rgba(255,255,255,.10);
}
.kpi-card h3{
    margin:10px 0 8px;
    font-size:1.75rem;
    font-weight:800;
}
.kpi-card small{
    opacity:.92;
    font-size:.84rem;
}
.kpi-card .meta{
    font-size:.83rem;
    opacity:.92;
}
.kpi-icon{
    position:absolute;
    right:18px;
    top:18px;
    font-size:2rem;
    opacity:.20;
}
.bg-sales{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.bg-expense{background:linear-gradient(135deg,#ef4444,#dc2626)}
.bg-profit{background:linear-gradient(135deg,#10b981,#059669)}
.bg-count{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.bg-paid{background:linear-gradient(135deg,#14b8a6,#0f766e)}
.bg-company{background:linear-gradient(135deg,#f59e0b,#d97706)}

.section-title{
    font-weight:800;
    margin-bottom:6px;
    color:#0f172a;
}
.section-sub{
    color:#64748b;
    font-size:.92rem;
    margin-bottom:16px;
}
.chart-box{
    position:relative;
    min-height:320px;
}
.chart-box canvas{
    width:100% !important;
    height:320px !important;
}
.chart-box.sm canvas{
    height:280px !important;
}
.form-control,.form-select{
    min-height:48px;
    border-radius:14px;
    border:1px solid #dbe3ef;
    box-shadow:none !important;
}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
    font-weight:700;
    border-bottom:1px solid #e2e8f0;
}
.badge-soft-success{background:#dcfce7;color:#15803d}
.badge-soft-warning{background:#fef3c7;color:#b45309}
.badge-soft-danger{background:#fee2e2;color:#b91c1c}
.badge-soft-info{background:#dbeafe;color:#1d4ed8}
.empty-box{
    border:1px dashed #cbd5e1;
    border-radius:18px;
    padding:18px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
}
@media (max-width:768px){
    .hero-card h2{font-size:1.5rem}
    .chart-box canvas,.chart-box.sm canvas{height:260px !important}
}
</style>

<div class="container-fluid py-4 reports-shell">

<?php if(is_super_admin()): ?>

<?php
$paymentFilter = $_GET['payment_status'] ?? '';
$where = [];

if($paymentFilter === 'paid'){
    $where[] = "EXISTS (
        SELECT 1 FROM payment_proofs pp
        WHERE pp.company_id = c.id AND pp.status='approved'
    )";
}
if($paymentFilter === 'unpaid'){
    $where[] = "NOT EXISTS (
        SELECT 1 FROM payment_proofs pp
        WHERE pp.company_id = c.id AND pp.status='approved'
    )";
}

$sql = "
    SELECT 
        c.id,
        c.name,
        c.status company_status,
        COALESCE((
            SELECT SUM(amount)
            FROM payment_proofs pp
            WHERE pp.company_id=c.id AND pp.status='approved'
        ),0) paid_amount,
        COALESCE((
            SELECT COUNT(*)
            FROM payment_proofs pp
            WHERE pp.company_id=c.id AND pp.status='approved'
        ),0) paid_count,
        (
            SELECT status
            FROM subscriptions s
            WHERE s.company_id=c.id
            ORDER BY id DESC
            LIMIT 1
        ) subscription_status
    FROM companies c
    WHERE c.id<>1
";

if($where){
    $sql .= " AND " . implode(' AND ', $where);
}
$sql .= " ORDER BY c.name";

$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

$totalCompanies = count($rows);
$totalPaid = 0;
$paidCompanies = 0;
$unpaidCompanies = 0;

foreach($rows as $r){
    $totalPaid += (float)$r['paid_amount'];
    if((float)$r['paid_amount'] > 0) $paidCompanies++;
    else $unpaidCompanies++;
}
?>

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-bar-chart-line-fill"></i> SaaS Reports</span>
        <h2>Modern Super Admin Reports</h2>
        <p>
            View company payments, subscription status, approved payment totals, and overall SaaS reporting from one dashboard.
        </p>
        <div class="hero-mini">
            <div class="box"><small>Companies</small><strong><?php echo $totalCompanies; ?></strong></div>
            <div class="box"><small>Paid Companies</small><strong><?php echo $paidCompanies; ?></strong></div>
            <div class="box"><small>Unpaid Companies</small><strong><?php echo $unpaidCompanies; ?></strong></div>
            <div class="box"><small>Total Paid</small><strong><?php echo reports_money($totalPaid); ?></strong></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-company">
                <div class="kpi-icon"><i class="bi bi-buildings"></i></div>
                <small>Total Companies</small>
                <h3><?php echo $totalCompanies; ?></h3>
                <div class="meta">All registered companies</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-paid">
                <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
                <small>Total Approved Paid</small>
                <h3><?php echo reports_money($totalPaid); ?></h3>
                <div class="meta">Approved company payments</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-profit">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <small>Paid Companies</small>
                <h3><?php echo $paidCompanies; ?></h3>
                <div class="meta">Have approved payment</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-expense">
                <div class="kpi-icon"><i class="bi bi-exclamation-circle"></i></div>
                <small>Unpaid Companies</small>
                <h3><?php echo $unpaidCompanies; ?></h3>
                <div class="meta">No approved payment yet</div>
            </div>
        </div>
    </div>

    <div class="soft-card p-4">
        <h5 class="section-title">Filter Companies</h5>
        <div class="section-sub">Filter SaaS report by payment status</div>

        <form class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Payment Status</label>
                <select name="payment_status" class="form-select">
                    <option value="">All companies</option>
                    <option value="paid" <?php echo $paymentFilter==='paid'?'selected':''; ?>>Paid</option>
                    <option value="unpaid" <?php echo $paymentFilter==='unpaid'?'selected':''; ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-2 align-self-end">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2 align-self-end">
                <a href="reports.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="soft-card p-4 h-100">
                <h5 class="section-title">Payment Status Overview</h5>
                <div class="section-sub">Paid vs unpaid companies</div>
                <div class="chart-box sm">
                    <canvas id="superStatusChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="soft-card p-4 h-100">
                <h5 class="section-title">Company Payment Report</h5>
                <div class="section-sub">Approved payments and subscription status</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Subscription</th>
                                    <th>Company Status</th>
                                    <th>Approved Payments</th>
                                    <th>Total Paid</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach($rows as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($r['name']); ?></td>
                                    <td>
                                        <span class="badge rounded-pill badge-soft-info">
                                            <?php echo e($r['subscription_status'] ?: 'none'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?php echo strtolower((string)$r['company_status']) === 'active' ? 'badge-soft-success' : 'badge-soft-warning'; ?>">
                                            <?php echo e($r['company_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int)$r['paid_count']; ?></td>
                                    <td><strong><?php echo reports_money($r['paid_amount']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if(!$rows): ?>
                                <tr>
                                    <td colspan="5"><div class="empty-box m-3">No companies found for this filter.</div></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    new Chart(document.getElementById('superStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Paid Companies', 'Unpaid Companies'],
            datasets: [{
                data: [<?php echo (int)$paidCompanies; ?>, <?php echo (int)$unpaidCompanies; ?>],
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
    </script>

<?php else: ?>

<?php
$cid = current_company_id();
$branchOnly = reports_branch_only();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');
$branchId = (int)($_GET['branch_id'] ?? 0);

if ($branchOnly) {
    $branchId = (int)current_branch_id();
}

$salesWhere = "company_id=$cid AND sale_date>='".$db->real_escape_string($from)."' AND sale_date<='".$db->real_escape_string($to)."'";
$expenseWhere = "company_id=$cid AND expense_date>='".$db->real_escape_string($from)."' AND expense_date<='".$db->real_escape_string($to)."'";

if($branchId){
    $salesWhere .= " AND branch_id=$branchId";
    $expenseWhere .= " AND branch_id=$branchId";
}

$sales = (float)(query_one("SELECT COALESCE(SUM(total_amount),0) total FROM sales WHERE $salesWhere")['total'] ?? 0);
$expenses = (float)(query_one("SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE $expenseWhere")['total'] ?? 0);
$profit = $sales - $expenses;

$detail = $db->query("
    SELECT sale_date trans_date, 'sale' type, invoice_no reference, total_amount amount
    FROM sales
    WHERE $salesWhere
    UNION ALL
    SELECT expense_date trans_date, 'expense' type, category reference, amount
    FROM expenses
    WHERE $expenseWhere
    ORDER BY trans_date DESC
")->fetch_all(MYSQLI_ASSOC);

$salesMonthly = $db->query("
    SELECT DATE_FORMAT(sale_date,'%Y-%m') label, COALESCE(SUM(total_amount),0) total
    FROM sales
    WHERE $salesWhere
    GROUP BY DATE_FORMAT(sale_date,'%Y-%m')
    ORDER BY label
")->fetch_all(MYSQLI_ASSOC);

$expenseMonthly = $db->query("
    SELECT DATE_FORMAT(expense_date,'%Y-%m') label, COALESCE(SUM(amount),0) total
    FROM expenses
    WHERE $expenseWhere
    GROUP BY DATE_FORMAT(expense_date,'%Y-%m')
    ORDER BY label
")->fetch_all(MYSQLI_ASSOC);

$branches = company_branches();
$scopeLabel = $branchOnly ? 'Branch Report' : 'Company Report';
?>

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-graph-up"></i> <?php echo e($scopeLabel); ?></span>
        <h2>Modern Financial Reports</h2>
        <p>
            Company admin sees all branches inside the company. Normal user sees only the assigned branch.
            Filter reports by date and branch for better control.
        </p>
        <div class="hero-mini">
            <div class="box"><small>Sales</small><strong><?php echo reports_money($sales); ?></strong></div>
            <div class="box"><small>Expenses</small><strong><?php echo reports_money($expenses); ?></strong></div>
            <div class="box"><small>Net Income</small><strong><?php echo reports_money($profit); ?></strong></div>
            <div class="box"><small>Transactions</small><strong><?php echo count($detail); ?></strong></div>
        </div>
    </div>

    <div class="soft-card p-4">
        <h5 class="section-title">Filter Report</h5>
        <div class="section-sub">Apply date range and branch filters</div>

        <form class="row g-3">
            <div class="col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="to" value="<?php echo e($to); ?>" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label">Branch</label>
                <select name="branch_id" class="form-select" <?php echo $branchOnly ? 'disabled' : ''; ?>>
                    <option value="0">All branches</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchId==(int)$b['id']?'selected':''; ?>>
                            <?php echo e($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if($branchOnly): ?>
                    <input type="hidden" name="branch_id" value="<?php echo (int)$branchId; ?>">
                <?php endif; ?>
            </div>

            <div class="col-md-3 align-self-end">
                <button class="btn btn-primary w-100">Apply Filter</button>
            </div>
        </form>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-sales">
                <div class="kpi-icon"><i class="bi bi-currency-dollar"></i></div>
                <small>Sales</small>
                <h3><?php echo reports_money($sales); ?></h3>
                <div class="meta">Filtered sales revenue</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-expense">
                <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
                <small>Expenses</small>
                <h3><?php echo reports_money($expenses); ?></h3>
                <div class="meta">Filtered expense amount</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-profit">
                <div class="kpi-icon"><i class="bi bi-bar-chart"></i></div>
                <small>Net Income</small>
                <h3><?php echo reports_money($profit); ?></h3>
                <div class="meta">Sales - Expenses</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-count">
                <div class="kpi-icon"><i class="bi bi-list-check"></i></div>
                <small>Transactions</small>
                <h3><?php echo count($detail); ?></h3>
                <div class="meta">Sales + expense rows</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="soft-card p-4 h-100">
                <h5 class="section-title">Profit Structure</h5>
                <div class="section-sub">Sales, expenses, and net income</div>
                <div class="chart-box sm">
                    <canvas id="profitChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="soft-card p-4 h-100">
                <h5 class="section-title">Monthly Trend</h5>
                <div class="section-sub">Sales and expenses over time</div>
                <div class="chart-box">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="soft-card p-4">
        <h5 class="section-title">Detailed Report</h5>
        <div class="section-sub">Transaction detail list</div>

        <div class="table-wrap">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Reference</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($detail as $d): ?>
                        <tr>
                            <td><?php echo e($d['trans_date']); ?></td>
                            <td>
                                <span class="badge rounded-pill <?php echo $d['type']==='sale' ? 'badge-soft-success' : 'badge-soft-danger'; ?>">
                                    <?php echo e($d['type']); ?>
                                </span>
                            </td>
                            <td><?php echo e($d['reference']); ?></td>
                            <td><strong><?php echo reports_money($d['amount']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if(!$detail): ?>
                        <tr>
                            <td colspan="4"><div class="empty-box m-3">No report records found.</div></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const salesMonthly = <?php echo json_encode($salesMonthly); ?>;
    const expenseMonthly = <?php echo json_encode($expenseMonthly); ?>;

    const labels = [...new Set([
        ...salesMonthly.map(x => x.label),
        ...expenseMonthly.map(x => x.label)
    ])];

    const salesVals = labels.map(l => Number((salesMonthly.find(x => x.label === l) || {}).total || 0));
    const expenseVals = labels.map(l => Number((expenseMonthly.find(x => x.label === l) || {}).total || 0));

    new Chart(document.getElementById('profitChart'), {
        type: 'doughnut',
        data: {
            labels: ['Sales', 'Expenses', 'Net Income'],
            datasets: [{
                data: [
                    <?php echo json_encode((float)$sales); ?>,
                    <?php echo json_encode((float)$expenses); ?>,
                    <?php echo json_encode(max(0,(float)$profit)); ?>
                ],
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Sales',
                    data: salesVals,
                    borderWidth: 1,
                    borderRadius: 8
                },
                {
                    label: 'Expenses',
                    data: expenseVals,
                    borderWidth: 1,
                    borderRadius: 8
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });
    </script>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>