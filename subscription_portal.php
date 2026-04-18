<?php
$pageTitle='Subscription';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');
$cid=current_company_id();
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $amount=(float)$_POST['amount']; $method=trim($_POST['payment_method']); $reference=trim($_POST['reference_no']);
  $file = upload_file('proof_file', UPLOAD_PAYMENTS, ['jpg','jpeg','png','pdf','webp']);
  $stmt=db()->prepare("INSERT INTO payment_proofs(company_id,subscription_id,amount,payment_method,reference_no,proof_file,status) VALUES(?,?,?,?,?,?,'pending')");
  $subId=db()->query("SELECT id FROM subscriptions WHERE company_id=$cid ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'] ?? null;
  $stmt->bind_param('iidsss',$cid,$subId,$amount,$method,$reference,$file); $stmt->execute();
  flash('success','Payment proof uploaded. Admin will review it manually.');
  redirect('subscription_portal.php');
}
$plans=db()->query("SELECT * FROM plans ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$current=db()->query("SELECT s.*, p.name plan_name FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.company_id=$cid ORDER BY s.id DESC LIMIT 1")->fetch_assoc();
$payments=db()->query("SELECT * FROM payment_proofs WHERE company_id=$cid ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card-soft p-4">
      <h5>Current Subscription</h5>
      <?php if($current): ?>
      <p class="mb-1">Plan: <strong><?php echo e($current['plan_name']); ?></strong></p>
      <p class="mb-1">Period: <?php echo e($current['start_date']); ?> to <?php echo e($current['end_date']); ?></p>
      <p class="mb-0">Status: <span class="badge text-bg-info"><?php echo e($current['status']); ?></span></p>
      <?php endif; ?>
      <hr>
      <h6>Available Plans</h6>
      <div class="row g-3">
        <?php foreach($plans as $p): ?><div class="col-md-4"><div class="border rounded-4 p-3 h-100"><div class="fw-bold"><?php echo e($p['name']); ?></div><div class="display-6">$<?php echo number_format($p['price_monthly'],0); ?></div><div class="small-muted"><?php echo e($p['description']); ?></div></div></div><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-soft p-4">
      <h5>Upload Payment Proof</h5>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="mb-2"><input name="amount" type="number" step="0.01" class="form-control" placeholder="Amount in USD" required></div>
        <div class="mb-2"><select name="payment_method" class="form-select"><option>EVC Plus</option><option>Zaad</option><option>eDahab</option><option>Sahal</option><option>Bank Transfer</option><option>Cash</option></select></div>
        <div class="mb-2"><input name="reference_no" class="form-control" placeholder="Reference / transaction no"></div>
        <div class="mb-2"><input type="file" name="proof_file" class="form-control" required></div>
        <button class="btn btn-primary w-100">Submit for Approval</button>
      </form>
    </div>
  </div>
</div>
<div class="card-soft p-4 mt-4">
  <h5>Payment History</h5>
  <table class="table"><thead><tr><th>Date</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th></tr></thead><tbody>
  <?php foreach($payments as $p): ?><tr><td><?php echo e($p['created_at']); ?></td><td><?php echo e($p['payment_method']); ?></td><td>$<?php echo number_format($p['amount'],2); ?></td><td><?php echo e($p['reference_no']); ?></td><td><?php echo e($p['status']); ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
