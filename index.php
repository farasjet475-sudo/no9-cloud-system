<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rbac.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
$success = flash('success');
$prefillUser = trim($_GET['user'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = db()->prepare("
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
            $error = 'Invalid login details.';
        } elseif (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid login details.';
        } else {
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
                'username'   => $user['username'] ?? '',
                'name'       => $user['full_name'] ?? ($user['username'] ?? ''),
                'full_name'  => $user['full_name'] ?? '',
                'role_id'    => (int)($user['role_id'] ?? 0),
                'role'       => $roleName,
                'email'      => $user['email'] ?? '',
                'status'     => $user['status'] ?? 'active',
            ];

            $_SESSION['branch_id'] = (int)($user['branch_id'] ?? 0);

            unset($_SESSION['permissions']);

            flash('success', 'Welcome back, ' . ($user['full_name'] ?? $user['username']));
            redirect('dashboard.php');
        }
    }

    $prefillUser = $username;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>No9 Cloud System - Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<style>
    body{
        min-height:100vh;
        margin:0;
        font-family:Arial, Helvetica, sans-serif;
        background:
            linear-gradient(135deg, rgba(15,23,42,.94), rgba(30,41,59,.90)),
            radial-gradient(circle at top right, rgba(37,99,235,.35), transparent 35%),
            radial-gradient(circle at bottom left, rgba(16,185,129,.20), transparent 30%);
        display:flex;
        align-items:center;
        justify-content:center;
        padding:24px;
    }
    .login-shell{
        width:100%;
        max-width:1100px;
        display:grid;
        grid-template-columns: 1.1fr .9fr;
        gap:24px;
        align-items:stretch;
    }
    .login-hero{
        color:#fff;
        border-radius:28px;
        padding:42px 36px;
        background:linear-gradient(135deg, rgba(37,99,235,.22), rgba(15,23,42,.18));
        border:1px solid rgba(255,255,255,.10);
        box-shadow:0 20px 50px rgba(0,0,0,.20);
        backdrop-filter: blur(10px);
        display:flex;
        flex-direction:column;
        justify-content:space-between;
        min-height:620px;
    }
    .hero-badge{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:8px 14px;
        border-radius:999px;
        background:rgba(255,255,255,.10);
        border:1px solid rgba(255,255,255,.12);
        font-size:13px;
        font-weight:700;
    }
    .login-hero h1{
        margin:18px 0 10px;
        font-size:46px;
        font-weight:800;
        line-height:1.05;
    }
    .hero-text{
        color:rgba(255,255,255,.82);
        font-size:17px;
        max-width:520px;
    }
    .hero-points{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px;
        margin-top:28px;
    }
    .hero-point{
        border-radius:18px;
        padding:16px;
        background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.10);
    }
    .hero-point strong{
        display:block;
        margin-bottom:4px;
        font-size:15px;
    }
    .hero-foot{
        color:rgba(255,255,255,.70);
        font-size:13px;
    }
    .login-card{
        background:#fff;
        border-radius:28px;
        padding:34px 30px;
        box-shadow:0 20px 50px rgba(0,0,0,.20);
        min-height:620px;
        display:flex;
        flex-direction:column;
        justify-content:center;
    }
    .login-card h2{
        font-weight:800;
        margin-bottom:6px;
        color:#0f172a;
    }
    .login-sub{
        color:#64748b;
        margin-bottom:20px;
    }
    .form-label{
        font-weight:700;
        color:#334155;
    }
    .form-control{
        min-height:50px;
        border-radius:14px;
        border:1px solid #dbe2ea;
    }
    .btn-login{
        min-height:52px;
        border-radius:14px;
        font-weight:700;
        font-size:16px;
    }
    .hint-box{
        border-radius:16px;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        padding:14px 16px;
        color:#475569;
        font-size:14px;
    }
    .mini-link{
        color:#64748b;
        font-size:13px;
        text-decoration:none;
    }
    .mini-link:hover{
        color:#0f172a;
    }
    @media (max-width: 920px){
        .login-shell{
            grid-template-columns:1fr;
            max-width:620px;
        }
        .login-hero,
        .login-card{
            min-height:auto;
        }
    }
</style>
</head>
<body>

<div class="login-shell">
    <div class="login-hero">
        <div>
            <span class="hero-badge">
                <i class="bi bi-cloud-check"></i>
                No9 Cloud System
            </span>

            <h1>Cloud inventory, POS, finance, and multi-branch control.</h1>
            <div class="hero-text">
                Sign in with your username and password to access your workspace, manage stock, sales, invoices, branches, and reports.
            </div>

            <div class="hero-points">
                <div class="hero-point">
                    <strong>Inventory & POS</strong>
                    Sales, receipts, quotations, stock movement, and product control.
                </div>
                <div class="hero-point">
                    <strong>Finance & Reports</strong>
                    Expenses, profit/loss, dashboards, and statement pages.
                </div>
                <div class="hero-point">
                    <strong>Multi-branch</strong>
                    Branch-based users, filters, dashboards, and reporting.
                </div>
                <div class="hero-point">
                    <strong>SaaS Ready</strong>
                    Companies, plans, subscriptions, and superadmin management.
                </div>
            </div>
        </div>

        <div class="hero-foot">
            Secure access for super admin, company admins, managers, cashiers, and branch users.
        </div>
    </div>

    <div class="login-card">
        <h2>Login</h2>
        <div class="login-sub">Enter your username and password to continue.</div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post" action="index.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

            <div class="mb-3">
                <label class="form-label">Username</label>
                <input
                    type="text"
                    name="username"
                    class="form-control"
                    required
                    placeholder="Enter username"
                    value="<?php echo e($prefillUser); ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input
                    type="password"
                    name="password"
                    class="form-control"
                    required
                    placeholder="Enter password"
                >
            </div>

            <button class="btn btn-primary w-100 btn-login" type="submit">Login</button>
        </form>

        <div class="hint-box mt-3">
            <strong>Tip:</strong> Use the username and password stored in your users table.
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <a href="forgot_password.php" class="mini-link">Forgot password?</a>
            <span class="text-muted small">Username + password login only</span>
        </div>
    </div>
</div>

</body>
</html>