<?php
require_once __DIR__ . '/../config/config.php';

/* ========================
   BASIC HELPERS
======================== */
function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ========================
   SAFE REDIRECT
======================== */
function redirect($url){
    if (!headers_sent()) {
        header('Location: '.$url);
        exit;
    }

    echo '<script>window.location.href='.json_encode($url).';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='.e($url).'"></noscript>';
    exit;
}

function app_url($path=''){
    return BASE_URL ? rtrim(BASE_URL,'/').'/'.ltrim($path,'/') : $path;
}

/* ========================
   CSRF
======================== */
function csrf_token(){
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(){
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $t = $_POST['csrf_token'] ?? '';
        if(!hash_equals($_SESSION['csrf_token'] ?? '', $t)){
            http_response_code(419);
            exit('Invalid CSRF token');
        }
    }
}

/* ========================
   FLASH MESSAGE
======================== */
function flash($key,$message=null){
    if($message!==null){
        $_SESSION['flash'][$key] = $message;
        return;
    }
    if(isset($_SESSION['flash'][$key])){
        $m = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $m;
    }
    return null;
}

/* ========================
   AUTH
======================== */
function is_logged_in(){ return !empty($_SESSION['user']); }
function current_user(){ return $_SESSION['user'] ?? []; }
function current_role(){ return current_user()['role'] ?? ''; }

function is_super_admin(){
    return in_array(current_role(), ['super_admin', 'superadmin'], true);
}
function is_company_admin(){ return current_role() === 'admin'; }
function is_cashier(){ return current_role() === 'cashier'; }

function require_login(){
    if(!is_logged_in()) redirect('index.php');
}

function require_super_admin(){
    require_login();
    if(!is_super_admin()) redirect('dashboard.php');
}

function require_company_admin(){
    require_login();
    if(is_super_admin() || !is_company_admin()) redirect('dashboard.php');
}

function require_company_user(){
    require_login();
    if(is_super_admin()) redirect('dashboard.php');
}

/* ========================
   USER INFO
======================== */
function current_company_id(){ return (int)(current_user()['company_id'] ?? 0); }
function current_branch_id(){ return (int)($_SESSION['branch_id'] ?? current_user()['branch_id'] ?? 0); }
function current_user_id(){ return (int)(current_user()['id'] ?? 0); }
function current_user_role_id(){ return (int)(current_user()['role_id'] ?? 0); }

