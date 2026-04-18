<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_super_admin();

$pageTitle = 'Companies';
require_once __DIR__ . '/includes/header.php';

$db = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $code   = trim($_POST['code'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    $adminId       = (int)($_POST['admin_id'] ?? 0);
    $adminName     = trim($_POST['admin_name'] ?? '');
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminEmail    = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';

    if ($name === '' || $code === '') {
        $error = 'Company name and company code are required.';
    } else {
        $safeCode = $db->real_escape_string($code);
        $dupSql = "SELECT id FROM companies WHERE company_code='$safeCode'";
        if ($id > 0) {
            $dupSql .= " AND id <> $id";
        }
        $dup = query_one($dupSql);

        if ($dup) {
            $error = 'Company code already exists.';
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE companies
                    SET name=?, company_code=?, email=?, phone=?, status=?
                    WHERE id=?
                ");
                $stmt->bind_param('sssssi', $name, $code, $email, $phone, $status, $id);
                $stmt->execute();
                $stmt->close();

                save_setting('company_name', $name, $id);
                save_setting('company_title', $name, $id);
                save_setting('company_email', $email, $id);
                save_setting('company_phone', $phone, $id);

                if ($adminId > 0 && $adminUsername !== '') {
                    if ($adminPassword !== '') {
                        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            UPDATE users
                            SET full_name=?, username=?, email=?, password_hash=?
                            WHERE id=? AND company_id=? AND role='admin'
                        ");
                        $stmt->bind_param('ssssii', $adminName, $adminUsername, $adminEmail, $hash, $adminId, $id);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users
                            SET full_name=?, username=?, email=?
                            WHERE id=? AND company_id=? AND role='admin'
                        ");
                        $stmt->bind_param('sssii', $adminName, $adminUsername, $adminEmail, $adminId, $id);
                    }
                    $stmt->execute();
                    $stmt->close();
                }

                log_activity('update', 'companies', $id);
                flash('success', 'Company updated successfully.');
                redirect('companies.php');
            } else {
                if ($adminName === '') {
                    $adminName = $name . ' Admin';
                }

                if ($adminUsername === '') {
                    $adminUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $code)) . '_admin';
                }

                if ($adminEmail === '') {
                    $adminEmail = $email;
                }

                if ($adminPassword === '') {
                    $adminPassword = 'admin123';
                }

                $db->begin_transaction();

                try {
                    $stmt = $db->prepare("
                        INSERT INTO companies(name, company_code, email, phone, status)
                        VALUES(?,?,?,?,?)
                    ");
                    $stmt->bind_param('sssss', $name, $code, $email, $phone, $status);
                    $stmt->execute();
                    $companyId = (int)$stmt->insert_id;
                    $stmt->close();

                    $stmt = $db->prepare("
                        INSERT INTO branches(company_id, name, address, status)
                        VALUES(?, 'Main Branch', 'Main office', 'active')
                    ");
                    $stmt->bind_param('i', $companyId);
                    $stmt->execute();
                    $branchId = (int)$stmt->insert_id;
                    $stmt->close();

                    if (function_exists('create_default_company_roles')) {
                        create_default_company_roles($companyId);
                    }

                    $adminRoleId = 0;
                    $stmt = $db->prepare("
                        SELECT id
                        FROM roles
                        WHERE company_id = ? AND name = 'admin'
                        LIMIT 1
                    ");
                    $stmt->bind_param('i', $companyId);
                    $stmt->execute();
                    $adminRole = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($adminRole) {
                        $adminRoleId = (int)$adminRole['id'];
                    }

                    $pass = password_hash($adminPassword, PASSWORD_DEFAULT);

                    if ($adminRoleId > 0) {
                        $legacyRole = 'admin';
                        $stmt = $db->prepare("
                            INSERT INTO users(company_id, branch_id, full_name, username, email, password_hash, role_id, role, status)
                            VALUES(?,?,?,?,?,?,?,?, 'active')
                        ");
                        $stmt->bind_param('iissssis', $companyId, $branchId, $adminName, $adminUsername, $adminEmail, $pass, $adminRoleId, $legacyRole);
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO users(company_id, branch_id, full_name, username, email, password_hash, role, status)
                            VALUES(?,?,?,?,?,?,'admin','active')
                        ");
                        $stmt->bind_param('iissss', $companyId, $branchId, $adminName, $adminUsername, $adminEmail, $pass);
                    }
                    $stmt->execute();
                    $stmt->close();

                    $planId = (int)(query_one("SELECT id FROM plans WHERE code='basic' LIMIT 1")['id'] ?? 1);

                    $stmt = $db->prepare("
                        INSERT INTO subscriptions(company_id, plan_id, start_date, end_date, status)
                        VALUES(?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'trial')
                    ");
                    $stmt->bind_param('ii', $companyId, $planId);
                    $stmt->execute();
                    $stmt->close();

                    save_setting('company_name', $name, $companyId);
                    save_setting('company_title', $name, $companyId);
                    save_setting('company_email', $email, $companyId);
                    save_setting('company_phone', $phone, $companyId);

                    log_activity('create', 'companies', $companyId);

                    $db->commit();

                    $link = company_login_link($code, $adminUsername);
                    flash('success', 'Company created successfully. Admin: ' . $adminUsername . ' / ' . $adminPassword . ' | Login: ' . $link);
                    redirect('companies.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $error = 'Failed to create company: ' . $e->getMessage();
                }
            }
        }
    }
}

if (isset($_GET['toggle'])) {
    $toggleId = (int)$_GET['toggle'];
    $db->query("UPDATE companies SET status = IF(status='active','inactive','active') WHERE id = $toggleId");
    flash('success', 'Company status updated.');
    redirect('companies.php');
}

$edit = null;
$adminEdit = [];

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $edit = $db->query("SELECT * FROM companies WHERE id = $editId LIMIT 1")->fetch_assoc();
    $adminEdit = $edit ? company_primary_admin((int)$edit['id']) : [];
}

$search = trim($_GET['search'] ?? '');
$where = '';

if ($search !== '') {
    $safe = $db->real_escape_string($search);
    $where = "WHERE c.name LIKE '%$safe%' OR c.company_code LIKE '%$safe%' OR c.email LIKE '%$safe%' OR c.phone LIKE '%$safe%'";
}

$rows = $db->query("
    SELECT
        c.*,
        (SELECT COUNT(*) FROM branches b WHERE b.company_id = c.id) AS branch_count,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count,
        (
            SELECT p.name
            FROM subscriptions s
            LEFT JOIN plans p ON p.id = s.plan_id
            WHERE s.company_id = c.id
            ORDER BY s.id DESC
            LIMIT 1
        ) AS plan_name,
        (
            SELECT s.status
            FROM subscriptions s
            WHERE s.company_id = c.id
            ORDER BY s.id DESC
            LIMIT 1
        ) AS subscription_status
    FROM companies c
    $where
    ORDER BY c.id DESC
")->fetch_all(MYSQLI_ASSOC);

$totalCompanies = count($rows);
$activeCompanies = 0;
$inactiveCompanies = 0;

foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'active') $activeCompanies++;
    if (($r['status'] ?? '') === 'inactive') $inactiveCompanies++;
}
?>

<style>
.company-page{display:grid;gap:20px}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    padding:24px;
    box-shadow:0 16px 36px rgba(15,23,42,.14);
}
.hero-card h2{margin:0;font-size:28px;font-weight:800}
.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.80)}
.hero-actions .btn{border-radius:12px;font-weight:700}

