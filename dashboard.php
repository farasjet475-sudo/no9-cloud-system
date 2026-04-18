<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$db = db();

/* ================= ROLE HELPERS ================= */
function dashboard_is_company_admin(): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $u = current_user();
    $role = strtolower((string)($u['role'] ?? ''));
    return in_array($role, ['admin', 'company_admin', 'manager'], true);
}

function dashboard_scope_is_branch_only(): bool {
    if (is_super_admin()) return false;
    return !dashboard_is_company_admin();
}

function dashboard_money($value): string {
    return '$' . number_format((float)$value, 2);
}

/* ================= SUPER ADMIN / SAAS ================= */
if (is_super_admin()) {
    $companies = (int)count_row("SELECT COUNT(*) FROM companies");
    $activeSubs = (int)count_row("SELECT COUNT(*) FROM subscriptions WHERE status='active'");
    $pendingPayments = (int)count_row("SELECT COUNT(*) FROM payment_proofs WHERE status='pending'");
    $monthlyRevenue = (float)count_row("
        SELECT COALESCE(SUM(amount),0)
        FROM payment_proofs
        WHERE status='approved'
          AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')
    ");

    $planRows = $db->query("
        SELECT p.name, COUNT(s.id) total
        FROM plans p
        LEFT JOIN subscriptions s ON s.plan_id=p.id
        GROUP BY p.id
        ORDER BY p.sort_order, p.id
    ")->fetch_all(MYSQLI_ASSOC);

    $companyGrowth = $db->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') label, COUNT(*) total
        FROM companies
        GROUP BY DATE_FORMAT(created_at,'%Y-%m')
        ORDER BY label
    ")->fetch_all(MYSQLI_ASSOC);

    $recentPayments = [];
    if (
        $db->query("SHOW TABLES LIKE 'payment_proofs'") &&
        $db->query("SHOW TABLES LIKE 'companies'")
    ) {
        $recentPayments = $db->query("
            SELECT pp.amount, pp.status, pp.created_at, c.name AS company_name
            FROM payment_proofs pp
            LEFT JOIN companies c ON c.id = pp.company_id
            ORDER BY pp.created_at DESC
            LIMIT 6
        ")->fetch_all(MYSQLI_ASSOC);
    }
}

/* ================= COMPANY ADMIN / USER ================= */
else {
    $cid = current_company_id();
    $bid = current_branch_id();
    $branchOnly = dashboard_scope_is_branch_only();

    $salesWhere = " WHERE company_id = $cid ";
    $expenseWhere = " WHERE company_id = $cid ";
    $productWhere = " WHERE company_id = $cid ";
    $customerWhere = " WHERE company_id = $cid ";
    $invoiceWhere = " WHERE company_id = $cid ";
    $saleJoinWhere = " WHERE s.company_id = $cid ";

    if ($branchOnly) {
        $salesWhere .= " AND branch_id = $bid ";
        $expenseWhere .= " AND branch_id = $bid ";
        $productWhere .= " AND branch_id = $bid ";
        $customerWhere .= " AND branch_id = $bid ";
        $invoiceWhere .= " AND branch_id = $bid ";
        $saleJoinWhere .= " AND s.branch_id = $bid ";
    }

    $scopeLabel = $branchOnly ? 'Branch Dashboard' : 'Company Dashboard';

    $salesRevenue = (float)count_row("SELECT COALESCE(SUM(total_amount),0) FROM sales {$salesWhere}");
    $expenses = (float)count_row("SELECT COALESCE(SUM(amount),0) FROM expenses {$expenseWhere}");

    $cogs = (float)count_row("
        SELECT COALESCE(SUM(si.qty * COALESCE(p.cost_price,0)),0)
        FROM sale_items si
        INNER JOIN sales s ON s.id = si.sale_id
        LEFT JOIN products p ON p.id = si.product_id
        {$saleJoinWhere}
    ");

    $grossProfit = $salesRevenue - $cogs;
    $netProfit = $grossProfit - $expenses;

    $inventoryValue = (float)count_row("
        SELECT COALESCE(SUM(stock_qty * COALESCE(cost_price,0)),0)
        FROM products
        {$productWhere}
    ");

    $stockQty = (float)count_row("
        SELECT COALESCE(SUM(stock_qty),0)
        FROM products
        {$productWhere}
    ");

    $customers = (int)count_row("
        SELECT COUNT(*)
        FROM customers
        {$customerWhere}
    ");

    $lowStockCount = (int)count_row("
        SELECT COUNT(*)
        FROM products
        {$productWhere} AND stock_qty <= min_stock
    ");

    $outOfStockCount = (int)count_row("
        SELECT COUNT(*)
        FROM products
        {$productWhere} AND stock_qty <= 0
    ");

    $accountsReceivable = 0;
    $invoiceCount = 0;
    try {
        $accountsReceivable = (float)count_row("
            SELECT COALESCE(SUM(due_amount),0)
            FROM invoices
            {$invoiceWhere}
        ");
        $invoiceCount = (int)count_row("
            SELECT COUNT(*)
            FROM invoices
            {$invoiceWhere}
        ");
    } catch (Throwable $e) {
        $accountsReceivable = 0;
        $invoiceCount = 0;
    }

    if ($branchOnly) {
        $salesData = $db->query("
            SELECT DATE_FORMAT(sale_date,'%Y-%m') label, COALESCE(SUM(total_amount),0) total
            FROM sales
            WHERE company_id=$cid AND branch_id=$bid
            GROUP BY DATE_FORMAT(sale_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);

        $expenseData = $db->query("
            SELECT DATE_FORMAT(expense_date,'%Y-%m') label, COALESCE(SUM(amount),0) total
            FROM expenses
            WHERE company_id=$cid AND branch_id=$bid
            GROUP BY DATE_FORMAT(expense_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);

        $cogsData = $db->query("
            SELECT DATE_FORMAT(s.sale_date,'%Y-%m') label,
                   COALESCE(SUM(si.qty * COALESCE(p.cost_price,0)),0) total
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.company_id=$cid AND s.branch_id=$bid
            GROUP BY DATE_FORMAT(s.sale_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $salesData = $db->query("
            SELECT DATE_FORMAT(sale_date,'%Y-%m') label, COALESCE(SUM(total_amount),0) total
            FROM sales
            WHERE company_id=$cid
            GROUP BY DATE_FORMAT(sale_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);

        $expenseData = $db->query("
            SELECT DATE_FORMAT(expense_date,'%Y-%m') label, COALESCE(SUM(amount),0) total
            FROM expenses
            WHERE company_id=$cid
            GROUP BY DATE_FORMAT(expense_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);

        $cogsData = $db->query("
            SELECT DATE_FORMAT(s.sale_date,'%Y-%m') label,
                   COALESCE(SUM(si.qty * COALESCE(p.cost_price,0)),0) total
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE s.company_id=$cid
            GROUP BY DATE_FORMAT(s.sale_date,'%Y-%m')
            ORDER BY label
        ")->fetch_all(MYSQLI_ASSOC);
    }

    $branchProfit = $db->query("
        SELECT 
            b.name,
            COALESCE((
                SELECT SUM(s.total_amount)
                FROM sales s
                WHERE s.branch_id=b.id AND s.company_id=b.company_id
            ),0) AS sales_total,
            COALESCE((
                SELECT SUM(e.amount)
                FROM expenses e
                WHERE e.branch_id=b.id AND e.company_id=b.company_id
            ),0) AS expense_total
        FROM branches b
        WHERE b.company_id=$cid
        ORDER BY b.name
    ")->fetch_all(MYSQLI_ASSOC);

    if ($branchOnly) {
        $topProducts = $db->query("
            SELECT 
                si.product_name,
                COALESCE(SUM(si.qty),0) qty_sold,
                COALESCE(SUM(si.line_total),0) total_sales
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            WHERE s.company_id=$cid AND s.branch_id=$bid
            GROUP BY si.product_name
            ORDER BY qty_sold DESC
            LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);

        $lowStockItems = $db->query("
            SELECT name, stock_qty, min_stock
            FROM products
            WHERE company_id=$cid AND branch_id=$bid AND stock_qty <= min_stock
            ORDER BY stock_qty ASC
            LIMIT 6
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $topProducts = $db->query("
            SELECT 
                si.product_name,
                COALESCE(SUM(si.qty),0) qty_sold,
                COALESCE(SUM(si.line_total),0) total_sales
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            WHERE s.company_id=$cid
            GROUP BY si.product_name
            ORDER BY qty_sold DESC
            LIMIT 5
        ")->fetch_all(MYSQLI_ASSOC);

        $lowStockItems = $db->query("
            SELECT name, stock_qty, min_stock
            FROM products
            WHERE company_id=$cid AND stock_qty <= min_stock
            ORDER BY stock_qty ASC
            LIMIT 6
        ")->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<style>
:root{
    --dash-radius:24px;
    --dash-shadow:0 14px 32px rgba(15,23,42,.07);
    --dash-shadow-lg:0 22px 50px rgba(15,23,42,.12);
    --dash-line:#e2e8f0;
}
.dash-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:30px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 20%),
        radial-gradient(circle at bottom left, rgba(16,185,129,.15), transparent 25%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 48%, #0f766e 100%);
    box-shadow:var(--dash-shadow-lg);
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
    max-width:780px;
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
    min-width:150px;
}
.hero-mini .box small{
    display:block;
    color:rgba(255,255,255,.78);
}
.hero-mini .box strong{
    font-size:1.12rem;
    font-weight:800;
}

.dashboard-card{
    border:1px solid var(--dash-line);
    border-radius:var(--dash-radius);
    background:#fff;
    box-shadow:var(--dash-shadow);
    height:100%;
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
    right:-20px;
    bottom:-20px;
    border-radius:50%;
    background:rgba(255,255,255,.10);
}
.kpi-card h3{
    margin:10px 0 8px;
    font-size:1.8rem;
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
.bg-sales{background:linear-gradient(135deg,#2563eb,#1d4ed8);}
.bg-cogs{background:linear-gradient(135deg,#f59e0b,#d97706);}
.bg-gross{background:linear-gradient(135deg,#10b981,#059669);}
.bg-net{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.bg-expense{background:linear-gradient(135deg,#ef4444,#dc2626);}
.bg-inventory{background:linear-gradient(135deg,#0ea5e9,#0284c7);}
.bg-receivable{background:linear-gradient(135deg,#14b8a6,#0f766e);}
.bg-stock{background:linear-gradient(135deg,#64748b,#475569);}
.chart-wrap{position:relative;height:340px}
.chart-wrap-sm{position:relative;height:290px}
.summary-table th{
    width:52%;
    background:#f8fafc;
    color:#334155;
    font-weight:700;
}
.widget-list .item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:11px 0;
    border-bottom:1px solid #e5e7eb;
}
.widget-list .item:last-child{border-bottom:0}
.badge-soft-danger{background:#fee2e2;color:#b91c1c}
.badge-soft-warning{background:#fef3c7;color:#b45309}
.badge-soft-success{background:#dcfce7;color:#15803d}
.badge-soft-info{background:#dbeafe;color:#1d4ed8}
.section-title{
    font-weight:800;
    margin-bottom:14px;
    color:#0f172a;
}
.section-sub{
    color:#64748b;
    font-size:.92rem;
    margin-top:-6px;
    margin-bottom:14px;
}
.mini-box{
    border:1px solid #e2e8f0;
    border-radius:18px;
    background:#fff;
    padding:14px 16px;
}
.empty-box{
    border:1px dashed #cbd5e1;
    border-radius:18px;
    padding:18px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
}
@media (max-width: 768px){
    .hero-card h2{font-size:1.55rem}
}
</style>

<div class="container-fluid py-4 dash-shell">

<?php if (is_super_admin()): ?>

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-cloud-check"></i> SaaS Super Admin</span>
        <h2>Modern SaaS Control Dashboard</h2>
        <p>
            Monitor all companies, subscriptions, payment proofs, plan distribution, and platform revenue from one control center.
        </p>
        <div class="hero-mini">
            <div class="box"><small>Companies</small><strong><?php echo $companies; ?></strong></div>
            <div class="box"><small>Active Subs</small><strong><?php echo $activeSubs; ?></strong></div>
            <div class="box"><small>Pending Payments</small><strong><?php echo $pendingPayments; ?></strong></div>
            <div class="box"><small>Monthly Revenue</small><strong><?php echo dashboard_money($monthlyRevenue); ?></strong></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-sales">
                <div class="kpi-icon"><i class="bi bi-buildings"></i></div>
                <small>Companies</small>
                <h3><?php echo $companies; ?></h3>
                <div class="meta">Registered SaaS companies</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-gross">
                <div class="kpi-icon"><i class="bi bi-patch-check"></i></div>
                <small>Active Subscriptions</small>
                <h3><?php echo $activeSubs; ?></h3>
                <div class="meta">Currently running plans</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-expense">
                <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                <small>Pending Payments</small>
                <h3><?php echo $pendingPayments; ?></h3>
                <div class="meta">Awaiting approval</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-net">
                <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
                <small>Monthly Revenue</small>
                <h3><?php echo dashboard_money($monthlyRevenue); ?></h3>
                <div class="meta">Approved income this month</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Plan Distribution</h5>
                <div class="section-sub">Subscription totals by SaaS plan</div>
                <div class="chart-wrap-sm">
                    <canvas id="planChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Company Growth</h5>
                <div class="section-sub">New companies by month</div>
                <div class="chart-wrap-sm">
                    <canvas id="companyGrowthChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-card p-4">
        <h5 class="section-title">Recent Payment Activity</h5>
        <div class="section-sub">Latest payment proofs across the SaaS</div>

        <?php if (!empty($recentPayments)): ?>
            <div class="widget-list">
                <?php foreach ($recentPayments as $rp): ?>
                    <?php $st = strtolower((string)($rp['status'] ?? 'pending')); ?>
                    <div class="item">
                        <div>
                            <div class="fw-semibold"><?php echo e($rp['company_name'] ?: 'Unknown Company'); ?></div>
                            <small class="text-muted"><?php echo e($rp['created_at']); ?></small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold"><?php echo dashboard_money($rp['amount'] ?? 0); ?></div>
                            <span class="badge rounded-pill <?php echo $st === 'approved' ? 'badge-soft-success' : ($st === 'pending' ? 'badge-soft-warning' : 'badge-soft-danger'); ?>">
                                <?php echo e(ucfirst($st)); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">No recent payment activity found.</div>
        <?php endif; ?>
    </div>

    <script>
    new Chart(document.getElementById('planChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($planRows,'name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('intval', array_column($planRows,'total'))); ?>,
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    new Chart(document.getElementById('companyGrowthChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($companyGrowth,'label')); ?>,
            datasets: [{
                label: 'Companies',
                data: <?php echo json_encode(array_map('intval', array_column($companyGrowth,'total'))); ?>,
                borderWidth: 1,
                borderRadius: 10
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display:false } },
            scales: { y: { beginAtZero:true } }
        }
    });
    </script>

<?php else: ?>

    <div class="hero-card">
        <span class="hero-badge">
            <i class="bi bi-speedometer2"></i>
            <?php echo e($scopeLabel); ?>
        </span>
        <h2>Modern Business Dashboard</h2>
        <p>
            Company admin sees all branches inside the same company. Regular user sees only the assigned branch.
            This keeps reporting secure and role-based.
        </p>
        <div class="hero-mini">
            <div class="box"><small>Sales</small><strong><?php echo dashboard_money($salesRevenue); ?></strong></div>
            <div class="box"><small>Expenses</small><strong><?php echo dashboard_money($expenses); ?></strong></div>
            <div class="box"><small>Net Profit</small><strong><?php echo dashboard_money($netProfit); ?></strong></div>
            <div class="box"><small>Inventory</small><strong><?php echo dashboard_money($inventoryValue); ?></strong></div>
        </div>
    </div>

    <div class="row g-3 mb-1">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-sales">
                <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <small>Sales Revenue</small>
                <h3><?php echo dashboard_money($salesRevenue); ?></h3>
                <div class="meta"><?php echo $branchOnly ? 'This branch only' : 'All company branches'; ?></div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-cogs">
                <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
                <small>COGS</small>
                <h3><?php echo dashboard_money($cogs); ?></h3>
                <div class="meta">Direct stock cost</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-gross">
                <div class="kpi-icon"><i class="bi bi-bar-chart"></i></div>
                <small>Gross Profit</small>
                <h3><?php echo dashboard_money($grossProfit); ?></h3>
                <div class="meta">Sales - COGS</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-net">
                <div class="kpi-icon"><i class="bi bi-wallet2"></i></div>
                <small>Net Profit</small>
                <h3><?php echo dashboard_money($netProfit); ?></h3>
                <div class="meta">Gross - Expenses</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-expense">
                <div class="kpi-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <small>Total Expenses</small>
                <h3><?php echo dashboard_money($expenses); ?></h3>
                <div class="meta">Operating expenses</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-inventory">
                <div class="kpi-icon"><i class="bi bi-archive"></i></div>
                <small>Inventory Value</small>
                <h3><?php echo dashboard_money($inventoryValue); ?></h3>
                <div class="meta">Stock cost valuation</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-receivable">
                <div class="kpi-icon"><i class="bi bi-credit-card-2-front"></i></div>
                <small>Accounts Receivable</small>
                <h3><?php echo dashboard_money($accountsReceivable); ?></h3>
                <div class="meta">Open invoice balance</div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="kpi-card bg-stock">
                <div class="kpi-icon"><i class="bi bi-stack"></i></div>
                <small>Stock Qty</small>
                <h3><?php echo number_format($stockQty,2); ?></h3>
                <div class="meta">Available units</div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Income Statement Trend</h5>
                <div class="section-sub">Sales, COGS, expenses, gross profit, and net profit by month</div>
                <div class="chart-wrap">
                    <canvas id="incomeChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="dashboard-card p-4 mb-4">
                <h5 class="section-title">Profit Structure</h5>
                <div class="section-sub">Current financial composition</div>
                <div class="chart-wrap-sm">
                    <canvas id="profitPieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$branchOnly): ?>
    <div class="row g-4 mb-4">
        <div class="col-lg-12">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Branch Comparison</h5>
                <div class="section-sub">Company admin can compare all company branches</div>
                <div class="chart-wrap-sm">
                    <canvas id="branchChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Financial Statement Snapshot</h5>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0 summary-table">
                        <tbody>
                            <tr><th>Sales Revenue</th><td><?php echo dashboard_money($salesRevenue); ?></td></tr>
                            <tr><th>Cost of Goods Sold (COGS)</th><td><?php echo dashboard_money($cogs); ?></td></tr>
                            <tr>
                                <th>Gross Profit</th>
                                <td class="<?php echo $grossProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo dashboard_money($grossProfit); ?>
                                </td>
                            </tr>
                            <tr><th>Operating Expenses</th><td><?php echo dashboard_money($expenses); ?></td></tr>
                            <tr>
                                <th>Net Profit / Loss</th>
                                <td class="<?php echo $netProfit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo dashboard_money($netProfit); ?>
                                </td>
                            </tr>
                            <tr><th>Inventory Value</th><td><?php echo dashboard_money($inventoryValue); ?></td></tr>
                            <tr><th>Accounts Receivable</th><td><?php echo dashboard_money($accountsReceivable); ?></td></tr>
                            <tr><th>Total Customers</th><td><?php echo $customers; ?></td></tr>
                            <tr><th>Total Invoices</th><td><?php echo $invoiceCount; ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Business Quick Widgets</h5>
                <div class="widget-list">
                    <div class="item">
                        <span>Low Stock Items</span>
                        <span class="badge rounded-pill badge-soft-warning"><?php echo $lowStockCount; ?></span>
                    </div>
                    <div class="item">
                        <span>Out of Stock</span>
                        <span class="badge rounded-pill badge-soft-danger"><?php echo $outOfStockCount; ?></span>
                    </div>
                    <div class="item">
                        <span>Customers</span>
                        <span class="badge rounded-pill badge-soft-info"><?php echo $customers; ?></span>
                    </div>
                    <div class="item">
                        <span>Invoices</span>
                        <span class="badge rounded-pill badge-soft-success"><?php echo $invoiceCount; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Low Stock Widget</h5>
                <?php if (!empty($lowStockItems)): ?>
                    <div class="widget-list">
                        <?php foreach ($lowStockItems as $item): ?>
                            <div class="item">
                                <div>
                                    <div class="fw-semibold"><?php echo e($item['name']); ?></div>
                                    <small class="text-muted">Min: <?php echo number_format((float)$item['min_stock'],2); ?></small>
                                </div>
                                <span class="badge rounded-pill badge-soft-danger"><?php echo number_format((float)$item['stock_qty'],2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-box">No low stock items found.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Top Products</h5>
                <?php if (!empty($topProducts)): ?>
                    <div class="widget-list">
                        <?php foreach($topProducts as $tp): ?>
                            <div class="item">
                                <div>
                                    <div class="fw-semibold"><?php echo e($tp['product_name']); ?></div>
                                    <small class="text-muted">Qty Sold: <?php echo number_format((float)$tp['qty_sold'],2); ?></small>
                                </div>
                                <strong><?php echo dashboard_money($tp['total_sales']); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-box">No product sales yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="dashboard-card p-4">
                <h5 class="section-title">Scope Information</h5>
                <div class="mini-box mb-3">
                    <strong>View Scope:</strong><br>
                    <?php echo $branchOnly ? 'This user sees only the assigned branch.' : 'This admin sees all branches inside the same company.'; ?>
                </div>
                <div class="mini-box">
                    <strong>SaaS Security Rule:</strong><br>
                    Super Admin sees the whole system, company admin sees only company data, and normal users see only branch data.
                </div>
            </div>
        </div>
    </div>

    <script>
    const salesData = <?php echo json_encode($salesData); ?>;
    const expenseData = <?php echo json_encode($expenseData); ?>;
    const cogsData = <?php echo json_encode($cogsData); ?>;
    const branchProfit = <?php echo json_encode($branchProfit); ?>;

    const labels = [...new Set([
        ...salesData.map(x => x.label),
        ...expenseData.map(x => x.label),
        ...cogsData.map(x => x.label)
    ])];

    const salesVals = labels.map(l => Number((salesData.find(x => x.label === l) || {}).total || 0));
    const expenseVals = labels.map(l => Number((expenseData.find(x => x.label === l) || {}).total || 0));
    const cogsVals = labels.map(l => Number((cogsData.find(x => x.label === l) || {}).total || 0));
    const grossVals = labels.map((l, i) => salesVals[i] - cogsVals[i]);
    const netVals = labels.map((l, i) => grossVals[i] - expenseVals[i]);

    new Chart(document.getElementById('incomeChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Sales Revenue', data: salesVals, borderWidth: 1, borderRadius: 8 },
                { label: 'COGS', data: cogsVals, borderWidth: 1, borderRadius: 8 },
                { label: 'Expenses', data: expenseVals, borderWidth: 1, borderRadius: 8 },
                { label: 'Gross Profit', data: grossVals, type: 'line', tension: 0.35, borderWidth: 3 },
                { label: 'Net Profit', data: netVals, type: 'line', tension: 0.35, borderWidth: 3 }
            ]
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });

    new Chart(document.getElementById('profitPieChart'), {
        type: 'doughnut',
        data: {
            labels: ['Sales', 'COGS', 'Expenses', 'Net Profit'],
            datasets: [{
                data: [
                    <?php echo json_encode((float)$salesRevenue); ?>,
                    <?php echo json_encode((float)$cogs); ?>,
                    <?php echo json_encode((float)$expenses); ?>,
                    <?php echo json_encode(max(0, (float)$netProfit)); ?>
                ],
                borderWidth: 1
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    <?php if (!$branchOnly): ?>
    new Chart(document.getElementById('branchChart'), {
        type: 'bar',
        data: {
            labels: branchProfit.map(x => x.name),
            datasets: [{
                label: 'Branch Net Profit',
                data: branchProfit.map(x => Number(x.sales_total || 0) - Number(x.expense_total || 0)),
                borderWidth: 1,
                borderRadius: 10
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
    <?php endif; ?>
    </script>

<?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>