<?php
$pageTitle = 'Branches';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) {
    redirect('dashboard.php');
}

if (!is_company_admin()) {
    flash('error', 'Only company admin can manage branches.');
    redirect('dashboard.php');
}

$cid = current_company_id();
$db  = db();

$message = '';
$error = '';

$plan = current_plan();
$maxBranches = (int)($plan['max_branches'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_branch') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $error = 'Branch name is required.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE branches
                    SET name=?, phone=?, email=?, address=?
                    WHERE id=? AND company_id=?
                ");
                $stmt->bind_param('ssssii', $name, $phone, $email, $address, $id, $cid);
                $stmt->execute();
                $stmt->close();

                flash('success', 'Branch updated successfully.');
                redirect('branches.php');
            } else {
                $currentCount = count_row("SELECT COUNT(*) FROM branches WHERE company_id=".(int)$cid);

                if ($maxBranches > 0 && $currentCount >= $maxBranches) {
                    $error = 'Your current plan allows only '.$maxBranches.' branch(es).';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO branches(company_id, name, phone, email, address)
                        VALUES(?,?,?,?,?)
                    ");
                    $stmt->bind_param('issss', $cid, $name, $phone, $email, $address);
                    $stmt->execute();
                    $stmt->close();

                    flash('success', 'Branch added successfully.');
                    redirect('branches.php');
                }
            }
        }
    }

    if ($action === 'delete_branch') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            if (current_branch_id() === $id) {
                $error = 'You cannot delete the currently active branch.';
            } else {
                $stmt = $db->prepare("DELETE FROM branches WHERE id=? AND company_id=?");
                $stmt->bind_param('ii', $id, $cid);
                $stmt->execute();
                $stmt->close();

                flash('success', 'Branch deleted successfully.');
                redirect('branches.php');
            }
        }
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $stmt = $db->prepare("
        SELECT *
        FROM branches
        WHERE id=? AND company_id=?
        LIMIT 1
    ");
    $stmt->bind_param('ii', $editId, $cid);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$rows = $db->query("
    SELECT *
    FROM branches
    WHERE company_id=".(int)$cid."
    ORDER BY id DESC
")->fetch_all(MYSQLI_ASSOC);

$totalBranches = count($rows);
?>

<style>
.branch-shell{display:grid;gap:18px}
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
}
.kpi-card small{opacity:.92;font-size:.84rem}
.kpi-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669)}
.section-title{font-weight:800;margin-bottom:6px}
.section-sub{color:#64748b;font-size:14px}
.form-label{font-weight:700}
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
.branch-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}
.action-btn{border-radius:12px}
</style>

<div class="container-fluid py-4 branch-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Branch Management</h3>
                <p>Manage company branches in a clean and professional way.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="kpi-card kpi-1">
                <small>Total Branches</small>
                <h3><?php echo $totalBranches; ?></h3>
            </div>
        </div>
        <div class="col-md-6">
            <div class="kpi-card kpi-2">
                <small>Plan Limit</small>
                <h3><?php echo $maxBranches > 0 ? $maxBranches : 'Unlimited'; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="soft-card p-4">
                <div class="section-title"><?php echo $edit ? 'Edit Branch' : 'Add Branch'; ?></div>
                <div class="section-sub mb-3">Create and manage company branch records.</div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_branch">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                    <div class="mb-3">
                        <label class="form-label">Branch Name</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo e($edit['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo e($edit['phone'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?php echo e($edit['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?php echo e($edit['address'] ?? ''); ?></textarea>
                    </div>

                    <button class="btn btn-primary w-100">
                        <?php echo $edit ? 'Update Branch' : 'Save Branch'; ?>
                    </button>

                    <?php if ($edit): ?>
                        <a href="branches.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4">
                <div class="section-title">Branch List</div>
                <div class="section-sub mb-3">All branches under your company.</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th width="180"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td>
                                            <span class="branch-badge"><?php echo e($r['name']); ?></span>
                                            <?php if ((int)$r['id'] === current_branch_id()): ?>
                                                <div class="small text-success mt-1">Current branch</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($r['phone']); ?></td>
                                        <td><?php echo e($r['email']); ?></td>
                                        <td><?php echo e($r['address']); ?></td>
                                        <td class="text-end">
                                            <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>

                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this branch?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete_branch">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger action-btn">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-5">No branches found.</td>
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