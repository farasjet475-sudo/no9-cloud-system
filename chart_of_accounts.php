<?php
$pageTitle = 'Chart of Accounts';
require_once __DIR__ . '/includes/finance_page_bootstrap.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_account') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);

        if ($code === '' || $name === '' || $type === '') {
            flash('error', 'Code, name, and type are required.');
            redirect('chart_of_accounts.php');
        }

        $allowedTypes = ['asset','liability','equity','income','expense'];
        if (!in_array($type, $allowedTypes, true)) {
            flash('error', 'Invalid account type.');
            redirect('chart_of_accounts.php');
        }

        $stmt = $conn->prepare("
            INSERT INTO chart_of_accounts (code, name, type, is_system, is_active)
            VALUES (?, ?, ?, 0, ?)
        ");
        $stmt->bind_param('sssi', $code, $name, $type, $isActive);

        if ($stmt->execute()) {
            flash('success', 'Account added successfully.');
        } else {
            flash('error', 'Failed to add account. Code may already exist.');
        }
        $stmt->close();

        redirect('chart_of_accounts.php');
    }

    if ($action === 'edit_account') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);

        if ($id <= 0 || $code === '' || $name === '' || $type === '') {
            flash('error', 'Invalid account data.');
            redirect('chart_of_accounts.php');
        }

        $allowedTypes = ['asset','liability','equity','income','expense'];
        if (!in_array($type, $allowedTypes, true)) {
            flash('error', 'Invalid account type.');
            redirect('chart_of_accounts.php');
        }

        $check = $conn->prepare("SELECT is_system FROM chart_of_accounts WHERE id=? LIMIT 1");
        $check->bind_param('i', $id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$existing) {
            flash('error', 'Account not found.');
            redirect('chart_of_accounts.php');
        }

        $stmt = $conn->prepare("
            UPDATE chart_of_accounts
            SET code=?, name=?, type=?, is_active=?
            WHERE id=?
        ");
        $stmt->bind_param('sssii', $code, $name, $type, $isActive, $id);

        if ($stmt->execute()) {
            flash('success', 'Account updated successfully.');
        } else {
            flash('error', 'Failed to update account.');
        }
        $stmt->close();

        redirect('chart_of_accounts.php');
    }

    if ($action === 'delete_account') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid account.');
            redirect('chart_of_accounts.php');
        }

        $check = $conn->prepare("SELECT is_system FROM chart_of_accounts WHERE id=? LIMIT 1");
        $check->bind_param('i', $id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$existing) {
            flash('error', 'Account not found.');
            redirect('chart_of_accounts.php');
        }

        if ((int)$existing['is_system'] === 1) {
            flash('error', 'System accounts cannot be deleted.');
            redirect('chart_of_accounts.php');
        }

        $stmt = $conn->prepare("DELETE FROM chart_of_accounts WHERE id=?");
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            flash('success', 'Account deleted successfully.');
        } else {
            flash('error', 'Failed to delete account.');
        }
        $stmt->close();

        redirect('chart_of_accounts.php');
    }
}

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = "WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (code LIKE ? OR name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($typeFilter !== '') {
    $where .= " AND type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    if ($statusFilter === 'active') {
        $where .= " AND is_active = 1";
    } elseif ($statusFilter === 'inactive') {
        $where .= " AND is_active = 0";
    }
}

$sql = "SELECT * FROM chart_of_accounts $where ORDER BY code ASC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$totals = $conn->query("
    SELECT
        COUNT(*) AS total_accounts,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_accounts,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_accounts,
        SUM(CASE WHEN is_system = 1 THEN 1 ELSE 0 END) AS system_accounts,
        SUM(CASE WHEN is_system = 0 THEN 1 ELSE 0 END) AS custom_accounts,
        SUM(CASE WHEN type = 'asset' THEN 1 ELSE 0 END) AS asset_count,
        SUM(CASE WHEN type = 'liability' THEN 1 ELSE 0 END) AS liability_count,
        SUM(CASE WHEN type = 'equity' THEN 1 ELSE 0 END) AS equity_count,
        SUM(CASE WHEN type = 'income' THEN 1 ELSE 0 END) AS income_count,
        SUM(CASE WHEN type = 'expense' THEN 1 ELSE 0 END) AS expense_count
    FROM chart_of_accounts
