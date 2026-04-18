<?php
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'My Profile';
require_once __DIR__ . '/includes/header.php';

$user = current_user();
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $imagePath = $user['image'] ?? '';

    if (!empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];

        if ((int)$file['error'] === 0) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $uploadDir = __DIR__ . '/uploads/profile/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $newName = 'user_' . (int)$user['id'] . '_' . time() . '.' . $ext;
                $fullPath = $uploadDir . $newName;
                $dbPath = 'uploads/profile/' . $newName;

                if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                    $imagePath = $dbPath;
                }
            }
        }
    }

    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            UPDATE users
            SET full_name=?, email=?, username=?, password_hash=?, image=?
            WHERE id=?
        ");
        $stmt->bind_param('sssssi', $full, $email, $username, $hash, $imagePath, $user['id']);
    } else {
        $stmt = $db->prepare("
            UPDATE users
            SET full_name=?, email=?, username=?, image=?
            WHERE id=?
        ");
        $stmt->bind_param('ssssi', $full, $email, $username, $imagePath, $user['id']);
    }

    $stmt->execute();
    $stmt->close();

    $_SESSION['user']['full_name'] = $full;
    $_SESSION['user']['name'] = $full;
    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['username'] = $username;
    $_SESSION['user']['image'] = $imagePath;

    flash('success', 'Profile updated successfully.');
    redirect('profile.php');
}

$row = query_one("SELECT * FROM users WHERE id=" . (int)$user['id']);
$displayName = $row['full_name'] ?? ($row['username'] ?? 'User');
$roleLabel = current_user()['role'] ?? 'user';
$image = $row['image'] ?? '';
$initial = strtoupper(substr($displayName, 0, 1));
?>