/* ========================
   BRANCH ACCESS / SESSION
======================== */
/* ========================
   BRANCH ACCESS / SESSION
======================== */
function user_accessible_branch_ids($companyId=null){
    if (is_super_admin()) {
        $rows = db()->query("SELECT id FROM branches ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        return array_map('intval', array_column($rows, 'id'));
    }

    $cid = $companyId ?: current_company_id();
    if (!$cid) return [];

    // Company admin -> all branches in own company
    if (is_company_admin()) {
        $stmt = db()->prepare("SELECT id FROM branches WHERE company_id=? ORDER BY name");
        $stmt->bind_param('i', $cid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map('intval', array_column($rows, 'id'));
    }

    // Normal user / cashier -> only own branch
    $fixed = (int)(current_user()['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
    return $fixed > 0 ? [$fixed] : [];
}

function can_access_branch($branchId, $companyId=null){
    $branchId = (int)$branchId;
    if ($branchId <= 0) return false;

    return in_array($branchId, user_accessible_branch_ids($companyId), true);
}

function require_branch_access($branchId=null){
    require_login();

    $branchId = $branchId ?? (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);

    if ($branchId <= 0) {
        http_response_code(400);
        exit('Invalid branch.');
    }

    if (!can_access_branch($branchId)) {
        http_response_code(403);
        exit('Access denied for this branch.');
    }
}

function set_current_branch($branchId){
    $branchId = (int)$branchId;

    if (!can_access_branch($branchId)) {
        return false;
    }

    // normal user / cashier cannot switch to other branch
    if (!is_super_admin() && !is_company_admin()) {
        $fixed = (int)(current_user()['branch_id'] ?? 0);
        if ($fixed > 0 && $branchId !== $fixed) {
            return false;
        }
    }

    $_SESSION['branch_id'] = $branchId;
    return true;
}

function set_branch_id($branchId){
    return set_current_branch($branchId);
}

function ensure_valid_branch_session(){
    if (is_super_admin()) return;

    $allowed = user_accessible_branch_ids();
    if (!$allowed) return;

    $current = (int)($_SESSION['branch_id'] ?? current_user()['branch_id'] ?? 0);

    if (!$current || !in_array($current, $allowed, true)) {
        $_SESSION['branch_id'] = $allowed[0];
    }
}

/* ========================
   HARD BRANCH LOCK HELPERS
======================== */
function user_fixed_branch_id(){
    // super admin and company admin are not fixed to one branch
    if (is_super_admin() || is_company_admin()) {
        return 0;
    }

    return (int)(current_user()['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
}

function enforce_user_branch_scope($branchId = null){
    // Super admin -> any branch
    if (is_super_admin()) {
        return (int)$branchId;
    }

    // Company admin -> any branch inside own company
    if (is_company_admin()) {
        $requested = (int)$branchId;

        if ($requested <= 0) {
            $requested = (int)($_SESSION['branch_id'] ?? 0);
        }

        if ($requested <= 0) {
            $allowed = user_accessible_branch_ids();
            if (!$allowed) {
                http_response_code(403);
                exit('No branch assigned to this admin.');
            }
            $requested = (int)$allowed[0];
            $_SESSION['branch_id'] = $requested;
        }

        if (!can_access_branch($requested)) {
            http_response_code(403);
            exit('You are not allowed to use another company branch.');
        }

        return $requested;
    }

    // Normal user / cashier -> only own fixed branch
    $userBranchId = (int)(current_user()['branch_id'] ?? $_SESSION['branch_id'] ?? 0);

    if ($userBranchId <= 0) {
        http_response_code(403);
        exit('No branch assigned to this user.');
    }

    if ($branchId !== null && (int)$branchId > 0 && (int)$branchId !== $userBranchId) {
        http_response_code(403);
        exit('You are not allowed to use another branch.');
    }

    $_SESSION['branch_id'] = $userBranchId;
    return $userBranchId;
}

function enforce_sales_branch($requestedBranchId = null){
    return enforce_user_branch_scope($requestedBranchId);
}
/* ========================
   MONEY
======================== */
function currency_symbol(){
    $settings = is_super_admin() ? [] : company_settings();
    return $settings['currency_symbol'] ?? '$';
}

function money($amount){
    return currency_symbol().number_format((float)$amount, 2);
}

/* ========================
   DATABASE SHORTCUTS
======================== */
function query_one($sql){
    $q = db()->query($sql);
    if(!$q) return [];
    $r = $q->fetch_assoc();
    return $r ?: [];
}

function count_row($sql){
    $r = query_one($sql);
    return (int)(array_values($r)[0] ?? 0);
}

/* ========================
   BRANCHES
======================== */
function company_branches($companyId=null){
    $cid = $companyId ?: current_company_id();
    if(!$cid) return [];

    $stmt = db()->prepare("SELECT id,name FROM branches WHERE company_id=? ORDER BY name");
    $stmt->bind_param('i', $cid);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/* ========================
   SETTINGS
======================== */
function company_settings($companyId=null){
    $cid = $companyId ?: current_company_id();
    if(!$cid) return [];

    $stmt = db()->prepare("SELECT setting_key,setting_value FROM settings WHERE company_id=?");
    $stmt->bind_param('i', $cid);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach($rows as $r){
        $out[$r['setting_key']] = $r['setting_value'];
    }
    return $out;
}

function save_setting($key, $value, $companyId=null){
    $cid = $companyId ?: current_company_id();
    if(!$cid || $key === '') return false;

    $existing = db()->prepare("SELECT id FROM settings WHERE company_id=? AND setting_key=? LIMIT 1");
    $existing->bind_param('is', $cid, $key);
    $existing->execute();
    $row = $existing->get_result()->fetch_assoc();
    $existing->close();

    if($row){
        $stmt = db()->prepare("UPDATE settings SET setting_value=? WHERE company_id=? AND setting_key=?");
        $stmt->bind_param('sis', $value, $cid, $key);
    } else {
        $stmt = db()->prepare("INSERT INTO settings(company_id,setting_key,setting_value) VALUES(?,?,?)");
        $stmt->bind_param('iss', $cid, $key, $value);
    }

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function company_branding($companyId=null){
    $cid = $companyId ?: current_company_id();

    if(!$cid){
        return [
            'name'            => defined('APP_NAME') ? APP_NAME : 'Application',
            'title'           => defined('APP_NAME') ? APP_NAME : 'Application',
            'logo'            => '',
            'tagline'         => 'Cloud inventory, finance, and multi-branch SaaS',
            'email'           => '',
            'phone'           => '',
            'address'         => '',
            'invoice_footer'  => 'Thank you for your business.',
            'currency_symbol' => '$',
        ];
    }

    $company = query_one("SELECT name,email,phone FROM companies WHERE id=".(int)$cid);
    $settings = company_settings($cid);

    return [
        'name'            => $settings['company_name'] ?? ($company['name'] ?? 'Company'),
        'title'           => $settings['company_title'] ?? ($company['name'] ?? 'Company'),
        'logo'            => $settings['company_logo'] ?? '',
        'tagline'         => $settings['company_tagline'] ?? 'Inventory, finance, and branch management',
        'email'           => $settings['company_email'] ?? ($company['email'] ?? ''),
        'phone'           => $settings['company_phone'] ?? ($company['phone'] ?? ''),
        'address'         => $settings['company_address'] ?? '',
        'invoice_footer'  => $settings['invoice_footer'] ?? 'Thank you for your business.',
        'currency_symbol' => $settings['currency_symbol'] ?? '$',
    ];
}

/* ========================
   FILE UPLOADS
======================== */
function upload_file($field, $targetDir, $allowedExt=['jpg','jpeg','png','webp','pdf']){
    if(empty($_FILES[$field]['name'])){
        return null;
    }

    if(!is_dir($targetDir)){
        @mkdir($targetDir, 0777, true);
    }

    $original = $_FILES[$field]['name'];
    $tmp      = $_FILES[$field]['tmp_name'];
    $error    = $_FILES[$field]['error'] ?? UPLOAD_ERR_OK;

    if($error !== UPLOAD_ERR_OK){
        throw new RuntimeException('File upload failed.');
    }

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if(!in_array($ext, $allowedExt, true)){
        throw new RuntimeException('Invalid file type.');
    }

    $newName = uniqid('upload_', true) . '.' . $ext;
    $target  = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $newName;

    if(!move_uploaded_file($tmp, $target)){
        throw new RuntimeException('Unable to move uploaded file.');
    }

    return $newName;
}

/* ========================
   SUBSCRIPTION
======================== */
function current_subscription_status(){
    $cid = current_company_id();
    if(!$cid){
        return ['state'=>'active','days_left'=>9999,'label'=>'Active'];
    }

    $stmt = db()->prepare("SELECT status,end_date,start_date FROM subscriptions WHERE company_id=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $cid);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        return ['state'=>'trial','days_left'=>0,'label'=>'Trial'];
    }

    $days = (int)floor((strtotime($row['end_date']) - strtotime(date('Y-m-d'))) / 86400);
    $state = $row['status'];

    if($state === 'active' && $days < 0){
        $state = 'expired';
    }

    return [
        'state'      => $state,
        'days_left'  => $days,
        'label'      => ucfirst($state),
        'start_date' => $row['start_date'] ?? null,
        'end_date'   => $row['end_date'] ?? null,
    ];
}

function current_plan(){
    $cid = current_company_id();

    if(!$cid){
        return [
            'id' => 0,
            'name' => 'No Plan',
            'price' => 0,
            'status' => 'inactive',
            'max_branches' => 0
        ];
    }

    $stmt = db()->prepare("
        SELECT
            p.id,
            p.name,
            p.price_monthly,
            p.max_branches,
            s.status
        FROM subscriptions s
        LEFT JOIN plans p ON p.id = s.plan_id
        WHERE s.company_id = ?
        ORDER BY s.id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$row){
        return [
            'id' => 0,
            'name' => 'No Plan',
            'price' => 0,
            'status' => 'inactive',
            'max_branches' => 0
        ];
    }

    return $row;
}

/* ========================
   PLAN LIMITS
======================== */
function plan_limits(){
    $plan = current_plan();

    return [
        'max_branches' => (int)($plan['max_branches'] ?? 0),
        'max_users'    => 0,
        'users'        => 0
    ];
}

/* ========================
   WRITE GUARD
======================== */
function can_subscription_write(){
    if(is_super_admin()) return true;
    return !in_array(current_subscription_status()['state'], ['expired','suspended'], true);
}

function write_guard(){
    if($_SERVER['REQUEST_METHOD']==='POST' && !can_subscription_write()){
        flash('error', 'Account is read-only (subscription expired or suspended).');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }
}

/* ========================
   COMPANY LOGIN HELPERS
======================== */
function company_login_link($companyCode, $username = ''){
    $url = 'login.php?company=' . urlencode((string)$companyCode);

    if ($username !== '') {
        $url .= '&user=' . urlencode((string)$username);
    }

    return $url;
}

function company_primary_admin($companyId){
    $companyId = (int)$companyId;

    $stmt = db()->prepare("
        SELECT *
        FROM users
        WHERE company_id=? AND role='admin'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: [];
}

/* ========================
   NOTIFICATIONS
======================== */
function unread_notifications_count(){
    $cid = current_company_id();
    if(!$cid) return 0;
    return count_row("SELECT COUNT(*) FROM notifications WHERE company_id=$cid AND is_read=0");
}

function add_notification($companyId, $type, $title, $message, $userId=null){
    $companyId = (int)$companyId;
    if($companyId <= 0 || $title === '' || $message === '') return false;

    $stmt = db()->prepare("
        INSERT INTO notifications(company_id,user_id,type,title,message,is_read,created_at)
        VALUES(?,?,?,?,?,0,NOW())
    ");
    $stmt->bind_param('iisss', $companyId, $userId, $type, $title, $message);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* ========================
   DASHBOARD / REPORTS
======================== */
function monthly_data($table, $amountField, $dateField='created_at', $companyScoped=true){
    $where = [];

    if ($companyScoped && !is_super_admin()) {
        $where[] = 'company_id='.(int)current_company_id();
    }

    $sql = "SELECT DATE_FORMAT($dateField,'%Y-%m') label, COALESCE(SUM($amountField),0) total
            FROM $table"
            . ($where ? ' WHERE '.implode(' AND ', $where) : '')
            . " GROUP BY DATE_FORMAT($dateField,'%Y-%m')
                ORDER BY label";

    $q = db()->query($sql);
    return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

function low_stock_items($companyId=null){
    $cid = $companyId ?: current_company_id();
    if(!$cid) return [];

    $sql = "SELECT p.*, b.name branch_name
            FROM products p
            LEFT JOIN branches b ON b.id=p.branch_id
            WHERE p.company_id=".(int)$cid."
            AND p.stock_qty<=p.min_stock
            ORDER BY p.stock_qty ASC, p.name ASC
            LIMIT 10";

    $q = db()->query($sql);
    return $q ? $q->fetch_all(MYSQLI_ASSOC) : [];
}

/* ========================
   LOG ACTIVITY
======================== */
function log_activity($action, $entity, $entityId=null){
    if(!is_logged_in()) return;

    $stmt = db()->prepare("
        INSERT INTO activity_logs(company_id,branch_id,user_id,action,entity,entity_id)
        VALUES(?,?,?,?,?,?)
    ");

    $cid = current_company_id();
    $bid = current_branch_id();
    $uid = current_user_id();

    $stmt->bind_param('iiissi', $cid, $bid, $uid, $action, $entity, $entityId);
    $stmt->execute();
    $stmt->close();
}
function get_exchange_rate($companyId){
    $stmt = db()->prepare("SELECT exchange_rate, currency_primary, currency_secondary FROM settings WHERE company_id=? LIMIT 1");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    return [
        'rate' => (float)($res['exchange_rate'] ?? 11000),
        'primary' => $res['currency_primary'] ?? 'SLSH',
        'secondary' => $res['currency_secondary'] ?? 'USD'
    ];
}

function slsh_to_usd($amount, $rate){
    return $rate > 0 ? $amount / $rate : 0;
}

function usd_to_slsh($amount, $rate){
    return $amount * $rate;
}

function format_money($amount, $currency='SLSH'){
    if ($currency === 'USD') {
        return '$' . number_format((float)$amount, 2);
    }
    return 'SLSH ' . number_format((float)$amount, 0);
}

function dashboard_format_money($value, ?string $currency = null): string {
    $c = dashboard_currency_settings();
    $target = strtoupper(trim((string)($currency ?? $c['selected'])));
    $amount = dashboard_convert_amount((float)$value, $target);

    if ($target === 'USD') {
        return '$' . number_format($amount, 2);
    }
    return 'SLSH ' . number_format($amount, 0);
}