")->fetch_assoc();
?>

<style>
.kpi-card{
    border:0;
    border-radius:18px;
    padding:18px;
    color:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.10);
    height:100%;
}
.kpi-card small{
    opacity:.92;
    font-size:.84rem;
}
.kpi-card h3{
    margin:8px 0 0;
    font-size:1.7rem;
    font-weight:700;
}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8);}
.kpi-2{background:linear-gradient(135deg,#10b981,#059669);}
.kpi-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
.kpi-4{background:linear-gradient(135deg,#8b5cf6,#7c3aed);}
.soft-card{
    border:0;
    border-radius:20px;
    background:#fff;
    box-shadow:0 10px 28px rgba(15,23,42,.06);
}
.section-title{
    font-weight:700;
    margin-bottom:14px;
}
.type-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.type-asset{background:#dbeafe;color:#1d4ed8;}
.type-liability{background:#fee2e2;color:#b91c1c;}
.type-equity{background:#ede9fe;color:#6d28d9;}
.type-income{background:#dcfce7;color:#15803d;}
.type-expense{background:#fef3c7;color:#b45309;}
.status-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
}
.status-active{background:#dcfce7;color:#15803d;}
.status-inactive{background:#fee2e2;color:#b91c1c;}
.system-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#e0f2fe;
    color:#0369a1;
}
.custom-pill{
    display:inline-block;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    background:#f3f4f6;
    color:#374151;
}
.breakdown-box{
    border-radius:16px;
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:14px 16px;
}
.breakdown-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:9px 0;
    border-bottom:1px dashed #dbe3ef;
}
.breakdown-item:last-child{
    border-bottom:0;
}
.table thead th{
    white-space:nowrap;
}
.modal .form-label{
    font-weight:600;
}
</style>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="kpi-card kpi-1">
            <small>Total Accounts</small>
            <h3><?php echo (int)($totals['total_accounts'] ?? 0); ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="kpi-card kpi-2">
            <small>Active Accounts</small>
            <h3><?php echo (int)($totals['active_accounts'] ?? 0); ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="kpi-card kpi-3">
            <small>System Accounts</small>
            <h3><?php echo (int)($totals['system_accounts'] ?? 0); ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="kpi-card kpi-4">
            <small>Custom Accounts</small>
            <h3><?php echo (int)($totals['custom_accounts'] ?? 0); ?></h3>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="soft-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="section-title mb-1">Chart of Accounts</h5>
                    <div class="text-muted">Manage and review all accounting accounts in the system</div>
                </div>

                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                    + Add Account
                </button>
            </div>

            <form class="row g-2 mb-3">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Search</label>
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search code or name"
                        value="<?php echo finance_escape($search); ?>"
                    >
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-1">Type</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="asset" <?php echo $typeFilter==='asset'?'selected':''; ?>>Asset</option>
                        <option value="liability" <?php echo $typeFilter==='liability'?'selected':''; ?>>Liability</option>
                        <option value="equity" <?php echo $typeFilter==='equity'?'selected':''; ?>>Equity</option>
                        <option value="income" <?php echo $typeFilter==='income'?'selected':''; ?>>Income</option>
                        <option value="expense" <?php echo $typeFilter==='expense'?'selected':''; ?>>Expense</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter==='inactive'?'selected':''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>System</th>
                            <th>Status</th>
                            <th width="180">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                    $type = strtolower((string)$row['type']);
                                    $typeClass = 'type-expense';
                                    if ($type === 'asset') $typeClass = 'type-asset';
                                    elseif ($type === 'liability') $typeClass = 'type-liability';
                                    elseif ($type === 'equity') $typeClass = 'type-equity';
                                    elseif ($type === 'income') $typeClass = 'type-income';
                                ?>
                                <tr>
                                    <td><strong><?php echo finance_escape($row['code']); ?></strong></td>
                                    <td><?php echo finance_escape($row['name']); ?></td>
                                    <td>
                                        <span class="type-pill <?php echo $typeClass; ?>">
                                            <?php echo finance_escape(ucfirst($row['type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$row['is_system']): ?>
                                            <span class="system-pill">System</span>
                                        <?php else: ?>
                                            <span class="custom-pill">Custom</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$row['is_active']): ?>
                                            <span class="status-pill status-active">Active</span>
                                        <?php else: ?>
                                            <span class="status-pill status-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editAccountModal<?php echo (int)$row['id']; ?>">
                                            Edit
                                        </button>

                                        <?php if (!(int)$row['is_system']): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this account?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete_account">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled>Locked</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <div class="modal fade" id="editAccountModal<?php echo (int)$row['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="post">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Account</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>

                                                <div class="modal-body">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="action" value="edit_account">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                                                    <div class="mb-2">
                                                        <label class="form-label">Code</label>
                                                        <input type="text" name="code" class="form-control" value="<?php echo finance_escape($row['code']); ?>" required>
                                                    </div>

                                                    <div class="mb-2">
                                                        <label class="form-label">Name</label>
                                                        <input type="text" name="name" class="form-control" value="<?php echo finance_escape($row['name']); ?>" required>
                                                    </div>

                                                    <div class="mb-2">
                                                        <label class="form-label">Type</label>
                                                        <select name="type" class="form-select">
                                                            <option value="asset" <?php echo $row['type']==='asset'?'selected':''; ?>>Asset</option>
                                                            <option value="liability" <?php echo $row['type']==='liability'?'selected':''; ?>>Liability</option>
                                                            <option value="equity" <?php echo $row['type']==='equity'?'selected':''; ?>>Equity</option>
                                                            <option value="income" <?php echo $row['type']==='income'?'selected':''; ?>>Income</option>
                                                            <option value="expense" <?php echo $row['type']==='expense'?'selected':''; ?>>Expense</option>
                                                        </select>
                                                    </div>

                                                    <div class="mb-2">
                                                        <label class="form-label">Status</label>
                                                        <select name="is_active" class="form-select">
                                                            <option value="1" <?php echo (int)$row['is_active']===1?'selected':''; ?>>Active</option>
                                                            <option value="0" <?php echo (int)$row['is_active']===0?'selected':''; ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="modal-footer">
                                                    <button class="btn btn-primary">Update Account</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No accounts found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="soft-card p-4 mb-4">
            <h5 class="section-title">Account Type Breakdown</h5>
            <div class="breakdown-box">
                <div class="breakdown-item">
                    <span>Assets</span>
                    <strong><?php echo (int)($totals['asset_count'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Liabilities</span>
                    <strong><?php echo (int)($totals['liability_count'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Equity</span>
                    <strong><?php echo (int)($totals['equity_count'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Income</span>
                    <strong><?php echo (int)($totals['income_count'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Expenses</span>
                    <strong><?php echo (int)($totals['expense_count'] ?? 0); ?></strong>
                </div>
            </div>
        </div>

        <div class="soft-card p-4">
            <h5 class="section-title">Status Summary</h5>
            <div class="breakdown-box">
                <div class="breakdown-item">
                    <span>Active</span>
                    <strong><?php echo (int)($totals['active_accounts'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Inactive</span>
                    <strong><?php echo (int)($totals['inactive_accounts'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>System</span>
                    <strong><?php echo (int)($totals['system_accounts'] ?? 0); ?></strong>
                </div>
                <div class="breakdown-item">
                    <span>Custom</span>
                    <strong><?php echo (int)($totals['custom_accounts'] ?? 0); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title">Add Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="add_account">

                    <div class="mb-2">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" class="form-control" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="asset">Asset</option>
                            <option value="liability">Liability</option>
                            <option value="equity">Equity</option>
                            <option value="income">Income</option>
                            <option value="expense">Expense</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary">Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>