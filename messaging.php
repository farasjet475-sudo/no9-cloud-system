<?php
require_once __DIR__ . '/includes/auth.php';
require_super_admin();
$pageTitle='Messaging';
require_once __DIR__ . '/includes/header.php';
$companies=db()->query("SELECT c.*, (SELECT email FROM users u WHERE u.company_id=c.id AND u.role='admin' ORDER BY id ASC LIMIT 1) admin_email, (SELECT phone FROM companies cc WHERE cc.id=c.id LIMIT 1) company_phone FROM companies c WHERE c.id<>1 ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $companyId=(int)$_POST['company_id']; $channel=$_POST['channel']; $subject=trim($_POST['subject']); $message=trim($_POST['message']);
  $company=query_one("SELECT * FROM companies WHERE id=$companyId"); $admin=company_primary_admin($companyId); $recipient=''; $status='logged';
  if($channel==='email'){ $recipient=$admin['email'] ?? $company['email']; if($recipient){ @mail($recipient,$subject,$message); $status='sent'; } }
  elseif($channel==='whatsapp'){ $recipient=$company['phone']; $status='link_ready'; }
  else { $recipient=$company['phone']; $status='queued'; }
  send_message_record($companyId,$channel,$recipient,$subject,$message,$status);
  add_notification($companyId,'message',$subject,$message);
  flash('success',ucfirst($channel).' message prepared for '.$company['name'].($channel==='whatsapp' ? '. Open the WhatsApp link below from history.' : '.'));
  redirect('messaging.php');
}
$rows=db()->query("SELECT sm.*, c.name company_name FROM sent_messages sm JOIN companies c ON c.id=sm.company_id ORDER BY sm.id DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4"><div class="col-lg-4"><div class="card-soft p-4"><h5>Send to Company Admin</h5>
<form method="post"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
<div class="mb-2"><select name="company_id" class="form-select"><?php foreach($companies as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
<div class="mb-2"><select name="channel" class="form-select"><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="sms">SMS</option></select></div>
<div class="mb-2"><input name="subject" class="form-control" placeholder="Subject / title"></div>
<div class="mb-2"><textarea name="message" class="form-control" rows="5" placeholder="Message"></textarea></div>
<button class="btn btn-primary w-100">Send / Log Message</button>
</form></div></div>
<div class="col-lg-8"><div class="card-soft p-4"><table class="table"><thead><tr><th>Date</th><th>Company</th><th>Channel</th><th>Recipient</th><th>Subject</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['company_name']); ?></td><td><?php echo e($r['channel']); ?></td><td><?php echo e($r['recipient']); ?><?php if($r['channel']==='whatsapp' && $r['recipient']): ?><div><a target="_blank" href="https://wa.me/<?php echo preg_replace('/\D+/','',$r['recipient']); ?>?text=<?php echo urlencode($r['message']); ?>">Open WhatsApp</a></div><?php endif; ?></td><td><?php echo e($r['subject']); ?></td><td><?php echo e($r['status']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>