<?php
$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/auth.php';

$db = db();

function notifications_is_company_admin(): bool {
    if (function_exists('is_company_admin') && is_company_admin()) return true;
    if (function_exists('is_admin') && is_admin()) return true;

    if (function_exists('current_user')) {
        $u = current_user();
        $role = strtolower((string)($u['role'] ?? ''));
        return in_array($role, ['admin', 'company_admin', 'manager'], true);
    }
    return false;
}

function notifications_can_send(): bool {
    return is_super_admin() || notifications_is_company_admin();
}

function notifications_table_exists(mysqli $db): bool {
    $q = $db->query("SHOW TABLES LIKE 'notifications'");
    return $q && $q->num_rows > 0;
}

function notifications_column_exists(mysqli $db, string $column): bool {
    $column = $db->real_escape_string($column);
    $q = $db->query("SHOW COLUMNS FROM `notifications` LIKE '$column'");
    return $q && $q->num_rows > 0;
}

if (!notifications_table_exists($db)) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-warning m-4">Notifications table was not found in the database.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$hasUserId = notifications_column_exists($db, 'user_id');
$hasSenderId = notifications_column_exists($db, 'sender_id');

$cid = function_exists('current_company_id') ? (int)current_company_id() : 0;
$bid = function_exists('current_branch_id') ? (int)current_branch_id() : 0;
$currentUser = function_exists('current_user') ? current_user() : [];
$currentUserId = (int)($currentUser['id'] ?? 0);

