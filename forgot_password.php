<?php
require_once __DIR__ . '/includes/functions.php';
$error=''; $success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $username=trim($_POST['username'] ?? '');
  $email=trim($_POST['email'] ?? '');
  $stmt=db()->prepare("SELECT * FROM users WHERE username=? AND email=? AND role='super_admin' LIMIT 1");
  $stmt->bind_param('ss',$username,$email); $stmt->execute();
  $user=$stmt->get_result()->fetch_assoc();
  if($user){
    $code=(string)random_int(100000,999999);
    db()->query("DELETE FROM password_resets WHERE user_id=".(int)$user['id']);
    $exp=date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $hash=password_hash($code,PASSWORD_DEFAULT);
    $ins=db()->prepare("INSERT INTO password_resets(user_id,code_hash,expires_at) VALUES(?,?,?)");
    $ins->bind_param('iss',$user['id'],$hash,$exp); $ins->execute();
    @mail($email,'No9 Cloud Password Reset','Your temporary reset code is: '.$code.' . It expires in 15 minutes.');
    $success='Temporary code generated. Check your email. For local testing use this code: '.$code;
  } else $error='Super admin account not found.';
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot Password</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/style.css" rel="stylesheet"></head><body>
<div class="login-wrap"><div class="login-card">
<h3>Forgot Superadmin Password</h3>
<?php if($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
<div class="mb-3"><input name="username" class="form-control" placeholder="Username"></div>
<div class="mb-3"><input name="email" type="email" class="form-control" placeholder="Email"></div>
<button class="btn btn-primary w-100">Send Temporary Code</button></form>
<a class="btn btn-link mt-2" href="reset_password.php">I already have a code</a><br><a href="index.php">Back to login</a>
</div></div></body></html>