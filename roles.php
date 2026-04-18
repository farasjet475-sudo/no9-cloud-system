<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('roles');

$pageTitle = 'Roles';
require_once __DIR__ . '/includes/header.php';

$db = db();
$isSuper = is_super_admin();
$cid = current_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_role') {
        require_module_write('roles');

        $name = trim($_POST['name'] ?? '');
        $isSystem = ($isSuper && !empty($_POST['is_system'])) ? 1 : 0;

        if ($name === '') {
            flash('error', 'Role name is required.');
            redirect('roles.php');
        }

        if (strtolower($name) === 'superadmin' && !$isSuper) {
            flash('error', 'You cannot create superadmin role.');
            redirect('roles.php');
        }

        if ($isSuper && $isSystem) {
            $stmt = $db->prepare("
                INSERT INTO roles (company_id, name, is_system)
                VALUES (NULL, ?, 1)
            ");
            $stmt->bind_param('s', $name);
        } else {
            $stmt = $db->prepare("
                INSERT INTO roles (company_id, name, is_system)
                VALUES (?, ?, 0)
            ");
            $stmt->bind_param('is', $cid, $name);
        }

        if ($stmt->execute()) {
            $stmt->close();
            flash('success', 'Role created successfully.');
        } else {
            $stmt->close();
            flash('error', 'Failed to create role. It may already exist.');
        }

        redirect('roles.php');
    }

    if ($action === 'delete_role') {
        require_module_delete('roles');

        $roleId = (int)($_POST['role_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$role) {
            flash('error', 'Role not found.');
            redirect('roles.php');
        }

        $roleCompanyId = (int)($role['company_id'] ?? 0);
        $roleName = strtolower(trim((string)($role['name'] ?? '')));
        $roleIsSystem = (int)($role['is_system'] ?? 0);

        if ($roleName === 'superadmin') {
            flash('error', 'Superadmin role cannot be deleted.');
            redirect('roles.php');
        }

        if (!$isSuper && ($roleIsSystem === 1 || $roleCompanyId !== $cid)) {
            flash('error', 'You cannot delete this role.');
            redirect('roles.php');
        }

        $stmt = $db->prepare("SELECT COUNT(*) total FROM users WHERE role_id = ?");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)($row['total'] ?? 0) > 0) {
            flash('error', 'This role is assigned to users. Remove it from users first.');
            redirect('roles.php');
        }

        $stmt = $db->prepare("DELETE FROM roles WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Role deleted successfully.');
        redirect('roles.php');
    }
}

$search = trim($_GET['search'] ?? '');
$params = [];
$types = '';

