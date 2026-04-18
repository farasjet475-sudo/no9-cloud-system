<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_super_admin();

$pageTitle = 'Subscriptions';
require_once __DIR__ . '/includes/header.php';

$db = db();
$error = '';

$prefillCompanyId = (int)($_GET['company_id'] ?? 0);

/* ========================
   SAVE / UPDATE
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_subscription') {
        $id        = (int)($_POST['id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        $planId    = (int)($_POST['plan_id'] ?? 0);
        $status    = trim($_POST['status'] ?? 'active');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($companyId <= 0 || $planId <= 0 || $startDate === '' || $endDate === '') {
            $error = 'Company, plan, start date, and end date are required.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE subscriptions
                    SET company_id=?, plan_id=?, status=?, start_date=?, end_date=?, notes=?
                    WHERE id=?
                ");
                $stmt->bind_param(
                    'iissssi',
                    $companyId,
                    $planId,
                    $status,
                    $startDate,
                    $endDate,
                    $notes,
                    $id
                );
                $stmt->execute();
                $stmt->close();

                log_activity('update', 'subscriptions', $id);
                flash('success', 'Subscription updated successfully.');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO subscriptions(company_id, plan_id, status, start_date, end_date, notes)
                    VALUES(?,?,?,?,?,?)
                ");
                $stmt->bind_param(
                    'iissss',
                    $companyId,
                    $planId,
                    $status,
                    $startDate,
                    $endDate,
                    $notes
                );
                $stmt->execute();
                $newId = (int)$stmt->insert_id;
                $stmt->close();

                log_activity('create', 'subscriptions', $newId);
                flash('success', 'Subscription added successfully.');
            }

            redirect('subscriptions.php?company_id=' . $companyId);
        }
    }

    if ($action === 'delete_subscription') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $db->prepare("DELETE FROM subscriptions WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        log_activity('delete', 'subscriptions', $id);
        flash('success', 'Subscription deleted successfully.');
        redirect('subscriptions.php');
    }
}

/* ========================
   EDIT
======================== */
$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($edit) {
        $prefillCompanyId = (int)($edit['company_id'] ?? $prefillCompanyId);
    }
}

/* ========================
   FILTERS
======================== */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$planFilter = (int)($_GET['plan_id'] ?? 0);
$companyFilter = (int)($_GET['company_id'] ?? $prefillCompanyId);

$where = "WHERE 1=1";

if ($search !== '') {
    $safe = $db->real_escape_string($search);
    $where .= " AND (
        c.name LIKE '%$safe%' OR
        p.name LIKE '%$safe%' OR
        p.code LIKE '%$safe%' OR
        s.notes LIKE '%$safe%'
    )";
}

if ($statusFilter !== '') {
    $safeStatus = $db->real_escape_string($statusFilter);
    $where .= " AND s.status='$safeStatus'";
}

if ($planFilter > 0) {
    $where .= " AND s.plan_id=".(int)$planFilter;
}

if ($companyFilter > 0) {
    $where .= " AND s.company_id=".(int)$companyFilter;
}

