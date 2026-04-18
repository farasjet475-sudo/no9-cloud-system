<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';

require_super_admin();

$pageTitle = 'Payments';
require_once __DIR__ . '/includes/header.php';

$db = db();
$error = '';

if (!function_exists('payment_status_badge_class')) {
    function payment_status_badge_class($status) {
        $status = strtolower((string)$status);
        if ($status === 'approved') return 'status-approved';
        if ($status === 'rejected') return 'status-rejected';
        if ($status === 'pending') return 'status-pending';
        return 'status-default';
    }
}

/* ========================
   HANDLE REVIEW ACTIONS
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    write_guard();

    $action = trim($_POST['action'] ?? '');

    if ($action === 'review_payment') {
        $id = (int)($_POST['id'] ?? 0);
        $decision = trim($_POST['decision'] ?? '');
        $reviewNote = trim($_POST['review_note'] ?? '');

        if ($id <= 0 || !in_array($decision, ['approved', 'rejected', 'pending'], true)) {
            $error = 'Invalid payment review request.';
        } else {
            $stmt = $db->prepare("
                SELECT *
                FROM payment_proofs
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $payment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$payment) {
                $error = 'Payment proof not found.';
            } else {
                $currentNotes = trim((string)($payment['notes'] ?? ''));
                $mergedNotes = $currentNotes;

                if ($reviewNote !== '') {
                    $stamp = '[' . date('Y-m-d H:i:s') . '] Review: ' . $reviewNote;
                    $mergedNotes = $mergedNotes !== '' ? ($mergedNotes . "\n" . $stamp) : $stamp;
                }

                $stmt = $db->prepare("
                    UPDATE payment_proofs
                    SET status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssi', $decision, $mergedNotes, $id);
                $stmt->execute();
                $stmt->close();

                /* ========================
                   OPTIONAL SUBSCRIPTION UPDATE
                ========================= */
                if ($decision === 'approved') {
                    $subscriptionId = (int)($payment['subscription_id'] ?? 0);
                    $amount = (float)($payment['amount'] ?? 0);

                    if ($subscriptionId > 0) {
                        $stmt = $db->prepare("
                            UPDATE subscriptions
                            SET status = 'active',
                                amount_paid = COALESCE(amount_paid, 0) + ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param('di', $amount, $subscriptionId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                log_activity('review', 'payment_proofs', $id);
                flash('success', 'Payment proof review updated successfully.');
                redirect('payments.php');
            }
        }
    }

    if ($action === 'delete_payment') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $error = 'Invalid payment selected.';
        } else {
            $stmt = $db->prepare("DELETE FROM payment_proofs WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            log_activity('delete', 'payment_proofs', $id);
            flash('success', 'Payment proof deleted successfully.');
            redirect('payments.php');
        }
    }
}

/* ========================
   FILTERS
======================== */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$companyFilter = (int)($_GET['company_id'] ?? 0);

$where = "WHERE 1=1";

if ($search !== '') {
    $safe = $db->real_escape_string($search);
    $where .= " AND (
        c.name LIKE '%$safe%' OR
        p.reference_no LIKE '%$safe%' OR
        p.payment_method LIKE '%$safe%' OR
        p.notes LIKE '%$safe%'
    )";
}

if ($statusFilter !== '') {
    $safeStatus = $db->real_escape_string($statusFilter);
    $where .= " AND p.status = '$safeStatus'";
}

if ($companyFilter > 0) {
    $where .= " AND p.company_id = " . (int)$companyFilter;
}

