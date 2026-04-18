<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_login();
require_module_read('roles');

$pageTitle = 'Permissions';
require_once __DIR__ . '/includes/header.php';

$db = db();
$isSuper = is_super_admin();
$cid = current_company_id();

$roleId = (int)($_GET['role_id'] ?? $_POST['role_id'] ?? 0);

if ($roleId <= 0) {
    flash('error', 'Role not selected.');
    redirect('roles.php');
}

$stmt = $db->prepare("
    SELECT r.*, c.name AS company_name
    FROM roles r
    LEFT JOIN companies c ON c.id = r.company_id
    WHERE r.id = ?
    LIMIT 1
");
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

if (!$isSuper && ($roleIsSystem === 1 || $roleCompanyId !== $cid)) {
    flash('error', 'You cannot manage permissions for this role.');
    redirect('roles.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();
    require_module_update('roles');

    $permissionIds = $_POST['permission_ids'] ?? [];
    $permissionIds = array_map('intval', $permissionIds);

    if ($roleName === 'superadmin' && !$isSuper) {
        flash('error', 'You cannot edit superadmin permissions.');
        redirect('roles.php');
    }

    $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $stmt->close();

    foreach ($permissionIds as $pid) {
        if ($pid <= 0) continue;

        $stmt = $db->prepare("
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            VALUES (?, ?)
        ");
        $stmt->bind_param('ii', $roleId, $pid);
        $stmt->execute();
        $stmt->close();
    }

    if ((int)(current_user()['role_id'] ?? 0) === $roleId) {
        unset($_SESSION['permissions']);
        rbac_refresh_user_session();
    }

    flash('success', 'Permissions updated successfully.');
    redirect('permissions.php?role_id=' . $roleId);
}

$permissions = all_permissions_grouped();
$selected = role_permission_ids($roleId);
?>

<style>
.permissions-page{display:grid;gap:20px}
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
.role-pill{
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
.panel-card{
    border:0;
    border-radius:24px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:24px}
.permission-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0,1fr));
    gap:16px;
}
.permission-box{
    border:1px solid #e2e8f0;
    border-radius:20px;
    padding:16px;
    background:linear-gradient(180deg,#ffffff 0%, #fbfdff 100%);
}
.permission-title{
    font-size:16px;
    font-weight:800;
    color:#0f172a;
    margin-bottom:12px;
    text-transform:capitalize;
}
.permission-box .form-check{
    padding:9px 10px 9px 2rem;
    border-radius:12px;
    transition:all .18s ease;
}
.permission-box .form-check:hover{
    background:#f8fafc;
}
.permission-box .form-check-label{
    font-size:13px;
    color:#334155;
    word-break:break-word;
}
.permission-box .form-check-input{
    margin-top:.2rem;
}
.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:18px;
}
.btn{
    border-radius:14px;
    font-weight:700;
}
@media (max-width: 1199.98px){
    .permission-grid{grid-template-columns:repeat(2, minmax(0,1fr));}
}
@media (max-width: 767.98px){
    .permission-grid{grid-template-columns:1fr;}
}
</style>

<div class="container-fluid py-4 permissions-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Permissions</h2>
                <p>Manage detailed access rights for this role.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="role-pill">
                    <?php echo e($role['name']); ?>
                </span>
                <a href="roles.php" class="btn btn-light">Back to Roles</a>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-body">
            <div class="toolbar">
                <div>
                    <div class="fw-bold text-dark fs-5"><?php echo e($role['name']); ?></div>
                    <div class="text-muted small">
                        <?php if ($isSuper): ?>
                            Company: <?php echo e($role['company_name'] ?: 'System'); ?>
                        <?php else: ?>
                            Company Role
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-muted small">
                    Select the permissions this role should have.
                </div>
            </div>

            <?php if (can_update('roles')): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="role_id" value="<?php echo $roleId; ?>">

                <div class="permission-grid">
                    <?php foreach ($permissions as $module => $items): ?>
                        <div class="permission-box">
                            <div class="permission-title"><?php echo e($module); ?></div>

                            <?php foreach ($items as $perm): ?>
                                <div class="form-check mb-1">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="permission_ids[]"
                                        value="<?php echo (int)$perm['id']; ?>"
                                        id="perm_<?php echo $roleId; ?>_<?php echo (int)$perm['id']; ?>"
                                        <?php echo in_array((int)$perm['id'], $selected, true) ? 'checked' : ''; ?>
                                    >
                                    <label class="form-check-label" for="perm_<?php echo $roleId; ?>_<?php echo (int)$perm['id']; ?>">
                                        <?php echo e($perm['code']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-4 d-flex gap-2 flex-wrap">
                    <button class="btn btn-success">Save Permissions</button>
                    <a href="roles.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
            <?php else: ?>
                <div class="alert alert-warning mb-0">You do not have permission to edit permissions.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>