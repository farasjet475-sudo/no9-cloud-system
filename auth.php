<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

/* ========================
   LOGIN USER
======================== */
function login_user(array $user){
    $roleName = trim((string)($user['role_name'] ?? ''));
    if ($roleName === '') {
        $roleName = trim((string)($user['role'] ?? 'cashier'));
        if ($roleName === '') {
            $roleName = 'cashier';
        }
    }

    $_SESSION['user'] = [
        'id'         => (int)($user['id'] ?? 0),
        'company_id' => (int)($user['company_id'] ?? 0),
        'branch_id'  => (int)($user['branch_id'] ?? 0),
        'username'   => (string)($user['username'] ?? ''),
        'name'       => (string)($user['full_name'] ?? ($user['username'] ?? '')),
        'full_name'  => (string)($user['full_name'] ?? ''),
        'role_id'    => (int)($user['role_id'] ?? 0),
        'role'       => (string)$roleName,
        'email'      => (string)($user['email'] ?? ''),
        'status'     => (string)($user['status'] ?? 'active'),
    ];

    $_SESSION['branch_id'] = (int)($user['branch_id'] ?? 0);
    unset($_SESSION['permissions']);
}

/* ========================
   LOGOUT
======================== */
function logout_user(){
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/* ========================
   ATTEMPT LOGIN
======================== */
function attempt_login($username, $password, $companyCode = null){
    $db = db();

    $username = trim((string)$username);
    $password = (string)$password;
    $companyCode = $companyCode !== null ? trim((string)$companyCode) : null;

    if ($username === '' || $password === '') {
        return false;
    }

    if ($companyCode !== null && $companyCode !== '') {
        $stmt = $db->prepare("
            SELECT
                u.*,
                r.name AS role_name,
                c.company_code,
                c.status AS company_status,
                c.name AS company_name
            FROM users u
            INNER JOIN companies c ON c.id = u.company_id
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE c.company_code = ?
              AND u.username = ?
              AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('ss', $companyCode, $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return false;
        }

        if (($user['company_status'] ?? 'active') !== 'active') {
            return false;
        }

        if (!password_verify($password, $user['password_hash'] ?? '')) {
            return false;
        }

        login_user($user);
        return true;
    }

    $stmt = $db->prepare("
        SELECT 
            u.*,
            r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.username = ?
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'] ?? '')) {
        return false;
    }

    login_user($user);
    return true;
}

/* ========================
   ROLE CHECKERS
======================== */
function user_is($role){
    return is_logged_in() && current_role() === $role;
}

/* ========================
   ACCESS HELPERS
======================== */
function require_role($role){
    require_login();

    if (!user_is($role)) {
        redirect('dashboard.php');
    }
}

function require_any_role(array $roles){
    require_login();

    if (!in_array(current_role(), $roles, true)) {
        redirect('dashboard.php');
    }
}

/* ========================
   INIT
======================== */
ensure_valid_branch_session();