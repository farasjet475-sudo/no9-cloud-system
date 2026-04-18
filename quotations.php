<?php
$pageTitle='Quotations';
require_once __DIR__ . '/includes/header.php';
if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();
$db  = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $customerName = trim($_POST['customer_name'] ?? '');
    $quoteNo      = trim($_POST['quote_no'] ?? ('QT-' . date('Ymd-His')));
    $quoteDate    = $_POST['quote_date'] ?? date('Y-m-d');
    $status       = trim($_POST['status'] ?? 'draft');
    $notes        = trim($_POST['notes'] ?? '');
    $tax          = (float)($_POST['tax'] ?? 0);

    $descriptions = $_POST['item_desc'] ?? [];
    $qtys         = $_POST['item_qty'] ?? [];
    $prices       = $_POST['item_price'] ?? [];
    $discounts    = $_POST['item_discount'] ?? [];

    $items = [];
    $amount = 0;

    foreach ($descriptions as $i => $d) {
        $d = trim($d);
        if ($d === '') continue;

        $q = max(0, (float)($qtys[$i] ?? 1));
        $p = max(0, (float)($prices[$i] ?? 0));
        $disc = max(0, (float)($discounts[$i] ?? 0));
        $line = ($q * $p) - $disc;
        if ($line < 0) $line = 0;

        $items[] = [
            'description' => $d,
            'barcode'     => '',
            'qty'         => $q,
            'price'       => $p,
            'discount'    => $disc,
            'total'       => $line,
        ];

        $amount += $line;
    }

    if ($customerName === '') {
        flash('error', 'Customer name is required.');
        redirect('quotations.php');
    }

    if (!$items) {
        flash('error', 'Add at least one quotation item.');
        redirect('quotations.php');
    }

    $details = json_encode([
        'notes' => $notes,
        'tax'   => $tax,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare("INSERT INTO quotations(company_id,branch_id,customer_name,quote_no,quote_date,amount,status,details) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->bind_param('iisssdss', $cid, $bid, $customerName, $quoteNo, $quoteDate, $amount, $status, $details);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    flash('success','Quotation saved. Stock was not changed.');
    redirect('quotation_print.php?id=' . $newId);
}

if (isset($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM quotations WHERE id=? AND company_id=? AND branch_id=?");
    $id = (int)$_GET['delete'];
    $stmt->bind_param('iii', $id, $cid, $bid);
    $stmt->execute();
    $stmt->close();
    flash('success','Quotation deleted.');
    redirect('quotations.php');
}

$stmt = $db->prepare("SELECT * FROM quotations WHERE company_id=? AND branch_id=? ORDER BY id DESC");
$stmt->bind_param('ii', $cid, $bid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ================= KPI ================= */
$totalQuotes = count($rows);
$totalAmount = 0;
$draftCount = 0;
$sentCount = 0;
$approvedCount = 0;
$todayCount = 0;
$todayDate = date('Y-m-d');

$statusBreakdown = [
    'draft' => 0,
    'sent' => 0,
    'approved' => 0,
];

$monthTotals = [];

foreach ($rows as $r) {
    $amount = (float)($r['amount'] ?? 0);
    $status = strtolower(trim((string)($r['status'] ?? 'draft')));
    $quoteDateValue = $r['quote_date'] ?? '';

    $totalAmount += $amount;

    if ($quoteDateValue === $todayDate) {
        $todayCount++;
    }

    if ($status === 'draft') $draftCount++;
    if ($status === 'sent') $sentCount++;
    if ($status === 'approved') $approvedCount++;

    if (isset($statusBreakdown[$status])) {
        $statusBreakdown[$status]++;
    }

    if ($quoteDateValue) {
        $monthKey = date('Y-m', strtotime($quoteDateValue));
        $monthTotals[$monthKey] = ($monthTotals[$monthKey] ?? 0) + $amount;
    }
}

ksort($monthTotals);

$chartLabels = array_keys($monthTotals);
$chartValues = array_values($monthTotals);
?>

<style>
:root{
    --q-bg:#f8fafc;
    --q-card:#ffffff;
    --q-text:#0f172a;
    --q-muted:#64748b;
    --q-line:#e2e8f0;
    --q-shadow:0 14px 32px rgba(15,23,42,.07);
    --q-shadow-lg:0 20px 48px rgba(15,23,42,.12);
}
.quote-shell{display:grid;gap:22px}
.hero-card{
    border:0;
    border-radius:28px;
    color:#fff;
    padding:28px;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.15), transparent 20%),
        radial-gradient(circle at bottom left, rgba(124,58,237,.18), transparent 24%),
        linear-gradient(135deg,#0f172a 0%, #1d4ed8 50%, #7c3aed 100%);
    box-shadow:var(--q-shadow-lg);
}
.hero-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    font-size:.83rem;
    font-weight:700;
}
.hero-card h2{
    margin:14px 0 8px;
    font-weight:800;
    letter-spacing:-.02em;
}
.hero-card p{
    margin:0;
    color:rgba(255,255,255,.86);
    max-width:780px;
}
.hero-stats{
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    margin-top:18px;
}
.hero-stat{
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.16);
    border-radius:18px;
    padding:14px 16px;
    min-width:150px;
}
.hero-stat small{
    display:block;
    color:rgba(255,255,255,.78);
}
.hero-stat strong{
    font-size:1.12rem;
    font-weight:800;
}

.soft-card{
    border:1px solid var(--q-line);
    border-radius:24px;
    background:var(--q-card);
    box-shadow:var(--q-shadow);
}
.soft-card-header{
    padding:20px 22px 0;
}
.soft-card-body{
    padding:20px 22px 22px;
}
.section-title{
    font-weight:800;
    color:var(--q-text);
    margin:0 0 4px;
}
.section-sub{
    color:var(--q-muted);
    font-size:.92rem;
    margin:0;
}
.kpi-card{
    border-radius:22px;
    padding:20px;
    color:#fff;
    box-shadow:0 12px 28px rgba(0,0,0,.12);
    min-height:140px;
    position:relative;
    overflow:hidden;
}
.kpi-card::after{
    content:"";
    position:absolute;
    width:120px;
    height:120px;
    right:-22px;
    bottom:-22px;
    border-radius:50%;
    background:rgba(255,255,255,.10);
}
.kpi-card small{opacity:.92;font-size:.84rem}
.kpi-card h3{margin:10px 0 8px;font-size:1.7rem;font-weight:800}
.kpi-card .meta{font-size:.84rem;opacity:.92}
.kpi-1{background:linear-gradient(135deg,#2563eb,#1d4ed8)}
.kpi-2{background:linear-gradient(135deg,#7c3aed,#6d28d9)}
.kpi-3{background:linear-gradient(135deg,#10b981,#059669)}
.kpi-4{background:linear-gradient(135deg,#f59e0b,#d97706)}

.form-control,
.form-select{
    min-height:48px;
    border-radius:14px;
    border:1px solid #dbe3ef;
    box-shadow:none !important;
}
.form-control:focus,
.form-select:focus{
    border-color:#60a5fa;
}
.table-wrap{
    border:1px solid #e2e8f0;
    border-radius:18px;
    overflow:hidden;
}
.table thead th{
    background:#f8fafc;
    color:#334155;
    white-space:nowrap;
    font-weight:700;
    border-bottom:1px solid #e2e8f0;
}
.table tbody td{
    vertical-align:middle;
}
.chart-box{
    position:relative;
    min-height:320px;
}
.chart-box canvas{
    width:100% !important;
    height:320px !important;
}
.status-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    text-transform:capitalize;
}
.status-draft{
    background:#ede9fe;
    color:#6d28d9;
}
.status-sent{
    background:#dbeafe;
    color:#1d4ed8;
}
.status-approved{
    background:#dcfce7;
    color:#166534;
}
.item-table th{
    font-size:.82rem;
}
.add-row-btn{
    border-radius:12px;
}
.action-btn{
    border-radius:12px;
}
.empty-box{
    border:1px dashed #cbd5e1;
    border-radius:18px;
    padding:18px;
    text-align:center;
    color:#64748b;
    background:#f8fafc;
}
.quick-note{
    border:1px dashed #cbd5e1;
    background:#f8fafc;
    border-radius:18px;
    padding:14px 16px;
    color:#64748b;
}
.summary-list{
    display:grid;
    gap:12px;
}
.summary-box{
    border:1px solid #e2e8f0;
    border-radius:16px;
    background:#fff;
    padding:14px 16px;
}
.summary-box strong{
    color:#0f172a;
}
@media (max-width: 768px){
    .hero-card h2{font-size:1.5rem}
    .chart-box canvas{height:260px !important}
}
</style>

<div class="container-fluid py-4 quote-shell">

    <div class="hero-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-4">
            <div>
                <span class="hero-badge">
                    <i class="bi bi-file-earmark-richtext"></i>
                    Quotation Management
                </span>
                <h2>Modern Quotation Dashboard</h2>
                <p>
                    Create, manage, and print quotations in a clean professional interface.
                    Quotations do not change stock and remain safe for proposal work.
                </p>

                <div class="hero-stats">
                    <div class="hero-stat">
                        <small>Total Quotations</small>
                        <strong><?php echo $totalQuotes; ?></strong>
                    </div>
                    <div class="hero-stat">
                        <small>Total Amount</small>
                        <strong><?php echo money($totalAmount); ?></strong>
                    </div>
                    <div class="hero-stat">
                        <small>Today</small>
                        <strong><?php echo $todayCount; ?></strong>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="dashboard.php" class="btn btn-light">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <a href="quotations.php" class="btn btn-outline-light">
                    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card kpi-1">
                <small>Total Quotations</small>
                <h3><?php echo $totalQuotes; ?></h3>
                <div class="meta">All saved quotation records</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card kpi-2">
                <small>Draft</small>
                <h3><?php echo $draftCount; ?></h3>
                <div class="meta">Waiting for review or sending</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card kpi-3">
                <small>Approved</small>
                <h3><?php echo $approvedCount; ?></h3>
                <div class="meta">Accepted quotations</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card kpi-4">
                <small>Total Amount</small>
                <h3><?php echo money($totalAmount); ?></h3>
                <div class="meta">Quotation value summary</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">

            <div class="soft-card mb-4">
                <div class="soft-card-header">
                    <h5 class="section-title">Create New Quotation</h5>
                    <p class="section-sub">Fill quotation information and add as many items as needed.</p>
                </div>
                <div class="soft-card-body">
                    <form method="post" id="quotationForm">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input name="customer_name" class="form-control" placeholder="Customer name" required>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Quotation No</label>
                                <input name="quote_no" class="form-control" value="QT-<?php echo date('Ymd-His'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quotation Date</label>
                                <input type="date" name="quote_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="draft">Draft</option>
                                    <option value="sent">Sent</option>
                                    <option value="approved">Approved</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tax</label>
                                <input type="number" step="0.01" min="0" name="tax" class="form-control" placeholder="0.00" value="0">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Quotation notes">
                        </div>

                        <div class="mt-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                <label class="form-label mb-0 fw-bold">Quotation Items</label>
                                <button type="button" class="btn btn-outline-primary btn-sm add-row-btn" id="addItemRow">
                                    <i class="bi bi-plus-circle me-1"></i> Add Row
                                </button>
                            </div>

                            <div class="table-wrap">
                                <div class="table-responsive">
                                    <table class="table table-sm item-table mb-0" id="quoteItems">
                                        <thead>
                                            <tr>
                                                <th>Description</th>
                                                <th width="90">Qty</th>
                                                <th width="120">Unit Price</th>
                                                <th width="110">Discount</th>
                                                <th width="70"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php for($i=0;$i<4;$i++): ?>
                                            <tr>
                                                <td><input name="item_desc[]" class="form-control"></td>
                                                <td><input name="item_qty[]" type="number" step="0.01" value="1" class="form-control"></td>
                                                <td><input name="item_price[]" type="number" step="0.01" value="0" class="form-control"></td>
                                                <td><input name="item_discount[]" type="number" step="0.01" value="0" class="form-control"></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-danger removeRowBtn">×</button>
                                                </td>
                                            </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="quick-note mt-3">
                            Quotation records are for proposal purposes only. Stock is not deducted until a real sale is processed.
                        </div>

                        <button class="btn btn-primary w-100 mt-4">
                            <i class="bi bi-save me-1"></i> Save Quotation
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <div class="col-lg-7">

            <div class="row g-4 mb-4">
                <div class="col-xl-7">
                    <div class="soft-card h-100">
                        <div class="soft-card-header">
                            <h5 class="section-title">Quotation Trend</h5>
                            <p class="section-sub">Quotation amount totals by month.</p>
                        </div>
                        <div class="soft-card-body">
                            <div class="chart-box">
                                <canvas id="quotationTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="soft-card h-100">
                        <div class="soft-card-header">
                            <h5 class="section-title">Status Summary</h5>
                            <p class="section-sub">Current quotation status breakdown.</p>
                        </div>
                        <div class="soft-card-body">
                            <div class="summary-list">
                                <div class="summary-box"><strong>Draft:</strong> <?php echo $draftCount; ?></div>
                                <div class="summary-box"><strong>Sent:</strong> <?php echo $sentCount; ?></div>
                                <div class="summary-box"><strong>Approved:</strong> <?php echo $approvedCount; ?></div>
                                <div class="summary-box"><strong>Total Amount:</strong> <?php echo money($totalAmount); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="soft-card">
                <div class="soft-card-header">
                    <h5 class="section-title">Saved Quotations</h5>
                    <p class="section-sub">Manage saved quotation records and open printable versions.</p>
                </div>
                <div class="soft-card-body">
                    <div class="table-wrap">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Quote No</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th width="170"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach($rows as $r): ?>
                                    <?php $st = strtolower(trim((string)$r['status'])); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($r['quote_no']); ?></td>
                                        <td><?php echo e($r['customer_name']); ?></td>
                                        <td><?php echo e($r['quote_date']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo e(in_array($st, ['draft','sent','approved']) ? $st : 'draft'); ?>">
                                                <?php echo e(ucfirst($r['status'])); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo money($r['amount']); ?></strong></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary action-btn" target="_blank" href="quotation_print.php?id=<?php echo (int)$r['id']; ?>">
                                                Print
                                            </a>
                                            <a class="btn btn-sm btn-outline-danger action-btn" onclick="return confirm('Delete quotation?')" href="?delete=<?php echo (int)$r['id']; ?>">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if(!$rows): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-box m-3">No quotations found.</div>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
    const addBtn = document.getElementById('addItemRow');
    const tbody = document.querySelector('#quoteItems tbody');

    if (addBtn && tbody) {
        addBtn.addEventListener('click', function(){
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input name="item_desc[]" class="form-control"></td>
                <td><input name="item_qty[]" type="number" step="0.01" value="1" class="form-control"></td>
                <td><input name="item_price[]" type="number" step="0.01" value="0" class="form-control"></td>
                <td><input name="item_discount[]" type="number" step="0.01" value="0" class="form-control"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRowBtn">×</button></td>
            `;
            tbody.appendChild(tr);
        });

        document.addEventListener('click', function(e){
            if (e.target.classList.contains('removeRowBtn')) {
                const rows = tbody.querySelectorAll('tr');
                if (rows.length > 1) {
                    e.target.closest('tr').remove();
                }
            }
        });
    }

    const trendLabels = <?php echo json_encode($chartLabels); ?>;
    const trendValues = <?php echo json_encode(array_map('floatval', $chartValues)); ?>;

    const ctx = document.getElementById('quotationTrendChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trendLabels.length ? trendLabels : ['No Data'],
                datasets: [{
                    label: 'Quotation Amount',
                    data: trendValues.length ? trendValues : [0],
                    backgroundColor: 'rgba(124, 58, 237, 0.85)',
                    borderColor: 'rgba(109, 40, 217, 1)',
                    borderWidth: 1.5,
                    borderRadius: 10,
                    borderSkipped: false
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>