/* ========================
   LOOKUPS
======================== */
$companies = $db->query("
    SELECT id, name
    FROM companies
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

$plans = $db->query("
    SELECT id, name, code, price_monthly, max_branches
    FROM plans
    ORDER BY sort_order ASC, id ASC
")->fetch_all(MYSQLI_ASSOC);

$currentCompany = [];
if ($companyFilter > 0) {
    $currentCompany = query_one("SELECT * FROM companies WHERE id=" . (int)$companyFilter . " LIMIT 1");
}

/* ========================
   DATA
======================== */
$rows = $db->query("
    SELECT
        s.*,
        c.name AS company_name,
        p.name AS plan_name,
        p.code AS plan_code,
        p.price_monthly,
        p.max_branches
    FROM subscriptions s
    LEFT JOIN companies c ON c.id = s.company_id
    LEFT JOIN plans p ON p.id = s.plan_id
    $where
    ORDER BY s.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* ========================
   KPI
======================== */
$totalSubscriptions = count($rows);
$activeCount = 0;
$expiredCount = 0;
$suspendedCount = 0;
$totalMonthlyValue = 0;

foreach ($rows as $r) {
    $status = strtolower((string)($r['status'] ?? ''));

    if ($status === 'active') $activeCount++;
    if ($status === 'expired') $expiredCount++;
    if ($status === 'suspended') $suspendedCount++;

    $totalMonthlyValue += (float)($r['price_monthly'] ?? 0);
}
?>

<style>
.sub-page{display:grid;gap:20px}
.hero-card{
    border:0;
    border-radius:28px;
    background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
    color:#fff;
    padding:28px;
    box-shadow:0 18px 40px rgba(15,23,42,.14);
}
.hero-card h2{margin:0;font-weight:800;font-size:28px}
.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.80)}
.hero-actions .btn{border-radius:14px;font-weight:700}

.kpi-card{
    border-radius:22px;
    padding:18px 20px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{display:block;opacity:.9}
.kpi-card h3{margin:8px 0 0;font-size:1.8rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}

.panel-card{
    border:0;
    border-radius:24px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:24px}
.panel-title{font-size:20px;font-weight:800;color:#0f172a;margin-bottom:4px}
.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}

.form-label{font-weight:700;color:#334155}
.form-control,.form-select{
    min-height:48px;
    border-radius:14px;
    border:1px solid #dbe2ea;
}
.form-control:focus,.form-select:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}
.btn{border-radius:14px;font-weight:700}
.action-btn{border-radius:12px}

.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:20px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
}
.plan-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}
.status-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status-active{background:#dcfce7;color:#166534}
.status-expired{background:#fee2e2;color:#b91c1c}
.status-suspended{background:#fef3c7;color:#92400e}
.status-trial{background:#ede9fe;color:#6d28d9}
.muted-line{color:#64748b;font-size:12px}
.company-pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.10);
    color:#fff;
    font-size:13px;
    font-weight:800;
}
</style>

<div class="container-fluid py-4 sub-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Subscriptions</h2>
                <p>Manage company plans, dates, and subscription status in a simple standard layout.</p>
            </div>
            <div class="hero-actions d-flex gap-2 flex-wrap">
                <?php if (!empty($currentCompany['id'])): ?>
                    <span class="company-pill"><?php echo e($currentCompany['name']); ?></span>
                    <a href="companies.php?edit=<?php echo (int)$currentCompany['id']; ?>" class="btn btn-light">Open Company</a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-light">Back</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Subscriptions</small>
                <h3><?php echo $totalSubscriptions; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Active</small>
                <h3><?php echo $activeCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Expired</small>
                <h3><?php echo $expiredCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Total Plan Value / Month</small>
                <h3><?php echo money($totalMonthlyValue); ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="panel-card">
                <div class="panel-body">
                    <div class="panel-title"><?php echo $edit ? 'Edit Subscription' : 'Add Subscription'; ?></div>
                    <div class="panel-sub">Choose company, choose plan, and set dates/status.</div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="save_subscription">
                        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                        <div class="mb-3">
                            <label class="form-label">Company</label>
                            <select name="company_id" class="form-select" required>
                                <option value="">Select company</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)($edit['company_id'] ?? $companyFilter) === (int)$c['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Plan</label>
                            <select name="plan_id" class="form-select" required>
                                <option value="">Select plan</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)($edit['plan_id'] ?? 0) === (int)$p['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($p['name']); ?> - <?php echo money($p['price_monthly']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required value="<?php echo e($edit['start_date'] ?? date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required value="<?php echo e($edit['end_date'] ?? date('Y-m-d', strtotime('+30 days'))); ?>">
                            </div>
                        </div>

                        <div class="mt-3 mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo (($edit['status'] ?? '') === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo (($edit['status'] ?? '') === 'expired') ? 'selected' : ''; ?>>Expired</option>
                                <option value="suspended" <?php echo (($edit['status'] ?? '') === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="trial" <?php echo (($edit['status'] ?? '') === 'trial') ? 'selected' : ''; ?>>Trial</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="4"><?php echo e($edit['notes'] ?? ''); ?></textarea>
                        </div>

                        <button class="btn btn-primary w-100"><?php echo $edit ? 'Update Subscription' : 'Save Subscription'; ?></button>

                        <?php if ($edit): ?>
                            <a href="subscriptions.php<?php echo $companyFilter > 0 ? '?company_id='.(int)$companyFilter : ''; ?>" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="panel-card mb-4">
                <div class="panel-body">
                    <div class="panel-title">Search Subscriptions</div>
                    <div class="panel-sub">Filter by company, plan, status, or notes.</div>

                    <form class="row g-3">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo e($search); ?>">
                        </div>

                        <div class="col-md-3">
                            <select name="company_id" class="form-select">
                                <option value="0">All Companies</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $companyFilter === (int)$c['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <select name="plan_id" class="form-select">
                                <option value="0">All Plans</option>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>" <?php echo $planFilter === (int)$p['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($p['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <select name="status" class="form-select">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                <option value="trial" <?php echo $statusFilter === 'trial' ? 'selected' : ''; ?>>Trial</option>
                            </select>
                        </div>

                        <div class="col-md-1">
                            <button class="btn btn-dark w-100">Go</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-body">
                    <div class="panel-title">Subscription List</div>
                    <div class="panel-sub">Simple overview of company subscriptions and plans.</div>

                    <div class="table-wrap">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Plan</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th width="220"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo e($r['company_name']); ?></div>
                                                <div class="muted-line">
                                                    <a href="companies.php?edit=<?php echo (int)$r['company_id']; ?>">Open Company</a>
                                                </div>
                                            </td>

                                            <td>
                                                <span class="plan-badge"><?php echo e($r['plan_name']); ?></span>
                                                <div class="muted-line">
                                                    <?php echo e($r['plan_code']); ?> |
                                                    <?php echo money($r['price_monthly']); ?> |
                                                    <?php echo ((int)($r['max_branches'] ?? 0) > 0) ? (int)$r['max_branches'].' branches' : 'Unlimited branches'; ?>
                                                </div>
                                            </td>

                                            <td><?php echo e($r['start_date']); ?></td>
                                            <td><?php echo e($r['end_date']); ?></td>

                                            <td>
                                                <?php
                                                $status = strtolower((string)$r['status']);
                                                $class = 'status-active';
                                                if ($status === 'expired') $class = 'status-expired';
                                                elseif ($status === 'suspended') $class = 'status-suspended';
                                                elseif ($status === 'trial') $class = 'status-trial';
                                                ?>
                                                <span class="status-badge <?php echo $class; ?>"><?php echo e(ucfirst($status)); ?></span>
                                            </td>

                                            <td><?php echo e($r['notes']); ?></td>

                                            <td class="text-end">
                                                <a href="?edit=<?php echo (int)$r['id']; ?><?php echo $companyFilter > 0 ? '&company_id='.(int)$companyFilter : ''; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>

                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this subscription?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="delete_subscription">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <button class="btn btn-sm btn-outline-danger action-btn">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if(!$rows): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-5">No subscriptions found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>