.stat-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.stat-card small{display:block;opacity:.9}
.stat-card h3{margin:8px 0 0;font-size:1.8rem;font-weight:800}
.bg1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.bg2{background:linear-gradient(135deg,#10b981,#059669)}
.bg3{background:linear-gradient(135deg,#f59e0b,#d97706)}

.panel-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:22px}
.panel-title{font-size:20px;font-weight:800;color:#0f172a;margin-bottom:4px}
.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}

.form-label{font-weight:700;color:#334155}
.form-control,.form-select{
    min-height:46px;
    border-radius:12px;
    border:1px solid #dbe2ea;
}
.form-control:focus,.form-select:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}
.btn{border-radius:12px;font-weight:700}
.action-btn{border-radius:10px}

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
.status-badge,.code-badge,.plan-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status-active{background:#dcfce7;color:#166534}
.status-inactive{background:#fee2e2;color:#b91c1c}
.code-badge{background:#eff6ff;color:#1d4ed8}
.plan-badge{background:#ede9fe;color:#6d28d9}
.small-muted{color:#64748b;font-size:12px}
.section-line{
    margin:18px 0;
    border-top:1px solid #e5e7eb;
    padding-top:18px;
}
</style>

<div class="container-fluid py-4 company-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Companies</h2>
                <p>Manage tenant companies and their admin accounts in a simple standard layout.</p>
            </div>
            <div class="hero-actions">
                <a href="dashboard.php" class="btn btn-light">Back</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="stat-card bg1">
                <small>Total Companies</small>
                <h3><?php echo (int)$totalCompanies; ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg2">
                <small>Active Companies</small>
                <h3><?php echo (int)$activeCompanies; ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg3">
                <small>Inactive Companies</small>
                <h3><?php echo (int)$inactiveCompanies; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="panel-card">
                <div class="panel-body">
                    <div class="panel-title"><?php echo $edit ? 'Edit Company' : 'Add Company'; ?></div>
                    <div class="panel-sub">Fill in the company details and admin account.</div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
                        <input type="hidden" name="admin_id" value="<?php echo (int)($adminEdit['id'] ?? 0); ?>">

                        <div class="mb-3">
                            <label class="form-label">Company Name</label>
                            <input name="name" class="form-control" required value="<?php echo e($edit['name'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Company Code</label>
                            <input name="code" class="form-control" required value="<?php echo e($edit['company_code'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input name="email" class="form-control" value="<?php echo e($edit['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input name="phone" class="form-control" value="<?php echo e($edit['phone'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo (($edit['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (($edit['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="section-line">
                            <div class="fw-bold mb-3">Admin Account</div>

                            <div class="mb-3">
                                <label class="form-label">Admin Name</label>
                                <input name="admin_name" class="form-control" value="<?php echo e($adminEdit['full_name'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Admin Username</label>
                                <input name="admin_username" class="form-control" value="<?php echo e($adminEdit['username'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Admin Email</label>
                                <input name="admin_email" class="form-control" value="<?php echo e($adminEdit['email'] ?? ''); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Admin Password</label>
                                <input name="admin_password" class="form-control" placeholder="<?php echo $edit ? 'Leave blank to keep current password' : 'Admin password'; ?>" value="<?php echo $edit ? '' : 'admin123'; ?>">
                            </div>
                        </div>

                        <button class="btn btn-primary w-100"><?php echo $edit ? 'Update Company' : 'Save Company'; ?></button>

                        <?php if ($edit): ?>
                            <a href="companies.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="panel-card mb-4">
                <div class="panel-body">
                    <div class="panel-title">Search Companies</div>
                    <div class="panel-sub">Search by name, code, email, or phone.</div>

                    <form class="row g-3">
                        <div class="col-md-10">
                            <input type="text" name="search" class="form-control" placeholder="Search companies..." value="<?php echo e($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-dark w-100">Search</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="panel-card">
                <div class="panel-body">
                    <div class="panel-title">Company List</div>
                    <div class="panel-sub">Simple company overview with direct actions.</div>

                    <div class="table-wrap">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>Status</th>
                                        <th>Branches</th>
                                        <th>Users</th>
                                        <th>Plan</th>
                                        <th width="240"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($rows as $r): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo e($r['name']); ?></div>
                                                <div class="small-muted mt-1">
                                                    <span class="code-badge"><?php echo e($r['company_code']); ?></span>
                                                </div>
                                                <div class="small-muted mt-1"><?php echo e($r['email'] ?? ''); ?> <?php echo e($r['phone'] ?? ''); ?></div>
                                            </td>

                                            <td>
                                                <?php if (($r['status'] ?? '') === 'active'): ?>
                                                    <span class="status-badge status-active">Active</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">Inactive</span>
                                                <?php endif; ?>
                                            </td>

                                            <td><?php echo (int)$r['branch_count']; ?></td>
                                            <td><?php echo (int)$r['user_count']; ?></td>

                                            <td>
                                                <?php if (!empty($r['plan_name'])): ?>
                                                    <span class="plan-badge"><?php echo e($r['plan_name']); ?></span>
                                                    <div class="small-muted mt-1"><?php echo e(ucfirst((string)($r['subscription_status'] ?? 'unknown'))); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted small">No plan</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-primary action-btn" href="?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                                                <a class="btn btn-sm btn-outline-warning action-btn" href="?toggle=<?php echo (int)$r['id']; ?>">Toggle</a>
                                                <a class="btn btn-sm btn-outline-dark action-btn" href="subscriptions.php?company_id=<?php echo (int)$r['id']; ?>">Subscription</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if(!$rows): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-5">No companies found.</td>
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