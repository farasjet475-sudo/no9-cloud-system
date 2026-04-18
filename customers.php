<?php
$pageTitle='Customers';
require_once __DIR__ . '/includes/header.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();
$db  = db();

/* ================= SAVE / UPDATE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    verify_csrf();

    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        flash('error', 'Customer name is required.');
        redirect('customers.php');
    }

    if($id){
        $stmt = $db->prepare("
            UPDATE customers
            SET name=?, phone=?, email=?, address=?
            WHERE id=? AND company_id=? AND branch_id=?
        ");
        $stmt->bind_param('ssssiii', $name, $phone, $email, $address, $id, $cid, $bid);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Customer updated.');
    } else {
        $stmt = $db->prepare("
            INSERT INTO customers(company_id,branch_id,name,phone,email,address)
            VALUES(?,?,?,?,?,?)
        ");
        $stmt->bind_param('iissss', $cid, $bid, $name, $phone, $email, $address);
        $stmt->execute();
        $stmt->close();

        flash('success', 'Customer added.');
    }

    redirect('customers.php');
}

/* ================= DELETE ================= */
if(isset($_GET['delete'])){
    $id = (int)$_GET['delete'];
    $db->query("DELETE FROM customers WHERE id=$id AND company_id=$cid AND branch_id=$bid");
    flash('success','Customer deleted.');
    redirect('customers.php');
}

/* ================= EDIT ================= */
$edit = null;
if(isset($_GET['edit'])){
    $editId = (int)$_GET['edit'];
    $edit = $db->query("
        SELECT *
        FROM customers
        WHERE id=$editId AND company_id=$cid AND branch_id=$bid
        LIMIT 1
    ")->fetch_assoc();
}

/* ================= FILTER ================= */
$search = trim($_GET['search'] ?? '');
$where  = "WHERE c.company_id=$cid AND c.branch_id=$bid";

if($search !== ''){
    $safe = $db->real_escape_string($search);
    $where .= " AND (
        c.name LIKE '%$safe%' OR
        c.phone LIKE '%$safe%' OR
        c.email LIKE '%$safe%' OR
        c.address LIKE '%$safe%'
    )";
}

/* ================= DATA ================= */
$rows = $db->query("
    SELECT c.*
    FROM customers c
    $where
    ORDER BY c.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* ================= KPI ================= */
$totalCustomers = count($rows);
$withPhone = 0;
$withEmail = 0;
$completeProfiles = 0;

foreach($rows as $r){
    $hasPhone = !empty(trim((string)$r['phone']));
    $hasEmail = !empty(trim((string)$r['email']));
    $hasAddress = !empty(trim((string)$r['address']));

    if($hasPhone) $withPhone++;
    if($hasEmail) $withEmail++;
    if($hasPhone && $hasEmail && $hasAddress) $completeProfiles++;
}
?>

<style>
.customer-shell{display:grid;gap:18px}
.hero-card{
    border:0;
    border-radius:24px;
    background:linear-gradient(135deg,#0f172a,#1e293b);
    color:#fff;
    box-shadow:0 16px 36px rgba(15,23,42,.18);
    padding:26px;
}
.hero-card h3{margin:0;font-weight:800}
.hero-card p{margin:6px 0 0;color:rgba(255,255,255,.82)}
.soft-card{
    border:0;
    border-radius:22px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.kpi-card{
    border-radius:20px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{opacity:.92;font-size:.84rem}
.kpi-card h3{margin:8px 0 0;font-size:1.7rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}
.section-title{font-weight:800;margin-bottom:6px}
.section-sub{color:#64748b;font-size:14px}
.filter-box .form-control{min-height:46px;border-radius:14px}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
}
.customer-avatar{
    width:40px;
    height:40px;
    border-radius:50%;
    background:#dbeafe;
    color:#1d4ed8;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    margin-right:10px;
}
.customer-name{
    display:flex;
    align-items:center;
}
.meta-text{
    color:#64748b;
    font-size:12px;
}
.action-btn{border-radius:12px}
.form-label{font-weight:700}
.empty-box{
    padding:36px;
    text-align:center;
    color:#64748b;
}
</style>

<div class="container-fluid py-4 customer-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3>Customer Management</h3>
                <p>Modern customer records page for POS, invoices, and sales workflow.</p>
            </div>
            <a href="dashboard.php" class="btn btn-light">Back</a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Customers</small>
                <h3><?php echo $totalCustomers; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>With Phone</small>
                <h3><?php echo $withPhone; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>With Email</small>
                <h3><?php echo $withEmail; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Complete Profiles</small>
                <h3><?php echo $completeProfiles; ?></h3>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="soft-card p-4">
                <div class="section-title"><?php echo $edit ? 'Edit Customer' : 'Add Customer'; ?></div>
                <div class="section-sub mb-3">Save customer records for receipts, invoices, and reports.</div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

                    <div class="mb-3">
                        <label class="form-label">Customer Name</label>
                        <input name="name" class="form-control" placeholder="Customer Name" required value="<?php echo e($edit['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input name="phone" class="form-control" placeholder="Phone" value="<?php echo e($edit['phone'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input name="email" class="form-control" placeholder="Email" value="<?php echo e($edit['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Address"><?php echo e($edit['address'] ?? ''); ?></textarea>
                    </div>

                    <button class="btn btn-primary w-100"><?php echo $edit ? 'Update Customer' : 'Save Customer'; ?></button>

                    <?php if ($edit): ?>
                        <a href="customers.php" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="soft-card p-4 filter-box mb-4">
                <div class="section-title">Search Customers</div>
                <div class="section-sub mb-3">Find by name, phone, email, or address.</div>

                <form class="row g-3">
                    <div class="col-md-10">
                        <input type="text" name="search" class="form-control" placeholder="Search customer..." value="<?php echo e($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-dark w-100">Search</button>
                    </div>
                </form>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">Customer List</div>
                <div class="section-sub mb-3">All customers saved for the current company and branch.</div>

                <div class="table-wrap">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th width="170"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($rows as $r): ?>
                                    <tr>
                                        <td>
                                            <div class="customer-name">
                                                <span class="customer-avatar">
                                                    <?php echo e(strtoupper(substr($r['name'], 0, 1))); ?>
                                                </span>
                                                <div>
                                                    <div class="fw-bold"><?php echo e($r['name']); ?></div>
                                                    <div class="meta-text">Customer record</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo e($r['phone']); ?></td>
                                        <td><?php echo e($r['email']); ?></td>
                                        <td><?php echo e($r['address']); ?></td>
                                        <td class="text-end">
                                            <a href="?edit=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-primary action-btn">Edit</a>
                                            <a href="?delete=<?php echo (int)$r['id']; ?>" onclick="return confirm('Delete customer?')" class="btn btn-sm btn-outline-danger action-btn">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-box">No customer records found.</div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>