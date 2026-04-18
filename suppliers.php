<?php
$pageTitle='Suppliers';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');
$cid=current_company_id(); $bid=current_branch_id();
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $id=(int)($_POST['id']??0); $name=trim($_POST['name']); $phone=trim($_POST['phone']); $email=trim($_POST['email']); $address=trim($_POST['address']);
    if($id){
        $stmt=db()->prepare("UPDATE suppliers SET name=?, phone=?, email=?, address=? WHERE id=? AND company_id=? AND branch_id=?");
        $stmt->bind_param('ssssiii',$name,$phone,$email,$address,$id,$cid,$bid); $stmt->execute(); flash('success','Supplier updated.');
    } else {
        $stmt=db()->prepare("INSERT INTO suppliers(company_id,branch_id,name,phone,email,address) VALUES(?,?,?,?,?,?)");
        $stmt->bind_param('iissss',$cid,$bid,$name,$phone,$email,$address); $stmt->execute(); flash('success','Supplier added.');
    }
    redirect('suppliers.php');
}
if(isset($_GET['delete'])){ db()->query("DELETE FROM suppliers WHERE id=".(int)$_GET['delete']." AND company_id=$cid AND branch_id=$bid"); flash('success','Supplier deleted.'); redirect('suppliers.php'); }
$edit=isset($_GET['edit']) ? db()->query("SELECT * FROM suppliers WHERE id=".(int)$_GET['edit']." AND company_id=$cid AND branch_id=$bid")->fetch_assoc() : null;
$rows=db()->query("SELECT * FROM suppliers WHERE company_id=$cid AND branch_id=$bid ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4">
  <div class="col-lg-4"><div class="card-soft p-4"><h5><?php echo $edit?'Edit':'Add'; ?> Supplier</h5>
    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="id" value="<?php echo (int)($edit['id']??0); ?>">
      <div class="mb-2"><input name="name" class="form-control" placeholder="Name" required value="<?php echo e($edit['name']??''); ?>"></div>
      <div class="mb-2"><input name="phone" class="form-control" placeholder="Phone" value="<?php echo e($edit['phone']??''); ?>"></div>
      <div class="mb-2"><input name="email" class="form-control" placeholder="Email" value="<?php echo e($edit['email']??''); ?>"></div>
      <div class="mb-2"><textarea name="address" class="form-control" placeholder="Address"><?php echo e($edit['address']??''); ?></textarea></div>
      <button class="btn btn-primary w-100"><?php echo $edit?'Update':'Save'; ?> Supplier</button></form></div></div>
  <div class="col-lg-8"><div class="card-soft p-4"><table class="table"><thead><tr><th>Name</th><th>Phone</th><th>Email</th><th></th></tr></thead><tbody>
    <?php foreach($rows as $r): ?><tr><td><?php echo e($r['name']); ?></td><td><?php echo e($r['phone']); ?></td><td><?php echo e($r['email']); ?></td><td class="text-end"><a href="?edit=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a> <a onclick="return confirm('Delete?')" href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger">Delete</a></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="4" class="text-center text-secondary">No records.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
