<?php
require_once __DIR__ . '/includes/auth.php';
require_super_admin();
$pageTitle='Company Admins';
require_once __DIR__ . '/includes/header.php';
$companies=db()->query("SELECT id,name FROM companies WHERE id<>1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $id=(int)($_POST['id']??0); $companyId=(int)$_POST['company_id']; $branchId=(int)($_POST['branch_id']??0); $full=trim($_POST['full_name']); $username=trim($_POST['username']); $email=trim($_POST['email']); $password=$_POST['password'] ?? '';
  if(!$branchId){ $branchId=(int)(db()->query("SELECT id FROM branches WHERE company_id=$companyId ORDER BY id ASC LIMIT 1")->fetch_assoc()['id'] ?? 0); }
  if($id){
    if($password){ $hash=password_hash($password,PASSWORD_DEFAULT); $stmt=db()->prepare("UPDATE users SET company_id=?, branch_id=?, full_name=?, username=?, email=?, password_hash=? WHERE id=? AND role='admin'"); $stmt->bind_param('iissssi',$companyId,$branchId,$full,$username,$email,$hash,$id); }
    else { $stmt=db()->prepare("UPDATE users SET company_id=?, branch_id=?, full_name=?, username=?, email=? WHERE id=? AND role='admin'"); $stmt->bind_param('iisssi',$companyId,$branchId,$full,$username,$email,$id); }
    $stmt->execute(); flash('success','Company admin updated.');
  } else {
    $hash=password_hash($password ?: 'admin123',PASSWORD_DEFAULT);
    $stmt=db()->prepare("INSERT INTO users(company_id,branch_id,full_name,username,email,password_hash,role,status) VALUES(?,?,?,?,?,?,'admin','active')");
    $stmt->bind_param('iissss',$companyId,$branchId,$full,$username,$email,$hash); $stmt->execute(); flash('success','Company admin added.');
  }
  redirect('admin_management.php');
}
if(isset($_GET['delete'])){ db()->query("DELETE FROM users WHERE id=".(int)$_GET['delete']." AND role='admin' AND company_id<>1"); flash('success','Admin deleted.'); redirect('admin_management.php'); }
$edit=isset($_GET['edit']) ? db()->query("SELECT * FROM users WHERE id=".(int)$_GET['edit']." AND role='admin'")->fetch_assoc() : null;
$rows=db()->query("SELECT u.*, c.name company_name, b.name branch_name FROM users u JOIN companies c ON c.id=u.company_id LEFT JOIN branches b ON b.id=u.branch_id WHERE u.role='admin' AND u.company_id<>1 ORDER BY c.name,u.id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4"><div class="col-lg-4"><div class="card-soft p-4"><h5><?php echo $edit?'Edit':'Add'; ?> Company Admin</h5>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo (int)($edit['id']??0); ?>">
<div class="mb-2"><select name="company_id" class="form-select"><?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo (($edit['company_id']??0)==$c['id'])?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
<div class="mb-2"><input name="full_name" class="form-control" placeholder="Full name" value="<?php echo e($edit['full_name']??''); ?>"></div>
<div class="mb-2"><input name="username" class="form-control" placeholder="Username" value="<?php echo e($edit['username']??''); ?>"></div>
<div class="mb-2"><input name="email" class="form-control" placeholder="Email" value="<?php echo e($edit['email']??''); ?>"></div>
<div class="mb-2"><input name="password" type="password" class="form-control" placeholder="Password <?php echo $edit?'(leave blank to keep)':''; ?>"></div>
<button class="btn btn-primary w-100"><?php echo $edit?'Update':'Add'; ?> Admin</button>
</form></div></div>
<div class="col-lg-8"><div class="card-soft p-4"><table class="table"><thead><tr><th>Company</th><th>Name</th><th>Username</th><th>Email</th><th>Branch</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?php echo e($r['company_name']); ?></td><td><?php echo e($r['full_name']); ?></td><td><?php echo e($r['username']); ?></td><td><?php echo e($r['email']); ?></td><td><?php echo e($r['branch_name']); ?></td><td class="text-end"><a href="?edit=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a> <a onclick="return confirm('Delete this company admin?')" href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger">Delete</a></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>