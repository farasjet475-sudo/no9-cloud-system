<?php
require_once __DIR__ . '/auth.php';

function is_superadmin_role_name($roleName) {
    return in_array(strtolower(trim((string)$roleName)), ['superadmin', 'super_admin'], true);
}

function rbac_refresh_user_session() {
    if (!is_logged_in()) {
        return;
    }

    $db = db();
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);

    if ($userId <= 0) {
        return;
    }

    $sql = "SELECT u.*, r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $fresh = $res->fetch_assoc();
    $stmt->close();

    if ($fresh) {
        $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], [
            'id'         => (int)($fresh['id'] ?? 0),
            'company_id' => (int)($fresh['company_id'] ?? 0),
            'branch_id'  => (int)($fresh['branch_id'] ?? 0),
            'username'   => $fresh['username'] ?? '',
            'name'       => $fresh['full_name'] ?? ($fresh['username'] ?? ''),
            'full_name'  => $fresh['full_name'] ?? '',
            'email'      => $fresh['email'] ?? '',
            'role_id'    => (int)($fresh['role_id'] ?? 0),
            'role'       => $fresh['role_name'] ?? ($fresh['role'] ?? 'cashier'),
            'status'     => $fresh['status'] ?? 'active',
        ]);

        $_SESSION['branch_id'] = (int)($fresh['branch_id'] ?? 0);
    }

    unset($_SESSION['permissions']);
}

function permission_codes_for_role($roleId) {
    $db = db();
    $roleId = (int)$roleId;

    if ($roleId <= 0) {
        return [];
    }

    $sql = "SELECT p.code
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $res = $stmt->get_result();

    $codes = [];
    while ($row = $res->fetch_assoc()) {
        $codes[] = $row['code'];
    }
    $stmt->close();

    return $codes;
}

function current_user_permissions() {
    if (!is_logged_in()) {
        return [];
    }

    if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        return $_SESSION['permissions'];
    }

    $user = current_user();
    $roleName = $user['role'] ?? '';

    if (is_superadmin_role_name($roleName)) {
        $_SESSION['permissions'] = ['*'];
        return $_SESSION['permissions'];
    }

    $roleId = (int)($user['role_id'] ?? 0);
    $codes = permission_codes_for_role($roleId);
    $_SESSION['permissions'] = $codes;

    return $codes;
}

function has_permission($code) {
    if (!is_logged_in()) {
        return false;
    }

    $perms = current_user_permissions();
    return in_array('*', $perms, true) || in_array($code, $perms, true);
}

function can_read($module) {
    return has_permission($module . '.read');
}

function can_write($module) {
    return has_permission($module . '.write');
}

function can_update($module) {
    return has_permission($module . '.update');
}

function can_delete($module) {
    return has_permission($module . '.delete');
}

function can_export($module = 'reports') {
    return has_permission($module . '.export');
}

function require_permission($code, $message = 'Access denied.') {
    if (!has_permission($code)) {
        http_response_code(403);
        exit('<div style="padding:20px;font-family:Arial">' . e($message) . '</div>');
    }
}

function require_module_read($module) {
    require_permission($module . '.read', 'You are not allowed to view this page.');
}

function require_module_write($module) {
    require_permission($module . '.write', 'You are not allowed to add data here.');
}

function require_module_update($module) {
    require_permission($module . '.update', 'You are not allowed to edit this data.');
}

function require_module_delete($module) {
    require_permission($module . '.delete', 'You are not allowed to delete this data.');
}

function all_permissions_grouped() {
    $db = db();
    $sql = "SELECT * FROM permissions ORDER BY module_name, action_name, code";
    $res = $db->query($sql);

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $grouped[$row['module_name']][] = $row;
    }

    return $grouped;
}

function role_permission_ids($roleId) {
    $db = db();
    $roleId = (int)$roleId;
    $ids = [];

    $stmt = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['permission_id'];
    }

    $stmt->close();
    return $ids;
}

function create_default_company_roles($companyId) {
    $db = db();
    $companyId = (int)$companyId;

    if ($companyId <= 0) {
        return;
    }

    $roles = ['admin', 'manager', 'cashier'];

    foreach ($roles as $name) {
        $stmt = $db->prepare("INSERT IGNORE INTO roles (company_id, name, is_system) VALUES (?, ?, 0)");
        $stmt->bind_param('is', $companyId, $name);
        $stmt->execute();
        $stmt->close();
    }

    assign_default_permissions_for_company_roles($companyId);
}

function assign_default_permissions_for_company_roles($companyId) {
    $db = db();
    $companyId = (int)$companyId;

    $roleMap = [];
    $stmt = $db->prepare("SELECT id, name FROM roles WHERE company_id = ?");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $roleMap[strtolower($row['name'])] = (int)$row['id'];
    }
    $stmt->close();

    if (!$roleMap) {
        return;
    }

    $permMap = [];
    $res = $db->query("SELECT id, code FROM permissions");
    while ($row = $res->fetch_assoc()) {
        $permMap[$row['code']] = (int)$row['id'];
    }

    $adminPerms = array_keys($permMap);

    $managerPerms = [
        'dashboard.read',
        'products.read','products.write','products.update',
        'sales.read','sales.write','sales.update','sales.print',
        'expenses.read','expenses.write','expenses.update',
        'customers.read','customers.write','customers.update',
        'suppliers.read','suppliers.write','suppliers.update',
        'stock.read','stock.write','stock.update',
        'reports.read','reports.export',
        'finance.read','finance.write','finance.update',
        'users.read',
        'settings.read'
    ];

    $cashierPerms = [
        'dashboard.read',
        'products.read',
        'sales.read','sales.write','sales.print',
        'customers.read','customers.write',
        'expenses.read','expenses.write'
    ];

    $sets = [
        'admin'   => $adminPerms,
        'manager' => $managerPerms,
        'cashier' => $cashierPerms,
    ];

    foreach ($sets as $roleName => $codes) {
        if (!isset($roleMap[$roleName])) {
            continue;
        }

        $roleId = $roleMap[$roleName];

        foreach ($codes as $code) {
            if (!isset($permMap[$code])) {
                continue;
            }

            $permId = $permMap[$code];
            $stmt = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $roleId, $permId);
            $stmt->execute();
            $stmt->close();
        }
    }
}