/* ================= SEND MESSAGE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['send_notification']) && notifications_can_send()) {
        if (!$hasUserId) {
            flash('error', 'Please add user_id column to notifications table first.');
            redirect('notifications.php');
        }

        $title   = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $type    = trim($_POST['type'] ?? 'message');
        $target  = $_POST['target'] ?? 'all';
        $userId  = (int)($_POST['user_id'] ?? 0);

        if ($title === '' || $message === '') {
            flash('error', 'Title and message are required.');
            redirect('notifications.php');
        }

        if ($target === 'single' && $userId <= 0) {
            flash('error', 'Please select one user.');
            redirect('notifications.php');
        }

        if ($target === 'single') {
            $stmt = $db->prepare("SELECT id FROM users WHERE id=? AND company_id=? LIMIT 1");
            $stmt->bind_param('ii', $userId, $cid);
            $stmt->execute();
            $ok = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ok) {
                flash('error', 'Selected user does not belong to your company.');
                redirect('notifications.php');
            }

            if ($hasSenderId) {
                $stmt = $db->prepare("
                    INSERT INTO notifications (company_id, user_id, sender_id, type, title, message, is_read, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                ");
                $stmt->bind_param('iiisss', $cid, $userId, $currentUserId, $type, $title, $message);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO notifications (company_id, user_id, type, title, message, is_read, created_at)
                    VALUES (?, ?, ?, ?, ?, 0, NOW())
                ");
                $stmt->bind_param('iisss', $cid, $userId, $type, $title, $message);
            }

            $stmt->execute();
            $stmt->close();

            flash('success', 'Message sent to selected user.');
            redirect('notifications.php');
        }

        if ($target === 'all') {
            $users = [];
            $res = $db->query("SELECT id FROM users WHERE company_id={$cid} ORDER BY id ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $users[] = (int)$row['id'];
                }
            }

            if (!$users) {
                flash('error', 'No users found in this company.');
                redirect('notifications.php');
            }

            foreach ($users as $uid) {
                if ($hasSenderId) {
                    $stmt = $db->prepare("
                        INSERT INTO notifications (company_id, user_id, sender_id, type, title, message, is_read, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $stmt->bind_param('iiisss', $cid, $uid, $currentUserId, $type, $title, $message);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO notifications (company_id, user_id, type, title, message, is_read, created_at)
                        VALUES (?, ?, ?, ?, ?, 0, NOW())
                    ");
                    $stmt->bind_param('iisss', $cid, $uid, $type, $title, $message);
                }
                $stmt->execute();
                $stmt->close();
            }

            flash('success', 'Message sent to all users in your company.');
            redirect('notifications.php');
        }
    }

    if (isset($_POST['mark_read'])) {
        $id = (int)($_POST['id'] ?? 0);

        if (is_super_admin()) {
            $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE id=?");
            $stmt->bind_param('i', $id);
        } elseif ($hasUserId) {
            $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND company_id=? AND user_id=?");
            $stmt->bind_param('iii', $id, $cid, $currentUserId);
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND company_id=?");
            $stmt->bind_param('ii', $id, $cid);
        }

        $stmt->execute();
        $stmt->close();

        flash('success', 'Notification marked as read.');
        redirect('notifications.php');
    }

    if (isset($_POST['mark_all_read'])) {
        if (is_super_admin()) {
            $db->query("UPDATE notifications SET is_read=1");
        } elseif ($hasUserId) {
            $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE company_id=? AND user_id=?");
            $stmt->bind_param('ii', $cid, $currentUserId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read=1 WHERE company_id=?");
            $stmt->bind_param('i', $cid);
            $stmt->execute();
            $stmt->close();
        }

        flash('success', 'All notifications marked as read.');
        redirect('notifications.php');
    }
}

/* ================= USERS FOR ADMIN SEND FORM ================= */
$companyUsers = [];
if (notifications_can_send()) {
    $res = $db->query("
        SELECT id, full_name, username, role
        FROM users
        WHERE company_id={$cid}
        ORDER BY full_name ASC, username ASC
    ");
    if ($res) {
        $companyUsers = $res->fetch_all(MYSQLI_ASSOC);
    }
}

/* ================= FETCH NOTIFICATIONS ================= */
$rows = [];

if (is_super_admin()) {
    $sql = "SELECT * FROM notifications ORDER BY id DESC LIMIT 100";
    $res = $db->query($sql);
    if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
} else {
    if ($hasUserId) {
        $stmt = $db->prepare("
            SELECT *
            FROM notifications
            WHERE company_id=? AND user_id=?
            ORDER BY id DESC
            LIMIT 100
        ");
        $stmt->bind_param('ii', $cid, $currentUserId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $stmt = $db->prepare("
            SELECT *
            FROM notifications
            WHERE company_id=?
            ORDER BY id DESC
            LIMIT 100
        ");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$unreadCount = 0;
$readCount = 0;
foreach ($rows as $r) {
    if ((int)($r['is_read'] ?? 0) === 1) $readCount++;
    else $unreadCount++;
}
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
:root{
    --nf-shadow:0 14px 32px rgba(15,23,42,.07);
    --nf-shadow-lg:0 22px 50px rgba(15,23,42,.12);
    --nf-line:#e2e8f0;
}
.nf-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:30px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.14), transparent 20%),
        radial-gradient(circle at bottom left, rgba(59,130,246,.16), transparent 25%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 50%, #0f766e 100%);
    box-shadow:var(--nf-shadow-lg);
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    font-size:.83rem;
    font-weight:700;
}
.hero-card h2{margin:14px 0 8px;font-weight:800}
.hero-card p{margin:0;color:rgba(255,255,255,.85)}
.hero-mini{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:18px;
}
.hero-mini .box{
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.16);
    border-radius:18px;
    padding:14px 16px;
    min-width:150px;
}
.hero-mini .box small{
    display:block;
    color:rgba(255,255,255,.78);
}
.hero-mini .box strong{
    font-size:1.05rem;
    font-weight:800;
}
.soft-card{
    border:1px solid var(--nf-line);
    border-radius:24px;
    background:#fff;
    box-shadow:var(--nf-shadow);
}
.notif-item{
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:16px;
    background:#fff;
}
.notif-item.unread{
    background:linear-gradient(180deg,#eff6ff 0%, #ffffff 100%);
    border-color:#bfdbfe;
}
.notif-title{
    font-weight:800;
    color:#0f172a;
}
.notif-message{
    color:#475569;
    margin-top:6px;
}
.notif-meta{
    color:#64748b;
    font-size:.88rem;
}
.empty-box{
    border:1px dashed #cbd5e1;
    border-radius:18px;
    padding:20px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
}
</style>

<div class="container-fluid py-4 nf-shell">

    <div class="hero-card">
        <span class="hero-badge"><i class="bi bi-bell-fill"></i> Notifications Center</span>
        <h2>Modern Notifications Dashboard</h2>
        <p>Admin wuxuu farriin u diri karaa hal user ama dhammaan users-ka isla company-giisa. User-kuna wuxuu arkaa notifications-kiisa oo keliya.</p>

        <div class="hero-mini">
            <div class="box"><small>Total</small><strong><?php echo count($rows); ?></strong></div>
            <div class="box"><small>Unread</small><strong><?php echo $unreadCount; ?></strong></div>
            <div class="box"><small>Read</small><strong><?php echo $readCount; ?></strong></div>
        </div>
    </div>

    <?php if (notifications_can_send()): ?>
    <div class="soft-card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h5 class="mb-1 fw-bold">Send Notification</h5>
                <div class="text-muted">Send to one user or all users in this company</div>
            </div>
        </div>

        <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

            <div class="col-md-3">
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <option value="message">Message</option>
                    <option value="alert">Alert</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Target</label>
                <select name="target" id="targetSelect" class="form-select">
                    <option value="all">All Users</option>
                    <option value="single">Single User</option>
                </select>
            </div>

            <div class="col-md-6" id="userSelectWrap" style="display:none;">
                <label class="form-label">Select User</label>
                <select name="user_id" class="form-select">
                    <option value="">Choose user</option>
                    <?php foreach ($companyUsers as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>">
                            <?php echo e(($u['full_name'] ?: $u['username']) . ' (' . $u['role'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required placeholder="Notification title">
            </div>

            <div class="col-md-6">
                <label class="form-label">Message</label>
                <input type="text" name="message" class="form-control" required placeholder="Write message">
            </div>

            <div class="col-12">
                <button type="submit" name="send_notification" class="btn btn-primary">
                    <i class="bi bi-send me-1"></i> Send Notification
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="soft-card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h5 class="mb-1 fw-bold">My Notifications</h5>
                <div class="text-muted">Latest 100 notifications</div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <button type="submit" name="mark_all_read" class="btn btn-primary">
                    <i class="bi bi-check2-all me-1"></i> Mark All Read
                </button>
            </form>
        </div>

        <div class="d-grid gap-3">
            <?php foreach ($rows as $r): ?>
                <?php
                    $isRead = (int)($r['is_read'] ?? 0) === 1;
                    $title = $r['title'] ?? 'Notification';
                    $message = $r['message'] ?? '';
                    $createdAt = $r['created_at'] ?? '';
                ?>
                <div class="notif-item <?php echo $isRead ? '' : 'unread'; ?>">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <div class="notif-title"><?php echo e($title); ?></div>
                            <div class="notif-message"><?php echo e($message); ?></div>
                            <div class="notif-meta mt-2">
                                <?php echo $isRead ? 'Read' : 'Unread'; ?>
                                <?php if ($createdAt): ?> • <?php echo e($createdAt); ?><?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$isRead): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" name="mark_read" class="btn btn-sm btn-outline-primary">
                                    Mark Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
                <div class="empty-box">No notifications found.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
const targetSelect = document.getElementById('targetSelect');
const userSelectWrap = document.getElementById('userSelectWrap');

if (targetSelect && userSelectWrap) {
    targetSelect.addEventListener('change', function () {
        userSelectWrap.style.display = this.value === 'single' ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>