/* ========================
   LOOKUPS
======================== */
$companies = $db->query("
    SELECT id, name
    FROM companies
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

/* ========================
   DATA
======================== */
$rows = $db->query("
    SELECT
        p.*,
        c.name AS company_name,
        s.start_date,
        s.end_date,
        s.status AS subscription_status,
        pl.name AS plan_name
    FROM payment_proofs p
    LEFT JOIN companies c ON c.id = p.company_id
    LEFT JOIN subscriptions s ON s.id = p.subscription_id
    LEFT JOIN plans pl ON pl.id = s.plan_id
    $where
    ORDER BY p.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* ========================
   KPI
======================== */
$totalPayments = count($rows);
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
$totalAmount = 0;

foreach ($rows as $r) {
    $status = strtolower((string)($r['status'] ?? 'pending'));
    if ($status === 'pending') $pendingCount++;
    if ($status === 'approved') $approvedCount++;
    if ($status === 'rejected') $rejectedCount++;
    $totalAmount += (float)($r['amount'] ?? 0);
}
?>

<style>
.payments-page{display:grid;gap:20px}
.hero-card{
    border:0;
    border-radius:28px;
    background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
    color:#fff;
    padding:28px;
    box-shadow:0 18px 40px rgba(15,23,42,.14);
}
.hero-card h2{margin:0;font-weight:800;font-size:28px}
.hero-card p{margin:8px 0 0;color:rgba(255,255,255,.80)}
.hero-actions .btn{border-radius:14px;font-weight:700}

.kpi-card{
    border-radius:22px;
    padding:18px 20px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{display:block;opacity:.9}
.kpi-card h3{margin:8px 0 0;font-size:1.8rem;font-weight:800}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#f59e0b,#d97706)}
.kpi-3{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed)}

.panel-card{
    border:0;
    border-radius:24px;
    background:#fff;
    box-shadow:0 12px 30px rgba(15,23,42,.06);
}
.panel-body{padding:24px}
.panel-title{font-size:20px;font-weight:800;color:#0f172a;margin-bottom:4px}
.panel-sub{color:#64748b;font-size:14px;margin-bottom:18px}

.form-label{font-weight:700;color:#334155}
.form-control,.form-select,.form-textarea{
    min-height:48px;
    border-radius:14px;
    border:1px solid #dbe2ea;
}
.form-control:focus,.form-select:focus,.form-textarea:focus{
    border-color:#93c5fd;
    box-shadow:0 0 0 .2rem rgba(37,99,235,.12);
}
.form-textarea{
    min-height:96px;
    padding:.75rem 1rem;
}

.btn{border-radius:14px;font-weight:700}
.action-btn{border-radius:12px}

.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:20px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
}
.status-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status-approved{background:#dcfce7;color:#166534}
.status-rejected{background:#fee2e2;color:#b91c1c}
.status-pending{background:#fef3c7;color:#92400e}
.status-default{background:#e5e7eb;color:#374151}

.amount-badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:12px;
    font-weight:700;
}
.muted-line{color:#64748b;font-size:12px}
.proof-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    text-decoration:none;
}
.review-box{
    border:1px solid #e2e8f0;
    border-radius:18px;
    padding:16px;
    background:#fff;
}
</style>

<div class="container-fluid py-4 payments-page">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h2>Payments</h2>
                <p>Review company payment proofs, approve or reject payments, and connect them with subscriptions.</p>
            </div>
            <div class="hero-actions">
                <a href="subscriptions.php" class="btn btn-light">Open Subscriptions</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-3">
            <div class="kpi-card kpi-1">
                <small>Total Payment Proofs</small>
                <h3><?php echo (int)$totalPayments; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-2">
                <small>Pending</small>
                <h3><?php echo (int)$pendingCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-3">
                <small>Approved</small>
                <h3><?php echo (int)$approvedCount; ?></h3>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card kpi-4">
                <small>Total Amount</small>
                <h3><?php echo money($totalAmount); ?></h3>
            </div>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Search Payments</div>
            <div class="panel-sub">Filter by company, status, reference number, payment method, or notes.</div>

            <form class="row g-3">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control" placeholder="Search payments..." value="<?php echo e($search); ?>">
                </div>

                <div class="col-md-4">
                    <select name="company_id" class="form-select">
                        <option value="0">All Companies</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $companyFilter === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo e($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-md-1">
                    <button class="btn btn-dark w-100">Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="panel-card">
        <div class="panel-body">
            <div class="panel-title">Payment Proof List</div>
            <div class="panel-sub">All uploaded payment proofs linked to companies and subscriptions.</div>

            <div class="table-wrap">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Subscription</th>
                                <th>Amount</th>
                                <th>Payment Info</th>
                                <th>Proof</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th width="320"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo e($r['company_name'] ?? 'Unknown Company'); ?></div>
                                        <div class="muted-line">
                                            <a href="companies.php?edit=<?php echo (int)($r['company_id'] ?? 0); ?>">Open Company</a>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="fw-bold"><?php echo e($r['plan_name'] ?? 'No Plan'); ?></div>
                                        <div class="muted-line">
                                            <?php echo e($r['start_date'] ?? '-'); ?> → <?php echo e($r['end_date'] ?? '-'); ?>
                                        </div>
                                        <div class="muted-line">
                                            Subscription: <?php echo e(ucfirst((string)($r['subscription_status'] ?? 'unknown'))); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="amount-badge"><?php echo money($r['amount'] ?? 0); ?></span>
                                    </td>

                                    <td>
                                        <div class="fw-semibold"><?php echo e($r['payment_method'] ?? 'N/A'); ?></div>
                                        <div class="muted-line">Ref: <?php echo e($r['reference_no'] ?? '-'); ?></div>
                                        <div class="muted-line"><?php echo e($r['created_at'] ?? '-'); ?></div>
                                    </td>

                                    <td>
                                        <?php if (!empty($r['proof_file'])): ?>
                                            <a class="proof-link" href="<?php echo e($r['proof_file']); ?>" target="_blank">
                                                <i class="bi bi-paperclip"></i>
                                                <span>Open Proof</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">No file</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?php echo payment_status_badge_class($r['status'] ?? 'pending'); ?>">
                                            <?php echo e(ucfirst((string)($r['status'] ?? 'pending'))); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="muted-line" style="white-space:pre-line;"><?php echo e($r['notes'] ?? '-'); ?></div>
                                    </td>

                                    <td>
                                        <div class="review-box">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="review_payment">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">

                                                <div class="mb-2">
                                                    <select name="decision" class="form-select">
                                                        <option value="pending" <?php echo (($r['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                        <option value="approved">Approve</option>
                                                        <option value="rejected">Reject</option>
                                                    </select>
                                                </div>

                                                <div class="mb-2">
                                                    <textarea name="review_note" class="form-textarea w-100" placeholder="Review note (optional)"></textarea>
                                                </div>

                                                <div class="d-flex gap-2 flex-wrap">
                                                    <button class="btn btn-primary btn-sm action-btn">Save Review</button>
                                            </form>

                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment proof?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete_payment">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button class="btn btn-outline-danger btn-sm action-btn">Delete</button>
                                            </form>
                                                </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if(!$rows): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-5">No payment proofs found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>