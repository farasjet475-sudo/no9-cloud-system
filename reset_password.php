<?php
require_once __DIR__ . '/includes/functions.php';
$error=''; $success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $username=trim($_POST['username']); $code=trim($_POST['code']); $password=$_POST['password'];
  $stmt=db()->prepare("SELECT * FROM users WHERE username=? AND role='super_admin' LIMIT 1");
  $stmt->bind_param('s',$username); $stmt->execute();
  $user=$stmt->get_result()->fetch_assoc();
  if($user){
    $row=query_one("SELECT * FROM password_resets WHERE user_id=".(int)$user['id']." ORDER BY id DESC LIMIT 1");
    if($row && strtotime($row['expires_at'])>time() && password_verify($code,$row['code_hash'])){
      $hash=password_hash($password,PASSWORD_DEFAULT);
      $up=db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
      $up->bind_param('si',$hash,$user['id']); $up->execute();
      db()->query("DELETE FROM password_resets WHERE user_id=".(int)$user['id']);
      $success='Password reset successful. You can now log in.';
    } else $error='Invalid or expired code.';
  } else $error='Super admin not found.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset Password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/style.css" rel="stylesheet"></head><body>
<div class="login-wrap"><div class="login-card"><h3>Reset Superadmin Password</h3>
<?php if($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
<div class="mb-3"><input name="username" class="form-control" placeholder="Username"></div>
<div class="mb-3"><input name="code" class="form-control" placeholder="Temporary code"></div>
<div class="mb-3"><input name="password" type="password" class="form-control" placeholder="New password"></div>
<button class="btn btn-primary w-100">Reset Password</button></form>
<a href="index.php">Back to login</a></div></div></body></html>