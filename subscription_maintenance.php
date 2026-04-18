<?php
require_once __DIR__ . '/../includes/functions.php';

echo "No9 Cloud subscription maintenance\n";
$db = db();

// Expire old active subscriptions
$db->query("UPDATE subscriptions SET status='expired' WHERE status='active' AND end_date < CURDATE()");

// Create reminders 5 days before expiry
$sql = "SELECT s.company_id, c.name company_name, DATEDIFF(s.end_date, CURDATE()) days_left
        FROM subscriptions s
        JOIN companies c ON c.id=s.company_id
        WHERE s.status='active' AND DATEDIFF(s.end_date, CURDATE()) BETWEEN 0 AND 5";
$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
foreach($rows as $r){
    $companyId=(int)$r['company_id'];
    $days=(int)$r['days_left'];
    $title='Subscription reminder';
    $msg='Your subscription expires in '.$days.' day(s). Upload payment proof to avoid read-only mode.';
    $check=$db->prepare("SELECT id FROM notifications WHERE company_id=? AND title=? AND DATE(created_at)=CURDATE() LIMIT 1");
    $check->bind_param('is',$companyId,$title); $check->execute();
    if(!$check->get_result()->fetch_assoc()) add_notification($companyId,'billing',$title,$msg);
}

echo "Done.\n";