<style>
.profile-modern{
    display:grid;
    gap:22px;
}
.profile-cover{
    position:relative;
    border-radius:30px;
    overflow:hidden;
    background:linear-gradient(135deg,#0f172a 0%, #1d4ed8 55%, #0ea5e9 100%);
    min-height:220px;
    box-shadow:0 18px 40px rgba(15,23,42,.16);
}
.profile-cover::before{
    content:'';
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 25%),
        radial-gradient(circle at bottom left, rgba(255,255,255,.12), transparent 20%);
}
.cover-content{
    position:relative;
    z-index:2;
    padding:28px;
    color:#fff;
    height:100%;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
}
.cover-text h2{
    margin:0;
    font-size:32px;
    font-weight:800;
}
.cover-text p{
    margin:8px 0 0;
    color:rgba(255,255,255,.85);
    max-width:620px;
}
.cover-role{
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-top:14px;
    padding:9px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.16);
    font-size:12px;
    font-weight:800;
}
.profile-layout{
    display:grid;
    grid-template-columns:340px 1fr;
    gap:22px;
}
.profile-card{
    background:#fff;
    border-radius:24px;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
    border:0;
}
.profile-card-body{
    padding:24px;
}
.side-card{
    text-align:center;
}
.avatar-wrap{
    margin-top:-88px;
    position:relative;
    z-index:3;
}
.profile-avatar{
    width:140px;
    height:140px;
    border-radius:50%;
    object-fit:cover;
    border:5px solid #fff;
    box-shadow:0 14px 30px rgba(15,23,42,.15);
    background:#fff;
}
.profile-avatar-placeholder{
    width:140px;
    height:140px;
    border-radius:50%;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    border:5px solid #fff;
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff;
    font-size:44px;
    font-weight:800;
    box-shadow:0 14px 30px rgba(15,23,42,.15);
}
.profile-name{
    font-size:24px;
    font-weight:800;
    color:#0f172a;
    margin-top:14px;
}
.profile-email{
    color:#64748b;
    font-size:14px;
    margin-top:4px;
    word-break:break-word;
}
.upload-box{
    margin-top:18px;
    padding:14px;
    border-radius:18px;
    background:#f8fafc;
    border:1px dashed #cbd5e1;
}
.upload-label{
    display:block;
    font-size:13px;
    font-weight:700;
    color:#334155;
    margin-bottom:10px;
    text-align:left;
}
.meta-grid{
    margin-top:18px;
    display:grid;
    gap:12px;
    text-align:left;
}
.meta-item{
    border:1px solid #e2e8f0;
    background:#fff;
    border-radius:16px;
    padding:14px;
}
.meta-title{
    font-size:12px;
    font-weight:800;
    color:#64748b;
    margin-bottom:4px;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.meta-value{
    font-size:14px;
    font-weight:700;
    color:#0f172a;
    word-break:break-word;
}
.main-title{
    font-size:24px;
    font-weight:800;
    color:#0f172a;
    margin-bottom:6px;
}
.main-sub{
    color:#64748b;
    font-size:14px;
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
.form-control:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}
.password-note{
    font-size:12px;
    color:#64748b;
    margin-top:6px;
}
.save-btn{
    min-height:50px;
    padding:0 22px;
    border-radius:14px;
    font-weight:800;
}
.security-panel{
    margin-top:20px;
    border-radius:18px;
    background:linear-gradient(135deg,#eff6ff,#f8fafc);
    border:1px solid #dbeafe;
    padding:18px;
}
.security-panel h6{
    font-size:15px;
    font-weight:800;
    color:#1e3a8a;
    margin-bottom:6px;
}
.security-panel p{
    margin:0;
    font-size:13px;
    color:#475569;
}
@media (max-width: 991.98px){
    .profile-layout{
        grid-template-columns:1fr;
    }
    .avatar-wrap{
        margin-top:-70px;
    }
}
</style>

<div class="container-fluid py-4 profile-modern">

    <div class="profile-cover">
        <div class="cover-content">
            <div class="cover-text">
                <h2>My Profile</h2>
                <p>Update your account details, login information, and profile image from one modern workspace.</p>

                <div class="cover-role">
                    <i class="bi bi-shield-check"></i>
                    <span><?php echo e(ucwords(str_replace('_', ' ', $roleLabel))); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="profile-layout">
        <div class="profile-card">
            <div class="profile-card-body side-card">
                <div class="avatar-wrap">
                    <?php if ($image && file_exists(__DIR__ . '/' . $image)): ?>
                        <img src="<?php echo e($image); ?>" alt="Profile" class="profile-avatar">
                    <?php else: ?>
                        <div class="profile-avatar-placeholder"><?php echo e($initial); ?></div>
                    <?php endif; ?>
                </div>

                <div class="profile-name"><?php echo e($displayName); ?></div>
                <div class="profile-email"><?php echo e($row['email'] ?? '-'); ?></div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-title">Username</div>
                        <div class="meta-value"><?php echo e($row['username'] ?? '-'); ?></div>
                    </div>

                    <div class="meta-item">
                        <div class="meta-title">Role</div>
                        <div class="meta-value"><?php echo e(ucwords(str_replace('_', ' ', $roleLabel))); ?></div>
                    </div>

                    <div class="meta-item">
                        <div class="meta-title">User ID</div>
                        <div class="meta-value"><?php echo (int)($row['id'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="profile-card">
            <div class="profile-card-body">
                <div class="main-title">Edit Profile</div>
                <div class="main-sub">Change your full name, email, username, password, and profile picture.</div>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                    <div class="upload-box mb-4">
                        <label class="upload-label">Profile Image</label>
                        <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <input
                                name="full_name"
                                class="form-control"
                                required
                                value="<?php echo e($row['full_name'] ?? ''); ?>"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input
                                name="username"
                                class="form-control"
                                required
                                value="<?php echo e($row['username'] ?? ''); ?>"
                            >
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Email Address</label>
                            <input
                                name="email"
                                type="email"
                                class="form-control"
                                value="<?php echo e($row['email'] ?? ''); ?>"
                            >
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">New Password</label>
                            <input
                                name="password"
                                type="password"
                                class="form-control"
                                placeholder="Leave blank to keep current password"
                            >
                            <div class="password-note">Enter a new password only if you want to change the current one.</div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-primary save-btn">Save Changes</button>
                    </div>
                </form>

                <div class="security-panel">
                    <h6>Security Tip</h6>
                    <p>Use a strong password and upload a clear profile image so your account is easier to recognize inside the system.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>