if ($isSuper) {
    $sql = "
        SELECT r.*, c.name AS company_name
        FROM roles r
        LEFT JOIN companies c ON c.id = r.company_id
        WHERE 1=1
    ";

    if ($search !== '') {
        $sql .= " AND (r.name LIKE ? OR c.name LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $sql .= " ORDER BY r.is_system DESC, r.name ASC";
} else {
    $sql = "
        SELECT r.*, NULL AS company_name
        FROM roles r
        WHERE r.company_id = ?
    ";
    $params[] = $cid;
    $types .= 'i';

    if ($search !== '') {
        $sql .= " AND r.name LIKE ?";
        $like = '%' . $search . '%';
        $params[] = $like;
        $types .= 's';
    }

    $sql .= " ORDER BY r.name ASC";
}

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$roles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalRoles = count($roles);
$systemRoles = 0;
$companyRoles = 0;

foreach ($roles as $r) {
    if ((int)($r['is_system'] ?? 0) === 1) $systemRoles++;
    else $companyRoles++;
}
?>

<style>
.roles-page{display:grid;gap:20px}
.hero-card{
    border:0;
    border-radius:26px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    padding:28px;
    box-shadow:0 18px 40px rgba(15,23,42,.14);
}
.hero-card h2{margin:0;font-weight:800}
.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.80)}
.stat-card{
    border:0;
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.stat-card small{opacity:.9}
.stat-card h3{margin:8px 0 0;font-size:1.8rem;font-weight:800}
.bg1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.bg2{background:linear-gradient(135deg,#10b981,#059669)}
.bg3{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.panel-card{
    border:0;
    border-radius:24px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:24px}
.panel-title{font-size:20px;font-weight:800;color:#0f172a;margin-bottom:4px}
.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}
.form-control,.form-select{
    min-height:48px;
    border-radius:14px;
    border:1px solid #dbe2ea;
}
.form-label{font-weight:700;color:#334155}
.btn{
    border-radius:14px;
    font-weight:700;
}
.role-card{
    border:1px solid #e2e8f0;
    border-radius:22px;
    padding:20px;
    background:#fff;
    box-shadow:0 8px 22px rgba(15,23,42,.04);
}
.role-name{font-size:22px;font-weight:800;color:#0f172a}
.role-meta{font-size:13px;color:#64748b;margin-top:6px}
.role-badge{
    display:inline-flex;
    align-items:center;
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
}
.badge-system{background:#ede9fe;color:#6d28d9}
.badge-company{background:#dbeafe;color:#1d4ed8}
.action-btn{border-radius:12px}
</style>

<div class="container-fluid py-4 roles-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Roles</h2>
                <p>Create roles and manage them separately from permissions.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="stat-card bg1">
                <small>Total Roles</small>
                <h3><?php echo (int)$totalRoles; ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg2">
                <small>Company Roles</small>
                <h3><?php echo (int)$companyRoles; ?></h3>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card bg3">
                <small>System Roles</small>
                <h3><?php echo (int)$systemRoles; ?></h3>
            </div>
        </div>
    </div>

    <?php if (can_write('roles')): ?>
    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Create Role</div>
            <div class="panel-sub">Add a role here. Permissions are managed on a separate page.</div>

            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="create_role">

                <div class="col-lg-6">
                    <label class="form-label">Role Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Example: Store Keeper" required>
                </div>

                <?php if ($isSuper): ?>
                <div class="col-lg-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_system" value="1" id="is_system">
                        <label class="form-check-label" for="is_system">System Role</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-lg-3 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Create Role</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Search Roles</div>
            <div class="panel-sub">Search roles by name<?php echo $isSuper ? ' or company' : ''; ?>.</div>

            <form class="row g-3">
                <div class="col-lg-10">
                    <input type="text" name="search" class="form-control" placeholder="Search roles..." value="<?php echo e($search); ?>">
                </div>
                <div class="col-lg-2">
                    <button class="btn btn-dark w-100">Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($roles as $role): ?>
            <?php
                $roleId = (int)$role['id'];
                $roleName = strtolower(trim((string)($role['name'] ?? '')));
                $isProtected = $roleName === 'superadmin';
            ?>
            <div class="col-lg-6">
                <div class="role-card h-100">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <div class="role-name"><?php echo e($role['name']); ?></div>
                                <?php if ((int)($role['is_system'] ?? 0) === 1): ?>
                                    <span class="role-badge badge-system">System Role</span>
                                <?php else: ?>
                                    <span class="role-badge badge-company">Company Role</span>
                                <?php endif; ?>
                            </div>

                            <div class="role-meta">
                                <?php if ($isSuper): ?>
                                    Company: <?php echo e($role['company_name'] ?: 'System'); ?>
                                <?php else: ?>
                                    Company Role
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <?php if (can_update('roles')): ?>
                                <a href="permissions.php?role_id=<?php echo $roleId; ?>" class="btn btn-outline-primary action-btn">Permissions</a>
                            <?php endif; ?>

                            <?php if (can_delete('roles') && !$isProtected): ?>
                                <form method="post" onsubmit="return confirm('Delete this role?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                    <input type="hidden" name="action" value="delete_role">
                                    <input type="hidden" name="role_id" value="<?php echo $roleId; ?>">
                                    <button class="btn btn-outline-danger action-btn">Delete</button>
                                </form>
                            <?php else: ?>
                                <?php if ($isProtected): ?>
                                    <span class="text-muted small">Protected</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$roles): ?>
            <div class="col-12">
                <div class="panel-card">
                    <div class="panel-body text-center text-muted py-5">No roles found.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>