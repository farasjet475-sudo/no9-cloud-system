<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('users');

$pageTitle = 'Users';
require_once __DIR__ . '/includes/header.php';

$db = db();
$isSuper = is_super_admin();
$cid = current_company_id();

$branches = $isSuper ? [] : company_branches();
$plan = function_exists('current_plan') ? current_plan() : ['name' => 'No Plan', 'max_branches' => 0];
$planName = $plan['name'] ?? 'No Plan';

$userLimit = 0;
if (function_exists('plan_limits')) {
    $limits = plan_limits();
    $userLimit = (int)($limits['max_users'] ?? $limits['users'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| ROLES FOR DROPDOWN
|--------------------------------------------------------------------------
*/
if ($isSuper) {
    $rolesStmt = $db->prepare("
        SELECT r.*, c.name AS company_name
        FROM roles r
        LEFT JOIN companies c ON c.id = r.company_id
        ORDER BY r.is_system DESC, r.name ASC
    ");
} else {
    $rolesStmt = $db->prepare("
        SELECT r.*, NULL AS company_name
        FROM roles r
        WHERE r.company_id = ?
        ORDER BY r.name ASC
    ");
    $rolesStmt->bind_param('i', $cid);
}
$rolesStmt->execute();
$roleRows = $rolesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rolesStmt->close();

/*
|--------------------------------------------------------------------------
| HELPER: VALID ROLE CHECK
|--------------------------------------------------------------------------
*/
function users_page_find_role(array $roles, int $roleId): ?array {
    foreach ($roles as $r) {
        if ((int)($r['id'] ?? 0) === $roleId) {
            return $r;
        }
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| HANDLE POST
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action   = trim($_POST['action'] ?? '');
    $id       = (int)($_POST['id'] ?? 0);
    $full     = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $branchId = (int)($_POST['branch_id'] ?? current_branch_id());
    $email    = trim($_POST['email'] ?? '');
    $status   = trim($_POST['status'] ?? 'active');
    $roleId   = (int)($_POST['role_id'] ?? 0);

    if ($action === 'save_user') {
        if ($id > 0) {
            require_module_update('users');
        } else {
            require_module_write('users');
        }

        if ($full === '' || $username === '') {
            flash('error', 'Full name and username are required.');
            redirect('users.php');
        }

        if ($roleId <= 0) {
            flash('error', 'Please select a role.');
            redirect('users.php');
        }

        $selectedRole = users_page_find_role($roleRows, $roleId);
        if (!$selectedRole) {
            flash('error', 'Invalid role selected.');
            redirect('users.php');
        }

        $selectedRoleName = strtolower(trim((string)($selectedRole['name'] ?? '')));
        $selectedRoleCompanyId = (int)($selectedRole['company_id'] ?? 0);
        $selectedRoleIsSystem = (int)($selectedRole['is_system'] ?? 0);

        if (!$isSuper) {
            if ($selectedRoleIsSystem === 1 || $selectedRoleCompanyId !== $cid) {
                flash('error', 'You cannot assign this role.');
                redirect('users.php');
            }
        }

        if (!$isSuper) {
            if ($branchId <= 0) {
                $branchId = current_branch_id();
            }

            if ($branchId > 0 && !can_access_branch($branchId)) {
                flash('error', 'Invalid branch selected.');
                redirect('users.php');
            }
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        if ($id === 0 && !$isSuper) {
            $existingUsers = count_row("SELECT COUNT(*) FROM users WHERE company_id=" . (int)$cid);
            if ($userLimit > 0 && $existingUsers >= $userLimit) {
                flash('error', 'User limit reached for current plan.');
                redirect('users.php');
            }
        }

        $safeUsername = $db->real_escape_string($username);

        if ($isSuper) {
            $duplicate = query_one("
                SELECT id
                FROM users
                WHERE username='$safeUsername'
                  AND id <> $id
                LIMIT 1
            ");
        } else {
            $duplicate = query_one("
                SELECT id
                FROM users
                WHERE company_id=" . (int)$cid . "
                  AND username='$safeUsername'
                  AND id <> $id
                LIMIT 1
            ");
        }

        if ($duplicate) {
            flash('error', 'Username already exists.');
            redirect('users.php');
        }

        if ($id > 0) {
            $stmt = $db->prepare("
                SELECT u.*, r.name AS role_name, r.company_id AS role_company_id, r.is_system
                FROM users u
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $targetUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$targetUser) {
                flash('error', 'User not found.');
                redirect('users.php');
            }

            if (!$isSuper && (int)($targetUser['company_id'] ?? 0) !== $cid) {
                flash('error', 'You cannot edit this user.');
                redirect('users.php');
            }

            $targetRoleName = strtolower(trim((string)($targetUser['role_name'] ?? $targetUser['role'] ?? '')));
            if (!$isSuper && in_array($targetRoleName, ['superadmin', 'super_admin'], true)) {
                flash('error', 'You cannot edit superadmin user.');
                redirect('users.php');
            }

            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                if ($isSuper) {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET full_name=?, username=?, email=?, branch_id=?, role_id=?, status=?, password_hash=?
                        WHERE id=?
                    ");
                    $stmt->bind_param('sssiissi', $full, $username, $email, $branchId, $roleId, $status, $hash, $id);
                } else {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET full_name=?, username=?, email=?, branch_id=?, role_id=?, status=?, password_hash=?
                        WHERE id=? AND company_id=?
                    ");
                    $stmt->bind_param('sssiissii', $full, $username, $email, $branchId, $roleId, $status, $hash, $id, $cid);
                }
            } else {
                if ($isSuper) {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET full_name=?, username=?, email=?, branch_id=?, role_id=?, status=?
                        WHERE id=?
                    ");
                    $stmt->bind_param('sssiisi', $full, $username, $email, $branchId, $roleId, $status, $id);
                } else {
                    $stmt = $db->prepare("
                        UPDATE users
                        SET full_name=?, username=?, email=?, branch_id=?, role_id=?, status=?
                        WHERE id=? AND company_id=?
                    ");
                    $stmt->bind_param('sssiisii', $full, $username, $email, $branchId, $roleId, $status, $id, $cid);
                }
            }

            $stmt->execute();
            $stmt->close();

            if ($id === current_user_id()) {
                rbac_refresh_user_session();
            }

            log_activity('update', 'users', $id);
            flash('success', 'User updated successfully.');
            redirect('users.php');
        } else {
            $hash = password_hash($password !== '' ? $password : 'admin123', PASSWORD_DEFAULT);

            if ($isSuper) {
                $companyIdForInsert = (int)($_POST['company_id'] ?? 0);
                if ($companyIdForInsert <= 0 && $selectedRoleCompanyId > 0) {
                    $companyIdForInsert = $selectedRoleCompanyId;
                }

                $stmt = $db->prepare("
                    INSERT INTO users(company_id, branch_id, full_name, username, email, password_hash, role_id, role, status)
                    VALUES(?,?,?,?,?,?,?,?,?)
                ");
                $legacyRole = $selectedRoleName ?: 'cashier';
                $stmt->bind_param(
                    'iissssiss',
                    $companyIdForInsert,
                    $branchId,
                    $full,
                    $username,
                    $email,
                    $hash,
                    $roleId,
                    $legacyRole,
                    $status
                );
            } else {
                $stmt = $db->prepare("
                    INSERT INTO users(company_id, branch_id, full_name, username, email, password_hash, role_id, role, status)
                    VALUES(?,?,?,?,?,?,?,?,?)
                ");
                $legacyRole = $selectedRoleName ?: 'cashier';
                $stmt->bind_param(
                    'iissssiss',
                    $cid,
                    $branchId,
                    $full,
                    $username,
                    $email,
                    $hash,
                    $roleId,
                    $legacyRole,
                    $status
                );
            }

            $stmt->execute();
            $newUserId = (int)$stmt->insert_id;
            $stmt->close();

            log_activity('create', 'users', $newUserId);
            flash('success', 'User added successfully.');
            redirect('users.php');
        }
    }
}

/*
|--------------------------------------------------------------------------
| DELETE USER
|--------------------------------------------------------------------------
*/
if (isset($_GET['delete'])) {
    require_module_delete('users');

    $id = (int)$_GET['delete'];

    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$targetUser) {
        flash('error', 'User not found.');
        redirect('users.php');
    }

    if (!$isSuper && (int)($targetUser['company_id'] ?? 0) !== $cid) {
        flash('error', 'You cannot delete this user.');
        redirect('users.php');
    }

    $targetRoleName = strtolower(trim((string)($targetUser['role_name'] ?? $targetUser['role'] ?? '')));
    if (in_array($targetRoleName, ['superadmin', 'super_admin'], true)) {
        flash('error', 'Superadmin user cannot be deleted.');
        redirect('users.php');
    }

    if ((int)$id === current_user_id()) {
        flash('error', 'You cannot delete your own account.');
        redirect('users.php');
    }

    if ($isSuper) {
        $stmt = $db->prepare("DELETE FROM users WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE id=? AND company_id=? LIMIT 1");
        $stmt->bind_param('ii', $id, $cid);
    }
    $stmt->execute();
    $stmt->close();

    log_activity('delete', 'users', $id);
    flash('success', 'User deleted.');
    redirect('users.php');
}

/*
|--------------------------------------------------------------------------
| EDIT USER
|--------------------------------------------------------------------------
*/
$edit = null;
if (isset($_GET['edit'])) {
    require_module_update('users');

    $editId = (int)$_GET['edit'];

    $sql = "
        SELECT u.*, r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
    ";

    if (!$isSuper) {
        $sql .= " AND u.company_id = ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);

    if ($isSuper) {
        $stmt->bind_param('i', $editId);
    } else {
        $stmt->bind_param('ii', $editId, $cid);
    }

    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$branchFilter = (int)($_GET['branch_id'] ?? 0);
$roleFilter = (int)($_GET['role_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$where = [];
$params = [];
$types = '';

if ($isSuper) {
    $where[] = '1=1';
} else {
    $where[] = 'u.company_id = ?';
    $params[] = $cid;
    $types .= 'i';
}

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($branchFilter > 0) {
    $where[] = "u.branch_id = ?";
    $params[] = $branchFilter;
    $types .= 'i';
}

if ($roleFilter > 0) {
    $where[] = "u.role_id = ?";
    $params[] = $roleFilter;
    $types .= 'i';
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = "u.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql = "
    SELECT
        u.*,
        b.name AS branch_name,
        r.name AS role_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE
            WHEN LOWER(COALESCE(r.name, u.role)) IN ('superadmin','super_admin') THEN 1
            WHEN LOWER(COALESCE(r.name, u.role)) = 'admin' THEN 2
            WHEN LOWER(COALESCE(r.name, u.role)) = 'manager' THEN 3
            WHEN LOWER(COALESCE(r.name, u.role)) = 'cashier' THEN 4
            ELSE 5
        END,
        u.id DESC
";

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalUsers = count($rows);
$activeCount = 0;
$inactiveCount = 0;
$adminCount = 0;
$cashierCount = 0;

foreach ($rows as $r) {
    $roleName = strtolower(trim((string)($r['role_name'] ?? $r['role'] ?? '')));
    if (($r['status'] ?? 'active') === 'active') $activeCount++;
    if (($r['status'] ?? 'active') === 'inactive') $inactiveCount++;
    if ($roleName === 'admin') $adminCount++;
    if ($roleName === 'cashier') $cashierCount++;
}
?>

<style>
.user-shell{display:grid;gap:18px}
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
.filter-box .form-control,.filter-box .form-select{min-height:46px;border-radius:14px}
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
.user-badge{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.role-admin{background:#ede9fe;color:#6d28d9}
.role-cashier{background:#dbeafe;color:#1d4ed8}
.role-manager{background:#dcfce7;color:#166534}
.role-super{background:#fee2e2;color:#b91c1c}
.status-active{background:#dcfce7;color:#166534}
.status-inactive{background:#e5e7eb;color:#374151}
.action-btn{border-radius:12px}
.avatar{
    width:40px;height:40px;border-radius:50%;
    background:#dbeafe;color:#1d4ed8;
    display:inline-flex;align-items:center;justify-content:center;
    font-weight:800;margin-right:10px;
}
.user-name{
    display:flex;align-items:center;
}
.meta-text{color:#64748b;font-size:12px}
.limit-note{
    border-radius:16px;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:14px 16px;
}
</style>

<div class="container-fluid py-4 user-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>User Management</h3>
                <p>Manage system users, branches, roles, and access permissions in a modern and professional way.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Users</small>
                <h3><?php echo (int)$totalUsers; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Active Users</small>
                <h3><?php echo (int)$activeCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Admins</small>
                <h3><?php echo (int)$adminCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Cashiers</small>
                <h3><?php echo (int)$cashierCount; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="soft-card p-4 mb-4">
                <div class="section-title"><?php echo $edit ? 'Edit User' : 'Add User'; ?></div>
                <div class="section-sub mb-3">Create and manage users with role-based access.</div>

                <div class="limit-note mb-3">
                    <div><strong>Current Plan:</strong> <?php echo e($planName); ?></div>
                    <div><strong>User Limit:</strong> <?php echo $userLimit > 0 ? $userLimit : 'Unlimited'; ?></div>
                    <div><strong>Total Current Users:</strong> <?php echo (int)$totalUsers; ?></div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                    <?php if ($isSuper): ?>
                        <div class="mb-3">
                            <label class="form-label">Company ID</label>
                            <input
                                name="company_id"
                                type="number"
                                class="form-control"
                                placeholder="Company ID"
                                value="<?php echo e((string)($edit['company_id'] ?? '')); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input name="full_name" class="form-control" placeholder="Full name" required value="<?php echo e($edit['full_name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input name="username" class="form-control" placeholder="Username" required value="<?php echo e($edit['username'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input name="email" class="form-control" placeholder="Email" value="<?php echo e($edit['email'] ?? ''); ?>">
                    </div>

                    <?php if (!$isSuper): ?>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php echo ((int)($edit['branch_id'] ?? current_branch_id()) === (int)$b['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label">Branch ID</label>
                        <input name="branch_id" type="number" class="form-control" placeholder="Branch ID" value="<?php echo e((string)($edit['branch_id'] ?? '')); ?>">
                    </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role_id" class="form-select" required>
                            <option value="">Select role</option>
                            <?php foreach($roleRows as $rr): ?>
                                <option value="<?php echo (int)$rr['id']; ?>" <?php echo ((int)($edit['role_id'] ?? 0) === (int)$rr['id']) ? 'selected' : ''; ?>>
                                    <?php
                                        echo e($rr['name']);
                                        if ($isSuper && !empty($rr['company_name'])) {
                                            echo ' - ' . e($rr['company_name']);
                                        }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?php echo (($edit['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo (($edit['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="<?php echo $edit ? 'Leave blank to keep current password' : 'Password'; ?>">
                    </div>

                    <button class="btn btn-primary w-100"><?php echo $edit ? 'Update User' : 'Save User'; ?></button>

                    <?php if ($edit): ?>
                        <a href="users.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4 filter-box mb-4">
                <div class="section-title">Search Users</div>
                <div class="section-sub mb-3">Filter by name, username, email, branch, role, or status.</div>

                <form class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Search users..." value="<?php echo e($search); ?>">
                    </div>

                    <div class="col-md-3">
                        <select name="branch_id" class="form-select">
                            <option value="0">All Branches</option>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchFilter === (int)$b['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($b['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <select name="role_id" class="form-select">
                            <option value="0">All Roles</option>
                            <?php foreach($roleRows as $rr): ?>
                                <option value="<?php echo (int)$rr['id']; ?>" <?php echo $roleFilter === (int)$rr['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($rr['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <button class="btn btn-dark">Search</button>
                        <a href="users.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">User List</div>
                <div class="section-sub mb-3">Users grouped by role and branch.</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th width="180"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows as $r): ?>
                                    <?php
                                        $rowRole = strtolower(trim((string)($r['role_name'] ?? $r['role'] ?? '')));
                                        $roleClass = 'role-cashier';
                                        $roleLabel = $r['role_name'] ?? $r['role'] ?? 'User';

                                        if (in_array($rowRole, ['superadmin', 'super_admin'], true)) {
                                            $roleClass = 'role-super';
                                        } elseif ($rowRole === 'admin') {
                                            $roleClass = 'role-admin';
                                        } elseif ($rowRole === 'manager') {
                                            $roleClass = 'role-manager';
                                        } elseif ($rowRole === 'cashier') {
                                            $roleClass = 'role-cashier';
                                        }

                                        $statusClass = (($r['status'] ?? 'active') === 'active') ? 'status-active' : 'status-inactive';
                                        $isProtected = in_array($rowRole, ['superadmin', 'super_admin'], true) || (int)$r['id'] === current_user_id();
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="user-name">
                                                <span class="avatar"><?php echo e(strtoupper(substr($r['full_name'] ?: $r['username'], 0, 1))); ?></span>
                                                <div>
                                                    <div class="fw-bold"><?php echo e($r['full_name'] ?: $r['username']); ?></div>
                                                    <div class="meta-text">User account</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo e($r['username']); ?></td>
                                        <td><?php echo e($r['email'] ?? '-'); ?></td>
                                        <td><span class="user-badge <?php echo e($roleClass); ?>"><?php echo e($roleLabel); ?></span></td>
                                        <td><?php echo e($r['branch_name'] ?? '-'); ?></td>
                                        <td><span class="user-badge <?php echo e($statusClass); ?>"><?php echo e($r['status'] ?? 'active'); ?></span></td>
                                        <td class="text-end">
                                            <?php if (can_update('users')): ?>
                                                <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>
                                            <?php endif; ?>

                                            <?php if (can_delete('users') && !$isProtected): ?>
                                                <a onclick="return confirm('Delete user?')" href="?delete=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger action-btn">Delete</a>
                                            <?php else: ?>
                                                <?php if ($isProtected): ?>
                                                    <span class="text-muted small">Protected</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">No users found.</td>
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