<?php
$pageTitle = 'Health Check';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

$db = db();

function hc_table_exists(mysqli $db, string $table): bool {
    $table = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '$table'");
    return $q && $q->num_rows > 0;
}

function hc_count_safe(mysqli $db, string $sql): int {
    try {
        $row = $db->query($sql)->fetch_row();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function hc_status_badge(bool $ok): string {
    return $ok
        ? '<span class="badge rounded-pill bg-success-subtle text-success-emphasis">OK</span>'
        : '<span class="badge rounded-pill bg-danger-subtle text-danger-emphasis">Issue</span>';
}

$checks = [];

/* DB connection */
$dbOk = false;
try {
    $db->query("SELECT 1");
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
}
$checks[] = [
    'label' => 'Database Connection',
    'ok' => $dbOk,
    'detail' => $dbOk ? 'Database connection is working normally.' : 'Database connection failed.'
];

/* Core tables */
$coreTables = ['users', 'companies', 'branches', 'products', 'sales', 'expenses', 'notifications'];
foreach ($coreTables as $table) {
    $exists = hc_table_exists($db, $table);
    $checks[] = [
        'label' => "Table: {$table}",
        'ok' => $exists,
        'detail' => $exists ? "Table `{$table}` exists." : "Table `{$table}` is missing."
    ];
}

/* Basic counts */
$usersCount = hc_table_exists($db, 'users') ? hc_count_safe($db, "SELECT COUNT(*) FROM users") : 0;
$productsCount = hc_table_exists($db, 'products') ? hc_count_safe($db, "SELECT COUNT(*) FROM products") : 0;
$salesCount = hc_table_exists($db, 'sales') ? hc_count_safe($db, "SELECT COUNT(*) FROM sales") : 0;
$expensesCount = hc_table_exists($db, 'expenses') ? hc_count_safe($db, "SELECT COUNT(*) FROM expenses") : 0;

/* Notifications columns */
$notifUserIdOk = false;
$notifSenderIdOk = false;
if (hc_table_exists($db, 'notifications')) {
    $q1 = $db->query("SHOW COLUMNS FROM notifications LIKE 'user_id'");
    $q2 = $db->query("SHOW COLUMNS FROM notifications LIKE 'sender_id'");
    $notifUserIdOk = $q1 && $q1->num_rows > 0;
    $notifSenderIdOk = $q2 && $q2->num_rows > 0;
}
$checks[] = [
    'label' => 'Notifications.user_id',
    'ok' => $notifUserIdOk,
    'detail' => $notifUserIdOk ? 'user_id column exists.' : 'user_id column is missing.'
];
$checks[] = [
    'label' => 'Notifications.sender_id',
    'ok' => $notifSenderIdOk,
    'detail' => $notifSenderIdOk ? 'sender_id column exists.' : 'sender_id column is missing.'
];

/* Low stock */
$lowStockCount = 0;
if (hc_table_exists($db, 'products')) {
    try {
        $lowStockCount = hc_count_safe($db, "SELECT COUNT(*) FROM products WHERE stock_qty <= min_stock");
    } catch (Throwable $e) {
        $lowStockCount = 0;
    }
}

$totalChecks = count($checks);
$passedChecks = count(array_filter($checks, fn($c) => $c['ok']));
$failedChecks = $totalChecks - $passedChecks;
$healthPercent = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100) : 0;
?>

<style>
:root{
    --hc-shadow:0 14px 32px rgba(15,23,42,.07);
    --hc-shadow-lg:0 22px 50px rgba(15,23,42,.12);
    --hc-line:#e2e8f0;
}
.hc-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:30px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 20%),
        radial-gradient(circle at bottom left, rgba(16,185,129,.15), transparent 25%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 48%, #0f766e 100%);
    box-shadow:var(--hc-shadow-lg);
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
    font-size:1.08rem;
    font-weight:800;
}
.soft-card{
    border:1px solid var(--hc-line);
    border-radius:24px;
    background:#fff;
    box-shadow:var(--hc-shadow);
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
.kpi-icon{
    position:absolute;
    right:18px;
    top:18px;
    font-size:2rem;
    opacity:.20;
}
.bg-ok{background:linear-gradient(135deg,#10b981,#059669)}
.bg-bad{background:linear-gradient(135deg,#ef4444,#dc2626)}
.bg-main{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.bg-warn{background:linear-gradient(135deg,#f59e0b,#d97706)}
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
.progress-soft{
    height:12px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
}
.progress-soft .bar{
    height:100%;
    border-radius:999px;
    background:linear-gradient(90deg,#2563eb,#10b981);
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
</style>

<div class="container-fluid py-4 hc-shell">

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-heart-pulse-fill"></i> System Health</span>
        <h2>Modern Health Check Dashboard</h2>
        <p>
            This page checks database connectivity, core tables, notification columns, and basic system readiness.
        </p>

        <div class="hero-mini">
            <div class="box"><small>Total Checks</small><strong><?php echo $totalChecks; ?></strong></div>
            <div class="box"><small>Passed</small><strong><?php echo $passedChecks; ?></strong></div>
            <div class="box"><small>Failed</small><strong><?php echo $failedChecks; ?></strong></div>
            <div class="box"><small>Health Score</small><strong><?php echo $healthPercent; ?>%</strong></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-main">
                <div class="kpi-icon"><i class="bi bi-list-check"></i></div>
                <small>Total Checks</small>
                <h3><?php echo $totalChecks; ?></h3>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-ok">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <small>Passed</small>
                <h3><?php echo $passedChecks; ?></h3>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-bad">
                <div class="kpi-icon"><i class="bi bi-x-circle"></i></div>
                <small>Failed</small>
                <h3><?php echo $failedChecks; ?></h3>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card bg-warn">
                <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
                <small>Health Score</small>
                <h3><?php echo $healthPercent; ?>%</h3>
            </div>
        </div>
    </div>

    <div class="soft-card p-4">
        <h5 class="section-title">Overall Status</h5>
        <div class="section-sub">System readiness summary</div>

        <div class="mb-3">
            <div class="d-flex justify-content-between mb-2">
                <span class="fw-semibold">Health Progress</span>
                <span><?php echo $healthPercent; ?>%</span>
            </div>
            <div class="progress-soft">
                <div class="bar" style="width: <?php echo $healthPercent; ?>%;"></div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-3">
                <div class="border rounded-4 p-3 bg-light">
                    <small class="text-muted d-block">Users</small>
                    <strong><?php echo $usersCount; ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 bg-light">
                    <small class="text-muted d-block">Products</small>
                    <strong><?php echo $productsCount; ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 bg-light">
                    <small class="text-muted d-block">Sales</small>
                    <strong><?php echo $salesCount; ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border rounded-4 p-3 bg-light">
                    <small class="text-muted d-block">Low Stock</small>
                    <strong><?php echo $lowStockCount; ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div class="soft-card p-4">
        <h5 class="section-title">Detailed Checks</h5>
        <div class="section-sub">Each core system check and its status</div>

        <div class="table-wrap">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Check</th>
                            <th>Status</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($check['label']); ?></td>
                                <td><?php echo hc_status_badge((bool)$check['ok']); ?></td>
                                <td><?php echo e($check['detail']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>