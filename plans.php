<?php
require_once __DIR__ . '/includes/auth.php';
require_super_admin();

$pageTitle = 'Plans';
require_once __DIR__ . '/includes/header.php';

$db = db();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $code         = trim($_POST['code'] ?? '');
        $priceMonthly = (float)($_POST['price_monthly'] ?? 0);
        $description  = trim($_POST['description'] ?? '');
        $sortOrder    = (int)($_POST['sort_order'] ?? 0);
        $maxBranches  = (int)($_POST['max_branches'] ?? 0);

        if ($name === '' || $code === '') {
            $error = 'Plan name and code are required.';
        } else {
            $safeCode = $db->real_escape_string($code);
            $duplicateSql = "SELECT id FROM plans WHERE code='$safeCode'";
            if ($id > 0) {
                $duplicateSql .= " AND id <> $id";
            }
            $duplicate = query_one($duplicateSql);

            if ($duplicate) {
                $error = 'Plan code already exists.';
            } else {
                if ($id > 0) {
                    $stmt = $db->prepare("
                        UPDATE plans
                        SET name=?, code=?, price_monthly=?, description=?, sort_order=?, max_branches=?
                        WHERE id=?
                    ");
                    $stmt->bind_param(
                        'ssdsiii',
                        $name,
                        $code,
                        $priceMonthly,
                        $description,
                        $sortOrder,
                        $maxBranches,
                        $id
                    );
                    $stmt->execute();
                    $stmt->close();

                    flash('success', 'Plan updated successfully.');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO plans(name, code, price_monthly, description, sort_order, max_branches)
                        VALUES(?,?,?,?,?,?)
                    ");
                    $stmt->bind_param(
                        'ssdsii',
                        $name,
                        $code,
                        $priceMonthly,
                        $description,
                        $sortOrder,
                        $maxBranches
                    );
                    $stmt->execute();
                    $stmt->close();

                    flash('success', 'Plan added successfully.');
                }

                redirect('plans.php');
            }
        }
    }

    if ($action === 'delete_plan') {
        $id = (int)($_POST['id'] ?? 0);

        $subCount = count_row("SELECT COUNT(*) FROM subscriptions WHERE plan_id=$id");
        if ($subCount > 0) {
            flash('error', 'This plan is linked to subscriptions and cannot be deleted.');
            redirect('plans.php');
        }

        $stmt = $db->prepare("DELETE FROM plans WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Plan deleted successfully.');
        redirect('plans.php');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$search = trim($_GET['search'] ?? '');
$where = '';
if ($search !== '') {
    $safe = $db->real_escape_string($search);
    $where = "WHERE name LIKE '%$safe%' OR code LIKE '%$safe%' OR description LIKE '%$safe%'";
}

$rows = $db->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id) AS subscription_count
    FROM plans p
    $where
    ORDER BY p.sort_order ASC, p.id ASC
")->fetch_all(MYSQLI_ASSOC);

$totalPlans = count($rows);
$totalActiveLinks = 0;
$totalRevenuePotential = 0;
$limitedPlans = 0;

foreach ($rows as $r) {
    $totalActiveLinks += (int)($r['subscription_count'] ?? 0);
    $totalRevenuePotential += (float)($r['price_monthly'] ?? 0);

    if ((int)($r['max_branches'] ?? 0) > 0) {
        $limitedPlans++;
    }
}
?>

<style>
.plan-shell{display:grid;gap:18px}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    box-shadow:0 16px 36px rgba(15,23,42,.18);
    padding:26px;
}
.hero-card h3{margin:0;font-weight:800}
.hero-card p{margin:6px 0 0;color:rgba(255,255,255,.82)}
.soft-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.kpi-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{opacity:.92;font-size:.84rem}
.kpi-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.section-title{font-weight:800;margin-bottom:6px}
.section-sub{color:#64748b;font-size:14px}
.form-label{font-weight:700}
.filter-box .form-control{min-height:46px;border-radius:14px}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
}
.plan-code{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}
.limit-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    background:#ecfccb;
    color:#3f6212;
    font-size:12px;
    font-weight:700;
}
.unlimited-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    background:#ede9fe;
    color:#6d28d9;
    font-size:12px;
    font-weight:700;
}
.action-btn{border-radius:12px}
</style>

<div class="container-fluid py-4 plan-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Plan Management</h3>
                <p>Create, edit, and manage SaaS subscription plans in a modern and professional layout.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Plans</small>
                <h3><?php echo $totalPlans; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Linked Subscriptions</small>
                <h3><?php echo $totalActiveLinks; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Total Price / Month</small>
                <h3><?php echo money($totalRevenuePotential); ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Limited Branch Plans</small>
                <h3><?php echo $limitedPlans; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="soft-card p-4">
                <div class="section-title"><?php echo $edit ? 'Edit Plan' : 'Add Plan'; ?></div>
                <div class="section-sub mb-3">Configure code, monthly price, branch limit, and description.</div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_plan">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                    <div class="mb-3">
                        <label class="form-label">Plan Name</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo e($edit['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Plan Code</label>
                        <input type="text" name="code" class="form-control" required value="<?php echo e($edit['code'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price / Month</label>
                        <input type="number" step="0.01" min="0" name="price_monthly" class="form-control" value="<?php echo e((string)($edit['price_monthly'] ?? 0)); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Max Branches</label>
                        <input type="number" min="0" name="max_branches" class="form-control" value="<?php echo e((string)($edit['max_branches'] ?? 0)); ?>">
                        <div class="small text-muted mt-1">0 = Unlimited</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" min="0" name="sort_order" class="form-control" value="<?php echo e((string)($edit['sort_order'] ?? 0)); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?php echo e($edit['description'] ?? ''); ?></textarea>
                    </div>

                    <button class="btn btn-primary w-100"><?php echo $edit ? 'Update Plan' : 'Save Plan'; ?></button>

                    <?php if ($edit): ?>
                        <a href="plans.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4 filter-box mb-4">
                <div class="section-title">Search Plans</div>
                <div class="section-sub mb-3">Find plans by name, code, or description.</div>

                <form class="row g-3">
                    <div class="col-md-10">
                        <input type="text" name="search" class="form-control" placeholder="Search plans..." value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-dark w-100">Search</button>
                    </div>
                </form>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">Plan List</div>
                <div class="section-sub mb-3">All subscription plans available in the SaaS system.</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Plan</th>
                                    <th>Code</th>
                                    <th>Price / Month</th>
                                    <th>Max Branches</th>
                                    <th>Description</th>
                                    <th>Subscriptions</th>
                                    <th width="180"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo e($r['name']); ?></td>
                                        <td><span class="plan-code"><?php echo e($r['code']); ?></span></td>
                                        <td><?php echo money($r['price_monthly']); ?></td>
                                        <td>
                                            <?php if ((int)$r['max_branches'] > 0): ?>
                                                <span class="limit-badge"><?php echo (int)$r['max_branches']; ?> branches</span>
                                            <?php else: ?>
                                                <span class="unlimited-badge">Unlimited</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($r['description']); ?></td>
                                        <td><?php echo (int)($r['subscription_count'] ?? 0); ?></td>
                                        <td class="text-end">
                                            <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>

                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this plan?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete_plan">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger action-btn">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">No plans found.</td>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>